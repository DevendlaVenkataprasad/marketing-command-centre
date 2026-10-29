<?php
/**
 * Finance Portal — the fixed asset register and depreciation.
 *
 * An asset comes onto the register three ways:
 *   from a posted bill line charged to a fixed-asset account (capitalised:
 *   the bill already debited the asset account, so no new entry is made);
 *   bought or brought in some other way (a journal debits the asset against
 *   the account it came from — opening balance equity for an asset already
 *   owned before the books started);
 *   or from this organisation's IT / Admin & Facilities asset registers.
 *
 * Depreciation is run month by month — straight line over the useful life
 * down to the salvage value, or written-down value at an annual rate — as
 * one journal per month for every asset, and never twice for one month.
 * Disposal takes the cost and accumulated depreciation off the books and
 * posts the gain or loss against what it was sold for.
 */

function fp_asset(int $id): ?array
{
    return fp_one('SELECT * FROM fp_assets WHERE id = ?', 'i', [$id]);
}

function fp_asset_category(int $id): ?array
{
    return fp_one('SELECT * FROM fp_asset_categories WHERE id = ?', 'i', [$id]);
}

function fp_asset_cat_options(): array
{
    $out = [];
    foreach (fp_all('SELECT id, name FROM fp_asset_categories WHERE active = 1 ORDER BY name') as $r) {
        $out[(int) $r['id']] = $r['name'];
    }
    return $out;
}

function fp_asset_event(int $id, string $kind, string $summary): void
{
    fp_exec('INSERT INTO fp_asset_events (asset_id, kind, summary, actor) VALUES (?,?,?,?)', 'isss', [$id, $kind, mb_substr($summary, 0, 500), fp_actor()]);
    fp_audit('asset', $id, $kind, $summary);
}

/**
 * Adds an asset. $p['source']: 'bill' (with bill_line_id — no journal),
 * 'journal' (with counter_account_id — posts the acquisition) or 'register'
 * (register only: an asset whose cost is already in the ledger).
 */
function fp_asset_add(array $p): int
{
    fp_need('assets.manage');
    $cat = fp_asset_category((int) ($p['category_id'] ?? 0));
    if (!$cat) {
        throw new InvalidArgumentException('Choose the asset category.');
    }
    $name = fp_str($p['name'] ?? '', 160, true, 'Asset name');
    $source = in_array($p['source'] ?? '', ['bill', 'journal', 'register'], true) ? $p['source'] : 'journal';
    $acq = fp_date($p['acquired_on'] ?? '', true, 'Acquired on');
    $svc = fp_date($p['in_service_on'] ?? '', false, 'In service from') ?? $acq;
    $billLine = null;
    $bill = null;
    $cost = (float) fp_dec($p['cost'] ?? '', $source !== 'bill', 'Cost', 0.01);
    if ($source === 'bill') {
        $bl = fp_one('SELECT l.*, b.journal_id, b.fx_rate, b.vendor_id, b.bill_date, b.id AS bid FROM fp_bill_lines l JOIN fp_bills b ON b.id = l.bill_id WHERE l.id = ?', 'i', [(int) ($p['bill_line_id'] ?? 0)]);
        if (!$bl || !$bl['journal_id']) {
            throw new InvalidArgumentException('Choose a line of a posted bill.');
        }
        if ($bl['asset_id']) {
            throw new InvalidArgumentException('That bill line is already on the register.');
        }
        if (fp_account((int) $bl['account_id'])['subtype'] !== 'fixed_asset') {
            throw new InvalidArgumentException('That bill line was not charged to a fixed-asset account.');
        }
        $cost = fp_r2((float) $bl['amount'] * (float) $bl['fx_rate']);
        $billLine = (int) $bl['id'];
        $bill = (int) $bl['bid'];
    }
    $salvage = fp_dec($p['salvage'] ?? '', false, 'Salvage value', 0) ?? fp_amt($cost * (float) $cat['salvage_pct'] / 100);
    if ((float) $salvage >= $cost) {
        throw new InvalidArgumentException('The salvage value must be less than the cost.');
    }
    $method = in_array($p['method'] ?? '', ['slm', 'wdv'], true) ? $p['method'] : $cat['method'];
    // Blank means "as the category says".
    $life = max(1, (int) (trim((string) ($p['life_months'] ?? '')) !== '' ? $p['life_months'] : $cat['life_months']));
    $wdvIn = trim((string) ($p['wdv_rate'] ?? '')) !== '' ? $p['wdv_rate'] : (string) ($cat['wdv_rate'] ?? '');
    $wdv = $method === 'wdv' ? fp_dec($wdvIn, true, 'Written-down rate (% a year)', 0.01, 100, 3) : null;
    $cc = fp_ref($p['cost_centre_id'] ?? null, 'fp_cost_centres', 'cost centre');
    $counter = $source === 'journal' ? fp_ref($p['counter_account_id'] ?? null, 'fp_accounts', 'account it was paid from', true, true) : null;
    $accum = (float) (fp_dec($p['accumulated'] ?? '', false, 'Depreciation already charged', 0) ?? 0);
    if ($accum > $cost - (float) $salvage) {
        throw new InvalidArgumentException('Depreciation already charged cannot exceed cost less salvage.');
    }
    return fp_tx(static function () use ($p, $cat, $name, $source, $acq, $svc, $cost, $salvage, $method, $life, $wdv, $cc, $counter, $accum, $billLine, $bill) {
        $tag = fp_str($p['tag'] ?? '', 30) ?: fp_next_code('fp_assets', 'FA', 'tag');
        if (fp_one('SELECT id FROM fp_assets WHERE tag = ?', 's', [$tag])) {
            throw new InvalidArgumentException('Asset tag ' . $tag . ' is already used.');
        }
        $id = fp_exec('INSERT INTO fp_assets (tag, name, category_id, acquired_on, in_service_on, cost, salvage, method, life_months, wdv_rate, accumulated, cost_centre_id, location, custodian, serial,
                       bill_id, bill_line_id, source_ref, notes, prepared_by_id, prepared_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', 'ssisssssississsiissis',
                      [$tag, $name, (int) $cat['id'], $acq, $svc, fp_amt($cost), $salvage, $method, $life, $wdv, fp_amt($accum), $cc, fp_str($p['location'] ?? '', 120) ?: null,
                       fp_str($p['custodian'] ?? '', 160) ?: null, fp_str($p['serial'] ?? '', 120) ?: null, $bill, $billLine, $p['source_ref'] ?? null, fp_str($p['notes'] ?? '', 500) ?: null, fp_me(), fp_actor()]);
        if ($billLine) {
            fp_exec('UPDATE fp_bill_lines SET asset_id = ? WHERE id = ?', 'ii', [$id, $billLine]);
        }
        if ($source === 'journal') {
            $lines = [['account_id' => (int) $cat['asset_account_id'], 'debit' => $cost, 'credit' => 0, 'cost_centre_id' => $cc, 'description' => $tag . ' ' . $name],
                      ['account_id' => $counter, 'debit' => 0, 'credit' => fp_r2($cost - $accum), 'description' => 'Acquisition of ' . $tag]];
            if ($accum > 0) {
                $lines[] = ['account_id' => (int) $cat['accum_account_id'], 'debit' => 0, 'credit' => $accum, 'description' => 'Depreciation charged before ' . fp_fmt_date($acq)];
            }
            $jid = fp_journal_system(['jdate' => $acq, 'type' => 'system', 'source' => 'asset', 'source_id' => $id, 'reference' => $tag, 'description' => 'Asset acquired: ' . $tag . ' ' . $name], $lines);
            fp_exec('UPDATE fp_assets SET journal_id = ? WHERE id = ?', 'ii', [$jid, $id]);
        }
        fp_asset_event($id, 'acquire', 'Put on the register (' . ['bill' => 'capitalised from a bill', 'journal' => 'acquisition posted', 'register' => 'register only'][$source] . '), cost ' . fp_money($cost));
        return $id;
    });
}

/** Posted bill lines charged to fixed-asset accounts and not yet on the register. */
function fp_assets_to_capitalise(): array
{
    return fp_all('SELECT l.*, b.number, b.bill_date, b.fx_rate, v.name AS vendor, a.code AS account_code, a.name AS account_name FROM fp_bill_lines l
                     JOIN fp_bills b ON b.id = l.bill_id JOIN fp_vendors v ON v.id = b.vendor_id JOIN fp_accounts a ON a.id = l.account_id
                    WHERE b.journal_id IS NOT NULL AND b.kind = "bill" AND a.subtype = "fixed_asset" AND l.asset_id IS NULL ORDER BY b.bill_date');
}

/** Monthly depreciation for one asset for the month $ym, given what has been charged so far. */
function fp_dep_amount(array $a, string $ym): float
{
    if ($a['status'] !== 'active' || $a['in_service_on'] > date('Y-m-t', strtotime($ym . '-01'))) {
        return 0.0;
    }
    $book = (float) $a['cost'] - (float) $a['accumulated'];
    $room = fp_r2($book - (float) $a['salvage']);
    if ($room <= 0) {
        return 0.0;
    }
    $amt = $a['method'] === 'wdv'
        ? $book * (float) $a['wdv_rate'] / 100 / 12
        : ((float) $a['cost'] - (float) $a['salvage']) / max(1, (int) $a['life_months']);
    return fp_r2(min($room, $amt));
}

/** Runs depreciation for a month: one journal, one line per category's accounts, never twice. */
function fp_dep_run(string $ym): array
{
    fp_need('assets.depreciate');
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
        throw new InvalidArgumentException('Choose the month.');
    }
    if ($ym > date('Y-m')) {
        throw new InvalidArgumentException('Depreciation is not run for a month that has not started.');
    }
    if (fp_one('SELECT id FROM fp_dep_runs WHERE period = ?', 's', [$ym])) {
        throw new InvalidArgumentException('Depreciation for ' . date('F Y', strtotime($ym . '-01')) . ' has already been run.');
    }
    $prev = fp_one('SELECT MAX(period) p FROM fp_dep_runs');
    if ($prev && $prev['p'] && $ym < $prev['p']) {
        throw new InvalidArgumentException('Depreciation has been run up to ' . $prev['p'] . '; an earlier month cannot be run now.');
    }
    $end = date('Y-m-t', strtotime($ym . '-01'));
    return fp_tx(static function () use ($ym, $end) {
        $run = fp_exec('INSERT INTO fp_dep_runs (period, run_by) VALUES (?,?)', 'ss', [$ym, fp_actor()]);
        $by = [];
        $total = 0;
        $count = 0;
        foreach (fp_all('SELECT a.*, c.accum_account_id, c.expense_account_id FROM fp_assets a JOIN fp_asset_categories c ON c.id = a.category_id WHERE a.status = "active" FOR UPDATE') as $a) {
            $amt = fp_dep_amount($a, $ym);
            if ($amt <= 0) {
                continue;
            }
            fp_exec('INSERT INTO fp_dep_lines (run_id, asset_id, amount) VALUES (?,?,?)', 'iis', [$run, (int) $a['id'], fp_amt($amt)]);
            fp_exec('UPDATE fp_assets SET accumulated = accumulated + ? WHERE id = ?', 'si', [fp_amt($amt), (int) $a['id']]);
            $k = $a['expense_account_id'] . '|' . $a['accum_account_id'] . '|' . (int) $a['cost_centre_id'];
            $by[$k] = ($by[$k] ?? 0) + $amt;
            $total += fp_paise($amt);
            $count++;
        }
        $jid = null;
        if ($by) {
            $lines = [];
            foreach ($by as $k => $amt) {
                [$exp, $acc, $cc] = explode('|', $k);
                $lines[] = ['account_id' => (int) $exp, 'debit' => fp_r2($amt), 'credit' => 0, 'cost_centre_id' => (int) $cc ?: null, 'description' => 'Depreciation ' . $ym];
                $lines[] = ['account_id' => (int) $acc, 'debit' => 0, 'credit' => fp_r2($amt), 'description' => 'Accumulated depreciation ' . $ym];
            }
            $jid = fp_journal_system(['jdate' => $end, 'type' => 'system', 'source' => 'depreciation', 'source_id' => $run, 'reference' => 'DEP ' . $ym,
                                      'description' => 'Depreciation for ' . date('F Y', strtotime($ym . '-01')) . ' (' . $count . ' assets)'], $lines);
        }
        fp_exec('UPDATE fp_dep_runs SET total = ?, assets = ?, journal_id = ? WHERE id = ?', 'siii', [fp_amt($total / 100), $count, $jid, $run]);
        fp_audit('asset', null, 'depreciate', 'Depreciation ' . $ym . ': ' . fp_money($total / 100) . ' on ' . $count . ' assets');
        return [$count, $total / 100];
    });
}

/** Takes an asset off the books: sold for $proceeds into $bankId (or written off when nothing came back). */
function fp_asset_dispose(int $id, array $p): string
{
    fp_need('assets.manage');
    $a = fp_asset($id);
    if (!$a || $a['status'] !== 'active') {
        throw new InvalidArgumentException('Only an active asset can be disposed of.');
    }
    $date = fp_date($p['disposed_on'] ?? date('Y-m-d'), true, 'Disposed on');
    if ($date < $a['acquired_on']) {
        throw new InvalidArgumentException('An asset cannot be disposed of before it was acquired.');
    }
    $proceeds = (float) (fp_dec($p['proceeds'] ?? '', false, 'Sale proceeds', 0) ?? 0);
    $bank = $proceeds > 0 ? fp_ref($p['bank_account_id'] ?? null, 'fp_bank_accounts', 'account the proceeds went to', true, true) : null;
    $reason = fp_str($p['reason'] ?? '', 500, true, 'The reason');
    return fp_tx(static function () use ($a, $id, $date, $proceeds, $bank, $reason) {
        $cat = fp_asset_category((int) $a['category_id']);
        $cost = (float) $a['cost'];
        $acc = (float) $a['accumulated'];
        $gain = fp_r2($proceeds - ($cost - $acc));
        $lines = [['account_id' => (int) $cat['accum_account_id'], 'debit' => $acc, 'credit' => 0, 'description' => 'Depreciation on ' . $a['tag']],
                  ['account_id' => (int) $cat['asset_account_id'], 'debit' => 0, 'credit' => $cost, 'description' => 'Cost of ' . $a['tag']]];
        if ($proceeds > 0) {
            $lines[] = ['account_id' => (int) fp_bank_account($bank)['account_id'], 'debit' => $proceeds, 'credit' => 0, 'description' => 'Sale of ' . $a['tag']];
        }
        if (fp_paise($gain) !== 0) {
            $lines[] = ['account_id' => fp_acct('asset_gain_loss'), 'debit' => $gain < 0 ? -$gain : 0, 'credit' => $gain > 0 ? $gain : 0, 'description' => $gain > 0 ? 'Gain on disposal' : 'Loss on disposal'];
        }
        $jid = fp_journal_system(['jdate' => $date, 'type' => 'system', 'source' => 'asset', 'source_id' => $id, 'reference' => $a['tag'],
                                  'description' => 'Disposal of ' . $a['tag'] . ' ' . $a['name'] . ': ' . $reason], $lines);
        fp_exec('UPDATE fp_assets SET status = ?, disposed_on = ?, disposal_proceeds = ?, disposal_journal_id = ? WHERE id = ?', 'sssii',
                [$proceeds > 0 ? 'disposed' : 'written_off', $date, fp_amt($proceeds), $jid, $id]);
        fp_asset_event($id, 'dispose', ($proceeds > 0 ? 'Sold for ' . fp_money($proceeds) : 'Written off') . '; ' . ($gain >= 0 ? 'gain ' : 'loss ') . fp_money(abs($gain)) . '. ' . $reason);
        return $proceeds > 0 ? 'Asset sold and taken off the books.' : 'Asset written off.';
    });
}

/** Moves an asset to another cost centre, place or custodian. A register change; nothing is posted. */
function fp_asset_transfer(int $id, array $p): void
{
    fp_need('assets.manage');
    $a = fp_asset($id);
    if (!$a || $a['status'] !== 'active') {
        throw new InvalidArgumentException('Only an active asset can be transferred.');
    }
    $cc = fp_ref($p['cost_centre_id'] ?? null, 'fp_cost_centres', 'cost centre');
    $loc = fp_str($p['location'] ?? '', 120);
    $cust = fp_str($p['custodian'] ?? '', 160);
    fp_bump('fp_assets', $id, $p['version'] ?? 0);
    fp_exec('UPDATE fp_assets SET cost_centre_id = ?, location = ?, custodian = ? WHERE id = ?', 'issi', [$cc, $loc ?: null, $cust ?: null, $id]);
    fp_asset_event($id, 'transfer', 'Moved to ' . trim(($cc ? fp_one('SELECT name FROM fp_cost_centres WHERE id = ?', 'i', [$cc])['name'] : 'no cost centre') . ($loc ? ', ' . $loc : '') . ($cust ? ', with ' . $cust : '')));
}

/**
 * Brings in devices and facility assets that carry a cost from this
 * organisation's IT and Admin & Facilities registers, register only (their
 * cost is assumed already in the ledger) — or with an opening balance entry
 * when $opening is set. Each once.
 */
function fp_asset_import_desks(int $categoryId, bool $opening): array
{
    fp_need('assets.manage');
    $cat = fp_asset_category($categoryId);
    if (!$cat) {
        throw new InvalidArgumentException('Choose the category the assets go into.');
    }
    $made = 0;
    foreach (['it', 'fa'] as $desk) {
        $table = $desk . '_' . (fp_org() > 0 ? 'o' . fp_org() . '_' : '') . 'assets';
        try {
            $rows = fabo_all('SELECT * FROM `' . $table . '` WHERE status NOT IN ("retired","lost") AND cost IS NOT NULL AND cost > 0');
        } catch (Throwable $e) {
            continue;
        }
        foreach ($rows as $r) {
            $ref = $desk . ':' . $r['id'];
            if (fp_one('SELECT id FROM fp_assets WHERE source_ref = ?', 's', [$ref])) {
                continue;
            }
            $acq = $r['purchase_date'] ?: substr((string) $r['created_at'], 0, 10);
            fp_asset_add(['category_id' => $categoryId, 'name' => trim($r['name'] . ' ' . ($r['model'] ?? '')), 'source' => $opening ? 'journal' : 'register',
                          'counter_account_id' => $opening ? fp_acct('opening_equity') : null, 'acquired_on' => $acq, 'cost' => $r['cost'],
                          'location' => $r['location'] ?? '', 'custodian' => $r['assigned_name'] ?? '', 'serial' => $r['serial'] ?? '', 'source_ref' => $ref,
                          'notes' => 'From the ' . ($desk === 'it' ? 'IT' : 'Facilities') . ' register, tag ' . $r['tag']]);
            $made++;
        }
    }
    return [$made];
}

/** Book value and the depreciation schedule ahead for one asset (management view). */
function fp_asset_schedule(array $a, int $months = 24): array
{
    $out = [];
    $copy = $a;
    $ym = date('Y-m');
    for ($i = 0; $i < $months; $i++) {
        $m = date('Y-m', strtotime($ym . '-01 +' . $i . ' month'));
        if (fp_one('SELECT l.id FROM fp_dep_lines l JOIN fp_dep_runs r ON r.id = l.run_id WHERE l.asset_id = ? AND r.period = ?', 'is', [(int) $a['id'], $m])) {
            continue;
        }
        $amt = fp_dep_amount($copy, $m);
        if ($amt <= 0) {
            break;
        }
        $copy['accumulated'] = (float) $copy['accumulated'] + $amt;
        $out[] = ['month' => $m, 'amount' => $amt, 'book' => fp_r2((float) $copy['cost'] - (float) $copy['accumulated'])];
    }
    return $out;
}

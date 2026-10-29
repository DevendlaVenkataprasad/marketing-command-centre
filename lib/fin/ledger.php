<?php
/**
 * Finance Portal — the general ledger.
 *
 * One rule above all: a posted journal is never changed or deleted. It is
 * corrected by another journal — a reversal, or an adjustment — that is
 * itself prepared, approved and posted, so the books always show what
 * happened and what was done about it.
 *
 * Every subledger (bills, invoices, receipts, payments, claims, depreciation)
 * posts here through fp_journal_system(), inside the same transaction as the
 * document it records. The approval that let the document through is the
 * approval of its journal. Reports read only posted journals.
 *
 * Balances are kept in the entity's base currency (debit_base/credit_base);
 * the transaction currency is kept beside them for the record.
 */

/* ============================================================== accounts */

function fp_account(int $id): ?array
{
    return fp_one('SELECT * FROM fp_accounts WHERE id = ?', 'i', [$id]);
}

/** A control account by its system key, e.g. 'ap', 'gst_in_cgst'. Throws when missing. */
function fp_acct(string $key): int
{
    static $memo = [];
    $k = fp_prefix() . $key;
    if (!isset($memo[$k])) {
        $r = fp_one('SELECT id FROM fp_accounts WHERE system_key = ?', 's', [$key]);
        if (!$r) {
            throw new InvalidArgumentException('The chart of accounts has no account marked "' . $key . '". Set one in Accounts.');
        }
        $memo[$k] = (int) $r['id'];
    }
    return $memo[$k];
}

function fp_account_types(): array
{
    return ['asset' => 'Asset', 'liability' => 'Liability', 'equity' => 'Equity', 'income' => 'Income', 'expense' => 'Expense'];
}

function fp_account_subtypes(): array
{
    return [
        'asset' => ['cash' => 'Cash', 'bank' => 'Bank', 'receivable' => 'Receivable', 'current_asset' => 'Other current asset',
                    'tax_input' => 'Tax credit (input)', 'fixed_asset' => 'Fixed asset', 'accum_dep' => 'Accumulated depreciation', 'other' => 'Other asset'],
        'liability' => ['payable' => 'Payable', 'current_liability' => 'Current liability', 'tax_output' => 'Tax payable (output)',
                        'long_term' => 'Long-term liability', 'other' => 'Other liability'],
        'equity' => ['capital' => 'Capital', 'retained' => 'Retained earnings', 'other' => 'Other equity'],
        'income' => ['revenue' => 'Operating revenue', 'other_income' => 'Other income'],
        'expense' => ['cogs' => 'Direct cost', 'opex' => 'Operating expense', 'depreciation' => 'Depreciation', 'finance_cost' => 'Finance cost', 'other' => 'Other expense'],
    ];
}

/** Postable accounts for a picker: id => "code · name". $types narrows to some account types. */
function fp_account_options(array $types = [], array $subtypes = []): array
{
    $sql = 'SELECT id, code, name FROM fp_accounts WHERE is_group = 0 AND active = 1';
    if ($types) {
        $sql .= ' AND type IN ("' . implode('","', array_map(static fn($t) => preg_replace('/[^a-z_]/', '', $t), $types)) . '")';
    }
    if ($subtypes) {
        $sql .= ' AND subtype IN ("' . implode('","', array_map(static fn($t) => preg_replace('/[^a-z_]/', '', $t), $subtypes)) . '")';
    }
    $out = [];
    foreach (fp_all($sql . ' ORDER BY code') as $r) {
        $out[(int) $r['id']] = $r['code'] . ' · ' . $r['name'];
    }
    return $out;
}

/** Natural balance: debit-positive for assets and expenses, credit-positive for the rest. */
function fp_natural(string $type, float $dr, float $cr): float
{
    return in_array($type, ['asset', 'expense'], true) ? $dr - $cr : $cr - $dr;
}

function fp_account_save(?int $id, array $p): int
{
    fp_need('coa.manage');
    $code = fp_str($p['code'] ?? '', 20, true, 'Account code');
    if (!preg_match('/^[A-Za-z0-9.\-]+$/', $code)) {
        throw new InvalidArgumentException('An account code may hold letters, digits, dots and dashes only.');
    }
    $name = fp_str($p['name'] ?? '', 160, true, 'Account name');
    $type = (string) ($p['type'] ?? '');
    if (!isset(fp_account_types()[$type])) {
        throw new InvalidArgumentException('Choose the account type.');
    }
    $sub = (string) ($p['subtype'] ?? 'other');
    if ($sub !== 'group' && !isset(fp_account_subtypes()[$type][$sub])) {
        $sub = 'other';
    }
    $group = !empty($p['is_group']) ? 1 : 0;
    $parent = fp_ref($p['parent_id'] ?? null, 'fp_accounts', 'parent account');
    if ($parent) {
        $par = fp_account($parent);
        if ((int) $par['is_group'] !== 1) {
            throw new InvalidArgumentException('The parent must be a group account.');
        }
        if ($par['type'] !== $type) {
            throw new InvalidArgumentException('A ' . $type . ' account must sit under a ' . $type . ' group.');
        }
        if ($id && $parent === $id) {
            throw new InvalidArgumentException('An account cannot be its own parent.');
        }
    }
    $cf = in_array($p['cash_flow'] ?? '', ['operating', 'investing', 'financing'], true) ? $p['cash_flow'] : 'operating';
    $dup = fp_one('SELECT id FROM fp_accounts WHERE code = ? AND id <> ?', 'si', [$code, (int) $id]);
    if ($dup) {
        throw new InvalidArgumentException('Account code ' . $code . ' is already used.');
    }
    $active = !isset($p['active']) || !empty($p['active']) ? 1 : 0;
    $desc = fp_str($p['description'] ?? '', 255);
    if ($id) {
        $before = fp_account($id);
        if (!$before) {
            throw new InvalidArgumentException('That account no longer exists.');
        }
        $used = (bool) fp_one('SELECT 1 FROM fp_journal_lines WHERE account_id = ? LIMIT 1', 'i', [$id]);
        if ($used && $before['type'] !== $type) {
            throw new InvalidArgumentException('This account has entries, so its type cannot change. Create a new account and move the balance with a journal.');
        }
        if ($used && $group) {
            throw new InvalidArgumentException('This account has entries, so it cannot become a group.');
        }
        if (!$active && $before['system_key']) {
            throw new InvalidArgumentException('This is a control account the system posts to (' . $before['system_key'] . '); it cannot be deactivated.');
        }
        fp_exec('UPDATE fp_accounts SET code=?, name=?, type=?, subtype=?, parent_id=?, is_group=?, cash_flow=?, description=?, active=? WHERE id=?',
                'ssssiissii', [$code, $name, $type, $group ? 'group' : $sub, $parent, $group, $cf, $desc ?: null, $active, $id]);
        fp_audit('account', $id, 'update', 'Account ' . $code . ' ' . $name . ' updated', $before, fp_account($id));
        return $id;
    }
    $id = fp_exec('INSERT INTO fp_accounts (code, name, type, subtype, parent_id, is_group, cash_flow, description, active) VALUES (?,?,?,?,?,?,?,?,?)',
                  'ssssiissi', [$code, $name, $type, $group ? 'group' : $sub, $parent, $group, $cf, $desc ?: null, $active]);
    fp_audit('account', $id, 'create', 'Account ' . $code . ' ' . $name . ' created');
    return $id;
}

/* =============================================================== periods */

/** Makes sure every month of a financial year exists as a period. */
function fp_periods_ensure(int $fy): void
{
    for ($n = 1; $n <= 12; $n++) {
        $m = fp_fy_month($fy, $n);
        fp_exec('INSERT IGNORE INTO fp_periods (fy, period_no, name, start_date, end_date) VALUES (?,?,?,?,?)', 'iisss',
                [$fy, $n, date('M Y', strtotime($m . '-01')), $m . '-01', date('Y-m-t', strtotime($m . '-01'))]);
    }
}

function fp_period_for(string $date): array
{
    $p = fp_one('SELECT * FROM fp_periods WHERE ? BETWEEN start_date AND end_date', 's', [$date]);
    if (!$p) {
        fp_periods_ensure(fp_fy_of($date));
        $p = fp_one('SELECT * FROM fp_periods WHERE ? BETWEEN start_date AND end_date', 's', [$date]);
    }
    return $p;
}

/** Stops unless $date falls in an open period. Every posting asks. */
function fp_period_check(string $date): int
{
    $p = fp_period_for($date);
    if ($p['status'] !== 'open') {
        throw new InvalidArgumentException('The period ' . $p['name'] . ' is ' . $p['status'] . '. Post into an open period, or ask the controller to reopen it.');
    }
    return (int) $p['id'];
}

function fp_period_set(int $id, string $status, string $note = ''): string
{
    fp_need('gl.close');
    $p = fp_one('SELECT * FROM fp_periods WHERE id = ?', 'i', [$id]);
    if (!$p) {
        throw new InvalidArgumentException('That period does not exist.');
    }
    if (!in_array($status, ['open', 'closed'], true)) {
        throw new InvalidArgumentException('Unknown period status.');
    }
    if ($p['status'] === 'locked') {
        throw new InvalidArgumentException('This period is locked by the year-end close and cannot be reopened.');
    }
    if ($status === 'closed') {
        $waiting = (int) fp_val('SELECT COUNT(*) FROM fp_journals WHERE jdate BETWEEN ? AND ? AND status IN ("draft","submitted","approved")', 'ss', [$p['start_date'], $p['end_date']]);
        if ($waiting && trim($note) === '') {
            throw new InvalidArgumentException($waiting . ' journal(s) in ' . $p['name'] . ' are not posted yet. Post or cancel them, or give a reason to close anyway.');
        }
    } elseif (trim($note) === '') {
        throw new InvalidArgumentException('Say why the period is being reopened — it is kept in the audit trail.');
    }
    fp_exec('UPDATE fp_periods SET status = ?, closed_by = ?, closed_at = NOW() WHERE id = ?', 'ssi', [$status, fp_actor(), $id]);
    fp_audit('period', $id, $status === 'closed' ? 'close' : 'reopen', $p['name'] . ' ' . ($status === 'closed' ? 'closed' : 'reopened') . ($note ? ': ' . $note : ''));
    return $p['name'] . ($status === 'closed' ? ' closed.' : ' reopened.');
}

/**
 * The year-end close: every income and expense balance of the year moves to
 * retained earnings (one closing journal per entity, dated the last day of
 * the year), and the year's periods are locked for good.
 */
function fp_year_close(int $fy): string
{
    fp_need('gl.close');
    if (!fp_can('settings.manage') && !fp_is_sysadmin() && !in_array('controller', fp_my_roles(), true) && !in_array('cfo', fp_my_roles(), true)) {
        fabo_deny('The year-end close is done by the controller or CFO.', 403);
    }
    [$from, $to] = fp_fy_range($fy);
    $open = (int) fp_val('SELECT COUNT(*) FROM fp_periods WHERE fy = ? AND status = "open"', 'i', [$fy]);
    if ($open) {
        throw new InvalidArgumentException('Close all ' . $open . ' open period(s) of FY ' . fp_fy_label($fy) . ' first.');
    }
    if (fp_one('SELECT id FROM fp_journals WHERE type = "closing" AND jdate = ? AND status = "posted"', 's', [$to])) {
        throw new InvalidArgumentException('FY ' . fp_fy_label($fy) . ' has already been closed.');
    }
    $retained = fp_acct('retained');
    $made = 0;
    fp_tx(static function () use ($fy, $from, $to, $retained, &$made) {
        foreach (fp_all('SELECT id FROM fp_entities') as $ent) {
            $lines = [];
            $net = 0.0;
            foreach (fp_all('SELECT l.account_id, a.type, SUM(l.debit_base) dr, SUM(l.credit_base) cr FROM fp_journal_lines l
                              JOIN fp_journals j ON j.id = l.journal_id JOIN fp_accounts a ON a.id = l.account_id
                             WHERE j.status = "posted" AND j.entity_id = ? AND j.jdate BETWEEN ? AND ? AND a.type IN ("income","expense")
                             GROUP BY l.account_id, a.type', 'iss', [(int) $ent['id'], $from, $to]) as $r) {
                $bal = fp_r2((float) $r['dr'] - (float) $r['cr']);
                if (fp_paise($bal) === 0) {
                    continue;
                }
                $lines[] = ['account_id' => (int) $r['account_id'], 'debit' => $bal < 0 ? -$bal : 0, 'credit' => $bal > 0 ? $bal : 0, 'description' => 'Year-end close'];
                $net += $bal;
            }
            if (!$lines) {
                continue;
            }
            $lines[] = ['account_id' => $retained, 'debit' => $net > 0 ? fp_r2($net) : 0, 'credit' => $net < 0 ? fp_r2(-$net) : 0, 'description' => 'Profit / loss for FY ' . fp_fy_label($fy)];
            // The year's periods are closed, so this one journal is let through on purpose.
            $GLOBALS['fp_allow_closed'] = true;
            try {
                fp_journal_system(['entity_id' => (int) $ent['id'], 'jdate' => $to, 'type' => 'closing', 'source' => 'close', 'source_id' => $fy,
                                   'description' => 'Year-end close FY ' . fp_fy_label($fy)], $lines);
            } finally {
                $GLOBALS['fp_allow_closed'] = false;
            }
            $made++;
        }
        fp_exec('UPDATE fp_periods SET status = "locked", closed_by = ?, closed_at = NOW() WHERE fy = ?', 'si', [fp_actor(), $fy]);
    });
    fp_periods_ensure($fy + 1);
    fp_audit('period', null, 'year_close', 'FY ' . fp_fy_label($fy) . ' closed; ' . $made . ' closing journal(s) posted, periods locked');
    return 'FY ' . fp_fy_label($fy) . ' closed. Profit or loss moved to retained earnings and the year is locked.';
}

/* ============================================================== journals */

function fp_journal(int $id): ?array
{
    return fp_one('SELECT * FROM fp_journals WHERE id = ?', 'i', [$id]);
}

function fp_journal_lines(int $id): array
{
    return fp_all('SELECT l.*, a.code, a.name AS account_name, a.type AS account_type, c.code AS cc_code, p.code AS project_code
                     FROM fp_journal_lines l JOIN fp_accounts a ON a.id = l.account_id
                     LEFT JOIN fp_cost_centres c ON c.id = l.cost_centre_id LEFT JOIN fp_projects p ON p.id = l.project_id
                    WHERE l.journal_id = ? ORDER BY l.line_no, l.id', 'i', [$id]);
}

function fp_journal_types(): array
{
    return ['manual' => 'Manual', 'accrual' => 'Accrual', 'prepayment' => 'Prepayment', 'adjustment' => 'Adjustment', 'reversal' => 'Reversal',
            'recurring' => 'Recurring', 'intercompany' => 'Intercompany', 'opening' => 'Opening balance', 'closing' => 'Year-end close', 'system' => 'System'];
}

function fp_journal_statuses(): array
{
    return ['draft' => ['Draft', ''], 'submitted' => ['Pending approval', 'amber'], 'approved' => ['Approved', 'blue'], 'posted' => ['Posted', 'green'],
            'rejected' => ['Rejected', 'red'], 'cancelled' => ['Cancelled', '']];
}

/**
 * Cleans and checks journal lines as typed. Each line is a debit or a
 * credit, never both; group and inactive accounts are refused.
 * Returns [lines, totalDebit, totalCredit] in transaction currency.
 */
function fp_lines_clean(array $raw): array
{
    $out = [];
    $dr = 0;
    $cr = 0;
    foreach ($raw as $i => $l) {
        $acc = (int) ($l['account_id'] ?? 0);
        $d = fp_dec($l['debit'] ?? '', false, 'Debit on line ' . ($i + 1), 0) ?? '0.00';
        $c = fp_dec($l['credit'] ?? '', false, 'Credit on line ' . ($i + 1), 0) ?? '0.00';
        if (!$acc && (float) $d == 0 && (float) $c == 0) {
            continue;   // an empty row of the form
        }
        $n = count($out) + 1;
        if (!$acc) {
            throw new InvalidArgumentException('Line ' . $n . ' has an amount but no account.');
        }
        $a = fp_account($acc);
        if (!$a || (int) $a['is_group'] === 1) {
            throw new InvalidArgumentException('Line ' . $n . ': choose a postable account, not a group.');
        }
        if ((int) $a['active'] !== 1) {
            throw new InvalidArgumentException('Line ' . $n . ': account ' . $a['code'] . ' is inactive.');
        }
        if ((float) $d > 0 && (float) $c > 0) {
            throw new InvalidArgumentException('Line ' . $n . ' has both a debit and a credit. Use two lines.');
        }
        if ((float) $d == 0 && (float) $c == 0) {
            throw new InvalidArgumentException('Line ' . $n . ' has no amount.');
        }
        $out[] = [
            'account_id' => $acc, 'debit' => $d, 'credit' => $c,
            'cost_centre_id' => fp_ref($l['cost_centre_id'] ?? null, 'fp_cost_centres', 'cost centre'),
            'project_id' => fp_ref($l['project_id'] ?? null, 'fp_projects', 'project'),
            'description' => fp_str($l['description'] ?? '', 255),
            'party_type' => in_array($l['party_type'] ?? '', ['vendor', 'customer', 'employee'], true) ? $l['party_type'] : null,
            'party_id' => !empty($l['party_id']) ? (int) $l['party_id'] : null,
            'tax_code_id' => !empty($l['tax_code_id']) ? (int) $l['tax_code_id'] : null,
        ];
        $dr += fp_paise($d);
        $cr += fp_paise($c);
    }
    return [$out, $dr / 100, $cr / 100];
}

/** Writes a journal's lines with their base amounts; rounding differences of a few paise go to the largest line. */
function fp_lines_write(int $journalId, array $lines, float $rate): float
{
    fp_exec('DELETE FROM fp_journal_lines WHERE journal_id = ?', 'i', [$journalId]);
    $base = [];
    $drSum = 0;
    $crSum = 0;
    foreach ($lines as $i => $l) {
        $db = fp_paise((float) $l['debit'] * $rate);
        $cb = fp_paise((float) $l['credit'] * $rate);
        $base[$i] = [$db, $cb];
        $drSum += $db;
        $crSum += $cb;
    }
    $diff = $drSum - $crSum;
    $balancedInTxn = array_sum(array_map(static fn($l) => fp_paise($l['debit']), $lines)) === array_sum(array_map(static fn($l) => fp_paise($l['credit']), $lines));
    if ($diff !== 0 && $balancedInTxn && abs($diff) <= count($lines)) {
        // Conversion rounding only: put the paise on the biggest line of the heavier side.
        $side = $diff > 0 ? 0 : 1;
        $big = null;
        foreach ($base as $i => $b) {
            if ($b[$side] > 0 && ($big === null || $b[$side] > $base[$big][$side])) {
                $big = $i;
            }
        }
        $base[$big][$side] -= $diff > 0 ? $diff : -$diff;
        $drSum -= $side === 0 ? $diff : 0;
    }
    foreach ($lines as $i => $l) {
        fp_exec('INSERT INTO fp_journal_lines (journal_id, line_no, account_id, cost_centre_id, project_id, description, debit, credit, debit_base, credit_base,
                 tax_code_id, party_type, party_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', 'iiiiisssssisi',
                [$journalId, $i + 1, (int) $l['account_id'], $l['cost_centre_id'] ?? null, $l['project_id'] ?? null, ($l['description'] ?? '') ?: null,
                 fp_amt($l['debit']), fp_amt($l['credit']), fp_amt($base[$i][0] / 100), fp_amt($base[$i][1] / 100),
                 $l['tax_code_id'] ?? null, $l['party_type'] ?? null, $l['party_id'] ?? null]);
    }
    return $drSum / 100;
}

/** Saves a draft journal (new, or a draft / returned one). Returns its id. */
function fp_journal_save(?int $id, array $p): int
{
    fp_need('gl.prepare');
    $date = fp_date($p['jdate'] ?? '', true, 'Accounting date');
    $type = (string) ($p['type'] ?? 'manual');
    if (!in_array($type, ['manual', 'accrual', 'prepayment', 'adjustment', 'reversal', 'opening', 'intercompany'], true)) {
        $type = 'manual';
    }
    $entity = fp_ref($p['entity_id'] ?? 1, 'fp_entities', 'entity', true, true);
    $currency = fp_currency($p['currency'] ?? '');
    $rate = fp_fx($currency, $p['fx_rate'] ?? '', $date);
    $desc = fp_str($p['description'] ?? '', 500, true, 'Description');
    $ref = fp_str($p['reference'] ?? '', 120);
    $autoRev = $type === 'accrual' ? fp_date($p['auto_reverse_on'] ?? '', false, 'Reverse on') : null;
    if ($autoRev && $autoRev <= $date) {
        throw new InvalidArgumentException('An accrual reverses after its own date.');
    }
    $icEntity = null;
    $icAccount = null;
    if ($type === 'intercompany') {
        $icEntity = fp_ref($p['ic_entity_id'] ?? null, 'fp_entities', 'counterpart entity', true, true);
        if ($icEntity === $entity) {
            throw new InvalidArgumentException('An intercompany entry is between two different entities.');
        }
        $icAccount = fp_ref($p['ic_account_id'] ?? null, 'fp_accounts', 'counterpart account', true, true);
    }
    [$lines] = fp_lines_clean($p['lines'] ?? []);
    if (count($lines) < 2) {
        throw new InvalidArgumentException('A journal needs at least two lines.');
    }
    if ($type === 'intercompany') {
        $ic = fp_acct('ic_recv');
        $icp = fp_acct('ic_pay');
        if (!array_filter($lines, static fn($l) => in_array($l['account_id'], [$ic, $icp], true))) {
            throw new InvalidArgumentException('An intercompany entry must use the intercompany receivable or payable account on this side.');
        }
    }
    return fp_tx(static function () use ($id, $date, $type, $entity, $currency, $rate, $desc, $ref, $autoRev, $icEntity, $icAccount, $lines, $p) {
        if ($id) {
            $j = fp_journal($id);
            if (!$j || $j['status'] !== 'draft') {
                throw new InvalidArgumentException('Only a draft journal can be edited. A posted one is corrected by a reversal or an adjustment.');
            }
            if ($j['source'] && $j['source'] !== 'manual') {
                throw new InvalidArgumentException('This journal was made by the system and is changed through its document.');
            }
            fp_bump('fp_journals', $id, $p['version'] ?? 0);
            fp_exec('UPDATE fp_journals SET entity_id=?, jdate=?, type=?, reference=?, description=?, currency=?, fx_rate=?, auto_reverse_on=?, ic_entity_id=?, ic_account_id=? WHERE id=?',
                    'isssssssiii', [$entity, $date, $type, $ref ?: null, $desc, $currency, $rate, $autoRev, $icEntity, $icAccount, $id]);
            $total = fp_lines_write($id, $lines, (float) $rate);
            fp_exec('UPDATE fp_journals SET total = ? WHERE id = ?', 'si', [fp_amt($total), $id]);
            fp_audit('journal', $id, 'update', 'Draft journal updated: ' . $desc, $j, fp_journal($id));
            return $id;
        }
        $id = fp_exec('INSERT INTO fp_journals (entity_id, jdate, type, source, reference, description, currency, fx_rate, auto_reverse_on, ic_entity_id, ic_account_id,
                       reversal_of, prepared_by_id, prepared_by) VALUES (?,?,?,"manual",?,?,?,?,?,?,?,?,?,?)', 'isssssssiiiis',
                      [$entity, $date, $type, $ref ?: null, $desc, $currency, $rate, $autoRev, $icEntity, $icAccount,
                       !empty($p['reversal_of']) ? (int) $p['reversal_of'] : null, fp_me(), fp_actor()]);
        $total = fp_lines_write($id, $lines, (float) $rate);
        fp_exec('UPDATE fp_journals SET total = ? WHERE id = ?', 'si', [fp_amt($total), $id]);
        fp_audit('journal', $id, 'create', 'Journal drafted: ' . $desc . ' (' . fp_money($total) . ')');
        return $id;
    });
}

/** [debit, credit] totals of a journal in base, in paise. */
function fp_journal_totals(int $id): array
{
    $r = fp_one('SELECT COALESCE(SUM(debit_base),0) d, COALESCE(SUM(credit_base),0) c, COUNT(*) n FROM fp_journal_lines WHERE journal_id = ?', 'i', [$id]);
    return [fp_paise($r['d']), fp_paise($r['c']), (int) $r['n']];
}

/** Sends a draft for approval. Debits must equal credits first. */
function fp_journal_submit(int $id, $version): string
{
    fp_need('gl.prepare');
    $j = fp_journal($id);
    if (!$j || $j['status'] !== 'draft') {
        throw new InvalidArgumentException('Only a draft can be submitted.');
    }
    [$d, $c, $n] = fp_journal_totals($id);
    if ($n < 2) {
        throw new InvalidArgumentException('A journal needs at least two lines.');
    }
    if ($d !== $c) {
        throw new InvalidArgumentException('Debits (' . fp_money($d / 100) . ') and credits (' . fp_money($c / 100) . ') do not balance. The difference is ' . fp_money(abs($d - $c) / 100) . '.');
    }
    fp_period_check((string) $j['jdate']);
    return fp_tx(static function () use ($id, $j, $version) {
        fp_bump('fp_journals', $id, $version);
        fp_exec('UPDATE fp_journals SET status = "submitted", return_note = NULL WHERE id = ?', 'i', [$id]);
        fp_audit('journal', $id, 'submit', 'Journal submitted for approval');
        $state = fp_wf_start('journal', $id, (float) $j['total'], null, (int) $j['entity_id'], (int) $j['prepared_by_id']);
        return $state === 'approved' ? 'Journal posted.' : 'Submitted for approval.';
    });
}

/** The approval engine's answer for a journal. */
function fp_wf_on_journal(string $event, array $doc, string $comment): void
{
    $id = (int) $doc['id'];
    if ($event === 'approved') {
        fp_exec('UPDATE fp_journals SET status = "approved", approved_by = ? WHERE id = ?', 'si', [fp_actor(), $id]);
        fp_journal_post($id);
    } elseif ($event === 'rejected') {
        fp_exec('UPDATE fp_journals SET status = "rejected", return_note = ? WHERE id = ?', 'si', [$comment, $id]);
    } elseif ($event === 'returned') {
        fp_exec('UPDATE fp_journals SET status = "draft", return_note = ? WHERE id = ?', 'si', [$comment, $id]);
    }
}

/**
 * Posts an approved (or system) journal: the one place a journal becomes
 * part of the books. Checks the period, the balance and the accounts again
 * under a row lock, numbers it, and — for an intercompany entry — posts the
 * matching entry in the other entity.
 */
function fp_journal_post(int $id): void
{
    fp_tx(static function () use ($id) {
        $j = fp_one('SELECT * FROM fp_journals WHERE id = ? FOR UPDATE', 'i', [$id]);
        if (!$j || !in_array($j['status'], ['approved'], true)) {
            throw new InvalidArgumentException('This journal cannot be posted from status ' . ($j['status'] ?? '?') . '.');
        }
        [$d, $c, $n] = fp_journal_totals($id);
        if ($n < 2 || $d !== $c || $d === 0) {
            throw new InvalidArgumentException('Unbalanced journal refused: debits ' . fp_money($d / 100) . ', credits ' . fp_money($c / 100) . '.');
        }
        $bad = fp_one('SELECT a.code FROM fp_journal_lines l JOIN fp_accounts a ON a.id = l.account_id WHERE l.journal_id = ? AND (a.is_group = 1 OR a.active = 0) LIMIT 1', 'i', [$id]);
        if ($bad) {
            throw new InvalidArgumentException('Account ' . $bad['code'] . ' is a group or inactive; it cannot take entries.');
        }
        $period = empty($GLOBALS['fp_allow_closed']) ? fp_period_check((string) $j['jdate']) : (int) fp_period_for((string) $j['jdate'])['id'];
        $number = $j['number'] ?: fp_next_no('JV', (string) $j['jdate']);
        fp_exec('UPDATE fp_journals SET status = "posted", number = ?, period_id = ?, total = ?, posted_by = ?, posted_at = NOW() WHERE id = ?',
                'sissi', [$number, $period, fp_amt($d / 100), fp_actor(), $id]);
        if ($j['reversal_of']) {
            fp_exec('UPDATE fp_journals SET reversed_by = ? WHERE id = ? AND reversed_by IS NULL', 'ii', [$id, (int) $j['reversal_of']]);
        }
        fp_audit('journal', $id, 'post', 'Journal ' . $number . ' posted (' . fp_money($d / 100) . ')');
        if ($j['type'] === 'intercompany' && $j['ic_entity_id'] && !$j['ic_pair_id']) {
            fp_ic_mirror($j);
        }
    });
}

/**
 * The other half of an intercompany entry. Entity A recorded a receivable
 * from (or payable to) B; B gets the opposite: the counterpart account
 * against its intercompany payable (or receivable), for the same amount.
 */
function fp_ic_mirror(array $j): void
{
    $recv = fp_acct('ic_recv');
    $pay = fp_acct('ic_pay');
    $net = 0;
    foreach (fp_journal_lines((int) $j['id']) as $l) {
        if ((int) $l['account_id'] === $recv) {
            $net += fp_paise($l['debit_base']) - fp_paise($l['credit_base']);
        } elseif ((int) $l['account_id'] === $pay) {
            $net -= fp_paise($l['credit_base']) - fp_paise($l['debit_base']);
        }
    }
    if ($net === 0) {
        return;
    }
    $amt = abs($net) / 100;
    // A is owed by B (net > 0): B owes A — Dr counterpart, Cr IC payable. Otherwise the reverse.
    $lines = $net > 0
        ? [['account_id' => (int) $j['ic_account_id'], 'debit' => $amt, 'credit' => 0, 'description' => 'Intercompany: ' . $j['description']],
           ['account_id' => $pay, 'debit' => 0, 'credit' => $amt, 'description' => 'Intercompany payable']]
        : [['account_id' => $recv, 'debit' => $amt, 'credit' => 0, 'description' => 'Intercompany receivable'],
           ['account_id' => (int) $j['ic_account_id'], 'debit' => 0, 'credit' => $amt, 'description' => 'Intercompany: ' . $j['description']]];
    $mirror = fp_journal_system(['entity_id' => (int) $j['ic_entity_id'], 'jdate' => $j['jdate'], 'type' => 'intercompany', 'source' => 'ic', 'source_id' => (int) $j['id'],
                                 'reference' => $j['number'], 'description' => $j['description'], 'ic_entity_id' => (int) $j['entity_id']], $lines);
    fp_exec('UPDATE fp_journals SET ic_pair_id = ? WHERE id = ?', 'ii', [$mirror, (int) $j['id']]);
    fp_exec('UPDATE fp_journals SET ic_pair_id = ? WHERE id = ?', 'ii', [(int) $j['id'], $mirror]);
}

/**
 * A journal the system makes from a document that has already been through
 * its own approval: written and posted in one step, inside the caller's
 * transaction. $h: entity_id, jdate, type, source, source_id, reference,
 * description, currency, fx_rate. Lines are in the document's currency.
 */
function fp_journal_system(array $h, array $lines): int
{
    return fp_tx(static function () use ($h, $lines) {
        $lines = array_values(array_filter($lines, static fn($l) => fp_paise($l['debit'] ?? 0) !== 0 || fp_paise($l['credit'] ?? 0) !== 0));
        foreach ($lines as &$l) {
            $dr = fp_r2($l['debit'] ?? 0);
            $cr = fp_r2($l['credit'] ?? 0);
            // A negative amount on one side is a positive one on the other.
            if ($dr < 0) { $cr += -$dr; $dr = 0; }
            if ($cr < 0) { $dr += -$cr; $cr = 0; }
            $net = fp_r2($dr - $cr);
            $l['debit'] = $net > 0 ? $net : 0;
            $l['credit'] = $net < 0 ? -$net : 0;
        }
        unset($l);
        $lines = array_values(array_filter($lines, static fn($l) => fp_paise($l['debit']) !== 0 || fp_paise($l['credit']) !== 0));
        $currency = $h['currency'] ?? fp_setting('base_currency');
        $rate = (float) ($h['fx_rate'] ?? 1) ?: 1.0;
        $id = fp_exec('INSERT INTO fp_journals (entity_id, jdate, type, source, source_id, reference, description, currency, fx_rate, status, ic_entity_id, prepared_by_id, prepared_by, approved_by)
                       VALUES (?,?,?,?,?,?,?,?,?,"approved",?,?,?,?)', 'isssissssiiss',
                      [(int) ($h['entity_id'] ?? 1), $h['jdate'], $h['type'] ?? 'system', $h['source'] ?? null, $h['source_id'] ?? null,
                       isset($h['reference']) ? mb_substr((string) $h['reference'], 0, 120) : null, mb_substr((string) ($h['description'] ?? ''), 0, 500),
                       $currency, number_format($rate, 6, '.', ''), $h['ic_entity_id'] ?? null, fp_me(), fp_actor(), fp_actor()]);
        fp_lines_write($id, $lines, $rate);
        fp_journal_post($id);
        return $id;
    });
}

/**
 * Prepares the reversal of a posted manual journal as a draft that goes
 * through approval like any other. A journal made by a document (a bill, a
 * payment) is reversed through that document instead — its credit note or
 * cancellation — so the subledger and the ledger can never disagree.
 */
function fp_journal_reverse_draft(int $id, string $date, string $reason): int
{
    fp_need('gl.prepare');
    $j = fp_journal($id);
    if (!$j || $j['status'] !== 'posted') {
        throw new InvalidArgumentException('Only a posted journal can be reversed.');
    }
    if ($j['reversed_by']) {
        throw new InvalidArgumentException('This journal has already been reversed.');
    }
    if ($j['source'] && !in_array($j['source'], ['manual', 'recurring'], true)) {
        throw new InvalidArgumentException('This entry came from a ' . $j['source'] . ' document; reverse it through that document (credit note, cancellation).');
    }
    if (fp_one('SELECT id FROM fp_journals WHERE reversal_of = ? AND status IN ("draft","submitted","approved")', 'i', [$id])) {
        throw new InvalidArgumentException('A reversal of this journal is already being prepared.');
    }
    $reason = fp_str($reason, 400, true, 'The reason for the reversal');
    $date = fp_date($date, true, 'Reversal date');
    if ($date < $j['jdate']) {
        throw new InvalidArgumentException('A reversal cannot be dated before the journal it reverses.');
    }
    $lines = [];
    foreach (fp_journal_lines($id) as $l) {
        $lines[] = ['account_id' => $l['account_id'], 'debit' => $l['credit'], 'credit' => $l['debit'], 'cost_centre_id' => $l['cost_centre_id'],
                    'project_id' => $l['project_id'], 'description' => 'Reversal: ' . ($l['description'] ?? ''), 'party_type' => $l['party_type'], 'party_id' => $l['party_id']];
    }
    return fp_journal_save(null, ['jdate' => $date, 'type' => 'reversal', 'entity_id' => $j['entity_id'], 'currency' => $j['currency'], 'fx_rate' => $j['fx_rate'],
                                  'reference' => $j['number'], 'description' => 'Reversal of ' . $j['number'] . ': ' . $reason, 'lines' => $lines, 'reversal_of' => $id]);
}

/** Cancels a draft or rejected journal. Posted journals are never cancelled. */
function fp_journal_cancel(int $id, $version): void
{
    fp_need('gl.prepare');
    $j = fp_journal($id);
    if (!$j || !in_array($j['status'], ['draft', 'rejected'], true)) {
        throw new InvalidArgumentException('Only a draft or rejected journal can be cancelled.');
    }
    fp_bump('fp_journals', $id, $version);
    fp_exec('UPDATE fp_journals SET status = "cancelled" WHERE id = ?', 'i', [$id]);
    fp_audit('journal', $id, 'cancel', 'Journal cancelled');
}

/** Makes a posted journal repeat. Each run is posted automatically, as the template was approved. */
function fp_recurring_add(int $journalId, string $freq, string $next, ?string $end): int
{
    fp_need('gl.prepare');
    $j = fp_journal($journalId);
    if (!$j || $j['status'] !== 'posted' || $j['source'] !== 'manual') {
        throw new InvalidArgumentException('Only a posted manual journal can be made recurring — so the template itself has been approved.');
    }
    if (!in_array($freq, ['monthly', 'quarterly', 'yearly'], true)) {
        throw new InvalidArgumentException('Choose how often it repeats.');
    }
    $next = fp_date($next, true, 'First run date');
    $end = fp_date($end, false, 'End date');
    if ($next <= $j['jdate']) {
        throw new InvalidArgumentException('The first run must be after the template\'s own date.');
    }
    $id = fp_exec('INSERT INTO fp_recurring (journal_id, frequency, next_date, end_date, created_by) VALUES (?,?,?,?,?)', 'issss',
                  [$journalId, $freq, $next, $end, fp_actor()]);
    fp_audit('journal', $journalId, 'recurring', 'Set to repeat ' . $freq . ' from ' . $next . ($end ? ' to ' . $end : ''));
    return $id;
}

/** Posts every recurring journal and accrual reversal that has fallen due. Run by the scheduler. */
function fp_recurring_run(): int
{
    $n = 0;
    $today = date('Y-m-d');
    foreach (fp_all('SELECT * FROM fp_recurring WHERE active = 1 AND next_date <= ? AND (end_date IS NULL OR next_date <= end_date)', 's', [$today]) as $r) {
        $j = fp_journal((int) $r['journal_id']);
        if (!$j) {
            continue;
        }
        try {
            $lines = array_map(static fn($l) => ['account_id' => $l['account_id'], 'debit' => $l['debit'], 'credit' => $l['credit'], 'cost_centre_id' => $l['cost_centre_id'],
                                                 'project_id' => $l['project_id'], 'description' => $l['description']], fp_journal_lines((int) $j['id']));
            fp_journal_system(['entity_id' => $j['entity_id'], 'jdate' => $r['next_date'], 'type' => 'recurring', 'source' => 'recurring', 'source_id' => (int) $r['id'],
                               'reference' => $j['number'], 'description' => $j['description'] . ' (' . date('M Y', strtotime($r['next_date'])) . ')',
                               'currency' => $j['currency'], 'fx_rate' => $j['fx_rate']], $lines);
            $step = ['monthly' => '+1 month', 'quarterly' => '+3 month', 'yearly' => '+1 year'][$r['frequency']];
            $next = date('Y-m-d', strtotime($r['next_date'] . ' ' . $step));
            fp_exec('UPDATE fp_recurring SET last_run = ?, next_date = ?, runs = runs + 1, active = IF(end_date IS NOT NULL AND ? > end_date, 0, 1) WHERE id = ?',
                    'sssi', [$r['next_date'], $next, $next, (int) $r['id']]);
            $n++;
        } catch (Throwable $e) {
            error_log('FABO finance recurring ' . $r['id'] . ': ' . $e->getMessage());
        }
    }
    foreach (fp_all('SELECT * FROM fp_journals WHERE status = "posted" AND auto_reverse_on IS NOT NULL AND auto_reverse_on <= ? AND reversed_by IS NULL', 's', [$today]) as $j) {
        try {
            $lines = array_map(static fn($l) => ['account_id' => $l['account_id'], 'debit' => $l['credit'], 'credit' => $l['debit'], 'cost_centre_id' => $l['cost_centre_id'],
                                                 'project_id' => $l['project_id'], 'description' => 'Reversal: ' . $l['description']], fp_journal_lines((int) $j['id']));
            $rev = fp_journal_system(['entity_id' => $j['entity_id'], 'jdate' => $j['auto_reverse_on'], 'type' => 'reversal', 'source' => 'manual', 'source_id' => (int) $j['id'],
                                      'reference' => $j['number'], 'description' => 'Automatic reversal of accrual ' . $j['number'], 'currency' => $j['currency'], 'fx_rate' => $j['fx_rate']], $lines);
            fp_exec('UPDATE fp_journals SET reversed_by = ? WHERE id = ?', 'ii', [$rev, (int) $j['id']]);
            fp_exec('UPDATE fp_journals SET reversal_of = ? WHERE id = ?', 'ii', [(int) $j['id'], $rev]);
            $n++;
        } catch (Throwable $e) {
            error_log('FABO finance accrual reversal ' . $j['id'] . ': ' . $e->getMessage());
        }
    }
    return $n;
}

/* ============================================================== reports */

/**
 * The common filter every ledger figure is read through:
 *   entity (null = all, consolidated), from, to, cc, project, eliminate (drop intercompany).
 * Returns [sql, types, params] for journals j / lines l.
 */
function fp_ledger_where(array $f, bool $withFrom = true): array
{
    $sql = ' AND j.status = "posted"';
    $t = '';
    $p = [];
    if (!empty($f['entity'])) {
        $sql .= ' AND j.entity_id = ?';
        $t .= 'i';
        $p[] = (int) $f['entity'];
    }
    if ($withFrom && !empty($f['from'])) {
        $sql .= ' AND j.jdate >= ?';
        $t .= 's';
        $p[] = $f['from'];
    }
    if (!empty($f['to'])) {
        $sql .= ' AND j.jdate <= ?';
        $t .= 's';
        $p[] = $f['to'];
    }
    if (!empty($f['cc'])) {
        $sql .= ' AND l.cost_centre_id = ?';
        $t .= 'i';
        $p[] = (int) $f['cc'];
    }
    if (!empty($f['project'])) {
        $sql .= ' AND l.project_id = ?';
        $t .= 'i';
        $p[] = (int) $f['project'];
    }
    if (!empty($f['eliminate'])) {
        $sql .= ' AND j.type <> "intercompany"';
    }
    if (!empty($f['exclude_closing'])) {
        $sql .= ' AND j.type <> "closing"';
    }
    return [$sql, $t, $p];
}

/** Debit and credit totals per account for a filter: account_id => [dr, cr]. */
function fp_balances(array $f, bool $withFrom = true): array
{
    [$w, $t, $p] = fp_ledger_where($f, $withFrom);
    $out = [];
    foreach (fp_all('SELECT l.account_id, SUM(l.debit_base) dr, SUM(l.credit_base) cr FROM fp_journal_lines l JOIN fp_journals j ON j.id = l.journal_id
                      WHERE 1=1' . $w . ' GROUP BY l.account_id', $t, $p) as $r) {
        $out[(int) $r['account_id']] = [(float) $r['dr'], (float) $r['cr']];
    }
    return $out;
}

function fp_accounts_all(): array
{
    $out = [];
    foreach (fp_all('SELECT * FROM fp_accounts ORDER BY code') as $a) {
        $out[(int) $a['id']] = $a;
    }
    return $out;
}

/**
 * The trial balance for a period: opening, movement and closing per
 * account, with total debits and credits that must agree.
 */
function fp_trial_balance(array $f): array
{
    $accts = fp_accounts_all();
    $open = !empty($f['from']) ? fp_balances(['to' => date('Y-m-d', strtotime($f['from'] . ' -1 day'))] + $f, false) : [];
    $move = fp_balances($f);
    $rows = [];
    $tot = ['od' => 0, 'oc' => 0, 'md' => 0, 'mc' => 0, 'cd' => 0, 'cc' => 0];
    foreach ($accts as $id => $a) {
        if ((int) $a['is_group'] === 1) {
            continue;
        }
        [$od, $oc] = $open[$id] ?? [0, 0];
        [$md, $mc] = $move[$id] ?? [0, 0];
        if (fp_paise($od) === 0 && fp_paise($oc) === 0 && fp_paise($md) === 0 && fp_paise($mc) === 0) {
            continue;
        }
        $ob = fp_r2($od - $oc);
        $cb = fp_r2($ob + $md - $mc);
        $row = ['id' => $id, 'code' => $a['code'], 'name' => $a['name'], 'type' => $a['type'],
                'open_dr' => max(0, $ob), 'open_cr' => max(0, -$ob), 'dr' => fp_r2($md), 'cr' => fp_r2($mc),
                'close_dr' => max(0, $cb), 'close_cr' => max(0, -$cb)];
        $rows[] = $row;
        $tot['od'] += fp_paise($row['open_dr']);
        $tot['oc'] += fp_paise($row['open_cr']);
        $tot['md'] += fp_paise($row['dr']);
        $tot['mc'] += fp_paise($row['cr']);
        $tot['cd'] += fp_paise($row['close_dr']);
        $tot['cc'] += fp_paise($row['close_cr']);
    }
    return ['rows' => $rows, 'totals' => array_map(static fn($v) => $v / 100, $tot), 'balanced' => $tot['cd'] === $tot['cc'] && $tot['md'] === $tot['mc']];
}

/** Profit and loss for a period, grouped as the chart of accounts groups it. */
function fp_profit_loss(array $f): array
{
    $accts = fp_accounts_all();
    $move = fp_balances($f + ['exclude_closing' => 1]);
    $sections = ['revenue' => [], 'cogs' => [], 'opex' => [], 'other_income' => [], 'other_exp' => []];
    $sum = array_fill_keys(array_keys($sections), 0.0);
    foreach ($move as $id => [$dr, $cr]) {
        $a = $accts[$id] ?? null;
        if (!$a || !in_array($a['type'], ['income', 'expense'], true)) {
            continue;
        }
        $v = fp_r2(fp_natural($a['type'], $dr, $cr));
        if (fp_paise($v) === 0) {
            continue;
        }
        $sec = $a['type'] === 'income' ? ($a['subtype'] === 'revenue' ? 'revenue' : 'other_income')
             : ($a['subtype'] === 'cogs' ? 'cogs' : (in_array($a['subtype'], ['finance_cost', 'other'], true) ? 'other_exp' : 'opex'));
        $parent = $a['parent_id'] && isset($accts[(int) $a['parent_id']]) ? $accts[(int) $a['parent_id']]['name'] : '';
        $sections[$sec][] = ['id' => $id, 'code' => $a['code'], 'name' => $a['name'], 'group' => $parent, 'amount' => $v];
        $sum[$sec] += $v;
    }
    foreach ($sections as &$s) {
        usort($s, static fn($x, $y) => strcmp($x['code'], $y['code']));
    }
    unset($s);
    $gross = $sum['revenue'] - $sum['cogs'];
    $operating = $gross - $sum['opex'];
    $net = $operating + $sum['other_income'] - $sum['other_exp'];
    return ['sections' => $sections, 'sum' => array_map('fp_r2', $sum), 'gross' => fp_r2($gross), 'operating' => fp_r2($operating), 'net' => fp_r2($net),
            'margin' => $sum['revenue'] > 0 ? $net / $sum['revenue'] : null];
}

/**
 * The balance sheet at a date. Profit not yet closed into retained earnings
 * is shown as its own lines — this year's, and any earlier year not yet
 * closed — so assets always equal liabilities plus equity.
 */
function fp_balance_sheet(array $f): array
{
    $to = $f['to'] ?? date('Y-m-d');
    $accts = fp_accounts_all();
    $bal = fp_balances(['to' => $to] + $f, false);
    $out = ['asset' => [], 'liability' => [], 'equity' => []];
    $sum = ['asset' => 0.0, 'liability' => 0.0, 'equity' => 0.0];
    $pl = 0.0;
    foreach ($bal as $id => [$dr, $cr]) {
        $a = $accts[$id] ?? null;
        if (!$a) {
            continue;
        }
        if (in_array($a['type'], ['income', 'expense'], true)) {
            $pl += $cr - $dr;
            continue;
        }
        $v = fp_r2(fp_natural($a['type'], $dr, $cr));
        if (fp_paise($v) === 0) {
            continue;
        }
        $parent = $a['parent_id'] && isset($accts[(int) $a['parent_id']]) ? $accts[(int) $a['parent_id']]['name'] : '';
        $out[$a['type']][] = ['id' => $id, 'code' => $a['code'], 'name' => $a['name'], 'group' => $parent, 'subtype' => $a['subtype'], 'amount' => $v];
        $sum[$a['type']] += $v;
    }
    $fy = fp_fy_of($to);
    [$fyFrom] = fp_fy_range($fy);
    $cur = 0.0;
    foreach (fp_balances(['from' => $fyFrom, 'to' => $to] + $f) as $id => [$dr, $cr]) {
        $a = $accts[$id] ?? null;
        if ($a && in_array($a['type'], ['income', 'expense'], true)) {
            $cur += $cr - $dr;
        }
    }
    $prior = fp_r2($pl - $cur);
    $cur = fp_r2($cur);
    if (fp_paise($prior) !== 0) {
        $out['equity'][] = ['id' => null, 'code' => '', 'name' => 'Profit of earlier years not yet closed', 'group' => '', 'subtype' => 'retained', 'amount' => $prior];
    }
    $out['equity'][] = ['id' => null, 'code' => '', 'name' => 'Profit for the current year (FY ' . fp_fy_label($fy) . ')', 'group' => '', 'subtype' => 'retained', 'amount' => $cur];
    $sum['equity'] += $prior + $cur;
    foreach ($out as &$s) {
        usort($s, static fn($x, $y) => strcmp((string) $x['code'], (string) $y['code']) ?: strcmp($x['name'], $y['name']));
    }
    unset($s);
    $sum = array_map('fp_r2', $sum);
    return ['sections' => $out, 'sum' => $sum, 'difference' => fp_r2($sum['asset'] - $sum['liability'] - $sum['equity']), 'as_of' => $to];
}

/**
 * The cash-flow statement, direct method, from the ledger itself. Every
 * posted journal that moves cash or bank is classified by what is on its
 * other side: fixed assets → investing; borrowings and equity → financing;
 * everything else → operating. The total always equals the change in cash.
 */
function fp_cash_flow(array $f): array
{
    $cashIds = array_map(static fn($r) => (int) $r['id'], fp_all('SELECT id FROM fp_accounts WHERE subtype IN ("cash","bank")'));
    if (!$cashIds) {
        return ['sections' => [], 'sum' => [], 'opening' => 0, 'closing' => 0, 'net' => 0];
    }
    $in = implode(',', $cashIds);
    $accts = fp_accounts_all();
    [$w, $t, $p] = fp_ledger_where($f);
    $flows = ['operating' => [], 'investing' => [], 'financing' => []];
    $jrows = fp_all('SELECT j.id, SUM(CASE WHEN l.account_id IN (' . $in . ') THEN l.debit_base - l.credit_base ELSE 0 END) cash
                       FROM fp_journals j JOIN fp_journal_lines l ON l.journal_id = j.id WHERE 1=1' . $w . '
                      GROUP BY j.id HAVING cash <> 0', $t, $p);
    foreach ($jrows as $jr) {
        $cash = (float) $jr['cash'];
        $other = fp_all('SELECT account_id, SUM(debit_base - credit_base) amt FROM fp_journal_lines WHERE journal_id = ? AND account_id NOT IN (' . $in . ') GROUP BY account_id',
                        'i', [(int) $jr['id']]);
        $tot = array_sum(array_map(static fn($o) => abs((float) $o['amt']), $other));
        if ($tot <= 0) {
            continue;   // a transfer between two cash accounts moves no cash in total
        }
        foreach ($other as $o) {
            $a = $accts[(int) $o['account_id']];
            $share = $cash * abs((float) $o['amt']) / $tot;
            $cat = $a['cash_flow'];
            $label = $a['type'] === 'income' ? 'Receipts — ' . $a['name']
                   : ($a['subtype'] === 'receivable' ? 'Collections from customers'
                   : ($a['subtype'] === 'payable' ? 'Payments to vendors'
                   : ($a['type'] === 'expense' ? 'Paid — ' . $a['name'] : $a['name'])));
            $flows[$cat][$label] = ($flows[$cat][$label] ?? 0) + $share;
        }
    }
    $sum = [];
    foreach ($flows as $k => &$rows) {
        $rows = array_map('fp_r2', $rows);
        arsort($rows);
        $sum[$k] = fp_r2(array_sum($rows));
    }
    unset($rows);
    $opening = 0.0;
    if (!empty($f['from'])) {
        foreach (fp_balances(['to' => date('Y-m-d', strtotime($f['from'] . ' -1 day'))] + $f, false) as $id => [$dr, $cr]) {
            if (in_array($id, $cashIds, true)) {
                $opening += $dr - $cr;
            }
        }
    }
    $net = fp_r2(array_sum($sum));
    return ['sections' => $flows, 'sum' => $sum, 'opening' => fp_r2($opening), 'net' => $net, 'closing' => fp_r2($opening + $net)];
}

/** The ledger of one account: opening balance, then every posted line with its running balance. */
function fp_gl_account(int $accountId, array $f, int $limit = 500, int $offset = 0): array
{
    $a = fp_account($accountId);
    if (!$a) {
        return ['account' => null, 'rows' => [], 'opening' => 0, 'total' => 0];
    }
    $opening = 0.0;
    if (!empty($f['from'])) {
        [$w0, $t0, $p0] = fp_ledger_where(['to' => date('Y-m-d', strtotime($f['from'] . ' -1 day'))] + $f, false);
        $o = fp_one('SELECT COALESCE(SUM(l.debit_base),0) dr, COALESCE(SUM(l.credit_base),0) cr FROM fp_journal_lines l JOIN fp_journals j ON j.id = l.journal_id
                      WHERE l.account_id = ?' . $w0, 'i' . $t0, array_merge([$accountId], $p0));
        $opening = fp_natural($a['type'], (float) $o['dr'], (float) $o['cr']);
    }
    [$w, $t, $p] = fp_ledger_where($f);
    $count = (int) fp_val('SELECT COUNT(*) FROM fp_journal_lines l JOIN fp_journals j ON j.id = l.journal_id WHERE l.account_id = ?' . $w, 'i' . $t, array_merge([$accountId], $p));
    // The running balance needs the lines before this page too.
    if ($offset > 0) {
        $prev = fp_one('SELECT COALESCE(SUM(dr),0) dr, COALESCE(SUM(cr),0) cr FROM (SELECT l.debit_base dr, l.credit_base cr FROM fp_journal_lines l JOIN fp_journals j ON j.id = l.journal_id
                         WHERE l.account_id = ?' . $w . ' ORDER BY j.jdate, j.id, l.line_no LIMIT ' . (int) $offset . ') x', 'i' . $t, array_merge([$accountId], $p));
        $opening += fp_natural($a['type'], (float) $prev['dr'], (float) $prev['cr']);
    }
    $rows = fp_all('SELECT l.*, j.jdate, j.number, j.type AS jtype, j.description AS jdesc, j.source, j.source_id, j.reference, c.code AS cc_code
                      FROM fp_journal_lines l JOIN fp_journals j ON j.id = l.journal_id LEFT JOIN fp_cost_centres c ON c.id = l.cost_centre_id
                     WHERE l.account_id = ?' . $w . ' ORDER BY j.jdate, j.id, l.line_no LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset, 'i' . $t, array_merge([$accountId], $p));
    $run = $opening;
    foreach ($rows as &$r) {
        $run += fp_natural($a['type'], (float) $r['debit_base'], (float) $r['credit_base']);
        $r['running'] = fp_r2($run);
    }
    unset($r);
    return ['account' => $a, 'rows' => $rows, 'opening' => fp_r2($opening), 'total' => $count];
}

/** Income and expense per month of a financial year, for the dashboard. */
function fp_monthly_pl(int $fy, array $f = []): array
{
    [$from, $to] = fp_fy_range($fy);
    [$w, $t, $p] = fp_ledger_where(['from' => $from, 'to' => $to, 'exclude_closing' => 1] + $f);
    $out = [];
    for ($n = 1; $n <= 12; $n++) {
        $out[fp_fy_month($fy, $n)] = ['income' => 0.0, 'expense' => 0.0];
    }
    foreach (fp_all('SELECT DATE_FORMAT(j.jdate, "%Y-%m") m, a.type, SUM(l.debit_base) dr, SUM(l.credit_base) cr FROM fp_journal_lines l
                      JOIN fp_journals j ON j.id = l.journal_id JOIN fp_accounts a ON a.id = l.account_id
                     WHERE a.type IN ("income","expense")' . $w . ' GROUP BY m, a.type', $t, $p) as $r) {
        if (isset($out[$r['m']])) {
            $out[$r['m']][$r['type']] += fp_natural($r['type'], (float) $r['dr'], (float) $r['cr']);
        }
    }
    return $out;
}

/** Cash in and out per month (bank and cash accounts). */
function fp_monthly_cash(int $fy, array $f = []): array
{
    [$from, $to] = fp_fy_range($fy);
    [$w, $t, $p] = fp_ledger_where(['from' => $from, 'to' => $to] + $f);
    $out = [];
    for ($n = 1; $n <= 12; $n++) {
        $out[fp_fy_month($fy, $n)] = ['in' => 0.0, 'out' => 0.0];
    }
    foreach (fp_all('SELECT DATE_FORMAT(j.jdate, "%Y-%m") m, SUM(l.debit_base) dr, SUM(l.credit_base) cr FROM fp_journal_lines l
                      JOIN fp_journals j ON j.id = l.journal_id JOIN fp_accounts a ON a.id = l.account_id
                     WHERE a.subtype IN ("cash","bank")' . $w . ' GROUP BY m', $t, $p) as $r) {
        if (isset($out[$r['m']])) {
            $out[$r['m']]['in'] += (float) $r['dr'];
            $out[$r['m']]['out'] += (float) $r['cr'];
        }
    }
    return $out;
}

/** The balance of every cash and bank account now (or at a date). */
function fp_cash_position(?string $to = null, array $f = []): array
{
    $out = [];
    $bal = fp_balances(['to' => $to ?? date('Y-m-d')] + $f, false);
    foreach (fp_all('SELECT b.*, a.code FROM fp_bank_accounts b JOIN fp_accounts a ON a.id = b.account_id WHERE b.active = 1 ORDER BY b.kind, b.name') as $b) {
        [$dr, $cr] = $bal[(int) $b['account_id']] ?? [0, 0];
        $b['balance'] = fp_r2($dr - $cr);
        $out[] = $b;
    }
    return $out;
}

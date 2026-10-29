<?php
/**
 * Finance Portal — tax codes, the document-line arithmetic every bill,
 * order and invoice shares, the GST and TDS registers, the statutory filing
 * calendar, internal controls, audit evidence and compliance issues.
 *
 * Nothing about a rate or a due date is decided in code. A tax code carries
 * its own rate, kind and (optionally) its own accounts; the starting set is
 * ordinary records the organisation edits. GST splits half and half into
 * CGST and SGST for an intra-state code, and goes whole to IGST for an
 * inter-state one; TDS is withheld from what is paid to the vendor.
 *
 * The filing calendar is the portal's existing one (lib/finance.php, table
 * filings) — the same register the administrator keeps for a client — read
 * here for this organisation, so there is one calendar, not two.
 */

require_once __DIR__ . '/../finance.php';

function fp_tax_code(?int $id): ?array
{
    if (!$id) {
        return null;
    }
    static $memo = [];
    $k = fp_prefix() . $id;
    return $memo[$k] ??= fp_one('SELECT * FROM fp_tax_codes WHERE id = ?', 'i', [$id]);
}

/** Tax codes for a picker. $kinds narrows ('gst','igst','exempt' for GST; 'tds' for withholding). */
function fp_tax_options(array $kinds = ['gst', 'igst', 'exempt', 'other']): array
{
    $out = [];
    foreach (fp_all('SELECT id, code, name FROM fp_tax_codes WHERE active = 1 ORDER BY kind, rate, code') as $r) {
        $k = fp_tax_code((int) $r['id'])['kind'];
        if (in_array($k, $kinds, true)) {
            $out[(int) $r['id']] = $r['name'];
        }
    }
    return $out;
}

/**
 * The tax on a base amount: [total, components], components being
 * [account_id, amount, label]. $side 'input' (purchases: a credit we claim)
 * or 'output' (sales: tax we owe).
 */
function fp_tax_split(?int $codeId, float $base, string $side): array
{
    $tc = fp_tax_code($codeId);
    if (!$tc || in_array($tc['kind'], ['exempt', 'tds'], true) || (float) $tc['rate'] == 0.0) {
        return [0.0, []];
    }
    $rate = (float) $tc['rate'];
    $own = $side === 'input' ? $tc['input_account_id'] : $tc['output_account_id'];
    if ($tc['kind'] === 'igst' || $tc['kind'] === 'other') {
        $amt = fp_r2($base * $rate / 100);
        $acct = $own ? (int) $own : fp_acct($side === 'input' ? 'gst_in_igst' : 'gst_out_igst');
        return [$amt, [[$acct, $amt, $tc['kind'] === 'igst' ? 'IGST' : $tc['code']]]];
    }
    // Intra-state GST: half central, half state.
    $half = fp_r2($base * $rate / 200);
    if ($own) {
        return [$half * 2, [[(int) $own, $half * 2, 'GST']]];
    }
    return [fp_r2($half * 2), [[fp_acct($side === 'input' ? 'gst_in_cgst' : 'gst_out_cgst'), $half, 'CGST'],
                               [fp_acct($side === 'input' ? 'gst_in_sgst' : 'gst_out_sgst'), $half, 'SGST']]];
}

/** TDS withheld on a base: [amount, account]. */
function fp_tds_calc(?int $codeId, float $base): array
{
    $tc = fp_tax_code($codeId);
    if (!$tc || $tc['kind'] !== 'tds') {
        return [0.0, null];
    }
    if ($tc['threshold'] !== null && $base < (float) $tc['threshold']) {
        return [0.0, null];
    }
    return [fp_r2($base * (float) $tc['rate'] / 100), $tc['output_account_id'] ? (int) $tc['output_account_id'] : fp_acct('tds_payable')];
}

/**
 * Cleans the lines of a bill, order or invoice and works out each line's
 * amount and tax. Returns [lines, subtotal, tax]. $accountTypes limits the
 * accounts a line may use (expense/asset for purchases, income for sales).
 */
function fp_doc_lines(array $raw, string $side, array $accountTypes, bool $taxable = true): array
{
    $out = [];
    $sub = 0;
    $tax = 0;
    foreach ($raw as $i => $l) {
        $desc = trim((string) ($l['description'] ?? ''));
        $qtyIn = trim((string) ($l['qty'] ?? ''));
        $rateIn = trim((string) ($l['rate'] ?? ''));
        if ($desc === '' && $rateIn === '' && empty($l['account_id'])) {
            continue;
        }
        $n = count($out) + 1;
        if ($desc === '') {
            throw new InvalidArgumentException('Line ' . $n . ' needs a description.');
        }
        $acc = (int) ($l['account_id'] ?? 0);
        $a = $acc ? fp_account($acc) : null;
        if (!$a || (int) $a['is_group'] === 1 || (int) $a['active'] !== 1) {
            throw new InvalidArgumentException('Line ' . $n . ': choose an active, postable account.');
        }
        if (!in_array($a['type'], $accountTypes, true)) {
            throw new InvalidArgumentException('Line ' . $n . ': account ' . $a['code'] . ' is a ' . $a['type'] . ' account, which does not belong on this document.');
        }
        $qty = (float) (fp_dec($qtyIn === '' ? '1' : $qtyIn, true, 'Quantity on line ' . $n, 0.001, 1e9, 3));
        $rate = (float) fp_dec($rateIn, true, 'Rate on line ' . $n, 0);
        $amount = fp_r2($qty * $rate);
        $tc = $taxable && !empty($l['tax_code_id']) ? (int) $l['tax_code_id'] : null;
        if ($tc) {
            $code = fp_tax_code($tc);
            if (!$code || (int) $code['active'] !== 1 || $code['kind'] === 'tds') {
                throw new InvalidArgumentException('Line ' . $n . ': choose a valid GST code.');
            }
        }
        [$t] = fp_tax_split($tc, $amount, $side);
        $out[] = [
            'description' => mb_substr($desc, 0, 255), 'account_id' => $acc,
            'cost_centre_id' => fp_ref($l['cost_centre_id'] ?? null, 'fp_cost_centres', 'cost centre', false, true),
            'project_id' => fp_ref($l['project_id'] ?? null, 'fp_projects', 'project'),
            'qty' => $qty, 'rate' => $rate, 'amount' => $amount, 'tax_code_id' => $tc, 'tax_amount' => $t,
            'po_line_id' => !empty($l['po_line_id']) ? (int) $l['po_line_id'] : null,
            'hsn' => fp_str($l['hsn'] ?? '', 12),
        ];
        $sub += fp_paise($amount);
        $tax += fp_paise($t);
    }
    if (!$out) {
        throw new InvalidArgumentException('Add at least one line.');
    }
    return [$out, $sub / 100, $tax / 100];
}

/* ================================================================ GST / TDS */

/** GST position for a window, from the ledger: output tax owed against input credit. */
function fp_gst_summary(string $from, string $to, ?int $entity = null): array
{
    $f = ['from' => $from, 'to' => $to, 'entity' => $entity];
    $bal = fp_balances($f);
    $out = [];
    foreach (['cgst', 'sgst', 'igst'] as $c) {
        $in = fp_one('SELECT id FROM fp_accounts WHERE system_key = ?', 's', ['gst_in_' . $c]);
        $op = fp_one('SELECT id FROM fp_accounts WHERE system_key = ?', 's', ['gst_out_' . $c]);
        [$idr, $icr] = $in ? ($bal[(int) $in['id']] ?? [0, 0]) : [0, 0];
        [$odr, $ocr] = $op ? ($bal[(int) $op['id']] ?? [0, 0]) : [0, 0];
        $out[$c] = ['input' => fp_r2($idr - $icr), 'output' => fp_r2($ocr - $odr)];
        $out[$c]['net'] = fp_r2($out[$c]['output'] - $out[$c]['input']);
    }
    $out['total'] = ['input' => fp_r2(array_sum(array_column($out, 'input'))), 'output' => fp_r2(array_sum(array_column($out, 'output')))];
    $out['total']['net'] = fp_r2($out['total']['output'] - $out['total']['input']);
    return $out;
}

/** Outward supplies (for GSTR-1 preparation): invoices and credit notes issued in the window. */
function fp_gst_sales(string $from, string $to): array
{
    return fp_all('SELECT i.number, i.kind, i.invoice_date, i.subtotal, i.tax_total, i.total, i.fx_rate, i.place_of_supply, c.name AS customer, c.gstin, c.state
                     FROM fp_invoices i JOIN fp_customers c ON c.id = i.customer_id
                    WHERE i.status IN ("issued","partially_paid","paid") AND i.invoice_date BETWEEN ? AND ? ORDER BY i.invoice_date, i.number', 'ss', [$from, $to]);
}

/** Inward supplies with GST (input credit register). */
function fp_gst_purchases(string $from, string $to): array
{
    return fp_all('SELECT b.number, b.kind, b.bill_no, b.bill_date, b.subtotal, b.tax_total, b.total, b.fx_rate, v.name AS vendor, v.gstin, v.state
                     FROM fp_bills b JOIN fp_vendors v ON v.id = b.vendor_id
                    WHERE b.status IN ("approved","partially_paid","paid","on_hold") AND b.journal_id IS NOT NULL AND b.bill_date BETWEEN ? AND ?
                    ORDER BY b.bill_date, b.number', 'ss', [$from, $to]);
}

/** TDS withheld, by section and vendor (for 26Q preparation and deposit tracking). */
function fp_tds_register(string $from, string $to): array
{
    return fp_all('SELECT b.number, b.bill_date, b.subtotal, b.tds_total, t.section, t.code, t.rate, v.name AS vendor, v.pan
                     FROM fp_bills b JOIN fp_vendors v ON v.id = b.vendor_id JOIN fp_tax_codes t ON t.id = b.tds_code_id
                    WHERE b.kind = "bill" AND b.tds_total > 0 AND b.journal_id IS NOT NULL AND b.bill_date BETWEEN ? AND ? ORDER BY t.section, b.bill_date', 'ss', [$from, $to]);
}

function fp_tax_code_save(?int $id, array $p): int
{
    fp_need('tax.manage');
    $code = strtoupper(fp_str($p['code'] ?? '', 20, true, 'Code'));
    $name = fp_str($p['name'] ?? '', 120, true, 'Name');
    $kind = (string) ($p['kind'] ?? '');
    if (!in_array($kind, ['gst', 'igst', 'tds', 'exempt', 'other'], true)) {
        throw new InvalidArgumentException('Choose the kind of tax.');
    }
    $rate = fp_dec($p['rate'] ?? '0', true, 'Rate', 0, 100, 3);
    $sec = fp_str($p['section'] ?? '', 20);
    $thr = fp_dec($p['threshold'] ?? '', false, 'Threshold', 0);
    $in = fp_ref($p['input_account_id'] ?? null, 'fp_accounts', 'input account');
    $out = fp_ref($p['output_account_id'] ?? null, 'fp_accounts', 'output account');
    $active = !isset($p['active']) || !empty($p['active']) ? 1 : 0;
    if (fp_one('SELECT id FROM fp_tax_codes WHERE code = ? AND id <> ?', 'si', [$code, (int) $id])) {
        throw new InvalidArgumentException('Tax code ' . $code . ' already exists.');
    }
    $v = [$code, $name, $kind, $rate, $sec ?: null, $thr, $in, $out, $active];
    if ($id) {
        $before = fp_tax_code($id);
        fp_exec('UPDATE fp_tax_codes SET code=?, name=?, kind=?, rate=?, section=?, threshold=?, input_account_id=?, output_account_id=?, active=? WHERE id=?', 'ssssssiiii', array_merge($v, [$id]));
        fp_audit('tax_code', $id, 'update', 'Tax code ' . $code . ' changed', $before, $p);
        return $id;
    }
    $id = fp_exec('INSERT INTO fp_tax_codes (code, name, kind, rate, section, threshold, input_account_id, output_account_id, active) VALUES (?,?,?,?,?,?,?,?,?)', 'ssssssiii', $v);
    fp_audit('tax_code', $id, 'create', 'Tax code ' . $code . ' added');
    return $id;
}

/* ======================================================= filing calendar */

/**
 * The organisation's statutory filings: the portal's filing register for
 * this organisation (client_id = the organisation; ours is 0).
 */
function fp_filings(?array $win = null, string $state = ''): array
{
    return fabo_filings(fp_org(), $state, '', 400, $win);
}

function fp_filing_metrics(): array
{
    return fabo_filing_metrics(fp_org());
}

/* ===================================================== controls & audit */

/** The period key a control is completed for: '2026-10', '2026-Q3', 'FY2026'. */
function fp_control_period(string $freq, string $date): string
{
    if ($freq === 'yearly') {
        return 'FY' . fp_fy_of($date);
    }
    if ($freq === 'quarterly') {
        return fp_fy_of($date) . '-Q' . (int) ceil(fp_fy_month_no($date) / 3);
    }
    return date('Y-m', strtotime($date));
}

/**
 * What the system can check by itself for a control in a month: null when
 * it cannot tell, otherwise [ok, detail].
 */
function fp_control_auto(?string $key, string $month): ?array
{
    $from = $month . '-01';
    $to = date('Y-m-t', strtotime($from));
    switch ($key) {
        case 'drafts':
            $n = (int) fp_val('SELECT COUNT(*) FROM fp_journals WHERE jdate BETWEEN ? AND ? AND status IN ("draft","submitted","approved")', 'ss', [$from, $to]);
            return [$n === 0, $n ? $n . ' journal(s) not posted' : 'Every journal posted'];
        case 'bank_rec':
            $n = (int) fp_val('SELECT COUNT(*) FROM fp_bank_lines WHERE status = "unmatched" AND txn_date <= ?', 's', [$to]);
            $imp = (int) fp_val('SELECT COUNT(*) FROM fp_bank_lines WHERE txn_date BETWEEN ? AND ?', 'ss', [$from, $to]);
            return [$imp > 0 && $n === 0, $imp === 0 ? 'No statement imported for the month' : ($n ? $n . ' statement line(s) unmatched' : 'All statement lines matched')];
        case 'depreciation':
            $active = (int) fp_val('SELECT COUNT(*) FROM fp_assets WHERE status = "active" AND in_service_on <= ?', 's', [$to]);
            $ran = (bool) fp_one('SELECT id FROM fp_dep_runs WHERE period = ?', 's', [$month]);
            return [$ran || $active === 0, $ran ? 'Run' : ($active ? 'Not run (' . $active . ' assets)' : 'No assets in service')];
        case 'ap_pending':
            $n = (int) fp_val('SELECT COUNT(*) FROM fp_bills WHERE status IN ("submitted","under_review","awaiting_approval") AND bill_date <= ?', 's', [$to]);
            return [$n === 0, $n ? $n . ' bill(s) still in approval' : 'No bills waiting'];
        case 'payroll':
            $j = (bool) fp_one('SELECT id FROM fp_journals WHERE source = "payroll" AND status = "posted" AND jdate BETWEEN ? AND ?', 'ss', [$from, $to]);
            return [$j, $j ? 'Payroll journal posted' : 'No payroll journal for the month'];
    }
    return null;
}

function fp_control_record(int $controlId, string $period, string $status, string $note): int
{
    fp_need('controls.manage');
    $c = fp_one('SELECT * FROM fp_controls WHERE id = ?', 'i', [$controlId]);
    if (!$c) {
        throw new InvalidArgumentException('That control no longer exists.');
    }
    if (!in_array($status, ['done', 'exception', 'na'], true)) {
        throw new InvalidArgumentException('Choose done, exception or not applicable.');
    }
    $note = fp_str($note, 1000, $status !== 'done', 'A note on the exception');
    fp_exec('INSERT INTO fp_control_runs (control_id, period, status, note, actor_id, actor) VALUES (?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE status = VALUES(status), note = VALUES(note), actor_id = VALUES(actor_id), actor = VALUES(actor), created_at = NOW()',
            'isssis', [$controlId, $period, $status, $note ?: null, fp_me(), fp_actor()]);
    $id = (int) fp_one('SELECT id FROM fp_control_runs WHERE control_id = ? AND period = ?', 'is', [$controlId, $period])['id'];
    fp_attach_many('control', $id);
    fp_audit('control', $id, 'record', $c['name'] . ' — ' . $period . ': ' . $status . ($note ? ' (' . $note . ')' : ''));
    return $id;
}

function fp_issue_save(?int $id, array $p): int
{
    fp_need('tax.manage');
    $title = fp_str($p['title'] ?? '', 190, true, 'Title');
    $area = fp_str($p['area'] ?? '', 60, true, 'Area');
    $sev = in_array($p['severity'] ?? '', ['low', 'medium', 'high', 'critical'], true) ? $p['severity'] : 'medium';
    $owner = (int) ($p['owner_person_id'] ?? 0) ?: null;
    if ($owner !== null && !fp_is_member($owner)) {
        throw new InvalidArgumentException('Choose an owner from your organisation.');
    }
    $due = fp_date($p['due_date'] ?? '', false, 'Due date');
    $status = in_array($p['status'] ?? '', ['open', 'in_progress', 'resolved', 'accepted'], true) ? $p['status'] : 'open';
    $res = fp_str($p['resolution'] ?? '', 1000, in_array($status, ['resolved', 'accepted'], true), 'How it was resolved');
    $details = fp_str($p['details'] ?? '', 5000);
    if ($id) {
        $before = fp_one('SELECT * FROM fp_issues WHERE id = ?', 'i', [$id]);
        fp_bump('fp_issues', $id, $p['version'] ?? 0);
        fp_exec('UPDATE fp_issues SET title=?, area=?, severity=?, owner_person_id=?, due_date=?, status=?, details=?, resolution=? WHERE id=?', 'sssissssi',
                [$title, $area, $sev, $owner, $due, $status, $details ?: null, $res ?: null, $id]);
        fp_audit('issue', $id, 'update', 'Compliance issue updated: ' . $title . ' (' . $status . ')', $before, $p);
        return $id;
    }
    $id = fp_exec('INSERT INTO fp_issues (title, area, severity, owner_person_id, due_date, status, details, resolution, prepared_by_id, prepared_by) VALUES (?,?,?,?,?,?,?,?,?,?)',
                  'sssissssis', [$title, $area, $sev, $owner, $due, $status, $details ?: null, $res ?: null, fp_me(), fp_actor()]);
    fp_audit('issue', $id, 'create', 'Compliance issue raised: ' . $title);
    if ($owner !== null) {
        fp_notify([$owner], 'Compliance issue assigned to you', $title . ($due ? ' — due ' . fp_fmt_date($due) : ''), 'controls', ['tab' => 'issues']);
    }
    return $id;
}

/** Audit evidence: a titled bundle of files, kept at least as long as the retention setting. */
function fp_evidence_add(array $p): int
{
    fp_need('controls.manage');
    $title = fp_str($p['title'] ?? '', 190, true, 'Title');
    $area = fp_str($p['area'] ?? '', 60, true, 'Area');
    $fy = (int) ($p['fy'] ?? fp_fy_of());
    [, $end] = fp_fy_range($fy);
    $keep = date('Y-m-d', strtotime($end . ' +' . max(1, (int) fp_setting('retention_years')) . ' year'));
    $id = fp_exec('INSERT INTO fp_evidence (title, area, fy, notes, keep_until, created_by) VALUES (?,?,?,?,?,?)', 'ssisss',
                  [$title, $area, $fy, fp_str($p['notes'] ?? '', 500) ?: null, $keep, fp_actor()]);
    if (!fp_attach_many('evidence', $id)) {
        throw new InvalidArgumentException('Attach at least one file as evidence.');
    }
    fp_audit('evidence', $id, 'create', 'Audit evidence filed: ' . $title . ' (kept until ' . $keep . ')');
    return $id;
}

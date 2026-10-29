<?php
/**
 * Finance Portal — payables and procurement.
 *
 *   Vendor        created as a draft, verified through approval before it can
 *                 be ordered from or paid. A change to an active vendor's bank
 *                 details, GSTIN, PAN or name is held aside and approved before
 *                 it takes effect — the classic payment-fraud route is closed.
 *   Requisition   anyone asks to buy something; their manager approves.
 *   Purchase order raised against an active vendor, approved by the budget
 *                 holder (and Finance above a limit); amended only by a new,
 *                 re-approved revision, with every earlier revision kept.
 *   Goods receipt what actually arrived, by someone other than whoever raised
 *                 the order.
 *   Bill          entered, validated (duplicates, accounts and tax, three-way
 *                 match, budget), approved, then posted: expense and input tax
 *                 against the vendor's payable, TDS withheld. Paid through
 *                 Treasury, in part or in full. Corrected by a debit note.
 *
 * Bill statuses and the only moves between them:
 *   draft → submitted → under_review → awaiting_approval → approved
 *        → partially_paid → paid; on_hold from any open status (and back);
 *   rejected from approval; returned goes back to draft; cancelled only
 *   before posting.
 */

/* ================================================================ vendors */

function fp_vendor(int $id): ?array
{
    return fp_one('SELECT * FROM fp_vendors WHERE id = ?', 'i', [$id]);
}

function fp_vendor_options(bool $activeOnly = true): array
{
    $out = [];
    foreach (fp_all('SELECT id, code, name, status FROM fp_vendors' . ($activeOnly ? ' WHERE status = "active"' : ' WHERE status <> "rejected"') . ' ORDER BY name') as $r) {
        $out[(int) $r['id']] = $r['name'] . ' (' . $r['code'] . ')' . ($r['status'] !== 'active' ? ' — ' . $r['status'] : '');
    }
    return $out;
}

function fp_vendor_statuses(): array
{
    return ['draft' => ['Draft', ''], 'pending' => ['Awaiting verification', 'amber'], 'active' => ['Active', 'green'],
            'blocked' => ['Blocked', 'red'], 'rejected' => ['Rejected', 'red']];
}

/** Fields whose change on an active vendor must be approved first. */
function fp_vendor_sensitive(): array
{
    return ['name', 'legal_name', 'gstin', 'pan', 'bank_name', 'bank_account', 'bank_ifsc', 'upi'];
}

function fp_check_gstin(string $g): void
{
    if ($g !== '' && !preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][0-9A-Z]Z[0-9A-Z]$/', $g)) {
        throw new InvalidArgumentException('That GSTIN does not look right: 15 characters, e.g. 29ABCDE1234F1Z5.');
    }
}

function fp_check_pan(string $p): void
{
    if ($p !== '' && !preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $p)) {
        throw new InvalidArgumentException('That PAN does not look right: 10 characters, e.g. ABCDE1234F.');
    }
}

function fp_vendor_save(?int $id, array $p): array
{
    fp_need('vendor.manage');
    $v = [
        'name' => fp_str($p['name'] ?? '', 190, true, 'Vendor name'),
        'legal_name' => fp_str($p['legal_name'] ?? '', 190),
        'category' => fp_str($p['category'] ?? '', 80),
        'gstin' => strtoupper(fp_str($p['gstin'] ?? '', 20)),
        'pan' => strtoupper(fp_str($p['pan'] ?? '', 12)),
        'email' => fp_str($p['email'] ?? '', 190),
        'phone' => fp_str($p['phone'] ?? '', 40),
        'address' => fp_str($p['address'] ?? '', 500),
        'state' => fp_str($p['state'] ?? '', 60),
        'payment_terms_days' => (string) max(0, min(365, (int) ($p['payment_terms_days'] ?? 30))),
        'tds_code_id' => (string) (fp_ref($p['tds_code_id'] ?? null, 'fp_tax_codes', 'TDS code') ?? ''),
        'msme' => !empty($p['msme']) ? '1' : '0',
        'bank_name' => fp_str($p['bank_name'] ?? '', 120),
        'bank_account' => preg_replace('/\s+/', '', fp_str($p['bank_account'] ?? '', 40)),
        'bank_ifsc' => strtoupper(fp_str($p['bank_ifsc'] ?? '', 15)),
        'upi' => fp_str($p['upi'] ?? '', 80),
        'notes' => fp_str($p['notes'] ?? '', 500),
    ];
    fp_check_gstin($v['gstin']);
    fp_check_pan($v['pan']);
    if ($v['email'] !== '' && !filter_var($v['email'], FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('That email address is not valid.');
    }
    if ($v['bank_ifsc'] !== '' && !preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $v['bank_ifsc'])) {
        throw new InvalidArgumentException('That IFSC does not look right: 11 characters, e.g. HDFC0001234.');
    }
    if ($v['bank_account'] !== '' && !preg_match('/^[0-9A-Za-z]{6,34}$/', $v['bank_account'])) {
        throw new InvalidArgumentException('A bank account number is 6 to 34 letters and digits.');
    }
    if ($v['gstin'] !== '' && ($dup = fp_one('SELECT code FROM fp_vendors WHERE gstin = ? AND id <> ? AND status <> "rejected"', 'si', [$v['gstin'], (int) $id]))) {
        throw new InvalidArgumentException('Vendor ' . $dup['code'] . ' already has GSTIN ' . $v['gstin'] . '.');
    }
    $cols = array_keys($v);
    $null = static fn($x) => $x === '' ? null : $x;
    if ($id) {
        $before = fp_vendor($id);
        if (!$before) {
            throw new InvalidArgumentException('That vendor no longer exists.');
        }
        fp_bump('fp_vendors', $id, $p['version'] ?? 0);
        if ($before['status'] === 'active' || $before['status'] === 'blocked') {
            $changes = [];
            foreach (fp_vendor_sensitive() as $k) {
                if ((string) ($before[$k] ?? '') !== $v[$k]) {
                    $changes[$k] = $v[$k];
                }
                $v[$k] = (string) ($before[$k] ?? '');    // the current value stays until the change is approved
            }
            $set = implode(', ', array_map(static fn($c) => $c . ' = ?', $cols));
            fp_exec('UPDATE fp_vendors SET ' . $set . ' WHERE id = ?', str_repeat('s', count($cols)) . 'i', array_merge(array_map($null, array_values($v)), [$id]));
            if ($changes) {
                if ($before['pending_json']) {
                    throw new InvalidArgumentException('A change to this vendor\'s details is already waiting for approval.');
                }
                fp_exec('UPDATE fp_vendors SET pending_json = ? WHERE id = ?', 'si', [json_encode($changes), $id]);
                fp_audit('vendor', $id, 'change_request', 'Change to ' . implode(', ', array_keys($changes)) . ' requested', fp_vendor_public($before), $changes);
                fp_wf_start('vendor', $id, 0, null, 1, fp_me());
                return [$id, 'Saved. The change to ' . implode(', ', array_map(static fn($k) => str_replace('_', ' ', $k), array_keys($changes)))
                             . ' takes effect once it is approved; until then payments use the current details.'];
            }
            fp_audit('vendor', $id, 'update', 'Vendor ' . $before['code'] . ' updated', fp_vendor_public($before), fp_vendor_public(fp_vendor($id)));
            return [$id, 'Vendor saved.'];
        }
        $set = implode(', ', array_map(static fn($c) => $c . ' = ?', $cols));
        fp_exec('UPDATE fp_vendors SET ' . $set . ' WHERE id = ?', str_repeat('s', count($cols)) . 'i', array_merge(array_map($null, array_values($v)), [$id]));
        fp_audit('vendor', $id, 'update', 'Vendor ' . $before['code'] . ' updated', fp_vendor_public($before), fp_vendor_public(fp_vendor($id)));
        return [$id, 'Vendor saved.'];
    }
    $code = fp_next_code('fp_vendors', 'V');
    $id = fp_exec('INSERT INTO fp_vendors (code, ' . implode(', ', $cols) . ', prepared_by_id, prepared_by) VALUES (?' . str_repeat(',?', count($cols)) . ',?,?)',
                  's' . str_repeat('s', count($cols)) . 'is', array_merge([$code], array_map($null, array_values($v)), [fp_me(), fp_actor()]));
    fp_audit('vendor', $id, 'create', 'Vendor ' . $code . ' ' . $v['name'] . ' created (draft)');
    return [$id, 'Vendor ' . $code . ' created as a draft. Submit it for verification before ordering from or paying it.'];
}

/** A vendor as the audit trail may record it: the account number masked. */
function fp_vendor_public(?array $v): ?array
{
    if ($v && !empty($v['bank_account'])) {
        $v['bank_account'] = fp_mask((string) $v['bank_account']);
    }
    return $v;
}

/** V0001, C0001: the next master code. */
function fp_next_code(string $table, string $prefix, string $col = 'code'): string
{
    $r = fp_one('SELECT MAX(CAST(SUBSTRING(' . $col . ', ' . (strlen($prefix) + 1) . ') AS UNSIGNED)) n FROM ' . $table . ' WHERE ' . $col . ' REGEXP ?', 's', ['^' . $prefix . '[0-9]+$']);
    return $prefix . str_pad((string) ((int) ($r['n'] ?? 0) + 1), 4, '0', STR_PAD_LEFT);
}

function fp_vendor_submit(int $id, $version): string
{
    fp_need('vendor.manage');
    $v = fp_vendor($id);
    if (!$v || !in_array($v['status'], ['draft', 'rejected'], true)) {
        throw new InvalidArgumentException('Only a draft or rejected vendor can be sent for verification.');
    }
    if ($v['pan'] === null && $v['gstin'] === null) {
        throw new InvalidArgumentException('Give the vendor\'s PAN or GSTIN before verification.');
    }
    return fp_tx(static function () use ($id, $version) {
        fp_bump('fp_vendors', $id, $version);
        fp_exec('UPDATE fp_vendors SET status = "pending" WHERE id = ?', 'i', [$id]);
        fp_audit('vendor', $id, 'submit', 'Vendor sent for verification');
        fp_wf_start('vendor', $id, 0, null, 1, fp_me());
        return 'Sent for verification.';
    });
}

function fp_wf_on_vendor(string $event, array $v, string $comment): void
{
    $id = (int) $v['id'];
    $pending = $v['pending_json'] ? json_decode((string) $v['pending_json'], true) : null;
    if ($event === 'approved') {
        if (is_array($pending)) {
            $set = [];
            $vals = [];
            foreach ($pending as $k => $val) {
                if (in_array($k, fp_vendor_sensitive(), true)) {
                    $set[] = $k . ' = ?';
                    $vals[] = $val === '' ? null : $val;
                }
            }
            if ($set) {
                fp_exec('UPDATE fp_vendors SET ' . implode(', ', $set) . ', pending_json = NULL, verified_by = ?, verified_at = NOW() WHERE id = ?',
                        str_repeat('s', count($vals)) . 'si', array_merge($vals, [fp_actor(), $id]));
            }
            fp_audit('vendor', $id, 'change_applied', 'Approved change applied: ' . implode(', ', array_keys($pending)));
            return;
        }
        fp_exec('UPDATE fp_vendors SET status = "active", verified_by = ?, verified_at = NOW() WHERE id = ?', 'si', [fp_actor(), $id]);
        return;
    }
    if (is_array($pending)) {
        fp_exec('UPDATE fp_vendors SET pending_json = NULL WHERE id = ?', 'i', [$id]);
        fp_audit('vendor', $id, 'change_' . $event, 'Requested change ' . $event . ': ' . $comment);
        return;
    }
    fp_exec('UPDATE fp_vendors SET status = ? WHERE id = ?', 'si', [$event === 'rejected' ? 'rejected' : 'draft', $id]);
}

function fp_vendor_block(int $id, bool $block, string $reason, $version): string
{
    fp_need($block ? 'vendor.manage' : 'ap.override');
    $v = fp_vendor($id);
    if (!$v || ($block && $v['status'] !== 'active') || (!$block && $v['status'] !== 'blocked')) {
        throw new InvalidArgumentException($block ? 'Only an active vendor can be blocked.' : 'This vendor is not blocked.');
    }
    $reason = fp_str($reason, 500, true, 'The reason');
    fp_bump('fp_vendors', $id, $version);
    fp_exec('UPDATE fp_vendors SET status = ? WHERE id = ?', 'si', [$block ? 'blocked' : 'active', $id]);
    fp_audit('vendor', $id, $block ? 'block' : 'unblock', 'Vendor ' . ($block ? 'blocked' : 'unblocked') . ': ' . $reason);
    return $block ? 'Vendor blocked: no orders or payments until unblocked.' : 'Vendor unblocked.';
}

/**
 * Brings in the vendors already kept on this organisation's IT and Admin &
 * Facilities desks, as draft vendors to verify. Run as often as wanted: a
 * vendor already brought in is skipped.
 */
function fp_vendor_import_desks(): array
{
    fp_need('vendor.manage');
    $made = 0;
    $seen = 0;
    foreach (['it', 'fa'] as $desk) {
        $table = $desk . '_' . (fp_org() > 0 ? 'o' . fp_org() . '_' : '') . 'vendors';
        try {
            $rows = fabo_all('SELECT * FROM `' . $table . '` WHERE active = 1');
        } catch (Throwable $e) {
            continue;   // that desk is not in use here
        }
        foreach ($rows as $r) {
            $seen++;
            $ref = $desk . ':' . $r['id'];
            if (fp_one('SELECT id FROM fp_vendors WHERE source_ref = ? OR name = ?', 'ss', [$ref, $r['name']])) {
                continue;
            }
            $code = fp_next_code('fp_vendors', 'V');
            $id = fp_exec('INSERT INTO fp_vendors (code, name, category, email, phone, notes, source_ref, prepared_by_id, prepared_by) VALUES (?,?,?,?,?,?,?,?,?)', 'sssssssis',
                          [$code, mb_substr((string) $r['name'], 0, 190), $desk === 'it' ? 'IT' : 'Facilities', $r['email'] ?: null, $r['phone'] ?: null,
                           mb_substr(trim(($r['services'] ? 'Services: ' . $r['services'] . '. ' : '') . ($r['contact'] ? 'Contact: ' . $r['contact'] : '')), 0, 500) ?: null,
                           $ref, fp_me(), fp_actor()]);
            fp_audit('vendor', $id, 'import', 'Vendor brought in from the ' . ($desk === 'it' ? 'IT' : 'Facilities') . ' desk');
            $made++;
        }
    }
    return [$made, $seen];
}

/** What the organisation owes a vendor, and what it has bought from them. */
function fp_vendor_balance(int $vendorId): array
{
    $r = fp_one('SELECT COALESCE(SUM(CASE WHEN kind = "bill" THEN total * fx_rate ELSE 0 END),0) billed,
                        COALESCE(SUM(CASE WHEN kind = "debit_note" THEN total * fx_rate ELSE 0 END),0) notes,
                        COALESCE(SUM(CASE WHEN kind = "bill" THEN (total - paid_amount) * fx_rate ELSE 0 END),0) open_bills
                   FROM fp_bills WHERE vendor_id = ? AND journal_id IS NOT NULL', 'i', [$vendorId]);
    $paid = fp_val('SELECT COALESCE(SUM(amount * fx_rate),0) FROM fp_payments WHERE payee_type = "vendor" AND payee_id = ? AND status = "completed"', 'i', [$vendorId]);
    $unappliedNotes = fp_val('SELECT COALESCE(SUM((b.total - b.paid_amount) * b.fx_rate),0) FROM fp_bills b WHERE b.vendor_id = ? AND b.kind = "debit_note" AND b.journal_id IS NOT NULL', 'i', [$vendorId]);
    return ['billed' => fp_r2($r['billed']), 'notes' => fp_r2($r['notes']), 'paid' => fp_r2($paid), 'outstanding' => fp_r2((float) $r['open_bills'] - $unappliedNotes)];
}

/** The vendor's account as we see it: every posted bill, note and payment, with a running balance (what we owe). */
function fp_vendor_ledger(int $vendorId): array
{
    $ap = fp_acct('ap');
    $rows = fp_all('SELECT j.id AS jid, j.jdate, j.number, j.description, j.source, j.source_id, l.debit_base, l.credit_base FROM fp_journal_lines l JOIN fp_journals j ON j.id = l.journal_id
                     WHERE j.status = "posted" AND l.account_id = ? AND l.party_type = "vendor" AND l.party_id = ? ORDER BY j.jdate, j.id', 'ii', [$ap, $vendorId]);
    $run = 0.0;
    foreach ($rows as &$r) {
        $run += (float) $r['credit_base'] - (float) $r['debit_base'];
        $r['balance'] = fp_r2($run);
    }
    unset($r);
    return $rows;
}

/* ============================================================ requisitions */

function fp_pr(int $id): ?array
{
    return fp_one('SELECT * FROM fp_requisitions WHERE id = ?', 'i', [$id]);
}

function fp_pr_save(?int $id, array $p): int
{
    if (!fp_self_writable()) {
        fabo_deny('Finance is view only here.', 403);
    }
    $purpose = fp_str($p['purpose'] ?? '', 500, true, 'What it is for');
    $cc = fp_ref($p['cost_centre_id'] ?? null, 'fp_cost_centres', 'cost centre', true, true);
    $project = fp_ref($p['project_id'] ?? null, 'fp_projects', 'project');
    $needed = fp_date($p['needed_by'] ?? '', false, 'Needed by');
    $vendor = fp_ref($p['suggested_vendor_id'] ?? null, 'fp_vendors', 'vendor');
    $lines = [];
    $total = 0;
    foreach ((array) ($p['lines'] ?? []) as $l) {
        $d = trim((string) ($l['description'] ?? ''));
        if ($d === '') {
            continue;
        }
        $qty = (float) fp_dec($l['qty'] ?? '1', true, 'Quantity', 0.001, 1e9, 3);
        $rate = (float) fp_dec($l['est_rate'] ?? '', true, 'Estimated price for "' . $d . '"', 0);
        $acc = fp_ref($l['account_id'] ?? null, 'fp_accounts', 'account');
        $amt = fp_r2($qty * $rate);
        $lines[] = [mb_substr($d, 0, 255), $acc, $qty, $rate, $amt];
        $total += fp_paise($amt);
    }
    if (!$lines) {
        throw new InvalidArgumentException('Add at least one item.');
    }
    return fp_tx(static function () use ($id, $p, $purpose, $cc, $project, $needed, $vendor, $lines, $total) {
        if ($id) {
            $r = fp_pr($id);
            if (!$r || $r['status'] !== 'draft') {
                throw new InvalidArgumentException('Only a draft requisition can be changed.');
            }
            if ((int) $r['requester_person_id'] !== fp_me() && !fp_can('proc.prepare')) {
                fabo_deny('This requisition is not yours.', 403);
            }
            fp_bump('fp_requisitions', $id, $p['version'] ?? 0);
            fp_exec('UPDATE fp_requisitions SET purpose=?, cost_centre_id=?, project_id=?, needed_by=?, suggested_vendor_id=?, est_total=? WHERE id=?', 'siisisi',
                    [$purpose, $cc, $project, $needed, $vendor, fp_amt($total / 100), $id]);
            fp_exec('DELETE FROM fp_requisition_lines WHERE requisition_id = ?', 'i', [$id]);
        } else {
            $id = fp_exec('INSERT INTO fp_requisitions (number, requester_person_id, requester, cost_centre_id, project_id, needed_by, purpose, suggested_vendor_id, est_total, prepared_by_id, prepared_by)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?)', 'sisiissisis',
                          [fp_next_no('PR', date('Y-m-d')), fp_me(), fp_actor(), $cc, $project, $needed, $purpose, $vendor, fp_amt($total / 100), fp_me(), fp_actor()]);
            fp_audit('pr', $id, 'create', 'Requisition raised: ' . $purpose);
        }
        foreach ($lines as [$d, $acc, $qty, $rate, $amt]) {
            fp_exec('INSERT INTO fp_requisition_lines (requisition_id, description, account_id, qty, est_rate, amount) VALUES (?,?,?,?,?,?)', 'isisss',
                    [$id, $d, $acc, (string) $qty, fp_amt($rate), fp_amt($amt)]);
        }
        return $id;
    });
}

function fp_pr_submit(int $id, $version): string
{
    $r = fp_pr($id);
    if (!$r || $r['status'] !== 'draft') {
        throw new InvalidArgumentException('Only a draft requisition can be submitted.');
    }
    if ((int) $r['requester_person_id'] !== fp_me() && !fp_can('proc.prepare')) {
        fabo_deny('This requisition is not yours.', 403);
    }
    return fp_tx(static function () use ($id, $r, $version) {
        fp_bump('fp_requisitions', $id, $version);
        fp_exec('UPDATE fp_requisitions SET status = "submitted", return_note = NULL WHERE id = ?', 'i', [$id]);
        fp_audit('pr', $id, 'submit', 'Requisition submitted');
        fp_wf_start('pr', $id, (float) $r['est_total'], $r['cost_centre_id'] ? (int) $r['cost_centre_id'] : null, (int) $r['entity_id'], (int) $r['requester_person_id']);
        return 'Submitted for approval.';
    });
}

function fp_wf_on_pr(string $event, array $r, string $comment): void
{
    $status = ['approved' => 'approved', 'rejected' => 'rejected', 'returned' => 'draft'][$event];
    fp_exec('UPDATE fp_requisitions SET status = ?, return_note = ? WHERE id = ?', 'ssi', [$status, $event === 'approved' ? null : $comment, (int) $r['id']]);
    if ($event === 'approved') {
        fp_notify(fp_role_holders(['ap_accountant', 'finance_manager']), 'Requisition approved — ready to order',
                  $r['number'] . ': ' . $r['purpose'] . ' (' . fp_money($r['est_total']) . ')', 'pr', ['id' => (int) $r['id']]);
    }
}

function fp_pr_cancel(int $id, $version): void
{
    $r = fp_pr($id);
    if (!$r || !in_array($r['status'], ['draft', 'submitted', 'approved'], true)) {
        throw new InvalidArgumentException('This requisition cannot be cancelled now.');
    }
    if ((int) $r['requester_person_id'] !== fp_me() && !fp_can('proc.prepare')) {
        fabo_deny('This requisition is not yours.', 403);
    }
    fp_bump('fp_requisitions', $id, $version);
    fp_exec('UPDATE fp_requisitions SET status = "cancelled" WHERE id = ?', 'i', [$id]);
    fp_wf_cancel('pr', $id);
    fp_audit('pr', $id, 'cancel', 'Requisition cancelled');
}

/* ======================================================== purchase orders */

function fp_po(int $id): ?array
{
    return fp_one('SELECT * FROM fp_pos WHERE id = ?', 'i', [$id]);
}

function fp_po_lines(int $id): array
{
    return fp_all('SELECT l.*, a.code AS account_code, a.name AS account_name FROM fp_po_lines l JOIN fp_accounts a ON a.id = l.account_id WHERE l.po_id = ? ORDER BY l.line_no', 'i', [$id]);
}

function fp_po_statuses(): array
{
    return ['draft' => ['Draft', ''], 'submitted' => ['Pending approval', 'amber'], 'approved' => ['Approved — open', 'blue'],
            'partially_received' => ['Partly received', 'blue'], 'received' => ['Received', 'green'], 'closed' => ['Closed', ''],
            'cancelled' => ['Cancelled', ''], 'rejected' => ['Rejected', 'red']];
}

function fp_po_save(?int $id, array $p): int
{
    fp_need('proc.prepare');
    $vendor = fp_ref($p['vendor_id'] ?? null, 'fp_vendors', 'vendor', true);
    $vs = fp_vendor($vendor)['status'];
    if (in_array($vs, ['blocked', 'rejected'], true)) {
        throw new InvalidArgumentException('This vendor is ' . $vs . '; no orders can be raised against it.');
    }
    $date = fp_date($p['po_date'] ?? date('Y-m-d'), true, 'Order date');
    $delivery = fp_date($p['delivery_date'] ?? '', false, 'Delivery date');
    $currency = fp_currency($p['currency'] ?? '');
    $rate = fp_fx($currency, $p['fx_rate'] ?? '', $date);
    $cc = fp_ref($p['cost_centre_id'] ?? null, 'fp_cost_centres', 'cost centre', true, true);
    $project = fp_ref($p['project_id'] ?? null, 'fp_projects', 'project');
    $pr = fp_ref($p['requisition_id'] ?? null, 'fp_requisitions', 'requisition');
    if ($pr && !in_array(fp_pr($pr)['status'], ['approved', 'ordered'], true)) {
        throw new InvalidArgumentException('An order can be raised only from an approved requisition.');
    }
    [$lines, $sub, $tax] = fp_doc_lines($p['lines'] ?? [], 'input', ['expense', 'asset']);
    $terms = fp_str($p['terms'] ?? '', 1000);
    return fp_tx(static function () use ($id, $p, $vendor, $date, $delivery, $currency, $rate, $cc, $project, $pr, $lines, $sub, $tax, $terms) {
        $total = fp_r2($sub + $tax);
        if ($id) {
            $po = fp_po($id);
            if (!$po || $po['status'] !== 'draft') {
                throw new InvalidArgumentException('Only a draft order (or an amendment being prepared) can be changed.');
            }
            fp_bump('fp_pos', $id, $p['version'] ?? 0);
            $old = [];
            foreach (fp_po_lines($id) as $l) {
                $old[(int) $l['id']] = $l;
            }
            fp_exec('UPDATE fp_pos SET vendor_id=?, po_date=?, delivery_date=?, currency=?, fx_rate=?, cost_centre_id=?, project_id=?, requisition_id=?, subtotal=?, tax_total=?, total=?, terms=? WHERE id=?',
                    'issssiiissssi', [$vendor, $date, $delivery, $currency, $rate, $cc, $project, $pr, fp_amt($sub), fp_amt($tax), fp_amt($total), $terms ?: null, $id]);
            // An amendment keeps each line's receipts and billing: a line already received cannot drop below what arrived.
            $keep = [];
            foreach ($lines as $i => $l) {
                $lid = (int) ($p['lines'][$i]['line_id'] ?? 0);
                if ($lid && isset($old[$lid])) {
                    if ($l['qty'] < (float) $old[$lid]['received_qty'] || $l['qty'] < (float) $old[$lid]['billed_qty']) {
                        throw new InvalidArgumentException('"' . $l['description'] . '" cannot be cut below what has already been received or billed.');
                    }
                    fp_exec('UPDATE fp_po_lines SET line_no=?, description=?, account_id=?, cost_centre_id=?, project_id=?, qty=?, rate=?, amount=?, tax_code_id=?, tax_amount=? WHERE id=?',
                            'isiiisssisi', [$i + 1, $l['description'], $l['account_id'], $l['cost_centre_id'] ?? $cc, $l['project_id'] ?? $project, (string) $l['qty'],
                                            fp_amt($l['rate']), fp_amt($l['amount']), $l['tax_code_id'], fp_amt($l['tax_amount']), $lid]);
                    $keep[] = $lid;
                } else {
                    fp_po_line_insert($id, $i + 1, $l, $cc, $project);
                }
            }
            foreach ($old as $lid => $l) {
                if (!in_array($lid, $keep, true)) {
                    if ((float) $l['received_qty'] > 0 || (float) $l['billed_qty'] > 0) {
                        throw new InvalidArgumentException('"' . $l['description'] . '" has been received or billed and cannot be removed.');
                    }
                    fp_exec('DELETE FROM fp_po_lines WHERE id = ?', 'i', [$lid]);
                }
            }
            fp_audit('po', $id, 'update', 'Purchase order ' . $po['number'] . ' updated');
            return $id;
        }
        $id = fp_exec('INSERT INTO fp_pos (number, vendor_id, requisition_id, po_date, delivery_date, currency, fx_rate, cost_centre_id, project_id, subtotal, tax_total, total, terms, prepared_by_id, prepared_by)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', 'siissssiissssis',
                      [fp_next_no('PO', $date), $vendor, $pr, $date, $delivery, $currency, $rate, $cc, $project, fp_amt($sub), fp_amt($tax), fp_amt($total), $terms ?: null, fp_me(), fp_actor()]);
        foreach ($lines as $i => $l) {
            fp_po_line_insert($id, $i + 1, $l, $cc, $project);
        }
        fp_audit('po', $id, 'create', 'Purchase order drafted (' . fp_money($total) . ')');
        return $id;
    });
}

function fp_po_line_insert(int $poId, int $no, array $l, ?int $cc, ?int $project): void
{
    fp_exec('INSERT INTO fp_po_lines (po_id, line_no, description, account_id, cost_centre_id, project_id, qty, rate, amount, tax_code_id, tax_amount) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            'iisiiisssis', [$poId, $no, $l['description'], $l['account_id'], $l['cost_centre_id'] ?? $cc, $l['project_id'] ?? $project, (string) $l['qty'],
                            fp_amt($l['rate']), fp_amt($l['amount']), $l['tax_code_id'], fp_amt($l['tax_amount'])]);
}

function fp_po_submit(int $id, $version, string $override = ''): string
{
    fp_need('proc.prepare');
    $po = fp_po($id);
    if (!$po || $po['status'] !== 'draft') {
        throw new InvalidArgumentException('Only a draft order can be submitted.');
    }
    if (fp_vendor((int) $po['vendor_id'])['status'] !== 'active') {
        throw new InvalidArgumentException('The vendor must be verified (active) before an order is sent for approval.');
    }
    // A commitment beyond budget is warned about, or stopped, as Settings say.
    $lines = array_map(static fn($l) => ['account_id' => (int) $l['account_id'], 'cost_centre_id' => $l['cost_centre_id'] ? (int) $l['cost_centre_id'] : null,
                                          'project_id' => $l['project_id'] ? (int) $l['project_id'] : null,
                                          'amount' => (float) $l['amount'] * (float) $po['fx_rate'] - (float) $l['billed_amount']], fp_po_lines($id));
    $check = fp_budget_check($lines, (string) $po['po_date'], $po['amendment_no'] > 0 ? $id : null);
    if ($check['block'] && !($override !== '' && fp_can('ap.override'))) {
        throw new InvalidArgumentException('Over budget: ' . implode(' ', $check['messages']) . ' A Finance Manager may approve the exception with a note.');
    }
    return fp_tx(static function () use ($id, $po, $version, $check, $override) {
        fp_bump('fp_pos', $id, $version);
        fp_exec('UPDATE fp_pos SET status = "submitted", return_note = NULL WHERE id = ?', 'i', [$id]);
        fp_audit('po', $id, 'submit', 'Purchase order submitted' . ($check['messages'] ? ' — budget: ' . implode(' ', $check['messages']) : '') . ($override ? ' — exception: ' . $override : ''));
        fp_wf_start('po', $id, (float) $po['total'] * (float) $po['fx_rate'], $po['cost_centre_id'] ? (int) $po['cost_centre_id'] : null, (int) $po['entity_id'], (int) $po['prepared_by_id']);
        return 'Submitted for approval.' . ($check['messages'] ? ' Note: ' . implode(' ', $check['messages']) : '');
    });
}

function fp_wf_on_po(string $event, array $po, string $comment): void
{
    $id = (int) $po['id'];
    if ($event === 'approved') {
        $recv = (float) fp_val('SELECT COALESCE(SUM(received_qty),0) FROM fp_po_lines WHERE po_id = ?', 'i', [$id]);
        fp_exec('UPDATE fp_pos SET status = ? WHERE id = ?', 'si', [$recv > 0 ? 'partially_received' : 'approved', $id]);
        fp_po_refresh_status($id);
        if ($po['requisition_id']) {
            fp_exec('UPDATE fp_requisitions SET status = "ordered" WHERE id = ? AND status = "approved"', 'i', [(int) $po['requisition_id']]);
        }
        return;
    }
    fp_exec('UPDATE fp_pos SET status = ?, return_note = ? WHERE id = ?', 'ssi', [$event === 'rejected' ? ($po['amendment_no'] > 0 ? 'draft' : 'rejected') : 'draft', $comment, $id]);
}

/** Opens an amendment: the order as approved is kept as a revision, and the new one goes back through approval. */
function fp_po_amend(int $id, string $reason, $version): string
{
    fp_need('proc.prepare');
    $po = fp_po($id);
    if (!$po || !in_array($po['status'], ['approved', 'partially_received'], true)) {
        throw new InvalidArgumentException('Only an open approved order can be amended.');
    }
    $reason = fp_str($reason, 500, true, 'The reason for the amendment');
    return fp_tx(static function () use ($id, $po, $reason, $version) {
        fp_bump('fp_pos', $id, $version);
        fp_exec('INSERT INTO fp_po_revisions (po_id, amendment_no, snapshot, reason, created_by) VALUES (?,?,?,?,?)', 'iisss',
                [$id, (int) $po['amendment_no'], json_encode(['po' => $po, 'lines' => fp_po_lines($id)]), $reason, fp_actor()]);
        fp_exec('UPDATE fp_pos SET status = "draft", amendment_no = amendment_no + 1 WHERE id = ?', 'i', [$id]);
        fp_audit('po', $id, 'amend', 'Amendment ' . ((int) $po['amendment_no'] + 1) . ' opened: ' . $reason);
        return 'Amendment opened. Make the changes and submit it for approval; receipts and bills against the order are kept.';
    });
}

function fp_po_close(int $id, string $reason, $version): string
{
    fp_need('proc.prepare');
    $po = fp_po($id);
    if (!$po || !in_array($po['status'], ['approved', 'partially_received', 'received', 'draft', 'rejected'], true)) {
        throw new InvalidArgumentException('This order cannot be closed now.');
    }
    $hasActivity = (float) fp_val('SELECT COALESCE(SUM(received_qty + billed_qty),0) FROM fp_po_lines WHERE po_id = ?', 'i', [$id]) > 0;
    $to = $hasActivity || !in_array($po['status'], ['draft', 'rejected'], true) ? 'closed' : 'cancelled';
    fp_bump('fp_pos', $id, $version);
    fp_exec('UPDATE fp_pos SET status = ? WHERE id = ?', 'si', [$to, $id]);
    fp_wf_cancel('po', $id);
    fp_audit('po', $id, $to, 'Purchase order ' . $to . ': ' . fp_str($reason, 500, true, 'The reason'));
    return $to === 'closed' ? 'Order closed: nothing more will be received or billed against it, and its remaining commitment is released.' : 'Order cancelled.';
}

function fp_po_refresh_status(int $id): void
{
    $po = fp_po($id);
    if (!$po || !in_array($po['status'], ['approved', 'partially_received', 'received'], true)) {
        return;
    }
    $r = fp_one('SELECT SUM(qty) q, SUM(received_qty) rq FROM fp_po_lines WHERE po_id = ?', 'i', [$id]);
    $s = (float) $r['rq'] <= 0 ? 'approved' : ((float) $r['rq'] + 0.0001 >= (float) $r['q'] ? 'received' : 'partially_received');
    fp_exec('UPDATE fp_pos SET status = ? WHERE id = ?', 'si', [$s, $id]);
}

/** Records what arrived against an order. */
function fp_grn_create(int $poId, array $p): int
{
    fp_need('proc.receive');
    $po = fp_po($poId);
    if (!$po || !in_array($po['status'], ['approved', 'partially_received'], true)) {
        throw new InvalidArgumentException('Goods can be received only against an approved, open order.');
    }
    if (fp_setting('sod_strict') === '1' && (int) $po['prepared_by_id'] === fp_me() && !empty(fp_viewer()['client'])) {
        throw new InvalidArgumentException('You raised this order, so someone else must confirm what was received (separation of duties).');
    }
    $date = fp_date($p['grn_date'] ?? date('Y-m-d'), true, 'Received on');
    if ($date > date('Y-m-d')) {
        throw new InvalidArgumentException('Goods cannot be received in the future.');
    }
    $lines = fp_po_lines($poId);
    $got = [];
    foreach ($lines as $l) {
        $q = (float) (fp_dec($p['qty'][$l['id']] ?? '', false, 'Quantity received', 0, 1e9, 3) ?? 0);
        $rej = (float) (fp_dec($p['rejected'][$l['id']] ?? '', false, 'Quantity rejected', 0, 1e9, 3) ?? 0);
        if ($q <= 0 && $rej <= 0) {
            continue;
        }
        if ($q > (float) $l['qty'] - (float) $l['received_qty'] + 0.0005) {
            throw new InvalidArgumentException('"' . $l['description'] . '": only ' . rtrim(rtrim(number_format((float) $l['qty'] - (float) $l['received_qty'], 3), '0'), '.') . ' remain to be received.');
        }
        $got[] = [(int) $l['id'], $q, $rej, fp_str($p['remarks'][$l['id']] ?? '', 255)];
    }
    if (!$got) {
        throw new InvalidArgumentException('Enter the quantity received on at least one line.');
    }
    return fp_tx(static function () use ($poId, $po, $date, $got, $p) {
        $id = fp_exec('INSERT INTO fp_grns (number, po_id, grn_date, received_by_id, received_by, notes) VALUES (?,?,?,?,?,?)', 'sisiss',
                      [fp_next_no('GRN', $date), $poId, $date, fp_me(), fp_actor(), fp_str($p['notes'] ?? '', 500) ?: null]);
        foreach ($got as [$lid, $q, $rej, $rem]) {
            fp_exec('INSERT INTO fp_grn_lines (grn_id, po_line_id, qty, rejected_qty, remarks) VALUES (?,?,?,?,?)', 'iisss', [$id, $lid, (string) $q, (string) $rej, $rem ?: null]);
            fp_exec('UPDATE fp_po_lines SET received_qty = received_qty + ? WHERE id = ?', 'si', [(string) $q, $lid]);
        }
        fp_po_refresh_status($poId);
        fp_audit('po', $poId, 'receive', 'Goods received against ' . $po['number']);
        return $id;
    });
}

/**
 * What approved orders still commit, per line: ordered value (in base) less
 * what has been billed against it. Closed and cancelled orders commit nothing.
 */
function fp_po_commitments(?int $excludePo = null): array
{
    return fp_all('SELECT l.account_id, l.cost_centre_id, l.project_id, p.po_date, GREATEST(0, (l.amount * p.fx_rate) - l.billed_amount) AS open_amount
                     FROM fp_po_lines l JOIN fp_pos p ON p.id = l.po_id
                    WHERE p.status IN ("approved","partially_received","received","submitted") AND p.id <> ?', 'i', [(int) $excludePo]);
}

/* ================================================================== bills */

function fp_bill(int $id): ?array
{
    return fp_one('SELECT * FROM fp_bills WHERE id = ?', 'i', [$id]);
}

function fp_bill_lines(int $id): array
{
    return fp_all('SELECT l.*, a.code AS account_code, a.name AS account_name, a.subtype AS account_subtype FROM fp_bill_lines l JOIN fp_accounts a ON a.id = l.account_id
                    WHERE l.bill_id = ? ORDER BY l.line_no', 'i', [$id]);
}

function fp_bill_statuses(): array
{
    return ['draft' => ['Draft', ''], 'submitted' => ['Submitted', 'blue'], 'under_review' => ['Under review', 'blue'],
            'awaiting_approval' => ['Awaiting approval', 'amber'], 'approved' => ['Approved — unpaid', 'green'], 'on_hold' => ['On hold', 'red'],
            'rejected' => ['Rejected', 'red'], 'partially_paid' => ['Partly paid', 'amber'], 'paid' => ['Paid', 'green'], 'cancelled' => ['Cancelled', '']];
}

/** The moves a bill may make. Anything else is refused. */
function fp_bill_moves(): array
{
    return [
        'submit'   => [['draft'], 'submitted'],
        'review'   => [['submitted'], 'under_review'],
        'approval' => [['under_review'], 'awaiting_approval'],
        'hold'     => [['submitted', 'under_review', 'awaiting_approval', 'approved', 'partially_paid'], 'on_hold'],
        'cancel'   => [['draft', 'submitted', 'under_review', 'rejected'], 'cancelled'],
    ];
}

function fp_bill_key(string $no): string
{
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $no));
}

/** Saves a draft bill or debit note. Returns [id, warning]. */
function fp_bill_save(?int $id, array $p): array
{
    fp_need('ap.prepare');
    $kind = ($p['kind'] ?? 'bill') === 'debit_note' ? 'debit_note' : 'bill';
    $vendor = fp_ref($p['vendor_id'] ?? null, 'fp_vendors', 'vendor', true);
    $v = fp_vendor($vendor);
    if (in_array($v['status'], ['blocked', 'rejected'], true)) {
        throw new InvalidArgumentException('This vendor is ' . $v['status'] . '.');
    }
    $billNo = fp_str($p['bill_no'] ?? '', 60, $kind === 'bill', 'The vendor\'s invoice number');
    $date = fp_date($p['bill_date'] ?? '', true, 'Invoice date');
    if ($date > date('Y-m-d')) {
        throw new InvalidArgumentException('An invoice cannot be dated in the future.');
    }
    $due = fp_date($p['due_date'] ?? '', false, 'Due date') ?? date('Y-m-d', strtotime($date . ' +' . (int) $v['payment_terms_days'] . ' day'));
    if ($due < $date) {
        throw new InvalidArgumentException('The due date is before the invoice date.');
    }
    $posting = fp_date($p['posting_date'] ?? '', false, 'Posting date') ?? $date;
    $currency = fp_currency($p['currency'] ?? '');
    $rate = fp_fx($currency, $p['fx_rate'] ?? '', $date);
    $entity = fp_ref($p['entity_id'] ?? 1, 'fp_entities', 'entity', true, true);
    $po = fp_ref($p['po_id'] ?? null, 'fp_pos', 'purchase order');
    if ($po) {
        $pord = fp_po($po);
        if ((int) $pord['vendor_id'] !== $vendor) {
            throw new InvalidArgumentException('That purchase order is for a different vendor.');
        }
        if (!in_array($pord['status'], ['approved', 'partially_received', 'received'], true)) {
            throw new InvalidArgumentException('Bills can be matched only to an approved, open order.');
        }
    }
    $ref = $kind === 'debit_note' ? fp_ref($p['ref_bill_id'] ?? null, 'fp_bills', 'original bill', true) : null;
    if ($ref) {
        $orig = fp_bill($ref);
        if ((int) $orig['vendor_id'] !== $vendor || $orig['kind'] !== 'bill' || !$orig['journal_id']) {
            throw new InvalidArgumentException('A debit note corrects a posted bill from the same vendor.');
        }
    }
    [$lines, $sub, $tax] = fp_doc_lines($p['lines'] ?? [], 'input', ['expense', 'asset']);
    if ($po) {
        $poLines = [];
        foreach (fp_po_lines($po) as $pl) {
            $poLines[(int) $pl['id']] = $pl;
        }
        foreach ($lines as $l) {
            if ($l['po_line_id'] && !isset($poLines[$l['po_line_id']])) {
                throw new InvalidArgumentException('"' . $l['description'] . '" points at a line of a different order.');
            }
        }
    }
    $tdsCode = $kind === 'bill' ? fp_ref($p['tds_code_id'] ?? null, 'fp_tax_codes', 'TDS code') : null;
    [$tds] = fp_tds_calc($tdsCode, $sub);
    $total = fp_r2($sub + $tax - $tds);
    if ($total <= 0) {
        throw new InvalidArgumentException('The bill comes to nothing payable.');
    }
    $key = fp_bill_key($billNo);
    // The same invoice number from the same vendor is the same invoice. Refused outright.
    if ($kind === 'bill' && $key !== '' && ($dup = fp_one('SELECT number FROM fp_bills WHERE vendor_id = ? AND bill_no_key = ? AND kind = "bill" AND status NOT IN ("cancelled","rejected") AND id <> ?',
                                                         'isi', [$vendor, $key, (int) $id]))) {
        throw new InvalidArgumentException('Invoice ' . $billNo . ' from this vendor is already entered as ' . $dup['number'] . '. It cannot be entered twice.');
    }
    // A different number with the same amount close in time may still be the same invoice: flagged, and confirmed at submission.
    $win = max(1, (int) fp_setting('dup_window_days'));
    $similar = $kind === 'bill' ? fp_all('SELECT number, bill_no, bill_date FROM fp_bills WHERE vendor_id = ? AND kind = "bill" AND ABS(total - ?) < 0.01 AND ABS(DATEDIFF(bill_date, ?)) <= ?
                                         AND status NOT IN ("cancelled","rejected") AND id <> ?', 'idsii', [$vendor, $total, $date, $win, (int) $id]) : [];
    $dupNote = $similar ? 'Possible duplicate of ' . implode(', ', array_map(static fn($s) => $s['number'] . ' (invoice ' . $s['bill_no'] . ', ' . fp_fmt_date($s['bill_date']) . ')', $similar)) . ': same vendor and amount within ' . $win . ' days.' : null;
    $desc = fp_str($p['description'] ?? '', 500);
    $id = fp_tx(static function () use ($id, $p, $kind, $ref, $vendor, $billNo, $key, $date, $due, $posting, $currency, $rate, $entity, $po, $sub, $tax, $tdsCode, $tds, $total, $dupNote, $desc, $lines) {
        $cols = [$entity, $kind, $ref, $vendor, $billNo, $key, $date, $due, $posting, $po, $currency, $rate, fp_amt($sub), fp_amt($tax), $tdsCode, fp_amt($tds), fp_amt($total), $dupNote, $desc ?: null];
        if ($id) {
            $b = fp_bill($id);
            if (!$b || $b['status'] !== 'draft') {
                throw new InvalidArgumentException('Only a draft bill can be changed.');
            }
            fp_bump('fp_bills', $id, $p['version'] ?? 0);
            fp_exec('UPDATE fp_bills SET entity_id=?, kind=?, ref_bill_id=?, vendor_id=?, bill_no=?, bill_no_key=?, bill_date=?, due_date=?, posting_date=?, po_id=?, currency=?, fx_rate=?,
                     subtotal=?, tax_total=?, tds_code_id=?, tds_total=?, total=?, dup_note=?, description=?, match_status="na", match_note=NULL, budget_note=NULL WHERE id=?',
                    'isiisssssissssissssi', array_merge($cols, [$id]));
            fp_exec('DELETE FROM fp_bill_lines WHERE bill_id = ?', 'i', [$id]);
            fp_audit('bill', $id, 'update', 'Draft ' . ($kind === 'bill' ? 'bill' : 'debit note') . ' updated (' . fp_money($total) . ')', $b, null);
        } else {
            $id = fp_exec('INSERT INTO fp_bills (entity_id, kind, ref_bill_id, vendor_id, bill_no, bill_no_key, bill_date, due_date, posting_date, po_id, currency, fx_rate, subtotal, tax_total,
                           tds_code_id, tds_total, total, dup_note, description, number, prepared_by_id, prepared_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                          'isiisssssissssisssssis', array_merge($cols, [fp_next_no($kind === 'bill' ? 'BILL' : 'DN', $date), fp_me(), fp_actor()]));
            fp_audit('bill', $id, 'create', ($kind === 'bill' ? 'Bill' : 'Debit note') . ' entered: ' . $billNo . ' (' . fp_money($total) . ')');
        }
        foreach ($lines as $i => $l) {
            fp_exec('INSERT INTO fp_bill_lines (bill_id, line_no, description, account_id, cost_centre_id, project_id, qty, rate, amount, tax_code_id, tax_amount, po_line_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                    'iisiiisssisi', [$id, $i + 1, $l['description'], $l['account_id'], $l['cost_centre_id'], $l['project_id'], (string) $l['qty'], fp_amt($l['rate']),
                                     fp_amt($l['amount']), $l['tax_code_id'], fp_amt($l['tax_amount']), $l['po_line_id']]);
        }
        fp_attach_many('bill', $id);
        return $id;
    });
    return [$id, $dupNote];
}

/** Moves a bill along, refusing anything the move table does not allow. */
function fp_bill_move(int $id, string $move, $version, array $p = []): string
{
    $b = fp_bill($id);
    if (!$b) {
        throw new InvalidArgumentException('That bill no longer exists.');
    }
    $moves = fp_bill_moves();
    if ($move === 'release') {
        fp_need('ap.override');
        if ($b['status'] !== 'on_hold') {
            throw new InvalidArgumentException('This bill is not on hold.');
        }
        $to = $b['hold_from'] === 'awaiting_approval' ? 'under_review' : ($b['hold_from'] ?: 'submitted');
        fp_bump('fp_bills', $id, $version);
        fp_exec('UPDATE fp_bills SET status = ?, hold_from = NULL, hold_reason = NULL WHERE id = ?', 'si', [$to, $id]);
        fp_audit('bill', $id, 'release', 'Hold released' . ($to === 'under_review' && $b['hold_from'] === 'awaiting_approval' ? '; to be sent for approval again' : ''));
        if (in_array($to, ['approved', 'partially_paid'], true)) {
            fp_bill_recalc((int) $id);
        }
        return 'Hold released.';
    }
    if (!isset($moves[$move])) {
        throw new InvalidArgumentException('Unknown action.');
    }
    [$from, $to] = $moves[$move];
    if (!in_array($b['status'], $from, true)) {
        throw new InvalidArgumentException('A bill that is ' . strtolower(fp_bill_statuses()[$b['status']][0]) . ' cannot be ' . ['submit' => 'submitted', 'review' => 'reviewed',
                                           'approval' => 'sent for approval', 'hold' => 'put on hold', 'cancel' => 'cancelled'][$move] . '.');
    }
    fp_need('ap.prepare');
    return fp_tx(static function () use ($id, $b, $move, $to, $version, $p) {
        if ($move === 'submit') {
            if (fp_vendor((int) $b['vendor_id'])['status'] !== 'active') {
                throw new InvalidArgumentException('The vendor is not verified yet. Submit the vendor for verification first.');
            }
            if ($b['dup_note'] && empty($p['confirm_unique'])) {
                throw new InvalidArgumentException($b['dup_note'] . ' Tick "This is not a duplicate" to submit it anyway.');
            }
            if ($b['dup_note']) {
                fp_audit('bill', $id, 'dup_confirmed', 'Confirmed not a duplicate: ' . $b['dup_note']);
            }
        }
        if ($move === 'review') {
            fp_bump('fp_bills', $id, $version);
            fp_exec('UPDATE fp_bills SET status = "under_review" WHERE id = ?', 'i', [$id]);
            $res = fp_bill_validate($id);
            fp_audit('bill', $id, 'review', 'Review started. Matching: ' . $res['match_status'] . '. ' . ($res['budget'] ? 'Budget: ' . $res['budget'] : ''));
            return 'Validation done. ' . ($res['match_status'] === 'exception' ? 'There are matching exceptions to look at.' : 'No exceptions.') . ($res['budget'] ? ' ' . $res['budget'] : '');
        }
        if ($move === 'approval') {
            $res = fp_bill_validate($id);
            $note = trim((string) ($p['override_note'] ?? ''));
            if (($res['match_status'] === 'exception' || $res['block']) ) {
                if (!fp_can('ap.override') || $note === '') {
                    throw new InvalidArgumentException(($res['match_status'] === 'exception' ? 'The bill does not match its order or receipt. ' : '')
                                                     . ($res['block'] ? 'It would take the budget over its limit. ' : '')
                                                     . 'A Finance Manager may accept the exception with a note.');
                }
                fp_exec('UPDATE fp_bills SET match_status = IF(match_status = "exception", "overridden", match_status), match_note = CONCAT(COALESCE(match_note, ""), ?) WHERE id = ?',
                        'si', [' Exception accepted by ' . fp_actor() . ': ' . mb_substr($note, 0, 300), $id]);
                fp_audit('bill', $id, 'override', 'Exception accepted: ' . $note);
            }
            fp_bump('fp_bills', $id, $version);
            fp_exec('UPDATE fp_bills SET status = "awaiting_approval", return_note = NULL WHERE id = ?', 'i', [$id]);
            $cc = fp_one('SELECT cost_centre_id FROM fp_bill_lines WHERE bill_id = ? AND cost_centre_id IS NOT NULL ORDER BY amount DESC LIMIT 1', 'i', [$id]);
            fp_audit('bill', $id, 'send_approval', 'Sent for approval');
            $state = fp_wf_start('bill', $id, (float) $b['total'] * (float) $b['fx_rate'], $cc ? (int) $cc['cost_centre_id'] : null, (int) $b['entity_id'], (int) $b['prepared_by_id']);
            return $state === 'approved' ? 'Approved and posted.' : 'Sent for approval.';
        }
        if ($move === 'hold') {
            $reason = fp_str($p['reason'] ?? '', 500, true, 'The reason for the hold');
            fp_bump('fp_bills', $id, $version);
            if ($b['status'] === 'awaiting_approval') {
                fp_wf_cancel('bill', $id, 'Put on hold');
            }
            fp_exec('UPDATE fp_bills SET status = "on_hold", hold_from = ?, hold_reason = ? WHERE id = ?', 'ssi', [$b['status'], $reason, $id]);
            fp_audit('bill', $id, 'hold', 'Put on hold: ' . $reason);
            return 'On hold. It will not be paid or approved until the hold is released.';
        }
        if ($move === 'cancel') {
            $reason = fp_str($p['reason'] ?? '', 500, true, 'The reason for cancelling');
            fp_bump('fp_bills', $id, $version);
            fp_exec('UPDATE fp_bills SET status = "cancelled" WHERE id = ?', 'i', [$id]);
            fp_audit('bill', $id, 'cancel', 'Cancelled: ' . $reason);
            return 'Bill cancelled.';
        }
        fp_bump('fp_bills', $id, $version);
        fp_exec('UPDATE fp_bills SET status = ? WHERE id = ?', 'si', [$to, $id]);
        fp_audit('bill', $id, $move, 'Bill ' . $move);
        return 'Submitted. Accounts payable will validate it.';
    });
}

/**
 * Validation of a bill under review: accounts and tax codes, the vendor's
 * GSTIN where input GST is claimed, the three-way match with its order and
 * receipts, and the budget. Writes the findings onto the bill.
 */
function fp_bill_validate(int $id): array
{
    $b = fp_bill($id);
    $lines = fp_bill_lines($id);
    $v = fp_vendor((int) $b['vendor_id']);
    $notes = [];
    $match = 'na';
    if ($b['tax_total'] > 0 && !$v['gstin']) {
        $notes[] = 'GST is charged but the vendor has no GSTIN on file, so the input credit may not be claimable.';
    }
    foreach ($lines as $l) {
        $a = fp_account((int) $l['account_id']);
        if ((int) $a['active'] !== 1 || (int) $a['is_group'] === 1) {
            $notes[] = 'Line ' . $l['line_no'] . ': account ' . $a['code'] . ' can no longer take entries.';
        }
        if ($l['tax_code_id'] && (int) (fp_tax_code((int) $l['tax_code_id'])['active'] ?? 0) !== 1) {
            $notes[] = 'Line ' . $l['line_no'] . ': its tax code is inactive.';
        }
    }
    if ($b['po_id'] && $b['kind'] === 'bill') {
        $match = 'matched';
        $tolP = (float) fp_setting('match_price_tol_pct') / 100;
        $tolQ = (float) fp_setting('match_qty_tol_pct') / 100;
        $poLines = [];
        foreach (fp_po_lines((int) $b['po_id']) as $pl) {
            $poLines[(int) $pl['id']] = $pl;
        }
        foreach ($lines as $l) {
            if (!$l['po_line_id']) {
                $match = 'exception';
                $notes[] = 'Line ' . $l['line_no'] . ' ("' . $l['description'] . '") is not on the order.';
                continue;
            }
            $pl = $poLines[(int) $l['po_line_id']];
            $avail = (float) $pl['received_qty'] - (float) $pl['billed_qty'];
            if ((float) $l['qty'] > $avail * (1 + $tolQ) + 0.0005) {
                $match = 'exception';
                $notes[] = 'Line ' . $l['line_no'] . ': billed ' . (float) $l['qty'] . ', but only ' . max(0, $avail) . ' received and not yet billed.';
            }
            if ((float) $pl['rate'] > 0 && abs((float) $l['rate'] - (float) $pl['rate']) > (float) $pl['rate'] * $tolP + 0.005) {
                $match = 'exception';
                $notes[] = 'Line ' . $l['line_no'] . ': price ' . fp_money($l['rate']) . ' against ' . fp_money($pl['rate']) . ' on the order.';
            }
        }
        if ($match === 'matched') {
            $notes[] = 'Order, receipt and bill agree.';
        }
    }
    $check = $b['kind'] === 'bill' ? fp_budget_check(array_map(static fn($l) => ['account_id' => (int) $l['account_id'], 'cost_centre_id' => $l['cost_centre_id'] ? (int) $l['cost_centre_id'] : null,
                                     'project_id' => $l['project_id'] ? (int) $l['project_id'] : null, 'amount' => (float) $l['amount'] * (float) $b['fx_rate'],
                                     'po_line_id' => $l['po_line_id']], $lines), (string) $b['bill_date']) : ['messages' => [], 'block' => false];
    $budget = implode(' ', $check['messages']);
    if ($b['match_status'] !== 'overridden') {
        fp_exec('UPDATE fp_bills SET match_status = ?, match_note = ?, budget_note = ? WHERE id = ?', 'sssi', [$match, implode(' ', $notes) ?: null, $budget ?: null, $id]);
    }
    return ['match_status' => $b['match_status'] === 'overridden' ? 'overridden' : $match, 'notes' => $notes, 'budget' => $budget, 'block' => $check['block']];
}

/** The engine's answer for a bill: on approval it is posted to the ledger. */
function fp_wf_on_bill(string $event, array $b, string $comment): void
{
    $id = (int) $b['id'];
    if ($event === 'approved') {
        fp_bill_post($id);
        return;
    }
    fp_exec('UPDATE fp_bills SET status = ?, return_note = ? WHERE id = ?', 'ssi', [$event === 'rejected' ? 'rejected' : 'draft', $comment, $id]);
}

/**
 * Posts an approved bill: each line's account (expense, or an asset to be
 * capitalised) and the input tax are debited; TDS withheld is credited to
 * TDS payable and the rest to the vendor's payable. A debit note is the
 * mirror image. Order lines are marked as billed.
 */
function fp_bill_post(int $id): void
{
    fp_tx(static function () use ($id) {
        $b = fp_one('SELECT * FROM fp_bills WHERE id = ? FOR UPDATE', 'i', [$id]);
        if ($b['journal_id']) {
            return;
        }
        $sign = $b['kind'] === 'debit_note' ? -1 : 1;
        $jl = [];
        $taxBy = [];
        foreach (fp_bill_lines($id) as $l) {
            $jl[] = ['account_id' => (int) $l['account_id'], 'debit' => $sign * (float) $l['amount'], 'credit' => 0, 'cost_centre_id' => $l['cost_centre_id'],
                     'project_id' => $l['project_id'], 'description' => $l['description'], 'tax_code_id' => $l['tax_code_id'], 'party_type' => 'vendor', 'party_id' => (int) $b['vendor_id']];
            [, $parts] = fp_tax_split($l['tax_code_id'] ? (int) $l['tax_code_id'] : null, (float) $l['amount'], 'input');
            foreach ($parts as [$acc, $amt, $label]) {
                $taxBy[$acc] = ($taxBy[$acc] ?? 0) + $amt;
            }
            if ($l['po_line_id'] && $b['kind'] === 'bill') {
                fp_exec('UPDATE fp_po_lines SET billed_qty = billed_qty + ?, billed_amount = billed_amount + ? WHERE id = ?', 'ssi',
                        [(string) $l['qty'], fp_amt((float) $l['amount'] * (float) $b['fx_rate']), (int) $l['po_line_id']]);
            }
        }
        foreach ($taxBy as $acc => $amt) {
            $jl[] = ['account_id' => $acc, 'debit' => $sign * $amt, 'credit' => 0, 'description' => 'Input tax'];
        }
        if ((float) $b['tds_total'] > 0) {
            [, $tdsAcc] = fp_tds_calc((int) $b['tds_code_id'], (float) $b['subtotal']);
            $jl[] = ['account_id' => $tdsAcc ?: fp_acct('tds_payable'), 'debit' => 0, 'credit' => (float) $b['tds_total'], 'description' => 'TDS withheld', 'party_type' => 'vendor', 'party_id' => (int) $b['vendor_id']];
        }
        $jl[] = ['account_id' => fp_acct('ap'), 'debit' => 0, 'credit' => $sign * (float) $b['total'], 'description' => 'Payable to vendor', 'party_type' => 'vendor', 'party_id' => (int) $b['vendor_id']];
        $v = fp_vendor((int) $b['vendor_id']);
        $jid = fp_journal_system(['entity_id' => (int) $b['entity_id'], 'jdate' => $b['posting_date'] ?: $b['bill_date'], 'type' => 'system', 'source' => 'bill', 'source_id' => $id,
                                  'reference' => $b['number'], 'description' => ($b['kind'] === 'bill' ? 'Bill ' : 'Debit note ') . $b['bill_no'] . ' — ' . $v['name'],
                                  'currency' => $b['currency'], 'fx_rate' => $b['fx_rate']], $jl);
        fp_exec('UPDATE fp_bills SET journal_id = ?, status = "approved" WHERE id = ?', 'ii', [$jid, $id]);
        if ($b['po_id']) {
            fp_po_refresh_status((int) $b['po_id']);
        }
        fp_audit('bill', $id, 'post', ($b['kind'] === 'bill' ? 'Bill' : 'Debit note') . ' posted to the ledger');
    });
}

/** Applies a posted debit note against an open bill of the same vendor. */
function fp_ap_note_apply(int $noteId, int $billId, $amount): string
{
    fp_need('ap.prepare');
    return fp_tx(static function () use ($noteId, $billId, $amount) {
        $n = fp_one('SELECT * FROM fp_bills WHERE id = ? FOR UPDATE', 'i', [$noteId]);
        $b = fp_one('SELECT * FROM fp_bills WHERE id = ? FOR UPDATE', 'i', [$billId]);
        if (!$n || $n['kind'] !== 'debit_note' || !$n['journal_id'] || !$b || $b['kind'] !== 'bill' || !in_array($b['status'], ['approved', 'partially_paid'], true)) {
            throw new InvalidArgumentException('Apply a posted debit note to an approved, unpaid bill.');
        }
        if ((int) $n['vendor_id'] !== (int) $b['vendor_id'] || $n['currency'] !== $b['currency']) {
            throw new InvalidArgumentException('The note and the bill must be from the same vendor and in the same currency.');
        }
        $amt = (float) fp_dec($amount, true, 'Amount to apply', 0.01);
        $left = fp_r2((float) $n['total'] - (float) $n['paid_amount']);
        $due = fp_r2((float) $b['total'] - (float) $b['paid_amount'] - fp_ap_pending((int) $b['id']));
        if ($amt > $left + 0.001 || $amt > $due + 0.001) {
            throw new InvalidArgumentException('At most ' . fp_money(min($left, $due)) . ' can be applied.');
        }
        fp_exec('INSERT INTO fp_ap_allocs (bill_id, note_id, amount, status) VALUES (?,?,?,"applied")', 'iis', [$billId, $noteId, fp_amt($amt)]);
        fp_exec('UPDATE fp_bills SET paid_amount = paid_amount + ? WHERE id = ?', 'si', [fp_amt($amt), $noteId]);
        fp_bill_recalc($billId);
        fp_exec('UPDATE fp_bills SET status = IF(paid_amount + 0.001 >= total, "paid", "partially_paid") WHERE id = ?', 'i', [$noteId]);
        fp_audit('bill', $billId, 'apply_note', 'Debit note ' . $n['number'] . ' applied: ' . fp_money($amt));
        return fp_money($amt) . ' of the debit note applied.';
    });
}

/** Amount of a bill tied up in payments not yet completed. */
function fp_ap_pending(int $billId): float
{
    return fp_val('SELECT COALESCE(SUM(a.amount),0) FROM fp_payment_allocs a JOIN fp_payments p ON p.id = a.payment_id
                    WHERE a.doc_type = "bill" AND a.doc_id = ? AND p.status IN ("draft","submitted","authorized","released")', 'i', [$billId]);
}

/** Recomputes a bill's paid amount from completed payments and applied notes, and its status. */
function fp_bill_recalc(int $billId): void
{
    $paid = fp_val('SELECT COALESCE(SUM(a.amount),0) FROM fp_payment_allocs a JOIN fp_payments p ON p.id = a.payment_id
                     WHERE a.doc_type = "bill" AND a.doc_id = ? AND p.status = "completed"', 'i', [$billId])
          + fp_val('SELECT COALESCE(SUM(amount),0) FROM fp_ap_allocs WHERE bill_id = ? AND note_id IS NOT NULL AND status = "applied"', 'i', [$billId]);
    fp_exec('UPDATE fp_bills SET paid_amount = ? WHERE id = ?', 'si', [fp_amt($paid), $billId]);
    $b = fp_bill($billId);
    if (in_array($b['status'], ['approved', 'partially_paid', 'paid'], true)) {
        $s = $paid + 0.001 >= (float) $b['total'] ? 'paid' : ($paid > 0 ? 'partially_paid' : 'approved');
        fp_exec('UPDATE fp_bills SET status = ? WHERE id = ?', 'si', [$s, $billId]);
    }
}

/** Approved bills still to pay, oldest due first — what Treasury proposes to pay. */
function fp_bills_payable(?int $vendorId = null, ?string $dueBy = null): array
{
    $sql = 'SELECT b.*, v.name AS vendor, v.status AS vendor_status FROM fp_bills b JOIN fp_vendors v ON v.id = b.vendor_id
             WHERE b.kind = "bill" AND b.status IN ("approved","partially_paid")';
    $t = '';
    $p = [];
    if ($vendorId) {
        $sql .= ' AND b.vendor_id = ?';
        $t .= 'i';
        $p[] = $vendorId;
    }
    if ($dueBy) {
        $sql .= ' AND (b.due_date <= ? OR b.scheduled_on <= ?)';
        $t .= 'ss';
        $p[] = $dueBy;
        $p[] = $dueBy;
    }
    $rows = fp_all($sql . ' ORDER BY COALESCE(b.scheduled_on, b.due_date), b.id', $t, $p);
    foreach ($rows as &$r) {
        $r['pending'] = fp_ap_pending((int) $r['id']);
        $r['open'] = fp_r2((float) $r['total'] - (float) $r['paid_amount'] - $r['pending']);
    }
    unset($r);
    return array_values(array_filter($rows, static fn($r) => $r['open'] > 0.004));
}

function fp_bill_schedule(int $id, ?string $date): void
{
    fp_need('pay.prepare');
    $b = fp_bill($id);
    if (!$b || !in_array($b['status'], ['approved', 'partially_paid'], true)) {
        throw new InvalidArgumentException('Only an approved, unpaid bill can be scheduled.');
    }
    $date = fp_date($date, false, 'Pay on');
    fp_exec('UPDATE fp_bills SET scheduled_on = ? WHERE id = ?', 'si', [$date, $id]);
    fp_audit('bill', $id, 'schedule', $date ? 'Scheduled for payment on ' . $date : 'Payment schedule cleared');
}

/**
 * Ageing of what is owed (payables) or owed to us (receivables), by days
 * past due at a date. Buckets: not yet due, 1–30, 31–60, 61–90, over 90.
 */
function fp_aging_buckets(): array
{
    return ['current' => 'Not yet due', 'd30' => '1–30 days', 'd60' => '31–60 days', 'd90' => '61–90 days', 'd90p' => 'Over 90 days'];
}

function fp_bucket_of(?string $due, string $asOf): string
{
    if (!$due || $due >= $asOf) {
        return 'current';
    }
    $d = (int) ((strtotime($asOf) - strtotime($due)) / 86400);
    return $d <= 30 ? 'd30' : ($d <= 60 ? 'd60' : ($d <= 90 ? 'd90' : 'd90p'));
}

function fp_ap_aging(string $asOf, ?int $entity = null): array
{
    $rows = [];
    $sql = 'SELECT b.*, v.name AS vendor FROM fp_bills b JOIN fp_vendors v ON v.id = b.vendor_id
             WHERE b.journal_id IS NOT NULL AND b.status NOT IN ("cancelled","rejected") AND b.kind = "bill" AND b.bill_date <= ?';
    $t = 's';
    $p = [$asOf];
    if ($entity) {
        $sql .= ' AND b.entity_id = ?';
        $t .= 'i';
        $p[] = $entity;
    }
    foreach (fp_all($sql, $t, $p) as $b) {
        // What was outstanding at the date: total less payments completed and notes applied by then.
        $paid = fp_val('SELECT COALESCE(SUM(a.amount),0) FROM fp_payment_allocs a JOIN fp_payments p ON p.id = a.payment_id
                         WHERE a.doc_type = "bill" AND a.doc_id = ? AND p.status = "completed" AND p.completed_on <= ?', 'is', [(int) $b['id'], $asOf])
              + fp_val('SELECT COALESCE(SUM(amount),0) FROM fp_ap_allocs WHERE bill_id = ? AND note_id IS NOT NULL AND status = "applied" AND DATE(created_at) <= ?', 'is', [(int) $b['id'], $asOf]);
        $open = fp_r2(((float) $b['total'] - $paid) * (float) $b['fx_rate']);
        if ($open <= 0.004) {
            continue;
        }
        $k = (int) $b['vendor_id'];
        $rows[$k] ??= ['id' => $k, 'name' => $b['vendor'], 'current' => 0, 'd30' => 0, 'd60' => 0, 'd90' => 0, 'd90p' => 0, 'total' => 0, 'docs' => 0];
        $rows[$k][fp_bucket_of($b['due_date'], $asOf)] += $open;
        $rows[$k]['total'] += $open;
        $rows[$k]['docs']++;
    }
    usort($rows, static fn($a, $b) => $b['total'] <=> $a['total']);
    return $rows;
}

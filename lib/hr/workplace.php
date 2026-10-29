<?php
/**
 * Workplace — company assets, expense claims, and the HR helpdesk with its
 * announcements.
 *
 *   ASSETS     a register of what the company owns. Issuing an asset to an
 *              employee and taking it back are both recorded, so who held
 *              what, and when, is never lost. An exit will not complete
 *              while assets are still held (unless that rule is switched off).
 *   CLAIMS     an employee claims an expense with its receipt; their manager
 *              (or HR, as set in the rules) approves, possibly a lower amount;
 *              then it is paid directly or added to the next payroll as a
 *              reimbursement, which payroll then pays and shows on the payslip.
 *   HELPDESK   announcements for everyone or one department, and questions
 *              an employee raises with HR. A query is seen only by the
 *              person who raised it and by HR: never by their manager.
 */

require_once __DIR__ . '/talent.php';

/** The next running number for a table's `number` column: PREFIX-YYYY-0001. */
function hr_next_number(string $table, string $prefix): string
{
    $head = $prefix . '-' . date('Y') . '-';
    $row = fabo_one('SELECT number FROM `' . $table . '` WHERE org_id = ? AND number LIKE ? ORDER BY LENGTH(number) DESC, number DESC LIMIT 1',
                    'is', [hr_org(), $head . '%']);
    $n = $row ? (int) substr($row['number'], strlen($head)) + 1 : 1;
    return $head . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
}

/* ================================================================ assets */

function hr_asset_statuses(): array
{
    return ['in_stock' => 'In stock', 'assigned' => 'Assigned', 'repair' => 'In repair', 'lost' => 'Lost', 'retired' => 'Retired'];
}

function hr_assets_list(array $f): array
{
    $where = ' WHERE a.org_id = ?';
    $types = 'i';
    $params = [hr_org()];
    $scope = hr_scope('assets', 'view');
    if ($scope !== 'org') {
        // Below organisation scope you see only what you, or your team, hold.
        $sc = hr_scope_sql('assets', 'view', 'a', 'employee_id');
        $where .= ' AND a.employee_id IS NOT NULL' . $sc['sql'];
    }
    if (($f['status'] ?? '') !== '' && isset(hr_asset_statuses()[$f['status']])) {
        $where .= ' AND a.status = ?';
        $types .= 's';
        $params[] = $f['status'];
    }
    if (!empty($f['category'])) {
        $where .= ' AND a.category_id = ' . (int) $f['category'];
    }
    if (!empty($f['employee'])) {
        $where .= ' AND a.employee_id = ' . (int) $f['employee'];
    }
    if (($f['q'] ?? '') !== '') {
        $where .= ' AND (a.tag LIKE ? OR a.name LIKE ? OR a.serial LIKE ? OR a.make_model LIKE ?)';
        $types .= 'ssss';
        array_push($params, '%' . $f['q'] . '%', '%' . $f['q'] . '%', '%' . $f['q'] . '%', '%' . $f['q'] . '%');
    }
    return fabo_all('SELECT a.*, m.name AS category, e.full_name, e.emp_code, e.photo_file,
                            (SELECT x.due_back FROM hr_asset_assignments x WHERE x.asset_id = a.id AND x.returned_on IS NULL ORDER BY x.id DESC LIMIT 1) AS due_back
                       FROM hr_assets a LEFT JOIN hr_masters m ON m.id = a.category_id LEFT JOIN hr_employees e ON e.id = a.employee_id'
                  . $where . ' ORDER BY a.tag LIMIT 3000', $types, $params);
}

function hr_asset(int $id): ?array
{
    $a = fabo_one('SELECT a.*, m.name AS category, e.full_name, e.emp_code FROM hr_assets a LEFT JOIN hr_masters m ON m.id = a.category_id
                    LEFT JOIN hr_employees e ON e.id = a.employee_id WHERE a.id = ? AND a.org_id = ?', 'ii', [$id, hr_org()]);
    if (!$a) {
        return null;
    }
    if (hr_scope('assets', 'view') !== 'org' && !($a['employee_id'] && hr_emp_allowed((int) $a['employee_id'], 'assets', 'view'))) {
        return null;
    }
    return $a;
}

function hr_asset_save(?int $id, array $p): int
{
    if (hr_scope('assets', $id ? 'edit' : 'create') !== 'org') {
        fabo_deny('Only HR or admin manage the asset register.', 403);
    }
    $tag = strtoupper(hr_str($p['tag'] ?? '', 40, true, 'Asset tag'));
    if (fabo_one('SELECT id FROM hr_assets WHERE org_id = ? AND tag = ?' . ($id ? ' AND id <> ' . (int) $id : ''), 'is', [hr_org(), $tag])) {
        throw new InvalidArgumentException('Asset tag ' . $tag . ' is already used. Each asset needs its own tag.');
    }
    $v = [
        'tag' => $tag,
        'name' => hr_str($p['name'] ?? '', 160, true, 'Asset name'),
        'category_id' => hr_ref_id($p['category_id'] ?? 0, 'hr_masters', 'Category'),
        'make_model' => hr_str($p['make_model'] ?? '', 120) ?: null,
        'serial' => hr_str($p['serial'] ?? '', 80) ?: null,
        'purchase_date' => hr_date($p['purchase_date'] ?? '', false, 'Purchase date'),
        'cost' => hr_dec($p['cost'] ?? '', 2, false, 'Cost', 0, 1000000000),
        'warranty_until' => hr_date($p['warranty_until'] ?? '', false, 'Warranty until'),
        'notes' => hr_str($p['notes'] ?? '', 2000) ?: null,
    ];
    $types = 'ssissssss';
    if ($id) {
        $before = hr_asset($id);
        if (!$before) {
            throw new InvalidArgumentException('That asset no longer exists.');
        }
        $st = (string) ($p['status'] ?? $before['status']);
        if ($st !== $before['status']) {
            if ($before['status'] === 'assigned' || $st === 'assigned') {
                throw new InvalidArgumentException('Use Issue / Take back to change who holds an asset.');
            }
            if (!isset(hr_asset_statuses()[$st])) {
                throw new InvalidArgumentException('Choose a status.');
            }
            $v['status'] = $st;
            $types .= 's';
        }
        fabo_exec('UPDATE hr_assets SET ' . implode(', ', array_map(static fn($c) => $c . ' = ?', array_keys($v))) . ' WHERE id = ? AND org_id = ?',
                  $types . 'ii', array_merge(array_values($v), [$id, hr_org()]));
        hr_audit('update', 'asset', $id, 'Asset updated: ' . $tag, $before, $v);
        return $id;
    }
    $id = fabo_exec('INSERT INTO hr_assets (org_id, ' . implode(', ', array_keys($v)) . ', created_by) VALUES (?, ' . implode(', ', array_fill(0, count($v), '?')) . ', ?)',
                    'i' . $types . 's', array_merge([hr_org()], array_values($v), [hr_actor()]));
    hr_audit('create', 'asset', $id, 'Asset added: ' . $tag . ' ' . $v['name']);
    return $id;
}

function hr_asset_delete(int $id): void
{
    $a = hr_asset($id);
    if (!$a || hr_scope('assets', 'delete') !== 'org') {
        fabo_deny('You cannot delete this asset.', 403);
    }
    if (fabo_one('SELECT id FROM hr_asset_assignments WHERE asset_id = ? LIMIT 1', 'i', [$id])) {
        throw new InvalidArgumentException('This asset has been issued before. Mark it Retired instead, so its history is kept.');
    }
    fabo_exec('DELETE FROM hr_assets WHERE id = ? AND org_id = ?', 'ii', [$id, hr_org()]);
    hr_audit('delete', 'asset', $id, 'Asset deleted: ' . $a['tag'], $a, null);
}

function hr_asset_issue(int $id, array $p): void
{
    $a = hr_asset($id);
    if (!$a || hr_scope('assets', 'edit') !== 'org') {
        fabo_deny('You cannot issue assets.', 403);
    }
    if ($a['status'] !== 'in_stock') {
        throw new InvalidArgumentException($a['tag'] . ' is ' . strtolower(hr_asset_statuses()[$a['status']]) . '. Only an asset in stock can be issued.');
    }
    $empId = (int) ($p['employee_id'] ?? 0);
    $emp = hr_employee($empId);
    if (!$emp || !in_array($emp['status'], hr_working_statuses(), true)) {
        throw new InvalidArgumentException('Choose a current employee.');
    }
    $on = hr_date($p['assigned_on'] ?? '', false, 'Issued on') ?? date('Y-m-d');
    hr_tx(static function () use ($id, $empId, $on, $p) {
        fabo_exec('INSERT INTO hr_asset_assignments (org_id, asset_id, employee_id, assigned_on, due_back, issue_note, assigned_by) VALUES (?,?,?,?,?,?,?)',
                  'iiissss', [hr_org(), $id, $empId, $on, hr_date($p['due_back'] ?? '', false, 'Due back'), hr_str($p['note'] ?? '', 255) ?: null, hr_actor()]);
        fabo_exec("UPDATE hr_assets SET status = 'assigned', employee_id = ? WHERE id = ?", 'ii', [$empId, $id]);
    });
    hr_audit('issue', 'asset', $id, $a['tag'] . ' issued to ' . $emp['full_name']);
    hr_notify_employee($empId, 'asset-' . $id . '-' . $on, 'Company asset issued to you', $a['tag'] . ' — ' . $a['name'] . '. Please look after it and return it when asked.',
                       'hr.php?p=assets', false);
}

function hr_asset_return(int $id, array $p): void
{
    $a = hr_asset($id);
    if (!$a || hr_scope('assets', 'edit') !== 'org') {
        fabo_deny('You cannot take assets back.', 403);
    }
    if ($a['status'] !== 'assigned') {
        throw new InvalidArgumentException('This asset is not issued to anyone.');
    }
    $st = (string) ($p['status'] ?? 'in_stock');
    if (!in_array($st, ['in_stock', 'repair', 'lost', 'retired'], true)) {
        throw new InvalidArgumentException('Choose what happens to the asset.');
    }
    $on = hr_date($p['returned_on'] ?? '', false, 'Returned on') ?? date('Y-m-d');
    hr_tx(static function () use ($id, $on, $st, $p) {
        fabo_exec('UPDATE hr_asset_assignments SET returned_on = ?, return_note = ?, returned_by = ? WHERE asset_id = ? AND returned_on IS NULL',
                  'sssi', [$on, hr_str($p['note'] ?? '', 255) ?: null, hr_actor(), $id]);
        fabo_exec('UPDATE hr_assets SET status = ?, employee_id = NULL WHERE id = ?', 'si', [$st, $id]);
    });
    hr_audit('return', 'asset', $id, $a['tag'] . ' returned by ' . $a['full_name'] . ' (' . hr_asset_statuses()[$st] . ')');
}

function hr_asset_history(int $id): array
{
    return fabo_all('SELECT x.*, e.full_name, e.emp_code FROM hr_asset_assignments x JOIN hr_employees e ON e.id = x.employee_id
                     WHERE x.asset_id = ? AND x.org_id = ? ORDER BY x.assigned_on DESC, x.id DESC', 'ii', [$id, hr_org()]);
}

/** Assets an employee holds now. */
function hr_emp_assets(int $empId): array
{
    return fabo_all("SELECT a.*, m.name AS category, x.assigned_on, x.due_back FROM hr_assets a LEFT JOIN hr_masters m ON m.id = a.category_id
                       LEFT JOIN hr_asset_assignments x ON x.asset_id = a.id AND x.returned_on IS NULL
                      WHERE a.org_id = ? AND a.employee_id = ? AND a.status = 'assigned' ORDER BY a.tag", 'ii', [hr_org(), $empId]);
}

/* ================================================================ claims */

function hr_claim_statuses(): array
{
    return ['submitted' => 'Submitted', 'approved' => 'Approved', 'rejected' => 'Rejected', 'paid' => 'Paid', 'cancelled' => 'Cancelled'];
}

function hr_claim(int $id): ?array
{
    $c = fabo_one('SELECT c.*, e.full_name, e.emp_code, e.manager_id, e.photo_file, m.name AS category, a.applied_run_id
                     FROM hr_claims c JOIN hr_employees e ON e.id = c.employee_id LEFT JOIN hr_masters m ON m.id = c.category_id
                     LEFT JOIN hr_payroll_adjustments a ON a.id = c.adjustment_id
                    WHERE c.id = ? AND c.org_id = ?', 'ii', [$id, hr_org()]);
    return $c && hr_emp_allowed((int) $c['employee_id'], 'claims', 'view') ? $c : null;
}

function hr_claims_list(array $f, int $limit = 1000): array
{
    $sc = hr_scope_sql('claims', 'view', 'e');
    $where = ' WHERE c.org_id = ?';
    $types = 'i';
    $params = [hr_org()];
    if (($f['status'] ?? '') !== '' && isset(hr_claim_statuses()[$f['status']])) {
        $where .= ' AND c.status = ?';
        $types .= 's';
        $params[] = $f['status'];
    }
    if (!empty($f['employee'])) {
        $where .= ' AND c.employee_id = ' . (int) $f['employee'];
    }
    if (!empty($f['category'])) {
        $where .= ' AND c.category_id = ' . (int) $f['category'];
    }
    if (!empty($f['from'])) {
        $where .= ' AND c.expense_date >= ?';
        $types .= 's';
        $params[] = $f['from'];
    }
    if (!empty($f['to'])) {
        $where .= ' AND c.expense_date <= ?';
        $types .= 's';
        $params[] = $f['to'];
    }
    return fabo_all('SELECT c.*, e.full_name, e.emp_code, e.photo_file, e.manager_id, m.name AS category, d.name AS department
                       FROM hr_claims c JOIN hr_employees e ON e.id = c.employee_id LEFT JOIN hr_masters m ON m.id = c.category_id
                       LEFT JOIN hr_departments d ON d.id = e.department_id' . $where . $sc['sql']
                  . ' ORDER BY FIELD(c.status, "submitted", "approved", "paid", "rejected", "cancelled"), c.created_at DESC LIMIT ' . max(1, min(5000, $limit)),
                    $types, $params);
}

/** Submits a claim, for oneself or (HR) for someone else. */
function hr_claim_create(int $empId, array $p, ?array $file): int
{
    $self = hr_self_emp();
    $own = $self && (int) $self['id'] === $empId;
    if (!$own && hr_scope('claims', 'create') !== 'org' && !hr_is_admin()) {
        fabo_deny('You can claim only your own expenses.', 403);
    }
    hr_need_emp($empId, 'claims', 'create');
    $emp = hr_employee($empId);
    $cat = hr_ref_id($p['category_id'] ?? 0, 'hr_masters', 'Category', true);
    $catRow = fabo_one('SELECT name, amount_limit FROM hr_masters WHERE id = ?', 'i', [$cat]);
    $date = hr_date($p['expense_date'] ?? '', true, 'Date of expense');
    if ($date > date('Y-m-d')) {
        throw new InvalidArgumentException('The expense date is in the future.');
    }
    $back = (int) hr_setting('claim_backdate_days');
    if ($back > 0 && $date < date('Y-m-d', strtotime('-' . $back . ' day'))) {
        throw new InvalidArgumentException('Expenses older than ' . $back . ' days cannot be claimed.');
    }
    $amount = hr_dec($p['amount'] ?? '', 2, true, 'Amount', 0.01, 100000000);
    if ($catRow['amount_limit'] !== null && (float) $amount > (float) $catRow['amount_limit']) {
        throw new InvalidArgumentException($catRow['name'] . ' claims are limited to ' . fabo_money_exact($catRow['amount_limit']) . ' each.');
    }
    $desc = hr_str($p['description'] ?? '', 500, true, 'What the expense was for');
    $meta = null;
    if ($file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        [$ok, $msg, $meta] = fabo_store_upload($file);
        if (!$ok) {
            throw new InvalidArgumentException('Receipt: ' . $msg);
        }
    }
    $need = hr_setting('claim_receipt_above');
    if (!$meta && $need !== null && $need !== '' && (float) $amount > (float) $need) {
        throw new InvalidArgumentException('Attach the receipt' . ((float) $need > 0 ? ' — it is needed for claims above ' . fabo_money_exact($need) : '') . '.');
    }
    $id = hr_tx(static function () use ($empId, $cat, $date, $amount, $desc, $meta) {
        return fabo_exec('INSERT INTO hr_claims (org_id, employee_id, number, category_id, expense_date, amount, description, receipt_file, receipt_name, receipt_mime, created_by)
                          VALUES (?,?,?,?,?,?,?,?,?,?,?)', 'iisisssssss',
                         [hr_org(), $empId, hr_next_number('hr_claims', 'CLM'), $cat, $date, $amount, $desc,
                          $meta['stored_name'] ?? null, $meta['original_name'] ?? null, $meta['mime'] ?? null, hr_actor()]);
    });
    $c = hr_claim($id) ?? fabo_one('SELECT * FROM hr_claims WHERE id = ?', 'i', [$id]);
    hr_audit('create', 'claim', $id, 'Claim ' . $c['number'] . ' for ' . $emp['full_name'] . ': ' . $amount);
    hr_notify_approvers($empId, 'claim-new-' . $id, 'Expense claim to approve',
        $emp['full_name'] . ' claimed ' . fabo_money_exact($amount) . ' for ' . $catRow['name'] . ' (' . $desc . ').', 'claims&tab=approvals');
    return $id;
}

/** May the viewer approve this claim? Never their own; managers only when the rules say so. */
function hr_claim_can_decide(array $c): bool
{
    if ($c['status'] !== 'submitted') {
        return false;
    }
    if (hr_is_admin()) {
        return true;
    }
    $self = hr_self_emp();
    if ($self && (int) $self['id'] === (int) $c['employee_id']) {
        return false;
    }
    $scope = hr_scope('claims', 'approve');
    if ($scope === null || !hr_emp_allowed((int) $c['employee_id'], 'claims', 'approve')) {
        return false;
    }
    return $scope === 'org' || hr_setting('claim_approval') === 'manager';
}

function hr_claim_decide(int $id, string $decision, array $p): string
{
    $c = hr_claim($id);
    if (!$c || !hr_claim_can_decide($c)) {
        if ($c && $c['status'] !== 'submitted') {
            throw new InvalidArgumentException('This claim has already been decided.');
        }
        fabo_deny('You cannot decide this claim.', 403);
    }
    if (!in_array($decision, ['approve', 'reject'], true)) {
        throw new InvalidArgumentException('Choose approve or reject.');
    }
    $note = hr_str($p['note'] ?? '', 500, $decision === 'reject', 'A reason for rejecting');
    $amt = $decision === 'approve' ? (hr_dec($p['approved_amount'] ?? '', 2, false, 'Approved amount', 0.01, (float) $c['amount']) ?? $c['amount']) : null;
    if ($amt !== null && (float) $amt < (float) $c['amount'] && $note === '') {
        throw new InvalidArgumentException('Say why a lower amount is approved.');
    }
    fabo_exec('UPDATE hr_claims SET status = ?, approved_amount = ?, decided_by = ?, decided_at = NOW(), decision_note = ? WHERE id = ? AND status = ?',
              'ssssis', [$decision === 'approve' ? 'approved' : 'rejected', $amt, hr_actor(), $note ?: null, $id, 'submitted']);
    hr_audit($decision, 'claim', $id, 'Claim ' . $c['number'] . ' ' . ($decision === 'approve' ? 'approved for ' . $amt : 'rejected') . ($note ? ': ' . $note : ''));
    hr_notify_employee((int) $c['employee_id'], 'claim-done-' . $id, 'Expense claim ' . ($decision === 'approve' ? 'approved' : 'rejected'),
        $c['number'] . ' (' . fabo_money_exact($c['amount']) . ') was ' . ($decision === 'approve' ? 'approved for ' . fabo_money_exact($amt) . '.' : 'rejected.')
        . ($note ? ' Note: ' . $note : ''));
    return $decision === 'approve' ? 'Approved. It now waits to be paid.' : 'Rejected.';
}

/**
 * Pays an approved claim: directly (with the date and reference), or by
 * adding it to a payroll month as a reimbursement.
 */
function hr_claim_pay(int $id, array $p): string
{
    $c = hr_claim($id);
    if (!$c || (!hr_is_admin() && hr_scope('claims', 'edit') !== 'org')) {
        fabo_deny('You cannot pay claims.', 403);
    }
    if ($c['status'] !== 'approved') {
        throw new InvalidArgumentException('Only an approved claim can be paid.');
    }
    $via = (string) ($p['via'] ?? 'direct');
    if ($via === 'payroll') {
        $period = (string) ($p['period'] ?? '');
        $adj = hr_adjustment_save(['employee_id' => $c['employee_id'], 'period' => $period, 'kind' => 'reimbursement',
                                   'label' => 'Expense claim ' . $c['number'], 'amount' => $c['approved_amount'] ?? $c['amount'],
                                   'reason' => mb_substr(($c['category'] ? $c['category'] . ': ' : '') . $c['description'], 0, 255)]);
        fabo_exec("UPDATE hr_claims SET status = 'paid', paid_via = 'payroll', paid_on = ?, adjustment_id = ? WHERE id = ?", 'sii', [$period . '-01', $adj, $id]);
        hr_audit('pay', 'claim', $id, 'Claim ' . $c['number'] . ' added to payroll ' . $period);
        hr_notify_employee((int) $c['employee_id'], 'claim-paid-' . $id, 'Expense claim to be paid with salary',
            $c['number'] . ' will be paid with your salary for ' . date('F Y', strtotime($period . '-01')) . '.');
        return 'Added to payroll for ' . date('F Y', strtotime($period . '-01')) . ' as a reimbursement.';
    }
    $on = hr_date($p['paid_on'] ?? '', true, 'Paid on');
    fabo_exec("UPDATE hr_claims SET status = 'paid', paid_via = 'direct', paid_on = ?, paid_ref = ? WHERE id = ?", 'ssi',
              [$on, hr_str($p['paid_ref'] ?? '', 120) ?: null, $id]);
    hr_audit('pay', 'claim', $id, 'Claim ' . $c['number'] . ' paid on ' . $on);
    hr_notify_employee((int) $c['employee_id'], 'claim-paid-' . $id, 'Expense claim paid', $c['number'] . ' was paid on ' . hr_fmt_date($on) . '.');
    return 'Recorded as paid.';
}

function hr_claim_cancel(int $id): void
{
    $c = hr_claim($id);
    $self = hr_self_emp();
    if (!$c || !(($self && (int) $self['id'] === (int) $c['employee_id']) || hr_is_admin() || hr_scope('claims', 'edit') === 'org')) {
        fabo_deny('You cannot cancel this claim.', 403);
    }
    if ($c['status'] !== 'submitted') {
        throw new InvalidArgumentException('Only a claim still waiting for approval can be cancelled.');
    }
    fabo_exec("UPDATE hr_claims SET status = 'cancelled' WHERE id = ?", 'i', [$id]);
    hr_audit('cancel', 'claim', $id, 'Claim ' . $c['number'] . ' cancelled');
}

function hr_claims_awaiting_me(): array
{
    if (!hr_is_admin() && !hr_can('claims', 'approve')) {
        return [];
    }
    return array_values(array_filter(hr_claims_list(['status' => 'submitted']), static fn($c) => hr_claim_can_decide($c)));
}

/* ========================================================= announcements */

/** Announcements an employee (or the viewer) should see today. */
function hr_announcements_live(?array $emp = null, int $limit = 10): array
{
    $dept = $emp['department_id'] ?? null;
    $all = hr_is_admin() || hr_scope('helpdesk', 'view') === 'org';
    return fabo_all('SELECT a.*, d.name AS department FROM hr_announcements a LEFT JOIN hr_departments d ON d.id = a.department_id
                      WHERE a.org_id = ? AND a.publish_from <= CURDATE() AND (a.publish_to IS NULL OR a.publish_to >= CURDATE())'
                  . ($all && !$emp ? '' : ' AND (a.department_id IS NULL' . ($dept ? ' OR a.department_id = ' . (int) $dept : '') . ')')
                  . ' ORDER BY a.pinned DESC, a.publish_from DESC, a.id DESC LIMIT ' . max(1, min(100, $limit)), 'i', [hr_org()]);
}

function hr_announcements_all(): array
{
    return fabo_all('SELECT a.*, d.name AS department FROM hr_announcements a LEFT JOIN hr_departments d ON d.id = a.department_id
                      WHERE a.org_id = ? ORDER BY a.publish_from DESC, a.id DESC LIMIT 300', 'i', [hr_org()]);
}

function hr_announcement_save(?int $id, array $p): int
{
    if (hr_scope('helpdesk', $id ? 'edit' : 'create') !== 'org') {
        fabo_deny('Only HR posts announcements.', 403);
    }
    $from = hr_date($p['publish_from'] ?? '', false, 'Show from') ?? date('Y-m-d');
    $to = hr_date($p['publish_to'] ?? '', false, 'Show until');
    if ($to && $to < $from) {
        throw new InvalidArgumentException('“Show until” is before “Show from”.');
    }
    $v = [hr_str($p['title'] ?? '', 190, true, 'Title'), hr_str($p['body'] ?? '', 10000, true, 'Message'),
          hr_ref_id($p['department_id'] ?? 0, 'hr_departments', 'Department'), !empty($p['pinned']) ? 1 : 0, $from, $to];
    if ($id) {
        fabo_exec('UPDATE hr_announcements SET title = ?, body = ?, department_id = ?, pinned = ?, publish_from = ?, publish_to = ? WHERE id = ? AND org_id = ?',
                  'ssiissii', array_merge($v, [$id, hr_org()]));
        hr_audit('update', 'announcement', $id, 'Announcement updated: ' . $v[0]);
        return $id;
    }
    $id = fabo_exec('INSERT INTO hr_announcements (org_id, title, body, department_id, pinned, publish_from, publish_to, created_by) VALUES (?,?,?,?,?,?,?,?)',
                    'issiisss', array_merge([hr_org()], $v, [hr_actor()]));
    hr_audit('create', 'announcement', $id, 'Announcement posted: ' . $v[0]);
    if (!empty($p['notify']) && $from <= date('Y-m-d')) {
        $sql = "SELECT id FROM hr_employees WHERE org_id = ? AND person_id IS NOT NULL AND status IN ('active','probation','notice')"
             . ($v[2] ? ' AND department_id = ' . (int) $v[2] : '');
        foreach (fabo_all($sql, 'i', [hr_org()]) as $e) {
            hr_notify_employee((int) $e['id'], 'announce-' . $id, $v[0], mb_strimwidth($v[1], 0, 300, '…'), 'hr.php?p=helpdesk', false);
        }
    }
    return $id;
}

function hr_announcement_delete(int $id): void
{
    if (hr_scope('helpdesk', 'delete') !== 'org') {
        fabo_deny('Only HR removes announcements.', 403);
    }
    $a = fabo_one('SELECT * FROM hr_announcements WHERE id = ? AND org_id = ?', 'ii', [$id, hr_org()]);
    if (!$a) {
        throw new InvalidArgumentException('That announcement no longer exists.');
    }
    fabo_exec('DELETE FROM hr_announcements WHERE id = ?', 'i', [$id]);
    hr_audit('delete', 'announcement', $id, 'Announcement removed: ' . $a['title'], $a, null);
}

/* =============================================================== tickets */

function hr_ticket_statuses(): array
{
    return ['open' => 'Open', 'in_progress' => 'In progress', 'waiting' => 'Waiting on employee', 'resolved' => 'Resolved', 'closed' => 'Closed'];
}

function hr_ticket_priorities(): array
{
    return ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'];
}

/** HR's desk: organisation-wide helpdesk edit. Everyone else sees only their own queries. */
function hr_is_helpdesk_agent(): bool
{
    return hr_is_admin() || hr_scope('helpdesk', 'edit') === 'org';
}

function hr_ticket(int $id): ?array
{
    $t = fabo_one('SELECT t.*, e.full_name, e.emp_code, e.photo_file, m.name AS category, d.name AS department
                     FROM hr_tickets t JOIN hr_employees e ON e.id = t.employee_id LEFT JOIN hr_masters m ON m.id = t.category_id
                     LEFT JOIN hr_departments d ON d.id = e.department_id WHERE t.id = ? AND t.org_id = ?', 'ii', [$id, hr_org()]);
    if (!$t) {
        return null;
    }
    $self = hr_self_emp();
    return hr_is_helpdesk_agent() || ($self && (int) $self['id'] === (int) $t['employee_id']) ? $t : null;
}

function hr_tickets_list(array $f): array
{
    $where = ' WHERE t.org_id = ?';
    $types = 'i';
    $params = [hr_org()];
    if (!hr_is_helpdesk_agent()) {
        $self = hr_self_emp();
        $where .= ' AND t.employee_id = ' . (int) ($self['id'] ?? 0);
    }
    $st = (string) ($f['status'] ?? '');
    if ($st === 'active') {
        $where .= " AND t.status IN ('open','in_progress','waiting')";
    } elseif ($st !== '' && isset(hr_ticket_statuses()[$st])) {
        $where .= ' AND t.status = ?';
        $types .= 's';
        $params[] = $st;
    }
    if (!empty($f['category'])) {
        $where .= ' AND t.category_id = ' . (int) $f['category'];
    }
    return fabo_all('SELECT t.*, e.full_name, e.emp_code, e.photo_file, m.name AS category,
                            (SELECT COUNT(*) FROM hr_ticket_replies r WHERE r.ticket_id = t.id AND r.internal = 0) AS replies
                       FROM hr_tickets t JOIN hr_employees e ON e.id = t.employee_id LEFT JOIN hr_masters m ON m.id = t.category_id'
                  . $where . ' ORDER BY FIELD(t.status, "open", "in_progress", "waiting", "resolved", "closed"),
                                FIELD(t.priority, "urgent", "high", "normal", "low"), t.created_at DESC LIMIT 2000', $types, $params);
}

function hr_ticket_create(array $p): int
{
    $self = hr_self_emp();
    $empId = (int) ($p['employee_id'] ?? ($self['id'] ?? 0));
    $own = $self && (int) $self['id'] === $empId;
    if (!$own && !hr_is_helpdesk_agent()) {
        fabo_deny('You can raise queries only for yourself.', 403);
    }
    if (!hr_employee($empId)) {
        throw new InvalidArgumentException('Choose the employee the query is for.');
    }
    $prio = (string) ($p['priority'] ?? 'normal');
    if (!isset(hr_ticket_priorities()[$prio])) {
        $prio = 'normal';
    }
    $subject = hr_str($p['subject'] ?? '', 190, true, 'Subject');
    $id = hr_tx(static function () use ($empId, $p, $prio, $subject) {
        return fabo_exec('INSERT INTO hr_tickets (org_id, employee_id, number, category_id, subject, body, priority, created_by) VALUES (?,?,?,?,?,?,?,?)',
                         'iisissss', [hr_org(), $empId, hr_next_number('hr_tickets', 'HRQ'), hr_ref_id($p['category_id'] ?? 0, 'hr_masters', 'Category'),
                                      $subject, hr_str($p['body'] ?? '', 10000, true, 'Details'), $prio, hr_actor()]);
    });
    $t = fabo_one('SELECT number FROM hr_tickets WHERE id = ?', 'i', [$id]);
    hr_audit('create', 'ticket', $id, 'Query ' . $t['number'] . ' raised: ' . $subject);
    hr_notify_admin('ticket-' . $id, 'New HR query ' . $t['number'], $subject . ' (' . hr_ticket_priorities()[$prio] . ')', 'admin/hr.php?p=helpdesk&id=' . $id);
    return $id;
}

function hr_ticket_replies(int $id, bool $withInternal): array
{
    return fabo_all('SELECT * FROM hr_ticket_replies WHERE ticket_id = ?' . ($withInternal ? '' : ' AND internal = 0') . ' ORDER BY id', 'i', [$id]);
}

/** A reply from HR or from the employee; HR can also change the status, priority and owner. */
function hr_ticket_reply(int $id, array $p): string
{
    $t = hr_ticket($id);
    if (!$t) {
        fabo_deny('That query is not available to you.', 404);
    }
    $agent = hr_is_helpdesk_agent();
    $body = hr_str($p['body'] ?? '', 10000);
    $internal = $agent && !empty($p['internal']) ? 1 : 0;
    if ($body !== '') {
        fabo_exec('INSERT INTO hr_ticket_replies (ticket_id, author, from_hr, internal, body) VALUES (?,?,?,?,?)', 'isiis',
                  [$id, hr_actor(), $agent && !(hr_self_emp() && (int) hr_self_emp()['id'] === (int) $t['employee_id']) ? 1 : 0, $internal, $body]);
    }
    $status = $t['status'];
    if ($agent) {
        $want = (string) ($p['status'] ?? $t['status']);
        if (isset(hr_ticket_statuses()[$want])) {
            $status = $want;
        }
        $prio = (string) ($p['priority'] ?? $t['priority']);
        fabo_exec('UPDATE hr_tickets SET status = ?, priority = ?, assigned_to = ?, resolved_at = ? WHERE id = ?', 'ssssi',
                  [$status, isset(hr_ticket_priorities()[$prio]) ? $prio : $t['priority'], hr_str($p['assigned_to'] ?? $t['assigned_to'], 160) ?: null,
                   in_array($status, ['resolved', 'closed'], true) ? ($t['resolved_at'] ?: date('Y-m-d H:i:s')) : null, $id]);
    } elseif ($body !== '') {
        // The employee answering a query re-opens it for HR.
        if (in_array($t['status'], ['waiting', 'resolved'], true)) {
            $status = 'open';
            fabo_exec("UPDATE hr_tickets SET status = 'open', resolved_at = NULL WHERE id = ?", 'i', [$id]);
        }
    } elseif (!empty($p['close']) && $t['status'] === 'resolved') {
        $status = 'closed';
        fabo_exec("UPDATE hr_tickets SET status = 'closed' WHERE id = ?", 'i', [$id]);
    } else {
        throw new InvalidArgumentException('Write a reply first.');
    }
    hr_audit('reply', 'ticket', $id, 'Query ' . $t['number'] . ' — ' . hr_ticket_statuses()[$status] . ($internal ? ' (internal note)' : ''));
    if ($agent && !$internal && ($body !== '' || $status !== $t['status'])) {
        hr_notify_employee((int) $t['employee_id'], 'ticket-' . $id . '-' . microtime(true), 'Update on your HR query ' . $t['number'],
            ($body !== '' ? mb_strimwidth($body, 0, 300, '…') . "\n" : '') . 'Status: ' . hr_ticket_statuses()[$status] . '.', 'hr.php?p=helpdesk&id=' . $id);
    } elseif (!$agent && $body !== '') {
        hr_notify_admin('ticket-reply-' . $id . '-' . microtime(true), 'Reply on HR query ' . $t['number'], mb_strimwidth($body, 0, 300, '…'),
                        'admin/hr.php?p=helpdesk&id=' . $id);
    }
    return $internal ? 'Internal note added.' : ($body !== '' ? 'Reply sent.' : 'Updated.');
}

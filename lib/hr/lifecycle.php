<?php
/**
 * Onboarding & Exits — what happens around the two ends of employment.
 *
 *   CHECKLISTS   reusable lists of tasks, one kind for joining and one for
 *                leaving. Each task names who does it (HR, the manager, IT,
 *                admin, finance or the employee) and when, counted in days
 *                from the joining date or the last working day.
 *   TASKS        a checklist started for one employee becomes their own task
 *                list. Whoever owns a task ticks it off.
 *   SEPARATIONS  a resignation (or a termination HR records) moves through
 *                submitted → approved → completed. Approval puts the
 *                employee on notice and starts the exit checklist;
 *                completion closes the record exactly as "Deactivate" does,
 *                so every leave, attendance and payroll record is kept.
 */

require_once __DIR__ . '/payroll.php';
require_once __DIR__ . '/pdf.php';

function hr_task_owners(): array
{
    return ['hr' => 'HR', 'manager' => 'Reporting manager', 'it' => 'IT', 'admin' => 'Admin / facilities',
            'finance' => 'Finance', 'employee' => 'The employee'];
}

function hr_separation_types(): array
{
    return ['resignation' => 'Resignation', 'termination' => 'Termination', 'retirement' => 'Retirement',
            'contract_end' => 'End of contract', 'absconding' => 'Absconding', 'other' => 'Other'];
}

function hr_separation_statuses(): array
{
    return ['submitted' => 'Submitted', 'approved' => 'In clearance', 'rejected' => 'Rejected',
            'withdrawn' => 'Withdrawn', 'completed' => 'Completed'];
}

function hr_fnf_statuses(): array
{
    return ['pending' => 'Pending', 'processing' => 'Processing', 'settled' => 'Settled'];
}

/* ------------------------------------------------------------ checklists */

function hr_checklists(string $kind = '', bool $activeOnly = false): array
{
    return fabo_all('SELECT c.*, (SELECT COUNT(*) FROM hr_checklist_items i WHERE i.checklist_id = c.id) AS items
                       FROM hr_checklists c WHERE c.org_id = ?' . ($kind !== '' ? ' AND c.kind = ?' : '')
                  . ($activeOnly ? ' AND c.active = 1' : '') . ' ORDER BY c.kind, c.is_default DESC, c.name',
                    'i' . ($kind !== '' ? 's' : ''), $kind !== '' ? [hr_org(), $kind] : [hr_org()]);
}

function hr_checklist(int $id): ?array
{
    return fabo_one('SELECT * FROM hr_checklists WHERE id = ? AND org_id = ?', 'ii', [$id, hr_org()]);
}

function hr_checklist_items(int $id): array
{
    return fabo_all('SELECT * FROM hr_checklist_items WHERE checklist_id = ? ORDER BY sort, id', 'i', [$id]);
}

function hr_checklist_default(string $kind): ?array
{
    return fabo_one('SELECT * FROM hr_checklists WHERE org_id = ? AND kind = ? AND active = 1 ORDER BY is_default DESC, id LIMIT 1',
                    'is', [hr_org(), $kind]);
}

/**
 * Saves a checklist and its tasks. Items come as parallel arrays
 * item_title[], item_owner[], item_due[]; blank titles are dropped.
 */
function hr_checklist_save(?int $id, array $p): int
{
    $kind = (string) ($p['kind'] ?? '');
    if (!in_array($kind, ['onboarding', 'offboarding'], true)) {
        throw new InvalidArgumentException('Choose whether this checklist is for joining or leaving.');
    }
    $name = hr_str($p['name'] ?? '', 120, true, 'Checklist name');
    $items = [];
    $titles = (array) ($p['item_title'] ?? []);
    foreach ($titles as $i => $t) {
        $t = hr_str($t, 190);
        if ($t === '') {
            continue;
        }
        $owner = (string) ($p['item_owner'][$i] ?? 'hr');
        if (!isset(hr_task_owners()[$owner])) {
            $owner = 'hr';
        }
        $due = (int) ($p['item_due'][$i] ?? 0);
        $items[] = [$t, $owner, max(-365, min(365, $due))];
    }
    if (!$items) {
        throw new InvalidArgumentException('Add at least one task to the checklist.');
    }
    $default = !empty($p['is_default']) ? 1 : 0;
    $before = $id ? hr_checklist($id) : null;
    if ($id && !$before) {
        throw new InvalidArgumentException('That checklist no longer exists.');
    }
    return hr_tx(static function () use ($id, $kind, $name, $items, $default, $p) {
        if ($default) {
            fabo_exec('UPDATE hr_checklists SET is_default = 0 WHERE org_id = ? AND kind = ?', 'is', [hr_org(), $kind]);
        }
        if ($id) {
            fabo_exec('UPDATE hr_checklists SET kind = ?, name = ?, is_default = ?, active = ? WHERE id = ? AND org_id = ?', 'ssiiii',
                      [$kind, $name, $default, !empty($p['active']) || !array_key_exists('active', $p) ? 1 : 0, $id, hr_org()]);
            fabo_exec('DELETE FROM hr_checklist_items WHERE checklist_id = ?', 'i', [$id]);
        } else {
            $id = fabo_exec('INSERT INTO hr_checklists (org_id, kind, name, is_default, created_by) VALUES (?,?,?,?,?)', 'issis',
                            [hr_org(), $kind, $name, $default, hr_actor()]);
        }
        foreach ($items as $n => [$t, $o, $d]) {
            fabo_exec('INSERT INTO hr_checklist_items (checklist_id, title, owner, due_days, sort) VALUES (?,?,?,?,?)', 'issii',
                      [$id, $t, $o, $d, $n]);
        }
        hr_audit('save', 'checklist', $id, ucfirst($kind) . ' checklist saved: ' . $name . ' (' . count($items) . ' tasks)');
        hr_config_log('lifecycle', 'Checklist: ' . $name, '', count($items) . ' tasks');
        return $id;
    });
}

function hr_checklist_delete(int $id): void
{
    $c = hr_checklist($id);
    if (!$c) {
        throw new InvalidArgumentException('That checklist no longer exists.');
    }
    fabo_exec('DELETE FROM hr_checklist_items WHERE checklist_id = ?', 'i', [$id]);
    fabo_exec('DELETE FROM hr_checklists WHERE id = ? AND org_id = ?', 'ii', [$id, hr_org()]);
    hr_audit('delete', 'checklist', $id, 'Checklist deleted: ' . $c['name'], $c, null);
}

/** The usual checklists, to start from. Days are from the joining date / last working day. */
function hr_checklist_templates(): array
{
    return [
        'onboarding' => ['Standard onboarding', [
            ['Send the offer letter and collect it signed', 'hr', -7],
            ['Collect ID, address proof, PAN and bank details', 'employee', 0],
            ['Create email and system accounts', 'it', -1],
            ['Prepare laptop, ID card and access card', 'it', -1],
            ['Allocate a desk or seat', 'admin', 0],
            ['Welcome and induction session', 'hr', 0],
            ['Introduce to the team and assign a buddy', 'manager', 0],
            ['Add to payroll with salary structure', 'finance', 5],
            ['Agree goals for the probation period', 'manager', 15],
            ['Probation review', 'manager', 90],
        ]],
        'offboarding' => ['Standard exit', [
            ['Hand over current work', 'employee', -7],
            ['Confirm knowledge transfer is complete', 'manager', -3],
            ['Exit interview', 'hr', -2],
            ['Return laptop, ID card and other company assets', 'employee', 0],
            ['Remove email and system access', 'it', 0],
            ['Clear advances, loans and dues', 'finance', 0],
            ['Issue relieving and experience letters', 'hr', 0],
            ['Full and final settlement', 'finance', 30],
        ]],
    ];
}

/** Adds the usual checklist of a kind as the default. */
function hr_checklist_add_template(string $kind): int
{
    $tpl = hr_checklist_templates()[$kind] ?? null;
    if (!$tpl) {
        throw new InvalidArgumentException('Unknown checklist.');
    }
    $have = fabo_one('SELECT id FROM hr_checklists WHERE org_id = ? AND name = ?', 'is', [hr_org(), $tpl[0]]);
    if ($have) {
        throw new InvalidArgumentException('“' . $tpl[0] . '” is already there.');
    }
    $p = ['kind' => $kind, 'name' => $tpl[0], 'is_default' => hr_checklist_default($kind) ? 0 : 1, 'item_title' => [], 'item_owner' => [], 'item_due' => []];
    foreach ($tpl[1] as [$t, $o, $d]) {
        $p['item_title'][] = $t;
        $p['item_owner'][] = $o;
        $p['item_due'][] = $d;
    }
    return hr_checklist_save(null, $p);
}

/* ------------------------------------------------------------------ tasks */

/**
 * Starts a checklist for one employee: copies its tasks with real due dates.
 * Base date: the joining date for onboarding, the last working day for an exit.
 */
function hr_lifecycle_start(int $empId, string $kind, ?int $checklistId = null, ?int $separationId = null, ?string $base = null): int
{
    $emp = hr_employee($empId);
    if (!$emp) {
        throw new InvalidArgumentException('That employee no longer exists.');
    }
    $list = $checklistId ? hr_checklist($checklistId) : hr_checklist_default($kind);
    if (!$list || $list['kind'] !== $kind) {
        throw new InvalidArgumentException('There is no ' . ($kind === 'onboarding' ? 'onboarding' : 'exit')
                                         . ' checklist yet. Add one under Onboarding & Exits → Checklists.');
    }
    if ($kind === 'onboarding' && fabo_one("SELECT id FROM hr_lifecycle_tasks WHERE employee_id = ? AND kind = 'onboarding' AND status = 'pending' LIMIT 1",
                                           'i', [$empId])) {
        throw new InvalidArgumentException('Onboarding is already in progress for ' . $emp['full_name'] . '.');
    }
    $base ??= $kind === 'onboarding' ? ($emp['date_of_joining'] ?: date('Y-m-d')) : ($emp['date_of_leaving'] ?: date('Y-m-d'));
    $n = 0;
    foreach (hr_checklist_items((int) $list['id']) as $it) {
        fabo_exec('INSERT INTO hr_lifecycle_tasks (org_id, employee_id, kind, separation_id, title, owner, due_date, sort)
                   VALUES (?,?,?,?,?,?,?,?)', 'iisisssi', [hr_org(), $empId, $kind, $separationId, $it['title'], $it['owner'],
                   date('Y-m-d', strtotime($base . ' ' . ((int) $it['due_days'] >= 0 ? '+' : '') . (int) $it['due_days'] . ' day')), (int) $it['sort']]);
        $n++;
    }
    hr_audit('start', 'lifecycle', $empId, ucfirst($kind) . ' started for ' . $emp['full_name'] . ' from “' . $list['name'] . '” (' . $n . ' tasks)');
    if ($kind === 'onboarding') {
        hr_notify_employee($empId, 'onboard-start-' . $empId . '-' . time(), 'Welcome aboard',
            'Your onboarding checklist is ready. Open My HR to see what is planned for your first days.', 'hr.php?p=lifecycle', false);
    }
    return $n;
}

function hr_tasks_for(int $empId, string $kind = ''): array
{
    return fabo_all('SELECT * FROM hr_lifecycle_tasks WHERE employee_id = ? AND org_id = ?' . ($kind !== '' ? ' AND kind = ?' : '')
                  . ' ORDER BY kind, sort, due_date, id', 'ii' . ($kind !== '' ? 's' : ''), $kind !== '' ? [$empId, hr_org(), $kind] : [$empId, hr_org()]);
}

function hr_task(int $id): ?array
{
    return fabo_one('SELECT t.*, e.full_name, e.emp_code, e.manager_id FROM hr_lifecycle_tasks t JOIN hr_employees e ON e.id = t.employee_id
                     WHERE t.id = ? AND t.org_id = ?', 'ii', [$id, hr_org()]);
}

/**
 * May the viewer tick this task? HR (lifecycle edit, organisation-wide), the
 * employee for their own tasks, and the manager for manager tasks of their
 * team. Anyone else, no.
 */
function hr_task_can_complete(array $t): bool
{
    if (hr_is_admin() || hr_scope('lifecycle', 'edit') === 'org') {
        return true;
    }
    if (!hr_can('lifecycle', 'edit')) {
        return false;
    }
    $self = hr_self_emp();
    if (!$self) {
        return false;
    }
    if ($t['owner'] === 'employee') {
        return (int) $t['employee_id'] === (int) $self['id'];
    }
    if ($t['owner'] === 'manager') {
        return (int) $t['employee_id'] !== (int) $self['id'] && hr_emp_allowed((int) $t['employee_id'], 'lifecycle', 'edit');
    }
    return false;
}

function hr_task_set(int $id, string $status, string $note = ''): void
{
    $t = hr_task($id);
    if (!$t) {
        throw new InvalidArgumentException('That task no longer exists.');
    }
    if (!hr_task_can_complete($t)) {
        fabo_deny('That task is not yours to update.', 403);
    }
    if (!in_array($status, ['pending', 'done', 'skipped'], true)) {
        throw new InvalidArgumentException('Choose done, not needed, or reopen.');
    }
    $note = hr_str($note, 255);
    fabo_exec('UPDATE hr_lifecycle_tasks SET status = ?, note = ?, done_by = ?, done_at = ? WHERE id = ?', 'ssssi',
              [$status, $note !== '' ? $note : $t['note'], $status === 'pending' ? null : hr_actor(),
               $status === 'pending' ? null : date('Y-m-d H:i:s'), $id]);
    hr_audit('task_' . $status, 'lifecycle_task', $id, $t['title'] . ' — ' . $t['full_name'] . ' (' . $status . ')');
}

/** Adds one task by hand to an employee's list. */
function hr_task_add(int $empId, string $kind, array $p): int
{
    if (!in_array($kind, ['onboarding', 'offboarding'], true)) {
        throw new InvalidArgumentException('Unknown list.');
    }
    $title = hr_str($p['title'] ?? '', 190, true, 'Task');
    $owner = (string) ($p['owner'] ?? 'hr');
    if (!isset(hr_task_owners()[$owner])) {
        throw new InvalidArgumentException('Choose who does the task.');
    }
    $due = hr_date($p['due_date'] ?? '', false, 'Due date');
    $sep = fabo_one("SELECT id FROM hr_separations WHERE employee_id = ? AND status = 'approved' ORDER BY id DESC LIMIT 1", 'i', [$empId]);
    $id = fabo_exec('INSERT INTO hr_lifecycle_tasks (org_id, employee_id, kind, separation_id, title, owner, due_date, sort) VALUES (?,?,?,?,?,?,?,99)',
                    'iisisss', [hr_org(), $empId, $kind, $kind === 'offboarding' ? ($sep['id'] ?? null) : null, $title, $owner, $due]);
    hr_audit('create', 'lifecycle_task', $id, 'Task added: ' . $title);
    return $id;
}

/**
 * Open tasks for the lifecycle desk, scoped to the viewer. $f: kind, owner,
 * status ('pending' by default), employee, due ('overdue' | 'week' | '').
 */
function hr_tasks_list(array $f, int $limit = 500): array
{
    $sc = hr_scope_sql('lifecycle', 'view', 'e');
    $where = ' WHERE t.org_id = ?';
    $types = 'i';
    $params = [hr_org()];
    foreach (['kind' => 't.kind', 'owner' => 't.owner', 'status' => 't.status'] as $k => $col) {
        if (($f[$k] ?? '') !== '') {
            $where .= ' AND ' . $col . ' = ?';
            $types .= 's';
            $params[] = (string) $f[$k];
        }
    }
    if (!empty($f['employee'])) {
        $where .= ' AND t.employee_id = ' . (int) $f['employee'];
    }
    if (($f['due'] ?? '') === 'overdue') {
        $where .= ' AND t.due_date < CURDATE()';
    } elseif (($f['due'] ?? '') === 'week') {
        $where .= ' AND t.due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)';
    }
    return fabo_all('SELECT t.*, e.full_name, e.emp_code, e.photo_file, e.manager_id, d.name AS department
                       FROM hr_lifecycle_tasks t JOIN hr_employees e ON e.id = t.employee_id
                       LEFT JOIN hr_departments d ON d.id = e.department_id' . $where . $sc['sql']
                  . ' ORDER BY t.due_date IS NULL, t.due_date, e.full_name, t.sort LIMIT ' . max(1, min(2000, $limit)), $types, $params);
}

/** Tasks the viewer personally has to do, pending. */
function hr_tasks_mine(): array
{
    $rows = hr_tasks_list(['status' => 'pending'], 2000);
    $self = hr_self_emp();
    $hr = hr_is_admin() || hr_scope('lifecycle', 'edit') === 'org';
    return array_values(array_filter($rows, static function ($t) use ($self, $hr) {
        if ($self && $t['owner'] === 'employee' && (int) $t['employee_id'] === (int) $self['id']) {
            return true;
        }
        if ($self && $t['owner'] === 'manager' && (int) $t['manager_id'] === (int) $self['id']) {
            return true;
        }
        return $hr && !in_array($t['owner'], ['employee', 'manager'], true);
    }));
}

/** Progress of one employee's list: [done, total]. */
function hr_task_progress(int $empId, string $kind): array
{
    $r = fabo_one("SELECT SUM(status <> 'pending') d, COUNT(*) n FROM hr_lifecycle_tasks WHERE employee_id = ? AND kind = ? AND org_id = ?",
                  'isi', [$empId, $kind, hr_org()]);
    return [(int) ($r['d'] ?? 0), (int) ($r['n'] ?? 0)];
}

/** Employees with an onboarding or exit list still open, with progress. */
function hr_lifecycle_in_progress(string $kind): array
{
    $sc = hr_scope_sql('lifecycle', 'view', 'e');
    return fabo_all("SELECT e.id, e.full_name, e.emp_code, e.photo_file, e.date_of_joining, e.date_of_leaving, e.status, d.name AS department,
                            SUM(t.status <> 'pending') AS done, COUNT(*) AS total,
                            SUM(t.status = 'pending' AND t.due_date < CURDATE()) AS overdue, MIN(CASE WHEN t.status = 'pending' THEN t.due_date END) AS next_due
                       FROM hr_lifecycle_tasks t JOIN hr_employees e ON e.id = t.employee_id LEFT JOIN hr_departments d ON d.id = e.department_id
                      WHERE t.org_id = ? AND t.kind = ?" . $sc['sql'] . "
                      GROUP BY e.id HAVING done < total ORDER BY next_due", 'is', [hr_org(), $kind]);
}

/* ------------------------------------------------------------ separations */

function hr_separation(int $id): ?array
{
    return fabo_one('SELECT s.*, e.full_name, e.emp_code, e.manager_id, e.date_of_joining, e.status AS emp_status, e.photo_file,
                            d.name AS department, g.name AS designation
                       FROM hr_separations s JOIN hr_employees e ON e.id = s.employee_id
                       LEFT JOIN hr_departments d ON d.id = e.department_id LEFT JOIN hr_designations g ON g.id = e.designation_id
                      WHERE s.id = ? AND s.org_id = ?', 'ii', [$id, hr_org()]);
}

/** The employee's separation that is still open (submitted or in clearance), if any. */
function hr_separation_open(int $empId): ?array
{
    $r = fabo_one("SELECT id FROM hr_separations WHERE employee_id = ? AND org_id = ? AND status IN ('submitted','approved') ORDER BY id DESC LIMIT 1",
                  'ii', [$empId, hr_org()]);
    return $r ? hr_separation((int) $r['id']) : null;
}

function hr_separations_list(string $status = '', int $limit = 300): array
{
    $sc = hr_scope_sql('lifecycle', 'view', 'e');
    return fabo_all('SELECT s.*, e.full_name, e.emp_code, e.photo_file, e.manager_id, d.name AS department
                       FROM hr_separations s JOIN hr_employees e ON e.id = s.employee_id LEFT JOIN hr_departments d ON d.id = e.department_id
                      WHERE s.org_id = ?' . ($status !== '' ? ' AND s.status = ?' : '') . $sc['sql']
                  . ' ORDER BY FIELD(s.status, "submitted", "approved", "completed", "rejected", "withdrawn"), COALESCE(s.approved_lwd, s.requested_lwd) LIMIT ' . max(1, min(2000, $limit)),
                    'i' . ($status !== '' ? 's' : ''), $status !== '' ? [hr_org(), $status] : [hr_org()]);
}

/**
 * A resignation (from the employee) or a separation HR records. HR's own
 * entries can be approved at once with the last working day given.
 */
function hr_separation_submit(int $empId, array $p): int
{
    $self = hr_self_emp();
    $own = $self && (int) $self['id'] === $empId;
    if (!$own && hr_scope('lifecycle', 'create') !== 'org' && !hr_is_admin()) {
        fabo_deny('Only HR records a separation for someone else.', 403);
    }
    hr_need_emp($empId, 'lifecycle', 'create');
    $emp = hr_employee($empId);
    if (!in_array($emp['status'], hr_working_statuses(), true)) {
        throw new InvalidArgumentException($emp['full_name'] . ' is not currently employed.');
    }
    if (hr_separation_open($empId)) {
        throw new InvalidArgumentException('An exit is already in progress for ' . $emp['full_name'] . '.');
    }
    $type = $own ? 'resignation' : (string) ($p['type'] ?? 'resignation');
    if (!isset(hr_separation_types()[$type])) {
        throw new InvalidArgumentException('Choose the kind of separation.');
    }
    $notice = hr_date($p['notice_date'] ?? '', false, 'Date of notice') ?? date('Y-m-d');
    $days = (int) hr_setting('exit_notice_days');
    $lwd = hr_date($p['requested_lwd'] ?? '', false, 'Last working day')
        ?? date('Y-m-d', strtotime($notice . ' +' . max(0, $days) . ' day'));
    if ($lwd < $notice) {
        throw new InvalidArgumentException('The last working day cannot be before the date of notice.');
    }
    $reason = hr_str($p['reason'] ?? '', 2000, $own, 'A reason');
    $id = fabo_exec('INSERT INTO hr_separations (org_id, employee_id, type, reason, notice_date, requested_lwd, prev_status, created_by)
                     VALUES (?,?,?,?,?,?,?,?)', 'iissssss', [hr_org(), $empId, $type, $reason ?: null, $notice, $lwd, $emp['status'], hr_actor()]);
    hr_audit('create', 'separation', $id, hr_separation_types()[$type] . ' recorded for ' . $emp['full_name'] . ', last day ' . $lwd);
    $words = $emp['full_name'] . ' — ' . hr_separation_types()[$type] . '. Requested last working day: ' . hr_fmt_date($lwd) . '.';
    if (!$own && !empty($p['approve_now']) && (hr_is_admin() || hr_scope('lifecycle', 'approve') === 'org')) {
        hr_separation_decide($id, 'approve', $lwd, 'Recorded by HR.');
    } else {
        hr_notify_approvers($empId, 'sep-new-' . $id, 'Resignation to review', $words . ($reason ? "\nReason: " . $reason : ''), 'lifecycle&tab=exits');
    }
    return $id;
}

/** May the viewer decide this separation? Never their own. */
function hr_separation_can_decide(array $s): bool
{
    if ($s['status'] !== 'submitted') {
        return false;
    }
    if (hr_is_admin()) {
        return true;
    }
    $self = hr_self_emp();
    if ($self && (int) $self['id'] === (int) $s['employee_id']) {
        return false;
    }
    return hr_emp_allowed((int) $s['employee_id'], 'lifecycle', 'approve');
}

/**
 * Approve or reject. Approval puts the employee on notice and starts the
 * default exit checklist against the agreed last working day.
 */
function hr_separation_decide(int $id, string $decision, ?string $lwd, string $note): void
{
    $s = hr_separation($id);
    if (!$s) {
        throw new InvalidArgumentException('That exit no longer exists.');
    }
    if (!hr_separation_can_decide($s)) {
        if ($s['status'] !== 'submitted') {
            throw new InvalidArgumentException('This has already been decided.');
        }
        fabo_deny('You cannot decide this exit.', 403);
    }
    if (!in_array($decision, ['approve', 'reject'], true)) {
        throw new InvalidArgumentException('Choose approve or reject.');
    }
    $note = hr_str($note, 500, $decision === 'reject', 'A reason for rejecting');
    $lwd = $decision === 'approve' ? (hr_date($lwd, false, 'Last working day') ?? $s['requested_lwd']) : null;
    if ($lwd && $lwd < $s['notice_date']) {
        throw new InvalidArgumentException('The last working day cannot be before the date of notice.');
    }
    $started = 0;
    hr_tx(static function () use ($s, $decision, $lwd, $note, &$started) {
        $cur = fabo_one('SELECT status FROM hr_separations WHERE id = ? FOR UPDATE', 'i', [(int) $s['id']]);
        if (!$cur || $cur['status'] !== 'submitted') {
            throw new InvalidArgumentException('This was decided a moment ago. Reload to see the outcome.');
        }
        fabo_exec('UPDATE hr_separations SET status = ?, approved_lwd = ?, decided_by = ?, decided_at = NOW(), decision_note = ? WHERE id = ?',
                  'ssssi', [$decision === 'approve' ? 'approved' : 'rejected', $lwd, hr_actor(), $note ?: null, (int) $s['id']]);
        if ($decision === 'approve') {
            $before = hr_employee((int) $s['employee_id']);
            fabo_exec("UPDATE hr_employees SET status = 'notice', date_of_leaving = ? WHERE id = ? AND org_id = ?", 'sii',
                      [$lwd, (int) $s['employee_id'], hr_org()]);
            hr_emp_history((int) $s['employee_id'], $before, ['status' => 'notice', 'date_of_leaving' => $lwd]);
            if (hr_checklist_default('offboarding')) {
                $started = hr_lifecycle_start((int) $s['employee_id'], 'offboarding', null, (int) $s['id'], $lwd);
            }
        }
    });
    hr_audit($decision, 'separation', (int) $s['id'], 'Exit ' . ($decision === 'approve' ? 'approved, last day ' . $lwd : 'rejected') . ' for ' . $s['full_name']
           . ($note ? ': ' . $note : ''));
    hr_notify_employee((int) $s['employee_id'], 'sep-done-' . $s['id'],
        $decision === 'approve' ? 'Your resignation was accepted' : 'Your resignation was not accepted',
        $decision === 'approve' ? 'Your last working day is ' . hr_fmt_date($lwd) . '.' . ($started ? ' Your exit checklist is on My HR.' : '') . ($note ? ' Note: ' . $note : '')
                                : 'Reason: ' . $note);
}

/** Withdraws an exit not yet completed: the employee returns to their earlier status, open exit tasks are set aside. */
function hr_separation_withdraw(int $id, string $note): void
{
    $s = hr_separation($id);
    if (!$s || !in_array($s['status'], ['submitted', 'approved'], true)) {
        throw new InvalidArgumentException('Only an exit that is still open can be withdrawn.');
    }
    $self = hr_self_emp();
    $own = $self && (int) $self['id'] === (int) $s['employee_id'];
    if (!$own && !hr_is_admin() && !hr_emp_allowed((int) $s['employee_id'], 'lifecycle', 'approve')) {
        fabo_deny('You cannot withdraw this exit.', 403);
    }
    if ($own && $s['status'] === 'approved' && !hr_is_admin()) {
        throw new InvalidArgumentException('Your resignation has already been accepted. Ask HR or your manager to withdraw it.');
    }
    $note = hr_str($note, 500);
    hr_tx(static function () use ($s, $note) {
        fabo_exec("UPDATE hr_separations SET status = 'withdrawn', decision_note = ?, decided_by = ?, decided_at = NOW() WHERE id = ?", 'ssi',
                  [$note ?: 'Withdrawn', hr_actor(), (int) $s['id']]);
        if ($s['status'] === 'approved') {
            $before = hr_employee((int) $s['employee_id']);
            $back = in_array($s['prev_status'], ['active', 'probation'], true) ? $s['prev_status'] : 'active';
            fabo_exec('UPDATE hr_employees SET status = ?, date_of_leaving = NULL WHERE id = ? AND org_id = ?', 'sii', [$back, (int) $s['employee_id'], hr_org()]);
            hr_emp_history((int) $s['employee_id'], $before, ['status' => $back, 'date_of_leaving' => null]);
            fabo_exec("UPDATE hr_lifecycle_tasks SET status = 'skipped', note = 'Exit withdrawn', done_by = ?, done_at = NOW()
                       WHERE separation_id = ? AND status = 'pending'", 'si', [hr_actor(), (int) $s['id']]);
        }
    });
    hr_audit('withdraw', 'separation', (int) $s['id'], 'Exit withdrawn for ' . $s['full_name'] . ($note ? ': ' . $note : ''));
    hr_notify_employee((int) $s['employee_id'], 'sep-withdrawn-' . $s['id'], 'Your exit was withdrawn', $note !== '' ? $note : 'You remain employed as before.');
}

/** HR's details on an exit: agreed last day, exit interview, full and final settlement. */
function hr_separation_update(int $id, array $p): void
{
    $s = hr_separation($id);
    if (!$s) {
        throw new InvalidArgumentException('That exit no longer exists.');
    }
    if (!hr_is_admin() && hr_scope('lifecycle', 'edit') !== 'org') {
        fabo_deny('Only HR records exit details.', 403);
    }
    $fnf = (string) ($p['fnf_status'] ?? $s['fnf_status']);
    if (!isset(hr_fnf_statuses()[$fnf])) {
        throw new InvalidArgumentException('Choose the settlement status.');
    }
    $rating = (int) ($p['exit_rating'] ?? 0);
    $vals = [
        hr_str($p['exit_interview'] ?? '', 5000) ?: null,
        $rating >= 1 && $rating <= 5 ? $rating : null,
        ($p['rehire_eligible'] ?? '') === '' ? null : (int) !empty($p['rehire_eligible']),
        $fnf,
        hr_dec($p['fnf_amount'] ?? '', 2, false, 'Settlement amount', -100000000, 100000000),
        hr_str($p['fnf_note'] ?? '', 500) ?: null,
        hr_date($p['fnf_settled_on'] ?? '', $fnf === 'settled', 'Settled on'),
    ];
    $lwd = hr_date($p['approved_lwd'] ?? '', false, 'Last working day');
    fabo_exec('UPDATE hr_separations SET exit_interview = ?, exit_rating = ?, rehire_eligible = ?, fnf_status = ?, fnf_amount = ?, fnf_note = ?, fnf_settled_on = ?
               WHERE id = ? AND org_id = ?', 'siissssii', array_merge($vals, [$id, hr_org()]));
    if ($lwd && $s['status'] === 'approved' && $lwd !== $s['approved_lwd']) {
        if ($lwd < $s['notice_date']) {
            throw new InvalidArgumentException('The last working day cannot be before the date of notice.');
        }
        fabo_exec('UPDATE hr_separations SET approved_lwd = ? WHERE id = ?', 'si', [$lwd, $id]);
        $before = hr_employee((int) $s['employee_id']);
        fabo_exec('UPDATE hr_employees SET date_of_leaving = ? WHERE id = ? AND org_id = ?', 'sii', [$lwd, (int) $s['employee_id'], hr_org()]);
        hr_emp_history((int) $s['employee_id'], $before, ['date_of_leaving' => $lwd]);
    }
    hr_audit('update', 'separation', $id, 'Exit details saved for ' . $s['full_name'] . ' (settlement ' . hr_fnf_statuses()[$fnf] . ')');
}

/** What still stands between an exit and its completion: [pending tasks, assets held]. */
function hr_separation_blockers(array $s): array
{
    $tasks = (int) (fabo_one("SELECT COUNT(*) n FROM hr_lifecycle_tasks WHERE employee_id = ? AND kind = 'offboarding' AND status = 'pending'",
                             'i', [(int) $s['employee_id']])['n'] ?? 0);
    $assets = fabo_all("SELECT id, tag, name FROM hr_assets WHERE org_id = ? AND employee_id = ? AND status = 'assigned'", 'ii',
                       [hr_org(), (int) $s['employee_id']]);
    return [$tasks, $assets];
}

/**
 * Closes the exit: the employee leaves on the agreed day with every record
 * kept, exactly as Deactivate does.
 */
function hr_separation_complete(int $id): void
{
    $s = hr_separation($id);
    if (!$s || $s['status'] !== 'approved') {
        throw new InvalidArgumentException('Only an approved exit can be completed.');
    }
    if (!hr_is_admin() && hr_scope('lifecycle', 'edit') !== 'org') {
        fabo_deny('Only HR completes an exit.', 403);
    }
    [, $assets] = hr_separation_blockers($s);
    if ($assets && hr_setting('exit_block_assets') === '1') {
        throw new InvalidArgumentException($s['full_name'] . ' still holds ' . count($assets) . ' company asset(s): '
            . implode(', ', array_map(static fn($a) => $a['tag'] . ' ' . $a['name'], $assets)) . '. Record them as returned (or lost) under Assets first.');
    }
    $status = match ($s['type']) { 'resignation' => 'resigned', 'termination', 'absconding' => 'terminated', default => 'inactive' };
    hr_tx(static function () use ($s, $status) {
        hr_employee_deactivate((int) $s['employee_id'], $status, $s['approved_lwd'], hr_separation_types()[$s['type']] . ' — exit completed');
        fabo_exec("UPDATE hr_separations SET status = 'completed', completed_at = NOW() WHERE id = ?", 'i', [(int) $s['id']]);
    });
    hr_audit('complete', 'separation', (int) $s['id'], 'Exit completed for ' . $s['full_name']);
}

/* ---------------------------------------------------------------- letters */

/**
 * A plain one-page letter on the company's name: reference and date, the
 * addressee, a subject, paragraphs and the signature block.
 */
function hr_letter_pdf(string $ref, array $to, string $subject, array $paras, string $signoff = 'For the company'): HrPdf
{
    $pdf = new HrPdf();
    $pdf->addPage();
    $co = trim((string) hr_setting('pay_company_name')) ?: hr_org_label(hr_org());
    $addr = trim((string) hr_setting('pay_company_address'));
    $x = 56;
    $w = $pdf->width() - 112;
    $y = 64;
    $pdf->text($x, $y, $co, 16, true);
    $y += 16;
    foreach ($addr !== '' ? $pdf->wrap($addr, $w, 9) : [] as $l) {
        $pdf->text($x, $y, $l, 9, false, 'left', 0.4);
        $y += 12;
    }
    $pdf->line($x, $y + 4, $x + $w, $y + 4, 0.8, 0.6);
    $y += 28;
    $pdf->text($x, $y, 'Ref: ' . $ref, 9.5, false, 'left', 0.35);
    $pdf->text($x + $w, $y, 'Date: ' . date('d M Y'), 9.5, false, 'right', 0.35);
    $y += 28;
    foreach ($to as $i => $l) {
        $pdf->text($x, $y, $l, 10.5, $i === 0);
        $y += 14;
    }
    $y += 14;
    $pdf->text($x, $y, 'Subject: ' . $subject, 11, true);
    $y += 26;
    foreach ($paras as $para) {
        foreach ($pdf->wrap($para, $w, 10.5) as $l) {
            $pdf->text($x, $y, $l, 10.5);
            $y += 15;
        }
        $y += 9;
    }
    $y += 24;
    $pdf->text($x, $y, $signoff . ' ' . $co, 10.5, true);
    $y += 52;
    $pdf->text($x, $y, 'Authorised signatory', 10, false, 'left', 0.35);
    $pdf->text($x, $pdf->height() - 36, 'This letter was generated by the HR system on ' . date('d M Y') . '.', 8, false, 'left', 0.55);
    return $pdf;
}

/** Relieving or experience letter for a completed (or approved) exit. */
function hr_exit_letter(array $s, string $kind): HrPdf
{
    $emp = hr_employee_full((int) $s['employee_id']);
    $lwd = hr_fmt_date($s['approved_lwd'] ?: $emp['date_of_leaving']);
    $doj = hr_fmt_date($emp['date_of_joining']);
    $role = trim(($emp['designation'] ?? '') . ($emp['department'] ? ', ' . $emp['department'] : ''));
    $to = [$emp['full_name'], 'Employee ID: ' . $emp['emp_code']];
    if ($kind === 'relieving') {
        return hr_letter_pdf('REL/' . $emp['emp_code'] . '/' . date('Y'), $to, 'Relieving letter', [
            'Dear ' . strtok($emp['full_name'], ' ') . ',',
            'This is to confirm that your ' . strtolower(hr_separation_types()[$s['type']]) . ' dated ' . hr_fmt_date($s['notice_date'])
            . ' has been accepted and you are relieved from your duties' . ($role !== '' ? ' as ' . $role : '') . ' with effect from the close of business on ' . $lwd . '.',
            'We confirm that you have handed over your responsibilities. Your full and final settlement will be processed as per company policy.',
            'We thank you for your contribution and wish you every success in the future.',
        ]);
    }
    return hr_letter_pdf('EXP/' . $emp['emp_code'] . '/' . date('Y'), ['To whom it may concern'], 'Experience certificate', [
        'This is to certify that ' . $emp['full_name'] . ' (Employee ID ' . $emp['emp_code'] . ') was employed with us from ' . $doj . ' to ' . $lwd
        . ($role !== '' ? ', and last held the position of ' . $role : '') . '.',
        'During this period we found them sincere and hardworking. We wish them the very best in their future endeavours.',
    ]);
}

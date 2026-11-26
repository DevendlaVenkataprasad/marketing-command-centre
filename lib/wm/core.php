<?php
/**
 * Work Management (wm_*): departments, tasks, status history, comments and
 * time logs, run from the admin portal (admin/work.php) and opened by the
 * team members and clients the administrator gives access to (work.php),
 * through the portal's own sign-in.
 *
 * WHO
 *   admin   the administrator: everything.
 *   team    one of our staff (clients.kind='team', parent_id IS NULL) with an
 *           active wm_members row: tasks in their departments; create, update,
 *           comment, log time. Never users or access.
 *   client  a client account (clients.kind='client', parent_id IS NULL) with an
 *           active wm_members row: read-only, only their departments and the
 *           tasks shared with them; no internal comments, no time logs.
 *
 * Every read and write goes through wm_ctx() and wm_task_scope(); nothing the
 * browser says about itself is believed. A record outside someone's scope is
 * reported as not found (404), never as forbidden.
 *
 * TIME. PHP runs on IST and the live MySQL clock on UTC, so every timestamp is
 * written from PHP (wm_now()) and never from NOW().
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';

const WM_SCHEMA_VERSION = 1;
const WM_STATUSES   = ['To Do', 'In Progress', 'On Hold', 'Completed', 'Cancelled'];
const WM_PRIORITIES = ['Critical', 'High', 'Medium', 'Low'];
const WM_CLOSED     = ['Completed', 'Cancelled'];
const WM_INVITE_DAYS = 7;

/** A failure meant for the person: its message is safe to show, its code is the HTTP status. */
class WmError extends RuntimeException
{
}

function wm_fail(string $message, int $code = 400): never
{
    throw new WmError($message, $code);
}

function wm_now(): string
{
    return (string) ($GLOBALS['wm_clock'] ?? date('Y-m-d H:i:s'));
}

function wm_today(): string
{
    return substr(wm_now(), 0, 10);
}

/* ================================================================ schema */

function wm_install(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $have = fabo_one("SELECT v FROM wm_settings WHERE k = '_schema'");
        if ($have && (int) $have['v'] >= WM_SCHEMA_VERSION) {
            return;
        }
    } catch (Throwable $e) {
        // First run: nothing there yet.
    }
    $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    foreach ([
        "CREATE TABLE IF NOT EXISTS wm_settings (k VARCHAR(60) NOT NULL PRIMARY KEY, v TEXT NULL) $t",
        "CREATE TABLE IF NOT EXISTS wm_departments (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
           name VARCHAR(120) NOT NULL, color VARCHAR(7) NOT NULL DEFAULT '#2a78d6', description TEXT NULL,
           is_active TINYINT(1) NOT NULL DEFAULT 1,
           created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
           UNIQUE KEY u_name (name)) $t",
        "CREATE TABLE IF NOT EXISTS wm_members (
           client_id INT UNSIGNED NOT NULL PRIMARY KEY,
           role ENUM('team','client') NOT NULL,
           is_active TINYINT(1) NOT NULL DEFAULT 1, can_comment TINYINT(1) NOT NULL DEFAULT 0,
           invited_at DATETIME NULL, added_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
           KEY k_role (role, is_active)) $t",
        "CREATE TABLE IF NOT EXISTS wm_user_department_access (
           user_id INT UNSIGNED NOT NULL, department_id INT UNSIGNED NOT NULL,
           PRIMARY KEY (user_id, department_id), KEY k_dept (department_id)) $t",
        "CREATE TABLE IF NOT EXISTS wm_tasks (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
           task_code VARCHAR(16) NULL, title VARCHAR(200) NOT NULL, description TEXT NULL,
           department_id INT UNSIGNED NOT NULL, assignee_id INT UNSIGNED NULL,
           priority ENUM('Critical','High','Medium','Low') NOT NULL DEFAULT 'Medium',
           status ENUM('To Do','In Progress','On Hold','Completed','Cancelled') NOT NULL DEFAULT 'To Do',
           progress TINYINT UNSIGNED NOT NULL DEFAULT 0,
           start_date DATE NULL, due_date DATE NULL, completed_at DATETIME NULL,
           created_by INT UNSIGNED NOT NULL DEFAULT 0, created_by_name VARCHAR(160) NOT NULL DEFAULT '',
           created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME NULL,
           UNIQUE KEY u_code (task_code), KEY k_dept (department_id), KEY k_assignee (assignee_id),
           KEY k_status (status), KEY k_due (due_date), KEY k_deleted (deleted_at)) $t",
        "CREATE TABLE IF NOT EXISTS wm_client_task_shares (
           client_user_id INT UNSIGNED NOT NULL, task_id INT UNSIGNED NOT NULL,
           PRIMARY KEY (client_user_id, task_id), KEY k_task (task_id)) $t",
        "CREATE TABLE IF NOT EXISTS wm_task_status_history (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, task_id INT UNSIGNED NOT NULL,
           old_status VARCHAR(20) NULL, new_status VARCHAR(20) NOT NULL,
           old_progress TINYINT UNSIGNED NULL, new_progress TINYINT UNSIGNED NOT NULL,
           changed_by INT UNSIGNED NOT NULL DEFAULT 0, changed_by_name VARCHAR(160) NOT NULL DEFAULT '',
           changed_at DATETIME NOT NULL, note VARCHAR(500) NULL,
           KEY k_task_at (task_id, changed_at), KEY k_at (changed_at)) $t",
        "CREATE TABLE IF NOT EXISTS wm_task_comments (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, task_id INT UNSIGNED NOT NULL,
           author_id INT UNSIGNED NOT NULL DEFAULT 0, author_name VARCHAR(160) NOT NULL DEFAULT '',
           body TEXT NOT NULL, is_internal TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL,
           KEY k_task (task_id, created_at)) $t",
        "CREATE TABLE IF NOT EXISTS wm_work_logs (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, task_id INT UNSIGNED NOT NULL,
           user_id INT UNSIGNED NOT NULL DEFAULT 0, user_name VARCHAR(160) NOT NULL DEFAULT '',
           date DATE NOT NULL, minutes INT UNSIGNED NOT NULL, description VARCHAR(500) NULL, created_at DATETIME NOT NULL,
           KEY k_task (task_id, date), KEY k_user (user_id, date)) $t",
        "CREATE TABLE IF NOT EXISTS wm_audit (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, at DATETIME NOT NULL,
           actor_id INT UNSIGNED NOT NULL DEFAULT 0, actor_name VARCHAR(160) NOT NULL DEFAULT '',
           action VARCHAR(40) NOT NULL, task_id INT UNSIGNED NULL, target VARCHAR(190) NOT NULL DEFAULT '', detail TEXT NULL,
           KEY k_at (at), KEY k_task (task_id)) $t",
    ] as $sql) {
        fabo_exec($sql);
    }
    fabo_exec("INSERT INTO wm_settings (k, v) VALUES ('_schema', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)",
              's', [(string) WM_SCHEMA_VERSION]);
}

/* ================================================================ who */

/**
 * What this viewer is in Work Management, read fresh from the database:
 *   ['role' => admin|team|client, 'id' => person id (0 for the admin), 'name',
 *    'can_comment' => bool, 'depts' => [department ids]]
 * or null when they have no access (no row, deactivated, wrong kind of account,
 * or an account that is itself revoked or expired).
 */
function wm_ctx(array $viewer): ?array
{
    wm_install();
    if (!empty($viewer['is_admin'])) {
        return ['role' => 'admin', 'id' => 0, 'name' => 'Administrator', 'can_comment' => true, 'depts' => []];
    }
    $c = $viewer['client'] ?? null;
    if (!$c || ($viewer['state'] ?? 'ok') !== 'ok') {
        return null;
    }
    $id = (int) $c['id'];
    $m = fabo_one('SELECT m.*, c.kind, c.parent_id, c.name FROM wm_members m JOIN clients c ON c.id = m.client_id
                   WHERE m.client_id = ? AND m.is_active = 1', 'i', [$id]);
    if (!$m || $m['parent_id'] !== null || $m['kind'] !== $m['role']) {
        return null;
    }
    $depts = array_map(static fn($r) => (int) $r['department_id'],
        fabo_all('SELECT department_id FROM wm_user_department_access WHERE user_id = ?', 'i', [$id]));
    return ['role' => $m['role'], 'id' => $id, 'name' => (string) $m['name'],
            'can_comment' => $m['role'] === 'team' || (int) $m['can_comment'] === 1, 'depts' => $depts];
}

function wm_is_admin(array $ctx): bool
{
    return $ctx['role'] === 'admin';
}

/** Admin only. Anyone else gets 403: the action exists, they may not use it. */
function wm_need_admin(array $ctx): void
{
    if (!wm_is_admin($ctx)) {
        wm_fail('Only the administrator can do this.', 403);
    }
}

/** Admin or team: anything that changes work. Clients are read-only. */
function wm_need_writer(array $ctx): void
{
    if ($ctx['role'] === 'client') {
        wm_fail('Your access is read-only.', 403);
    }
}

/**
 * The WHERE fragment (task alias t) limiting tasks to what $ctx may see, as
 * [sql, types, params]. Soft-deleted tasks are always out.
 */
function wm_task_scope(array $ctx, string $alias = 't'): array
{
    $a = $alias;
    if (wm_is_admin($ctx)) {
        return ["$a.deleted_at IS NULL", '', []];
    }
    $depts = $ctx['depts'] ?: [0];
    $in = implode(',', array_map('intval', $depts));
    if ($ctx['role'] === 'team') {
        return ["$a.deleted_at IS NULL AND $a.department_id IN ($in)", '', []];
    }
    return ["$a.deleted_at IS NULL AND ($a.department_id IN ($in)
              OR $a.id IN (SELECT s.task_id FROM wm_client_task_shares s WHERE s.client_user_id = ?))",
            'i', [(int) $ctx['id']]];
}

/** One task the viewer may see, or a 404. */
function wm_task_get(array $ctx, int $id): array
{
    [$w, $ty, $p] = wm_task_scope($ctx);
    $t = fabo_one("SELECT t.* FROM wm_tasks t WHERE t.id = ? AND $w", 'i' . $ty, array_merge([$id], $p));
    if (!$t) {
        wm_fail('That task was not found.', 404);
    }
    return $t;
}

/** Departments this viewer may see (all for the admin, theirs for others). */
function wm_departments(array $ctx, bool $withCounts = false): array
{
    if (wm_is_admin($ctx)) {
        $rows = fabo_all('SELECT * FROM wm_departments ORDER BY is_active DESC, name');
    } elseif ($ctx['depts']) {
        $in = implode(',', array_map('intval', $ctx['depts']));
        $rows = fabo_all("SELECT * FROM wm_departments WHERE id IN ($in) ORDER BY is_active DESC, name");
    } else {
        $rows = [];
    }
    if ($withCounts && $rows) {
        [$w, $ty, $p] = wm_task_scope($ctx);
        $n = [];
        foreach (fabo_all("SELECT t.department_id, COUNT(*) AS total,
                                  SUM(t.status NOT IN ('Completed','Cancelled')) AS open,
                                  SUM(t.status = 'Completed') AS done
                           FROM wm_tasks t WHERE $w GROUP BY t.department_id", $ty, $p) as $r) {
            $n[(int) $r['department_id']] = $r;
        }
        foreach ($rows as &$d) {
            $c = $n[(int) $d['id']] ?? [];
            $d['total'] = (int) ($c['total'] ?? 0);
            $d['open'] = (int) ($c['open'] ?? 0);
            $d['done'] = (int) ($c['done'] ?? 0);
        }
        unset($d);
        // Tasks the viewer cannot see still stop a delete; that is decided in wm_dept_delete().
    }
    return $rows;
}

function wm_dept_may_use(array $ctx, int $deptId): bool
{
    return wm_is_admin($ctx) || in_array($deptId, $ctx['depts'], true);
}

/* ================================================================ audit */

function wm_audit(array $ctx, string $action, string $target = '', ?int $taskId = null, $detail = null): void
{
    try {
        fabo_exec('INSERT INTO wm_audit (at, actor_id, actor_name, action, task_id, target, detail) VALUES (?,?,?,?,?,?,?)',
                  'sississ', [wm_now(), (int) $ctx['id'], mb_substr((string) $ctx['name'], 0, 160), $action, $taskId,
                              mb_substr($target, 0, 190),
                              $detail === null ? null : (is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE))]);
    } catch (Throwable $e) {
        error_log('WM audit: ' . $e->getMessage());
    }
}

/* ================================================================ input */

function wm_str($v, int $max, bool $required = false, string $label = 'This field'): string
{
    $s = trim(str_replace("\0", '', (string) $v));
    if ($required && $s === '') {
        wm_fail($label . ' is required.');
    }
    if (mb_strlen($s) > $max) {
        wm_fail($label . ' is too long (at most ' . $max . ' characters).');
    }
    return $s;
}

function wm_date_in($v, string $label = 'Date', bool $required = false): ?string
{
    $v = trim((string) $v);
    if ($v === '') {
        if ($required) {
            wm_fail($label . ' is required.');
        }
        return null;
    }
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])
        || (int) $m[1] < 2000 || (int) $m[1] > 2100) {
        wm_fail($label . ' is not a valid date.');
    }
    return $v;
}

function wm_color_in($v): string
{
    $v = strtolower(trim((string) $v));
    return preg_match('/^#[0-9a-f]{6}$/', $v) ? $v : '#2a78d6';
}

/* ================================================================ departments */

function wm_dept_save(array $ctx, array $in): array
{
    wm_need_admin($ctx);
    $id = (int) ($in['id'] ?? 0);
    $name = wm_str($in['name'] ?? '', 120, true, 'Department name');
    $color = wm_color_in($in['color'] ?? '');
    $desc = wm_str($in['description'] ?? '', 2000, false, 'Description');
    $active = array_key_exists('is_active', $in) ? (!empty($in['is_active']) ? 1 : 0) : 1;
    $clash = fabo_one('SELECT id FROM wm_departments WHERE name = ? AND id <> ?', 'si', [$name, $id]);
    if ($clash) {
        wm_fail('A department with that name already exists.', 409);
    }
    $now = wm_now();
    if ($id > 0) {
        $old = fabo_one('SELECT * FROM wm_departments WHERE id = ?', 'i', [$id]);
        if (!$old) {
            wm_fail('That department was not found.', 404);
        }
        fabo_exec('UPDATE wm_departments SET name = ?, color = ?, description = ?, is_active = ?, updated_at = ? WHERE id = ?',
                  'sssisi', [$name, $color, $desc, $active, $now, $id]);
        wm_audit($ctx, 'department_updated', $name, null, ['active' => $active]);
    } else {
        $id = fabo_exec('INSERT INTO wm_departments (name, color, description, is_active, created_at, updated_at) VALUES (?,?,?,?,?,?)',
                        'sssiss', [$name, $color, $desc, $active, $now, $now]);
        wm_audit($ctx, 'department_created', $name);
    }
    return fabo_one('SELECT * FROM wm_departments WHERE id = ?', 'i', [$id]);
}

/** Deletes a department with no tasks at all (deleted ones included, so history stays whole). */
function wm_dept_delete(array $ctx, int $id): void
{
    wm_need_admin($ctx);
    $d = fabo_one('SELECT * FROM wm_departments WHERE id = ?', 'i', [$id]);
    if (!$d) {
        wm_fail('That department was not found.', 404);
    }
    $n = fabo_one('SELECT COUNT(*) AS n FROM wm_tasks WHERE department_id = ?', 'i', [$id]);
    if ((int) $n['n'] > 0) {
        wm_fail('This department has tasks, so it cannot be deleted. Deactivate it instead.', 409);
    }
    fabo_exec('DELETE FROM wm_user_department_access WHERE department_id = ?', 'i', [$id]);
    fabo_exec('DELETE FROM wm_departments WHERE id = ?', 'i', [$id]);
    wm_audit($ctx, 'department_deleted', (string) $d['name']);
}

/* ================================================================ people */

/** Active team members (the assignee list). */
function wm_team(): array
{
    return fabo_all("SELECT c.id, c.name, c.email FROM wm_members m JOIN clients c ON c.id = m.client_id
                     WHERE m.role = 'team' AND m.is_active = 1 AND c.kind = 'team' AND c.parent_id IS NULL
                       AND c.revoked_at IS NULL ORDER BY c.name");
}

/** Names for a set of person ids. */
function wm_names(array $ids): array
{
    $ids = array_values(array_filter(array_unique(array_map('intval', $ids))));
    if (!$ids) {
        return [];
    }
    $out = [];
    foreach (fabo_all('SELECT id, name FROM clients WHERE id IN (' . implode(',', $ids) . ')') as $r) {
        $out[(int) $r['id']] = (string) $r['name'];
    }
    return $out;
}

/* ================================================================ tasks */

function wm_task_input(array $ctx, array $in, ?array $old): array
{
    $f = [];
    $f['title'] = wm_str($in['title'] ?? ($old['title'] ?? ''), 200, true, 'Title');
    $f['description'] = wm_str($in['description'] ?? ($old['description'] ?? ''), 10000, false, 'Description');
    $dept = (int) ($in['department_id'] ?? ($old['department_id'] ?? 0));
    if ($dept <= 0) {
        wm_fail('Choose a department.');
    }
    $d = fabo_one('SELECT id, is_active FROM wm_departments WHERE id = ?', 'i', [$dept]);
    if (!$d || !wm_dept_may_use($ctx, $dept)) {
        wm_fail('That department was not found.', 404);
    }
    if (!(int) $d['is_active'] && (!$old || (int) $old['department_id'] !== $dept)) {
        wm_fail('That department is inactive. Choose an active one.');
    }
    $f['department_id'] = $dept;
    $asg = (int) ($in['assignee_id'] ?? ($old['assignee_id'] ?? 0));
    if ($asg > 0) {
        $ok = false;
        foreach (wm_team() as $p) {
            if ((int) $p['id'] === $asg) {
                $ok = true;
                break;
            }
        }
        if (!$ok && !($old && (int) $old['assignee_id'] === $asg)) {
            wm_fail('The assignee must be an active team member.');
        }
    }
    $f['assignee_id'] = $asg > 0 ? $asg : null;
    $pr = (string) ($in['priority'] ?? ($old['priority'] ?? 'Medium'));
    if (!in_array($pr, WM_PRIORITIES, true)) {
        wm_fail('Choose a valid priority.');
    }
    $f['priority'] = $pr;
    $st = (string) ($in['status'] ?? ($old['status'] ?? 'To Do'));
    if (!in_array($st, WM_STATUSES, true)) {
        wm_fail('Choose a valid status.');
    }
    $f['status'] = $st;
    $pg = $in['progress'] ?? ($old['progress'] ?? 0);
    if (!is_numeric($pg) || (int) $pg < 0 || (int) $pg > 100) {
        wm_fail('Progress must be between 0 and 100.');
    }
    $f['progress'] = (int) $pg;
    if ($st === 'Completed') {
        $f['progress'] = 100;
    }
    $f['start_date'] = wm_date_in($in['start_date'] ?? ($old['start_date'] ?? ''), 'Start date');
    $f['due_date'] = wm_date_in($in['due_date'] ?? ($old['due_date'] ?? ''), 'Due date');
    if ($f['start_date'] && $f['due_date'] && $f['due_date'] < $f['start_date']) {
        wm_fail('The due date is before the start date.');
    }
    return $f;
}

function wm_history_add(array $ctx, int $taskId, ?string $oldS, string $newS, ?int $oldP, int $newP, string $note = ''): void
{
    fabo_exec('INSERT INTO wm_task_status_history (task_id, old_status, new_status, old_progress, new_progress, changed_by, changed_by_name, changed_at, note)
               VALUES (?,?,?,?,?,?,?,?,?)', 'issiiisss',
              [$taskId, $oldS, $newS, $oldP, $newP, (int) $ctx['id'], mb_substr((string) $ctx['name'], 0, 160), wm_now(),
               $note === '' ? null : mb_substr($note, 0, 500)]);
}

/** Creates or edits a task. Status and progress changes land in the history in the same transaction. */
function wm_task_save(array $ctx, array $in): array
{
    wm_need_writer($ctx);
    $id = (int) ($in['id'] ?? 0);
    $old = $id > 0 ? wm_task_get($ctx, $id) : null;
    $f = wm_task_input($ctx, $in, $old);
    $note = wm_str($in['note'] ?? '', 500, false, 'Note');
    $now = wm_now();
    $db = fabo_db();
    $db->begin_transaction();
    try {
        if ($old) {
            $completed = $f['status'] === 'Completed'
                ? ($old['status'] === 'Completed' ? $old['completed_at'] : $now) : null;
            fabo_exec('UPDATE wm_tasks SET title = ?, description = ?, department_id = ?, assignee_id = ?, priority = ?, status = ?,
                              progress = ?, start_date = ?, due_date = ?, completed_at = ?, updated_at = ? WHERE id = ?',
                      'ssiississssi', [$f['title'], $f['description'], $f['department_id'], $f['assignee_id'], $f['priority'],
                                       $f['status'], $f['progress'], $f['start_date'], $f['due_date'], $completed, $now, $id]);
            if ($old['status'] !== $f['status'] || (int) $old['progress'] !== $f['progress']) {
                wm_history_add($ctx, $id, $old['status'], $f['status'], (int) $old['progress'], $f['progress'], $note);
            }
            if (!wm_is_admin($ctx)) {
                // A team member may move a task only between their own departments; checked in wm_task_input().
            }
        } else {
            $id = fabo_exec('INSERT INTO wm_tasks (title, description, department_id, assignee_id, priority, status, progress,
                                    start_date, due_date, completed_at, created_by, created_by_name, created_at, updated_at)
                             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)', 'ssiississsisss',
                            [$f['title'], $f['description'], $f['department_id'], $f['assignee_id'], $f['priority'], $f['status'],
                             $f['progress'], $f['start_date'], $f['due_date'], $f['status'] === 'Completed' ? $now : null,
                             (int) $ctx['id'], mb_substr((string) $ctx['name'], 0, 160), $now, $now]);
            fabo_exec('UPDATE wm_tasks SET task_code = ? WHERE id = ?', 'si', [sprintf('T-%04d', $id), $id]);
            wm_history_add($ctx, $id, null, $f['status'], null, $f['progress'], $note !== '' ? $note : 'Created');
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
    $t = fabo_one('SELECT * FROM wm_tasks WHERE id = ?', 'i', [$id]);
    if ($old) {
        $changed = [];
        foreach (['title', 'description', 'department_id', 'assignee_id', 'priority', 'status', 'progress', 'start_date', 'due_date'] as $k) {
            if ((string) ($old[$k] ?? '') !== (string) ($t[$k] ?? '')) {
                $changed[$k] = [$old[$k], $t[$k]];
            }
        }
        if ($changed) {
            wm_audit($ctx, 'task_edited', (string) $t['task_code'], $id, $changed);
        }
        if ($old['status'] !== $t['status']) {
            wm_audit($ctx, 'status_changed', (string) $t['task_code'], $id, $old['status'] . ' → ' . $t['status']);
        }
    } else {
        wm_audit($ctx, 'task_created', (string) $t['task_code'], $id, $t['title']);
    }
    return $t;
}

/** A quick status (and optionally progress) change, as from the board. */
function wm_task_status(array $ctx, int $id, string $status, $progress = null, string $note = ''): array
{
    $t = wm_task_get($ctx, $id);
    $in = ['id' => $id, 'status' => $status, 'note' => $note];
    if ($progress !== null && $progress !== '') {
        $in['progress'] = $progress;
    } elseif ($status !== 'Completed' && $t['status'] === 'Completed') {
        $in['progress'] = $t['progress'];
    }
    return wm_task_save($ctx, $in);
}

function wm_task_delete(array $ctx, int $id): void
{
    wm_need_admin($ctx);
    $t = wm_task_get($ctx, $id);
    fabo_exec('UPDATE wm_tasks SET deleted_at = ? WHERE id = ?', 'si', [wm_now(), $id]);
    wm_audit($ctx, 'task_deleted', (string) $t['task_code'], $id, $t['title']);
}

/* ================================================================ detail */

function wm_task_detail(array $ctx, int $id): array
{
    $t = wm_task_get($ctx, $id);
    $client = $ctx['role'] === 'client';
    $comments = fabo_all('SELECT id, author_id, author_name, body, is_internal, created_at FROM wm_task_comments
                          WHERE task_id = ?' . ($client ? ' AND is_internal = 0' : '') . ' ORDER BY created_at, id', 'i', [$id]);
    // Notes on the history are written by staff for staff, like internal comments: a client gets the changes without them.
    $history = fabo_all('SELECT id, old_status, new_status, old_progress, new_progress, changed_by_name, changed_at, '
                        . ($client ? 'NULL AS note' : 'note') . '
                         FROM wm_task_status_history WHERE task_id = ? ORDER BY changed_at, id', 'i', [$id]);
    $logs = $client ? [] : fabo_all('SELECT id, user_id, user_name, date, minutes, description FROM wm_work_logs
                                     WHERE task_id = ? ORDER BY date DESC, id DESC', 'i', [$id]);
    $shares = [];
    if (wm_is_admin($ctx)) {
        $shares = array_map(static fn($r) => (int) $r['client_user_id'],
            fabo_all('SELECT client_user_id FROM wm_client_task_shares WHERE task_id = ?', 'i', [$id]));
    }
    return ['task' => wm_task_public($t, $ctx), 'comments' => $comments, 'history' => $history, 'logs' => $logs,
            'minutes' => array_sum(array_map(static fn($l) => (int) $l['minutes'], $logs)), 'shares' => $shares];
}

/** A task as the browser receives it. */
function wm_task_public(array $t, array $ctx, ?array $names = null, ?array $depts = null): array
{
    $names = $names ?? wm_names([(int) $t['assignee_id']]);
    $today = wm_today();
    $out = [
        'id' => (int) $t['id'], 'code' => (string) $t['task_code'], 'title' => (string) $t['title'],
        'description' => (string) $t['description'], 'department_id' => (int) $t['department_id'],
        'assignee_id' => $t['assignee_id'] === null ? null : (int) $t['assignee_id'],
        'assignee' => $t['assignee_id'] === null ? '' : ($names[(int) $t['assignee_id']] ?? ''),
        'priority' => (string) $t['priority'], 'status' => (string) $t['status'], 'progress' => (int) $t['progress'],
        'start_date' => $t['start_date'], 'due_date' => $t['due_date'], 'completed_at' => $t['completed_at'],
        'created_at' => $t['created_at'], 'updated_at' => $t['updated_at'],
        'overdue' => $t['due_date'] !== null && $t['due_date'] < $today && !in_array($t['status'], WM_CLOSED, true),
    ];
    if ($depts !== null) {
        $out['department'] = $depts[(int) $t['department_id']]['name'] ?? '';
    }
    return $out;
}

/**
 * The task list with filters: dept, status, priority, assignee, due (overdue|today|week|none), q.
 */
function wm_tasks(array $ctx, array $f = []): array
{
    [$w, $ty, $p] = wm_task_scope($ctx);
    $where = [$w];
    if (!empty($f['dept'])) {
        $where[] = 't.department_id = ?';
        $ty .= 'i';
        $p[] = (int) $f['dept'];
    }
    if (!empty($f['status']) && in_array($f['status'], WM_STATUSES, true)) {
        $where[] = 't.status = ?';
        $ty .= 's';
        $p[] = $f['status'];
    }
    if (!empty($f['priority']) && in_array($f['priority'], WM_PRIORITIES, true)) {
        $where[] = 't.priority = ?';
        $ty .= 's';
        $p[] = $f['priority'];
    }
    if (isset($f['assignee']) && $f['assignee'] !== '') {
        if ((string) $f['assignee'] === 'none') {
            $where[] = 't.assignee_id IS NULL';
        } else {
            $where[] = 't.assignee_id = ?';
            $ty .= 'i';
            $p[] = (int) $f['assignee'];
        }
    }
    $today = wm_today();
    switch ((string) ($f['due'] ?? '')) {
        case 'overdue':
            $where[] = "t.due_date < ? AND t.status NOT IN ('Completed','Cancelled')";
            $ty .= 's';
            $p[] = $today;
            break;
        case 'today':
            $where[] = 't.due_date = ?';
            $ty .= 's';
            $p[] = $today;
            break;
        case 'week':
            $where[] = 't.due_date BETWEEN ? AND ?';
            $ty .= 'ss';
            array_push($p, $today, date('Y-m-d', strtotime($today . ' +7 days')));
            break;
        case 'none':
            $where[] = 't.due_date IS NULL';
            break;
    }
    $q = trim((string) ($f['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($q, 0, 100)) . '%';
        $where[] = '(t.title LIKE ? OR t.description LIKE ? OR t.task_code LIKE ?)';
        $ty .= 'sss';
        array_push($p, $like, $like, $like);
    }
    $rows = fabo_all('SELECT t.* FROM wm_tasks t WHERE ' . implode(' AND ', $where) . "
                      ORDER BY FIELD(t.status,'In Progress','To Do','On Hold','Completed','Cancelled'),
                               t.due_date IS NULL, t.due_date, FIELD(t.priority,'Critical','High','Medium','Low'), t.id DESC
                      LIMIT 2000", $ty, $p);
    $names = wm_names(array_column($rows, 'assignee_id'));
    $depts = [];
    foreach (fabo_all('SELECT id, name, color FROM wm_departments') as $d) {
        $depts[(int) $d['id']] = $d;
    }
    return array_map(static fn($t) => wm_task_public($t, $ctx, $names, $depts), $rows);
}

/* ================================================================ comments, time */

function wm_comment_add(array $ctx, int $taskId, string $body, bool $internal): array
{
    wm_task_get($ctx, $taskId);
    if (!$ctx['can_comment']) {
        wm_fail('Your access does not include comments.', 403);
    }
    $body = wm_str($body, 5000, true, 'Comment');
    if ($ctx['role'] === 'client') {
        $internal = false;                 // a client's comment is always visible to them
    }
    $id = fabo_exec('INSERT INTO wm_task_comments (task_id, author_id, author_name, body, is_internal, created_at) VALUES (?,?,?,?,?,?)',
                    'iissis', [$taskId, (int) $ctx['id'], mb_substr((string) $ctx['name'], 0, 160), $body, $internal ? 1 : 0, wm_now()]);
    return fabo_one('SELECT id, author_id, author_name, body, is_internal, created_at FROM wm_task_comments WHERE id = ?', 'i', [$id]);
}

function wm_log_add(array $ctx, int $taskId, string $date, int $minutes, string $desc): array
{
    wm_need_writer($ctx);
    wm_task_get($ctx, $taskId);
    $date = wm_date_in($date, 'Date', true);
    if ($minutes < 1 || $minutes > 24 * 60) {
        wm_fail('Time must be between 1 minute and 24 hours.');
    }
    if ($date > wm_today()) {
        wm_fail('Time cannot be logged for a future date.');
    }
    $desc = wm_str($desc, 500, false, 'Description');
    $id = fabo_exec('INSERT INTO wm_work_logs (task_id, user_id, user_name, date, minutes, description, created_at) VALUES (?,?,?,?,?,?,?)',
                    'iississ', [$taskId, (int) $ctx['id'], mb_substr((string) $ctx['name'], 0, 160), $date, $minutes, $desc, wm_now()]);
    return fabo_one('SELECT id, user_id, user_name, date, minutes, description FROM wm_work_logs WHERE id = ?', 'i', [$id]);
}

/** One's own entry, or any for the admin. */
function wm_log_delete(array $ctx, int $logId): void
{
    wm_need_writer($ctx);
    $l = fabo_one('SELECT * FROM wm_work_logs WHERE id = ?', 'i', [$logId]);
    if (!$l) {
        wm_fail('That time entry was not found.', 404);
    }
    wm_task_get($ctx, (int) $l['task_id']);
    if (!wm_is_admin($ctx) && (int) $l['user_id'] !== (int) $ctx['id']) {
        wm_fail('That time entry was not found.', 404);
    }
    fabo_exec('DELETE FROM wm_work_logs WHERE id = ?', 'i', [$logId]);
}

/* ================================================================ overview */

function wm_overview(array $ctx, int $dept, string $from, string $to): array
{
    [$w, $ty, $p] = wm_task_scope($ctx);
    if ($dept > 0) {
        $w .= ' AND t.department_id = ?';
        $ty .= 'i';
        $p[] = $dept;
    }
    $today = wm_today();
    $weekStart = date('Y-m-d', strtotime($today . ' -' . ((int) date('N', strtotime($today)) - 1) . ' days'));
    $k = fabo_one("SELECT SUM(t.status IN ('To Do','In Progress','On Hold')) AS open,
                          SUM(t.status = 'In Progress') AS progress,
                          SUM(t.due_date = ? AND t.status NOT IN ('Completed','Cancelled')) AS due_today,
                          SUM(t.due_date < ? AND t.status NOT IN ('Completed','Cancelled')) AS overdue,
                          SUM(t.status = 'Completed' AND t.completed_at >= ?) AS done_week
                   FROM wm_tasks t WHERE $w", 'sss' . $ty, array_merge([$today, $today, $weekStart . ' 00:00:00'], $p));
    // Status changes per day over the chosen period (default: this week).
    $changes = fabo_all("SELECT DATE(h.changed_at) AS d, h.new_status AS s, COUNT(*) AS n
                         FROM wm_task_status_history h JOIN wm_tasks t ON t.id = h.task_id
                         WHERE $w AND h.changed_at BETWEEN ? AND ? AND (h.old_status IS NULL OR h.old_status <> h.new_status)
                         GROUP BY DATE(h.changed_at), h.new_status", $ty . 'ss', array_merge($p, [$from . ' 00:00:00', $to . ' 23:59:59']));
    $perDept = fabo_all("SELECT d.id, d.name, d.color, COUNT(t.id) AS n FROM wm_tasks t JOIN wm_departments d ON d.id = t.department_id
                         WHERE $w AND t.status IN ('To Do','In Progress','On Hold') GROUP BY d.id ORDER BY n DESC, d.name", $ty, $p);
    $soon = date('Y-m-d', strtotime($today . ' +14 days'));
    $up = fabo_all("SELECT t.* FROM wm_tasks t WHERE $w AND t.status NOT IN ('Completed','Cancelled') AND t.due_date BETWEEN ? AND ?
                    ORDER BY t.due_date, FIELD(t.priority,'Critical','High','Medium','Low') LIMIT 8", $ty . 'ss', array_merge($p, [$today, $soon]));
    $od = fabo_all("SELECT t.* FROM wm_tasks t WHERE $w AND t.status NOT IN ('Completed','Cancelled') AND t.due_date < ?
                    ORDER BY t.due_date, FIELD(t.priority,'Critical','High','Medium','Low') LIMIT 8", $ty . 's', array_merge($p, [$today]));
    $names = wm_names(array_merge(array_column($up, 'assignee_id'), array_column($od, 'assignee_id')));
    $depts = [];
    foreach (fabo_all('SELECT id, name, color FROM wm_departments') as $d) {
        $depts[(int) $d['id']] = $d;
    }
    $total = fabo_one("SELECT COUNT(*) AS n FROM wm_tasks t WHERE $w", $ty, $p);
    return [
        'kpis' => ['open' => (int) $k['open'], 'progress' => (int) $k['progress'], 'due_today' => (int) $k['due_today'],
                   'overdue' => (int) $k['overdue'], 'done_week' => (int) $k['done_week']],
        'total' => (int) $total['n'],
        'changes' => $changes, 'per_dept' => $perDept,
        'upcoming' => array_map(static fn($t) => wm_task_public($t, $ctx, $names, $depts), $up),
        'overdue' => array_map(static fn($t) => wm_task_public($t, $ctx, $names, $depts), $od),
        'from' => $from, 'to' => $to,
    ];
}

<?php
/**
 * Work Management self-test, LOCAL ONLY:
 *   php tests/wm_selftest.php          roles and scoping, status history, status-at-date, report, xlsx
 *   php tests/wm_selftest.php seed     leaves test people/departments/tasks for an HTTP run (passwords below)
 *   php tests/wm_selftest.php clean    removes everything the test made
 * Uses its own people (ids 992101-992105) and departments named "WMTEST …" on the
 * local database, and refuses to run anywhere else. Never deploy this.
 * No email leaves the machine (wm_mail_hook).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
chdir(dirname(__DIR__));
$_SERVER['HTTP_HOST'] = 'localhost:8765';
$_SERVER['SCRIPT_NAME'] = '/work.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once 'lib/ui.php';
require_once 'lib/wm/core.php';
require_once 'lib/wm/access.php';
require_once 'lib/wm/report.php';
if (str_contains(str_replace('\\', '/', __DIR__), '/home/') || !in_array(DB_HOST, ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "Refusing: this test only runs on a local development copy.\n");
    exit(2);
}
fabo_boot();
wm_install();

const TA = 992101;   // team member, department 1
const TB = 992102;   // team member, department 2
const CX = 992103;   // client X: department 1 + one shared task
const CY = 992104;   // client Y: one shared task only
const TN = 992105;   // team account without Work access
$PEOPLE = [TA => ['WMTEST Team A', 'team'], TB => ['WMTEST Team B', 'team'], CX => ['WMTEST Client X', 'client'],
           CY => ['WMTEST Client Y', 'client'], TN => ['WMTEST No Access', 'team']];
$GLOBALS['wm_mail_hook'] = static function ($to, $s) {
    $GLOBALS['mails'][] = [$to, $s];
    return [true, 'test hook'];
};

function cleanup(): void
{
    $ids = implode(',', [TA, TB, CX, CY, TN]);
    $tasks = array_map(static fn($r) => (int) $r['id'], fabo_all("SELECT t.id FROM wm_tasks t JOIN wm_departments d ON d.id = t.department_id WHERE d.name LIKE 'WMTEST %'"));
    if ($tasks) {
        $in = implode(',', $tasks);
        foreach (['wm_task_status_history', 'wm_task_comments', 'wm_work_logs', 'wm_client_task_shares'] as $t) {
            fabo_exec("DELETE FROM $t WHERE task_id IN ($in)");
        }
        fabo_exec("DELETE FROM wm_audit WHERE task_id IN ($in)");
        fabo_exec("DELETE FROM wm_tasks WHERE id IN ($in)");
    }
    fabo_exec("DELETE FROM wm_user_department_access WHERE user_id IN ($ids) OR department_id IN (SELECT id FROM wm_departments WHERE name LIKE 'WMTEST %')");
    fabo_exec("DELETE FROM wm_client_task_shares WHERE client_user_id IN ($ids)");
    fabo_exec("DELETE FROM wm_members WHERE client_id IN ($ids)");
    fabo_exec("DELETE FROM wm_departments WHERE name LIKE 'WMTEST %'");
    fabo_exec("DELETE FROM wm_audit WHERE target LIKE 'WMTEST %' OR target LIKE 'Work-Report_WMTEST%' OR actor_name LIKE 'WMTEST %'");
    fabo_exec("DELETE FROM password_invites WHERE client_id IN ($ids)");
    try {
        fabo_exec("DELETE FROM notifications WHERE client_id IN ($ids)");
    } catch (Throwable $e) {
    }
    fabo_exec("DELETE FROM clients WHERE id IN ($ids)");
    // Tasks numbered by the test leave a gap in the sequence; reset it when the table is otherwise empty.
    if (!fabo_one('SELECT id FROM wm_tasks LIMIT 1')) {
        fabo_exec('ALTER TABLE wm_tasks AUTO_INCREMENT = 1');
    }
}

$mode = $argv[1] ?? 'test';
cleanup();
if ($mode === 'clean') {
    echo "cleaned\n";
    exit(0);
}

foreach ($PEOPLE as $id => [$name, $kind]) {
    fabo_exec('INSERT INTO clients (id, name, kind, email, token, expires_at) VALUES (?,?,?,?,?,NULL)', 'issss',
              [$id, $name, $kind, 'wmtest' . $id . '@example.test', fabo_new_token()]);
    fabo_set_password($id, 'wm-pass-' . $id);
    fabo_exec('UPDATE clients SET must_change = 0 WHERE id = ?', 'i', [$id]);
}

$pass = $failed = 0;
function ok(bool $c, string $what): void
{
    global $pass, $failed;
    if ($c) {
        $pass++;
    } else {
        $failed++;
        echo "FAIL: $what\n";
    }
}
/** Runs $fn and returns the WmError code it threw, or 0. */
function code(callable $fn): int
{
    try {
        $fn();
        return 0;
    } catch (WmError $e) {
        return (int) $e->getCode();
    }
}
function viewer(int $id): array
{
    return fabo_person_viewer(fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [$id]));
}

$GLOBALS['wm_clock'] = '2026-11-01 10:00:00';
$admin = wm_ctx(fabo_admin_viewer());
ok($admin['role'] === 'admin', 'admin context');
ok(wm_ctx(viewer(TN)) === null, 'a team account without membership has no access');

/* ---- departments */
$d1 = wm_dept_save($admin, ['name' => 'WMTEST Design', 'color' => '#2a78d6']);
$d2 = wm_dept_save($admin, ['name' => 'WMTEST Web', 'color' => '#1baf7a']);
ok(code(fn() => wm_dept_save($admin, ['name' => 'WMTEST Design'])) === 409, 'duplicate department name refused');
ok(code(fn() => wm_dept_save($admin, ['name' => ''])) === 400, 'empty department name refused');
$d1 = (int) $d1['id'];
$d2 = (int) $d2['id'];

/* ---- members */
$r = wm_member_invite($admin, ['role' => 'team', 'person_id' => TA, 'depts' => [$d1]]);
ok($r['mailed'] && $r['kind'] === 'signin', 'existing account with a password gets a sign-in link');
wm_member_invite($admin, ['role' => 'team', 'person_id' => TB, 'depts' => [$d2]]);
wm_member_invite($admin, ['role' => 'client', 'person_id' => CX, 'depts' => [$d1]]);
wm_member_invite($admin, ['role' => 'client', 'person_id' => CY, 'depts' => []]);
ok(code(fn() => wm_member_invite($admin, ['role' => 'client', 'person_id' => TN])) === 404, 'a team account cannot be added as a client');
ok(code(fn() => wm_member_invite($admin, ['role' => 'team', 'person_id' => TA])) === 409, 'the same person cannot be added twice');
// A brand-new person gets an account and a one-time password link.
$GLOBALS['mails'] = [];
$new = wm_member_invite($admin, ['role' => 'team', 'name' => 'WMTEST New Person', 'email' => 'wmtest-new@example.test', 'depts' => [$d1]]);
ok($new['kind'] === 'invite' && str_contains($new['link'], 'setpassword.php?t='), 'new person gets a one-time set-password link');
ok(count($GLOBALS['mails']) === 1 && $GLOBALS['mails'][0][0] === 'wmtest-new@example.test', 'the invitation is emailed');
$newId = (int) $new['member']['id'];
$newRow = fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [$newId]);
ok($newRow['kind'] === 'team' && $newRow['parent_id'] === null, 'new team member is one of our staff');
ok(fabo_invite_live($newId) !== null, 'invitation is live');
// Clean this one up with the rest.
fabo_exec('DELETE FROM wm_user_department_access WHERE user_id = ?', 'i', [$newId]);
fabo_exec('DELETE FROM wm_members WHERE client_id = ?', 'i', [$newId]);
fabo_exec('DELETE FROM password_invites WHERE client_id = ?', 'i', [$newId]);
fabo_exec('DELETE FROM clients WHERE id = ?', 'i', [$newId]);

$ta = wm_ctx(viewer(TA));
$tb = wm_ctx(viewer(TB));
$cx = wm_ctx(viewer(CX));
$cy = wm_ctx(viewer(CY));
ok($ta && $ta['role'] === 'team' && $ta['depts'] === [$d1], 'team A context');
ok($cx && $cx['role'] === 'client' && !$cx['can_comment'], 'client X context, no comments by default');

/* ---- team member may not manage users or departments (403) */
ok(code(fn() => wm_member_invite($ta, ['role' => 'team', 'name' => 'X', 'email' => 'x@example.test'])) === 403, 'team member cannot invite (403)');
ok(code(fn() => wm_member_update($ta, TB, ['depts' => [$d1]])) === 403, 'team member cannot change access (403)');
ok(code(fn() => wm_member_active($ta, TB, false)) === 403, 'team member cannot deactivate (403)');
ok(code(fn() => wm_members($ta)) === 403, 'team member cannot list users (403)');
ok(code(fn() => wm_dept_save($ta, ['name' => 'WMTEST Mine'])) === 403, 'team member cannot create departments (403)');

/* ---- tasks and history */
$t1 = wm_task_save($admin, ['title' => 'WMTEST Logo', 'description' => 'New logo', 'department_id' => $d1, 'priority' => 'High', 'due_date' => '2026-11-10']);
$t1id = (int) $t1['id'];
ok(preg_match('/^T-\d{4,}$/', (string) $t1['task_code']) === 1, 'readable task code');
$h = fabo_all('SELECT * FROM wm_task_status_history WHERE task_id = ? ORDER BY id', 'i', [$t1id]);
ok(count($h) === 1 && $h[0]['old_status'] === null && $h[0]['new_status'] === 'To Do' && (int) $h[0]['new_progress'] === 0, 'initial history row on creation');

$GLOBALS['wm_clock'] = '2026-11-05 09:00:00';
wm_task_save($ta, ['id' => $t1id, 'status' => 'In Progress', 'progress' => 30, 'note' => 'Drafts started']);
$GLOBALS['wm_clock'] = '2026-11-06 12:00:00';
wm_task_save($ta, ['id' => $t1id, 'progress' => 60]);
wm_task_save($ta, ['id' => $t1id, 'title' => 'WMTEST Logo v2']);
$h = fabo_all('SELECT * FROM wm_task_status_history WHERE task_id = ? ORDER BY id', 'i', [$t1id]);
ok(count($h) === 3, 'status and progress changes recorded, a title edit is not (' . count($h) . ')');
ok($h[1]['old_status'] === 'To Do' && $h[1]['new_status'] === 'In Progress' && (int) $h[1]['new_progress'] === 30 && $h[1]['note'] === 'Drafts started'
   && $h[1]['changed_by_name'] === 'WMTEST Team A' && $h[1]['changed_at'] === '2026-11-05 09:00:00', 'status change row has who, when and note');
ok($h[2]['old_status'] === 'In Progress' && $h[2]['new_status'] === 'In Progress' && (int) $h[2]['old_progress'] === 30 && (int) $h[2]['new_progress'] === 60, 'progress-only change row');

$GLOBALS['wm_clock'] = '2026-11-12 16:30:00';
$done = wm_task_status($ta, $t1id, 'Completed');
ok((int) $done['progress'] === 100 && $done['completed_at'] === '2026-11-12 16:30:00', 'completing sets progress 100 and completed_at');

// A task created after the compare date, in department 2.
$GLOBALS['wm_clock'] = '2026-11-08 11:00:00';
$t2 = wm_task_save($tb, ['title' => 'WMTEST Landing page', 'department_id' => $d2, 'assignee_id' => TB, 'due_date' => '2026-11-09']);
$t2id = (int) $t2['id'];
// A task in department 2 shared with client Y.
$t3 = wm_task_save($admin, ['title' => 'WMTEST Hosting', 'department_id' => $d2, 'status' => 'On Hold']);
$t3id = (int) $t3['id'];
wm_task_shares_set($admin, $t3id, [CY]);
ok(code(fn() => wm_task_shares_set($admin, $t3id, [TA])) === 404, 'tasks are shared only with client members');

/* ---- scoping: 404 for what is not theirs */
ok(code(fn() => wm_task_get($ta, $t2id)) === 404, 'team A cannot open a department-2 task (404)');
ok(code(fn() => wm_task_save($ta, ['title' => 'WMTEST sneaky', 'department_id' => $d2])) === 404, 'team A cannot create in department 2 (404)');
ok(code(fn() => wm_task_save($ta, ['id' => $t1id, 'department_id' => $d2])) === 404, 'team A cannot move a task into department 2 (404)');
ok(code(fn() => wm_task_get($cx, $t3id)) === 404, 'client X cannot open client Y\'s shared task (404)');
ok(code(fn() => wm_task_detail($cx, $t3id)) === 404, 'client X cannot read client Y\'s task detail (404)');
ok(code(fn() => wm_task_get($cy, $t1id)) === 404, 'client Y cannot open a department-1 task (404)');
ok(wm_task_get($cy, $t3id)['id'] == $t3id, 'client Y opens the task shared with them');
ok(wm_task_get($cx, $t1id)['id'] == $t1id, 'client X opens tasks in their department');
$cxList = array_column(wm_tasks($cx), 'id');
ok($cxList === [$t1id], 'client X lists only their department\'s tasks');
ok(array_column(wm_tasks($cy), 'id') === [$t3id], 'client Y lists only the shared task');
ok(count(wm_tasks($admin)) === 3, 'admin sees every task');
ok(array_map('intval', array_column(wm_departments($cy), 'id')) === [], 'client Y has no departments');

/* ---- clients are read-only */
ok(code(fn() => wm_task_save($cx, ['id' => $t1id, 'status' => 'To Do'])) === 403, 'client cannot edit a task (403)');
ok(code(fn() => wm_task_save($cx, ['title' => 'WMTEST x', 'department_id' => $d1])) === 403, 'client cannot create a task (403)');
ok(code(fn() => wm_log_add($cx, $t1id, '2026-11-12', 30, '')) === 403, 'client cannot log time (403)');
ok(code(fn() => wm_comment_add($cx, $t1id, 'hello', false)) === 403, 'client without comment right cannot comment (403)');
ok(code(fn() => wm_task_delete($ta, $t1id)) === 403, 'team member cannot delete tasks (403)');

/* ---- comments and time */
$GLOBALS['wm_clock'] = '2026-11-12 17:00:00';
wm_comment_add($ta, $t1id, 'WMTEST internal: invoice later', true);
$GLOBALS['wm_clock'] = '2026-11-12 17:05:00';
wm_comment_add($admin, $t1id, 'Final logo delivered', false);
$GLOBALS['wm_clock'] = '2026-11-12 18:00:00';
wm_comment_add($ta, $t1id, 'WMTEST internal: last word', true);
wm_log_add($ta, $t1id, '2026-11-05', 90, 'Sketches');
wm_log_add($ta, $t1id, '2026-11-03', 60, 'Before the period');
ok(code(fn() => wm_log_add($ta, $t1id, '2026-11-20', 30, '')) === 400, 'time cannot be logged in the future');
wm_member_update($admin, CX, ['depts' => [$d1], 'can_comment' => true]);
$cx = wm_ctx(viewer(CX));
$GLOBALS['wm_clock'] = '2026-11-12 17:30:00';    // before the last internal comment
$c = wm_comment_add($cx, $t1id, 'Looks great', true);
$GLOBALS['wm_clock'] = '2026-11-12 18:00:00';
ok((int) $c['is_internal'] === 0, 'a client comment is never internal');
$det = wm_task_detail($cx, $t1id);
ok(count($det['comments']) === 2 && !array_filter($det['comments'], static fn($x) => (int) $x['is_internal'] === 1), 'client sees no internal comments');
ok($det['logs'] === [] && $det['minutes'] === 0, 'client sees no time logs');
ok(count($det['history']) === 4 && !array_filter($det['history'], static fn($x) => $x['note'] !== null), 'client sees the status history without staff notes');
ok(in_array('Drafts started', array_column(wm_task_detail($ta, $t1id)['history'], 'note'), true), 'team sees history notes');
ok(count(wm_task_detail($ta, $t1id)['comments']) === 4, 'team sees internal comments');

/* ---- status at a date */
$at = wm_status_at([$t1id, $t2id], '2026-10-31');
ok(!isset($at[$t1id]) && !isset($at[$t2id]), 'before creation: not created');
$at = wm_status_at([$t1id, $t2id], '2026-11-04');
ok($at[$t1id]['status'] === 'To Do' && $at[$t1id]['progress'] === 0 && !isset($at[$t2id]), 'status on 4 Nov');
$at = wm_status_at([$t1id], '2026-11-05');
ok($at[$t1id]['status'] === 'In Progress' && $at[$t1id]['progress'] === 30, 'a change during the day counts for that day');
$at = wm_status_at([$t1id], '2026-11-11');
ok($at[$t1id]['status'] === 'In Progress' && $at[$t1id]['progress'] === 60, 'status on 11 Nov (latest change before end of day)');
$at = wm_status_at([$t1id], '2026-11-12');
ok($at[$t1id]['status'] === 'Completed' && $at[$t1id]['progress'] === 100, 'status on 12 Nov');

/* ---- the report: last week vs today */
$GLOBALS['wm_clock'] = '2026-11-12 19:00:00';
$rep = wm_report($admin, ['compare' => '2026-11-05', 'current' => '2026-11-12', 'depts' => [$d1, $d2]]);
$by = [];
foreach ($rep['rows'] as $row) {
    $by[$row['id']] = $row;
}
ok(count($rep['rows']) === 3, 'report has the three tasks');
$r1 = $by[$t1id];
ok($r1['status_cmp'] === 'In Progress' && $r1['status_cur'] === 'Completed' && $r1['progress_cmp'] === 30 && $r1['progress_cur'] === 100, 'task 1: last week vs today');
ok($r1['change'] === 'In Progress → Completed' && $r1['changed'], 'task 1: change text');
ok($r1['completed_on'] === '2026-11-12', 'task 1: completed on');
ok(abs($r1['hours'] - 1.5) < 0.001, 'hours logged only inside the period (' . $r1['hours'] . ')');
ok(str_contains($r1['remarks'], 'WMTEST internal: last word'), 'admin remarks show the latest comment, internal included');
$r2 = $by[$t2id];
ok($r2['status_cmp'] === 'Not created' && $r2['progress_cmp'] === null && $r2['change'] === 'Not created → To Do', 'task created after the compare date');
ok($r2['overdue_cur'] && !$r2['overdue_cmp'], 'overdue today, not on the compare date');
ok($by[$t3id]['change'] === 'Not created → On Hold', 'task 3');
$counts = [];
foreach ($rep['summary']['counts'] as $cc) {
    $counts[$cc['status']] = [$cc['compare'], $cc['current']];
}
ok($counts['In Progress'] === [1, 0] && $counts['Completed'] === [0, 1] && $counts['Not created'] === [2, 0] && $counts['To Do'] === [0, 1], 'summary counts per status');
$pd = [];
foreach ($rep['summary']['per_dept'] as $p) {
    $pd[$p['department']] = $p;
}
ok($pd['WMTEST Design']['completed'] === 1 && $pd['WMTEST Web']['total'] === 2 && $pd['WMTEST Web']['overdue'] === 1, 'per-department totals');
// Same report as of the compare date alone: task 2 does not exist yet.
$early = wm_report($admin, ['compare' => '2026-11-01', 'current' => '2026-11-05', 'depts' => [$d1, $d2]]);
ok(count($early['rows']) === 1 && $early['rows'][0]['id'] === $t1id, 'tasks created after the current date are left out');
// Default dates: same day last week vs today.
$def = wm_report_filters($admin, []);
ok($def['current'] === '2026-11-12' && $def['compare'] === '2026-11-05', 'default dates');
ok(code(fn() => wm_report($admin, ['compare' => '2026-11-12', 'current' => '2026-11-05'])) === 400, 'compare after current refused');
// Status filter.
ok(count(wm_report($admin, ['compare' => '2026-11-05', 'current' => '2026-11-12', 'depts' => [$d1, $d2], 'status' => 'Completed'])['rows']) === 1, 'status filter');
// Admin's client filter = that client's scope.
$rx = wm_report($admin, ['compare' => '2026-11-05', 'current' => '2026-11-12', 'client' => CY]);
ok(array_column($rx['rows'], 'id') === [$t3id], 'admin client filter shows that client\'s scope');
// The client's own report.
$rc = wm_report($cx, ['compare' => '2026-11-05', 'current' => '2026-11-12', 'client' => CY]);
ok(array_column($rc['rows'], 'id') === [$t1id], 'a client\'s report is always their own scope');
ok($rc['rows'][0]['hours'] === null, 'client report carries no time logs');
ok(str_contains($rc['rows'][0]['remarks'], 'Looks great') && !str_contains($rc['rows'][0]['remarks'], 'internal'), 'client report remarks never show internal comments');
ok(code(fn() => wm_report($ta, ['depts' => [$d2]])) === 404, 'team member cannot report on another department (404)');
ok(wm_report_filename($rep) === 'Work-Report_2-departments_2026-11-12.xlsx', 'file name (' . wm_report_filename($rep) . ')');
ok(wm_report_filename(wm_report($admin, ['compare' => '2026-11-05', 'current' => '2026-11-12', 'depts' => [$d1]])) === 'Work-Report_WMTEST-Design_2026-11-12.xlsx', 'file name for one department');

/* ---- the .xlsx opens and its columns are right */
$tmp = tempnam(sys_get_temp_dir(), 'wmt') . '.xlsx';
wm_report_xlsx($rep, 'Administrator')->save($tmp);
$zip = new ZipArchive();
ok($zip->open($tmp) === true, 'xlsx is a valid zip');
$wbx = simplexml_load_string((string) $zip->getFromName('xl/workbook.xml'));
$names = [];
foreach ($wbx->sheets->sheet as $s) {
    $names[] = (string) $s['name'];
}
ok($names === ['Task Status', 'Summary'], 'two sheets: Task Status and Summary');
$sx = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
$sheet = simplexml_load_string($sx);
ok($sheet !== false, 'sheet 1 is well-formed XML');
$head = [];
foreach ($sheet->sheetData->row[0]->c as $cell) {
    $head[] = (string) $cell->is->t;
}
ok($head === WM_REPORT_COLUMNS, 'header row has the 17 columns in order');
ok(count($sheet->sheetData->row) === 4, 'one row per task plus the header');
ok(str_contains($sx, 'state="frozen"') && str_contains($sx, '<autoFilter ref="A1:Q4"/>'), 'frozen header and auto-filter');
ok(str_contains($sx, '<cols>') && str_contains($sx, 'width="46"'), 'column widths');
$st = (string) $zip->getFromName('xl/styles.xml');
ok(simplexml_load_string($st) !== false && str_contains($st, 'dd\-mmm\-yyyy') && str_contains($st, 'FF1D5FD0') && str_contains($st, 'wrapText="1"'), 'styles: date format, brand header fill, wrapped text');
ok(str_contains($st, 'FFD9F2E3') && str_contains($st, 'FFDCE9FB') && str_contains($st, 'FFFDEFD3') && str_contains($st, 'FFB3261E'), 'status fills and red overdue text');
$row2 = $sheet->sheetData->row[1];
$vals = [];
foreach ($row2->c as $cell) {
    $vals[] = isset($cell->is) ? (string) $cell->is->t : (string) $cell->v;
}
ok($vals[1] === (string) $t1['task_code'] && $vals[9] === 'In Progress' && $vals[10] === 'Completed' && $vals[13] === 'In Progress → Completed', 'first data row values');
ok($vals[8] === (string) WmXlsx::serial('2026-11-10'), 'due date stored as a real Excel date');
$sum = (string) $zip->getFromName('xl/worksheets/sheet2.xml');
ok(str_contains($sum, 'Work Status Report') && str_contains($sum, 'Generated by') && str_contains($sum, 'WMTEST Web'), 'summary sheet content');
$zip->close();
@unlink($tmp);
ok(WmXlsx::serial('2026-11-12') === 46338, 'Excel serial date');

/* ---- departments with tasks cannot be deleted */
ok(code(fn() => wm_dept_delete($admin, $d1)) === 409, 'department with tasks cannot be deleted');
$d3 = wm_dept_save($admin, ['name' => 'WMTEST Empty']);
wm_dept_delete($admin, (int) $d3['id']);
ok(!fabo_one('SELECT id FROM wm_departments WHERE id = ?', 'i', [(int) $d3['id']]), 'an empty department is deleted');
// Inactive department: no new tasks.
wm_dept_save($admin, ['id' => $d2, 'name' => 'WMTEST Web', 'is_active' => 0]);
ok(code(fn() => wm_task_save($admin, ['title' => 'WMTEST x', 'department_id' => $d2])) === 400, 'no new tasks in an inactive department');
wm_dept_save($admin, ['id' => $d2, 'name' => 'WMTEST Web', 'is_active' => 1]);

/* ---- validation */
ok(code(fn() => wm_task_save($admin, ['title' => '', 'department_id' => $d1])) === 400, 'title required');
ok(code(fn() => wm_task_save($admin, ['title' => 'WMTEST x', 'department_id' => $d1, 'status' => 'Done'])) === 400, 'unknown status refused');
ok(code(fn() => wm_task_save($admin, ['title' => 'WMTEST x', 'department_id' => $d1, 'progress' => 101])) === 400, 'progress over 100 refused');
ok(code(fn() => wm_task_save($admin, ['title' => 'WMTEST x', 'department_id' => $d1, 'due_date' => '2026-02-30'])) === 400, 'impossible date refused');
ok(code(fn() => wm_task_save($admin, ['title' => 'WMTEST x', 'department_id' => $d1, 'start_date' => '2026-11-10', 'due_date' => '2026-11-01'])) === 400, 'due before start refused');
ok(code(fn() => wm_task_save($admin, ['title' => 'WMTEST x', 'department_id' => $d1, 'assignee_id' => CX])) === 400, 'a client cannot be the assignee');

/* ---- soft delete */
wm_task_delete($admin, $t3id);
ok(code(fn() => wm_task_get($admin, $t3id)) === 404, 'a deleted task is gone (404)');
ok(fabo_one('SELECT deleted_at FROM wm_tasks WHERE id = ?', 'i', [$t3id])['deleted_at'] !== null, 'deleted softly');
ok(code(fn() => wm_task_get($cy, $t3id)) === 404, 'client no longer sees the deleted shared task');

/* ---- deactivate and revoke take effect at once */
wm_member_active($admin, TA, false);
ok(wm_ctx(viewer(TA)) === null, 'deactivated: no access on the next request');
ok(wm_members($admin)[0]['id'] !== 0, 'members list');
wm_member_active($admin, TA, true);
ok(wm_ctx(viewer(TA))['depts'] === [$d1], 'activated again with departments kept');
wm_member_active($admin, CX, false, true);
ok(wm_ctx(viewer(CX)) === null && !fabo_one('SELECT * FROM wm_user_department_access WHERE user_id = ?', 'i', [CX]), 'revoked: no access, departments cleared');
// A revoked portal account loses Work access too.
fabo_exec('UPDATE clients SET revoked_at = ? WHERE id = ?', 'si', [date('Y-m-d H:i:s'), TB]);
ok(wm_ctx(viewer(TB)) === null, 'revoked portal account has no Work access');

/* ---- audit */
$acts = array_column(fabo_all('SELECT action FROM wm_audit WHERE task_id IN (?,?,?) OR target LIKE ?', 'iiis', [$t1id, $t2id, $t3id, 'WMTEST %']), 'action');
foreach (['task_created', 'task_edited', 'status_changed', 'task_deleted', 'user_invited', 'access_changed', 'user_deactivated', 'access_revoked', 'department_created'] as $a) {
    ok(in_array($a, $acts, true), 'audit: ' . $a);
}

if ($mode === 'seed') {
    fabo_exec('UPDATE clients SET revoked_at = NULL WHERE id = ?', 'i', [TB]);
    wm_member_active($admin, CX, true);
    wm_member_update($admin, CX, ['depts' => [$d1], 'can_comment' => true]);
    echo "seeded: team A " . TA . " / wm-pass-" . TA . ", client X " . CX . " / wm-pass-" . CX . ", client Y " . CY . " / wm-pass-" . CY . "\n";
} else {
    cleanup();
}
unset($GLOBALS['wm_clock']);
echo "$pass passed, $failed failed\n";
exit($failed ? 1 : 0);

<?php
/**
 * Incubation Management — one incubation workspace: its lifecycle, team, the
 * startup's application and evaluations, tasks, meetings, notes, documents,
 * checklists, recommendations, progress and reassignment history.
 */

require_once __DIR__ . '/parts.php';

$id = (int) ($_GET['id'] ?? 0);
try {
    $ws = inc_workspace_need($id);
} catch (InvalidArgumentException $e) {
    fabo_deny($e->getMessage(), 404);
}
$appId = (int) $ws['application_id'];
$self = inc_url('workspace', ['id' => $id]);
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    $flash = inc_action(static function () use ($a, $id, $ws, $appId) {
        switch ($a) {
            case 'stage':
                inc_ws_move_stage($id, (int) ($_POST['stage_id'] ?? 0), trim((string) ($_POST['reason'] ?? '')));
                return 'Incubation stage changed.';
            case 'reassign':
                if (empty($_POST['confirm'])) {
                    throw new InvalidArgumentException('Tick the confirmation to reassign.');
                }
                $r = inc_assign($appId, (int) ($_POST['team_id'] ?? 0), (int) ($_POST['owner_id'] ?? 0), (string) ($_POST['reason'] ?? ''), (string) ($_POST['request_key'] ?? ''));
                return ['reassigned' => 'Reassigned. The incubation record, its stage and its history are kept.', 'unchanged' => 'Already with that team and owner — nothing changed.',
                        'repeat' => 'That reassignment was already recorded.', 'created' => 'Assigned.'][$r['result']];
            case 'target':
                inc_ws_set_target($id, (string) ($_POST['target_date'] ?? ''));
                return 'Target date saved.';
            case 'check':
                inc_ws_check($id, (int) ($_POST['item_id'] ?? 0), !empty($_POST['done']));
                return 'Checklist updated.';
            case 'meeting':
                inc_meeting_add($id, $_POST);
                return 'Meeting recorded.';
            case 'note':
                inc_note_add('workspace', $id, (string) ($_POST['body'] ?? ''), (string) ($_POST['kind'] ?? 'note'));
                return 'Note added.';
            case 'task':
                inc_task_add('workspace', $id, $_POST);
                return 'Task added.';
            case 'task_set':
                inc_task_set((int) ($_POST['task_id'] ?? 0), (string) ($_POST['status'] ?? ''));
                return 'Task updated.';
            case 'file':
                if (empty($_FILES['upload']) || ($_FILES['upload']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    throw new InvalidArgumentException('Choose a file.');
                }
                inc_file_add('workspace', $id, $_FILES['upload'], (string) ($_POST['label'] ?? ''));
                return 'Document added.';
            case 'retry':
                [, $msg] = inc_notify_retry((int) ($_POST['log_id'] ?? 0));
                return $msg;
        }
        throw new InvalidArgumentException('Unknown action.');
    }, $self);
    $ws = inc_workspace($id);
}

$app = inc_app($appId);
$stage = inc_stage((int) $ws['stage_id']);
$prog = inc_ws_progress($id);
$prev = inc_ws_previous_stage($id);
$name = $ws['startup_name'] ?: $ws['applicant_name'];
inc_layout_start($name . ' · ' . $ws['ref'], 'Incubation record for ' . e($ws['app_ref']) . ' · created ' . e(inc_fmt_date($ws['created_at'])) . ($ws['closed_at'] ? ' · closed ' . e(inc_fmt_date($ws['closed_at'])) : ''),
    ['Incubations' => inc_url('workspaces'), $ws['ref'] => null],
    '<a class="btn" href="' . e(inc_url('application', ['id' => $appId])) . '">Application ' . e($ws['app_ref']) . '</a>', $flash);

echo '<div class="inc-states">'
   . '<div><span>Incubation stage</span><strong>' . e($ws['stage_name']) . '</strong><small>since ' . e(inc_fmt_date($ws['stage_since'])) . '</small></div>'
   . '<div><span>Team · owner</span><strong>' . e(inc_team_name((int) $ws['team_id'])) . '</strong><small>' . e(inc_person_name((int) $ws['owner_id'])) . '</small></div>'
   . '<div><span>Development stage</span><strong>' . e($ws['dev_stage'] ? (inc_dev_stages()[$ws['dev_stage']] ?? '') : 'Not set') . '</strong></div>'
   . '<div><span>Application pipeline</span><strong>' . e($app['stage_name']) . '</strong></div>'
   . '<div><span>Official score</span><strong>' . inc_score_html($app) . '</strong></div>'
   . '<div><span>Uniqueness</span><strong>' . inc_uniq_html($app['uniqueness_rating']) . '</strong></div>'
   . '<div><span>Progress</span><strong>' . (int) $prog['pct'] . '%</strong><small>' . $prog['checks'][0] . '/' . $prog['checks'][1] . ' checks · ' . $prog['tasks'][0] . '/' . $prog['tasks'][1] . ' tasks · '
   . $prog['recs'][0] . '/' . $prog['recs'][1] . ' actions</small></div>'
   . '</div>';

echo '<div class="inc-grid2 wide-left"><div>';
/* Checklist of the current stage. */
$items = inc_checklist((int) $ws['stage_id']);
$checks = inc_ws_checks($id);
echo '<section class="card"><h2>' . e($ws['stage_name']) . ' checklist</h2>';
if (!$items) {
    echo '<p class="muted small">No checklist for this stage. Add items under Settings → Lifecycle.</p>';
}
foreach ($items as $it) {
    $done = isset($checks[(int) $it['id']]);
    echo '<div class="inc-check">' . inc_button($done ? '☑' : '☐', ['action' => 'check', 'item_id' => $it['id'], 'done' => $done ? '' : '1'], 'btn btn-sm inc-tick' . ($done ? ' on' : ''))
       . ' <span' . ($done ? ' class="done"' : '') . '>' . e($it['text']) . '</span>' . ($done ? ' <span class="muted small">' . e($checks[(int) $it['id']]['done_by']) . ' · ' . e(inc_fmt_date($checks[(int) $it['id']]['done_at'])) . '</span>' : '') . '</div>';
}
echo '</section>';

echo '<section class="card"><h2>Tasks and follow-ups</h2>' . inc_tasks_html(inc_all('SELECT * FROM inc_tasks WHERE (entity = "workspace" AND entity_id = ?) OR application_id = ?
     ORDER BY status = "open" DESC, due_date IS NULL, due_date, id', 'ii', [$id, $appId])) . inc_task_form() . '</section>';

echo '<section class="card"><h2>Meetings</h2>';
foreach (inc_meetings($id) as $m) {
    echo '<details class="inc-meeting"><summary><strong>' . e($m['title']) . '</strong> <span class="muted small">' . e(inc_fmt_date($m['met_on'])) . '</span></summary>'
       . ($m['attendees'] ? '<p class="small"><strong>Attendees:</strong> ' . e($m['attendees']) . '</p>' : '')
       . ($m['notes'] ? '<p class="small">' . nl2br(e($m['notes'])) . '</p>' : '') . ($m['next_steps'] ? '<p class="small"><strong>Next steps:</strong> ' . nl2br(e($m['next_steps'])) . '</p>' : '') . '</details>';
}
echo '<details><summary class="btn btn-sm">Record a meeting</summary>' . inc_form_open() . '<input type="hidden" name="action" value="meeting">'
   . '<div class="inc-row">' . inc_field('Title', '<input type="text" name="title" maxlength="190" required>', '', true) . inc_field('Date', '<input type="date" name="met_on" required value="' . e(date('Y-m-d')) . '">', '', true) . '</div>'
   . inc_field('Attendees', '<input type="text" name="attendees" maxlength="500">') . inc_field('Notes', inc_textarea('notes', '', 3)) . inc_field('Next steps', inc_textarea('next_steps', '', 2))
   . '<button class="btn btn-sm btn-primary">Save meeting</button></form></details></section>';

echo '<section class="card"><h2>Notes and comments</h2>' . inc_form_open() . '<input type="hidden" name="action" value="note">'
   . inc_select('kind', ['note' => 'Internal note', 'comment' => 'Comment', 'call' => 'Call log', 'email' => 'Email log'], 'note') . inc_textarea('body', '', 3, 'required')
   . ' <button class="btn btn-sm btn-primary">Add</button></form>';
foreach (inc_notes('workspace', $id) as $n) {
    echo '<div class="inc-note-item"><span class="muted small">' . e(ucfirst($n['kind'])) . ' · ' . e($n['actor']) . ' · ' . e(inc_fmt_datetime($n['created_at'])) . '</span><div>' . nl2br(e($n['body'])) . '</div></div>';
}
echo '</section>';

echo '<section class="card"><h2>Documents</h2>' . inc_files_html(array_merge(inc_files('workspace', $id), inc_files('application', $appId)))
   . inc_form_open('', true) . '<input type="hidden" name="action" value="file"><input type="file" name="upload" required> <input type="text" name="label" placeholder="Label (optional)"> <button class="btn btn-sm">Upload</button></form></section>';

echo '<section class="card"><h2>Activity and email history</h2>' . inc_timeline_html(inc_timeline($appId, $id));
$log = inc_all('SELECT * FROM inc_notify_log WHERE workspace_id = ? OR application_id = ? ORDER BY created_at DESC, id DESC LIMIT 50', 'ii', [$id, $appId]);
if ($log) {
    echo '<h3>Notifications sent</h3><ul class="small inc-log">';
    foreach ($log as $l) {
        echo '<li>' . e(inc_fmt_datetime($l['created_at'])) . ' · ' . e($l['channel']) . ' to ' . e($l['recipient_id'] ? inc_person_name((int) $l['recipient_id']) : 'administrator')
           . ' · ' . e($l['subject']) . ' · ' . inc_badge($l['status'], ['sent' => 'green', 'failed' => 'red', 'queued' => 'amber'][$l['status']] ?? '')
           . ($l['channel'] === 'email' && in_array($l['status'], ['failed', 'queued'], true) ? ' ' . inc_button('Retry', ['action' => 'retry', 'log_id' => $l['id']]) : '') . '</li>';
    }
    echo '</ul>';
}
echo '</section></div><div>';

echo '<section class="card"><h2>Incubation stage</h2>' . inc_form_open() . '<input type="hidden" name="action" value="stage">'
   . inc_field('Move to', inc_select('stage_id', inc_stage_map('lifecycle', true), $ws['stage_id']))
   . inc_field('Reason', '<input type="text" name="reason" maxlength="500">', 'Some stages require a reason.')
   . '<button class="btn btn-primary">Change stage</button></form>'
   . ($prev ? '<p class="small">Previous stage: ' . e($prev['name']) . ' ' . inc_button('Return to it', ['action' => 'stage', 'stage_id' => $prev['id'], 'reason' => 'Returned to the previous stage'], 'btn btn-sm') . '</p>' : '')
   . '<p class="muted small">Only the incubation stage changes; the application pipeline and the development stage are not affected.</p></section>';

echo '<section class="card"><h2>Reassign</h2><p class="muted small">Keeps this incubation record, its stage and all history. The new owner is notified' . (inc_setting('notify_prev_owner') === '1' ? ', and so is the previous one' : '') . '.</p>'
   . inc_form_open() . '<input type="hidden" name="action" value="reassign"><input type="hidden" name="request_key" value="' . e(bin2hex(random_bytes(12))) . '">'
   . inc_field('Team', inc_select('team_id', inc_teams(), $ws['team_id'], '', 'required')) . inc_field('Owner', inc_select('owner_id', inc_people_with_role(), $ws['owner_id'], '', 'required'))
   . inc_field('Reason', '<input type="text" name="reason" maxlength="500">')
   . '<label class="check"><input type="checkbox" name="confirm" value="1" required> Confirm reassignment</label> <button class="btn">Reassign</button></form></section>';

echo '<section class="card"><h2>Target date</h2>' . inc_form_open('class="inc-row"') . '<input type="hidden" name="action" value="target">'
   . '<input type="date" name="target_date" value="' . e((string) $ws['target_date']) . '"> <button class="btn btn-sm">Save</button></form></section>';

$evals = inc_evals_for_app($appId);
echo '<section class="card"><h2>Evaluations</h2>';
if (!$evals) {
    echo '<p class="muted small">Not Evaluated.</p>';
}
foreach ($evals as $e) {
    echo '<div class="small"><a href="' . e(inc_url('evaluation', ['id' => $e['id']])) . '">#' . (int) $e['id'] . '</a> · ' . e(inc_dev_stages()[$e['dev_stage']] ?? '') . ' · ' . inc_eval_badge($e['status'])
       . ($e['status'] === 'approved' ? ' · ' . e(inc_fmt_score($e['score'])) . '/100' : '') . ' · ' . e(inc_fmt_date($e['created_at'])) . '</div>';
}
echo '<a class="btn btn-sm" href="' . e(inc_url('application', ['id' => $appId, 'tab' => 'evaluation'])) . '">Evaluations and recommendations</a></section>';

$recs = inc_recs_for_app($appId);
echo '<section class="card"><h2>Recommendations and target dates</h2>';
if (!$recs) {
    echo '<p class="muted small">None yet.</p>';
}
foreach ($recs as $r) {
    echo '<div class="small">' . inc_badge(str_replace('_', ' ', $r['status'])) . ' ' . e(mb_strimwidth($r['text'], 0, 120, '…')) . ($r['due_date'] ? ' <span class="muted">due ' . e(inc_fmt_date($r['due_date'])) . '</span>' : '') . '</div>';
}
echo '</section>';

$as = inc_assignments_for($id);
echo '<section class="card"><h2>Assignment history</h2><ul class="small">';
foreach ($as as $x) {
    echo '<li>' . e(inc_fmt_datetime($x['created_at'])) . ' · ' . ($x['kind'] === 'assign' ? 'Assigned to ' : 'Reassigned Application: ' . e(inc_person_name((int) $x['prev_owner_id'])) . ' → ')
       . e(inc_person_name((int) $x['owner_id'])) . ' (' . e(inc_team_name((int) $x['team_id'])) . ') at "' . e((string) (inc_stage((int) $x['stage_id_at'])['name'] ?? '')) . '"'
       . ($x['reason'] ? ' · ' . e($x['reason']) : '') . ' · ' . e($x['actor']) . '</li>';
}
echo '</ul><h3>Lifecycle history</h3><ul class="small">';
foreach (inc_stage_history('lifecycle', $id) as $h) {
    echo '<li>' . e(inc_fmt_datetime($h['created_at'])) . ': ' . e($h['from_name'] ?? 'start') . ' → ' . e($h['to_name']) . ($h['reason'] ? ' · ' . e($h['reason']) : '') . ' · ' . e($h['actor']) . '</li>';
}
echo '</ul></section>';
echo '</div></div>';
inc_layout_end();

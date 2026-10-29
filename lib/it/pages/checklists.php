<?php
/**
 * Admin & Facilities — housekeeping. Tasks by area that repeat daily, weekly
 * (on a weekday) or monthly (on a date); each day's list shows what is due,
 * who did it and when, and the supervisor's check. Tasks are set up below.
 */

$day = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['day'] ?? '')) ? (string) $_GET['day'] : date('Y-m-d');
$self = it_url('checklists', ['day' => $day]);
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    $flash = inc_action(static function () use ($a, $day) {
        $tid = (int) ($_POST['task_id'] ?? 0);
        switch ($a) {
            case 'done':
                $who = inc_int_or_null($_POST['person_id'] ?? null);
                $name = $who ? (string) (it_one('SELECT name FROM it_people WHERE id = ?', 'i', [$who])['name'] ?? '') : it_actor();
                it_exec('INSERT INTO it_task_log (task_id, day, done_by, done_at, note) VALUES (?,?,?,NOW(),?)
                         ON DUPLICATE KEY UPDATE done_by = VALUES(done_by), done_at = NOW(), note = VALUES(note)', 'isss',
                        [$tid, $day, $name, mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 255) ?: null]);
                return 'Marked done.';
            case 'verify':
                it_exec('UPDATE it_task_log SET verified_by = ?, verified_at = NOW() WHERE task_id = ? AND day = ? AND done_at IS NOT NULL', 'sis', [it_actor(), $tid, $day]);
                return 'Checked.';
            case 'undo':
                it_exec('DELETE FROM it_task_log WHERE task_id = ? AND day = ?', 'is', [$tid, $day]);
                return 'Undone.';
            case 'task_save':
                $freq = in_array($_POST['frequency'] ?? '', ['daily', 'weekly', 'monthly'], true) ? $_POST['frequency'] : 'daily';
                $row = [inc_str($_POST['area'] ?? '', 120) ?: null, inc_str($_POST['task'] ?? '', 255, true, 'Task'), $freq,
                        $freq === 'weekly' ? max(1, min(7, (int) ($_POST['weekday'] ?? 1))) : null, $freq === 'monthly' ? max(1, min(31, (int) ($_POST['monthday'] ?? 1))) : null,
                        inc_int_or_null($_POST['person_id'] ?? null)];
                if ($tid) {
                    it_exec('UPDATE it_tasks SET area = ?, task = ?, frequency = ?, weekday = ?, monthday = ?, person_id = ? WHERE id = ?', 'sssiiii', array_merge($row, [$tid]));
                    return 'Task saved.';
                }
                it_exec('INSERT INTO it_tasks (area, task, frequency, weekday, monthday, person_id) VALUES (?,?,?,?,?,?)', 'sssiii', $row);
                return 'Task added.';
            case 'task_toggle':
                it_exec('UPDATE it_tasks SET active = 1 - active WHERE id = ?', 'i', [$tid]);
                return 'Task updated.';
        }
        throw new InvalidArgumentException('Unknown action.');
    }, $self);
}

$ts = strtotime($day);
$due = it_all('SELECT t.*, p.name AS person_name, l.done_by, l.done_at, l.verified_by, l.verified_at, l.note
               FROM it_tasks t LEFT JOIN it_people p ON p.id = t.person_id LEFT JOIN it_task_log l ON l.task_id = t.id AND l.day = ?
               WHERE t.active = 1 AND (t.frequency = "daily" OR (t.frequency = "weekly" AND t.weekday = ?) OR (t.frequency = "monthly" AND (t.monthday = ? OR (t.monthday > ? AND ? = ?))))
               ORDER BY t.area, t.sort, t.id', 'siiiii', [$day, (int) date('N', $ts), (int) date('j', $ts), (int) date('t', $ts), (int) date('j', $ts), (int) date('t', $ts)]);
$done = count(array_filter($due, static fn($t) => $t['done_at']));
$people = array_column(it_all('SELECT id, name FROM it_people WHERE active = 1 ORDER BY name'), 'name', 'id');

$nav = '<a class="btn" href="' . e(it_url('checklists', ['day' => date('Y-m-d', strtotime($day . ' -1 day'))])) . '">← Previous day</a> '
     . ($day !== date('Y-m-d') ? '<a class="btn" href="' . e(it_url('checklists')) . '">Today</a> ' : '')
     . '<a class="btn" href="' . e(it_url('checklists', ['day' => date('Y-m-d', strtotime($day . ' +1 day'))])) . '">Next day →</a>';
it_layout_start('Housekeeping', '<strong>' . e(date('l, d M Y', $ts)) . '</strong> · ' . $done . ' of ' . count($due) . ' tasks done', $nav, $flash);

if ($due) {
    $pct = (int) round($done * 100 / count($due));
    echo '<div class="kb-progress" style="height:8px;margin-bottom:14px" title="' . $pct . '% done"><i style="width:' . $pct . '%"></i></div>';
}
$byArea = [];
foreach ($due as $t) {
    $byArea[$t['area'] ?: 'General'][] = $t;
}
if (!$due) {
    echo inc_empty('Nothing due on this day.', 'Set up cleaning tasks below; daily ones show every day.');
}
echo '<div class="inc-grid2">';
foreach ($byArea as $area => $tasks) {
    echo '<section class="card"><h2>' . e($area) . '</h2><ul class="it-tasks">';
    foreach ($tasks as $t) {
        echo '<li class="' . ($t['done_at'] ? 'done' : '') . '"><div><strong>' . e($t['task']) . '</strong><div class="muted small">' . e(ucfirst($t['frequency']))
           . ($t['person_name'] ? ' · ' . e($t['person_name']) : '') . ($t['done_at'] ? ' · done by ' . e((string) $t['done_by']) . ' at ' . e(date('H:i', strtotime($t['done_at']))) : '')
           . ($t['verified_at'] ? ' · checked by ' . e((string) $t['verified_by']) : '') . ($t['note'] ? ' · ' . e($t['note']) : '') . '</div></div><div class="it-taskacts">';
        if (!$t['done_at']) {
            echo inc_form_open('class="inc-inline"') . '<input type="hidden" name="action" value="done"><input type="hidden" name="task_id" value="' . (int) $t['id'] . '">'
               . inc_select('person_id', $people, $t['person_id'], 'Me') . ' <button class="btn btn-sm btn-primary">Done</button></form>';
        } else {
            echo ($t['verified_at'] ? inc_badge('Checked', 'green') : inc_button('Check', ['action' => 'verify', 'task_id' => $t['id']], 'btn btn-sm'))
               . ' ' . inc_button('Undo', ['action' => 'undo', 'task_id' => $t['id']], 'linkbtn');
        }
        echo '</div></li>';
    }
    echo '</ul></section>';
}
echo '</div>';

// Setting up the tasks.
$days = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
$all = it_all('SELECT t.*, p.name AS person_name FROM it_tasks t LEFT JOIN it_people p ON p.id = t.person_id ORDER BY t.active DESC, t.area, t.sort, t.id');
$form = static function (?array $t) use ($people, $days): string {
    return inc_form_open('class="inc-row it-taskform"') . '<input type="hidden" name="action" value="task_save">' . ($t ? '<input type="hidden" name="task_id" value="' . (int) $t['id'] . '">' : '')
         . inc_select('area', it_list('area') + ($t && $t['area'] ? [$t['area'] => $t['area']] : []), $t['area'] ?? '', 'Area…')
         . '<input type="text" name="task" maxlength="255" required placeholder="e.g. Clean and restock washrooms" value="' . e($t['task'] ?? '') . '" class="grow">'
         . inc_select('frequency', ['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'], $t['frequency'] ?? 'daily')
         . inc_select('weekday', $days, $t['weekday'] ?? 1) . '<input type="number" name="monthday" min="1" max="31" value="' . e((string) ($t['monthday'] ?? 1)) . '" title="Day of the month" class="inc-w">'
         . inc_select('person_id', $people, $t['person_id'] ?? '', 'Anyone') . '<button class="btn btn-sm' . ($t ? '' : ' btn-primary') . '">' . ($t ? 'Save' : 'Add task') . '</button></form>';
};
echo '<section class="card"><h2>Cleaning tasks</h2><p class="muted small">Weekly tasks use the weekday; monthly tasks use the day of the month (the 31st means the last day).</p>' . $form(null);
foreach ($all as $t) {
    echo '<details class="it-taskrow' . ((int) $t['active'] !== 1 ? ' off' : '') . '"><summary>' . e(($t['area'] ? $t['area'] . ' — ' : '') . $t['task']) . ' <span class="muted small">'
       . e(ucfirst($t['frequency']) . ($t['frequency'] === 'weekly' ? ', ' . $days[(int) $t['weekday']] : ($t['frequency'] === 'monthly' ? ', day ' . (int) $t['monthday'] : '')))
       . ($t['person_name'] ? ' · ' . e($t['person_name']) : '') . '</span>' . ((int) $t['active'] !== 1 ? ' ' . inc_badge('Paused') : '') . '</summary>'
       . $form($t) . inc_button((int) $t['active'] === 1 ? 'Pause this task' : 'Resume this task', ['action' => 'task_toggle', 'task_id' => $t['id']], 'linkbtn') . '</details>';
}
echo '</section>';

it_layout_end();

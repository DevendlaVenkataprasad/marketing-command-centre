<?php
/**
 * Admin & Facilities — duty and attendance. One sheet per day: every active
 * person with their shift and post (filled from their usual ones), present /
 * absent / leave / replaced / off, and in and out times. Saved in one go.
 * Below, the month at a glance.
 */

$day = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['day'] ?? '')) ? (string) $_GET['day'] : date('Y-m-d');
$month = substr($day, 0, 7);
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $flash = inc_action(static function () use ($day) {
        $n = 0;
        foreach ((array) ($_POST['st'] ?? []) as $pid => $status) {
            $pid = (int) $pid;
            if (!in_array($status, ['present', 'absent', 'leave', 'replaced', 'off'], true)) {
                if ($status === '') {
                    it_exec('DELETE FROM it_duty WHERE person_id = ? AND day = ?', 'is', [$pid, $day]);
                }
                continue;
            }
            $t = static fn($v) => preg_match('/^\d{2}:\d{2}$/', (string) $v) ? $v . ':00' : null;
            it_exec('INSERT INTO it_duty (person_id, day, shift, post, status, in_time, out_time, note) VALUES (?,?,?,?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE shift = VALUES(shift), post = VALUES(post), status = VALUES(status), in_time = VALUES(in_time), out_time = VALUES(out_time), note = VALUES(note)',
                    'isssssss', [$pid, $day, mb_substr((string) ($_POST['shift'][$pid] ?? ''), 0, 120) ?: null, mb_substr((string) ($_POST['post'][$pid] ?? ''), 0, 120) ?: null, $status,
                                 $t($_POST['in'][$pid] ?? ''), $t($_POST['out'][$pid] ?? ''), mb_substr(trim((string) ($_POST['note'][$pid] ?? '')), 0, 255) ?: null]);
            $n++;
        }
        it_log('duty', null, 'Duty for ' . $day . ' saved (' . $n . ' people).');
        return 'Saved duty for ' . date('d M Y', strtotime($day)) . ' — ' . $n . ' marked.';
    }, it_url('duty', ['day' => $day]));
}

$people = it_all('SELECT p.*, v.name AS agency_name, d.shift AS d_shift, d.post AS d_post, d.status, d.in_time, d.out_time, d.note
                  FROM it_people p LEFT JOIN it_vendors v ON v.id = p.agency_id LEFT JOIN it_duty d ON d.person_id = p.id AND d.day = ?
                  WHERE p.active = 1 OR d.id IS NOT NULL ORDER BY p.role, p.name', 's', [$day]);
$counts = array_count_values(array_map(static fn($r) => $r['status'] ?: 'unmarked', $people));
$statuses = ['present' => 'Present', 'absent' => 'Absent', 'leave' => 'On leave', 'replaced' => 'Replaced by agency', 'off' => 'Weekly off'];

$nav = '<a class="btn" href="' . e(it_url('duty', ['day' => date('Y-m-d', strtotime($day . ' -1 day'))])) . '">← Previous day</a> '
     . ($day !== date('Y-m-d') ? '<a class="btn" href="' . e(it_url('duty')) . '">Today</a> ' : '')
     . '<a class="btn" href="' . e(it_url('duty', ['day' => date('Y-m-d', strtotime($day . ' +1 day'))])) . '">Next day →</a>';
it_layout_start('Duty & attendance', '<strong>' . e(date('l, d M Y', strtotime($day))) . '</strong> · ' . (int) ($counts['present'] ?? 0) . ' present · ' . (int) ($counts['absent'] ?? 0) . ' absent · '
                . (int) ($counts['unmarked'] ?? 0) . ' not marked', $nav, $flash);

if (!$people) {
    echo inc_empty('No support staff yet.', 'Add them under <a href="' . e(it_url('staff')) . '">Support staff</a> first.');
    it_layout_end();
    return;
}

echo '<form method="get" class="inc-row" style="margin-bottom:12px"><input type="hidden" name="p" value="duty"><label class="small">Go to <input type="date" name="day" value="' . e($day) . '" data-autosubmit></label></form>';
echo inc_form_open() . '<input type="hidden" name="action" value="save"><div class="scrollx"><table class="data stack inc-table it-duty"><thead><tr>'
   . '<th>Person</th><th>Shift</th><th>Post</th><th>Status</th><th>In</th><th>Out</th><th>Note</th></tr></thead><tbody>';
foreach ($people as $r) {
    $id = (int) $r['id'];
    $tm = static fn($v) => $v ? substr((string) $v, 0, 5) : '';
    echo '<tr><td data-l="Person"><strong>' . e($r['name']) . '</strong><div class="muted small">' . e($r['role'] ?: '') . ($r['agency_name'] ? ' · ' . e($r['agency_name']) : '') . '</div></td>'
       . '<td data-l="Shift">' . inc_select('shift[' . $id . ']', it_list('shift') + ($r['d_shift'] ? [$r['d_shift'] => $r['d_shift']] : []), $r['d_shift'] ?? $r['shift'], '—') . '</td>'
       . '<td data-l="Post">' . inc_select('post[' . $id . ']', it_list('area') + it_list('location') + ($r['d_post'] ? [$r['d_post'] => $r['d_post']] : []), $r['d_post'] ?? $r['post'], '—') . '</td>'
       . '<td data-l="Status">' . inc_select('st[' . $id . ']', $statuses, $r['status'] ?? '', 'Not marked') . '</td>'
       . '<td data-l="In"><input type="time" name="in[' . $id . ']" value="' . e($tm($r['in_time'])) . '"></td>'
       . '<td data-l="Out"><input type="time" name="out[' . $id . ']" value="' . e($tm($r['out_time'])) . '"></td>'
       . '<td data-l="Note"><input type="text" name="note[' . $id . ']" maxlength="255" value="' . e((string) $r['note']) . '" placeholder="e.g. replaced by Ravi"></td></tr>';
}
echo '</tbody></table></div><div class="inc-row"><button class="btn btn-primary">Save duty for ' . e(date('d M', strtotime($day))) . '</button>'
   . '<button type="button" class="btn" data-allpresent>Mark everyone not marked as present</button></div></form>';

// The month at a glance.
$from = $month . '-01';
$to = date('Y-m-t', strtotime($from));
$sum = it_all("SELECT p.id, p.name, p.role, SUM(d.status = 'present') AS present, SUM(d.status = 'absent') AS absent, SUM(d.status = 'leave') AS onleave,
                      SUM(d.status = 'replaced') AS replaced, SUM(d.status = 'off') AS off
               FROM it_people p JOIN it_duty d ON d.person_id = p.id AND d.day BETWEEN ? AND ? GROUP BY p.id, p.name, p.role ORDER BY p.role, p.name", 'ss', [$from, $to]);
echo '<section class="card" style="margin-top:18px"><h2>' . e(date('F Y', strtotime($from))) . ' at a glance</h2>';
if (!$sum) {
    echo '<p class="muted small">Nothing marked this month yet.</p>';
} else {
    echo '<div class="scrollx"><table class="data stack"><thead><tr><th>Person</th><th class="num">Present</th><th class="num">Absent</th><th class="num">Leave</th><th class="num">Replaced</th><th class="num">Off</th></tr></thead><tbody>';
    foreach ($sum as $s) {
        echo '<tr><td data-l="Person">' . e($s['name']) . ' <span class="muted small">' . e((string) $s['role']) . '</span></td><td data-l="Present" class="num">' . (int) $s['present'] . '</td>'
           . '<td data-l="Absent" class="num' . ((int) $s['absent'] ? ' tone-risk' : '') . '">' . (int) $s['absent'] . '</td><td data-l="Leave" class="num">' . (int) $s['onleave'] . '</td>'
           . '<td data-l="Replaced" class="num">' . (int) $s['replaced'] . '</td><td data-l="Off" class="num">' . (int) $s['off'] . '</td></tr>';
    }
    echo '</tbody></table></div>';
}
echo '</section>';

it_layout_end();

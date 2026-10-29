<?php
/**
 * My HR — an employee's own page, and their team's when they hold a team
 * role. Every block appears only when the role that powers it is granted,
 * and the ones about things that may not exist yet (an onboarding list,
 * assets, claims) only when there is something to show.
 */

require_once __DIR__ . '/leave_parts.php';

$me = hr_self_emp();
$id = (int) $me['id'];
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    if ($a === 'punch') {
        hr_need_emp($id, 'attendance', 'create');
        $flash = hr_action(static function () use ($id) { return hr_attendance_punch($id, ($_POST['what'] ?? '') === 'out' ? 'out' : 'in'); }, hr_url('me'));
    }
}

$emp = hr_employee_full($id);
$today = date('Y-m-d');
$month = date('Y-m');
hr_layout_start('My HR', 'Hello, ' . e(strtok($emp['full_name'], ' ')) . '. Everything here is your own information.', []);
echo hr_flash_html($flash);

echo '<div class="card"><div class="hr-me-top">'
   . ($emp['photo_file'] ? '<img src="' . e(hr_url('file', ['kind' => 'photo', 'id' => $id, 'v' => substr(md5($emp['photo_file']), 0, 6)])) . '" alt="">'
                         : '<span class="ph" aria-hidden="true">' . e(mb_strtoupper(mb_substr($emp['full_name'], 0, 1))) . '</span>')
   . '<div class="who"><h2>' . e($emp['full_name']) . '</h2><div class="muted">' . e($emp['emp_code']) . ' · ' . e($emp['designation'] ?? '') . ($emp['department'] ? ' · ' . e($emp['department']) : '')
   . ($emp['manager'] ? ' · reports to ' . e($emp['manager']) : '') . '</div></div><div class="hr-acts">';
if (hr_can('employees', 'view')) {
    echo '<a class="btn btn-sm" href="' . e(hr_url('employee', ['id' => $id])) . '">My profile</a>';
}
if (hr_emp_allowed($id, 'employees', 'edit')) {
    echo '<a class="btn btn-sm" href="' . e(hr_url('employee', ['id' => $id, 'edit' => 1])) . '">Update my details</a>';
}
echo '</div></div>';

if (hr_can('attendance', 'create') && hr_setting('att_self_checkin') === '1') {
    $rec = hr_attendance_row($id, $today);
    echo '<div class="hr-punch" style="margin-top:14px"><strong>Today:</strong> '
       . ($rec && $rec['check_in'] ? 'checked in at ' . e(date('H:i', strtotime($rec['check_in']))) : 'not checked in')
       . ($rec && $rec['check_out'] ? ', out at ' . e(date('H:i', strtotime($rec['check_out']))) : '') . ' ';
    if (!$rec || !$rec['check_in']) {
        echo hr_button_form('Check in now', ['action' => 'punch', 'what' => 'in'], 'btn btn-primary');
    } elseif (!$rec['check_out']) {
        echo hr_button_form('Check out now', ['action' => 'punch', 'what' => 'out'], 'btn btn-primary');
    }
    echo '</div>';
}

/* What can I do here? One tap each. */
$quick = [];
if (hr_can('leave', 'create')) { $quick[] = ['Apply for leave', '#apply', '⟶']; }
if (hr_can('claims', 'create')) { $quick[] = ['Claim an expense', hr_url('claims', ['tab' => 'mine']), '₹']; }
if (hr_can('helpdesk', 'create')) { $quick[] = ['Ask HR', hr_url('helpdesk', ['tab' => 'queries']), '?']; }
if (hr_can('payroll', 'view')) { $quick[] = ['My payslips', '#payslips', '▤']; }
if (hr_can('attendance', 'create')) { $quick[] = ['Fix my attendance', hr_url('attendance', ['tab' => 'corrections']), '✓']; }
if (hr_can('training', 'view')) { $quick[] = ['My training', hr_url('training', ['tab' => 'mine']), '◆']; }
if (hr_can('performance', 'view')) { $quick[] = ['My review', hr_url('performance', ['tab' => 'mine']), '★']; }
if (hr_can('holidays', 'view')) { $quick[] = ['Holiday list', hr_url('holidays'), '☀']; }
if ($quick) {
    echo '<div class="hr-quick hr-quick-row" style="margin-top:14px"><div class="grid">';
    foreach ($quick as [$label, $url, $icon]) {
        echo '<a href="' . e($url) . '"><span class="ic" aria-hidden="true">' . e($icon) . '</span>' . e($label) . '</a>';
    }
    echo '</div></div>';
}
echo '</div>';

$attn = hr_attention();
$notes = hr_can('helpdesk', 'view') ? hr_announcements_live($emp, 3) : [];
if ($attn || $notes) {
    echo '<div class="hr-top">' . ($attn ? hr_attention_html('Waiting on you') : '');
    if ($notes) {
        echo '<div class="card"><h2>Announcements</h2>';
        foreach ($notes as $a) {
            echo '<div class="hr-notice-mini' . ($a['pinned'] ? ' pinned' : '') . '"><strong>' . ($a['pinned'] ? '📌 ' : '') . e($a['title']) . '</strong>'
               . '<div class="small">' . e(mb_strimwidth($a['body'], 0, 220, '…')) . '</div></div>';
        }
        echo '<p><a class="btn btn-sm" href="' . e(hr_url('helpdesk')) . '">All announcements</a></p></div>';
    }
    echo '</div>';
}

/* Onboarding or an exit in progress comes first: it is what this person is in the middle of. */
if (hr_can('lifecycle', 'view')) {
    foreach (['onboarding' => 'Your onboarding', 'offboarding' => 'Your exit checklist'] as $k => $t) {
        [$d, $n] = hr_task_progress($id, $k);
        if ($n && $d < $n) {
            $mineOpen = array_filter(hr_tasks_for($id, $k), static fn($x) => $x['status'] === 'pending' && $x['owner'] === 'employee');
            echo '<div class="card"><h2>' . e($t) . '</h2><p class="mt0">' . hr_progress_html($d, $n) . '</p>'
               . ($mineOpen ? '<p class="mt0">' . count($mineOpen) . ' task(s) are yours to do.</p>' : '')
               . '<a class="btn btn-sm btn-primary" href="' . e(hr_url('lifecycle', ['tab' => 'mine'])) . '">Open</a></div>';
        }
    }
}

if (hr_can('attendance', 'view')) {
    $grid = hr_att_grid([$emp], $month . '-01', $today)[$id] ?? [];
    $t = hr_att_totals($grid);
    echo '<h2 class="hr-group-h">This month — ' . e(date('F Y')) . '</h2><div class="tiles">'
       . hr_tile('Present days', hr_fmt_days($t['present']), 'green', '', hr_url('attendance', ['tab' => 'monthly']))
       . hr_tile('Absent days', hr_fmt_days($t['absent']), $t['absent'] ? 'red' : '')
       . hr_tile('Leave taken', hr_fmt_days($t['leave']))
       . hr_tile('Late arrivals', (string) $t['late'], $t['late'] ? 'amber' : '')
       . hr_tile('Hours worked', e(hr_fmt_minutes($t['worked'])))
       . hr_tile('Overtime', e(hr_fmt_minutes($t['ot']))) . '</div>';
}

if (hr_can('leave', 'view')) {
    echo '<h2 class="hr-group-h">My leave — ' . e(date('F Y')) . ' and the year ' . e(hr_leave_year_label(hr_leave_year_of($today))) . '</h2>';
    hr_render_month_and_year($emp, hr_leave_year_of($today));
    if (hr_can('leave', 'create')) {
        echo '<details class="card" id="apply"><summary><strong>Apply for leave</strong> <span class="muted small">— the days are worked out for you</span></summary>'
           . '<p class="hr-hint">Weekly offs and holidays are not counted. Your request goes through each approval step HR has set, and you are told at every step. '
           . 'The leave counts only once it is fully approved.</p>';
        hr_render_leave_form([], $id, [], hr_url('leave', ['tab' => 'apply']));
        echo '</details>';
    }
}

echo '<div class="hr-two">';
if (hr_can('leave', 'view')) {
    [$mine] = hr_leave_list(['employee' => $id], 0, 50);
    $pending = array_filter($mine, static fn($r) => in_array($r['status'], ['submitted', 'draft'], true));
    $upcoming = array_filter($mine, static fn($r) => $r['status'] === 'approved' && $r['end_date'] >= date('Y-m-d'));
    echo '<div class="card"><h2>Leave requests</h2><h3 class="hr-sub-h">Pending</h3>';
    hr_render_leave_rows(array_values($pending), false);
    echo '<h3 class="hr-sub-h">Coming up</h3>';
    hr_render_leave_rows(array_values($upcoming), false);
    echo '<p><a class="btn btn-sm" href="' . e(hr_url('employee', ['id' => $id, 'tab' => 'leave'])) . '">Full leave history</a></p></div>';
}
if (hr_can('payroll', 'view')) {
    $slips = array_filter(hr_emp_pay_history($id), static fn($h) => $h['run_status'] === 'payslips' && $h['number']);
    echo '<div class="card" id="payslips"><h2>My payslips</h2>';
    if (!$slips) {
        echo hr_empty('No payslips yet.');
    } else {
        echo '<ul class="hr-list">';
        foreach (array_slice($slips, 0, 12) as $h) {
            echo '<li><span>' . e(date('F Y', strtotime($h['period'] . '-01'))) . ' · net ' . e(fabo_money_exact($h['net'])) . '</span><a class="btn btn-sm" href="'
               . e(hr_url('file', ['kind' => 'payslip', 'item' => $h['id']])) . '">Download PDF</a></li>';
        }
        echo '</ul>';
    }
    echo '</div>';
}
if (hr_can('holidays', 'view')) {
    $cal = hr_emp_calendar_id($emp);
    $hol = $cal ? hr_holiday_dates([$cal], $today, date('Y-m-d', strtotime('+90 day')), true) : [];
    echo '<div class="card"><h2>Upcoming holidays</h2>';
    if (!$hol) {
        echo hr_empty('None in the next 90 days.');
    } else {
        echo '<ul class="hr-list">';
        foreach (array_slice($hol, 0, 6, true) as $d => $h) {
            echo '<li><span>' . e($h['name']) . ($h['optional'] ? ' <span class="pill grey">Optional</span>' : '') . '</span><span class="muted">' . e(date('D, j M', strtotime($d))) . '</span></li>';
        }
        echo '</ul>';
    }
    echo '<p><a class="btn btn-sm" href="' . e(hr_url('holidays')) . '">All holidays</a></p></div>';
}
if (hr_can('claims', 'view')) {
    $claims = hr_claims_list(['employee' => $id], 5);
    if ($claims) {
        echo '<div class="card"><h2>My recent claims</h2><ul class="hr-list">';
        foreach ($claims as $c) {
            echo '<li><span><a href="' . e(hr_url('claims', ['id' => $c['id']])) . '">' . e($c['number']) . '</a> · ' . e(fabo_money_exact($c['amount'])) . ' <span class="muted small">'
               . e($c['category'] ?? '') . '</span></span>' . hr_badge($c['status'], hr_claim_statuses()[$c['status']]) . '</li>';
        }
        echo '</ul><p><a class="btn btn-sm" href="' . e(hr_url('claims', ['tab' => 'mine'])) . '">All my claims</a></p></div>';
    }
}
if (hr_can('assets', 'view')) {
    $assets = hr_emp_assets($id);
    if ($assets) {
        echo '<div class="card"><h2>Company assets with me</h2><ul class="hr-list">';
        foreach ($assets as $a) {
            echo '<li><span><strong>' . e($a['tag']) . '</strong> ' . e($a['name']) . '</span><span class="muted small">since ' . e(hr_fmt_date($a['assigned_on']))
               . ($a['due_back'] ? ' · due back ' . e(hr_fmt_date($a['due_back'])) : '') . '</span></li>';
        }
        echo '</ul></div>';
    }
}
if (hr_can('attendance', 'view')) {
    $corr = fabo_all('SELECT * FROM hr_attendance_corrections WHERE employee_id = ? ORDER BY id DESC LIMIT 5', 'i', [$id]);
    if ($corr) {
        echo '<div class="card"><h2>Attendance corrections</h2><ul class="hr-list">';
        foreach ($corr as $c) {
            echo '<li><span>' . e(hr_fmt_date($c['work_date'])) . ' — ' . e($c['reason']) . '</span>' . hr_badge($c['status'], ucfirst($c['status'])) . '</li>';
        }
        echo '</ul></div>';
    }
}
$notes = fabo_notifications((int) hr_ctx()['person']['id'], false, 6);
echo '<div class="card"><h2>Recent notifications</h2>';
if (!$notes) {
    echo hr_empty('Nothing yet.');
} else {
    echo '<ul class="hr-list">';
    foreach ($notes as $n) {
        echo '<li><span>' . ($n['read_at'] ? '' : '<strong>') . e($n['title']) . ($n['read_at'] ? '' : '</strong>') . '<div class="muted small">' . e(mb_strimwidth((string) $n['body'], 0, 140, '…')) . '</div></span>'
           . '<span class="muted small">' . e(date('d M H:i', strtotime($n['created_at']))) . '</span></li>';
    }
    echo '</ul>';
}
echo '<p><a class="btn btn-sm" href="notifications.php">All notifications</a></p></div>';
echo '</div>';

// A team view for anyone whose role covers their team.
if (hr_scope('attendance', 'view') === 'team' || hr_scope('leave', 'view') === 'team') {
    $team = hr_team_ids($id);
    echo '<div class="card" style="margin-top:16px"><h2>My team today</h2>';
    if (!$team) {
        echo hr_empty('Nobody reports to you on the HR records.');
    } else {
        $emps = fabo_all("SELECT * FROM hr_employees WHERE id IN (" . implode(',', $team) . ") AND status IN ('active','probation','notice') ORDER BY full_name");
        $grid = hr_att_grid($emps, $today, $today);
        echo '<table class="data hr-table stack"><thead><tr><th>Employee</th><th>Today</th><th>In</th><th></th></tr></thead><tbody>';
        foreach ($emps as $e) {
            [$st, $rec, $note] = $grid[(int) $e['id']][$today];
            echo '<tr><td data-l="Employee">' . hr_person_cell($e) . '</td><td data-l="Today">' . ($st ? hr_badge($st, $st) : '<span class="muted">' . e($note ?: '—') . '</span>') . '</td>'
               . '<td data-l="In">' . e($rec && $rec['check_in'] ? date('H:i', strtotime($rec['check_in'])) : '') . '</td>'
               . '<td><a class="btn btn-sm" href="' . e(hr_url('employee', ['id' => $e['id']])) . '">View</a></td></tr>';
        }
        echo '</tbody></table>';
        if (hr_can('employees', 'view')) {
            echo '<p><a class="btn btn-sm" href="' . e(hr_url('orgchart')) . '">Org chart</a></p>';
        }
    }
    echo '</div>';
}

hr_layout_end();

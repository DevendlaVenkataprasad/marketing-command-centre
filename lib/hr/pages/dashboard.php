<?php
/**
 * HR Dashboard — what needs doing first, then today, then the workforce,
 * then the trends. Live figures only; every card opens the records behind it.
 */

require_once __DIR__ . '/../../charts.php';

$f = [
    'month'       => hr_month_param(),
    'department'  => (int) ($_GET['department'] ?? 0),
    'designation' => (int) ($_GET['designation'] ?? 0),
    'status'      => (string) ($_GET['status'] ?? ''),
    'employee'    => (int) ($_GET['employee'] ?? 0),
];
$s = hr_dashboard_stats($f);
$cards = array_column($s['cards'], null, 'key');

hr_layout_start('HR Dashboard', 'Today is ' . date('l, j F Y') . '. Figures are live.', [],
                hr_report_allowed('dashboard_summary') ? '<a class="btn btn-sm" href="' . e(hr_url('file', ['kind' => 'report', 'report' => 'dashboard_summary', 'fmt' => 'pdf', 'month' => $f['month']])) . '">Download summary (PDF)</a>' : '');

/* Setup: one line while anything essential is missing. */
if (hr_can_setup()) {
    $org = hr_org();
    $has = static fn(string $sql) => (bool) fabo_one($sql, 'i', [$org]);
    $missing = 0;
    foreach (['SELECT id FROM hr_departments WHERE org_id = ? LIMIT 1', 'SELECT id FROM hr_shifts WHERE org_id = ? AND active = 1 LIMIT 1',
              'SELECT id FROM hr_holidays WHERE org_id = ? LIMIT 1', 'SELECT id FROM hr_leave_types WHERE org_id = ? LIMIT 1',
              'SELECT id FROM hr_leave_policies WHERE org_id = ? LIMIT 1', 'SELECT id FROM hr_salary_components WHERE org_id = ? LIMIT 1'] as $sql) {
        $missing += $has($sql) ? 0 : 1;
    }
    $missing += hr_setting('pay_divisor') && hr_setting('pay_lop_method') ? 0 : 1;
    if ($missing) {
        echo '<div class="hr-setup-banner"><div><strong>HR setup is not finished</strong> — ' . $missing . ' essential step' . ($missing === 1 ? '' : 's')
           . ' left. Leave and payroll need them.</div><a class="btn btn-sm btn-primary" href="' . e(hr_url('setup')) . '">Continue setup &rarr;</a></div>';
    }
}

/* Quick actions: the things people come here to do. */
$quick = [];
if (hr_can('employees', 'create') && hr_scope('employees', 'create') === 'org') { $quick[] = ['Add employee', hr_url('employee', ['new' => 1]), '+']; }
if (hr_can('attendance', 'create')) { $quick[] = ['Mark attendance', hr_url('attendance'), '✓']; }
if (hr_can('leave', 'create')) { $quick[] = ['Record leave', hr_url('leave', ['tab' => 'apply']), '⟶']; }
if (hr_scope('recruitment', 'create') === 'org') { $quick[] = ['New job opening', hr_url('recruitment', ['new' => 1]), '◎']; }
if (hr_can('payroll', 'process_payroll')) { $quick[] = ['Run payroll', hr_url('payroll'), '₹']; }
if (hr_is_admin() || hr_scope('helpdesk', 'create') === 'org') { $quick[] = ['Post a notice', hr_url('helpdesk', ['tab' => 'notices']) . '#post', '✎']; }

echo '<div class="hr-top">' . hr_attention_html();
if ($quick) {
    echo '<div class="card hr-quick"><h2>Quick actions</h2><div class="grid">';
    foreach ($quick as [$label, $url, $icon]) {
        echo '<a href="' . e($url) . '"><span class="ic" aria-hidden="true">' . e($icon) . '</span>' . e($label) . '</a>';
    }
    echo '</div></div>';
}
echo '</div>';

/* Today */
$tile = static function (string $key, string $tone = '') use ($cards): string {
    $c = $cards[$key] ?? null;
    return $c ? hr_tile($c['label'], e((string) $c['value']), $tone, e($c['note']), $c['href']) : '';
};
echo '<h2 class="hr-group-h">Today</h2><div class="tiles">'
   . $tile('present', 'green') . $tile('absent', ($cards['absent']['value'] ?? 0) ? 'red' : '') . $tile('leave') . $tile('late', ($cards['late']['value'] ?? 0) ? 'amber' : '')
   . '</div>';

/* Workforce */
$org = hr_org();
$month0 = date('Y-m-01');
$joiners = (int) (fabo_one('SELECT COUNT(*) n FROM hr_employees WHERE org_id = ? AND date_of_joining BETWEEN ? AND LAST_DAY(?)', 'iss', [$org, $month0, $month0])['n'] ?? 0);
$leavers = (int) (fabo_one("SELECT COUNT(*) n FROM hr_employees WHERE org_id = ? AND status IN ('resigned','terminated','inactive') AND date_of_leaving BETWEEN ? AND LAST_DAY(?)",
                           'iss', [$org, $month0, $month0])['n'] ?? 0);
$notice = (int) (fabo_one("SELECT COUNT(*) n FROM hr_employees WHERE org_id = ? AND status = 'notice'", 'i', [$org])['n'] ?? 0);
echo '<h2 class="hr-group-h">Workforce</h2><div class="tiles">' . $tile('active') . $tile('total')
   . (hr_can('employees', 'view') ? hr_tile('Joined this month', (string) $joiners, $joiners ? 'green' : '', date('F'), hr_url('reports', ['report' => 'headcount']))
                                  . hr_tile('Left this month', (string) $leavers, '', $notice ? $notice . ' on notice' : '', hr_url('lifecycle', ['tab' => 'exits'])) : '');
if (hr_can('recruitment', 'view')) {
    $open = (int) (fabo_one("SELECT COALESCE(SUM(GREATEST(o.positions - (SELECT COUNT(*) FROM hr_candidates c WHERE c.opening_id = o.id AND c.stage = 'hired'), 0)), 0) n
                             FROM hr_job_openings o WHERE o.org_id = ? AND o.status IN ('open','on_hold')", 'i', [$org])['n'] ?? 0);
    echo hr_tile('Open positions', (string) $open, $open ? 'amber' : '', '', hr_url('recruitment'));
}
echo $tile('payroll') . '</div>';

/* Trends, with their filters right above them. */
echo '<h2 class="hr-group-h">Trends</h2>';
$filtered = array_filter([$f['department'], $f['designation'], $f['status'], $f['employee']]);
echo '<details class="card hr-filters-wrap"' . ($filtered ? ' open' : '') . '><summary>Filter the figures' . ($filtered ? ' <span class="badge">' . count($filtered) . '</span>' : '') . '</summary>'
   . '<form method="get" class="hr-filters" aria-label="Filters" style="padding:10px 0 0"><input type="hidden" name="p" value="dashboard">'
   . '<div class="grp"><label for="f-month">Month for charts</label><select id="f-month" name="month" data-autosubmit>' . hr_options(hr_month_choices(), $f['month']) . '</select></div>'
   . '<div class="grp"><label for="f-dept">Department</label><select id="f-dept" name="department" data-autosubmit>' . hr_options(hr_departments_map(), $f['department'], 'All') . '</select></div>'
   . '<div class="grp"><label for="f-desig">Designation</label><select id="f-desig" name="designation" data-autosubmit>' . hr_options(hr_designations_map(), $f['designation'], 'All') . '</select></div>'
   . '<div class="grp"><label for="f-status">Status</label><select id="f-status" name="status" data-autosubmit>' . hr_options(hr_emp_statuses(), $f['status'], 'All') . '</select></div>'
   . '<div class="grp"><label for="f-emp">Employee</label><select id="f-emp" name="employee" data-autosubmit>' . hr_options(hr_employee_choices(false, 'dashboard'), $f['employee'], 'All') . '</select></div>'
   . ($filtered ? '<a class="btn btn-sm" href="' . e(hr_url('dashboard')) . '">Clear filters</a>' : '')
   . '<noscript><button class="btn btn-sm btn-primary">Apply</button></noscript></form></details>';

$S = FABO_SERIES;
echo '<div class="chartgrid">';
fabo_chart_line('Attendance trend (person-days)', $s['trend']['cats'], [
    ['name' => 'Present', 'color' => $S[2], 'values' => $s['trend']['present']],
    ['name' => 'On leave', 'color' => $S[0], 'values' => $s['trend']['leave']],
    ['name' => 'Absent', 'color' => $S[7], 'values' => $s['trend']['absent']],
]);
fabo_chart_bar('Absences and late arrivals', $s['trend']['cats'], [
    ['name' => 'Absent days', 'color' => $S[7], 'values' => $s['trend']['absent']],
    ['name' => 'Late arrivals', 'color' => $S[3], 'values' => $s['trend']['late']],
]);
$slices = [];
$i = 0;
$other = 0;
foreach ($s['by_dept'] as $d => $n) {
    if ($i < count($S) - 1) {
        $slices[] = ['label' => $d, 'value' => $n, 'color' => $S[$i]];
    } else {
        $other += $n;
    }
    $i++;
}
if ($other) {
    $slices[] = ['label' => 'Other', 'value' => $other, 'color' => FABO_NEUTRAL];
}
fabo_chart_pie('Employees by department', $slices);
if ($s['leave_by_type']) {
    fabo_chart_bar('Leave taken by type — ' . date('F Y', strtotime($s['month'] . '-01')) . ' (days)', array_keys($s['leave_by_type']),
                   [['name' => 'Days', 'color' => $S[0], 'values' => array_values($s['leave_by_type'])]]);
} else {
    echo '<div class="chartcard"><h3>Leave taken by type — ' . e(date('F Y', strtotime($s['month'] . '-01'))) . '</h3><div class="empty">No approved leave in this month.</div></div>';
}
if ($s['can_pay']) {
    if ($s['pay_trend']['cats']) {
        fabo_chart_bar('Payroll cost (approved runs)', $s['pay_trend']['cats'], [
            ['name' => 'Cost to company', 'color' => $S[6], 'values' => $s['pay_trend']['gross']],
            ['name' => 'Net paid', 'color' => $S[2], 'values' => $s['pay_trend']['net']],
        ], 'money');
    } else {
        echo '<div class="chartcard"><h3>Payroll cost</h3><div class="empty">No approved payroll yet.</div></div>';
    }
}
echo '</div>';

echo '<div class="hr-two" style="margin-top:16px">';
if (hr_can('helpdesk', 'view')) {
    $notes = hr_announcements_live(hr_self_emp(), 4);
    echo '<div class="card"><h2>Announcements</h2>';
    if ($notes) {
        echo '<ul class="hr-list">';
        foreach ($notes as $a) {
            echo '<li><span>' . ($a['pinned'] ? '📌 ' : '') . '<a href="' . e(hr_url('helpdesk')) . '">' . e($a['title']) . '</a></span><span class="muted small">' . e(hr_fmt_date($a['publish_from'])) . '</span></li>';
        }
        echo '</ul>';
    } else {
        echo hr_empty('No announcements.');
    }
    echo '</div>';
}
echo '<div class="card"><h2>Upcoming holidays</h2>';
if ($s['upcoming']) {
    echo '<ul class="hr-list">';
    foreach ($s['upcoming'] as $h) {
        echo '<li><span>' . e($h['name']) . ($h['optional'] ? ' <span class="pill grey">Optional</span>' : '') . '</span><span class="muted">'
           . e(date('D, j M', strtotime($h['date']))) . ' · ' . e(fabo_due_phrase($h['date'])) . '</span></li>';
    }
    echo '</ul>';
} else {
    echo hr_empty('No holidays in the next 60 days.', hr_can('holidays', 'create') ? ' Add them under Holidays.' : '');
}
echo '</div>';

echo '<div class="card"><h2>Birthdays and work anniversaries</h2>';
if ($s['events']) {
    echo '<ul class="hr-list">';
    foreach ($s['events'] as $ev) {
        echo '<li><span>' . e($ev['name']) . ' — ' . e($ev['what']) . '</span><span class="muted">' . e(date('D, j M', strtotime($ev['date'])))
           . ' · ' . e(fabo_due_phrase($ev['date'])) . '</span></li>';
    }
    echo '</ul>';
} else {
    echo hr_empty('None in the next 30 days.', ' Dates of birth and joining come from employee records.');
}
echo '</div></div>';

hr_layout_end();

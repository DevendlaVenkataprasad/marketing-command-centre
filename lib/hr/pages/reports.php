<?php
/**
 * Reports & Analytics — pick a report, set its filters, preview it, and
 * download it as Excel, CSV or PDF. Every download is built from live data
 * on request and served through the authorised file route; nothing is
 * left at a public address.
 */

$defs = array_filter(hr_report_defs(), static fn($k) => hr_report_allowed($k), ARRAY_FILTER_USE_KEY);
$key = (string) ($_GET['report'] ?? '');
if (!isset($defs[$key])) { $key = ''; }

$desc = [
    'employee_register' => 'Everyone on the register with job details' . (hr_can('confidential', 'view') ? ' and bank details.' : '.'),
    'daily_attendance' => 'Each employee\'s status, times and hours on one day.',
    'monthly_attendance' => 'Present, absent, leave, late and hours per employee for a month.',
    'attendance_history' => 'One employee, day by day, over a date range.',
    'machine_report' => 'The biometric machine layout: Person ID, Name, Department, Position, Gender, Date, Day, Timetable, First-In, Last-Out.',
    'leave_balance' => 'Opening, credited, taken, pending and available for each leave type.',
    'leave_requests' => 'Every request with its dates, status and approver.',
    'dept_leave' => 'Approved leave days per department and leave type.',
    'holiday_calendar' => 'The holidays in a year, per calendar.',
    'late_absence' => 'Late arrivals, early departures, absences and missing punches.',
    'overtime' => 'Overtime hours and whether they were approved.',
    'payroll_register' => 'Every employee\'s pay, component by component, for one run.',
    'salary_deductions' => 'Every deduction, with how it was worked out.',
    'unpaid_leave' => 'Leave without pay and absences without leave.',
    'headcount' => 'Opening and closing headcount, joiners, leavers and attrition, month by month.',
    'recruitment_pipeline' => 'Every opening with its candidates at each stage.',
    'exits_register' => 'Resignations and other exits with tenure, settlement and rehire eligibility.',
    'claims_register' => 'Every expense claim with what was approved and how it was paid.',
    'asset_register' => 'Every company asset, its value and who holds it.',
    'training_register' => 'Who attended which session, hours, scores and feedback.',
    'performance_ratings' => 'Self, manager and final ratings for each review.',
    'dashboard_summary' => 'The dashboard\'s figures, as a document.',
];

// Grouped the way the HR menu is, so a report is found where its subject lives.
$groupsOf = [
    'People' => ['employee_register', 'headcount', 'recruitment_pipeline', 'exits_register'],
    'Time' => ['daily_attendance', 'monthly_attendance', 'attendance_history', 'machine_report', 'late_absence', 'overtime', 'leave_balance', 'leave_requests', 'dept_leave', 'unpaid_leave', 'holiday_calendar'],
    'Pay' => ['payroll_register', 'salary_deductions', 'claims_register'],
    'Growth & workplace' => ['performance_ratings', 'training_register', 'asset_register'],
    'Summary' => ['dashboard_summary'],
];

hr_layout_start('Reports & Analytics', 'Live reports. Download as Excel or CSV for working with the data, or PDF to share and print.', ['Reports' => '']);

if (!$defs) {
    echo '<div class="card">' . hr_empty('No reports are available to you.') . '</div>';
    hr_layout_end();
    return;
}
if ($key === '') {
    foreach ($groupsOf as $g => $keys) {
        $have = array_values(array_filter($keys, static fn($k) => isset($defs[$k])));
        if (!$have) {
            continue;
        }
        echo '<h2 class="hr-group-h">' . e($g) . '</h2><div class="hr-reports" style="margin-bottom:8px">';
        foreach ($have as $k) {
            echo '<a href="' . e(hr_url('reports', ['report' => $k])) . '"><div class="t">' . e($defs[$k][0]) . '</div><div class="d">' . e($desc[$k] ?? '') . '</div></a>';
        }
        if ($g === 'Pay' && hr_can('payroll', 'view')) {
            echo '<a href="' . e(hr_url('payroll')) . '"><div class="t">Individual Payslip PDF</div><div class="d">Open a payroll run, then an employee, to download their payslip.</div></a>';
        }
        echo '</div>';
    }
} else {
    echo '<p><a class="btn btn-sm" href="' . e(hr_url('reports')) . '">&larr; All reports</a></p>';
}

if ($key !== '') {
    [$title, , $filters] = $defs[$key];
    try {
        $f = hr_report_filters($_GET);
    } catch (InvalidArgumentException $e) {
        echo '<div class="flash err">' . e($e->getMessage()) . '</div>';
        $f = hr_report_filters([]);
    }
    echo '<form method="get" class="card hr-filters"><input type="hidden" name="p" value="reports"><input type="hidden" name="report" value="' . e($key) . '">';
    foreach ($filters as $fl) {
        switch ($fl) {
            case 'date': echo '<div class="grp"><label for="rd">Date</label><input id="rd" type="date" name="date" value="' . e($f['date']) . '"></div>'; break;
            case 'month': echo '<div class="grp"><label for="rm">Month</label><select id="rm" name="month">' . hr_options(hr_month_choices(), $f['month']) . '</select></div>'; break;
            case 'from': echo '<div class="grp"><label for="rf">From</label><input id="rf" type="date" name="from" value="' . e($f['from']) . '"></div>'; break;
            case 'to': echo '<div class="grp"><label for="rt">To</label><input id="rt" type="date" name="to" value="' . e($f['to']) . '"></div>'; break;
            case 'department': echo '<div class="grp"><label for="rdp">Department</label><select id="rdp" name="department">' . hr_options(hr_departments_map(false), $f['department'], 'All') . '</select></div>'; break;
            case 'employee': echo '<div class="grp"><label for="re">Employee</label><select id="re" name="employee" required>' . hr_options(hr_employee_choices(false, 'attendance'), $f['employee'], 'Choose…') . '</select></div>'; break;
            case 'status':
                $opts = match ($key) { 'employee_register' => hr_emp_statuses(), 'claims_register' => hr_claim_statuses(), default => hr_leave_statuses() };
                echo '<div class="grp"><label for="rs">Status</label><select id="rs" name="status">' . hr_options($opts, $f['status'], 'All') . '</select></div>'; break;
            case 'leave_type':
                $t = [];
                foreach (hr_leave_types(false) as $x) { $t[(int) $x['id']] = $x['name']; }
                echo '<div class="grp"><label for="rl">Leave type</label><select id="rl" name="leave_type">' . hr_options($t, $f['leave_type'], 'All') . '</select></div>'; break;
            case 'year':
                $ys = [];
                for ($y = (int) date('Y') + 1; $y >= (int) date('Y') - 5; $y--) { $ys[$y] = $key === 'leave_balance' ? hr_leave_year_label($y) : (string) $y; }
                $yv = in_array($key, ['holiday_calendar', 'headcount', 'performance_ratings'], true) ? (int) ($_GET['year'] ?? date('Y')) : $f['year'];
                echo '<div class="grp"><label for="ry">Year</label><select id="ry" name="year">' . hr_options($ys, $yv) . '</select></div>'; break;
            case 'calendar': echo '<div class="grp"><label for="rc">Calendar</label><select id="rc" name="calendar">' . hr_options(hr_calendars_map(false), $f['calendar'], 'All') . '</select></div>'; break;
            case 'run':
                $runs = [];
                foreach (hr_runs(36) as $r) { $runs[(int) $r['id']] = date('F Y', strtotime($r['period'] . '-01')) . ' — ' . hr_run_statuses()[$r['status']]; }
                echo '<div class="grp"><label for="rr">Payroll run</label><select id="rr" name="run">' . hr_options($runs, $f['run'] ?: array_key_first($runs)) . '</select></div>'; break;
        }
    }
    echo '<button class="btn btn-sm btn-primary">Show</button></form>';

    try {
        $r = hr_report_build($key, $f);
        $dl = static fn($fmt) => hr_url('file', ['kind' => 'report', 'report' => $key, 'fmt' => $fmt] + array_intersect_key($_GET, array_flip($filters)));
        echo '<div class="card"><div class="hr-head" style="margin-bottom:10px"><div class="pagehead"><h2 style="margin:0">' . e($r['title']) . '</h2><div class="sub">' . e($r['subtitle']) . '</div></div>'
           . '<div class="acts"><a class="btn btn-sm btn-primary" href="' . e($dl('xlsx')) . '">Excel</a><a class="btn btn-sm" href="' . e($dl('csv')) . '">CSV</a>'
           . '<a class="btn btn-sm" href="' . e($dl('pdf')) . '">PDF</a></div></div>';
        if (!$r['rows']) {
            echo hr_empty('No records for these filters.');
        } else {
            $show = array_slice($r['rows'], 0, 200);
            echo '<div class="scrollx"><table class="data hr-table"><thead><tr>';
            foreach ($r['cols'] as [$lab, , $al]) { echo '<th' . ($al === 'right' ? ' class="num"' : '') . '>' . e($lab) . '</th>'; }
            echo '</tr></thead><tbody>';
            foreach ($show as $row) {
                echo '<tr>';
                foreach ($r['cols'] as $i => [, , $al]) {
                    $c = $row[$i] ?? '';
                    if (is_array($c)) {
                        $k = array_key_first($c);
                        $c = match ($k) { 'm' => fabo_money_exact($c[$k]), 'n' => hr_fmt_days($c[$k]), 'd' => hr_fmt_date((string) $c[$k]), default => (string) $c[$k] };
                    }
                    echo '<td' . ($al === 'right' ? ' class="num"' : '') . '>' . e((string) $c) . '</td>';
                }
                echo '</tr>';
            }
            echo '</tbody>';
            if ($r['totals']) {
                echo '<tfoot><tr>';
                foreach ($r['cols'] as $i => [, , $al]) { echo '<th' . ($al === 'right' ? ' class="num"' : '') . '>' . e((string) ($r['totals'][$i] ?? '')) . '</th>'; }
                echo '</tr></tfoot>';
            }
            echo '</table></div>' . (count($r['rows']) > 200 ? '<p class="hr-hint">Showing the first 200 of ' . count($r['rows']) . ' rows. The download has them all.</p>' : '');
        }
        echo '</div>';
    } catch (InvalidArgumentException $e) {
        echo '<div class="hr-warn">' . e($e->getMessage()) . '</div>';
    }
}
hr_layout_end();

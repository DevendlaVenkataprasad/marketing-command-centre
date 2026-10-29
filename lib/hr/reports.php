<?php
/**
 * HR reports and the dashboard's figures.
 *
 * Every report is a function returning a title, columns and rows from live
 * data, so the same definition serves the on-screen preview, the Excel and
 * CSV downloads and the PDF. Each one names the module whose data it shows;
 * the viewer needs that module's view right as well as the Reports export
 * right, and rows are narrowed to the viewer's scope by the same
 * hr_scope_sql() the pages use.
 */

require_once __DIR__ . '/payroll.php';
require_once __DIR__ . '/pdf.php';

function hr_report_defs(): array
{
    return [
        'employee_register' => ['Employee Register', 'employees', ['department', 'status']],
        'daily_attendance'  => ['Daily Attendance Report', 'attendance', ['date', 'department']],
        'monthly_attendance' => ['Monthly Attendance Summary', 'attendance', ['month', 'department']],
        'attendance_history' => ['Employee Attendance History', 'attendance', ['employee', 'from', 'to']],
        'machine_report'    => ['First/Last Access Records Report', 'attendance', ['from', 'to', 'department']],
        'leave_balance'     => ['Leave Balance Report', 'leave', ['year', 'department']],
        'leave_requests'    => ['Leave Request and Approval Report', 'leave', ['from', 'to', 'status', 'leave_type', 'department']],
        'dept_leave'        => ['Department-wise Leave Report', 'leave', ['from', 'to']],
        'holiday_calendar'  => ['Holiday Calendar', 'holidays', ['year', 'calendar']],
        'late_absence'      => ['Late Arrival and Absence Report', 'attendance', ['from', 'to', 'department']],
        'overtime'          => ['Overtime Report', 'attendance', ['from', 'to', 'department']],
        'payroll_register'  => ['Monthly Payroll Register', 'payroll', ['run']],
        'salary_deductions' => ['Salary Deduction Report', 'payroll', ['run']],
        'unpaid_leave'      => ['Unpaid Leave Report', 'leave', ['from', 'to', 'department']],
        'headcount'         => ['Headcount and Attrition', 'employees', ['year', 'department']],
        'recruitment_pipeline' => ['Recruitment Pipeline', 'recruitment', []],
        'exits_register'    => ['Exits Register', 'lifecycle', ['from', 'to', 'department']],
        'claims_register'   => ['Expense Claims Register', 'claims', ['from', 'to', 'status', 'department']],
        'asset_register'    => ['Asset Register', 'assets', []],
        'training_register' => ['Training Register', 'training', ['from', 'to', 'department']],
        'performance_ratings' => ['Performance Ratings', 'performance', ['year', 'department']],
        'dashboard_summary' => ['HR Dashboard Summary', 'dashboard', ['month']],
    ];
}

function hr_report_allowed(string $key): bool
{
    $def = hr_report_defs()[$key] ?? null;
    if (!$def) {
        return false;
    }
    if (hr_is_admin()) {
        return true;
    }
    return hr_can('reports', 'export') && hr_can($def[1], 'view') && hr_scope($def[1], 'view') !== 'self';
}

/** Reads and validates the report filters from a request. */
function hr_report_filters(array $q): array
{
    $f = [];
    $f['date']  = hr_date($q['date'] ?? '', false, 'Date') ?? date('Y-m-d');
    $f['month'] = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) ($q['month'] ?? '')) ? $q['month'] : date('Y-m');
    $f['from']  = hr_date($q['from'] ?? '', false, 'From') ?? date('Y-m-01');
    $f['to']    = hr_date($q['to'] ?? '', false, 'To') ?? date('Y-m-d');
    if ($f['to'] < $f['from']) {
        throw new InvalidArgumentException('“To” is before “From”.');
    }
    if ((strtotime($f['to']) - strtotime($f['from'])) / 86400 > 400) {
        throw new InvalidArgumentException('Choose a range of at most about 13 months.');
    }
    $f['year'] = (int) ($q['year'] ?? 0) ?: hr_leave_year_of(date('Y-m-d'));
    $f['cal_year'] = (int) ($q['year'] ?? 0) ?: (int) date('Y');
    foreach (['department', 'employee', 'leave_type', 'calendar', 'run'] as $k) {
        $f[$k] = (int) ($q[$k] ?? 0);
    }
    $f['status'] = (string) ($q['status'] ?? '');
    return $f;
}

/** Builds one report. Returns [title, subtitle, cols, rows, totals]. */
function hr_report_build(string $key, array $f): array
{
    $def = hr_report_defs()[$key] ?? null;
    if (!$def || !hr_report_allowed($key)) {
        fabo_deny('That report is not available to you.', 403);
    }
    $fn = 'hr_report_' . $key;
    $r = $fn($f);
    $r['title'] = $def[0];
    return $r;
}

function hr_dept_name(int $id): string
{
    if (!$id) {
        return 'All departments';
    }
    return hr_departments_map(false)[$id] ?? 'Department';
}

function hr_report_employee_register(array $f): array
{
    [$rows] = hr_employees_list(['department' => $f['department'], 'status' => $f['status'] !== '' ? $f['status'] : ''], 'name', 'asc', 0, 5000);
    $conf = hr_can('confidential', 'view');
    $cols = [['Employee ID', 60, 'left'], ['Name', 110, 'left'], ['Work email', 120, 'left'], ['Phone', 70, 'left'],
             ['Department', 80, 'left'], ['Designation', 80, 'left'], ['Manager', 80, 'left'], ['Joined', 55, 'left'],
             ['Type', 55, 'left'], ['Location', 60, 'left'], ['Status', 50, 'left']];
    if ($conf) {
        $cols[] = ['Bank account', 70, 'left'];
        $cols[] = ['IFSC', 55, 'left'];
        $cols[] = ['PAN', 55, 'left'];
    }
    $out = [];
    foreach ($rows as $e) {
        $r = [$e['emp_code'], $e['full_name'], (string) $e['work_email'], (string) $e['phone'], (string) $e['department'],
              (string) $e['designation'], (string) $e['manager'], ['d' => (string) $e['date_of_joining']], (string) $e['employment_type'],
              (string) $e['location'], hr_status_label($e['status'])];
        if ($conf) {
            array_push($r, (string) $e['bank_account'], (string) $e['bank_ifsc'], (string) $e['pan']);
        }
        $out[] = $r;
    }
    return ['subtitle' => hr_dept_name($f['department']) . ' · ' . ($f['status'] !== '' ? hr_status_label($f['status']) : 'All statuses')
                        . ' · ' . count($out) . ' employees', 'cols' => $cols, 'rows' => $out, 'totals' => []];
}

function hr_report_daily_attendance(array $f): array
{
    $emps = hr_att_employees(['department' => $f['department']], $f['date'], $f['date']);
    $grid = hr_att_grid($emps, $f['date'], $f['date']);
    $rows = [];
    foreach ($emps as $e) {
        [$st, $rec, $note] = $grid[(int) $e['id']][$f['date']];
        $rows[] = [$e['emp_code'], $e['full_name'], (string) $e['department'], $st ?: '—',
                   $rec && $rec['check_in'] ? date('H:i', strtotime($rec['check_in'])) : '',
                   $rec && $rec['check_out'] ? date('H:i', strtotime($rec['check_out'])) : '',
                   $rec ? hr_fmt_minutes((int) $rec['worked_minutes']) : '',
                   $rec && $rec['late_minutes'] ? $rec['late_minutes'] . ' min' : '',
                   $rec && $rec['early_minutes'] ? $rec['early_minutes'] . ' min' : '',
                   $rec && $rec['overtime_minutes'] ? hr_fmt_minutes((int) $rec['overtime_minutes']) : '',
                   trim($note . ($rec && $rec['remarks'] ? ' ' . $rec['remarks'] : ''))];
    }
    return ['subtitle' => hr_fmt_date($f['date']) . ' · ' . hr_dept_name($f['department']),
            'cols' => [['Employee ID', 60, 'left'], ['Name', 110, 'left'], ['Department', 80, 'left'], ['Status', 80, 'left'],
                       ['In', 35, 'left'], ['Out', 35, 'left'], ['Worked', 45, 'right'], ['Late', 40, 'right'], ['Early', 40, 'right'],
                       ['Overtime', 45, 'right'], ['Notes', 120, 'left']], 'rows' => $rows, 'totals' => []];
}

function hr_report_monthly_attendance(array $f): array
{
    $from = $f['month'] . '-01';
    $to   = date('Y-m-t', strtotime($from));
    $emps = hr_att_employees(['department' => $f['department']], $from, $to);
    $grid = hr_att_grid($emps, $from, $to);
    $rows = [];
    foreach ($emps as $e) {
        $t = hr_att_totals($grid[(int) $e['id']] ?? []);
        $rows[] = [$e['emp_code'], $e['full_name'], (string) $e['department'], ['n' => $t['present']], ['n' => $t['absent']],
                   ['n' => $t['leave']], ['n' => $t['half']], ['n' => $t['wfh']], ['n' => $t['off']], ['n' => $t['holiday']],
                   ['n' => $t['late']], ['n' => $t['early']], hr_fmt_minutes($t['worked']), hr_fmt_minutes($t['ot'])];
    }
    return ['subtitle' => date('F Y', strtotime($from)) . ' · ' . hr_dept_name($f['department']),
            'cols' => [['Employee ID', 55, 'left'], ['Name', 105, 'left'], ['Department', 75, 'left'], ['Present', 42, 'right'],
                       ['Absent', 40, 'right'], ['Leave', 38, 'right'], ['Half days', 42, 'right'], ['WFH', 32, 'right'],
                       ['Weekly off', 45, 'right'], ['Holidays', 42, 'right'], ['Late', 32, 'right'], ['Early out', 42, 'right'],
                       ['Worked', 50, 'right'], ['Overtime', 50, 'right']], 'rows' => $rows, 'totals' => []];
}

function hr_report_attendance_history(array $f): array
{
    if (!$f['employee']) {
        throw new InvalidArgumentException('Choose an employee.');
    }
    hr_need_emp($f['employee'], 'attendance', 'view');
    $e = hr_employee($f['employee']);
    $grid = hr_att_grid([$e], $f['from'], $f['to'])[(int) $e['id']] ?? [];
    $rows = [];
    foreach ($grid as $d => [$st, $rec, $note]) {
        $rows[] = [['d' => $d], date('D', strtotime($d)), $st ?: '—',
                   $rec && $rec['check_in'] ? date('H:i', strtotime($rec['check_in'])) : '',
                   $rec && $rec['check_out'] ? date('H:i', strtotime($rec['check_out'])) : '',
                   $rec ? hr_fmt_minutes((int) $rec['worked_minutes']) : '', $rec && $rec['late_minutes'] ? $rec['late_minutes'] . ' min' : '',
                   $rec && $rec['overtime_minutes'] ? hr_fmt_minutes((int) $rec['overtime_minutes']) : '', $rec['source'] ?? '',
                   trim($note . ($rec && $rec['remarks'] ? ' ' . $rec['remarks'] : ''))];
    }
    return ['subtitle' => $e['full_name'] . ' (' . $e['emp_code'] . ') · ' . hr_fmt_date($f['from']) . ' – ' . hr_fmt_date($f['to']),
            'cols' => [['Date', 60, 'left'], ['Day', 30, 'left'], ['Status', 90, 'left'], ['In', 35, 'left'], ['Out', 35, 'left'],
                       ['Worked', 45, 'right'], ['Late', 40, 'right'], ['Overtime', 45, 'right'], ['Source', 45, 'left'], ['Notes', 140, 'left']],
            'rows' => $rows, 'totals' => []];
}

/**
 * Attendance in the biometric machine's own layout, one row per employee per
 * day, so it can be read side by side with — or in place of — the machine's
 * report. Person ID is the Biometric ID (the Employee ID when there is none);
 * "-" where there was no punch.
 */
function hr_report_machine_report(array $f): array
{
    require_once __DIR__ . '/biometric.php';
    $emps = hr_att_employees(['department' => $f['department']], $f['from'], $f['to']);
    $desig = hr_designations_map(false);
    usort($emps, static fn($a, $b) => strnatcasecmp((string) ($a['biometric_id'] ?: $a['emp_code']), (string) ($b['biometric_id'] ?: $b['emp_code'])));
    $grid = hr_att_grid($emps, $f['from'], $f['to']);
    $rows = [];
    $n = 0;
    foreach ($emps as $e) {
        foreach ($grid[(int) $e['id']] ?? [] as $d => $cell) {
            [, $rec] = $cell;
            if (!$cell['info']['employed']) {
                continue;
            }
            $rows[] = [(string) ++$n, (string) ($e['biometric_id'] ?: $e['emp_code']), $e['full_name'], (string) $e['department'],
                       (string) ($desig[(int) $e['designation_id']] ?? ''), (string) $e['gender'], $d, hr_bio_dow($d),
                       hr_bio_timetable_text($rec && $rec['shift_id'] ? hr_shift_cached((int) $rec['shift_id']) : $cell['info']['shift']),
                       $rec && $rec['check_in'] ? date('H:i:s', strtotime($rec['check_in'])) : '-',
                       $rec && $rec['check_out'] ? date('H:i:s', strtotime($rec['check_out'])) : '-'];
        }
    }
    return ['subtitle' => $f['from'] . ' 00:00:00 - ' . $f['to'] . ' 23:59:59' . ($f['department'] ? ' · ' . hr_dept_name($f['department']) : ''),
            'cols' => [['No.', 28, 'left'], ['Person ID', 55, 'left'], ['Name', 110, 'left'], ['Department', 80, 'left'], ['Position', 95, 'left'],
                       ['Gender', 40, 'left'], ['Date', 55, 'left'], ['Day Of Week', 45, 'left'], ['Timetable', 85, 'left'],
                       ['First-In', 45, 'left'], ['Last-Out', 45, 'left']],
            'rows' => $rows, 'totals' => []];
}

function hr_report_leave_balance(array $f): array
{
    [$emps] = hr_employees_list(['department' => $f['department'], 'status' => 'working'], 'name', 'asc', 0, 5000);
    $rows = [];
    foreach ($emps as $e) {
        if (!hr_emp_allowed((int) $e['id'], 'leave', 'view')) {
            continue;
        }
        foreach (hr_leave_balances($e, $f['year']) as $b) {
            $rows[] = [$e['emp_code'], $e['full_name'], (string) $e['department'], $b['type']['name'],
                       ['n' => $b['opening']], ['n' => $b['entitlement']], ['n' => $b['accrued']], ['n' => $b['carried']],
                       ['n' => $b['taken']], ['n' => $b['pending']], ['n' => $b['expired']], ['n' => $b['adjusted']],
                       $b['available'] === null ? 'Unlimited' : ['n' => $b['available']]];
        }
    }
    return ['subtitle' => 'Leave year ' . hr_leave_year_label($f['year']) . ' · ' . hr_dept_name($f['department']),
            'cols' => [['Employee ID', 55, 'left'], ['Name', 100, 'left'], ['Department', 70, 'left'], ['Leave type', 90, 'left'],
                       ['Opening', 42, 'right'], ['Entitlement', 50, 'right'], ['Accrued', 42, 'right'], ['Carried in', 45, 'right'],
                       ['Taken', 38, 'right'], ['Pending', 40, 'right'], ['Expired', 40, 'right'], ['Adjusted', 42, 'right'],
                       ['Available', 48, 'right']], 'rows' => $rows, 'totals' => []];
}

function hr_report_leave_requests(array $f): array
{
    [$list] = hr_leave_list(['from' => $f['from'], 'to' => $f['to'], 'status' => $f['status'], 'type' => $f['leave_type'],
                             'department' => $f['department']], 0, 5000);
    $rows = [];
    foreach ($list as $r) {
        $acts = hr_leave_actions((int) $r['id']);
        $last = $acts ? end($acts) : null;
        $rows[] = [(string) $r['id'], $r['emp_code'], $r['full_name'], (string) $r['department'], $r['type_name'], ['d' => $r['start_date']],
                   ['d' => $r['end_date']], ['n' => (float) $r['days']], hr_leave_statuses()[$r['status']] ?? $r['status'],
                   $r['submitted_at'] ? date('Y-m-d', strtotime($r['submitted_at'])) : '', $last ? (string) $last['actor_label'] : '',
                   (string) ($r['decision_comment'] ?? ''), (string) $r['reason']];
    }
    return ['subtitle' => hr_fmt_date($f['from']) . ' – ' . hr_fmt_date($f['to']) . ' · ' . count($rows) . ' requests',
            'cols' => [['#', 30, 'left'], ['Employee ID', 55, 'left'], ['Name', 95, 'left'], ['Department', 70, 'left'], ['Type', 75, 'left'],
                       ['From', 55, 'left'], ['To', 55, 'left'], ['Days', 32, 'right'], ['Status', 55, 'left'], ['Submitted', 55, 'left'],
                       ['Last action by', 80, 'left'], ['Comment', 90, 'left'], ['Reason', 100, 'left']], 'rows' => $rows, 'totals' => []];
}

function hr_report_dept_leave(array $f): array
{
    [$list] = hr_leave_list(['from' => $f['from'], 'to' => $f['to'], 'status' => 'approved'], 0, 10000);
    $types = [];
    $by = [];
    foreach ($list as $r) {
        $dept = $r['department'] ?: 'No department';
        $types[$r['type_name']] = true;
        $by[$dept][$r['type_name']] = ($by[$dept][$r['type_name']] ?? 0) + (float) $r['days'];
    }
    ksort($by);
    $types = array_keys($types);
    sort($types);
    $cols = [['Department', 110, 'left']];
    foreach ($types as $t) {
        $cols[] = [$t, 60, 'right'];
    }
    $cols[] = ['Total days', 60, 'right'];
    $rows = [];
    $tot = array_fill(0, count($types) + 1, 0.0);
    foreach ($by as $dept => $vals) {
        $r = [$dept];
        $sum = 0;
        foreach ($types as $i => $t) {
            $v = $vals[$t] ?? 0;
            $r[] = ['n' => $v];
            $sum += $v;
            $tot[$i] += $v;
        }
        $r[] = ['n' => $sum];
        $tot[count($types)] += $sum;
        $rows[] = $r;
    }
    $totals = array_merge(['Total'], array_map('hr_fmt_days', $tot));
    return ['subtitle' => 'Approved leave, ' . hr_fmt_date($f['from']) . ' – ' . hr_fmt_date($f['to']), 'cols' => $cols, 'rows' => $rows,
            'totals' => $rows ? $totals : []];
}

function hr_report_holiday_calendar(array $f): array
{
    $cals = $f['calendar'] ? [$f['calendar']] : array_keys(hr_calendars_map());
    $names = hr_calendars_map(false);
    $rows = [];
    foreach ($cals as $cid) {
        $from = $f['cal_year'] . '-01-01';
        $to = $f['cal_year'] . '-12-31';
        $seen = [];
        foreach (hr_holiday_dates([$cid], $from, $to, true) as $d => $h) {
            $k = $h['id'] . '|' . $h['name'];
            if (isset($seen[$k]) && $seen[$k][1] === date('Y-m-d', strtotime($d . ' -1 day'))) {
                $rows[$seen[$k][0]][2] = ['d' => $d];   // extend a multi-day holiday
                $seen[$k][1] = $d;
                continue;
            }
            $rows[] = [$names[$cid] ?? '', ['d' => $d], ['d' => $d], date('l', strtotime($d)), $h['name'],
                       hr_holiday_types()[$h['type']] ?? $h['type'], $h['optional'] ? 'Optional' : 'Mandatory'];
            $seen[$k] = [count($rows) - 1, $d];
        }
    }
    return ['subtitle' => 'Year ' . $f['cal_year'] . ($f['calendar'] ? ' · ' . ($names[$f['calendar']] ?? '') : ' · all calendars'),
            'cols' => [['Calendar', 90, 'left'], ['From', 60, 'left'], ['To', 60, 'left'], ['Day', 60, 'left'], ['Holiday', 150, 'left'],
                       ['Type', 85, 'left'], ['Observance', 60, 'left']], 'rows' => array_values($rows), 'totals' => []];
}

function hr_report_late_absence(array $f): array
{
    $emps = hr_att_employees(['department' => $f['department']], $f['from'], $f['to']);
    $grid = hr_att_grid($emps, $f['from'], $f['to']);
    $rows = [];
    foreach ($emps as $e) {
        foreach ($grid[(int) $e['id']] ?? [] as $d => [$st, $rec, $note]) {
            $late = $rec && $rec['late_minutes'] > 0;
            $early = $rec && $rec['early_minutes'] > 0;
            if (!$late && !$early && $st !== 'Absent' && $st !== 'Missing Check-in / Check-out') {
                continue;
            }
            $what = [];
            if ($st === 'Absent') { $what[] = 'Absent'; }
            if ($st === 'Missing Check-in / Check-out') { $what[] = 'Missing punch'; }
            if ($late) { $what[] = 'Late'; }
            if ($early) { $what[] = 'Left early'; }
            $rows[] = [['d' => $d], $e['emp_code'], $e['full_name'], (string) $e['department'], implode(', ', $what),
                       $rec && $rec['check_in'] ? date('H:i', strtotime($rec['check_in'])) : '',
                       $rec && $rec['check_out'] ? date('H:i', strtotime($rec['check_out'])) : '',
                       $late ? ['n' => (int) $rec['late_minutes']] : '', $early ? ['n' => (int) $rec['early_minutes']] : '', $note];
        }
    }
    return ['subtitle' => hr_fmt_date($f['from']) . ' – ' . hr_fmt_date($f['to']) . ' · ' . hr_dept_name($f['department']),
            'cols' => [['Date', 60, 'left'], ['Employee ID', 55, 'left'], ['Name', 100, 'left'], ['Department', 75, 'left'], ['Issue', 85, 'left'],
                       ['In', 35, 'left'], ['Out', 35, 'left'], ['Late (min)', 48, 'right'], ['Early (min)', 50, 'right'], ['Notes', 100, 'left']],
            'rows' => $rows, 'totals' => []];
}

function hr_report_overtime(array $f): array
{
    $sc = hr_scope_sql('attendance', 'view', 'e');
    $rows = [];
    $tot = 0;
    $where = ' WHERE a.org_id = ? AND a.overtime_minutes > 0 AND a.work_date BETWEEN ? AND ?' . ($f['department'] ? ' AND e.department_id = ' . (int) $f['department'] : '');
    foreach (fabo_all('SELECT a.*, e.full_name, e.emp_code, d.name AS department FROM hr_attendance a JOIN hr_employees e ON e.id = a.employee_id
                       LEFT JOIN hr_departments d ON d.id = e.department_id' . $where . $sc['sql'] . ' ORDER BY a.work_date, e.full_name',
                      'iss', [hr_org(), $f['from'], $f['to']]) as $a) {
        $rows[] = [['d' => $a['work_date']], $a['emp_code'], $a['full_name'], (string) $a['department'], $a['status'],
                   hr_fmt_minutes((int) $a['worked_minutes']), ['n' => round($a['overtime_minutes'] / 60, 2)],
                   $a['overtime_approved'] ? 'Approved' : 'Not approved'];
        $tot += (int) $a['overtime_minutes'];
    }
    return ['subtitle' => hr_fmt_date($f['from']) . ' – ' . hr_fmt_date($f['to']) . ' · ' . hr_dept_name($f['department']),
            'cols' => [['Date', 60, 'left'], ['Employee ID', 55, 'left'], ['Name', 110, 'left'], ['Department', 80, 'left'], ['Status', 80, 'left'],
                       ['Worked', 50, 'right'], ['Overtime (h)', 55, 'right'], ['Approval', 65, 'left']],
            'rows' => $rows, 'totals' => $rows ? ['Total', '', '', '', '', '', hr_fmt_days(round($tot / 60, 2)), ''] : []];
}

function hr_report_run(array $f): array
{
    $run = $f['run'] ? hr_run($f['run']) : (hr_runs(1)[0] ?? null);
    if (!$run) {
        throw new InvalidArgumentException('There is no payroll run yet.');
    }
    return $run;
}

function hr_report_payroll_register(array $f): array
{
    $run = hr_report_run($f);
    $items = hr_run_items((int) $run['id']);
    $codes = [];
    $byItem = [];
    foreach ($items as $it) {
        foreach (hr_item_lines((int) $it['id']) as $l) {
            $k = $l['kind'] . '|' . $l['name'];
            $codes[$k] = [$l['kind'], $l['name']];
            $byItem[(int) $it['id']][$k] = ($byItem[(int) $it['id']][$k] ?? 0) + (float) $l['amount'];
        }
    }
    $order = ['earning' => 1, 'reimbursement' => 2, 'earning_round' => 3, 'deduction' => 4, 'deduction_round' => 5, 'employer' => 6];
    uasort($codes, static fn($a, $b) => ($order[$a[0]] ?? 9) <=> ($order[$b[0]] ?? 9));
    $cols = [['Employee ID', 55, 'left'], ['Name', 95, 'left'], ['Department', 70, 'left'], ['Payable days', 45, 'right'], ['LOP days', 40, 'right']];
    foreach ($codes as [$kind, $name]) {
        $cols[] = [$name . ($kind === 'deduction' || $kind === 'deduction_round' ? ' (−)' : ($kind === 'employer' ? ' (employer)' : '')), 55, 'right'];
    }
    array_push($cols, ['Gross', 60, 'right'], ['Deductions', 60, 'right'], ['Net pay', 60, 'right'], ['Payment', 50, 'left']);
    $rows = [];
    $tot = [];
    foreach ($items as $it) {
        $r = [$it['emp_code'], $it['full_name'], (string) $it['department'], ['n' => (float) $it['payable_days']], ['n' => (float) $it['lop_days']]];
        $i = 5;
        foreach (array_keys($codes) as $k) {
            $v = $byItem[(int) $it['id']][$k] ?? 0;
            $r[] = ['m' => $v];
            $tot[$i] = ($tot[$i] ?? 0) + $v;
            $i++;
        }
        foreach (['gross', 'deductions', 'net'] as $k) {
            $r[] = ['m' => (float) $it[$k]];
            $tot[$i] = ($tot[$i] ?? 0) + (float) $it[$k];
            $i++;
        }
        $r[] = $it['hold'] ? 'On hold' : ucfirst(str_replace('_', ' ', $it['payment_status']));
        $rows[] = $r;
    }
    $totals = ['Total'];
    foreach ($tot as $i => $v) {
        $totals[$i] = fabo_thousands($v, 2);
    }
    return ['subtitle' => date('F Y', strtotime($run['period'] . '-01')) . ' · ' . hr_run_statuses()[$run['status']] . ' · ' . count($rows) . ' employees',
            'cols' => $cols, 'rows' => $rows, 'totals' => $rows ? $totals : []];
}

function hr_report_salary_deductions(array $f): array
{
    $run = hr_report_run($f);
    $rows = [];
    $tot = 0.0;
    foreach (hr_run_items((int) $run['id']) as $it) {
        foreach (hr_item_lines((int) $it['id']) as $l) {
            if ($l['kind'] !== 'deduction' && $l['kind'] !== 'deduction_round') {
                continue;
            }
            $rows[] = [$it['emp_code'], $it['full_name'], (string) $it['department'], $l['name'], (string) $l['basis'], ['m' => (float) $l['amount']]];
            $tot += (float) $l['amount'];
        }
        if ((float) $it['lop_days'] > 0) {
            $rows[] = [$it['emp_code'], $it['full_name'], (string) $it['department'], 'Loss of pay (reduces earnings)',
                       hr_fmt_days($it['lop_days']) . ' day(s) of ' . hr_fmt_days($it['divisor_days']), ''];
        }
    }
    return ['subtitle' => date('F Y', strtotime($run['period'] . '-01')) . ' · ' . hr_run_statuses()[$run['status']],
            'cols' => [['Employee ID', 55, 'left'], ['Name', 105, 'left'], ['Department', 75, 'left'], ['Deduction', 110, 'left'],
                       ['Basis', 170, 'left'], ['Amount', 65, 'right']],
            'rows' => $rows, 'totals' => $rows ? ['Total', '', '', '', '', fabo_thousands($tot, 2)] : []];
}

function hr_report_unpaid_leave(array $f): array
{
    [$list] = hr_leave_list(['from' => $f['from'], 'to' => $f['to'], 'status' => 'approved', 'department' => $f['department']], 0, 10000);
    $rows = [];
    $tot = 0.0;
    foreach ($list as $r) {
        if ($r['is_paid']) {
            continue;
        }
        $rows[] = [$r['emp_code'], $r['full_name'], (string) $r['department'], $r['type_name'], ['d' => $r['start_date']], ['d' => $r['end_date']],
                   ['n' => (float) $r['days']], (string) $r['reason']];
        $tot += (float) $r['days'];
    }
    // Absences with no leave count as unpaid too when payroll deducts them.
    $emps = hr_att_employees(['department' => $f['department']], $f['from'], $f['to']);
    $grid = hr_att_grid($emps, $f['from'], $f['to']);
    foreach ($emps as $e) {
        $abs = 0;
        foreach ($grid[(int) $e['id']] ?? [] as [$st]) {
            if ($st === 'Absent') {
                $abs++;
            }
        }
        if ($abs) {
            $rows[] = [$e['emp_code'], $e['full_name'], (string) $e['department'], 'Absent without leave', ['d' => $f['from']], ['d' => $f['to']],
                       ['n' => $abs], 'From attendance'];
            $tot += $abs;
        }
    }
    return ['subtitle' => hr_fmt_date($f['from']) . ' – ' . hr_fmt_date($f['to']) . ' · ' . hr_dept_name($f['department']),
            'cols' => [['Employee ID', 55, 'left'], ['Name', 105, 'left'], ['Department', 75, 'left'], ['Kind', 110, 'left'], ['From', 60, 'left'],
                       ['To', 60, 'left'], ['Days', 40, 'right'], ['Reason', 120, 'left']],
            'rows' => $rows, 'totals' => $rows ? ['Total', '', '', '', '', '', hr_fmt_days($tot), ''] : []];
}

function hr_report_dashboard_summary(array $f): array
{
    $s = hr_dashboard_stats(['month' => $f['month']]);
    $rows = [];
    foreach ($s['cards'] as $c) {
        $rows[] = [$c['label'], is_numeric($c['value']) ? ['n' => $c['value']] : (string) $c['value'], (string) ($c['note'] ?? '')];
    }
    foreach ($s['by_dept'] as $d => $n) {
        $rows[] = ['Employees in ' . $d, ['n' => $n], ''];
    }
    foreach ($s['leave_by_type'] as $t => $n) {
        $rows[] = ['Approved ' . $t . ' days (' . date('F Y', strtotime($f['month'] . '-01')) . ')', ['n' => $n], ''];
    }
    return ['subtitle' => 'As of ' . date('d M Y H:i') . ' · month ' . date('F Y', strtotime($f['month'] . '-01')),
            'cols' => [['Measure', 260, 'left'], ['Value', 80, 'right'], ['Note', 180, 'left']], 'rows' => $rows, 'totals' => []];
}

/* ------------------------------------------- people, talent, workplace */

function hr_report_headcount(array $f): array
{
    $sc = hr_scope_sql('employees', 'view', 'e');
    $dept = $f['department'] ? ' AND e.department_id = ' . (int) $f['department'] : '';
    $emps = fabo_all('SELECT e.date_of_joining, e.date_of_leaving, e.status FROM hr_employees e WHERE e.org_id = ?' . $dept . $sc['sql'], 'i', [hr_org()]);
    $year = $f['cal_year'];
    $rows = [];
    $tj = $tl = 0;
    for ($m = 1; $m <= 12; $m++) {
        $from = sprintf('%04d-%02d-01', $year, $m);
        $to = date('Y-m-t', strtotime($from));
        if ($from > date('Y-m-d')) {
            break;
        }
        $open = $join = $leave = 0;
        foreach ($emps as $e) {
            $doj = $e['date_of_joining'] ?: '1900-01-01';
            $dol = $e['date_of_leaving'] && !in_array($e['status'], hr_working_statuses(), true) ? $e['date_of_leaving'] : null;
            if ($doj < $from && (!$dol || $dol >= $from)) { $open++; }
            if ($doj >= $from && $doj <= $to) { $join++; }
            if ($dol && $dol >= $from && $dol <= $to) { $leave++; }
        }
        $close = $open + $join - $leave;
        $avg = ($open + $close) / 2;
        $tj += $join;
        $tl += $leave;
        $rows[] = [date('F Y', strtotime($from)), ['n' => $open], ['n' => $join], ['n' => $leave], ['n' => $close],
                   $avg > 0 ? number_format($leave * 100 / $avg, 1) . '%' : '—'];
    }
    return ['subtitle' => $year . ' · ' . hr_dept_name($f['department']) . ' · attrition = leavers ÷ average headcount in the month',
            'cols' => [['Month', 110, 'left'], ['Opening', 70, 'right'], ['Joined', 70, 'right'], ['Left', 70, 'right'], ['Closing', 70, 'right'], ['Attrition', 70, 'right']],
            'rows' => $rows, 'totals' => ['Year', '', (string) $tj, (string) $tl, '', '']];
}

function hr_report_recruitment_pipeline(array $f): array
{
    $rows = [];
    foreach (hr_openings() as $o) {
        $n = [];
        foreach (fabo_all('SELECT stage, COUNT(*) c FROM hr_candidates WHERE opening_id = ? GROUP BY stage', 'i', [(int) $o['id']]) as $r) {
            $n[$r['stage']] = (int) $r['c'];
        }
        $rows[] = [$o['title'], (string) $o['department'], hr_opening_statuses()[$o['status']], ['n' => $o['positions']], ['n' => array_sum($n)],
                   ['n' => $n['screening'] ?? 0], ['n' => $n['interview'] ?? 0], ['n' => $n['offer'] ?? 0], ['n' => $n['hired'] ?? 0],
                   ['n' => ($n['rejected'] ?? 0) + ($n['withdrawn'] ?? 0)], ['d' => (string) $o['target_date']]];
    }
    return ['subtitle' => count($rows) . ' openings · as of ' . date('d M Y'),
            'cols' => [['Opening', 120, 'left'], ['Department', 80, 'left'], ['Status', 55, 'left'], ['Positions', 45, 'right'], ['Candidates', 50, 'right'],
                       ['Screening', 50, 'right'], ['Interview', 50, 'right'], ['Offer', 40, 'right'], ['Hired', 40, 'right'], ['Out', 35, 'right'], ['Fill by', 55, 'left']],
            'rows' => $rows, 'totals' => []];
}

function hr_report_exits_register(array $f): array
{
    $sc = hr_scope_sql('lifecycle', 'view', 'e');
    $rows = [];
    foreach (fabo_all("SELECT s.*, e.full_name, e.emp_code, e.date_of_joining, d.name AS department FROM hr_separations s JOIN hr_employees e ON e.id = s.employee_id
                       LEFT JOIN hr_departments d ON d.id = e.department_id WHERE s.org_id = ? AND s.status IN ('submitted','approved','completed')
                       AND COALESCE(s.approved_lwd, s.requested_lwd) BETWEEN ? AND ?" . ($f['department'] ? ' AND e.department_id = ' . (int) $f['department'] : '') . $sc['sql']
                       . ' ORDER BY COALESCE(s.approved_lwd, s.requested_lwd)', 'iss', [hr_org(), $f['from'], $f['to']]) as $s) {
        $lwd = $s['approved_lwd'] ?: $s['requested_lwd'];
        $tenure = $s['date_of_joining'] ? round((strtotime($lwd) - strtotime($s['date_of_joining'])) / 86400 / 365.25, 1) : null;
        $rows[] = [$s['emp_code'], $s['full_name'], (string) $s['department'], hr_separation_types()[$s['type']], ['d' => $s['notice_date']], ['d' => $lwd],
                   $tenure !== null ? $tenure . ' yrs' : '', hr_separation_statuses()[$s['status']], hr_fnf_statuses()[$s['fnf_status']],
                   $s['rehire_eligible'] === null ? '' : ($s['rehire_eligible'] ? 'Yes' : 'No'), mb_strimwidth((string) $s['reason'], 0, 120, '…')];
    }
    return ['subtitle' => 'Last working day ' . hr_fmt_date($f['from']) . ' – ' . hr_fmt_date($f['to']) . ' · ' . hr_dept_name($f['department']) . ' · ' . count($rows) . ' exits',
            'cols' => [['Emp ID', 50, 'left'], ['Name', 95, 'left'], ['Department', 70, 'left'], ['Type', 60, 'left'], ['Notice', 55, 'left'], ['Last day', 55, 'left'],
                       ['Tenure', 40, 'right'], ['Status', 55, 'left'], ['Settlement', 50, 'left'], ['Rehire', 35, 'left'], ['Reason', 120, 'left']],
            'rows' => $rows, 'totals' => []];
}

function hr_report_claims_register(array $f): array
{
    $rows = [];
    $tot = $totA = 0.0;
    $deptName = $f['department'] ? (hr_departments_map(false)[$f['department']] ?? '') : '';
    foreach (hr_claims_list(['from' => $f['from'], 'to' => $f['to'], 'status' => $f['status']], 5000) as $c) {
        if ($deptName !== '' && $c['department'] !== $deptName) {
            continue;
        }
        $ok = in_array($c['status'], ['approved', 'paid'], true);
        $tot += (float) $c['amount'];
        $totA += $ok ? (float) ($c['approved_amount'] ?? $c['amount']) : 0;
        $rows[] = [$c['number'], $c['emp_code'], $c['full_name'], (string) $c['department'], (string) $c['category'], ['d' => $c['expense_date']], $c['description'],
                   ['m' => $c['amount']], ['m' => $ok ? ($c['approved_amount'] ?? $c['amount']) : 0],
                   hr_claim_statuses()[$c['status']], $c['status'] === 'paid' ? ($c['paid_via'] === 'payroll' ? 'Payroll ' . substr((string) $c['paid_on'], 0, 7) : hr_fmt_date($c['paid_on'])) : ''];
    }
    return ['subtitle' => 'Spent ' . hr_fmt_date($f['from']) . ' – ' . hr_fmt_date($f['to']) . ' · ' . ($f['status'] !== '' ? (hr_claim_statuses()[$f['status']] ?? '') : 'All statuses') . ' · ' . count($rows) . ' claims',
            'cols' => [['Claim', 60, 'left'], ['Emp ID', 45, 'left'], ['Name', 80, 'left'], ['Department', 60, 'left'], ['Category', 55, 'left'], ['Spent on', 50, 'left'],
                       ['Description', 120, 'left'], ['Claimed', 55, 'right'], ['Approved', 55, 'right'], ['Status', 45, 'left'], ['Paid', 55, 'left']],
            'rows' => $rows, 'totals' => ['Total', '', '', '', '', '', '', fabo_money_exact($tot), fabo_money_exact($totA), '', '']];
}

function hr_report_asset_register(array $f): array
{
    $rows = [];
    $tot = 0.0;
    foreach (hr_assets_list([]) as $a) {
        $tot += (float) $a['cost'];
        $rows[] = [$a['tag'], $a['name'], (string) $a['category'], (string) $a['make_model'], (string) $a['serial'], hr_asset_statuses()[$a['status']],
                   $a['employee_id'] ? $a['full_name'] . ' (' . $a['emp_code'] . ')' : '', ['d' => (string) $a['purchase_date']], ['m' => $a['cost'] ?? 0],
                   ['d' => (string) $a['warranty_until']]];
    }
    return ['subtitle' => count($rows) . ' assets · as of ' . date('d M Y'),
            'cols' => [['Tag', 50, 'left'], ['Asset', 100, 'left'], ['Category', 60, 'left'], ['Make / model', 80, 'left'], ['Serial', 70, 'left'], ['Status', 50, 'left'],
                       ['Held by', 100, 'left'], ['Purchased', 55, 'left'], ['Cost', 55, 'right'], ['Warranty', 55, 'left']],
            'rows' => $rows, 'totals' => ['Total', '', '', '', '', '', '', '', fabo_money_exact($tot), '']];
}

function hr_report_training_register(array $f): array
{
    $sc = hr_scope_sql('training', 'view', 'e');
    $rows = [];
    $hours = 0.0;
    foreach (fabo_all('SELECT x.*, t.title, t.start_date, t.hours, t.mode, t.mandatory, e.full_name, e.emp_code, d.name AS department
                       FROM hr_training_enrolments x JOIN hr_trainings t ON t.id = x.training_id JOIN hr_employees e ON e.id = x.employee_id
                       LEFT JOIN hr_departments d ON d.id = e.department_id WHERE x.org_id = ? AND t.start_date BETWEEN ? AND ?'
                       . ($f['department'] ? ' AND e.department_id = ' . (int) $f['department'] : '') . $sc['sql'] . ' ORDER BY t.start_date, e.full_name',
                      'iss', [hr_org(), $f['from'], $f['to']]) as $x) {
        $hours += $x['status'] === 'completed' ? (float) $x['hours'] : 0;
        $rows[] = [$x['emp_code'], $x['full_name'], (string) $x['department'], $x['title'], ['d' => $x['start_date']], hr_training_modes()[$x['mode']] ?? $x['mode'],
                   $x['mandatory'] ? 'Yes' : 'No', hr_enrolment_statuses()[$x['status']], ['n' => $x['status'] === 'completed' ? (float) $x['hours'] : 0],
                   $x['score'] !== null ? hr_fmt_days($x['score']) : '', $x['rating'] ? $x['rating'] . '/5' : ''];
    }
    return ['subtitle' => hr_fmt_date($f['from']) . ' – ' . hr_fmt_date($f['to']) . ' · ' . hr_dept_name($f['department']),
            'cols' => [['Emp ID', 45, 'left'], ['Name', 85, 'left'], ['Department', 65, 'left'], ['Session', 110, 'left'], ['Date', 50, 'left'], ['Mode', 55, 'left'],
                       ['Mandatory', 45, 'left'], ['Status', 50, 'left'], ['Hours', 40, 'right'], ['Score', 35, 'right'], ['Rating', 35, 'right']],
            'rows' => $rows, 'totals' => ['Total', '', '', '', '', '', '', '', hr_fmt_days($hours), '', '']];
}

function hr_report_performance_ratings(array $f): array
{
    $sc = hr_scope_sql('performance', 'view', 'e');
    $rows = [];
    foreach (fabo_all('SELECT r.*, c.name AS cycle, e.full_name, e.emp_code, d.name AS department, m.full_name AS reviewer FROM hr_reviews r
                       JOIN hr_review_cycles c ON c.id = r.cycle_id JOIN hr_employees e ON e.id = r.employee_id
                       LEFT JOIN hr_departments d ON d.id = e.department_id LEFT JOIN hr_employees m ON m.id = r.reviewer_id
                       WHERE r.org_id = ? AND YEAR(c.period_to) = ?' . ($f['department'] ? ' AND e.department_id = ' . (int) $f['department'] : '') . $sc['sql']
                       . ' ORDER BY c.period_to, e.full_name', 'ii', [hr_org(), $f['cal_year']]) as $r) {
        $rows[] = [$r['cycle'], $r['emp_code'], $r['full_name'], (string) $r['department'], (string) $r['reviewer'], hr_review_stages()[$r['stage']],
                   $r['self_rating'] !== null ? (string) $r['self_rating'] : '', $r['manager_rating'] !== null ? (string) $r['manager_rating'] : '',
                   $r['final_rating'] !== null ? hr_rating_words($r['final_rating']) : '', (string) $r['final_note']];
    }
    return ['subtitle' => 'Cycles ending in ' . $f['cal_year'] . ' · ' . hr_dept_name($f['department']) . ' · ' . count($rows) . ' reviews',
            'cols' => [['Cycle', 80, 'left'], ['Emp ID', 45, 'left'], ['Name', 85, 'left'], ['Department', 65, 'left'], ['Reviewer', 75, 'left'], ['Stage', 60, 'left'],
                       ['Self', 30, 'right'], ['Manager', 40, 'right'], ['Final', 90, 'left'], ['Calibration note', 100, 'left']],
            'rows' => $rows, 'totals' => []];
}

/** Sends a built report as xlsx, csv or pdf. */
function hr_report_send(array $r, string $fmt, string $key): void
{
    $file = $key . '-' . date('Ymd-His');
    $head = array_map(static fn($c) => $c[0], $r['cols']);
    if ($fmt === 'pdf') {
        $plain = array_map(static function ($row) {
            return array_map(static function ($c) {
                if (is_array($c)) {
                    $k = array_key_first($c);
                    $v = $c[$k];
                    return match ($k) {
                        'm' => fabo_thousands((float) $v, 2),
                        'n' => hr_fmt_days($v),
                        'd' => $v ? date('d M Y', strtotime((string) $v)) : '',
                        default => (string) $v,
                    };
                }
                return (string) $c;
            }, $row);
        }, $r['rows']);
        hr_audit('export', 'report', null, $r['title'] . ' (PDF)');
        hr_pdf_table($r['title'], $r['subtitle'], $r['cols'], $plain, $r['totals'])->send($file . '.pdf');
    }
    if ($fmt === 'csv') {
        hr_audit('export', 'report', null, $r['title'] . ' (CSV)');
        $rows = [$head];
        foreach ($r['rows'] as $row) {
            $rows[] = array_map(static fn($c) => is_array($c) ? reset($c) : $c, $row);
        }
        if ($r['totals']) {
            $t = [];
            foreach (array_keys($head) as $i) { $t[] = $r['totals'][$i] ?? ''; }
            $rows[] = $t;
        }
        hr_csv_download($file . '.csv', $rows);
    }
    hr_audit('export', 'report', null, $r['title'] . ' (Excel)');
    $x = new FaboXlsx();
    $rows = [$head];
    foreach ($r['rows'] as $row) {
        $rows[] = array_map(static fn($c) => is_array($c) || !is_string($c) ? $c : FaboXlsx::sanitiseText($c), $row);
    }
    if ($r['totals']) {
        $t = [];
        foreach (array_keys($head) as $i) { $t[] = (string) ($r['totals'][$i] ?? ''); }
        $rows[] = $t;
    }
    $x->addSheet(mb_substr($r['title'], 0, 31), $rows);
    $x->download($file . '.xlsx');
    exit;
}

/* ------------------------------------------------------------ dashboard */

/**
 * The dashboard's figures, all live. $f: month (YYYY-MM), department,
 * designation, status, employee — the same filters the page offers.
 */
function hr_dashboard_stats(array $f): array
{
    $org   = hr_org();
    $today = date('Y-m-d');
    $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) ($f['month'] ?? '')) ? $f['month'] : date('Y-m');
    $mFrom = $month . '-01';
    $mTo   = min(date('Y-m-t', strtotime($mFrom)), max($today, $mFrom));
    $flt   = ['department' => (int) ($f['department'] ?? 0), 'designation' => (int) ($f['designation'] ?? 0),
              'status' => (string) ($f['status'] ?? ''), 'employee' => (int) ($f['employee'] ?? 0)];

    $where = ' WHERE e.org_id = ?';
    $types = 'i';
    $params = [$org];
    foreach (['department' => 'department_id', 'designation' => 'designation_id', 'employee' => 'id'] as $k => $col) {
        if ($flt[$k]) {
            $where .= ' AND e.' . $col . ' = ' . (int) $flt[$k];
        }
    }
    if ($flt['status'] !== '' && isset(hr_emp_statuses()[$flt['status']])) {
        $where .= ' AND e.status = ?';
        $types .= 's';
        $params[] = $flt['status'];
    }
    $sc = hr_scope_sql('dashboard', 'view', 'e');
    $where .= $sc['sql'];
    $all = fabo_all('SELECT e.*, d.name AS department FROM hr_employees e LEFT JOIN hr_departments d ON d.id = e.department_id' . $where,
                    $types, $params);
    $working = array_values(array_filter($all, static fn($e) => in_array($e['status'], hr_working_statuses(), true)
                                                             && (!$e['date_of_joining'] || $e['date_of_joining'] <= $today)));
    $ids = array_map(static fn($e) => (int) $e['id'], $working);

    $todayGrid = hr_att_grid($working, $today, $today);
    $present = $absent = $leave = $late = $notMarked = 0;
    foreach ($working as $e) {
        [$st, $rec, $note] = $todayGrid[(int) $e['id']][$today];
        if (hr_att_present_value($st) > 0) { $present++; }
        if ($st === 'Absent') { $absent++; }
        if ($st === 'On Leave' || ($st === 'Half Day' && !$rec)) { $leave++; }
        if ($rec && $rec['late_minutes'] > 0) { $late++; }
        if ($st === '' && $note === 'Not marked yet') { $notMarked++; }
    }
    $inIds = $ids ? implode(',', $ids) : '0';
    $pendLeave = (int) (fabo_one("SELECT COUNT(*) n FROM hr_leave_requests WHERE org_id = ? AND status = 'submitted' AND employee_id IN ($inIds)", 'i', [$org])['n'] ?? 0);
    $pendCorr  = (int) (fabo_one("SELECT COUNT(*) n FROM hr_attendance_corrections WHERE org_id = ? AND status = 'submitted' AND employee_id IN ($inIds)", 'i', [$org])['n'] ?? 0);

    $calIds = array_keys(hr_calendars_map());
    $upcoming = [];
    foreach (hr_holiday_dates($calIds, $today, date('Y-m-d', strtotime('+60 day')), true) as $d => $h) {
        $upcoming[] = ['date' => $d, 'name' => $h['name'], 'optional' => $h['optional']];
    }
    $run = fabo_one('SELECT * FROM hr_payroll_runs WHERE org_id = ? ORDER BY period DESC LIMIT 1', 'i', [$org]);
    $canPay = hr_can('payroll', 'view') && hr_scope('payroll', 'view') === 'org';

    $cards = [
        ['key' => 'total', 'label' => 'Total Employees', 'value' => count($all), 'note' => 'All statuses', 'href' => hr_url('employees', ['status' => ''])],
        ['key' => 'active', 'label' => 'Active Employees', 'value' => count($working), 'note' => 'Active, probation, notice', 'href' => hr_url('employees')],
        ['key' => 'present', 'label' => 'Present Today', 'value' => $present, 'note' => $notMarked ? $notMarked . ' not marked yet' : '', 'href' => hr_url('attendance', ['date' => $today])],
        ['key' => 'absent', 'label' => 'Absent Today', 'value' => $absent, 'note' => '', 'href' => hr_url('attendance', ['date' => $today])],
        ['key' => 'leave', 'label' => 'On Leave Today', 'value' => $leave, 'note' => '', 'href' => hr_url('leave', ['tab' => 'calendar'])],
        ['key' => 'late', 'label' => 'Late Arrivals Today', 'value' => $late, 'note' => '', 'href' => hr_url('attendance', ['date' => $today])],
        ['key' => 'pending_leave', 'label' => 'Pending Leave Requests', 'value' => $pendLeave, 'note' => '', 'href' => hr_url('leave', ['tab' => 'approvals'])],
        ['key' => 'holidays', 'label' => 'Upcoming Holidays', 'value' => count($upcoming), 'note' => 'Next 60 days', 'href' => hr_url('holidays')],
        ['key' => 'corrections', 'label' => 'Pending Attendance Corrections', 'value' => $pendCorr, 'note' => '', 'href' => hr_url('attendance', ['tab' => 'corrections'])],
    ];
    if ($canPay) {
        $cards[] = ['key' => 'payroll', 'label' => 'Payroll Status', 'value' => $run ? hr_run_statuses()[$run['status']] : 'Not started',
                    'note' => $run ? date('F Y', strtotime($run['period'] . '-01')) : '', 'href' => hr_url('payroll')];
    }

    // Six months of attendance, absences and lates.
    $trend = ['cats' => [], 'present' => [], 'absent' => [], 'late' => [], 'leave' => []];
    for ($i = 5; $i >= 0; $i--) {
        $m = date('Y-m', strtotime($mFrom . ' -' . $i . ' month'));
        $from = $m . '-01';
        $to = min(date('Y-m-t', strtotime($from)), $today);
        $trend['cats'][] = date('M', strtotime($from));
        if ($from > $today) {
            foreach (['present', 'absent', 'late', 'leave'] as $k) { $trend[$k][] = 0; }
            continue;
        }
        $emps = array_values(array_filter($all, static fn($e) => (!$e['date_of_joining'] || $e['date_of_joining'] <= $to)
                                                              && (!$e['date_of_leaving'] || $e['date_of_leaving'] >= $from)
                                                              && $e['status'] !== 'inactive'));
        $g = hr_att_grid($emps, $from, $to);
        $p = $a = $l = $lv = 0;
        foreach ($g as $days) {
            $t = hr_att_totals($days);
            $p += $t['present'];
            $a += $t['absent'];
            $l += $t['late'];
            $lv += $t['leave'];
        }
        $trend['present'][] = $p;
        $trend['absent'][] = $a;
        $trend['late'][] = $l;
        $trend['leave'][] = $lv;
    }

    $byDept = [];
    foreach ($working as $e) {
        $d = $e['department'] ?: 'No department';
        $byDept[$d] = ($byDept[$d] ?? 0) + 1;
    }
    arsort($byDept);

    $leaveByType = [];
    foreach (hr_approved_leave_days($ids, $mFrom, date('Y-m-t', strtotime($mFrom))) as $days) {
        foreach ($days as $x) {
            $leaveByType[$x['name']] = ($leaveByType[$x['name']] ?? 0) + ($x['half'] ? 0.5 : 1);
        }
    }
    arsort($leaveByType);

    $payTrend = ['cats' => [], 'gross' => [], 'net' => []];
    if ($canPay) {
        foreach (array_reverse(fabo_all("SELECT r.period, SUM(i.gross) g, SUM(i.net) n, SUM(i.employer_cost) c FROM hr_payroll_runs r
                                         JOIN hr_payroll_items i ON i.run_id = r.id WHERE r.org_id = ?
                                         AND r.status IN ('approved','finalized','payslips') GROUP BY r.period ORDER BY r.period DESC LIMIT 6",
                                        'i', [$org])) as $p) {
            $payTrend['cats'][] = date('M y', strtotime($p['period'] . '-01'));
            $payTrend['gross'][] = (float) $p['g'] + (float) $p['c'];
            $payTrend['net'][] = (float) $p['n'];
        }
    }

    $events = [];
    foreach ($working as $e) {
        foreach (['date_of_birth' => 'Birthday', 'date_of_joining' => 'Work anniversary'] as $col => $what) {
            if (!$e[$col]) {
                continue;
            }
            $md = substr($e[$col], 5);
            $next = date('Y') . '-' . $md;
            if ($md === '02-29' && !checkdate(2, 29, (int) date('Y'))) {
                $next = date('Y') . '-02-28';
            }
            if ($next < $today) {
                $next = (date('Y') + 1) . '-' . $md;
            }
            if ($next <= date('Y-m-d', strtotime('+30 day')) && ($col === 'date_of_birth' || substr($e[$col], 0, 4) < substr($next, 0, 4))) {
                $years = (int) substr($next, 0, 4) - (int) substr($e[$col], 0, 4);
                $events[] = ['date' => $next, 'name' => $e['full_name'], 'what' => $what . ($col === 'date_of_joining' ? ' (' . $years . ' yr)' : '')];
            }
        }
    }
    usort($events, static fn($a, $b) => strcmp($a['date'], $b['date']));

    return ['cards' => $cards, 'trend' => $trend, 'by_dept' => $byDept, 'leave_by_type' => $leaveByType, 'pay_trend' => $payTrend,
            'upcoming' => array_slice($upcoming, 0, 8), 'events' => array_slice($events, 0, 10), 'month' => $month, 'run' => $run,
            'can_pay' => $canPay];
}

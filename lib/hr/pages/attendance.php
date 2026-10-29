<?php
/**
 * Attendance Management — the daily register, the monthly calendar,
 * corrections, import, and month locking.
 */

$flash = null;
$importResult = null;
$self = hr_self_emp();

/* --------------------------------------------------------------- actions */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    $back = hr_url('attendance', array_filter(['tab' => $_GET['tab'] ?? '', 'date' => $_GET['date'] ?? '', 'month' => $_GET['month'] ?? '',
                                               'department' => $_GET['department'] ?? '']));
    if ($a === 'save') {
        $empId = (int) ($_POST['employee_id'] ?? 0);
        $date = (string) ($_POST['work_date'] ?? '');
        $exists = $date !== '' && hr_attendance_row($empId, hr_date($date) ?? '');
        hr_need_emp($empId, 'attendance', $exists ? 'edit' : 'create');
        if (hr_scope('attendance', $exists ? 'edit' : 'create') === 'self') {
            fabo_deny('Ask for a correction instead.', 403);
        }
        $flash = hr_action(static function () use ($empId) {
            $date = hr_date($_POST['work_date'] ?? '', true, 'Date');
            hr_attendance_save($empId, $date, $_POST, 'manual');
            return 'Attendance saved for ' . hr_fmt_date($date) . '.';
        }, $back);
    } elseif ($a === 'bulk') {
        hr_need('attendance', 'create');
        $flash = hr_action(static function () {
            $date = hr_date($_POST['work_date'] ?? '', true, 'Date');
            $status = (string) ($_POST['status'] ?? '');
            $ids = array_map('intval', (array) ($_POST['emp'] ?? []));
            if (!$ids) { throw new InvalidArgumentException('Tick at least one employee.'); }
            if ($status === '') { throw new InvalidArgumentException('Choose the status to mark.'); }
            $n = 0;
            $skip = [];
            foreach ($ids as $eid) {
                if (!hr_emp_allowed($eid, 'attendance', 'create') || hr_scope('attendance', 'create') === 'self') { continue; }
                try {
                    hr_attendance_save($eid, $date, ['status' => $status, 'check_in' => $_POST['check_in'] ?? '', 'check_out' => $_POST['check_out'] ?? ''], 'manual');
                    $n++;
                } catch (InvalidArgumentException $e) {
                    $skip[] = (hr_employee($eid)['full_name'] ?? '#' . $eid) . ': ' . $e->getMessage();
                }
            }
            if ($skip && !$n) { throw new InvalidArgumentException(implode(' ', array_slice($skip, 0, 3))); }
            return $n . ' marked ' . $status . '.' . ($skip ? ' Skipped ' . count($skip) . ': ' . implode(' ', array_slice($skip, 0, 3)) : '');
        }, $back);
    } elseif ($a === 'delete') {
        $flash = hr_action(static function () { hr_attendance_delete((int) ($_POST['id'] ?? 0)); return 'Record removed. The day is now worked out from leave, holidays and settings.'; }, $back);
    } elseif ($a === 'ot') {
        $rec = fabo_one('SELECT * FROM hr_attendance WHERE id = ? AND org_id = ?', 'ii', [(int) ($_POST['id'] ?? 0), hr_org()]);
        if (!$rec) { $flash = ['err', 'That record no longer exists.']; }
        else {
            hr_need_emp((int) $rec['employee_id'], 'attendance', 'approve');
            $flash = hr_action(static function () use ($rec) {
                if ($why = hr_locked_reason($rec['work_date'], $rec['work_date'])) { throw new InvalidArgumentException($why); }
                $v = !empty($_POST['approve']) ? 1 : 0;
                fabo_exec('UPDATE hr_attendance SET overtime_approved = ? WHERE id = ?', 'ii', [$v, (int) $rec['id']]);
                hr_audit($v ? 'ot_approve' : 'ot_unapprove', 'attendance', (int) $rec['id'], 'Overtime ' . ($v ? 'approved' : 'unapproved') . ' for ' . $rec['work_date']);
                return 'Overtime ' . ($v ? 'approved.' : 'set back to not approved.');
            }, $back);
        }
    } elseif ($a === 'comp_off') {
        $flash = hr_action(static function () {
            hr_comp_off_grant((int) ($_POST['id'] ?? 0), (string) ($_POST['days'] ?? '1'), trim((string) ($_POST['note'] ?? '')));
            return 'Compensatory off credited.';
        }, $back);
    } elseif ($a === 'correction') {
        $flash = hr_action(static function () {
            $empId = (int) ($_POST['employee_id'] ?? 0);
            hr_att_correction_request($empId, $_POST);
            return 'Correction requested. You will be notified of the decision.';
        }, hr_url('attendance', ['tab' => 'corrections']));
    } elseif ($a === 'decide_correction') {
        hr_need('attendance', 'approve');
        $flash = hr_action(static function () {
            $d = (string) ($_POST['decision'] ?? '');
            hr_att_correction_decide((int) ($_POST['id'] ?? 0), $d === 'approve' ? 'approve' : 'reject', (string) ($_POST['comment'] ?? ''));
            return $d === 'approve' ? 'Correction approved and the day updated.' : 'Correction rejected.';
        }, hr_url('attendance', ['tab' => 'corrections']));
    } elseif ($a === 'lock' || $a === 'reopen') {
        if (!hr_is_admin() && !(hr_can('attendance', 'configure') && hr_scope('attendance', 'configure') === 'org')) {
            fabo_deny('You cannot lock or reopen attendance months.', 403);
        }
        $flash = hr_action(static function () use ($a) {
            hr_att_lock((string) ($_POST['period'] ?? ''), $a === 'lock', trim((string) ($_POST['reason'] ?? '')));
            return $a === 'lock' ? 'Month locked. Attendance and leave in it can no longer change.' : 'Month reopened.';
        }, $back);
    } elseif ($a === 'import') {
        hr_need('attendance', 'create');
        if (hr_scope('attendance', 'create') !== 'org') { fabo_deny('Importing needs organisation-wide rights.', 403); }
        try {
            [$path, $name] = hr_uploaded_table('file');
            $dry = empty($_POST['commit']);
            $importResult = [hr_attendance_import($path, $name, !empty($_POST['overwrite']), $dry), $dry];
        } catch (Throwable $e) {
            $flash = ['err', fabo_user_error($e)];
        }
    } elseif ($a === 'bio_upload' || $a === 'bio_apply' || $a === 'bio_cancel') {
        hr_need('attendance', 'create');
        if (hr_scope('attendance', 'create') !== 'org') { fabo_deny('Importing needs organisation-wide rights.', 403); }
        require_once __DIR__ . '/../biometric.php';
        if ($a === 'bio_upload') {
            try {
                [$path, $name] = hr_uploaded_table('file');
                $parsed = hr_bio_parse(hr_read_table_file($path, $name));
                if (!$parsed['rows']) {
                    throw new InvalidArgumentException('The report has no attendance rows in it.');
                }
                hr_redirect(hr_url('attendance', ['tab' => 'import', 'bio' => hr_bio_park($parsed, $name)]));
            } catch (Throwable $e) {
                $flash = ['err', fabo_user_error($e)];
            }
        } elseif ($a === 'bio_cancel') {
            hr_bio_discard((string) ($_POST['bio'] ?? ''));
            hr_redirect(hr_url('attendance', ['tab' => 'import']), ['ok', 'Import cancelled. Nothing was saved.']);
        } else {
            $parked = hr_bio_unpark((string) ($_POST['bio'] ?? ''));
            if (!$parked) {
                $flash = ['err', 'That upload has expired. Upload the report again.'];
            } else {
                try {
                    $sum = hr_bio_apply($parked['parsed'], (array) ($_POST['who'] ?? []), [
                        'replace_manual' => !empty($_POST['replace_manual']), 'use_pid_as_code' => !empty($_POST['use_pid_as_code']),
                        'make_shift' => !empty($_POST['make_shift'])]);
                    hr_bio_discard((string) $_POST['bio']);
                    fabo_session();
                    $_SESSION['hr_bio_result'] = $sum + ['file' => $parked['file']];
                    hr_redirect(hr_url('attendance', ['tab' => 'import', 'done' => 1]));
                } catch (Throwable $e) {
                    $flash = ['err', fabo_user_error($e)];
                }
            }
        }
    } elseif ($a === 'remind') {
        if (!hr_is_admin() && hr_scope('attendance', 'edit') !== 'org') { fabo_deny('Not available.', 403); }
        $flash = hr_action(static function () { $n = hr_att_missing_reminders(); return $n . ' reminder(s) sent. Each person is reminded once per week.'; }, $back);
    }
}

/* ----------------------------------------------------------------- tabs */

$selfOnly = hr_scope('attendance', 'view') === 'self';
$tabs = [];
if (!$selfOnly) { $tabs['daily'] = 'Daily register'; }
$tabs['monthly'] = $selfOnly ? 'My attendance' : 'Monthly calendar';
$pendC = count(hr_att_corrections('submitted'));
$tabs['corrections'] = 'Corrections' . ($pendC && hr_can('attendance', 'approve') ? ' <span class="badge">' . $pendC . '</span>' : '');
if (hr_can('attendance', 'create') && hr_scope('attendance', 'create') === 'org') { $tabs['import'] = 'Import'; }
$tab = hr_tab($tabs, $selfOnly ? 'monthly' : 'daily');

$acts = '';
if (hr_report_allowed('monthly_attendance')) {
    $acts .= '<a class="btn btn-sm" href="' . e(hr_url('reports', ['report' => 'monthly_attendance'])) . '">Attendance reports</a>';
}
if (hr_is_admin() || hr_scope('attendance', 'configure') === 'org') {
    $acts .= '<a class="btn btn-sm" href="' . e(hr_url('sheets', ['tab' => 'attendance'])) . '">Attendance rules</a>';
}
hr_layout_start('Attendance Management', 'Days without a record are worked out from approved leave, holidays and weekly offs.', ['Attendance' => ''], $acts);
echo hr_flash_html($flash);
if ($importResult) {
    echo hr_import_report($importResult[0], $importResult[1]);
}
hr_tabs('attendance', $tabs, $tab);

$legend = '<div class="hr-legend">';
foreach (hr_att_statuses() as $s => $abbr) {
    $legend .= '<span><b class="st-' . e(str_replace('+', 'p', $abbr)) . '">' . e($abbr) . '</b>' . e($s) . '</span>';
}
$legend .= '<span><b class="st-NA">·</b>Not employed / future</span></div>';

if ($tab === 'daily') {
    $date = hr_date($_GET['date'] ?? '') ?? date('Y-m-d');
    if ($date > date('Y-m-d')) { $date = date('Y-m-d'); }
    $dept = (int) ($_GET['department'] ?? 0);
    $emps = hr_att_employees(['department' => $dept], $date, $date);
    $grid = hr_att_grid($emps, $date, $date);
    $locked = hr_locked_reason($date, $date);
    $canCreate = hr_can('attendance', 'create') && !$locked;
    $statusOpts = array_combine(hr_att_manual_statuses(), hr_att_manual_statuses());
    $count = ['present' => 0, 'absent' => 0, 'leave' => 0, 'late' => 0, 'unmarked' => 0];
    foreach ($emps as $e) {
        [$st, $rec, $note] = $grid[(int) $e['id']][$date];
        if (hr_att_present_value($st) > 0) { $count['present']++; }
        if ($st === 'Absent') { $count['absent']++; }
        if ($st === 'On Leave') { $count['leave']++; }
        if ($rec && $rec['late_minutes'] > 0) { $count['late']++; }
        if (!$rec && in_array($note, ['Not marked', 'Not marked yet'], true)) { $count['unmarked']++; }
    }
    echo '<form method="get" class="card hr-filters"><input type="hidden" name="p" value="attendance"><input type="hidden" name="tab" value="daily">'
       . '<div class="grp"><label for="ad">Date</label><input id="ad" type="date" name="date" value="' . e($date) . '" max="' . date('Y-m-d') . '" data-autosubmit></div>'
       . '<div class="grp"><label for="add">Department</label><select id="add" name="department" data-autosubmit>' . hr_options(hr_departments_map(), $dept, 'All') . '</select></div>'
       . '<a class="btn btn-sm" href="' . e(hr_url('attendance', ['tab' => 'daily', 'date' => date('Y-m-d', strtotime($date . ' -1 day')), 'department' => $dept])) . '">&larr; Previous day</a>'
       . ($date < date('Y-m-d') ? '<a class="btn btn-sm" href="' . e(hr_url('attendance', ['tab' => 'daily', 'date' => date('Y-m-d', strtotime($date . ' +1 day')), 'department' => $dept])) . '">Next day &rarr;</a>' : '')
       . (hr_report_allowed('daily_attendance') ? '<a class="btn btn-sm" href="' . e(hr_url('file', ['kind' => 'report', 'report' => 'daily_attendance', 'fmt' => 'xlsx', 'date' => $date, 'department' => $dept])) . '">Export</a>' : '')
       . (hr_report_allowed('machine_report') ? '<a class="btn btn-sm" href="' . e(hr_url('file', ['kind' => 'report', 'report' => 'machine_report', 'fmt' => 'xlsx', 'from' => $date, 'to' => $date, 'department' => $dept])) . '">Export (machine format)</a>' : '')
       . '<noscript><button class="btn btn-sm">Go</button></noscript></form>';
    echo '<div class="tiles">' . hr_tile('Present', (string) $count['present'], 'green') . hr_tile('Absent', (string) $count['absent'], $count['absent'] ? 'red' : '')
       . hr_tile('On leave', (string) $count['leave']) . hr_tile('Late arrivals', (string) $count['late'], $count['late'] ? 'amber' : '')
       . hr_tile('Not marked', (string) $count['unmarked'], $count['unmarked'] ? 'amber' : '') . '</div>';
    if ($locked) {
        echo '<div class="hr-warn">' . e($locked) . '</div>';
    }
    echo '<div class="card"><h2>' . e(date('l, j F Y', strtotime($date))) . '</h2>';
    if (!$emps) {
        echo hr_empty('No employees on this date.');
    } else {
        if ($canCreate) {
            echo hr_form_open('', 'id="bulkform"') . '<input type="hidden" name="action" value="bulk"><input type="hidden" name="work_date" value="' . e($date) . '"></form>';
        }
        echo '<div class="scrollx"><table class="data hr-table stack"><thead><tr>' . ($canCreate ? '<th><input type="checkbox" data-checkall="emp[]" aria-label="Select all"></th>' : '')
           . '<th>Employee</th><th>Status</th><th>In</th><th>Out</th><th class="num">Worked</th><th>Late / early</th><th>Overtime</th><th>Notes</th><th></th></tr></thead><tbody>';
        foreach ($emps as $e) {
            $eid = (int) $e['id'];
            [$st, $rec, $note] = $grid[$eid][$date];
            $info = $grid[$eid][$date]['info'];
            echo '<tr>' . ($canCreate ? '<td><input type="checkbox" name="emp[]" value="' . $eid . '" form="bulkform" aria-label="Select ' . e($e['full_name']) . '"></td>' : '')
               . '<td data-l="Employee">' . hr_person_cell($e) . '<div class="muted small">' . e($info['shift']['name'] ?? 'No shift') . '</div></td>'
               . '<td data-l="Status">' . ($st ? hr_badge($st, $st) : '<span class="muted">—</span>') . ($rec && $rec['source'] !== 'manual' ? ' <span class="muted small">(' . e($rec['source']) . ')</span>' : '') . '</td>'
               . '<td data-l="In">' . e($rec && $rec['check_in'] ? date('H:i', strtotime($rec['check_in'])) : '') . '</td>'
               . '<td data-l="Out">' . e($rec && $rec['check_out'] ? date('H:i', strtotime($rec['check_out'])) . (substr($rec['check_out'], 0, 10) !== $date ? ' +1' : '') : '') . '</td>'
               . '<td class="num" data-l="Worked">' . e($rec ? hr_fmt_minutes((int) $rec['worked_minutes']) : '') . '</td>'
               . '<td data-l="Late / early">' . e(trim(($rec && $rec['late_minutes'] ? 'Late ' . $rec['late_minutes'] . 'm ' : '') . ($rec && $rec['early_minutes'] ? 'Early ' . $rec['early_minutes'] . 'm' : ''))) . '</td>'
               . '<td data-l="Overtime">' . ($rec && $rec['overtime_minutes'] ? e(hr_fmt_minutes((int) $rec['overtime_minutes'])) . ' ' . ($rec['overtime_approved'] ? hr_badge('approved', 'Approved') : hr_badge('pending', 'Not approved'))
                    . (hr_emp_allowed($eid, 'attendance', 'approve') && !$locked ? ' ' . hr_button_form($rec['overtime_approved'] ? 'Unapprove' : 'Approve', ['action' => 'ot', 'id' => $rec['id'], 'approve' => $rec['overtime_approved'] ? 0 : 1]) : '') : '') . '</td>'
               . '<td data-l="Notes" class="small">' . e(trim($note . ($rec && $rec['remarks'] ? ' · ' . $rec['remarks'] : ''))) . '</td><td class="hr-acts">';
            $mayEdit = !$locked && hr_emp_allowed($eid, 'attendance', $rec ? 'edit' : 'create') && hr_scope('attendance', $rec ? 'edit' : 'create') !== 'self';
            if ($mayEdit) {
                echo '<details class="hr-row"><summary class="btn btn-sm">' . ($rec ? 'Edit' : 'Mark') . '</summary><div style="min-width:280px;margin-top:8px">'
                   . hr_form_open('', 'class="hr-form"') . '<input type="hidden" name="action" value="save"><input type="hidden" name="employee_id" value="' . $eid . '">'
                   . '<input type="hidden" name="work_date" value="' . e($date) . '"><div class="formgrid" style="grid-template-columns:1fr 1fr">'
                   . hr_field('Check-in', hr_input('check_in', $rec && $rec['check_in'] ? date('H:i', strtotime($rec['check_in'])) : '', 'time'))
                   . hr_field('Check-out', hr_input('check_out', $rec && $rec['check_out'] ? date('H:i', strtotime($rec['check_out'])) : '', 'time'))
                   . hr_field('Break (min)', hr_input('break_minutes', $rec ? $rec['break_minutes'] : '', 'number', ['min' => 0, 'placeholder' => 'Shift default']))
                   . hr_field('Status', hr_select('status', $statusOpts, $rec && $rec['status_manual'] ? $rec['status'] : '', 'Work out from times'))
                   . '</div>' . hr_field('Remarks', hr_input('remarks', $rec['remarks'] ?? '', 'text', ['maxlength' => 255]))
                   . (hr_setting('att_ot_needs_approval') === '1' && hr_emp_allowed($eid, 'attendance', 'approve') ? '<div class="field">' . hr_checkbox('overtime_approved', (bool) ($rec['overtime_approved'] ?? 0), 'Overtime approved') . '</div>' : '')
                   . '<button class="btn btn-sm btn-primary">Save</button></form></div></details>';
                if ($rec && hr_emp_allowed($eid, 'attendance', 'edit')) {
                    echo hr_button_form('Clear', ['action' => 'delete', 'id' => $rec['id']], 'btn btn-sm', 'Remove this record? The day will be worked out from leave, holidays and settings again.');
                }
            }
            if ($rec && in_array($rec['status'], ['Holiday Worked'], true) && !$rec['comp_off_ledger_id'] && hr_setting('att_comp_off_on_holiday') === '1'
                && hr_emp_allowed($eid, 'leave', 'approve') && !$locked) {
                echo hr_form_open('', 'class="hr-inline" data-confirm="Credit a compensatory off for this day?"') . '<input type="hidden" name="action" value="comp_off"><input type="hidden" name="id" value="' . (int) $rec['id'] . '">'
                   . '<select name="days" aria-label="Days"><option value="1">1 day</option><option value="0.5">½ day</option></select><button class="btn btn-sm">Grant comp-off</button></form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
        if ($canCreate) {
            echo '<div class="hr-filters" style="padding:12px 0 0"><div class="grp"><label for="bs">Mark ticked as</label><select id="bs" name="status" form="bulkform">' . hr_options($statusOpts, 'Present') . '</select></div>'
               . '<div class="grp"><label for="bi">Check-in (optional)</label><input id="bi" type="time" name="check_in" form="bulkform"></div>'
               . '<div class="grp"><label for="bo">Check-out (optional)</label><input id="bo" type="time" name="check_out" form="bulkform"></div>'
               . '<button class="btn btn-primary" form="bulkform" data-confirm="Mark every ticked employee for ' . e(hr_fmt_date($date)) . '? Existing records for them are replaced.">Apply to ticked</button></div>';
        }
    }
    echo '</div>';
    if (hr_is_admin() || hr_scope('attendance', 'edit') === 'org') {
        echo '<div class="card"><h2>Reminders</h2><p class="mt0">Send each employee with unmarked working days in the past week an in-app reminder (and email, if mail is configured). Sent at most once a week per person.</p>'
           . hr_button_form('Send missing-attendance reminders', ['action' => 'remind'], 'btn btn-sm') . '</div>';
    }
}

if ($tab === 'monthly') {
    $month = hr_month_param();
    $from = $month . '-01';
    $to = date('Y-m-t', strtotime($from));
    $dept = (int) ($_GET['department'] ?? 0);
    $emps = hr_att_employees(['department' => $dept, 'employee' => $selfOnly ? (int) ($self['id'] ?? -1) : (int) ($_GET['employee'] ?? 0)], $from, $to);
    $grid = hr_att_grid($emps, $from, $to);
    $lock = hr_att_lock_state($month);
    $mayLock = hr_is_admin() || (hr_can('attendance', 'configure') && hr_scope('attendance', 'configure') === 'org');
    echo '<form method="get" class="card hr-filters"><input type="hidden" name="p" value="attendance"><input type="hidden" name="tab" value="monthly">'
       . '<div class="grp"><label for="mm">Month</label><select id="mm" name="month" data-autosubmit>' . hr_options(hr_month_choices(), $month) . '</select></div>'
       . (!$selfOnly ? '<div class="grp"><label for="md">Department</label><select id="md" name="department" data-autosubmit>' . hr_options(hr_departments_map(), $dept, 'All') . '</select></div>' : '')
       . (hr_report_allowed('monthly_attendance') ? '<a class="btn btn-sm" href="' . e(hr_url('file', ['kind' => 'report', 'report' => 'monthly_attendance', 'fmt' => 'xlsx', 'month' => $month, 'department' => $dept])) . '">Export summary</a>' : '')
       . (hr_report_allowed('machine_report') ? '<a class="btn btn-sm" href="' . e(hr_url('file', ['kind' => 'report', 'report' => 'machine_report', 'fmt' => 'xlsx', 'from' => $from, 'to' => min($to, date('Y-m-d')), 'department' => $dept])) . '">Export (machine format)</a>' : '')
       . '</form>';
    if ($lock && $lock['locked']) {
        echo '<div class="hr-warn"><strong>' . e(date('F Y', strtotime($from))) . ' is locked</strong> by ' . e($lock['locked_by']) . ' on ' . e(date('d M Y', strtotime($lock['locked_at'])))
           . ($lock['reason'] ? ' — ' . e($lock['reason']) : '') . '. Attendance and leave in this month cannot change.';
        $paid = fabo_one("SELECT id FROM hr_payroll_runs WHERE org_id = ? AND period = ? AND status IN ('finalized','payslips')", 'is', [hr_org(), $month]);
        if ($paid) {
            echo ' Payroll for this month is finalized, so it stays locked; corrections go into a later month as payroll adjustments.';
        } elseif ($mayLock) {
            echo hr_form_open('', 'class="hr-filters" style="padding:8px 0 0" data-confirm="Reopen this month? Changes will again be possible."') . '<input type="hidden" name="action" value="reopen"><input type="hidden" name="period" value="' . e($month) . '">'
               . '<div class="grp"><label for="rr">Reason for reopening</label><input id="rr" name="reason" required maxlength="255"></div><button class="btn btn-sm">Reopen month</button></form>';
        }
        echo '</div>';
    } elseif ($mayLock && $to < date('Y-m-d')) {
        echo '<div class="hr-note">Lock a finished month once its attendance is checked, so it cannot change before payroll. Finalizing payroll locks it automatically. '
           . hr_button_form('Lock ' . date('F Y', strtotime($from)), ['action' => 'lock', 'period' => $month, 'reason' => 'Attendance reviewed'], 'btn btn-sm', 'Lock attendance for this month?') . '</div>';
    }
    echo $legend;
    if (!$emps) {
        echo '<div class="card">' . hr_empty('No employees to show.') . '</div>';
    } else {
        echo '<div class="card scrollx" style="padding:0"><table class="hr-cal"><thead><tr><th class="nm">Employee</th>';
        for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            echo '<th' . (in_array((int) date('w', strtotime($d)), [0, 6], true) ? ' class="we"' : '') . '>' . date('j', strtotime($d)) . '<br><span class="muted">'
               . substr(date('D', strtotime($d)), 0, 2) . '</span></th>';
        }
        echo '<th title="Present days">P</th><th title="Absent days">A</th><th title="Leave days">L</th><th title="Late arrivals">Late</th></tr></thead><tbody>';
        $codes = hr_att_statuses();
        foreach ($emps as $e) {
            $days = $grid[(int) $e['id']] ?? [];
            $t = hr_att_totals($days);
            echo '<tr><td class="nm"><a href="' . e(hr_url('employee', ['id' => $e['id'], 'tab' => 'attendance', 'month' => $month])) . '">' . e($e['full_name']) . '</a></td>';
            foreach ($days as $d => [$st, $rec, $note]) {
                $abbr = $st !== '' ? ($codes[$st] ?? '?') : '';
                $cls = $abbr !== '' ? 'st-' . str_replace('+', 'p', $abbr) : 'st-NA';
                $tip = date('D j M', strtotime($d)) . ': ' . ($st ?: ($note ?: 'No record')) . ($rec && $rec['check_in'] ? ' · ' . date('H:i', strtotime($rec['check_in'])) . '–' . ($rec['check_out'] ? date('H:i', strtotime($rec['check_out'])) : '?') : '')
                     . ($rec && $rec['late_minutes'] ? ' · late ' . $rec['late_minutes'] . ' min' : '') . ($note && $st ? ' · ' . $note : '');
                $link = !$selfOnly && hr_can('attendance', 'view') ? hr_url('attendance', ['tab' => 'daily', 'date' => $d, 'department' => $dept]) : '';
                echo '<td class="c ' . e($cls) . '" title="' . e($tip) . '">' . ($link && $d <= date('Y-m-d') ? '<a href="' . e($link) . '">' . e($abbr ?: '·') . '</a>' : e($abbr ?: '·')) . '</td>';
            }
            echo '<td class="tot">' . hr_fmt_days($t['present']) . '</td><td class="tot">' . hr_fmt_days($t['absent']) . '</td><td class="tot">' . hr_fmt_days($t['leave']) . '</td><td class="tot">' . $t['late'] . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
}

if ($tab === 'corrections') {
    $status = (string) ($_GET['status'] ?? 'submitted');
    $rows = hr_att_corrections(in_array($status, ['submitted', 'approved', 'rejected', ''], true) ? $status : 'submitted');
    $canAsk = hr_can('attendance', 'create');
    if ($canAsk) {
        $choices = hr_scope('attendance', 'create') === 'self' ? [] : hr_employee_choices(true, 'attendance', 'create');
        echo '<div class="card"><h2>Ask for a correction</h2><p class="mt0 hr-hint">For a missed punch, a wrong time, or a day marked wrongly. '
           . 'Requests can be made up to ' . (int) hr_setting('att_correction_days') . ' day(s) after the date. The day changes only when the request is approved.</p>'
           . hr_form_open('', 'class="hr-form"') . '<input type="hidden" name="action" value="correction"><div class="formgrid">'
           . ($choices ? hr_field('Employee', hr_select('employee_id', $choices, '', 'Choose…', ['required' => true]), true)
                       : '<input type="hidden" name="employee_id" value="' . (int) ($self['id'] ?? 0) . '">')
           . hr_field('Date', hr_input('work_date', '', 'date', ['required' => true, 'max' => date('Y-m-d')]), true)
           . hr_field('Correct check-in', hr_input('check_in', '', 'time'))
           . hr_field('Correct check-out', hr_input('check_out', '', 'time'))
           . hr_field('Correct status', hr_select('status', array_combine(hr_att_manual_statuses(), hr_att_manual_statuses()), '', 'Work out from the times'))
           . hr_field('Reason', hr_input('reason', '', 'text', ['required' => true, 'maxlength' => 500, 'placeholder' => 'e.g. Forgot to check out']), true, '', 'full')
           . '</div><button class="btn btn-primary">Submit request</button></form></div>';
    }
    echo '<div class="card"><h2>Correction requests</h2><nav class="hr-acts" style="margin-bottom:10px">';
    foreach (['submitted' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', '' => 'All'] as $k => $lab) {
        echo '<a class="btn btn-sm' . ($k === $status ? ' btn-primary' : '') . '" href="' . e(hr_url('attendance', ['tab' => 'corrections', 'status' => $k])) . '">' . e($lab) . '</a>';
    }
    echo '</nav>';
    if (!$rows) {
        echo hr_empty('No requests here.');
    } else {
        echo '<div class="scrollx"><table class="data hr-table stack"><thead><tr><th>Employee</th><th>Date</th><th>Asked for</th><th>Reason</th><th>Status</th><th></th></tr></thead><tbody>';
        foreach ($rows as $c) {
            $ask = [];
            if ($c['req_check_in']) { $ask[] = 'in ' . date('H:i', strtotime($c['req_check_in'])); }
            if ($c['req_check_out']) { $ask[] = 'out ' . date('H:i', strtotime($c['req_check_out'])); }
            if ($c['req_status']) { $ask[] = $c['req_status']; }
            echo '<tr><td data-l="Employee">' . hr_person_cell(['id' => $c['employee_id'], 'full_name' => $c['full_name'], 'emp_code' => $c['emp_code']]) . '</td>'
               . '<td data-l="Date">' . e(hr_fmt_date($c['work_date'])) . '</td><td data-l="Asked for">' . e(implode(', ', $ask)) . '</td>'
               . '<td data-l="Reason" class="small">' . e($c['reason']) . '</td><td data-l="Status">' . hr_badge($c['status'], ucfirst($c['status']))
               . ($c['decided_by'] ? '<div class="muted small">' . e($c['decided_by']) . ($c['decision_comment'] ? ': ' . e($c['decision_comment']) : '') . '</div>' : '') . '</td><td>';
            if (hr_att_correction_can_decide($c)) {
                echo hr_form_open('', 'class="hr-acts"') . '<input type="hidden" name="action" value="decide_correction"><input type="hidden" name="id" value="' . (int) $c['id'] . '">'
                   . '<input name="comment" placeholder="Comment (needed to reject)" maxlength="500" aria-label="Comment">'
                   . '<button class="btn btn-sm btn-approve" name="decision" value="approve">Approve</button><button class="btn btn-sm btn-reject" name="decision" value="reject">Reject</button></form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';
}

if ($tab === 'import') {
    require_once __DIR__ . '/../biometric.php';
    fabo_session();
    $res = !empty($_GET['done']) ? ($_SESSION['hr_bio_result'] ?? null) : null;
    unset($_SESSION['hr_bio_result']);
    $parked = isset($_GET['bio']) ? hr_bio_unpark((string) $_GET['bio']) : null;

    if ($res) {
        $ok = !$res['errors'];
        echo '<div class="card"><h2>Biometric report imported</h2><div class="tiles">'
           . hr_tile('New days recorded', (string) $res['days'], 'green') . hr_tile('Days updated', (string) $res['updated'])
           . hr_tile('Days with no punch', (string) $res['no_punch'], '', 'Read from leave, holidays and weekly offs')
           . hr_tile('Kept (entered by hand)', (string) $res['kept']) . hr_tile('Employees added', (string) count($res['created']))
           . hr_tile('Problems', (string) count($res['errors']), $ok ? '' : 'amber') . '</div>';
        if ($res['shift']) {
            echo '<div class="hr-note">The shift “' . e($res['shift']) . '” was made from the machine\'s timetable (15 minutes grace, Sundays off)'
               . (!empty($res['shift_default']) ? ' and set as the default shift' : ' and given to the people added') . '. '
               . '<a href="' . e(hr_url('schedules')) . '">Check it</a> — late arrivals and half days are worked out against it. '
               . '<strong>If your office is closed on Saturdays (all, or 2nd and 4th), set that on the shift</strong>, or those days will read as absent.</div>';
        }
        foreach (['created' => 'Employees added', 'linked' => 'Biometric IDs linked', 'skipped_people' => 'People skipped'] as $k => $label) {
            if ($res[$k]) {
                echo '<p><strong>' . e($label) . ':</strong> ' . e(implode(', ', $res[$k])) . '</p>';
            }
        }
        if ($res['created']) {
            echo '<div class="hr-warn">Employees added from the report have their date of joining set to their first day in the report. '
               . 'Open each one to correct it and fill in the rest of their details.</div>';
        }
        if ($res['errors']) {
            echo '<div class="hr-warn"><strong>Not imported:</strong><ul>';
            foreach (array_slice($res['errors'], 0, 60, true) as $where => $why) {
                echo '<li>' . e($where . ': ' . $why) . '</li>';
            }
            echo '</ul></div>';
        }
        echo '<p><a class="btn btn-sm btn-primary" href="' . e(hr_url('attendance', ['tab' => 'monthly'])) . '">Open the monthly calendar</a> '
           . '<a class="btn btn-sm" href="' . e(hr_url('attendance', ['tab' => 'import'])) . '">Import another report</a></p></div>';
    }

    if ($parked) {
        $parsed = $parked['parsed'];
        $people = hr_bio_people($parsed);
        $dates = array_column($parsed['rows'], 'date');
        $unmatched = array_filter($people, static fn($p) => !$p['emp']);
        $canCreate = hr_is_admin() || hr_scope('employees', 'create') === 'org';
        $empChoices = [];
        foreach (fabo_all("SELECT id, full_name, emp_code FROM hr_employees WHERE org_id = ? AND (biometric_id IS NULL OR biometric_id = '')
                           ORDER BY full_name", 'i', [hr_org()]) as $e) {
            $empChoices['emp:' . $e['id']] = 'Link to ' . $e['full_name'] . ' (' . $e['emp_code'] . ')';
        }
        $existing = (int) (fabo_one('SELECT COUNT(*) n FROM hr_attendance WHERE org_id = ? AND work_date BETWEEN ? AND ?', 'iss',
                                    [hr_org(), min($dates), max($dates)])['n'] ?? 0);
        echo '<div class="card"><h2>Check the report before importing</h2><dl class="hr-kv">'
           . '<dt>File</dt><dd>' . e($parked['file']) . ($parsed['title'] ? ' — ' . e($parsed['title']) : '') . '</dd>'
           . '<dt>Dates</dt><dd>' . e(hr_fmt_date(min($dates))) . ' to ' . e(hr_fmt_date(max($dates))) . '</dd>'
           . '<dt>Rows</dt><dd>' . count($parsed['rows']) . ' (' . count(array_filter($parsed['rows'], static fn($r) => $r['in'] || $r['out'])) . ' with a punch)</dd>'
           . '<dt>People</dt><dd>' . count($people) . ' — ' . (count($people) - count($unmatched)) . ' matched, <strong>' . count($unmatched) . ' not matched</strong></dd>'
           . ($existing ? '<dt>Already recorded</dt><dd>' . $existing . ' day(s) in these dates already have attendance</dd>' : '')
           . '</dl>';
        if ($parsed['skipped']) {
            echo '<div class="hr-warn">' . count($parsed['skipped']) . ' line(s) could not be read and will be left out: '
               . e(implode('; ', array_map(static fn($l, $w) => 'line ' . $l . ' — ' . $w, array_keys($parsed['skipped']), $parsed['skipped']))) . '</div>';
        }
        echo hr_form_open('', 'class="hr-form" data-confirm="Import this report now?"') . '<input type="hidden" name="action" value="bio_apply">'
           . '<input type="hidden" name="bio" value="' . e((string) $_GET['bio']) . '">';
        if ($unmatched) {
            echo '<h3 style="font-size:14px;margin:14px 0 6px">People the portal does not know yet (' . count($unmatched) . ')</h3>'
               . '<p class="hr-hint mt0">Choose what to do with each. Linking saves their Person ID on the employee, so next time they match by themselves.</p>'
               . '<div class="scrollx"><table class="data hr-table stack"><thead><tr><th>Person ID</th><th>Name</th><th>Department</th><th>Position</th>'
               . '<th class="num">Days with a punch</th><th>What to do</th></tr></thead><tbody>';
            foreach ($unmatched as $p) {
                $opts = ($canCreate ? ['new' => 'Add as a new employee'] : []) + ['skip' => 'Skip — do not import'] + $empChoices;
                $def = $p['suggest'] ? 'emp:' . $p['suggest']['id'] : ($canCreate ? 'new' : 'skip');
                echo '<tr><td data-l="Person ID"><strong>' . e($p['pid']) . '</strong></td><td data-l="Name">' . e($p['name'])
                   . ($p['suggest'] ? '<div class="muted small">Same name as ' . e($p['suggest']['full_name']) . ' — linked by default</div>' : '') . '</td>'
                   . '<td data-l="Department">' . e($p['dept']) . '</td><td data-l="Position">' . e($p['position']) . '</td>'
                   . '<td class="num" data-l="Days">' . (int) $p['punched'] . ' of ' . (int) $p['rows'] . '</td>'
                   . '<td><select name="who[' . e($p['pid']) . ']" aria-label="What to do with ' . e($p['name']) . '">' . hr_options($opts, $def) . '</select></td></tr>';
            }
            echo '</tbody></table></div>';
        }
        $matched = array_filter($people, static fn($p) => (bool) $p['emp']);
        if ($matched) {
            echo '<details style="margin-top:12px"><summary class="btn btn-sm">Matched people (' . count($matched) . ')</summary><ul class="hr-list">';
            foreach ($matched as $p) {
                echo '<li><span>' . e($p['pid'] . ' — ' . $p['name']) . '</span><span class="muted">→ ' . e($p['emp']['full_name'] . ' (' . $p['emp']['emp_code'] . ')') . '</span></li>';
            }
            echo '</ul></details>';
        }
        $tt = hr_bio_report_timetable($parsed);
        echo '<div class="formsec" style="margin-top:14px"><h3>Options</h3>';
        if ($canCreate && $unmatched) {
            echo '<div class="field">' . hr_checkbox('use_pid_as_code', true, 'Use the machine\'s Person ID as the Employee ID for people added') . '</div>';
        }
        if ($tt && !hr_bio_match_shift($tt)) {
            echo '<div class="field">' . hr_checkbox('make_shift', true, 'Create a shift “Office hours ' . $tt[0] . '–' . $tt[1]
               . '” from the machine\'s timetable (15 minutes grace, Sundays off) for the people added'
               . (hr_default_shift() ? '' : ', and make it the default shift') . ', so late arrivals and half days are worked out against the machine\'s hours')
               . '<div class="hr-hint">A full day is the shift less one hour. Saturdays are working days until you change them on the shift.</div></div>';
        } elseif ($tt && $unmatched && $canCreate) {
            echo '<p class="hr-hint">People added go on the existing ' . e($tt[0] . '–' . $tt[1]) . ' shift, matching the machine\'s timetable.</p>';
        }
        echo '<div class="field">' . hr_checkbox('replace_manual', false, 'Also replace days that were entered or corrected by hand') . '</div>'
           . '<p class="hr-hint">Days imported from the machine before are always updated, so you can import the same report again later in the day. '
           . 'Days with no punch are not recorded: leave, holidays and weekly offs show for them, and other days read as absent.</p></div>'
           . '<div class="hr-formfoot"><button class="btn btn-primary">Import attendance</button></div></form>'
           . hr_form_open('', 'style="margin-top:8px"') . '<input type="hidden" name="action" value="bio_cancel"><input type="hidden" name="bio" value="'
           . e((string) $_GET['bio']) . '"><button class="btn btn-sm">Cancel — do not import</button></form></div>';
    } elseif (isset($_GET['bio'])) {
        echo '<div class="flash err">That upload has expired. Upload the report again.</div>';
    }

    if (!$parked) {
        echo '<div class="card"><h2>Import the biometric machine report</h2>'
           . '<p class="mt0">Upload the <strong>First/Last Access Records Report</strong> exactly as the machine exports it (.xls). '
           . 'You will see who it matches before anything is saved. People are matched by the <strong>Biometric ID</strong> on their employee record '
           . '(or their Employee ID); anyone new can be added or linked on the next screen.</p>'
           . hr_form_open('', 'class="hr-form"', true) . '<input type="hidden" name="action" value="bio_upload"><div class="formgrid">'
           . hr_field('Machine report (.xls, .xlsx or .csv)', '<input type="file" name="file" accept=".xls,.xlsx,.csv" required>', true) . '</div>'
           . '<button class="btn btn-primary">Upload and check</button></form></div>';
    }
}

hr_layout_end();

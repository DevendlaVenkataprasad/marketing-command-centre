<?php
/**
 * Attendance: one record per employee per day, the rules that turn punch
 * times into a status, the daily register and monthly calendar, correction
 * requests, month locking, and import.
 *
 * A record is stored only when something happened — a punch, a manual mark,
 * an import. Days without one are worked out when they are shown: approved
 * leave, a holiday, a weekly off, or (for a past working day) whatever the
 * "unmarked day" setting says. That keeps the register honest about what was
 * actually recorded, and lets a holiday added later show up everywhere.
 */

require_once __DIR__ . '/leave.php';

/** Every status the module understands, with its register code. */
function hr_att_statuses(): array
{
    return [
        'Present'                      => 'P',
        'Absent'                       => 'A',
        'Half Day'                     => 'HD',
        'On Leave'                     => 'L',
        'Weekly Off'                   => 'WO',
        'Public Holiday'               => 'H',
        'Work From Home'               => 'WFH',
        'Holiday Worked'               => 'HW',
        'Compensatory Off Earned'      => 'CO+',
        'Missing Check-in / Check-out' => 'MP',
    ];
}

/** Statuses an administrator may set by hand. */
function hr_att_manual_statuses(): array
{
    $s = ['Present', 'Absent', 'Half Day', 'Work From Home', 'Holiday Worked', 'Weekly Off', 'Public Holiday', 'On Leave'];
    if (hr_setting('att_wfh_allowed') !== '1') {
        $s = array_values(array_diff($s, ['Work From Home']));
    }
    return $s;
}

/** How much of a day a status pays as worked (for payroll and summaries). */
function hr_att_present_value(string $status): float
{
    return match ($status) {
        'Present', 'Work From Home', 'Holiday Worked', 'Compensatory Off Earned' => 1.0,
        'Half Day' => 0.5,
        default => 0.0,
    };
}

/* --------------------------------------------------------- calculation */

/**
 * Works out hours, lateness, overtime and status from check-in/out against
 * a shift. $in/$out are 'Y-m-d H:i:s' or null. $dayType is 'work',
 * 'off' or 'holiday'. Returns the computed fields.
 */
function hr_att_compute(?array $shift, string $date, ?string $in, ?string $out, int $breakMin, string $dayType): array
{
    $r = ['worked_minutes' => 0, 'late_minutes' => 0, 'early_minutes' => 0, 'overtime_minutes' => 0, 'status' => null];
    if (!$in && !$out) {
        return $r;
    }
    if (!$in || !$out) {
        $r['status'] = 'Missing Check-in / Check-out';
    } else {
        $span = (int) round((strtotime($out) - strtotime($in)) / 60);
        $r['worked_minutes'] = max(0, $span - $breakMin);
    }
    if ($shift) {
        $start = strtotime($date . ' ' . $shift['start_time']);
        $end   = strtotime($date . ' ' . $shift['end_time']);
        if ($end <= $start) {
            $end += 86400;     // overnight shift ends the next morning
        }
        if ($in && $dayType === 'work') {
            $late = (int) floor((strtotime($in) - $start) / 60);
            if ($late > (int) $shift['grace_minutes']) {
                $r['late_minutes'] = $late;
            }
        }
        if ($out && $dayType === 'work') {
            $early = (int) floor(($end - strtotime($out)) / 60);
            if ($early > (int) $shift['early_grace_minutes']) {
                $r['early_minutes'] = $early;
            }
        }
        if ($in && $out) {
            $worked = $r['worked_minutes'];
            if ($dayType !== 'work') {
                $r['status'] = 'Holiday Worked';
                $r['overtime_minutes'] = $worked;
            } else {
                $full = (float) $shift['full_day_hours'] * 60;
                $half = (float) $shift['half_day_hours'] * 60;
                /* Arriving inside the grace period and staying to the end of
                   the shift is a full day, even though the grace minutes
                   leave the worked time a little short of it. */
                $onTime = $r['late_minutes'] === 0 && $r['early_minutes'] === 0;
                $r['status'] = ($worked >= $full || ($onTime && $worked >= $half)) ? 'Present'
                             : ($worked >= $half ? 'Half Day' : 'Absent');
                $after = $shift['overtime_after_hours'] !== null ? (float) $shift['overtime_after_hours'] * 60 : null;
                if ($after !== null && $worked > $after) {
                    $ot = (int) ($worked - $after);
                    $r['overtime_minutes'] = $ot >= (int) $shift['min_overtime_minutes'] ? $ot : 0;
                }
            }
        }
    } elseif ($in && $out) {
        // No shift: hours are recorded but the status cannot be judged against one.
        $r['status'] = $dayType === 'work' ? 'Present' : 'Holiday Worked';
    }
    return $r;
}

/** Joins a date and HH:MM into a timestamp; a check-out earlier than check-in rolls to the next day. */
function hr_att_stamp(string $date, ?string $time, ?string $after = null): ?string
{
    if ($time === null) {
        return null;
    }
    $ts = strtotime($date . ' ' . (strlen($time) === 5 ? $time . ':00' : $time));
    if ($after !== null && $ts <= strtotime($after)) {
        $ts += 86400;
    }
    return date('Y-m-d H:i:s', $ts);
}

/* ------------------------------------------------------------- saving */

function hr_attendance_row(int $empId, string $date): ?array
{
    return fabo_one('SELECT * FROM hr_attendance WHERE employee_id = ? AND work_date = ?', 'is', [$empId, $date]);
}

/**
 * Records (or replaces) one day for one employee. $p: check_in, check_out
 * (HH:MM), break_minutes, status (optional: forces a manual status),
 * remarks, overtime_approved. One record per employee per day: a second save
 * for the same day updates the first, it never adds another.
 */
function hr_attendance_save(int $empId, string $date, array $p, string $source = 'manual', bool $correction = false): int
{
    $emp = hr_employee($empId);
    if (!$emp) {
        throw new InvalidArgumentException('That employee no longer exists.');
    }
    if ($date > date('Y-m-d')) {
        throw new InvalidArgumentException('Attendance cannot be recorded for a future date.');
    }
    if ($emp['date_of_joining'] && $date < $emp['date_of_joining']) {
        throw new InvalidArgumentException($emp['full_name'] . ' had not joined on ' . hr_fmt_date($date) . '.');
    }
    if ($emp['date_of_leaving'] && $date > $emp['date_of_leaving']) {
        throw new InvalidArgumentException($emp['full_name'] . ' had left by ' . hr_fmt_date($date) . '.');
    }
    if ($why = hr_locked_reason($date, $date)) {
        throw new InvalidArgumentException($why);
    }
    $map   = hr_day_map($emp, $date, $date)[$date];
    $shift = $map['shift'];
    $dayType = $map['holiday'] ? 'holiday' : ($map['off'] ? 'off' : 'work');

    // Seconds are kept when they are given (the machine records them); a form gives HH:MM.
    $tin  = hr_time_secs($p['check_in'] ?? '', 'Check-in');
    $tout = hr_time_secs($p['check_out'] ?? '', 'Check-out');
    $in   = hr_att_stamp($date, $tin);
    $out  = hr_att_stamp($date, $tout, $in ?? ($shift ? $date . ' ' . $shift['start_time'] : null));
    if ($in && $out && (strtotime($out) - strtotime($in)) > 20 * 3600) {
        throw new InvalidArgumentException('Check-out is more than 20 hours after check-in. Check the times.');
    }
    $break = ($p['break_minutes'] ?? '') === ''
           ? (int) ($shift['break_minutes'] ?? 0)
           : (int) hr_dec($p['break_minutes'], 0, false, 'Break (minutes)', 0, 600);
    $calc = hr_att_compute($shift, $date, $in, $out, ($in && $out) ? $break : 0, $dayType);

    $manual = trim((string) ($p['status'] ?? ''));
    if ($manual !== '') {
        if (!in_array($manual, hr_att_manual_statuses(), true) && !isset(hr_att_statuses()[$manual])) {
            throw new InvalidArgumentException('Choose an attendance status.');
        }
        if ($manual === 'Work From Home' && hr_setting('att_wfh_allowed') !== '1') {
            throw new InvalidArgumentException('Work from home is turned off under attendance settings.');
        }
        $status = $manual;
    } else {
        $status = $calc['status'];
        if ($status === null) {
            throw new InvalidArgumentException('Enter check-in and check-out times, or choose a status.');
        }
    }
    $remarks = hr_str($p['remarks'] ?? '', 255) ?: null;
    $otOk = hr_setting('att_ot_needs_approval') === '1' ? (!empty($p['overtime_approved']) ? 1 : 0) : 1;

    $before = hr_attendance_row($empId, $date);
    $vals = [
        'shift_id' => $shift ? (int) $shift['id'] : null, 'check_in' => $in, 'check_out' => $out,
        'break_minutes' => ($in && $out) ? $break : 0, 'worked_minutes' => $calc['worked_minutes'],
        'overtime_minutes' => $calc['overtime_minutes'], 'overtime_approved' => $otOk,
        'late_minutes' => $calc['late_minutes'], 'early_minutes' => $calc['early_minutes'],
        'status' => $status, 'status_manual' => $manual !== '' ? 1 : 0, 'source' => $source, 'remarks' => $remarks,
    ];
    if ($before) {
        $set = implode(', ', array_map(static fn($c) => $c . ' = ?', array_keys($vals)));
        fabo_exec('UPDATE hr_attendance SET ' . $set . ', updated_by = ? WHERE id = ?',
                  'issiiiiiisiss' . 'si', array_merge(array_values($vals), [hr_actor(), (int) $before['id']]));
        hr_audit($correction ? 'correct' : 'update', 'attendance', (int) $before['id'],
                 $emp['full_name'] . ' ' . $date . ': ' . $before['status'] . ' → ' . $status, $before, $vals);
        return (int) $before['id'];
    }
    $id = fabo_exec('INSERT INTO hr_attendance (org_id, employee_id, work_date, ' . implode(', ', array_keys($vals)) . ', created_by)
                     VALUES (?,?,?,' . implode(',', array_fill(0, count($vals), '?')) . ',?)',
                    'iis' . 'issiiiiiisiss' . 's', array_merge([hr_org(), $empId, $date], array_values($vals), [hr_actor()]));
    hr_audit('create', 'attendance', $id, $emp['full_name'] . ' ' . $date . ': ' . $status, null, $vals);
    return $id;
}

function hr_attendance_delete(int $id): void
{
    $a = fabo_one('SELECT * FROM hr_attendance WHERE id = ? AND org_id = ?', 'ii', [$id, hr_org()]);
    if (!$a) {
        throw new InvalidArgumentException('That record no longer exists.');
    }
    hr_need_emp((int) $a['employee_id'], 'attendance', 'edit');
    if ($why = hr_locked_reason($a['work_date'], $a['work_date'])) {
        throw new InvalidArgumentException($why);
    }
    if ($a['comp_off_ledger_id']) {
        throw new InvalidArgumentException('A compensatory off was granted for this day. Adjust the leave balance first.');
    }
    fabo_exec('DELETE FROM hr_attendance WHERE id = ?', 'i', [$id]);
    hr_audit('delete', 'attendance', $id, 'Attendance record removed for ' . $a['work_date'], $a, null);
}

/** Self-service check-in / check-out for today, when the setting allows it. */
function hr_attendance_punch(int $empId, string $what): string
{
    if (hr_setting('att_self_checkin') !== '1') {
        throw new InvalidArgumentException('Checking in from the HR page is turned off.');
    }
    $date = date('Y-m-d');
    $now  = date('H:i');
    $row  = hr_attendance_row($empId, $date);
    // An overnight shift that started yesterday is checked out against yesterday.
    if ($what === 'out' && !$row) {
        $y = hr_attendance_row($empId, date('Y-m-d', strtotime('-1 day')));
        if ($y && $y['check_in'] && !$y['check_out']) {
            $row = $y;
            $date = $y['work_date'];
        }
    }
    if ($what === 'in') {
        if ($row && $row['check_in']) {
            throw new InvalidArgumentException('You already checked in at ' . date('H:i', strtotime($row['check_in'])) . '.');
        }
        hr_attendance_save($empId, $date, ['check_in' => $now, 'check_out' => ''], 'self');
        return 'Checked in at ' . $now . '.';
    }
    if (!$row || !$row['check_in']) {
        throw new InvalidArgumentException('Check in first.');
    }
    if ($row['check_out']) {
        throw new InvalidArgumentException('You already checked out at ' . date('H:i', strtotime($row['check_out'])) . '.');
    }
    hr_attendance_save($empId, $date, ['check_in' => date('H:i', strtotime($row['check_in'])), 'check_out' => $now,
                                       'remarks' => $row['remarks']], 'self');
    return 'Checked out at ' . $now . '.';
}

/* ------------------------------------------------------------ register */

/**
 * What a day was for one employee, combining the stored record (if any),
 * approved leave, holidays and weekly offs. Returns [status, record|null, note].
 */
function hr_att_resolve(array $info, ?array $rec, ?array $leave, string $date): array
{
    if (!$info['employed']) {
        return ['', $rec, 'Not employed'];
    }
    if ($rec) {
        $st = $rec['status'];
        // A recorded absence on a day of approved leave is the leave.
        if ($leave && in_array($st, ['Absent', 'Missing Check-in / Check-out'], true)) {
            return ['On Leave', $rec, $leave['name']];
        }
        return [$st, $rec, $leave ? $leave['name'] . ($leave['half'] ? ' (half day)' : '') : ''];
    }
    if ($leave) {
        return [$leave['half'] ? 'Half Day' : 'On Leave', null, $leave['name'] . ($leave['half'] ? ' (half day)' : '')];
    }
    if ($info['holiday']) {
        return ['Public Holiday', null, $info['holiday']];
    }
    if ($info['off']) {
        return ['Weekly Off', null, ''];
    }
    if ($date >= date('Y-m-d')) {
        return ['', null, $date === date('Y-m-d') ? 'Not marked yet' : ''];
    }
    // Before the organisation recorded any attendance at all there is nothing to judge by.
    $since = hr_att_tracking_since();
    if ($since === null || $date < $since) {
        return ['', null, 'Before attendance tracking'];
    }
    return [hr_setting('att_unmarked_past') === 'present' ? 'Present' : 'Absent', null, 'Not marked'];
}

/** The first date this organisation recorded attendance, or null when it never has. */
function hr_att_tracking_since(): ?string
{
    static $c = [];
    $org = hr_org();
    if (!array_key_exists($org, $c)) {
        $c[$org] = fabo_one('SELECT MIN(work_date) d FROM hr_attendance WHERE org_id = ?', 'i', [$org])['d'] ?? null;
    }
    return $c[$org];
}

/**
 * The register for a set of employees over a date range, as
 * emp_id => date => [status, record, note]. One query per table, not per day.
 */
function hr_att_grid(array $emps, string $from, string $to): array
{
    $ids = array_map(static fn($e) => (int) $e['id'], $emps);
    $recs = [];
    if ($ids) {
        foreach (fabo_all('SELECT * FROM hr_attendance WHERE employee_id IN (' . implode(',', $ids) . ') AND work_date BETWEEN ? AND ?',
                          'ss', [$from, $to]) as $r) {
            $recs[(int) $r['employee_id']][$r['work_date']] = $r;
        }
    }
    $leaves = hr_approved_leave_days($ids, $from, $to);
    $out = [];
    foreach ($emps as $e) {
        $eid = (int) $e['id'];
        foreach (hr_day_map($e, $from, $to) as $d => $info) {
            $out[$eid][$d] = hr_att_resolve($info, $recs[$eid][$d] ?? null, $leaves[$eid][$d] ?? null, $d) + ['info' => $info];
        }
    }
    return $out;
}

/** Totals for one employee's row of the grid. */
function hr_att_totals(array $days): array
{
    $t = ['present' => 0.0, 'absent' => 0.0, 'leave' => 0.0, 'off' => 0, 'holiday' => 0, 'late' => 0, 'early' => 0,
          'worked' => 0, 'ot' => 0, 'wfh' => 0, 'missing' => 0, 'half' => 0];
    foreach ($days as $d) {
        [$st, $rec] = $d;
        $t['present'] += hr_att_present_value($st);
        if ($st === 'Absent') { $t['absent'] += 1; }
        if ($st === 'On Leave') { $t['leave'] += 1; }
        if ($st === 'Half Day') { $t['half']++; if (!$rec || $rec['status'] !== 'Half Day') { $t['leave'] += 0.5; } else { $t['absent'] += 0.5; } }
        if ($st === 'Weekly Off') { $t['off']++; }
        if ($st === 'Public Holiday') { $t['holiday']++; }
        if ($st === 'Work From Home') { $t['wfh']++; }
        if ($st === 'Missing Check-in / Check-out') { $t['missing']++; }
        if ($rec) {
            $t['late']  += $rec['late_minutes'] > 0 ? 1 : 0;
            $t['early'] += $rec['early_minutes'] > 0 ? 1 : 0;
            $t['worked'] += (int) $rec['worked_minutes'];
            $t['ot'] += (int) $rec['overtime_minutes'];
        }
    }
    return $t;
}

/** The employees a register shows, scoped, with optional department filter. */
function hr_att_employees(array $f, string $from, string $to): array
{
    $where = ' WHERE e.org_id = ? AND (e.date_of_joining IS NULL OR e.date_of_joining <= ?)
               AND (e.date_of_leaving IS NULL OR e.date_of_leaving >= ?)';
    $types = 'iss';
    $params = [hr_org(), $to, $from];
    if (empty($f['include_inactive'])) {
        $where .= " AND (e.status IN ('active','probation','notice') OR e.date_of_leaving >= ?)";
        $types .= 's';
        $params[] = $from;
    }
    if (!empty($f['department'])) {
        $where .= ' AND e.department_id = ?';
        $types .= 'i';
        $params[] = (int) $f['department'];
    }
    if (!empty($f['employee'])) {
        $where .= ' AND e.id = ?';
        $types .= 'i';
        $params[] = (int) $f['employee'];
    }
    $sc = hr_scope_sql('attendance', 'view', 'e');
    return fabo_all('SELECT e.*, d.name AS department FROM hr_employees e LEFT JOIN hr_departments d ON d.id = e.department_id'
                  . $where . $sc['sql'] . ' ORDER BY e.full_name', $types, $params);
}

/* --------------------------------------------------------- corrections */

/**
 * An employee (or someone for them) asks for a day to be changed. The
 * record itself changes only when the request is approved.
 */
function hr_att_correction_request(int $empId, array $p): int
{
    hr_need_emp($empId, 'attendance', 'create');
    $emp  = hr_employee($empId);
    $date = hr_date($p['work_date'] ?? '', true, 'Date');
    if ($date > date('Y-m-d')) {
        throw new InvalidArgumentException('You cannot correct a future date.');
    }
    $limit = (int) hr_setting('att_correction_days');
    if (!hr_can('attendance', 'edit') && $date < date('Y-m-d', strtotime('-' . $limit . ' day'))) {
        throw new InvalidArgumentException('Corrections can be requested up to ' . $limit . ' day(s) after the date. Ask HR for older ones.');
    }
    if ($why = hr_locked_reason($date, $date)) {
        throw new InvalidArgumentException($why);
    }
    $in  = hr_time($p['check_in'] ?? '', false, 'Check-in');
    $out = hr_time($p['check_out'] ?? '', false, 'Check-out');
    $st  = trim((string) ($p['status'] ?? ''));
    if ($st !== '' && !in_array($st, hr_att_manual_statuses(), true)) {
        throw new InvalidArgumentException('Choose a status from the list.');
    }
    if (!$in && !$out && $st === '') {
        throw new InvalidArgumentException('Say what should change: the times, the status, or both.');
    }
    $reason = hr_str($p['reason'] ?? '', 500, true, 'Reason');
    if (fabo_one("SELECT id FROM hr_attendance_corrections WHERE employee_id = ? AND work_date = ? AND status = 'submitted'",
                 'is', [$empId, $date])) {
        throw new InvalidArgumentException('There is already a pending correction for ' . hr_fmt_date($date) . '.');
    }
    $sin  = hr_att_stamp($date, $in);
    $sout = hr_att_stamp($date, $out, $sin);
    $id = fabo_exec('INSERT INTO hr_attendance_corrections (org_id, employee_id, work_date, req_check_in, req_check_out, req_status,
                     reason, created_by) VALUES (?,?,?,?,?,?,?,?)', 'iissssss',
                    [hr_org(), $empId, $date, $sin, $sout, $st ?: null, $reason, hr_actor()]);
    hr_audit('request', 'attendance_correction', $id, 'Correction requested for ' . $emp['full_name'] . ' on ' . $date);
    hr_notify_approvers($empId, 'attcorr-' . $id, 'Attendance correction requested',
        $emp['full_name'] . ' asked to correct ' . hr_fmt_date($date) . ': ' . $reason, 'attendance&tab=corrections');
    return $id;
}

function hr_att_correction(int $id): ?array
{
    return fabo_one('SELECT c.*, e.full_name, e.emp_code FROM hr_attendance_corrections c JOIN hr_employees e ON e.id = c.employee_id
                     WHERE c.id = ? AND c.org_id = ?', 'ii', [$id, hr_org()]);
}

/** May the viewer decide this correction? Never their own. */
function hr_att_correction_can_decide(array $c): bool
{
    if ($c['status'] !== 'submitted') {
        return false;
    }
    if (hr_is_admin()) {
        return true;
    }
    $self = hr_self_emp();
    if ($self && (int) $self['id'] === (int) $c['employee_id']) {
        return false;
    }
    $scope = hr_scope('attendance', 'approve');
    if ($scope === null || !hr_emp_allowed((int) $c['employee_id'], 'attendance', 'approve')) {
        return false;
    }
    return $scope === 'org' || hr_setting('att_correction_approval') === 'manager';
}

function hr_att_correction_decide(int $id, string $decision, string $comment): void
{
    $c = hr_att_correction($id);
    if (!$c) {
        throw new InvalidArgumentException('That request no longer exists.');
    }
    if (!hr_att_correction_can_decide($c)) {
        if ($c['status'] !== 'submitted') {
            throw new InvalidArgumentException('This correction has already been decided.');
        }
        fabo_deny('You cannot decide this correction.', 403);
    }
    $comment = hr_str($comment, 500, $decision === 'reject', 'A reason for rejecting');
    hr_tx(function () use ($id, $decision, $comment) {
        $c = fabo_one('SELECT * FROM hr_attendance_corrections WHERE id = ? AND org_id = ? FOR UPDATE', 'ii', [$id, hr_org()]);
        if (!$c || $c['status'] !== 'submitted') {
            throw new InvalidArgumentException('This correction was decided a moment ago. Reload to see the outcome.');
        }
        if ($decision === 'approve') {
            $cur = hr_attendance_row((int) $c['employee_id'], $c['work_date']);
            $in  = $c['req_check_in'] ? date('H:i', strtotime($c['req_check_in'])) : ($cur && $cur['check_in'] ? date('H:i', strtotime($cur['check_in'])) : '');
            $out = $c['req_check_out'] ? date('H:i', strtotime($c['req_check_out'])) : ($cur && $cur['check_out'] ? date('H:i', strtotime($cur['check_out'])) : '');
            hr_attendance_save((int) $c['employee_id'], $c['work_date'], [
                'check_in' => $in, 'check_out' => $out, 'status' => $c['req_status'] ?? '',
                'remarks' => 'Corrected: ' . $c['reason'], 'overtime_approved' => $cur['overtime_approved'] ?? 0,
            ], 'manual', true);
        }
        fabo_exec('UPDATE hr_attendance_corrections SET status = ?, decided_by = ?, decided_at = NOW(), decision_comment = ? WHERE id = ?',
                  'sssi', [$decision === 'approve' ? 'approved' : 'rejected', hr_actor(), $comment ?: null, $id]);
    });
    hr_audit($decision, 'attendance_correction', $id, 'Correction ' . ($decision === 'approve' ? 'approved' : 'rejected')
           . ' for ' . $c['full_name'] . ' on ' . $c['work_date'] . ($comment ? ': ' . $comment : ''));
    hr_notify_employee((int) $c['employee_id'], 'attcorr-done-' . $id,
        'Attendance correction ' . ($decision === 'approve' ? 'approved' : 'rejected'),
        'Your correction for ' . hr_fmt_date($c['work_date']) . ' was ' . ($decision === 'approve' ? 'approved.' : 'rejected.')
        . ($comment ? ' Comment: ' . $comment : ''));
}

function hr_att_corrections(string $status = 'submitted', int $limit = 200): array
{
    $sc = hr_scope_sql('attendance', 'view', 'e');
    return fabo_all('SELECT c.*, e.full_name, e.emp_code FROM hr_attendance_corrections c JOIN hr_employees e ON e.id = c.employee_id
                     WHERE c.org_id = ?' . ($status !== '' ? ' AND c.status = ?' : '') . $sc['sql']
                  . ' ORDER BY c.created_at DESC LIMIT ' . max(1, min(1000, $limit)),
                    'i' . ($status !== '' ? 's' : ''), $status !== '' ? [hr_org(), $status] : [hr_org()]);
}

/* -------------------------------------------------------------- locking */

function hr_att_lock_state(string $period): ?array
{
    return fabo_one('SELECT * FROM hr_attendance_locks WHERE org_id = ? AND period = ?', 'is', [hr_org(), $period]);
}

function hr_att_lock(string $period, bool $lock, string $reason): void
{
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
        throw new InvalidArgumentException('Choose a month.');
    }
    if (!$lock) {
        $reason = hr_str($reason, 255, true, 'A reason for reopening');
        $run = fabo_one('SELECT status FROM hr_payroll_runs WHERE org_id = ? AND period = ?', 'is', [hr_org(), $period]);
        if ($run && in_array($run['status'], ['finalized', 'payslips'], true)) {
            throw new InvalidArgumentException('Payroll for this month is finalized, so its attendance stays locked. '
                                             . 'Record a payroll adjustment in the next month instead.');
        }
        fabo_exec('UPDATE hr_attendance_locks SET locked = 0, reopened_by = ?, reopened_at = NOW(), reason = ? WHERE org_id = ? AND period = ?',
                  'ssis', [hr_actor(), $reason, hr_org(), $period]);
    } else {
        fabo_exec('INSERT INTO hr_attendance_locks (org_id, period, locked, locked_by, locked_at, reason) VALUES (?,?,1,?,NOW(),?)
                   ON DUPLICATE KEY UPDATE locked = 1, locked_by = VALUES(locked_by), locked_at = NOW(), reason = VALUES(reason)',
                  'isss', [hr_org(), $period, hr_actor(), $reason ?: null]);
    }
    hr_audit($lock ? 'lock' : 'reopen', 'attendance_month', null, ($lock ? 'Locked' : 'Reopened') . ' attendance for ' . $period
           . ($reason ? ': ' . $reason : ''));
}

/* --------------------------------------------------------------- import */

/**
 * Imports attendance rows: Employee ID, Date, Check In, Check Out, Break
 * (min), Status, Remarks. An existing day is updated only when $overwrite.
 * This is also the structure a device export can be mapped into; no device
 * is connected by the portal itself.
 */
function hr_attendance_import(string $path, string $name, bool $overwrite, bool $dryRun): array
{
    $rows = hr_read_table_file($path, $name);
    if (!$rows) {
        throw new InvalidArgumentException('The file is empty.');
    }
    [, $head] = array_shift($rows);
    $want = ['employee id' => 'code', 'emp code' => 'code', 'employee code' => 'code', 'date' => 'date', 'work date' => 'date',
             'check in' => 'in', 'check-in' => 'in', 'in' => 'in', 'check out' => 'out', 'check-out' => 'out', 'out' => 'out',
             'break (min)' => 'break', 'break' => 'break', 'break minutes' => 'break', 'status' => 'status', 'remarks' => 'remarks'];
    $cols = [];
    foreach ($head as $i => $h) {
        $k = mb_strtolower(trim((string) $h));
        if (isset($want[$k])) {
            $cols[$want[$k]] = $i;
        }
    }
    if (!isset($cols['code'], $cols['date'])) {
        throw new InvalidArgumentException('The first row must be headings, including “Employee ID” and “Date”. Download the template to see them.');
    }
    $codes = [];
    foreach (fabo_all('SELECT id, emp_code FROM hr_employees WHERE org_id = ?', 'i', [hr_org()]) as $e) {
        $codes[strtoupper($e['emp_code'])] = (int) $e['id'];
    }
    $done = 0;
    $errors = [];
    $seen = [];
    foreach ($rows as [$line, $cells]) {
        if (!array_filter($cells, static fn($c) => trim((string) $c) !== '')) {
            continue;
        }
        $get = static fn($k) => isset($cols[$k]) ? trim((string) ($cells[$cols[$k]] ?? '')) : '';
        try {
            $code = strtoupper($get('code'));
            if (!isset($codes[$code])) {
                throw new InvalidArgumentException('Employee ID “' . $code . '” was not found.');
            }
            $empId = $codes[$code];
            if (!hr_emp_allowed($empId, 'attendance', 'create')) {
                throw new InvalidArgumentException('You cannot record attendance for ' . $code . '.');
            }
            $date = hr_date($get('date'), true, 'Date');
            if (isset($seen[$empId . $date])) {
                throw new InvalidArgumentException($code . ' on ' . $date . ' appears twice in the file (line ' . $seen[$empId . $date] . ').');
            }
            $seen[$empId . $date] = $line;
            // Spreadsheet times may arrive as "2026-10-01 09:30" or a fraction of a day.
            $t = static function (string $v): string {
                if ($v === '') { return ''; }
                if (is_numeric($v) && (float) $v < 1) { $m = (int) round((float) $v * 1440); return sprintf('%02d:%02d', intdiv($m, 60), $m % 60); }
                if (preg_match('/(\d{1,2}:\d{2})(:\d{2})?\s*([AaPp][Mm])?$/', $v, $mm)) { return $mm[1] . (isset($mm[3]) ? ' ' . $mm[3] : ''); }
                return $v;
            };
            $p = ['check_in' => $t($get('in')), 'check_out' => $t($get('out')), 'break_minutes' => $get('break'),
                  'status' => $get('status'), 'remarks' => $get('remarks')];
            if ($p['status'] !== '') {
                $match = null;
                foreach (hr_att_statuses() as $s => $abbr) {
                    if (strcasecmp($s, $p['status']) === 0 || strcasecmp($abbr, $p['status']) === 0) { $match = $s; }
                }
                if (!$match) {
                    throw new InvalidArgumentException('Status “' . $p['status'] . '” is not recognised.');
                }
                $p['status'] = $match;
            }
            if (!$overwrite && hr_attendance_row($empId, $date)) {
                throw new InvalidArgumentException('Already recorded; left unchanged (tick “Update days already recorded” to replace).');
            }
            if ($dryRun) {
                hr_time($p['check_in'], false, 'Check-in');
                hr_time($p['check_out'], false, 'Check-out');
                if ($why = hr_locked_reason($date, $date)) {
                    throw new InvalidArgumentException($why);
                }
                if ($date > date('Y-m-d')) {
                    throw new InvalidArgumentException('Future date.');
                }
            } else {
                hr_attendance_save($empId, $date, $p, 'import');
            }
            $done++;
        } catch (InvalidArgumentException $e) {
            $errors[$line] = $e->getMessage();
        } catch (mysqli_sql_exception $e) {
            $errors[$line] = fabo_db_words($e);
        }
    }
    if (!$dryRun) {
        hr_audit('import', 'attendance', null, 'Imported ' . $done . ' attendance rows from ' . $name
               . ($errors ? ' (' . count($errors) . ' refused)' : ''));
    }
    return ['created' => $done, 'errors' => $errors, 'rows' => count($rows)];
}

/** Working days in the past week with no record at all — for the "missing attendance" reminder. */
function hr_att_missing_reminders(): int
{
    $from = date('Y-m-d', strtotime('-7 day'));
    $to   = date('Y-m-d', strtotime('-1 day'));
    $emps = hr_att_employees([], $from, $to);
    $grid = hr_att_grid($emps, $from, $to);
    $sent = 0;
    foreach ($emps as $e) {
        $missing = [];
        foreach ($grid[(int) $e['id']] ?? [] as $d => $cell) {
            if (!$cell[1] && $cell[2] === 'Not marked') {
                $missing[] = hr_fmt_date($d);
            }
        }
        if ($missing && hr_notify_employee((int) $e['id'], 'att-missing-' . $to, 'Attendance missing',
                'No attendance is recorded for: ' . implode(', ', $missing) . '. Submit a correction if you were working.',
                'hr.php?p=me')) {
            $sent++;
        }
    }
    return $sent;
}

<?php
/**
 * HR master data: departments, designations, the simple lists (employment
 * types, work locations, employee categories), shifts and their assignment
 * to people, and holiday calendars.
 *
 * Also the one function every calculation leans on, hr_day_map(): for an
 * employee and a date range, which days are weekly offs, which are holidays,
 * which shift applies, and whether they were employed at all. Leave days,
 * the attendance register and payroll all read it, so a holiday added here
 * is a holiday everywhere.
 */

require_once __DIR__ . '/core.php';

function hr_master_kinds(): array
{
    return [
        'employment_type' => 'Employment Types',
        'location'        => 'Work Locations',
        'category'        => 'Employee Categories',
        'claim_category'  => 'Claim Categories',
        'asset_category'  => 'Asset Categories',
        'ticket_category' => 'Helpdesk Categories',
        'training_category' => 'Training Categories',
    ];
}

function hr_holiday_types(): array
{
    return [
        'public'     => 'Public holiday',
        'company'    => 'Company holiday',
        'regional'   => 'Regional holiday',
        'optional'   => 'Optional holiday',
        'restricted' => 'Restricted holiday',
    ];
}

/* ----------------------------------------------------------- lookups */

function hr_map(string $sql, array $params = []): array
{
    $out = [];
    foreach (fabo_all($sql, str_repeat('i', count($params)), $params) as $r) {
        $out[(int) $r['id']] = $r['name'];
    }
    return $out;
}

function hr_departments_map(bool $activeOnly = true): array
{
    return hr_map('SELECT id, name FROM hr_departments WHERE org_id = ?' . ($activeOnly ? ' AND active = 1' : '')
                . ' ORDER BY name', [hr_org()]);
}

function hr_designations_map(bool $activeOnly = true): array
{
    return hr_map('SELECT id, name FROM hr_designations WHERE org_id = ?' . ($activeOnly ? ' AND active = 1' : '')
                . ' ORDER BY name', [hr_org()]);
}

function hr_master_map(string $kind, bool $activeOnly = true): array
{
    $out = [];
    foreach (fabo_all('SELECT id, name FROM hr_masters WHERE org_id = ? AND kind = ?' . ($activeOnly ? ' AND active = 1' : '')
                    . ' ORDER BY name', 'is', [hr_org(), $kind]) as $r) {
        $out[(int) $r['id']] = $r['name'];
    }
    return $out;
}

function hr_shifts_map(bool $activeOnly = true): array
{
    $out = [];
    foreach (fabo_all('SELECT id, name, start_time, end_time FROM hr_shifts WHERE org_id = ?'
                    . ($activeOnly ? ' AND active = 1' : '') . ' ORDER BY name', 'i', [hr_org()]) as $r) {
        $out[(int) $r['id']] = $r['name'] . ' (' . substr($r['start_time'], 0, 5) . '–' . substr($r['end_time'], 0, 5) . ')';
    }
    return $out;
}

function hr_calendars_map(bool $activeOnly = true): array
{
    return hr_map('SELECT id, name FROM hr_holiday_calendars WHERE org_id = ?' . ($activeOnly ? ' AND active = 1' : '')
                . ' ORDER BY is_default DESC, name', [hr_org()]);
}

function hr_shift(int $id): ?array
{
    return fabo_one('SELECT * FROM hr_shifts WHERE id = ? AND org_id = ?', 'ii', [$id, hr_org()]);
}

/* ------------------------------------------------- references & deletes */

/** Where each master is used. A record that is referenced is deactivated, never deleted. */
function hr_master_refs(string $table): array
{
    return [
        'hr_departments' => [['hr_employees', 'department_id'], ['hr_designations', 'department_id'],
                             ['hr_holiday_calendars', 'department_id']],
        'hr_designations' => [['hr_employees', 'designation_id']],
        'hr_masters'      => [['hr_employees', 'employment_type_id'], ['hr_employees', 'location_id'],
                              ['hr_employees', 'category_id'], ['hr_holiday_calendars', 'location_id'],
                              ['hr_job_openings', 'location_id'], ['hr_job_openings', 'employment_type_id'],
                              ['hr_claims', 'category_id'], ['hr_assets', 'category_id'], ['hr_tickets', 'category_id'],
                              ['hr_trainings', 'category_id']],
        'hr_shifts'       => [['hr_shift_assignments', 'shift_id'], ['hr_attendance', 'shift_id']],
        'hr_holiday_calendars' => [['hr_holidays', 'calendar_id'], ['hr_employees', 'holiday_calendar_id']],
    ][$table] ?? [];
}

function hr_ref_count(string $table, int $id): int
{
    $n = 0;
    foreach (hr_master_refs($table) as [$t, $c]) {
        $n += (int) (fabo_one('SELECT COUNT(*) n FROM `' . $t . '` WHERE `' . $c . '` = ?', 'i', [$id])['n'] ?? 0);
    }
    return $n;
}

/** Deletes when nothing uses it; otherwise says so and leaves it for deactivation. */
function hr_master_delete(string $table, int $id, string $what): array
{
    $allowed = ['hr_departments', 'hr_designations', 'hr_masters', 'hr_shifts', 'hr_holiday_calendars'];
    if (!in_array($table, $allowed, true)) {
        return [false, 'Not available.'];
    }
    $row = fabo_one('SELECT * FROM `' . $table . '` WHERE id = ? AND org_id = ?', 'ii', [$id, hr_org()]);
    if (!$row) {
        return [false, 'That record no longer exists.'];
    }
    $refs = hr_ref_count($table, $id);
    if ($refs > 0) {
        return [false, '“' . $row['name'] . '” is used by ' . $refs . ' record' . ($refs === 1 ? '' : 's')
                     . ' and cannot be deleted. Deactivate it instead — existing records keep it, new ones cannot choose it.'];
    }
    fabo_exec('DELETE FROM `' . $table . '` WHERE id = ? AND org_id = ?', 'ii', [$id, hr_org()]);
    hr_audit('delete', $table, $id, $what . ' deleted: ' . $row['name'], $row, null);
    hr_config_log('masters', $what . ': ' . $row['name'], $row, 'deleted');
    return [true, '“' . $row['name'] . '” deleted.'];
}

function hr_master_toggle(string $table, int $id, bool $active, string $what): array
{
    $allowed = ['hr_departments', 'hr_designations', 'hr_masters', 'hr_shifts', 'hr_holiday_calendars', 'hr_leave_types',
                'hr_salary_components', 'hr_leave_policies'];
    if (!in_array($table, $allowed, true)) {
        return [false, 'Not available.'];
    }
    $row = fabo_one('SELECT * FROM `' . $table . '` WHERE id = ? AND org_id = ?', 'ii', [$id, hr_org()]);
    if (!$row) {
        return [false, 'That record no longer exists.'];
    }
    fabo_exec('UPDATE `' . $table . '` SET active = ? WHERE id = ? AND org_id = ?', 'iii', [$active ? 1 : 0, $id, hr_org()]);
    hr_audit($active ? 'activate' : 'deactivate', $table, $id, $what . ' ' . ($active ? 'activated' : 'deactivated') . ': ' . $row['name']);
    hr_config_log('masters', $what . ': ' . $row['name'], 'active=' . $row['active'], 'active=' . ($active ? 1 : 0));
    return [true, '“' . $row['name'] . '” ' . ($active ? 'activated.' : 'deactivated. It stays on existing records.')];
}

/* --------------------------------------------------------------- saves */

function hr_department_save(?int $id, array $p): int
{
    $name = hr_str($p['name'] ?? '', 120, true, 'Department name');
    $code = hr_str($p['code'] ?? '', 20) ?: null;
    $desc = hr_str($p['description'] ?? '', 255) ?: null;
    return hr_master_upsert('hr_departments', $id, 'Department',
        ['name' => $name, 'code' => $code, 'description' => $desc]);
}

function hr_designation_save(?int $id, array $p): int
{
    $name = hr_str($p['name'] ?? '', 120, true, 'Designation name');
    $dept = hr_ref_id($p['department_id'] ?? 0, 'hr_departments', 'Department');
    $lvl  = hr_str($p['level'] ?? '', 40) ?: null;
    return hr_master_upsert('hr_designations', $id, 'Designation',
        ['name' => $name, 'department_id' => $dept, 'level' => $lvl]);
}

function hr_master_save(string $kind, ?int $id, array $p): int
{
    if (!isset(hr_master_kinds()[$kind])) {
        throw new InvalidArgumentException('Unknown list.');
    }
    $label = rtrim(hr_master_kinds()[$kind], 's');
    $name  = hr_str($p['name'] ?? '', 120, true, $label . ' name');
    $desc  = hr_str($p['description'] ?? '', 255) ?: null;
    if ($id) {
        $row = fabo_one('SELECT * FROM hr_masters WHERE id = ? AND org_id = ? AND kind = ?', 'iis', [$id, hr_org(), $kind]);
        if (!$row) {
            throw new InvalidArgumentException('That record no longer exists.');
        }
    }
    $vals = ['kind' => $kind, 'name' => $name, 'description' => $desc];
    if ($kind === 'claim_category') {
        // A ceiling per claim; blank means no limit.
        $vals['amount_limit'] = hr_dec($p['amount_limit'] ?? '', 2, false, 'Limit per claim', 0, 100000000);
    }
    return hr_master_upsert('hr_masters', $id, $label, $vals);
}

/**
 * Insert or update one master row, refusing a duplicate name with a sentence
 * rather than a database error, and logging the change.
 */
function hr_master_upsert(string $table, ?int $id, string $what, array $vals): int
{
    $org = hr_org();
    $dupSql = 'SELECT id FROM `' . $table . '` WHERE org_id = ? AND name = ?' . (isset($vals['kind']) ? ' AND kind = ?' : '')
            . ($id ? ' AND id <> ?' : '');
    $types = 'is' . (isset($vals['kind']) ? 's' : '') . ($id ? 'i' : '');
    $params = [$org, $vals['name']];
    if (isset($vals['kind'])) { $params[] = $vals['kind']; }
    if ($id) { $params[] = $id; }
    if (fabo_one($dupSql, $types, $params)) {
        throw new InvalidArgumentException('A ' . strtolower($what) . ' called “' . $vals['name'] . '” already exists. '
                                         . 'Use a different name, or edit the existing one.');
    }
    $cols = array_keys($vals);
    $typ  = '';
    foreach ($vals as $v) {
        $typ .= is_int($v) ? 'i' : 's';
    }
    if ($id) {
        $before = fabo_one('SELECT * FROM `' . $table . '` WHERE id = ? AND org_id = ?', 'ii', [$id, $org]);
        if (!$before) {
            throw new InvalidArgumentException('That record no longer exists.');
        }
        $set = implode(', ', array_map(static fn($c) => '`' . $c . '` = ?', $cols));
        fabo_exec('UPDATE `' . $table . '` SET ' . $set . ' WHERE id = ? AND org_id = ?', $typ . 'ii',
                  array_merge(array_values($vals), [$id, $org]));
        hr_audit('update', $table, $id, $what . ' updated: ' . $vals['name'], $before, $vals);
        hr_config_log('masters', $what . ': ' . $vals['name'], $before, $vals);
        return $id;
    }
    $new = fabo_exec('INSERT INTO `' . $table . '` (org_id, ' . implode(', ', array_map(static fn($c) => '`' . $c . '`', $cols))
                   . ', created_by) VALUES (?, ' . implode(', ', array_fill(0, count($cols), '?')) . ', ?)',
                     'i' . $typ . 's', array_merge([$org], array_values($vals), [hr_actor()]));
    hr_audit('create', $table, $new, $what . ' created: ' . $vals['name'], null, $vals);
    hr_config_log('masters', $what . ': ' . $vals['name'], '', $vals);
    return $new;
}

/* ---------------------------------------------------------------- shifts */

function hr_shift_save(?int $id, array $p): int
{
    $name  = hr_str($p['name'] ?? '', 120, true, 'Shift name');
    $code  = hr_str($p['code'] ?? '', 20) ?: null;
    $start = hr_time($p['start_time'] ?? '', true, 'Start time');
    $end   = hr_time($p['end_time'] ?? '', true, 'End time');
    if ($start === $end) {
        throw new InvalidArgumentException('Start and end time cannot be the same.');
    }
    $break = (int) hr_dec($p['break_minutes'] ?? '0', 0, false, 'Break (minutes)', 0, 600);
    $grace = (int) hr_dec($p['grace_minutes'] ?? '0', 0, false, 'Late grace (minutes)', 0, 240);
    $egr   = (int) hr_dec($p['early_grace_minutes'] ?? '0', 0, false, 'Early-leave grace (minutes)', 0, 240);
    $full  = hr_dec($p['full_day_hours'] ?? '', 2, true, 'Hours for a full day', 0.5, 24);
    $half  = hr_dec($p['half_day_hours'] ?? '', 2, true, 'Hours for a half day', 0.25, 24);
    if ((float) $half >= (float) $full) {
        throw new InvalidArgumentException('Hours for a half day must be less than hours for a full day.');
    }
    $span = hr_shift_span_minutes($start, $end) - $break;
    if ((float) $full * 60 > $span + 0.01) {
        throw new InvalidArgumentException('A full day (' . $full . ' h) is longer than the shift itself ('
                                         . round($span / 60, 2) . ' h after the break). Check the times and the break.');
    }
    $otAfter = hr_dec($p['overtime_after_hours'] ?? '', 2, false, 'Overtime starts after (hours)', 0, 24);
    $otMin   = (int) hr_dec($p['min_overtime_minutes'] ?? '0', 0, false, 'Minimum overtime (minutes)', 0, 600);
    $offs    = hr_offs_from_post($p);
    $vals = [
        'name' => $name, 'code' => $code, 'start_time' => $start, 'end_time' => $end, 'break_minutes' => $break,
        'grace_minutes' => $grace, 'early_grace_minutes' => $egr, 'full_day_hours' => $full, 'half_day_hours' => $half,
        'overtime_after_hours' => $otAfter, 'min_overtime_minutes' => $otMin, 'weekly_offs' => $offs,
    ];
    $id = hr_master_upsert('hr_shifts', $id, 'Shift', $vals);
    if (!empty($p['is_default'])) {
        fabo_exec('UPDATE hr_shifts SET is_default = (id = ?) WHERE org_id = ?', 'ii', [$id, hr_org()]);
    }
    unset($GLOBALS['hr_default_shift_cache']);
    return $id;
}

/** Minutes from start to end, across midnight when the end is earlier. */
function hr_shift_span_minutes(string $start, string $end): int
{
    $s = (int) substr($start, 0, 2) * 60 + (int) substr($start, 3, 2);
    $e = (int) substr($end, 0, 2) * 60 + (int) substr($end, 3, 2);
    return $e > $s ? $e - $s : 1440 - $s + $e;
}

function hr_shift_overnight(array $shift): bool
{
    return substr($shift['end_time'], 0, 5) <= substr($shift['start_time'], 0, 5);
}

/**
 * Puts an employee on a shift from a date. The open assignment before it is
 * closed the day before, so history is kept and no two overlap.
 */
function hr_shift_assign(int $empId, int $shiftId, string $from): void
{
    if (!hr_shift($shiftId)) {
        throw new InvalidArgumentException('Choose a shift.');
    }
    hr_tx(static function () use ($empId, $shiftId, $from): void {
        $org = hr_org();
        $later = fabo_one('SELECT id FROM hr_shift_assignments WHERE employee_id = ? AND effective_from > ?', 'is', [$empId, $from]);
        if ($later) {
            throw new InvalidArgumentException('This employee already has a shift change after ' . hr_fmt_date($from)
                                             . '. Remove that one first, or choose a later date.');
        }
        $same = fabo_one('SELECT id, shift_id FROM hr_shift_assignments WHERE employee_id = ? AND effective_from = ?', 'is', [$empId, $from]);
        if ($same) {
            fabo_exec('UPDATE hr_shift_assignments SET shift_id = ? WHERE id = ?', 'ii', [$shiftId, (int) $same['id']]);
        } else {
            fabo_exec('UPDATE hr_shift_assignments SET effective_to = DATE_SUB(?, INTERVAL 1 DAY)
                       WHERE employee_id = ? AND effective_to IS NULL AND effective_from < ?', 'sis', [$from, $empId, $from]);
            fabo_exec('INSERT INTO hr_shift_assignments (org_id, employee_id, shift_id, effective_from, created_by)
                       VALUES (?,?,?,?,?)', 'iiiss', [$org, $empId, $shiftId, $from, hr_actor()]);
        }
        $name = hr_shift($shiftId)['name'] ?? '';
        fabo_exec('INSERT INTO hr_employee_history (employee_id, field, old_value, new_value, effective_on, changed_by)
                   VALUES (?,?,?,?,?,?)', 'isssss', [$empId, 'Shift', null, $name, $from, hr_actor()]);
        hr_audit('assign_shift', 'employee', $empId, 'Shift set to ' . $name . ' from ' . $from);
        unset($GLOBALS['hr_shift_assign_cache'][$empId]);
    });
}

function hr_shift_assignments(int $empId): array
{
    return fabo_all('SELECT a.*, s.name FROM hr_shift_assignments a JOIN hr_shifts s ON s.id = a.shift_id
                     WHERE a.employee_id = ? ORDER BY a.effective_from DESC', 'i', [$empId]);
}

/** The shift an employee works on a date: their assignment, else the organisation default. */
function hr_emp_shift(int $empId, string $date): ?array
{
    /* One query per employee per request, not one per day: a six-month
       register for fifty people asked the database nine thousand times. */
    $all = &$GLOBALS['hr_shift_assign_cache'];
    if (!isset($all[$empId])) {
        $all[$empId] = fabo_all('SELECT shift_id, effective_from, effective_to FROM hr_shift_assignments
                                 WHERE employee_id = ? ORDER BY effective_from DESC', 'i', [$empId]);
    }
    foreach ($all[$empId] as $a) {
        if ($a['effective_from'] <= $date && (!$a['effective_to'] || $a['effective_to'] >= $date)) {
            return hr_shift_cached((int) $a['shift_id']);
        }
    }
    return hr_default_shift();
}

function hr_shift_cached(int $id): ?array
{
    static $c = [];
    if (!array_key_exists($id, $c)) {
        $c[$id] = fabo_one('SELECT * FROM hr_shifts WHERE id = ?', 'i', [$id]);
    }
    return $c[$id];
}

function hr_default_shift(): ?array
{
    $c = &$GLOBALS['hr_default_shift_cache'];   // cleared when a default shift is set during the request
    $org = hr_org();
    if (!is_array($c) || !array_key_exists($org, $c)) {
        $c[$org] = fabo_one('SELECT * FROM hr_shifts WHERE org_id = ? AND is_default = 1 AND active = 1 LIMIT 1', 'i', [$org]);
    }
    return $c[$org];
}

/* ------------------------------------------------------------- holidays */

function hr_calendar_save(?int $id, array $p): int
{
    $name = hr_str($p['name'] ?? '', 120, true, 'Calendar name');
    $loc  = hr_ref_id($p['location_id'] ?? 0, 'hr_masters', 'Location');
    $dept = hr_ref_id($p['department_id'] ?? 0, 'hr_departments', 'Department');
    $id   = hr_master_upsert('hr_holiday_calendars', $id, 'Holiday calendar',
                ['name' => $name, 'location_id' => $loc, 'department_id' => $dept]);
    $hasDefault = fabo_one('SELECT id FROM hr_holiday_calendars WHERE org_id = ? AND is_default = 1', 'i', [hr_org()]);
    if (!empty($p['is_default']) || !$hasDefault) {
        fabo_exec('UPDATE hr_holiday_calendars SET is_default = (id = ?) WHERE org_id = ?', 'ii', [$id, hr_org()]);
    }
    return $id;
}

/**
 * Adds or edits a holiday. Refuses one that overlaps an active holiday in
 * the same calendar unless $p['allow_overlap'] says that is intended.
 */
function hr_holiday_save(?int $id, array $p): int
{
    $org  = hr_org();
    $cal  = hr_ref_id($p['calendar_id'] ?? 0, 'hr_holiday_calendars', 'Holiday calendar', true);
    $name = hr_str($p['name'] ?? '', 160, true, 'Holiday name');
    $from = hr_date($p['start_date'] ?? '', true, 'Holiday date');
    $to   = hr_date($p['end_date'] ?? '', false, 'End date') ?? $from;
    if ($to < $from) {
        throw new InvalidArgumentException('The end date is before the start date.');
    }
    if ((strtotime($to) - strtotime($from)) / 86400 > 31) {
        throw new InvalidArgumentException('A single holiday can span at most 31 days.');
    }
    $type = (string) ($p['type'] ?? 'public');
    if (!isset(hr_holiday_types()[$type])) {
        throw new InvalidArgumentException('Choose a holiday type.');
    }
    $optional  = in_array($type, ['optional', 'restricted'], true) || !empty($p['is_optional']) ? 1 : 0;
    $recurring = !empty($p['recurring']) ? 1 : 0;
    $desc      = hr_str($p['description'] ?? '', 255) ?: null;
    $active    = isset($p['active']) ? (!empty($p['active']) ? 1 : 0) : 1;

    if (empty($p['allow_overlap'])) {
        $clash = fabo_one('SELECT id, name, start_date FROM hr_holidays WHERE org_id = ? AND calendar_id = ? AND active = 1
                           AND start_date <= ? AND end_date >= ?' . ($id ? ' AND id <> ?' : ''),
                          'iiss' . ($id ? 'i' : ''), array_merge([$org, $cal, $to, $from], $id ? [$id] : []));
        if ($clash) {
            throw new InvalidArgumentException('“' . $clash['name'] . '” is already on ' . hr_fmt_date($clash['start_date'])
                . ' in this calendar. Tick “Allow on the same date” if both are intended.');
        }
    }
    $vals = [$cal, $name, $from, $to, $type, $optional, $recurring, $desc, $active];
    if ($id) {
        $before = fabo_one('SELECT * FROM hr_holidays WHERE id = ? AND org_id = ?', 'ii', [$id, $org]);
        if (!$before) {
            throw new InvalidArgumentException('That holiday no longer exists.');
        }
        hr_holiday_guard_locked($before['start_date'], $before['end_date']);
        hr_holiday_guard_locked($from, $to);
        fabo_exec('UPDATE hr_holidays SET calendar_id = ?, name = ?, start_date = ?, end_date = ?, type = ?, is_optional = ?,
                   recurring = ?, description = ?, active = ? WHERE id = ? AND org_id = ?', 'issssiisiii',
                  array_merge($vals, [$id, $org]));
        hr_audit('update', 'holiday', $id, 'Holiday updated: ' . $name, $before, $p);
        hr_config_log('holidays', $name, $before, ['calendar_id' => $cal, 'name' => $name, 'start_date' => $from,
                      'end_date' => $to, 'type' => $type, 'recurring' => $recurring, 'active' => $active]);
        return $id;
    }
    hr_holiday_guard_locked($from, $to);
    $new = fabo_exec('INSERT INTO hr_holidays (org_id, calendar_id, name, start_date, end_date, type, is_optional, recurring,
                      description, active, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)', 'iissssiisis',
                     array_merge([$org], $vals, [hr_actor()]));
    hr_audit('create', 'holiday', $new, 'Holiday added: ' . $name . ' on ' . $from);
    hr_config_log('holidays', $name, '', ['start_date' => $from, 'end_date' => $to, 'type' => $type, 'calendar_id' => $cal]);
    return $new;
}

/** A holiday inside a finalized payroll month cannot change: it would alter paid salaries silently. */
function hr_holiday_guard_locked(string $from, string $to): void
{
    for ($m = substr($from, 0, 7); $m <= substr($to, 0, 7); $m = date('Y-m', strtotime($m . '-01 +1 month'))) {
        $run = fabo_one('SELECT status FROM hr_payroll_runs WHERE org_id = ? AND period = ?', 'is', [hr_org(), $m]);
        if ($run && in_array($run['status'], ['finalized', 'payslips'], true)) {
            throw new InvalidArgumentException('Payroll for ' . date('F Y', strtotime($m . '-01'))
                . ' is finalized, so holidays in that month cannot change. Record a payroll adjustment instead.');
        }
    }
}

function hr_holiday_delete(int $id): array
{
    $row = fabo_one('SELECT * FROM hr_holidays WHERE id = ? AND org_id = ?', 'ii', [$id, hr_org()]);
    if (!$row) {
        return [false, 'That holiday no longer exists.'];
    }
    try {
        hr_holiday_guard_locked($row['start_date'], $row['end_date']);
    } catch (InvalidArgumentException $e) {
        return [false, $e->getMessage()];
    }
    fabo_exec('DELETE FROM hr_holidays WHERE id = ? AND org_id = ?', 'ii', [$id, hr_org()]);
    hr_audit('delete', 'holiday', $id, 'Holiday deleted: ' . $row['name'], $row, null);
    hr_config_log('holidays', $row['name'], $row, 'deleted');
    return [true, '“' . $row['name'] . '” deleted.'];
}

/**
 * Every holiday date between two dates for a set of calendars, recurring
 * ones repeated into each year. date => [name, type, optional, id].
 */
function hr_holiday_dates(array $calendarIds, string $from, string $to, bool $includeOptional = false): array
{
    $calendarIds = array_values(array_filter(array_map('intval', $calendarIds)));
    if (!$calendarIds) {
        return [];
    }
    $in  = implode(',', $calendarIds);
    $out = [];
    $rows = fabo_all('SELECT * FROM hr_holidays WHERE active = 1 AND calendar_id IN (' . $in . ')
                      AND ((start_date <= ? AND end_date >= ?) OR recurring = 1)', 'ss', [$to, $from]);
    $y1 = (int) substr($from, 0, 4);
    $y2 = (int) substr($to, 0, 4);
    foreach ($rows as $h) {
        if (!$includeOptional && $h['is_optional']) {
            continue;
        }
        $years = $h['recurring'] ? range(min($y1, (int) substr($h['start_date'], 0, 4)), $y2) : [null];
        foreach ($years as $y) {
            $s = $h['start_date'];
            $e = $h['end_date'];
            if ($y !== null) {
                if ($y < (int) substr($h['start_date'], 0, 4)) {
                    continue;   // a recurring holiday starts the year it was first entered
                }
                $shift = $y - (int) substr($s, 0, 4);
                $s = date('Y-m-d', strtotime($s . ' +' . $shift . ' year'));
                $e = date('Y-m-d', strtotime($e . ' +' . $shift . ' year'));
            }
            for ($d = max($s, $from); $d <= min($e, $to); $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
                $out[$d] ??= ['name' => $h['name'], 'type' => $h['type'], 'optional' => (int) $h['is_optional'], 'id' => (int) $h['id']];
            }
        }
    }
    ksort($out);
    return $out;
}

/**
 * Which holiday calendar applies to an employee: their own, else one made
 * for their location, else one for their department, else the default.
 */
function hr_emp_calendar_id(array $emp): ?int
{
    if (!empty($emp['holiday_calendar_id'])) {
        return (int) $emp['holiday_calendar_id'];
    }
    static $cals = [];
    $org = (int) $emp['org_id'];
    $cals[$org] ??= fabo_all('SELECT id, location_id, department_id, is_default FROM hr_holiday_calendars
                              WHERE org_id = ? AND active = 1', 'i', [$org]);
    foreach ($cals[$org] as $c) {
        if ($c['location_id'] && (int) $c['location_id'] === (int) ($emp['location_id'] ?? 0)) {
            return (int) $c['id'];
        }
    }
    foreach ($cals[$org] as $c) {
        if ($c['department_id'] && (int) $c['department_id'] === (int) ($emp['department_id'] ?? 0)) {
            return (int) $c['id'];
        }
    }
    foreach ($cals[$org] as $c) {
        if ($c['is_default']) {
            return (int) $c['id'];
        }
    }
    return null;
}

/* -------------------------------------------------------------- day map */

/**
 * What every day between $from and $to is for one employee.
 *
 *   date => [
 *     'employed' => joined on or before and not yet left,
 *     'off'      => a weekly off (employee's own pattern, else their shift's),
 *     'holiday'  => holiday name or null (optional holidays excluded),
 *     'shift'    => the shift row or null,
 *   ]
 */
function hr_day_map(array $emp, string $from, string $to): array
{
    $cal  = hr_emp_calendar_id($emp);
    $hols = $cal ? hr_holiday_dates([$cal], $from, $to) : [];
    $own  = trim((string) ($emp['weekly_offs'] ?? '')) !== '' ? hr_parse_offs($emp['weekly_offs']) : null;
    $out  = [];
    for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        $shift = hr_emp_shift((int) $emp['id'], $d);
        $offs  = $own ?? hr_parse_offs($shift['weekly_offs'] ?? '');
        $out[$d] = [
            'employed' => (empty($emp['date_of_joining']) || $emp['date_of_joining'] <= $d)
                       && (empty($emp['date_of_leaving']) || $emp['date_of_leaving'] >= $d),
            'off'      => hr_is_off($offs, $d),
            'holiday'  => $hols[$d]['name'] ?? null,
            'shift'    => $shift,
        ];
    }
    return $out;
}

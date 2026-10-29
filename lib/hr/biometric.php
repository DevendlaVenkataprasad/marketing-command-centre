<?php
/**
 * The biometric machine's punch report ("First/Last Access Records Report"):
 * importing it as attendance, and exporting attendance in the same layout.
 *
 * The report has a few title lines, then a heading row —
 *   No. | Person ID | Name | Department | Position | Gender | Date |
 *   Day Of Week | Timetable | First-In | Last-Out
 * — then one row per person per day, "-" where there was no punch, and a
 * "Checked by / Approved by" footer. Columns are found by their headings, so
 * a machine that orders them differently still imports.
 *
 * How a row becomes attendance:
 *   - the person is matched by the employee's Biometric ID, then Employee ID;
 *     anyone else is shown on the preview to create, link or skip;
 *   - First-In / Last-Out become check-in / check-out, and the status is
 *     worked out against the employee's shift (late, half day, overtime…);
 *   - a single punch is recorded and flagged "Missing check-in / check-out";
 *   - a day with no punch at all records nothing, so approved leave, holidays
 *     and weekly offs still show for it, and anything else reads as absent;
 *   - importing the same report again (say, after the day ends) updates the
 *     days it imported before. Days entered or corrected by hand are left
 *     alone unless HR ticks the box to replace them.
 */

require_once __DIR__ . '/attendance.php';
require_once __DIR__ . '/xls.php';

/** Punches closer together than this are one punch tapped twice, not a check-in and a check-out. */
const HR_BIO_DOUBLE_PUNCH_MINUTES = 10;

/** Heading words => field, lower-case, punctuation-insensitive. */
function hr_bio_columns(): array
{
    return [
        'personid' => 'pid', 'userid' => 'pid', 'employeeid' => 'pid', 'empid' => 'pid', 'enrollid' => 'pid', 'acno' => 'pid', 'id' => 'pid',
        'name' => 'name', 'employeename' => 'name', 'personname' => 'name',
        'department' => 'dept', 'dept' => 'dept',
        'position' => 'position', 'designation' => 'position',
        'gender' => 'gender',
        'date' => 'date', 'attendancedate' => 'date',
        'dayofweek' => 'dow', 'day' => 'dow',
        'timetable' => 'timetable', 'shift' => 'timetable',
        'firstin' => 'in', 'checkin' => 'in', 'intime' => 'in', 'in' => 'in',
        'lastout' => 'out', 'checkout' => 'out', 'outtime' => 'out', 'out' => 'out',
    ];
}

function hr_bio_key(string $h): string
{
    return preg_replace('/[^a-z0-9]/', '', mb_strtolower($h));
}

/**
 * Reads a punch report into
 *   ['title', 'range', 'rows' => [[line, pid, name, dept, position, gender, date, timetable, in, out]], 'skipped' => [line => why]]
 * $table is [[line, cells], ...] as the file readers return it.
 */
function hr_bio_parse(array $table): array
{
    $cols = null;
    $title = '';
    $range = '';
    $rows = [];
    $skipped = [];
    foreach ($table as [$line, $cells]) {
        $first = trim((string) ($cells[0] ?? ''));
        if ($cols === null) {
            $map = [];
            foreach ($cells as $i => $h) {
                $k = hr_bio_columns()[hr_bio_key((string) $h)] ?? null;
                if ($k && !isset($map[$k])) {
                    $map[$k] = $i;
                }
            }
            if (isset($map['pid'], $map['date']) && (isset($map['in']) || isset($map['out']))) {
                $cols = $map;
                continue;
            }
            if ($first !== '') {
                if ($title === '') {
                    $title = $first;
                } elseif ($range === '' && preg_match('/\d{4}-\d{2}-\d{2}/', $first)) {
                    $range = $first;
                }
            }
            continue;
        }
        $get = static fn(string $k) => isset($cols[$k]) ? trim((string) ($cells[$cols[$k]] ?? '')) : '';
        $pid = $get('pid');
        $rawDate = $get('date');
        if ($pid === '' && $rawDate === '') {
            continue;                       // blank line, or the "Checked by" footer
        }
        if ($pid === '' || $rawDate === '') {
            if (stripos(implode(' ', $cells), 'checked by') === false && stripos($first, 'date/time') === false) {
                $skipped[$line] = 'No Person ID or date.';
            }
            continue;
        }
        try {
            $date = hr_date(substr($rawDate, 0, 10), true, 'Date');
            $in   = hr_bio_time($get('in'));
            $out  = hr_bio_time($get('out'));
        } catch (InvalidArgumentException $e) {
            $skipped[$line] = $e->getMessage();
            continue;
        }
        $rows[] = ['line' => $line, 'pid' => $pid, 'name' => $get('name'), 'dept' => $get('dept'), 'position' => $get('position'),
                   'gender' => $get('gender'), 'date' => $date, 'timetable' => $get('timetable'), 'in' => $in, 'out' => $out];
    }
    if ($cols === null) {
        throw new InvalidArgumentException('This does not look like the machine\'s punch report: no heading row with “Person ID”, “Date” and '
                                         . '“First-In” / “Last-Out” was found. Upload the report exactly as the machine exports it.');
    }
    return ['title' => $title, 'range' => $range, 'rows' => $rows, 'skipped' => $skipped];
}

/** A punch time as HH:MM:SS, or null for "-" / blank. Accepts a date-time too. */
function hr_bio_time(string $v): ?string
{
    $v = trim($v);
    if ($v === '' || preg_match('/^-+$/', $v)) {
        return null;
    }
    if (is_numeric($v) && (float) $v < 1) {
        $s = (int) round((float) $v * 86400);
        return sprintf('%02d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60);
    }
    if (preg_match('/(\d{1,2}):(\d{2})(?::(\d{2}))?\s*([AaPp][Mm])?$/', $v, $m)) {
        $h = (int) $m[1];
        if (!empty($m[4])) {
            $h = $h % 12 + (strtolower($m[4]) === 'pm' ? 12 : 0);
        }
        if ($h < 24 && (int) $m[2] < 60) {
            return sprintf('%02d:%02d:%02d', $h, (int) $m[2], (int) ($m[3] ?? 0));
        }
    }
    throw new InvalidArgumentException('“' . $v . '” is not a time.');
}

/** The timetable most rows of a report carry, as ['10:00', '17:40'], or null. */
function hr_bio_report_timetable(array $parsed): ?array
{
    $count = [];
    foreach ($parsed['rows'] as $r) {
        if ($t = hr_bio_timetable($r['timetable'])) {
            $k = implode('-', $t);
            $count[$k] = ($count[$k] ?? 0) + 1;
        }
    }
    if (!$count) {
        return null;
    }
    arsort($count);
    return explode('-', (string) array_key_first($count));
}

/** An active shift with exactly these start and end times, or null. */
function hr_bio_match_shift(array $tt): ?int
{
    $s = fabo_one('SELECT id FROM hr_shifts WHERE org_id = ? AND active = 1 AND start_time = ? AND end_time = ? ORDER BY is_default DESC, id LIMIT 1',
                  'iss', [hr_org(), $tt[0] . ':00', $tt[1] . ':00']);
    return $s ? (int) $s['id'] : null;
}

/** "(10:00:00-17:40:00)" → ['10:00', '17:40'], or null. */
function hr_bio_timetable(string $t): ?array
{
    if (preg_match('/(\d{1,2}:\d{2})(?::\d{2})?\s*-\s*(\d{1,2}:\d{2})/', $t, $m)) {
        return [str_pad($m[1], 5, '0', STR_PAD_LEFT), str_pad($m[2], 5, '0', STR_PAD_LEFT)];
    }
    return null;
}

/* --------------------------------------------------------------- preview */

/**
 * Who is in the report and who they match:
 *   pid => ['pid', 'name', 'dept', 'position', 'gender', 'rows', 'punched', 'from', 'to',
 *           'emp' (matched employee row or null), 'suggest' (an employee with the same name, or null)]
 */
function hr_bio_people(array $parsed): array
{
    $byBio = [];
    $byCode = [];
    $byName = [];
    foreach (fabo_all('SELECT * FROM hr_employees WHERE org_id = ?', 'i', [hr_org()]) as $e) {
        if ($e['biometric_id'] !== null && $e['biometric_id'] !== '') {
            $byBio[mb_strtoupper($e['biometric_id'])] = $e;
        }
        $byCode[mb_strtoupper($e['emp_code'])] = $e;
        $byName[hr_bio_key($e['full_name'])][] = $e;
    }
    $people = [];
    foreach ($parsed['rows'] as $r) {
        $k = $r['pid'];
        if (!isset($people[$k])) {
            $u = mb_strtoupper($k);
            $emp = $byBio[$u] ?? null;
            if (!$emp && isset($byCode[$u]) && ($byCode[$u]['biometric_id'] === null || $byCode[$u]['biometric_id'] === '')) {
                $emp = $byCode[$u];
            }
            $same = $byName[hr_bio_key($r['name'])] ?? [];
            $suggest = !$emp && count($same) === 1 && ($same[0]['biometric_id'] === null || $same[0]['biometric_id'] === '') ? $same[0] : null;
            $people[$k] = ['pid' => $k, 'name' => $r['name'], 'dept' => $r['dept'], 'position' => $r['position'], 'gender' => $r['gender'],
                           'rows' => 0, 'punched' => 0, 'from' => $r['date'], 'to' => $r['date'], 'emp' => $emp, 'suggest' => $suggest];
        }
        $p = &$people[$k];
        $p['rows']++;
        $p['punched'] += ($r['in'] || $r['out']) ? 1 : 0;
        $p['from'] = min($p['from'], $r['date']);
        $p['to'] = max($p['to'], $r['date']);
        unset($p);
    }
    return $people;
}

/* ------------------------------------------------------- parked uploads */

/*
 * Between the preview and the import the parsed report is kept server-side
 * in uploads/ (which the web server never serves), under a random name tied
 * to the organisation and the person who uploaded it. It is deleted once
 * imported, and anything left over is cleared after a day.
 */
function hr_bio_park(array $parsed, string $fileName): string
{
    $dir = fabo_upload_dir();
    foreach (glob($dir . DIRECTORY_SEPARATOR . 'hrbio_*.json') ?: [] as $old) {
        if (filemtime($old) < time() - 86400) {
            @unlink($old);
        }
    }
    $token = bin2hex(random_bytes(16));
    file_put_contents($dir . DIRECTORY_SEPARATOR . 'hrbio_' . $token . '.json', json_encode([
        'org' => hr_org(), 'by' => hr_actor(), 'at' => time(), 'file' => $fileName, 'parsed' => $parsed,
    ]));
    return $token;
}

function hr_bio_unpark(string $token): ?array
{
    if (!preg_match('/^[0-9a-f]{32}$/', $token)) {
        return null;
    }
    $f = fabo_upload_dir() . DIRECTORY_SEPARATOR . 'hrbio_' . $token . '.json';
    $d = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    if (!is_array($d) || (int) $d['org'] !== hr_org() || $d['by'] !== hr_actor() || $d['at'] < time() - 86400) {
        return null;
    }
    return $d;
}

function hr_bio_discard(string $token): void
{
    if (preg_match('/^[0-9a-f]{32}$/', $token)) {
        @unlink(fabo_upload_dir() . DIRECTORY_SEPARATOR . 'hrbio_' . $token . '.json');
    }
}

/* ----------------------------------------------------------------- apply */

/**
 * Imports the report. $actions: pid => 'skip' | 'new' | 'emp:<id>' for
 * people the preview could not match (matched people are always imported).
 * $opt: replace_manual (bool), use_pid_as_code (bool), make_shift (bool).
 * Returns a summary with per-line problems.
 */
function hr_bio_apply(array $parsed, array $actions, array $opt): array
{
    $people = hr_bio_people($parsed);
    $sum = ['days' => 0, 'updated' => 0, 'kept' => 0, 'no_punch' => 0, 'created' => [], 'linked' => [], 'skipped_people' => [],
            'errors' => [], 'shift' => null];

    /* The machine's timetable as a shift. People added from the report go on
       it; when HR ticked the option and no shift has these hours it is made,
       and it becomes the default only if there is no default yet. */
    $tt = hr_bio_report_timetable($parsed);
    $ttShift = $tt ? hr_bio_match_shift($tt) : null;
    if ($tt && !$ttShift && !empty($opt['make_shift'])) {
        $span = hr_shift_span_minutes($tt[0], $tt[1]) / 60;
        $name = 'Office hours ' . $tt[0] . '–' . $tt[1];
        $existing = fabo_one('SELECT id FROM hr_shifts WHERE org_id = ? AND name = ?', 'is', [hr_org(), $name]);
        $ttShift = $existing ? (int) $existing['id'] : hr_shift_save(null, [
            'name' => $name, 'start_time' => $tt[0], 'end_time' => $tt[1], 'break_minutes' => 0, 'grace_minutes' => 15,
            'early_grace_minutes' => 15, 'full_day_hours' => number_format(max(0.5, $span - 1), 2, '.', ''),
            'half_day_hours' => number_format($span / 2, 2, '.', ''), 'off_0' => 'all', 'is_default' => hr_default_shift() ? 0 : 1]);
        $GLOBALS['hr_shift_assign_cache'] = [];
        $sum['shift'] = $name;
        $sum['shift_default'] = (bool) (hr_shift($ttShift)['is_default'] ?? false);
    }

    // Decide who each Person ID is.
    $empFor = [];
    $canCreate = hr_is_admin() || hr_scope('employees', 'create') === 'org';
    foreach ($people as $pid => $p) {
        if ($p['emp']) {
            $empFor[$pid] = (int) $p['emp']['id'];
            continue;
        }
        $act = (string) ($actions[$pid] ?? 'skip');
        try {
            if (str_starts_with($act, 'emp:')) {
                $eid = (int) substr($act, 4);
                $e = hr_employee($eid);
                if (!$e) {
                    throw new InvalidArgumentException('the employee chosen for ' . $pid . ' no longer exists');
                }
                if ($e['biometric_id'] !== null && $e['biometric_id'] !== '' && $e['biometric_id'] !== $pid) {
                    throw new InvalidArgumentException($e['full_name'] . ' already has Biometric ID ' . $e['biometric_id']);
                }
                fabo_exec('UPDATE hr_employees SET biometric_id = ? WHERE id = ? AND org_id = ?', 'sii', [$pid, $eid, hr_org()]);
                hr_audit('update', 'employee', $eid, 'Biometric ID ' . $pid . ' linked from the machine report');
                $empFor[$pid] = $eid;
                $sum['linked'][] = $e['full_name'] . ' ← ' . $pid;
            } elseif ($act === 'new') {
                if (!$canCreate) {
                    throw new InvalidArgumentException('you cannot add employees');
                }
                $empFor[$pid] = hr_bio_create_employee($p, !empty($opt['use_pid_as_code']), $ttShift);
                $sum['created'][] = $p['name'] . ' (' . $pid . ')';
            } else {
                $sum['skipped_people'][] = $p['name'] . ' (' . $pid . ')';
            }
        } catch (InvalidArgumentException $e) {
            $sum['errors']['Person ' . $pid] = 'Not imported: ' . $e->getMessage() . '.';
        }
    }

    // The days.
    foreach ($parsed['rows'] as $r) {
        $eid = $empFor[$r['pid']] ?? null;
        if (!$eid) {
            continue;
        }
        if (!$r['in'] && !$r['out']) {
            $sum['no_punch']++;
            continue;
        }
        if (!hr_emp_allowed($eid, 'attendance', 'create')) {
            $sum['errors']['Line ' . $r['line']] = 'You cannot record attendance for ' . $r['name'] . '.';
            continue;
        }
        $cur = hr_attendance_row($eid, $r['date']);
        if ($cur && !in_array($cur['source'], ['device', 'import'], true) && empty($opt['replace_manual'])) {
            $sum['kept']++;
            continue;
        }
        $in = $r['in'];
        $out = $r['out'];
        if ($in && $out) {
            /* Two taps on the machine minutes apart are one arrival, not a
               day's work: the "Last-Out" is dropped and the day shows a
               missing check-out until the real one arrives in a later
               report. A Last-Out earlier than the First-In is only a real
               check-out on a night shift. */
            $gap = (strtotime('2000-01-01 ' . $out) - strtotime('2000-01-01 ' . $in)) / 60;
            $night = ($sh = hr_emp_shift($eid, $r['date'])) && hr_shift_overnight($sh);
            if (($gap >= 0 && $gap < HR_BIO_DOUBLE_PUNCH_MINUTES) || ($gap < 0 && !$night)) {
                $out = null;
            }
        }
        try {
            hr_attendance_save($eid, $r['date'], [
                'check_in' => $in ?: '', 'check_out' => $out ?: '',
                'remarks' => 'Biometric' . (!$in ? ': no check-in punch' : (!$out ? ': no check-out punch' : '')),
                'overtime_approved' => $cur['overtime_approved'] ?? 0,
            ], 'device');
            $cur ? $sum['updated']++ : $sum['days']++;
        } catch (InvalidArgumentException $e) {
            $sum['errors']['Line ' . $r['line']] = $r['name'] . ', ' . hr_fmt_date($r['date']) . ': ' . $e->getMessage();
        } catch (mysqli_sql_exception $e) {
            $sum['errors']['Line ' . $r['line']] = fabo_db_words($e);
        }
    }
    hr_audit('import', 'attendance', null, 'Biometric report imported: ' . $sum['days'] . ' new day(s), ' . $sum['updated'] . ' updated, '
           . count($sum['created']) . ' employee(s) added' . ($sum['errors'] ? ', ' . count($sum['errors']) . ' problem(s)' : ''));
    return $sum;
}

/**
 * Adds an employee from the report. Department and designation are matched
 * by name and added when missing; the date of joining is their first day in
 * the report, to be corrected on their record if they joined earlier.
 */
function hr_bio_create_employee(array $p, bool $pidAsCode, ?int $shiftId = null): int
{
    $dept = null;
    if ($p['dept'] !== '') {
        $d = fabo_one('SELECT id FROM hr_departments WHERE org_id = ? AND name = ?', 'is', [hr_org(), mb_substr($p['dept'], 0, 120)]);
        $dept = $d ? (int) $d['id'] : hr_department_save(null, ['name' => mb_substr($p['dept'], 0, 120)]);
    }
    $desig = null;
    if ($p['position'] !== '') {
        $d = fabo_one('SELECT id FROM hr_designations WHERE org_id = ? AND name = ?', 'is', [hr_org(), mb_substr($p['position'], 0, 120)]);
        $desig = $d ? (int) $d['id'] : hr_designation_save(null, ['name' => mb_substr($p['position'], 0, 120)]);
    }
    $code = '';
    if ($pidAsCode && preg_match('/^[A-Za-z0-9][A-Za-z0-9\/_.-]{0,29}$/', $p['pid'])
        && !fabo_one('SELECT id FROM hr_employees WHERE org_id = ? AND emp_code = ?', 'is', [hr_org(), strtoupper($p['pid'])])) {
        $code = $p['pid'];
    }
    $g = mb_strtolower($p['gender']);
    $id = hr_employee_save(null, [
        'full_name' => $p['name'] !== '' ? $p['name'] : 'Person ' . $p['pid'], 'emp_code' => $code, 'biometric_id' => $p['pid'],
        'department_id' => $dept, 'designation_id' => $desig, 'date_of_joining' => $p['from'], 'status' => 'active',
        'gender' => $g === 'male' ? 'Male' : ($g === 'female' ? 'Female' : ''), 'shift_id' => $shiftId ?: '',
        'notes' => 'Added from the biometric report. Date of joining set to their first day in the report — correct it if they joined earlier.',
    ], false);
    return $id;
}

/* ---------------------------------------------------------------- export */

/** Day-of-week the way the machine prints it: "Thu." */
function hr_bio_dow(string $date): string
{
    return date('D', strtotime($date)) . '.';
}

/** A shift as the machine's Timetable column: "(10:00:00-17:40:00)". */
function hr_bio_timetable_text(?array $shift): string
{
    if (!$shift) {
        return '';
    }
    return '(' . substr($shift['start_time'], 0, 8) . '-' . substr($shift['end_time'], 0, 8) . ')';
}

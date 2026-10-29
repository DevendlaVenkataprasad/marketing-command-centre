<?php
/**
 * Holiday Management — the holidays in each calendar, as a list and as a
 * year calendar, with add, edit, import and export.
 */

require_once __DIR__ . '/parts.php';

$year = (int) ($_GET['year'] ?? date('Y'));
if ($year < 2000 || $year > 2100) { $year = (int) date('Y'); }
$cals = hr_calendars_map(false);
$cal  = (int) ($_GET['calendar'] ?? 0);
if ($cal && !isset($cals[$cal])) { $cal = 0; }
$view = ($_GET['view'] ?? 'list') === 'calendar' ? 'calendar' : 'list';
$keep = array_filter(['year' => $year, 'calendar' => $cal, 'view' => $view]);
$back = hr_url('holidays', $keep);
$flash = null;
$importResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    if ($a === 'hol_save') {
        $id = (int) ($_POST['id'] ?? 0) ?: null;
        hr_need('holidays', $id ? 'edit' : 'create');
        $flash = hr_action(static function () use ($id) {
            hr_holiday_save($id, $_POST);
            return 'Holiday saved. Attendance, leave and payroll use it from now on.';
        }, $back);
    } elseif ($a === 'hol_delete') {
        hr_need('holidays', 'delete');
        [$ok, $msg] = hr_holiday_delete((int) ($_POST['id'] ?? 0));
        hr_redirect($back, [$ok ? 'ok' : 'err', $msg]);
    } elseif ($a === 'hol_import') {
        hr_need('holidays', 'create');
        try {
            [$path, $name] = hr_uploaded_table('file');
            $dry = empty($_POST['commit']);
            $calId = (int) ($_POST['calendar_id'] ?? 0);
            $rows = hr_read_table_file($path, $name);
            [, $head] = array_shift($rows) ?? [0, []];
            $idx = [];
            foreach ($head as $i => $h) { $idx[mb_strtolower(trim((string) $h))] = $i; }
            $col = static fn(array $names) => array_reduce($names, static fn($c, $n) => $c ?? ($idx[$n] ?? null));
            $cName = $col(['holiday', 'holiday name', 'name']);
            $cFrom = $col(['date', 'holiday date', 'from', 'start date']);
            if ($cName === null || $cFrom === null) {
                throw new InvalidArgumentException('The first row must be headings including “Holiday Name” and “Date”. Download the template.');
            }
            $cTo = $col(['to', 'end date']); $cType = $col(['type', 'holiday type']); $cRec = $col(['recurring', 'recurring every year']); $cDesc = $col(['description']);
            $types = array_change_key_case(array_flip(hr_holiday_types()));
            $done = 0; $errors = [];
            foreach ($rows as [$line, $cells]) {
                if (!array_filter($cells, static fn($c) => trim((string) $c) !== '')) { continue; }
                try {
                    $t = mb_strtolower(trim((string) ($cType !== null ? ($cells[$cType] ?? '') : '')));
                    $p = ['calendar_id' => $calId, 'name' => $cells[$cName] ?? '', 'start_date' => $cells[$cFrom] ?? '',
                          'end_date' => $cTo !== null ? ($cells[$cTo] ?? '') : '', 'type' => $t === '' ? 'public' : ($types[$t] ?? (isset(hr_holiday_types()[$t]) ? $t : 'x')),
                          'recurring' => $cRec !== null && in_array(mb_strtolower(trim((string) ($cells[$cRec] ?? ''))), ['yes', 'y', '1', 'true'], true),
                          'description' => $cDesc !== null ? ($cells[$cDesc] ?? '') : ''];
                    if ($p['type'] === 'x') { throw new InvalidArgumentException('Type “' . $t . '” is not one of: ' . implode(', ', hr_holiday_types()) . '.'); }
                    if ($dry) {
                        hr_ref_id($calId, 'hr_holiday_calendars', 'Holiday calendar', true);
                        hr_date($p['start_date'], true, 'Date');
                        hr_str($p['name'], 160, true, 'Holiday name');
                    } else {
                        hr_holiday_save(null, $p);
                    }
                    $done++;
                } catch (InvalidArgumentException $e) {
                    $errors[$line] = $e->getMessage();
                }
            }
            $importResult = [['created' => $done, 'errors' => $errors, 'rows' => count($rows)], $dry];
            if (!$dry) { hr_audit('import', 'holiday', null, 'Imported ' . $done . ' holidays'); }
        } catch (Throwable $e) {
            $flash = ['err', fabo_user_error($e)];
        }
    } else {
        $flash = hr_parts_post($back);
    }
}

$where = ' WHERE h.org_id = ? AND (YEAR(h.start_date) = ? OR YEAR(h.end_date) = ? OR (h.recurring = 1 AND YEAR(h.start_date) <= ?))';
$params = [hr_org(), $year, $year, $year];
if ($cal) { $where .= ' AND h.calendar_id = ' . $cal; }
$rows = fabo_all('SELECT h.*, c.name AS calendar FROM hr_holidays h JOIN hr_holiday_calendars c ON c.id = h.calendar_id' . $where
               . ' ORDER BY DATE_FORMAT(h.start_date, "%m-%d"), h.name', 'iiii', $params);
$editId = (int) ($_GET['edit'] ?? 0);
$edit = $editId ? fabo_one('SELECT * FROM hr_holidays WHERE id = ? AND org_id = ?', 'ii', [$editId, hr_org()]) : null;

$acts = '';
if (hr_report_allowed('holiday_calendar') || hr_can('holidays', 'export')) {
    $acts .= '<a class="btn btn-sm" href="' . e(hr_url('file', ['kind' => 'report', 'report' => 'holiday_calendar', 'fmt' => 'xlsx', 'year' => $year, 'calendar' => $cal])) . '">Export (Excel)</a>'
           . '<a class="btn btn-sm" href="' . e(hr_url('file', ['kind' => 'report', 'report' => 'holiday_calendar', 'fmt' => 'pdf', 'year' => $year, 'calendar' => $cal])) . '">Download PDF</a>';
}
hr_layout_start('Holiday Management', 'Holidays here are skipped in leave counts, shown in attendance, and paid in payroll for the employees whose calendar they are in.',
                ['Holidays' => ''], $acts);
echo hr_flash_html($flash);
if ($importResult) { echo hr_import_report($importResult[0], $importResult[1]); }

if (!$cals) {
    echo '<div class="hr-warn">Create a holiday calendar first. Most companies need one; add more for other locations or departments.</div>';
    hr_part_calendars($back, $keep);
    hr_layout_end();
    return;
}

$years = [];
for ($y = (int) date('Y') + 2; $y >= (int) date('Y') - 3; $y--) { $years[$y] = (string) $y; }
echo '<form method="get" class="card hr-filters"><input type="hidden" name="p" value="holidays">'
   . '<div class="grp"><label for="hy">Year</label><select id="hy" name="year" data-autosubmit>' . hr_options($years, $year) . '</select></div>'
   . '<div class="grp"><label for="hc">Calendar</label><select id="hc" name="calendar" data-autosubmit>' . hr_options($cals, $cal, 'All calendars') . '</select></div>'
   . '<div class="grp"><label for="hv">View</label><select id="hv" name="view" data-autosubmit>' . hr_options(['list' => 'List', 'calendar' => 'Calendar'], $view) . '</select></div>'
   . '<noscript><button class="btn btn-sm">Show</button></noscript></form>';

if ($view === 'list') {
    echo '<div class="card"><h2>Holidays in ' . $year . ' (' . count($rows) . ')</h2>';
    if (!$rows) {
        echo hr_empty('No holidays for this year yet.', hr_can('holidays', 'create') ? ' Add them below or import a list.' : '');
    } else {
        echo '<div class="scrollx"><table class="data hr-table stack"><thead><tr><th>Date</th><th>Day</th><th>Holiday</th><th>Type</th><th>Calendar</th><th>Repeats</th><th>Status</th><th></th></tr></thead><tbody>';
        foreach ($rows as $h) {
            $s = $h['recurring'] ? $year . substr($h['start_date'], 4) : $h['start_date'];
            $e = $h['recurring'] ? date('Y-m-d', strtotime($s . ' +' . ((strtotime($h['end_date']) - strtotime($h['start_date'])) / 86400) . ' day')) : $h['end_date'];
            echo '<tr><td data-l="Date" class="nowrap">' . e(hr_fmt_date($s)) . ($e !== $s ? ' – ' . e(hr_fmt_date($e)) : '') . '</td><td data-l="Day">' . e(date('l', strtotime($s))) . '</td>'
               . '<td data-l="Holiday"><strong>' . e($h['name']) . '</strong>' . ($h['description'] ? '<div class="muted small">' . e($h['description']) . '</div>' : '') . '</td>'
               . '<td data-l="Type">' . e(hr_holiday_types()[$h['type']] ?? $h['type']) . ($h['is_optional'] ? ' <span class="pill grey">Optional</span>' : '') . '</td>'
               . '<td data-l="Calendar">' . e($h['calendar']) . '</td><td data-l="Repeats">' . ($h['recurring'] ? 'Every year' : '—') . '</td>'
               . '<td data-l="Status">' . hr_badge($h['active'] ? 'active' : 'inactive') . '</td><td class="hr-acts">';
            if (hr_can('holidays', 'edit')) {
                echo '<a class="btn btn-sm" href="' . e(hr_url('holidays', $keep + ['edit' => $h['id']])) . '#hol-form">Edit</a>';
            }
            if (hr_can('holidays', 'delete')) {
                echo hr_button_form('Delete', ['action' => 'hol_delete', 'id' => $h['id']], 'btn btn-sm btn-danger', 'Delete “' . $h['name'] . '”?');
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '<p class="hr-hint">Optional and restricted holidays are listed for information. They are not days off automatically — an employee takes them as leave.</p></div>';
} else {
    $dates = hr_holiday_dates($cal ? [$cal] : array_keys(hr_calendars_map()), $year . '-01-01', $year . '-12-31', true);
    echo '<div class="card"><h2>' . $year . ' at a glance</h2><div class="hr-two" style="grid-template-columns:repeat(auto-fit,minmax(230px,1fr))">';
    for ($m = 1; $m <= 12; $m++) {
        $first = sprintf('%04d-%02d-01', $year, $m);
        echo '<div><h3 style="margin:0 0 6px;font-size:13px">' . date('F', strtotime($first)) . '</h3><table class="hr-cal" style="width:100%"><thead><tr>';
        foreach (['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'] as $d) { echo '<th>' . $d . '</th>'; }
        echo '</tr></thead><tbody><tr>';
        $pad = ((int) date('N', strtotime($first))) - 1;
        echo str_repeat('<td class="c"></td>', $pad);
        $col = $pad;
        for ($d = $first; substr($d, 0, 7) === substr($first, 0, 7); $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            if ($col === 7) { echo '</tr><tr>'; $col = 0; }
            $h = $dates[$d] ?? null;
            echo '<td class="c' . ($h ? ($h['optional'] ? ' st-MP' : ' st-H') : '') . '"' . ($h ? ' title="' . e($h['name']) . '"' : '') . '>' . (int) substr($d, 8) . '</td>';
            $col++;
        }
        echo str_repeat('<td class="c"></td>', (7 - $col) % 7) . '</tr></tbody></table></div>';
    }
    echo '</div><div class="hr-legend"><span><b class="st-H">15</b> Holiday</span><span><b class="st-MP">15</b> Optional holiday</span></div></div>';
}

if (($edit && hr_can('holidays', 'edit')) || (!$edit && hr_can('holidays', 'create'))) {
    $v = $edit ?? ['calendar_id' => $cal ?: (array_key_first(hr_calendars_map()) ?? ''), 'name' => '', 'start_date' => '', 'end_date' => '', 'type' => 'public', 'recurring' => 0, 'description' => '', 'active' => 1];
    echo '<div class="card" id="hol-form"><h2>' . ($edit ? 'Edit holiday' : 'Add a holiday') . '</h2>' . hr_form_open($back, 'class="hr-form"')
       . '<input type="hidden" name="action" value="hol_save">' . ($edit ? '<input type="hidden" name="id" value="' . (int) $edit['id'] . '">' : '') . '<div class="formgrid">'
       . hr_field('Holiday name', hr_input('name', $v['name'], 'text', ['required' => true, 'maxlength' => 160, 'placeholder' => 'e.g. Independence Day']), true)
       . hr_field('Calendar', hr_select('calendar_id', hr_calendars_map(), $v['calendar_id'], '', ['required' => true]), true)
       . hr_field('Date', hr_input('start_date', $v['start_date'], 'date', ['required' => true]), true)
       . hr_field('Last day (for a multi-day holiday)', hr_input('end_date', $v['end_date'] !== $v['start_date'] ? $v['end_date'] : '', 'date'))
       . hr_field('Type', hr_select('type', hr_holiday_types(), $v['type']), true, 'Optional and restricted holidays are not automatic days off.')
       . hr_field('Description', hr_input('description', $v['description'] ?? '', 'text', ['maxlength' => 255]))
       . '</div><div class="formgrid"><div class="field">' . hr_checkbox('recurring', (bool) $v['recurring'], 'Repeats every year on the same date') . '</div>'
       . '<div class="field">' . hr_checkbox('allow_overlap', false, 'Allow on the same date as another holiday in this calendar') . '</div>'
       . ($edit ? '<div class="field"><label>Status</label>' . hr_select('active', ['1' => 'Active', '0' => 'Inactive'], (string) $v['active']) . '</div>' : '')
       . '</div><div class="hr-formfoot"><button class="btn btn-primary">' . ($edit ? 'Save holiday' : 'Add holiday') . '</button>'
       . ($edit ? '<a class="btn" href="' . e(hr_url('holidays', $keep)) . '">Cancel</a>' : '') . '</div></form></div>';
}

if (hr_can('holidays', 'create')) {
    echo '<div class="card"><h2>Import holidays</h2><p class="mt0">Columns: Holiday Name, Date, End Date (optional), Type, Recurring (Yes/No), Description. '
       . '<a href="' . e(hr_url('file', ['kind' => 'template', 'what' => 'holidays'])) . '">Download template</a>. Duplicates in the same calendar are refused line by line.</p>'
       . hr_form_open('', 'class="hr-form"', true) . '<input type="hidden" name="action" value="hol_import"><div class="formgrid">'
       . hr_field('Into calendar', hr_select('calendar_id', hr_calendars_map(), $cal ?: '', '', ['required' => true]), true)
       . hr_field('File (.xls, .xlsx or .csv)', '<input type="file" name="file" accept=".xls,.xlsx,.csv" required>', true) . '</div>'
       . '<div class="hr-formfoot"><button class="btn" name="commit" value="">Check file</button><button class="btn btn-primary" name="commit" value="1" data-confirm="Import every valid row?">Import</button></div></form></div>';
}
hr_part_calendars($back, $keep);

hr_layout_end();

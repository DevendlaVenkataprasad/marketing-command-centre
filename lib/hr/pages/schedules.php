<?php
/**
 * Shifts & Work Calendars — shift timings, weekly-off patterns, and who is
 * on which shift from when.
 */

require_once __DIR__ . '/parts.php';

$tabs = ['shifts' => 'Shifts & weekly offs', 'assign' => 'Assign employees', 'calendars' => 'Work calendars'];
$tab  = hr_tab($tabs, 'shifts');
$keep = ['tab' => $tab];
$back = hr_url('schedules', $keep);
$flash = $_SERVER['REQUEST_METHOD'] === 'POST' ? hr_parts_post($back) : null;

hr_layout_start('Shifts & Work Calendars', 'Shift timings drive late arrivals, half days and overtime. Weekly offs and holidays drive leave counts and payroll.',
                ['HR Setup' => hr_url('setup'), 'Shifts & Work Calendars' => '']);
echo hr_flash_html($flash);
hr_tabs('schedules', $tabs, $tab);

if ($tab === 'shifts') {
    hr_part_shifts($back, $keep);
} elseif ($tab === 'calendars') {
    hr_part_calendars($back, $keep);
} else {
    $dept = (int) ($_GET['department'] ?? 0);
    $f = ['department' => $dept, 'status' => 'working'];
    [$emps] = hr_employees_list($f, 'name', 'asc', 0, 2000);
    $canEdit = hr_can_any(hr_cfg('schedules', 'edit'));
    $shifts = hr_shifts_map();
    echo '<form method="get" class="card hr-filters"><input type="hidden" name="p" value="schedules"><input type="hidden" name="tab" value="assign">'
       . '<div class="grp"><label for="f-dept">Department</label><select id="f-dept" name="department" data-autosubmit>' . hr_options(hr_departments_map(), $dept, 'All departments')
       . '</select></div><noscript><button class="btn btn-sm">Apply</button></noscript></form>';
    if (!$shifts) {
        echo '<div class="hr-warn">Add a shift first.</div>';
    }
    echo '<div class="card"><h2>Current shifts</h2>';
    if (!$emps) {
        echo hr_empty('No current employees.');
    } else {
        echo $canEdit && $shifts ? hr_form_open($back, 'data-confirm="Move the ticked employees to this shift from the chosen date?"') . '<input type="hidden" name="action" value="shift_assign">' : '';
        echo '<div class="scrollx"><table class="data hr-table stack"><thead><tr>' . ($canEdit ? '<th><input type="checkbox" data-checkall="emp[]" aria-label="Select all"></th>' : '')
           . '<th>Employee</th><th>Department</th><th>Shift today</th><th>Since</th><th>Weekly offs</th></tr></thead><tbody>';
        $def = hr_default_shift();
        foreach ($emps as $e) {
            $a = hr_emp_shift_now((int) $e['id']);
            $s = $a ? hr_shift_cached((int) $a['shift_id']) : $def;
            echo '<tr>' . ($canEdit ? '<td><input type="checkbox" name="emp[]" value="' . (int) $e['id'] . '" aria-label="Select ' . e($e['full_name']) . '"></td>' : '')
               . '<td data-l="Employee">' . hr_person_cell($e) . '</td><td data-l="Department">' . e($e['department'] ?? '—') . '</td>'
               . '<td data-l="Shift">' . ($s ? e($s['name']) . (!$a ? ' <span class="muted small">(default)</span>' : '') : '<span class="muted">None</span>') . '</td>'
               . '<td data-l="Since">' . e($a ? hr_fmt_date($a['effective_from']) : '—') . '</td>'
               . '<td data-l="Weekly offs">' . e($e['weekly_offs'] ? hr_offs_words($e['weekly_offs']) . ' (own)' : hr_offs_words($s['weekly_offs'] ?? '')) . '</td></tr>';
        }
        echo '</tbody></table></div>';
        if ($canEdit && $shifts) {
            echo '<div class="formgrid" style="margin-top:12px">' . hr_field('Move ticked employees to', hr_select('shift_id', $shifts, '', 'Choose a shift', ['required' => true]), true)
               . hr_field('From', hr_input('from', date('Y-m-d'), 'date', ['required' => true]), true, 'Their previous shift ends the day before. History is kept.')
               . '</div><button class="btn btn-primary">Assign shift</button></form>';
        }
    }
    echo '</div>';
}
hr_layout_end();

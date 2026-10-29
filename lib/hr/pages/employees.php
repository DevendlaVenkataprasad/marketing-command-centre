<?php
/**
 * Employee Management — the register: search, filters, sorting, pages,
 * import and export.
 */

$flash = null;
$importResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import') {
    hr_need('employees', 'create');
    if (hr_scope('employees', 'create') !== 'org') {
        fabo_deny('Importing employees needs organisation-wide rights.', 403);
    }
    try {
        [$path, $name] = hr_uploaded_table('file');
        $dry = empty($_POST['commit']);
        $importResult = [hr_employee_import($path, $name, $dry), $dry];
    } catch (InvalidArgumentException $e) {
        $flash = ['err', $e->getMessage()];
    } catch (Throwable $e) {
        $flash = ['err', fabo_user_error($e)];
    }
}

$f = [
    'q'           => trim((string) ($_GET['q'] ?? '')),
    'department'  => (int) ($_GET['department'] ?? 0),
    'designation' => (int) ($_GET['designation'] ?? 0),
    'type'        => (int) ($_GET['type'] ?? 0),
    'location'    => (int) ($_GET['location'] ?? 0),
    'manager'     => (int) ($_GET['manager'] ?? 0),
    'status'      => array_key_exists('status', $_GET) ? (string) $_GET['status'] : 'working',
];
$sort = (string) ($_GET['sort'] ?? 'name');
$dir  = (string) ($_GET['dir'] ?? 'asc');
$q = array_filter($f, static fn($v) => $v !== '' && $v !== 0) + ['status' => $f['status']];
$q += array_filter(['sort' => $_GET['sort'] ?? '', 'dir' => $_GET['dir'] ?? '']);

[, $total] = hr_employees_list($f, $sort, $dir, 0, 1);
[$offset, $pager] = hr_pager($total, 25, 'employees', $q);
[$rows] = hr_employees_list($f, $sort, $dir, $offset, 25);

$acts = '';
if (hr_can('employees', 'create') && hr_scope('employees', 'create') === 'org') {
    $acts .= '<a class="btn btn-sm btn-primary" href="' . e(hr_url('employee', ['new' => 1])) . '">+ Add employee</a>';
}
if (hr_can('employees', 'export') || hr_is_admin()) {
    $acts .= '<a class="btn btn-sm" href="' . e(hr_url('file', ['kind' => 'report', 'report' => 'employee_register', 'fmt' => 'xlsx',
             'department' => $f['department'], 'status' => $f['status'] === 'working' ? '' : $f['status']])) . '">Export (Excel)</a>';
}
$acts .= '<a class="btn btn-sm" href="' . e(hr_url('orgchart')) . '">Org chart</a>';
hr_layout_start('Employees', $total . ' employee(s) match the filters.', ['Employees' => ''], $acts);
echo hr_flash_html($flash);
if ($importResult) {
    echo hr_import_report($importResult[0], $importResult[1]);
}

$statusOpts = ['working' => 'Current (active, probation, notice)', '' => 'All statuses'] + hr_emp_statuses();
echo '<form method="get" class="card hr-filters" role="search"><input type="hidden" name="p" value="employees">'
   . '<div class="grp"><label for="f-q">Search</label><input id="f-q" type="search" name="q" value="' . e($f['q']) . '" placeholder="Name, ID, email or phone"></div>'
   . '<div class="grp"><label for="f-d">Department</label><select id="f-d" name="department" data-autosubmit>' . hr_options(hr_departments_map(false), $f['department'], 'All') . '</select></div>'
   . '<div class="grp"><label for="f-g">Designation</label><select id="f-g" name="designation" data-autosubmit>' . hr_options(hr_designations_map(false), $f['designation'], 'All') . '</select></div>'
   . '<div class="grp"><label for="f-t">Employment type</label><select id="f-t" name="type" data-autosubmit>' . hr_options(hr_master_map('employment_type', false), $f['type'], 'All') . '</select></div>'
   . '<div class="grp"><label for="f-l">Location</label><select id="f-l" name="location" data-autosubmit>' . hr_options(hr_master_map('location', false), $f['location'], 'All') . '</select></div>'
   . '<div class="grp"><label for="f-s">Status</label><select id="f-s" name="status" data-autosubmit>' . hr_options($statusOpts, $f['status']) . '</select></div>'
   . ($f['manager'] ? '<input type="hidden" name="manager" value="' . (int) $f['manager'] . '">' : '')
   . '<button class="btn btn-sm btn-primary">Search</button>'
   . ($f['q'] !== '' || $f['department'] || $f['designation'] || $f['type'] || $f['location'] || $f['manager'] || $f['status'] !== 'working'
        ? '<a class="btn btn-sm" href="' . e(hr_url('employees')) . '">Clear</a>' : '') . '</form>';

if ($f['manager'] && ($m = hr_employee($f['manager']))) {
    echo '<div class="hr-note">Showing the people who report to <strong>' . e($m['full_name']) . '</strong>.</div>';
}

echo '<div class="card">';
if (!$rows) {
    $filtered = $f['q'] !== '' || $f['department'] || $f['designation'] || $f['type'] || $f['location'] || $f['manager'] || $f['status'] !== 'working';
    echo hr_empty($filtered ? 'No employees match these filters.' : 'No employees yet.',
                  hr_can('employees', 'create') ? ' Add one, or import a spreadsheet below.' : '');
} else {
    echo '<div class="scrollx"><table class="data hr-table stack"><thead><tr>'
       . hr_sort_th('Employee', 'name', 'employees', $q) . hr_sort_th('Employee ID', 'code', 'employees', $q)
       . hr_sort_th('Department', 'dept', 'employees', $q) . hr_sort_th('Designation', 'desig', 'employees', $q)
       . '<th>Manager</th>' . hr_sort_th('Joined', 'doj', 'employees', $q) . '<th>Type</th>' . hr_sort_th('Status', 'status', 'employees', $q)
       . '<th></th></tr></thead><tbody>';
    foreach ($rows as $e) {
        echo '<tr><td data-l="Employee">' . hr_person_cell($e) . (($e['work_email'] ?? '') !== '' ? '<div class="muted small">' . e($e['work_email']) . '</div>' : '') . '</td>'
           . '<td data-l="Employee ID">' . e($e['emp_code']) . '</td><td data-l="Department">' . e($e['department'] ?? '—') . '</td>'
           . '<td data-l="Designation">' . e($e['designation'] ?? '—') . '</td><td data-l="Manager">' . e($e['manager'] ?? '—') . '</td>'
           . '<td data-l="Joined" class="nowrap">' . e(hr_fmt_date($e['date_of_joining'])) . '</td><td data-l="Type">' . e($e['employment_type'] ?? '—') . '</td>'
           . '<td data-l="Status">' . hr_badge(hr_status_label($e['status'])) . '</td><td class="hr-acts"><a class="btn btn-sm" href="'
           . e(hr_url('employee', ['id' => $e['id']])) . '">View</a>'
           . (hr_emp_allowed((int) $e['id'], 'employees', 'edit') && hr_scope('employees', 'edit') !== 'self'
                ? '<a class="btn btn-sm" href="' . e(hr_url('employee', ['id' => $e['id'], 'edit' => 1])) . '">Edit</a>' : '') . '</td></tr>';
    }
    echo '</tbody></table></div>' . $pager;
}
echo '</div>';

if (hr_can('employees', 'create') && hr_scope('employees', 'create') === 'org') {
    echo '<div class="card"><h2>Import employees from Excel or CSV</h2>'
       . '<p class="mt0">Download the template, fill one row per employee, then upload it. Press <strong>Check file</strong> first — it reports every problem by line and saves nothing. '
       . 'Departments, designations, types and locations must already exist (matched by name). Leave “Employee ID” blank to have one generated. Duplicate IDs and emails are refused.</p>'
       . '<p><a class="btn btn-sm" href="' . e(hr_url('file', ['kind' => 'template', 'what' => 'employees'])) . '">Download template</a></p>'
       . hr_form_open('', 'class="hr-form"', true) . '<input type="hidden" name="action" value="import">'
       . '<div class="formgrid">' . hr_field('File (.xls, .xlsx or .csv)', '<input type="file" name="file" accept=".xls,.xlsx,.csv" required>', true) . '</div>'
       . '<div class="hr-formfoot"><button class="btn" name="commit" value="">Check file</button>'
       . '<button class="btn btn-primary" name="commit" value="1" data-confirm="Import every valid row now? Rows with problems are skipped and listed.">Import</button></div></form></div>';
}

hr_layout_end();

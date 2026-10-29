<?php
/**
 * Organisation Structure — departments, designations and the other lists an
 * employee record chooses from: employment types, work locations,
 * categories. Plus who manages whom (the full tree is on the Org Chart).
 */

require_once __DIR__ . '/parts.php';

$tabs = [
    'department'      => 'Departments',
    'designation'     => 'Designations',
    'managers'        => 'Reporting managers',
    'employment_type' => 'Employment types',
    'location'        => 'Work locations',
    'category'        => 'Employee categories',
];
$tab  = hr_tab($tabs, 'department');
$keep = ['tab' => $tab];
$back = hr_url('masters', $keep);
$flash = $_SERVER['REQUEST_METHOD'] === 'POST' ? hr_parts_post($back) : null;

hr_layout_start('Organisation Structure', 'The lists every employee record chooses from. A list item in use can be deactivated but not deleted.',
                ['HR Setup' => hr_url('setup'), 'Organisation Structure' => ''],
                hr_can('employees', 'view') ? '<a class="btn btn-sm" href="' . e(hr_url('orgchart')) . '">View org chart</a>' : '');
echo hr_flash_html($flash);
hr_tabs('masters', $tabs, $tab);

if ($tab === 'managers') {
    $rows = fabo_all("SELECT m.id, m.full_name, m.emp_code, m.photo_file, d.name AS department, g.name AS designation,
                             COUNT(e.id) AS reports
                        FROM hr_employees m JOIN hr_employees e ON e.manager_id = m.id AND e.status IN ('active','probation','notice')
                        LEFT JOIN hr_departments d ON d.id = m.department_id LEFT JOIN hr_designations g ON g.id = m.designation_id
                       WHERE m.org_id = ? GROUP BY m.id ORDER BY m.full_name", 'i', [hr_org()]);
    echo '<div class="card"><h2>Reporting managers</h2><p class="hr-hint mt0">A manager is anyone another employee reports to. Set it on the employee\'s record. '
       . 'Managers can approve their team\'s leave only once you give them a Manager role under Access Control.</p>';
    if (!$rows) {
        echo hr_empty('No reporting lines yet.', ' Choose a reporting manager when you add or edit an employee.');
    } else {
        echo '<table class="data hr-table stack"><thead><tr><th>Manager</th><th>Department</th><th>Designation</th><th class="num">Direct reports</th><th></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr><td data-l="Manager">' . hr_person_cell($r) . '</td><td data-l="Department">' . e($r['department'] ?? '—') . '</td>'
               . '<td data-l="Designation">' . e($r['designation'] ?? '—') . '</td><td class="num" data-l="Reports">' . (int) $r['reports'] . '</td>'
               . '<td><a class="btn btn-sm" href="' . e(hr_url('employees', ['manager' => $r['id']])) . '">View team</a></td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';
} else {
    hr_part_masters($tab, $back, $keep);
    if ($tab === 'location') {
        echo '<div class="hr-note">Work calendars: a holiday calendar can be made for a location so everyone working there follows it. '
           . '<a href="' . e(hr_url('schedules', ['tab' => 'calendars'])) . '">Manage holiday calendars</a>.</div>';
    }
}
hr_layout_end();

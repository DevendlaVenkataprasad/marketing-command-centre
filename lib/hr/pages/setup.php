<?php
/**
 * HR Setup — every configuration area on one page, in the order it is best
 * done, each with whether it is done yet. The areas themselves stay where
 * they always were (Organisation Structure, Shifts, Policies & Rules, …);
 * this is the map to them, so nobody has to know where a setting lives.
 */

if (!hr_can_setup()) {
    fabo_deny('You do not have access to HR setup.', 403);
}

$org = hr_org();
$has = static fn(string $sql) => (bool) fabo_one($sql, 'i', [$org]);
$cnt = static fn(string $sql) => (int) (fabo_one($sql, 'i', [$org])['n'] ?? 0);

/* [title, what it is for, done?, required?, link, module that must be viewable (null: any setup viewer)] */
$steps = [
    'Foundations' => [
        ['Departments and designations', 'The organisation\'s structure. Every employee record, report and approval chain uses it.',
         $has('SELECT id FROM hr_departments WHERE org_id = ? LIMIT 1') && $has('SELECT id FROM hr_designations WHERE org_id = ? LIMIT 1'), true,
         hr_url('masters'), 'masters', $cnt('SELECT COUNT(*) n FROM hr_departments WHERE org_id = ? AND active = 1') . ' departments'],
        ['Locations, employment types and categories', 'Optional lists for filtering and for location-specific holidays.',
         $has("SELECT id FROM hr_masters WHERE org_id = ? AND kind IN ('location','employment_type') LIMIT 1"), false,
         hr_url('masters', ['tab' => 'location']), 'masters', ''],
        ['Shifts and weekly offs', 'Working hours, grace period, half-day and overtime thresholds. Mark one as the default.',
         $has('SELECT id FROM hr_shifts WHERE org_id = ? AND active = 1 AND is_default = 1 LIMIT 1'), true,
         hr_url('schedules'), 'schedules', $cnt('SELECT COUNT(*) n FROM hr_shifts WHERE org_id = ? AND active = 1') . ' shifts'],
        ['Holiday calendar', 'The holidays each office follows. Leave and payroll skip them automatically.',
         $has('SELECT id FROM hr_holidays WHERE org_id = ? LIMIT 1'), true,
         hr_url('holidays'), 'holidays', $cnt('SELECT COUNT(*) n FROM hr_holidays WHERE org_id = ? AND active = 1') . ' holidays'],
    ],
    'Time off and attendance' => [
        ['Leave types, policies and approval steps', 'Which leave exists, how much each employee gets, how it accrues and who approves it.',
         $has('SELECT id FROM hr_leave_types WHERE org_id = ? LIMIT 1') && $has('SELECT id FROM hr_leave_policies WHERE org_id = ? LIMIT 1'), true,
         hr_url('sheets', ['tab' => 'leave']), 'sheets', $cnt('SELECT COUNT(*) n FROM hr_leave_types WHERE org_id = ? AND active = 1') . ' leave types'],
        ['Attendance rules', 'How unmarked days count, self check-in, corrections, work from home and overtime approval.',
         true, false, hr_url('sheets', ['tab' => 'attendance']), 'sheets', 'Defaults in use'],
    ],
    'Pay' => [
        ['Payroll rules', 'How per-day pay and unpaid days are worked out. Payroll will not run until both are chosen.',
         hr_setting('pay_divisor') && hr_setting('pay_lop_method'), true, hr_url('sheets', ['tab' => 'payroll']), 'sheets', ''],
        ['Salary components', 'Basic, allowances and deductions with their rates. Each employee\'s structure is built from them.',
         $has('SELECT id FROM hr_salary_components WHERE org_id = ? LIMIT 1'), true, hr_url('sheets', ['tab' => 'payroll']) . '#components', 'sheets',
         $cnt('SELECT COUNT(*) n FROM hr_salary_components WHERE org_id = ? AND active = 1') . ' components'],
        ['Expense claim categories and rules', 'What can be claimed, limits per claim, receipts and who approves.',
         $has("SELECT id FROM hr_masters WHERE org_id = ? AND kind = 'claim_category' LIMIT 1"), false, hr_url('claims', ['tab' => 'setup']), 'claims', ''],
    ],
    'People processes' => [
        ['Onboarding and exit checklists', 'The tasks for every joiner and leaver, who does each and by when.',
         $has("SELECT id FROM hr_checklists WHERE org_id = ? AND kind = 'onboarding' AND active = 1 LIMIT 1")
         && $has("SELECT id FROM hr_checklists WHERE org_id = ? AND kind = 'offboarding' AND active = 1 LIMIT 1"), false,
         hr_url('lifecycle', ['tab' => 'setup']), 'lifecycle', ''],
        ['Performance review rules', 'Whether a self-assessment comes first. Review cycles are started on the Performance page.',
         true, false, hr_url('performance', ['tab' => 'cycles']), 'performance', 'Defaults in use'],
        ['Asset categories', 'Laptops, phones, ID cards… to group the asset register.',
         $has("SELECT id FROM hr_masters WHERE org_id = ? AND kind = 'asset_category' LIMIT 1"), false, hr_url('assets', ['tab' => 'setup']), 'assets', ''],
        ['Helpdesk and training categories', 'Topics employees raise queries under, and kinds of training.',
         $has("SELECT id FROM hr_masters WHERE org_id = ? AND kind IN ('ticket_category','training_category') LIMIT 1"), false,
         hr_url('helpdesk', ['tab' => 'setup']), 'helpdesk', ''],
        ['Employee ID format and email notifications', 'How new Employee IDs are numbered, and whether HR notices are emailed too.',
         true, false, hr_url('sheets', ['tab' => 'employees']), 'sheets', 'Defaults in use'],
    ],
    'Access' => [
        ['Who can use HR', 'Roles for HR staff, payroll, managers and employees. Nobody but the administrator has access until given a role.',
         $has('SELECT r.id FROM hr_roles r JOIN hr_role_members m ON m.role_id = r.id WHERE r.org_id = ? LIMIT 1'), false,
         hr_url('settings'), 'access', $cnt('SELECT COUNT(*) n FROM hr_roles WHERE org_id = ? AND active = 1') . ' roles'],
    ],
];

$total = $done = 0;
foreach ($steps as $items) {
    foreach ($items as $s) {
        if ($s[3]) {
            $total++;
            $done += $s[2] ? 1 : 0;
        }
    }
}

hr_layout_start('HR Setup', 'Everything HR needs to know about your organisation, in the order it is best done.', ['HR Setup' => ''],
                (hr_is_admin() || hr_can('sheets', 'view') ? '<a class="btn btn-sm" href="' . e(hr_url('file', ['kind' => 'config'])) . '">Export configuration (Excel)</a>' : '')
              . (hr_can('sheets', 'view') ? '<a class="btn btn-sm" href="' . e(hr_url('sheets', ['tab' => 'history'])) . '">Change history</a>' : ''));

$pct = $total ? (int) round($done * 100 / $total) : 100;
echo '<div class="card hr-progress"><div><strong>' . $done . ' of ' . $total . ' essential steps done</strong>'
   . '<div class="muted small">' . ($done === $total ? 'HR is ready to use. The optional areas can be filled in whenever you need them.'
                                                    : 'Payroll and leave work once the essential steps are done.') . '</div></div>'
   . '<div class="bar" role="progressbar" aria-valuenow="' . $pct . '" aria-valuemin="0" aria-valuemax="100"><i style="width:' . $pct . '%"></i></div></div>';

$n = 0;
foreach ($steps as $group => $items) {
    $cards = '';
    foreach ($items as [$title, $what, $ok, $required, $url, $module, $meta]) {
        $n++;
        if ($module === 'access' ? !hr_is_admin() : (!hr_is_admin() && !hr_can($module, 'view'))) {
            continue;
        }
        $state = $ok ? hr_badge('done', 'Done') : ($required ? hr_badge('pending', 'To do') : hr_badge('draft', 'Optional'));
        $cards .= '<a class="hr-setup-card' . (!$ok && $required ? ' todo' : '') . '" href="' . e($url) . '"><span class="num">' . $n . '</span>'
                . '<span class="body"><span class="t">' . e($title) . '</span><span class="d">' . e($what) . '</span>'
                . ($meta !== '' ? '<span class="m">' . e($meta) . '</span>' : '') . '</span><span class="st">' . $state . '</span></a>';
    }
    if ($cards !== '') {
        echo '<h2 class="hr-group-h">' . e($group) . '</h2><div class="hr-setup">' . $cards . '</div>';
    }
}

hr_layout_end();

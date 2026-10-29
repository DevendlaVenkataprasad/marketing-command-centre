<?php
/**
 * Policies & Rules — the HR rules that are policy rather than structure:
 * leave types, entitlements and approval steps, attendance rules, payroll
 * rules and salary components, Employee ID format and notifications. Each
 * rule lives here and only here; shifts, holidays and the organisation's
 * lists have their own pages, all reached from HR Setup.
 */

require_once __DIR__ . '/parts.php';

// Older links to tabs that moved to their own pages.
$moved = ['holidays' => hr_url('holidays'), 'schedules' => hr_url('schedules')];
if (isset($moved[(string) ($_GET['tab'] ?? '')]) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    hr_redirect($moved[(string) $_GET['tab']]);
}

$tabs = [
    'leave'      => 'Leave',
    'attendance' => 'Attendance',
    'payroll'    => 'Payroll',
    'employees'  => 'Employee IDs & notifications',
    'history'    => 'Change history',
];
$tab  = hr_tab($tabs, 'leave');
$keep = ['tab' => $tab];
$back = hr_url('sheets', $keep + array_filter(['policy' => (int) ($_GET['policy'] ?? 0)]));
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'settings_preview') {
    $flash = hr_parts_post($back);
}

$canConfig = hr_can('sheets', 'configure');
hr_layout_start('Policies & Rules', 'Leave, attendance and payroll rules. Every change is previewed first and recorded with who made it and when it takes effect.',
                ['HR Setup' => hr_url('setup'), 'Policies & Rules' => ''],
                '<a class="btn btn-sm" href="' . e(hr_url('file', ['kind' => 'config'])) . '">Export all sheets (Excel)</a>');
echo hr_flash_html($flash);
hr_tabs('sheets', $tabs, $tab);

if (!$canConfig && !hr_is_admin()) {
    echo '<div class="hr-note">You can view these settings. Changes need the Configure right.</div>';
}

switch ($tab) {
    case 'leave':
        hr_part_approval_chain($back, $keep);
        hr_part_leave_types($back, $keep);
        hr_part_policies($back, $keep);
        hr_part_settings('leave', $back, 'Leave rules', 'How requests are approved and how balances behave across all leave types.');
        break;
    case 'attendance':
        hr_part_settings('attendance', $back, 'Attendance rules',
            'Working hours, grace periods, half-day thresholds, breaks and overtime belong to each shift (see Work schedules). These rules apply to everyone.');
        echo '<div class="card"><h2>Shift timings</h2><p class="mt0">Office hours, grace, half-day threshold, break and overtime rules are set per shift.</p>'
           . '<a class="btn btn-sm" href="' . e(hr_url('schedules')) . '">Open Shifts & Work Calendars</a></div>';
        break;
    case 'payroll':
        hr_part_settings('payroll', $back, 'Payroll rules', 'How payable days, unpaid leave, overtime and rounding are worked out. Required items must be chosen before the first payroll.');
        hr_part_components($back, $keep);
        break;
    case 'employees':
        hr_part_settings('employees', $back, 'Employee IDs');
        hr_part_settings('notifications', $back, 'Notifications');
        echo '<div class="card"><h2>Departments, designations and other lists</h2><p class="mt0">These are kept under Organisation Structure.</p>'
           . '<a class="btn btn-sm" href="' . e(hr_url('masters')) . '">Open Organisation Structure</a></div>';
        break;
    case 'history':
        hr_part_history((string) ($_GET['area'] ?? ''));
        break;
}

hr_layout_end();

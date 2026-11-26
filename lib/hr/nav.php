<?php
/**
 * The HR entries in the portal sidebar. Kept apart from the rest of lib/hr
 * so lib/ui.php can draw the menu on every page without loading the module.
 */

/**
 * Does a signed-in person hold any HR role? Read fresh from the database;
 * a missing table (HR never opened yet) simply means no.
 */
function hr_nav_person_has_access(array $viewer): bool
{
    $c = $viewer['client'] ?? null;
    if (!$c || !empty($viewer['is_admin'])) {
        return false;
    }
    try {
        $org = fabo_module_org_of($viewer);
        // The HR module: a client's own company, or ours for our own staff.
        if (($org > 0 || fabo_is_our_staff($viewer)) && fabo_hr_grant($viewer) !== 'none') {
            return true;
        }
        return (bool) fabo_one('SELECT 1 FROM hr_role_members m JOIN hr_roles r ON r.id = m.role_id AND r.active = 1 AND r.org_id = ?
                                JOIN hr_role_perms p ON p.role_id = r.id WHERE m.person_id = ? LIMIT 1', 'ii', [$org, (int) $c['id']]);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * The sidebar block: "HR Management", and when an HR page is open, the
 * desks this viewer may open, nested beneath it. $current is 'hr-<page>'.
 */
function hr_nav_html(array $viewer, string $current): string
{
    $admin = !empty($viewer['is_admin']);
    if (!$admin && !hr_nav_person_has_access($viewer)) {
        return '';
    }
    $in = str_starts_with($current, 'hr-');
    /* A collapsible group, like Marketing Management: open on an HR page,
       closed elsewhere but holding the same desks. Off an HR page the HR
       context is built just for this list (no counts) and put away again. */
    $borrowed = false;
    if (empty($GLOBALS['hr_ctx'])) {
        try {
            require_once __DIR__ . '/app.php';   // the menu's helpers (hr_can_setup, hr_interviews_mine...) and hr_start()
            hr_start($viewer);
            $borrowed = true;
        } catch (Throwable $e) {
            return '<a href="hr.php">HR Management</a>';
        }
    }
    try {
        $subs = hr_nav_subs($in ? substr($current, 3) : '', $in);
    } catch (Throwable $e) {
        $subs = '';
    }
    if ($borrowed) {
        unset($GLOBALS['hr_ctx'], $GLOBALS['hr_attention']);
    }
    if ($subs === '') {
        $subs = '<a class="hr-sub" href="hr.php">Open HR</a>';
    }
    return fabo_nav_group('HR Management', 'briefcase', $in, $subs);
}

/** The HR desks the viewer may open, grouped; $page lights one, $counts adds the waiting badges. */
function hr_nav_subs(string $page, bool $counts): string
{
    $h = '';
    // Pages reached from another desk light up that desk.
    $page = ['employee' => 'employees', 'sheets' => 'setup', 'masters' => 'setup', 'schedules' => 'setup'][$page] ?? $page;
    /* Grouped the way the work is: who we employ, their time, their pay,
       their growth, the workplace, then reporting and setup. A group with
       nothing the viewer may open is not drawn at all. */
    $groups = [
        ''          => ['me' => [null, 'My HR'], 'dashboard' => ['dashboard', 'Dashboard']],
        'People'    => ['employees' => ['employees', 'Employees'], 'orgchart' => ['employees', 'Org chart'],
                        'recruitment' => ['recruitment', 'Recruitment'], 'assessment' => ['recruitment', 'Interview assessments'], 'lifecycle' => ['lifecycle', 'Onboarding & exits']],
        'Time'      => ['attendance' => ['attendance', 'Attendance'], 'leave' => ['leave', 'Leave'], 'holidays' => ['holidays', 'Holidays']],
        'Pay'       => ['payroll' => ['payroll', 'Payroll'], 'claims' => ['claims', 'Expense claims']],
        'Growth'    => ['performance' => ['performance', 'Performance'], 'training' => ['training', 'Training']],
        'Workplace' => ['assets' => ['assets', 'Assets'], 'helpdesk' => ['helpdesk', 'Helpdesk & notices']],
        'Insights & setup' => ['reports' => ['reports', 'Reports'], 'setup' => [null, 'HR setup'], 'settings' => ['access', 'Access control']],
    ];
    $badges = ['leave' => 'leave', 'attendance' => 'attendance', 'claims' => 'claims', 'lifecycle' => 'lifecycle',
               'performance' => 'performance', 'recruitment' => 'recruitment', 'helpdesk' => 'helpdesk', 'assets' => 'assets'];
    foreach ($groups as $group => $items) {
        $links = '';
        foreach ($items as $p => [$module, $label]) {
            if ($p === 'me' && !hr_self_emp()) {
                continue;
            }
            if ($p === 'setup' && !hr_can_setup()) {
                continue;
            }
            if ($p === 'recruitment' && !hr_can('recruitment', 'view') && !hr_interviews_mine(false)) {
                continue;
            }
            if ($module !== null && $p !== 'recruitment' && !hr_can($module, 'view')) {
                continue;
            }
            $on = $page === $p;
            $n = $counts && isset($badges[$p]) ? hr_attention_count($badges[$p]) : 0;
            $links .= '<a class="hr-sub' . ($on ? ' on' : '') . '"' . ($on ? ' aria-current="page"' : '') . ' href="'
                    . e(hr_url($p)) . '">' . e($label) . ($n ? ' <span class="badge">' . $n . '</span>' : '') . '</a>';
        }
        if ($links !== '') {
            $h .= ($group !== '' ? '<div class="hr-grp">' . e($group) . '</div>' : '') . $links;
        }
    }
    return $h;
}

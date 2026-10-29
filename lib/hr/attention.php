<?php
/**
 * "Needs your attention" — one list, across every HR module, of what is
 * waiting on the person looking. The dashboard, My HR and the sidebar badges
 * all read it, so a number in one place is the same number everywhere.
 */

require_once __DIR__ . '/workplace.php';

/**
 * key => [label, count, url, tone, module]. Only items the viewer can act on,
 * and only those with something waiting.
 */
function hr_attention(): array
{
    // Worked out once per request (hr_start() clears it): the menu badges and the page both ask.
    if (isset($GLOBALS['hr_attention'])) {
        return $GLOBALS['hr_attention'];
    }
    $out = [];
    $add = static function (string $key, string $label, int $n, string $url, string $tone, string $module) use (&$out) {
        if ($n > 0) {
            $out[$key] = [$label, $n, $url, $tone, $module];
        }
    };
    $orgEdit = static fn(string $m) => hr_is_admin() || hr_scope($m, 'edit') === 'org';

    if (hr_can('leave', 'approve')) {
        $add('leave', 'Leave requests to approve', count(hr_leave_awaiting_me()), hr_url('leave', ['tab' => 'approvals']), 'amber', 'leave');
    }
    if (hr_can('attendance', 'approve')) {
        $n = count(array_filter(hr_att_corrections('submitted'), static fn($c) => hr_att_correction_can_decide($c)));
        $add('corrections', 'Attendance corrections to decide', $n, hr_url('attendance', ['tab' => 'corrections']), 'amber', 'attendance');
    }
    if (hr_can('claims', 'approve')) {
        $add('claims', 'Expense claims to approve', count(hr_claims_awaiting_me()), hr_url('claims', ['tab' => 'approvals']), 'amber', 'claims');
    }
    if ($orgEdit('claims') && hr_can('claims', 'view')) {
        $n = (int) (fabo_one("SELECT COUNT(*) n FROM hr_claims WHERE org_id = ? AND status = 'approved'", 'i', [hr_org()])['n'] ?? 0);
        $add('claims_pay', 'Approved claims to pay', $n, hr_url('claims', ['tab' => 'all', 'status' => 'approved']), 'blue', 'claims');
    }
    if (hr_can('lifecycle', 'approve')) {
        $n = count(array_filter(hr_separations_list('submitted'), static fn($s) => hr_separation_can_decide($s)));
        $add('resignations', 'Resignations to review', $n, hr_url('lifecycle', ['tab' => 'exits']), 'red', 'lifecycle');
    }
    if (hr_can('lifecycle', 'view')) {
        $mine = hr_tasks_mine();
        $due = array_filter($mine, static fn($t) => !$t['due_date'] || $t['due_date'] <= date('Y-m-d', strtotime('+7 day')));
        $over = array_filter($mine, static fn($t) => $t['due_date'] && $t['due_date'] < date('Y-m-d'));
        $add('tasks', 'Onboarding / exit tasks due this week' . ($over ? ' (' . count($over) . ' overdue)' : ''), count($due),
             hr_url('lifecycle', ['tab' => 'tasks', 'mine' => 1]), $over ? 'red' : 'amber', 'lifecycle');
    }
    if (hr_can('performance', 'view')) {
        $add('reviews', 'Performance reviews waiting on you', count(hr_reviews_waiting_me()), hr_url('performance', ['tab' => 'mine']), 'blue', 'performance');
    }
    if (hr_self_emp()) {
        $add('interviews', 'Interviews to give feedback on', count(array_filter(hr_interviews_mine(),
             static fn($i) => $i['scheduled_at'] <= date('Y-m-d H:i:s'))), hr_url('recruitment', ['tab' => 'myinterviews']), 'amber', 'recruitment');
    }
    if (hr_is_helpdesk_agent() && hr_can('helpdesk', 'view')) {
        $n = (int) (fabo_one("SELECT COUNT(*) n FROM hr_tickets WHERE org_id = ? AND status IN ('open','in_progress')", 'i', [hr_org()])['n'] ?? 0);
        $add('tickets', 'Open helpdesk queries', $n, hr_url('helpdesk', ['tab' => 'queries']), 'amber', 'helpdesk');
    }
    if ($orgEdit('assets') && hr_can('assets', 'view')) {
        $n = (int) (fabo_one("SELECT COUNT(*) n FROM hr_asset_assignments x JOIN hr_assets a ON a.id = x.asset_id
                              WHERE x.org_id = ? AND x.returned_on IS NULL AND x.due_back IS NOT NULL AND x.due_back < CURDATE()", 'i', [hr_org()])['n'] ?? 0);
        $add('assets', 'Assets overdue for return', $n, hr_url('assets', ['status' => 'assigned']), 'red', 'assets');
    }
    if ($orgEdit('employees') && hr_can('employees', 'view')) {
        $n = (int) (fabo_one("SELECT COUNT(*) n FROM hr_employees WHERE org_id = ? AND status = 'probation' AND probation_end IS NOT NULL
                              AND probation_end <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)", 'i', [hr_org()])['n'] ?? 0);
        $add('probation', 'Probations ending in the next 30 days', $n, hr_url('employees', ['status' => 'probation']), 'blue', 'employees');
    }
    if ($orgEdit('lifecycle') && hr_can('lifecycle', 'view')) {
        $n = (int) (fabo_one("SELECT COUNT(*) n FROM hr_separations WHERE org_id = ? AND status = 'approved' AND approved_lwd < CURDATE()", 'i', [hr_org()])['n'] ?? 0);
        $add('exits_close', 'Exits past the last working day, not yet completed', $n, hr_url('lifecycle', ['tab' => 'exits']), 'red', 'lifecycle');
    }
    return $GLOBALS['hr_attention'] = $out;
}

/** The total waiting for one module, for a sidebar badge. */
function hr_attention_count(string $module): int
{
    $n = 0;
    foreach (hr_attention() as [, $c, , , $m]) {
        if ($m === $module) {
            $n += $c;
        }
    }
    return $n;
}

/** Renders the list as a card. */
function hr_attention_html(string $title = 'Needs your attention'): string
{
    $items = hr_attention();
    $h = '<div class="card hr-attn"><h2>' . e($title) . '</h2>';
    if (!$items) {
        return $h . '<p class="hr-allclear">You are all caught up — nothing is waiting on you.</p></div>';
    }
    $h .= '<ul>';
    foreach ($items as [$label, $n, $url, $tone]) {
        $h .= '<li><a href="' . e($url) . '"><span class="n ' . e($tone) . '">' . (int) $n . '</span><span>' . e($label) . '</span><span class="go" aria-hidden="true">&rsaquo;</span></a></li>';
    }
    return $h . '</ul></div>';
}

/* ------------------------------------------------------------- org chart */

/**
 * The reporting tree. Roots are current employees with no current manager,
 * or the viewer themselves when they see only their team.
 */
function hr_org_tree(int $dept = 0): array
{
    $sc = hr_scope_sql('employees', 'view', 'e');
    $rows = fabo_all("SELECT e.id, e.full_name, e.emp_code, e.photo_file, e.manager_id, e.department_id, e.status, d.name AS department, g.name AS designation
                        FROM hr_employees e LEFT JOIN hr_departments d ON d.id = e.department_id LEFT JOIN hr_designations g ON g.id = e.designation_id
                       WHERE e.org_id = ? AND e.status IN ('active','probation','notice')" . $sc['sql'] . ' ORDER BY e.full_name', 'i', [hr_org()]);
    $by = [];
    foreach ($rows as $r) {
        $by[(int) $r['id']] = $r + ['kids' => []];
    }
    $roots = [];
    foreach ($by as $id => $r) {
        $m = (int) $r['manager_id'];
        if ($m && isset($by[$m]) && $m !== $id) {
            $by[$m]['kids'][] = $id;
        } else {
            $roots[] = $id;
        }
    }
    if ($dept) {
        // Keep only the branches that hold someone from the department, with their managers above them.
        $keep = [];
        foreach ($by as $id => $r) {
            if ((int) $r['department_id'] === $dept) {
                for ($x = $id, $guard = 0; $x && isset($by[$x]) && $guard < 20; $x = (int) $by[$x]['manager_id'], $guard++) {
                    $keep[$x] = true;
                }
            }
        }
        foreach ($by as $id => $r) {
            $by[$id]['kids'] = array_values(array_filter($r['kids'], static fn($k) => isset($keep[$k])));
        }
        $roots = array_values(array_filter($roots, static fn($k) => isset($keep[$k])));
    }
    return [$by, $roots];
}

/** Counts everyone below one person in the tree. */
function hr_org_span(array $by, int $id, int $depth = 0): int
{
    if ($depth > 20) {
        return 0;
    }
    $n = 0;
    foreach ($by[$id]['kids'] as $k) {
        $n += 1 + hr_org_span($by, $k, $depth + 1);
    }
    return $n;
}

<?php
/**
 * Incubation Management entries in the admin sidebar. Kept apart from the rest
 * of lib/inc so lib/ui.php can draw the menu on every page without loading
 * the module.
 */

function inc_url(string $page, array $q = []): string
{
    if (!empty($GLOBALS['inc_embed'])) {
        $q['embed'] = 1;
    }
    return 'incubation.php?' . http_build_query(['p' => $page] + $q);
}

/**
 * Does this person sit on an active department of their organisation's
 * Incubation Management? That alone opens the department desk (?p=desk), even
 * without the module -- and only while their organisation still holds it.
 */
function inc_desk_member(array $viewer): bool
{
    $c = $viewer['client'] ?? null;
    if (!empty($viewer['is_admin']) || !$c || empty($c['id']) || ($viewer['state'] ?? 'ok') !== 'ok') {
        return false;
    }
    require_once __DIR__ . '/org.php';
    $org = inc_org_of($viewer);
    if ($org < 0 || !inc_org_enabled($org)) {
        return false;
    }
    $p = inc_prefix($org);
    try {
        return (bool) fabo_one('SELECT 1 AS x FROM ' . $p . 'team_members m JOIN ' . $p . 'teams t ON t.id = m.team_id AND t.active = 1
                                 WHERE m.person_id = ? LIMIT 1', 'i', [(int) $c['id']]);
    } catch (Throwable $e) {
        return false;                       // that organisation has not opened its Incubation Management yet
    }
}

/**
 * The person's departments with how many applications each has open, for the
 * card on their My Work dashboard. name => count; empty when they sit on none.
 */
function inc_desk_open_counts(array $viewer): array
{
    if (!inc_desk_member($viewer)) {
        return [];
    }
    $p = inc_prefix(inc_org_of($viewer));
    $out = [];
    // Applications given the department under "Assign for incubation", or on one of its
    // forms, count before the desk first syncs them (inc_dept_sync).
    $routed = ' + (SELECT COUNT(*) FROM ' . $p . 'applications a JOIN ' . $p . 'forms f ON f.id = a.form_id WHERE f.dept_team_id = t.id AND COALESCE(a.dup_status, "") <> "confirmed"
                      AND NOT EXISTS (SELECT 1 FROM ' . $p . 'dept_assignments d3 WHERE d3.application_id = a.id AND d3.team_id = t.id))';
    $sql = static fn(string $extra) => 'SELECT t.id, t.name, (SELECT COUNT(*) FROM ' . $p . 'dept_assignments d WHERE d.team_id = t.id AND d.state = "open")
                             + (SELECT COUNT(*) FROM ' . $p . 'applications a WHERE a.team_id = t.id AND COALESCE(a.dup_status, "") <> "confirmed"
                                   AND NOT EXISTS (SELECT 1 FROM ' . $p . 'dept_assignments d2 WHERE d2.application_id = a.id AND d2.team_id = t.id))' . $extra . ' AS n
                         FROM ' . $p . 'teams t JOIN ' . $p . 'team_members m ON m.team_id = t.id AND m.person_id = ?
                        WHERE t.active = 1 GROUP BY t.id, t.name ORDER BY t.name';
    try {
        try {
            $rows = fabo_all($sql($routed), 'i', [(int) $viewer['client']['id']]);
        } catch (Throwable $e) {
            $rows = fabo_all($sql(''), 'i', [(int) $viewer['client']['id']]);   // before forms have dept_team_id
        }
        foreach ($rows as $r) {
            $out[(int) $r['id']] = ['name' => (string) $r['name'], 'open' => (int) $r['n']];
        }
    } catch (Throwable $e) {
        return [];                          // before the department tables exist
    }
    return $out;
}

/**
 * What this viewer may do in their organisation's Incubation Management:
 * 'none' | 'view' | 'edit' (Owner).
 *
 *   administrator                  -> edit, in ours
 *   our own staff                  -> granted on Team Management, in ours
 *   a client head                  -> granted on Clients, in their own
 *   a client head's team member    -> granted by the head on My Team, in the
 *                                     head's, never more than the head holds now
 *
 * Which organisation's data that is: inc_org_of() in lib/inc/org.php.
 */
function inc_grant(array $viewer): string
{
    if (!empty($viewer['is_admin'])) {
        return 'edit';
    }
    $c = $viewer['client'] ?? null;
    if (!$c || ($viewer['state'] ?? 'ok') !== 'ok' || !empty($c['is_committee'])) {
        return 'none';
    }
    require_once __DIR__ . '/org.php';
    $org = inc_org_of($viewer);
    if ($org < 0) {
        return 'none';
    }
    $rank = ['none' => 0, 'view' => 1, 'edit' => 2];
    $p = $viewer['access']['incubation'] ?? 'none';
    $p = isset($rank[$p]) ? $p : 'none';
    // A client's team member: capped by what their head holds now.
    if ($org > 0 && (int) $c['id'] !== $org) {
        $cap = fabo_client_access($org)['incubation'] ?? 'none';
        $cap = isset($rank[$cap]) ? $cap : 'none';
        if ($rank[$cap] < $rank[$p]) {
            $p = $cap;
        }
    }
    return $p;
}

/** The desks listed under "Incubation Management" while the module is open. */
function inc_nav_items(): array
{
    return [
        'dashboard'    => 'Dashboard',
        'applications' => 'Applications',
        'gems'         => 'Gems',
        'desk'         => 'Department desk',
        'team'         => 'Team workload',
        'reports'      => 'Reports',
        'settings'     => 'Settings',
    ];
}

/**
 * One link, with the desks nested under it while an incubation page is open. $current is 'inc-<page>'.
 * $owner false hides Settings, which a View only member cannot open.
 */
function inc_nav_html(string $current, bool $owner = true, bool $deskOnly = false): string
{
    $in = str_starts_with($current, 'inc-');
    // A department member without the CRM itself: one link, to their desk.
    if ($deskOnly) {
        return '<a' . ($in ? ' class="on"' : '') . ' href="' . e(inc_url('desk')) . '">Incubation — my department</a>';
    }
    // A collapsible group, like Marketing Management: open on an incubation page.
    $page = $in ? substr($current, 4) : '';
    $page = ['application' => 'applications', 'new' => 'applications', 'evaluate' => 'applications', 'evaluation' => 'applications',
             'screening' => 'applications', 'workspaces' => 'applications', 'workspace' => 'applications',
             'form' => 'settings', 'template' => 'settings'][$page] ?? $page;
    $h = '';
    foreach (inc_nav_items() as $p => $label) {
        if (!$owner && $p === 'settings') {
            continue;
        }
        $on = $page === $p;
        $h .= '<a class="inc-sub' . ($on ? ' on' : '') . '"' . ($on ? ' aria-current="page"' : '') . ' href="' . e(inc_url($p)) . '">' . e($label) . '</a>';
    }
    return fabo_nav_group('Incubation Management', 'rocket', $in, $h);
}

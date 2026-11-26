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
    return (inc_kind_now() === 'eo' ? 'organizers.php?' : 'incubation.php?') . http_build_query(['p' => $page] + $q);
}

/** inc_kind() for code that runs before lib/inc/org.php is loaded (the menu). */
function inc_kind_now(): string
{
    return ($GLOBALS['inc_kind'] ?? 'inc') === 'eo' ? 'eo' : 'inc';
}

/**
 * Does this person sit on an active department of their organisation's
 * Incubation Management? That alone opens the department desk (?p=desk), even
 * without the module -- and only while their organisation still holds it.
 */
function inc_desk_member(array $viewer, string $kind = 'inc'): bool
{
    $c = $viewer['client'] ?? null;
    if (!empty($viewer['is_admin']) || !$c || empty($c['id']) || ($viewer['state'] ?? 'ok') !== 'ok') {
        return false;
    }
    require_once __DIR__ . '/org.php';
    $org = inc_org_of($viewer);
    if ($org < 0 || !inc_org_enabled($org, $kind)) {
        return false;
    }
    $p = inc_prefix($org);
    try {
        // Event Organizers has one team of its own; the other departments are Incubation's.
        if ($kind === 'eo') {
            return (bool) fabo_one('SELECT 1 AS x FROM ' . $p . 'team_members m JOIN ' . $p . 'teams t ON t.id = m.team_id AND t.active = 1
                                     JOIN ' . $p . 'settings s ON s.k = "_eo_team_id" AND s.v = m.team_id
                                     WHERE m.person_id = ? LIMIT 1', 'i', [(int) $c['id']]);
        }
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
        $kinds = [];
        try {
            foreach (fabo_all('SELECT id, kind FROM ' . $p . 'teams') as $k) {
                $kinds[(int) $k['id']] = (string) $k['kind'];
            }
        } catch (Throwable $e) {
            // before mentors and jury (inc_teams.kind): every one is a department
        }
        foreach ($rows as $r) {
            $out[(int) $r['id']] = ['name' => (string) $r['name'], 'open' => (int) $r['n'], 'kind' => $kinds[(int) $r['id']] ?? 'department'];
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
function inc_grant(array $viewer, string $key = 'incubation'): string
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
    // Capped by what the client above holds now: a team member's head, or the client who made a client.
    return fabo_module_permission($viewer, $key);
}

/** The desks listed under "Incubation Management" while the module is open. */
function inc_nav_items(array $deskKinds = []): array
{
    $items = [
        'dashboard'    => 'Dashboard',
        'applications' => 'Applications',
        'gems'         => 'Gems',
        'desk'         => 'Department desk',
    ];
    // Mentors and a jury have desks of their own, listed once the owner has made one.
    foreach (['mentor' => 'Mentor desk', 'jury' => 'Jury desk'] as $k => $label) {
        if (in_array($k, $deskKinds, true)) {
            $items['desk:' . $k] = $label;
        }
    }
    return $items + [
        'team'         => 'Team workload',
        'reports'      => 'Reports',
        'settings'     => 'Settings',
    ];
}

/**
 * Which kinds of desk (department, mentor, jury) to list: every kind the
 * organisation has, for whoever runs the module; the kinds the person sits
 * on, for a member. Before the kind column exists, departments only.
 */
function inc_nav_desk_kinds(?array $viewer, bool $mine): array
{
    if (!$viewer) {
        return ['department'];
    }
    require_once __DIR__ . '/org.php';
    $org = inc_org_of($viewer);
    if ($org < 0) {
        return ['department'];
    }
    $p = inc_prefix($org);
    try {
        $rows = $mine
            ? fabo_all('SELECT DISTINCT t.kind FROM ' . $p . 'teams t JOIN ' . $p . 'team_members m ON m.team_id = t.id WHERE t.active = 1 AND m.person_id = ?',
                       'i', [(int) ($viewer['client']['id'] ?? 0)])
            : fabo_all('SELECT DISTINCT kind FROM ' . $p . 'teams WHERE active = 1');
        return array_values(array_unique(array_map(static fn($r) => (string) $r['kind'], $rows)));
    } catch (Throwable $e) {
        return ['department'];
    }
}

/** Which desk the page being drawn is (desk.php sets it), for the menu. */
function inc_nav_desk_now(): string
{
    return (string) ($GLOBALS['inc_desk_kind'] ?? 'department');
}

/**
 * One link, with the desks nested under it while an incubation page is open. $current is 'inc-<page>'.
 * $owner false hides Settings, which a View only member cannot open.
 */
function inc_nav_html(string $current, bool $owner = true, bool $deskOnly = false, ?array $viewer = null): string
{
    // Drawn on Event Organizers' pages too: these links are Incubation's whichever is open.
    $was = $GLOBALS['inc_kind'] ?? 'inc';
    $GLOBALS['inc_kind'] = 'inc';
    try {
        return inc_nav_html_links($current, $owner, $deskOnly, $viewer);
    } finally {
        $GLOBALS['inc_kind'] = $was;
    }
}

function inc_nav_html_links(string $current, bool $owner, bool $deskOnly, ?array $viewer = null): string
{
    $in = str_starts_with($current, 'inc-');
    // A department member, mentor or juror without the CRM itself: a link to each desk they sit on.
    if ($deskOnly) {
        $kinds = inc_nav_desk_kinds($viewer, true) ?: ['department'];
        $labels = ['department' => 'Incubation — my department', 'mentor' => 'Incubation — mentor desk', 'jury' => 'Incubation — jury desk'];
        $h = '';
        foreach ($labels as $k => $label) {
            if (in_array($k, $kinds, true)) {
                $on = $in && inc_nav_desk_now() === $k;
                $h .= '<a' . ($on ? ' class="on"' : '') . ' href="' . e(inc_url('desk', ['kind' => $k])) . '">' . e($label) . '</a>';
            }
        }
        return $h;
    }
    // A collapsible group, like Marketing Management: open on an incubation page.
    $page = $in ? substr($current, 4) : '';
    $page = ['application' => 'applications', 'new' => 'applications', 'evaluate' => 'applications', 'evaluation' => 'applications',
             'screening' => 'applications', 'workspaces' => 'applications', 'workspace' => 'applications',
             'form' => 'settings', 'template' => 'settings'][$page] ?? $page;
    if ($page === 'desk' && inc_nav_desk_now() !== 'department') {
        $page = 'desk:' . inc_nav_desk_now();
    }
    $h = '';
    foreach (inc_nav_items(inc_nav_desk_kinds($viewer ?? ($GLOBALS['inc_ctx']['viewer'] ?? null), false)) as $p => $label) {
        if (!$owner && $p === 'settings') {
            continue;
        }
        $on = $page === $p;
        [$pg, $dk] = array_pad(explode(':', $p, 2), 2, '');
        $href = inc_url($pg, $dk !== '' ? ['kind' => $dk] : ($pg === 'desk' ? ['kind' => 'department'] : []));
        $h .= '<a class="inc-sub' . ($on ? ' on' : '') . '"' . ($on ? ' aria-current="page"' : '') . ' href="' . e($href) . '">' . e($label) . '</a>';
    }
    return fabo_nav_group('Incubation Management', 'rocket', $in, $h);
}

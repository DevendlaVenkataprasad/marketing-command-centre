<?php
/**
 * Co-working Space in the sidebar: one group, its pages nested under it
 * while one of them is open. Kept apart from lib/cw/app.php so lib/ui.php
 * can draw the menu on every page without loading the module. The centre
 * shown is the one last opened (remembered by cw_main()).
 *
 * cw_assigned() lives here for the same reason: the sidebar has to know
 * whether somebody runs a centre before the module is loaded.
 */

/**
 * The centres handed to one person on Centre teams: centre id => 'edit' | 'view'.
 *
 * Only a team member of the organisation that owns the centre, while that
 * organisation's head still holds the module -- and never more than the head
 * holds now, read fresh, so taking the module from the head takes every
 * centre from their people at once.
 */
function cw_assigned(?array $viewer): array
{
    $c = $viewer['client'] ?? null;
    if (!$c || !empty($viewer['is_admin']) || ($viewer['state'] ?? 'ok') !== 'ok' || !empty($c['is_committee'])
        || ($c['kind'] ?? '') !== 'team' || (int) ($c['parent_id'] ?? 0) <= 0) {
        return [];
    }
    static $memo = [];
    $id = (int) $c['id'];
    if (isset($memo[$id])) {
        return $memo[$id];
    }
    $org = (int) $c['parent_id'];
    $cap = (string) (fabo_client_access($org)['coworking'] ?? 'none');
    $out = [];
    if ($cap === 'view' || $cap === 'edit') {
        try {
            foreach (fabo_all('SELECT p.centre_id, p.role FROM cw_centre_people p JOIN cw_centres c ON c.id = p.centre_id
                                WHERE p.person_id = ? AND c.org_id = ? ORDER BY c.sort, c.id', 'ii', [$id, $org]) as $r) {
                $out[(int) $r['centre_id']] = $cap === 'view' ? 'view' : (string) $r['role'];
            }
        } catch (Throwable $e) {
            // cw_centre_people not there yet: nobody runs a centre
        }
    }
    return $memo[$id] = $out;
}

/** $owner: runs the whole module (Centre teams, Form & emails). $all: sees every centre, not only their own. */
function cw_nav_html(string $current, bool $owner = true, bool $all = true): string
{
    $pages = ['overview' => $all ? 'All centres' : 'My centres', 'dashboard' => 'Dashboard', 'seats' => 'Seat map', 'spaces' => 'Space register',
              'startups' => 'Startups', 'waiting' => 'Waiting list', 'renewals' => 'Renewals', 'actions' => 'Action items',
              'log' => 'Monthly log', 'forms' => 'Form & emails', 'team' => 'Centre teams', 'settings' => 'Settings'];
    if (!$owner) {
        unset($pages['forms'], $pages['team']);
    }
    $page = str_starts_with($current, 'cw-') ? substr($current, 3) : '';
    $org = isset($_GET['org']) ? '&org=' . (int) $_GET['org'] : '';
    $h = '';
    foreach ($pages as $p => $label) {
        $on = $page === $p;
        $h .= '<a class="it-sub' . ($on ? ' on' : '') . '"' . ($on ? ' aria-current="page"' : '') . ' href="coworking.php?p=' . $p . $org . '">' . e($label) . '</a>';
    }
    return fabo_nav_group('Co-working Space', 'cowork', $page !== '', $h);
}

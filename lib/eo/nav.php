<?php
/**
 * Event Organizers entries in the sidebar. Kept apart from the rest of lib/eo
 * so lib/ui.php can draw the menu on every page without loading the module.
 */

require_once __DIR__ . '/../inc/nav.php';

/** A link inside Event Organizers, whichever product's page is drawing it. */
function eo_url(string $page, array $q = []): string
{
    return 'organizers.php?' . http_build_query(['p' => $page] + $q);
}

/** 'none' | 'view' | 'edit': granted like Incubation Management, on its own key. */
function eo_grant(array $viewer): string
{
    return inc_grant($viewer, 'organizers');
}

/** Does this person sit on a department of their organisation's Event Organizers? */
function eo_desk_member(array $viewer): bool
{
    return inc_desk_member($viewer, 'eo');
}

/** One collapsible group; a department member without the grant gets their pages only. */
function eo_nav_html(string $current, bool $owner = true, bool $granted = true): string
{
    $in = str_starts_with($current, 'eo-');
    $page = $in ? substr($current, 3) : '';
    $page = ['event' => 'events', 'checkin' => 'events', 'kiosk' => 'events', 'badges' => 'events', 'application' => 'applications', 'new' => 'applications', 'quick' => 'applications',
             'form' => 'events'][$page] ?? $page;
    $items = ['dashboard' => 'Dashboard', 'events' => 'Approved events', 'applications' => 'Applications', 'desk' => 'Organizers desk', 'orders' => 'Orders & revenue',
              'analytics' => 'Analytics', 'mentors' => 'Mentors', 'sponsors' => 'Sponsors', 'team' => 'Organizers team', 'inbox' => 'Messages & feedback',
              'settings' => 'Settings', 'developer' => 'Developer', 'plans' => 'Plans'];
    if (!$granted) {
        $items = array_diff_key($items, array_flip(['applications', 'settings', 'developer', 'plans']));
    } elseif (!$owner) {
        $items = array_diff_key($items, array_flip(['settings', 'developer', 'plans']));
    }
    $h = '';
    foreach ($items as $p => $label) {
        $on = $page === $p;
        $h .= '<a class="inc-sub' . ($on ? ' on' : '') . '"' . ($on ? ' aria-current="page"' : '') . ' href="' . e(eo_url($p)) . '">' . e($label) . '</a>';
    }
    return fabo_nav_group('Event Organizers', 'calendar', $in, $h);
}

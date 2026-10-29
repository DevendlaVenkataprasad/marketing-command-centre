<?php
/**
 * IT Management and Admin & Facilities in the sidebar: one group each, the
 * desk's pages nested under it while one of them is open. Kept apart from
 * lib/it/app.php so lib/ui.php can draw the menu on every page without
 * loading the module.
 *
 * $help is it_helpdesk_info() for someone without the desk: they get the
 * helpdesk group (their requests, and the queue when they work requests).
 * $mine adds "My requests" to the full desk, for a person (not the admin).
 */
function it_nav_html(string $current, string $desk = 'it', ?array $help = null, bool $mine = false): string
{
    $script = $desk === 'fa' ? 'facilities.php' : 'it.php';
    if ($help !== null) {
        $pages = ['my' => 'My requests'] + (!empty($help[1]) ? ['queue' => 'My queue'] : []);
    } else {
        $pages = $desk === 'fa'
            ? ['dashboard' => 'Dashboard', 'tickets' => 'Requests', 'staff' => 'Support staff', 'duty' => 'Duty & attendance', 'checklists' => 'Housekeeping',
               'stock' => 'Supplies', 'assets' => 'Facility assets', 'renewals' => 'Contracts & compliance',
               'vendors' => 'Vendors & agencies', 'settings' => 'Settings']
            : ['dashboard' => 'Dashboard', 'tickets' => 'Tickets', 'assets' => 'Assets', 'renewals' => 'Renewals', 'vendors' => 'Vendors', 'settings' => 'Settings'];
        if ($mine) {
            $pages['my'] = 'My requests';
        }
    }
    $page = str_starts_with($current, $desk . '-') ? substr($current, strlen($desk) + 1) : '';
    $page = ['ticket' => $help !== null ? 'queue' : 'tickets', 'asset' => 'assets', 'myticket' => 'my', 'work' => $help !== null ? 'queue' : 'tickets'][$page] ?? $page;
    $h = '';
    foreach ($pages as $p => $label) {
        $on = $page === $p;
        $h .= '<a class="it-sub' . ($on ? ' on' : '') . '"' . ($on ? ' aria-current="page"' : '') . ' href="' . $script . '?p=' . $p . '">' . e($label) . '</a>';
    }
    $name = $help !== null ? ($desk === 'fa' ? 'Facilities Helpdesk' : 'IT Helpdesk') : ($desk === 'fa' ? 'Admin & Facilities' : 'IT Management');
    return fabo_nav_group($name, $desk === 'fa' ? 'building' : 'monitor', $page !== '', $h);
}

<?php
/**
 * Dashboard Charts, for a client running their own organisation.
 *
 * The chart builder from Sheet Management -> Dashboard, on its own. Any head
 * of an organisation has it -- choosing what their dashboards draw changes
 * no data and no sheet, so it does not wait for the "Manage their own
 * sheets" right the way renaming columns does. It is the same page as the
 * admin's (admin/sheets.php), held to the Dashboard tab and to this
 * organisation's own charts: see fabo_may_chart() in lib/manage.php.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/manage.php';
fabo_boot();

header('X-Robots-Tag: noindex, nofollow');

$viewer = fabo_portal_viewer((string) ($_GET['k'] ?? ''));
if (!$viewer) {
    header('Location: login.php?next=' . rawurlencode('charts.php'));
    exit;
}
if (!fabo_may_chart($viewer)) {
    fabo_deny('Your account cannot change dashboard charts.', 403);
}

$GLOBALS['fabo_manage_viewer'] = $viewer;
$GLOBALS['fabo_charts_only']   = true;
require __DIR__ . '/admin/sheets.php';

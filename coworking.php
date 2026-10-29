<?php
/**
 * Co-working Space for a client granted it on Clients (or a member of their
 * team, given it by the head): their organisation's centres — the hub and
 * its spokes — and nobody else's. Signed-in sessions only.
 * The administrator's desk is admin/coworking.php. See lib/cw/.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/cw/app.php';
fabo_boot();

$viewer = fabo_portal_viewer();
if (!$viewer) {
    header('Location: login.php?next=' . rawurlencode('coworking.php'));
    exit;
}
cw_main($viewer);

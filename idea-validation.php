<?php
/**
 * Startup Discovery for a client granted it on Clients (or a member of their
 * team, given it by the head): HoD dashboards, students, rounds, the pipeline
 * and teams. Signed-in sessions only. The administrator's desk is
 * admin/idea-validation.php. See lib/iv/.
 * Heads of Department sign in to hod.php; students use idea-form.php.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/iv/app.php';
fabo_boot();

$viewer = fabo_portal_viewer();
if (!$viewer || !fabo_signed_in()) {
    header('Location: login.php?next=' . rawurlencode('idea-validation.php'));
    exit;
}
iv_main($viewer);

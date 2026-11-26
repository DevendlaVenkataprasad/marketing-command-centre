<?php
/**
 * Academic for a client granted it on Clients (or a member of their team,
 * given it by the head): their institutions, departments, students, faculty,
 * mentors and institution admins. Signed-in sessions only.
 * The administrator's desk is admin/academic.php. See lib/edu/.
 * Students and the rest sign in to campus.php, not here.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/edu/app.php';
fabo_boot();

$viewer = fabo_portal_viewer();
if (!$viewer || !fabo_signed_in()) {
    header('Location: login.php?next=' . rawurlencode('academic.php'));
    exit;
}
edu_main($viewer);

<?php
/** A client's or team member's own profile. */

require_once __DIR__ . '/lib/profile.php';
require_once __DIR__ . '/lib/uploads.php';
fabo_boot();

header('X-Robots-Tag: noindex, nofollow');

$viewer = fabo_portal_viewer((string) ($_GET['k'] ?? ''));
if (!$viewer) {
    header('Location: login.php?next=' . rawurlencode('profile.php'));
    exit;
}

$flash = fabo_profile_action($viewer);
// The password may have just changed, so read the record back before drawing.
if ($flash[0] === 'ok') {
    $fresh = fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [(int) $viewer['client']['id']]);
    if ($fresh) {
        $viewer['client'] = $fresh;
    }
}
fabo_profile_page($viewer, $flash);

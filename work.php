<?php
/**
 * Work Management for team members and clients the administrator has given
 * access (Admin → Work Management → Access). Signed-in sessions only, through
 * the portal's own login; never a ?k= link. The administrator's way in is
 * admin/work.php.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/wm/app.php';
fabo_boot();

$self = 'work.php' . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
$person = fabo_signed_in();
if (!$person) {
    if (fabo_admin_signed_in()) {
        header('Location: admin/' . $self);
        exit;
    }
    header('Location: login.php?next=' . rawurlencode($self));
    exit;
}
if ((int) ($person['must_change'] ?? 0) === 1) {
    header('Location: login.php?change=1&next=' . rawurlencode($self));
    exit;
}
$viewer = fabo_person_viewer($person);
if (($viewer['state'] ?? 'ok') !== 'ok') {
    fabo_deny('Your account cannot open Work Management right now.', 403);
}
wm_main($viewer);

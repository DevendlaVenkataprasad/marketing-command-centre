<?php
/**
 * HR for signed-in people: self-service, and whatever HR desks the
 * administrator has granted them through an HR role.
 *
 * Only a signed-in session opens this page — never a ?k= link — because it
 * shows personal and salary data. With no HR role the answer is a 403, both
 * here and in every action the pages take; hiding the menu entry is not the
 * control, this is.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/hr/app.php';
fabo_boot();

$person = fabo_signed_in();
if (!$person) {
    // The administrator's own HR desk lives under admin/.
    if (fabo_admin_signed_in()) {
        header('Location: admin/hr.php' . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''));
        exit;
    }
    $here = 'hr.php' . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
    header('Location: login.php?next=' . rawurlencode($here));
    exit;
}
$viewer = fabo_person_viewer($person);
if (($viewer['state'] ?? 'ok') !== 'ok') {
    fabo_deny('Your account cannot open HR right now.', 403);
}
// A one-off password is changed before personal and salary data is shown.
if ((int) ($person['must_change'] ?? 0) === 1) {
    header('Location: login.php?change=1&next=' . rawurlencode('hr.php'));
    exit;
}

hr_app($viewer);

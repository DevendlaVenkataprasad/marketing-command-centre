<?php
/**
 * CEO Dashboard for people given access by their organisation's owner.
 * Signed-in sessions only, never a ?k= link: it reads across every module.
 * What each person may see is decided in lib/ceo on every call. The
 * administrator's way in is admin/ceo.php.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/ceo/app.php';
fabo_boot();

$self = 'ceo.php' . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
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
    fabo_deny('Your account cannot open the CEO Dashboard right now.', 403);
}
ceo_main($viewer);

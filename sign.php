<?php
/**
 * Digital Signatures for anyone signed in to an organisation that holds it
 * (granted on Clients; ours always). Signed-in sessions only — never a ?k=
 * link — because it signs documents in the person's name. What each person
 * may open and do is decided in lib/ds on every page and every action. The
 * administrator's way in is admin/sign.php.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/ds/app.php';
fabo_boot();

$self = 'sign.php' . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
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
    fabo_deny('Your account cannot open Digital Signatures right now.', 403);
}
ds_main($viewer);

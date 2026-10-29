<?php
/**
 * The Finance Portal for anyone signed in to an organisation that holds
 * Finance (granted on Clients; ours always). Signed-in sessions only — never
 * a ?k= link — because it shows the organisation's books. What each person
 * may open and do is their finance role (lib/fin/core.php), checked on every
 * page and every action. The administrator's way in is admin/fin.php.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/fin/app.php';
fabo_boot();

$self = 'fin.php' . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
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
    fabo_deny('Your account cannot open Finance right now.', 403);
}
fp_main($viewer);

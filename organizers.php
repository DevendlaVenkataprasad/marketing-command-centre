<?php
/**
 * Event Organizers for everyone but the administrator: our own staff granted it
 * on Team Management, a client head granted it on Clients, their team members
 * granted it by the head, and members of its departments. The administrator's
 * desk is admin/organizers.php; both serve the same pages (lib/eo, lib/inc).
 *
 * Only a signed-in session opens it, never a ?k= link: applications carry
 * applicants' personal details.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/inc/app.php';
require_once __DIR__ . '/lib/eo/nav.php';
fabo_boot();

$person = fabo_signed_in();
if (!$person) {
    if (fabo_admin_signed_in()) {
        header('Location: admin/organizers.php' . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''));
        exit;
    }
    $here = 'organizers.php' . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
    header('Location: login.php?next=' . rawurlencode($here));
    exit;
}
$viewer = fabo_person_viewer($person);
if (eo_grant($viewer) === 'none' && !eo_desk_member($viewer)) {
    fabo_deny('You do not have access to Event Organizers. Ask whoever manages your account to grant it.', 403);
}
// A one-off password is changed before applicants' details are shown.
if ((int) ($person['must_change'] ?? 0) === 1) {
    header('Location: login.php?change=1&next=' . rawurlencode('organizers.php'));
    exit;
}

inc_use_kind('eo');
inc_app_main($viewer);

<?php
/**
 * Incubation Management for everyone but the administrator: our own staff granted
 * it on Team Management (our Incubation Management), and a client head granted it on
 * Clients -- or their team member granted it by the head -- who each work in
 * their organisation's own, separate one (lib/inc/org.php).
 *
 * The administrator's desk is admin/incubation.php; this serves the same
 * pages (lib/inc/pages/) as View only (read and export) or Owner
 * (everything). Only a signed-in session opens it
 * — never a ?k= link — because applications carry applicants' personal
 * details. Without the grant the answer is a 403, here and in every action
 * the pages take; hiding the menu entry is not the control, this is.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/inc/app.php';
fabo_boot();

$person = fabo_signed_in();
if (!$person) {
    // The administrator's own desk lives under admin/.
    if (fabo_admin_signed_in()) {
        header('Location: admin/incubation.php' . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''));
        exit;
    }
    $here = 'incubation.php' . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
    header('Location: login.php?next=' . rawurlencode($here));
    exit;
}
$viewer = fabo_person_viewer($person);
// The CRM itself, or -- for a department member -- their department desk alone.
if (inc_grant($viewer) === 'none' && !inc_desk_member($viewer)) {
    fabo_deny('You do not have access to Incubation Management. Ask whoever manages your account to grant it.', 403);
}
// A one-off password is changed before applicants' details are shown.
if ((int) ($person['must_change'] ?? 0) === 1) {
    header('Location: login.php?change=1&next=' . rawurlencode('incubation.php'));
    exit;
}

inc_app_main($viewer);

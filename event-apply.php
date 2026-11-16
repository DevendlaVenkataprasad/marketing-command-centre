<?php
/**
 * Event Organizers — the public application page: the main pipeline's own page,
 * after asking the event whether it is still taking applications (deadline,
 * seats, approval), so a form link that was shared widely closes with the event.
 */

require_once __DIR__ . '/lib/eo/public.php';
$_GET['m'] = 'eo';
$org = eo_public_start('applicant');
if ($org >= 0) {
    $ev = eo_event_of_form_token((string) ($_GET['f'] ?? ''));
    $why = $ev ? eo_closed_reason($ev) : '';
    if ($why !== '') {
        // A full event with a waiting list sends people to join it.
        if ($why === 'This event is full.' && (int) $ev['waitlist_enabled'] === 1 && (int) $ev['published'] === 1) {
            header('Location: ' . eo_here($org, 'event.php?e=' . $ev['token']));
            exit;
        }
        header('X-Robots-Tag: noindex, nofollow');
        eo_public_stop($org, 200, (string) $ev['name'], 'Applications are closed', $why);
    }
}

require __DIR__ . '/incubation-apply.php';

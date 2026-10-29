<?php
/**
 * An event's committee: where it stands, and a member's vote.
 *
 *   GET  ?sheet=events&id=N            the committee, every member's decision,
 *                                      their justifications, and whether the
 *                                      viewer may vote.
 *   POST action=vote, id, decision     approve or reject; a rejection carries
 *        (approved|rejected),          its justification, which is required.
 *        justification
 *
 * The event is read through fabo_load_row(), so the viewer's scope applies:
 * nobody can see or vote on an event they could not open. Whether they may
 * vote at all is decided in fabo_cm_vote(): members of that committee only,
 * and only until it has decided. See lib/committees.php.
 */

require_once __DIR__ . '/../lib/endpoint.php';
require_once __DIR__ . '/../lib/committees.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    fabo_csrf_check();
}
[$viewer, $key, $sheet, $perm] = fabo_endpoint('view');
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
if ($key !== 'events') {
    fabo_json_fail('Committees decide on events only.', 400);
}
$id  = (int) ($_REQUEST['id'] ?? 0);
$row = fabo_load_row($viewer, $key, $id);
if (!$row) {
    /* A member may sit on a committee for an event outside the rows they
       can otherwise see -- the committee is their reason to see it. */
    $person = fabo_cm_person($viewer);
    $row = $person > 0
        ? fabo_one('SELECT e.* FROM `events` e JOIN committee_members m
                     ON m.committee_id = e.committee_id AND m.person_id = ? WHERE e.id = ?',
                   'ii', [$person, $id])
        : null;
}
if (!$row) {
    fabo_json_fail('That event was not found.', 404);
}

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ((string) ($_POST['action'] ?? '') !== 'vote') {
        fabo_json_fail('Nothing to do.', 400);
    }
    try {
        $s = fabo_cm_vote($viewer, $id, (string) ($_POST['decision'] ?? ''),
                          (string) ($_POST['justification'] ?? ''));
    } catch (InvalidArgumentException $e) {
        fabo_json_fail($e->getMessage(), 400);
    }
    $message = $s['state'] === 'pending'
        ? 'Your decision is recorded. Waiting on ' . $s['pending'] . ' more.'
        : 'Your decision is recorded — the committee has ' . $s['state'] . ' this event.';
}

$s = fabo_cm_status($id);
$person = fabo_cm_person($viewer);
$mine   = null;
foreach ($s['members'] ?? [] as $m) {
    if ($m['id'] === $person) {
        $mine = $m;
    }
}
fabo_json([
    'ok'       => true,
    'message'  => $message,
    'event'    => ['id' => $id, 'ref' => (string) ($row['event_id'] ?? ''),
                   'name' => (string) ($row['event_name'] ?? ''),
                   'starts' => (string) ($row['start_date'] ?? ''),
                   'hub' => (string) ($row['hub_spoke'] ?? ''),
                   'type' => (string) ($row['event_type'] ?? '')],
    'status'   => $s,
    'mine'     => $mine,
    'can_vote' => $mine !== null && $s !== null && $s['state'] === 'pending',
]);

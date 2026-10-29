<?php
/**
 * The committee desk's own endpoint (committee.php): one event, in full, for
 * a member of the committee it was sent to -- and that member's vote.
 *
 *   GET  ?id=N                          the event's details, its attachments,
 *                                       every member's decision, and whether
 *                                       the viewer may still decide.
 *   POST action=vote, id, decision      approve or reject; a rejection must
 *        (approved|rejected),           carry its remarks / justification.
 *        justification
 *
 * Not fabo_endpoint(): a committee member holds no sheets at all. What lets
 * them in is sitting on the event's committee, checked here on every call --
 * fabo_cm_sees_event() for reading, fabo_cm_vote() for deciding.
 */

require_once __DIR__ . '/../lib/endpoint.php';
require_once __DIR__ . '/../lib/uploads.php';
require_once __DIR__ . '/../lib/committees.php';
fabo_boot();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    fabo_csrf_check();
}
$token  = (string) ($_REQUEST['k'] ?? '');
$viewer = $token !== '' ? fabo_client_viewer($token)
        : (fabo_signed_in() ? fabo_person_viewer(fabo_signed_in()) : null);
if (!$viewer) {
    fabo_json_fail('Please sign in.', 401);
}
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$id = (int) ($_REQUEST['id'] ?? 0);
if (!fabo_cm_sees_event($viewer, $id)) {
    fabo_json_fail('That event is not assigned to a committee you sit on.', 404);
}

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ((string) ($_POST['action'] ?? '') !== 'vote') {
        fabo_json_fail('Nothing to do.', 400);
    }
    /* One decision per member on the desk: once given it stands, and the
       committee's rule settles the event from there. */
    foreach (fabo_cm_status($id)['members'] ?? [] as $m) {
        if ($m['id'] === fabo_cm_person($viewer) && $m['decision'] !== 'pending') {
            fabo_json_fail('You have already ' . $m['decision'] . ' this event.', 400);
        }
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

$row    = fabo_one('SELECT * FROM `events` WHERE id = ?', 'i', [$id]);
$s      = fabo_cm_status($id);
$person = fabo_cm_person($viewer);
$mine   = null;
foreach ($s['members'] ?? [] as $m) {
    if ($m['id'] === $person) {
        $mine = $m;
    }
}

$files = [];
foreach (fabo_cm_event_files($id) as $a) {
    $q = ['f' => (int) $a['id']];
    if ($token !== '') {
        $q['k'] = $token;
    }
    $files[] = [
        'id'       => (int) $a['id'],
        'name'     => (string) $a['original_name'],
        'where'    => (string) $a['_where'],
        'size'     => fabo_human_size((int) $a['size_bytes']),
        'is_image' => fabo_is_image((string) $a['mime']) && (string) $a['mime'] !== 'image/svg+xml',
        'by'       => (string) ($a['uploaded_by'] ?? ''),
        'when'     => date('d M Y, H:i', strtotime((string) $a['created_at'])),
        'url'      => 'download.php?' . http_build_query($q),
        // event | quotation | invoice -- the pop-up lists each under its own heading
        'kind'     => (string) ($a['_kind'] ?? 'event'),
        'approved_by' => (string) ($a['approved_by'] ?? ''),
        'amount'   => isset($a['required']) && (float) $a['required'] > 0
                      ? fabo_money((float) $a['required']) : '',
    ];
}

/* Upcoming: the committee verifies the quotations. Completed: it reviews
   the invoices. The same split as the Upcoming / Completed Events tabs. */
require_once __DIR__ . '/../lib/eventstages.php';
$stage = $row && fabo_evstage_is_done($row) ? 'completed' : 'upcoming';

require_once __DIR__ . '/../lib/approvals.php';
$appr  = fabo_approval('events', $id);
$state = in_array((string) $appr['state'], ['approved', 'rejected'], true) ? (string) $appr['state'] : 'pending';

fabo_json([
    'ok'       => true,
    'message'  => $message,
    'approval' => ['state' => $state, 'remarks' => (string) ($appr['remarks'] ?? ''),
                   'by' => (string) ($appr['decided_by'] ?? ''),
                   'when' => !empty($appr['decided_at']) ? date('d M Y, H:i', strtotime((string) $appr['decided_at'])) : ''],
    'event'    => ['id' => $id, 'ref' => (string) ($row['event_id'] ?? ''),
                   'name' => (string) ($row['event_name'] ?? ''),
                   'starts' => (string) ($row['start_date'] ?? ''),
                   'hub' => (string) ($row['hub_spoke'] ?? ''),
                   'type' => (string) ($row['event_type'] ?? '')],
    'details'  => fabo_cm_event_details($row ?: []),
    /* The budget the committee is deciding on: every head with an amount,
       its note, its quotations count and where it stands. */
    'budget'   => fabo_cm_budget_lines($id),
    'budget_total' => fabo_cm_budget_total($id),
    'stage'    => $stage,
    'files'    => $files,
    'status'   => $s,
    'mine'     => $mine,
    'can_vote' => $mine !== null && $s !== null && $s['state'] === 'pending' && $mine['decision'] === 'pending',
]);

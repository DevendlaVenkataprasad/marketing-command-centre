<?php
/**
 * Approve or reject a Content / Design row, and send rows out for approval.
 *
 * Deciding is not a sheet-wide right. A client sees the approval state of
 * every row they can open, but the Approve and Reject buttons only appear —
 * and are only accepted here — on the rows someone has explicitly sent them,
 * and only for accounts that hold the sheet with edit. A sheet shared
 * read-only is shared to be read.
 * fabo_may_decide() is the single gate; the UI never decides this by itself.
 */

require_once __DIR__ . '/../lib/endpoint.php';
require_once __DIR__ . '/../lib/approvals.php';
require_once __DIR__ . '/../lib/notify.php';

/* The endpoint asks only for 'view' because reading the approval state is
   something any holder of the sheet may do. Deciding is not: fabo_may_decide()
   below requires edit, and it is the only gate that matters. */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    fabo_csrf_check();
}
[$viewer, $key, $sheet, $perm] = fabo_endpoint('view');

if (!fabo_has_approval($key)) {
    fabo_json_fail('This sheet has no approval step.', 400);
}

$rowId = (int) ($_REQUEST['id'] ?? 0);
$row   = fabo_load_row($viewer, $key, $rowId);
if (!$row) {
    fabo_json_fail('Row not found.', 404);
}

$action   = (string) ($_POST['action'] ?? '');
$rowTitle = fabo_row_title($key, $row);
$actor    = $viewer['is_admin'] ? null : $viewer['client'];
$whoName  = $viewer['is_admin'] ? 'Admin' : (string) $viewer['client']['name'];
$whoId    = $viewer['is_admin'] ? 0 : (int) $viewer['client']['id'];
$sent     = [];   // per-recipient outcome, echoed back to the drawer

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* ------------------------------------------- send the row for approval */
    if ($action === 'request' || $action === 'cancel') {
        if (!fabo_may_request($viewer, $key)) {
            fabo_json_fail('You cannot send rows for approval.', 403);
        }
        // Only people this viewer is actually allowed to ask.
        $allowed = [];
        foreach (fabo_request_candidates($viewer, $key, $row ?: null) as $c) {
            $allowed[(int) $c['id']] = $c;
        }
        // The drawer sends a comma-separated list; a plain form would send an array.
        $raw = $_POST['to'] ?? [];
        $raw = is_array($raw) ? $raw : explode(',', (string) $raw);
        $ids = array_values(array_unique(array_map('intval', $raw)));
        $ids = array_values(array_filter($ids, static fn($i) => isset($allowed[$i])));
        if (!$ids) {
            fabo_json_fail($action === 'cancel'
                ? 'Choose whose request to withdraw.'
                : 'Choose at least one person to send this to.', 400);
        }

        if ($action === 'cancel') {
            foreach ($ids as $cid) {
                fabo_cancel_request($key, $rowId, $cid);
                fabo_audit($viewer, $sheet['table'], $rowId, '(approval request)',
                           $allowed[$cid]['name'], 'withdrawn');
            }
        } else {
            $note   = trim((string) ($_POST['note'] ?? ''));
            $people = [];
            foreach ($ids as $cid) {
                fabo_add_request($key, $rowId, $cid, $note, $whoName, $whoId);
                fabo_audit($viewer, $sheet['table'], $rowId, '(approval request)',
                           null, $allowed[$cid]['name'] . ($note !== '' ? ': ' . $note : ''));
                $people[] = $allowed[$cid];
            }
            // Notices are written per person, then every email goes down one
            // SMTP session — sending a row to forty clients otherwise costs a
            // second or two each and the request gives up part way through.
            $results = fabo_announce_requests($people, $key, $rowId, $rowTitle, $note, $actor);
            foreach ($people as $p) {
                [$ok, $detail] = $results[(int) $p['id']] ?? [false, 'not attempted'];
                $sent[] = ['name' => $p['name'], 'ok' => $ok, 'detail' => $detail];
            }
        }
    }

    /* -------------------------------------------------- record a decision */
    elseif ($action === '' || $action === 'decide') {
        if (!fabo_may_decide($viewer, $key, $rowId)) {
            fabo_json_fail($key === 'events'
                ? 'Events are approved by their committee. Choose a Committee on the row; it reviews the event and its budget.'
                : ($viewer['is_admin']
                ? 'This sheet has no approval step.'
                : 'This item has not been sent to you for approval.'), 403);
        }
        $state   = (string) ($_POST['state'] ?? '');
        $remarks = (string) ($_POST['remarks'] ?? '');

        [$ok, $msg] = fabo_set_approval($key, $rowId, $state, $remarks, $whoName);
        if (!$ok) {
            fabo_json_fail($msg, 400);
        }
        fabo_audit($viewer, $sheet['table'], $rowId, '(approval)', null,
                   $state . ($remarks !== '' ? ': ' . $remarks : ''));

        // A client answering closes their request and tells whoever asked.
        if (!$viewer['is_admin']) {
            $cid = (int) $viewer['client']['id'];
            fabo_mark_responded($key, $rowId, $cid);
            $req = fabo_approval_request($key, $rowId, $cid);
            if ($req) {
                fabo_announce_decision($req, $key, $rowId, $rowTitle, $state,
                                       trim($remarks), $viewer['client'], $row);
            }
        }
    } else {
        fabo_json_fail('Unknown action.', 400);
    }
}

/* ------------------------------------------------------------- response */

$a   = fabo_approval($key, $rowId);
$log = [];
foreach (fabo_approval_log($key, $rowId) as $l) {
    $log[] = [
        'state'   => fabo_approval_label($l['state']),
        'tone'    => fabo_approval_tone($l['state']),
        'remarks' => $l['remarks'],
        'by'      => $l['decided_by'],
        'when'    => date('d M Y, H:i', strtotime($l['created_at'])),
    ];
}

// Who this row has been sent to, and who it still could be sent to.
$mayRequest = fabo_may_request($viewer, $key);
$requests   = [];
$candidates = [];
if ($mayRequest) {
    foreach (fabo_row_requests($key, $rowId) as $r) {
        $requests[] = [
            'client_id' => (int) $r['client_id'],
            'name'      => $r['name'],
            'org'       => $r['organisation'],
            'note'      => $r['note'],
            'by'        => $r['requested_by'],
            'when'      => date('d M Y, H:i', strtotime($r['requested_at'])),
            'answered'  => $r['responded_at'] ? date('d M Y, H:i', strtotime($r['responded_at'])) : null,
        ];
    }
    foreach (fabo_request_candidates($viewer, $key, $row ?: null) as $c) {
        $candidates[] = [
            'id'    => (int) $c['id'],
            'name'  => $c['name'],
            'org'   => $c['organisation'],
            'kind'  => $c['kind'],
            'email' => $c['email'],
            'head'  => !empty($c['is_head']),
        ];
    }
}

$mine = $viewer['is_admin'] ? null : fabo_approval_request($key, $rowId, (int) $viewer['client']['id']);

$fresh = fabo_refresh_row($key, fabo_compute($key, fabo_one('SELECT * FROM `' . $sheet['table'] . '` WHERE id = ?', 'i', [$rowId])));

fabo_json([
    'ok'          => true,
    'state'       => $a['state'],
    'label'       => fabo_approval_label((string) $a['state']),
    'tone'        => fabo_approval_tone((string) $a['state']),
    'remarks'     => $a['remarks'],
    'by'          => $a['decided_by'],
    'when'        => $a['decided_at'] ? date('d M Y, H:i', strtotime($a['decided_at'])) : null,
    'log'         => $log,
    // can_edit now means "may record a decision on THIS row", not "may edit the sheet".
    'can_edit'    => fabo_may_decide($viewer, $key, $rowId),
    'can_request' => $mayRequest,
    'requests'    => $requests,
    'candidates'  => $candidates,
    'asked_note'  => $mine['note'] ?? null,
    'asked_by'    => $mine['requested_by'] ?? null,
    'sent'        => $sent,
    'row'         => fabo_row_payload($sheet, $fresh),
]);

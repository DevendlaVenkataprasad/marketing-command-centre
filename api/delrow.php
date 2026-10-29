<?php
/**
 * Deleting a row, or asking for it to be deleted.
 *
 * The head of an organisation removes its records: we remove ours, a client
 * removes theirs. Anyone under a head — our team, a client's team, a
 * sub-account — is told so and offered a request instead, and that request
 * goes to their own head. A client's staff never trouble us about a record
 * that is not ours, and nothing here destroys a row on one person's say-so.
 */

require_once __DIR__ . '/../lib/endpoint.php';
require_once __DIR__ . '/../lib/deletions.php';
require_once __DIR__ . '/../lib/notify.php';

fabo_csrf_check();
[$viewer, $key, $sheet, $perm] = fabo_endpoint('edit');

$id  = (int) ($_POST['id'] ?? 0);
$row = fabo_load_row($viewer, $key, $id);
if (!$row) {
    fabo_json_fail('Row not found.', 404);
}

$label  = fabo_row_title($key, $row) ?: ('Row #' . $id);
$action = (string) ($_POST['action'] ?? '');

/* -------------------------------------------- someone asking to delete */

if ($action === 'request') {
    if (fabo_may_delete($viewer)) {
        fabo_json_fail('You can delete this yourself.', 400);
    }
    [$reqId, $already] = fabo_delete_request_add(
        $viewer, 'row', $key, $id, null, $label, (string) ($_POST['reason'] ?? '')
    );
    $req = fabo_delete_request($reqId);
    fabo_announce_delete_request($req, $viewer);
    fabo_audit($viewer, $sheet['table'], $id, '(delete request)', null,
               (string) ($_POST['reason'] ?? 'no reason given'));

    $head = fabo_delete_head($viewer)[1];
    fabo_json([
        'ok'      => true,
        'message' => $already
            ? 'That request was already waiting — ' . $head . ' has been reminded.'
            : 'Request sent. ' . ucfirst($head) . ' has been notified and will decide.',
    ]);
}

/* --------------------------------------------------- the admin deleting */

if (!fabo_may_delete($viewer)) {
    // The browser turns this into the request box; the rule is enforced here.
    $open = fabo_delete_request_open('row', $key, $id, null);
    fabo_json([
        'ok'             => false,
        'error'          => fabo_delete_denied_message($viewer),
        'needs_request'  => true,
        'already_asked'  => $open !== null,
        'label'          => $label,
    ], 403);
}

/* Files, the row, its Master Activity Log line and its place in the queue --
   see fabo_delete_row(). A failure is reported as one, with the reason, so
   the grid keeps the row on screen instead of hiding one that still exists. */
try {
    $gone = fabo_delete_row($key, $sheet, $id);
} catch (Throwable $e) {
    error_log('FABO delrow ' . $key . ' #' . $id . ': ' . $e->getMessage());
    fabo_json_fail('The row could not be deleted: ' . fabo_db_words($e), 500);
}
if (!$gone) {
    fabo_json_fail('The row could not be removed from the database. Nothing was changed.', 500);
}

fabo_audit($viewer, $sheet['table'], $id, '(row)', 'present', 'deleted');

fabo_json(['ok' => true]);

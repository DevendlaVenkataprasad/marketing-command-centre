<?php
/** List or delete the files on a row. */

require_once __DIR__ . '/../lib/endpoint.php';
require_once __DIR__ . '/../lib/uploads.php';
require_once __DIR__ . '/../lib/deletions.php';
require_once __DIR__ . '/../lib/notify.php';

$need = ($_SERVER['REQUEST_METHOD'] === 'POST') ? 'edit' : 'view';
if ($need === 'edit') {
    fabo_csrf_check();
}
[$viewer, $key, $sheet, $perm] = fabo_endpoint($need);

$rowId = (int) ($_REQUEST['id'] ?? 0);
if (!fabo_load_row($viewer, $key, $rowId)) {
    fabo_json_fail('Row not found.', 404);
}

$act = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string) ($_POST['action'] ?? '') : '';

if ($act === 'delete' || $act === 'delete_request') {
    $aid = (int) ($_POST['attachment_id'] ?? 0);
    $a = fabo_one('SELECT * FROM attachments WHERE id = ? AND sheet_key = ? AND row_id = ?',
                  'isi', [$aid, $key, $rowId]);
    if (!$a) {
        fabo_json_fail('File not found.', 404);
    }

    // A document is the organisation's record too: the head of that
    // organisation removes one, and everybody under them asks.
    if ($act === 'delete_request') {
        if (fabo_may_delete($viewer)) {
            fabo_json_fail('You can delete this yourself.', 400);
        }
        [$reqId, $already] = fabo_delete_request_add(
            $viewer, 'file', $key, $rowId, $aid,
            (string) $a['original_name'], (string) ($_POST['reason'] ?? '')
        );
        fabo_announce_delete_request(fabo_delete_request($reqId), $viewer);
        fabo_audit($viewer, $sheet['table'], $rowId, '(delete request)',
                   $a['original_name'], (string) ($_POST['reason'] ?? 'no reason given'));
        $head = fabo_delete_head($viewer)[1];
        fabo_json([
            'ok'      => true,
            'message' => $already
                ? 'That request was already waiting — ' . $head . ' has been reminded.'
                : 'Request sent. ' . ucfirst($head) . ' has been notified and will decide.',
        ]);
    }

    if (!fabo_may_delete($viewer)) {
        fabo_json([
            'ok'            => false,
            'error'         => fabo_delete_denied_message($viewer),
            'needs_request' => true,
            'already_asked' => fabo_delete_request_open('file', $key, $rowId, $aid) !== null,
            'label'         => (string) $a['original_name'],
        ], 403);
    }

    fabo_exec('DELETE FROM attachments WHERE id = ?', 'i', [$aid]);
    fabo_delete_stored((string) $a['stored_name']);
    fabo_audit($viewer, $sheet['table'], $rowId, '(file)', $a['original_name'], 'deleted');
}

$base = $viewer['is_admin'] ? '../download.php' : 'download.php';
$out  = [];
foreach (fabo_attachments($key, $rowId) as $a) {
    $q = ['f' => $a['id']];
    if (!$viewer['is_admin']) {
        $q['k'] = $viewer['client']['token'];
    }
    $out[] = [
        'id'      => (int) $a['id'],
        'name'    => $a['original_name'],
        'mime'    => $a['mime'],
        'size'    => fabo_human_size((int) $a['size_bytes']),
        'is_image'=> fabo_is_image((string) $a['mime']),
        // Put in a Documents column on a shared sheet, picture or not.
        'is_doc'  => (string) ($a['caption'] ?? '') === 'file',
        'by'      => $a['uploaded_by'],
        'when'    => date('d M Y, H:i', strtotime($a['created_at'])),
        'url'     => $base . '?' . http_build_query($q),
    ];
}
fabo_json(['ok' => true, 'files' => $out, 'can_edit' => $perm === 'edit']);

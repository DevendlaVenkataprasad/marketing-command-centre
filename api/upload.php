<?php
/** Attach a file to a row. Anyone who may edit the sheet may attach. */

require_once __DIR__ . '/../lib/endpoint.php';
require_once __DIR__ . '/../lib/uploads.php';

fabo_csrf_check();
[$viewer, $key, $sheet, $perm] = fabo_endpoint('edit');

$rowId = (int) ($_POST['id'] ?? 0);
if (!fabo_load_row($viewer, $key, $rowId)) {
    fabo_json_fail('Row not found.', 404);
}

[$ok, $msg, $meta] = fabo_store_upload($_FILES['file'] ?? []);
if (!$ok) {
    fabo_json_fail($msg, 400);
}

$who = $viewer['is_admin'] ? 'Admin' : ($viewer['client']['name'] . ' (' . ($viewer['client']['kind'] ?? 'client') . ')');
fabo_exec('INSERT INTO attachments (sheet_key, row_id, original_name, stored_name, mime, size_bytes, caption, uploaded_by)
           VALUES (?,?,?,?,?,?,?,?)',
          'sisssiss',
          [$key, $rowId, $meta['original_name'], $meta['stored_name'], $meta['mime'],
           (int) $meta['size'], trim((string) ($_POST['caption'] ?? '')), $who]);

fabo_audit($viewer, $sheet['table'], $rowId, '(file)', null, $meta['original_name']);

fabo_json(['ok' => true, 'message' => 'Uploaded ' . $meta['original_name'] . '.',
           'count' => count(fabo_attachments($key, $rowId))]);

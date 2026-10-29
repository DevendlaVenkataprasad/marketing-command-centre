<?php
/**
 * The file and image columns of a built sheet.
 *
 * A cell of either type holds an attachment's id, so uploading is two steps
 * that must not come apart: store the file, then point the cell at it. Doing
 * both here means a half-done upload cannot leave a row referring to a file
 * that was never saved.
 *
 * Authorised exactly like any other cell edit — edit on the sheet, and the
 * row has to be one the viewer can already reach.
 */

require_once __DIR__ . '/../lib/endpoint.php';
require_once __DIR__ . '/../lib/uploads.php';
require_once __DIR__ . '/../lib/sheetbuilder.php';

fabo_csrf_check();
[$viewer, $key, $sheet, $perm] = fabo_endpoint('edit');

$id    = (int) ($_POST['id'] ?? 0);
$field = (string) ($_POST['field'] ?? '');

// The column has to exist on this sheet and actually be an upload column.
$type = '';
foreach ($sheet['columns'] as $c) {
    if ($c[1] === $field) {
        $type = (string) $c[2];
        break;
    }
}
if (!fabo_is_upload_type($type)) {
    fabo_json_fail('That column does not take a file.', 400);
}

/*
 * id 0 means the row does not exist yet — the composer is still being filled
 * in. The file is stored against row 0 under a one-off draft token and stays
 * there until the row is created, at which point api/newrow.php adopts it.
 * Anything never adopted is swept up there too.
 */
$draft = $id === 0 ? trim((string) ($_POST['draft'] ?? '')) : '';
$row   = null;

if ($id === 0) {
    if ($draft !== '' && !preg_match('~^[a-f0-9]{32}$~', $draft)) {
        fabo_json_fail('That draft is not one of ours.', 400);
    }
    if ($draft === '') {
        $draft = bin2hex(random_bytes(16));
    }
} else {
    $row = fabo_load_row($viewer, $key, $id);
    if (!$row) {
        fabo_json_fail('Row not found.', 404);
    }
}

$table = $sheet['table'];
$was   = $row ? (int) ($row[$field] ?? 0) : 0;
$who   = (string) ($viewer['who'] ?? 'someone');
$mark  = $draft !== '' ? '__draft:' . $draft . ':' . $field : '';

/* ------------------------------------------------------------- removing */

if (($_POST['action'] ?? '') === 'clear') {
    if ($id === 0) {
        // Nothing is pointing at it yet, so clearing means dropping the
        // staged file — the same as never having chosen it.
        foreach (fabo_all('SELECT id FROM attachments
                           WHERE sheet_key = ? AND row_id = 0 AND caption = ? AND uploaded_by = ?',
                          'sss', [$key, $mark, $who]) as $a) {
            fabo_drop_cell_file((int) $a['id']);
        }
        fabo_json(['ok' => true, 'cleared' => true, 'draft' => $draft, 'message' => 'Removed.']);
    }
    fabo_exec('UPDATE `' . $table . '` SET `' . $field . '` = NULL WHERE id = ?', 'i', [$id]);
    if ($was > 0) {
        fabo_drop_cell_file($was);
    }
    fabo_audit($viewer, $table, $id, $field, $was ?: null, null);
    fabo_json(['ok' => true, 'cleared' => true, 'message' => 'Removed.']);
}

/* ------------------------------------------------------------ uploading */

$file = $_FILES['file'] ?? null;
if (!$file) {
    fabo_json_fail('No file was sent.', 400);
}
[$ok, $message, $stored] = fabo_store_upload($file);
if (!$ok || !$stored) {
    fabo_json_fail($message, 400);
}
if ($type === 'image' && !fabo_is_image((string) $stored['mime'])) {
    fabo_delete_stored((string) $stored['stored_name']);
    fabo_json_fail('That column takes an image.', 400);
}

// One file per cell: anything already staged for this draft column goes.
if ($id === 0) {
    foreach (fabo_all('SELECT id FROM attachments
                       WHERE sheet_key = ? AND row_id = 0 AND caption = ? AND uploaded_by = ?',
                      'sss', [$key, $mark, $who]) as $a) {
        fabo_drop_cell_file((int) $a['id']);
    }
}

$aid = (int) fabo_exec(
    'INSERT INTO attachments (sheet_key, row_id, original_name, stored_name, mime,
         size_bytes, caption, uploaded_by)
     VALUES (?,?,?,?,?,?,?,?)', 'sissssss',
    [$key, $id, $stored['original_name'], $stored['stored_name'], $stored['mime'],
     (string) (int) $stored['size'], $mark, $who]
);
if ($id > 0) {
    fabo_exec('UPDATE `' . $table . '` SET `' . $field . '` = ? WHERE id = ?', 'ii', [$aid, $id]);
    // The one it replaced is no longer referenced by anything, so it goes.
    if ($was > 0 && $was !== $aid) {
        fabo_drop_cell_file($was);
    }
    fabo_audit($viewer, $table, $id, $field, $was ?: null, $aid);
}

$token = (!$viewer['is_admin'] && !fabo_signed_in())
    ? '&k=' . rawurlencode((string) $viewer['client']['token']) : '';

fabo_json([
    'ok'    => true,
    'id'    => $aid,
    'draft' => $draft,
    'name'  => $stored['original_name'],
    'mime'  => $stored['mime'],
    'image' => fabo_is_image((string) $stored['mime']),
    'href'  => 'download.php?f=' . $aid . $token,
    'message' => 'Uploaded.',
]);

/** Deletes an attachment and the file behind it. */
function fabo_drop_cell_file(int $attachmentId): void
{
    $a = fabo_one('SELECT stored_name FROM attachments WHERE id = ?', 'i', [$attachmentId]);
    if ($a) {
        fabo_delete_stored((string) $a['stored_name']);
        fabo_exec('DELETE FROM attachments WHERE id = ?', 'i', [$attachmentId]);
    }
}

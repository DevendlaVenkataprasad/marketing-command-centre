<?php
/**
 * The Images column: a gallery in a cell.
 *
 * Unlike an `image` column, which holds one picture as an attachment id,
 * `images` holds none — the pictures are attachments on the row and the
 * column is only where they are shown. That is why this endpoint exists
 * rather than reusing api/cellfile.php: there is no single id to set.
 *
 * Authorised exactly like any cell edit: edit on the sheet, and a row the
 * viewer can already reach.
 */

require_once __DIR__ . '/../lib/endpoint.php';
require_once __DIR__ . '/../lib/uploads.php';

fabo_csrf_check();
[$viewer, $key, $sheet, $perm] = fabo_endpoint('edit');

$id    = (int) ($_POST['id'] ?? 0);
$field = (string) ($_POST['field'] ?? '');

// The column has to exist on this sheet and be a gallery.
$isGallery = false;
foreach ($sheet['columns'] as $c) {
    if ($c[1] === $field && $c[2] === 'images') {
        $isGallery = true;
        break;
    }
}
if (!$isGallery) {
    fabo_json_fail('That column does not hold images.', 400);
}
if (!fabo_load_row($viewer, $key, $id)) {
    fabo_json_fail('Row not found.', 404);
}

$token = (!$viewer['is_admin'] && !fabo_signed_in())
    ? '&k=' . rawurlencode((string) $viewer['client']['token']) : '';

/* ------------------------------------------------------------- removing */

if (($_POST['action'] ?? '') === 'drop') {
    $aid = (int) ($_POST['image'] ?? 0);
    // It has to be on this row, or an id alone would reach another's.
    $a = fabo_one('SELECT stored_name FROM attachments
                   WHERE id = ? AND sheet_key = ? AND row_id = ?', 'isi', [$aid, $key, $id]);
    if (!$a) {
        fabo_json_fail('That image is not on this row.', 404);
    }
    fabo_delete_stored((string) $a['stored_name']);
    fabo_exec('DELETE FROM attachments WHERE id = ?', 'i', [$aid]);
    fabo_audit($viewer, $sheet['table'], $id, $field, $aid, null);
    fabo_json(['ok' => true, 'removed' => $aid, 'message' => 'Image removed.']);
}

/* ------------------------------------------------------------ uploading */

$files = $_FILES['images'] ?? null;
if (!$files || !isset($files['name'])) {
    fabo_json_fail('No image was sent.', 400);
}
$names = is_array($files['name']) ? $files['name'] : [$files['name']];
$out   = [];
$errs  = [];

for ($i = 0; $i < count($names); $i++) {
    $one = is_array($files['name'])
        ? ['name' => $files['name'][$i], 'type' => $files['type'][$i],
           'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i],
           'size' => $files['size'][$i]]
        : $files;
    if (($one['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        continue;
    }
    [$ok, $msg, $stored] = fabo_store_upload($one);
    if (!$ok || !$stored) {
        $errs[] = $one['name'] . ': ' . $msg;
        continue;
    }
    // Any image format, but it has to be an image — the bytes decide, so a
    // renamed file cannot arrive as a picture.
    if (!fabo_is_image((string) $stored['mime'])) {
        fabo_delete_stored((string) $stored['stored_name']);
        $errs[] = $one['name'] . ': that is not an image.';
        continue;
    }
    $aid = (int) fabo_exec('INSERT INTO attachments
             (sheet_key, row_id, original_name, stored_name, mime, size_bytes,
              caption, uploaded_by)
           VALUES (?,?,?,?,?,?,?,?)', 'sissssss',
        [$key, $id, $stored['original_name'], $stored['stored_name'],
         $stored['mime'], (string) (int) $stored['size'], '',
         (string) ($viewer['who'] ?? 'someone')]);
    $out[] = ['id' => $aid, 'name' => $stored['original_name'],
              'href' => 'download.php?f=' . $aid . $token];
}

if (!$out && $errs) {
    fabo_json_fail(implode(' ', $errs), 400);
}
fabo_audit($viewer, $sheet['table'], $id, $field, null, count($out) . ' image(s)');

fabo_json(['ok' => true, 'images' => $out, 'warn' => $errs ? implode(' ', $errs) : '',
           'message' => count($out) === 1 ? 'Image added.' : count($out) . ' images added.']);

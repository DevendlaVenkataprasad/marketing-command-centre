<?php
/**
 * Creates a row from the composer — the line that opens when you press Add
 * row — with everything filled in at once.
 *
 * api/addrow.php still exists and still makes a blank row for editing in
 * place; both are useful. This is for the case where you know what the row
 * says before it exists, and especially where it carries a file: the upload
 * had nowhere to attach itself, so it was staged, and this is where it is
 * adopted. Row and files land together or not at all.
 */

require_once __DIR__ . '/../lib/endpoint.php';
require_once __DIR__ . '/../lib/uploads.php';
require_once __DIR__ . '/../lib/sheetbuilder.php';

fabo_csrf_check();
[$viewer, $key, $sheet, $perm] = fabo_endpoint('edit');

$draft = trim((string) ($_POST['draft'] ?? ''));
if ($draft !== '' && !preg_match('~^[a-f0-9]{32}$~', $draft)) {
    fabo_json_fail('That draft is not one of ours.', 400);
}
$who = (string) ($viewer['who'] ?? 'someone');

/* Anything staged and then abandoned is swept up here rather than in a
   scheduled job, which this portal does not have. A day is long enough that
   nobody loses a file they are still working on. */
foreach (fabo_all("SELECT id FROM attachments
                   WHERE row_id = 0 AND caption LIKE '\_\_draft:%'
                     AND created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)") as $old) {
    fabo_drop_new_file((int) $old['id']);
}

$editable = fabo_editable_fields($sheet);
$values   = [];
foreach ((array) ($_POST['f'] ?? []) as $field => $v) {
    $field = (string) $field;
    if (!isset($editable[$field]) || (!is_scalar($v) && $v !== null)) {
        continue;
    }
    $v = trim((string) $v);
    if ($v === '__add__' || $v === '__manage__') {
        fabo_json_fail('That is the add-option marker, not a value.', 400);
    }
    $values[$field] = fabo_coerce($editable[$field], $v);
}

/* The grid's own rules: kept columns, locked results columns, dropdown lists,
   and no approval or committee hand-off through a plain value. */
$values = fabo_guard_values($sheet, $values, null);

if (!fabo_scope_allows($viewer, $key, $values)) {
    fabo_json_fail('That value is outside your data scope.', 403);
}

/* ------------------------------------------------------------ the row */

$cols  = [];
$marks = [];
$types = '';
$args  = [];

if (!empty($sheet['id_field']) && !empty($sheet['prefix'])) {
    $cols[]  = '`' . $sheet['id_field'] . '`';
    $marks[] = '?';
    $types  .= 's';
    $args[]  = fabo_next_id($sheet);
}
foreach ($values as $f => $v) {
    $cols[]  = '`' . $f . '`';
    $marks[] = '?';
    $types  .= 's';
    $args[]  = $v;
}

// Whose row this is: their own, or for the admin the client being worked in.
$owner = fabo_row_owner($viewer);
if ($owner === null && $viewer['is_admin']) {
    $picked = (int) ($_POST['client'] ?? 0);
    if ($picked > 0 && fabo_one("SELECT id FROM clients WHERE id = ? AND kind = 'client'",
                                'i', [$picked])) {
        $owner = $picked;
    }
}
if ($owner !== null) {
    $cols[]  = '`client_id`';
    $marks[] = '?';
    $types  .= 'i';
    $args[]  = $owner;
}

$table = $sheet['table'];
$id    = $cols
    ? (int) fabo_exec('INSERT INTO `' . $table . '` (' . implode(', ', $cols) . ')'
                    . ' VALUES (' . implode(', ', $marks) . ')', $types, $args)
    : (int) fabo_exec('INSERT INTO `' . $table . '` () VALUES ()');

/* A row entered as already Completed is entered as approved. */
fabo_signoff_on_complete($key, $id, $viewer);

/* ------------------------------------------- the files it was given */

if ($draft !== '') {
    foreach ($sheet['columns'] as $c) {
        if (!fabo_is_upload_type((string) $c[2])) {
            continue;
        }
        $mark = '__draft:' . $draft . ':' . $c[1];
        $a = fabo_one('SELECT id FROM attachments
                       WHERE sheet_key = ? AND row_id = 0 AND caption = ? AND uploaded_by = ?
                       ORDER BY id DESC LIMIT 1', 'sss', [$key, $mark, $who]);
        if (!$a) {
            continue;
        }
        $aid = (int) $a['id'];
        fabo_exec("UPDATE attachments SET row_id = ?, caption = '' WHERE id = ?", 'ii', [$id, $aid]);
        fabo_exec('UPDATE `' . $table . '` SET `' . $c[1] . '` = ? WHERE id = ?', 'ii', [$aid, $id]);
    }
    // Anything else left over from this draft was replaced, not adopted.
    foreach (fabo_all('SELECT id FROM attachments
                       WHERE sheet_key = ? AND row_id = 0 AND caption LIKE ? AND uploaded_by = ?',
                      'sss', [$key, '__draft:' . $draft . ':%', $who]) as $left) {
        fabo_drop_new_file((int) $left['id']);
    }
}

fabo_audit($viewer, $table, $id, '(row)', null, 'added');

$fresh = fabo_compute($key, fabo_one('SELECT * FROM `' . $table . '` WHERE id = ?', 'i', [$id]));
if ($key === 'campaigns') {
    $fresh = fabo_score_row($fresh);
}
$fresh = fabo_refresh_row($key, $fresh);

/* Into the Master Activity Log, which is what the dashboard is built from.
   This row arrived with its fields already filled in, so it earns its line
   straight away. */
require_once __DIR__ . '/../lib/masterlog.php';
fabo_master_sync($key, (int) $id);

fabo_json(['ok' => true, 'id' => $id, 'row' => fabo_row_payload($sheet, $fresh),
           'message' => 'Row added.']);

/** Deletes an attachment and the file behind it. */
function fabo_drop_new_file(int $attachmentId): void
{
    $a = fabo_one('SELECT stored_name FROM attachments WHERE id = ?', 'i', [$attachmentId]);
    if ($a) {
        fabo_delete_stored((string) $a['stored_name']);
        fabo_exec('DELETE FROM attachments WHERE id = ?', 'i', [$attachmentId]);
    }
}

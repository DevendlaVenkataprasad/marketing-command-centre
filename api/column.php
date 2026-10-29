<?php
/**
 * Rename a column label in place. An empty label resets it to the default.
 *
 * The wording belongs to an organisation, not to the portal: we keep the
 * shared default, and a client who renames a column changes only what their
 * own people see. Two organisations can call the same column different
 * things without either noticing the other.
 *
 * Who may rename: the administrator, and a client in their own right. Their
 * staff read the sheet; they do not re-word it.
 */

require_once __DIR__ . '/../lib/endpoint.php';
require_once __DIR__ . '/../lib/lists.php';
require_once __DIR__ . '/../lib/deletions.php';   // fabo_may_delete(): head of an org

fabo_csrf_check();

// Asking for 'edit' is what proves the caller may touch this sheet at all.
// Before this the endpoint checked only for a ?k= link, so a signed-in
// client could rename any column for every other client.
[$viewer, $key, $sheet, $perm] = fabo_endpoint('edit');

// The same rule the grid rendered the control from, so the two cannot drift.
// Narrower than the dropdown rule on purpose: a heading is read by everyone
// in the organisation, on every row. See fabo_may_rename_columns().
if (!fabo_may_rename_columns($viewer, $perm)) {
    fabo_json_fail('Only the head of your organisation can rename a column.', 403);
}

$owner = fabo_customiser($viewer);
$field = (string) ($_POST['field'] ?? '');
$label = trim((string) ($_POST['label'] ?? ''));

$raw = fabo_sheet($key, true);
if (!$raw) {
    fabo_json_fail('Unknown sheet.', 404);
}
$default = null;
foreach ($raw['columns'] as $c) {
    if ($c[1] === $field) { $default = $c[0]; break; }
}
if ($default === null) {
    fabo_json_fail('Unknown column.', 404);
}

/*
 * For a client, "the default" is whatever we have named it — their reset
 * should go back to the wording they were given, not to the built-in one we
 * may have already changed for everybody.
 */
if ($owner > 0) {
    $shared = fabo_one('SELECT label FROM column_labels
                        WHERE sheet_key = ? AND field = ? AND client_id = 0',
                       'ss', [$key, $field]);
    if ($shared) {
        $default = (string) $shared['label'];
    }
}

if ($label === '' || $label === $default) {
    fabo_exec('DELETE FROM column_labels WHERE sheet_key = ? AND field = ? AND client_id = ?',
              'ssi', [$key, $field, $owner]);
    $label = $default;
} else {
    $label = mb_substr($label, 0, 160);
    fabo_exec('INSERT INTO column_labels (sheet_key, field, label, client_id) VALUES (?,?,?,?)
               ON DUPLICATE KEY UPDATE label = VALUES(label)', 'sssi',
              [$key, $field, $label, $owner]);
}

fabo_json([
    'ok'         => true,
    'label'      => $label,
    'default'    => $default,
    'is_default' => $label === $default,
]);

<?php
/**
 * Inserts a row with its ID pre-generated, filed under whoever it belongs to.
 *
 * A client's new row is theirs. The admin's belongs to the client they are
 * working in, or to nobody when they are looking at everything.
 */

require_once __DIR__ . '/../lib/endpoint.php';

fabo_csrf_check();
[$viewer, $key, $sheet, $perm] = fabo_endpoint('edit');

$cols  = [];
$vals  = [];
$types = '';

if (!empty($sheet['id_field']) && !empty($sheet['prefix'])) {
    $cols[]  = '`' . $sheet['id_field'] . '`';
    $vals[]  = fabo_next_id($sheet);
    $types  .= 's';
}

// Whose row this is. A client's own; for the admin, the client picked in the
// toolbar, so working inside a client files the work under them.
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
    $vals[]  = $owner;
    $types  .= 'i';
}

if ($cols) {
    $sql = 'INSERT INTO `' . $sheet['table'] . '` (' . implode(', ', $cols) . ') VALUES ('
         . implode(', ', array_fill(0, count($cols), '?')) . ')';
    $id = fabo_exec($sql, $types, $vals);
} else {
    $id = fabo_exec('INSERT INTO `' . $sheet['table'] . '` () VALUES ()');
}

fabo_audit($viewer, $sheet['table'], (int) $id, '(row)', null, 'added');

/* Into the Master Activity Log, which is what the dashboard is built from.
   A row with nothing in it yet earns no line; it gets one when it says
   something — see fabo_master_sync(). */
require_once __DIR__ . '/../lib/masterlog.php';
fabo_master_sync($key, (int) $id);

$fresh = fabo_compute($key, fabo_one('SELECT * FROM `' . $sheet['table'] . '` WHERE id = ?', 'i', [$id]));
if ($key === 'campaigns') {
    $fresh = fabo_score_row($fresh);   // the score is relative to every other campaign
}
$fresh = fabo_refresh_row($key, $fresh);

fabo_json(['ok' => true, 'row' => fabo_row_payload($sheet, $fresh)]);

<?php
/**
 * Gives several events one committee at once — the Events grid's
 * "Assign committee to selected".
 *
 *   POST sheet=events, ids[]=…, committee=<name>
 *
 * Each event goes through exactly what editing its Committee cell does
 * (api/save.php): the viewer must be able to edit the sheet and open the
 * row, the column must not be locked for that row, and fabo_cm_assign()
 * checks the committee is the viewer's organisation's own, sends the event
 * and its budget to it and tells the members. An event already with that
 * committee is left as it is. The members get one email listing every
 * newly assigned event (fabo_cm_assign_many_mail()), not one per event.
 */

require_once __DIR__ . '/../lib/endpoint.php';
require_once __DIR__ . '/../lib/committees.php';

fabo_csrf_check();
[$viewer, $key, $sheet, $perm] = fabo_endpoint('edit');
if ($key !== 'events') {
    fabo_json_fail('Committees are given to events only.', 400);
}
if (!isset(fabo_editable_fields($sheet)['committee'])) {
    fabo_json_fail('The Committee column cannot be edited.', 400);
}
$name = trim((string) ($_POST['committee'] ?? ''));
if ($name === '') {
    fabo_json_fail('Choose a committee.', 400);
}
$ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])))));
if (!$ids) {
    fabo_json_fail('Tick at least one programme.', 400);
}
if (count($ids) > 500) {
    fabo_json_fail('Assign at most 500 programmes at a time.', 400);
}
$col = null;
foreach ($sheet['columns'] as $c) {
    if ($c[1] === 'committee') {
        $col = $c;
    }
}

$done = $same = 0;
$skipped = [];
$title = null;
$cm = null;
$assigned = [];
foreach ($ids as $id) {
    $row = fabo_load_row($viewer, $key, $id);
    if (!$row) {
        $skipped[] = '#' . $id . ' (not found)';
        continue;
    }
    $label = trim((string) ($row['event_name'] ?? '')) ?: (string) ($row['event_id'] ?? ('#' . $id));
    if ($col && fabo_grid_cell_locked($col, $row)) {
        $skipped[] = $label . ' (' . fabo_grid_cell_lock_note($col, $row) . ')';
        continue;
    }
    if (!fabo_scope_allows($viewer, $key, array_merge($row, ['committee' => $name]))) {
        $skipped[] = $label . ' (outside your data scope)';
        continue;
    }
    if ((string) $row['committee'] === $name) {
        $same++;
        continue;
    }
    try {
        [$c] = fabo_cm_assign($viewer, $id, $name, false);
    } catch (InvalidArgumentException $e) {
        fabo_json_fail($e->getMessage(), 400);    // the committee itself is wrong: same for every row
    }
    $cm = $c;
    $assigned[] = $id;
    $title = fabo_cm_title((string) $c['name']);
    fabo_audit($viewer, $sheet['table'], $id, 'committee', $row['committee'], $name);
    $done++;
}

$mailed = 0;
if ($cm && $assigned) {
    $who = !empty($viewer['is_admin']) ? 'Admin' : (string) ($viewer['client']['name'] ?? '');
    $mailed = fabo_cm_assign_many_mail($cm, $assigned, $who);
}

$msg = $done
    ? $done . ' programme' . ($done === 1 ? '' : 's') . ' sent to ' . ($title ?? fabo_cm_title($name))
      . ' with ' . ($done === 1 ? 'its' : 'their') . ' budget' . ($done === 1 ? '' : 's') . '; '
      . $mailed . ' member' . ($mailed === 1 ? '' : 's') . ' emailed once with the full list.'
    : 'No programme was newly assigned.';
if ($same) {
    $msg .= ' ' . $same . ' already had this committee and ' . ($same === 1 ? 'was' : 'were') . ' left as ' . ($same === 1 ? 'it was' : 'they were') . '.';
}
if ($skipped) {
    $msg .= ' Not assigned: ' . implode('; ', array_slice($skipped, 0, 5)) . (count($skipped) > 5 ? '; …' : '') . '.';
}
fabo_json(['ok' => true, 'message' => $msg, 'assigned' => $done, 'same' => $same, 'skipped' => count($skipped)]);

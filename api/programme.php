<?php
/**
 * Saves a whole programme from the calendar's form, creating it if it is new.
 *
 * The grid saves one cell at a time, which is right for a spreadsheet and
 * wrong for filling in a programme: you would send twenty requests and could
 * leave the record half-written if one failed. This writes the lot in one
 * go, or writes nothing.
 *
 * It is the Events sheet's own endpoint, so it is bound to that sheet — the
 * permission checked below is the Events permission, and the fields it will
 * accept are the Events columns. Nothing else can be reached through it.
 */

require_once __DIR__ . '/../lib/endpoint.php';
require_once __DIR__ . '/../lib/programs.php';

fabo_csrf_check();
[$viewer, $key, $sheet, $perm] = fabo_endpoint('edit');

if ($key !== 'events') {
    fabo_json_fail('That form belongs to the Events sheet.', 400);
}

$id       = (int) ($_POST['id'] ?? 0);
$editable = fabo_editable_fields($sheet);

/* The form's fields, and only these. A column that is computed, or that the
   grid alone manages (the approval hand-off), is not in the list and cannot
   be written from here even if it is posted. */
$fields = [
    'event_name', 'event_type', 'brand', 'business_unit', 'start_date', 'end_date',
    'location', 'city', 'owner', 'status', 'priority',
    'budget_allocated', 'budget_spent', 'expected_attendees', 'actual_attendees',
    'leads_expected', 'leads_generated', 'linked_campaign_id', 'creative_due_date',
    'programme_details', 'sector', 'target_audience', 'partners', 'partner_details',
    'partners_count', 'duration_hours', 'duration_hours_expected', 'funding_source',
    'local_resources', 'case_studies', 'spoc_name', 'spoc_email', 'spoc_contact',
    'approval_status', 'approval_note', 'remarks',
];

$values = [];
foreach ($fields as $f) {
    if (!array_key_exists($f, $_POST) || !isset($editable[$f])) {
        continue;
    }
    $raw = $_POST[$f];
    if (!is_scalar($raw) && $raw !== null) {
        continue;                       // an array where a value was expected
    }
    $raw = (string) $raw;
    // The dropdown markers are controls, not values — the same guard the
    // cell save has, for the same reason.
    if ($raw === '__add__' || $raw === '__manage__') {
        fabo_json_fail('That is the add-option marker, not a value.', 400);
    }
    $values[$f] = fabo_coerce($editable[$f], $raw);
}

if (!$values) {
    fabo_json_fail('Nothing to save.', 400);
}

// A programme with no name is a row nobody can find again.
if (array_key_exists('event_name', $values) && trim((string) $values['event_name']) === '') {
    fabo_json_fail('Give the programme a name.', 400);
}

// A run that ends before it starts is a typo, and silently storing it would
// hide the programme from every view that asks what is on this week.
$start = (string) ($values['start_date'] ?? '');
$end   = (string) ($values['end_date'] ?? '');
if ($start !== '' && $end !== '' && $end < $start) {
    fabo_json_fail('The end date is before the start date.', 400);
}

$table = $sheet['table'];

if ($id > 0) {
    $row = fabo_load_row($viewer, $key, $id);     // ownership is checked in here
    if (!$row) {
        fabo_json_fail('That programme was not found.', 404);
    }
    // The grid's own rules: kept columns, locked results columns, dropdown lists.
    $values = fabo_guard_values($sheet, $values, $row);
    if (!$values) {
        fabo_json_fail('Nothing to save.', 400);
    }
    if (!fabo_scope_allows($viewer, $key, array_merge($row, $values))) {
        fabo_json_fail('That value is outside your data scope.', 403);
    }

    $set = [];
    $types = '';
    $args = [];
    foreach ($values as $f => $v) {
        $set[]  = '`' . $f . '` = ?';
        $types .= 's';
        $args[] = $v;
    }
    $types .= 'i';
    $args[] = $id;
    fabo_exec('UPDATE `' . $table . '` SET ' . implode(', ', $set) . ' WHERE id = ?', $types, $args);

    foreach ($values as $f => $v) {
        if ((string) ($row[$f] ?? '') !== (string) $v) {
            fabo_audit($viewer, $table, $id, $f, $row[$f] ?? null, $v);
        }
    }
} else {
    $values = fabo_guard_values($sheet, $values, null);
    if (!fabo_scope_allows($viewer, $key, $values)) {
        fabo_json_fail('That value is outside your data scope.', 403);
    }

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
    // Whose programme this is — their own; for the admin, the client they
    // are working inside, exactly as adding a row does.
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

    $id = (int) fabo_exec('INSERT INTO `' . $table . '` (' . implode(', ', $cols) . ')'
                        . ' VALUES (' . implode(', ', $marks) . ')', $types, $args);
    fabo_audit($viewer, $table, $id, '(programme)', null, 'added');
}

fabo_json(['ok' => true, 'id' => $id,
           'message' => 'Saved “' . (string) ($values['event_name'] ?? 'programme') . '”.']);

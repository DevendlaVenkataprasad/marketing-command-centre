<?php
/**
 * Saves one cell and returns the whole row, recomputed.
 *
 * One column is more than a value: picking a name in "Send for Approval" sends
 * that person the row — an email and a notice on their dashboard — and grants
 * them the right to approve or reject it. Clearing the cell takes that back.
 */

require_once __DIR__ . '/../lib/endpoint.php';
require_once __DIR__ . '/../lib/approvals.php';
require_once __DIR__ . '/../lib/notify.php';

fabo_csrf_check();
[$viewer, $key, $sheet, $perm] = fabo_endpoint('edit');

$id    = (int) ($_POST['id'] ?? 0);
$field = (string) ($_POST['field'] ?? '');
$value = $_POST['value'] ?? '';

$editable = fabo_editable_fields($sheet);
if (!isset($editable[$field])) {
    fabo_json_fail('That column cannot be edited.', 400);
}

$row = fabo_load_row($viewer, $key, $id);
if (!$row) {
    fabo_json_fail('Row not found.', 404);
}

/* A results column stays shut until the event has been and gone. The grid
   renders it shut from the same rule; this is what makes it true. */
foreach ($sheet['columns'] as $c) {
    /* fabo_grid_cell_locked(): the grid's own rule, which lets a Start Date
       stand in for a missing End Date. The shared live sheet keeps its own. */
    if ($c[1] === $field && fabo_grid_cell_locked($c, $row)) {
        fabo_json_fail(fabo_grid_cell_lock_note($c, $row), 403);
    }
    /* A column the portal keeps is not one a person writes. The grid already
       draws it read-only; this is what makes that true rather than merely
       drawn, since anything that can post could otherwise set it and be
       silently undone by the next recalculation. */
    if ($c[1] === $field && fabo_col_kept($c) !== '') {
        fabo_json_fail(fabo_col_kept($c), 403);
    }
}

if ($value === '__add__' || $value === '__manage__') {
    fabo_json_fail('That is the add-option marker, not a value.', 400);
}
$new = fabo_coerce($editable[$field], $value);
$old = $row[$field];

/* A dropdown takes what its list offers and nothing else. */
foreach ($sheet['columns'] as $c) {
    if ($c[1] === $field && ($why = fabo_list_refusal($c, $new, $old)) !== '') {
        fabo_json_fail($why, 400);
    }
}

// A scoped client may not move a row out of — or into — its own scope.
$after = array_merge($row, [$field => $new]);
if (!fabo_scope_allows($viewer, $key, $after)) {
    fabo_json_fail('That value is outside your data scope.', 403);
}

// Sending a row for approval is an action, not just a stored name, so it is
// authorised on its own terms before anything is written.
$notice = '';

/* Giving an event a committee is the same kind of action: the name must be
   one of the viewer's own organisation's committees, it is stored with the
   committee's id, and the members are told. Done here, in full, and the
   ordinary write below is skipped. See lib/committees.php. */
if ($key === 'events' && $field === 'committee') {
    require_once __DIR__ . '/../lib/committees.php';
    try {
        [, $notice] = fabo_cm_assign($viewer, $id, (string) ($new ?? ''));
    } catch (InvalidArgumentException $e) {
        fabo_json_fail($e->getMessage(), 400);
    }
    fabo_audit($viewer, $sheet['table'], $id, 'committee', $old, $new);
    $fresh = fabo_refresh_row($key, fabo_compute($key, fabo_one('SELECT * FROM `' . $sheet['table']
             . '` WHERE id = ?', 'i', [$id])));
    fabo_json(['ok' => true, 'row' => fabo_row_payload($sheet, $fresh), 'notice' => $notice]);
}
if ($field === 'approval_to') {
    if (!fabo_may_request($viewer, $key)) {
        fabo_json_fail('You cannot send rows for approval.', 403);
    }
    $wanted = trim((string) $new);

    // Only people this viewer is actually allowed to ask, matched on the same
    // label the dropdown offered.
    $byLabel = fabo_client_option_map(fabo_request_candidates($viewer, $key, $row));
    if ($wanted !== '' && !isset($byLabel[$wanted])) {
        fabo_json_fail('That is not someone you can send this row to.', 400);
    }

    // Whoever was named before is no longer being asked.
    $previous = trim((string) $old);
    if ($previous !== '' && $previous !== $wanted && isset($byLabel[$previous])) {
        fabo_cancel_request($key, $id, (int) $byLabel[$previous]['id']);
    }

    if ($wanted === '') {
        $notice = $previous !== '' ? 'Request to ' . $previous . ' withdrawn.' : '';
    } elseif ($wanted !== $previous) {
        $person = $byLabel[$wanted];
        $who    = $viewer['is_admin'] ? 'Admin' : (string) $viewer['client']['name'];
        $title  = fabo_row_title($key, $row);
        $actor  = $viewer['is_admin'] ? null : $viewer['client'];

        $whoId = $viewer['is_admin'] ? 0 : (int) $viewer['client']['id'];
        fabo_add_request($key, $id, (int) $person['id'], '', $who, $whoId);
        [$sent, $detail] = fabo_announce_request($person, $key, $id, $title, '', $actor);

        $notice = $sent
            ? 'Sent to ' . $person['name'] . ' — emailed ' . $detail
                . ', and a notice is on their dashboard.'
            : 'Sent to ' . $person['name'] . ' — a notice is on their dashboard, but the email '
                . 'did not go out (' . $detail . ').';
        fabo_audit($viewer, $sheet['table'], $id, '(approval request)', null, $person['name']);
    }
}

if ((string) $old !== (string) $new) {
    $type = $editable[$field] === 'int' ? 'i'
          : (in_array($editable[$field], ['money', 'dec'], true) ? 'd' : 's');
    fabo_exec('UPDATE `' . $sheet['table'] . '` SET `' . $field . '` = ? WHERE id = ?',
              $type . 'i', [$new, $id]);
    fabo_audit($viewer, $sheet['table'], $id, $field, $old, $new);

    /* The Master Activity Log keeps itself: every row on a detail sheet has a
       line there, and the dashboard is built from those lines. Brought up to
       date here, after the change has landed, so the log is right the moment
       the row is. It never fails a save — see fabo_master_sync(). */
    require_once __DIR__ . '/../lib/masterlog.php';
    fabo_master_sync($key, (int) $id);

    /* A completed event is an approved one. Done before the row is read back
       below, so the Sign-off cell repaints with it straight away. */
    if ($field === 'status') {
        fabo_signoff_on_complete($key, (int) $id, $viewer);
    }
    /* Design support asked for (or completed) on an event that is already
       approved: the requisition goes to the design team now. */
    if ($key === 'events' && str_starts_with($field, 'design_')) {
        require_once __DIR__ . '/../lib/eventdesign.php';
        fabo_evdesign_raise((int) $id);
    }
}

$fresh = fabo_compute($key, fabo_one('SELECT * FROM `' . $sheet['table'] . '` WHERE id = ?', 'i', [$id]));
if ($key === 'campaigns') {
    $fresh = fabo_score_row($fresh);   // the score is relative to every other campaign
}
$fresh = fabo_refresh_row($key, $fresh);

fabo_json(['ok' => true, 'row' => fabo_row_payload($sheet, $fresh), 'notice' => $notice]);

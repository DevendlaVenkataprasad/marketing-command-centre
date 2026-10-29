<?php
/**
 * One event's budget: read the heads, enter what is needed, attach the paper.
 *
 * The one rule this file exists to keep is in fabo_budget_out(): what a team
 * member is sent never contains the allocation, the threshold or the ceiling.
 * Not blanked, not zeroed — absent. The verdict is decided here and only its
 * colour and its sentence travel, so there is nothing in the response for a
 * curious person to read out of the network tab.
 *
 * The heads themselves are never editable here at any level. Adding, renaming,
 * re-costing and reordering happen in Sheet Management, which is a different
 * page behind a different right; this endpoint only ever writes an amount, a
 * note, or a file.
 */

require_once __DIR__ . '/../lib/endpoint.php';
require_once __DIR__ . '/../lib/budget.php';
require_once __DIR__ . '/../lib/uploads.php';
require_once __DIR__ . '/../lib/deletions.php';
require_once __DIR__ . '/../lib/notify.php';

$need = ($_SERVER['REQUEST_METHOD'] === 'POST') ? 'edit' : 'view';
if ($need === 'edit') {
    fabo_csrf_check();
}
[$viewer, $key, $sheet, $perm] = fabo_endpoint($need);

/* The column is what makes a sheet budgetable. A sheet without it has no
   budget form, whatever is asked for here. */
$hasBudget = false;
foreach ($sheet['columns'] as $c) {
    if (($c[3]['calc'] ?? '') === 'budget') { $hasBudget = true; break; }
}
if (!$hasBudget) {
    fabo_json_fail('This sheet has no budget form.', 400);
}

$rowId = (int) ($_REQUEST['id'] ?? 0);
if (!fabo_load_row($viewer, $key, $rowId)) {
    fabo_json_fail('Row not found.', 404);
}

$scope   = fabo_customiser($viewer);
$maySee  = fabo_budget_may_see_allocated($viewer);
$who     = $viewer['is_admin'] ? 'Admin' : (string) ($viewer['client']['name'] ?? 'Someone');

/* ------------------------------------------------------------------ read */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $p = fabo_budget_payload($key, $rowId, $scope, $maySee);
    $p['editable'] = ($perm === 'edit');

    /* The files on each line, so the drawer can list them without a second
       round trip per row. A portal viewer arrives by token, and their download
       links have to carry it or they land on the sign-in page. */
    $tok  = (string) ($_REQUEST['k'] ?? '');
    $want = (int) ($_GET['line'] ?? 0);
    if ($want > 0 && fabo_budget_line($want, $key, $rowId)) {
        $p['line_files'] = array_map(static fn($a) => [
            'id'   => (int) $a['id'],
            'name' => (string) $a['original_name'],
            'size' => fabo_human_size((int) $a['size_bytes']),
            'url'  => '../download.php?f=' . (int) $a['id']
                    . ($tok !== '' ? '&k=' . rawurlencode($tok) : ''),
            'when' => date('d M Y', strtotime((string) $a['created_at'])),
            'by'   => (string) $a['uploaded_by'],
            // quotation | invoice, so the drawer can list each under its own heading
            'kind' => fabo_budget_doc_kind($a['doc_kind'] ?? null),
        ], fabo_budget_files($want));
    }
    fabo_json($p);
}

/* ----------------------------------------------------------------- write */

$action = (string) ($_POST['action'] ?? '');

/* Somebody could ask for a head that is not on their form, or for another
   organisation's. fabo_budget_set() checks both; this only catches the shape. */
if ($action === 'set') {
    $itemId = (int) ($_POST['item_id'] ?? 0);
    $raw    = str_replace([',', ' '], '', (string) ($_POST['required'] ?? ''));
    if ($raw !== '' && !is_numeric($raw)) {
        fabo_json_fail('Enter an amount in figures.', 400);
    }
    try {
        $line = fabo_budget_set($key, $rowId, $itemId, (float) $raw, $who, $scope,
                                $_POST['note'] ?? null);
    } catch (RuntimeException $e) {
        fabo_json_fail(fabo_user_error($e), 400);
    }
    fabo_audit($viewer, $sheet['table'], $rowId, 'budget: ' . $line['name'],
               '', number_format((float) $raw, 2, '.', ''));

    $p = fabo_budget_payload($key, $rowId, $scope, $maySee);
    $p['line'] = fabo_budget_out($line, $maySee);
    fabo_json($p);
}

/* ------------------------------------------------ the team puts it in */

/* On Events the committee decides the event and its budget: "submit" sends
   the whole budget to the event's committee (a fresh round if it was sent
   back), and nobody else approves a line. See lib/committees.php. */
if ($key === 'events' && in_array($action, ['decide', 'approved_by'], true)) {
    fabo_json_fail('On Events the committee approves the budget, not an owner.', 403);
}
if ($action === 'submit' && $key === 'events') {
    require_once __DIR__ . '/../lib/committees.php';
    try {
        [, $note] = fabo_cm_send_again($viewer, $rowId);
    } catch (InvalidArgumentException $e) {
        fabo_json_fail($e->getMessage(), 400);
    }
    fabo_audit($viewer, $sheet['table'], $rowId, 'budget sent to committee', '', $note);
    $p = fabo_budget_payload($key, $rowId, $scope, $maySee);
    $p['message'] = $note;
    fabo_json($p);
}

if ($action === 'submit') {
    try {
        [$sent, $waiting] = fabo_budget_submit($key, $rowId, $who, $scope);
    } catch (RuntimeException $e) {
        fabo_json_fail(fabo_user_error($e), 400);
    }
    if ($sent === 0) {
        fabo_json_fail('There is nothing new to submit.', 400);
    }
    fabo_audit($viewer, $sheet['table'], $rowId, 'budget submitted', '',
               $sent . ' head' . ($sent === 1 ? '' : 's')
               . ($waiting ? ', ' . $waiting . ' needing approval' : ''));

    /* One message, once, when there is actually something for a person to
       decide. Told per keystroke it would be noise; told never it would be an
       approval step that waits for somebody to happen to look. */
    if ($waiting > 0 && !$maySee) {
        [$headId] = fabo_delete_head($viewer);
        fabo_notify($headId,
            'Budget approval needed on ' . $sheet['label'],
            $who . ' has submitted a budget with ' . $waiting . ' head'
          . ($waiting === 1 ? '' : 's') . ' over the allowed limit. '
          . 'Open the Budget Required column on that row to approve or send back.',
            fabo_row_link($headId, $key, $rowId));
    }

    $p = fabo_budget_payload($key, $rowId, $scope, $maySee);
    $p['message'] = $waiting
        ? 'Submitted. ' . $waiting . ' head' . ($waiting === 1 ? ' is' : 's are')
        . ' over the limit and ' . ($waiting === 1 ? 'has' : 'have')
        . ' gone to the budget owner.'
        : 'Submitted and approved — all of it is within the allowed limits.';
    fabo_json($p);
}

/* ------------------------------------------------- approve, or send it back */

if ($action === 'decide') {
    /* The same right that sets the allocations answers the requests against
       them. Checked here and not merely drawn differently in the page. */
    if (!$maySee) {
        fabo_json_fail('Only the budget owner can answer this.', 403);
    }
    $lineId = (int) ($_POST['line_id'] ?? 0);
    try {
        $line = fabo_budget_decide($key, $rowId, $lineId,
                                   (string) ($_POST['decision'] ?? ''),
                                   (string) ($_POST['remarks'] ?? ''), $who);
    } catch (RuntimeException $e) {
        fabo_json_fail(fabo_user_error($e), 400);
    }

    fabo_audit($viewer, $sheet['table'], $rowId,
               'budget decision: ' . $line['name'],
               '', $line['decision'] . ($line['verdict']['remarks'] !== ''
                   ? ' - ' . $line['verdict']['remarks'] : ''));

    /* Tell whoever asked. A decision nobody hears about leaves the team
       staring at "waiting" for a week. */
    $asker = trim((string) $line['by']);
    if ($asker !== '') {
        $them = fabo_one('SELECT id FROM clients WHERE name = ? AND revoked_at IS NULL',
                         's', [$asker]);
        if ($them) {
            $ok = $line['decision'] === 'approved';
            fabo_notify((int) $them['id'],
                'Budget ' . ($ok ? 'approved' : 'not approved') . ': ' . $line['name'],
                ($ok ? 'Your request for ' . fabo_money($line['required'])
                     . ' against ' . $line['name'] . ' was approved.'
                     : 'Your request for ' . fabo_money($line['required'])
                     . ' against ' . $line['name'] . ' was not approved.')
                . ($line['verdict']['remarks'] !== ''
                   ? ' Reason given: ' . $line['verdict']['remarks'] : ''),
                fabo_row_link((int) $them['id'], $key, $rowId));
        }
    }

    $p = fabo_budget_payload($key, $rowId, $scope, $maySee);
    $p['line']    = fabo_budget_out($line, $maySee);
    $p['message'] = $line['decision'] === 'approved'
                  ? 'Approved. It now counts as allowed.'
                  : 'Sent back with your reason.';
    fabo_json($p);
}

/* ------------------------------------- whose approval: CEO or Committee */

if ($action === 'approved_by') {
    /* Recorded by whoever answers the requests, beside their answer. */
    if (!$maySee) {
        fabo_json_fail('Only the budget owner can record who approved this.', 403);
    }
    try {
        $line = fabo_budget_set_approver($key, $rowId, (int) ($_POST['line_id'] ?? 0),
                                         (string) ($_POST['approved_by'] ?? ''));
    } catch (RuntimeException $e) {
        fabo_json_fail(fabo_user_error($e), 400);
    }
    fabo_audit($viewer, $sheet['table'], $rowId, 'budget approved by: ' . $line['name'],
               '', $line['approved_by']);

    $p = fabo_budget_payload($key, $rowId, $scope, $maySee);
    $p['message'] = $line['approved_by'] !== ''
                  ? 'Recorded: ' . $line['approved_by'] . '.' : 'Cleared.';
    fabo_json($p);
}

/* --------------------------------------------------------------- a file */

if ($action === 'upload') {
    $itemId = (int) ($_POST['item_id'] ?? 0);

    /* A file has to hang on a line, so an amount of nothing still opens one.
       Attaching a quotation before deciding the figure is the normal order of
       events, not a mistake. */
    $line = fabo_one('SELECT * FROM budget_lines WHERE sheet_key = ? AND row_id = ? AND item_id = ?',
                     'sii', [$key, $rowId, $itemId]);
    if (!$line) {
        try {
            fabo_budget_set($key, $rowId, $itemId, 0.0, $who, $scope);
        } catch (RuntimeException $e) {
            fabo_json_fail(fabo_user_error($e), 400);
        }
        $line = fabo_one('SELECT * FROM budget_lines
                          WHERE sheet_key = ? AND row_id = ? AND item_id = ?',
                         'sii', [$key, $rowId, $itemId]);
    }
    $lineId = (int) $line['id'];

    $file = $_FILES['file'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        fabo_json_fail('Choose a file to upload.', 400);
    }
    [$ok, $message, $meta] = fabo_store_upload($file);
    if (!$ok) {
        fabo_json_fail($message, 400);
    }
    /* A quotation before the event, an invoice once it has happened -- the
       same line Upcoming and Completed Events are split on. */
    $kind = fabo_budget_stage($key, $rowId) === 'completed' ? 'invoice' : 'quotation';
    fabo_exec('INSERT INTO attachments (sheet_key, row_id, original_name, stored_name,
                   mime, size_bytes, caption, doc_kind, uploaded_by)
               VALUES (?,?,?,?,?,?,?,?,?)', 'sisssisss',
              [FABO_BUDGET_FILES, $lineId, $meta['original_name'], $meta['stored_name'],
               $meta['mime'], (int) $meta['size'],
               mb_substr(trim((string) ($_POST['caption'] ?? '')), 0, 255), $kind, $who]);

    fabo_audit($viewer, $sheet['table'], $rowId,
               'budget ' . $kind . ': ' . $line['item_name'], '', $meta['original_name']);

    $p = fabo_budget_payload($key, $rowId, $scope, $maySee);
    $p['message'] = $kind === 'invoice' ? 'Invoice uploaded.' : 'Quotation uploaded.';
    fabo_json($p);
}

if ($action === 'delete_file') {
    $aid  = (int) ($_POST['attachment_id'] ?? 0);
    $line = fabo_budget_line((int) ($_POST['line_id'] ?? 0), $key, $rowId);
    if (!$line) {
        fabo_json_fail('Budget line not found.', 404);
    }
    $a = fabo_one('SELECT * FROM attachments WHERE id = ? AND sheet_key = ? AND row_id = ?',
                  'isi', [$aid, FABO_BUDGET_FILES, (int) $line['id']]);
    if (!$a) {
        fabo_json_fail('File not found.', 404);
    }
    /* A quotation is the organisation's record as much as the uploader's, so
       removing one follows the same rule as every other document here: whoever
       heads the organisation does it, and everybody else asks. */
    if (!fabo_may_delete($viewer)) {
        fabo_json_fail('Ask whoever heads your organisation to remove this file.', 403);
    }
    fabo_delete_stored((string) $a['stored_name']);
    fabo_exec('DELETE FROM attachments WHERE id = ?', 'i', [$aid]);
    fabo_audit($viewer, $sheet['table'], $rowId,
               'budget file removed: ' . $line['item_name'], (string) $a['original_name'], '');

    $p = fabo_budget_payload($key, $rowId, $scope, $maySee);
    $p['message'] = 'Removed.';
    fabo_json($p);
}

fabo_json_fail('Unknown action.', 400);

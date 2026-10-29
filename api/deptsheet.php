<?php
/**
 * The department live sheet's own endpoint.
 *
 * Everything the sheet at d.php does — add a row, save a cell, attach a
 * photograph, take one off — answered as JSON so the page never reloads.
 *
 * There is no session behind this: the sealed token in the request is the
 * whole of the authority, and it names exactly one department. Every query
 * below is bound to that department, so there is no id in the request that
 * could be changed to reach somebody else's work.
 */

require_once __DIR__ . '/../lib/departments.php';
require_once __DIR__ . '/../lib/endpoint.php';
require_once __DIR__ . '/../lib/uploads.php';

/* Declared up here: a constant exists only once execution has passed it,
   and the actions below use these. */
/** What marks an attachment as a document of the Attachments column. */
const FABO_DS_FILE = 'file';

fabo_boot();

header('Content-Type: application/json; charset=utf-8');
/* Every answer here is about one department and must never be stored and
   handed to another. */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
header('Pragma: no-cache');
header('X-LiteSpeed-Cache-Control: no-cache');

/** A refusal, in the same shape as every other endpoint's. */
function fabo_ds_fail(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

/* Checked before the link: a file too big for the server arrives with no
   fields at all -- the token included -- and would otherwise be answered
   as a dead link. */
if (fabo_upload_body_lost()) {
    fabo_ds_fail(fabo_upload_too_big_words(), 413);
}

$dept = fabo_dept_open((string) ($_POST['t'] ?? $_GET['t'] ?? ''));
if (!$dept) {
    fabo_ds_fail('This link is no longer available. Reload the page.', 403);
}

$deptId = (int) $dept['id'];
$action = (string) ($_POST['action'] ?? '');
$cols   = fabo_dept_columns();

/* Every answer says which department it acted for, so the page can check it
   is the one it was drawn for and stop if not. Added once, here, by wrapping
   the output rather than by remembering it in each branch below. */
ob_start(static function (string $out) use ($deptId): string {
    $j = json_decode($out, true);
    if (!is_array($j)) {
        return $out;
    }
    $j['dept'] = $deptId;
    return (string) json_encode($j);
});

/* ------------------------------------------------------- who am I */

if ($action === 'whoami') {
    echo json_encode(['ok' => true, 'name' => (string) $dept['name'],
                      'hub' => (string) ($dept['hub_spoke'] ?? '')]);
    exit;
}

/* ------------------------------------------------------------- adding */

if ($action === 'add') {
    $id = fabo_dept_add_row($dept);
    echo json_encode(['ok' => true, 'row' => fabo_ds_row(fabo_dept_row($deptId, $id), $cols, $dept),
                      'message' => 'Row added.']);
    exit;
}

/* An event added already filled in, from the + Add row box: Upcoming offers
   the planning columns, Completed every column (lib/eventstages.php). All of
   it is checked before the row exists, so a refused value leaves no
   half-made row behind; then each cell goes through fabo_ds_save_cell(),
   the same check as typing into the sheet. */
if ($action === 'addfilled') {
    require_once __DIR__ . '/../lib/eventstages.php';
    $stage = (string) ($_POST['stage'] ?? '');
    $in    = json_decode((string) ($_POST['values'] ?? ''), true);
    $in    = is_array($in) ? $in : [];

    $want = [];
    foreach ($cols as $field => $meta) {
        if (!array_key_exists($field, $in)) {
            continue;
        }
        if ($stage === 'upcoming' && fabo_evstage_of($field) === 'result') {
            continue;                       // results wait for the event
        }
        $v = trim((string) $in[$field]);
        if ($v !== '') {
            $want[$field] = $v;
        }
    }
    try {
        $want = fabo_evstage_check_new($stage, $want, isset($cols['end_date']));
    } catch (InvalidArgumentException $e) {
        fabo_ds_fail($e->getMessage());
    }
    if (!$want) {
        fabo_ds_fail('Fill in at least the event’s name and dates.');
    }
    foreach ($want as $field => $v) {
        $why = fabo_list_refusal([$cols[$field]['label'], $field, $cols[$field]['type'],
                                  ['list' => $cols[$field]['list']]], $v, null);
        if ($why !== '') {
            fabo_ds_fail($why);
        }
    }

    /* Budget Required, filled in the same box: an amount per head, and the
       quotations behind each. Upcoming only -- a budget is asked for before
       an event. Every amount and head is checked here, before the row
       exists, so a mistake leaves nothing half-made behind. */
    require_once __DIR__ . '/../lib/budget.php';
    $bScope  = (int) ($dept['client_id'] ?? 0);
    $bAmount = [];
    $bFiles  = [];
    if ($stage === 'upcoming') {
        $bin = json_decode((string) ($_POST['budget'] ?? ''), true);
        foreach (is_array($bin) ? $bin : [] as $itemId => $amt) {
            $raw = str_replace([',', ' '], '', (string) $amt);
            if ($raw === '') {
                continue;
            }
            if (!is_numeric($raw) || (float) $raw < 0) {
                fabo_ds_fail('Budget: enter each amount in figures.');
            }
            $bAmount[(int) $itemId] = (float) $raw;
        }
        foreach ($_FILES as $name => $f) {
            if (preg_match('~^bfile_(\d+)$~', (string) $name, $m)) {
                $bFiles[(int) $m[1]] = $f;
            }
        }
        foreach (array_unique(array_merge(array_keys($bAmount), array_keys($bFiles))) as $itemId) {
            $it = fabo_budget_item((int) $itemId, $bScope);
            if (!$it || !(int) $it['active']) {
                fabo_ds_fail('Budget: one of those heads is no longer on the form. Reload the page.');
            }
        }
    }

    /* Who will send the results once the event is over (lib/evresults.php). */
    $resName  = trim((string) ($_POST['results_name'] ?? ''));
    $resEmail = trim((string) ($_POST['results_email'] ?? ''));
    if ($resEmail !== '' && !filter_var($resEmail, FILTER_VALIDATE_EMAIL)) {
        fabo_ds_fail('"' . $resEmail . '" is not an email address — it is where the results reminder goes.');
    }

    $id   = fabo_dept_add_row($dept);
    $warn = [];
    foreach ($want as $field => $v) {
        $why = fabo_ds_save_cell($deptId, $cols, $id, $field, $v);
        if ($why !== '') {
            $warn[] = $cols[$field]['label'] . ': ' . $why;
        }
    }
    $gave = false;
    foreach (array_keys($want) as $field) {
        $gave = $gave || fabo_evstage_of($field) === 'result';
    }
    if ($stage === 'upcoming' || $gave) {
        try {
            require_once __DIR__ . '/../lib/evresults.php';
            $rec = fabo_evres_ensure($id, null, $resName, $resEmail, $dept, $stage === 'upcoming');
            /* A Completed event added with its results already in: submitted
               with it, so it is not then chased for them. */
            $ctx = ($rec && $stage === 'completed') ? fabo_evres_context($rec) : null;
            $row = $ctx ? fabo_evres_ctx_row($ctx, $id) : null;
            if ($row) {
                fabo_evres_submit($rec, $ctx, $row, (string) ($dept['contact_name'] ?? '') ?: (string) $dept['name'], false);
            }
        } catch (Throwable $e) {
            error_log('FABO evres on dept add: ' . $e->getMessage());   // made later, by the sweep
        }
    }
    foreach ($bAmount as $itemId => $amt) {
        try {
            fabo_budget_set('events', $id, $itemId, $amt, (string) $dept['name'], $bScope);
        } catch (RuntimeException $e) {
            $warn[] = 'Budget: ' . fabo_user_error($e);
        }
    }
    foreach ($bFiles as $itemId => $f) {
        $names = is_array($f['name']) ? $f['name'] : [$f['name']];
        foreach (array_keys($names) as $i) {
            $one = is_array($f['name'])
                ? ['name' => $f['name'][$i], 'type' => $f['type'][$i], 'tmp_name' => $f['tmp_name'][$i],
                   'error' => $f['error'][$i], 'size' => $f['size'][$i]]
                : $f;
            if (($one['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $why = fabo_ds_budget_attach($dept, $id, $itemId, $one, $bScope);
            if ($why !== '') {
                $warn[] = 'Budget: ' . $one['name'] . ': ' . $why;
            }
        }
    }
    echo json_encode(['ok' => true, 'row' => fabo_ds_row(fabo_dept_row($deptId, $id), $cols, $dept),
                      'budget' => fabo_budget_totals('events', $id, $bScope),
                      'message' => 'Event added.', 'warn' => implode(' ', $warn)]);
    exit;
}

/* ------------------------------------------------------- the budget
   Budget Required on a department's sheet: the same budget form as the main
   sheet's (lib/budget.php) -- one box per head the admin allows, and the
   quotations behind each -- for an UPCOMING event only. A budget is asked
   for before an event, never after it.

   What the department enters stays a draft while the sheet is theirs. When
   they press Submit, every amount goes to the admin for review
   (fabo_dept_submit()), who approves or sends back each head from the
   Budget Required column on Programs Data / Upcoming Events -- the same
   place, the same buttons, as any other event's budget.

   A department never sees an allocation or a limit: fabo_budget_out() with
   $maySee false leaves them out of the answer altogether. */
if (in_array($action, ['budget', 'budget_set', 'budget_upload', 'budget_dropfile'], true)) {
    require_once __DIR__ . '/../lib/budget.php';
    require_once __DIR__ . '/../lib/eventstages.php';
    $rowId = (int) ($_POST['id'] ?? 0);
    $row   = fabo_dept_row($deptId, $rowId);
    if (!$row) {
        fabo_ds_fail('That row is not yours.', 403);
    }
    $scope = (int) ($dept['client_id'] ?? 0);
    $who   = (string) $dept['name'];
    $open  = !fabo_evstage_is_done($row);

    if ($action !== 'budget' && !$open) {
        fabo_ds_fail('The budget is entered for upcoming events only. This event\'s '
                   . 'dates have passed.');
    }

    try {
        if ($action === 'budget_set') {
            $raw = str_replace([',', ' '], '', (string) ($_POST['required'] ?? ''));
            if ($raw !== '' && !is_numeric($raw)) {
                fabo_ds_fail('Enter an amount in figures.');
            }
            fabo_budget_set('events', $rowId, (int) ($_POST['item_id'] ?? 0), (float) $raw,
                            $who, $scope, $_POST['note'] ?? null);
        } elseif ($action === 'budget_upload') {
            $file = $_FILES['file'] ?? null;
            if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                fabo_ds_fail('Choose a file to upload.');
            }
            $why = fabo_ds_budget_attach($dept, $rowId, (int) ($_POST['item_id'] ?? 0), $file, $scope);
            if ($why !== '') {
                fabo_ds_fail($why);
            }
        } elseif ($action === 'budget_dropfile') {
            /* Only a file this department put there, on a line of this row:
               until the sheet is handed in, the quotation is still theirs. */
            $a = fabo_one('SELECT a.* FROM attachments a
                             JOIN budget_lines l ON l.id = a.row_id
                            WHERE a.id = ? AND a.sheet_key = ? AND l.sheet_key = ? AND l.row_id = ?
                              AND a.department_id = ?',
                          'issii', [(int) ($_POST['file'] ?? 0), FABO_BUDGET_FILES, 'events',
                                    $rowId, $deptId]);
            if (!$a) {
                fabo_ds_fail('That document was not found.');
            }
            fabo_delete_stored((string) $a['stored_name']);
            fabo_exec('DELETE FROM attachments WHERE id = ?', 'i', [(int) $a['id']]);
        }
    } catch (RuntimeException $e) {
        fabo_ds_fail(fabo_user_error($e));
    }

    $p = fabo_budget_payload('events', $rowId, $scope, false);
    $p['open']     = $open;
    $p['editable'] = $open;
    /* Each line's documents, so the box lists them without another trip. */
    $byLine = [];
    foreach ($p['lines'] as $l) {
        if ((int) $l['line_id'] > 0) {
            $byLine[(int) $l['line_id']] = array_map(static fn($a) => [
                'id'   => (int) $a['id'],
                'name' => (string) $a['original_name'],
                'size' => fabo_human_size((int) $a['size_bytes']),
                'mine' => (int) ($a['department_id'] ?? 0) === $deptId,
            ], fabo_budget_files((int) $l['line_id']));
        }
    }
    $p['line_files'] = (object) $byLine;
    echo json_encode($p);
    exit;
}

/* ---------------------------------------------------------- handing in */

if ($action === 'submit') {
    /* Everything on the sheet goes to the organisers at once. The rows are
       already on the Events table; what changes is that they stop being
       drafts, so this cannot half-finish and lose somebody's month. */
    $n = fabo_dept_submit($deptId);
    if ($n === 0) {
        fabo_ds_fail('There is nothing to submit yet. Add a row first.');
    }
    echo json_encode(['ok' => true, 'submitted' => $n,
                      'message' => $n === 1
                          ? 'One row submitted. It is with the organisers now.'
                          : $n . ' rows submitted. They are with the organisers now.']);
    exit;
}

/* ------------------------------------------------------------- saving */

if ($action === 'save') {
    $why = fabo_ds_save_cell($deptId, $cols, (int) ($_POST['id'] ?? 0),
                             (string) ($_POST['field'] ?? ''), (string) ($_POST['value'] ?? ''));
    if ($why !== '') {
        fabo_ds_fail($why);
    }
    echo json_encode(['ok' => true, 'saved' => true]);
    exit;
}

/* Many cells in one request -- what Save all sends. One request instead of
   one per cell, so the host never sees a burst. Each cell succeeds or fails
   on its own: results maps "rowId|field" to true or the reason it failed. */
if ($action === 'saveall') {
    $cells = json_decode((string) ($_POST['cells'] ?? ''), true);
    if (!is_array($cells)) {
        fabo_ds_fail('Nothing to save.');
    }
    if (count($cells) > 500) {
        fabo_ds_fail('Too many changes at once — save in smaller parts.');
    }
    $results = [];
    foreach ($cells as $c) {
        if (!is_array($c)) {
            continue;
        }
        $rowId = (int) ($c['id'] ?? 0);
        $field = (string) ($c['field'] ?? '');
        $why   = fabo_ds_save_cell($deptId, $cols, $rowId, $field, (string) ($c['value'] ?? ''));
        $results[$rowId . '|' . $field] = $why === '' ? true : $why;
    }
    echo json_encode(['ok' => true, 'results' => $results]);
    exit;
}

/* ------------------------------------------------------------ removing */

if ($action === 'delete') {
    $rowId = (int) ($_POST['id'] ?? 0);
    if (!fabo_dept_row($deptId, $rowId)) {
        fabo_ds_fail('That row is not yours.', 403);
    }
    foreach (fabo_ds_images($rowId) as $img) {
        fabo_ds_drop((int) $img['id']);
    }
    /* Its budget goes with it -- the amounts and the quotations behind them.
       A budget for a row that no longer exists is one nobody can reach. */
    require_once __DIR__ . '/../lib/budget.php';
    foreach (fabo_all("SELECT id FROM budget_lines WHERE sheet_key = 'events' AND row_id = ?",
                      'i', [$rowId]) as $bl) {
        foreach (fabo_budget_files((int) $bl['id']) as $bf) {
            fabo_delete_stored((string) $bf['stored_name']);
            fabo_exec('DELETE FROM attachments WHERE id = ?', 'i', [(int) $bf['id']]);
        }
    }
    fabo_exec("DELETE FROM budget_lines WHERE sheet_key = 'events' AND row_id = ?", 'i', [$rowId]);
    fabo_exec('DELETE FROM `events` WHERE id = ? AND department_id = ?', 'ii', [$rowId, $deptId]);
    echo json_encode(['ok' => true, 'removed' => $rowId, 'message' => 'Row removed.']);
    exit;
}

/* ------------------------------------------------------------- images */

if ($action === 'upload') {
    $rowId = (int) ($_POST['id'] ?? 0);
    if (!fabo_dept_row($deptId, $rowId)) {
        fabo_ds_fail('That row is not yours.', 403);
    }
    fabo_upload_take_chunk('images', (string) $_POST['t'], fn (string $m) => fabo_ds_fail($m));
    $files = $_FILES['images'] ?? null;
    if (!$files || !isset($files['name'])) {
        fabo_ds_fail('No image was sent.');
    }
    // One or many: the field is images[] but a single file still arrives.
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
        // An image column takes images, of any kind — the bytes decide, not
        // the name, so a renamed file cannot slip through as a picture.
        if (!fabo_is_image((string) $stored['mime'])) {
            fabo_delete_stored((string) $stored['stored_name']);
            $errs[] = $one['name'] . ': that is not an image.';
            continue;
        }
        $aid = (int) fabo_exec('INSERT INTO attachments
                 (sheet_key, row_id, original_name, stored_name, mime, size_bytes,
                  caption, uploaded_by, department_id)
               VALUES (?,?,?,?,?,?,?,?,?)', 'sissssssi',
            ['events', $rowId, $stored['original_name'], $stored['stored_name'],
             $stored['mime'], (int) $stored['size'], '', (string) $dept['name'], $deptId]);
        $out[] = fabo_ds_image($aid, (string) $stored['original_name']);
    }
    if (!$out && $errs) {
        fabo_ds_fail(implode(' ', $errs));
    }
    echo json_encode(['ok' => true, 'images' => $out,
                      'message' => count($out) === 1 ? 'Image added.'
                                 : count($out) . ' images added.',
                      'warn' => $errs ? implode(' ', $errs) : '']);
    exit;
}

/* ---------------------------------------------------------- documents

   The Attachments column: any document the portal accepts -- PDF, Word,
   Excel, PowerPoint, CSV, text, ZIP -- not only photographs. Kept in the
   same attachments table as the images, marked "file" in its caption so the
   two columns never show each other's things; the organisers see both in
   the row's files on the Events sheet. */
if ($action === 'attach') {
    $rowId = (int) ($_POST['id'] ?? 0);
    if (!fabo_dept_row($deptId, $rowId)) {
        fabo_ds_fail('That row is not yours.', 403);
    }
    // Any size, any number: a big file comes in pieces, put together here.
    fabo_upload_take_chunk('files', (string) $_POST['t'], fn (string $m) => fabo_ds_fail($m));
    $files = $_FILES['files'] ?? null;
    if (!$files || !isset($files['name'])) {
        fabo_ds_fail('No file was sent.');
    }
    $names = is_array($files['name']) ? $files['name'] : [$files['name']];
    $out  = [];
    $errs = [];
    for ($i = 0; $i < count($names); $i++) {
        $one = is_array($files['name'])
            ? ['name' => $files['name'][$i], 'type' => $files['type'][$i],
               'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i],
               'size' => $files['size'][$i]]
            : $files;
        $err = (int) ($one['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if (in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            $errs[] = $one['name'] . ': bigger than this server accepts.';
            continue;
        }
        [$ok, $msg, $stored] = fabo_store_upload($one);
        if (!$ok || !$stored) {
            $errs[] = $one['name'] . ': ' . $msg;
            continue;
        }
        $aid = (int) fabo_exec('INSERT INTO attachments
                 (sheet_key, row_id, original_name, stored_name, mime, size_bytes,
                  caption, uploaded_by, department_id)
               VALUES (?,?,?,?,?,?,?,?,?)', 'sissssssi',
            ['events', $rowId, $stored['original_name'], $stored['stored_name'],
             $stored['mime'], (int) $stored['size'], FABO_DS_FILE, (string) $dept['name'], $deptId]);
        $out[] = fabo_ds_file(['id' => $aid, 'original_name' => $stored['original_name'],
                               'size_bytes' => (int) $stored['size']]);
    }
    if (!$out && $errs) {
        fabo_ds_fail(implode(' ', $errs));
    }
    echo json_encode(['ok' => true, 'files' => $out,
                      'message' => count($out) === 1 ? 'Document attached.'
                                 : count($out) . ' documents attached.',
                      'warn' => $errs ? implode(' ', $errs) : '']);
    exit;
}

if ($action === 'dropfile') {
    $rowId = (int) ($_POST['id'] ?? 0);
    $aid   = (int) ($_POST['file'] ?? 0);
    if (!fabo_dept_row($deptId, $rowId)) {
        fabo_ds_fail('That row is not yours.', 403);
    }
    $a = fabo_one("SELECT id FROM attachments
                   WHERE id = ? AND sheet_key = 'events' AND row_id = ?", 'ii', [$aid, $rowId]);
    if (!$a) {
        fabo_ds_fail('That document is not on this row.', 404);
    }
    fabo_ds_drop($aid);
    echo json_encode(['ok' => true, 'removed' => $aid, 'message' => 'Document removed.']);
    exit;
}

if ($action === 'dropimage') {
    $rowId = (int) ($_POST['id'] ?? 0);
    $aid   = (int) ($_POST['image'] ?? 0);
    if (!fabo_dept_row($deptId, $rowId)) {
        fabo_ds_fail('That row is not yours.', 403);
    }
    // The image has to be on that row, or an id alone would reach another.
    $a = fabo_one("SELECT id FROM attachments
                   WHERE id = ? AND sheet_key = 'events' AND row_id = ?", 'ii', [$aid, $rowId]);
    if (!$a) {
        fabo_ds_fail('That image is not on this row.', 404);
    }
    fabo_ds_drop($aid);
    echo json_encode(['ok' => true, 'removed' => $aid, 'message' => 'Image removed.']);
    exit;
}

fabo_ds_fail('Unknown action.');

/* --------------------------------------------------------------- bits */

/**
 * Save one cell on one of this department's rows. Returns '' when saved,
 * or the reason it was not, in words for the sheet.
 */
/**
 * Attach one quotation to one budget head of one row. '' when it worked,
 * otherwise the reason. A file hangs on a budget line, so attaching one
 * before the amount is decided opens the line at nothing -- as on the main
 * sheet.
 */
function fabo_ds_budget_attach(array $dept, int $rowId, int $itemId, array $file, int $scope): string
{
    require_once __DIR__ . '/../lib/budget.php';
    $who  = (string) $dept['name'];
    $find = static fn() => fabo_one('SELECT * FROM budget_lines
                                      WHERE sheet_key = ? AND row_id = ? AND item_id = ?',
                                    'sii', ['events', $rowId, $itemId]);
    $line = $find();
    if (!$line) {
        try {
            fabo_budget_set('events', $rowId, $itemId, 0.0, $who, $scope);
        } catch (RuntimeException $e) {
            return fabo_user_error($e);
        }
        $line = $find();
    }
    [$ok, $msg, $meta] = fabo_store_upload($file);
    if (!$ok) {
        return $msg;
    }
    fabo_exec('INSERT INTO attachments (sheet_key, row_id, original_name, stored_name,
                   mime, size_bytes, caption, uploaded_by, department_id)
               VALUES (?,?,?,?,?,?,?,?,?)', 'sisssissi',
              [FABO_BUDGET_FILES, (int) $line['id'], $meta['original_name'],
               $meta['stored_name'], $meta['mime'], (int) $meta['size'], '', $who, (int) $dept['id']]);
    return '';
}

function fabo_ds_save_cell(int $deptId, array $cols, int $rowId, string $field, string $v): string
{
    if (!isset($cols[$field])) {
        return 'That column is not on this sheet.';
    }
    $row = fabo_dept_row($deptId, $rowId);
    if (!$row) {
        return 'That row is not yours.';
    }
    if ($v === '__add__' || $v === '__manage__') {
        return 'That is the add-option marker, not a value.';
    }
    $why = fabo_list_refusal([$cols[$field]['label'], $field, $cols[$field]['type'],
                              ['list' => $cols[$field]['list']]], $v, $row[$field] ?? null);
    if ($why !== '') {
        return $why;
    }
    fabo_exec('UPDATE `events` SET `' . $field . '` = ? WHERE id = ? AND department_id = ?',
              'sii', [fabo_coerce($cols[$field]['type'], $v), $rowId, $deptId]);
    if ($field === 'status') {
        require_once __DIR__ . '/../lib/approvals.php';
        fabo_signoff_on_complete('events', $rowId);   // completed means approved
    }
    return '';
}

/** A row's documents: marked as such, or anything that is not an image. */
function fabo_ds_files(int $rowId): array
{
    return array_values(array_filter(fabo_ds_images($rowId), 'fabo_ds_is_file'));
}

function fabo_ds_is_file(array $a): bool
{
    return (string) ($a['caption'] ?? '') === FABO_DS_FILE || !fabo_is_image((string) $a['mime']);
}

function fabo_ds_file(array $a): array
{
    $name = (string) $a['original_name'];
    return ['id' => (int) $a['id'], 'name' => $name,
            'ext' => strtolower(pathinfo($name, PATHINFO_EXTENSION)),
            'size' => fabo_human_size((int) ($a['size_bytes'] ?? 0))];
}

/** Every image on one row, oldest first. */
function fabo_ds_images(int $rowId): array
{
    return fabo_all("SELECT * FROM attachments
                     WHERE sheet_key = 'events' AND row_id = ?
                     ORDER BY id", 'i', [$rowId]);
}

function fabo_ds_image(int $id, string $name): array
{
    return ['id' => $id, 'name' => $name];
}

function fabo_ds_drop(int $attachmentId): void
{
    $a = fabo_one('SELECT stored_name FROM attachments WHERE id = ?', 'i', [$attachmentId]);
    if ($a) {
        fabo_delete_stored((string) $a['stored_name']);
        fabo_exec('DELETE FROM attachments WHERE id = ?', 'i', [$attachmentId]);
    }
}

/** One row as the sheet's script wants it. */
function fabo_ds_row(?array $r, array $cols, array $dept): ?array
{
    if (!$r) {
        return null;
    }
    $vals = [];
    foreach ($cols as $field => $meta) {
        $vals[$field] = (string) ($r[$field] ?? '');
    }
    $imgs  = [];
    $files = [];
    foreach (fabo_ds_images((int) $r['id']) as $a) {
        if (fabo_ds_is_file($a)) {
            $files[] = fabo_ds_file($a);
        } else {
            $imgs[] = ['id' => (int) $a['id'], 'name' => (string) $a['original_name']];
        }
    }
    return ['id' => (int) $r['id'], 'ref' => (string) ($r['event_id'] ?? ''),
            'values' => $vals, 'images' => $imgs, 'files' => $files];
}

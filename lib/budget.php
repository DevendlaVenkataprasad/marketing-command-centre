<?php
/**
 * Event budgets: what the admin allows, and what the team asks for.
 *
 * TWO SIDES, AND A WALL BETWEEN THEM
 *
 * The admin keeps a catalogue of budget heads — Food, Travel, Venue and so on
 * — and against each one writes two numbers: what is allocated to it, and how
 * far over that a request may still go (a percentage). That is Sheet
 * Management, and it is the same per-organisation desk the dropdowns and
 * requisition types already live on, so a client runs their own catalogue and
 * never sees ours or another client's.
 *
 * A team member opens the Budget Required cell on an event and gets the same
 * heads, with one box each: how much they need. They cannot add a head,
 * rename one, reorder them or touch an allocation.
 *
 * AND THEY DO NOT SEE THE ALLOCATION AT ALL.
 *
 * That is the point of fabo_budget_may_see_allocated(), and it is enforced
 * where it has to be — in what the server sends, not in what the page draws.
 * A team member's JSON carries the head, their own figure and a verdict; the
 * allocation, the threshold and the computed ceiling are simply not in it.
 * Hiding them in CSS or leaving them out of the markup would put the numbers
 * one View Source away, which is not hiding them.
 *
 * The verdict is worked out here, on the server, for the same reason: the
 * browser is told green or red, never the number that decided it.
 *
 * WHY THE LINE KEEPS ITS OWN COPY OF THE NUMBERS
 *
 * Each saved line stores the head's name, allocation and threshold as they
 * stood when it was saved. Rename Food to Catering next month, or cut its
 * allocation, and what was already asked for still reads as it was asked and
 * still shows the verdict it was given. A budget request is a record of a
 * decision, and a record that rewrites itself is not one.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/manage.php';
require_once __DIR__ . '/lists.php';

/** Where a budget line's files are filed in the shared attachments table. */
const FABO_BUDGET_FILES = 'budget';

/**
 * What a budget document is, kept on attachments.doc_kind.
 *
 * Before the event the papers behind a head are quotations -- what it will
 * cost. After it they are invoices -- what it did cost. The committee
 * verifies the first on Upcoming Events and reviews the second on Completed
 * Events, so the two are kept apart rather than read as one pile. A file
 * filed before the kinds existed (doc_kind empty) was a quotation.
 */
function fabo_budget_doc_kinds(): array
{
    return ['quotation' => 'Quotation', 'invoice' => 'Invoice'];
}

/** Which kind a stored doc_kind is, an empty one read as a quotation. */
function fabo_budget_doc_kind(?string $k): string
{
    return $k === 'invoice' ? 'invoice' : 'quotation';
}

/**
 * Whose approval a budget line carries -- the Approved By dropdown on the
 * budget form. Recorded by the budget owner beside their decision.
 */
function fabo_budget_approvers(): array
{
    return ['CEO Approved', 'Committee Approved'];
}

/**
 * Upcoming or completed, for the budget form of one row.
 *
 * The same test that splits Upcoming Events from Completed Events
 * (fabo_evstage_is_done()), so the form says Quotation on exactly the rows
 * the Upcoming tab shows and Invoices on exactly the rows Completed shows.
 * A sheet that is not Events has no such split and reads as upcoming.
 */
function fabo_budget_stage(string $sheetKey, int $rowId): string
{
    if ($sheetKey !== 'events') {
        return 'upcoming';
    }
    require_once __DIR__ . '/eventstages.php';
    $row = fabo_one('SELECT start_date, end_date FROM `events` WHERE id = ?', 'i', [$rowId]);
    return $row && fabo_evstage_is_done($row) ? 'completed' : 'upcoming';
}

/** What the form's documents column is headed, for a stage. */
function fabo_budget_doc_label(string $stage): string
{
    return $stage === 'completed' ? 'Documents / Invoices' : 'Documents / Quotation';
}

/**
 * What a portal starts with.
 *
 * Heads, not amounts: the allocations are nought until somebody sets them,
 * because a made-up allocation would be worse than none — it would give
 * verdicts. The threshold starts at 10%, which is a gentle default and easily
 * changed.
 */
function fabo_budget_defaults(): array
{
    return [
        'Food & Catering',
        'Travel',
        'Accommodation',
        'Venue',
        'Marketing & Promotion',
        'Transportation',
        'Printing & Branding',
        'Speaker / Guest Fees',
        'Equipment & Rentals',
        'Miscellaneous',
    ];
}

/** Seeds a scope's catalogue the first time it is asked for. */
function fabo_budget_install(int $scope = 0): void
{
    if (fabo_one('SELECT id FROM budget_items WHERE client_id = ? LIMIT 1', 'i', [$scope])) {
        return;
    }
    $i = 0;
    foreach (fabo_budget_defaults() as $name) {
        fabo_exec('INSERT IGNORE INTO budget_items (client_id, name, allocated,
                       threshold_pct, sort_order, active)
                   VALUES (?,?,0,10,?,1)', 'isi', [$scope, $name, ++$i * 10]);
    }
}

/**
 * Give an organisation its own copy of the shared catalogue.
 *
 * The same trick fabo_req_types_adopt() plays, and for the same reason: until
 * a client changes something they should see ours, and the moment they do they
 * need their own rows to change.
 */
function fabo_budget_adopt(int $owner): void
{
    if ($owner <= 0) {
        return;
    }
    if (fabo_one('SELECT id FROM budget_items WHERE client_id = ? LIMIT 1', 'i', [$owner])) {
        return;
    }
    fabo_budget_install(0);
    foreach (fabo_all('SELECT name, allocated, threshold_pct, sort_order, active
                       FROM budget_items WHERE client_id = 0 ORDER BY sort_order, id') as $r) {
        fabo_exec('INSERT INTO budget_items (client_id, name, allocated, threshold_pct,
                       sort_order, active) VALUES (?,?,?,?,?,?)', 'isddii',
                  [$owner, $r['name'], (float) $r['allocated'], (float) $r['threshold_pct'],
                   (int) $r['sort_order'], (int) $r['active']]);
    }
}

/**
 * The catalogue for a scope, in order.
 *
 * A scope with nothing of its own reads the shared set, so a new client sees
 * sensible heads on day one without anybody copying them across.
 */
function fabo_budget_items(int $scope = 0, bool $activeOnly = false): array
{
    fabo_budget_install(0);
    $use = $scope;
    if ($scope > 0 && !fabo_one('SELECT id FROM budget_items WHERE client_id = ? LIMIT 1',
                                'i', [$scope])) {
        $use = 0;                                   // not customised: ours
    }
    $sql = 'SELECT * FROM budget_items WHERE client_id = ?'
         . ($activeOnly ? ' AND active = 1' : '')
         . ' ORDER BY sort_order, id';
    return fabo_all($sql, 'i', [$use]);
}

/** One head, within its scope — never another's. */
function fabo_budget_item(int $id, int $scope = 0): ?array
{
    $r = fabo_one('SELECT * FROM budget_items WHERE id = ?', 'i', [$id]);
    if (!$r) {
        return null;
    }
    $owns = (int) $r['client_id'];
    return ($owns === $scope || $owns === 0) ? $r : null;
}

/* ------------------------------------------------- the admin's catalogue */

function fabo_budget_add(string $name, float $allocated, float $pct, int $scope = 0): void
{
    $name = trim(mb_substr($name, 0, 120));
    if ($name === '') {
        throw new RuntimeException('Give the budget head a name.');
    }
    if (fabo_one('SELECT id FROM budget_items WHERE client_id = ? AND name = ?',
                 'is', [$scope, $name])) {
        throw new RuntimeException('There is already a head called that.');
    }
    if ($scope > 0) {
        fabo_budget_adopt($scope);                  // own it before adding to it
    }
    $next = (int) (fabo_one('SELECT COALESCE(MAX(sort_order),0) + 10 n
                             FROM budget_items WHERE client_id = ?', 'i', [$scope])['n'] ?? 10);
    fabo_exec('INSERT INTO budget_items (client_id, name, allocated, threshold_pct,
                   sort_order, active) VALUES (?,?,?,?,?,1)', 'isddi',
              [$scope, $name, max(0, $allocated), max(0, $pct), $next]);
}

function fabo_budget_save(int $id, string $name, float $allocated, float $pct,
                          bool $active, int $scope = 0): void
{
    if ($scope > 0) {
        fabo_budget_adopt($scope);
    }
    $it = fabo_budget_item($id, $scope);
    if (!$it) {
        throw new RuntimeException('That budget head is not yours to change.');
    }
    /* Editing a shared head from inside a client's desk must not rewrite ours
       for everybody. Adopting above gives them their own row; find it by name
       and change that one instead. */
    if ((int) $it['client_id'] !== $scope) {
        $mine = fabo_one('SELECT id FROM budget_items WHERE client_id = ? AND name = ?',
                         'is', [$scope, $it['name']]);
        if (!$mine) {
            throw new RuntimeException('That budget head is not yours to change.');
        }
        $id = (int) $mine['id'];
    }
    $name = trim(mb_substr($name, 0, 120));
    if ($name === '') {
        throw new RuntimeException('Give the budget head a name.');
    }
    fabo_exec('UPDATE budget_items SET name = ?, allocated = ?, threshold_pct = ?,
                   active = ? WHERE id = ? AND client_id = ?', 'sddiii',
              [$name, max(0, $allocated), max(0, $pct), $active ? 1 : 0, $id, $scope]);
}

function fabo_budget_delete(int $id, int $scope = 0): void
{
    if ($scope > 0) {
        fabo_budget_adopt($scope);
    }
    $it = fabo_budget_item($id, $scope);
    if (!$it || (int) $it['client_id'] !== $scope) {
        throw new RuntimeException('That budget head is not yours to delete.');
    }
    /* Lines already asked for keep their own copy of everything, so deleting
       a head removes it from the form and leaves the history standing. */
    fabo_exec('DELETE FROM budget_items WHERE id = ? AND client_id = ?', 'ii', [$id, $scope]);
}

function fabo_budget_move(int $id, string $dir, int $scope = 0): void
{
    if ($scope > 0) {
        fabo_budget_adopt($scope);
    }
    $rows = fabo_budget_items($scope);
    $ids  = array_column($rows, 'id');
    $at   = array_search($id, array_map('intval', $ids), true);
    if ($at === false) {
        return;
    }
    $to = $dir === 'up' ? $at - 1 : $at + 1;
    if ($to < 0 || $to >= count($rows)) {
        return;
    }
    $a = $rows[$at];
    $b = $rows[$to];
    fabo_exec('UPDATE budget_items SET sort_order = ? WHERE id = ?', 'ii',
              [(int) $b['sort_order'], (int) $a['id']]);
    fabo_exec('UPDATE budget_items SET sort_order = ? WHERE id = ?', 'ii',
              [(int) $a['sort_order'], (int) $b['id']]);
}

/* ------------------------------------------------------------ the verdict */

/**
 * Is this request within what the head allows?
 *
 * The ceiling is the allocation plus the threshold percentage of it, which is
 * what "Budget Allocated + the defined percentage limit" means: 1,00,000 with
 * 10% allows 1,10,000.
 *
 * An allocation of nought is the one case worth care. It means nobody has set
 * this head yet, not that nothing may be spent, so a request against it is
 * reported as unset rather than as a breach — telling somebody they are over
 * a limit that was never decided would be a lie.
 */
function fabo_budget_verdict(float $required, float $allocated, float $pct,
                             string $decision = 'none', string $remarks = ''): array
{
    $ceiling = $allocated + ($allocated * $pct / 100);
    $over    = max(0.0, $required - $ceiling);
    $within  = $allocated > 0 && $required <= $ceiling + 0.004;  // a paisa is not a breach

    if ($required <= 0) {
        return ['state' => 'none', 'ceiling' => $ceiling, 'over' => 0.0,
                'message' => '', 'remarks' => ''];
    }

    /* The decision the line carries outranks the arithmetic, because it is a
       record of what somebody actually said about this figure. The arithmetic
       only decides what happens to a line nobody has answered yet. */
    if ($decision === 'approved') {
        return ['state' => 'approved', 'ceiling' => $ceiling, 'over' => $over,
                'message' => $within
                    ? 'Within the allowed limit, and approved.'
                    : 'Over the limit, approved by the budget owner.',
                'remarks' => $remarks];
    }
    if ($decision === 'rejected') {
        return ['state' => 'rejected', 'ceiling' => $ceiling, 'over' => $over,
                'message' => 'Not approved. Change the amount and submit it again.',
                'remarks' => $remarks];
    }
    if ($decision === 'pending') {
        /* Within its limit and still pending means it came from a
           department's live sheet, where every amount waits for review. */
        return ['state' => 'pending', 'ceiling' => $ceiling, 'over' => $over,
                'message' => $within || $allocated <= 0
                    ? 'Waiting for the budget owner to review.'
                    : 'Over the allowed limit. With the budget owner.',
                'remarks' => $remarks];
    }

    /* Nobody has been asked yet. */
    if ($allocated <= 0) {
        return ['state' => 'unset', 'ceiling' => 0.0, 'over' => 0.0,
                'message' => 'No allocation has been set for this head yet.',
                'remarks' => ''];
    }
    return ['state' => 'draft', 'ceiling' => $ceiling, 'over' => $over,
            'message' => $within
                ? 'Within the allowed limit. Submit it to have it counted.'
                : 'Over the allowed limit. Submitting sends it to the budget '
                . 'owner for approval.',
            'remarks' => ''];
}

/** Would this figure need somebody's approval, or does it simply tally? */
function fabo_budget_needs_approval(float $required, float $allocated, float $pct): bool
{
    return $required > 0 && $allocated > 0
        && $required > $allocated + ($allocated * $pct / 100) + 0.004;
}

/**
 * Whether a state counts as settled and allowed.
 *
 * "Approved" and "within the limit" are the same thing to everything that
 * totals or colours, which is what the owner approving it MEANS — the extra
 * now sits under the allocated budget rather than beside it.
 */
function fabo_budget_is_allowed(string $state): bool
{
    return $state === 'approved';
}

/** The tone each state reads as. One place, so cell and drawer never differ. */
function fabo_budget_state_tone(string $state): string
{
    switch ($state) {
        case 'approved': return 'good';
        case 'pending':  return 'warn';
        case 'rejected': return 'risk';
        case 'unset':    return 'warn';
        case 'draft':    return 'muted';
        default:         return '';
    }
}

/** What each state is called on screen. */
function fabo_budget_state_word(string $state): string
{
    switch ($state) {
        case 'approved': return 'Approved';
        case 'pending':  return 'Waiting for approval';
        case 'rejected': return 'Sent back';
        case 'unset':    return 'No allocation set';
        case 'draft':    return 'Not submitted';
        default:         return '';
    }
}

/**
 * May this viewer be shown the allocations and thresholds?
 *
 * The same right that opens Sheet Management, because that is where the
 * numbers are set — so whoever may set them may read them, and nobody else.
 * fabo_may_manage() already refuses every team member outright, which is the
 * rule asked for: the owner's assigned amounts are not the team's business.
 */
function fabo_budget_may_see_allocated(?array $viewer): bool
{
    return fabo_may_manage($viewer, 'sheets');
}

/* --------------------------------------------------------------- the lines */

/**
 * What one event's budget looks like: every head, with anything asked
 * against it.
 *
 * Heads come from the catalogue and figures from the saved lines, joined in
 * PHP rather than SQL because the two live in different scopes and a head with
 * nothing against it still has to appear — that is the form.
 *
 * Saved lines whose head has since been deleted are appended at the end, so a
 * request already made never silently disappears from the event it was made
 * against.
 */
function fabo_budget_lines(string $sheetKey, int $rowId, int $scope = 0): array
{
    $saved = [];
    foreach (fabo_all('SELECT * FROM budget_lines WHERE sheet_key = ? AND row_id = ?',
                      'si', [$sheetKey, $rowId]) as $r) {
        $saved[(int) $r['item_id']] = $r;
    }

    $files = fabo_budget_file_counts(array_map('intval', array_column($saved, 'id')));
    $kinds = fabo_budget_file_kinds(array_map('intval', array_column($saved, 'id')));
    $out   = [];

    foreach (fabo_budget_items($scope, true) as $it) {
        $id  = (int) $it['id'];
        $ln  = $saved[$id] ?? null;
        $out[] = fabo_budget_line_shape($it, $ln, $files, false, $kinds);
        unset($saved[$id]);
    }
    foreach ($saved as $ln) {                        // head gone, request stands
        $out[] = fabo_budget_line_shape(null, $ln, $files, true, $kinds);
    }
    return $out;
}

/** One line, whether it has been saved against yet or not. */
function fabo_budget_line_shape(?array $item, ?array $line, array $files,
                                bool $retired = false, ?array $kinds = null): array
{
    /* The line's own copy wins where it has one: that is what was decided.
       A head never asked against reads today's numbers, because nothing has
       been decided about it yet. */
    $allocated = $line !== null ? (float) $line['allocated']     : (float) ($item['allocated'] ?? 0);
    $pct       = $line !== null ? (float) $line['threshold_pct'] : (float) ($item['threshold_pct'] ?? 0);
    $required  = $line !== null ? (float) $line['required']      : 0.0;
    $lineId    = $line !== null ? (int) $line['id'] : 0;
    if ($kinds === null) {
        $kinds = fabo_budget_file_kinds([$lineId]);
    }

    return [
        'item_id'   => (int) ($item['id'] ?? ($line['item_id'] ?? 0)),
        'line_id'   => $lineId,
        'name'      => (string) ($item['name'] ?? ($line['item_name'] ?? 'Budget head')),
        'allocated' => $allocated,
        'pct'       => $pct,
        'required'  => $required,
        'note'      => (string) ($line['note'] ?? ''),
        'files'     => (int) ($files[$lineId] ?? 0),
        'quotes'    => (int) ($kinds[$lineId]['quotation'] ?? 0),
        'invoices'  => (int) ($kinds[$lineId]['invoice'] ?? 0),
        'approved_by' => (string) ($line['approved_by'] ?? ''),
        'retired'   => $retired,
        'by'        => (string) ($line['updated_by'] ?? ''),
        'at'        => (string) ($line['updated_at'] ?? ''),
        'decision'  => (string) ($line['decision'] ?? 'none'),
        'decided_by'=> (string) ($line['decided_by'] ?? ''),
        'decided_at'=> (string) ($line['decided_at'] ?? ''),
        'verdict'   => fabo_budget_verdict($required, $allocated, $pct,
                                           (string) ($line['decision'] ?? 'none'),
                                           (string) ($line['remarks'] ?? '')),
    ];
}

/**
 * Save what somebody is asking for against one head.
 *
 * The allocation and threshold are read from the catalogue HERE and written
 * onto the line. They are never accepted from the request: a team member's
 * browser has no business sending a number it is not allowed to see, and one
 * that did would be setting its own limit.
 */
function fabo_budget_set(string $sheetKey, int $rowId, int $itemId, float $required,
                         string $who, int $scope = 0, ?string $note = null): array
{
    $it = fabo_budget_item($itemId, $scope);
    if (!$it || !(int) $it['active']) {
        throw new RuntimeException('That budget head is not on this form.');
    }
    $required = max(0.0, round($required, 2));
    if ($required > 999999999999.0) {
        throw new RuntimeException('That amount is too large.');
    }
    $allocated = (float) $it['allocated'];
    $pct       = (float) $it['threshold_pct'];
    $note      = $note === null ? null : trim(mb_substr($note, 0, 255));
    $ceiling   = $allocated + ($allocated * $pct / 100);

    $before = fabo_one('SELECT * FROM budget_lines
                        WHERE sheet_key = ? AND row_id = ? AND item_id = ?',
                       'sii', [$sheetKey, $rowId, $itemId]);

    /*
     * What this amount does to any decision already taken.
     *
     * Typing a figure asks nobody anything: it leaves a DRAFT, and the team
     * submits the form when they are ready. Otherwise filling ten heads down
     * the page would ping the owner ten times on half-finished numbers.
     *
     * An answer already given is an answer ABOUT AN AMOUNT, so changing the
     * amount voids it and the line returns to draft - which is exactly the
     * loop a rejection is for: read the reason, correct the figure, submit it
     * again. Leaving it approved would let fifty thousand be signed off and
     * then quietly edited to five hundred thousand under the same green tick.
     */
    $same = $before !== null
         && abs((float) $before['required'] - $required) < 0.005
         && abs((float) $before['allocated'] - $allocated) < 0.005
         && abs((float) $before['threshold_pct'] - $pct) < 0.005;

    if ($required <= 0) {
        $decision    = 'none';                    // emptied: nothing to ask
        $keepRemarks = false;
    } elseif ($same) {
        $decision    = (string) ($before['decision'] ?? 'draft');
        $decision    = $decision === 'none' ? 'draft' : $decision;
        $keepRemarks = true;                      // unchanged: the answer stands
    } else {
        $decision    = 'draft';                   // a new figure: nobody has seen it
        $keepRemarks = false;
    }

    fabo_exec('INSERT INTO budget_lines (sheet_key, row_id, item_id, item_name, allocated,
                   threshold_pct, required, note, decision, updated_by)
               VALUES (?,?,?,?,?,?,?,?,?,?)
               ON DUPLICATE KEY UPDATE
                   item_name = VALUES(item_name), allocated = VALUES(allocated),
                   threshold_pct = VALUES(threshold_pct), required = VALUES(required),
                   note = COALESCE(VALUES(note), note), decision = VALUES(decision),
                   updated_by = VALUES(updated_by)',
              'siisdddsss',
              [$sheetKey, $rowId, $itemId, (string) $it['name'], $allocated, $pct,
               $required, $note, $decision, mb_substr($who, 0, 160)]);

    if (!$keepRemarks) {
        /* Approved By goes with the answer it described. */
        fabo_exec('UPDATE budget_lines SET remarks = NULL, decided_by = NULL, decided_at = NULL,
                       approved_by = NULL
                   WHERE sheet_key = ? AND row_id = ? AND item_id = ?',
                  'sii', [$sheetKey, $rowId, $itemId]);
    }

    /* Editing can void an approval, so the event's figure has to follow. */
    fabo_budget_sync_allocated($sheetKey, $rowId);

    $line = fabo_one('SELECT * FROM budget_lines WHERE sheet_key = ? AND row_id = ? AND item_id = ?',
                     'sii', [$sheetKey, $rowId, $itemId]);
    $files = fabo_budget_file_counts([(int) ($line['id'] ?? 0)]);
    return fabo_budget_line_shape($it, $line, $files);
}


/**
 * The team's one act: put this event's budget in for approval.
 *
 * Everything still in draft goes forward at once. What each line becomes is
 * decided by the numbers alone:
 *
 *   inside its limit  -> approved outright. It tallies, so there is nothing
 *                        for a person to decide, and making the owner
 *                        rubber-stamp it would bury the ones that matter.
 *   over its limit    -> pending, for the owner to answer.
 *
 * Returns [submitted, waiting] - how many went, and how many of those need a
 * human. The caller tells the owner only when the second is more than nought.
 */
function fabo_budget_submit(string $sheetKey, int $rowId, string $who, int $scope = 0): array
{
    $lines = fabo_all('SELECT * FROM budget_lines
                       WHERE sheet_key = ? AND row_id = ? AND decision = "draft"',
                      'si', [$sheetKey, $rowId]);

    $sent = 0; $waiting = 0;
    foreach ($lines as $l) {
        $req = (float) $l['required'];
        if ($req <= 0) {
            continue;                              // nothing asked on this head
        }
        $needs = fabo_budget_needs_approval($req, (float) $l['allocated'],
                                            (float) $l['threshold_pct']);
        /* An unallocated head cannot be judged, so it waits for a person
           rather than being waved through on a limit nobody has set. */
        if ((float) $l['allocated'] <= 0) {
            $needs = true;
        }
        if ($needs) {
            fabo_exec('UPDATE budget_lines SET decision = "pending", remarks = NULL,
                           decided_by = NULL, decided_at = NULL WHERE id = ?',
                      'i', [(int) $l['id']]);
            $waiting++;
        } else {
            fabo_exec('UPDATE budget_lines SET decision = "approved", remarks = NULL,
                           decided_by = ?, decided_at = NOW() WHERE id = ?',
                      'si', ['Within the allowed limit', (int) $l['id']]);
        }
        $sent++;
    }
    fabo_budget_sync_allocated($sheetKey, $rowId);
    return [$sent, $waiting];
}

/**
 * Write the event's Budget Allocated from what has actually been approved.
 *
 * The column used to be typed. It is now the sum of the approved lines and
 * nothing else, so "allocated" means what somebody agreed to rather than what
 * somebody hoped for - and it moves on its own the moment a decision does.
 *
 * Only sheets that have both a budget form and a budget_allocated column are
 * touched, and the write is skipped where the column is not really there.
 */
function fabo_budget_sync_allocated(string $sheetKey, int $rowId): float
{
    $sheet = fabo_sheet($sheetKey);
    $table = (string) ($sheet['table'] ?? '');
    if ($table === '') {
        return 0.0;
    }
    $has = false;
    foreach ($sheet['columns'] ?? [] as $c) {
        if ($c[1] === 'budget_allocated' && $c[2] !== 'computed') { $has = true; break; }
    }
    $total = (float) (fabo_one('SELECT COALESCE(SUM(required),0) t FROM budget_lines
                                WHERE sheet_key = ? AND row_id = ? AND decision = "approved"',
                               'si', [$sheetKey, $rowId])['t'] ?? 0);
    if (!$has) {
        return $total;
    }
    try {
        fabo_exec('UPDATE `' . $table . '` SET `budget_allocated` = ? WHERE id = ?',
                  'di', [$total, $rowId]);
    } catch (Throwable $e) {
        // No such column on this table after all; the figure still stands.
    }
    return $total;
}

/** Does this sheet keep its Budget Allocated from the budget form? */
function fabo_budget_drives_allocated(array $sheet): bool
{
    $budget = false; $alloc = false;
    foreach ($sheet['columns'] ?? [] as $c) {
        if (($c[3]['calc'] ?? '') === 'budget')                 { $budget = true; }
        if ($c[1] === 'budget_allocated' && $c[2] !== 'computed') { $alloc = true; }
    }
    return $budget && $alloc;
}

/**
 * The owner's answer to a request that went over its limit.
 *
 * Only ever reached by somebody fabo_budget_may_see_allocated() lets through.
 * The endpoint checks that, because deciding a budget is the same authority as
 * setting one.
 *
 * A rejection must carry a justification, and that is not politeness: the
 * person who asked cannot see the allocation or the limit, so "no" on its own
 * tells them nothing they can act on. The remark is the whole of what they
 * learn, and it is what they will read against the red.
 */
function fabo_budget_decide(string $sheetKey, int $rowId, int $lineId, string $decision,
                            string $remarks, string $who): array
{
    if (!in_array($decision, ['approved', 'rejected'], true)) {
        throw new RuntimeException('That is not a decision.');
    }
    $line = fabo_budget_line($lineId, $sheetKey, $rowId);
    if (!$line) {
        throw new RuntimeException('That budget line was not found.');
    }

    $remarks = trim(mb_substr($remarks, 0, 500));
    if ($decision === 'rejected' && $remarks === '') {
        throw new RuntimeException('Say why it is not approved - the team sees '
                                 . 'this instead of the amount.');
    }

    /* Only a request actually over its limit can be decided. Anything else is
       already settled, and a decision stamped on it would be meaningless - or
       worse, would read as though the limit had been waived. */
    $state = fabo_budget_verdict((float) $line['required'], (float) $line['allocated'],
                                 (float) $line['threshold_pct'],
                                 (string) $line['decision'])['state'];
    if (!in_array($state, ['pending', 'approved', 'rejected'], true)) {
        throw new RuntimeException('That request is within its limit, '
                                 . 'so there is nothing to approve.');
    }

    fabo_exec('UPDATE budget_lines SET decision = ?, remarks = ?, decided_by = ?,
                   decided_at = NOW() WHERE id = ?', 'sssi',
              [$decision, $remarks !== '' ? $remarks : null,
               mb_substr($who, 0, 160), $lineId]);
    if ($decision === 'rejected') {
        // Nobody approved it, so it cannot carry an Approved By.
        fabo_exec('UPDATE budget_lines SET approved_by = NULL WHERE id = ?', 'i', [$lineId]);
    }

    fabo_budget_sync_allocated($sheetKey, $rowId);   // approved is what counts

    $fresh = fabo_budget_line($lineId, $sheetKey, $rowId);
    $item  = fabo_one('SELECT * FROM budget_items WHERE id = ?', 'i', [(int) $line['item_id']]);
    return fabo_budget_line_shape($item ?: null, $fresh, fabo_budget_file_counts([$lineId]));
}

/**
 * Record whose approval a line carries: CEO or Committee, or '' to clear it.
 *
 * Only for a line with an amount on it -- an empty head has nothing to have
 * been approved. Reached only by the budget owner; the endpoint checks.
 */
function fabo_budget_set_approver(string $sheetKey, int $rowId, int $lineId,
                                  string $value): array
{
    $line = fabo_budget_line($lineId, $sheetKey, $rowId);
    if (!$line) {
        throw new RuntimeException('That budget line was not found.');
    }
    $value = trim($value);
    if ($value !== '' && !in_array($value, fabo_budget_approvers(), true)) {
        throw new RuntimeException('Choose CEO Approved or Committee Approved.');
    }
    if ($value !== '' && (float) $line['required'] <= 0) {
        throw new RuntimeException('Enter an amount against this head first.');
    }
    fabo_exec('UPDATE budget_lines SET approved_by = ? WHERE id = ?', 'si',
              [$value !== '' ? $value : null, $lineId]);

    $fresh = fabo_budget_line($lineId, $sheetKey, $rowId);
    $item  = fabo_one('SELECT * FROM budget_items WHERE id = ?', 'i', [(int) $line['item_id']]);
    return fabo_budget_line_shape($item ?: null, $fresh, fabo_budget_file_counts([$lineId]));
}

/** The requests on one row still sitting with the owner. */
function fabo_budget_waiting(string $sheetKey, int $rowId, int $scope = 0): array
{
    return array_values(array_filter(fabo_budget_lines($sheetKey, $rowId, $scope),
        static fn($l) => $l['verdict']['state'] === 'pending'));
}

/** One saved line, found by its own id and checked against its row. */
function fabo_budget_line(int $lineId, string $sheetKey, int $rowId): ?array
{
    return fabo_one('SELECT * FROM budget_lines WHERE id = ? AND sheet_key = ? AND row_id = ?',
                    'isi', [$lineId, $sheetKey, $rowId]);
}

/** Everything asked for on one row, for the cell in the grid. */
function fabo_budget_totals(string $sheetKey, int $rowId, int $scope = 0): array
{
    $sum = 0.0; $approvedSum = 0.0;
    $pending = 0; $rejected = 0; $draft = 0; $approved = 0; $filled = 0; $files = 0;
    foreach (fabo_budget_lines($sheetKey, $rowId, $scope) as $l) {
        if ($l['required'] > 0) {
            $sum += $l['required'];
            $filled++;
        }
        $st = $l['verdict']['state'];
        if ($st === 'pending')  { $pending++; }
        if ($st === 'rejected') { $rejected++; }
        if ($st === 'draft' || $st === 'unset') { $draft++; }
        if ($st === 'approved') { $approved++; $approvedSum += $l['required']; }
        $files += $l['files'];
    }
    return ['total' => $sum, 'approved_total' => $approvedSum,
            'pending' => $pending, 'rejected' => $rejected, 'draft' => $draft,
            'approved' => $approved, 'filled' => $filled, 'files' => $files];
}

/**
 * The same totals for many rows at once.
 *
 * The grid draws one cell per row and would otherwise ask per row, which on a
 * sheet of two hundred events is two hundred round trips for one column.
 */
function fabo_budget_progress(string $sheetKey, array $rowIds, int $scope = 0): array
{
    $out = [];
    foreach ($rowIds as $id) {
        $out[(int) $id] = ['total' => 0.0, 'approved_total' => 0.0,
                           'pending' => 0, 'rejected' => 0, 'draft' => 0,
                           'approved' => 0, 'filled' => 0, 'files' => 0];
    }
    if (!$rowIds) {
        return $out;
    }
    $in  = implode(',', array_fill(0, count($rowIds), '?'));
    $par = array_merge([$sheetKey], array_map('intval', $rowIds));
    $rows = fabo_all('SELECT id, row_id, required, allocated, threshold_pct, decision
                      FROM budget_lines WHERE sheet_key = ? AND row_id IN (' . $in . ')',
                     's' . str_repeat('i', count($rowIds)), $par);

    $files = fabo_budget_file_counts(array_map('intval', array_column($rows, 'id')));
    foreach ($rows as $r) {
        $rid = (int) $r['row_id'];
        if (!isset($out[$rid])) {
            continue;
        }
        $req = (float) $r['required'];
        if ($req > 0) {
            $out[$rid]['total'] += $req;
            $out[$rid]['filled']++;
        }
        $st = fabo_budget_verdict($req, (float) $r['allocated'],
                                  (float) $r['threshold_pct'],
                                  (string) $r['decision'])['state'];
        if ($st === 'pending')  { $out[$rid]['pending']++; }
        if ($st === 'rejected') { $out[$rid]['rejected']++; }
        if ($st === 'draft' || $st === 'unset') { $out[$rid]['draft']++; }
        if ($st === 'approved') {
            $out[$rid]['approved']++;
            $out[$rid]['approved_total'] += $req;
        }
        $out[$rid]['files'] += (int) ($files[(int) $r['id']] ?? 0);
    }
    return $out;
}

/**
 * How the grid cell should be coloured.
 *
 * Red for a refusal, amber while the owner still has it, green once
 * everything on the row is either inside its limit or approved. Rejection
 * outranks waiting: a row with one of each has something that needs redoing,
 * not merely something that needs waiting for.
 */
function fabo_budget_tone(array $p): string
{
    if (($p['rejected'] ?? 0) > 0) { return 'risk'; }
    if (($p['pending'] ?? 0) > 0)  { return 'warn'; }
    if (($p['draft'] ?? 0) > 0)    { return ''; }   // typed, not yet asked for
    if (($p['approved'] ?? 0) > 0) { return 'good'; }
    return '';
}

/**
 * A line as the caller is allowed to know it.
 *
 * This is the wall. With $maySee false the allocation, the threshold and the
 * ceiling never enter the array, so they cannot reach the browser however the
 * page is read. What survives is the head's name, the figure this person
 * entered themselves, and the verdict — which is what they need to act and
 * nothing more.
 */
function fabo_budget_out(array $l, bool $maySee): array
{
    $out = [
        'item_id'  => $l['item_id'],
        'line_id'  => $l['line_id'],
        'name'     => $l['name'],
        'required' => $l['required'],
        'note'     => $l['note'],
        'files'    => $l['files'],
        'quotes'   => $l['quotes'],
        'invoices' => $l['invoices'],
        /* Whose approval it carries is no secret: it names an authority,
           never a figure. */
        'approved_by' => $l['approved_by'],
        'retired'  => $l['retired'],
        'by'       => $l['by'],
        'state'    => $l['verdict']['state'],
        'word'     => fabo_budget_state_word($l['verdict']['state']),
        'tone'     => fabo_budget_state_tone($l['verdict']['state']),
        'message'  => $l['verdict']['message'],

        /* The owner's justification crosses the wall on purpose. A team
           member cannot see the limit, so a bare refusal tells them nothing
           they can act on; this sentence is the whole of what they learn, and
           it is why the remark is compulsory on a rejection. */
        'remarks'    => (string) ($l['verdict']['remarks'] ?? ''),
        'decided_by' => (string) ($l['decided_by'] ?? ''),
        'decided_at' => ($l['decided_at'] ?? '') !== ''
                        ? date('d M Y', strtotime((string) $l['decided_at'])) : '',
    ];
    if ($maySee) {
        $out['allocated'] = $l['allocated'];
        $out['pct']       = $l['pct'];
        $out['ceiling']   = $l['verdict']['ceiling'];
        $out['over']      = $l['verdict']['over'];
    }
    return $out;
}

/** The whole form, plus the totals the grid cell shows. */
function fabo_budget_payload(string $key, int $rowId, int $scope, bool $maySee): array
{
    $lines = fabo_budget_lines($key, $rowId, $scope);
    $sum   = 0.0; $approvedSum = 0.0;
    $pending = 0; $rejected = 0; $draft = 0; $approved = 0; $filled = 0; $files = 0;
    foreach ($lines as $l) {
        if ($l['required'] > 0) { $sum += $l['required']; $filled++; }
        $st = $l['verdict']['state'];
        if ($st === 'pending')  { $pending++; }
        if ($st === 'rejected') { $rejected++; }
        if ($st === 'draft' || $st === 'unset') { $draft++; }
        if ($st === 'approved') { $approved++; $approvedSum += $l['required']; }
        $files += $l['files'];
    }
    $stage = fabo_budget_stage($key, $rowId);
    $base = [
        'ok'       => true,
        /* Quotations before the event, invoices after it: the documents
           column is headed for the stage the row is in, and an upload is
           filed as that kind. */
        'stage'     => $stage,
        'doc_label' => fabo_budget_doc_label($stage),
        'approvers' => fabo_budget_approvers(),
        'lines'    => array_map(static fn($l) => fabo_budget_out($l, $maySee), $lines),
        'total'    => $sum,
        /* What the event's Budget Allocated now reads, which is the approved
           lines and nothing else. Safe for everybody: it is the sum of what
           was asked and granted, never the heads' own allocations. */
        'approved_total' => $approvedSum,
        'pending'  => $pending,
        'rejected' => $rejected,
        'draft'    => $draft,
        'approved' => $approved,
        'filled'   => $filled,
        'files'    => $files,
        'may_see'  => $maySee,
        /* Whether this viewer is the one who answers the over-limit requests.
           It changes which buttons the drawer offers; the endpoint checks it
           again before acting on any of them. */
        'may_decide' => $maySee,
        'editable' => true,
    ];
    // array_merge, not +: the committee's words and buttons replace the owner's.
    return $key === 'events' ? array_merge($base, fabo_budget_committee_mode($rowId, $lines, $maySee)) : $base;
}

/**
 * On Events the committee decides the budget -- nobody else. The drawer is
 * told so: no owner buttons, the words say "with the committee", and the one
 * button is "Send to committee", offered when there is something to send.
 * See the flow in lib/committees.php.
 */
function fabo_budget_committee_mode(int $rowId, array $lines, bool $maySee = false): array
{
    require_once __DIR__ . '/committees.php';
    $ev = fabo_one('SELECT committee_id FROM `events` WHERE id = ?', 'i', [$rowId]);
    $c  = $ev && (int) $ev['committee_id'] > 0 ? fabo_cm_get((int) $ev['committee_id']) : null;
    $s  = $c ? fabo_cm_status($rowId) : null;

    $words = [
        'pending'  => ['With the committee', 'The committee is reviewing it.'],
        'approved' => ['Approved', 'Approved by the committee.'],
        'rejected' => ['Sent back', 'Sent back by the committee — see the remarks. Change the amount if needed, then send it again.'],
        'draft'    => ['Not sent yet', 'Goes to the committee when the budget is sent.'],
        'unset'    => ['Not sent yet', 'Goes to the committee when the budget is sent.'],
    ];
    $out = [];
    $draft = 0;
    foreach ($lines as $l) {
        $st = $l['verdict']['state'];
        $o  = fabo_budget_out($l, $maySee);   // whoever may read allocations still can; nobody decides here
        if (isset($words[$st])) {
            [$o['word'], $o['message']] = $words[$st];
            $o['state'] = $st === 'unset' ? 'draft' : $st;
            $o['tone']  = fabo_budget_state_tone($o['state']);
        }
        if ($o['state'] === 'draft' && $l['required'] > 0) {
            $draft++;
        }
        $out[] = $o;
    }
    $state = $s['state'] ?? '';
    return [
        'committee_mode'  => true,
        'committee'       => $c ? fabo_cm_title((string) $c['name']) : '',
        'committee_state' => $state,
        // Something new to show it, or a decision to answer by sending it again.
        'can_send'        => $c !== null && ($draft > 0 || $state === 'rejected'),
        'lines'           => $out,
        'draft'           => $draft,
        'may_decide'      => false,
    ];
}

/* ------------------------------------------------------------ attachments */

/** How many files sit on each of these lines. */
function fabo_budget_file_counts(array $lineIds): array
{
    $lineIds = array_values(array_filter(array_map('intval', $lineIds)));
    if (!$lineIds) {
        return [];
    }
    $in  = implode(',', array_fill(0, count($lineIds), '?'));
    $out = [];
    foreach (fabo_all('SELECT row_id, COUNT(*) n FROM attachments
                       WHERE sheet_key = ? AND row_id IN (' . $in . ') GROUP BY row_id',
                      's' . str_repeat('i', count($lineIds)),
                      array_merge([FABO_BUDGET_FILES], $lineIds)) as $r) {
        $out[(int) $r['row_id']] = (int) $r['n'];
    }
    return $out;
}

/** How many quotations and invoices sit on each of these lines. */
function fabo_budget_file_kinds(array $lineIds): array
{
    $lineIds = array_values(array_filter(array_map('intval', $lineIds)));
    if (!$lineIds) {
        return [];
    }
    $in  = implode(',', array_fill(0, count($lineIds), '?'));
    $out = [];
    try {
        foreach (fabo_all('SELECT row_id, doc_kind, COUNT(*) n FROM attachments
                           WHERE sheet_key = ? AND row_id IN (' . $in . ')
                           GROUP BY row_id, doc_kind',
                          's' . str_repeat('i', count($lineIds)),
                          array_merge([FABO_BUDGET_FILES], $lineIds)) as $r) {
            $k = fabo_budget_doc_kind($r['doc_kind'] ?? null);
            $out[(int) $r['row_id']][$k] = ($out[(int) $r['row_id']][$k] ?? 0) + (int) $r['n'];
        }
    } catch (Throwable $e) {
        // Before doc_kind exists every file reads as a quotation.
        foreach (fabo_budget_file_counts($lineIds) as $id => $n) {
            $out[$id]['quotation'] = $n;
        }
    }
    return $out;
}

/** The files on one line, newest first; one kind only when $kind is given. */
function fabo_budget_files(int $lineId, string $kind = ''): array
{
    if ($lineId <= 0) {
        return [];
    }
    $all = fabo_all('SELECT * FROM attachments WHERE sheet_key = ? AND row_id = ?
                     ORDER BY id DESC', 'si', [FABO_BUDGET_FILES, $lineId]);
    if ($kind === '') {
        return $all;
    }
    return array_values(array_filter($all,
        static fn($a) => fabo_budget_doc_kind($a['doc_kind'] ?? null) === $kind));
}

<?php
/**
 * Deletion by request.
 *
 * A row on a sheet, or a document attached to one, is a record, and two
 * people are involved in destroying one: someone asks, and the head of that
 * organisation decides.
 *
 * Who the head is depends on who is asking. A client is the head of their own
 * organisation, so they delete their own records outright and ask nobody. A
 * team member, and a sub-account a client created, ask the head above them:
 * the client who created them, or the administrator for our own staff. So a
 * client's staff never trouble us about a record that is not ours.
 *
 * The rule lives in fabo_may_delete() and fabo_delete_head(). Every path that
 * destroys something goes through them, so the buttons in the browser are a
 * convenience rather than the control.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/approvals.php';   // fabo_row_title()
require_once __DIR__ . '/uploads.php';     // fabo_delete_stored()

/**
 * May this viewer destroy a record outright?
 *
 * The administrator, and a client in their own right: a client heads their
 * own organisation, and the rows they can reach are their own. Their staff
 * and any sub-account they created must ask instead.
 */
function fabo_may_delete(array $viewer): bool
{
    if (!empty($viewer['is_admin'])) {
        return true;
    }
    $c = $viewer['client'] ?? null;
    if (!$c) {
        return false;
    }
    return ($c['kind'] ?? 'client') === 'client' && empty($c['parent_id']);
}

/**
 * Who decides this viewer's request, as [id, label, person|null].
 *
 * id 0 is the administrator, and is also what a viewer who heads nothing in
 * particular falls back to. A client's team and sub-accounts resolve to that
 * client, so their request never reaches us.
 */
function fabo_delete_head(array $viewer): array
{
    $c = $viewer['client'] ?? null;
    $parentId = (int) ($c['parent_id'] ?? 0);
    if ($parentId > 0) {
        $p = fabo_one('SELECT * FROM clients WHERE id = ? AND revoked_at IS NULL',
                      'i', [$parentId]);
        if ($p) {
            $name = ($p['organisation'] ?? '') !== '' ? $p['organisation'] : $p['name'];
            return [(int) $p['id'], $name, $p];
        }
    }
    // Our own staff, and anyone whose head has since been removed.
    return [0, 'the administrator', null];
}

/** May this viewer answer that request? Only the head it was sent to. */
function fabo_may_decide_delete(array $viewer, array $req): bool
{
    $head = (int) ($req['head_id'] ?? 0);
    if (!empty($viewer['is_admin'])) {
        return $head === 0;
    }
    return fabo_may_delete($viewer) && $head === (int) ($viewer['client']['id'] ?? -1);
}

/** The sentence shown to someone who cannot delete. */
function fabo_delete_denied_message(array $viewer = []): string
{
    $who = $viewer ? fabo_delete_head($viewer)[1] : 'the administrator';
    return 'You do not have access to remove this. Send a request and '
         . $who . ' will review it.';
}

/* ------------------------------------------------------------- requests */

/** An identical request already waiting, or null. */
function fabo_delete_request_open(string $kind, string $sheetKey, int $rowId, ?int $attachmentId): ?array
{
    if ($attachmentId) {
        return fabo_one("SELECT * FROM delete_requests
                         WHERE state = 'pending' AND kind = ? AND attachment_id = ?",
                        'si', [$kind, $attachmentId]);
    }
    return fabo_one("SELECT * FROM delete_requests
                     WHERE state = 'pending' AND kind = ? AND sheet_key = ? AND row_id = ?
                       AND attachment_id IS NULL", 'ssi', [$kind, $sheetKey, $rowId]);
}

/**
 * Records a request to delete something. Returns [id, alreadyOpen].
 * Asking twice for the same thing updates the reason rather than piling up.
 */
function fabo_delete_request_add(array $viewer, string $kind, string $sheetKey, int $rowId,
                                 ?int $attachmentId, string $label, string $reason): array
{
    $who     = $viewer['is_admin'] ? 'Admin' : (string) $viewer['client']['name'];
    $whoId   = $viewer['is_admin'] ? null : (int) $viewer['client']['id'];
    $headId  = fabo_delete_head($viewer)[0];
    $reason  = trim($reason);
    $existing = fabo_delete_request_open($kind, $sheetKey, $rowId, $attachmentId);

    if ($existing) {
        fabo_exec('UPDATE delete_requests SET reason = ?, requested_by = ?, requested_by_name = ?,
                   head_id = ?, created_at = NOW() WHERE id = ?', 'sisii',
                  [$reason === '' ? null : $reason, $whoId, $who, $headId, (int) $existing['id']]);
        return [(int) $existing['id'], true];
    }

    $id = fabo_exec('INSERT INTO delete_requests (kind, sheet_key, row_id, attachment_id, label,
                        requested_by, requested_by_name, head_id, reason)
                     VALUES (?,?,?,?,?,?,?,?,?)', 'ssiisisis',
                    [$kind, $sheetKey, $rowId, $attachmentId, mb_substr($label, 0, 190),
                     $whoId, $who, $headId, $reason === '' ? null : $reason]);
    return [$id, false];
}

function fabo_delete_request(int $id): ?array
{
    return fabo_one('SELECT * FROM delete_requests WHERE id = ?', 'i', [$id]);
}

/**
 * Requests in one state, newest first, for one head's desk.
 *
 * $headId 0 is the administrator's queue. Passing null returns every queue
 * and is only for a caller that has already decided it may see them all.
 */
function fabo_delete_requests(string $state = 'pending', int $limit = 200,
                              ?int $headId = 0): array
{
    $sql = 'SELECT * FROM delete_requests WHERE state = ?';
    $t   = 's';
    $p   = [$state];
    if ($headId !== null) {
        $sql .= ' AND head_id = ?';
        $t   .= 'i';
        $p[]  = $headId;
    }
    $sql .= ' ORDER BY created_at DESC, id DESC LIMIT ' . max(1, min(500, $limit));
    return fabo_all($sql, $t, $p);
}

function fabo_delete_pending_count(?int $headId = 0): int
{
    $sql = "SELECT COUNT(*) AS n FROM delete_requests WHERE state = 'pending'";
    $t   = '';
    $p   = [];
    if ($headId !== null) {
        $sql .= ' AND head_id = ?';
        $t    = 'i';
        $p    = [$headId];
    }
    $r = fabo_one($sql, $t, $p);
    return (int) ($r['n'] ?? 0);
}

/** The pending count for whoever is looking, on their own desk. */
function fabo_delete_pending_for(array $viewer): int
{
    if (!empty($viewer['is_admin'])) {
        return fabo_delete_pending_count(0);
    }
    if (!fabo_may_delete($viewer)) {
        return 0;   // they answer nothing, so nothing is waiting on them
    }
    return fabo_delete_pending_count((int) $viewer['client']['id']);
}

/** Everything one person has asked to delete, newest first. */
function fabo_delete_requests_by(int $clientId, int $limit = 50): array
{
    return fabo_all('SELECT * FROM delete_requests WHERE requested_by = ?
                     ORDER BY created_at DESC, id DESC LIMIT ' . max(1, min(200, $limit)),
                    'i', [$clientId]);
}

/* -------------------------------------------------------------- decision */

/**
 * Approves or refuses a request. Approving performs the deletion itself, so
 * nothing is destroyed anywhere else in the code without passing through here.
 *
 * Returns [ok, message].
 */
function fabo_delete_decide(int $id, string $state, string $note, string $who,
                            ?array $decider = null): array
{
    if (!in_array($state, ['approved', 'rejected'], true)) {
        return [false, 'Unknown decision.'];
    }
    $req = fabo_delete_request($id);
    if (!$req) {
        return [false, 'That request no longer exists.'];
    }
    if ($req['state'] !== 'pending') {
        return [false, 'That request has already been answered.'];
    }
    if ($decider !== null && !fabo_may_decide_delete($decider, $req)) {
        return [false, 'That request is not yours to answer.'];
    }
    $note = trim($note);
    if ($state === 'rejected' && $note === '') {
        return [false, 'Say why you are refusing, so they know what to do next.'];
    }

    if ($state === 'approved') {
        [$done, $why] = fabo_delete_perform($req);
        if (!$done) {
            return [false, $why];
        }
    }

    fabo_exec('UPDATE delete_requests SET state = ?, decided_by = ?, decision_note = ?,
               decided_at = NOW() WHERE id = ?', 'sssi',
              [$state, $who, $note === '' ? null : $note, $id]);

    return [true, $state === 'approved' ? 'Deleted.' : 'Request refused.'];
}

/** Carries out an approved deletion. Returns [ok, reason]. */
function fabo_delete_perform(array $req): array
{
    if ($req['kind'] === 'file') {
        $a = fabo_one('SELECT * FROM attachments WHERE id = ?', 'i', [(int) $req['attachment_id']]);
        if (!$a) {
            return [true, 'The file was already gone.'];   // nothing left to do
        }
        fabo_delete_stored((string) $a['stored_name']);
        fabo_exec('DELETE FROM attachments WHERE id = ?', 'i', [(int) $a['id']]);
        return [true, 'File deleted.'];
    }

    $sheet = fabo_sheet((string) $req['sheet_key']);
    if (!$sheet) {
        return [false, 'That sheet no longer exists.'];
    }
    $rowId = (int) $req['row_id'];
    $row   = fabo_one('SELECT id FROM `' . $sheet['table'] . '` WHERE id = ?', 'i', [$rowId]);
    if (!$row) {
        return [true, 'The row was already gone.'];
    }

    return fabo_delete_row((string) $req['sheet_key'], $sheet, $rowId)
        ? [true, 'Row deleted.']
        : [false, 'The row could not be removed from the database.'];
}

/**
 * Removes one row for good: its files, the row itself, its line in the Master
 * Activity Log and, on the requisition sheet, its place in the queue.
 *
 * The one place a row is deleted, so the grid's delete and an approved
 * delete request cannot drift apart. Returns whether the row is really gone,
 * checked against the table rather than assumed from the DELETE having run.
 */
function fabo_delete_row(string $key, array $sheet, int $rowId): bool
{
    $table = (string) $sheet['table'];

    // A row's attachments would otherwise be orphaned on disk.
    foreach (fabo_all('SELECT * FROM attachments WHERE sheet_key = ? AND row_id = ?',
                      'si', [$key, $rowId]) as $a) {
        fabo_delete_stored((string) $a['stored_name']);
    }
    fabo_exec('DELETE FROM attachments WHERE sheet_key = ? AND row_id = ?', 'si', [$key, $rowId]);
    fabo_exec('DELETE FROM `' . $table . '` WHERE id = ?', 'i', [$rowId]);

    if (fabo_one('SELECT id FROM `' . $table . '` WHERE id = ?', 'i', [$rowId])) {
        error_log("FABO delete: {$table} #{$rowId} is still there after DELETE");
        return false;
    }

    /* And out of the Master Activity Log with it. A line for a row that is
       gone would keep counting towards the dashboard for ever. */
    require_once __DIR__ . '/masterlog.php';
    fabo_master_forget($key, $rowId);

    /* A requisition that is gone no longer holds a place in the queue: the
       ones behind it move up, and the next form promises from the shorter
       queue. Promises already made hold, as they do on every recalculation. */
    if ($table === 'requisitions') {
        try {
            require_once __DIR__ . '/requisitions.php';
            fabo_req_reschedule();
        } catch (Throwable $e) {
            error_log('FABO delete requeue: ' . $e->getMessage());
        }
    }
    return true;
}

/** What a request is about, in one line. */
function fabo_delete_describe(array $req): string
{
    $sheet = fabo_sheet_label((string) $req['sheet_key']);
    return ($req['kind'] === 'file' ? 'Document' : 'Row') . ' — ' . $sheet
         . ($req['label'] !== '' ? ': ' . $req['label'] : '');
}

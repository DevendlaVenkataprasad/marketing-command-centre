<?php
/**
 * Approve / reject for Content and Design rows.
 *
 * A rejection must carry remarks — enforced here, not only in the form, so the
 * rule holds however the endpoint is called. Every decision is logged, so the
 * reason a piece was sent back is never lost.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/rules.php';
require_once __DIR__ . '/auth.php';

/** Sheets that carry an approval workflow. */
function fabo_approval_sheets(): array
{
    return ['content' => 'Content', 'design' => 'Design', 'events' => 'Events',
            'webcontent' => 'Website Content'];
}

function fabo_has_approval(string $sheetKey): bool
{
    return array_key_exists($sheetKey, fabo_approval_sheets());
}

function fabo_approval_states(): array
{
    return [
        'pending'  => ['Pending review', 'warn'],
        'approved' => ['Approved',       'good'],
        'rejected' => ['Rejected',       'risk'],
    ];
}

/** Current decision for a row, or the pending default. */
function fabo_approval(string $sheetKey, int $rowId): array
{
    $r = fabo_one('SELECT * FROM approvals WHERE sheet_key = ? AND row_id = ?', 'si', [$sheetKey, $rowId]);
    return $r ?: ['state' => 'pending', 'remarks' => null, 'decided_by' => null, 'decided_at' => null];
}

/** Decisions for many rows at once, keyed by row id. */
function fabo_approvals_for(string $sheetKey, array $rowIds): array
{
    if (!$rowIds) {
        return [];
    }
    $in  = implode(',', array_fill(0, count($rowIds), '?'));
    $out = [];
    foreach (fabo_all('SELECT * FROM approvals WHERE sheet_key = ? AND row_id IN (' . $in . ')',
                      's' . str_repeat('i', count($rowIds)),
                      array_merge([$sheetKey], array_map('intval', $rowIds))) as $r) {
        $out[(int) $r['row_id']] = $r;
    }
    return $out;
}

/**
 * Records a decision. Returns [ok, message].
 * $state is 'approved' | 'rejected' | 'pending'.
 */
function fabo_set_approval(string $sheetKey, int $rowId, string $state, string $remarks, string $who): array
{
    if (!fabo_has_approval($sheetKey)) {
        return [false, 'This sheet has no approval step.'];
    }
    if (!isset(fabo_approval_states()[$state])) {
        return [false, 'Unknown decision.'];
    }
    $remarks = trim($remarks);

    // The one hard rule: a rejection must say why.
    if ($state === 'rejected' && $remarks === '') {
        return [false, 'Remarks are required when rejecting. Say what needs changing.'];
    }

    fabo_exec('INSERT INTO approvals (sheet_key, row_id, state, remarks, decided_by, decided_at)
               VALUES (?,?,?,?,?,NOW())
               ON DUPLICATE KEY UPDATE state = VALUES(state), remarks = VALUES(remarks),
                                       decided_by = VALUES(decided_by), decided_at = VALUES(decided_at)',
              'sisss', [$sheetKey, $rowId, $state, $remarks === '' ? null : $remarks, $who]);

    fabo_exec('INSERT INTO approval_log (sheet_key, row_id, state, remarks, decided_by)
               VALUES (?,?,?,?,?)', 'sisss',
              [$sheetKey, $rowId, $state, $remarks === '' ? null : $remarks, $who]);

    /* A website post's decision is also its place on the schedule: approved
       puts it there, rejected takes it off. See fabo_wc_after_decision(). */
    if ($sheetKey === 'webcontent') {
        require_once __DIR__ . '/webcontent.php';
        fabo_wc_after_decision($rowId, $state, $remarks, $who);
    }

    /* An approved event that asked for design & publicity support goes to the
       design team now, and everyone is told. See lib/eventdesign.php. */
    if ($sheetKey === 'events' && $state === 'approved') {
        try {
            require_once __DIR__ . '/eventdesign.php';
            fabo_evdesign_raise($rowId);
        } catch (Throwable $e) {
            error_log('FABO event design on approval: ' . $e->getMessage());
        }
    }

    $label = fabo_approval_states()[$state][0];
    return [true, $label . '.'];
}

/**
 * A short human name for a row, for emails and notices.
 *
 * work.php already knows which column titles each sheet. It includes grid.php,
 * which includes this file, so it is pulled in here at call time rather than
 * at the top — by then this file has finished loading and there is no loop.
 * The business ID is the fallback when a row has no title of its own.
 */
function fabo_row_title(string $sheetKey, array $row): string
{
    if (!function_exists('fabo_work_sources')) {
        require_once __DIR__ . '/work.php';
    }
    if (function_exists('fabo_work_sources')) {
        $field = fabo_work_sources()[$sheetKey][3] ?? null;
        if ($field && trim((string) ($row[$field] ?? '')) !== '') {
            return mb_substr(trim((string) $row[$field]), 0, 120);
        }
    }
    $sheet = fabo_sheet($sheetKey);
    $idF   = $sheet['id_field'] ?? null;
    if ($idF && trim((string) ($row[$idF] ?? '')) !== '') {
        return (string) $row[$idF];
    }
    return '';
}

/* ------------------------------------------------------------- requests */

/*
 * Approving is not a sheet-wide right. Someone with edit access chooses the
 * rows that need a client's word on them and sends those; until a row has been
 * sent to a client, that client can read its state and nothing more. This is
 * enforced in fabo_may_decide(), which every path to a decision goes through.
 */

/** The live request for one row and one client, or null. */
function fabo_approval_request(string $sheetKey, int $rowId, int $clientId): ?array
{
    return fabo_one('SELECT * FROM approval_requests
                     WHERE sheet_key = ? AND row_id = ? AND client_id = ? AND cancelled_at IS NULL',
                    'sii', [$sheetKey, $rowId, $clientId]);
}

/** Everyone asked about one row, newest first, with their names. */
function fabo_row_requests(string $sheetKey, int $rowId): array
{
    return fabo_all("SELECT r.*, COALESCE(c.name, 'Administrator') AS name,
                            c.email, c.organisation
                     FROM approval_requests r
                     LEFT JOIN clients c ON c.id = r.client_id
                     WHERE r.sheet_key = ? AND r.row_id = ? AND r.cancelled_at IS NULL
                     ORDER BY r.requested_at DESC", 'si', [$sheetKey, $rowId]);
}

/**
 * Who has been asked, for a whole sheet at once: row_id => list of
 * ['name', 'answered']. One query for the grid rather than one per row.
 */
function fabo_requests_for_rows(string $sheetKey, array $rowIds): array
{
    $ids = array_values(array_unique(array_map('intval', $rowIds)));
    $out = [];
    if (!$ids) {
        return $out;
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    foreach (fabo_all("SELECT r.row_id, r.responded_at,
                              COALESCE(c.name, 'Administrator') AS name, c.organisation
                       FROM approval_requests r
                       LEFT JOIN clients c ON c.id = r.client_id
                       WHERE r.sheet_key = ? AND r.row_id IN (" . $in . ") AND r.cancelled_at IS NULL
                       ORDER BY r.requested_at",
                      's' . str_repeat('i', count($ids)),
                      array_merge([$sheetKey], $ids)) as $r) {
        $out[(int) $r['row_id']][] = [
            'name'     => (string) $r['name'],
            'org'      => (string) $r['organisation'],
            'answered' => !empty($r['responded_at']),
        ];
    }
    return $out;
}

/**
 * Everything sent out for approval on one sheet, newest first.
 *
 * One row per request, carrying the client it went to, when it went, what came
 * back and why. This is the same data the grid already uses, read the other way
 * round: by request rather than by row, so the sheet can show its own history.
 */
function fabo_sheet_approval_activity(string $sheetKey, ?array $viewer = null,
                                      int $limit = 200): array
{
    $sheet = fabo_sheet($sheetKey);
    if (!$sheet || !fabo_has_approval($sheetKey)) {
        return [];
    }
    /*
     * Scoped to the reader's organisation, exactly as the rows themselves
     * are. Without this the panel listed every request on the sheet — who
     * asked, which client, and that client's organisation — so anyone inside
     * one client could read the names of every other client we work for.
     *
     * A null owner means the admin or our own staff: they see all of it,
     * which is the point of the panel for them.
     */
    $owner  = $viewer === null ? null : fabo_row_owner($viewer);
    $where  = '';
    $types  = 's';
    $params = [$sheetKey];
    if ($owner !== null) {
        // Either the request went to somebody in this organisation, or it was
        // raised on one of its own rows.
        $where    = ' AND (c.parent_id = ? OR c.id = ?)';
        $types   .= 'ii';
        $params[] = $owner;
        $params[] = $owner;
    }

    $rows = fabo_all("SELECT r.row_id, r.note, r.requested_by, r.requested_at, r.responded_at,
                             COALESCE(c.name, 'Administrator') AS client_name,
                             c.organisation AS client_org,
                             a.state, a.remarks, a.decided_by, a.decided_at
                      FROM approval_requests r
                      LEFT JOIN clients c ON c.id = r.client_id
                      LEFT JOIN approvals a ON a.sheet_key = r.sheet_key AND a.row_id = r.row_id
                      WHERE r.sheet_key = ? AND r.cancelled_at IS NULL" . $where . "
                      ORDER BY r.requested_at DESC, r.id DESC
                      LIMIT " . max(1, min(500, $limit)), $types, $params);
    if (!$rows) {
        return [];
    }

    // One lookup for every title, rather than one query per line.
    $ids   = array_values(array_unique(array_map(static fn($r) => (int) $r['row_id'], $rows)));
    $in    = implode(',', array_fill(0, count($ids), '?'));
    $byId  = [];
    foreach (fabo_all('SELECT * FROM `' . $sheet['table'] . '` WHERE id IN (' . $in . ')',
                      str_repeat('i', count($ids)), $ids) as $r) {
        $byId[(int) $r['id']] = $r;
    }

    foreach ($rows as $i => $r) {
        $src = $byId[(int) $r['row_id']] ?? [];
        $rows[$i]['title'] = $src ? fabo_row_title($sheetKey, $src) : '';
        // Until they answer, the row's stored state is not their answer.
        $rows[$i]['status'] = $r['responded_at'] ? (string) ($r['state'] ?? 'pending') : 'pending';
    }
    return $rows;
}

/** Row ids on one sheet that this client has been asked to decide. */
function fabo_requested_rows(string $sheetKey, int $clientId): array
{
    $out = [];
    foreach (fabo_all('SELECT row_id FROM approval_requests
                       WHERE sheet_key = ? AND client_id = ? AND cancelled_at IS NULL',
                      'si', [$sheetKey, $clientId]) as $r) {
        $out[(int) $r['row_id']] = true;
    }
    return $out;
}

/** Everything still waiting on one client, across sheets. */
function fabo_pending_requests(int $clientId): array
{
    return fabo_all('SELECT * FROM approval_requests
                     WHERE client_id = ? AND cancelled_at IS NULL AND responded_at IS NULL
                     ORDER BY requested_at DESC LIMIT 100', 'i', [$clientId]);
}

function fabo_pending_request_count(int $clientId): int
{
    $r = fabo_one('SELECT COUNT(*) AS n FROM approval_requests
                   WHERE client_id = ? AND cancelled_at IS NULL AND responded_at IS NULL',
                  'i', [$clientId]);
    return (int) ($r['n'] ?? 0);
}

/**
 * May this viewer record a decision on this row?
 *
 * Admin always may. Anyone else may only when the row was explicitly sent to
 * them — holding edit access to the sheet is not enough.
 */
function fabo_may_decide(array $viewer, string $sheetKey, int $rowId): bool
{
    if (!fabo_has_approval($sheetKey)) {
        return false;
    }
    /* An event is approved or rejected by its committee and nobody else --
       not the admin, not an organisation's head (lib/committees.php). The
       Review panel still shows its history; it just offers no decision. */
    if ($sheetKey === 'events') {
        return false;
    }
    if (!empty($viewer['is_admin'])) {
        return true;
    }
    if (($viewer['state'] ?? 'ok') !== 'ok') {
        return false;
    }
    /*
     * A sheet shared read-only is shared to be read.
     *
     * This used to accept 'view', so somebody given a sheet to look at could
     * still approve or reject on it. Deciding is not looking: an approval is
     * a change to the row's standing that everybody else then works from, and
     * an account that cannot change a cell should not be able to change that.
     *
     * The consequence is worth saying out loud, because it is the thing that
     * will surprise somebody: a row sent for approval to an account that only
     * holds 'view' can no longer be decided by them. If you want somebody to
     * sign work off, give them edit on that sheet.
     */
    if (fabo_permission($viewer, $sheetKey) !== 'edit') {
        return false;
    }
    // The head of an organisation speaks for it: they can approve or reject
    // anything on a sheet they hold, without waiting to be asked.
    if (fabo_is_org_head($viewer)) {
        return true;
    }
    return fabo_approval_request($sheetKey, $rowId, (int) $viewer['client']['id']) !== null;
}

/**
 * Is this viewer the head of a client organisation?
 *
 * A client account that runs its own people is that organisation's head. Their
 * own staff and sub-accounts are not, and neither is an ordinary client.
 */
function fabo_is_org_head(array $viewer): bool
{
    if (!empty($viewer['is_admin'])) {
        return false;
    }
    $c = $viewer['client'] ?? null;
    require_once __DIR__ . '/people.php';
    return $c
        && empty($c['parent_id'])
        && fabo_may_make($c);
}

/**
 * The person a team member answers to.
 *
 * Our own team answers to the administrator. A client's team answers to the
 * client who created them. Returned as [inbox id, label, person|null], where a
 * null person means the administrator.
 */
function fabo_approval_head(array $viewer): ?array
{
    if (!empty($viewer['is_admin'])) {
        return null;
    }
    $c = $viewer['client'] ?? null;
    if (!$c) {
        return null;
    }
    $parentId = (int) ($c['parent_id'] ?? 0);
    if ($parentId > 0) {
        $p = fabo_one('SELECT * FROM clients WHERE id = ? AND revoked_at IS NULL', 'i', [$parentId]);
        return $p ? [(int) $p['id'], $p['name'] . ' (your head)', $p] : null;
    }
    // Created by us, so we are their head.
    return [0, 'Administrator (internal approval)', null];
}

/**
 * May this viewer ask someone else to decide? Admin, or a client who runs
 * their own people and can edit the sheet.
 */
function fabo_may_request(array $viewer, string $sheetKey): bool
{
    if (!fabo_has_approval($sheetKey)) {
        return false;
    }
    // An event goes to its committee (the Committee column), not to a person.
    if ($sheetKey === 'events') {
        return false;
    }
    if (!empty($viewer['is_admin'])) {
        return true;
    }
    if (($viewer['state'] ?? 'ok') !== 'ok') {
        return false;
    }
    // One of OUR staff may send any sheet they hold out for a client's word
    // on it. Holding the sheet at all is the whole condition. A client's own
    // team member is not covered by this and falls through to the rule below,
    // which keeps them inside their organisation.
    if (fabo_is_our_staff($viewer)) {
        return fabo_permission($viewer, $sheetKey) !== 'none';
    }
    // A client may only send rows on to the people they run themselves —
    // either kind, since a row can go to a client or to a team member.
    require_once __DIR__ . '/people.php';
    return fabo_may_make($viewer['client'] ?? null)
        && fabo_permission($viewer, $sheetKey) === 'edit';
}

/**
 * The people this viewer may send a row to: everyone who can already open the
 * sheet. A client only ever sees their own accounts, never ours.
 */
function fabo_request_candidates(array $viewer, string $sheetKey, ?array $row = null): array
{
    /* Given the row: a row belongs to one client organisation, so only that
       organisation is ever asked to approve it. Offering every client who
       holds the sheet let one client be sent (and emailed) another's row. */
    $rowOrg = (int) ($row['client_id'] ?? 0);
    // Approval is a client's judgement on our work, so only clients are ever
    // offered — a team member is on our side of the line, never asked to sign
    // work off. The admin and our team may ask any client who holds the sheet;
    // a client may only ask the people they created.
    // Us — the admin and our own staff — may ask any client who holds the
    // sheet. Anyone inside a client may ask only within their organisation:
    // offering them a list of every client on the portal would tell them who
    // else we work for, which is not theirs to know.
    $mine = !empty($viewer['is_admin']) || fabo_is_our_staff($viewer);

    if ($mine && $rowOrg > 0) {
        $rows = fabo_all("SELECT c.* FROM clients c
                          JOIN client_sheet_access a ON a.client_id = c.id AND a.sheet_key = ?
                          WHERE c.kind = 'client' AND c.revoked_at IS NULL
                            AND (c.id = ? OR c.parent_id = ?)
                          ORDER BY c.organisation, c.name", 'sii', [$sheetKey, $rowOrg, $rowOrg]);
    } elseif ($mine) {
        $rows = fabo_all("SELECT c.* FROM clients c
                          JOIN client_sheet_access a ON a.client_id = c.id AND a.sheet_key = ?
                          WHERE c.kind = 'client' AND c.revoked_at IS NULL
                          ORDER BY c.organisation, c.name", 's', [$sheetKey]);
    } else {
        // The organisation, not the person: a client's team member has no
        // accounts of their own, and asking by their own id would offer them
        // nobody at all.
        $org  = fabo_viewer_org($viewer);
        $rows = fabo_all("SELECT c.* FROM clients c
                          JOIN client_sheet_access a ON a.client_id = c.id AND a.sheet_key = ?
                          WHERE c.kind = 'client' AND c.revoked_at IS NULL
                            AND (c.parent_id = ? OR c.id = ?)
                          ORDER BY c.organisation, c.name",
                         'sii', [$sheetKey, $org, $org]);
        // Not themselves: a person cannot be their own approver.
        $rows = array_values(array_filter($rows,
            static fn($c) => (int) $c['id'] !== (int) $viewer['client']['id']));
    }
    // An expired account cannot act on anything, so do not offer it.
    $out = array_values(array_filter($rows, static fn($c) => fabo_client_state($c) === 'ok'));

    // A team member can send a row up for a sign-off before, or instead of,
    // putting it in front of a client. For our staff that head is us; for a
    // client's team member it is the client who runs them.
    if (empty($viewer['is_admin']) && ($viewer['client']['kind'] ?? 'client') === 'team') {
        $head = fabo_approval_head($viewer);
        if ($head) {
            [$hid, $label, $person] = $head;
            // A client's head is already on the list above, being a client in
            // their own organisation. Mark that entry rather than adding a
            // second copy of the same person.
            $already = false;
            foreach ($out as $i => $c) {
                if ((int) $c['id'] === $hid && $hid > 0) {
                    $out[$i]['is_head'] = true;
                    $already = true;
                    break;
                }
            }
            if ($already) {
                return $out;
            }
            array_unshift($out, $person ?: [
                'id'           => 0,
                'name'         => 'Administrator',
                'organisation' => '',
                'kind'         => 'admin',
                'email'        => defined('ADMIN_INBOX_EMAIL') ? ADMIN_INBOX_EMAIL : '',
                'token'        => '',
                'is_head'      => true,
            ]);
            $out[0]['is_head'] = true;
        }
    }
    return $out;
}

/** Records a request. Re-sending an existing one reopens it. Returns its id. */
function fabo_add_request(string $sheetKey, int $rowId, int $clientId, string $note,
                          string $who, int $whoId = 0): int
{
    fabo_exec('INSERT INTO approval_requests (sheet_key, row_id, client_id, note, requested_by,
                  requested_by_id)
               VALUES (?,?,?,?,?,?)
               ON DUPLICATE KEY UPDATE note = VALUES(note), requested_by = VALUES(requested_by),
                                       requested_by_id = VALUES(requested_by_id),
                                       requested_at = NOW(), responded_at = NULL, cancelled_at = NULL',
              'siissi', [$sheetKey, $rowId, $clientId, $note === '' ? null : $note, $who,
                         $whoId > 0 ? $whoId : null]);
    $r = fabo_approval_request($sheetKey, $rowId, $clientId);
    return (int) ($r['id'] ?? 0);
}

/** Withdraws a request. The client keeps the row in view, but can no longer act. */
function fabo_cancel_request(string $sheetKey, int $rowId, int $clientId): void
{
    fabo_exec('UPDATE approval_requests SET cancelled_at = NOW()
               WHERE sheet_key = ? AND row_id = ? AND client_id = ? AND cancelled_at IS NULL',
              'sii', [$sheetKey, $rowId, $clientId]);
}

/** Closes the request once its client has answered. */
function fabo_mark_responded(string $sheetKey, int $rowId, int $clientId): void
{
    fabo_exec('UPDATE approval_requests SET responded_at = NOW()
               WHERE sheet_key = ? AND row_id = ? AND client_id = ? AND cancelled_at IS NULL',
              'sii', [$sheetKey, $rowId, $clientId]);
}

/** The decision history for a row, newest first. */
function fabo_approval_log(string $sheetKey, int $rowId): array
{
    return fabo_all('SELECT state, remarks, decided_by, created_at FROM approval_log
                     WHERE sheet_key = ? AND row_id = ? ORDER BY created_at DESC, id DESC LIMIT 50',
                    'si', [$sheetKey, $rowId]);
}

/** Tone for an approval state. */
function fabo_approval_tone(string $state): string
{
    return fabo_approval_states()[$state][1] ?? 'muted';
}

/** Is this event's status the one that means it happened? */
function fabo_event_is_complete(?string $status): bool
{
    return strcasecmp(trim((string) $status), FABO_STATUS_COMPLETED) === 0;
}

/**
 * A completed event is an approved one.
 *
 * When an event's Status is Completed and its Sign-off is still Pending or
 * blank, Sign-off becomes Approved -- an event that has already happened is
 * not waiting on anybody's say-so, and counting it as "Pending Approval"
 * made the dashboard read as if half the year were still undecided.
 *
 * A Sign-off of Rejected is a decision somebody made and is left alone.
 * Nothing is written if this organisation's Sign-off list has no Approved.
 * Returns whether the row was changed.
 */
function fabo_signoff_on_complete(string $sheetKey, int $rowId, ?array $viewer = null): bool
{
    if ($sheetKey !== 'events' || $rowId <= 0) {
        return false;
    }
    $r = fabo_one('SELECT status, approval_status FROM `events` WHERE id = ?', 'i', [$rowId]);
    if (!$r || !fabo_event_is_complete($r['status'] ?? null)) {
        return false;
    }
    $was = trim((string) ($r['approval_status'] ?? ''));
    if ($was !== '' && strcasecmp($was, 'Pending') !== 0) {
        return false;
    }
    require_once __DIR__ . '/lists.php';
    $opts = fabo_list_values('Sign-off');
    if ($opts && !in_array('Approved', $opts, true)) {
        return false;
    }
    fabo_exec('UPDATE `events` SET approval_status = ? WHERE id = ?', 'si', ['Approved', $rowId]);
    if ($viewer) {
        fabo_audit($viewer, 'events', $rowId, 'approval_status', $was, 'Approved');
    }
    return true;
}

function fabo_approval_label(string $state): string
{
    return fabo_approval_states()[$state][0] ?? $state;
}

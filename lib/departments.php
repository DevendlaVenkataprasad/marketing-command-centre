<?php
/**
 * Event departments, and the live sheets they fill in.
 *
 * An event is rarely run by one team. Logistics, catering, security, the
 * media crew — each holds a piece of the picture, and most of them have no
 * account here and never will. A department gets a sealed link to a live
 * sheet with the same columns as the Events sheet, and what they type lands
 * as an ordinary event row tagged with their department.
 *
 * That last part is the point: there is no second store to reconcile. A row
 * a department submits is on the calendar, in the figures and in the export
 * the moment they save it, because it is the same row in the same table.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/cryptolink.php';
require_once __DIR__ . '/sheets.php';
require_once __DIR__ . '/uploads.php';

/** How long a share link lasts by default. */
const FABO_DEPT_TTL_DAYS = 30;

/**
 * The columns a department fills in.
 *
 * Deliberately a subset of the Events sheet, not all of it: budget, sign-off
 * and the linked campaign are ours to decide, and putting them in front of a
 * caterer invites a well-meant guess. Everything here is something the
 * department is the best-placed source for.
 */
/**
 * The list the hub/spoke dropdown reads.
 *
 * An ordinary managed list, so its options are added, renamed and retired in
 * Sheet Management like every other dropdown -- which is the rule for every
 * dropdown in the portal, and the reason this is not a fixed pair of words.
 */
const FABO_HUB_LIST = 'Hub / Spoke';

/** The hub/spoke options on offer, in the order they are listed. */
function fabo_hub_values(): array
{
    require_once __DIR__ . '/lists.php';
    return fabo_list_values(FABO_HUB_LIST);
}

function fabo_dept_fields(): array
{
    return array_keys(fabo_dept_columns());
}

/**
 * Columns this portal fills in for itself, which a department never should.
 *
 * Everything else on the Events sheet is theirs. Kept as a short list of
 * exceptions rather than a long list of inclusions, so a column added to
 * the sheet appears on the shared sheet by default — which is the whole
 * point of deriving one from the other.
 */
function fabo_dept_not_theirs(): array
{
    return [
        /* Budget Allocated and Budget Spent are NOT on this list: a department
           reports what it was given and what it spent, on its own rows, and
           those rows are the main sheet's rows once submitted. An approved
           request on the budget form still sets Allocated for that row. */
        'linked_campaign_id',
        // The approval trail belongs to whoever is approving.
        'approval_to', 'approval_status', 'approval_note',
        // Whether a committee approves is the organisers' business, not the unit's.
        'committee', 'committee_state',
        // Written by the portal when the event is approved (lib/eventdesign.php).
        'design_req_ref',
        // Bookkeeping, not content.
        'client_id', 'department_id',
    ];
}

/**
 * The live sheet's columns: the Events sheet's own, as it stands today.
 *
 * Worked out from the sheet definition every time rather than copied into a
 * list here. A column added, renamed or taken off in Sheet Management is
 * added, renamed or taken off the shared sheet with it — there is no second
 * place to remember, which is the only way the two stay the same.
 *
 * Returns [field => [label, type, list]].
 */
function fabo_dept_columns(): array
{
    $skip = array_flip(fabo_dept_not_theirs());
    $out  = [];
    foreach (fabo_sheet('events')['columns'] as $c) {
        /* Computed columns work themselves out and an id is issued here, so
           neither is something anybody types.

           A gallery is left out for a different reason: the live sheet draws
           its own Images column at the end, with thumbnails and an upload
           button. Letting one through here as well put the sheet's own
           row_images into the ordinary loop, where it became a TEXT BOX
           labelled "Images" — so the page showed two columns of that name,
           one of them useless. The gallery is d.php's to draw. */
        if (!isset($skip[$c[1]]) && !in_array($c[2], ['computed', 'id', 'images'], true)) {
            /* A metric column is labelled "Expected" or "Achieved" and takes
               its meaning from the banner above it on the sheet. The live
               sheet has no banner, so the name is folded in — a bare
               "Expected" column tells a caterer nothing. */
            $label = ($c[3]['group'] ?? '') !== ''
                ? $c[3]['group'] . ' — ' . $c[0]
                : $c[0];
            $out[$c[1]] = ['label' => $label, 'type' => $c[2], 'list' => $c[3]['list'] ?? null];
        }
    }
    return $out;
}

/* ------------------------------------------------------------- reading */

/** Every department on one event, newest first. */
function fabo_departments(int $eventRowId): array
{
    return fabo_all('SELECT * FROM event_departments WHERE event_row_id = ?
                     ORDER BY name', 'i', [$eventRowId]);
}

/**
 * Every department of several events at once.
 *
 * The desk shows all the events the filters left, so asking per event would
 * be one query per row on a page that can hold a hundred of them.
 */
function fabo_departments_for(array $eventRowIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $eventRowIds))));
    if (!$ids) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    return fabo_all('SELECT * FROM event_departments WHERE event_row_id IN (' . $in . ')
                     ORDER BY event_row_id, name', str_repeat('i', count($ids)), $ids);
}

/**
 * Every department, for whoever is looking.
 *
 * Departments are no longer attached to an event, so there is nothing to
 * group them by: a client sees theirs, we see all of them.
 */
/**
 * Which portal a viewer is looking at: 0 for ours, the organisation for a client.
 *
 * The same numbering fabo_customiser() uses for column names and dropdown
 * options, so a department is scoped exactly the way everything else on the
 * sheet already is. Every place that lists, counts or acts on a department
 * asks this, so no two of them can disagree about who owns what.
 */
function fabo_dept_portal(array $viewer): int
{
    if (!empty($viewer['is_admin'])) {
        return 0;
    }
    require_once __DIR__ . '/lists.php';
    return fabo_customiser($viewer);
}

/**
 * Every department of one portal, and of no other.
 *
 * A department created in a client's portal is theirs; one created here is
 * ours. Neither shows in the other -- a sheet somebody shared with their own
 * spokes is not our business, and ours is not theirs.
 *
 * Ours are stored with client_id NULL rather than 0, so the comparison is on
 * COALESCE: both spellings have always meant the same thing.
 */
function fabo_departments_all(array $viewer, string $hub = ''): array
{
    try {
        /* Ordered by hub/spoke first: with one sheet per spoke, the list reads
           as the spokes it is shared with rather than as an alphabet of units.
           Departments with none sort last so they stand out as needing one. */
        $order  = ' ORDER BY (hub_spoke IS NULL OR hub_spoke = ""), hub_spoke, name, id';
        $mine   = fabo_dept_portal($viewer);
        $where  = ' WHERE COALESCE(client_id, 0) = ?';
        $types  = 'i';
        $args   = [$mine];
        if ($hub !== '') {
            $where  .= ' AND hub_spoke = ?';
            $types  .= 's';
            $args[]  = $hub;
        }
        return fabo_all('SELECT * FROM event_departments' . $where . $order, $types, $args);
    } catch (Throwable $e) {
        return [];
    }
}

/** Whether a department is this viewer's to see or act on. */
function fabo_dept_is_ours(array $viewer, ?array $dept): bool
{
    return $dept !== null
        && (int) ($dept['client_id'] ?? 0) === fabo_dept_portal($viewer);
}

function fabo_department(int $id): ?array
{
    return fabo_one('SELECT * FROM event_departments WHERE id = ?', 'i', [$id]);
}

/** How many rows and images a department has contributed. */
function fabo_dept_counts(int $id): array
{
    $rows = (int) (fabo_one('SELECT COUNT(*) c FROM `events` WHERE department_id = ?',
                            'i', [$id])['c'] ?? 0);
    $imgs = (int) (fabo_one('SELECT COUNT(*) c FROM attachments WHERE department_id = ?',
                            'i', [$id])['c'] ?? 0);
    return ['rows' => $rows, 'media' => $imgs];
}

/** Whether a department's link still works, and why not if it does not. */
function fabo_dept_state(array $d): string
{
    if (!empty($d['revoked_at'])) {
        return 'revoked';
    }
    if (!empty($d['expires_at']) && strtotime((string) $d['expires_at']) < time()) {
        return 'expired';
    }
    return 'ok';
}

/* ------------------------------------------------------------- writing */

/**
 * Creates a department and returns [id, url]. The sealed link is kept too
 * (fabo_dept_remember), so the portal can show it again until it is replaced.
 */
function fabo_dept_create(?int $eventRowId, ?int $clientId, array $in): array
{
    $name = trim((string) ($in['name'] ?? ''));
    if ($name === '') {
        throw new InvalidArgumentException('Choose which business unit this is.');
    }
    /* Checked against the list rather than trusted, because the select is a
       suggestion and the value posted with it is whatever the browser sent.
       A department that is not a business unit would tag its rows with a
       value the Business Unit column cannot show. */
    require_once __DIR__ . '/lists.php';
    $units = fabo_list_values('Business Unit');
    if ($units && !in_array($name, $units, true)) {
        throw new InvalidArgumentException(
            'Departments are business units now. Pick one from the list, or add it '
          . 'to Business Unit on Sheet Management first.');
    }
    /* Which hub or spoke this sheet is being shared for. Checked against the
       list for the same reason the unit is: what the browser posts is whatever
       the browser posts. Blank is allowed -- the links issued before this
       carry none, and refusing it here would make them unreissuable. */
    $hub = trim((string) ($in['hub_spoke'] ?? ''));
    if ($hub !== '') {
        $hubs = fabo_hub_values();
        if ($hubs && !in_array($hub, $hubs, true)) {
            throw new InvalidArgumentException(
                'Pick a hub or spoke from the list, or add it to '
              . FABO_HUB_LIST . ' on Sheet Management first.');
        }
    }

    [$secret, $hash] = fabo_link_secret();

    $days = (int) ($in['days'] ?? FABO_DEPT_TTL_DAYS);
    $days = max(1, min(365, $days));

    $id = (int) fabo_exec(
        'INSERT INTO event_departments
           (event_row_id, client_id, name, hub_spoke, contact_name, contact_email,
            token_hash, expires_at)
         VALUES (?,?,?,?,?,?,?,?)',
        'iissssss',
        /* No event: the sheet a department is sent is theirs to fill in, and
           the events on it come from them. The column stays for the links
           issued before this changed. */
        [$eventRowId ?: null, $clientId, mb_substr($name, 0, 160),
         $hub !== '' ? mb_substr($hub, 0, 120) : null,
         mb_substr(trim((string) ($in['contact_name'] ?? '')), 0, 160),
         mb_substr(trim((string) ($in['contact_email'] ?? '')), 0, 190),
         $hash, date('Y-m-d H:i:s', time() + $days * 86400)]
    );
    $token = fabo_dept_token($id, $secret);
    fabo_dept_remember($id, $token);
    return [$id, fabo_dept_url_of($token)];
}

/** Replaces the link, invalidating the old one. Returns the new url. */
function fabo_dept_rotate(int $id): string
{
    [$secret, $hash] = fabo_link_secret();
    /* A new link keeps the date the old one was set to run until, if that is
       still ahead; only a link already past it gets the default term. */
    $d     = fabo_department($id);
    $cur   = !empty($d['expires_at']) ? (int) strtotime((string) $d['expires_at']) : 0;
    $until = $cur > time() ? $cur : time() + FABO_DEPT_TTL_DAYS * 86400;
    fabo_exec('UPDATE event_departments SET token_hash = ?, revoked_at = NULL,
               expires_at = ? WHERE id = ?', 'ssi',
              [$hash, date('Y-m-d H:i:s', $until), $id]);
    $token = fabo_dept_token($id, $secret);
    fabo_dept_remember($id, $token);
    return fabo_dept_url_of($token);
}

/**
 * Make sure the column that keeps each department's current link exists.
 *
 * Added on demand, like dept_submitted_at, so a half-finished upload cannot
 * take the departments list down with it. Returns whether it is there.
 */
function fabo_dept_has_link_column(): bool
{
    static $have = null;
    if ($have !== null) {
        return $have;
    }
    try {
        if (fabo_all("SHOW COLUMNS FROM `event_departments` LIKE 'link_token'")) {
            return $have = true;
        }
        fabo_exec('ALTER TABLE `event_departments` ADD COLUMN `link_token` VARCHAR(400) NULL');
        return $have = true;
    } catch (Throwable $e) {
        error_log('FABO event_departments link_token: ' . $e->getMessage());
        return $have = false;
    }
}

/**
 * Keeps the sealed token of a department's current link, so the portal can
 * show and copy it again later rather than only once. It is the sealed form,
 * not the secret: it opens only with the portal's own key.
 */
function fabo_dept_remember(int $id, string $token): void
{
    if (fabo_dept_has_link_column()) {
        fabo_exec('UPDATE event_departments SET link_token = ? WHERE id = ?', 'si', [$token, $id]);
    }
}

/**
 * The department's current link, or '' when there is none to show: issued
 * before links were kept (a New link fixes that), or no longer live.
 */
function fabo_dept_link(array $d): string
{
    $token = (string) ($d['link_token'] ?? '');
    if ($token === '' || fabo_dept_state($d) !== 'ok') {
        return '';
    }
    return fabo_dept_url_of($token);
}

/**
 * Moves the date a department's link works until, to the end of that day.
 * The link itself does not change, so what was already sent keeps working --
 * including a link that had lapsed and is brought back by a later date.
 */
function fabo_dept_set_expiry(int $id, string $date): void
{
    $date = trim($date);
    $dt   = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$dt || $dt->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('Pick a valid date.');
    }
    $until = $dt->getTimestamp() + 86399;
    if ($until < time()) {
        throw new InvalidArgumentException('Pick today or a later date.');
    }
    if ($until > time() + 2 * 366 * 86400) {
        throw new InvalidArgumentException('A link can run for two years at most.');
    }
    fabo_exec('UPDATE event_departments SET expires_at = ? WHERE id = ?', 'si',
              [date('Y-m-d H:i:s', $until), $id]);
}

function fabo_dept_revoke(int $id): void
{
    fabo_exec('UPDATE event_departments SET revoked_at = NOW() WHERE id = ?', 'i', [$id]);
}

function fabo_dept_restore(int $id): void
{
    fabo_exec('UPDATE event_departments SET revoked_at = NULL WHERE id = ?', 'i', [$id]);
}

/**
 * Deletes a department. The rows it contributed are kept and simply lose
 * their tag — they are real data about the event, and throwing them away
 * because a link was tidied up would be the wrong trade every time.
 */
function fabo_dept_delete(int $id): void
{
    fabo_exec('UPDATE `events` SET department_id = NULL WHERE department_id = ?', 'i', [$id]);
    fabo_exec('UPDATE attachments SET department_id = NULL WHERE department_id = ?', 'i', [$id]);
    fabo_exec('DELETE FROM event_departments WHERE id = ?', 'i', [$id]);
}

/* --------------------------------------------------------------- links */

/** The sealed URL for a department. */
function fabo_dept_url(int $id, string $secret): string
{
    return fabo_dept_url_of(fabo_dept_token($id, $secret));
}

/** The sealed token for a department's secret. */
function fabo_dept_token(int $id, string $secret): string
{
    return fabo_seal(['d' => $id, 's' => $secret, 'v' => 1]);
}

/** The share url for a sealed token. */
function fabo_dept_url_of(string $token): string
{
    return fabo_portal_url() . 'd.php?t=' . $token;
}

/**
 * The department a token names, or null.
 *
 * Checks in this order, and says nothing different at any step: the token
 * opens, the department exists, the secret matches what was stored, and the
 * link is neither revoked nor expired.
 */
function fabo_dept_open(string $token): ?array
{
    $data = fabo_unseal($token);
    if (!$data || empty($data['d']) || empty($data['s'])) {
        return null;
    }
    $d = fabo_department((int) $data['d']);
    if (!$d) {
        return null;
    }
    if (!fabo_link_secret_ok((string) $data['s'], (string) $d['token_hash'])) {
        return null;
    }
    if (fabo_dept_state($d) !== 'ok') {
        return null;
    }

    /* Whose sheet this is, settled here rather than by each page that opens a
     * link.
     *
     * The sheet a department fills in is the organisation's own Events sheet:
     * their column names, the columns they have added, removed or arranged,
     * and their dropdown options. All of that is looked up against
     * fabo_view_owner(), and a page that reads the sheet before setting it
     * gets the shipped set instead -- which looked like the shared sheet
     * simply not keeping up.
     *
     * It is done inside here because this is the one function every entry
     * point already calls to turn a token into a department, so there is
     * nowhere left to forget it: d.php, api/deptsheet.php and download.php
     * all come through here.
     */
    require_once __DIR__ . '/lists.php';
    fabo_view_owner((int) ($d['client_id'] ?? 0));

    return $d;
}

/** Notes that somebody opened the link. */
function fabo_dept_touch(int $id): void
{
    fabo_exec('UPDATE event_departments
               SET opened_count = opened_count + 1, last_opened_at = NOW()
               WHERE id = ?', 'i', [$id]);
}

/* ------------------------------------------------- the rows they submit */

/** The event rows one department has contributed. */
/**
 * Make sure the column that separates a draft from a handed-in row exists.
 *
 * lib/db.php adds it on boot, but the portal is uploaded as loose files and
 * for a while after an upload some are new and some are not. Without this, a
 * lib/db.php that had not arrived yet meant every query naming the column
 * failed and took the sheets down with it -- a half-finished upload reading
 * as a broken feature.
 *
 * Returns whether the column is there. Asked once per request.
 */
function fabo_dept_has_submitted_column(): bool
{
    static $have = null;
    if ($have !== null) {
        return $have;
    }
    try {
        $res = fabo_all("SHOW COLUMNS FROM `events` LIKE 'dept_submitted_at'");
        if ($res) {
            return $have = true;
        }
        /* Not there: add it, and count everything already contributed as
           handed in -- those rows have been on the main sheet for weeks and
           must not drop off it into somebody's shared sheet as drafts. */
        fabo_exec('ALTER TABLE `events` ADD COLUMN `dept_submitted_at` DATETIME NULL');
        fabo_exec('UPDATE `events` SET `dept_submitted_at` = COALESCE(`created_at`, NOW())
                    WHERE `department_id` IS NOT NULL');
        return $have = true;
    } catch (Throwable $e) {
        error_log('FABO dept_submitted_at: ' . $e->getMessage());
        return $have = false;
    }
}

/**
 * The rows on a department's shared sheet -- the ones still being filled in.
 *
 * Handed-in rows are deliberately left out: submitting clears the sheet so it
 * is ready for the next round, and what was handed in is on the main sheet
 * where it now belongs.
 */
function fabo_dept_rows(int $id): array
{
    if (!fabo_dept_has_submitted_column()) {
        // No column to tell them apart yet: every row is still theirs.
        return fabo_all('SELECT * FROM `events` WHERE department_id = ? ORDER BY id',
                        'i', [$id]);
    }
    return fabo_all('SELECT * FROM `events`
                      WHERE department_id = ? AND dept_submitted_at IS NULL
                      ORDER BY id', 'i', [$id]);
}

/**
 * Hand in everything on a department's sheet.
 *
 * Nothing is moved or copied -- the rows are already on the Events table.
 * What changes is that they stop being drafts, which is one column, so this
 * cannot half-finish and lose somebody's month.
 *
 * Returns how many rows were handed in; zero when there was nothing to hand.
 */
function fabo_dept_submit(int $id): int
{
    if (!fabo_dept_has_submitted_column()) {
        throw new RuntimeException(
            'This sheet cannot be submitted yet: the portal is still being '
          . 'updated. Try again in a few minutes.');
    }
    $n = (int) (fabo_one('SELECT COUNT(*) c FROM `events`
                           WHERE department_id = ? AND dept_submitted_at IS NULL',
                         'i', [$id])['c'] ?? 0);
    if ($n === 0) {
        return 0;
    }
    /* The budgets on these rows go to the admin for review. Every amount a
       department asked for waits for a person -- within its limit or not --
       because a department is outside the team, and its figures are
       requests, not entries. The admin approves or sends back each head from
       the Budget Required column on Programs Data / Upcoming Events. */
    try {
        fabo_exec("UPDATE budget_lines l
                     JOIN `events` e ON e.id = l.row_id
                      SET l.decision = 'pending', l.remarks = NULL,
                          l.decided_by = NULL, l.decided_at = NULL
                    WHERE l.sheet_key = 'events' AND e.department_id = ?
                      AND e.dept_submitted_at IS NULL
                      AND l.decision IN ('draft', 'none') AND l.required > 0", 'i', [$id]);
    } catch (Throwable $e) {
        error_log('FABO dept budget submit: ' . $e->getMessage());   // no budget table yet
    }
    fabo_exec('UPDATE `events` SET dept_submitted_at = NOW()
                WHERE department_id = ? AND dept_submitted_at IS NULL', 'i', [$id]);
    return $n;
}

/**
 * Send everything a department handed in back to its shared sheet.
 *
 * The undo of fabo_dept_submit(), for a sheet submitted by mistake: the rows
 * become drafts again, so they leave the main sheet and reappear on the
 * department's link to be edited and submitted afresh. Budget heads still
 * waiting for a decision go back to unsent; ones already approved or sent
 * back keep that decision.
 *
 * Returns how many rows were sent back.
 */
function fabo_dept_reopen(int $id): int
{
    if (!fabo_dept_has_submitted_column()) {
        return 0;
    }
    $n = (int) (fabo_one('SELECT COUNT(*) c FROM `events`
                           WHERE department_id = ? AND dept_submitted_at IS NOT NULL',
                         'i', [$id])['c'] ?? 0);
    if ($n === 0) {
        return 0;
    }
    try {
        fabo_exec("UPDATE budget_lines l
                     JOIN `events` e ON e.id = l.row_id
                      SET l.decision = 'draft'
                    WHERE l.sheet_key = 'events' AND e.department_id = ?
                      AND e.dept_submitted_at IS NOT NULL
                      AND l.decision = 'pending'", 'i', [$id]);
    } catch (Throwable $e) {
        error_log('FABO dept budget reopen: ' . $e->getMessage());   // no budget table yet
    }
    fabo_exec('UPDATE `events` SET dept_submitted_at = NULL
                WHERE department_id = ? AND dept_submitted_at IS NOT NULL', 'i', [$id]);
    return $n;
}

/** How many rows are waiting to be handed in, and how many already have been. */
function fabo_dept_submit_counts(int $id): array
{
    if (!fabo_dept_has_submitted_column()) {
        return ['draft' => count(fabo_dept_rows($id)), 'sent' => 0];
    }
    $r = fabo_one('SELECT SUM(dept_submitted_at IS NULL) draft,
                          SUM(dept_submitted_at IS NOT NULL) sent
                     FROM `events` WHERE department_id = ?', 'i', [$id]);
    return ['draft' => (int) ($r['draft'] ?? 0), 'sent' => (int) ($r['sent'] ?? 0)];
}

/** One of their rows, or null — the id alone is never enough. */
/**
 * One row of a department's sheet, while it is still theirs to change.
 *
 * A handed-in row is not: it is on the main sheet now, and letting the link
 * go on editing it would mean the figures somebody reported could change
 * after they reported them.
 */
function fabo_dept_row(int $deptId, int $rowId): ?array
{
    if (!fabo_dept_has_submitted_column()) {
        return fabo_one('SELECT * FROM `events` WHERE id = ? AND department_id = ?',
                        'ii', [$rowId, $deptId]);
    }
    return fabo_one('SELECT * FROM `events`
                      WHERE id = ? AND department_id = ? AND dept_submitted_at IS NULL',
                    'ii', [$rowId, $deptId]);
}

/**
 * Adds an empty row for a department, filed under the same client as the
 * event it belongs to, so it lands in the right organisation's data.
 */
function fabo_dept_add_row(array $dept): int
{
    $sheet = fabo_sheet('events');
    /* A department's sheet is not part of somebody else's event any more, so
       a new row starts empty rather than as a copy of a parent. The one thing
       it is given is the department's own business unit, because that is what
       the department IS — see fabo_may_make() and the Business Unit column. */
    $event = (int) ($dept['event_row_id'] ?? 0) > 0
        ? fabo_one('SELECT client_id, event_name, brand, business_unit,
                           start_date, end_date, city
                    FROM `events` WHERE id = ?', 'i', [(int) $dept['event_row_id']])
        : null;
    $event = $event ?: ['client_id' => $dept['client_id'] ?? null, 'event_name' => '',
                        'brand' => '', 'business_unit' => '', 'start_date' => null,
                        'end_date' => null, 'city' => ''];

    /*
     * The row starts as a copy of the event it belongs to — its name, dates
     * and place — so it is unmistakably part of that event rather than a new
     * one. The department then changes whatever their part differs on.
     */
    require_once __DIR__ . '/endpoint.php';
    require_once __DIR__ . '/lists.php';
    /* The department's name goes into Department only when it IS one of the
       Department options. Links made before departments had to be business
       units were named after spokes, and stamping those names in put spoke
       names into a column whose list has none -- every one then appeared in
       that column's dropdown as an extra option. */
    $units  = fabo_list_values('Business Unit');
    $myUnit = (string) ($dept['name'] ?? '');
    if ($units && !in_array($myUnit, $units, true)) {
        $myUnit = in_array((string) ($event['business_unit'] ?? ''), $units, true)
            ? (string) $event['business_unit'] : '';
    }
    return (int) fabo_exec(
        'INSERT INTO `events` (`' . $sheet['id_field'] . '`, client_id, department_id,
             event_name, brand, business_unit, hub_spoke, start_date, end_date, city)
         VALUES (?,?,?,?,?,?,?,?,?,?)',
        'siissssss' . 's',
        [fabo_next_id($sheet), $event['client_id'] ?? null, (int) $dept['id'],
         (string) ($event['event_name'] ?? ''),
         (string) ($event['brand'] ?? ''),
         /* The department's own unit, not the parent event's: the whole point
            of a department row is that it is one team's part of the event, and
            the Business Unit column is where that now reads. */
         $myUnit,
         /* And the hub or spoke the sheet was shared for, so a row says where
            it came from without the spoke having to pick it on every line. */
         (string) ($dept['hub_spoke'] ?? ''),
         $event['start_date'] ?: null, $event['end_date'] ?: null,
         (string) ($event['city'] ?? '')]
    );
}

/** Media on an event, newest first. Images only — this is a gallery. */
/**
 * The same gallery across several events.
 *
 * Capped, because the desk can have a hundred events in view and a gallery
 * that loads every photograph any of them ever collected is a page nobody
 * waits for. Newest first, so the cap cuts the oldest.
 */
/**
 * The photographs to show in Departments & collection.
 *
 * $portal keeps the gallery to one portal's own: a picture sent up through a
 * client's shared sheet belongs in their portal, not in ours. Passing null
 * keeps every picture, which is what the older event-based callers expect.
 */
function fabo_media_for(array $eventRowIds, int $max = 60, ?int $portal = null): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $eventRowIds))));
    if (!$ids) {
        return [];
    }
    $in   = implode(',', array_fill(0, count($ids), '?'));
    $args = array_merge($ids, $ids);
    $rows = fabo_all("SELECT a.*, d.name AS dept_name, pe.event_name AS event_name
                      FROM attachments a
                      LEFT JOIN event_departments d ON d.id = a.department_id
                      LEFT JOIN `events` e  ON e.id = a.row_id
                      LEFT JOIN `events` pe ON pe.id = COALESCE(d.event_row_id, a.row_id)
                      WHERE a.sheet_key = 'events'
                        AND (a.row_id IN (" . $in . ")
                             OR e.department_id IN (SELECT id FROM event_departments
                                                    WHERE event_row_id IN (" . $in . ")))
                      ORDER BY a.created_at DESC, a.id DESC
                      LIMIT " . (int) ($max * 3),
                     str_repeat('i', count($args)), $args);
    $out = array_values(array_filter($rows,
        static fn($r) => fabo_is_image((string) $r['mime'])));

    if ($portal !== null) {
        /* Which portal each department belongs to, so a picture sent up
           through somebody else's shared sheet does not appear here. A
           picture with no department at all is an event's own and stays. */
        $owner = [];
        foreach (fabo_all('SELECT id, COALESCE(client_id, 0) o FROM event_departments') as $d) {
            $owner[(int) $d['id']] = (int) $d['o'];
        }
        $out = array_values(array_filter($out, static function ($r) use ($owner, $portal) {
            $d = (int) ($r['department_id'] ?? 0);
            return $d === 0 || ($owner[$d] ?? -1) === $portal;
        }));
    }
    return array_slice($out, 0, $max);
}

function fabo_event_media(int $eventRowId): array
{
    /* The event's own images plus everything on the rows its departments
       contributed — one gallery for the event, wherever a photograph was
       actually attached. */
    $rows = fabo_all("SELECT a.*, d.name AS dept_name
                      FROM attachments a
                      LEFT JOIN event_departments d ON d.id = a.department_id
                      LEFT JOIN `events` e ON e.id = a.row_id
                      WHERE a.sheet_key = 'events'
                        AND (a.row_id = ?
                             OR e.department_id IN (SELECT id FROM event_departments
                                                    WHERE event_row_id = ?))
                      ORDER BY a.created_at DESC, a.id DESC",
                     'ii', [$eventRowId, $eventRowId]);
    return array_values(array_filter($rows, static fn($r) => fabo_is_image((string) $r['mime'])));
}

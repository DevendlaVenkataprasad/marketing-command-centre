<?php
/**
 * Event results -- the follow-up after an event added from a live sheet.
 *
 * An Upcoming event is added with its plan; the results (Achieved figures,
 * spend, leads, photographs, case studies) can only be typed once its last
 * day has passed. By then a department has handed the row in (d.php) and
 * can no longer change it, and a shared sheet (s.php) is somewhere they have
 * to find again, with their row somewhere in it. So every event added from
 * either gets a results record here:
 *
 *   - who owes the results: a name and an email, asked when the event is
 *     added, defaulting to the person the sheet was sent to;
 *   - its own link (r.php?k=...), which opens one short form with only the
 *     results columns of that one event -- no sheet, no grid -- and works
 *     after the row has been handed in;
 *   - where it stands: Not due, Due, Overdue, In progress or Submitted. The
 *     Events sheet shows it in its Results column, and both live sheets list
 *     the events still owing results above their grids.
 *
 * Reminders (fabo_evres_sweep(), run from cron.php and, throttled, on page
 * loads) are for events entered as Upcoming from the day this went in, and
 * no others: the owner is emailed the day after the event ends, again on day 3
 * and day 7, and on day 10 the organisation is told which events are still
 * without results. Only events with nothing typed into any results column
 * are chased; one that has been started is in progress, not forgotten.
 *
 * Events that ended before this was installed are not chased and read
 * "Not recorded" / "Recorded" -- a list of last year's events all marked
 * Overdue would bury the ones that matter.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sheets.php';
require_once __DIR__ . '/compute.php';

/** Days after the end date on which the owner is reminded, in turn. */
const FABO_EVRES_REMIND_DAYS = [1, 3, 7];
/** Days after the end date at which the organisation is told. */
const FABO_EVRES_ESCALATE_DAY = 10;
/** Past this many days the event is Overdue rather than Due. */
const FABO_EVRES_OVERDUE_DAY = 7;
/** Events that ended longer ago than this are never mailed about. */
const FABO_EVRES_MAIL_WINDOW = 30;
/** How far before installation an event's end may be and still be followed up. */
const FABO_EVRES_GRACE_DAYS = 7;

/** The table, and the day it was installed. Safe to run every time. */
function fabo_evres_install(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $db = fabo_db();
    $db->query("CREATE TABLE IF NOT EXISTS `event_results` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `row_id` INT UNSIGNED NOT NULL,
  `client_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `share_id` INT UNSIGNED NULL,
  `dept_id` INT UNSIGNED NULL,
  `owner_name` VARCHAR(160) NULL,
  `owner_email` VARCHAR(190) NULL,
  `token` CHAR(40) NOT NULL,
  `state` VARCHAR(12) NOT NULL DEFAULT 'open',
  `submitted_at` DATETIME NULL,
  `submitted_by` VARCHAR(160) NULL,
  `remind` TINYINT(1) NOT NULL DEFAULT 0,
  `reminders` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `last_reminded_at` DATETIME NULL,
  `escalated_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `u_row` (`row_id`),
  UNIQUE KEY `u_token` (`token`),
  KEY `k_share` (`share_id`),
  KEY `k_dept` (`dept_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // The first version had no department link.
    $res = @$db->query("SHOW COLUMNS FROM `event_results` LIKE 'dept_id'");
    if ($res && $res->num_rows === 0) {
        $db->query('ALTER TABLE `event_results` ADD COLUMN `dept_id` INT UNSIGNED NULL AFTER `share_id`,
                    ADD KEY `k_dept` (`dept_id`)');
    }
    /* Whether this event is chased by email: only Upcoming events entered
       once this was in place (fabo_evres_ensure(..., remind: true)). */
    $res = @$db->query("SHOW COLUMNS FROM `event_results` LIKE 'remind'");
    if ($res && $res->num_rows === 0) {
        $db->query('ALTER TABLE `event_results` ADD COLUMN `remind` TINYINT(1) NOT NULL DEFAULT 0 AFTER `state`');
    }
    require_once __DIR__ . '/google.php';
    if (fabo_setting('evres_installed') === '') {
        fabo_setting_put('evres_installed', date('Y-m-d'));
    }
}

/** Events ending before this day are history, not followed up. */
function fabo_evres_since(): string
{
    static $since = null;
    if ($since === null) {
        try {
            fabo_evres_install();
            $on = fabo_setting('evres_installed') ?: date('Y-m-d');
        } catch (Throwable $e) {
            $on = date('Y-m-d');
        }
        $since = date('Y-m-d', strtotime($on . ' -' . FABO_EVRES_GRACE_DAYS . ' days'));
    }
    return $since;
}

/* ------------------------------------------------------------ records */

/** The records of these events, by row id. */
function fabo_evres_records(array $rowIds): array
{
    $rowIds = array_values(array_unique(array_filter(array_map('intval', $rowIds))));
    if (!$rowIds) {
        return [];
    }
    try {
        fabo_evres_install();
        $out = [];
        foreach (array_chunk($rowIds, 500) as $ids) {
            foreach (fabo_all('SELECT * FROM event_results WHERE row_id IN (' . implode(',', $ids) . ')') as $r) {
                $out[(int) $r['row_id']] = $r;
            }
        }
        return $out;
    } catch (Throwable $e) {
        error_log('FABO evres records: ' . $e->getMessage());
        return [];
    }
}

/**
 * The record of one event, made if it has none -- through a share ($share)
 * or a department's sheet ($dept). Who owes the results is given when the
 * event is added; otherwise it is whoever the sheet was sent to. Never moves
 * an existing owner.
 */
function fabo_evres_ensure(int $rowId, ?array $share, string $name = '', string $email = '',
                           ?array $dept = null, bool $remind = false): ?array
{
    fabo_evres_install();
    $have = fabo_one('SELECT * FROM event_results WHERE row_id = ?', 'i', [$rowId]);
    if ($have) {
        return $have;
    }
    $from  = $share ?: $dept;
    $name  = trim($name) !== '' ? trim($name) : trim((string) ($from['contact_name'] ?? ''));
    $email = trim($email) !== '' ? trim($email) : trim((string) ($from['contact_email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $email = '';
    }
    $org = $share ? (int) ($share['filed_under'] ?? 0) : (int) ($dept['client_id'] ?? 0);
    try {
        fabo_exec('INSERT INTO event_results (row_id, client_id, share_id, dept_id, owner_name, owner_email, token, remind)
                   VALUES (?,?,?,?,?,?,?,?)', 'iiiisssi',
                  [$rowId, $org, $share ? (int) $share['id'] : null, $dept ? (int) $dept['id'] : null,
                   $name !== '' ? mb_substr($name, 0, 160) : null,
                   $email !== '' ? mb_substr($email, 0, 190) : null, bin2hex(random_bytes(20)), $remind ? 1 : 0]);
    } catch (Throwable $e) {
        // Made at the same moment by another request: theirs stands.
    }
    return fabo_one('SELECT * FROM event_results WHERE row_id = ?', 'i', [$rowId]);
}

/** The record a results link names; null for anything else. */
function fabo_evres_by_token(string $token): ?array
{
    $token = trim($token);
    if (!preg_match('~^[0-9a-f]{40}$~', $token)) {
        return null;
    }
    try {
        fabo_evres_install();
        return fabo_one('SELECT * FROM event_results WHERE token = ?', 's', [$token]);
    } catch (Throwable $e) {
        return null;
    }
}

/* ------------------------------------------------------------- the URL */

/**
 * The portal's address. A page request knows it; cron, run from the command
 * line, does not -- so it is remembered from the last request that did.
 */
function fabo_evres_base(): string
{
    require_once __DIR__ . '/ui.php';
    require_once __DIR__ . '/google.php';
    if (PHP_SAPI !== 'cli' && !empty($_SERVER['HTTP_HOST'])) {
        $base = rtrim(fabo_portal_url(), '/') . '/';
        if (fabo_setting('portal_base_url') !== $base) {
            fabo_setting_put('portal_base_url', $base);
        }
        return $base;
    }
    return fabo_setting('portal_base_url');
}

/** The results link of one record; '' when the address is not known yet. */
function fabo_evres_url(array $rec): string
{
    $base = fabo_evres_base();
    return $base === '' ? '' : $base . 'r.php?k=' . $rec['token'];
}

/* -------------------------------------------------------------- state */

/** The columns that are an event's results: Completed-only, typed by a person. */
function fabo_evres_fields(array $sheet): array
{
    require_once __DIR__ . '/eventstages.php';
    $out = [];
    foreach ($sheet['columns'] as $c) {
        if (in_array($c[2], ['id', 'computed'], true) || fabo_col_kept($c) !== '') {
            continue;
        }
        if (fabo_evstage_of((string) $c[1]) === 'result') {
            $out[(string) $c[1]] = $c;
        }
    }
    return $out;
}

/** Has anything been typed into the results? (Pictures count.) */
function fabo_evres_started(array $row, array $fields, int $images = 0): bool
{
    foreach ($fields as $f => $c) {
        if ($c[2] === 'images') {
            if ($images > 0) {
                return true;
            }
            continue;
        }
        $v = $row[$f] ?? null;
        if ($v !== null && trim((string) $v) !== '') {
            return true;
        }
    }
    return false;
}

/** The day the event ended: End Date, or Start Date when there is none. */
function fabo_evres_end(array $row): string
{
    $d = trim((string) ($row['end_date'] ?? ''));
    if ($d === '') {
        $d = trim((string) ($row['start_date'] ?? ''));
    }
    return ($d !== '' && strtotime($d)) ? date('Y-m-d', strtotime($d)) : '';
}

/**
 * Where an event's results stand:
 *   [key, label, tone, days since it ended (null before)].
 * key: none (no dates), notdue, due, overdue, started, submitted, and
 * old (ended before this was installed, no record: not followed up).
 */
function fabo_evres_status(array $row, ?array $rec, bool $started): array
{
    if ($rec && (string) $rec['state'] === 'submitted') {
        return ['submitted', 'Submitted', 'tone-good', null];
    }
    $end = fabo_evres_end($row);
    if ($end === '') {
        return ['none', '', '', null];
    }
    $days = (int) floor((strtotime(fabo_today()) - strtotime($end)) / 86400);
    if ($days < 0) {
        return ['notdue', 'Not due', '', null];
    }
    if (!$rec && $end < fabo_evres_since()) {
        return ['old', $started ? 'Recorded' : 'Not recorded', '', $days];
    }
    if ($started) {
        return ['started', 'In progress', 'tone-warn', $days];
    }
    if ($days > FABO_EVRES_OVERDUE_DAY) {
        return ['overdue', 'Overdue', 'tone-risk', $days];
    }
    return ['due', 'Due', 'tone-warn', $days];
}

/** Pictures on each of these events, by row id. */
function fabo_evres_picture_counts(array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) {
        return [];
    }
    $out = [];
    try {
        foreach (fabo_all("SELECT row_id, COUNT(*) n FROM attachments
                            WHERE sheet_key = 'events' AND row_id IN (" . implode(',', $ids) . ")
                              AND mime LIKE 'image/%' AND COALESCE(caption, '') NOT IN ('file')
                              AND COALESCE(caption, '') NOT LIKE '\\_\\_cell:%'
                            GROUP BY row_id") as $a) {
            $out[(int) $a['row_id']] = (int) $a['n'];
        }
    } catch (Throwable $e) {
        // No pictures counted: the typed columns still decide.
    }
    return $out;
}

/**
 * Fill the Results column (results_state) and $row['_results'] on rows of
 * the Events sheet, with one query for all of them. Called through
 * fabo_fill_assets_approval(), so the grid, the views, the export and both
 * live sheets all say the same thing.
 */
function fabo_evres_fill(array $rows): array
{
    if (!$rows) {
        return $rows;
    }
    $sheet = fabo_sheet('events');
    if (!$sheet) {
        return $rows;
    }
    $fields = fabo_evres_fields($sheet);
    $ids    = array_map(static fn($r) => (int) ($r['id'] ?? 0), $rows);
    $recs   = fabo_evres_records($ids);
    $hasGallery = false;
    foreach ($fields as $c) {
        $hasGallery = $hasGallery || $c[2] === 'images';
    }
    $pics = $hasGallery ? fabo_evres_picture_counts($ids) : [];
    foreach ($rows as $i => $r) {
        $id      = (int) ($r['id'] ?? 0);
        $rec     = $recs[$id] ?? null;
        $started = fabo_evres_started($r, $fields, $pics[$id] ?? 0);
        [$key, $label, $tone, $days] = fabo_evres_status($r, $rec, $started);
        if ($key === 'submitted' && !empty($rec['submitted_at'])) {
            $label .= ' ' . date('d M', strtotime((string) $rec['submitted_at']));
        }
        $rows[$i]['results_state'] = $label;
        $rows[$i]['_results'] = ['key' => $key, 'label' => $label, 'days' => $days,
                                 'started' => $started, 'rec' => $rec];
        if ($tone !== '') {
            $rows[$i]['_tone'] = $rows[$i]['_tone'] ?? [];
            $rows[$i]['_tone']['results_state'] = $tone;
        }
    }
    return $rows;
}

/** Does this row still owe results someone should be asked for? */
function fabo_evres_owing(array $row): bool
{
    $k = $row['_results']['key'] ?? '';
    return in_array($k, ['due', 'overdue', 'started'], true);
}

/** One line of a "Results due" list, as both live sheets draw it. */
function fabo_evres_item(array $row, array $rec): array
{
    $res = $row['_results'];
    $end = fabo_evres_end($row);
    return [
        'key'   => $res['key'],
        'label' => $res['label'],
        'days'  => $res['days'],
        'title' => trim((string) ($row['event_name'] ?? '')) ?: 'Event ' . ($row['event_id'] ?? '#' . $row['id']),
        'ref'   => (string) ($row['event_id'] ?? ''),
        'ended' => $end !== '' ? date('d M Y', strtotime($end)) : '',
        'url'   => fabo_evres_url($rec),
        'owner' => (string) ($rec['owner_name'] ?? ''),
    ];
}

/* ------------------------------------------------- on the shared sheet */

/**
 * What the shared sheet (s.php) needs to know about one row's results:
 * null when it owes none here (not ended, history, or not this share's
 * event). Makes the row's record the first time it is due, so its button
 * has a link.
 */
function fabo_evres_share_info(array $share, array $row): ?array
{
    $res = $row['_results'] ?? null;
    if (!$res || !in_array($res['key'], ['due', 'overdue', 'started', 'submitted'], true)) {
        return null;
    }
    $rec = $res['rec'];
    if (!$rec) {
        if ((int) ($row['share_id'] ?? 0) !== (int) $share['id']) {
            return null;                    // the team's own event: theirs to record
        }
        try {
            $rec = fabo_evres_ensure((int) $row['id'], $share);
        } catch (Throwable $e) {
            error_log('FABO evres ensure: ' . $e->getMessage());
            return null;
        }
        if (!$rec) {
            return null;
        }
    } elseif ((int) ($rec['share_id'] ?? 0) !== (int) $share['id']
              && (int) ($row['share_id'] ?? 0) !== (int) $share['id']) {
        return null;
    }
    return fabo_evres_item($row, $rec);
}

/* ---------------------------------------------- on a department's sheet */

/**
 * The events a department has handed in that have ended and still owe their
 * results -- the list above its sheet (d.php). Handed-in rows are not on
 * the sheet any more, so this list is the way back to them.
 */
function fabo_evres_dept_due(array $dept): array
{
    require_once __DIR__ . '/departments.php';
    if (!fabo_dept_has_submitted_column()) {
        return [];
    }
    try {
        $rows = fabo_all("SELECT * FROM `events`
                           WHERE department_id = ? AND dept_submitted_at IS NOT NULL
                             AND COALESCE(end_date, start_date) BETWEEN ? AND ?
                           ORDER BY COALESCE(end_date, start_date)",
                         'iss', [(int) $dept['id'], fabo_evres_since(), fabo_today()]);
    } catch (Throwable $e) {
        error_log('FABO evres dept due: ' . $e->getMessage());
        return [];
    }
    $out = [];
    foreach (fabo_compute_all('events', $rows) as $r) {
        if (!fabo_evres_owing($r)) {
            continue;
        }
        $rec = $r['_results']['rec'] ?: fabo_evres_ensure((int) $r['id'], null, '', '', $dept);
        if ($rec) {
            $out[] = fabo_evres_item($r, $rec);
        }
    }
    usort($out, static fn($a, $b) => ([ 'overdue' => 0, 'due' => 1, 'started' => 2][$a['key']] ?? 3)
                                     <=> ([ 'overdue' => 0, 'due' => 1, 'started' => 2][$b['key']] ?? 3)
                                     ?: ($b['days'] ?? 0) <=> ($a['days'] ?? 0));
    return $out;
}

/* ------------------------------------------------- the results form */

/**
 * Everything r.php needs to work on one record's event: the share or the
 * department behind it, the Events sheet as that organisation sees it, and
 * who is filling it in. Null when the link behind it has been closed.
 *
 * A closed share or a withdrawn department link closes its results links;
 * an expired one does not -- the results of an event planned under it are
 * still owed.
 */
function fabo_evres_context(array $rec): ?array
{
    require_once __DIR__ . '/shares.php';
    require_once __DIR__ . '/lists.php';
    if (!empty($rec['share_id'])) {
        $share = fabo_one('SELECT * FROM sheet_shares WHERE id = ?', 'i', [(int) $rec['share_id']]);
        if (!$share || empty($share['active'])) {
            return null;
        }
        $sheet = fabo_share_sheet($share);
        return $sheet ? fabo_evres_share_ctx($share, $sheet) : null;
    }
    if (!empty($rec['dept_id'])) {
        require_once __DIR__ . '/departments.php';
        $dept = fabo_department((int) $rec['dept_id']);
        if (!$dept || !empty($dept['revoked_at'])) {
            return null;
        }
        fabo_view_owner((int) ($dept['client_id'] ?? 0));
        $sheet = fabo_sheet('events');
        return $sheet ? ['kind' => 'dept', 'share' => null, 'dept' => $dept, 'sheet' => $sheet,
                         'org' => (int) ($dept['client_id'] ?? 0), 'unit' => (string) $dept['name'],
                         'contact' => (string) ($dept['contact_name'] ?? '')] : null;
    }
    return null;
}

/** The context of a share already open (see fabo_evres_context()). */
function fabo_evres_share_ctx(array $share, array $sheet): array
{
    return ['kind' => 'share', 'share' => $share, 'dept' => null, 'sheet' => $sheet,
            'org' => (int) $share['filed_under'], 'unit' => (string) $share['department'],
            'contact' => (string) $share['contact_name']];
}

/** The event itself, computed, while it is still this link's to reach. */
function fabo_evres_ctx_row(array $ctx, int $rowId): ?array
{
    if ($ctx['kind'] === 'share') {
        return fabo_share_row($ctx['share'], $ctx['sheet'], $rowId);
    }
    $r = fabo_one('SELECT * FROM `events` WHERE id = ? AND department_id = ?', 'ii',
                  [$rowId, (int) $ctx['dept']['id']]);
    return $r ? (fabo_compute_all('events', [$r])[0] ?? $r) : null;
}

/**
 * The results columns the form offers, in the sheet's order:
 * [field => [label, type, list, mode (edit|file|images), col]].
 */
function fabo_evres_ctx_fields(array $ctx): array
{
    require_once __DIR__ . '/eventstages.php';
    $out = [];
    if ($ctx['kind'] === 'share') {
        foreach (fabo_share_columns($ctx['sheet'], $ctx['share']) as $f => $m) {
            if (fabo_evstage_of($f) === 'result' && in_array($m['mode'], ['edit', 'file', 'images'], true)) {
                $out[$f] = $m;
            }
        }
        return $out;
    }
    require_once __DIR__ . '/departments.php';
    $skip = array_flip(fabo_dept_not_theirs());
    foreach ($ctx['sheet']['columns'] as $c) {
        [$label, $f, $type] = $c;
        $meta = $c[3] ?? [];
        if (isset($skip[$f]) || in_array($type, ['id', 'computed'], true) || fabo_col_kept($c) !== ''
            || fabo_evstage_of($f) !== 'result') {
            continue;
        }
        $list = (string) ($meta['list'] ?? '');
        $out[$f] = [
            'label' => ($meta['group'] ?? '') !== '' ? $meta['group'] . ' — ' . $label : $label,
            'type'  => $type,
            'list'  => ($list !== '' && !fabo_is_dynamic_list($list)) ? $list : null,
            'mode'  => $type === 'images' ? 'images' : (in_array($type, ['file', 'image'], true) ? 'file' : 'edit'),
            'col'   => $c,
        ];
    }
    return $out;
}

/** A line of the row's history, under the person and department behind the link. */
function fabo_evres_ctx_audit(array $ctx, int $rowId, string $field, $old, $new): void
{
    if ($ctx['kind'] === 'share') {
        fabo_share_audit($ctx['share'], $ctx['sheet'], $rowId, $field, $old, $new);
        return;
    }
    try {
        fabo_exec('INSERT INTO activity_audit (who, client_id, table_name, row_id, field, old_value, new_value)
                   VALUES (?,?,?,?,?,?,?)', 'sisisss',
                  [mb_substr(trim($ctx['contact'] . ' · ' . $ctx['unit'], ' ·') . ' (results form)', 0, 160),
                   $ctx['org'] > 0 ? $ctx['org'] : null, 'events', $rowId, $field,
                   $old === null ? null : mb_substr((string) $old, 0, 2000),
                   $new === null ? null : mb_substr((string) $new, 0, 2000)]);
    } catch (Throwable $e) {
        error_log('FABO evres audit: ' . $e->getMessage());
    }
}

/**
 * Save one results cell. Returns the row afterwards; throws
 * InvalidArgumentException, in words for the form, when it may not.
 */
function fabo_evres_ctx_save(array $ctx, array $row, array $fields, string $field, string $value): array
{
    if ($ctx['kind'] === 'share') {
        return fabo_share_save($ctx['share'], $ctx['sheet'], (int) $row['id'], $field, $value);
    }
    $m = $fields[$field] ?? null;
    if (!$m || $m['mode'] !== 'edit') {
        throw new InvalidArgumentException('That column is not on this form.');
    }
    require_once __DIR__ . '/grid.php';
    require_once __DIR__ . '/endpoint.php';
    if (fabo_cell_locked($m['col'], $row)) {
        throw new InvalidArgumentException(fabo_cell_lock_note($m['col'], $row));
    }
    if ($m['type'] === 'checkbox') {
        $value = in_array(strtolower(trim($value)), ['1', 'on', 'yes', 'true'], true) ? '1' : '0';
    }
    $new = fabo_coerce($m['type'], $value);
    $why = fabo_list_refusal($m['col'], $new, $row[$field] ?? null);
    if ($why !== '') {
        throw new InvalidArgumentException($why);
    }
    $old = $row[$field] ?? null;
    fabo_exec('UPDATE `events` SET `' . $field . '` = ? WHERE id = ? AND department_id = ?',
              is_int($new) ? 'iii' : (is_float($new) ? 'dii' : 'sii'),
              [$new, (int) $row['id'], (int) $ctx['dept']['id']]);
    if ((string) $old !== (string) ($new ?? '')) {
        fabo_evres_ctx_audit($ctx, (int) $row['id'], $field, $old, $new);
    }
    if ($field === 'status') {
        require_once __DIR__ . '/approvals.php';
        fabo_signoff_on_complete('events', (int) $row['id']);
    }
    return fabo_evres_ctx_row($ctx, (int) $row['id']) ?? $row;
}

/** Store one uploaded file on the event. Returns the attachment id; throws on refusal. */
function fabo_evres_ctx_store(array $ctx, int $rowId, array $file, string $caption, bool $imagesOnly): int
{
    if ($ctx['kind'] === 'share') {
        return (int) fabo_share_store($ctx['share'], 'events', $rowId, $file, $caption, $imagesOnly)[0];
    }
    require_once __DIR__ . '/uploads.php';
    [$ok, $msg, $stored] = fabo_store_upload($file);
    if (!$ok || !$stored) {
        throw new InvalidArgumentException(($file['name'] ?? 'File') . ': ' . $msg);
    }
    if ($imagesOnly && !fabo_is_image((string) $stored['mime'])) {
        fabo_delete_stored((string) $stored['stored_name']);
        throw new InvalidArgumentException(($file['name'] ?? 'File') . ': that is not an image.');
    }
    return (int) fabo_exec('INSERT INTO attachments (sheet_key, row_id, original_name, stored_name, mime,
                                                      size_bytes, caption, uploaded_by, department_id)
                             VALUES (?,?,?,?,?,?,?,?,?)', 'sissssssi',
        ['events', $rowId, $stored['original_name'], $stored['stored_name'], $stored['mime'],
         (string) (int) $stored['size'], $caption, mb_substr((string) $ctx['unit'], 0, 160),
         (int) $ctx['dept']['id']]);
}

/* ---------------------------------------------------------- submitting */

/**
 * Mark the results in: the record, the row's history, and -- unless they
 * came in with the event itself ($notify false) -- the team's bell.
 */
function fabo_evres_submit(array $rec, array $ctx, array $row, string $by, bool $notify = true): void
{
    fabo_exec("UPDATE event_results SET state = 'submitted', submitted_at = NOW(), submitted_by = ?
                WHERE id = ?", 'si', [mb_substr($by, 0, 160), (int) $rec['id']]);
    fabo_evres_ctx_audit($ctx, (int) $row['id'], '(results)', null, 'submitted');
    if (!$notify) {
        return;
    }
    try {
        require_once __DIR__ . '/notify.php';
        $title = 'Results submitted: ' . (trim((string) ($row['event_name'] ?? '')) ?: ($row['event_id'] ?? 'event'));
        $body  = trim($by) . ($ctx['unit'] !== '' ? ' (' . $ctx['unit'] . ')' : '') . ' sent the results of this event.';
        $org   = (int) $ctx['org'];
        $link  = fabo_row_link($org > 0 ? $org : FABO_ADMIN_INBOX, 'events', (int) $row['id'], 'completed');
        if ($org > 0) {
            fabo_notify($org, $title, $body, $link);
        } else {
            fabo_notify_admin($title, $body, $link);
        }
    } catch (Throwable $e) {
        error_log('FABO evres submit notice: ' . $e->getMessage());
    }
}

/** Someone saved a result without pressing Submit: in progress, not open. */
function fabo_evres_touch(array $rec): void
{
    if ((string) $rec['state'] === 'open') {
        fabo_exec("UPDATE event_results SET state = 'draft' WHERE id = ? AND state = 'open'", 'i', [(int) $rec['id']]);
    }
}

/* ---------------------------------------------------------- reminders */

/**
 * The organisation an email goes out as: its owner's record, or null for
 * ours. See fabo_sender_for().
 */
function fabo_evres_actor(int $org): ?array
{
    if ($org <= 0) {
        return null;
    }
    return fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [$org]) ?: null;
}

/**
 * Chase the results still owed. At most every half hour, and a few emails
 * at a time, from a page load; everything at once from cron ($force).
 * Returns [reminders sent, organisations told].
 */
function fabo_evres_sweep(bool $force = false): array
{
    require_once __DIR__ . '/google.php';
    $base = fabo_evres_base();                 // remembered on a web request
    if (!$force) {
        $last = (int) fabo_setting('evres_last_sweep', '0');
        if ($last > time() - 1800) {
            return [0, 0];
        }
    }
    fabo_setting_put('evres_last_sweep', (string) time());
    if ($base === '') {
        return [0, 0];                        // no address to put in a link yet
    }
    fabo_evres_install();
    require_once __DIR__ . '/shares.php';
    require_once __DIR__ . '/departments.php';
    require_once __DIR__ . '/lists.php';
    require_once __DIR__ . '/outbox.php';
    require_once __DIR__ . '/mailtpl.php';

    $since = max(fabo_evres_since(),
                 date('Y-m-d', strtotime(fabo_today() . ' -' . (FABO_EVRES_MAIL_WINDOW + 1) . ' days')));
    $until = date('Y-m-d', strtotime(fabo_today() . ' -1 day'));
    $was   = fabo_view_owner();

    /* Only events entered as Upcoming from now on are chased by email
       (remind = 1, set when the event is added). A record made later -- for
       the Results due list, or for an event added before this -- is listed,
       never mailed about. */
    $open = fabo_all("SELECT e.*, e.id AS row_id_e, r.id AS rec_id, r.token, r.state, r.reminders,
                             r.last_reminded_at, r.escalated_at, r.owner_name, r.owner_email,
                             r.share_id AS rec_share, r.dept_id AS rec_dept, r.client_id AS rec_org
                        FROM event_results r JOIN events e ON e.id = r.row_id
                       WHERE r.state <> 'submitted' AND r.remind = 1
                         AND COALESCE(e.end_date, e.start_date) BETWEEN ? AND ?",
                     'ss', [$since, $until]);

    $sent   = 0;
    $owners = [];     // email => [org, name, email, [events]]
    $late   = [];     // org => [events]
    $byOrg  = [];
    foreach ($open as $r) {
        $byOrg[(int) $r['rec_org']][] = $r;
    }
    foreach ($byOrg as $org => $list) {
        fabo_view_owner($org);
        $sheet = fabo_sheet('events');
        if (!$sheet) {
            continue;
        }
        $fields = fabo_evres_fields($sheet);
        $pics   = fabo_evres_picture_counts(array_column($list, 'row_id_e'));
        foreach ($list as $r) {
            $row = $r;
            $row['id'] = (int) $r['row_id_e'];
            if (fabo_evres_started($row, $fields, $pics[$row['id']] ?? 0)) {
                continue;                       // started: in progress, not forgotten
            }
            /* Who it is owed through, and whether that link is still open. */
            $unit = '';
            if ($r['rec_share']) {
                $share = fabo_one('SELECT * FROM sheet_shares WHERE id = ?', 'i', [(int) $r['rec_share']]);
                if (!$share || empty($share['active'])) {
                    continue;
                }
                $unit = (string) $share['department'];
            } elseif ($r['rec_dept']) {
                $dept = fabo_department((int) $r['rec_dept']);
                if (!$dept || !empty($dept['revoked_at'])) {
                    continue;
                }
                $unit = (string) $dept['name'];
            }
            $days  = (int) floor((strtotime(fabo_today()) - strtotime(fabo_evres_end($row))) / 86400);
            $quiet = empty($r['last_reminded_at'])
                  || strtotime((string) $r['last_reminded_at']) <= time() - 2 * 86400 + 3600;
            $email = trim((string) ($r['owner_email'] ?? '')) ?: trim((string) ($r['spoc_email'] ?? ''));
            $canMail = $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL);
            $event = [
                'rec'   => (int) $r['rec_id'],
                'title' => trim((string) ($r['event_name'] ?? '')) ?: (string) ($r['event_id'] ?? 'Event'),
                'ref'   => (string) ($r['event_id'] ?? ''),
                'ended' => date('d M Y', strtotime(fabo_evres_end($row))),
                'days'  => $days,
                'url'   => fabo_evres_url(['token' => $r['token']]),
                'dept'  => $unit,
                'owner' => trim((string) ($r['owner_name'] ?? '')),
                'email' => $canMail ? $email : '',
                'row'   => $row['id'],
            ];
            $k = (int) $r['reminders'];
            if ($canMail && $k < count(FABO_EVRES_REMIND_DAYS) && $days >= FABO_EVRES_REMIND_DAYS[$k] && $quiet) {
                $key = strtolower($email);
                $owners[$key] ??= ['org' => $org, 'name' => $event['owner'], 'email' => $email, 'events' => []];
                $owners[$key]['events'][] = $event;
            }
            if ($days >= FABO_EVRES_ESCALATE_DAY && empty($r['escalated_at']) && ($k >= 1 || !$canMail)) {
                $late[$org][] = $event;
            }
        }
    }
    fabo_view_owner($was);

    /* On a page load only a few go at a time, so nobody's page waits on a
       pile of mail; the rest follow on the next sweep. Cron sends them all. */
    $owners = $force ? $owners : array_slice($owners, 0, 4);
    foreach ($owners as $o) {
        try {
            if (fabo_evres_mail_owner($o)) {
                fabo_exec('UPDATE event_results SET reminders = reminders + 1, last_reminded_at = NOW()
                            WHERE id IN (' . implode(',', array_map('intval', array_column($o['events'], 'rec'))) . ')');
                $sent++;
            }
        } catch (Throwable $e) {
            error_log('FABO evres remind: ' . $e->getMessage());
        }
    }
    $told = 0;
    foreach ($late as $org => $events) {
        try {
            fabo_evres_escalate((int) $org, $events);
            fabo_exec('UPDATE event_results SET escalated_at = NOW()
                        WHERE id IN (' . implode(',', array_map('intval', array_column($events, 'rec'))) . ')');
            $told++;
        } catch (Throwable $e) {
            error_log('FABO evres escalate: ' . $e->getMessage());
        }
    }
    return [$sent, $told];
}

/** One email to one owner, listing every event of theirs that needs results. Returns whether it went or was queued. */
function fabo_evres_mail_owner(array $o): bool
{
    $events = $o['events'];
    $n      = count($events);
    $brand  = fabo_org_brand((int) $o['org'])[1];
    $subject = $n === 1
        ? 'Please add the results: ' . $events[0]['title']
        : 'Please add the results of ' . $n . ' events';
    $hello = trim((string) $o['name']) !== '' ? 'Hello ' . $o['name'] . ',' : 'Hello,';
    $lead  = ($n === 1 ? 'The event below has ended.' : 'These events have ended.')
           . ' Please add the results — achieved figures, spend, leads and photographs. '
           . 'Each link opens a short form for that one event; no sign-in is needed.';

    $text  = $hello . "\n\n" . $lead . "\n\n";
    $inner = fabo_mail_p(e($hello)) . fabo_mail_p(e($lead));
    foreach ($events as $ev) {
        $text  .= '• ' . $ev['title'] . ($ev['ref'] !== '' ? ' (' . $ev['ref'] . ')' : '')
                . ' — ended ' . $ev['ended'] . "\n  " . $ev['url'] . "\n\n";
        $inner .= fabo_mail_p('<strong>' . e($ev['title']) . '</strong>'
                              . ($ev['ref'] !== '' ? ' &middot; ' . e($ev['ref']) : '')
                              . '<br><span style="color:' . fabo_mail_soft() . ';">Ended ' . e($ev['ended'])
                              . ($ev['dept'] !== '' ? ' &middot; ' . e($ev['dept']) : '') . '</span>', 'margin:0 0 8px;')
                . fabo_mail_own_link($ev['url'], 'Add the results');
    }
    $text  .= "You can save a draft and come back to it. Press Submit when everything is in, and the reminders stop.\n\n" . $brand;
    $inner .= fabo_mail_note(e('You can save a draft and come back to it. Press Submit when everything is in, and the reminders stop.'));
    $html   = fabo_mail_wrap($brand, $n === 1 ? 'Your event has ended — results, please' : 'Your events have ended — results, please',
                             $inner, $subject);

    [$ok, , $queued] = fabo_post_or_queue($o['email'], $subject, $text, $html, null, 'event-results',
                                          fabo_evres_actor((int) $o['org']));
    return $ok || $queued > 0;
}

/** Tell the organisation which events are ten days past their end with no results. */
function fabo_evres_escalate(int $org, array $events): void
{
    require_once __DIR__ . '/notify.php';
    $n     = count($events);
    $brand = fabo_org_brand($org)[1];
    $title = $n === 1 ? 'No results yet: ' . $events[0]['title'] : $n . ' events still without results';
    $lines = [];
    foreach ($events as $ev) {
        $lines[] = $ev['title'] . ($ev['ref'] !== '' ? ' (' . $ev['ref'] . ')' : '') . ' — ended ' . $ev['ended']
                 . ' (' . $ev['days'] . ' days ago)'
                 . ($ev['dept'] !== '' ? ', ' . $ev['dept'] : '')
                 . ($ev['owner'] !== '' || $ev['email'] !== ''
                    ? ', owed by ' . trim($ev['owner'] . ($ev['email'] !== '' ? ' <' . $ev['email'] . '>' : ''))
                    : ', nobody to remind (no email on file)');
    }
    $body = 'Reminders have gone out, and nothing has been entered yet. '
          . 'Follow up with the department, or fill the results in on Completed Events.' . "\n\n" . implode("\n", $lines);
    $link = fabo_row_link($org > 0 ? $org : FABO_ADMIN_INBOX, 'events', (int) $events[0]['row'], 'completed');
    if ($org > 0) {
        fabo_notify($org, $title, $body, $link);
        $to = (string) (fabo_one('SELECT email FROM clients WHERE id = ?', 'i', [$org])['email'] ?? '');
    } else {
        fabo_notify_admin($title, $body, $link);
        $to = defined('ADMIN_INBOX_EMAIL') ? (string) ADMIN_INBOX_EMAIL : '';
    }
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return;
    }
    require_once __DIR__ . '/outbox.php';
    require_once __DIR__ . '/mailtpl.php';
    $inner = fabo_mail_p(e('Reminders have gone out for these events, and no results have been entered yet.'))
           . fabo_mail_list(array_map('e', $lines))
           . fabo_mail_p(e('Follow up with the department, or fill the results in yourself on Completed Events.'))
           . fabo_mail_button('Open the portal');
    fabo_post_or_queue($to, $title, $body . "\n\n" . fabo_mail_home(),
                       fabo_mail_wrap($brand, $title, $inner, $title), null, 'event-results-late',
                       fabo_evres_actor($org));
}

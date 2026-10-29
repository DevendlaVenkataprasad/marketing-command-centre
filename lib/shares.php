<?php
/**
 * Shared live sheets -- any sheet, shared with a department, by link.
 *
 * Made on the Intake Forms desk: pick the sheet, the department it is for and
 * the person who will fill it in, and a link is made. Whoever has the link
 * opens s.php and works on that sheet as a grid -- every column of it, read
 * from the sheet as it stands today, so a column renamed or added on Sheet
 * Management is on the shared sheet the next time it loads. Nothing is kept
 * in step by hand, because there is no second copy of the columns.
 *
 * Every column is there. The ones a person types into are editable; the ones
 * the portal fills in itself (ids, computed columns, kept columns, cells shut
 * until a date passes, and lists of the organisation's own people or
 * committees) are shown, read-only, with their values -- worked out live,
 * exactly as the sheet shows them.
 *
 * Rows are real rows on the sheet from the moment they are added: there is no
 * draft step, so the team sees them as they are typed. Each is filed under
 * the organisation the share belongs to, stamped with the department (when
 * the sheet has a Business Unit column) and with the share that made it, and
 * every edit is in the row's history under the person's name.
 *
 * A share sees either the rows added through it, or -- when made that way --
 * all of its organisation's rows on that sheet.
 *
 * The link is the whole of the authority: no sign-in, so every action is
 * bound to the one share the link names, and only to rows that share may see.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lists.php';
require_once __DIR__ . '/uploads.php';

/** How a share's link is presented: s.php?k=<token>. */
const FABO_SHARE_PAGE = 's.php';

/**
 * Columns that are a decision of the team running the sheet, not something
 * the person with the link reports -- shown to them, never set by them. A
 * department signing off its own event, or moving its own request up the
 * creative queue, is exactly what a share must not allow.
 */
const FABO_SHARE_TEAM_ONLY = [
    'approval_status'  => 'Signed off by the team that runs this sheet.',
    'approval_note'    => 'Written by whoever signs it off.',
    'confirm_state'    => 'Kept by the portal.',
    'queue_position'   => 'Set by the creative queue.',
    'promised_start'   => 'Set by the creative queue.',
    'promised_due'     => 'Set by the creative queue.',
    'turnaround_hours' => 'Set by the creative queue.',
    'extended_count'   => 'Set by the creative queue.',
    'extension_reason' => 'Written by the team when a date moves.',
];

/** The dropdown a share's hub or spoke comes from (as on the Events sheet). */
const FABO_SHARE_HUB_LIST = 'Hub / Spoke';

/** The hub or spoke a share is for; '' when it is for all of them. */
function fabo_share_hub(?array $share): string
{
    return trim((string) ($share['hub_spoke'] ?? ''));
}

/** The sheet's Hub / Spoke column, or null when it has none. */
function fabo_share_hub_field(array $sheet): ?string
{
    foreach ($sheet['columns'] as $c) {
        if ($c[2] === 'select' && ($c[3]['list'] ?? '') === FABO_SHARE_HUB_LIST) {
            return (string) $c[1];
        }
    }
    return null;
}

/* -------------------------------------------------------------- shares */

/** A fresh link token: 40 random hex characters. */
function fabo_share_new_token(): string
{
    return bin2hex(random_bytes(20));
}

/** The address a share is opened at. */
function fabo_share_url(array $share): string
{
    require_once __DIR__ . '/ui.php';
    return fabo_portal_url() . FABO_SHARE_PAGE . '?k=' . $share['token'];
}

/** Open, closed or expired. */
function fabo_share_state(array $s): string
{
    if (empty($s['active'])) {
        return 'off';
    }
    if (!empty($s['expires_at']) && strtotime((string) $s['expires_at']) < time()) {
        return 'expired';
    }
    return 'ok';
}

/** The share a token names, while its link works; null otherwise. */
function fabo_share_open(string $token): ?array
{
    $token = trim($token);
    if (!preg_match('~^[0-9a-f]{40}$~', $token)) {
        return null;
    }
    try {
        $s = fabo_one('SELECT * FROM sheet_shares WHERE token = ?', 's', [$token]);
    } catch (Throwable $e) {
        return null;
    }
    if (!$s || fabo_share_state($s) !== 'ok') {
        return null;
    }
    /* The sheet must still exist for the organisation the share is for: a
       sheet built in one client's portal and since deleted closes the link. */
    if (!fabo_share_sheet($s)) {
        return null;
    }
    return $s;
}

/** Every share of one organisation (0 for ours), newest first. */
function fabo_shares_for(int $scope): array
{
    try {
        return $scope > 0
            ? fabo_all('SELECT * FROM sheet_shares WHERE owner_scope = ? ORDER BY created_at DESC', 'i', [$scope])
            : fabo_all('SELECT * FROM sheet_shares ORDER BY created_at DESC');
    } catch (Throwable $e) {
        return [];
    }
}

/** Count the open, and when. */
function fabo_share_touch(int $id): void
{
    try {
        fabo_exec('UPDATE sheet_shares SET opened_count = opened_count + 1, last_opened_at = NOW()
                    WHERE id = ?', 'i', [$id]);
    } catch (Throwable $e) {
        // Counting is a courtesy, never a reason for the page to fail.
    }
}

/**
 * Make a share. Returns its id.
 *
 * $owner is the organisation the share belongs to and its rows are filed
 * under: 0 for ours. The sheet must exist for that organisation; the
 * department must be one of its Business Unit options when it has any.
 */
function fabo_share_create(int $scope, int $owner, string $sheetKey, string $department,
                           string $name, string $email, string $sees, ?string $expires, string $by,
                           string $hub = ''): int
{
    $department = trim($department);
    $hub        = trim($hub);
    $name       = trim($name);
    $email      = trim($email);
    if ($department === '') {
        throw new InvalidArgumentException('Choose the department this sheet is for.');
    }
    if ($name === '') {
        throw new InvalidArgumentException('Give the name of the person who will fill it in.');
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('"' . $email . '" is not an email address.');
    }
    if (!in_array($sees, ['own', 'all'], true)) {
        $sees = 'own';
    }
    $was = fabo_view_owner();
    fabo_view_owner($owner);
    $sheet = fabo_sheet($sheetKey);
    $units = fabo_list_values('Business Unit');
    $hubs  = fabo_list_values(FABO_SHARE_HUB_LIST);
    fabo_view_owner($was);
    if (!$sheet || $sheetKey === 'lists') {
        throw new InvalidArgumentException('Pick a sheet that this organisation has.');
    }
    if ($units && !in_array($department, $units, true)) {
        throw new InvalidArgumentException('Pick a department from the list, or add it to '
                                           . 'Business Unit on Sheet Management first.');
    }
    if ($hub !== '' && $hubs && !in_array($hub, $hubs, true)) {
        throw new InvalidArgumentException('Pick a hub or spoke from the list, or add it to '
                                           . FABO_SHARE_HUB_LIST . ' on Sheet Management first.');
    }
    fabo_share_ensure_column($sheet);

    return (int) fabo_exec(
        'INSERT INTO sheet_shares (owner_scope, filed_under, sheet_key, department, hub_spoke, contact_name,
                                   contact_email, sees, token, active, expires_at, created_by)
         VALUES (?,?,?,?,?,?,?,?,?,1,?,?)', 'iisssssssss',
        [$scope, $owner, $sheetKey, mb_substr($department, 0, 160),
         $hub !== '' ? mb_substr($hub, 0, 120) : null, mb_substr($name, 0, 160),
         mb_substr($email, 0, 190), $sees, fabo_share_new_token(), $expires, mb_substr($by, 0, 160)]);
}

/**
 * The sheet behind a share, as the organisation it belongs to sees it --
 * their names for its columns, their own built sheets, their dropdowns.
 * Leaves the view owner set to that organisation, which is what every read
 * of the share's lists and labels from here on wants.
 */
function fabo_share_sheet(array $share): ?array
{
    fabo_view_owner((int) $share['filed_under']);
    return fabo_sheet((string) $share['sheet_key']);
}

/**
 * The column on the sheet's own table that says which share made a row.
 * Added the first time a sheet is shared, so a sheet nobody shares is never
 * altered. Returns whether it is there.
 */
function fabo_share_ensure_column(array $sheet): bool
{
    static $done = [];
    $table = (string) $sheet['table'];
    if (isset($done[$table])) {
        return $done[$table];
    }
    try {
        if (fabo_all('SHOW COLUMNS FROM `' . $table . '` LIKE "share_id"')) {
            return $done[$table] = true;
        }
        fabo_exec('ALTER TABLE `' . $table . '` ADD COLUMN `share_id` INT UNSIGNED NULL');
        fabo_exec('ALTER TABLE `' . $table . '` ADD KEY `k_share` (`share_id`)');
        return $done[$table] = true;
    } catch (Throwable $e) {
        error_log('FABO share column on ' . $table . ': ' . $e->getMessage());
        return $done[$table] = false;
    }
}

/* ------------------------------------------------------------ columns */

/**
 * Every column of the shared sheet, and how the shared page treats it.
 *
 * Returns [field => [label, type, list, mode, note]] in the sheet's order,
 * where mode is:
 *   edit    -- typed into, as on the sheet;
 *   read    -- shown with its value, not typed: ids, computed and kept
 *              columns, and lists of the organisation's own people or
 *              committees, which are not for a link to see or set;
 *   images  -- the sheet's photo gallery;
 *   file    -- a single file or image, uploaded into the cell.
 * A column shut until a date passes is 'edit' with its 'unlock' kept, and is
 * judged row by row.
 */
function fabo_share_columns(array $sheet, ?array $share = null): array
{
    $hub = fabo_share_hub($share);
    $out = [];
    foreach ($sheet['columns'] as $c) {
        [$label, $field, $type] = $c;
        $meta = $c[3] ?? [];
        $list = (string) ($meta['list'] ?? '');
        $mode = 'edit';
        $note = '';
        if ($type === 'id') {
            $mode = 'read'; $note = 'Issued automatically.';
        } elseif ($type === 'computed') {
            $mode = 'read'; $note = 'Worked out by the portal.';
        } elseif ($type === 'images') {
            $mode = 'images';
        } elseif ($type === 'file' || $type === 'image') {
            $mode = 'file';
        } elseif (fabo_col_kept($c) !== '') {
            $mode = 'read'; $note = fabo_col_kept($c);
        } elseif ($list !== '' && fabo_is_dynamic_list($list)) {
            $mode = 'read'; $note = 'Set by the team that runs this sheet.';
        } elseif ($field === 'approval_to') {
            $mode = 'read'; $note = 'Sent for approval from the sheet.';
        } elseif ($list === 'Business Unit' && $type === 'select') {
            /* Stamped with the share's department on every row it adds: the
               department is who the sheet was shared with, not a choice. */
            $mode = 'read'; $note = 'The department this sheet is shared with.';
        } elseif ($hub !== '' && $list === FABO_SHARE_HUB_LIST && $type === 'select') {
            /* Shared for one hub or spoke: its rows are stamped with it. */
            $mode = 'read'; $note = 'The hub / spoke this sheet is shared for.';
        } elseif (isset(FABO_SHARE_TEAM_ONLY[$field])) {
            $mode = 'read'; $note = FABO_SHARE_TEAM_ONLY[$field];
        }
        $out[$field] = [
            'label'  => ($meta['group'] ?? '') !== '' ? $meta['group'] . ' — ' . $label : $label,
            'type'   => $type,
            'list'   => ($mode === 'edit' && $list !== '') ? $list : null,
            'mode'   => $mode,
            'note'   => $note,
            'unlock' => (string) ($meta['unlock'] ?? ''),
            'col'    => $c,
        ];
    }
    return $out;
}

/** The dropdown options the shared page needs, keyed by list name. */
function fabo_share_lists(array $cols): array
{
    $out = [];
    foreach ($cols as $m) {
        if ($m['list']) {
            $out[$m['list']] = fabo_list_values((string) $m['list']);
        }
    }
    return $out;
}

/* --------------------------------------------------------------- rows */

/** The WHERE that keeps a share to the rows it may see, as [sql, types, params]. */
function fabo_share_where(array $share): array
{
    if ((string) $share['sees'] !== 'all') {
        return ['`share_id` = ?', 'i', [(int) $share['id']]];
    }
    $sql = 'COALESCE(`client_id`, 0) = ?';
    /* Rows a department is still drafting on its own shared sheet (d.php)
       are not on the Events sheet yet, so they are not on this one either. */
    if ((string) $share['sheet_key'] === 'events') {
        require_once __DIR__ . '/departments.php';
        if (fabo_dept_has_submitted_column()) {
            $sql .= ' AND (`department_id` IS NULL OR `dept_submitted_at` IS NOT NULL)';
        }
    }
    $params = [(int) $share['filed_under']];
    $types  = 'i';
    /* Shared for one hub or spoke: "every row" means every row of that hub. */
    $hub = fabo_share_hub($share);
    if ($hub !== '') {
        $was   = fabo_view_owner();
        $sheet = fabo_share_sheet($share);
        fabo_view_owner($was);
        $hubField = $sheet ? fabo_share_hub_field($sheet) : null;
        if ($hubField !== null) {
            $sql     .= ' AND `' . $hubField . '` = ?';
            $types   .= 's';
            $params[] = $hub;
        }
    }
    return [$sql, $types, $params];
}

/** The rows a share sees, oldest first, with every computed column filled. */
function fabo_share_rows(array $share, array $sheet): array
{
    if (!fabo_share_ensure_column($sheet)) {
        return [];
    }
    [$sql, $types, $params] = fabo_share_where($share);
    $rows = fabo_all('SELECT * FROM `' . $sheet['table'] . '` WHERE ' . $sql . ' ORDER BY id', $types, $params);
    require_once __DIR__ . '/compute.php';
    return fabo_compute_all((string) $share['sheet_key'], $rows);
}

/** One row the share may see, computed; null when it may not. */
function fabo_share_row(array $share, array $sheet, int $rowId): ?array
{
    [$sql, $types, $params] = fabo_share_where($share);
    $r = fabo_one('SELECT * FROM `' . $sheet['table'] . '` WHERE id = ? AND ' . $sql,
                  'i' . $types, array_merge([$rowId], $params));
    if (!$r) {
        return null;
    }
    require_once __DIR__ . '/compute.php';
    return fabo_compute_all((string) $share['sheet_key'], [$r])[0] ?? $r;
}

/**
 * Add an empty row through a share. Returns its id.
 *
 * Filed under the share's organisation, numbered like any other row, and
 * stamped with the department where the sheet has a Business Unit column.
 */
function fabo_share_add_row(array $share, array $sheet): int
{
    fabo_share_ensure_column($sheet);
    require_once __DIR__ . '/endpoint.php';
    $cols  = ['`share_id`', '`client_id`'];
    $vals  = [(int) $share['id'], (int) $share['filed_under'] > 0 ? (int) $share['filed_under'] : null];
    $types = 'ii';
    if (!empty($sheet['id_field']) && !empty($sheet['prefix'])) {
        $cols[] = '`' . $sheet['id_field'] . '`';
        $vals[] = fabo_next_id($sheet);
        $types .= 's';
    }
    foreach ($sheet['columns'] as $c) {
        if (($c[3]['list'] ?? '') === 'Business Unit' && $c[2] === 'select') {
            $cols[] = '`' . $c[1] . '`';
            $vals[] = (string) $share['department'];
            $types .= 's';
            break;
        }
    }
    $hubField = fabo_share_hub_field($sheet);
    if ($hubField !== null && fabo_share_hub($share) !== '') {
        $cols[] = '`' . $hubField . '`';
        $vals[] = fabo_share_hub($share);
        $types .= 's';
    }
    $id = (int) fabo_exec('INSERT INTO `' . $sheet['table'] . '` (' . implode(', ', $cols) . ') VALUES ('
                          . implode(', ', array_fill(0, count($cols), '?')) . ')', $types, $vals);
    fabo_share_audit($share, $sheet, $id, '(row)', null, 'added');
    fabo_share_after_change((string) $share['sheet_key'], $id);
    return $id;
}

/**
 * Add an event through a share already filled in, as Upcoming or Completed.
 *
 * On the Events sheet, + Add row asks first which kind of event this is.
 * Upcoming offers the planning columns only (what the Upcoming Events view
 * shows); Completed offers every column, results included. Everything is
 * checked before the row exists -- a refused dropdown value or a date on the
 * wrong side of today leaves nothing half-made behind -- and each value is
 * then saved through fabo_share_save(), so the share's own rules hold.
 *
 * Returns [row id, [warnings]]: a value that could still not be saved is
 * said, not silently dropped.
 */
function fabo_share_add_filled(array $share, array $sheet, string $stage, array $values,
                               string $ownerName = '', string $ownerEmail = ''): array
{
    if ((string) $share['sheet_key'] !== 'events') {
        throw new InvalidArgumentException('This sheet adds rows one at a time.');
    }
    /* Who will send the results once the event is over (lib/evresults.php):
       checked before anything is written, like everything else here. */
    $ownerName  = trim($ownerName);
    $ownerEmail = trim($ownerEmail);
    if ($ownerEmail !== '' && !filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('"' . $ownerEmail . '" is not an email address — '
                                           . 'it is where the results reminder goes.');
    }
    require_once __DIR__ . '/eventstages.php';
    require_once __DIR__ . '/endpoint.php';

    $cols = fabo_share_columns($sheet, $share);
    $want = [];
    foreach ($cols as $f => $m) {
        if ($m['mode'] !== 'edit' || !array_key_exists($f, $values)) {
            continue;
        }
        if ($stage === 'upcoming' && fabo_evstage_of($f) === 'result') {
            continue;                       // results wait for the event
        }
        $v = trim((string) $values[$f]);
        if ($v === '' || ($m['type'] === 'checkbox' && $v === '0')) {
            continue;
        }
        $want[$f] = $v;
    }

    /* The dates fit the kind -- and a completed one-day event's Start Date
       is its End Date too, which is what opens the Achieved columns here. */
    $want = fabo_evstage_check_new($stage, $want,
                                   isset($cols['end_date']) && $cols['end_date']['mode'] === 'edit');

    // Every dropdown checked before anything is written.
    foreach ($want as $f => $v) {
        $why = fabo_list_refusal($cols[$f]['col'], fabo_coerce($cols[$f]['type'], $v), null);
        if ($why !== '') {
            throw new InvalidArgumentException($why);
        }
    }

    $id   = fabo_share_add_row($share, $sheet);
    $warn = [];
    // Dates first: the Achieved columns open on them.
    $first = array_intersect_key($want, ['start_date' => 1, 'end_date' => 1]);
    foreach ($first + $want as $f => $v) {
        try {
            fabo_share_save($share, $sheet, $id, $f, $v);
        } catch (InvalidArgumentException $e) {
            $warn[] = $cols[$f]['label'] . ': ' . $e->getMessage();
        }
    }
    try {
        require_once __DIR__ . '/evresults.php';
        $rec = fabo_evres_ensure($id, $share, $ownerName, $ownerEmail, null, $stage === 'upcoming');
        /* A Completed event added with its results already in: submitted
           with it, so it is not then chased for them. */
        $gave = false;
        foreach (array_keys($want) as $f) {
            $gave = $gave || fabo_evstage_of($f) === 'result';
        }
        if ($rec && $stage === 'completed' && $gave) {
            $row = fabo_share_row($share, $sheet, $id);
            if ($row) {
                fabo_evres_submit($rec, fabo_evres_share_ctx($share, $sheet), $row, (string) $share['contact_name'], false);
            }
        }
    } catch (Throwable $e) {
        error_log('FABO evres on add: ' . $e->getMessage());   // made later, by the sweep
    }
    return [$id, $warn];
}

/**
 * Save one cell through a share. Returns the row as it stands afterwards.
 *
 * Only an editable column, only on a row the share may see, never a cell shut
 * until a date passes, and a dropdown only takes what its list offers.
 */
function fabo_share_save(array $share, array $sheet, int $rowId, string $field, string $value): array
{
    $cols = fabo_share_columns($sheet, $share);
    $m    = $cols[$field] ?? null;
    if (!$m) {
        throw new InvalidArgumentException('That column is not on this sheet.');
    }
    if ($m['mode'] !== 'edit') {
        throw new InvalidArgumentException($m['label'] . ': ' . ($m['note'] !== '' ? $m['note']
                                           : 'not typed on this sheet.'));
    }
    $row = fabo_share_row($share, $sheet, $rowId);
    if (!$row) {
        throw new InvalidArgumentException('That row is not on this sheet.');
    }
    if (fabo_cell_locked($m['col'], $row)) {
        throw new InvalidArgumentException(fabo_cell_lock_note($m['col'], $row));
    }
    if ($value === '__add__' || $value === '__manage__') {
        throw new InvalidArgumentException('That is not a value.');
    }
    require_once __DIR__ . '/endpoint.php';
    if ($m['type'] === 'checkbox') {
        $value = in_array(strtolower(trim($value)), ['1', 'on', 'yes', 'true'], true) ? '1' : '0';
    }
    $new = fabo_coerce($m['type'], $value);
    $why = fabo_list_refusal($m['col'], $new, $row[$field] ?? null);
    if ($why !== '') {
        throw new InvalidArgumentException($why);
    }
    $old = $row[$field] ?? null;
    fabo_exec('UPDATE `' . $sheet['table'] . '` SET `' . $field . '` = ? WHERE id = ?',
              is_int($new) ? 'ii' : (is_float($new) ? 'di' : 'si'), [$new, $rowId]);
    if ((string) $old !== (string) ($new ?? '')) {
        fabo_share_audit($share, $sheet, $rowId, $field, $old, $new);
    }
    if ($field === 'status' && function_exists('fabo_signoff_on_complete')) {
        fabo_signoff_on_complete((string) $share['sheet_key'], $rowId);
    }
    fabo_share_after_change((string) $share['sheet_key'], $rowId);
    return fabo_share_row($share, $sheet, $rowId) ?? $row;
}

/** Remove a row -- only one this share added, whatever else it can see. */
function fabo_share_delete_row(array $share, array $sheet, int $rowId): void
{
    $r = fabo_one('SELECT id FROM `' . $sheet['table'] . '` WHERE id = ? AND share_id = ?',
                  'ii', [$rowId, (int) $share['id']]);
    if (!$r) {
        throw new InvalidArgumentException('Only rows added on this sheet can be removed here.');
    }
    foreach (fabo_share_attachments((string) $share['sheet_key'], $rowId) as $a) {
        fabo_share_drop((int) $a['id']);
    }
    fabo_exec('DELETE FROM `' . $sheet['table'] . '` WHERE id = ?', 'i', [$rowId]);
    fabo_share_audit($share, $sheet, $rowId, '(row)', 'row', 'removed');
}

/** Keep the Master Activity Log in step with a row that changed, as every other path does. */
function fabo_share_after_change(string $key, int $rowId): void
{
    try {
        if (is_file(__DIR__ . '/masterlog.php')) {
            require_once __DIR__ . '/masterlog.php';
            if (function_exists('fabo_master_sync')) {
                fabo_master_sync($key, $rowId);
            }
        }
    } catch (Throwable $e) {
        error_log('FABO share masterlog: ' . $e->getMessage());
    }
    // An approved event given design support from a shared sheet: raise it now (lib/eventdesign.php).
    if ($key === 'events') {
        try {
            require_once __DIR__ . '/eventdesign.php';
            fabo_evdesign_raise($rowId);
        } catch (Throwable $e) {
            error_log('FABO share event design: ' . $e->getMessage());
        }
    }
}

/** One line of the row's history, under the person and department of the share. */
function fabo_share_audit(array $share, array $sheet, int $rowId, string $field, $old, $new): void
{
    try {
        fabo_exec('INSERT INTO activity_audit (who, client_id, table_name, row_id, field, old_value, new_value)
                   VALUES (?,?,?,?,?,?,?)', 'sisisss',
                  [mb_substr($share['contact_name'] . ' · ' . $share['department']
                             . (fabo_share_hub($share) !== '' ? ' · ' . fabo_share_hub($share) : '')
                             . ' (shared sheet)', 0, 160),
                   (int) $share['filed_under'] > 0 ? (int) $share['filed_under'] : null,
                   (string) $sheet['table'], $rowId, $field,
                   $old === null ? null : mb_substr((string) $old, 0, 2000),
                   $new === null ? null : mb_substr((string) $new, 0, 2000)]);
    } catch (Throwable $e) {
        error_log('FABO share audit: ' . $e->getMessage());
    }
}

/* -------------------------------------------------------- attachments */

/** Every attachment on one row of a sheet, oldest first. */
function fabo_share_attachments(string $key, int $rowId): array
{
    return fabo_all('SELECT * FROM attachments WHERE sheet_key = ? AND row_id = ? ORDER BY id', 'si', [$key, $rowId]);
}

/** Store one uploaded file as an attachment on a row. Returns [id, stored] or throws. */
function fabo_share_store(array $share, string $key, int $rowId, array $file, string $caption,
                          bool $imagesOnly = false): array
{
    [$ok, $msg, $stored] = fabo_store_upload($file);
    if (!$ok || !$stored) {
        throw new InvalidArgumentException(($file['name'] ?? 'File') . ': ' . $msg);
    }
    if ($imagesOnly && !fabo_is_image((string) $stored['mime'])) {
        fabo_delete_stored((string) $stored['stored_name']);
        throw new InvalidArgumentException(($file['name'] ?? 'File') . ': that is not an image.');
    }
    $aid = (int) fabo_exec('INSERT INTO attachments (sheet_key, row_id, original_name, stored_name, mime,
                                                     size_bytes, caption, uploaded_by)
                            VALUES (?,?,?,?,?,?,?,?)', 'sissssss',
        [$key, $rowId, $stored['original_name'], $stored['stored_name'], $stored['mime'],
         (string) (int) $stored['size'], $caption,
         mb_substr($share['contact_name'] . ' · ' . $share['department'], 0, 160)]);
    return [$aid, $stored];
}

/** Delete one attachment and its file. */
function fabo_share_drop(int $attachmentId): void
{
    $a = fabo_one('SELECT stored_name FROM attachments WHERE id = ?', 'i', [$attachmentId]);
    if ($a) {
        fabo_delete_stored((string) $a['stored_name']);
        fabo_exec('DELETE FROM attachments WHERE id = ?', 'i', [$attachmentId]);
    }
}

/**
 * The attachment a share may download, or null: one on a row it can see.
 * Used by download.php for links opened with ?s=<token>.
 */
function fabo_share_attachment(array $share, int $attachmentId): ?array
{
    $sheet = fabo_share_sheet($share);
    if (!$sheet) {
        return null;
    }
    $a = fabo_one('SELECT * FROM attachments WHERE id = ? AND sheet_key = ?', 'is',
                  [$attachmentId, (string) $share['sheet_key']]);
    if (!$a || !fabo_share_row($share, $sheet, (int) $a['row_id'])) {
        return null;
    }
    return $a;
}

/* -------------------------------------------------------- the payload */

/**
 * One row as the shared page draws it: every column's value -- raw for the
 * editable ones, as displayed for the rest -- which cells are shut, and the
 * row's pictures, files and documents.
 */
function fabo_share_payload(array $share, array $sheet, array $row): array
{
    require_once __DIR__ . '/grid.php';
    $cols   = fabo_share_columns($sheet, $share);
    $vals   = [];
    $locked = [];
    $cells  = [];
    $atts   = fabo_share_attachments((string) $share['sheet_key'], (int) $row['id']);
    $byId   = [];
    foreach ($atts as $a) {
        $byId[(int) $a['id']] = $a;
    }
    $images = [];
    $docs   = [];
    $cellIds = [];
    foreach ($cols as $f => $m) {
        if ($m['mode'] === 'file' && (int) ($row[$f] ?? 0) > 0) {
            $cellIds[(int) $row[$f]] = true;
        }
    }
    foreach ($atts as $a) {
        if (isset($cellIds[(int) $a['id']])) {
            continue;                           // shown in its own cell
        }
        $isFile = (string) ($a['caption'] ?? '') === 'file' || !fabo_is_image((string) $a['mime']);
        if ($isFile) {
            $docs[] = ['id' => (int) $a['id'], 'name' => (string) $a['original_name'],
                       'size' => fabo_human_size((int) $a['size_bytes'])];
        } else {
            $images[] = ['id' => (int) $a['id'], 'name' => (string) $a['original_name']];
        }
    }
    foreach ($cols as $f => $m) {
        if ($m['mode'] === 'edit') {
            $vals[$f] = (string) fabo_raw($m['col'], $row);
            if ($m['unlock'] !== '' && fabo_cell_locked($m['col'], $row)) {
                $locked[$f] = fabo_cell_lock_note($m['col'], $row);
            }
        } elseif ($m['mode'] === 'read') {
            $vals[$f] = fabo_display($m['col'], $row);
        } elseif ($m['mode'] === 'file') {
            $a = $byId[(int) ($row[$f] ?? 0)] ?? null;
            $cells[$f] = $a ? ['id' => (int) $a['id'], 'name' => (string) $a['original_name'],
                               'image' => fabo_is_image((string) $a['mime'])] : null;
        }
    }
    return [
        'id'     => (int) $row['id'],
        'ref'    => !empty($sheet['id_field']) ? (string) ($row[$sheet['id_field']] ?? '') : '',
        'mine'   => (int) ($row['share_id'] ?? 0) === (int) $share['id'],
        'values' => $vals,
        'locked' => $locked,
        'cells'  => $cells,
        'images' => $images,
        'files'  => $docs,
        /* An event of this share's that has ended and owes its results: the
           list above the grid, with its results link (lib/evresults.php). */
        'results' => (string) $share['sheet_key'] === 'events'
            ? (static function () use ($share, $row) {
                require_once __DIR__ . '/evresults.php';
                return fabo_evres_share_info($share, $row);
            })()
            : null,
    ];
}

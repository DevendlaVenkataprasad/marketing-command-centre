<?php
/**
 * Shared bootstrap for every JSON endpoint. Authorisation is re-checked here
 * on every call — the UI hiding something is never relied on.
 */

require_once __DIR__ . '/grid.php';

function fabo_json($payload, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function fabo_json_fail(string $message, int $code = 400): void
{
    error_log('FABO endpoint fail: ' . $message . ' @ ' . ($_SERVER['REQUEST_URI'] ?? '?'));
    fabo_json(['ok' => false, 'error' => $message], $code);
}

/**
 * Resolves viewer + sheet + permission for an endpoint, or stops.
 * Returns [$viewer, $key, $sheet, $perm].
 */
function fabo_endpoint(string $need = 'view'): array
{
    fabo_boot();

    // A portal token, a signed-in portal session, or the admin.
    $token = (string) ($_REQUEST['k'] ?? '');

    /* One browser can be signed in as the admin AND as a team member -- the
       admin portal in one tab, somebody's portal in another. The person used
       to win, so a call made from an admin page was judged as that person and
       turned away ("You do not have access to this sheet"). A call that comes
       from the admin portal is the admin's, provided the admin really is
       signed in: this chooses between the caller's own sign-ins, never past
       them. Said outright with as=admin, or known from the page it came from. */
    $fromAdmin = ($_REQUEST['as'] ?? '') === 'admin'
              || str_contains((string) (parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''),
                                                  PHP_URL_PATH) ?? ''), '/admin/');

    if ($token === '' && $fromAdmin && fabo_admin_locked() && fabo_admin_signed_in()) {
        $viewer = fabo_admin_viewer();
    } elseif ($token !== '') {
        $viewer = fabo_client_viewer($token);     // stops if expired or revoked
    } elseif (fabo_signed_in()) {
        $viewer = fabo_person_viewer(fabo_signed_in());
    } elseif (fabo_admin_signed_in()) {
        $viewer = fabo_admin_viewer();
    } else {
        fabo_json_fail('Please sign in.', 401);
    }

    // Whose dropdowns and column wording this request should see. Set
    // before the sheet is loaded, because loading it applies the labels.
    require_once __DIR__ . '/lists.php';
    fabo_view_owner(fabo_customiser($viewer));

    $key   = (string) ($_REQUEST['sheet'] ?? '');
    $sheet = fabo_sheet($key);
    if (!$sheet) {
        fabo_json_fail('Unknown sheet.', 404);
    }

    $perm = fabo_permission($viewer, $key);
    if ($perm === 'none' || ($need === 'edit' && $perm !== 'edit')) {
        error_log('FABO denied ' . ($viewer['who'] ?? '?') . " -> {$key} ({$need})");
        fabo_json_fail('You do not have access to this sheet.', 403);
    }

    return [$viewer, $key, $sheet, $perm];
}

/** Coerces a submitted value to the column's storage type. */
function fabo_coerce(string $type, $value)
{
    $v = is_string($value) ? trim($value) : $value;
    if ($v === '' || $v === null) {
        return null;
    }
    switch ($type) {
        case 'date':
            $t = strtotime((string) $v);
            return $t ? date('Y-m-d', $t) : null;
        case 'int':
            return is_numeric($v) ? (int) $v : null;
        case 'money':
        case 'dec':
            return is_numeric($v) ? (float) $v : null;
        case 'checkbox':
            // Anything a tick box can post: '1', 'on', 'true'.
            return in_array(strtolower((string) $v), ['1', 'on', 'true', 'yes'], true) ? 1 : 0;
        case 'file':
        case 'image':
        case 'images':
            // The cell holds an attachment id, and only api/cellfile.php sets
            // it — a plain cell save must never be able to point a row at
            // somebody else's upload.
            return null;
        default:
            return (string) $v;
    }
}

/** Writes one audit entry per changed cell. */
function fabo_audit(array $viewer, string $table, int $rowId, string $field, $old, $new): void
{
    fabo_exec(
        'INSERT INTO activity_audit (who, client_id, table_name, row_id, field, old_value, new_value)
         VALUES (?,?,?,?,?,?,?)',
        'sisisss',
        [
            $viewer['is_admin'] ? 'Admin' : ($viewer['client']['name'] . ' (client)'),
            $viewer['is_admin'] ? null : (int) $viewer['client']['id'],
            $table,
            $rowId,
            $field,
            $old === null ? null : (string) $old,
            $new === null ? null : (string) $new,
        ]
    );
}

/** Next business ID for a sheet, e.g. ACT-0041. */
function fabo_next_id(array $sheet): string
{
    if (empty($sheet['prefix']) || empty($sheet['id_field'])) {
        return '';
    }
    $f   = $sheet['id_field'];
    $pre = $sheet['prefix'];
    $row = fabo_one(
        'SELECT MAX(CAST(SUBSTRING(`' . $f . '`, ?) AS UNSIGNED)) AS n
         FROM `' . $sheet['table'] . '` WHERE `' . $f . '` LIKE ?',
        'is',
        [strlen($pre) + 1, $pre . '%']
    );
    $next = (int) ($row['n'] ?? 0) + 1;
    return $pre . str_pad((string) $next, (int) $sheet['pad'], '0', STR_PAD_LEFT);
}

/** Loads one row by primary key, respecting the viewer's scope. */
function fabo_load_row(array $viewer, string $key, int $id): ?array
{
    $sheet  = fabo_sheet($key);
    $scope  = fabo_scope_where($viewer, $key);
    $sql    = 'SELECT * FROM `' . $sheet['table'] . '` WHERE id = ?' . $scope['sql'];
    $types  = 'i' . $scope['types'];
    $params = array_merge([$id], $scope['params']);

    // The same ownership rule the grid lists rows by. Without it a client
    // holding 'edit' could reach another client's row by its id alone —
    // never shown to them, but a row id is easy enough to guess.
    $owner = fabo_row_owner($viewer);
    if ($owner !== null) {
        $sql    .= ' AND `client_id` = ?';
        $types  .= 'i';
        $params[] = $owner;
    }
    return fabo_one($sql, $types, $params);
}

/**
 * The cell rules api/save.php applies one cell at a time, applied to a whole
 * set of values at once -- for the endpoints that write many fields in one
 * request (the composer's new row, the programme form). Without it those
 * routes could write what the grid refuses: a column the portal keeps (the
 * approved budget), a results column before the event has happened, or a
 * dropdown value that is not on its list.
 *
 * Columns the portal keeps are dropped rather than refused, so a form that
 * shows them read-only still saves. Returns the values that may be written.
 * $row is the stored row for an update, or null for a new one.
 */
function fabo_guard_values(array $sheet, array $values, ?array $row): array
{
    // Actions with their own authorisation in api/save.php, never plain values.
    unset($values['approval_to'], $values['committee']);
    $after = array_merge($row ?? [], $values);
    foreach ($sheet['columns'] as $c) {
        $f = $c[1];
        if (!array_key_exists($f, $values)) {
            continue;
        }
        if (fabo_col_kept($c) !== '') {
            unset($values[$f]);
            continue;
        }
        $old = $row[$f] ?? null;
        $changed = (string) ($values[$f] ?? '') !== (string) ($old ?? '');
        if ($changed && fabo_grid_cell_locked($c, $after)) {
            fabo_json_fail(fabo_grid_cell_lock_note($c, $after), 403);
        }
        if (($why = fabo_list_refusal($c, $values[$f], $old)) !== '') {
            fabo_json_fail($why, 400);
        }
    }
    return $values;
}

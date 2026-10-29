<?php
/**
 * What the Sheets page needs to know, kept out of the page itself.
 *
 * The page manages three things — what a sheet is called, what its columns
 * are called, and what a dropdown offers — and each of them is a question
 * about the whole portal rather than about one sheet. Answering them here
 * means the page can stay a page.
 */

require_once __DIR__ . '/sheets.php';
require_once __DIR__ . '/sheetbuilder.php';
require_once __DIR__ . '/lists.php';

/**
 * Every sheet a reader can manage, shipped and built, in navigation order.
 *
 * The dashboard is left out because it has no columns of its own, and a
 * built sheet that has been put away is added at the end — it is not in the
 * navigation, but it is still somebody's sheet and still needs managing.
 */
function fabo_admin_sheet_index(int $owner = 0): array
{
    /* The names this organisation sees, and the sheets it built. Ours at
       owner 0; a client's own at their id, and never another client's. */
    $names = fabo_sheet_name_overrides($owner);
    $built = $owner > 0
        ? fabo_all('SELECT * FROM custom_sheets WHERE client_id = ? ORDER BY sort_order, id',
                   'i', [$owner])
        : fabo_all('SELECT * FROM custom_sheets ORDER BY sort_order, id');
    $byKey = [];
    foreach ($built as $b) {
        $byKey[(string) $b['sheet_key']] = $b;
    }

    $out = [];
    foreach (fabo_sheet_order() as $k) {
        if ($k === 'dashboard') {
            continue;
        }
        $raw = fabo_sheet($k, true);
        $now = fabo_sheet($k);
        if (!$now) {
            continue;
        }
        $out[] = [
            'key'      => $k,
            'label'    => $now['label'],
            'original' => $raw['label'],
            'subtitle' => (string) ($now['subtitle'] ?? ''),
            'renamed'  => isset($names[$k]),
            'built'    => !empty($now['custom']),
            'row'      => $byKey[$k] ?? null,
            'columns'  => max(0, count($now['columns']) - 1),   // the row id is not a column
            'live'     => true,
        ];
    }
    foreach ($built as $b) {
        if (empty($b['archived_at'])) {
            continue;
        }
        $out[] = [
            'key'      => (string) $b['sheet_key'],
            'label'    => (string) $b['label'],
            'original' => (string) $b['label'],
            'subtitle' => (string) ($b['subtitle'] ?? ''),
            'renamed'  => false,
            'built'    => true,
            'row'      => $b,
            'columns'  => count(fabo_custom_columns((int) $b['id'])),
            'live'     => false,
        ];
    }
    return $out;
}

/** How many rows a sheet holds, whatever kind of sheet it is. */
function fabo_admin_sheet_rows(string $key, ?array $built): int
{
    try {
        $table = $built ? fabo_custom_table((string) $built['sheet_key'])
                        : (string) (fabo_sheet($key)['table'] ?? '');
        if ($table === '') {
            return 0;
        }
        return (int) (fabo_one('SELECT COUNT(*) c FROM `' . $table . '`')['c'] ?? 0);
    } catch (Throwable $e) {
        return 0;            // a sheet whose table is not built yet counts nought
    }
}

/**
 * One sheet's columns as the page shows them: what it is called now, what it
 * was called when it shipped, what it holds, and which dropdown feeds it.
 */
function fabo_admin_columns(string $key): array
{
    $now = fabo_sheet($key);
    $raw = fabo_sheet($key, true);
    if (!$now) {
        return [];
    }
    $original = [];
    foreach ($raw['columns'] as $c) {
        $original[$c[1]] = $c[0];
    }

    /* The manager lists what the sheet HAS, not what it shows. A column
       somebody has taken off is still here, marked, with a way to put it
       back — otherwise removing one would be a door that locks behind you. */
    $hidden  = fabo_hidden_columns($key);
    $shownBy = [];
    foreach ($now['columns'] as $c) {
        $shownBy[$c[1]] = $c;
    }

    $out = [];
    foreach ($raw['columns'] as $rawCol) {
        $c = $shownBy[$rawCol[1]] ?? $rawCol;
        [$label, $field, $type] = [$c[0], $c[1], $c[2]];
        $opts  = $c[3] ?? [];
        $was   = $original[$field] ?? $label;
        $out[] = [
            'label'     => $label,
            'original'  => $was,
            'renamed'   => $label !== $was,
            'field'     => $field,
            'type'      => $type,
            'group'     => (string) ($opts['group'] ?? ''),
            'list'      => (string) ($opts['list'] ?? ''),
            'calc'      => (string) ($opts['calc'] ?? ''),
            'unlock'    => (string) ($opts['unlock'] ?? ''),
            'extra'     => !empty($opts['extra']),      // added here, not shipped
            'readonly'  => in_array($type, ['computed', 'id'], true),
            'hidden'    => isset($hidden[$field]),
        ];
    }
    /* Added columns are not in the shipped definition, so they are appended
       rather than found above. */
    foreach ($now['columns'] as $c) {
        if (isset($original[$c[1]])) {
            continue;
        }
        $opts  = $c[3] ?? [];
        $out[] = [
            'label' => $c[0], 'original' => $c[0], 'renamed' => false,
            'field' => $c[1], 'type' => $c[2],
            'group' => (string) ($opts['group'] ?? ''),
            'list'  => (string) ($opts['list'] ?? ''),
            'calc'  => (string) ($opts['calc'] ?? ''),
            'unlock' => (string) ($opts['unlock'] ?? ''),
            'extra' => !empty($opts['extra']),
            'readonly' => in_array($c[2], ['computed', 'id'], true),
            'hidden' => false,
        ];
    }

    /*
     * Put them in the order they have been arranged into.
     *
     * This list is built by walking the sheet's DEFINITION, because it has to
     * include the columns somebody has hidden and those are not in what the
     * sheet shows. Walking the definition also meant walking it in the built
     * order, so this table ignored the arrangement entirely — and that is the
     * bug it caused:
     *
     *   the grid and the live sheet honoured the saved order, but the page
     *   you arrange it ON redrew itself in the built order after every save.
     *   It looked as though the save had been thrown away.
     *
     * Worse, the hidden input this page posts is built from this list. So the
     * next save sent the BUILT order back — genuinely undoing the previous
     * arrangement, which is why it sometimes stuck and sometimes did not.
     *
     * The ranking is the same one fabo_sheet() applies, deliberately: the id
     * first, then whatever has a saved place, then anything with none in its
     * defined order. Two lists claiming to show one order have to agree.
     */
    $order = fabo_column_order($key);
    if ($order) {
        $ranked = [];
        foreach ($out as $i => $c) {
            $ranked[] = [
                ($c['type'] ?? '') === 'id'
                    ? fabo_id_rank($key, $order, (string) $c['field'])
                    : ($order[$c['field']] ?? 1000000 + $i),
                $i,
                $c,
            ];
        }
        usort($ranked, static fn($a, $b) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);
        $out = array_column($ranked, 2);
    }
    return $out;
}

/** Plain English for a column type, so the table does not read as jargon. */
function fabo_admin_type_words(): array
{
    return [
        'id'       => 'Reference',
        'text'     => 'Text',
        'long'     => 'Long text',
        'select'   => 'Dropdown',
        'date'     => 'Date',
        'int'      => 'Whole number',
        'dec'      => 'Decimal',
        'money'    => 'Money',
        'pct'      => 'Percentage',
        'url'      => 'Link',
        'file'     => 'File',
        'image'    => 'Picture',
        'images'   => 'Picture gallery',
        'checkbox' => 'Tick box',
        'computed' => 'Worked out',
    ];
}

/**
 * Where a dropdown list is used: every sheet and column that reads from it.
 *
 * This is what makes renaming an option safe to offer — the same answer
 * tells the page what to warn about and tells the rename which tables hold
 * the old value.
 */
function fabo_admin_list_usage(): array
{
    $use = [];
    foreach (fabo_sheets() as $key => $sheet) {
        foreach ($sheet['columns'] as $c) {
            $list = (string) (($c[3]['list'] ?? ''));
            if ($list === '') {
                continue;
            }
            $use[$list][] = [
                'sheet'       => $key,
                'sheet_label' => (string) ($sheet['label'] ?? $key),
                'table'       => (string) ($sheet['table'] ?? ''),
                'field'       => $c[1],
                'column'      => $c[0],
            ];
        }
    }
    return $use;
}

/**
 * Every dropdown list the shared set knows about, with its options.
 *
 * Both the ones in use and the ones left over from a column that has since
 * been renamed or removed, because a list nobody reads is exactly the kind
 * of thing somebody comes here to tidy up.
 */
function fabo_admin_lists(int $owner = 0): array
{
    /* An organisation that has never touched a list has no rows of its own,
       so it is shown the shared defaults — the same options it is already
       seeing in every dropdown. The first edit adopts a copy of them, and
       from then on it reads its own. */
    $rows = fabo_all('SELECT id, list_name, value, sort_order, active
                      FROM lists WHERE client_id = ?
                      ORDER BY list_name, sort_order, value', 'i', [$owner]);
    if (!$rows && $owner > 0) {
        $rows = fabo_all('SELECT id, list_name, value, sort_order, active
                          FROM lists WHERE client_id = 0
                          ORDER BY list_name, sort_order, value');
    }
    $out = [];
    foreach ($rows as $r) {
        $out[(string) $r['list_name']][] = [
            'id'     => (int) $r['id'],
            'value'  => (string) $r['value'],
            'sort'   => (int) $r['sort_order'],
            'active' => (int) $r['active'] === 1,
        ];
    }
    // A list a column asks for but nobody has filled in yet still belongs
    // here, or there would be no way to give it its first option.
    foreach (array_keys(fabo_admin_list_usage()) as $name) {
        if (!isset($out[$name]) && !fabo_is_dynamic_list($name)) {
            $out[$name] = [];
        }
    }
    ksort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

/* --------------------------------------------------------------- writing */

/**
 * Rename a column on a shipped sheet.
 *
 * Setting it back to what it shipped as removes the override rather than
 * storing a second copy of the same words, so "renamed" keeps meaning what
 * it says and the reset button has something to do.
 */
function fabo_admin_rename_column(string $key, string $field, string $label,
                                  int $owner = 0): string
{
    $raw = fabo_sheet($key, true);
    if (!$raw) {
        throw new InvalidArgumentException('That sheet was not found.');
    }
    $label = trim($label);
    if ($label === '') {
        throw new InvalidArgumentException('Give the column a name.');
    }
    $was = null;
    foreach ($raw['columns'] as $c) {
        if ($c[1] === $field) {
            $was = $c[0];
            break;
        }
    }
    if ($was === null) {
        throw new InvalidArgumentException('That column is not on this sheet.');
    }

    fabo_exec('DELETE FROM column_labels WHERE sheet_key = ? AND field = ? AND client_id = ?',
              'ssi', [$key, $field, $owner]);
    if ($label === $was) {
        return 'Put back to “' . $was . '”.';
    }
    fabo_exec('INSERT INTO column_labels (sheet_key, field, label, client_id) VALUES (?,?,?,?)',
              'sssi', [$key, $field, mb_substr($label, 0, 160), $owner]);
    return 'The column reads “' . $label . '” everywhere now.';
}

/** Forget a rename, whatever it was. */
function fabo_admin_reset_column(string $key, string $field, int $owner = 0): void
{
    fabo_exec('DELETE FROM column_labels WHERE sheet_key = ? AND field = ? AND client_id = ?',
              'ssi', [$key, $field, $owner]);
}

/** The next place in the order, so a new option lands at the end. */
function fabo_admin_list_next_sort(string $list, int $owner = 0): int
{
    $m = fabo_one('SELECT MAX(sort_order) m FROM lists WHERE list_name = ? AND client_id = ?',
                  'si', [$list, $owner]);
    return (int) (($m['m'] ?? 0)) + 10;
}

/** Add an option to a shared list. Bringing back a removed one counts as adding. */
function fabo_admin_list_add(string $list, string $value, int $owner = 0): string
{
    $list  = trim($list);
    $value = trim($value);
    if ($list === '' || $value === '') {
        throw new InvalidArgumentException('Give the list a name and the option a value.');
    }
    if (fabo_is_dynamic_list($list)) {
        throw new InvalidArgumentException('That list is built from live data and cannot be edited.');
    }
    /* The first change an organisation makes takes a copy of the shared
       defaults, so it starts from what it was already seeing rather than
       from nothing. Adding to a list it has never touched would otherwise
       leave it with one option where it used to see twelve.

       Before the check below, never after: the copy may itself bring across
       the value being added, and finding that out from a unique key is a
       database error rather than an answer. */
    require_once __DIR__ . '/lists.php';
    fabo_list_adopt($owner, $list);
    $have = fabo_one('SELECT id, active FROM lists
                      WHERE list_name = ? AND value = ? AND client_id = ?',
                     'ssi', [$list, $value, $owner]);
    if ($have) {
        if ((int) $have['active'] === 1) {
            throw new InvalidArgumentException('“' . $value . '” is already on that list.');
        }
        fabo_exec('UPDATE lists SET active = 1 WHERE id = ?', 'i', [(int) $have['id']]);
        return '“' . $value . '” is back on the list.';
    }
    fabo_exec('INSERT INTO lists (list_name, value, sort_order, active, client_id)
               VALUES (?,?,?,1,?)', 'ssii',
              [mb_substr($list, 0, 255), mb_substr($value, 0, 255),
               fabo_admin_list_next_sort($list, $owner), $owner]);
    return '“' . $value . '” added.';
}

/**
 * Rename an option, and carry the rows that hold it along with it.
 *
 * A dropdown value is stored as the words themselves, so renaming the option
 * alone would leave every row that used it holding text no dropdown offers.
 * The columns that read this list are known, so the rows can be brought
 * across — which is what somebody means by "rename".
 */
function fabo_admin_list_rename(int $id, string $value, int $owner = 0): string
{
    $value = trim($value);
    if ($value === '') {
        throw new InvalidArgumentException('Give the option a value.');
    }
    $row = fabo_one('SELECT * FROM lists WHERE id = ? AND client_id = ?', 'ii', [$id, $owner]);
    if (!$row) {
        throw new InvalidArgumentException('That option was not found.');
    }
    $list = (string) $row['list_name'];
    $old  = (string) $row['value'];
    if ($old === $value) {
        return 'Nothing to change.';
    }
    $clash = fabo_one('SELECT id FROM lists WHERE list_name = ? AND value = ? AND client_id = ?',
                      'ssi', [$list, $value, $owner]);
    if ($clash) {
        throw new InvalidArgumentException('“' . $value . '” is already on that list.');
    }

    fabo_exec('UPDATE lists SET value = ? WHERE id = ?', 'si', [mb_substr($value, 0, 255), $id]);

    $moved = 0;
    foreach (fabo_admin_list_usage()[$list] ?? [] as $u) {
        if ($u['table'] === '') {
            continue;
        }
        try {
            // fabo_exec returns the rows an UPDATE touched: no insert id to
            // stand in front of it.
            /* Scoped to the organisation doing the renaming. Without this,
               a client tidying up their own dropdown would rewrite the same
               words out of every other client's rows. */
            $moved += $owner > 0
                ? fabo_exec('UPDATE `' . $u['table'] . '` SET `' . $u['field'] . '` = ? '
                          . 'WHERE `' . $u['field'] . '` = ? AND client_id = ?',
                            'ssi', [$value, $old, $owner])
                : fabo_exec('UPDATE `' . $u['table'] . '` SET `' . $u['field'] . '` = ? '
                          . 'WHERE `' . $u['field'] . '` = ?', 'ss', [$value, $old]);
        } catch (Throwable $e) {
            error_log('FABO list rename ' . $u['table'] . '.' . $u['field'] . ': ' . $e->getMessage());
        }
    }
    return $moved > 0
        ? 'Renamed, and ' . $moved . ' row' . ($moved === 1 ? '' : 's') . ' moved across with it.'
        : 'Renamed.';
}

/** Take an option off the list without losing the rows that hold it. */
function fabo_admin_list_remove(int $id, int $owner = 0): string
{
    $row = fabo_one('SELECT * FROM lists WHERE id = ? AND client_id = ?', 'ii', [$id, $owner]);
    if (!$row) {
        throw new InvalidArgumentException('That option was not found.');
    }
    fabo_exec('UPDATE lists SET active = 0 WHERE id = ?', 'i', [$id]);
    return '“' . $row['value'] . '” is off the list. Rows that already hold it keep it.';
}

/** Put a removed option back. */
function fabo_admin_list_restore(int $id, int $owner = 0): void
{
    fabo_exec('UPDATE lists SET active = 1 WHERE id = ? AND client_id = ?', 'ii', [$id, $owner]);
}

/**
 * Move an option up or down by swapping places with its neighbour.
 *
 * Sort orders in the shipped data are spaced ten apart and some are equal,
 * so the order is settled here first and written back as 10, 20, 30 — after
 * which a swap is a swap and cannot leave two options tied.
 */
function fabo_admin_list_move(int $id, string $dir, int $owner = 0): void
{
    $row = fabo_one('SELECT * FROM lists WHERE id = ? AND client_id = ?', 'ii', [$id, $owner]);
    if (!$row) {
        throw new InvalidArgumentException('That option was not found.');
    }
    $list = (string) $row['list_name'];
    $all  = fabo_all('SELECT id FROM lists WHERE list_name = ? AND client_id = ?
                      ORDER BY sort_order, value', 'si', [$list, $owner]);

    $ids = array_map(static fn($r) => (int) $r['id'], $all);
    $at  = array_search($id, $ids, true);
    if ($at === false) {
        return;
    }
    $to = $dir === 'up' ? $at - 1 : $at + 1;
    if ($to < 0 || $to >= count($ids)) {
        return;                     // already at the end it is being pushed towards
    }
    [$ids[$at], $ids[$to]] = [$ids[$to], $ids[$at]];

    foreach ($ids as $i => $one) {
        fabo_exec('UPDATE lists SET sort_order = ? WHERE id = ?', 'ii', [($i + 1) * 10, $one]);
    }
}

/** Start a new list with its first option — an empty list has nothing to show. */
function fabo_admin_list_create(string $list, string $first, int $owner = 0): string
{
    $list = trim($list);
    if ($list === '') {
        throw new InvalidArgumentException('Give the list a name.');
    }
    if ($list[0] === '@') {
        throw new InvalidArgumentException('A name starting with @ is reserved for lists built from live data.');
    }
    if (fabo_one('SELECT id FROM lists WHERE list_name = ? AND client_id = ?',
                 'si', [$list, $owner])) {
        throw new InvalidArgumentException('There is already a list called “' . $list . '”.');
    }
    return fabo_admin_list_add($list, $first, $owner);
}

/* ------------------------------------- columns added to a shipped sheet */

/**
 * A field name for an added column: from the label, and never colliding with
 * a column the sheet already has — shipped, added, or left in the table by an
 * earlier removal, because adding one back would otherwise land on data that
 * is not this column's.
 */
function fabo_extra_field(string $key, string $label): string
{
    $base = strtolower(trim(preg_replace('~[^a-zA-Z0-9]+~', '_', $label), '_'));
    $base = substr($base ?: 'field', 0, 48);
    if (is_numeric($base[0] ?? '')) {
        $base = 'f' . $base;
    }
    $taken = ['id' => true, 'client_id' => true, 'created_at' => true, 'updated_at' => true];
    foreach ((fabo_sheet($key, true)['columns'] ?? []) as $c) {
        $taken[$c[1]] = true;
    }
    foreach (fabo_sheet_extras($key) as $x) {
        $taken[(string) $x['field']] = true;
    }
    foreach (fabo_admin_table_columns($key) as $f) {
        $taken[$f] = true;
    }
    $field = $base;
    $n     = 2;
    while (isset($taken[$field])) {
        $field = substr($base, 0, 44) . '_' . $n++;
    }
    return $field;
}

/** Adds the column to the sheet's own table, if it is not already there. */
function fabo_extra_sync_column(string $key, string $field, string $type): void
{
    $table = (string) (fabo_sheet($key, true)['table'] ?? '');
    if ($table === '') {
        throw new InvalidArgumentException('That sheet has no table to add a column to.');
    }
    if (in_array($field, fabo_admin_table_columns($key), true)) {
        return;
    }
    $db = fabo_db();
    if (!$db->query('ALTER TABLE `' . $table . '` ADD COLUMN `' . $field . '` '
                  . fabo_sql_type($type))) {
        throw new InvalidArgumentException('The column could not be added: ' . $db->error);
    }
}

/** Adds a column to a shipped sheet, and to the table underneath it. */
function fabo_extra_add(string $key, array $in, int $owner = 0): int
{
    $sheet = fabo_sheet($key, true);
    if (!$sheet) {
        throw new InvalidArgumentException('That sheet was not found.');
    }
    if (!empty(fabo_sheet($key)['custom'])) {
        throw new InvalidArgumentException('That sheet was built here — use its own columns.');
    }
    $label = trim((string) ($in['label'] ?? ''));
    $type  = (string) ($in['type'] ?? 'text');
    if ($label === '') {
        throw new InvalidArgumentException('Give the column a name.');
    }
    /* Two columns on one sheet with the same heading is not an error the
       database would catch — the field names differ — but it is a sheet
       nobody can read, and the second one is almost always a double click. */
    if (fabo_one('SELECT id FROM sheet_extras
                  WHERE sheet_key = ? AND client_id = ? AND label = ?',
                 'sis', [$key, $owner, $label])) {
        throw new InvalidArgumentException(
            'This sheet already has a column called “' . $label . '”.');
    }
    if (!isset(fabo_builder_types()[$type])) {
        throw new InvalidArgumentException('That is not a column type.');
    }
    $list = trim((string) ($in['list_name'] ?? ''));
    if ($type === 'select' && $list === '') {
        // A dropdown with no list behind it renders empty forever, so it gets
        // one named after itself, ready to fill in on the Options tab.
        $list = mb_substr($label, 0, 80);
    }

    /* A gallery holds the row's pictures rather than a column's, so a second
       one on the same sheet would show exactly what the first one shows. */
    if ($type === 'images') {
        foreach ((fabo_sheet($key)['columns'] ?? []) as $c) {
            if ($c[2] === 'images') {
                throw new InvalidArgumentException(
                    'This sheet already has a picture gallery (' . $c[0] . '). A gallery holds '
                  . "the row's pictures, so a second one would show the same ones.");
            }
        }
    }

    $field = fabo_extra_field($key, $label);
    $max   = (int) (fabo_one('SELECT MAX(sort_order) m FROM sheet_extras
                              WHERE sheet_key = ? AND client_id = ?',
                             'si', [$key, $owner])['m'] ?? 0);

    // The table first: a column the sheet shows but the table has not got is
    // an error on every row, where the other way round is merely unused.
    fabo_extra_sync_column($key, $field, $type);
    $id = (int) fabo_exec(
        'INSERT INTO sheet_extras (sheet_key, client_id, field, label, type, list_name, sort_order)
         VALUES (?,?,?,?,?,?,?)', 'sissssi',
        [$key, $owner, $field, mb_substr($label, 0, 120), $type,
         $type === 'select' ? $list : null, $max + 10]
    );
    fabo_schema_generation(true);       // the shape of that sheet just changed
    return $id;
}

/** Renames an added column, or repoints its dropdown. The type never changes. */
function fabo_extra_edit(int $id, array $in, int $owner = 0): void
{
    $row = fabo_one('SELECT * FROM sheet_extras WHERE id = ? AND client_id = ?',
                    'ii', [$id, $owner]);
    if (!$row) {
        throw new InvalidArgumentException('That column was not found.');
    }
    $label = trim((string) ($in['label'] ?? ''));
    if ($label === '') {
        throw new InvalidArgumentException('Give the column a name.');
    }
    $list = $row['type'] === 'select'
        ? mb_substr(trim((string) ($in['list_name'] ?? $row['list_name'] ?? $label)), 0, 80)
        : null;
    fabo_exec('UPDATE sheet_extras SET label = ?, list_name = ? WHERE id = ?', 'ssi',
              [mb_substr($label, 0, 120), $list, $id]);
    fabo_schema_generation(true);
}

/**
 * Takes an added column off the sheet.
 *
 * The table column stays, and the data in it with it — the same rule the
 * builder follows, for the same reason: "I removed a column" should not mean
 * "I deleted a month of work". Adding a column of that name again picks up a
 * new field rather than inheriting the old data.
 */
function fabo_extra_remove(int $id, int $owner = 0): void
{
    fabo_exec('DELETE FROM sheet_extras WHERE id = ? AND client_id = ?', 'ii', [$id, $owner]);
    fabo_schema_generation(true);
}

/** Moves an added column past its neighbour. Shipped columns do not move. */
function fabo_extra_move(int $id, string $dir, int $owner = 0): void
{
    $row = fabo_one('SELECT * FROM sheet_extras WHERE id = ? AND client_id = ?',
                    'ii', [$id, $owner]);
    if (!$row) {
        return;
    }
    $ids = array_map(static fn($r) => (int) $r['id'],
                     fabo_sheet_extras((string) $row['sheet_key'], $owner));
    $at  = array_search($id, $ids, true);
    if ($at === false) {
        return;
    }
    $to = $dir === 'up' ? $at - 1 : $at + 1;
    if ($to < 0 || $to >= count($ids)) {
        return;                         // already at the end it is pushed towards
    }
    [$ids[$at], $ids[$to]] = [$ids[$to], $ids[$at]];
    foreach ($ids as $i => $one) {
        fabo_exec('UPDATE sheet_extras SET sort_order = ? WHERE id = ?', 'ii', [($i + 1) * 10, $one]);
    }
    fabo_schema_generation(true);
}

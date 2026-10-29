<?php
/**
 * Sheets you build yourself.
 *
 * The eleven sheets the portal ships with are defined in code, because they
 * are the product. This is for the twelfth — the one a particular business
 * needs and nobody else does. A sheet made here behaves like any other: it
 * has a grid, permissions, an export, dropdowns and column renaming, and it
 * appears in the navigation beside the rest.
 *
 * Two rules keep that from becoming a mess:
 *
 *   1. A custom sheet belongs to an organisation. Ours (client_id 0) are
 *      everybody's; a client's are theirs alone, and nobody else ever sees
 *      the sheet or its table.
 *   2. Its data lives in its own table, `cs_<key>`, built from the columns
 *      and altered when they change. A column is never dropped when it is
 *      removed from the builder — the column stops being shown and the data
 *      stays, because "I deleted a column" should not mean "I deleted a
 *      month of work".
 */

require_once __DIR__ . '/db.php';

/** The column types a builder offers, as type => label. */
function fabo_builder_types(): array
{
    return [
        'text'     => 'Text',
        'long'     => 'Long text',
        'select'   => 'Dropdown',
        'date'     => 'Date',
        'int'      => 'Whole number',
        'dec'      => 'Decimal',
        'money'    => 'Money',
        'url'      => 'Link',
        'file'     => 'File attachment',
        'image'    => 'Image',
        'images'   => 'Picture gallery',
        'checkbox' => 'Yes / no',
    ];
}

/** A one-line explanation of each, for the builder. */
function fabo_builder_type_notes(): array
{
    return [
        'text'     => 'A single line.',
        'long'     => 'Several lines, for notes.',
        'select'   => 'Choose from a list you control.',
        'date'     => 'A date picker.',
        'int'      => 'Counts, quantities.',
        'dec'      => 'Anything with a decimal point.',
        'money'    => 'Shown with the rupee symbol and grouped.',
        'url'      => 'A web address, shown as a link.',
        'file'     => 'One file per row — a PDF, a document, anything.',
        'image'    => 'One picture per row, shown as a thumbnail.',
        'images'   => 'Any number of pictures per row, shown as a gallery.',
        'checkbox' => 'Ticked or not.',
    ];
}

/** Whether a type stores an uploaded file rather than a typed value. */
function fabo_is_upload_type(string $type): bool
{
    return $type === 'file' || $type === 'image';
}

/** The table a custom sheet's rows live in. */
function fabo_custom_table(string $key): string
{
    return 'cs_' . preg_replace('~[^a-z0-9_]~', '', $key);
}

/**
 * A key from a name: lowercase, underscores, never colliding with a built-in
 * sheet or an existing custom one.
 */
function fabo_builder_key(string $label): string
{
    $base = strtolower(trim(preg_replace('~[^a-zA-Z0-9]+~', '_', $label), '_'));
    $base = substr($base ?: 'sheet', 0, 32);
    if (is_numeric($base[0] ?? '')) {
        $base = 's' . $base;
    }
    $taken = array_flip(array_merge(
        ['dashboard', 'lists', 'master', 'store', 'calendar', 'content', 'website',
         'design', 'campaigns', 'influencers', 'btl', 'events', 'finance', 'meetings'],
        array_column(fabo_all('SELECT sheet_key FROM custom_sheets'), 'sheet_key')
    ));
    $key = $base;
    $n   = 2;
    while (isset($taken[$key])) {
        $key = substr($base, 0, 28) . '_' . $n++;
    }
    return $key;
}

/** A field name from a label, unique within the sheet. */
function fabo_builder_field(int $sheetId, string $label): string
{
    $base = strtolower(trim(preg_replace('~[^a-zA-Z0-9]+~', '_', $label), '_'));
    $base = substr($base ?: 'field', 0, 48);
    if (is_numeric($base[0] ?? '')) {
        $base = 'f' . $base;
    }
    // `id`, `client_id` and the timestamps belong to the table, not to a column.
    $taken = array_flip(array_merge(['id', 'client_id', 'created_at', 'updated_at'],
        array_column(fabo_all('SELECT field FROM custom_columns WHERE sheet_id = ?',
                              'i', [$sheetId]), 'field')));
    $field = $base;
    $n     = 2;
    while (isset($taken[$field])) {
        $field = substr($base, 0, 44) . '_' . $n++;
    }
    return $field;
}

/* ------------------------------------------------------------- reading */

/**
 * The custom sheets one organisation can see: ours, plus their own.
 *
 * Scoped exactly like dropdown options are, so a client building a sheet
 * cannot put it in front of another client.
 */
function fabo_custom_sheets(?int $owner = null): array
{
    require_once __DIR__ . '/lists.php';
    $owner = $owner === null ? fabo_view_owner() : max(0, $owner);
    static $cache = [];
    $ck = $owner . ':' . fabo_schema_generation();
    if (isset($cache[$ck])) {
        return $cache[$ck];
    }
    try {
        $rows = $owner > 0
            ? fabo_all('SELECT * FROM custom_sheets WHERE archived_at IS NULL
                        AND client_id IN (0, ?) ORDER BY sort_order, id', 'i', [$owner])
            : fabo_all('SELECT * FROM custom_sheets WHERE archived_at IS NULL
                        AND client_id = 0 ORDER BY sort_order, id');
    } catch (Throwable $e) {
        return $cache[$ck] = [];           // table not built yet
    }
    return $cache[$ck] = $rows;
}

function fabo_custom_sheet(int $id): ?array
{
    return fabo_one('SELECT * FROM custom_sheets WHERE id = ?', 'i', [$id]);
}

/**
 * Does this scope own that sheet?
 *
 * Every mutator below takes an id, and an id is a number somebody can type
 * into a form. The scope on the page says which organisation is asking; this
 * says whether the thing they named is theirs. Without the pair of them, a
 * client managing their own sheets could rename ours by guessing.
 *
 * Scope 0 is us, and we own everything — including, deliberately, the
 * sheets a client built, because somebody has to be able to clean up.
 */
function fabo_builder_owns(int $sheetId, int $scope): bool
{
    if ($scope === 0) {
        return true;
    }
    $row = fabo_one('SELECT client_id FROM custom_sheets WHERE id = ?', 'i', [$sheetId]);
    return $row !== null && (int) ($row['client_id'] ?? 0) === $scope;
}

/** The same question for a column, answered through the sheet it sits on. */
function fabo_builder_owns_column(int $columnId, int $scope): bool
{
    if ($scope === 0) {
        return true;
    }
    $col = fabo_one('SELECT sheet_id FROM custom_columns WHERE id = ?', 'i', [$columnId]);
    return $col !== null && fabo_builder_owns((int) $col['sheet_id'], $scope);
}

/** Throws unless the scope owns the sheet. */
function fabo_builder_require(int $sheetId, int $scope): void
{
    if (!fabo_builder_owns($sheetId, $scope)) {
        throw new InvalidArgumentException('That sheet is not yours to change.');
    }
}

/** Throws unless the scope owns the column. */
function fabo_builder_require_column(int $columnId, int $scope): void
{
    if (!fabo_builder_owns_column($columnId, $scope)) {
        throw new InvalidArgumentException('That column is not yours to change.');
    }
}

function fabo_custom_sheet_by_key(string $key): ?array
{
    return fabo_one('SELECT * FROM custom_sheets WHERE sheet_key = ?', 's', [$key]);
}

/** A sheet's columns, in order. */
function fabo_custom_columns(int $sheetId): array
{
    try {
        return fabo_all('SELECT * FROM custom_columns WHERE sheet_id = ?
                         ORDER BY sort_order, id', 'i', [$sheetId]);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Every custom sheet as a definition fabo_sheets() can merge in.
 *
 * The shape is exactly the shipped one — [label, field, type, opts] — so
 * everything downstream (grid, export, compute, permissions) treats a built
 * sheet and a shipped one identically, with no special cases.
 */
function fabo_custom_sheet_defs(?int $owner = null): array
{
    $out = [];
    foreach (fabo_custom_sheets($owner) as $s) {
        $cols = [['Row ID', 'row_ref', 'id']];
        foreach (fabo_custom_columns((int) $s['id']) as $c) {
            $opts = [];
            if ($c['type'] === 'select' && !empty($c['list_name'])) {
                $opts['list'] = $c['list_name'];
            }
            $cols[] = [(string) $c['label'], (string) $c['field'], (string) $c['type'], $opts];
        }
        $out[(string) $s['sheet_key']] = [
            'label'    => (string) $s['label'],
            'title'    => strtoupper((string) $s['label']),
            'subtitle' => (string) ($s['subtitle'] ?? ''),
            'table'    => fabo_custom_table((string) $s['sheet_key']),
            'prefix'   => strtoupper(substr(preg_replace('~[^A-Za-z]~', '',
                             (string) $s['sheet_key']) ?: 'ROW', 0, 3)) . '-',
            'pad'      => 3,
            'id_field' => 'row_ref',
            'columns'  => $cols,
            'custom'   => true,
            'custom_id'=> (int) $s['id'],
        ];
    }
    return $out;
}

/** Renames of the shipped sheets, per organisation: key => [label, subtitle]. */
function fabo_sheet_name_overrides(?int $owner = null): array
{
    require_once __DIR__ . '/lists.php';
    $owner = $owner === null ? fabo_view_owner() : max(0, $owner);
    static $cache = [];
    $ck = $owner . ':' . fabo_schema_generation();
    if (isset($cache[$ck])) {
        return $cache[$ck];
    }
    try {
        $rows = fabo_all('SELECT sheet_key, label, subtitle, client_id FROM sheet_labels
                          WHERE client_id IN (0, ?) ORDER BY client_id', 'i', [$owner]);
    } catch (Throwable $e) {
        return $cache[$ck] = [];
    }
    $out = [];
    foreach ($rows as $r) {                 // the org's own row comes last and wins
        $out[(string) $r['sheet_key']] = [
            'label'    => (string) $r['label'],
            'subtitle' => (string) ($r['subtitle'] ?? ''),
        ];
    }
    return $cache[$ck] = $out;
}

/* ------------------------------------------------------------- writing */

/** Creates a sheet and its table. Returns the new id. */
function fabo_builder_create(string $label, ?int $clientId, string $subtitle = ''): int
{
    fabo_schema_generation(true);   // the set of sheets just changed
    $label = trim($label);
    if ($label === '') {
        throw new InvalidArgumentException('Give the sheet a name.');
    }
    $key = fabo_builder_key($label);
    $max = (int) (fabo_one('SELECT MAX(sort_order) m FROM custom_sheets')['m'] ?? 0);

    $id = (int) fabo_exec(
        'INSERT INTO custom_sheets (sheet_key, label, subtitle, client_id, sort_order)
         VALUES (?,?,?,?,?)', 'sssii',
        [$key, mb_substr($label, 0, 120), mb_substr(trim($subtitle), 0, 255),
         $clientId ?: 0, $max + 10]
    );
    fabo_builder_sync_table($id);
    return $id;
}

function fabo_builder_rename(int $id, string $label, string $subtitle = ''): void
{
    fabo_schema_generation(true);   // the set of sheets just changed
    $label = trim($label);
    if ($label === '') {
        throw new InvalidArgumentException('Give the sheet a name.');
    }
    fabo_exec('UPDATE custom_sheets SET label = ?, subtitle = ? WHERE id = ?', 'ssi',
              [mb_substr($label, 0, 120), mb_substr(trim($subtitle), 0, 255), $id]);
}

/**
 * Archives a sheet: it leaves the navigation, and its table is left alone.
 * Deliberately not a delete — a sheet holds work, and one wrong click should
 * not be able to destroy it.
 */
function fabo_builder_archive(int $id): void
{
    fabo_schema_generation(true);   // the set of sheets just changed
    fabo_exec('UPDATE custom_sheets SET archived_at = NOW() WHERE id = ?', 'i', [$id]);
}

function fabo_builder_restore(int $id): void
{
    fabo_schema_generation(true);   // the set of sheets just changed
    fabo_exec('UPDATE custom_sheets SET archived_at = NULL WHERE id = ?', 'i', [$id]);
}

/** Adds a column and widens the table to hold it. Returns the new id. */
function fabo_builder_add_column(int $sheetId, array $in): int
{
    fabo_schema_generation(true);   // the set of sheets just changed
    $label = trim((string) ($in['label'] ?? ''));
    $type  = (string) ($in['type'] ?? 'text');
    if ($label === '') {
        throw new InvalidArgumentException('Give the column a name.');
    }
    if (!isset(fabo_builder_types()[$type])) {
        throw new InvalidArgumentException('That is not a column type.');
    }
    $list = trim((string) ($in['list_name'] ?? ''));
    if ($type === 'select' && $list === '') {
        // A dropdown with no list behind it would render empty forever, so it
        // gets one named after itself, ready to fill in from the sheet.
        $list = mb_substr($label, 0, 80);
    }
    $max = (int) (fabo_one('SELECT MAX(sort_order) m FROM custom_columns WHERE sheet_id = ?',
                           'i', [$sheetId])['m'] ?? 0);

    $id = (int) fabo_exec(
        'INSERT INTO custom_columns (sheet_id, field, label, type, list_name, sort_order)
         VALUES (?,?,?,?,?,?)', 'issssi',
        [$sheetId, fabo_builder_field($sheetId, $label), mb_substr($label, 0, 120),
         $type, $type === 'select' ? $list : null, $max + 10]
    );
    fabo_builder_sync_table($sheetId);
    return $id;
}

/** Renames a column, or repoints a dropdown. The type never changes. */
function fabo_builder_edit_column(int $columnId, array $in): void
{
    fabo_schema_generation(true);   // the set of sheets just changed
    $label = trim((string) ($in['label'] ?? ''));
    if ($label === '') {
        throw new InvalidArgumentException('Give the column a name.');
    }
    $col = fabo_one('SELECT * FROM custom_columns WHERE id = ?', 'i', [$columnId]);
    if (!$col) {
        throw new InvalidArgumentException('That column was not found.');
    }
    $list = $col['type'] === 'select'
        ? mb_substr(trim((string) ($in['list_name'] ?? $col['list_name'] ?? $label)), 0, 80)
        : null;
    fabo_exec('UPDATE custom_columns SET label = ?, list_name = ? WHERE id = ?', 'ssi',
              [mb_substr($label, 0, 120), $list, $columnId]);
}

/**
 * Takes a column off the sheet. The database column is left in place and the
 * data with it — see the note at the top of this file.
 */
function fabo_builder_remove_column(int $columnId): void
{
    fabo_schema_generation(true);   // the set of sheets just changed
    fabo_exec('DELETE FROM custom_columns WHERE id = ?', 'i', [$columnId]);
}

/** Moves a column one place up or down. */
function fabo_builder_move_column(int $columnId, string $dir): void
{
    fabo_schema_generation(true);   // the set of sheets just changed
    $col = fabo_one('SELECT * FROM custom_columns WHERE id = ?', 'i', [$columnId]);
    if (!$col) {
        return;
    }
    $cols = fabo_custom_columns((int) $col['sheet_id']);
    foreach ($cols as $i => $c) {
        if ((int) $c['id'] !== $columnId) {
            continue;
        }
        $j = $dir === 'up' ? $i - 1 : $i + 1;
        if (!isset($cols[$j])) {
            return;
        }
        // Normalise to 10, 20, 30… then swap the two, so repeated moves stay sane.
        foreach ($cols as $k => $cc) {
            fabo_exec('UPDATE custom_columns SET sort_order = ? WHERE id = ?', 'ii',
                      [($k + 1) * 10, (int) $cc['id']]);
        }
        fabo_exec('UPDATE custom_columns SET sort_order = ? WHERE id = ?', 'ii',
                  [($j + 1) * 10, $columnId]);
        fabo_exec('UPDATE custom_columns SET sort_order = ? WHERE id = ?', 'ii',
                  [($i + 1) * 10, (int) $cols[$j]['id']]);
        return;
    }
}

/**
 * Brings a custom sheet's table in line with its columns.
 *
 * Only ever creates the table and adds columns. Nothing is dropped or
 * retyped: a change that could lose a column of data is not something a
 * builder screen should be able to do by itself.
 */
function fabo_builder_sync_table(int $sheetId): void
{
    $sheet = fabo_custom_sheet($sheetId);
    if (!$sheet) {
        return;
    }
    $db    = fabo_db();
    $table = fabo_custom_table((string) $sheet['sheet_key']);

    try {
        $db->query('CREATE TABLE IF NOT EXISTS `' . $table . '` ('
            . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . "`row_ref` VARCHAR(24) NOT NULL DEFAULT '',"
            . '`client_id` INT UNSIGNED NULL,'
            . '`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
            . '`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
            . 'PRIMARY KEY (`id`), KEY `k_owner` (`client_id`), KEY `k_ref` (`row_ref`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $have = [];
        $res  = $db->query('SHOW COLUMNS FROM `' . $table . '`');
        while ($res && $r = $res->fetch_assoc()) {
            $have[$r['Field']] = true;
        }
        foreach (fabo_custom_columns($sheetId) as $c) {
            if (isset($have[$c['field']])) {
                continue;
            }
            $db->query('ALTER TABLE `' . $table . '` ADD COLUMN `' . $c['field'] . '` '
                     . fabo_builder_sql_type((string) $c['type']));
        }
    } catch (Throwable $e) {
        error_log('FABO builder sync ' . $table . ': ' . $e->getMessage());
    }
}

/** The storage for a builder type. An upload holds the attachment's id. */
function fabo_builder_sql_type(string $type): string
{
    switch ($type) {
        case 'long':     return 'TEXT NULL';
        case 'date':     return 'DATE NULL';
        case 'int':      return 'INT NULL';
        case 'dec':      return 'DECIMAL(12,4) NULL';
        case 'money':    return 'DECIMAL(14,2) NULL';
        case 'url':      return 'VARCHAR(512) NULL';
        case 'checkbox': return 'TINYINT(1) NULL';
        case 'file':
        case 'image':    return 'INT UNSIGNED NULL';
        // A gallery is not a value: the pictures are attachments on the row
        // and the column is only where they are shown.
        case 'images':   return 'TINYINT(1) NULL';
        default:         return 'VARCHAR(255) NULL';
    }
}

/* ------------------------------------- columns added to a shipped sheet
 *
 * These live here rather than with the rest of the Sheets page because
 * fabo_sheets() reads them on every request, and db.php loads this file
 * on every request. Writing them is the admin page's business; knowing
 * about them is the portal's.
 */

/**
 * The columns somebody has added to the sheets that shipped with the portal.
 *
 * Read by fabo_sheets(), so an added column is a column like any other from
 * that point on: the grid edits it, the export carries it, permissions cover
 * it, and a report that walks the columns finds it.
 *
 * Scoped the way dropdown options are — 0 is the shared set, an organisation
 * sees theirs on top of it — so one business adding a column cannot put it on
 * another's sheet.
 */
function fabo_sheet_extra_defs(?int $owner = null): array
{
    $owner = $owner === null
        ? (function_exists('fabo_view_owner') ? fabo_view_owner() : 0)
        : max(0, $owner);

    static $cache = [];
    $ck = $owner . ':' . fabo_schema_generation();
    if (isset($cache[$ck])) {
        return $cache[$ck];
    }
    try {
        $rows = $owner > 0
            ? fabo_all('SELECT * FROM sheet_extras WHERE client_id IN (0, ?)
                        ORDER BY sheet_key, sort_order, id', 'i', [$owner])
            : fabo_all('SELECT * FROM sheet_extras WHERE client_id = 0
                        ORDER BY sheet_key, sort_order, id');
    } catch (Throwable $e) {
        return $cache[$ck] = [];        // the table is not there yet
    }

    $out = [];
    foreach ($rows as $r) {
        $opts = [];
        if ($r['type'] === 'select' && !empty($r['list_name'])) {
            $opts['list'] = (string) $r['list_name'];
        }
        $opts['extra'] = true;          // so the page can tell them apart
        $out[(string) $r['sheet_key']][] =
            [(string) $r['label'], (string) $r['field'], (string) $r['type'], $opts];
    }
    return $cache[$ck] = $out;
}

/** The added columns on one sheet, as stored rather than as merged. */
function fabo_sheet_extras(string $key, int $owner = 0): array
{
    try {
        return fabo_all('SELECT * FROM sheet_extras
                         WHERE sheet_key = ? AND client_id = ?
                         ORDER BY sort_order, id', 'si', [$key, $owner]);
    } catch (Throwable $e) {
        return [];
    }
}

/** What the sheet's table actually has, which is not always what it shows. */
function fabo_admin_table_columns(string $key): array
{
    $table = (string) (fabo_sheet($key, true)['table'] ?? '');
    if ($table === '') {
        return [];
    }
    try {
        $out = [];
        foreach (fabo_all('SHOW COLUMNS FROM `' . $table . '`') as $r) {
            $out[] = (string) $r['Field'];
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

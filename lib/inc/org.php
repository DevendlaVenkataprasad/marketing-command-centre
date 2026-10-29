<?php
/**
 * Incubation Management — whose Incubation Management this is.
 *
 * Every organisation that runs the module has its own, entirely separate set
 * of tables. Ours (organisation 0: the administrator and our own staff) are
 * the original inc_* tables. A client organisation granted the module on the
 * Clients page gets inc_o<client id>_* -- created the first time one of them
 * opens it -- and a client's team members work in their head's set.
 *
 * Every query in lib/inc goes through inc_one() / inc_all() / inc_exec(),
 * which rewrite the inc_ table names (and the fk_inc_ constraint names, which
 * must be unique across the database) for the organisation in use. One
 * organisation therefore cannot read or write another's applications, forms,
 * teams or settings: the rows are not in the tables it queries.
 *
 * The organisation is fixed by inc_start() from the viewer; the public
 * application page and cron.php name it explicitly.
 */

/** The organisation whose tables are in use: 0 for ours, else the client head's id. */
function inc_org(): int
{
    return (int) ($GLOBALS['inc_org'] ?? 0);
}

/** Switches to another organisation's tables (cron.php walks them one by one). */
function inc_use_org(int $org): void
{
    $org = max(0, $org);
    if ($org !== inc_org()) {
        $GLOBALS['inc_org'] = $org;
        $GLOBALS['inc_settings_cache'] = [];     // settings are per organisation
    }
    // Its links -- emails from cron included -- carry its own address when it has one (lib/orghost.php).
    if (function_exists('fabo_link_org')) {
        fabo_link_org($org);
    }
}

/** The table prefix for an organisation. */
function inc_prefix(?int $org = null): string
{
    $org = $org ?? inc_org();
    return $org > 0 ? 'inc_o' . $org . '_' : 'inc_';
}

/** $sql with its inc_ tables (and fk_inc_ constraints) renamed for the organisation in use. */
function inc_sql(string $sql): string
{
    $org = inc_org();
    if ($org <= 0) {
        return $sql;
    }
    return preg_replace('/\b(fk_)?inc_(?!o\d+_)/', '$1inc_o' . $org . '_', $sql);
}

function inc_all(string $sql, string $types = '', array $params = []): array
{
    return fabo_all(inc_sql($sql), $types, $params);
}

function inc_one(string $sql, string $types = '', array $params = []): ?array
{
    return fabo_one(inc_sql($sql), $types, $params);
}

function inc_exec(string $sql, string $types = '', array $params = []): int
{
    return fabo_exec(inc_sql($sql), $types, $params);
}

/**
 * Which organisation's Incubation Management a viewer works in, or -1 for none.
 *
 *   administrator, and our own staff (team, no parent)  -> 0
 *   a client head (client, no parent)                   -> their own id
 *   a client head's team member (team, parent = head)   -> the head's id
 *
 * Anyone else -- a client's own clients, say -- has no Incubation Management.
 */
function inc_org_of(array $viewer): int
{
    if (!empty($viewer['is_admin'])) {
        return 0;
    }
    $c = $viewer['client'] ?? null;
    if (!$c || empty($c['id'])) {
        return -1;
    }
    $kind   = (string) ($c['kind'] ?? '');
    $parent = (int) ($c['parent_id'] ?? 0);
    if ($parent === 0) {
        return $kind === 'team' ? 0 : ($kind === 'client' ? (int) $c['id'] : -1);
    }
    if ($kind !== 'team') {
        return -1;
    }
    $head = fabo_one('SELECT id, kind, parent_id FROM clients WHERE id = ?', 'i', [$parent]);
    return $head && $head['kind'] === 'client' && empty($head['parent_id']) ? (int) $head['id'] : -1;
}

/**
 * May this organisation have an Incubation Management at all? Ours always may; a
 * client organisation only while its head holds the module. Used where an
 * organisation is named from outside (the public form link, cron.php), so a
 * made-up number never creates tables.
 */
function inc_org_enabled(int $org): bool
{
    if ($org === 0) {
        return true;
    }
    if ($org < 0) {
        return false;
    }
    $head = fabo_one('SELECT c.id FROM clients c JOIN client_sheet_access a ON a.client_id = c.id AND a.sheet_key = "incubation"
                       WHERE c.id = ? AND c.kind = "client" AND c.parent_id IS NULL AND c.revoked_at IS NULL
                         AND a.permission IN ("view","edit")', 'i', [$org]);
    return (bool) $head;
}

/** Client organisations that have opened their Incubation Management (their tables exist). */
function inc_orgs_installed(): array
{
    $out = [];
    foreach (fabo_all("SELECT table_name AS t FROM information_schema.tables
                        WHERE table_schema = DATABASE() AND table_name LIKE 'inc\\_o%\\_settings'") as $r) {
        if (preg_match('/^inc_o(\d+)_settings$/', (string) $r['t'], $m)) {
            $out[] = (int) $m[1];
        }
    }
    sort($out);
    return $out;
}

/**
 * The SQL condition (on clients aliased $a) for the people who belong to the
 * organisation in use and can be put on its incubation teams: our own staff
 * for us; the head's own team members for a client.
 */
function inc_people_cond(string $a = 'c'): string
{
    $org = inc_org();
    return $a . ".kind = 'team' AND " . ($org > 0 ? $a . '.parent_id = ' . $org : $a . '.parent_id IS NULL');
}

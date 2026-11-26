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
 *
 * Event Organizers (lib/eo) works the same applications: an event's forms and
 * the people who apply are in these tables, on the one main pipeline. inc_kind()
 * 'eo' means a page is being served as Event Organizers: its own grant, links
 * and wording, and only event applications (eo_scope). What belongs to events
 * alone (tickets, orders, sessions...) is written inc_ev_* in the statements and
 * lands in tables of its own, eo_ev_* / eo_o<client id>_ev_*.
 */

/** Which product the engine is running as: 'inc' (Incubation Management) or 'eo' (Event Organizers). */
function inc_kind(): string
{
    return ($GLOBALS['inc_kind'] ?? 'inc') === 'eo' ? 'eo' : 'inc';
}

/** Switches product, before inc_start(). Everything per-organisation is per-product too. */
function inc_use_kind(string $kind): void
{
    $kind = $kind === 'eo' ? 'eo' : 'inc';
    if ($kind !== inc_kind()) {
        $GLOBALS['inc_kind'] = $kind;
        $GLOBALS['inc_settings_cache'] = [];
    }
}

/** The grant that opens a product: client_sheet_access.sheet_key and $viewer['access']. */
function inc_grant_key(?string $kind = null): string
{
    return ($kind ?? inc_kind()) === 'eo' ? 'organizers' : 'incubation';
}

/** What keys a cache of anything read from the tables in use. */
function inc_scope_key(): string
{
    return inc_kind() . ':' . inc_org();
}

/** The query-string part that keeps a public link in Event Organizers ('' for Incubation). */
function inc_kind_q(): string
{
    return inc_kind() === 'eo' ? '&m=eo' : '';
}

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

/** The table prefix of the event layer. */
function eo_prefix(?int $org = null): string
{
    $org = $org ?? inc_org();
    return $org > 0 ? 'eo_o' . $org . '_' : 'eo_';
}

/** $sql with its inc_ tables (and fk_inc_ constraints) renamed for the organisation in use. */
function inc_sql(string $sql): string
{
    $org = inc_org();
    $sql = preg_replace('/\binc_ev_/', eo_prefix($org) . 'ev_', $sql);
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
 *   any client (ours, or a client's own client)         -> their own id
 *   a client's team member (team, parent = that client) -> that client's id
 *
 * See fabo_module_org() in lib/auth.php: every module answers the same way.
 */
function inc_org_of(array $viewer): int
{
    return fabo_module_org($viewer);
}

/**
 * May this organisation have an Incubation Management at all? Ours always may; a
 * client organisation only while its head holds the module. Used where an
 * organisation is named from outside (the public form link, cron.php), so a
 * made-up number never creates tables.
 */
function inc_org_enabled(int $org, ?string $kind = null): bool
{
    if ($org === 0) {
        return true;
    }
    if ($org < 0) {
        return false;
    }
    // Its head holds it now, and so does every client above it.
    return fabo_head_module_permission($org, inc_grant_key($kind)) !== 'none';
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

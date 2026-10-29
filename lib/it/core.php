<?php
/**
 * The desk engine behind two modules: IT Management ('it') and Admin &
 * Facilities ('fa'). Both run on the same code (lib/it/app.php): a helpdesk,
 * an asset register, renewals/contracts and vendors; Facilities adds its own
 * pages (support staff, duty, housekeeping, visitors, registers, stock).
 *
 * WHOSE DATA. Like Incubation Management, every organisation has its own,
 * entirely separate tables: ours are it_* / fa_*; a client organisation
 * granted the module gets it_o<id>_* / fa_o<id>_* the first time it opens it.
 * Code always names tables it_*; it_sql() rewrites them for the desk and the
 * organisation in use, so one cannot read another's rows.
 *
 * WHO. The administrator holds both. A client head is granted either on
 * Clients (Owner or View only) and may pass it to their own team, never more
 * than they hold — see it_grant().
 */

require_once __DIR__ . '/../inc/org.php';

function it_desks(): array
{
    return ['it' => 'IT Management', 'fa' => 'Admin & Facilities'];
}

/** The desk in use: 'it' or 'fa'. */
function it_desk(): string
{
    return (string) ($GLOBALS['it_desk'] ?? 'it');
}

/** The organisation whose tables are in use: 0 for ours, else the client head's id. */
function it_org(): int
{
    return (int) ($GLOBALS['it_org'] ?? 0);
}

function it_use(string $desk, int $org): void
{
    $GLOBALS['it_desk'] = isset(it_desks()[$desk]) ? $desk : 'it';
    $GLOBALS['it_org'] = max(0, $org);
}

/** The table prefix for the desk and organisation in use. */
function it_prefix(?string $desk = null, ?int $org = null): string
{
    $desk = $desk ?? it_desk();
    $org = $org ?? it_org();
    return $desk . '_' . ($org > 0 ? 'o' . $org . '_' : '');
}

/** $sql with its it_ tables renamed for the desk and organisation in use. */
function it_sql(string $sql): string
{
    $p = it_prefix();
    return $p === 'it_' ? $sql : preg_replace('/\bit_(?!o\d+_)/', $p, $sql);
}

function it_all(string $sql, string $types = '', array $params = []): array
{
    return fabo_all(it_sql($sql), $types, $params);
}

function it_one(string $sql, string $types = '', array $params = []): ?array
{
    return fabo_one(it_sql($sql), $types, $params);
}

function it_exec(string $sql, string $types = '', array $params = []): int
{
    return fabo_exec(it_sql($sql), $types, $params);
}

/**
 * Everything that differs between the two desks: names, numbering, starting
 * lists, the kinds of renewal, and the desk's own extra pages.
 */
function it_cfg(?string $key = null)
{
    static $cfg = null;
    $cfg ??= [
        'it' => [
            'name' => 'IT Management', 'short' => 'IT', 'script' => 'it.php', 'icon' => 'monitor', 'team' => 'IT team',
            'ticket' => 'Ticket', 'tickets' => 'Tickets', 'ticket_ref' => 'IT', 'asset' => 'Device', 'assets' => 'Assets', 'asset_tag' => 'AST',
            'renewals' => 'Renewals', 'renewal' => 'renewal', 'renewed' => 'Renewed', 'support' => 'IT Support',
            'intro' => 'Helpdesk, devices, renewals and vendors in one place.',
            'request_title' => 'Raise an IT request', 'request_hint' => 'e.g. Laptop will not connect to Wi-Fi',
            'lists' => [
                'ticket_category' => ['Hardware', 'Software', 'Network & Wi-Fi', 'Email & accounts', 'Printer', 'Access request', 'New equipment', 'Other'],
                'asset_type' => ['Laptop', 'Desktop', 'Monitor', 'Printer', 'Projector', 'Network device', 'Phone', 'Server', 'Lab kit', 'Other'],
                'location' => ['Main office', 'Lab', 'Conference room', 'Store room'],
            ],
            'kinds' => ['software' => 'Software licence', 'saas' => 'SaaS subscription', 'domain' => 'Domain', 'ssl' => 'SSL certificate',
                        'hosting' => 'Hosting / cloud', 'amc' => 'AMC / maintenance', 'internet' => 'Internet line', 'other' => 'Other'],
            'extra' => [],
        ],
        'fa' => [
            'name' => 'Admin & Facilities', 'short' => 'Facilities', 'script' => 'facilities.php', 'icon' => 'building', 'team' => 'admin team',
            'ticket' => 'Request', 'tickets' => 'Requests', 'ticket_ref' => 'FAC', 'asset' => 'Facility asset', 'assets' => 'Facility assets', 'asset_tag' => 'FA',
            'renewals' => 'Contracts & compliance', 'renewal' => 'contract', 'renewed' => 'Done', 'support' => 'Admin & Facilities',
            'intro' => 'Building requests, support staff, housekeeping, stock and contracts.',
            'request_title' => 'Report a facility issue', 'request_hint' => 'e.g. AC not cooling in cabin 4',
            'lists' => [
                'ticket_category' => ['Electrical', 'Plumbing', 'AC / cooling', 'Housekeeping', 'Furniture', 'Security', 'Pantry', 'Pest control', 'Room / event setup', 'Other'],
                'asset_type' => ['Air conditioner', 'Furniture', 'Generator (DG set)', 'UPS / inverter', 'Water purifier', 'Fire extinguisher', 'CCTV camera', 'Lift', 'Pantry appliance', 'Vehicle', 'Other'],
                'location' => ['Ground floor', 'First floor', 'Reception', 'Pantry', 'Washrooms', 'Parking', 'Terrace', 'Store room'],
                'staff_role' => ['Security guard', 'Housekeeping', 'Driver', 'Pantry / office boy', 'Electrician', 'Plumber', 'Gardener', 'Supervisor'],
                'shift' => ['General (9–6)', 'Morning (6–2)', 'Evening (2–10)', 'Night (10–6)'],
                'area' => ['Reception', 'Washrooms', 'Cabins', 'Conference rooms', 'Pantry', 'Lab', 'Corridors', 'Parking', 'Main gate'],
            ],
            'kinds' => ['amc' => 'AMC / service contract', 'maintenance' => 'Preventive maintenance', 'compliance' => 'Compliance / certificate',
                        'insurance' => 'Insurance', 'rent' => 'Rent / lease', 'utility' => 'Utility bill', 'agency' => 'Staffing agency contract', 'other' => 'Other'],
            /* Visitors and Registers were taken off (2026-10-22): their pages
               (lib/it/pages/visitors.php, registers.php) and tables are kept,
               just no longer offered. */
            'extra' => ['staff' => 'Support staff', 'duty' => 'Duty & attendance', 'checklists' => 'Housekeeping', 'stock' => 'Supplies'],
        ],
    ];
    $c = $cfg[it_desk()];
    return $key === null ? $c : ($c[$key] ?? null);
}

/**
 * A page's wording in the desk's own words. The pages are written for IT
 * ("ticket", "device", "renewal"); on Admin & Facilities the same text reads
 * "request", "asset", "contract". Used only on fixed wording, never on what
 * people typed.
 */
function it_w(string $s): string
{
    if (it_desk() === 'it') {
        return $s;
    }
    static $exact = [
        'IT Management' => 'Admin & Facilities', 'Helpdesk, devices, renewals and vendors in one place.' => '',
        'Renewals' => 'Contracts & compliance', 'Next renewal' => 'Next due', 'IT settings' => 'Facilities settings', 'IT desk' => 'Admin desk',
        'Renewed' => 'Done',
    ];
    if (isset($exact[$s])) {
        return $exact[$s] !== '' ? $exact[$s] : (string) it_cfg('intro');
    }
    $s = str_replace(['an IT request', 'IT request', 'IT team', 'the IT '], ['a facility request', 'facility request', 'admin team', 'the admin '], $s);
    return preg_replace_callback('/\b(tickets?|devices?|renewals?|renewed)\b/i', static function ($m) {
        $map = ['ticket' => 'request', 'tickets' => 'requests', 'device' => 'asset', 'devices' => 'assets',
                'renewal' => 'contract', 'renewals' => 'contracts', 'renewed' => 'done'];
        $to = $map[strtolower($m[1])];
        return ctype_upper($m[1][0]) ? ucfirst($to) : $to;
    }, $s);
}

/* ================================================================ access */

/** The module key a desk is granted under (Clients → module access). */
function it_module_key(string $desk): string
{
    return $desk === 'fa' ? 'facilities' : 'it';
}

/**
 * What a viewer may do on a desk: 'none' | 'view' | 'edit'.
 *   administrator              -> edit, in ours
 *   a client head              -> as granted on Clients, in their own
 *   a client head's team member -> as the head granted, never more than the head holds now
 * Our own staff are not offered these desks: they are the administrator's.
 */
function it_grant(array $viewer, string $desk): string
{
    if (!empty($viewer['is_admin'])) {
        return 'edit';
    }
    $c = $viewer['client'] ?? null;
    if (!$c || ($viewer['state'] ?? 'ok') !== 'ok' || !empty($c['is_committee'])) {
        return 'none';
    }
    $org = inc_org_of($viewer);
    if ($org <= 0) {
        return 'none';
    }
    $key = it_module_key($desk);
    $rank = ['none' => 0, 'view' => 1, 'edit' => 2];
    $p = $viewer['access'][$key] ?? 'none';
    $p = isset($rank[$p]) ? $p : 'none';
    if ((int) $c['id'] !== $org) {
        $cap = fabo_client_access($org)[$key] ?? 'none';
        $cap = isset($rank[$cap]) ? $cap : 'none';
        if ($rank[$cap] < $rank[$p]) {
            $p = $cap;
        }
    }
    return $p;
}

/** May this organisation run the desk at all? Ours always; a client only while its head holds it. */
function it_org_enabled(string $desk, int $org): bool
{
    if ($org === 0) {
        return true;
    }
    if ($org < 0) {
        return false;
    }
    return (bool) fabo_one('SELECT c.id FROM clients c JOIN client_sheet_access a ON a.client_id = c.id AND a.sheet_key = ?
                            WHERE c.id = ? AND c.kind = "client" AND c.parent_id IS NULL AND c.revoked_at IS NULL AND a.permission IN ("view","edit")',
                           'si', [it_module_key($desk), $org]);
}

/** Client organisations that have opened a desk (their tables exist). */
function it_orgs_installed(string $desk): array
{
    $out = [];
    foreach (fabo_all("SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE ?", 's', [$desk . '\\_o%\\_settings']) as $r) {
        if (preg_match('/^' . $desk . '_o(\d+)_settings$/', (string) $r['t'], $m)) {
            $out[] = (int) $m[1];
        }
    }
    return $out;
}

/**
 * A client's way in (it.php / facilities.php at the root): a signed-in
 * session only — never a ?k= link — with the grant checked here and again on
 * every write (it_app_main).
 */
function it_portal_main(string $desk): void
{
    $self = ($desk === 'fa' ? 'facilities.php' : 'it.php') . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
    $person = fabo_signed_in();
    if (!$person) {
        if (fabo_admin_signed_in()) {
            header('Location: admin/' . $self);
            exit;
        }
        header('Location: login.php?next=' . rawurlencode($self));
        exit;
    }
    if ((int) ($person['must_change'] ?? 0) === 1) {
        header('Location: login.php?change=1&next=' . rawurlencode($self));
        exit;
    }
    it_app_main(fabo_person_viewer($person), $desk);
}

/* ============================================================ helpdesk */

/*
 * THE HELPDESK, for everyone in the organisation.
 *
 * The desk itself (tickets board, assets, renewals, vendors, settings) is for
 * whoever holds the grant above. Everybody else signed in to an organisation
 * whose desk is on still needs to ask for help: they get the helpdesk —
 * raise a request, follow their own, talk to the team, confirm it is fixed
 * or reopen it (pages "my" and "myticket").
 *
 * The people chosen as the team in the desk's Settings also work requests
 * without the full desk: their queue ("queue": assigned to them, and
 * unassigned ones to take) and the ticket page for those. Anyone a ticket is
 * assigned to may work that one ticket.
 */

/** The organisation whose helpdesk a signed-in person may use, or null. Ours (0) for our own staff. */
function it_self_org(array $viewer, string $desk): ?int
{
    if (!empty($viewer['is_admin'])) {
        return null;
    }
    $c = $viewer['client'] ?? null;
    // Committee members raise requests too: the helpdesk is the one desk their portal opens besides the committee's.
    if (!$c || empty($c['id']) || ($viewer['state'] ?? 'ok') !== 'ok') {
        return null;
    }
    $org = inc_org_of($viewer);
    if ($org < 0) {
        return null;
    }
    try {
        return it_org_enabled($desk, $org) ? $org : null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Is this person on the desk's team (Settings → team)? Only an explicit choice counts. Uses the desk in use. */
function it_is_agent(int $personId): bool
{
    if ($personId <= 0) {
        return false;
    }
    try {
        $ids = array_filter(array_map('intval', explode(',', (string) (it_one("SELECT v FROM it_settings WHERE k = 'team_ids'")['v'] ?? ''))));
        if (in_array($personId, $ids, true)) {
            return true;
        }
        // Someone with a request assigned to them works it, team or not.
        return (bool) it_one("SELECT 1 FROM it_tickets WHERE assignee_id = ? AND status NOT IN ('resolved','closed') LIMIT 1", 'i', [$personId]);
    } catch (Throwable $e) {
        return false;    // the desk has not been opened yet
    }
}

/**
 * For the sidebar: [org, agent] when the person uses the helpdesk of $desk,
 * else null. Looks at the desk's tables without disturbing the desk a page
 * may already be using.
 */
function it_helpdesk_info(array $viewer, string $desk): ?array
{
    $org = it_self_org($viewer, $desk);
    if ($org === null) {
        return null;
    }
    $was = [$GLOBALS['it_desk'] ?? null, $GLOBALS['it_org'] ?? null];
    it_use($desk, $org);
    try {
        $agent = it_is_agent((int) $viewer['client']['id']);
    } finally {
        $GLOBALS['it_desk'] = $was[0];
        $GLOBALS['it_org'] = $was[1];
    }
    return [$org, $agent];
}

/** May the viewer change things on the desk in use (Owner), or only read it? */
function it_can_edit(): bool
{
    return ($GLOBALS['it_grant'] ?? 'edit') === 'edit';
}

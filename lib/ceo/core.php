<?php
/**
 * CEO Dashboard — whose dashboard this is, and what this person may see.
 *
 * WHOSE. Each organisation has its own dashboard over its own modules. The
 * organisation must hold the 'ceo' module (granted on Clients); ours always
 * does. A client's data is never read for anybody outside that client.
 *
 * WHO. Nobody gets in by belonging to the organisation. Access is one of:
 *   - the owner: the client's head, or the administrator. Runs the access
 *     screen and sees every module the organisation holds.
 *   - a person: an active ceo_access row naming them, carrying a profile.
 *   - a position: an active row naming an HR designation; applies to whoever
 *     holds it now, read fresh, so a change of job changes the access.
 * A row naming the person beats a position. Both are read from the database
 * on every request, so a grant or revoke applies on the very next call.
 *
 * WHAT. A profile says which modules, spokes and KPIs, which sensitive
 * categories and which actions. Sensitive categories are never implied, not
 * even for the owner: the owner holds only what is switched on for them.
 */

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/../inc/org.php';
require_once __DIR__ . '/../lists.php';

/** The modules the dashboard reads. Adding a module is an entry here, a source in kpis.php and KPIs that name it. */
function ceo_modules(): array
{
    return [
        'inc'       => ['label' => 'Incubation',         'grant' => 'incubation', 'marker' => 'inc_applications', 'color' => 1, 'href' => 'incubation.php'],
        'marketing' => ['label' => 'Marketing',          'grant' => null,         'marker' => 'events',           'color' => 2, 'href' => null],
        'fin'       => ['label' => 'Finance',            'grant' => 'finance',    'marker' => 'fp_wf_steps',      'color' => 3, 'href' => 'fin.php'],
        'hr'        => ['label' => 'Human Resources',    'grant' => 'hr',         'marker' => 'hr_employees',     'color' => 4, 'href' => 'hr.php'],
        'it'        => ['label' => 'IT Management',      'grant' => 'it',         'marker' => 'it_tickets',       'color' => 7, 'href' => 'it.php'],
        'fa'        => ['label' => 'Admin & Facilities', 'grant' => 'facilities', 'marker' => 'fa_tickets',       'color' => 6, 'href' => 'facilities.php'],
        'cw'        => ['label' => 'Co-working',         'grant' => 'coworking',  'marker' => 'cw_centres',       'color' => 5, 'href' => 'coworking.php'],
        'esign'     => ['label' => 'Digital Signature',  'grant' => 'esign',      'marker' => 'ds_documents',     'color' => 8, 'href' => 'sign.php'],
    ];
}

/** What needs an explicit tick. Never implied by a role, a job title or being the owner. */
function ceo_sensitive(): array
{
    return [
        'finance'   => ['Financial figures', 'Budgets, expenses, payments, receivables and co-working dues.'],
        'salary'    => ['Salary and payroll', 'Payroll cost and anything derived from pay.'],
        'personal'  => ['Personal employee data', 'Names on leave, claim and HR-ticket items.'],
        'contracts' => ['Contracts and confidential documents', 'Titles of documents marked confidential, and contract details.'],
    ];
}

/** The actions a profile can allow. */
function ceo_actions_all(): array
{
    return [
        'view'      => 'View dashboard',
        'filter'    => 'Use filters',
        'export'    => 'Export and download',
        'report'    => 'Generate reports',
        'targets'   => 'Set targets',
        'configure' => 'Configure dashboard',
    ];
}

/* ================================================================ whose */

/** Table name for an organisation's per-organisation modules. Everything else is shared with an org column. */
function ceo_t(string $table, int $org): string
{
    if ($org > 0 && preg_match('/^(inc|it|fa|fp)_(.+)$/', $table, $m)) {
        return $m[1] . '_o' . $org . '_' . $m[2];
    }
    return $table;
}

/** Does this table exist? Asked once a request; a module never used has none, and that is not an error. */
function ceo_has_table(string $table): bool
{
    static $names = null;
    if ($names === null) {
        $names = [];
        foreach (fabo_all('SELECT TABLE_NAME n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()') as $r) {
            $names[strtolower((string) $r['n'])] = true;
        }
    }
    return isset($names[strtolower($table)]);
}

/** The organisation a viewer belongs to, or -1 (committee members and outsiders have none). */
function ceo_org_of(array $viewer): int
{
    $c = $viewer['client'] ?? null;
    if (empty($viewer['is_admin']) && $c && !empty($c['is_committee'])) {
        return -1;
    }
    return inc_org_of($viewer);
}

/** The 'ceo' grant an organisation holds. Ours always; a client's as ticked on Clients. */
function ceo_org_enabled(int $org): bool
{
    if ($org === 0) {
        return true;
    }
    if ($org < 0) {
        return false;
    }
    // Any client, a client's own client too, while every client above it holds it as well.
    return fabo_head_module_permission($org, 'ceo') !== 'none';
}

/** Organisations for the administrator's switcher: id => name. Ours first. */
function ceo_orgs(): array
{
    $out = [0 => defined('APP_NAME') ? APP_NAME : 'Our organisation'];
    return $out + fabo_module_orgs('ceo');
}

/** The organisation this request is about. Only the administrator chooses; everyone else has their own. */
function ceo_pick_org(array $viewer): int
{
    if (empty($viewer['is_admin'])) {
        return ceo_org_of($viewer);
    }
    fabo_session();
    $orgs = ceo_orgs();
    $want = isset($_REQUEST['org']) ? (int) $_REQUEST['org'] : (int) ($_SESSION['ceo_admin_org'] ?? -1);
    if (!isset($orgs[$want])) {
        $ids = array_keys($orgs);
        $want = (int) ($ids[1] ?? 0);    // the first client holding it: that is where the data is
    }
    $_SESSION['ceo_admin_org'] = $want;
    return $want;
}

function ceo_org_name(int $org): string
{
    if ($org === 0) {
        return defined('APP_NAME') ? APP_NAME : 'Our organisation';
    }
    $c = fabo_one('SELECT name, organisation FROM clients WHERE id = ?', 'i', [$org]);
    return (string) (($c['organisation'] ?? '') ?: ($c['name'] ?? 'Organisation'));
}

/* ================================================================ spokes */

/** The same place spelt the several ways the modules spell it. */
function ceo_spoke_key(string $name): string
{
    $k = strtolower(trim($name));
    $k = preg_replace('/\b(rtih|hub|spoke|centre|center|office|campus)\b/', '', $k);
    $k = preg_replace('/[^a-z]/', '', (string) $k);
    return [
        'vishakapatnam' => 'visakhapatnam', 'vishakhapatnam' => 'visakhapatnam', 'visakapatnam' => 'visakhapatnam', 'vizag' => 'visakhapatnam',
        'ananthapuram' => 'anantapur', 'anantapuram' => 'anantapur', 'ananthapur' => 'anantapur', 'anantapuramu' => 'anantapur', 'ananthapuramu' => 'anantapur',
        'rajamahendravaram' => 'rajahmundry', 'rajamundry' => 'rajahmundry', 'rajamahendri' => 'rajahmundry',
        'tirupathi' => 'tirupati', 'vijaywada' => 'vijayawada', 'bezawada' => 'vijayawada',
    ][$k] ?? $k;
}

/** The organisation's hub and spokes, hub first: key => ['key','name','kind']. From its Hub / Spoke list and its co-working centres. */
function ceo_spokes(int $org): array
{
    static $memo = [];
    if (isset($memo[$org])) {
        return $memo[$org];
    }
    $out = [];
    $names = array_values(array_filter(array_map('trim', (array) fabo_list_values('Hub / Spoke', $org))));
    foreach ($names as $i => $n) {
        $k = ceo_spoke_key($n);
        if ($k !== '' && !isset($out[$k])) {
            $out[$k] = ['key' => $k, 'name' => $n, 'kind' => $i === 0 ? 'hub' : 'spoke'];
        }
    }
    if (ceo_has_table('cw_centres')) {
        foreach (fabo_all('SELECT name, kind FROM cw_centres WHERE org_id = ? ORDER BY sort, id', 'i', [$org]) as $c) {
            $k = ceo_spoke_key((string) $c['name']);
            if ($k === '') {
                continue;
            }
            if (isset($out[$k])) {
                if ($c['kind'] === 'hub') {
                    foreach ($out as &$o) {
                        $o['kind'] = 'spoke';
                    }
                    unset($o);
                    $out[$k]['kind'] = 'hub';
                }
            } else {
                $out[$k] = ['key' => $k, 'name' => (string) $c['name'], 'kind' => (string) $c['kind']];
            }
        }
    }
    uasort($out, static fn($a, $b) => ($a['kind'] === 'hub' ? 0 : 1) <=> ($b['kind'] === 'hub' ? 0 : 1));
    return $memo[$org] = $out;
}

/* ================================================================ profiles */

function ceo_scope_clean(array $s): array
{
    $pick = static fn($v, array $allowed) => array_values(array_intersect($allowed, array_map('strval', (array) $v)));
    $sp = $s['spokes'] ?? '*';
    $kp = $s['kpis'] ?? '*';
    $actions = $pick($s['actions'] ?? ['view'], array_keys(ceo_actions_all()));
    if (!in_array('view', $actions, true)) {
        array_unshift($actions, 'view');
    }
    return [
        'modules'   => $pick($s['modules'] ?? [], array_keys(ceo_modules())),
        'spokes'    => $sp === '*' ? '*' : array_values(array_unique(array_map(static fn($x) => substr((string) preg_replace('/[^a-z]/', '', (string) $x), 0, 60), (array) $sp))),
        'kpis'      => $kp === '*' ? '*' : array_values(array_filter(array_map('strval', (array) $kp), static fn($x) => preg_match('/^[a-z0-9_]{1,40}$/', $x))),
        'sensitive' => $pick($s['sensitive'] ?? [], array_keys(ceo_sensitive())),
        'actions'   => $actions,
    ];
}

/** The profiles every organisation starts with. All can be edited; only the one named for it opens a sensitive category. */
function ceo_profile_seeds(): array
{
    $all = array_keys(ceo_modules());
    return [
        ['Executive overview', 'All modules and spokes, no sensitive figures. Can filter, export and run reports.',
         ['modules' => $all, 'spokes' => '*', 'kpis' => '*', 'sensitive' => [], 'actions' => ['view', 'filter', 'export', 'report']]],
        ['Executive with finance', 'As Executive overview, with financial figures. Salary and personal data stay closed.',
         ['modules' => $all, 'spokes' => '*', 'kpis' => '*', 'sensitive' => ['finance'], 'actions' => ['view', 'filter', 'export', 'report', 'targets']]],
        ['Department head', 'View and filter only. Pick the modules this head should see.',
         ['modules' => [], 'spokes' => '*', 'kpis' => '*', 'sensitive' => [], 'actions' => ['view', 'filter']]],
    ];
}

function ceo_profiles(int $org): array
{
    ceo_install();
    if (!fabo_one('SELECT id FROM ceo_profiles WHERE org_id = ? LIMIT 1', 'i', [$org])) {
        foreach (ceo_profile_seeds() as [$n, $d, $s]) {
            fabo_exec('INSERT INTO ceo_profiles (org_id, name, description, scope_json, is_system, created_by) VALUES (?,?,?,?,1,"System")', 'isss',
                      [$org, $n, $d, json_encode(ceo_scope_clean($s))]);
        }
    }
    $out = [];
    foreach (fabo_all('SELECT * FROM ceo_profiles WHERE org_id = ? AND deleted_at IS NULL ORDER BY is_system DESC, name', 'i', [$org]) as $p) {
        $p['scope'] = ceo_scope_clean(json_decode((string) $p['scope_json'], true) ?: []);
        unset($p['scope_json']);
        $out[(int) $p['id']] = $p;
    }
    return $out;
}

/* ================================================================ people */

/** Is this person in this organisation? Asked of every id that comes in from a form. */
function ceo_person_in_org(int $personId, int $org): ?array
{
    $p = fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [$personId]);
    if (!$p || !empty($p['is_committee']) || $p['revoked_at'] !== null) {
        return null;
    }
    $parent = (int) ($p['parent_id'] ?? 0);
    if ($org === 0) {
        return $p['kind'] === 'team' && $parent === 0 ? $p : null;
    }
    return ((int) $p['id'] === $org || ($p['kind'] === 'team' && $parent === $org)) ? $p : null;
}

/** People in the organisation with their HR record where one links to them: id => row. */
function ceo_people(int $org, string $q = '', int $limit = 400): array
{
    $hr = ceo_has_table('hr_employees');
    $sql = 'SELECT c.id, c.name, c.email, c.kind, c.login_id, '
         . ($hr ? 'e.emp_code, e.status emp_status, d.name AS position, dp.name AS department' : 'NULL emp_code, NULL emp_status, NULL position, NULL department')
         . ' FROM clients c '
         . ($hr ? 'LEFT JOIN hr_employees e ON e.person_id = c.id AND e.org_id = ' . $org . '
                   LEFT JOIN hr_designations d ON d.id = e.designation_id
                   LEFT JOIN hr_departments dp ON dp.id = e.department_id ' : '')
         . ' WHERE c.revoked_at IS NULL AND (c.is_committee = 0 OR c.is_committee IS NULL) AND ';
    $p = [];
    $types = '';
    if ($org === 0) {
        $sql .= "c.kind = 'team' AND (c.parent_id IS NULL OR c.parent_id = 0)";
    } else {
        $sql .= "(c.id = ? OR (c.kind = 'team' AND c.parent_id = ?))";
        $p = [$org, $org];
        $types = 'ii';
    }
    if ($q !== '') {
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $sql .= ' AND (c.name LIKE ? OR c.email LIKE ?' . ($hr ? ' OR e.emp_code LIKE ? OR d.name LIKE ? OR dp.name LIKE ?' : '') . ')';
        array_push($p, $like, $like);
        $types .= 'ss';
        if ($hr) {
            array_push($p, $like, $like, $like);
            $types .= 'sss';
        }
    }
    $sql .= ' ORDER BY c.name LIMIT ' . max(1, min(1000, $limit));
    $out = [];
    foreach (fabo_all($sql, $types, $p) as $r) {
        $out[(int) $r['id']] = $r;
    }
    return $out;
}

/** The HR job title a person holds now, or ''. Only while they are working here. */
function ceo_person_position(int $personId, int $org): string
{
    if ($personId <= 0 || !ceo_has_table('hr_employees')) {
        return '';
    }
    $r = fabo_one("SELECT d.name FROM hr_employees e JOIN hr_designations d ON d.id = e.designation_id
                    WHERE e.person_id = ? AND e.org_id = ? AND e.status IN ('active','probation','notice')", 'ii', [$personId, $org]);
    return (string) ($r['name'] ?? '');
}

/** Positions the organisation has, with how many people hold each and how many of those can sign in. */
function ceo_positions(int $org): array
{
    if (!ceo_has_table('hr_designations')) {
        return [];
    }
    return fabo_all("SELECT d.name, COUNT(e.id) holders, COALESCE(SUM(e.person_id IS NOT NULL), 0) with_login
                       FROM hr_designations d
                       LEFT JOIN hr_employees e ON e.designation_id = d.id AND e.status IN ('active','probation','notice')
                      WHERE d.org_id = ? AND d.active = 1 GROUP BY d.id, d.name ORDER BY d.name", 'i', [$org]);
}

/** Exactly who a position grant reaches right now. Shown before it is confirmed. */
function ceo_position_people(int $org, string $position): array
{
    if (!ceo_has_table('hr_employees')) {
        return [];
    }
    $rows = fabo_all("SELECT e.full_name, e.emp_code, e.person_id, dp.name AS department
                        FROM hr_employees e
                        JOIN hr_designations d ON d.id = e.designation_id AND d.name = ?
                        LEFT JOIN hr_departments dp ON dp.id = e.department_id
                       WHERE e.org_id = ? AND e.status IN ('active','probation','notice') ORDER BY e.full_name", 'si', [$position, $org]);
    foreach ($rows as &$r) {
        $r['can_sign_in'] = !empty($r['person_id']) && ceo_person_in_org((int) $r['person_id'], $org) !== null;
    }
    unset($r);
    return $rows;
}

/** A fingerprint of who a position reaches, so a confirmation applies to the list that was shown and no other. */
function ceo_position_hash(array $people): string
{
    $ids = array_map(static fn($p) => (int) ($p['person_id'] ?? 0), array_filter($people, static fn($p) => $p['can_sign_in']));
    sort($ids);
    return sha1(implode(',', $ids));
}

/* ================================================================ access */

function ceo_is_owner(array $viewer, int $org): bool
{
    if (!empty($viewer['is_admin'])) {
        return true;
    }
    $c = $viewer['client'] ?? null;
    return $c && $org > 0 && (int) $c['id'] === $org && ($c['kind'] ?? '') === 'client';     // a client's own client heads its own
}

/** The person id access rows are kept under. The administrator's is 0. */
function ceo_viewer_id(array $viewer): int
{
    return !empty($viewer['is_admin']) ? 0 : (int) ($viewer['client']['id'] ?? 0);
}

function ceo_actor(array $viewer): string
{
    return !empty($viewer['is_admin']) ? 'Administrator' : (string) ($viewer['client']['name'] ?? 'Unknown');
}

/**
 * What this viewer may do on this organisation's dashboard, or null for
 * nothing. Read from the database on every call. This is the only place the
 * answer is made; pages, API, exports and scheduled reports all go through it.
 */
function ceo_access(array $viewer, int $org): ?array
{
    if ($org < 0 || !ceo_org_enabled($org) || (($viewer['state'] ?? 'ok') !== 'ok')) {
        return null;
    }
    ceo_install();
    $owner = ceo_is_owner($viewer, $org);
    $pid = ceo_viewer_id($viewer);
    $profiles = ceo_profiles($org);

    $row = fabo_one('SELECT * FROM ceo_access WHERE org_id = ? AND subject = "person" AND person_id = ? AND status = "active"', 'ii', [$org, $pid]);
    $via = 'person';
    if ((!$row || !isset($profiles[(int) $row['profile_id']])) && !$owner) {
        $row = null;
        $position = ceo_person_position($pid, $org);
        if ($position !== '') {
            $row = fabo_one('SELECT * FROM ceo_access WHERE org_id = ? AND subject = "position" AND position = ? AND status = "active"', 'is', [$org, $position]);
            $via = 'position';
        }
    }
    $profile = $row ? ($profiles[(int) $row['profile_id']] ?? null) : null;

    if ($owner) {
        // The owner runs the dashboard and sees every module, but a sensitive category only when switched on for them.
        return [
            'owner' => true, 'via' => 'owner', 'profile' => $profile['name'] ?? 'Owner', 'profile_id' => (int) ($profile['id'] ?? 0),
            'modules' => array_keys(ceo_modules()), 'spokes' => '*', 'kpis' => '*',
            'sensitive' => $profile['scope']['sensitive'] ?? [],
            'actions' => array_keys(ceo_actions_all()),
        ];
    }
    if (!$profile) {
        return null;
    }
    return ['owner' => false, 'via' => $via, 'profile' => $profile['name'], 'profile_id' => (int) $profile['id']] + $profile['scope'];
}

function ceo_may(?array $a, string $action): bool
{
    return $a && in_array($action, $a['actions'], true);
}

function ceo_allowed_spoke(array $a, string $key): bool
{
    return $key === '' ? $a['spokes'] === '*' : ($a['spokes'] === '*' || in_array($key, $a['spokes'], true));
}

/* ================================================================ module state */

/** Is the module usable here: 'ok', 'not_granted' (the organisation does not hold it) or 'not_set_up' (no tables yet). */
function ceo_module_state(int $org, string $m): string
{
    static $memo = [];
    if (isset($memo[$org][$m])) {
        return $memo[$org][$m];
    }
    $def = ceo_modules()[$m] ?? null;
    if (!$def) {
        return 'not_granted';
    }
    $state = 'ok';
    if ($org > 0) {
        $access = fabo_client_access($org);
        if ($def['grant'] !== null) {
            if (!in_array($access[$def['grant']] ?? 'none', ['view', 'edit'], true)) {
                $state = 'not_granted';
            }
        } else {
            $held = array_intersect_key($access, array_flip(fabo_marketing_sheet_keys()));
            if (!array_filter($held, static fn($p) => in_array($p, ['view', 'edit'], true))) {
                $state = 'not_granted';
            }
        }
    }
    if ($state === 'ok' && !ceo_has_table(ceo_t($def['marker'], $org))) {
        $state = 'not_set_up';
    }
    return $memo[$org][$m] = $state;
}

/**
 * May this viewer open the module itself? It decides whether a link is drawn
 * and whether a record's own wording is shown; the module still judges every
 * click for itself, so a link is never the protection.
 */
function ceo_can_open(array $viewer, string $m, int $org): bool
{
    static $memo = [];
    $ck = ceo_viewer_id($viewer) . ':' . $org . ':' . $m . ':' . (empty($viewer['is_admin']) ? 0 : 1);
    if (isset($memo[$ck])) {
        return $memo[$ck];
    }
    if (!empty($viewer['is_admin'])) {
        return $memo[$ck] = true;
    }
    try {
        switch ($m) {
            case 'marketing':
                $ok = (bool) array_filter(fabo_marketing_sheet_keys(), static fn($k) => fabo_permission($viewer, $k) !== 'none');
                break;
            case 'hr':
                require_once __DIR__ . '/../hr/core.php';
                $ok = fabo_hr_grant($viewer) !== 'none' || hr_person_perms(ceo_viewer_id($viewer), $org);
                break;
            case 'inc':
                require_once __DIR__ . '/../inc/nav.php';
                $ok = inc_grant($viewer) !== 'none';
                break;
            case 'it':
            case 'fa':
                require_once __DIR__ . '/../it/core.php';
                $ok = it_grant($viewer, $m) !== 'none';
                break;
            case 'cw':
                require_once __DIR__ . '/../cw/core.php';
                $ok = cw_grant($viewer) !== 'none';
                break;
            case 'esign':
                // Everyone signs what is sent to them; reading other people's documents is the grant.
                $ok = fabo_module_permission($viewer, 'esign') !== 'none';
                break;
            case 'fin':
                require_once __DIR__ . '/../fin/core.php';
                $was = [$GLOBALS['fp_org'] ?? null, $GLOBALS['fp_viewer'] ?? null, $GLOBALS['fp_grant'] ?? null, $GLOBALS['fp_settings_cache'] ?? null];
                fp_use($org);
                $GLOBALS['fp_viewer'] = $viewer;
                $GLOBALS['fp_grant'] = fp_org_grant($org);
                try {
                    $ok = $GLOBALS['fp_grant'] !== 'none' && fp_has_access();
                } finally {
                    [$GLOBALS['fp_org'], $GLOBALS['fp_viewer'], $GLOBALS['fp_grant'], $GLOBALS['fp_settings_cache']] = $was;
                }
                break;
            default:
                $ok = false;
        }
    } catch (Throwable $e) {
        error_log('FABO ceo can_open ' . $m . ': ' . $e->getMessage());
        $ok = false;
    }
    return $memo[$ck] = (bool) $ok;
}

/** A link into a module, right for whichever portal the viewer is in. */
function ceo_link(array $viewer, int $org, string $m, array $q = []): string
{
    $admin = !empty($viewer['is_admin']);
    $page = $m === 'marketing' ? ($admin ? 'sheet.php' : 'c.php') : (string) ceo_modules()[$m]['href'];
    if ($admin && $org > 0) {
        $q['org'] = $org;
    }
    return $page . ($q ? '?' . http_build_query($q) : '');
}

/* ================================================================ audit */

function ceo_audit(int $org, array $viewer, string $action, string $subject, array $detail = []): void
{
    require_once __DIR__ . '/../throttle.php';
    fabo_exec('INSERT INTO ceo_audit (org_id, actor_id, actor, action, subject, detail_json, ip) VALUES (?,?,?,?,?,?,?)', 'iisssss',
              [$org, ceo_viewer_id($viewer), ceo_actor($viewer), $action, mb_substr($subject, 0, 190),
               $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null, PHP_SAPI === 'cli' ? null : fabo_client_ip()]);
}

/* ================================================================ settings */

function ceo_setting(int $org, string $k, string $default = ''): string
{
    $r = fabo_one('SELECT v FROM ceo_settings WHERE org_id = ? AND k = ?', 'is', [$org, $k]);
    return $r ? (string) $r['v'] : $default;
}

function ceo_setting_put(int $org, string $k, string $v): void
{
    fabo_exec('INSERT INTO ceo_settings (org_id, k, v) VALUES (?,?,?) ON DUPLICATE KEY UPDATE v = VALUES(v)', 'iss', [$org, $k, $v]);
}

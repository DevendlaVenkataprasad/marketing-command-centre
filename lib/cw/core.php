<?php
/**
 * Co-working Space — the data, the rules and the numbers.
 *
 * An organisation runs one or more centres (RTIH: the Amaravati hub and its
 * five spokes, taken from its Hub / Spoke list). Every centre keeps the same
 * template as the "Coworking Master" workbook:
 *
 *   Space Register    one row per desk and cabin: who manages it, who uses it,
 *                     the agreement, any notice, vendor details.
 *   Startup Register  one row per startup: contacts, billable, dues, deposit.
 *   Waiting List      applicants and their pipeline.
 *   Monthly Log       a month-end snapshot of the headline numbers.
 *
 * Everything else -- dashboard, seat map, renewals, action items, the
 * per-startup tracker -- is worked out here from those four, exactly as the
 * workbook's formulas did, so nothing is typed twice.
 *
 * Tables cw_* are shared by every organisation; each row belongs to a centre
 * and each centre to one organisation (org_id: a client's id, 0 for ours).
 * The module is granted like Finance or IT: 'coworking' in fabo_modules().
 */

require_once __DIR__ . '/../db.php';

const CW_SCHEMA_VERSION = 4;

/* ================================================================ schema */

function cw_install(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    require_once __DIR__ . '/../google.php';
    if ((int) fabo_setting('cw_schema', '0') >= CW_SCHEMA_VERSION) {
        return;
    }
    $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $db = fabo_db();
    foreach ([
        "CREATE TABLE IF NOT EXISTS cw_centres (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, org_id INT UNSIGNED NOT NULL DEFAULT 0,
           name VARCHAR(120) NOT NULL, kind ENUM('hub','spoke') NOT NULL DEFAULT 'spoke',
           manager VARCHAR(60) NOT NULL DEFAULT 'RTIH', desk_rate DECIMAL(12,2) NOT NULL DEFAULT 4000,
           cabin_rate DECIMAL(12,2) NOT NULL DEFAULT 12000, term_months TINYINT UNSIGNED NOT NULL DEFAULT 11,
           address VARCHAR(255) NULL, sort SMALLINT NOT NULL DEFAULT 0,
           created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY k_org (org_id, sort)) $t",
        "CREATE TABLE IF NOT EXISTS cw_spaces (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, centre_id INT UNSIGNED NOT NULL,
           space_id VARCHAR(80) NOT NULL, type ENUM('Desk','Cabin') NOT NULL DEFAULT 'Desk', zone VARCHAR(60) NOT NULL DEFAULT '',
           managed_by VARCHAR(60) NOT NULL DEFAULT '', occupied_by ENUM('Startup','Vendor','Vacant','Not checked') NOT NULL DEFAULT 'Not checked',
           occupant VARCHAR(190) NOT NULL DEFAULT '', agreement VARCHAR(10) NOT NULL DEFAULT '', agreement_date DATE NULL,
           action VARCHAR(40) NOT NULL DEFAULT '', vendor_what VARCHAR(190) NULL, vendor_contact VARCHAR(160) NULL,
           vendor_phone VARCHAR(60) NULL, people SMALLINT NULL, using_since DATE NULL, checked_by VARCHAR(120) NULL,
           checked_on DATE NULL, notes TEXT NULL, sort INT NOT NULL DEFAULT 0,
           updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
           UNIQUE KEY u_space (centre_id, space_id), KEY k_centre (centre_id, sort)) $t",
        "CREATE TABLE IF NOT EXISTS cw_startups (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, centre_id INT UNSIGNED NOT NULL,
           name VARCHAR(190) NOT NULL, type VARCHAR(40) NOT NULL DEFAULT 'Startup', email VARCHAR(190) NULL, phone VARCHAR(80) NULL,
           billable VARCHAR(20) NOT NULL DEFAULT 'Yes', due_amount DECIMAL(12,2) NULL, dues_as_on DATE NULL,
           deposit DECIMAL(12,2) NULL, deposit_paid_on DATE NULL, deposit_not_paid TINYINT(1) NOT NULL DEFAULT 0,
           notes TEXT NULL, sort INT NOT NULL DEFAULT 0,
           updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
           UNIQUE KEY u_name (centre_id, name), KEY k_centre (centre_id, sort)) $t",
        "CREATE TABLE IF NOT EXISTS cw_waiting (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, centre_id INT UNSIGNED NOT NULL,
           applicant VARCHAR(190) NOT NULL, contact VARCHAR(160) NULL, email VARCHAR(190) NULL, phone VARCHAR(80) NULL,
           sector VARCHAR(120) NULL, desks_needed SMALLINT NULL, cabin_needed VARCHAR(10) NOT NULL DEFAULT '',
           preferred_zone VARCHAR(60) NULL, source VARCHAR(160) NULL, applied_on DATE NULL,
           status VARCHAR(40) NOT NULL DEFAULT 'New', expected_start DATE NULL, next_step VARCHAR(255) NULL,
           owner VARCHAR(120) NULL, notes TEXT NULL,
           updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
           KEY k_centre (centre_id, status)) $t",
        "CREATE TABLE IF NOT EXISTS cw_monthly (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, centre_id INT UNSIGNED NOT NULL, month DATE NOT NULL,
           desks_occupied INT NULL, occupancy DECIMAL(6,4) NULL, cabins_occupied INT NULL, vendors INT NULL,
           truly_vacant INT NULL, rent DECIMAL(14,2) NULL, billing_confirm DECIMAL(14,2) NULL, dues DECIMAL(14,2) NULL,
           action_items INT NULL, waiting_open INT NULL, renewals_due INT NULL, notes VARCHAR(500) NULL,
           created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY k_centre (centre_id, month)) $t",
        /* 2: the stage emails each organisation writes, and what happened to each application. */
        "CREATE TABLE IF NOT EXISTS cw_mail_tpl (
           org_id INT UNSIGNED NOT NULL, stage VARCHAR(60) NOT NULL, enabled TINYINT(1) NOT NULL DEFAULT 1,
           subject VARCHAR(255) NOT NULL, body TEXT NOT NULL,
           updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (org_id, stage)) $t",
        "CREATE TABLE IF NOT EXISTS cw_events (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, wait_id INT UNSIGNED NOT NULL, kind VARCHAR(20) NOT NULL,
           detail VARCHAR(500) NOT NULL, actor VARCHAR(190) NOT NULL DEFAULT '',
           created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY k_wait (wait_id, id)) $t",
        /* 3: who runs each centre (a team member of the organisation, managing or reading it),
           and what was changed where, by whom, for the owner to follow. */
        "CREATE TABLE IF NOT EXISTS cw_centre_people (
           centre_id INT UNSIGNED NOT NULL, person_id INT UNSIGNED NOT NULL, role ENUM('edit','view') NOT NULL DEFAULT 'edit',
           added_by VARCHAR(190) NOT NULL DEFAULT '', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
           PRIMARY KEY (centre_id, person_id), KEY k_person (person_id)) $t",
        "CREATE TABLE IF NOT EXISTS cw_activity (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, org_id INT UNSIGNED NOT NULL, centre_id INT UNSIGNED NOT NULL DEFAULT 0,
           actor VARCHAR(190) NOT NULL DEFAULT '', detail VARCHAR(500) NOT NULL,
           created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY k_org (org_id, id), KEY k_centre (centre_id, id)) $t",
    ] as $sql) {
        $db->query($sql);
    }
    /* 2: an application keeps its reference, where it came from, its website and
       the one-time ticket that stops a double click filing it twice. */
    foreach (['ref' => "VARCHAR(24) NULL", 'channel' => "VARCHAR(20) NOT NULL DEFAULT 'Manual'",
              'website' => "VARCHAR(255) NULL", 'ticket' => "CHAR(32) NULL"] as $col => $def) {
        if (!fabo_one("SHOW COLUMNS FROM cw_waiting LIKE '" . $col . "'")) {
            $db->query("ALTER TABLE cw_waiting ADD COLUMN `" . $col . "` " . $def);
        }
    }
    if (!fabo_one("SHOW INDEX FROM cw_waiting WHERE Key_name = 'u_ticket'")) {
        $db->query("ALTER TABLE cw_waiting ADD UNIQUE KEY u_ticket (ticket)");
    }
    /* 4: seats held by the centre's own staff, and open space let by the square foot at the centre's rate. */
    if (!fabo_one("SHOW COLUMNS FROM cw_spaces LIKE 'area'")) {
        $db->query("ALTER TABLE cw_spaces MODIFY type ENUM('Desk','Cabin','Open space') NOT NULL DEFAULT 'Desk',
                     MODIFY occupied_by ENUM('Startup','Staff','Vendor','Vacant','Not checked') NOT NULL DEFAULT 'Not checked',
                     ADD COLUMN area DECIMAL(10,2) NULL AFTER people");
    }
    if (!fabo_one("SHOW COLUMNS FROM cw_centres LIKE 'open_rate'")) {
        $db->query("ALTER TABLE cw_centres ADD COLUMN open_rate DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER cabin_rate");
    }
    fabo_setting_put('cw_schema', (string) CW_SCHEMA_VERSION);
}

/* ================================================================ access */

/** 'none' | 'view' | 'edit': the administrator always; anyone else by the Co-working module grant. */
function cw_grant(array $viewer): string
{
    if (!empty($viewer['is_admin'])) {
        return 'edit';
    }
    if (!fabo_signed_in()) {
        return 'none';
    }
    return fabo_module_permission($viewer, 'coworking');
}

/** Organisations holding the module, for the administrator's switcher: id => name. Ours (0) first. */
function cw_orgs(): array
{
    $out = [0 => 'Our own'];
    try {
        $out += fabo_module_orgs('coworking');      // clients at any level, a client's own clients too
    } catch (Throwable $e) {
        // client_sheet_access not there yet: ours only
    }
    return $out;
}

/** Whose co-working this request is about. A client: their own organisation. The administrator: the one picked. */
function cw_org(array $viewer): int
{
    if (empty($viewer['is_admin'])) {
        return fabo_module_org_of($viewer);
    }
    $orgs = cw_orgs();
    $want = isset($_REQUEST['org']) ? (int) $_REQUEST['org'] : null;
    if ($want !== null && isset($orgs[$want])) {
        return $want;
    }
    // The first organisation that holds it, when there is one: that is whose data there is.
    $ids = array_keys($orgs);
    return (int) ($ids[1] ?? 0);
}

/* =============================================================== centres */

/**
 * An organisation's centres, hub first. Made on first use from its Hub /
 * Spoke list -- the first entry is the hub, the rest its spokes -- or one
 * "Main centre" when it keeps no such list.
 */
function cw_centres(int $org): array
{
    cw_install();
    $rows = fabo_all('SELECT * FROM cw_centres WHERE org_id = ? ORDER BY sort, id', 'i', [$org]);
    if ($rows) {
        return $rows;
    }
    require_once __DIR__ . '/../lists.php';
    $names = array_values(array_filter(array_map('trim', fabo_list_values('Hub / Spoke', $org))));
    if (!$names) {
        $names = ['Main centre'];
    }
    foreach ($names as $i => $n) {
        fabo_exec('INSERT INTO cw_centres (org_id, name, kind, sort) VALUES (?,?,?,?)', 'issi',
                  [$org, mb_substr($n, 0, 120), $i === 0 ? 'hub' : 'spoke', $i + 1]);
    }
    return fabo_all('SELECT * FROM cw_centres WHERE org_id = ? ORDER BY sort, id', 'i', [$org]);
}

function cw_centre(int $org, int $id): ?array
{
    return fabo_one('SELECT * FROM cw_centres WHERE id = ? AND org_id = ?', 'ii', [$id, $org]);
}

/** How much each centre holds: id => [spaces, startups, waiting, log]. */
function cw_centre_sizes(int $org): array
{
    $out = [];
    foreach (fabo_all("SELECT c.id,
                         (SELECT COUNT(*) FROM cw_spaces x WHERE x.centre_id = c.id) spaces,
                         (SELECT COUNT(*) FROM cw_startups x WHERE x.centre_id = c.id) startups,
                         (SELECT COUNT(*) FROM cw_waiting x WHERE x.centre_id = c.id) waiting,
                         (SELECT COUNT(*) FROM cw_monthly x WHERE x.centre_id = c.id) log
                       FROM cw_centres c WHERE c.org_id = ?", 'i', [$org]) as $r) {
        $out[(int) $r['id']] = array_map('intval', $r);
    }
    return $out;
}

/** A new centre for the organisation, last in the order. Returns its id. */
function cw_centre_add(int $org, string $name, string $kind): int
{
    $name = mb_substr(trim($name), 0, 120);
    if ($name === '') {
        throw new InvalidArgumentException('Give the centre a name.');
    }
    if (fabo_one('SELECT id FROM cw_centres WHERE org_id = ? AND name = ?', 'is', [$org, $name])) {
        throw new InvalidArgumentException('There is already a centre called ' . $name . '.');
    }
    $sort = (int) (fabo_one('SELECT COALESCE(MAX(sort), 0) + 1 n FROM cw_centres WHERE org_id = ?', 'i', [$org])['n'] ?? 1);
    return fabo_exec('INSERT INTO cw_centres (org_id, name, kind, sort) VALUES (?,?,?,?)', 'issi',
                     [$org, $name, $kind === 'hub' ? 'hub' : 'spoke', $sort]);
}

/**
 * Removes a centre and everything it holds. The last centre stays (the
 * module always has one), and a centre with data goes only when $withData
 * says the person confirmed losing it.
 */
function cw_centre_delete(int $org, int $id, bool $withData): string
{
    $c = cw_centre($org, $id);
    if (!$c) {
        throw new InvalidArgumentException('That centre is not here any more.');
    }
    if ((int) fabo_one('SELECT COUNT(*) n FROM cw_centres WHERE org_id = ?', 'i', [$org])['n'] <= 1) {
        throw new InvalidArgumentException('Keep at least one centre.');
    }
    $size = cw_centre_sizes($org)[$id] ?? ['spaces' => 0, 'startups' => 0, 'waiting' => 0, 'log' => 0];
    if ($size['spaces'] + $size['startups'] + $size['waiting'] + $size['log'] > 0 && !$withData) {
        throw new InvalidArgumentException($c['name'] . ' still holds ' . $size['spaces'] . ' spaces and ' . $size['startups']
            . ' startups. Confirm that they go with it to remove it.');
    }
    foreach (['cw_spaces', 'cw_startups', 'cw_waiting', 'cw_monthly', 'cw_centre_people'] as $t) {
        fabo_exec("DELETE FROM $t WHERE centre_id = ?", 'i', [$id]);
    }
    fabo_exec('DELETE FROM cw_centres WHERE id = ? AND org_id = ?', 'ii', [$id, $org]);
    return (string) $c['name'];
}

/** Moves a centre one place up (-1) or down (+1) in the order the switcher and overview show. */
function cw_centre_move(int $org, int $id, int $dir): void
{
    $ids = array_map('intval', array_column(fabo_all('SELECT id FROM cw_centres WHERE org_id = ? ORDER BY sort, id', 'i', [$org]), 'id'));
    $at = array_search($id, $ids, true);
    $to = $at === false ? false : $at + ($dir < 0 ? -1 : 1);
    if ($at === false || $to < 0 || $to >= count($ids)) {
        return;
    }
    [$ids[$at], $ids[$to]] = [$ids[$to], $ids[$at]];
    foreach ($ids as $i => $cid) {
        fabo_exec('UPDATE cw_centres SET sort = ? WHERE id = ?', 'ii', [$i + 1, $cid]);
    }
}

/* ============================================================== the rules */

/** The choices the workbook's dropdowns offered. */
function cw_choices(): array
{
    return [
        'occupied_by' => ['Startup', 'Staff', 'Vendor', 'Vacant', 'Not checked'],
        'type'        => ['Desk', 'Cabin', 'Open space'],
        'agreement'   => ['', 'Yes', 'No'],
        'action'      => ['', 'Give Notice', 'Notice Given', 'To be Vacated'],
        'billable'    => ['Yes', 'No', 'Waived', 'To confirm'],
        'startup_type'=> ['Startup', 'MSME', 'Corporate', 'Other'],
        'wait_status' => ['New', 'In review', 'Approved – waiting for space', 'Offer sent', 'Moved in', 'Dropped'],
    ];
}

function cw_open_wait_statuses(): array
{
    return ['New', 'In review', 'Approved – waiting for space', 'Offer sent'];
}

function cw_today(): string
{
    return date('Y-m-d');
}

/** When an agreement signed on $date runs out: the day before the term in months is up (the workbook's EDATE(date, term) - 1). */
function cw_agreement_end(?string $date, int $term): ?string
{
    if (!$date) {
        return null;
    }
    $d = new DateTimeImmutable($date);
    $end = $d->modify('+' . $term . ' months');
    // EDATE keeps the day, or the month's last day when it has fewer.
    if ((int) $end->format('d') !== (int) $d->format('d')) {
        $end = $end->modify('last day of previous month');
    }
    return $end->modify('-1 day')->format('Y-m-d');
}

function cw_days_between(string $from, string $to): int
{
    return (int) round((strtotime($to) - strtotime($from)) / 86400);
}

/** The key a startup is matched on everywhere: the name, trimmed, any case. */
function cw_key(?string $name): string
{
    return mb_strtolower(preg_replace('/\s+/', ' ', trim((string) $name)));
}

/**
 * Everything one centre's pages show, worked out from its four registers.
 * The single source for the dashboard, seat map, renewals, action items and
 * tracker, so they can never disagree.
 */
function cw_model(array $centre): array
{
    $cid   = (int) $centre['id'];
    $today = cw_today();
    $term  = max(1, (int) $centre['term_months']);
    $own   = cw_key($centre['manager'] ?: 'RTIH');
    $deskR = (float) $centre['desk_rate'];
    $cabR  = (float) $centre['cabin_rate'];
    $openR = (float) ($centre['open_rate'] ?? 0);

    $spaces   = fabo_all('SELECT * FROM cw_spaces WHERE centre_id = ? ORDER BY sort, id', 'i', [$cid]);
    $startups = fabo_all('SELECT * FROM cw_startups WHERE centre_id = ? ORDER BY sort, name', 'i', [$cid]);
    $waiting  = fabo_all('SELECT * FROM cw_waiting WHERE centre_id = ? ORDER BY applied_on IS NULL, applied_on, id', 'i', [$cid]);
    $log      = fabo_all('SELECT * FROM cw_monthly WHERE centre_id = ? ORDER BY month, id', 'i', [$cid]);

    /* ---- spaces: who manages them, renewal status */
    $byStartup = [];
    foreach ($spaces as &$s) {
        $s['_own']  = cw_key($s['managed_by'] ?: $centre['manager']) === $own;
        $s['_end']  = cw_agreement_end($s['agreement_date'], $term);
        $s['_days'] = $s['_end'] ? cw_days_between($today, $s['_end']) : null;
        $s['_renew'] = $s['_end'] === null || !$s['_own'] ? ''
                     : ($s['_days'] < 0 ? 'Renewal Due' : ($s['_days'] <= 30 ? 'Renewal Upcoming' : ''));
        if ($s['occupied_by'] === 'Startup' && trim($s['occupant']) !== '') {
            $byStartup[cw_key($s['occupant'])][] = &$s;
        }
    }
    unset($s);

    /* ---- startups: their space, rent and status (the Startup Tracker) */
    $known = [];
    foreach ($startups as &$st) {
        $k = cw_key($st['name']);
        $known[$k] = true;
        $mine = $byStartup[$k] ?? [];
        $desks = $cabins = $partner = 0;
        $open = 0.0;
        $firstAgr = null;
        $signed = false;
        foreach ($mine as $s) {
            if (!$s['_own']) {
                $partner++;
                continue;
            }
            if ($s['type'] === 'Open space') {
                $open += (float) $s['area'];
            } else {
                $s['type'] === 'Cabin' ? $cabins++ : $desks++;
            }
            if ($s['agreement'] === 'Yes') {
                $signed = true;
            }
            if ($s['agreement_date'] && ($firstAgr === null || $s['agreement_date'] < $firstAgr)) {
                $firstAgr = $s['agreement_date'];
            }
        }
        $full = $desks * $deskR + $cabins * $cabR + $open * $openR;
        $ownN = $desks + $cabins + ($open > 0 ? 1 : 0);
        $bill = $st['billable'];
        $dues = (float) ($st['due_amount'] ?? 0);
        if ($ownN === 0 && $partner === 0) {
            $status = $dues > 0 ? 'Past tenant - dues open' : 'Past tenant';
        } elseif ($ownN === 0) {
            $managers = array_unique(array_map(static fn($s) => (string) $s['managed_by'], $mine));
            $status = ($managers[0] ?? 'Partner') . '-managed';
        } elseif ($bill === 'To confirm' || $bill === '') {
            $status = 'Billing to confirm';
        } elseif ($bill === 'No' || $bill === 'Waived') {
            $status = 'Not billable';
        } else {
            $status = 'OK';
        }
        $st['_desks']   = $desks;
        $st['_cabins']  = $cabins;
        $st['_open']    = $open;
        $st['_own_n']   = $ownN;
        $st['_partner'] = $partner;
        $st['_full']    = $full;
        $st['_rent']    = $bill === 'Yes' ? $full : 0.0;
        $st['_status']  = $status;
        $st['_using']   = $ownN + $partner > 0;
        $st['_signed']  = $signed;
        $st['_first']   = $firstAgr;
        $st['_months']  = $firstAgr ? max(0, (int) floor(cw_days_between($firstAgr, $today) / 30.44)) : null;
    }
    unset($st);

    /* ---- desks and cabins */
    $desks  = array_values(array_filter($spaces, static fn($s) => $s['type'] === 'Desk'));
    $cabins = array_values(array_filter($spaces, static fn($s) => $s['type'] === 'Cabin'));
    $opens  = array_values(array_filter($spaces, static fn($s) => $s['type'] === 'Open space'));
    $taken  = static fn($s) => in_array($s['occupied_by'], ['Startup', 'Staff'], true) || ($s['occupied_by'] === 'Vendor' && !$s['_own']);
    $count  = static fn(array $list, callable $f) => count(array_filter($list, $f));

    $zones = [];
    foreach ($desks as $s) {
        $z = $s['zone'] !== '' ? $s['zone'] : 'Desks';
        $zones[$z] ??= ['total' => 0, 'occupied' => 0, 'startups' => 0, 'staff' => 0, 'vendors' => 0, 'vacant' => 0, 'notchecked' => 0];
        $zones[$z]['total']++;
        $zones[$z]['occupied']   += $taken($s) ? 1 : 0;
        $zones[$z]['startups']   += $s['occupied_by'] === 'Startup' ? 1 : 0;
        $zones[$z]['staff']      += $s['occupied_by'] === 'Staff' ? 1 : 0;
        $zones[$z]['vendors']    += $s['occupied_by'] === 'Vendor' ? 1 : 0;
        $zones[$z]['vacant']     += $s['occupied_by'] === 'Vacant' ? 1 : 0;
        $zones[$z]['notchecked'] += $s['occupied_by'] === 'Not checked' ? 1 : 0;
    }
    $deskTotal = count($desks);
    $deskTaken = $count($desks, $taken);
    $ownDesks  = array_values(array_filter($desks, static fn($s) => $s['_own']));
    $ptrDesks  = array_values(array_filter($desks, static fn($s) => !$s['_own']));
    $partnerNames = array_values(array_unique(array_filter(array_map(static fn($s) => (string) $s['managed_by'], $ptrDesks))));

    $area = [];
    foreach ($zones as $z => $v) {
        $area[] = ['label' => $z, 'total' => $v['total'], 'occupied' => $v['occupied']];
    }
    $area[] = ['label' => 'All desks', 'total' => $deskTotal, 'occupied' => $deskTaken, 'sum' => true];
    $area[] = ['label' => 'Cabins', 'total' => count($cabins),
               'occupied' => $count($cabins, static fn($s) => in_array($s['occupied_by'], ['Startup', 'Staff', 'Vendor'], true))];
    if ($opens) {
        $area[] = ['label' => 'Open space (sq ft)', 'total' => (int) round(array_sum(array_map(static fn($s) => (float) $s['area'], $opens))),
                   'occupied' => (int) round(array_sum(array_map(static fn($s) => $taken($s) ? (float) $s['area'] : 0, $opens)))];
    }
    if ($ptrDesks) {
        $area[] = ['label' => 'Desks: ' . ($centre['manager'] ?: 'own') . '-managed', 'total' => count($ownDesks), 'occupied' => $count($ownDesks, $taken)];
        $area[] = ['label' => 'Desks: ' . implode(', ', $partnerNames ?: ['partner']), 'total' => count($ptrDesks), 'occupied' => $count($ptrDesks, $taken)];
    }

    /* ---- agreements (Renewals): one per startup and signing date, on the centre's own spaces */
    $agreements = [];
    foreach ($spaces as $s) {
        if (!$s['_own'] || !$s['agreement_date'] || $s['occupied_by'] !== 'Startup') {
            continue;
        }
        $k = cw_key($s['occupant']) . '|' . $s['agreement_date'];
        if (!isset($agreements[$k])) {
            $days = (int) $s['_days'];
            $agreements[$k] = ['startup' => $s['occupant'], 'spaces' => 0, 'date' => $s['agreement_date'], 'end' => $s['_end'],
                               'days' => $days, 'remarks' => [], 'space_ids' => [],
                               'status' => $days < 0 ? 'Expired' : ($days <= 30 ? 'Due in 30 days' : ($days <= 60 ? 'Due in 31–60 days' : 'Active'))];
        }
        $agreements[$k]['spaces']++;
        $agreements[$k]['space_ids'][] = $s['space_id'];
        if ($s['action'] !== '') {
            $agreements[$k]['remarks'][] = $s['action'];
        }
    }
    $agreements = array_values($agreements);
    usort($agreements, static fn($a, $b) => [$a['days'], $a['startup']] <=> [$b['days'], $b['startup']]);
    $renewals = ['Expired' => 0, 'Due in 30 days' => 0, 'Due in 31–60 days' => 0, 'Active' => 0];
    foreach ($agreements as $a) {
        $renewals[$a['status']]++;
    }

    /* ---- billing */
    $billed = $expected = $confirm = $dues = $duesPast = $depHeld = 0.0;
    $confirmN = 0;
    $byStatus = [];
    foreach ($startups as $st) {
        $byStatus[$st['_status']] = ($byStatus[$st['_status']] ?? 0) + 1;
        $billed += $st['_rent'];
        if (in_array($st['_status'], ['OK', 'Billing to confirm'], true)) {
            $expected += $st['_full'];
        }
        if ($st['_status'] === 'Billing to confirm') {
            $confirm += $st['_full'];
            $confirmN++;
        }
        $d = (float) ($st['due_amount'] ?? 0);
        $dues += $d;
        if (!$st['_using']) {
            $duesPast += $d;
        }
        $depHeld += (float) ($st['deposit'] ?? 0);
    }
    $topDues = array_values(array_filter($startups, static fn($s) => (float) ($s['due_amount'] ?? 0) > 0));
    usort($topDues, static fn($a, $b) => (float) $b['due_amount'] <=> (float) $a['due_amount']);
    $topDues = array_slice($topDues, 0, 10);

    // Paperwork is about the centre's own tenants: a startup only on a partner's desks is the partner's to chase.
    $using = array_values(array_filter($startups, static fn($s) => $s['_own_n'] > 0));
    $three = date('Y-m-d', strtotime('-3 months'));
    $paperwork = [
        'Startups using space'          => count($using),
        'Without a signed agreement'    => $count($using, static fn($s) => !$s['_signed']),
        'Without an email on file'      => $count($using, static fn($s) => trim((string) $s['email']) === ''),
        'Joined in the last 3 months'   => $count($using, static fn($s) => $s['_first'] && $s['_first'] >= $three),
        'Deposits marked "Not Paid"'    => $count($startups, static fn($s) => (int) $s['deposit_not_paid'] === 1),
        'Deposit paid date missing'     => $count($using, static fn($s) => !$s['deposit_paid_on'] && !(int) $s['deposit_not_paid']),
    ];

    /* ---- vacancy check */
    $pool     = $count($desks, static fn($s) => !in_array($s['occupied_by'], ['Startup', 'Staff'], true));
    $notCheck = $count($desks, static fn($s) => $s['occupied_by'] === 'Not checked');

    /* ---- waiting list */
    $openStatuses = cw_open_wait_statuses();
    $pipeline = array_fill_keys($openStatuses, 0);
    $needed = 0;
    foreach ($waiting as &$w) {
        $w['_days'] = $w['applied_on'] ? cw_days_between($w['applied_on'], $today) : null;
        $w['_open'] = in_array($w['status'], $openStatuses, true);
        if ($w['_open']) {
            $pipeline[$w['status']]++;
        }
        if (in_array($w['status'], ['Approved – waiting for space', 'Offer sent'], true)) {
            $needed += (int) $w['desks_needed'];
        }
    }
    unset($w);
    $freeDesks = $count($desks, static fn($s) => $s['occupied_by'] === 'Vacant');

    $m = [
        'centre' => $centre, 'spaces' => $spaces, 'startups' => $startups, 'waiting' => $waiting, 'log' => $log,
        'desks_total' => $deskTotal, 'desks_taken' => $deskTaken,
        'occupancy' => $deskTotal ? $deskTaken / $deskTotal : 0.0,
        'desks_startup' => $count($desks, static fn($s) => $s['occupied_by'] === 'Startup'),
        'desks_staff' => $count($desks, static fn($s) => $s['occupied_by'] === 'Staff'),
        'desks_vendor' => $count($desks, static fn($s) => $s['occupied_by'] === 'Vendor'),
        'desks_vacant' => $freeDesks, 'desks_notchecked' => $notCheck,
        'cabins_total' => count($cabins),
        'cabins_taken' => $count($cabins, static fn($s) => in_array($s['occupied_by'], ['Startup', 'Staff', 'Vendor'], true)),
        'billed' => $billed, 'expected' => $expected, 'confirm' => $confirm, 'confirm_n' => $confirmN,
        'dues' => $dues, 'dues_past' => $duesPast, 'deposits' => $depHeld,
        'zones' => $zones, 'area' => $area, 'by_status' => $byStatus, 'top_dues' => $topDues, 'paperwork' => $paperwork,
        'agreements' => $agreements, 'renewals' => $renewals,
        'check' => ['pool' => $pool, 'checked' => $pool - $notCheck, 'notchecked' => $notCheck,
                    'vendor_people' => array_sum(array_map(static fn($s) => (int) $s['people'],
                                       array_filter($spaces, static fn($s) => $s['occupied_by'] === 'Vendor')))],
        'pipeline' => $pipeline, 'needed' => $needed, 'free' => $freeDesks, 'short' => max(0, $needed - $freeDesks),
        'partners' => $partnerNames,
    ];
    $m['actions'] = cw_actions($m, $known);
    $m['action_count'] = 0;
    foreach ($m['actions'] as $issues) {
        foreach ($issues as $items) {
            $m['action_count'] += count($items);
        }
    }
    return $m;
}

/**
 * Everything that needs following up, by priority: priority => issue => [[startup/space, detail], ...].
 * It builds itself; an item drops off once the data behind it is fixed.
 */
function cw_actions(array $m, array $known): array
{
    $fmtD  = static fn(?string $d) => $d ? date('d-M-y', strtotime($d)) : '';
    $money = static fn($v) => cw_inr($v);
    $a = ['High' => [], 'Medium' => [], 'Low' => []];
    $add = static function (string $prio, string $issue, string $who, string $detail) use (&$a) {
        $a[$prio][$issue][] = [$who, $detail];
    };
    foreach ($m['agreements'] as $g) {
        if ($g['status'] === 'Expired') {
            $add('High', 'Agreement expired', $g['startup'], 'Ended ' . $fmtD($g['end']) . ' (' . $g['spaces'] . ' space(s)) — renew and update the Agreement date on Space Register');
        }
    }
    foreach ($m['startups'] as $s) {
        if ($s['_status'] === 'Billing to confirm') {
            $add('High', 'Billing to confirm', $s['name'], $s['_desks'] . ' desk(s) + ' . $s['_cabins'] . ' cabin(s)'
                 . ($s['_open'] > 0 ? ' + ' . (float) $s['_open'] . ' sq ft' : '') . ' in use — set Billable on Startup Register');
        }
        if ($s['_status'] === 'Past tenant - dues open') {
            $add('High', 'Past tenant with dues', $s['name'], $money($s['due_amount']) . ' outstanding, no space in use');
        }
    }
    foreach ($m['spaces'] as $s) {
        if (in_array($s['occupied_by'], ['Startup', 'Vendor'], true) && trim($s['occupant']) === '') {
            $add('High', 'Space record to fix', $s['space_id'], 'Marked ' . $s['occupied_by'] . ' but no name is given');
        } elseif ($s['occupied_by'] === 'Startup' && !isset($known[cw_key($s['occupant'])])) {
            $add('High', 'Space record to fix', $s['space_id'], '"' . $s['occupant'] . '" is not on Startup Register — add it or fix the spelling');
        }
    }
    foreach ($m['agreements'] as $g) {
        if ($g['status'] === 'Due in 30 days') {
            $add('Medium', 'Renewal due in 30 days', $g['startup'], 'Ends ' . $fmtD($g['end']) . ' (' . $g['days'] . ' days left)');
        }
    }
    foreach ($m['startups'] as $s) {
        $usesOwn = $s['_own_n'] > 0;
        if ((int) $s['deposit_not_paid'] === 1) {
            $add('Medium', 'Deposit not paid', $s['name'], 'Deposit of ' . $money($s['deposit']) . ' marked Not Paid');
        } elseif ($usesOwn && !$s['deposit_paid_on']) {
            $add('Medium', 'Deposit date missing', $s['name'], 'No deposit paid date on Startup Register');
        }
        if ($usesOwn && !$s['_signed']) {
            $add('Medium', 'No signed agreement', $s['name'], 'None of its spaces has Agreement = Yes');
        }
        if ($usesOwn && trim((string) $s['email']) === '') {
            $add('Low', 'Email missing', $s['name'], 'No email on Startup Register');
        }
    }
    $vendors = [];
    foreach ($m['spaces'] as $s) {
        if ($s['type'] === 'Cabin' && $s['_own'] && $s['occupied_by'] === 'Startup' && ($s['agreement'] !== 'Yes' || !$s['agreement_date'])) {
            $add('Medium', 'Cabin agreement pending', $s['occupant'] ?: $s['space_id'], $s['space_id'] . ': no signed, dated agreement for the cabin');
        }
        if ($s['action'] !== '') {
            $add('Medium', 'Space flagged', $s['occupant'] ?: $s['space_id'], $s['space_id'] . ': ' . $s['action']);
        }
        if ($s['occupied_by'] === 'Vacant' && trim((string) $s['checked_by']) === '') {
            $add('Medium', 'Vacancy check pending', $s['space_id'], 'Marked Vacant — record who checked it and when');
        }
        if ($s['occupied_by'] === 'Vendor') {
            $vendors[cw_key($s['occupant']) ?: $s['space_id']][] = $s;
        }
    }
    foreach ($vendors as $list) {
        $v = $list[0];
        $name = $v['occupant'] ?: $v['space_id'];
        $ownN = count(array_filter($list, static fn($s) => $s['_own']));
        if ($ownN) {
            $add('Medium', 'Vendor on own desk (unpaid)', $name, $ownN . ' desk(s) — decide whether they stay, pay or move');
        }
        $have = array_filter($list, static fn($s) => trim((string) $s['vendor_what']) !== '' && (trim((string) $s['vendor_contact']) !== '' || trim((string) $s['vendor_phone']) !== ''));
        if (!$have) {
            $add('Low', 'Vendor details missing', $name, 'Fill in what they do and a contact on Space Register');
        }
    }
    foreach ($m['waiting'] as $w) {
        if ($w['_open'] && (($w['expected_start'] && $w['expected_start'] < cw_today()) || ($w['_days'] !== null && $w['_days'] > 30 && trim((string) $w['next_step']) === ''))) {
            $add('Medium', 'Waiting list follow-up', $w['applicant'], $w['status'] . ($w['_days'] !== null ? ' · waiting ' . $w['_days'] . ' days' : ''));
        }
    }
    return $a;
}

/** The headline numbers of a centre at this moment: the Monthly Log's live row. */
function cw_snapshot_row(array $m): array
{
    return [
        'desks_occupied' => $m['desks_taken'], 'occupancy' => round($m['occupancy'], 4), 'cabins_occupied' => $m['cabins_taken'],
        'vendors' => $m['desks_vendor'], 'truly_vacant' => $m['desks_vacant'], 'rent' => $m['billed'], 'billing_confirm' => $m['confirm'],
        'dues' => $m['dues'], 'action_items' => $m['action_count'], 'waiting_open' => array_sum($m['pipeline']),
        'renewals_due' => $m['renewals']['Expired'] + $m['renewals']['Due in 30 days'],
    ];
}

/** Indian grouping: 644116 -> ₹6,44,116. */
function cw_inr($v, bool $sign = true): string
{
    $n = (int) round((float) $v);
    $neg = $n < 0;
    $s = (string) abs($n);
    if (strlen($s) > 3) {
        $last = substr($s, -3);
        $rest = substr($s, 0, -3);
        $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
        $s = $rest . ',' . $last;
    }
    return ($neg ? '-' : '') . ($sign ? '₹' : '') . $s;
}

/* ================================================================ saving */

function cw_date_in($v): ?string
{
    $v = trim((string) $v);
    if ($v === '') {
        return null;
    }
    if (preg_match('~^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$~', $v, $x)) {
        return checkdate((int) $x[2], (int) $x[1], (int) $x[3]) ? sprintf('%04d-%02d-%02d', $x[3], $x[2], $x[1]) : null;
    }
    $t = strtotime($v);
    return $t ? date('Y-m-d', $t) : null;
}

function cw_num_in($v): ?float
{
    $v = trim(str_replace([',', '₹', ' '], '', (string) $v));
    return $v === '' || !is_numeric($v) ? null : (float) $v;
}

function cw_pick($v, array $allowed, string $default): string
{
    $v = trim((string) $v);
    return in_array($v, $allowed, true) ? $v : $default;
}

/**
 * A zone's proper name for a short code from the workbook: "CWS1" is
 * "Co-working Space 1", "ADMIN" the "Administration Area". Anything else is
 * kept as typed.
 */
function cw_proper_zone(string $zone): string
{
    $zone = trim($zone);
    if (preg_match('~^CWS[\s-]*(\d*)$~i', $zone, $m)) {
        return trim('Co-working Space ' . $m[1]);
    }
    return strcasecmp($zone, 'ADMIN') === 0 ? 'Administration Area' : $zone;
}

/**
 * A space's proper name for a short workbook code: "RTIH/CWS1/CH-41" is
 * "Co-working Space 1 · Desk 41" and "CABIN-9" is "Cabin 9". The zone stays
 * in a desk's name because desk numbers restart in every zone. A name that
 * is not one of those codes is kept as typed.
 */
function cw_proper_space(string $spaceId, string $type, string $zone): string
{
    $spaceId = trim($spaceId);
    if (preg_match('~^CABIN[\s-]*(\d+)$~i', $spaceId, $m)) {
        return 'Cabin ' . $m[1];
    }
    if (preg_match('~(?:^|/)CH-?(\d+)$~i', $spaceId, $m)) {
        $word = $type === 'Cabin' ? 'Cabin ' : 'Desk ';
        return ($zone !== '' ? $zone . ' · ' : '') . $word . $m[1];
    }
    return $spaceId;
}

function cw_space_save(int $centreId, ?int $id, array $in): int
{
    $c = cw_choices();
    $zone = mb_substr(cw_proper_zone((string) ($in['zone'] ?? '')), 0, 60);
    $spaceId = mb_substr(cw_proper_space((string) ($in['space_id'] ?? ''), (string) ($in['type'] ?? 'Desk'), $zone), 0, 80);
    if ($spaceId === '') {
        throw new InvalidArgumentException('Give the space an ID.');
    }
    $dup = fabo_one('SELECT id FROM cw_spaces WHERE centre_id = ? AND space_id = ? AND id <> ?', 'isi', [$centreId, $spaceId, (int) $id]);
    if ($dup) {
        throw new InvalidArgumentException('Another space is already called ' . $spaceId . '.');
    }
    $occ = cw_pick($in['occupied_by'] ?? '', $c['occupied_by'], 'Not checked');
    $vals = [
        $spaceId, cw_pick($in['type'] ?? '', $c['type'], 'Desk'), $zone,
        mb_substr(trim((string) ($in['managed_by'] ?? '')), 0, 60), $occ,
        in_array($occ, ['Startup', 'Staff', 'Vendor'], true) ? mb_substr(trim((string) ($in['occupant'] ?? '')), 0, 190) : '',
        cw_pick($in['agreement'] ?? '', $c['agreement'], ''), cw_date_in($in['agreement_date'] ?? ''),
        cw_pick($in['action'] ?? '', $c['action'], ''),
        trim((string) ($in['vendor_what'] ?? '')) ?: null, trim((string) ($in['vendor_contact'] ?? '')) ?: null,
        trim((string) ($in['vendor_phone'] ?? '')) ?: null, ($p = cw_num_in($in['people'] ?? '')) === null ? null : (int) $p,
        cw_date_in($in['using_since'] ?? ''), trim((string) ($in['checked_by'] ?? '')) ?: null, cw_date_in($in['checked_on'] ?? ''),
        trim((string) ($in['notes'] ?? '')) ?: null, ($in['type'] ?? '') === 'Open space' ? cw_num_in($in['area'] ?? '') : null,
    ];
    if ($id) {
        fabo_exec('UPDATE cw_spaces SET space_id=?, type=?, zone=?, managed_by=?, occupied_by=?, occupant=?, agreement=?, agreement_date=?,
                     action=?, vendor_what=?, vendor_contact=?, vendor_phone=?, people=?, using_since=?, checked_by=?, checked_on=?, notes=?, area=?
                   WHERE id = ? AND centre_id = ?', 'ssssssssssssissssdii', array_merge($vals, [$id, $centreId]));
        return $id;
    }
    $sort = (int) (fabo_one('SELECT COALESCE(MAX(sort), 0) + 1 n FROM cw_spaces WHERE centre_id = ?', 'i', [$centreId])['n'] ?? 1);
    return fabo_exec('INSERT INTO cw_spaces (space_id, type, zone, managed_by, occupied_by, occupant, agreement, agreement_date, action,
                        vendor_what, vendor_contact, vendor_phone, people, using_since, checked_by, checked_on, notes, area, centre_id, sort)
                      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', 'ssssssssssssissssdii', array_merge($vals, [$centreId, $sort]));
}

/** Adds $n spaces named <prefix><start..> in one zone. Returns how many were added. */
function cw_spaces_add(int $centreId, string $type, string $zone, string $prefix, int $start, int $n, string $managedBy): int
{
    $added = 0;
    $n = max(0, min(200, $n));
    // "… Desk" + 1 reads "… Desk 1", not "… Desk1".
    $prefix = preg_match('~\pL$~u', $prefix) ? $prefix . ' ' : $prefix;
    for ($i = 0; $i < $n; $i++) {
        try {
            cw_space_save($centreId, null, ['space_id' => $prefix . ($start + $i), 'type' => $type, 'zone' => $zone,
                                             'managed_by' => $managedBy, 'occupied_by' => 'Vacant']);
            $added++;
        } catch (InvalidArgumentException $e) {
            // that ID is taken: skip it
        }
    }
    return $added;
}

function cw_startup_save(int $centreId, ?int $id, array $in): int
{
    $c = cw_choices();
    $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 190);
    if ($name === '') {
        throw new InvalidArgumentException('Give the startup a name.');
    }
    $dup = fabo_one('SELECT id FROM cw_startups WHERE centre_id = ? AND name = ? AND id <> ?', 'isi', [$centreId, $name, (int) $id]);
    if ($dup) {
        throw new InvalidArgumentException($name . ' is already on Startup Register.');
    }
    $old = $id ? fabo_one('SELECT name FROM cw_startups WHERE id = ? AND centre_id = ?', 'ii', [$id, $centreId]) : null;
    $vals = [
        $name, mb_substr(trim((string) ($in['type'] ?? 'Startup')), 0, 40) ?: 'Startup',
        trim((string) ($in['email'] ?? '')) ?: null, trim((string) ($in['phone'] ?? '')) ?: null,
        cw_pick($in['billable'] ?? '', $c['billable'], 'To confirm'),
        cw_num_in($in['due_amount'] ?? ''), cw_date_in($in['dues_as_on'] ?? ''), cw_num_in($in['deposit'] ?? ''),
        cw_date_in($in['deposit_paid_on'] ?? ''), !empty($in['deposit_not_paid']) ? 1 : 0, trim((string) ($in['notes'] ?? '')) ?: null,
    ];
    if ($id) {
        fabo_exec('UPDATE cw_startups SET name=?, type=?, email=?, phone=?, billable=?, due_amount=?, dues_as_on=?, deposit=?,
                     deposit_paid_on=?, deposit_not_paid=?, notes=? WHERE id = ? AND centre_id = ?',
                  'sssssdsdsisii', array_merge($vals, [$id, $centreId]));
        // A renamed startup keeps its spaces: the Space Register follows the new name.
        if ($old && $old['name'] !== $name) {
            fabo_exec('UPDATE cw_spaces SET occupant = ? WHERE centre_id = ? AND occupant = ?', 'sis', [$name, $centreId, $old['name']]);
        }
        return $id;
    }
    $sort = (int) (fabo_one('SELECT COALESCE(MAX(sort), 0) + 1 n FROM cw_startups WHERE centre_id = ?', 'i', [$centreId])['n'] ?? 1);
    return fabo_exec('INSERT INTO cw_startups (name, type, email, phone, billable, due_amount, dues_as_on, deposit, deposit_paid_on,
                        deposit_not_paid, notes, centre_id, sort) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                     'sssssdsdsisii', array_merge($vals, [$centreId, $sort]));
}

function cw_wait_save(int $centreId, ?int $id, array $in): int
{
    $c = cw_choices();
    $name = mb_substr(trim((string) ($in['applicant'] ?? '')), 0, 190);
    if ($name === '') {
        throw new InvalidArgumentException('Give the applicant a name.');
    }
    $vals = [
        $name, trim((string) ($in['contact'] ?? '')) ?: null, trim((string) ($in['email'] ?? '')) ?: null,
        trim((string) ($in['phone'] ?? '')) ?: null, trim((string) ($in['sector'] ?? '')) ?: null,
        ($d = cw_num_in($in['desks_needed'] ?? '')) === null ? null : (int) $d, cw_pick($in['cabin_needed'] ?? '', ['', 'Yes', 'No'], ''),
        cw_proper_zone((string) ($in['preferred_zone'] ?? '')) ?: null, trim((string) ($in['source'] ?? '')) ?: null,
        cw_date_in($in['applied_on'] ?? '') ?? cw_today(), cw_pick($in['status'] ?? '', $c['wait_status'], 'New'),
        cw_date_in($in['expected_start'] ?? ''), trim((string) ($in['next_step'] ?? '')) ?: null,
        trim((string) ($in['owner'] ?? '')) ?: null, trim((string) ($in['notes'] ?? '')) ?: null,
    ];
    if ($id) {
        fabo_exec('UPDATE cw_waiting SET applicant=?, contact=?, email=?, phone=?, sector=?, desks_needed=?, cabin_needed=?, preferred_zone=?,
                     source=?, applied_on=?, status=?, expected_start=?, next_step=?, owner=?, notes=? WHERE id = ? AND centre_id = ?',
                  'sssssisssssssssii', array_merge($vals, [$id, $centreId]));
        return $id;
    }
    return fabo_exec('INSERT INTO cw_waiting (applicant, contact, email, phone, sector, desks_needed, cabin_needed, preferred_zone, source,
                        applied_on, status, expected_start, next_step, owner, notes, centre_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                     'sssssisssssssssi', array_merge($vals, [$centreId]));
}

/** The month-end snapshot: this month's live numbers kept in Monthly Log (replacing one already taken this month). */
function cw_snapshot_take(array $centre, string $notes = ''): void
{
    $row = cw_snapshot_row(cw_model($centre));
    $month = date('Y-m-01');
    fabo_exec('DELETE FROM cw_monthly WHERE centre_id = ? AND month = ?', 'is', [(int) $centre['id'], $month]);
    fabo_exec('INSERT INTO cw_monthly (centre_id, month, desks_occupied, occupancy, cabins_occupied, vendors, truly_vacant, rent,
                 billing_confirm, dues, action_items, waiting_open, renewals_due, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
              'isidiiidddiiis', [(int) $centre['id'], $month, $row['desks_occupied'], $row['occupancy'], $row['cabins_occupied'],
                                 $row['vendors'], $row['truly_vacant'], $row['rent'], $row['billing_confirm'], $row['dues'],
                                 $row['action_items'], $row['waiting_open'], $row['renewals_due'], $notes !== '' ? mb_substr($notes, 0, 500) : null]);
}

/* ================================================================ import */

/**
 * Loads a workbook in the Coworking Master template into a centre, replacing
 * what the centre held: Space Register, Startup Register (with the rates and
 * term at its top), Waiting List and the Monthly Log history. Columns are
 * found by their headings, so an added or moved column does no harm.
 * Returns counts of what was loaded.
 */
function cw_import(array $centre, string $path): array
{
    require_once __DIR__ . '/xlsx.php';
    $book = cw_xlsx_read($path);
    $find = static function (string $want) use ($book): ?array {
        foreach ($book as $name => $rows) {
            if (cw_key($name) === cw_key($want)) {
                return $rows;
            }
        }
        return null;
    };
    $spacesSh = $find('Space Register');
    $startSh  = $find('Startup Register');
    if ($spacesSh === null || $startSh === null) {
        foreach ($book as $rows) {
            if (cw_allocation_heading($rows) !== null) {
                return cw_import_allocation($centre, $rows);
            }
        }
        throw new InvalidArgumentException('This workbook has no "Space Register" and "Startup Register" tabs, nor a "Co-working ID" allocation sheet — use the Coworking Master template.');
    }
    $table = static function (array $rows, string $firstHeading): array {
        foreach ($rows as $r => $cells) {
            if (cw_key((string) ($cells['A'] ?? '')) === cw_key($firstHeading)) {
                $cols = [];
                foreach ($cells as $col => $v) {
                    if (trim((string) $v) !== '') {
                        $cols[cw_key((string) $v)] = $col;
                    }
                }
                $out = [];
                foreach ($rows as $r2 => $c2) {
                    if ($r2 <= $r) {
                        continue;
                    }
                    $row = [];
                    foreach ($cols as $k => $col) {
                        $row[$k] = $c2[$col] ?? '';
                    }
                    $out[] = $row;
                }
                return $out;
            }
        }
        return [];
    };
    $date = static fn($v) => cw_xlsx_date($v);
    $cid = (int) $centre['id'];

    $db = fabo_db();
    $db->begin_transaction();
    try {
        fabo_exec('DELETE FROM cw_spaces WHERE centre_id = ?', 'i', [$cid]);
        fabo_exec('DELETE FROM cw_startups WHERE centre_id = ?', 'i', [$cid]);
        fabo_exec('DELETE FROM cw_waiting WHERE centre_id = ?', 'i', [$cid]);

        /* Rates and term: the cells beside their labels at the top of Startup Register. */
        $rates = ['desk rate' => 'desk_rate', 'cabin rate' => 'cabin_rate', 'agreement term' => 'term_months'];
        $set = [];
        foreach (array_slice($startSh, 0, 6, true) as $cells) {
            $cols = array_keys($cells);
            foreach ($cols as $i => $col) {
                foreach ($rates as $label => $field) {
                    if (str_starts_with(cw_key((string) $cells[$col]), $label) && isset($cols[$i + 1]) && is_numeric($cells[$cols[$i + 1]])) {
                        $set[$field] = (float) $cells[$cols[$i + 1]];
                    }
                }
            }
        }
        if ($set) {
            fabo_exec('UPDATE cw_centres SET desk_rate = ?, cabin_rate = ?, term_months = ? WHERE id = ?', 'ddii',
                      [$set['desk_rate'] ?? (float) $centre['desk_rate'], $set['cabin_rate'] ?? (float) $centre['cabin_rate'],
                       (int) ($set['term_months'] ?? $centre['term_months']), $cid]);
        }

        $nStart = 0;
        foreach ($table($startSh, 'Startup name') as $r) {
            $name = trim((string) ($r['startup name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $paid = trim((string) ($r['deposit paid on'] ?? ''));
            cw_startup_save($cid, null, [
                'name' => $name, 'type' => $r['type'] ?? 'Startup', 'email' => $r['email'] ?? '', 'phone' => cw_xlsx_text($r['phone'] ?? ''),
                'billable' => $r['billable'] ?? 'To confirm', 'due_amount' => $r['due amount (₹)'] ?? '',
                'dues_as_on' => $date($r['dues as on'] ?? ''), 'deposit' => $r['security deposit (₹)'] ?? '',
                'deposit_paid_on' => cw_key($paid) === 'not paid' ? '' : $date($paid),
                'deposit_not_paid' => cw_key($paid) === 'not paid' ? 1 : 0, 'notes' => $r['notes'] ?? '',
            ]);
            $nStart++;
        }

        $nSpace = 0;
        foreach ($table($spacesSh, 'Space ID') as $r) {
            $sid = trim((string) ($r['space id'] ?? ''));
            if ($sid === '') {
                continue;
            }
            cw_space_save($cid, null, [
                'space_id' => $sid, 'type' => $r['type'] ?? 'Desk', 'zone' => $r['zone'] ?? '', 'managed_by' => $r['managed by'] ?? '',
                'occupied_by' => $r['occupied by'] ?? 'Not checked', 'occupant' => $r['startup / vendor name'] ?? '',
                'agreement' => $r['agreement'] ?? '', 'agreement_date' => $date($r['agreement date'] ?? ''),
                'action' => $r['action / notice'] ?? '', 'vendor_what' => $r['vendor: what they do'] ?? '',
                'vendor_contact' => $r['vendor contact'] ?? '', 'vendor_phone' => cw_xlsx_text($r['vendor phone'] ?? ''),
                'people' => $r['no. of people'] ?? '', 'using_since' => $date($r['using since'] ?? ''),
                'checked_by' => $r['checked by'] ?? '', 'checked_on' => $date($r['checked on'] ?? ''), 'notes' => $r['notes'] ?? '',
            ]);
            $nSpace++;
        }

        $nWait = 0;
        if ($waitSh = $find('Waiting List')) {
            foreach ($table($waitSh, 'Applicant / startup') as $r) {
                if (trim((string) ($r['applicant / startup'] ?? '')) === '') {
                    continue;
                }
                cw_wait_save($cid, null, [
                    'applicant' => $r['applicant / startup'], 'contact' => $r['contact person'] ?? '', 'email' => $r['email'] ?? '',
                    'phone' => cw_xlsx_text($r['phone'] ?? ''), 'sector' => $r['sector'] ?? '', 'desks_needed' => $r['desks needed'] ?? '',
                    'cabin_needed' => $r['cabin needed'] ?? '', 'preferred_zone' => $r['preferred zone'] ?? '',
                    'source' => $r['source / referred by'] ?? '', 'applied_on' => $date($r['applied on'] ?? ''),
                    'status' => $r['status'] ?? 'New', 'expected_start' => $date($r['expected start'] ?? ''),
                    'next_step' => $r['next step'] ?? '', 'owner' => $r['owner'] ?? '', 'notes' => $r['notes'] ?? '',
                ]);
                $nWait++;
            }
        }

        /* Monthly Log: the history rows under "History" (the live row above it is worked out here instead). */
        $nLog = 0;
        if ($logSh = $find('Monthly Log')) {
            fabo_exec('DELETE FROM cw_monthly WHERE centre_id = ?', 'i', [$cid]);
            $inHistory = false;
            foreach ($logSh as $cells) {
                $a = (string) ($cells['A'] ?? '');
                if (str_starts_with(cw_key($a), 'history')) {
                    $inHistory = true;
                    continue;
                }
                if (!$inHistory || !is_numeric($a)) {
                    continue;
                }
                $n = static fn($k) => isset($cells[$k]) && is_numeric($cells[$k]) ? (float) $cells[$k] : null;
                fabo_exec('INSERT INTO cw_monthly (centre_id, month, desks_occupied, occupancy, cabins_occupied, vendors, truly_vacant, rent,
                             billing_confirm, dues, action_items, waiting_open, renewals_due, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                          'isidiiidddiiis', [$cid, date('Y-m-01', strtotime((string) cw_xlsx_date($a))), $n('B'), $n('C'), $n('D'), $n('E'), $n('F'),
                                             $n('G'), $n('H'), $n('I'), $n('J'), $n('K'), $n('L'), isset($cells['M']) ? mb_substr((string) $cells['M'], 0, 500) : null]);
                $nLog++;
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
    return ['spaces' => $nSpace, 'startups' => $nStart, 'waiting' => $nWait, 'log' => $nLog];
}

/**
 * Where a "Co-working Space Allocation" sheet's headings are (one row per
 * startup with its room, seats, mode and January..December ticks, as the
 * spokes keep it): [heading row, heading => column], or null when $rows is
 * not one.
 */
function cw_allocation_heading(array $rows): ?array
{
    foreach (array_slice($rows, 0, 15, true) as $r => $cells) {
        $cols = [];
        foreach ($cells as $col => $v) {
            if (trim((string) $v) !== '') {
                $cols[cw_key((string) $v)] = $col;
            }
        }
        if (isset($cols['co-working id'], $cols['name of the startup'])) {
            return [$r, $cols];
        }
    }
    return null;
}

/**
 * Loads an allocation sheet into a centre, replacing its spaces and startups.
 * Every allotted number is a desk ("Desk 3") for its startup; a desk number
 * missing between 1 and the highest is added as a vacant desk. Pro bono
 * startups are Waived, payment-basis ones billable at the desk rent, which
 * becomes the centre's desk rate. Founder, POC, registration numbers,
 * paperwork and the months ticked go in the startup's notes. The waiting
 * list and monthly log are not on the sheet, so they are kept.
 */
function cw_import_allocation(array $centre, array $rows): array
{
    [$head, $cols] = cw_allocation_heading($rows);
    $cid = (int) $centre['id'];
    $year = date('Y');
    foreach (array_slice($rows, 0, $head, true) as $cells) {
        if (preg_match('~\b(20\d\d)\b~', implode(' ', $cells), $m)) {
            $year = $m[1];
        }
    }
    $months = [];
    foreach (['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december'] as $mo) {
        if (isset($cols[$mo])) {
            $months[ucfirst(substr($mo, 0, 3))] = $cols[$mo];
        }
    }
    $get = static function (array $cells, string ...$keys) use ($cols): string {
        foreach ($keys as $k) {
            if (isset($cols[$k]) && trim((string) ($cells[$cols[$k]] ?? '')) !== '') {
                return trim(preg_replace('/\s+/', ' ', (string) $cells[$cols[$k]]));
            }
        }
        return '';
    };
    $coId = static fn(string $v) => preg_replace('/\s*-\s*/', '-', $v);

    $startups = $spaces = $rents = [];
    foreach ($rows as $r => $cells) {
        $name = $get($cells, 'name of the startup');
        if ($r <= $head || $name === '') {
            continue;
        }
        $mode = cw_key($get($cells, 'mode'));
        $rent = cw_num_in($get($cells, 'rental charges/month'));
        $paid = str_contains($mode, 'payment');
        $free = str_contains($mode, 'pro bono') || cw_key($get($cells, 'rental charges/month')) === 'free';
        if ($paid && $rent) {
            $rents[] = $rent;
        }
        $ticked = ['yes' => [], 'no' => []];
        foreach ($months as $label => $col) {
            $v = cw_key((string) ($cells[$col] ?? ''));
            if (isset($ticked[$v])) {
                $ticked[$v][] = $label;
            }
        }
        /* A date Excel took in month-first order (1 Oct typed, 10 Jan stored): when the
           day and month read the other way round fall in the first month ticked, that is it. */
        $first = $ticked['yes'] ? (int) date('n', strtotime('1 ' . $ticked['yes'][0] . ' ' . $year)) : 0;
        $date = static function (string $v) use ($first, $year): string {
            $d = cw_xlsx_date($v);
            if ($d === '' || !is_numeric($v) || !$first) {
                return $d;
            }
            [$y, $m, $day] = array_map('intval', explode('-', $d));
            return $day <= 12 && $m !== $first && $day === $first && (string) $y === (string) $year
                ? sprintf('%04d-%02d-%02d', $y, $day, $m) : $d;
        };
        $agreement = cw_key($get($cells, 'agreement signing status'));
        $allotted = $date($get($cells, 'date of allocation'));
        $joined = $date($get($cells, 'date of joining'));
        $room = $get($cells, 'alloted room/cabin', 'allotted room/cabin');
        $vacated = in_array('vacated', array_map(static fn($v) => cw_key((string) $v), $cells), true);
        $notes = [];
        foreach (['Founder' => 'name of the founder', 'RTIH POC' => 'rtih poc', 'DPIIT' => 'dpiit number', 'CIN' => 'cin number',
                  'GST' => 'gst number', 'Co-working ID' => 'co-working id', 'Mode' => 'mode', 'Rent / month' => 'rental charges/month',
                  'Documents submitted' => 'documents submitted', 'Agreement' => 'agreement signing status',
                  'Agreement soft copy' => 'agreement soft copy'] as $label => $k) {
            $v = $get($cells, $k);
            if ($v !== '' && !in_array(cw_key($v), ['na', 'vacated'], true)) {
                $notes[] = $label . ': ' . ($k === 'co-working id' ? $coId($v) : cw_xlsx_text($v));
            }
        }
        if ($allotted !== '') {
            $notes[] = 'Allotted on: ' . date('d M Y', strtotime($allotted));
        }
        if ($joined !== '') {
            $notes[] = 'Joined on: ' . date('d M Y', strtotime($joined));
        }
        if ($ticked['yes']) {
            $notes[] = 'Months in use (' . $year . '): ' . implode(', ', $ticked['yes']);
        }
        if ($ticked['no']) {
            $notes[] = 'Months not in use (' . $year . '): ' . implode(', ', $ticked['no']);
        }
        if ($vacated) {
            $notes[] = 'Vacated';
        }
        $email = $get($cells, 'email');
        $startups[] = [
            'name' => $name, 'type' => 'Startup', 'email' => str_contains($email, '@') ? $email : '',
            'phone' => cw_xlsx_text($get($cells, 'contact')),
            'billable' => $free ? 'Waived' : ($paid ? 'Yes' : 'To confirm'), 'notes' => implode("\n", $notes),
        ];
        if (!$vacated && is_numeric($room)) {
            $id = $get($cells, 'co-working id');
            $spaces[(int) $room] = [
                'type' => 'Desk', 'occupied_by' => 'Startup', 'occupant' => $name,
                'agreement' => $agreement === 'completed' ? 'Yes' : ($agreement !== '' ? 'No' : ''), 'agreement_date' => $allotted,
                'people' => $get($cells, 'no. of seats'), 'using_since' => $joined ?: $allotted,
                'notes' => $id !== '' ? 'Co-working ID: ' . $coId($id) : '',
            ];
        }
    }
    if (!$startups) {
        throw new InvalidArgumentException('The allocation sheet has no startups under its headings.');
    }
    for ($n = 1, $max = $spaces ? max(array_keys($spaces)) : 0; $n <= $max; $n++) {
        $spaces[$n] ??= ['type' => 'Desk', 'occupied_by' => 'Vacant', 'notes' => 'Not on the allocation sheet'];
    }
    ksort($spaces);

    $db = fabo_db();
    $db->begin_transaction();
    try {
        fabo_exec('DELETE FROM cw_spaces WHERE centre_id = ?', 'i', [$cid]);
        fabo_exec('DELETE FROM cw_startups WHERE centre_id = ?', 'i', [$cid]);
        if ($rents) {
            // The rent most rooms are let at.
            $counts = array_count_values(array_map('strval', $rents));
            arsort($counts);
            fabo_exec('UPDATE cw_centres SET desk_rate = ? WHERE id = ?', 'di', [(float) array_key_first($counts), $cid]);
        }
        foreach ($startups as $st) {
            cw_startup_save($cid, null, $st);
        }
        foreach ($spaces as $n => $s) {
            cw_space_save($cid, null, ['space_id' => 'Desk ' . $n] + $s);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
    return ['spaces' => count($spaces), 'startups' => count($startups), 'waiting' => 0, 'log' => 0, 'kept' => true];
}

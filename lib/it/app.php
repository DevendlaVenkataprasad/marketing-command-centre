<?php
/**
 * IT Management and Admin & Facilities — one desk engine (see lib/it/core.php
 * for the two desks, whose data is whose, and who may use them).
 *
 *   Tickets   a helpdesk: raised by staff through the private request link
 *             (it-request.php) or logged here; assigned, worked on a board,
 *             answered by email, resolved and closed. The requester follows
 *             and replies through their own ticket link.
 *   Assets    every device: added, assigned to a person or a place, returned,
 *             sent for repair, retired — each step kept in its history.
 *   Renewals  software, domains, SSL, hosting, AMC, internet: what renews
 *             when and for how much, with reminders and "mark renewed".
 *   Vendors   who supplies and services what.
 *
 * Its own tables (it_*), created on first use. The page furniture — board,
 * pop-up, filters, tiles, buttons — and email delivery are the incubation
 * module's (lib/inc/app.php), reused rather than copied.
 */

require_once __DIR__ . '/../ui.php';
require_once __DIR__ . '/../inc/app.php';
require_once __DIR__ . '/core.php';

const IT_SCHEMA_VERSION = 3;

/* ================================================================ schema */

function it_install(): void
{
    static $done = [];
    if (isset($done[it_prefix()])) {
        return;
    }
    $done[it_prefix()] = true;
    try {
        $have = it_one("SELECT v FROM it_settings WHERE k = '_schema'");
        if ($have && (int) $have['v'] >= IT_SCHEMA_VERSION) {
            return;
        }
    } catch (Throwable $e) {
        // First run: the tables are not there yet.
    }
    $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $db = fabo_db();
    foreach ([
        "CREATE TABLE IF NOT EXISTS it_settings (k VARCHAR(60) NOT NULL PRIMARY KEY, v TEXT NULL) $t",
        "CREATE TABLE IF NOT EXISTS it_lists (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, kind VARCHAR(30) NOT NULL, name VARCHAR(120) NOT NULL,
           active TINYINT(1) NOT NULL DEFAULT 1, sort SMALLINT NOT NULL DEFAULT 0, UNIQUE KEY u_kind_name (kind, name)) $t",
        "CREATE TABLE IF NOT EXISTS it_vendors (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(160) NOT NULL, contact VARCHAR(160) NULL, email VARCHAR(190) NULL,
           phone VARCHAR(40) NULL, services VARCHAR(255) NULL, notes TEXT NULL, active TINYINT(1) NOT NULL DEFAULT 1,
           created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY u_name (name)) $t",
        "CREATE TABLE IF NOT EXISTS it_assets (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tag VARCHAR(40) NOT NULL, name VARCHAR(160) NOT NULL, type VARCHAR(120) NULL,
           brand VARCHAR(80) NULL, model VARCHAR(120) NULL, serial VARCHAR(120) NULL,
           status ENUM('in_stock','assigned','repair','retired','lost') NOT NULL DEFAULT 'in_stock',
           assigned_name VARCHAR(160) NULL, assigned_person_id INT UNSIGNED NULL, location VARCHAR(120) NULL,
           purchase_date DATE NULL, cost DECIMAL(12,2) NULL, vendor_id INT UNSIGNED NULL, warranty_end DATE NULL, notes TEXT NULL,
           created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
           UNIQUE KEY u_tag (tag), KEY k_status (status), KEY k_person (assigned_person_id)) $t",
        "CREATE TABLE IF NOT EXISTS it_tickets (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, ref VARCHAR(24) NULL, token CHAR(32) NOT NULL,
           title VARCHAR(200) NOT NULL, description TEXT NULL, category VARCHAR(120) NULL,
           priority ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
           status ENUM('new','in_progress','waiting','resolved','closed') NOT NULL DEFAULT 'new',
           requester_name VARCHAR(160) NOT NULL DEFAULT '', requester_email VARCHAR(190) NULL, requester_id INT UNSIGNED NULL,
           assignee_id INT UNSIGNED NULL, asset_id INT UNSIGNED NULL, channel ENUM('desk','link') NOT NULL DEFAULT 'desk',
           due_at DATETIME NULL, first_reply_at DATETIME NULL, resolved_at DATETIME NULL, closed_at DATETIME NULL,
           created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
           UNIQUE KEY u_token (token), UNIQUE KEY u_ref (ref), KEY k_status (status), KEY k_assignee (assignee_id), KEY k_asset (asset_id)) $t",
        "CREATE TABLE IF NOT EXISTS it_ticket_notes (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, ticket_id INT UNSIGNED NOT NULL,
           kind ENUM('reply','internal','requester','system') NOT NULL, body TEXT NOT NULL, actor VARCHAR(190) NOT NULL DEFAULT '',
           emailed TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY k_ticket (ticket_id, created_at)) $t",
        "CREATE TABLE IF NOT EXISTS it_renewals (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(160) NOT NULL,
           kind VARCHAR(30) NOT NULL DEFAULT 'other',
           vendor_id INT UNSIGNED NULL, cost DECIMAL(12,2) NULL, billing ENUM('monthly','quarterly','yearly','one_time') NOT NULL DEFAULT 'yearly',
           renews_on DATE NULL, seats INT NULL, seats_used INT NULL, owner VARCHAR(160) NULL, auto_renew TINYINT(1) NOT NULL DEFAULT 0,
           status ENUM('active','cancelled') NOT NULL DEFAULT 'active', notes TEXT NULL, last_renewed_on DATE NULL,
           created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
           KEY k_renews (status, renews_on)) $t",
        "CREATE TABLE IF NOT EXISTS it_activity (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, entity VARCHAR(20) NOT NULL, entity_id INT UNSIGNED NULL,
           summary VARCHAR(500) NOT NULL, actor VARCHAR(190) NOT NULL DEFAULT '', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
           KEY k_entity (entity, entity_id, created_at)) $t",
    ] as $sql) {
        $db->query(it_sql($sql));
    }
    // Admin & Facilities keeps more than the shared desk: its people, their duty, housekeeping, visitors, registers and stock.
    if (it_desk() === 'fa') {
        foreach ([
            "CREATE TABLE IF NOT EXISTS it_people (
               id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(160) NOT NULL, role VARCHAR(120) NULL, agency_id INT UNSIGNED NULL,
               phone VARCHAR(40) NULL, id_proof VARCHAR(160) NULL, verification ENUM('pending','verified','not_needed') NOT NULL DEFAULT 'pending',
               shift VARCHAR(120) NULL, post VARCHAR(120) NULL, emergency VARCHAR(190) NULL, joined_on DATE NULL, left_on DATE NULL,
               active TINYINT(1) NOT NULL DEFAULT 1, notes TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY k_active (active, role)) $t",
            "CREATE TABLE IF NOT EXISTS it_duty (
               id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, person_id INT UNSIGNED NOT NULL, day DATE NOT NULL, shift VARCHAR(120) NULL, post VARCHAR(120) NULL,
               status ENUM('present','absent','leave','replaced','off') NOT NULL DEFAULT 'present', in_time TIME NULL, out_time TIME NULL, note VARCHAR(255) NULL,
               updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY u_day (person_id, day), KEY k_day (day)) $t",
            "CREATE TABLE IF NOT EXISTS it_tasks (
               id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, area VARCHAR(120) NULL, task VARCHAR(255) NOT NULL, frequency ENUM('daily','weekly','monthly') NOT NULL DEFAULT 'daily',
               weekday TINYINT NULL, monthday TINYINT NULL, person_id INT UNSIGNED NULL, active TINYINT(1) NOT NULL DEFAULT 1, sort SMALLINT NOT NULL DEFAULT 0) $t",
            "CREATE TABLE IF NOT EXISTS it_task_log (
               id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, task_id INT UNSIGNED NOT NULL, day DATE NOT NULL, done_by VARCHAR(160) NULL, done_at DATETIME NULL,
               verified_by VARCHAR(160) NULL, verified_at DATETIME NULL, note VARCHAR(255) NULL, UNIQUE KEY u_task_day (task_id, day), KEY k_day (day)) $t",
            "CREATE TABLE IF NOT EXISTS it_visitors (
               id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, pass_no VARCHAR(24) NULL, name VARCHAR(160) NOT NULL, phone VARCHAR(40) NULL, company VARCHAR(160) NULL,
               purpose VARCHAR(255) NULL, host_name VARCHAR(160) NULL, host_id INT UNSIGNED NULL, id_proof VARCHAR(160) NULL, vehicle VARCHAR(40) NULL, people SMALLINT NOT NULL DEFAULT 1,
               in_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, out_at DATETIME NULL, KEY k_in (in_at), KEY k_out (out_at)) $t",
            "CREATE TABLE IF NOT EXISTS it_registers (
               id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, type ENUM('material_out','material_in','key','incident','courier_in','courier_out') NOT NULL,
               ref_no VARCHAR(24) NULL, title VARCHAR(200) NOT NULL, person VARCHAR(160) NULL, details TEXT NULL, due_back DATE NULL,
               status ENUM('open','closed') NOT NULL DEFAULT 'open', closed_note VARCHAR(500) NULL, opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, closed_at DATETIME NULL,
               KEY k_type (type, status)) $t",
            "CREATE TABLE IF NOT EXISTS it_stock (
               id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(160) NOT NULL, category VARCHAR(80) NULL, unit VARCHAR(30) NOT NULL DEFAULT 'pcs',
               qty DECIMAL(12,2) NOT NULL DEFAULT 0, reorder_level DECIMAL(12,2) NOT NULL DEFAULT 0, active TINYINT(1) NOT NULL DEFAULT 1, UNIQUE KEY u_name (name)) $t",
            "CREATE TABLE IF NOT EXISTS it_stock_moves (
               id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, item_id INT UNSIGNED NOT NULL, qty DECIMAL(12,2) NOT NULL, kind ENUM('in','out','adjust') NOT NULL,
               note VARCHAR(255) NULL, actor VARCHAR(190) NOT NULL DEFAULT '', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY k_item (item_id, created_at)) $t",
        ] as $sql) {
            $db->query(it_sql($sql));
        }
    }
    // 2: kinds and activity entities became free text, so each desk can have its own.
    $col = it_one("SHOW COLUMNS FROM it_renewals LIKE 'kind'");
    if ($col && str_starts_with(strtolower((string) $col['Type']), 'enum')) {
        $db->query(it_sql("ALTER TABLE it_renewals MODIFY kind VARCHAR(30) NOT NULL DEFAULT 'other'"));
    }
    $col = it_one("SHOW COLUMNS FROM it_activity LIKE 'entity'");
    if ($col && str_starts_with(strtolower((string) $col['Type']), 'enum')) {
        $db->query(it_sql("ALTER TABLE it_activity MODIFY entity VARCHAR(20) NOT NULL"));
    }
    // 3: requests raised from the portal's helpdesk (see it_self_org()).
    $col = it_one("SHOW COLUMNS FROM it_tickets LIKE 'channel'");
    if ($col && !str_contains((string) $col['Type'], "'portal'")) {
        $db->query(it_sql("ALTER TABLE it_tickets MODIFY channel ENUM('desk','link','portal') NOT NULL DEFAULT 'desk'"));
    }
    // Starting lists; the administrator edits them in Settings.
    foreach (it_cfg('lists') as $kind => $names) {
        if (!it_one('SELECT id FROM it_lists WHERE kind = ? LIMIT 1', 's', [$kind])) {
            foreach ($names as $i => $n) {
                it_exec('INSERT IGNORE INTO it_lists (kind, name, sort) VALUES (?,?,?)', 'ssi', [$kind, $n, $i + 1]);
            }
        }
    }
    if (it_setting('request_token') === '') {
        it_setting_set('request_token', bin2hex(random_bytes(16)));
    }
    it_setting_set('_schema', (string) IT_SCHEMA_VERSION);
}

/* ============================================================= settings */

function it_setting_defaults(): array
{
    return [
        'sla_urgent' => '4', 'sla_high' => '8', 'sla_medium' => '24', 'sla_low' => '72',   // hours to resolve
        'team_ids' => '',             // who tickets may be assigned to; empty = every active team member
        'request_enabled' => '0',     // the private request link is off until switched on
        'request_token' => '',
        'notify_requester' => '1', 'notify_team' => '1',
        'auto_close_days' => '3',     // a resolved ticket closes itself after this many days without a reply
        'remind_days' => '30',        // renewals and warranties this close are flagged and mailed
    ];
}

function it_setting(string $k): string
{
    $cache = &$GLOBALS['it_settings_cache'][it_prefix()];
    $cache ??= [];
    if (!array_key_exists($k, $cache)) {
        try {
            $r = it_one('SELECT v FROM it_settings WHERE k = ?', 's', [$k]);
        } catch (Throwable $e) {
            $r = null;
        }
        $cache[$k] = $r !== null ? (string) $r['v'] : (it_setting_defaults()[$k] ?? '');
    }
    return $cache[$k];
}

function it_setting_set(string $k, string $v): void
{
    it_exec('INSERT INTO it_settings (k, v) VALUES (?,?) ON DUPLICATE KEY UPDATE v = VALUES(v)', 'ss', [$k, $v]);
    $GLOBALS['it_settings_cache'][it_prefix()][$k] = $v;
}

/** Active (or all) names of one list: id => name. */
function it_list(string $kind, bool $activeOnly = true): array
{
    $out = [];
    foreach (it_all('SELECT id, name FROM it_lists WHERE kind = ?' . ($activeOnly ? ' AND active = 1' : '') . ' ORDER BY sort, name', 's', [$kind]) as $r) {
        $out[$r['name']] = $r['name'];
    }
    return $out;
}

function it_list_kinds(): array
{
    $labels = ['ticket_category' => it_cfg('ticket') . ' categories', 'asset_type' => it_cfg('asset') . ' types', 'location' => 'Locations',
               'staff_role' => 'Staff roles', 'shift' => 'Shifts', 'area' => 'Housekeeping areas'];
    return array_intersect_key($labels, it_cfg('lists'));
}

/* =============================================================== people */

/** Every active team member of ours: id => [name, email]. */
function it_staff(): array
{
    static $byOrg = [];
    $out = &$byOrg[it_org()];
    if ($out === null) {
        $out = [];
        $org = it_org();
        foreach (fabo_all("SELECT id, name, email FROM clients WHERE " . ($org > 0 ? '((kind = \'team\' AND parent_id = ' . $org . ') OR id = ' . $org . ')' : "kind = 'team' AND (parent_id IS NULL OR parent_id = 0)")
                           . " AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY name") as $r) {
            $out[(int) $r['id']] = ['name' => (string) $r['name'], 'email' => (string) $r['email']];
        }
    }
    return $out;
}

/** Who tickets can be assigned to: the IT team chosen in Settings, or everyone. id => name. */
function it_team(): array
{
    $ids = array_filter(array_map('intval', explode(',', it_setting('team_ids'))));
    $staff = it_staff();
    $pick = $ids ? array_intersect_key($staff, array_flip($ids)) : $staff;
    return array_map(static fn($p) => $p['name'], $pick);
}

function it_person_name(?int $id): string
{
    return $id ? (it_staff()[$id]['name'] ?? '(former member)') : '';
}

function it_actor(): string
{
    $v = $GLOBALS['it_viewer'] ?? null;
    return !$v || !empty($v['is_admin']) ? 'Administrator' : (string) ($v['client']['name'] ?? 'Staff');
}

function it_log(string $entity, ?int $id, string $summary): void
{
    it_exec('INSERT INTO it_activity (entity, entity_id, summary, actor) VALUES (?,?,?,?)', 'siss', [$entity, $id, mb_substr($summary, 0, 500), it_actor()]);
}

/* ================================================================ links */

function it_url(string $page, array $q = []): string
{
    if (!empty($GLOBALS['it_embed'])) {
        $q['embed'] = 1;
    }
    return it_cfg('script') . '?' . http_build_query(['p' => $page] + $q);
}

function it_public_base(): string
{
    if (PHP_SAPI === 'cli' && empty($_SERVER['HTTP_HOST'])) {
        return 'https://portal.invalid/';
    }
    return rtrim(fabo_portal_url(), '/') . '/';
}

function it_request_link(): string
{
    return it_public_base() . 'it-request.php?' . http_build_query(array_filter(['d' => it_desk() === 'it' ? null : it_desk(), 'o' => it_org() ?: null, 'k' => it_setting('request_token')]));
}

function it_ticket_link(array $t): string
{
    // Someone in the portal follows it in their helpdesk; anyone else on the public tracking page.
    if (!empty($t['requester_id']) && !empty($t['id'])) {
        return it_public_base() . 'login.php?next=' . rawurlencode(it_cfg('script') . '?p=myticket&id=' . (int) $t['id']);
    }
    return it_public_base() . 'it-request.php?' . http_build_query(array_filter(['d' => it_desk() === 'it' ? null : it_desk(), 'o' => it_org() ?: null, 't' => $t['token']]));
}

/** A notice on a person's portal bell, linking into the helpdesk. Never throws. */
function it_bell(?int $personId, string $title, string $body, string $page, int $ticketId): void
{
    if (!$personId) {
        return;
    }
    try {
        require_once __DIR__ . '/../notify.php';
        fabo_notify($personId, $title, $body, it_cfg('script') . '?' . http_build_query(['p' => $page, 'id' => $ticketId]));
    } catch (Throwable $e) {
        error_log('FABO IT bell: ' . $e->getMessage());
    }
}

/** The people who hear about a ticket on the team side: its assignee, else the team chosen in Settings. */
function it_team_people(array $t): array
{
    if (!empty($t['assignee_id'])) {
        return [(int) $t['assignee_id']];
    }
    return array_values(array_intersect(array_filter(array_map('intval', explode(',', it_setting('team_ids')))), array_keys(it_staff())));
}

/* ------------------------------------------------- words for the requester */

/** The status as the person who asked sees it. */
function it_status_plain(string $s): string
{
    return ['new' => 'Submitted', 'in_progress' => 'In progress', 'waiting' => 'Needs your reply', 'resolved' => 'Resolved — please confirm', 'closed' => 'Closed'][$s] ?? $s;
}

/** One line on what happens next, for the person who asked. */
function it_next_plain(array $t): string
{
    $who = $t['assignee_id'] ? it_person_name((int) $t['assignee_id']) : '';
    return match ($t['status']) {
        'new' => 'Waiting for the ' . it_cfg('team') . ' to pick it up.',
        'in_progress' => ($who !== '' ? $who : 'The ' . it_cfg('team')) . ' is working on it.',
        'waiting' => ($who !== '' ? $who : 'The ' . it_cfg('team')) . ' asked you something — please reply.',
        'resolved' => 'Marked as fixed. Please confirm, or reopen it if it is not.',
        default => 'Closed' . ($t['closed_at'] ? ' on ' . inc_fmt_datetime($t['closed_at']) : '') . '.',
    };
}

/** The tone of a status pill: what the person should feel about it at a glance. */
function it_status_tone(string $s): string
{
    return ['new' => 'blue', 'in_progress' => 'amber', 'waiting' => 'red', 'resolved' => 'green', 'closed' => 'grey'][$s] ?? 'grey';
}

/** How urgent, said plainly: [label, meaning]. */
function it_urgency_plain(): array
{
    return ['low' => ['Low', 'It can wait — no work is held up.'], 'medium' => ['Medium', 'It is slowing me down.'],
            'high' => ['High', 'Part of my work is blocked.'], 'urgent' => ['Urgent', 'My work has stopped completely.']];
}

/** The helpdesk's look, on the helpdesk pages only. */
function it_helpdesk_css(): string
{
    return '<link rel="stylesheet" href="' . e(fabo_asset(fabo_root_prefix() . 'assets/helpdesk.css')) . '">';
}

/* ================================================================ labels */

function it_statuses(): array
{
    return ['new' => 'New', 'in_progress' => 'In progress', 'waiting' => 'Waiting on requester', 'resolved' => 'Resolved', 'closed' => 'Closed'];
}

function it_priorities(): array
{
    return ['urgent' => 'Urgent', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low'];
}

function it_asset_statuses(): array
{
    return ['in_stock' => 'In stock', 'assigned' => 'In use', 'repair' => 'In repair', 'retired' => 'Retired', 'lost' => 'Lost'];
}

function it_renewal_kinds(): array
{
    return it_cfg('kinds');
}

function it_billing(): array
{
    return ['monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'yearly' => 'Yearly', 'one_time' => 'One-time'];
}

function it_priority_badge(string $p): string
{
    return inc_badge(it_priorities()[$p] ?? $p, ['urgent' => 'red', 'high' => 'amber', 'medium' => 'blue', 'low' => ''][$p] ?? '');
}

function it_status_badge(string $s): string
{
    return inc_badge(it_statuses()[$s] ?? $s, ['new' => 'blue', 'in_progress' => 'amber', 'waiting' => '', 'resolved' => 'green', 'closed' => ''][$s] ?? '');
}

function it_asset_badge(string $s): string
{
    return inc_badge(it_asset_statuses()[$s] ?? $s, ['in_stock' => 'blue', 'assigned' => 'green', 'repair' => 'amber', 'retired' => '', 'lost' => 'red'][$s] ?? '');
}

function it_money($v): string
{
    return $v === null || $v === '' ? '—' : '₹' . number_format((float) $v, 0);
}

function it_is_overdue(array $t): bool
{
    return $t['due_at'] && !in_array($t['status'], ['resolved', 'closed'], true) && strtotime((string) $t['due_at']) < time();
}

/* ================================================================ email */

/** Sends one IT email; never throws. Returns the delivery status. */
function it_mail(string $to, string $subject, string $text): string
{
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return 'skipped';
    }
    return inc_send_email($to, $subject, $text)[0];
}

/** The IT team's addresses (or the admin inbox when no team is chosen). */
function it_team_emails(): array
{
    $ids = array_filter(array_map('intval', explode(',', it_setting('team_ids'))));
    $out = [];
    foreach ($ids as $id) {
        $e = it_staff()[$id]['email'] ?? '';
        if ($e !== '') {
            $out[] = $e;
        }
    }
    // No team chosen: ours go to the admin inbox; a client's to the client's own head, never to us.
    if (!$out && it_org() > 0) {
        $head = (string) (fabo_one('SELECT email FROM clients WHERE id = ?', 'i', [it_org()])['email'] ?? '');
        if ($head !== '') {
            $out[] = $head;
        }
    } elseif (!$out && defined('ADMIN_INBOX_EMAIL') && ADMIN_INBOX_EMAIL) {
        $out[] = (string) ADMIN_INBOX_EMAIL;
    }
    return array_unique($out);
}

/* ============================================================== tickets */

function it_ticket(int $id): ?array
{
    return it_one('SELECT t.*, a.tag AS asset_tag, a.name AS asset_name FROM it_tickets t LEFT JOIN it_assets a ON a.id = t.asset_id WHERE t.id = ?', 'i', [$id]);
}

function it_ticket_by_token(string $token): ?array
{
    return preg_match('/^[a-f0-9]{32}$/', $token) ? it_one('SELECT * FROM it_tickets WHERE token = ?', 's', [$token]) : null;
}

function it_due_for(string $priority, ?int $from = null): string
{
    $h = max(1, (int) it_setting('sla_' . $priority));
    return date('Y-m-d H:i:s', ($from ?? time()) + $h * 3600);
}

/**
 * Opens a ticket, from the desk or from the request link. Validates, sets the
 * due time from the priority, numbers it, and emails the requester (with
 * their tracking link) and the IT team.
 */
function it_ticket_create(array $in, string $channel = 'desk'): array
{
    $title = inc_str($in['title'] ?? '', 200, true, 'Subject');
    $desc = inc_str($in['description'] ?? '', 8000, $channel === 'link', 'Description');
    $name = inc_str($in['requester_name'] ?? '', 160, true, 'Name');
    $email = trim((string) ($in['requester_email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('That email address does not look right.');
    }
    if ($channel === 'link' && $email === '') {
        throw new InvalidArgumentException('Your email address is needed so we can reply.');
    }
    $priority = isset(it_priorities()[$in['priority'] ?? '']) ? (string) $in['priority'] : 'medium';
    $category = (string) ($in['category'] ?? '');
    $category = isset(it_list('ticket_category')[$category]) ? $category : null;
    $assignee = $channel === 'desk' ? inc_int_or_null($in['assignee_id'] ?? null) : null;
    if ($assignee && !isset(it_team()[$assignee])) {
        $assignee = null;
    }
    $asset = it_asset_from_input($in);
    // From the helpdesk the requester is the person signed in, not a name typed in.
    $reqId = $channel === 'portal' ? ((int) ($in['_requester_id'] ?? 0) ?: null) : null;
    if ($reqId === null && $email !== '') {
        foreach (it_staff() as $sid => $p) {
            if (strcasecmp($p['email'], $email) === 0) {
                $reqId = $sid;
                break;
            }
        }
    }
    $id = it_exec('INSERT INTO it_tickets (token, title, description, category, priority, status, requester_name, requester_email, requester_id, assignee_id, asset_id, channel, due_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', 'ssssssssiiiss',
                    [bin2hex(random_bytes(16)), $title, $desc ?: null, $category, $priority, $assignee ? 'in_progress' : 'new', $name, $email ?: null,
                     $reqId, $assignee, $asset, $channel, it_due_for($priority)]);
    $ref = it_cfg('ticket_ref') . '-' . date('Y') . '-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
    it_exec('UPDATE it_tickets SET ref = ? WHERE id = ?', 'si', [$ref, $id]);
    it_note($id, 'system', 'Ticket opened ' . ($channel === 'link' ? 'by ' . $name . ' through the request link' : ($channel === 'portal' ? 'by ' . $name . ' from the helpdesk' : 'on the IT desk')) . '.');
    it_log('ticket', $id, $ref . ' opened: ' . $title);
    $t = it_ticket($id);
    if (it_setting('notify_requester') === '1' && $email !== '') {
        it_mail($email, 'We received your ' . it_cfg('short') . ' request ' . $ref . ': ' . $title,
            'Hello ' . $name . ",\n\nThank you — your " . it_cfg('short') . " request has been logged.\n\nReference: " . $ref . "\nSubject: " . $title
          . "\nPriority: " . it_priorities()[$priority] . "\nWe aim to resolve it by: " . date('d M Y, H:i', strtotime((string) $t['due_at']))
          . "\n\nFollow it, or add more details, here:\n" . it_ticket_link($t) . "\n\nRegards,\n" . it_cfg('support'));
    }
    if (it_setting('notify_team') === '1') {
        $to = $assignee ? [it_staff()[$assignee]['email'] ?? ''] : it_team_emails();
        foreach ($to as $addr) {
            it_mail($addr, ($assignee ? 'Assigned to you: ' : 'New ' . it_cfg('short') . ' ' . strtolower(it_cfg('ticket')) . ': ') . $ref . ' — ' . $title,
                'Requester: ' . $name . ($email ? ' <' . $email . '>' : '') . "\nPriority: " . it_priorities()[$priority] . "\nCategory: " . ($category ?: '—')
              . "\n\n" . ($desc ?: '(no description)') . "\n\nOpen " . it_cfg('name') . " → " . it_cfg('tickets') . ".");
        }
    }
    foreach (it_team_people($t) as $pid) {
        if ($pid !== (int) $reqId) {
            it_bell($pid, ($assignee ? 'Assigned to you: ' : 'New ' . it_cfg('short') . ' request: ') . $ref, $title . ' — from ' . $name . ' (' . it_priorities()[$priority] . ')', 'work', $id);
        }
    }
    return $t;
}

/** An asset picked by id, or typed by tag on the request form. */
function it_asset_from_input(array $in): ?int
{
    $id = inc_int_or_null($in['asset_id'] ?? null);
    if ($id && it_one('SELECT id FROM it_assets WHERE id = ?', 'i', [$id])) {
        return $id;
    }
    $tag = trim((string) ($in['asset_tag'] ?? ''));
    if ($tag !== '') {
        $a = it_one('SELECT id FROM it_assets WHERE tag = ?', 's', [$tag]);
        return $a ? (int) $a['id'] : null;
    }
    return null;
}

function it_note(int $ticketId, string $kind, string $body, bool $emailed = false, ?string $actor = null): void
{
    it_exec('INSERT INTO it_ticket_notes (ticket_id, kind, body, actor, emailed) VALUES (?,?,?,?,?)', 'isssi',
              [$ticketId, $kind, $body, $actor ?? it_actor(), $emailed ? 1 : 0]);
}

function it_notes(int $ticketId): array
{
    return it_all('SELECT * FROM it_ticket_notes WHERE ticket_id = ? ORDER BY created_at, id', 'i', [$ticketId]);
}

/** Saves the desk-side fields of a ticket; status changes go through it_ticket_status(). */
function it_ticket_update(int $id, array $in): void
{
    $t = it_ticket($id) ?? throw new InvalidArgumentException('That ticket no longer exists.');
    $title = inc_str($in['title'] ?? $t['title'], 200, true, 'Subject');
    $priority = isset(it_priorities()[$in['priority'] ?? '']) ? (string) $in['priority'] : $t['priority'];
    $category = (string) ($in['category'] ?? '');
    $category = isset(it_list('ticket_category', false)[$category]) ? $category : null;
    $assignee = inc_int_or_null($in['assignee_id'] ?? null);
    if ($assignee && !isset(it_team()[$assignee]) && (int) $t['assignee_id'] !== $assignee) {
        $assignee = null;
    }
    $asset = it_asset_from_input($in);
    $due = $t['due_at'];
    if ($priority !== $t['priority']) {
        $due = it_due_for($priority, strtotime((string) $t['created_at']));
    }
    if (!empty($in['due_at']) && ($d = strtotime((string) $in['due_at']))) {
        $due = date('Y-m-d H:i:s', $d);
    }
    it_exec('UPDATE it_tickets SET title = ?, priority = ?, category = ?, assignee_id = ?, asset_id = ?, due_at = ? WHERE id = ?', 'sssiisi',
              [$title, $priority, $category, $assignee, $asset, $due, $id]);
    $changes = [];
    if ($priority !== $t['priority']) {
        $changes[] = 'priority ' . it_priorities()[$t['priority']] . ' → ' . it_priorities()[$priority];
    }
    if ((int) $assignee !== (int) $t['assignee_id']) {
        $changes[] = 'assigned to ' . ($assignee ? it_person_name($assignee) : 'nobody');
        if ($assignee && it_setting('notify_team') === '1') {
            it_mail(it_staff()[$assignee]['email'] ?? '', 'Assigned to you: ' . $t['ref'] . ' — ' . $title,
                'Requester: ' . $t['requester_name'] . "\nPriority: " . it_priorities()[$priority] . "\n\n" . ($t['description'] ?: '(no description)')
              . "\n\nOpen " . it_cfg('name') . " → " . it_cfg('tickets') . ".");
        }
        if ($assignee) {
            it_bell($assignee, 'Assigned to you: ' . $t['ref'], $title, 'work', $id);
        }
        if ($assignee && $t['status'] === 'new') {
            it_ticket_status($id, 'in_progress', false);
        }
    }
    if ($category !== $t['category']) {
        $changes[] = 'category ' . ($category ?: 'none');
    }
    if ((int) $asset !== (int) $t['asset_id']) {
        $changes[] = $asset ? 'linked to asset ' . (it_one('SELECT tag FROM it_assets WHERE id = ?', 'i', [$asset])['tag'] ?? '') : 'asset unlinked';
    }
    if ($changes) {
        it_note($id, 'system', ucfirst(implode('; ', $changes)) . '.');
    }
}

/** Moves a ticket to a status; resolving and closing email the requester. */
function it_ticket_status(int $id, string $status, bool $notify = true): string
{
    $t = it_ticket($id) ?? throw new InvalidArgumentException('That ticket no longer exists.');
    if (!isset(it_statuses()[$status])) {
        throw new InvalidArgumentException('Unknown status.');
    }
    if ($t['status'] === $status) {
        return 'It is already ' . it_statuses()[$status] . '.';
    }
    it_exec('UPDATE it_tickets SET status = ?, resolved_at = ' . ($status === 'resolved' ? 'NOW()' : (in_array($status, ['new', 'in_progress', 'waiting'], true) ? 'NULL' : 'resolved_at'))
            . ', closed_at = ' . ($status === 'closed' ? 'NOW()' : 'NULL') . ' WHERE id = ?', 'si', [$status, $id]);
    it_note($id, 'system', 'Status: ' . it_statuses()[$t['status']] . ' → ' . it_statuses()[$status] . '.');
    it_log('ticket', $id, $t['ref'] . ' ' . it_statuses()[$status]);
    if ($notify && it_setting('notify_requester') === '1' && $t['requester_email'] && in_array($status, ['resolved', 'closed'], true)) {
        it_mail((string) $t['requester_email'], ($status === 'resolved' ? 'Resolved: ' : 'Closed: ') . $t['ref'] . ' — ' . $t['title'],
            'Hello ' . $t['requester_name'] . ",\n\nYour " . it_cfg('short') . " request " . $t['ref'] . ' has been ' . ($status === 'resolved' ? 'resolved' : 'closed') . '.'
          . ($status === 'resolved' ? "\n\nIf it is not fixed, reply through the link below within " . (int) it_setting('auto_close_days') . ' days and we will pick it up again; otherwise it closes on its own.' : '')
          . "\n\n" . it_ticket_link($t) . "\n\nRegards,\n" . it_cfg('support'));
    }
    if ($notify && in_array($status, ['resolved', 'waiting'], true)) {
        it_bell($t['requester_id'] ? (int) $t['requester_id'] : null, ($status === 'resolved' ? 'Resolved — please confirm: ' : 'Your reply is needed: ') . $t['ref'], $t['title'], 'myticket', $id);
    }
    return 'Ticket ' . it_statuses()[$status] . '.';
}

/** A message on the ticket: a reply (emailed to the requester) or an internal note. */
function it_ticket_reply(int $id, string $body, bool $internal, string $then = ''): string
{
    $t = it_ticket($id) ?? throw new InvalidArgumentException('That ticket no longer exists.');
    $body = inc_str($body, 8000, true, 'Message');
    $emailed = false;
    $then = $then !== '' && isset(it_statuses()[$then]) ? $then : '';
    if (!$internal && $t['requester_email']) {
        // One message that says what happened: a reply, a question, or "we think it is fixed".
        $head = ['resolved' => 'Resolved: ', 'waiting' => 'Question about '][$then] ?? 'Re: ';
        $tail = ['resolved' => "\n\nWe have marked this request as resolved. If it is not fixed, reopen it from the link below within " . (int) it_setting('auto_close_days') . ' days; otherwise it closes on its own.',
                 'waiting' => "\n\nPlease reply so we can carry on."][$then] ?? '';
        $emailed = it_mail((string) $t['requester_email'], $head . $t['ref'] . ' — ' . $t['title'],
            'Hello ' . $t['requester_name'] . ",\n\n" . $body . $tail . "\n\nOpen your request:\n" . it_ticket_link($t) . "\n\nRegards,\n" . it_cfg('support')) !== 'skipped';
    }
    if (!$internal && $then === '') {
        it_bell($t['requester_id'] ? (int) $t['requester_id'] : null, 'New reply on ' . $t['ref'], mb_substr($body, 0, 160), 'myticket', $id);
    }
    it_note($id, $internal ? 'internal' : 'reply', $body, $emailed);
    if (!$internal && !$t['first_reply_at']) {
        it_exec('UPDATE it_tickets SET first_reply_at = NOW() WHERE id = ?', 'i', [$id]);
    }
    if ($then !== '') {
        // The email above already said so; the status change only adds the bell.
        it_ticket_status($id, $then, $internal);
        if (!$internal) {
            it_bell($t['requester_id'] ? (int) $t['requester_id'] : null, ($then === 'resolved' ? 'Resolved — please confirm: ' : ($then === 'waiting' ? 'Your reply is needed: ' : 'Update on ')) . $t['ref'], mb_substr($body, 0, 160), 'myticket', $id);
        }
    } elseif (!$internal && $t['status'] === 'new') {
        it_ticket_status($id, 'in_progress', false);
    }
    return $internal ? 'Internal note added.' : ($emailed ? 'Reply emailed to ' . $t['requester_email'] . '.' : 'Reply saved (no email address to send it to).');
}

/** The requester writes back through their link: reopens a resolved ticket, wakes a waiting one, tells the assignee. */
function it_requester_reply(array $t, string $body): void
{
    $body = inc_str($body, 8000, true, 'Message');
    it_note((int) $t['id'], 'requester', $body, false, $t['requester_name'] ?: 'Requester');
    if (in_array($t['status'], ['waiting', 'resolved'], true)) {
        it_ticket_status((int) $t['id'], 'in_progress', false);
    }
    $to = $t['assignee_id'] ? [it_staff()[(int) $t['assignee_id']]['email'] ?? ''] : it_team_emails();
    foreach ($to as $addr) {
        it_mail($addr, 'Requester replied: ' . $t['ref'] . ' — ' . $t['title'], $t['requester_name'] . " wrote:\n\n" . $body . "\n\nOpen " . it_cfg('name') . " → " . it_cfg('tickets') . ".");
    }
    foreach (it_team_people($t) as $pid) {
        it_bell($pid, $t['ref'] . ': ' . ($t['requester_name'] ?: 'The requester') . ' replied', mb_substr($body, 0, 160), 'work', (int) $t['id']);
    }
}

/** Resolved tickets with no reply for N days close themselves. Returns how many. */
function it_auto_close(): int
{
    $days = max(1, (int) it_setting('auto_close_days'));
    $n = 0;
    foreach (it_all("SELECT id FROM it_tickets WHERE status = 'resolved' AND resolved_at < NOW() - INTERVAL ? DAY", 'i', [$days]) as $r) {
        it_ticket_status((int) $r['id'], 'closed', false);
        $n++;
    }
    return $n;
}

/** Tickets matching the desk's filters. */
function it_tickets(array $f, int $limit = 1000): array
{
    $w = ['1=1'];
    $types = '';
    $p = [];
    if (($f['q'] ?? '') !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $f['q']) . '%';
        $w[] = '(t.ref LIKE ? OR t.title LIKE ? OR t.requester_name LIKE ? OR t.requester_email LIKE ?)';
        $types .= 'ssss';
        array_push($p, $like, $like, $like, $like);
    }
    foreach (['priority' => 't.priority', 'category' => 't.category', 'status' => 't.status'] as $k => $col) {
        if (($f[$k] ?? '') !== '') {
            $w[] = $col . ' = ?';
            $types .= 's';
            $p[] = $f[$k];
        }
    }
    if (($f['assignee'] ?? '') === 'none') {
        $w[] = 't.assignee_id IS NULL';
    } elseif ((int) ($f['assignee'] ?? 0) > 0) {
        $w[] = 't.assignee_id = ?';
        $types .= 'i';
        $p[] = (int) $f['assignee'];
    }
    if (!empty($f['overdue'])) {
        $w[] = "t.status NOT IN ('resolved','closed') AND t.due_at < NOW()";
    }
    if (empty($f['status']) && empty($f['with_closed'])) {
        $w[] = "(t.status <> 'closed' OR t.closed_at > NOW() - INTERVAL 14 DAY)";
    }
    return it_all('SELECT t.*, a.tag AS asset_tag FROM it_tickets t LEFT JOIN it_assets a ON a.id = t.asset_id WHERE ' . implode(' AND ', $w)
                  . " ORDER BY FIELD(t.priority,'urgent','high','medium','low'), t.due_at IS NULL, t.due_at, t.id DESC LIMIT " . (int) $limit, $types, $p);
}

/* =============================================================== assets */

function it_asset(int $id): ?array
{
    return it_one('SELECT a.*, v.name AS vendor_name FROM it_assets a LEFT JOIN it_vendors v ON v.id = a.vendor_id WHERE a.id = ?', 'i', [$id]);
}

function it_asset_save(?int $id, array $in): int
{
    $name = inc_str($in['name'] ?? '', 160, true, 'Name');
    $tag = trim((string) ($in['tag'] ?? ''));
    $vendor = inc_int_or_null($in['vendor_id'] ?? null);
    $row = [mb_substr($tag, 0, 40), $name, inc_str($in['type'] ?? '', 120) ?: null, inc_str($in['brand'] ?? '', 80) ?: null, inc_str($in['model'] ?? '', 120) ?: null,
            inc_str($in['serial'] ?? '', 120) ?: null, inc_str($in['location'] ?? '', 120) ?: null, inc_date($in['purchase_date'] ?? null),
            ($in['cost'] ?? '') !== '' ? (float) $in['cost'] : null, $vendor && it_one('SELECT id FROM it_vendors WHERE id = ?', 'i', [$vendor]) ? $vendor : null,
            inc_date($in['warranty_end'] ?? null), inc_str($in['notes'] ?? '', 4000) ?: null];
    try {
        if ($id) {
            if (!it_asset($id)) {
                throw new InvalidArgumentException('That asset no longer exists.');
            }
            if ($row[0] === '') {
                $row[0] = (string) it_asset($id)['tag'];
            }
            it_exec('UPDATE it_assets SET tag = ?, name = ?, type = ?, brand = ?, model = ?, serial = ?, location = ?, purchase_date = ?, cost = ?, vendor_id = ?, warranty_end = ?, notes = ? WHERE id = ?',
                      'ssssssssdissi', array_merge($row, [$id]));
            it_log('asset', $id, 'Details updated.');
            return $id;
        }
        $row[0] = $row[0] !== '' ? $row[0] : 'TMP-' . bin2hex(random_bytes(6));
        $id = it_exec('INSERT INTO it_assets (tag, name, type, brand, model, serial, location, purchase_date, cost, vendor_id, warranty_end, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                        'ssssssssdiss', $row);
        if (str_starts_with($row[0], 'TMP-')) {
            it_exec('UPDATE it_assets SET tag = ? WHERE id = ?', 'si', [it_cfg('asset_tag') . '-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT), $id]);
        }
        it_log('asset', $id, 'Added to the inventory.');
        return $id;
    } catch (mysqli_sql_exception $e) {
        if (str_contains($e->getMessage(), 'Duplicate')) {
            throw new InvalidArgumentException('Another asset already has the tag "' . $row[0] . '".');
        }
        throw $e;
    }
}

/**
 * One step in an asset's life: assign (to a team member, a named person or a
 * place), return to stock, send for repair, retire, mark lost.
 */
function it_asset_action(int $id, string $action, array $in): string
{
    $a = it_asset($id) ?? throw new InvalidArgumentException('That asset no longer exists.');
    $note = inc_str($in['note'] ?? '', 500);
    switch ($action) {
        case 'assign':
            $pid = inc_int_or_null($in['person_id'] ?? null);
            $pid = $pid && isset(it_staff()[$pid]) ? $pid : null;
            $who = $pid ? it_staff()[$pid]['name'] : inc_str($in['person_name'] ?? '', 160);
            $loc = inc_str($in['location'] ?? '', 120) ?: $a['location'];
            if ($who === '' && !$loc) {
                throw new InvalidArgumentException('Choose who (or where) the asset goes to.');
            }
            it_exec("UPDATE it_assets SET status = 'assigned', assigned_name = ?, assigned_person_id = ?, location = ? WHERE id = ?", 'sisi', [$who ?: null, $pid, $loc, $id]);
            $msg = 'Assigned to ' . ($who !== '' ? $who : $loc) . ($who !== '' && $loc ? ' (' . $loc . ')' : '');
            break;
        case 'return':
            it_exec("UPDATE it_assets SET status = 'in_stock', assigned_name = NULL, assigned_person_id = NULL, location = COALESCE(?, location) WHERE id = ?", 'si',
                      [inc_str($in['location'] ?? '', 120) ?: null, $id]);
            $msg = 'Returned to stock' . ($a['assigned_name'] ? ' from ' . $a['assigned_name'] : '');
            break;
        case 'repair':
            it_exec("UPDATE it_assets SET status = 'repair' WHERE id = ?", 'i', [$id]);
            $msg = 'Sent for repair';
            break;
        case 'retire':
        case 'lost':
            it_exec('UPDATE it_assets SET status = ?, assigned_name = NULL, assigned_person_id = NULL WHERE id = ?', 'si', [$action === 'retire' ? 'retired' : 'lost', $id]);
            $msg = $action === 'retire' ? 'Retired' : 'Marked lost';
            break;
        default:
            throw new InvalidArgumentException('Unknown action.');
    }
    it_log('asset', $id, $msg . ($note !== '' ? ' — ' . $note : '') . '.');
    return $msg . '.';
}

function it_assets(array $f): array
{
    $w = ['1=1'];
    $types = '';
    $p = [];
    if (($f['q'] ?? '') !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $f['q']) . '%';
        $w[] = '(a.tag LIKE ? OR a.name LIKE ? OR a.serial LIKE ? OR a.assigned_name LIKE ? OR a.model LIKE ?)';
        $types .= 'sssss';
        array_push($p, $like, $like, $like, $like, $like);
    }
    foreach (['type' => 'a.type', 'status' => 'a.status', 'location' => 'a.location'] as $k => $col) {
        if (($f[$k] ?? '') !== '') {
            $w[] = $col . ' = ?';
            $types .= 's';
            $p[] = $f[$k];
        }
    }
    if (!empty($f['warranty'])) {
        $w[] = 'a.warranty_end IS NOT NULL AND a.warranty_end <= CURDATE() + INTERVAL ' . (int) it_setting('remind_days') . " DAY AND a.status NOT IN ('retired','lost')";
    }
    return it_all('SELECT a.*, (SELECT COUNT(*) FROM it_tickets t WHERE t.asset_id = a.id AND t.status NOT IN (\'resolved\',\'closed\')) AS open_tickets
                     FROM it_assets a WHERE ' . implode(' AND ', $w) . ' ORDER BY a.status = \'retired\', a.tag LIMIT 2000', $types, $p);
}

/* ============================================================= renewals */

function it_renewal(int $id): ?array
{
    return it_one('SELECT r.*, v.name AS vendor_name FROM it_renewals r LEFT JOIN it_vendors v ON v.id = r.vendor_id WHERE r.id = ?', 'i', [$id]);
}

function it_renewal_save(?int $id, array $in): int
{
    $name = inc_str($in['name'] ?? '', 160, true, 'Name');
    $kind = isset(it_renewal_kinds()[$in['kind'] ?? '']) ? (string) $in['kind'] : 'other';
    $kind = isset(it_renewal_kinds()[$kind]) ? $kind : (string) array_key_last(it_renewal_kinds());
    $billing = isset(it_billing()[$in['billing'] ?? '']) ? (string) $in['billing'] : 'yearly';
    $vendor = inc_int_or_null($in['vendor_id'] ?? null);
    $row = [$name, $kind, $vendor && it_one('SELECT id FROM it_vendors WHERE id = ?', 'i', [$vendor]) ? $vendor : null,
            ($in['cost'] ?? '') !== '' ? (float) $in['cost'] : null, $billing, inc_date($in['renews_on'] ?? null),
            ($in['seats'] ?? '') !== '' ? (int) $in['seats'] : null, ($in['seats_used'] ?? '') !== '' ? (int) $in['seats_used'] : null,
            inc_str($in['owner'] ?? '', 160) ?: null, !empty($in['auto_renew']) ? 1 : 0, ($in['status'] ?? 'active') === 'cancelled' ? 'cancelled' : 'active',
            inc_str($in['notes'] ?? '', 4000) ?: null];
    if ($id) {
        if (!it_renewal($id)) {
            throw new InvalidArgumentException('That renewal no longer exists.');
        }
        it_exec('UPDATE it_renewals SET name = ?, kind = ?, vendor_id = ?, cost = ?, billing = ?, renews_on = ?, seats = ?, seats_used = ?, owner = ?, auto_renew = ?, status = ?, notes = ? WHERE id = ?',
                  'ssidssiisissi', array_merge($row, [$id]));
        it_log('renewal', $id, 'Details updated.');
        return $id;
    }
    $id = it_exec('INSERT INTO it_renewals (name, kind, vendor_id, cost, billing, renews_on, seats, seats_used, owner, auto_renew, status, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                    'ssidssiisiss', $row);
    it_log('renewal', $id, 'Added.');
    return $id;
}

/** "Renewed": the next renewal date moves on by one billing period (one-time items are marked done). */
function it_renewal_renewed(int $id, ?float $paid): string
{
    $r = it_renewal($id) ?? throw new InvalidArgumentException('That renewal no longer exists.');
    $from = $r['renews_on'] ?: date('Y-m-d');
    $next = ['monthly' => '+1 month', 'quarterly' => '+3 months', 'yearly' => '+1 year'][$r['billing']] ?? null;
    $nextDate = $next ? date('Y-m-d', strtotime($from . ' ' . $next)) : null;
    it_exec('UPDATE it_renewals SET renews_on = ?, last_renewed_on = CURDATE(), cost = COALESCE(?, cost)' . ($next ? '' : ", status = 'cancelled'") . ' WHERE id = ?', 'sdi',
              [$nextDate, $paid, $id]);
    $nextWord = it_desk() === 'fa' ? 'next due' : 'next renewal';
    it_log('renewal', $id, it_cfg('renewed') . ($paid !== null ? ' for ' . it_money($paid) : '') . ($nextDate ? '; ' . $nextWord . ' ' . date('d M Y', strtotime($nextDate)) : '') . '.');
    return 'Marked ' . strtolower(it_cfg('renewed')) . ($nextDate ? ' — ' . $nextWord . ' ' . date('d M Y', strtotime($nextDate)) : '') . '.';
}

/** What a renewal costs per month, for the spend figures. */
function it_monthly_cost(array $r): float
{
    $c = (float) $r['cost'];
    return ['monthly' => $c, 'quarterly' => $c / 3, 'yearly' => $c / 12][$r['billing']] ?? 0.0;
}

/* ============================================================== vendors */

function it_vendors(bool $activeOnly = false): array
{
    return array_column(it_all('SELECT id, name FROM it_vendors' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY name'), 'name', 'id');
}

function it_vendor_save(?int $id, array $in): int
{
    $row = [inc_str($in['name'] ?? '', 160, true, 'Name'), inc_str($in['contact'] ?? '', 160) ?: null, inc_str($in['email'] ?? '', 190) ?: null,
            inc_str($in['phone'] ?? '', 40) ?: null, inc_str($in['services'] ?? '', 255) ?: null, inc_str($in['notes'] ?? '', 4000) ?: null,
            !empty($in['active']) || !$id ? 1 : 0];
    try {
        if ($id) {
            it_exec('UPDATE it_vendors SET name = ?, contact = ?, email = ?, phone = ?, services = ?, notes = ?, active = ? WHERE id = ?', 'ssssssii', array_merge($row, [$id]));
            return $id;
        }
        return it_exec('INSERT INTO it_vendors (name, contact, email, phone, services, notes, active) VALUES (?,?,?,?,?,?,?)', 'ssssssi', $row);
    } catch (mysqli_sql_exception $e) {
        if (str_contains($e->getMessage(), 'Duplicate')) {
            throw new InvalidArgumentException('A vendor called "' . $row[0] . '" already exists.');
        }
        throw $e;
    }
}

/* ======================================================= reminders (cron) */

/**
 * Once a day: close resolved tickets nobody answered, and send the IT team
 * one digest of renewals and warranties coming up. Returns a short summary.
 */
function it_daily(): string
{
    it_install();
    $closed = it_auto_close();
    if (it_setting('last_digest') === date('Y-m-d')) {
        return "closed $closed";
    }
    it_setting_set('last_digest', date('Y-m-d'));
    $days = (int) it_setting('remind_days');
    $ren = it_all("SELECT name, renews_on, cost FROM it_renewals WHERE status = 'active' AND renews_on IS NOT NULL AND renews_on <= CURDATE() + INTERVAL ? DAY ORDER BY renews_on", 'i', [$days]);
    $war = it_all("SELECT tag, name, warranty_end FROM it_assets WHERE status NOT IN ('retired','lost') AND warranty_end IS NOT NULL AND warranty_end BETWEEN CURDATE() AND CURDATE() + INTERVAL ? DAY ORDER BY warranty_end", 'i', [$days]);
    $over = (int) (it_one("SELECT COUNT(*) n FROM it_tickets WHERE status NOT IN ('resolved','closed') AND due_at < NOW()")['n'] ?? 0);
    if (!$ren && !$war && !$over) {
        return "closed $closed, nothing to remind";
    }
    $text = it_cfg('short') . " reminders for " . date('d M Y') . "\n";
    if ($over) {
        $text .= "\nOverdue " . strtolower(it_cfg('tickets')) . ": $over\n";
    }
    if ($ren) {
        $text .= "\n" . it_cfg('renewals') . " due in the next $days days:\n";
        foreach ($ren as $r) {
            $text .= '  ' . date('d M Y', strtotime($r['renews_on'])) . ' — ' . $r['name'] . ' (' . it_money($r['cost']) . ")\n";
        }
    }
    if ($war) {
        $text .= "\nWarranties ending in the next $days days:\n";
        foreach ($war as $a) {
            $text .= '  ' . date('d M Y', strtotime($a['warranty_end'])) . ' — ' . $a['tag'] . ' ' . $a['name'] . "\n";
        }
    }
    foreach (it_team_emails() as $to) {
        it_mail($to, it_cfg('short') . ' reminders: ' . count($ren) . ' ' . it_cfg('renewal') . '(s), ' . count($war) . ' warranty(ies), ' . $over . ' overdue ' . strtolower(it_cfg('ticket')) . '(s)', $text . "\nOpen " . it_cfg('name') . ".");
    }
    return "closed $closed, digest sent";
}

/* ============================================================== desk app */

function it_pages(): array
{
    return ['dashboard' => 'Dashboard', 'tickets' => it_cfg('tickets'), 'ticket' => it_cfg('ticket'), 'assets' => it_cfg('assets'), 'asset' => it_cfg('asset')]
         + it_cfg('extra') + ['renewals' => it_cfg('renewals'), 'vendors' => 'Vendors', 'settings' => 'Settings']
         // The helpdesk, for everyone in the organisation (see it_self_org()).
         + ['my' => 'My requests', 'myticket' => 'My request', 'queue' => 'My queue', 'work' => 'Request'];
}

/** Pages of the helpdesk: anyone with helpdesk access may post on these (each checks its own rights). */
function it_helpdesk_pages(): array
{
    return ['my', 'myticket', 'queue', 'work'];
}

/** The signed-in person using the desk (their clients.id), or 0 for the administrator. */
function it_me(): int
{
    $v = $GLOBALS['it_viewer'] ?? null;
    return !$v || !empty($v['is_admin']) ? 0 : (int) ($v['client']['id'] ?? 0);
}

/** May the person work this ticket from the helpdesk side: on the team, or it is assigned to them. */
function it_agent_may(array $t): bool
{
    $me = it_me();
    if ($me <= 0) {
        return false;
    }
    return (int) $t['assignee_id'] === $me || in_array($me, array_filter(array_map('intval', explode(',', it_setting('team_ids')))), true);
}

function it_app_main(array $viewer, string $desk = 'it'): void
{
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store, private');
    $grant = it_grant($viewer, $desk);
    $selfOrg = it_self_org($viewer, $desk);
    if ($grant === 'none' && $selfOrg === null) {
        fabo_deny('You do not have access to ' . it_desks()[$desk] . '.', 403);
    }
    it_use($desk, !empty($viewer['is_admin']) ? 0 : ($grant !== 'none' ? inc_org_of($viewer) : $selfOrg));
    $GLOBALS['it_grant'] = $grant;
    $GLOBALS['it_desk_grant'] = $grant;          // the grant itself, before any per-ticket lift below
    it_install();
    it_auto_close();
    $GLOBALS['it_viewer'] = $viewer;
    $GLOBALS['it_embed'] = !empty($_GET['embed']);
    $GLOBALS['it_agent'] = it_is_agent(it_me());
    $page = (string) ($_GET['p'] ?? ($grant === 'none' ? 'my' : 'dashboard'));
    if (!isset(it_pages()[$page])) {
        $page = $grant === 'none' ? 'my' : 'dashboard';
    }
    if ($grant === 'none' && !in_array($page, it_helpdesk_pages(), true)) {
        /* Without the desk: the helpdesk pages, and the ticket page for a
           ticket this person may work (it_agent_may). Anything else is the
           helpdesk's front page. */
        $t = $page === 'ticket' ? it_ticket((int) ($_GET['id'] ?? 0)) : null;
        if (!$t || !it_agent_may($t)) {
            header('Location: ' . it_url('my'));
            exit;
        }
    }
    if ($page === 'ticket' && $grant !== 'edit') {
        // The team (or the assignee) works a ticket from the helpdesk with the desk's own ticket page.
        $t = it_ticket((int) ($_GET['id'] ?? 0));
        if ($t && it_agent_may($t)) {
            $GLOBALS['it_grant'] = 'edit';
        }
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        fabo_csrf_check();
        if (!it_can_edit() && !in_array($page, it_helpdesk_pages(), true)) {
            fabo_deny('View only: you can read ' . it_cfg('name') . ' but not change it.', 403);
        }
    }
    $GLOBALS['it_page'] = $page;
    require __DIR__ . '/pages/' . $page . '.php';
}

function it_layout_start(string $title, string $sub = '', string $actions = '', ?array $flash = null, array $crumbs = []): void
{
    $page = (string) ($GLOBALS['it_page'] ?? 'dashboard');
    if (!empty($GLOBALS['it_embed'])) {
        $pre = fabo_root_prefix();
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($title) . '</title>'
           . '<link rel="stylesheet" href="' . e(fabo_asset($pre . 'assets/app.css')) . '"><link rel="stylesheet" href="' . e(fabo_asset($pre . 'assets/inc.css')) . '"></head><body class="inc-embed">';
    } else {
        fabo_head($GLOBALS['it_viewer'], $title === it_cfg('name') ? $title : $title . ' · ' . it_cfg('name'), it_desk() . '-' . $page);
    }
    // "View only" hides every form; the helpdesk pages are where anyone raises and answers requests, so never on them.
    echo '<main class="wrap-page inc it' . (it_can_edit() || in_array($page, it_helpdesk_pages(), true) ? '' : ' it-ro') . '" id="main">';
    if (in_array($page, it_helpdesk_pages(), true)) {
        // The helpdesk draws its own heading (lib/it/helpdesk.php); only the messages come from here.
        echo it_helpdesk_css() . '<div class="hd">' . inc_flash_html(inc_take_flash()) . inc_flash_html($flash);
        return;
    }
    if ($crumbs) {
        $trail = '<a href="' . e(it_url('dashboard')) . '">' . e(it_cfg('name')) . '</a>';
        foreach ($crumbs as $label => $href) {
            $trail .= ' &rsaquo; ' . ($href ? '<a href="' . e($href) . '">' . e($label) . '</a>' : e($label));
        }
        echo '<nav class="inc-crumbs" aria-label="Breadcrumb">' . $trail . '</nav>';
    }
    echo '<div class="inc-head"><div class="pagehead"><h1>' . e($title) . '</h1>' . ($sub !== '' ? '<div class="sub">' . $sub . '</div>' : '')
       . '</div><div class="acts">' . $actions . '</div></div>';
    if (!it_can_edit() && !in_array($page, it_helpdesk_pages(), true)) {
        echo '<div class="inc-note"><strong>View only.</strong> You can read and filter everything here; changes are made by the owner.</div>';
    }
    echo inc_flash_html(inc_take_flash());
    echo inc_flash_html($flash);
}

function it_layout_end(): void
{
    echo (in_array((string) ($GLOBALS['it_page'] ?? ''), it_helpdesk_pages(), true) ? '</div>' : '')
       . '</main><script src="' . e(fabo_asset(fabo_root_prefix() . 'assets/inc.js')) . '" defer></script>';
    fabo_foot();
}

/** Board | List switch for the IT pages. */
function it_view_switch(string $page, string $view, array $q): string
{
    $mk = static fn($v, $label) => '<a class="' . ($view === $v ? 'on' : '') . '" href="' . e(it_url($page, ['view' => $v] + $q)) . '"' . ($view === $v ? ' aria-current="page"' : '') . '>' . $label . '</a>';
    return '<nav class="inc-seg" aria-label="View">' . $mk('board', 'Board') . $mk('list', 'List') . '</nav>';
}

/** A compact filter bar (search + a few selects), in the same style as Applications. */
function it_filter_bar(string $page, array $f, array $selects, array $keep = []): string
{
    $h = '<form method="get" class="inc-filters" role="search"><input type="hidden" name="p" value="' . e($page) . '">';
    foreach ($keep as $k => $v) {
        $h .= '<input type="hidden" name="' . e($k) . '" value="' . e((string) $v) . '">';
    }
    $n = 0;
    $grid = '';
    foreach ($selects as $k => [$label, $opts]) {
        $n += ($f[$k] ?? '') !== '' ? 1 : 0;
        $grid .= '<label><span>' . e($label) . '</span>' . inc_select($k, $opts, $f[$k] ?? '', 'Any') . '</label>';
    }
    $h .= '<div class="inc-fbar"><input type="search" name="q" value="' . e($f['q'] ?? '') . '" placeholder="Search" aria-label="Search"><button class="btn btn-primary">Search</button>'
        . '<details class="inc-fmore"><summary class="btn">Filters' . ($n ? ' <span class="inc-fcount">' . $n . '</span>' : '') . '</summary><div class="inc-fpanel"><div class="inc-fgrid">' . $grid
        . '</div><div class="inc-factions"><a class="btn" href="' . e(it_url($page, $keep)) . '">Clear all</a><button class="btn btn-primary">Apply filters</button></div></div></details></div>';
    $chips = '';
    foreach ($selects as $k => [$label, $opts]) {
        if (($f[$k] ?? '') !== '') {
            $chips .= '<a class="inc-chip" href="' . e(it_url($page, array_diff_key(array_filter($f, static fn($v) => $v !== '' && $v !== null), [$k => 1]) + $keep)) . '">'
                    . e($label . ': ' . ($opts[$f[$k]] ?? $f[$k])) . ' <span aria-hidden="true">×</span></a>';
        }
    }
    return $h . ($chips ? '<div class="inc-chips">' . $chips . '</div>' : '') . '</form>';
}

/** cron.php: the daily job (auto-close, reminder digest) for each desk of each organisation that holds it. */
function it_cron_all(): void
{
    foreach (array_keys(it_desks()) as $desk) {
        foreach (array_merge([0], it_orgs_installed($desk)) as $org) {
            if (!it_org_enabled($desk, $org)) {
                continue;
            }
            try {
                it_use($desk, $org);
                it_daily();
            } catch (Throwable $e) {
                error_log('FABO cron ' . $desk . ' (org ' . $org . '): ' . $e->getMessage());
            }
        }
    }
}

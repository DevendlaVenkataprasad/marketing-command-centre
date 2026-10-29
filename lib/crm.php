<?php
/**
 * CRM — boards of records that move through stages.
 *
 * A CRM is three things an administrator designs and one thing everybody
 * fills:
 *
 *   Stages  — the columns a record moves across (New, Screening, Offer…).
 *   Fields  — what a record holds, designed in the Field Studio, one of the
 *             types in fabo_crm_types().
 *   Imports — a CSV or Excel file of applications, its columns mapped onto
 *             those fields, brought in a whole file at a time.
 *   Records — the applications, leads or enquiries themselves.
 *
 * A record's values live in one JSON document keyed by field key, so adding
 * a field is a row in crm_fields, never an ALTER TABLE, and a field can be
 * renamed freely: the key it is stored under never changes.
 *
 * WHO OWNS WHAT. Every board carries the organisation it belongs to in
 * client_id: 0 is ours, anything else is a client organisation (the same
 * number fabo_customiser() returns). The administrator sees every board; a
 * client sees their own organisation's and nobody else's. CRM is granted
 * like Finance — a module in "Sheet and module access" — so View only lets
 * them read, and Can edit lets them work their records.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lists.php';
require_once __DIR__ . '/auth.php';

const FABO_CRM_IMPORT_MAX_ROWS = 50000;

/* ============================================================== storage */

function fabo_crm_install(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $db = fabo_db();
    $tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    $db->query("CREATE TABLE IF NOT EXISTS `crm_boards` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `name` VARCHAR(120) NOT NULL,
  `record_label` VARCHAR(60) NOT NULL DEFAULT 'Record',
  `description` VARCHAR(255) NOT NULL DEFAULT '',
  `title_field` VARCHAR(48) NULL,
  `owner_person` INT UNSIGNED NULL,
  `client_design` TINYINT(1) NOT NULL DEFAULT 0,
  `archived` TINYINT(1) NOT NULL DEFAULT 0,
  `created_by` VARCHAR(160) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_client` (`client_id`)
)" . $tail);

    $db->query("CREATE TABLE IF NOT EXISTS `crm_stages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `crm_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(80) NOT NULL,
  `tone` VARCHAR(12) NOT NULL DEFAULT 'info',
  `kind` ENUM('open','won','lost') NOT NULL DEFAULT 'open',
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_crm` (`crm_id`,`sort_order`)
)" . $tail);

    $db->query("CREATE TABLE IF NOT EXISTS `crm_fields` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `crm_id` INT UNSIGNED NOT NULL,
  `field_key` VARCHAR(48) NOT NULL,
  `label` VARCHAR(120) NOT NULL,
  `type` VARCHAR(16) NOT NULL DEFAULT 'text',
  `options` TEXT NULL,
  `config` TEXT NULL,
  `help` VARCHAR(255) NOT NULL DEFAULT '',
  `required` TINYINT(1) NOT NULL DEFAULT 0,
  `is_unique` TINYINT(1) NOT NULL DEFAULT 0,
  `in_list` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `u_crm_key` (`crm_id`,`field_key`),
  KEY `k_crm` (`crm_id`,`sort_order`)
)" . $tail);

    $db->query("CREATE TABLE IF NOT EXISTS `crm_records` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `crm_id` INT UNSIGNED NOT NULL,
  `stage_id` INT UNSIGNED NULL,
  `data` LONGTEXT NOT NULL,
  `assigned_to` INT UNSIGNED NULL,
  `import_id` INT UNSIGNED NULL,
  `created_by` VARCHAR(160) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_crm_stage` (`crm_id`,`stage_id`),
  KEY `k_crm_assigned` (`crm_id`,`assigned_to`),
  KEY `k_crm_updated` (`crm_id`,`updated_at`),
  KEY `k_import` (`import_id`)
)" . $tail);

    $db->query("CREATE TABLE IF NOT EXISTS `crm_files` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `crm_id` INT UNSIGNED NOT NULL,
  `record_id` INT UNSIGNED NOT NULL,
  `field_key` VARCHAR(48) NOT NULL,
  `stored_name` VARCHAR(80) NOT NULL,
  `original_name` VARCHAR(190) NOT NULL,
  `mime` VARCHAR(120) NOT NULL DEFAULT '',
  `size` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_record` (`record_id`,`field_key`)
)" . $tail);

    $db->query("CREATE TABLE IF NOT EXISTS `crm_activity` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `crm_id` INT UNSIGNED NOT NULL,
  `record_id` INT UNSIGNED NOT NULL,
  `who` VARCHAR(160) NOT NULL DEFAULT '',
  `kind` VARCHAR(16) NOT NULL DEFAULT 'edit',
  `detail` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_record` (`record_id`,`created_at`)
)" . $tail);

    $db->query("CREATE TABLE IF NOT EXISTS `crm_imports` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `crm_id` INT UNSIGNED NOT NULL,
  `file_name` VARCHAR(190) NOT NULL DEFAULT '',
  `total_rows` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_n` INT UNSIGNED NOT NULL DEFAULT 0,
  `updated_n` INT UNSIGNED NOT NULL DEFAULT 0,
  `skipped_n` INT UNSIGNED NOT NULL DEFAULT 0,
  `warning_n` INT UNSIGNED NOT NULL DEFAULT 0,
  `messages` MEDIUMTEXT NULL,
  `who` VARCHAR(160) NOT NULL DEFAULT '',
  `undone_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_crm` (`crm_id`,`created_at`)
)" . $tail);

    /* One person's access to one CRM, granted on their page or on the CRM's
       own Access tab. Kept apart from client_sheet_access because those rows
       are wiped and rewritten whenever a person's sheets are saved. */
    $db->query("CREATE TABLE IF NOT EXISTS `crm_access` (
  `crm_id` INT UNSIGNED NOT NULL,
  `person_id` INT UNSIGNED NOT NULL,
  `permission` ENUM('view','edit') NOT NULL DEFAULT 'view',
  `scope` ENUM('all','own') NOT NULL DEFAULT 'all',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`crm_id`,`person_id`),
  KEY `k_person` (`person_id`)
)" . $tail);

    /* Columns that arrived after the first release. */
    fabo_migrate_once('crm_owner_assign', static function () use ($db) {
        foreach ([['crm_boards', 'owner_person', 'INT UNSIGNED NULL AFTER `title_field`'],
                  ['crm_records', 'assigned_to', 'INT UNSIGNED NULL AFTER `data`']] as [$t, $c, $def]) {
            $res = $db->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
            if (!$res || !$res->num_rows) {
                $db->query("ALTER TABLE `$t` ADD COLUMN `$c` $def");
            }
        }
        $res = $db->query("SHOW INDEX FROM `crm_records` WHERE Key_name = 'k_crm_assigned'");
        if (!$res || !$res->num_rows) {
            $db->query('ALTER TABLE `crm_records` ADD KEY `k_crm_assigned` (`crm_id`,`assigned_to`)');
        }
    });
}

/* ========================================================= field types */

/**
 * Every type a field can be, in the order the studio offers them.
 * key => [label, what it is for, glyph, group]
 */
function fabo_crm_types(): array
{
    return [
        'text'        => ['Single line text', 'Names, short answers, IDs', 'Aa', 'Text'],
        'textarea'    => ['Paragraph', 'Addresses, notes, long answers', '¶', 'Text'],
        'email'       => ['Email', 'Checked to be a real address', '@', 'Contact'],
        'phone'       => ['Phone', 'Mobile or landline numbers', '☏', 'Contact'],
        'url'         => ['Website / link', 'A web address, opened in a new tab', '↗', 'Contact'],
        'number'      => ['Whole number', 'Counts, ages, years', '123', 'Numbers'],
        'decimal'     => ['Decimal', 'Marks, scores, measurements', '1.5', 'Numbers'],
        'currency'    => ['Currency (₹)', 'Fees, budgets, amounts', '₹', 'Numbers'],
        'percent'     => ['Percent', 'Percentages, e.g. 87.5', '%', 'Numbers'],
        'rating'      => ['Rating', 'One to five stars', '★', 'Numbers'],
        'date'        => ['Date', 'Applied on, date of birth', '31', 'Date & time'],
        'datetime'    => ['Date & time', 'Interview slot, call time', '⏲', 'Date & time'],
        'time'        => ['Time', 'A time of day', '◷', 'Date & time'],
        'select'      => ['Dropdown', 'Pick one from a list', '▾', 'Choice'],
        'radio'       => ['Single choice', 'Pick one, all options shown', '◉', 'Choice'],
        'multiselect' => ['Multi-select', 'Pick several — tags, skills', '☰', 'Choice'],
        'checkbox'    => ['Yes / No', 'A tick box', '☑', 'Choice'],
        'file'        => ['File upload', 'Resumes, documents, photos', '⇪', 'Other'],
        'autonumber'  => ['Auto number', 'APP-0001, APP-0002… given automatically', '#', 'Other'],
    ];
}

function fabo_crm_type_label(string $t): string
{
    return fabo_crm_types()[$t][0] ?? $t;
}

function fabo_crm_has_options(string $t): bool
{
    return in_array($t, ['select', 'radio', 'multiselect'], true);
}

function fabo_crm_is_numeric_type(string $t): bool
{
    return in_array($t, ['number', 'decimal', 'currency', 'percent', 'rating'], true);
}

/** The colours a stage can be drawn in. key => label. */
function fabo_crm_tones(): array
{
    return [
        'info'   => 'Blue',
        'teal'   => 'Teal',
        'violet' => 'Violet',
        'warn'   => 'Amber',
        'pink'   => 'Pink',
        'good'   => 'Green',
        'risk'   => 'Red',
        'muted'  => 'Grey',
    ];
}

/* ============================================================== access */

/** The organisation whose boards this viewer works with (0 = ours). */
function fabo_crm_org(array $viewer): int
{
    return fabo_customiser($viewer);
}

function fabo_crm_who(array $viewer): string
{
    if (!empty($viewer['is_admin'])) {
        return 'Administrator';
    }
    return mb_substr((string) ($viewer['client']['name'] ?? 'Someone'), 0, 160);
}

/*
 * WHO SEES WHAT
 *
 * A CRM belongs to the organisation that made it: ours (client_id 0) when the
 * administrator makes it, a client's when that client does. Inside that, it
 * belongs to one person on that organisation's own team (owner_person), who
 * runs it.
 *
 * Everybody else is let in one CRM at a time, from crm_access: View or Edit,
 * and either All records or Only the records assigned to them. Grants never
 * cross organisations except one way — the administrator may share one of
 * OUR CRMs with a client. A client may share theirs with their own team and
 * nobody else, so their data stays inside their organisation.
 *
 * The head of a client organisation who holds the CRM module (Clients ->
 * Sheet and module access) sees every CRM their organisation owns, and with
 * Can edit may create new ones. One of our own staff given the CRM module
 * (Team Management) is treated the same way for our own CRMs.
 */

/** The person behind a viewer (0 for the administrator). */
function fabo_crm_me(array $viewer): int
{
    return !empty($viewer['is_admin']) ? 0 : (int) ($viewer['client']['id'] ?? 0);
}

/** Is this the head of a client organisation, rather than someone inside one? */
function fabo_crm_is_head(array $viewer): bool
{
    $c = $viewer['client'] ?? [];
    return empty($viewer['is_admin']) && ($c['kind'] ?? 'client') === 'client'
        && (int) ($c['parent_id'] ?? 0) === 0;
}

/** A client's own team member (not one of ours, not a head): their head's id, else 0. */
function fabo_crm_head_of(array $viewer): int
{
    $c = $viewer['client'] ?? [];
    return empty($viewer['is_admin']) && ($c['kind'] ?? '') === 'team' ? (int) ($c['parent_id'] ?? 0) : 0;
}

/**
 * 'none' | 'view' | 'edit' — the CRM module grant. A client's team member is
 * made a CRM owner by their head on My Team, and never holds more than the
 * head holds now: taking the module from the head takes it from their team.
 */
function fabo_crm_permission(array $viewer): string
{
    $p = fabo_module_permission($viewer, 'crm');
    $head = fabo_crm_head_of($viewer);
    if ($p !== 'none' && $head > 0) {
        $p = fabo_cap_hr($p, fabo_client_access($head)['crm'] ?? 'none');
    }
    return $p;
}

/**
 * Whose CRMs the CRM module reaches for this viewer: a head's own
 * organisation; their head's for a client's team member made an owner on My
 * Team; ours (0) for one of our own staff given it on Team Management. -1 for
 * anyone else (a client's sub-clients), whom the module never reaches.
 */
function fabo_crm_module_org(array $viewer): int
{
    if (fabo_crm_is_head($viewer)) {
        return fabo_crm_me($viewer);
    }
    if (fabo_crm_head_of($viewer) > 0) {
        return fabo_crm_head_of($viewer);
    }
    return fabo_is_our_staff($viewer) ? 0 : -1;
}

/** The organisation a viewer's new CRMs belong to: ours for the admin and our staff, the head's for their team. */
function fabo_crm_viewer_org(array $viewer): int
{
    if (!empty($viewer['is_admin']) || fabo_is_our_staff($viewer)) {
        return 0;
    }
    return fabo_crm_head_of($viewer) ?: fabo_crm_me($viewer);
}

/** May this viewer make new CRMs (for their own organisation)? */
function fabo_crm_may_create(array $viewer): bool
{
    return !empty($viewer['is_admin'])
        || (fabo_crm_module_org($viewer) >= 0 && fabo_crm_permission($viewer) === 'edit');
}

/**
 * What this viewer may do on this board: ['perm' => none|view|edit,
 * 'scope' => all|own, 'why' => owner|org|grant|admin]. 'own' means they see
 * only the records assigned to them.
 */
function fabo_crm_access(array $viewer, array $board): array
{
    if (!empty($viewer['is_admin'])) {
        return ['perm' => 'edit', 'scope' => 'all', 'why' => 'admin'];
    }
    $none = ['perm' => 'none', 'scope' => 'own', 'why' => ''];
    if (($viewer['state'] ?? 'ok') !== 'ok' || !empty($viewer['client']['is_committee'])) {
        return $none;
    }
    // Per request; fabo_crm_grant() empties it, so a change is seen at once.
    $cache = &$GLOBALS['fabo_crm_access_cache'];
    $cache ??= [];
    $me  = fabo_crm_me($viewer);
    $key = $me . ':' . $board['id'] . ':' . ($board['owner_person'] ?? '');
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $out = $none;
    if ($me > 0 && (int) ($board['owner_person'] ?? 0) === $me) {
        $out = ['perm' => 'edit', 'scope' => 'all', 'why' => 'owner'];
    } else {
        $rank = ['none' => 0, 'view' => 1, 'edit' => 2];
        if ((int) $board['client_id'] === fabo_crm_module_org($viewer)) {
            $m = fabo_crm_permission($viewer);
            if ($m !== 'none') {
                $out = ['perm' => $m, 'scope' => 'all', 'why' => 'org'];
            }
        }
        $g = fabo_one('SELECT permission, scope FROM crm_access WHERE crm_id = ? AND person_id = ?', 'ii',
                      [(int) $board['id'], $me]);
        /* "All our CRMs" -- a grant on crm 0 -- reaches every CRM their OWN
           organisation owns, now and later, and never anyone else's. */
        if ((int) $board['client_id'] === fabo_crm_person_org($viewer['client'] ?? [])) {
            $all = fabo_one('SELECT permission, scope FROM crm_access WHERE crm_id = 0 AND person_id = ?', 'i', [$me]);
            if ($all && (!$g || $rank[$all['permission']] > $rank[$g['permission']]
                         || ($all['permission'] === $g['permission'] && $all['scope'] === 'all'))) {
                $g = $all;
            }
        }
        /* A grant only counts inside the organisation that owns the CRM, or on
           one of ours. So a person who later moves organisation, or a grant
           written by hand, never opens somebody else's data. */
        if ($g && fabo_crm_grant_allowed($board, $viewer['client'] ?? [])) {
            if ($rank[$g['permission']] > $rank[$out['perm']]) {
                $out['perm'] = $g['permission'];
                $out['why']  = $out['why'] ?: 'grant';
            }
            if ($out['why'] === 'grant') {
                $out['scope'] = $g['scope'];
            }
        }
    }
    return $cache[$key] = $out;
}

/**
 * The organisation a person works inside: 0 for our own staff, the head's id
 * for a client's people, and their own id for a head. -1 for anyone else (a
 * client's own sub-clients), who never gets "All our CRMs".
 */
function fabo_crm_person_org(array $person): int
{
    $id = (int) ($person['id'] ?? 0);
    $parent = (int) ($person['parent_id'] ?? 0);
    $kind = (string) ($person['kind'] ?? 'client');
    if ($id <= 0) {
        return -1;
    }
    if ($kind === 'team') {
        return $parent;                      // ours: 0; a client's team: that client
    }
    return $parent === 0 ? $id : -1;
}

/** May somebody hold a grant on this board at all? Ours: anyone. A client's: their own people. */
function fabo_crm_grant_allowed(array $board, array $person): bool
{
    $org = (int) $board['client_id'];
    if ($org === 0) {
        return true;
    }
    $id = (int) ($person['id'] ?? 0);
    return $id === $org || (int) ($person['parent_id'] ?? 0) === $org;
}

/** May this viewer open this board at all? */
function fabo_crm_may_see(array $viewer, array $board): bool
{
    return fabo_crm_access($viewer, $board)['perm'] !== 'none';
}

/** May they add, change, move, import and delete records? */
function fabo_crm_may_edit(array $viewer, array $board): bool
{
    return fabo_crm_access($viewer, $board)['perm'] === 'edit';
}

/** Only the records assigned to them? Returns their id when so, else null. */
function fabo_crm_only(array $viewer, array $board): ?int
{
    return fabo_crm_access($viewer, $board)['scope'] === 'own' ? fabo_crm_me($viewer) : null;
}

/**
 * May they change the board's design — fields, stages, settings, and who has
 * access? Us; the person who runs it; the head of the organisation that owns
 * it; and anyone with Edit on all records if the board was opened for that.
 */
function fabo_crm_may_design(array $viewer, array $board): bool
{
    $a = fabo_crm_access($viewer, $board);
    if (in_array($a['why'], ['admin', 'owner'], true)) {
        return true;
    }
    if ($a['why'] === 'org' && $a['perm'] === 'edit') {
        return true;
    }
    /* Design includes the Access tab, which on one of OUR CRMs lists every
       client and can share it with them. A client given Edit on our CRM must
       never reach that, so on ours only our own team can design by grant. */
    if ((int) $board['client_id'] === 0) {
        $c = $viewer['client'] ?? [];
        if (($c['kind'] ?? '') !== 'team' || (int) ($c['parent_id'] ?? 0) !== 0) {
            return false;
        }
    }
    return $a['perm'] === 'edit' && $a['scope'] === 'all' && !empty($board['client_design']);
}

/* ============================================================== people */

/**
 * An organisation's own team: for us, our team members; for a client, the
 * head and the team members they made. id => name. These are who a CRM may
 * belong to, and who records are assigned to.
 */
function fabo_crm_team(int $org): array
{
    $rows = $org === 0
        ? fabo_all("SELECT id, name FROM clients WHERE kind = 'team' AND COALESCE(parent_id, 0) = 0
                     AND revoked_at IS NULL ORDER BY name")
        : fabo_all("SELECT id, name FROM clients WHERE (id = ? OR (kind = 'team' AND parent_id = ?))
                     AND revoked_at IS NULL ORDER BY id = ? DESC, name", 'iii', [$org, $org, $org]);
    $out = [];
    foreach ($rows as $r) {
        $out[(int) $r['id']] = (string) $r['name'];
    }
    return $out;
}

/** Records on a board may be assigned to its organisation's team, and to anyone granted it. */
function fabo_crm_assignable(array $board): array
{
    $out = fabo_crm_team((int) $board['client_id']);
    foreach (fabo_all('SELECT c.id, c.name FROM crm_access a JOIN clients c ON c.id = a.person_id
                        WHERE a.crm_id = ? ORDER BY c.name', 'i', [(int) $board['id']]) as $r) {
        $out[(int) $r['id']] = (string) $r['name'];
    }
    return $out;
}

/**
 * Who the CRM's Access tab offers. For one of ours: our team, and the heads
 * of client organisations (sharing one of our CRMs with a client). For a
 * client's: their own team, nobody else.
 */
function fabo_crm_grantable_people(array $board, bool $withClients = true): array
{
    $org = (int) $board['client_id'];
    $out = [];
    foreach (fabo_crm_team($org) as $id => $name) {
        $out[$id] = ['name' => $name, 'group' => $org === 0 ? 'Our team' : 'Their team'];
    }
    /* Clients are listed -- and so can be granted -- only when the
       administrator is the one sharing. Anyone else running one of our CRMs
       shares it within our own team. */
    if ($org === 0 && $withClients) {
        foreach (fabo_all("SELECT id, name, organisation FROM clients WHERE kind = 'client'
                            AND COALESCE(parent_id, 0) = 0 AND revoked_at IS NULL
                            ORDER BY organisation, name") as $c) {
            $org = trim((string) $c['organisation']);
            $out[(int) $c['id']] = ['name' => trim($c['name'] . ($org !== '' && $org !== $c['name'] ? ' · ' . $org : '')),
                                    'group' => 'Clients'];
        }
    }
    unset($out[(int) ($board['owner_person'] ?? 0)]);     // already runs it
    if ($org > 0) {
        unset($out[$org]);                                   // the head sees it through the module
    }
    return $out;
}

/** Boards an organisation owns: what its own access screens offer. */
function fabo_crm_boards_owned(int $org): array
{
    fabo_crm_install();
    return fabo_all('SELECT id, name, record_label, owner_person FROM crm_boards
                      WHERE client_id = ? AND archived = 0 ORDER BY name', 'i', [$org]);
}

/** One person's grants. crm_id => ['permission' =>, 'scope' =>] */
function fabo_crm_grants_of(int $personId): array
{
    fabo_crm_install();
    $out = [];
    foreach (fabo_all('SELECT crm_id, permission, scope FROM crm_access WHERE person_id = ?', 'i', [$personId]) as $g) {
        $out[(int) $g['crm_id']] = $g;
    }
    return $out;
}

/** Sets one person's access to one board ('none' removes it). */
function fabo_crm_grant(int $crmId, int $personId, string $perm, string $scope): void
{
    if ($personId <= 0) {
        return;
    }
    $GLOBALS['fabo_crm_access_cache'] = [];
    $person = fabo_one('SELECT id, parent_id FROM clients WHERE id = ?', 'i', [$personId]);
    $board  = fabo_crm_board($crmId);
    if (!$person || !$board) {
        return;
    }
    if (!in_array($perm, ['view', 'edit'], true) || !fabo_crm_grant_allowed($board, $person)) {
        fabo_exec('DELETE FROM crm_access WHERE crm_id = ? AND person_id = ?', 'ii', [$crmId, $personId]);
        return;
    }
    $scope = $scope === 'own' ? 'own' : 'all';
    fabo_exec('INSERT INTO crm_access (crm_id, person_id, permission, scope) VALUES (?,?,?,?)
               ON DUPLICATE KEY UPDATE permission = VALUES(permission), scope = VALUES(scope)',
              'iiss', [$crmId, $personId, $perm, $scope]);
}

/**
 * Saves the "CRM access" section of a person's page: crmperm[<crm id>] and
 * crmscope[<crm id>]. Touches only the boards the page offered ($boards), so a
 * grant made somewhere else is never wiped by a save here.
 */
function fabo_crm_save_access(int $personId, array $post, array $boards, bool $allowAll = false): void
{
    $perm  = (array) ($post['crmperm'] ?? []);
    $scope = (array) ($post['crmscope'] ?? []);
    foreach ($boards as $b) {
        $id = (int) $b['id'];
        if (array_key_exists($id, $perm) || array_key_exists((string) $id, $perm)) {
            fabo_crm_grant($id, $personId, (string) $perm[$id], (string) ($scope[$id] ?? 'all'));
        }
    }
    if ($allowAll && (array_key_exists(0, $perm) || array_key_exists('0', $perm))) {
        fabo_crm_grant_all($personId, (string) $perm[0], (string) ($scope[0] ?? 'all'));
    }
}

/**
 * "All our CRMs, now and future" for one person: a grant on crm 0. It only
 * ever opens CRMs of the person's own organisation (fabo_crm_access()), so it
 * is offered on a team member's page and nowhere else.
 */
function fabo_crm_grant_all(int $personId, string $perm, string $scope): void
{
    $person = fabo_one('SELECT id, kind, parent_id FROM clients WHERE id = ?', 'i', [$personId]);
    if (!$person) {
        return;
    }
    $GLOBALS['fabo_crm_access_cache'] = [];
    if (!in_array($perm, ['view', 'edit'], true) || ($person['kind'] ?? '') !== 'team') {
        fabo_exec('DELETE FROM crm_access WHERE crm_id = 0 AND person_id = ?', 'i', [$personId]);
        return;
    }
    fabo_exec('INSERT INTO crm_access (crm_id, person_id, permission, scope) VALUES (0,?,?,?)
               ON DUPLICATE KEY UPDATE permission = VALUES(permission), scope = VALUES(scope)',
              'iss', [$personId, $perm, $scope === 'own' ? 'own' : 'all']);
}

/**
 * The "CRM access" section for a person's page. $boards are what may be
 * offered; $personId is 0 on a create form. With $allowAll the section always
 * shows, led by "All our CRMs" -- so a team member can be given the CRMs at
 * the moment they are added, even before the first CRM exists. Without it,
 * nothing renders when there are no CRMs to offer.
 */
function fabo_crm_access_section(array $boards, int $personId = 0, bool $allowAll = false, ?string $runPerm = null): string
{
    if (!$boards && !$allowAll && $runPerm === null) {
        return '';
    }
    $have = $personId > 0 ? fabo_crm_grants_of($personId) : [];
    $h = static fn($x) => htmlspecialchars((string) $x, ENT_QUOTES, 'UTF-8');
    $out = '<div class="formsec crm-grant"><h3>CRM access</h3>'
         . '<p class="lead">' . ($allowAll ? '<b>All our CRMs</b> covers every CRM your organisation has or '
                                           . 'creates later. Below it, each CRM can also be given on its own. ' : 'Each CRM is shared on its own. ')
         . '<b>Only assigned</b> limits them to the records assigned to them; <b>All records</b> shows the whole CRM.</p>'
         . '<table class="data accesstable"><thead><tr><th>CRM</th><th>No access</th><th>View only</th>'
         . '<th>Can edit</th><th style="width:220px">Records</th></tr></thead><tbody>';
    $rowOf = static function (int $id, string $label, string $tag, string $cur, string $sc) use ($h): string {
        $r = '<tr><td>' . $label . $tag . '</td>';
        foreach (['none', 'view', 'edit'] as $p) {
            $r .= '<td><input type="radio" name="crmperm[' . $id . ']" value="' . $p . '"'
                . ($cur === $p ? ' checked' : '') . ' aria-label="' . $h(strip_tags($label)) . ': ' . $p . '"></td>';
        }
        return $r . '<td style="min-width:200px"><select name="crmscope[' . $id . ']" style="min-width:190px;font:inherit;'
             . 'font-size:13px;padding:4px 6px" aria-label="Which records of ' . $h(strip_tags($label)) . '">'
             . '<option value="all"' . ($sc === 'all' ? ' selected' : '') . '>All records</option>'
             . '<option value="own"' . ($sc === 'own' ? ' selected' : '') . '>Only assigned to them</option>'
             . '</select></td></tr>';
    };
    /* The CRM module itself (perm[crm]), when the page offers it here rather
       than as a separate module row, so CRM is granted in one place only. */
    if ($runPerm !== null) {
        $out .= '<tr><td><b>Create and run our CRMs</b> <span class="pill edit">module</span>'
              . '<div class="hint">Can edit: creates CRMs and runs every one of ours. View only: reads them all.</div></td>';
        foreach (['none', 'view', 'edit'] as $p) {
            $out .= '<td><input type="radio" name="perm[crm]" value="' . $p . '"'
                  . ($runPerm === $p ? ' checked' : '') . ' aria-label="Create and run our CRMs: ' . $p . '"></td>';
        }
        $out .= '<td style="color:#8a93a3">every record</td></tr>';
    }
    if ($allowAll) {
        $out .= $rowOf(0, '<b>All our CRMs</b>', ' <span class="pill view">now and future</span>',
                       $have[0]['permission'] ?? 'none', $have[0]['scope'] ?? 'all');
        if (!$boards) {
            $out .= '<tr><td colspan="5" style="color:#8a93a3;font-size:12.5px">No CRMs yet. Choose access above, '
                  . 'and it applies to every CRM as soon as it is created.</td></tr>';
        }
    }
    foreach ($boards as $b) {
        $id  = (int) $b['id'];
        $cur = $have[$id]['permission'] ?? 'none';
        $sc  = $have[$id]['scope'] ?? 'all';
        $owner = $personId > 0 && (int) ($b['owner_person'] ?? 0) === $personId;
        $out .= '<tr><td>' . $h($b['name']) . ($owner ? ' <span class="pill edit">runs it</span>' : '') . '</td>';
        foreach (['none', 'view', 'edit'] as $p) {
            $out .= '<td><input type="radio" name="crmperm[' . $id . ']" value="' . $p . '"'
                  . ($cur === $p ? ' checked' : '') . ' aria-label="' . $h($b['name']) . ': ' . $p . '"></td>';
        }
        // Styled here: this section sits on pages that do not load crm.css.
        $out .= '<td style="min-width:200px"><select name="crmscope[' . $id . ']" style="min-width:190px;font:inherit;'
              . 'font-size:13px;padding:4px 6px" aria-label="Which records of ' . $h($b['name']) . '">'
              . '<option value="all"' . ($sc === 'all' ? ' selected' : '') . '>All records</option>'
              . '<option value="own"' . ($sc === 'own' ? ' selected' : '') . '>Only assigned to them</option>'
              . '</select></td></tr>';
    }
    return $out . '</tbody></table></div>';
}

/** Does this viewer have any CRM at all? Decides whether the menu shows it. */
function fabo_crm_has_any(array $viewer): bool
{
    if (!empty($viewer['is_admin'])) {
        return true;
    }
    if (($viewer['state'] ?? 'ok') !== 'ok' || !empty($viewer['client']['is_committee'])) {
        return false;
    }
    fabo_crm_install();
    $me = fabo_crm_me($viewer);
    if (fabo_crm_module_org($viewer) >= 0 && fabo_crm_permission($viewer) !== 'none') {
        return true;
    }
    return (bool) fabo_one('SELECT 1 AS x FROM crm_boards WHERE owner_person = ?
                            UNION SELECT 1 FROM crm_access WHERE person_id = ? LIMIT 1', 'ii', [$me, $me]);
}

/* ============================================================== boards */

function fabo_crm_board(int $id): ?array
{
    fabo_crm_install();
    return $id > 0 ? fabo_one('SELECT * FROM crm_boards WHERE id = ?', 'i', [$id]) : null;
}

/**
 * The boards this viewer may see, each with 'org_name' (whose it is),
 * 'runner' (the team member who runs it) and 'access' (fabo_crm_access()).
 */
function fabo_crm_boards_for(array $viewer, bool $archived = false): array
{
    fabo_crm_install();
    $rows = fabo_all('SELECT b.*, COALESCE(NULLIF(c.organisation, ""), c.name) AS org_name, p.name AS runner
                        FROM crm_boards b
                        LEFT JOIN clients c ON c.id = b.client_id
                        LEFT JOIN clients p ON p.id = b.owner_person
                       WHERE b.archived = ? ORDER BY b.client_id, b.name', 'i', [$archived ? 1 : 0]);
    $out = [];
    foreach ($rows as $b) {
        $a = fabo_crm_access($viewer, $b);
        if ($a['perm'] !== 'none') {
            $b['access'] = $a;
            $out[] = $b;
        }
    }
    return $out;
}

/** "Belongs to" choices when making a CRM: the maker's own team. id => name (0 = the maker). */
function fabo_crm_owner_choices(array $viewer): array
{
    $org = fabo_crm_viewer_org($viewer);
    /* A team member -- ours, or a client's -- runs it themselves by their own
       id, since 0 there means nobody in particular; the admin and a head use
       0 for "Me". */
    $me  = (fabo_is_our_staff($viewer) || fabo_crm_head_of($viewer) > 0) ? fabo_crm_me($viewer) : 0;
    $out = [$me => !empty($viewer['is_admin']) ? 'Me (Administrator)' : 'Me'];
    foreach (fabo_crm_team($org) as $id => $name) {
        if ($id !== $org && $id !== $me) {
            $out[$id] = $name;
        }
    }
    return $out;
}

/** Starter layouts offered when a board is made. */
function fabo_crm_templates(): array
{
    return [
        'applications' => [
            'label'  => 'Applications',
            'record' => 'Application',
            'hint'   => 'Admissions, job or programme applications',
            'stages' => [['New', 'info'], ['Screening', 'teal'], ['Interview', 'violet'],
                         ['Offer', 'warn'], ['Enrolled', 'good', 'won'], ['Rejected', 'risk', 'lost']],
            'fields' => [
                ['Application No', 'autonumber', ['config' => ['prefix' => 'APP-', 'digits' => 4, 'next' => 1]]],
                ['Full Name', 'text', ['required' => 1]],
                ['Email', 'email', ['is_unique' => 1]],
                ['Phone', 'phone'],
                ['City', 'text'],
                ['Source', 'select', ['options' => ['Website', 'Walk-in', 'Referral', 'Social Media', 'Event', 'Other']]],
                ['Applied On', 'date'],
            ],
        ],
        'sales' => [
            'label'  => 'Sales pipeline',
            'record' => 'Lead',
            'hint'   => 'Leads and deals from first contact to won or lost',
            'stages' => [['Lead', 'info'], ['Contacted', 'teal'], ['Qualified', 'violet'],
                         ['Proposal', 'warn'], ['Won', 'good', 'won'], ['Lost', 'risk', 'lost']],
            'fields' => [
                ['Contact Name', 'text', ['required' => 1]],
                ['Company', 'text'],
                ['Email', 'email'],
                ['Phone', 'phone'],
                ['Deal Value', 'currency'],
                ['Source', 'select', ['options' => ['Website', 'Referral', 'Cold call', 'Event', 'Other']]],
                ['Next Follow-up', 'date'],
            ],
        ],
        'blank' => [
            'label'  => 'Blank',
            'record' => 'Record',
            'hint'   => 'One stage and one field — design the rest yourself',
            'stages' => [['New', 'info']],
            'fields' => [['Name', 'text', ['required' => 1]]],
        ],
    ];
}

function fabo_crm_board_create(array $viewer, array $in): int
{
    fabo_crm_install();
    $name = trim((string) ($in['name'] ?? ''));
    if ($name === '') {
        throw new InvalidArgumentException('Give the CRM a name.');
    }
    if (!fabo_crm_may_create($viewer)) {
        throw new InvalidArgumentException('Your account cannot create CRMs.');
    }
    $tpl   = fabo_crm_templates()[(string) ($in['template'] ?? 'blank')] ?? fabo_crm_templates()['blank'];
    $label = trim((string) ($in['record_label'] ?? '')) ?: $tpl['record'];

    // Always the maker's own organisation; the person running it is from its own team.
    $org    = fabo_crm_viewer_org($viewer);
    $runner = (int) ($in['owner_person'] ?? 0);
    if ($runner > 0 && !array_key_exists($runner, fabo_crm_owner_choices($viewer))) {
        throw new InvalidArgumentException('Choose someone from your own team.');
    }

    $id = fabo_exec('INSERT INTO crm_boards (client_id, name, record_label, description, owner_person, created_by)
                     VALUES (?,?,?,?,?,?)', 'isssis',
                    [$org, mb_substr($name, 0, 120), mb_substr($label, 0, 60),
                     mb_substr(trim((string) ($in['description'] ?? '')), 0, 255), $runner ?: null,
                     fabo_crm_who($viewer)]);

    foreach ($tpl['stages'] as $i => $s) {
        fabo_exec('INSERT INTO crm_stages (crm_id, name, tone, kind, sort_order) VALUES (?,?,?,?,?)',
                  'isssi', [$id, $s[0], $s[1], $s[2] ?? 'open', $i + 1]);
    }
    $titleKey = null;
    foreach ($tpl['fields'] as $f) {
        $extra = $f[2] ?? [];
        $key = fabo_crm_field_add($id, [
            'label'    => $f[0],
            'type'     => $f[1],
            'required' => $extra['required'] ?? 0,
            'is_unique'=> $extra['is_unique'] ?? 0,
            'in_list'  => 1,
            'options'  => implode("\n", $extra['options'] ?? []),
            'prefix'   => $extra['config']['prefix'] ?? '',
            'digits'   => $extra['config']['digits'] ?? 4,
            'next'     => $extra['config']['next'] ?? 1,
        ]);
        if ($titleKey === null && $f[1] === 'text') {
            $titleKey = $key;
        }
    }
    if ($titleKey) {
        fabo_exec('UPDATE crm_boards SET title_field = ? WHERE id = ?', 'si', [$titleKey, $id]);
    }
    return $id;
}

function fabo_crm_board_save(array $board, array $viewer, array $in): void
{
    $name = trim((string) ($in['name'] ?? ''));
    if ($name === '') {
        throw new InvalidArgumentException('Give the CRM a name.');
    }
    $title = (string) ($in['title_field'] ?? '');
    if ($title !== '' && !fabo_crm_field_by_key((int) $board['id'], $title)) {
        $title = '';
    }
    fabo_exec('UPDATE crm_boards SET name = ?, record_label = ?, description = ?, title_field = ? WHERE id = ?',
              'ssssi', [mb_substr($name, 0, 120),
                        mb_substr(trim((string) ($in['record_label'] ?? '')) ?: 'Record', 0, 60),
                        mb_substr(trim((string) ($in['description'] ?? '')), 0, 255),
                        $title !== '' ? $title : null, (int) $board['id']]);

    // Who runs it: someone on the owning organisation's own team.
    if (array_key_exists('owner_person', $in)) {
        $runner = (int) $in['owner_person'];
        $team   = fabo_crm_team((int) $board['client_id']);
        if ($runner > 0 && !isset($team[$runner])) {
            throw new InvalidArgumentException('Choose someone from the team this CRM belongs to.');
        }
        fabo_exec('UPDATE crm_boards SET owner_person = ? WHERE id = ?', 'ii', [$runner ?: null, (int) $board['id']]);
    }
    fabo_exec('UPDATE crm_boards SET client_design = ? WHERE id = ?', 'ii',
              [!empty($in['client_design']) ? 1 : 0, (int) $board['id']]);
}

/** Removes a board and everything in it, files included. */
function fabo_crm_board_delete(array $board): void
{
    require_once __DIR__ . '/uploads.php';
    $id = (int) $board['id'];
    foreach (fabo_all('SELECT stored_name FROM crm_files WHERE crm_id = ?', 'i', [$id]) as $f) {
        fabo_delete_stored((string) $f['stored_name']);
    }
    foreach (['crm_files', 'crm_activity', 'crm_records', 'crm_fields', 'crm_stages', 'crm_imports', 'crm_access'] as $t) {
        fabo_exec('DELETE FROM `' . $t . '` WHERE crm_id = ?', 'i', [$id]);
    }
    fabo_exec('DELETE FROM crm_boards WHERE id = ?', 'i', [$id]);
}

/* ============================================================== stages */

function fabo_crm_stages(int $crmId): array
{
    return fabo_all('SELECT s.*, (SELECT COUNT(*) FROM crm_records r WHERE r.stage_id = s.id) AS records
                       FROM crm_stages s WHERE s.crm_id = ? ORDER BY s.sort_order, s.id', 'i', [$crmId]);
}

function fabo_crm_stage(int $crmId, int $stageId): ?array
{
    return fabo_one('SELECT * FROM crm_stages WHERE id = ? AND crm_id = ?', 'ii', [$stageId, $crmId]);
}

function fabo_crm_stage_clean(array $in): array
{
    $name = trim((string) ($in['name'] ?? ''));
    if ($name === '') {
        throw new InvalidArgumentException('Give the stage a name.');
    }
    $tone = (string) ($in['tone'] ?? 'info');
    $kind = (string) ($in['kind'] ?? 'open');
    return [mb_substr($name, 0, 80),
            isset(fabo_crm_tones()[$tone]) ? $tone : 'info',
            in_array($kind, ['open', 'won', 'lost'], true) ? $kind : 'open'];
}

function fabo_crm_stage_add(int $crmId, array $in): int
{
    [$name, $tone, $kind] = fabo_crm_stage_clean($in);
    $exists = fabo_one('SELECT id FROM crm_stages WHERE crm_id = ? AND LOWER(name) = LOWER(?)', 'is', [$crmId, $name]);
    if ($exists) {
        throw new InvalidArgumentException('There is already a stage called “' . $name . '”.');
    }
    $max = (int) (fabo_one('SELECT MAX(sort_order) AS m FROM crm_stages WHERE crm_id = ?', 'i', [$crmId])['m'] ?? 0);
    return fabo_exec('INSERT INTO crm_stages (crm_id, name, tone, kind, sort_order) VALUES (?,?,?,?,?)',
                     'isssi', [$crmId, $name, $tone, $kind, $max + 1]);
}

function fabo_crm_stage_save(int $crmId, int $stageId, array $in): void
{
    [$name, $tone, $kind] = fabo_crm_stage_clean($in);
    $clash = fabo_one('SELECT id FROM crm_stages WHERE crm_id = ? AND LOWER(name) = LOWER(?) AND id <> ?',
                      'isi', [$crmId, $name, $stageId]);
    if ($clash) {
        throw new InvalidArgumentException('There is already a stage called “' . $name . '”.');
    }
    fabo_exec('UPDATE crm_stages SET name = ?, tone = ?, kind = ? WHERE id = ? AND crm_id = ?',
              'sssii', [$name, $tone, $kind, $stageId, $crmId]);
}

function fabo_crm_stage_move(int $crmId, int $stageId, string $dir): void
{
    fabo_crm_reorder('crm_stages', $crmId, $stageId, $dir);
}

/** Deletes a stage, moving whatever sat in it to another one first. */
function fabo_crm_stage_delete(int $crmId, int $stageId, int $moveTo): void
{
    $stages = fabo_crm_stages($crmId);
    if (count($stages) <= 1) {
        throw new InvalidArgumentException('A CRM needs at least one stage.');
    }
    if ($moveTo === $stageId || !fabo_crm_stage($crmId, $moveTo)) {
        throw new InvalidArgumentException('Choose the stage its records should move to.');
    }
    fabo_exec('UPDATE crm_records SET stage_id = ? WHERE crm_id = ? AND stage_id = ?', 'iii',
              [$moveTo, $crmId, $stageId]);
    fabo_exec('DELETE FROM crm_stages WHERE id = ? AND crm_id = ?', 'ii', [$stageId, $crmId]);
}

/** Swaps an item with its neighbour in sort order. Shared by stages and fields. */
function fabo_crm_reorder(string $table, int $crmId, int $id, string $dir): void
{
    $rows = fabo_all('SELECT id FROM `' . $table . '` WHERE crm_id = ? ORDER BY sort_order, id', 'i', [$crmId]);
    $ids  = array_map(static fn($r) => (int) $r['id'], $rows);
    $at   = array_search($id, $ids, true);
    if ($at === false) {
        return;
    }
    $to = $dir === 'up' ? $at - 1 : $at + 1;
    if ($to < 0 || $to >= count($ids)) {
        return;
    }
    [$ids[$at], $ids[$to]] = [$ids[$to], $ids[$at]];
    foreach ($ids as $i => $rid) {
        fabo_exec('UPDATE `' . $table . '` SET sort_order = ? WHERE id = ?', 'ii', [$i + 1, $rid]);
    }
}

/* ============================================================== fields */

/** A field row, with its options and config decoded. */
function fabo_crm_field_decode(array $f): array
{
    $f['opts'] = json_decode((string) ($f['options'] ?? ''), true) ?: [];
    $f['cfg']  = json_decode((string) ($f['config'] ?? ''), true) ?: [];
    return $f;
}

function fabo_crm_fields(int $crmId): array
{
    return array_map('fabo_crm_field_decode',
        fabo_all('SELECT * FROM crm_fields WHERE crm_id = ? ORDER BY sort_order, id', 'i', [$crmId]));
}

function fabo_crm_field(int $crmId, int $fieldId): ?array
{
    $f = fabo_one('SELECT * FROM crm_fields WHERE id = ? AND crm_id = ?', 'ii', [$fieldId, $crmId]);
    return $f ? fabo_crm_field_decode($f) : null;
}

function fabo_crm_field_by_key(int $crmId, string $key): ?array
{
    $f = fabo_one('SELECT * FROM crm_fields WHERE field_key = ? AND crm_id = ?', 'si', [$key, $crmId]);
    return $f ? fabo_crm_field_decode($f) : null;
}

/** A storage key from a label: "Date of Birth" -> date_of_birth, unique on the board. */
function fabo_crm_make_key(int $crmId, string $label): string
{
    $base = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $label), '_'));
    if ($base === '' || ctype_digit($base[0])) {
        $base = 'f_' . $base;
    }
    $base = substr($base, 0, 40);
    $key  = $base;
    for ($i = 2; fabo_one('SELECT id FROM crm_fields WHERE crm_id = ? AND field_key = ?', 'is', [$crmId, $key]); $i++) {
        $key = $base . '_' . $i;
    }
    return $key;
}

/** Field settings from a form, checked. [label, type, options, config, help, flags] */
function fabo_crm_field_clean(array $in, ?array $was = null): array
{
    $label = trim((string) ($in['label'] ?? ''));
    if ($label === '') {
        throw new InvalidArgumentException('Give the field a name.');
    }
    $type = (string) ($in['type'] ?? 'text');
    if (!isset(fabo_crm_types()[$type])) {
        throw new InvalidArgumentException('Choose what kind of field it is.');
    }
    $opts = [];
    if (fabo_crm_has_options($type)) {
        $raw = is_array($in['options'] ?? null) ? $in['options'] : preg_split('/\r\n|\r|\n/', (string) ($in['options'] ?? ''));
        $seen = [];
        foreach ($raw as $o) {
            $o = mb_substr(trim((string) $o), 0, 120);
            if ($o !== '' && !isset($seen[mb_strtolower($o)])) {
                $seen[mb_strtolower($o)] = true;
                $opts[] = $o;
            }
        }
        if (!$opts) {
            throw new InvalidArgumentException('A ' . strtolower(fabo_crm_type_label($type))
                                             . ' needs at least one option — one per line.');
        }
    }
    $cfg = [];
    if ($type === 'autonumber') {
        $prev  = $was['cfg'] ?? [];
        $cfg = [
            'prefix' => mb_substr(trim((string) ($in['prefix'] ?? ($prev['prefix'] ?? ''))), 0, 20),
            'digits' => max(1, min(10, (int) ($in['digits'] ?? ($prev['digits'] ?? 4)))),
            'next'   => max(1, (int) ($in['next'] ?? ($prev['next'] ?? 1))),
        ];
    }
    $ph = trim((string) ($in['placeholder'] ?? ''));
    if ($ph !== '') {
        $cfg['placeholder'] = mb_substr($ph, 0, 120);
    }
    return [
        mb_substr($label, 0, 120), $type, $opts, $cfg,
        mb_substr(trim((string) ($in['help'] ?? '')), 0, 255),
        $type === 'autonumber' ? 0 : (!empty($in['required']) ? 1 : 0),
        // Numbers it gives out are unique already; the others cannot clash.
        in_array($type, ['file', 'checkbox', 'multiselect', 'autonumber'], true) ? 0
            : (!empty($in['is_unique']) ? 1 : 0),
        !empty($in['in_list']) ? 1 : 0,
    ];
}

/** Adds a field; returns its storage key. */
function fabo_crm_field_add(int $crmId, array $in): string
{
    [$label, $type, $opts, $cfg, $help, $req, $uniq, $inList] = fabo_crm_field_clean($in);
    $key = fabo_crm_make_key($crmId, $label);
    $max = (int) (fabo_one('SELECT MAX(sort_order) AS m FROM crm_fields WHERE crm_id = ?', 'i', [$crmId])['m'] ?? 0);
    fabo_exec('INSERT INTO crm_fields (crm_id, field_key, label, type, options, config, help, required, is_unique, in_list, sort_order)
               VALUES (?,?,?,?,?,?,?,?,?,?,?)', 'issssssiiii',
              [$crmId, $key, $label, $type,
               $opts ? json_encode($opts, JSON_UNESCAPED_UNICODE) : null,
               $cfg ? json_encode($cfg, JSON_UNESCAPED_UNICODE) : null,
               $help, $req, $uniq, $inList, $max + 1]);
    if ($type === 'autonumber') {
        fabo_crm_autonumber_backfill($crmId, $key);
    }
    return $key;
}

function fabo_crm_field_save(int $crmId, int $fieldId, array $in): void
{
    $was = fabo_crm_field($crmId, $fieldId);
    if (!$was) {
        throw new InvalidArgumentException('That field was not found.');
    }
    [$label, $type, $opts, $cfg, $help, $req, $uniq, $inList] = fabo_crm_field_clean($in, $was);
    fabo_exec('UPDATE crm_fields SET label = ?, type = ?, options = ?, config = ?, help = ?,
                                     required = ?, is_unique = ?, in_list = ?
                WHERE id = ? AND crm_id = ?', 'sssssiiiii',
              [$label, $type,
               $opts ? json_encode($opts, JSON_UNESCAPED_UNICODE) : null,
               $cfg ? json_encode($cfg, JSON_UNESCAPED_UNICODE) : null,
               $help, $req, $uniq, $inList, $fieldId, $crmId]);
    if ($type === 'autonumber' && $was['type'] !== 'autonumber') {
        fabo_crm_autonumber_backfill($crmId, (string) $was['field_key']);
    }
}

/** Deletes a field and what every record held in it. */
function fabo_crm_field_delete(int $crmId, int $fieldId): void
{
    $f = fabo_crm_field($crmId, $fieldId);
    if (!$f) {
        return;
    }
    $path = fabo_crm_json_path((string) $f['field_key']);
    fabo_exec('UPDATE crm_records SET data = JSON_REMOVE(data, ?) WHERE crm_id = ? AND JSON_CONTAINS_PATH(data, "one", ?)',
              'sis', [$path, $crmId, $path]);
    if ($f['type'] === 'file') {
        require_once __DIR__ . '/uploads.php';
        foreach (fabo_all('SELECT stored_name FROM crm_files WHERE crm_id = ? AND field_key = ?', 'is',
                          [$crmId, $f['field_key']]) as $x) {
            fabo_delete_stored((string) $x['stored_name']);
        }
        fabo_exec('DELETE FROM crm_files WHERE crm_id = ? AND field_key = ?', 'is', [$crmId, $f['field_key']]);
    }
    fabo_exec('DELETE FROM crm_fields WHERE id = ? AND crm_id = ?', 'ii', [$fieldId, $crmId]);
    fabo_exec('UPDATE crm_boards SET title_field = NULL WHERE id = ? AND title_field = ?', 'is',
              [$crmId, $f['field_key']]);
}

function fabo_crm_field_move(int $crmId, int $fieldId, string $dir): void
{
    fabo_crm_reorder('crm_fields', $crmId, $fieldId, $dir);
}

/** How many records hold a value in each field. key => n */
function fabo_crm_field_usage(int $crmId, array $fields): array
{
    $out = [];
    foreach ($fields as $f) {
        $path = fabo_crm_json_path((string) $f['field_key']);
        $out[$f['field_key']] = (int) (fabo_one(
            'SELECT COUNT(*) AS n FROM crm_records WHERE crm_id = ?
               AND JSON_EXTRACT(data, ?) IS NOT NULL
               AND JSON_UNQUOTE(JSON_EXTRACT(data, ?)) NOT IN ("", "null")', 'iss', [$crmId, $path, $path])['n'] ?? 0);
    }
    return $out;
}

/** Gives every record that has no number yet the next one, oldest first. */
function fabo_crm_autonumber_backfill(int $crmId, string $key): void
{
    $f = fabo_crm_field_by_key($crmId, $key);
    if (!$f) {
        return;
    }
    $cfg  = $f['cfg'];
    $next = (int) ($cfg['next'] ?? 1);
    foreach (fabo_all('SELECT id, data FROM crm_records WHERE crm_id = ? ORDER BY id', 'i', [$crmId]) as $r) {
        $d = json_decode((string) $r['data'], true) ?: [];
        if (($d[$key] ?? '') !== '') {
            continue;
        }
        $d[$key] = fabo_crm_autonumber_format($cfg, $next++);
        fabo_exec('UPDATE crm_records SET data = ?, updated_at = updated_at WHERE id = ?', 'si',
                  [fabo_crm_json($d), (int) $r['id']]);
    }
    $cfg['next'] = $next;
    fabo_exec('UPDATE crm_fields SET config = ? WHERE id = ?', 'si',
              [json_encode($cfg, JSON_UNESCAPED_UNICODE), (int) $f['id']]);
}

function fabo_crm_autonumber_format(array $cfg, int $n): string
{
    return (string) ($cfg['prefix'] ?? '') . str_pad((string) $n, (int) ($cfg['digits'] ?? 4), '0', STR_PAD_LEFT);
}

/** Takes the next number for an autonumber field and moves the counter on. */
function fabo_crm_autonumber_take(array &$field): string
{
    $row = fabo_one('SELECT config FROM crm_fields WHERE id = ?', 'i', [(int) $field['id']]);
    $cfg = json_decode((string) ($row['config'] ?? ''), true) ?: $field['cfg'];
    $n   = max(1, (int) ($cfg['next'] ?? 1));
    $cfg['next'] = $n + 1;
    fabo_exec('UPDATE crm_fields SET config = ? WHERE id = ?', 'si',
              [json_encode($cfg, JSON_UNESCAPED_UNICODE), (int) $field['id']]);
    $field['cfg'] = $cfg;
    return fabo_crm_autonumber_format($cfg, $n);
}

/* ============================================================== values */

function fabo_crm_json(array $data): string
{
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
}

/** A JSON path to one key. Keys are [a-z0-9_] so this is always safe. */
function fabo_crm_json_path(string $key): string
{
    return '$."' . preg_replace('/[^a-z0-9_]/', '', $key) . '"';
}

/** An option matched case-insensitively, as the list spells it; null when absent. */
function fabo_crm_match_option(array $opts, string $v): ?string
{
    $lv = mb_strtolower(trim($v));
    foreach ($opts as $o) {
        if (mb_strtolower((string) $o) === $lv) {
            return (string) $o;
        }
    }
    return null;
}

/**
 * A date in any of the shapes people type or spreadsheets export, as Y-m-d.
 * Day-first wherever it is ambiguous (03/04/2026 is 3 April), as it is read
 * here. Excel serial numbers are accepted too.
 */
function fabo_crm_parse_date(string $v): ?string
{
    $v = trim($v);
    if ($v === '') {
        return null;
    }
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T].*)?$/', $v, $m)) {
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : null;
    }
    if (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2,4})(?:\s.*)?$/', $v, $m)) {
        $y = (int) $m[3];
        if ($y < 100) {
            $y += $y < 70 ? 2000 : 1900;
        }
        [$d, $mo] = [(int) $m[1], (int) $m[2]];
        if ($mo > 12 && $d <= 12) {          // clearly month-first: 12/31/2026
            [$d, $mo] = [$mo, $d];
        }
        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }
    if (preg_match('/^\d{4,5}(\.\d+)?$/', $v) && (float) $v > 59 && (float) $v < 80000) {
        return fabo_crm_excel_date((float) $v, false);
    }
    $ts = strtotime($v);
    return $ts !== false ? date('Y-m-d', $ts) : null;
}

/** Excel's serial day count (from 1899-12-30) as a date, or a date and time. */
function fabo_crm_excel_date(float $serial, bool $withTime): string
{
    $days = (int) floor($serial);
    $secs = (int) round(($serial - $days) * 86400);
    $d = (new DateTimeImmutable('1899-12-30', new DateTimeZone('UTC')))->modify('+' . $days . ' days');
    if ($withTime && $secs > 0) {
        return $d->modify('+' . $secs . ' seconds')->format('Y-m-d H:i');
    }
    return $d->format('Y-m-d');
}

function fabo_crm_parse_time(string $v): ?string
{
    $v = trim($v);
    if ($v === '') {
        return null;
    }
    if (is_numeric($v) && (float) $v >= 0 && (float) $v < 1) {   // an Excel time fraction
        $s = (int) round((float) $v * 86400);
        return sprintf('%02d:%02d', intdiv($s, 3600) % 24, intdiv($s % 3600, 60));
    }
    $ts = strtotime('2000-01-01 ' . $v);
    return $ts !== false ? date('H:i', $ts) : null;
}

function fabo_crm_parse_number(string $v): ?float
{
    $v = str_replace([',', '₹', 'Rs.', 'Rs', 'INR', '%', ' ', "\u{00A0}"], '', trim($v));
    if ($v === '' || !is_numeric($v)) {
        return null;
    }
    return (float) $v;
}

/**
 * One raw value made fit for a field.
 *
 * Returns [value, problem]: value is null for "blank", problem is '' when
 * the value was usable and a sentence when it was not. The same rules serve
 * the record form and the importer, so a value means the same either way;
 * dropdown options are matched without regard to case.
 */
function fabo_crm_coerce(array $f, $raw): array
{
    $type = (string) $f['type'];

    if ($type === 'multiselect') {
        $parts = is_array($raw) ? $raw : preg_split('/\s*[,;|]\s*/', trim((string) $raw));
        $out = [];
        $bad = [];
        foreach ($parts as $p) {
            $p = trim((string) $p);
            if ($p === '') {
                continue;
            }
            $m = fabo_crm_match_option($f['opts'], $p);
            if ($m === null) {
                $bad[] = $p;
            } elseif (!in_array($m, $out, true)) {
                $out[] = $m;
            }
        }
        return [$out ?: null, $bad ? '“' . implode('”, “', $bad) . '” is not one of the options' : ''];
    }

    $v = is_array($raw) ? implode(', ', $raw) : trim((string) $raw);
    if ($v === '' && $type !== 'checkbox') {
        return [null, ''];
    }

    switch ($type) {
        case 'text':
            return [mb_substr($v, 0, 500), ''];
        case 'textarea':
            return [mb_substr($v, 0, 20000), ''];
        case 'email':
            $v = mb_strtolower($v);
            return filter_var($v, FILTER_VALIDATE_EMAIL) ? [$v, ''] : [null, '“' . $v . '” is not an email address'];
        case 'phone':
            $digits = preg_replace('/\D/', '', $v);
            if (preg_match('/^\d+(\.\d+)?E\+?\d+$/i', $v)) {           // 9.18E+11 from Excel
                $v = number_format((float) $v, 0, '', '');
                $digits = $v;
            }
            return strlen($digits) >= 6 && strlen($digits) <= 16 && preg_match('/^[\d\s+()\-.\/]+$/', $v)
                ? [mb_substr($v, 0, 40), ''] : [null, '“' . $v . '” is not a phone number'];
        case 'url':
            if (!preg_match('~^[a-z][a-z0-9+.\-]*://~i', $v)) {
                $v = 'https://' . $v;
            }
            return filter_var($v, FILTER_VALIDATE_URL) && preg_match('~^https?://~i', $v)
                ? [mb_substr($v, 0, 1000), ''] : [null, '“' . $v . '” is not a web address'];
        case 'number':
            $n = fabo_crm_parse_number($v);
            return $n === null ? [null, '“' . $v . '” is not a number'] : [(int) round($n), ''];
        case 'decimal':
        case 'currency':
        case 'percent':
            $n = fabo_crm_parse_number($v);
            return $n === null ? [null, '“' . $v . '” is not a number'] : [round($n, 4), ''];
        case 'rating':
            if (preg_match('/^★+$/u', $v)) {
                return [min(5, mb_strlen($v)), ''];
            }
            $n = fabo_crm_parse_number(preg_replace('~/\s*\d+$~', '', $v));
            return $n === null || $n < 0 || $n > 5 ? [null, '“' . $v . '” is not a rating from 0 to 5']
                                                  : [(int) round($n), ''];
        case 'date':
            $d = fabo_crm_parse_date($v);
            return $d ? [$d, ''] : [null, '“' . $v . '” is not a date'];
        case 'datetime':
            if (preg_match('/^\d{4,5}\.\d+$/', $v)) {
                return [fabo_crm_excel_date((float) $v, true), ''];
            }
            $d = fabo_crm_parse_date($v);
            if (!$d) {
                return [null, '“' . $v . '” is not a date and time'];
            }
            $t = preg_match('/(\d{1,2}:\d{2}(?::\d{2})?\s*(?:[ap]\.?m\.?)?)\s*$/i', $v, $m)
               ? fabo_crm_parse_time($m[1]) : null;
            return [$d . ' ' . ($t ?? '00:00'), ''];
        case 'time':
            $t = fabo_crm_parse_time($v);
            return $t ? [$t, ''] : [null, '“' . $v . '” is not a time'];
        case 'select':
        case 'radio':
            $m = fabo_crm_match_option($f['opts'], $v);
            return $m === null ? [null, '“' . $v . '” is not one of the options'] : [$m, ''];
        case 'checkbox':
            $l = mb_strtolower($v);
            if (in_array($l, ['1', 'yes', 'y', 'true', 't', 'on', 'x', '✓', '✔', 'checked', 'done'], true)) {
                return [1, ''];
            }
            if (in_array($l, ['', '0', 'no', 'n', 'false', 'f', 'off', '-', 'unchecked'], true)) {
                return [0, ''];
            }
            return [null, '“' . $v . '” is not a yes or no'];
        case 'autonumber':
            return [mb_substr($v, 0, 40), ''];
        case 'file':
            return [null, ''];       // files arrive as uploads, never as text
    }
    return [mb_substr($v, 0, 500), ''];
}

/** Is this stored value blank? */
function fabo_crm_blank($v): bool
{
    return $v === null || $v === '' || $v === [];
}

/* ============================================================= records */

function fabo_crm_record(int $crmId, int $id): ?array
{
    $r = fabo_one('SELECT r.*, p.name AS assignee FROM crm_records r LEFT JOIN clients p ON p.id = r.assigned_to
                    WHERE r.id = ? AND r.crm_id = ?', 'ii', [$id, $crmId]);
    if ($r) {
        $r['d'] = json_decode((string) $r['data'], true) ?: [];
    }
    return $r;
}

/** The headline of a record: its title field, else its first filled text, else its number. */
function fabo_crm_title(array $board, array $fields, array $rec): string
{
    $d   = $rec['d'] ?? [];
    $key = (string) ($board['title_field'] ?? '');
    if ($key !== '' && !fabo_crm_blank($d[$key] ?? null)) {
        return (string) (is_array($d[$key]) ? implode(', ', $d[$key]) : $d[$key]);
    }
    foreach ($fields as $f) {
        if (in_array($f['type'], ['text', 'email'], true) && !fabo_crm_blank($d[$f['field_key']] ?? null)) {
            return (string) $d[$f['field_key']];
        }
    }
    return $board['record_label'] . ' #' . $rec['id'];
}

/**
 * Records on a board, filtered, sorted and paged.
 * $q: ['search' => '', 'stage' => 0, 'sort' => 'updated|created|<field key>', 'dir' => 'asc|desc']
 * Returns [rows, total].
 */
function fabo_crm_records(int $crmId, array $fields, array $q, int $limit = 50, int $offset = 0): array
{
    $where  = ' WHERE r.crm_id = ?';
    $types  = 'i';
    $params = [$crmId];
    if (!empty($q['stage'])) {
        $where   .= ' AND r.stage_id = ?';
        $types   .= 'i';
        $params[] = (int) $q['stage'];
    }
    // 'only' is the viewer's limit and always applies; 'assigned' is a filter they chose.
    if (isset($q['only']) && $q['only'] !== null) {
        $where   .= ' AND r.assigned_to = ?';
        $types   .= 'i';
        $params[] = (int) $q['only'];
    }
    if (!empty($q['assigned'])) {
        if ((int) $q['assigned'] < 0) {
            $where .= ' AND r.assigned_to IS NULL';
        } else {
            $where   .= ' AND r.assigned_to = ?';
            $types   .= 'i';
            $params[] = (int) $q['assigned'];
        }
    }
    $s = trim((string) ($q['search'] ?? ''));
    if ($s !== '') {
        $like   = '%' . addcslashes($s, '%_\\') . '%';
        $where .= ' AND (r.data LIKE ?' . (ctype_digit($s) ? ' OR r.id = ?' : '') . ')';
        $types .= 's';
        $params[] = $like;
        if (ctype_digit($s)) {
            $types   .= 'i';
            $params[] = (int) $s;
        }
    }
    $total = (int) (fabo_one('SELECT COUNT(*) AS n FROM crm_records r' . $where, $types, $params)['n'] ?? 0);

    $dir  = strtolower((string) ($q['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
    $sort = (string) ($q['sort'] ?? 'updated');
    $order = 'r.updated_at ' . $dir;
    if ($sort === 'created') {
        $order = 'r.id ' . $dir;
    } elseif ($sort === 'stage') {
        $order = 's.sort_order ' . $dir . ', r.id DESC';
    } elseif ($sort === 'assigned') {
        $order = 'p.name IS NULL, p.name ' . $dir . ', r.id DESC';
    } else {
        foreach ($fields as $f) {
            if ($f['field_key'] === $sort) {
                $expr  = 'JSON_UNQUOTE(JSON_EXTRACT(r.data, \'' . fabo_crm_json_path($sort) . '\'))';
                $order = (fabo_crm_is_numeric_type($f['type']) ? 'CAST(' . $expr . ' AS DECIMAL(20,4))' : $expr)
                       . ' ' . $dir . ', r.id DESC';
            }
        }
    }
    $rows = fabo_all('SELECT r.*, s.name AS stage_name, s.tone AS stage_tone, p.name AS assignee
                        FROM crm_records r LEFT JOIN crm_stages s ON s.id = r.stage_id
                        LEFT JOIN clients p ON p.id = r.assigned_to'
                     . $where . ' ORDER BY ' . $order . ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset),
                     $types, $params);
    foreach ($rows as &$r) {
        $r['d'] = json_decode((string) $r['data'], true) ?: [];
    }
    return [$rows, $total];
}

/**
 * Values from a record form. Returns [data, problems]. Fields the form does
 * not carry (autonumber, file) are left to the caller.
 */
function fabo_crm_from_post(array $fields, array $post): array
{
    $data = [];
    $errs = [];
    foreach ($fields as $f) {
        $k = (string) $f['field_key'];
        if (in_array($f['type'], ['autonumber', 'file'], true)) {
            continue;
        }
        if ($f['type'] === 'checkbox') {
            $data[$k] = !empty($post[$k]) ? 1 : 0;
            continue;
        }
        $raw = $post[$k] ?? ($f['type'] === 'multiselect' ? [] : '');
        if ($f['type'] === 'datetime' && is_string($raw)) {
            $raw = str_replace('T', ' ', $raw);
        }
        [$v, $p] = fabo_crm_coerce($f, $raw);
        if ($p !== '') {
            $errs[] = $f['label'] . ': ' . $p . '.';
        }
        $data[$k] = $v;
    }
    return [$data, $errs];
}

/**
 * Required fields left blank and unique fields already used elsewhere.
 * $private hides which record holds a clashing value: someone who sees only
 * their own records must not learn about the others by trying values.
 */
function fabo_crm_check(int $crmId, array $fields, array $data, int $exceptId = 0, bool $private = false): array
{
    $errs = [];
    foreach ($fields as $f) {
        $k = (string) $f['field_key'];
        $v = $data[$k] ?? null;
        if (!empty($f['required']) && fabo_crm_blank($v) && $f['type'] !== 'file') {
            $errs[] = $f['label'] . ' is required.';
        }
        if (!empty($f['is_unique']) && !fabo_crm_blank($v) && $f['type'] !== 'autonumber') {
            $path = fabo_crm_json_path($k);
            $dup = fabo_one('SELECT id FROM crm_records WHERE crm_id = ? AND id <> ?
                               AND LOWER(JSON_UNQUOTE(JSON_EXTRACT(data, ?))) = LOWER(?) LIMIT 1',
                            'iiss', [$crmId, $exceptId, $path, (string) $v]);
            if ($dup) {
                $errs[] = $private ? $f['label'] . ' must be unique, and that value is already in use.'
                                   : $f['label'] . ' “' . $v . '” is already on record #' . $dup['id'] . '.';
            }
        }
    }
    return $errs;
}

/**
 * Assigns a record to somebody on the board's list (0 = nobody), logs it and
 * tells them. Returns whether anything changed.
 */
function fabo_crm_assign(array $board, array $rec, int $personId, array $viewer): bool
{
    $crmId = (int) $board['id'];
    $was   = (int) ($rec['assigned_to'] ?? 0);
    if ($personId === $was) {
        return false;
    }
    $people = fabo_crm_assignable($board);
    if ($personId > 0 && !isset($people[$personId])) {
        throw new InvalidArgumentException('That person cannot be assigned records on this CRM.');
    }
    fabo_exec('UPDATE crm_records SET assigned_to = ? WHERE id = ? AND crm_id = ?', 'iii',
              [$personId ?: null, (int) $rec['id'], $crmId]);
    $who = fabo_crm_who($viewer);
    fabo_crm_log($crmId, (int) $rec['id'], $who, 'assign',
                 $personId ? 'Assigned to ' . $people[$personId] : 'Unassigned'
                           . ($was && isset($people[$was]) ? ' (was ' . $people[$was] . ')' : ''));
    if ($personId > 0 && $personId !== fabo_crm_me($viewer)) {
        require_once __DIR__ . '/notify.php';
        $rec['d'] = $rec['d'] ?? (json_decode((string) $rec['data'], true) ?: []);
        fabo_notify($personId, $board['record_label'] . ' assigned to you — ' . $board['name'],
                    fabo_crm_title($board, fabo_crm_fields($crmId), $rec) . ' was assigned to you by ' . $who . '.',
                    'crm.php?' . http_build_query(['crm' => $crmId, 'tab' => 'record', 'rec' => (int) $rec['id']]));
    }
    return true;
}

/** Saves a new record; returns its id. Values are already checked. */
function fabo_crm_record_create(array $board, array $fields, array $data, int $stageId, string $who,
                                int $assignTo = 0, array $viewer = []): int
{
    $crmId = (int) $board['id'];
    foreach ($fields as &$f) {
        if ($f['type'] === 'autonumber' && fabo_crm_blank($data[$f['field_key']] ?? null)) {
            $data[$f['field_key']] = fabo_crm_autonumber_take($f);
        }
    }
    unset($f);
    $data = array_filter($data, static fn($v) => !fabo_crm_blank($v));
    $id = fabo_exec('INSERT INTO crm_records (crm_id, stage_id, data, created_by) VALUES (?,?,?,?)', 'iiss',
                    [$crmId, $stageId ?: null, fabo_crm_json($data), $who]);
    fabo_crm_log($crmId, $id, $who, 'create', 'Created');
    if ($assignTo > 0) {
        fabo_crm_assign($board, fabo_crm_record($crmId, $id), $assignTo, $viewer ?: ['is_admin' => true]);
    }
    return $id;
}

/** Saves changes to a record, logging what moved. */
function fabo_crm_record_update(array $board, array $fields, array $rec, array $data, int $stageId, string $who): void
{
    $old = $rec['d'];
    $new = $old;
    $changed = [];
    foreach ($fields as $f) {
        $k = (string) $f['field_key'];
        if (!array_key_exists($k, $data)) {
            continue;
        }
        $a = $old[$k] ?? null;
        $b = $data[$k];
        if (fabo_crm_blank($a) && fabo_crm_blank($b)) {
            continue;
        }
        if ($a != $b || (is_array($a) !== is_array($b))) {
            $changed[] = $f['label'];
        }
        if (fabo_crm_blank($b)) {
            unset($new[$k]);
        } else {
            $new[$k] = $b;
        }
    }
    $crmId = (int) $board['id'];
    if ($stageId && $stageId !== (int) $rec['stage_id']) {
        fabo_crm_set_stage($board, $rec, $stageId, $who);
    }
    if ($changed) {
        fabo_exec('UPDATE crm_records SET data = ? WHERE id = ? AND crm_id = ?', 'sii',
                  [fabo_crm_json($new), (int) $rec['id'], $crmId]);
        fabo_crm_log($crmId, (int) $rec['id'], $who, 'edit', 'Changed ' . implode(', ', $changed));
    }
}

function fabo_crm_set_stage(array $board, array $rec, int $stageId, string $who): bool
{
    $crmId = (int) $board['id'];
    $to = fabo_crm_stage($crmId, $stageId);
    if (!$to || $stageId === (int) $rec['stage_id']) {
        return false;
    }
    $from = $rec['stage_id'] ? fabo_crm_stage($crmId, (int) $rec['stage_id']) : null;
    fabo_exec('UPDATE crm_records SET stage_id = ? WHERE id = ? AND crm_id = ?', 'iii',
              [$stageId, (int) $rec['id'], $crmId]);
    fabo_crm_log($crmId, (int) $rec['id'], $who, 'stage',
                 ($from ? $from['name'] : 'No stage') . ' → ' . $to['name']);
    return true;
}

function fabo_crm_record_delete(int $crmId, int $id): void
{
    require_once __DIR__ . '/uploads.php';
    foreach (fabo_all('SELECT stored_name FROM crm_files WHERE record_id = ? AND crm_id = ?', 'ii', [$id, $crmId]) as $f) {
        fabo_delete_stored((string) $f['stored_name']);
    }
    fabo_exec('DELETE FROM crm_files WHERE record_id = ? AND crm_id = ?', 'ii', [$id, $crmId]);
    fabo_exec('DELETE FROM crm_activity WHERE record_id = ? AND crm_id = ?', 'ii', [$id, $crmId]);
    fabo_exec('DELETE FROM crm_records WHERE id = ? AND crm_id = ?', 'ii', [$id, $crmId]);
}

/* =========================================================== dashboard */

/**
 * The figures one board's dashboard draws, limited to one person's records
 * when $only is set.
 */
function fabo_crm_stats(array $board, array $stages, ?int $only, string $breakKey = ''): array
{
    $crmId = (int) $board['id'];
    $w = ' WHERE r.crm_id = ?' . ($only !== null ? ' AND r.assigned_to = ?' : '');
    $t = 'i' . ($only !== null ? 'i' : '');
    $p = $only !== null ? [$crmId, $only] : [$crmId];

    $byStage = [];
    foreach (fabo_all('SELECT r.stage_id, COUNT(*) AS n FROM crm_records r' . $w . ' GROUP BY r.stage_id', $t, $p) as $r) {
        $byStage[(int) $r['stage_id']] = (int) $r['n'];
    }
    $total = array_sum($byStage);
    $won = $lost = 0;
    foreach ($stages as $s) {
        $n = $byStage[(int) $s['id']] ?? 0;
        if ($s['kind'] === 'won')  { $won  += $n; }
        if ($s['kind'] === 'lost') { $lost += $n; }
    }

    $since = static function (string $from, string $to) use ($w, $t, $p): int {
        return (int) (fabo_one('SELECT COUNT(*) AS n FROM crm_records r' . $w
                               . ' AND r.created_at >= ? AND r.created_at < ?', $t . 'ss',
                               array_merge($p, [$from, $to]))['n'] ?? 0);
    };
    $today = date('Y-m-d', strtotime('+1 day'));
    $d30   = date('Y-m-d', strtotime('-29 days'));
    $d60   = date('Y-m-d', strtotime('-59 days'));

    // Twelve Monday-to-Sunday weeks, oldest first, the last one being this week.
    $weeks = [];
    $start = strtotime('monday this week', strtotime('today'));
    for ($i = 11; $i >= 0; $i--) {
        $ws = strtotime('-' . $i . ' week', $start);
        $weeks[date('Y-m-d', $ws)] = 0;
    }
    $first = array_key_first($weeks);
    foreach (fabo_all('SELECT DATE(r.created_at) AS d, COUNT(*) AS n FROM crm_records r' . $w
                      . ' AND r.created_at >= ? GROUP BY DATE(r.created_at)', $t . 's', array_merge($p, [$first])) as $r) {
        $wk = date('Y-m-d', strtotime('monday this week', strtotime($r['d'])));
        if (isset($weeks[$wk])) {
            $weeks[$wk] += (int) $r['n'];
        }
    }

    // Per person: open, won, lost.
    $people = [];
    foreach (fabo_all('SELECT r.assigned_to AS pid, p.name, s.kind, COUNT(*) AS n
                         FROM crm_records r LEFT JOIN crm_stages s ON s.id = r.stage_id
                         LEFT JOIN clients p ON p.id = r.assigned_to' . $w
                      . ' GROUP BY r.assigned_to, p.name, s.kind', $t, $p) as $r) {
        $k = (int) $r['pid'];
        $people[$k] ??= ['name' => $k ? ((string) $r['name'] ?: 'Removed person') : 'Unassigned',
                         'open' => 0, 'won' => 0, 'lost' => 0, 'total' => 0];
        $kind = in_array($r['kind'], ['won', 'lost'], true) ? $r['kind'] : 'open';
        $people[$k][$kind] += (int) $r['n'];
        $people[$k]['total'] += (int) $r['n'];
    }
    uasort($people, static fn($a, $b) => $b['total'] <=> $a['total']);

    // Split by one choice field.
    $break = [];
    if ($breakKey !== '') {
        $path = fabo_crm_json_path($breakKey);
        foreach (fabo_all('SELECT JSON_UNQUOTE(JSON_EXTRACT(r.data, ?)) AS v, COUNT(*) AS n FROM crm_records r'
                          . $w . ' GROUP BY v ORDER BY n DESC', 's' . $t, array_merge([$path], $p)) as $r) {
            $v = $r['v'];
            $label = ($v === null || $v === '' || $v === 'null') ? 'Not filled' : (string) $v;
            $break[$label] = ($break[$label] ?? 0) + (int) $r['n'];
        }
        arsort($break);
    }

    $activity = fabo_all('SELECT a.*, r.data FROM crm_activity a JOIN crm_records r ON r.id = a.record_id'
                         . str_replace('r.crm_id', 'a.crm_id', $w) . ' ORDER BY a.id DESC LIMIT 12', $t, $p);

    return [
        'total'    => $total,
        'by_stage' => $byStage,
        'won'      => $won,
        'lost'     => $lost,
        'open'     => $total - $won - $lost,
        'conv'     => ($won + $lost) > 0 ? $won / ($won + $lost) : null,
        'added30'  => $since($d30, $today),
        'prev30'   => $since($d60, $d30),
        'unassigned' => $people[0]['total'] ?? 0,
        'weeks'    => $weeks,
        'people'   => $people,
        'break'    => $break,
        'activity' => $activity,
    ];
}

function fabo_crm_log(int $crmId, int $recordId, string $who, string $kind, string $detail): void
{
    fabo_exec('INSERT INTO crm_activity (crm_id, record_id, who, kind, detail) VALUES (?,?,?,?,?)', 'iisss',
              [$crmId, $recordId, mb_substr($who, 0, 160), $kind, mb_substr($detail, 0, 5000)]);
}

function fabo_crm_activity(int $crmId, int $recordId): array
{
    return fabo_all('SELECT * FROM crm_activity WHERE record_id = ? AND crm_id = ? ORDER BY id DESC LIMIT 200',
                    'ii', [$recordId, $crmId]);
}

/* =============================================================== files */

function fabo_crm_files(int $crmId, int $recordId): array
{
    $out = [];
    foreach (fabo_all('SELECT * FROM crm_files WHERE record_id = ? AND crm_id = ? ORDER BY id', 'ii',
                      [$recordId, $crmId]) as $f) {
        $out[$f['field_key']][] = $f;
    }
    return $out;
}

/** Stores the files sent for each file field of a record. Returns problems. */
function fabo_crm_files_take(array $board, array $fields, int $recordId, string $who): array
{
    require_once __DIR__ . '/uploads.php';
    $errs  = [];
    $crmId = (int) $board['id'];
    foreach ($fields as $f) {
        if ($f['type'] !== 'file') {
            continue;
        }
        $k   = (string) $f['field_key'];
        $set = $_FILES['file_' . $k] ?? null;
        if (!$set || !is_array($set['name'])) {
            continue;
        }
        foreach ($set['name'] as $i => $name) {
            if ((int) $set['error'][$i] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            [$ok, $msg, $meta] = fabo_store_upload([
                'name' => $name, 'type' => $set['type'][$i], 'tmp_name' => $set['tmp_name'][$i],
                'error' => $set['error'][$i], 'size' => $set['size'][$i],
            ]);
            if (!$ok) {
                $errs[] = $f['label'] . ': ' . $name . ' — ' . $msg;
                continue;
            }
            fabo_exec('INSERT INTO crm_files (crm_id, record_id, field_key, stored_name, original_name, mime, size)
                       VALUES (?,?,?,?,?,?,?)', 'iissssi',
                      [$crmId, $recordId, $k, $meta['stored_name'], $meta['original_name'], $meta['mime'], $meta['size']]);
            fabo_crm_log($crmId, $recordId, $who, 'file', 'Added ' . $meta['original_name'] . ' to ' . $f['label']);
        }
    }
    return $errs;
}

function fabo_crm_file(int $crmId, int $fileId): ?array
{
    return fabo_one('SELECT * FROM crm_files WHERE id = ? AND crm_id = ?', 'ii', [$fileId, $crmId]);
}

function fabo_crm_file_delete(int $crmId, int $fileId, string $who): void
{
    require_once __DIR__ . '/uploads.php';
    $f = fabo_crm_file($crmId, $fileId);
    if (!$f) {
        return;
    }
    fabo_delete_stored((string) $f['stored_name']);
    fabo_exec('DELETE FROM crm_files WHERE id = ?', 'i', [$fileId]);
    fabo_crm_log($crmId, (int) $f['record_id'], $who, 'file', 'Removed ' . $f['original_name']);
}

/** Streams one file to the browser: images inline, everything else as a download. */
function fabo_crm_file_send(array $f): void
{
    require_once __DIR__ . '/uploads.php';
    $path = fabo_upload_dir() . DIRECTORY_SEPARATOR . basename((string) $f['stored_name']);
    if (!is_file($path)) {
        fabo_deny('That file is no longer on the server.', 404);
    }
    $mime   = (string) $f['mime'] ?: 'application/octet-stream';
    $inline = in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'], true);
    $name   = str_replace(['"', "\r", "\n"], '', (string) $f['original_name']);
    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="'
         . preg_replace('/[^\x20-\x7E]/', '_', $name) . '"; filename*=UTF-8\'\'' . rawurlencode($name));
    header('Content-Length: ' . filesize($path));
    header('X-Robots-Tag: noindex, nofollow');
    readfile($path);
    exit;
}

/* ============================================================= display */

/** A stored value as HTML for a table cell, a card or the record page. */
function fabo_crm_show(array $f, $v, bool $short = false): string
{
    if (fabo_crm_blank($v) && $f['type'] !== 'checkbox') {
        return '';
    }
    $h = static fn($x) => htmlspecialchars((string) $x, ENT_QUOTES, 'UTF-8');
    switch ($f['type']) {
        case 'email':
            return '<a href="mailto:' . $h($v) . '">' . $h($v) . '</a>';
        case 'phone':
            return '<a href="tel:' . $h(preg_replace('/[^\d+]/', '', (string) $v)) . '">' . $h($v) . '</a>';
        case 'url':
            $label = preg_replace('~^https?://(www\.)?~i', '', (string) $v);
            return '<a href="' . $h($v) . '" target="_blank" rel="noopener noreferrer">'
                 . $h($short ? mb_strimwidth($label, 0, 32, '…') : $label) . '</a>';
        case 'textarea':
            return $short ? $h(mb_strimwidth((string) $v, 0, 80, '…')) : nl2br($h($v));
        case 'currency':
            return $h(fabo_money_exact($v));
        case 'percent':
            return $h(rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.')) . '%';
        case 'number':
            return $h(number_format((float) $v));
        case 'decimal':
            return $h(rtrim(rtrim(number_format((float) $v, 4, '.', ','), '0'), '.'));
        case 'rating':
            $n = max(0, min(5, (int) $v));
            return '<span class="crm-stars" title="' . $n . ' of 5">' . str_repeat('★', $n)
                 . '<i>' . str_repeat('★', 5 - $n) . '</i></span>';
        case 'date':
            $ts = strtotime((string) $v);
            return $ts ? $h(date('j M Y', $ts)) : $h($v);
        case 'datetime':
            $ts = strtotime((string) $v);
            return $ts ? $h(date('j M Y, g:i a', $ts)) : $h($v);
        case 'time':
            $ts = strtotime('2000-01-01 ' . $v);
            return $ts ? $h(date('g:i a', $ts)) : $h($v);
        case 'checkbox':
            return !empty($v) ? '<span class="crm-yes">✓ Yes</span>' : '<span class="crm-no">No</span>';
        case 'select':
        case 'radio':
            return '<span class="crm-tag">' . $h($v) . '</span>';
        case 'multiselect':
            $out = '';
            foreach ((array) $v as $x) {
                $out .= '<span class="crm-tag">' . $h($x) . '</span> ';
            }
            return trim($out);
        case 'autonumber':
            return '<span class="crm-auto">' . $h($v) . '</span>';
    }
    return $h($short ? mb_strimwidth((string) $v, 0, 60, '…') : $v);
}

/**
 * Horizontal bars as HTML: one row per item, its label and count always in
 * text beside the bar, so nothing rests on colour. $items are
 * [label, count, tone|null, href|null]; a tone paints the bar in that stage's
 * colour, otherwise the single accent hue is used.
 */
function fabo_crm_hbars(array $items, ?int $total = null, string $empty = 'Nothing yet.'): string
{
    $h = static fn($x) => htmlspecialchars((string) $x, ENT_QUOTES, 'UTF-8');
    $max = 0;
    $sum = 0;
    foreach ($items as $it) {
        $max = max($max, (int) $it[1]);
        $sum += (int) $it[1];
    }
    $total = $total ?? $sum;
    if ($sum === 0) {
        return '<div class="empty">' . $h($empty) . '</div>';
    }
    $out = '<ul class="crm-hbars">';
    foreach ($items as $it) {
        [$label, $n] = $it;
        $tone = $it[2] ?? null;
        $href = $it[3] ?? null;
        $pct  = $total > 0 ? round($n / $total * 100) : 0;
        $w    = $max > 0 ? max($n > 0 ? 1.5 : 0, $n / $max * 100) : 0;
        $lab  = $href ? '<a href="' . $h($href) . '">' . $h($label) . '</a>' : $h($label);
        $out .= '<li' . ($tone ? ' class="crm-t-' . $h($tone) . '"' : '') . ' data-tip="' . $h($label . ': '
              . number_format($n) . ' (' . $pct . '%)') . '"><span class="crm-hb-l">' . $lab . '</span>'
              . '<span class="crm-hb-track"><i style="width:' . $w . '%"></i></span>'
              . '<span class="crm-hb-n"><b>' . number_format($n) . '</b> <small>' . $pct . '%</small></span></li>';
    }
    return $out . '</ul>';
}

/**
 * Records added per week, as a column chart: one series, the accent hue,
 * flat at the baseline and rounded at the top, the latest week labelled, and
 * a hover tooltip on every column. A table of the same figures is kept for
 * screen readers.
 */
function fabo_crm_weeks_chart(array $weeks): string
{
    $h = static fn($x) => htmlspecialchars((string) $x, ENT_QUOTES, 'UTF-8');
    $W = 640; $H = 190; $ml = 34; $mr = 8; $mt = 18; $mb = 26;
    $pw = $W - $ml - $mr; $ph = $H - $mt - $mb;
    $vals = array_values($weeks);
    if (array_sum($vals) === 0) {
        return '<div class="empty">Nothing added in the last 12 weeks.</div>';
    }
    $max  = max(1, max($vals));
    // A tidy top for the scale: 1, 2 or 5 times a power of ten.
    $mag  = pow(10, floor(log10($max)));
    $top  = (float) ($max / $mag <= 1 ? 1 : ($max / $mag <= 2 ? 2 : ($max / $mag <= 5 ? 5 : 10))) * $mag;
    $n    = count($vals);
    $slot = $pw / $n;
    $bw   = min(30, $slot * 0.62);
    $svg  = '<svg class="crm-weeks" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="Records added per week, last 12 weeks">';
    // A half-way line only where it lands on a whole number.
    foreach ($top >= 2 ? [0, 0.5, 1] : [0, 1] as $f) {
        $y = $mt + $ph - $f * $ph;
        $svg .= '<line x1="' . $ml . '" x2="' . ($W - $mr) . '" y1="' . $y . '" y2="' . $y . '" class="' . ($f === 0 ? 'base' : 'grid') . '"/>'
              . '<text x="' . ($ml - 6) . '" y="' . ($y + 4) . '" class="tick" text-anchor="end">' . number_format($top * $f) . '</text>';
    }
    $i = 0;
    foreach ($weeks as $wk => $v) {
        $x  = $ml + $i * $slot + ($slot - $bw) / 2;
        $bh = $v > 0 ? max(2, $v / $top * $ph) : 0;
        $y  = $mt + $ph - $bh;
        $r  = min(4, $bh, $bw / 2);
        $lab = 'Week of ' . date('j M', strtotime($wk)) . ': ' . number_format($v) . ' added';
        $svg .= '<g class="crm-wk" data-tip="' . $h($lab) . '"><title>' . $h($lab) . '</title>'
              . '<rect x="' . ($ml + $i * $slot) . '" y="' . $mt . '" width="' . $slot . '" height="' . $ph . '" class="hit"/>';
        if ($bh > 0) {
            $svg .= '<path d="M' . $x . ' ' . ($mt + $ph) . 'V' . ($y + $r) . 'Q' . $x . ' ' . $y . ' ' . ($x + $r) . ' ' . $y
                  . 'H' . ($x + $bw - $r) . 'Q' . ($x + $bw) . ' ' . $y . ' ' . ($x + $bw) . ' ' . ($y + $r)
                  . 'V' . ($mt + $ph) . 'Z" class="bar"/>';
        }
        if ($i === $n - 1 && $v > 0) {
            $svg .= '<text x="' . ($x + $bw / 2) . '" y="' . ($y - 5) . '" class="val" text-anchor="middle">' . number_format($v) . '</text>';
        }
        if ($i % 2 === 1 || $i === $n - 1) {
            $svg .= '<text x="' . ($x + $bw / 2) . '" y="' . ($H - 8) . '" class="tick" text-anchor="middle">'
                  . $h($i === $n - 1 ? 'This wk' : date('j M', strtotime($wk))) . '</text>';
        }
        $svg .= '</g>';
        $i++;
    }
    $svg .= '</svg>';
    $tbl = '<table class="crm-sr"><caption>Records added per week</caption><tr><th>Week of</th><th>Added</th></tr>';
    foreach ($weeks as $wk => $v) {
        $tbl .= '<tr><td>' . $h(date('j M Y', strtotime($wk))) . '</td><td>' . (int) $v . '</td></tr>';
    }
    return $svg . $tbl . '</table>';
}

/**
 * A line icon (24-unit grid, drawn in the current text colour). Decorative:
 * every icon sits beside a word, so it is hidden from screen readers.
 */
function fabo_crm_icon(string $name, int $size = 16): string
{
    static $set = [
        'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>',
        'board'     => '<path d="M6 5v11M12 5v6M18 5v14"/>',
        'list'      => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
        'plus'      => '<path d="M5 12h14M12 5v14"/>',
        'upload'    => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/>',
        'download'  => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>',
        'sliders'   => '<path d="M21 4h-7M10 4H3M21 12h-9M8 12H3M21 20h-5M12 20H3M14 2v4M8 10v4M16 18v4"/>',
        'users'     => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/><circle cx="9" cy="7" r="4"/>',
        'fields'    => '<rect x="2" y="6" width="20" height="12" rx="2"/><path d="M7 12h.01M12 12h.01M17 12h.01"/>',
        'stages'    => '<path d="M13 17l5-5-5-5M6 17l5-5-5-5"/>',
        'search'    => '<circle cx="11" cy="11" r="8"/><path d="M21 21l-4.3-4.3"/>',
        'phone'     => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>',
        'mail'      => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="M22 7l-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
        'chat'      => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
        'note'      => '<path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/>',
        'calendar'  => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'clock'     => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'file'      => '<path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><path d="M14 2v6h6"/>',
        'trash'     => '<path d="M3 6h18M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>',
        'back'      => '<path d="M19 12H5M12 19l-7-7 7-7"/>',
        'check'     => '<path d="M20 6L9 17l-5-5"/>',
        'history'   => '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8M3 3v5h5M12 7v5l4 2"/>',
        'lock'      => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'inbox'     => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
        'target'    => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>',
        'userplus'  => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M19 8v6M22 11h-6"/><circle cx="9" cy="7" r="4"/>',
        'trend'     => '<path d="M22 7l-8.5 8.5-5-5L2 17M16 7h6v6"/>',
        'x'         => '<path d="M18 6L6 18M6 6l12 12"/>',
        'cog'       => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
        'chevron'   => '<path d="M6 9l6 6 6-6"/>',
    ];
    return '<svg class="crm-ic" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
         . ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
         . ($set[$name] ?? '') . '</svg>';
}

/** "just now", "5 min ago", "3 h ago", "2 d ago", then the date. */
function fabo_crm_ago(?string $when): string
{
    $ts = $when ? strtotime($when) : false;
    if (!$ts) {
        return '';
    }
    $s = time() - $ts;
    if ($s < 60)       { return 'just now'; }
    if ($s < 3600)     { return (int) floor($s / 60) . ' min ago'; }
    if ($s < 86400)    { return (int) floor($s / 3600) . ' h ago'; }
    if ($s < 86400 * 14) { return (int) floor($s / 86400) . ' d ago'; }
    return date(date('Y', $ts) === date('Y') ? 'j M' : 'j M Y', $ts);
}

/** One colour per board, chosen from its id, for its icon tile. */
function fabo_crm_board_tone(array $board): string
{
    $t = ['info', 'teal', 'violet', 'warn', 'pink', 'good'];
    return $t[((int) $board['id']) % count($t)];
}

/**
 * The counts the list and board filters show: per stage, mine, unassigned,
 * all — within what this viewer may see.
 */
function fabo_crm_counts(int $crmId, ?int $only, int $me): array
{
    $w = ' WHERE crm_id = ?' . ($only !== null ? ' AND assigned_to = ?' : '');
    $t = 'i' . ($only !== null ? 'i' : '');
    $p = $only !== null ? [$crmId, $only] : [$crmId];
    $out = ['stage' => [], 'all' => 0, 'mine' => 0, 'none' => 0];
    foreach (fabo_all('SELECT stage_id, COUNT(*) n, SUM(assigned_to = ?) mine, SUM(assigned_to IS NULL) nobody
                         FROM crm_records' . $w . ' GROUP BY stage_id', 'i' . $t, array_merge([$me], $p)) as $r) {
        $out['stage'][(int) $r['stage_id']] = (int) $r['n'];
        $out['all']  += (int) $r['n'];
        $out['mine'] += (int) $r['mine'];
        $out['none'] += (int) $r['nobody'];
    }
    return $out;
}

/** The first email and phone a record holds, for its quick actions. */
function fabo_crm_contact(array $fields, array $d): array
{
    $email = $phone = '';
    foreach ($fields as $f) {
        $v = $d[$f['field_key']] ?? '';
        if ($email === '' && $f['type'] === 'email' && is_string($v)) { $email = $v; }
        if ($phone === '' && $f['type'] === 'phone' && is_string($v)) { $phone = $v; }
    }
    $wa = preg_replace('/\D/', '', $phone);
    if (strlen($wa) === 10) {
        $wa = '91' . $wa;           // a bare Indian mobile number
    }
    return ['email' => $email, 'phone' => $phone, 'wa' => strlen($wa) >= 10 ? $wa : ''];
}

/** Initials in a round badge, for a person on a card or a row. */
function fabo_crm_avatar(?string $name): string
{
    $name = trim((string) $name);
    if ($name === '') {
        return '';
    }
    $parts = preg_split('/\s+/', $name);
    $ini = mb_strtoupper(mb_substr($parts[0], 0, 1) . (count($parts) > 1 ? mb_substr(end($parts), 0, 1) : ''));
    $hue = abs(crc32($name)) % 6;
    return '<span class="crm-av crm-av' . $hue . '" title="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8')
         . '" aria-hidden="true">' . htmlspecialchars($ini, ENT_QUOTES, 'UTF-8') . '</span>';
}

/** A stored value as plain text, for export. */
function fabo_crm_plain(array $f, $v)
{
    if (fabo_crm_blank($v)) {
        return $f['type'] === 'checkbox' ? 'No' : '';
    }
    switch ($f['type']) {
        case 'multiselect': return implode(', ', (array) $v);
        case 'checkbox':    return !empty($v) ? 'Yes' : 'No';
        case 'number':
        case 'rating':      return ['n' => (int) $v];
        case 'decimal':
        case 'percent':     return ['n' => (float) $v];
        case 'currency':    return ['m' => (float) $v];
        case 'date':        return ['d' => (string) $v];
    }
    return (string) $v;
}

/** The input for one field, named f[<key>]. */
function fabo_crm_input(array $f, $v, bool $disabled = false): string
{
    $h    = static fn($x) => htmlspecialchars((string) $x, ENT_QUOTES, 'UTF-8');
    $k    = (string) $f['field_key'];
    $name = 'f[' . $k . ']';
    $id   = 'crmf_' . $k;
    $req  = !empty($f['required']) && !$disabled ? ' required' : '';
    $dis  = $disabled ? ' disabled' : '';
    $ph   = isset($f['cfg']['placeholder']) ? ' placeholder="' . $h($f['cfg']['placeholder']) . '"' : '';
    $val  = is_array($v) ? '' : (string) ($v ?? '');
    $base = ' id="' . $id . '" name="' . $h($name) . '"' . $req . $dis . $ph;

    switch ($f['type']) {
        case 'textarea':
            return '<textarea' . $base . ' rows="4">' . $h($val) . '</textarea>';
        case 'email':
            return '<input type="email"' . $base . ' value="' . $h($val) . '">';
        case 'phone':
            return '<input type="tel"' . $base . ' value="' . $h($val) . '">';
        case 'url':
            return '<input type="url"' . $base . ' value="' . $h($val) . '" placeholder="https://">';
        case 'number':
            return '<input type="number" step="1"' . $base . ' value="' . $h($val) . '">';
        case 'decimal':
            return '<input type="number" step="any"' . $base . ' value="' . $h($val) . '">';
        case 'currency':
            return '<div class="crm-affix"><span>₹</span><input type="number" step="0.01"' . $base
                 . ' value="' . $h($val) . '"></div>';
        case 'percent':
            return '<div class="crm-affix crm-affix-r"><input type="number" step="any"' . $base
                 . ' value="' . $h($val) . '"><span>%</span></div>';
        case 'rating':
            $out = '<div class="crm-rate">';
            for ($i = 5; $i >= 1; $i--) {
                $out .= '<input type="radio" id="' . $id . '_' . $i . '" name="' . $h($name) . '" value="' . $i . '"'
                      . ((int) $val === $i ? ' checked' : '') . $dis . '><label for="' . $id . '_' . $i
                      . '" title="' . $i . ' of 5">★</label>';
            }
            return $out . '</div>' . ($disabled ? '' : '<label class="crm-clear"><input type="radio" name="'
                 . $h($name) . '" value=""' . ($val === '' ? ' checked' : '') . '> no rating</label>');
        case 'date':
            return '<input type="date"' . $base . ' value="' . $h($val) . '">';
        case 'datetime':
            return '<input type="datetime-local"' . $base . ' value="' . $h(str_replace(' ', 'T', $val)) . '">';
        case 'time':
            return '<input type="time"' . $base . ' value="' . $h($val) . '">';
        case 'select':
            $out = '<select' . $base . '><option value="">—</option>';
            $opts = $f['opts'];
            if ($val !== '' && !in_array($val, $opts, true)) {
                $opts[] = $val;          // a value no longer offered stays selectable
            }
            foreach ($opts as $o) {
                $out .= '<option' . ($o === $val ? ' selected' : '') . '>' . $h($o) . '</option>';
            }
            return $out . '</select>';
        case 'radio':
            $out = '<div class="crm-choices">';
            foreach ($f['opts'] as $i => $o) {
                $out .= '<label><input type="radio" name="' . $h($name) . '" value="' . $h($o) . '"'
                      . ($o === $val ? ' checked' : '') . ($i === 0 ? $req : '') . $dis . '> ' . $h($o) . '</label>';
            }
            return $out . '</div>';
        case 'multiselect':
            $on  = (array) ($v ?? []);
            $out = '<div class="crm-choices crm-multi">';
            foreach (array_unique(array_merge($f['opts'], $on)) as $o) {
                $out .= '<label><input type="checkbox" name="' . $h($name) . '[]" value="' . $h($o) . '"'
                      . (in_array($o, $on, true) ? ' checked' : '') . $dis . '> ' . $h($o) . '</label>';
            }
            return $out . '</div>';
        case 'checkbox':
            return '<label class="crm-check"><input type="checkbox" id="' . $id . '" name="' . $h($name)
                 . '" value="1"' . (!empty($v) ? ' checked' : '') . $dis . '> Yes</label>';
        case 'autonumber':
            return '<div class="crm-auto-in">' . ($val !== '' ? $h($val) : 'Given when saved') . '</div>';
        case 'file':
            return $disabled ? '' : '<input type="file" name="file_' . $h($k) . '[]" multiple>';
    }
    return '<input type="text"' . $base . ' value="' . $h($val) . '" maxlength="500">';
}

/* ============================================================== import */

/** Import files wait here between upload and mapping. */
function fabo_crm_stash_path(string $token, string $ext): string
{
    require_once __DIR__ . '/uploads.php';
    return fabo_upload_dir() . DIRECTORY_SEPARATOR . 'crmimp-' . $token . '.' . $ext;
}

/** Keeps an uploaded import file until it is mapped. Returns [ok, message, meta]. */
function fabo_crm_stash(array $file): array
{
    require_once __DIR__ . '/uploads.php';
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return [false, ($file['error'] ?? 0) === UPLOAD_ERR_NO_FILE ? 'Choose a CSV or Excel file first.'
                                                                    : 'The upload did not finish. Try again.', null];
    }
    $name = (string) ($file['name'] ?? 'import');
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv', 'xlsx', 'txt', 'tsv'], true)) {
        return [false, 'Use a .csv or .xlsx file. An older .xls can be saved as .xlsx from Excel (File → Save As).', null];
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return [false, 'That was not a valid upload.', null];
    }
    // Throw away anything left over from a mapping somebody never finished.
    foreach (glob(fabo_upload_dir() . DIRECTORY_SEPARATOR . 'crmimp-*') ?: [] as $old) {
        if (filemtime($old) < time() - 86400) {
            @unlink($old);
        }
    }
    $token = bin2hex(random_bytes(16));
    if (!move_uploaded_file($file['tmp_name'], fabo_crm_stash_path($token, $ext))) {
        return [false, 'The file could not be saved on the server.', null];
    }
    return [true, '', ['token' => $token, 'ext' => $ext, 'name' => mb_substr($name, 0, 190)]];
}

/**
 * A spreadsheet as a header row and data rows.
 * Returns ['headers' => [...], 'rows' => [[n, [cells]]...], 'sheets' => [names], 'sheet' => i]
 */
function fabo_crm_read_file(string $path, string $ext, int $sheet = 0): array
{
    $sheets = [];
    if ($ext === 'xlsx') {
        [$sheets, $grid] = fabo_crm_read_xlsx($path, $sheet);
    } else {
        $grid = fabo_crm_read_csv($path, $ext === 'tsv' ? "\t" : null);
    }

    // The first row with anything in it is the header row.
    $headers = [];
    $rows    = [];
    $width   = 0;
    foreach ($grid as [$n, $cells]) {
        $has = false;
        foreach ($cells as $c) {
            if (trim((string) $c) !== '') { $has = true; break; }
        }
        if (!$has) {
            continue;
        }
        if (!$headers) {
            $headers = $cells;
            continue;
        }
        $rows[] = [$n, $cells];
        $width  = max($width, count($cells));
        if (count($rows) > FABO_CRM_IMPORT_MAX_ROWS) {
            throw new InvalidArgumentException('That file has more than ' . number_format(FABO_CRM_IMPORT_MAX_ROWS)
                . ' rows. Split it into smaller files and import them one after another.');
        }
    }
    $width = max($width, count($headers));
    $out   = [];
    $seen  = [];
    for ($i = 0; $i < $width; $i++) {
        $h = trim(preg_replace('/\s+/u', ' ', (string) ($headers[$i] ?? '')));
        if ($h === '') {
            $h = 'Column ' . fabo_crm_col_letter($i);
        }
        $base = $h;
        for ($j = 2; isset($seen[mb_strtolower($h)]); $j++) {
            $h = $base . ' (' . $j . ')';
        }
        $seen[mb_strtolower($h)] = true;
        $out[] = $h;
    }
    return ['headers' => $out, 'rows' => $rows, 'sheets' => $sheets, 'sheet' => $sheet];
}

function fabo_crm_col_letter(int $i): string
{
    $s = '';
    for ($n = $i; $n >= 0; $n = intdiv($n, 26) - 1) {
        $s = chr(65 + $n % 26) . $s;
    }
    return $s;
}

/** CSV rows as [[line, cells]...]: delimiter sniffed, BOM dropped, legacy encodings made UTF-8. */
function fabo_crm_read_csv(string $path, ?string $delim = null): array
{
    $fh = fopen($path, 'rb');
    if (!$fh) {
        throw new InvalidArgumentException('The file could not be opened.');
    }
    $head = (string) fread($fh, 8192);
    if ($delim === null) {
        $line  = strtok($head, "\n") ?: $head;
        $best  = ',';
        $bestN = -1;
        foreach ([',', ';', "\t", '|'] as $d) {
            $n = substr_count($line, $d);
            if ($n > $bestN) { $best = $d; $bestN = $n; }
        }
        $delim = $best;
    }
    rewind($fh);
    if (fread($fh, 3) !== "\xEF\xBB\xBF") {
        rewind($fh);
    }
    $out = [];
    $n   = 0;
    while (($cells = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
        $n++;
        if ($cells === [null]) {
            continue;
        }
        foreach ($cells as &$c) {
            $c = (string) $c;
            if ($c !== '' && !mb_check_encoding($c, 'UTF-8')) {
                $c = mb_convert_encoding($c, 'UTF-8', 'Windows-1252');
            }
        }
        unset($c);
        $out[] = [$n, $cells];
        if ($n > FABO_CRM_IMPORT_MAX_ROWS + 100) {
            break;
        }
    }
    fclose($fh);
    return $out;
}

/**
 * The cells of one worksheet of an .xlsx, streamed, without any library.
 * Shared strings, inline strings and booleans are read as text; numbers
 * formatted as dates come back as dates. Returns [sheet names, rows].
 */
function fabo_crm_read_xlsx(string $path, int $want): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new InvalidArgumentException('That does not look like an Excel (.xlsx) file.');
    }
    $wbXml   = $zip->getFromName('xl/workbook.xml');
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($wbXml === false) {
        $zip->close();
        throw new InvalidArgumentException('That Excel file has no worksheets in it.');
    }

    // Which file is each worksheet?
    $targets = [];
    if ($relsXml !== false) {
        $rx = new XMLReader();
        $rx->XML($relsXml);
        while ($rx->read()) {
            if ($rx->nodeType === XMLReader::ELEMENT && $rx->localName === 'Relationship') {
                $t = (string) $rx->getAttribute('Target');
                $t = str_starts_with($t, '/') ? ltrim($t, '/') : 'xl/' . $t;
                $targets[(string) $rx->getAttribute('Id')] = $t;
            }
        }
        $rx->close();
    }
    $names = [];
    $files = [];
    $rx = new XMLReader();
    $rx->XML($wbXml);
    while ($rx->read()) {
        if ($rx->nodeType === XMLReader::ELEMENT && $rx->localName === 'sheet') {
            $names[] = (string) $rx->getAttribute('name');
            $rid = (string) $rx->getAttributeNs('id', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $files[] = $targets[$rid] ?? 'xl/worksheets/sheet' . count($names) . '.xml';
        }
    }
    $rx->close();
    if (!$names) {
        $zip->close();
        throw new InvalidArgumentException('That Excel file has no worksheets in it.');
    }
    $want = max(0, min($want, count($names) - 1));

    // Shared strings: the text of most cells.
    $shared = [];
    $ssXml  = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $rx = new XMLReader();
        $rx->XML($ssXml, null, LIBXML_PARSEHUGE);
        $cur = null;
        $skip = 0;
        while ($rx->read()) {
            if ($rx->nodeType === XMLReader::ELEMENT) {
                if ($rx->localName === 'si') { $cur = ''; }
                elseif ($rx->localName === 'rPh' && !$rx->isEmptyElement) { $skip++; }
                elseif ($rx->localName === 't' && $cur !== null && !$skip) { $cur .= $rx->readString(); }
            } elseif ($rx->nodeType === XMLReader::END_ELEMENT) {
                if ($rx->localName === 'si') { $shared[] = $cur; $cur = null; }
                elseif ($rx->localName === 'rPh') { $skip = max(0, $skip - 1); }
            }
        }
        $rx->close();
    }

    // Styles: which cell formats are dates.
    $dateStyle = [];
    $stXml = $zip->getFromName('xl/styles.xml');
    if ($stXml !== false) {
        $custom = [];
        $rx = new XMLReader();
        $rx->XML($stXml);
        $inXfs = false;
        $i = 0;
        while ($rx->read()) {
            if ($rx->nodeType === XMLReader::ELEMENT && $rx->localName === 'numFmt') {
                $custom[(int) $rx->getAttribute('numFmtId')] = (string) $rx->getAttribute('formatCode');
            } elseif ($rx->nodeType === XMLReader::ELEMENT && $rx->localName === 'cellXfs') {
                $inXfs = true;
            } elseif ($rx->nodeType === XMLReader::END_ELEMENT && $rx->localName === 'cellXfs') {
                $inXfs = false;
            } elseif ($inXfs && $rx->nodeType === XMLReader::ELEMENT && $rx->localName === 'xf') {
                $id = (int) $rx->getAttribute('numFmtId');
                $isDate = ($id >= 14 && $id <= 22) || ($id >= 45 && $id <= 47) || ($id >= 27 && $id <= 36);
                if (isset($custom[$id])) {
                    $code = preg_replace('/"[^"]*"|\[[^\]]*\]|\\\\./', '', $custom[$id]);
                    $isDate = (bool) preg_match('/[dmyhs]/i', $code) && !preg_match('/^[#0.,%\s]+$/', $code);
                }
                if ($isDate) {
                    $dateStyle[$i] = (bool) preg_match('/[hs]/i', $custom[$id] ?? ($id === 22 ? 'h' : ''));
                }
                $i++;
            }
        }
        $rx->close();
    }

    $sheetXml = $zip->getFromName($files[$want]);
    $zip->close();
    if ($sheetXml === false) {
        throw new InvalidArgumentException('That worksheet could not be read.');
    }

    $rows = [];
    $rx = new XMLReader();
    $rx->XML($sheetXml, null, LIBXML_PARSEHUGE);
    $cells = null;
    $rowN  = 0;
    $col   = 0;
    $t = $s = '';
    $val = null;
    while ($rx->read()) {
        if ($rx->nodeType === XMLReader::ELEMENT) {
            switch ($rx->localName) {
                case 'row':
                    $rowN  = (int) ($rx->getAttribute('r') ?: $rowN + 1);
                    $cells = [];
                    $col   = 0;
                    if ($rx->isEmptyElement) {
                        $cells = null;
                    }
                    break;
                case 'c':
                    $ref = (string) $rx->getAttribute('r');
                    if ($ref !== '' && preg_match('/^([A-Z]+)/', $ref, $m)) {
                        $col = 0;
                        foreach (str_split($m[1]) as $ch) {
                            $col = $col * 26 + (ord($ch) - 64);
                        }
                        $col--;
                    }
                    $t   = (string) $rx->getAttribute('t');
                    $s   = (string) $rx->getAttribute('s');
                    $val = null;
                    if ($rx->isEmptyElement) {
                        $col++;
                    }
                    break;
                case 'v':
                    $val = $rx->readString();
                    break;
                case 't':
                    if ($t === 'inlineStr') {
                        $val = ($val ?? '') . $rx->readString();
                    }
                    break;
            }
        } elseif ($rx->nodeType === XMLReader::END_ELEMENT) {
            if ($rx->localName === 'c' && $cells !== null) {
                $out = '';
                if ($val !== null) {
                    if ($t === 's') {
                        $out = (string) ($shared[(int) $val] ?? '');
                    } elseif ($t === 'b') {
                        $out = $val === '1' ? 'TRUE' : 'FALSE';
                    } elseif ($t === 'inlineStr' || $t === 'str' || $t === 'e') {
                        $out = (string) $val;
                    } elseif (is_numeric($val)) {
                        $si = (int) $s;
                        if ($s !== '' && isset($dateStyle[$si]) && (float) $val > 0) {
                            $out = (float) $val < 1
                                 ? fabo_crm_parse_time((string) $val)
                                 : fabo_crm_excel_date((float) $val, $dateStyle[$si]);
                        } else {
                            // 1.0E+11 and 0.30000000000000004 are not what anybody typed.
                            $f = (float) $val;
                            $out = (floor($f) == $f && abs($f) < 1e15)
                                 ? number_format($f, 0, '.', '') : (string) round($f, 10);
                        }
                    } else {
                        $out = (string) $val;
                    }
                }
                $cells[$col] = $out;
                $col++;
            } elseif ($rx->localName === 'row' && $cells !== null) {
                $line = [];
                $max  = $cells ? max(array_keys($cells)) : -1;
                for ($i = 0; $i <= $max; $i++) {
                    $line[] = $cells[$i] ?? '';
                }
                $rows[] = [$rowN, $line];
                $cells  = null;
                if (count($rows) > FABO_CRM_IMPORT_MAX_ROWS + 100) {
                    break;
                }
            }
        }
    }
    $rx->close();
    return [$names, $rows];
}

/** The field a column header most likely means, or '' for none. */
function fabo_crm_guess_target(string $header, array $fields): string
{
    $norm = static fn($s) => preg_replace('/[^a-z0-9]/', '', mb_strtolower((string) $s));
    $h = $norm($header);
    if ($h === '') {
        return '';
    }
    foreach ($fields as $f) {
        if ($norm($f['label']) === $h || $norm($f['field_key']) === $h) {
            return (string) $f['field_key'];
        }
    }
    if (in_array($h, ['stage', 'status', 'pipelinestage', 'applicationstatus'], true)) {
        return '__stage';
    }
    if (in_array($h, ['assignedto', 'assignee', 'owner', 'counsellor', 'counselor', 'handledby',
                      'salesperson', 'executive', 'recordowner'], true)) {
        return '__assign';
    }
    $syn = [
        'email'  => ['emailid', 'emailaddress', 'mail', 'email'],
        'phone'  => ['phone', 'mobile', 'mobileno', 'mobilenumber', 'phonenumber', 'contactno', 'contactnumber', 'whatsapp'],
    ];
    foreach ($fields as $f) {
        if (isset($syn[$f['type']]) && in_array($h, $syn[$f['type']], true)) {
            return (string) $f['field_key'];
        }
    }
    return '';
}

/** A type for a field made from a column during import, from its name and values. */
function fabo_crm_guess_type(string $header, array $samples): string
{
    $h = mb_strtolower($header);
    if (str_contains($h, 'email')) { return 'email'; }
    if (preg_match('/phone|mobile|contact no|whatsapp/', $h)) { return 'phone'; }
    if (preg_match('/\bdate\b|\bdob\b|birth|\bon$/', $h)) { return 'date'; }
    if (preg_match('/url|website|link|linkedin/', $h)) { return 'url'; }
    if (preg_match('/address|remark|note|comment|description/', $h)) { return 'textarea'; }
    $samples = array_values(array_filter(array_map('trim', $samples), static fn($s) => $s !== ''));
    if ($samples && count(array_filter($samples, static fn($s) => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $s))) === count($samples)) {
        return 'date';
    }
    return 'text';
}

/**
 * Brings a mapped file into a board.
 *
 * $opt:
 *   map            column index => field key | '__stage' | '__new' | ''
 *   default_stage  where rows without a (recognised) stage go
 *   create_stages  make a stage for a stage name the board does not have
 *   match          field key to recognise an existing record by ('' = always create)
 *   mode           'skip' | 'update' when a row matches an existing record
 *   add_options    add unknown dropdown values to the field's options
 *
 * Returns the import's summary row.
 */
function fabo_crm_import_run(array $board, array $table, array $opt, array $viewer, string $fileName): array
{
    @set_time_limit(600);
    $crmId = (int) $board['id'];
    $who   = fabo_crm_who($viewer);
    $db    = fabo_db();

    /* Columns that become new fields are made first, so they are ordinary
       fields by the time the rows are read. */
    $map = [];
    foreach ($table['headers'] as $i => $header) {
        $target = (string) ($opt['map'][$i] ?? '');
        if ($target === '__new') {
            $samples = [];
            foreach (array_slice($table['rows'], 0, 50) as [$n, $cells]) {
                $samples[] = (string) ($cells[$i] ?? '');
            }
            $exists = fabo_crm_field_by_key($crmId, fabo_crm_guess_target($header, fabo_crm_fields($crmId)));
            $target = $exists ? (string) $exists['field_key']
                    : fabo_crm_field_add($crmId, ['label' => $header, 'type' => fabo_crm_guess_type($header, $samples),
                                                  'in_list' => 1]);
        }
        if ($target !== '') {
            $map[$i] = $target;
        }
    }
    $fields = [];
    foreach (fabo_crm_fields($crmId) as $f) {
        $fields[$f['field_key']] = $f;
    }
    foreach ($map as $i => $k) {
        if ($k !== '__stage' && $k !== '__assign' && !isset($fields[$k])) {
            unset($map[$i]);
        }
    }

    /* Who rows can be assigned to, found by name or by email. Somebody who
       sees only their own records imports only their own records. */
    $only    = isset($opt['only']) && $opt['only'] !== null ? (int) $opt['only'] : null;
    $people  = fabo_crm_assignable($board);
    $byWho   = [];
    if ($people) {
        $in = implode(',', array_map('intval', array_keys($people)));
        foreach (fabo_all('SELECT id, name, email, login_id FROM clients WHERE id IN (' . $in . ')') as $pp) {
            foreach (['name', 'email', 'login_id'] as $col) {
                $v = mb_strtolower(trim((string) ($pp[$col] ?? '')));
                if ($v !== '') {
                    $byWho[$v] = (int) $pp['id'];
                }
            }
        }
    }
    $defAssign = (int) ($opt['assign_default'] ?? 0);
    if (!isset($people[$defAssign])) {
        $defAssign = 0;
    }
    if ($only !== null) {
        $defAssign = $only;
    }
    if (!$map) {
        throw new InvalidArgumentException('Map at least one column to a field before importing.');
    }

    $stages = [];
    foreach (fabo_crm_stages($crmId) as $s) {
        $stages[mb_strtolower($s['name'])] = (int) $s['id'];
    }
    $defStage = (int) ($opt['default_stage'] ?? 0);
    if (!in_array($defStage, $stages, true)) {
        $defStage = (int) (reset($stages) ?: 0);
    }

    $match = (string) ($opt['match'] ?? '');
    if ($match !== '' && !isset($fields[$match])) {
        $match = '';
    }
    $mode = ($opt['mode'] ?? 'skip') === 'update' ? 'update' : 'skip';

    /* What is already on the board, for matching and for unique fields:
       value (lower-cased) => record id, per field. Read once, kept current
       as rows go in, so two rows of the same file collide with each other
       as well as with what was there before. */
    $index  = [];
    $owners = [];
    foreach ($fields as $k => $f) {
        if ($k === $match || !empty($f['is_unique'])) {
            $index[$k] = [];
            $path = fabo_crm_json_path($k);
            foreach (fabo_all('SELECT id, assigned_to, LOWER(JSON_UNQUOTE(JSON_EXTRACT(data, ?))) AS v FROM crm_records
                                WHERE crm_id = ? AND JSON_EXTRACT(data, ?) IS NOT NULL', 'sis',
                              [$path, $crmId, $path]) as $r) {
                if ((string) $r['v'] !== '' && $r['v'] !== null) {
                    $index[$k][(string) $r['v']] = (int) $r['id'];
                    $owners[(int) $r['id']] = (int) $r['assigned_to'];
                }
            }
        }
    }

    $impId = fabo_exec('INSERT INTO crm_imports (crm_id, file_name, total_rows, who) VALUES (?,?,?,?)',
                       'isis', [$crmId, mb_substr($fileName, 0, 190), count($table['rows']), $who]);

    $created = $updated = $skipped = $warned = 0;
    $msgs    = [];
    $note    = static function (string $m) use (&$msgs) {
        if (count($msgs) < 500) {
            $msgs[] = $m;
        }
    };
    $optionsGrew = [];
    $autos = array_filter($fields, static fn($f) => $f['type'] === 'autonumber');
    $autoNext = [];
    foreach ($autos as $k => $f) {
        $autoNext[$k] = max(1, (int) ($f['cfg']['next'] ?? 1));
    }

    $ins = $db->prepare('INSERT INTO crm_records (crm_id, stage_id, data, assigned_to, import_id, created_by) VALUES (?,?,?,?,?,?)');
    $upd = $db->prepare('UPDATE crm_records SET data = ?, stage_id = ?, assigned_to = ? WHERE id = ? AND crm_id = ?');
    $assignedTally = [];
    $db->begin_transaction();
    try {
        foreach ($table['rows'] as [$line, $cells]) {
            $data     = [];
            $stage    = 0;
            $assignee = 0;
            $rowWarn  = [];
            foreach ($map as $i => $k) {
                $raw = trim((string) ($cells[$i] ?? ''));
                if ($raw === '') {
                    continue;
                }
                if ($k === '__assign') {
                    $hit = $byWho[mb_strtolower($raw)] ?? 0;
                    if ($hit && $only === null) {
                        $assignee = $hit;
                    } elseif (!$hit) {
                        $rowWarn[] = '“' . $raw . '” is not on this CRM’s team, so it was not assigned to them';
                    }
                    continue;
                }
                if ($k === '__stage') {
                    $sk = mb_strtolower($raw);
                    if (!isset($stages[$sk]) && !empty($opt['create_stages'])) {
                        $stages[$sk] = fabo_crm_stage_add($crmId, ['name' => $raw, 'tone' => 'info']);
                    }
                    if (isset($stages[$sk])) {
                        $stage = $stages[$sk];
                    } else {
                        $rowWarn[] = 'stage “' . $raw . '” is not on this CRM, so it went to the default stage';
                    }
                    continue;
                }
                $f = $fields[$k];
                if (fabo_crm_has_options($f['type']) && !empty($opt['add_options'])) {
                    $parts = $f['type'] === 'multiselect' ? preg_split('/\s*[,;|]\s*/', $raw) : [$raw];
                    foreach ($parts as $p) {
                        $p = mb_substr(trim($p), 0, 120);
                        if ($p !== '' && fabo_crm_match_option($fields[$k]['opts'], $p) === null) {
                            $fields[$k]['opts'][] = $p;
                            $optionsGrew[$k] = true;
                        }
                    }
                    $f = $fields[$k];
                }
                [$v, $problem] = fabo_crm_coerce($f, $raw);
                if ($problem !== '') {
                    $rowWarn[] = $f['label'] . ': ' . $problem . ' — left blank';
                }
                if (!fabo_crm_blank($v)) {
                    $data[$k] = $v;
                }
            }

            if (!$data && !$stage) {
                $skipped++;
                $note('Row ' . $line . ': nothing in the mapped columns — skipped.');
                continue;
            }

            // Is it a record we already have?
            $existing = 0;
            if ($match !== '' && isset($data[$match])) {
                $existing = $index[$match][mb_strtolower((string) (is_array($data[$match]) ? implode(', ', $data[$match]) : $data[$match]))] ?? 0;
            }
            // Somebody limited to their own records cannot reach anyone else's through a match.
            if ($existing && $only !== null && ($owners[$existing] ?? 0) !== $only) {
                $skipped++;
                $note('Row ' . $line . ': it matches an existing record, so it was skipped.');
                continue;
            }
            if ($existing && $mode === 'skip') {
                $skipped++;
                $note('Row ' . $line . ': ' . $fields[$match]['label'] . ' “' . $data[$match]
                    . '” is already on record #' . $existing . ' — skipped.');
                continue;
            }

            $merged = $data;
            if ($existing) {
                $old    = fabo_crm_record($crmId, $existing);
                $merged = array_merge($old['d'] ?? [], $data);
            }

            // Required fields and unique fields.
            $fail = [];
            foreach ($fields as $k => $f) {
                if (!empty($f['required']) && !in_array($f['type'], ['file', 'autonumber'], true)
                    && fabo_crm_blank($merged[$k] ?? null)) {
                    $fail[] = $f['label'] . ' is required but blank';
                }
                if (!empty($f['is_unique']) && $f['type'] !== 'autonumber' && isset($data[$k]) && $k !== $match) {
                    $hit = $index[$k][mb_strtolower((string) $data[$k])] ?? 0;
                    if ($hit && $hit !== $existing) {
                        $fail[] = $only !== null
                                ? $f['label'] . ' must be unique and that value is already in use'
                                : $f['label'] . ' “' . $data[$k] . '” is already on record #' . $hit;
                    }
                }
            }
            if ($fail) {
                $skipped++;
                $note('Row ' . $line . ': ' . implode('; ', $fail) . ' — skipped.');
                continue;
            }
            if ($rowWarn) {
                $warned++;
                $note('Row ' . $line . ': ' . implode('; ', $rowWarn) . '.');
            }

            if ($existing) {
                $stageId = $stage ?: (int) ($old['stage_id'] ?: $defStage);
                $json    = fabo_crm_json(array_filter($merged, static fn($v) => !fabo_crm_blank($v)));
                $asg     = $assignee ?: ((int) $old['assigned_to'] ?: null);
                $upd->bind_param('siiii', $json, $stageId, $asg, $existing, $crmId);
                $upd->execute();
                if ($assignee && $assignee !== (int) $old['assigned_to']) {
                    fabo_crm_log($crmId, $existing, $who, 'assign', 'Assigned to ' . $people[$assignee] . ' by import');
                    $assignedTally[$assignee] = ($assignedTally[$assignee] ?? 0) + 1;
                }
                if ($stage && $stage !== (int) $old['stage_id']) {
                    fabo_crm_log($crmId, $existing, $who, 'stage', 'Moved by import of ' . $fileName);
                }
                fabo_crm_log($crmId, $existing, $who, 'import', 'Updated from ' . $fileName . ', row ' . $line);
                $updated++;
                $rid = $existing;
            } else {
                foreach ($autos as $k => $f) {
                    if (fabo_crm_blank($data[$k] ?? null)) {
                        $data[$k] = fabo_crm_autonumber_format($f['cfg'], $autoNext[$k]++);
                    }
                }
                $stageId = $stage ?: $defStage;
                $json    = fabo_crm_json($data);
                $asg     = ($assignee ?: $defAssign) ?: null;
                $ins->bind_param('iisiis', $crmId, $stageId, $json, $asg, $impId, $who);
                $ins->execute();
                if ($asg) {
                    $assignedTally[$asg] = ($assignedTally[$asg] ?? 0) + 1;
                }
                $rid = (int) $db->insert_id;
                $created++;
            }
            foreach ($index as $k => $_) {
                if (isset($data[$k]) && !is_array($data[$k])) {
                    $index[$k][mb_strtolower((string) $data[$k])] = $rid;
                }
            }
        }

        foreach ($optionsGrew as $k => $_) {
            fabo_exec('UPDATE crm_fields SET options = ? WHERE id = ?', 'si',
                      [json_encode(array_values($fields[$k]['opts']), JSON_UNESCAPED_UNICODE), (int) $fields[$k]['id']]);
        }
        foreach ($autos as $k => $f) {
            $cfg = $f['cfg'];
            $cfg['next'] = $autoNext[$k];
            fabo_exec('UPDATE crm_fields SET config = ? WHERE id = ?', 'si',
                      [json_encode($cfg, JSON_UNESCAPED_UNICODE), (int) $f['id']]);
        }
        fabo_exec('UPDATE crm_imports SET created_n = ?, updated_n = ?, skipped_n = ?, warning_n = ?, messages = ?
                    WHERE id = ?', 'iiiisi',
                  [$created, $updated, $skipped, $warned, json_encode($msgs, JSON_UNESCAPED_UNICODE), $impId]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        fabo_exec('DELETE FROM crm_imports WHERE id = ?', 'i', [$impId]);
        throw $e;
    }
    $ins->close();
    $upd->close();

    // One notice per person, not one per row.
    require_once __DIR__ . '/notify.php';
    foreach ($assignedTally as $pid => $n) {
        if ($pid !== fabo_crm_me($viewer)) {
            fabo_notify($pid, $n . ' ' . mb_strtolower($board['record_label']) . ($n === 1 ? '' : 's')
                            . ' assigned to you — ' . $board['name'],
                        $who . ' imported ' . $fileName . ' and assigned ' . $n . ' to you.',
                        'crm.php?' . http_build_query(['crm' => $crmId, 'tab' => 'table', 'assigned' => $pid]));
        }
    }

    return fabo_one('SELECT * FROM crm_imports WHERE id = ?', 'i', [$impId]) ?? [];
}

function fabo_crm_imports(int $crmId): array
{
    return fabo_all('SELECT i.*, (SELECT COUNT(*) FROM crm_records r WHERE r.import_id = i.id) AS still_there
                       FROM crm_imports i WHERE i.crm_id = ? ORDER BY i.id DESC LIMIT 50', 'i', [$crmId]);
}

/** Removes every record an import created. Records it only updated are left as they are. */
function fabo_crm_import_undo(int $crmId, int $impId): int
{
    $imp = fabo_one('SELECT * FROM crm_imports WHERE id = ? AND crm_id = ?', 'ii', [$impId, $crmId]);
    if (!$imp) {
        throw new InvalidArgumentException('That import was not found.');
    }
    $ids = array_map(static fn($r) => (int) $r['id'],
                     fabo_all('SELECT id FROM crm_records WHERE crm_id = ? AND import_id = ?', 'ii', [$crmId, $impId]));
    foreach ($ids as $id) {
        fabo_crm_record_delete($crmId, $id);
    }
    fabo_exec('UPDATE crm_imports SET undone_at = NOW() WHERE id = ?', 'i', [$impId]);
    return count($ids);
}

/* ============================================================== export */

/** Every record on a board as one worksheet: what the board holds, in field order. */
function fabo_crm_export(array $board, ?int $only = null): void
{
    require_once __DIR__ . '/xlsx.php';
    $crmId  = (int) $board['id'];
    $fields = array_filter(fabo_crm_fields($crmId), static fn($f) => $f['type'] !== 'file');
    $head   = array_merge(['ID', 'Stage', 'Assigned to'], array_map(static fn($f) => $f['label'], array_values($fields)),
                          ['Created', 'Updated']);
    $rows   = [$head];
    [$recs] = fabo_crm_records($crmId, $fields, ['sort' => 'created', 'dir' => 'asc', 'only' => $only], 1000000);
    foreach ($recs as $r) {
        $line = [['n' => (int) $r['id']], (string) ($r['stage_name'] ?? ''), (string) ($r['assignee'] ?? '')];
        foreach ($fields as $f) {
            $line[] = fabo_crm_plain($f, $r['d'][$f['field_key']] ?? null);
        }
        $line[] = ['d' => (string) $r['created_at']];
        $line[] = ['d' => (string) $r['updated_at']];
        $rows[] = $line;
    }
    $x = new FaboXlsx();
    $x->addSheet($board['name'], $rows);
    $x->download($board['name'] . ' ' . date('Y-m-d') . '.xlsx');
}

/**
 * A workbook to fill in and import: the first sheet is just the header row,
 * so nothing in it is ever imported by mistake; the second says what each
 * column takes.
 */
function fabo_crm_template(array $board): void
{
    require_once __DIR__ . '/xlsx.php';
    $fields = array_filter(fabo_crm_fields((int) $board['id']),
                           static fn($f) => !in_array($f['type'], ['file', 'autonumber'], true));
    $head   = array_merge(array_map(static fn($f) => $f['label'], array_values($fields)), ['Stage', 'Assigned To']);
    $eg = ['date' => '2026-01-31 or 31/01/2026', 'datetime' => '2026-01-31 14:30', 'time' => '14:30',
           'checkbox' => 'Yes or No', 'rating' => '0 to 5', 'email' => 'name@example.com',
           'phone' => '98765 43210', 'currency' => '25000', 'percent' => '87.5', 'number' => '42',
           'decimal' => '7.25', 'url' => 'https://example.com'];
    $guide = [['Column', 'Type', 'Required', 'What to put in it']];
    foreach ($fields as $f) {
        $what = fabo_crm_has_options($f['type'])
            ? ($f['type'] === 'multiselect' ? 'Any of, comma-separated: ' : 'One of: ') . implode(', ', $f['opts'])
            : ($eg[$f['type']] ?? 'Text');
        $guide[] = [$f['label'], fabo_crm_type_label($f['type']), !empty($f['required']) ? 'Yes' : '', $what];
    }
    $guide[] = ['Stage', 'Stage', '', 'One of: ' . implode(', ', array_column(fabo_crm_stages((int) $board['id']), 'name'))
                                      . ' — blank goes to the first stage'];
    $guide[] = ['Assigned To', 'Person', '', 'The name or email of one of: '
                . implode(', ', fabo_crm_assignable($board)) . ' — blank leaves it unassigned'];
    $x = new FaboXlsx();
    $x->addSheet($board['name'], [$head]);
    $x->addSheet('Guide', $guide);
    $x->download($board['name'] . ' - import template.xlsx');
}

<?php
/**
 * Startup Discovery — the data and the rules.
 *
 * An organisation (the "owner": a client head, or us as 0) runs idea rounds
 * across its schools and colleges. Each department of each college has ONE
 * Head of Department dashboard. The HoD shares an idea form with their
 * students, validates the ideas with the Gem the owner assigned (inside the
 * portal, or in their own AI tool with the result pasted back, at most
 * `ai_limit` checks per idea), and forwards their top picks (`top_n`, 3 by
 * default). Forwarded ideas land in the owner's single pipeline, where the
 * owner assigns students to each project as a team.
 *
 *   iv_colleges     -> iv_departments (1 HoD each: iv_hods)
 *   iv_students     one row per student per organisation (iv_students.ukey)
 *   iv_rounds       submission window, Gem, AI limit, top N
 *   iv_forms        one per round per department, shared by the HoD
 *   iv_ideas        one per student per round (UNIQUE round_id, student_id)
 *   iv_ai_runs      every Gem check, counted against the limit
 *   iv_team         a student sits on one project team per round
 *
 * HoDs are NOT portal accounts in `clients`: hundreds of HoDs must not appear
 * in My Team or the IT helpdesk. They sign in to hod.php with their own
 * password, kept here. Every query filters by org_id. The module is granted
 * like Academic: 'ideaval' in fabo_modules().
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../google.php';     // fabo_setting()

const IV_SCHEMA_VERSION = 5;
const IV_INVITE_DAYS = 7;
const IV_MAX_IMPORT = 50000;

/** Now, on PHP's clock. The database clock may be UTC: every stored time here is written and compared with this. */
function iv_now(int $offset = 0): string
{
    return date('Y-m-d H:i:s', time() + $offset);
}

/* ================================================================ schema */

function iv_schema_sql(): array
{
    $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
"CREATE TABLE IF NOT EXISTS iv_settings (
  org_id INT UNSIGNED NOT NULL,
  k VARCHAR(64) NOT NULL,
  v TEXT NULL,
  PRIMARY KEY (org_id, k)
) $t",

"CREATE TABLE IF NOT EXISTS iv_colleges (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  code VARCHAR(20) NULL,
  name VARCHAR(190) NOT NULL,
  kind VARCHAR(30) NOT NULL DEFAULT 'College',
  district VARCHAR(80) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY u_name (org_id, name),
  UNIQUE KEY u_code (org_id, code)
) $t",

"CREATE TABLE IF NOT EXISTS iv_departments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  college_id INT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  branch VARCHAR(12) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY u_name (college_id, name),
  UNIQUE KEY u_branch (college_id, branch),
  KEY k_org (org_id, college_id)
) $t",

"CREATE TABLE IF NOT EXISTS iv_hods (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  department_id INT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  email VARCHAR(160) NOT NULL,
  phone VARCHAR(40) NOT NULL DEFAULT '',
  status ENUM('active','suspended') NOT NULL DEFAULT 'active',
  ai_limit TINYINT UNSIGNED NULL,
  pass_hash VARCHAR(255) NULL,
  pass_set_at DATETIME NULL,
  must_change TINYINT(1) NOT NULL DEFAULT 0,
  invite_hash CHAR(64) NULL,
  invite_expires DATETIME NULL,
  invited_at DATETIME NULL,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY u_dept (department_id),
  UNIQUE KEY u_email (org_id, email),
  UNIQUE KEY u_invite (invite_hash)
) $t",

"CREATE TABLE IF NOT EXISTS iv_students (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  college_id INT UNSIGNED NOT NULL DEFAULT 0,
  department_id INT UNSIGNED NOT NULL DEFAULT 0,
  name VARCHAR(160) NOT NULL,
  email VARCHAR(160) NOT NULL DEFAULT '',
  phone VARCHAR(20) NOT NULL DEFAULT '',
  roll_no VARCHAR(40) NOT NULL DEFAULT '',
  year_of_study VARCHAR(20) NOT NULL DEFAULT '',
  ukey VARCHAR(200) NOT NULL,
  source VARCHAR(20) NOT NULL DEFAULT 'manual',
  source_ref VARCHAR(60) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY u_key (org_id, ukey),
  KEY k_dept (org_id, department_id),
  KEY k_college (org_id, college_id),
  KEY k_roll (org_id, roll_no),
  KEY k_email (org_id, email),
  KEY k_phone (org_id, phone)
) $t",

"CREATE TABLE IF NOT EXISTS iv_rounds (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  gem_id INT UNSIGNED NULL,
  opens_at DATETIME NOT NULL,
  closes_at DATETIME NOT NULL,
  review_by DATETIME NULL,
  ai_limit TINYINT UNSIGNED NOT NULL DEFAULT 2,
  top_n TINYINT UNSIGNED NOT NULL DEFAULT 3,
  allow_new TINYINT(1) NOT NULL DEFAULT 1,
  status ENUM('draft','live','closed') NOT NULL DEFAULT 'live',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY u_name (org_id, name),
  KEY k_org (org_id, status)
) $t",

"CREATE TABLE IF NOT EXISTS iv_dept_windows (
  round_id INT UNSIGNED NOT NULL,
  department_id INT UNSIGNED NOT NULL,
  org_id INT UNSIGNED NOT NULL,
  closes_at DATETIME NOT NULL,
  PRIMARY KEY (round_id, department_id)
) $t",

"CREATE TABLE IF NOT EXISTS iv_forms (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  round_id INT UNSIGNED NOT NULL,
  department_id INT UNSIGNED NOT NULL,
  title VARCHAR(190) NOT NULL,
  intro TEXT NULL,
  questions_json MEDIUMTEXT NULL,
  token CHAR(32) NOT NULL,
  is_open TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY u_round_dept (round_id, department_id),
  UNIQUE KEY u_token (token)
) $t",

"CREATE TABLE IF NOT EXISTS iv_ideas (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  round_id INT UNSIGNED NOT NULL,
  college_id INT UNSIGNED NOT NULL,
  department_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  form_id INT UNSIGNED NOT NULL,
  title VARCHAR(190) NOT NULL,
  answers_json MEDIUMTEXT NULL,
  status ENUM('submitted','validated','rejected') NOT NULL DEFAULT 'submitted',
  hod_note TEXT NULL,
  hod_rank TINYINT UNSIGNED NULL,
  ai_score DECIMAL(6,2) NULL,
  ai_max DECIMAL(6,2) NULL,
  forwarded_at DATETIME NULL,
  stage VARCHAR(20) NULL,
  stage_at DATETIME NULL,
  owner_note TEXT NULL,
  submitted_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY u_round_student (round_id, student_id),
  KEY k_dept (org_id, round_id, department_id, status),
  KEY k_stage (org_id, round_id, stage)
) $t",

"CREATE TABLE IF NOT EXISTS iv_ai_runs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  idea_id INT UNSIGNED NOT NULL,
  gem_id INT UNSIGNED NULL,
  mode ENUM('portal','own') NOT NULL,
  tool VARCHAR(60) NOT NULL DEFAULT '',
  state ENUM('pending','done') NOT NULL DEFAULT 'pending',
  score DECIMAL(6,2) NULL,
  max_score DECIMAL(6,2) NULL,
  breakdown_json TEXT NULL,
  summary TEXT NULL,
  improvements_json TEXT NULL,
  actor VARCHAR(190) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  KEY k_idea (idea_id, state)
) $t",

"CREATE TABLE IF NOT EXISTS iv_team (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  round_id INT UNSIGNED NOT NULL,
  idea_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  role ENUM('lead','member') NOT NULL DEFAULT 'member',
  added_by VARCHAR(190) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  UNIQUE KEY u_round_student (round_id, student_id),
  KEY k_idea (idea_id)
) $t",

"CREATE TABLE IF NOT EXISTS iv_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  idea_id INT UNSIGNED NOT NULL,
  actor VARCHAR(190) NOT NULL DEFAULT '',
  what VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL,
  KEY k_idea (idea_id, id)
) $t",

"CREATE TABLE IF NOT EXISTS iv_audit (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  actor VARCHAR(190) NOT NULL DEFAULT '',
  action VARCHAR(40) NOT NULL,
  subject VARCHAR(255) NOT NULL DEFAULT '',
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL,
  KEY k_org (org_id, id)
) $t",
    ];
}

function iv_install(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if ((int) fabo_setting('iv_schema', '0') >= IV_SCHEMA_VERSION) {
        return;
    }
    foreach (iv_schema_sql() as $sql) {
        fabo_exec($sql);
    }
    /* 2: what the sheet said about a student beyond who they are (district,
       remarks, the form's other email, the PIN exactly as typed), and a flag
       for a record to look at: a PIN two different students gave, or one
       that is not a PIN at all. */
    $db = fabo_db();
    $cols = [
        'district'   => "VARCHAR(80) NOT NULL DEFAULT ''",
        'alt_email'  => "VARCHAR(160) NOT NULL DEFAULT ''",
        'roll_typed' => "VARCHAR(80) NOT NULL DEFAULT ''",
        'notes'      => 'TEXT NULL',
        'flag'       => "VARCHAR(20) NOT NULL DEFAULT ''",
    ];
    foreach ($cols as $c => $def) {
        if (!$db->query("SHOW COLUMNS FROM iv_students LIKE '$c'")->num_rows) {
            $db->query("ALTER TABLE iv_students ADD COLUMN $c $def");
        }
    }
    if (!$db->query("SHOW INDEX FROM iv_students WHERE Key_name = 'k_flag'")->num_rows) {
        $db->query('ALTER TABLE iv_students ADD KEY k_flag (org_id, flag)');
    }
    /* 3: what each upload did with every row (iv_import_log()). */
    $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    fabo_exec("CREATE TABLE IF NOT EXISTS iv_imports (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      org_id INT UNSIGNED NOT NULL,
      label VARCHAR(120) NOT NULL DEFAULT '',
      rows_read INT UNSIGNED NOT NULL DEFAULT 0,
      students INT UNSIGNED NOT NULL DEFAULT 0,
      repeats INT UNSIGNED NOT NULL DEFAULT 0,
      added INT UNSIGNED NOT NULL DEFAULT 0,
      skipped INT UNSIGNED NOT NULL DEFAULT 0,
      actor VARCHAR(190) NOT NULL DEFAULT '',
      created_at DATETIME NOT NULL,
      KEY k_org (org_id, id)
    ) $t");
    fabo_exec("CREATE TABLE IF NOT EXISTS iv_import_rows (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      import_id INT UNSIGNED NOT NULL,
      org_id INT UNSIGNED NOT NULL,
      line INT UNSIGNED NOT NULL,
      student_id INT UNSIGNED NOT NULL DEFAULT 0,
      outcome VARCHAR(10) NOT NULL,
      first_line INT UNSIGNED NOT NULL DEFAULT 0,
      name VARCHAR(160) NOT NULL DEFAULT '',
      roll_typed VARCHAR(80) NOT NULL DEFAULT '',
      email VARCHAR(160) NOT NULL DEFAULT '',
      phone VARCHAR(40) NOT NULL DEFAULT '',
      college VARCHAR(220) NOT NULL DEFAULT '',
      note VARCHAR(255) NOT NULL DEFAULT '',
      KEY k_import (import_id, outcome, line)
    ) $t");
    /* 4: keeping HoDs honest. The own-tool reply exactly as pasted; whether a
       round lets HoDs use their own AI tool at all; why a HoD ranked their
       picks against the AI scores. */
    $add = [
        ['iv_ai_runs', 'reply_text', 'MEDIUMTEXT NULL'],
        ['iv_rounds', 'own_ai', 'TINYINT(1) NOT NULL DEFAULT 1'],
        ['iv_ideas', 'pick_note', 'TEXT NULL'],
        /* 5: the owner's note to students, on every department's form of the round (HoDs cannot change it). */
        ['iv_rounds', 'form_note', 'TEXT NULL'],
    ];
    foreach ($add as [$tbl, $c, $def]) {
        if (!$db->query("SHOW COLUMNS FROM $tbl LIKE '$c'")->num_rows) {
            $db->query("ALTER TABLE $tbl ADD COLUMN $c $def");
        }
    }
    fabo_exec('INSERT INTO app_settings (k, v) VALUES ("iv_schema", ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', 's', [(string) IV_SCHEMA_VERSION]);
}

/* ================================================================ access */

/** The owner grant that applies: 'none' | 'view' | 'edit'. A client's team member never holds more than their head holds now. */
function iv_grant(array $viewer): string
{
    if (!empty($viewer['is_admin'])) {
        return 'edit';
    }
    if (!fabo_signed_in()) {
        return 'none';
    }
    $p = fabo_module_permission($viewer, 'ideaval');
    $c = $viewer['client'] ?? [];
    $parent = (int) ($c['parent_id'] ?? 0);
    if ($p !== 'none' && ($c['kind'] ?? '') === 'team' && $parent > 0) {
        $cap = (string) (fabo_client_access($parent)['ideaval'] ?? 'none');
        $rank = ['none' => 0, 'view' => 1, 'edit' => 2];
        $p = ($rank[$cap] ?? 0) < $rank[$p] ? (isset($rank[$cap]) ? $cap : 'none') : $p;
    }
    return $p;
}

/** Organisations holding the module, for the administrator's switcher: id => name. Ours (0) first. */
function iv_orgs(): array
{
    $out = [0 => 'Our own'];
    try {
        $out += fabo_module_orgs('ideaval');        // clients at any level, a client's own clients too
    } catch (Throwable $e) {
        // not installed yet: ours only
    }
    return $out;
}

/** Whose Startup Discovery this request is about. A client: their own (a client's client too). The administrator: the one picked (ours by default). */
function iv_org(array $viewer): int
{
    if (empty($viewer['is_admin'])) {
        return fabo_module_org_of($viewer);
    }
    $orgs = iv_orgs();
    $want = isset($_REQUEST['org']) ? (int) $_REQUEST['org'] : null;
    if ($want !== null && isset($orgs[$want])) {
        return $want;
    }
    return 0;
}

function iv_org_name(int $org): string
{
    if ($org <= 0) {
        return fabo_app_name();
    }
    $c = fabo_one('SELECT name, organisation FROM clients WHERE id = ?', 'i', [$org]);
    return trim((string) ($c['organisation'] ?? '')) ?: trim((string) ($c['name'] ?? '')) ?: fabo_app_name();
}

/** The head of the organisation, to send mail under its name. */
function iv_org_actor(int $org): ?array
{
    if ($org <= 0) {
        return null;
    }
    $c = fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [$org]);
    if ($c) {
        $c['send_as_name'] = iv_org_name($org);
    }
    return $c ?: null;
}

/* ============================================================== settings */

function iv_get(int $org, string $k, string $default = ''): string
{
    $r = fabo_one('SELECT v FROM iv_settings WHERE org_id = ? AND k = ?', 'is', [$org, $k]);
    return $r === null ? $default : (string) $r['v'];
}

function iv_set(int $org, string $k, string $v): void
{
    fabo_exec('INSERT INTO iv_settings (org_id, k, v) VALUES (?,?,?) ON DUPLICATE KEY UPDATE v = VALUES(v)', 'iss', [$org, $k, $v]);
}

/** The key in the HoD portal's address that says whose it is. Made on first use. */
function iv_portal_key(int $org): string
{
    $k = iv_get($org, 'portal_key');
    if (!preg_match('/^[0-9a-f]{16}$/', $k)) {
        $k = bin2hex(random_bytes(8));
        iv_set($org, 'portal_key', $k);
    }
    return $k;
}

function iv_org_by_key(string $key): ?int
{
    if (!preg_match('/^[0-9a-f]{16}$/', $key)) {
        return null;
    }
    iv_install();
    $r = fabo_one('SELECT org_id FROM iv_settings WHERE k = "portal_key" AND v = ?', 's', [$key]);
    return $r ? (int) $r['org_id'] : null;
}

/** The site's base address, ending in a slash: the organisation's own address when it has a working one. */
function iv_base_url(int $org): string
{
    require_once __DIR__ . '/../orghost.php';
    if ($org > 0) {
        $own = fabo_org_host($org);
        if ($own !== '' && fabo_one('SELECT web_host_ok_at FROM clients WHERE id = ? AND web_host_ok_at IS NOT NULL', 'i', [$org])) {
            return 'https://' . $own . '/';
        }
    }
    $https = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
          || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    $dir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/')));
    $dir = preg_replace('#/(admin|api|tests)$#', '', $dir);
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim($dir, '/') . '/';
}

function iv_portal_url(int $org, array $q = []): string
{
    return iv_base_url($org) . 'hod.php?' . http_build_query(['o' => iv_portal_key($org)] + $q);
}

function iv_form_url(int $org, string $token): string
{
    return iv_base_url($org) . 'idea-form.php?f=' . rawurlencode($token);
}

/* ================================================================= audit */

function iv_audit(int $org, string $actor, string $action, string $subject = ''): void
{
    try {
        require_once __DIR__ . '/../throttle.php';
        fabo_exec('INSERT INTO iv_audit (org_id, actor, action, subject, ip, created_at) VALUES (?,?,?,?,?,?)', 'isssss',
                  [$org, mb_substr($actor, 0, 190), $action, mb_substr($subject, 0, 255), fabo_client_ip(), iv_now()]);
    } catch (Throwable $e) {
        error_log('FABO iv audit: ' . $e->getMessage());
    }
}

function iv_log(int $org, int $ideaId, string $actor, string $what): void
{
    fabo_exec('INSERT INTO iv_log (org_id, idea_id, actor, what, created_at) VALUES (?,?,?,?,?)', 'iisss',
              [$org, $ideaId, mb_substr($actor, 0, 190), mb_substr($what, 0, 255), iv_now()]);
}

function iv_clean(?string $s, int $max): string
{
    $s = trim(preg_replace('/\s+/u', ' ', (string) $s));
    return mb_substr($s, 0, $max);
}

/** Multi-line text, trimmed, kept to $max characters. */
function iv_text(?string $s, int $max): string
{
    $s = str_replace(["\r\n", "\r"], "\n", (string) $s);
    return mb_substr(trim($s), 0, $max);
}

function iv_tx(callable $fn)
{
    $db = fabo_db();
    $db->begin_transaction();
    try {
        $r = $fn();
        $db->commit();
        return $r;
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

/* ============================================================== colleges */

function iv_college_kinds(): array
{
    return ['Polytechnic', 'College', 'Engineering college', 'School', 'ITI', 'University', 'Other'];
}

function iv_colleges(int $org): array
{
    iv_install();
    return fabo_all('SELECT * FROM iv_colleges WHERE org_id = ? ORDER BY name', 'i', [$org]);
}

function iv_college(int $org, int $id): ?array
{
    return fabo_one('SELECT * FROM iv_colleges WHERE org_id = ? AND id = ?', 'ii', [$org, $id]);
}

/** Adds or updates a college. Returns its id. */
function iv_college_save(int $org, array $d, ?int $id = null): int
{
    $name = iv_clean($d['name'] ?? '', 190);
    if ($name === '') {
        throw new InvalidArgumentException('Give the college or school a name.');
    }
    $code = iv_norm_code((string) ($d['code'] ?? ''));
    $code = $code === '' ? null : $code;
    $kind = in_array($d['kind'] ?? '', iv_college_kinds(), true) ? $d['kind'] : 'College';
    $clash = fabo_one('SELECT id FROM iv_colleges WHERE org_id = ? AND (name = ? OR (code IS NOT NULL AND code = ?))', 'iss', [$org, $name, (string) $code]);
    if ($clash && (int) $clash['id'] !== (int) $id) {
        throw new InvalidArgumentException('There is already a college with that name or code.');
    }
    $district = iv_clean($d['district'] ?? '', 80);
    if ($id) {
        if (!iv_college($org, $id)) {
            throw new InvalidArgumentException('That college is not in this organisation.');
        }
        fabo_exec('UPDATE iv_colleges SET name = ?, code = ?, kind = ?, district = ? WHERE org_id = ? AND id = ?', 'ssssii', [$name, $code, $kind, $district, $org, $id]);
        return $id;
    }
    return fabo_exec('INSERT INTO iv_colleges (org_id, name, code, kind, district) VALUES (?,?,?,?,?)', 'issss', [$org, $name, $code, $kind, $district]);
}

function iv_college_delete(int $org, int $id): void
{
    if (fabo_one('SELECT id FROM iv_departments WHERE org_id = ? AND college_id = ? LIMIT 1', 'ii', [$org, $id])) {
        throw new InvalidArgumentException('Remove its departments first.');
    }
    fabo_exec('UPDATE iv_students SET college_id = 0 WHERE org_id = ? AND college_id = ?', 'ii', [$org, $id]);
    fabo_exec('DELETE FROM iv_colleges WHERE org_id = ? AND id = ?', 'ii', [$org, $id]);
}

/**
 * "155 GOVT POLYTECHNIC, PILLARIPATTU" -> ['155', 'GOVT POLYTECHNIC, PILLARIPATTU'].
 * A value with no leading number is all name.
 */
function iv_split_college(string $v): array
{
    $v = iv_clean($v, 220);
    if (preg_match('/^([0-9]{1,6})\s*[-–.:)]?\s+(.+)$/u', $v, $m)) {
        return [$m[1], trim($m[2])];
    }
    return ['', $v];
}

/** The college for a code and/or name, made when missing and $make. Cached per request. Returns 0 when not found. */
function iv_college_find(int $org, string $code, string $name, string $district = '', bool $make = true, string $kind = 'College'): int
{
    static $cache = [];
    $code = iv_norm_code($code);
    $name = iv_clean($name, 190);
    $ck = $org . '|' . mb_strtolower($code) . '|' . mb_strtolower($name);
    if (isset($cache[$ck])) {
        return $cache[$ck];
    }
    $r = null;
    if ($code !== '') {
        $r = fabo_one('SELECT id FROM iv_colleges WHERE org_id = ? AND code = ?', 'is', [$org, $code]);
    }
    if (!$r && $name !== '') {
        $r = fabo_one('SELECT id FROM iv_colleges WHERE org_id = ? AND name = ?', 'is', [$org, $name]);
    }
    if ($r) {
        return $cache[$ck] = (int) $r['id'];
    }
    if (!$make || $name === '') {
        return 0;
    }
    return $cache[$ck] = iv_college_save($org, ['name' => $name, 'code' => $code, 'district' => $district, 'kind' => $kind]);
}

/* =========================================================== departments */

/**
 * Branch codes as Andhra Pradesh SBTET writes them in a diploma PIN
 * (24045-CM-042), with the department each one is. Used to place imported
 * students and to name departments made on import. Anything else is kept as
 * written.
 */
function iv_branch_names(): array
{
    return [
        'C' => 'Civil Engineering', 'CE' => 'Civil Engineering', 'M' => 'Mechanical Engineering', 'ME' => 'Mechanical Engineering',
        'EE' => 'Electrical and Electronics Engineering', 'EEE' => 'Electrical and Electronics Engineering',
        'EC' => 'Electronics and Communication Engineering', 'ECE' => 'Electronics and Communication Engineering',
        'CM' => 'Computer Engineering', 'CS' => 'Computer Science and Engineering', 'CSE' => 'Computer Science and Engineering',
        'A' => 'Automobile Engineering', 'AA' => 'Automobile Engineering', 'AE' => 'Automobile Engineering',
        'AIM' => 'Artificial Intelligence and Machine Learning', 'AI' => 'Artificial Intelligence',
        'CH' => 'Chemical Engineering', 'PH' => 'Pharmacy', 'AM' => 'Architectural Assistantship', 'MNG' => 'Mining Engineering',
        'AEI' => 'Applied Electronics and Instrumentation', 'EI' => 'Electronics and Instrumentation', 'BM' => 'Biomedical Engineering',
        'CCP' => 'Commercial and Computer Practice', 'MET' => 'Metallurgical Engineering', 'TT' => 'Textile Technology',
        'CIOT' => 'Computer Engineering (IoT)', 'CER' => 'Ceramic Technology', 'MT' => 'Mechatronics', 'MRAC' => 'Refrigeration and Air Conditioning',
        'EV' => 'Electric Vehicle Technology', 'CHST' => 'Chemical Engineering (Sugar Technology)', 'CHPC' => 'Chemical Engineering (Petrochemicals)',
        'CHOT' => 'Chemical Engineering (Oil Technology)', 'ECII' => 'Electronics and Communication (Industry Integrated)',
        'EEVT' => 'Electrical (Electric Vehicle Technology)', 'DCME' => 'Computer Engineering', 'CP' => 'Computer Engineering (Printing)',
        'CCB' => 'Cloud Computing and Big Data', 'VM' => 'Mechanical (Industry Integrated)',
    ];
}

/**
 * "24045-CM-042" -> ['year' => '24', 'college' => '045', 'branch' => 'CM', 'roll' => '24045-CM-042'], or null.
 * Found however the student punctuated it: 24029_ec_039, 24019--EC--002,
 * (24199-EC-049), 24045CM42, "DME(24045-M-001)". The number is written with
 * three digits, so 24045-CM-42 and 24045-CM-042 are one student.
 */
function iv_parse_pin(string $pin): ?array
{
    if (!preg_match('/(?<!\d)(\d{2})(\d{3})\s*[-_.,\s]*\s*([A-Za-z]{1,5})\s*[-_.,\s]*\s*(\d{1,4})(?!\d)(?!\s*[-_]\s*\d)/', $pin, $m)) {
        return null;
    }
    $roll = $m[1] . $m[2] . '-' . strtoupper($m[3]) . '-' . str_pad($m[4], 3, '0', STR_PAD_LEFT);
    return ['year' => $m[1], 'college' => $m[2], 'branch' => strtoupper($m[3]), 'roll' => $roll];
}

function iv_departments(int $org, ?int $collegeId = null): array
{
    iv_install();
    $sql = 'SELECT d.*, c.name AS college_name, c.code AS college_code, c.district,
                   h.id AS hod_id, h.name AS hod_name, h.email AS hod_email, h.phone AS hod_phone, h.status AS hod_status,
                   h.pass_hash IS NOT NULL AS hod_ready, h.invited_at, h.last_login_at, h.ai_limit AS hod_ai_limit
              FROM iv_departments d JOIN iv_colleges c ON c.id = d.college_id
              LEFT JOIN iv_hods h ON h.department_id = d.id
             WHERE d.org_id = ?' . ($collegeId ? ' AND d.college_id = ?' : '') . ' ORDER BY c.name, d.name';
    return $collegeId ? fabo_all($sql, 'ii', [$org, $collegeId]) : fabo_all($sql, 'i', [$org]);
}

function iv_department(int $org, int $id): ?array
{
    return fabo_one('SELECT d.*, c.name AS college_name, c.code AS college_code, c.district FROM iv_departments d JOIN iv_colleges c ON c.id = d.college_id WHERE d.org_id = ? AND d.id = ?', 'ii', [$org, $id]);
}

/** Adds or updates a department of a college. Returns its id. */
function iv_department_save(int $org, int $collegeId, string $name, string $branch = '', ?int $id = null): int
{
    $name = iv_clean($name, 160);
    $branch = strtoupper(iv_clean($branch, 12));
    if ($name === '' && $branch !== '') {
        $name = iv_branch_names()[$branch] ?? $branch;
    }
    if ($name === '') {
        throw new InvalidArgumentException('Give the department a name.');
    }
    if (!iv_college($org, $collegeId)) {
        throw new InvalidArgumentException('Pick the college this department is in.');
    }
    $clash = fabo_one('SELECT id FROM iv_departments WHERE college_id = ? AND (name = ? OR (branch IS NOT NULL AND branch = ?))', 'iss', [$collegeId, $name, $branch]);
    if ($clash && (int) $clash['id'] !== (int) $id) {
        throw new InvalidArgumentException('This college already has a department called ' . $name . ($branch !== '' ? ' or with branch code ' . $branch : '') . '.');
    }
    $b = $branch === '' ? null : $branch;
    if ($id) {
        $old = iv_department($org, $id);
        if (!$old) {
            throw new InvalidArgumentException('That department is not in this organisation.');
        }
        if ((int) $old['college_id'] !== $collegeId && fabo_one('SELECT id FROM iv_ideas WHERE org_id = ? AND department_id = ? LIMIT 1', 'ii', [$org, $id])) {
            throw new InvalidArgumentException('This department already has ideas, so it stays in its college.');
        }
        fabo_exec('UPDATE iv_departments SET college_id = ?, name = ?, branch = ? WHERE org_id = ? AND id = ?', 'issii', [$collegeId, $name, $b, $org, $id]);
        fabo_exec('UPDATE iv_students SET college_id = ? WHERE org_id = ? AND department_id = ?', 'iii', [$collegeId, $org, $id]);
        iv_department_link_students($org, $id);
        return $id;
    }
    $new = fabo_exec('INSERT INTO iv_departments (org_id, college_id, name, branch) VALUES (?,?,?,?)', 'iiss', [$org, $collegeId, $name, $b]);
    iv_department_link_students($org, $new);
    return $new;
}

/**
 * Departments are made for HoD dashboards only (never by a student upload).
 * When one is made or changed, the college's students who are in no
 * department yet and whose PIN carries its branch (24045-CM-042 -> CM, or a
 * code that means the same department: C / CE) join it. Returns how many.
 */
function iv_department_link_students(int $org, int $deptId): int
{
    $d = iv_department($org, $deptId);
    if (!$d) {
        return 0;
    }
    $names = iv_branch_names();
    $n = 0;
    foreach (fabo_all('SELECT id, roll_no FROM iv_students WHERE org_id = ? AND college_id = ? AND department_id = 0 AND roll_no <> ""', 'ii', [$org, (int) $d['college_id']]) as $s) {
        $p = iv_parse_pin((string) $s['roll_no']);
        if (!$p) {
            continue;
        }
        $b = $p['branch'];
        if (($d['branch'] !== null && $b === (string) $d['branch']) || (isset($names[$b]) && $names[$b] === $d['name'])) {
            $n += fabo_exec('UPDATE iv_students SET department_id = ? WHERE id = ? AND department_id = 0', 'ii', [$deptId, (int) $s['id']]) > 0 ? 1 : 0;
        }
    }
    return $n;
}

/**
 * Removes the departments no HoD dashboard uses: no Head, no idea form, no
 * ideas, no extended deadline. Their students stay, in their college.
 * Returns how many departments went.
 */
function iv_departments_prune(int $org): int
{
    $ids = array_map(static fn($r) => (int) $r['id'], fabo_all('SELECT d.id FROM iv_departments d WHERE d.org_id = ?
                     AND NOT EXISTS (SELECT 1 FROM iv_hods h WHERE h.department_id = d.id)
                     AND NOT EXISTS (SELECT 1 FROM iv_forms f WHERE f.department_id = d.id)
                     AND NOT EXISTS (SELECT 1 FROM iv_ideas i WHERE i.department_id = d.id)
                     AND NOT EXISTS (SELECT 1 FROM iv_dept_windows w WHERE w.department_id = d.id)', 'i', [$org]));
    if (!$ids) {
        return 0;
    }
    iv_tx(static function () use ($org, $ids) {
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(',', $chunk);
            fabo_exec("UPDATE iv_students SET department_id = 0 WHERE org_id = ? AND department_id IN ($in)", 'i', [$org]);
            fabo_exec("DELETE FROM iv_departments WHERE org_id = ? AND id IN ($in)", 'i', [$org]);
        }
    });
    return count($ids);
}

/** The department for a branch code or name in a college; made when missing and $make. 0 when none. */
function iv_department_find(int $org, int $collegeId, string $branch, string $name = '', bool $make = true): int
{
    static $cache = [];
    $branch = strtoupper(iv_clean($branch, 12));
    $name = iv_clean($name, 160);
    $ck = $collegeId . '|' . $branch . '|' . mb_strtolower($name);
    if (isset($cache[$ck])) {
        return $cache[$ck];
    }
    $r = null;
    if ($branch !== '') {
        $r = fabo_one('SELECT id FROM iv_departments WHERE org_id = ? AND college_id = ? AND branch = ?', 'iis', [$org, $collegeId, $branch]);
        if (!$r && isset(iv_branch_names()[$branch])) {
            // Two codes can mean one department (C and CE): find it by the name the code stands for.
            $r = fabo_one('SELECT id FROM iv_departments WHERE org_id = ? AND college_id = ? AND name = ?', 'iis', [$org, $collegeId, iv_branch_names()[$branch]]);
        }
    }
    if (!$r && $name !== '') {
        $r = fabo_one('SELECT id FROM iv_departments WHERE org_id = ? AND college_id = ? AND name = ?', 'iis', [$org, $collegeId, $name]);
    }
    if ($r) {
        return $cache[$ck] = (int) $r['id'];
    }
    if (!$make || ($branch === '' && $name === '')) {
        return 0;
    }
    $dname = $name !== '' ? $name : (iv_branch_names()[$branch] ?? $branch);
    // The code is kept only when no department of this college has it yet under another name.
    return $cache[$ck] = iv_department_save($org, $collegeId, $dname, $branch);
}

function iv_department_delete(int $org, int $id): void
{
    if (!iv_department($org, $id)) {
        throw new InvalidArgumentException('That department is not in this organisation.');
    }
    if (fabo_one('SELECT id FROM iv_ideas WHERE org_id = ? AND department_id = ? LIMIT 1', 'ii', [$org, $id])) {
        throw new InvalidArgumentException('This department has ideas, so it cannot be removed. Suspend its Head of Department instead.');
    }
    iv_tx(static function () use ($org, $id) {
        fabo_exec('DELETE FROM iv_hods WHERE org_id = ? AND department_id = ?', 'ii', [$org, $id]);
        fabo_exec('DELETE FROM iv_forms WHERE org_id = ? AND department_id = ?', 'ii', [$org, $id]);
        fabo_exec('DELETE FROM iv_dept_windows WHERE org_id = ? AND department_id = ?', 'ii', [$org, $id]);
        fabo_exec('UPDATE iv_students SET department_id = 0 WHERE org_id = ? AND department_id = ?', 'ii', [$org, $id]);
        fabo_exec('DELETE FROM iv_departments WHERE org_id = ? AND id = ?', 'ii', [$org, $id]);
    });
}

/* ================================================================== HoDs */

function iv_hod(int $org, int $id): ?array
{
    return fabo_one('SELECT * FROM iv_hods WHERE org_id = ? AND id = ?', 'ii', [$org, $id]);
}

function iv_hod_by_email(int $org, string $email): ?array
{
    $email = mb_strtolower(trim($email));
    return $email === '' ? null : fabo_one('SELECT * FROM iv_hods WHERE org_id = ? AND email = ?', 'is', [$org, $email]);
}

function iv_hod_for_dept(int $org, int $deptId): ?array
{
    return fabo_one('SELECT * FROM iv_hods WHERE org_id = ? AND department_id = ?', 'ii', [$org, $deptId]);
}

/**
 * Sets the Head of Department of a department (one per department, one
 * department per HoD email). Returns the HoD's id.
 */
function iv_hod_save(int $org, int $deptId, array $d): int
{
    if (!iv_department($org, $deptId)) {
        throw new InvalidArgumentException('That department is not in this organisation.');
    }
    $name = iv_clean($d['name'] ?? '', 160);
    $email = mb_strtolower(iv_clean($d['email'] ?? '', 160));
    if ($name === '') {
        throw new InvalidArgumentException('Give the Head of Department\'s name.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('The Head of Department needs a valid email: it is what they sign in with.');
    }
    $phone = iv_clean($d['phone'] ?? '', 40);
    $limit = isset($d['ai_limit']) && $d['ai_limit'] !== '' && $d['ai_limit'] !== null ? max(0, min(20, (int) $d['ai_limit'])) : null;
    $mine = iv_hod_for_dept($org, $deptId);
    $other = iv_hod_by_email($org, $email);
    if ($other && (!$mine || (int) $other['id'] !== (int) $mine['id'])) {
        $od = iv_department($org, (int) $other['department_id']);
        throw new InvalidArgumentException($email . ' is already the Head of ' . ($od ? $od['name'] . ', ' . $od['college_name'] : 'another department') . '. One email signs in to one department dashboard.');
    }
    if ($mine) {
        // A new person on the dashboard: the old password and links stop working.
        $newPerson = $mine['email'] !== $email;
        fabo_exec('UPDATE iv_hods SET name = ?, email = ?, phone = ?, ai_limit = ?' . ($newPerson ? ', pass_hash = NULL, invite_hash = NULL, invite_expires = NULL, invited_at = NULL, last_login_at = NULL' : '') . ' WHERE id = ?',
                  'sssii', [$name, $email, $phone, $limit, (int) $mine['id']]);
        return (int) $mine['id'];
    }
    return fabo_exec('INSERT INTO iv_hods (org_id, department_id, name, email, phone, ai_limit) VALUES (?,?,?,?,?,?)', 'iisssi', [$org, $deptId, $name, $email, $phone, $limit]);
}

function iv_hod_status(int $org, int $id, string $status): void
{
    fabo_exec('UPDATE iv_hods SET status = ? WHERE org_id = ? AND id = ?', 'sii', [$status === 'suspended' ? 'suspended' : 'active', $org, $id]);
}

function iv_hod_limit_set(int $org, int $id, ?int $limit): void
{
    fabo_exec('UPDATE iv_hods SET ai_limit = ? WHERE org_id = ? AND id = ?', 'iii', [$limit === null ? null : max(0, min(20, $limit)), $org, $id]);
}

/** A fresh sign-in link for one HoD; only its hash is kept. Returns the full address. */
function iv_invite_make(int $org, int $hodId): string
{
    $h = iv_hod($org, $hodId);
    if (!$h) {
        throw new InvalidArgumentException('That Head of Department is not in this organisation.');
    }
    $token = bin2hex(random_bytes(24));
    fabo_exec('UPDATE iv_hods SET invite_hash = ?, invite_expires = ?, invited_at = ? WHERE org_id = ? AND id = ?',
              'sssii', [hash('sha256', $token), iv_now(IV_INVITE_DAYS * 86400), iv_now(), $org, $hodId]);
    return iv_portal_url($org, ['invite' => $token]);
}

function iv_invite_hod(string $token): ?array
{
    if (!preg_match('/^[0-9a-f]{48}$/', $token)) {
        return null;
    }
    iv_install();
    return fabo_one('SELECT * FROM iv_hods WHERE invite_hash = ? AND invite_expires > ? AND status = "active"', 'ss', [hash('sha256', $token), iv_now()]);
}

function iv_password_problem(string $plain, ?array $h = null): ?string
{
    if (mb_strlen($plain) < 10) {
        return 'Use at least 10 characters.';
    }
    if (mb_strlen($plain) > 200) {
        return 'That is too long.';
    }
    $low = mb_strtolower($plain);
    if (preg_match('/^(.)\1+$/u', $plain) || preg_match('/^(password|qwerty|123456|welcome|admin|abcdef)/', $low)) {
        return 'That password is too easy to guess. Try three or four unrelated words.';
    }
    if ($h) {
        $local = mb_strtolower((string) strtok((string) ($h['email'] ?? ''), '@'));
        if (mb_strlen($local) >= 4 && str_contains($low, $local)) {
            return 'Leave your email out of your password.';
        }
    }
    return null;
}

function iv_password_set(int $org, int $hodId, string $plain, bool $temporary = false): void
{
    $h = iv_hod($org, $hodId);
    if (!$h) {
        throw new InvalidArgumentException('That Head of Department is not in this organisation.');
    }
    if ($why = $temporary ? (mb_strlen($plain) < 10 ? 'Use at least 10 characters.' : null) : iv_password_problem($plain, $h)) {
        throw new InvalidArgumentException($why);
    }
    fabo_exec('UPDATE iv_hods SET pass_hash = ?, pass_set_at = ?, must_change = ?, invite_hash = NULL, invite_expires = NULL WHERE org_id = ? AND id = ?',
              'ssiii', [password_hash($plain, PASSWORD_DEFAULT), iv_now(), $temporary ? 1 : 0, $org, $hodId]);
}

/** The HoD whose email and password these are, or null. Takes the same time either way. */
function iv_check_login(int $org, string $email, string $plain): ?array
{
    $h = iv_hod_by_email($org, $email);
    $hash = (string) ($h['pass_hash'] ?? '');
    $ok = password_verify($plain, $hash !== '' ? $hash : '$2y$10$abcdefghijklmnopqrstuuK8bS2wW3Yc7FzHnV7l7n0aGq6zjTqS');
    if (!$h || $hash === '' || !$ok || $h['status'] !== 'active') {
        return null;
    }
    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        fabo_exec('UPDATE iv_hods SET pass_hash = ? WHERE id = ?', 'si', [password_hash($plain, PASSWORD_DEFAULT), (int) $h['id']]);
    }
    return $h;
}

/** Changes whenever the password or the email does, so either change signs out every other session. */
function iv_fingerprint(array $h): string
{
    return hash('sha256', 'iv|' . (int) $h['id'] . '|' . (string) $h['email'] . '|' . (string) ($h['pass_hash'] ?? ''));
}

/** Sends one email, or hands it to the test hook. Returns [sent or queued?, words]. */
function iv_send(int $org, string $to, string $subject, string $text, string $html = '', string $kind = 'iv'): array
{
    if (!empty($GLOBALS['iv_mail_hook']) && is_callable($GLOBALS['iv_mail_hook'])) {     // tests: nothing leaves the machine
        ($GLOBALS['iv_mail_hook'])($to, $subject, $text);
        return [true, 'Sent to ' . $to . '.'];
    }
    if (str_ends_with($to, '.example')) {
        return [false, 'A sample address: nothing is sent.'];
    }
    require_once __DIR__ . '/../outbox.php';
    [$sent, $why, $queued] = fabo_post_or_queue($to, $subject, $text, $html, null, $kind, iv_org_actor($org));
    if ($sent) {
        return [true, 'Sent to ' . $to . '.'];
    }
    return [(bool) $queued, $queued ? 'The mail server is busy; it goes to ' . $to . ' shortly.' : 'Could not send: ' . $why];
}

/** Emails a HoD their dashboard sign-in link. Returns [sent?, words, link]. */
function iv_invite_send(int $org, int $hodId, bool $reset = false): array
{
    $h = iv_hod($org, $hodId);
    if (!$h) {
        return [false, 'That Head of Department is not in this organisation.', ''];
    }
    $d = iv_department($org, (int) $h['department_id']);
    $url = iv_invite_make($org, $hodId);
    $orgName = iv_org_name($org);
    $where = $d ? $d['name'] . ', ' . $d['college_name'] : 'your department';
    $subject = $reset ? 'Reset your ' . $orgName . ' HoD dashboard password' : 'Your Head of Department dashboard — ' . $orgName;
    $lead = $reset ? 'Somebody asked to reset the password for your Head of Department dashboard.'
                   : 'A Head of Department dashboard has been made for you for ' . $where . '. From it you share the idea form with your students, validate their ideas with AI, and send your top ideas forward.';
    $text = 'Dear ' . $h['name'] . ",\n\n" . $lead . "\n\nSet your password here (the link works for " . IV_INVITE_DAYS . " days):\n" . $url
          . "\n\nYou sign in with: " . $h['email'] . "\n\n" . $orgName;
    $html = '<p>Dear ' . htmlspecialchars($h['name']) . ',</p><p>' . htmlspecialchars($lead) . '</p>'
          . '<p><a href="' . htmlspecialchars($url) . '" style="display:inline-block;padding:10px 18px;background:#1d5fd0;color:#fff;border-radius:8px;text-decoration:none;font-weight:600">Open my dashboard</a></p>'
          . '<p style="color:#5f6979;font-size:13px">The link works for ' . IV_INVITE_DAYS . ' days. You sign in with <strong>' . htmlspecialchars($h['email']) . '</strong>.</p>'
          . '<p>' . htmlspecialchars($orgName) . '</p>';
    [$ok, $words] = iv_send($org, (string) $h['email'], $subject, $text, $html, 'iv invite ' . $hodId);
    return [$ok, $words, $url];
}

/* =============================================================== students */

function iv_norm_email(string $s): string
{
    $s = mb_strtolower(trim($s));
    return filter_var($s, FILTER_VALIDATE_EMAIL) ? mb_substr($s, 0, 160) : '';
}

/** The last ten digits of an Indian mobile number, or '' when it does not look like one. */
function iv_norm_phone(string $s): string
{
    $d = preg_replace('/\D+/', '', $s);
    if (strlen($d) > 10 && str_starts_with($d, '91')) {
        $d = substr($d, -10);
    }
    return strlen($d) >= 8 && strlen($d) <= 15 ? $d : '';
}

function iv_norm_roll(string $s): string
{
    if ($p = iv_parse_pin($s)) {
        return $p['roll'];
    }
    return mb_substr(strtoupper(preg_replace('/\s+/', '', trim($s))), 0, 40);
}

/**
 * May this roll number tell one student from another? A diploma PIN may; so
 * may anything with letters and digits, or a long number. Not what students
 * type when they do not know their PIN: their postal PIN code (515301), "0",
 * "1234567890", a date.
 */
function iv_roll_is_key(string $roll): bool
{
    if ($roll === '') {
        return false;
    }
    if (iv_parse_pin($roll)) {
        return true;
    }
    if (preg_match('/\d{4}-\d{2}-\d{2}|\d{2}[-\/.]\d{2}[-\/.]\d{4}/', $roll)) {
        return false;                       // a date
    }
    $digits = preg_replace('/\D/', '', $roll);
    $hasLetter = (bool) preg_match('/[A-Z]/i', $roll);
    if ($hasLetter) {
        return strlen($digits) >= 3 && mb_strlen($roll) >= 6;
    }
    if (strlen($digits) < 8 || preg_match('/^(\d)\1+$/', $digits) || in_array($digits, ['1234567890', '0123456789', '9876543210', '12345678', '123456789'], true)) {
        return false;
    }
    return true;
}

/** A name as words: lower case, letters only, without titles. */
function iv_name_words(string $name): array
{
    $n = mb_strtolower(preg_replace('/[^\p{L}\s]+/u', ' ', $name));
    $n = preg_replace('/\b(mr|mrs|ms|miss|dr|shri|sri|smt|kumari|kum)\b/u', ' ', $n);
    return array_values(array_filter(preg_split('/\s+/u', trim($n)), static fn($w) => $w !== ''));
}

/**
 * Could these two names be one person? "Vempatibabu" / "Vempati Babu",
 * "B.Mounika" / "BOYA MOUNIKA", "G Naga Ganesh Eswar" / "Gangisetty Naga
 * Ganesh Eswar" -- yes. "P SASIDHAR" / "P SPANDANA" -- no. An empty name
 * says nothing either way.
 */
function iv_names_alike(string $a, string $b): bool
{
    $wa = iv_name_words($a);
    $wb = iv_name_words($b);
    if (!$wa || !$wb) {
        return true;
    }
    // Initials aside ("V HIMASEKHAR" / "VARANASI HIMA SEKHAR"), and run together ("Mannetimanikanthreedy").
    $full = static fn(array $w) => array_values(array_filter($w, static fn($x) => mb_strlen($x) >= 2));
    $fa = $full($wa) ?: $wa;
    $fb = $full($wb) ?: $wb;
    $ja = implode('', $fa);
    $jb = implode('', $fb);
    if ($ja === $jb) {
        return true;
    }
    if ((mb_strlen($ja) >= 5 && str_contains($jb, $ja)) || (mb_strlen($jb) >= 5 && str_contains($ja, $jb))) {
        return true;
    }
    if (mb_strlen($ja) >= 6 && mb_strlen($jb) >= 6 && levenshtein($ja, $jb) <= 2) {
        return true;                        // "DUDEKUALA" / "DUDEKULA", "Amarnath" / "Amaranath"
    }
    foreach ([[$fa, $jb], [$fb, $ja]] as [$words, $other]) {
        foreach ($words as $w) {
            if (mb_strlen($w) >= 4 && str_contains($other, $w)) {
                return true;                // "Manneti Manikanth Reddy" / "Mannetimanikanthreedy"
            }
        }
    }
    foreach ($fa as $x) {
        foreach ($fb as $y) {
            if ($x === $y && mb_strlen($x) >= 3) {
                return true;
            }
            if (mb_strlen($x) >= 5 && mb_strlen($y) >= 5 && levenshtein($x, $y) <= 1) {
                return true;                // "Laskshmi" / "lakshmi"
            }
        }
    }
    return false;
}

/**
 * Is this record the person these details describe? Only when the name could
 * be theirs: a team lead's email or phone on a team-mate's row, or a parent's
 * on two children's, never makes two people one. With no name to compare
 * (an exact lookup), the same email or phone decides.
 */
function iv_same_person(array $rec, string $name, string $email, string $phone): bool
{
    if (trim($name) !== '' && trim((string) $rec['name']) !== '') {
        return iv_names_alike((string) $rec['name'], $name);
    }
    if ($email !== '' && ($email === (string) $rec['email'] || $email === (string) ($rec['alt_email'] ?? ''))) {
        return true;
    }
    if ($phone !== '' && $phone === (string) $rec['phone']) {
        return true;
    }
    return true;
}

/**
 * The key a new or changed record is stored under: its PIN when that tells
 * students apart and nobody else holds it, else its email, else its phone,
 * else one of its own. Never another record's key.
 */
function iv_student_ukey(int $org, string $roll, string $email, string $phone, int $self = 0): string
{
    $free = static function (string $k) use ($org, $self): bool {
        $r = fabo_one('SELECT id FROM iv_students WHERE org_id = ? AND ukey = ?', 'is', [$org, $k]);
        return !$r || (int) $r['id'] === $self;
    };
    foreach ([iv_roll_is_key($roll) ? 'r:' . $roll : '', $email !== '' ? 'e:' . $email : '', $phone !== '' ? 'p:' . $phone : ''] as $k) {
        if ($k !== '' && $free($k)) {
            return $k;
        }
    }
    return 'x:' . bin2hex(random_bytes(8));
}

function iv_student(int $org, int $id): ?array
{
    return fabo_one('SELECT s.*, c.name AS college_name, c.code AS college_code, c.district AS college_district, d.name AS dept_name, d.branch AS dept_branch
                       FROM iv_students s
                       LEFT JOIN iv_colleges c ON c.id = s.college_id LEFT JOIN iv_departments d ON d.id = s.department_id
                      WHERE s.org_id = ? AND s.id = ?', 'ii', [$org, $id]);
}

/**
 * The student these details belong to, or null.
 *
 * A PIN finds its record only when the person matches too (same email, same
 * phone, or a name that could be theirs): two different students who typed
 * one PIN stay two records. Without a PIN, an email or phone finds a record
 * only for the same person and never one that has a different PIN of its
 * own: brothers and sisters often share a parent's email or phone. $name ''
 * skips the person check (an exact lookup).
 */
function iv_student_match(int $org, string $roll, string $email, string $phone, string $name = ''): ?array
{
    $rk = iv_roll_is_key($roll);
    if ($rk) {
        foreach (fabo_all('SELECT * FROM iv_students WHERE org_id = ? AND roll_no = ? ORDER BY ukey = ? DESC, id LIMIT 20', 'iss', [$org, $roll, 'r:' . $roll]) as $r) {
            if ($name === '' || iv_same_person($r, $name, $email, $phone)) {
                return $r;
            }
        }
    }
    $ownPin = static fn(array $r) => (string) $r['roll_no'] !== '' && (string) $r['roll_no'] !== $roll && iv_roll_is_key((string) $r['roll_no']);
    if ($email !== '') {
        foreach (fabo_all('SELECT * FROM iv_students WHERE org_id = ? AND (email = ? OR alt_email = ?) ORDER BY id LIMIT 25', 'iss', [$org, $email, $email]) as $r) {
            if ($rk && $ownPin($r)) {
                continue;
            }
            if ($name === '' || iv_names_alike((string) $r['name'], $name)) {
                return $r;
            }
        }
    }
    if ($phone !== '') {
        foreach (fabo_all('SELECT * FROM iv_students WHERE org_id = ? AND phone = ? ORDER BY id LIMIT 25', 'is', [$org, $phone]) as $r) {
            if ($rk && $ownPin($r)) {
                continue;
            }
            if ($name === '' || iv_names_alike((string) $r['name'], $name)) {
                return $r;
            }
        }
    }
    return null;
}

/**
 * Adds a student, or updates the one these details already belong to (never
 * a second row for the same person, never one row for two people). An empty
 * value never overwrites one already on the record. $id forces an update of
 * that row (an edit: what the form says, even when cleared).
 * $d may also carry: district, alt_email, notes, roll_typed (the PIN as typed).
 * Returns [id, 'added' | 'updated' | 'same'].
 */
function iv_student_save(int $org, array $d, ?int $id = null, string $source = 'manual'): array
{
    $name = iv_clean($d['name'] ?? '', 160);
    $typed = iv_clean((string) ($d['roll_typed'] ?? ($d['roll_no'] ?? '')), 80);
    $roll = iv_norm_roll((string) ($d['roll_no'] ?? ''));
    $email = iv_norm_email((string) ($d['email'] ?? ''));
    $alt = iv_norm_email((string) ($d['alt_email'] ?? ''));
    if ($email === '' && $alt !== '') {
        [$email, $alt] = [$alt, ''];
    }
    if ($alt === $email) {
        $alt = '';
    }
    $phone = iv_norm_phone((string) ($d['phone'] ?? ''));
    if (($d['email'] ?? '') !== '' && $email === '' && $id === null && $roll === '' && $phone === '') {
        throw new InvalidArgumentException('The email "' . iv_clean($d['email'], 80) . '" does not look right.');
    }
    $college = (int) ($d['college_id'] ?? 0);
    $dept = (int) ($d['department_id'] ?? 0);
    if ($dept) {
        $dr = iv_department($org, $dept);
        if (!$dr) {
            throw new InvalidArgumentException('That department is not in this organisation.');
        }
        $college = (int) $dr['college_id'];
    } elseif ($college && !iv_college($org, $college)) {
        throw new InvalidArgumentException('That college is not in this organisation.');
    }
    $year = iv_clean((string) ($d['year_of_study'] ?? ''), 20);
    $district = iv_clean((string) ($d['district'] ?? ''), 80);
    $notes = iv_text((string) ($d['notes'] ?? ''), 2000);
    $have = $id ? fabo_one('SELECT * FROM iv_students WHERE org_id = ? AND id = ?', 'ii', [$org, $id]) : iv_student_match($org, $roll, $email, $phone, $name);
    if ($id && !$have) {
        throw new InvalidArgumentException('That student is not in this organisation.');
    }
    if ($have) {
        /* The same student registering again: what is already on the record
           stays (the first name and phone seen), so uploading one sheet twice
           changes nothing; gaps are filled. Corrections are made on the
           student's page. */
        $new = [
            'name' => (string) $have['name'] !== '' ? $have['name'] : $name,
            // A real PIN on the record stays; a real PIN replaces a missing or unusable one.
            'roll_no' => iv_roll_is_key((string) $have['roll_no']) ? $have['roll_no']
                       : (iv_roll_is_key($roll) ? $roll : ((string) $have['roll_no'] !== '' ? $have['roll_no'] : $roll)),
            'email' => $have['email'] !== '' ? $have['email'] : $email,
            'phone' => (string) $have['phone'] !== '' ? $have['phone'] : $phone,
            // Placed already: stays placed. Not yet (or only a college): this row may place them.
            'college_id' => (int) $have['department_id'] ? (int) $have['college_id'] : ($dept ? $college : ((int) $have['college_id'] ?: $college)),
            'department_id' => (int) $have['department_id'] ?: $dept,
            'year_of_study' => (string) $have['year_of_study'] !== '' ? $have['year_of_study'] : $year,
            'district' => (string) $have['district'] !== '' ? $have['district'] : $district,
            'alt_email' => $have['alt_email'],
            'roll_typed' => $have['roll_typed'] !== '' ? $have['roll_typed'] : $typed,
            'notes' => (string) $have['notes'],
        ];
        // A second email for the same person is kept beside the first, not over it.
        foreach ([$email, $alt] as $e) {
            if ($e !== '' && $e !== $new['email'] && $new['alt_email'] === '') {
                $new['alt_email'] = $e;
            }
        }
        if ($notes !== '' && !str_contains($new['notes'], $notes)) {
            $new['notes'] = mb_substr(trim($new['notes'] . ($new['notes'] !== '' ? "\n" : '') . $notes), 0, 2000);
        }
        if ($source === 'form') {   // a student's own form fills gaps only: the roster's details win
            foreach (['name', 'roll_no', 'email', 'phone'] as $k) {
                if ((string) $have[$k] !== '') {
                    $new[$k] = $have[$k];
                }
            }
        }
        if ($id) {          // an edit: what the form says, even when cleared
            $new = ['name' => $name ?: $have['name'], 'roll_no' => $roll, 'email' => $email, 'phone' => $phone,
                    'college_id' => $college, 'department_id' => $dept, 'year_of_study' => $year,
                    'district' => $district, 'alt_email' => $alt, 'roll_typed' => $typed, 'notes' => $notes];
            if (iv_roll_is_key($roll) && ($o = fabo_one('SELECT name FROM iv_students WHERE org_id = ? AND roll_no = ? AND id <> ? LIMIT 1', 'isi', [$org, $roll, $id]))) {
                throw new InvalidArgumentException('Another student (' . $o['name'] . ') already has that roll number / PIN.');
            }
        }
        if ($new['name'] === '' || ($new['roll_no'] === '' && $new['email'] === '' && $new['phone'] === '')) {
            throw new InvalidArgumentException('A student needs a name and a roll number / PIN, an email or a phone number.');
        }
        $ukey = iv_student_ukey($org, (string) $new['roll_no'], (string) $new['email'], (string) $new['phone'], (int) $have['id']);
        // Keep the key it has when it still describes it: a key never moves for nothing.
        if (in_array($have['ukey'], ['r:' . $new['roll_no'], 'e:' . $new['email'], 'p:' . $new['phone']], true) && !str_starts_with($ukey, 'r:')) {
            $ukey = $have['ukey'];
        }
        $flag = iv_student_flag($org, (string) $new['roll_no'], (int) $have['id']);
        $same = true;
        foreach (['name', 'roll_no', 'email', 'phone', 'year_of_study', 'district', 'alt_email', 'roll_typed', 'notes'] as $k) {
            $same = $same && (string) $new[$k] === (string) $have[$k];
        }
        $same = $same && (int) $new['college_id'] === (int) $have['college_id'] && (int) $new['department_id'] === (int) $have['department_id']
             && $ukey === $have['ukey'] && $flag === (string) $have['flag'];
        if ($same) {
            return [(int) $have['id'], 'same'];
        }
        fabo_exec('UPDATE iv_students SET name = ?, roll_no = ?, email = ?, phone = ?, college_id = ?, department_id = ?, year_of_study = ?, ukey = ?,
                          district = ?, alt_email = ?, roll_typed = ?, notes = ?, flag = ? WHERE id = ?',
                  'ssssiisssssssi', [$new['name'], $new['roll_no'], $new['email'], $new['phone'], $new['college_id'], $new['department_id'], $new['year_of_study'], $ukey,
                                     $new['district'], $new['alt_email'], $new['roll_typed'], $new['notes'] !== '' ? $new['notes'] : null, $flag, (int) $have['id']]);
        return [(int) $have['id'], 'updated'];
    }
    if ($name === '') {
        throw new InvalidArgumentException('Give the student\'s name.');
    }
    if ($roll === '' && $email === '' && $phone === '') {
        throw new InvalidArgumentException('A student needs a roll number / PIN, an email or a phone number.');
    }
    $ukey = iv_student_ukey($org, $roll, $email, $phone);
    $flag = iv_student_flag($org, $roll, 0);
    $sid = fabo_exec('INSERT INTO iv_students (org_id, college_id, department_id, name, email, phone, roll_no, year_of_study, ukey, source, source_ref, district, alt_email, roll_typed, notes, flag)
                      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                     'iiisssssssssssss', [$org, $college, $dept, $name, $email, $phone, $roll, $year, $ukey, mb_substr($source, 0, 20), iv_clean((string) ($d['source_ref'] ?? ''), 60),
                                          $district, $alt, $typed, $notes !== '' ? $notes : null, $flag]);
    return [$sid, 'added'];
}

/** What to look at on a record: '' | 'pin_shared' (another student gave this PIN) | 'pin_unclear' (what was typed is not a PIN). */
/**
 * Records are no longer marked for a look (the owner asked: what students
 * typed is correct; several students of one college may give one value).
 * Kept so the column stays empty; matching still never makes two people one.
 */
function iv_student_flag(int $org, string $roll, int $self): string
{
    return '';
}

function iv_flag_label(string $flag): string
{
    return '';
}

/** The PIN / roll number as the student typed it (what screens and exports show). */
function iv_roll_shown(array $s): string
{
    $t = trim((string) ($s['roll_typed'] ?? ''));
    return $t !== '' ? $t : (string) ($s['roll_no'] ?? '');
}

/**
 * Removes every student of the organisation who has no idea and sits on no
 * team, to load the list again from a corrected sheet. Returns how many went.
 */
function iv_students_clear(int $org): int
{
    return fabo_exec('DELETE FROM iv_students WHERE org_id = ?
                         AND id NOT IN (SELECT student_id FROM iv_ideas WHERE org_id = ?)
                         AND id NOT IN (SELECT student_id FROM iv_team WHERE org_id = ?)', 'iii', [$org, $org, $org]);
}

function iv_student_delete(int $org, int $id): void
{
    if (fabo_one('SELECT id FROM iv_ideas WHERE org_id = ? AND student_id = ? LIMIT 1', 'ii', [$org, $id])
        || fabo_one('SELECT id FROM iv_team WHERE org_id = ? AND student_id = ? LIMIT 1', 'ii', [$org, $id])) {
        throw new InvalidArgumentException('This student has an idea or sits on a team, so the record stays.');
    }
    fabo_exec('DELETE FROM iv_students WHERE org_id = ? AND id = ?', 'ii', [$org, $id]);
}

/** Students, filtered: college, dept (-1 = none yet), q (name, roll, email, phone). Returns [rows, total]. */
function iv_students(int $org, array $f = [], int $limit = 100, int $offset = 0): array
{
    iv_install();
    $w = ['s.org_id = ?'];
    $t = 'i';
    $p = [$org];
    if (!empty($f['college'])) {
        $w[] = 's.college_id = ?';
        $t .= 'i';
        $p[] = (int) $f['college'];
    }
    if (isset($f['dept']) && (int) $f['dept'] !== 0) {
        $w[] = 's.department_id = ?';
        $t .= 'i';
        $p[] = (int) $f['dept'] < 0 ? 0 : (int) $f['dept'];
    }
    if (!empty($f['flag'])) {
        if ($f['flag'] === 'any') {
            $w[] = 's.flag <> ""';
        } else {
            $w[] = 's.flag = ?';
            $t .= 's';
            $p[] = (string) $f['flag'];
        }
    }
    $q = trim((string) ($f['q'] ?? ''));
    if ($q !== '') {
        $w[] = '(s.name LIKE ? OR s.roll_no LIKE ? OR s.email LIKE ? OR s.phone LIKE ? OR s.alt_email LIKE ? OR s.roll_typed LIKE ?)';
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $t .= 'ssssss';
        array_push($p, $like, $like);
        array_push($p, $like, $like, $like, $like);
    }
    $where = implode(' AND ', $w);
    $total = (int) (fabo_one("SELECT COUNT(*) n FROM iv_students s WHERE $where", $t, $p)['n'] ?? 0);
    $rows = fabo_all("SELECT s.*, s.roll_no AS pin_key, COALESCE(NULLIF(s.roll_typed, ''), s.roll_no) AS roll_no, c.name AS college_name, d.name AS dept_name FROM iv_students s
                        LEFT JOIN iv_colleges c ON c.id = s.college_id LEFT JOIN iv_departments d ON d.id = s.department_id
                       WHERE $where ORDER BY c.name, d.name, s.name LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset), $t, $p);
    return [$rows, $total];
}

/* ============================================================ table files */

/** Header words people actually type, mapped to our columns. */
function iv_header_col(string $h): string
{
    $h = strtolower(trim(preg_replace('/[^a-z0-9]+/i', ' ', $h)));
    $map = [
        'name' => 'name', 'full name' => 'name', 'student name' => 'name', 'full name of the student' => 'name', 'name of the student' => 'name',
        // "Email ID" is the student's own; "Email Address" (the Google Form's sign-in address) is used only when it is empty.
        'email' => 'email', 'email id' => 'email', 'e mail' => 'email', 'mail' => 'email', 'mail id' => 'email', 'e mail id' => 'email',
        'email address' => 'email_alt',
        'phone' => 'phone', 'mobile' => 'phone', 'mobile number' => 'phone', 'phone number' => 'phone', 'mobile no' => 'phone', 'contact' => 'phone',
        'contact number' => 'phone', 'contact no' => 'phone', 'phone no' => 'phone', 'cell' => 'phone', 'cell number' => 'phone',
        'roll no' => 'roll_no', 'roll number' => 'roll_no', 'roll' => 'roll_no', 'pin' => 'roll_no', 'pin no' => 'roll_no', 'pin number' => 'roll_no',
        'pin xxxxx xx xxx' => 'roll_no', 'hall ticket' => 'roll_no', 'hall ticket number' => 'roll_no', 'registration number' => 'roll_no', 'reg no' => 'roll_no', 'admission number' => 'roll_no',
        'college' => 'college', 'school' => 'college', 'institution' => 'college', 'institute' => 'college', 'polytechnic' => 'college', 'college name' => 'college',
        'school name' => 'college', 'code and name of the polytechnic' => 'college', 'name of the polytechnic' => 'college', 'name of the college' => 'college',
        'college code' => 'college_code', 'school code' => 'college_code', 'institution code' => 'college_code', 'code' => 'college_code',
        'district' => 'district', 'name of the district' => 'district',
        'department' => 'department', 'dept' => 'department', 'branch' => 'department', 'department name' => 'department', 'branch name' => 'department',
        'branch code' => 'branch', 'department code' => 'branch', 'dept code' => 'branch',
        'year' => 'year', 'year of study' => 'year', 'semester' => 'year', 'class' => 'year',
        'hod name' => 'hod_name', 'head of department' => 'hod_name', 'hod' => 'hod_name', 'head of the department' => 'hod_name', 'name of hod' => 'hod_name',
        'name of the head of section' => 'hod_name', 'head of section' => 'hod_name', 'name of the head of the section' => 'hod_name', 'hos' => 'hod_name',
        'name of head of section' => 'hod_name', 'name of the head of department' => 'hod_name', 'name of the hod' => 'hod_name', 'hos name' => 'hod_name',
        'hod email' => 'hod_email', 'hod email id' => 'hod_email', 'hod mail' => 'hod_email', 'email of hod' => 'hod_email',
        'hod phone' => 'hod_phone', 'hod mobile' => 'hod_phone', 'hod mobile number' => 'hod_phone', 'hod phone number' => 'hod_phone',
        'type' => 'kind', 'college type' => 'kind',
        'remarks' => 'notes', 'remark' => 'notes', 'notes' => 'notes', 'note' => 'notes', 'comments' => 'notes', 'comment' => 'notes',
    ];
    return $map[$h] ?? '';
}

/**
 * Reads an uploaded .csv or .xlsx into [header cells, [row cells...]].
 * $name is the original file name (decides the format).
 */
function iv_read_table(string $path, string $name): array
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $rows = [];
    if ($ext === 'xlsx') {
        require_once __DIR__ . '/../cw/xlsx.php';
        $book = cw_xlsx_read($path);
        $sheet = reset($book) ?: [];
        foreach ($sheet as $cells) {
            $line = [];
            foreach ($cells as $col => $v) {
                $n = 0;
                foreach (str_split((string) $col) as $ch) {
                    $n = $n * 26 + (ord($ch) - 64);
                }
                $line[$n - 1] = trim((string) $v);
            }
            if ($line) {
                $max = max(array_keys($line));
                $full = [];
                for ($i = 0; $i <= $max; $i++) {
                    $full[] = $line[$i] ?? '';
                }
                $rows[] = $full;
            }
        }
    } elseif ($ext === 'csv' || $ext === 'txt') {
        $fh = @fopen($path, 'rb');
        if (!$fh) {
            throw new InvalidArgumentException('Could not read the file.');
        }
        $first = (string) fgets($fh);
        $delim = substr_count($first, ';') > substr_count($first, ',') ? ';' : (substr_count($first, "\t") > substr_count($first, ',') ? "\t" : ',');
        // Read again from the top with the CSV reader: a header cell may hold a line break ("\nPhone Number").
        rewind($fh);
        if (fread($fh, 3) !== "\xEF\xBB\xBF") {          // Excel's byte-order mark
            rewind($fh);
        }
        while (($cells = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
            $rows[] = array_map(static fn($c) => trim((string) $c), $cells);
        }
        fclose($fh);
    } else {
        throw new InvalidArgumentException('Upload a .csv or .xlsx file.');
    }
    $rows = array_values(array_filter($rows, static fn($r) => implode('', $r) !== ''));
    if (!$rows) {
        throw new InvalidArgumentException('The file is empty.');
    }
    $head = array_shift($rows);
    return [$head, $rows];
}

/**
 * Each row as [our column => value], using the header. $for 'dashboards':
 * a sheet of Heads, where a plain Name / Email ID / Contact Number column is
 * the Head's own.
 */
function iv_table_records(array $head, array $rows, string $for = 'students'): array
{
    $cols = array_map('iv_header_col', $head);
    if ($for === 'dashboards') {
        $rename = ['name' => 'hod_name', 'email' => 'hod_email', 'email_alt' => 'hod_email', 'phone' => 'hod_phone'];
        foreach ($cols as $i => $c) {
            if (isset($rename[$c]) && !in_array($rename[$c], $cols, true)) {
                $cols[$i] = $rename[$c];
            }
        }
    }
    $out = [];
    foreach ($rows as $cells) {
        $r = [];
        foreach ($cols as $i => $c) {
            if ($c !== '' && (!isset($r[$c]) || $r[$c] === '')) {
                $r[$c] = trim((string) ($cells[$i] ?? ''));
            }
        }
        if (($r['email'] ?? '') === '' && ($r['email_alt'] ?? '') !== '') {
            $r['email'] = $r['email_alt'];
        }
        if (($r['hod_email'] ?? '') === '' && ($r['email_alt'] ?? '') !== '' && $for === 'dashboards') {
            $r['hod_email'] = $r['email_alt'];
        }
        // The other email stays with the record (kept on the student as their second address).
        $out[] = $r;
    }
    $cols = array_map(static fn($c) => $c === 'email_alt' ? 'email' : $c, $cols);
    return [$cols, $out];
}

/**
 * What a sheet's Branch / Department cell means: [branch code, department name].
 * "CM", "DCME", "Computer Engg." and "Computer Engineering" all come back as
 * code CM; a name not recognised is kept as the department's name.
 */
function iv_branch_split(string $dept, string $branch = ''): array
{
    $branch = strtoupper(preg_replace('/[^A-Za-z]/', '', $branch));
    $dept = iv_clean($dept, 160);
    if ($branch !== '' || $dept === '') {
        return [$branch, $dept];
    }
    $g = iv_branch_guess($dept);
    if ($g !== '') {
        return [$g, ''];
    }
    $letters = preg_replace('/[^A-Za-z]/', '', $dept);
    if ($letters !== '' && strlen($letters) <= 5 && strtoupper($letters) === $letters && !preg_match('/\s/', trim($dept))) {
        return [$letters, ''];         // a code we do not know yet, written as one
    }
    return ['', $dept];
}

/** The branch code a department name or abbreviation stands for, or ''. */
function iv_branch_guess(string $s): string
{
    $norm = static function (string $x): string {
        $x = strtolower($x);
        $x = preg_replace('/\b(diploma|in|of|and|the|dept|department|branch|engineering|engg|engineer|eng|technology|tech)\b/', ' ', preg_replace('/[^a-z0-9]+/', ' ', $x));
        return preg_replace('/\s+/', '', $x);
    };
    $raw = strtoupper(preg_replace('/[^A-Za-z]/', '', $s));
    $aliases = [
        'DCME' => 'CM', 'DCM' => 'CM', 'DECE' => 'EC', 'DEEE' => 'EE', 'DME' => 'M', 'DCE' => 'C', 'DAE' => 'A', 'DCHE' => 'CH', 'DPHARM' => 'PH', 'DPHARMACY' => 'PH',
        'MECH' => 'M', 'MECHANICAL' => 'M', 'CIVIL' => 'C', 'COMPUTER' => 'CM', 'COMPUTERS' => 'CM', 'ELECTRONICS' => 'EC', 'ELECTRICAL' => 'EE', 'AUTO' => 'A',
        'AUTOMOBILE' => 'A', 'PHARMACY' => 'PH', 'CHEMICAL' => 'CH', 'MINING' => 'MNG', 'AIML' => 'AIM', 'AIANDML' => 'AIM', 'ARCHITECTURE' => 'AM',
        'METALLURGY' => 'MET', 'METALLURGICAL' => 'MET', 'TEXTILE' => 'TT', 'BIOMEDICAL' => 'BM', 'CERAMIC' => 'CER', 'MECHATRONICS' => 'MT', 'CSE' => 'CS', 'ECE' => 'EC', 'EEE' => 'EE',
    ];
    if (isset($aliases[$raw])) {
        return $aliases[$raw];
    }
    if ($raw !== '' && isset(iv_branch_names()[$raw])) {
        return $raw;
    }
    $n = $norm($s);
    if ($n === '') {
        return '';
    }
    foreach (iv_branch_names() as $code => $name) {
        if ($norm($name) === $n) {
            return in_array($code, ['CE', 'ME', 'EEE', 'ECE', 'CSE', 'AA', 'AE', 'DCME'], true) ? (['CE' => 'C', 'ME' => 'M', 'EEE' => 'EE', 'ECE' => 'EC', 'CSE' => 'CS', 'AA' => 'A', 'AE' => 'A', 'DCME' => 'CM'][$code]) : $code;
        }
    }
    // Shortened names: "Electrical & Electronics", "Electronics & Communication", "Computer Science".
    foreach (['electricalelectronics' => 'EE', 'electronicscommunication' => 'EC', 'electronicscomm' => 'EC', 'computerscience' => 'CS', 'artificialintelligencemachinelearning' => 'AIM',
              'artificialintelligence' => 'AI', 'refrigerationairconditioning' => 'MRAC', 'electronicsinstrumentation' => 'EI', 'appliedelectronicsinstrumentation' => 'AEI',
              'commercialcomputerpractice' => 'CCP', 'architecturalassistantship' => 'AM', 'architectural' => 'AM'] as $k => $code) {
        if ($n === $k) {
            return $code;
        }
    }
    return '';
}

/** A college code as one form: "056" and "56" are the same college. */
function iv_norm_code(string $c): string
{
    $c = iv_clean($c, 20);
    return preg_match('/^\d+$/', $c) ? (ltrim($c, '0') !== '' ? ltrim($c, '0') : '0') : $c;
}

/**
 * Files a batch of student records ([column => value]) with no duplicates:
 * the college from its code or name, the department from its name, branch
 * code, or the branch in a diploma PIN. $opts: college (id for rows without
 * one), dept (id for rows without one), make (create missing colleges and
 * departments), source.
 * Returns ['added','updated','same','skipped' => [[line, why]...]].
 */
function iv_students_import(int $org, array $records, array $opts = [], int $firstLine = 2): array
{
    $out = ['added' => 0, 'updated' => 0, 'same' => 0, 'skipped' => []];
    $make = !empty($opts['make']);
    $fbCollege = (int) ($opts['college'] ?? 0);
    $fbDept = (int) ($opts['dept'] ?? 0);
    if ($fbDept && ($fd = iv_department($org, $fbDept))) {
        $fbCollege = (int) $fd['college_id'];
    } else {
        $fbDept = 0;
    }
    $source = (string) ($opts['source'] ?? 'upload');
    $seen = [];      // student id => the first line of this file that was them
    $log = [];       // [line, student id, outcome, first line, row, why] per row
    // Written in batches of 500: one commit per batch instead of one per row (thousands of rows on a shared host).
    $db = fabo_db();
    $db->begin_transaction();
    foreach ($records as $i => $r) {
        $line = $firstLine + $i;
        if ($i > 0 && $i % 500 === 0) {
            $db->commit();
            $db->begin_transaction();
        }
        if ($i >= IV_MAX_IMPORT) {
            $out['skipped'][] = [$line, 'Stopped: one import takes up to ' . number_format(IV_MAX_IMPORT) . ' rows.'];
            break;
        }
        try {
            [$code, $cname] = iv_split_college((string) ($r['college'] ?? ''));
            if (($r['college_code'] ?? '') !== '') {
                $code = $r['college_code'];
            }
            $pin = iv_parse_pin((string) ($r['roll_no'] ?? ''));
            $college = 0;
            if ($code !== '' || $cname !== '') {
                $college = iv_college_find($org, $code, $cname, (string) ($r['district'] ?? ''), $make, (string) ($opts['kind'] ?? 'College'));
                if (!$college) {
                    throw new InvalidArgumentException('No college "' . trim($code . ' ' . $cname) . '". Add it, or tick "Create missing colleges and departments".');
                }
            }
            $college = $college ?: $fbCollege;
            $dept = 0;
            if ($college) {
                [$branch, $dname] = iv_branch_split((string) ($r['department'] ?? ''), (string) ($r['branch'] ?? ''));
                if ($branch === '' && $dname === '') {
                    $branch = $pin['branch'] ?? '';
                }
                if ($branch !== '' || $dname !== '') {
                    // A student's sheet never makes a department: departments are HoD dashboards. The
                    // student joins one that exists; otherwise it joins them when it is made.
                    $dept = iv_department_find($org, $college, $branch, $dname, false);
                }
                if (!$dept && $fbDept && $college === $fbCollege) {
                    $dept = $fbDept;
                }
            }
            [$sid, $how] = iv_student_save($org, ['name' => $r['name'] ?? '', 'email' => $r['email'] ?? '', 'phone' => $r['phone'] ?? '',
                                               'roll_no' => $r['roll_no'] ?? '', 'year_of_study' => $r['year'] ?? '', 'college_id' => $college,
                                               'department_id' => $dept, 'source_ref' => $r['_ref'] ?? '',
                                               'district' => $r['district'] ?? '', 'notes' => $r['notes'] ?? '',
                                               'alt_email' => ($r['email_alt'] ?? '') !== ($r['email'] ?? '') ? ($r['email_alt'] ?? '') : '',
                                               'roll_typed' => $r['roll_no'] ?? ''], null, $source);
            $out[$how]++;
            // Which student this row is, and whether that student already had a row earlier in this file.
            $first = $seen[$sid] ?? null;
            $seen[$sid] ??= $line;
            $log[] = [$line, $sid, $first === null ? ($how === 'added' ? 'new' : 'known') : 'repeat', $first ?? 0, $r, ''];
        } catch (Throwable $e) {
            $why = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Could not save this row.';
            $out['skipped'][] = [$line, $why];
            $log[] = [$line, 0, 'skipped', 0, $r, $why];
            if (!$e instanceof InvalidArgumentException) {
                error_log('FABO iv import: ' . $e->getMessage());
            }
        }
    }
    $db->commit();
    $out['students'] = count($seen);
    $out['repeats'] = count(array_filter($log, static fn($x) => $x[2] === 'repeat'));
    $out['batch'] = iv_import_log($org, (string) ($opts['label'] ?? $source), $log, $out);
    return $out;
}

/**
 * Keeps what an upload did with every row, so its numbers can be checked:
 * which student each row is, and the rows that were a student already seen
 * earlier in the same file (the same person registering again). Only the
 * newest few uploads per organisation are kept. Returns the upload's id.
 */
function iv_import_log(int $org, string $label, array $log, array $out): int
{
    try {
        $bid = fabo_exec('INSERT INTO iv_imports (org_id, label, rows_read, students, repeats, added, skipped, actor, created_at) VALUES (?,?,?,?,?,?,?,?,?)', 'isiiiiiss',
                         [$org, mb_substr($label, 0, 120), count($log), (int) $out['students'], (int) $out['repeats'], (int) $out['added'], count($out['skipped']),
                          mb_substr((string) ($GLOBALS['iv_actor_name'] ?? ''), 0, 190), iv_now()]);
        $db = fabo_db();
        $db->begin_transaction();
        foreach ($log as $k => [$line, $sid, $outcome, $first, $r, $why]) {
            if ($k > 0 && $k % 500 === 0) {
                $db->commit();
                $db->begin_transaction();
            }
            fabo_exec('INSERT INTO iv_import_rows (import_id, org_id, line, student_id, outcome, first_line, name, roll_typed, email, phone, college, note) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                      'iiiisissssss', [$bid, $org, (int) $line, (int) $sid, $outcome, (int) $first,
                                       mb_substr((string) ($r['name'] ?? ''), 0, 160), mb_substr((string) ($r['roll_no'] ?? ''), 0, 80), mb_substr((string) ($r['email'] ?? ''), 0, 160),
                                       mb_substr((string) ($r['phone'] ?? ''), 0, 40), mb_substr(trim(($r['college_code'] ?? '') . ' ' . ($r['college'] ?? '')), 0, 220), mb_substr($why, 0, 255)]);
        }
        $db->commit();
        // Only the newest five uploads are kept.
        foreach (fabo_all('SELECT id FROM iv_imports WHERE org_id = ? ORDER BY id DESC LIMIT 100 OFFSET 5', 'i', [$org]) as $old) {
            fabo_exec('DELETE FROM iv_import_rows WHERE import_id = ?', 'i', [(int) $old['id']]);
            fabo_exec('DELETE FROM iv_imports WHERE id = ?', 'i', [(int) $old['id']]);
        }
        return $bid;
    } catch (Throwable $e) {
        error_log('FABO iv import log: ' . $e->getMessage());
        return 0;
    }
}

/** The newest upload of an organisation, or null. */
function iv_last_import(int $org): ?array
{
    try {
        return fabo_one('SELECT * FROM iv_imports WHERE org_id = ? ORDER BY id DESC LIMIT 1', 'i', [$org]);
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Bulk department dashboards: each row a college (code/name, district), a
 * department (name and/or branch code) and its Head (name, email, phone).
 * Colleges and departments are made when missing; the HoD is set or updated.
 * $invite: email each new or changed HoD their sign-in link.
 * Returns ['depts' => made, 'hods' => set, 'invited' => n, 'skipped' => [[line, why]]].
 */
function iv_dashboards_import(int $org, array $records, bool $invite = false, int $firstLine = 2, string $kind = 'College'): array
{
    $out = ['colleges' => 0, 'depts' => 0, 'hods' => 0, 'invited' => 0, 'skipped' => []];
    foreach ($records as $i => $r) {
        $line = $firstLine + $i;
        try {
            [$code, $cname] = iv_split_college((string) ($r['college'] ?? ''));
            if (($r['college_code'] ?? '') !== '') {
                $code = $r['college_code'];
            }
            $code = iv_norm_code($code);
            if ($cname === '' && $code === '') {
                throw new InvalidArgumentException('No college or school named.');
            }
            $had = $code !== '' ? fabo_one('SELECT id FROM iv_colleges WHERE org_id = ? AND code = ?', 'is', [$org, $code]) : null;
            $had = $had ?: ($cname !== '' ? fabo_one('SELECT id FROM iv_colleges WHERE org_id = ? AND name = ?', 'is', [$org, $cname]) : null);
            if (!$had && $cname === '') {
                throw new InvalidArgumentException('College code ' . $code . ' is new: give its name too.');
            }
            $college = $had ? (int) $had['id'] : iv_college_find($org, $code, $cname, (string) ($r['district'] ?? ''), true, in_array($r['kind'] ?? '', iv_college_kinds(), true) ? $r['kind'] : $kind);
            if (!$had) {
                $out['colleges']++;
            }
            [$branch, $dname] = iv_branch_split((string) ($r['department'] ?? ''), (string) ($r['branch'] ?? ''));
            if ($branch === '' && $dname === '') {
                throw new InvalidArgumentException('No department named.');
            }
            $before = iv_department_find($org, $college, $branch, $dname, false);
            $dept = $before ?: iv_department_find($org, $college, $branch, $dname, true);
            if (!$before) {
                $out['depts']++;
            }
            if (($r['hod_email'] ?? '') !== '' || ($r['hod_name'] ?? '') !== '') {
                $prev = iv_hod_for_dept($org, $dept);
                $hid = iv_hod_save($org, $dept, ['name' => $r['hod_name'] ?? '', 'email' => $r['hod_email'] ?? '', 'phone' => $r['hod_phone'] ?? '',
                                                 'ai_limit' => $prev['ai_limit'] ?? null]);
                $out['hods']++;
                $changed = !$prev || $prev['email'] !== mb_strtolower(trim((string) $r['hod_email']));
                if ($invite && $changed) {
                    [$ok] = iv_invite_send($org, $hid);
                    $out['invited'] += $ok ? 1 : 0;
                }
            }
        } catch (Throwable $e) {
            $out['skipped'][] = [$line, $e instanceof InvalidArgumentException ? $e->getMessage() : 'Could not save this row.'];
            if (!$e instanceof InvalidArgumentException) {
                error_log('FABO iv dashboards import: ' . $e->getMessage());
            }
        }
    }
    return $out;
}

/* ================================================================ rounds */

function iv_rounds(int $org): array
{
    iv_install();
    return fabo_all('SELECT * FROM iv_rounds WHERE org_id = ? ORDER BY opens_at DESC, id DESC', 'i', [$org]);
}

function iv_round(int $org, int $id): ?array
{
    return fabo_one('SELECT * FROM iv_rounds WHERE org_id = ? AND id = ?', 'ii', [$org, $id]);
}

/** The round people are working in: the newest live one, else the newest of all. */
function iv_round_current(int $org): ?array
{
    iv_install();
    return fabo_one('SELECT * FROM iv_rounds WHERE org_id = ? AND status = "live" ORDER BY opens_at DESC, id DESC LIMIT 1', 'i', [$org])
        ?? fabo_one('SELECT * FROM iv_rounds WHERE org_id = ? AND status <> "draft" ORDER BY opens_at DESC, id DESC LIMIT 1', 'i', [$org]);
}

/** "2026-11-24T10:00" or "2026-11-24 10:00" -> "2026-11-24 10:00:00", or null. */
function iv_dt(?string $v): ?string
{
    $v = trim((string) $v);
    if ($v === '') {
        return null;
    }
    $ts = strtotime(str_replace('T', ' ', $v));
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
}

function iv_round_save(int $org, array $d, ?int $id = null): int
{
    $name = iv_clean($d['name'] ?? '', 160);
    if ($name === '') {
        throw new InvalidArgumentException('Give the round a name, for example "Idea round 2026-27".');
    }
    $opens = iv_dt($d['opens_at'] ?? '');
    $closes = iv_dt($d['closes_at'] ?? '');
    if (!$opens || !$closes) {
        throw new InvalidArgumentException('Set when idea submission opens and closes.');
    }
    if ($closes <= $opens) {
        throw new InvalidArgumentException('Submission must close after it opens.');
    }
    $review = iv_dt($d['review_by'] ?? '');
    if ($review && $review < $closes) {
        throw new InvalidArgumentException('The HoD review deadline must be after submission closes.');
    }
    $gem = (int) ($d['gem_id'] ?? 0) ?: null;
    if ($gem) {
        require_once __DIR__ . '/gem.php';
        if (!iv_gem($org, $gem)) {
            throw new InvalidArgumentException('That Gem is not available.');
        }
    }
    $limit = max(0, min(20, (int) ($d['ai_limit'] ?? 2)));
    $top = max(1, min(20, (int) ($d['top_n'] ?? 3)));
    $status = in_array($d['status'] ?? '', ['draft', 'live', 'closed'], true) ? $d['status'] : 'live';
    $allow = !empty($d['allow_new']) ? 1 : 0;
    // The rounds form says own_ai_form=1, so its unticked box means off. Anything else that leaves it out keeps what the round has (new: on).
    $ownAi = !empty($d['own_ai_form']) || array_key_exists('own_ai', $d) ? (!empty($d['own_ai']) ? 1 : 0) : (int) (($id ? iv_round($org, $id)['own_ai'] ?? 1 : 1));
    $clash = fabo_one('SELECT id FROM iv_rounds WHERE org_id = ? AND name = ?', 'is', [$org, $name]);
    if ($clash && (int) $clash['id'] !== (int) $id) {
        throw new InvalidArgumentException('There is already a round called ' . $name . '.');
    }
    if ($id) {
        if (!iv_round($org, $id)) {
            throw new InvalidArgumentException('That round is not in this organisation.');
        }
        fabo_exec('UPDATE iv_rounds SET name = ?, gem_id = ?, opens_at = ?, closes_at = ?, review_by = ?, ai_limit = ?, top_n = ?, allow_new = ?, own_ai = ?, status = ? WHERE org_id = ? AND id = ?',
                  'sisssiiiisii', [$name, $gem, $opens, $closes, $review, $limit, $top, $allow, $ownAi, $status, $org, $id]);
    } else {
        $id = fabo_exec('INSERT INTO iv_rounds (org_id, name, gem_id, opens_at, closes_at, review_by, ai_limit, top_n, allow_new, own_ai, status) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                        'isisssiiiis', [$org, $name, $gem, $opens, $closes, $review, $limit, $top, $allow, $ownAi, $status]);
    }
    // The note to students: only when the form sent it, so other callers keep it.
    if (array_key_exists('form_note', $d)) {
        $note = iv_text((string) $d['form_note'], 3000);
        fabo_exec('UPDATE iv_rounds SET form_note = ? WHERE org_id = ? AND id = ?', 'sii', [$note !== '' ? $note : null, $org, $id]);
    }
    iv_round_forms_refresh($org, (int) $id);
    return (int) $id;
}

/** The note to students on a round's forms: the owner's, or the standard one. */
function iv_form_note(?array $round): string
{
    $note = trim((string) ($round['form_note'] ?? ''));
    return $note !== '' ? $note : "Share your idea with your Head of Department. Answer each question in a few clear sentences: the problem, who has it, and what you will build.";
}

/**
 * Puts the owner's title ("Round — Department") and note on every
 * department form of a round. HoDs cannot change either.
 */
function iv_round_forms_refresh(int $org, int $roundId): void
{
    $round = iv_round($org, $roundId);
    if (!$round) {
        return;
    }
    $note = iv_form_note($round);
    foreach (fabo_all('SELECT f.id, f.title, f.intro, d.name AS dept FROM iv_forms f JOIN iv_departments d ON d.id = f.department_id
                       WHERE f.org_id = ? AND f.round_id = ?', 'ii', [$org, $roundId]) as $f) {
        $title = mb_substr($round['name'] . ' — ' . $f['dept'], 0, 190);
        if ($title !== $f['title'] || $note !== (string) $f['intro']) {
            fabo_exec('UPDATE iv_forms SET title = ?, intro = ? WHERE id = ? AND org_id = ?', 'ssii', [$title, $note, (int) $f['id'], $org]);
        }
    }
}

function iv_round_delete(int $org, int $id): void
{
    if (fabo_one('SELECT id FROM iv_ideas WHERE org_id = ? AND round_id = ? LIMIT 1', 'ii', [$org, $id])) {
        throw new InvalidArgumentException('This round has ideas, so it stays. Close it instead.');
    }
    fabo_exec('DELETE FROM iv_forms WHERE org_id = ? AND round_id = ?', 'ii', [$org, $id]);
    fabo_exec('DELETE FROM iv_dept_windows WHERE org_id = ? AND round_id = ?', 'ii', [$org, $id]);
    fabo_exec('DELETE FROM iv_rounds WHERE org_id = ? AND id = ?', 'ii', [$org, $id]);
}

/** When submission closes for one department: its own extension, else the round's. */
function iv_closes_for(array $round, int $deptId): string
{
    $w = fabo_one('SELECT closes_at FROM iv_dept_windows WHERE round_id = ? AND department_id = ?', 'ii', [(int) $round['id'], $deptId]);
    return $w ? (string) $w['closes_at'] : (string) $round['closes_at'];
}

/** null when one department may submit now, else why not (in words for a student). */
function iv_window_problem(array $round, int $deptId): ?string
{
    if ($round['status'] !== 'live') {
        return 'Idea submission for ' . $round['name'] . ' is not open.';
    }
    $now = iv_now();
    if ($now < $round['opens_at']) {
        return 'Idea submission opens on ' . iv_when($round['opens_at']) . '.';
    }
    $closes = iv_closes_for($round, $deptId);
    if ($now >= $closes) {
        return 'Idea submission closed on ' . iv_when($closes) . '.';
    }
    return null;
}

/** Owner: a later closing time for one department (null clears it). */
function iv_dept_window_set(int $org, int $roundId, int $deptId, ?string $closes): void
{
    $r = iv_round($org, $roundId);
    if (!$r || !iv_department($org, $deptId)) {
        throw new InvalidArgumentException('That round or department is not in this organisation.');
    }
    if ($closes === null) {
        fabo_exec('DELETE FROM iv_dept_windows WHERE round_id = ? AND department_id = ?', 'ii', [$roundId, $deptId]);
        return;
    }
    if ($closes <= $r['opens_at']) {
        throw new InvalidArgumentException('The new closing time must be after submission opens.');
    }
    fabo_exec('INSERT INTO iv_dept_windows (round_id, department_id, org_id, closes_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE closes_at = VALUES(closes_at)',
              'iiis', [$roundId, $deptId, $org, $closes]);
}

function iv_when(?string $dt): string
{
    if (!$dt) {
        return '—';
    }
    $ts = strtotime($dt);
    return $ts ? date('j M Y, g:i a', $ts) : '—';
}

function iv_date(?string $dt): string
{
    $ts = $dt ? strtotime($dt) : false;
    return $ts ? date('j M Y', $ts) : '—';
}

/** AI checks allowed per idea for this HoD in this round: their own limit when the owner set one, else the round's. */
function iv_ai_limit(array $round, ?array $hod): int
{
    if ($hod && $hod['ai_limit'] !== null && $hod['ai_limit'] !== '') {
        return (int) $hod['ai_limit'];
    }
    return (int) $round['ai_limit'];
}

/* ================================================================= forms */

/** The question types a form offers. */
function iv_question_types(): array
{
    return ['long' => 'Paragraph', 'text' => 'Short answer', 'number' => 'Number', 'url' => 'Link'];
}

/** The questions a new form starts with: the Gem's own, so the AI has what it scores. */
function iv_default_questions(int $org, ?array $round): array
{
    $qs = [];
    if ($round && $round['gem_id']) {
        require_once __DIR__ . '/gem.php';
        $gem = iv_gem($org, (int) $round['gem_id']);
        foreach ($gem['fields'] ?? [] as $f) {
            $qs[] = ['uid' => 'g_' . substr(preg_replace('/[^a-z0-9_]/', '', (string) $f['uid']), 0, 36), 'label' => (string) $f['label'],
                     'help' => (string) ($f['help'] ?? ''), 'type' => isset(iv_question_types()[$f['type'] ?? '']) ? $f['type'] : 'long',
                     'required' => !empty($f['required']), 'gem' => true];
        }
    }
    if (!$qs) {
        foreach (['Problem statement' => 'What problem are you solving, and who has it?', 'Target users' => 'Who will use your solution?',
                  'Your solution' => 'What will you build, and how does it work?', 'What makes it different?' => 'How is it better than what exists today?'] as $l => $h) {
            $qs[] = ['uid' => 'q_' . substr(md5($l), 0, 8), 'label' => $l, 'help' => $h, 'type' => 'long', 'required' => true, 'gem' => false];
        }
    }
    return $qs;
}

/** The form of one department for one round, made on first use. */
function iv_form_for(int $org, array $round, int $deptId): array
{
    $f = fabo_one('SELECT * FROM iv_forms WHERE org_id = ? AND round_id = ? AND department_id = ?', 'iii', [$org, (int) $round['id'], $deptId]);
    if ($f) {
        return $f;
    }
    $d = iv_department($org, $deptId);
    if (!$d) {
        throw new InvalidArgumentException('That department is not in this organisation.');
    }
    fabo_exec('INSERT IGNORE INTO iv_forms (org_id, round_id, department_id, title, intro, questions_json, token) VALUES (?,?,?,?,?,?,?)', 'iiissss',
              [$org, (int) $round['id'], $deptId, mb_substr($round['name'] . ' — ' . $d['name'], 0, 190), iv_form_note($round),
               json_encode(iv_default_questions($org, $round), JSON_UNESCAPED_UNICODE), bin2hex(random_bytes(16))]);
    return fabo_one('SELECT * FROM iv_forms WHERE org_id = ? AND round_id = ? AND department_id = ?', 'iii', [$org, (int) $round['id'], $deptId]);
}

function iv_form_questions(array $form): array
{
    return json_decode((string) $form['questions_json'], true) ?: [];
}

function iv_form_by_token(string $token): ?array
{
    if (!preg_match('/^[0-9a-f]{32}$/', $token)) {
        return null;
    }
    iv_install();
    return fabo_one('SELECT * FROM iv_forms WHERE token = ?', 's', [$token]);
}

/**
 * What a HoD can change on their form: whether it accepts ideas. The title,
 * the note to students and the questions are the owner's (the round's name,
 * its note, its Gem); they are put back as the owner set them on every save.
 * $title, $intro and $rows are ignored.
 */
function iv_form_save(int $org, int $formId, string $title, string $intro, array $rows, bool $open): void
{
    $f = fabo_one('SELECT * FROM iv_forms WHERE org_id = ? AND id = ?', 'ii', [$org, $formId]);
    if (!$f) {
        throw new InvalidArgumentException('That form is not in this organisation.');
    }
    $qs = iv_form_locked_questions($org, $f) ?: iv_form_questions($f);
    fabo_exec('UPDATE iv_forms SET questions_json = ?, is_open = ? WHERE id = ?', 'sii', [json_encode($qs, JSON_UNESCAPED_UNICODE), $open ? 1 : 0, $formId]);
    iv_round_forms_refresh($org, (int) $f['round_id']);
}

/**
 * The owner's questions on a form: the round's Gem as it is now, or, with no
 * Gem on the round, the Gem questions the form already holds, untouched.
 */
function iv_form_locked_questions(int $org, array $form): array
{
    $round = iv_round($org, (int) $form['round_id']);
    if ($round && $round['gem_id']) {
        require_once __DIR__ . '/gem.php';
        $gem = iv_gem($org, (int) $round['gem_id']);
        if ($gem && $gem['fields']) {
            return array_map('iv_gem_form_question', $gem['fields']);
        }
    }
    return array_values(array_filter(iv_form_questions($form), static fn($q) => !empty($q['gem'])));
}

/* ================================================================= ideas */

function iv_statuses(): array
{
    return ['submitted' => 'To validate', 'validated' => 'Validated', 'rejected' => 'Not taken'];
}

/** The owner's pipeline, in order. An idea enters it at 'forwarded' when its HoD sends it. */
function iv_stages(): array
{
    return ['forwarded' => 'Forwarded by HoD', 'review' => 'Owner review', 'team' => 'Team assigned',
            'build' => 'In development', 'selected' => 'Selected', 'dropped' => 'Not taken forward'];
}

/**
 * A student submits (or, while the window is open and their HoD has not yet
 * decided, updates) their idea for the form's round. One idea per student per
 * round. Returns [idea id, 'added' | 'updated'].
 */
function iv_idea_submit(array $form, array $in): array
{
    $org = (int) $form['org_id'];
    $round = iv_round($org, (int) $form['round_id']);
    $dept = iv_department($org, (int) $form['department_id']);
    if (!$round || !$dept) {
        throw new InvalidArgumentException('This form is no longer in use.');
    }
    if (!(int) $form['is_open']) {
        throw new InvalidArgumentException('Your Head of Department has paused this form.');
    }
    if ($why = iv_window_problem($round, (int) $dept['id'])) {
        throw new InvalidArgumentException($why);
    }
    $name = iv_clean($in['name'] ?? '', 160);
    $roll = iv_norm_roll((string) ($in['roll_no'] ?? ''));
    $email = iv_norm_email((string) ($in['email'] ?? ''));
    $phone = iv_norm_phone((string) ($in['phone'] ?? ''));
    $title = iv_clean($in['title'] ?? '', 190);
    if ($name === '' || $roll === '' || $email === '') {
        throw new InvalidArgumentException('Fill in your name, roll number / PIN and a valid email.');
    }
    if ($title === '') {
        throw new InvalidArgumentException('Give your idea a title.');
    }
    $answers = [];
    foreach (iv_form_questions($form) as $q) {
        $v = iv_text($in['q'][$q['uid']] ?? '', 5000);
        if ($v === '' && !empty($q['required'])) {
            throw new InvalidArgumentException('Answer "' . $q['label'] . '".');
        }
        if ($v !== '' && $q['type'] === 'url' && !filter_var($v, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('"' . $q['label'] . '" needs a full link, starting with https://');
        }
        if ($v !== '' && $q['type'] === 'number' && !is_numeric($v)) {
            throw new InvalidArgumentException('"' . $q['label'] . '" needs a number.');
        }
        $answers[] = ['uid' => $q['uid'], 'label' => $q['label'], 'help' => $q['help'] ?? '', 'value' => $v];
    }
    return iv_tx(static function () use ($org, $round, $dept, $form, $name, $roll, $email, $phone, $title, $answers) {
        $have = iv_student_match($org, $roll, $email, $phone, $name);
        if ($have && (int) $have['department_id'] && (int) $have['department_id'] !== (int) $dept['id']) {
            $theirs = iv_department($org, (int) $have['department_id']);
            throw new InvalidArgumentException('Your roll number is registered with ' . ($theirs ? $theirs['name'] . ', ' . $theirs['college_name'] : 'another department')
                                               . '. Use the form your own Head of Department shared, or ask them to correct your department.');
        }
        if (!$have && !(int) $round['allow_new']) {
            throw new InvalidArgumentException('Your roll number is not on your department\'s student list. Ask your Head of Department to add you.');
        }
        // Fills gaps on the record (and places a student with no department here); never a second row.
        [$sid] = iv_student_save($org, ['name' => $name, 'roll_no' => $roll, 'email' => $email, 'phone' => $phone,
                                         'department_id' => (int) $dept['id']], null, 'form');
        $idea = fabo_one('SELECT * FROM iv_ideas WHERE round_id = ? AND student_id = ? FOR UPDATE', 'ii', [(int) $round['id'], $sid]);
        $json = json_encode($answers, JSON_UNESCAPED_UNICODE);
        if ($idea) {
            if ((int) $idea['department_id'] !== (int) $dept['id']) {
                throw new InvalidArgumentException('You already sent an idea in this round to another department.');
            }
            if ($idea['status'] !== 'submitted' || fabo_one('SELECT id FROM iv_ai_runs WHERE idea_id = ? LIMIT 1', 'i', [(int) $idea['id']])) {
                throw new InvalidArgumentException('Your Head of Department has already started reviewing your idea, so it can no longer be changed.');
            }
            fabo_exec('UPDATE iv_ideas SET title = ?, answers_json = ?, form_id = ?, submitted_at = ? WHERE id = ?', 'ssisi', [$title, $json, (int) $form['id'], iv_now(), (int) $idea['id']]);
            iv_log($org, (int) $idea['id'], $name . ' (student)', 'Updated the idea.');
            return [(int) $idea['id'], 'updated'];
        }
        $id = fabo_exec('INSERT INTO iv_ideas (org_id, round_id, college_id, department_id, student_id, form_id, title, answers_json, submitted_at) VALUES (?,?,?,?,?,?,?,?,?)',
                        'iiiiiisss', [$org, (int) $round['id'], (int) $dept['college_id'], (int) $dept['id'], $sid, (int) $form['id'], $title, $json, iv_now()]);
        iv_log($org, $id, $name . ' (student)', 'Submitted the idea.');
        return [$id, 'added'];
    });
}

function iv_idea(int $org, int $id): ?array
{
    return fabo_one('SELECT i.*, s.name AS student_name, COALESCE(NULLIF(s.roll_typed, ""), s.roll_no) AS roll_no, s.email AS student_email, s.phone AS student_phone,
                            d.name AS dept_name, c.name AS college_name, r.name AS round_name
                       FROM iv_ideas i JOIN iv_students s ON s.id = i.student_id JOIN iv_departments d ON d.id = i.department_id
                       JOIN iv_colleges c ON c.id = i.college_id JOIN iv_rounds r ON r.id = i.round_id
                      WHERE i.org_id = ? AND i.id = ?', 'ii', [$org, $id]);
}

function iv_idea_answers(array $idea): array
{
    return json_decode((string) $idea['answers_json'], true) ?: [];
}

/**
 * Ideas, filtered: round, dept, college, status, stage ('any' = in the
 * pipeline), forwarded (1), q. Newest first unless sort = 'score'.
 */
function iv_ideas(int $org, array $f = [], int $limit = 500): array
{
    $w = ['i.org_id = ?'];
    $t = 'i';
    $p = [$org];
    foreach (['round' => 'i.round_id', 'dept' => 'i.department_id', 'college' => 'i.college_id'] as $k => $col) {
        if (!empty($f[$k])) {
            $w[] = "$col = ?";
            $t .= 'i';
            $p[] = (int) $f[$k];
        }
    }
    if (!empty($f['status']) && isset(iv_statuses()[$f['status']])) {
        $w[] = 'i.status = ?';
        $t .= 's';
        $p[] = $f['status'];
    }
    if (($f['stage'] ?? '') === 'any') {
        $w[] = 'i.stage IS NOT NULL';
    } elseif (!empty($f['stage']) && isset(iv_stages()[$f['stage']])) {
        $w[] = 'i.stage = ?';
        $t .= 's';
        $p[] = $f['stage'];
    }
    $q = trim((string) ($f['q'] ?? ''));
    if ($q !== '') {
        $w[] = '(i.title LIKE ? OR s.name LIKE ? OR s.roll_no LIKE ? OR s.roll_typed LIKE ?)';
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $t .= 'ssss';
        array_push($p, $like, $like, $like, $like);
    }
    $order = ($f['sort'] ?? '') === 'score' ? 'i.ai_score IS NULL, i.ai_score DESC, i.submitted_at' : (($f['sort'] ?? '') === 'rank' ? 'i.hod_rank IS NULL, i.hod_rank, i.ai_score DESC' : 'i.submitted_at DESC');
    return fabo_all('SELECT i.*, s.name AS student_name, COALESCE(NULLIF(s.roll_typed, ""), s.roll_no) AS roll_no, d.name AS dept_name, c.name AS college_name,
                            (SELECT COUNT(*) FROM iv_ai_runs a WHERE a.idea_id = i.id AND a.state = "done") AS runs,
                            (SELECT COUNT(*) FROM iv_team m WHERE m.idea_id = i.id) AS team_size
                       FROM iv_ideas i JOIN iv_students s ON s.id = i.student_id JOIN iv_departments d ON d.id = i.department_id
                       JOIN iv_colleges c ON c.id = i.college_id
                      WHERE ' . implode(' AND ', $w) . ' ORDER BY ' . $order . ' LIMIT ' . max(1, $limit), $t, $p);
}

/** Has this department's HoD already sent their picks for the round? */
function iv_dept_forwarded(int $org, int $roundId, int $deptId): bool
{
    return (bool) fabo_one('SELECT id FROM iv_ideas WHERE org_id = ? AND round_id = ? AND department_id = ? AND forwarded_at IS NOT NULL LIMIT 1', 'iii', [$org, $roundId, $deptId]);
}

/** HoD: validated or not taken, with a note. Not after the department's picks were sent. */
function iv_idea_decide(int $org, int $ideaId, int $deptId, string $status, string $note, string $actor): void
{
    $idea = iv_idea($org, $ideaId);
    if (!$idea || (int) $idea['department_id'] !== $deptId) {
        throw new InvalidArgumentException('That idea is not in your department.');
    }
    if (!in_array($status, ['submitted', 'validated', 'rejected'], true)) {
        throw new InvalidArgumentException('Choose Validated or Not taken.');
    }
    if (iv_dept_forwarded($org, (int) $idea['round_id'], $deptId)) {
        throw new InvalidArgumentException('Your top ideas have been sent forward, so decisions are final. Ask the owner to return them if something must change.');
    }
    fabo_exec('UPDATE iv_ideas SET status = ?, hod_note = ?, hod_rank = IF(? = "validated", hod_rank, NULL) WHERE id = ?', 'sssi',
              [$status, iv_text($note, 3000), $status, $ideaId]);
    iv_log($org, $ideaId, $actor, 'Marked ' . iv_statuses()[$status] . '.');
}

/**
 * HoD: their ranked picks ([idea id => rank 1..top_n]); 0 or empty clears a
 * rank. Only validated ideas of the department, no rank twice, at most top_n.
 */
function iv_ranks_set(int $org, array $round, int $deptId, array $ranks, string $actor): void
{
    if (iv_dept_forwarded($org, (int) $round['id'], $deptId)) {
        throw new InvalidArgumentException('Your top ideas have already been sent forward.');
    }
    $top = (int) $round['top_n'];
    $mine = [];
    foreach (iv_ideas($org, ['round' => (int) $round['id'], 'dept' => $deptId]) as $i) {
        $mine[(int) $i['id']] = $i;
    }
    $clean = [];
    foreach ($ranks as $id => $rank) {
        $id = (int) $id;
        $rank = (int) $rank;
        if (!isset($mine[$id])) {
            throw new InvalidArgumentException('That idea is not in your department.');
        }
        if ($rank <= 0) {
            continue;
        }
        if ($rank > $top) {
            throw new InvalidArgumentException('Rank from 1 to ' . $top . '.');
        }
        if ($mine[$id]['status'] !== 'validated') {
            throw new InvalidArgumentException('Only validated ideas can be ranked: "' . $mine[$id]['title'] . '" is not.');
        }
        if (in_array($rank, $clean, true)) {
            throw new InvalidArgumentException('Rank ' . $rank . ' is given twice.');
        }
        $clean[$id] = $rank;
    }
    iv_tx(static function () use ($org, $round, $deptId, $clean, $actor, $mine) {
        fabo_exec('UPDATE iv_ideas SET hod_rank = NULL WHERE org_id = ? AND round_id = ? AND department_id = ?', 'iii', [$org, (int) $round['id'], $deptId]);
        foreach ($clean as $id => $rank) {
            fabo_exec('UPDATE iv_ideas SET hod_rank = ? WHERE id = ?', 'ii', [$rank, $id]);
            if ((int) $mine[$id]['hod_rank'] !== $rank) {
                iv_log($org, $id, $actor, 'Ranked #' . $rank . ' in the department.');
            }
        }
    });
}

/**
 * HoD: sends the ranked ideas into the owner's pipeline. Returns how many went.
 * Keeping it honest: when the round scores with a Gem and the HoD has AI
 * checks, every pick must have been checked; and when a pick is ranked above a
 * validated idea that scored higher, the HoD must say why ($reason). The
 * reason travels with each pick (pick_note) so the owner reads it.
 */
function iv_forward(int $org, array $round, int $deptId, string $actor, ?array $hod = null, string $reason = ''): int
{
    $picks = fabo_all('SELECT i.*, (SELECT COUNT(*) FROM iv_ai_runs a WHERE a.idea_id = i.id AND a.state = "done") AS runs FROM iv_ideas i
                        WHERE i.org_id = ? AND i.round_id = ? AND i.department_id = ? AND i.hod_rank IS NOT NULL AND i.status = "validated" ORDER BY i.hod_rank',
                      'iii', [$org, (int) $round['id'], $deptId]);
    if (!$picks) {
        throw new InvalidArgumentException('Rank at least one validated idea first.');
    }
    if (count($picks) > (int) $round['top_n']) {
        throw new InvalidArgumentException('Send at most ' . (int) $round['top_n'] . ' ideas.');
    }
    if (iv_dept_forwarded($org, (int) $round['id'], $deptId)) {
        throw new InvalidArgumentException('Your top ideas have already been sent forward.');
    }
    if ($round['gem_id'] && iv_ai_limit($round, $hod) > 0) {
        foreach ($picks as $p) {
            if (!(int) $p['runs']) {
                throw new InvalidArgumentException('Check "' . $p['title'] . '" with AI before sending it: every top pick needs an AI score.');
            }
        }
    }
    $against = iv_picks_against_scores(iv_ideas($org, ['round' => (int) $round['id'], 'dept' => $deptId]));
    $reason = iv_text($reason, 2000);
    if ($against && mb_strlen($reason) < 15) {
        throw new InvalidArgumentException('Your order goes against the AI scores (' . $against[0] . '). Say why in a sentence or two, so the owner understands.');
    }
    return iv_tx(static function () use ($org, $round, $picks, $actor, $against, $reason) {
        foreach ($picks as $i) {
            fabo_exec('UPDATE iv_ideas SET forwarded_at = ?, stage = "forwarded", stage_at = ?, pick_note = ? WHERE id = ?', 'sssi',
                      [iv_now(), iv_now(), $against ? $reason : null, (int) $i['id']]);
            // The student whose idea it is leads its team.
            fabo_exec('INSERT IGNORE INTO iv_team (org_id, round_id, idea_id, student_id, role, added_by, created_at) VALUES (?,?,?,?, "lead", ?, ?)',
                      'iiiiss', [$org, (int) $round['id'], (int) $i['id'], (int) $i['student_id'], $actor, iv_now()]);
            iv_log($org, (int) $i['id'], $actor, mb_substr('Sent forward as the department\'s #' . (int) $i['hod_rank'] . '.' . ($against ? ' Order against the AI scores: ' . $reason : ''), 0, 255));
        }
        return count($picks);
    });
}

/**
 * Where a HoD's ranking goes against the AI scores: a ranked idea placed
 * above a validated idea that scored higher (ranked lower, or not ranked at
 * all). Ideas never checked are left out: there is nothing to compare.
 * Returns readable lines, the widest gap first.
 */
function iv_picks_against_scores(array $ideas): array
{
    $pct = static fn($i) => $i['ai_score'] !== null && (float) $i['ai_max'] > 0 ? (float) $i['ai_score'] / (float) $i['ai_max'] : null;
    $out = [];
    foreach ($ideas as $p) {
        if ($p['status'] !== 'validated' || !$p['hod_rank'] || ($pp = $pct($p)) === null) {
            continue;
        }
        foreach ($ideas as $q) {
            if ((int) $q['id'] === (int) $p['id'] || $q['status'] !== 'validated' || ($qp = $pct($q)) === null || $qp <= $pp + 0.0001) {
                continue;
            }
            if (!$q['hod_rank'] || (int) $q['hod_rank'] > (int) $p['hod_rank']) {
                $out[] = [$qp - $pp, '"' . $p['title'] . '" (' . iv_score($p['ai_score'], $p['ai_max']) . ') is ranked above "' . $q['title'] . '" (' . iv_score($q['ai_score'], $q['ai_max']) . ')'];
            }
        }
    }
    usort($out, static fn($x, $y) => $y[0] <=> $x[0]);
    return array_column($out, 1);
}

/**
 * HoD: one decision for many ideas of their department at once (the Students
 * page). Each idea keeps its note. Returns [how many changed, problems].
 */
function iv_ideas_decide_many(int $org, array $ids, int $deptId, string $status, string $actor): array
{
    $done = 0;
    $problems = [];
    foreach (array_unique(array_map('intval', $ids)) as $id) {
        $i = iv_idea($org, $id);
        if (!$i || (int) $i['department_id'] !== $deptId) {
            $problems[] = 'One idea is not in your department.';
            continue;
        }
        if ($i['status'] === $status) {
            continue;
        }
        try {
            iv_idea_decide($org, $id, $deptId, $status, (string) $i['hod_note'], $actor);
            $done++;
        } catch (InvalidArgumentException $e) {
            $problems[] = '"' . $i['title'] . '": ' . $e->getMessage();
        }
    }
    return [$done, array_values(array_unique($problems))];
}

/**
 * What the owner should know before trusting a HoD's decision on this idea.
 * Each flag: ['k' => key, 'text' => words]. Shown on the owner's idea page
 * and as a count on the pipeline card.
 */
function iv_idea_flags(int $org, array $i): array
{
    $flags = [];
    $runs = fabo_all('SELECT mode, score, max_score FROM iv_ai_runs WHERE org_id = ? AND idea_id = ? AND state = "done"', 'ii', [$org, (int) $i['id']]);
    $by = ['portal' => [], 'own' => []];
    foreach ($runs as $r) {
        $by[$r['mode']][] = (float) $r['max_score'] > 0 ? (float) $r['score'] / (float) $r['max_score'] : 0;
    }
    if (!$runs && $i['status'] === 'validated') {
        $flags[] = ['k' => 'no_check', 'text' => 'Validated without any AI check.'];
    }
    if ($by['own'] && !$by['portal']) {
        $flags[] = ['k' => 'own_only', 'text' => 'Scored only in the HoD\'s own AI tool, not in the portal. Their pasted reply is kept with the check. "Verify with AI" scores it here.'];
    }
    if ($by['own'] && $by['portal']) {
        $gap = abs(array_sum($by['own']) / count($by['own']) - array_sum($by['portal']) / count($by['portal']));
        if ($gap >= 0.2) {
            $flags[] = ['k' => 'disagree', 'text' => 'The own-tool score and the portal score differ by ' . round($gap * 100) . ' percentage points.'];
        }
    }
    if ($i['status'] === 'validated' && $i['ai_score'] !== null && (float) $i['ai_max'] > 0 && (float) $i['ai_score'] / (float) $i['ai_max'] < 0.4) {
        $flags[] = ['k' => 'low', 'text' => 'Validated although the AI scored it under 40%.'];
    }
    if (trim((string) ($i['pick_note'] ?? '')) !== '') {
        $flags[] = ['k' => 'order', 'text' => 'Ranked against the AI scores. The HoD\'s reason: ' . $i['pick_note']];
    }
    if ($i['forwarded_at'] && $i['ai_score'] !== null && (float) $i['ai_max'] > 0) {
        $n = (int) (fabo_one('SELECT COUNT(*) n FROM iv_ideas WHERE org_id = ? AND round_id = ? AND department_id = ? AND status = "rejected" AND ai_max > 0 AND ai_score / ai_max > ?',
                             'iiid', [$org, (int) $i['round_id'], (int) $i['department_id'], (float) $i['ai_score'] / (float) $i['ai_max'] + 0.0001])['n'] ?? 0);
        if ($n) {
            $flags[] = ['k' => 'passed', 'text' => $n . ' idea' . ($n === 1 ? '' : 's') . ' in this department scored higher but ' . ($n === 1 ? 'was' : 'were') . ' marked Not taken.'];
        }
    }
    $src = fabo_one('SELECT source FROM iv_students WHERE id = ?', 'i', [(int) $i['student_id']]);
    if ($src && $src['source'] === 'form') {
        $flags[] = ['k' => 'new_student', 'text' => 'The student was not on your list: they were added when this idea came in through the HoD\'s form.'];
    }
    return $flags;
}

/** Owner: hands a department's picks back to its HoD (out of the pipeline; teams other than the lead are kept off). */
function iv_return_dept(int $org, int $roundId, int $deptId, string $actor): int
{
    $ids = array_map(static fn($r) => (int) $r['id'], fabo_all('SELECT id FROM iv_ideas WHERE org_id = ? AND round_id = ? AND department_id = ? AND forwarded_at IS NOT NULL', 'iii', [$org, $roundId, $deptId]));
    if (!$ids) {
        throw new InvalidArgumentException('Nothing from that department is in the pipeline.');
    }
    if (fabo_one('SELECT id FROM iv_team WHERE org_id = ? AND idea_id IN (' . implode(',', $ids) . ') AND role = "member" LIMIT 1', 'i', [$org])) {
        throw new InvalidArgumentException('Teams have been assigned to these ideas. Remove the team members first.');
    }
    iv_tx(static function () use ($org, $ids, $actor) {
        foreach ($ids as $id) {
            fabo_exec('UPDATE iv_ideas SET forwarded_at = NULL, stage = NULL, stage_at = NULL WHERE id = ?', 'i', [$id]);
            fabo_exec('DELETE FROM iv_team WHERE idea_id = ?', 'i', [$id]);
            iv_log($org, $id, $actor, 'Returned to the Head of Department.');
        }
    });
    return count($ids);
}

/** Owner: moves an idea along the pipeline. */
function iv_stage_set(int $org, int $ideaId, string $stage, string $note, string $actor): void
{
    $idea = iv_idea($org, $ideaId);
    if (!$idea || $idea['stage'] === null) {
        throw new InvalidArgumentException('That idea is not in the pipeline.');
    }
    if (!isset(iv_stages()[$stage])) {
        throw new InvalidArgumentException('Pick a stage.');
    }
    if ($stage === $idea['stage'] && trim($note) === '') {
        return;
    }
    fabo_exec('UPDATE iv_ideas SET stage = ?, stage_at = IF(stage = ?, stage_at, ?)' . (trim($note) !== '' ? ', owner_note = ?' : '') . ' WHERE id = ?',
              trim($note) !== '' ? 'ssssi' : 'sssi', trim($note) !== '' ? [$stage, $stage, iv_now(), iv_text($note, 3000), $ideaId] : [$stage, $stage, iv_now(), $ideaId]);
    if ($stage !== $idea['stage']) {
        iv_log($org, $ideaId, $actor, 'Moved to ' . iv_stages()[$stage] . '.');
    }
}

/* ================================================================== team */

function iv_team(int $org, int $ideaId): array
{
    return fabo_all('SELECT m.*, s.name, COALESCE(NULLIF(s.roll_typed, ""), s.roll_no) AS roll_no, s.email, s.phone, d.name AS dept_name, c.name AS college_name FROM iv_team m
                       JOIN iv_students s ON s.id = m.student_id LEFT JOIN iv_departments d ON d.id = s.department_id LEFT JOIN iv_colleges c ON c.id = s.college_id
                      WHERE m.org_id = ? AND m.idea_id = ? ORDER BY m.role = "lead" DESC, s.name', 'ii', [$org, $ideaId]);
}

/** Owner: puts a student on a project's team. A student sits on one team per round. */
function iv_team_add(int $org, int $ideaId, int $studentId, string $actor): void
{
    $idea = iv_idea($org, $ideaId);
    if (!$idea || $idea['stage'] === null) {
        throw new InvalidArgumentException('Teams are assigned to ideas in the pipeline.');
    }
    $s = iv_student($org, $studentId);
    if (!$s) {
        throw new InvalidArgumentException('That student is not in this organisation.');
    }
    $on = fabo_one('SELECT m.idea_id, i.title FROM iv_team m JOIN iv_ideas i ON i.id = m.idea_id WHERE m.round_id = ? AND m.student_id = ?', 'ii', [(int) $idea['round_id'], $studentId]);
    if ($on) {
        throw new InvalidArgumentException($s['name'] . ((int) $on['idea_id'] === $ideaId ? ' is already on this team.' : ' is already on the team of "' . $on['title'] . '" in this round.'));
    }
    fabo_exec('INSERT INTO iv_team (org_id, round_id, idea_id, student_id, role, added_by, created_at) VALUES (?,?,?,?, "member", ?, ?)',
              'iiiiss', [$org, (int) $idea['round_id'], $ideaId, $studentId, $actor, iv_now()]);
    iv_log($org, $ideaId, $actor, 'Added ' . $s['name'] . ($s['roll_no'] !== '' ? ' (' . $s['roll_no'] . ')' : '') . ' to the team.');
    if (in_array($idea['stage'], ['forwarded', 'review'], true)) {
        iv_stage_set($org, $ideaId, 'team', '', $actor);
    }
}

function iv_team_remove(int $org, int $ideaId, int $studentId, string $actor): void
{
    $m = fabo_one('SELECT m.*, s.name FROM iv_team m JOIN iv_students s ON s.id = m.student_id WHERE m.org_id = ? AND m.idea_id = ? AND m.student_id = ?', 'iii', [$org, $ideaId, $studentId]);
    if (!$m) {
        return;
    }
    if ($m['role'] === 'lead') {
        throw new InvalidArgumentException('The student whose idea it is leads the team and stays on it.');
    }
    fabo_exec('DELETE FROM iv_team WHERE id = ?', 'i', [(int) $m['id']]);
    iv_log($org, $ideaId, $actor, 'Took ' . $m['name'] . ' off the team.');
}

/** Students free to join a team in a round, for the picker: by name / roll, nearest first (same department, then college). */
function iv_team_candidates(int $org, array $idea, string $q, int $limit = 30): array
{
    $like = '%' . addcslashes(trim($q), '%_\\') . '%';
    return fabo_all('SELECT s.id, s.name, COALESCE(NULLIF(s.roll_typed, ""), s.roll_no) AS roll_no, d.name AS dept_name, c.name AS college_name FROM iv_students s
                       LEFT JOIN iv_departments d ON d.id = s.department_id LEFT JOIN iv_colleges c ON c.id = s.college_id
                      WHERE s.org_id = ? AND (s.name LIKE ? OR s.roll_no LIKE ? OR s.roll_typed LIKE ? OR s.email LIKE ?)
                        AND NOT EXISTS (SELECT 1 FROM iv_team m WHERE m.round_id = ? AND m.student_id = s.id)
                      ORDER BY s.department_id = ? DESC, s.college_id = ? DESC, s.name LIMIT ' . max(1, $limit),
                    'issssiii', [$org, $like, $like, $like, $like, (int) $idea['round_id'], (int) $idea['department_id'], (int) $idea['college_id']]);
}

/* ================================================================ counts */

/** Per-department progress for a round: dept id => [ideas, validated, rejected, ranked, forwarded, checked]. */
function iv_dept_progress(int $org, int $roundId): array
{
    $out = [];
    foreach (fabo_all('SELECT department_id, COUNT(*) ideas, SUM(status = "validated") validated, SUM(status = "rejected") rejected,
                              SUM(hod_rank IS NOT NULL) ranked, SUM(forwarded_at IS NOT NULL) forwarded,
                              SUM(ai_score IS NOT NULL) checked
                         FROM iv_ideas WHERE org_id = ? AND round_id = ? GROUP BY department_id', 'ii', [$org, $roundId]) as $r) {
        $out[(int) $r['department_id']] = array_map('intval', $r);
    }
    return $out;
}

function iv_totals(int $org, ?int $roundId): array
{
    $t = fabo_one('SELECT (SELECT COUNT(*) FROM iv_colleges WHERE org_id = ?) colleges, (SELECT COUNT(*) FROM iv_departments WHERE org_id = ?) depts,
                          (SELECT COUNT(*) FROM iv_hods WHERE org_id = ?) hods, (SELECT COUNT(*) FROM iv_hods WHERE org_id = ? AND pass_hash IS NOT NULL) hods_ready,
                          (SELECT COUNT(*) FROM iv_students WHERE org_id = ?) students', 'iiiii', [$org, $org, $org, $org, $org]);
    $t = array_map('intval', $t ?: []);
    $i = $roundId ? fabo_one('SELECT COUNT(*) ideas, SUM(status = "validated") validated, SUM(status = "rejected") rejected, SUM(forwarded_at IS NOT NULL) forwarded,
                                     SUM(stage = "selected") selected FROM iv_ideas WHERE org_id = ? AND round_id = ?', 'ii', [$org, $roundId]) : null;
    foreach (['ideas', 'validated', 'rejected', 'forwarded', 'selected'] as $k) {
        $t[$k] = (int) ($i[$k] ?? 0);
    }
    return $t;
}

/** A score as "72.5 / 100". */
function iv_score($score, $max): string
{
    if ($score === null || $score === '') {
        return '—';
    }
    $f = static fn($n) => rtrim(rtrim(number_format((float) $n, 1), '0'), '.');
    return $f($score) . ' / ' . $f($max);
}

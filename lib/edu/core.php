<?php
/**
 * Academic — the data and the rules.
 *
 * An organisation (a government council, a university, a group of colleges)
 * keeps its institutions, their departments, and the people in them:
 * students, faculty, mentors and institution admins. One person is one row in
 * edu_people however many roles they hold; each role is a row in edu_roles
 * tied to an institution (and a department where it has one). A mentor may sit
 * across the whole organisation (institution_id 0).
 *
 * These people are NOT portal accounts in `clients`: thousands of students
 * must never appear on My Team, in committee digests or the IT helpdesk. They
 * sign in to their own Campus portal (campus.php, lib/edu/portal.php) with
 * their own password, kept here.
 *
 * Every table carries org_id (a client's head id, 0 for ours) and every
 * query here filters by it. The module is granted like Co-working:
 * 'academic' in fabo_modules().
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../google.php';     // fabo_setting()

const EDU_SCHEMA_VERSION = 1;
const EDU_INVITE_DAYS = 7;

/** Now, on PHP's clock (the app's time zone). The database clock may be UTC: every stored time in Academic is written and compared with this. */
function edu_now(int $offset = 0): string
{
    return date('Y-m-d H:i:s', time() + $offset);
}

/* ================================================================ schema */

function edu_schema_sql(): array
{
    $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
"CREATE TABLE IF NOT EXISTS edu_settings (
  org_id INT UNSIGNED NOT NULL,
  k VARCHAR(64) NOT NULL,
  v TEXT NULL,
  PRIMARY KEY (org_id, k)
) $t",

"CREATE TABLE IF NOT EXISTS edu_institutions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  name VARCHAR(190) NOT NULL,
  kind VARCHAR(30) NOT NULL DEFAULT 'College',
  code VARCHAR(40) NOT NULL DEFAULT '',
  district VARCHAR(80) NOT NULL DEFAULT '',
  city VARCHAR(80) NOT NULL DEFAULT '',
  contact_name VARCHAR(120) NOT NULL DEFAULT '',
  contact_email VARCHAR(160) NOT NULL DEFAULT '',
  contact_phone VARCHAR(40) NOT NULL DEFAULT '',
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY u_name (org_id, name),
  KEY k_org (org_id, status)
) $t",

"CREATE TABLE IF NOT EXISTS edu_departments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  institution_id INT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY u_name (institution_id, name),
  KEY k_org (org_id, institution_id)
) $t",

"CREATE TABLE IF NOT EXISTS edu_people (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  email VARCHAR(160) NULL,
  phone VARCHAR(40) NOT NULL DEFAULT '',
  roll_no VARCHAR(60) NOT NULL DEFAULT '',
  apaar_id VARCHAR(40) NOT NULL DEFAULT '',
  programme VARCHAR(120) NOT NULL DEFAULT '',
  year_of_study TINYINT UNSIGNED NULL,
  status ENUM('active','suspended') NOT NULL DEFAULT 'active',
  pass_hash VARCHAR(255) NULL,
  pass_set_at DATETIME NULL,
  must_change TINYINT(1) NOT NULL DEFAULT 0,
  invite_hash CHAR(64) NULL,
  invite_expires DATETIME NULL,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY u_email (org_id, email),
  UNIQUE KEY u_invite (invite_hash),
  KEY k_org (org_id, status),
  KEY k_roll (org_id, roll_no)
) $t",

"CREATE TABLE IF NOT EXISTS edu_roles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  person_id INT UNSIGNED NOT NULL,
  role ENUM('student','faculty','mentor','inst_admin') NOT NULL,
  institution_id INT UNSIGNED NOT NULL DEFAULT 0,
  department_id INT UNSIGNED NOT NULL DEFAULT 0,
  title VARCHAR(160) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY u_role (person_id, role, institution_id, department_id),
  KEY k_org (org_id, role, institution_id),
  KEY k_dept (department_id)
) $t",

"CREATE TABLE IF NOT EXISTS edu_mentor_links (
  org_id INT UNSIGNED NOT NULL,
  mentor_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (mentor_id, student_id),
  KEY k_student (student_id),
  KEY k_org (org_id)
) $t",

"CREATE TABLE IF NOT EXISTS edu_audit (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  actor VARCHAR(190) NOT NULL DEFAULT '',
  action VARCHAR(40) NOT NULL,
  subject VARCHAR(255) NOT NULL DEFAULT '',
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_org (org_id, id)
) $t",
    ];
}

function edu_install(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if ((int) fabo_setting('edu_schema', '0') >= EDU_SCHEMA_VERSION) {
        return;
    }
    foreach (edu_schema_sql() as $sql) {
        fabo_exec($sql);
    }
    fabo_exec('INSERT INTO app_settings (k, v) VALUES ("edu_schema", ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', 's', [(string) EDU_SCHEMA_VERSION]);
}

/* ================================================================ access */

/**
 * The Academic grant that applies: 'none' | 'view' | 'edit'. A client's team
 * member never holds more than their head holds now, read fresh.
 */
function edu_grant(array $viewer): string
{
    if (!empty($viewer['is_admin'])) {
        return 'edit';
    }
    if (!fabo_signed_in()) {
        return 'none';
    }
    $p = fabo_module_permission($viewer, 'academic');
    $c = $viewer['client'] ?? [];
    $parent = (int) ($c['parent_id'] ?? 0);
    if ($p !== 'none' && ($c['kind'] ?? '') === 'team' && $parent > 0) {
        $cap = (string) (fabo_client_access($parent)['academic'] ?? 'none');
        $rank = ['none' => 0, 'view' => 1, 'edit' => 2];
        $p = ($rank[$cap] ?? 0) < $rank[$p] ? (isset($rank[$cap]) ? $cap : 'none') : $p;
    }
    return $p;
}

/** Organisations holding the module, for the administrator's switcher: id => name. Ours (0) first. */
function edu_orgs(): array
{
    $out = [0 => 'Our own'];
    try {
        $out += fabo_module_orgs('academic');       // clients at any level, a client's own clients too
    } catch (Throwable $e) {
        // not installed yet: ours only
    }
    return $out;
}

/** Whose Academic this request is about. A client: their own. The administrator: the one picked. */
function edu_org(array $viewer): int
{
    if (empty($viewer['is_admin'])) {
        return fabo_module_org_of($viewer);
    }
    $orgs = edu_orgs();
    $want = isset($_REQUEST['org']) ? (int) $_REQUEST['org'] : null;
    if ($want !== null && isset($orgs[$want])) {
        return $want;
    }
    $ids = array_keys($orgs);
    return (int) ($ids[1] ?? 0);
}

function edu_org_name(int $org): string
{
    if ($org <= 0) {
        return fabo_app_name();
    }
    $c = fabo_one('SELECT name, organisation FROM clients WHERE id = ?', 'i', [$org]);
    return trim((string) ($c['organisation'] ?? '')) ?: trim((string) ($c['name'] ?? '')) ?: fabo_app_name();
}

/** The head of the organisation, to send mail under its name. */
function edu_org_actor(int $org): ?array
{
    if ($org <= 0) {
        return null;
    }
    $c = fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [$org]);
    if ($c) {
        $c['send_as_name'] = edu_org_name($org);
    }
    return $c ?: null;
}

/* ============================================================== settings */

function edu_get(int $org, string $k, string $default = ''): string
{
    $r = fabo_one('SELECT v FROM edu_settings WHERE org_id = ? AND k = ?', 'is', [$org, $k]);
    return $r === null ? $default : (string) $r['v'];
}

function edu_set(int $org, string $k, string $v): void
{
    fabo_exec('INSERT INTO edu_settings (org_id, k, v) VALUES (?,?,?) ON DUPLICATE KEY UPDATE v = VALUES(v)', 'iss', [$org, $k, $v]);
}

/** The roles, in the order they are offered. */
function edu_roles_all(): array
{
    return ['student' => 'Student', 'faculty' => 'Faculty', 'mentor' => 'Mentor', 'inst_admin' => 'Institution admin', 'council' => 'Council'];
}

function edu_role_label(string $role): string
{
    return edu_roles_all()[$role] ?? $role;
}

/** Is the Campus portal open to this role in this organisation? All are, until switched off. */
function edu_portal_on(int $org, string $role): bool
{
    return isset(edu_roles_all()[$role]) && edu_get($org, 'portal_' . $role, '1') === '1';
}

/** The key in the Campus portal's address that says whose it is. Made on first use. */
function edu_portal_key(int $org): string
{
    $k = edu_get($org, 'portal_key');
    if (!preg_match('/^[0-9a-f]{16}$/', $k)) {
        $k = bin2hex(random_bytes(8));
        edu_set($org, 'portal_key', $k);
    }
    return $k;
}

/** The organisation a portal key belongs to, or null. */
function edu_org_by_key(string $key): ?int
{
    if (!preg_match('/^[0-9a-f]{16}$/', $key)) {
        return null;
    }
    edu_install();
    $r = fabo_one('SELECT org_id FROM edu_settings WHERE k = "portal_key" AND v = ?', 's', [$key]);
    return $r ? (int) $r['org_id'] : null;
}

/** The portal's own base address, ending in a slash: the organisation's address when it has one. */
function edu_base_url(int $org): string
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

function edu_portal_url(int $org, array $q = []): string
{
    return edu_base_url($org) . 'campus.php?' . http_build_query(['o' => edu_portal_key($org)] + $q);
}

/** A headline number tile (desk, reports, portal). */
function edu_kpi(string $label, $value, string $tone = '', string $href = ''): string
{
    $tag = $href !== '' ? 'a href="' . e($href) . '"' : 'div';
    return '<' . $tag . ' class="edu-kpi ' . e($tone) . '"><span class="k">' . e($label) . '</span><strong>' . e(is_int($value) ? fabo_thousands($value) : (string) $value) . '</strong></' . ($href !== '' ? 'a' : 'div') . '>';
}

/* ================================================================= audit */

function edu_audit(int $org, string $actor, string $action, string $subject = ''): void
{
    try {
        require_once __DIR__ . '/../throttle.php';
        fabo_exec('INSERT INTO edu_audit (org_id, actor, action, subject, ip) VALUES (?,?,?,?,?)', 'issss',
                  [$org, mb_substr($actor, 0, 190), $action, mb_substr($subject, 0, 255), fabo_client_ip()]);
    } catch (Throwable $e) {
        error_log('FABO edu audit: ' . $e->getMessage());
    }
}

/* ========================================================== institutions */

function edu_inst_kinds(): array
{
    return ['University', 'College', 'Polytechnic', 'ITI', 'School', 'Research institute', 'Other'];
}

function edu_institutions(int $org, bool $activeOnly = false): array
{
    edu_install();
    return fabo_all('SELECT * FROM edu_institutions WHERE org_id = ?' . ($activeOnly ? ' AND status = "active"' : '') . ' ORDER BY name', 'i', [$org]);
}

function edu_institution(int $org, int $id): ?array
{
    return fabo_one('SELECT * FROM edu_institutions WHERE org_id = ? AND id = ?', 'ii', [$org, $id]);
}

function edu_clean(?string $s, int $max): string
{
    $s = trim(preg_replace('/\s+/u', ' ', (string) $s));
    return mb_substr($s, 0, $max);
}

/** Adds or updates one institution. Returns its id. Throws with a reason a person can read. */
function edu_institution_save(int $org, array $d, ?int $id = null): int
{
    $name = edu_clean($d['name'] ?? '', 190);
    if ($name === '') {
        throw new InvalidArgumentException('Give the institution a name.');
    }
    $kind = in_array($d['kind'] ?? '', edu_inst_kinds(), true) ? $d['kind'] : 'College';
    $email = edu_clean($d['contact_email'] ?? '', 160);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('The contact email does not look right.');
    }
    $clash = fabo_one('SELECT id FROM edu_institutions WHERE org_id = ? AND name = ?', 'is', [$org, $name]);
    if ($clash && (int) $clash['id'] !== (int) $id) {
        throw new InvalidArgumentException('There is already an institution called ' . $name . '.');
    }
    $vals = [$name, $kind, edu_clean($d['code'] ?? '', 40), edu_clean($d['district'] ?? '', 80), edu_clean($d['city'] ?? '', 80),
             edu_clean($d['contact_name'] ?? '', 120), $email, edu_clean($d['contact_phone'] ?? '', 40),
             ($d['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active'];
    if ($id) {
        if (!edu_institution($org, $id)) {
            throw new InvalidArgumentException('That institution is not in this organisation.');
        }
        fabo_exec('UPDATE edu_institutions SET name=?, kind=?, code=?, district=?, city=?, contact_name=?, contact_email=?, contact_phone=?, status=?
                    WHERE org_id = ? AND id = ?', 'sssssssssii', array_merge($vals, [$org, $id]));
        return $id;
    }
    return fabo_exec('INSERT INTO edu_institutions (name, kind, code, district, city, contact_name, contact_email, contact_phone, status, org_id)
                      VALUES (?,?,?,?,?,?,?,?,?,?)', 'sssssssssi', array_merge($vals, [$org]));
}

/** Removes an institution, its departments and the roles held in it. People with no role left stay as records. */
function edu_institution_delete(int $org, int $id): void
{
    if (!edu_institution($org, $id)) {
        throw new InvalidArgumentException('That institution is not in this organisation.');
    }
    fabo_exec('DELETE FROM edu_roles WHERE org_id = ? AND institution_id = ?', 'ii', [$org, $id]);
    fabo_exec('DELETE FROM edu_departments WHERE org_id = ? AND institution_id = ?', 'ii', [$org, $id]);
    fabo_exec('DELETE FROM edu_institutions WHERE org_id = ? AND id = ?', 'ii', [$org, $id]);
}

/* =========================================================== departments */

function edu_departments(int $org, ?int $instId = null): array
{
    if ($instId !== null) {
        return fabo_all('SELECT * FROM edu_departments WHERE org_id = ? AND institution_id = ? ORDER BY name', 'ii', [$org, $instId]);
    }
    return fabo_all('SELECT * FROM edu_departments WHERE org_id = ? ORDER BY institution_id, name', 'i', [$org]);
}

function edu_department(int $org, int $id): ?array
{
    return fabo_one('SELECT * FROM edu_departments WHERE org_id = ? AND id = ?', 'ii', [$org, $id]);
}

function edu_department_save(int $org, int $instId, string $name, ?int $id = null): int
{
    $name = edu_clean($name, 160);
    if ($name === '') {
        throw new InvalidArgumentException('Give the department a name.');
    }
    if (!edu_institution($org, $instId)) {
        throw new InvalidArgumentException('That institution is not in this organisation.');
    }
    $clash = fabo_one('SELECT id FROM edu_departments WHERE institution_id = ? AND name = ?', 'is', [$instId, $name]);
    if ($clash && (int) $clash['id'] !== (int) $id) {
        throw new InvalidArgumentException('This institution already has a department called ' . $name . '.');
    }
    if ($id) {
        $d = edu_department($org, $id);
        if (!$d || (int) $d['institution_id'] !== $instId) {
            throw new InvalidArgumentException('That department is not in this institution.');
        }
        fabo_exec('UPDATE edu_departments SET name = ? WHERE org_id = ? AND id = ?', 'sii', [$name, $org, $id]);
        return $id;
    }
    return fabo_exec('INSERT INTO edu_departments (org_id, institution_id, name) VALUES (?,?,?)', 'iis', [$org, $instId, $name]);
}

/** Removes a department. Roles in it stay, at the institution, without a department. */
function edu_department_delete(int $org, int $id): void
{
    if (!edu_department($org, $id)) {
        throw new InvalidArgumentException('That department is not in this organisation.');
    }
    // A role that would clash with the same role at institution level is the same thing twice: drop it.
    foreach (fabo_all('SELECT id, person_id, role, institution_id FROM edu_roles WHERE org_id = ? AND department_id = ?', 'ii', [$org, $id]) as $r) {
        $dup = fabo_one('SELECT id FROM edu_roles WHERE person_id = ? AND role = ? AND institution_id = ? AND department_id = 0',
                        'isi', [(int) $r['person_id'], $r['role'], (int) $r['institution_id']]);
        if ($dup) {
            fabo_exec('DELETE FROM edu_roles WHERE id = ?', 'i', [(int) $r['id']]);
        } else {
            fabo_exec('UPDATE edu_roles SET department_id = 0 WHERE id = ?', 'i', [(int) $r['id']]);
        }
    }
    fabo_exec('DELETE FROM edu_departments WHERE org_id = ? AND id = ?', 'ii', [$org, $id]);
}

/** A department by name in an institution, made if asked to. */
function edu_department_find(int $org, int $instId, string $name, bool $make): int
{
    $name = edu_clean($name, 160);
    if ($name === '') {
        return 0;
    }
    $r = fabo_one('SELECT id FROM edu_departments WHERE org_id = ? AND institution_id = ? AND name = ?', 'iis', [$org, $instId, $name]);
    if ($r) {
        return (int) $r['id'];
    }
    return $make ? edu_department_save($org, $instId, $name) : -1;
}

/* ================================================================ people */

function edu_person(int $org, int $id): ?array
{
    return fabo_one('SELECT * FROM edu_people WHERE org_id = ? AND id = ?', 'ii', [$org, $id]);
}

function edu_person_by_email(int $org, string $email): ?array
{
    $email = mb_strtolower(trim($email));
    return $email === '' ? null : fabo_one('SELECT * FROM edu_people WHERE org_id = ? AND email = ?', 'is', [$org, $email]);
}

/** Adds or updates one person's record (not their roles). Returns their id. */
function edu_person_save(int $org, array $d, ?int $id = null): int
{
    $name = edu_clean($d['name'] ?? '', 160);
    if ($name === '') {
        throw new InvalidArgumentException('Give the person a name.');
    }
    $email = mb_strtolower(edu_clean($d['email'] ?? '', 160));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('The email ' . $email . ' does not look right.');
    }
    if ($email !== '') {
        $clash = edu_person_by_email($org, $email);
        if ($clash && (int) $clash['id'] !== (int) $id) {
            throw new InvalidArgumentException($email . ' already belongs to ' . $clash['name'] . '.');
        }
    }
    $year = (int) ($d['year_of_study'] ?? 0);
    $year = $year >= 1 && $year <= 10 ? $year : null;
    $vals = [$name, $email !== '' ? $email : null, edu_clean($d['phone'] ?? '', 40), edu_clean($d['roll_no'] ?? '', 60),
             edu_clean($d['apaar_id'] ?? '', 40), edu_clean($d['programme'] ?? '', 120), $year];
    if ($id) {
        if (!edu_person($org, $id)) {
            throw new InvalidArgumentException('That person is not in this organisation.');
        }
        fabo_exec('UPDATE edu_people SET name=?, email=?, phone=?, roll_no=?, apaar_id=?, programme=?, year_of_study=? WHERE org_id = ? AND id = ?',
                  'ssssssiii', array_merge($vals, [$org, $id]));
        return $id;
    }
    return fabo_exec('INSERT INTO edu_people (name, email, phone, roll_no, apaar_id, programme, year_of_study, org_id) VALUES (?,?,?,?,?,?,?,?)',
                     'ssssssii', array_merge($vals, [$org]));
}

function edu_person_status(int $org, int $id, string $status): void
{
    fabo_exec('UPDATE edu_people SET status = ? WHERE org_id = ? AND id = ?', 'sii', [$status === 'suspended' ? 'suspended' : 'active', $org, $id]);
}

function edu_person_delete(int $org, int $id): void
{
    if (!edu_person($org, $id)) {
        throw new InvalidArgumentException('That person is not in this organisation.');
    }
    fabo_exec('DELETE FROM edu_roles WHERE org_id = ? AND person_id = ?', 'ii', [$org, $id]);
    fabo_exec('DELETE FROM edu_mentor_links WHERE org_id = ? AND (mentor_id = ? OR student_id = ?)', 'iii', [$org, $id, $id]);
    // Their learning and sessions go with them; a session they booked opens again if it is still ahead.
    require_once __DIR__ . '/lms.php';
    edu_lms_install();
    foreach (['edu_enrolments', 'edu_lesson_done', 'edu_attempts'] as $t) {
        fabo_exec("DELETE FROM `$t` WHERE org_id = ? AND person_id = ?", 'ii', [$org, $id]);
    }
    fabo_exec('DELETE FROM edu_slots WHERE org_id = ? AND mentor_id = ?', 'ii', [$org, $id]);
    // Their rewards: the ledger, badges and orders (stock held by a pending order goes back on the shelf).
    edu_game_install();
    foreach (fabo_all('SELECT item_id FROM edu_orders WHERE org_id = ? AND person_id = ? AND status = "pending"', 'ii', [$org, $id]) as $o) {
        fabo_exec('UPDATE edu_store_items SET stock = stock + 1 WHERE id = ? AND stock IS NOT NULL', 'i', [(int) $o['item_id']]);
    }
    require_once __DIR__ . '/ideas.php';
    edu_ideas_install();
    require_once __DIR__ . '/privacy.php';
    edu_privacy_install();
    fabo_exec('DELETE FROM edu_login_codes WHERE org_id = ? AND person_id = ?', 'ii', [$org, $id]);
    foreach (['edu_ledger', 'edu_badges', 'edu_orders', 'edu_ideas', 'edu_consents'] as $t) {
        fabo_exec("DELETE FROM `$t` WHERE org_id = ? AND person_id = ?", 'ii', [$org, $id]);
    }
    fabo_exec('UPDATE edu_slots SET status = IF(starts_at > ?, "open", status), student_id = NULL, topic = NULL, booked_at = NULL WHERE org_id = ? AND student_id = ?',
              'sii', [edu_now(), $org, $id]);
    fabo_exec('DELETE FROM edu_people WHERE org_id = ? AND id = ?', 'ii', [$org, $id]);
}

/** One person's roles, with the institution and department names. */
function edu_person_roles(int $org, int $pid): array
{
    return fabo_all('SELECT r.*, COALESCE(i.name, "") AS inst_name, COALESCE(d.name, "") AS dept_name
                       FROM edu_roles r
                  LEFT JOIN edu_institutions i ON i.id = r.institution_id AND i.org_id = r.org_id
                  LEFT JOIN edu_departments d ON d.id = r.department_id AND d.org_id = r.org_id
                      WHERE r.org_id = ? AND r.person_id = ?
                   ORDER BY FIELD(r.role, "council", "inst_admin", "faculty", "mentor", "student"), i.name, d.name', 'ii', [$org, $pid]);
}

/**
 * Gives a person a role. A student, faculty member or institution admin
 * belongs to an institution; a mentor may sit across the organisation (0).
 * The department, when given, must be in that institution. Returns the role id.
 */
function edu_role_add(int $org, int $pid, string $role, int $instId = 0, int $deptId = 0, string $title = ''): int
{
    if (!isset(edu_roles_all()[$role])) {
        throw new InvalidArgumentException('Pick a role.');
    }
    if (!edu_person($org, $pid)) {
        throw new InvalidArgumentException('That person is not in this organisation.');
    }
    if ($role === 'council') {
        $instId = 0;     // the council sees every institution
        $deptId = 0;
    }
    if ($instId === 0 && !in_array($role, ['mentor', 'council'], true)) {
        throw new InvalidArgumentException(edu_role_label($role) . ' needs an institution.');
    }
    if ($instId > 0 && !edu_institution($org, $instId)) {
        throw new InvalidArgumentException('That institution is not in this organisation.');
    }
    if ($deptId > 0) {
        $d = edu_department($org, $deptId);
        if (!$d || (int) $d['institution_id'] !== $instId) {
            throw new InvalidArgumentException('That department is not in this institution.');
        }
    }
    if ($role === 'inst_admin') {
        $deptId = 0;     // an institution admin runs the whole institution
    }
    $have = fabo_one('SELECT id FROM edu_roles WHERE person_id = ? AND role = ? AND institution_id = ? AND department_id = ?',
                     'isii', [$pid, $role, $instId, $deptId]);
    if ($have) {
        fabo_exec('UPDATE edu_roles SET title = ? WHERE id = ?', 'si', [edu_clean($title, 160), (int) $have['id']]);
        return (int) $have['id'];
    }
    return fabo_exec('INSERT INTO edu_roles (org_id, person_id, role, institution_id, department_id, title) VALUES (?,?,?,?,?,?)',
                     'iisiis', [$org, $pid, $role, $instId, $deptId, edu_clean($title, 160)]);
}

function edu_role_remove(int $org, int $roleId): ?array
{
    $r = fabo_one('SELECT * FROM edu_roles WHERE org_id = ? AND id = ?', 'ii', [$org, $roleId]);
    if ($r) {
        fabo_exec('DELETE FROM edu_roles WHERE id = ?', 'i', [$roleId]);
        if ($r['role'] === 'mentor' && !fabo_one('SELECT id FROM edu_roles WHERE person_id = ? AND role = "mentor"', 'i', [(int) $r['person_id']])) {
            fabo_exec('DELETE FROM edu_mentor_links WHERE org_id = ? AND mentor_id = ?', 'ii', [$org, (int) $r['person_id']]);
        }
    }
    return $r;
}

/**
 * People, filtered. $f: role, inst (id), dept (id), q (name, email, roll no),
 * insts (list of institution ids the viewer is limited to), limit, offset.
 * Each row carries 'roles' (role, institution, department) and 'n' the total.
 */
function edu_people(int $org, array $f = []): array
{
    $where = ['p.org_id = ?'];
    $types = 'i';
    $params = [$org];
    $join = [];
    if (!empty($f['role']) && isset(edu_roles_all()[$f['role']])) {
        $join[] = 'r.role = ?';
        $types .= 's';
        $params[] = $f['role'];
    }
    if (!empty($f['inst'])) {
        $join[] = 'r.institution_id = ?';
        $types .= 'i';
        $params[] = (int) $f['inst'];
    }
    if (!empty($f['dept'])) {
        $join[] = 'r.department_id = ?';
        $types .= 'i';
        $params[] = (int) $f['dept'];
    }
    if (isset($f['insts'])) {
        $ids = array_values(array_filter(array_map('intval', (array) $f['insts'])));
        if (!$ids) {
            return [];
        }
        $join[] = 'r.institution_id IN (' . implode(',', $ids) . ')';
    }
    if ($join) {
        $where[] = 'EXISTS (SELECT 1 FROM edu_roles r WHERE r.person_id = p.id AND r.org_id = p.org_id AND ' . implode(' AND ', $join) . ')';
    }
    $q = trim((string) ($f['q'] ?? ''));
    if ($q !== '') {
        $where[] = '(p.name LIKE ? OR p.email LIKE ? OR p.roll_no LIKE ? OR p.phone LIKE ?)';
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $types .= 'ssss';
        array_push($params, $like, $like, $like, $like);
    }
    $limit = max(1, min(500, (int) ($f['limit'] ?? 100)));
    $offset = max(0, (int) ($f['offset'] ?? 0));
    $sql = 'SELECT SQL_CALC_FOUND_ROWS p.* FROM edu_people p WHERE ' . implode(' AND ', $where) . ' ORDER BY p.name, p.id LIMIT ' . $limit . ' OFFSET ' . $offset;
    $rows = fabo_all($sql, $types, $params);
    $total = (int) (fabo_one('SELECT FOUND_ROWS() AS n')['n'] ?? count($rows));
    if ($rows) {
        $ids = implode(',', array_map(static fn($r) => (int) $r['id'], $rows));
        $roles = [];
        foreach (fabo_all('SELECT r.person_id, r.role, r.title, COALESCE(i.name, "") AS inst_name, COALESCE(d.name, "") AS dept_name, r.institution_id
                             FROM edu_roles r
                        LEFT JOIN edu_institutions i ON i.id = r.institution_id
                        LEFT JOIN edu_departments d ON d.id = r.department_id
                            WHERE r.org_id = ? AND r.person_id IN (' . $ids . ')', 'i', [$org]) as $r) {
            $roles[(int) $r['person_id']][] = $r;
        }
        foreach ($rows as &$r) {
            $r['roles'] = $roles[(int) $r['id']] ?? [];
            $r['n'] = $total;
        }
        unset($r);
    }
    return $rows;
}

/** How many people hold each role, per institution: inst_id => role => n (inst 0 = organisation-wide mentors). */
function edu_counts(int $org): array
{
    $out = [];
    foreach (fabo_all('SELECT institution_id, role, COUNT(DISTINCT person_id) AS n FROM edu_roles WHERE org_id = ? GROUP BY institution_id, role', 'i', [$org]) as $r) {
        $out[(int) $r['institution_id']][$r['role']] = (int) $r['n'];
    }
    return $out;
}

/** Headline numbers for an organisation, or for some of its institutions. */
function edu_totals(int $org, ?array $instIds = null): array
{
    $in = '';
    $deptIn = '';
    if ($instIds !== null) {
        $ids = array_values(array_filter(array_map('intval', $instIds)));
        $in = $ids ? ' AND r.institution_id IN (' . implode(',', $ids) . ')' : ' AND 0';
        $deptIn = $ids ? ' AND institution_id IN (' . implode(',', $ids) . ')' : ' AND 0';
    }
    $out = ['student' => 0, 'faculty' => 0, 'mentor' => 0, 'inst_admin' => 0];
    foreach (fabo_all('SELECT r.role, COUNT(DISTINCT r.person_id) AS n FROM edu_roles r WHERE r.org_id = ?' . $in . ' GROUP BY r.role', 'i', [$org]) as $r) {
        $out[$r['role']] = (int) $r['n'];
    }
    $acc = fabo_one('SELECT COUNT(*) AS people, SUM(p.pass_hash IS NOT NULL) AS active, SUM(p.last_login_at >= ?) AS recent
                       FROM edu_people p WHERE p.org_id = ?' . ($instIds !== null
                         ? ' AND EXISTS (SELECT 1 FROM edu_roles r WHERE r.person_id = p.id' . $in . ')' : ''), 'si', [edu_now(-30 * 86400), $org]);
    $out['people'] = (int) ($acc['people'] ?? 0);
    $out['accounts'] = (int) ($acc['active'] ?? 0);
    $out['recent'] = (int) ($acc['recent'] ?? 0);
    $out['institutions'] = $instIds === null
        ? (int) (fabo_one('SELECT COUNT(*) AS n FROM edu_institutions WHERE org_id = ?', 'i', [$org])['n'] ?? 0)
        : count(array_filter($instIds));
    $out['departments'] = (int) (fabo_one('SELECT COUNT(*) AS n FROM edu_departments WHERE org_id = ?' . $deptIn, 'i', [$org])['n'] ?? 0);
    return $out;
}

/* =============================================================== mentors */

/** Links a mentor to a student, both in this organisation, the mentor holding the role. */
function edu_mentor_assign(int $org, int $mentorId, int $studentId): bool
{
    if ($mentorId === $studentId) {
        return false;
    }
    if (!fabo_one('SELECT id FROM edu_roles WHERE org_id = ? AND person_id = ? AND role = "mentor"', 'ii', [$org, $mentorId])
        || !fabo_one('SELECT id FROM edu_roles WHERE org_id = ? AND person_id = ? AND role = "student"', 'ii', [$org, $studentId])) {
        return false;
    }
    fabo_exec('INSERT IGNORE INTO edu_mentor_links (org_id, mentor_id, student_id) VALUES (?,?,?)', 'iii', [$org, $mentorId, $studentId]);
    return true;
}

function edu_mentor_unassign(int $org, int $mentorId, int $studentId): void
{
    fabo_exec('DELETE FROM edu_mentor_links WHERE org_id = ? AND mentor_id = ? AND student_id = ?', 'iii', [$org, $mentorId, $studentId]);
}

function edu_mentor_students(int $org, int $mentorId): array
{
    return fabo_all('SELECT p.* FROM edu_mentor_links l JOIN edu_people p ON p.id = l.student_id AND p.org_id = l.org_id
                      WHERE l.org_id = ? AND l.mentor_id = ? ORDER BY p.name', 'ii', [$org, $mentorId]);
}

function edu_student_mentors(int $org, int $studentId): array
{
    return fabo_all('SELECT p.*, (SELECT title FROM edu_roles r WHERE r.person_id = p.id AND r.role = "mentor" LIMIT 1) AS title
                       FROM edu_mentor_links l JOIN edu_people p ON p.id = l.mentor_id AND p.org_id = l.org_id
                      WHERE l.org_id = ? AND l.student_id = ? ORDER BY p.name', 'ii', [$org, $studentId]);
}

/** A student by email or roll number, for assigning by typing. */
function edu_find_student(int $org, string $what): ?array
{
    $what = trim($what);
    if ($what === '') {
        return null;
    }
    return fabo_one('SELECT p.* FROM edu_people p WHERE p.org_id = ? AND (p.email = ? OR (p.roll_no <> "" AND p.roll_no = ?))
                       AND EXISTS (SELECT 1 FROM edu_roles r WHERE r.person_id = p.id AND r.role = "student") LIMIT 1',
                    'iss', [$org, mb_strtolower($what), $what]);
}

/* ============================================================== accounts */

/**
 * A fresh sign-in link for one person: replaces any earlier one, lasts
 * EDU_INVITE_DAYS. Only its hash is kept. Returns the full address.
 */
function edu_invite_make(int $org, int $pid): string
{
    $p = edu_person($org, $pid);
    if (!$p || (string) $p['email'] === '') {
        throw new InvalidArgumentException('Add an email address first: it is what they sign in with.');
    }
    $token = bin2hex(random_bytes(24));
    fabo_exec('UPDATE edu_people SET invite_hash = ?, invite_expires = ? WHERE org_id = ? AND id = ?',
              'ssii', [hash('sha256', $token), date('Y-m-d H:i:s', time() + EDU_INVITE_DAYS * 86400), $org, $pid]);
    return edu_portal_url($org, ['invite' => $token]);
}

/** The person a sign-in link belongs to, while it is still good. */
function edu_invite_person(string $token): ?array
{
    if (!preg_match('/^[0-9a-f]{48}$/', $token)) {
        return null;
    }
    edu_install();
    return fabo_one('SELECT * FROM edu_people WHERE invite_hash = ? AND invite_expires > ? AND status = "active"', 'ss', [hash('sha256', $token), edu_now()]);
}

/** Why a password is not good enough, or null (the rules are in privacy.php). */
function edu_password_ok(string $plain, ?array $person = null): ?string
{
    require_once __DIR__ . '/privacy.php';
    return edu_password_problem($plain, $person);
}

/** Sets a password, ends any sign-in link. $temporary: they must change it at next sign-in. */
function edu_password_set(int $org, int $pid, string $plain, bool $temporary = false): void
{
    $p = edu_person($org, $pid);
    // A temporary password is made by the portal itself: only its length is checked.
    if ($why = $temporary ? (mb_strlen($plain) < 10 ? 'Use at least 10 characters.' : null) : edu_password_ok($plain, $p)) {
        throw new InvalidArgumentException($why);
    }
    fabo_exec('UPDATE edu_people SET pass_hash = ?, pass_set_at = ?, must_change = ?, invite_hash = NULL, invite_expires = NULL
                WHERE org_id = ? AND id = ?', 'ssiii', [password_hash($plain, PASSWORD_DEFAULT), edu_now(), $temporary ? 1 : 0, $org, $pid]);
    require_once __DIR__ . '/privacy.php';
    edu_seclog($org, $pid, 'password', $temporary ? 'temporary, set by staff' : '');
    // Someone who already had a password is told it changed, in case it was not them.
    if (!$temporary && $p && $p['pass_hash'] !== null && (string) $p['email'] !== '') {
        require_once __DIR__ . '/mentoring.php';
        edu_send($org, (string) $p['email'], 'Your ' . edu_org_name($org) . ' Campus password was changed',
                 'Hello ' . $p['name'] . ",

The password for your Campus account was changed on " . date('j M Y \a	 g:i a') . ".

If this was not you, use \"Forgot your password\" on the sign-in page at once and tell your institution.

" . edu_org_name($org));
    }
}

/** The person whose email and password these are, in this organisation, or null. Same time either way. */
function edu_check_login(int $org, string $email, string $plain): ?array
{
    $p = edu_person_by_email($org, $email);
    $hash = (string) ($p['pass_hash'] ?? '');
    $ok = password_verify($plain, $hash !== '' ? $hash : '$2y$10$abcdefghijklmnopqrstuuK8bS2wW3Yc7FzHnV7l7n0aGq6zjTqS');
    if (!$p || $hash === '' || !$ok || $p['status'] !== 'active') {
        return null;
    }
    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        fabo_exec('UPDATE edu_people SET pass_hash = ? WHERE id = ?', 'si', [password_hash($plain, PASSWORD_DEFAULT), (int) $p['id']]);
    }
    return $p;
}

/** Changes whenever the password does, so a password change signs out every other session. */
function edu_fingerprint(array $p): string
{
    return hash('sha256', 'edu|' . (int) $p['id'] . '|' . (string) ($p['pass_hash'] ?? ''));
}

/** Emails a person their sign-in link. Returns [sent?, words]. */
function edu_invite_send(int $org, int $pid, bool $reset = false): array
{
    $p = edu_person($org, $pid);
    if (!$p) {
        return [false, 'That person is not in this organisation.'];
    }
    if (str_ends_with((string) $p['email'], '.example')) {
        return [false, 'A sample address: nothing is sent. Use "Set a temporary password" to try the portal as them.'];
    }
    $url = edu_invite_make($org, $pid);
    $orgName = edu_org_name($org);
    $subject = $reset ? 'Reset your ' . $orgName . ' Campus password' : 'Your ' . $orgName . ' Campus account';
    $lead = $reset ? 'Somebody asked to reset the password for your ' . $orgName . ' Campus account.'
                   : 'An account has been made for you on the ' . $orgName . ' Campus portal.';
    $text = 'Hello ' . $p['name'] . ",\n\n" . $lead . "\n\nSet your password here (the link works for " . EDU_INVITE_DAYS . " days):\n" . $url
          . "\n\nYou sign in with this email address: " . $p['email'] . "\n\n" . ($reset ? "If it was not you, ignore this message: your password stays as it is.\n\n" : '') . $orgName;
    $html = '<p>Hello ' . htmlspecialchars($p['name']) . ',</p><p>' . htmlspecialchars($lead) . '</p>'
          . '<p><a href="' . htmlspecialchars($url) . '" style="display:inline-block;padding:10px 18px;background:#1d5fd0;color:#fff;border-radius:8px;text-decoration:none;font-weight:600">Set your password</a></p>'
          . '<p style="color:#5f6979;font-size:13px">The link works for ' . EDU_INVITE_DAYS . ' days. You sign in with <strong>' . htmlspecialchars((string) $p['email']) . '</strong>.</p>'
          . ($reset ? '<p style="color:#5f6979;font-size:13px">If it was not you, ignore this message: your password stays as it is.</p>' : '')
          . '<p>' . htmlspecialchars($orgName) . '</p>';
    if (!empty($GLOBALS['edu_mail_hook']) && is_callable($GLOBALS['edu_mail_hook'])) {     // tests: nothing leaves the machine
        ($GLOBALS['edu_mail_hook'])((string) $p['email'], $subject, $text);
        return [true, 'Sent to ' . $p['email'] . '.'];
    }
    require_once __DIR__ . '/../outbox.php';
    [$sent, $why, $queued] = fabo_post_or_queue((string) $p['email'], $subject, $text, $html, null, 'edu invite ' . $pid, edu_org_actor($org));
    if ($sent) {
        return [true, 'Sent to ' . $p['email'] . '.'];
    }
    return [(bool) $queued, $queued ? 'The mail server is busy; it goes to ' . $p['email'] . ' shortly.' : 'Could not send: ' . $why];
}

/* ================================================================ import */

/** The columns a CSV may carry, in the template's order. */
function edu_import_columns(): array
{
    return ['name', 'email', 'phone', 'role', 'institution', 'department', 'roll_no', 'programme', 'year', 'apaar_id', 'title'];
}

/** Header words people actually type, mapped to our columns. */
function edu_import_header(string $h): string
{
    $h = strtolower(trim(preg_replace('/[^a-z0-9]+/i', ' ', $h)));
    $map = ['full name' => 'name', 'student name' => 'name', 'name' => 'name', 'email' => 'email', 'email id' => 'email', 'e mail' => 'email',
            'email address' => 'email', 'mobile' => 'phone', 'phone' => 'phone', 'mobile number' => 'phone', 'phone number' => 'phone',
            'role' => 'role', 'type' => 'role', 'institution' => 'institution', 'college' => 'institution', 'institute' => 'institution',
            'department' => 'department', 'branch' => 'department', 'dept' => 'department', 'roll no' => 'roll_no', 'roll number' => 'roll_no',
            'roll' => 'roll_no', 'hall ticket' => 'roll_no', 'registration number' => 'roll_no', 'reg no' => 'roll_no', 'student id' => 'roll_no',
            'programme' => 'programme', 'program' => 'programme', 'course' => 'programme', 'year' => 'year', 'year of study' => 'year',
            'apaar id' => 'apaar_id', 'apaar' => 'apaar_id', 'abc id' => 'apaar_id', 'title' => 'title', 'designation' => 'title', 'expertise' => 'title'];
    return $map[$h] ?? '';
}

function edu_import_role(string $v, string $fallback): string
{
    $v = strtolower(trim($v));
    if ($v === '') {
        return $fallback;
    }
    foreach (['student' => 'student', 'faculty' => 'faculty', 'teacher' => 'faculty', 'professor' => 'faculty', 'lecturer' => 'faculty',
              'mentor' => 'mentor', 'council' => 'council', 'government' => 'council', 'observer' => 'council', 'institution admin' => 'inst_admin', 'admin' => 'inst_admin', 'principal' => 'inst_admin', 'inst_admin' => 'inst_admin'] as $w => $r) {
        if (str_contains($v, $w)) {
            return $r;
        }
    }
    return '';
}

/**
 * Reads a CSV of people and files them. A row whose email is already here
 * updates that person and adds the role; a row with no email is added as a
 * record that cannot sign in (matched on roll number within the organisation
 * when it has one). $opts: role (when the file has no Role column),
 * institution (id, when it has no Institution column), create (make missing
 * institutions and departments).
 * Returns ['added' => n, 'updated' => n, 'skipped' => [[line, why]...]].
 */
function edu_import_csv(int $org, string $path, array $opts = []): array
{
    $out = ['added' => 0, 'updated' => 0, 'skipped' => []];
    $fh = @fopen($path, 'rb');
    if (!$fh) {
        throw new InvalidArgumentException('Could not read the file.');
    }
    $first = (string) fgets($fh);
    $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);       // Excel's byte-order mark
    $delim = substr_count($first, ';') > substr_count($first, ',') ? ';' : (substr_count($first, "\t") > substr_count($first, ',') ? "\t" : ',');
    $head = array_map('edu_import_header', str_getcsv($first, $delim, '"', ''));
    if (!in_array('name', $head, true)) {
        fclose($fh);
        throw new InvalidArgumentException('The first row must name the columns, with at least a Name column. Download the template to see them.');
    }
    $fallbackRole = isset(edu_roles_all()[$opts['role'] ?? '']) ? $opts['role'] : 'student';
    $fallbackInst = (int) ($opts['institution'] ?? 0);
    if ($fallbackInst && !edu_institution($org, $fallbackInst)) {
        $fallbackInst = 0;
    }
    $make = !empty($opts['create']);
    $insts = [];
    foreach (edu_institutions($org) as $i) {
        $insts[mb_strtolower($i['name'])] = (int) $i['id'];
        if ($i['code'] !== '') {
            $insts[mb_strtolower($i['code'])] = (int) $i['id'];
        }
    }
    $line = 1;
    $rows = 0;
    while (($cells = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
        $line++;
        if ($cells === [null] || implode('', array_map('trim', array_map('strval', $cells))) === '') {
            continue;
        }
        if (++$rows > 20000) {
            $out['skipped'][] = [$line, 'Stopped: one file takes up to 20,000 rows. Split the rest into another file.'];
            break;
        }
        $r = [];
        foreach ($head as $i => $col) {
            if ($col !== '' && !isset($r[$col])) {
                $r[$col] = trim((string) ($cells[$i] ?? ''));
            }
        }
        try {
            $role = edu_import_role($r['role'] ?? '', $fallbackRole);
            if ($role === '') {
                throw new InvalidArgumentException('Role "' . $r['role'] . '" is not one of Student, Faculty, Mentor, Institution admin.');
            }
            $instName = (string) ($r['institution'] ?? '');
            $instId = $fallbackInst;
            if ($instName !== '') {
                $instId = $insts[mb_strtolower($instName)] ?? 0;
                if (!$instId) {
                    if (!$make) {
                        throw new InvalidArgumentException('No institution called "' . $instName . '". Add it, or tick "Create missing institutions".');
                    }
                    $instId = edu_institution_save($org, ['name' => $instName]);
                    $insts[mb_strtolower($instName)] = $instId;
                }
            }
            if (!$instId && !in_array($role, ['mentor', 'council'], true)) {
                throw new InvalidArgumentException('No institution: fill the Institution column or pick one for the whole file.');
            }
            $deptId = 0;
            if ($instId && ($r['department'] ?? '') !== '') {
                $deptId = edu_department_find($org, $instId, $r['department'], $make);
                if ($deptId < 0) {
                    throw new InvalidArgumentException('No department "' . $r['department'] . '" in that institution. Add it, or tick "Create missing".');
                }
            }
            $email = mb_strtolower((string) ($r['email'] ?? ''));
            $have = $email !== '' ? edu_person_by_email($org, $email) : null;
            if (!$have && $email === '' && ($r['roll_no'] ?? '') !== '') {
                $have = fabo_one('SELECT * FROM edu_people WHERE org_id = ? AND roll_no = ? AND email IS NULL LIMIT 1', 'is', [$org, $r['roll_no']]);
            }
            $data = ['name' => $r['name'] ?? '', 'email' => $email, 'phone' => $r['phone'] ?? '', 'roll_no' => $r['roll_no'] ?? '',
                     'apaar_id' => $r['apaar_id'] ?? '', 'programme' => $r['programme'] ?? '', 'year_of_study' => $r['year'] ?? ''];
            if ($have) {
                // Only what the file says: an empty cell keeps what is already on the record.
                foreach ($data as $k => $v) {
                    if ($v === '' || $v === null) {
                        $data[$k] = $have[$k] ?? '';
                    }
                }
                $pid = edu_person_save($org, $data, (int) $have['id']);
                $out['updated']++;
            } else {
                $pid = edu_person_save($org, $data);
                $out['added']++;
            }
            edu_role_add($org, $pid, $role, $instId, $deptId, (string) ($r['title'] ?? ''));
        } catch (Throwable $e) {
            $out['skipped'][] = [$line, $e instanceof InvalidArgumentException ? $e->getMessage() : 'Could not save this row.'];
            if (!$e instanceof InvalidArgumentException) {
                error_log('FABO edu import: ' . $e->getMessage());
            }
        }
    }
    fclose($fh);
    return $out;
}

/* ========================================================== sample data */

/**
 * A small, believable organisation to show: three institutions, their
 * departments, faculty, institution admins, mentors and students, with
 * mentors linked to students. Only into an organisation with no
 * institutions yet. Emails end in .example (reserved, never delivered).
 */
function edu_sample_load(int $org): array
{
    if (edu_institutions($org)) {
        throw new InvalidArgumentException('Sample data goes only into an empty Academic: this one already has institutions.');
    }
    $insts = [
        ['Government Engineering College, Guntur', 'College', 'GEC-GNT', 'Guntur', 'Guntur', ['Computer Science', 'Electronics & Communication', 'Mechanical']],
        ['Sri Venkateswara Polytechnic, Tirupati', 'Polytechnic', 'SVP-TPT', 'Tirupati', 'Tirupati', ['Civil', 'Electrical', 'Computer Engineering']],
        ['Andhra Arts & Science College, Visakhapatnam', 'College', 'AASC-VSP', 'Visakhapatnam', 'Visakhapatnam', ['Commerce', 'Biotechnology', 'Data Science']],
    ];
    $first = ['Aarav', 'Ananya', 'Bhavya', 'Charan', 'Deepika', 'Eshwar', 'Gayathri', 'Harsha', 'Ishita', 'Jaswanth', 'Keerthi', 'Lokesh',
              'Meghana', 'Nikhil', 'Pavani', 'Rahul', 'Sahithi', 'Tarun', 'Varsha', 'Yashwanth', 'Divya', 'Karthik', 'Sravani', 'Vamsi'];
    $last = ['Reddy', 'Naidu', 'Rao', 'Varma', 'Chowdary', 'Sharma', 'Kumar', 'Prasad', 'Devi', 'Murthy'];
    $n = 0;
    $students = [];
    $people = 0;
    foreach ($insts as $k => [$name, $kind, $code, $district, $city, $depts]) {
        $iid = edu_institution_save($org, ['name' => $name, 'kind' => $kind, 'code' => $code, 'district' => $district, 'city' => $city,
                                           'contact_name' => 'Principal\'s Office', 'contact_email' => 'office@' . strtolower($code) . '.example']);
        $admin = edu_person_save($org, ['name' => 'Dr. ' . $first[($k * 5) % 24] . ' ' . $last[$k], 'email' => 'principal@' . strtolower($code) . '.example']);
        edu_role_add($org, $admin, 'inst_admin', $iid, 0, 'Principal');
        $people++;
        foreach ($depts as $j => $dn) {
            $did = edu_department_save($org, $iid, $dn);
            $fac = edu_person_save($org, ['name' => 'Prof. ' . $first[($k * 7 + $j * 3 + 2) % 24] . ' ' . $last[($k + $j + 3) % 10],
                                          'email' => 'faculty' . ($j + 1) . '@' . strtolower($code) . '.example']);
            edu_role_add($org, $fac, 'faculty', $iid, $did, $j === 0 ? 'Head of Department' : 'Assistant Professor');
            $people++;
            for ($s = 0; $s < 4; $s++) {
                $n++;
                $nm = $first[($n * 7) % 24] . ' ' . $last[($n * 3 + $k) % 10];
                $sid = edu_person_save($org, ['name' => $nm, 'email' => 'student' . $n . '@' . strtolower($code) . '.example',
                                              'roll_no' => $code . '-' . (2026 - ($s % 4)) . '-' . str_pad((string) $n, 3, '0', STR_PAD_LEFT),
                                              'programme' => ($kind === 'Polytechnic' ? 'Diploma in ' : ($k === 2 ? 'B.Sc / B.Com · ' : 'B.Tech ')) . $dn,
                                              'year_of_study' => ($s % 4) + 1]);
                edu_role_add($org, $sid, 'student', $iid, $did);
                $students[] = $sid;
                $people++;
            }
        }
    }
    $mentors = [['Ravi Teja Kommineni', 'Founder mentor · SaaS & go-to-market'], ['Lakshmi Priya Ganta', 'Product design & UX'],
                ['Suresh Babu Pinnamaneni', 'Agritech & rural innovation'], ['Farah Siddiqui', 'Funding, grants & DPIIT recognition']];
    foreach ($mentors as $m => [$nm, $title]) {
        $mid = edu_person_save($org, ['name' => $nm, 'email' => 'mentor' . ($m + 1) . '@mentors.example']);
        edu_role_add($org, $mid, 'mentor', 0, 0, $title);
        $people++;
        foreach ($students as $i => $sid) {
            if ($i % count($mentors) === $m) {
                edu_mentor_assign($org, $mid, $sid);
            }
        }
    }
    // A published course, and sessions the mentors offer over the next fortnight.
    require_once __DIR__ . '/lms.php';
    require_once __DIR__ . '/mentoring.php';
    edu_lms_install();
    if (!fabo_one('SELECT id FROM edu_courses WHERE org_id = ? AND title LIKE "Startup Foundations%"', 'i', [$org])) {
        edu_sample_course($org, 'Sample data');
    }
    foreach (fabo_all('SELECT person_id FROM edu_roles WHERE org_id = ? AND role = "mentor" ORDER BY person_id', 'i', [$org]) as $k => $m) {
        try {
            edu_slot_add($org, (int) $m['person_id'], date('Y-m-d', strtotime('+' . (2 + $k) . ' days')) . ' ' . ['10:00', '11:30', '15:00', '17:00'][$k % 4],
                         30, $k % 2 ? 'in_person' : 'online', $k % 2 ? 'Incubation hub, meeting room 2' : 'https://meet.google.com/', 2);
        } catch (InvalidArgumentException $e) {
            // a clash with a time they already offer: fine
        }
    }
    edu_set($org, 'sample_loaded', date('Y-m-d H:i:s'));
    return ['institutions' => count($insts), 'people' => $people, 'students' => count($students)];
}

/** Removes everything the sample made, and only that: people on .example addresses and the three sample institutions. */
function edu_sample_remove(int $org): int
{
    $n = 0;
    foreach (fabo_all('SELECT id FROM edu_people WHERE org_id = ? AND email LIKE "%.example"', 'i', [$org]) as $p) {
        edu_person_delete($org, (int) $p['id']);
        $n++;
    }
    foreach (['GEC-GNT', 'SVP-TPT', 'AASC-VSP'] as $code) {
        $i = fabo_one('SELECT id FROM edu_institutions WHERE org_id = ? AND code = ?', 'is', [$org, $code]);
        if ($i && !fabo_one('SELECT id FROM edu_roles WHERE institution_id = ? LIMIT 1', 'i', [(int) $i['id']])) {
            edu_institution_delete($org, (int) $i['id']);
        }
    }
    // The sample course, unless real students have taken it.
    require_once __DIR__ . '/lms.php';
    foreach (fabo_all('SELECT id FROM edu_courses WHERE org_id = ? AND title LIKE "Startup Foundations%" AND created_by = "Sample data"', 'i', [$org]) as $c) {
        if (!fabo_one('SELECT id FROM edu_enrolments WHERE course_id = ? LIMIT 1', 'i', [(int) $c['id']])) {
            edu_course_delete($org, (int) $c['id']);
        }
    }
    fabo_exec('DELETE FROM edu_settings WHERE org_id = ? AND k = "sample_loaded"', 'i', [$org]);
    return $n;
}

<?php
/**
 * Digital Signatures — the engine underneath every page.
 *
 * WHOSE. Like Finance and Co-working, every organisation's documents are its
 * own: ours (org 0) for the administrator and our staff, and a client's for
 * a client organisation granted "Digital Signatures" on Clients (its head
 * and their team). Rows carry org_id and every query names it.
 *
 * WHO. Everyone in an organisation that holds the module may sign what is
 * sent to them, follow their own documents and verify a PDF. Beyond that,
 * roles (Super Administrator … Auditor) are sets of permissions, editable as
 * a matrix, and a Department Administrator's roles are scoped to the
 * departments named on the grant. Every page and every action asks
 * ds_can() on the server; hiding a button is never the control.
 *
 * WHAT IS KEPT. Files are encrypted at rest (AES-256-GCM, a key file outside
 * the web root). The audit trail is a hash chain sealed with a key the
 * database does not hold, so a row changed or removed is detected.
 */

require_once __DIR__ . '/../inc/org.php';
require_once __DIR__ . '/schema.php';

/* ================================================================ context */

function ds_org(): int
{
    return (int) ($GLOBALS['ds_org'] ?? 0);
}

function ds_use(int $org): void
{
    $GLOBALS['ds_org'] = max(0, $org);
    $GLOBALS['ds_settings_cache'] = null;
    if (function_exists('fabo_link_org')) {
        fabo_link_org(max(0, $org));
    }
}

function ds_viewer(): array
{
    return $GLOBALS['ds_viewer'] ?? ['is_admin' => true, 'client' => null];
}

/** The person acting: their clients.id, or 0 for the administrator (and the system). */
function ds_me(): int
{
    $v = ds_viewer();
    return !empty($v['is_admin']) ? 0 : (int) ($v['client']['id'] ?? 0);
}

function ds_actor(): string
{
    if (!empty($GLOBALS['ds_system'])) {
        return 'System';
    }
    $v = ds_viewer();
    return !empty($v['is_admin']) ? 'Administrator' : (string) ($v['client']['name'] ?? 'Staff');
}

/** Which organisation's signatures a viewer works in, or -1 (committee members and outsiders have none). */
function ds_org_of(array $viewer): int
{
    $c = $viewer['client'] ?? null;
    if (empty($viewer['is_admin']) && $c && !empty($c['is_committee'])) {
        return -1;
    }
    return inc_org_of($viewer);
}

/** The module grant an organisation holds: ours always 'edit'; a client's as granted on Clients. */
function ds_org_grant(int $org): string
{
    if ($org === 0) {
        return 'edit';
    }
    if ($org < 0) {
        return 'none';
    }
    // Any client, a client's own client too, capped by every client above it.
    return fabo_head_module_permission($org, 'esign');
}

/** Organisations with the module, for the administrator's switcher: id => name. */
function ds_orgs_available(): array
{
    $out = [0 => defined('APP_NAME') ? APP_NAME : 'Our organisation'];
    return $out + fabo_module_orgs('esign');
}

function ds_org_name(?int $org = null): string
{
    $org = $org ?? ds_org();
    if ($org === 0) {
        return defined('APP_NAME') ? APP_NAME : 'Our organisation';
    }
    return (string) (fabo_one('SELECT COALESCE(NULLIF(organisation, ""), name) n FROM clients WHERE id = ?', 'i', [$org])['n'] ?? ('#' . $org));
}

/** May anything be changed in this organisation? (Owner grant.) */
function ds_writable(): bool
{
    return ($GLOBALS['ds_grant'] ?? 'edit') === 'edit';
}

/** Runs $fn in one transaction; nested calls join the outer one. */
function ds_tx(callable $fn)
{
    if (!empty($GLOBALS['ds_in_tx'])) {
        return $fn();
    }
    $db = fabo_db();
    $GLOBALS['ds_in_tx'] = true;
    $db->begin_transaction();
    try {
        $out = $fn();
        $db->commit();
        return $out;
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    } finally {
        $GLOBALS['ds_in_tx'] = false;
    }
}

/* ================================================================== roles */

function ds_roles(): array
{
    return [
        'super_admin' => ['Super Administrator', 'Everything in this organisation, including roles, providers and the trust store.'],
        'org_admin'   => ['Organisation Administrator', 'Runs the module for the organisation: every document, workflow, signatory and setting.'],
        'dept_admin'  => ['Department Administrator', 'Runs documents, signatories and reports for the departments named on the grant.'],
        'creator'     => ['Document Creator', 'Uploads documents and sends them for signature.'],
        'reviewer'    => ['Document Reviewer', 'Reviews documents routed to them before they are signed.'],
        'signatory'   => ['Authorized Signatory', 'Signs documents with an Aadhaar eSign or a DSC.'],
        'viewer'      => ['Document Viewer', 'Reads the documents of their departments.'],
        'auditor'     => ['Auditor', 'Reads every document, the audit trail and reports. Changes nothing.'],
    ];
}

/** Every permission: key => [group, label]. */
function ds_permissions(): array
{
    return [
        'dashboard.view'      => ['Overview', 'See the signatures dashboard and analytics'],
        'docs.create'         => ['Documents', 'Upload documents and send them for signature'],
        'docs.view_dept'      => ['Documents', 'Read documents of their departments'],
        'docs.view_all'       => ['Documents', 'Read every document in the organisation'],
        'docs.manage'         => ['Documents', 'Edit, cancel and reinitiate documents in scope'],
        'docs.delete'         => ['Documents', 'Delete draft and closed documents (soft delete)'],
        'workflows.manage'    => ['Configuration', 'Create and change signature workflows'],
        'signatories.manage'  => ['Configuration', 'Maintain the signatory directory and certificates'],
        'categories.manage'   => ['Configuration', 'Maintain document categories and retention'],
        'providers.manage'    => ['Configuration', 'Configure eSign / DSC providers and the trust store'],
        'delegations.manage'  => ['Configuration', 'Set up signing delegations'],
        'monitor.manage'      => ['Operations', 'Monitor pending signatures, remind and escalate'],
        'reports.view'        => ['Reports', 'Run reports'],
        'reports.export'      => ['Reports', 'Export reports (Excel / PDF)'],
        'audit.view'          => ['Audit', 'Read the audit trail and check its integrity'],
        'verify.use'          => ['Verification', 'Verify signed PDFs'],
        'roles.manage'        => ['Administration', 'Assign roles and edit this permission matrix'],
        'settings.manage'     => ['Administration', 'Change module settings and API access'],
    ];
}

function ds_default_matrix(): array
{
    $all = array_keys(ds_permissions());
    return [
        'super_admin' => $all,
        'org_admin'   => array_values(array_diff($all, ['roles.manage'])),
        'dept_admin'  => ['dashboard.view', 'docs.create', 'docs.view_dept', 'docs.manage', 'signatories.manage', 'delegations.manage',
                          'monitor.manage', 'reports.view', 'reports.export', 'audit.view', 'verify.use'],
        'creator'     => ['dashboard.view', 'docs.create', 'verify.use'],
        'reviewer'    => ['dashboard.view', 'verify.use'],
        'signatory'   => ['dashboard.view', 'verify.use'],
        'viewer'      => ['dashboard.view', 'docs.view_dept', 'verify.use'],
        'auditor'     => ['dashboard.view', 'docs.view_all', 'reports.view', 'reports.export', 'audit.view', 'verify.use'],
    ];
}

function ds_matrix(): array
{
    $m = json_decode((string) ds_setting('perm_matrix'), true);
    $def = ds_default_matrix();
    if (!is_array($m)) {
        return $def;
    }
    $valid = array_keys(ds_permissions());
    foreach ($def as $role => $perms) {
        if ($role !== 'super_admin' && isset($m[$role]) && is_array($m[$role])) {
            $def[$role] = array_values(array_intersect($m[$role], $valid));
        }
    }
    return $def;
}

/** A person's roles here: role => [department ids] (empty list = not scoped). */
function ds_roles_of(int $personId): array
{
    static $memo = [];
    $k = ds_org() . ':' . $personId;
    if (isset($memo[$k])) {
        return $memo[$k];
    }
    $out = [];
    if ($personId > 0) {
        foreach (fabo_all('SELECT role, department_id FROM ds_role_members WHERE org_id = ? AND person_id = ?', 'ii', [ds_org(), $personId]) as $r) {
            $out[$r['role']] = $out[$r['role']] ?? [];
            if ((int) $r['department_id'] > 0) {
                $out[$r['role']][] = (int) $r['department_id'];
            }
        }
    }
    return $memo[$k] = $out;
}

/**
 * The super administrator: the portal administrator, the head of a client
 * organisation that holds the module as Owner, a team member the head made
 * Owner of it on My Team, or anyone given the Super Administrator role.
 */
function ds_is_super(): bool
{
    if (!empty($GLOBALS['ds_system'])) {
        return true;
    }
    $v = ds_viewer();
    if (!empty($v['is_admin'])) {
        return true;
    }
    if (!ds_writable()) {
        return false;
    }
    $me = ds_me();
    if ($me > 0 && $me === ds_org()) {
        return true;
    }
    if (ds_member_grant() === 'edit') {
        return true;
    }
    return isset(ds_roles_of($me)['super_admin']);
}

/** The module grant a team member holds personally (My Team / Team Management). */
function ds_member_grant(): string
{
    $v = ds_viewer();
    if (!empty($v['is_admin']) || empty($v['client']['id']) || (int) $v['client']['id'] === ds_org()) {
        return 'none';
    }
    $p = (string) ($v['access']['esign'] ?? 'none');
    if (!in_array($p, ['view', 'edit'], true)) {
        return 'none';
    }
    return $p === 'edit' && ds_writable() ? 'edit' : 'view';
}

/** Does the viewer hold $perm (anywhere in scope)? */
function ds_can(string $perm): bool
{
    $readOnly = in_array($perm, ['dashboard.view', 'docs.view_dept', 'docs.view_all', 'reports.view', 'reports.export', 'audit.view', 'verify.use'], true);
    if (!$readOnly && !ds_writable()) {
        return false;
    }
    if (ds_is_super()) {
        return true;
    }
    if ($readOnly && (ds_member_grant() === 'view' || (!ds_writable() && ds_me() === ds_org()))) {
        return true;                                   // View-only grants read everything
    }
    if ($perm === 'verify.use' || $perm === 'dashboard.view') {
        return true;                                   // everyone signs and verifies
    }
    if ($perm === 'docs.create' && ds_setting('members_create') === '1') {
        return true;
    }
    $m = ds_matrix();
    foreach (array_keys(ds_roles_of(ds_me())) as $r) {
        if (in_array($perm, $m[$r] ?? [], true)) {
            return true;
        }
    }
    return false;
}

function ds_can_any(array $perms): bool
{
    foreach ($perms as $p) {
        if (ds_can($p)) {
            return true;
        }
    }
    return false;
}

/** Stops with a 403 unless the viewer holds $perm. */
function ds_need(string $perm): void
{
    if (!ds_can($perm)) {
        error_log('FABO esign denied ' . ds_actor() . ' -> ' . $perm . ' (org ' . ds_org() . ')');
        fabo_deny('You do not have permission for this (' . (ds_permissions()[$perm][1] ?? $perm) . '). Ask your administrator.', 403);
    }
}

/**
 * The departments a scoped permission reaches for the viewer: null for
 * every department (an organisation-wide role), else a list of ids — the
 * departments named on their scoped roles plus their own department.
 */
function ds_scope_depts(string $perm): ?array
{
    if (ds_is_super() || ds_member_grant() !== 'none') {
        return null;
    }
    $m = ds_matrix();
    $depts = [];
    foreach (ds_roles_of(ds_me()) as $role => $scoped) {
        if (!in_array($perm, $m[$role] ?? [], true)) {
            continue;
        }
        if (!$scoped && $role !== 'dept_admin' && $role !== 'viewer') {
            return null;                               // organisation-wide
        }
        $depts = array_merge($depts, $scoped);
    }
    $own = ds_person_dept(ds_me());
    if ($own) {
        $depts[] = $own;
    }
    return array_values(array_unique($depts));
}

/* ================================================================= people */

/** The organisation's people: id => name (our staff for ours; the head and their team for a client). */
function ds_people(bool $rows = false): array
{
    static $memo = [];
    $org = ds_org();
    if (!isset($memo[$org])) {
        $memo[$org] = $org > 0
            ? fabo_all('SELECT id, name, email, kind, parent_id FROM clients WHERE (id = ? OR (parent_id = ? AND kind = "team")) AND revoked_at IS NULL AND COALESCE(is_committee,0) = 0 ORDER BY name', 'ii', [$org, $org])
            : fabo_all('SELECT id, name, email, kind, parent_id FROM clients WHERE kind = "team" AND parent_id IS NULL AND revoked_at IS NULL AND COALESCE(is_committee,0) = 0 ORDER BY name');
    }
    $out = [];
    foreach ($memo[$org] as $r) {
        $out[(int) $r['id']] = $rows ? $r : (string) $r['name'];
    }
    return $out;
}

function ds_is_member(int $personId): bool
{
    return $personId === 0 ? true : isset(ds_people()[$personId]);
}

function ds_person_name(?int $id): string
{
    if ($id === null) {
        return '—';
    }
    if ($id === 0) {
        return 'Administrator';
    }
    static $memo = [];
    return $memo[$id] ??= (string) (fabo_one('SELECT name FROM clients WHERE id = ?', 'i', [$id])['name'] ?? ('#' . $id));
}

function ds_person_email(int $id): string
{
    return $id > 0 ? (string) (fabo_one('SELECT email FROM clients WHERE id = ?', 'i', [$id])['email'] ?? '') : (defined('ADMIN_EMAIL') ? (string) ADMIN_EMAIL : '');
}

/** A person's signatory profile here, or null. */
function ds_profile(int $personId): ?array
{
    static $memo = [];
    $k = ds_org() . ':' . $personId;
    if (!array_key_exists($k, $memo)) {
        $memo[$k] = $personId > 0 ? fabo_one('SELECT * FROM ds_signatories WHERE org_id = ? AND person_id = ?', 'ii', [ds_org(), $personId]) : null;
    }
    return $memo[$k];
}

/** HR's record of a person, when HR is in use: department, designation, code, mobile, manager. */
function ds_hr_of(int $personId): ?array
{
    if ($personId <= 0) {
        return null;
    }
    static $memo = [];
    $k = ds_org() . ':' . $personId;
    if (!array_key_exists($k, $memo)) {
        try {
            $memo[$k] = fabo_one('SELECT e.department_id, e.emp_code AS employee_code, e.phone AS mobile, d.name AS designation, m.person_id AS manager_person_id
                                    FROM hr_employees e LEFT JOIN hr_designations d ON d.id = e.designation_id LEFT JOIN hr_employees m ON m.id = e.manager_id
                                   WHERE e.org_id = ? AND e.person_id = ? LIMIT 1', 'ii', [ds_org(), $personId]);
        } catch (Throwable $e) {
            $memo[$k] = null;
        }
    }
    return $memo[$k];
}

function ds_person_dept(int $personId): ?int
{
    $p = ds_profile($personId);
    if ($p && $p['department_id']) {
        return (int) $p['department_id'];
    }
    $h = ds_hr_of($personId);
    return $h && $h['department_id'] ? (int) $h['department_id'] : null;
}

function ds_designation(int $personId): string
{
    if ($personId === 0 && !empty($GLOBALS['ds_guest'])) {
        $g = $GLOBALS['ds_guest'];
        return trim(($g['designation'] ?? '') . (!empty($g['organisation']) ? ', ' . $g['organisation'] : '')) ?: 'External signatory';
    }
    if ($personId === 0) {
        return 'Administrator';
    }
    $p = ds_profile($personId);
    if ($p && trim((string) $p['designation']) !== '') {
        return (string) $p['designation'];
    }
    $h = ds_hr_of($personId);
    if ($h && trim((string) $h['designation']) !== '') {
        return (string) $h['designation'];
    }
    return $personId === ds_org() && ds_org() > 0 ? 'Head of organisation' : '';
}

/** A person's manager, from the signatory directory's reporting line or HR. */
function ds_manager_of(int $personId): ?int
{
    $h = ds_hr_of($personId);
    $m = $h ? (int) ($h['manager_person_id'] ?? 0) : 0;
    return $m > 0 && ds_is_member($m) ? $m : null;
}

/* ================================================== departments and units */

/** The organisation's departments (HR's master): id => name. */
function ds_departments(bool $activeOnly = true): array
{
    static $memo = [];
    $k = ds_org() . ($activeOnly ? 'a' : '');
    if (!isset($memo[$k])) {
        $memo[$k] = [];
        try {
            foreach (fabo_all('SELECT id, name FROM hr_departments WHERE org_id = ?' . ($activeOnly ? ' AND active = 1' : '') . ' ORDER BY name', 'i', [ds_org()]) as $r) {
                $memo[$k][(int) $r['id']] = (string) $r['name'];
            }
        } catch (Throwable $e) {
        }
    }
    return $memo[$k];
}

function ds_dept_name(?int $id): string
{
    return $id ? (ds_departments(false)[$id] ?? ('#' . $id)) : '—';
}

/** Adds a department to HR's master (the one list both modules use). */
function ds_department_add(string $name): int
{
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 120) {
        throw new InvalidArgumentException('Give the department a name of up to 120 characters.');
    }
    if (!function_exists('hr_install')) {
        require_once __DIR__ . '/../hr/schema.php';
    }
    try {
        fabo_one('SELECT 1 FROM hr_departments LIMIT 1');
    } catch (Throwable $e) {
        hr_install();
    }
    $have = fabo_one('SELECT id FROM hr_departments WHERE org_id = ? AND name = ?', 'is', [ds_org(), $name]);
    if ($have) {
        fabo_exec('UPDATE hr_departments SET active = 1 WHERE id = ?', 'i', [(int) $have['id']]);
        return (int) $have['id'];
    }
    return fabo_exec('INSERT INTO hr_departments (org_id, name, created_by) VALUES (?,?,?)', 'iss', [ds_org(), $name, ds_actor()]);
}

/** Organisational units: the organisation's Hub / Spoke list (first is the hub). */
function ds_units(): array
{
    require_once __DIR__ . '/../lists.php';
    $vals = array_values(array_filter(array_map('trim', (array) fabo_list_values('Hub / Spoke', ds_org()))));
    if (!$vals && ds_org() > 0) {
        $vals = array_values(array_filter(array_map('trim', (array) fabo_list_values('Hub / Spoke', 0))));
    }
    return $vals;
}

/* =============================================================== settings */

function ds_setting_defaults(): array
{
    return [
        'ref_prefix'        => 'DS',
        'members_create'    => '1',
        'mail_notify'       => '1',
        'deadline_days'     => '7',
        'reminder_hours'    => '24',
        'deadline_warn_hours' => '24',
        'max_upload_mb'     => '25',
        'require_trusted'   => '1',
        'dsc_identity'      => 'registered_or_identity',
        'stamp_approvals'   => '1',
        'public_verify'     => '1',
        'expire_on_deadline'=> '0',
        'retention_years'   => '8',
        'subfilter'         => 'adbe.pkcs7.detached',
        'esign_name_match'  => 'warn',
        'clamav_cmd'        => '',
        'perm_matrix'       => '',
        'last_cron'         => '',
    ];
}

function ds_setting(string $k): string
{
    if (!is_array($GLOBALS['ds_settings_cache'] ?? null)) {
        $GLOBALS['ds_settings_cache'] = [];
        try {
            foreach (fabo_all('SELECT k, v FROM ds_settings WHERE org_id = ?', 'i', [ds_org()]) as $r) {
                $GLOBALS['ds_settings_cache'][$r['k']] = (string) $r['v'];
            }
        } catch (Throwable $e) {
        }
    }
    return $GLOBALS['ds_settings_cache'][$k] ?? (ds_setting_defaults()[$k] ?? '');
}

function ds_setting_put(string $k, string $v): void
{
    fabo_exec('INSERT INTO ds_settings (org_id, k, v) VALUES (?,?,?) ON DUPLICATE KEY UPDATE v = VALUES(v)', 'iss', [ds_org(), $k, $v]);
    $GLOBALS['ds_settings_cache'] = null;
}

/* ===================================================================== keys */

/**
 * The module's master key (32 bytes). From DS_STORAGE_KEY (base64) when set,
 * else a key file kept outside the web root and made on first use. Once
 * documents exist, a missing or different key is refused rather than
 * replaced: a new key would make every stored file unreadable.
 */
function ds_master_key(): string
{
    static $key = null;
    if ($key !== null) {
        return $key;
    }
    $env = defined('DS_STORAGE_KEY') ? (string) DS_STORAGE_KEY : (string) getenv('DS_STORAGE_KEY');
    if ($env !== '') {
        $k = base64_decode($env, true);
        if ($k === false || strlen($k) < 32) {
            throw new RuntimeException('DS_STORAGE_KEY must be 32 bytes, base64-encoded.');
        }
        $key = substr($k, 0, 32);
    } else {
        $file = ds_key_file();
        if (is_file($file)) {
            $k = base64_decode(trim((string) file_get_contents($file)), true);
            if ($k === false || strlen($k) < 32) {
                throw new RuntimeException('The signature storage key file is damaged: ' . $file);
            }
            $key = substr($k, 0, 32);
        } else {
            if (fabo_setting_get_raw('ds_key_fp') !== '') {
                throw new RuntimeException('The signature storage key file is missing (' . $file . '). Restore it from backup; stored documents cannot be read without it.');
            }
            $dir = dirname($file);
            if (!is_dir($dir)) {
                @mkdir($dir, 0700, true);
            }
            $key = random_bytes(32);
            if (@file_put_contents($file, base64_encode($key) . "\n", LOCK_EX) === false) {
                throw new RuntimeException('Cannot write the signature storage key to ' . $file . '.');
            }
            @chmod($file, 0600);
        }
    }
    $fp = substr(hash('sha256', 'ds-key-fp' . $key), 0, 16);
    $have = fabo_setting_get_raw('ds_key_fp');
    if ($have === '') {
        fabo_exec('INSERT INTO app_settings (k, v) VALUES ("ds_key_fp", ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', 's', [$fp]);
    } elseif (!hash_equals($have, $fp)) {
        $key = null;
        throw new RuntimeException('The signature storage key does not match the one documents were stored with. Restore the original key file.');
    }
    return $key;
}

/** Where the key file lives: DS_KEY_FILE, else beside the site (never under public_html) on live, else uploads/ds locally. */
function ds_key_file(): string
{
    $env = (string) getenv('DS_KEY_FILE');
    if ($env !== '') {
        return $env;
    }
    $root = dirname(__DIR__, 2);
    $norm = str_replace('\\', '/', $root);
    if (preg_match('~^(/home/[^/]+/domains/[^/]+)/public_html/~', $norm, $m)) {
        return $m[1] . '/.mcc-esign/storage.key';
    }
    return $root . '/uploads/ds/.storage.key';
}

function fabo_setting_get_raw(string $k): string
{
    return (string) (fabo_one('SELECT v FROM app_settings WHERE k = ?', 's', [$k])['v'] ?? '');
}

/** A purpose-bound sub-key (files, audit, seals, callbacks). */
function ds_subkey(string $purpose): string
{
    return hash_hmac('sha256', 'ds:' . $purpose, ds_master_key(), true);
}

/* ================================================================== files */

function ds_storage_dir(): string
{
    require_once __DIR__ . '/../uploads.php';
    $d = rtrim(fabo_upload_dir(), '/\\') . '/ds/o' . ds_org() . '/' . date('Y');
    if (!is_dir($d)) {
        @mkdir($d, 0750, true);
        @file_put_contents(dirname($d, 2) . '/.htaccess', "Require all denied\n");
    }
    return $d;
}

/** Stores bytes encrypted; returns the ds_files id. */
function ds_store(string $bytes, string $kind, string $name, ?int $docId, string $mime = 'application/pdf'): int
{
    $nonce = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($bytes, 'aes-256-gcm', ds_subkey('files'), OPENSSL_RAW_DATA, $nonce, $tag, 'ds1');
    if ($ct === false) {
        throw new RuntimeException('The document could not be encrypted for storage.');
    }
    $stored = bin2hex(random_bytes(16)) . '.bin';
    $dir = ds_storage_dir();
    $rel = 'o' . ds_org() . '/' . date('Y') . '/' . $stored;
    if (@file_put_contents($dir . '/' . $stored, "DSE1" . $nonce . $tag . $ct, LOCK_EX) === false) {
        throw new RuntimeException('The document could not be saved to storage.');
    }
    return fabo_exec('INSERT INTO ds_files (org_id, doc_id, kind, stored_name, original_name, mime, size, sha256, created_by) VALUES (?,?,?,?,?,?,?,?,?)',
                     'iissssiss', [ds_org(), $docId, $kind, $rel, mb_substr($name, 0, 255), $mime, strlen($bytes), hash('sha256', $bytes), ds_actor()]);
}

/** A stored file's row (of this organisation), or null. */
function ds_file(int $id): ?array
{
    return $id > 0 ? fabo_one('SELECT * FROM ds_files WHERE id = ? AND org_id = ? AND deleted_at IS NULL', 'ii', [$id, ds_org()]) : null;
}

/** A stored file's bytes, decrypted and checked against its SHA-256. */
function ds_read(int $id): string
{
    $f = ds_file($id);
    if (!$f) {
        throw new RuntimeException('That file is not available.');
    }
    require_once __DIR__ . '/../uploads.php';
    $path = rtrim(fabo_upload_dir(), '/\\') . '/ds/' . $f['stored_name'];
    $raw = @file_get_contents($path);
    if ($raw === false || strncmp($raw, 'DSE1', 4) !== 0) {
        throw new RuntimeException('The stored file is missing or damaged.');
    }
    $pt = openssl_decrypt(substr($raw, 32), 'aes-256-gcm', ds_subkey('files'), OPENSSL_RAW_DATA, substr($raw, 4, 12), substr($raw, 16, 16), 'ds1');
    if ($pt === false || !hash_equals($f['sha256'], hash('sha256', $pt))) {
        throw new RuntimeException('The stored file failed its integrity check.');
    }
    return $pt;
}

/**
 * Is this upload a PDF we will accept? Refuses what is not a PDF, what is
 * encrypted, too large, or carries active content (JavaScript, launch
 * actions, embedded files, XFA, rich media), looking inside compressed
 * object streams as well; and runs ClamAV when the server has it.
 * Returns [bytes to keep, pages, notes].
 */
function ds_scan_pdf(string $bytes, string $name): array
{
    $max = max(1, (int) ds_setting('max_upload_mb')) * 1048576;
    if (strlen($bytes) === 0) {
        throw new InvalidArgumentException('The file is empty.');
    }
    if (strlen($bytes) > $max) {
        throw new InvalidArgumentException('The file is larger than ' . ds_setting('max_upload_mb') . ' MB.');
    }
    if (!str_contains(substr($bytes, 0, 1024), '%PDF-')) {
        throw new InvalidArgumentException('"' . $name . '" is not a PDF. Save the document as PDF and upload that.');
    }
    $notes = [];
    $pdf = new DsPdf($bytes);                 // throws on encrypted / unreadable
    $bad = ['JavaScript' => 'JavaScript', 'JS' => 'JavaScript', 'Launch' => 'a launch action', 'EmbeddedFile' => 'an embedded file',
            'EmbeddedFiles' => 'embedded files', 'RichMedia' => 'rich media', 'XFA' => 'an XFA form', 'ImportData' => 'a data import action',
            'SubmitForm' => 'a form submission action', 'GoToE' => 'an embedded go-to action'];
    $found = [];
    $walk = static function ($v, int $depth) use (&$walk, &$found, $bad) {
        if ($depth > 30) {
            return;
        }
        if ($v instanceof DsName && isset($bad[$v->n])) {
            $found[$bad[$v->n]] = true;
        } elseif ($v instanceof DsDict) {
            foreach ($v->d as $k => $x) {
                if (isset($bad[$k])) {
                    $found[$bad[$k]] = true;
                }
                $walk($x, $depth + 1);
            }
        } elseif (is_array($v)) {
            foreach ($v as $x) {
                $walk($x, $depth + 1);
            }
        }
    };
    $n = 0;
    foreach (array_keys($pdf->xref) as $num) {
        if (++$n > 200000) {
            break;
        }
        try {
            $walk($pdf->get(new DsRef($num)), 0);
        } catch (Throwable $e) {
        }
    }
    if ($found) {
        throw new InvalidArgumentException('"' . $name . '" was refused: it contains ' . implode(', ', array_keys($found))
            . '. Print it to a fresh PDF (File → Print → Save as PDF) and upload that copy.');
    }
    $pages = count($pdf->pages());
    if ($pdf->repaired) {
        $bytes = $pdf->rewrite();
        $pdf = new DsPdf($bytes);
        $notes[] = 'The PDF\'s internal index was damaged and has been rebuilt; the content is unchanged.';
    }
    $cmd = trim(ds_setting('clamav_cmd'));
    if ($cmd !== '' && function_exists('exec') && preg_match('~^/[\w./-]+$~', $cmd)) {
        $f = tempnam(sys_get_temp_dir(), 'dsav');
        file_put_contents($f, $bytes);
        $out = [];
        $rc = 0;
        @exec(escapeshellarg($cmd) . ' --no-summary ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
        @unlink($f);
        if ($rc === 1) {
            throw new InvalidArgumentException('"' . $name . '" was refused by the virus scanner: ' . mb_substr(implode(' ', $out), 0, 200));
        }
        $notes[] = $rc === 0 ? 'Virus scan: clean.' : 'Virus scan could not run (' . mb_substr(implode(' ', $out), 0, 120) . ').';
    }
    return [$bytes, $pages, $notes];
}

/* ================================================================== audit */

/**
 * Writes one entry to the tamper-evident trail. Each entry's hash is an HMAC
 * (with a key the database does not hold) over the previous entry's hash and
 * this entry's fields, so changing, removing or re-ordering any entry breaks
 * every hash after it. Taken under a row lock so the chain never forks.
 */
function ds_audit(?int $docId, string $entity, ?int $entityId, string $action, string $summary, array $detail = []): void
{
    foreach (['password', 'otp', 'pin', 'token', 'secret', 'key'] as $k) {
        unset($detail[$k]);
    }
    $org = ds_org();
    $me = ds_me();
    $row = [
        'doc_id' => $docId, 'entity' => $entity, 'entity_id' => $entityId, 'action' => $action, 'summary' => mb_substr($summary, 0, 600),
        'detail_json' => $detail ? mb_substr((string) json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR), 0, 60000) : null,
        'actor_id' => !empty($GLOBALS['ds_system']) ? 0 : $me, 'actor' => ds_actor(), 'designation' => !empty($GLOBALS['ds_system']) ? '' : ds_designation($me),
        'ip' => function_exists('fabo_client_ip') && PHP_SAPI !== 'cli' ? fabo_client_ip() : (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        'user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? (PHP_SAPI === 'cli' ? 'cli' : '')), 0, 255),
        'created_at' => date('Y-m-d H:i:s'),
    ];
    ds_tx(static function () use ($org, $row) {
        fabo_exec('INSERT IGNORE INTO ds_seq (org_id, kind, fy, next_no) VALUES (?, "audit", 0, 1)', 'i', [$org]);
        $seq = (int) fabo_one('SELECT next_no FROM ds_seq WHERE org_id = ? AND kind = "audit" AND fy = 0 FOR UPDATE', 'i', [$org])['next_no'];
        $prev = $seq > 1 ? (string) (fabo_one('SELECT hash FROM ds_audit WHERE org_id = ? AND seq = ?', 'ii', [$org, $seq - 1])['hash'] ?? str_repeat('0', 64)) : str_repeat('0', 64);
        $hash = ds_audit_hash($org, $seq, $prev, $row);
        fabo_exec('INSERT INTO ds_audit (org_id, seq, doc_id, entity, entity_id, action, summary, detail_json, actor_id, actor, designation, ip, user_agent, created_at, prev_hash, hash)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', 'iiisisssisssssss',
                  [$org, $seq, $row['doc_id'], $row['entity'], $row['entity_id'], $row['action'], $row['summary'], $row['detail_json'], $row['actor_id'],
                   $row['actor'], $row['designation'], $row['ip'], $row['user_agent'], $row['created_at'], $prev, $hash]);
        fabo_exec('UPDATE ds_seq SET next_no = next_no + 1 WHERE org_id = ? AND kind = "audit" AND fy = 0', 'i', [$org]);
    });
    if (function_exists('ds_cache_bust')) {
        ds_cache_bust();
    }
}

function ds_audit_hash(int $org, int $seq, string $prev, array $r): string
{
    $msg = implode("\x1f", [$org, $seq, $prev, (string) $r['doc_id'], $r['entity'], (string) $r['entity_id'], $r['action'], $r['summary'],
                            (string) $r['detail_json'], (string) $r['actor_id'], $r['actor'], (string) $r['designation'], (string) $r['ip'],
                            (string) $r['user_agent'], $r['created_at']]);
    return hash_hmac('sha256', $msg, ds_subkey('audit'));
}

/**
 * Re-computes the whole chain. Returns [ok, entries checked, first broken
 * seq or null, message].
 */
function ds_audit_check(): array
{
    $org = ds_org();
    $prev = str_repeat('0', 64);
    $want = 1;
    $n = 0;
    $last = 0;
    do {
        $rows = fabo_all('SELECT * FROM ds_audit WHERE org_id = ? AND seq > ? ORDER BY seq LIMIT 2000', 'ii', [$org, $last]);
        foreach ($rows as $r) {
            $seq = (int) $r['seq'];
            if ($seq !== $want) {
                return [false, $n, $want, 'Entry ' . $want . ' is missing: the trail has a gap.'];
            }
            if (!hash_equals($prev, (string) $r['prev_hash']) || !hash_equals(ds_audit_hash($org, $seq, $prev, $r), (string) $r['hash'])) {
                return [false, $n, $seq, 'Entry ' . $seq . ' (' . $r['created_at'] . ', ' . $r['action'] . ') does not match its seal: it was altered.'];
            }
            $prev = (string) $r['hash'];
            $want++;
            $n++;
            $last = $seq;
        }
    } while (count($rows) === 2000);
    $next = (int) (fabo_one('SELECT next_no FROM ds_seq WHERE org_id = ? AND kind = "audit" AND fy = 0', 'i', [$org])['next_no'] ?? 1);
    if ($next !== $want) {
        return [false, $n, $want, 'The newest entries (from ' . $want . ') have been removed.'];
    }
    return [true, $n, null, 'All ' . number_format($n) . ' entries are intact and in order.'];
}

/** An HMAC seal over a signing's evidence, so an edited request row is detectable. */
function ds_seal(array $evidence): string
{
    ksort($evidence);
    return hash_hmac('sha256', (string) json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ds_subkey('seal'));
}

/* ============================================================== numbering */

function ds_fy_of(?string $date = null): int
{
    $ts = $date ? strtotime($date) : time();
    return (int) date('n', $ts) >= 4 ? (int) date('Y', $ts) : (int) date('Y', $ts) - 1;
}

/** The next reference: DS/26-27/000042, under a row lock. */
function ds_next_ref(string $kind = 'doc'): string
{
    $fy = ds_fy_of();
    return ds_tx(static function () use ($kind, $fy) {
        fabo_exec('INSERT IGNORE INTO ds_seq (org_id, kind, fy, next_no) VALUES (?,?,?,1)', 'isi', [ds_org(), $kind, $fy]);
        $n = (int) fabo_one('SELECT next_no FROM ds_seq WHERE org_id = ? AND kind = ? AND fy = ? FOR UPDATE', 'isi', [ds_org(), $kind, $fy])['next_no'];
        fabo_exec('UPDATE ds_seq SET next_no = next_no + 1 WHERE org_id = ? AND kind = ? AND fy = ?', 'isi', [ds_org(), $kind, $fy]);
        $prefix = $kind === 'doc' ? (preg_replace('/[^A-Z0-9-]/', '', strtoupper(ds_setting('ref_prefix'))) ?: 'DS') : strtoupper($kind);
        return $prefix . '/' . substr((string) $fy, 2) . '-' . substr((string) ($fy + 1), 2) . '/' . str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    });
}

/** A tracking code people can read out: 16 characters, no look-alikes. */
function ds_tracking_code(): string
{
    $a = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $s = '';
    foreach (str_split(random_bytes(16)) as $c) {
        $s .= $a[ord($c) % 32];
    }
    return $s;
}

/* ============================================================= validation */

function ds_str($v, int $max, bool $req = false, string $label = 'This field'): string
{
    $s = trim((string) ($v ?? ''));
    if ($req && $s === '') {
        throw new InvalidArgumentException($label . ' is required.');
    }
    if (mb_strlen($s) > $max) {
        throw new InvalidArgumentException($label . ' must be ' . $max . ' characters or fewer.');
    }
    return $s;
}

function ds_date($v, bool $req = false, string $label = 'Date'): ?string
{
    $s = trim((string) ($v ?? ''));
    if ($s === '') {
        if ($req) {
            throw new InvalidArgumentException($label . ' is required.');
        }
        return null;
    }
    $d = DateTime::createFromFormat('Y-m-d', $s);
    if (!$d || $d->format('Y-m-d') !== $s) {
        throw new InvalidArgumentException($label . ' is not a valid date.');
    }
    return $s;
}

function ds_amount($v): ?string
{
    $s = str_replace([',', '₹', ' '], '', trim((string) ($v ?? '')));
    if ($s === '') {
        return null;
    }
    if (!is_numeric($s) || (float) $s < 0 || (float) $s > 999999999999) {
        throw new InvalidArgumentException('The document value must be a positive amount.');
    }
    return number_format((float) $s, 2, '.', '');
}

function ds_pick($v, array $allowed, string $label)
{
    if (!array_key_exists((string) $v, $allowed)) {
        throw new InvalidArgumentException('Choose a valid ' . $label . '.');
    }
    return (string) $v;
}

/** One-time token for create forms: a double click never makes two documents. */
function ds_nonce_field(): string
{
    fabo_session();
    $n = bin2hex(random_bytes(12));
    $_SESSION['ds_nonce'][$n] = time();
    if (count($_SESSION['ds_nonce']) > 60) {
        $_SESSION['ds_nonce'] = array_slice($_SESSION['ds_nonce'], -60, null, true);
    }
    return '<input type="hidden" name="_nonce" value="' . $n . '">';
}

function ds_nonce_check(): void
{
    fabo_session();
    $n = (string) ($_POST['_nonce'] ?? '');
    if ($n === '' || !isset($_SESSION['ds_nonce'][$n])) {
        throw new InvalidArgumentException('This form was already submitted (or has expired). Check the list before trying again.');
    }
    unset($_SESSION['ds_nonce'][$n]);
}

/** Optimistic lock on a document: refuses a change drawn from an older copy. */
function ds_bump(int $docId, $version): void
{
    $n = fabo_exec('UPDATE ds_documents SET version = version + 1, last_activity_at = NOW() WHERE id = ? AND org_id = ? AND version = ?', 'iii', [$docId, ds_org(), (int) $version]);
    if ($n < 1) {
        throw new InvalidArgumentException('Someone else changed this document while you had it open. Reload the page and try again.');
    }
}

/* =================================================================== clock */

/*
 * The database and PHP may keep different clocks (on live, MySQL NOW() is
 * UTC while PHP is Asia/Kolkata). Times the database makes (NOW(),
 * DATE_ADD(NOW(), ...)) and times PHP makes must never be compared raw:
 * that made every new request look hours old (instant reminders) and every
 * one-time code look expired. Rule: store times on the database clock; read
 * them with ds_ts(); write PHP-made times with ds_db_time().
 */

/** Seconds to add to a database time (read as a PHP local time) to get the real time. */
function ds_clock_skew(): int
{
    static $skew = null;
    if ($skew === null) {
        $skew = 0;
        try {
            $r = fabo_one('SELECT UNIX_TIMESTAMP() u, NOW() n');
            // Zones differ by whole quarter-hours; rounding absorbs the moment between the two clocks.
            $skew = (int) (round(((int) $r['u'] - strtotime((string) $r['n'])) / 900) * 900);
        } catch (Throwable $e) {
        }
    }
    return $skew;
}

/** A database time as a Unix timestamp (0 when empty). Times with an explicit zone are taken as they are. */
function ds_ts(?string $d): int
{
    if ($d === null || $d === '' || str_starts_with($d, '0000')) {
        return 0;
    }
    if (preg_match('/(Z|[+-]\d\d:?\d\d)$/', $d)) {
        return (int) strtotime($d);
    }
    return (int) strtotime($d) + ds_clock_skew();
}

/** A Unix timestamp written on the database clock, to store next to NOW(). */
function ds_db_time(int $ts): string
{
    return date('Y-m-d H:i:s', $ts - ds_clock_skew());
}

/* ============================================================== formatting */

function ds_fmt_date(?string $d): string
{
    return $d && !str_starts_with($d, '0000') ? date('d M Y', strtotime($d)) : '—';
}

function ds_fmt_dt(?string $d): string
{
    return $d ? date('d M Y, H:i', ds_ts($d)) : '—';
}

/** An audit entry's time: written by PHP on its own clock (and sealed), so shown as it is. */
function ds_fmt_local(?string $d): string
{
    return $d ? date('d M Y, H:i', (int) strtotime($d)) : '—';
}

function ds_ago(?string $d): string
{
    if (!$d) {
        return '—';
    }
    $s = time() - ds_ts($d);
    if ($s < 60) {
        return 'just now';
    }
    if ($s < 3600) {
        return intdiv($s, 60) . ' min ago';
    }
    if ($s < 86400) {
        return intdiv($s, 3600) . ' h ago';
    }
    if ($s < 86400 * 30) {
        return intdiv($s, 86400) . ' d ago';
    }
    return ds_fmt_date($d);
}

function ds_money($v): string
{
    return $v === null || $v === '' ? '—' : '₹' . number_format((float) $v, 2);
}

/* ============================================================ notifications */

/**
 * Tells people in their portal inbox and, when the organisation keeps mail
 * on, by email (branded centrally by lib/mailer.php). Logged per channel.
 */
function ds_notify(array $personIds, string $event, string $title, string $body, ?int $docId, array $q = []): void
{
    require_once __DIR__ . '/../notify.php';
    foreach (array_values(array_unique(array_map('intval', $personIds))) as $pid) {
        if ($pid === ds_me() && empty($GLOBALS['ds_system']) && $event !== 'test') {
            continue;
        }
        $link = ($pid === 0 ? 'admin/' : '') . 'sign.php?' . http_build_query($q ?: ($docId ? ['p' => 'doc', 'id' => $docId] : ['p' => 'inbox']));
        try {
            if ($pid === 0) {
                fabo_notify_admin($title, $body, $link);
            } else {
                fabo_notify($pid, $title, $body, $link);
            }
            fabo_exec('INSERT INTO ds_notifications (org_id, person_id, doc_id, event, channel, subject) VALUES (?,?,?,?,?,?)', 'iiisss',
                      [ds_org(), $pid, $docId, $event, 'inapp', mb_substr($title, 0, 255)]);
        } catch (Throwable $e) {
            error_log('FABO esign notify: ' . $e->getMessage());
        }
        if (ds_setting('mail_notify') !== '1' || $pid === 0) {
            continue;
        }
        $email = ds_person_email($pid);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        $status = 'sent';
        $err = null;
        try {
            $r = fabo_send_mail($email, $title, $body . "\n\nOpen it: " . ds_abs_url($link) . "\n");
            if (is_array($r) && isset($r[0]) && $r[0] === false) {
                $status = 'failed';
                $err = (string) ($r[1] ?? '');
            }
        } catch (Throwable $e) {
            $status = 'failed';
            $err = $e->getMessage();
        }
        try {
            fabo_exec('INSERT INTO ds_notifications (org_id, person_id, doc_id, event, channel, subject, status, error) VALUES (?,?,?,?,?,?,?,?)', 'iiisssss',
                      [ds_org(), $pid, $docId, $event, 'email', mb_substr($title, 0, 255), $status, $err ? mb_substr($err, 0, 255) : null]);
        } catch (Throwable $e) {
        }
    }
}

function ds_abs_url(string $path): string
{
    if (function_exists('fabo_link_host') && ($own = fabo_link_host()) !== '') {
        return 'https://' . $own . '/' . ltrim($path, '/');
    }
    $host = (string) ($_SERVER['HTTP_HOST'] ?? (defined('PORTAL_HOST') ? PORTAL_HOST : 'localhost'));
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || PHP_SAPI === 'cli';
    $base = rtrim(str_replace(['/admin', '/api'], '', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/\\');
    return ($https ? 'https' : 'http') . '://' . $host . $base . '/' . ltrim($path, '/');
}

/* ============================================================ trust store */

/** Trusted anchors for this organisation: the portal-wide ones and its own (PEM list). */
function ds_anchors(): array
{
    return array_map(static fn($r) => (string) $r['pem'],
        fabo_all('SELECT pem FROM ds_trust_anchors WHERE org_id IN (0, ?) AND active = 1', 'i', [ds_org()]));
}

/** Adds certificates (PEM bundle or one DER) to the trust store. Returns how many were new. */
function ds_anchor_add(string $data, string $label, string $kind, bool $global): int
{
    $blocks = ds_pem_split($data);
    if (!$blocks && $data !== '') {
        $blocks = [ds_pem($data)];
    }
    $n = 0;
    foreach ($blocks as $pem) {
        $c = ds_cert_summary($pem);
        if (!$c) {
            throw new InvalidArgumentException('That is not a certificate (use a .cer, .crt or .pem file).');
        }
        if ($kind === 'root' && !$c['self_signed']) {
            $kind = 'intermediate';
        }
        $n += fabo_exec('INSERT IGNORE INTO ds_trust_anchors (org_id, label, kind, fingerprint, subject, not_after, pem, added_by) VALUES (?,?,?,?,?,?,?,?)',
                        'isssssss', [$global ? 0 : ds_org(), mb_substr($label !== '' ? $label : ($c['cn'] ?: 'Certificate'), 0, 160), $kind, $c['fingerprint'],
                                    mb_substr($c['subject_dn'], 0, 600), date('Y-m-d H:i:s', $c['not_after']), $pem, ds_actor()]) > 0 ? 1 : 0;
        ds_audit(null, 'trust', null, 'trust_add', 'Added "' . ($c['cn'] ?: $c['subject_dn']) . '" to the trust store' . ($global ? ' (all organisations)' : ''), ['fingerprint' => $c['fingerprint']]);
    }
    return $n;
}

/** The fetcher verification uses: CRLs cached until their next update. */
function ds_fetcher(): callable
{
    return static function (string $url, ?string $post = null, ?string $type = null): ?string {
        if (isset($GLOBALS['ds_test_fetch'])) {
            return ($GLOBALS['ds_test_fetch'])($url, $post, $type);
        }
        if ($post === null) {
            $h = hash('sha256', $url);
            $c = fabo_one('SELECT body, next_update, fetched_at FROM ds_crl_cache WHERE url_hash = ?', 's', [$h]);
            if ($c && (($c['next_update'] && ds_ts($c['next_update']) > time()) || ds_ts($c['fetched_at']) > time() - 3600)) {
                return (string) $c['body'];
            }
            $body = ds_http_fetch($url);
            if ($body !== null) {
                $crl = ds_crl_parse($body);
                fabo_exec('REPLACE INTO ds_crl_cache (url_hash, url, body, fetched_at, next_update) VALUES (?,?,?,NOW(),?)', 'ssss',
                          [$h, mb_substr($url, 0, 600), $body, $crl && $crl['next_update'] ? ds_db_time((int) $crl['next_update']) : null]);
            }
            return $body ?? ($c ? (string) $c['body'] : null);
        }
        return ds_http_fetch($url, $post, $type);
    };
}

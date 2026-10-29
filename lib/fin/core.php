<?php
/**
 * Finance Portal — the engine underneath every finance page.
 *
 * WHOSE BOOKS. Like IT Management and Incubation Management, every
 * organisation keeps entirely separate tables: ours are fp_*; a client
 * organisation granted Finance on Clients gets fp_o<id>_* the first time
 * anyone in it opens the portal. Code always names tables fp_*; fp_sql()
 * rewrites them for the organisation in use, so one organisation cannot read
 * or write another's ledger. (Function names are fp_ too; no column may start
 * with "fp_", or the rewrite would touch it.)
 *
 * WHO. The Finance grant on Clients decides whether an organisation has the
 * portal at all and whether it may change anything (Owner) or only read it
 * (View only). Inside it, people get finance roles (AP Accountant, Finance
 * Manager, CFO, Internal Auditor …) in Settings; a role is a set of
 * permissions, editable as a matrix. Everyone in the organisation is an
 * Employee: they claim expenses, ask for purchases and payments, and approve
 * what the approval rules route to them. Every page and every action asks
 * fp_can() on the server; hiding a button is never the control.
 *
 * MONEY. Amounts are DECIMAL(16,2) in the database and handled as strings or
 * rounded floats here; anything that must balance is compared in paise
 * (integers) so 0.1 + 0.2 never decides whether a journal posts.
 */

require_once __DIR__ . '/../inc/org.php';

/* ================================================================ tables */

function fp_org(): int
{
    return (int) ($GLOBALS['fp_org'] ?? 0);
}

function fp_use(int $org): void
{
    $GLOBALS['fp_org'] = max(0, $org);
    $GLOBALS['fp_settings_cache'] = null;
}

function fp_prefix(?int $org = null): string
{
    $org = $org ?? fp_org();
    return 'fp_' . ($org > 0 ? 'o' . $org . '_' : '');
}

function fp_sql(string $sql): string
{
    $p = fp_prefix();
    return $p === 'fp_' ? $sql : preg_replace('/\bfp_(?!o\d+_)/', $p, $sql);
}

function fp_all(string $sql, string $types = '', array $params = []): array
{
    return fabo_all(fp_sql($sql), $types, $params);
}

function fp_one(string $sql, string $types = '', array $params = []): ?array
{
    return fabo_one(fp_sql($sql), $types, $params);
}

function fp_exec(string $sql, string $types = '', array $params = []): int
{
    return fabo_exec(fp_sql($sql), $types, $params);
}

/** A number from one row, or 0. */
function fp_val(string $sql, string $types = '', array $params = []): float
{
    $r = fp_one($sql, $types, $params);
    return $r ? (float) (array_values($r)[0] ?? 0) : 0.0;
}

/**
 * Runs $fn in one database transaction. Anything thrown rolls the whole
 * thing back, so a journal is never half-posted and a payment never marks
 * a bill paid without its ledger entry. Nested calls join the outer one.
 */
function fp_tx(callable $fn)
{
    $db = fabo_db();
    if (!empty($GLOBALS['fp_in_tx'])) {
        return $fn();
    }
    $GLOBALS['fp_in_tx'] = true;
    $db->begin_transaction();
    try {
        $out = $fn();
        $db->commit();
        return $out;
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    } finally {
        $GLOBALS['fp_in_tx'] = false;
    }
}

/* ================================================================ access */

/** The organisation a viewer's finance belongs to, or -1 (inc_org_of: ours 0, a client head's id). */
function fp_org_of(array $viewer): int
{
    $c = $viewer['client'] ?? null;
    if (empty($viewer['is_admin']) && $c && !empty($c['is_committee'])) {
        return -1;
    }
    return inc_org_of($viewer);
}

/** The Finance grant an organisation holds: ours always 'edit'; a client's as granted on Clients. */
function fp_org_grant(int $org): string
{
    if ($org === 0) {
        return 'edit';
    }
    if ($org < 0) {
        return 'none';
    }
    $r = fabo_one('SELECT a.permission FROM clients c JOIN client_sheet_access a ON a.client_id = c.id AND a.sheet_key = "finance"
                   WHERE c.id = ? AND c.kind = "client" AND c.parent_id IS NULL AND c.revoked_at IS NULL', 'i', [$org]);
    $p = (string) ($r['permission'] ?? 'none');
    return in_array($p, ['view', 'edit'], true) ? $p : 'none';
}

/** Client organisations that have opened the portal (their tables exist). */
function fp_orgs_installed(): array
{
    $out = [];
    foreach (fabo_all("SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'fp\\_o%\\_settings'") as $r) {
        if (preg_match('/^fp_o(\d+)_settings$/', (string) $r['t'], $m)) {
            $out[] = (int) $m[1];
        }
    }
    sort($out);
    return $out;
}

function fp_viewer(): array
{
    return $GLOBALS['fp_viewer'] ?? ['is_admin' => true, 'client' => null];
}

/** The person acting: their clients.id, or 0 for the administrator (and for cron). */
function fp_me(): int
{
    $v = fp_viewer();
    return !empty($v['is_admin']) ? 0 : (int) ($v['client']['id'] ?? 0);
}

function fp_actor(): string
{
    $v = fp_viewer();
    if (!empty($GLOBALS['fp_system'])) {
        return 'System';
    }
    return !empty($v['is_admin']) ? 'Administrator' : (string) ($v['client']['name'] ?? 'Staff');
}

/** May anything be changed in this organisation's books? (Owner grant.) */
function fp_writable(): bool
{
    return ($GLOBALS['fp_grant'] ?? 'edit') === 'edit';
}

/**
 * The finance administrator: the portal administrator, the head of a client
 * organisation that holds Finance as Owner, or anyone given the System
 * Administrator role. Holds every permission, and is the approver of last
 * resort when nobody holds the role a rule names.
 */
function fp_is_sysadmin(): bool
{
    if (!empty($GLOBALS['fp_system'])) {
        return true;
    }
    $v = fp_viewer();
    if (!empty($v['is_admin'])) {
        return true;
    }
    if (!fp_writable()) {
        return false;
    }
    $me = fp_me();
    if ($me > 0 && $me === fp_org()) {
        return true;
    }
    // A team member the head made Finance Owner on My Team (or we did, on Team Management).
    if (fp_member_grant() === 'edit') {
        return true;
    }
    return in_array('sysadmin', fp_my_roles(), true);
}

/**
 * The Finance grant a team member holds personally (My Team / Team
 * Management): 'none' | 'view' | 'edit', never more than the organisation holds.
 * Without one they still have self-service: claims, requests, approvals.
 */
function fp_member_grant(): string
{
    $v = fp_viewer();
    if (!empty($v['is_admin']) || empty($v['client']['id']) || (int) $v['client']['id'] === fp_org()) {
        return 'none';
    }
    $p = (string) ($v['access']['finance'] ?? 'none');
    if (!in_array($p, ['view', 'edit'], true)) {
        return 'none';
    }
    return $p === 'edit' && fp_writable() ? 'edit' : 'view';
}

/** The organisation's head: the person whose grant it is (0 = the administrator for ours). */
function fp_head_id(): int
{
    return fp_org();
}

/* ------------------------------------------------------------- roles */

function fp_roles(): array
{
    return [
        'employee'        => 'Employee',
        'dept_manager'    => 'Department Manager',
        'ap_accountant'   => 'AP Accountant',
        'ar_accountant'   => 'AR Accountant',
        'accountant'      => 'General Accountant',
        'fpa'             => 'FP&A Analyst',
        'finance_manager' => 'Finance Manager',
        'controller'      => 'Controller',
        'cfo'             => 'CFO',
        'auditor'         => 'Internal Auditor',
        'sysadmin'        => 'System Administrator',
    ];
}

/** Every permission, grouped as the matrix shows them. key => [group, label]. */
function fp_permissions(): array
{
    return [
        'dashboard.view'    => ['Overview', 'See the finance dashboard'],
        'reports.view'      => ['Reports', 'Run financial reports'],
        'reports.export'    => ['Reports', 'Export reports (Excel / CSV)'],
        'gl.view'           => ['General ledger', 'See journals, ledger and statements'],
        'gl.prepare'        => ['General ledger', 'Prepare and submit journals'],
        'gl.close'          => ['General ledger', 'Close and reopen periods, year-end close'],
        'coa.manage'        => ['General ledger', 'Maintain the chart of accounts'],
        'ap.view'           => ['Payables', 'See vendor bills'],
        'ap.prepare'        => ['Payables', 'Enter, validate and submit vendor bills'],
        'ap.override'       => ['Payables', 'Accept a matching or budget exception'],
        'vendor.manage'     => ['Payables', 'Create and edit vendors (activation is approved)'],
        'proc.view'         => ['Procurement', 'See requisitions and purchase orders'],
        'proc.prepare'      => ['Procurement', 'Raise and amend purchase orders'],
        'proc.receive'      => ['Procurement', 'Record goods / service receipts'],
        'ar.view'           => ['Receivables', 'See customer invoices and receipts'],
        'ar.prepare'        => ['Receivables', 'Raise invoices and credit notes'],
        'ar.receipt'        => ['Receivables', 'Record and allocate customer receipts'],
        'customer.manage'   => ['Receivables', 'Create and edit customers, credit limits'],
        'budget.view'       => ['Budgets', 'See budgets and budget vs actual'],
        'budget.prepare'    => ['Budgets', 'Prepare budgets, forecasts and transfers'],
        'budget.control'    => ['Budgets', 'Freeze and release budgets'],
        'claims.view'       => ['Expenses', 'See everyone\'s expense claims'],
        'pay.view'          => ['Treasury', 'See payments and bank accounts'],
        'pay.prepare'       => ['Treasury', 'Prepare payments and batches'],
        'pay.execute'       => ['Treasury', 'Release to bank and record the bank result'],
        'bank.manage'       => ['Treasury', 'Maintain bank accounts, import statements'],
        'bank.reconcile'    => ['Treasury', 'Reconcile bank statements'],
        'assets.view'       => ['Fixed assets', 'See the asset register'],
        'assets.manage'     => ['Fixed assets', 'Add, transfer and dispose of assets'],
        'assets.depreciate' => ['Fixed assets', 'Run depreciation'],
        'tax.view'          => ['Tax & compliance', 'See tax registers and the filing calendar'],
        'tax.manage'        => ['Tax & compliance', 'Maintain tax codes, filings and compliance issues'],
        'controls.manage'   => ['Audit', 'Complete control checklists, keep audit evidence'],
        'audit.view'        => ['Audit', 'Read the audit trail'],
        'settings.manage'   => ['Settings', 'Roles, approval rules, masters and settings'],
    ];
}

/** The permissions each role starts with. Editable in Settings → Permissions. */
function fp_default_matrix(): array
{
    $view = ['dashboard.view', 'reports.view', 'gl.view', 'ap.view', 'proc.view', 'ar.view', 'budget.view', 'claims.view',
             'pay.view', 'assets.view', 'tax.view'];
    $all = array_keys(fp_permissions());
    return [
        'employee'        => [],
        'dept_manager'    => ['dashboard.view', 'budget.view', 'proc.view', 'reports.view'],
        'ap_accountant'   => ['dashboard.view', 'ap.view', 'ap.prepare', 'vendor.manage', 'proc.view', 'proc.prepare', 'proc.receive',
                              'pay.view', 'pay.prepare', 'claims.view', 'gl.view', 'reports.view', 'tax.view'],
        'ar_accountant'   => ['dashboard.view', 'ar.view', 'ar.prepare', 'ar.receipt', 'customer.manage', 'gl.view', 'reports.view', 'pay.view'],
        'accountant'      => ['dashboard.view', 'gl.view', 'gl.prepare', 'ap.view', 'ar.view', 'pay.view', 'bank.reconcile', 'assets.view',
                              'assets.manage', 'assets.depreciate', 'tax.view', 'tax.manage', 'reports.view', 'budget.view', 'controls.manage'],
        'fpa'             => ['dashboard.view', 'budget.view', 'budget.prepare', 'reports.view', 'reports.export', 'gl.view'],
        'finance_manager' => array_merge($view, ['reports.export', 'gl.prepare', 'gl.close', 'ap.prepare', 'ap.override', 'vendor.manage',
                              'proc.prepare', 'proc.receive', 'ar.prepare', 'ar.receipt', 'customer.manage', 'budget.prepare', 'budget.control',
                              'pay.prepare', 'pay.execute', 'bank.manage', 'bank.reconcile', 'assets.manage', 'assets.depreciate', 'tax.manage',
                              'controls.manage', 'audit.view']),
        'controller'      => array_merge($view, ['reports.export', 'gl.prepare', 'gl.close', 'coa.manage', 'ap.override', 'budget.control',
                              'bank.reconcile', 'assets.manage', 'assets.depreciate', 'tax.manage', 'controls.manage', 'audit.view', 'settings.manage']),
        'cfo'             => array_merge($view, ['reports.export', 'budget.control', 'gl.close', 'audit.view', 'settings.manage', 'ap.override']),
        'auditor'         => array_merge($view, ['reports.export', 'audit.view']),
        'sysadmin'        => $all,
    ];
}

function fp_matrix(): array
{
    $m = json_decode((string) fp_setting('perm_matrix'), true);
    $def = fp_default_matrix();
    if (!is_array($m)) {
        return $def;
    }
    $valid = array_keys(fp_permissions());
    foreach ($def as $role => $perms) {
        if (isset($m[$role]) && is_array($m[$role]) && $role !== 'sysadmin') {
            $def[$role] = array_values(array_intersect($m[$role], $valid));
        }
    }
    return $def;
}

/** The roles a person holds (always including Employee). Cached per request. */
function fp_roles_of(int $personId): array
{
    static $memo = [];
    $k = fp_prefix() . $personId;
    if (isset($memo[$k])) {
        return $memo[$k];
    }
    $roles = ['employee'];
    if ($personId > 0) {
        try {
            foreach (fp_all('SELECT DISTINCT role FROM fp_role_members WHERE person_id = ?', 'i', [$personId]) as $r) {
                $roles[] = (string) $r['role'];
            }
        } catch (Throwable $e) {
        }
    }
    return $memo[$k] = array_values(array_unique($roles));
}

function fp_my_roles(): array
{
    return fp_roles_of(fp_me());
}

/**
 * Is Finance the viewer's at all? Ours, the administrator and a client's head
 * always; a client's team member only once assigned to it -- a Finance grant
 * on My Team, a finance role beyond Employee, or a cost centre to manage.
 * Otherwise the portal is neither in their sidebar nor open to them.
 */
function fp_has_access(): bool
{
    $v = fp_viewer();
    if (!empty($v['is_admin']) || fp_org() === 0 || fp_me() === fp_org()) {
        return true;
    }
    if (fp_member_grant() !== 'none' || array_diff(fp_my_roles(), ['employee'])) {
        return true;
    }
    try {
        return (bool) fp_managed_ccs(fp_me());
    } catch (Throwable $e) {
        return false;          // the organisation's tables are not made yet
    }
}

/** Does the viewer hold $perm? Write permissions need an Owner grant. */
function fp_can(string $perm): bool
{
    $readOnly = str_ends_with($perm, '.view') || $perm === 'reports.export';
    if (!$readOnly && !fp_writable()) {
        return false;
    }
    if (fp_is_sysadmin()) {
        return true;
    }
    // A View-only organisation: its people may read everything, change nothing.
    if (!fp_writable() && $readOnly && fp_me() === fp_org()) {
        return true;
    }
    // Finance View only on My Team: reads every desk, changes nothing.
    if ($readOnly && fp_member_grant() !== 'none') {
        return true;
    }
    $m = fp_matrix();
    foreach (fp_my_roles() as $r) {
        if (in_array($perm, $m[$r] ?? [], true)) {
            return true;
        }
    }
    return false;
}

function fp_can_any(array $perms): bool
{
    foreach ($perms as $p) {
        if (fp_can($p)) {
            return true;
        }
    }
    return false;
}

/** Stops with a 403 unless the viewer holds $perm. The check every action makes. */
function fp_need(string $perm): void
{
    if (!fp_can($perm)) {
        error_log('FABO finance denied ' . fp_actor() . ' -> ' . $perm . ' (org ' . fp_org() . ')');
        fabo_deny('You do not have permission for this (' . (fp_permissions()[$perm][1] ?? $perm) . '). Ask your finance administrator.', 403);
    }
}

/** May the viewer change anything at all as an employee (own claims, requests)? */
function fp_self_writable(): bool
{
    return fp_writable() && (fp_me() > 0 || fp_is_sysadmin());
}

/** Cost centres a person manages (owner on the centre, or Department Manager scoped to it). */
function fp_managed_ccs(int $personId): array
{
    if ($personId <= 0) {
        return [];
    }
    $ids = [];
    foreach (fp_all('SELECT id FROM fp_cost_centres WHERE manager_person_id = ?', 'i', [$personId]) as $r) {
        $ids[] = (int) $r['id'];
    }
    foreach (fp_all('SELECT cost_centre_id FROM fp_role_members WHERE person_id = ? AND role = "dept_manager" AND cost_centre_id IS NOT NULL', 'i', [$personId]) as $r) {
        $ids[] = (int) $r['cost_centre_id'];
    }
    return array_values(array_unique($ids));
}

/* ------------------------------------------------------------- people */

/** The people of the organisation in use: id => name (our staff for ours; the head and their team for a client). */
function fp_people(bool $withEmail = false): array
{
    $org = fp_org();
    $rows = $org > 0
        ? fabo_all('SELECT id, name, email FROM clients WHERE (id = ? OR (parent_id = ? AND kind = "team")) AND revoked_at IS NULL ORDER BY name', 'ii', [$org, $org])
        : fabo_all('SELECT id, name, email FROM clients WHERE kind = "team" AND parent_id IS NULL AND revoked_at IS NULL ORDER BY name');
    $out = [];
    foreach ($rows as $r) {
        $out[(int) $r['id']] = $withEmail ? $r : (string) $r['name'];
    }
    return $out;
}

function fp_person_name(?int $id): string
{
    if ($id === null) {
        return '—';
    }
    if ($id === 0) {
        return 'Administrator';
    }
    static $memo = [];
    if (!isset($memo[$id])) {
        $memo[$id] = (string) (fabo_one('SELECT name FROM clients WHERE id = ?', 'i', [$id])['name'] ?? ('#' . $id));
    }
    return $memo[$id];
}

/** Is $personId one of this organisation's people? Writes that name a person check it. */
function fp_is_member(int $personId): bool
{
    return $personId === 0 ? fp_org() === 0 : isset(fp_people()[$personId]);
}

/**
 * A person's manager, as HR knows it: their employee record's manager, if
 * that manager has a sign-in. Null when HR is not in use or has no answer.
 */
function fp_hr_manager_of(int $personId): ?int
{
    if ($personId <= 0) {
        return null;
    }
    try {
        $r = fabo_one('SELECT m.person_id FROM hr_employees e JOIN hr_employees m ON m.id = e.manager_id
                        WHERE e.org_id = ? AND e.person_id = ? AND m.person_id IS NOT NULL LIMIT 1', 'ii', [fp_org(), $personId]);
        return $r ? (int) $r['person_id'] : null;
    } catch (Throwable $e) {
        return null;
    }
}

/* ============================================================== settings */

function fp_setting_defaults(): array
{
    return [
        'fy_start_month'      => '4',
        'base_currency'       => 'INR',
        'sod_strict'          => '1',
        'budget_control'      => 'warn',
        'budget_warn_pct'     => '80',
        'budget_block_pct'    => '100',
        'match_price_tol_pct' => '2',
        'match_qty_tol_pct'   => '0',
        'dup_window_days'     => '30',
        'claim_receipt_above' => '500',
        'claim_backdate_days' => '60',
        'mileage_rate'        => '',
        'per_diem_rate'       => '',
        'escalate_hours'      => '48',
        'ar_reminder_days'    => '3,15,30',
        'ar_credit_control'   => 'warn',
        'retention_years'     => '8',
        'mail_notify'         => '1',
        'perm_matrix'         => '',
        'last_cron'           => '',
    ];
}

function fp_setting(string $k): string
{
    if (!is_array($GLOBALS['fp_settings_cache'] ?? null)) {
        $GLOBALS['fp_settings_cache'] = [];
        try {
            foreach (fp_all('SELECT k, v FROM fp_settings') as $r) {
                $GLOBALS['fp_settings_cache'][$r['k']] = (string) $r['v'];
            }
        } catch (Throwable $e) {
        }
    }
    return $GLOBALS['fp_settings_cache'][$k] ?? (fp_setting_defaults()[$k] ?? '');
}

function fp_setting_put(string $k, string $v): void
{
    fp_exec('INSERT INTO fp_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', 'ss', [$k, $v]);
    $GLOBALS['fp_settings_cache'] = null;
}

/* ================================================================= audit */

/**
 * The audit trail: who did what to which record, when, from where, and what
 * the record looked like before and after. Written for every change.
 */
function fp_audit(string $entity, ?int $id, string $action, string $summary, $before = null, $after = null): void
{
    $enc = static function ($v) {
        if ($v === null) {
            return null;
        }
        if (is_array($v)) {
            // Never keep a secret in the trail, even one held in a record.
            foreach (['bank_account', 'pass', 'password', 'token'] as $k) {
                if (isset($v[$k]) && $v[$k] !== '') {
                    $v[$k] = fp_mask((string) $v[$k]);
                }
            }
        }
        return mb_substr((string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR), 0, 60000);
    };
    fp_exec('INSERT INTO fp_audit (entity, entity_id, action, summary, before_json, after_json, actor_id, actor, ip) VALUES (?,?,?,?,?,?,?,?,?)',
            'sissssiss', [$entity, $id, $action, mb_substr($summary, 0, 500), $enc($before), $enc($after), fp_me(), fp_actor(),
                         function_exists('fabo_client_ip') ? fabo_client_ip() : (string) ($_SERVER['REMOTE_ADDR'] ?? '')]);
}

/** Shows only the last four characters of an account number or similar. */
function fp_mask(string $s): string
{
    $s = trim($s);
    return strlen($s) <= 4 ? str_repeat('•', strlen($s)) : str_repeat('•', max(0, strlen($s) - 4)) . substr($s, -4);
}

/* ============================================================= numbering */

/** The financial year a date falls in, as the year it starts in. */
function fp_fy_of(?string $date = null): int
{
    $ts = $date ? strtotime($date) : time();
    $start = max(1, min(12, (int) fp_setting('fy_start_month')));
    return (int) date('n', $ts) >= $start ? (int) date('Y', $ts) : (int) date('Y', $ts) - 1;
}

function fp_fy_label(int $fy): string
{
    $start = (int) fp_setting('fy_start_month');
    return $start === 1 ? (string) $fy : $fy . '-' . substr((string) ($fy + 1), 2);
}

/** [first day, last day] of a financial year. */
function fp_fy_range(int $fy): array
{
    $start = max(1, min(12, (int) fp_setting('fy_start_month')));
    $from = sprintf('%04d-%02d-01', $fy, $start);
    return [$from, date('Y-m-d', strtotime($from . ' +1 year -1 day'))];
}

/** Month n (1–12) of a financial year as 'Y-m'. */
function fp_fy_month(int $fy, int $n): string
{
    [$from] = fp_fy_range($fy);
    return date('Y-m', strtotime($from . ' +' . ($n - 1) . ' month'));
}

/** Which month (1–12) of its financial year a date is. */
function fp_fy_month_no(string $date): int
{
    [$from] = fp_fy_range(fp_fy_of($date));
    $a = (int) date('Y', strtotime($from)) * 12 + (int) date('n', strtotime($from));
    $b = (int) date('Y', strtotime($date)) * 12 + (int) date('n', strtotime($date));
    return $b - $a + 1;
}

/**
 * The next reference for a kind of document in a financial year, as
 * BILL/26-27/0007. Taken under a row lock inside the caller's transaction,
 * so two people saving at once never get the same number.
 */
function fp_next_no(string $kind, string $date): string
{
    $fy = fp_fy_of($date);
    return fp_tx(static function () use ($kind, $fy) {
        fp_exec('INSERT IGNORE INTO fp_seq (kind, fy, next_no) VALUES (?, ?, 1)', 'si', [$kind, $fy]);
        $n = (int) fp_one('SELECT next_no FROM fp_seq WHERE kind = ? AND fy = ? FOR UPDATE', 'si', [$kind, $fy])['next_no'];
        fp_exec('UPDATE fp_seq SET next_no = next_no + 1 WHERE kind = ? AND fy = ?', 'si', [$kind, $fy]);
        $label = (int) fp_setting('fy_start_month') === 1 ? (string) $fy : substr((string) $fy, 2) . '-' . substr((string) ($fy + 1), 2);
        return $kind . '/' . $label . '/' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    });
}

/* ============================================================ validation */

function fp_str($v, int $max, bool $req = false, string $label = 'This field'): string
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

/** A money or quantity amount as a 2-decimal string, or null when blank and not required. */
function fp_dec($v, bool $req = false, string $label = 'Amount', ?float $min = null, ?float $max = 999999999999.0, int $dp = 2): ?string
{
    $s = str_replace([',', '₹', ' '], '', trim((string) ($v ?? '')));
    if ($s === '') {
        if ($req) {
            throw new InvalidArgumentException($label . ' is required.');
        }
        return null;
    }
    if (!is_numeric($s)) {
        throw new InvalidArgumentException($label . ' must be a number.');
    }
    $f = round((float) $s, $dp);
    if ($min !== null && $f < $min) {
        throw new InvalidArgumentException($label . ' must be at least ' . $min . '.');
    }
    if ($max !== null && $f > $max) {
        throw new InvalidArgumentException($label . ' is too large.');
    }
    return number_format($f, $dp, '.', '');
}

function fp_date($v, bool $req = false, string $label = 'Date'): ?string
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

/** An id that must exist in $table (optionally active). Returns null for blank when not required. */
function fp_ref($v, string $table, string $label, bool $req = false, bool $activeOnly = false): ?int
{
    $id = (int) ($v ?? 0);
    if ($id <= 0) {
        if ($req) {
            throw new InvalidArgumentException('Choose ' . $label . '.');
        }
        return null;
    }
    $row = fp_one('SELECT * FROM ' . $table . ' WHERE id = ?', 'i', [$id]);
    if (!$row) {
        throw new InvalidArgumentException('That ' . $label . ' no longer exists.');
    }
    if ($activeOnly && isset($row['active']) && (int) $row['active'] !== 1) {
        throw new InvalidArgumentException('That ' . $label . ' is inactive.');
    }
    return $id;
}

function fp_currency($v): string
{
    $c = strtoupper(trim((string) ($v ?? '')));
    if ($c === '') {
        return fp_setting('base_currency');
    }
    if (!preg_match('/^[A-Z]{3}$/', $c)) {
        throw new InvalidArgumentException('A currency is a three-letter code such as INR or USD.');
    }
    return $c;
}

/** The exchange rate to base for a document: 1 in base currency, else as given (or the latest on file). */
function fp_fx($currency, $rate, string $date): string
{
    if ($currency === fp_setting('base_currency')) {
        return '1.000000';
    }
    $r = fp_dec($rate, false, 'Exchange rate', 0.000001, 1000000, 6);
    if ($r === null) {
        $row = fp_one('SELECT rate FROM fp_fx_rates WHERE currency = ? AND rate_date <= ? ORDER BY rate_date DESC LIMIT 1', 'ss', [$currency, $date]);
        if (!$row) {
            throw new InvalidArgumentException('Give the exchange rate from ' . $currency . ' to ' . fp_setting('base_currency') . ' (none is on file for that date).');
        }
        $r = number_format((float) $row['rate'], 6, '.', '');
    }
    return $r;
}

/* ============================================================= money */

function fp_r2($v): float
{
    return round((float) $v, 2);
}

/** In paise — what every balance check compares. */
function fp_paise($v): int
{
    return (int) round((float) $v * 100);
}

function fp_money($v, bool $exact = true): string
{
    if ($v === null || $v === '') {
        return '—';
    }
    return $exact ? fabo_money_exact($v) : fabo_money($v);
}

function fp_amt($v): string
{
    return number_format((float) $v, 2, '.', '');
}

function fp_pct(?float $v, int $dp = 0): string
{
    return $v === null ? '—' : number_format($v * 100, $dp) . '%';
}

function fp_fmt_date(?string $d): string
{
    if (!$d || str_starts_with($d, '0000')) {
        return '—';
    }
    return date('d M Y', strtotime($d));
}

function fp_fmt_dt(?string $d): string
{
    return $d ? date('d M Y, H:i', strtotime($d)) : '—';
}

/* ======================================================== concurrency */

/**
 * A one-time token for a create form. A second submit of the same form (a
 * double click, a refresh, a resent request) is refused instead of creating
 * the record twice.
 */
function fp_nonce_field(): string
{
    fabo_session();
    $n = bin2hex(random_bytes(12));
    $_SESSION['fp_nonce'][$n] = time();
    if (count($_SESSION['fp_nonce']) > 60) {
        $_SESSION['fp_nonce'] = array_slice($_SESSION['fp_nonce'], -60, null, true);
    }
    return '<input type="hidden" name="_nonce" value="' . $n . '">';
}

function fp_nonce_check(): void
{
    fabo_session();
    $n = (string) ($_POST['_nonce'] ?? '');
    if ($n === '' || !isset($_SESSION['fp_nonce'][$n])) {
        throw new InvalidArgumentException('This form was already submitted (or has expired). Check the list before trying again.');
    }
    unset($_SESSION['fp_nonce'][$n]);
}

/**
 * Optimistic locking: changes a record only if nobody else has since. Every
 * edit and decision form carries the version it was drawn from.
 */
function fp_bump(string $table, int $id, $version): void
{
    $n = fp_exec('UPDATE ' . $table . ' SET version = version + 1 WHERE id = ? AND version = ?', 'ii', [$id, (int) $version]);
    if ($n < 1) {
        throw new InvalidArgumentException('Someone else changed this record while you had it open. Reload the page and try again.');
    }
}

/* ============================================================ documents */

/**
 * Every kind of document the portal keeps, for the approval engine, the
 * attachments, the audit trail and search.
 *   table, number column, page, permission to read it (null: decided per row).
 */
function fp_doc_types(): array
{
    return [
        'journal'  => ['label' => 'Journal entry',     'table' => 'fp_journals',          'no' => 'number', 'page' => 'journal',  'perm' => 'gl.view'],
        'bill'     => ['label' => 'Vendor bill',       'table' => 'fp_bills',             'no' => 'number', 'page' => 'bill',     'perm' => 'ap.view'],
        'invoice'  => ['label' => 'Customer invoice',  'table' => 'fp_invoices',          'no' => 'number', 'page' => 'invoice',  'perm' => 'ar.view'],
        'receipt'  => ['label' => 'Customer receipt',  'table' => 'fp_receipts',          'no' => 'number', 'page' => 'receipt',  'perm' => 'ar.view'],
        'payment'  => ['label' => 'Payment',           'table' => 'fp_payments',          'no' => 'number', 'page' => 'payment',  'perm' => null],
        'batch'    => ['label' => 'Payment batch',     'table' => 'fp_payment_batches',   'no' => 'number', 'page' => 'batch',    'perm' => 'pay.view'],
        'claim'    => ['label' => 'Expense claim',     'table' => 'fp_claims',            'no' => 'number', 'page' => 'claim',    'perm' => null],
        'advance'  => ['label' => 'Travel advance',    'table' => 'fp_advances',          'no' => 'number', 'page' => 'advance',  'perm' => null],
        'budget'   => ['label' => 'Budget',            'table' => 'fp_budgets',           'no' => 'number', 'page' => 'budget',   'perm' => 'budget.view'],
        'transfer' => ['label' => 'Budget transfer',   'table' => 'fp_budget_transfers',  'no' => 'number', 'page' => 'transfer',   'perm' => 'budget.view'],
        'vendor'   => ['label' => 'Vendor',            'table' => 'fp_vendors',           'no' => 'code',   'page' => 'vendor',   'perm' => 'ap.view'],
        'pr'       => ['label' => 'Purchase requisition', 'table' => 'fp_requisitions',   'no' => 'number', 'page' => 'pr',       'perm' => null],
        'po'       => ['label' => 'Purchase order',    'table' => 'fp_pos',               'no' => 'number', 'page' => 'po',       'perm' => 'proc.view'],
        'asset'    => ['label' => 'Fixed asset',       'table' => 'fp_assets',            'no' => 'tag',    'page' => 'asset',    'perm' => 'assets.view'],
        'control'  => ['label' => 'Control evidence',  'table' => 'fp_control_runs',      'no' => 'id',     'page' => 'controls', 'perm' => 'controls.manage'],
        'evidence' => ['label' => 'Audit evidence',    'table' => 'fp_evidence',          'no' => 'title',  'page' => 'controls', 'perm' => 'audit.view'],
    ];
}

function fp_doc(string $type, int $id): ?array
{
    $t = fp_doc_types()[$type] ?? null;
    return $t ? fp_one('SELECT * FROM ' . $t['table'] . ' WHERE id = ?', 'i', [$id]) : null;
}

/** May the viewer read this document? Their own claims, requests and payments always. */
function fp_doc_can_view(string $type, array $doc): bool
{
    $t = fp_doc_types()[$type] ?? null;
    if (!$t) {
        return false;
    }
    if ($t['perm'] !== null) {
        return fp_can($t['perm']) || fp_wf_is_approver_of($type, (int) $doc['id']);
    }
    $me = fp_me();
    $owner = (int) ($doc['employee_person_id'] ?? $doc['requester_person_id'] ?? $doc['prepared_by_id'] ?? -1);
    if ($owner === $me && $me > 0) {
        return true;
    }
    if (fp_wf_is_approver_of($type, (int) $doc['id'])) {
        return true;
    }
    $perm = ['claim' => 'claims.view', 'advance' => 'claims.view', 'pr' => 'proc.view', 'payment' => 'pay.view'][$type];
    if (fp_can($perm)) {
        return true;
    }
    // A department manager reads what was raised against their cost centres.
    $cc = (int) ($doc['cost_centre_id'] ?? 0);
    return $cc > 0 && in_array($cc, fp_managed_ccs($me), true);
}

/* ============================================================ attachments */

function fp_attach(string $type, int $docId, ?array $file, string $note = ''): ?int
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    require_once __DIR__ . '/../uploads.php';
    [$ok, $msg, $meta] = fabo_store_upload($file);
    if (!$ok) {
        throw new InvalidArgumentException('Attachment: ' . $msg);
    }
    $id = fp_exec('INSERT INTO fp_files (doc_type, doc_id, stored_name, original_name, mime, size, note, uploaded_by_id, uploaded_by) VALUES (?,?,?,?,?,?,?,?,?)',
                  'sisssisis', [$type, $docId, $meta['stored_name'], $meta['original_name'], $meta['mime'], (int) $meta['size'],
                                mb_substr($note, 0, 255) ?: null, fp_me(), fp_actor()]);
    fp_audit($type, $docId, 'attach', 'Attached ' . $meta['original_name']);
    return $id;
}

/** Every file in an upload field that may hold several (name="files[]"). */
function fp_attach_many(string $type, int $docId, string $field = 'files'): int
{
    $f = $_FILES[$field] ?? null;
    if (!$f) {
        return 0;
    }
    if (!is_array($f['name'])) {
        return fp_attach($type, $docId, $f) ? 1 : 0;
    }
    $n = 0;
    foreach ($f['name'] as $i => $name) {
        if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        fp_attach($type, $docId, ['name' => $name, 'type' => $f['type'][$i], 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]]);
        $n++;
    }
    return $n;
}

function fp_files(string $type, int $docId): array
{
    return fp_all('SELECT * FROM fp_files WHERE doc_type = ? AND doc_id = ? ORDER BY id', 'si', [$type, $docId]);
}

/* ============================================================ notices */

/**
 * Tells people something in their portal inbox, and by email when the
 * organisation keeps mail on. Mail is branded with the organisation's name
 * centrally (lib/mailer.php); nothing here names a sender.
 */
function fp_notify(array $personIds, string $title, string $body, string $page, array $q = []): void
{
    require_once __DIR__ . '/../notify.php';
    $personIds = array_values(array_unique(array_map('intval', $personIds)));
    foreach ($personIds as $pid) {
        if ($pid === fp_me() && empty($GLOBALS['fp_system'])) {
            continue;   // never tell someone about their own action
        }
        $link = ($pid === 0 ? 'admin/' : '') . 'fin.php?' . http_build_query(['p' => $page] + $q);
        try {
            if ($pid === 0) {
                fabo_notify_admin($title, $body, $link);
                continue;
            }
            fabo_notify($pid, $title, $body, $link);
            if (fp_setting('mail_notify') === '1') {
                $email = (string) (fabo_one('SELECT email FROM clients WHERE id = ?', 'i', [$pid])['email'] ?? '');
                if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    fabo_send_mail($email, $title, $body . "\n\nOpen it in the portal: " . fp_abs_url($link) . "\n");
                }
            }
        } catch (Throwable $e) {
            error_log('FABO finance notify: ' . $e->getMessage());
        }
    }
}

function fp_abs_url(string $path): string
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '' && defined('APP_URL')) {
        return rtrim((string) APP_URL, '/') . '/' . $path;
    }
    $base = rtrim(str_replace('/admin', '', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/\\');
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $host . $base . '/' . $path;
}

/** Everyone holding one of $roles (the finance administrator when nobody does). */
function fp_role_holders(array $roles, ?int $ccId = null): array
{
    if (!$roles) {
        return [];
    }
    $in = implode(',', array_fill(0, count($roles), '?'));
    $sql = 'SELECT DISTINCT person_id FROM fp_role_members WHERE role IN (' . $in . ')';
    $types = str_repeat('s', count($roles));
    $params = $roles;
    if ($ccId) {
        $sql .= ' AND (cost_centre_id IS NULL OR cost_centre_id = ?)';
        $types .= 'i';
        $params[] = $ccId;
    }
    $ids = array_map(static fn($r) => (int) $r['person_id'], fp_all($sql, $types, $params));
    return array_values(array_filter($ids, 'fp_is_member'));
}

<?php
/**
 * HR Management — the context every HR page and action runs in.
 *
 * Three questions are answered here and nowhere else:
 *
 *   WHOSE data      hr_org(): our company is 0, a client organisation is its
 *                   id. Every HR query filters on it.
 *   MAY THEY        hr_can($module, $action). The administrator may do
 *                   everything. Anybody else holds nothing until a role
 *                   granting it is given to them on the Access page —
 *                   there are no default grants and none are inferred.
 *   ON WHOM         a role's scope: 'self' (their own employee record),
 *                   'team' (the people who report to them, at any depth, and
 *                   themselves) or 'org' (everyone in their organisation).
 *                   hr_emp_allowed() and hr_scope_sql() apply it, so a record
 *                   id typed into a URL is checked the same way a list is.
 */

require_once __DIR__ . '/../ui.php';
require_once __DIR__ . '/../notify.php';
require_once __DIR__ . '/../uploads.php';
require_once __DIR__ . '/../xlsx.php';
require_once __DIR__ . '/schema.php';

/* ------------------------------------------------------------- catalogue */

/** The HR desks, in sidebar order, with the actions each one understands. */
function hr_modules(): array
{
    return [
        'dashboard'  => ['label' => 'HR Dashboard',               'actions' => ['view']],
        'employees'  => ['label' => 'Employee Management',        'actions' => ['view', 'create', 'edit', 'delete', 'export']],
        'confidential' => ['label' => 'Confidential employee data (bank, IDs, documents, salary)',
                         'actions' => ['view', 'edit'], 'nav' => false],
        'leave'      => ['label' => 'Leave Management',           'actions' => ['view', 'create', 'edit', 'approve', 'delete', 'export', 'configure']],
        'attendance' => ['label' => 'Attendance Management',      'actions' => ['view', 'create', 'edit', 'approve', 'export', 'configure']],
        'payroll'    => ['label' => 'Payroll Management',         'actions' => ['view', 'process_payroll', 'approve', 'export', 'configure']],
        'holidays'   => ['label' => 'Holiday Management',         'actions' => ['view', 'create', 'edit', 'delete', 'export']],
        'recruitment' => ['label' => 'Recruitment',               'actions' => ['view', 'create', 'edit', 'delete', 'export']],
        'lifecycle'  => ['label' => 'Onboarding & Exits',         'actions' => ['view', 'create', 'edit', 'approve', 'configure']],
        'performance' => ['label' => 'Performance Reviews',       'actions' => ['view', 'create', 'edit', 'approve', 'configure']],
        'training'   => ['label' => 'Training & Development',     'actions' => ['view', 'create', 'edit', 'delete', 'export']],
        'assets'     => ['label' => 'Asset Management',           'actions' => ['view', 'create', 'edit', 'delete', 'export']],
        'claims'     => ['label' => 'Expense Claims',             'actions' => ['view', 'create', 'edit', 'approve', 'export', 'configure']],
        'helpdesk'   => ['label' => 'Helpdesk & Announcements',   'actions' => ['view', 'create', 'edit', 'delete']],
        'sheets'     => ['label' => 'Policies & Rules (leave, attendance, payroll)', 'actions' => ['view', 'configure']],
        'masters'    => ['label' => 'Organisation Structure (departments, designations, lists)', 'actions' => ['view', 'create', 'edit', 'delete']],
        'schedules'  => ['label' => 'Shifts & Work Calendars',    'actions' => ['view', 'create', 'edit', 'delete']],
        'reports'    => ['label' => 'Reports & Analytics',        'actions' => ['view', 'export']],
        'access'     => ['label' => 'Access Control', 'actions' => ['view', 'configure'], 'admin_only' => true],
    ];
}

function hr_action_labels(): array
{
    return [
        'view' => 'View', 'create' => 'Create', 'edit' => 'Edit', 'approve' => 'Approve',
        'delete' => 'Delete', 'export' => 'Export', 'configure' => 'Configure',
        'process_payroll' => 'Process Payroll',
    ];
}

/**
 * What a 'self'-scoped role may meaningfully hold. Anything else on a self
 * role would be either pointless (approve your own leave) or a way to change
 * company-wide settings through a role meant for one person, so it is
 * refused when the role is saved and ignored if it is ever found.
 */
function hr_self_allowed(string $module, string $action): bool
{
    $ok = [
        'dashboard'  => ['view'],
        'employees'  => ['view', 'edit'],          // edit = their own contact details only
        'confidential' => ['view'],
        'leave'      => ['view', 'create', 'edit'], // apply and cancel their own
        'attendance' => ['view', 'create'],        // check in/out and ask for corrections
        'payroll'    => ['view'],                  // their own payslips
        'holidays'   => ['view'],
        'reports'    => [],
        'recruitment' => [],
        'lifecycle'  => ['view', 'create', 'edit'], // resign; tick their own onboarding tasks
        'performance' => ['view', 'edit'],         // their goals and self-assessment
        'training'   => ['view', 'create'],        // enrol in open sessions
        'assets'     => ['view'],                  // what they hold
        'claims'     => ['view', 'create'],        // submit and follow their own claims
        'helpdesk'   => ['view', 'create'],        // read announcements, raise a query
    ];
    return in_array($action, $ok[$module] ?? [], true);
}

/** Config desks act on the organisation, so only an 'org' role may change them. */
function hr_org_only_module(string $module): bool
{
    return in_array($module, ['sheets', 'masters', 'schedules', 'access'], true);
}

/* --------------------------------------------------------------- context */

/**
 * Set once per request by the front controller. Pages read it through the
 * helpers below rather than passing it around.
 */
function hr_start(array $viewer): array
{
    hr_install();
    $isAdmin = !empty($viewer['is_admin']);
    $person  = $isAdmin ? null : ($viewer['client'] ?? null);

    if ($isAdmin) {
        $org = hr_admin_org();
    } else {
        $org = fabo_viewer_org($viewer);
    }

    $ctx = [
        'viewer'   => $viewer,
        'is_admin' => $isAdmin,
        'person'   => $person,
        'org'      => $org,
        'actor'    => $isAdmin ? 'Administrator' : ((string) ($person['name'] ?? 'User') . ' (#' . (int) ($person['id'] ?? 0) . ')'),
        'perms'    => [],
        'self_emp' => null,
        'owner'    => false,   // a client given "HR — their own": Can edit
    ];
    if (!$isAdmin && $person) {
        $ctx['perms']    = hr_person_perms((int) $person['id'], $org);
        /* The HR module. For a client, granted on their access page: their own
           company. For our own staff, granted on Team Management: ours (org 0).
           Anyone else at org 0 -- nobody today -- still gets HR through roles. */
        $grant = ($org > 0 || fabo_is_our_staff($viewer)) ? fabo_hr_grant($viewer) : 'none';
        if ($grant === 'edit') {
            $ctx['owner'] = true;
            $ctx['actor'] .= ' — HR owner';
        } elseif ($grant === 'view') {
            foreach (hr_modules() as $m => $def) {
                if (empty($def['admin_only'])) {
                    foreach (array_intersect($def['actions'], ['view', 'export']) as $act) {
                        $ctx['perms'][$m][$act] = 'org';
                    }
                }
            }
        }
        $ctx['self_emp'] = fabo_one('SELECT * FROM hr_employees WHERE person_id = ? AND org_id = ?',
                                    'ii', [(int) $person['id'], $org]);
    }
    $GLOBALS['hr_ctx'] = $ctx;
    unset($GLOBALS['hr_attention']);
    return $ctx;
}

function hr_ctx(): array
{
    if (empty($GLOBALS['hr_ctx'])) {
        throw new RuntimeException('HR context not started.');
    }
    return $GLOBALS['hr_ctx'];
}

function hr_org(): int        { return (int) hr_ctx()['org']; }
/** Runs HR for the organisation in view: the administrator, or a client given "HR — their own" to edit. */
function hr_is_admin(): bool  { $c = hr_ctx(); return $c['is_admin'] || !empty($c['owner']); }
/** The portal administrator only: switches between companies. */
function hr_is_portal_admin(): bool { return (bool) hr_ctx()['is_admin']; }
function hr_actor(): string   { return (string) hr_ctx()['actor']; }
function hr_self_emp(): ?array { return hr_ctx()['self_emp']; }

/**
 * Which organisation the administrator is looking at. Ours by default; a
 * client organisation when picked from the switcher, remembered for the
 * session so every HR page agrees.
 */
function hr_admin_org(): int
{
    fabo_session();
    if (isset($_GET['org']) && is_numeric($_GET['org'])) {
        $want = (int) $_GET['org'];
        if ($want === 0 || fabo_one('SELECT id FROM clients WHERE id = ? AND kind = "client"
                                     AND (parent_id IS NULL OR parent_id = 0)', 'i', [$want])) {
            $_SESSION['hr_org'] = $want;
        }
    }
    return (int) ($_SESSION['hr_org'] ?? 0);
}

/** Organisations the administrator can switch between: ours plus every top-level client. */
function hr_org_choices(): array
{
    $out = [0 => fabo_app_name() . ' (our company)'];
    foreach (fabo_all('SELECT id, name, organisation FROM clients WHERE kind = "client"
                       AND (parent_id IS NULL OR parent_id = 0) ORDER BY organisation, name') as $c) {
        $out[(int) $c['id']] = ($c['organisation'] ?: $c['name']);
    }
    return $out;
}

/**
 * module => action => widest scope, from every active role the person holds
 * in this organisation. Two roles combine: the wider scope wins.
 */
function hr_person_perms(int $personId, int $org): array
{
    $rank = ['self' => 1, 'team' => 2, 'org' => 3];
    $out  = [];
    $rows = fabo_all('SELECT r.scope, p.module, p.action
                        FROM hr_role_members m
                        JOIN hr_roles r ON r.id = m.role_id AND r.active = 1 AND r.org_id = ?
                        JOIN hr_role_perms p ON p.role_id = r.id
                       WHERE m.person_id = ?', 'ii', [$org, $personId]);
    $mods = hr_modules();
    foreach ($rows as $r) {
        $m = $r['module'];
        $a = $r['action'];
        if (!isset($mods[$m]) || !in_array($a, $mods[$m]['actions'], true) || !empty($mods[$m]['admin_only'])) {
            continue;
        }
        if ($r['scope'] === 'self' && !hr_self_allowed($m, $a)) {
            continue;
        }
        if ($r['scope'] !== 'org' && hr_org_only_module($m)) {
            continue;
        }
        $cur = $out[$m][$a] ?? null;
        if ($cur === null || $rank[$r['scope']] > $rank[$cur]) {
            $out[$m][$a] = $r['scope'];
        }
    }
    return $out;
}

/** The scope a viewer holds for an action, or null when they hold none. Admin: 'org'. */
function hr_scope(string $module, string $action = 'view'): ?string
{
    $ctx = hr_ctx();
    if ($ctx['is_admin'] || !empty($ctx['owner'])) {
        return 'org';
    }
    return $ctx['perms'][$module][$action] ?? null;
}

function hr_can(string $module, string $action = 'view'): bool
{
    return hr_scope($module, $action) !== null;
}

/** Holds anything at all in HR. */
function hr_has_any(): bool
{
    return hr_is_admin() || !empty(hr_ctx()['perms']);
}

/** Stops unless the viewer holds the action. Logged, generic message. */
function hr_need(string $module, string $action = 'view'): void
{
    if (!hr_can($module, $action)) {
        error_log('FABO HR denied ' . hr_actor() . " -> {$module}.{$action}");
        fabo_deny('You do not have permission to do that in HR.', 403);
    }
}

/** Employee ids that report to $empId, directly or further down. */
function hr_team_ids(int $empId): array
{
    $org  = hr_org();
    $all  = [];
    $wave = [$empId];
    for ($depth = 0; $wave && $depth < 12; $depth++) {
        $in   = implode(',', array_map('intval', $wave));
        $wave = [];
        foreach (fabo_all('SELECT id FROM hr_employees WHERE org_id = ? AND manager_id IN (' . $in . ')',
                          'i', [$org]) as $r) {
            $id = (int) $r['id'];
            if (!isset($all[$id]) && $id !== $empId) {
                $all[$id] = true;
                $wave[]   = $id;
            }
        }
    }
    return array_keys($all);
}

/**
 * May the viewer act on this employee for this action? Checks the org, then
 * the scope. This is what makes a guessed ?id= harmless.
 */
function hr_emp_allowed(int $empId, string $module, string $action = 'view'): bool
{
    $scope = hr_scope($module, $action);
    if ($scope === null || $empId <= 0) {
        return false;
    }
    $emp = fabo_one('SELECT id, org_id FROM hr_employees WHERE id = ?', 'i', [$empId]);
    if (!$emp || (int) $emp['org_id'] !== hr_org()) {
        return false;
    }
    if ($scope === 'org') {
        return true;
    }
    $self = hr_self_emp();
    if (!$self) {
        return false;
    }
    if ((int) $self['id'] === $empId) {
        return true;
    }
    return $scope === 'team' && in_array($empId, hr_team_ids((int) $self['id']), true);
}

function hr_need_emp(int $empId, string $module, string $action = 'view'): void
{
    if (!hr_emp_allowed($empId, $module, $action)) {
        error_log('FABO HR denied ' . hr_actor() . " -> {$module}.{$action} on employee {$empId}");
        fabo_deny('That record is not available to you.', 404);
    }
}

/**
 * The employees a list may show, as SQL on the alias given. Always pins the
 * org; narrows to self or team when that is the viewer's scope; matches
 * nothing at all when they hold no scope.
 */
function hr_scope_sql(string $module, string $action = 'view', string $alias = 'e', string $col = 'id'): array
{
    $scope = hr_scope($module, $action);
    $c     = $alias . '.' . $col;
    if ($scope === null) {
        return ['sql' => ' AND 1 = 0', 'types' => '', 'params' => []];
    }
    if ($scope === 'org') {
        return ['sql' => '', 'types' => '', 'params' => []];
    }
    $self = hr_self_emp();
    if (!$self) {
        return ['sql' => ' AND 1 = 0', 'types' => '', 'params' => []];
    }
    $ids = [(int) $self['id']];
    if ($scope === 'team') {
        $ids = array_merge($ids, hr_team_ids((int) $self['id']));
    }
    return ['sql' => ' AND ' . $c . ' IN (' . implode(',', array_map('intval', $ids)) . ')',
            'types' => '', 'params' => []];
}

/* ---------------------------------------------------------------- audit */

function hr_audit(string $action, string $entity, ?int $entityId, string $summary = '',
                  $before = null, $after = null): void
{
    $ctx = $GLOBALS['hr_ctx'] ?? null;
    $isAdmin = $ctx ? $ctx['is_admin'] : true;
    $enc = static fn($v) => $v === null ? null : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    try {
        fabo_exec('INSERT INTO hr_audit (org_id, actor_type, actor_id, actor_label, action, entity, entity_id,
                                         summary, before_json, after_json, ip)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?)', 'isisssissss', [
            $ctx ? (int) $ctx['org'] : 0,
            $ctx ? ($isAdmin ? 'admin' : 'person') : 'system',
            $ctx && !$isAdmin ? (int) ($ctx['person']['id'] ?? 0) : null,
            $ctx ? $ctx['actor'] : 'System',
            $action, $entity, $entityId, mb_substr($summary, 0, 255),
            $enc($before), $enc($after),
            mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'cli'), 0, 45),
        ]);
    } catch (Throwable $e) {
        error_log('FABO HR audit failed: ' . $e->getMessage());
    }
}

/** The audit trail of one record, newest first. */
function hr_audit_for(string $entity, int $id, int $limit = 50): array
{
    return fabo_all('SELECT * FROM hr_audit WHERE org_id = ? AND entity = ? AND entity_id = ?
                     ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)), 'isi', [hr_org(), $entity, $id]);
}

/* ------------------------------------------------------------- settings */

/**
 * Every setting the HR desks read, with how to edit it. A default of null
 * means "not configured": the module says so and refuses to guess, which is
 * how business policy stays the administrator's decision rather than ours.
 */
function hr_setting_defs(): array
{
    return [
        // Leave
        'leave_year_start_month' => ['area' => 'leave', 'label' => 'Leave year starts in', 'type' => 'month', 'default' => '1',
            'help' => 'Balances, accruals and carry-forward are counted per leave year.'],
        'leave_approval_chain' => ['area' => 'leave_chain', 'label' => 'Leave approval steps', 'type' => 'chain', 'default' => null],
        'leave_admin_override' => ['area' => 'leave', 'label' => 'The administrator can approve at any step', 'type' => 'bool', 'default' => '1',
            'help' => 'When on, the administrator can give the final approval without waiting for the earlier steps.'],
        'leave_reserve_pending' => ['area' => 'leave', 'label' => 'Hold pending requests against the balance', 'type' => 'bool', 'default' => '1',
            'help' => 'When on, a submitted request reduces the available balance until it is decided, so the same days cannot be asked for twice.'],
        'leave_accrual_timing' => ['area' => 'leave', 'label' => 'Monthly accrual is credited', 'type' => 'select', 'default' => 'start',
            'options' => ['start' => 'At the start of each month', 'end' => 'When the month is complete']],
        'leave_backdate_days' => ['area' => 'leave', 'label' => 'Employees may apply for past dates up to (days)', 'type' => 'int', 'default' => '30'],

        // Attendance
        'att_unmarked_past' => ['area' => 'attendance', 'label' => 'A past working day with no attendance record counts as', 'type' => 'select',
            'default' => 'absent', 'options' => ['absent' => 'Absent', 'present' => 'Present (attendance is by exception)']],
        'att_correction_days' => ['area' => 'attendance', 'label' => 'Corrections may be requested up to (days after the date)', 'type' => 'int', 'default' => '7'],
        'att_correction_approval' => ['area' => 'attendance', 'label' => 'Corrections are approved by', 'type' => 'select', 'default' => 'admin',
            'options' => ['admin' => 'Admin / HR', 'manager' => 'Reporting manager (or Admin / HR)']],
        'att_self_checkin' => ['area' => 'attendance', 'label' => 'Employees may check in and out from their HR page', 'type' => 'bool', 'default' => '0'],
        'att_wfh_allowed' => ['area' => 'attendance', 'label' => 'Work from home may be recorded', 'type' => 'bool', 'default' => '1'],
        'att_ot_needs_approval' => ['area' => 'attendance', 'label' => 'Overtime counts for pay only once approved', 'type' => 'bool', 'default' => '1'],
        'att_comp_off_on_holiday' => ['area' => 'attendance', 'label' => 'Working on a holiday or weekly off can earn a compensatory off', 'type' => 'bool', 'default' => '0'],

        // Payroll
        'pay_divisor' => ['area' => 'payroll', 'label' => 'Per-day salary is the monthly amount divided by', 'type' => 'select', 'default' => null,
            'options' => ['calendar' => 'Calendar days in the month', 'working' => 'Working days (calendar − weekly offs − holidays)',
                          'fixed30' => 'A fixed 30 days', 'fixed26' => 'A fixed 26 days'],
            'help' => 'Required before payroll can be calculated. Confirm the method with your payroll adviser.'],
        'pay_lop_method' => ['area' => 'payroll', 'label' => 'Unpaid leave and absence deduction', 'type' => 'select', 'default' => null,
            'options' => ['prorate' => 'Pro-rate each component marked "reduces with unpaid days" by per-day rate × unpaid days',
                          'none' => 'Do not deduct automatically (record adjustments by hand)'],
            'help' => 'Required before payroll can be calculated.'],
        'pay_overtime' => ['area' => 'payroll', 'label' => 'Overtime pay', 'type' => 'select', 'default' => 'none',
            'options' => ['none' => 'Not paid through payroll', 'multiplier' => 'Hourly rate × multiplier', 'fixed' => 'Fixed amount per hour']],
        'pay_overtime_value' => ['area' => 'payroll', 'label' => 'Overtime multiplier or hourly amount', 'type' => 'decimal', 'default' => ''],
        'pay_round' => ['area' => 'payroll', 'label' => 'Round net pay', 'type' => 'select', 'default' => 'rupee',
            'options' => ['none' => 'No rounding (to the paisa)', 'rupee' => 'To the nearest rupee', 'ten' => 'Up to the next ₹10']],
        'pay_cutoff_day' => ['area' => 'payroll', 'label' => 'Payroll cut-off day of the month', 'type' => 'int', 'default' => '',
            'help' => 'For reference on the payroll page. Attendance is counted for the whole calendar month.'],
        'pay_needs_review' => ['area' => 'payroll', 'label' => 'A run must be reviewed before it can be approved', 'type' => 'bool', 'default' => '1'],
        'pay_slip_format' => ['area' => 'payroll', 'label' => 'Payslip number format', 'type' => 'text', 'default' => 'PS/{YYYY}/{MM}/{SEQ}',
            'help' => '{YYYY} year, {MM} month, {SEQ} a running number for the month, {EMP} employee ID.'],
        'pay_company_name' => ['area' => 'payroll', 'label' => 'Company name on payslips', 'type' => 'text', 'default' => ''],
        'pay_company_address' => ['area' => 'payroll', 'label' => 'Company address on payslips', 'type' => 'textarea', 'default' => ''],

        // Employees
        'emp_code_format' => ['area' => 'employees', 'label' => 'Employee ID format', 'type' => 'text', 'default' => 'EMP{SEQ}',
            'help' => '{SEQ} is a running number padded to 4 digits; {YY} the joining year. IDs are never reused.'],

        // Notifications
        'notify_email' => ['area' => 'notifications', 'label' => 'Also send HR notifications by email', 'type' => 'bool', 'default' => '1',
            'help' => 'Uses the portal\'s own mail settings. In-app notifications are always sent.'],

        // Onboarding and exits
        'onboard_auto' => ['area' => 'lifecycle', 'label' => 'Start the default onboarding checklist when an employee is added', 'type' => 'bool', 'default' => '1',
            'help' => 'Only once a default onboarding checklist exists. It can also be started by hand from the employee\'s Onboarding & exit tab.'],
        'exit_notice_days' => ['area' => 'lifecycle', 'label' => 'Standard notice period (days)', 'type' => 'int', 'default' => '30',
            'help' => 'Suggests the last working day when someone resigns. The approver can set a different date.'],
        'exit_block_assets' => ['area' => 'lifecycle', 'label' => 'An exit cannot be completed while the employee still holds company assets', 'type' => 'bool', 'default' => '1'],

        // Expense claims
        'claim_approval' => ['area' => 'claims', 'label' => 'Claims are approved by', 'type' => 'select', 'default' => 'manager',
            'options' => ['manager' => 'Reporting manager (or HR)', 'hr' => 'HR only']],
        'claim_receipt_above' => ['area' => 'claims', 'label' => 'A receipt must be attached to claims above (₹)', 'type' => 'decimal', 'default' => '0',
            'help' => '0 asks for a receipt on every claim. Leave it blank to never require one.'],
        'claim_backdate_days' => ['area' => 'claims', 'label' => 'Expenses may be claimed up to (days after they were spent)', 'type' => 'int', 'default' => '60'],

        // Performance
        'perf_self_first' => ['area' => 'performance', 'label' => 'Employees complete a self-assessment before their manager rates them', 'type' => 'bool', 'default' => '1',
            'help' => 'When off, the manager can rate as soon as the goals are in.'],
    ];
}

function hr_setting(string $key, ?int $org = null)
{
    static $cache = [];
    $org ??= hr_org();
    if (!isset($cache[$org])) {
        $cache[$org] = [];
        foreach (fabo_all('SELECT k, v FROM hr_settings WHERE org_id = ?', 'i', [$org]) as $r) {
            $cache[$org][$r['k']] = $r['v'];
        }
    }
    if ($key === '__flush') {
        unset($cache[$org]);
        return null;
    }
    if (array_key_exists($key, $cache[$org])) {
        return $cache[$org][$key];
    }
    return hr_setting_defs()[$key]['default'] ?? null;
}

/** Saves one setting, keeping its history. Returns true when it changed. */
function hr_setting_set(string $key, ?string $value, ?string $effective = null): bool
{
    $defs = hr_setting_defs();
    if (!isset($defs[$key])) {
        throw new InvalidArgumentException('Unknown setting.');
    }
    $old = hr_setting($key);
    if ((string) $old === (string) $value) {
        return false;
    }
    fabo_exec('INSERT INTO hr_settings (org_id, k, v, updated_by) VALUES (?,?,?,?)
               ON DUPLICATE KEY UPDATE v = VALUES(v), updated_by = VALUES(updated_by)',
              'isss', [hr_org(), $key, $value, hr_actor()]);
    fabo_exec('INSERT INTO hr_config_history (org_id, area, item, old_value, new_value, effective_from, changed_by)
               VALUES (?,?,?,?,?,?,?)', 'issssss',
              [hr_org(), $defs[$key]['area'], $defs[$key]['label'], (string) $old, (string) $value,
               $effective ?: date('Y-m-d'), hr_actor()]);
    hr_setting('__flush');
    return true;
}

/** Records a change to a master or policy record in the configuration history. */
function hr_config_log(string $area, string $item, $old, $new, ?string $effective = null): void
{
    $fmt = static function ($v): string {
        if (is_array($v)) {
            $parts = [];
            foreach ($v as $k => $x) {
                if (in_array($k, ['id', 'org_id', 'created_at', 'updated_at', 'created_by', 'updated_by'], true)) {
                    continue;
                }
                $parts[] = $k . '=' . (is_scalar($x) || $x === null ? (string) $x : json_encode($x));
            }
            return implode('; ', $parts);
        }
        return (string) $v;
    };
    fabo_exec('INSERT INTO hr_config_history (org_id, area, item, old_value, new_value, effective_from, changed_by)
               VALUES (?,?,?,?,?,?,?)', 'issssss',
              [hr_org(), $area, mb_substr($item, 0, 120), $fmt($old), $fmt($new), $effective ?: date('Y-m-d'), hr_actor()]);
}

/* -------------------------------------------------------- notifications */

/**
 * An in-app notice to one employee (through the account they sign in with),
 * once per $key. Email follows, to the owner's mailbox (never the
 * employee's), when the portal has mail configured and the HR setting
 * allows it. Returns false when it had already been sent.
 */
function hr_notify_employee(int $empId, string $key, string $title, string $body, string $link = 'hr.php?p=me', bool $email = true): bool
{
    $emp = fabo_one('SELECT id, org_id, person_id, full_name, emp_code FROM hr_employees WHERE id = ?', 'i', [$empId]);
    if (!$emp) {
        return false;
    }
    if (!hr_notice_once((int) $emp['org_id'], $key . ':e' . $empId)) {
        return false;
    }
    if ($emp['person_id']) {
        fabo_notify((int) $emp['person_id'], $title, $body, $link);
    }
    if (!$email) {
        return true;
    }
    // Employees' own addresses are never written to: the owner's mailbox gets it, headed with who it is for.
    $org = (int) $emp['org_id'];
    hr_email($org, hr_owner_email($org), hr_for_employee_subject($emp, $title),
             hr_for_employee_body($emp, $body), hr_owner_name($org), null,
             ($org > 0 ? '' : 'admin/') . 'hr.php?p=employee&id=' . (int) $emp['id']);
    return true;
}

/**
 * The owner's mailbox for an organisation: where every HR email goes,
 * including those meant for an employee. Our own company (org 0) uses the
 * administrator's inbox; a client company uses the address on its account,
 * falling back to the administrator's.
 */
function hr_owner_email(int $org): string
{
    if ($org > 0) {
        $c = fabo_one('SELECT email FROM clients WHERE id = ?', 'i', [$org]);
        $to = trim((string) ($c['email'] ?? ''));
        if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return $to;
        }
    }
    $to = defined('ADMIN_INBOX_EMAIL') ? trim((string) ADMIN_INBOX_EMAIL) : '';
    if ($to === '' && function_exists('fabo_admin_email')) {
        $to = fabo_admin_email();
    }
    return $to;
}

/**
 * A client company with no usable email of its own: its HR mail falls back
 * to the main owner's inbox, which then needs to be told which company it is.
 */
function hr_owner_is_fallback(int $org): bool
{
    if ($org <= 0) {
        return false;
    }
    $c = fabo_one('SELECT email FROM clients WHERE id = ?', 'i', [$org]);
    $to = trim((string) ($c['email'] ?? ''));
    return $to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL);
}

/** The company's name, as the main owner knows it. */
function hr_org_label(int $org): string
{
    if ($org <= 0) {
        return fabo_app_name();
    }
    $c = fabo_one('SELECT name, organisation FROM clients WHERE id = ?', 'i', [$org]);
    return trim((string) ($c['organisation'] ?? '')) ?: (string) ($c['name'] ?? ('Company #' . $org));
}

/** The subject in the owner's inbox: headed with the company when it fell back to the main owner. */
function hr_owner_subject(int $org, string $subject): string
{
    return hr_owner_is_fallback($org) ? '[' . hr_org_label($org) . '] ' . $subject : $subject;
}

/** Who the owner's mailbox is addressed to in the greeting. */
function hr_owner_name(int $org): string
{
    if ($org > 0 && !hr_owner_is_fallback($org)) {
        $c = fabo_one('SELECT name FROM clients WHERE id = ?', 'i', [$org]);
        if (trim((string) ($c['name'] ?? '')) !== '') {
            return (string) $c['name'];
        }
    }
    return 'Administrator';
}

/** "Ravi Kumar (EMP001) — Leave approved": the subject of an employee's email in the owner's mailbox. */
function hr_for_employee_subject(array $emp, string $title): string
{
    $code = trim((string) ($emp['emp_code'] ?? ''));
    return $emp['full_name'] . ($code !== '' ? ' (' . $code . ')' : '') . ' — ' . $title;
}

/** The body of an employee's email in the owner's mailbox, saying who it is for. */
function hr_for_employee_body(array $emp, string $body): string
{
    $code = trim((string) ($emp['emp_code'] ?? ''));
    return 'For employee: ' . $emp['full_name'] . ($code !== '' ? ' (' . $code . ')' : '') . "\n\n" . $body;
}

/** A notice to the administrator's inbox and mailbox, once per $key. */
function hr_notify_admin(string $key, string $title, string $body, string $link = 'admin/hr.php'): bool
{
    if (!hr_notice_once(hr_org(), $key . ':admin')) {
        return false;
    }
    fabo_notify_admin($title, $body, $link);
    hr_email(hr_org(), hr_owner_email(hr_org()), $title, $body, hr_owner_name(hr_org()), null, $link);
    return true;
}

/** True the first time a key is seen for an org; false every time after. */
function hr_notice_once(int $org, string $key): bool
{
    try {
        fabo_exec('INSERT INTO hr_notice_log (org_id, dedupe_key) VALUES (?, ?)', 'is', [$org, mb_substr($key, 0, 190)]);
        return true;
    } catch (mysqli_sql_exception $e) {
        if (str_contains($e->getMessage(), 'Duplicate')) {
            return false;
        }
        throw $e;
    }
}

/**
 * Emails one HR notice. It goes through the portal's outbox, so a message
 * the mail server will not take right now is kept and sent again by itself.
 * Silent when HR email is off, mail is not configured, or there is no valid
 * address. $page is where the button in the message leads, after sign-in.
 */
function hr_email(int $org, string $to, string $title, string $body, string $name = '', ?int $personId = null,
                  string $page = 'hr.php?p=me'): void
{
    if (hr_setting('notify_email', $org) !== '1' || !function_exists('fabo_mail_configured') || !fabo_mail_configured()) {
        return;
    }
    $to = trim($to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return;
    }
    // A client company's mail landing in the main owner's inbox says which company, and links to it on the admin side.
    if (hr_owner_is_fallback($org) && $to === hr_owner_email($org)) {
        $title = hr_owner_subject($org, $title);
        $body  = 'Company: ' . hr_org_label($org) . "\n" . $body;
        if (!str_starts_with($page, 'admin/')) {
            $page = 'admin/' . $page;
        }
        $page .= (str_contains($page, '?') ? '&' : '?') . 'org=' . $org;
    }
    try {
        require_once __DIR__ . '/../outbox.php';
        require_once __DIR__ . '/../mailtpl.php';
        $url = rtrim(fabo_portal_url(), '/') . '/' . ltrim($page, '/');
        if (!str_starts_with($page, 'admin/')) {
            $url = rtrim(fabo_portal_url(), '/') . '/login.php?next=' . rawurlencode(ltrim($page, '/'));
        }
        $text = ($name !== '' ? 'Hello ' . $name . ",\n\n" : '') . $body . "\n\nOpen HR: " . $url . "\n";
        $html = fabo_mail_wrap(fabo_app_name(), $title,
            ($name !== '' ? fabo_mail_p('Hello ' . e($name) . ',') : '') . fabo_mail_p(nl2br(e($body)))
            . fabo_mail_own_link($url, 'Open HR'), $body);
        /* The outbox keeps only the newest waiting message of one kind per
           person (right for passwords). Each HR notice matters on its own,
           so each gets its own kind and none replaces another in the queue. */
        fabo_post_or_queue($to, $title, $text, $html, $personId, 'hr ' . substr(sha1($title . "\n" . $body . microtime()), 0, 12));
    } catch (Throwable $e) {
        error_log('FABO HR email failed: ' . $e->getMessage());
    }
}

/** Notifies the person(s) who can approve for an employee: their manager's account, and the admin. */
function hr_notify_approvers(int $empId, string $key, string $title, string $body, string $page): void
{
    $emp = fabo_one('SELECT manager_id FROM hr_employees WHERE id = ?', 'i', [$empId]);
    $mgr = $emp && $emp['manager_id']
         ? fabo_one('SELECT id, person_id FROM hr_employees WHERE id = ?', 'i', [(int) $emp['manager_id']]) : null;
    if ($mgr && $mgr['person_id']) {
        // In-app only: the owner's mailbox already gets the administrator's copy below.
        hr_notify_employee((int) $mgr['id'], $key . ':mgr', $title, $body, 'hr.php?p=' . $page, false);
    }
    hr_notify_admin($key, $title, $body, 'admin/hr.php?p=' . $page);
}

/* ---------------------------------------------------------------- roles */

/** Starting sets of permissions for a new role. A template only ticks boxes; it grants nothing by itself. */
function hr_role_templates(): array
{
    return [
        'self' => ['Employee self-service', 'self', 'Each employee sees and manages only their own HR information.', [
            'dashboard' => [], 'employees' => ['view', 'edit'], 'confidential' => ['view'], 'leave' => ['view', 'create', 'edit'],
            'attendance' => ['view', 'create'], 'payroll' => ['view'], 'holidays' => ['view'],
            'lifecycle' => ['view', 'create', 'edit'], 'performance' => ['view', 'edit'], 'training' => ['view', 'create'],
            'assets' => ['view'], 'claims' => ['view', 'create'], 'helpdesk' => ['view', 'create']]],
        'manager' => ['Manager', 'team', 'Sees their team; approves their leave, corrections, claims and resignations; reviews their performance.', [
            'dashboard' => ['view'], 'employees' => ['view'], 'leave' => ['view', 'create', 'edit', 'approve', 'export'],
            'attendance' => ['view', 'create', 'approve', 'export'], 'holidays' => ['view'], 'reports' => ['view', 'export'], 'payroll' => ['view'],
            'recruitment' => ['view', 'edit'], 'lifecycle' => ['view', 'create', 'edit', 'approve'], 'performance' => ['view', 'create', 'edit', 'approve'],
            'training' => ['view', 'create'], 'assets' => ['view'], 'claims' => ['view', 'create', 'approve'], 'helpdesk' => ['view', 'create']]],
        'hr' => ['HR staff', 'org', 'Runs employees, hiring, onboarding and exits, leave, attendance, training, assets and the helpdesk. No payroll.', [
            'dashboard' => ['view'], 'employees' => ['view', 'create', 'edit', 'delete', 'export'], 'confidential' => ['view'],
            'leave' => ['view', 'create', 'edit', 'approve', 'delete', 'export', 'configure'], 'attendance' => ['view', 'create', 'edit', 'approve', 'export', 'configure'],
            'holidays' => ['view', 'create', 'edit', 'delete', 'export'], 'masters' => ['view', 'create', 'edit'], 'schedules' => ['view', 'create', 'edit'],
            'sheets' => ['view'], 'reports' => ['view', 'export'],
            'recruitment' => ['view', 'create', 'edit', 'delete', 'export'], 'lifecycle' => ['view', 'create', 'edit', 'approve', 'configure'],
            'performance' => ['view', 'create', 'edit', 'approve', 'configure'], 'training' => ['view', 'create', 'edit', 'delete', 'export'],
            'assets' => ['view', 'create', 'edit', 'delete', 'export'], 'claims' => ['view', 'create', 'approve', 'export'],
            'helpdesk' => ['view', 'create', 'edit', 'delete']]],
        'payroll' => ['Payroll officer', 'org', 'Prepares payroll, salary structures and claim payouts. Approval stays with the administrator unless ticked.', [
            'dashboard' => ['view'], 'employees' => ['view'], 'confidential' => ['view', 'edit'], 'payroll' => ['view', 'process_payroll', 'export'],
            'reports' => ['view', 'export'], 'holidays' => ['view'], 'claims' => ['view', 'edit', 'export']]],
    ];
}

/** The role made from a template in this organisation, created the first time it is asked for. */
function hr_role_from_template(string $key, ?string $name = null): int
{
    $tpl = hr_role_templates()[$key] ?? null;
    if (!$tpl) {
        throw new InvalidArgumentException('Unknown role template.');
    }
    $name ??= $tpl[0];
    $have = fabo_one('SELECT id FROM hr_roles WHERE org_id = ? AND name = ?', 'is', [hr_org(), $name]);
    if ($have) {
        return (int) $have['id'];
    }
    $scope = $tpl[1];
    $id = fabo_exec('INSERT INTO hr_roles (org_id, name, description, scope, created_by) VALUES (?,?,?,?,?)', 'issss',
                    [hr_org(), $name, $tpl[2], $scope, hr_actor()]);
    foreach ($tpl[3] as $m => $acts) {
        foreach ($acts as $act) {
            if ($scope === 'self' && !hr_self_allowed($m, $act)) { continue; }
            if ($scope !== 'org' && hr_org_only_module($m)) { continue; }
            fabo_exec('INSERT IGNORE INTO hr_role_perms (role_id, module, action) VALUES (?,?,?)', 'iss', [$id, $m, $act]);
        }
    }
    hr_audit('create', 'hr_role', $id, 'Role created from template: ' . $name . ' (' . $scope . ')');
    return $id;
}

/** The modules added after the first release; roles made before them do not include them. */
function hr_newer_modules(): array
{
    return ['recruitment', 'lifecycle', 'performance', 'training', 'assets', 'claims', 'helpdesk'];
}

/** Roles named after a template that hold none of the newer modules. */
function hr_roles_missing_new_modules(): array
{
    $names = array_map(static fn($t) => $t[0], hr_role_templates());
    $out = [];
    foreach (fabo_all('SELECT id, name FROM hr_roles WHERE org_id = ?', 'i', [hr_org()]) as $r) {
        if (!in_array($r['name'], $names, true)) {
            continue;
        }
        $in = implode(',', array_map(static fn($m) => "'" . $m . "'", hr_newer_modules()));
        if (!fabo_one('SELECT 1 FROM hr_role_perms WHERE role_id = ? AND module IN (' . $in . ') LIMIT 1', 'i', [(int) $r['id']])) {
            $out[(int) $r['id']] = $r['name'];
        }
    }
    return $out;
}

/**
 * Adds each template's permissions for the newer modules to the roles made
 * from it (matched by name). Only adds; nothing already ticked is removed.
 */
function hr_roles_add_new_modules(): int
{
    if (!hr_is_admin()) {
        fabo_deny('Only the administrator manages HR access.', 403);
    }
    $n = 0;
    foreach (hr_role_templates() as [$name, $scope, , $perms]) {
        $role = fabo_one('SELECT id, scope FROM hr_roles WHERE org_id = ? AND name = ?', 'is', [hr_org(), $name]);
        if (!$role) {
            continue;
        }
        foreach (hr_newer_modules() as $m) {
            foreach ($perms[$m] ?? [] as $act) {
                if ($role['scope'] === 'self' && !hr_self_allowed($m, $act)) {
                    continue;
                }
                $n += fabo_exec('INSERT IGNORE INTO hr_role_perms (role_id, module, action) VALUES (?,?,?)', 'iss', [(int) $role['id'], $m, $act]) ? 1 : 0;
            }
        }
        hr_audit('permissions', 'hr_role', (int) $role['id'], 'Newer modules added to role ' . $name);
    }
    return $n;
}

/** id => "Name — covers …" for every active role in this organisation. */
function hr_dashboard_role_choices(): array
{
    $out = [];
    foreach (fabo_all('SELECT id, name, scope FROM hr_roles WHERE org_id = ? AND active = 1 ORDER BY scope = "self" DESC, name', 'i', [hr_org()]) as $r) {
        $out[(int) $r['id']] = $r['name'] . ' — ' . ['self' => 'own record', 'team' => 'their team', 'org' => 'whole organisation'][$r['scope']];
    }
    return $out;
}

/* ------------------------------------------------------------ validation */

function hr_str($v, int $max = 255, bool $required = false, string $label = 'This field'): string
{
    $s = trim((string) ($v ?? ''));
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s) ?? '';
    if ($required && $s === '') {
        throw new InvalidArgumentException($label . ' is required.');
    }
    if (mb_strlen($s) > $max) {
        throw new InvalidArgumentException($label . ' must be ' . $max . ' characters or fewer.');
    }
    return $s;
}

/** A Y-m-d date or null; throws with the label when it is not a real date. */
function hr_date($v, bool $required = false, string $label = 'Date'): ?string
{
    $s = trim((string) ($v ?? ''));
    if ($s === '') {
        if ($required) {
            throw new InvalidArgumentException($label . ' is required.');
        }
        return null;
    }
    // Accept the forms people type and spreadsheets export.
    foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'd.m.Y', 'Y/m/d', 'd-M-Y', 'd M Y', 'j M Y', 'j-M-Y'] as $f) {
        $d = DateTime::createFromFormat('!' . $f, $s);
        if ($d && $d->format($f) === $s) {
            return $d->format('Y-m-d');
        }
    }
    $d = DateTime::createFromFormat('!Y-m-d', substr($s, 0, 10));
    if ($d && $d->format('Y-m-d') === substr($s, 0, 10)) {
        return $d->format('Y-m-d');
    }
    throw new InvalidArgumentException($label . ' is not a valid date. Use the date picker or YYYY-MM-DD.');
}

/** HH:MM (24h) or null. */
function hr_time($v, bool $required = false, string $label = 'Time'): ?string
{
    $s = trim((string) ($v ?? ''));
    if ($s === '') {
        if ($required) {
            throw new InvalidArgumentException($label . ' is required.');
        }
        return null;
    }
    if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?\s*([AaPp][Mm])?$/', $s, $m)) {
        $h = (int) $m[1];
        $i = (int) $m[2];
        if (!empty($m[3])) {
            $pm = strtolower($m[3]) === 'pm';
            if ($h < 1 || $h > 12) {
                throw new InvalidArgumentException($label . ' is not a valid time.');
            }
            $h = $h % 12 + ($pm ? 12 : 0);
        }
        if ($h <= 23 && $i <= 59) {
            return sprintf('%02d:%02d', $h, $i);
        }
    }
    throw new InvalidArgumentException($label . ' is not a valid time. Use HH:MM, for example 09:30.');
}

/** Like hr_time(), but keeps seconds when they were given: 'HH:MM' or 'HH:MM:SS'. */
function hr_time_secs($v, string $label = 'Time'): ?string
{
    $s = trim((string) ($v ?? ''));
    if (preg_match('/^(\d{1,2}):(\d{2}):(\d{2})$/', $s, $m) && (int) $m[1] < 24 && (int) $m[2] < 60 && (int) $m[3] < 60) {
        return sprintf('%02d:%02d:%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
    }
    return hr_time($s, false, $label);
}

/** A decimal as a string with fixed places, for exact storage. */
function hr_dec($v, int $dp = 2, bool $required = false, string $label = 'Amount', ?float $min = null, ?float $max = null): ?string
{
    $s = trim(str_replace([',', '₹', ' '], '', (string) ($v ?? '')));
    if ($s === '') {
        if ($required) {
            throw new InvalidArgumentException($label . ' is required.');
        }
        return null;
    }
    if (!preg_match('/^-?\d+(\.\d+)?$/', $s)) {
        throw new InvalidArgumentException($label . ' must be a number.');
    }
    if ($min !== null && (float) $s < $min) {
        throw new InvalidArgumentException($label . ' cannot be less than ' . $min . '.');
    }
    if ($max !== null && (float) $s > $max) {
        throw new InvalidArgumentException($label . ' cannot be more than ' . $max . '.');
    }
    return number_format(round((float) $s, $dp), $dp, '.', '');
}

/** An id that must belong to this org in $table, or null. */
function hr_ref_id($v, string $table, string $label, bool $required = false): ?int
{
    $id = (int) ($v ?? 0);
    if ($id <= 0) {
        if ($required) {
            throw new InvalidArgumentException($label . ' is required.');
        }
        return null;
    }
    $ok = fabo_one('SELECT id FROM `' . $table . '` WHERE id = ? AND org_id = ?', 'ii', [$id, hr_org()]);
    if (!$ok) {
        throw new InvalidArgumentException($label . ' is not one of the available choices.');
    }
    return $id;
}

/**
 * Runs $fn inside a transaction; rolls back on any throw. Re-entrant: called
 * inside another hr_tx() it joins the outer transaction, because a second
 * BEGIN would silently commit everything done so far.
 */
function hr_tx(callable $fn)
{
    static $depth = 0;
    if ($depth > 0) {
        $depth++;
        try {
            return $fn();
        } finally {
            $depth--;
        }
    }
    $db = fabo_db();
    $db->begin_transaction();
    $depth = 1;
    try {
        $out = $fn();
        $depth = 0;
        $db->commit();
        return $out;
    } catch (Throwable $e) {
        $depth = 0;
        $db->rollback();
        throw $e;
    }
}

/* --------------------------------------------------------------- dates */

function hr_weekday_names(): array
{
    return [0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'];
}

/**
 * A weekly-off pattern, as stored: comma-separated tokens. "0" is every
 * Sunday; "6#2" the second Saturday of the month; "6#2,6#4" second and fourth.
 */
function hr_parse_offs(?string $pattern): array
{
    $out = [];
    foreach (explode(',', (string) $pattern) as $tok) {
        $tok = trim($tok);
        if ($tok === '') {
            continue;
        }
        if (preg_match('/^([0-6])(?:#([1-5]))?$/', $tok, $m)) {
            $out[] = ['dow' => (int) $m[1], 'nth' => isset($m[2]) ? (int) $m[2] : 0];
        }
    }
    return $out;
}

function hr_is_off(array $offs, string $date): bool
{
    $ts  = strtotime($date);
    $dow = (int) date('w', $ts);
    $nth = intdiv((int) date('j', $ts) - 1, 7) + 1;
    foreach ($offs as $o) {
        if ($o['dow'] === $dow && ($o['nth'] === 0 || $o['nth'] === $nth)) {
            return true;
        }
    }
    return false;
}

function hr_offs_words(?string $pattern): string
{
    $names = hr_weekday_names();
    $ord   = [1 => '1st', 2 => '2nd', 3 => '3rd', 4 => '4th', 5 => '5th'];
    $words = [];
    foreach (hr_parse_offs($pattern) as $o) {
        $words[] = $o['nth'] ? $ord[$o['nth']] . ' ' . $names[$o['dow']] : 'Every ' . $names[$o['dow']];
    }
    return $words ? implode(', ', $words) : 'None';
}

/** Builds a stored pattern from the weekly-off form controls. */
function hr_offs_from_post(array $post, string $prefix = 'off'): string
{
    $tok = [];
    foreach (range(0, 6) as $d) {
        $mode = (string) ($post[$prefix . '_' . $d] ?? '');
        if ($mode === 'all') {
            $tok[] = (string) $d;
        } elseif ($mode !== '' && preg_match('/^[1-5](,[1-5])*$/', $mode)) {
            foreach (explode(',', $mode) as $n) {
                $tok[] = $d . '#' . $n;
            }
        }
    }
    return implode(',', $tok);
}

function hr_leave_year_of(string $date, ?int $org = null): int
{
    $start = max(1, min(12, (int) hr_setting('leave_year_start_month', $org)));
    $y = (int) substr($date, 0, 4);
    $m = (int) substr($date, 5, 2);
    return $m >= $start ? $y : $y - 1;
}

/** [first day, last day] of a leave year. */
function hr_leave_year_bounds(int $year, ?int $org = null): array
{
    $start = max(1, min(12, (int) hr_setting('leave_year_start_month', $org)));
    $from  = sprintf('%04d-%02d-01', $year, $start);
    $to    = date('Y-m-d', strtotime($from . ' +1 year -1 day'));
    return [$from, $to];
}

function hr_leave_year_label(int $year): string
{
    $start = (int) hr_setting('leave_year_start_month');
    return $start === 1 ? (string) $year : $year . '–' . substr((string) ($year + 1), 2);
}

function hr_fmt_date(?string $d): string
{
    if (!$d) {
        return '';
    }
    $ts = strtotime($d);
    return $ts ? date('d M Y', $ts) : '';
}

function hr_fmt_days($v): string
{
    $f = (float) $v;
    return rtrim(rtrim(number_format($f, 2, '.', ''), '0'), '.') ?: '0';
}

function hr_fmt_minutes(int $m): string
{
    if ($m <= 0) {
        return '0h';
    }
    return intdiv($m, 60) . 'h' . ($m % 60 ? ' ' . str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT) . 'm' : '');
}

/* ------------------------------------------------------------------ UI */

/** A link to an HR page. Works from admin/hr.php and from hr.php alike. */
function hr_url(string $page, array $q = []): string
{
    return 'hr.php?' . http_build_query(['p' => $page] + $q);
}

function hr_tone(string $status): string
{
    $s = strtolower($status);
    $map = [
        'approved' => 'green', 'present' => 'green', 'active' => 'green', 'finalized' => 'green', 'payslips' => 'green',
        'paid' => 'green', 'work from home' => 'green', 'holiday worked' => 'green', 'compensatory off earned' => 'green',
        'submitted' => 'amber', 'pending' => 'amber', 'calculated' => 'amber', 'reviewed' => 'amber', 'half day' => 'amber',
        'probation' => 'amber', 'on notice' => 'amber', 'late' => 'amber', 'missing check-in / check-out' => 'amber',
        'on leave' => 'blue', 'draft' => 'grey', 'weekly off' => 'grey', 'public holiday' => 'grey', 'cancelled' => 'grey',
        'inactive' => 'grey', 'resigned' => 'grey', 'on hold' => 'amber', 'not marked' => 'grey',
        'rejected' => 'red', 'absent' => 'red', 'terminated' => 'red',
        // Recruitment, lifecycle, performance, training, assets, claims, helpdesk
        'open' => 'green', 'closed' => 'grey', 'filled' => 'green', 'applied' => 'blue', 'screening' => 'amber', 'interview' => 'amber',
        'offer' => 'amber', 'hired' => 'green', 'withdrawn' => 'grey', 'scheduled' => 'blue', 'completed' => 'green', 'no show' => 'red',
        'done' => 'green', 'skipped' => 'grey', 'overdue' => 'red', 'in clearance' => 'amber', 'settled' => 'green', 'processing' => 'amber',
        'self assessment' => 'blue', 'manager review' => 'amber', 'planned' => 'blue', 'enrolled' => 'blue', 'in stock' => 'green',
        'assigned' => 'blue', 'in repair' => 'amber', 'lost' => 'red', 'retired' => 'grey', 'in progress' => 'amber',
        'waiting on employee' => 'amber', 'resolved' => 'green', 'urgent' => 'red', 'high' => 'amber', 'normal' => 'grey', 'low' => 'grey',
    ];
    return $map[$s] ?? 'grey';
}

function hr_badge(string $status, string $label = ''): string
{
    $tone = hr_tone($status);
    return '<span class="pill hr-pill ' . e($tone) . '"><i class="dot"></i>' . e($label !== '' ? $label : ucfirst($status)) . '</span>';
}

function hr_flash_html(?array $flash): string
{
    if (!$flash) {
        return '';
    }
    return '<div class="flash ' . ($flash[0] === 'ok' ? 'ok' : 'err') . '" role="status">' . e($flash[1]) . '</div>';
}

/** <option> list from id => label. */
function hr_options(array $map, $selected, string $blank = ''): string
{
    $h = $blank !== '' ? '<option value="">' . e($blank) . '</option>' : '';
    foreach ($map as $k => $v) {
        $h .= '<option value="' . e($k) . '"' . ((string) $k === (string) $selected ? ' selected' : '') . '>' . e($v) . '</option>';
    }
    return $h;
}

/** Simple pagination: returns [offset, html]. */
function hr_pager(int $total, int $per, string $page, array $q): array
{
    $pages = max(1, (int) ceil($total / $per));
    $cur   = max(1, min($pages, (int) ($_GET['pg'] ?? 1)));
    $html  = '';
    if ($pages > 1) {
        $html .= '<nav class="hr-pager" aria-label="Pages">';
        $html .= '<span class="muted">' . $total . ' records · page ' . $cur . ' of ' . $pages . '</span>';
        if ($cur > 1) {
            $html .= '<a class="btn btn-sm" href="' . e(hr_url($page, $q + ['pg' => $cur - 1])) . '">&larr; Previous</a>';
        }
        if ($cur < $pages) {
            $html .= '<a class="btn btn-sm" href="' . e(hr_url($page, $q + ['pg' => $cur + 1])) . '">Next &rarr;</a>';
        }
        $html .= '</nav>';
    }
    return [($cur - 1) * $per, $html];
}

/** A sortable column header link. */
function hr_sort_th(string $label, string $key, string $page, array $q, string $cls = ''): string
{
    $cur = (string) ($_GET['sort'] ?? '');
    $dir = (string) ($_GET['dir'] ?? 'asc');
    $next = ($cur === $key && $dir === 'asc') ? 'desc' : 'asc';
    $arrow = $cur === $key ? ($dir === 'asc' ? ' ▲' : ' ▼') : '';
    unset($q['pg']);
    return '<th' . ($cls ? ' class="' . e($cls) . '"' : '') . ' aria-sort="'
         . ($cur === $key ? ($dir === 'asc' ? 'ascending' : 'descending') : 'none') . '"><a class="hr-sort" href="'
         . e(hr_url($page, $q + ['sort' => $key, 'dir' => $next])) . '">' . e($label) . $arrow . '</a></th>';
}

function hr_tile(string $label, string $value, string $tone = '', string $foot = '', string $href = ''): string
{
    $tag = $href !== '' ? 'a' : 'div';
    $txt = !preg_match('/^[\d.,\s₹%\-]+$/u', strip_tags($value)) ? ' txt' : '';
    return '<' . $tag . ' class="tile hr-tile' . $txt . ($tone ? ' ' . e($tone) : '') . '"'
         . ($href !== '' ? ' href="' . e($href) . '"' : '') . '>'
         . '<div class="k">' . e($label) . '</div><div class="v">' . $value . '</div>'
         . ($foot !== '' ? '<div class="tnote">' . $foot . '</div>' : '') . '</' . $tag . '>';
}

/** The month a page is looking at, from ?month=YYYY-MM, defaulting to now. */
function hr_month_param(string $key = 'month'): string
{
    $m = (string) ($_GET[$key] ?? '');
    return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) ? $m : date('Y-m');
}

/** Serves a CSV download with a formula-injection guard on every text cell. */
function hr_csv_download(string $filename, array $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    foreach ($rows as $r) {
        fputcsv($out, array_map(static function ($c) {
            if (is_array($c)) {
                $c = reset($c);
            }
            return is_string($c) ? FaboXlsx::sanitiseText($c) : $c;
        }, $r), ',', '"', '');
    }
    fclose($out);
    exit;
}

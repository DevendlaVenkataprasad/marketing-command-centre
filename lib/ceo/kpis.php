<?php
/**
 * CEO Dashboard — the KPI registry and the sources behind it.
 *
 * ceo_kpis() is the one definition of every metric: its name, module, how it
 * is worked out, what it may be cut by, who may see it and how fresh it stays.
 * Cards, charts, reports, exports and targets all read a KPI by key from here,
 * so a figure cannot mean one thing on a card and another in a report.
 *
 * Each module has one source, ceo_src_<module>($org, $f), which is read-only:
 * it runs SELECTs on the module's own tables and never calls a module's
 * installer. It returns key => number, or [n, d] for a ratio, so spokes can be
 * added up properly. A key a source leaves out when asked for one spoke is one
 * that module does not record by spoke.
 *
 * Counting once. A startup is the row in the incubation registry, however many
 * applications it has. Co-working memberships are their own figure, not added
 * to startups. Marketing work is counted from the Master Activity Log, which
 * every marketing sheet already feeds, and never from those sheets as well.
 * An HR expense claim that Finance mirrors counts as HR's approval only.
 */

require_once __DIR__ . '/core.php';

const CEO_TTL = ['fast' => 60, 'standard' => 300, 'slow' => 900];

function ceo_kpis(): array
{
    /* key => [label, module, unit, kind, by spoke, sensitive, better, refresh, link, calculation, source, target periods] */
    $rows = [
        'inc_active'      => ['Active startups', 'inc', 'count', 'stock', true, null, 'up', 'standard', ['p' => 'applications'],
                              'Distinct startups in the registry with an application in a stage that is not final. Confirmed duplicates excluded.', 'inc_startups, inc_applications, inc_stages', 'mqy'],
        'inc_new'         => ['New startup applications', 'inc', 'count', 'flow', true, null, 'up', 'fast', ['p' => 'applications'],
                              'Applications submitted in the period, excluding confirmed duplicates.', 'inc_applications', 'wmqy'],
        'inc_incubating'  => ['Startups under incubation', 'inc', 'count', 'stock', true, null, 'up', 'standard', ['p' => 'workspaces'],
                              'Distinct startups with an open incubation record.', 'inc_workspaces', 'mqy'],
        'inc_registrations' => ['Programme and event registrations', 'inc', 'count', 'flow', true, null, 'up', 'fast', ['p' => 'applications'],
                              'Applications filed on event or programme forms in the period.', 'inc_applications (purpose event, program)', 'wmqy'],
        'inc_delayed'     => ['Delayed incubation milestones', 'inc', 'count', 'stock', true, null, 'down', 'standard', ['p' => 'workspaces'],
                              'Open incubation records past their target date.', 'inc_workspaces', 'mq'],

        'mk_programs'     => ['Active programmes', 'marketing', 'count', 'stock', true, null, 'up', 'standard', ['sheet' => 'events'],
                              'Events not Completed, Cancelled or Postponed that have not ended.', 'events', 'mq'],
        'mk_events'       => ['Events conducted', 'marketing', 'count', 'flow', true, null, 'up', 'standard', ['sheet' => 'events'],
                              'Events marked Completed that ended in the period.', 'events', 'mqy'],
        'mk_attendance'   => ['Event attendance', 'marketing', 'count', 'flow', true, null, 'up', 'standard', ['sheet' => 'events'],
                              'Actual attendees recorded on events completed in the period.', 'events.actual_attendees', 'mqy'],
        'mk_leads'        => ['Leads from events', 'marketing', 'count', 'flow', true, null, 'up', 'standard', ['sheet' => 'events'],
                              'Leads generated recorded on events completed in the period.', 'events.leads_generated', 'mqy'],
        'mk_reports_due'  => ['Reports pending submission', 'marketing', 'count', 'stock', true, null, 'down', 'standard', ['sheet' => 'events'],
                              'Event results owing: due, overdue or started and not submitted.', 'events, event_results', 'wm'],
        'mk_tasks_done'   => ['Marketing activities completed', 'marketing', 'count', 'flow', false, null, 'up', 'standard', ['sheet' => 'master'],
                              'Master Activity Log rows with a completion date in the period.', 'master_log', 'wmq'],
        'mk_requisitions' => ['Open requisitions', 'marketing', 'count', 'stock', true, null, 'down', 'standard', ['sheet' => 'requisitions'],
                              'Requisitions not Delivered, Cancelled or Rejected.', 'requisitions', 'w'],
        'mk_spend'        => ['Event spend', 'marketing', 'money', 'flow', true, 'finance', 'neutral', 'slow', ['sheet' => 'events'],
                              'Budget spent on events that started in the period.', 'events.budget_spent', 'mqy'],

        'hr_active'       => ['Active employees', 'hr', 'count', 'stock', true, null, 'neutral', 'standard', ['p' => 'employees'],
                              'Employees with status active, probation or on notice.', 'hr_employees', 'qy'],
        'hr_pending'      => ['Pending HR requests', 'hr', 'count', 'stock', true, null, 'down', 'fast', ['p' => 'leave'],
                              'Leave awaiting a decision, expense claims submitted and HR tickets not resolved.', 'hr_leave_requests, hr_claims, hr_tickets', 'w'],
        'hr_positions'    => ['Open positions', 'hr', 'count', 'stock', false, null, 'neutral', 'standard', ['p' => 'recruitment'],
                              'Sum of positions on openings with status open.', 'hr_job_openings', 'mq'],
        'hr_joiners'      => ['New joiners', 'hr', 'count', 'flow', true, null, 'neutral', 'standard', ['p' => 'employees'],
                              'Employees whose date of joining falls in the period.', 'hr_employees', 'mqy'],
        'hr_attendance'   => ['Attendance rate', 'hr', 'pct', 'flow', true, null, 'up', 'slow', ['p' => 'attendance'],
                              'Attendance days marked present, as a share of days marked present or absent.', 'hr_attendance', 'wm'],
        'hr_payroll'      => ['Payroll cost', 'hr', 'money', 'flow', false, 'salary', 'neutral', 'slow', ['p' => 'payroll'],
                              'Net pay on payroll runs whose period ends in the reporting period and that are finalised.', 'hr_payroll_runs, hr_payroll_items', 'mqy'],

        'it_open'         => ['Open IT tickets', 'it', 'count', 'stock', false, null, 'down', 'fast', ['p' => 'tickets'],
                              'Tickets new, in progress or waiting.', 'it_tickets', 'dw'],
        'it_resolved'     => ['IT tickets resolved', 'it', 'count', 'flow', false, null, 'up', 'fast', ['p' => 'tickets'],
                              'Tickets resolved in the period.', 'it_tickets', 'wm'],
        'it_sla'          => ['IT tickets resolved on time', 'it', 'pct', 'flow', false, null, 'up', 'standard', ['p' => 'tickets'],
                              'Of tickets resolved in the period with a due time, the share resolved by it.', 'it_tickets', 'wm'],
        'it_assigned'     => ['IT assets assigned', 'it', 'count', 'stock', false, null, 'neutral', 'standard', ['p' => 'assets'],
                              'Assets with status assigned.', 'it_assets', 'q'],
        'it_available'    => ['IT assets available', 'it', 'count', 'stock', false, null, 'neutral', 'standard', ['p' => 'assets'],
                              'Assets in stock.', 'it_assets', 'q'],
        'it_renewals'     => ['IT renewals due in 30 days', 'it', 'count', 'stock', false, null, 'down', 'slow', ['p' => 'renewals'],
                              'Active renewals dated within the next 30 days, including any past due.', 'it_renewals', 'm'],

        'fa_open'         => ['Open facilities requests', 'fa', 'count', 'stock', false, null, 'down', 'fast', ['p' => 'tickets'],
                              'Facilities requests new, in progress or waiting.', 'fa_tickets', 'dw'],
        'fa_resolved'     => ['Facilities requests resolved', 'fa', 'count', 'flow', false, null, 'up', 'fast', ['p' => 'tickets'],
                              'Requests resolved in the period.', 'fa_tickets', 'wm'],
        'fa_visitors'     => ['Visitors', 'fa', 'count', 'flow', false, null, 'neutral', 'standard', ['p' => 'visitors'],
                              'Visitor passes opened in the period.', 'fa_visitors', 'wm'],
        'fa_incidents'    => ['Open incidents', 'fa', 'count', 'stock', false, null, 'down', 'standard', ['p' => 'registers'],
                              'Incident register entries still open.', 'fa_registers', 'm'],
        'fa_lowstock'     => ['Supplies below reorder level', 'fa', 'count', 'stock', false, null, 'down', 'slow', ['p' => 'stock'],
                              'Active supplies at or below their reorder level.', 'fa_stock', 'm'],

        'cw_members'      => ['Active co-working memberships', 'cw', 'count', 'stock', true, null, 'up', 'standard', ['p' => 'overview'],
                              'Startups on the registers holding at least one desk, cabin or open space.', 'cw_startups, cw_spaces', 'mq'],
        'cw_occupancy'    => ['Desk occupancy', 'cw', 'pct', 'stock', true, null, 'up', 'standard', ['p' => 'overview'],
                              'Desks taken as a share of all desks, by the co-working model.', 'cw_spaces', 'mq'],
        'cw_cabins'       => ['Cabin occupancy', 'cw', 'pct', 'stock', true, null, 'up', 'standard', ['p' => 'overview'],
                              'Cabins taken as a share of all cabins.', 'cw_spaces', 'mq'],
        'cw_waiting'      => ['Waiting-list applicants', 'cw', 'count', 'stock', true, null, 'neutral', 'standard', ['p' => 'overview'],
                              'Applicants open in the pipeline.', 'cw_waiting', 'm'],
        'cw_renewals'     => ['Agreements expired or due in 30 days', 'cw', 'count', 'stock', true, null, 'down', 'standard', ['p' => 'overview'],
                              'Agreements expired or ending in 30 days.', 'cw_spaces', 'm'],
        'cw_dues'         => ['Co-working dues', 'cw', 'money', 'stock', true, 'finance', 'down', 'standard', ['p' => 'overview'],
                              'Dues outstanding on the startup registers.', 'cw_startups', 'm'],

        'ds_pending'      => ['Pending digital signatures', 'esign', 'count', 'stock', true, null, 'down', 'fast', ['p' => 'documents'],
                              'Signature requests waiting on a signer now.', 'ds_signature_requests', 'dw'],
        'ds_signed'       => ['Documents fully signed', 'esign', 'count', 'flow', true, null, 'up', 'fast', ['p' => 'documents', 'status' => 'signed'],
                              'Documents completed in the period.', 'ds_documents', 'wm'],
        'ds_overdue'      => ['Overdue signatures', 'esign', 'count', 'stock', true, null, 'down', 'fast', ['p' => 'documents'],
                              'Pending signature requests past their due time.', 'ds_signature_requests', 'w'],

        'fin_approvals'   => ['Finance approvals waiting', 'fin', 'count', 'stock', false, null, 'down', 'fast', ['p' => 'approvals'],
                              'Approval steps pending, on one approval engine for all finance documents.', 'fp_wf_steps', 'w'],
        'fin_budget'      => ['Budget utilisation', 'fin', 'pct', 'stock', false, 'finance', 'neutral', 'slow', ['p' => 'budgets'],
                              'Actual plus committed spend against approved budgets for the financial year, by Finance\'s own budget-versus-actual.', 'fp_budgets, fp_budget_lines, fp_journal_lines', 'mq'],
        'fin_expenses'    => ['Expenses booked', 'fin', 'money', 'flow', false, 'finance', 'neutral', 'slow', ['p' => 'bills'],
                              'Approved vendor bills by bill date, plus approved expense claims, in base currency.', 'fp_bills, fp_claims', 'mqy'],
        'fin_payments'    => ['Payments made', 'fin', 'money', 'flow', false, 'finance', 'neutral', 'slow', ['p' => 'payments'],
                              'Completed payments by payment date, in base currency.', 'fp_payments', 'mqy'],
        'fin_receivables' => ['Receivables outstanding', 'fin', 'money', 'stock', false, 'finance', 'down', 'slow', ['p' => 'invoices'],
                              'Issued invoices less what has been received, in base currency.', 'fp_invoices', 'mq'],
        'fin_overdue_ap'  => ['Bills overdue', 'fin', 'count', 'stock', false, null, 'down', 'standard', ['p' => 'bills'],
                              'Approved bills unpaid past their due date.', 'fp_bills', 'w'],
    ];
    $out = [];
    foreach ($rows as $k => [$label, $module, $unit, $kind, $spoke, $sens, $better, $refresh, $link, $calc, $src, $periods]) {
        $out[$k] = compact('label', 'module', 'unit', 'kind', 'spoke', 'better', 'refresh', 'link', 'calc') + [
            'key' => $k, 'sensitive' => $sens, 'source' => $src,
            'periods' => array_values(array_map(static fn($c) => ['d' => 'daily', 'w' => 'weekly', 'm' => 'monthly', 'q' => 'quarterly', 'y' => 'annual'][$c], str_split($periods))),
        ];
    }
    return $out;
}

/**
 * Figures made from the others, counted over only the modules the person may
 * see. key => [label, parts, calculation].
 */
function ceo_rollups(): array
{
    return [
        'x_approvals' => ['Pending approvals', ['fin' => '_appr', 'hr' => '_appr', 'marketing' => '_appr'],
                          'Finance approval steps, HR leave and claims awaiting a decision, and marketing sign-offs requested. A claim mirrored into Finance counts once.'],
        'x_overdue'   => ['Overdue tasks', ['inc' => '_overdue', 'marketing' => '_overdue', 'hr' => '_overdue', 'it' => '_overdue', 'fa' => '_overdue', 'esign' => '_overdue', 'fin' => '_overdue'],
                          'Open items past their due date: incubation tasks, marketing activities, HR onboarding and exit tasks, IT and facilities tickets, signatures and finance approvals past escalation.'],
    ];
}

/** The cards shown when nobody has chosen. In the order of the brief. */
function ceo_default_cards(): array
{
    return ['inc_active', 'inc_new', 'inc_incubating', 'mk_programs', 'mk_events', 'inc_registrations', 'mk_attendance', 'hr_active', 'hr_pending', 'it_open', 'it_assigned', 'it_available',
            'cw_members', 'cw_occupancy', 'ds_pending', 'x_approvals', 'fin_budget', 'fin_expenses', 'fin_payments', 'x_overdue', 'mk_reports_due'];
}

/* ================================================================ helpers */

/** Rows of one column whose spelling is this spoke: for matching free text. */
function ceo_raw_for_spoke(string $table, string $col, string $spoke, string $where = '', string $types = '', array $params = []): array
{
    $out = [];
    foreach (fabo_all('SELECT DISTINCT `' . $col . '` v FROM `' . $table . '` WHERE `' . $col . '` IS NOT NULL AND `' . $col . "` <> ''" . $where, $types, $params) as $r) {
        if (ceo_spoke_key((string) $r['v']) === $spoke) {
            $out[] = (string) $r['v'];
        }
    }
    return $out;
}

/** ' AND col IN (…)' with its binds; matches nothing when there is nothing to match. */
function ceo_in(string $col, array $vals, string $type): array
{
    if (!$vals) {
        return [' AND 1 = 0', '', []];
    }
    return [' AND ' . $col . ' IN (' . implode(',', array_fill(0, count($vals), '?')) . ')', str_repeat($type, count($vals)), array_values($vals)];
}

function ceo_count(string $sql, string $types = '', array $params = []): int
{
    return (int) (fabo_one($sql, $types, $params)['n'] ?? 0);
}

function ceo_sum(string $sql, string $types = '', array $params = []): float
{
    return (float) (fabo_one($sql, $types, $params)['n'] ?? 0);
}

function ceo_day_from(array $f): string
{
    return $f['from'] . ' 00:00:00';
}

function ceo_day_to(array $f): string
{
    return $f['to'] . ' 23:59:59';
}

function ceo_today(): string
{
    return function_exists('fabo_today') ? fabo_today() : date('Y-m-d');
}

/* ================================================================ sources */

function ceo_src_inc(int $org, array $f): array
{
    $A = ceo_t('inc_applications', $org);
    $S = ceo_t('inc_startups', $org);
    $W = ceo_t('inc_workspaces', $org);
    $G = ceo_t('inc_stages', $org);
    $T = ceo_t('inc_tasks', $org);
    [$sw, $st, $sp] = ['', '', []];
    if ($f['spoke'] !== '') {
        $ids = [];
        foreach (fabo_all('SELECT id, name FROM `' . ceo_t('inc_masters', $org) . '` WHERE kind = "spoke"') as $m) {
            if (ceo_spoke_key((string) $m['name']) === $f['spoke']) {
                $ids[] = (int) $m['id'];
            }
        }
        [$sw, $st, $sp] = ceo_in('COALESCE(a.spoke_id, s.spoke_id)', $ids, 'i');
    }
    $from = ceo_day_from($f);
    $to = ceo_day_to($f);
    $base = "FROM `$A` a LEFT JOIN `$S` s ON s.id = a.startup_id WHERE a.dup_status <> 'confirmed'";
    $today = ceo_today();
    return [
        'inc_new' => ceo_count("SELECT COUNT(*) n $base AND a.submitted_at BETWEEN ? AND ?$sw", 'ss' . $st, array_merge([$from, $to], $sp)),
        'inc_registrations' => ceo_count("SELECT COUNT(*) n $base AND a.purpose IN ('event','program') AND a.submitted_at BETWEEN ? AND ?$sw", 'ss' . $st, array_merge([$from, $to], $sp)),
        'inc_active' => ceo_count("SELECT COUNT(DISTINCT s.id) n FROM `$A` a JOIN `$S` s ON s.id = a.startup_id JOIN `$G` g ON g.id = a.stage_id
                                    WHERE a.dup_status <> 'confirmed' AND g.is_terminal = 0$sw", $st, $sp),
        'inc_incubating' => ceo_count("SELECT COUNT(DISTINCT COALESCE(CONCAT('s', w.startup_id), CONCAT('a', w.application_id))) n
                                         FROM `$W` w JOIN `$A` a ON a.id = w.application_id LEFT JOIN `$S` s ON s.id = a.startup_id
                                        WHERE w.closed_at IS NULL$sw", $st, $sp),
        'inc_delayed' => ceo_count("SELECT COUNT(*) n FROM `$W` w JOIN `$A` a ON a.id = w.application_id LEFT JOIN `$S` s ON s.id = a.startup_id
                                     WHERE w.closed_at IS NULL AND w.target_date < ?$sw", 's' . $st, array_merge([$today], $sp)),
        '_overdue' => ceo_count("SELECT COUNT(*) n FROM `$T` t LEFT JOIN `$A` a ON a.id = t.application_id LEFT JOIN `$S` s ON s.id = a.startup_id
                                  WHERE t.status = 'open' AND t.due_date < ?$sw", 's' . $st, array_merge([$today], $sp)),
    ];
}

function ceo_src_marketing(int $org, array $f): array
{
    $spoke = $f['spoke'];
    [$ew, $et, $ep] = ['', '', []];
    [$rw, $rt, $rp] = ['', '', []];
    if ($spoke !== '') {
        [$ew, $et, $ep] = ceo_in('hub_spoke', ceo_raw_for_spoke('events', 'hub_spoke', $spoke, ' AND COALESCE(client_id, 0) = ?', 'i', [$org]), 's');
        [$rw, $rt, $rp] = ceo_in('spoke', ceo_raw_for_spoke('requisitions', 'spoke', $spoke, ' AND COALESCE(client_id, 0) = ?', 'i', [$org]), 's');
    }
    // A department's rows still being filled in are drafts, exactly as on the Events sheet.
    require_once __DIR__ . '/../departments.php';
    $live = fabo_dept_has_submitted_column() ? ' AND (department_id IS NULL OR dept_submitted_at IS NOT NULL)' : '';
    $today = ceo_today();
    $ev = "FROM events WHERE COALESCE(client_id, 0) = ?$live$ew";
    $done = " AND status = 'Completed' AND COALESCE(end_date, start_date) BETWEEN ? AND ?";
    $bind = array_merge([$org], $ep, [$f['from'], $f['to']]);
    $types = 'i' . $et . 'ss';
    $out = [
        'mk_programs' => ceo_count("SELECT COUNT(*) n $ev AND COALESCE(status, '') NOT IN ('Completed','Cancelled','Postponed') AND COALESCE(end_date, start_date) >= ?",
                                   'i' . $et . 's', array_merge([$org], $ep, [$today])),
        'mk_events' => ceo_count("SELECT COUNT(*) n $ev$done", $types, $bind),
        'mk_attendance' => (int) ceo_sum("SELECT SUM(actual_attendees) n $ev$done", $types, $bind),
        'mk_leads' => (int) ceo_sum("SELECT SUM(leads_generated) n $ev$done", $types, $bind),
        'mk_spend' => ceo_sum("SELECT SUM(budget_spent) n $ev AND start_date BETWEEN ? AND ?", $types, $bind),
        'mk_requisitions' => ceo_count("SELECT COUNT(*) n FROM requisitions WHERE COALESCE(client_id, 0) = ?$rw AND COALESCE(status, '') NOT IN ('Delivered','Cancelled','Rejected')", 'i' . $rt, array_merge([$org], $rp)),
    ];
    $out['mk_reports_due'] = ceo_owing_results($org, $f['spoke'])['owing'];
    if ($spoke === '') {
        $out['mk_tasks_done'] = ceo_count('SELECT COUNT(*) n FROM master_log WHERE COALESCE(client_id, 0) = ? AND completion_date BETWEEN ? AND ?', 'iss', [$org, $f['from'], $f['to']]);
        $out['_overdue'] = ceo_count("SELECT COUNT(*) n FROM master_log WHERE COALESCE(client_id, 0) = ? AND due_date < ? AND completion_date IS NULL
                                       AND COALESCE(status, '') NOT IN ('Completed','Cancelled','Published/Live')", 'is', [$org, $today]);
        $out['_appr'] = ceo_count('SELECT COUNT(*) n FROM approval_requests WHERE client_id = ? AND responded_at IS NULL AND cancelled_at IS NULL', 'i', [$org]);
    }
    return $out;
}

/** Event results: how many are owing and how many have come in, by the same rule the Events sheet shows. */
function ceo_owing_results(int $org, string $spoke = ''): array
{
    require_once __DIR__ . '/../evresults.php';
    fabo_evres_install();
    $was = fabo_view_owner();
    fabo_view_owner($org);
    try {
        $rows = fabo_all('SELECT * FROM events WHERE COALESCE(client_id, 0) = ? AND COALESCE(end_date, start_date) <= ? AND COALESCE(status, "") NOT IN ("Cancelled","Postponed")', 'is', [$org, ceo_today()]);
        $rows = array_values(array_filter($rows, static fn($r) => $spoke === '' || ceo_spoke_key((string) ($r['hub_spoke'] ?? '')) === $spoke));
        $rows = fabo_evres_fill($rows);
    } finally {
        fabo_view_owner($was);
    }
    $owing = $in = 0;
    $list = [];
    foreach ($rows as $r) {
        $k = (string) ($r['_results']['key'] ?? '');
        if (fabo_evres_owing($r)) {
            $owing++;
            $list[] = $r;
        } elseif ($k === 'submitted') {
            $in++;
        }
    }
    return ['owing' => $owing, 'submitted' => $in, 'rows' => $list];
}

/** Employee ids filter for HR: by spoke through the location master, by department directly. */
function ceo_hr_scope(int $org, array $f): array
{
    $w = '';
    $t = '';
    $p = [];
    if ($f['spoke'] !== '') {
        $ids = [];
        foreach (fabo_all('SELECT id, name FROM hr_masters WHERE org_id = ? AND kind = "location"', 'i', [$org]) as $m) {
            if (ceo_spoke_key((string) $m['name']) === $f['spoke']) {
                $ids[] = (int) $m['id'];
            }
        }
        [$w, $t, $p] = ceo_in('e.location_id', $ids, 'i');
    }
    if (!empty($f['dept'])) {
        $w .= ' AND e.department_id = ?';
        $t .= 'i';
        $p[] = (int) $f['dept'];
    }
    return [$w, $t, $p];
}

function ceo_src_hr(int $org, array $f): array
{
    [$w, $t, $p] = ceo_hr_scope($org, $f);
    $emp = 'JOIN hr_employees e ON e.id = x.employee_id';
    $leave = ceo_count("SELECT COUNT(*) n FROM hr_leave_requests x $emp WHERE x.org_id = ? AND x.status = 'submitted'$w", 'i' . $t, array_merge([$org], $p));
    $claims = ceo_count("SELECT COUNT(*) n FROM hr_claims x $emp WHERE x.org_id = ? AND x.status = 'submitted'$w", 'i' . $t, array_merge([$org], $p));
    $tickets = ceo_count("SELECT COUNT(*) n FROM hr_tickets x $emp WHERE x.org_id = ? AND x.status NOT IN ('resolved','closed')$w", 'i' . $t, array_merge([$org], $p));
    $att = fabo_one("SELECT SUM(x.status IN ('Present','Half Day','Work From Home','Holiday Worked')) a, SUM(x.status IN ('Present','Half Day','Work From Home','Holiday Worked','Absent')) b
                       FROM hr_attendance x $emp WHERE x.org_id = ? AND x.work_date BETWEEN ? AND ?$w", 'iss' . $t, array_merge([$org, $f['from'], $f['to']], $p));
    $out = [
        'hr_active' => ceo_count("SELECT COUNT(*) n FROM hr_employees e WHERE e.org_id = ? AND e.status IN ('active','probation','notice')$w", 'i' . $t, array_merge([$org], $p)),
        'hr_pending' => $leave + $claims + $tickets,
        'hr_joiners' => ceo_count("SELECT COUNT(*) n FROM hr_employees e WHERE e.org_id = ? AND e.date_of_joining BETWEEN ? AND ?$w", 'iss' . $t, array_merge([$org, $f['from'], $f['to']], $p)),
        'hr_attendance' => [(float) ($att['a'] ?? 0), (float) ($att['b'] ?? 0)],
        '_appr' => $leave + $claims,
        '_overdue' => ceo_count("SELECT COUNT(*) n FROM hr_lifecycle_tasks x $emp WHERE x.org_id = ? AND x.status = 'pending' AND x.due_date < ?$w", 'is' . $t, array_merge([$org, ceo_today()], $p)),
    ];
    if ($f['spoke'] === '' && empty($f['dept'])) {
        $out['hr_positions'] = (int) ceo_sum("SELECT SUM(positions) n FROM hr_job_openings WHERE org_id = ? AND status = 'open'", 'i', [$org]);
        $out['hr_payroll'] = ceo_sum("SELECT SUM(i.net) n FROM hr_payroll_runs r JOIN hr_payroll_items i ON i.run_id = r.id
                                       WHERE r.org_id = ? AND r.status IN ('finalized','payslips') AND r.period_end BETWEEN ? AND ?", 'iss', [$org, $f['from'], $f['to']]);
    }
    return $out;
}

/** What IT and Facilities have in common: one table layout, two desks. */
function ceo_desk_common(string $desk, int $org, array $f): array
{
    $T = ceo_t($desk . '_tickets', $org);
    $from = ceo_day_from($f);
    $to = ceo_day_to($f);
    return [
        $desk . '_open' => ceo_count("SELECT COUNT(*) n FROM `$T` WHERE status IN ('new','in_progress','waiting')"),
        $desk . '_resolved' => ceo_count("SELECT COUNT(*) n FROM `$T` WHERE resolved_at BETWEEN ? AND ?", 'ss', [$from, $to]),
        '_overdue' => ceo_count("SELECT COUNT(*) n FROM `$T` WHERE status IN ('new','in_progress','waiting') AND due_at < NOW()"),
    ];
}

function ceo_src_it(int $org, array $f): array
{
    if ($f['spoke'] !== '') {
        return [];
    }
    $T = ceo_t('it_tickets', $org);
    $sla = fabo_one("SELECT SUM(resolved_at <= due_at) a, COUNT(*) b FROM `$T` WHERE due_at IS NOT NULL AND resolved_at BETWEEN ? AND ?", 'ss', [ceo_day_from($f), ceo_day_to($f)]);
    $A = ceo_t('it_assets', $org);
    return ceo_desk_common('it', $org, $f) + [
        'it_sla' => [(float) ($sla['a'] ?? 0), (float) ($sla['b'] ?? 0)],
        'it_assigned' => ceo_count("SELECT COUNT(*) n FROM `$A` WHERE status = 'assigned'"),
        'it_available' => ceo_count("SELECT COUNT(*) n FROM `$A` WHERE status = 'in_stock'"),
        'it_renewals' => ceo_count('SELECT COUNT(*) n FROM `' . ceo_t('it_renewals', $org) . "` WHERE status = 'active' AND renews_on <= DATE_ADD(?, INTERVAL 30 DAY)", 's', [ceo_today()]),
    ];
}

function ceo_src_fa(int $org, array $f): array
{
    if ($f['spoke'] !== '') {
        return [];
    }
    $V = ceo_t('fa_visitors', $org);
    $R = ceo_t('fa_registers', $org);
    $K = ceo_t('fa_stock', $org);
    $out = ceo_desk_common('fa', $org, $f);
    if (ceo_has_table($V)) {
        $out['fa_visitors'] = ceo_count("SELECT COUNT(*) n FROM `$V` WHERE in_at BETWEEN ? AND ?", 'ss', [ceo_day_from($f), ceo_day_to($f)]);
    }
    if (ceo_has_table($R)) {
        $out['fa_incidents'] = ceo_count("SELECT COUNT(*) n FROM `$R` WHERE type = 'incident' AND status = 'open'");
    }
    if (ceo_has_table($K)) {
        $out['fa_lowstock'] = ceo_count("SELECT COUNT(*) n FROM `$K` WHERE active = 1 AND reorder_level > 0 AND qty <= reorder_level");
    }
    return $out;
}

function ceo_src_cw(int $org, array $f): array
{
    require_once __DIR__ . '/../cw/core.php';
    $members = $dues = $wait = $ren = 0.0;
    $seen = [];
    $desk = [0.0, 0.0];
    $cabin = [0.0, 0.0];
    foreach (fabo_all('SELECT * FROM cw_centres WHERE org_id = ? ORDER BY sort, id', 'i', [$org]) as $c) {
        if ($f['spoke'] !== '' && ceo_spoke_key((string) $c['name']) !== $f['spoke']) {
            continue;
        }
        $m = cw_model($c);
        foreach ($m['spaces'] as $s) {
            if (($s['occupied_by'] ?? '') === 'Startup' && trim((string) ($s['occupant'] ?? '')) !== '') {
                $seen[(int) $c['id']][mb_strtolower(trim((string) $s['occupant']))] = true;
            }
        }
        $members += count($seen[(int) $c['id']] ?? []);
        $desk[0] += (float) $m['desks_taken'];
        $desk[1] += (float) $m['desks_total'];
        $cabin[0] += (float) $m['cabins_taken'];
        $cabin[1] += (float) $m['cabins_total'];
        $dues += (float) $m['dues'];
        $wait += (float) array_sum($m['pipeline']);
        $ren += (float) $m['renewals']['Expired'] + (float) $m['renewals']['Due in 30 days'];
    }
    return ['cw_members' => (int) $members, 'cw_occupancy' => $desk, 'cw_cabins' => $cabin, 'cw_waiting' => (int) $wait, 'cw_renewals' => (int) $ren, 'cw_dues' => $dues];
}

function ceo_src_esign(int $org, array $f): array
{
    [$w, $t, $p] = ['', '', []];
    if ($f['spoke'] !== '') {
        [$w, $t, $p] = ceo_in('d.unit', ceo_raw_for_spoke('ds_documents', 'unit', $f['spoke'], ' AND org_id = ?', 'i', [$org]), 's');
    }
    if (!empty($f['dept'])) {
        $w .= ' AND d.department_id = ?';
        $t .= 'i';
        $p[] = (int) $f['dept'];
    }
    $req = "FROM ds_signature_requests r JOIN ds_documents d ON d.id = r.doc_id WHERE r.org_id = ? AND r.status = 'pending'$w";
    $overdue = ceo_count("SELECT COUNT(*) n $req AND r.due_at < NOW()", 'i' . $t, array_merge([$org], $p));
    return [
        'ds_pending' => ceo_count("SELECT COUNT(*) n $req", 'i' . $t, array_merge([$org], $p)),
        'ds_overdue' => $overdue,
        'ds_signed' => ceo_count("SELECT COUNT(*) n FROM ds_documents d WHERE d.org_id = ? AND d.completed_at BETWEEN ? AND ?$w", 'iss' . $t, array_merge([$org, ceo_day_from($f), ceo_day_to($f)], $p)),
        '_overdue' => $overdue,
    ];
}

function ceo_src_fin(int $org, array $f): array
{
    if ($f['spoke'] !== '') {
        return [];
    }
    require_once __DIR__ . '/../fin/core.php';
    $W = ceo_t('fp_wf_steps', $org);
    $C = ceo_t('fp_claims', $org);
    $B = ceo_t('fp_bills', $org);
    $P = ceo_t('fp_payments', $org);
    $I = ceo_t('fp_invoices', $org);
    $today = ceo_today();
    // A claim that came across from HR is HR's to count: leave it out of the rollup, keep it on the Finance card.
    $steps = ceo_count("SELECT COUNT(*) n FROM `$W` WHERE status = 'pending'");
    $mirrored = ceo_count("SELECT COUNT(*) n FROM `$W` w JOIN `$C` c ON c.id = w.doc_id WHERE w.doc_type = 'claim' AND w.status = 'pending' AND c.hr_claim_id IS NOT NULL");
    $expenses = ceo_sum("SELECT SUM(total * fx_rate) n FROM `$B` WHERE status IN ('approved','partially_paid','paid') AND bill_date BETWEEN ? AND ?", 'ss', [$f['from'], $f['to']])
              + ceo_sum("SELECT SUM(approved_total) n FROM `$C` WHERE status IN ('approved','paid') AND updated_at BETWEEN ? AND ?", 'ss', [ceo_day_from($f), ceo_day_to($f)]);
    return [
        'fin_approvals' => $steps,
        'fin_budget' => ceo_budget_use($org),
        'fin_expenses' => $expenses,
        'fin_payments' => ceo_sum("SELECT SUM(amount * fx_rate) n FROM `$P` WHERE status = 'completed' AND pay_date BETWEEN ? AND ?", 'ss', [$f['from'], $f['to']]),
        'fin_receivables' => ceo_sum("SELECT SUM((total - received_amount) * fx_rate) n FROM `$I` WHERE status IN ('issued','partially_paid')"),
        'fin_overdue_ap' => ceo_count("SELECT COUNT(*) n FROM `$B` WHERE status IN ('approved','partially_paid') AND due_date < ?", 's', [$today]),
        '_appr' => $steps - $mirrored,
        '_overdue' => ceo_count("SELECT COUNT(*) n FROM `$W` WHERE status = 'pending' AND escalated_at IS NOT NULL"),
    ];
}

/** Budget use through Finance's own budget-versus-actual, so the two can never differ. [used, budget]. */
function ceo_budget_use(int $org): array
{
    require_once __DIR__ . '/../fin/app.php';
    $was = $GLOBALS['fp_org'] ?? null;
    fp_use($org);
    try {
        $used = $budget = 0.0;
        foreach (fp_budget_vs_actual(fp_fy_of(ceo_today())) as $l) {
            $budget += (float) $l['budget'];
            $used += (float) $l['actual'] + (float) $l['committed'];
        }
        return [$used, $budget];
    } finally {
        $GLOBALS['fp_org'] = $was;
    }
}

/* ================================================================ cache */

/** A value computed at most once per $ttl. Returns [value, computed_at]. Nothing past its time is ever returned. */
function ceo_cached(int $org, string $key, int $ttl, callable $make, bool $force = false): array
{
    $ck = sha1($key);
    if (!$force) {
        $r = fabo_one('SELECT payload, computed_at FROM ceo_cache WHERE org_id = ? AND ck = ? AND expires_at > NOW()', 'is', [$org, $ck]);
        if ($r) {
            return [json_decode((string) $r['payload'], true), (string) $r['computed_at']];
        }
    }
    $value = $make();
    $now = date('Y-m-d H:i:s');
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    if (strlen((string) $json) < 2000000) {
        fabo_exec('INSERT INTO ceo_cache (org_id, ck, payload, computed_at, expires_at) VALUES (?,?,?,NOW(),DATE_ADD(NOW(), INTERVAL ? SECOND))
                   ON DUPLICATE KEY UPDATE payload = VALUES(payload), computed_at = VALUES(computed_at), expires_at = VALUES(expires_at)', 'issi', [$org, $ck, $json, $ttl]);
    }
    return [$value, $now];
}

/** One module's figures for one cut. ['state', 'values', 'at']: a failed source says so; it never shows an old figure as current. */
function ceo_source(int $org, string $m, array $f, bool $force = false): array
{
    $state = ceo_module_state($org, $m);
    if ($state !== 'ok') {
        return ['state' => $state, 'values' => [], 'at' => null];
    }
    $refresh = [];
    foreach (ceo_kpis() as $k) {
        if ($k['module'] === $m) {
            $refresh[] = CEO_TTL[$k['refresh']];
        }
    }
    try {
        [$v, $at] = ceo_cached($org, implode('|', ['src', $m, $f['from'], $f['to'], $f['spoke'], (int) ($f['dept'] ?? 0)]), $refresh ? min($refresh) : 300,
                               static fn() => ('ceo_src_' . $m)($org, $f), $force);
        return ['state' => 'ok', 'values' => $v, 'at' => $at];
    } catch (Throwable $e) {
        error_log('FABO ceo source ' . $m . ': ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
        return ['state' => 'failed', 'values' => [], 'at' => null];
    }
}

/* ================================================================ periods */

/** The window before this one, of the same length. */
function ceo_previous(array $f): array
{
    $days = (int) round((strtotime($f['to']) - strtotime($f['from'])) / 86400) + 1;
    $f['to'] = date('Y-m-d', strtotime($f['from'] . ' -1 day'));
    $f['from'] = date('Y-m-d', strtotime($f['to'] . ' -' . ($days - 1) . ' days'));
    return $f;
}

/** The spokes this cut should add up: one chosen, the person's allowed set, or the whole organisation ([''] = no split). */
function ceo_cuts(array $a, array $f): array
{
    if ($f['spoke'] !== '') {
        return [$f['spoke']];
    }
    return $a['spokes'] === '*' ? [''] : $a['spokes'];
}

function ceo_add($x, $y)
{
    if (is_array($x) || is_array($y)) {
        $x = is_array($x) ? $x : [0, 0];
        $y = is_array($y) ? $y : [0, 0];
        return [(float) $x[0] + (float) $y[0], (float) $x[1] + (float) $y[1]];
    }
    return $x === null ? $y : ($y === null ? $x : $x + $y);
}

/** All of a module's figures across the cuts, added up properly. */
function ceo_module_values(int $org, string $m, array $f, array $cuts, bool $force): array
{
    $vals = [];
    $state = 'ok';
    $at = null;
    foreach ($cuts as $s) {
        $r = ceo_source($org, $m, ['spoke' => $s] + $f, $force);
        if ($r['state'] !== 'ok') {
            return ['state' => $r['state'], 'values' => [], 'at' => null];
        }
        foreach ($r['values'] as $k => $v) {
            $vals[$k] = ceo_add($vals[$k] ?? null, $v);
        }
        $at = $at === null || $r['at'] < $at ? $r['at'] : $at;
    }
    return ['state' => $state, 'values' => $vals, 'at' => $at];
}

function ceo_ratio($v): ?float
{
    if (is_array($v)) {
        return (float) $v[1] > 0 ? round((float) $v[0] / (float) $v[1] * 100, 1) : null;
    }
    return $v === null ? null : (float) $v;
}

/**
 * Every KPI this person may see, for one cut, with its previous value and
 * state. Permissions are applied here: a figure a person may not see is never
 * in what goes back to the browser.
 */
function ceo_kpi_values(array $viewer, int $org, array $a, array $f, bool $force = false): array
{
    $mods = ceo_modules();
    $cuts = ceo_cuts($a, $f);
    $split = $cuts !== [''];
    $prevF = ceo_previous($f);
    $now = $prev = [];
    foreach ($a['modules'] as $m) {
        if (!empty($f['module']) && $f['module'] !== $m) {
            continue;
        }
        $now[$m] = ceo_module_values($org, $m, $f, $cuts, $force);
        $prev[$m] = ceo_module_values($org, $m, $prevF, $cuts, false);
    }
    $hist = ceo_snapshot_history($org, count($cuts) === 1 ? $cuts[0] : null, $prevF);

    $out = [];
    foreach (ceo_kpis() as $k => $d) {
        $m = $d['module'];
        if (!isset($now[$m]) || ($a['kpis'] !== '*' && !in_array($k, $a['kpis'], true))) {
            continue;
        }
        $row = ['key' => $k, 'label' => $d['label'], 'module' => $m, 'module_label' => $mods[$m]['label'], 'unit' => $d['unit'], 'kind' => $d['kind'],
                'better' => $d['better'], 'calc' => $d['calc'], 'ttl' => CEO_TTL[$d['refresh']], 'value' => null, 'prev' => null, 'delta' => null,
                'at' => $now[$m]['at'], 'href' => null, 'spark' => [], 'note' => ''];
        if ($d['sensitive'] !== null && !in_array($d['sensitive'], $a['sensitive'], true)) {
            $out[$k] = ['state' => 'restricted', 'note' => ceo_sensitive()[$d['sensitive']][0] . ' need explicit access.', 'at' => null, 'calc' => ''] + $row;
            continue;
        }
        if ($now[$m]['state'] !== 'ok') {
            $out[$k] = ['state' => $now[$m]['state'], 'at' => null] + $row;
            continue;
        }
        if ($split && !$d['spoke']) {
            if ($a['spokes'] !== '*') {
                continue;               // someone limited to spokes never sees an organisation-wide figure
            }
            $out[$k] = ['state' => 'not_split', 'at' => null] + $row;
            continue;
        }
        if (!array_key_exists($k, $now[$m]['values'])) {
            $out[$k] = ['state' => empty($f['dept']) ? 'no_data' : 'not_split', 'at' => null] + $row;
            continue;
        }
        $v = ceo_ratio($now[$m]['values'][$k]);
        $row['value'] = $v;
        if ($d['kind'] === 'flow') {
            $row['prev'] = ceo_ratio($prev[$m]['values'][$k] ?? null);
        } else {
            $row['prev'] = isset($hist['prev'][$k]) ? (float) $hist['prev'][$k] : null;
        }
        if ($v !== null && $row['prev'] !== null) {
            $row['delta'] = $d['unit'] === 'pct' ? round($v - $row['prev'], 1)
                          : ((float) $row['prev'] != 0.0 ? round(($v - $row['prev']) / abs((float) $row['prev']) * 100, 1) : null);
        }
        $row['spark'] = $hist['spark'][$k] ?? [];
        if (ceo_can_open($viewer, $m, $org)) {
            $row['href'] = ceo_link($viewer, $org, $m, $d['link']);
        }
        $out[$k] = ['state' => $v === null ? 'no_data' : 'ok'] + $row;
    }
    foreach (ceo_rollups() as $k => [$label, $parts, $calc]) {
        if ($a['kpis'] !== '*' && !in_array($k, $a['kpis'], true)) {
            continue;
        }
        $sum = null;
        $prevSum = null;
        $missing = [];
        $at = null;
        foreach ($parts as $m => $part) {
            if (!isset($now[$m])) {
                continue;
            }
            if ($now[$m]['state'] === 'ok' && array_key_exists($part, $now[$m]['values'])) {
                $sum = (float) ($sum ?? 0) + (float) $now[$m]['values'][$part];
                $at = $at === null || $now[$m]['at'] < $at ? $now[$m]['at'] : $at;
            } else {
                $missing[] = $mods[$m]['label'];
            }
            if (isset($hist['prev'][$k])) {
                $prevSum = (float) $hist['prev'][$k];
            }
        }
        $delta = $sum !== null && $prevSum ? round(($sum - $prevSum) / abs($prevSum) * 100, 1) : null;
        $out[$k] = ['key' => $k, 'label' => $label, 'module' => 'ops', 'module_label' => 'All modules', 'unit' => 'count', 'kind' => 'stock', 'better' => 'down', 'calc' => $calc,
                    'ttl' => 60, 'value' => $sum, 'prev' => $prevSum, 'delta' => $delta, 'at' => $at, 'href' => 'ceo:actions', 'spark' => $hist['spark'][$k] ?? [],
                    'note' => $missing ? 'Not counted: ' . implode(', ', $missing) . '.' : '', 'state' => $sum === null ? 'unavailable' : 'ok'];
    }
    return $out;
}

/* ================================================================ snapshots */

/** Stock figures have no past of their own, so the dashboard keeps one reading a day. Value at the end of the previous window, and a short run for the sparkline. */
function ceo_snapshot_history(int $org, ?string $spoke, array $prevF): array
{
    if ($spoke === null) {
        return ['prev' => [], 'spark' => []];
    }
    $prev = $spark = [];
    $since = min($prevF['from'], date('Y-m-d', strtotime(ceo_today() . ' -30 days')));
    foreach (fabo_all('SELECT kpi_key, day, value FROM ceo_snapshots WHERE org_id = ? AND spoke_key = ? AND day >= ? ORDER BY day', 'iss', [$org, $spoke, $since]) as $r) {
        $spark[$r['kpi_key']][] = (float) $r['value'];
        if ($r['day'] <= $prevF['to'] && $r['day'] >= $prevF['from']) {
            $prev[$r['kpi_key']] = (float) $r['value'];
        }
    }
    foreach ($spark as $k => $v) {
        $spark[$k] = array_slice($v, -21);
    }
    return ['prev' => $prev, 'spark' => $spark];
}

/** Takes today's reading for the organisation and each spoke. Once a day; the first caller does it. */
function ceo_snapshot_take(int $org): int
{
    $today = ceo_today();
    if (ceo_setting($org, 'snapshot_day') === $today) {
        return 0;
    }
    ceo_setting_put($org, 'snapshot_day', $today);
    $f = ['from' => date('Y-m-01', strtotime($today)), 'to' => $today, 'spoke' => '', 'dept' => 0];
    $n = 0;
    foreach (array_merge([''], array_keys(ceo_spokes($org))) as $spoke) {
        $parts = [];
        foreach (array_keys(ceo_modules()) as $m) {
            $r = ceo_source($org, $m, ['spoke' => $spoke] + $f);
            foreach ($r['values'] as $k => $v) {
                $parts[$k][$m] = $v;
                $def = ceo_kpis()[$k] ?? null;
                if ($def === null) {
                    continue;
                }
                $val = ceo_ratio($v);
                if ($val !== null) {
                    fabo_exec('INSERT INTO ceo_snapshots (org_id, day, spoke_key, kpi_key, value) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)', 'isssd', [$org, $today, $spoke, $k, $val]);
                    $n++;
                }
            }
        }
        foreach (ceo_rollups() as $k => [, $roll]) {
            $sum = null;
            foreach ($roll as $m => $part) {
                if (isset($parts[$part][$m])) {
                    $sum = (float) ($sum ?? 0) + (float) $parts[$part][$m];
                }
            }
            if ($sum !== null) {
                fabo_exec('INSERT INTO ceo_snapshots (org_id, day, spoke_key, kpi_key, value) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)', 'isssd', [$org, $today, $spoke, $k, $sum]);
                $n++;
            }
        }
    }
    return $n;
}

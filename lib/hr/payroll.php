<?php
/**
 * Payroll: salary components, employee salary structures (with revision
 * history), monthly payroll runs and payslips.
 *
 * A run moves Draft → Calculated → Reviewed → Approved → Finalized →
 * Payslips generated. Until it is approved it can be recalculated as often
 * as needed. From finalization on, its numbers are frozen: every line was
 * written to hr_payroll_lines with the basis it was calculated on, the
 * month's attendance is locked, and leave, holidays and attendance in that
 * month refuse to change. A later correction is an adjustment in a later
 * run — never a silent rewrite of a paid month.
 *
 * What payroll does NOT decide: how payable days are counted and how unpaid
 * days are deducted. Both are settings the administrator must choose before
 * the first calculation, because they are company policy and statutory
 * practice differs. Statutory components (PF, ESI, PT, TDS) are ordinary
 * components here with the rates the administrator enters; verify them with
 * a payroll adviser before production use.
 */

require_once __DIR__ . '/attendance.php';

function hr_component_kinds(): array
{
    return [
        'earning'       => 'Earning',
        'reimbursement' => 'Reimbursement',
        'deduction'     => 'Employee deduction',
        'employer'      => 'Employer contribution',
    ];
}

function hr_component_calcs(): array
{
    return [
        'fixed'     => 'Fixed monthly amount',
        'pct_basic' => '% of Basic',
        'pct_gross' => '% of Gross earnings',
        'balance'   => 'Balance of monthly gross (whatever is left)',
    ];
}

function hr_run_statuses(): array
{
    return ['draft' => 'Draft', 'calculated' => 'Calculated', 'reviewed' => 'Reviewed', 'approved' => 'Approved',
            'finalized' => 'Finalized', 'payslips' => 'Payslips generated'];
}

/* ------------------------------------------------------------ components */

function hr_components(bool $activeOnly = true): array
{
    return fabo_all('SELECT * FROM hr_salary_components WHERE org_id = ?' . ($activeOnly ? ' AND active = 1' : '')
                  . " ORDER BY FIELD(kind,'earning','reimbursement','deduction','employer'), sort, name", 'i', [hr_org()]);
}

function hr_component(int $id): ?array
{
    return fabo_one('SELECT * FROM hr_salary_components WHERE id = ? AND org_id = ?', 'ii', [$id, hr_org()]);
}

function hr_component_save(?int $id, array $p): int
{
    $org  = hr_org();
    $code = strtoupper(hr_str($p['code'] ?? '', 20, true, 'Code'));
    if (!preg_match('/^[A-Z][A-Z0-9_]{0,19}$/', $code)) {
        throw new InvalidArgumentException('Code must start with a letter and use letters, digits and _ only (for example HRA).');
    }
    $name = hr_str($p['name'] ?? '', 120, true, 'Component name');
    $kind = (string) ($p['kind'] ?? '');
    if (!isset(hr_component_kinds()[$kind])) {
        throw new InvalidArgumentException('Choose whether this is an earning, deduction, reimbursement or employer contribution.');
    }
    $calc = (string) ($p['calc'] ?? 'fixed');
    if (!isset(hr_component_calcs()[$calc])) {
        throw new InvalidArgumentException('Choose how the component is calculated.');
    }
    if ($calc === 'balance' && $kind !== 'earning') {
        throw new InvalidArgumentException('Only an earning can take the balance of monthly gross.');
    }
    $isBasic = !empty($p['is_basic']) ? 1 : 0;
    if ($isBasic && ($kind !== 'earning' || $calc === 'pct_basic')) {
        throw new InvalidArgumentException('Basic must be an earning, and cannot be a percentage of itself.');
    }
    $def = hr_dec($p['default_value'] ?? '0', 2, false, $calc === 'fixed' ? 'Default amount' : 'Default percentage', 0,
                  $calc === 'fixed' ? 100000000 : 100) ?? '0.00';
    $ceil = hr_dec($p['wage_ceiling'] ?? '', 2, false, 'Wage ceiling', 0);
    $glim = hr_dec($p['gross_limit'] ?? '', 2, false, 'Applies only when monthly gross is at most', 0);
    if (fabo_one('SELECT id FROM hr_salary_components WHERE org_id = ? AND code = ?' . ($id ? ' AND id <> ?' : ''),
                 'is' . ($id ? 'i' : ''), array_merge([$org, $code], $id ? [$id] : []))) {
        throw new InvalidArgumentException('A component with the code ' . $code . ' already exists.');
    }
    $vals = [$code, $name, $kind, $calc, $def, $ceil, $glim, !empty($p['prorate']) ? 1 : 0, $isBasic,
             !empty($p['taxable']) ? 1 : 0, (int) ($p['sort'] ?? 0), hr_str($p['description'] ?? '', 255) ?: null];
    if ($id) {
        $before = hr_component($id);
        if (!$before) {
            throw new InvalidArgumentException('That component no longer exists.');
        }
        fabo_exec('UPDATE hr_salary_components SET code = ?, name = ?, kind = ?, calc = ?, default_value = ?, wage_ceiling = ?,
                   gross_limit = ?, prorate = ?, is_basic = ?, taxable = ?, sort = ?, description = ? WHERE id = ? AND org_id = ?',
                  'sssssssiiiisii', array_merge($vals, [$id, $org]));
        hr_audit('update', 'salary_component', $id, 'Component updated: ' . $code, $before, $p);
        hr_config_log('payroll', 'Component ' . $code, $before, array_combine(
            ['code', 'name', 'kind', 'calc', 'default_value', 'wage_ceiling', 'gross_limit', 'prorate', 'is_basic', 'taxable', 'sort', 'description'], $vals));
    } else {
        $id = fabo_exec('INSERT INTO hr_salary_components (org_id, code, name, kind, calc, default_value, wage_ceiling, gross_limit,
                         prorate, is_basic, taxable, sort, description, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                        'isssssssiiiiss', array_merge([$org], $vals, [hr_actor()]));
        hr_audit('create', 'salary_component', $id, 'Component created: ' . $code);
        hr_config_log('payroll', 'Component ' . $code, '', 'created');
    }
    if ($isBasic) {
        fabo_exec('UPDATE hr_salary_components SET is_basic = (id = ?) WHERE org_id = ?', 'ii', [$id, $org]);
    }
    return $id;
}

/**
 * Common Indian components, offered as a starting point the administrator
 * picks from. Rates are the commonly used statutory ones; they are added
 * only when chosen and must be verified for the company before use.
 */
function hr_component_templates(): array
{
    return [
        'BASIC' => ['Basic Salary', 'earning', 'fixed', '0', null, null, 1, 1],
        'HRA'   => ['House Rent Allowance', 'earning', 'pct_basic', '40', null, null, 1, 0],
        'CONV'  => ['Conveyance Allowance', 'earning', 'fixed', '0', null, null, 1, 0],
        'SPL'   => ['Special Allowance', 'earning', 'balance', '0', null, null, 1, 0],
        'REIMB' => ['Reimbursements', 'reimbursement', 'fixed', '0', null, null, 0, 0],
        'PF'    => ['Provident Fund (employee)', 'deduction', 'pct_basic', '12', '15000', null, 1, 0],
        'ESI'   => ['ESI (employee)', 'deduction', 'pct_gross', '0.75', null, '21000', 1, 0],
        'PT'    => ['Professional Tax', 'deduction', 'fixed', '0', null, null, 0, 0],
        'TDS'   => ['Income Tax (TDS)', 'deduction', 'fixed', '0', null, null, 0, 0],
        'EPF'   => ['Provident Fund (employer)', 'employer', 'pct_basic', '12', '15000', null, 1, 0],
        'EESI'  => ['ESI (employer)', 'employer', 'pct_gross', '3.25', null, '21000', 1, 0],
    ];
}

function hr_components_add_templates(array $codes): int
{
    $n = 0;
    $i = 0;
    foreach (hr_component_templates() as $code => [$name, $kind, $calc, $def, $ceil, $glim, $prorate, $basic]) {
        $i++;
        if (!in_array($code, $codes, true) || fabo_one('SELECT id FROM hr_salary_components WHERE org_id = ? AND code = ?', 'is', [hr_org(), $code])) {
            continue;
        }
        hr_component_save(null, ['code' => $code, 'name' => $name, 'kind' => $kind, 'calc' => $calc, 'default_value' => $def,
                                 'wage_ceiling' => $ceil, 'gross_limit' => $glim, 'prorate' => $prorate, 'is_basic' => $basic,
                                 'taxable' => $kind === 'earning' ? 1 : 0, 'sort' => $i * 10]);
        $n++;
    }
    return $n;
}

/* ------------------------------------------------------------ structures */

function hr_structures(int $empId): array
{
    return fabo_all('SELECT * FROM hr_salary_structures WHERE employee_id = ? AND org_id = ? ORDER BY effective_from DESC',
                    'ii', [$empId, hr_org()]);
}

/** The structure in force on a date, with its lines. */
function hr_structure_on(int $empId, string $date): ?array
{
    $s = fabo_one('SELECT * FROM hr_salary_structures WHERE employee_id = ? AND org_id = ? AND effective_from <= ?
                   AND (effective_to IS NULL OR effective_to >= ?) ORDER BY effective_from DESC LIMIT 1',
                  'iiss', [$empId, hr_org(), $date, $date]);
    if ($s) {
        $s['lines'] = hr_structure_lines((int) $s['id']);
    }
    return $s;
}

function hr_structure_lines(int $structId): array
{
    return fabo_all('SELECT l.*, c.code, c.name, c.kind, c.wage_ceiling, c.gross_limit, c.prorate, c.is_basic, c.sort
                       FROM hr_salary_lines l JOIN hr_salary_components c ON c.id = l.component_id
                      WHERE l.structure_id = ? ORDER BY FIELD(c.kind,"earning","reimbursement","deduction","employer"), c.sort, c.name',
                    'i', [$structId]);
}

/**
 * Saves a salary structure from a date. A new date is a revision: the
 * previous structure is closed the day before, and kept. The same date
 * replaces that structure's lines — allowed only while no finalized payroll
 * has used it.
 */
function hr_structure_save(int $empId, array $p): int
{
    $emp = hr_employee($empId);
    if (!$emp) {
        throw new InvalidArgumentException('That employee no longer exists.');
    }
    $from  = hr_date($p['effective_from'] ?? '', true, 'Effective from');
    $gross = hr_dec($p['monthly_gross'] ?? '0', 2, false, 'Monthly gross', 0, 100000000) ?? '0.00';
    $note  = hr_str($p['note'] ?? '', 255) ?: null;
    $lines = [];
    foreach (hr_components() as $c) {
        $cid = (int) $c['id'];
        if (empty($p['use'][$cid])) {
            continue;
        }
        $calc = $c['calc'];
        $val  = $calc === 'balance' ? '0.00'
              : hr_dec($p['value'][$cid] ?? $c['default_value'], 2, true, $c['name'], 0, $calc === 'fixed' ? 100000000 : 100);
        $lines[$cid] = [$calc, $val];
    }
    if (!$lines) {
        throw new InvalidArgumentException('Choose at least one salary component.');
    }
    $hasBalance = (bool) array_filter($lines, static fn($l) => $l[0] === 'balance');
    if ($hasBalance && (float) $gross <= 0) {
        throw new InvalidArgumentException('Enter the monthly gross: a “balance” component needs it to know what is left.');
    }
    $basicId = (int) (fabo_one('SELECT id FROM hr_salary_components WHERE org_id = ? AND is_basic = 1 AND active = 1', 'i', [hr_org()])['id'] ?? 0);
    $needsBasic = (bool) array_filter($lines, static fn($l) => $l[0] === 'pct_basic');
    if ($needsBasic && (!$basicId || !isset($lines[$basicId]))) {
        throw new InvalidArgumentException('A component is a % of Basic, so Basic must be part of the structure.');
    }
    // Check the earnings fit inside the gross before saving.
    $preview = hr_structure_preview($lines, (float) $gross);
    if ($hasBalance && $preview['balance'] < -0.005) {
        throw new InvalidArgumentException('The fixed earnings add up to ' . fabo_money_exact($preview['fixed_earnings'])
            . ', more than the monthly gross of ' . fabo_money_exact((float) $gross) . '.');
    }

    return hr_tx(function () use ($empId, $from, $gross, $note, $lines, $emp) {
        $org = hr_org();
        $later = fabo_one('SELECT effective_from FROM hr_salary_structures WHERE employee_id = ? AND effective_from > ?', 'is', [$empId, $from]);
        if ($later) {
            throw new InvalidArgumentException('There is already a structure from ' . hr_fmt_date($later['effective_from'])
                                             . '. Revisions must be added in date order.');
        }
        $same = fabo_one('SELECT * FROM hr_salary_structures WHERE employee_id = ? AND effective_from = ?', 'is', [$empId, $from]);
        if ($same) {
            $used = fabo_one("SELECT r.period FROM hr_payroll_items i JOIN hr_payroll_runs r ON r.id = i.run_id
                              WHERE i.structure_id = ? AND r.status IN ('finalized','payslips') LIMIT 1", 'i', [(int) $same['id']]);
            if ($used) {
                throw new InvalidArgumentException('This structure was used in the finalized payroll for ' . $used['period']
                                                 . '. Add a revision with a new effective date instead.');
            }
            $sid = (int) $same['id'];
            $before = $same + ['lines' => hr_structure_lines($sid)];
            fabo_exec('UPDATE hr_salary_structures SET monthly_gross = ?, note = ? WHERE id = ?', 'ssi', [$gross, $note, $sid]);
            fabo_exec('DELETE FROM hr_salary_lines WHERE structure_id = ?', 'i', [$sid]);
        } else {
            $before = hr_structure_on($empId, date('Y-m-d', strtotime($from . ' -1 day')));
            fabo_exec('UPDATE hr_salary_structures SET effective_to = DATE_SUB(?, INTERVAL 1 DAY)
                       WHERE employee_id = ? AND effective_to IS NULL AND effective_from < ?', 'sis', [$from, $empId, $from]);
            $sid = fabo_exec('INSERT INTO hr_salary_structures (org_id, employee_id, effective_from, monthly_gross, note, created_by)
                              VALUES (?,?,?,?,?,?)', 'iissss', [$org, $empId, $from, $gross, $note, hr_actor()]);
        }
        foreach ($lines as $cid => [$calc, $val]) {
            fabo_exec('INSERT INTO hr_salary_lines (structure_id, component_id, calc, value) VALUES (?,?,?,?)', 'iiss', [$sid, $cid, $calc, $val]);
        }
        fabo_exec('INSERT INTO hr_employee_history (employee_id, field, old_value, new_value, effective_on, changed_by) VALUES (?,?,?,?,?,?)',
                  'isssss', [$empId, 'Salary structure', $before ? fabo_money_exact($before['monthly_gross']) . ' gross' : null,
                             fabo_money_exact((float) $gross) . ' gross', $from, hr_actor()]);
        hr_audit($same ? 'update' : 'revise', 'salary_structure', $sid, 'Salary structure for ' . $emp['full_name'] . ' from ' . $from,
                 $before ? ['monthly_gross' => $before['monthly_gross']] : null, ['monthly_gross' => $gross, 'lines' => $lines]);
        return $sid;
    });
}

/** Full-month amounts for a set of lines: what a structure is worth before any proration. */
function hr_structure_preview(array $lines, float $gross): array
{
    $comps = [];
    foreach (hr_components(false) as $c) {
        $comps[(int) $c['id']] = $c;
    }
    $basic = 0.0;
    foreach ($lines as $cid => [$calc, $val]) {
        if (!empty($comps[$cid]['is_basic'])) {
            $basic = (float) $val;
        }
    }
    $fixed = 0.0;
    $amounts = [];
    foreach ($lines as $cid => [$calc, $val]) {
        $c = $comps[$cid] ?? null;
        if (!$c || $c['kind'] !== 'earning' || $calc === 'balance' || $calc === 'pct_gross') {
            continue;
        }
        $amt = $calc === 'pct_basic' ? round($basic * (float) $val / 100, 2) : (float) $val;
        $amounts[$cid] = $amt;
        $fixed += $amt;
    }
    return ['fixed_earnings' => $fixed, 'balance' => round($gross - $fixed, 2), 'amounts' => $amounts, 'basic' => $basic];
}

/* -------------------------------------------------------------- runs */

function hr_run(int $id): ?array
{
    return fabo_one('SELECT * FROM hr_payroll_runs WHERE id = ? AND org_id = ?', 'ii', [$id, hr_org()]);
}

function hr_runs(int $limit = 36): array
{
    return fabo_all('SELECT r.*, (SELECT COUNT(*) FROM hr_payroll_items i WHERE i.run_id = r.id) AS items,
                            (SELECT COALESCE(SUM(net),0) FROM hr_payroll_items i WHERE i.run_id = r.id) AS net_total,
                            (SELECT COALESCE(SUM(gross),0) FROM hr_payroll_items i WHERE i.run_id = r.id) AS gross_total,
                            (SELECT COALESCE(SUM(employer_cost),0) FROM hr_payroll_items i WHERE i.run_id = r.id) AS employer_total
                       FROM hr_payroll_runs r WHERE r.org_id = ? ORDER BY r.period DESC LIMIT ' . max(1, $limit), 'i', [hr_org()]);
}

/** Settings payroll cannot run without, as sentences, or [] when ready. */
function hr_payroll_blockers(): array
{
    $out = [];
    if (!hr_setting('pay_divisor')) {
        $out[] = 'Choose how payable days are counted (Policies & Rules → Payroll → “Per-day salary is the monthly amount divided by”).';
    }
    if (!hr_setting('pay_lop_method')) {
        $out[] = 'Choose how unpaid leave and absence are deducted (Policies & Rules → Payroll).';
    }
    if (!fabo_one('SELECT id FROM hr_salary_components WHERE org_id = ? AND active = 1 LIMIT 1', 'i', [hr_org()])) {
        $out[] = 'Add salary components (HR Setup → Salary components).';
    }
    if (hr_setting('pay_overtime') !== 'none' && (float) hr_setting('pay_overtime_value') <= 0) {
        $out[] = 'Overtime pay is switched on but its multiplier / hourly amount is not set.';
    }
    return $out;
}

function hr_run_create(string $period): int
{
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
        throw new InvalidArgumentException('Choose a month.');
    }
    if ($period > date('Y-m')) {
        throw new InvalidArgumentException('Payroll cannot be started for a future month.');
    }
    if (fabo_one('SELECT id FROM hr_payroll_runs WHERE org_id = ? AND period = ?', 'is', [hr_org(), $period])) {
        throw new InvalidArgumentException('A payroll run for ' . date('F Y', strtotime($period . '-01')) . ' already exists. Open it from the list.');
    }
    $from = $period . '-01';
    $to   = date('Y-m-t', strtotime($from));
    $id = fabo_exec('INSERT INTO hr_payroll_runs (org_id, period, period_start, period_end, status, created_by) VALUES (?,?,?,?,?,?)',
                    'isssss', [hr_org(), $period, $from, $to, 'draft', hr_actor()]);
    hr_audit('create', 'payroll_run', $id, 'Payroll run created for ' . $period);
    return $id;
}

/**
 * Calculates every employee in a run. Allowed until the run is approved.
 * Everything happens in one transaction: items, lines and the adjustments
 * picked up are written together, or not at all.
 */
function hr_run_calculate(int $runId): array
{
    $run = hr_run($runId);
    if (!$run) {
        throw new InvalidArgumentException('That payroll run no longer exists.');
    }
    if (!in_array($run['status'], ['draft', 'calculated', 'reviewed'], true)) {
        throw new InvalidArgumentException('This run is ' . strtolower(hr_run_statuses()[$run['status']])
                                         . '. Send it back to calculation before recalculating.');
    }
    if ($b = hr_payroll_blockers()) {
        throw new InvalidArgumentException(implode(' ', $b));
    }
    $emps = fabo_all("SELECT * FROM hr_employees WHERE org_id = ? AND (date_of_joining IS NULL OR date_of_joining <= ?)
                      AND (date_of_leaving IS NULL OR date_of_leaving >= ?)
                      AND (status IN ('active','probation','notice') OR date_of_leaving IS NOT NULL)
                      ORDER BY full_name", 'iss', [hr_org(), $run['period_end'], $run['period_start']]);
    $settings = [];
    foreach (['pay_divisor', 'pay_lop_method', 'pay_overtime', 'pay_overtime_value', 'pay_round', 'att_unmarked_past', 'att_ot_needs_approval'] as $k) {
        $settings[$k] = hr_setting($k);
    }
    $summary = hr_tx(function () use ($run, $emps, $settings) {
        $runId = (int) $run['id'];
        $hold  = [];
        foreach (fabo_all('SELECT employee_id, hold FROM hr_payroll_items WHERE run_id = ?', 'i', [$runId]) as $h) {
            $hold[(int) $h['employee_id']] = (int) $h['hold'];
        }
        fabo_exec('DELETE l FROM hr_payroll_lines l JOIN hr_payroll_items i ON i.id = l.item_id WHERE i.run_id = ?', 'i', [$runId]);
        fabo_exec('DELETE FROM hr_payroll_items WHERE run_id = ?', 'i', [$runId]);
        fabo_exec('UPDATE hr_payroll_adjustments SET applied_run_id = NULL WHERE applied_run_id = ?', 'i', [$runId]);
        $n = 0;
        $warn = 0;
        foreach ($emps as $e) {
            $calc = hr_payroll_compute($e, $run, $settings);
            $itemId = fabo_exec('INSERT INTO hr_payroll_items (run_id, employee_id, structure_id, calendar_days, working_days, divisor_days,
                         present_days, paid_leave_days, unpaid_leave_days, absent_days, not_employed_days, holidays, weekly_offs, lop_days,
                         payable_days, overtime_hours, gross, deductions, employer_cost, net, warnings, hold)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', 'iiissssssssssssssssssi', [
                $runId, (int) $e['id'], $calc['structure_id'], $calc['days']['calendar'], $calc['days']['working'], $calc['days']['divisor'],
                $calc['days']['present'], $calc['days']['paid_leave'], $calc['days']['unpaid_leave'], $calc['days']['absent'],
                $calc['days']['not_employed'], $calc['days']['holidays'], $calc['days']['offs'], $calc['days']['lop'], $calc['days']['payable'],
                $calc['ot_hours'], $calc['gross'], $calc['deductions'], $calc['employer'], $calc['net'],
                $calc['warnings'] ? implode("\n", $calc['warnings']) : null, $hold[(int) $e['id']] ?? 0,
            ]);
            foreach ($calc['lines'] as $i => $l) {
                fabo_exec('INSERT INTO hr_payroll_lines (item_id, component_id, code, name, kind, full_amount, amount, basis, sort)
                           VALUES (?,?,?,?,?,?,?,?,?)', 'iissssssi',
                          [$itemId, $l['component_id'], $l['code'], $l['name'], $l['kind'], number_format($l['full'], 2, '.', ''),
                           number_format($l['amount'], 2, '.', ''), mb_substr($l['basis'], 0, 255), $i]);
            }
            foreach ($calc['adjustment_ids'] as $aid) {
                fabo_exec('UPDATE hr_payroll_adjustments SET applied_run_id = ? WHERE id = ?', 'ii', [$runId, $aid]);
            }
            $n++;
            $warn += $calc['warnings'] ? 1 : 0;
        }
        fabo_exec("UPDATE hr_payroll_runs SET status = 'calculated', calculated_by = ?, calculated_at = NOW(), settings_json = ?,
                   reviewed_by = NULL, reviewed_at = NULL WHERE id = ?", 'ssi', [hr_actor(), json_encode($settings), $runId]);
        return ['employees' => $n, 'with_warnings' => $warn];
    });
    hr_audit('calculate', 'payroll_run', $runId, 'Payroll ' . $run['period'] . ' calculated for ' . $summary['employees'] . ' employees');
    return $summary;
}

/**
 * One employee's pay for a run: day counts, every component with the basis
 * it was worked out on, adjustments, overtime and rounding. Nothing is saved.
 */
function hr_payroll_compute(array $emp, array $run, array $settings): array
{
    $from = $run['period_start'];
    $to   = $run['period_end'];
    $warnings = [];

    /* ---- days ---- */
    $grid   = hr_att_grid([$emp], $from, $to)[(int) $emp['id']] ?? [];
    $leaves = hr_approved_leave_days([(int) $emp['id']], $from, $to)[(int) $emp['id']] ?? [];
    $d = ['calendar' => 0, 'not_employed' => 0, 'offs' => 0, 'holidays' => 0, 'working' => 0, 'present' => 0.0,
          'paid_leave' => 0.0, 'unpaid_leave' => 0.0, 'absent' => 0.0, 'unmarked' => 0, 'missing' => 0];
    $fullOffs = 0;
    $fullHols = 0;
    $otMin = 0;
    foreach ($grid as $date => $cell) {
        [$st, $rec, $note] = $cell;
        $info = $cell['info'];
        $d['calendar']++;
        if ($info['holiday']) { $fullHols++; } elseif ($info['off']) { $fullOffs++; }
        if ($rec && (int) $rec['overtime_minutes'] > 0 && ($settings['att_ot_needs_approval'] !== '1' || $rec['overtime_approved'])) {
            $otMin += (int) $rec['overtime_minutes'];
        }
        if (!$info['employed']) { $d['not_employed']++; continue; }
        if ($info['holiday'] && !isset($leaves[$date])) { $d['holidays']++; continue; }
        if ($info['off'] && !isset($leaves[$date])) { $d['offs']++; continue; }
        $d['working']++;
        $remain = 1.0;
        if (isset($leaves[$date])) {
            $part = $leaves[$date]['half'] ? 0.5 : 1.0;
            if ($leaves[$date]['paid']) {
                $d['paid_leave'] += $part;
            } else {
                $d['unpaid_leave'] += $part;
            }
            $remain -= $part;
        }
        if ($remain <= 0) {
            continue;
        }
        if ($rec) {
            $val = hr_att_present_value($rec['status']);
            if ($rec['status'] === 'Missing Check-in / Check-out') { $d['missing']++; }
        } else {
            $val = $settings['att_unmarked_past'] === 'present' ? 1.0 : 0.0;
            if ($date < date('Y-m-d')) { $d['unmarked']++; }
        }
        $got = min($remain, $val);
        $d['present'] += $got;
        $d['absent']  += $remain - $got;
    }
    $lop = $settings['pay_lop_method'] === 'none' ? 0.0 : $d['unpaid_leave'] + $d['absent'];
    $method = $settings['pay_divisor'];
    $divisor = match ($method) {
        'working' => (float) max(1, $d['calendar'] - $fullOffs - $fullHols),
        'fixed30' => 30.0,
        'fixed26' => 26.0,
        default   => (float) $d['calendar'],
    };
    // Days outside employment are not paid, whatever the divisor.
    $notEmp = $method === 'working'
            ? (float) count(array_filter($grid, static fn($c) => !$c['info']['employed'] && !$c['info']['off'] && !$c['info']['holiday']))
            : ($method === 'calendar' ? (float) $d['not_employed'] : round($d['not_employed'] * $divisor / max(1, $d['calendar']), 2));
    $payable = max(0.0, round($divisor - $notEmp - $lop, 2));
    $factor  = $divisor > 0 ? min(1.0, $payable / $divisor) : 0.0;
    $d['divisor'] = $divisor;
    $d['lop'] = $lop;
    $d['payable'] = $payable;

    if ($d['unmarked'] > 0) {
        $warnings[] = $d['unmarked'] . ' working day(s) have no attendance and were counted as '
                    . ($settings['att_unmarked_past'] === 'present' ? 'present' : 'absent') . '.';
    }
    if ($d['missing'] > 0) {
        $warnings[] = $d['missing'] . ' day(s) have a missing check-in or check-out.';
    }
    $pendLeave = (int) (fabo_one("SELECT COUNT(*) n FROM hr_leave_requests WHERE employee_id = ? AND status = 'submitted'
                                  AND start_date <= ? AND end_date >= ?", 'iss', [(int) $emp['id'], $to, $from])['n'] ?? 0);
    if ($pendLeave) {
        $warnings[] = $pendLeave . ' leave request(s) in this month are still waiting for a decision.';
    }
    $pendCorr = (int) (fabo_one("SELECT COUNT(*) n FROM hr_attendance_corrections WHERE employee_id = ? AND status = 'submitted'
                                 AND work_date BETWEEN ? AND ?", 'iss', [(int) $emp['id'], $from, $to])['n'] ?? 0);
    if ($pendCorr) {
        $warnings[] = $pendCorr . ' attendance correction(s) in this month are still pending.';
    }

    /* ---- salary ---- */
    $struct = hr_structure_on((int) $emp['id'], min($to, $emp['date_of_leaving'] ?: $to));
    $lines = [];
    $adjIds = [];
    if (!$struct) {
        $warnings[] = 'No salary structure covers this month. Nothing can be paid until one is added.';
    } else {
        $revised = fabo_one('SELECT effective_from FROM hr_salary_structures WHERE employee_id = ? AND effective_from > ? AND effective_from <= ?',
                            'iss', [(int) $emp['id'], $from, $to]);
        if ($revised) {
            $warnings[] = 'Salary was revised on ' . hr_fmt_date($revised['effective_from'])
                        . '; the new structure is used for the whole month. Record any difference as an adjustment.';
        }
        $pctNote = $factor < 1 ? ' × ' . hr_fmt_days($payable) . '/' . hr_fmt_days($divisor) . ' payable days' : '';
        // Earnings first: Basic, then fixed and % of Basic, then the balance.
        $basicFull = 0.0;
        foreach ($struct['lines'] as $l) {
            if ($l['is_basic']) { $basicFull = (float) $l['value']; }
        }
        $grossTarget = (float) $struct['monthly_gross'];
        $earnFull = 0.0;
        $earnRows = [];
        foreach ($struct['lines'] as $l) {
            if (!in_array($l['kind'], ['earning', 'reimbursement'], true) || $l['calc'] === 'balance' || $l['calc'] === 'pct_gross') {
                continue;
            }
            $full = $l['calc'] === 'pct_basic' ? round($basicFull * (float) $l['value'] / 100, 2) : (float) $l['value'];
            $basis = $l['calc'] === 'pct_basic' ? hr_fmt_days($l['value']) . '% of Basic ' . fabo_money_exact($basicFull) : 'Fixed';
            $earnRows[] = [$l, $full, $basis];
            if ($l['kind'] === 'earning') { $earnFull += $full; }
        }
        foreach ($struct['lines'] as $l) {
            if ($l['kind'] === 'earning' && $l['calc'] === 'balance') {
                $full = max(0.0, round($grossTarget - $earnFull, 2));
                $earnRows[] = [$l, $full, 'Balance of monthly gross ' . fabo_money_exact($grossTarget)];
                $earnFull += $full;
            }
        }
        foreach ($struct['lines'] as $l) {
            if ($l['kind'] === 'earning' && $l['calc'] === 'pct_gross') {
                $full = round($earnFull * (float) $l['value'] / 100, 2);
                $earnRows[] = [$l, $full, hr_fmt_days($l['value']) . '% of gross'];
            }
        }
        $basicPaid = $basicFull;
        $grossFull = 0.0;
        foreach ($earnRows as [$l, $full, $basis]) {
            $amt = $l['prorate'] ? round($full * $factor, 2) : $full;
            if ($l['is_basic']) { $basicPaid = $amt; }
            if ($l['kind'] === 'earning') { $grossFull += $full; }
            $lines[] = ['component_id' => (int) $l['component_id'], 'code' => $l['code'], 'name' => $l['name'], 'kind' => $l['kind'],
                        'full' => $full, 'amount' => $amt, 'basis' => $basis . ($l['prorate'] && $factor < 1 ? $pctNote : '')];
        }
        $grossPaid = array_sum(array_map(static fn($x) => $x['kind'] === 'earning' ? $x['amount'] : 0, $lines));

        // Overtime.
        $otHours = round($otMin / 60, 2);
        if ($otHours > 0 && $settings['pay_overtime'] !== 'none') {
            $val = (float) $settings['pay_overtime_value'];
            if ($settings['pay_overtime'] === 'fixed') {
                $amt = round($otHours * $val, 2);
                $basis = hr_fmt_days($otHours) . ' h × ' . fabo_money_exact($val) . ' per hour';
            } else {
                $shift = hr_emp_shift((int) $emp['id'], $to);
                $hrs = $shift ? (float) $shift['full_day_hours'] : 0;
                if ($hrs <= 0) {
                    $amt = 0.0;
                    $basis = 'Not paid: no shift with full-day hours is assigned';
                    $warnings[] = 'Overtime could not be priced: no shift is assigned.';
                } else {
                    $hourly = $grossFull / $divisor / $hrs;
                    $amt = round($otHours * $hourly * $val, 2);
                    $basis = hr_fmt_days($otHours) . ' h × ' . fabo_money_exact($hourly) . '/h × ' . $val;
                }
            }
            if ($amt > 0) {
                $lines[] = ['component_id' => null, 'code' => 'OT', 'name' => 'Overtime Pay', 'kind' => 'earning', 'full' => $amt, 'amount' => $amt, 'basis' => $basis];
                $grossPaid += $amt;
            }
        }

        // Deductions and employer contributions, on what is actually paid.
        foreach ($struct['lines'] as $l) {
            if (!in_array($l['kind'], ['deduction', 'employer'], true)) {
                continue;
            }
            if ($l['gross_limit'] !== null && $grossFull > (float) $l['gross_limit']) {
                continue;   // e.g. ESI: not applicable above the wage limit
            }
            if ($l['calc'] === 'pct_basic' || $l['calc'] === 'pct_gross') {
                $base = $l['calc'] === 'pct_basic' ? $basicPaid : $grossPaid;
                $baseFull = $l['calc'] === 'pct_basic' ? $basicFull : $grossFull;
                $capNote = '';
                if ($l['wage_ceiling'] !== null) {
                    if ($base > (float) $l['wage_ceiling']) { $capNote = ' (capped at ' . fabo_money_exact((float) $l['wage_ceiling']) . ')'; }
                    $base = min($base, (float) $l['wage_ceiling']);
                    $baseFull = min($baseFull, (float) $l['wage_ceiling']);
                }
                $amt  = round($base * (float) $l['value'] / 100, 2);
                $full = round($baseFull * (float) $l['value'] / 100, 2);
                $basis = hr_fmt_days($l['value']) . '% of ' . ($l['calc'] === 'pct_basic' ? 'Basic ' : 'gross ') . fabo_money_exact($base) . $capNote;
            } else {
                $full = (float) $l['value'];
                $amt  = $l['prorate'] ? round($full * $factor, 2) : $full;
                $basis = 'Fixed' . ($l['prorate'] && $factor < 1 ? $pctNote : '');
            }
            $lines[] = ['component_id' => (int) $l['component_id'], 'code' => $l['code'], 'name' => $l['name'], 'kind' => $l['kind'],
                        'full' => $full, 'amount' => $amt, 'basis' => $basis];
        }
    }

    // Approved adjustments for this month not yet used by another run.
    foreach (fabo_all('SELECT * FROM hr_payroll_adjustments WHERE employee_id = ? AND period = ? AND org_id = ?
                       AND (applied_run_id IS NULL OR applied_run_id = ?)', 'isii', [(int) $emp['id'], $run['period'], hr_org(), (int) $run['id']]) as $a) {
        $lines[] = ['component_id' => null, 'code' => 'ADJ' . $a['id'], 'name' => $a['label'], 'kind' => $a['kind'],
                    'full' => (float) $a['amount'], 'amount' => (float) $a['amount'], 'basis' => 'Adjustment' . ($a['reason'] ? ': ' . $a['reason'] : '')];
        $adjIds[] = (int) $a['id'];
    }

    $gross = $ded = $emplr = $reimb = 0.0;
    foreach ($lines as $l) {
        match ($l['kind']) {
            'earning' => $gross += $l['amount'],
            'reimbursement' => $reimb += $l['amount'],
            'deduction' => $ded += $l['amount'],
            'employer' => $emplr += $l['amount'],
            default => null,
        };
    }
    $net = round($gross + $reimb - $ded, 2);
    $rounded = match ($settings['pay_round']) {
        'rupee' => round($net),
        'ten'   => ceil($net / 10) * 10,
        default => $net,
    };
    if (abs($rounded - $net) >= 0.005) {
        $lines[] = ['component_id' => null, 'code' => 'ROUND', 'name' => 'Rounding', 'kind' => $rounded > $net ? 'earning_round' : 'deduction_round',
                    'full' => abs($rounded - $net), 'amount' => abs($rounded - $net), 'basis' => 'Net rounded ' . ($settings['pay_round'] === 'ten' ? 'up to ₹10' : 'to the rupee')];
        $net = $rounded;
    }
    if ($net < 0) {
        $warnings[] = 'Deductions exceed earnings: net pay is negative.';
    }
    if ($struct && ($emp['payment_mode'] ?? '') !== 'cash' && ($emp['payment_mode'] ?? '') !== 'cheque' && (!$emp['bank_account'] || !$emp['bank_ifsc'])) {
        $warnings[] = 'Bank account or IFSC is missing.';
    }
    return [
        'structure_id' => $struct ? (int) $struct['id'] : null,
        'days' => $d, 'ot_hours' => round($otMin / 60, 2), 'lines' => $lines, 'adjustment_ids' => $adjIds,
        'gross' => round($gross + $reimb, 2), 'deductions' => round($ded, 2), 'employer' => round($emplr, 2), 'net' => round($net, 2),
        'warnings' => $warnings,
    ];
}

function hr_run_items(int $runId, bool $scoped = true): array
{
    $sc = $scoped ? hr_scope_sql('payroll', 'view', 'e') : ['sql' => ''];
    return fabo_all('SELECT i.*, e.full_name, e.emp_code, e.bank_account, e.bank_ifsc, e.bank_name, e.payment_mode, d.name AS department,
                            g.name AS designation, p.number AS payslip_number
                       FROM hr_payroll_items i JOIN hr_employees e ON e.id = i.employee_id
                       LEFT JOIN hr_departments d ON d.id = e.department_id LEFT JOIN hr_designations g ON g.id = e.designation_id
                       LEFT JOIN hr_payslips p ON p.item_id = i.id
                      WHERE i.run_id = ? AND e.org_id = ?' . $sc['sql'] . ' ORDER BY e.full_name', 'ii', [$runId, hr_org()]);
}

function hr_item(int $itemId): ?array
{
    return fabo_one('SELECT i.*, r.period, r.status AS run_status, r.org_id, e.full_name, e.emp_code
                       FROM hr_payroll_items i JOIN hr_payroll_runs r ON r.id = i.run_id JOIN hr_employees e ON e.id = i.employee_id
                      WHERE i.id = ? AND r.org_id = ?', 'ii', [$itemId, hr_org()]);
}

function hr_item_lines(int $itemId): array
{
    return fabo_all('SELECT * FROM hr_payroll_lines WHERE item_id = ? ORDER BY sort, id', 'i', [$itemId]);
}

/**
 * Moves a run along its workflow. $to: reviewed | approved | finalized |
 * payslips | back (to calculated). Each step checks the one before it.
 */
function hr_run_advance(int $runId, string $to, string $confirm = ''): string
{
    $run = hr_run($runId);
    if (!$run) {
        throw new InvalidArgumentException('That payroll run no longer exists.');
    }
    $s = $run['status'];
    $needReview = hr_setting('pay_needs_review') === '1';
    switch ($to) {
        case 'reviewed':
            hr_need('payroll', 'process_payroll');
            if ($s !== 'calculated') {
                throw new InvalidArgumentException('Only a calculated run can be marked reviewed.');
            }
            fabo_exec("UPDATE hr_payroll_runs SET status = 'reviewed', reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND status = 'calculated'",
                      'si', [hr_actor(), $runId]);
            $msg = 'Marked as reviewed.';
            break;
        case 'approved':
            hr_need('payroll', 'approve');
            if ($s !== 'reviewed' && !($s === 'calculated' && !$needReview)) {
                throw new InvalidArgumentException($needReview ? 'Review the run before approving it.' : 'Calculate the run before approving it.');
            }
            fabo_exec("UPDATE hr_payroll_runs SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ? AND status IN ('calculated','reviewed')",
                      'si', [hr_actor(), $runId]);
            $msg = 'Approved.';
            break;
        case 'back':
            hr_need('payroll', 'process_payroll');
            if (!in_array($s, ['reviewed', 'approved'], true)) {
                throw new InvalidArgumentException('Only a reviewed or approved run can be sent back.');
            }
            fabo_exec("UPDATE hr_payroll_runs SET status = 'calculated', reviewed_by = NULL, reviewed_at = NULL, approved_by = NULL,
                       approved_at = NULL WHERE id = ?", 'i', [$runId]);
            $msg = 'Sent back for recalculation.';
            break;
        case 'finalized':
            hr_need('payroll', 'approve');
            if ($s !== 'approved') {
                throw new InvalidArgumentException('Approve the run before finalizing it.');
            }
            if (strtoupper(trim($confirm)) !== 'FINALIZE') {
                throw new InvalidArgumentException('Type FINALIZE to confirm. Finalized payroll cannot be changed.');
            }
            hr_tx(function () use ($runId, $run) {
                fabo_exec("UPDATE hr_payroll_runs SET status = 'finalized', finalized_by = ?, finalized_at = NOW() WHERE id = ? AND status = 'approved'",
                          'si', [hr_actor(), $runId]);
                fabo_exec('INSERT INTO hr_attendance_locks (org_id, period, locked, locked_by, locked_at, reason) VALUES (?,?,1,?,NOW(),?)
                           ON DUPLICATE KEY UPDATE locked = 1, locked_by = VALUES(locked_by), locked_at = NOW(), reason = VALUES(reason)',
                          'isss', [hr_org(), $run['period'], hr_actor(), 'Payroll finalized']);
            });
            foreach (hr_run_items($runId, false) as $it) {
                if (!$it['hold']) {
                    hr_notify_employee((int) $it['employee_id'], 'pay-final-' . $runId, 'Payroll finalized for ' . date('F Y', strtotime($run['period'] . '-01')),
                        'Your salary for ' . date('F Y', strtotime($run['period'] . '-01')) . ' has been finalized. Your payslip will follow.');
                }
            }
            $msg = 'Finalized. Attendance for ' . $run['period'] . ' is now locked.';
            break;
        case 'payslips':
            hr_need('payroll', 'process_payroll');
            if (!in_array($s, ['finalized', 'payslips'], true)) {
                throw new InvalidArgumentException('Payslips are generated after the run is finalized.');
            }
            $n = hr_payslips_generate($run);
            $msg = $n . ' payslip(s) generated.';
            break;
        default:
            throw new InvalidArgumentException('Unknown step.');
    }
    hr_audit('payroll_' . $to, 'payroll_run', $runId, 'Payroll ' . $run['period'] . ': ' . $msg);
    return $msg;
}

function hr_run_delete(int $runId): void
{
    $run = hr_run($runId);
    if (!$run || !in_array($run['status'], ['draft', 'calculated', 'reviewed'], true)) {
        throw new InvalidArgumentException('Only a run that has not been approved can be deleted.');
    }
    hr_tx(function () use ($runId) {
        fabo_exec('DELETE l FROM hr_payroll_lines l JOIN hr_payroll_items i ON i.id = l.item_id WHERE i.run_id = ?', 'i', [$runId]);
        fabo_exec('DELETE FROM hr_payroll_items WHERE run_id = ?', 'i', [$runId]);
        fabo_exec('UPDATE hr_payroll_adjustments SET applied_run_id = NULL WHERE applied_run_id = ?', 'i', [$runId]);
        fabo_exec('DELETE FROM hr_payroll_runs WHERE id = ?', 'i', [$runId]);
    });
    hr_audit('delete', 'payroll_run', $runId, 'Payroll run deleted: ' . $run['period'], $run, null);
}

/** Holds or releases one employee's pay in an open run. */
function hr_item_hold(int $itemId, bool $hold): void
{
    $it = hr_item($itemId);
    if (!$it || in_array($it['run_status'], ['finalized', 'payslips'], true)) {
        throw new InvalidArgumentException('Pay can be held only before the run is finalized.');
    }
    fabo_exec('UPDATE hr_payroll_items SET hold = ? WHERE id = ?', 'ii', [$hold ? 1 : 0, $itemId]);
    hr_audit($hold ? 'hold' : 'release', 'payroll_item', $itemId, ($hold ? 'Held' : 'Released') . ' pay for ' . $it['full_name']);
}

/** Records that a finalized salary was paid (outside the portal — no bank integration is claimed). */
function hr_item_mark_paid(int $itemId, string $status, ?string $paidOn, string $ref): void
{
    $it = hr_item($itemId);
    if (!$it || !in_array($it['run_status'], ['finalized', 'payslips'], true)) {
        throw new InvalidArgumentException('Payment can be recorded once the run is finalized.');
    }
    if (!in_array($status, ['pending', 'paid', 'on_hold'], true)) {
        throw new InvalidArgumentException('Choose a payment status.');
    }
    $paidOn = $status === 'paid' ? (hr_date($paidOn, true, 'Paid on') ?? date('Y-m-d')) : null;
    fabo_exec('UPDATE hr_payroll_items SET payment_status = ?, paid_on = ?, payment_ref = ? WHERE id = ?',
              'sssi', [$status, $paidOn, hr_str($ref, 120) ?: null, $itemId]);
    hr_audit('payment', 'payroll_item', $itemId, 'Payment ' . $status . ' for ' . $it['full_name'] . ($ref ? ' (' . $ref . ')' : ''));
}

/* --------------------------------------------------------- adjustments */

function hr_adjustment_save(array $p): int
{
    $empId = (int) ($p['employee_id'] ?? 0);
    hr_need_emp($empId, 'payroll', 'process_payroll');
    $period = (string) ($p['period'] ?? '');
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
        throw new InvalidArgumentException('Choose the payroll month the adjustment belongs to.');
    }
    $run = fabo_one('SELECT status FROM hr_payroll_runs WHERE org_id = ? AND period = ?', 'is', [hr_org(), $period]);
    if ($run && !in_array($run['status'], ['draft', 'calculated', 'reviewed'], true)) {
        throw new InvalidArgumentException('Payroll for ' . $period . ' is already ' . strtolower(hr_run_statuses()[$run['status']])
                                         . '. Put the adjustment in a later month.');
    }
    $kind = (string) ($p['kind'] ?? '');
    if (!in_array($kind, ['earning', 'deduction', 'reimbursement'], true)) {
        throw new InvalidArgumentException('Choose whether the adjustment adds to or takes from pay.');
    }
    $label  = hr_str($p['label'] ?? '', 120, true, 'Description');
    $amount = hr_dec($p['amount'] ?? '', 2, true, 'Amount', 0.01, 100000000);
    $reason = hr_str($p['reason'] ?? '', 255, true, 'Reason');
    $id = fabo_exec('INSERT INTO hr_payroll_adjustments (org_id, employee_id, period, kind, label, amount, reason, created_by)
                     VALUES (?,?,?,?,?,?,?,?)', 'iissssss', [hr_org(), $empId, $period, $kind, $label, $amount, $reason, hr_actor()]);
    hr_audit('create', 'payroll_adjustment', $id, $label . ' ' . $amount . ' for employee ' . $empId . ' in ' . $period . ': ' . $reason);
    return $id;
}

function hr_adjustment_delete(int $id): void
{
    $a = fabo_one('SELECT * FROM hr_payroll_adjustments WHERE id = ? AND org_id = ?', 'ii', [$id, hr_org()]);
    if (!$a) {
        throw new InvalidArgumentException('That adjustment no longer exists.');
    }
    if ($a['applied_run_id']) {
        $run = hr_run((int) $a['applied_run_id']);
        if ($run && !in_array($run['status'], ['draft', 'calculated', 'reviewed'], true)) {
            throw new InvalidArgumentException('This adjustment is part of an approved or finalized run and cannot be removed.');
        }
    }
    fabo_exec('DELETE FROM hr_payroll_adjustments WHERE id = ?', 'i', [$id]);
    hr_audit('delete', 'payroll_adjustment', $id, 'Adjustment removed: ' . $a['label'], $a, null);
}

function hr_adjustments(string $period = ''): array
{
    $sc = hr_scope_sql('payroll', 'view', 'e');
    return fabo_all('SELECT a.*, e.full_name, e.emp_code, r.period AS run_period, r.status AS run_status FROM hr_payroll_adjustments a
                       JOIN hr_employees e ON e.id = a.employee_id LEFT JOIN hr_payroll_runs r ON r.id = a.applied_run_id
                      WHERE a.org_id = ?' . ($period !== '' ? ' AND a.period = ?' : '') . $sc['sql'] . ' ORDER BY a.period DESC, a.id DESC LIMIT 500',
                    'i' . ($period !== '' ? 's' : ''), $period !== '' ? [hr_org(), $period] : [hr_org()]);
}

/* ------------------------------------------------------------ payslips */

function hr_payslips_generate(array $run): int
{
    $fmt = (string) (hr_setting('pay_slip_format') ?: 'PS/{YYYY}/{MM}/{SEQ}');
    $n = 0;
    hr_tx(function () use ($run, $fmt, &$n) {
        $seq = (int) (fabo_one('SELECT COUNT(*) c FROM hr_payslips WHERE run_id = ?', 'i', [(int) $run['id']])['c'] ?? 0);
        foreach (hr_run_items((int) $run['id'], false) as $it) {
            if ($it['hold'] || $it['payslip_number'] || !$it['structure_id']) {
                continue;
            }
            $seq++;
            $num = strtr($fmt, ['{YYYY}' => substr($run['period'], 0, 4), '{YY}' => substr($run['period'], 2, 2),
                                '{MM}' => substr($run['period'], 5, 2), '{SEQ}' => str_pad((string) $seq, 4, '0', STR_PAD_LEFT),
                                '{EMP}' => $it['emp_code']]);
            fabo_exec('INSERT INTO hr_payslips (org_id, run_id, item_id, employee_id, number, generated_by) VALUES (?,?,?,?,?,?)',
                      'iiiiss', [hr_org(), (int) $run['id'], (int) $it['id'], (int) $it['employee_id'], $num, hr_actor()]);
            $n++;
        }
        fabo_exec("UPDATE hr_payroll_runs SET status = 'payslips', payslips_at = NOW() WHERE id = ?", 'i', [(int) $run['id']]);
    });
    foreach (hr_run_items((int) $run['id'], false) as $it) {
        if ($it['payslip_number']) {
            hr_notify_employee((int) $it['employee_id'], 'payslip-' . $it['id'], 'Payslip available for ' . date('F Y', strtotime($run['period'] . '-01')),
                'Your payslip ' . $it['payslip_number'] . ' is ready to view and download from your HR page.');
        }
    }
    return $n;
}

/** Everything a payslip shows, or null when it is not available to the viewer. */
function hr_payslip_data(int $itemId): ?array
{
    $it = fabo_one('SELECT i.*, r.period, r.period_start, r.period_end, r.status AS run_status, p.number, p.generated_at
                      FROM hr_payroll_items i JOIN hr_payroll_runs r ON r.id = i.run_id LEFT JOIN hr_payslips p ON p.item_id = i.id
                     WHERE i.id = ? AND r.org_id = ?', 'ii', [$itemId, hr_org()]);
    if (!$it) {
        return null;
    }
    $emp = hr_employee_full((int) $it['employee_id']);
    return ['item' => $it, 'emp' => $emp, 'lines' => hr_item_lines($itemId)];
}

/** An employee's payroll history: finalized months, newest first. */
function hr_emp_pay_history(int $empId): array
{
    return fabo_all("SELECT i.*, r.period, r.status AS run_status, p.number FROM hr_payroll_items i JOIN hr_payroll_runs r ON r.id = i.run_id
                     LEFT JOIN hr_payslips p ON p.item_id = i.id WHERE i.employee_id = ? AND r.org_id = ?
                     ORDER BY r.period DESC LIMIT 60", 'ii', [$empId, hr_org()]);
}

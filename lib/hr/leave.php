<?php
/**
 * Leave: types, policies, the ledger, balances, requests and approvals.
 *
 * How a balance is kept
 * ---------------------
 * Every day credited or debited is one row in hr_leave_ledger, and a balance
 * is the sum of those rows. Each row names what caused it (ref_type, ref_id,
 * ref_version) and the database refuses the same cause twice. So:
 *
 *   - approving a request posts one 'taken' row; approving it again cannot
 *     post another — leave is deducted exactly once;
 *   - rejecting posts nothing;
 *   - cancelling an approved request posts one 'reversal' row;
 *   - correcting an approved request reverses the old version and posts the
 *     new one under the next version number;
 *   - accruals are posted per month (or per year) as the time comes, using
 *     the rule in force then, so a policy change affects the future and never
 *     rewrites what was already credited.
 *
 * Pending requests are not in the ledger. They are shown separately and,
 * when "hold pending requests" is on, held against the available balance.
 */

require_once __DIR__ . '/employees.php';
require_once __DIR__ . '/leave_chain.php';

/* ----------------------------------------------------------------- types */

function hr_leave_types(bool $activeOnly = true): array
{
    return fabo_all('SELECT * FROM hr_leave_types WHERE org_id = ?' . ($activeOnly ? ' AND active = 1' : '')
                  . ' ORDER BY sort, name', 'i', [hr_org()]);
}

function hr_leave_type(int $id): ?array
{
    return fabo_one('SELECT * FROM hr_leave_types WHERE id = ? AND org_id = ?', 'ii', [$id, hr_org()]);
}

function hr_leave_type_save(?int $id, array $p): int
{
    $org  = hr_org();
    $code = strtoupper(hr_str($p['code'] ?? '', 12, true, 'Short code'));
    if (!preg_match('/^[A-Z0-9_-]{1,12}$/', $code)) {
        throw new InvalidArgumentException('Short code may contain letters, digits, - and _ only (for example CL).');
    }
    $name = hr_str($p['name'] ?? '', 120, true, 'Leave type name');
    foreach (['code' => $code, 'name' => $name] as $col => $val) {
        if (fabo_one('SELECT id FROM hr_leave_types WHERE org_id = ? AND ' . $col . ' = ?' . ($id ? ' AND id <> ?' : ''),
                     'is' . ($id ? 'i' : ''), array_merge([$org, $val], $id ? [$id] : []))) {
            throw new InvalidArgumentException('A leave type with the ' . ($col === 'code' ? 'code' : 'name') . ' “' . $val . '” already exists.');
        }
    }
    $paid  = !empty($p['is_paid']) ? 1 : 0;
    $comp  = !empty($p['is_comp_off']) ? 1 : 0;
    $unl   = !empty($p['unlimited']) ? 1 : 0;
    $att   = hr_dec($p['attachment_after_days'] ?? '', 1, false, 'Attachment required after (days)', 0, 365);
    $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($p['color'] ?? '')) ? $p['color'] : null;
    $desc  = hr_str($p['description'] ?? '', 255) ?: null;
    $sort  = (int) ($p['sort'] ?? 0);
    if ($id) {
        $before = hr_leave_type($id);
        if (!$before) {
            throw new InvalidArgumentException('That leave type no longer exists.');
        }
        fabo_exec('UPDATE hr_leave_types SET code = ?, name = ?, is_paid = ?, is_comp_off = ?, unlimited = ?, attachment_after_days = ?,
                   color = ?, description = ?, sort = ? WHERE id = ? AND org_id = ?', 'ssiiisssiii',
                  [$code, $name, $paid, $comp, $unl, $att, $color, $desc, $sort, $id, $org]);
        hr_audit('update', 'leave_type', $id, 'Leave type updated: ' . $name, $before, $p);
        hr_config_log('leave', 'Leave type ' . $code, $before, ['code' => $code, 'name' => $name, 'is_paid' => $paid,
                      'unlimited' => $unl, 'is_comp_off' => $comp]);
        return $id;
    }
    $new = fabo_exec('INSERT INTO hr_leave_types (org_id, code, name, is_paid, is_comp_off, unlimited, attachment_after_days, color,
                      description, sort, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)', 'issiiisssis',
                     [$org, $code, $name, $paid, $comp, $unl, $att, $color, $desc, $sort, hr_actor()]);
    hr_audit('create', 'leave_type', $new, 'Leave type created: ' . $name);
    hr_config_log('leave', 'Leave type ' . $code, '', ['name' => $name, 'is_paid' => $paid]);
    return $new;
}

/**
 * The usual Indian leave types, by name only. Nothing here carries an
 * entitlement: how many days each one gives is set on a policy, by the
 * administrator, because that is company policy and not ours to assume.
 */
function hr_leave_type_templates(): array
{
    return [
        ['CL', 'Casual Leave', 1, 0, 0], ['SL', 'Sick Leave', 1, 0, 0], ['EL', 'Earned Leave / Privilege Leave', 1, 0, 0],
        ['AL', 'Annual Leave', 1, 0, 0], ['CO', 'Compensatory Off', 1, 1, 0], ['ML', 'Maternity Leave', 1, 0, 0],
        ['PTL', 'Paternity Leave', 1, 0, 0], ['LWP', 'Leave Without Pay', 0, 0, 1],
    ];
}

function hr_leave_types_add_templates(array $codes): int
{
    $n = 0;
    foreach (hr_leave_type_templates() as $i => [$code, $name, $paid, $comp, $unl]) {
        if (!in_array($code, $codes, true)) {
            continue;
        }
        if (fabo_one('SELECT id FROM hr_leave_types WHERE org_id = ? AND (code = ? OR name = ?)', 'iss', [hr_org(), $code, $name])) {
            continue;
        }
        hr_leave_type_save(null, ['code' => $code, 'name' => $name, 'is_paid' => $paid, 'is_comp_off' => $comp,
                                  'unlimited' => $unl, 'sort' => $i * 10]);
        $n++;
    }
    return $n;
}

/* -------------------------------------------------------------- policies */

function hr_leave_policies(bool $activeOnly = false): array
{
    return fabo_all('SELECT * FROM hr_leave_policies WHERE org_id = ?' . ($activeOnly ? ' AND active = 1' : '')
                  . ' ORDER BY is_default DESC, name', 'i', [hr_org()]);
}

function hr_leave_policy(int $id): ?array
{
    return fabo_one('SELECT * FROM hr_leave_policies WHERE id = ? AND org_id = ?', 'ii', [$id, hr_org()]);
}

function hr_leave_policies_map(): array
{
    $out = [];
    foreach (hr_leave_policies(true) as $p) {
        $out[(int) $p['id']] = $p['name'] . ($p['is_default'] ? ' (default)' : '');
    }
    return $out;
}

function hr_ids_csv($v): ?string
{
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) $v))));
    return $ids ? implode(',', $ids) : null;
}

function hr_leave_policy_save(?int $id, array $p): int
{
    $org  = hr_org();
    $name = hr_str($p['name'] ?? '', 120, true, 'Policy name');
    if (fabo_one('SELECT id FROM hr_leave_policies WHERE org_id = ? AND name = ?' . ($id ? ' AND id <> ?' : ''),
                 'is' . ($id ? 'i' : ''), array_merge([$org, $name], $id ? [$id] : []))) {
        throw new InvalidArgumentException('A leave policy called “' . $name . '” already exists.');
    }
    $desc  = hr_str($p['description'] ?? '', 255) ?: null;
    $depts = hr_ids_csv($p['department_ids'] ?? []);
    $types = hr_ids_csv($p['employment_type_ids'] ?? []);
    $from  = hr_date($p['effective_from'] ?? '', false, 'Effective from');
    $to    = hr_date($p['effective_to'] ?? '', false, 'Effective to');
    if ($from && $to && $to < $from) {
        throw new InvalidArgumentException('“Effective to” is before “Effective from”.');
    }
    if ($id) {
        $before = hr_leave_policy($id);
        if (!$before) {
            throw new InvalidArgumentException('That policy no longer exists.');
        }
        fabo_exec('UPDATE hr_leave_policies SET name = ?, description = ?, department_ids = ?, employment_type_ids = ?,
                   effective_from = ?, effective_to = ? WHERE id = ? AND org_id = ?', 'ssssssii',
                  [$name, $desc, $depts, $types, $from, $to, $id, $org]);
        hr_audit('update', 'leave_policy', $id, 'Leave policy updated: ' . $name, $before, $p);
        hr_config_log('leave', 'Policy ' . $name, $before, ['department_ids' => $depts, 'employment_type_ids' => $types,
                      'effective_from' => $from, 'effective_to' => $to], $from);
    } else {
        $id = fabo_exec('INSERT INTO hr_leave_policies (org_id, name, description, department_ids, employment_type_ids,
                         effective_from, effective_to, created_by) VALUES (?,?,?,?,?,?,?,?)', 'isssssss',
                        [$org, $name, $desc, $depts, $types, $from, $to, hr_actor()]);
        hr_audit('create', 'leave_policy', $id, 'Leave policy created: ' . $name);
        hr_config_log('leave', 'Policy ' . $name, '', 'created', $from);
    }
    if (!empty($p['is_default']) || !fabo_one('SELECT id FROM hr_leave_policies WHERE org_id = ? AND is_default = 1', 'i', [$org])) {
        fabo_exec('UPDATE hr_leave_policies SET is_default = (id = ?) WHERE org_id = ?', 'ii', [$id, $org]);
    }
    return $id;
}

/** leave_type_id => rule row for a policy. */
function hr_policy_rules(int $policyId): array
{
    $out = [];
    foreach (fabo_all('SELECT r.* FROM hr_leave_rules r JOIN hr_leave_policies p ON p.id = r.policy_id
                       WHERE r.policy_id = ? AND p.org_id = ?', 'ii', [$policyId, hr_org()]) as $r) {
        $out[(int) $r['leave_type_id']] = $r;
    }
    return $out;
}

/**
 * Sets one leave type's rule inside a policy. The old values go to the
 * configuration history with the date the change takes effect; ledger rows
 * already posted are not touched.
 */
function hr_leave_rule_save(int $policyId, int $typeId, array $p): void
{
    $pol  = hr_leave_policy($policyId);
    $type = hr_leave_type($typeId);
    if (!$pol || !$type) {
        throw new InvalidArgumentException('That policy or leave type no longer exists.');
    }
    $acc = (string) ($p['accrual'] ?? 'annual');
    if (!in_array($acc, ['annual', 'monthly', 'none'], true)) {
        throw new InvalidArgumentException('Choose how the leave is credited.');
    }
    $v = [
        'annual_entitlement' => hr_dec($p['annual_entitlement'] ?? '', 2, $acc !== 'none' && !$type['unlimited'], 'Days per year', 0, 366) ?? '0.00',
        'accrual'            => $acc,
        'prorate_joining'    => !empty($p['prorate_joining']) ? 1 : 0,
        'carry_forward'      => !empty($p['carry_forward']) ? 1 : 0,
        'carry_limit'        => hr_dec($p['carry_limit'] ?? '', 2, false, 'Carry-forward limit', 0, 999),
        'carry_expiry_months' => ($p['carry_expiry_months'] ?? '') === '' ? null : (int) hr_dec($p['carry_expiry_months'], 0, false, 'Carried leave expires after (months)', 1, 24),
        'encashable'         => !empty($p['encashable']) ? 1 : 0,
        'max_consecutive'    => hr_dec($p['max_consecutive'] ?? '', 1, false, 'Maximum consecutive days', 0.5, 366),
        'min_notice_days'    => ($p['min_notice_days'] ?? '') === '' ? null : (int) hr_dec($p['min_notice_days'], 0, false, 'Apply at least (days ahead)', 0, 365),
        'min_service_days'   => (int) (hr_dec($p['min_service_days'] ?? '0', 0, false, 'Minimum service (days)', 0, 3650) ?? 0),
        'allow_in_probation' => !empty($p['allow_in_probation']) ? 1 : 0,
        'allow_half_day'     => !empty($p['allow_half_day']) ? 1 : 0,
        'allow_negative'     => !empty($p['allow_negative']) ? 1 : 0,
        'negative_limit'     => hr_dec($p['negative_limit'] ?? '0', 1, false, 'Negative balance limit', 0, 365) ?? '0.0',
        'count_weekly_offs'  => !empty($p['count_weekly_offs']) ? 1 : 0,
        'count_holidays'     => !empty($p['count_holidays']) ? 1 : 0,
        'effective_from'     => hr_date($p['effective_from'] ?? '', false, 'Effective from') ?? date('Y-m-d'),
    ];
    if (!$v['carry_forward']) {
        $v['carry_limit'] = null;
        $v['carry_expiry_months'] = null;
    }
    $before = fabo_one('SELECT * FROM hr_leave_rules WHERE policy_id = ? AND leave_type_id = ?', 'ii', [$policyId, $typeId]);
    $cols = array_keys($v);
    if ($before) {
        $set = implode(', ', array_map(static fn($c) => $c . ' = ?', $cols));
        fabo_exec('UPDATE hr_leave_rules SET ' . $set . ', updated_by = ? WHERE id = ?', str_repeat('s', count($cols)) . 'si',
                  array_merge(array_values($v), [hr_actor(), (int) $before['id']]));
    } else {
        fabo_exec('INSERT INTO hr_leave_rules (policy_id, leave_type_id, ' . implode(', ', $cols) . ', updated_by)
                   VALUES (?, ?, ' . implode(', ', array_fill(0, count($cols), '?')) . ', ?)', 'ii' . str_repeat('s', count($cols)) . 's',
                  array_merge([$policyId, $typeId], array_values($v), [hr_actor()]));
    }
    hr_audit($before ? 'update' : 'create', 'leave_rule', $policyId, $pol['name'] . ' · ' . $type['name'] . ' rule saved', $before, $v);
    hr_config_log('leave', $pol['name'] . ' · ' . $type['code'], $before ?: '', $v, $v['effective_from']);
}

function hr_leave_rule_delete(int $policyId, int $typeId): void
{
    $pol = hr_leave_policy($policyId);
    if (!$pol) {
        throw new InvalidArgumentException('That policy no longer exists.');
    }
    $before = fabo_one('SELECT * FROM hr_leave_rules WHERE policy_id = ? AND leave_type_id = ?', 'ii', [$policyId, $typeId]);
    if ($before) {
        fabo_exec('DELETE FROM hr_leave_rules WHERE id = ?', 'i', [(int) $before['id']]);
        hr_audit('delete', 'leave_rule', $policyId, 'Rule removed from ' . $pol['name'], $before, null);
        hr_config_log('leave', $pol['name'] . ' · type #' . $typeId, $before, 'removed');
    }
}

/** How many employees a policy currently applies to — shown before a change is saved. */
function hr_policy_reach(int $policyId): int
{
    $n = 0;
    foreach (fabo_all("SELECT * FROM hr_employees WHERE org_id = ? AND status IN ('active','probation','notice')", 'i', [hr_org()]) as $e) {
        $p = hr_emp_policy($e, date('Y-m-d'));
        if ($p && (int) $p['id'] === $policyId) {
            $n++;
        }
    }
    return $n;
}

/**
 * The policy that applies to an employee on a date: the one set on them,
 * else the first active policy whose departments / employment types match,
 * else the default. Null when none is configured.
 */
function hr_emp_policy(array $emp, string $date): ?array
{
    static $pols = [];
    $org = (int) $emp['org_id'];
    $pols[$org] ??= fabo_all('SELECT * FROM hr_leave_policies WHERE org_id = ? AND active = 1 ORDER BY is_default, id', 'i', [$org]);
    $inRange = static fn($p) => (!$p['effective_from'] || $p['effective_from'] <= $date) && (!$p['effective_to'] || $p['effective_to'] >= $date);
    if (!empty($emp['leave_policy_id'])) {
        foreach ($pols[$org] as $p) {
            if ((int) $p['id'] === (int) $emp['leave_policy_id'] && $inRange($p)) {
                return $p;
            }
        }
    }
    foreach ($pols[$org] as $p) {
        if ($p['is_default'] || !$inRange($p) || (!$p['department_ids'] && !$p['employment_type_ids'])) {
            continue;
        }
        $deptOk = !$p['department_ids'] || in_array((int) $emp['department_id'], array_map('intval', explode(',', $p['department_ids'])), true);
        $typeOk = !$p['employment_type_ids'] || in_array((int) $emp['employment_type_id'], array_map('intval', explode(',', $p['employment_type_ids'])), true);
        if ($deptOk && $typeOk) {
            return $p;
        }
    }
    foreach ($pols[$org] as $p) {
        if ($p['is_default'] && $inRange($p)) {
            return $p;
        }
    }
    return null;
}

function hr_emp_rule(array $emp, int $typeId, string $date): ?array
{
    $pol = hr_emp_policy($emp, $date);
    if (!$pol) {
        return null;
    }
    static $rules = [];
    $rules[(int) $pol['id']] ??= fabo_all('SELECT * FROM hr_leave_rules WHERE policy_id = ?', 'i', [(int) $pol['id']]);
    foreach ($rules[(int) $pol['id']] as $r) {
        if ((int) $r['leave_type_id'] === $typeId) {
            return $r;
        }
    }
    return null;
}

/* ---------------------------------------------------------------- ledger */

/**
 * Posts one ledger row. Returns false (and posts nothing) when the same
 * cause has already been posted — that refusal is the "exactly once".
 */
function hr_ledger_post(array $emp, int $typeId, int $year, string $kind, float $days, string $postedOn,
                        string $refType, int $refId, int $version = 0, string $note = ''): bool
{
    try {
        fabo_exec('INSERT INTO hr_leave_ledger (org_id, employee_id, leave_type_id, leave_year, kind, days, posted_on, ref_type, ref_id,
                   ref_version, note, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', 'iiiisssssiss',
                  [(int) $emp['org_id'], (int) $emp['id'], $typeId, $year, $kind, number_format(round($days, 2), 2, '.', ''),
                   $postedOn, $refType, $refId, $version, mb_substr($note, 0, 255), hr_actor()]);
        return true;
    } catch (mysqli_sql_exception $e) {
        if (str_contains($e->getMessage(), 'Duplicate entry')) {
            return false;
        }
        throw $e;
    }
}

/** Ledger kinds, as the balance table names them. */
function hr_ledger_kinds(): array
{
    return [
        'opening' => 'Opening balance', 'accrual' => 'Accrued', 'comp_off' => 'Compensatory off earned',
        'taken' => 'Taken', 'reversal' => 'Returned (cancelled / corrected)', 'carry_forward' => 'Carried forward',
        'expired' => 'Expired', 'lapsed' => 'Lapsed at year end', 'carried_out' => 'Moved to next year', 'adjustment' => 'Manual adjustment', 'encashed' => 'Encashed',
    ];
}

/**
 * Posts every accrual that has fallen due for an employee in a leave year,
 * and any carried-forward leave that has expired. Safe to call on every page
 * view: each credit has its own cause and is posted at most once.
 */
function hr_leave_sync(array $emp, ?int $year = null): void
{
    $today = date('Y-m-d');
    $year ??= hr_leave_year_of($today, (int) $emp['org_id']);
    [$yFrom, $yTo] = hr_leave_year_bounds($year, (int) $emp['org_id']);
    $doj = $emp['date_of_joining'] ?: $yFrom;
    $dol = $emp['date_of_leaving'] ?: null;
    if ($doj > $yTo || ($dol && $dol < $yFrom) || $yFrom > $today) {
        return;
    }
    $timing = (string) hr_setting('leave_accrual_timing', (int) $emp['org_id']);
    $types  = fabo_all('SELECT * FROM hr_leave_types WHERE org_id = ? AND active = 1 AND unlimited = 0', 'i', [(int) $emp['org_id']]);
    // What is already posted, read once, so a page view inserts only what is new.
    $have = [];
    foreach (fabo_all("SELECT leave_type_id, ref_type, ref_id FROM hr_leave_ledger WHERE employee_id = ? AND leave_year = ?
                       AND ref_type IN ('accrual','cf_expiry')", 'ii', [(int) $emp['id'], $year]) as $r) {
        $have[$r['leave_type_id'] . '|' . $r['ref_type'] . '|' . $r['ref_id']] = true;
    }
    foreach ($types as $t) {
        $tid = (int) $t['id'];
        for ($i = 0; $i < 12; $i++) {
            $mFrom = date('Y-m-d', strtotime($yFrom . ' +' . $i . ' month'));
            $mTo   = date('Y-m-t', strtotime($mFrom));
            $rule  = hr_emp_rule($emp, $tid, max($mFrom, $doj) <= $mTo ? max($mFrom, $doj) : $mFrom);
            if (!$rule || (float) $rule['annual_entitlement'] <= 0) {
                continue;
            }
            if ($rule['accrual'] === 'annual') {
                if ($i > 0 && !($doj > $yFrom && substr($doj, 0, 7) === substr($mFrom, 0, 7))) {
                    continue;
                }
                if (isset($have[$tid . '|accrual|' . ($year * 100)])) {
                    continue;
                }
                $postOn = max($yFrom, $doj);
                if (substr($postOn, 0, 7) !== substr($mFrom, 0, 7) || $postOn > $today) {
                    continue;
                }
                $amount = (float) $rule['annual_entitlement'];
                if ($rule['prorate_joining'] && $doj > $yFrom) {
                    $monthsLeft = 12 - $i;
                    $amount = round($amount * $monthsLeft / 12 * 2) / 2;   // to the nearest half day
                }
                hr_ledger_post($emp, $tid, $year, 'accrual', $amount, $postOn, 'accrual', $year * 100, 0,
                               'Annual credit' . ($amount != (float) $rule['annual_entitlement'] ? ' (pro-rated from joining)' : ''));
            } elseif ($rule['accrual'] === 'monthly') {
                if (isset($have[$tid . '|accrual|' . ($year * 100 + $i + 1)])) {
                    continue;
                }
                $due = $timing === 'end' ? date('Y-m-d', strtotime($mTo . ' +1 day')) : $mFrom;
                if ($due > $today || $doj > $mTo || ($dol && $dol < $mFrom)) {
                    continue;
                }
                $amount = (float) $rule['annual_entitlement'] / 12;
                if ($rule['prorate_joining'] && $doj > $mFrom) {
                    $dim = (int) date('t', strtotime($mFrom));
                    $worked = (strtotime($mTo) - strtotime($doj)) / 86400 + 1;
                    $amount = $amount * $worked / $dim;
                }
                hr_ledger_post($emp, $tid, $year, 'accrual', round($amount, 2), max($mFrom, $doj), 'accrual', $year * 100 + $i + 1, 0,
                               'Monthly credit for ' . date('M Y', strtotime($mFrom)));
            }
        }
        // Carried leave that was not used by its expiry date lapses.
        $rule = hr_emp_rule($emp, $tid, min($today, $yTo));
        if ($rule && $rule['carry_expiry_months'] && !isset($have[$tid . '|cf_expiry|' . $year])) {
            $expiry = date('Y-m-d', strtotime($yFrom . ' +' . (int) $rule['carry_expiry_months'] . ' month -1 day'));
            if ($expiry < $today) {
                $carried = (float) (fabo_one("SELECT COALESCE(SUM(days),0) s FROM hr_leave_ledger WHERE employee_id = ? AND leave_type_id = ?
                                              AND leave_year = ? AND kind = 'carry_forward'", 'iii', [(int) $emp['id'], $tid, $year])['s'] ?? 0);
                if ($carried > 0) {
                    $usedBy = -(float) (fabo_one("SELECT COALESCE(SUM(days),0) s FROM hr_leave_ledger WHERE employee_id = ? AND leave_type_id = ?
                                                  AND leave_year = ? AND kind IN ('taken','reversal') AND posted_on <= ?",
                                                 'iiis', [(int) $emp['id'], $tid, $year, $expiry])['s'] ?? 0);
                    $lapse = max(0, $carried - max(0, $usedBy));
                    if ($lapse > 0) {
                        hr_ledger_post($emp, $tid, $year, 'expired', -$lapse, $expiry, 'cf_expiry', $year, 0,
                                       'Carried-forward leave not used by ' . hr_fmt_date($expiry));
                    }
                }
            }
        }
    }
}

/**
 * Balances for one employee and leave year, per leave type:
 *   opening, entitlement, accrued, carried, taken, pending, expired,
 *   adjusted, available, unpaid (for unpaid types), rule.
 */
function hr_leave_balances(array $emp, ?int $year = null, bool $sync = true): array
{
    $year ??= hr_leave_year_of(date('Y-m-d'), (int) $emp['org_id']);
    if ($sync) {
        hr_leave_sync($emp, $year);
    }
    [$yFrom, $yTo] = hr_leave_year_bounds($year, (int) $emp['org_id']);
    $sums = [];
    foreach (fabo_all('SELECT leave_type_id, kind, SUM(days) s FROM hr_leave_ledger WHERE employee_id = ? AND leave_year = ?
                       GROUP BY leave_type_id, kind', 'ii', [(int) $emp['id'], $year]) as $r) {
        $sums[(int) $r['leave_type_id']][$r['kind']] = (float) $r['s'];
    }
    $pending = [];
    foreach (fabo_all("SELECT leave_type_id, SUM(days) s FROM hr_leave_requests WHERE employee_id = ? AND status = 'submitted'
                       AND start_date BETWEEN ? AND ? GROUP BY leave_type_id", 'iss', [(int) $emp['id'], $yFrom, $yTo]) as $r) {
        $pending[(int) $r['leave_type_id']] = (float) $r['s'];
    }
    $reserve = hr_setting('leave_reserve_pending', (int) $emp['org_id']) === '1';
    $out = [];
    foreach (fabo_all('SELECT * FROM hr_leave_types WHERE org_id = ? AND active = 1 ORDER BY sort, name', 'i', [(int) $emp['org_id']]) as $t) {
        $tid  = (int) $t['id'];
        $s    = $sums[$tid] ?? [];
        $rule = hr_emp_rule($emp, $tid, min(date('Y-m-d'), $yTo));
        if (!$rule && !$t['unlimited'] && !$s && empty($pending[$tid])) {
            continue;   // not part of this employee's policy and never used
        }
        $taken = -(($s['taken'] ?? 0) + ($s['reversal'] ?? 0));
        $total = array_sum($s);
        $pend  = $pending[$tid] ?? 0;
        $out[$tid] = [
            'type'        => $t,
            'rule'        => $rule,
            'entitlement' => $rule ? (float) $rule['annual_entitlement'] : 0.0,
            'opening'     => $s['opening'] ?? 0,
            'accrued'     => ($s['accrual'] ?? 0) + ($s['comp_off'] ?? 0),
            'carried'     => $s['carry_forward'] ?? 0,
            'taken'       => $taken,
            'pending'     => $pend,
            'expired'     => -(($s['expired'] ?? 0) + ($s['lapsed'] ?? 0)),
            'adjusted'    => ($s['adjustment'] ?? 0) + ($s['encashed'] ?? 0),
            'carried_out' => -($s['carried_out'] ?? 0),
            'balance'     => $total,
            'available'   => $t['unlimited'] ? null : round($total - ($reserve ? $pend : 0), 2),
            'unpaid'      => $t['is_paid'] ? 0 : $taken,
        ];
    }
    return $out;
}

/**
 * Leave month by month for one employee and leave year:
 *   type_id => 'YYYY-MM' => ['credited', 'taken', 'pending']
 * Taken counts approved leave on the days it falls (a request spanning two
 * months is split between them); credited is what the ledger added that
 * month; pending is requests still waiting for approval.
 */
function hr_leave_month_stats(array $emp, int $year): array
{
    [$yFrom, $yTo] = hr_leave_year_bounds($year, (int) $emp['org_id']);
    $out = [];
    foreach (fabo_all("SELECT leave_type_id, DATE_FORMAT(posted_on, '%Y-%m') m, SUM(days) s FROM hr_leave_ledger
                       WHERE employee_id = ? AND leave_year = ? AND kind IN ('accrual','comp_off') GROUP BY leave_type_id, m",
                      'ii', [(int) $emp['id'], $year]) as $r) {
        $out[(int) $r['leave_type_id']][$r['m']]['credited'] = (float) $r['s'];
    }
    $types = [];
    foreach (fabo_all('SELECT id, code FROM hr_leave_types WHERE org_id = ?', 'i', [(int) $emp['org_id']]) as $t) {
        $types[$t['code']] = (int) $t['id'];
    }
    foreach (hr_approved_leave_days([(int) $emp['id']], $yFrom, $yTo)[(int) $emp['id']] ?? [] as $d => $x) {
        $tid = $types[$x['code']] ?? 0;
        $m = substr($d, 0, 7);
        $out[$tid][$m]['taken'] = ($out[$tid][$m]['taken'] ?? 0) + ($x['half'] ? 0.5 : 1);
    }
    foreach (fabo_all("SELECT * FROM hr_leave_requests WHERE employee_id = ? AND status = 'submitted' AND start_date <= ? AND end_date >= ?",
                      'iss', [(int) $emp['id'], $yTo, $yFrom]) as $r) {
        $rule = hr_emp_rule($emp, (int) $r['leave_type_id'], $r['start_date']);
        foreach (hr_leave_count($emp, $rule, $r['start_date'], $r['end_date'], (bool) $r['start_half'], (bool) $r['end_half'])['detail'] as $d => $lab) {
            if ($lab === 'Full day' || $lab === 'Half day') {
                $m = substr($d, 0, 7);
                $out[(int) $r['leave_type_id']][$m]['pending'] = ($out[(int) $r['leave_type_id']][$m]['pending'] ?? 0) + ($lab === 'Half day' ? 0.5 : 1);
            }
        }
    }
    return $out;
}

/** Opening balance or a manual adjustment, by an authorised user, with a reason. */
function hr_leave_adjust(array $emp, int $typeId, int $year, string $kind, string $days, string $note): void
{
    if (!in_array($kind, ['opening', 'adjustment', 'encashed'], true)) {
        throw new InvalidArgumentException('Choose the kind of adjustment.');
    }
    $t = hr_leave_type($typeId);
    if (!$t) {
        throw new InvalidArgumentException('Choose a leave type.');
    }
    $d = (float) hr_dec($days, 2, true, 'Days', -366, 366);
    if ($d == 0.0) {
        throw new InvalidArgumentException('Days cannot be zero.');
    }
    if ($kind === 'encashed' && $d > 0) {
        $d = -$d;   // encashment always takes days out
    }
    $note = hr_str($note, 255, true, 'Reason');
    if ($kind === 'opening' && fabo_one("SELECT id FROM hr_leave_ledger WHERE employee_id = ? AND leave_type_id = ? AND leave_year = ?
                                         AND kind = 'opening'", 'iii', [(int) $emp['id'], $typeId, $year])) {
        throw new InvalidArgumentException('An opening balance for ' . $t['name'] . ' in ' . hr_leave_year_label($year)
                                         . ' is already recorded. Use a manual adjustment to change it.');
    }
    $seq = (int) (fabo_one('SELECT COALESCE(MAX(ref_id),0) m FROM hr_leave_ledger WHERE ref_type = "manual"')['m'] ?? 0) + 1;
    hr_ledger_post($emp, $typeId, $year, $kind, $d, date('Y-m-d'), 'manual', $seq, 0, $note);
    hr_audit('leave_adjust', 'employee', (int) $emp['id'], hr_ledger_kinds()[$kind] . ' ' . hr_fmt_days($d) . ' day(s) of '
           . $t['name'] . ' — ' . $note);
    hr_notify_employee((int) $emp['id'], 'leave-adjust-' . $seq, 'Your ' . $t['name'] . ' balance was updated',
        hr_ledger_kinds()[$kind] . ': ' . ($d > 0 ? '+' : '') . hr_fmt_days($d) . ' day(s). Reason: ' . $note);
}

function hr_leave_ledger_rows(int $empId, int $year): array
{
    return fabo_all('SELECT l.*, t.name AS type_name, t.code FROM hr_leave_ledger l JOIN hr_leave_types t ON t.id = l.leave_type_id
                     WHERE l.employee_id = ? AND l.leave_year = ? ORDER BY l.posted_on, l.id', 'ii', [$empId, $year]);
}

/* ------------------------------------------------------------ counting */

/**
 * Counts the leave days a request covers. Weekly offs and holidays are left
 * out unless the policy rule says they count (the "sandwich" rule).
 * Returns ['days' => float, 'detail' => [date => label], 'skipped' => int].
 */
function hr_leave_count(array $emp, ?array $rule, string $from, string $to, bool $startHalf, bool $endHalf): array
{
    $map = hr_day_map($emp, $from, $to);
    $days = 0.0;
    $detail = [];
    $skipped = 0;
    $countOff = $rule && $rule['count_weekly_offs'];
    $countHol = $rule && $rule['count_holidays'];
    foreach ($map as $d => $info) {
        if ($info['holiday'] && !$countHol) {
            $detail[$d] = 'Holiday — ' . $info['holiday'];
            $skipped++;
            continue;
        }
        if ($info['off'] && !$countOff) {
            $detail[$d] = 'Weekly off';
            $skipped++;
            continue;
        }
        $v = 1.0;
        if ($from === $to) {
            $v = ($startHalf || $endHalf) ? 0.5 : 1.0;
        } elseif ($d === $from && $startHalf) {
            $v = 0.5;
        } elseif ($d === $to && $endHalf) {
            $v = 0.5;
        }
        $days += $v;
        $detail[$d] = $v === 0.5 ? 'Half day' : 'Full day';
    }
    return ['days' => $days, 'detail' => $detail, 'skipped' => $skipped];
}

/* ------------------------------------------------------------- locking */

/** A date inside a locked attendance month or a finalized payroll month, or null. */
function hr_locked_reason(string $from, string $to): ?string
{
    for ($m = substr($from, 0, 7); $m <= substr($to, 0, 7); $m = date('Y-m', strtotime($m . '-01 +1 month'))) {
        $run = fabo_one('SELECT status FROM hr_payroll_runs WHERE org_id = ? AND period = ?', 'is', [hr_org(), $m]);
        if ($run && in_array($run['status'], ['finalized', 'payslips'], true)) {
            return 'Payroll for ' . date('F Y', strtotime($m . '-01')) . ' is finalized. Changes to that month go through a payroll adjustment.';
        }
        $lock = fabo_one('SELECT locked FROM hr_attendance_locks WHERE org_id = ? AND period = ?', 'is', [hr_org(), $m]);
        if ($lock && (int) $lock['locked'] === 1) {
            return 'Attendance for ' . date('F Y', strtotime($m . '-01')) . ' is locked. An administrator must reopen it first.';
        }
    }
    return null;
}

/* ------------------------------------------------------------ requests */

function hr_leave_request(int $id): ?array
{
    return fabo_one('SELECT r.*, t.name AS type_name, t.code AS type_code, t.is_paid, e.full_name, e.emp_code, e.manager_id
                       FROM hr_leave_requests r JOIN hr_leave_types t ON t.id = r.leave_type_id
                       JOIN hr_employees e ON e.id = r.employee_id
                      WHERE r.id = ? AND r.org_id = ?', 'ii', [$id, hr_org()]);
}

function hr_leave_statuses(): array
{
    return ['draft' => 'Draft', 'submitted' => 'Submitted', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'];
}

/**
 * Every rule a request must pass, in the order a person would want to hear
 * about them. Returns the computed days. $exceptId is the request being
 * edited, so it does not overlap or reserve against itself.
 */
function hr_leave_validate(array $emp, array $type, string $from, string $to, bool $startHalf, bool $endHalf,
                           int $exceptId = 0, bool $privileged = false, bool $hasAttachment = false,
                           ?string $session = null): array
{
    if ($to < $from) {
        throw new InvalidArgumentException('The end date is before the start date.');
    }
    if (hr_leave_year_of($from, (int) $emp['org_id']) !== hr_leave_year_of($to, (int) $emp['org_id'])) {
        [, $yEnd] = hr_leave_year_bounds(hr_leave_year_of($from, (int) $emp['org_id']), (int) $emp['org_id']);
        throw new InvalidArgumentException('A request cannot cross into the next leave year. Make one request up to '
                                         . hr_fmt_date($yEnd) . ' and another from the day after.');
    }
    if ((strtotime($to) - strtotime($from)) / 86400 > 366) {
        throw new InvalidArgumentException('A single request can cover at most one year.');
    }
    if (!in_array($emp['status'], hr_working_statuses(), true)) {
        throw new InvalidArgumentException($emp['full_name'] . ' is not a current employee.');
    }
    if ($emp['date_of_joining'] && $from < $emp['date_of_joining']) {
        throw new InvalidArgumentException('The leave starts before the date of joining (' . hr_fmt_date($emp['date_of_joining']) . ').');
    }
    if ($emp['date_of_leaving'] && $to > $emp['date_of_leaving']) {
        throw new InvalidArgumentException('The leave ends after the last working day (' . hr_fmt_date($emp['date_of_leaving']) . ').');
    }
    $rule = hr_emp_rule($emp, (int) $type['id'], $from);
    if (!$rule && !$type['unlimited']) {
        throw new InvalidArgumentException($type['name'] . ' is not part of the leave policy that applies to ' . $emp['full_name']
                                         . '. Add it to the policy under Policies & Rules → Leave first.');
    }
    if (($startHalf || $endHalf) && $rule && !$rule['allow_half_day']) {
        throw new InvalidArgumentException('Half-day ' . $type['name'] . ' is not allowed by the policy.');
    }
    $count = hr_leave_count($emp, $rule, $from, $to, $startHalf, $endHalf);
    $days  = $count['days'];
    if ($days <= 0) {
        throw new InvalidArgumentException('Every date in that range is a weekly off or a holiday, so there is nothing to apply for.');
    }
    if ($rule && $rule['max_consecutive'] !== null && $days > (float) $rule['max_consecutive'] + 0.001) {
        throw new InvalidArgumentException($type['name'] . ' can be taken for at most ' . hr_fmt_days($rule['max_consecutive'])
                                         . ' day(s) at a time; this request is ' . hr_fmt_days($days) . '.');
    }
    if ($rule && (int) $rule['min_service_days'] > 0 && $emp['date_of_joining']) {
        $eligible = date('Y-m-d', strtotime($emp['date_of_joining'] . ' +' . (int) $rule['min_service_days'] . ' day'));
        if ($from < $eligible) {
            throw new InvalidArgumentException($type['name'] . ' can be taken from ' . hr_fmt_date($eligible)
                                             . ' (after ' . (int) $rule['min_service_days'] . ' days of service).');
        }
    }
    if ($rule && !$rule['allow_in_probation'] && ($emp['status'] === 'probation'
            || ($emp['probation_end'] && $from <= $emp['probation_end']))) {
        throw new InvalidArgumentException($type['name'] . ' cannot be taken during probation.');
    }
    if (!$privileged) {
        if ($rule && $rule['min_notice_days'] !== null && (int) $rule['min_notice_days'] > 0) {
            $earliest = date('Y-m-d', strtotime('+' . (int) $rule['min_notice_days'] . ' day'));
            if ($from < $earliest) {
                throw new InvalidArgumentException($type['name'] . ' must be applied for at least ' . (int) $rule['min_notice_days']
                                                 . ' day(s) in advance.');
            }
        }
        $back = (int) hr_setting('leave_backdate_days');
        if ($from < date('Y-m-d', strtotime('-' . $back . ' day'))) {
            throw new InvalidArgumentException('Leave can be applied for at most ' . $back . ' day(s) in the past. Ask HR to record older leave.');
        }
    }
    if ($type['attachment_after_days'] !== null && $days > (float) $type['attachment_after_days'] && !$hasAttachment) {
        throw new InvalidArgumentException('A supporting document is required for ' . $type['name'] . ' longer than '
                                         . hr_fmt_days($type['attachment_after_days']) . ' day(s).');
    }
    // Overlap with any live request, half days included.
    foreach (fabo_all("SELECT * FROM hr_leave_requests WHERE employee_id = ? AND status IN ('submitted','approved')
                       AND start_date <= ? AND end_date >= ? AND id <> ?", 'issi', [(int) $emp['id'], $to, $from, $exceptId]) as $o) {
        $sameHalfOnly = $o['start_date'] === $o['end_date'] && $from === $to && $o['start_date'] === $from
                     && $o['half_session'] && $session && $o['half_session'] !== $session;
        if (!$sameHalfOnly) {
            throw new InvalidArgumentException('This overlaps another ' . strtolower(hr_leave_statuses()[$o['status']]) . ' request ('
                . hr_fmt_date($o['start_date']) . ($o['end_date'] !== $o['start_date'] ? ' – ' . hr_fmt_date($o['end_date']) : '') . ').');
        }
    }
    // Balance.
    if (!$type['unlimited']) {
        $bal = hr_leave_balances($emp, hr_leave_year_of($from, (int) $emp['org_id']))[(int) $type['id']] ?? null;
        $available = $bal ? (float) $bal['available'] : 0.0;
        if ($exceptId && $bal && hr_setting('leave_reserve_pending', (int) $emp['org_id']) === '1') {
            $own = fabo_one("SELECT days FROM hr_leave_requests WHERE id = ? AND status = 'submitted'", 'i', [$exceptId]);
            $available += $own ? (float) $own['days'] : 0;
        }
        $floor = ($rule && $rule['allow_negative']) ? -(float) $rule['negative_limit'] : 0.0;
        if ($available - $days < $floor - 0.001) {
            throw new InvalidArgumentException('Not enough ' . $type['name'] . ': ' . hr_fmt_days(max(0, $available)) . ' day(s) available, '
                . hr_fmt_days($days) . ' requested.' . ($floor < 0 ? ' The policy allows going ' . hr_fmt_days(-$floor) . ' day(s) below zero.' : '')
                . ' Choose fewer days or Leave Without Pay.');
        }
    }
    return ['days' => $days, 'detail' => $count['detail'], 'rule' => $rule];
}

/**
 * Creates a request (draft, or submitted at once). $p: employee_id,
 * leave_type_id, start_date, end_date, day_type ('full'|'first'|'second' for
 * one day), start_half, end_half, reason. Returns the new id.
 */
function hr_leave_create(array $p, bool $submit, ?array $file, bool $privileged): int
{
    $empId = (int) ($p['employee_id'] ?? 0);
    hr_need_emp($empId, 'leave', 'create');
    $emp  = hr_employee($empId);
    $type = hr_leave_type((int) ($p['leave_type_id'] ?? 0));
    if (!$type || !$type['active']) {
        throw new InvalidArgumentException('Choose a leave type.');
    }
    [$from, $to, $sh, $eh, $sess] = hr_leave_dates_from_post($p);
    $reason = hr_str($p['reason'] ?? '', 1000, $submit, 'Reason');

    $meta = null;
    if ($file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        [$ok, $msg, $meta] = fabo_store_upload($file);
        if (!$ok) {
            throw new InvalidArgumentException('Attachment: ' . $msg);
        }
    }
    try {
        if ($to < $from) {
            throw new InvalidArgumentException('The end date is before the start date.');
        }
        $chk = $submit ? hr_leave_validate($emp, $type, $from, $to, $sh, $eh, 0, $privileged, (bool) $meta, $sess)
                       : ['days' => hr_leave_count($emp, hr_emp_rule($emp, (int) $type['id'], $from), $from, $to, $sh, $eh)['days']];
        $chain  = hr_chain_resolve($emp, $type);
        $levels = count($chain);
        $id = hr_tx(function () use ($emp, $type, $from, $to, $sh, $eh, $sess, $chk, $reason, $meta, $submit, $levels, $chain) {
            $id = fabo_exec('INSERT INTO hr_leave_requests (org_id, employee_id, leave_type_id, start_date, end_date, start_half, end_half,
                             half_session, days, reason, attachment_file, attachment_name, attachment_mime, status, levels_needed,
                             approval_chain, submitted_at, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', 'iiissiisssssssisss',
                            [hr_org(), (int) $emp['id'], (int) $type['id'], $from, $to, $sh ? 1 : 0, $eh ? 1 : 0, $sess,
                             number_format($chk['days'], 2, '.', ''), $reason ?: null, $meta['stored_name'] ?? null,
                             $meta['original_name'] ?? null, $meta['mime'] ?? null, $submit ? 'submitted' : 'draft', $levels,
                             json_encode($chain), $submit ? date('Y-m-d H:i:s') : null, hr_actor()]);
            hr_leave_action($id, 0, $submit ? 'submitted' : 'draft', '');
            return $id;
        });
    } catch (Throwable $e) {
        if ($meta) {
            fabo_delete_stored($meta['stored_name']);
        }
        throw $e;
    }
    hr_audit($submit ? 'submit' : 'draft', 'leave', $id, $type['name'] . ' for ' . $emp['full_name'] . ', ' . $from . ' to ' . $to);
    if ($submit) {
        hr_leave_announce_submitted($id);
    }
    return $id;
}

/** Reads dates and halves from the form. Returns [from, to, startHalf, endHalf, session]. */
function hr_leave_dates_from_post(array $p): array
{
    $from = hr_date($p['start_date'] ?? '', true, 'Start date');
    $to   = hr_date($p['end_date'] ?? '', false, 'End date') ?? $from;
    $sh = $eh = false;
    $sess = null;
    if ($from === $to) {
        $dt = (string) ($p['day_type'] ?? 'full');
        if ($dt === 'first' || $dt === 'second') {
            $sh = true;
            $sess = $dt;
        }
    } else {
        $sh = !empty($p['start_half']);
        $eh = !empty($p['end_half']);
    }
    return [$from, $to, $sh, $eh, $sess];
}

function hr_leave_action(int $reqId, int $level, string $action, string $comment): void
{
    $ctx = hr_ctx();
    fabo_exec('INSERT INTO hr_leave_actions (request_id, level, action, actor_label, actor_person_id, comment) VALUES (?,?,?,?,?,?)',
              'iissis', [$reqId, $level, $action, $ctx['actor'], $ctx['is_admin'] ? null : (int) ($ctx['person']['id'] ?? 0),
                         $comment !== '' ? mb_substr($comment, 0, 500) : null]);
}

function hr_leave_actions(int $reqId): array
{
    return fabo_all('SELECT * FROM hr_leave_actions WHERE request_id = ? ORDER BY id', 'i', [$reqId]);
}

function hr_leave_submit(int $id, bool $privileged): void
{
    $r = hr_leave_request($id);
    if (!$r || $r['status'] !== 'draft') {
        throw new InvalidArgumentException('Only a draft can be submitted.');
    }
    hr_need_emp((int) $r['employee_id'], 'leave', 'create');
    $emp  = hr_employee((int) $r['employee_id']);
    $type = hr_leave_type((int) $r['leave_type_id']);
    if (trim((string) $r['reason']) === '') {
        throw new InvalidArgumentException('Add a reason before submitting.');
    }
    $chk = hr_leave_validate($emp, $type, $r['start_date'], $r['end_date'], (bool) $r['start_half'], (bool) $r['end_half'],
                             $id, $privileged, (bool) $r['attachment_file'], $r['half_session']);
    $chain = hr_chain_resolve($emp, $type);
    fabo_exec("UPDATE hr_leave_requests SET status = 'submitted', days = ?, submitted_at = NOW(), approval_chain = ?, levels_needed = ?,
               level_done = 0 WHERE id = ? AND status = 'draft'",
              'ssii', [number_format($chk['days'], 2, '.', ''), json_encode($chain), count($chain), $id]);
    hr_leave_action($id, 0, 'submitted', '');
    hr_audit('submit', 'leave', $id, 'Leave submitted');
    hr_leave_announce_submitted($id);
}

function hr_leave_announce_submitted(int $id): void
{
    $r = hr_leave_request($id);
    $chain = hr_leave_chain($r);
    $own = ($self = hr_self_emp()) && (int) $self['id'] === (int) $r['employee_id'];
    $what = hr_fmt_days($r['days']) . ' day(s) of ' . $r['type_name'] . ', ' . hr_fmt_date($r['start_date'])
          . ($r['end_date'] !== $r['start_date'] ? ' to ' . hr_fmt_date($r['end_date']) : '');
    hr_notify_employee((int) $r['employee_id'], 'leave-sent-' . $id, $own ? 'Leave request submitted' : 'Leave recorded for you',
        ($own ? 'Your request for ' : 'HR recorded a request for ') . $what . '.'
        . "\nIt needs " . count($chain) . ' approval(s): ' . implode(' → ', array_map(static fn($s) => $s['label'], $chain)) . '.'
        . "\nThe leave is granted only after every approval. You will be told at each step.",
        'hr.php?p=leave&id=' . $id);
    hr_leave_notify_step($id);
}

/**
 * Who may decide a request at its current step?
 *   - the person named at this step (the manager, a specific person);
 *   - for an HR step, anyone holding organisation-wide leave approval;
 *   - the administrator at their own step, and — when "The administrator can
 *     approve at any step" is on — at any step, as the final decision.
 * Nobody decides their own request.
 * Returns 'final', 'level' or null.
 */
function hr_leave_can_decide(array $r): ?string
{
    if ($r['status'] !== 'submitted') {
        return null;
    }
    $step = hr_leave_step($r);
    if (hr_is_admin()) {
        if (!$step || $step['type'] === 'admin') {
            return 'level';
        }
        return hr_setting('leave_admin_override') === '1' ? 'final' : null;
    }
    $self = hr_self_emp();
    if (!$step || !hr_can('leave', 'approve') || ($self && (int) $self['id'] === (int) $r['employee_id'])) {
        return null;
    }
    if ($step['type'] === 'person') {
        return $self && (int) $self['id'] === (int) $step['employee_id'] ? 'level' : null;
    }
    if ($step['type'] === 'hr') {
        return hr_scope('leave', 'approve') === 'org' && hr_emp_allowed((int) $r['employee_id'], 'leave', 'approve') ? 'level' : null;
    }
    return null;
}

/**
 * Approves or rejects. On the final approval the leave is posted to the
 * ledger inside one transaction, with the request row locked, so two people
 * pressing Approve at once deduct it once.
 */
function hr_leave_decide(int $id, string $decision, string $comment): string
{
    if (!in_array($decision, ['approve', 'reject'], true)) {
        throw new InvalidArgumentException('Choose approve or reject.');
    }
    $comment = hr_str($comment, 500, $decision === 'reject', 'A reason for rejecting');
    $r = hr_leave_request($id);
    if (!$r) {
        throw new InvalidArgumentException('That request no longer exists.');
    }
    $how = hr_leave_can_decide($r);
    if ($how === null) {
        if ($r['status'] !== 'submitted') {
            throw new InvalidArgumentException('This request has already been ' . strtolower(hr_leave_statuses()[$r['status']] ?? $r['status']) . '.');
        }
        fabo_deny('You cannot decide this request.', 403);
    }
    $result = hr_tx(function () use ($id, $decision, $comment, $how) {
        $r = fabo_one('SELECT * FROM hr_leave_requests WHERE id = ? AND org_id = ? FOR UPDATE', 'ii', [$id, hr_org()]);
        if (!$r || $r['status'] !== 'submitted') {
            throw new InvalidArgumentException('This request was decided by someone else a moment ago. Reload to see the outcome.');
        }
        $level = (int) $r['level_done'] + 1;
        if ($decision === 'reject') {
            fabo_exec("UPDATE hr_leave_requests SET status = 'rejected', decided_at = NOW(), decision_comment = ? WHERE id = ?",
                      'si', [$comment, $id]);
            hr_leave_action($id, $level, 'rejected', $comment);
            return 'rejected';
        }
        $final = $how === 'final' || $level >= (int) $r['levels_needed'];
        if (!$final) {
            fabo_exec('UPDATE hr_leave_requests SET level_done = ? WHERE id = ?', 'ii', [$level, $id]);
            hr_leave_action($id, $level, 'approved', $comment);
            return 'level';
        }
        if ($why = hr_locked_reason($r['start_date'], $r['end_date'])) {
            throw new InvalidArgumentException($why);
        }
        $emp  = hr_employee((int) $r['employee_id']);
        $type = hr_leave_type((int) $r['leave_type_id']);
        // Re-check the balance now, without this request's own hold.
        $chk = hr_leave_validate($emp, $type, $r['start_date'], $r['end_date'], (bool) $r['start_half'], (bool) $r['end_half'],
                                 $id, true, true, $r['half_session']);
        $days = $chk['days'];
        $year = hr_leave_year_of($r['start_date'], (int) $emp['org_id']);
        $posted = hr_ledger_post($emp, (int) $type['id'], $year, 'taken', -$days, $r['start_date'], 'leave', $id, (int) $r['version'],
                                 $type['name'] . ' ' . $r['start_date'] . ' to ' . $r['end_date']);
        if (!$posted) {
            throw new RuntimeException('This leave was already deducted. Nothing was changed.');
        }
        fabo_exec("UPDATE hr_leave_requests SET status = 'approved', level_done = ?, days = ?, decided_at = NOW(), decision_comment = ?
                   WHERE id = ?", 'issi', [max($level, (int) $r['levels_needed']), number_format($days, 2, '.', ''), $comment ?: null, $id]);
        hr_leave_action($id, $level, 'approved', $comment);
        return 'approved';
    });
    hr_audit($result === 'rejected' ? 'reject' : 'approve', 'leave', $id,
             'Leave ' . ($result === 'level' ? 'approved at level ' . ((int) $r['level_done'] + 1) : $result) . ($comment ? ': ' . $comment : ''));
    $what = $r['type_name'] . ', ' . hr_fmt_date($r['start_date']) . ($r['end_date'] !== $r['start_date'] ? ' to ' . hr_fmt_date($r['end_date']) : '');
    if ($result === 'approved') {
        hr_notify_employee((int) $r['employee_id'], 'leave-ok-' . $id . '-v' . $r['version'], 'Leave approved',
            'Your ' . $what . ' (' . hr_fmt_days($r['days']) . ' day(s)) was approved.' . ($comment ? ' Comment: ' . $comment : ''));
    } elseif ($result === 'rejected') {
        hr_notify_employee((int) $r['employee_id'], 'leave-no-' . $id, 'Leave rejected',
            'Your ' . $what . ' was not approved. Reason: ' . $comment);
    } else {
        $chain = hr_leave_chain($r);
        $done = (int) $r['level_done'] + 1;
        $next = $chain[$done] ?? null;
        hr_notify_employee((int) $r['employee_id'], 'leave-step-ok-' . $id . '-' . $done . '-v' . $r['version'], 'Leave approved at step ' . $done . ' of ' . count($chain),
            'Your ' . $what . ' was approved by ' . hr_actor() . '.' . ($next ? ' It now waits for ' . $next['label'] . '.' : '')
            . ' The leave is granted only after the last approval.', 'hr.php?p=leave&id=' . $id);
        hr_leave_notify_step($id);
    }
    return $result;
}

/**
 * Cancels a request. A draft or a submitted one simply closes. An approved
 * one returns its days to the balance with a reversal row — once — and only
 * while its month is still open.
 */
function hr_leave_cancel(int $id, string $comment, bool $privileged): void
{
    $r = hr_leave_request($id);
    if (!$r) {
        throw new InvalidArgumentException('That request no longer exists.');
    }
    $own = ($self = hr_self_emp()) && (int) $self['id'] === (int) $r['employee_id'];
    if (!$privileged) {
        if (!$own || !hr_can('leave', 'edit')) {
            fabo_deny('You cannot cancel this request.', 403);
        }
        if ($r['status'] === 'approved' && $r['start_date'] <= date('Y-m-d')) {
            throw new InvalidArgumentException('Leave that has already started can only be cancelled by HR.');
        }
    } else {
        hr_need_emp((int) $r['employee_id'], 'leave', hr_can('leave', 'approve') ? 'approve' : 'edit');
    }
    if (!in_array($r['status'], ['draft', 'submitted', 'approved'], true)) {
        throw new InvalidArgumentException('This request is already ' . strtolower(hr_leave_statuses()[$r['status']]) . '.');
    }
    $comment = hr_str($comment, 500, $r['status'] === 'approved', 'A reason for cancelling');
    hr_tx(function () use ($id, $comment) {
        $r = fabo_one('SELECT * FROM hr_leave_requests WHERE id = ? AND org_id = ? FOR UPDATE', 'ii', [$id, hr_org()]);
        if (!$r || !in_array($r['status'], ['draft', 'submitted', 'approved'], true)) {
            throw new InvalidArgumentException('This request changed a moment ago. Reload to see it.');
        }
        if ($r['status'] === 'approved') {
            if ($why = hr_locked_reason($r['start_date'], $r['end_date'])) {
                throw new InvalidArgumentException($why);
            }
            $emp = hr_employee((int) $r['employee_id']);
            hr_ledger_post($emp, (int) $r['leave_type_id'], hr_leave_year_of($r['start_date'], (int) $emp['org_id']), 'reversal',
                           (float) $r['days'], date('Y-m-d'), 'leave', $id, (int) $r['version'], 'Cancelled: ' . $comment);
        }
        fabo_exec("UPDATE hr_leave_requests SET status = 'cancelled', decided_at = NOW(), decision_comment = ? WHERE id = ?",
                  'si', [$comment ?: null, $id]);
        hr_leave_action($id, 0, 'cancelled', $comment);
    });
    hr_audit('cancel', 'leave', $id, 'Leave cancelled' . ($comment ? ': ' . $comment : ''), $r, null);
    if (!$own) {
        hr_notify_employee((int) $r['employee_id'], 'leave-cx-' . $id, 'Leave cancelled',
            'Your ' . $r['type_name'] . ' from ' . hr_fmt_date($r['start_date']) . ' was cancelled.' . ($comment ? ' Reason: ' . $comment : ''));
    }
}

/**
 * Corrects an approved request (different dates or halves). The old days
 * come back with a reversal, the new days go out under the next version —
 * both in one transaction, so the balance is never counted with both or
 * neither.
 */
function hr_leave_correct(int $id, array $p, string $comment): void
{
    hr_need('leave', 'approve');
    $r = hr_leave_request($id);
    if (!$r || $r['status'] !== 'approved') {
        throw new InvalidArgumentException('Only an approved request can be corrected. Cancel or edit others instead.');
    }
    hr_need_emp((int) $r['employee_id'], 'leave', 'approve');
    $comment = hr_str($comment, 500, true, 'A reason for the correction');
    [$from, $to, $sh, $eh, $sess] = hr_leave_dates_from_post($p);
    hr_tx(function () use ($id, $from, $to, $sh, $eh, $sess, $comment) {
        $r = fabo_one('SELECT * FROM hr_leave_requests WHERE id = ? AND org_id = ? FOR UPDATE', 'ii', [$id, hr_org()]);
        if (!$r || $r['status'] !== 'approved') {
            throw new InvalidArgumentException('This request changed a moment ago. Reload to see it.');
        }
        foreach ([[$r['start_date'], $r['end_date']], [$from, $to]] as [$a, $b]) {
            if ($why = hr_locked_reason($a, $b)) {
                throw new InvalidArgumentException($why);
            }
        }
        $emp  = hr_employee((int) $r['employee_id']);
        $type = hr_leave_type((int) $r['leave_type_id']);
        $year = hr_leave_year_of($r['start_date'], (int) $emp['org_id']);
        // Return the old days first, so the new request is checked against the true balance.
        hr_ledger_post($emp, (int) $type['id'], $year, 'reversal', (float) $r['days'], date('Y-m-d'), 'leave', $id, (int) $r['version'],
                       'Corrected: ' . $comment);
        $chk  = hr_leave_validate($emp, $type, $from, $to, $sh, $eh, $id, true, true, $sess);
        $ver  = (int) $r['version'] + 1;
        $year2 = hr_leave_year_of($from, (int) $emp['org_id']);
        hr_ledger_post($emp, (int) $type['id'], $year2, 'taken', -$chk['days'], $from, 'leave', $id, $ver,
                       $type['name'] . ' ' . $from . ' to ' . $to . ' (corrected)');
        fabo_exec('UPDATE hr_leave_requests SET start_date = ?, end_date = ?, start_half = ?, end_half = ?, half_session = ?, days = ?,
                   version = ? WHERE id = ?', 'ssiissii',
                  [$from, $to, $sh ? 1 : 0, $eh ? 1 : 0, $sess, number_format($chk['days'], 2, '.', ''), $ver, $id]);
        hr_leave_action($id, 0, 'corrected', $comment . ' (was ' . $r['start_date'] . ' – ' . $r['end_date'] . ', ' . hr_fmt_days($r['days']) . ' d)');
    });
    hr_audit('correct', 'leave', $id, 'Approved leave corrected: ' . $comment, $r, ['start_date' => $from, 'end_date' => $to]);
    hr_notify_employee((int) $r['employee_id'], 'leave-fix-' . $id . '-' . time(), 'Leave corrected',
        'Your ' . $r['type_name'] . ' was corrected to ' . hr_fmt_date($from) . ($to !== $from ? ' – ' . hr_fmt_date($to) : '') . '. Reason: ' . $comment);
}

/** Requests, filtered and scoped. Returns [rows, total]. */
function hr_leave_list(array $f, int $offset = 0, int $limit = 50): array
{
    $where = ' WHERE r.org_id = ?';
    $types = 'i';
    $params = [hr_org()];
    if (!empty($f['status']) && isset(hr_leave_statuses()[$f['status']])) {
        $where .= ' AND r.status = ?';
        $types .= 's';
        $params[] = $f['status'];
    }
    foreach (['employee' => 'r.employee_id', 'type' => 'r.leave_type_id', 'department' => 'e.department_id'] as $k => $col) {
        if (!empty($f[$k])) {
            $where .= ' AND ' . $col . ' = ?';
            $types .= 'i';
            $params[] = (int) $f[$k];
        }
    }
    if (!empty($f['from'])) {
        $where .= ' AND r.end_date >= ?';
        $types .= 's';
        $params[] = $f['from'];
    }
    if (!empty($f['to'])) {
        $where .= ' AND r.start_date <= ?';
        $types .= 's';
        $params[] = $f['to'];
    }
    if (($q = trim((string) ($f['q'] ?? ''))) !== '') {
        $where .= ' AND (e.full_name LIKE ? OR e.emp_code LIKE ?)';
        $types .= 'ss';
        $like = '%' . $q . '%';
        array_push($params, $like, $like);
    }
    $sc = hr_scope_sql('leave', 'view', 'e');
    $where .= $sc['sql'];
    $from = ' FROM hr_leave_requests r JOIN hr_employees e ON e.id = r.employee_id JOIN hr_leave_types t ON t.id = r.leave_type_id
              LEFT JOIN hr_departments d ON d.id = e.department_id';
    $total = (int) (fabo_one('SELECT COUNT(*) n' . $from . $where, $types, $params)['n'] ?? 0);
    $rows = fabo_all('SELECT r.*, e.full_name, e.emp_code, e.manager_id, t.name AS type_name, t.code AS type_code, t.color, t.is_paid,
                             d.name AS department' . $from . $where . ' ORDER BY (r.status = "submitted") DESC, r.start_date DESC, r.id DESC
                      LIMIT ' . max(0, $offset) . ', ' . max(1, min(5000, $limit)), $types, $params);
    return [$rows, $total];
}

/** Submitted requests the viewer can decide now — including ones where they are named outside their usual scope. */
function hr_leave_awaiting_me(): array
{
    if (!hr_is_admin() && !hr_can('leave', 'approve')) {
        return [];
    }
    $rows = fabo_all("SELECT r.*, e.full_name, e.emp_code, e.manager_id, t.name AS type_name, t.code AS type_code, t.color, t.is_paid,
                             d.name AS department
                        FROM hr_leave_requests r JOIN hr_employees e ON e.id = r.employee_id JOIN hr_leave_types t ON t.id = r.leave_type_id
                        LEFT JOIN hr_departments d ON d.id = e.department_id
                       WHERE r.org_id = ? AND r.status = 'submitted' ORDER BY r.start_date, r.id LIMIT 1000", 'i', [hr_org()]);
    return array_values(array_filter($rows, static fn($r) => hr_leave_can_decide($r) !== null));
}

/** Approved leave per date for a set of employees: emp_id => date => [type, half, paid]. */
function hr_approved_leave_days(array $empIds, string $from, string $to): array
{
    $empIds = array_values(array_filter(array_map('intval', $empIds)));
    if (!$empIds) {
        return [];
    }
    $out = [];
    $rows = fabo_all("SELECT r.*, t.code, t.name, t.is_paid FROM hr_leave_requests r JOIN hr_leave_types t ON t.id = r.leave_type_id
                      WHERE r.status = 'approved' AND r.employee_id IN (" . implode(',', $empIds) . ") AND r.start_date <= ? AND r.end_date >= ?",
                     'ss', [$to, $from]);
    $emps = [];
    foreach ($rows as $r) {
        $eid = (int) $r['employee_id'];
        $emps[$eid] ??= fabo_one('SELECT * FROM hr_employees WHERE id = ?', 'i', [$eid]);
        $rule = hr_emp_rule($emps[$eid], (int) $r['leave_type_id'], $r['start_date']);
        $cnt  = hr_leave_count($emps[$eid], $rule, $r['start_date'], $r['end_date'], (bool) $r['start_half'], (bool) $r['end_half']);
        foreach ($cnt['detail'] as $d => $lab) {
            if ($d < $from || $d > $to || !in_array($lab, ['Full day', 'Half day'], true)) {
                continue;
            }
            $out[$eid][$d] = ['code' => $r['code'], 'name' => $r['name'], 'half' => $lab === 'Half day', 'paid' => (int) $r['is_paid'],
                              'request_id' => (int) $r['id']];
        }
    }
    return $out;
}

/* ------------------------------------------------------------ year end */

/**
 * What closing a leave year would do, per employee and type: the closing
 * balance, how much carries forward, how much lapses, and what could be
 * encashed. Nothing is posted.
 */
function hr_leave_year_close_preview(int $year): array
{
    [, $yTo] = hr_leave_year_bounds($year);
    $out = [];
    foreach (fabo_all('SELECT * FROM hr_employees WHERE org_id = ? AND (date_of_leaving IS NULL OR date_of_leaving > ?)
                       ORDER BY full_name', 'is', [hr_org(), $yTo]) as $emp) {
        foreach (hr_leave_balances($emp, $year) as $tid => $b) {
            if ($b['type']['unlimited']) {
                continue;
            }
            $rule = hr_emp_rule($emp, $tid, $yTo);
            $close = round($b['balance'], 2);
            $carry = 0.0;
            if ($close < 0) {
                $carry = $close;                         // a negative balance follows the employee
            } elseif ($rule && $rule['carry_forward']) {
                $carry = $rule['carry_limit'] !== null ? min($close, (float) $rule['carry_limit']) : $close;
            }
            $lapse = $close > 0 ? round($close - max(0, $carry), 2) : 0.0;
            $done = (bool) fabo_one("SELECT id FROM hr_leave_ledger WHERE employee_id = ? AND leave_type_id = ? AND ref_type = 'year_close'
                                     AND ref_id = ?", 'iii', [(int) $emp['id'], $tid, $year]);
            $out[] = ['emp' => $emp, 'type' => $b['type'], 'closing' => $close, 'carry' => $carry, 'lapse' => $lapse,
                      'encashable' => ($rule && $rule['encashable']) ? $lapse : 0.0, 'pending' => $b['pending'], 'done' => $done];
        }
    }
    return $out;
}

/** Posts the year-end carry-forward and lapse. Idempotent per employee and type. */
function hr_leave_year_close(int $year): array
{
    [, $yTo] = hr_leave_year_bounds($year);
    if ($yTo >= date('Y-m-d')) {
        throw new InvalidArgumentException('Leave year ' . hr_leave_year_label($year) . ' has not ended yet (it ends ' . hr_fmt_date($yTo) . ').');
    }
    $posted = 0;
    $skippedPending = 0;
    $nextFrom = date('Y-m-d', strtotime($yTo . ' +1 day'));
    foreach (hr_leave_year_close_preview($year) as $row) {
        if ($row['done']) {
            continue;
        }
        if ($row['pending'] > 0) {
            $skippedPending++;
            continue;      // decide pending requests first, or the closing balance is not final
        }
        hr_tx(function () use ($row, $year, $yTo, $nextFrom, &$posted) {
            $tid = (int) $row['type']['id'];
            if ($row['lapse'] > 0) {
                hr_ledger_post($row['emp'], $tid, $year, 'lapsed', -$row['lapse'], $yTo, 'year_close', $year, 0,
                               'Not carried forward at the end of ' . hr_leave_year_label($year));
            }
            hr_ledger_post($row['emp'], $tid, $year, 'carried_out', -$row['carry'], $yTo, 'year_close', $year, 1,
                           'Moved to ' . hr_leave_year_label($year + 1));
            if ($row['carry'] != 0.0) {
                hr_ledger_post($row['emp'], $tid, $year + 1, 'carry_forward', $row['carry'], $nextFrom, 'year_close', $year, 0,
                               'Carried from ' . hr_leave_year_label($year));
            }
            $posted++;
        });
    }
    hr_audit('year_close', 'leave', null, 'Leave year ' . hr_leave_year_label($year) . ' closed: ' . $posted . ' balances carried'
           . ($skippedPending ? ', ' . $skippedPending . ' waiting on pending requests' : ''));
    return [$posted, $skippedPending];
}

/** Grants a compensatory off from a worked holiday or weekly off. */
function hr_comp_off_grant(int $attendanceId, string $days, string $note): void
{
    $a = fabo_one('SELECT * FROM hr_attendance WHERE id = ? AND org_id = ?', 'ii', [$attendanceId, hr_org()]);
    if (!$a) {
        throw new InvalidArgumentException('That attendance record no longer exists.');
    }
    hr_need_emp((int) $a['employee_id'], 'leave', 'approve');
    if ($a['comp_off_ledger_id']) {
        throw new InvalidArgumentException('A compensatory off was already granted for this day.');
    }
    $type = fabo_one('SELECT * FROM hr_leave_types WHERE org_id = ? AND is_comp_off = 1 AND active = 1 ORDER BY id LIMIT 1', 'i', [hr_org()]);
    if (!$type) {
        throw new InvalidArgumentException('There is no active leave type marked “Compensatory off”. Add one under Policies & Rules → Leave.');
    }
    $d = (float) hr_dec($days, 1, true, 'Days', 0.5, 1);
    $emp = hr_employee((int) $a['employee_id']);
    hr_tx(function () use ($emp, $type, $a, $d, $note, $attendanceId) {
        $ok = hr_ledger_post($emp, (int) $type['id'], hr_leave_year_of($a['work_date'], (int) $emp['org_id']), 'comp_off', $d, $a['work_date'],
                             'attendance', $attendanceId, 0, 'Worked on ' . hr_fmt_date($a['work_date']) . ($note ? ' — ' . $note : ''));
        if (!$ok) {
            throw new InvalidArgumentException('A compensatory off was already granted for this day.');
        }
        $lid = (int) (fabo_one("SELECT id FROM hr_leave_ledger WHERE ref_type = 'attendance' AND ref_id = ? AND kind = 'comp_off'", 'i', [$attendanceId])['id'] ?? 0);
        fabo_exec("UPDATE hr_attendance SET comp_off_ledger_id = ?, status = 'Compensatory Off Earned' WHERE id = ?", 'ii', [$lid, $attendanceId]);
    });
    hr_audit('comp_off', 'attendance', $attendanceId, 'Compensatory off granted: ' . $d . ' day(s)');
    hr_notify_employee((int) $emp['id'], 'compoff-' . $attendanceId, 'Compensatory off credited',
        hr_fmt_days($d) . ' day(s) of ' . $type['name'] . ' for working on ' . hr_fmt_date($a['work_date']) . '.');
}

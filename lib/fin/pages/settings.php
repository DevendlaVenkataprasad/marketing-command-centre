<?php
/**
 * Finance settings: policies and thresholds, who holds which finance role,
 * what each role may do, the approval rules, delegations, the masters
 * (entities, cost centres, projects, categories, exchange rates), and the
 * integrations with the rest of the portal — with what each needs.
 */

fp_need('settings.manage');
$tabs = ['general' => 'Policies', 'roles' => 'People & roles', 'matrix' => 'Permissions', 'rules' => 'Approval rules', 'delegations' => 'Delegations', 'masters' => 'Masters', 'integrations' => 'Integrations'];
$tab = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'general';
$back = fp_url('settings', ['tab' => $tab]);
$flash = null;
$posted = (bool) fp_one('SELECT id FROM fp_journals WHERE status = "posted" LIMIT 1');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    $flash = fp_action(static function () use ($a, $posted) {
        switch ($a) {
            case 'general':
                $before = [];
                foreach (array_keys(fp_setting_defaults()) as $k) {
                    $before[$k] = fp_setting($k);
                }
                $num = static function (string $k, float $min, float $max, bool $blank = false) {
                    $v = trim((string) ($_POST[$k] ?? ''));
                    if ($v === '' && $blank) {
                        return '';
                    }
                    if (!is_numeric($v) || (float) $v < $min || (float) $v > $max) {
                        throw new InvalidArgumentException(str_replace('_', ' ', $k) . ' must be between ' . $min . ' and ' . $max . '.');
                    }
                    return (string) (float) $v;
                };
                if (!$posted) {
                    fp_setting_put('fy_start_month', (string) max(1, min(12, (int) ($_POST['fy_start_month'] ?? 4))));
                    fp_setting_put('base_currency', fp_currency($_POST['base_currency'] ?? 'INR'));
                }
                fp_setting_put('sod_strict', !empty($_POST['sod_strict']) ? '1' : '0');
                fp_setting_put('budget_control', ($_POST['budget_control'] ?? '') === 'block' ? 'block' : 'warn');
                fp_setting_put('budget_warn_pct', $num('budget_warn_pct', 1, 200));
                fp_setting_put('budget_block_pct', $num('budget_block_pct', 1, 300));
                fp_setting_put('match_price_tol_pct', $num('match_price_tol_pct', 0, 50));
                fp_setting_put('match_qty_tol_pct', $num('match_qty_tol_pct', 0, 50));
                fp_setting_put('dup_window_days', $num('dup_window_days', 1, 365));
                fp_setting_put('claim_receipt_above', $num('claim_receipt_above', 0, 1e9, true));
                fp_setting_put('claim_backdate_days', $num('claim_backdate_days', 0, 3650));
                fp_setting_put('mileage_rate', $num('mileage_rate', 0, 1000, true));
                fp_setting_put('per_diem_rate', $num('per_diem_rate', 0, 1e6, true));
                fp_setting_put('escalate_hours', $num('escalate_hours', 1, 2000));
                $days = implode(',', array_values(array_filter(array_map('intval', explode(',', (string) ($_POST['ar_reminder_days'] ?? ''))), static fn($d) => $d > 0 && $d < 366)));
                fp_setting_put('ar_reminder_days', $days);
                fp_setting_put('ar_credit_control', ($_POST['ar_credit_control'] ?? '') === 'block' ? 'block' : 'warn');
                fp_setting_put('retention_years', $num('retention_years', 1, 30));
                fp_setting_put('mail_notify', !empty($_POST['mail_notify']) ? '1' : '0');
                $after = [];
                foreach (array_keys(fp_setting_defaults()) as $k) {
                    $after[$k] = fp_setting($k);
                }
                fp_audit('settings', null, 'update', 'Finance policies changed', $before, $after);
                return 'Settings saved.';
            case 'role_add':
                $pid = (int) ($_POST['person_id'] ?? 0);
                $role = (string) ($_POST['role'] ?? '');
                if (!fp_is_member($pid) || $pid === 0) {
                    throw new InvalidArgumentException('Choose someone in your organisation.');
                }
                if (!isset(fp_roles()[$role]) || $role === 'employee') {
                    throw new InvalidArgumentException('Choose a role.');
                }
                $cc = fp_ref($_POST['cost_centre_id'] ?? null, 'fp_cost_centres', 'cost centre');
                if (fp_one('SELECT id FROM fp_role_members WHERE person_id = ? AND role = ? AND (cost_centre_id <=> ?)', 'isi', [$pid, $role, $cc])) {
                    throw new InvalidArgumentException('They already hold that role.');
                }
                $id = fp_exec('INSERT INTO fp_role_members (person_id, role, cost_centre_id, added_by) VALUES (?,?,?,?)', 'isis', [$pid, $role, $cc, fp_actor()]);
                fp_audit('role', $id, 'grant', fp_person_name($pid) . ' given the ' . fp_roles()[$role] . ' role' . ($cc ? ' for cost centre #' . $cc : ''));
                fp_notify([$pid], 'You have a finance role', 'You are now ' . fp_roles()[$role] . ' in Finance.', 'dashboard');
                return fp_person_name($pid) . ' is now ' . fp_roles()[$role] . '.';
            case 'role_remove':
                $r = fp_one('SELECT * FROM fp_role_members WHERE id = ?', 'i', [(int) ($_POST['id'] ?? 0)]);
                if (!$r) {
                    throw new InvalidArgumentException('Already removed.');
                }
                fp_exec('DELETE FROM fp_role_members WHERE id = ?', 'i', [(int) $r['id']]);
                fp_audit('role', (int) $r['id'], 'revoke', fp_person_name((int) $r['person_id']) . ' no longer ' . (fp_roles()[$r['role']] ?? $r['role']));
                return 'Role removed.';
            case 'matrix':
                $m = [];
                foreach (array_keys(fp_roles()) as $role) {
                    if ($role === 'sysadmin') {
                        continue;
                    }
                    $m[$role] = array_values(array_intersect(array_keys((array) ($_POST['perm'][$role] ?? [])), array_keys(fp_permissions())));
                }
                $before = fp_matrix();
                fp_setting_put('perm_matrix', json_encode($m));
                fp_audit('settings', null, 'permissions', 'Role permissions changed', $before, fp_matrix());
                return 'Permissions saved.';
            case 'matrix_reset':
                fp_setting_put('perm_matrix', '');
                fp_audit('settings', null, 'permissions', 'Role permissions reset to the defaults');
                return 'Permissions reset to the defaults.';
            case 'rule':
                fp_wf_rule_save(inc_int_or_null($_POST['id'] ?? null), $_POST);
                return 'Approval rule saved. It applies to documents submitted from now on.';
            case 'delegate':
                fp_delegation_save($_POST);
                return 'Delegation saved.';
            case 'entity':
                $id = inc_int_or_null($_POST['id'] ?? null);
                $code = strtoupper(fp_str($_POST['code'] ?? '', 20, true, 'Code'));
                $vals = [$code, fp_str($_POST['name'] ?? '', 190, true, 'Name'), strtoupper(fp_str($_POST['gstin'] ?? '', 20)) ?: null, strtoupper(fp_str($_POST['pan'] ?? '', 12)) ?: null,
                         fp_str($_POST['state'] ?? '', 60) ?: null, fp_str($_POST['address'] ?? '', 500) ?: null];
                fp_check_gstin((string) $vals[2]);
                if (fp_one('SELECT id FROM fp_entities WHERE code = ? AND id <> ?', 'si', [$code, (int) $id])) {
                    throw new InvalidArgumentException('Entity code ' . $code . ' is used.');
                }
                if ($id) {
                    fp_exec('UPDATE fp_entities SET code=?, name=?, gstin=?, pan=?, state=?, address=? WHERE id=?', 'ssssssi', array_merge($vals, [$id]));
                } else {
                    $id = fp_exec('INSERT INTO fp_entities (code, name, gstin, pan, state, address, base_currency) VALUES (?,?,?,?,?,?,?)', 'sssssss', array_merge($vals, [fp_setting('base_currency')]));
                }
                fp_audit('entity', $id, 'save', 'Entity ' . $code . ' saved');
                return 'Entity saved.';
            case 'cc':
                $id = inc_int_or_null($_POST['id'] ?? null);
                $code = strtoupper(fp_str($_POST['code'] ?? '', 20, true, 'Code'));
                $mgr = (int) ($_POST['manager_person_id'] ?? 0) ?: null;
                if ($mgr !== null && !fp_is_member($mgr)) {
                    throw new InvalidArgumentException('Choose the budget holder from your organisation.');
                }
                if (fp_one('SELECT id FROM fp_cost_centres WHERE code = ? AND id <> ?', 'si', [$code, (int) $id])) {
                    throw new InvalidArgumentException('Cost centre ' . $code . ' exists.');
                }
                $active = !isset($_POST['active']) || !empty($_POST['active']) ? 1 : 0;
                if ($id) {
                    fp_exec('UPDATE fp_cost_centres SET code=?, name=?, manager_person_id=?, active=? WHERE id=?', 'ssiii', [$code, fp_str($_POST['name'] ?? '', 120, true, 'Name'), $mgr, $active, $id]);
                } else {
                    $id = fp_exec('INSERT INTO fp_cost_centres (code, name, manager_person_id) VALUES (?,?,?)', 'ssi', [$code, fp_str($_POST['name'] ?? '', 120, true, 'Name'), $mgr]);
                }
                fp_audit('cost_centre', $id, 'save', 'Cost centre ' . $code . ' saved' . ($mgr ? ', budget holder ' . fp_person_name($mgr) : ''));
                return 'Cost centre saved.';
            case 'cc_import':
                try {
                    $deps = fabo_all('SELECT * FROM hr_departments WHERE org_id = ? AND active = 1', 'i', [fp_org()]);
                } catch (Throwable $e) {
                    throw new InvalidArgumentException('HR is not in use here, so there are no departments to bring in.');
                }
                $n = 0;
                foreach ($deps as $d) {
                    if (fp_one('SELECT id FROM fp_cost_centres WHERE hr_department_id = ?', 'i', [(int) $d['id']])) {
                        continue;
                    }
                    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($d['code'] ?: mb_substr($d['name'], 0, 6)))) ?: 'D' . $d['id'];
                    while (fp_one('SELECT id FROM fp_cost_centres WHERE code = ?', 's', [$code])) {
                        $code .= 'X';
                    }
                    fp_exec('INSERT INTO fp_cost_centres (code, name, hr_department_id) VALUES (?,?,?)', 'ssi', [mb_substr($code, 0, 20), mb_substr($d['name'], 0, 120), (int) $d['id']]);
                    $n++;
                }
                fp_audit('cost_centre', null, 'import', $n . ' cost centre(s) brought in from HR departments');
                return $n ? $n . ' HR department(s) brought in as cost centres. Name each one\'s budget holder.' : 'Every HR department is already a cost centre.';
            case 'project':
                $id = inc_int_or_null($_POST['id'] ?? null);
                $code = strtoupper(fp_str($_POST['code'] ?? '', 20, true, 'Code'));
                $vals = [$code, fp_str($_POST['name'] ?? '', 160, true, 'Name'), fp_ref($_POST['cost_centre_id'] ?? null, 'fp_cost_centres', 'cost centre'),
                         fp_date($_POST['start_date'] ?? '', false, 'Start'), fp_date($_POST['end_date'] ?? '', false, 'End'), ($_POST['status'] ?? '') === 'closed' ? 'closed' : 'active'];
                if (fp_one('SELECT id FROM fp_projects WHERE code = ? AND id <> ?', 'si', [$code, (int) $id])) {
                    throw new InvalidArgumentException('Project ' . $code . ' exists.');
                }
                if ($id) {
                    fp_exec('UPDATE fp_projects SET code=?, name=?, cost_centre_id=?, start_date=?, end_date=?, status=? WHERE id=?', 'ssisssi', array_merge($vals, [$id]));
                } else {
                    $id = fp_exec('INSERT INTO fp_projects (code, name, cost_centre_id, start_date, end_date, status) VALUES (?,?,?,?,?,?)', 'ssisss', $vals);
                }
                fp_audit('project', $id, 'save', 'Project ' . $code . ' saved');
                return 'Project saved.';
            case 'category':
                $id = inc_int_or_null($_POST['id'] ?? null);
                $vals = [fp_str($_POST['name'] ?? '', 80, true, 'Name'), in_array($_POST['kind'] ?? '', ['normal', 'travel', 'accommodation', 'mileage', 'per_diem'], true) ? $_POST['kind'] : 'normal',
                         fp_ref($_POST['account_id'] ?? null, 'fp_accounts', 'expense account', true, true), fp_dec($_POST['limit_per_item'] ?? '', false, 'Limit', 0), fp_dec($_POST['receipt_above'] ?? '', false, 'Receipt above', 0),
                         !isset($_POST['active']) || !empty($_POST['active']) ? 1 : 0];
                if ($id) {
                    fp_exec('UPDATE fp_expense_categories SET name=?, kind=?, account_id=?, limit_per_item=?, receipt_above=?, active=? WHERE id=?', 'ssissii', array_merge($vals, [$id]));
                } else {
                    $id = fp_exec('INSERT INTO fp_expense_categories (name, kind, account_id, limit_per_item, receipt_above, active) VALUES (?,?,?,?,?,?)', 'ssissi', $vals);
                }
                fp_audit('category', $id, 'save', 'Expense category ' . $vals[0] . ' saved');
                return 'Category saved.';
            case 'asset_cat':
                $id = inc_int_or_null($_POST['id'] ?? null);
                $vals = [fp_str($_POST['name'] ?? '', 80, true, 'Name'), fp_ref($_POST['asset_account_id'] ?? null, 'fp_accounts', 'asset account', true), fp_ref($_POST['accum_account_id'] ?? null, 'fp_accounts', 'accumulated depreciation account', true),
                         fp_ref($_POST['expense_account_id'] ?? null, 'fp_accounts', 'depreciation account', true), ($_POST['method'] ?? '') === 'wdv' ? 'wdv' : 'slm', max(1, (int) ($_POST['life_months'] ?? 60)),
                         fp_dec($_POST['wdv_rate'] ?? '', false, 'WDV rate', 0, 100, 3), fp_dec($_POST['salvage_pct'] ?? '5', true, 'Salvage %', 0, 100, 3)];
                if ($id) {
                    fp_exec('UPDATE fp_asset_categories SET name=?, asset_account_id=?, accum_account_id=?, expense_account_id=?, method=?, life_months=?, wdv_rate=?, salvage_pct=? WHERE id=?', 'siiisissi', array_merge($vals, [$id]));
                } else {
                    $id = fp_exec('INSERT INTO fp_asset_categories (name, asset_account_id, accum_account_id, expense_account_id, method, life_months, wdv_rate, salvage_pct) VALUES (?,?,?,?,?,?,?,?)', 'siiisiss', $vals);
                }
                fp_audit('asset_category', $id, 'save', 'Asset category ' . $vals[0] . ' saved');
                return 'Asset category saved. Existing assets keep their own method and life.';
            case 'fx':
                $cur = fp_currency($_POST['currency'] ?? '');
                if ($cur === fp_setting('base_currency')) {
                    throw new InvalidArgumentException('The base currency needs no rate.');
                }
                fp_exec('INSERT INTO fp_fx_rates (currency, rate_date, rate, source) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE rate = VALUES(rate), source = VALUES(source)', 'ssss',
                        [$cur, fp_date($_POST['rate_date'] ?? '', true, 'Date'), fp_dec($_POST['rate'] ?? '', true, 'Rate', 0.000001, 1000000, 6), fp_str($_POST['source'] ?? '', 60) ?: null]);
                fp_audit('fx', null, 'save', 'Rate for ' . $cur . ' on ' . $_POST['rate_date'] . ': ' . $_POST['rate']);
                return 'Rate saved.';
            case 'spend_log':
                [$n, $skipped] = fp_import_spend_log((int) ($_POST['bank_account_id'] ?? 0), (int) ($_POST['input_account_id'] ?? 0));
                return $n . ' spend-log entr' . ($n === 1 ? 'y' : 'ies') . ' posted to the ledger.' . ($skipped ? ' Skipped: ' . implode('; ', array_slice($skipped, 0, 5)) : '');
            case 'payroll':
                $jid = fp_import_payroll((int) ($_POST['run_id'] ?? 0), (int) ($_POST['cost_centre_id'] ?? 0) ?: null);
                inc_redirect(fp_url('journal', ['id' => $jid]), ['ok', 'Payroll posted to the ledger.']);
                // no break: inc_redirect exits
            case 'vendors_desks':
                [$made] = fp_vendor_import_desks();
                return $made . ' vendor(s) brought in from the IT and Facilities desks.';
            case 'cron':
                $r = fp_cron_org();
                return 'Background jobs run: ' . implode(', ', array_map(static fn($k, $v) => str_replace('_', ' ', $k) . ' ' . (is_int($v) ? $v : $v), array_keys($r), $r)) . '.';
        }
        throw new InvalidArgumentException('Unknown action.');
    }, $back);
}

fp_layout_start('Finance settings', '', '', [], $flash);
echo '<nav class="subtabs">';
foreach ($tabs as $k => $l) {
    echo '<a href="' . e(fp_url('settings', ['tab' => $k])) . '"' . ($tab === $k ? ' class="on"' : '') . '>' . e($l) . '</a>';
}
echo '</nav>';
$s = static fn(string $k) => e(fp_setting($k));

if ($tab === 'general') {
    $months = [];
    for ($m = 1; $m <= 12; $m++) {
        $months[$m] = date('F', mktime(0, 0, 0, $m, 1));
    }
    echo '<section class="card">' . inc_form_open() . '<input type="hidden" name="action" value="general">'
       . '<h2>Books</h2><div class="inc-row">' . inc_field('Financial year starts in', inc_select('fy_start_month', $months, (int) fp_setting('fy_start_month'), '', $posted ? 'disabled' : ''), $posted ? 'Fixed once entries are posted' : 'April for India')
       . inc_field('Base currency', '<input name="base_currency" maxlength="3" size="4" value="' . $s('base_currency') . '"' . ($posted ? ' readonly' : '') . '>') . '</div>'
       . '<h2>Separation of duties</h2><label class="check"><input type="checkbox" name="sod_strict" value="1"' . (fp_setting('sod_strict') === '1' ? ' checked' : '') . '> Strict: nobody approves what they submitted, and one person cannot approve two levels of one document</label>'
       . '<p class="muted small">Expense claims, advances, requisitions and payments can never be approved by whoever raised them, whatever this says. Turn strict off only for a very small team — every self-approval is still recorded.</p>'
       . '<h2>Budgetary control</h2><div class="inc-row">' . inc_field('When a budget would be exceeded', inc_select('budget_control', ['warn' => 'Warn, and let it through', 'block' => 'Stop it (a Finance Manager may accept the exception)'], fp_setting('budget_control')))
       . inc_field('Warn at % used', '<input name="budget_warn_pct" size="5" value="' . $s('budget_warn_pct') . '">') . inc_field('Stop at % used', '<input name="budget_block_pct" size="5" value="' . $s('budget_block_pct') . '">') . '</div>'
       . '<h2>Payables</h2><div class="inc-row">' . inc_field('Price tolerance vs order (%)', '<input name="match_price_tol_pct" size="5" value="' . $s('match_price_tol_pct') . '">')
       . inc_field('Quantity tolerance vs receipt (%)', '<input name="match_qty_tol_pct" size="5" value="' . $s('match_qty_tol_pct') . '">')
       . inc_field('Duplicate check window (days)', '<input name="dup_window_days" size="5" value="' . $s('dup_window_days') . '">', 'Same vendor and amount within this many days is flagged') . '</div>'
       . '<h2>Expense claims</h2><div class="inc-row">' . inc_field('Receipt needed above', '<input name="claim_receipt_above" size="8" value="' . $s('claim_receipt_above') . '">', 'Blank: always')
       . inc_field('Claims may go back (days)', '<input name="claim_backdate_days" size="5" value="' . $s('claim_backdate_days') . '">', '0: no limit')
       . inc_field('Mileage rate per km', '<input name="mileage_rate" size="6" value="' . $s('mileage_rate') . '">', 'Blank: mileage not offered')
       . inc_field('Per diem a day', '<input name="per_diem_rate" size="8" value="' . $s('per_diem_rate') . '">', 'Blank: per diem not offered') . '</div>'
       . '<h2>Receivables</h2><div class="inc-row">' . inc_field('Remind customers at days overdue', '<input name="ar_reminder_days" size="12" value="' . $s('ar_reminder_days') . '">', 'e.g. 3,15,30 — blank: no automatic reminders')
       . inc_field('Over a credit limit', inc_select('ar_credit_control', ['warn' => 'Warn', 'block' => 'Stop the invoice'], fp_setting('ar_credit_control'))) . '</div>'
       . '<h2>Approvals, notices and records</h2><div class="inc-row">' . inc_field('Escalate an approval after (hours)', '<input name="escalate_hours" size="5" value="' . $s('escalate_hours') . '">', 'A rule can set its own')
       . inc_field('Keep audit evidence for (years after the year)', '<input name="retention_years" size="4" value="' . $s('retention_years') . '">', 'Check your statutory requirement') . '</div>'
       . '<label class="check"><input type="checkbox" name="mail_notify" value="1"' . (fp_setting('mail_notify') === '1' ? ' checked' : '') . '> Email approval requests and decisions as well as showing them in the portal</label>'
       . '<div class="inc-formfoot"><button class="btn btn-primary">Save settings</button></div></form></section>';
} elseif ($tab === 'roles') {
    $people = fp_people();
    $members = fp_all('SELECT * FROM fp_role_members ORDER BY role, person_id');
    echo '<section class="card"><h2>Give someone a finance role</h2><p class="muted small">Everyone in the organisation is an Employee: they claim expenses and raise requests. A role adds what the Permissions tab lists for it. '
       . 'A Department Manager can be limited to one cost centre.</p>' . inc_form_open() . '<input type="hidden" name="action" value="role_add"><div class="inc-row">'
       . inc_field('Person', inc_select('person_id', $people, '', 'Choose…', 'required'), '', true, 'grow') . inc_field('Role', inc_select('role', array_diff_key(fp_roles(), ['employee' => 1]), '', 'Choose…', 'required'), '', true)
       . inc_field('Only for cost centre', inc_select('cost_centre_id', fp_cc_options(), '', 'All')) . '</div><button class="btn btn-primary">Add role</button></form></section>';
    echo '<section class="card"><h2>Who holds what</h2><p class="small">' . (fp_org() > 0 ? '<strong>' . e(fp_person_name(fp_org())) . '</strong> heads the organisation and is its finance administrator.' : 'The portal administrator is the finance administrator.') . '</p>';
    if (!$members) {
        echo '<p class="muted small">No finance roles given yet.</p>';
    } else {
        echo '<table class="data inc-table stack"><thead><tr><th>Person</th><th>Role</th><th>Scope</th><th>Given by</th><th></th></tr></thead><tbody>';
        foreach ($members as $m) {
            echo '<tr><td data-l="Person">' . e(fp_person_name((int) $m['person_id'])) . (!fp_is_member((int) $m['person_id']) ? ' ' . inc_badge('No longer here', 'red') : '') . '</td><td data-l="Role">' . e(fp_roles()[$m['role']] ?? $m['role']) . '</td>'
               . '<td data-l="Scope">' . ($m['cost_centre_id'] ? e(fp_one('SELECT code FROM fp_cost_centres WHERE id = ?', 'i', [(int) $m['cost_centre_id']])['code'] ?? '') : 'All') . '</td><td data-l="Given by" class="small">' . e($m['added_by'] . ' · ' . fp_fmt_dt($m['created_at'])) . '</td>'
               . '<td>' . inc_button('Remove', ['action' => 'role_remove', 'id' => $m['id']], 'btn btn-sm', 'Remove this role?') . '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</section>';
} elseif ($tab === 'matrix') {
    $m = fp_matrix();
    $roles = array_diff_key(fp_roles(), ['sysadmin' => 1]);
    echo '<section class="card">' . inc_form_open() . '<input type="hidden" name="action" value="matrix"><p class="muted small">What each role may do. The System Administrator holds everything. Approving is not a permission: it follows the approval rules.</p>'
       . '<div class="scrollx"><table class="data inc-table fp-matrix"><thead><tr><th>Permission</th>';
    foreach ($roles as $r => $label) {
        echo '<th class="cb" title="' . e($label) . '">' . e($label) . '</th>';
    }
    echo '</tr></thead><tbody>';
    $group = '';
    foreach (fp_permissions() as $perm => [$g, $label]) {
        if ($g !== $group) {
            echo '<tr class="fp-sub"><td colspan="' . (count($roles) + 1) . '">' . e($g) . '</td></tr>';
            $group = $g;
        }
        echo '<tr><td>' . e($label) . '</td>';
        foreach ($roles as $r => $l) {
            echo '<td class="cb"><input type="checkbox" name="perm[' . e($r) . '][' . e($perm) . ']" value="1"' . (in_array($perm, $m[$r] ?? [], true) ? ' checked' : '') . ' aria-label="' . e($l . ': ' . $label) . '"></td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div><div class="inc-formfoot"><button class="btn btn-primary">Save permissions</button></div></form>'
       . inc_button('Reset to the defaults', ['action' => 'matrix_reset'], 'btn btn-sm', 'Reset every role to its default permissions?') . '</section>';
} elseif ($tab === 'rules') {
    $rules = fp_all('SELECT * FROM fp_wf_rules ORDER BY doc_type, level, min_amount, id');
    $edit = ctype_digit((string) ($_GET['edit'] ?? '')) ? fp_one('SELECT * FROM fp_wf_rules WHERE id = ?', 'i', [(int) $_GET['edit']]) : null;
    $docs = array_map(static fn($d) => $d['label'], array_diff_key(fp_doc_types(), array_flip(['receipt', 'asset', 'control', 'evidence'])));
    $x = $edit ?? ['doc_type' => 'bill', 'level' => 1, 'min_amount' => '0', 'approver_type' => 'role', 'required' => 1, 'active' => 1];
    $chosen = array_filter(explode(',', (string) ($x['approver_roles'] ?? '')));
    $roleChecks = '';
    foreach (array_diff_key(fp_roles(), ['employee' => 1]) as $r => $l) {
        $roleChecks .= '<label class="check"><input type="checkbox" name="approver_roles[]" value="' . e($r) . '"' . (in_array($r, $chosen, true) ? ' checked' : '') . '> ' . e($l) . '</label> ';
    }
    echo '<section class="card"><h2>' . ($edit ? 'Edit rule' : 'Add a rule') . '</h2><p class="muted small">Rules at the same level must all approve (in parallel); levels go in order. A rule applies when the document\'s amount is within its band '
       . 'and, if it names one, the entity or cost centre matches. With no rule at all, a Finance approval step is used.</p>' . inc_form_open() . '<input type="hidden" name="action" value="rule">'
       . ($edit ? '<input type="hidden" name="id" value="' . (int) $edit['id'] . '">' : '')
       . '<div class="inc-row">' . inc_field('Document', inc_select('doc_type', $docs, $x['doc_type'])) . inc_field('Level', '<input type="number" name="level" min="1" max="9" value="' . (int) $x['level'] . '">')
       . inc_field('Step name', '<input name="label" maxlength="80" required value="' . e($x['label'] ?? '') . '" placeholder="e.g. CFO approval">', '', true, 'grow')
       . inc_field('From amount', '<input name="min_amount" size="10" value="' . e(fp_amt($x['min_amount'])) . '">') . inc_field('Up to amount', '<input name="max_amount" size="10" value="' . e(fp_inp($x['max_amount'] ?? '')) . '" placeholder="no limit">') . '</div>'
       . '<div class="inc-row">' . inc_field('Approved by', inc_select('approver_type', ['role' => 'People holding a role', 'person' => 'One named person', 'manager' => 'The submitter\'s manager (HR, else the cost centre owner)', 'cc_owner' => 'The cost centre\'s budget holder'], $x['approver_type']))
       . inc_field('Named person', inc_select('approver_person_id', fp_people() + (fp_org() === 0 ? [0 => 'Administrator'] : []), $x['approver_person_id'] ?? '', '—'))
       . inc_field('Approvals needed', '<input type="number" name="required" min="1" max="5" value="' . (int) $x['required'] . '">', 'Different people')
       . inc_field('Escalate after (hours)', '<input type="number" name="escalate_hours" min="1" value="' . e((string) ($x['escalate_hours'] ?? '')) . '" placeholder="default">') . '</div>'
       . inc_field('Roles (for "people holding a role")', '<div>' . $roleChecks . '</div>')
       . '<div class="inc-row">' . (count(fp_entity_options()) > 1 ? inc_field('Only for entity', inc_select('entity_id', fp_entity_options(), $x['entity_id'] ?? '', 'Any')) : '')
       . inc_field('Only for cost centre', inc_select('cost_centre_id', fp_cc_options(), $x['cost_centre_id'] ?? '', 'Any'))
       . '<label class="check"><input type="hidden" name="active" value="0"><input type="checkbox" name="active" value="1"' . ((int) $x['active'] === 1 ? ' checked' : '') . '> Active</label></div>'
       . '<button class="btn btn-primary">Save rule</button>' . ($edit ? ' <a class="btn" href="' . e(fp_url('settings', ['tab' => 'rules'])) . '">Cancel</a>' : '') . '</form></section>';
    echo '<div class="scrollx"><table class="data inc-table stack"><thead><tr><th>Document</th><th>Level</th><th>Step</th><th>Amount band</th><th>Approver</th><th>Scope</th><th></th></tr></thead><tbody>';
    foreach ($rules as $r) {
        $who = ['role' => implode(', ', array_map(static fn($k) => fp_roles()[$k] ?? $k, array_filter(explode(',', (string) $r['approver_roles'])))), 'person' => fp_person_name($r['approver_person_id'] !== null ? (int) $r['approver_person_id'] : null),
                'manager' => 'Submitter\'s manager', 'cc_owner' => 'Budget holder'][$r['approver_type']];
        echo '<tr' . ((int) $r['active'] !== 1 ? ' class="muted"' : '') . '><td data-l="Document">' . e(fp_doc_types()[$r['doc_type']]['label'] ?? $r['doc_type']) . '</td><td data-l="Level">' . (int) $r['level'] . '</td>'
           . '<td data-l="Step">' . e($r['label']) . ((int) $r['required'] > 1 ? ' <span class="muted small">×' . (int) $r['required'] . '</span>' : '') . '</td>'
           . '<td data-l="Band">' . e(fp_money($r['min_amount'], false)) . ' – ' . ($r['max_amount'] !== null ? e(fp_money($r['max_amount'], false)) : 'any') . '</td><td data-l="Approver">' . e($who) . '</td>'
           . '<td data-l="Scope" class="small">' . e(trim(($r['entity_id'] ? 'entity #' . $r['entity_id'] . ' ' : '') . ($r['cost_centre_id'] ? 'cc ' . (fp_one('SELECT code FROM fp_cost_centres WHERE id = ?', 'i', [(int) $r['cost_centre_id']])['code'] ?? '') : '')) ?: 'All') . '</td>'
           . '<td><a class="btn btn-sm" href="' . e(fp_url('settings', ['tab' => 'rules', 'edit' => $r['id']])) . '">Edit</a></td></tr>';
    }
    echo '</tbody></table></div>';
} elseif ($tab === 'delegations') {
    $people = fp_people();
    echo '<section class="card"><h2>Delegate someone\'s approvals</h2>' . inc_form_open() . '<input type="hidden" name="action" value="delegate"><div class="inc-row">'
       . inc_field('Approvals of', inc_select('from_person_id', $people, '', 'Choose…', 'required'), '', true) . inc_field('Go to', inc_select('to_person_id', $people, '', 'Choose…', 'required'), '', true)
       . inc_field('From', '<input type="date" name="from_date" required value="' . e(date('Y-m-d')) . '">', '', true) . inc_field('To', '<input type="date" name="to_date" required>', '', true)
       . inc_field('Reason', '<input name="reason" maxlength="255">', '', false, 'grow') . '</div><button class="btn btn-primary">Save</button></form></section>';
    $rows = fp_all('SELECT * FROM fp_delegations ORDER BY active DESC, to_date DESC LIMIT 200');
    echo $rows ? '<table class="data inc-table stack"><thead><tr><th>From</th><th>To</th><th>Dates</th><th>Documents</th><th>Reason</th><th>Status</th></tr></thead><tbody>' . implode('', array_map(static fn($d) => '<tr><td>' . e(fp_person_name((int) $d['from_person_id'])) . '</td><td>' . e(fp_person_name((int) $d['to_person_id'])) . '</td>'
         . '<td>' . e(fp_fmt_date($d['from_date']) . ' – ' . fp_fmt_date($d['to_date'])) . '</td><td class="small">' . e($d['doc_types'] ?: 'All') . '</td><td class="small">' . e($d['reason'] ?? '') . '</td>'
         . '<td>' . ((int) $d['active'] === 1 && $d['to_date'] >= date('Y-m-d') ? inc_badge('Active', 'green') : inc_badge('Ended')) . '</td></tr>', $rows)) . '</tbody></table>' : '<p class="muted small">No delegations.</p>';
} elseif ($tab === 'masters') {
    $people = fp_people();
    // Entities
    echo '<section class="card"><h2>Legal entities</h2><p class="muted small">Each entity keeps its own books within the same chart of accounts; reports can show one or all (consolidated), and intercompany entries are posted on both sides.</p><table class="data inc-table stack"><tbody>';
    foreach (fp_all('SELECT * FROM fp_entities ORDER BY id') as $en) {
        echo '<tr><td>' . inc_form_open('class="inc-row"') . '<input type="hidden" name="action" value="entity"><input type="hidden" name="id" value="' . (int) $en['id'] . '">'
           . '<input name="code" size="6" maxlength="20" value="' . e($en['code']) . '" aria-label="Code"><input name="name" maxlength="190" value="' . e($en['name']) . '" aria-label="Name">'
           . '<input name="gstin" size="16" maxlength="20" placeholder="GSTIN" value="' . e($en['gstin'] ?? '') . '"><input name="pan" size="11" maxlength="12" placeholder="PAN" value="' . e($en['pan'] ?? '') . '">'
           . '<input name="state" size="12" maxlength="60" placeholder="State" value="' . e($en['state'] ?? '') . '"><input name="address" maxlength="500" placeholder="Address (printed on invoices)" value="' . e($en['address'] ?? '') . '">'
           . '<button class="btn btn-sm">Save</button></form></td></tr>';
    }
    echo '<tr><td>' . inc_form_open('class="inc-row"') . '<input type="hidden" name="action" value="entity"><input name="code" size="6" maxlength="20" placeholder="Code" required><input name="name" maxlength="190" placeholder="New entity name" required>'
       . '<input name="gstin" size="16" maxlength="20" placeholder="GSTIN"><input name="state" size="12" placeholder="State"><button class="btn btn-sm">Add entity</button></form></td></tr></tbody></table></section>';
    // Cost centres
    echo '<section class="card"><div class="inc-cardhead"><h2>Cost centres (departments)</h2>' . inc_button('Bring in HR departments', ['action' => 'cc_import'], 'btn btn-sm') . '</div>'
       . '<p class="muted small">The budget holder approves purchase orders for the cost centre and gets its budget alerts.</p><table class="data inc-table stack"><tbody>';
    foreach (fp_all('SELECT * FROM fp_cost_centres ORDER BY code') as $c) {
        echo '<tr' . ((int) $c['active'] !== 1 ? ' class="muted"' : '') . '><td>' . inc_form_open('class="inc-row"') . '<input type="hidden" name="action" value="cc"><input type="hidden" name="id" value="' . (int) $c['id'] . '">'
           . '<input name="code" size="8" maxlength="20" value="' . e($c['code']) . '"><input name="name" maxlength="120" value="' . e($c['name']) . '">' . inc_select('manager_person_id', $people, $c['manager_person_id'] ?? '', 'No budget holder')
           . '<label class="check small"><input type="hidden" name="active" value="0"><input type="checkbox" name="active" value="1"' . ((int) $c['active'] === 1 ? ' checked' : '') . '> Active</label>'
           . ($c['hr_department_id'] ? ' <span class="muted small">HR</span>' : '') . '<button class="btn btn-sm">Save</button></form></td></tr>';
    }
    echo '<tr><td>' . inc_form_open('class="inc-row"') . '<input type="hidden" name="action" value="cc"><input name="code" size="8" maxlength="20" placeholder="Code" required><input name="name" maxlength="120" placeholder="Name" required>'
       . inc_select('manager_person_id', $people, '', 'Budget holder') . '<button class="btn btn-sm">Add</button></form></td></tr></tbody></table></section>';
    // Projects
    echo '<section class="card"><h2>Projects</h2><table class="data inc-table stack"><tbody>';
    foreach (fp_all('SELECT * FROM fp_projects ORDER BY status, code') as $p) {
        echo '<tr><td>' . inc_form_open('class="inc-row"') . '<input type="hidden" name="action" value="project"><input type="hidden" name="id" value="' . (int) $p['id'] . '">'
           . '<input name="code" size="8" value="' . e($p['code']) . '"><input name="name" value="' . e($p['name']) . '">' . inc_select('cost_centre_id', fp_cc_options(), $p['cost_centre_id'] ?? '', '—')
           . '<input type="date" name="start_date" value="' . e($p['start_date'] ?? '') . '"><input type="date" name="end_date" value="' . e($p['end_date'] ?? '') . '">' . inc_select('status', ['active' => 'Active', 'closed' => 'Closed'], $p['status'])
           . '<button class="btn btn-sm">Save</button></form></td></tr>';
    }
    echo '<tr><td>' . inc_form_open('class="inc-row"') . '<input type="hidden" name="action" value="project"><input name="code" size="8" placeholder="Code" required><input name="name" placeholder="Project name" required>'
       . inc_select('cost_centre_id', fp_cc_options(), '', 'Cost centre') . '<input type="date" name="start_date"><input type="date" name="end_date"><button class="btn btn-sm">Add</button></form></td></tr></tbody></table></section>';
    // Expense categories
    $exp = fp_account_options(['expense']);
    echo '<section class="card"><h2>Expense claim categories</h2><table class="data inc-table stack"><tbody>';
    foreach (fp_all('SELECT * FROM fp_expense_categories ORDER BY name') as $c) {
        echo '<tr><td>' . inc_form_open('class="inc-row"') . '<input type="hidden" name="action" value="category"><input type="hidden" name="id" value="' . (int) $c['id'] . '">'
           . '<input name="name" maxlength="80" value="' . e($c['name']) . '">' . inc_select('kind', ['normal' => 'Ordinary', 'travel' => 'Travel', 'accommodation' => 'Accommodation', 'mileage' => 'Mileage (km × rate)', 'per_diem' => 'Per diem (days × rate)'], $c['kind'])
           . inc_select('account_id', $exp, $c['account_id']) . '<input name="limit_per_item" size="8" placeholder="Limit" value="' . e(fp_inp($c['limit_per_item'])) . '"><input name="receipt_above" size="8" placeholder="Receipt above" value="' . e(fp_inp($c['receipt_above'])) . '">'
           . '<label class="check small"><input type="hidden" name="active" value="0"><input type="checkbox" name="active" value="1"' . ((int) $c['active'] === 1 ? ' checked' : '') . '> Active</label><button class="btn btn-sm">Save</button></form></td></tr>';
    }
    echo '<tr><td>' . inc_form_open('class="inc-row"') . '<input type="hidden" name="action" value="category"><input name="name" maxlength="80" placeholder="New category" required>'
       . inc_select('kind', ['normal' => 'Ordinary', 'travel' => 'Travel', 'accommodation' => 'Accommodation', 'mileage' => 'Mileage', 'per_diem' => 'Per diem'], 'normal') . inc_select('account_id', $exp, '', 'Expense account')
       . '<input name="limit_per_item" size="8" placeholder="Limit"><button class="btn btn-sm">Add</button></form></td></tr></tbody></table></section>';
    // Asset categories
    $fa = fp_account_options(['asset']);
    echo '<section class="card"><h2>Fixed asset categories</h2><table class="data inc-table stack"><tbody>';
    foreach (array_merge(fp_all('SELECT * FROM fp_asset_categories ORDER BY name'), [['id' => 0, 'name' => '', 'asset_account_id' => '', 'accum_account_id' => fp_acct('accum_dep'), 'expense_account_id' => fp_acct('depreciation'), 'method' => 'slm', 'life_months' => 60, 'wdv_rate' => '', 'salvage_pct' => 5]]) as $c) {
        echo '<tr><td>' . inc_form_open('class="inc-row"') . '<input type="hidden" name="action" value="asset_cat">' . ($c['id'] ? '<input type="hidden" name="id" value="' . (int) $c['id'] . '">' : '')
           . '<input name="name" maxlength="80" value="' . e($c['name']) . '" placeholder="New category"' . ($c['id'] ? '' : ' required') . '>' . inc_select('asset_account_id', $fa, $c['asset_account_id'], 'Asset account')
           . inc_select('accum_account_id', $fa, $c['accum_account_id']) . inc_select('expense_account_id', $exp, $c['expense_account_id']) . inc_select('method', ['slm' => 'Straight line', 'wdv' => 'Written-down value'], $c['method'])
           . '<input name="life_months" size="4" value="' . (int) $c['life_months'] . '" title="Life in months"><input name="wdv_rate" size="5" placeholder="WDV %" value="' . e((string) ($c['wdv_rate'] ?? '')) . '">'
           . '<input name="salvage_pct" size="4" value="' . e((string) (float) $c['salvage_pct']) . '" title="Salvage %"><button class="btn btn-sm">' . ($c['id'] ? 'Save' : 'Add') . '</button></form></td></tr>';
    }
    echo '</tbody></table></section>';
    // Exchange rates
    echo '<section class="card"><h2>Exchange rates</h2><p class="muted small">Used when a foreign-currency document gives no rate of its own: the latest rate on or before its date.</p>'
       . inc_form_open('class="inc-row"') . '<input type="hidden" name="action" value="fx"><input name="currency" size="4" maxlength="3" placeholder="USD" required><input type="date" name="rate_date" value="' . e(date('Y-m-d')) . '" required>'
       . '<input name="rate" size="10" placeholder="1 USD in ' . e(fp_setting('base_currency')) . '" required><input name="source" maxlength="60" placeholder="Source (e.g. RBI reference)"><button class="btn btn-sm">Save rate</button></form>';
    $rates = fp_all('SELECT * FROM fp_fx_rates ORDER BY rate_date DESC, currency LIMIT 30');
    echo $rates ? '<table class="data inc-table"><tbody>' . implode('', array_map(static fn($r) => '<tr><td>' . e($r['currency']) . '</td><td>' . e(fp_fmt_date($r['rate_date'])) . '</td><td class="num">' . e((string) (float) $r['rate']) . '</td><td class="small">' . e($r['source'] ?? '') . '</td></tr>', $rates)) . '</tbody></table>' : '';
    echo '</section>';
} else {
    // Integrations: what is connected, what each needs.
    require_once __DIR__ . '/../../mailer.php';
    $hr = true;
    try {
        fabo_one('SELECT 1 FROM hr_claims LIMIT 1');
    } catch (Throwable $e) {
        $hr = false;
    }
    $desk = static function (string $d): bool {
        try {
            fabo_one('SELECT 1 FROM `' . $d . '_' . (fp_org() > 0 ? 'o' . fp_org() . '_' : '') . 'vendors` LIMIT 1');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    };
    $runs = fp_payroll_runs_open();
    $spend = (int) (fabo_one('SELECT COUNT(*) n FROM expenses WHERE client_id = ?', 'i', [fp_org()])['n'] ?? 0) - (int) fp_val('SELECT COUNT(*) FROM fp_imports WHERE source = "spend_log"');
    $row = static fn(string $name, bool $ok, string $what, string $act = '') => '<tr><td data-l="Integration"><strong>' . e($name) . '</strong></td><td data-l="Status">' . inc_badge($ok ? 'Connected' : 'Not available', $ok ? 'green' : '') . '</td><td data-l="What it does" class="small">' . $what . '</td><td>' . $act . '</td></tr>';
    echo '<section class="card"><h2>Connections</h2><div class="scrollx"><table class="data inc-table stack"><thead><tr><th>Integration</th><th>Status</th><th>What it does</th><th></th></tr></thead><tbody>'
       . $row('HR — expense claims', $hr, 'Claims approved in HR are brought in at Finance review; paying them in Finance marks them paid in HR.', $hr ? '<a class="btn btn-sm" href="' . e(fp_url('claims')) . '">Claims</a>' : '')
       . $row('HR — payroll', $hr, 'A finalised payroll month posts to the ledger as one journal (gross, employer cost, net and statutory dues).' . ($hr && !$runs ? ' Nothing new to post.' : ''),
              $runs ? inc_form_open('class="inc-inline"') . '<input type="hidden" name="action" value="payroll">' . inc_select('run_id', array_column(array_map(static fn($r) => ['id' => $r['id'], 'l' => $r['period'] . ' — net ' . fp_money($r['net'], false)], $runs), 'l', 'id'))
                    . inc_select('cost_centre_id', fp_cc_options(), '', 'No cost centre') . '<button class="btn btn-sm">Post</button></form>' : '')
       . $row('HR — departments', $hr, 'HR departments become cost centres.', $hr ? inc_button('Bring in', ['action' => 'cc_import'], 'btn btn-sm') : '')
       . $row('IT & Facilities desks', $desk('it') || $desk('fa'), 'Their vendors become draft vendors to verify; their assets with a cost join the fixed asset register.',
              ($desk('it') || $desk('fa')) ? inc_button('Bring in vendors', ['action' => 'vendors_desks'], 'btn btn-sm') . ' <a class="btn btn-sm" href="' . e(fp_url('assets')) . '">Assets</a>' : '')
       . (fp_org() === 0 ? $row('Client billing (portal invoices)', true, 'Invoices raised to clients on Client billing come into receivables with their payments.', '<a class="btn btn-sm" href="' . e(fp_url('invoices')) . '">Bring in</a>') : '')
       . $row('Spend log', true, 'The simple spend book kept before the Finance Portal posts to the ledger, one journal per entry.' . ($spend > 0 ? ' ' . $spend . ' entr' . ($spend === 1 ? 'y' : 'ies') . ' not yet posted.' : ' Nothing new.'),
              $spend > 0 ? inc_form_open('class="inc-inline"') . '<input type="hidden" name="action" value="spend_log">' . inc_select('bank_account_id', fp_bank_options(), '', 'Paid from…', 'required')
                         . inc_select('input_account_id', fp_account_options([], ['tax_input']), fp_acct('gst_in_igst'), '', 'required') . '<button class="btn btn-sm">Post</button></form>' : '')
       . $row('Statutory filing calendar', true, 'The portal\'s filing register for this organisation is the Tax & compliance calendar — one register, not two.', '<a class="btn btn-sm" href="' . e(fp_url('tax', ['tab' => 'calendar'])) . '">Open</a>')
       . $row('Email', fabo_mail_configured(), 'Approval requests, decisions and customer reminders are emailed under the organisation\'s name.' . (fabo_mail_configured() ? '' : ' Outgoing mail is not configured on this server.'))
       . $row('Banking', false, 'No bank interface is connected: payments are released as a bank upload file and completed by recording the bank\'s reference; statements are imported as CSV. A direct bank connection needs the bank\'s API credentials and agreement, which are not set up.')
       . $row('Accounting / ERP export', true, 'Every report exports to Excel and CSV; the general ledger and journal register can be imported into other accounting software.', '<a class="btn btn-sm" href="' . e(fp_url('reports')) . '">Reports</a>')
       . '</tbody></table></div></section>';
    $last = fp_setting('last_cron');
    echo '<section class="card"><h2>Background jobs</h2><p class="small">Approval reminders and escalation, recurring journals, accrual reversals, customer reminders and budget alerts run each hour someone uses Finance'
       . ' (and from the server scheduler, cron.php, when it is set up). Last run: <strong>' . e($last ? fp_fmt_dt($last) : 'never') . '</strong>.</p>' . inc_button('Run now', ['action' => 'cron'], 'btn btn-sm') . '</section>';
}
fp_layout_end();

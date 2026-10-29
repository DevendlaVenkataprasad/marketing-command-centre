<?php
/**
 * Configuration building blocks shared by Policies & Rules and the desks
 * that own the same data (Organisation Structure, Shifts & Work Calendars,
 * Holidays, and each module's own categories). Each block renders its table and form, and hr_parts_post()
 * handles every action they post — with its own permission check, so a
 * block reached from any page is guarded the same way.
 */

function hr_can_any(array $pairs): bool
{
    foreach ($pairs as [$m, $a]) {
        if (hr_can($m, $a)) {
            return true;
        }
    }
    return false;
}

function hr_need_any(array $pairs): void
{
    if (!hr_can_any($pairs)) {
        error_log('FABO HR denied ' . hr_actor() . ' -> config action');
        fabo_deny('You do not have permission to change this.', 403);
    }
}

/** Org-wide config rights: only an org-scoped grant (or the admin) changes shared setup. */
function hr_cfg(string $module, string $action): array
{
    return [[$module, $action], ['sheets', 'configure']];
}

/**
 * kind => [table, one, many, module that owns it, [table, column] counting its use].
 * The organisation lists belong to Organisation Structure; each module's own
 * categories belong to that module and are changed with its Configure right.
 */
function hr_master_tables(): array
{
    return [
        'department'      => ['hr_departments', 'Department', 'Departments', 'masters', ['hr_employees', 'department_id']],
        'designation'     => ['hr_designations', 'Designation', 'Designations', 'masters', ['hr_employees', 'designation_id']],
        'employment_type' => ['hr_masters', 'Employment type', 'Employment Types', 'masters', ['hr_employees', 'employment_type_id']],
        'location'        => ['hr_masters', 'Work location', 'Work Locations', 'masters', ['hr_employees', 'location_id']],
        'category'        => ['hr_masters', 'Employee category', 'Employee Categories', 'masters', ['hr_employees', 'category_id']],
        'claim_category'  => ['hr_masters', 'Claim category', 'Claim Categories', 'claims', ['hr_claims', 'category_id']],
        'asset_category'  => ['hr_masters', 'Asset category', 'Asset Categories', 'assets', ['hr_assets', 'category_id']],
        'ticket_category' => ['hr_masters', 'Helpdesk category', 'Helpdesk Categories', 'helpdesk', ['hr_tickets', 'category_id']],
        'training_category' => ['hr_masters', 'Training category', 'Training Categories', 'training', ['hr_trainings', 'category_id']],
    ];
}

/** The rights that change one list: Organisation Structure's, or the owning module's Configure (org-wide). */
function hr_master_rights(string $kind, string $action): array
{
    $mod = hr_master_tables()[$kind][3] ?? 'masters';
    if ($mod === 'masters') {
        return hr_cfg('masters', $action);
    }
    // Modules without a Configure action use Edit for their lists.
    $act = in_array('configure', hr_modules()[$mod]['actions'], true) ? 'configure' : 'edit';
    return hr_scope($mod, $act) === 'org' ? [[$mod, $act]] : [['access', 'configure']];
}

/* =============================================================== POST */

/**
 * Handles any configuration action. Returns a flash on refusal (the page
 * re-renders with the message); on success it redirects to $back.
 */
function hr_parts_post(string $back): ?array
{
    $a = (string) ($_POST['action'] ?? '');
    $p = $_POST;
    $id = (int) ($p['id'] ?? 0) ?: null;

    switch ($a) {
        /* ---- masters ---- */
        case 'master_save':
            $kind = (string) ($p['kind'] ?? '');
            $mt = hr_master_tables()[$kind] ?? null;
            if (!$mt) {
                return ['err', 'Unknown list.'];
            }
            hr_need_any(hr_master_rights($kind, $id ? 'edit' : 'create'));
            return hr_action(function () use ($kind, $id, $p, $mt) {
                if ($kind === 'department') { hr_department_save($id, $p); }
                elseif ($kind === 'designation') { hr_designation_save($id, $p); }
                else { hr_master_save($kind, $id, $p); }
                return $mt[1] . ' “' . trim((string) $p['name']) . '” saved.';
            }, $back);
        case 'master_toggle':
            $mt = hr_master_tables()[(string) ($p['kind'] ?? '')] ?? null;
            if (!$mt) { return ['err', 'Unknown list.']; }
            hr_need_any(hr_master_rights((string) $p['kind'], 'edit'));
            [$ok, $msg] = hr_master_toggle($mt[0], (int) $id, !empty($p['active']), $mt[1]);
            hr_redirect($back, [$ok ? 'ok' : 'err', $msg]);
        case 'master_delete':
            $mt = hr_master_tables()[(string) ($p['kind'] ?? '')] ?? null;
            if (!$mt) { return ['err', 'Unknown list.']; }
            hr_need_any(hr_master_rights((string) $p['kind'], 'delete'));
            [$ok, $msg] = hr_master_delete($mt[0], (int) $id, $mt[1]);
            hr_redirect($back, [$ok ? 'ok' : 'err', $msg]);
        case 'master_import':
            $kind = (string) ($p['kind'] ?? '');
            if (!isset(hr_master_tables()[$kind])) { return ['err', 'Unknown list.']; }
            hr_need_any(hr_master_rights($kind, 'create'));
            return hr_action(function () use ($kind) {
                [$path, $name] = hr_uploaded_table('file');
                $rows = hr_read_table_file($path, $name);
                array_shift($rows);
                $n = 0;
                $skip = 0;
                foreach ($rows as [, $cells]) {
                    $nm = trim((string) ($cells[0] ?? ''));
                    if ($nm === '') { continue; }
                    try {
                        $q = ['name' => $nm, 'code' => $cells[1] ?? '', 'description' => $cells[$kind === 'department' ? 2 : 1] ?? ''];
                        if ($kind === 'department') { hr_department_save(null, $q); }
                        elseif ($kind === 'designation') { hr_designation_save(null, ['name' => $nm]); }
                        else { hr_master_save($kind, null, $q); }
                        $n++;
                    } catch (InvalidArgumentException $e) {
                        $skip++;
                    }
                }
                return $n . ' added' . ($skip ? ', ' . $skip . ' skipped (already there or invalid)' : '') . '.';
            }, $back);

        /* ---- shifts ---- */
        case 'shift_save':
            hr_need_any(hr_cfg('schedules', $id ? 'edit' : 'create'));
            if ($id) {
                $n = (int) (fabo_one('SELECT COUNT(DISTINCT employee_id) n FROM hr_shift_assignments WHERE shift_id = ?
                                      AND (effective_to IS NULL OR effective_to >= CURDATE())', 'i', [$id])['n'] ?? 0);
                $isDef = (int) (hr_shift($id)['is_default'] ?? 0);
                if (($n > 0 || $isDef) && empty($p['confirm_impact'])) {
                    return ['err', 'This shift applies to ' . ($isDef ? 'everyone without a shift of their own' : $n . ' employee(s)')
                                 . '. Tick “I understand this changes their attendance from today” to save.'];
                }
            }
            return hr_action(function () use ($id, $p) {
                hr_shift_save($id, $p);
                return 'Shift “' . trim((string) $p['name']) . '” saved.';
            }, $back);
        case 'shift_toggle':
            hr_need_any(hr_cfg('schedules', 'edit'));
            [$ok, $msg] = hr_master_toggle('hr_shifts', (int) $id, !empty($p['active']), 'Shift');
            hr_redirect($back, [$ok ? 'ok' : 'err', $msg]);
        case 'shift_delete':
            hr_need_any(hr_cfg('schedules', 'delete'));
            [$ok, $msg] = hr_master_delete('hr_shifts', (int) $id, 'Shift');
            hr_redirect($back, [$ok ? 'ok' : 'err', $msg]);
        case 'shift_assign':
            hr_need_any(hr_cfg('schedules', 'edit'));
            return hr_action(function () use ($p) {
                $from = hr_date($p['from'] ?? '', true, 'Effective from');
                $shift = (int) ($p['shift_id'] ?? 0);
                $ids = array_map('intval', (array) ($p['emp'] ?? []));
                if (!$ids) {
                    throw new InvalidArgumentException('Tick at least one employee.');
                }
                $n = 0;
                foreach ($ids as $eid) {
                    if (!hr_employee($eid)) { continue; }
                    hr_shift_assign($eid, $shift, $from);
                    $n++;
                }
                return $n . ' employee(s) moved to ' . (hr_shift($shift)['name'] ?? 'the shift') . ' from ' . hr_fmt_date($from) . '.';
            }, $back);

        /* ---- holiday calendars ---- */
        case 'cal_save':
            hr_need_any(hr_cfg('holidays', $id ? 'edit' : 'create'));
            return hr_action(function () use ($id, $p) {
                hr_calendar_save($id, $p);
                return 'Calendar “' . trim((string) $p['name']) . '” saved.';
            }, $back);
        case 'cal_toggle':
            hr_need_any(hr_cfg('holidays', 'edit'));
            [$ok, $msg] = hr_master_toggle('hr_holiday_calendars', (int) $id, !empty($p['active']), 'Holiday calendar');
            hr_redirect($back, [$ok ? 'ok' : 'err', $msg]);
        case 'cal_delete':
            hr_need_any(hr_cfg('holidays', 'delete'));
            [$ok, $msg] = hr_master_delete('hr_holiday_calendars', (int) $id, 'Holiday calendar');
            hr_redirect($back, [$ok ? 'ok' : 'err', $msg]);

        /* ---- settings ---- */
        case 'settings_apply':
            $area = (string) ($p['area'] ?? '');
            hr_need_any(hr_settings_rights($area));
            return hr_action(function () use ($p, $area) {
                $eff = hr_date($p['effective_from'] ?? '', false, 'Effective from');
                $n = 0;
                foreach (hr_setting_defs() as $k => $def) {
                    if ($def['area'] !== $area || !array_key_exists('set_' . $k, $p)) {
                        continue;
                    }
                    $v = hr_setting_value_from_post($k, $def, $p['set_' . $k]);
                    $n += hr_setting_set($k, $v, $eff) ? 1 : 0;
                }
                hr_audit('settings', 'settings', null, ucfirst($area) . ' settings: ' . $n . ' change(s)');
                return $n ? $n . ' setting(s) changed.' : 'Nothing changed.';
            }, $back);

        /* ---- leave approval steps ---- */
        case 'chain_save':
            hr_need_any(hr_cfg('leave', 'configure'));
            return hr_action(function () use ($p) {
                $typeId = (int) ($p['chain_for'] ?? 0) ?: null;
                if ($typeId && !empty($p['use_default'])) {
                    hr_chain_save($typeId, null);
                    return 'This leave type now follows the default approval steps.';
                }
                $steps = hr_chain_from_post($p);
                hr_chain_save($typeId, $steps);
                return 'Approval steps saved: ' . hr_chain_words($steps) . '. They apply to requests submitted from now on; '
                     . 'requests already waiting keep the steps they were sent with.';
            }, $back . '&chain_for=' . (int) ($p['chain_for'] ?? 0) . '#chain');

        /* ---- leave types & policies ---- */
        case 'ltype_save':
            hr_need_any(hr_cfg('leave', 'configure'));
            return hr_action(function () use ($id, $p) {
                hr_leave_type_save($id, $p);
                return 'Leave type saved.';
            }, $back);
        case 'ltype_toggle':
            hr_need_any(hr_cfg('leave', 'configure'));
            [$ok, $msg] = hr_master_toggle('hr_leave_types', (int) $id, !empty($p['active']), 'Leave type');
            hr_redirect($back, [$ok ? 'ok' : 'err', $msg]);
        case 'ltype_templates':
            hr_need_any(hr_cfg('leave', 'configure'));
            return hr_action(function () use ($p) {
                $n = hr_leave_types_add_templates(array_map('strval', (array) ($p['codes'] ?? [])));
                return $n . ' leave type(s) added. Set how many days each gives in a leave policy below.';
            }, $back);
        case 'policy_save':
            hr_need_any(hr_cfg('leave', 'configure'));
            return hr_action(function () use ($id, $p) {
                $pid = hr_leave_policy_save($id, $p);
                hr_redirect(hr_url((string) ($GLOBALS['hr_page'] ?? 'sheets'), ['tab' => 'leave', 'policy' => $pid]), ['ok', 'Policy saved.']);
            }, $back);
        case 'policy_toggle':
            hr_need_any(hr_cfg('leave', 'configure'));
            [$ok, $msg] = hr_master_toggle('hr_leave_policies', (int) $id, !empty($p['active']), 'Leave policy');
            hr_redirect($back, [$ok ? 'ok' : 'err', $msg]);
        case 'rule_save':
            hr_need_any(hr_cfg('leave', 'configure'));
            $pid = (int) ($p['policy_id'] ?? 0);
            $reach = hr_policy_reach($pid);
            if ($reach > 0 && empty($p['confirm_impact'])) {
                return ['err', 'This policy applies to ' . $reach . ' employee(s). Tick “I understand this affects their balances from the effective date” to save.'];
            }
            return hr_action(function () use ($pid, $p) {
                hr_leave_rule_save($pid, (int) ($p['leave_type_id'] ?? 0), $p);
                return 'Rule saved. Credits already given are unchanged; new credits follow the new rule.';
            }, $back);
        case 'rule_delete':
            hr_need_any(hr_cfg('leave', 'configure'));
            return hr_action(function () use ($p) {
                hr_leave_rule_delete((int) ($p['policy_id'] ?? 0), (int) ($p['leave_type_id'] ?? 0));
                return 'Leave type removed from the policy. Balances already credited stay.';
            }, $back);

        /* ---- salary components ---- */
        case 'comp_save':
            hr_need_any(hr_cfg('payroll', 'configure'));
            return hr_action(function () use ($id, $p) {
                hr_component_save($id, $p);
                return 'Component saved. It applies from the next payroll calculation; finalized payroll is unchanged.';
            }, $back);
        case 'comp_toggle':
            hr_need_any(hr_cfg('payroll', 'configure'));
            [$ok, $msg] = hr_master_toggle('hr_salary_components', (int) $id, !empty($p['active']), 'Component');
            hr_redirect($back, [$ok ? 'ok' : 'err', $msg]);
        case 'comp_templates':
            hr_need_any(hr_cfg('payroll', 'configure'));
            return hr_action(function () use ($p) {
                $n = hr_components_add_templates(array_map('strval', (array) ($p['codes'] ?? [])));
                return $n . ' component(s) added. Check each rate before running payroll.';
            }, $back);
    }
    return null;
}

function hr_settings_rights(string $area): array
{
    return match ($area) {
        'leave'      => hr_cfg('leave', 'configure'),
        'attendance' => hr_cfg('attendance', 'configure'),
        'payroll'    => hr_cfg('payroll', 'configure'),
        'lifecycle'  => hr_cfg('lifecycle', 'configure'),
        'claims'     => hr_cfg('claims', 'configure'),
        'performance' => hr_cfg('performance', 'configure'),
        default      => [['sheets', 'configure']],
    };
}

function hr_setting_value_from_post(string $k, array $def, $raw): ?string
{
    $raw = is_string($raw) ? trim($raw) : '';
    switch ($def['type']) {
        case 'bool':
            return $raw === '1' ? '1' : '0';
        case 'int':
            if ($raw === '') { return ''; }
            if (!preg_match('/^\d{1,4}$/', $raw)) { throw new InvalidArgumentException($def['label'] . ' must be a whole number.'); }
            return $raw;
        case 'decimal':
            return $raw === '' ? '' : hr_dec($raw, 2, false, $def['label'], 0, 1000000);
        case 'month':
            if (!preg_match('/^(1[0-2]|[1-9])$/', $raw)) { throw new InvalidArgumentException('Choose a month.'); }
            return $raw;
        case 'select':
            if ($raw === '') { return null; }
            if (!isset($def['options'][$raw])) { throw new InvalidArgumentException('Choose a value for “' . $def['label'] . '”.'); }
            return $raw;
        case 'textarea':
            return hr_str($raw, 1000);
        default:
            $v = hr_str($raw, 120);
            if ($k === 'emp_code_format' && !str_contains($v, '{SEQ}')) {
                throw new InvalidArgumentException('The Employee ID format must contain {SEQ} so every ID is different.');
            }
            if ($k === 'pay_slip_format' && !str_contains($v, '{SEQ}') && !str_contains($v, '{EMP}')) {
                throw new InvalidArgumentException('The payslip number format must contain {SEQ} or {EMP} so numbers do not repeat.');
            }
            return $v;
    }
}

/* ============================================================ RENDERING */

function hr_part_masters(string $kind, string $back, array $tabKeep): void
{
    [$table, $one, $many, , [$useTable, $useCol]] = hr_master_tables()[$kind];
    $canAdd  = hr_can_any(hr_master_rights($kind, 'create'));
    $canEdit = hr_can_any(hr_master_rights($kind, 'edit'));
    $canDel  = hr_can_any(hr_master_rights($kind, 'delete'));
    $org = hr_org();
    // How many records use each entry: current employees for the organisation lists, every record for a module's categories.
    $useWhere = $useTable === 'hr_employees' ? ' AND e.status IN ("active","probation","notice")' : '';
    $useSql = '(SELECT COUNT(*) FROM `' . $useTable . '` e WHERE e.`' . $useCol . '` = %s.id' . $useWhere . ') AS n';
    $useLabel = $useTable === 'hr_employees' ? 'Employees' : 'In use';
    if ($kind === 'department') {
        $rows = fabo_all('SELECT d.*, ' . sprintf($useSql, 'd') . ' FROM hr_departments d WHERE d.org_id = ? ORDER BY d.active DESC, d.name', 'i', [$org]);
    } elseif ($kind === 'designation') {
        $rows = fabo_all('SELECT g.*, d.name AS department, ' . sprintf($useSql, 'g') . '
                          FROM hr_designations g LEFT JOIN hr_departments d ON d.id = g.department_id WHERE g.org_id = ? ORDER BY g.active DESC, g.name', 'i', [$org]);
    } else {
        $rows = fabo_all('SELECT m.*, ' . sprintf($useSql, 'm') . ' FROM hr_masters m WHERE m.org_id = ? AND m.kind = ? ORDER BY m.active DESC, m.name', 'is', [$org, $kind]);
    }
    $isClaim = $kind === 'claim_category';
    $editId = (int) ($_GET['edit'] ?? 0);
    $edit = null;
    foreach ($rows as $r) {
        if ((int) $r['id'] === $editId) { $edit = $r; }
    }
    echo '<div class="card"><h2>' . e($many) . ' <span class="muted small">(' . count($rows) . ')</span></h2>';
    if ($rows) {
        echo '<div class="scrollx"><table class="data hr-table stack"><thead><tr><th>Name</th>'
           . ($kind === 'department' ? '<th>Code</th>' : '') . ($kind === 'designation' ? '<th>Department</th><th>Level</th>' : '')
           . (!in_array($kind, ['designation'], true) ? '<th>Description</th>' : '') . ($isClaim ? '<th class="num">Limit per claim</th>' : '')
           . '<th class="num">' . $useLabel . '</th><th>Status</th><th></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr><td data-l="Name"><strong>' . e($r['name']) . '</strong></td>'
               . ($kind === 'department' ? '<td data-l="Code">' . e($r['code'] ?? '') . '</td>' : '')
               . ($kind === 'designation' ? '<td data-l="Department">' . e($r['department'] ?? '—') . '</td><td data-l="Level">' . e($r['level'] ?? '') . '</td>' : '')
               . (!in_array($kind, ['designation'], true) ? '<td data-l="Description">' . e($r['description'] ?? '') . '</td>' : '')
               . ($isClaim ? '<td class="num" data-l="Limit">' . ($r['amount_limit'] !== null ? e(fabo_money_exact($r['amount_limit'])) : '<span class="muted">No limit</span>') . '</td>' : '')
               . '<td class="num" data-l="' . $useLabel . '">' . (int) $r['n'] . '</td>'
               . '<td data-l="Status">' . hr_badge($r['active'] ? 'active' : 'inactive') . '</td><td class="hr-acts">';
            if ($canEdit) {
                echo '<a class="btn btn-sm" href="' . e(hr_url((string) $GLOBALS['hr_page'], $tabKeep + ['edit' => $r['id']])) . '#form-' . e($kind) . '">Edit</a>';
                echo hr_button_form($r['active'] ? 'Deactivate' : 'Activate', ['action' => 'master_toggle', 'kind' => $kind, 'id' => $r['id'],
                     'active' => $r['active'] ? 0 : 1], 'btn btn-sm', $r['active'] && $r['n'] ? 'Deactivate “' . $r['name'] . '”? ' . $r['n']
                     . ' employee(s) keep it; it can no longer be chosen for new records.' : '');
            }
            if ($canDel) {
                echo hr_button_form('Delete', ['action' => 'master_delete', 'kind' => $kind, 'id' => $r['id']], 'btn btn-sm btn-danger',
                                    'Delete “' . $r['name'] . '”? This cannot be undone. Records that use it will refuse the delete.');
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    } else {
        echo hr_empty('No ' . strtolower($many) . ' yet.', $canAdd ? ' Add the first one below.' : '');
    }
    echo '</div>';
    if (($canAdd && !$edit) || ($canEdit && $edit)) {
        echo '<div class="card" id="form-' . e($kind) . '"><h2>' . ($edit ? 'Edit ' . e(strtolower($one)) . ': ' . e($edit['name']) : 'Add ' . e(strtolower($one))) . '</h2>'
           . hr_form_open($back, 'class="hr-form"') . '<input type="hidden" name="action" value="master_save"><input type="hidden" name="kind" value="' . e($kind) . '">'
           . ($edit ? '<input type="hidden" name="id" value="' . (int) $edit['id'] . '">' : '') . '<div class="formgrid">'
           . hr_field('Name', hr_input('name', $edit['name'] ?? ($_POST['name'] ?? ''), 'text', ['required' => true, 'maxlength' => 120,
                      'placeholder' => $kind === 'department' ? 'e.g. Marketing' : ($kind === 'designation' ? 'e.g. Senior Designer' : '')]), true);
        if ($kind === 'department') {
            echo hr_field('Code', hr_input('code', $edit['code'] ?? '', 'text', ['maxlength' => 20, 'placeholder' => 'e.g. MKT']));
        }
        if ($kind === 'designation') {
            echo hr_field('Department', hr_select('department_id', hr_departments_map(), $edit['department_id'] ?? '', 'Any department'))
               . hr_field('Level / grade', hr_input('level', $edit['level'] ?? '', 'text', ['maxlength' => 40, 'placeholder' => 'e.g. L3']));
        } else {
            echo hr_field('Description', hr_input('description', $edit['description'] ?? '', 'text', ['maxlength' => 255]));
        }
        if ($isClaim) {
            echo hr_field('Limit per claim (₹)', hr_input('amount_limit', $edit['amount_limit'] ?? '', 'number', ['step' => '0.01', 'min' => '0', 'placeholder' => 'No limit']),
                          false, 'A claim above this amount is refused when submitted.');
        }
        echo '</div><div class="hr-formfoot"><button class="btn btn-primary">' . ($edit ? 'Save changes' : 'Add') . '</button>'
           . ($edit ? '<a class="btn" href="' . e(hr_url((string) $GLOBALS['hr_page'], $tabKeep)) . '">Cancel</a>' : '') . '</div></form>';
        if (!$edit && $canAdd) {
            echo '<details class="mt-sm"><summary class="btn btn-sm">Import from a file</summary><div style="margin-top:10px">'
               . hr_form_open($back, '', true) . '<input type="hidden" name="action" value="master_import"><input type="hidden" name="kind" value="' . e($kind) . '">'
               . '<p class="hr-hint">A .xls, .xlsx or .csv file with a heading row; the first column is the name' . ($kind === 'department' ? ', then code and description' : '')
               . '. Names already present are skipped.</p><input type="file" name="file" accept=".xls,.xlsx,.csv" required> '
               . '<button class="btn btn-sm btn-primary">Import</button></form></div></details>';
        }
        echo '</div>';
    }
}

function hr_part_shifts(string $back, array $keep): void
{
    $canAdd  = hr_can_any(hr_cfg('schedules', 'create'));
    $canEdit = hr_can_any(hr_cfg('schedules', 'edit'));
    $canDel  = hr_can_any(hr_cfg('schedules', 'delete'));
    $rows = fabo_all('SELECT s.*, (SELECT COUNT(DISTINCT a.employee_id) FROM hr_shift_assignments a WHERE a.shift_id = s.id
                      AND (a.effective_to IS NULL OR a.effective_to >= CURDATE())) AS n FROM hr_shifts s WHERE s.org_id = ?
                      ORDER BY s.active DESC, s.is_default DESC, s.name', 'i', [hr_org()]);
    $edit = null;
    foreach ($rows as $r) {
        if ((int) $r['id'] === (int) ($_GET['edit_shift'] ?? 0)) { $edit = $r; }
    }
    echo '<div class="card"><h2>Shifts</h2>';
    if (!$rows) {
        echo '<div class="hr-warn">No shift is set up yet. Attendance can still be marked by hand, but late arrivals, half days and overtime '
           . 'are worked out only against a shift. Add one below and mark it as the default.</div>';
    } else {
        echo '<div class="scrollx"><table class="data hr-table stack"><thead><tr><th>Shift</th><th>Timing</th><th>Break</th><th>Grace</th>'
           . '<th>Full / half day</th><th>Overtime after</th><th>Weekly offs</th><th class="num">Employees</th><th>Status</th><th></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr><td data-l="Shift"><strong>' . e($r['name']) . '</strong>' . ($r['is_default'] ? ' <span class="pill blue">Default</span>' : '')
               . ($r['code'] ? '<div class="muted small">' . e($r['code']) . '</div>' : '') . '</td>'
               . '<td data-l="Timing" class="nowrap">' . e(substr($r['start_time'], 0, 5) . ' – ' . substr($r['end_time'], 0, 5))
               . (hr_shift_overnight($r) ? ' <span class="muted small">(overnight)</span>' : '') . '</td>'
               . '<td data-l="Break">' . (int) $r['break_minutes'] . ' min</td><td data-l="Grace">' . (int) $r['grace_minutes'] . ' min late · '
               . (int) $r['early_grace_minutes'] . ' min early</td><td data-l="Full / half">' . hr_fmt_days($r['full_day_hours']) . ' h / '
               . hr_fmt_days($r['half_day_hours']) . ' h</td><td data-l="Overtime">' . ($r['overtime_after_hours'] !== null ? hr_fmt_days($r['overtime_after_hours']) . ' h' : 'Not tracked')
               . '</td><td data-l="Weekly offs">' . e(hr_offs_words($r['weekly_offs'])) . '</td><td class="num" data-l="Employees">' . (int) $r['n'] . '</td>'
               . '<td data-l="Status">' . hr_badge($r['active'] ? 'active' : 'inactive') . '</td><td class="hr-acts">';
            if ($canEdit) {
                echo '<a class="btn btn-sm" href="' . e(hr_url((string) $GLOBALS['hr_page'], $keep + ['edit_shift' => $r['id']])) . '#shift-form">Edit</a>'
                   . hr_button_form($r['active'] ? 'Deactivate' : 'Activate', ['action' => 'shift_toggle', 'id' => $r['id'], 'active' => $r['active'] ? 0 : 1]);
            }
            if ($canDel) {
                echo hr_button_form('Delete', ['action' => 'shift_delete', 'id' => $r['id']], 'btn btn-sm btn-danger', 'Delete this shift? Shifts in use cannot be deleted.');
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';
    if (!($edit ? $canEdit : $canAdd)) {
        return;
    }
    $v = $edit ?? ['name' => '', 'code' => '', 'start_time' => '', 'end_time' => '', 'break_minutes' => '', 'grace_minutes' => '',
                   'early_grace_minutes' => '', 'full_day_hours' => '', 'half_day_hours' => '', 'overtime_after_hours' => '',
                   'min_overtime_minutes' => '', 'weekly_offs' => '', 'is_default' => 0];
    echo '<div class="card" id="shift-form"><h2>' . ($edit ? 'Edit shift: ' . e($edit['name']) : 'Add a shift') . '</h2>'
       . hr_form_open($back, 'class="hr-form"') . '<input type="hidden" name="action" value="shift_save">'
       . ($edit ? '<input type="hidden" name="id" value="' . (int) $edit['id'] . '">' : '')
       . '<div class="formsec"><h3>Timing</h3><div class="formgrid">'
       . hr_field('Shift name', hr_input('name', $v['name'], 'text', ['required' => true, 'maxlength' => 120, 'placeholder' => 'e.g. General shift']), true)
       . hr_field('Code', hr_input('code', $v['code'], 'text', ['maxlength' => 20, 'placeholder' => 'e.g. GEN']))
       . hr_field('Starts at', hr_input('start_time', substr((string) $v['start_time'], 0, 5), 'time', ['required' => true]), true)
       . hr_field('Ends at', hr_input('end_time', substr((string) $v['end_time'], 0, 5), 'time', ['required' => true]), true,
                  'An end earlier than the start is an overnight shift.')
       . hr_field('Break (minutes)', hr_input('break_minutes', $v['break_minutes'], 'number', ['min' => 0, 'max' => 600]))
       . '</div></div><div class="formsec"><h3>Rules</h3><div class="formgrid">'
       . hr_field('Hours for a full day', hr_input('full_day_hours', $v['full_day_hours'], 'number', ['step' => '0.25', 'min' => '0.5', 'max' => 24, 'required' => true]), true,
                  'Worked hours (after the break) at or above this count as Present.')
       . hr_field('Hours for a half day', hr_input('half_day_hours', $v['half_day_hours'], 'number', ['step' => '0.25', 'min' => '0.25', 'max' => 24, 'required' => true]), true,
                  'At or above this but below a full day is a Half Day; below it is Absent.')
       . hr_field('Late after (grace, minutes)', hr_input('grace_minutes', $v['grace_minutes'], 'number', ['min' => 0, 'max' => 240]), false,
                  'Checking in later than start + grace is a late arrival.')
       . hr_field('Early leaving grace (minutes)', hr_input('early_grace_minutes', $v['early_grace_minutes'], 'number', ['min' => 0, 'max' => 240]))
       . hr_field('Overtime starts after (hours worked)', hr_input('overtime_after_hours', $v['overtime_after_hours'], 'number', ['step' => '0.25', 'min' => 0, 'max' => 24]),
                  false, 'Leave blank if overtime is not tracked.')
       . hr_field('Minimum overtime that counts (minutes)', hr_input('min_overtime_minutes', $v['min_overtime_minutes'], 'number', ['min' => 0, 'max' => 600]))
       . '</div></div><div class="formsec"><h3>Weekly offs</h3>' . hr_offs_picker($v['weekly_offs'])
       . '<p class="hr-hint">An employee can have their own weekly offs on their record; otherwise their shift\'s apply.</p></div>'
       . '<div class="field">' . hr_checkbox('is_default', (bool) $v['is_default'], 'Default shift — used for anyone without a shift of their own') . '</div>';
    if ($edit) {
        echo '<div class="hr-warn">' . hr_checkbox('confirm_impact', false, 'I understand this changes their attendance from today')
           . '<div class="hr-hint">Days already recorded keep the hours they were saved with. Finalized payroll is not affected.</div></div>';
    }
    echo '<div class="hr-formfoot"><button class="btn btn-primary">' . ($edit ? 'Save shift' : 'Add shift') . '</button>'
       . ($edit ? '<a class="btn" href="' . e(hr_url((string) $GLOBALS['hr_page'], $keep)) . '">Cancel</a>' : '') . '</div></form></div>';
}

function hr_part_calendars(string $back, array $keep): void
{
    $canAdd  = hr_can_any(hr_cfg('holidays', 'create'));
    $canEdit = hr_can_any(hr_cfg('holidays', 'edit'));
    $canDel  = hr_can_any(hr_cfg('holidays', 'delete'));
    $rows = fabo_all('SELECT c.*, l.name AS location, d.name AS department,
                             (SELECT COUNT(*) FROM hr_holidays h WHERE h.calendar_id = c.id AND h.active = 1) AS n
                        FROM hr_holiday_calendars c LEFT JOIN hr_masters l ON l.id = c.location_id LEFT JOIN hr_departments d ON d.id = c.department_id
                       WHERE c.org_id = ? ORDER BY c.is_default DESC, c.name', 'i', [hr_org()]);
    $edit = null;
    foreach ($rows as $r) {
        if ((int) $r['id'] === (int) ($_GET['edit_cal'] ?? 0)) { $edit = $r; }
    }
    echo '<div class="card"><h2>Holiday calendars</h2><p class="hr-hint mt0">Each employee follows one calendar: the one on their record, '
       . 'else one made for their work location, else one for their department, else the default.</p>';
    if ($rows) {
        echo '<div class="scrollx"><table class="data hr-table stack"><thead><tr><th>Calendar</th><th>For location</th><th>For department</th>'
           . '<th class="num">Holidays</th><th>Status</th><th></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr><td data-l="Calendar"><strong>' . e($r['name']) . '</strong>' . ($r['is_default'] ? ' <span class="pill blue">Default</span>' : '') . '</td>'
               . '<td data-l="Location">' . e($r['location'] ?? '—') . '</td><td data-l="Department">' . e($r['department'] ?? '—') . '</td>'
               . '<td class="num" data-l="Holidays">' . (int) $r['n'] . '</td><td data-l="Status">' . hr_badge($r['active'] ? 'active' : 'inactive') . '</td><td class="hr-acts">'
               . '<a class="btn btn-sm" href="' . e(hr_url('holidays', ['calendar' => $r['id']])) . '">Holidays</a>';
            if ($canEdit) {
                echo '<a class="btn btn-sm" href="' . e(hr_url((string) $GLOBALS['hr_page'], $keep + ['edit_cal' => $r['id']])) . '#cal-form">Edit</a>'
                   . hr_button_form($r['active'] ? 'Deactivate' : 'Activate', ['action' => 'cal_toggle', 'id' => $r['id'], 'active' => $r['active'] ? 0 : 1]);
            }
            if ($canDel) {
                echo hr_button_form('Delete', ['action' => 'cal_delete', 'id' => $r['id']], 'btn btn-sm btn-danger', 'Delete this calendar? A calendar with holidays cannot be deleted.');
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    } else {
        echo hr_empty('No holiday calendar yet.', ' Add one, then add its holidays.');
    }
    echo '</div>';
    if (!($edit ? $canEdit : $canAdd)) {
        return;
    }
    echo '<div class="card" id="cal-form"><h2>' . ($edit ? 'Edit calendar' : 'Add a calendar') . '</h2>' . hr_form_open($back, 'class="hr-form"')
       . '<input type="hidden" name="action" value="cal_save">' . ($edit ? '<input type="hidden" name="id" value="' . (int) $edit['id'] . '">' : '')
       . '<div class="formgrid">' . hr_field('Name', hr_input('name', $edit['name'] ?? '', 'text', ['required' => true, 'placeholder' => 'e.g. Hyderabad office']), true)
       . hr_field('For work location', hr_select('location_id', hr_master_map('location'), $edit['location_id'] ?? '', 'Any location'))
       . hr_field('For department', hr_select('department_id', hr_departments_map(), $edit['department_id'] ?? '', 'Any department'))
       . '</div><div class="field">' . hr_checkbox('is_default', (bool) ($edit['is_default'] ?? !$rows), 'Default calendar') . '</div>'
       . '<div class="hr-formfoot"><button class="btn btn-primary">Save calendar</button>'
       . ($edit ? '<a class="btn" href="' . e(hr_url((string) $GLOBALS['hr_page'], $keep)) . '">Cancel</a>' : '') . '</div></form></div>';
}

/**
 * Settings for one area, with a preview: the first submit shows what will
 * change; nothing is saved until "Apply these changes".
 */
function hr_part_settings(string $area, string $back, string $title, string $explain = ''): void
{
    $can = hr_can_any(hr_settings_rights($area));
    $defs = array_filter(hr_setting_defs(), static fn($d) => $d['area'] === $area);
    echo '<div class="card"><h2>' . e($title) . '</h2>' . ($explain !== '' ? '<p class="hr-hint mt0">' . $explain . '</p>' : '');

    // Step two: the preview.
    if ($can && ($_POST['action'] ?? '') === 'settings_preview' && ($_POST['area'] ?? '') === $area) {
        $changes = [];
        $err = null;
        foreach ($defs as $k => $d) {
            if (!array_key_exists('set_' . $k, $_POST)) { continue; }
            try {
                $new = hr_setting_value_from_post($k, $d, $_POST['set_' . $k]);
            } catch (InvalidArgumentException $e) {
                $err = $e->getMessage();
                break;
            }
            $old = hr_setting($k);
            if ((string) $old !== (string) $new) {
                $changes[$k] = [$old, $new];
            }
        }
        if ($err) {
            echo '<div class="flash err">' . e($err) . '</div>';
        } elseif (!$changes) {
            echo '<div class="hr-note">Nothing would change.</div>';
        } else {
            $fmt = static function ($d, $v) {
                if ($v === null || $v === '') { return 'Not set'; }
                if ($d['type'] === 'bool') { return $v === '1' ? 'Yes' : 'No'; }
                if ($d['type'] === 'select') { return $d['options'][$v] ?? $v; }
                if ($d['type'] === 'month') { return date('F', mktime(0, 0, 0, (int) $v, 1)); }
                return (string) $v;
            };
            echo '<div class="hr-warn"><strong>Check these changes before applying them.</strong> ' . e(hr_settings_impact($area)) . '</div>'
               . '<table class="data hr-table hr-diff"><thead><tr><th>Setting</th><th>Now</th><th>After</th></tr></thead><tbody>';
            foreach ($changes as $k => [$o, $n]) {
                echo '<tr><td>' . e($defs[$k]['label']) . '</td><td class="old">' . e($fmt($defs[$k], $o)) . '</td><td class="new">' . e($fmt($defs[$k], $n)) . '</td></tr>';
            }
            echo '</tbody></table>' . hr_form_open($back) . '<input type="hidden" name="action" value="settings_apply"><input type="hidden" name="area" value="' . e($area) . '">';
            foreach ($changes as $k => [$o, $n]) {
                echo '<input type="hidden" name="set_' . e($k) . '" value="' . e((string) $n) . '">';
            }
            echo '<div class="formgrid" style="margin-top:12px">' . hr_field('Effective from', hr_input('effective_from', date('Y-m-d'), 'date'), false,
                 'Recorded in the change history. Settings act from the moment they are applied; finalized payroll never changes.') . '</div>'
               . '<div class="hr-formfoot"><button class="btn btn-primary">Apply these changes</button><a class="btn" href="' . e($back) . '">Cancel</a></div></form>';
            echo '</div>';
            return;
        }
    }

    echo hr_form_open($back, 'class="hr-form"') . '<input type="hidden" name="action" value="settings_preview"><input type="hidden" name="area" value="' . e($area) . '"><div class="formgrid">';
    foreach ($defs as $k => $d) {
        $cur = hr_setting($k);
        $dis = $can ? [] : ['disabled' => true];
        switch ($d['type']) {
            case 'bool':
                $in = '<select name="set_' . e($k) . '"' . ($can ? '' : ' disabled') . '>' . hr_options(['1' => 'Yes', '0' => 'No'], $cur) . '</select>';
                break;
            case 'select':
                $in = '<select name="set_' . e($k) . '"' . ($can ? '' : ' disabled') . '>' . hr_options($d['options'], $cur, $cur === null ? '— Not configured —' : '') . '</select>';
                break;
            case 'month':
                $months = [];
                for ($m = 1; $m <= 12; $m++) { $months[$m] = date('F', mktime(0, 0, 0, $m, 1)); }
                $in = '<select name="set_' . e($k) . '"' . ($can ? '' : ' disabled') . '>' . hr_options($months, $cur) . '</select>';
                break;
            case 'textarea':
                $in = '<textarea name="set_' . e($k) . '" rows="3"' . ($can ? '' : ' disabled') . '>' . e((string) $cur) . '</textarea>';
                break;
            case 'int':
            case 'decimal':
                $in = hr_input('set_' . $k, (string) $cur, 'number', $dis + ['step' => $d['type'] === 'int' ? '1' : '0.01', 'min' => 0]);
                break;
            default:
                $in = hr_input('set_' . $k, (string) $cur, 'text', $dis + ['maxlength' => 120]);
        }
        $needs = $d['default'] === null && ($cur === null || $cur === '');
        echo hr_field($d['label'], $in, $needs, ($needs ? '<strong class="req">Not configured yet.</strong> ' : '') . ($d['help'] ?? ''),
                      $d['type'] === 'textarea' ? 'full' : '');
    }
    echo '</div>' . ($can ? '<div class="hr-formfoot"><button class="btn btn-primary">Review changes</button></div>' : '<p class="muted small">You can view these settings but not change them.</p>')
       . '</form></div>';
}

function hr_settings_impact(string $area): string
{
    $n = (int) (fabo_one("SELECT COUNT(*) n FROM hr_employees WHERE org_id = ? AND status IN ('active','probation','notice')", 'i', [hr_org()])['n'] ?? 0);
    return match ($area) {
        'leave' => 'These apply to all ' . $n . ' current employees from now on. Leave already approved or credited is not changed.',
        'attendance' => 'These change how attendance is read for all ' . $n . ' current employees, including in months not yet locked.',
        'payroll' => 'These apply to the next payroll calculation. Runs already approved or finalized keep their numbers.',
        default => 'These apply across HR from now on.',
    };
}

function hr_part_leave_types(string $back, array $keep): void
{
    $can = hr_can_any(hr_cfg('leave', 'configure'));
    $types = hr_leave_types(false);
    $edit = null;
    foreach ($types as $t) {
        if ((int) $t['id'] === (int) ($_GET['edit_type'] ?? 0)) { $edit = $t; }
    }
    echo '<div class="card"><h2>Leave types</h2>';
    if ($types) {
        echo '<div class="scrollx"><table class="data hr-table stack"><thead><tr><th>Code</th><th>Leave type</th><th>Paid</th><th>Balance</th>'
           . '<th>Attachment</th><th>Status</th><th></th></tr></thead><tbody>';
        foreach ($types as $t) {
            echo '<tr><td data-l="Code"><strong>' . e($t['code']) . '</strong></td><td data-l="Leave type">' . e($t['name'])
               . ($t['is_comp_off'] ? ' <span class="pill blue">Comp-off</span>' : '') . '</td><td data-l="Paid">' . ($t['is_paid'] ? 'Paid' : 'Unpaid') . '</td>'
               . '<td data-l="Balance">' . ($t['unlimited'] ? 'Not tracked (no limit)' : 'Tracked') . '</td><td data-l="Attachment">'
               . ($t['attachment_after_days'] !== null ? 'Over ' . hr_fmt_days($t['attachment_after_days']) . ' day(s)' : '—') . '</td>'
               . '<td data-l="Status">' . hr_badge($t['active'] ? 'active' : 'inactive') . '</td><td class="hr-acts">';
            if ($can) {
                echo '<a class="btn btn-sm" href="' . e(hr_url((string) $GLOBALS['hr_page'], $keep + ['edit_type' => $t['id']])) . '#ltype-form">Edit</a>'
                   . hr_button_form($t['active'] ? 'Deactivate' : 'Activate', ['action' => 'ltype_toggle', 'id' => $t['id'], 'active' => $t['active'] ? 0 : 1]);
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    } else {
        echo hr_empty('No leave types yet.', ' Add the usual ones in one step below, or create your own.');
    }
    if ($can) {
        $have = array_map(static fn($t) => $t['code'], $types);
        $missing = array_filter(hr_leave_type_templates(), static fn($t) => !in_array($t[0], $have, true));
        if ($missing) {
            echo '<details style="margin-top:12px"' . (!$types ? ' open' : '') . '><summary class="btn btn-sm">Add standard leave types</summary>'
               . hr_form_open($back) . '<input type="hidden" name="action" value="ltype_templates"><p class="hr-hint">Adds the names only. '
               . 'How many days each gives is set on a leave policy — nothing is assumed.</p><div class="formgrid">';
            foreach ($missing as [$code, $name, $paid]) {
                echo '<div class="field">' . hr_checkbox('codes[]', true, $code . ' — ' . $name . ($paid ? '' : ' (unpaid)'), $code) . '</div>';
            }
            echo '</div><button class="btn btn-sm btn-primary">Add selected</button></form></details>';
        }
    }
    echo '</div>';
    if (!$can) {
        return;
    }
    $v = $edit ?? ['code' => '', 'name' => '', 'is_paid' => 1, 'is_comp_off' => 0, 'unlimited' => 0, 'attachment_after_days' => '', 'color' => '', 'description' => '', 'sort' => 0];
    echo '<div class="card" id="ltype-form"><h2>' . ($edit ? 'Edit leave type: ' . e($edit['name']) : 'Add a leave type') . '</h2>' . hr_form_open($back, 'class="hr-form"')
       . '<input type="hidden" name="action" value="ltype_save">' . ($edit ? '<input type="hidden" name="id" value="' . (int) $edit['id'] . '">' : '')
       . '<div class="formgrid">'
       . hr_field('Short code', hr_input('code', $v['code'], 'text', ['required' => true, 'maxlength' => 12, 'placeholder' => 'e.g. CL']), true)
       . hr_field('Name', hr_input('name', $v['name'], 'text', ['required' => true, 'maxlength' => 120, 'placeholder' => 'e.g. Casual Leave']), true)
       . hr_field('Supporting document required when longer than (days)', hr_input('attachment_after_days', $v['attachment_after_days'], 'number', ['step' => '0.5', 'min' => 0]),
                  false, 'Leave blank if never required.')
       . hr_field('Display order', hr_input('sort', $v['sort'], 'number'))
       . hr_field('Description', hr_input('description', $v['description'], 'text', ['maxlength' => 255]), false, '', 'full')
       . '</div><div class="formgrid">'
       . '<div class="field">' . hr_checkbox('is_paid', (bool) $v['is_paid'], 'Paid leave (no salary deduction)') . '</div>'
       . '<div class="field">' . hr_checkbox('unlimited', (bool) $v['unlimited'], 'No balance limit (for example Leave Without Pay)') . '</div>'
       . '<div class="field">' . hr_checkbox('is_comp_off', (bool) $v['is_comp_off'], 'Compensatory off (credited for working on a holiday)') . '</div>'
       . '</div><div class="hr-formfoot"><button class="btn btn-primary">Save leave type</button>'
       . ($edit ? '<a class="btn" href="' . e(hr_url((string) $GLOBALS['hr_page'], $keep)) . '">Cancel</a>' : '') . '</div></form></div>';
}

/** Who approves leave, in what order: the default, and per leave type. */
function hr_part_approval_chain(string $back, array $keep): void
{
    $can = hr_can_any(hr_cfg('leave', 'configure'));
    $types = hr_leave_types();
    $for = (int) ($_GET['chain_for'] ?? 0);
    $type = $for ? hr_leave_type($for) : null;
    if ($for && !$type) { $for = 0; }
    $steps = $type ? (hr_chain_parse($type['approval_chain']) ?: []) : hr_chain_default();
    $own = $type && hr_chain_parse($type['approval_chain']);

    echo '<div class="card" id="chain"><h2>Leave approval steps</h2>'
       . '<p class="mt0">Who has to approve a leave request, in order, before the leave is granted. Each approver is emailed when it is their turn; '
       . 'the employee is emailed after every step. The days are deducted only after the last approval, and a rejection at any step ends the request.</p>';

    // The rules in force, at a glance.
    echo '<table class="data hr-table stack" style="margin-bottom:14px"><thead><tr><th>Leave type</th><th>Approval steps</th><th class="num">Approvals needed</th><th></th></tr></thead><tbody>'
       . '<tr><td data-l="Leave type"><strong>Default</strong> <span class="muted small">(every type without its own)</span></td><td data-l="Steps">'
       . e(hr_chain_words(hr_chain_default())) . '</td><td class="num" data-l="Needed">' . count(hr_chain_default()) . '</td><td>'
       . ($can ? '<a class="btn btn-sm" href="' . e(hr_url((string) $GLOBALS['hr_page'], $keep + ['chain_for' => 0])) . '#chain">Change</a>' : '') . '</td></tr>';
    foreach ($types as $t) {
        $c = hr_chain_parse($t['approval_chain']);
        echo '<tr><td data-l="Leave type">' . e($t['name']) . '</td><td data-l="Steps">' . ($c ? e(hr_chain_words($c)) : '<span class="muted">Same as default</span>')
           . '</td><td class="num" data-l="Needed">' . count($c ?: hr_chain_default()) . '</td><td>'
           . ($can ? '<a class="btn btn-sm" href="' . e(hr_url((string) $GLOBALS['hr_page'], $keep + ['chain_for' => $t['id']])) . '#chain">' . ($c ? 'Change' : 'Set own steps') . '</a>' : '') . '</td></tr>';
    }
    echo '</tbody></table>';

    // Warnings that would leave a request stuck.
    $stuck = fabo_all("SELECT DISTINCT m.id, m.full_name, m.person_id FROM hr_employees m JOIN hr_employees e ON e.manager_id = m.id
                       AND e.status IN ('active','probation','notice') WHERE m.org_id = ? ORDER BY m.full_name", 'i', [hr_org()]);
    $stuck = array_filter($stuck, static fn($m) => !hr_emp_can_approve((int) $m['id']));
    $usesMgr = (bool) array_filter(array_merge(hr_chain_default(), ...array_map(static fn($t) => hr_chain_parse($t['approval_chain'] ?? null), $types)),
                                   static fn($s) => in_array($s['type'], ['manager', 'skip'], true));
    if ($stuck && $usesMgr) {
        echo '<div class="hr-warn"><strong>These managers cannot approve yet:</strong> ' . e(implode(', ', array_map(static fn($m) => $m['full_name'], $stuck)))
           . '. Requests waiting for them go to the administrator instead. Give them dashboard access and the <em>Manager</em> role '
           . '(Access Control) so they can approve their team.</div>';
    }

    if (!$can) {
        echo '</div>';
        return;
    }
    echo hr_form_open($back, 'class="hr-form"') . '<input type="hidden" name="action" value="chain_save"><input type="hidden" name="chain_for" value="' . $for . '">'
       . '<h3 style="margin:6px 0 10px;font-size:14px">' . ($type ? 'Steps for ' . e($type['name']) : 'Default steps') . '</h3>';
    if ($type) {
        echo '<div class="field"><label class="check"><input type="radio" name="use_default" value="1"' . ($own ? '' : ' checked') . '> Follow the default steps ('
           . e(hr_chain_words(hr_chain_default())) . ')</label><label class="check"><input type="radio" name="use_default" value="0"' . ($own ? ' checked' : '')
           . '> Use its own steps, set below</label></div>';
    }
    $people = [];
    foreach (fabo_all("SELECT id, full_name, emp_code, person_id FROM hr_employees WHERE org_id = ? AND status IN ('active','probation','notice')
                       AND person_id IS NOT NULL ORDER BY full_name", 'i', [hr_org()]) as $e) {
        $people[(int) $e['id']] = $e['full_name'] . ' (' . $e['emp_code'] . ')' . (hr_emp_can_approve((int) $e['id']) ? '' : ' — cannot approve yet');
    }
    $typeOpts = hr_chain_step_types();
    echo '<div class="scrollx"><table class="data hr-table"><thead><tr><th>Step</th><th>Who approves</th><th>Person (for “A specific person”)</th></tr></thead><tbody>';
    for ($i = 0; $i < 5; $i++) {
        $s = $steps[$i] ?? null;
        echo '<tr><td><strong>' . ($i + 1) . '</strong></td><td><select name="step_type[]" aria-label="Step ' . ($i + 1) . '">'
           . hr_options($typeOpts, $s['type'] ?? '', $i === 0 ? 'Choose…' : 'No further step') . '</select></td>'
           . '<td><select name="step_emp[]" aria-label="Person for step ' . ($i + 1) . '">' . hr_options($people, $s['employee_id'] ?? '', '—') . '</select></td></tr>';
    }
    echo '</tbody></table></div><ul class="hr-hint" style="margin:8px 0 12px;padding-left:18px">'
       . '<li><strong>Reporting manager</strong> and <strong>Manager\'s manager</strong> come from each employee\'s record; a step is skipped for someone who has none.</li>'
       . '<li><strong>A specific person</strong> must have dashboard access and a role that can approve leave.</li>'
       . '<li><strong>Anyone in HR</strong> means anyone whose role covers the whole organisation and can approve leave; the first one to act decides that step.</li>'
       . '<li>Nobody ever approves their own leave — such a step is skipped for them. '
       . (hr_setting('leave_admin_override') === '1' ? 'The administrator can still give the final approval at any step (Leave rules below).' : 'The administrator approves only at their own step.') . '</li></ul>'
       . '<div class="hr-formfoot"><button class="btn btn-primary">Save approval steps</button>'
       . ($for ? '<a class="btn" href="' . e(hr_url((string) $GLOBALS['hr_page'], $keep)) . '#chain">Back to default</a>' : '') . '</div></form></div>';
}

function hr_part_policies(string $back, array $keep): void
{
    $can = hr_can_any(hr_cfg('leave', 'configure'));
    $pols = hr_leave_policies();
    $types = hr_leave_types();
    $sel = (int) ($_GET['policy'] ?? ($pols[0]['id'] ?? 0));
    $pol = $sel ? hr_leave_policy($sel) : null;
    $depts = hr_departments_map();
    $etypes = hr_master_map('employment_type');

    echo '<div class="card"><h2>Leave policies</h2><p class="hr-hint mt0">A policy says how many days of each leave type an employee gets and the rules for taking them. '
       . 'An employee follows the policy set on their record; otherwise the first policy for their department or employment type; otherwise the default.</p>';
    if (!$pols) {
        echo '<div class="hr-warn">No leave policy yet. Until there is one, no leave is credited and only unlimited types (such as Leave Without Pay) can be applied for.</div>';
    } else {
        echo '<div class="scrollx"><table class="data hr-table stack"><thead><tr><th>Policy</th><th>Applies to</th><th>Effective</th><th class="num">Employees now</th><th>Status</th><th></th></tr></thead><tbody>';
        foreach ($pols as $p) {
            $for = [];
            foreach (array_filter(explode(',', (string) $p['department_ids'])) as $d) { $for[] = $depts[(int) $d] ?? '#' . $d; }
            foreach (array_filter(explode(',', (string) $p['employment_type_ids'])) as $d) { $for[] = $etypes[(int) $d] ?? '#' . $d; }
            echo '<tr' . ((int) $p['id'] === $sel ? ' style="background:#f4f8ff"' : '') . '><td data-l="Policy"><strong>' . e($p['name']) . '</strong>'
               . ($p['is_default'] ? ' <span class="pill blue">Default</span>' : '') . '</td><td data-l="Applies to">'
               . e($for ? implode(', ', $for) : ($p['is_default'] ? 'Everyone not covered by another policy' : 'Only employees it is set on')) . '</td>'
               . '<td data-l="Effective">' . e(($p['effective_from'] ? hr_fmt_date($p['effective_from']) : 'Always') . ($p['effective_to'] ? ' – ' . hr_fmt_date($p['effective_to']) : '')) . '</td>'
               . '<td class="num" data-l="Employees">' . hr_policy_reach((int) $p['id']) . '</td><td data-l="Status">' . hr_badge($p['active'] ? 'active' : 'inactive') . '</td>'
               . '<td class="hr-acts"><a class="btn btn-sm" href="' . e(hr_url((string) $GLOBALS['hr_page'], $keep + ['policy' => $p['id']])) . '#rules">Rules</a>';
            if ($can) {
                echo '<a class="btn btn-sm" href="' . e(hr_url((string) $GLOBALS['hr_page'], $keep + ['policy' => $p['id'], 'edit_policy' => 1])) . '#policy-form">Edit</a>'
                   . hr_button_form($p['active'] ? 'Deactivate' : 'Activate', ['action' => 'policy_toggle', 'id' => $p['id'], 'active' => $p['active'] ? 0 : 1]);
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';

    if ($pol) {
        $rules = hr_policy_rules((int) $pol['id']);
        $reach = hr_policy_reach((int) $pol['id']);
        echo '<div class="card" id="rules"><h2>Rules in “' . e($pol['name']) . '”</h2>';
        if ($reach) {
            echo '<div class="hr-note">This policy applies to <strong>' . $reach . '</strong> current employee(s). A change applies from its effective date: '
               . 'leave already credited or taken stays as it is.</div>';
        }
        if ($rules) {
            echo '<div class="scrollx"><table class="data hr-table stack"><thead><tr><th>Leave type</th><th class="num">Days / year</th><th>Credited</th><th>Carry forward</th>'
               . '<th>Limits</th><th>Weekly offs / holidays in leave</th><th>Effective</th><th></th></tr></thead><tbody>';
            foreach ($types as $t) {
                $r = $rules[(int) $t['id']] ?? null;
                if (!$r) { continue; }
                $lim = [];
                if ($r['max_consecutive'] !== null) { $lim[] = 'max ' . hr_fmt_days($r['max_consecutive']) . ' at a time'; }
                if ((int) $r['min_service_days']) { $lim[] = 'after ' . (int) $r['min_service_days'] . ' days of service'; }
                if (!$r['allow_in_probation']) { $lim[] = 'not in probation'; }
                if (!$r['allow_half_day']) { $lim[] = 'no half days'; }
                if ($r['allow_negative']) { $lim[] = 'may go ' . hr_fmt_days($r['negative_limit']) . ' below zero'; }
                if ($r['min_notice_days']) { $lim[] = (int) $r['min_notice_days'] . ' days notice'; }
                echo '<tr><td data-l="Leave type"><strong>' . e($t['name']) . '</strong></td><td class="num" data-l="Days">' . hr_fmt_days($r['annual_entitlement']) . '</td>'
                   . '<td data-l="Credited">' . e(['annual' => 'Yearly, up front', 'monthly' => 'Monthly (1/12 each month)', 'none' => 'Not credited automatically'][$r['accrual']])
                   . ($r['prorate_joining'] ? '<div class="muted small">Pro-rated for joiners</div>' : '') . '</td>'
                   . '<td data-l="Carry forward">' . ($r['carry_forward'] ? 'Yes' . ($r['carry_limit'] !== null ? ', up to ' . hr_fmt_days($r['carry_limit']) : ', no limit')
                        . ($r['carry_expiry_months'] ? '; expires after ' . (int) $r['carry_expiry_months'] . ' months' : '') : 'No')
                   . ($r['encashable'] ? '<div class="muted small">Encashable</div>' : '') . '</td>'
                   . '<td data-l="Limits">' . e($lim ? implode('; ', $lim) : '—') . '</td>'
                   . '<td data-l="Weekly offs / holidays">' . ($r['count_weekly_offs'] ? 'Weekly offs count' : 'Weekly offs skipped') . ', '
                   . ($r['count_holidays'] ? 'holidays count' : 'holidays skipped') . '</td><td data-l="Effective">' . e(hr_fmt_date($r['effective_from'])) . '</td><td class="hr-acts">';
                if ($can) {
                    echo '<a class="btn btn-sm" href="' . e(hr_url((string) $GLOBALS['hr_page'], $keep + ['policy' => $pol['id'], 'rule' => $t['id']])) . '#rule-form">Edit</a>'
                       . hr_button_form('Remove', ['action' => 'rule_delete', 'policy_id' => $pol['id'], 'leave_type_id' => $t['id']], 'btn btn-sm btn-danger',
                                        'Remove ' . $t['name'] . ' from this policy? Balances already credited stay; no more will be credited.');
                }
                echo '</td></tr>';
            }
            echo '</tbody></table></div>';
        } else {
            echo hr_empty('This policy has no leave types yet.', $can ? ' Add the first rule below.' : '');
        }
        echo '</div>';

        if ($can && $types) {
            $tid = (int) ($_GET['rule'] ?? 0);
            $r = $tid ? ($rules[$tid] ?? null) : null;
            $v = $r ?? ['annual_entitlement' => '', 'accrual' => 'annual', 'prorate_joining' => 1, 'carry_forward' => 0, 'carry_limit' => '',
                        'carry_expiry_months' => '', 'encashable' => 0, 'max_consecutive' => '', 'min_notice_days' => '', 'min_service_days' => 0,
                        'allow_in_probation' => 1, 'allow_half_day' => 1, 'allow_negative' => 0, 'negative_limit' => 0, 'count_weekly_offs' => 0,
                        'count_holidays' => 0, 'effective_from' => date('Y-m-d')];
            $typeOpts = [];
            foreach ($types as $t) { $typeOpts[(int) $t['id']] = $t['name'] . (isset($rules[(int) $t['id']]) ? ' (has a rule)' : ''); }
            echo '<div class="card" id="rule-form"><h2>' . ($r ? 'Edit rule' : 'Add a leave type to this policy') . '</h2>' . hr_form_open($back . '&policy=' . (int) $pol['id'], 'class="hr-form"')
               . '<input type="hidden" name="action" value="rule_save"><input type="hidden" name="policy_id" value="' . (int) $pol['id'] . '">'
               . '<div class="formsec"><h3>Entitlement</h3><div class="formgrid">'
               . hr_field('Leave type', hr_select('leave_type_id', $typeOpts, $tid ?: '', 'Choose…', ['required' => true]), true)
               . hr_field('Days per year', hr_input('annual_entitlement', $v['annual_entitlement'], 'number', ['step' => '0.5', 'min' => 0, 'max' => 366, 'placeholder' => 'e.g. 12']), true,
                          'Your company\'s figure. Nothing is filled in for you.')
               . hr_field('Credited', hr_select('accrual', ['annual' => 'Yearly, all at the start of the leave year', 'monthly' => 'Monthly, 1/12 each month',
                          'none' => 'Not automatically (opening balances and adjustments only)'], $v['accrual']))
               . hr_field('Effective from', hr_input('effective_from', $r ? date('Y-m-d') : $v['effective_from'], 'date'), false, 'Credits from this date use the new values.')
               . '</div><div class="field">' . hr_checkbox('prorate_joining', (bool) $v['prorate_joining'], 'Pro-rate for people who join part-way through the year') . '</div></div>'
               . '<div class="formsec"><h3>Carry forward and encashment</h3><div class="formgrid">'
               . '<div class="field">' . hr_checkbox('carry_forward', (bool) $v['carry_forward'], 'Unused days carry into the next leave year') . '</div>'
               . hr_field('Carry at most (days)', hr_input('carry_limit', $v['carry_limit'], 'number', ['step' => '0.5', 'min' => 0]), false, 'Blank = no limit.')
               . hr_field('Carried days expire after (months)', hr_input('carry_expiry_months', $v['carry_expiry_months'], 'number', ['min' => 1, 'max' => 24]), false, 'Blank = they do not expire.')
               . '<div class="field">' . hr_checkbox('encashable', (bool) $v['encashable'], 'Unused days can be encashed') . '</div>'
               . '</div></div><div class="formsec"><h3>Rules for taking it</h3><div class="formgrid">'
               . hr_field('Most days in one request', hr_input('max_consecutive', $v['max_consecutive'], 'number', ['step' => '0.5', 'min' => '0.5']), false, 'Blank = no limit.')
               . hr_field('Apply at least (days ahead)', hr_input('min_notice_days', $v['min_notice_days'], 'number', ['min' => 0]), false, 'Blank = no notice needed. HR can always record leave.')
               . hr_field('Available after (days of service)', hr_input('min_service_days', $v['min_service_days'], 'number', ['min' => 0]))
               . hr_field('May go below zero by (days)', hr_input('negative_limit', $v['negative_limit'], 'number', ['step' => '0.5', 'min' => 0]))
               . '</div><div class="formgrid">'
               . '<div class="field">' . hr_checkbox('allow_in_probation', (bool) $v['allow_in_probation'], 'Allowed during probation') . '</div>'
               . '<div class="field">' . hr_checkbox('allow_half_day', (bool) $v['allow_half_day'], 'Half days allowed') . '</div>'
               . '<div class="field">' . hr_checkbox('allow_negative', (bool) $v['allow_negative'], 'Negative balance allowed') . '</div>'
               . '<div class="field">' . hr_checkbox('count_weekly_offs', (bool) $v['count_weekly_offs'], 'Weekly offs inside the leave count as leave') . '</div>'
               . '<div class="field">' . hr_checkbox('count_holidays', (bool) $v['count_holidays'], 'Holidays inside the leave count as leave') . '</div>'
               . '</div></div>';
            if ($reach) {
                echo '<div class="hr-warn">' . hr_checkbox('confirm_impact', false, 'I understand this affects their balances from the effective date') . '</div>';
            }
            echo '<div class="hr-formfoot"><button class="btn btn-primary">Save rule</button>'
               . ($r ? '<a class="btn" href="' . e(hr_url((string) $GLOBALS['hr_page'], $keep + ['policy' => $pol['id']])) . '#rules">Cancel</a>' : '') . '</div></form></div>';
        } elseif ($can && !$types) {
            echo '<div class="hr-warn">Add leave types first, then give this policy a rule for each.</div>';
        }
    }

    if ($can) {
        $ed = !empty($_GET['edit_policy']) ? $pol : null;
        $selD = array_filter(explode(',', (string) ($ed['department_ids'] ?? '')));
        $selT = array_filter(explode(',', (string) ($ed['employment_type_ids'] ?? '')));
        echo '<div class="card" id="policy-form"><h2>' . ($ed ? 'Edit policy: ' . e($ed['name']) : 'Add a leave policy') . '</h2>' . hr_form_open($back, 'class="hr-form"')
           . '<input type="hidden" name="action" value="policy_save">' . ($ed ? '<input type="hidden" name="id" value="' . (int) $ed['id'] . '">' : '')
           . '<div class="formgrid">' . hr_field('Policy name', hr_input('name', $ed['name'] ?? '', 'text', ['required' => true, 'maxlength' => 120, 'placeholder' => 'e.g. Standard policy']), true)
           . hr_field('Effective from', hr_input('effective_from', $ed['effective_from'] ?? '', 'date'))
           . hr_field('Effective to', hr_input('effective_to', $ed['effective_to'] ?? '', 'date'))
           . hr_field('Description', hr_input('description', $ed['description'] ?? '', 'text', ['maxlength' => 255]), false, '', 'full')
           . '</div><div class="formgrid"><div class="field"><label>Departments</label><select name="department_ids[]" multiple size="4">'
           . hr_options($depts, null) . '</select><div class="hr-hint">Ctrl/Cmd-click to choose several. None = not tied to a department.</div></div>'
           . '<div class="field"><label>Employment types</label><select name="employment_type_ids[]" multiple size="4">' . hr_options($etypes, null) . '</select></div></div>'
           . '<div class="field">' . hr_checkbox('is_default', (bool) ($ed['is_default'] ?? !$pols), 'Default policy — for anyone no other policy covers') . '</div>'
           . '<div class="hr-formfoot"><button class="btn btn-primary">Save policy</button>' . ($ed ? '<a class="btn" href="' . e(hr_url((string) $GLOBALS['hr_page'], $keep + ['policy' => $ed['id']])) . '">Cancel</a>' : '') . '</div></form>';
        // Pre-select multi-selects (hr_options marks only one).
        if ($selD || $selT) {
            echo '<script>(function(){var d=' . json_encode(array_values($selD)) . ',t=' . json_encode(array_values($selT)) . ';'
               . 'document.querySelectorAll(\'select[name="department_ids[]"] option\').forEach(function(o){o.selected=d.indexOf(o.value)>-1;});'
               . 'document.querySelectorAll(\'select[name="employment_type_ids[]"] option\').forEach(function(o){o.selected=t.indexOf(o.value)>-1;});})();</script>';
        }
        echo '</div>';
    }
}

function hr_part_components(string $back, array $keep): void
{
    $can = hr_can_any(hr_cfg('payroll', 'configure'));
    $rows = hr_components(false);
    $edit = null;
    foreach ($rows as $r) {
        if ((int) $r['id'] === (int) ($_GET['edit_comp'] ?? 0)) { $edit = $r; }
    }
    echo '<div class="card"><h2>Salary components</h2><p class="hr-hint mt0">The building blocks of every salary structure. A change applies to payroll calculated '
       . 'after it; finalized payroll keeps the amounts it was paid with. Statutory rates (PF, ESI, PT, TDS) must be checked with your payroll adviser.</p>';
    if ($rows) {
        echo '<div class="scrollx"><table class="data hr-table stack"><thead><tr><th>Code</th><th>Component</th><th>Type</th><th>Calculated as</th>'
           . '<th>Limits</th><th>Reduces with unpaid days</th><th>Status</th><th></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $calc = $r['calc'] === 'fixed' ? 'Fixed' . ((float) $r['default_value'] ? ', usually ' . fabo_money_exact($r['default_value']) : '')
                  : ($r['calc'] === 'balance' ? 'Balance of gross' : hr_fmt_days($r['default_value']) . ($r['calc'] === 'pct_basic' ? '% of Basic' : '% of Gross'));
            $lim = [];
            if ($r['wage_ceiling'] !== null) { $lim[] = 'base capped at ' . fabo_money_exact($r['wage_ceiling']); }
            if ($r['gross_limit'] !== null) { $lim[] = 'only if gross ≤ ' . fabo_money_exact($r['gross_limit']); }
            echo '<tr><td data-l="Code"><strong>' . e($r['code']) . '</strong>' . ($r['is_basic'] ? ' <span class="pill blue">Basic</span>' : '') . '</td>'
               . '<td data-l="Component">' . e($r['name']) . '</td><td data-l="Type">' . e(hr_component_kinds()[$r['kind']] ?? $r['kind']) . '</td>'
               . '<td data-l="Calculated as">' . e($calc) . '</td><td data-l="Limits">' . e($lim ? implode('; ', $lim) : '—') . '</td>'
               . '<td data-l="Reduces">' . ($r['prorate'] ? 'Yes' : 'No') . '</td><td data-l="Status">' . hr_badge($r['active'] ? 'active' : 'inactive') . '</td><td class="hr-acts">';
            if ($can) {
                echo '<a class="btn btn-sm" href="' . e(hr_url((string) $GLOBALS['hr_page'], $keep + ['edit_comp' => $r['id']])) . '#comp-form">Edit</a>'
                   . hr_button_form($r['active'] ? 'Deactivate' : 'Activate', ['action' => 'comp_toggle', 'id' => $r['id'], 'active' => $r['active'] ? 0 : 1]);
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    } else {
        echo hr_empty('No salary components yet.', ' Pick from the common ones below, or add your own.');
    }
    if ($can) {
        $have = array_map(static fn($r) => $r['code'], $rows);
        $missing = array_diff_key(hr_component_templates(), array_flip($have));
        if ($missing) {
            echo '<details style="margin-top:12px"' . (!$rows ? ' open' : '') . '><summary class="btn btn-sm">Add common components</summary>' . hr_form_open($back)
               . '<input type="hidden" name="action" value="comp_templates"><p class="hr-hint">Commonly used in India. Rates shown are the usual statutory ones and are only a starting point — '
               . 'verify every rate, ceiling and threshold for your company before running payroll. Professional Tax and TDS start at zero: set them per employee.</p><div class="formgrid">';
            foreach ($missing as $code => [$name, $kind, $calc, $def, $ceil, $glim]) {
                $what = $calc === 'fixed' ? 'fixed' : ($calc === 'balance' ? 'balance of gross' : $def . '% of ' . ($calc === 'pct_basic' ? 'Basic' : 'Gross'))
                      . ($ceil ? ', capped ' . $ceil : '') . ($glim ? ', if gross ≤ ' . $glim : '');
                echo '<div class="field">' . hr_checkbox('codes[]', in_array($code, ['BASIC', 'HRA', 'SPL'], true), $code . ' — ' . $name . ' (' . $what . ')', $code) . '</div>';
            }
            echo '</div><button class="btn btn-sm btn-primary">Add selected</button></form></details>';
        }
    }
    echo '</div>';
    if (!$can) {
        return;
    }
    $v = $edit ?? ['code' => '', 'name' => '', 'kind' => 'earning', 'calc' => 'fixed', 'default_value' => '', 'wage_ceiling' => '', 'gross_limit' => '',
                   'prorate' => 1, 'is_basic' => 0, 'taxable' => 1, 'sort' => 0, 'description' => ''];
    echo '<div class="card" id="comp-form"><h2>' . ($edit ? 'Edit component: ' . e($edit['name']) : 'Add a salary component') . '</h2>' . hr_form_open($back, 'class="hr-form"')
       . '<input type="hidden" name="action" value="comp_save">' . ($edit ? '<input type="hidden" name="id" value="' . (int) $edit['id'] . '">' : '') . '<div class="formgrid">'
       . hr_field('Code', hr_input('code', $v['code'], 'text', ['required' => true, 'maxlength' => 20, 'placeholder' => 'e.g. HRA']), true)
       . hr_field('Name', hr_input('name', $v['name'], 'text', ['required' => true, 'maxlength' => 120, 'placeholder' => 'e.g. House Rent Allowance']), true)
       . hr_field('Type', hr_select('kind', hr_component_kinds(), $v['kind']), true)
       . hr_field('Calculated as', hr_select('calc', hr_component_calcs(), $v['calc']), true)
       . hr_field('Default amount or percentage', hr_input('default_value', $v['default_value'], 'number', ['step' => '0.01', 'min' => 0]), false,
                  'An amount for “Fixed”, a percentage otherwise. Each employee\'s structure can differ.')
       . hr_field('Base is capped at (₹)', hr_input('wage_ceiling', $v['wage_ceiling'], 'number', ['step' => '0.01', 'min' => 0]), false, 'For a percentage; e.g. a PF wage ceiling. Blank = no cap.')
       . hr_field('Only when monthly gross is at most (₹)', hr_input('gross_limit', $v['gross_limit'], 'number', ['step' => '0.01', 'min' => 0]), false, 'e.g. an ESI threshold. Blank = always.')
       . hr_field('Order', hr_input('sort', $v['sort'], 'number'))
       . '</div><div class="formgrid">'
       . '<div class="field">' . hr_checkbox('prorate', (bool) $v['prorate'], 'Reduces with unpaid days (pro-rated)') . '</div>'
       . '<div class="field">' . hr_checkbox('is_basic', (bool) $v['is_basic'], 'This is Basic salary') . '</div>'
       . '<div class="field">' . hr_checkbox('taxable', (bool) $v['taxable'], 'Taxable') . '</div>'
       . '</div><div class="hr-formfoot"><button class="btn btn-primary">Save component</button>'
       . ($edit ? '<a class="btn" href="' . e(hr_url((string) $GLOBALS['hr_page'], $keep)) . '">Cancel</a>' : '') . '</div></form></div>';
}

function hr_part_history(string $area = ''): void
{
    $rows = fabo_all('SELECT * FROM hr_config_history WHERE org_id = ?' . ($area !== '' ? ' AND area = ?' : '') . ' ORDER BY id DESC LIMIT 300',
                     'i' . ($area !== '' ? 's' : ''), $area !== '' ? [hr_org(), $area] : [hr_org()]);
    echo '<div class="card"><h2>Change history</h2><p class="hr-hint mt0">Every configuration change, who made it and when it takes effect. Nothing here can be edited.</p>';
    if (!$rows) {
        echo hr_empty('No changes recorded yet.');
    } else {
        echo '<div class="scrollx"><table class="data hr-table stack"><thead><tr><th>When</th><th>Area</th><th>Item</th><th>Before</th><th>After</th><th>Effective</th><th>By</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr><td data-l="When" class="nowrap">' . e(date('d M Y H:i', strtotime($r['changed_at']))) . '</td><td data-l="Area">' . e(ucfirst($r['area'])) . '</td>'
               . '<td data-l="Item">' . e($r['item']) . '</td><td data-l="Before" class="small">' . e(mb_strimwidth((string) $r['old_value'], 0, 220, '…')) . '</td>'
               . '<td data-l="After" class="small">' . e(mb_strimwidth((string) $r['new_value'], 0, 220, '…')) . '</td><td data-l="Effective">' . e(hr_fmt_date($r['effective_from'])) . '</td>'
               . '<td data-l="By">' . e($r['changed_by']) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';
}

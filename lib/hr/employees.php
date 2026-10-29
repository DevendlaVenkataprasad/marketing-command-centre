<?php
/**
 * Employees: the register, profiles, history of changes, documents, and
 * bulk import.
 *
 * An employee may be linked to the account a person already signs in with
 * (a `clients` row — our staff, or a client's own people). That link is how
 * self-service finds "my" record; it never creates an account and never
 * changes what the account can do outside HR.
 */

require_once __DIR__ . '/masters.php';

function hr_emp_statuses(): array
{
    return [
        'active'     => 'Active',
        'probation'  => 'Probation',
        'notice'     => 'On Notice',
        'inactive'   => 'Inactive',
        'resigned'   => 'Resigned',
        'terminated' => 'Terminated',
    ];
}

/** Statuses that count as currently employed. */
function hr_working_statuses(): array
{
    return ['active', 'probation', 'notice'];
}

function hr_status_label(string $s): string
{
    return hr_emp_statuses()[$s] ?? ucfirst($s);
}

/** One employee of this organisation, or null. Scope is checked by the caller. */
function hr_employee(int $id): ?array
{
    return fabo_one('SELECT * FROM hr_employees WHERE id = ? AND org_id = ?', 'ii', [$id, hr_org()]);
}

/** One employee with the names of everything it points at. */
function hr_employee_full(int $id): ?array
{
    return fabo_one('SELECT e.*, d.name AS department, g.name AS designation, m.full_name AS manager,
                            t.name AS employment_type, l.name AS location, c.name AS category,
                            hc.name AS holiday_calendar, lp.name AS leave_policy, p.name AS account_name, p.email AS account_email
                       FROM hr_employees e
                       LEFT JOIN hr_departments d ON d.id = e.department_id
                       LEFT JOIN hr_designations g ON g.id = e.designation_id
                       LEFT JOIN hr_employees m ON m.id = e.manager_id
                       LEFT JOIN hr_masters t ON t.id = e.employment_type_id
                       LEFT JOIN hr_masters l ON l.id = e.location_id
                       LEFT JOIN hr_masters c ON c.id = e.category_id
                       LEFT JOIN hr_holiday_calendars hc ON hc.id = e.holiday_calendar_id
                       LEFT JOIN hr_leave_policies lp ON lp.id = e.leave_policy_id
                       LEFT JOIN clients p ON p.id = e.person_id
                      WHERE e.id = ? AND e.org_id = ?', 'ii', [$id, hr_org()]);
}

/** id => "Name (CODE)" for every employee in the org, for pickers. */
function hr_employee_choices(bool $workingOnly = true, string $module = 'employees', string $action = 'view'): array
{
    $sc  = hr_scope_sql($module, $action, 'e');
    $sql = 'SELECT e.id, e.full_name, e.emp_code FROM hr_employees e WHERE e.org_id = ?'
         . ($workingOnly ? " AND e.status IN ('active','probation','notice')" : '') . $sc['sql'] . ' ORDER BY e.full_name';
    $out = [];
    foreach (fabo_all($sql, 'i', [hr_org()]) as $r) {
        $out[(int) $r['id']] = $r['full_name'] . ' (' . $r['emp_code'] . ')';
    }
    return $out;
}

/**
 * Accounts that may be linked to an employee of this org: our own staff for
 * our company; the client and the people they created for a client org.
 * Accounts already linked to another employee are left out.
 */
function hr_person_choices(?int $keepId = null): array
{
    $org = hr_org();
    if ($org === 0) {
        $rows = fabo_all("SELECT c.id, c.name, c.email FROM clients c
                          WHERE c.kind = 'team' AND (c.parent_id IS NULL OR c.parent_id = 0)
                          ORDER BY c.name");
    } else {
        $rows = fabo_all('SELECT c.id, c.name, c.email FROM clients c WHERE c.id = ? OR c.parent_id = ? ORDER BY c.name',
                         'ii', [$org, $org]);
    }
    $taken = [];
    foreach (fabo_all('SELECT person_id FROM hr_employees WHERE person_id IS NOT NULL') as $r) {
        $taken[(int) $r['person_id']] = true;
    }
    $out = [];
    foreach ($rows as $r) {
        $id = (int) $r['id'];
        if (isset($taken[$id]) && $id !== $keepId) {
            continue;
        }
        $out[$id] = $r['name'] . ($r['email'] ? ' — ' . $r['email'] : '');
    }
    return $out;
}

function hr_person_in_org(int $personId): bool
{
    return array_key_exists($personId, hr_person_choices($personId));
}

/* ------------------------------------------------------------- IDs */

/**
 * The next employee ID, from the format in settings. The counter only ever
 * goes up — inside the caller's transaction, under a row lock — so an ID is
 * never handed out twice, even after an employee is removed.
 */
function hr_next_emp_code(?string $doj = null): string
{
    $org = hr_org();
    $fmt = (string) (hr_setting('emp_code_format') ?: 'EMP{SEQ}');
    fabo_exec("INSERT IGNORE INTO hr_settings (org_id, k, v) VALUES (?, '_emp_seq', '0')", 'i', [$org]);
    for ($i = 0; $i < 50; $i++) {
        $row = fabo_one("SELECT v FROM hr_settings WHERE org_id = ? AND k = '_emp_seq' FOR UPDATE", 'i', [$org]);
        $seq = (int) ($row['v'] ?? 0) + 1;
        fabo_exec("UPDATE hr_settings SET v = ? WHERE org_id = ? AND k = '_emp_seq'", 'si', [(string) $seq, $org]);
        $code = strtr($fmt, [
            '{SEQ}' => str_pad((string) $seq, 4, '0', STR_PAD_LEFT),
            '{YY}'  => substr($doj ?: date('Y-m-d'), 2, 2),
            '{YYYY}' => substr($doj ?: date('Y-m-d'), 0, 4),
        ]);
        if (!fabo_one('SELECT id FROM hr_employees WHERE org_id = ? AND emp_code = ?', 'is', [$org, $code])) {
            return $code;
        }
    }
    throw new RuntimeException('Could not find a free employee ID. Check the ID format under Policies & Rules → Employee IDs.');
}

/* ------------------------------------------------------------- saving */

/** Fields whose change is kept in the employee's history, with readable names. */
function hr_emp_tracked(): array
{
    return [
        'department_id' => 'Department', 'designation_id' => 'Designation', 'manager_id' => 'Reporting manager',
        'status' => 'Status', 'employment_type_id' => 'Employment type', 'biometric_id' => 'Biometric ID', 'location_id' => 'Work location',
        'leave_policy_id' => 'Leave policy', 'holiday_calendar_id' => 'Holiday calendar', 'weekly_offs' => 'Weekly offs',
        'date_of_leaving' => 'Date of leaving', 'category_id' => 'Category', 'full_name' => 'Name', 'work_email' => 'Work email',
    ];
}

/**
 * Creates or updates an employee. $p is the posted form. Confidential
 * fields (bank, IDs) are written only when $confidential is true, so a user
 * without that right cannot overwrite them by adding fields to the form.
 * Returns the employee id.
 */
function hr_employee_save(?int $id, array $p, bool $confidential, ?array $photo = null): int
{
    $org    = hr_org();
    $before = $id ? hr_employee($id) : null;
    if ($id && !$before) {
        throw new InvalidArgumentException('That employee no longer exists.');
    }

    $v = [];
    $v['full_name']  = hr_str($p['full_name'] ?? '', 160, true, 'Full name');
    $bio = hr_str($p['biometric_id'] ?? '', 40);
    if ($bio !== '' && !preg_match('/^[A-Za-z0-9\/_.-]{1,40}$/', $bio)) {
        throw new InvalidArgumentException('Biometric ID may contain letters, digits, / _ . and - only.');
    }
    $v['biometric_id'] = $bio !== '' ? $bio : null;
    $email = hr_str($p['work_email'] ?? '', 190);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Work email is not a valid email address.');
    }
    $v['work_email'] = $email !== '' ? mb_strtolower($email) : null;
    $phone = hr_str($p['phone'] ?? '', 40);
    if ($phone !== '' && !preg_match('/^[0-9+()\-\s]{6,40}$/', $phone)) {
        throw new InvalidArgumentException('Phone number may contain only digits, spaces, +, - and brackets.');
    }
    $v['phone']          = $phone ?: null;
    $v['date_of_birth']  = hr_date($p['date_of_birth'] ?? '', false, 'Date of birth');
    $v['gender']         = hr_str($p['gender'] ?? '', 20) ?: null;
    $v['department_id']  = hr_ref_id($p['department_id'] ?? 0, 'hr_departments', 'Department');
    $v['designation_id'] = hr_ref_id($p['designation_id'] ?? 0, 'hr_designations', 'Designation');
    $v['manager_id']     = hr_ref_id($p['manager_id'] ?? 0, 'hr_employees', 'Reporting manager');
    $v['date_of_joining'] = hr_date($p['date_of_joining'] ?? '', true, 'Date of joining');
    $v['probation_end']  = hr_date($p['probation_end'] ?? '', false, 'Probation ends');
    $v['date_of_leaving'] = hr_date($p['date_of_leaving'] ?? '', false, 'Date of leaving');
    $v['employment_type_id'] = hr_ref_id($p['employment_type_id'] ?? 0, 'hr_masters', 'Employment type');
    $v['category_id']    = hr_ref_id($p['category_id'] ?? 0, 'hr_masters', 'Employee category');
    $v['location_id']    = hr_ref_id($p['location_id'] ?? 0, 'hr_masters', 'Work location');
    $v['holiday_calendar_id'] = hr_ref_id($p['holiday_calendar_id'] ?? 0, 'hr_holiday_calendars', 'Holiday calendar');
    $v['leave_policy_id'] = hr_ref_id($p['leave_policy_id'] ?? 0, 'hr_leave_policies', 'Leave policy');
    $status = (string) ($p['status'] ?? 'active');
    if (!isset(hr_emp_statuses()[$status])) {
        throw new InvalidArgumentException('Choose an employment status.');
    }
    $v['status'] = $status;
    $v['weekly_offs'] = !empty($p['own_offs']) ? hr_offs_from_post($p) : null;
    $v['emergency_name']     = hr_str($p['emergency_name'] ?? '', 160) ?: null;
    $v['emergency_relation'] = hr_str($p['emergency_relation'] ?? '', 60) ?: null;
    $v['emergency_phone']    = hr_str($p['emergency_phone'] ?? '', 40) ?: null;
    $v['address']            = hr_str($p['address'] ?? '', 1000) ?: null;
    $v['notes']              = hr_str($p['notes'] ?? '', 2000) ?: null;

    if ($confidential) {
        $v['bank_name']    = hr_str($p['bank_name'] ?? '', 120) ?: null;
        $acct = preg_replace('/\s+/', '', hr_str($p['bank_account'] ?? '', 40));
        if ($acct !== '' && !preg_match('/^[0-9A-Za-z]{6,34}$/', $acct)) {
            throw new InvalidArgumentException('Bank account number should be 6–34 letters or digits.');
        }
        $v['bank_account'] = $acct ?: null;
        $ifsc = strtoupper(hr_str($p['bank_ifsc'] ?? '', 20));
        if ($ifsc !== '' && !preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc)) {
            throw new InvalidArgumentException('IFSC should look like ABCD0123456 (4 letters, a zero, then 6 letters or digits).');
        }
        $v['bank_ifsc'] = $ifsc ?: null;
        $pan = strtoupper(hr_str($p['pan'] ?? '', 20));
        if ($pan !== '' && !preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $pan)) {
            throw new InvalidArgumentException('PAN should look like ABCDE1234F.');
        }
        $v['pan']          = $pan ?: null;
        $v['uan']          = hr_str($p['uan'] ?? '', 20) ?: null;
        $v['pf_number']    = hr_str($p['pf_number'] ?? '', 40) ?: null;
        $v['esi_number']   = hr_str($p['esi_number'] ?? '', 40) ?: null;
        $v['payment_mode'] = in_array($p['payment_mode'] ?? '', ['bank', 'cash', 'cheque'], true) ? $p['payment_mode'] : null;
    }

    // Cross-field rules.
    if ($v['date_of_leaving'] && $v['date_of_leaving'] < $v['date_of_joining']) {
        throw new InvalidArgumentException('Date of leaving cannot be before the date of joining.');
    }
    if ($v['probation_end'] && $v['probation_end'] < $v['date_of_joining']) {
        throw new InvalidArgumentException('Probation cannot end before the date of joining.');
    }
    if ($v['date_of_birth'] && $v['date_of_birth'] > date('Y-m-d', strtotime('-14 years'))) {
        throw new InvalidArgumentException('Date of birth looks wrong: the employee would be under 14.');
    }
    if ($v['manager_id'] && $id) {
        if ($v['manager_id'] === $id) {
            throw new InvalidArgumentException('An employee cannot report to themselves.');
        }
        if (in_array($v['manager_id'], hr_team_ids($id), true)) {
            throw new InvalidArgumentException('That manager reports (directly or indirectly) to this employee, which would make a loop.');
        }
    }
    if ($v['biometric_id'] !== null) {
        $dup = fabo_one('SELECT emp_code, full_name FROM hr_employees WHERE org_id = ? AND biometric_id = ?' . ($id ? ' AND id <> ?' : ''),
                        'is' . ($id ? 'i' : ''), array_merge([$org, $v['biometric_id']], $id ? [$id] : []));
        if ($dup) {
            throw new InvalidArgumentException('Biometric ID ' . $v['biometric_id'] . ' already belongs to ' . $dup['full_name']
                                             . ' (' . $dup['emp_code'] . '). Each person has their own ID on the machine.');
        }
    }
    if ($v['work_email']) {
        $dup = fabo_one('SELECT emp_code, full_name FROM hr_employees WHERE org_id = ? AND work_email = ?' . ($id ? ' AND id <> ?' : ''),
                        'is' . ($id ? 'i' : ''), array_merge([$org, $v['work_email']], $id ? [$id] : []));
        if ($dup) {
            throw new InvalidArgumentException('The work email ' . $v['work_email'] . ' already belongs to ' . $dup['full_name']
                                             . ' (' . $dup['emp_code'] . '). Each employee needs their own.');
        }
    }

    // Linking a sign-in account.
    $person = (int) ($p['person_id'] ?? 0);
    if ($person > 0) {
        if (!hr_person_in_org($person) && (int) ($before['person_id'] ?? 0) !== $person) {
            throw new InvalidArgumentException('That sign-in account cannot be linked: it belongs to another organisation or is linked to another employee.');
        }
        $v['person_id'] = $person;
    } else {
        $v['person_id'] = null;
    }

    // Employee ID: typed (validated) or generated.
    $typedCode = strtoupper(hr_str($p['emp_code'] ?? '', 30));
    if ($typedCode !== '' && !preg_match('/^[A-Z0-9][A-Z0-9\/_.-]{0,29}$/', $typedCode)) {
        throw new InvalidArgumentException('Employee ID may contain letters, digits, / _ . and - only.');
    }

    return hr_tx(function () use ($id, $before, $v, $typedCode, $org, $p, $photo) {
        if ($id) {
            if ($typedCode !== '' && $typedCode !== $before['emp_code']) {
                hr_assert_code_free($typedCode, $id);
                $v['emp_code'] = $typedCode;
            }
            $cols = array_keys($v);
            $set  = implode(', ', array_map(static fn($c) => '`' . $c . '` = ?', $cols));
            fabo_exec('UPDATE hr_employees SET ' . $set . ' WHERE id = ? AND org_id = ?',
                      str_repeat('s', count($cols)) . 'ii', array_merge(array_values($v), [$id, $org]));
            hr_emp_history($id, $before, $v);
            hr_audit('update', 'employee', $id, 'Employee updated: ' . $v['full_name'], hr_emp_redact($before), hr_emp_redact($v));
        } else {
            $code = $typedCode !== '' ? $typedCode : hr_next_emp_code($v['date_of_joining']);
            hr_assert_code_free($code, 0);
            $v['emp_code'] = $code;
            $cols = array_keys($v);
            $id = fabo_exec('INSERT INTO hr_employees (org_id, ' . implode(', ', array_map(static fn($c) => '`' . $c . '`', $cols))
                          . ', created_by) VALUES (?, ' . implode(', ', array_fill(0, count($cols), '?')) . ', ?)',
                            'i' . str_repeat('s', count($cols)) . 's', array_merge([$org], array_values($v), [hr_actor()]));
            fabo_exec('INSERT INTO hr_employee_history (employee_id, field, old_value, new_value, effective_on, changed_by)
                       VALUES (?,?,?,?,?,?)', 'isssss', [$id, 'Record', null, 'Created (' . $code . ')', $v['date_of_joining'], hr_actor()]);
            hr_audit('create', 'employee', $id, 'Employee created: ' . $v['full_name'] . ' (' . $code . ')', null, hr_emp_redact($v));
        }
        $shiftId = (int) ($p['shift_id'] ?? 0);
        if ($shiftId > 0) {
            $cur = hr_emp_shift_now($id);
            if (!$cur || (int) $cur['shift_id'] !== $shiftId) {
                $from = $before ? (hr_date($p['shift_from'] ?? '') ?: date('Y-m-d')) : $v['date_of_joining'];
                hr_shift_assign($id, $shiftId, $from);
            }
        }
        if ($photo && ($photo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            hr_employee_photo($id, $photo);
        }
        return $id;
    });
}

function hr_assert_code_free(string $code, int $exceptId): void
{
    if (fabo_one('SELECT id FROM hr_employees WHERE org_id = ? AND emp_code = ? AND id <> ?', 'isi', [hr_org(), $code, $exceptId])) {
        throw new InvalidArgumentException('Employee ID ' . $code . ' is already in use. Leave it blank to have one generated.');
    }
}

/** Bank and ID numbers never go into the audit log in full. */
function hr_emp_redact(?array $row): ?array
{
    if (!$row) {
        return $row;
    }
    foreach (['bank_account', 'pan', 'uan', 'pf_number', 'esi_number'] as $k) {
        if (!empty($row[$k])) {
            $row[$k] = '••••' . substr((string) $row[$k], -4);
        }
    }
    return $row;
}

function hr_emp_history(int $id, array $before, array $after): void
{
    $names = null;
    foreach (hr_emp_tracked() as $field => $label) {
        if (!array_key_exists($field, $after)) {
            continue;
        }
        $old = $before[$field] ?? null;
        $new = $after[$field] ?? null;
        if ((string) $old === (string) $new) {
            continue;
        }
        $names ??= hr_emp_ref_names();
        $fmt = static function ($val) use ($field, $names) {
            if ($val === null || $val === '') {
                return '—';
            }
            if ($field === 'status') {
                return hr_status_label((string) $val);
            }
            if ($field === 'weekly_offs') {
                return hr_offs_words((string) $val);
            }
            return $names[$field][(int) $val] ?? (string) $val;
        };
        $eff = $field === 'date_of_leaving' ? ($new ?: null) : date('Y-m-d');
        fabo_exec('INSERT INTO hr_employee_history (employee_id, field, old_value, new_value, effective_on, changed_by)
                   VALUES (?,?,?,?,?,?)', 'isssss', [$id, $label, mb_substr($fmt($old), 0, 255), mb_substr($fmt($new), 0, 255), $eff, hr_actor()]);
    }
}

function hr_emp_ref_names(): array
{
    $org = hr_org();
    $m = static fn(string $sql) => hr_map($sql, [$org]);
    return [
        'department_id'  => $m('SELECT id, name FROM hr_departments WHERE org_id = ?'),
        'designation_id' => $m('SELECT id, name FROM hr_designations WHERE org_id = ?'),
        'manager_id'     => $m('SELECT id, full_name AS name FROM hr_employees WHERE org_id = ?'),
        'employment_type_id' => $m('SELECT id, name FROM hr_masters WHERE org_id = ?'),
        'location_id'    => $m('SELECT id, name FROM hr_masters WHERE org_id = ?'),
        'category_id'    => $m('SELECT id, name FROM hr_masters WHERE org_id = ?'),
        'leave_policy_id' => $m('SELECT id, name FROM hr_leave_policies WHERE org_id = ?'),
        'holiday_calendar_id' => $m('SELECT id, name FROM hr_holiday_calendars WHERE org_id = ?'),
    ];
}

function hr_emp_shift_now(int $empId): ?array
{
    return fabo_one('SELECT * FROM hr_shift_assignments WHERE employee_id = ? AND effective_from <= CURDATE()
                     AND (effective_to IS NULL OR effective_to >= CURDATE()) ORDER BY effective_from DESC LIMIT 1', 'i', [$empId]);
}

/** What an employee may change about themselves: contact details and emergency contact. */
function hr_employee_self_update(int $id, array $p): void
{
    $before = hr_employee($id);
    if (!$before) {
        throw new InvalidArgumentException('That employee no longer exists.');
    }
    $phone = hr_str($p['phone'] ?? '', 40);
    if ($phone !== '' && !preg_match('/^[0-9+()\-\s]{6,40}$/', $phone)) {
        throw new InvalidArgumentException('Phone number may contain only digits, spaces, +, - and brackets.');
    }
    $v = [
        'phone' => $phone ?: null,
        'address' => hr_str($p['address'] ?? '', 1000) ?: null,
        'emergency_name' => hr_str($p['emergency_name'] ?? '', 160) ?: null,
        'emergency_relation' => hr_str($p['emergency_relation'] ?? '', 60) ?: null,
        'emergency_phone' => hr_str($p['emergency_phone'] ?? '', 40) ?: null,
    ];
    fabo_exec('UPDATE hr_employees SET phone = ?, address = ?, emergency_name = ?, emergency_relation = ?, emergency_phone = ?
               WHERE id = ? AND org_id = ?', 'sssssii', array_merge(array_values($v), [$id, hr_org()]));
    hr_audit('self_update', 'employee', $id, 'Contact details updated by the employee', $before, $v);
}

/** Ends employment without deleting anything: history, leave and payroll stay. */
function hr_employee_deactivate(int $id, string $status, ?string $leaving, string $reason): void
{
    if (!in_array($status, ['inactive', 'resigned', 'terminated'], true)) {
        throw new InvalidArgumentException('Choose why the employee is being deactivated.');
    }
    $before = hr_employee($id);
    if (!$before) {
        throw new InvalidArgumentException('That employee no longer exists.');
    }
    $leaving = hr_date($leaving, $status !== 'inactive', 'Last working day');
    if ($leaving && $before['date_of_joining'] && $leaving < $before['date_of_joining']) {
        throw new InvalidArgumentException('The last working day cannot be before the date of joining.');
    }
    $after = ['status' => $status, 'date_of_leaving' => $leaving];
    fabo_exec('UPDATE hr_employees SET status = ?, date_of_leaving = ? WHERE id = ? AND org_id = ?',
              'ssii', [$status, $leaving, $id, hr_org()]);
    hr_emp_history($id, $before, $after);
    hr_audit('deactivate', 'employee', $id, 'Employee ' . hr_status_label($status) . ': ' . $before['full_name']
           . ($reason !== '' ? ' — ' . $reason : ''), hr_emp_redact($before), $after);
}

function hr_employee_reactivate(int $id): void
{
    $before = hr_employee($id);
    if (!$before) {
        throw new InvalidArgumentException('That employee no longer exists.');
    }
    fabo_exec("UPDATE hr_employees SET status = 'active', date_of_leaving = NULL WHERE id = ? AND org_id = ?", 'ii', [$id, hr_org()]);
    hr_emp_history($id, $before, ['status' => 'active', 'date_of_leaving' => null]);
    hr_audit('reactivate', 'employee', $id, 'Employee reactivated: ' . $before['full_name']);
}

function hr_employee_history(int $id): array
{
    return fabo_all('SELECT * FROM hr_employee_history WHERE employee_id = ? ORDER BY changed_at DESC, id DESC LIMIT 200', 'i', [$id]);
}

/* ------------------------------------------------------- photo & docs */

function hr_employee_photo(int $id, array $file): void
{
    [$ok, $msg, $meta] = fabo_store_upload($file);
    if (!$ok) {
        throw new InvalidArgumentException('Photo: ' . $msg);
    }
    if (!fabo_is_inline_image($meta['mime'])) {
        fabo_delete_stored($meta['stored_name']);
        throw new InvalidArgumentException('The photo must be a JPEG, PNG, GIF or WebP image.');
    }
    $old = hr_employee($id)['photo_file'] ?? null;
    fabo_exec('UPDATE hr_employees SET photo_file = ?, photo_mime = ? WHERE id = ? AND org_id = ?',
              'ssii', [$meta['stored_name'], $meta['mime'], $id, hr_org()]);
    if ($old) {
        fabo_delete_stored($old);
    }
    hr_audit('photo', 'employee', $id, 'Profile photo updated');
}

function hr_employee_docs(int $id): array
{
    return fabo_all('SELECT * FROM hr_employee_docs WHERE employee_id = ? ORDER BY created_at DESC', 'i', [$id]);
}

function hr_employee_doc_add(int $empId, string $title, array $file): void
{
    $title = hr_str($title, 160, true, 'Document title');
    [$ok, $msg, $meta] = fabo_store_upload($file);
    if (!$ok) {
        throw new InvalidArgumentException($msg);
    }
    $docId = fabo_exec('INSERT INTO hr_employee_docs (employee_id, title, stored_name, original_name, mime, size, uploaded_by)
               VALUES (?,?,?,?,?,?,?)', 'issssis',
              [$empId, $title, $meta['stored_name'], $meta['original_name'], $meta['mime'], $meta['size'], hr_actor()]);
    hr_audit('upload', 'employee', $empId, 'Document added: ' . $title, null, ['doc_id' => $docId, 'file' => $meta['original_name']]);
}

function hr_employee_doc_delete(int $empId, int $docId): void
{
    $doc = fabo_one('SELECT * FROM hr_employee_docs WHERE id = ? AND employee_id = ?', 'ii', [$docId, $empId]);
    if (!$doc) {
        throw new InvalidArgumentException('That document no longer exists.');
    }
    fabo_exec('DELETE FROM hr_employee_docs WHERE id = ?', 'i', [$docId]);
    fabo_delete_stored($doc['stored_name']);
    hr_audit('delete_doc', 'employee', $empId, 'Document removed: ' . $doc['title'], $doc, null);
}

/* -------------------------------------------------------------- listing */

/**
 * The register, filtered, scoped and sorted. Returns [rows, total].
 * $f: q, department, designation, status ('working' | a status | ''), type, location, manager.
 */
function hr_employees_list(array $f, string $sort = 'name', string $dir = 'asc', int $offset = 0, int $limit = 50): array
{
    $where = ' WHERE e.org_id = ?';
    $types = 'i';
    $params = [hr_org()];
    if (($q = trim((string) ($f['q'] ?? ''))) !== '') {
        $where .= ' AND (e.full_name LIKE ? OR e.emp_code LIKE ? OR e.work_email LIKE ? OR e.phone LIKE ?)';
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
        $types .= 'ssss';
        array_push($params, $like, $like, $like, $like);
    }
    foreach (['department' => 'department_id', 'designation' => 'designation_id', 'type' => 'employment_type_id',
              'location' => 'location_id', 'manager' => 'manager_id'] as $k => $col) {
        if (!empty($f[$k])) {
            $where .= ' AND e.' . $col . ' = ?';
            $types .= 'i';
            $params[] = (int) $f[$k];
        }
    }
    $st = (string) ($f['status'] ?? 'working');
    if ($st === 'working') {
        $where .= " AND e.status IN ('active','probation','notice')";
    } elseif ($st !== '' && isset(hr_emp_statuses()[$st])) {
        $where .= ' AND e.status = ?';
        $types .= 's';
        $params[] = $st;
    }
    $sc = hr_scope_sql('employees', 'view', 'e');
    $where .= $sc['sql'];

    $order = [
        'name' => 'e.full_name', 'code' => 'e.emp_code', 'dept' => 'd.name', 'desig' => 'g.name',
        'doj' => 'e.date_of_joining', 'status' => 'e.status',
    ][$sort] ?? 'e.full_name';
    $order .= strtolower($dir) === 'desc' ? ' DESC' : ' ASC';

    $from = ' FROM hr_employees e LEFT JOIN hr_departments d ON d.id = e.department_id
              LEFT JOIN hr_designations g ON g.id = e.designation_id
              LEFT JOIN hr_employees m ON m.id = e.manager_id
              LEFT JOIN hr_masters l ON l.id = e.location_id
              LEFT JOIN hr_masters t ON t.id = e.employment_type_id';
    $total = (int) (fabo_one('SELECT COUNT(*) n' . $from . $where, $types, $params)['n'] ?? 0);
    $rows  = fabo_all('SELECT e.*, d.name AS department, g.name AS designation, m.full_name AS manager, l.name AS location,
                              t.name AS employment_type' . $from . $where . ' ORDER BY ' . $order . ', e.id LIMIT '
                    . max(0, $offset) . ', ' . max(1, min(5000, $limit)), $types, $params);
    return [$rows, $total];
}

/* --------------------------------------------------------------- import */

/** Import columns: header names accepted (lower-case) => field. */
function hr_employee_import_columns(): array
{
    return [
        'employee id' => 'emp_code', 'emp code' => 'emp_code', 'employee code' => 'emp_code',
        'biometric id' => 'biometric_id', 'person id' => 'biometric_id', 'machine id' => 'biometric_id',
        'full name' => 'full_name', 'name' => 'full_name',
        'work email' => 'work_email', 'email' => 'work_email',
        'phone' => 'phone', 'phone number' => 'phone', 'mobile' => 'phone',
        'department' => 'department', 'designation' => 'designation',
        'reporting manager' => 'manager', 'manager' => 'manager',
        'date of joining' => 'date_of_joining', 'doj' => 'date_of_joining', 'joining date' => 'date_of_joining',
        'date of birth' => 'date_of_birth', 'dob' => 'date_of_birth',
        'employment type' => 'employment_type', 'status' => 'status', 'employment status' => 'status',
        'work location' => 'location', 'location' => 'location', 'category' => 'category',
        'address' => 'address', 'gender' => 'gender',
        'emergency contact' => 'emergency_name', 'emergency phone' => 'emergency_phone',
    ];
}

/**
 * Reads an uploaded CSV or XLSX of employees and creates each valid row.
 * Departments, designations, types and locations are matched by name and
 * must already exist (so a typo does not invent a department). With $dryRun
 * nothing is saved and the report says what would happen.
 * Returns ['created' => n, 'errors' => [line => message], 'rows' => n].
 */
function hr_employee_import(string $path, string $name, bool $dryRun): array
{
    $rows = hr_read_table_file($path, $name);
    if (!$rows) {
        throw new InvalidArgumentException('The file is empty.');
    }
    [$line0, $head] = array_shift($rows);
    $map = hr_employee_import_columns();
    $cols = [];
    foreach ($head as $i => $h) {
        $k = mb_strtolower(trim((string) $h));
        if (isset($map[$k])) {
            $cols[$i] = $map[$k];
        }
    }
    if (!in_array('full_name', $cols, true) || !in_array('date_of_joining', $cols, true)) {
        throw new InvalidArgumentException('The first row must be headings, including “Full Name” and “Date of Joining”. '
                                         . 'Download the template to see every column.');
    }
    $lookup = static function (array $mapIdName, string $val): ?int {
        $val = mb_strtolower(trim($val));
        foreach ($mapIdName as $id => $n) {
            if (mb_strtolower($n) === $val) {
                return (int) $id;
            }
        }
        return null;
    };
    $depts = hr_departments_map();
    $desig = hr_designations_map();
    $types = hr_master_map('employment_type');
    $locs  = hr_master_map('location');
    $cats  = hr_master_map('category');
    $statusByLabel = array_change_key_case(array_flip(hr_emp_statuses()));

    $created = 0;
    $errors  = [];
    $seenCodes = [];
    $seenEmails = [];
    foreach ($rows as [$line, $cells]) {
        if (!array_filter($cells, static fn($c) => trim((string) $c) !== '')) {
            continue;
        }
        $r = [];
        foreach ($cols as $i => $field) {
            $r[$field] = trim((string) ($cells[$i] ?? ''));
        }
        try {
            $p = $r;
            foreach (['department' => [$depts, 'department_id', 'Department'], 'designation' => [$desig, 'designation_id', 'Designation'],
                      'employment_type' => [$types, 'employment_type_id', 'Employment type'], 'location' => [$locs, 'location_id', 'Work location'],
                      'category' => [$cats, 'category_id', 'Category']] as $k => [$m, $field, $label]) {
                if (($r[$k] ?? '') !== '') {
                    $id = $lookup($m, $r[$k]);
                    if (!$id) {
                        throw new InvalidArgumentException($label . ' “' . $r[$k] . '” does not exist. Add it under Organisation Structure first.');
                    }
                    $p[$field] = $id;
                }
            }
            if (($r['manager'] ?? '') !== '') {
                $mgr = fabo_one('SELECT id FROM hr_employees WHERE org_id = ? AND (emp_code = ? OR full_name = ?) LIMIT 1',
                                'iss', [hr_org(), strtoupper($r['manager']), $r['manager']]);
                if (!$mgr) {
                    throw new InvalidArgumentException('Reporting manager “' . $r['manager'] . '” was not found (use their Employee ID).');
                }
                $p['manager_id'] = (int) $mgr['id'];
            }
            $st = mb_strtolower($r['status'] ?? '');
            $p['status'] = $st === '' ? 'active' : ($statusByLabel[$st] ?? (isset(hr_emp_statuses()[$st]) ? $st : ''));
            if ($p['status'] === '') {
                throw new InvalidArgumentException('Status “' . $r['status'] . '” is not one of: ' . implode(', ', hr_emp_statuses()) . '.');
            }
            $code = strtoupper($r['emp_code'] ?? '');
            if ($code !== '') {
                if (isset($seenCodes[$code])) {
                    throw new InvalidArgumentException('Employee ID ' . $code . ' appears twice in the file (line ' . $seenCodes[$code] . ').');
                }
                $seenCodes[$code] = $line;
            }
            $em = mb_strtolower($r['work_email'] ?? '');
            if ($em !== '') {
                if (isset($seenEmails[$em])) {
                    throw new InvalidArgumentException('Work email ' . $em . ' appears twice in the file (line ' . $seenEmails[$em] . ').');
                }
                $seenEmails[$em] = $line;
            }
            if ($dryRun) {
                // Validate everything the save would, without writing.
                hr_str($p['full_name'] ?? '', 160, true, 'Full name');
                hr_date($p['date_of_joining'] ?? '', true, 'Date of joining');
                hr_date($p['date_of_birth'] ?? '', false, 'Date of birth');
                if ($em !== '' && !filter_var($em, FILTER_VALIDATE_EMAIL)) {
                    throw new InvalidArgumentException('Work email is not a valid email address.');
                }
                if ($em !== '' && fabo_one('SELECT id FROM hr_employees WHERE org_id = ? AND work_email = ?', 'is', [hr_org(), $em])) {
                    throw new InvalidArgumentException('Work email ' . $em . ' already belongs to an employee.');
                }
                if ($code !== '' && fabo_one('SELECT id FROM hr_employees WHERE org_id = ? AND emp_code = ?', 'is', [hr_org(), $code])) {
                    throw new InvalidArgumentException('Employee ID ' . $code . ' is already in use.');
                }
            } else {
                hr_employee_save(null, $p, false);
            }
            $created++;
        } catch (InvalidArgumentException $e) {
            $errors[$line] = $e->getMessage();
        } catch (mysqli_sql_exception $e) {
            $errors[$line] = fabo_db_words($e);
        }
    }
    if (!$dryRun) {
        hr_audit('import', 'employee', null, 'Imported ' . $created . ' employees from ' . $name
               . ($errors ? ' (' . count($errors) . ' rows refused)' : ''));
    }
    return ['created' => $created, 'errors' => $errors, 'rows' => count($rows)];
}

/** [[line, cells], ...] from a CSV or XLSX upload, using the portal's own readers. */
function hr_read_table_file(string $path, string $name): array
{
    require_once __DIR__ . '/../crm.php';
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext === 'xlsx') {
        $res = fabo_crm_read_xlsx($path, 0);
        return $res[1] ?? $res;
    }
    if ($ext === 'xls') {
        require_once __DIR__ . '/xls.php';
        return hr_xls_read($path, 0)[1];
    }
    if ($ext === 'csv' || $ext === 'txt') {
        return fabo_crm_read_csv($path);
    }
    throw new InvalidArgumentException('Upload a .xls, .xlsx or .csv file.');
}

/* ------------------------------------------------ the employee's dashboard */

/**
 * Gives an employee their own HR dashboard: a portal sign-in (made for them,
 * or an unlinked one already on their address), linked to their record, and
 * a role — the Employee self-service role unless another is chosen. Their
 * sign-in details are emailed to them.
 *
 * Returns ['person_id', 'password' (null when an existing account was
 * linked), 'sent', 'detail', 'login_id', 'email'].
 */
function hr_employee_grant_dashboard(int $empId, string $email, int $roleId = 0, bool $send = true): array
{
    $emp = hr_employee($empId);
    if (!$emp) {
        throw new InvalidArgumentException('That employee no longer exists.');
    }
    if ($emp['person_id']) {
        if (hr_employee_roles($emp)) {
            throw new InvalidArgumentException($emp['full_name'] . ' already has dashboard access.');
        }
        // Linked already, but with no HR role: give the role, nothing else.
        $role = $roleId > 0 ? $roleId : hr_role_from_template('self');
        fabo_exec('INSERT IGNORE INTO hr_role_members (role_id, person_id, granted_by) VALUES (?,?,?)', 'iis', [$role, (int) $emp['person_id'], hr_actor()]);
        hr_audit('dashboard_access', 'employee', (int) $emp['id'], 'HR dashboard given to ' . $emp['full_name'] . ' (existing account)');
        $roleName = (string) (fabo_one('SELECT name FROM hr_roles WHERE id = ?', 'i', [$role])['name'] ?? '');
        [$sent, $detail] = $send ? hr_send_dashboard_welcome((int) $emp['person_id'], null, $emp, $roleName) : [false, 'not emailed (you chose not to)'];
        $acct = fabo_one('SELECT email FROM clients WHERE id = ?', 'i', [(int) $emp['person_id']]);
        return ['person_id' => (int) $emp['person_id'], 'password' => null, 'role' => $role, 'sent' => $sent, 'detail' => $detail,
                'login_id' => fabo_login_id((int) $emp['person_id']), 'email' => (string) ($acct['email'] ?? '')];
    }
    // Optional: without one they sign in with their User ID, and every HR email goes to the owner anyway.
    $email = mb_strtolower(hr_str($email, 190));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('The sign-in email is not a valid email address.');
    }
    if ($roleId > 0 && !fabo_one('SELECT id FROM hr_roles WHERE id = ? AND org_id = ? AND active = 1', 'ii', [$roleId, hr_org()])) {
        throw new InvalidArgumentException('Choose one of the HR roles.');
    }
    $org = hr_org();
    $out = hr_tx(function () use ($emp, $email, $roleId, $org) {
        // An account already on this address in this organisation, not yet linked, is used rather than duplicated.
        $existing = $email === '' ? null : ($org === 0
            ? fabo_one("SELECT * FROM clients WHERE email = ? AND kind = 'team' AND (parent_id IS NULL OR parent_id = 0) ORDER BY id LIMIT 1", 's', [$email])
            : fabo_one('SELECT * FROM clients WHERE email = ? AND (id = ? OR parent_id = ?) ORDER BY id LIMIT 1', 'sii', [$email, $org, $org]));
        $plain = null;
        if ($existing) {
            if (fabo_one('SELECT id FROM hr_employees WHERE person_id = ?', 'i', [(int) $existing['id']])) {
                throw new InvalidArgumentException('The account on ' . $email . ' is already linked to another employee. Use a different email.');
            }
            $pid = (int) $existing['id'];
        } else {
            $orgName = $org ? (string) (fabo_one('SELECT organisation FROM clients WHERE id = ?', 'i', [$org])['organisation'] ?? '') : '';
            $pid = fabo_exec("INSERT INTO clients (name, kind, email, organisation, contact_note, token, expires_at, parent_id)
                              VALUES (?, 'team', ?, ?, ?, ?, NULL, ?)", 'sssssi',
                             [$emp['full_name'], $email !== '' ? $email : null, $orgName !== '' ? $orgName : null, 'HR dashboard for employee ' . $emp['emp_code'],
                              fabo_new_token(), $org ?: null]);
            $plain = fabo_set_password($pid);     // a one-off password; they choose their own at first sign-in
        }
        fabo_exec('UPDATE hr_employees SET person_id = ? WHERE id = ? AND org_id = ?', 'iii', [$pid, (int) $emp['id'], $org]);
        $role = $roleId > 0 ? $roleId : hr_role_from_template('self');
        fabo_exec('INSERT IGNORE INTO hr_role_members (role_id, person_id, granted_by) VALUES (?,?,?)', 'iis', [$role, $pid, hr_actor()]);
        fabo_exec('INSERT INTO hr_employee_history (employee_id, field, old_value, new_value, effective_on, changed_by) VALUES (?,?,?,?,?,?)',
                  'isssss', [(int) $emp['id'], 'Dashboard access', null, 'Given (' . ($email !== '' ? $email : 'User ID sign-in') . ')', date('Y-m-d'), hr_actor()]);
        hr_audit('dashboard_access', 'employee', (int) $emp['id'], 'HR dashboard given to ' . $emp['full_name'] . ($email !== '' ? ' (' . $email . ')' : '')
               . ($existing ? ', linked to their existing account' : ', new sign-in created'));
        return ['person_id' => $pid, 'password' => $plain, 'role' => $role];
    });
    $roleName = (string) (fabo_one('SELECT name FROM hr_roles WHERE id = ?', 'i', [$out['role']])['name'] ?? '');
    [$sent, $detail] = $send ? hr_send_dashboard_welcome($out['person_id'], $out['password'], $emp, $roleName)
                             : [false, 'not emailed (you chose not to)'];
    return $out + ['sent' => $sent, 'detail' => $detail, 'login_id' => fabo_login_id($out['person_id']), 'email' => $email];
}

/**
 * What to tell the administrator after access was given or re-sent. When the
 * email did not go, the one-off password is shown here — once — so it can be
 * passed on by hand; it is never stored in readable form.
 */
function hr_dashboard_result_words(array $g): string
{
    if ($g['password'] === null) {
        return 'Their HR dashboard is ready. They sign in with their existing account (User ID ' . $g['login_id'] . ')'
             . ($g['sent'] ? '; the owner’s mailbox was told.' : '.');
    }
    if ($g['sent']) {
        return 'Their HR dashboard is ready. The sign-in details (User ID ' . $g['login_id'] . ') were emailed to the owner’s mailbox to pass on.';
    }
    return 'Their HR dashboard is ready, but the sign-in details were not emailed (' . $g['detail'] . '). '
         . 'Give them these yourself — this is the only time the password is shown: User ID ' . $g['login_id']
         . ', temporary password ' . $g['password'] . '. They choose their own password when they first sign in.';
}

/** Issues a new one-off password for an employee's dashboard and emails it. */
function hr_employee_resend_access(int $empId): array
{
    $emp = hr_employee($empId);
    if (!$emp || !$emp['person_id']) {
        throw new InvalidArgumentException('This employee has no dashboard access yet.');
    }
    $plain = fabo_set_password((int) $emp['person_id']);
    $role = fabo_one('SELECT r.name FROM hr_role_members m JOIN hr_roles r ON r.id = m.role_id AND r.org_id = ?
                      WHERE m.person_id = ? ORDER BY r.id LIMIT 1', 'ii', [hr_org(), (int) $emp['person_id']]);
    [$sent, $detail] = hr_send_dashboard_welcome((int) $emp['person_id'], $plain, $emp, (string) ($role['name'] ?? ''));
    hr_audit('dashboard_resend', 'employee', $empId, 'New sign-in details issued to ' . $emp['full_name']);
    $p = fabo_one('SELECT email FROM clients WHERE id = ?', 'i', [(int) $emp['person_id']]);
    return ['password' => $plain, 'sent' => $sent, 'detail' => $detail, 'login_id' => fabo_login_id((int) $emp['person_id']),
            'email' => (string) ($p['email'] ?? '')];
}

/** Takes the HR roles away from an employee's account. The account and every HR record stay. */
function hr_employee_revoke_dashboard(int $empId): void
{
    $emp = hr_employee($empId);
    if (!$emp || !$emp['person_id']) {
        throw new InvalidArgumentException('This employee has no dashboard access.');
    }
    fabo_exec('DELETE m FROM hr_role_members m JOIN hr_roles r ON r.id = m.role_id WHERE m.person_id = ? AND r.org_id = ?',
              'ii', [(int) $emp['person_id'], hr_org()]);
    fabo_exec('INSERT INTO hr_employee_history (employee_id, field, old_value, new_value, effective_on, changed_by) VALUES (?,?,?,?,?,?)',
              'isssss', [$empId, 'Dashboard access', 'Given', 'Removed', date('Y-m-d'), hr_actor()]);
    hr_audit('dashboard_revoke', 'employee', $empId, 'HR dashboard access removed from ' . $emp['full_name']);
}

/** The HR roles an employee's account holds here: [['id', 'name', 'scope'], ...]. */
function hr_employee_roles(array $emp): array
{
    if (!$emp['person_id']) {
        return [];
    }
    return fabo_all('SELECT r.id, r.name, r.scope FROM hr_role_members m JOIN hr_roles r ON r.id = m.role_id AND r.org_id = ?
                     WHERE m.person_id = ? ORDER BY r.name', 'ii', [hr_org(), (int) $emp['person_id']]);
}

/**
 * The sign-in details for an employee's new dashboard. They go to the
 * owner’s mailbox (never the employee's), headed with the employee's name,
 * for the owner to pass on. The employee also gets an in-app welcome.
 * Returns [sent, detail].
 */
function hr_send_dashboard_welcome(int $personId, ?string $plain, array $emp, string $roleName): array
{
    require_once __DIR__ . '/../outbox.php';
    require_once __DIR__ . '/../mailtpl.php';
    $org     = (int) ($emp['org_id'] ?? hr_org());
    $to      = hr_owner_email($org);
    $owner   = hr_owner_name($org);
    $loginId = fabo_login_id($personId);
    $url     = rtrim(fabo_portal_url(), '/') . '/login.php?next=' . rawurlencode('hr.php');
    $brand   = fabo_app_name();
    $subject = hr_owner_subject($org, hr_for_employee_subject($emp, 'HR dashboard sign-in details'));
    $who     = $emp['full_name'] . (trim((string) ($emp['emp_code'] ?? '')) !== '' ? ' (' . $emp['emp_code'] . ')' : '');
    if (hr_owner_is_fallback($org)) {
        $who .= ', ' . hr_org_label($org);   // the main owner's inbox serves every company
    }
    $can = 'apply for leave and follow each approval, see how much leave they have left this month and this year, '
         . 'check their attendance and holidays, and download their payslips';
    fabo_notify($personId, 'Welcome — your HR dashboard is ready',
                'From HR Management you can apply for leave and follow each approval, see how much leave you have left, '
                . 'check your attendance and holidays, and download your payslips.', 'hr.php');

    $text = 'Hello ' . $owner . ",\n\nThe HR dashboard for " . $who . ' is ready'
          . ($roleName !== '' ? ' (' . $roleName . ')' : '') . '. They can ' . $can . ".\n\n"
          . "Pass these sign-in details on to them:\n\n"
          . 'Sign in: ' . $url . "\n"
          . 'User ID: ' . $loginId . "\n"
          . ($plain !== null ? 'Temporary password: ' . $plain . "\nThey will be asked to choose their own password the first time they sign in.\n"
                             : "They use the password they already have for this portal.\n");
    $rows = ['Employee' => e($who), 'User ID' => '<strong>' . e($loginId) . '</strong>'];
    if ($plain !== null) {
        $rows['Temporary password'] = '<strong style="font-family:Consolas,monospace">' . e($plain) . '</strong>';
    }
    $html = fabo_mail_wrap($brand, 'HR dashboard for ' . $emp['full_name'],
        fabo_mail_p('Hello ' . e($owner) . ',')
        . fabo_mail_p('The HR dashboard for <strong>' . e($who) . '</strong> is ready' . ($roleName !== '' ? ' (' . e($roleName) . ')' : '')
                      . '. They can ' . e($can) . '. Pass these sign-in details on to them:')
        . fabo_mail_rows($rows)
        . ($plain !== null ? fabo_mail_note('They will be asked to choose their own password the first time they sign in.')
                           : fabo_mail_note('They use the password they already have for this portal.'))
        . fabo_mail_own_link($url, 'Sign-in page'), 'HR dashboard for ' . $emp['full_name']);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return [false, 'there is no owner email set for this company'];
    }
    if (!function_exists('fabo_mail_configured') || !fabo_mail_configured()) {
        return [false, 'no mail server is configured for the portal'];
    }
    [$sent, $detail] = fabo_post_or_queue($to, $subject, $text, $html, $personId, 'sign-in details');
    return [$sent, $detail];
}

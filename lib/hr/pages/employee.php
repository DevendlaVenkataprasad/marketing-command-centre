<?php
/**
 * One employee: add, edit, and the profile with their leave, attendance,
 * payroll, documents and history. Every read and write checks that the
 * viewer may act on THIS employee (hr_emp_allowed), so changing ?id= in the
 * address gets a 404, not someone else's record.
 */

$id    = (int) ($_GET['id'] ?? 0);
$isNew = !$id && !empty($_GET['new']);
$flash = null;

if ($isNew) {
    hr_need('employees', 'create');
    if (hr_scope('employees', 'create') !== 'org') {
        fabo_deny('Adding employees needs organisation-wide rights.', 403);
    }
    $emp = null;
} else {
    hr_need_emp($id, 'employees', 'view');
    $emp = hr_employee_full($id);
}
$selfScope = !$isNew && hr_scope('employees', 'edit') === 'self';
$mayEdit   = $isNew || (hr_emp_allowed($id, 'employees', 'edit') && !$selfScope);
$maySelfEdit = $selfScope && hr_emp_allowed($id, 'employees', 'edit');
$confView  = !$isNew ? hr_emp_allowed($id, 'confidential', 'view') : hr_can('confidential', 'view');
$confEdit  = $isNew ? hr_can('confidential', 'edit') : hr_emp_allowed($id, 'confidential', 'edit');
$editing   = $isNew || (!empty($_GET['edit']) && ($mayEdit || $maySelfEdit));

/* --------------------------------------------------------------- actions */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    $here = hr_url('employee', ['id' => $id]);
    if ($a === 'save') {
        if (!$mayEdit) {
            fabo_deny('You cannot edit this employee.', 403);
        }
        try {
            $newId = hr_employee_save($isNew ? null : $id, $_POST, $confEdit, $_FILES['photo'] ?? null);
            $msg = $isNew ? 'Employee added.' : 'Changes saved.';
            $tone = 'ok';
            if ($isNew && !empty($_POST['start_onboarding']) && hr_checklist_default('onboarding')) {
                try {
                    $msg .= ' Onboarding started with ' . hr_lifecycle_start($newId, 'onboarding') . ' task(s).';
                } catch (InvalidArgumentException $e) {
                    $msg .= ' Onboarding was not started: ' . $e->getMessage();
                }
            }
            // Their own HR dashboard, when asked for on the form (administrator only: it grants a role).
            if ($isNew && hr_is_admin() && !empty($_POST['give_dashboard']) && empty($_POST['person_id'])) {
                try {
                    $g = hr_employee_grant_dashboard($newId, '',
                                                     (int) ($_POST['dash_role'] ?? 0),
                                                     !empty($_POST['dash_send']));
                    $msg .= ' ' . hr_dashboard_result_words($g);
                    $tone = $g['sent'] || $g['password'] === null ? 'ok' : 'err';
                } catch (InvalidArgumentException $e) {
                    $msg .= ' Their dashboard was not set up: ' . $e->getMessage() . ' Use “Give dashboard access” below.';
                    $tone = 'err';
                }
            }
            hr_redirect(hr_url('employee', ['id' => $newId]), [$tone, $msg]);
        } catch (InvalidArgumentException $e) {
            $flash = ['err', $e->getMessage()];
        } catch (Throwable $e) {
            $flash = ['err', fabo_user_error($e)];
        }
    } elseif (in_array($a, ['dash_give', 'dash_resend', 'dash_revoke'], true)) {
        if (!hr_is_admin()) {
            fabo_deny('Only the administrator gives or removes dashboard access.', 403);
        }
        try {
            if ($a === 'dash_give') {
                $g = hr_employee_grant_dashboard($id, (string) ($_POST['dash_email'] ?? ''), (int) ($_POST['dash_role'] ?? 0), !empty($_POST['dash_send']));
                hr_redirect($here, [$g['sent'] || $g['password'] === null ? 'ok' : 'err', hr_dashboard_result_words($g)]);
            } elseif ($a === 'dash_resend') {
                $g = hr_employee_resend_access($id);
                hr_redirect($here, [$g['sent'] ? 'ok' : 'err', hr_dashboard_result_words($g)]);
            } else {
                hr_employee_revoke_dashboard($id);
                hr_redirect($here, ['ok', 'Dashboard access removed. Their sign-in account and every HR record are kept.']);
            }
        } catch (InvalidArgumentException $e) {
            $flash = ['err', $e->getMessage()];
        } catch (Throwable $e) {
            $flash = ['err', fabo_user_error($e)];
        }
    } elseif ($a === 'self_save') {
        if (!$maySelfEdit && !$mayEdit) {
            fabo_deny('You cannot edit this record.', 403);
        }
        $flash = hr_action(static function () use ($id) { hr_employee_self_update($id, $_POST); return 'Your details were updated.'; }, $here);
    } elseif ($a === 'deactivate') {
        if (!hr_emp_allowed($id, 'employees', 'delete') && !hr_is_admin()) {
            fabo_deny('You cannot deactivate employees.', 403);
        }
        $flash = hr_action(static function () use ($id) {
            hr_employee_deactivate($id, (string) ($_POST['status'] ?? ''), (string) ($_POST['date_of_leaving'] ?? ''), trim((string) ($_POST['reason'] ?? '')));
            return 'Employee deactivated. Their history, leave and payroll records are kept.';
        }, $here);
    } elseif ($a === 'reactivate') {
        if (!hr_emp_allowed($id, 'employees', 'delete') && !hr_is_admin()) {
            fabo_deny('You cannot reactivate employees.', 403);
        }
        $flash = hr_action(static function () use ($id) { hr_employee_reactivate($id); return 'Employee reactivated.'; }, $here);
    } elseif ($a === 'doc_add') {
        if (!$confEdit) {
            fabo_deny('You cannot add documents.', 403);
        }
        $flash = hr_action(static function () use ($id) {
            hr_employee_doc_add($id, (string) ($_POST['title'] ?? ''), $_FILES['doc'] ?? []);
            return 'Document added.';
        }, $here . '&tab=documents');
    } elseif ($a === 'doc_delete') {
        if (!$confEdit) {
            fabo_deny('You cannot remove documents.', 403);
        }
        $flash = hr_action(static function () use ($id) { hr_employee_doc_delete($id, (int) ($_POST['doc_id'] ?? 0)); return 'Document removed.'; }, $here . '&tab=documents');
    } elseif ($a === 'leave_adjust') {
        if (!hr_emp_allowed($id, 'leave', 'configure') && !hr_is_admin()) {
            fabo_deny('You cannot adjust leave balances.', 403);
        }
        $flash = hr_action(static function () use ($id) {
            hr_leave_adjust(hr_employee($id), (int) ($_POST['leave_type_id'] ?? 0), (int) ($_POST['year'] ?? 0),
                            (string) ($_POST['kind'] ?? ''), (string) ($_POST['days'] ?? ''), (string) ($_POST['note'] ?? ''));
            return 'Balance updated.';
        }, $here . '&tab=leave&year=' . (int) ($_POST['year'] ?? 0));
    }
}

/* ---------------------------------------------------------------- form */

if ($editing) {
    $v = $emp ?? [];
    if ($flash && $flash[0] === 'err') {
        $v = array_merge($v, array_map(static fn($x) => is_string($x) ? $x : $x, $_POST));
    }
    $title = $isNew ? 'Add employee' : 'Edit ' . $emp['full_name'];
    hr_layout_start($title, $isNew ? 'Fields marked <span class="req">*</span> are required. The Employee ID is generated if left blank.' : 'Employee ID ' . e($emp['emp_code']),
        ['Employees' => hr_url('employees')] + ($isNew ? [] : [$emp['full_name'] => hr_url('employee', ['id' => $id])]) + [$isNew ? 'Add' : 'Edit' => '']);
    echo hr_flash_html($flash);

    if (!$mayEdit && $maySelfEdit) {
        echo '<div class="card">' . hr_form_open('', 'class="hr-form"') . '<input type="hidden" name="action" value="self_save">'
           . '<div class="formsec"><h3>Your contact details</h3><div class="formgrid">'
           . hr_field('Phone number', hr_input('phone', $v['phone'] ?? '', 'tel', ['maxlength' => 40, 'placeholder' => 'e.g. +91 98765 43210']))
           . hr_field('Address', '<textarea name="address" rows="3" maxlength="1000">' . e($v['address'] ?? '') . '</textarea>', false, '', 'full')
           . '</div></div><div class="formsec"><h3>Emergency contact</h3><div class="formgrid">'
           . hr_field('Name', hr_input('emergency_name', $v['emergency_name'] ?? '', 'text', ['maxlength' => 160]))
           . hr_field('Relationship', hr_input('emergency_relation', $v['emergency_relation'] ?? '', 'text', ['maxlength' => 60]))
           . hr_field('Phone', hr_input('emergency_phone', $v['emergency_phone'] ?? '', 'tel', ['maxlength' => 40]))
           . '</div></div><div class="hr-formfoot"><button class="btn btn-primary">Save</button><a class="btn" href="' . e(hr_url('employee', ['id' => $id])) . '">Cancel</a></div></form></div>';
        hr_layout_end();
        return;
    }

    $mgrs = hr_employee_choices(true, 'employees', 'view');
    unset($mgrs[$id]);
    $curShift = $id ? hr_emp_shift_now($id) : null;
    echo '<div class="card">' . hr_form_open('', 'class="hr-form"', true) . '<input type="hidden" name="action" value="save">'
       . '<div class="formsec"><h3>Basic details</h3><div class="formgrid">'
       . hr_field('Full name', hr_input('full_name', $v['full_name'] ?? '', 'text', ['required' => true, 'maxlength' => 160, 'autocomplete' => 'off']), true)
       . hr_field('Employee ID', hr_input('emp_code', $v['emp_code'] ?? '', 'text', ['maxlength' => 30, 'placeholder' => $isNew ? 'Generated if blank' : '']), false,
                  'Must be unique. Format for generated IDs is set under Policies & Rules → Employee IDs.')
       . hr_field('Biometric / machine ID', hr_input('biometric_id', $v['biometric_id'] ?? '', 'text', ['maxlength' => 40, 'placeholder' => 'e.g. R0005']), false,
                  'The Person ID on the attendance machine. Used to match the machine\'s report when it is imported.')
       . hr_field('Work email', hr_input('work_email', $v['work_email'] ?? '', 'email', ['maxlength' => 190, 'placeholder' => 'name@company.com']), false, 'Must be unique.')
       . hr_field('Phone number', hr_input('phone', $v['phone'] ?? '', 'tel', ['maxlength' => 40]))
       . hr_field('Date of birth', hr_input('date_of_birth', $v['date_of_birth'] ?? '', 'date', ['max' => date('Y-m-d')]))
       . hr_field('Gender', hr_select('gender', ['Female' => 'Female', 'Male' => 'Male', 'Other' => 'Other', 'Prefer not to say' => 'Prefer not to say'], $v['gender'] ?? '', 'Not stated'))
       . hr_field('Profile photo', '<input type="file" name="photo" accept="image/jpeg,image/png,image/gif,image/webp">', false, 'JPEG, PNG, GIF or WebP.')
       . '</div></div>'
       . '<div class="formsec"><h3>Job</h3><div class="formgrid">'
       . hr_field('Department', hr_select('department_id', hr_departments_map(), $v['department_id'] ?? '', 'Choose…'))
       . hr_field('Designation', hr_select('designation_id', hr_designations_map(), $v['designation_id'] ?? '', 'Choose…'))
       . hr_field('Reporting manager', hr_select('manager_id', $mgrs, $v['manager_id'] ?? '', 'None'))
       . hr_field('Date of joining', hr_input('date_of_joining', $v['date_of_joining'] ?? '', 'date', ['required' => true]), true)
       . hr_field('Probation ends', hr_input('probation_end', $v['probation_end'] ?? '', 'date'))
       . hr_field('Employment type', hr_select('employment_type_id', hr_master_map('employment_type'), $v['employment_type_id'] ?? '', 'Choose…'))
       . hr_field('Employment status', hr_select('status', hr_emp_statuses(), $v['status'] ?? 'active'), true)
       . hr_field('Date of leaving', hr_input('date_of_leaving', $v['date_of_leaving'] ?? '', 'date'))
       . hr_field('Work location', hr_select('location_id', hr_master_map('location'), $v['location_id'] ?? '', 'Choose…'))
       . hr_field('Employee category', hr_select('category_id', hr_master_map('category'), $v['category_id'] ?? '', 'Choose…'))
       . '</div></div>'
       . '<div class="formsec"><h3>Schedule, leave and holidays</h3><div class="formgrid">'
       . hr_field('Assigned shift', hr_select('shift_id', hr_shifts_map(), $curShift['shift_id'] ?? '', 'Default shift'), false,
                  $isNew ? 'Starts on the date of joining.' : 'A change starts on the date below; the history is kept.')
       . ($isNew ? '' : hr_field('Shift change from', hr_input('shift_from', date('Y-m-d'), 'date')))
       . hr_field('Leave policy', hr_select('leave_policy_id', hr_leave_policies_map(), $v['leave_policy_id'] ?? '', 'Automatic (by department / default)'))
       . hr_field('Holiday calendar', hr_select('holiday_calendar_id', hr_calendars_map(), $v['holiday_calendar_id'] ?? '', 'Automatic (by location / default)'))
       . '</div><div class="field">' . hr_checkbox('own_offs', !empty($v['weekly_offs']), 'This employee has their own weekly offs (instead of their shift\'s)') . '</div>'
       . hr_offs_picker($v['weekly_offs'] ?? '') . '</div>'
       . '<div class="formsec"><h3>Contact and emergency</h3><div class="formgrid">'
       . hr_field('Emergency contact name', hr_input('emergency_name', $v['emergency_name'] ?? '', 'text', ['maxlength' => 160]))
       . hr_field('Relationship', hr_input('emergency_relation', $v['emergency_relation'] ?? '', 'text', ['maxlength' => 60]))
       . hr_field('Emergency phone', hr_input('emergency_phone', $v['emergency_phone'] ?? '', 'tel', ['maxlength' => 40]))
       . hr_field('Address', '<textarea name="address" rows="3" maxlength="1000">' . e($v['address'] ?? '') . '</textarea>', false, '', 'full')
       . '</div></div>';
    if ($confEdit) {
        echo '<div class="formsec"><h3>Bank and payroll (confidential)</h3><div class="formgrid">'
           . hr_field('Payment mode', hr_select('payment_mode', ['bank' => 'Bank transfer', 'cash' => 'Cash', 'cheque' => 'Cheque'], $v['payment_mode'] ?? 'bank'))
           . hr_field('Bank name', hr_input('bank_name', $v['bank_name'] ?? '', 'text', ['maxlength' => 120]))
           . hr_field('Account number', hr_input('bank_account', $v['bank_account'] ?? '', 'text', ['maxlength' => 34, 'autocomplete' => 'off']))
           . hr_field('IFSC', hr_input('bank_ifsc', $v['bank_ifsc'] ?? '', 'text', ['maxlength' => 11, 'placeholder' => 'ABCD0123456']))
           . hr_field('PAN', hr_input('pan', $v['pan'] ?? '', 'text', ['maxlength' => 10, 'placeholder' => 'ABCDE1234F']))
           . hr_field('UAN', hr_input('uan', $v['uan'] ?? '', 'text', ['maxlength' => 20]))
           . hr_field('PF number', hr_input('pf_number', $v['pf_number'] ?? '', 'text', ['maxlength' => 40]))
           . hr_field('ESI number', hr_input('esi_number', $v['esi_number'] ?? '', 'text', ['maxlength' => 40]))
           . '</div><p class="hr-hint">The salary structure is set on the employee\'s Payroll tab after saving.</p></div>';
    }
    $people = hr_person_choices($v['person_id'] ?? null);
    if ($isNew && ($ob = hr_checklist_default('onboarding'))) {
        echo '<div class="formsec"><h3>Onboarding</h3><div class="field">'
           . hr_checkbox('start_onboarding', $flash ? !empty($_POST['start_onboarding']) : hr_setting('onboard_auto') === '1',
                         'Start the “' . $ob['name'] . '” checklist (' . count(hr_checklist_items((int) $ob['id'])) . ' tasks, dated from the joining date)')
           . '</div></div>';
    }
    if ($isNew && hr_is_admin()) {
        $give = $flash ? !empty($_POST['give_dashboard']) : true;
        echo '<div class="formsec"><h3>Employee dashboard</h3>'
           . '<div class="field">' . hr_checkbox('give_dashboard', $give, 'Give this employee their own HR dashboard') . '</div>'
           . '<p class="hr-hint">They can apply for leave and follow each approval, see the leave they have left this month and this year, '
           . 'check attendance and holidays, and download payslips. They sign in with a User ID; the sign-in details are emailed to the owner’s mailbox to pass on.</p><div class="formgrid">'
           . hr_field('What they can do', hr_select('dash_role', hr_dashboard_role_choices(), $_POST['dash_role'] ?? '', 'Employee self-service (their own record)'), false,
                      'Roles are managed under Access Control.')
           . '</div><div class="field">' . hr_checkbox('dash_send', $flash ? !empty($_POST['dash_send']) : true, 'Email their sign-in details to the owner now') . '</div></div>';
    }
    echo '<div class="formsec"><h3>' . ($isNew && hr_is_admin() ? 'Or link an existing sign-in account' : 'Sign-in account (for self-service)') . '</h3><div class="formgrid">'
       . hr_field('Linked account', hr_select('person_id', $people, $v['person_id'] ?? '', 'Not linked'), false,
                  'Only if they already sign in to this portal. Linking gives no HR access by itself — the dashboard section or Access Control does.')
       . hr_field('Notes', '<textarea name="notes" rows="2" maxlength="2000">' . e($v['notes'] ?? '') . '</textarea>', false, '', 'full')
       . '</div></div>'
       . '<div class="hr-formfoot"><button class="btn btn-primary">' . ($isNew ? 'Add employee' : 'Save changes') . '</button>'
       . '<a class="btn" href="' . e($isNew ? hr_url('employees') : hr_url('employee', ['id' => $id])) . '">Cancel</a></div></form></div>';
    hr_layout_end();
    return;
}

/* ------------------------------------------------------------- profile */

$tabs = ['overview' => 'Overview'];
if (hr_emp_allowed($id, 'leave', 'view')) { $tabs['leave'] = 'Leave'; }
if (hr_emp_allowed($id, 'attendance', 'view')) { $tabs['attendance'] = 'Attendance'; }
if (hr_emp_allowed($id, 'payroll', 'view')) { $tabs['payroll'] = 'Payroll'; }
if ($confView) { $tabs['documents'] = 'Documents'; }
if (hr_emp_allowed($id, 'lifecycle', 'view')) { $tabs['journey'] = 'Onboarding & exit'; }
if (hr_emp_allowed($id, 'assets', 'view')) { $tabs['assets'] = 'Assets'; }
if (hr_emp_allowed($id, 'claims', 'view')) { $tabs['claims'] = 'Claims'; }
if (hr_emp_allowed($id, 'training', 'view') || hr_emp_allowed($id, 'performance', 'view')) { $tabs['growth'] = 'Growth'; }
$tabs['history'] = 'History';
$tab = hr_tab($tabs, 'overview');

$acts = '';
if ($mayEdit) {
    $acts .= '<a class="btn btn-sm btn-primary" href="' . e(hr_url('employee', ['id' => $id, 'edit' => 1])) . '">Edit</a>';
} elseif ($maySelfEdit) {
    $acts .= '<a class="btn btn-sm btn-primary" href="' . e(hr_url('employee', ['id' => $id, 'edit' => 1])) . '">Update my details</a>';
}
if (hr_emp_allowed($id, 'leave', 'create')) {
    $acts .= '<a class="btn btn-sm" href="' . e(hr_url('leave', ['tab' => 'apply', 'employee' => $id])) . '">Record leave</a>';
}
hr_layout_start($emp['full_name'], e($emp['emp_code']) . ' · ' . e($emp['designation'] ?? 'No designation') . ' · ' . e($emp['department'] ?? 'No department')
              . ' · ' . hr_badge(hr_status_label($emp['status'])), ['Employees' => hr_url('employees'), $emp['full_name'] => ''], $acts);
echo hr_flash_html($flash);
hr_tabs('employee', $tabs, $tab, ['id' => $id]);

if ($tab === 'overview') {
    $shift = hr_emp_shift($id, date('Y-m-d'));
    $pol = hr_emp_policy($emp, date('Y-m-d'));
    $calId = hr_emp_calendar_id($emp);
    echo '<div class="hr-two"><div class="card"><div class="hr-me-top">'
       . ($emp['photo_file'] ? '<img src="' . e(hr_url('file', ['kind' => 'photo', 'id' => $id, 'v' => substr(md5($emp['photo_file']), 0, 6)])) . '" alt="">'
                             : '<span class="ph" aria-hidden="true">' . e(mb_strtoupper(mb_substr($emp['full_name'], 0, 1))) . '</span>')
       . '<div class="who"><h2>' . e($emp['full_name']) . '</h2><div class="muted">' . e($emp['work_email'] ?? '') . ($emp['phone'] ? ' · ' . e($emp['phone']) : '') . '</div></div></div>'
       . '<dl class="hr-kv" style="margin-top:14px">'
       . '<dt>Employee ID</dt><dd>' . e($emp['emp_code']) . '</dd>'
       . '<dt>Biometric ID</dt><dd>' . e($emp['biometric_id'] ?: '—') . '</dd>'
       . '<dt>Department</dt><dd>' . e($emp['department'] ?? '—') . '</dd>'
       . '<dt>Designation</dt><dd>' . e($emp['designation'] ?? '—') . '</dd>'
       . '<dt>Reporting manager</dt><dd>' . ($emp['manager_id'] ? hr_person_cell(['id' => $emp['manager_id'], 'full_name' => $emp['manager'], 'emp_code' => '']) : '—') . '</dd>'
       . '<dt>Date of joining</dt><dd>' . e(hr_fmt_date($emp['date_of_joining'])) . '</dd>'
       . ($emp['probation_end'] ? '<dt>Probation ends</dt><dd>' . e(hr_fmt_date($emp['probation_end'])) . '</dd>' : '')
       . ($emp['date_of_leaving'] ? '<dt>Date of leaving</dt><dd>' . e(hr_fmt_date($emp['date_of_leaving'])) . '</dd>' : '')
       . '<dt>Employment type</dt><dd>' . e($emp['employment_type'] ?? '—') . '</dd>'
       . '<dt>Status</dt><dd>' . hr_badge(hr_status_label($emp['status'])) . '</dd>'
       . '<dt>Work location</dt><dd>' . e($emp['location'] ?? '—') . '</dd>'
       . '<dt>Category</dt><dd>' . e($emp['category'] ?? '—') . '</dd>'
       . '<dt>Date of birth</dt><dd>' . e(hr_fmt_date($emp['date_of_birth'])) . '</dd>'
       . '</dl></div>';
    echo '<div class="card"><h2>Schedule and policies</h2><dl class="hr-kv">'
       . '<dt>Shift today</dt><dd>' . ($shift ? e($shift['name'] . ' · ' . substr($shift['start_time'], 0, 5) . '–' . substr($shift['end_time'], 0, 5)) : '<span class="req">None assigned</span>') . '</dd>'
       . '<dt>Weekly offs</dt><dd>' . e($emp['weekly_offs'] ? hr_offs_words($emp['weekly_offs']) . ' (own)' : hr_offs_words($shift['weekly_offs'] ?? '')) . '</dd>'
       . '<dt>Leave policy</dt><dd>' . ($pol ? e($pol['name']) . ($emp['leave_policy_id'] ? '' : ' <span class="muted small">(automatic)</span>') : '<span class="req">None applies</span>') . '</dd>'
       . '<dt>Holiday calendar</dt><dd>' . ($calId ? e(hr_calendars_map(false)[$calId] ?? '') : '<span class="req">None</span>') . '</dd>'
       . '<dt>Sign-in account</dt><dd>' . ($emp['person_id'] ? e($emp['account_name'] . ($emp['account_email'] ? ' — ' . $emp['account_email'] : '')) : 'Not linked') . '</dd>'
       . '</dl><h2 style="margin-top:16px">Contact</h2><dl class="hr-kv">'
       . '<dt>Address</dt><dd>' . nl2br(e($emp['address'] ?? '—')) . '</dd>'
       . '<dt>Emergency contact</dt><dd>' . e(trim(($emp['emergency_name'] ?? '') . ($emp['emergency_relation'] ? ' (' . $emp['emergency_relation'] . ')' : '') . ' ' . ($emp['emergency_phone'] ?? '')) ?: '—') . '</dd>'
       . '</dl>';
    if ($confView) {
        echo '<h2 style="margin-top:16px">Bank and statutory (confidential)</h2><dl class="hr-kv">'
           . '<dt>Payment mode</dt><dd>' . e(ucfirst((string) ($emp['payment_mode'] ?? '—'))) . '</dd>'
           . '<dt>Bank</dt><dd>' . e($emp['bank_name'] ?? '—') . '</dd>'
           . '<dt>Account</dt><dd>' . e($emp['bank_account'] ? 'XXXX' . substr($emp['bank_account'], -4) : '—') . '</dd>'
           . '<dt>IFSC</dt><dd>' . e($emp['bank_ifsc'] ?? '—') . '</dd>'
           . '<dt>PAN</dt><dd>' . e($emp['pan'] ?? '—') . '</dd><dt>UAN</dt><dd>' . e($emp['uan'] ?? '—') . '</dd>'
           . '<dt>PF / ESI</dt><dd>' . e(($emp['pf_number'] ?? '—') . ' / ' . ($emp['esi_number'] ?? '—')) . '</dd></dl>';
    }
    echo '</div></div>';

    if (hr_is_admin()) {
        $roles = hr_employee_roles($emp);
        echo '<div class="card" id="dashboard-access"><h2>Employee dashboard</h2>';
        if ($emp['person_id'] && $roles) {
            echo '<p class="mt0">' . hr_badge('active', 'Has access') . ' Signs in as <strong>' . e(fabo_login_id((int) $emp['person_id'])) . '</strong> · '
               . e(implode(', ', array_map(static fn($r) => $r['name'], $roles))) . '. Their HR emails go to the owner’s mailbox.</p><div class="hr-acts">'
               . hr_button_form('Email new sign-in details', ['action' => 'dash_resend'], 'btn btn-sm',
                                'Issue a new one-off password and email it to the owner? Their current password stops working.')
               . hr_button_form('Remove dashboard access', ['action' => 'dash_revoke'], 'btn btn-sm btn-danger',
                                'Remove their HR dashboard? Their records are kept and access can be given again.')
               . '</div>';
        } else {
            echo '<p class="mt0">' . ($emp['person_id'] ? 'Their sign-in account is linked but holds no HR role, so they cannot open HR.'
                                                       : 'They do not have their own HR dashboard yet.') . '</p>'
               . hr_form_open('', 'class="hr-form"') . '<input type="hidden" name="action" value="dash_give"><div class="formgrid">'
               . ($emp['person_id'] ? '<input type="hidden" name="dash_email" value="' . e($emp['account_email'] ?? '') . '">'
                    : '<input type="hidden" name="dash_email" value="">')
               . hr_field('What they can do', hr_select('dash_role', hr_dashboard_role_choices(), '', 'Employee self-service (their own record)'))
               . '</div><div class="field">' . hr_checkbox('dash_send', true, 'Email their sign-in details to the owner') . '</div>'
               . '<button class="btn btn-primary">Give dashboard access</button></form>';
        }
        echo '</div>';
    }

    if (hr_is_admin() || hr_emp_allowed($id, 'employees', 'delete')) {
        echo '<div class="card"><h2>Employment status</h2>';
        if (in_array($emp['status'], hr_working_statuses(), true)) {
            $openExit = hr_can('lifecycle', 'view') ? hr_separation_open($id) : null;
            if ($openExit) {
                echo '<p class="mt0">' . hr_badge('in clearance', hr_separation_statuses()[$openExit['status']]) . ' Exit in progress — last working day '
                   . e(hr_fmt_date($openExit['approved_lwd'] ?: $openExit['requested_lwd'])) . '. <a href="' . e(hr_url('lifecycle', ['sep' => $openExit['id']])) . '">Open the exit</a></p>';
            } elseif (hr_is_admin() || hr_scope('lifecycle', 'create') === 'org') {
                echo '<p class="mt0">Leaving? <a class="btn btn-sm btn-primary" href="' . e(hr_url('lifecycle', ['tab' => 'exits', 'new' => $id])) . '#record">Start the exit process</a> '
                   . '<span class="muted small">— notice period, clearance checklist, settlement and letters.</span></p>';
            }
            echo '<details><summary class="btn btn-sm">Or deactivate straight away</summary><div style="margin-top:10px">';
            echo '<p class="mt0">Deactivating keeps every record — leave, attendance, payroll, documents — and removes them from current lists.</p>'
               . hr_form_open('', 'class="hr-form" data-confirm="Deactivate ' . e($emp['full_name']) . '?"') . '<input type="hidden" name="action" value="deactivate"><div class="formgrid">'
               . hr_field('Reason', hr_select('status', ['resigned' => 'Resigned', 'terminated' => 'Terminated', 'inactive' => 'Inactive (other)'], 'resigned'), true)
               . hr_field('Last working day', hr_input('date_of_leaving', $emp['date_of_leaving'] ?? date('Y-m-d'), 'date'), true)
               . hr_field('Note', hr_input('reason', '', 'text', ['maxlength' => 200])) . '</div><button class="btn btn-danger">Deactivate employee</button></form></div></details>';
        } else {
            echo '<p class="mt0">This employee is ' . e(strtolower(hr_status_label($emp['status']))) . '.</p>'
               . hr_button_form('Reactivate', ['action' => 'reactivate'], 'btn btn-primary', 'Reactivate this employee and clear the leaving date?');
        }
        echo '</div>';
    }
}

if ($tab === 'leave') {
    $year = (int) ($_GET['year'] ?? 0) ?: hr_leave_year_of(date('Y-m-d'));
    $bal = hr_leave_balances($emp, $year);
    $years = [];
    for ($y = hr_leave_year_of(date('Y-m-d')) + 1; $y >= (int) substr((string) ($emp['date_of_joining'] ?: date('Y-m-d')), 0, 4) - 1 && count($years) < 8; $y--) {
        $years[$y] = hr_leave_year_label($y);
    }
    echo '<form method="get" class="hr-filters card"><input type="hidden" name="p" value="employee"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="tab" value="leave">'
       . '<div class="grp"><label for="ly">Leave year</label><select id="ly" name="year" data-autosubmit>' . hr_options($years, $year) . '</select></div></form>';
    require_once __DIR__ . '/leave_parts.php';
    hr_render_balance_cards($bal);
    hr_render_balance_table($bal);
    echo '<div class="card"><h2>Ledger — every credit and debit in ' . e(hr_leave_year_label($year)) . '</h2>';
    $led = hr_leave_ledger_rows($id, $year);
    if (!$led) {
        echo hr_empty('Nothing posted in this leave year.');
    } else {
        echo '<div class="scrollx"><table class="data hr-table stack"><thead><tr><th>Date</th><th>Leave type</th><th>What</th><th class="num">Days</th><th>Note</th><th>By</th></tr></thead><tbody>';
        foreach ($led as $l) {
            echo '<tr><td data-l="Date">' . e(hr_fmt_date($l['posted_on'])) . '</td><td data-l="Type">' . e($l['type_name']) . '</td><td data-l="What">'
               . e(hr_ledger_kinds()[$l['kind']] ?? $l['kind']) . '</td><td class="num" data-l="Days">' . ((float) $l['days'] > 0 ? '+' : '') . hr_fmt_days($l['days']) . '</td>'
               . '<td data-l="Note" class="small">' . e($l['note']) . '</td><td data-l="By" class="small">' . e($l['created_by']) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';
    if (hr_is_admin() || hr_emp_allowed($id, 'leave', 'configure')) {
        $tmap = [];
        foreach (hr_leave_types() as $t) { if (!$t['unlimited']) { $tmap[(int) $t['id']] = $t['name']; } }
        echo '<div class="card"><h2>Opening balance or adjustment</h2>' . hr_form_open('', 'class="hr-form"')
           . '<input type="hidden" name="action" value="leave_adjust"><input type="hidden" name="year" value="' . $year . '"><div class="formgrid">'
           . hr_field('Leave type', hr_select('leave_type_id', $tmap, '', 'Choose…', ['required' => true]), true)
           . hr_field('Kind', hr_select('kind', ['opening' => 'Opening balance (once per year)', 'adjustment' => 'Manual adjustment (+ or −)', 'encashed' => 'Encashed (takes days out)'], 'adjustment'), true)
           . hr_field('Days', hr_input('days', '', 'number', ['step' => '0.5', 'required' => true, 'placeholder' => 'e.g. 2 or -1']), true)
           . hr_field('Reason', hr_input('note', '', 'text', ['required' => true, 'maxlength' => 255]), true)
           . '</div><button class="btn btn-primary" data-confirm="Post this to ' . e($emp['full_name']) . '’s leave balance?">Post to balance</button></form></div>';
    }
    [$reqs] = hr_leave_list(['employee' => $id], 0, 100);
    echo '<div class="card"><h2>Leave history</h2>';
    hr_render_leave_rows($reqs, false);
    echo '</div>';
}

if ($tab === 'attendance') {
    $month = hr_month_param();
    $from = $month . '-01';
    $to = date('Y-m-t', strtotime($from));
    $grid = hr_att_grid([$emp], $from, $to)[$id] ?? [];
    $t = hr_att_totals($grid);
    echo '<form method="get" class="hr-filters card"><input type="hidden" name="p" value="employee"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="tab" value="attendance">'
       . '<div class="grp"><label for="am">Month</label><select id="am" name="month" data-autosubmit>' . hr_options(hr_month_choices(), $month) . '</select></div>'
       . '<a class="btn btn-sm" href="' . e(hr_url('file', ['kind' => 'report', 'report' => 'attendance_history', 'fmt' => 'xlsx', 'employee' => $id, 'from' => $from, 'to' => $to])) . '">Export</a></form>';
    echo '<div class="tiles">' . hr_tile('Present days', hr_fmt_days($t['present']), 'green') . hr_tile('Absent days', hr_fmt_days($t['absent']), $t['absent'] ? 'red' : '')
       . hr_tile('Leave days', hr_fmt_days($t['leave'])) . hr_tile('Late arrivals', (string) $t['late'], $t['late'] ? 'amber' : '')
       . hr_tile('Hours worked', e(hr_fmt_minutes($t['worked']))) . hr_tile('Overtime', e(hr_fmt_minutes($t['ot']))) . '</div>';
    echo '<div class="card"><div class="scrollx"><table class="data hr-table stack"><thead><tr><th>Date</th><th>Status</th><th>In</th><th>Out</th><th class="num">Worked</th><th>Late</th><th>Notes</th></tr></thead><tbody>';
    foreach ($grid as $d => [$st, $rec, $note]) {
        echo '<tr><td data-l="Date" class="nowrap">' . e(date('D j M', strtotime($d))) . '</td><td data-l="Status">' . ($st ? hr_badge($st, $st) : '<span class="muted">—</span>') . '</td>'
           . '<td data-l="In">' . e($rec && $rec['check_in'] ? date('H:i', strtotime($rec['check_in'])) : '') . '</td>'
           . '<td data-l="Out">' . e($rec && $rec['check_out'] ? date('H:i', strtotime($rec['check_out'])) : '') . '</td>'
           . '<td class="num" data-l="Worked">' . e($rec ? hr_fmt_minutes((int) $rec['worked_minutes']) : '') . '</td>'
           . '<td data-l="Late">' . e($rec && $rec['late_minutes'] ? $rec['late_minutes'] . ' min' : '') . '</td>'
           . '<td data-l="Notes" class="small">' . e(trim($note . ($rec && $rec['remarks'] ? ' · ' . $rec['remarks'] : ''))) . '</td></tr>';
    }
    echo '</tbody></table></div></div>';
}

if ($tab === 'payroll') {
    $structs = hr_structures($id);
    $cur = hr_structure_on($id, date('Y-m-d'));
    echo '<div class="card"><h2>Salary structure</h2>';
    if ($cur && hr_emp_allowed($id, 'confidential', 'view')) {
        $prev = hr_structure_preview(array_combine(array_map(static fn($l) => (int) $l['component_id'], $cur['lines']),
                array_map(static fn($l) => [$l['calc'], $l['value']], $cur['lines'])), (float) $cur['monthly_gross']);
        echo '<p class="mt0">In force since ' . e(hr_fmt_date($cur['effective_from'])) . ' · monthly gross <strong>' . e(fabo_money_exact($cur['monthly_gross'])) . '</strong></p>'
           . '<table class="data hr-table"><thead><tr><th>Component</th><th>Type</th><th>Set as</th></tr></thead><tbody>';
        foreach ($cur['lines'] as $l) {
            echo '<tr><td>' . e($l['name']) . '</td><td>' . e(hr_component_kinds()[$l['kind']] ?? $l['kind']) . '</td><td>'
               . e($l['calc'] === 'fixed' ? fabo_money_exact($l['value']) : ($l['calc'] === 'balance' ? 'Balance: ' . fabo_money_exact(max(0, $prev['balance']))
                  : hr_fmt_days($l['value']) . '% of ' . ($l['calc'] === 'pct_basic' ? 'Basic' : 'Gross'))) . '</td></tr>';
        }
        echo '</tbody></table>';
    } elseif (!$cur) {
        echo hr_empty('No salary structure yet.', ' Payroll cannot pay this employee until one is set.');
    } else {
        echo '<p class="muted">Salary details are confidential.</p>';
    }
    if (hr_emp_allowed($id, 'payroll', 'process_payroll')) {
        echo '<p><a class="btn btn-sm btn-primary" href="' . e(hr_url('payroll', ['tab' => 'structures', 'employee' => $id])) . '">' . ($cur ? 'Revise salary' : 'Set salary structure') . '</a></p>';
    }
    if (count($structs) > 1 && hr_emp_allowed($id, 'confidential', 'view')) {
        echo '<h3>Revision history</h3><ul class="hr-list">';
        foreach ($structs as $s) {
            echo '<li><span>' . e(hr_fmt_date($s['effective_from'])) . ($s['effective_to'] ? ' – ' . e(hr_fmt_date($s['effective_to'])) : ' onwards') . '</span><span>'
               . e(fabo_money_exact($s['monthly_gross'])) . ' gross' . ($s['note'] ? ' · ' . e($s['note']) : '') . '</span></li>';
        }
        echo '</ul>';
    }
    echo '</div><div class="card"><h2>Payroll history</h2>';
    $hist = hr_emp_pay_history($id);
    if (!$hist) {
        echo hr_empty('No payroll yet.');
    } else {
        echo '<table class="data hr-table stack"><thead><tr><th>Month</th><th>Status</th><th class="num">Payable days</th><th class="num">Gross</th><th class="num">Deductions</th><th class="num">Net</th><th>Payment</th><th></th></tr></thead><tbody>';
        foreach ($hist as $h) {
            $issued = in_array($h['run_status'], ['payslips'], true) && $h['number'];
            if (!hr_is_admin() && hr_scope('payroll', 'view') !== 'org' && !$issued) {
                continue;     // an employee sees a month once its payslip is out
            }
            echo '<tr><td data-l="Month">' . e(date('F Y', strtotime($h['period'] . '-01'))) . '</td><td data-l="Status">' . hr_badge($h['run_status'], hr_run_statuses()[$h['run_status']]) . '</td>'
               . '<td class="num" data-l="Payable">' . hr_fmt_days($h['payable_days']) . '</td><td class="num" data-l="Gross">' . e(fabo_money_exact($h['gross'])) . '</td>'
               . '<td class="num" data-l="Deductions">' . e(fabo_money_exact($h['deductions'])) . '</td><td class="num" data-l="Net"><strong>' . e(fabo_money_exact($h['net'])) . '</strong></td>'
               . '<td data-l="Payment">' . e(ucfirst(str_replace('_', ' ', $h['payment_status']))) . '</td><td>'
               . ($issued || hr_scope('payroll', 'view') === 'org' ? '<a class="btn btn-sm" href="' . e(hr_url('file', ['kind' => 'payslip', 'item' => $h['id']])) . '">Payslip PDF</a>' : '') . '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';
}

if ($tab === 'documents') {
    $docs = hr_employee_docs($id);
    echo '<div class="card"><h2>Employment documents</h2>';
    if (!$docs) {
        echo hr_empty('No documents yet.');
    } else {
        echo '<ul class="hr-list">';
        foreach ($docs as $d) {
            echo '<li><span><a href="' . e(hr_url('file', ['kind' => 'doc', 'id' => $d['id']])) . '">' . e($d['title']) . '</a> <span class="muted small">'
               . e($d['original_name']) . ' · ' . e(fabo_human_size((int) $d['size'])) . ' · ' . e(hr_fmt_date($d['created_at'])) . '</span></span>'
               . ($confEdit ? hr_button_form('Remove', ['action' => 'doc_delete', 'doc_id' => $d['id']], 'btn btn-sm btn-danger', 'Remove this document?') : '') . '</li>';
        }
        echo '</ul>';
    }
    if ($confEdit) {
        echo '<h3 style="margin-top:14px">Add a document</h3>' . hr_form_open('', 'class="hr-form"', true) . '<input type="hidden" name="action" value="doc_add"><div class="formgrid">'
           . hr_field('Title', hr_input('title', '', 'text', ['required' => true, 'maxlength' => 160, 'placeholder' => 'e.g. Offer letter']), true)
           . hr_field('File', '<input type="file" name="doc" required>', true, 'PDF, Office files or images, up to ' . e(fabo_upload_limit_words()) . '.')
           . '</div><button class="btn btn-primary">Upload</button></form>';
    }
    echo '</div>';
}

if ($tab === 'journey') {
    foreach (['onboarding' => 'Onboarding', 'offboarding' => 'Exit checklist'] as $k => $t) {
        $list = hr_tasks_for($id, $k);
        echo '<div class="card"><h2>' . e($t) . '</h2>';
        if ($list) {
            [$d, $n] = hr_task_progress($id, $k);
            echo '<p class="mt0">' . hr_progress_html($d, $n) . '</p>';
            hr_render_tasks($list, hr_url('lifecycle', ['emp' => $id]));
        } else {
            echo hr_empty($k === 'onboarding' ? 'No onboarding checklist was started.' : 'No exit in progress.');
        }
        echo '</div>';
    }
    echo '<p><a class="btn btn-sm btn-primary" href="' . e(hr_url('lifecycle', ['emp' => $id])) . '">Manage onboarding &amp; exit</a></p>';
}

if ($tab === 'assets') {
    $held = hr_emp_assets($id);
    echo '<div class="card"><h2>Company assets held now</h2>';
    if (!$held) {
        echo hr_empty('None.');
    } else {
        echo '<ul class="hr-list">';
        foreach ($held as $a) {
            echo '<li><span><a href="' . e(hr_url('assets', ['asset' => $a['id']])) . '"><strong>' . e($a['tag']) . '</strong></a> ' . e($a['name'])
               . ' <span class="muted small">' . e($a['category'] ?? '') . '</span></span><span class="muted small">since ' . e(hr_fmt_date($a['assigned_on'])) . '</span></li>';
        }
        echo '</ul>';
    }
    if (hr_is_admin() || hr_scope('assets', 'edit') === 'org') {
        echo '<p><a class="btn btn-sm" href="' . e(hr_url('assets', ['status' => 'in_stock'])) . '">Issue an asset from stock</a></p>';
    }
    echo '</div>';
    $hist = fabo_all('SELECT x.*, a.tag, a.name FROM hr_asset_assignments x JOIN hr_assets a ON a.id = x.asset_id WHERE x.employee_id = ? AND x.org_id = ? ORDER BY x.assigned_on DESC',
                     'ii', [$id, hr_org()]);
    if ($hist) {
        echo '<div class="card"><h2>Asset history</h2><table class="data hr-table stack"><thead><tr><th>Asset</th><th>Issued</th><th>Returned</th></tr></thead><tbody>';
        foreach ($hist as $h) {
            echo '<tr><td data-l="Asset">' . e($h['tag'] . ' ' . $h['name']) . '</td><td data-l="Issued">' . e(hr_fmt_date($h['assigned_on'])) . '</td>'
               . '<td data-l="Returned">' . ($h['returned_on'] ? e(hr_fmt_date($h['returned_on'])) : hr_badge('assigned', 'Still held')) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
}

if ($tab === 'claims') {
    $rows = hr_claims_list(['employee' => $id], 200);
    echo '<div class="card"><h2>Expense claims</h2>';
    if (!$rows) {
        echo hr_empty('No claims.');
    } else {
        echo '<table class="data hr-table stack"><thead><tr><th>Claim</th><th>Category</th><th>Spent on</th><th class="num">Amount</th><th>Status</th></tr></thead><tbody>';
        foreach ($rows as $c) {
            echo '<tr><td data-l="Claim"><a href="' . e(hr_url('claims', ['id' => $c['id']])) . '">' . e($c['number']) . '</a><div class="muted small">' . e(mb_strimwidth($c['description'], 0, 60, '…')) . '</div></td>'
               . '<td data-l="Category">' . e($c['category'] ?? '—') . '</td><td data-l="Spent on">' . e(hr_fmt_date($c['expense_date'])) . '</td>'
               . '<td class="num" data-l="Amount">' . e(fabo_money_exact($c['amount'])) . '</td><td data-l="Status">' . hr_badge($c['status'], hr_claim_statuses()[$c['status']]) . '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';
}

if ($tab === 'growth') {
    if (hr_emp_allowed($id, 'performance', 'view')) {
        $revs = fabo_all('SELECT r.*, c.name AS cycle FROM hr_reviews r JOIN hr_review_cycles c ON c.id = r.cycle_id WHERE r.employee_id = ? AND r.org_id = ? ORDER BY c.period_to DESC',
                         'ii', [$id, hr_org()]);
        echo '<div class="card"><h2>Performance reviews</h2>';
        if (!$revs) {
            echo hr_empty('No reviews yet.');
        } else {
            echo '<ul class="hr-list">';
            foreach ($revs as $r) {
                echo '<li><span><a href="' . e(hr_url('performance', ['id' => $r['id']])) . '">' . e($r['cycle']) . '</a></span><span>'
                   . hr_badge(strtolower(hr_review_stages()[$r['stage']]), hr_review_stages()[$r['stage']]) . ' ' . e($r['final_rating'] !== null ? hr_rating_words($r['final_rating']) : '') . '</span></li>';
            }
            echo '</ul>';
        }
        echo '</div>';
    }
    if (hr_emp_allowed($id, 'training', 'view')) {
        $tr = hr_emp_trainings($id);
        echo '<div class="card"><h2>Training</h2>';
        if (!$tr) {
            echo hr_empty('No training yet.');
        } else {
            echo '<ul class="hr-list">';
            foreach ($tr as $t) {
                echo '<li><span><a href="' . e(hr_url('training', ['id' => $t['training_id']])) . '">' . e($t['title']) . '</a> <span class="muted small">' . e(hr_fmt_date($t['start_date']))
                   . ($t['hours'] ? ' · ' . e(hr_fmt_days($t['hours'])) . ' h' : '') . '</span></span>' . hr_badge(strtolower(str_replace('_', ' ', hr_enrolment_statuses()[$t['status']])), hr_enrolment_statuses()[$t['status']]) . '</li>';
            }
            echo '</ul>';
        }
        echo '</div>';
    }
}

if ($tab === 'history') {
    $hist = hr_employee_history($id);
    echo '<div class="card"><h2>Changes to this record</h2>';
    if (!$hist) {
        echo hr_empty('No changes recorded.');
    } else {
        echo '<div class="scrollx"><table class="data hr-table stack"><thead><tr><th>When</th><th>What</th><th>From</th><th>To</th><th>Effective</th><th>By</th></tr></thead><tbody>';
        foreach ($hist as $h) {
            echo '<tr><td data-l="When" class="nowrap">' . e(date('d M Y H:i', strtotime($h['changed_at']))) . '</td><td data-l="What">' . e($h['field']) . '</td>'
               . '<td data-l="From">' . e($h['old_value'] ?? '—') . '</td><td data-l="To">' . e($h['new_value'] ?? '—') . '</td>'
               . '<td data-l="Effective">' . e(hr_fmt_date($h['effective_on'])) . '</td><td data-l="By">' . e($h['changed_by']) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';
}

hr_layout_end();

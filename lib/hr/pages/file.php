<?php
/**
 * Every HR download goes through here: photos, documents, leave
 * attachments, payslips, reports, templates and the configuration export.
 * Each kind checks the viewer's right to that module AND to that employee
 * before a byte is sent. Files are read from uploads/ (which the web server
 * refuses to serve directly), and sent no-store.
 */

$kind = (string) ($_GET['kind'] ?? '');

/** Sends a stored upload. Images inline, everything else as an attachment. */
function hr_send_stored(string $stored, string $mime, string $name, bool $inline = false): void
{
    if (!preg_match('/^[0-9a-f]{32}\.[A-Za-z0-9]{1,8}$/', $stored)) {
        fabo_deny('That file is not available.', 404);
    }
    $path = fabo_upload_dir() . DIRECTORY_SEPARATOR . $stored;
    if (!is_file($path)) {
        fabo_deny('That file is no longer on the server.', 404);
    }
    $inline = $inline && fabo_is_inline_image($mime);
    header('Content-Type: ' . ($inline ? $mime : 'application/octet-stream'));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . preg_replace('/[^A-Za-z0-9._ -]/', '_', $name) . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    readfile($path);
    exit;
}

switch ($kind) {
    case 'photo':
        $id = (int) ($_GET['id'] ?? 0);
        hr_need_emp($id, 'employees', 'view');
        $e = hr_employee($id);
        if (!$e || !$e['photo_file']) {
            fabo_deny('No photo.', 404);
        }
        hr_send_stored($e['photo_file'], (string) $e['photo_mime'], 'photo', true);

    case 'doc':
        $doc = fabo_one('SELECT d.*, e.org_id FROM hr_employee_docs d JOIN hr_employees e ON e.id = d.employee_id WHERE d.id = ?', 'i', [(int) ($_GET['id'] ?? 0)]);
        if (!$doc || (int) $doc['org_id'] !== hr_org() || !hr_emp_allowed((int) $doc['employee_id'], 'confidential', 'view')) {
            fabo_deny('That document is not available.', 404);
        }
        hr_audit('download', 'employee_doc', (int) $doc['id'], 'Downloaded ' . $doc['title']);
        hr_send_stored($doc['stored_name'], $doc['mime'], $doc['original_name']);

    case 'leave_att':
        $r = hr_leave_request((int) ($_GET['id'] ?? 0));
        if (!$r || !$r['attachment_file'] || !hr_emp_allowed((int) $r['employee_id'], 'leave', 'view')) {
            fabo_deny('That attachment is not available.', 404);
        }
        hr_send_stored($r['attachment_file'], (string) $r['attachment_mime'], (string) $r['attachment_name']);

    case 'resume':
        $c = hr_candidate((int) ($_GET['id'] ?? 0));
        if (!$c || !$c['resume_file']) {
            fabo_deny('That résumé is not available.', 404);
        }
        hr_audit('download', 'candidate', (int) $c['id'], 'Résumé downloaded: ' . $c['full_name']);
        hr_send_stored($c['resume_file'], (string) $c['resume_mime'], (string) $c['resume_name']);

    case 'claim':
        $c = hr_claim((int) ($_GET['id'] ?? 0));
        if (!$c || !$c['receipt_file']) {
            fabo_deny('That receipt is not available.', 404);
        }
        hr_send_stored($c['receipt_file'], (string) $c['receipt_mime'], (string) $c['receipt_name'], true);

    case 'letter':
        $what = (string) ($_GET['what'] ?? '');
        if ($what === 'offer') {
            $c = hr_candidate((int) ($_GET['cand'] ?? 0), 'edit');
            if (!$c || !in_array($c['stage'], ['offer', 'hired'], true)) {
                fabo_deny('That letter is not available.', 404);
            }
            hr_audit('letter', 'candidate', (int) $c['id'], 'Offer letter for ' . $c['full_name']);
            hr_offer_letter($c)->send('offer-letter-' . preg_replace('/[^A-Za-z0-9]+/', '-', $c['full_name']) . '.pdf');
        }
        $s = hr_separation((int) ($_GET['sep'] ?? 0));
        if (!$s || !in_array($what, ['relieving', 'experience'], true) || !in_array($s['status'], ['approved', 'completed'], true)
            || (!hr_is_admin() && hr_scope('lifecycle', 'edit') !== 'org')) {
            fabo_deny('That letter is not available.', 404);
        }
        hr_audit('letter', 'separation', (int) $s['id'], ucfirst($what) . ' letter for ' . $s['full_name']);
        hr_exit_letter($s, $what)->send($what . '-letter-' . $s['emp_code'] . '.pdf');

    case 'payslip':
        $itemId = (int) ($_GET['item'] ?? 0);
        $data = hr_payslip_data($itemId);
        if (!$data || !hr_emp_allowed((int) $data['item']['employee_id'], 'payroll', 'view')) {
            fabo_deny('That payslip is not available.', 404);
        }
        // Below organisation scope a payslip exists only once it has been issued.
        $orgScope = hr_is_admin() || hr_scope('payroll', 'view') === 'org';
        if (!$orgScope && ($data['item']['run_status'] !== 'payslips' || !$data['item']['number'])) {
            fabo_deny('That payslip has not been issued yet.', 404);
        }
        hr_audit('download', 'payslip', $itemId, 'Payslip ' . ($data['item']['number'] ?: '(preview)') . ' for ' . $data['emp']['full_name']);
        hr_payslip_pdf($data)->send('payslip-' . $data['emp']['emp_code'] . '-' . $data['item']['period'] . '.pdf', !isset($_GET['view']));

    case 'report':
        $key = (string) ($_GET['report'] ?? '');
        if (!hr_report_allowed($key)) {
            fabo_deny('That report is not available to you.', 403);
        }
        try {
            $r = hr_report_build($key, hr_report_filters($_GET));
        } catch (InvalidArgumentException $e) {
            fabo_deny($e->getMessage(), 400);
        }
        $fmt = (string) ($_GET['fmt'] ?? 'xlsx');
        hr_report_send($r, in_array($fmt, ['xlsx', 'csv', 'pdf'], true) ? $fmt : 'xlsx', $key);

    case 'template':
        $what = (string) ($_GET['what'] ?? '');
        $tpl = [
            'employees'  => ['employees', [['Employee ID', 'Full Name', 'Work Email', 'Phone', 'Department', 'Designation', 'Reporting Manager',
                             'Date of Joining', 'Date of Birth', 'Employment Type', 'Status', 'Work Location', 'Category', 'Gender', 'Address',
                             'Emergency Contact', 'Emergency Phone']]],
            'attendance' => ['attendance', [['Employee ID', 'Date', 'Check In', 'Check Out', 'Break (min)', 'Status', 'Remarks']]],
            'holidays'   => ['holidays', [['Holiday Name', 'Date', 'End Date', 'Type', 'Recurring', 'Description']]],
        ][$what] ?? null;
        if (!$tpl) {
            fabo_deny('Unknown template.', 404);
        }
        hr_need($tpl[0], 'view');
        hr_csv_download('hr-' . $what . '-template.csv', $tpl[1]);

    case 'config':
        if (!hr_is_admin() && !hr_can('sheets', 'view')) {
            fabo_deny('Not available.', 403);
        }
        $x = new FaboXlsx();
        $org = hr_org();
        $dump = static function (string $title, array $head, array $rows) use ($x) {
            $out = [$head];
            foreach ($rows as $r) {
                $out[] = array_map(static fn($v) => is_string($v) ? FaboXlsx::sanitiseText($v) : $v, array_values($r));
            }
            $x->addSheet($title, $out);
        };
        $dump('Departments', ['Name', 'Code', 'Description', 'Active'], fabo_all('SELECT name, code, description, active FROM hr_departments WHERE org_id = ? ORDER BY name', 'i', [$org]));
        $dump('Designations', ['Name', 'Department', 'Level', 'Active'], fabo_all('SELECT g.name, d.name AS dept, g.level, g.active FROM hr_designations g LEFT JOIN hr_departments d ON d.id = g.department_id WHERE g.org_id = ? ORDER BY g.name', 'i', [$org]));
        $dump('Lists', ['List', 'Name', 'Active'], fabo_all('SELECT kind, name, active FROM hr_masters WHERE org_id = ? ORDER BY kind, name', 'i', [$org]));
        $dump('Shifts', ['Name', 'Code', 'Start', 'End', 'Break min', 'Grace min', 'Early grace', 'Full day h', 'Half day h', 'OT after h', 'Weekly offs', 'Default', 'Active'],
              array_map(static function ($s) { $s['weekly_offs'] = hr_offs_words($s['weekly_offs']); return $s; },
                        fabo_all('SELECT name, code, start_time, end_time, break_minutes, grace_minutes, early_grace_minutes, full_day_hours, half_day_hours, overtime_after_hours, weekly_offs, is_default, active FROM hr_shifts WHERE org_id = ?', 'i', [$org])));
        $dump('Holidays', ['Calendar', 'Name', 'From', 'To', 'Type', 'Optional', 'Recurring', 'Active'],
              fabo_all('SELECT c.name AS cal, h.name, h.start_date, h.end_date, h.type, h.is_optional, h.recurring, h.active FROM hr_holidays h JOIN hr_holiday_calendars c ON c.id = h.calendar_id WHERE h.org_id = ? ORDER BY h.start_date', 'i', [$org]));
        $dump('Leave types', ['Code', 'Name', 'Paid', 'Comp off', 'Unlimited', 'Attachment after days', 'Active'],
              fabo_all('SELECT code, name, is_paid, is_comp_off, unlimited, attachment_after_days, active FROM hr_leave_types WHERE org_id = ? ORDER BY sort, name', 'i', [$org]));
        $dump('Leave rules', ['Policy', 'Leave type', 'Days/year', 'Accrual', 'Carry forward', 'Carry limit', 'Max consecutive', 'Min service days', 'Half day', 'Negative allowed', 'Count weekly offs', 'Count holidays', 'Effective'],
              fabo_all('SELECT p.name, t.name AS t, r.annual_entitlement, r.accrual, r.carry_forward, r.carry_limit, r.max_consecutive, r.min_service_days, r.allow_half_day, r.allow_negative, r.count_weekly_offs, r.count_holidays, r.effective_from
                        FROM hr_leave_rules r JOIN hr_leave_policies p ON p.id = r.policy_id JOIN hr_leave_types t ON t.id = r.leave_type_id WHERE p.org_id = ? ORDER BY p.name, t.sort', 'i', [$org]));
        if (hr_is_admin() || hr_can('payroll', 'configure')) {
            $dump('Salary components', ['Code', 'Name', 'Kind', 'Calc', 'Default', 'Wage ceiling', 'Gross limit', 'Prorate', 'Basic', 'Active'],
                  fabo_all('SELECT code, name, kind, calc, default_value, wage_ceiling, gross_limit, prorate, is_basic, active FROM hr_salary_components WHERE org_id = ? ORDER BY sort', 'i', [$org]));
        }
        $settings = [];
        foreach (hr_setting_defs() as $k => $d) {
            $settings[] = [ucfirst($d['area']), $d['label'], (string) (hr_setting($k) ?? '(not configured)')];
        }
        $dump('Settings', ['Area', 'Setting', 'Value'], $settings);
        hr_audit('export', 'config', null, 'HR configuration exported');
        $x->download('hr-configuration-' . date('Ymd') . '.xlsx');
        exit;
}
fabo_deny('Not available.', 404);

<?php
/**
 * The signatory directory: who may sign, with what authority and limit,
 * their department and unit, how they sign, and their certificates.
 * Names and e-mail are the portal's own (clients); HR fills the gaps.
 */

$scope = ds_scope_depts('signatories.manage');
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $flash = ds_action(static function () use ($scope) {
        $pid = (int) ($_POST['person_id'] ?? 0);
        if (!ds_is_member($pid) || $pid === 0) {
            throw new InvalidArgumentException('Choose someone in this organisation.');
        }
        switch ($_POST['action'] ?? '') {
            case 'save':
                $dept = (int) ($_POST['department_id'] ?? 0) ?: null;
                if ($scope !== null && (!$dept || !in_array($dept, $scope, true))) {
                    throw new InvalidArgumentException('You manage signatories of your own departments only.');
                }
                $lim = ds_amount($_POST['authority_limit'] ?? '');
                $vals = [ds_str($_POST['employee_id'] ?? '', 40) ?: null, ds_str($_POST['mobile'] ?? '', 20) ?: null, $dept, ds_str($_POST['designation'] ?? '', 120) ?: null,
                         ds_str($_POST['unit'] ?? '', 120) ?: null, ds_str($_POST['authority'] ?? '', 120) ?: null, $lim,
                         ds_pick($_POST['provider_pref'] ?? '', ['' => 1, 'esign' => 1, 'dsc' => 1, 'drawn' => 1, 'eapproval' => 1], 'preferred method') ?: null,
                         !empty($_POST['may_delegate']) ? 1 : 0, ($_POST['status'] ?? 'active') === 'suspended' ? 'suspended' : 'active', ds_str($_POST['notes'] ?? '', 255) ?: null];
                if ($vals[1] !== null && !preg_match('/^[+0-9 ()-]{7,20}$/', $vals[1])) {
                    throw new InvalidArgumentException('Give a valid mobile number.');
                }
                fabo_exec('INSERT INTO ds_signatories (employee_id, mobile, department_id, designation, unit, authority, authority_limit, provider_pref, may_delegate, status, notes, org_id, person_id)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE employee_id=VALUES(employee_id), mobile=VALUES(mobile), department_id=VALUES(department_id),
                           designation=VALUES(designation), unit=VALUES(unit), authority=VALUES(authority), authority_limit=VALUES(authority_limit), provider_pref=VALUES(provider_pref),
                           may_delegate=VALUES(may_delegate), status=VALUES(status), notes=VALUES(notes)', 'ssisssssissii', array_merge($vals, [ds_org(), $pid]));
                ds_audit(null, 'signatory', $pid, 'signatory_save', 'Saved the signatory profile of ' . ds_person_name($pid) . ($vals[5] ? ' (authority: ' . $vals[5] . ($lim ? ' up to ' . ds_money($lim) : '') . ')' : ''));
                return 'Saved ' . ds_person_name($pid) . '.';
            case 'cert':
                $up = ds_upload_bytes('cert');
                $data = $up ? $up[0] : (string) ($_POST['pem'] ?? '');
                $c = ds_cert_summary(trim($data) !== '' && !str_contains($data, '-----BEGIN') ? ds_pem($data) : $data);
                if (!$c) {
                    throw new InvalidArgumentException('That is not a certificate. Export the DSC\'s public certificate (.cer) and upload it.');
                }
                if (!ds_cert_can_sign($c)) {
                    throw new InvalidArgumentException('That certificate is not issued for signing.');
                }
                $other = fabo_one('SELECT person_id FROM ds_certificates WHERE org_id = ? AND fingerprint = ? AND source = "registered"', 'is', [ds_org(), $c['fingerprint']]);
                if ($other && (int) $other['person_id'] !== $pid) {
                    throw new InvalidArgumentException('That certificate is already registered to ' . ds_person_name((int) $other['person_id']) . '.');
                }
                fabo_exec('INSERT INTO ds_certificates (org_id, person_id, source, fingerprint, serial, subject_cn, subject_dn, issuer_dn, email, not_before, not_after, pem, status)
                           VALUES (?,?,"registered",?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE person_id = VALUES(person_id), source = "registered"', 'iissssssssss',
                          [ds_org(), $pid, $c['fingerprint'], $c['serial'], mb_substr($c['cn'], 0, 255), mb_substr($c['subject_dn'], 0, 600), mb_substr($c['issuer_dn'], 0, 600), $c['email'] ?: null,
                           gmdate('Y-m-d H:i:s', $c['not_before']), gmdate('Y-m-d H:i:s', $c['not_after']), $c['pem'], $c['not_after'] < time() ? 'expired' : 'unknown']);
                ds_audit(null, 'certificate', $pid, 'cert_register', 'Registered certificate "' . $c['cn'] . '" (serial ' . $c['serial'] . ', issued by ' . $c['issuer_cn'] . ') to ' . ds_person_name($pid), ['fingerprint' => $c['fingerprint']]);
                return 'Certificate registered to ' . ds_person_name($pid) . '.';
            case 'uncert':
                $n = fabo_exec('DELETE FROM ds_certificates WHERE org_id = ? AND id = ? AND person_id = ?', 'iii', [ds_org(), (int) ($_POST['cert_id'] ?? 0), $pid]);
                ds_audit(null, 'certificate', $pid, 'cert_remove', 'Removed a certificate from ' . ds_person_name($pid));
                return $n ? 'Certificate removed.' : 'Nothing removed.';
        }
        throw new InvalidArgumentException('Unknown action.');
    }, ds_url('signatories', ['person' => $_POST['person_id'] ?? null]));
}

$people = ds_people(true);
$profiles = [];
foreach (fabo_all('SELECT * FROM ds_signatories WHERE org_id = ?', 'i', [ds_org()]) as $r) {
    $profiles[(int) $r['person_id']] = $r;
}
$certs = [];
foreach (fabo_all('SELECT * FROM ds_certificates WHERE org_id = ? AND person_id IS NOT NULL ORDER BY not_after DESC', 'i', [ds_org()]) as $c) {
    $certs[(int) $c['person_id']][] = $c;
}
$q = mb_strtolower(trim((string) ($_GET['q'] ?? '')));
ds_layout_start('Signatory directory', 'Signing authority, limits and certificates. A workflow stage that names an authority (e.g. "Department Head") is given to the person holding it here.', '',
    ['Administration' => ds_url('admin'), 'Signatories' => null], $flash);
echo '<form method="get" class="ds-inlineform ds-searchbar"><input type="hidden" name="p" value="signatories">' . ds_icon('search') . '<input type="search" name="q" value="' . e($q) . '" placeholder="Search people, authority, department"></form>';
echo '<div class="ds-card ds-rise"><div class="ds-tablewrap"><table class="ds-table"><thead><tr><th>Name</th><th>Employee ID</th><th>Department</th><th>Designation</th><th>Unit</th><th>Signing authority</th><th>Method</th><th>Certificate</th><th>Status</th><th></th></tr></thead><tbody>';
foreach ($people as $pid => $person) {
    $pr = $profiles[$pid] ?? null;
    $hr = ds_hr_of($pid);
    $dept = $pr['department_id'] ?? ($hr['department_id'] ?? null);
    if ($scope !== null && !in_array((int) $dept, $scope, true)) {
        continue;
    }
    $hay = mb_strtolower($person['name'] . ' ' . $person['email'] . ' ' . ($pr['authority'] ?? '') . ' ' . ds_dept_name($dept ? (int) $dept : null));
    if ($q !== '' && !str_contains($hay, $q)) {
        continue;
    }
    $cs = $certs[$pid] ?? [];
    $best = $cs[0] ?? null;
    $cstate = !$best ? '<span class="ds-muted">—</span>' : (strtotime($best['not_after']) < time() ? ds_badge('Expired', 'red') : (strtotime($best['not_after']) < time() + 30 * 86400 ? ds_badge('Expires ' . date('d M', strtotime($best['not_after'])), 'amber') : ds_badge('Valid to ' . date('M Y', strtotime($best['not_after'])), 'green')));
    echo '<tr><td data-l="Name"><span class="ds-who">' . ds_avatar($pid, 'sm') . '<span><strong>' . e($person['name']) . '</strong><small class="ds-muted">' . e((string) $person['email']) . '</small></span></span></td>'
       . '<td data-l="Employee ID">' . e((string) ($pr['employee_id'] ?? ($hr['employee_code'] ?? ''))) . '</td><td data-l="Department">' . e(ds_dept_name($dept ? (int) $dept : null)) . '</td>'
       . '<td data-l="Designation">' . e(ds_designation($pid)) . '</td><td data-l="Unit">' . e((string) ($pr['unit'] ?? '')) . '</td>'
       . '<td data-l="Authority">' . ($pr && $pr['authority'] ? '<strong>' . e($pr['authority']) . '</strong>' . ($pr['authority_limit'] !== null ? '<small class="ds-muted"> up to ' . e(ds_money($pr['authority_limit'])) . '</small>' : '') : '<span class="ds-muted">—</span>') . '</td>'
       . '<td data-l="Method">' . ($pr && $pr['provider_pref'] ? ds_method_chip($pr['provider_pref']) : '<span class="ds-muted">Any</span>') . '</td><td data-l="Certificate">' . $cstate . '</td>'
       . '<td data-l="Status">' . ($pr ? ($pr['status'] === 'active' ? ds_badge('Active', 'green') : ds_badge('Suspended', 'red')) : ds_badge('No profile', 'grey')) . '</td>'
       . '<td><button type="button" class="ds-btn ds-btn-ghost ds-btn-sm" data-open="dlg-sig-' . $pid . '">' . ds_icon('edit') . 'Edit</button></td></tr>';
    // the edit dialog
    $v = static fn($k, $d = '') => e((string) ($pr[$k] ?? $d));
    $dlg = '<dialog class="ds-dlg wide" id="dlg-sig-' . $pid . '">' . ds_form('', true) . '<input type="hidden" name="action" value="save"><input type="hidden" name="person_id" value="' . $pid . '">'
         . '<header><h3>' . ds_avatar($pid, 'sm') . e($person['name']) . '</h3><button type="button" class="ds-icobtn" data-close aria-label="Close">' . ds_icon('x') . '</button></header><div class="ds-dlg-b ds-formgrid">'
         . ds_field('Employee ID', '<input name="employee_id" maxlength="40" value="' . $v('employee_id', (string) ($hr['employee_code'] ?? '')) . '">')
         . ds_field('Mobile', '<input name="mobile" maxlength="20" value="' . $v('mobile', (string) ($hr['mobile'] ?? '')) . '">', 'For eSign it should be the Aadhaar-linked number.')
         . ds_field('Department', ds_select('department_id', ds_departments(), $pr['department_id'] ?? ($hr['department_id'] ?? ''), 'None'))
         . ds_field('Designation', '<input name="designation" maxlength="120" value="' . $v('designation', (string) ($hr['designation'] ?? '')) . '">')
         . ds_field('Organisational unit', ds_units() ? ds_select('unit', array_combine(ds_units(), ds_units()), $pr['unit'] ?? '', 'None') : '<input name="unit" value="' . $v('unit') . '">')
         . ds_field('Signing authority', '<input name="authority" maxlength="120" value="' . $v('authority') . '" list="ds-auths" placeholder="e.g. Department Head, CEO">', 'Workflow stages find signatories by this.')
         . ds_field('Authority limit (₹)', '<input name="authority_limit" value="' . $v('authority_limit') . '" placeholder="No limit">', 'Only documents up to this value are routed to them by authority.')
         . ds_field('Preferred method', ds_select('provider_pref', ['esign' => 'Aadhaar eSign', 'dsc' => 'DSC', 'drawn' => 'Drawn', 'eapproval' => 'Electronic approval'], $pr['provider_pref'] ?? '', 'Any'))
         . ds_field('Account status', ds_select('status', ['active' => 'Active', 'suspended' => 'Suspended'], $pr['status'] ?? 'active'))
         . ds_field('Notes', '<input name="notes" maxlength="255" value="' . $v('notes') . '">')
         . '<label class="ds-check span-2"><input type="checkbox" name="may_delegate" value="1"' . (!empty($pr['may_delegate']) ? ' checked' : '') . '> May delegate signing while away (only where policy allows; the delegate signs in their own name)</label>'
         . '</div><footer><button type="button" class="ds-btn ds-btn-ghost" data-close>Cancel</button><button class="ds-btn ds-btn-primary">Save profile</button></footer></form>'
         . '<div class="ds-dlg-b ds-certs"><h4>' . ds_icon('key') . 'Certificates</h4>';
    foreach ($cs as $c) {
        $dlg .= '<div class="ds-certrow"><div><strong>' . e((string) $c['subject_cn']) . '</strong> ' . ($c['source'] === 'registered' ? ds_badge('Registered', 'blue', false) : ds_badge('Seen on a signature', 'grey', false))
              . '<small class="ds-muted">' . e((string) $c['issuer_dn']) . ' · serial ' . e((string) $c['serial']) . ' · valid to ' . e(ds_fmt_date($c['not_after'])) . '</small><small class="mono">' . e(substr($c['fingerprint'], 0, 40)) . '…</small></div>'
              . ds_button('Remove', ['action' => 'uncert', 'person_id' => $pid, 'cert_id' => $c['id']], 'ds-btn ds-btn-ghost ds-btn-sm', 'Remove this certificate?') . '</div>';
    }
    $dlg .= ds_form('class="ds-inlineform"', true) . '<input type="hidden" name="action" value="cert"><input type="hidden" name="person_id" value="' . $pid . '"><input type="file" name="cert" accept=".cer,.crt,.pem,.der" required aria-label="Certificate file">'
          . '<button class="ds-btn ds-btn-sm">' . ds_icon('upload') . 'Register DSC certificate</button></form><p class="ds-hint">Upload the public certificate only (.cer). Private keys stay on the token and never come here.</p></div></dialog>';
    $GLOBALS['ds_dialogs'][] = $dlg;
}
echo '</tbody></table></div></div><datalist id="ds-auths">';
foreach (array_unique(array_filter(array_map(static fn($p) => (string) $p['authority'], $profiles))) as $a) {
    echo '<option value="' . e($a) . '">';
}
foreach (['Department Head', 'Manager', 'Finance Controller', 'CEO', 'Director', 'Hub Head', 'Spoke Head'] as $a) {
    echo '<option value="' . e($a) . '">';
}
echo '</datalist>' . implode('', $GLOBALS['ds_dialogs'] ?? []);
ds_layout_end();

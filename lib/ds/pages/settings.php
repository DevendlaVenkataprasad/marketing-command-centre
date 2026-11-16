<?php
/** Settings: general rules, security, departments and units, notifications, and API access. */

$flash = null;
$newToken = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    if ($a === 'token') {
        try {
            $label = ds_str($_POST['label'] ?? '', 120, true, 'A name');
            $scopes = array_values(array_intersect((array) ($_POST['scopes'] ?? []), ['read', 'write', 'verify']));
            $raw = 'dsk_' . bin2hex(random_bytes(24));
            $days = max(1, min(730, (int) ($_POST['days'] ?? 90)));
            $id = fabo_exec('INSERT INTO ds_api_tokens (org_id, person_id, label, prefix, token_hash, scopes, expires_at) VALUES (?,?,?,?,?,?, DATE_ADD(NOW(), INTERVAL ? DAY))', 'iissssi',
                            [ds_org(), ds_me(), $label, substr($raw, 0, 8), hash('sha256', $raw), implode(',', $scopes ?: ['read']), $days]);
            ds_audit(null, 'api', $id, 'token_create', 'Created API token "' . $label . '" (' . implode(', ', $scopes ?: ['read']) . ', ' . $days . ' days)');
            $newToken = $raw;
            $flash = ['ok', 'Token created. Copy it now — it is shown only once.'];
        } catch (InvalidArgumentException $e) {
            $flash = ['err', $e->getMessage()];
        }
    } else {
        $flash = ds_action(static function () use ($a) {
            switch ($a) {
                case 'general':
                    $s = [
                        'ref_prefix' => preg_replace('/[^A-Z0-9-]/', '', strtoupper((string) ($_POST['ref_prefix'] ?? 'DS'))) ?: 'DS',
                        'members_create' => !empty($_POST['members_create']) ? '1' : '0', 'mail_notify' => !empty($_POST['mail_notify']) ? '1' : '0',
                        'deadline_days' => (string) max(1, min(365, (int) ($_POST['deadline_days'] ?? 7))), 'reminder_hours' => (string) max(1, min(720, (int) ($_POST['reminder_hours'] ?? 24))),
                        'deadline_warn_hours' => (string) max(1, min(240, (int) ($_POST['deadline_warn_hours'] ?? 24))), 'max_upload_mb' => (string) max(1, min(100, (int) ($_POST['max_upload_mb'] ?? 25))),
                        'require_trusted' => !empty($_POST['require_trusted']) ? '1' : '0', 'stamp_approvals' => !empty($_POST['stamp_approvals']) ? '1' : '0',
                        'public_verify' => !empty($_POST['public_verify']) ? '1' : '0', 'expire_on_deadline' => !empty($_POST['expire_on_deadline']) ? '1' : '0',
                        'dsc_identity' => ds_pick($_POST['dsc_identity'] ?? '', ['registered' => 1, 'registered_or_identity' => 1, 'any' => 1], 'DSC identity rule'),
                        'esign_name_match' => ($_POST['esign_name_match'] ?? 'warn') === 'block' ? 'block' : 'warn',
                        'subfilter' => ($_POST['subfilter'] ?? '') === 'ETSI.CAdES.detached' ? 'ETSI.CAdES.detached' : 'adbe.pkcs7.detached',
                        'clamav_cmd' => preg_match('~^(/[\w./-]+)?$~', trim((string) ($_POST['clamav_cmd'] ?? ''))) ? trim((string) $_POST['clamav_cmd']) : '',
                    ];
                    foreach ($s as $k => $v) {
                        ds_setting_put($k, $v);
                    }
                    ds_audit(null, 'settings', null, 'settings', 'Changed Digital Signatures settings', $s);
                    return 'Settings saved.';
                case 'dept':
                    ds_need('settings.manage');
                    $id = ds_department_add((string) ($_POST['name'] ?? ''));
                    ds_audit(null, 'department', $id, 'dept_add', 'Added department "' . ds_dept_name($id) . '"');
                    return 'Department added.';
                case 'revoke_token':
                    fabo_exec('UPDATE ds_api_tokens SET revoked_at = NOW() WHERE id = ? AND org_id = ?', 'ii', [(int) ($_POST['id'] ?? 0), ds_org()]);
                    ds_audit(null, 'api', (int) ($_POST['id'] ?? 0), 'token_revoke', 'Revoked an API token');
                    return 'Token revoked.';
                case 'test_mail':
                    ds_notify([ds_me()], 'test', 'Test notification from Digital Signatures', 'Notifications reach you in the portal' . (ds_setting('mail_notify') === '1' ? ' and by e-mail.' : '.'), null);
                    return 'A test notification was sent to you.';
                case 'run_jobs':
                    $r = ds_cron_org();
                    return 'Background jobs ran: ' . implode(', ', array_map(static fn($k, $v) => $k . ' ' . (is_int($v) ? $v : $v), array_keys($r), $r)) . '.';
            }
            throw new InvalidArgumentException('Unknown action.');
        }, ds_url('settings'));
    }
}
$chk = static fn(string $k) => ds_setting($k) === '1' ? ' checked' : '';
$tab = (string) ($_GET['tab'] ?? 'general');
ds_layout_start('Settings', 'How Digital Signatures behaves for ' . e(ds_org_name()) . '.', '', ['Administration' => ds_url('admin'), 'Settings' => null], $flash);
echo '<nav class="ds-tabs" aria-label="Settings">';
foreach (['general' => 'General & security', 'org' => 'Departments & units', 'notify' => 'Notifications', 'api' => 'API access', 'system' => 'System'] as $k => $l) {
    echo '<a href="' . e(ds_url('settings', ['tab' => $k])) . '"' . ($k === $tab ? ' class="on" aria-current="page"' : '') . '>' . e($l) . '</a>';
}
echo '</nav>';

if ($tab === 'general') {
    echo ds_form() . '<input type="hidden" name="action" value="general"><div class="ds-grid g-2"><section class="ds-card ds-rise"><div class="ds-card-h"><h2>' . ds_icon('files') . 'Documents</h2></div><div class="ds-card-b ds-formgrid one">'
       . ds_field('Reference prefix', '<input name="ref_prefix" maxlength="10" value="' . e(ds_setting('ref_prefix')) . '">', 'References look like ' . e(ds_setting('ref_prefix')) . '/26-27/000001.')
       . ds_field('Largest upload (MB)', '<input type="number" name="max_upload_mb" min="1" max="100" value="' . e(ds_setting('max_upload_mb')) . '">', 'The server accepts ' . e(ini_get('upload_max_filesize')) . ' per file.')
       . ds_field('Days each signatory has (default)', '<input type="number" name="deadline_days" min="1" max="365" value="' . e(ds_setting('deadline_days')) . '">')
       . ds_field('Remind every (hours, default)', '<input type="number" name="reminder_hours" min="1" max="720" value="' . e(ds_setting('reminder_hours')) . '">')
       . ds_field('Warn before a deadline (hours)', '<input type="number" name="deadline_warn_hours" min="1" max="240" value="' . e(ds_setting('deadline_warn_hours')) . '">')
       . '<label class="ds-check"><input type="checkbox" name="members_create" value="1"' . $chk('members_create') . '> Everyone in the organisation may create documents (otherwise Document Creators only)</label>'
       . '<label class="ds-check"><input type="checkbox" name="stamp_approvals" value="1"' . $chk('stamp_approvals') . '> Stamp electronic approvals on the PDF</label>'
       . '<label class="ds-check"><input type="checkbox" name="expire_on_deadline" value="1"' . $chk('expire_on_deadline') . '> Expire documents not signed by their signing deadline</label>'
       . '<label class="ds-check"><input type="checkbox" name="public_verify" value="1"' . $chk('public_verify') . '> Allow verification by tracking code on the public page (status and signers only)</label></div></section>'
       . '<section class="ds-card ds-rise"><div class="ds-card-h"><h2>' . ds_icon('shield') . 'Signatures &amp; security</h2></div><div class="ds-card-b ds-formgrid one">'
       . '<label class="ds-check"><input type="checkbox" name="require_trusted" value="1"' . $chk('require_trusted') . '> Accept digital signatures only from certificates chaining to the trust store (recommended; required for legal validity)</label>'
       . ds_field('DSC identity rule', ds_select('dsc_identity', ['registered' => 'Only certificates registered to the signatory', 'registered_or_identity' => 'Registered, or e-mail / name matching the signatory', 'any' => 'Any valid certificate (record only)'], ds_setting('dsc_identity')))
       . ds_field('eSign certificate name differs from the signatory', ds_select('esign_name_match', ['warn' => 'Accept and flag it', 'block' => 'Refuse the signature'], ds_setting('esign_name_match') ?: 'warn'))
       . ds_field('PDF signature format', ds_select('subfilter', ['adbe.pkcs7.detached' => 'adbe.pkcs7.detached (widest compatibility)', 'ETSI.CAdES.detached' => 'ETSI.CAdES.detached (PAdES baseline, if the ESP supports it)'], ds_setting('subfilter')))
       . ds_field('Virus scanner command', '<input name="clamav_cmd" class="mono" value="' . e(ds_setting('clamav_cmd')) . '" placeholder="/usr/bin/clamdscan">', 'Optional. Uploads are always checked for active PDF content; ClamAV is added when installed.')
       . '<div class="ds-legal">' . ds_icon('lock') . '<p>Files are encrypted with AES-256-GCM. The key is kept outside the web folder at <span class="mono">' . e(ds_key_file()) . '</span>' . (getenv('DS_STORAGE_KEY') ? ' (overridden by DS_STORAGE_KEY)' : '')
       . '. <strong>Back it up with the database</strong>: documents cannot be read without it.</p></div></div></section></div>'
       . '<div class="ds-savebar"><button class="ds-btn ds-btn-primary">Save settings</button></div></form>';
}
if ($tab === 'org') {
    echo '<div class="ds-grid g-2">' . ds_card_start('Departments', '', '', 'building') . '<p class="ds-muted small">The same list HR uses — a department added here appears there too.</p><div class="ds-chips">';
    foreach (ds_departments() as $id => $n) {
        $c = (int) fabo_one('SELECT COUNT(*) n FROM ds_documents WHERE org_id = ? AND department_id = ? AND deleted_at IS NULL', 'ii', [ds_org(), $id])['n'];
        echo '<span class="ds-person">' . ds_icon('building') . e($n) . ' <small>' . $c . '</small></span>';
    }
    echo (ds_departments() ? '' : '<span class="ds-muted">None yet</span>') . '</div>' . ds_form('class="ds-inlineform"') . '<input type="hidden" name="action" value="dept"><input name="name" required maxlength="120" placeholder="New department">'
       . '<button class="ds-btn ds-btn-sm">' . ds_icon('plus') . 'Add</button></form>' . ds_card_end();
    echo ds_card_start('Organisational units', '', '', 'flow') . '<p class="ds-muted small">The organisation\'s Hub / Spoke list (Sheet Management → Lists). The first is the hub.</p><ol class="ds-units">';
    foreach (ds_units() as $i => $u) {
        echo '<li>' . ds_icon($i === 0 ? 'building' : 'dot') . e($u) . ($i === 0 ? ' ' . ds_badge('Hub', 'blue', false) : ' ' . ds_badge('Spoke', 'grey', false)) . '</li>';
    }
    echo '</ol>' . (ds_units() ? '' : '<p class="ds-muted">No units listed.</p>') . ds_card_end() . '</div>';
}
if ($tab === 'notify') {
    echo ds_form() . '<input type="hidden" name="action" value="general">';
    foreach (ds_setting_defaults() as $k => $d) {
        if (!in_array($k, ['mail_notify', 'perm_matrix', 'last_cron'], true)) {
            echo '<input type="hidden" name="' . e($k) . '" value="' . e(ds_setting($k)) . '">';
        }
    }
    echo '<div class="ds-card ds-rise"><div class="ds-card-b"><label class="ds-check"><input type="checkbox" name="mail_notify" value="1"' . $chk('mail_notify') . '> E-mail people as well as notifying them in the portal</label>'
       . '<p class="ds-muted small">Sent when a document is submitted, a signature is requested, signed, rejected, changes are requested, a deadline is near, a document expires, and when a workflow completes; plus reminders and escalations at each workflow\'s interval.</p>'
       . '</div><div class="ds-card-f"><button class="ds-btn ds-btn-primary">Save</button></div></div></form>'
       . ds_button('Send me a test notification', ['action' => 'test_mail'], 'ds-btn ds-btn-ghost', '', 'bell');
    $log = fabo_all('SELECT * FROM ds_notifications WHERE org_id = ? ORDER BY id DESC LIMIT 100', 'i', [ds_org()]);
    echo ds_card_start('Recent notifications', '', '', 'bell') . '<div class="ds-tablewrap"><table class="ds-table compact"><thead><tr><th>When</th><th>To</th><th>Event</th><th>Channel</th><th>Subject</th><th>Status</th></tr></thead><tbody>';
    foreach ($log as $n) {
        echo '<tr><td>' . e(ds_fmt_dt($n['created_at'])) . '</td><td>' . e(ds_person_name((int) $n['person_id'])) . '</td><td>' . e($n['event']) . '</td><td>' . e($n['channel']) . '</td><td>' . e($n['subject']) . '</td><td>'
           . ($n['status'] === 'sent' ? ds_badge('Sent', 'green') : ds_badge(ucfirst($n['status']), 'red')) . ($n['error'] ? ' <small>' . e($n['error']) . '</small>' : '') . '</td></tr>';
    }
    echo '</tbody></table></div>' . ds_card_end();
}
if ($tab === 'api') {
    if ($newToken) {
        echo '<div class="ds-note ok">' . ds_icon('key') . '<span>Your new token: <code class="ds-copy mono" data-copy="' . e($newToken) . '">' . e($newToken) . '</code> — copy it now.</span></div>';
    }
    $base = ds_abs_url('api/sign.php');
    echo '<div class="ds-grid g-2-1">' . ds_card_start('API tokens', '', '', 'api') . '<ul class="ds-list">';
    foreach (fabo_all('SELECT * FROM ds_api_tokens WHERE org_id = ? ORDER BY id DESC', 'i', [ds_org()]) as $t) {
        $live = !$t['revoked_at'] && (!$t['expires_at'] || ds_ts($t['expires_at']) > time());
        echo '<li><span class="ds-li-x"><span class="ds-fileic">' . ds_icon('key') . '</span><span class="ds-li-b"><strong>' . e($t['label']) . ' <span class="mono">' . e($t['prefix']) . '…</span></strong><small>'
           . e($t['scopes']) . ' · ' . e(ds_person_name((int) $t['person_id'])) . ' · expires ' . e(ds_fmt_date($t['expires_at'])) . ' · last used ' . e($t['last_used_at'] ? ds_ago($t['last_used_at']) : 'never') . '</small></span>'
           . ($live ? ds_badge('Active', 'green') . ds_button('Revoke', ['action' => 'revoke_token', 'id' => $t['id']], 'ds-btn ds-btn-ghost ds-btn-sm danger', 'Revoke this token?') : ds_badge('Revoked / expired', 'grey')) . '</span></li>';
    }
    echo '</ul>' . ds_form('class="ds-inlineform"') . '<input type="hidden" name="action" value="token"><input name="label" required maxlength="120" placeholder="Token name (e.g. ERP integration)">'
       . '<label class="ds-check"><input type="checkbox" name="scopes[]" value="read" checked> read</label><label class="ds-check"><input type="checkbox" name="scopes[]" value="write"> write</label><label class="ds-check"><input type="checkbox" name="scopes[]" value="verify" checked> verify</label>'
       . '<input type="number" name="days" value="90" min="1" max="730" class="ds-num" aria-label="Days valid"><button class="ds-btn ds-btn-sm">' . ds_icon('plus') . 'Create</button></form>'
       . '<p class="ds-hint">A token acts as you, within your permissions. Only its hash is stored.</p>' . ds_card_end()
       . ds_card_start('Using the API', '', '', 'api') . '<p class="small">Base URL <code class="mono">' . e($base) . '</code></p><pre class="ds-code">curl -H "Authorization: Bearer dsk_…" \\
  "' . e($base) . '?r=documents&amp;status=pending"</pre><p class="small">Resources: <code>documents</code>, <code>document</code>, <code>requests</code>, <code>workflows</code>, <code>signatories</code>, <code>verify</code>, <code>audit</code>, '
       . '<code>reports</code>, <code>notifications</code>. Full reference: <span class="mono">docs/DIGITAL-SIGNATURES.md</span>.</p>' . ds_card_end() . '</div>';
}
if ($tab === 'system') {
    $chain = ds_can('audit.view') ? ds_audit_check() : null;
    $files = fabo_one('SELECT COUNT(*) n, COALESCE(SUM(size),0) s FROM ds_files WHERE org_id = ? AND deleted_at IS NULL', 'i', [ds_org()]);
    echo '<div class="ds-grid g-2">' . ds_card_start('Health', '', '', 'gauge') . '<ul class="ds-ready">'
       . '<li class="ok">' . ds_icon('check-circle') . 'Schema version ' . DS_SCHEMA_VERSION . '</li>'
       . '<li class="ok">' . ds_icon('lock') . number_format((int) $files['n']) . ' encrypted files · ' . e(fabo_human_size((int) $files['s'])) . '</li>'
       . ($chain ? '<li class="' . ($chain[0] ? 'ok' : 'no') . '">' . ds_icon($chain[0] ? 'shield-check' : 'alert') . e($chain[3]) . '</li>' : '')
       . '<li class="' . (function_exists('openssl_cms_verify') ? 'ok' : 'no') . '">' . ds_icon('key') . 'OpenSSL ' . e(OPENSSL_VERSION_TEXT) . '</li>'
       . '<li class="' . (ds_setting('last_cron') && strtotime(ds_setting('last_cron')) > time() - 7200 ? 'ok' : 'no') . '">' . ds_icon('clock') . 'Background jobs last ran ' . e(ds_setting('last_cron') ? ds_ago(ds_setting('last_cron')) : 'never') . '</li>'
       . '</ul>' . ds_button('Run background jobs now', ['action' => 'run_jobs'], 'ds-btn ds-btn-ghost ds-btn-sm', '', 'refresh') . ds_card_end()
       . ds_card_start('Backup & recovery', '', '', 'history') . '<p class="small">Back up together: the database (all <span class="mono">ds_*</span> tables), the folder <span class="mono">uploads/ds/</span>, and the key file <span class="mono">' . e(ds_key_file()) . '</span>. '
       . 'Restoring the files without the key, or the key without the files, recovers nothing.</p><p class="small">Reminders, escalations and expiry run hourly when the module is opened; schedule <span class="mono">cron.php</span> every 5 minutes for them to run on their own.</p>' . ds_card_end() . '</div>';
}
ds_layout_end();

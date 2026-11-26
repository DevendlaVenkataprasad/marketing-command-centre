<?php
/**
 * Academic desk — Privacy & security (Phase 5). Loaded by lib/edu/app.php.
 *
 *   ?tab=requests   correction and erasure requests to act on
 *   ?tab=consent    the privacy notice, the grievance contact, guardian consent, who has agreed
 *   ?tab=security   two-step sign-in and the security events log
 *   ?tab=access     who opened or exported whose records
 *   ?tab=retention  accounts nobody uses, to review (never removed automatically)
 */

require_once __DIR__ . '/privacy.php';

function edu_post_privacy(int $org, string $a): void
{
    $id = (int) ($_POST['id'] ?? 0);
    switch ($a) {
        case 'request-decide':
            $st = (string) ($_POST['status'] ?? '');
            edu_request_decide($org, $id, $st, edu_actor(), (string) ($_POST['staff_note'] ?? ''));
            edu_audit($org, edu_actor(), 'request-' . $st, (string) $id);
            edu_flash('ok', $st === 'done' ? 'Done, and the person has been emailed.' : 'Declined, and the person has been told why.');
            edu_go('privacy');
        case 'notice-save':
            $text = trim((string) ($_POST['notice'] ?? ''));
            edu_set($org, 'privacy_notice', $text === trim(edu_notice_default($org)) ? '' : mb_substr($text, 0, 20000));
            edu_set($org, 'grievance_contact', mb_substr(trim((string) ($_POST['grievance'] ?? '')), 0, 600));
            edu_set($org, 'guardian_required', !empty($_POST['guardian_required']) ? '1' : '0');
            edu_audit($org, edu_actor(), 'notice-save', edu_notice_version($org));
            edu_flash('ok', 'Saved. Anyone who agreed to an earlier version is asked again at their next visit.');
            edu_go('privacy', ['tab' => 'consent']);
        case 'mfa-save':
            $m = (string) ($_POST['mfa_mode'] ?? 'admins');
            edu_set($org, 'mfa_mode', in_array($m, ['admins', 'everyone', 'off'], true) ? $m : 'admins');
            edu_audit($org, edu_actor(), 'mfa-mode', $m);
            edu_flash('ok', 'Saved. It applies from each person\'s next sign-in.');
            edu_go('privacy', ['tab' => 'security']);
        case 'retention-erase':
            if (($_POST['confirm'] ?? '') !== 'ERASE') {
                throw new InvalidArgumentException('Type ERASE to confirm.');
            }
            $n = 0;
            foreach (array_map('intval', (array) ($_POST['ids'] ?? [])) as $pid) {
                $p = edu_person($org, $pid);
                if ($p) {
                    edu_person_delete($org, $pid);
                    edu_seclog($org, $pid, 'erased', 'retention review by ' . edu_actor());
                    $n++;
                }
            }
            edu_audit($org, edu_actor(), 'retention-erase', (string) $n);
            edu_flash('ok', $n . ' account' . ($n === 1 ? '' : 's') . ' erased.');
            edu_go('privacy', ['tab' => 'retention']);
    }
}

function edu_page_privacy(int $org): void
{
    edu_privacy_trim();
    $tab = in_array($_GET['tab'] ?? '', ['requests', 'consent', 'security', 'access', 'retention'], true) ? $_GET['tab'] : 'requests';
    $open = count(edu_requests($org));
    $ct = edu_consent_totals($org);
    echo '<section class="edu-kpis">' . edu_kpi('Requests to act on', $open, 't-acc', edu_url('privacy')) . edu_kpi('Accounts set up', $ct['people'] ?? 0, 't-student')
       . edu_kpi('Agreed to the current notice', $ct['current'] ?? 0, 't-faculty') . edu_kpi('Under 18', $ct['minors'] ?? 0, 't-mentor')
       . edu_kpi('Guardian agreed', $ct['guardian_ok'] ?? 0, 't-inst') . edu_kpi('Two-step sign-in', ['admins' => 'Admins', 'everyone' => 'Everyone', 'off' => 'Off'][edu_mfa_mode($org)], 't-recent', edu_url('privacy', ['tab' => 'security'])) . '</section>'
       . '<nav class="edu-tabs">';
    foreach (['requests' => 'Requests' . ($open ? ' (' . $open . ')' : ''), 'consent' => 'Privacy notice & consent', 'security' => 'Security', 'access' => 'Access log', 'retention' => 'Retention review'] as $k => $l) {
        echo '<a class="' . ($tab === $k ? 'on' : '') . '" href="' . e(edu_url('privacy', ['tab' => $k])) . '">' . e($l) . '</a>';
    }
    echo '</nav>';
    ('edu_privacy_tab_' . $tab)($org);
}

function edu_privacy_tab_requests(int $org): void
{
    $edit = edu_can_edit();
    $rows = edu_requests($org, false);
    echo '<section class="edu-card"><p class="muted">People ask from Your profile → Your data in the Campus portal. Act within a reasonable time and say why if you decline; the person is emailed either way. '
       . 'Erasing removes the account with its courses, rewards, sessions, ideas and consents; only a masked line stays here as the record that it was done.</p>'
       . '<div class="edu-tablewrap"><table class="edu-table"><thead><tr><th>When</th><th>Who</th><th>Asks to</th><th>Details</th><th>Status</th>' . ($edit ? '<th></th>' : '') . '</tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr><td>' . e(date('d M Y', strtotime($r['created_at']))) . '</td><td>' . ($r['status'] === 'open' && edu_person($org, (int) $r['person_id'])
                ? '<a href="' . e(edu_url('person', ['id' => (int) $r['person_id']])) . '">' . e($r['who']) . '</a>' : e($r['who'])) . '</td>'
           . '<td>' . ($r['kind'] === 'erase' ? '<span class="pill red">Erase account</span>' : '<span class="pill amber">Correct record</span>') . '</td><td>' . nl2br(e((string) $r['details'])) . '</td>'
           . '<td>' . ['open' => '<span class="pill amber">Waiting</span>', 'done' => '<span class="pill green">Done</span>', 'rejected' => '<span class="pill grey">Declined</span>'][$r['status']]
           . ($r['staff_note'] !== '' ? '<br><span class="muted">' . e($r['staff_note']) . '</span>' : '') . ($r['decided_by'] !== '' ? '<br><span class="muted">' . e($r['decided_by']) . ', ' . e(fabo_short_date($r['decided_at'])) . '</span>' : '') . '</td>';
        if ($edit) {
            echo '<td class="act">' . ($r['status'] === 'open'
                ? '<form method="post" class="edu-orderform">' . fabo_csrf_field() . '<input type="hidden" name="a" value="request-decide"><input type="hidden" name="id" value="' . (int) $r['id'] . '">'
                  . '<input name="staff_note" maxlength="500" placeholder="Note to the person (needed to decline)" aria-label="Note">'
                  . '<button class="btn btn-sm ' . ($r['kind'] === 'erase' ? 'btn-danger' : 'btn-approve') . '" name="status" value="done"' . ($r['kind'] === 'erase' ? ' data-confirm-btn="Erase this account and everything held with it? This cannot be undone."' : '') . '>' . ($r['kind'] === 'erase' ? 'Erase now' : 'Corrected') . '</button>'
                  . '<button class="btn btn-sm" name="status" value="rejected">Decline</button></form>' : '') . '</td>';
        }
        echo '</tr>';
    }
    if (!$rows) {
        echo '<tr><td colspan="6" class="edu-empty">No requests yet.</td></tr>';
    }
    echo '</tbody></table></div></section>';
}

function edu_privacy_tab_consent(int $org): void
{
    $edit = edu_can_edit();
    $dis = $edit ? '' : ' disabled';
    echo '<form method="post" class="edu-card edu-form">' . fabo_csrf_field() . '<input type="hidden" name="a" value="notice-save"><h2>Privacy notice</h2>'
       . '<p class="muted">Everyone agrees to this before the Campus portal opens; change it and they are asked again. It starts from a plain-language template written with the DPDP Act, 2023 in mind — have your own counsel check it before real students use the portal. '
       . 'Current version <code>' . e(edu_notice_version($org)) . '</code> · <a href="' . e(edu_portal_url($org, ['v' => 'privacy'])) . '" target="_blank" rel="noopener">See it as people do</a></p>'
       . '<label class="field"><span>Notice text (# Heading, - list, **bold**)</span><textarea name="notice" rows="16"' . $dis . '>' . e(edu_notice_text($org)) . '</textarea></label>'
       . '<label class="field"><span>Grievance officer: name, email, phone (shown under the notice)</span><textarea name="grievance" rows="2" maxlength="600"' . $dis . '>' . e(edu_get($org, 'grievance_contact', '')) . '</textarea></label>'
       . '<label class="edu-check"><input type="checkbox" name="guardian_required" value="1"' . (edu_guardian_required($org) ? ' checked' : '') . $dis . '> A student under 18 can use the portal only after a parent or guardian agrees by email (recommended)</label>'
       . ($edit ? '<button class="btn btn-primary">Save</button>' : '') . '</form>';
    $rows = fabo_all('SELECT c.*, p.name, p.email FROM edu_consents c JOIN edu_people p ON p.id = c.person_id
                       WHERE c.org_id = ? AND c.id = (SELECT MAX(id) FROM edu_consents x WHERE x.person_id = c.person_id) ORDER BY c.accepted_at DESC LIMIT 200', 'i', [$org]);
    echo '<section class="edu-card"><h2>Who has agreed <span class="muted">(latest 200)</span></h2><div class="edu-tablewrap"><table class="edu-table"><thead><tr><th>Person</th><th>Agreed</th><th>Version</th><th>Under 18</th><th>Guardian</th></tr></thead><tbody>';
    $v = edu_notice_version($org);
    foreach ($rows as $r) {
        echo '<tr><td><a href="' . e(edu_url('person', ['id' => (int) $r['person_id']])) . '">' . e($r['name']) . '</a></td><td>' . e(date('d M Y, H:i', strtotime($r['accepted_at']))) . '</td>'
           . '<td>' . ($r['withdrawn_at'] ? '<span class="pill grey">Withdrawn</span>' : ($r['notice_version'] === $v ? '<span class="pill green">Current</span>' : '<span class="pill amber">Older</span>')) . '</td>'
           . '<td>' . ((int) $r['minor'] ? 'Yes' : '') . '</td><td>' . ((int) $r['minor'] ? e((string) $r['guardian_name']) . ' · ' . ($r['guardian_at'] ? '<span class="pill green">Agreed</span>' : '<span class="pill amber">Waiting</span>') : '') . '</td></tr>';
    }
    if (!$rows) {
        echo '<tr><td colspan="5" class="edu-empty">Nobody has signed in since the notice was added.</td></tr>';
    }
    echo '</tbody></table></div></section>';
}

function edu_privacy_tab_security(int $org): void
{
    $edit = edu_can_edit();
    $mode = edu_mfa_mode($org);
    echo '<form method="post" class="edu-card edu-form">' . fabo_csrf_field() . '<input type="hidden" name="a" value="mfa-save"><h2>Two-step sign-in</h2>'
       . '<p class="muted">After the password, a 6-digit code is emailed and must be typed in. Sessions also end on their own: after 30 minutes idle (12 hours at most) for institution admins and council, after 8 hours idle (7 days at most) for everyone else.</p><fieldset class="edu-switches">';
    foreach (['admins' => ['Institution admins and council', 'They see many people\'s records. Recommended.'], 'everyone' => ['Everyone', 'Every sign-in asks for a code.'], 'off' => ['Off', 'Password only. Not recommended once real students use the portal.']] as $k => [$l, $d]) {
        echo '<label class="edu-switch"><input type="radio" name="mfa_mode" value="' . $k . '"' . ($mode === $k ? ' checked' : '') . ($edit ? '' : ' disabled') . '><span><strong>' . e($l) . '</strong><em>' . e($d) . '</em></span></label>';
    }
    echo '</fieldset><p class="muted">Sample people (.example addresses) never get a code, because those addresses cannot receive mail.</p>' . ($edit ? '<button class="btn btn-primary">Save</button>' : '') . '</form>';
    $rows = fabo_all('SELECT s.*, p.name FROM edu_security_log s LEFT JOIN edu_people p ON p.id = s.person_id WHERE s.org_id = ? ORDER BY s.id DESC LIMIT 150', 'i', [$org]);
    $fails = (int) (fabo_one('SELECT COUNT(*) AS n FROM edu_security_log WHERE org_id = ? AND event IN ("login_fail","code_fail","code_locked") AND created_at >= ?', 'is', [$org, edu_now(-86400)])['n'] ?? 0);
    echo '<section class="edu-card"><div class="edu-card-h"><h2>Security events</h2><span class="muted">' . $fails . ' failed attempt' . ($fails === 1 ? '' : 's') . ' in the last 24 hours · kept for a year</span></div>'
       . '<div class="edu-tablewrap"><table class="edu-table"><thead><tr><th>When</th><th>Who</th><th>What</th><th>Detail</th><th>From</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $bad = in_array($r['event'], ['login_fail', 'code_fail', 'code_locked'], true);
        echo '<tr><td>' . e(date('d M, H:i', strtotime($r['created_at']))) . '</td><td>' . e((string) ($r['name'] ?? ($r['person_id'] ? '#' . $r['person_id'] : '—'))) . '</td>'
           . '<td>' . ($bad ? '<span class="pill red">' . e(edu_seclog_words($r['event'])) . '</span>' : e(edu_seclog_words($r['event']))) . '</td><td class="muted">' . e($r['detail']) . '</td><td class="muted">' . e((string) $r['ip']) . '</td></tr>';
    }
    if (!$rows) {
        echo '<tr><td colspan="5" class="edu-empty">Nothing yet.</td></tr>';
    }
    echo '</tbody></table></div></section>';
}

function edu_privacy_tab_access(int $org): void
{
    $rows = fabo_all('SELECT a.*, p.name FROM edu_access_log a LEFT JOIN edu_people p ON p.id = a.person_id WHERE a.org_id = ? ORDER BY a.id DESC LIMIT 200', 'i', [$org]);
    echo '<section class="edu-card"><p class="muted">Each time staff open a person\'s page or export a list or report, and each page an institution admin or council member opens in the Campus portal. Kept for three years.</p>'
       . '<div class="edu-tablewrap"><table class="edu-table"><thead><tr><th>When</th><th>Who</th><th>Did</th><th>Whose record</th><th>Detail</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr><td>' . e(date('d M, H:i', strtotime($r['created_at']))) . '</td><td>' . e($r['actor']) . '</td><td>' . e(['view' => 'Opened', 'export' => 'Exported'][$r['action']] ?? $r['action']) . '</td>'
           . '<td>' . ($r['person_id'] ? e((string) ($r['name'] ?? '#' . $r['person_id'] . ' (erased)')) : '<span class="muted">—</span>') . '</td><td class="muted">' . e($r['detail']) . '</td></tr>';
    }
    if (!$rows) {
        echo '<tr><td colspan="5" class="edu-empty">Nothing yet.</td></tr>';
    }
    echo '</tbody></table></div></section>';
}

function edu_privacy_tab_retention(int $org): void
{
    $months = max(6, min(120, (int) ($_GET['months'] ?? 24)));
    $rows = edu_retention_review($org, $months);
    echo '<section class="edu-card"><form method="get" class="edu-inline">' . (!empty($GLOBALS['edu_viewer']['is_admin']) ? '<input type="hidden" name="org" value="' . $org . '">' : '')
       . '<input type="hidden" name="p" value="privacy"><input type="hidden" name="tab" value="retention"><label>Not used for <input type="number" name="months" min="6" max="120" value="' . $months . '" class="edu-num"> months, or suspended</label><button class="btn">Show</button></form>'
       . '<p class="muted">Personal data should not be kept longer than it is needed. Nothing is removed automatically: review these, check with the institution, then erase the ones that are no longer needed.</p>';
    $edit = edu_can_edit();
    echo '<form method="post">' . fabo_csrf_field() . '<input type="hidden" name="a" value="retention-erase"><div class="edu-tablewrap"><table class="edu-table"><thead><tr>' . ($edit ? '<th></th>' : '') . '<th>Person</th><th>Roles</th><th>Status</th><th>Last sign-in</th><th>Added</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr>' . ($edit ? '<td><input type="checkbox" name="ids[]" value="' . (int) $r['id'] . '" aria-label="Select ' . e($r['name']) . '"></td>' : '')
           . '<td><a href="' . e(edu_url('person', ['id' => (int) $r['id']])) . '">' . e($r['name']) . '</a><br><span class="muted">' . e((string) $r['email']) . '</span></td>'
           . '<td>' . e(implode(', ', array_map('edu_role_label', array_filter(explode(',', (string) $r['role_list']))))) . '</td><td>' . ($r['status'] === 'suspended' ? '<span class="pill red">Suspended</span>' : 'Active') . '</td>'
           . '<td>' . ($r['last_login_at'] ? e(fabo_short_date($r['last_login_at'])) : 'Never') . '</td><td>' . e(fabo_short_date($r['created_at'])) . '</td></tr>';
    }
    if (!$rows) {
        echo '<tr><td colspan="6" class="edu-empty">Nobody to review.</td></tr>';
    }
    echo '</tbody></table></div>';
    if ($edit && $rows) {
        echo '<div class="edu-inline"><input name="confirm" placeholder="Type ERASE" aria-label="Type ERASE to confirm" autocomplete="off"><button class="btn btn-danger">Erase the ticked accounts</button></div>';
    }
    echo '</form></section>';
}

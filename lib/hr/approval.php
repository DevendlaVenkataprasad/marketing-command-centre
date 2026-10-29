<?php
/**
 * Interview assessments — the CEO's approval and the offer letter.
 *
 *   HR names the CEO once (name, email, designation) on the CEO approval
 *   tab. HR then sends assessed candidates for approval; the CEO is emailed
 *   a private link (ceo-approval.php?t=…) to a page listing every candidate
 *   waiting, with the interview scores, each sheet and the résumé. The CEO
 *   approves, or rejects with remarks (required). HR is told either way.
 *
 *   Only an approved candidate can be given an offer letter, and only HR can
 *   send it: the letter is made as a PDF, kept, and the candidate is emailed
 *   a private link to download it (offer-letter.php?t=…).
 *
 * The CEO's page has no HR context, so the functions it calls take the org
 * explicitly.
 */

function hr_approval_statuses(): array
{
    return ['pending' => 'Waiting for CEO', 'approved' => 'Approved by CEO', 'rejected' => 'Rejected by CEO'];
}

function hr_approval_tone(?string $s): string
{
    return ['pending' => 'amber', 'approved' => 'green', 'rejected' => 'red'][$s] ?? 'grey';
}

/* ------------------------------------------------------------------- CEO */

/** The CEO set for an organisation: name, email, designation, token (empty strings when not set). */
function hr_ceo(?int $org = null): array
{
    $org ??= hr_org();
    $out = [];
    foreach (['name', 'email', 'designation', 'token'] as $k) {
        $out[$k] = (string) (fabo_one('SELECT v FROM hr_settings WHERE org_id = ? AND k = ?', 'is', [$org, 'assess_ceo_' . $k])['v'] ?? '');
    }
    $out['org'] = $org;
    return $out;
}

function hr_ceo_put(string $k, string $v): void
{
    fabo_exec('INSERT INTO hr_settings (org_id, k, v, updated_by) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE v = VALUES(v), updated_by = VALUES(updated_by)',
              'isss', [hr_org(), 'assess_ceo_' . $k, $v, hr_actor()]);
}

function hr_ceo_save(array $p): void
{
    $email = mb_strtolower(trim((string) ($p['email'] ?? '')));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Give the CEO\'s email address — the approval link is sent there.');
    }
    $was = hr_ceo();
    hr_ceo_put('name', hr_str($p['name'] ?? '', 160, true, 'CEO name'));
    hr_ceo_put('email', $email);
    hr_ceo_put('designation', hr_str($p['designation'] ?? '', 160) ?: 'Chief Executive Officer');
    // A new person gets a new link, so the old address's link stops working.
    if ($was['token'] === '' || ($was['email'] !== '' && $was['email'] !== $email)) {
        hr_ceo_put('token', bin2hex(random_bytes(16)));
    }
    hr_setting('__flush');
    hr_audit('update', 'ceo', null, 'CEO for approvals set: ' . $p['name'] . ' <' . $email . '>');
}

function hr_ceo_new_link(): void
{
    if (hr_ceo()['token'] === '') {
        throw new InvalidArgumentException('Set the CEO first.');
    }
    hr_ceo_put('token', bin2hex(random_bytes(16)));
    hr_audit('update', 'ceo', null, 'CEO approval link replaced');
}

function hr_ceo_link(array $ceo): string
{
    return rtrim(fabo_portal_url(), '/') . '/ceo-approval.php?t=' . $ceo['token'];
}

/** [org, ceo] for a token from the CEO's link, or null. */
function hr_ceo_by_token(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        return null;
    }
    $row = fabo_one("SELECT org_id FROM hr_settings WHERE k = 'assess_ceo_token' AND v = ?", 's', [$token]);
    if (!$row) {
        return null;
    }
    $ceo = hr_ceo((int) $row['org_id']);
    return $ceo['email'] !== '' ? $ceo : null;
}

/* -------------------------------------------------------------- summaries */

/** The submitted sheets of a candidate, with the average out of 50 and the recommendations. */
function hr_approval_summary(int $org, int $sid): array
{
    $sheets = fabo_all("SELECT a.id, a.round, a.total, a.recommendation, a.status, p.name AS iv_name, p.designation AS iv_designation
                          FROM hr_assessments a JOIN hr_panelists p ON p.id = a.panelist_id
                         WHERE a.shortlist_id = ? AND a.org_id = ? ORDER BY a.round, p.name", 'ii', [$sid, $org]);
    $done = array_values(array_filter($sheets, static fn($s) => $s['status'] === 'submitted'));
    return ['sheets' => $done, 'assigned' => count($sheets),
            'avg' => $done ? round(array_sum(array_column($done, 'total')) / count($done), 1) : null];
}

/** Candidates the CEO sees: waiting ones first, then the ones decided. */
function hr_approval_list(int $org, ?string $status = null): array
{
    return fabo_all('SELECT * FROM hr_shortlist WHERE org_id = ? AND approval_status ' . ($status ? '= ?' : 'IS NOT NULL')
                  . ' ORDER BY approval_status = \'pending\' DESC, position, rank_no IS NULL, rank_no, full_name',
                    $status ? 'is' : 'i', $status ? [$org, $status] : [$org]);
}

/* ------------------------------------------------------------ HR: send */

/** Sends candidates to the CEO. Each needs at least one submitted sheet. Returns a sentence. */
function hr_approval_send(array $ids, string $note): string
{
    $ceo = hr_ceo();
    if ($ceo['email'] === '') {
        throw new InvalidArgumentException('Set the CEO\'s name and email on the CEO approval tab first.');
    }
    $note = hr_str($note, 5000) ?: null;
    $sent = 0;
    $skip = [];
    foreach (array_unique(array_map('intval', $ids)) as $id) {
        $s = $id > 0 ? hr_shortlist_get($id) : null;
        if (!$s) {
            continue;
        }
        if ($s['approval_status'] === 'approved') {
            $skip[] = $s['full_name'] . ' (already approved)';
            continue;
        }
        if (!fabo_one("SELECT 1 FROM hr_assessments WHERE shortlist_id = ? AND status = 'submitted' LIMIT 1", 'i', [$id])) {
            $skip[] = $s['full_name'] . ' (no submitted assessment yet)';
            continue;
        }
        fabo_exec("UPDATE hr_shortlist SET approval_status = 'pending', approval_note = ?, approval_remarks = NULL, approval_at = NULL, approval_by = NULL,
                          approval_sent_at = NOW() WHERE id = ? AND org_id = ?", 'sii', [$note, $id, hr_org()]);
        hr_audit('update', 'shortlist', $id, 'Sent to CEO for approval: ' . $s['full_name']
               . ($s['approval_status'] === 'rejected' ? ' (again; earlier remarks: ' . mb_substr((string) $s['approval_remarks'], 0, 300) . ')' : ''));
        $sent++;
    }
    $msg = $sent . ' candidate' . ($sent === 1 ? '' : 's') . ' sent to ' . $ceo['name'] . ' for approval.';
    if ($skip) {
        $msg .= ' Not sent: ' . implode('; ', array_slice($skip, 0, 5)) . (count($skip) > 5 ? '; …' : '') . '.';
    }
    if ($sent) {
        $msg .= hr_assess_mail_words(hr_approval_mail_ceo($ceo), $ceo['email'], 'The approval link was');
    }
    return $msg;
}

/** Emails the CEO the link to the approval page, listing who is waiting. */
function hr_approval_mail_ceo(array $ceo): array
{
    require_once __DIR__ . '/../mailtpl.php';
    $org = (int) $ceo['org'];
    $wait = hr_approval_list($org, 'pending');
    $url = hr_ceo_link($ceo);
    $lines = [];
    foreach ($wait as $s) {
        $sum = hr_approval_summary($org, (int) $s['id']);
        $lines[] = $s['full_name'] . ' — ' . $s['position'] . ($sum['avg'] !== null ? ' (interview average ' . hr_assess_fmt($sum['avg']) . ' / 50)' : '');
    }
    $n = count($wait);
    $intro = $n . ' shortlisted candidate' . ($n === 1 ? ' is' : 's are') . ' waiting for your approval.';
    $after = 'Open the approval page to see each candidate\'s interview scores, the assessment sheets and résumé, and approve or reject them. '
           . 'If you reject a candidate, please add your remarks.';
    $text = 'Dear ' . $ceo['name'] . ",\n\n" . $intro . "\n\n" . implode("\n", $lines) . "\n\n" . $after . "\n\nApproval page: " . $url
          . "\n\nThis link is personal to you — please do not forward it.\n";
    $html = fabo_mail_wrap(hr_org_label($org), 'Candidates for your approval',
        fabo_mail_p('Dear ' . e($ceo['name']) . ',') . fabo_mail_p(e($intro)) . ($lines ? fabo_mail_list(array_map('e', $lines)) : '')
        . fabo_mail_p(e($after)) . fabo_mail_own_link($url, 'Open approval page', 'This link is personal to you — please do not forward it.'), $intro);
    return hr_assess_mail($org, $ceo['email'], 'Approval needed — ' . $n . ' candidate' . ($n === 1 ? '' : 's'), $text, $html, 'ceo');
}

/* ------------------------------------------------------- CEO: decide */

/** The CEO's decision on one waiting candidate. Rejection needs remarks. */
function hr_approval_decide(array $ceo, int $sid, string $decision, string $remarks): array
{
    $org = (int) $ceo['org'];
    $s = fabo_one('SELECT * FROM hr_shortlist WHERE id = ? AND org_id = ?', 'ii', [$sid, $org]);
    if (!$s || $s['approval_status'] !== 'pending') {
        throw new InvalidArgumentException('That candidate is no longer waiting for your decision.');
    }
    if (!in_array($decision, ['approved', 'rejected'], true)) {
        throw new InvalidArgumentException('Choose approve or reject.');
    }
    $remarks = trim(mb_substr($remarks, 0, 5000));
    if ($decision === 'rejected' && $remarks === '') {
        throw new InvalidArgumentException('Please give your remarks for rejecting ' . $s['full_name'] . '.');
    }
    fabo_exec("UPDATE hr_shortlist SET approval_status = ?, approval_remarks = ?, approval_at = NOW(), approval_by = ?, decision = ? WHERE id = ?",
              'ssssi', [$decision, $remarks !== '' ? $remarks : null, $ceo['name'], $decision === 'approved' ? 'selected' : 'rejected', $sid]);
    fabo_exec('INSERT INTO hr_audit (org_id, actor_type, actor_id, actor_label, action, entity, entity_id, summary) VALUES (?,?,?,?,?,?,?,?)',
              'isisssis', [$org, 'system', null, $ceo['name'] . ' (CEO, approval link)', $decision === 'approved' ? 'approve' : 'reject', 'shortlist', $sid,
                           'CEO ' . $decision . ': ' . $s['full_name'] . ($remarks !== '' ? ' — ' . mb_substr($remarks, 0, 300) : '')]);
    // Tell HR.
    require_once __DIR__ . '/../mailtpl.php';
    $to = hr_owner_email($org);
    $word = $decision === 'approved' ? 'approved' : 'rejected';
    $url = rtrim(fabo_portal_url(), '/') . '/login.php?next=' . rawurlencode('hr.php?p=assessment&sl=' . $sid);
    $body = $ceo['name'] . ' has ' . $word . ' ' . $s['full_name'] . ' (' . $s['position'] . ').'
          . ($remarks !== '' ? "\n\nRemarks: " . $remarks : '')
          . ($decision === 'approved' ? "\n\nYou can now send the offer letter from the candidate's page." : '');
    hr_assess_mail($org, $to, 'CEO ' . $word . ': ' . $s['full_name'], $body . "\n\nOpen: " . $url . "\n",
        fabo_mail_wrap(hr_org_label($org), 'CEO ' . $word . ' a candidate', fabo_mail_p(nl2br(e($body))) . fabo_mail_own_link($url, 'Open candidate'), $body), 'ceo-decision');
    return $s;
}

/* -------------------------------------------------------- offer letter */

/** Saves the offer details. Only for a candidate the CEO approved. */
function hr_offer_save(int $sid, array $p): array
{
    $s = hr_shortlist_get($sid);
    if (!$s) {
        fabo_deny('That candidate is not available to you.', 404);
    }
    if ($s['approval_status'] !== 'approved') {
        throw new InvalidArgumentException('The offer letter can only be made after the CEO has approved this candidate.');
    }
    $salary = trim(str_replace(',', '', (string) ($p['offer_salary'] ?? '')));
    if ($salary !== '' && (!is_numeric($salary) || (float) $salary < 0)) {
        throw new InvalidArgumentException('The salary must be a number, e.g. 600000.');
    }
    $join = trim((string) ($p['offer_join_date'] ?? ''));
    if ($join !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $join)) {
        throw new InvalidArgumentException('The joining date is not valid.');
    }
    fabo_exec('UPDATE hr_shortlist SET offer_designation = ?, offer_salary = ?, offer_join_date = ?, offer_place = ? WHERE id = ? AND org_id = ?',
              'sdssii', [hr_str($p['offer_designation'] ?? '', 160, true, 'Designation'), $salary === '' ? null : round((float) $salary, 2),
                         $join ?: null, hr_str($p['offer_place'] ?? '', 160) ?: null, $sid, hr_org()]);
    return hr_shortlist_get($sid);
}

function hr_offer_pdf(array $s): HrPdf
{
    $org = hr_org_label(hr_org());
    $paras = ['Dear ' . $s['full_name'] . ',',
              'With reference to your application and the subsequent interview, we are pleased to offer you the position of ' . $s['offer_designation']
              . ' at ' . $org . ($s['offer_place'] ? ', ' . $s['offer_place'] : '') . '.'];
    if ($s['offer_salary'] !== null) {
        $paras[] = 'Your annual cost to company will be Rs. ' . fabo_thousands((float) $s['offer_salary'], 2)
                 . ' (' . trim(hr_amount_words((float) $s['offer_salary'])) . '), with the detailed salary structure given in your appointment letter.';
    }
    if ($s['offer_join_date']) {
        $paras[] = 'You are requested to join on ' . hr_fmt_date($s['offer_join_date']) . '. Please bring proof of identity, address, education and previous employment on your first day.';
    }
    $paras[] = 'This offer is subject to satisfactory verification of your documents and references. Please confirm your acceptance by replying to this email or by signing and returning a copy of this letter.';
    $paras[] = 'We look forward to welcoming you.';
    return hr_letter_pdf('OFR/' . date('Y') . '/S' . str_pad((string) $s['id'], 4, '0', STR_PAD_LEFT),
                         array_values(array_filter([$s['full_name'], $s['email'], $s['phone']])), 'Offer of employment', $paras);
}

/** Makes the letter, keeps it, and emails the candidate a link to it. Returns a sentence. */
function hr_offer_send(int $sid, array $p): string
{
    $s = hr_offer_save($sid, $p);
    $to = trim((string) $s['email']);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Add the candidate\'s email address (Details above) before sending the offer letter.');
    }
    $bytes = hr_offer_pdf($s)->output();
    $stored = bin2hex(random_bytes(16)) . '.pdf';
    if (file_put_contents(fabo_upload_dir() . DIRECTORY_SEPARATOR . $stored, $bytes) === false) {
        throw new RuntimeException('The offer letter could not be saved.');
    }
    $token = $s['offer_token'] ?: bin2hex(random_bytes(16));
    fabo_exec('UPDATE hr_shortlist SET offer_file = ?, offer_token = ?, offer_sent_at = NOW(), offer_sent_by = ? WHERE id = ?', 'sssi', [$stored, $token, hr_actor(), $sid]);
    if (!empty($s['offer_file'])) {
        fabo_delete_stored($s['offer_file']);
    }
    require_once __DIR__ . '/../mailtpl.php';
    $org = hr_org();
    $brand = hr_org_label($org);
    $url = rtrim(fabo_portal_url(), '/') . '/offer-letter.php?t=' . $token;
    $intro = 'We are pleased to offer you the position of ' . $s['offer_designation'] . ' at ' . $brand . '.';
    $after = 'Your offer letter is ready to download from the link below. Please read it and confirm your acceptance by replying to this email.';
    $text = 'Dear ' . $s['full_name'] . ",\n\n" . $intro . "\n\n" . $after . "\n\nYour offer letter: " . $url . "\n\nRegards,\nHR, " . $brand . "\n";
    $html = fabo_mail_wrap($brand, 'Offer of employment',
        fabo_mail_p('Dear ' . e($s['full_name']) . ',') . fabo_mail_p(e($intro)) . fabo_mail_p(e($after))
        . fabo_mail_own_link($url, 'Download offer letter') . fabo_mail_p('Regards,<br>HR, ' . e($brand)), $intro);
    $r = hr_assess_mail($org, $to, 'Offer of employment — ' . $brand, $text, $html, 'offer');
    hr_audit('send', 'shortlist', $sid, 'Offer letter sent to ' . $s['full_name'] . ' <' . $to . '> (' . $r[0] . ')');
    return 'Offer letter made.' . hr_assess_mail_words($r, $to, 'The download link was');
}

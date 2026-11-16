<?php
/**
 * Digital Signatures — people outside the organisation.
 *
 * A sender may route a document to anyone by name and e-mail. That person
 * gets a link to one page (guest-sign.php) where they read the document,
 * prove they hold the mailbox with a one-time code, and draw or upload
 * their signature — no account, no portal. What they do is an electronic
 * signature, recorded and sealed like a drawn signature by staff, with the
 * verified e-mail address as their identity.
 *
 * The link is a 48-character secret; only its SHA-256 is stored. The code
 * is six digits, valid ten minutes, five tries; only an HMAC of it is
 * stored. A new link replaces the old one (reminders send a new link).
 */

/* ========================================================== who they are */

/** An external signatory from a form row: name, e-mail, organisation, designation. */
function ds_external_clean(array $r): array
{
    $name = ds_str($r['ext_name'] ?? '', 160, true, 'The external signatory\'s name');
    $email = mb_strtolower(ds_str($r['ext_email'] ?? '', 190, true, 'The external signatory\'s e-mail'));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('"' . $email . '" is not a valid e-mail address.');
    }
    return ['name' => $name, 'email' => $email, 'organisation' => ds_str($r['ext_org'] ?? '', 190, false, 'Organisation') ?: null,
            'designation' => ds_str($r['ext_designation'] ?? '', 120, false, 'Designation') ?: null];
}

/** The external signatory's id (made or updated by e-mail, per organisation). */
function ds_external_upsert(array $ext): int
{
    fabo_exec('INSERT INTO ds_external_signers (org_id, name, email, organisation, designation, created_by) VALUES (?,?,?,?,?,?)
               ON DUPLICATE KEY UPDATE name = VALUES(name), organisation = COALESCE(VALUES(organisation), organisation), designation = COALESCE(VALUES(designation), designation)',
              'isssss', [ds_org(), $ext['name'], $ext['email'], $ext['organisation'], $ext['designation'], ds_actor()]);
    return (int) fabo_one('SELECT id FROM ds_external_signers WHERE org_id = ? AND email = ?', 'is', [ds_org(), $ext['email']])['id'];
}

function ds_external(int $id): array
{
    static $memo = [];
    return $memo[$id] ??= (fabo_one('SELECT * FROM ds_external_signers WHERE id = ?', 'i', [$id]) ?: ['name' => 'External signatory', 'email' => '', 'organisation' => null, 'designation' => null]);
}

/** Who a request is for, in words. */
function ds_req_name(array $r): string
{
    return !empty($r['external_id']) ? ds_external((int) $r['external_id'])['name'] : ds_person_name((int) $r['person_id']);
}

/** "a•••@domain" — enough to recognise an address, not to harvest it. */
function ds_mask_email(string $e): string
{
    [$u, $d] = array_pad(explode('@', $e, 2), 2, '');
    return mb_substr($u, 0, 1) . str_repeat('•', max(2, min(6, mb_strlen($u) - 1))) . '@' . $d;
}

/* ================================================================= links */

/**
 * E-mails a fresh signing link for an external request ($why: request,
 * reminder or completed). The previous link stops working. Returns the raw
 * link token (never stored).
 */
function ds_guest_send_link(int $reqId, $why = 'request'): string
{
    $why = $why === false ? 'request' : ($why === true ? 'reminder' : (string) $why);
    $r = fabo_one('SELECT * FROM ds_signature_requests WHERE id = ?', 'i', [$reqId]);
    $doc = fabo_one('SELECT * FROM ds_documents WHERE id = ?', 'i', [(int) $r['doc_id']]);
    $ext = ds_external((int) $r['external_id']);
    $raw = bin2hex(random_bytes(24));
    $until = ds_db_time($why === 'completed' ? time() + 86400 * 30 : max(time() + 86400 * 7, ($r['due_at'] ? ds_ts($r['due_at']) : time()) + 86400 * 7));
    fabo_exec('UPDATE ds_signature_requests SET token_hash = ?, token_expires = ? WHERE id = ?', 'ssi', [hash('sha256', $raw), $until, $reqId]);
    if (isset($GLOBALS['ds_test_links'])) {
        $GLOBALS['ds_test_links'][$reqId] = $raw;
    }
    // The page that caused this (the signatory's own, when they were last) moves to the new link.
    $GLOBALS['ds_guest_new_link'][$reqId] = $raw;
    $url = ds_abs_url('guest-sign.php?t=' . $raw);
    $org = ds_org_name((int) $doc['org_id']);
    $from = ds_person_name((int) $doc['owner_id']);
    if ($why === 'completed') {
        $subject = 'Signed: ' . $doc['title'] . ' (' . $doc['ref_no'] . ')';
        $body = "Dear {$ext['name']},\n\n\"{$doc['title']}\" has now been signed by everyone. You can view and download the signed copy here (the link works for 30 days):\n\n$url\n\nRegards,\n$org";
    } else {
        $verb = $r['action'] === 'approve' ? 'approve' : 'sign';
        $subject = ($why === 'reminder' ? 'Reminder: ' : '') . "Please $verb: {$doc['title']} ({$doc['ref_no']})";
        $body = "Dear {$ext['name']},\n\n$from of $org has sent you \"{$doc['title']}\" to $verb."
              . ($r['due_at'] ? "\nPlease complete it by " . ds_fmt_dt($r['due_at']) . '.' : '')
              . "\n\nOpen the document and $verb it here — no account is needed:\n\n$url\n\n"
              . "You will be asked for a one-time code sent to this e-mail address, then you can draw your signature or upload an image of it."
              . ($why === 'reminder' ? "\n\nThis link replaces any earlier one." : '') . "\n\nRegards,\n$org";
    }
    $status = 'sent';
    $err = null;
    if (empty($GLOBALS['ds_test_links'])) {
        try {
            require_once __DIR__ . '/../notify.php';
            $res = fabo_send_mail($ext['email'], $subject, $body);
            if (is_array($res) && isset($res[0]) && $res[0] === false) {
                $status = 'failed';
                $err = (string) ($res[1] ?? '');
            }
        } catch (Throwable $e) {
            $status = 'failed';
            $err = $e->getMessage();
        }
    }
    try {
        fabo_exec('INSERT INTO ds_notifications (org_id, person_id, doc_id, event, channel, subject, status, error) VALUES (?,?,?,?,?,?,?,?)', 'iiisssss',
                  [(int) $doc['org_id'], 0, (int) $doc['id'], 'external_' . $why, 'email', mb_substr($subject . ' → ' . $ext['email'], 0, 255), $status, $err ? mb_substr($err, 0, 255) : null]);
    } catch (Throwable $e) {
    }
    if ($why !== 'request') {
        ds_audit((int) $doc['id'], 'request', $reqId, $why === 'completed' ? 'external_link' : 'reminder', ($why === 'completed' ? 'Sent the signed copy link to ' : 'Sent a new signing link to ')
            . $ext['name'] . ' (' . $ext['email'] . ')');
    }
    return $raw;
}

/**
 * Opens a signing link: [request, document, external signer] with the
 * organisation and an acting identity set up, or null when the link is
 * wrong, replaced or out of date.
 */
function ds_guest_open(string $raw): ?array
{
    if (!preg_match('/^[a-f0-9]{48}$/', $raw)) {
        return null;
    }
    $r = fabo_one('SELECT * FROM ds_signature_requests WHERE token_hash = ? AND token_expires > NOW() AND external_id IS NOT NULL', 's', [hash('sha256', $raw)]);
    if (!$r) {
        return null;
    }
    $doc = fabo_one('SELECT * FROM ds_documents WHERE id = ? AND deleted_at IS NULL', 'i', [(int) $r['doc_id']]);
    if (!$doc) {
        return null;
    }
    ds_use((int) $doc['org_id']);
    $ext = ds_external((int) $r['external_id']);
    $GLOBALS['ds_guest'] = $ext;
    $GLOBALS['ds_viewer'] = ['is_admin' => false, 'client' => ['id' => 0, 'name' => $ext['name'] . ' (external)', 'kind' => 'guest'], 'access' => [], 'state' => 'ok'];
    $GLOBALS['ds_grant'] = 'edit';
    return [$r, $doc, $ext];
}

/** Is this request still waiting for this external signer to act? */
function ds_guest_can_act(array $r, array $doc): bool
{
    return $r['status'] === 'pending' && in_array($doc['status'], ds_open_statuses(), true) && (int) $r['cycle'] === (int) $doc['cycle']
        && (int) $r['version_id'] === (int) $doc['current_version_id'];
}

/* ================================================================= codes */

/** E-mails a six-digit code. Returns the masked address it went to. */
function ds_guest_send_code(array $r, array $ext): string
{
    require_once __DIR__ . '/../throttle.php';
    if (fabo_throttle_blocked('ds-guest-code', 'r' . $r['id'], 5, 3600)) {
        throw new InvalidArgumentException('Too many codes were requested. Try again in an hour.');
    }
    fabo_throttle_hit('ds-guest-code', 'r' . $r['id']);
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    fabo_exec('UPDATE ds_signature_requests SET otp_hash = ?, otp_expires = DATE_ADD(NOW(), INTERVAL 10 MINUTE), otp_tries = 0 WHERE id = ?', 'si',
              [hash_hmac('sha256', $r['id'] . '|' . $code, ds_subkey('otp')), (int) $r['id']]);
    if (isset($GLOBALS['ds_test_codes'])) {
        $GLOBALS['ds_test_codes'][(int) $r['id']] = $code;
    } else {
        require_once __DIR__ . '/../notify.php';
        $doc = fabo_one('SELECT ref_no, title, org_id FROM ds_documents WHERE id = ?', 'i', [(int) $r['doc_id']]);
        fabo_send_mail($ext['email'], 'Your signing code: ' . $code, "Your one-time code to sign \"{$doc['title']}\" ({$doc['ref_no']}) is:\n\n    $code\n\nIt works for 10 minutes. If you did not ask for it, ignore this e-mail.\n\n" . ds_org_name((int) $doc['org_id']));
    }
    ds_audit((int) $r['doc_id'], 'request', (int) $r['id'], 'external_code', 'A one-time code was e-mailed to ' . $ext['name'] . ' (' . ds_mask_email($ext['email']) . ')');
    return ds_mask_email($ext['email']);
}

/** Checks a code (five tries), and uses it up when right. */
function ds_guest_check_code(array $r, string $code): void
{
    $row = fabo_one('SELECT otp_hash, otp_expires < NOW() AS gone, otp_tries FROM ds_signature_requests WHERE id = ?', 'i', [(int) $r['id']]);
    if (!$row['otp_hash'] || (int) $row['gone'] !== 0) {
        throw new InvalidArgumentException('Ask for a new code: the last one has expired or was already used.');
    }
    if ((int) $row['otp_tries'] >= 5) {
        throw new InvalidArgumentException('Too many wrong codes. Ask for a new one.');
    }
    $code = preg_replace('/\D/', '', $code);
    if (!hash_equals((string) $row['otp_hash'], hash_hmac('sha256', $r['id'] . '|' . $code, ds_subkey('otp')))) {
        fabo_exec('UPDATE ds_signature_requests SET otp_tries = otp_tries + 1 WHERE id = ?', 'i', [(int) $r['id']]);
        ds_audit((int) $r['doc_id'], 'security', (int) $r['id'], 'reauth_failed', 'A wrong signing code was entered on the external link of ' . ds_req_name($r));
        throw new InvalidArgumentException('That code is not right. Check the latest e-mail and try again.');
    }
    fabo_exec('UPDATE ds_signature_requests SET otp_hash = NULL, otp_expires = NULL, otp_tries = 0 WHERE id = ?', 'i', [(int) $r['id']]);
}

/* ============================================================ the actions */

/**
 * Signs (or approves) as the external signatory: the code proves the
 * mailbox; the drawn or uploaded signature is stamped where the sender
 * placed it (or at the foot of the last page); the record is sealed.
 */
function ds_guest_sign(string $raw, string $code, ?string $pngData, string $remarks, bool $consent, array $post = []): string
{
    $o = ds_guest_open($raw);
    if (!$o) {
        throw new InvalidArgumentException('This link is no longer valid.');
    }
    [$r, $doc, $ext] = $o;
    if (!ds_guest_can_act($r, $doc)) {
        throw new InvalidArgumentException('There is nothing to sign on this link any more.');
    }
    if (!$consent) {
        throw new InvalidArgumentException('Tick the box to agree to sign electronically.');
    }
    $png = ($pngData !== null && $pngData !== '') ? ds_drawn_png($pngData) : null;
    if ($r['action'] !== 'approve' && $png === null) {
        throw new InvalidArgumentException('Draw your signature or upload an image of it first.');
    }
    $remarks = ds_str($remarks, 1000, false, 'Remarks');
    $typed = ds_typed_clean($post, $r, $png !== null ? 'drawn' : 'eapproval');
    ds_guest_check_code($r, $code);
    ds_crypto_lock_check($doc, true, (int) $r['id']);
    $method = $png !== null ? 'drawn' : 'eapproval';
    $status = $r['action'] === 'approve' ? 'approved' : 'signed';
    $name = $typed['name'] ?? $ext['name'];
    $when = $typed['date'] ?? date('d M Y, H:i T');
    $lines = $method === 'drawn'
        ? [[$name, 7.5, true], [trim(($ext['designation'] ?? '') . ' ' . ($ext['organisation'] ? '· ' . $ext['organisation'] : '')) ?: 'External signatory', 6.5], [$when, 6.2], ['Electronic signature · ' . $doc['ref_no'], 5.6]]
        : [['APPROVED', 8, true], [$name, 7, true], [$when, 6.2], ['Electronic approval · ' . $doc['ref_no'], 5.6]];
    ds_tx(static function () use ($r, $doc, $ext, $method, $status, $png, $remarks, $lines, $name, $typed) {
        [$fid, $before, $after, $where] = ds_stamp_working((int) $doc['id'], $r, null, $lines, $png, '0.06 0.18 0.45', $method, $typed);
        $ev = ['intent' => $method === 'drawn' ? 'Signed electronically (drawn or uploaded signature)' : 'Approved electronically', 'signer_type' => 'external',
               'email' => $ext['email'], 'organisation' => $ext['organisation'], 'identity_check' => 'one-time code e-mailed to ' . $ext['email'],
               'consent' => 'I agree to sign this document electronically.', 'pdf_sha256_before' => $before, 'pdf_sha256_after' => $after, 'stamped_on' => $where,
               'legal_note' => 'Electronic signature; not a digital signature under the IT Act.', 'typed_by_signatory' => $typed];
        if ($png !== null) {
            $ev['signature_image_sha256'] = hash('sha256', $png);
        }
        ds_request_done($r, $doc, $status, $method, $remarks, $ev, $name . ' (external, ' . $ext['email'] . ') ' . ($status === 'approved' ? 'approved ' : 'signed ') . $doc['ref_no']);
        // The link stays open for reading and, once complete, the signed copy.
        fabo_exec('UPDATE ds_signature_requests SET token_expires = GREATEST(token_expires, DATE_ADD(NOW(), INTERVAL 30 DAY)) WHERE id = ?', 'i', [(int) $r['id']]);
        ds_advance((int) $doc['id']);
    });
    if (!ds_doc_completed((int) $doc['id'])) {
        ds_notify([(int) $doc['owner_id']], 'signed', $doc['ref_no'] . ': ' . $name . ($status === 'approved' ? ' approved' : ' signed'),
            $name . ' (' . $ext['email'] . ', external) ' . ($status === 'approved' ? 'approved' : 'signed') . ' "' . $doc['title'] . '".', (int) $doc['id']);
    }
    return $status === 'approved' ? 'Approved. Thank you.' : 'Signed. Thank you — the sender has been told.';
}

/** Declines (rejects) or asks for changes, with a reason; the code is needed too. */
function ds_guest_decline(string $raw, string $code, string $decision, string $reason): string
{
    $o = ds_guest_open($raw);
    if (!$o) {
        throw new InvalidArgumentException('This link is no longer valid.');
    }
    [$r, $doc, $ext] = $o;
    if (!ds_guest_can_act($r, $doc)) {
        throw new InvalidArgumentException('There is nothing to act on with this link any more.');
    }
    $reason = ds_str($reason, 1000, true, 'A reason');
    ds_guest_check_code($r, $code);
    $status = $decision === 'changes' ? 'changes' : 'rejected';
    ds_tx(static function () use ($r, $doc, $ext, $status, $reason) {
        ds_request_done($r, $doc, $status, $r['action'], $reason, ['intent' => $status === 'rejected' ? 'Declined' : 'Requested changes', 'signer_type' => 'external', 'email' => $ext['email']],
            $ext['name'] . ' (external) ' . ($status === 'rejected' ? 'declined ' : 'requested changes to ') . $doc['ref_no']);
        fabo_exec('UPDATE ds_signature_requests SET status = "cancelled" WHERE doc_id = ? AND cycle = ? AND status IN ("waiting","pending")', 'ii', [(int) $doc['id'], (int) $doc['cycle']]);
        fabo_exec('UPDATE ds_documents SET status = ? WHERE id = ?', 'si', [$status === 'rejected' ? 'rejected' : 'changes_requested', (int) $doc['id']]);
    });
    ds_notify(ds_doc_participants((int) $doc['id']), $status === 'rejected' ? 'rejected' : 'changes', $doc['ref_no'] . ($status === 'rejected' ? ' was declined' : ': changes requested'),
        $ext['name'] . ' (' . $ext['email'] . ', external) ' . ($status === 'rejected' ? 'declined "' : 'asked for changes to "') . $doc['title'] . '": ' . $reason, (int) $doc['id']);
    return $status === 'rejected' ? 'You have declined to sign. The sender has been told.' : 'Your request for changes was sent to the sender.';
}

/** An avatar for whoever a request is for (an external signer is marked as such). */
function ds_req_avatar(array $r, string $size = ''): string
{
    if (empty($r['external_id'])) {
        return ds_avatar((int) $r['person_id'], $size);
    }
    $name = ds_req_name($r);
    return '<span class="ds-av ext' . ($size ? ' ' . $size : '') . '" title="' . e($name) . ' (external)" aria-hidden="true">' . e(ds_initials($name)) . '</span>';
}

/**
 * Tells the person a request is for: in the portal (and by e-mail) for staff;
 * for an external signer, a fresh signing link by e-mail when it asks them to act.
 */
function ds_notify_req(array $r, string $event, string $title, string $body, ?int $docId, array $q = []): void
{
    if (empty($r['external_id'])) {
        ds_notify([(int) $r['person_id']], $event, $title, $body, $docId, $q);
        return;
    }
    if (in_array($event, ['reminder', 'deadline', 'escalated'], true) && ($r['status'] ?? 'pending') === 'pending') {
        ds_guest_send_link((int) $r['id'], 'reminder');
    }
}

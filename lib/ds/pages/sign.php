<?php
/**
 * The signing workspace: the whole document, who has signed, who is next,
 * and — for the signatory alone — review, approve, sign, reject or ask
 * for changes. An approval or drawn signature is recorded for the signed-in
 * signatory; a digital signature goes through the eSign
 * provider or the signatory's own DSC and is verified before it counts.
 */

$reqId = (int) ($_GET['id'] ?? 0);
$r = fabo_one('SELECT * FROM ds_signature_requests WHERE id = ? AND org_id = ?', 'ii', [$reqId, ds_org()]);
if (!$r) {
    fabo_deny('That signature request does not exist.', 404);
}
$doc = ds_doc_open((int) $r['doc_id']);
if ((int) $r['person_id'] !== ds_me()) {
    // Others may look at the document, never act on someone else's request.
    inc_redirect(ds_url('doc', ['id' => $doc['id']]), ['err', 'That request is addressed to ' . ds_req_name($r) . '. Only they can act on it.']);
}
$self = ds_url('sign', ['id' => $reqId]);
$flash = null;
$esignForm = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    $ver = (int) ($_POST['version'] ?? 0);
    $place = isset($_POST['placement']) && $_POST['placement'] !== '' ? (string) $_POST['placement'] : null;
    try {
        if ($a === 'esign') {
            $esignForm = ds_esign_start($reqId, $place, !empty($_POST['consent']), $ver);
        } else {
            $msg = match ($a) {
                'review', 'reject', 'changes' => ds_decide($reqId, $a, (string) ($_POST['remarks'] ?? ''), $ver),
                'eapproval', 'drawn' => ds_sign_simple($reqId, $a, '', (string) ($_POST['remarks'] ?? ''), $_POST['signature'] ?? null, $place, $ver, $_POST),
                'dsc' => (static function () use ($reqId, $ver) {
                    $up = ds_upload_bytes('signed_pdf');
                    if (!$up) {
                        throw new InvalidArgumentException('Choose the signed PDF to upload.');
                    }
                    return ds_dsc_upload($reqId, $up[0], $ver);
                })(),
                'cancel_txn' => (static function () use ($reqId) {
                    $n = fabo_exec('UPDATE ds_signature_transactions SET status = "cancelled" WHERE request_id = ? AND status IN ("initiated","awaiting")', 'i', [$reqId]);
                    return $n ? 'Your signing session was cancelled; the document is free for others.' : 'Nothing to cancel.';
                })(),
                default => throw new InvalidArgumentException('Unknown action.'),
            };
            inc_redirect(in_array($a, ['cancel_txn'], true) ? $self : ds_url('doc', ['id' => $doc['id']]), ['ok', $msg]);
        }
    } catch (InvalidArgumentException $e) {
        $flash = ['err', $e->getMessage()];
    } catch (Throwable $e) {
        error_log('FABO esign sign action: ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
        $flash = ['err', fabo_user_error($e)];
    }
    $r = fabo_one('SELECT * FROM ds_signature_requests WHERE id = ?', 'i', [$reqId]);
    $doc = ds_doc((int) $r['doc_id']);
}

if ($esignForm) {
    // Hand over to the ESP: a self-submitting form (the signatory authenticates there, never here).
    ds_layout_start('Opening Aadhaar eSign…', 'You are being taken to the eSign Service Provider to authenticate with Aadhaar OTP.', '', ['My signatures' => ds_url('inbox'), $doc['ref_no'] => null]);
    echo '<div class="ds-handoff ds-rise"><div class="ds-spinner" aria-hidden="true"></div><p>Connecting securely to the eSign Service Provider… Transaction <span class="mono">' . e($esignForm['txn']) . '</span></p>'
       . '<form method="post" action="' . e($esignForm['url']) . '" id="esp-form">';
    foreach ($esignForm['fields'] as $k => $v) {
        echo '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
    }
    echo '<noscript><button class="ds-btn ds-btn-primary">Continue to eSign</button></noscript></form><script>setTimeout(function(){document.getElementById("esp-form").submit();},600);</script></div>';
    ds_layout_end();
    return;
}

$reqs = ds_doc_requests((int) $doc['id'], (int) $r['cycle']);
$pending = $r['status'] === 'pending' && in_array($doc['status'], ds_open_statuses(), true);
$methods = $pending ? ds_allowed_methods($r) : [];
$lock = $pending ? ds_crypto_lock_check($doc, false, $reqId) : null;
$myTxn = fabo_one('SELECT * FROM ds_signature_transactions WHERE request_id = ? AND status IN ("initiated","awaiting") AND expires_at > NOW() ORDER BY id DESC LIMIT 1', 'i', [$reqId]);
$lastFail = fabo_one('SELECT * FROM ds_signature_transactions WHERE request_id = ? AND status = "failed" AND updated_at > DATE_SUB(NOW(), INTERVAL 1 HOUR) ORDER BY id DESC LIMIT 1', 'i', [$reqId]);
$verb = ['sign' => 'Sign', 'approve' => 'Approve', 'review' => 'Review'][$r['action']] ?? 'Sign';

ds_layout_start($verb . ': ' . $doc['title'], '<span class="mono">' . e($doc['ref_no']) . '</span> · ' . ds_status_badge($doc['status']) . ' · from ' . e(ds_person_name((int) $doc['owner_id'])) . ' · ' . ds_priority_chip($doc['priority']),
    '<a class="ds-btn ds-btn-ghost" href="' . e(ds_url('file', ['doc' => $doc['id'], 'kind' => 'current', 'dl' => 1])) . '">' . ds_icon('download') . 'Download</a>',
    ['My signatures' => ds_url('inbox'), $doc['ref_no'] => ds_url('doc', ['id' => $doc['id']]), $verb => null], $flash, 'Signing workspace');

if ($lastFail && !$flash) {
    echo ds_flash_html(['err', 'Your last signing attempt did not complete: ' . $lastFail['error_message']]);
}

echo '<div class="ds-signgrid"><div class="ds-signgrid-l">';
$myFields = ds_request_fields($r);
$fieldLabels = array_map(static fn($k) => $k[0], ds_field_kinds());
$viewFid = (int) ($doc['signed_file_id'] ?: $doc['working_file_id']);
ds_preview_warm($viewFid);
echo '<div class="ds-viewer ds-rise" data-viewer' . ds_preview_attr((int) $doc['id'], $viewFid) . ' data-placeable="' . ($pending && $r['action'] !== 'review' && !$myFields ? '1' : '0') . '"'
   . ($myFields && $pending ? ' data-fields="' . e(json_encode(array_map(static fn($f) => $f + ['label' => $fieldLabels[$f['kind']]], $myFields))) . '"' : '') . ' data-src="' . e(ds_url('file', ['doc' => $doc['id'], 'kind' => 'current', 'inline' => 1, 'f' => (int) ($doc['signed_file_id'] ?: $doc['working_file_id'])])) . '">'
   . '<div class="ds-viewer-bar"><span class="ds-viewer-t">' . ds_icon('file') . 'Read the whole document <small data-pageinfo></small></span><span class="ds-viewer-c">'
   . '<button type="button" class="ds-icobtn" data-zoom="-1" aria-label="Zoom out">' . ds_icon('zoom-out') . '</button><button type="button" class="ds-icobtn" data-zoom="1" aria-label="Zoom in">' . ds_icon('zoom-in') . '</button></span></div>'
   . ($pending && $myFields ? '<div class="ds-placehint is-fixed">' . ds_icon('move') . '<span><strong>' . ds_person_name((int) $doc['owner_id']) . ' marked ' . count($myFields) . ' place' . (count($myFields) === 1 ? '' : 's') . ' for you.</strong> They are filled in when you sign.</span><button type="button" class="ds-btn ds-btn-sm" data-nextfield>' . ds_icon('chev-d') . 'Next field</button></div>' : '')
   . ($pending && $r['action'] !== 'review' && !$myFields ? '<div class="ds-placehint" data-placehint>' . ds_icon('move') . '<span><strong>Choose where your signature goes:</strong> click on the page. Otherwise it is placed at the foot of the last page.</span></div>' : '')
   . '<div class="ds-viewer-pages" data-pages><div class="ds-skel"></div><div class="ds-skel"></div></div><div class="ds-readmeter" data-readmeter><i></i><span>Reading progress</span></div></div>';
echo '</div><aside class="ds-signgrid-r">';

/* The request */
echo '<div class="ds-card ds-rise ds-signcard"><div class="ds-signcard-h"><span class="ds-req-ic a-' . e($r['action']) . '">' . ds_icon(['sign' => 'sign', 'approve' => 'check', 'review' => 'eye'][$r['action']] ?? 'pen') . '</span>'
   . '<div><div class="ds-eyebrow">' . e((string) $r['stage_name']) . '</div><strong>You are asked to ' . strtolower($verb) . '</strong>'
   . ($r['on_behalf_of_id'] ? '<small>On behalf of ' . e(ds_person_name((int) $r['on_behalf_of_id'])) . ' (delegation) — you sign in your own name.</small>' : '')
   . ($r['due_at'] ? '<small class="' . (ds_ts($r['due_at']) < time() ? 'ds-red' : '') . '">' . ds_icon('clock') . 'Due ' . e(ds_fmt_dt($r['due_at'])) . '</small>' : '') . '</div></div>';
if ($doc['remarks']) {
    echo '<div class="ds-instr">' . ds_icon('chat') . '<p>' . nl2br(e($doc['remarks'])) . '</p></div>';
}
if (!$pending) {
    echo '<div class="ds-note">' . ds_icon('check-circle') . '<span>This request is <strong>' . e(strtolower(ds_request_statuses()[$r['status']][0] ?? $r['status'])) . '</strong>'
       . ($r['acted_at'] ? ' (' . e(ds_fmt_dt($r['acted_at'])) . ')' : '') . '. There is nothing more to do.</span></div>';
} elseif ($lock) {
    echo '<div class="ds-note warn">' . ds_icon('lock') . '<span>' . e(ds_person_name((int) $lock['person_id'])) . ' is applying a digital signature right now. Signing opens again by ' . e(date('H:i', ds_ts($lock['expires_at']))) . '. You can still reject or request changes.</span></div>';
}
if ($myTxn && $myTxn['provider'] === 'dsc_upload') {
    echo '<div class="ds-note">' . ds_icon('usb') . '<span>You downloaded a copy to sign with your DSC. Upload it by ' . e(date('H:i', ds_ts($myTxn['expires_at']))) . '.</span>'
       . ds_button('Release', ['action' => 'cancel_txn'], 'ds-btn ds-btn-ghost ds-btn-sm') . '</div>';
}

if ($pending) {
    $hidden = '<input type="hidden" name="version" value="' . (int) $doc['version'] . '"><input type="hidden" name="placement" value="" data-placement>';
    if ($r['action'] === 'review') {
        echo ds_form('class="ds-signform"') . $hidden . '<input type="hidden" name="action" value="review">'
           . ds_field('Remarks (optional)', '<textarea name="remarks" rows="2" maxlength="1000"></textarea>')
           . '<button class="ds-btn ds-btn-primary ds-btn-block">' . ds_icon('check') . 'Mark as reviewed &amp; forward</button></form>';
    } elseif (!$methods) {
        echo '<div class="ds-note warn">' . ds_icon('alert') . '<span>This request asks for ' . e(ds_method_label($r['method'])) . ', which is not set up yet. Ask your administrator, or request changes.</span></div>';
    } else {
        $info = [
            'esign' => ['fingerprint', 'Aadhaar eSign', 'Digital signature · IT Act recognised', 'Authenticate with an OTP on your Aadhaar-linked mobile at the eSign provider.', 'digital'],
            'dsc' => ['usb', 'DSC (USB token)', 'Digital signature · IT Act recognised', 'Download, sign in Adobe Acrobat/Reader with your Class 3 token, and upload.', 'digital'],
            'drawn' => ['pen', 'Signature', 'Draw it or upload an image of it', 'Your signature is placed on the document.', 'drawn'],
            'eapproval' => ['check', 'Electronic approval', 'Electronic record · not a digital signature', 'Approve with one click; an approval stamp is added.', 'elec'],
        ];
        echo '<fieldset class="ds-methods"><legend>How do you want to ' . strtolower($verb) . '?</legend>';
        foreach ($methods as $i => $m) {
            [$ic, $label, $kindTxt, $desc, $cls] = $info[$m];
            echo '<label class="ds-method m-' . $cls . '"><input type="radio" name="ds-method" value="' . $m . '"' . ($i === 0 ? ' checked' : '') . ($lock && in_array($m, ['esign', 'dsc', 'drawn'], true) ? ' disabled' : '') . '>'
               . '<span class="ds-method-i">' . ds_icon($ic) . '</span><span class="ds-method-b"><strong>' . e($label) . '</strong><em>' . e($kindTxt) . '</em><small>' . e($desc) . '</small></span><span class="ds-method-r"></span></label>';
        }
        echo '</fieldset>';

        if (in_array('esign', $methods, true)) {
            $p = ds_provider_for('esign');
            echo '<div class="ds-mpane" data-mpane="esign" hidden>' . ds_form('class="ds-signform" data-busy-form') . $hidden . '<input type="hidden" name="action" value="esign">'
               . '<div class="ds-esp">' . ds_icon('shield') . '<span>Provider: <strong>' . e($p['config']['esp_name'] ?? $p['label']) . '</strong>' . ($p['environment'] !== 'production' ? ' ' . ds_badge('Sandbox', 'amber', false) : '') . '</span></div>'
               . '<label class="ds-consent"><input type="checkbox" name="consent" value="1" required> <span>I consent to authenticate with my Aadhaar number through the eSign Service Provider, for signing <strong>' . e($doc['ref_no']) . '</strong>. '
               . 'I understand my Aadhaar e-KYC is used only to issue a one-time signing certificate in my name, and that the document\'s hash — not its content — is sent.</span></label>'
               . '<button class="ds-btn ds-btn-primary ds-btn-block" data-busy="Connecting…">' . ds_icon('fingerprint') . 'Continue to Aadhaar eSign</button></form></div>';
        }
        if (in_array('dsc', $methods, true)) {
            $p = ds_provider_for('dsc');
            $instr = trim((string) ($p['config']['instructions'] ?? ''));
            echo '<div class="ds-mpane" data-mpane="dsc" hidden><ol class="ds-dscsteps"><li><strong>Download</strong> the document for signing. It is held for you while you sign.'
               . '<a class="ds-btn ds-btn-sm" href="' . e(ds_url('file', ['kind' => 'dsc', 'req' => $reqId])) . '">' . ds_icon('download') . 'Download for DSC signing</a></li>'
               . '<li><strong>Sign</strong> it in Adobe Acrobat / Reader (Tools → Certificates → Digitally Sign) with your USB token. Save; do not print or "Save As" another PDF.</li>'
               . '<li><strong>Upload</strong> the signed file. It is checked: same document, one new valid signature, your certificate, a trusted CA.</li></ol>'
               . ($instr !== '' ? '<div class="ds-instr">' . ds_icon('chat') . '<p>' . nl2br(e($instr)) . '</p></div>' : '')
               . ds_form('class="ds-signform" data-busy-form', true) . $hidden . '<input type="hidden" name="action" value="dsc">'
               . '<label class="ds-drop small" data-drop><input type="file" name="signed_pdf" accept="application/pdf,.pdf" required><span class="ds-drop-i">' . ds_icon('upload') . '</span><strong>Upload the signed PDF</strong><span class="ds-drop-f" data-drop-name hidden></span></label>'
               . '<button class="ds-btn ds-btn-primary ds-btn-block" data-busy="Verifying signature…">' . ds_icon('shield-check') . 'Verify &amp; record my signature</button></form></div>';
        }
        foreach (['drawn', 'eapproval'] as $m) {
            if (!in_array($m, $methods, true)) {
                continue;
            }
            echo '<div class="ds-mpane" data-mpane="' . $m . '" hidden>' . ds_form('class="ds-signform" data-busy-form' . ($m === 'drawn' ? ' data-padform' : '')) . $hidden . '<input type="hidden" name="action" value="' . $m . '">';
            if ($m === 'drawn') {
                echo ds_pad_html();
            }
            echo ds_typed_html($r, $m) . ds_field('Remarks (optional)', '<textarea name="remarks" rows="2" maxlength="1000"></textarea>')
               . '<p class="ds-legalnote">' . ds_icon('alert') . 'This is an electronic record, sealed and logged with your identity, time and IP — not a digital signature under the IT Act.</p>'
               . '<button class="ds-btn ds-btn-primary ds-btn-block" data-busy="Recording…">' . ds_icon($m === 'drawn' ? 'pen' : 'check') . ($m === 'drawn' ? 'Sign document' : ($r['action'] === 'approve' ? 'Approve document' : 'Approve electronically')) . '</button></form></div>';
        }
    }
    echo '<div class="ds-decline"><button type="button" class="ds-btn ds-btn-ghost ds-btn-sm" data-open="dlg-changes">' . ds_icon('edit') . 'Request changes</button>'
       . '<button type="button" class="ds-btn ds-btn-ghost ds-btn-sm danger" data-open="dlg-reject">' . ds_icon('x-circle') . 'Reject</button></div>';
}
echo '</div>';

/* Progress */
echo ds_card_start('Signing progress', '', 'compact', 'flow') . ds_stepper($doc, $reqs) . ds_card_end();
echo ds_card_start('Document', '', 'compact', 'file') . '<dl class="ds-kv one"><div><dt>Reference</dt><dd class="mono">' . e($doc['ref_no']) . '</dd></div><div><dt>Owner</dt><dd>' . e(ds_person_name((int) $doc['owner_id'])) . '</dd></div>'
   . '<div><dt>Department</dt><dd>' . e(ds_dept_name($doc['department_id'] ? (int) $doc['department_id'] : null)) . '</dd></div><div><dt>Category</dt><dd>' . e(ds_category_name($doc['category_id'] ? (int) $doc['category_id'] : null)) . '</dd></div>'
   . ($doc['value_amount'] !== null ? '<div><dt>Value</dt><dd>' . e(ds_money($doc['value_amount'])) . '</dd></div>' : '') . '<div><dt>Valid until</dt><dd>' . e(ds_fmt_date($doc['valid_until'])) . '</dd></div></dl>'
   . ($doc['description'] ? '<p class="ds-pre small">' . e($doc['description']) . '</p>' : '') . ds_card_end();
echo ds_card_start('History', '<a class="ds-link" href="' . e(ds_url('doc', ['id' => $doc['id'], 'tab' => 'activity'])) . '">All</a>', 'compact', 'history') . ds_timeline(array_slice(ds_doc_timeline((int) $doc['id'], 8), 0, 8)) . ds_card_end();
echo '</aside></div>';

if ($pending) {
    foreach (['changes' => ['Request changes', 'edit', 'Tell the owner what needs to change. The signing stops and comes back to them.', 'Send request', 'ds-btn-primary'],
              'reject' => ['Reject document', 'x-circle', 'Rejecting stops the signing for everyone. The owner is told your reason.', 'Reject', 'ds-btn-danger']] as $a => [$t, $ic, $txt, $btn, $cls]) {
        echo '<dialog class="ds-dlg" id="dlg-' . $a . '">' . ds_form() . '<input type="hidden" name="action" value="' . $a . '"><input type="hidden" name="version" value="' . (int) $doc['version'] . '">'
           . '<header><h3>' . ds_icon($ic) . e($t) . '</h3><button type="button" class="ds-icobtn" data-close aria-label="Close">' . ds_icon('x') . '</button></header><div class="ds-dlg-b"><p>' . e($txt) . '</p>'
           . ds_field('Reason', '<textarea name="remarks" rows="3" required maxlength="1000"></textarea>', '', true) . '</div>'
           . '<footer><button type="button" class="ds-btn ds-btn-ghost" data-close>Back</button><button class="ds-btn ' . $cls . '">' . e($btn) . '</button></footer></form></dialog>';
    }
}
ds_layout_end();

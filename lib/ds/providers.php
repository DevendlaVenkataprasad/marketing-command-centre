<?php
/**
 * Digital Signatures — how a signatory signs.
 *
 * Four methods, never confused with one another:
 *
 *   Electronic approval   the signatory confirms with their portal password.
 *                         Recorded and sealed here; stamped on the PDF as an
 *                         approval. It is NOT a digital signature.
 *   Drawn signature       a handwritten mark drawn on screen, confirmed with
 *                         the password and stamped on the PDF. Also NOT a
 *                         digital signature.
 *   Aadhaar eSign         a digital signature by a licensed eSign Service
 *                         Provider (eMudhra, C-DAC, Protean …) under the CCA's
 *                         eSign API. Legally recognised (IT Act, Second
 *                         Schedule).
 *   DSC                   a digital signature with the signatory's own Class 3
 *                         certificate on a USB token, applied in their PDF
 *                         software and uploaded. Legally recognised.
 *
 * A document is never marked signed by a click. The digital methods count
 * only when the signature verifies cryptographically over exactly the bytes
 * prepared for it. Private keys never touch this application: eSign keys
 * live at the ESP, DSC keys on the token. The ASP's own key (which signs the
 * request to the ESP) is a file on the server named in the provider's
 * settings, never a value in the database. No OTP passes through here: the
 * signatory authenticates on the ESP's own page.
 *
 * Providers are adapters behind one interface (ds_provider_kinds()): adding
 * another ESP is configuration — its gateway URL, ASP id and certificates —
 * not code, because every licensed ESP implements the same CCA API.
 */

require_once __DIR__ . '/verify.php';

/* ============================================================ registry */

/** Provider adapters: key => label, method, config fields [key => [label, type, hint, required]]. */
function ds_provider_kinds(): array
{
    return [
        'esign_cca' => [
            'label' => 'Aadhaar eSign (CCA eSign API)', 'method' => 'esign',
            'presets' => ['eMudhra', 'C-DAC', 'Protean (NSDL)', 'Other licensed ESP'],
            'fields' => [
                'esp_name'          => ['eSign Service Provider', 'text', 'eMudhra, C-DAC, Protean … as on your ASP agreement.', true],
                'gateway_url'       => ['ESP gateway URL', 'url', 'The ESP\'s eSign form URL for this environment, from your onboarding kit.', true],
                'asp_id'            => ['ASP ID', 'text', 'The Application Service Provider ID the ESP issued to your organisation.', true],
                'asp_key_path'      => ['ASP private key file', 'path', 'Path on this server to the PEM key that signs requests. Keep it outside the web folder. Never paste the key itself.', true],
                'asp_key_pass_env'  => ['Key passphrase variable', 'text', 'If the key is encrypted: the name of the environment variable holding its passphrase (not the passphrase).', false],
                'asp_cert_path'     => ['ASP certificate file', 'path', 'Path to the ASP certificate registered with the ESP (sent in the request\'s KeyInfo).', false],
                'esp_cert_pem'      => ['ESP response-signing certificate', 'pem', 'The ESP\'s public certificate. Every response is checked against it before anything is accepted.', true],
                'api_version'       => ['eSign API version', 'select:2.1,3.2', '2.1 for OTP/biometric on the ESP page.', true],
                'auth_mode'         => ['Authentication mode', 'select:1,2,3', '1 = OTP, 2 = fingerprint, 3 = iris.', true],
                'request_field'     => ['Request form field', 'text', 'The form field the ESP expects the signed XML in (usually "msg").', true],
                'reserve_kb'        => ['Signature space (KB)', 'number', 'Space reserved in the PDF for the returned signature. 16 suits most ESPs.', false],
            ],
        ],
        'dsc_upload' => [
            'label' => 'DSC on a USB token (sign in PDF software, then upload)', 'method' => 'dsc',
            'presets' => [],
            'fields' => [
                'window_minutes' => ['Time to sign and upload (minutes)', 'number', 'How long the downloaded copy is held for the signatory. Others wait meanwhile.', false],
                'instructions'   => ['Instructions for signatories', 'textarea', 'Shown on the signing page, e.g. which software and token driver to use.', false],
            ],
        ],
    ];
}

/** The organisation's active configuration for a method ('esign' | 'dsc'), or null. */
function ds_provider_for(string $method): ?array
{
    $kind = $method === 'esign' ? 'esign_cca' : ($method === 'dsc' ? 'dsc_upload' : '');
    $r = fabo_one('SELECT * FROM ds_providers WHERE org_id = ? AND provider = ? AND active = 1 ORDER BY priority, id LIMIT 1', 'is', [ds_org(), $kind]);
    if ($r) {
        $r['config'] = json_decode((string) $r['config_json'], true) ?: [];
        return $r;
    }
    // DSC needs nothing configured to work.
    return $method === 'dsc' ? ['id' => null, 'provider' => 'dsc_upload', 'label' => 'DSC', 'environment' => 'production', 'config' => []] : null;
}

/** Is a provider's configuration complete enough to use? [ok, problems]. */
function ds_provider_check(array $p): array
{
    $kinds = ds_provider_kinds();
    $k = $kinds[$p['provider']] ?? null;
    if (!$k) {
        return [false, ['Unknown provider.']];
    }
    $c = $p['config'] ?? (json_decode((string) ($p['config_json'] ?? ''), true) ?: []);
    $bad = [];
    foreach ($k['fields'] as $f => [$label, $type, , $req]) {
        if ($req && trim((string) ($c[$f] ?? '')) === '') {
            $bad[] = $label . ' is not set.';
        }
    }
    if ($p['provider'] === 'esign_cca') {
        if (!empty($c['gateway_url']) && !preg_match('~^https://~i', $c['gateway_url'])) {
            $bad[] = 'The gateway URL must be https.';
        }
        if (!empty($c['asp_key_path'])) {
            $k = ds_asp_key($c);
            if (!$k) {
                $bad[] = 'The ASP private key cannot be read from ' . $c['asp_key_path'] . '.';
            }
        }
        if (!empty($c['esp_cert_pem']) && !ds_cert_summary($c['esp_cert_pem'])) {
            $bad[] = 'The ESP certificate is not a valid certificate.';
        }
    }
    return [!$bad, $bad];
}

/** Methods a request allows, among those available here. */
function ds_allowed_methods(array $r): array
{
    $m = (string) $r['method'];
    if ($r['action'] === 'review') {
        return [];
    }
    if ($m === 'any') {
        $list = $r['action'] === 'approve' ? ['eapproval', 'drawn', 'esign'] : ['drawn', 'esign'];
    } elseif ($m === 'digital') {
        $list = ['esign', 'dsc'];
    } else {
        $list = [$m];
    }
    return array_values(array_filter($list, static fn($x) => $x !== 'esign' || ds_provider_for('esign')));
}

/* ======================================================= re-authentication */

/**
 * Confirms the signatory is who is signed in: their portal password, asked
 * again at the moment of signing. Five wrong answers in 15 minutes lock
 * signing for that person for 15 minutes.
 */
function ds_reauth(string $password): void
{
    require_once __DIR__ . '/../throttle.php';
    $key = 'p' . ds_me();
    if (fabo_throttle_blocked('ds-reauth', $key, 5, 900)) {
        throw new InvalidArgumentException('Too many wrong passwords. Signing is locked for 15 minutes.');
    }
    $ok = false;
    if (!empty($GLOBALS['ds_test_reauth'])) {
        $ok = $password === $GLOBALS['ds_test_reauth'];
    } elseif (ds_me() > 0) {
        $hash = (string) (fabo_one('SELECT password_hash FROM clients WHERE id = ?', 'i', [ds_me()])['password_hash'] ?? '');
        $ok = $hash !== '' && password_verify($password, $hash);
    } elseif (function_exists('fabo_admin_locked') && fabo_admin_locked()) {
        $ok = fabo_admin_check(function_exists('fabo_admin_email') ? fabo_admin_email() : '', $password);
    }
    if (!$ok) {
        fabo_throttle_hit('ds-reauth', $key);
        ds_audit(null, 'security', ds_me(), 'reauth_failed', 'Wrong password when confirming a signature (' . ds_actor() . ')');
        throw new InvalidArgumentException('That password is not right. Enter your portal password to confirm it is you.');
    }
    fabo_throttle_clear('ds-reauth', $key);
}

/* =============================================================== placing */

/**
 * Where a request's stamp or signature goes: the place the signatory chose
 * on the page ({page, x, y, w, h} as fractions), else the next free slot at
 * the foot of the last page. Returns [page index, u, v, w, h].
 */
function ds_placement(array $r, ?string $json, int $pageCount): array
{
    $p = $json ? json_decode($json, true) : null;
    if (is_array($p) && isset($p['page'], $p['x'], $p['y'])) {
        $page = max(0, min($pageCount - 1, (int) $p['page']));
        $w = max(0.08, min(0.6, (float) ($p['w'] ?? 0.28)));
        $h = max(0.03, min(0.25, (float) ($p['h'] ?? 0.075)));
        $x = max(0, min(1 - $w, (float) $p['x']));
        $y = max(0, min(1 - $h, (float) $p['y']));
        return [$page, $x, $y, $w, $h];
    }
    $slot = (int) (fabo_one('SELECT COUNT(*) n FROM ds_signature_requests WHERE doc_id = ? AND cycle = ? AND (stage_seq < ? OR (stage_seq = ? AND seq < ?)) AND action <> "review"',
                            'iiiii', [(int) $r['doc_id'], (int) $r['cycle'], (int) $r['stage_seq'], (int) $r['stage_seq'], (int) $r['seq']])['n'] ?? 0);
    $col = $slot % 3;
    $row = intdiv($slot, 3);
    return [$pageCount - 1, 0.04 + $col * 0.32, max(0.05, 0.86 - $row * 0.085), 0.29, 0.07];
}

/**
 * The fields the sender can place in the studio: key => [label, default
 * width, default height] (fractions of the page as displayed).
 */
function ds_field_kinds(): array
{
    return [
        'signature'   => ['Signature', 0.26, 0.07],
        'initials'    => ['Initials', 0.09, 0.05],
        'name'        => ['Full name', 0.24, 0.03],
        'date'        => ['Date signed', 0.15, 0.03],
        'designation' => ['Designation', 0.24, 0.03],
    ];
}

/** Placed fields from the studio, validated: [{kind, page, x, y, w, h}], at most 60. */
function ds_fields_clean($spots): array
{
    $out = [];
    foreach (is_array($spots) ? $spots : [] as $s) {
        if (!is_array($s) || !isset(ds_field_kinds()[$s['kind'] ?? ''], $s['page'], $s['x'], $s['y'])) {
            continue;
        }
        $w = max(0.03, min(0.8, (float) ($s['w'] ?? ds_field_kinds()[$s['kind']][1])));
        $h = max(0.012, min(0.3, (float) ($s['h'] ?? ds_field_kinds()[$s['kind']][2])));
        $out[] = ['kind' => $s['kind'], 'page' => max(0, min(9999, (int) $s['page'])), 'x' => round(max(0, min(1 - $w, (float) $s['x'])), 4),
                  'y' => round(max(0, min(1 - $h, (float) $s['y'])), 4), 'w' => round($w, 4), 'h' => round($h, 4)];
        if (count($out) >= 60) {
            break;
        }
    }
    return $out;
}

/** The fields the sender placed for this request ([] when none: the signatory, or the foot of the last page, decides). */
function ds_request_fields(array $r): array
{
    $p = json_decode((string) ($r['placement_json'] ?? ''), true);
    return is_array($p) && array_is_list($p) ? ds_fields_clean($p) : [];
}

/**
 * Fills a request's placed fields into an update. Signature fields carry the
 * mark for this method (a drawn image, an approval box) — except for a
 * digital signature, whose first signature field becomes the signature
 * itself (returned) and any further ones a note pointing to it. Names,
 * dates, designations and initials are written in as text (initials drawn
 * when the signatory drew). Returns [the first signature field or null,
 * where everything went].
 */
function ds_apply_fields(DsPdfUpdate $u, array $r, array $fields, string $method, ?string $png, array $boxLines, string $accent, ?array $typed = null): array
{
    $pages = count($u->pdf->pages());
    // What the signatory typed (full name, date, designation) is what goes on the page.
    $name = $typed['name'] ?? ds_req_name($r);
    $kinds = array_column($fields, 'kind');
    $caption = in_array('name', $kinds, true) || in_array('date', $kinds, true) ? [] : [[$name], [$typed['date'] ?? date('d M Y')]];
    $parts = preg_split('/\s+/', trim($name));
    $initials = mb_strtoupper(implode('', array_map(static fn($p) => mb_substr($p, 0, 1), array_slice(array_filter($parts), 0, 3))));
    $digital = ds_method_is_digital($method);
    $sig = null;
    $where = [];
    foreach ($fields as $i => $f) {
        $page = min($f['page'], $pages - 1);
        [$rect, $rot] = $u->rectFor($page, $f['x'], $f['y'], $f['w'], $f['h']);
        $where[] = ['kind' => $f['kind'], 'page' => $page + 1, 'rect' => $rect];
        $nm = 'ds-req-' . $r['id'] . '-' . $i;
        switch ($f['kind']) {
            case 'signature':
                if ($digital) {
                    if ($sig === null) {
                        $sig = ['page' => $page, 'rect' => $rect, 'rot' => $rot];
                    } else {
                        $u->stamp($page, $rect, $rot, [['Digitally signed by ' . $name, 7, true]], null, $nm, $accent, 'text');
                    }
                } elseif ($method === 'drawn') {
                    $u->stamp($page, $rect, $rot, $caption, $png, $nm, $accent, 'ink');
                } else {
                    $u->stamp($page, $rect, $rot, $boxLines, null, $nm, $accent);
                }
                break;
            case 'initials':
                if ($method === 'drawn' && $png !== null) {
                    $u->stamp($page, $rect, $rot, [], $png, $nm, $accent, 'ink');
                } else {
                    $u->stamp($page, $rect, $rot, [[$initials, 0, true]], null, $nm, $accent, 'text');
                }
                break;
            case 'name':
                $u->stamp($page, $rect, $rot, [[$name, 0, false]], null, $nm, $accent, 'text');
                break;
            case 'date':
                $u->stamp($page, $rect, $rot, [[$typed['date'] ?? date('d M Y'), 0, false]], null, $nm, $accent, 'text');
                break;
            case 'designation':
                $u->stamp($page, $rect, $rot, [[($typed['designation'] ?? '') !== '' ? $typed['designation'] : (ds_designation(!empty($r['external_id']) ? 0 : (int) $r['person_id']) ?: '—'), 0, false]], null, $nm, $accent, 'text');
                break;
        }
    }
    return [$sig, $where];
}

/**
 * Appends a request's marks to the document's working file under a row lock
 * (so two people approving at once each add to the other's result) and makes
 * it the new working file: the sender's placed fields when there are any,
 * else one mark where the signatory chose (or the foot of the last page).
 * Returns [new file id, sha256 before, sha256 after, where].
 */
function ds_stamp_working(int $docId, array $r, ?string $placeJson, array $lines, ?string $png, string $accent, string $method = 'eapproval', ?array $typed = null): array
{
    $row = fabo_one('SELECT working_file_id, ref_no FROM ds_documents WHERE id = ? FOR UPDATE', 'i', [$docId]);
    $before = ds_read((int) $row['working_file_id']);
    $pdf = new DsPdf($before);
    $u = new DsPdfUpdate($pdf);
    $fields = ds_request_fields($r);
    if ($fields) {
        [, $where] = ds_apply_fields($u, $r, $fields, $method, $png, $lines, $accent, $typed);
    } else {
        [$page, $x, $y, $w, $h] = ds_placement($r, $placeJson, count($pdf->pages()));
        [$rect, $rot] = $u->rectFor($page, $x, $y, $w, $h);
        if ($method === 'drawn') {
            $u->stamp($page, $rect, $rot, [[$typed['name'] ?? ds_req_name($r)], [$typed['date'] ?? date('d M Y')]], $png, 'ds-req-' . $r['id'], $accent, 'ink');
        } else {
            $u->stamp($page, $rect, $rot, $lines, $png, 'ds-req-' . $r['id'], $accent);
        }
        $where = [['kind' => 'signature', 'page' => $page + 1, 'rect' => $rect]];
    }
    $after = $u->build()['pdf'];
    $fid = ds_store($after, 'working', $row['ref_no'] . '.pdf', $docId);
    fabo_exec('UPDATE ds_documents SET working_file_id = ? WHERE id = ?', 'ii', [$fid, $docId]);
    return [$fid, hash('sha256', $before), hash('sha256', $after), $where];
}

/* ============================================ what the signatory types */

/** Does this request ask the signatory to type their name and date (and which extras)? */
function ds_typed_needs(array $r, string $method): array
{
    $kinds = array_column(ds_request_fields($r), 'kind');
    $nameDate = $method === 'drawn' || in_array('name', $kinds, true) || in_array('date', $kinds, true);
    return ['name' => $nameDate, 'date' => $nameDate, 'designation' => in_array('designation', $kinds, true)];
}

/** The full name, date and designation the signatory typed, checked (null when none are asked). */
function ds_typed_clean(array $post, array $r, string $method): ?array
{
    $need = ds_typed_needs($r, $method);
    if (!$need['name'] && !$need['designation']) {
        return null;
    }
    $out = [];
    if ($need['name']) {
        $name = trim(preg_replace('/\s+/u', ' ', (string) ($post['typed_name'] ?? '')));
        if (mb_strlen($name) < 2) {
            throw new InvalidArgumentException('Type your full name.');
        }
        $out['name'] = mb_substr($name, 0, 120);
        $d = (string) ($post['typed_date'] ?? '');
        $ts = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? strtotime($d . ' 12:00:00') : false;
        if (!$ts || $d < '2000-01-01' || $d > '2100-12-31') {
            throw new InvalidArgumentException('Enter the date.');
        }
        $out['date'] = date('d M Y', $ts);
    }
    if ($need['designation']) {
        $des = trim((string) ($post['typed_designation'] ?? ''));
        if ($des === '') {
            throw new InvalidArgumentException('Type your designation.');
        }
        $out['designation'] = mb_substr($des, 0, 120);
    }
    return $out;
}

/** The boxes for them on a signing form. */
function ds_typed_html(array $r, string $method): string
{
    $need = ds_typed_needs($r, $method);
    $h = '';
    if ($need['name']) {
        $h .= '<div class="ds-row2 ds-typed">' . ds_field('Full name', '<input name="typed_name" maxlength="120" required autocomplete="name" placeholder="Your full name">')
            . ds_field('Date', '<input type="date" name="typed_date" required>') . '</div>';
    }
    if ($need['designation']) {
        $h .= ds_field('Designation', '<input name="typed_designation" maxlength="120" required autocomplete="organization-title" placeholder="Your designation">');
    }
    return $h;
}

/* ===================================================== approval and drawn */

/**
 * Electronic approval or drawn signature, by the signatory signed in to the
 * portal (no password again — the organisation chose that). Both are stamped on the PDF (an approval only when the organisation
 * stamps approvals) and sealed with what was approved.
 */
function ds_sign_simple(int $reqId, string $method, string $unused, string $remarks, ?string $pngData, ?string $placeJson, $docVersion, array $post = []): string
{
    [$r, $doc] = ds_request_mine($reqId, (int) $docVersion);
    if (!in_array($method, ['eapproval', 'drawn'], true) || !in_array($method, ds_allowed_methods($r), true)) {
        throw new InvalidArgumentException('This request cannot be completed by ' . strtolower(ds_method_label($method)) . '.');
    }
    $remarks = ds_str($remarks, 1000, false, 'Remarks');
    $png = null;
    if ($method === 'drawn') {
        $png = ds_drawn_png((string) $pngData);
    }
    $typed = ds_typed_clean($post, $r, $method);
    $stamp = $method === 'drawn' || ds_setting('stamp_approvals') === '1';
    if ($stamp) {
        ds_crypto_lock_check($doc, true, (int) $r['id']);
    }
    $name = $typed['name'] ?? ds_req_name($r);
    $when = $typed['date'] ?? date('d M Y, H:i T');
    $behalf = $r['on_behalf_of_id'] ? ' for ' . ds_person_name((int) $r['on_behalf_of_id']) : '';
    $lines = $method === 'drawn'
        ? [[$name . $behalf, 7.5, true], [$typed['designation'] ?? (ds_designation((int) $r['person_id']) ?: 'Signatory'), 6.5], [$when, 6.2], ['Drawn signature · ' . $doc['ref_no'], 5.6]]
        : [['APPROVED' . ($r['action'] === 'sign' ? ' (e-signature)' : ''), 8, true], [$name . $behalf, 7, true], [$when, 6.2], ['Electronic approval · ' . $doc['ref_no'], 5.6]];
    $status = $r['action'] === 'approve' ? 'approved' : 'signed';
    ds_tx(static function () use ($r, $doc, $method, $remarks, $png, $placeJson, $stamp, $lines, $status, $name, $typed) {
        $ev = ['intent' => $method === 'drawn' ? 'Signed with a drawn signature' : 'Approved electronically', 'identity' => 'signed-in portal session of ' . ds_actor(),
               'typed_by_signatory' => $typed,
               'legal_note' => 'Not a digital signature under the IT Act; recorded and sealed by this portal.'];
        if ($stamp) {
            [$fid, $shaBefore, $shaAfter, $where] = ds_stamp_working((int) $doc['id'], $r, $placeJson, $lines, $png, $method === 'drawn' ? '0.06 0.18 0.45' : '0.11 0.37 0.82', $method, $typed);
            $ev += ['pdf_sha256_before' => $shaBefore, 'pdf_sha256_after' => $shaAfter, 'stamped_on' => $where];
        } else {
            $ev['pdf_sha256'] = hash('sha256', ds_read((int) $doc['working_file_id']));
        }
        if ($png !== null) {
            $ev['drawn_image_sha256'] = hash('sha256', $png);
        }
        ds_request_done($r, $doc, $status, $method, $remarks, $ev, $name . ($method === 'drawn' ? ' signed ' : ($status === 'approved' ? ' approved ' : ' signed (electronic approval) ')) . $doc['ref_no']
            . ' — ' . ds_method_label($method));
        ds_advance((int) $doc['id']);
    });
    // When this signature completed the document, the owner gets the one "fully signed" e-mail instead.
    if ((int) $doc['owner_id'] !== ds_me() && !ds_doc_completed((int) $doc['id'])) {
        ds_notify([(int) $doc['owner_id']], 'signed', $doc['ref_no'] . ': ' . $name . ($status === 'approved' ? ' approved' : ' signed'),
            $name . ($status === 'approved' ? ' approved "' : ' signed "') . $doc['title'] . '" (' . ds_method_label($method) . ').', (int) $doc['id']);
    }
    return $status === 'approved' ? 'Approved. Thank you.' : 'Signed. Thank you.';
}

/**
 * A signature from the pad: a PNG (or JPEG) data URL, drawn or uploaded, and
 * not blank. Large images (a phone photo of a signature) are scaled down and
 * re-encoded here rather than refused; what is stamped is always a small PNG.
 */
function ds_drawn_png(string $data): string
{
    if (!preg_match('~^data:image/(png|jpe?g|webp);base64,([A-Za-z0-9+/=\s]+)$~', trim($data), $m)) {
        throw new InvalidArgumentException('Draw your signature in the box, or upload an image of it, first.');
    }
    $bin = (string) base64_decode(preg_replace('/\s+/', '', $m[2]), true);
    if ($bin === '' || strlen($bin) > 12000000) {
        throw new InvalidArgumentException('That signature image is too large. Use a smaller photo or draw it instead.');
    }
    if (!function_exists('imagecreatefromstring')) {
        if (strncmp($bin, "\x89PNG", 4) !== 0) {
            throw new InvalidArgumentException('Upload the signature as a PNG, or draw it instead.');
        }
        return $bin;
    }
    $im = @imagecreatefromstring($bin);
    if (!$im) {
        throw new InvalidArgumentException('That signature image could not be opened. Try another image, or draw it instead.');
    }
    if (!imageistruecolor($im)) {
        imagepalettetotruecolor($im);
    }
    $w = imagesx($im);
    $h = imagesy($im);
    // Scale down to what a signature box needs.
    $k = min(1, 900 / max(1, $w), 360 / max(1, $h));
    if ($k < 1) {
        $nw = max(1, (int) round($w * $k));
        $nh = max(1, (int) round($h * $k));
        $sm = imagecreatetruecolor($nw, $nh);
        imagealphablending($sm, false);
        imagesavealpha($sm, true);
        imagefill($sm, 0, 0, imagecolorallocatealpha($sm, 255, 255, 255, 127));
        imagecopyresampled($sm, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($im);
        $im = $sm;
        $w = $nw;
        $h = $nh;
    }
    $ink = 0;
    for ($y = 0; $y < $h; $y += 2) {
        for ($x = 0; $x < $w; $x += 2) {
            $c = imagecolorat($im, $x, $y);
            $a = ($c >> 24) & 0x7f;
            $lum = 0.299 * (($c >> 16) & 255) + 0.587 * (($c >> 8) & 255) + 0.114 * ($c & 255);
            if ($a < 100 && $lum < 200) {
                $ink++;
            }
        }
    }
    if ($w < 30 || $h < 10 || $ink < 8) {
        imagedestroy($im);
        throw new InvalidArgumentException('The signature is empty or too small. Draw your signature, or upload a clearer image of it.');
    }
    imagealphablending($im, false);
    imagesavealpha($im, true);
    ob_start();
    imagepng($im, null, 9);
    $png = (string) ob_get_clean();
    imagedestroy($im);
    return $png;
}

/* ================================================================== eSign */

/** The ASP's private key from its file (and passphrase variable), or null. */
function ds_asp_key(array $c)
{
    $path = (string) ($c['asp_key_path'] ?? '');
    if ($path === '' || !is_file($path) || !is_readable($path)) {
        return null;
    }
    $pass = !empty($c['asp_key_pass_env']) ? (string) getenv((string) $c['asp_key_pass_env']) : '';
    $k = @openssl_pkey_get_private((string) file_get_contents($path), $pass !== '' ? $pass : null);
    return $k ?: null;
}

/**
 * Starts an Aadhaar eSign: prepares the PDF with an empty signature field,
 * hashes the bytes it will cover, and builds the signed request for the
 * ESP. Returns the gateway URL and the form fields to post there.
 */
function ds_esign_start(int $reqId, ?string $placeJson, bool $consent, $docVersion): array
{
    [$r, $doc] = ds_request_mine($reqId, (int) $docVersion);
    if (!in_array('esign', ds_allowed_methods($r), true)) {
        throw new InvalidArgumentException('Aadhaar eSign is not available for this request.');
    }
    if (!$consent) {
        throw new InvalidArgumentException('Give your consent to authenticate with Aadhaar before signing.');
    }
    $p = ds_provider_for('esign');
    [$ok, $bad] = ds_provider_check($p);
    if (!$ok) {
        throw new InvalidArgumentException('Aadhaar eSign is not fully set up: ' . implode(' ', $bad) . ' Ask your administrator.');
    }
    ds_crypto_lock_check($doc, true, (int) $r['id']);
    $c = $p['config'];
    $name = ds_req_name($r);
    return ds_tx(static function () use ($r, $doc, $p, $c, $placeJson, $name) {
        $row = fabo_one('SELECT working_file_id FROM ds_documents WHERE id = ? FOR UPDATE', 'i', [(int) $doc['id']]);
        $base = ds_read((int) $row['working_file_id']);
        $pdf = new DsPdf($base);
        $u = new DsPdfUpdate($pdf);
        // The sender's placed fields: the first signature field is where the signature goes; the rest are filled in the same revision, so they are signed too.
        [$sigAt] = ds_apply_fields($u, $r, ds_request_fields($r), 'esign', null, [], '0.07 0.5 0.36');
        if ($sigAt) {
            [$page, $rect, $rot] = [$sigAt['page'], $sigAt['rect'], $sigAt['rot']];
        } else {
            [$page, $x, $y, $w, $h] = ds_placement($r, $placeJson, count($pdf->pages()));
            [$rect, $rot] = $u->rectFor($page, $x, $y, $w, $h);
        }
        $u->signatureField($page, $rect, $rot, [['Digitally signed (Aadhaar eSign)', 7.5, true], [$name, 7, true], ['Date: ' . date('d M Y, H:i T'), 6.2],
            [$doc['ref_no'] . ' · via ' . ($c['esp_name'] ?? 'ESP'), 5.6]], 'ESign_' . $r['id'] . '_' . $r['cycle'],
            ['Name' => $name, 'Reason' => 'Signed ' . $doc['ref_no'] . ' (' . ($r['stage_name'] ?? '') . ')', 'Location' => ds_org_name()],
            max(8, min(64, (int) ($c['reserve_kb'] ?? 16))) * 1024, ds_setting('subfilter'));
        $built = $u->build();
        $hash = hash('sha256', DsPdfUpdate::covered($built['pdf'], $built['range']));
        $prepId = ds_store($built['pdf'], 'prepared', $doc['ref_no'] . '-prepared.pdf', (int) $doc['id']);
        $txn = 'DS' . ds_org() . '-' . date('ymdHis') . '-' . bin2hex(random_bytes(6));
        $xml = ds_esign_request_xml($c, $txn, $hash, $doc['ref_no'] . ' ' . mb_substr($doc['title'], 0, 60));
        $tid = fabo_exec('INSERT INTO ds_signature_transactions (org_id, request_id, doc_id, provider_id, provider, txn_ref, status, base_file_id, prepared_file_id, byte_range,
                           hex_at, hex_len, doc_hash, request_digest, ip, user_agent, initiated_by_id, expires_at)
                          VALUES (?,?,?,?,?,?,"initiated",?,?,?,?,?,?,?,?,?,?, DATE_ADD(NOW(), INTERVAL 15 MINUTE))', 'iiiissiisiissssi',
                         [ds_org(), (int) $r['id'], (int) $doc['id'], $p['id'], 'esign_cca', $txn, (int) $row['working_file_id'], $prepId, implode(' ', $built['range']),
                          $built['hex_at'], $built['hex_len'], $hash, hash('sha256', $xml), PHP_SAPI === 'cli' ? 'cli' : fabo_client_ip(),
                          mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), ds_me()]);
        ds_audit((int) $doc['id'], 'transaction', $tid, 'esign_start', $name . ' started Aadhaar eSign of ' . $doc['ref_no'] . ' with ' . ($c['esp_name'] ?? 'the ESP')
            . ' (' . $p['environment'] . ')', ['txn' => $txn, 'document_hash' => $hash, 'consent' => 'given']);
        return ['url' => (string) $c['gateway_url'], 'fields' => [($c['request_field'] ?? 'msg') ?: 'msg' => $xml], 'txn' => $txn];
    });
}

/** The eSign request XML (CCA eSign API 2.1 / 3.2), signed with the ASP key. */
function ds_esign_request_xml(array $c, string $txn, string $hash, string $docInfo): string
{
    $dom = new DOMDocument('1.0', 'UTF-8');
    $root = $dom->createElement('Esign');
    $attrs = ['ver' => (string) ($c['api_version'] ?? '2.1'), 'sc' => 'Y', 'ts' => date('Y-m-d\TH:i:s'), 'txn' => $txn, 'ekycId' => '', 'ekycIdType' => 'A',
              'aspId' => (string) $c['asp_id'], 'AuthMode' => (string) ($c['auth_mode'] ?? '1'), 'responseSigType' => 'pkcs7', 'preVerified' => 'n',
              'organizationFlag' => 'n', 'responseUrl' => ds_abs_url('sign-callback.php')];
    foreach ($attrs as $k => $v) {
        $root->setAttribute($k, $v);
    }
    $dom->appendChild($root);
    $docs = $dom->createElement('Docs');
    $ih = $dom->createElement('InputHash', $hash);
    $ih->setAttribute('id', '1');
    $ih->setAttribute('hashAlgorithm', 'SHA256');
    $ih->setAttribute('docInfo', preg_replace('/[^\p{L}\p{N} .,\/()-]/u', '', $docInfo));
    $docs->appendChild($ih);
    $root->appendChild($docs);
    $key = ds_asp_key($c);
    if (!$key) {
        throw new RuntimeException('The ASP private key cannot be read.');
    }
    $cert = !empty($c['asp_cert_path']) && is_readable((string) $c['asp_cert_path']) ? (string) file_get_contents((string) $c['asp_cert_path']) : null;
    ds_xmldsig_sign($dom, $key, $cert);
    return $dom->saveXML($dom->documentElement);
}

const DS_XMLDSIG_NS = 'http://www.w3.org/2000/09/xmldsig#';

/** Enveloped XML-DSig over the whole document: C14N, RSA-SHA256, SHA-256 digest. */
function ds_xmldsig_sign(DOMDocument $dom, $key, ?string $certPem): void
{
    $digest = base64_encode(hash('sha256', $dom->documentElement->C14N(false, false), true));
    $sig = $dom->createElementNS(DS_XMLDSIG_NS, 'Signature');
    $dom->documentElement->appendChild($sig);
    $si = $dom->createElementNS(DS_XMLDSIG_NS, 'SignedInfo');
    $sig->appendChild($si);
    $cm = $dom->createElementNS(DS_XMLDSIG_NS, 'CanonicalizationMethod');
    $cm->setAttribute('Algorithm', 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315');
    $si->appendChild($cm);
    $sm = $dom->createElementNS(DS_XMLDSIG_NS, 'SignatureMethod');
    $sm->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256');
    $si->appendChild($sm);
    $ref = $dom->createElementNS(DS_XMLDSIG_NS, 'Reference');
    $ref->setAttribute('URI', '');
    $si->appendChild($ref);
    $tr = $dom->createElementNS(DS_XMLDSIG_NS, 'Transforms');
    $ref->appendChild($tr);
    $t = $dom->createElementNS(DS_XMLDSIG_NS, 'Transform');
    $t->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#enveloped-signature');
    $tr->appendChild($t);
    $dm = $dom->createElementNS(DS_XMLDSIG_NS, 'DigestMethod');
    $dm->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmlenc#sha256');
    $ref->appendChild($dm);
    $ref->appendChild($dom->createElementNS(DS_XMLDSIG_NS, 'DigestValue', $digest));
    $value = '';
    if (!openssl_sign($si->C14N(false, false), $value, $key, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('The eSign request could not be signed with the ASP key.');
    }
    $sig->appendChild($dom->createElementNS(DS_XMLDSIG_NS, 'SignatureValue', base64_encode($value)));
    if ($certPem) {
        $ki = $dom->createElementNS(DS_XMLDSIG_NS, 'KeyInfo');
        $xd = $dom->createElementNS(DS_XMLDSIG_NS, 'X509Data');
        $xd->appendChild($dom->createElementNS(DS_XMLDSIG_NS, 'X509Certificate', base64_encode(ds_unpem($certPem))));
        $ki->appendChild($xd);
        $sig->appendChild($ki);
    }
}

/** Parses XML safely: no DTD, no entities, no network. */
function ds_xml_load(string $xml): ?DOMDocument
{
    if ($xml === '' || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false || strlen($xml) > 5_000_000) {
        return null;
    }
    $dom = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return $ok ? $dom : null;
}

/**
 * Checks an enveloped XML-DSig against a certificate: the SignedInfo
 * signature and the whole-document digest (inclusive or exclusive C14N,
 * SHA-1 or SHA-256 as the ESP used).
 */
function ds_xmldsig_verify(DOMDocument $dom, string $certPem): bool
{
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('ds', DS_XMLDSIG_NS);
    $sig = $xp->query('/*/ds:Signature')->item(0);
    if (!$sig) {
        return false;
    }
    $si = $xp->query('ds:SignedInfo', $sig)->item(0);
    $sv = $xp->query('ds:SignatureValue', $sig)->item(0);
    $ref = $xp->query('ds:SignedInfo/ds:Reference', $sig);
    if (!$si || !$sv || $ref->length !== 1 || $ref->item(0)->getAttribute('URI') !== '') {
        return false;
    }
    $c14nAlg = (string) $xp->query('ds:CanonicalizationMethod', $si)->item(0)?->getAttribute('Algorithm');
    $exclusive = str_contains($c14nAlg, 'xml-exc-c14n');
    $sigAlg = (string) $xp->query('ds:SignatureMethod', $si)->item(0)?->getAttribute('Algorithm');
    $alg = str_ends_with($sigAlg, 'rsa-sha256') ? OPENSSL_ALGO_SHA256 : (str_ends_with($sigAlg, 'rsa-sha1') ? OPENSSL_ALGO_SHA1 : null);
    if ($alg === null) {
        return false;
    }
    $key = @openssl_pkey_get_public($certPem);
    if (!$key || openssl_verify($si->C14N($exclusive, false), (string) base64_decode(preg_replace('/\s+/', '', $sv->textContent)), $key, $alg) !== 1) {
        return false;
    }
    $r = $ref->item(0);
    $dAlg = (string) $xp->query('ds:DigestMethod', $r)->item(0)?->getAttribute('Algorithm');
    $hash = str_ends_with($dAlg, 'sha256') ? 'sha256' : (str_ends_with($dAlg, 'sha1') ? 'sha1' : null);
    $dv = trim((string) $xp->query('ds:DigestValue', $r)->item(0)?->textContent);
    if (!$hash || $dv === '') {
        return false;
    }
    $copy = new DOMDocument();
    $copy->loadXML($dom->saveXML(), LIBXML_NONET);
    $xp2 = new DOMXPath($copy);
    $xp2->registerNamespace('ds', DS_XMLDSIG_NS);
    $s2 = $xp2->query('/*/ds:Signature')->item(0);
    $s2->parentNode->removeChild($s2);
    $tAlgs = [];
    foreach ($xp->query('ds:Transforms/ds:Transform', $r) as $t) {
        $tAlgs[] = $t->getAttribute('Algorithm');
    }
    $excl = (bool) array_filter($tAlgs, static fn($a) => str_contains($a, 'xml-exc-c14n'));
    return hash_equals(base64_encode(hash($hash, $copy->documentElement->C14N($excl, false), true)), preg_replace('/\s+/', '', $dv));
}

/**
 * The ESP's response, posted back by the signatory's browser. Verified
 * before anything is believed: the XML signature against the ESP's
 * certificate, the transaction it names, its expiry, its status; then the
 * PKCS #7 against the exact bytes prepared; then the whole signed PDF.
 * Returns [txn row|null, ok, message]. A second delivery of the same
 * response changes nothing.
 */
function ds_esign_callback(string $raw): array
{
    $xml = trim($raw);
    if ($xml !== '' && $xml[0] !== '<') {
        $dec = base64_decode($xml, true);
        $xml = $dec !== false ? trim($dec) : $xml;
    }
    $dom = ds_xml_load($xml);
    if (!$dom || $dom->documentElement->localName !== 'EsignResp') {
        return [null, false, 'The response from the eSign provider could not be read.'];
    }
    $e = $dom->documentElement;
    $txnRef = (string) $e->getAttribute('txn');
    $t = $txnRef !== '' ? fabo_one('SELECT * FROM ds_signature_transactions WHERE txn_ref = ?', 's', [$txnRef]) : null;
    if (!$t) {
        error_log('FABO esign callback: unknown txn ' . mb_substr($txnRef, 0, 80));
        return [null, false, 'This eSign response does not belong to a signing started here.'];
    }
    ds_use((int) $t['org_id']);
    $GLOBALS['ds_system'] = true;
    $GLOBALS['ds_viewer'] = ['is_admin' => false, 'client' => fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [(int) $t['initiated_by_id']]) ?: ['id' => 0, 'name' => 'System'], 'access' => []];
    fabo_exec('UPDATE ds_signature_transactions SET callback_count = callback_count + 1 WHERE id = ?', 'i', [(int) $t['id']]);
    $docId = (int) $t['doc_id'];
    if ($t['status'] === 'completed') {
        ds_audit($docId, 'transaction', (int) $t['id'], 'callback_duplicate', 'A repeated eSign response for ' . $txnRef . ' was received and ignored');
        return [$t, true, 'This signature was already recorded.'];
    }
    if (!in_array($t['status'], ['initiated', 'awaiting'], true)) {
        ds_audit($docId, 'transaction', (int) $t['id'], 'callback_ignored', 'An eSign response for ' . $txnRef . ' arrived after the signing was ' . $t['status']);
        return [$t, false, 'This signing was ' . $t['status'] . ' before the response arrived. Start again.'];
    }
    $fail = static function (string $msg, string $code = '') use ($t, $docId) {
        fabo_exec('UPDATE ds_signature_transactions SET status = "failed", error_message = ?, response_code = ? WHERE id = ? AND status IN ("initiated","awaiting")',
                  'ssi', [mb_substr($msg, 0, 1000), $code !== '' ? mb_substr($code, 0, 40) : null, (int) $t['id']]);
        ds_audit($docId, 'transaction', (int) $t['id'], 'esign_failed', 'Aadhaar eSign failed for ' . $t['txn_ref'] . ': ' . $msg, ['code' => $code]);
        return [$t, false, $msg];
    };
    $p = $t['provider_id'] ? fabo_one('SELECT * FROM ds_providers WHERE id = ? AND org_id = ?', 'ii', [(int) $t['provider_id'], (int) $t['org_id']]) : null;
    $c = $p ? (json_decode((string) $p['config_json'], true) ?: []) : [];
    if (empty($c['esp_cert_pem']) || !ds_xmldsig_verify($dom, (string) $c['esp_cert_pem'])) {
        ds_audit($docId, 'security', (int) $t['id'], 'callback_forged', 'An eSign response for ' . $txnRef . ' failed the ESP signature check and was rejected');
        return $fail('The response is not signed by the configured eSign provider. Nothing was signed.', 'SIG');
    }
    fabo_exec('UPDATE ds_signature_transactions SET response_digest = ?, provider_ref = ? WHERE id = ?', 'ssi',
              [hash('sha256', $xml), mb_substr((string) $e->getAttribute('resCode'), 0, 120) ?: null, (int) $t['id']]);
    if (ds_ts($t['expires_at']) < time() - 120) {
        return $fail('The signing session expired before the response arrived.', 'EXP');
    }
    if ((string) $e->getAttribute('status') !== '1') {
        $code = (string) $e->getAttribute('errCode');
        $msg = (string) $e->getAttribute('errMsg');
        return $fail('The eSign provider did not sign' . ($msg !== '' && $msg !== 'NA' ? ': ' . $msg : '') . '.', $code);
    }
    $xp = new DOMXPath($dom);
    $sigNode = $xp->query('//*[local-name()="DocSignature"]')->item(0);
    $der = $sigNode ? base64_decode(preg_replace('/\s+/', '', $sigNode->textContent), true) : false;
    if (!$der) {
        return $fail('The response carries no document signature.', 'NOSIG');
    }
    $GLOBALS['ds_system'] = false;
    try {
        $prepared = ds_read((int) $t['prepared_file_id']);
        $range = array_map('intval', explode(' ', (string) $t['byte_range']));
        if (!hash_equals((string) $t['doc_hash'], hash('sha256', DsPdfUpdate::covered($prepared, $range)))) {
            throw new RuntimeException('The prepared document does not match its recorded hash.');
        }
        $signed = DsPdfUpdate::embed(['pdf' => $prepared, 'hex_at' => (int) $t['hex_at'], 'hex_len' => (int) $t['hex_len']], ds_der_raw($der, ds_der_node($der)));
        return ds_digital_complete($t, $signed, 'esign', ['esp' => $c['esp_name'] ?? null, 'environment' => $p['environment'] ?? null, 'esp_response_code' => $e->getAttribute('resCode')]);
    } catch (Throwable $ex) {
        error_log('FABO esign complete: ' . $ex->getMessage());
        return $fail($ex instanceof InvalidArgumentException ? $ex->getMessage() : 'The signature could not be applied (' . $ex->getMessage() . ').', 'APPLY');
    }
}

/**
 * Puts a returned signature into its prepared PDF and accepts it only when
 * the result verifies: the new signature is intact over exactly the
 * prepared bytes, the signed PDF is the prepared file plus nothing else,
 * earlier signatures still hold, and the certificate is good under the
 * organisation's trust policy.
 */
function ds_digital_complete(array $t, string $signed, string $method, array $extra): array
{
    $docId = (int) $t['doc_id'];
    return ds_tx(static function () use ($t, $signed, $method, $extra, $docId) {
        $doc = fabo_one('SELECT * FROM ds_documents WHERE id = ? FOR UPDATE', 'i', [$docId]);
        $r = fabo_one('SELECT * FROM ds_signature_requests WHERE id = ? FOR UPDATE', 'i', [(int) $t['request_id']]);
        if (!$doc || !$r || $r['status'] !== 'pending' || !in_array($doc['status'], ds_open_statuses(), true)) {
            throw new InvalidArgumentException('The request is no longer waiting for this signature.');
        }
        if ((int) $doc['working_file_id'] !== (int) $t['base_file_id']) {
            throw new InvalidArgumentException('The document changed while it was being signed. Sign again.');
        }
        // A DSC copy may have been prepared (placed fields, an empty signature field): it must extend that copy.
        $base = ds_read((int) ($t['provider'] === 'dsc_upload' && $t['prepared_file_id'] ? $t['prepared_file_id'] : $t['base_file_id']));
        if (strlen($signed) <= strlen($base) || strncmp($signed, $base, strlen($base)) !== 0) {
            throw new InvalidArgumentException('The signed file is not the document prepared for signing with a signature added.');
        }
        [$sig, $report] = ds_accept_new_signature($signed, strlen($base), (int) $r['person_id'], $method);
        $fid = ds_store($signed, 'working', $doc['ref_no'] . '.pdf', $docId);
        $sigFile = $fid;
        // A DSC signed on the page's copy (not the prepared one) skipped the sender's placed fields:
        // they are filled in now, after the signature, as annotations — which leave it valid.
        $fields = ds_request_fields($r);
        if ($method === 'dsc' && !$t['prepared_file_id'] && $fields) {
            $u = new DsPdfUpdate(new DsPdf($signed));
            [$sigAt] = ds_apply_fields($u, $r, $fields, 'dsc', null, [], '0.07 0.5 0.36');
            if ($sigAt) {
                $u->stamp($sigAt['page'], $sigAt['rect'], $sigAt['rot'], [['Digitally signed by ' . ds_req_name($r) . ' (DSC)', 7, true]], null,
                          'ds-req-' . $r['id'] . '-sig', '0.07 0.5 0.36', 'text');
            }
            $fid = ds_store($u->build()['pdf'], 'working', $doc['ref_no'] . '.pdf', $docId);
        }
        fabo_exec('UPDATE ds_documents SET working_file_id = ? WHERE id = ?', 'ii', [$fid, $docId]);
        fabo_exec('UPDATE ds_signature_transactions SET status = "completed", output_file_id = ?, completed_at = NOW(), error_message = NULL WHERE id = ?', 'ii', [$sigFile, (int) $t['id']]);
        $s = $sig['signer'];
        ds_cert_observe($s, (int) $r['person_id']);
        $ev = $extra + ['intent' => 'Digitally signed', 'transaction' => $t['txn_ref'], 'document_hash' => $t['doc_hash'], 'pdf_sha256_after' => hash('sha256', $signed),
                        'certificate' => ['subject' => $s['cn'], 'serial' => $s['serial'], 'issuer' => $s['issuer_cn'], 'fingerprint' => $s['fingerprint'],
                                          'valid_to' => gmdate('c', $s['not_after'])],
                        'signing_time' => $sig['signing_time'] ? gmdate('c', (int) $sig['signing_time']) : null, 'verification' => $sig['verdict'],
                        'chain' => $sig['chain']['status'] ?? null, 'revocation' => $sig['revocation']['status'] ?? null, 'pades' => $sig['pades']];
        $nameNote = ds_name_matches($s['cn'], ds_req_name($r)) ? '' : ' (certificate name "' . $s['cn'] . '" differs from the signatory\'s portal name)';
        if ($nameNote !== '') {
            $ev['name_mismatch'] = true;
        }
        $GLOBALS['ds_viewer'] = ['is_admin' => (int) $r['person_id'] === 0, 'client' => fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [(int) $r['person_id']]), 'access' => []];
        ds_request_done($r, $doc, 'signed', $method, '', $ev, ds_req_name($r) . ' digitally signed ' . $doc['ref_no'] . ' with ' . ds_method_label($method)
            . ' — certificate of ' . $s['cn'] . ' issued by ' . $s['issuer_cn'] . $nameNote);
        ds_advance($docId);
        if ((int) $doc['owner_id'] !== (int) $r['person_id'] && !ds_doc_completed($docId)) {
            ds_notify([(int) $doc['owner_id']], 'signed', $doc['ref_no'] . ': ' . ds_req_name($r) . ' signed',
                ds_req_name($r) . ' digitally signed "' . $doc['title'] . '" (' . ds_method_label($method) . ')' . $nameNote . '.', $docId);
        }
        return [$t, true, 'Digitally signed. The signature was verified and added to the document.'];
    });
}

/**
 * The checks every incoming digital signature must pass. $baseLen is the
 * length of the file before it; $personId the signatory. Returns the new
 * signature's report and the whole report, or refuses with the reason.
 */
function ds_accept_new_signature(string $signed, int $baseLen, int $personId, string $method): array
{
    $report = ds_verify_pdf($signed, ['anchors' => ds_anchors(), 'fetch' => ds_fetcher()]);
    $new = array_values(array_filter($report['signatures'], static fn($s) => $s['revision_len'] > $baseLen));
    if (count($new) !== 1) {
        throw new InvalidArgumentException(count($new) === 0 ? 'No new digital signature was found in the file.' : 'The file holds more than one new signature; sign once and upload that.');
    }
    $sig = $new[0];
    if ($sig['kind'] !== 'signature') {
        throw new InvalidArgumentException('The new signature is a document timestamp, not a signature.');
    }
    if ($sig['integrity'] !== 'ok') {
        throw new InvalidArgumentException('The new signature does not verify over the document. Nothing was recorded.');
    }
    if (!$sig['covers_whole']) {
        throw new InvalidArgumentException('Something was added to the file after it was signed. Upload the file exactly as your signing software saved it.');
    }
    $changes = ds_changes_after($signed, $baseLen, null);
    if ($changes['status'] === 'modified') {
        throw new InvalidArgumentException('The document content was changed while signing (' . implode('; ', array_slice($changes['details'], 0, 3)) . '). Download it again and only sign it.');
    }
    foreach ($report['signatures'] as $old) {
        if ($old['revision_len'] <= $baseLen && in_array($old['verdict'], ['modified', 'invalid'], true)) {
            throw new InvalidArgumentException('Signing broke an earlier signature on the document. Download it again and sign without changing it.');
        }
    }
    if ($sig['verdict'] === 'invalid') {
        throw new InvalidArgumentException('The signature is not valid: ' . ds_first_fail($sig) . '.');
    }
    if ($sig['verdict'] === 'expired') {
        throw new InvalidArgumentException('The signing certificate was not valid at the time of signing. Renew your certificate.');
    }
    if ($sig['verdict'] === 'revoked') {
        throw new InvalidArgumentException('The signing certificate has been revoked by its issuer.');
    }
    if (ds_setting('require_trusted') === '1' && ($sig['chain']['status'] ?? '') !== 'trusted') {
        throw new InvalidArgumentException('The certificate does not chain to a trusted certifying authority (' . ($sig['chain']['message'] ?? '') . '). '
            . 'Only certificates from CCA-licensed authorities are accepted; the administrator manages the trust store.');
    }
    if ($method === 'dsc') {
        ds_dsc_identity_check($sig['signer'], $personId);
    } elseif (ds_setting('esign_name_match') === 'block' && !ds_name_matches($sig['signer']['cn'], ds_person_name($personId))) {
        throw new InvalidArgumentException('The name on the eSign certificate ("' . $sig['signer']['cn'] . '") does not match the signatory.');
    }
    return [$sig, $report];
}

function ds_first_fail(array $sig): string
{
    foreach ($sig['checks'] as $c) {
        if ($c['state'] === 'fail') {
            return rtrim($c['message'], '.');
        }
    }
    return 'it failed verification';
}

/** Do two names refer to the same person? Order, case, titles and initials aside. */
function ds_name_matches(string $a, string $b): bool
{
    $norm = static function (string $s): array {
        $s = mb_strtolower(preg_replace('/\b(mr|mrs|ms|dr|shri|smt|sri|prof)\.?\s+/i', '', $s));
        $parts = preg_split('/[^\p{L}]+/u', $s, -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_filter($parts, static fn($p) => mb_strlen($p) > 1));
    };
    $x = $norm($a);
    $y = $norm($b);
    if (!$x || !$y) {
        return false;
    }
    $common = array_intersect($x, $y);
    return count($common) >= min(2, min(count($x), count($y)));
}

/**
 * Is this DSC the signatory's? Under the organisation's policy: a
 * certificate registered to them in the directory; or (default) also one
 * whose e-mail or name matches theirs.
 */
function ds_dsc_identity_check(array $cert, int $personId): void
{
    $policy = ds_setting('dsc_identity');
    $reg = fabo_one('SELECT person_id FROM ds_certificates WHERE org_id = ? AND fingerprint = ? AND source = "registered"', 'is', [ds_org(), $cert['fingerprint']]);
    if ($reg) {
        if ((int) $reg['person_id'] !== $personId) {
            throw new InvalidArgumentException('This certificate is registered to ' . ds_person_name((int) $reg['person_id']) . ', not to you. Nobody may sign with another person\'s certificate.');
        }
        return;
    }
    if ($policy === 'registered') {
        throw new InvalidArgumentException('Your DSC is not registered in the signatory directory. Ask the administrator to register certificate ' . substr($cert['fingerprint'], 0, 16) . '… to you.');
    }
    $email = mb_strtolower(ds_person_email($personId));
    if ($email !== '' && mb_strtolower($cert['email']) === $email) {
        return;
    }
    if (ds_name_matches($cert['cn'], ds_person_name($personId))) {
        return;
    }
    if ($policy === 'any') {
        return;
    }
    throw new InvalidArgumentException('The certificate is issued to "' . $cert['cn'] . '"' . ($cert['email'] ? ' (' . $cert['email'] . ')' : '')
        . ', which does not match you. Sign with your own DSC, or ask the administrator to register this certificate to you.');
}

/** Keeps a signer's certificate on file (observed), linked to them. */
function ds_cert_observe(array $c, int $personId): void
{
    fabo_exec('INSERT INTO ds_certificates (org_id, person_id, source, fingerprint, serial, subject_cn, subject_dn, issuer_dn, email, not_before, not_after, pem, status, checked_at)
               VALUES (?,?,"observed",?,?,?,?,?,?,?,?,?,"good",NOW()) ON DUPLICATE KEY UPDATE checked_at = NOW(), person_id = COALESCE(person_id, VALUES(person_id))',
              'iisssssssss', [ds_org(), $personId ?: null, $c['fingerprint'], $c['serial'], mb_substr($c['cn'], 0, 255), mb_substr($c['subject_dn'], 0, 600),
                              mb_substr($c['issuer_dn'], 0, 600), $c['email'] ?: null, gmdate('Y-m-d H:i:s', $c['not_before']), gmdate('Y-m-d H:i:s', $c['not_after']), $c['pem']]);
}

/* =================================================================== DSC */

/**
 * The copy a DSC signatory downloads to sign. Holds the document for them
 * (others wait) for the configured window. Returns [bytes, file name].
 */
function ds_dsc_download(int $reqId): array
{
    [$r, $doc] = ds_request_mine($reqId);
    if (!in_array('dsc', ds_allowed_methods($r), true)) {
        throw new InvalidArgumentException('DSC signing is not available for this request.');
    }
    ds_crypto_lock_check($doc, true, (int) $r['id']);
    $p = ds_provider_for('dsc');
    $mins = max(10, min(1440, (int) ($p['config']['window_minutes'] ?? 60)));
    return ds_tx(static function () use ($r, $doc, $p, $mins) {
        $row = fabo_one('SELECT working_file_id FROM ds_documents WHERE id = ? FOR UPDATE', 'i', [(int) $doc['id']]);
        $bytes = ds_read((int) $row['working_file_id']);
        $open = fabo_one('SELECT * FROM ds_signature_transactions WHERE request_id = ? AND provider = "dsc_upload" AND status = "awaiting" AND base_file_id = ? AND expires_at > NOW()',
                         'ii', [(int) $r['id'], (int) $row['working_file_id']]);
        if ($open) {
            fabo_exec('UPDATE ds_signature_transactions SET expires_at = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE id = ?', 'ii', [$mins, (int) $open['id']]);
            if ($open['prepared_file_id']) {
                $bytes = ds_read((int) $open['prepared_file_id']);
            }
        } else {
            fabo_exec('UPDATE ds_signature_transactions SET status = "expired" WHERE request_id = ? AND provider = "dsc_upload" AND status = "awaiting"', 'i', [(int) $r['id']]);
            $prepId = null;
            $fields = ds_request_fields($r);
            if ($fields) {
                // The sender's fields filled in, and an empty signature field where the signature goes: Acrobat signs into it.
                $u = new DsPdfUpdate(new DsPdf($bytes));
                [$sigAt] = ds_apply_fields($u, $r, $fields, 'dsc', null, [], '0.07 0.5 0.36');
                if ($sigAt) {
                    $u->emptySignatureField($sigAt['page'], $sigAt['rect'], $sigAt['rot'], 'Signature_' . $r['id'], 'Signature · ' . ds_req_name($r));
                }
                $bytes = $u->build()['pdf'];
                $prepId = ds_store($bytes, 'prepared', $doc['ref_no'] . '-to-sign.pdf', (int) $doc['id']);
            }
            $txn = 'DSC' . ds_org() . '-' . date('ymdHis') . '-' . bin2hex(random_bytes(5));
            $tid = fabo_exec('INSERT INTO ds_signature_transactions (org_id, request_id, doc_id, provider_id, provider, txn_ref, status, base_file_id, prepared_file_id, doc_hash, ip, user_agent, initiated_by_id, expires_at)
                              VALUES (?,?,?,?,"dsc_upload",?,"awaiting",?,?,?,?,?,?, DATE_ADD(NOW(), INTERVAL ? MINUTE))', 'iiiisiisssii',
                             [ds_org(), (int) $r['id'], (int) $doc['id'], $p['id'], $txn, (int) $row['working_file_id'], $prepId, hash('sha256', $bytes),
                              PHP_SAPI === 'cli' ? 'cli' : fabo_client_ip(), mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), ds_me(), $mins]);
            ds_audit((int) $doc['id'], 'transaction', $tid, 'dsc_download', ds_actor() . ' downloaded ' . $doc['ref_no'] . ' to sign with their DSC (held for ' . $mins . ' minutes)',
                     ['txn' => $txn, 'pdf_sha256' => hash('sha256', $bytes)]);
        }
        return [$bytes, preg_replace('/[^A-Za-z0-9._-]+/', '-', $doc['ref_no']) . '-to-sign.pdf'];
    });
}

/**
 * Which copy of the document a DSC-signed upload was made from. The usual
 * case is the copy downloaded for DSC signing (its hold still open); but a
 * signatory may also have signed the document as downloaded from the page,
 * or come back after the hold ran out. Any of these is accepted when the
 * upload is exactly that copy with something added after it, and the
 * document has not changed since (nobody else signed meanwhile). Returns the
 * transaction to complete, opening one when needed, or refuses with the reason.
 */
function ds_dsc_match(array $r, array $doc, string $bytes): array
{
    $working = (int) $doc['working_file_id'];
    $cands = fabo_all('SELECT * FROM ds_signature_transactions WHERE request_id = ? AND provider = "dsc_upload" AND status IN ("awaiting","expired") AND base_file_id = ? ORDER BY id DESC LIMIT 5',
                      'ii', [(int) $r['id'], $working]);
    foreach ($cands as $t) {
        $base = ds_read((int) ($t['prepared_file_id'] ?: $t['base_file_id']));
        if (strlen($bytes) > strlen($base) && strncmp($bytes, $base, strlen($base)) === 0) {
            if ($t['status'] !== 'awaiting' || ds_ts($t['expires_at']) < time()) {
                ds_crypto_lock_check($doc, true, (int) $r['id']);
                fabo_exec('UPDATE ds_signature_transactions SET status = "awaiting", expires_at = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE id = ?', 'i', [(int) $t['id']]);
                $t['status'] = 'awaiting';
            }
            return $t;
        }
    }
    // Signed from the document as shown on the page (not the DSC copy).
    $plain = ds_read($working);
    if (strlen($bytes) > strlen($plain) && strncmp($bytes, $plain, strlen($plain)) === 0) {
        ds_crypto_lock_check($doc, true, (int) $r['id']);
        $p = ds_provider_for('dsc');
        $txn = 'DSC' . ds_org() . '-' . date('ymdHis') . '-' . bin2hex(random_bytes(5));
        $tid = fabo_exec('INSERT INTO ds_signature_transactions (org_id, request_id, doc_id, provider_id, provider, txn_ref, status, base_file_id, doc_hash, ip, user_agent, initiated_by_id, expires_at)
                          VALUES (?,?,?,?,"dsc_upload",?,"awaiting",?,?,?,?,?, DATE_ADD(NOW(), INTERVAL 15 MINUTE))', 'iiiisisssi',
                         [ds_org(), (int) $r['id'], (int) $doc['id'], $p['id'], $txn, $working, hash('sha256', $plain),
                          PHP_SAPI === 'cli' ? 'cli' : fabo_client_ip(), mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), ds_me()]);
        ds_audit((int) $doc['id'], 'transaction', $tid, 'dsc_download', ds_actor() . ' uploaded a DSC-signed copy of ' . $doc['ref_no'] . ' made from the document as shown on the page', ['txn' => $txn]);
        return fabo_one('SELECT * FROM ds_signature_transactions WHERE id = ?', 'i', [$tid]);
    }
    ds_audit((int) $doc['id'], 'request', (int) $r['id'], 'dsc_rejected', 'An uploaded DSC copy was refused: it is not the current document with a signature added', ['sha256' => hash('sha256', $bytes)]);
    throw new InvalidArgumentException('This file is not the current version of the document with your signature added. Download it again (Download for DSC signing), sign it in Adobe Acrobat / Reader with your token and Save — do not use "Save As", print to PDF or edit it — then upload that file.');
}

/** The DSC-signed copy coming back. Accepted only as the downloaded file plus one valid signature by this signatory. */
function ds_dsc_upload(int $reqId, string $bytes, $docVersion): string
{
    [$r, $doc] = ds_request_mine($reqId, (int) $docVersion);
    if (!in_array('dsc', ds_allowed_methods($r), true)) {
        throw new InvalidArgumentException('DSC signing is not available for this request.');
    }
    $t = ds_dsc_match($r, $doc, $bytes);
    $base = ds_read((int) ($t['prepared_file_id'] ?: $t['base_file_id']));
    if (strlen($bytes) <= strlen($base) || strncmp($bytes, $base, strlen($base)) !== 0) {
        ds_audit((int) $doc['id'], 'transaction', (int) $t['id'], 'dsc_rejected', 'An uploaded DSC copy was refused: it is not the downloaded document with a signature added', ['sha256' => hash('sha256', $bytes)]);
        throw new InvalidArgumentException('This is not the copy you downloaded with a signature added. Sign the downloaded file without saving it any other way ("Save As" or printing to PDF removes the original), then upload it.');
    }
    try {
        ds_digital_complete($t, $bytes, 'dsc', ['uploaded_sha256' => hash('sha256', $bytes)]);
    } catch (InvalidArgumentException $e) {
        fabo_exec('UPDATE ds_signature_transactions SET error_message = ? WHERE id = ?', 'si', [mb_substr($e->getMessage(), 0, 1000), (int) $t['id']]);
        ds_audit((int) $doc['id'], 'transaction', (int) $t['id'], 'dsc_rejected', 'An uploaded DSC copy was refused: ' . $e->getMessage());
        throw $e;
    }
    return 'Digitally signed with your DSC. The signature was verified and recorded.';
}

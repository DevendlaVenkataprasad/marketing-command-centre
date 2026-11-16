<?php
/**
 * Incubation Management — stage certificates.
 *
 * "Your startup has reached <stage>": a certificate issued to an application
 * for the pipeline stage it is at, from one template per organisation.
 *
 *   TEMPLATE   inc_settings 'cert_template' (JSON): wording with {placeholders},
 *              accent colour, logos (uploaded images, in order) and up to three
 *              signatories, each with a signature image.
 *   STAGES     inc_settings 'cert_stages' (JSON: stage id => custom line or "")
 *              — the stages that issue one automatically when an application
 *              reaches them (inc_cert_on_stage(), called from every move).
 *   ISSUED     inc_certificates: one row per certificate, with a snapshot of
 *              its wording, so a certificate reads the same later even after
 *              the template changes. Its random code is the public link
 *              (certificate.php?c=...), which shows it, prints it to PDF, and
 *              is the verification that it is genuine.
 *
 * Signatures are images of the signatory's signature (not a cryptographic
 * signing certificate); authenticity is the verification page, which reads
 * the record from this portal.
 */

require_once __DIR__ . '/../uploads.php';

function inc_cert_upgrade(): void
{
    $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    fabo_db()->query(inc_sql("CREATE TABLE IF NOT EXISTS inc_certificates (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      code CHAR(24) NOT NULL,
      application_id INT UNSIGNED NOT NULL,
      stage_id INT UNSIGNED NULL,
      stage_name VARCHAR(120) NOT NULL DEFAULT '',
      recipient VARCHAR(190) NOT NULL DEFAULT '',
      startup VARCHAR(190) NOT NULL DEFAULT '',
      program VARCHAR(190) NOT NULL DEFAULT '',
      email VARCHAR(190) NULL,
      snapshot MEDIUMTEXT NULL,
      issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      issued_by VARCHAR(190) NOT NULL DEFAULT '',
      emailed_at DATETIME NULL,
      mail_status VARCHAR(20) NULL,
      revoked_at DATETIME NULL,
      revoked_reason VARCHAR(255) NULL,
      UNIQUE KEY u_code (code),
      KEY k_app (application_id, stage_id)
    ) $t"));
}

/* -------------------------------------------------------------- template */

function inc_cert_default_template(): array
{
    return [
        'title' => 'Certificate of Achievement',
        'intro' => 'This certificate is proudly presented to',
        'body' => 'for successfully reaching the {stage} stage of {program} at {org}.',
        'note' => 'We congratulate the team on this milestone and wish them every success in the journey ahead.',
        'accent' => '#1d4ed8',
        'show_person' => '1',
        'logos' => [],
        'signatories' => [],
        'stamp' => null,          // an uploaded seal image; none = the drawn digital stamp
    ];
}

function inc_cert_template(): array
{
    $t = json_decode(inc_setting('cert_template'), true);
    return is_array($t) ? $t + inc_cert_default_template() : inc_cert_default_template();
}

function inc_cert_template_put(array $t): void
{
    inc_setting_set('cert_template', json_encode($t, JSON_UNESCAPED_UNICODE));
}

/** Saves the wording and colour. */
function inc_cert_template_save(array $p): void
{
    inc_need('configure');
    $t = inc_cert_template();
    $t['title'] = inc_str($p['title'] ?? '', 120, true, 'Title');
    $t['intro'] = inc_str($p['intro'] ?? '', 200);
    $t['body'] = inc_str($p['body'] ?? '', 1000, true, 'Main text');
    $t['note'] = inc_str($p['note'] ?? '', 600);
    $accent = trim((string) ($p['accent'] ?? ''));
    $t['accent'] = preg_match('/^#[0-9a-fA-F]{6}$/', $accent) ? strtolower($accent) : '#1d4ed8';
    $t['show_person'] = !empty($p['show_person']) ? '1' : '0';
    inc_cert_template_put($t);
    inc_audit('update', 'certificate', null, 'Certificate template saved');
}

/** Stores an uploaded image (PNG, JPG or WebP, up to 2 MB). Returns ['f' => stored name, 'm' => mime, 'n' => original name]. */
function inc_cert_store_image(?array $file, string $what): array
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new InvalidArgumentException('Choose an image for the ' . $what . '.');
    }
    $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true)) {
        throw new InvalidArgumentException('The ' . $what . ' must be a PNG, JPG or WebP image (PNG with a transparent background looks best).');
    }
    if ((int) ($file['size'] ?? 0) > 2 * 1024 * 1024) {
        throw new InvalidArgumentException('Keep the ' . $what . ' under 2 MB.');
    }
    [$ok, $msg, $meta] = fabo_store_upload($file);
    if (!$ok) {
        throw new InvalidArgumentException(ucfirst($what) . ': ' . $msg);
    }
    return ['f' => $meta['stored_name'], 'm' => $meta['mime'], 'n' => $meta['original_name']];
}

function inc_cert_logo_add(?array $file): void
{
    inc_need('configure');
    $t = inc_cert_template();
    if (count($t['logos']) >= 6) {
        throw new InvalidArgumentException('Up to 6 logos fit on a certificate. Remove one first.');
    }
    $t['logos'][] = inc_cert_store_image($file, 'logo');
    inc_cert_template_put($t);
}

/** Replaces, removes or moves one logo. */
function inc_cert_logo_change(int $i, string $do, ?array $file = null): void
{
    inc_need('configure');
    $t = inc_cert_template();
    if (!isset($t['logos'][$i])) {
        throw new InvalidArgumentException('That logo is not there any more.');
    }
    if ($do === 'replace') {
        $new = inc_cert_store_image($file, 'logo');
        fabo_delete_stored((string) $t['logos'][$i]['f']);
        $t['logos'][$i] = $new;
    } elseif ($do === 'remove') {
        fabo_delete_stored((string) $t['logos'][$i]['f']);
        array_splice($t['logos'], $i, 1);
    } elseif ($do === 'left' || $do === 'right') {
        $j = $do === 'left' ? $i - 1 : $i + 1;
        if (isset($t['logos'][$j])) {
            [$t['logos'][$i], $t['logos'][$j]] = [$t['logos'][$j], $t['logos'][$i]];
        }
    }
    inc_cert_template_put($t);
}

/** Saves one signatory slot (0–2): name, designation, and a new signature image if one was chosen. */
function inc_cert_signatory_save(int $i, array $p, ?array $file): void
{
    inc_need('configure');
    if ($i < 0 || $i > 2) {
        throw new InvalidArgumentException('Unknown signatory.');
    }
    $t = inc_cert_template();
    $cur = $t['signatories'][$i] ?? [];
    $name = inc_str($p['sig_name'] ?? '', 120);
    $role = inc_str($p['sig_role'] ?? '', 160);
    if (!empty($p['remove'])) {
        if (!empty($cur['sig']['f'])) {
            fabo_delete_stored((string) $cur['sig']['f']);
        }
        unset($t['signatories'][$i]);
    } else {
        if ($name === '') {
            throw new InvalidArgumentException('Give the signatory\'s name.');
        }
        $cur['name'] = $name;
        $cur['role'] = $role;
        if ($file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $new = inc_cert_store_image($file, 'signature');
            if (!empty($cur['sig']['f'])) {
                fabo_delete_stored((string) $cur['sig']['f']);
            }
            $cur['sig'] = $new;
        } elseif (!empty($p['clear_sig']) && !empty($cur['sig']['f'])) {
            fabo_delete_stored((string) $cur['sig']['f']);
            unset($cur['sig']);
        }
        $t['signatories'][$i] = $cur;
    }
    ksort($t['signatories']);
    inc_cert_template_put($t);
}

/** Uploads (or replaces) the organisation's seal image, or removes it to go back to the drawn digital stamp. */
function inc_cert_stamp_change(string $do, ?array $file = null): void
{
    inc_need('configure');
    $t = inc_cert_template();
    if ($do === 'upload') {
        $new = inc_cert_store_image($file, 'stamp');
        if (!empty($t['stamp']['f'])) {
            fabo_delete_stored((string) $t['stamp']['f']);
        }
        $t['stamp'] = $new;
    } elseif ($do === 'remove') {
        if (!empty($t['stamp']['f'])) {
            fabo_delete_stored((string) $t['stamp']['f']);
        }
        $t['stamp'] = null;
    }
    inc_cert_template_put($t);
}

/** An uploaded image as a data: URI, so the certificate page needs no other request. */
function inc_cert_img(?array $img): string
{
    $f = (string) ($img['f'] ?? '');
    if (!preg_match('/^[0-9a-f]{32}\.[A-Za-z0-9]{1,8}$/', $f)) {
        return '';
    }
    $path = fabo_upload_dir() . DIRECTORY_SEPARATOR . $f;
    if (!is_file($path) || filesize($path) > 2 * 1024 * 1024) {
        return '';
    }
    $mime = in_array($img['m'] ?? '', ['image/png', 'image/jpeg', 'image/webp'], true) ? $img['m'] : 'image/png';
    return 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($path));
}

/* ---------------------------------------------------------------- stages */

/** Stages that issue a certificate automatically: stage id => custom line ('' = the template's). */
function inc_cert_stages(): array
{
    $s = json_decode(inc_setting('cert_stages'), true);
    return is_array($s) ? array_combine(array_map('intval', array_keys($s)), array_values($s)) : [];
}

function inc_cert_stages_save(array $p): void
{
    inc_need('configure');
    $out = [];
    foreach (inc_stages('crm') as $st) {
        $sid = (int) $st['id'];
        if (!empty($p['auto'][$sid])) {
            $out[$sid] = inc_str($p['line'][$sid] ?? '', 500);
        }
    }
    inc_setting_set('cert_stages', json_encode($out, JSON_UNESCAPED_UNICODE));
    inc_audit('update', 'certificate', null, 'Certificate stages: ' . count($out));
}

/* ----------------------------------------------------------------- issue */

/** The organisation's name, as the certificate gives it. */
function inc_cert_org_name(): string
{
    if (inc_org() > 0) {
        $c = fabo_one('SELECT name, organisation FROM clients WHERE id = ?', 'i', [inc_org()]);
        return trim((string) ($c['organisation'] ?? '')) ?: (string) ($c['name'] ?? fabo_app_name());
    }
    return fabo_app_name();
}

/** The wording and look of one certificate, frozen when it is issued. */
function inc_cert_snapshot(array $app, array $stage): array
{
    $t = inc_cert_template();
    $vars = ['{name}' => (string) $app['applicant_name'], '{startup}' => (string) ($app['startup_name'] ?: $app['applicant_name']),
             '{stage}' => (string) $stage['name'], '{program}' => (string) (($app['event_name'] ?? '') ?: ($app['form_name'] ?? 'the programme')),
             '{org}' => inc_cert_org_name(), '{ref}' => (string) $app['ref'], '{date}' => date('d F Y')];
    $line = inc_cert_stages()[(int) $stage['id']] ?? '';
    return ['title' => strtr($t['title'], $vars), 'intro' => strtr($t['intro'], $vars), 'body' => strtr($line !== '' ? $line : $t['body'], $vars),
            'note' => strtr($t['note'], $vars), 'accent' => $t['accent'], 'show_person' => $t['show_person'], 'logos' => $t['logos'],
            'signatories' => array_values($t['signatories']), 'stamp' => $t['stamp'] ?? null, 'org' => $vars['{org}'], 'startup' => $vars['{startup}'], 'person' => $vars['{name}'],
            'stage' => $vars['{stage}'], 'program' => $vars['{program}'], 'date' => $vars['{date}']];
}

function inc_cert_link(array $c): string
{
    return rtrim(fabo_portal_url_safe(), '/') . '/certificate.php?c=' . $c['code'] . (inc_org() > 0 ? '&o=' . inc_org() : '') . inc_kind_q();
}

/**
 * Issues the certificate for an application's stage (its current one unless
 * given) and emails the applicant the link. One per application and stage:
 * asked again, the same certificate is emailed again rather than a second one
 * made. Returns [certificate row, 'sent'|'queued'|'skipped'|'failed', detail].
 */
function inc_cert_issue(int $appId, ?int $stageId = null, bool $email = true, bool $fresh = false): array
{
    $app = inc_one('SELECT a.*, f.name AS form_name FROM inc_applications a LEFT JOIN inc_forms f ON f.id = a.form_id WHERE a.id = ?', 'i', [$appId]);
    if (!$app) {
        throw new InvalidArgumentException('That application no longer exists.');
    }
    $stage = inc_stage($stageId ?: (int) $app['stage_id']);
    if (!$stage || $stage['pipeline'] !== 'crm') {
        throw new InvalidArgumentException('Choose a stage of the application pipeline.');
    }
    $c = $fresh ? null : inc_one('SELECT * FROM inc_certificates WHERE application_id = ? AND stage_id = ? AND revoked_at IS NULL ORDER BY id DESC LIMIT 1', 'ii', [$appId, (int) $stage['id']]);
    if ($c) {
        /* Sent again: it takes the template as it is now (logos, signatures,
           colour, wording), keeping its ID and the date it was first issued,
           so the link already in the applicant's inbox shows the same. */
        inc_cert_refresh($c, $app, $stage);
        $c = inc_one('SELECT * FROM inc_certificates WHERE id = ?', 'i', [(int) $c['id']]);
    }
    if (!$c) {
        $snap = inc_cert_snapshot($app, $stage);
        $code = substr(bin2hex(random_bytes(12)), 0, 24);
        $id = inc_exec('INSERT INTO inc_certificates (code, application_id, stage_id, stage_name, recipient, startup, program, email, snapshot, issued_by) VALUES (?,?,?,?,?,?,?,?,?,?)',
                       'siisssssss', [$code, $appId, (int) $stage['id'], $stage['name'], (string) $app['applicant_name'], $snap['startup'], $snap['program'],
                                      ((string) $app['applicant_email']) ?: null, json_encode($snap, JSON_UNESCAPED_UNICODE), inc_actor()]);
        $c = inc_one('SELECT * FROM inc_certificates WHERE id = ?', 'i', [$id]);
        inc_activity($appId, $app['workspace_id'] ? (int) $app['workspace_id'] : null, 'certificate', 'Certificate issued for reaching "' . $stage['name'] . '" (' . $code . ').');
        inc_audit('issue', 'certificate', $id, $app['ref'] . ': ' . $stage['name']);
    }
    if (!$email) {
        return [$c, 'skipped', 'not emailed'];
    }
    $to = trim((string) $app['applicant_email']);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        inc_exec('UPDATE inc_certificates SET mail_status = ? WHERE id = ?', 'si', ['skipped', (int) $c['id']]);
        return [$c, 'skipped', 'no email address on the application'];
    }
    [$status, $detail] = inc_cert_mail($c, $app);
    inc_exec('UPDATE inc_certificates SET emailed_at = NOW(), mail_status = ? WHERE id = ?', 'si', [$status, (int) $c['id']]);
    if ($status === 'sent' || $status === 'queued') {
        inc_activity($appId, null, 'certificate', 'Certificate for "' . $c['stage_name'] . '" emailed to ' . $to . ($status === 'queued' ? ' (queued)' : '') . '.');
    }
    return [$c, $status, $detail];
}

/** Rebuilds an issued certificate from the current template, keeping its ID and first-issue date. */
function inc_cert_refresh(array $c, ?array $app = null, ?array $stage = null): void
{
    $app ??= inc_one('SELECT a.*, f.name AS form_name FROM inc_applications a LEFT JOIN inc_forms f ON f.id = a.form_id WHERE a.id = ?', 'i', [(int) $c['application_id']]);
    $stage ??= inc_stage((int) $c['stage_id']) ?: ['id' => (int) $c['stage_id'], 'name' => $c['stage_name']];
    if (!$app) {
        return;
    }
    $snap = inc_cert_snapshot($app, $stage);
    $snap['date'] = date('d F Y', strtotime((string) $c['issued_at']));
    inc_exec('UPDATE inc_certificates SET snapshot = ?, startup = ?, program = ? WHERE id = ?', 'sssi',
             [json_encode($snap, JSON_UNESCAPED_UNICODE), $snap['startup'], $snap['program'], (int) $c['id']]);
}

/** The email that carries a certificate's link. */
function inc_cert_mail(array $c, array $app): array
{
    require_once __DIR__ . '/../mailtpl.php';
    $snap = json_decode((string) $c['snapshot'], true) ?: [];
    $who = (string) ($app['applicant_name'] ?: 'there');
    $startup = (string) ($c['startup'] ?: $who);
    $url = inc_cert_link($c);
    $subject = 'Certificate: ' . $startup . ' has reached "' . $c['stage_name'] . '"';
    $lead = 'Congratulations! ' . $startup . ' has reached the "' . $c['stage_name'] . '" stage' . ($c['program'] !== '' ? ' of ' . $c['program'] : '') . '.';
    $text = 'Hello ' . $who . ",\n\n" . $lead . "\n\nYour certificate is ready. Open it to view, download or print it:\n" . $url
          . "\n\nCertificate ID: " . $c['code'] . "\n\nRegards,\n" . ($snap['org'] ?? inc_cert_org_name());
    $html = fabo_mail_p('Hello ' . e($who) . ',') . fabo_mail_p('<strong>' . e($lead) . '</strong>')
          . fabo_mail_p('Your certificate is ready. Open it to view, download (PDF) or print it.')
          . fabo_mail_own_link($url, 'View my certificate', 'Certificate ID ' . $c['code'] . ' — anyone can check it is genuine at this link.')
          . fabo_mail_p('Regards,<br>' . e($snap['org'] ?? inc_cert_org_name()));
    [$status, $detail] = inc_send_email((string) $app['applicant_email'], $subject, $text, $html);
    return [$status, $detail];
}

/** Called after every pipeline move: issues the stage's certificate when that stage sends one. Never throws. */
function inc_cert_on_stage(int $appId, int $stageId): void
{
    try {
        if (!isset(inc_cert_stages()[$stageId])) {
            return;
        }
        inc_cert_issue($appId, $stageId, true);
    } catch (Throwable $e) {
        error_log('FABO incubation certificate: ' . $e->getMessage());
    }
}

/** Sends the certificate of each chosen application's current stage. Returns counts for a message. */
function inc_cert_bulk(array $ids): array
{
    inc_need('edit');
    $out = ['sent' => 0, 'queued' => 0, 'skipped' => [], 'failed' => []];
    foreach (array_unique(array_map('intval', $ids)) as $id) {
        $ref = (string) (inc_one('SELECT ref FROM inc_applications WHERE id = ?', 'i', [$id])['ref'] ?? ('#' . $id));
        try {
            [, $status, $detail] = inc_cert_issue($id);
            if ($status === 'sent' || $status === 'queued') {
                $out[$status]++;
            } elseif ($status === 'skipped') {
                $out['skipped'][$ref] = $detail;
            } else {
                $out['failed'][$ref] = $detail;
            }
        } catch (InvalidArgumentException $e) {
            $out['skipped'][$ref] = $e->getMessage();
        }
    }
    return $out;
}

function inc_cert_revoke(int $id, string $reason): void
{
    inc_need('configure');
    $c = inc_one('SELECT * FROM inc_certificates WHERE id = ?', 'i', [$id]);
    if (!$c) {
        throw new InvalidArgumentException('That certificate no longer exists.');
    }
    inc_exec('UPDATE inc_certificates SET revoked_at = NOW(), revoked_reason = ? WHERE id = ?', 'si', [inc_str($reason, 255) ?: null, $id]);
    inc_activity((int) $c['application_id'], null, 'certificate', 'Certificate ' . $c['code'] . ' (' . $c['stage_name'] . ') revoked.');
    inc_audit('revoke', 'certificate', $id, $c['code']);
}

/* ---------------------------------------------------------------- render */

/**
 * The certificate itself (A4 landscape), as HTML with its own styles.
 * $snap: a snapshot (inc_cert_snapshot()); $code / $verify: the ID and link
 * printed at the foot ('' in a preview).
 */
function inc_cert_render(array $snap, string $code = '', string $verify = '', string $issued = ''): string
{
    $a = preg_match('/^#[0-9a-f]{6}$/i', (string) ($snap['accent'] ?? '')) ? $snap['accent'] : '#1d4ed8';
    $logos = '';
    foreach ($snap['logos'] ?? [] as $l) {
        if ($src = inc_cert_img($l)) {
            $logos .= '<img src="' . $src . '" alt="' . e((string) ($l['n'] ?? 'Logo')) . '">';
        }
    }
    $sigs = '';
    foreach ($snap['signatories'] ?? [] as $s) {
        $img = inc_cert_img($s['sig'] ?? null);
        $sigs .= '<div class="cx-sig"><div class="cx-sigimg">' . ($img ? '<img src="' . $img . '" alt="Signature of ' . e((string) $s['name']) . '">' : '') . '</div>'
               . '<div class="cx-sigline"></div><div class="cx-signame">' . e((string) $s['name']) . '</div><div class="cx-sigrole">' . e((string) ($s['role'] ?? '')) . '</div></div>';
    }
    $date = $issued !== '' ? $issued : (string) $snap['date'];
    $id = $code !== '' ? strtoupper(implode('-', str_split($code, 4))) : 'PREVIEW';
    // The stamp: the organisation's own seal when one is uploaded, else a drawn digital stamp.
    $stampImg = inc_cert_img($snap['stamp'] ?? null);
    $stamp = $stampImg !== '' ? '<img class="cx-stampimg" src="' . $stampImg . '" alt="Seal of ' . e((string) $snap['org']) . '">'
                              : inc_cert_stamp_svg((string) $snap['org'], $date, $code, $a);
    return '<style>
.cx{--a:' . $a . ';position:relative;width:297mm;height:210mm;box-sizing:border-box;padding:11mm;background:#fff;color:#1c2333;font-family:"Inter",system-ui,"Segoe UI",Arial,sans-serif;overflow:hidden}
.cx *{box-sizing:border-box}
.cx-frame{position:relative;height:100%;border:2.2mm solid var(--a);padding:3mm}
.cx-in{position:relative;height:100%;border:.6mm solid color-mix(in srgb,var(--a) 45%,#c9a227);padding:9mm 12mm 7mm;display:flex;flex-direction:column;align-items:center;text-align:center;
       background:radial-gradient(ellipse at top,color-mix(in srgb,var(--a) 6%,#fff),#fff 60%)}
.cx-id{position:absolute;top:5mm;left:6mm;max-width:62mm;text-align:left;font-size:2.9mm;line-height:1.45;color:#6b7385}
.cx-id b{color:#2a3142}
.cx-logos{display:flex;justify-content:center;align-items:center;gap:12mm;min-height:17mm;margin-bottom:5mm;max-width:165mm}
.cx-logos img{max-height:19mm;max-width:40mm;object-fit:contain}
.cx-title{margin:0;font-family:"Playfair Display",Georgia,serif;font-size:13.5mm;line-height:1.05;font-weight:700;color:var(--a);letter-spacing:.3mm}
.cx-rule{width:52mm;height:.8mm;margin:4mm auto 5mm;background:linear-gradient(90deg,transparent,color-mix(in srgb,var(--a) 45%,#c9a227),transparent)}
.cx-intro{font-size:4.3mm;letter-spacing:.9mm;text-transform:uppercase;color:#5d6678}
.cx-name{margin:4mm 0 1mm;font-family:"Playfair Display",Georgia,serif;font-size:12mm;font-style:italic;font-weight:600;color:#141a28;line-height:1.15;max-width:240mm}
.cx-person{font-size:4.4mm;color:#5d6678;margin-bottom:3mm}
.cx-body{font-size:5mm;line-height:1.5;max-width:210mm;margin:2mm 0}
.cx-stage{display:inline-block;margin:3mm 0 2mm;padding:2mm 7mm;border-radius:99px;background:var(--a);color:#fff;font-weight:700;font-size:4.4mm;letter-spacing:.5mm;text-transform:uppercase}
.cx-note{font-size:3.9mm;color:#5d6678;max-width:200mm;line-height:1.5}
.cx-foot{margin-top:auto;width:100%;display:grid;grid-template-columns:1fr auto 1fr;align-items:end;gap:6mm;padding:0 3mm 1mm}
.cx-meta{font-size:3mm;color:#6b7385;text-align:left;line-height:1.45}
.cx-meta b{color:#2a3142}
.cx-stampbox{display:flex;justify-content:center;align-items:center}
.cx-stamp{width:34mm;height:34mm;transform:rotate(-9deg);opacity:.92}
.cx-stampimg{max-width:36mm;max-height:34mm;object-fit:contain}
.cx-sigs{display:flex;gap:6mm;justify-content:flex-end;min-width:0}
.cx-sig{flex:0 1 52mm;min-width:0;text-align:center}
.cx-sigimg{height:15mm;display:flex;align-items:flex-end;justify-content:center}
.cx-sigimg img{max-height:15mm;max-width:100%;object-fit:contain;mix-blend-mode:multiply}
.cx-sigline{height:.35mm;background:#2a3142;margin:1mm 0 1.5mm}
.cx-signame{font-weight:700;font-size:4mm}
.cx-sigrole{font-size:3.4mm;color:#5d6678}
</style>
<div class="cx"><div class="cx-frame"><div class="cx-in">'
        . '<div class="cx-id"><b>Certificate ID</b><br>' . e($id)
        . '</div>'
        . ($logos !== '' ? '<div class="cx-logos">' . $logos . '</div>' : '<div class="cx-logos"></div>')
        . '<h1 class="cx-title">' . e((string) $snap['title']) . '</h1><div class="cx-rule"></div>'
        . ((string) $snap['intro'] !== '' ? '<div class="cx-intro">' . e((string) $snap['intro']) . '</div>' : '')
        . '<div class="cx-name">' . e((string) $snap['startup']) . '</div>'
        . (($snap['show_person'] ?? '1') === '1' && trim((string) $snap['person']) !== '' && $snap['person'] !== $snap['startup'] ? '<div class="cx-person">' . e((string) $snap['person']) . '</div>' : '')
        . '<div class="cx-body">' . e((string) $snap['body']) . '</div>'
        . '<div class="cx-stage">' . e((string) $snap['stage']) . '</div>'
        . ((string) $snap['note'] !== '' ? '<div class="cx-note">' . e((string) $snap['note']) . '</div>' : '')
        . '<div class="cx-foot"><div class="cx-meta"><b>Date</b><br>' . e($date) . '<br><b>Issued by</b><br>' . e((string) $snap['org']) . '</div>'
        . '<div class="cx-stampbox">' . $stamp . '</div>'
        . '<div class="cx-sigs">' . $sigs . '</div></div>'
        . '</div></div></div>';
}

/**
 * A round digital stamp, drawn: the organisation's name around the rim,
 * "DIGITALLY VERIFIED", the issue date and the certificate ID in the middle,
 * in the certificate's colour like stamp ink.
 */
function inc_cert_stamp_svg(string $org, string $date, string $code, string $colour): string
{
    $pid = 'cxs' . substr(md5($org . $code . $date), 0, 8);
    $rim = mb_strtoupper(trim($org) !== '' ? trim($org) : 'Verified') . '  •  DIGITALLY VERIFIED  •  ';
    $short = $code !== '' ? strtoupper(substr($code, 0, 4) . '-' . substr($code, -4)) : 'PREVIEW';
    $c = e($colour);
    return '<svg class="cx-stamp" viewBox="0 0 120 120" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Digital stamp of ' . e($org) . '">'
         . '<defs><path id="' . $pid . '" d="M60,60 m-45,0 a45,45 0 1,1 90,0 a45,45 0 1,1 -90,0"/></defs>'
         . '<circle cx="60" cy="60" r="57" fill="none" stroke="' . $c . '" stroke-width="3"/>'
         . '<circle cx="60" cy="60" r="52" fill="none" stroke="' . $c . '" stroke-width="1"/>'
         . '<circle cx="60" cy="60" r="36" fill="none" stroke="' . $c . '" stroke-width="1"/>'
         . '<text fill="' . $c . '" font-family="Inter,Arial,sans-serif" font-size="7" font-weight="700" textLength="276" lengthAdjust="spacingAndGlyphs">'
         . '<textPath href="#' . $pid . '" startOffset="0">' . e($rim) . '</textPath></text>'
         . '<text x="60" y="44" text-anchor="middle" fill="' . $c . '" font-size="11">★</text>'
         . '<text x="60" y="60" text-anchor="middle" fill="' . $c . '" font-family="Inter,Arial,sans-serif" font-size="10.5" font-weight="800" letter-spacing=".6">VERIFIED</text>'
         . '<text x="60" y="71" text-anchor="middle" fill="' . $c . '" font-family="Inter,Arial,sans-serif" font-size="6.2" font-weight="600">' . e($date) . '</text>'
         . '<text x="60" y="80" text-anchor="middle" fill="' . $c . '" font-family="Inter,Arial,sans-serif" font-size="5.6" letter-spacing=".4">' . e($short) . '</text>'
         . '</svg>';
}

/** The fonts the certificate uses. */
function inc_cert_fonts(): string
{
    return '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
         . '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=Playfair+Display:ital,wght@0,600;0,700;1,600&display=swap" rel="stylesheet">';
}

/** Sample wording for the template preview. */
function inc_cert_preview_snapshot(): array
{
    $stage = ['id' => 0, 'name' => 'Shortlisted'];
    $app = ['applicant_name' => 'Priya Sharma', 'startup_name' => 'GreenLeaf Innovations', 'event_name' => 'PolyFest 2026', 'form_name' => 'PolyFest Registration', 'ref' => 'INC-2026-00042'];
    return inc_cert_snapshot($app, $stage);
}

/** Finds a certificate by its ID in any organisation: [org, code] or null. For the verification box. */
function inc_cert_find(string $code): ?array
{
    $code = strtolower(preg_replace('/[^a-fA-F0-9]/', '', $code));
    if (strlen($code) !== 24) {
        return null;
    }
    $orgs = [0];
    foreach (fabo_all("SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'inc\_o%\_certificates'") as $r) {
        if (preg_match('/^inc_o(\d+)_certificates$/', (string) $r['t'], $m)) {
            $orgs[] = (int) $m[1];
        }
    }
    foreach ($orgs as $o) {
        try {
            $row = fabo_one('SELECT code FROM ' . ($o > 0 ? 'inc_o' . $o . '_certificates' : 'inc_certificates') . ' WHERE code = ?', 's', [$code]);
        } catch (Throwable $e) {
            $row = null;
        }
        if ($row) {
            return [$o, $code];
        }
    }
    return null;
}

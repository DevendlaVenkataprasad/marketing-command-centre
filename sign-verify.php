<?php
/**
 * Public verification by tracking code (when the organisation allows it):
 * whether the document exists, its status, who signed and when, and the
 * SHA-256 of the final PDF — nothing of its content. Anyone holding a
 * printed or forwarded copy can confirm it against this.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/ds/app.php';
fabo_boot();
ds_install();
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

$code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($_GET['c'] ?? '')));
$doc = null;
$msg = '';
if ($code !== '') {
    require_once __DIR__ . '/lib/throttle.php';
    if (fabo_throttle_blocked('ds-pubverify', fabo_client_ip(), 30, 900)) {
        $msg = 'Too many look-ups. Try again in a few minutes.';
    } else {
        fabo_throttle_hit('ds-pubverify', fabo_client_ip());
        $doc = fabo_one('SELECT * FROM ds_documents WHERE tracking_code = ? AND deleted_at IS NULL', 's', [$code]);
        if ($doc) {
            ds_use((int) $doc['org_id']);
            $GLOBALS['ds_system'] = true;
            if (ds_setting('public_verify') !== '1' || $doc['confidentiality'] === 'restricted') {
                $doc = null;
            }
        }
        if (!$doc) {
            $msg = 'No document is published under that code.';
        }
    }
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Verify a signed document</title>
<link rel="stylesheet" href="<?= e(fabo_asset('assets/app.css')) ?>"><link rel="stylesheet" href="<?= e(fabo_asset('assets/sign.css')) ?>"></head>
<body class="ds-public"><main class="ds ds-pubwrap">
<div class="ds-hero ds-rise"><div class="ds-hero-in"><div class="ds-hero-t"><div class="ds-eyebrow">Document verification</div><h1>Verify a signed document</h1>
<p>Enter the tracking code printed on the signature certificate.</p></div></div></div>
<form method="get" class="ds-card ds-rise ds-inlineform ds-pubform"><input name="c" value="<?= e($code) ?>" placeholder="Tracking code" required autocomplete="off" maxlength="20" aria-label="Tracking code"><button class="ds-btn ds-btn-primary">Verify</button></form>
<?php if ($msg !== '') { ?><div class="ds-toast err" role="alert"><span><?= e($msg) ?></span></div><?php } ?>
<?php if ($doc) {
    $final = ds_file((int) ($doc['signed_file_id'] ?: $doc['working_file_id']));
    $reqs = ds_doc_requests((int) $doc['id'], (int) $doc['cycle']);
    $ok = $doc['status'] === 'signed';
    ?>
<div class="ds-verdict t-<?= $ok ? 'green' : 'amber' ?> ds-rise"><div class="ds-verdict-i"><svg viewBox="0 0 64 64" aria-hidden="true"><circle cx="32" cy="32" r="28"/></svg><?= ds_icon($ok ? 'shield-check' : 'clock') ?></div><div>
<div class="ds-eyebrow"><?= e(ds_org_name((int) $doc['org_id'])) ?> · <?= e($doc['ref_no']) ?></div><h2><?= e(ds_doc_statuses()[$doc['status']][0]) ?></h2>
<p><?= $ok ? 'Completed ' . e(ds_fmt_dt($doc['completed_at'])) : 'Signing is not complete.' ?></p>
<?php if ($final) { ?><p class="mono small">SHA-256 of the <?= $ok ? 'signed' : 'current' ?> PDF: <?= e($final['sha256']) ?></p><?php } ?></div></div>
<section class="ds-card ds-rise"><div class="ds-card-h"><h2><?= ds_icon('users') ?>Signatories</h2></div><div class="ds-card-b"><ul class="ds-list">
<?php foreach ($reqs as $r) {
        if (in_array($r['status'], ['skipped', 'cancelled', 'waiting'], true)) {
            continue;
        } ?>
<li><span class="ds-li-x"><span class="ds-li-b"><strong><?= e(ds_req_name($r)) ?></strong><small><?= e((string) $r['stage_name']) ?><?= $r['acted_at'] ? ' · ' . e(ds_fmt_dt($r['acted_at'])) : '' ?></small></span><?= ds_method_chip($r['used_method']) ?><?= ds_req_badge($r['status']) ?></span></li>
<?php } ?></ul>
<p class="ds-muted small">To check the file itself, compare its SHA-256 with the one above, or verify the PDF's digital signatures in any PDF reader. Electronic approvals and drawn signatures are records of this portal, not digital signatures.</p></div></section>
<?php } ?>
</main></body></html>

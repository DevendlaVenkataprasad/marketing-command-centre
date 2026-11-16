<?php
/**
 * The one page an external signatory sees: the document, the places
 * marked for them, and a signature box (draw it or upload an image).
 * No account — the link is the invitation, a one-time code e-mailed to
 * them proves the mailbox. Afterwards the same link shows the outcome and,
 * once everyone has signed, the signed copy.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/ds/app.php';
fabo_boot();
ds_install();
require_once __DIR__ . '/lib/throttle.php';
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');

$raw = strtolower(preg_replace('/[^A-Fa-f0-9]/', '', (string) ($_POST['t'] ?? $_GET['t'] ?? '')));
$ip = fabo_client_ip();
$self = 'guest-sign.php?t=' . $raw;
$ajax = !empty($_POST['ajax']);

/** Ends a POST from the page's script with a JSON answer. */
$reply = static function (bool $ok, string $msg, string $go = '') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => $ok, 'msg' => $msg, 'go' => $go]);
    exit;
};

if (fabo_throttle_blocked('ds-guest-bad', $ip, 20, 900)) {
    http_response_code(429);
    $o = null;
    $gone = 'Too many attempts from your network. Try again in a few minutes.';
} else {
    $o = ds_guest_open($raw);
    $gone = '';
    if (!$o) {
        fabo_throttle_hit('ds-guest-bad', $ip);
        $gone = 'This link is not valid any more. It may have been replaced by a newer e-mail, or it has expired. Ask the sender for a new link.';
    }
}

/* Pictures of pages and the PDF itself — only of the file this link shows. */
if ($o && $_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['kind'])) {
    [$r, $doc] = $o;
    $fid = (int) ($doc['signed_file_id'] ?: $doc['working_file_id']);
    if ($_GET['kind'] === 'page') {
        $n = max(1, (int) ($_GET['n'] ?? 1));
        $etag = '"' . substr((string) ds_file($fid)['sha256'], 0, 40) . '-' . $n . '"';
        header('Cache-Control: private, max-age=86400');
        header('ETag: ' . $etag);
        header('X-Content-Type-Options: nosniff');
        if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
            http_response_code(304);
            exit;
        }
        try {
            $img = ds_preview_page($fid, $n);
        } catch (Throwable $e) {
            header('Cache-Control: no-store');
            http_response_code(404);
            exit;
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . strlen($img));
        echo $img;
        exit;
    }
    ds_send_file($fid, $doc['ref_no'] . ($doc['status'] === 'signed' ? '-signed' : '') . '.pdf', empty($_GET['dl']), (int) $doc['id'], empty($_GET['dl']) ? 'preview' : 'external signatory copy');
}

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Only from this page: a POST from another site is refused.
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) {
        http_response_code(403);
        if ($ajax) {
            $reply(false, 'This request did not come from the signing page.');
        }
        exit('Forbidden');
    }
    $a = (string) ($_POST['action'] ?? '');
    $done = static function (string $msg) use ($ajax, $reply, $self, $o) {
        // Signing last completes the document, which e-mails a new link for the signed copy and retires this one.
        $new = $o ? ($GLOBALS['ds_guest_new_link'][(int) $o[0]['id']] ?? null) : null;
        if ($new) {
            $self = 'guest-sign.php?t=' . $new;
        }
        if ($ajax) {
            $reply(true, $msg, $self . '&done=1');
        }
        header('Location: ' . $self . '&done=1');
        exit;
    };
    try {
        if (!$o) {
            throw new InvalidArgumentException($gone);
        }
        if (fabo_throttle_blocked('ds-guest-post', $ip, 40, 900)) {
            throw new InvalidArgumentException('Too many attempts. Try again in a few minutes.');
        }
        fabo_throttle_hit('ds-guest-post', $ip);
        [$r, $doc, $ext] = $o;
        if ($a === 'code') {
            if (!ds_guest_can_act($r, $doc)) {
                throw new InvalidArgumentException('There is nothing to sign on this link any more.');
            }
            $to = ds_guest_send_code($r, $ext);
            $msg = 'A 6-digit code was sent to ' . $to . '. It works for 10 minutes.';
            if ($ajax) {
                $reply(true, $msg);
            }
            $flash = ['ok', $msg];
        } elseif ($a === 'sign') {
            $msg = ds_guest_sign($raw, (string) ($_POST['code'] ?? ''), isset($_POST['signature']) ? (string) $_POST['signature'] : null,
                                 (string) ($_POST['remarks'] ?? ''), !empty($_POST['consent']), $_POST);
            $done($msg);
        } elseif ($a === 'reject' || $a === 'changes') {
            $msg = ds_guest_decline($raw, (string) ($_POST['code'] ?? ''), $a, (string) ($_POST['reason'] ?? ''));
            $done($msg);
        } else {
            throw new InvalidArgumentException('Unknown action.');
        }
    } catch (InvalidArgumentException $e) {
        $flash = ['err', $e->getMessage()];
    } catch (Throwable $e) {
        error_log('FABO esign guest: ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
        $flash = ['err', 'Something went wrong. Please try again.'];
    }
    if ($ajax) {
        $reply(false, $flash[1]);
    }
    $o = ds_guest_open($raw) ?: $o;
}
header('Cache-Control: no-store');

$title = 'Sign a document';
$r = $doc = $ext = null;
if ($o) {
    [$r, $doc, $ext] = $o;
    $title = $doc['title'];
}
$org = $doc ? ds_org_name((int) $doc['org_id']) : '';
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= e($title) ?> · Signature</title>
<meta name="robots" content="noindex,nofollow">
<link rel="stylesheet" href="<?= e(fabo_asset('assets/app.css')) ?>"><link rel="stylesheet" href="<?= e(fabo_asset('assets/sign.css')) ?>"></head>
<body class="ds-public ds-guestbody"><main class="ds ds-guest" id="main">
<?php if (!$o) { ?>
<div class="ds-guest-top"><span class="ds-guest-brand"><?= ds_icon('sign') ?>Document signing</span></div>
<div class="ds-verdict t-amber ds-rise"><div class="ds-verdict-i"><svg viewBox="0 0 64 64" aria-hidden="true"><circle cx="32" cy="32" r="28"/></svg><?= ds_icon('lock') ?></div><div>
<h2>This link cannot be opened</h2><p><?= e($gone) ?></p></div></div>
<?php } else {
    $can = ds_guest_can_act($r, $doc);
    $verb = $r['action'] === 'approve' ? 'approve' : 'sign';
    $fid = (int) ($doc['signed_file_id'] ?: $doc['working_file_id']);
    ds_preview_warm($fid);
    $info = ds_preview_info($fid);
    $fields = $can ? ds_request_fields($r) : [];
    $labels = array_map(static fn($k) => $k[0], ds_field_kinds());
    ?>
<div class="ds-guest-top"><span class="ds-guest-brand"><?= ds_icon('building') ?><?= e($org) ?></span><span class="ds-guest-me"><?= ds_icon('user') ?><?= e($ext['name']) ?> · <?= e(ds_mask_email((string) $ext['email'])) ?></span></div>
<header class="ds-hero ds-rise"><div class="ds-hero-in"><div class="ds-hero-t"><div class="ds-eyebrow"><?= e($doc['ref_no']) ?> · from <?= e(ds_person_name((int) $doc['owner_id'])) ?></div>
<h1><?= e($doc['title']) ?></h1><p><?= $can ? 'You are asked to ' . $verb . ' this document' . ($r['due_at'] ? ' by ' . e(ds_fmt_dt($r['due_at'])) : '') . '. Read it, then ' . $verb . ' on the right.' : 'Thank you. Here is where this document stands.' ?></p></div>
<div class="ds-hero-a"><a class="ds-btn ds-btn-ghost" href="<?= e($self . '&kind=pdf&dl=1') ?>"><?= ds_icon('download') ?>Download PDF</a></div></div></header>
<?= ds_flash_html($flash) ?>
<div class="ds-signgrid"><div class="ds-signgrid-l">
<div class="ds-viewer ds-rise" data-viewer data-placeable="0"<?= $info ? ' data-preview="' . e(json_encode(['img' => $self . '&kind=page&n=', 'sizes' => $info['sizes']])) . '"' : '' ?>
<?= $fields ? ' data-fields="' . e(json_encode(array_map(static fn($f) => $f + ['label' => $labels[$f['kind']]], $fields))) . '"' : '' ?> data-src="<?= e($self . '&kind=pdf') ?>">
<div class="ds-viewer-bar"><span class="ds-viewer-t"><?= ds_icon('file') ?>Document <small data-pageinfo></small></span><span class="ds-viewer-c">
<button type="button" class="ds-icobtn" data-zoom="-1" aria-label="Zoom out"><?= ds_icon('zoom-out') ?></button><button type="button" class="ds-icobtn" data-zoom="1" aria-label="Zoom in"><?= ds_icon('zoom-in') ?></button></span></div>
<?php if ($fields) { ?><div class="ds-placehint is-fixed"><?= ds_icon('move') ?><span><strong><?= count($fields) ?> place<?= count($fields) === 1 ? ' is' : 's are' ?> marked for you.</strong> They are filled in when you <?= $verb ?>.</span><button type="button" class="ds-btn ds-btn-sm" data-nextfield><?= ds_icon('chev-d') ?>Show me</button></div><?php } ?>
<div class="ds-viewer-pages" data-pages><div class="ds-skel"></div><div class="ds-skel"></div></div><div class="ds-readmeter" data-readmeter><i></i><span>Reading progress</span></div></div>
</div><aside class="ds-signgrid-r">
<?php if (!empty($_GET['done']) || !$can) {
        $st = ds_request_statuses()[$r['status']][0] ?? $r['status'];
        $good = in_array($r['status'], ['signed', 'approved', 'reviewed'], true);
        ?>
<div class="ds-card ds-rise ds-signcard ds-guestdone"><div class="ds-guestdone-i t-<?= $good ? 'green' : 'amber' ?>"><?= ds_icon($good ? 'check-circle' : 'alert') ?></div>
<h2><?= $r['status'] === 'pending' ? 'Waiting for you' : e($st) ?></h2>
<p><?php
        if ($r['status'] === 'pending') {
            echo 'This document is not open for signing right now (' . e(strtolower(ds_doc_statuses()[$doc['status']][0] ?? $doc['status'])) . '). The sender will send a new link if needed.';
        } elseif ($good) {
            echo 'You ' . e(strtolower($st)) . ' on ' . e(ds_fmt_dt($r['acted_at'])) . '. ';
            echo $doc['status'] === 'signed' ? 'Everyone has signed — download the signed copy below.' : 'When everyone has signed you will get an e-mail with the signed copy.';
        } else {
            echo 'Recorded on ' . e(ds_fmt_dt($r['acted_at'])) . '. The sender has been told.';
        }
        ?></p>
<?php if ($doc['status'] === 'signed') { ?><a class="ds-btn ds-btn-primary ds-btn-block" href="<?= e($self . '&kind=pdf&dl=1') ?>"><?= ds_icon('download') ?>Download the signed copy</a><?php } ?>
</div>
<?php } else { ?>
<div class="ds-card ds-rise ds-signcard"><div class="ds-signcard-h"><span class="ds-req-ic a-<?= e($r['action']) ?>"><?= ds_icon($verb === 'sign' ? 'sign' : 'check') ?></span>
<div><div class="ds-eyebrow"><?= e((string) $r['stage_name']) ?></div><strong>You are asked to <?= $verb ?></strong><?= $r['due_at'] ? '<small>' . ds_icon('clock') . 'Due ' . e(ds_fmt_dt($r['due_at'])) . '</small>' : '' ?></div></div>
<?php if ($doc['remarks']) { ?><div class="ds-instr"><?= ds_icon('chat') ?><p><?= nl2br(e($doc['remarks'])) ?></p></div><?php } ?>
<form method="post" class="ds-signform" data-guestform data-padform action="<?= e($self) ?>">
<input type="hidden" name="t" value="<?= e($raw) ?>"><input type="hidden" name="action" value="sign">
<div class="ds-gstep"><span class="ds-gstep-n">1</span><div><strong>Your signature</strong><small><?= $verb === 'sign' ? 'Draw it, or upload a photo or scan of it.' : 'Optional — leave empty to approve without one.' ?></small></div></div>
<?= ds_pad_html($verb !== 'sign') ?>
<?= ds_typed_html($r, 'drawn') ?>
<div class="ds-gstep"><span class="ds-gstep-n">2</span><div><strong>Confirm it is you</strong><small>We e-mail a 6-digit code to <?= e(ds_mask_email((string) $ext['email'])) ?>.</small></div></div>
<div class="ds-coderow"><input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" placeholder="6-digit code" aria-label="6-digit code" required>
<button type="button" class="ds-btn" data-guestcode><?= ds_icon('mail') ?>Email me a code</button></div>
<p class="ds-hint" data-guestnote role="status"></p>
<?= ds_field('Remarks (optional)', '<textarea name="remarks" rows="2" maxlength="1000"></textarea>') ?>
<label class="ds-check ds-consent"><input type="checkbox" name="consent" value="1" required> I agree to <?= $verb ?> this document electronically, and that my <?= $verb === 'sign' ? 'signature' : 'approval' ?> is as binding as one on paper.</label>
<button class="ds-btn ds-btn-primary ds-btn-block ds-btn-lg" type="submit"><?= ds_icon($verb === 'sign' ? 'sign' : 'check') ?><?= $verb === 'sign' ? 'Sign document' : 'Approve document' ?></button>
</form>
<details class="ds-guestno"><summary>Decline, or ask for changes</summary>
<form method="post" data-guestform action="<?= e($self) ?>"><input type="hidden" name="t" value="<?= e($raw) ?>">
<?= ds_field('Reason', '<textarea name="reason" rows="3" maxlength="1000" required></textarea>') ?>
<div class="ds-coderow"><input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" placeholder="6-digit code" aria-label="6-digit code" required>
<button type="button" class="ds-btn" data-guestcode><?= ds_icon('mail') ?>Email me a code</button></div>
<p class="ds-hint" data-guestnote role="status"></p>
<div class="ds-btnrow"><button class="ds-btn" name="action" value="changes"><?= ds_icon('edit') ?>Ask for changes</button><button class="ds-btn ds-btn-danger" name="action" value="reject"><?= ds_icon('x') ?>Decline</button></div>
</form></details>
<p class="ds-muted small ds-guestlegal"><?= ds_icon('shield') ?>Your signature, the code check and the time are sealed into the document's audit record by <?= e($org) ?>.</p>
</div>
<?php } ?>
</aside></div>
<?php } ?>
</main><script src="<?= e(fabo_asset('assets/sign.js')) ?>" defer></script></body></html>

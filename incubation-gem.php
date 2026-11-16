<?php
/**
 * Incubation Management — the page an applicant opens from a Gem email.
 *
 * The link carries a 32-character token tied to one application and one Gem
 * (inc_gem_requests). It shows that Gem's questions once; after they are
 * answered the same link only says thank you. The score is never shown here.
 * Scoring happens after the reply has been sent to the browser, so the
 * applicant does not wait on Gemini; cron.php scores anything left over.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/inc/gems.php';
require_once __DIR__ . '/lib/throttle.php';
fabo_boot();
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, private');

$org = max(0, (int) ($_GET['o'] ?? 0));
inc_use_kind((string) ($_GET['m'] ?? ''));
$req = $gem = null;
if (inc_org_enabled($org)) {
    inc_start(['is_admin' => false, 'client' => ['name' => 'applicant'], 'access' => null], $org);
    $req = inc_gem_request_by_token((string) ($_GET['t'] ?? ''));
    $gem = $req ? inc_gem((int) $req['gem_id']) : null;
}

$errors = [];
$message = '';
$justDone = false;
if ($req && $gem && !$req['submitted_at'] && $_SERVER['REQUEST_METHOD'] === 'POST') {
    fabo_csrf_check();
    if (fabo_throttle_blocked('gem-ip', fabo_client_ip(), 30, 3600)) {
        $message = fabo_throttle_words();
    } else {
        fabo_throttle_hit('gem-ip', fabo_client_ip());
        try {
            inc_gem_submit($req, $gem, $_POST);
            $justDone = true;
            $req = inc_gem_request_by_token((string) $req['token']);
        } catch (InvalidArgumentException $e) {
            $errors = json_decode($e->getMessage(), true) ?: [];
            $message = $errors ? 'Please answer the questions marked below.' : $e->getMessage();
        } catch (Throwable $e) {
            error_log('FABO gem submit: ' . $e->getMessage());
            $message = 'Your answers could not be saved just now. Please try again in a few minutes.';
        }
    }
}

$brand = $org > 0 ? fabo_org_brand($org)[1] : fabo_app_name();
fabo_csrf_token();
ob_start();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($gem ? $gem['name'] : 'Form') ?> &middot; <?= e($brand) ?></title>
<link rel="stylesheet" href="<?= e(fabo_asset('assets/form.css')) ?>">
<link rel="stylesheet" href="<?= e(fabo_asset('assets/inc.css')) ?>">
</head>
<body class="fm-body">
<header class="fm-top"><div class="brandmark"><span><?= e($brand) ?></span></div></header>
<main class="fm-wrap" id="main"><div class="fm-card">
<?php if (!$req || !$gem): ?>
  <div class="fm-head"><div class="fm-eyebrow">Form</div><h1>This link is not valid</h1>
  <p class="fm-lead">Please use the full link from your email. If it still does not open, reply to that email and we will send a new one.</p></div>
<?php elseif ($req['submitted_at']): ?>
  <div class="fm-head"><div class="fm-eyebrow"><?= e($gem['name']) ?></div><h1>Thank you<?= $req['applicant_name'] !== '' ? ', ' . e($req['applicant_name']) : '' ?></h1>
  <p class="fm-lead"><?= $justDone ? 'Your answers have been received.' : 'We already have your answers, received ' . e(inc_fmt_datetime($req['submitted_at'])) . '.' ?>
  The incubation team will be in touch about the next step.<?= $req['ref'] ? ' Your reference is <strong>' . e($req['ref']) . '</strong>.' : '' ?></p></div>
<?php else: ?>
  <div class="fm-head"><div class="fm-eyebrow"><?= e($gem['name']) ?></div>
  <h1><?= e($req['startup_name'] !== '' ? $req['startup_name'] : 'Hello ' . ($req['applicant_name'] ?: '')) ?></h1>
  <?= trim((string) $gem['intro']) !== '' ? '<p class="fm-lead">' . nl2br(e($gem['intro'])) . '</p>' : '' ?></div>
  <?php if ($message !== ''): ?><div class="inc-warn" role="alert"><?= e($message) ?></div><?php endif; ?>
  <form method="post" class="inc-applyform" novalidate>
    <?= fabo_csrf_field() ?>
    <?php foreach ($gem['fields'] as $f):
        $id = 'g_' . $f['uid'];
        $v = (string) ($_POST[$f['uid']] ?? '');
        $err = $errors[$f['uid']] ?? ''; ?>
      <div class="inc-f">
        <label for="<?= e($id) ?>"><?= e($f['label']) ?><?= $f['required'] ? ' <span class="req" aria-hidden="true">*</span>' : '' ?></label>
        <?php $help = trim((string) ($f['help'] ?? '')); if ($help !== ''): ?><p class="inc-qhelp" id="<?= e($id) ?>_help"><?= nl2br(e($help)) ?></p><?php endif; ?>
        <?php if ($f['type'] === 'short'): ?>
          <input type="text" id="<?= e($id) ?>" name="<?= e($f['uid']) ?>" value="<?= e($v) ?>" maxlength="300"<?= $help !== '' ? ' aria-describedby="' . e($id) . '_help"' : '' ?><?= $f['required'] ? ' required' : '' ?><?= $err ? ' aria-invalid="true"' : '' ?>>
        <?php else: ?>
          <textarea id="<?= e($id) ?>" name="<?= e($f['uid']) ?>" rows="6" maxlength="5000"<?= $help !== '' ? ' aria-describedby="' . e($id) . '_help"' : '' ?><?= $f['required'] ? ' required' : '' ?><?= $err ? ' aria-invalid="true"' : '' ?>><?= e($v) ?></textarea>
        <?php endif; ?>
        <?php if ($err): ?><div class="inc-err"><?= e($err) ?></div><?php endif; ?>
      </div>
    <?php endforeach; ?>
    <div class="inc-formfoot"><button class="btn btn-primary">Submit</button></div>
  </form>
<?php endif; ?>
</div></main>
</body>
</html>
<?php
/* Send the page, then score: the applicant is not kept waiting on Gemini. */
$page = (string) ob_get_clean();
if ($justDone && $req) {
    ignore_user_abort(true);
    header('Content-Length: ' . strlen($page));
    header('Connection: close');
    echo $page;
    if (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    } elseif (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        @ob_flush();
        flush();
    }
    set_time_limit(180);
    try {
        inc_gem_score((int) $req['id']);
    } catch (Throwable $e) {
        error_log('FABO gem score after submit: ' . $e->getMessage());
    }
    exit;
}
echo $page;

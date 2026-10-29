<?php
/**
 * An application form, built on the Intake Forms desk. One unguessable link,
 * no sign-in; each application becomes a new record in the form's CRM
 * (lib/crm_forms.php, fabo_cf_submit).
 *
 * Guarded the way form.php is: a CSRF token and a one-use ticket per filled-in
 * form (so a double click or a re-sent post cannot make two records), limits
 * per address and per form, and a hidden field that only a robot fills in.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/crm_forms.php';
require_once __DIR__ . '/lib/throttle.php';
fabo_boot();

header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');

$form = fabo_cf_by_token((string) ($_GET['f'] ?? ''));
if (!$form) {
    fabo_deny('This form link is not valid.', 404);
}
$token = (string) $form['token'];
$state = fabo_cf_state($form);
$board = fabo_crm_board((int) $form['crm_id']);
[$brandOrg, $brandName, $brandHasLogo] = fabo_org_brand((int) $form['org_id']);
$brandLogo = $brandHasLogo ? 'logo.php?a=' . rawurlencode($token) : 'assets/logo.png';

fabo_session();
$ticketKey = 'fabo_cf_ticket_' . $token;
$doneKey   = 'fabo_cf_done_' . $token;
$errors    = [];
$old       = [];

if ($state === 'ok' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    fabo_csrf_check();
    $old = is_array($_POST['f'] ?? null) ? $_POST['f'] : [];
    $ticket = (string) ($_POST['ticket'] ?? '');
    $live   = (string) ($_SESSION[$ticketKey] ?? '');
    if (trim((string) ($_POST['website'] ?? '')) !== '') {
        // Only a robot fills in a field nobody can see. Thank it and keep nothing.
        $_SESSION[$doneKey] = ['ref' => '', 'files' => []];
        header('Location: apply.php?' . http_build_query(['f' => $token, 'done' => 1]));
        exit;
    }
    if ($ticket === '' || $live === '' || !hash_equals($live, $ticket)) {
        if (!empty($_SESSION[$doneKey])) {
            header('Location: apply.php?' . http_build_query(['f' => $token, 'done' => 1]));
            exit;
        }
        $errors[] = 'This page had expired. Please check your answers and send it again.';
    } elseif (fabo_throttle_blocked('cf-ip', $token . '|' . fabo_client_ip(), 20, 3600)
              || fabo_throttle_blocked('cf-all', $token, 500, 3600)) {
        $errors[] = fabo_throttle_words();
    } else {
        try {
            unset($_SESSION[$ticketKey]);       // spent before anything is written
            [, $ref, $fileErrs] = fabo_cf_submit($form, $old);
            fabo_throttle_hit('cf-ip', $token . '|' . fabo_client_ip());
            fabo_throttle_hit('cf-all', $token);
            $_SESSION[$doneKey] = ['ref' => $ref, 'files' => $fileErrs];
            header('Location: apply.php?' . http_build_query(['f' => $token, 'done' => 1]));
            exit;
        } catch (InvalidArgumentException $e) {
            $_SESSION[$ticketKey] = $live;      // nothing was saved: the same form may be sent again
            $errors = explode("\n", $e->getMessage());
        } catch (Throwable $e) {
            $_SESSION[$ticketKey] = $live;
            error_log('FABO apply.php: ' . $e->getMessage());
            $errors[] = 'Something went wrong saving your application. Please try again in a moment.';
        }
    }
}

$done = isset($_GET['done']) && !empty($_SESSION[$doneKey]) ? $_SESSION[$doneKey] : null;
if (!$done) {
    $_SESSION[$ticketKey] = $_SESSION[$ticketKey] ?? bin2hex(random_bytes(16));
}
if ($done && isset($_GET['again'])) {
    unset($_SESSION[$doneKey]);
    header('Location: apply.php?' . http_build_query(['f' => $token]));
    exit;
}
$questions = $state === 'ok' ? fabo_cf_questions($form) : [];
$hasFiles  = (bool) array_filter($questions, static fn($q) => $q['kind'] === 'field' && $q['field']['type'] === 'file');
$para = static function (string $text): string {
    $out = '';
    foreach (preg_split('/\n\s*\n/', trim($text)) ?: [] as $p) {
        if (trim($p) !== '') {
            $out .= '<p class="fm-lead">' . nl2br(e($p)) . '</p>';
        }
    }
    return $out;
};
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($form['title']) ?> &middot; <?= e($brandName) ?></title>
<link rel="icon" href="<?= e($brandLogo) ?>">
<link rel="stylesheet" href="<?= e(fabo_asset('assets/app.css')) ?>">
<link rel="stylesheet" href="<?= e(fabo_asset('assets/form.css')) ?>">
<style>
  .crm-choices { display: flex; flex-wrap: wrap; gap: 6px 16px; padding: 4px 0; }
  .crm-choices label, .crm-check { display: inline-flex; gap: 7px; align-items: center; cursor: pointer; font-weight: 400; }
  .crm-rate { display: inline-flex; flex-direction: row-reverse; justify-content: flex-end; }
  .crm-rate input { position: absolute; opacity: 0; pointer-events: none; }
  .crm-rate label { font-size: 26px; color: #d8dce3; cursor: pointer; padding: 0 2px; line-height: 1; }
  .crm-rate input:checked ~ label, .crm-rate label:hover, .crm-rate label:hover ~ label { color: #e0a100; }
  .crm-rate input:focus-visible + label { outline: 2px solid #1d5fd0; }
  .crm-clear { font-size: 12px; color: #8a93a3; display: inline-flex; gap: 4px; align-items: center; }
  .crm-affix { display: flex; align-items: stretch; }
  .crm-affix span { display: flex; align-items: center; padding: 0 11px; background: #f3f5f8; border: 1px solid #dde2ea; color: #5f6979; font-size: 13px; }
  .crm-affix span:first-child { border-right: 0; border-radius: 8px 0 0 8px; }
  .crm-affix input { border-radius: 0 8px 8px 0 !important; min-width: 0; flex: 1; }
  .cf-sec { margin: 22px 0 4px; font-size: 16px; }
  .cf-sec + .fm-help { display: block; margin-bottom: 8px; }
  .cf-errs { margin: 0; padding-left: 18px; }
  .cf-trap { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }
</style>
</head>
<body class="fm-body">
<div class="fm-top">
  <div class="brandmark">
    <img class="brandlogo" src="<?= e($brandLogo) ?>" alt="" width="32" height="32">
    <span><?= e($brandName) ?></span>
  </div>
</div>
<div class="fm-wrap">
  <div class="fm-card">
    <div class="fm-head">
      <span class="fm-eyebrow">Application</span>
      <h1><?= e($form['title']) ?></h1>
      <?php if (!$done && $state === 'ok'): ?><?= $para((string) $form['intro']) ?><?php endif; ?>
    </div>

<?php if ($state !== 'ok'): ?>
    <div class="flash err">This form is not taking applications at the moment. Please contact <?= e($brandName) ?> for details.</div>

<?php elseif ($done): ?>
    <div class="fm-done">
      <div class="fm-tick" aria-hidden="true">&#10003;</div>
      <h2>Application received</h2>
      <?= $para((string) $form['thanks']) ?>
      <?php if ($done['ref'] !== ''): ?>
        <div class="fm-refs"><div class="fm-ref"><span>Your reference</span><strong><?= e($done['ref']) ?></strong>
          <small>Quote it if you contact us</small></div></div>
      <?php endif; ?>
      <?php if (!empty($done['files'])): ?>
        <div class="flash err">Some files could not be kept: <?= e(implode('; ', $done['files'])) ?></div>
      <?php endif; ?>
      <p class="fm-note"><a href="apply.php?<?= e(http_build_query(['f' => $token, 'done' => 1, 'again' => 1])) ?>">Send another application</a></p>
    </div>

<?php else: ?>
    <?php if ($errors): ?>
      <div class="flash err" role="alert"><b>Please check:</b><ul class="cf-errs"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <form method="post" action="apply.php?<?= e(http_build_query(['f' => $token])) ?>"<?= $hasFiles ? ' enctype="multipart/form-data"' : '' ?> class="fm-form" novalidate>
      <?= fabo_csrf_field() ?>
      <input type="hidden" name="ticket" value="<?= e($_SESSION[$ticketKey]) ?>">
      <div class="cf-trap" aria-hidden="true"><label>Leave this empty <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
      <div class="formgrid">
      <?php foreach ($questions as $q):
          if ($q['kind'] === 'heading'): ?>
        </div>
        <h2 class="fm-secttl cf-sec"><?= e($q['label']) ?></h2>
        <?php if ($q['help'] !== ''): ?><small class="fm-help"><?= e($q['help']) ?></small><?php endif; ?>
        <div class="formgrid">
          <?php continue; endif;
          $f   = $q['field'];
          $k   = (string) $f['field_key'];
          $req = !empty($f['required']);
          $full = in_array($f['type'], ['textarea', 'multiselect', 'radio', 'file'], true) ? ' full' : ''; ?>
        <div class="field<?= $full ?><?= $req ? ' needed' : '' ?>">
          <label for="crmf_<?= e($k) ?>"><?= e($f['label']) ?><?= $req ? '<span class="req">*</span>' : '' ?></label>
          <?= fabo_crm_input($f, $old[$k] ?? null) ?>
          <?php if (trim((string) $f['help']) !== ''): ?><small class="fm-help"><?= e($f['help']) ?></small><?php endif; ?>
        </div>
      <?php endforeach; ?>
      </div>
      <div class="fm-actions" style="margin-top:18px">
        <button class="btn btn-primary" type="submit">Submit application</button>
      </div>
    </form>
<?php endif; ?>
  </div>
</div>
</body>
</html>

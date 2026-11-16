<?php
/**
 * Incubation Management — the public application page.
 *
 * Every form is closed to the public until an administrator opens its link
 * (Settings → Forms → the form → "Open a public link"). With no ?f= the page
 * lists the open forms by purpose, but only when the administrator has turned
 * that on; otherwise it says nothing about which forms exist.
 *
 * A submission goes straight into Incubation Management (never the main CRM).
 * The page is throttled per address and per form, carries a CSRF token, a
 * hidden honeypot field, and a one-time ticket so a double click or a
 * refresh cannot file the same application twice.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/inc/render.php';
require_once __DIR__ . '/lib/throttle.php';
fabo_boot();
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, private');

/* Whose Incubation Management: ours with no o=, else a client organisation's --
   only one whose head holds the module, so a made-up number opens nothing
   (and never creates tables). */
$org = max(0, (int) ($_GET['o'] ?? 0));
inc_use_kind((string) ($_GET['m'] ?? ''));
if (inc_kind() === 'eo') {
    require_once __DIR__ . '/lib/eo/app.php';
}
if (!inc_org_enabled($org)) {
    $org = -1;
}
if ($org >= 0) {
    inc_start(['is_admin' => false, 'client' => ['name' => 'applicant'], 'access' => null], $org);
}
$orgQ = ($org > 0 ? '&o=' . $org : '') . inc_kind_q();

$token = (string) ($_GET['f'] ?? '');
$form = $token !== '' && $org >= 0 ? inc_form_by_token($token) : null;
$open = $form && (int) $form['public_enabled'] === 1 && (int) $form['archived'] === 0 ? $form : null;
$version = $open ? inc_form_published((int) $open['id']) : null;

$errors = [];
$message = '';
$done = null;
$values = [];
if ($open && $version && $_SERVER['REQUEST_METHOD'] === 'POST') {
    fabo_csrf_check();
    $fields = inc_form_fields((int) $version['id'], true);
    [$raw, $files] = inc_posted_answers($fields);
    $values = $raw;
    if (trim((string) ($_POST['website_url'] ?? '')) !== '') {
        // The honeypot: people never see this field, scripts fill it in.
        $done = ['ref' => '', 'repeat' => true];
    } elseif (fabo_throttle_blocked('inc-ip', $token . '|' . fabo_client_ip(), 10, 3600) || fabo_throttle_blocked('inc-all', $token, 500, 3600)) {
        $message = fabo_throttle_words();
    } else {
        fabo_throttle_hit('inc-ip', $token . '|' . fabo_client_ip());
        fabo_throttle_hit('inc-all', $token);
        try {
            $done = inc_app_submit($open, $version, $raw, $files, ['channel' => 'public', 'ticket' => (string) ($_POST['ticket'] ?? ''), 'ip' => fabo_client_ip()]);
            // An event's form: payment, or a ticket at once, follows from the event.
            if (inc_kind() === 'eo' && ($next = eo_after_submit($done, $open))) {
                header('Location: ' . $next);
                exit;
            }
        } catch (InvalidArgumentException $e) {
            [$errors, $message] = inc_submit_errors($e);
        } catch (Throwable $e) {
            error_log('FABO incubation public submit: ' . $e->getMessage());
            $message = 'Your application could not be saved just now. Please try again in a few minutes.';
        }
    }
}

// A client organisation's form carries its own name, not the portal's.
$brand = $org > 0 ? fabo_org_brand($org)[1]
                  : fabo_app_name();
fabo_csrf_token();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($open ? $open['name'] : 'Apply') ?> &middot; <?= e($brand) ?></title>
<link rel="stylesheet" href="<?= e(fabo_asset('assets/form.css')) ?>">
<link rel="stylesheet" href="<?= e(fabo_asset('assets/inc.css')) ?>">
</head>
<body class="fm-body">
<header class="fm-top"><div class="brandmark"><span><?= e($brand) ?></span></div></header>
<main class="fm-wrap" id="main"><div class="fm-card">
<?php if ($done): ?>
  <div class="fm-head"><div class="fm-eyebrow">Application received</div><h1>Thank you</h1>
  <p class="fm-lead"><?= $done['ref'] !== '' ? 'Your application reference is <strong>' . e($done['ref']) . '</strong>. Please keep it for any follow-up.' : 'Your application has been received.' ?>
  <?= e(inc_words('The incubation team')) ?> will be in touch.</p></div>
<?php elseif ($open && $version): ?>
  <div class="fm-head"><div class="fm-eyebrow"><?= e(isset(inc_purposes()[$open['purpose']]) ? inc_purpose_text($open['purpose'], $open['event_name'] ?? null) : 'Application') ?></div><h1><?= e($open['name']) ?></h1>
  <?= $open['description'] ? '<p class="fm-lead">' . nl2br(e($open['description'])) . '</p>' : '' ?></div>
  <?php if ($message !== ''): ?><div class="inc-warn" role="alert"><?= e($message) ?></div><?php endif; ?>
  <form method="post" enctype="multipart/form-data" class="inc-applyform" novalidate<?= (int) ($open['stepwise'] ?? 0) === 1 ? ' data-steps="1"' : '' ?>>
    <?= fabo_csrf_field() ?>
    <input type="hidden" name="ticket" value="<?= e((string) ($_POST['ticket'] ?? bin2hex(random_bytes(16)))) ?>">
    <div class="inc-hp" aria-hidden="true"><label>Leave this empty <input type="text" name="website_url" tabindex="-1" autocomplete="off"></label></div>
    <?= inc_render_fields(inc_form_fields((int) $version['id'], true), $values, $errors) ?>
    <div class="inc-formfoot"><button class="btn btn-primary">Submit application</button></div>
  </form>
<?php else:
    $list = $org >= 0 && inc_setting('public_index') === '1'
          ? array_filter(inc_forms_list(), static fn($f) => (int) $f['public_enabled'] === 1 && $f['published_no'] !== null) : []; ?>
  <div class="fm-head"><div class="fm-eyebrow">Apply</div><h1><?= $list ? 'Choose what you are applying for' : 'This application link is not open' ?></h1>
  <?php if (!$list): ?><p class="fm-lead">The form may have closed, or the link may be incomplete. Please check the link you were given.</p><?php endif; ?></div>
  <?php foreach ($list as $f): ?>
    <p><a class="btn" href="<?= inc_kind() === 'eo' ? 'event-apply' : 'incubation-apply' ?>.php?f=<?= e($f['public_token'] . $orgQ) ?>"><?= e($f['name']) ?></a> <span class="fm-hint"><?= e(inc_purpose_text($f['purpose'], $f['event_name'] ?? null)) ?></span></p>
  <?php endforeach; ?>
<?php endif; ?>
</div></main>
<script src="<?= e(fabo_asset('assets/inc.js')) ?>" defer></script>
</body>
</html>

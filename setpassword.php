<?php
/**
 * Choose your own password.
 *
 * Opened from the link in an invitation. The link says which account — which
 * matters, because one person may run five companies from one mailbox and
 * an email address cannot tell them apart — and the password that comes out
 * of this page is known only to the person who typed it.
 *
 * The link opens a form and nothing else. On its own it signs nobody in.
 */

/* Guarded rather than required outright: if this page reached the server
   before lib/invite.php did, say so in a sentence instead of handing the
   visitor a blank five-hundred. */
if (!is_file(__DIR__ . '/lib/invite.php')) {
    require_once __DIR__ . '/lib/ui.php';
    http_response_code(503);
    echo '<!doctype html><meta charset="utf-8"><title>Not ready yet</title>'
       . '<p style="font:16px/1.6 system-ui;margin:3rem auto;max-width:34rem">'
       . 'This link is not ready yet. Please ask whoever invited you to send '
       . 'it again in a few minutes.</p>';
    exit;
}

require_once __DIR__ . '/lib/invite.php';
require_once __DIR__ . '/lib/ui.php';
fabo_boot();

header('X-Robots-Tag: noindex, nofollow');

$token  = (string) ($_GET['t'] ?? $_POST['t'] ?? '');
$person = fabo_invite_open($token);
$error  = '';
$done   = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    fabo_csrf_check();
    [$ok, $msg, $who] = fabo_invite_use($token,
        (string) ($_POST['new_password'] ?? ''),
        (string) ($_POST['confirm_password'] ?? ''));
    if ($ok && $who) {
        /* Straight in, rather than asking them to type what they just chose.
           They came from their own mailbox and have just proved it. */
        fabo_login($who);
        header('Location: c.php');
        exit;
    }
    $error  = $msg;
    $person = $who ?: $person;
}

fabo_csrf_token();
$brand = $person ? fabo_owner_brand($person) : fabo_app_name();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Set your password &middot; <?= e(fabo_app_name()) ?></title>
<link rel="stylesheet" href="<?= e(fabo_asset('assets/app.css')) ?>">
<link rel="stylesheet" href="<?= e(fabo_asset('assets/form.css')) ?>">
</head>
<body class="fm-body">
<div class="fm-top">
  <div class="brandmark"><?= e(fabo_app_name()) ?> <span>&middot; <?= e(fabo_app_tagline()) ?></span></div>
</div>
<div class="fm-wrap" style="max-width:560px">
  <div class="fm-card">

<?php if (!$person): ?>

    <div class="fm-head">
      <span class="fm-eyebrow">Invitation</span>
      <h1>This link has expired</h1>
      <p class="fm-lead">It may also have been used already &mdash; each one works once.
        Ask whoever set up your account for a fresh invitation and it will be sent
        to you.</p>
    </div>
    <div class="fm-actions">
      <a class="btn" href="login.php">Go to sign in</a>
    </div>

<?php else: ?>

    <div class="fm-head">
      <span class="fm-eyebrow">Welcome</span>
      <h1>Choose your password</h1>
      <p class="fm-lead">This sets the password for <strong><?= e((string) $person['name']) ?></strong><?php
        $org = trim((string) ($person['organisation'] ?? ''));
        if ($org !== '' && $org !== (string) $person['name']) {
            echo ' at <strong>' . e($org) . '</strong>';
        } ?>. Nobody else knows it, including us.</p>
    </div>

    <?php if ($error !== ''): ?>
      <div class="fm-note warn"><?= e($error) ?></div>
    <?php endif; ?>

    <?php /* What they will type next time. The user ID matters most: their
             address may belong to several accounts, this only to one. */ ?>
    <div class="fm-refs">
      <div class="fm-ref accent">
        <span>Your user ID</span>
        <strong><?= e((string) ($person['login_id'] ?? '')) ?: '&mdash;' ?></strong>
        <small>sign in with this</small>
      </div>
      <div class="fm-ref">
        <span>Your email</span>
        <strong><?= e((string) ($person['email'] ?? '')) ?: '&mdash;' ?></strong>
        <small>where we write to you</small>
      </div>
    </div>

    <form method="post" autocomplete="on">
      <?= fabo_csrf_field() ?>
      <input type="hidden" name="t" value="<?= e($token) ?>">
      <div class="formgrid">
        <div class="field full needed">
          <label for="np">New password<span class="req">*</span></label>
          <input id="np" type="password" name="new_password" required minlength="8"
                 autocomplete="new-password" autofocus>
          <small class="fm-help">At least 8 characters. A short phrase you will
            remember beats a short muddle you will not.</small>
        </div>
        <div class="field full needed">
          <label for="cp">Type it again<span class="req">*</span></label>
          <input id="cp" type="password" name="confirm_password" required minlength="8"
                 autocomplete="new-password">
        </div>
      </div>
      <div class="fm-actions">
        <button class="btn btn-primary">Set my password and sign in</button>
      </div>
      <p class="fm-hint fm-foot">This link works once. After you press the button it
        stops working, and your new password is the only way in.</p>
    </form>

<?php endif; ?>

  </div>
  <p class="fm-sig"><?= e($brand) ?></p>
</div>
</body>
</html>

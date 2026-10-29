<?php
/**
 * Sign in — and, when a password has been forgotten, the way back in.
 *
 * One page, several steps: sign in, ask for a code, type the code, choose a
 * new password. Which step is on screen comes from $step, and each one checks
 * its own precondition rather than trusting the step before it.
 *
 * There are no links here to an admin, client or team entrance. Everybody
 * arrives with the address and the link from their welcome email, and one door
 * is one door less to explain — and to probe.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/brand.php';
require_once __DIR__ . '/lib/reset.php';
require_once __DIR__ . '/lib/throttle.php';
fabo_boot();

header('X-Robots-Tag: noindex, nofollow');

$error  = '';
$notice = !empty($_GET['out']) ? 'You have been signed out.' : '';

$adminMode = !empty($_GET['admin']) || !empty($_POST['admin']);
// On an organisation's own address (lib/orghost.php) the page is theirs: their name, their logo, their people.
$hostOrg = fabo_request_org();
[, $siteName, $siteLogo] = $hostOrg > 0 ? fabo_org_brand($hostOrg) : [0, fabo_app_name(), false];
$next      = (string) ($_GET['next'] ?? $_POST['next'] ?? '');
$action    = (string) ($_POST['action'] ?? '');
$ip        = fabo_client_ip();

/* Limits on guessing: per address the request came from, and per account. */
const FABO_TRIES_IP      = 20;     // failed sign-ins from one IP ...
const FABO_TRIES_ACCOUNT = 8;      // ... or against one account ...
const FABO_TRIES_WINDOW  = 900;    // ... in 15 minutes

/**
 * Where to go after the admin signs in: a page of the admin area on this
 * site, or the admin home. "//elsewhere.example" and "/\elsewhere" look like
 * paths but take the browser to another site, so anything that is not plainly
 * a local admin page is refused.
 */
function fabo_safe_admin_next(string $next): string
{
    if ($next === '' || str_starts_with($next, '//') || str_contains($next, '\\')
        || str_contains($next, '..') || preg_match('/[\r\n\x00]/', $next)) {
        return 'admin/index.php';
    }
    return preg_match('~^(/[A-Za-z0-9_./-]*)?/?admin/[A-Za-z0-9_-]+\.php(\?[^#\s]*)?$~', $next) && str_starts_with($next, '/')
         ? $next : 'admin/index.php';
}

// Already signed in? Go straight through.
if ($adminMode && fabo_admin_signed_in()) {
    header('Location: ' . fabo_safe_admin_next($next));
    exit;
}
$changing = !empty($_GET['change']) || $action === 'change';
if (!$adminMode && !$changing && fabo_signed_in() && empty($_GET['stay'])) {
    header('Location: c.php');
    exit;
}

fabo_session();

/* ---------------------------------------------------------------- steps */

$step = 'in';
if ($changing) {
    $step = 'change';
} elseif (isset($_GET['forgot']) || str_starts_with($action, 'forgot')) {
    $step = 'forgot';
}

$resetId = (int) ($_SESSION['fabo_reset_id'] ?? 0);

/* -------------------------------------------------------------- actions */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    fabo_csrf_check();

    /* ---- the password a first sign-in asks them to replace ---- */
    if ($action === 'change') {
        $person = fabo_signed_in();
        $new    = (string) ($_POST['new_password'] ?? '');
        $again  = (string) ($_POST['confirm_password'] ?? '');
        if (!$person) {
            $error = 'Please sign in first.';
        } elseif ((int) ($person['must_change'] ?? 0) !== 1) {
            /* This step asks for no current password, so it is only for the
               first sign-in with an issued one. Any other change goes through
               the profile page, which checks the current password first. */
            $error = 'Change your password from your Profile page, where your current password is asked for.';
        } elseif (mb_strlen($new) < 8) {
            $error = 'Choose a password of at least 8 characters.';
        } elseif ($new !== $again) {
            $error = 'The two passwords do not match.';
        } else {
            fabo_exec('UPDATE clients SET password_hash = ?, must_change = 0 WHERE id = ?', 'si',
                      [password_hash($new, PASSWORD_DEFAULT), (int) $person['id']]);
            fabo_session_refresh((int) $person['id']);   // this session stays; any other ends
            $to = fabo_safe_next($next);
            header('Location: ' . ($to !== '' ? $to : 'c.php'));
            exit;
        }

    /* ---- step 1: which address ---- */
    } elseif ($action === 'forgot_start') {
        $step = 'forgot';
        $askFor = trim((string) ($_POST['email'] ?? ''));
        // Each request can send an email: limited per IP and per address.
        if (fabo_throttle_blocked('reset-ip', $ip, 10, 3600)
            || fabo_throttle_blocked('reset-acct', $askFor, 5, 3600)) {
            [$ok, $msg, $id] = [false, fabo_throttle_words(), 0];
        } else {
            fabo_throttle_hit('reset-ip', $ip);
            fabo_throttle_hit('reset-acct', $askFor);
            [$ok, $msg, $id] = fabo_reset_start($askFor);
        }
        if (!$ok) {
            $error = $msg;
        } else {
            // An unknown address gets the same page and the same words; it
            // simply has no reset behind it, so no code will ever match.
            $_SESSION['fabo_reset_id']    = $id;
            $_SESSION['fabo_reset_email'] = trim((string) ($_POST['email'] ?? ''));
            $resetId = $id;
            $notice  = $msg;
            $step    = 'code';
        }

    /* ---- step 2: the code ---- */
    } elseif ($action === 'forgot_code') {
        $step = 'code';
        if (fabo_throttle_blocked('code-ip', $ip, FABO_TRIES_IP, FABO_TRIES_WINDOW)) {
            [$ok, $msg] = [false, fabo_throttle_words()];
        } else {
            [$ok, $msg] = fabo_reset_check($resetId, (string) ($_POST['code'] ?? ''));
            if (!$ok) {
                fabo_throttle_hit('code-ip', $ip);
            }
        }
        if ($ok) {
            $notice = $msg;
            $step   = 'new';
        } else {
            $error = $msg;
        }

    /* ---- send another code ---- */
    } elseif ($action === 'forgot_resend') {
        $askFor = (string) ($_SESSION['fabo_reset_email'] ?? '');
        if (fabo_throttle_blocked('reset-ip', $ip, 10, 3600)
            || fabo_throttle_blocked('reset-acct', $askFor, 5, 3600)) {
            [$ok, $msg, $id] = [false, fabo_throttle_words(), 0];
        } else {
            fabo_throttle_hit('reset-ip', $ip);
            fabo_throttle_hit('reset-acct', $askFor);
            [$ok, $msg, $id] = fabo_reset_start($askFor);
        }
        if ($id > 0) {
            $_SESSION['fabo_reset_id'] = $id;
            $resetId = $id;
        }
        $notice = $msg;
        $step   = 'code';

    /* ---- step 3: the new password ---- */
    } elseif ($action === 'forgot_reset') {
        $step = 'new';
        [$ok, $msg, $person] = fabo_reset_complete(
            $resetId, (string) ($_POST['new_password'] ?? ''), (string) ($_POST['confirm_password'] ?? '')
        );
        if (!$ok) {
            $error = $msg;
            if (!fabo_reset_verified($resetId)) {
                $step = 'forgot';       // the code died under them
            }
        } else {
            unset($_SESSION['fabo_reset_id'], $_SESSION['fabo_reset_email']);
            fabo_login($person);
            $to = fabo_safe_next($next);
            header('Location: ' . ($to !== '' ? $to : 'c.php'));
            exit;
        }

    /* ---- the admin door ---- */
    } elseif ($adminMode) {
        if (fabo_throttle_blocked('login-ip', $ip, FABO_TRIES_IP, FABO_TRIES_WINDOW)
            || fabo_throttle_blocked('login-acct', 'admin', 30, FABO_TRIES_WINDOW)) {
            $error = fabo_throttle_words();
        } elseif (fabo_admin_check((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''))) {
            fabo_admin_login();
            fabo_throttle_clear('login-acct', 'admin');
            header('Location: ' . fabo_safe_admin_next($next));
            exit;
        } else {
            fabo_throttle_hit('login-ip', $ip);
            fabo_throttle_hit('login-acct', 'admin');
            $error = 'That email and password do not match.';
            error_log('FABO admin login failed from ' . $ip);
        }

    /* ---- the ordinary door ---- */
    } else {
        $typed = trim((string) ($_POST['email'] ?? ''));
        if (fabo_throttle_blocked('login-ip', $ip, FABO_TRIES_IP, FABO_TRIES_WINDOW)
            || fabo_throttle_blocked('login-acct', $typed, FABO_TRIES_ACCOUNT, FABO_TRIES_WINDOW)) {
            $error  = fabo_throttle_words();
            $person = null;
        } else {
            $person = fabo_check_login($typed, (string) ($_POST['password'] ?? ''));
        }
        if ($error !== '') {
            // refused before checking anything
        } elseif (!$person) {
            /* One message for every failure. It used to say how many accounts
               shared an address, or that a password "opens N accounts" --
               which told a guesser that an address exists, or that the
               password they just tried was right. The user-ID hint is the
               same for everyone, so it gives nothing away. */
            fabo_throttle_hit('login-ip', $ip);
            fabo_throttle_hit('login-acct', $typed);
            $error = 'That email and password do not match an active account. If several '
                   . 'accounts share your email, sign in with the user ID from your welcome email.';
            error_log('FABO login failed from ' . $ip);
        } elseif ($hostOrg > 0 && fabo_org_head((int) $person['id']) !== $hostOrg) {
            // The right password, at another organisation's door: say where theirs is.
            $error = 'This sign-in page is for ' . $siteName . '. Please sign in at https://' . fabo_person_host($person) . '/login.php';
        } else {
            fabo_throttle_clear('login-acct', $typed);
            fabo_login($person);
            $to = fabo_safe_next($next);
            if ((int) $person['must_change'] === 1) {
                $to = 'login.php?change=1' . ($to !== '' ? '&next=' . rawurlencode($to) : '');
            }
            header('Location: ' . ($to !== '' ? $to : 'c.php'));
            exit;
        }
    }
}

// Landing on a later step with nothing behind it sends them back to the start.
/* An unknown address (reset id 0) stays on the code step exactly as a real
   one does, so the page never shows which addresses have accounts. */
if ($step === 'new' && !fabo_reset_verified($resetId)) {
    $step  = 'forgot';
}

$signedIn   = fabo_signed_in();
$mustChange = $changing && $signedIn;
$maskedTo   = (string) ($_SESSION['fabo_reset_email'] ?? '');
if ($maskedTo !== '' && str_contains($maskedTo, '@')) {
    [$u, $d]  = explode('@', $maskedTo, 2);
    $maskedTo = mb_substr($u, 0, 2) . str_repeat('•', max(2, mb_strlen($u) - 2)) . '@' . $d;
}
fabo_csrf_token();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Sign in &middot; <?= e($siteName) ?></title>
<link rel="stylesheet" href="<?= e(fabo_asset('assets/app.css')) ?>">
</head>
<body class="authbody">
<div class="auth">

  <aside class="authside">
    <div class="authbrand">
      <span class="mark"><?= $siteLogo ? '<img src="logo.php?h=1" alt="" style="width:100%;height:100%;object-fit:contain;border-radius:inherit;background:#fff">' : e(mb_substr($siteName, 0, 1)) ?></span>
      <span class="name"><?= e($siteName) ?></span>
    </div>
<?php if ($hostOrg > 0): ?>
    <h2>Welcome to <?= e($siteName) ?>.</h2>
    <p>Sign in with the email or user ID and the password from your welcome email.</p>
    <div class="authfoot"><?= e($siteName) ?></div>
<?php else: ?>
    <h2>Every campaign, every client, in one place.</h2>
    <p>Plans, content, design, spend and results — live, and only ever the parts
       that belong to you.</p>
    <ul class="authpoints">
      <li><span>Approvals that go to the right people, and come back with a reason</span></li>
      <li><span>Sheets shared row by row, inside your own brand and unit</span></li>
      <li><span>Your own clients and team, under your own name</span></li>
    </ul>
    <div class="authfoot"><?= e(fabo_app_tagline()) ?></div>
<?php endif; ?>
  </aside>

  <main class="authmain">
    <div class="authcard">

      <div class="authbrand small">
        <span class="mark"><?= $siteLogo ? '<img src="logo.php?h=1" alt="" style="width:100%;height:100%;object-fit:contain;border-radius:inherit;background:#fff">' : e(mb_substr($siteName, 0, 1)) ?></span>
        <span class="name"><?= e($siteName) ?></span>
      </div>

<?php if ($error !== ''): ?>
      <div class="flash err"><?= e($error) ?></div>
<?php elseif ($notice !== ''): ?>
      <div class="flash ok"><?= e($notice) ?></div>
<?php endif; ?>

<?php if ($mustChange): ?>

      <h1>Choose your password</h1>
      <p class="lead">You signed in with the password we emailed you. Please set one of your own.</p>
      <form method="post" action="login.php?change=1" autocomplete="on">
        <?= fabo_csrf_field() ?>
        <input type="hidden" name="action" value="change">
        <input type="hidden" name="next" value="<?= e($next) ?>">
        <div class="field">
          <label for="np">New password</label>
          <input id="np" type="password" name="new_password" required minlength="8"
                 autocomplete="new-password" autofocus>
          <div class="hint">At least 8 characters.</div>
        </div>
        <div class="field">
          <label for="cp">Repeat it</label>
          <input id="cp" type="password" name="confirm_password" required minlength="8"
                 autocomplete="new-password">
        </div>
        <button class="btn btn-primary authgo">Save and continue</button>
      </form>
      <div class="authalt"><a href="<?= e(fabo_safe_next($next) ?: 'c.php') ?>">Skip for now</a></div>

<?php elseif ($step === 'forgot'): ?>

      <h1>Reset your password</h1>
      <p class="lead">Give us the address you sign in with and we will send a 6-digit code.</p>
      <form method="post" autocomplete="on">
        <?= fabo_csrf_field() ?>
        <input type="hidden" name="action" value="forgot_start">
        <input type="hidden" name="next" value="<?= e($next) ?>">
        <div class="field">
          <label for="fe">Email address</label>
          <input id="fe" type="email" name="email" required autocomplete="username" autofocus
                 value="<?= e((string) ($_POST['email'] ?? $_SESSION['fabo_reset_email'] ?? '')) ?>">
        </div>
        <button class="btn btn-primary authgo">Send me a code</button>
      </form>
      <div class="authalt"><a href="login.php">Back to sign in</a></div>

<?php elseif ($step === 'code'): ?>

      <h1>Enter your code</h1>
      <p class="lead">We sent a 6-digit code to
        <strong><?= $maskedTo !== '' ? e($maskedTo) : 'your address' ?></strong>.
        It is good for 10 minutes.</p>
      <form method="post">
        <?= fabo_csrf_field() ?>
        <input type="hidden" name="action" value="forgot_code">
        <input type="hidden" name="next" value="<?= e($next) ?>">
        <div class="field">
          <label for="oc">6-digit code</label>
          <input id="oc" class="otp" type="text" name="code" required autofocus
                 inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code"
                 placeholder="––––––">
        </div>
        <button class="btn btn-primary authgo">Verify</button>
      </form>
      <form method="post" class="authresend">
        <?= fabo_csrf_field() ?>
        <input type="hidden" name="action" value="forgot_resend">
        <span>Nothing arrived? Check spam, or</span>
        <button class="linkbtn">send another code</button>
      </form>
      <div class="authalt"><a href="login.php">Back to sign in</a></div>

<?php elseif ($step === 'new'): ?>

      <h1>Choose a new password</h1>
      <p class="lead">Almost there. Pick something you have not used here before.</p>
      <form method="post" autocomplete="on">
        <?= fabo_csrf_field() ?>
        <input type="hidden" name="action" value="forgot_reset">
        <input type="hidden" name="next" value="<?= e($next) ?>">
        <div class="field">
          <label for="rn">New password</label>
          <input id="rn" type="password" name="new_password" required minlength="8"
                 autocomplete="new-password" autofocus>
          <div class="hint">At least 8 characters.</div>
        </div>
        <div class="field">
          <label for="rc">Repeat it</label>
          <input id="rc" type="password" name="confirm_password" required minlength="8"
                 autocomplete="new-password">
        </div>
        <button class="btn btn-primary authgo">Save and sign in</button>
      </form>

<?php else: ?>

      <h1>Sign in</h1>
      <p class="lead"><?= $adminMode
          ? 'Administrator access to every sheet, client and team member.'
          : 'Use the email and password from your welcome message.' ?></p>
      <form method="post" autocomplete="on">
        <?= fabo_csrf_field() ?>
        <?php if ($adminMode): ?>
          <input type="hidden" name="admin" value="1">
        <?php endif; ?>
        <input type="hidden" name="next" value="<?= e($next) ?>">
        <div class="field">
          <label for="em">Email address or user ID</label>
          <?php /* Not type="email": a user ID is not an address, and the browser
                   would refuse to submit one. Several people can share a mailbox,
                   so the ID is what identifies one account outright. */ ?>
          <?php $byId = trim((string) ($_GET['u'] ?? '')) !== '' && !isset($_POST['email']); ?>
          <input id="em" type="text" name="email" required autocomplete="username"
                 inputmode="email" spellcheck="false"
                 value="<?= e((string) ($_POST['email'] ?? $_GET['u'] ?? '')) ?>"
                 <?= $byId ? '' : 'autofocus' ?>>
          <small class="fieldhint"><?= $byId
            ? 'This is your user ID, from your sign-in email. It opens this account '
              . 'and no other, even where several share an email address.'
            : 'Your user ID is in your sign-in email &mdash; use it if your address '
              . 'is shared with somebody else.' ?></small>
        </div>
        <div class="field">
          <label for="pw">Password</label>
          <input id="pw" type="password" name="password" required autocomplete="current-password"
                 <?= $byId ? 'autofocus' : '' ?>>
        </div>
        <button class="btn btn-primary authgo">Sign in</button>
      </form>
      <?php if (!$adminMode): ?>
        <div class="authalt"><a href="login.php?forgot=1">Forgotten your password?</a></div>
      <?php endif; ?>
      <p class="authnote">Your welcome email also holds a private link that opens your
        dashboard without a password.</p>

<?php endif; ?>

    </div>
    <div class="authlegal">&copy; <?= date('Y') ?> <?= e($siteName) ?></div>
  </main>

</div>
</body>
</html>

<?php
/**
 * Event Organizers — an attendee's account: sign up, sign in, reset a password,
 * and My Hub with their tickets, orders, certificates, waiting lists, notices
 * and profile. Separate from portal logins: an attendee reaches nothing of the
 * portal. What they see is found by the email they signed up with, so an account
 * opened after applying still shows those tickets.
 */

require_once __DIR__ . '/lib/eo/public.php';
$org = eo_public_start('attendee');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, private');
if ($org < 0) {
    eo_public_stop(-1, 404, 'Account', 'This page is not open', 'Please check the link you were given.');
}
$u = eo_current_user($org);
$v = (string) ($_GET['v'] ?? '');
$self = static fn(string $view = '') => eo_here($org, 'event-account.php' . ($view !== '' ? '?v=' . $view : ''));
$message = '';
$done = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    fabo_csrf_check();
    $a = (string) ($_POST['action'] ?? '');
    try {
        if ($a === 'signout') {
            unset($_SESSION['eo_user'][$org]);
            header('Location: ' . $self());
            exit;
        }
        if (in_array($a, ['signin', 'signup', 'forgot', 'reset'], true) && fabo_throttle_blocked('ev-auth', fabo_client_ip(), 15, 900)) {
            throw new InvalidArgumentException(fabo_throttle_words());
        }
        switch ($a) {
            case 'signin':
                fabo_throttle_hit('ev-auth', fabo_client_ip());
                $hit = eo_user_signin((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''));
                if (!$hit) {
                    throw new InvalidArgumentException('That email and password do not match.');
                }
                eo_sign_in_user($org, $hit);
                header('Location: ' . $self());
                exit;
            case 'signup':
                fabo_throttle_hit('ev-auth', fabo_client_ip());
                eo_sign_in_user($org, eo_user_signup($_POST));
                header('Location: ' . $self());
                exit;
            case 'forgot':
                fabo_throttle_hit('ev-auth', fabo_client_ip());
                eo_user_forgot((string) ($_POST['email'] ?? ''));
                $done = 'If that email has an account, a reset link is on its way.';
                break;
            case 'reset':
                fabo_throttle_hit('ev-auth', fabo_client_ip());
                if (!eo_user_reset((string) ($_POST['k'] ?? ''), (string) ($_POST['password'] ?? ''))) {
                    throw new InvalidArgumentException('That link has expired. Please ask for a new one.');
                }
                $done = 'Your password is changed. Sign in with it now.';
                $v = '';
                break;
            case 'profile':
                if ($u) {
                    eo_user_save((int) $u['id'], $_POST);
                    $u = eo_current_user($org);
                    $done = 'Saved.';
                }
                break;
            case 'read':
                if ($u) {
                    inc_exec('UPDATE inc_ev_notices SET read_at = NOW() WHERE email = ? AND read_at IS NULL', 's', [(string) $u['email']]);
                }
                break;
        }
    } catch (InvalidArgumentException $e) {
        $message = $e->getMessage();
    }
}

eo_public_head($org, $u ? 'My hub' : 'Sign in', '', true);
$flash = ($message !== '' ? '<div class="inc-warn" role="alert">' . e($message) . '</div>' : '') . ($done !== '' ? '<div class="inc-note" role="status">' . e($done) . '</div>' : '');

if (!$u) {
    $form = static fn(string $action, string $body, string $label) => '<form method="post" class="ev-form">' . fabo_csrf_field() . '<input type="hidden" name="action" value="' . $action . '">' . $body . '<p><button class="btn btn-primary">' . e($label) . '</button></p></form>';
    $mail = '<label>Email <input type="email" name="email" required maxlength="190" autocomplete="username"></label>';
    if ($v === 'signup') {
        echo '<div class="fm-head"><h1>Create your account</h1></div>' . $flash
           . $form('signup', '<label>Your name <input type="text" name="name" required maxlength="190"></label>' . $mail . '<label>Phone <input type="tel" name="phone" maxlength="40"></label><label>Password <input type="password" name="password" required minlength="8" autocomplete="new-password"></label>', 'Create account')
           . '<p class="ev-muted"><a href="' . e($self()) . '">I already have an account</a></p>';
    } elseif ($v === 'forgot') {
        echo '<div class="fm-head"><h1>Forgot your password?</h1></div>' . $flash . $form('forgot', $mail, 'Send me a link');
    } elseif ($v === 'reset') {
        echo '<div class="fm-head"><h1>Choose a new password</h1></div>' . $flash
           . $form('reset', '<input type="hidden" name="k" value="' . e((string) ($_GET['k'] ?? '')) . '"><label>New password <input type="password" name="password" required minlength="8" autocomplete="new-password"></label>', 'Save password');
    } else {
        echo '<div class="fm-head"><h1>Sign in</h1></div>' . $flash . $form('signin', $mail . '<label>Password <input type="password" name="password" required autocomplete="current-password"></label>', 'Sign in')
           . '<p class="ev-muted"><a href="' . e($self('signup')) . '">Create an account</a> · <a href="' . e($self('forgot')) . '">Forgot your password?</a></p>';
    }
    eo_public_foot($org, false);
    exit;
}

$h = eo_user_hub((string) $u['email']);
$today = date('Y-m-d');
$coming = array_filter($h['tickets'], static fn($t) => ($t['end_date'] ?: $t['start_date'] ?: $today) >= $today && $t['status'] === 'issued');
$came = array_filter($h['tickets'], static fn($t) => (int) $t['days_in'] > 0);
echo '<div class="fm-head"><div class="fm-eyebrow">My hub</div><h1>Hello, ' . e($u['name']) . '</h1>'
   . '<ul class="ev-meta"><li>' . count($coming) . ' upcoming</li><li>' . count($came) . ' attended</li><li>' . count($h['certs']) . ' certificates</li></ul></div>' . $flash;
$unread = array_filter($h['notices'], static fn($n) => !$n['read_at']);
if ($h['notices']) {
    echo '<section class="ev-sec"><h2>Notifications' . ($unread ? ' <span class="pill amber">' . count($unread) . ' new</span>' : '') . '</h2><ul class="ev-people">';
    foreach (array_slice($h['notices'], 0, 8) as $n) {
        $safe = str_starts_with((string) $n['link'], 'http');
        echo '<li>' . ($safe ? '<a href="' . e($n['link']) . '">' . e($n['title']) . '</a>' : e($n['title'])) . ' <span class="ev-muted">' . e($n['message']) . '</span></li>';
    }
    echo '</ul>' . ($unread ? '<form method="post">' . fabo_csrf_field() . '<input type="hidden" name="action" value="read"><button class="btn btn-sm">Mark all read</button></form>' : '') . '</section>';
}
echo '<section class="ev-sec"><h2>My tickets</h2>';
if (!$h['tickets']) {
    echo '<p class="ev-muted">Nothing yet. <a href="' . e(eo_here($org, 'event.php')) . '">Browse events</a>.</p>';
} else {
    echo '<ul class="ev-people">';
    foreach ($h['tickets'] as $t) {
        echo '<li><a href="' . e(eo_ticket_url((string) $t['code'])) . '">' . e($t['name']) . '</a> <span class="ev-muted">' . e(eo_dates_text($t)) . ' · ' . e(['issued' => 'Confirmed', 'reserved' => 'Awaiting payment', 'cancelled' => 'Cancelled'][$t['status']]) . '</span></li>';
    }
    echo '</ul>';
}
echo '</section>';
if ($h['orders']) {
    echo '<section class="ev-sec"><h2>My orders</h2><ul class="ev-people">';
    foreach ($h['orders'] as $o) {
        echo '<li><a href="' . e(eo_order_url($o)) . '">' . e($o['event_name']) . '</a> <span class="ev-muted">' . e(eo_money((float) $o['amount'], (string) $o['currency'])) . ' · ' . e(ucfirst((string) $o['status'])) . '</span></li>';
    }
    echo '</ul></section>';
}
if ($h['certs']) {
    echo '<section class="ev-sec"><h2>My certificates</h2><ul class="ev-people">';
    foreach ($h['certs'] as $c) {
        echo '<li><a href="' . e(eo_cert_url((string) $c['token'])) . '">' . e($c['event_name']) . '</a> <span class="ev-muted">' . e($c['number']) . '</span></li>';
    }
    echo '</ul></section>';
}
if ($h['waiting']) {
    echo '<section class="ev-sec"><h2>Waiting lists</h2><ul class="ev-people">';
    foreach ($h['waiting'] as $w) {
        echo '<li>' . e($w['event_name']) . ' <span class="ev-muted">' . ($w['status'] === 'notified' ? 'A spot is open for you' : 'Number ' . (int) $w['position']) . '</span></li>';
    }
    echo '</ul></section>';
}
echo '<section class="ev-sec"><h2>My profile</h2><form method="post" class="ev-form">' . fabo_csrf_field() . '<input type="hidden" name="action" value="profile">';
foreach (['name' => 'Name', 'phone' => 'Phone', 'organization' => 'Organisation', 'job_title' => 'Job title', 'location' => 'Location'] as $key => $label) {
    echo '<label>' . e($label) . ' <input type="text" name="' . $key . '" value="' . e((string) $u[$key]) . '" maxlength="190"></label>';
}
echo '<label>About you <textarea name="bio" rows="3" maxlength="1000">' . e((string) $u['bio']) . '</textarea></label><p><button class="btn btn-primary">Save</button></p></form></section>';
echo '<form method="post">' . fabo_csrf_field() . '<input type="hidden" name="action" value="signout"><button class="btn">Sign out</button></form>';
eo_public_foot($org, false);

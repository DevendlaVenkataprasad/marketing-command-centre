<?php
/**
 * The Campus portal (campus.php): where students, faculty, mentors and
 * institution admins sign in, with their own email and password (edu_people),
 * never a staff account. One person, one sign-in; someone with several roles
 * switches between them at the top.
 *
 *   campus.php?o=<portal key>              sign in (or the home of the role in use)
 *   campus.php?o=<key>&invite=<token>      set a password from a sign-in link
 *   campus.php?o=<key>&v=forgot            ask for a new sign-in link
 *   campus.php?o=<key>&v=profile           their details and password
 *   campus.php?o=<key>&as=<role id>        switch role
 *
 * On an organisation's own address the key is not needed. What anyone sees
 * is decided here on every request from the database: their roles, the
 * institution each role is in, and which portals the organisation has open.
 */

require_once __DIR__ . '/../ui.php';
require_once __DIR__ . '/../throttle.php';
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/portal_lms.php';
require_once __DIR__ . '/portal_game.php';
require_once __DIR__ . '/portal_p4.php';
require_once __DIR__ . '/portal_privacy.php';

/* ================================================================ session */

/** The organisation this request is for: the key in the address, else the organisation's own web address. */
function edu_portal_org(): ?int
{
    $key = (string) ($_GET['o'] ?? $_POST['o'] ?? '');
    if ($key !== '') {
        return edu_org_by_key($key);
    }
    require_once __DIR__ . '/../orghost.php';
    $org = fabo_request_org();
    if ($org > 0) {
        edu_install();
        return fabo_one('SELECT org_id FROM edu_settings WHERE org_id = ? AND k = "portal_key"', 'i', [$org]) ? $org : null;
    }
    return null;
}

function edu_purl(array $q = []): string
{
    return 'campus.php?' . http_build_query(['o' => edu_portal_key($GLOBALS['edu_porg'])] + $q);
}

/** The signed-in Campus person for this organisation, or null. Identity comes from the database every time. */
function edu_portal_person(int $org): ?array
{
    fabo_session();
    $s = $_SESSION['edu'] ?? null;
    if (!is_array($s) || (int) ($s['org'] ?? -1) !== $org) {
        return null;
    }
    $p = edu_person($org, (int) ($s['pid'] ?? 0));
    if (!$p || $p['status'] !== 'active' || $p['pass_hash'] === null || !hash_equals(edu_fingerprint($p), (string) ($s['fp'] ?? ''))) {
        unset($_SESSION['edu']);
        return null;
    }
    // Idle and absolute limits: shorter for roles that see many people's records.
    $now = time();
    if ($now - (int) ($s['seen'] ?? $now) > (int) ($s['idle'] ?? 28800) || $now - (int) ($s['at'] ?? $now) > (int) ($s['abs'] ?? 604800)) {
        edu_seclog($org, (int) $p['id'], 'timeout');
        unset($_SESSION['edu']);
        $_SESSION['edu_timeout'] = 1;
        return null;
    }
    $_SESSION['edu']['seen'] = $now;
    return $p;
}

/** Signs in. $proven: the email was just proven (a sign-in link), so no code is asked for. */
function edu_portal_login(int $org, array $p, bool $proven = false): void
{
    fabo_session();
    session_regenerate_id(true);
    [$idle, $abs] = edu_session_limits($org, (int) $p['id']);
    $mfa = !$proven && edu_mfa_required($org, $p) ? 'pending' : 'ok';
    $_SESSION['edu'] = ['org' => $org, 'pid' => (int) $p['id'], 'fp' => edu_fingerprint($p), 'role' => 0,
                         'at' => time(), 'seen' => time(), 'idle' => $idle, 'abs' => $abs, 'mfa' => $mfa];
    edu_seclog($org, (int) $p['id'], 'login_ok', $proven ? 'sign-in link' : '');
    if ($mfa === 'pending') {
        edu_mfa_send($org, $p);
    }
    fabo_exec('UPDATE edu_people SET last_login_at = ? WHERE id = ?', 'si', [edu_now(), (int) $p['id']]);
}

/** Their roles the organisation has a portal open for. */
function edu_portal_roles(int $org, int $pid): array
{
    return array_values(array_filter(edu_person_roles($org, $pid), static fn($r) => edu_portal_on($org, $r['role'])));
}

/* =================================================================== main */

function edu_portal_main(): void
{
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store, private');
    $org = edu_portal_org();
    if ($org === null) {
        fabo_simple_page('Campus portal', 'This address is not complete. Use the Campus portal link your institution sent you.');
        return;
    }
    $GLOBALS['edu_porg'] = $org;
    edu_lms_install();
    edu_game_install();
    edu_ideas_install();
    fabo_session();
    // A certificate check needs nobody signed in.
    if (isset($_GET['verify'])) {
        edu_portal_verify($org, (string) $_GET['verify']);
        return;
    }
    $v = (string) ($_GET['v'] ?? '');
    $post = $_SERVER['REQUEST_METHOD'] === 'POST';
    if ($post) {
        fabo_csrf_check();
    }
    $a = $post ? (string) ($_POST['a'] ?? '') : '';
    edu_privacy_install();
    // A parent or guardian confirming consent, and the notice itself: nobody signed in.
    if (isset($_GET['guardian'])) {
        edu_portal_guardian($org, (string) $_GET['guardian'], $a);
        return;
    }
    if ($v === 'privacy') {
        edu_portal_notice($org);
        return;
    }

    // A sign-in link: choose a password, then in.
    $invite = (string) ($_GET['invite'] ?? '');
    if ($invite !== '') {
        edu_portal_invite($org, $invite, $a === 'set-password');
        return;
    }
    if ($a === 'logout') {
        if (!empty($_SESSION['edu']['pid'])) {
            edu_seclog($org, (int) $_SESSION['edu']['pid'], 'logout');
        }
        unset($_SESSION['edu']);
        header('Location: ' . edu_purl(), true, 303);
        exit;
    }
    $me = edu_portal_person($org);
    if (!$me) {
        if ($v === 'forgot') {
            edu_portal_forgot($org, $a === 'forgot');
            return;
        }
        edu_portal_signin($org, $a === 'login');
        return;
    }
    // The emailed code, when one is owed.
    if (($_SESSION['edu']['mfa'] ?? 'ok') !== 'ok') {
        edu_portal_code($org, $me, $a);
        return;
    }
    if ($post) {
        edu_portal_privacy_post($org, $me, $a);         // data requests: redirects when it acts
    }
    if ($v === 'mydata' && !empty($_GET['dl'])) {
        edu_portal_mydata($org, $me);
    }
    if ((int) $me['must_change'] === 1 || $v === 'profile') {
        edu_portal_profile($org, $me, $post && in_array($a, ['profile', 'password'], true));
        return;
    }
    // The privacy notice accepted (and a guardian's consent for a student under 18).
    if (($gate = edu_consent_gate($org, $me)) !== '') {
        edu_portal_consent($org, $me, $gate, $a);
        return;
    }
    // A course file (cover, lesson document): checked against what this person may open.
    if ($v === 'file' && !$post) {
        edu_portal_file($org, $me);
    }

    $roles = edu_portal_roles($org, (int) $me['id']);
    if (!$roles) {
        edu_portal_shell($org, $me, [], null, static function () {
            echo '<section class="cp-card"><h1>Nothing to show yet</h1><p>Your account has no role the portal is open for. Ask your institution to add you as a student, faculty member, mentor or institution admin.</p></section>';
        });
        return;
    }
    if (isset($_GET['as'])) {
        $_SESSION['edu']['role'] = (int) $_GET['as'];
    }
    $role = null;
    foreach ($roles as $r) {
        if ((int) $r['id'] === (int) ($_SESSION['edu']['role'] ?? 0)) {
            $role = $r;
        }
    }
    $role ??= $roles[0];
    $_SESSION['edu']['role'] = (int) $role['id'];

    $GLOBALS['edu_me_name'] = $me['name'] . ' (Campus #' . (int) $me['id'] . ')';
    $view = isset(edu_portal_views($role['role'])[$v]) ? $v : 'home';
    // Those who see many people's records leave a trace of what they opened.
    if (in_array($role['role'], ['inst_admin', 'council'], true) && !$post) {
        edu_access($org, $GLOBALS['edu_me_name'], 'view', 0, edu_role_label($role['role']) . ': ' . $view . ($role['inst_name'] !== '' ? ' · ' . $role['inst_name'] : ''));
    }
    $GLOBALS['edu_pview'] = $view;
    if ($post) {
        edu_portal_post_p4($org, $me, $role, $a);       // ideas and applying to incubation: redirects when it acts
        edu_portal_post_game($org, $me, $role, $a);     // rewards, store, kudos: redirects when it acts
        edu_portal_post_lms($org, $me, $role, $a);      // learning and mentoring: redirects when it acts
        if ($role['role'] === 'inst_admin') {
            edu_portal_inst_post($org, $me, $role, $a);
        }
    }
    edu_portal_report_csv_maybe($org, $role, $view);
    edu_portal_shell($org, $me, $roles, $role, static function () use ($org, $me, $role, $view) {
        edu_portal_view($org, $me, $role, $view);
    });
}

/* ================================================================== shell */

function edu_portal_head(int $org, string $title): void
{
    $name = edu_org_name($org);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<meta name="robots" content="noindex,nofollow"><title>' . e($title) . ' · ' . e($name) . '</title>'
       . '<link rel="icon" href="logo.php?edu=' . e(edu_portal_key($org)) . '">'
       . '<link rel="stylesheet" href="' . e(fabo_asset('assets/app.css')) . '"><link rel="stylesheet" href="' . e(fabo_asset('assets/academic.css')) . '"></head><body class="cp">';
}

function edu_portal_brand(int $org): string
{
    return '<span class="cp-brand"><img src="logo.php?edu=' . e(edu_portal_key($org)) . '" alt="" width="34" height="34"><span><strong>' . e(edu_org_name($org)) . '</strong><em>Campus portal</em></span></span>';
}

function edu_portal_shell(int $org, array $me, array $roles, ?array $role, callable $body): void
{
    edu_portal_head($org, $role ? edu_role_label($role['role']) : 'Campus');
    echo '<header class="cp-top">' . edu_portal_brand($org) . '<nav class="cp-me">';
    if (count($roles) > 1) {
        echo '<form method="get" class="cp-switch"><input type="hidden" name="o" value="' . e(edu_portal_key($org)) . '"><label><span>Viewing as</span><select name="as" onchange="this.form.submit()">';
        foreach ($roles as $r) {
            echo '<option value="' . (int) $r['id'] . '"' . ($role && (int) $r['id'] === (int) $role['id'] ? ' selected' : '') . '>'
               . e(edu_role_label($r['role']) . ($r['inst_name'] !== '' ? ' · ' . $r['inst_name'] : '')) . '</option>';
        }
        echo '</select></label><noscript><button class="btn btn-sm">Switch</button></noscript></form>';
    }
    echo '<a class="cp-avatar" href="' . e(edu_purl(['v' => 'profile'])) . '" title="Your profile">' . e(edu_initials($me['name'])) . '</a>'
       . '<form method="post">' . fabo_csrf_field() . '<input type="hidden" name="a" value="logout"><button class="btn btn-sm">Sign out</button></form></nav></header>'
       . ($role ? edu_portal_tabs($role['role'], (string) ($GLOBALS['edu_pview'] ?? 'home')) : '')
       . '<main class="cp-main" id="main">';
    $flash = $_SESSION['edu_pflash'] ?? null;
    unset($_SESSION['edu_pflash']);
    if ($flash) {
        echo '<div class="edu-flash ' . e($flash[0]) . '" role="status">' . e($flash[1]) . ($flash[2] ?? '') . '</div>';
    }
    $body();
    echo '</main><script src="' . e(fabo_asset('assets/academic.js')) . '" defer></script></body></html>';
}

function edu_initials(string $name): string
{
    $name = preg_replace('/^(dr|prof|mr|mrs|ms|shri|smt)\.?\s+/i', '', trim($name));
    $parts = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY);
    $i = mb_strtoupper(mb_substr($parts[0] ?? '?', 0, 1) . (count($parts) > 1 ? mb_substr(end($parts), 0, 1) : ''));
    return $i !== '' ? $i : '?';
}

function edu_pflash(string $tone, string $msg, string $extra = ''): void
{
    $_SESSION['edu_pflash'] = [$tone, $msg, $extra];
}

/** A page without the signed-in frame: sign in, set a password, forgotten password. */
function edu_portal_gate(int $org, string $title, string $inner): void
{
    edu_portal_head($org, $title);
    $welcome = edu_get($org, 'portal_welcome');
    echo '<main class="cp-gate" id="main"><div class="cp-gate-card">' . edu_portal_brand($org)
       . ($welcome !== '' ? '<p class="cp-welcome">' . nl2br(e($welcome)) . '</p>' : '') . $inner . '</div>'
       . '<p class="cp-foot">For students, faculty, mentors and institution admins of ' . e(edu_org_name($org)) . '.</p></main></body></html>';
}

/* ============================================================ the gates */

function edu_portal_signin(int $org, bool $posted): void
{
    $err = '';
    $email = '';
    if ($posted) {
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        $ip = fabo_client_ip();
        $acct = $org . '|' . $email;
        if (fabo_throttle_blocked('edu-login-ip', $ip, 30, 900) || fabo_throttle_blocked('edu-login-acct', $acct, 8, 900)) {
            $err = fabo_throttle_words();
        } else {
            $p = edu_check_login($org, $email, (string) ($_POST['password'] ?? ''));
            if ($p) {
                fabo_throttle_clear('edu-login-acct', $acct);
                edu_portal_login($org, $p);
                header('Location: ' . edu_purl(), true, 303);
                exit;
            }
            fabo_throttle_hit('edu-login-ip', $ip);
            $who = edu_person_by_email($org, $email);
            edu_seclog($org, (int) ($who['id'] ?? 0), 'login_fail', $who ? '' : 'unknown: ' . mb_substr($email, 0, 120));
            fabo_throttle_hit('edu-login-acct', $acct);
            $err = 'That email and password do not match an account here. If you have never signed in, use the link your institution emailed you.';
        }
    }
    $timedOut = !empty($_SESSION['edu_timeout']);
    unset($_SESSION['edu_timeout']);
    edu_portal_gate($org, 'Sign in', '<h1>Sign in</h1>' . ($timedOut && $err === '' ? '<div class="edu-flash info" role="status">You were signed out to keep your account safe. Please sign in again.</div>' : '') . ($err !== '' ? '<div class="edu-flash err" role="alert">' . e($err) . '</div>' : '')
        . '<form method="post" class="cp-form">' . fabo_csrf_field() . '<input type="hidden" name="a" value="login">'
        . '<label class="field"><span>Email</span><input type="email" name="email" required autocomplete="username" value="' . e($email) . '" autofocus></label>'
        . '<label class="field"><span>Password</span><input type="password" name="password" required autocomplete="current-password"></label>'
        . '<button class="btn btn-primary cp-wide">Sign in</button></form>'
        . '<p class="cp-alt"><a href="' . e(edu_purl(['v' => 'forgot'])) . '">Forgot your password, or never set one?</a></p>');
}

function edu_portal_forgot(int $org, bool $posted): void
{
    if ($posted) {
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        $ip = fabo_client_ip();
        if (!fabo_throttle_blocked('edu-reset-ip', $ip, 10, 3600) && !fabo_throttle_blocked('edu-reset-acct', $org . '|' . $email, 3, 3600)) {
            fabo_throttle_hit('edu-reset-ip', $ip);
            fabo_throttle_hit('edu-reset-acct', $org . '|' . $email);
            $p = edu_person_by_email($org, $email);
            if ($p && $p['status'] === 'active' && edu_portal_roles($org, (int) $p['id']) && !str_ends_with($email, '.example')) {
                edu_invite_send($org, (int) $p['id'], true);
            }
        }
        // The same answer either way: the page never says whether an address has an account.
        edu_portal_gate($org, 'Check your email', '<h1>Check your email</h1><p>If ' . e($email) . ' has an account here, a link to set your password is on its way. It works for '
            . EDU_INVITE_DAYS . ' days.</p><p class="cp-alt"><a href="' . e(edu_purl()) . '">Back to sign in</a></p>');
        return;
    }
    edu_portal_gate($org, 'Set or reset your password', '<h1>Set or reset your password</h1><p class="muted">Type the email your institution has for you. We will send a link to set a password.</p>'
        . '<form method="post" class="cp-form">' . fabo_csrf_field() . '<input type="hidden" name="a" value="forgot">'
        . '<label class="field"><span>Email</span><input type="email" name="email" required autocomplete="username" autofocus></label>'
        . '<button class="btn btn-primary cp-wide">Send the link</button></form><p class="cp-alt"><a href="' . e(edu_purl()) . '">Back to sign in</a></p>');
}

function edu_portal_invite(int $org, string $token, bool $posted): void
{
    $p = edu_invite_person($token);
    if (!$p || (int) $p['org_id'] !== $org) {
        edu_portal_gate($org, 'Link expired', '<h1>This link has expired</h1><p>Sign-in links work for ' . EDU_INVITE_DAYS . ' days and only once. Ask for a new one below.</p>'
            . '<p class="cp-alt"><a class="btn btn-primary" href="' . e(edu_purl(['v' => 'forgot'])) . '">Send me a new link</a></p>');
        return;
    }
    $err = '';
    if ($posted) {
        $a = (string) ($_POST['password'] ?? '');
        $b = (string) ($_POST['password2'] ?? '');
        $err = edu_password_ok($a, $p) ?? ($a !== $b ? 'The two passwords are not the same.' : '');
        if ($err === '') {
            edu_password_set($org, (int) $p['id'], $a);
            edu_portal_login($org, edu_person($org, (int) $p['id']), true);
            edu_pflash('ok', 'Welcome, ' . $p['name'] . '. Your password is set.');
            header('Location: ' . edu_purl(), true, 303);
            exit;
        }
    }
    edu_portal_gate($org, 'Set your password', '<h1>Welcome, ' . e($p['name']) . '</h1><p class="muted">Choose a password. You will sign in with <strong>' . e((string) $p['email']) . '</strong>.</p>'
        . ($err !== '' ? '<div class="edu-flash err" role="alert">' . e($err) . '</div>' : '')
        . '<form method="post" class="cp-form">' . fabo_csrf_field() . '<input type="hidden" name="a" value="set-password">'
        . '<input type="email" name="email" value="' . e((string) $p['email']) . '" autocomplete="username" hidden>'
        . '<label class="field"><span>New password (at least 10 characters; three or four words work well)</span><input type="password" name="password" required minlength="10" autocomplete="new-password" autofocus></label>'
        . '<label class="field"><span>Type it again</span><input type="password" name="password2" required minlength="10" autocomplete="new-password"></label>'
        . '<button class="btn btn-primary cp-wide">Set password and sign in</button></form>');
}

function edu_portal_profile(int $org, array $me, bool $posted): void
{
    $must = (int) $me['must_change'] === 1;
    if ($posted) {
        try {
            if (($_POST['a'] ?? '') === 'password') {
                if (!password_verify((string) ($_POST['current'] ?? ''), (string) $me['pass_hash'])) {
                    throw new InvalidArgumentException('Your current password is not right.');
                }
                if ((string) ($_POST['password'] ?? '') !== (string) ($_POST['password2'] ?? '')) {
                    throw new InvalidArgumentException('The two new passwords are not the same.');
                }
                edu_password_set($org, (int) $me['id'], (string) ($_POST['password'] ?? ''));
                edu_portal_login($org, edu_person($org, (int) $me['id']), true);
                edu_pflash('ok', 'Password changed. Other devices are signed out.');
                header('Location: ' . edu_purl($must ? [] : ['v' => 'profile']), true, 303);
                exit;
            }
            if (!$must) {
                // Only the name and phone: email, roll number and roles are kept by the institution.
                $data = $me;
                $data['name'] = (string) ($_POST['name'] ?? $me['name']);
                $data['phone'] = (string) ($_POST['phone'] ?? $me['phone']);
                edu_person_save($org, $data, (int) $me['id']);
                edu_pflash('ok', 'Saved.');
            }
        } catch (InvalidArgumentException $e) {
            edu_pflash('err', $e->getMessage());
        }
        header('Location: ' . edu_purl(['v' => 'profile']), true, 303);
        exit;
    }
    $roles = edu_portal_roles($org, (int) $me['id']);
    edu_portal_shell($org, $me, $must ? [] : $roles, null, static function () use ($org, $me, $must, $roles) {
        if ($must) {
            echo '<div class="edu-flash info">You were given a temporary password. Choose your own to continue.</div>';
        } else {
            echo '<p class="edu-back"><a href="' . e(edu_purl()) . '">← Back</a></p><section class="cp-card"><h1>Your profile</h1>'
               . '<form method="post" class="cp-form">' . fabo_csrf_field() . '<input type="hidden" name="a" value="profile">'
               . '<label class="field"><span>Name</span><input name="name" required maxlength="160" value="' . e($me['name']) . '"></label>'
               . '<label class="field"><span>Phone</span><input name="phone" maxlength="40" value="' . e($me['phone']) . '"></label>'
               . '<p class="muted">Email: ' . e((string) $me['email']) . ($me['roll_no'] !== '' ? ' · Roll no: ' . e($me['roll_no']) : '') . '. Your institution keeps these; ask them to correct anything.</p>'
               . '<button class="btn btn-primary">Save</button></form><h2>Your roles</h2><div class="cp-chips">';
            foreach ($roles as $r) {
                echo '<span class="edu-role r-' . e($r['role']) . '">' . e(edu_role_label($r['role'])) . '</span> <span class="muted">' . e(trim($r['inst_name'] . ($r['dept_name'] !== '' ? ' · ' . $r['dept_name'] : ''), ' ·') ?: 'Across the organisation') . '</span><br>';
            }
            echo '</div></section>';
        }
        echo '<section class="cp-card"><h2>' . ($must ? 'Choose your password' : 'Change password') . '</h2><form method="post" class="cp-form">' . fabo_csrf_field() . '<input type="hidden" name="a" value="password">'
           . '<input type="email" name="email" value="' . e((string) $me['email']) . '" autocomplete="username" hidden>'
           . '<label class="field"><span>' . ($must ? 'Temporary password' : 'Current password') . '</span><input type="password" name="current" required autocomplete="current-password"></label>'
           . '<label class="field"><span>New password (at least 10 characters; three or four words work well)</span><input type="password" name="password" required minlength="10" autocomplete="new-password"></label>'
           . '<label class="field"><span>Type it again</span><input type="password" name="password2" required minlength="10" autocomplete="new-password"></label>'
           . '<button class="btn btn-primary">' . ($must ? 'Set password' : 'Change password') . '</button></form></section>';
        if (!$must) {
            edu_pv_your_data($org, $me);
        }
    });
}

/* ============================================================ role homes */

function edu_portal_hello(array $me, array $role, string $line): void
{
    $h = (int) date('G');
    $greet = $h < 12 ? 'Good morning' : ($h < 17 ? 'Good afternoon' : 'Good evening');
    $first = preg_split('/\s+/', preg_replace('/^(dr|prof|mr|mrs|ms)\.?\s+/i', '', trim($me['name'])))[0] ?? '';
    echo '<section class="cp-hello r-' . e($role['role']) . '"><span class="edu-role r-' . e($role['role']) . '">' . e(edu_role_label($role['role'])) . '</span>'
       . '<h1>' . e($greet . ', ' . $first) . '</h1><p>' . e($line) . '</p></section>';
}

function edu_portal_person_list(array $rows, string $empty, bool $showRoll = true): void
{
    echo '<ul class="cp-people">';
    foreach ($rows as $p) {
        $sub = array_filter([$showRoll ? $p['roll_no'] : '', $p['programme'] ?? '', !empty($p['year_of_study']) ? 'Year ' . (int) $p['year_of_study'] : '', $p['title'] ?? '']);
        echo '<li><span class="cp-av">' . e(edu_initials($p['name'])) . '</span><span><strong>' . e($p['name']) . '</strong><em>' . e(implode(' · ', $sub)) . '</em></span>'
           . ((string) $p['email'] !== '' ? '<a href="mailto:' . e((string) $p['email']) . '" class="cp-mail">' . e((string) $p['email']) . '</a>' : '') . '</li>';
    }
    if (!$rows) {
        echo '<li class="edu-empty">' . e($empty) . '</li>';
    }
    echo '</ul>';
}

function edu_portal_home_student(int $org, array $me, array $role): void
{
    edu_portal_hello($me, $role, trim($role['inst_name'] . ($role['dept_name'] !== '' ? ' · ' . $role['dept_name'] : '')));
    echo '<div class="cp-grid"><section class="cp-card"><h2>Your details</h2><dl class="edu-dl">'
       . '<dt>Institution</dt><dd>' . e($role['inst_name']) . '</dd>'
       . '<dt>Department</dt><dd>' . e($role['dept_name'] ?: '—') . '</dd>'
       . '<dt>Programme</dt><dd>' . e($me['programme'] ?: '—') . '</dd>'
       . '<dt>Year</dt><dd>' . e($me['year_of_study'] ? (string) $me['year_of_study'] : '—') . '</dd>'
       . '<dt>Roll no</dt><dd>' . e($me['roll_no'] ?: '—') . '</dd>'
       . '<dt>APAAR ID</dt><dd>' . e($me['apaar_id'] ?: '—') . '</dd></dl></section>';
    echo '<section class="cp-card"><h2>Your mentors</h2>';
    edu_portal_person_list(edu_student_mentors($org, (int) $me['id']), 'No mentor yet. Your institution links mentors to students.', false);
    echo '</section></div>';
    edu_pv_student_home_extra($org, $me);
}

/** The students a faculty role covers: their department's, or the whole institution's when the role has no department. */
function edu_portal_faculty_students(int $org, array $role): array
{
    $f = ['role' => 'student', 'inst' => (int) $role['institution_id'], 'limit' => 500];
    if ((int) $role['department_id'] > 0) {
        $f['dept'] = (int) $role['department_id'];
    }
    return edu_people($org, $f);
}

function edu_portal_home_faculty(int $org, array $me, array $role): void
{
    $students = edu_portal_faculty_students($org, $role);
    $total = (int) ($students[0]['n'] ?? 0);
    edu_portal_hello($me, $role, trim(($role['title'] !== '' ? $role['title'] . ' · ' : '') . ($role['dept_name'] !== '' ? $role['dept_name'] . ', ' : '') . $role['inst_name']));
    $colleagues = array_values(array_filter(edu_people($org, ['role' => 'faculty', 'inst' => (int) $role['institution_id']] + ((int) $role['department_id'] > 0 ? ['dept' => (int) $role['department_id']] : []) + ['limit' => 200]),
                                            static fn($p) => (int) $p['id'] !== (int) $me['id']));
    foreach ($colleagues as &$c) {
        $c['title'] = '';
        foreach ($c['roles'] as $r) {
            if ($r['role'] === 'faculty' && (int) $r['institution_id'] === (int) $role['institution_id']) {
                $c['title'] = $r['title'];
            }
        }
    }
    unset($c);
    echo '<div class="cp-kpis"><div><b>' . fabo_thousands($total) . '</b><span>Students</span></div><div><b>' . count($colleagues) . '</b><span>Colleagues</span></div></div>';
    echo '<section class="cp-card"><div class="cp-card-h"><h2>Students' . ($role['dept_name'] !== '' ? ' in ' . e($role['dept_name']) : '') . '</h2>'
       . '<input type="search" class="cp-search" placeholder="Search" aria-label="Search students" data-filter="#cp-stu"></div><div id="cp-stu">';
    edu_portal_person_list($students, 'No students listed yet.');
    echo '</div>' . ($total > count($students) ? '<p class="muted">Showing the first ' . count($students) . ' of ' . fabo_thousands($total) . '.</p>' : '') . '</section>';
    echo '<section class="cp-card"><h2>Colleagues</h2>';
    edu_portal_person_list($colleagues, 'No colleagues listed yet.', false);
    echo '</section>';
}

function edu_portal_home_mentor(int $org, array $me, array $role): void
{
    $students = edu_mentor_students($org, (int) $me['id']);
    // Where each student is, for the list.
    foreach ($students as &$s) {
        $s['title'] = '';
        foreach (edu_person_roles($org, (int) $s['id']) as $r) {
            if ($r['role'] === 'student') {
                $s['title'] = trim($r['inst_name'] . ($r['dept_name'] !== '' ? ' · ' . $r['dept_name'] : ''));
                break;
            }
        }
    }
    unset($s);
    edu_portal_hello($me, $role, $role['title'] !== '' ? $role['title'] : 'Mentor');
    $up = edu_mentor_slots($org, (int) $me['id'], true);
    $booked = array_values(array_filter($up, static fn($s) => $s['status'] === 'booked'));
    $past = edu_mentor_slots($org, (int) $me['id'], false);
    $rated = array_filter(array_column($past, 'rating'));
    echo '<div class="cp-kpis"><div><b>' . count($students) . '</b><span>Students you mentor</span></div><div><b>' . count($booked) . '</b><span>Sessions booked</span></div>'
       . '<div><b>' . (count($up) - count($booked)) . '</b><span>Open times</span></div><div><b>' . ($rated ? round(array_sum($rated) / count($rated), 1) . ' ★' : '—') . '</b><span>Your rating</span></div></div>';
    if ($booked) {
        echo '<section class="cp-card"><div class="cp-card-h"><h2>Next sessions</h2><a href="' . e(edu_purl(['v' => 'sessions'])) . '">All sessions</a></div><ul class="cp-sessions">';
        foreach (array_slice($booked, 0, 4) as $s) {
            echo edu_session_li($s, 'mentor') . '</li>';
        }
        echo '</ul></section>';
    } elseif (!$up) {
        echo '<section class="cp-card"><p>You have no open times. <a href="' . e(edu_purl(['v' => 'sessions'])) . '">Offer a time</a> so your students can book you.</p></section>';
    }
    echo '<section class="cp-card"><div class="cp-card-h"><h2>Your students</h2><input type="search" class="cp-search" placeholder="Search" aria-label="Search students" data-filter="#cp-mst"></div><ul class="cp-people" id="cp-mst">';
    foreach ($students as $s) {
        $learn = edu_student_learning($org, (int) $s['id']);
        $fin = count(array_filter($learn, static fn($c) => $c['completed_at']));
        $now = array_values(array_filter($learn, static fn($c) => !$c['completed_at']));
        echo '<li><span class="cp-av">' . e(edu_initials($s['name'])) . '</span><span><strong>' . e($s['name']) . '</strong><em>' . e(implode(' · ', array_filter([$s['title'], $s['programme'], $s['year_of_study'] ? 'Year ' . (int) $s['year_of_study'] : ''])))
           . '</em><em>' . ($learn ? $fin . ' course' . ($fin === 1 ? '' : 's') . ' finished' . ($now ? ' · now on ' . e($now[0]['title']) . ' (' . (int) $now[0]['progress'] . '%)' : '') : 'No courses yet') . '</em></span>'
           . ((string) $s['email'] !== '' ? '<a href="mailto:' . e((string) $s['email']) . '" class="cp-mail">' . e((string) $s['email']) . '</a>' : '') . '</li>';
    }
    if (!$students) {
        echo '<li class="edu-empty">No students linked to you yet. The programme team links mentors to students.</li>';
    }
    echo '</ul></section>';
    edu_pv_kudos_form($org, $me, $students);
    edu_pv_ideas_table($org, edu_ideas_list($org, null, (int) $me['id']), 'Your students\x27 ideas', true);
}

function edu_portal_home_inst_admin(int $org, array $me, array $role): void
{
    $iid = (int) $role['institution_id'];
    $t = edu_totals($org, [$iid]);
    edu_portal_hello($me, $role, $role['inst_name']);
    echo '<div class="cp-kpis"><div><b>' . fabo_thousands($t['student']) . '</b><span>Students</span></div><div><b>' . fabo_thousands($t['faculty']) . '</b><span>Faculty</span></div>'
       . '<div><b>' . $t['departments'] . '</b><span>Departments</span></div><div><b>' . $t['accounts'] . ' / ' . $t['people'] . '</b><span>Accounts set up</span></div></div>';

    $deptCounts = [];
    foreach (fabo_all('SELECT department_id, role, COUNT(DISTINCT person_id) AS n FROM edu_roles WHERE org_id = ? AND institution_id = ? GROUP BY department_id, role', 'ii', [$org, $iid]) as $r) {
        $deptCounts[(int) $r['department_id']][$r['role']] = (int) $r['n'];
    }
    $depts = edu_departments($org, $iid);
    echo '<section class="cp-card"><h2>Departments</h2><div class="edu-tablewrap"><table class="edu-table"><thead><tr><th>Department</th><th class="n">Students</th><th class="n">Faculty</th></tr></thead><tbody>';
    foreach ($depts as $d) {
        echo '<tr><td><a href="' . e(edu_purl(['dept' => (int) $d['id']])) . '#people">' . e($d['name']) . '</a></td><td class="n">' . (int) ($deptCounts[(int) $d['id']]['student'] ?? 0) . '</td><td class="n">' . (int) ($deptCounts[(int) $d['id']]['faculty'] ?? 0) . '</td></tr>';
    }
    if (!$depts) {
        echo '<tr><td colspan="3" class="edu-empty">No departments yet. The programme team adds them.</td></tr>';
    }
    echo '</tbody></table></div></section>';

    $who = (string) ($_GET['who'] ?? 'student');
    $who = in_array($who, ['student', 'faculty'], true) ? $who : 'student';
    $dept = (int) ($_GET['dept'] ?? 0);
    $q = trim((string) ($_GET['q'] ?? ''));
    $rows = edu_people($org, array_filter(['role' => $who, 'inst' => $iid, 'dept' => $dept, 'q' => $q, 'limit' => 100]));
    $total = (int) ($rows[0]['n'] ?? 0);
    echo '<section class="cp-card" id="people"><div class="cp-card-h"><h2>People</h2><form method="get" class="cp-filters"><input type="hidden" name="o" value="' . e(edu_portal_key($org)) . '">'
       . '<select name="who" aria-label="Role"><option value="student">Students</option><option value="faculty"' . ($who === 'faculty' ? ' selected' : '') . '>Faculty</option></select>'
       . '<select name="dept" aria-label="Department"><option value="0">Every department</option>';
    foreach ($depts as $d) {
        echo '<option value="' . (int) $d['id'] . '"' . ($dept === (int) $d['id'] ? ' selected' : '') . '>' . e($d['name']) . '</option>';
    }
    echo '</select><input type="search" name="q" value="' . e($q) . '" placeholder="Name, email, roll no" aria-label="Search"><button class="btn btn-sm">Show</button></form></div>'
       . '<div class="edu-tablewrap"><table class="edu-table"><thead><tr><th>Name</th><th>Email</th><th>' . ($who === 'student' ? 'Roll no' : 'Designation') . '</th><th>Department</th><th>Account</th><th></th></tr></thead><tbody>';
    foreach ($rows as $p) {
        $d = '';
        $title = '';
        foreach ($p['roles'] as $r) {
            if ($r['role'] === $who && (int) $r['institution_id'] === $iid) {
                $d = $r['dept_name'];
                $title = $r['title'];
            }
        }
        $canInvite = (string) $p['email'] !== '' && $p['pass_hash'] === null && $p['status'] === 'active' && !str_ends_with((string) $p['email'], '.example');
        echo '<tr><td><strong>' . e($p['name']) . '</strong></td><td>' . e((string) $p['email']) . '</td><td>' . e($who === 'student' ? $p['roll_no'] : $title) . '</td><td>' . e($d) . '</td>'
           . '<td>' . ($p['status'] === 'suspended' ? '<span class="pill red">Suspended</span>' : ($p['pass_hash'] !== null ? '<span class="pill green">Set up</span>' : '<span class="pill grey">Not yet</span>')) . '</td>'
           . '<td class="act">' . ($canInvite ? '<form method="post">' . fabo_csrf_field() . '<input type="hidden" name="a" value="invite"><input type="hidden" name="id" value="' . (int) $p['id'] . '"><button class="btn btn-sm">Email sign-in link</button></form>' : '') . '</td></tr>';
    }
    if (!$rows) {
        echo '<tr><td colspan="6" class="edu-empty">Nobody matches.</td></tr>';
    }
    echo '</tbody></table></div>' . ($total > count($rows) ? '<p class="muted">Showing ' . count($rows) . ' of ' . fabo_thousands($total) . '. Search to narrow it.</p>' : '') . '</section>';

    echo '<details class="cp-card"><summary><h2>Add a student or faculty member</h2></summary><form method="post" class="edu-form">' . fabo_csrf_field() . '<input type="hidden" name="a" value="add"><div class="edu-fields">'
       . '<label class="field wide"><span>Full name</span><input name="name" required maxlength="160"></label>'
       . '<label class="field"><span>Email</span><input type="email" name="email" maxlength="160"></label>'
       . '<label class="field"><span>Phone</span><input name="phone" maxlength="40"></label>'
       . '<label class="field"><span>Role</span><select name="role" data-edu-role><option value="student">Student</option><option value="faculty">Faculty</option></select></label>'
       . '<label class="field"><span>Department</span><select name="department_id"><option value="0">None / whole institution</option>';
    foreach ($depts as $d) {
        echo '<option value="' . (int) $d['id'] . '">' . e($d['name']) . '</option>';
    }
    echo '</select></label><label class="field" data-for="student"><span>Roll / registration no</span><input name="roll_no" maxlength="60"></label>'
       . '<label class="field" data-for="student"><span>Programme</span><input name="programme" maxlength="120"></label>'
       . '<label class="field" data-for="student"><span>Year of study</span><input type="number" name="year_of_study" min="1" max="10"></label>'
       . '<label class="field" data-for="faculty"><span>Designation</span><input name="title" maxlength="160"></label></div>'
       . '<label class="edu-check"><input type="checkbox" name="invite" value="1"> Email them a sign-in link now</label><button class="btn btn-primary">Add</button></form></details>';
}

/** What an institution admin may change: people in their own institution, nothing else. */
function edu_portal_inst_post(int $org, array $me, array $role, string $a): void
{
    $iid = (int) $role['institution_id'];
    try {
        if ($a === 'add') {
            $r = (string) ($_POST['role'] ?? '');
            if (!in_array($r, ['student', 'faculty'], true)) {
                throw new InvalidArgumentException('Pick Student or Faculty.');
            }
            $dept = (int) ($_POST['department_id'] ?? 0);
            $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
            $have = $email !== '' ? edu_person_by_email($org, $email) : null;
            // Someone already here (from another institution, say) gains the role; their record is not theirs to change.
            $pid = $have ? (int) $have['id'] : edu_person_save($org, $_POST);
            edu_role_add($org, $pid, $r, $iid, $dept, (string) ($_POST['title'] ?? ''));
            edu_audit($org, $me['name'] . ' (institution admin)', 'person-add', $iid . ' ' . ($_POST['name'] ?? ''));
            $msg = $have ? $have['name'] . ' was already here; they are now ' . edu_role_label($r) . ' at your institution too.' : 'Added.';
            if (!empty($_POST['invite']) && $email !== '' && !$have) {
                [, $w] = edu_invite_send($org, $pid);
                $msg .= ' ' . $w;
            }
            edu_pflash('ok', $msg);
        } elseif ($a === 'invite') {
            $pid = (int) ($_POST['id'] ?? 0);
            if (!fabo_one('SELECT id FROM edu_roles WHERE org_id = ? AND person_id = ? AND institution_id = ? AND role IN ("student","faculty")', 'iii', [$org, $pid, $iid])) {
                throw new InvalidArgumentException('That person is not in your institution.');
            }
            [$ok, $w] = edu_invite_send($org, $pid);
            edu_audit($org, $me['name'] . ' (institution admin)', 'invite', (string) $pid);
            edu_pflash($ok ? 'ok' : 'err', $w);
        }
    } catch (InvalidArgumentException $e) {
        edu_pflash('err', $e->getMessage());
    } catch (mysqli_sql_exception $e) {
        error_log('FABO edu portal post: ' . $e->getMessage());
        edu_pflash('err', 'That could not be saved. Try again.');
    }
    header('Location: ' . edu_purl() . '#people', true, 303);
    exit;
}

<?php
/**
 * The Head of Department portal (hod.php): each HoD signs in with their own
 * email and password (iv_hods), never a staff account, and sees only their
 * own department.
 *
 *   hod.php?o=<key>                  sign in, or the dashboard
 *   hod.php?o=<key>&invite=<token>   set a password from a sign-in link
 *   hod.php?o=<key>&v=forgot         ask for a new link
 *   hod.php?o=<key>&v=form           the idea form: questions, share, pause
 *   hod.php?o=<key>&v=idea&id=       one idea: AI check (portal or own tool), decision
 *   hod.php?o=<key>&v=students       the department's students
 *   hod.php?o=<key>&v=profile        password
 *
 * Who they are, which department, and what they may do is read from the
 * database on every request.
 */

require_once __DIR__ . '/../ui.php';
require_once __DIR__ . '/../throttle.php';
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/gem.php';
require_once __DIR__ . '/app.php';      // the shared pieces: copy box, pills, the answer and AI-check blocks

/* ================================================================ session */

function iv_portal_org(): ?int
{
    $key = (string) ($_GET['o'] ?? $_POST['o'] ?? '');
    if ($key !== '') {
        return iv_org_by_key($key);
    }
    require_once __DIR__ . '/../orghost.php';
    $org = fabo_request_org();
    if ($org > 0) {
        iv_install();
        return fabo_one('SELECT org_id FROM iv_settings WHERE org_id = ? AND k = "portal_key"', 'i', [$org]) ? $org : null;
    }
    return null;
}

function iv_purl(array $q = []): string
{
    return 'hod.php?' . http_build_query(['o' => iv_portal_key($GLOBALS['iv_porg'])] + $q);
}

function iv_portal_hod(int $org): ?array
{
    fabo_session();
    $s = $_SESSION['iv'] ?? null;
    if (!is_array($s) || (int) ($s['org'] ?? -1) !== $org) {
        return null;
    }
    $h = iv_hod($org, (int) ($s['hid'] ?? 0));
    if (!$h || $h['status'] !== 'active' || $h['pass_hash'] === null || !hash_equals(iv_fingerprint($h), (string) ($s['fp'] ?? ''))) {
        unset($_SESSION['iv']);
        return null;
    }
    $now = time();
    if ($now - (int) ($s['seen'] ?? $now) > 28800 || $now - (int) ($s['at'] ?? $now) > 604800) {
        unset($_SESSION['iv']);
        $_SESSION['iv_timeout'] = 1;
        return null;
    }
    $_SESSION['iv']['seen'] = $now;
    return $h;
}

function iv_portal_login(int $org, array $h): void
{
    fabo_session();
    session_regenerate_id(true);
    $_SESSION['iv'] = ['org' => $org, 'hid' => (int) $h['id'], 'fp' => iv_fingerprint($h), 'at' => time(), 'seen' => time()];
    fabo_exec('UPDATE iv_hods SET last_login_at = ? WHERE id = ?', 'si', [iv_now(), (int) $h['id']]);
}

function iv_pflash(string $tone, string $msg, string $extra = ''): void
{
    $_SESSION['iv_pflash'] = [$tone, $msg, $extra];
}

function iv_pgo(array $q = []): void
{
    header('Location: ' . iv_purl($q), true, 303);
    exit;
}

/* =================================================================== main */

function iv_portal_main(): void
{
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store, private');
    $org = iv_portal_org();
    if ($org === null) {
        fabo_simple_page('Head of Department', 'This address is not complete. Use the link you were sent.');
        return;
    }
    $GLOBALS['iv_porg'] = $org;
    fabo_session();
    $post = $_SERVER['REQUEST_METHOD'] === 'POST';
    if ($post) {
        fabo_csrf_check();
    }
    $a = $post ? (string) ($_POST['a'] ?? '') : '';
    $v = (string) ($_GET['v'] ?? '');

    $invite = (string) ($_GET['invite'] ?? '');
    if ($invite !== '') {
        iv_portal_invite($org, $invite, $a === 'set-password');
        return;
    }
    if ($a === 'logout') {
        unset($_SESSION['iv']);
        iv_pgo();
    }
    $me = iv_portal_hod($org);
    if (!$me) {
        if ($v === 'forgot') {
            iv_portal_forgot($org, $a === 'forgot');
            return;
        }
        iv_portal_signin($org, $a === 'login');
        return;
    }
    $dept = iv_department($org, (int) $me['department_id']);
    if (!$dept) {
        unset($_SESSION['iv']);
        fabo_simple_page('Head of Department', 'Your department is no longer set up. Please contact the owner.');
        return;
    }
    $GLOBALS['iv_me'] = $me;
    $actor = $me['name'] . ' (HoD, ' . $dept['name'] . ')';
    if ((int) $me['must_change'] === 1 && $v !== 'profile') {
        $v = 'profile';
    }

    // The round they work in: the newest live one, or one they pick from the past.
    $rounds = fabo_all('SELECT * FROM iv_rounds WHERE org_id = ? AND status <> "draft" ORDER BY opens_at DESC, id DESC', 'i', [$org]);
    $round = null;
    $want = (int) ($_GET['round'] ?? $_POST['round'] ?? 0);
    foreach ($rounds as $r) {
        if ($want && (int) $r['id'] === $want) {
            $round = $r;
        }
    }
    $round ??= iv_round_current($org);

    if ($post) {
        iv_portal_post($org, $me, $dept, $round, $a, $actor);
    }
    $views = ['home' => 'Dashboard', 'form' => 'Idea form', 'idea' => 'Idea', 'students' => 'Students', 'student' => 'Student', 'profile' => 'Profile'];
    $view = isset($views[$v]) ? $v : 'home';
    // One of their students in the panel over the Students list: the profile alone.
    if ($view === 'student' && !empty($_GET['embed'])) {
        header('Content-Type: text/html; charset=utf-8');
        $s = iv_student($org, (int) ($_GET['id'] ?? 0));
        if (!$s || (int) $s['department_id'] !== (int) $dept['id']) {
            http_response_code(404);
            echo '<div class="iv-pn-missing"><p>That student is not in your department.</p></div>';
            exit;
        }
        $rq = $round ? ['round' => (int) $round['id']] : [];
        echo iv_portal_student_panel($org, $me, $dept, $round, $s);
        exit;
    }
    iv_portal_shell($org, $me, $dept, $view, $round, $rounds, static function () use ($org, $me, $dept, $round, $view) {
        ('iv_portal_view_' . $view)($org, $me, $dept, $round);
    });
}

/* ================================================================== shell */

function iv_portal_head(int $org, string $title): void
{
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<meta name="robots" content="noindex,nofollow"><title>' . e($title) . ' · ' . e(iv_org_name($org)) . '</title>'
       . '<link rel="stylesheet" href="' . e(fabo_asset('assets/app.css')) . '"><link rel="stylesheet" href="' . e(fabo_asset('assets/ideas.css')) . '"></head><body class="hp">';
}

function iv_portal_shell(int $org, array $me, array $dept, string $view, ?array $round, array $rounds, callable $body): void
{
    iv_portal_head($org, 'HoD dashboard');
    $tabs = ['home' => 'Dashboard', 'form' => 'Idea form', 'students' => 'Students'];
    echo '<header class="hp-top"><span class="hp-brand"><span class="hp-mark" aria-hidden="true">💡</span><span><strong>' . e(iv_org_name($org)) . '</strong><em>Head of Department · ' . e($dept['name']) . '</em></span></span>'
       . '<nav class="hp-me"><a class="hp-av" href="' . e(iv_purl(['v' => 'profile'])) . '" title="Your profile">' . e(mb_strtoupper(mb_substr($me['name'], 0, 1))) . '</a>'
       . '<form method="post">' . fabo_csrf_field() . '<input type="hidden" name="a" value="logout"><button class="btn btn-sm">Sign out</button></form></nav></header>'
       . '<nav class="hp-tabs" aria-label="Sections">';
    $rq = $round && count($rounds) > 1 ? ['round' => (int) $round['id']] : [];
    foreach ($tabs as $k => $l) {
        $on = $view === $k || ($view === 'idea' && $k === 'home') || ($view === 'student' && $k === 'students');
        echo '<a href="' . e(iv_purl(($k === 'home' ? [] : ['v' => $k]) + $rq)) . '"' . ($on ? ' class="on" aria-current="page"' : '') . '>' . e($l) . '</a>';
    }
    if (count($rounds) > 1 && $round) {
        echo '<form method="get" class="hp-round"><input type="hidden" name="o" value="' . e(iv_portal_key($org)) . '">' . ($view !== 'home' && $view !== 'idea' ? '<input type="hidden" name="v" value="' . e($view) . '">' : '')
           . '<select name="round" onchange="this.form.submit()" aria-label="Round">';
        foreach ($rounds as $r) {
            echo '<option value="' . (int) $r['id'] . '"' . ((int) $r['id'] === (int) $round['id'] ? ' selected' : '') . '>' . e($r['name']) . '</option>';
        }
        echo '</select></form>';
    }
    echo '</nav><main class="hp-main iv" id="main">';
    $flash = $_SESSION['iv_pflash'] ?? null;
    unset($_SESSION['iv_pflash']);
    if ($flash) {
        echo '<div class="iv-flash ' . e($flash[0]) . '" role="status">' . e($flash[1]) . ($flash[2] ?? '') . '</div>';
    }
    $body();
    echo '</main><script src="' . e(fabo_asset('assets/ideas.js')) . '" defer></script></body></html>';
}

function iv_portal_gate(int $org, string $title, string $inner): void
{
    iv_portal_head($org, $title);
    echo '<main class="hp-gate" id="main"><div class="hp-gate-card"><span class="hp-brand"><span class="hp-mark" aria-hidden="true">💡</span><span><strong>' . e(iv_org_name($org)) . '</strong><em>Head of Department dashboard</em></span></span>'
       . $inner . '</div></main></body></html>';
}

/* ============================================================ the gates */

function iv_portal_signin(int $org, bool $posted): void
{
    $err = '';
    $email = '';
    if ($posted) {
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        $ip = fabo_client_ip();
        $acct = $org . '|' . $email;
        if (fabo_throttle_blocked('iv-login-ip', $ip, 30, 900) || fabo_throttle_blocked('iv-login-acct', $acct, 8, 900)) {
            $err = fabo_throttle_words();
        } else {
            $h = iv_check_login($org, $email, (string) ($_POST['password'] ?? ''));
            if ($h) {
                fabo_throttle_clear('iv-login-acct', $acct);
                iv_portal_login($org, $h);
                iv_pgo();
            }
            fabo_throttle_hit('iv-login-ip', $ip);
            fabo_throttle_hit('iv-login-acct', $acct);
            $err = 'That email and password do not match. If you have never signed in, use the link you were emailed, or ask for a new one below.';
        }
    }
    $timed = !empty($_SESSION['iv_timeout']);
    unset($_SESSION['iv_timeout']);
    iv_portal_gate($org, 'Sign in', '<h1>Sign in</h1>' . ($timed && $err === '' ? '<div class="iv-flash info">You were signed out to keep your account safe. Please sign in again.</div>' : '')
        . ($err !== '' ? '<div class="iv-flash err" role="alert">' . e($err) . '</div>' : '')
        . '<form method="post" class="iv-form">' . fabo_csrf_field() . '<input type="hidden" name="a" value="login">'
        . '<label class="field"><span>Email</span><input type="email" name="email" required autocomplete="username" value="' . e($email) . '" autofocus></label>'
        . '<label class="field"><span>Password</span><input type="password" name="password" required autocomplete="current-password"></label>'
        . '<button class="btn btn-primary hp-wide">Sign in</button></form>'
        . '<p class="hp-alt"><a href="' . e(iv_purl(['v' => 'forgot'])) . '">Forgot your password, or never set one?</a></p>');
}

function iv_portal_forgot(int $org, bool $posted): void
{
    if ($posted) {
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        $ip = fabo_client_ip();
        if (!fabo_throttle_blocked('iv-reset-ip', $ip, 10, 3600) && !fabo_throttle_blocked('iv-reset-acct', $org . '|' . $email, 3, 3600)) {
            fabo_throttle_hit('iv-reset-ip', $ip);
            fabo_throttle_hit('iv-reset-acct', $org . '|' . $email);
            $h = iv_hod_by_email($org, $email);
            if ($h && $h['status'] === 'active') {
                iv_invite_send($org, (int) $h['id'], $h['pass_hash'] !== null);
            }
        }
        iv_portal_gate($org, 'Check your email', '<h1>Check your email</h1><p>If ' . e($email) . ' belongs to a Head of Department here, a link to set your password is on its way. It works for ' . IV_INVITE_DAYS . ' days.</p><p class="hp-alt"><a href="' . e(iv_purl()) . '">Back to sign in</a></p>');
        return;
    }
    iv_portal_gate($org, 'New sign-in link', '<h1>Get a sign-in link</h1><p class="muted">Enter the email the owner registered for you.</p>'
        . '<form method="post" class="iv-form">' . fabo_csrf_field() . '<input type="hidden" name="a" value="forgot"><label class="field"><span>Email</span><input type="email" name="email" required autofocus></label>'
        . '<button class="btn btn-primary hp-wide">Send the link</button></form><p class="hp-alt"><a href="' . e(iv_purl()) . '">Back to sign in</a></p>');
}

function iv_portal_invite(int $org, string $token, bool $posted): void
{
    $h = iv_invite_hod($token);
    if (!$h || (int) $h['org_id'] !== $org) {
        iv_portal_gate($org, 'Link expired', '<h1>This link has expired</h1><p>Sign-in links work for ' . IV_INVITE_DAYS . ' days, once. Ask for a new one.</p><p class="hp-alt"><a href="' . e(iv_purl(['v' => 'forgot'])) . '">Get a new link</a></p>');
        return;
    }
    $err = '';
    if ($posted) {
        $p1 = (string) ($_POST['password'] ?? '');
        if ($p1 !== (string) ($_POST['password2'] ?? '')) {
            $err = 'The two passwords are not the same.';
        } else {
            try {
                iv_password_set($org, (int) $h['id'], $p1);
                iv_portal_login($org, iv_hod($org, (int) $h['id']));
                iv_pflash('ok', 'Welcome, ' . $h['name'] . '. Your dashboard is ready.');
                iv_pgo();
            } catch (InvalidArgumentException $e) {
                $err = $e->getMessage();
            }
        }
    }
    $d = iv_department($org, (int) $h['department_id']);
    iv_portal_gate($org, 'Set your password', '<h1>Welcome, ' . e($h['name']) . '</h1><p class="muted">Head of Department · ' . e(($d['name'] ?? '') . ', ' . ($d['college_name'] ?? '')) . '</p>'
        . ($err !== '' ? '<div class="iv-flash err" role="alert">' . e($err) . '</div>' : '')
        . '<form method="post" class="iv-form">' . fabo_csrf_field() . '<input type="hidden" name="a" value="set-password">'
        . '<label class="field"><span>Email (you sign in with)</span><input type="email" value="' . e($h['email']) . '" readonly autocomplete="username"></label>'
        . '<label class="field"><span>Choose a password</span><input type="password" name="password" required minlength="10" autocomplete="new-password"><small>At least 10 characters. Three or four unrelated words work well.</small></label>'
        . '<label class="field"><span>Once more</span><input type="password" name="password2" required minlength="10" autocomplete="new-password"></label>'
        . '<button class="btn btn-primary hp-wide">Set password and open my dashboard</button></form>');
}

/* =================================================================== POST */

function iv_portal_post(int $org, array $me, array $dept, ?array $round, string $a, string $actor): void
{
    $deptId = (int) $dept['id'];
    $rq = $round ? ['round' => (int) $round['id']] : [];
    $back = ['v' => (string) ($_GET['v'] ?? '')] + array_filter(['id' => (int) ($_GET['id'] ?? 0) ?: null]) + $rq;
    $back = array_filter($back, static fn($x) => $x !== '' && $x !== null);
    try {
        if ($a === 'password') {
            if ((string) ($_POST['password'] ?? '') !== (string) ($_POST['password2'] ?? '')) {
                throw new InvalidArgumentException('The two new passwords are not the same.');
            }
            if (!(int) $me['must_change'] && !password_verify((string) ($_POST['current'] ?? ''), (string) $me['pass_hash'])) {
                throw new InvalidArgumentException('Your current password is not right.');
            }
            iv_password_set($org, (int) $me['id'], (string) $_POST['password']);
            iv_portal_login($org, iv_hod($org, (int) $me['id']));
            iv_pflash('ok', 'Password changed.');
            iv_pgo();
        }
        if (!$round) {
            throw new InvalidArgumentException('There is no round yet. The owner opens one.');
        }
        // Everything below is about one idea of this department, or this department's form.
        $idea = null;
        if (in_array($a, ['run-portal', 'run-own', 'decide'], true)) {
            $idea = iv_idea($org, (int) ($_POST['id'] ?? 0));
            if (!$idea || (int) $idea['department_id'] !== $deptId) {
                throw new InvalidArgumentException('That idea is not in your department.');
            }
            if ((int) $idea['round_id'] !== (int) $round['id']) {
                $round = iv_round($org, (int) $idea['round_id']);
            }
        }
        switch ($a) {
            case 'form-save':
                $form = iv_form_for($org, $round, $deptId);
                $rows = [];
                foreach ((array) ($_POST['q'] ?? []) as $r) {
                    if (is_array($r)) {
                        $rows[] = $r;
                    }
                }
                iv_form_save($org, (int) $form['id'], (string) ($_POST['title'] ?? ''), (string) ($_POST['intro'] ?? ''), $rows, !empty($_POST['is_open']));
                iv_pflash('ok', 'Form saved. Students who open the link now see these questions.');
                iv_pgo(['v' => 'form'] + $rq);

            case 'run-portal':
                @set_time_limit(300);
                $msg = iv_run_portal($org, $idea, $round, $me, $actor);
                iv_pflash('ok', $msg);
                iv_pgo(['v' => 'idea', 'id' => (int) $idea['id']] + $rq);

            case 'run-own':
                $msg = iv_run_own($org, $idea, $round, $me, (string) ($_POST['tool'] ?? ''), (string) ($_POST['reply'] ?? ''), $actor);
                iv_pflash('ok', $msg);
                iv_pgo(['v' => 'idea', 'id' => (int) $idea['id']] + $rq);

            case 'decide':
                iv_idea_decide($org, (int) $idea['id'], $deptId, (string) ($_POST['status'] ?? ''), (string) ($_POST['note'] ?? ''), $actor);
                iv_pflash('ok', 'Saved: ' . (iv_statuses()[$_POST['status']] ?? '') . '.');
                $next = fabo_one('SELECT id FROM iv_ideas WHERE org_id = ? AND round_id = ? AND department_id = ? AND status = "submitted" AND id <> ? ORDER BY submitted_at LIMIT 1',
                                 'iiii', [$org, (int) $idea['round_id'], $deptId, (int) $idea['id']]);
                iv_pgo(!empty($_POST['next']) && $next ? ['v' => 'idea', 'id' => (int) $next['id']] + $rq : ['v' => 'idea', 'id' => (int) $idea['id']] + $rq);

            case 'decide-many':
                // Students page and panel: 'validated:12' for one idea, or 'validated' with the ticked ids[].
                $do = (string) ($_POST['do'] ?? '');
                $ids = [];
                if (str_contains($do, ':')) {
                    [$do, $one] = explode(':', $do, 2);
                    $ids = [(int) $one];
                } else {
                    $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
                }
                $to = iv_portal_back_q((string) ($_POST['back_q'] ?? ''), $rq);
                if (!in_array($do, ['validated', 'rejected', 'submitted'], true) || !$ids) {
                    iv_pflash('err', $ids ? 'Choose Validate or Not taken.' : 'Tick the ideas first.');
                    iv_pgo($to);
                }
                [$n, $problems] = iv_ideas_decide_many($org, $ids, $deptId, $do, $actor);
                $word = iv_statuses()[$do];
                if ($problems) {
                    iv_pflash($n ? 'ok' : 'err', ($n ? $n . ' marked ' . $word . '. ' : '') . implode(' ', array_slice($problems, 0, 3)));
                } else {
                    iv_pflash('ok', $n ? ($n === 1 ? 'Marked ' . $word . '.' : $n . ' ideas marked ' . $word . '.') : 'Nothing to change: already ' . $word . '.');
                }
                iv_pgo($to);

            case 'ranks':
                $ranks = [];
                foreach ((array) ($_POST['rank'] ?? []) as $id => $r) {
                    $ranks[(int) $id] = (int) $r;
                }
                iv_ranks_set($org, $round, $deptId, $ranks, $actor);
                if (!empty($_POST['forward'])) {
                    $n = iv_forward($org, $round, $deptId, $actor, $me, (string) ($_POST['reason'] ?? ''));
                    iv_audit($org, $actor, 'forward', $dept['name'] . ', ' . $dept['college_name'] . ': ' . $n . ' ideas');
                    iv_pflash('ok', $n . ' idea' . ($n === 1 ? '' : 's') . ' sent forward to the owner. Thank you!');
                } else {
                    iv_pflash('ok', 'Ranking saved. Send them forward when you are ready.');
                }
                iv_pgo($rq);
        }
    } catch (InvalidArgumentException $e) {
        iv_pflash('err', $e->getMessage());
    } catch (Throwable $e) {
        error_log('FABO iv portal ' . $a . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        iv_pflash('err', 'That could not be saved. Please try again.');
    }
    iv_pgo($back);
}

/* ================================================================== views */

function iv_portal_no_round(): void
{
    echo '<section class="iv-card iv-start"><h1>No idea round yet</h1><p>The owner has not opened a round. When they do, your idea form and the ideas your students send appear here.</p></section>';
}

function iv_portal_view_home(int $org, array $me, array $dept, ?array $round): void
{
    $first = explode(' ', preg_replace('/^(dr|prof|mr|mrs|ms|shri|smt)\.?\s+/i', '', trim($me['name'])))[0] ?? $me['name'];
    echo '<section class="hp-hello"><h1>Hello, ' . e($first) . '</h1><p>' . e($dept['name']) . ' · ' . e($dept['college_name']) . '</p></section>';
    if (!$round) {
        iv_portal_no_round();
        return;
    }
    $deptId = (int) $dept['id'];
    $form = iv_form_for($org, $round, $deptId);
    $ideas = iv_ideas($org, ['round' => (int) $round['id'], 'dept' => $deptId, 'sort' => 'score']);
    $sent = iv_dept_forwarded($org, (int) $round['id'], $deptId);
    $closes = iv_closes_for($round, $deptId);
    $now = iv_now();
    $open = $round['status'] === 'live' && $now >= $round['opens_at'] && $now < $closes;
    $limit = iv_ai_limit($round, $me);
    $count = static fn($s) => count(array_filter($ideas, static fn($i) => $i['status'] === $s));
    $checked = count(array_filter($ideas, static fn($i) => $i['ai_score'] !== null));
    $gem = $round['gem_id'] ? iv_gem($org, (int) $round['gem_id']) : null;

    echo '<section class="iv-kpis">' . iv_portal_kpi('Ideas received', count($ideas)) . iv_portal_kpi('To validate', $count('submitted'), $count('submitted') ? 'warn' : '')
       . iv_portal_kpi('AI-checked', $checked) . iv_portal_kpi('Validated', $count('validated'), 'good') . iv_portal_kpi('Sent forward', $sent ? count(array_filter($ideas, static fn($i) => $i['forwarded_at'])) : 0, 'accent') . '</section>';

    echo '<div class="iv-grid2">';
    echo '<section class="iv-card hp-round-card"><h2>' . e($round['name']) . '</h2><ol class="hp-steps">'
       . '<li class="' . ($open ? 'now' : ($now >= $closes ? 'done' : '')) . '"><b>Students submit</b><span>' . e(iv_when($round['opens_at'])) . ' → ' . e(iv_when($closes)) . '</span></li>'
       . '<li class="' . (!$open && !$sent && $now >= $closes ? 'now' : ($sent ? 'done' : '')) . '"><b>You validate with AI</b><span>' . $limit . ' AI check' . ($limit === 1 ? '' : 's') . ' per idea · ' . e($gem['name'] ?? 'Gem not assigned yet') . '</span></li>'
       . '<li class="' . ($sent ? 'done' : '') . '"><b>Send your top ' . (int) $round['top_n'] . '</b><span>' . ($round['review_by'] ? 'by ' . e(iv_when($round['review_by'])) : 'when you are ready') . '</span></li></ol></section>';
    $url = iv_form_url($org, $form['token']);
    $msg = 'Share your startup idea for ' . $round['name'] . ' (' . $dept['name'] . '). Submit before ' . iv_when($closes) . ': ' . $url;
    echo '<section class="iv-card"><h2>Share the idea form</h2><p class="muted">Send this link to your students. Each student sends one idea; they sign it with their PIN / roll number.</p>'
       . iv_copy($url) . '<div class="iv-actions sm"><a class="btn btn-sm" href="https://wa.me/?text=' . e(rawurlencode($msg)) . '" target="_blank" rel="noopener">Share on WhatsApp</a>'
       . '<a class="btn btn-sm" href="mailto:?subject=' . e(rawurlencode('Submit your idea: ' . $round['name'])) . '&body=' . e(rawurlencode($msg)) . '">Email</a>'
       . '<a class="btn btn-sm" href="' . e(iv_purl(['v' => 'form', 'round' => (int) $round['id']])) . '">Edit questions</a></div>'
       . (!(int) $form['is_open'] ? '<p class="iv-warn">The form is paused: students cannot send ideas.</p>' : ($open ? '' : '<p class="iv-warn">Submission is ' . ($now < $round['opens_at'] ? 'not open yet' : 'closed') . '.</p>')) . '</section>';
    echo '</div>';

    // Ideas, best AI score first, with the top picks.
    $validated = array_values(array_filter($ideas, static fn($i) => $i['status'] === 'validated'));
    echo '<section class="iv-card"><div class="iv-card-h"><h2>Ideas (' . count($ideas) . ')</h2><span class="muted sm">Open one to check it with AI and decide.</span></div>';
    if (!$ideas) {
        echo '<p class="iv-empty">No ideas yet. Share the form with your students.</p>';
    } else {
        echo '<div class="iv-tablewrap"><table class="iv-table"><thead><tr><th>Idea</th><th>Student</th><th>Decision</th><th class="n">AI score</th><th class="n">AI checks</th></tr></thead><tbody>';
        foreach ($ideas as $i) {
            echo '<tr><td><a href="' . e(iv_purl(['v' => 'idea', 'id' => (int) $i['id'], 'round' => (int) $round['id']])) . '"><strong>' . e($i['title']) . '</strong></a><div class="muted sm">' . e(iv_date($i['submitted_at'])) . '</div></td>'
               . '<td>' . e($i['student_name']) . '<div class="muted sm">' . e($i['roll_no']) . '</div></td><td>' . iv_pill($i['status']) . ($i['forwarded_at'] ? ' <span class="iv-pill g-forwarded">Sent #' . (int) $i['hod_rank'] . '</span>' : '') . '</td>'
               . '<td class="n">' . iv_portal_score_chip($i) . '</td><td class="n">' . (int) $i['runs'] . ' / ' . $limit . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</section>';

    echo '<section class="iv-card hp-picks" id="picks"><h2>Your top ' . (int) $round['top_n'] . '</h2>';
    if ($sent) {
        echo '<p class="iv-flash ok">Sent to the owner. These are now in their pipeline, where they form the teams.</p><ol class="hp-picked">';
        foreach (array_filter($ideas, static fn($i) => $i['forwarded_at']) as $i) {
            echo '<li><strong>' . e($i['title']) . '</strong> <span class="muted">— ' . e($i['student_name']) . ' · ' . e(iv_score($i['ai_score'], $i['ai_max'])) . '</span></li>';
        }
        echo '</ol>';
    } elseif (!$validated) {
        echo '<p class="muted">Validate ideas first; then rank your best ' . (int) $round['top_n'] . ' here and send them forward.</p>';
    } else {
        $needAi = $round['gem_id'] && $limit > 0;
        $against = iv_picks_against_scores($ideas);
        echo '<p class="muted">Give your best validated ideas a rank, 1 being the strongest. Up to ' . (int) $round['top_n'] . '.' . ($needAi ? ' Each pick needs an AI check.' : '') . '</p>'
           . '<form method="post" class="iv-form" data-picks="' . (int) $round['top_n'] . '">' . fabo_csrf_field()
           . '<input type="hidden" name="a" value="ranks"><input type="hidden" name="round" value="' . (int) $round['id'] . '">'
           . '<div class="hp-picks-bar"><button type="button" class="btn btn-sm" data-rankbyscore>Rank by AI score</button><button type="button" class="btn btn-sm hp-ghost" data-rankclear>Clear ranks</button></div>'
           . '<table class="iv-table"><thead><tr><th>Rank</th><th>Idea</th><th class="n">AI score</th></tr></thead><tbody>';
        foreach ($validated as $i) {
            $pct = $i['ai_score'] !== null && (float) $i['ai_max'] > 0 ? round((float) $i['ai_score'] / (float) $i['ai_max'], 4) : '';
            echo '<tr data-pick data-pct="' . e((string) $pct) . '" data-title="' . e($i['title']) . '"><td><select name="rank[' . (int) $i['id'] . ']" aria-label="Rank for ' . e($i['title']) . '"><option value="0">—</option>';
            for ($n = 1; $n <= (int) $round['top_n']; $n++) {
                echo '<option value="' . $n . '"' . ((int) $i['hod_rank'] === $n ? ' selected' : '') . '>#' . $n . '</option>';
            }
            echo '</select></td><td><a href="' . e(iv_purl(['v' => 'idea', 'id' => (int) $i['id'], 'round' => (int) $round['id']])) . '"><strong>' . e($i['title']) . '</strong></a><div class="muted sm">' . e($i['student_name']) . '</div></td>'
               . '<td class="n">' . ($i['ai_score'] === null && $needAi ? '<span class="hp-score none">Needs an AI check</span>' : iv_portal_score_chip($i)) . '</td></tr>';
        }
        echo '</tbody></table>'
           . '<label class="field hp-reason" data-reason' . ($against ? '' : ' hidden') . '><span>Why this order? <em data-reasonwhy>' . e($against[0] ?? '') . '</em></span>'
           . '<textarea name="reason" rows="2" maxlength="2000" placeholder="Your order differs from the AI scores. Say why in a sentence or two. The owner reads this."></textarea></label>'
           . '<div class="iv-actions"><button class="btn">Save ranking</button>'
           . '<button class="btn btn-primary" name="forward" value="1" data-confirm="Send the ranked ideas to the owner? After this your decisions are final.">Send top ideas forward</button></div></form>';
    }
    echo '</section>';
}

function iv_portal_kpi(string $label, int $n, string $tone = ''): string
{
    return '<div class="iv-kpi ' . e($tone) . '"><span class="k">' . e($label) . '</span><strong>' . fabo_thousands($n) . '</strong></div>';
}

function iv_portal_view_form(int $org, array $me, array $dept, ?array $round): void
{
    if (!$round) {
        iv_portal_no_round();
        return;
    }
    $form = iv_form_for($org, $round, (int) $dept['id']);
    $qs = iv_form_questions($form);
    $url = iv_form_url($org, $form['token']);
    echo '<section class="iv-card"><div class="iv-card-h"><h2>Idea form · ' . e($round['name']) . '</h2><a class="btn btn-sm" href="' . e($url) . '" target="_blank" rel="noopener">Preview ↗</a></div>'
       . '<p class="muted">Students also give their name, PIN / roll number, email, phone and an idea title. The questions are <span class="iv-pill g-team">Set by the owner</span>: they are what the AI scores, so they cannot be added to or changed here. You can change the title and the note to students, or pause the form.</p>'
       . iv_copy($url) . '</section>';
    echo '<form method="post" class="iv-form iv-card" id="formBuilder">' . fabo_csrf_field() . '<input type="hidden" name="a" value="form-save"><input type="hidden" name="round" value="' . (int) $round['id'] . '">'
       . '<label class="field"><span>Title</span><input name="title" maxlength="190" value="' . e($form['title']) . '"></label>'
       . '<label class="field"><span>Note to students</span><textarea name="intro" rows="3" maxlength="3000">' . e((string) $form['intro']) . '</textarea></label>'
       . '<h3>Questions</h3><ol class="iv-qs" data-qs>';
    foreach ($qs as $n => $q) {
        echo iv_portal_q_row((string) $n, $q);
    }
    echo '</ol>'
       . '<label class="iv-check"><input type="checkbox" name="is_open" value="1"' . ((int) $form['is_open'] ? ' checked' : '') . '> Accepting ideas (untick to pause the form)</label>'
       . '<div class="iv-actions"><button class="btn btn-primary">Save form</button></div></form>';
}

function iv_portal_q_row(string $n, array $q): string
{
    // Every question on a HoD's form is the owner's: shown, never sent back, so nothing can be changed here.
    return '<li class="iv-q gem locked"><div class="iv-qlock"><span class="iv-pill g-team">Set by the owner</span> <span class="muted sm">' . e(iv_question_types()[$q['type'] ?? 'long'] ?? 'Paragraph')
         . (!empty($q['required']) ? ' · required' : ' · optional') . '</span></div><strong>' . e($q['label']) . '</strong>'
         . (trim((string) ($q['help'] ?? '')) !== '' ? '<p class="muted sm">' . nl2br(e((string) $q['help'])) . '</p>' : '') . '</li>';
}

function iv_portal_view_idea(int $org, array $me, array $dept, ?array $round): void
{
    $i = iv_idea($org, (int) ($_GET['id'] ?? 0));
    if (!$i || (int) $i['department_id'] !== (int) $dept['id']) {
        echo '<section class="iv-card"><p>That idea is not in your department. <a href="' . e(iv_purl()) . '">Back to the dashboard</a>.</p></section>';
        return;
    }
    $round = iv_round($org, (int) $i['round_id']);
    $sent = iv_dept_forwarded($org, (int) $round['id'], (int) $dept['id']);
    $limit = iv_ai_limit($round, $me);
    $used = iv_runs_used((int) $i['id']);
    $left = max(0, $limit - $used);
    $gem = $round['gem_id'] ? iv_gem($org, (int) $round['gem_id']) : null;
    echo '<p class="iv-crumb"><a href="' . e(iv_purl(['round' => (int) $round['id']])) . '">← Dashboard</a></p>'
       . '<h1 class="iv-title">' . e($i['title']) . '</h1><p class="iv-sub">' . iv_pill($i['status']) . ' <span class="muted">· ' . e($i['student_name']) . ($i['roll_no'] ? ' (' . e($i['roll_no']) . ')' : '') . ' · ' . e(iv_when($i['submitted_at'])) . '</span></p>';
    echo '<div class="iv-grid2 wide-left"><div>';
    iv_idea_answers_html($i);
    iv_runs_html($org, $i);
    echo '</div><div>';

    // AI check: one clear button in the portal; the HoD's own tool as a short, checked path below it.
    $num = static fn($x) => rtrim(rtrim(number_format((float) $x, 1), '0'), '.');
    $portalOk = iv_portal_ai_ready();
    $ownOk = (int) ($round['own_ai'] ?? 1) === 1;
    echo '<section class="iv-card hp-ai"><div class="iv-card-h"><h2>Check with AI</h2><span class="iv-left' . ($left ? '' : ' none') . '">' . $left . ' of ' . $limit . ' left</span></div>';
    if (!$gem || !$gem['criteria']) {
        echo '<p class="muted">The owner has not assigned a Gem to this round yet.</p>';
    } elseif ($left <= 0) {
        echo '<p class="muted">This idea has used its AI checks. ' . ($limit ? 'The owner can raise your limit.' : 'AI checks are off for you.') . '</p>';
    } elseif (!$portalOk && !$ownOk) {
        echo '<p class="muted">AI is not available for this round yet. Please contact the owner.</p>';
    } else {
        echo '<p class="hp-ai-gem">Scored by <strong>' . e($gem['name']) . '</strong> · ' . count($gem['criteria']) . ' criteria · out of ' . e($num($gem['max'])) . '</p>';
        if ($portalOk) {
            echo '<form method="post" class="hp-ai-go" data-busy="Checking… up to a minute">' . fabo_csrf_field() . '<input type="hidden" name="a" value="run-portal"><input type="hidden" name="id" value="' . (int) $i['id'] . '">'
               . '<button class="btn btn-primary"><span aria-hidden="true">✦</span> Check this idea now</button><small>Takes up to a minute. If the AI is busy, the check is not counted.</small></form>';
        }
        if ($ownOk) {
            $code = iv_check_code($org, $i);
            $crit = array_map(static fn($c) => ['name' => $c['name'], 'max' => (float) $c['weight']], $gem['criteria']);
            echo ($portalOk ? '<details class="hp-own-d"><summary>Or use your own AI tool</summary>' : '<div class="hp-own-d open">')
               . '<div class="hp-own" data-own data-code="' . e($code) . '" data-crit="' . e(json_encode($crit, JSON_UNESCAPED_UNICODE)) . '">'
               . '<div class="hp-step"><b>1</b><div><p>Copy the prompt and open your AI tool. Paste the prompt there.</p>'
               . '<div class="hp-tools"><button type="button" class="btn btn-sm" data-copy-from="ivPrompt" data-go="https://gemini.google.com/app" data-tool="Gemini">Copy &amp; open Gemini ↗</button>'
               . '<button type="button" class="btn btn-sm" data-copy-from="ivPrompt" data-go="https://chatgpt.com/" data-tool="ChatGPT">Copy &amp; open ChatGPT ↗</button>'
               . '<button type="button" class="btn btn-sm hp-ghost" data-copy-from="ivPrompt" data-tool="">Copy only</button></div>'
               . '<details class="hp-peek"><summary>See the prompt</summary><textarea readonly rows="8" class="iv-prompt" id="ivPrompt">' . e(iv_prompt($gem, $i, $code)) . '</textarea></details></div></div>'
               . '<form method="post" class="hp-step" data-ownform>' . fabo_csrf_field() . '<input type="hidden" name="a" value="run-own"><input type="hidden" name="id" value="' . (int) $i['id'] . '"><input type="hidden" name="tool" value="" data-toolname>'
               . '<b>2</b><div><label class="hp-paste"><span>Paste the AI\'s whole reply</span><textarea name="reply" rows="4" required placeholder="Paste here. The marks are read for you." data-reply></textarea></label>'
               . '<div class="hp-preview" data-preview aria-live="polite"></div>'
               . '<div class="hp-own-foot"><button class="btn btn-primary" data-ownsave>Save this check</button><small>The reply is kept for the owner to see. Marks cannot be typed in.</small></div></div></form>'
               . '</div>' . ($portalOk ? '</details>' : '</div>');
        }
    }
    echo '</section>';

    // Decision
    echo '<section class="iv-card"><h2>Your decision</h2>';
    if ($sent) {
        echo '<p>' . iv_pill($i['status']) . '</p>' . ($i['hod_note'] ? '<p class="iv-note">' . nl2br(e($i['hod_note'])) . '</p>' : '') . '<p class="muted sm">Your top ideas have been sent, so decisions are final.</p>';
    } else {
        if (!$used && $gem && $limit > 0) {
            echo '<p class="hp-hint">Not checked with AI yet. You can still decide, but a top pick needs an AI score before you send it.</p>';
        }
        echo '<form method="post" class="iv-form">' . fabo_csrf_field() . '<input type="hidden" name="a" value="decide"><input type="hidden" name="id" value="' . (int) $i['id'] . '"><div class="hp-decide">';
        foreach (['validated' => 'Validated', 'rejected' => 'Not taken', 'submitted' => 'Undecided'] as $k => $l) {
            echo '<label class="hp-opt s-' . $k . '"><input type="radio" name="status" value="' . $k . '"' . ($i['status'] === $k ? ' checked' : '') . ' required><span>' . e($l) . '</span></label>';
        }
        echo '</div><label class="field"><span>Note (the owner sees it)</span><textarea name="note" rows="3" maxlength="3000">' . e((string) $i['hod_note']) . '</textarea></label>'
           . '<div class="iv-actions"><button class="btn btn-primary">Save</button><button class="btn" name="next" value="1">Save and open the next</button></div></form>';
    }
    echo '</section></div></div>';
}

/** Where a decision made on the Students page goes back to: the same filter, search and open student. */
function iv_portal_back_q(string $qs, array $rq): array
{
    parse_str(ltrim($qs, '?'), $q);
    $out = ['v' => in_array($q['v'] ?? '', ['students', 'student', 'idea'], true) ? $q['v'] : 'students'];
    foreach (['q' => 120, 'f' => 12] as $k => $max) {
        if (isset($q[$k]) && is_string($q[$k]) && $q[$k] !== '') {
            $out[$k] = mb_substr($q[$k], 0, $max);
        }
    }
    foreach (['open', 'id'] as $k) {
        if (!empty($q[$k]) && (int) $q[$k] > 0) {
            $out[$k] = (int) $q[$k];
        }
    }
    return $out + $rq;
}

/** The Students page's filters: key => label. */
function iv_portal_student_filters(): array
{
    return ['all' => 'All', 'sent' => 'Sent an idea', 'todo' => 'To validate', 'validated' => 'Validated', 'rejected' => 'Not taken', 'none' => 'No idea yet'];
}

/** Does a student row (with its idea, if any) pass the filter? */
function iv_portal_student_in(string $f, ?array $idea): bool
{
    return match ($f) {
        'sent' => $idea !== null,
        'todo' => $idea !== null && $idea['status'] === 'submitted',
        'validated' => $idea !== null && $idea['status'] === 'validated',
        'rejected' => $idea !== null && $idea['status'] === 'rejected',
        'none' => $idea === null,
        default => true,
    };
}

/** The AI score as a small chip with its share of the maximum. */
function iv_portal_score_chip(array $i): string
{
    if ($i['ai_score'] === null) {
        return '<span class="hp-score none" title="Not checked with AI yet">No AI check</span>';
    }
    $p = (float) $i['ai_max'] > 0 ? (float) $i['ai_score'] / (float) $i['ai_max'] * 100 : 0;
    $tone = $p >= 70 ? 'hi' : ($p >= 40 ? 'mid' : 'lo');
    return '<span class="hp-score ' . $tone . '" title="AI score' . ((int) ($i['runs'] ?? 0) > 1 ? ', average of ' . (int) $i['runs'] . ' checks' : '') . '">' . e(iv_score($i['ai_score'], $i['ai_max'])) . '</span>';
}

function iv_portal_view_students(int $org, array $me, array $dept, ?array $round): void
{
    $q = trim((string) ($_GET['q'] ?? ''));
    $f = (string) ($_GET['f'] ?? 'all');
    $filters = iv_portal_student_filters();
    if (!isset($filters[$f])) {
        $f = 'all';
    }
    $rq = $round ? ['round' => (int) $round['id']] : [];
    [$rows, $total] = iv_students($org, ['dept' => (int) $dept['id'], 'q' => $q], 3000);
    $ideas = [];
    $sent = false;
    if ($round) {
        foreach (iv_ideas($org, ['round' => (int) $round['id'], 'dept' => (int) $dept['id']], 5000) as $i) {
            $ideas[(int) $i['student_id']] = $i;
        }
        $sent = iv_dept_forwarded($org, (int) $round['id'], (int) $dept['id']);
    }
    $count = array_fill_keys(array_keys($filters), 0);
    $shown = [];
    foreach ($rows as $s) {
        $i = $ideas[(int) $s['id']] ?? null;
        foreach ($filters as $k => $l) {
            if (iv_portal_student_in($k, $i)) {
                $count[$k]++;
            }
        }
        if (iv_portal_student_in($f, $i)) {
            $shown[] = [$s, $i];
        }
    }
    // Students with an idea to decide first, then by name: the work is at the top.
    usort($shown, static function ($a, $b) {
        $w = static fn($i) => $i === null ? 3 : ['submitted' => 0, 'validated' => 1, 'rejected' => 2][$i['status']];
        return [$w($a[1]), $a[0]['name']] <=> [$w($b[1]), $b[0]['name']];
    });
    $cap = 400;
    $more = count($shown) - $cap;
    $shown = array_slice($shown, 0, $cap);
    $here = ['v' => 'students'] + array_filter(['q' => $q, 'f' => $f !== 'all' ? $f : '']) + $rq;
    $backQ = http_build_query($here);
    $canDecide = $round && !$sent;
    $lst = array_filter(['lq' => $q, 'lf' => $f !== 'all' ? $f : '']);   // the panel's buttons come back to this list

    echo '<div class="hp-ph"><div><h1>Students</h1><p class="muted">' . e($dept['name']) . ' · ' . fabo_thousands($total) . ' on the list'
       . ($round ? ' · ' . $count['sent'] . ' sent an idea for ' . e($round['name']) : '') . '</p></div>'
       . '<form method="get" class="hp-search" role="search"><input type="hidden" name="o" value="' . e(iv_portal_key($org)) . '"><input type="hidden" name="v" value="students">'
       . ($f !== 'all' ? '<input type="hidden" name="f" value="' . e($f) . '">' : '') . ($round ? '<input type="hidden" name="round" value="' . (int) $round['id'] . '">' : '')
       . '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>'
       . '<input type="search" name="q" value="' . e($q) . '" placeholder="Search name, PIN, email or phone" aria-label="Search students"></form></div>';

    echo '<nav class="hp-seg" aria-label="Show">';
    foreach ($filters as $k => $l) {
        if (!$round && $k !== 'all') {
            continue;
        }
        $href = iv_purl(['v' => 'students'] + array_filter(['q' => $q, 'f' => $k !== 'all' ? $k : '']) + $rq);
        echo '<a href="' . e($href) . '" class="' . ($f === $k ? 'on' : '') . ($k === 'todo' && $count['todo'] ? ' attn' : '') . '"' . ($f === $k ? ' aria-current="true"' : '') . '>'
           . e($l) . ' <b>' . fabo_thousands($count[$k]) . '</b></a>';
    }
    echo '</nav>';

    if ($round && $sent) {
        echo '<p class="iv-flash info">Your top ideas have been sent to the owner, so decisions are final for ' . e($round['name']) . '.</p>';
    } elseif ($round && $count['sent']) {
        echo '<p class="hp-hint">Validate the ideas worth taking forward; tick several to decide them together. Your top ' . (int) $round['top_n'] . ' need an AI check before you send them.</p>';
    }

    $open = (int) ($_GET['open'] ?? 0);
    echo '<section class="iv-card hp-stu">';
    if ($canDecide) {
        echo '<form method="post" id="ivBulk" data-bulk>' . fabo_csrf_field() . '<input type="hidden" name="a" value="decide-many"><input type="hidden" name="back_q" value="' . e($backQ) . '">'
           . ($round ? '<input type="hidden" name="round" value="' . (int) $round['id'] . '">' : '') . '</form>';
    }
    echo '<div class="iv-tablewrap"><table class="iv-table iv-click hp-stu-t" data-panels' . ($open ? ' data-open-id="' . $open . '" data-open-panel="' . e(iv_purl(['v' => 'student', 'id' => $open, 'embed' => 1] + $lst + $rq)) . '"' : '') . '><thead><tr>'
       . ($canDecide ? '<th class="ck"><input type="checkbox" data-checkall aria-label="Tick every idea shown"></th>' : '')
       . '<th>Student</th><th class="hp-c-contact">Contact</th><th>Idea</th><th>Decision</th><th class="act"><span class="sr-only">Actions</span></th></tr></thead><tbody>';
    if (!$shown) {
        $why = $q !== '' ? 'No students match "' . $q . '".' : ($f === 'all' ? 'No students on your list yet. Students who send an idea through your form are added here.' : 'Nobody here yet.');
        echo '<tr><td colspan="' . ($canDecide ? 6 : 5) . '" class="iv-empty">' . e($why) . '</td></tr>';
    }
    foreach ($shown as [$s, $i]) {
        $sid = (int) $s['id'];
        $href = iv_purl(['v' => 'student', 'id' => $sid] + $rq);
        $panel = iv_purl(['v' => 'student', 'id' => $sid, 'embed' => 1] + $lst + $rq);
        $pin = (string) $s['roll_no'];
        echo '<tr data-href="' . e($href) . '" data-panel="' . e($panel) . '" data-id="' . $sid . '"' . ($i && $i['status'] === 'submitted' ? ' class="todo"' : '') . '>';
        if ($canDecide) {
            echo '<td class="ck">' . ($i ? '<input type="checkbox" name="ids[]" value="' . (int) $i['id'] . '" form="ivBulk" data-tick aria-label="Tick ' . e($i['title']) . '">' : '') . '</td>';
        }
        echo '<td class="hp-c-who"><a href="' . e($href) . '" data-panel-link class="hp-who"><span class="hp-av-s" aria-hidden="true">' . e(mb_strtoupper(mb_substr((string) $s['name'], 0, 1))) . '</span><span><strong>' . e($s['name']) . '</strong>'
           . '<small class="mono">' . e($pin !== '' ? $pin : 'No PIN') . '</small></span></a></td>'
           . '<td class="hp-c-contact"><span class="hp-line">' . e($s['email'] ?: '—') . '</span><small class="muted">' . e($s['phone'] ?: '') . '</small></td>';
        if ($i) {
            echo '<td class="hp-c-idea"><a href="' . e(iv_purl(['v' => 'idea', 'id' => (int) $i['id']] + $rq)) . '">' . e($i['title']) . '</a>' . iv_portal_score_chip($i) . '</td>'
               . '<td class="hp-c-dec">' . iv_pill($i['status']) . ($i['forwarded_at'] ? ' <span class="iv-pill g-forwarded">Sent #' . (int) $i['hod_rank'] . '</span>' : '') . '</td><td class="act">';
            if ($canDecide && $i['status'] !== 'validated') {
                echo '<button class="btn btn-sm hp-val" name="do" value="validated:' . (int) $i['id'] . '" form="ivBulk">Validate</button>';
            } elseif ($i['status'] === 'validated') {
                echo '<span class="hp-done" aria-hidden="true">✓</span>';
            }
            echo '</td>';
        } else {
            echo '<td class="hp-c-idea"><span class="muted">No idea yet</span></td><td class="hp-c-dec"></td><td class="act"></td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
    if ($more > 0) {
        echo '<p class="muted sm hp-more">' . fabo_thousands($more) . ' more not shown. Search or pick a filter to narrow the list.</p>';
    }
    if ($canDecide) {
        echo '<div class="hp-bulk" data-bulkbar hidden role="region" aria-label="Ticked ideas"><span><b data-tickcount>0</b> ticked</span>'
           . '<button class="btn btn-sm hp-val" name="do" value="validated" form="ivBulk">Validate</button>'
           . '<button class="btn btn-sm" name="do" value="rejected" form="ivBulk">Not taken</button>'
           . '<button class="btn btn-sm" name="do" value="submitted" form="ivBulk">Undecided</button>'
           . '<button type="button" class="btn btn-sm hp-ghost" data-untick>Clear</button></div>';
    }
    echo '</section>';
}

/**
 * One student in the panel over the Students list: who they are in one
 * line, then this round's idea with its score and the decision buttons, then
 * contact details. Read-only for the student's record; the owner keeps it.
 */
function iv_portal_student_panel(int $org, array $me, array $dept, ?array $round, array $s): string
{
    $rq = $round ? ['round' => (int) $round['id']] : [];
    $pin = iv_roll_shown($s);
    $college = trim((string) ($s['college_name'] ?? ''));
    $district = (string) ($s['district'] !== '' ? $s['district'] : ($s['college_district'] ?? ''));
    $email = (string) $s['email'];
    $phone = (string) $s['phone'];
    $ic = static fn(string $d) => '<svg viewBox="0 0 24 24" aria-hidden="true">' . $d . '</svg>';

    $h = '<div class="iv-pn-view hp-pv" data-pn-title="' . e($s['name']) . '" data-pn-full="' . e(iv_purl(['v' => 'student', 'id' => (int) $s['id']] + $rq)) . '">'
       . '<header class="hp-pv-head"><span class="iv-av xl" aria-hidden="true">' . e(mb_strtoupper(mb_substr((string) $s['name'], 0, 1))) . '</span><div class="hp-pv-who"><h2>' . e($s['name']) . '</h2>'
       . '<p>' . ($pin !== '' ? '<span class="mono">' . e($pin) . '</span>' : '<span class="muted">No PIN</span>') . ($college !== '' ? ' · ' . e($college) : '') . ($district !== '' ? ' · ' . e($district) : '') . '</p></div>'
       . '<div class="hp-pv-act">'
       . ($email !== '' ? '<a class="iv-pn-ic" href="mailto:' . e($email) . '" title="Email ' . e($email) . '" aria-label="Email">' . $ic('<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>') . '</a>' : '')
       . ($phone !== '' ? '<a class="iv-pn-ic" href="tel:' . e($phone) . '" title="Call ' . e($phone) . '" aria-label="Call">' . $ic('<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/>') . '</a>' : '')
       . '</div></header>';

    $i = $round ? fabo_one('SELECT id FROM iv_ideas WHERE org_id = ? AND round_id = ? AND student_id = ?', 'iii', [$org, (int) $round['id'], (int) $s['id']]) : null;
    $i = $i ? iv_idea($org, (int) $i['id']) : null;
    if ($i && (int) $i['department_id'] === (int) $dept['id']) {
        $runs = iv_runs_used((int) $i['id']);
        $i['runs'] = $runs;
        $limit = iv_ai_limit($round, $me);
        $sent = iv_dept_forwarded($org, (int) $round['id'], (int) $dept['id']);
        $ideaUrl = iv_purl(['v' => 'idea', 'id' => (int) $i['id']] + $rq);
        $pct = $i['ai_score'] !== null && (float) $i['ai_max'] > 0 ? (float) $i['ai_score'] / (float) $i['ai_max'] * 100 : null;
        $h .= '<section class="iv-card hp-pi"><span class="iv-eyebrow">Idea · ' . e($round['name']) . '</span><h3 class="hp-pi-t"><a href="' . e($ideaUrl) . '">' . e($i['title']) . '</a></h3>'
            . '<p class="hp-pi-st">' . iv_pill($i['status']) . ($i['forwarded_at'] ? ' <span class="iv-pill g-forwarded">Sent #' . (int) $i['hod_rank'] . '</span>' : '') . ' <span class="muted sm">sent ' . e(iv_date($i['submitted_at'])) . '</span></p>'
            . '<div class="hp-pi-score">' . ($pct === null
                ? '<div><strong class="muted">Not checked with AI</strong><small>' . max(0, $limit - $runs) . ' of ' . $limit . ' AI checks left</small></div>'
                : '<div><strong>' . e(iv_score($i['ai_score'], $i['ai_max'])) . '</strong><small>AI score' . ($runs > 1 ? ', average of ' . $runs . ' checks' : '') . ' · ' . max(0, $limit - $runs) . ' of ' . $limit . ' left</small></div>'
                  . '<div class="iv-meter big"><i style="width:' . round($pct) . '%"></i></div>')
            . '</div>';
        $ans = array_values(array_filter(iv_idea_answers($i), static fn($a) => $a['value'] !== ''));
        if ($ans) {
            $h .= '<div class="hp-pi-ans">';
            foreach (array_slice($ans, 0, 2) as $a) {
                $h .= '<div><h4>' . e($a['label']) . '</h4><p>' . e(mb_strimwidth($a['value'], 0, 260, '…')) . '</p></div>';
            }
            $h .= '</div>';
        }
        $h .= '<div class="hp-pi-do">';
        if (!$sent) {
            $back = http_build_query(['v' => 'students', 'open' => (int) $s['id']] + array_filter(['q' => mb_substr((string) ($_GET['lq'] ?? ''), 0, 120), 'f' => isset(iv_portal_student_filters()[$_GET['lf'] ?? '']) && $_GET['lf'] !== 'all' ? (string) $_GET['lf'] : '']) + $rq);
            $btn = static fn(string $st, string $label, string $cls) => '<form method="post" action="' . e(iv_purl()) . '">' . fabo_csrf_field() . '<input type="hidden" name="a" value="decide-many">'
                . '<input type="hidden" name="back_q" value="' . e($back) . '">' . ($round ? '<input type="hidden" name="round" value="' . (int) $round['id'] . '">' : '')
                . '<button class="btn ' . $cls . '" name="do" value="' . $st . ':' . (int) $i['id'] . '"' . ($i['status'] === $st ? ' disabled aria-pressed="true"' : '') . '>' . $label . '</button></form>';
            $h .= $btn('validated', $i['status'] === 'validated' ? '✓ Validated' : 'Validate', 'hp-val') . $btn('rejected', 'Not taken', '');
        }
        $h .= '<a class="btn btn-primary" href="' . e($ideaUrl) . '">' . ($pct === null && !$sent ? 'Open and check with AI' : 'Open the idea') . ' →</a></div>'
            . ($sent ? '<p class="muted sm">Your top ideas have been sent, so decisions are final.</p>' : '') . '</section>';
    } elseif ($round) {
        $h .= '<section class="iv-card hp-pi empty"><span class="iv-eyebrow">' . e($round['name']) . '</span><p>No idea from ' . e(explode(' ', (string) $s['name'])[0]) . ' yet.</p>'
            . '<p class="muted sm">Share your form link from the Dashboard; their idea appears here when they send it.</p></section>';
    }

    $row = static fn(string $k, string $v) => $v !== '' ? '<dt>' . e($k) . '</dt><dd>' . $v . '</dd>' : '';
    $h .= '<section class="iv-card"><h3 class="hp-pv-h">Details</h3><dl class="iv-dl">'
        . $row('Email', $email !== '' ? '<a href="mailto:' . e($email) . '">' . e($email) . '</a>' : '')
        . $row('Phone', $phone !== '' ? '<a href="tel:' . e($phone) . '">' . e($phone) . '</a>' : '')
        . $row('PIN / roll', e($pin))
        . $row('College', e(trim((((string) ($s['college_code'] ?? '')) !== '' ? $s['college_code'] . ' · ' : '') . $college)))
        . $row('District', e($district))
        . $row('Year', e((string) $s['year_of_study']))
        . '</dl><p class="muted sm hp-pv-foot">The owner keeps this list. Ask them to correct anything wrong.</p></section>';

    $older = fabo_all('SELECT i.id, i.title, i.status, r.name AS round_name FROM iv_ideas i JOIN iv_rounds r ON r.id = i.round_id WHERE i.org_id = ? AND i.student_id = ? AND i.department_id = ?' . ($round ? ' AND i.round_id <> ' . (int) $round['id'] : '') . ' ORDER BY i.id DESC',
                      'iii', [$org, (int) $s['id'], (int) $dept['id']]);
    if ($older) {
        $h .= '<section class="iv-card"><h3 class="hp-pv-h">Earlier rounds</h3><ul class="iv-list">';
        foreach ($older as $o) {
            $h .= '<li><a href="' . e(iv_purl(['v' => 'idea', 'id' => (int) $o['id']])) . '">' . e($o['title']) . '</a> <span class="muted sm">· ' . e($o['round_name']) . '</span> ' . iv_pill($o['status']) . '</li>';
        }
        $h .= '</ul></section>';
    }
    return $h . '</div>';
}

/** One student of this department, read-only. Anyone outside the department answers "not here". */
function iv_portal_view_student(int $org, array $me, array $dept, ?array $round): void
{
    $s = iv_student($org, (int) ($_GET['id'] ?? 0));
    $rq = $round ? ['round' => (int) $round['id']] : [];
    if (!$s || (int) $s['department_id'] !== (int) $dept['id']) {
        echo '<section class="iv-card"><p>That student is not in your department. <a href="' . e(iv_purl(['v' => 'students'] + $rq)) . '">Back to your students</a>.</p></section>';
        return;
    }
    echo '<p class="iv-crumb"><a href="' . e(iv_purl(['v' => 'students'] + $rq)) . '">← Students</a></p>';
    // No list of others who gave this PIN here: they may be other departments' students, which a HoD does not see.
    echo iv_student_profile_html($org, $s, static fn(int $id) => iv_purl(['v' => 'idea', 'id' => $id] + $rq), null);
    echo '<p class="muted sm">To correct these details, ask the owner: they keep the student list.</p>';
}

function iv_portal_view_profile(int $org, array $me, array $dept, ?array $round): void
{
    $must = (int) $me['must_change'] === 1;
    echo '<section class="iv-card hp-narrow"><h1>Your profile</h1><dl class="iv-dl"><dt>Name</dt><dd>' . e($me['name']) . '</dd><dt>Email</dt><dd>' . e($me['email']) . '</dd>'
       . '<dt>Department</dt><dd>' . e($dept['name']) . ', ' . e($dept['college_name']) . '</dd></dl><p class="muted sm">To change your name or email, ask the owner.</p>'
       . '<h2>' . ($must ? 'Choose a new password to continue' : 'Change password') . '</h2><form method="post" class="iv-form">' . fabo_csrf_field() . '<input type="hidden" name="a" value="password">'
       . ($must ? '' : '<label class="field"><span>Current password</span><input type="password" name="current" required autocomplete="current-password"></label>')
       . '<label class="field"><span>New password</span><input type="password" name="password" required minlength="10" autocomplete="new-password"></label>'
       . '<label class="field"><span>Once more</span><input type="password" name="password2" required minlength="10" autocomplete="new-password"></label>'
       . '<button class="btn btn-primary">Change password</button></form></section>';
}

<?php
/**
 * Academic — data protection and Campus security (Phase 5).
 *
 * Written with India's Digital Personal Data Protection Act, 2023 in mind
 * (it is not legal advice; the organisation's own counsel signs off the
 * notice):
 *   - Consent: everyone accepts the organisation's privacy notice before the
 *     Campus portal opens; a new notice text asks again. A student under 18
 *     names a parent or guardian, who confirms from an emailed link before
 *     the portal opens (switchable).
 *   - Rights: a person downloads their data, asks for a correction, or asks
 *     for their account to be erased; staff act on requests from the desk.
 *   - Accountability: who looked at or exported whose records (access log),
 *     sign-in and security events (security log), and a retention review of
 *     accounts nobody uses any more. Logs are trimmed by age.
 *
 * Security for the Campus portal: a one-time code by email at sign-in for
 * the roles that see many people's records (institution admin and council,
 * by default), idle and absolute session limits, stronger passwords, and an
 * email when a password changes.
 */

require_once __DIR__ . '/core.php';
require_once __DIR__ . '/../throttle.php';

const EDU_PRIV_VERSION = 5;
const EDU_CODE_MINUTES = 10;
const EDU_GUARDIAN_DAYS = 14;

function edu_privacy_install(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if ((int) fabo_setting('edu_priv_schema', '0') >= EDU_PRIV_VERSION) {
        return;
    }
    $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    foreach ([
        "CREATE TABLE IF NOT EXISTS edu_consents (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, org_id INT UNSIGNED NOT NULL, person_id INT UNSIGNED NOT NULL,
           notice_version CHAR(12) NOT NULL, accepted_at DATETIME NOT NULL, ip VARCHAR(45) NULL,
           minor TINYINT(1) NOT NULL DEFAULT 0, guardian_name VARCHAR(160) NULL, guardian_email VARCHAR(160) NULL,
           guardian_hash CHAR(64) NULL, guardian_expires DATETIME NULL, guardian_at DATETIME NULL, guardian_ip VARCHAR(45) NULL,
           withdrawn_at DATETIME NULL,
           KEY k_person (org_id, person_id, id), UNIQUE KEY u_guardian (guardian_hash)) $t",
        "CREATE TABLE IF NOT EXISTS edu_requests (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, org_id INT UNSIGNED NOT NULL, person_id INT UNSIGNED NOT NULL,
           kind ENUM('erase','correct') NOT NULL, details TEXT NULL, who VARCHAR(190) NOT NULL DEFAULT '',
           status ENUM('open','done','rejected') NOT NULL DEFAULT 'open', staff_note VARCHAR(500) NOT NULL DEFAULT '',
           decided_by VARCHAR(190) NOT NULL DEFAULT '', created_at DATETIME NOT NULL, decided_at DATETIME NULL,
           KEY k_org (org_id, status, created_at)) $t",
        "CREATE TABLE IF NOT EXISTS edu_access_log (
           id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, org_id INT UNSIGNED NOT NULL, actor VARCHAR(190) NOT NULL,
           action VARCHAR(30) NOT NULL, person_id INT UNSIGNED NOT NULL DEFAULT 0, detail VARCHAR(255) NOT NULL DEFAULT '',
           ip VARCHAR(45) NULL, created_at DATETIME NOT NULL,
           KEY k_org (org_id, created_at), KEY k_person (person_id, created_at)) $t",
        "CREATE TABLE IF NOT EXISTS edu_security_log (
           id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, org_id INT UNSIGNED NOT NULL, person_id INT UNSIGNED NOT NULL DEFAULT 0,
           event VARCHAR(30) NOT NULL, detail VARCHAR(255) NOT NULL DEFAULT '', ip VARCHAR(45) NULL, created_at DATETIME NOT NULL,
           KEY k_org (org_id, created_at), KEY k_person (person_id, created_at)) $t",
        "CREATE TABLE IF NOT EXISTS edu_login_codes (
           person_id INT UNSIGNED PRIMARY KEY, org_id INT UNSIGNED NOT NULL, code_hash CHAR(64) NOT NULL,
           expires_at DATETIME NOT NULL, attempts TINYINT UNSIGNED NOT NULL DEFAULT 0) $t",
    ] as $sql) {
        fabo_exec($sql);
    }
    fabo_exec('INSERT INTO app_settings (k, v) VALUES ("edu_priv_schema", ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', 's', [(string) EDU_PRIV_VERSION]);
}

/* ================================================================== logs */

function edu_seclog(int $org, int $pid, string $event, string $detail = ''): void
{
    try {
        edu_privacy_install();
        fabo_exec('INSERT INTO edu_security_log (org_id, person_id, event, detail, ip, created_at) VALUES (?,?,?,?,?,?)',
                  'iissss', [$org, $pid, $event, mb_substr($detail, 0, 255), fabo_client_ip(), edu_now()]);
    } catch (Throwable $e) {
        error_log('FABO edu seclog: ' . $e->getMessage());
    }
}

/** Who looked at or took whose records. $pid 0 for a list or an export. */
function edu_access(int $org, string $actor, string $action, int $pid = 0, string $detail = ''): void
{
    try {
        edu_privacy_install();
        fabo_exec('INSERT INTO edu_access_log (org_id, actor, action, person_id, detail, ip, created_at) VALUES (?,?,?,?,?,?,?)',
                  'ississs', [$org, mb_substr($actor, 0, 190), $action, $pid, mb_substr($detail, 0, 255), fabo_client_ip(), edu_now()]);
    } catch (Throwable $e) {
        error_log('FABO edu access log: ' . $e->getMessage());
    }
}

function edu_seclog_words(string $e): string
{
    return ['login_ok' => 'Signed in', 'login_fail' => 'Wrong password', 'code_sent' => 'Sign-in code sent', 'code_ok' => 'Sign-in code accepted',
            'code_fail' => 'Wrong sign-in code', 'code_locked' => 'Too many wrong codes', 'timeout' => 'Session timed out', 'password' => 'Password changed',
            'reset_sent' => 'Reset link sent', 'consent' => 'Accepted privacy notice', 'guardian_ok' => 'Guardian consent confirmed',
            'export' => 'Downloaded own data', 'request' => 'Made a data request', 'erased' => 'Account erased', 'logout' => 'Signed out'][$e] ?? $e;
}

/** Trims logs by age: security events after a year, access records after three, used codes at once. */
function edu_privacy_trim(): void
{
    edu_privacy_install();
    fabo_exec('DELETE FROM edu_security_log WHERE created_at < ?', 's', [edu_now(-365 * 86400)]);
    fabo_exec('DELETE FROM edu_access_log WHERE created_at < ?', 's', [edu_now(-3 * 365 * 86400)]);
    fabo_exec('DELETE FROM edu_login_codes WHERE expires_at < ?', 's', [edu_now(-86400)]);
}

/* ============================================================ passwords */

/** Why a password is not good enough, or null. Used by every place a Campus password is set. */
function edu_password_problem(string $plain, ?array $person = null): ?string
{
    if (mb_strlen($plain) < 10) {
        return 'Use at least 10 characters.';
    }
    if (mb_strlen($plain) > 200) {
        return 'That is too long.';
    }
    $low = mb_strtolower($plain);
    if (preg_match('/^(.)\1+$/u', $plain) || in_array($low, ['password12', 'password123', '1234567890', 'qwertyuiop', 'iloveindia', 'welcome123', 'abcdefghij'], true)
        || preg_match('/^(password|qwerty|123456|welcome|admin)/', $low)) {
        return 'That password is too easy to guess. Try three or four unrelated words.';
    }
    if ($person) {
        $local = mb_strtolower((string) strtok((string) ($person['email'] ?? ''), '@'));
        $first = mb_strtolower((string) (preg_split('/\s+/', trim((string) ($person['name'] ?? '')))[0] ?? ''));
        foreach ([$local, $first] as $w) {
            if (mb_strlen($w) >= 4 && str_contains($low, $w)) {
                return 'Leave your name and email out of your password.';
            }
        }
    }
    return null;
}

/* ===================================================== two-step sign-in */

/** Which roles must enter an emailed code at sign-in: 'admins' (institution admin, council) | 'everyone' | 'off'. */
function edu_mfa_mode(int $org): string
{
    $m = edu_get($org, 'mfa_mode', 'admins');
    return in_array($m, ['admins', 'everyone', 'off'], true) ? $m : 'admins';
}

/**
 * Does this person need a code? Sample people (.example addresses, which
 * can never receive mail) never do, or they could not sign in at all.
 */
function edu_mfa_required(int $org, array $p): bool
{
    $mode = edu_mfa_mode($org);
    if ($mode === 'off' || str_ends_with((string) $p['email'], '.example')) {
        return false;
    }
    if ($mode === 'everyone') {
        return true;
    }
    return (bool) fabo_one('SELECT id FROM edu_roles WHERE org_id = ? AND person_id = ? AND role IN ("inst_admin","council") LIMIT 1', 'ii', [$org, (int) $p['id']]);
}

/** Makes and emails a six-digit code; replaces any earlier one. Returns [sent?, words]. */
function edu_mfa_send(int $org, array $p): array
{
    edu_privacy_install();
    $pid = (int) $p['id'];
    if (fabo_throttle_blocked('edu-code-send', (string) $pid, 6, 3600)) {
        return [false, fabo_throttle_words()];
    }
    fabo_throttle_hit('edu-code-send', (string) $pid);
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    fabo_exec('INSERT INTO edu_login_codes (person_id, org_id, code_hash, expires_at, attempts) VALUES (?,?,?,?,0)
               ON DUPLICATE KEY UPDATE code_hash = VALUES(code_hash), expires_at = VALUES(expires_at), attempts = 0',
              'iiss', [$pid, $org, hash('sha256', $pid . '|' . $code), edu_now(EDU_CODE_MINUTES * 60)]);
    require_once __DIR__ . '/mentoring.php';     // edu_send()
    [$ok] = edu_send($org, (string) $p['email'], $code . ' is your ' . edu_org_name($org) . ' sign-in code',
                     'Your sign-in code is ' . $code . "\n\nIt works for " . EDU_CODE_MINUTES . " minutes. If you did not just sign in to the Campus portal, change your password: someone else knows it.\n\n" . edu_org_name($org));
    edu_seclog($org, $pid, 'code_sent');
    return [$ok, $ok ? 'We emailed a 6-digit code to ' . edu_mask_email((string) $p['email']) . '.' : 'The code could not be sent. Try again in a minute.'];
}

/** Checks a code. Five wrong tries and the code is spent. */
function edu_mfa_check(int $org, array $p, string $code): bool
{
    edu_privacy_install();
    $pid = (int) $p['id'];
    $row = fabo_one('SELECT * FROM edu_login_codes WHERE person_id = ? AND org_id = ?', 'ii', [$pid, $org]);
    if (!$row || $row['expires_at'] < edu_now() || (int) $row['attempts'] >= 5) {
        return false;
    }
    if (!hash_equals((string) $row['code_hash'], hash('sha256', $pid . '|' . preg_replace('/\D/', '', $code)))) {
        fabo_exec('UPDATE edu_login_codes SET attempts = attempts + 1 WHERE person_id = ?', 'i', [$pid]);
        edu_seclog($org, $pid, (int) $row['attempts'] + 1 >= 5 ? 'code_locked' : 'code_fail');
        return false;
    }
    fabo_exec('DELETE FROM edu_login_codes WHERE person_id = ?', 'i', [$pid]);
    edu_seclog($org, $pid, 'code_ok');
    return true;
}

function edu_mask_email(string $e): string
{
    [$a, $d] = array_pad(explode('@', $e, 2), 2, '');
    return mb_substr($a, 0, 2) . str_repeat('•', max(1, mb_strlen($a) - 2)) . '@' . $d;
}

/* ============================================================== sessions */

/** Idle and absolute limits (seconds) for a person: shorter for those who see many records. */
function edu_session_limits(int $org, int $pid): array
{
    $wide = (bool) fabo_one('SELECT id FROM edu_roles WHERE org_id = ? AND person_id = ? AND role IN ("inst_admin","council") LIMIT 1', 'ii', [$org, $pid]);
    return $wide ? [30 * 60, 12 * 3600] : [8 * 3600, 7 * 86400];
}

/* =============================================================== consent */

function edu_notice_default(int $org): string
{
    $n = edu_org_name($org);
    return "# How " . $n . " uses your information\n\n"
         . $n . " runs this Campus portal for its institutions, students, faculty and mentors.\n\n"
         . "## What we keep\n- Your name, email, phone, institution, department, programme, year, roll number and APAAR ID, as your institution gave them\n"
         . "- What you do here: courses and quiz results, mentoring sessions, ideas and applications, XP, coins and orders\n- Sign-in and security records\n\n"
         . "## Why\n- To run your learning, mentoring and incubation support\n- To report numbers to your institution and to bodies that oversee it (counts, not your personal details, unless they need them for a listed purpose)\n\n"
         . "## Who sees it\n- You; your institution's admins and your faculty; your mentors (your learning and ideas); the programme team. We do not sell your data or use it for advertising.\n\n"
         . "## Your rights\n- See and download your data, ask us to correct it, or ask us to erase your account (from Your profile → Your data)\n- Withdraw consent the same way; some records may be kept where the law requires\n\n"
         . "## Students under 18\nWe need a parent or guardian to agree before you use the portal.\n\n"
         . "## Questions or complaints\nWrite to our grievance officer (below). You may also approach the Data Protection Board of India.";
}

function edu_notice_text(int $org): string
{
    $t = trim(edu_get($org, 'privacy_notice', ''));
    return $t !== '' ? $t : edu_notice_default($org);
}

/** The notice's version: changes whenever its words (or the grievance contact) change. */
function edu_notice_version(int $org): string
{
    return substr(sha1(edu_notice_text($org) . '|' . edu_get($org, 'grievance_contact', '')), 0, 12);
}

function edu_guardian_required(int $org): bool
{
    return edu_get($org, 'guardian_required', '1') === '1';
}

/** A person's latest consent row, or null. */
function edu_consent_of(int $org, int $pid): ?array
{
    edu_privacy_install();
    return fabo_one('SELECT * FROM edu_consents WHERE org_id = ? AND person_id = ? ORDER BY id DESC LIMIT 1', 'ii', [$org, $pid]);
}

/** What stands between this person and the portal: '' (nothing) | 'consent' | 'guardian'. */
function edu_consent_gate(int $org, array $p): string
{
    $c = edu_consent_of($org, (int) $p['id']);
    if (!$c || $c['withdrawn_at'] !== null || $c['notice_version'] !== edu_notice_version($org)) {
        return 'consent';
    }
    if ((int) $c['minor'] === 1 && $c['guardian_at'] === null && edu_guardian_required($org)) {
        return 'guardian';
    }
    return '';
}

/**
 * Records consent. A minor's guardian (name, email -- not their own) is
 * emailed a link to confirm. Returns the consent row id.
 */
function edu_consent_give(int $org, array $p, bool $minor, string $gName = '', string $gEmail = ''): int
{
    edu_privacy_install();
    $isStudent = (bool) fabo_one('SELECT id FROM edu_roles WHERE org_id = ? AND person_id = ? AND role = "student" LIMIT 1', 'ii', [$org, (int) $p['id']]);
    $minor = $minor && $isStudent;
    $gName = edu_clean($gName, 160);
    $gEmail = mb_strtolower(trim($gEmail));
    if ($minor) {
        if ($gName === '' || !filter_var($gEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Give your parent or guardian\'s name and email address.');
        }
        if ($gEmail === mb_strtolower((string) $p['email'])) {
            throw new InvalidArgumentException('Use your parent or guardian\'s own email address, not yours.');
        }
    }
    $prev = edu_consent_of($org, (int) $p['id']);
    // A guardian who already confirmed this student stays confirmed when only the notice changed.
    $keepGuardian = $minor && $prev && (int) $prev['minor'] === 1 && $prev['guardian_at'] !== null && mb_strtolower((string) $prev['guardian_email']) === $gEmail;
    $token = $minor && !$keepGuardian ? bin2hex(random_bytes(24)) : null;
    $id = fabo_exec('INSERT INTO edu_consents (org_id, person_id, notice_version, accepted_at, ip, minor, guardian_name, guardian_email, guardian_hash, guardian_expires, guardian_at, guardian_ip)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', 'iisssissssss',
                    [$org, (int) $p['id'], edu_notice_version($org), edu_now(), fabo_client_ip(), $minor ? 1 : 0, $minor ? $gName : null, $minor ? $gEmail : null,
                     $token ? hash('sha256', $token) : null, $token ? edu_now(EDU_GUARDIAN_DAYS * 86400) : null,
                     $keepGuardian ? $prev['guardian_at'] : null, $keepGuardian ? $prev['guardian_ip'] : null]);
    edu_seclog($org, (int) $p['id'], 'consent', $minor ? 'under 18' : '');
    if ($token) {
        require_once __DIR__ . '/mentoring.php';
        $url = edu_portal_url($org, ['guardian' => $token]);
        edu_send($org, $gEmail, 'Please confirm ' . $p['name'] . '\'s use of the ' . edu_org_name($org) . ' Campus portal',
                 'Dear ' . $gName . ",\n\n" . $p['name'] . ' (a student under 18) has asked to use the ' . edu_org_name($org) . " Campus portal for courses, mentoring and startup support.\n\n"
                 . "Read how their information is used, and give or refuse your consent, here (the link works for " . EDU_GUARDIAN_DAYS . " days):\n" . $url
                 . "\n\nIf you do not know this student, ignore this message.\n\n" . edu_org_name($org));
    }
    return $id;
}

/** The consent row a guardian's link belongs to, while it is good. */
function edu_guardian_consent(string $token): ?array
{
    if (!preg_match('/^[0-9a-f]{48}$/', $token)) {
        return null;
    }
    edu_privacy_install();
    return fabo_one('SELECT * FROM edu_consents WHERE guardian_hash = ? AND guardian_expires > ? AND withdrawn_at IS NULL', 'ss', [hash('sha256', $token), edu_now()]);
}

function edu_guardian_confirm(string $token, bool $agree): ?array
{
    $c = edu_guardian_consent($token);
    if (!$c) {
        return null;
    }
    if ($agree) {
        fabo_exec('UPDATE edu_consents SET guardian_at = ?, guardian_ip = ?, guardian_hash = NULL WHERE id = ?', 'ssi', [edu_now(), fabo_client_ip(), (int) $c['id']]);
        edu_seclog((int) $c['org_id'], (int) $c['person_id'], 'guardian_ok', (string) $c['guardian_name']);
    } else {
        fabo_exec('UPDATE edu_consents SET withdrawn_at = ?, guardian_hash = NULL WHERE id = ?', 'si', [edu_now(), (int) $c['id']]);
        edu_seclog((int) $c['org_id'], (int) $c['person_id'], 'consent', 'guardian refused');
    }
    return $c;
}

/** Consent numbers for the desk. */
function edu_consent_totals(int $org): array
{
    edu_privacy_install();
    $v = edu_notice_version($org);
    $r = fabo_one('SELECT COUNT(*) AS people, SUM(c.id IS NOT NULL AND c.notice_version = ? AND c.withdrawn_at IS NULL) AS current,
                          SUM(c.minor = 1 AND c.withdrawn_at IS NULL) AS minors, SUM(c.minor = 1 AND c.guardian_at IS NOT NULL AND c.withdrawn_at IS NULL) AS guardian_ok
                     FROM edu_people p LEFT JOIN edu_consents c ON c.id = (SELECT MAX(id) FROM edu_consents x WHERE x.person_id = p.id AND x.org_id = p.org_id)
                    WHERE p.org_id = ? AND p.pass_hash IS NOT NULL', 'si', [$v, $org]) ?: [];
    return array_map('intval', $r);
}

/* ================================================================ rights */

/** Everything held about a person, for "Download my data". */
function edu_person_export(int $org, int $pid): array
{
    $p = edu_person($org, $pid);
    if (!$p) {
        return [];
    }
    unset($p['pass_hash'], $p['invite_hash']);
    $q = static fn(string $sql) => fabo_all($sql, 'ii', [$org, $pid]);
    $out = ['organisation' => edu_org_name($org), 'exported_at' => edu_now(), 'person' => $p,
            'roles' => array_map(static fn($r) => ['role' => $r['role'], 'institution' => $r['inst_name'], 'department' => $r['dept_name'], 'title' => $r['title']], edu_person_roles($org, $pid))];
    $tables = [
        'courses' => 'SELECT c.title, e.enrolled_at, e.progress, e.completed_at, e.cert_code FROM edu_enrolments e JOIN edu_courses c ON c.id = e.course_id WHERE e.org_id = ? AND e.person_id = ?',
        'quiz_attempts' => 'SELECT l.title AS quiz, a.score, a.passed, a.created_at FROM edu_attempts a JOIN edu_lessons l ON l.id = a.lesson_id WHERE a.org_id = ? AND a.person_id = ?',
        'mentoring_sessions' => 'SELECT s.starts_at, s.minutes, s.mode, s.status, s.topic, s.rating, s.feedback FROM edu_slots s WHERE s.org_id = ? AND s.student_id = ?',
        'rewards_ledger' => 'SELECT currency, amount, reason, note, created_at FROM edu_ledger WHERE org_id = ? AND person_id = ? ORDER BY id',
        'badges' => 'SELECT badge, earned_at FROM edu_badges WHERE org_id = ? AND person_id = ?',
        'store_orders' => 'SELECT s.title, o.price, o.status, o.note, o.created_at FROM edu_orders o JOIN edu_store_items s ON s.id = o.item_id WHERE o.org_id = ? AND o.person_id = ?',
        'ideas' => 'SELECT title, problem, solution, sector, stage, team, inc_ref, applied_at, created_at FROM edu_ideas WHERE org_id = ? AND person_id = ?',
        'consents' => 'SELECT notice_version, accepted_at, minor, guardian_name, guardian_email, guardian_at, withdrawn_at FROM edu_consents WHERE org_id = ? AND person_id = ?',
        'requests' => 'SELECT kind, details, status, staff_note, created_at, decided_at FROM edu_requests WHERE org_id = ? AND person_id = ?',
        'security_events' => 'SELECT event, created_at FROM edu_security_log WHERE org_id = ? AND person_id = ? ORDER BY id DESC LIMIT 200',
    ];
    foreach ($tables as $k => $sql) {
        try {
            $out[$k] = $q($sql);
        } catch (Throwable $e) {
            $out[$k] = [];      // a module not installed yet: nothing held there
        }
    }
    return $out;
}

/** A request from the person: 'erase' or 'correct'. One open request of each kind at a time. */
function edu_request_make(int $org, array $p, string $kind, string $details): int
{
    edu_privacy_install();
    if (!in_array($kind, ['erase', 'correct'], true)) {
        throw new InvalidArgumentException('Pick what you are asking for.');
    }
    $details = mb_substr(trim($details), 0, 2000);
    if ($kind === 'correct' && mb_strlen($details) < 5) {
        throw new InvalidArgumentException('Say what is wrong and what it should be.');
    }
    if (fabo_one('SELECT id FROM edu_requests WHERE org_id = ? AND person_id = ? AND kind = ? AND status = "open"', 'iis', [$org, (int) $p['id'], $kind])) {
        throw new InvalidArgumentException('You already have a request like this waiting. We will reply by email.');
    }
    $id = fabo_exec('INSERT INTO edu_requests (org_id, person_id, kind, details, who, created_at) VALUES (?,?,?,?,?,?)',
                    'iissss', [$org, (int) $p['id'], $kind, $details, mb_substr($p['name'] . ' <' . $p['email'] . '>', 0, 190), edu_now()]);
    edu_seclog($org, (int) $p['id'], 'request', $kind);
    // Tell the organisation's head.
    $head = edu_org_actor($org);
    if ($head && !empty($head['email'])) {
        require_once __DIR__ . '/mentoring.php';
        edu_send($org, (string) $head['email'], 'Campus data request: ' . ($kind === 'erase' ? 'erase an account' : 'correct a record'),
                 $p['name'] . ' has asked to ' . ($kind === 'erase' ? 'erase their Campus account' : 'correct their record') . ".\nOpen Academic → Privacy & security to act on it.\n\n" . edu_org_name($org));
    }
    return $id;
}

/** Staff act on a request. Erasing removes the account and everything held about it (edu_person_delete). */
function edu_request_decide(int $org, int $id, string $status, string $actor, string $note = ''): void
{
    $r = fabo_one('SELECT * FROM edu_requests WHERE org_id = ? AND id = ?', 'ii', [$org, $id]);
    if (!$r || $r['status'] !== 'open') {
        throw new InvalidArgumentException('That request is not waiting for a decision.');
    }
    if (!in_array($status, ['done', 'rejected'], true)) {
        throw new InvalidArgumentException('Pick done or rejected.');
    }
    if ($status === 'rejected' && trim($note) === '') {
        throw new InvalidArgumentException('Say why, for the record and for the person.');
    }
    $p = edu_person($org, (int) $r['person_id']);
    if ($status === 'done' && $r['kind'] === 'erase' && $p) {
        $email = (string) $p['email'];
        $name = $p['name'];
        edu_person_delete($org, (int) $p['id']);
        // Only a masked trace of who was erased stays, as the record that it was done.
        fabo_exec('UPDATE edu_requests SET who = ?, details = NULL WHERE id = ?', 'si', [mb_substr($name, 0, 1) . '… <' . edu_mask_email($email) . '>', $id]);
        edu_seclog($org, (int) $r['person_id'], 'erased', 'by ' . $actor);
        if ($email !== '') {
            require_once __DIR__ . '/mentoring.php';
            edu_send($org, $email, 'Your ' . edu_org_name($org) . ' Campus account has been erased',
                     'Hello ' . $name . ",\n\nAs you asked, your Campus account and the records held with it have been erased. An application you sent to incubation stays with the incubation team; write to them if you want it removed too.\n\n" . edu_org_name($org));
        }
    } elseif ($p && (string) $p['email'] !== '') {
        require_once __DIR__ . '/mentoring.php';
        edu_send($org, (string) $p['email'], 'Your data request', 'Hello ' . $p['name'] . ",\n\nYour request to " . ($r['kind'] === 'erase' ? 'erase your account' : 'correct your record')
                 . ' has been ' . ($status === 'done' ? 'done' : 'declined') . '.' . ($note !== '' ? "\n\n" . $note : '') . "\n\n" . edu_org_name($org));
    }
    fabo_exec('UPDATE edu_requests SET status = ?, staff_note = ?, decided_by = ?, decided_at = ? WHERE id = ?', 'ssssi', [$status, mb_substr(trim($note), 0, 500), mb_substr($actor, 0, 190), edu_now(), $id]);
}

function edu_requests(int $org, bool $openOnly = true): array
{
    edu_privacy_install();
    return fabo_all('SELECT * FROM edu_requests WHERE org_id = ?' . ($openOnly ? ' AND status = "open"' : '') . ' ORDER BY status = "open" DESC, created_at DESC LIMIT 200', 'i', [$org]);
}

/** Accounts nobody uses: suspended, or never/not signed in for $months. For a person to review, never removed automatically. */
function edu_retention_review(int $org, int $months = 24): array
{
    $cut = edu_now(-$months * 30 * 86400);
    return fabo_all('SELECT p.*, (SELECT GROUP_CONCAT(DISTINCT r.role) FROM edu_roles r WHERE r.person_id = p.id) AS role_list FROM edu_people p
                      WHERE p.org_id = ? AND (p.status = "suspended" OR COALESCE(p.last_login_at, p.created_at) < ?)
                        AND p.email NOT LIKE "%.example" ORDER BY COALESCE(p.last_login_at, p.created_at) LIMIT 500', 'is', [$org, $cut]);
}

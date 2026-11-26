<?php
/**
 * Access model. There are no logins and no identity sessions — a client is
 * whoever holds the link. Everything here is enforced server-side, in the
 * page controller AND in every JSON, save and export endpoint.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/rules.php';
require_once __DIR__ . '/orghost.php';

/* -------------------------------------------------------------- CSRF */

/**
 * A session is used only to hold the CSRF token. It carries no identity:
 * who you are is decided entirely by the token in the URL.
 */
function fabo_session(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }
    // Behind Hostinger's proxy the HTTPS flag arrives as a header.
    $https = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
          || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;

    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'cookie_secure'   => $https,
        'use_strict_mode' => true,
    ]);
}

function fabo_csrf_token(): string
{
    fabo_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function fabo_csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . htmlspecialchars(fabo_csrf_token(), ENT_QUOTES) . '">';
}

/** Verifies the CSRF token on a POST, or stops with a generic error. */
function fabo_csrf_check(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    /* An upload too big for the server arrives with no fields at all, so
       it would fail here as an expired session. Say what really happened. */
    require_once __DIR__ . '/uploads.php';
    if (fabo_upload_body_lost()) {
        fabo_deny(fabo_upload_too_big_words(), 413);
    }
    fabo_session();
    $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($sent) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sent)) {
        error_log('FABO CSRF check failed for ' . ($_SERVER['REQUEST_URI'] ?? '?'));
        fabo_deny('Your session expired. Reload the page and try again.', 400);
    }
}

/* ------------------------------------------------------------- denial */

/** Generic on screen, detail in error_log. Used by pages and endpoints alike. */
function fabo_deny(string $message = 'Not available.', int $code = 403): void
{
    http_response_code($code);
    $json = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
         || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $message]);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: noindex, nofollow');
        $m = htmlspecialchars($message, ENT_QUOTES);
        echo '<!doctype html><meta charset="utf-8"><meta name="robots" content="noindex,nofollow">'
           . '<title>Not available</title>'
           . '<style>body{font:15px/1.6 system-ui,Segoe UI,sans-serif;background:#f6f7f9;color:#1d2330;'
           . 'display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0}'
           . 'div{background:#fff;border:1px solid #dfe3ea;border-radius:10px;padding:26px 30px;max-width:460px}</style>'
           . '<div>' . $m . '</div>';
    }
    exit;
}

/* ------------------------------------------------------- safe redirects */

/**
 * The page to land on after signing in, from a ?next= parameter.
 *
 * An approval email links straight at one row, and that link goes through the
 * sign-in page. Only our own relative pages are ever followed: anything with a
 * scheme, a host, or a leading slash is dropped, so the parameter can never
 * bounce someone to another site.
 */
function fabo_safe_next(string $next): string
{
    $next = trim($next);
    if ($next === '' || strlen($next) > 300) {
        return '';
    }
    if (str_contains($next, "\n") || str_contains($next, "\r")) {
        return '';   // never let a header be split
    }
    if (str_starts_with($next, '/') || str_starts_with($next, '\\') || str_contains($next, '//')) {
        return '';
    }
    if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $next)) {
        return '';   // http:, javascript:, data: …
    }
    // A relative path within the portal, nothing climbing out of it.
    $path = explode('?', $next, 2)[0];
    if (str_contains($path, '..') || !preg_match('~^[A-Za-z0-9_./-]+\.php$~', $path)) {
        return '';
    }
    return $next;
}

/* ------------------------------------------------------------ clients */

function fabo_new_token(): string
{
    do {
        $t = bin2hex(random_bytes(16)); // 32 hex characters
        $clash = fabo_one('SELECT id FROM clients WHERE token = ?', 's', [$t]);
    } while ($clash);
    return $t;
}

function fabo_client_by_token(string $token): ?array
{
    if (!preg_match('/^[0-9a-f]{32}$/', $token)) {
        return null;
    }
    return fabo_one('SELECT * FROM clients WHERE token = ?', 's', [$token]);
}

/** 'ok' | 'expired' | 'revoked'. No expiry date means no expiry. */
function fabo_client_state(array $client): string
{
    if (!empty($client['revoked_at'])) {
        return 'revoked';
    }
    if (!empty($client['expires_at']) && strtotime($client['expires_at']) < time()) {
        return 'expired';
    }
    return 'ok';
}

/** sheet_key => 'view'|'edit' for one client. */
function fabo_client_access(int $clientId): array
{
    $out = [];
    foreach (fabo_all('SELECT sheet_key, permission FROM client_sheet_access WHERE client_id = ?',
                      'i', [$clientId]) as $r) {
        $out[$r['sheet_key']] = $r['permission'];
    }
    return $out;
}

/**
 * The same, for a whole list at once: client_id => (sheet_key => permission).
 *
 * A roster of a hundred people otherwise runs a hundred queries to colour in
 * one column. Missing ids come back as an empty map, so callers can index
 * straight into the result.
 */
function fabo_access_map(array $clientIds): array
{
    $ids = array_values(array_unique(array_map('intval', $clientIds)));
    $out = [];
    foreach ($ids as $id) {
        $out[$id] = [];
    }
    if (!$ids) {
        return $out;
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    foreach (fabo_all('SELECT client_id, sheet_key, permission FROM client_sheet_access
                       WHERE client_id IN (' . $in . ')',
                      str_repeat('i', count($ids)), $ids) as $r) {
        $out[(int) $r['client_id']][$r['sheet_key']] = $r['permission'];
    }
    return $out;
}

/** How many accounts each of these people has created. id => count. */
function fabo_children_counts(array $parentIds): array
{
    $ids = array_values(array_unique(array_map('intval', $parentIds)));
    $out = [];
    foreach ($ids as $id) {
        $out[$id] = 0;
    }
    if (!$ids) {
        return $out;
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    foreach (fabo_all('SELECT parent_id, COUNT(*) AS n FROM clients
                       WHERE parent_id IN (' . $in . ') GROUP BY parent_id',
                      str_repeat('i', count($ids)), $ids) as $r) {
        $out[(int) $r['parent_id']] = (int) $r['n'];
    }
    return $out;
}

/* ------------------------------------------------------------- viewer */

/**
 * The one object every page and endpoint authorises against.
 *   is_admin, client (row|null), access (map|null), brand, bu
 * Admin has every sheet at 'edit' and no scope.
 */
function fabo_admin_viewer(): array
{
    return ['is_admin' => true, 'client' => null, 'access' => null,
            'brand' => 'both', 'bu' => 'both', 'who' => 'admin'];
}

/**
 * Resolves the client viewer from ?k=, or stops. Callers that must tolerate
 * an expired link (the client page itself) pass $allowExpired.
 */
function fabo_client_viewer(string $token, bool $allowExpired = false): array
{
    $c = fabo_client_by_token($token);
    if (!$c) {
        fabo_deny('This link is not valid.', 404);
    }
    // An account whose creator is no longer active is not active either.
    $state = fabo_effective_state($c);
    if ($state !== 'ok' && !$allowExpired) {
        fabo_deny('This link is no longer active.', 403);
    }
    fabo_link_org(fabo_org_head((int) $c['id']));       // links made here carry their organisation's address
    return [
        'is_admin' => false,
        'client'   => $c,
        'state'    => $state,
        'access'   => fabo_client_access((int) $c['id']),
        'brand'    => 'both',      // scope retired; see fabo_scope_where()
        'bu'       => 'both',
        'who'      => 'client #' . $c['id'] . ' ' . $c['name'],
    ];
}

/* ------------------------------------------------------ credentials */

/**
 * A readable one-off password: two words and six random characters, easy to
 * retype from an email. The words carry ~9 bits; the six characters, drawn from
 * 32 that cannot be misread (no 0/O, 1/I/L), carry 30 more -- ~39 bits in all,
 * where two words and a 4-digit number carried only ~22 and could be guessed
 * online. It is changed at first sign-in either way.
 */
function fabo_make_password(): string
{
    $words = ['amber', 'basil', 'cedar', 'delta', 'ember', 'flint', 'grove', 'heron',
              'ivory', 'jasper', 'kite', 'lotus', 'maple', 'nimbus', 'onyx', 'pearl',
              'quartz', 'raven', 'sable', 'topaz', 'umber', 'violet', 'willow', 'zephyr'];
    $chars = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
    $tail  = '';
    for ($i = 0; $i < 6; $i++) {
        $tail .= $chars[random_int(0, strlen($chars) - 1)];
    }
    $a = $words[random_int(0, count($words) - 1)];
    $b = $words[random_int(0, count($words) - 1)];
    return ucfirst($a) . '-' . $b . '-' . $tail;
}

/**
 * A user ID nobody else has: what to sign in with when an address does not
 * identify one account.
 *
 * Several people can share one mailbox — a centre, a family business, a
 * shared office address — and then the address alone cannot say which
 * account is meant. Every account gets one of these so there is always an
 * answer that is only ever true of one of them.
 */
function fabo_make_login_id(string $name = ''): string
{
    $base = strtoupper(preg_replace('~[^A-Za-z]~', '', $name));
    $base = $base !== '' ? substr($base, 0, 3) : 'MCC';
    while (strlen($base) < 3) {
        $base .= 'X';
    }
    for ($i = 0; $i < 40; $i++) {
        $try = $base . '-' . str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT);
        if (!fabo_one('SELECT id FROM clients WHERE login_id = ?', 's', [$try])) {
            return $try;
        }
    }
    return 'MCC-' . bin2hex(random_bytes(4));   // never collide, however unlikely
}

/** The account's user ID, made and stored the first time it is asked for. */
function fabo_login_id(int $personId): string
{
    $p = fabo_one('SELECT login_id, name FROM clients WHERE id = ?', 'i', [$personId]);
    if (!$p) {
        return '';
    }
    $have = trim((string) ($p['login_id'] ?? ''));
    if ($have !== '') {
        return $have;
    }
    $made = fabo_make_login_id((string) ($p['name'] ?? ''));
    fabo_exec('UPDATE clients SET login_id = ? WHERE id = ?', 'si', [$made, $personId]);
    return $made;
}

/**
 * Would this password be ambiguous?
 *
 * Two accounts on the same address with the same password cannot be told
 * apart at sign-in: the address matches both and so does the password. The
 * portal refuses to create that rather than picking one of them and calling
 * the other a mismatch, which is what it used to do.
 */
function fabo_password_taken(string $email, string $plain, int $exceptId = 0): bool
{
    $email = trim($email);
    if ($email === '' || $plain === '') {
        return false;
    }
    foreach (fabo_all('SELECT id, password_hash FROM clients WHERE email = ? AND id <> ?',
                      'si', [$email, $exceptId]) as $other) {
        if (password_verify($plain, (string) ($other['password_hash'] ?? ''))) {
            return true;
        }
    }
    return false;
}

/**
 * Is this account one of several on its address?
 *
 * The answer decides how it is written to: an account with the mailbox to
 * itself can be told "sign in with your email"; one of five sharing it has
 * to be given its user ID, because the address alone opens nothing.
 */
function fabo_shares_email(array $person): int
{
    $email = trim((string) ($person['email'] ?? ''));
    if ($email === '') {
        return 0;
    }
    return (int) (fabo_one('SELECT COUNT(*) c FROM clients WHERE email = ?',
                           's', [$email])['c'] ?? 0);
}

/**
 * Sets a person's password and returns the plain text, to be emailed once.
 *
 * A generated password is tried again until it is unlike anyone else's on the
 * same address. One that somebody typed is not changed behind their back —
 * the caller is told instead, by fabo_password_taken().
 */
function fabo_set_password(int $personId, ?string $plain = null): string
{
    $person = fabo_one('SELECT email FROM clients WHERE id = ?', 'i', [$personId]);
    $email  = (string) ($person['email'] ?? '');

    if ($plain === null) {
        $plain = fabo_make_password();
        for ($i = 0; $i < 20 && fabo_password_taken($email, $plain, $personId); $i++) {
            $plain = fabo_make_password();
        }
    }
    fabo_exec('UPDATE clients SET password_hash = ?, must_change = 1 WHERE id = ?', 'si',
              [password_hash($plain, PASSWORD_DEFAULT), $personId]);
    fabo_login_id($personId);            // so the mail can carry it
    return $plain;
}

/**
 * Checks a user ID or an email, plus a password. Returns the person, or null.
 *
 * An address does not identify an account — several people may share one —
 * so every account that holds the address is tried, and the one whose
 * password verifies is the one signing in. Taking the lowest id and calling
 * everybody else a mismatch is exactly the bug this replaces.
 *
 * A user ID identifies exactly one account, and is offered for the case
 * where two people on one address want to be sure which they are opening.
 */
function fabo_check_login(string $who, string $password): ?array
{
    $who = trim($who);
    if ($who === '' || $password === '') {
        return null;
    }
    // A user ID first: it is unique, so it settles the question outright.
    $rows = fabo_all('SELECT * FROM clients WHERE login_id = ?', 's', [$who]);
    if (!$rows) {
        $rows = fabo_all('SELECT * FROM clients WHERE email = ? ORDER BY id', 's', [$who]);
    }

    /*
     * Every account the name could mean, and whether the password opens it.
     *
     * One match is a sign-in. Several — the same address AND the same
     * password on two accounts — is a question this form cannot answer, and
     * picking one would sign somebody into an account that is not theirs.
     * They are sent to their user ID instead, which means exactly one thing.
     */
    /*
     * As typed, then with the edges trimmed.
     *
     * A password is read out of an email and pasted, and a mail client hands
     * over a trailing space or a newline with it. The portal never generates
     * a password with whitespace at either end, so a trimmed match is the
     * password the person meant — and refusing it teaches them their details
     * are wrong when they are not. Anything in the middle is untouched.
     */
    $tries = [$password];
    $edged = trim($password);
    if ($edged !== '' && $edged !== $password) {
        $tries[] = $edged;
    }

    $opened = [];
    foreach ($rows as $p) {
        if (fabo_client_state($p) !== 'ok') {
            continue;
        }
        foreach ($tries as $try) {
            if (password_verify($try, (string) ($p['password_hash'] ?? ''))) {
                $opened[] = $p;
                break;
            }
        }
    }
    if (count($opened) === 1) {
        return $opened[0];
    }
    if (count($opened) > 1) {
        // Ambiguous, not wrong. The caller says so rather than "no".
        $GLOBALS['fabo_login_ambiguous'] = count($opened);
        return null;
    }
    // Always spend the time hashing, so a missing address is not faster to
    // probe than a real one with the wrong password. The stand-in has to be a
    // real bcrypt hash: a malformed one is rejected instantly, which made a
    // missing address measurably FASTER and gave away which ones exist.
    static $standIn = null;
    $standIn ??= password_hash('stand-in for timing only', PASSWORD_DEFAULT);
    password_verify($password, $standIn);
    return null;
}

/**
 * Did the last sign-in fail because the answer was "more than one account"?
 *
 * Several clients can share a mailbox and, since they sign in by user ID,
 * nothing stops them sharing a password too. When both match more than one
 * account, the form has to say which piece of information is missing.
 */
function fabo_login_was_ambiguous(): int
{
    return (int) ($GLOBALS['fabo_login_ambiguous'] ?? 0);
}

/* ----------------------------------------------------- admin sign-in */

/**
 * The administrator's sign-in details, from the database if they are there.
 *
 * They live in config.local.php, which is the right place for a secret and
 * the wrong place for something you may urgently need to change: that file
 * also holds the live database credentials, so replacing it to alter one
 * line risks the whole site, and editing it in place needs file access
 * somebody locked out may not have to hand.
 *
 * So the database may override it. app_settings holds `admin_email` and
 * `admin_pass_hash`; either one set there wins, and with neither set nothing
 * changes and the config file is still the answer. A password reset becomes
 * one row, which is something a person can do from phpMyAdmin at two in the
 * morning without touching a file that could take the portal down.
 *
 * Only ever a hash. Nothing here stores or accepts a plain password.
 */
function fabo_admin_setting(string $key): string
{
    static $cache = [];
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $cache[$key] = '';
    try {
        $row = fabo_one('SELECT v FROM app_settings WHERE k = ?', 's', [$key]);
        $cache[$key] = trim((string) ($row['v'] ?? ''));
    } catch (Throwable $e) {
        /* No table yet, or no database at all. The config file answers, which
           is what happened before this existed. */
    }
    return $cache[$key];
}

/** The address the administrator signs in with. */
function fabo_admin_email(): string
{
    $set = fabo_admin_setting('admin_email');
    return $set !== '' ? $set : (defined('ADMIN_EMAIL') ? ADMIN_EMAIL : '');
}

/** The hash their password is checked against. */
function fabo_admin_hash(): string
{
    $set = fabo_admin_setting('admin_pass_hash');
    return $set !== '' ? $set : (defined('ADMIN_PASS_HASH') ? ADMIN_PASS_HASH : '');
}

/** Is an admin password configured at all? */
function fabo_admin_locked(): bool
{
    return fabo_admin_hash() !== '';
}

/** Is this request being made on the server itself (the command line, or localhost)? */
function fabo_is_local_request(): bool
{
    if (PHP_SAPI === 'cli') {
        return true;
    }
    return in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true);
}

/**
 * A fingerprint of the admin password as it stands now. Kept in the session
 * at sign-in and compared on every request, so changing the password ends
 * every admin session that was opened with the old one.
 */
function fabo_admin_fingerprint(): string
{
    return hash('sha256', 'admin|' . fabo_admin_hash());
}

/**
 * Is the administrator signed in?
 *
 * This is the ONE question every admin gate asks. With no admin password set,
 * the answer is yes only on the server itself -- a development copy on
 * localhost -- and no for every visitor of a live site. It used to be yes for
 * everybody, so a deployment that lost its config handed the whole portal to
 * whoever opened it.
 */
function fabo_admin_signed_in(): bool
{
    if (!fabo_admin_locked()) {
        return fabo_is_local_request();
    }
    fabo_session();
    $have = $_SESSION['fabo_admin'] ?? '';
    if (!is_string($have) || $have === '' || !hash_equals(fabo_admin_fingerprint(), $have)) {
        unset($_SESSION['fabo_admin']);
        return false;
    }
    return true;
}

function fabo_admin_check(string $email, string $password): bool
{
    if (!fabo_admin_locked()) {
        return false;
    }
    /* The password is always checked, even when the address is wrong, so the
       time taken says nothing about whether the address was right. Trimmed as
       well as typed, because a password pasted out of a message arrives with a
       space or a newline on it more often than not. */
    $hash   = fabo_admin_hash();
    $passOk = password_verify($password, $hash);
    $edged  = trim($password);
    if (!$passOk && $edged !== '' && $edged !== $password) {
        $passOk = password_verify($edged, $hash);
    }
    $want    = fabo_admin_email();
    $emailOk = $want === '' || hash_equals(mb_strtolower($want), mb_strtolower(trim($email)));
    return $passOk && $emailOk;
}

function fabo_admin_login(): void
{
    fabo_session();
    session_regenerate_id(true);
    $_SESSION['fabo_admin'] = fabo_admin_fingerprint();
}

/**
 * Guards every admin page. Without an admin password configured, a live site
 * refuses outright rather than opening up -- set ADMIN_PASS_HASH in
 * config.local.php to use the admin area.
 */
function fabo_require_admin(): void
{
    if (fabo_admin_signed_in()) {
        return;
    }
    if (!fabo_admin_locked()) {
        fabo_deny('The admin area is not available: no admin password is configured on this server.', 503);
    }
    $to = rawurlencode($_SERVER['REQUEST_URI'] ?? '');
    header('Location: ../login.php?admin=1&next=' . $to);
    exit;
}

/* ------------------------------------------------- session sign-in */

/**
 * A fingerprint of a person's password as it stands now. Kept in their session
 * at sign-in and compared on every request: when the password changes -- by
 * them, by a reset, or by whoever manages them -- every session opened with
 * the old one ends.
 */
function fabo_person_fingerprint(int $personId): string
{
    $row = fabo_one('SELECT password_hash FROM clients WHERE id = ?', 'i', [$personId]);
    return hash('sha256', 'person|' . $personId . '|' . (string) ($row['password_hash'] ?? ''));
}

/** Remembers who signed in. Identity still comes from the database each request. */
function fabo_login(array $person): void
{
    fabo_session();
    session_regenerate_id(true);
    $_SESSION['fabo_person'] = (int) $person['id'];
    $_SESSION['fabo_pwv']    = fabo_person_fingerprint((int) $person['id']);
    fabo_exec('UPDATE clients SET last_login_at = NOW(), open_count = open_count + 1 WHERE id = ?',
              'i', [(int) $person['id']]);
}

/**
 * Keeps THIS session open after its own person changed their password. Every
 * other session they had ends, which is the point of changing it.
 */
function fabo_session_refresh(int $personId): void
{
    fabo_session();
    if ((int) ($_SESSION['fabo_person'] ?? 0) === $personId) {
        session_regenerate_id(true);
        $_SESSION['fabo_pwv'] = fabo_person_fingerprint($personId);
    }
}

/** Ends the session completely -- every key in it, and its cookie. */
function fabo_logout(): void
{
    fabo_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000, 'path' => $p['path'], 'domain' => $p['domain'],
            'secure' => $p['secure'], 'httponly' => $p['httponly'], 'samesite' => $p['samesite'] ?: 'Lax',
        ]);
    }
    session_destroy();
}

/**
 * Is every account above this one still active?
 *
 * A client's sub-accounts read and write that client's data, so revoking or
 * expiring the client has to take them with it. Before this, a client could
 * create a "never expires" sub-account, be revoked, and carry on through it.
 */
function fabo_lineage_ok(array $c): bool
{
    /* Read fresh every time, never remembered: a permission answer cached
       for the rest of the request would still say "active" after a revoke
       made earlier in that same request. One or two small lookups. */
    $parent = (int) ($c['parent_id'] ?? 0);
    for ($depth = 0; $parent > 0 && $depth < 8; $depth++) {
        $row = fabo_one('SELECT id, parent_id, revoked_at, expires_at FROM clients WHERE id = ?', 'i', [$parent]);
        if (!$row || fabo_client_state($row) !== 'ok') {
            return false;
        }
        $parent = (int) ($row['parent_id'] ?? 0);
    }
    return true;
}

/** A person's own state, made 'revoked' when an account above them is no longer active. */
function fabo_effective_state(array $c): string
{
    $own = fabo_client_state($c);
    return ($own === 'ok' && !fabo_lineage_ok($c)) ? 'revoked' : $own;
}

/** The signed-in person, or null. */
function fabo_signed_in(): ?array
{
    fabo_session();
    $id = (int) ($_SESSION['fabo_person'] ?? 0);
    if (!$id) {
        return null;
    }
    $p = fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [$id]);
    $pwv = $_SESSION['fabo_pwv'] ?? '';
    if (!$p || fabo_effective_state($p) !== 'ok'
        || !is_string($pwv) || !hash_equals(fabo_person_fingerprint($id), $pwv)) {
        unset($_SESSION['fabo_person'], $_SESSION['fabo_pwv']);
        return null;
    }
    // Their organisation's own address (lib/orghost.php): their links carry it, and
    // a page opened at another of our addresses moves there, still signed in.
    // Admin pages are the administrator's, whoever else is signed in alongside.
    if (!str_contains(str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '')), '/admin/')) {
        fabo_link_org(fabo_org_head($id));
        fabo_org_host_steer($p);
    }
    return $p;
}

/** Builds the viewer for a signed-in person — same shape as a token viewer. */
function fabo_person_viewer(array $c): array
{
    return [
        'is_admin' => false,
        'client'   => $c,
        'state'    => fabo_effective_state($c),
        'access'   => fabo_client_access((int) $c['id']),
        'brand'    => 'both',      // scope retired; see fabo_scope_where()
        'bu'       => 'both',
        'who'      => ($c['kind'] === 'team' ? 'team' : 'client') . ' #' . $c['id'] . ' ' . $c['name'],
    ];
}

/**
 * The portal viewer: a signed-in session, or the token in the URL.
 * Either way the sheets, scope and expiry come from the database.
 */
function fabo_portal_viewer(string $token = '', bool $allowExpired = false): ?array
{
    if ($token !== '') {
        return fabo_client_viewer($token, $allowExpired);
    }
    $c = fabo_signed_in();
    return $c ? fabo_person_viewer($c) : null;
}

/** Permission a viewer holds on one sheet: 'none' | 'view' | 'edit'. */
function fabo_permission(array $viewer, string $sheetKey): string
{
    if (!in_array($sheetKey, fabo_sheet_order(), true)) {
        return 'none';
    }
    if ($viewer['is_admin']) {
        return $sheetKey === 'dashboard' ? 'view' : 'edit';
    }
    if (($viewer['state'] ?? 'ok') !== 'ok') {
        return 'none';
    }
    $p = $viewer['access'][$sheetKey] ?? 'none';
    if ($sheetKey === 'dashboard' && $p === 'edit') {
        return 'view'; // the Dashboard is read-only for everyone
    }
    if (!empty(fabo_sheet($sheetKey)['admin_only'])) {
        return 'none'; // Lists is admin-only
    }
    return $p;
}

/** Stops unless the viewer holds at least $need on $sheetKey. */
function fabo_require(array $viewer, string $sheetKey, string $need = 'view'): void
{
    $p = fabo_permission($viewer, $sheetKey);
    if ($p === 'none' || ($need === 'edit' && $p !== 'edit')) {
        error_log('FABO denied ' . ($viewer['who'] ?? '?') . " -> {$sheetKey} ({$need})");
        fabo_deny('You do not have access to this sheet.', 403);
    }
}

/** The sheets a viewer may see, in canonical order. */
function fabo_visible_sheets(array $viewer): array
{
    $out = [];
    foreach (fabo_sheet_order() as $k) {
        if (fabo_permission($viewer, $k) !== 'none') {
            $out[$k] = fabo_permission($viewer, $k);
        }
    }
    return $out;
}

/* ------------------------------------------------------------ modules */

/*
 * A module is a desk that is granted like a sheet but has no editable grid:
 * it keeps its own pages and its own tables. The grant is stored in the same
 * client_sheet_access row, so revoking it works exactly the way revoking a
 * sheet does, and a client sees it in the same list.
 *
 * Modules are deliberately NOT in fabo_sheet_order(): that list drives the
 * grid, the exporter and the intake forms, none of which apply here.
 *
 * 'team' marks a module a client's head may pass on to their own team (My
 * Team), with the name and description shown there. This list is the only
 * place that says so: a module added here with 'team' appears on My Team by
 * itself (fabo_child_module_keys()).
 */
function fabo_modules(): array
{
    return [
        'finance' => [
            'label' => 'Finance',
            'page'  => 'finance.php',
            'hint'  => 'The Finance Portal for their organisation — ledger, payables, receivables, budgets, expenses, payments, assets and tax — plus what we bill them and the filing calendar. View only reads it.',
            'team'  => ['Finance',
                        'Owner: runs your Finance Portal as you do — ledger, bills, invoices, payments, budgets, settings and finance roles. View only: reads every desk and report. Without it they still claim expenses and raise requests; finer roles (AP accountant, CFO …) are given in Finance → Settings.'],
        ],
        /* No-login form links for their own sheets. View only shows their
           forms and links; Can edit also makes, renews and deletes them. */
        'intake' => [
            'label' => 'Intake Forms',
            'page'  => 'intake.php',
            'hint'  => 'Share their own sheets as forms anyone can fill in, without signing in.',
        ],
        /* Their organisation's OWN CRMs (lib/crm.php). Can edit lets them
           create CRMs and share them with their own team; View only reads
           them. One of OUR CRMs is shared separately, under "CRM access". */
        'crm' => [
            'label' => 'CRM — their own',
            'page'  => 'crm.php',
            'hint'  => 'Create and run their own CRMs, shared with their own team only.',
            'team'  => ['CRM',
                        'Owner: creates CRMs and runs every CRM your company has, as you do. View only: reads them all. To share just one CRM, use that CRM\'s Access tab.'],
        ],
        /* HR for their own company only (lib/hr). Can edit makes them its HR
           administrator: employees, attendance, leave, payroll, settings and
           who on their team may use HR. View only reads and exports it. */
        'hr' => [
            'label' => 'HR — their own',
            'page'  => 'hr.php',
            'hint'  => 'Run HR for their own company: employees, attendance, leave and payroll.',
            'team'  => ['HR Management',
                        'Owner: runs HR for your company — employees, attendance, leave, payroll and who may use HR. View only: reads and exports it.'],
        ],
        /* Their organisation's OWN Incubation Management (lib/inc, tables
           inc_o<their id>_*): never ours, never another client's. Can edit
           makes them its owner; View only reads and exports it. They may pass
           it on to their own team, never more than they hold. */
        'incubation' => [
            'label' => 'Incubation Management — their own',
            'page'  => 'incubation.php',
            'hint'  => 'Run their own Incubation Management: application forms, screening, evaluation and incubation workspaces.',
            'team'  => ['Incubation Management',
                        'Owner: runs your Incubation Management as you do — applications, screening, evaluation, workspaces, forms and settings. View only: reads and exports it.'],
        ],
        /* Their organisation's OWN Event Organizers (lib/eo on the lib/inc engine,
           tables eo_o<their id>_*): the events their committees approved, with
           forms, the application board, tickets and check-in. Separate from
           Incubation Management in both its data and who holds it. */
        'organizers' => [
            'label' => 'Event Organizers — their own',
            'page'  => 'organizers.php',
            'hint'  => 'Run their approved events: application forms and the pipeline, a public page, tickets, check-in, agenda and feedback.',
            'team'  => ['Event Organizers',
                        'Owner: runs your approved events as you do — forms, applications, public pages, tickets, check-in and settings. View only: reads and exports them.'],
        ],
        /* Their organisation's OWN IT desk and facilities desk (lib/it, tables
           it_o<id>_* / fa_o<id>_*). Can edit runs it; View only reads it.
           They may pass either to their own team, never more than they hold. */
        'it' => [
            'label' => 'IT Management — their own',
            'page'  => 'it.php',
            'hint'  => 'Run their own IT desk: helpdesk tickets, devices, renewals and vendors.',
            'team'  => ['IT Management',
                        'Owner: runs your IT desk as you do — tickets, devices, renewals, vendors and settings. View only: reads it.'],
        ],
        /* Their organisation's co-working centres -- the hub and its spokes
           (lib/cw, rows by org_id). Can edit keeps the registers; View only
           reads the dashboards. */
        'coworking' => [
            'label' => 'Co-working Space — their own',
            'page'  => 'coworking.php',
            'hint'  => 'Run their co-working centres: desks and cabins, startups, rent and dues, renewals, waiting list and the seat map.',
            'team'  => ['Co-working Space',
                        'Owner: runs your co-working centres as you do — spaces, startups, rent and dues, renewals, waiting list, monthly log and imports. View only: reads every centre\'s dashboards, seat map and registers.'],
        ],
        /* Their organisation's own Digital Signatures (lib/ds, rows by org_id):
           documents sent for signature, workflows, eSign / DSC, verification and
           the audit trail. Can edit runs it; View only reads it. Everyone in the
           organisation signs what is addressed to them either way. */
        'esign' => [
            'label' => 'Digital Signatures — their own',
            'page'  => 'sign.php',
            'hint'  => 'Send documents for signature and run the full signing lifecycle: workflows, Aadhaar eSign and DSC, verification, reports and the audit trail.',
            'team'  => ['Digital Signatures',
                        'Owner: runs Digital Signatures as you do — every document, workflow, signatory, provider and setting. View only: reads every document and report. Without it they still sign what is sent to them; finer roles are given in Digital Signatures → Roles.'],
        ],
        /* The CEO Dashboard (lib/ceo): an executive view across the modules their
           organisation holds. Ticking it here only switches it on for the
           organisation's head; nobody else sees it until the head names them on
           its Access page, so it is deliberately not a 'team' module. */
        'ceo' => [
            'label' => 'CEO Dashboard',
            'page'  => 'ceo.php',
            'hint'  => 'Live executive overview across their modules, hub and spokes, targets and reports. Only the head has it until they grant it to named people.',
        ],
        /* Their organisation's own Academic (lib/edu, rows by org_id): institutions,
           departments, students, faculty, mentors and institution admins, and the
           Campus portal (campus.php) those people sign in to with their own
           accounts -- never staff logins. Can edit runs it; View only reads it. */
        'academic' => [
            'label' => 'Academic — their own',
            'page'  => 'academic.php',
            'hint'  => 'Run their institutions, departments, students, faculty, mentors and institution admins, and the Campus portal they sign in to.',
            'team'  => ['Academic',
                        'Owner: runs Academic as you do — institutions, departments, people, imports, sign-in links and the Campus portal settings. View only: reads it.'],
        ],
        /* Their organisation's own Startup Discovery (lib/iv, rows by org_id): one
           dashboard per department for its Head of Department (who signs in to
           hod.php with their own account, never a staff login), students, idea
           rounds validated with a Gem, and one pipeline of the top ideas with teams. */
        'ideaval' => [
            'label' => 'Startup Discovery — their own',
            'page'  => 'idea-validation.php',
            'hint'  => 'HoD dashboards per department, students, idea rounds checked with a Gem, and one pipeline of each department\'s top ideas with teams.',
            'team'  => ['Startup Discovery',
                        'Owner: runs Startup Discovery as you do — HoD dashboards, students, rounds and timing, the pipeline and teams. View only: reads it.'],
        ],
        'facilities' => [
            'label' => 'Admin & Facilities — their own',
            'page'  => 'facilities.php',
            'hint'  => 'Run their own facilities: building requests, support staff and duty, housekeeping, visitors, registers, supplies and contracts.',
            'team'  => ['Admin & Facilities',
                        'Owner: runs your facilities desk as you do — requests, support staff, duty, housekeeping, visitors, registers, supplies and contracts. View only: reads it.'],
        ],
    ];
}

function fabo_module_label(string $key): string
{
    return fabo_modules()[$key]['label'] ?? $key;
}

/** 'none' | 'view' | 'edit' for a module, by the same rules as a sheet. */
function fabo_module_permission(array $viewer, string $key): string
{
    if (!isset(fabo_modules()[$key])) {
        return 'none';
    }
    if (!empty($viewer['is_admin'])) {
        return 'edit';
    }
    if (($viewer['state'] ?? 'ok') !== 'ok' || !empty($viewer['client']['is_committee'])) {
        return 'none';
    }
    /*
     * 'view' is the reading side: what we have billed them, and any filing we
     * handle on their behalf. 'edit' additionally lets them keep their own
     * books — their spend and their own filing register.
     *
     * Note what 'edit' does NOT reach: an invoice WE raised against them. A
     * client marking their own bill paid is not a permission we can offer,
     * whatever the grant says, so that stays read-only either way and the
     * page enforces it.
     */
    $p = $viewer['access'][$key] ?? 'none';
    $p = in_array($p, ['view', 'edit'], true) ? $p : 'none';
    /* Never more than the client above holds now: a team member's head, or the
       client who made this client (a client's client is its own organisation,
       but what it runs was handed down and goes when the hand-down does). */
    $parent = (int) ($viewer['client']['parent_id'] ?? 0);
    if ($p !== 'none' && $parent > 0) {
        $up = fabo_one('SELECT kind FROM clients WHERE id = ?', 'i', [$parent]);
        if ($up && $up['kind'] === 'client') {
            $p = fabo_cap_hr($p, fabo_head_module_permission($parent, $key));
        }
    }
    return $p;
}

/**
 * The HR module grant that actually applies: 'none' | 'view' | 'edit'.
 *
 * A client's head is granted HR by the administrator; their own team members
 * are granted it by the head on My Team, and never hold more than the head
 * holds NOW -- read fresh here, so taking HR from the head takes it from
 * their team at once, whatever rows were left behind. Our own staff are
 * granted it on Team Management.
 */
function fabo_hr_grant(array $viewer): string
{
    $p = fabo_module_permission($viewer, 'hr');
    $c = $viewer['client'] ?? null;
    if ($p === 'none' || !empty($viewer['is_admin']) || !$c) {
        return $p;
    }
    $parent = (int) ($c['parent_id'] ?? 0);
    if (($c['kind'] ?? 'client') === 'team' && $parent > 0) {
        return fabo_cap_hr($p, fabo_client_access($parent)['hr'] ?? 'none');
    }
    return $p;
}

/** $want, but never more than $cap. */
function fabo_cap_hr(string $want, string $cap): string
{
    $rank = ['none' => 0, 'view' => 1, 'edit' => 2];
    $want = isset($rank[$want]) ? $want : 'none';
    $cap  = isset($rank[$cap]) ? $cap : 'none';
    return $rank[$want] <= $rank[$cap] ? $want : $cap;
}

/** The modules this viewer may open at all. key => permission. */
function fabo_visible_modules(array $viewer): array
{
    $out = [];
    foreach (array_keys(fabo_modules()) as $k) {
        $p = fabo_module_permission($viewer, $k);
        if ($p !== 'none') {
            $out[$k] = $p;
        }
    }
    return $out;
}

/** Stops unless the viewer holds the module. */
function fabo_require_module(array $viewer, string $key): void
{
    if (fabo_module_permission($viewer, $key) === 'none') {
        error_log('FABO denied ' . ($viewer['who'] ?? '?') . " -> module {$key}");
        fabo_deny('You do not have access to ' . fabo_module_label($key) . '.', 403);
    }
}

/* ------------------------------------------------------ granting them */

/*
 * Sheets and modules are granted from the same form and stored in the same
 * table, so the rule about what may be handed out, and how strongly, lives
 * here rather than being spelled out again on every page that grants it.
 */

/** Everything that can be shared with a client, in the order it is offered. */
function fabo_grantable_keys(): array
{
    $out = [];
    foreach (fabo_sheet_order() as $k) {
        if (empty(fabo_sheet($k)['admin_only'])) {   // Lists never goes to a client
            $out[] = $k;
        }
    }
    return array_merge($out, array_keys(fabo_modules()));
}

/** A label for either kind of key. */
function fabo_grantable_label(string $key): string
{
    if ($key === 'incubation') {
        return 'Incubation Management';   // granted to our own staff only, on Team Management
    }
    return isset(fabo_modules()[$key]) ? fabo_module_label($key) : fabo_sheet_label($key);
}

/**
 * Read-only by nature. Only the Dashboard: it reports on other sheets and
 * has nothing of its own to change. A module can be granted either way.
 */
function fabo_grant_view_only(string $key): bool
{
    return $key === 'dashboard';
}

/** The permission actually stored for $key, or 'none' to store nothing. */
function fabo_grant_clamp(string $key, string $perm): string
{
    if (!in_array($key, fabo_grantable_keys(), true)) {
        return 'none';
    }
    if ($perm !== 'view' && $perm !== 'edit') {
        return 'none';
    }
    return fabo_grant_view_only($key) ? 'view' : $perm;
}

/* -------------------------------------------------------------- scope */

/*
 * The portal once filtered every sheet by brand and business unit, because it
 * served two brands. It now serves many clients, and a row belongs to whoever
 * the sheet was shared with — not to a brand. Brand and Business Unit remain
 * as ordinary columns on the sheets; they no longer decide who sees what.
 *
 * These two are kept so every caller keeps working, and so the rule lives in
 * one obvious place if a scope is ever wanted again.
 */

/**
 * Whose rows a viewer owns, or null when they own all of them.
 *
 * A client sees their own work and nothing else, so a client created today
 * opens every sheet with its full set of columns and no rows at all, ready to
 * fill in. Their own staff share their household, so a sub-account sees what
 * the parent account sees. The admin and our own team see everything.
 */
/**
 * Is this one of our own staff, rather than somebody inside a client?
 *
 * The distinction the portal turns on, and the one that is easy to get
 * wrong. `kind` says team or client; it does NOT say whose team. A client's
 * team member and one of ours are both kind='team' — what separates them is
 * that ours was created by us and so has no parent, while theirs points back
 * at the client who created them.
 *
 * Testing `kind === 'team'` on its own therefore reads "is staff" as "is OUR
 * staff", and hands a client's employee the run of every organisation on the
 * portal. Anything that means "may see across clients" must ask this.
 *
 * The administrator is deliberately not included: callers that also mean the
 * admin say so themselves, because several of them treat the two differently.
 */
function fabo_is_our_staff(?array $viewer): bool
{
    $c = $viewer['client'] ?? null;
    if (!$c) {
        return false;
    }
    return ($c['kind'] ?? 'client') === 'team' && (int) ($c['parent_id'] ?? 0) === 0;
}

/*
 * MODULES BELONG TO ORGANISATIONS. Every client is the head of an
 * organisation of its own -- one we made, and equally one a client made on My
 * Clients (a client's client: a separate organisation with its own data in
 * every module). A team member belongs to the client who made them. What a
 * head holds of a module is never more than every client above it holds NOW.
 * Sheets keep their own rules (fabo_viewer_org(), fabo_row_owner()).
 */

/**
 * The organisation whose module data a viewer works in: 0 for ours
 * (administrator, our own staff), a client's own id, a team member's head's
 * id; -1 for none.
 */
function fabo_module_org(?array $viewer): int
{
    if (!empty($viewer['is_admin'])) {
        return 0;
    }
    $c = $viewer['client'] ?? null;
    if (!$c || empty($c['id'])) {
        return -1;
    }
    if (fabo_is_our_staff($viewer)) {
        return 0;
    }
    if (($c['kind'] ?? '') === 'client') {
        return (int) $c['id'];
    }
    $parent = (int) ($c['parent_id'] ?? 0);
    if (($c['kind'] ?? '') !== 'team' || $parent <= 0) {
        return -1;
    }
    $head = fabo_one('SELECT id, kind FROM clients WHERE id = ?', 'i', [$parent]);
    return $head && $head['kind'] === 'client' ? (int) $head['id'] : -1;
}

/**
 * What an organisation's head holds of a module now: their own grant, capped
 * by every client above them (a client's client never holds more than the
 * client who made it). 'none' | 'view' | 'edit'.
 */
function fabo_head_module_permission(int $headId, string $key, int $depth = 0): string
{
    if ($headId <= 0 || $depth > 8) {
        return 'none';
    }
    $h = fabo_one('SELECT id, kind, parent_id, revoked_at, is_committee FROM clients WHERE id = ?', 'i', [$headId]);
    if (!$h || ($h['kind'] ?? '') !== 'client' || !empty($h['revoked_at']) || !empty($h['is_committee'])) {
        return 'none';
    }
    $p = fabo_client_access($headId)[$key] ?? 'none';
    $p = in_array($p, ['view', 'edit'], true) ? $p : 'none';
    $parent = (int) ($h['parent_id'] ?? 0);
    if ($p !== 'none' && $parent > 0) {
        $up = fabo_one('SELECT kind FROM clients WHERE id = ?', 'i', [$parent]);
        if ($up && $up['kind'] === 'client') {
            $p = fabo_cap_hr($p, fabo_head_module_permission($parent, $key, $depth + 1));
        }
    }
    return $p;
}

/** fabo_module_org(), but never -1: an account with no organisation of its own keeps the old answer (fabo_viewer_org()), never ours by accident. */
function fabo_module_org_of(?array $viewer): int
{
    $o = fabo_module_org($viewer);
    return $o >= 0 ? $o : fabo_viewer_org($viewer);
}

/** Organisations holding a module, for an administrator's switcher: id => name. Clients at any level. */
function fabo_module_orgs(string $key): array
{
    $out = [];
    foreach (fabo_all("SELECT c.id, COALESCE(NULLIF(c.organisation, ''), c.name) AS n FROM client_sheet_access a
                         JOIN clients c ON c.id = a.client_id
                        WHERE a.sheet_key = ? AND a.permission IN ('view','edit') AND c.kind = 'client' AND c.revoked_at IS NULL
                        ORDER BY n", 's', [$key]) as $r) {
        if (fabo_head_module_permission((int) $r['id'], $key) !== 'none') {
            $out[(int) $r['id']] = (string) $r['n'];
        }
    }
    return $out;
}

/** The organisation a person belongs to: their client, or themselves. */
function fabo_viewer_org(?array $viewer): int
{
    $c = $viewer['client'] ?? null;
    if (!$c || fabo_is_our_staff($viewer)) {
        return 0;
    }
    return (int) ($c['parent_id'] ?? 0) ?: (int) $c['id'];
}

function fabo_row_owner(array $viewer): ?int
{
    if (!empty($viewer['is_admin'])) {
        return null;
    }
    $c = $viewer['client'] ?? null;
    if (!$c) {
        return null;
    }
    // Only OUR OWN staff work across every client. A client's team member is
    // also kind='team', so see fabo_is_our_staff() for why the kind alone is
    // not the question.
    if (fabo_is_our_staff($viewer)) {
        return null;
    }
    // Their organisation: the client who created them, or themselves.
    return (int) ($c['parent_id'] ?? 0) ?: (int) $c['id'];
}

/** No rows are filtered by scope any more. */
function fabo_scope_where(array $viewer, string $sheetKey): array
{
    return ['sql' => '', 'types' => '', 'params' => []];
}

/** No value is out of scope any more. */
function fabo_scope_allows(array $viewer, string $sheetKey, array $row): bool
{
    return true;
}

/** The URL prefix a viewer's links carry (client links keep ?k=). */
function fabo_link(array $viewer, string $page, array $q = []): string
{
    // A signed-in portal user needs no token in the URL; a link holder does.
    if (!$viewer['is_admin'] && !fabo_signed_in()) {
        $q = array_merge(['k' => $viewer['client']['token']], $q);
    }
    return $page . ($q ? '?' . http_build_query($q) : '');
}

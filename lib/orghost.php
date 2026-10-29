<?php
/**
 * A client organisation's own web address.
 *
 * Every organisation is served by this one portal, from one folder and one
 * database. An organisation may also have an address of its own -- e.g.
 * ratan-tata-innovation-hub.mark-up.in -- pointed (in hPanel) at the same
 * folder. Nothing about the data changes; what changes is:
 *
 *   - the links the portal writes for that organisation (sheets, forms,
 *     applications, emails, background mail too) carry its address:
 *     fabo_portal_url() asks fabo_link_host();
 *   - its sign-in page carries its name and logo, and only its people sign
 *     in there;
 *   - its people who arrive at the main address (an old link, a bookmark) are
 *     carried across signed in, by a one-time hand-off -- the session cookie
 *     belongs to one address and cannot follow them on its own.
 *
 * An address is only ever used once a check has proved it reaches this
 * portal (web_host_ok_at). Saved but not yet set up in hPanel, it changes
 * nothing, so nobody is sent to an address that does not answer.
 *
 * Links shared before an organisation had its address keep working: the main
 * address still serves every page.
 */

/** The portal's own address (config.php). */
function fabo_main_host(): string
{
    return strtolower(defined('PORTAL_HOST') && PORTAL_HOST !== '' ? PORTAL_HOST : 'marketing-command-centre.mark-up.in');
}

/** The host this request came in on, lower case and without a port. */
function fabo_request_host(): string
{
    return strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
}

/**
 * What an admin typed, as a host name: "Ratan Tata Innovation Hub" or
 * "ratan-tata-innovation-hub" become ratan-tata-innovation-hub.<base>; a
 * full name with a dot ("portal.rtih.co.in") is kept as it is. '' when
 * nothing usable is left.
 */
function fabo_org_host_normalise(string $typed): string
{
    $t = strtolower(trim($typed));
    $t = preg_replace('~^https?://~', '', $t);
    $t = rtrim(explode('/', $t, 2)[0], '.');
    if (str_contains($t, '.')) {
        return preg_match('/^(?=.{4,190}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/', $t) ? $t : '';
    }
    $label = trim(preg_replace('/[^a-z0-9]+/', '-', $t), '-');
    $label = rtrim(substr($label, 0, 63), '-');
    $base = strtolower(defined('ORG_HOST_BASE') && ORG_HOST_BASE !== '' ? ORG_HOST_BASE : 'mark-up.in');
    return $label !== '' ? $label . '.' . $base : '';
}

/** Every organisation's working address: client id => host. Read once a request. */
function fabo_org_hosts(bool $fresh = false): array
{
    static $map = null;
    if ($map === null || $fresh) {
        $map = [];
        try {
            foreach (fabo_all('SELECT id, web_host FROM clients WHERE web_host IS NOT NULL AND web_host_ok_at IS NOT NULL') as $r) {
                $map[(int) $r['id']] = strtolower((string) $r['web_host']);
            }
        } catch (Throwable $e) {
            $map = [];          // before the columns exist: nobody has an address yet
        }
    }
    return $map;
}

/** The organisation (head account) a client id belongs to: itself, or the account that created it. */
function fabo_org_head(int $clientId): int
{
    static $heads = [];
    if ($clientId <= 0) {
        return 0;
    }
    if (!isset($heads[$clientId])) {
        $id = $clientId;
        for ($depth = 0; $depth < 8; $depth++) {
            $r = fabo_one('SELECT parent_id, kind FROM clients WHERE id = ?', 'i', [$id]);
            if (!$r) {
                $id = 0;
                break;
            }
            if ((int) ($r['parent_id'] ?? 0) === 0) {
                // Our own staff (a team account nobody created) belong to no organisation.
                $id = ($r['kind'] ?? 'client') === 'team' ? 0 : $id;
                break;
            }
            $id = (int) $r['parent_id'];
        }
        $heads[$clientId] = $id;
    }
    return $heads[$clientId];
}

/** An organisation's working address, or ''. Any of its people's ids will do. */
function fabo_org_host(int $clientId): string
{
    $map = fabo_org_hosts();
    if (!$map || $clientId <= 0) {
        return '';
    }
    return $map[$clientId] ?? ($map[fabo_org_head($clientId)] ?? '');
}

/**
 * The organisation the links of this request are for -- set by whoever
 * knows: the signed-in person, a link's own token, Incubation Management's
 * organisation (cron too). 0: ours, the main address.
 */
function fabo_link_org(?int $set = null): int
{
    static $org = 0;
    if ($set !== null) {
        $org = max(0, $set);
    }
    return $org;
}

/** The host links should carry right now, or '' for "the address this request came in on". */
function fabo_link_host(): string
{
    $org = fabo_link_org();
    return $org > 0 ? fabo_org_host($org) : '';
}

/** The organisation whose own address this request came in on, or 0. */
function fabo_request_org(): int
{
    $host = fabo_request_host();
    if ($host === '' || $host === fabo_main_host()) {
        return 0;
    }
    $id = array_search($host, fabo_org_hosts(), true);
    return $id === false ? 0 : (int) $id;
}

/* ------------------------------------------------------------- set-up */

/**
 * Saves an organisation's address and checks it at once. Typing nothing
 * removes it. Returns [ok, message].
 */
function fabo_org_host_save(int $orgId, string $typed): array
{
    $typed = trim($typed);
    if ($typed === '') {
        fabo_exec('UPDATE clients SET web_host = NULL, web_host_ok_at = NULL, web_host_nonce = NULL WHERE id = ?', 'i', [$orgId]);
        fabo_org_hosts(true);
        return [true, 'Their own address is removed. Their links use the main address again.'];
    }
    $host = fabo_org_host_normalise($typed);
    if ($host === '') {
        return [false, 'That is not a usable web address. Use letters, numbers and dashes, e.g. ratan-tata-innovation-hub.'];
    }
    if ($host === fabo_main_host()) {
        return [false, 'That is the portal\'s own address.'];
    }
    $taken = fabo_one('SELECT id FROM clients WHERE web_host = ? AND id <> ?', 'si', [$host, $orgId]);
    if ($taken) {
        return [false, $host . ' already belongs to another organisation.'];
    }
    $was = (string) (fabo_one('SELECT web_host FROM clients WHERE id = ?', 'i', [$orgId])['web_host'] ?? '');
    if ($was !== $host) {
        fabo_exec('UPDATE clients SET web_host = ?, web_host_ok_at = NULL WHERE id = ?', 'si', [$host, $orgId]);
    }
    return fabo_org_host_check($orgId);
}

/**
 * Does the organisation's address reach this portal, over HTTPS? It asks
 * orghost-check.php there for a one-off number only this database knows, so
 * an address pointed somewhere else -- or not yet created -- fails. The
 * address is used from the moment this passes, and not before.
 */
function fabo_org_host_check(int $orgId): array
{
    $row = fabo_one('SELECT web_host FROM clients WHERE id = ?', 'i', [$orgId]);
    $host = (string) ($row['web_host'] ?? '');
    if ($host === '') {
        return [false, 'No address is saved for them.'];
    }
    $nonce = bin2hex(random_bytes(16));
    fabo_exec('UPDATE clients SET web_host_nonce = ? WHERE id = ?', 'si', [$nonce, $orgId]);
    $ch = curl_init('https://' . $host . '/orghost-check.php?n=' . $nonce);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8,
                            CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
    $body = (string) curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fabo_exec('UPDATE clients SET web_host_nonce = NULL WHERE id = ?', 'i', [$orgId]);
    if ($code === 200 && trim($body) === 'fabo-orghost-ok') {
        fabo_exec('UPDATE clients SET web_host_ok_at = COALESCE(web_host_ok_at, NOW()) WHERE id = ?', 'i', [$orgId]);
        fabo_org_hosts(true);
        return [true, 'https://' . $host . ' is working. Their links, emails and sign-in now use it.'];
    }
    fabo_exec('UPDATE clients SET web_host_ok_at = NULL WHERE id = ?', 'i', [$orgId]);
    fabo_org_hosts(true);
    $why = $err !== '' ? (stripos($err, 'resolve') !== false ? 'the address does not exist yet'
                         : (stripos($err, 'SSL') !== false || stripos($err, 'certificate') !== false ? 'its SSL certificate is not ready yet' : $err))
         : 'it answered, but not as this portal (HTTP ' . $code . ')';
    return [false, 'Saved as https://' . $host . ', but not in use yet: ' . $why . '. Create it in hPanel (Domains → Subdomains, pointing to the portal\'s folder), wait for its SSL, then press "Check again". Until then their links keep using the main address.'];
}

/**
 * A new organisation gets its address on its own: made from its organisation
 * name (else the client's name), with -2, -3... when another has it, and
 * checked at once. With the catch-all subdomain set up in hPanel it works
 * straight away; without it, it waits, and fabo_org_hosts_recheck() switches
 * it on when it starts to answer. Returns the address saved, or ''.
 */
function fabo_org_host_assign(int $orgId): string
{
    $c = fabo_one('SELECT name, organisation, web_host FROM clients WHERE id = ?', 'i', [$orgId]);
    if (!$c || (string) ($c['web_host'] ?? '') !== '') {
        return (string) ($c['web_host'] ?? '');
    }
    $host = fabo_org_host_normalise(trim((string) $c['organisation']) ?: (string) $c['name']);
    if ($host === '' || !str_contains($host, '.')) {
        return '';
    }
    [$label, $rest] = explode('.', $host, 2);
    for ($n = 1; $n < 50; $n++) {
        $try = ($n === 1 ? $label : substr($label, 0, 60) . '-' . $n) . '.' . $rest;
        if ($try !== fabo_main_host() && !fabo_one('SELECT id FROM clients WHERE web_host = ?', 's', [$try])) {
            fabo_exec('UPDATE clients SET web_host = ?, web_host_ok_at = NULL WHERE id = ?', 'si', [$try, $orgId]);
            fabo_org_host_check($orgId);
            return $try;
        }
    }
    return '';
}

/**
 * Checks every saved address that is not working yet, and switches on the
 * ones that now are. Run by cron.php, and when the admin opens such a client.
 * Returns how many were switched on.
 */
function fabo_org_hosts_recheck(): int
{
    $on = 0;
    foreach (fabo_all('SELECT id FROM clients WHERE web_host IS NOT NULL AND web_host_ok_at IS NULL') as $r) {
        $on += fabo_org_host_check((int) $r['id'])[0] ? 1 : 0;
    }
    return $on;
}

/* ------------------------------------------------------------ hand-off */

function fabo_handoff_table(): void
{
    static $done = false;
    if (!$done) {
        fabo_db()->query('CREATE TABLE IF NOT EXISTS `host_handoffs` (
  `token_hash` CHAR(64) NOT NULL,
  `person_id` INT UNSIGNED NOT NULL,
  `host` VARCHAR(190) NOT NULL,
  `next` VARCHAR(300) NOT NULL DEFAULT \'\',
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME NULL,
  PRIMARY KEY (`token_hash`),
  KEY `k_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $done = true;
    }
}

/** The address a person should be working on: their organisation's own, else the main one. */
function fabo_person_host(array $person): string
{
    $org = fabo_org_head((int) $person['id']);
    return ($org > 0 ? fabo_org_host($org) : '') ?: fabo_main_host();
}

/**
 * A one-time link that signs $person in on $host and opens $next there.
 * Good for two minutes, once, on that host only.
 */
function fabo_handoff_url(array $person, string $host, string $next): string
{
    fabo_handoff_table();
    fabo_exec('DELETE FROM host_handoffs WHERE expires_at < NOW() - INTERVAL 1 DAY');
    $token = bin2hex(random_bytes(32));
    fabo_exec('INSERT INTO host_handoffs (token_hash, person_id, host, next, expires_at) VALUES (?,?,?,?, NOW() + INTERVAL 2 MINUTE)',
              'siss', [hash('sha256', $token), (int) $person['id'], $host, fabo_safe_next($next)]);
    return 'https://' . $host . '/handoff.php?t=' . $token;
}

/** Takes a hand-off token on this host: [person, next], or null. Each token works once. */
function fabo_handoff_take(string $token): ?array
{
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
        return null;
    }
    fabo_handoff_table();
    $hash = hash('sha256', $token);
    $h = fabo_one('SELECT * FROM host_handoffs WHERE token_hash = ? AND used_at IS NULL AND expires_at >= NOW()', 's', [$hash]);
    if (!$h || $h['host'] !== fabo_request_host()) {
        return null;
    }
    // Claimed in one statement, so two requests with the same token cannot both get in.
    if (fabo_exec('UPDATE host_handoffs SET used_at = NOW() WHERE token_hash = ? AND used_at IS NULL', 's', [$hash]) < 1) {
        return null;
    }
    $p = fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [(int) $h['person_id']]);
    if (!$p || fabo_effective_state($p) !== 'ok' || fabo_person_host($p) !== $h['host']) {
        return null;
    }
    return [$p, (string) $h['next']];
}

/**
 * A signed-in person on the wrong address -- one of ours (the main address
 * or another organisation's) that is not theirs -- is carried to their own,
 * still signed in, on the same page. Only for a page being opened in the
 * browser (never a form post, a download or a background request), and never
 * on an address that is not ours (e.g. a test machine).
 */
function fabo_org_host_steer(array $person): void
{
    if (PHP_SAPI === 'cli' || headers_sent() || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        return;
    }
    $mode = (string) ($_SERVER['HTTP_SEC_FETCH_MODE'] ?? '');
    if ($mode !== '' ? $mode !== 'navigate' : !str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/html')) {
        return;
    }
    if (in_array(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')), ['handoff.php', 'logout.php', 'orghost-check.php'], true)) {
        return;
    }
    $here = fabo_request_host();
    if ($here !== fabo_main_host() && fabo_request_org() === 0) {
        return;                                             // not one of our addresses
    }
    $want = fabo_person_host($person);
    if ($want === $here) {
        return;
    }
    $next = ltrim((string) ($_SERVER['REQUEST_URI'] ?? ''), '/');
    header('Location: ' . fabo_handoff_url($person, $want, $next), true, 302);
    exit;
}

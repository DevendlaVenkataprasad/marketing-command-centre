<?php
/**
 * Is this address safe for the server to fetch?
 *
 * The crawler is handed a URL by whoever is using the portal and then asks
 * the SERVER to fetch it. That is a different thing from a browser fetching
 * it: the server sits inside the hosting network, behind whatever the
 * firewall lets through, and it is trusted by things a stranger is not.
 *
 * So "https://example.com" and "http://127.0.0.1:3306" are not two points on
 * one scale. The second is somebody using this portal as a way to knock on
 * doors only the server can reach — the database, an admin panel bound to
 * localhost, another site on the same machine, or the cloud metadata service
 * at 169.254.169.254, which on a badly configured host hands out credentials
 * to anything that asks.
 *
 * Everything here is a deny list applied to the RESOLVED ADDRESS, never to
 * the name. A name is a promise the DNS server makes and can break: it can
 * point at a public address now and 127.0.0.1 on the next lookup, which is
 * why fabo_url_open() resolves once, checks what it got, and then connects
 * to that address rather than asking again.
 */

/** Only these two. Not file://, gopher://, ftp://, dict://, php:// or data:. */
const FABO_FETCH_SCHEMES = ['http', 'https'];

/** Only the web's own ports: a crawler has no business on 22 or 3306. */
const FABO_FETCH_PORTS = [80, 443];

/** How long any single fetch may take, and how much it may bring back. */
const FABO_FETCH_TIMEOUT = 15;
const FABO_FETCH_MAX_BYTES = 2097152;      // 2 MB of HTML is already absurd
const FABO_FETCH_MAX_REDIRECTS = 4;

/**
 * The address ranges the server must never be talked into visiting.
 *
 * Written as CIDR and checked numerically, because the textual forms of an
 * address are many and a check that compares strings will be walked around
 * by the first person who tries "0177.0.0.1" or "::ffff:127.0.0.1".
 */
function fabo_blocked_ranges(): array
{
    return [
        // IPv4
        '0.0.0.0/8',          // "this network" — 0.0.0.0 reaches localhost on Linux
        '10.0.0.0/8',         // private
        '100.64.0.0/10',      // carrier-grade NAT
        '127.0.0.0/8',        // loopback
        '169.254.0.0/16',     // link-local, and the cloud metadata service
        '172.16.0.0/12',      // private
        '192.0.0.0/24',       // IETF protocol assignments
        '192.0.2.0/24',       // documentation
        '192.168.0.0/16',     // private
        '198.18.0.0/15',      // benchmarking
        '198.51.100.0/24',    // documentation
        '203.0.113.0/24',     // documentation
        '224.0.0.0/4',        // multicast
        '240.0.0.0/4',        // reserved, includes 255.255.255.255
        // IPv6
        '::/128',             // unspecified
        '::1/128',            // loopback
        '::ffff:0:0/96',      // IPv4-mapped — the way round an IPv4-only check
        '64:ff9b::/96',       // NAT64
        '100::/64',           // discard
        '2001:db8::/32',      // documentation
        'fc00::/7',           // unique local
        'fe80::/10',          // link-local
        'ff00::/8',           // multicast
    ];
}

/** Is a literal address inside a CIDR block? */
function fabo_ip_in_range(string $ip, string $cidr): bool
{
    [$net, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
    $ipBin  = @inet_pton($ip);
    $netBin = @inet_pton($net);
    if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
        return false;                       // different families never match
    }
    $bits = $bits === null ? strlen($ipBin) * 8 : (int) $bits;
    $whole = intdiv($bits, 8);
    $rest  = $bits % 8;

    if ($whole > 0 && substr($ipBin, 0, $whole) !== substr($netBin, 0, $whole)) {
        return false;
    }
    if ($rest === 0) {
        return true;
    }
    $mask = chr((0xFF << (8 - $rest)) & 0xFF);
    return (($ipBin[$whole] ?? "\0") & $mask) === (($netBin[$whole] ?? "\0") & $mask);
}

/** Is this literal address one the server may open a connection to? */
function fabo_ip_allowed(string $ip): bool
{
    if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return false;
    }
    foreach (fabo_blocked_ranges() as $cidr) {
        if (fabo_ip_in_range($ip, $cidr)) {
            return false;
        }
    }
    return true;
}

/**
 * Every address a host name resolves to.
 *
 * Every one of them is checked, not just the first: a name that answers with
 * a public address and a loopback address is a name that will eventually
 * hand out the loopback one.
 */
function fabo_resolve_all(string $host): array
{
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        return [$host];                     // already an address
    }
    $out = [];
    foreach (['A' => DNS_A, 'AAAA' => DNS_AAAA] as $field => $type) {
        $recs = @dns_get_record($host, $type);
        foreach ($recs ?: [] as $r) {
            $ip = $r['ip'] ?? $r['ipv6'] ?? '';
            if ($ip !== '') {
                $out[] = $ip;
            }
        }
    }
    if (!$out) {
        $one = @gethostbyname($host);       // last resort, IPv4 only
        if ($one !== '' && $one !== $host) {
            $out[] = $one;
        }
    }
    return array_values(array_unique($out));
}

/**
 * May the server fetch this URL? Returns [ok, reason, parts].
 *
 * `parts` carries the scheme, host, port, path and the address that was
 * resolved and accepted, so the caller can connect to THAT rather than
 * resolving the name a second time and possibly getting a different answer.
 */
function fabo_url_safe(string $url): array
{
    $url = trim($url);
    if ($url === '') {
        return [false, 'No address given.', []];
    }
    if (strlen($url) > 2048) {
        return [false, 'That address is too long.', []];
    }
    $p = @parse_url($url);
    if (!is_array($p) || empty($p['host'])) {
        return [false, 'That does not look like a web address.', []];
    }
    $scheme = strtolower((string) ($p['scheme'] ?? ''));
    if (!in_array($scheme, FABO_FETCH_SCHEMES, true)) {
        return [false, 'Only http and https addresses can be fetched.', []];
    }
    if (isset($p['user']) || isset($p['pass'])) {
        /* http://real.example.com@127.0.0.1/ reads as one host to a person
           and another to a parser. Refused rather than guessed at. */
        return [false, 'An address with a username in it is not fetched.', []];
    }
    $port = (int) ($p['port'] ?? ($scheme === 'https' ? 443 : 80));
    if (!in_array($port, FABO_FETCH_PORTS, true)) {
        return [false, 'Only the standard web ports are fetched.', []];
    }
    $host = strtolower(trim((string) $p['host'], '[]'));
    if ($host === '' || str_ends_with($host, '.localhost') || $host === 'localhost') {
        return [false, 'That address points back at the server.', []];
    }

    $ips = fabo_resolve_all($host);
    if (!$ips) {
        return [false, 'That address could not be looked up.', []];
    }
    foreach ($ips as $ip) {
        if (!fabo_ip_allowed($ip)) {
            /* Deliberately not saying which address it resolved to: that
               answer is a map of the inside of the network. */
            return [false, 'That address points somewhere private.', []];
        }
    }

    return [true, '', [
        'scheme' => $scheme,
        'host'   => $host,
        'port'   => $port,
        'ip'     => $ips[0],
        'path'   => (string) ($p['path'] ?? '/'),
        'query'  => (string) ($p['query'] ?? ''),
        'url'    => $url,
    ]];
}

/**
 * Fetch a URL, checking safety at every hop. Returns [ok, body, meta].
 *
 * Redirects are followed by hand rather than by the HTTP layer, because a
 * redirect is a second URL chosen by somebody else: the first address can be
 * a perfectly ordinary public page that answers "302 → http://169.254.169.254".
 * Every hop goes back through fabo_url_safe().
 */
function fabo_url_open(string $url, int $hops = 0): array
{
    $meta = ['url' => $url, 'status' => 0, 'type' => '', 'final' => $url, 'hops' => $hops];

    if ($hops > FABO_FETCH_MAX_REDIRECTS) {
        return [false, '', $meta + ['error' => 'Too many redirects.']];
    }
    [$ok, $why, $parts] = fabo_url_safe($url);
    if (!$ok) {
        return [false, '', $meta + ['error' => $why]];
    }

    /* Connect to the address that was CHECKED. Letting the HTTP layer look the
       name up again is the DNS-rebinding hole: a hostile name server answers
       with a public address for the check and 127.0.0.1 for the connection. */
    if (function_exists('curl_init')) {
        return fabo_url_open_pinned($url, $parts, $hops, $meta);
    }
    if ($parts['scheme'] === 'https') {
        // Without cURL there is no way to pin an https connection to an address.
        return [false, '', $meta + ['error' => 'Secure fetching needs the PHP cURL extension on this server.']];
    }
    // Plain http: ask the checked address directly, naming the site in Host.
    $ipHost = str_contains($parts['ip'], ':') ? '[' . $parts['ip'] . ']' : $parts['ip'];
    $pinned = 'http://' . $ipHost . ':' . $parts['port'] . $parts['path']
            . ($parts['query'] !== '' ? '?' . $parts['query'] : '');

    $ctx = stream_context_create(['http' => [
        'method'           => 'GET',
        'follow_location'  => 0,             // checked by hand, see above
        'ignore_errors'    => true,
        'timeout'          => FABO_FETCH_TIMEOUT,
        'protocol_version' => 1.1,
        /* Browser-shaped, because many firewalls turn away anything that
           does not look like one; it still names itself. */
        'header'           => "Host: " . $parts['host'] . "\r\n"
                            . "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
                            . "(KHTML, like Gecko) Chrome/124.0 Safari/537.36 MarketingCommandCentre/1.0\r\n"
                            . "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8\r\n"
                            . "Accept-Language: en\r\n"
                            . "Connection: close\r\n",
    ]]);

    $fh = @fopen($pinned, 'rb', false, $ctx);
    if (!$fh) {
        return [false, '', $meta + ['error' => 'Nothing answered at that address.']];
    }

    $status = 0;
    $type    = '';
    $location = '';
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('~^HTTP/\S+\s+(\d{3})~', $h, $m)) {
            $status = (int) $m[1];           // last wins, which is the final hop
        }
        if (stripos($h, 'Content-Type:') === 0) {
            $type = trim(substr($h, 13));
        }
        if (stripos($h, 'Location:') === 0) {
            $location = trim(substr($h, 9));
        }
    }
    $meta['status'] = $status;
    $meta['type']   = $type;

    if ($status >= 300 && $status < 400 && $location !== '') {
        fclose($fh);
        /* A relative Location is resolved against the address we asked for,
           and then checked from scratch like any other. */
        $next = fabo_url_absolute($location, $url);
        return fabo_url_open($next, $hops + 1);
    }

    /* Read with a ceiling. A stream that never ends is a way to fill the
       disk or the memory limit, and neither needs to be reachable from a
       text box. */
    $body = '';
    while (!feof($fh) && strlen($body) < FABO_FETCH_MAX_BYTES) {
        $chunk = fread($fh, 65536);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $body .= $chunk;
    }
    fclose($fh);
    $body = substr($body, 0, FABO_FETCH_MAX_BYTES);

    $meta['final'] = $url;
    $meta['bytes'] = strlen($body);
    return [$status >= 200 && $status < 300, $body, $meta];
}

/**
 * The cURL fetch behind fabo_url_open(): the connection is pinned to the
 * address fabo_url_safe() checked (CURLOPT_RESOLVE), only http and https are
 * spoken, redirects are never followed here (the caller re-checks each hop),
 * and the body is cut off at the size limit while it streams in.
 */
function fabo_url_open_pinned(string $url, array $parts, int $hops, array $meta): array
{
    $ch = curl_init($url);
    $body = '';
    $headers = [];
    $ip = str_contains($parts['ip'], ':') ? '[' . $parts['ip'] . ']' : $parts['ip'];
    curl_setopt_array($ch, [
        CURLOPT_RESOLVE         => [$parts['host'] . ':' . $parts['port'] . ':' . $ip],
        CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_FOLLOWLOCATION  => false,
        CURLOPT_CONNECTTIMEOUT  => FABO_FETCH_TIMEOUT,
        CURLOPT_TIMEOUT         => FABO_FETCH_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER  => true,
        CURLOPT_SSL_VERIFYHOST  => 2,
        CURLOPT_HTTPHEADER      => [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) '
            . 'Chrome/124.0 Safari/537.36 MarketingCommandCentre/1.0',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en',
        ],
        CURLOPT_HEADERFUNCTION  => static function ($c, string $line) use (&$headers): int {
            $headers[] = trim($line);
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION   => static function ($c, string $chunk) use (&$body): int {
            $room = FABO_FETCH_MAX_BYTES - strlen($body);
            if ($room <= 0) {
                return 0;                    // stops the transfer: the ceiling is reached
            }
            $body .= substr($chunk, 0, $room);
            return strlen($chunk) <= $room ? strlen($chunk) : 0;
        },
    ]);
    $ran    = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $type   = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $err    = curl_errno($ch);
    curl_close($ch);

    // A transfer cut short at the size ceiling is still a good answer.
    if ($ran === false && $err !== CURLE_WRITE_ERROR) {
        return [false, '', $meta + ['error' => 'Nothing answered at that address.']];
    }
    $meta['status'] = $status;
    $meta['type']   = $type;

    if ($status >= 300 && $status < 400) {
        $location = '';
        foreach ($headers as $h) {
            if (stripos($h, 'Location:') === 0) {
                $location = trim(substr($h, 9));
            }
        }
        if ($location !== '') {
            return fabo_url_open(fabo_url_absolute($location, $url), $hops + 1);
        }
    }
    $meta['final'] = $url;
    $meta['bytes'] = strlen($body);
    return [$status >= 200 && $status < 300, $body, $meta];
}

/** A possibly-relative href, resolved against the page it was found on. */
function fabo_url_absolute(string $href, string $base): string
{
    $href = trim($href);
    if ($href === '' || str_starts_with($href, '#')) {
        return '';
    }
    if (preg_match('~^[a-zA-Z][a-zA-Z0-9+.-]*:~', $href)) {
        return $href;                       // already absolute, scheme and all
    }
    $b = @parse_url($base);
    if (!is_array($b) || empty($b['host'])) {
        return '';
    }
    $root = ($b['scheme'] ?? 'https') . '://' . $b['host']
          . (isset($b['port']) ? ':' . $b['port'] : '');

    if (str_starts_with($href, '//')) {
        return ($b['scheme'] ?? 'https') . ':' . $href;
    }
    if (str_starts_with($href, '/')) {
        return $root . $href;
    }
    $dir = (string) ($b['path'] ?? '/');
    $dir = substr($dir, 0, strrpos($dir, '/') !== false ? strrpos($dir, '/') + 1 : 0);
    if ($dir === '') {
        $dir = '/';
    }
    /* Flatten ../ and ./ so two spellings of one page are one URL. */
    $path  = $dir . $href;
    $parts = [];
    foreach (explode('/', $path) as $seg) {
        if ($seg === '.' || $seg === '') {
            continue;
        }
        if ($seg === '..') {
            array_pop($parts);
            continue;
        }
        $parts[] = $seg;
    }
    return $root . '/' . implode('/', $parts);
}

/**
 * One spelling per page.
 *
 * A crawler that treats "/about", "/about/", "/about#team" and
 * "/about?utm_source=x" as four pages fetches the same words four times and
 * then asks the AI to write four posts about them.
 */
function fabo_url_normalise(string $url): string
{
    $p = @parse_url(trim($url));
    if (!is_array($p) || empty($p['host'])) {
        return '';
    }
    $scheme = strtolower((string) ($p['scheme'] ?? 'https'));
    $host   = strtolower((string) $p['host']);
    $port   = isset($p['port']) && !in_array((int) $p['port'], [80, 443], true)
            ? ':' . (int) $p['port'] : '';
    $path   = (string) ($p['path'] ?? '/');
    if ($path === '') {
        $path = '/';
    }
    if ($path !== '/' && str_ends_with($path, '/')) {
        $path = rtrim($path, '/');          // /about/ and /about are one page
    }

    /* Tracking parameters describe where a visitor came from, not which page
       they landed on, so they are not part of the page's identity. */
    $drop = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
             'gclid', 'fbclid', 'msclkid', 'mc_cid', 'mc_eid', 'ref', 'referrer',
             '_ga', 'igshid', 'si'];
    $query = '';
    if (!empty($p['query'])) {
        parse_str($p['query'], $q);
        foreach ($drop as $d) {
            unset($q[$d]);
        }
        ksort($q);                          // ?b=2&a=1 and ?a=1&b=2 are one page
        $query = $q ? '?' . http_build_query($q) : '';
    }
    return $scheme . '://' . $host . $port . $path . $query;   // the fragment is dropped
}

/** Is this URL on the site we were asked to crawl? */
function fabo_url_same_site(string $url, string $root): bool
{
    $a = @parse_url($url);
    $b = @parse_url($root);
    if (!is_array($a) || !is_array($b) || empty($a['host']) || empty($b['host'])) {
        return false;
    }
    $ah = strtolower($a['host']);
    $bh = strtolower($b['host']);
    if ($ah === $bh) {
        return true;
    }
    /* www.example.com and example.com are the same site; blog.example.com is
       not, and is left alone unless somebody asks for it by name. */
    return ltrim($ah, 'w.') === ltrim($bh, 'w.')
        && (str_starts_with($ah, 'www.') || str_starts_with($bh, 'www.'));
}

<?php
/**
 * Digital Signatures — the verification engine.
 *
 * Given the bytes of a PDF, finds every signature in it and checks each one
 * the way a relying party must:
 *
 *   integrity      the CMS signature verifies over exactly the bytes its
 *                  /ByteRange names (OpenSSL does the cryptography);
 *   modifications  nothing but signatures, form fields and annotations was
 *                  added after it (pages, page content, the catalogue are
 *                  compared object by object against the signed revision);
 *   identity       the signer's certificate chains, signature by signature,
 *                  to a certificate in the trust store (CCA India roots and
 *                  whatever the administrator adds);
 *   time           the certificates were valid when it was signed: at the
 *                  time of a verified RFC 3161 timestamp, or — with no
 *                  timestamp — now, as ETSI EN 319 102-1 requires;
 *   revocation     OCSP first, then the CRL, each response's own signature
 *                  checked. A check that cannot be made is "unavailable"
 *                  and never counts as a pass.
 *
 * Verdicts, worst first: invalid, modified, revoked, expired, incomplete,
 * valid. "Incomplete" means the signature itself is sound but something a
 * legal reliance needs could not be established (an untrusted root, an
 * unreachable revocation service).
 */

require_once __DIR__ . '/asn1.php';
require_once __DIR__ . '/pdf.php';

function ds_verdicts(): array
{
    return [
        'valid'      => ['Valid signature', 'green'],
        'incomplete' => ['Verification incomplete', 'amber'],
        'expired'    => ['Certificate expired', 'amber'],
        'revoked'    => ['Certificate revoked', 'red'],
        'modified'   => ['Document modified after signing', 'red'],
        'invalid'    => ['Invalid signature', 'red'],
        'unsigned'   => ['No digital signature', ''],
    ];
}

function ds_verdict_rank(string $v): int
{
    return ['valid' => 0, 'unsigned' => 1, 'incomplete' => 2, 'expired' => 3, 'revoked' => 4, 'modified' => 5, 'invalid' => 6][$v] ?? 6;
}

/* ============================================================== finding */

/**
 * Every signature dictionary in a PDF: its /ByteRange, the DER it holds and
 * what the dictionary says about itself. Found by the byte ranges
 * themselves, so a signature is found however the form around it is built.
 */
function ds_pdf_signatures(string $pdf): array
{
    $out = [];
    $len = strlen($pdf);
    if (!preg_match_all('/\/ByteRange\s*\[\s*(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s*\]/', $pdf, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        return [];
    }
    $seen = [];
    foreach ($m as $hit) {
        $r = [(int) $hit[1][0], (int) $hit[2][0], (int) $hit[3][0], (int) $hit[4][0]];
        $key = implode(',', $r);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $sig = ['range' => $r, 'der' => null, 'dict' => [], 'problem' => null, 'at' => $hit[0][1]];
        if ($r[0] !== 0 || $r[1] <= 0 || $r[2] <= $r[1] || $r[2] + $r[3] > $len || $r[3] < 0) {
            $sig['problem'] = 'The signature\'s byte range is malformed or points outside the file.';
        } elseif ($pdf[$r[1]] !== '<' || $pdf[$r[2] - 1] !== '>') {
            $sig['problem'] = 'The signature\'s byte range does not exclude exactly its own signature value.';
        } else {
            $hex = preg_replace('/[^0-9A-Fa-f]/', '', substr($pdf, $r[1] + 1, $r[2] - $r[1] - 2));
            $bin = (string) hex2bin(strlen($hex) % 2 ? $hex . '0' : $hex);
            try {
                $node = ds_der_node($bin);
                $sig['der'] = ds_der_raw($bin, $node);
            } catch (Throwable $e) {
                $sig['problem'] = trim($bin, "\0") === '' ? 'This signature field was prepared but never signed.' : 'The signature value is not valid DER.';
            }
        }
        // The dictionary around it: the nearest "n g obj" before the /ByteRange.
        $start = max(0, $hit[0][1] - 4096);
        if (preg_match_all('/(\d+)\s+(\d+)\s+obj\b/', substr($pdf, $start, $hit[0][1] - $start), $om, PREG_OFFSET_CAPTURE)) {
            $objOff = $start + end($om[0])[1];
            try {
                [, , $d] = DsPdf::over($pdf)->objectAt($objOff);
                if ($d instanceof DsDict) {
                    $txt = static fn($v) => $v instanceof DsStr ? ds_pdf_text($v->s) : ($v instanceof DsName ? $v->n : null);
                    foreach (['Type', 'Filter', 'SubFilter', 'Name', 'Reason', 'Location', 'ContactInfo', 'M'] as $k) {
                        $sig['dict'][$k] = $txt($d->get($k));
                    }
                    $ref = $d->get('Reference');
                    if (is_array($ref)) {
                        foreach ($ref as $sr) {
                            if ($sr instanceof DsDict && $sr->name('TransformMethod') === 'DocMDP') {
                                $tp = $sr->get('TransformParams');
                                $sig['dict']['DocMDP'] = $tp instanceof DsDict ? (int) ($tp->get('P') ?? 2) : 2;
                            }
                        }
                    }
                }
            } catch (Throwable $e) {
            }
        }
        $out[] = $sig;
    }
    usort($out, static fn($a, $b) => ($a['range'][2] + $a['range'][3]) <=> ($b['range'][2] + $b['range'][3]));
    return $out;
}

/** PDF text string → UTF-8 (UTF-16BE with BOM, else PDFDocEncoding ~ Latin-1). */
function ds_pdf_text(string $s): string
{
    if (str_starts_with($s, "\xFE\xFF")) {
        return (string) @mb_convert_encoding(substr($s, 2), 'UTF-8', 'UTF-16BE');
    }
    if (str_starts_with($s, "\xEF\xBB\xBF")) {
        return substr($s, 3);
    }
    return (string) @mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
}

/** /M "D:20261102143000+05'30'" → Unix time, or null. */
function ds_pdf_date(?string $s): ?int
{
    if (!$s || !preg_match("/^D:(\d{4})(\d{2})?(\d{2})?(\d{2})?(\d{2})?(\d{2})?([Zz+-])?(\d{2})?'?(\d{2})?/", $s, $m)) {
        return null;
    }
    $t = gmmktime((int) ($m[4] ?? 0), (int) ($m[5] ?? 0), (int) ($m[6] ?? 0), (int) ($m[2] ?: 1), (int) ($m[3] ?: 1), (int) $m[1]);
    if (!empty($m[7]) && in_array($m[7], ['+', '-'], true)) {
        $off = (int) ($m[8] ?? 0) * 3600 + (int) ($m[9] ?? 0) * 60;
        $t += $m[7] === '+' ? -$off : $off;
    }
    return $t;
}

/* ========================================================= certificates */

/** What a person needs to see of a certificate. */
function ds_cert_summary(string $pemOrDer): ?array
{
    $pem = str_contains($pemOrDer, '-----BEGIN') ? $pemOrDer : ds_pem($pemOrDer);
    $x = @openssl_x509_parse($pem);
    if (!$x) {
        return null;
    }
    $s = $x['subject'] ?? [];
    $i = $x['issuer'] ?? [];
    $one = static fn($v) => is_array($v) ? (string) end($v) : (string) ($v ?? '');
    $email = $one($s['emailAddress'] ?? '');
    $san = (string) ($x['extensions']['subjectAltName'] ?? '');
    if ($email === '' && preg_match('/email:([^,\s]+)/i', $san, $mm)) {
        $email = $mm[1];
    }
    $ku = (string) ($x['extensions']['keyUsage'] ?? '');
    return [
        'cn' => $one($s['CN'] ?? ''), 'email' => $email, 'org' => $one($s['O'] ?? ''), 'ou' => $one($s['OU'] ?? ''),
        'state' => $one($s['ST'] ?? ''), 'country' => $one($s['C'] ?? ''), 'pseudonym' => $one($s['pseudonym'] ?? ''),
        'serial' => strtoupper((string) ($x['serialNumberHex'] ?? dechex((int) ($x['serialNumber'] ?? 0)))),
        'issuer_cn' => $one($i['CN'] ?? ''), 'issuer_org' => $one($i['O'] ?? ''),
        'subject_dn' => ds_dn_text($s), 'issuer_dn' => ds_dn_text($i),
        'not_before' => (int) $x['validFrom_time_t'], 'not_after' => (int) $x['validTo_time_t'],
        'fingerprint' => strtoupper((string) openssl_x509_fingerprint($pem, 'sha256')),
        'key_usage' => $ku, 'ext_key_usage' => (string) ($x['extensions']['extendedKeyUsage'] ?? ''),
        'policies' => (string) ($x['extensions']['certificatePolicies'] ?? ''),
        'is_ca' => str_contains((string) ($x['extensions']['basicConstraints'] ?? ''), 'CA:TRUE'),
        'self_signed' => ds_dn_text($s) === ds_dn_text($i),
        'ocsp' => preg_match_all('/OCSP - URI:(\S+)/', (string) ($x['extensions']['authorityInfoAccess'] ?? ''), $o) ? $o[1] : [],
        'ca_issuers' => preg_match_all('/CA Issuers - URI:(\S+)/', (string) ($x['extensions']['authorityInfoAccess'] ?? ''), $c) ? $c[1] : [],
        'crl' => preg_match_all('/URI:(\S+)/', (string) ($x['extensions']['crlDistributionPoints'] ?? ''), $cr) ? $cr[1] : [],
        'pem' => $pem,
    ];
}

function ds_dn_text(array $dn): string
{
    $parts = [];
    foreach ($dn as $k => $v) {
        foreach ((array) $v as $vv) {
            $parts[] = $k . '=' . $vv;
        }
    }
    return implode(', ', $parts);
}

/** May this key sign documents? (Key usage, when stated, must allow it.) */
function ds_cert_can_sign(array $c): bool
{
    return $c['key_usage'] === '' || str_contains($c['key_usage'], 'Digital Signature') || str_contains($c['key_usage'], 'Non Repudiation');
}

/**
 * Builds and checks the path from a certificate to a trust anchor. Each link
 * is a real signature check (openssl_x509_verify); issuers must be CAs; every
 * certificate must be valid at $at.
 *
 * Returns status trusted | untrusted | broken, the path (summaries, leaf
 * first), the anchor's name, and whether any link was out of date at $at.
 */
function ds_chain(string $leafPem, array $pool, array $anchors, int $at, ?callable $fetch = null): array
{
    $anchorFp = [];
    foreach ($anchors as $a) {
        $fp = strtoupper((string) @openssl_x509_fingerprint($a, 'sha256'));
        if ($fp !== '') {
            $anchorFp[$fp] = $a;
        }
    }
    $cands = [];
    foreach (array_merge($pool, $anchors) as $p) {
        $parts = ds_x509_parts(ds_unpem($p));
        if ($parts) {
            $cands[] = ['pem' => $p, 'parts' => $parts];
        }
    }
    $path = [];
    $cur = $leafPem;
    $timeOk = true;
    for ($depth = 0; $depth < 10; $depth++) {
        $sum = ds_cert_summary($cur);
        if (!$sum) {
            return ['status' => 'broken', 'path' => $path, 'anchor' => null, 'time_ok' => false, 'message' => 'A certificate in the chain cannot be read.'];
        }
        if ($at < $sum['not_before'] || $at > $sum['not_after']) {
            $timeOk = false;
        }
        $path[] = $sum;
        if (isset($anchorFp[$sum['fingerprint']])) {
            return ['status' => 'trusted', 'path' => $path, 'anchor' => $sum['cn'] ?: $sum['subject_dn'], 'time_ok' => $timeOk, 'message' => ''];
        }
        $parts = ds_x509_parts(ds_unpem($cur));
        if (!$parts) {
            return ['status' => 'broken', 'path' => $path, 'anchor' => null, 'time_ok' => $timeOk, 'message' => 'A certificate in the chain is malformed.'];
        }
        $next = null;
        for ($try = 0; $try < 2 && $next === null; $try++) {
            foreach ($cands as $c) {
                if ($c['parts']['subject'] !== $parts['issuer'] || $c['pem'] === $cur) {
                    continue;
                }
                if (@openssl_x509_verify($cur, $c['pem']) === 1) {
                    $cs = ds_cert_summary($c['pem']);
                    if ($cs && ($cs['is_ca'] || isset($anchorFp[$cs['fingerprint']]))) {
                        $next = $c['pem'];
                        break;
                    }
                }
            }
            // The issuer is not in the signature: fetch it from the certificate's own
            // "CA Issuers" address. It only fills a gap; trust still needs an anchor.
            if ($next === null && $try === 0 && $fetch && $sum['ca_issuers']) {
                foreach (array_slice($sum['ca_issuers'], 0, 2) as $url) {
                    foreach (ds_certs_from_bytes((string) $fetch($url, null, null)) as $pem) {
                        if ($p2 = ds_x509_parts(ds_unpem($pem))) {
                            $cands[] = ['pem' => $pem, 'parts' => $p2];
                        }
                    }
                }
            }
        }
        if ($next === null) {
            if ($parts['issuer'] === $parts['subject'] && @openssl_x509_verify($cur, $cur) === 1) {
                return ['status' => 'untrusted', 'path' => $path, 'anchor' => null, 'time_ok' => $timeOk,
                        'message' => 'The chain ends at "' . ($sum['cn'] ?: $sum['subject_dn']) . '", a root that is not in the trust store.'];
            }
            return ['status' => 'untrusted', 'path' => $path, 'anchor' => null, 'time_ok' => $timeOk,
                    'message' => 'The issuer of "' . ($sum['cn'] ?: $sum['subject_dn']) . '" (' . $sum['issuer_cn'] . ') was not found, so the chain cannot reach a trusted root.'];
        }
        $cur = $next;
    }
    return ['status' => 'broken', 'path' => $path, 'anchor' => null, 'time_ok' => $timeOk, 'message' => 'The certificate chain is too long.'];
}

/** Certificates in a fetched body: PEM, a single DER certificate, or a PKCS #7 bundle (.p7c). */
function ds_certs_from_bytes(string $b): array
{
    if ($b === '') {
        return [];
    }
    if (str_contains($b, '-----BEGIN CERTIFICATE-----')) {
        return ds_pem_split($b);
    }
    if (@openssl_x509_read(ds_pem($b))) {
        return [ds_pem($b)];
    }
    $cms = ds_cms_parse($b);
    return $cms ? array_map('ds_pem', $cms['certs']) : [];
}

/* =========================================================== revocation */

/**
 * Is this certificate revoked? OCSP first, then the CRL. $fetch($url,
 * $postBody, $contentType) returns the response body or null. Returns
 * status good | revoked | unavailable, with the method, time and message.
 */
function ds_revocation(array $cert, ?array $issuer, callable $fetch, int $at): array
{
    if (!$issuer) {
        return ['status' => 'unavailable', 'method' => null, 'message' => 'The issuing certificate is not available to check revocation.'];
    }
    $cp = ds_x509_parts(ds_unpem($cert['pem']));
    $ip = ds_x509_parts(ds_unpem($issuer['pem']));
    if (!$cp || !$ip) {
        return ['status' => 'unavailable', 'method' => null, 'message' => 'The certificates could not be read for a revocation check.'];
    }
    $tried = [];
    foreach ($cert['ocsp'] as $url) {
        if (!preg_match('~^https?://~i', $url)) {
            continue;
        }
        $tried[] = 'OCSP';
        $body = $fetch($url, ds_ocsp_request($cp, $ip), 'application/ocsp-request');
        if (!$body) {
            continue;
        }
        $r = ds_ocsp_parse($body, $cp['serial']);
        if (!$r || !ds_signed_by($r['tbs'], $r['sig'], $r['sigalg'], array_merge([$issuer['pem']], array_map('ds_pem', $r['certs'])), $issuer['pem'])) {
            continue;
        }
        if ($r['status'] === 'good') {
            return ['status' => 'good', 'method' => 'OCSP', 'checked' => $r['this_update'], 'message' => 'The OCSP responder reports the certificate as good.'];
        }
        if ($r['status'] === 'revoked') {
            return ['status' => 'revoked', 'method' => 'OCSP', 'revoked_at' => $r['revoked_at'], 'message' => 'The OCSP responder reports the certificate as revoked.'];
        }
    }
    foreach ($cert['crl'] as $url) {
        if (!preg_match('~^https?://~i', $url)) {
            continue;
        }
        $tried[] = 'CRL';
        $body = $fetch($url, null, null);
        if (!$body) {
            continue;
        }
        $crl = ds_crl_parse($body);
        if (!$crl || $crl['issuer'] !== $ip['subject'] || !ds_signed_by($crl['tbs'], $crl['sig'], $crl['sigalg'], [$issuer['pem']], $issuer['pem'])) {
            continue;
        }
        if ($crl['next_update'] && $crl['next_update'] < time() - 86400 * 7) {
            continue;                                      // badly stale: no longer authoritative
        }
        $serial = $cp['serial'];
        if (isset($crl['revoked'][$serial])) {
            return ['status' => 'revoked', 'method' => 'CRL', 'revoked_at' => $crl['revoked'][$serial], 'message' => 'The certificate is on the issuer\'s revocation list.'];
        }
        return ['status' => 'good', 'method' => 'CRL', 'checked' => $crl['this_update'], 'message' => 'The certificate is not on the issuer\'s revocation list (published ' . gmdate('d M Y', (int) $crl['this_update']) . ').'];
    }
    if (!$cert['ocsp'] && !$cert['crl']) {
        return ['status' => 'unavailable', 'method' => null, 'message' => 'The certificate names no OCSP responder or revocation list.'];
    }
    return ['status' => 'unavailable', 'method' => null, 'message' => 'The revocation service (' . implode(', ', array_unique($tried)) . ') could not be reached or gave no usable answer.'];
}

/**
 * Was $tbs signed by one of $signers (PEM)? An OCSP responder may sign with
 * a delegated certificate, which must itself be issued by $issuerPem.
 */
function ds_signed_by(string $tbs, string $sig, string $sigalg, array $signers, string $issuerPem): bool
{
    $alg = ds_sigalg_of_oid($sigalg);
    if ($alg === null) {
        return false;
    }
    foreach ($signers as $pem) {
        $key = @openssl_pkey_get_public($pem);
        if ($key && @openssl_verify($tbs, $sig, $key, $alg) === 1) {
            return $pem === $issuerPem || @openssl_x509_verify($pem, $issuerPem) === 1;
        }
    }
    return false;
}

/** HTTP fetch for revocation data, through the portal's SSRF guard. */
function ds_http_fetch(string $url, ?string $post = null, ?string $type = null): ?string
{
    if (!function_exists('curl_init')) {
        return null;
    }
    require_once __DIR__ . '/../ssrf.php';
    [$ok] = fabo_url_safe($url);
    if (!$ok) {
        return null;
    }
    $ch = curl_init($url);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_FOLLOWLOCATION => false,
             CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_USERAGENT => 'DigitalSignatureVerifier/1.0'];
    if ($post !== null) {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = $post;
        $opts[CURLOPT_HTTPHEADER] = ['Content-Type: ' . ($type ?: 'application/octet-stream')];
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return is_string($body) && $code === 200 && $body !== '' && strlen($body) < 20_000_000 ? $body : null;
}

/* ============================================================ the check */

/**
 * Verifies every signature in a PDF. $opt:
 *   anchors     trusted root/intermediate certificates (PEM list)
 *   fetch       callable($url, $post, $type): ?string — revocation fetcher (default: none, so revocation is unavailable)
 *   now         the time to judge "now" by (tests)
 *   revocation  false to skip revocation entirely (reported as not checked)
 */
function ds_verify_pdf(string $pdf, array $opt = []): array
{
    $now = (int) ($opt['now'] ?? time());
    $anchors = array_values(array_filter((array) ($opt['anchors'] ?? [])));
    $fetch = $opt['fetch'] ?? static fn() => null;
    $report = ['sha256' => hash('sha256', $pdf), 'size' => strlen($pdf), 'checked_at' => $now, 'signatures' => [], 'overall' => 'unsigned',
               'anchors' => count($anchors), 'pages' => null, 'problem' => null];
    try {
        $full = new DsPdf($pdf);
        $report['pages'] = count($full->pages());
    } catch (Throwable $e) {
        $full = null;
        $report['problem'] = 'The file could not be read as a PDF: ' . $e->getMessage();
    }
    $sigs = ds_pdf_signatures($pdf);
    $tmp = [];
    $mk = static function (string $data) use (&$tmp): string {
        $f = tempnam(sys_get_temp_dir(), 'dsv');
        file_put_contents($f, $data);
        $tmp[] = $f;
        return $f;
    };
    try {
        foreach ($sigs as $i => $s) {
            $report['signatures'][] = ds_verify_one($pdf, $s, $i, $full, $anchors, $fetch, $now, $mk, $opt);
        }
    } finally {
        foreach ($tmp as $f) {
            @unlink($f);
        }
    }
    $worst = 'unsigned';
    foreach ($report['signatures'] as $r) {
        if ($worst === 'unsigned' || ds_verdict_rank($r['verdict']) > ds_verdict_rank($worst)) {
            $worst = $r['verdict'];
        }
    }
    $report['overall'] = $worst;
    return $report;
}

function ds_verify_one(string $pdf, array $s, int $i, ?DsPdf $full, array $anchors, callable $fetch, int $now, callable $mk, array $opt): array
{
    $d = $s['dict'];
    $isTs = ($d['SubFilter'] ?? '') === 'ETSI.RFC3161' || ($d['Type'] ?? '') === 'DocTimeStamp';
    $r = ['index' => $i + 1, 'kind' => $isTs ? 'timestamp' : 'signature', 'subfilter' => $d['SubFilter'] ?? null,
          'name' => $d['Name'] ?? null, 'reason' => $d['Reason'] ?? null, 'location' => $d['Location'] ?? null,
          'claimed_time' => ds_pdf_date($d['M'] ?? null), 'range' => $s['range'], 'revision_len' => $s['range'][2] + $s['range'][3],
          'covers_whole' => $s['range'][2] + $s['range'][3] === strlen($pdf), 'integrity' => 'failed', 'signer' => null, 'chain' => null,
          'signing_time' => null, 'time_source' => 'none', 'timestamp' => null, 'validity' => null, 'revocation' => null,
          'modifications' => null, 'pades' => false, 'verdict' => 'invalid', 'notes' => [], 'checks' => []];
    $check = static function (string $key, string $label, string $state, string $msg) use (&$r) {
        $r['checks'][] = ['key' => $key, 'label' => $label, 'state' => $state, 'message' => $msg];
    };
    if ($s['problem'] || !$s['der']) {
        $check('integrity', 'Signature value', 'fail', $s['problem'] ?: 'No signature value.');
        return $r;
    }
    $covered = DsPdfUpdate::covered($pdf, $s['range']);
    $cms = ds_cms_parse($s['der']);
    if (!$cms || !$cms['signer']) {
        $check('integrity', 'Signature value', 'fail', 'The signature value is not a CMS / PKCS #7 signature.');
        return $r;
    }
    $r['pades'] = !empty($cms['signer']['ess']);

    /* ---- integrity */
    if ($isTs) {
        $tst = ds_tst_verify($s['der'], $mk, $anchors, $now);
        $ok = $tst['valid'] && $tst['hash_alg'] && hash_equals((string) $tst['imprint'], hash($tst['hash_alg'], $covered));
        $r['integrity'] = $ok ? 'ok' : 'failed';
        $r['timestamp'] = $tst;
        $r['signing_time'] = $tst['time'];
        $r['time_source'] = 'timestamp';
        $leafPem = $tst['tsa_pem'];
    } else {
        $sigFile = $mk($s['der']);
        $dataFile = $mk($covered);
        $signersFile = $mk('');
        $ok = @openssl_cms_verify($dataFile, OPENSSL_CMS_DETACHED | OPENSSL_CMS_BINARY | OPENSSL_CMS_NOVERIFY, $signersFile, [], null, null, null, $sigFile, OPENSSL_ENCODING_DER);
        while (openssl_error_string() !== false) {
        }
        $r['integrity'] = $ok ? 'ok' : 'failed';
        $leafPem = $ok ? (string) file_get_contents($signersFile) : '';
        if ($leafPem === '') {
            // Find the signer among the embedded certificates, for the report.
            foreach ($cms['certs'] as $c) {
                $p = ds_x509_parts($c);
                if ($p && (($cms['signer']['sid_serial'] && $p['serial'] === $cms['signer']['sid_serial']) || count($cms['certs']) === 1)) {
                    $leafPem = ds_pem($c);
                    break;
                }
            }
        }
        $leafPem = ds_pem_split($leafPem)[0] ?? $leafPem;
        $r['signing_time'] = $cms['signer']['signing_time'] ?? $r['claimed_time'];
        $r['time_source'] = $cms['signer']['signing_time'] ? 'signed attribute' : ($r['claimed_time'] ? 'claimed (/M)' : 'none');
        if ($cms['signer']['tst']) {
            $tst = ds_tst_verify($cms['signer']['tst'], $mk, $anchors, $now);
            $tst['matches'] = $tst['valid'] && $tst['hash_alg'] && hash_equals((string) $tst['imprint'], hash($tst['hash_alg'], $cms['signer']['signature']));
            $r['timestamp'] = $tst;
            if ($tst['matches'] && $tst['trusted']) {
                $r['signing_time'] = $tst['time'];
                $r['time_source'] = 'trusted timestamp';
            }
        }
    }
    if ($r['integrity'] !== 'ok') {
        $check('integrity', 'Signature value', 'fail', 'The signature does not match the signed content: the bytes it covers were altered, or it was not made over them.');
        $r['signer'] = $leafPem !== '' ? ds_cert_summary($leafPem) : null;
        return $r;
    }
    $check('integrity', 'Signature value', 'pass', 'The ' . ($isTs ? 'timestamp' : 'signature') . ' verifies over the ' . number_format($s['range'][1] + $s['range'][3]) . ' bytes it covers.');
    $signer = $leafPem !== '' ? ds_cert_summary($leafPem) : null;
    $r['signer'] = $signer;
    if (!$signer) {
        $check('identity', 'Signer certificate', 'fail', 'The signer\'s certificate is not in the signature.');
        return $r;
    }

    /* ---- modifications after this signature */
    $r['modifications'] = ds_changes_after($pdf, $r['revision_len'], $full, $d['DocMDP'] ?? null);
    $mod = $r['modifications']['status'];
    if ($mod === 'modified') {
        $check('modified', 'Changes after signing', 'fail', 'The document was changed after this signature: ' . implode('; ', array_slice($r['modifications']['details'], 0, 4)) . '.');
    } elseif ($mod === 'none') {
        $check('modified', 'Changes after signing', 'pass', 'Nothing was added after this signature.');
    } else {
        $check('modified', 'Changes after signing', 'pass', 'Only ' . ($mod === 'annotations' ? 'signatures, signature fields and annotations (such as approval stamps)' : 'further signatures') . ' were added after this signature; the signed content is unchanged.');
    }

    /* ---- key usage */
    if (!$isTs && !ds_cert_can_sign($signer)) {
        $check('usage', 'Key usage', 'fail', 'The certificate is not issued for signing (key usage: ' . $signer['key_usage'] . ').');
        $r['verdict'] = 'invalid';
        return $r;
    }

    /* ---- identity: the chain */
    $refTime = ($r['time_source'] === 'trusted timestamp' && $r['signing_time']) ? (int) $r['signing_time'] : $now;
    $chain = ds_chain($leafPem, array_map('ds_pem', $cms['certs']), $anchors, $refTime, $fetch);
    $r['chain'] = ['status' => $chain['status'], 'anchor' => $chain['anchor'], 'message' => $chain['message'],
                   'path' => array_map(static fn($c) => ['cn' => $c['cn'] ?: $c['subject_dn'], 'org' => $c['org'], 'not_after' => $c['not_after'], 'fingerprint' => $c['fingerprint']], $chain['path'])];
    if ($chain['status'] === 'trusted') {
        $check('chain', 'Certificate chain', 'pass', 'Chains to the trusted root "' . $chain['anchor'] . '".');
    } elseif ($chain['status'] === 'broken') {
        $check('chain', 'Certificate chain', 'fail', $chain['message']);
    } else {
        $check('chain', 'Certificate chain', 'warn', $chain['message'] . ($anchors ? '' : ' The trust store is empty: add the CCA India root certificates.'));
    }

    /* ---- time */
    $signedAt = $r['signing_time'];
    $expired = false;
    if ($signedAt && ($signedAt < $signer['not_before'] - 300 || $signedAt > $signer['not_after'])) {
        $expired = true;
        $check('validity', 'Certificate validity', 'fail', 'The certificate was not valid when the document was signed (' . gmdate('d M Y H:i', (int) $signedAt) . ' UTC).');
    } elseif (!$chain['time_ok']) {
        $expired = true;
        $check('validity', 'Certificate validity', 'fail', $r['time_source'] === 'trusted timestamp'
            ? 'A certificate in the chain was not valid at the trusted signing time.'
            : 'A certificate in the chain has expired (valid to ' . gmdate('d M Y', $signer['not_after']) . ') and there is no trusted timestamp proving the signature was made while it was valid.');
    } else {
        $check('validity', 'Certificate validity', 'pass', 'Valid ' . gmdate('d M Y', $signer['not_before']) . ' – ' . gmdate('d M Y', $signer['not_after'])
            . ($r['time_source'] === 'trusted timestamp' ? ', judged at the trusted signing time.' : ', judged now (no trusted timestamp).'));
    }
    $r['validity'] = ['status' => $expired ? 'expired' : 'valid', 'at' => $refTime];
    if ($r['timestamp']) {
        $t = $r['timestamp'];
        $ok = ($t['matches'] ?? $t['valid']) && $t['trusted'];
        $check('timestamp', 'Trusted timestamp', $ok ? 'pass' : 'warn', $ok
            ? 'Timestamped by ' . $t['tsa'] . ' at ' . gmdate('d M Y H:i:s', (int) $t['time']) . ' UTC.'
            : 'A timestamp is present but could not be trusted (' . ($t['message'] ?: 'its authority is not in the trust store') . ').');
    }

    /* ---- revocation (of the signer, and every intermediate up to the anchor) */
    $revoked = false;
    if (($opt['revocation'] ?? true) === false) {
        $r['revocation'] = ['status' => 'not_checked', 'message' => 'Revocation was not checked.'];
        $check('revocation', 'Revocation', 'warn', 'Revocation was not checked.');
    } else {
        $path = $chain['path'];
        $res = [];
        for ($k = 0; $k < count($path) - 1; $k++) {
            $res[] = ds_revocation($path[$k], $path[$k + 1], $fetch, $refTime);
        }
        if (!$res) {
            $res[] = ds_revocation($signer, null, $fetch, $refTime);
        }
        $first = $res[0];
        $bad = array_values(array_filter($res, static fn($x) => $x['status'] === 'revoked'));
        $unk = array_values(array_filter($res, static fn($x) => $x['status'] !== 'good'));
        if ($bad) {
            $when = $bad[0]['revoked_at'] ?? null;
            // Revoked after a trusted signing time: the signature was made while the key was good.
            if ($when && $r['time_source'] === 'trusted timestamp' && $signedAt && $when > $signedAt) {
                $r['revocation'] = ['status' => 'good', 'method' => $bad[0]['method'], 'message' => 'Revoked on ' . gmdate('d M Y', $when) . ', after the trusted signing time.'];
                $check('revocation', 'Revocation', 'pass', $r['revocation']['message']);
            } else {
                $revoked = true;
                $r['revocation'] = $bad[0];
                $check('revocation', 'Revocation', 'fail', $bad[0]['message'] . ($when ? ' Revoked ' . gmdate('d M Y H:i', $when) . ' UTC.' : ''));
            }
        } elseif ($unk) {
            $r['revocation'] = $unk[0];
            $check('revocation', 'Revocation', 'warn', $unk[0]['message']);
        } else {
            $r['revocation'] = $first;
            $check('revocation', 'Revocation', 'pass', $first['message']);
        }
    }

    /* ---- the verdict */
    if ($chain['status'] === 'broken') {
        $r['verdict'] = 'invalid';
    } elseif ($mod === 'modified') {
        $r['verdict'] = 'modified';
    } elseif ($revoked) {
        $r['verdict'] = 'revoked';
    } elseif ($expired) {
        $r['verdict'] = 'expired';
    } elseif ($chain['status'] !== 'trusted' || ($r['revocation']['status'] ?? '') !== 'good') {
        $r['verdict'] = 'incomplete';
    } else {
        $r['verdict'] = 'valid';
    }
    return $r;
}

/**
 * An RFC 3161 timestamp token: does it verify, what does it stamp, when,
 * by whom, and does its authority chain to the trust store?
 */
function ds_tst_verify(string $der, callable $mk, array $anchors, int $now): array
{
    $out = ['valid' => false, 'trusted' => false, 'time' => null, 'tsa' => null, 'hash_alg' => null, 'imprint' => null, 'tsa_pem' => '', 'message' => ''];
    $in = $mk($der);
    $signers = $mk('');
    $content = $mk('');
    $ok = @openssl_cms_verify($in, OPENSSL_CMS_BINARY | OPENSSL_CMS_NOVERIFY, $signers, [], null, $content, null, null, OPENSSL_ENCODING_DER);
    while (openssl_error_string() !== false) {
    }
    if (!$ok) {
        $out['message'] = 'the timestamp token does not verify';
        return $out;
    }
    $info = ds_tstinfo_parse((string) file_get_contents($content));
    if (!$info) {
        $out['message'] = 'the timestamp token holds no TSTInfo';
        return $out;
    }
    $pem = ds_pem_split((string) file_get_contents($signers))[0] ?? '';
    $sum = $pem !== '' ? ds_cert_summary($pem) : null;
    $out['valid'] = true;
    $out['time'] = $info['gen_time'];
    $out['hash_alg'] = ds_hash_of_oid($info['hash_alg']);
    $out['imprint'] = $info['imprint'];
    $out['tsa'] = $sum['cn'] ?? 'an unnamed authority';
    $out['tsa_pem'] = $pem;
    if ($pem !== '') {
        $cms = ds_cms_parse($der);
        $ch = ds_chain($pem, array_map('ds_pem', $cms['certs'] ?? []), $anchors, (int) $info['gen_time']);
        $out['trusted'] = $ch['status'] === 'trusted' && $ch['time_ok'];
        if (!$out['trusted']) {
            $out['message'] = $ch['message'] ?: 'its certificate was not valid at the stamped time';
        }
    }
    return $out;
}

/**
 * What changed after a signed revision of $revLen bytes. Every object the
 * later sections define is classified: new signatures, fields, annotations
 * and their appearances are allowed; a new page, changed page content or a
 * changed catalogue (beyond its form and security store) is a modification.
 * A certification signature's DocMDP level narrows what is allowed.
 */
function ds_changes_after(string $pdf, int $revLen, ?DsPdf $full, ?int $docMdp = null): array
{
    if ($revLen >= strlen($pdf) || trim(substr($pdf, $revLen)) === '') {
        return ['status' => 'none', 'details' => [], 'revisions_after' => 0];
    }
    $details = [];
    $annots = false;
    $sigs = false;
    try {
        $full = $full ?? new DsPdf($pdf);
        $old = new DsPdf(substr($pdf, 0, $revLen));
        $oldPages = $old->pages();
        $contentNums = [];
        foreach ($oldPages as $pg) {
            $c = $pg['dict']->get('Contents');
            if ($c instanceof DsRef) {
                $contentNums[$c->num] = true;
                $arr = $old->get($c);
                foreach (is_array($arr) ? $arr : [] as $x) {
                    if ($x instanceof DsRef) {
                        $contentNums[$x->num] = true;
                    }
                }
            } elseif (is_array($c)) {
                foreach ($c as $x) {
                    if ($x instanceof DsRef) {
                        $contentNums[$x->num] = true;
                    }
                }
            }
            // Resources an existing page draws with may not change either.
            $res = $pg['dict']->get('Resources');
            if ($res instanceof DsRef) {
                $contentNums[$res->num] = true;
            }
        }
        $infoNum = ($old->trailer->get('Info') instanceof DsRef) ? $old->trailer->get('Info')->num : -1;
        $rootNum = ($old->trailer->get('Root') instanceof DsRef) ? $old->trailer->get('Root')->num : -1;
        if (count($full->pages()) !== count($oldPages)) {
            $details[] = 'the page count changed from ' . count($oldPages) . ' to ' . count($full->pages());
        }
        $strip = static function ($v, array $keys) {
            if (!$v instanceof DsDict) {
                return DsPdf::ser($v);
            }
            $d = $v->d;
            foreach ($keys as $k) {
                unset($d[$k]);
            }
            return DsPdf::ser($v instanceof DsStream ? new DsStream($d, $v->data) : new DsDict($d));
        };
        foreach ($full->numsSince($revLen) as $num) {
            $ref = new DsRef($num);
            try {
                $new = $full->get($ref);
            } catch (Throwable $e) {
                continue;
            }
            if ($new instanceof DsStream && in_array($new->name('Type'), ['XRef', 'ObjStm'], true)) {
                continue;
            }
            $existed = isset($old->xref[$num]) && $old->xref[$num]['t'] !== 0;
            $oldV = $existed ? $old->get($ref) : null;
            $type = $new instanceof DsDict ? ($new->name('Type') ?? '') : '';
            $sub = $new instanceof DsDict ? ($new->name('Subtype') ?? '') : '';
            if (!$existed) {
                if ($type === 'Page' || $type === 'Pages') {
                    $details[] = 'a page was added';
                } elseif ($type === 'Sig' || $type === 'DocTimeStamp') {
                    $sigs = true;
                } elseif ($type === 'Annot' || $sub === 'Widget' || ($new instanceof DsDict && $new->get('FT') instanceof DsName)) {
                    if ($sub !== 'Widget') {
                        $annots = true;
                    } else {
                        $sigs = true;
                    }
                }
                continue;
            }
            if ($num === $infoNum || ($oldV instanceof DsStream && $oldV->name('Type') === 'Metadata')) {
                continue;                                  // document information and XMP are bookkeeping
            }
            if (DsPdf::ser($oldV) === DsPdf::ser($new)) {
                continue;
            }
            if (isset($contentNums[$num])) {
                $details[] = 'the content of a page was changed';
                continue;
            }
            $oType = $oldV instanceof DsDict ? ($oldV->name('Type') ?? '') : '';
            if ($num === $rootNum || $oType === 'Catalog') {
                if ($strip($oldV, ['AcroForm', 'DSS', 'Perms', 'Extensions', 'Metadata']) !== $strip($new, ['AcroForm', 'DSS', 'Perms', 'Extensions', 'Metadata'])) {
                    $details[] = 'the document catalogue was changed';
                }
            } elseif ($oType === 'Page') {
                if ($strip($oldV, ['Annots']) !== $strip($new, ['Annots'])) {
                    $details[] = 'a page\'s content or resources were changed';
                }
            } elseif ($oType === 'Pages') {
                $details[] = 'the page tree was changed';
            } elseif ($oType === 'Sig') {
                $details[] = 'an earlier signature was altered';
            } elseif ($oldV instanceof DsDict && $oldV->get('Fields') !== null) {
                // the AcroForm: fields added
            } elseif ($oType === 'Annot' || ($oldV instanceof DsDict && in_array($oldV->name('Subtype'), ['Widget', 'Stamp', 'FreeText', 'Text', 'Link', 'Ink', 'Highlight', 'Square'], true))) {
                $annots = true;
            } elseif (is_array($oldV) && !array_filter($oldV, static fn($x) => !$x instanceof DsRef)) {
                // an /Annots or /Fields array of references, grown
            } else {
                $details[] = 'object ' . $num . ' (' . ($oType ?: ($oldV instanceof DsStream ? 'stream' : gettype($oldV))) . ') was changed';
            }
        }
    } catch (Throwable $e) {
        return ['status' => 'modified', 'details' => ['the later revisions could not be analysed (' . $e->getMessage() . ')'], 'revisions_after' => null];
    }
    if ($docMdp === 1 && ($sigs || $annots)) {
        $details[] = 'this is a certification signature that allows no changes, yet changes were made';
    } elseif ($docMdp === 2 && $annots) {
        $details[] = 'this certification signature allows form filling and signing only, yet annotations were added';
    }
    $after = substr_count(substr($pdf, $revLen), '%%EOF');
    if ($details) {
        return ['status' => 'modified', 'details' => array_values(array_unique($details)), 'revisions_after' => $after];
    }
    return ['status' => $annots ? 'annotations' : 'signatures_only', 'details' => [], 'revisions_after' => $after];
}

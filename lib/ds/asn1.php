<?php
/**
 * Digital Signatures — a small DER (ASN.1) reader and writer.
 *
 * Just enough of ASN.1 to look inside what OpenSSL will not show PHP: the
 * signed attributes of a CMS signature (signing time, message digest), an
 * RFC 3161 timestamp token, a certificate revocation list and an OCSP
 * response. Every cryptographic check itself is still made by OpenSSL; this
 * only finds the bytes to hand it.
 *
 * A node is an array: tag (the first identifier byte), cons (constructed),
 * cls (0 universal, 1 application, 2 context, 3 private), no (tag number),
 * off (where the node starts), hl (header length), len (content length),
 * end (one past the last byte) and, for constructed nodes, kids.
 */

/** Parses one DER node at $off. Throws on anything malformed. */
function ds_der_node(string $der, int $off = 0, int $depth = 0): array
{
    $n = strlen($der);
    if ($off + 2 > $n || $depth > 40) {
        throw new RuntimeException('Malformed DER (truncated).');
    }
    $tag = ord($der[$off]);
    $no = $tag & 0x1f;
    $p = $off + 1;
    if ($no === 0x1f) {                       // high tag number form
        $no = 0;
        do {
            if ($p >= $n) {
                throw new RuntimeException('Malformed DER (tag).');
            }
            $b = ord($der[$p++]);
            $no = ($no << 7) | ($b & 0x7f);
        } while ($b & 0x80);
    }
    if ($p >= $n) {
        throw new RuntimeException('Malformed DER (length).');
    }
    $l = ord($der[$p++]);
    if ($l === 0x80) {
        throw new RuntimeException('Indefinite-length BER is not DER.');
    }
    if ($l & 0x80) {
        $k = $l & 0x7f;
        if ($k > 4 || $p + $k > $n) {
            throw new RuntimeException('Malformed DER (long length).');
        }
        $l = 0;
        for ($i = 0; $i < $k; $i++) {
            $l = ($l << 8) | ord($der[$p++]);
        }
    }
    $end = $p + $l;
    if ($end > $n) {
        throw new RuntimeException('Malformed DER (overrun).');
    }
    $node = ['tag' => $tag, 'cons' => (bool) ($tag & 0x20), 'cls' => $tag >> 6, 'no' => $no, 'off' => $off, 'hl' => $p - $off, 'len' => $l, 'end' => $end];
    if ($node['cons']) {
        $kids = [];
        $q = $p;
        while ($q < $end) {
            $kid = ds_der_node($der, $q, $depth + 1);
            $kids[] = $kid;
            $q = $kid['end'];
        }
        $node['kids'] = $kids;
    }
    return $node;
}

/** The content bytes of a node. */
function ds_der_val(string $der, array $node): string
{
    return substr($der, $node['off'] + $node['hl'], $node['len']);
}

/** The whole node, header included. */
function ds_der_raw(string $der, array $node): string
{
    return substr($der, $node['off'], $node['end'] - $node['off']);
}

/** Child $i, or null. */
function ds_der_kid(array $node, int $i): ?array
{
    return $node['kids'][$i] ?? null;
}

/** The first child with this identifier byte, or null. */
function ds_der_find(array $node, int $tag): ?array
{
    foreach ($node['kids'] ?? [] as $k) {
        if ($k['tag'] === $tag) {
            return $k;
        }
    }
    return null;
}

/** An OBJECT IDENTIFIER's content as dotted text. */
function ds_der_oid(string $bytes): string
{
    if ($bytes === '') {
        return '';
    }
    $first = ord($bytes[0]);
    $parts = [intdiv($first, 40) > 2 ? 2 : intdiv($first, 40), $first >= 80 ? $first - 80 : $first % 40];
    $v = 0;
    for ($i = 1, $n = strlen($bytes); $i < $n; $i++) {
        $b = ord($bytes[$i]);
        $v = ($v << 7) | ($b & 0x7f);
        if (!($b & 0x80)) {
            $parts[] = $v;
            $v = 0;
        }
    }
    return implode('.', $parts);
}

/** UTCTime / GeneralizedTime content as a Unix time, or null. */
function ds_der_time(string $s, int $tag): ?int
{
    $s = trim($s);
    if ($tag === 0x17 && preg_match('/^(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})?Z$/', $s, $m)) {
        $y = (int) $m[1];
        $y += $y >= 50 ? 1900 : 2000;
        return gmmktime((int) $m[4], (int) $m[5], (int) ($m[6] ?? 0), (int) $m[2], (int) $m[3], $y);
    }
    if ($tag === 0x18 && preg_match('/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})?(?:[.,]\d+)?(Z|[+-]\d{4})?$/', $s, $m)) {
        $t = gmmktime((int) $m[4], (int) $m[5], (int) ($m[6] ?? 0), (int) $m[2], (int) $m[3], (int) $m[1]);
        if (!empty($m[7]) && $m[7] !== 'Z') {
            $sign = $m[7][0] === '-' ? -1 : 1;
            $t -= $sign * ((int) substr($m[7], 1, 2) * 3600 + (int) substr($m[7], 3, 2) * 60);
        }
        return $t;
    }
    return null;
}

/** An INTEGER's content as an upper-case hex string without leading zero bytes. */
function ds_der_int_hex(string $bytes): string
{
    $h = strtoupper(bin2hex(ltrim($bytes, "\0")));
    return $h === '' ? '00' : $h;
}

/* ---------------------------------------------------------------- writing */

function ds_der_len(int $n): string
{
    if ($n < 0x80) {
        return chr($n);
    }
    $b = '';
    while ($n > 0) {
        $b = chr($n & 0xff) . $b;
        $n >>= 8;
    }
    return chr(0x80 | strlen($b)) . $b;
}

/** A node from its identifier byte and content. */
function ds_der(int $tag, string $content): string
{
    return chr($tag) . ds_der_len(strlen($content)) . $content;
}

function ds_der_seq(string ...$parts): string
{
    return ds_der(0x30, implode('', $parts));
}

function ds_der_oid_enc(string $dotted): string
{
    $p = array_map('intval', explode('.', $dotted));
    $out = chr($p[0] * 40 + $p[1]);
    for ($i = 2, $n = count($p); $i < $n; $i++) {
        $v = $p[$i];
        $chunk = chr($v & 0x7f);
        $v >>= 7;
        while ($v > 0) {
            $chunk = chr(0x80 | ($v & 0x7f)) . $chunk;
            $v >>= 7;
        }
        $out .= $chunk;
    }
    return ds_der(0x06, $out);
}

/** An INTEGER from a hex string (non-negative). */
function ds_der_int_from_hex(string $hex): string
{
    $hex = ltrim($hex, '0');
    if ($hex === '') {
        $hex = '00';
    }
    if (strlen($hex) % 2) {
        $hex = '0' . $hex;
    }
    $b = hex2bin($hex);
    if (ord($b[0]) & 0x80) {
        $b = "\0" . $b;
    }
    return ds_der(0x02, $b);
}

function ds_der_gentime(int $t): string
{
    return ds_der(0x18, gmdate('YmdHis', $t) . 'Z');
}

function ds_der_utctime(int $t): string
{
    return ds_der(0x17, gmdate('ymdHis', $t) . 'Z');
}

/* ------------------------------------------------------------ OIDs we use */

function ds_oid_names(): array
{
    return [
        '1.2.840.113549.1.7.2'       => 'signedData',
        '1.2.840.113549.1.7.1'       => 'data',
        '1.2.840.113549.1.9.3'       => 'contentType',
        '1.2.840.113549.1.9.4'       => 'messageDigest',
        '1.2.840.113549.1.9.5'       => 'signingTime',
        '1.2.840.113549.1.9.16.2.47' => 'signingCertificateV2',
        '1.2.840.113549.1.9.16.2.12' => 'signingCertificate',
        '1.2.840.113549.1.9.16.2.14' => 'timeStampToken',
        '1.2.840.113549.1.9.16.1.4'  => 'tstInfo',
        '1.2.840.113583.1.1.8'       => 'adbeRevocationInfoArchival',
        '2.16.840.1.101.3.4.2.1'     => 'sha256',
        '2.16.840.1.101.3.4.2.2'     => 'sha384',
        '2.16.840.1.101.3.4.2.3'     => 'sha512',
        '1.3.14.3.2.26'              => 'sha1',
        '1.2.840.113549.1.1.1'       => 'rsaEncryption',
        '1.2.840.113549.1.1.5'       => 'sha1WithRSA',
        '1.2.840.113549.1.1.11'      => 'sha256WithRSA',
        '1.2.840.113549.1.1.12'      => 'sha384WithRSA',
        '1.2.840.113549.1.1.13'      => 'sha512WithRSA',
        '1.2.840.113549.1.1.10'      => 'rsassaPss',
        '1.2.840.10045.4.3.2'        => 'ecdsaWithSHA256',
        '1.2.840.10045.4.3.3'        => 'ecdsaWithSHA384',
        '1.2.840.10045.4.3.4'        => 'ecdsaWithSHA512',
        '1.3.6.1.5.5.7.48.1.1'       => 'ocspBasic',
    ];
}

/** The PHP hash name for a digest OID, or null. */
function ds_hash_of_oid(string $oid): ?string
{
    return ['2.16.840.1.101.3.4.2.1' => 'sha256', '2.16.840.1.101.3.4.2.2' => 'sha384', '2.16.840.1.101.3.4.2.3' => 'sha512',
            '1.3.14.3.2.26' => 'sha1'][$oid] ?? null;
}

/** The openssl_verify algorithm for a signature algorithm OID, or null. */
function ds_sigalg_of_oid(string $oid): ?int
{
    return ['1.2.840.113549.1.1.5' => OPENSSL_ALGO_SHA1, '1.2.840.113549.1.1.11' => OPENSSL_ALGO_SHA256,
            '1.2.840.113549.1.1.12' => OPENSSL_ALGO_SHA384, '1.2.840.113549.1.1.13' => OPENSSL_ALGO_SHA512,
            '1.2.840.10045.4.3.2' => OPENSSL_ALGO_SHA256, '1.2.840.10045.4.3.3' => OPENSSL_ALGO_SHA384,
            '1.2.840.10045.4.3.4' => OPENSSL_ALGO_SHA512, '1.2.840.10045.4.1' => OPENSSL_ALGO_SHA1][$oid] ?? null;
}

/* ------------------------------------------------------------------ PEM */

function ds_pem(string $der, string $label = 'CERTIFICATE'): string
{
    return '-----BEGIN ' . $label . "-----\n" . chunk_split(base64_encode($der), 64, "\n") . '-----END ' . $label . "-----\n";
}

/** DER from a PEM block (or the input when it is already DER). */
function ds_unpem(string $s): string
{
    if (preg_match('/-----BEGIN [A-Z0-9 ]+-----(.+?)-----END [A-Z0-9 ]+-----/s', $s, $m)) {
        return (string) base64_decode(preg_replace('/\s+/', '', $m[1]), true);
    }
    return $s;
}

/** Every certificate in a PEM bundle, as PEM blocks. */
function ds_pem_split(string $bundle): array
{
    preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $bundle, $m);
    return array_map(static fn($b) => $b . "\n", $m[0]);
}

/* ===================================================================== CMS */

/**
 * What is inside a CMS SignedData (PKCS #7) signature: its certificates
 * (DER), and for the first signer the digest algorithm, signed attributes,
 * signature value and any timestamp token. Null when it is not SignedData.
 */
function ds_cms_parse(string $der): ?array
{
    try {
        $ci = ds_der_node($der);
        if ($ci['tag'] !== 0x30 || ds_der_oid(ds_der_val($der, $ci['kids'][0])) !== '1.2.840.113549.1.7.2') {
            return null;
        }
        $sd = $ci['kids'][1]['kids'][0] ?? null;          // [0] EXPLICIT SignedData
        if (!$sd || $sd['tag'] !== 0x30) {
            return null;
        }
        $out = ['certs' => [], 'signer' => null, 'econtent_type' => null, 'econtent' => null];
        $i = 0;
        $kids = $sd['kids'];
        $i++;                                              // version
        $i++;                                              // digestAlgorithms SET
        $eci = $kids[$i++] ?? null;                        // encapContentInfo
        if ($eci) {
            $out['econtent_type'] = ds_der_oid(ds_der_val($der, $eci['kids'][0]));
            if (isset($eci['kids'][1]['kids'][0])) {
                $out['econtent'] = ds_der_val($der, $eci['kids'][1]['kids'][0]);
            }
        }
        while (isset($kids[$i]) && $kids[$i]['tag'] !== 0x31) {
            if ($kids[$i]['tag'] === 0xa0) {               // [0] certificates
                foreach ($kids[$i]['kids'] as $c) {
                    if ($c['tag'] === 0x30) {
                        $out['certs'][] = ds_der_raw($der, $c);
                    }
                }
            }
            $i++;                                          // [1] crls are skipped
        }
        $sis = $kids[$i] ?? null;                          // signerInfos SET
        $si = $sis['kids'][0] ?? null;
        if ($si) {
            $s = ['sid_serial' => null, 'sid_issuer' => null, 'sid_ski' => null, 'digest' => null, 'attrs' => [], 'attrs_raw' => null,
                  'sigalg' => null, 'signature' => '', 'tst' => null, 'signing_time' => null, 'message_digest' => null, 'ess' => false];
            $j = 1;                                        // after version
            $sid = $si['kids'][$j++];
            if ($sid['tag'] === 0x30) {
                $s['sid_issuer'] = ds_der_raw($der, $sid['kids'][0]);
                $s['sid_serial'] = ds_der_int_hex(ds_der_val($der, $sid['kids'][1]));
            } else {
                $s['sid_ski'] = bin2hex(ds_der_val($der, $sid));
            }
            $s['digest'] = ds_der_oid(ds_der_val($der, $si['kids'][$j++]['kids'][0]));
            if (($si['kids'][$j]['tag'] ?? 0) === 0xa0) {  // [0] IMPLICIT signedAttrs
                $sa = $si['kids'][$j++];
                // Signed as a SET OF: the [0] tag is replaced by 0x31 for hashing.
                $s['attrs_raw'] = "\x31" . substr(ds_der_raw($der, $sa), 1);
                foreach ($sa['kids'] as $a) {
                    $oid = ds_der_oid(ds_der_val($der, $a['kids'][0]));
                    $v = $a['kids'][1]['kids'][0] ?? null;
                    $s['attrs'][] = $oid;
                    if ($oid === '1.2.840.113549.1.9.5' && $v) {
                        $s['signing_time'] = ds_der_time(ds_der_val($der, $v), $v['tag']);
                    } elseif ($oid === '1.2.840.113549.1.9.4' && $v) {
                        $s['message_digest'] = bin2hex(ds_der_val($der, $v));
                    } elseif ($oid === '1.2.840.113549.1.9.16.2.47' || $oid === '1.2.840.113549.1.9.16.2.12') {
                        $s['ess'] = true;
                    }
                }
            }
            $s['sigalg'] = ds_der_oid(ds_der_val($der, $si['kids'][$j++]['kids'][0]));
            $s['signature'] = ds_der_val($der, $si['kids'][$j++]);
            $ua = $si['kids'][$j] ?? null;
            if ($ua && $ua['tag'] === 0xa1) {               // [1] unsignedAttrs
                foreach ($ua['kids'] as $a) {
                    if (ds_der_oid(ds_der_val($der, $a['kids'][0])) === '1.2.840.113549.1.9.16.2.14' && isset($a['kids'][1]['kids'][0])) {
                        $s['tst'] = ds_der_raw($der, $a['kids'][1]['kids'][0]);
                    }
                }
            }
            $out['signer'] = $s;
        }
        return $out;
    } catch (Throwable $e) {
        return null;
    }
}

/** TSTInfo (RFC 3161): the hash it stamps and the time it was stamped. */
function ds_tstinfo_parse(string $der): ?array
{
    try {
        $t = ds_der_node($der);
        $mi = $t['kids'][2];
        return ['policy' => ds_der_oid(ds_der_val($der, $t['kids'][1])),
                'hash_alg' => ds_der_oid(ds_der_val($der, $mi['kids'][0]['kids'][0])),
                'imprint' => bin2hex(ds_der_val($der, $mi['kids'][1])),
                'serial' => ds_der_int_hex(ds_der_val($der, $t['kids'][3])),
                'gen_time' => ds_der_time(ds_der_val($der, $t['kids'][4]), $t['kids'][4]['tag'])];
    } catch (Throwable $e) {
        return null;
    }
}

/* ===================================================================== X.509 */

/**
 * The parts of a certificate the revocation checks need, read straight from
 * the DER: the raw issuer and subject names, the serial, the public key bit
 * string (hashed for OCSP) and the to-be-signed bytes with their signature.
 */
function ds_x509_parts(string $der): ?array
{
    try {
        $c = ds_der_node($der);
        $tbs = $c['kids'][0];
        $k = $tbs['kids'];
        $i = ($k[0]['tag'] === 0xa0) ? 1 : 0;             // [0] version
        $serial = ds_der_int_hex(ds_der_val($der, $k[$i]));
        $issuer = ds_der_raw($der, $k[$i + 2]);
        $subject = ds_der_raw($der, $k[$i + 4]);
        $spki = $k[$i + 5];
        $bits = ds_der_val($der, $spki['kids'][1]);
        return ['serial' => $serial, 'issuer' => $issuer, 'subject' => $subject, 'key_bits' => substr($bits, 1),
                'tbs' => ds_der_raw($der, $tbs), 'sigalg' => ds_der_oid(ds_der_val($der, $c['kids'][1]['kids'][0])),
                'sig' => substr(ds_der_val($der, $c['kids'][2]), 1)];
    } catch (Throwable $e) {
        return null;
    }
}

/* ===================================================================== CRL */

/** A CRL's issuer, dates, revoked serials and signature parts. Null when malformed. */
function ds_crl_parse(string $der): ?array
{
    try {
        $der = ds_unpem($der);
        $c = ds_der_node($der);
        $tbs = $c['kids'][0];
        $k = $tbs['kids'];
        $i = ($k[0]['tag'] === 0x02) ? 1 : 0;              // optional version
        $i++;                                              // signature AlgorithmIdentifier
        $issuer = ds_der_raw($der, $k[$i++]);
        $thisUp = ds_der_time(ds_der_val($der, $k[$i]), $k[$i]['tag']);
        $i++;
        $next = null;
        if (isset($k[$i]) && in_array($k[$i]['tag'], [0x17, 0x18], true)) {
            $next = ds_der_time(ds_der_val($der, $k[$i]), $k[$i]['tag']);
            $i++;
        }
        $revoked = [];
        if (isset($k[$i]) && $k[$i]['tag'] === 0x30) {
            foreach ($k[$i]['kids'] as $r) {
                $revoked[ds_der_int_hex(ds_der_val($der, $r['kids'][0]))] = ds_der_time(ds_der_val($der, $r['kids'][1]), $r['kids'][1]['tag']);
            }
        }
        return ['issuer' => $issuer, 'this_update' => $thisUp, 'next_update' => $next, 'revoked' => $revoked,
                'tbs' => ds_der_raw($der, $tbs), 'sigalg' => ds_der_oid(ds_der_val($der, $c['kids'][1]['kids'][0])),
                'sig' => substr(ds_der_val($der, $c['kids'][2]), 1)];
    } catch (Throwable $e) {
        return null;
    }
}

/* ==================================================================== OCSP */

/** An OCSP request (RFC 6960) for one certificate, SHA-1 CertID as responders expect. */
function ds_ocsp_request(array $cert, array $issuer): string
{
    $certId = ds_der_seq(
        ds_der_seq(ds_der_oid_enc('1.3.14.3.2.26'), ds_der(0x05, '')),
        ds_der(0x04, sha1($cert['issuer'], true)),
        ds_der(0x04, sha1($issuer['key_bits'], true)),
        ds_der_int_from_hex($cert['serial'])
    );
    return ds_der_seq(ds_der_seq(ds_der_seq(ds_der_seq($certId))));
}

/**
 * Reads an OCSP response for one serial: status good|revoked|unknown, the
 * revocation time, and what is needed to check the responder's signature.
 * Null when the response is unusable.
 */
function ds_ocsp_parse(string $der, string $serialHex): ?array
{
    try {
        $r = ds_der_node($der);
        if ((int) ord(ds_der_val($der, $r['kids'][0])) !== 0 || !isset($r['kids'][1])) {
            return null;                                   // responseStatus not successful
        }
        $rb = $r['kids'][1]['kids'][0];                    // ResponseBytes
        if (ds_der_oid(ds_der_val($der, $rb['kids'][0])) !== '1.3.6.1.5.5.7.48.1.1') {
            return null;
        }
        $basicDer = ds_der_val($der, $rb['kids'][1]);
        $b = ds_der_node($basicDer);
        $tbs = $b['kids'][0];
        $certs = [];
        if (isset($b['kids'][3]) && $b['kids'][3]['tag'] === 0xa0) {
            foreach ($b['kids'][3]['kids'][0]['kids'] ?? [] as $c) {
                $certs[] = ds_der_raw($basicDer, $c);
            }
        }
        $k = $tbs['kids'];
        $i = ($k[0]['tag'] === 0xa0) ? 1 : 0;
        $i++;                                              // responderID
        $i++;                                              // producedAt
        $out = null;
        foreach ($k[$i]['kids'] as $single) {
            $cid = $single['kids'][0];
            if (ds_der_int_hex(ds_der_val($basicDer, $cid['kids'][3])) !== strtoupper($serialHex)) {
                continue;
            }
            $st = $single['kids'][1];
            $status = ['0' => 'good', '1' => 'revoked', '2' => 'unknown'][(string) $st['no']] ?? 'unknown';
            $when = null;
            if ($status === 'revoked' && isset($st['kids'][0])) {
                $when = ds_der_time(ds_der_val($basicDer, $st['kids'][0]), $st['kids'][0]['tag']);
            }
            $thisUp = ds_der_time(ds_der_val($basicDer, $single['kids'][2]), $single['kids'][2]['tag']);
            $out = ['status' => $status, 'revoked_at' => $when, 'this_update' => $thisUp];
        }
        if (!$out) {
            return null;
        }
        $out['tbs'] = ds_der_raw($basicDer, $tbs);
        $out['sigalg'] = ds_der_oid(ds_der_val($basicDer, $b['kids'][1]['kids'][0]));
        $out['sig'] = substr(ds_der_val($basicDer, $b['kids'][2]), 1);
        $out['certs'] = $certs;
        return $out;
    } catch (Throwable $e) {
        return null;
    }
}

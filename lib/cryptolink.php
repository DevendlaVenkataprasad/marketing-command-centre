<?php
/**
 * Sealed share links.
 *
 * A department's live sheet is reached by a link and nothing else — there is
 * no sign-in behind it — so the link itself has to carry the whole of the
 * security. Three things make that hold:
 *
 *   1. The secret in the link is 32 random bytes. Guessing one is not a
 *      realistic attack.
 *   2. Only a SHA-256 hash of that secret is stored. A dump of the database
 *      yields no working link, the same reason passwords are hashed.
 *   3. What travels in the URL is not the secret but an AES-256-GCM sealed
 *      blob of it, with the department id and an expiry bound in. GCM
 *      authenticates as well as encrypts, so a changed character does not
 *      decrypt to something else — it fails to decrypt at all, and a link
 *      edited by hand to point at another department is refused rather than
 *      honoured.
 *
 * Over HTTPS that gives an end-to-end encrypted link: sealed at rest in the
 * URL, encrypted in transit, and useless if the database is stolen.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/google.php';      // fabo_setting / fabo_setting_put

const FABO_SEAL_CIPHER = 'aes-256-gcm';

/**
 * The key everything is sealed with, 32 bytes.
 *
 * Kept in app_settings and generated on first use, so nobody has to edit a
 * config file to turn sharing on. Rotating it is deliberate and drastic: it
 * invalidates every link ever issued, which is exactly what you want after a
 * leak and never otherwise.
 */
function fabo_seal_key(): string
{
    static $key = null;
    if ($key !== null) {
        return $key;
    }
    $stored = fabo_setting('link_key');
    if ($stored !== '') {
        $raw = base64_decode($stored, true);
        if ($raw !== false && strlen($raw) === 32) {
            return $key = $raw;
        }
    }
    $raw = random_bytes(32);
    fabo_setting_put('link_key', base64_encode($raw));
    return $key = $raw;
}

/** URL-safe base64: no +, no /, no = to be mangled by a mail client. */
function fabo_b64url(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function fabo_b64url_decode(string $s): string
{
    $pad = strlen($s) % 4;
    return (string) base64_decode(strtr($s, '-_', '+/') . ($pad ? str_repeat('=', 4 - $pad) : ''), true);
}

/**
 * Seals an array into a token safe to put in a URL.
 *
 * Layout: iv (12) | tag (16) | ciphertext. Everything is needed to open it
 * and none of it is secret on its own.
 */
function fabo_seal(array $data): string
{
    $iv  = random_bytes(12);
    $tag = '';
    $ct  = openssl_encrypt(
        json_encode($data, JSON_UNESCAPED_SLASHES),
        FABO_SEAL_CIPHER,
        fabo_seal_key(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );
    if ($ct === false) {
        throw new RuntimeException('Could not seal the link.');
    }
    return fabo_b64url($iv . $tag . $ct);
}

/**
 * Opens a token, or null if it is not ours, has been altered, or is rubbish.
 *
 * Every failure returns the same null: a caller cannot tell a tampered token
 * from a made-up one, so the page cannot be used to probe for valid ones.
 */
function fabo_unseal(string $token): ?array
{
    /* Anything outside the URL-safe alphabet is noise a mail client or a
       chat app put there -- most often a space where it wrapped a long line,
       which arrives as %20. Left in, it threw the padding sum out and a
       perfectly good link read as withdrawn. A tampered character inside the
       alphabet still fails GCM below, so dropping noise opens nothing new. */
    $raw = fabo_b64url_decode((string) preg_replace('/[^A-Za-z0-9_-]/', '', $token));
    if (strlen($raw) < 29) {                 // 12 + 16 + at least one byte
        return null;
    }
    $iv  = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ct  = substr($raw, 28);

    $json = openssl_decrypt($ct, FABO_SEAL_CIPHER, fabo_seal_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($json === false) {
        return null;                          // wrong key, or tampered with
    }
    $data = json_decode($json, true);
    return is_array($data) ? $data : null;
}

/** A fresh secret and the hash to store beside it. */
function fabo_link_secret(): array
{
    $secret = bin2hex(random_bytes(32));
    return [$secret, hash('sha256', $secret)];
}

/** Constant-time check of a presented secret against what was stored. */
function fabo_link_secret_ok(string $secret, string $hash): bool
{
    return $hash !== '' && hash_equals($hash, hash('sha256', $secret));
}

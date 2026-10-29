<?php
/**
 * Marketing Command Centre - Central Marketing Reporting Portal
 * Local configuration. Values come from the environment when set, with
 * sensible local (XAMPP / WAMP / Laragon) defaults otherwise.
 */

function fabo_env(string $key, string $default): string
{
    $v = getenv($key);
    if ($v === false || $v === '') {
        return $default;
    }
    return $v;
}

/**
 * A password as it arrives from a config file, cleaned up.
 *
 * Every space is removed, not just the ones at the ends: Google prints an
 * app password in four groups of four and people paste what they see. No
 * mail password has a meaningful space in it, so taking them all out can
 * only turn a puzzling 535 into a working send.
 */
function fabo_secret(string $raw): string
{
    return preg_replace('~\s+~', '', $raw) ?? $raw;
}

// Local secrets (SMTP credentials and anything else you would not commit).
if (is_file(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}

define('DB_HOST', fabo_env('DB_HOST', '127.0.0.1'));
define('DB_PORT', (int) fabo_env('DB_PORT', '3306'));
define('DB_NAME', fabo_env('DB_NAME', 'fabo_marketing'));
define('DB_USER', fabo_env('DB_USER', 'root'));
define('DB_PASS', fabo_env('DB_PASS', ''));

/**
 * The portal's own name. It appears on admin screens, on the sign-in page and
 * in any message not sent on a client's behalf. A client's own dashboard and
 * their outgoing mail carry their organisation instead — see lib/brand.php.
 */
define('APP_NAME',    fabo_env('APP_NAME', 'Marketing Command Centre'));
define('APP_TAGLINE', fabo_env('APP_TAGLINE', 'Central Marketing Reporting'));

/**
 * What goes on an invoice: the address it is billed from, and where payment
 * should be sent. Both are free text — put them on as many lines as you like.
 */
define('APP_ADDRESS', fabo_env('APP_ADDRESS', ''));
define('APP_BANK',    fabo_env('APP_BANK', ''));
define('APP_TAXID',   fabo_env('APP_TAXID', ''));

// Base URL path the portal is served from, used when printing client links.
define('APP_BASE', fabo_env('APP_BASE', ''));

// The portal's own address, and the domain a client organisation's own
// address sits under (lib/orghost.php): e.g. ratan-tata-innovation-hub.mark-up.in.
define('PORTAL_HOST',   fabo_env('PORTAL_HOST', 'marketing-command-centre.mark-up.in'));
define('ORG_HOST_BASE', fabo_env('ORG_HOST_BASE', 'mark-up.in'));

// Outgoing mail. Leave SMTP_HOST empty to fall back to PHP's mail().
//
// The password has its spaces taken out because Google shows an app
// password as four groups of four — "abcd efgh ijkl mnop" — and it is
// copied that way far more often than not. The spaces are presentation,
// not part of the secret, and a password that fails for an invisible
// reason costs an afternoon.
define('SMTP_HOST',      fabo_env('SMTP_HOST', ''));
define('SMTP_PORT', (int) fabo_env('SMTP_PORT', '587'));
define('SMTP_USER',      fabo_env('SMTP_USER', ''));
define('SMTP_PASS', fabo_secret(fabo_env('SMTP_PASS', '')));
define('SMTP_FROM',      fabo_env('SMTP_FROM', ''));
define('SMTP_FROM_NAME', fabo_env('SMTP_FROM_NAME', 'Marketing Command Centre'));

/*
 * A second mailbox, used only when the first will not take a message.
 *
 * Shared hosting limits how much a mailbox may send in an hour, which is
 * fine for a handful of clients and not fine for a thousand. Setting these
 * to a transactional provider (Brevo, Zoho, SendGrid, Amazon SES) gives the
 * portal somewhere to go when the everyday mailbox says "slow down", and
 * nothing else in the portal has to know about it.
 *
 * Leave them empty and the portal behaves exactly as it did.
 */
define('SMTP2_HOST',      fabo_env('SMTP2_HOST', ''));
define('SMTP2_PORT', (int) fabo_env('SMTP2_PORT', '587'));
define('SMTP2_USER',      fabo_env('SMTP2_USER', ''));
define('SMTP2_PASS', fabo_secret(fabo_env('SMTP2_PASS', '')));
define('SMTP2_FROM',      fabo_env('SMTP2_FROM', ''));
define('SMTP2_FROM_NAME', fabo_env('SMTP2_FROM_NAME', ''));

/*
 * How many messages an hour the portal will send at most.
 *
 * 0 means no limit of our own — the mail server's limit is the only one.
 * Set it a little under whatever the provider allows and the portal paces
 * itself instead of being throttled, which is the difference between a
 * queue that drains and one that keeps being refused.
 */
define('MAIL_PER_HOUR', (int) fabo_env('MAIL_PER_HOUR', '0'));

/**
 * CA bundle used to verify the mail server's certificate. Windows PHP ships
 * without one, so a copy lives in certs/. Certificate checking stays ON.
 */
// Admin sign-in. With no hash set the admin area stays open, as it shipped.
define('ADMIN_EMAIL',     fabo_env('ADMIN_EMAIL', ''));
define('ADMIN_PASS_HASH', fabo_env('ADMIN_PASS_HASH', ''));

/**
 * Where the portal writes to the administrator: approval requests from the
 * team, deletion requests, invoice copies.
 *
 * Separate from ADMIN_EMAIL because that one is the sign-in name. The two are
 * usually the same address and this falls back to it, but they do not have to
 * be — the mailbox you read can move without changing how you sign in.
 */
define('ADMIN_INBOX_EMAIL', fabo_env('ADMIN_INBOX_EMAIL', '') ?: ADMIN_EMAIL);

/**
 * Google, for the meetings desk. All optional: with none of these set the
 * module still schedules meetings, emails invitations and keeps minutes —
 * it just cannot create the Calendar event or fetch a Meet transcript.
 *
 * The refresh token is NOT here: Google hands that over during the connect
 * flow, so it is stored at runtime in app_settings.
 */
define('GOOGLE_CLIENT_ID',     fabo_env('GOOGLE_CLIENT_ID', ''));
define('GOOGLE_CLIENT_SECRET', fabo_env('GOOGLE_CLIENT_SECRET', ''));

/** Gemini writes the minutes from a transcript. Also optional. */
define('GEMINI_API_KEY', fabo_env('GEMINI_API_KEY', ''));

/*
 * The key cron.php wants when it is reached over the web.
 *
 * Hostinger's cron runs PHP directly and needs none of this. It matters only
 * for hosts that can fetch a URL but not run a command: with no key set the
 * web route answers 404 and the command line still works, which is the safer
 * way round.
 */
define('CRON_KEY', fabo_env('CRON_KEY', ''));
/*
 * An alias, not a version. Google retires numbered models — 2.0-flash was
 * withdrawn and started answering with an error rather than minutes — and a
 * portal that runs for years cannot depend on a name with a number in it.
 * Set GEMINI_MODEL explicitly to pin a particular one.
 */
define('GEMINI_MODEL',   fabo_env('GEMINI_MODEL', 'gemini-flash-latest'));

define('SMTP_CAFILE', fabo_env('SMTP_CAFILE', __DIR__ . '/certs/cacert.pem'));

date_default_timezone_set(fabo_env('APP_TZ', 'Asia/Kolkata'));

// Show generic errors on screen; detail goes to error_log.
ini_set('display_errors', '0');
error_reporting(E_ALL);

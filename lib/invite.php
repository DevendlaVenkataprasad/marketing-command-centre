<?php
/**
 * Invitations: a link that lets one account set its own password.
 *
 * The portal used to generate a password and email it. That has two faults.
 * A password in an inbox is a password anybody the mail is forwarded to can
 * use; and when several accounts share a mailbox — one person running five
 * companies, which is the ordinary case here — a message saying "your
 * password is X" cannot say *which* of their accounts it belongs to.
 *
 * An invitation fixes both. It belongs to one account, it is used once, and
 * what it produces is a password only that person ever knows. Nothing the
 * portal sends can be used to sign in by itself: the link only opens a form.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

/** How long an invitation stays good for. Long enough to be read at leisure. */
const FABO_INVITE_DAYS = 14;

/**
 * Mints an invitation for one account and returns its token.
 *
 * Any earlier unused invitation for the same account is spent first: two
 * live links to the same account means the older mail still works after the
 * newer one has been used, which is exactly the surprise nobody wants.
 */
function fabo_invite_make(int $clientId, int $days = FABO_INVITE_DAYS): string
{
    if ($clientId <= 0) {
        return '';
    }
    try {
        fabo_exec('UPDATE password_invites SET used_at = NOW()
                   WHERE client_id = ? AND used_at IS NULL', 'i', [$clientId]);

        $token = bin2hex(random_bytes(32));
        fabo_exec('INSERT INTO password_invites (client_id, token, expires_at)
                   VALUES (?,?,?)', 'iss',
                  [$clientId, $token,
                   date('Y-m-d H:i:s', time() + max(1, $days) * 86400)]);
        return $token;
    } catch (Throwable $e) {
        error_log('FABO invite make: ' . $e->getMessage());
        return '';
    }
}

/** The address an invitation is opened at. */
function fabo_invite_url(string $token): string
{
    if (trim($token) === '') {
        return '';
    }
    require_once __DIR__ . '/ui.php';
    return rtrim(fabo_portal_url(), '/') . '/setpassword.php?t=' . rawurlencode($token);
}

/** Mints one and returns the link in a single step. */
function fabo_invite_link(int $clientId, int $days = FABO_INVITE_DAYS): string
{
    $t = fabo_invite_make($clientId, $days);
    return $t === '' ? '' : fabo_invite_url($t);
}

/**
 * The account an invitation opens, or null.
 *
 * Null covers every way a link can be no good — unknown, already used, out
 * of date, or belonging to an account that has since been closed. The page
 * says the same thing for all of them: ask for a new link. Telling a
 * stranger which of those it was would be telling them something.
 */
function fabo_invite_open(string $token): ?array
{
    $token = trim($token);
    if (!preg_match('~^[0-9a-f]{64}$~', $token)) {
        return null;
    }
    try {
        $in = fabo_one('SELECT * FROM password_invites
                        WHERE token = ? AND used_at IS NULL
                          AND expires_at > NOW()', 's', [$token]);
    } catch (Throwable $e) {
        return null;
    }
    if (!$in) {
        return null;
    }
    $person = fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [(int) $in['client_id']]);
    if (!$person || fabo_client_state($person) !== 'ok') {
        return null;
    }
    // The invitation's own id travels with the person, so spending it later
    // does not need a second lookup by a token the caller may have dropped.
    $person['invite_id'] = (int) $in['id'];
    return $person;
}

/**
 * Spends an invitation and sets the password it was issued for.
 *
 * Returns [ok, message, person]. The password is theirs from this moment:
 * nothing the portal has sent them will sign in on its own again.
 */
function fabo_invite_use(string $token, string $new, string $again): array
{
    $person = fabo_invite_open($token);
    if (!$person) {
        return [false, 'That link has been used already, or it has expired. '
                     . 'Ask for a new one and it will be sent to you.', null];
    }
    if (mb_strlen($new) < 8) {
        return [false, 'Choose a password of at least 8 characters.', $person];
    }
    if ($new !== $again) {
        return [false, 'The two passwords do not match.', $person];
    }

    fabo_set_password((int) $person['id'], $new);
    // Their own choice, so there is nothing to make them change on the way in.
    fabo_exec('UPDATE clients SET must_change = 0 WHERE id = ?', 'i', [(int) $person['id']]);
    fabo_exec('UPDATE password_invites SET used_at = NOW() WHERE id = ?',
              'i', [(int) $person['invite_id']]);

    require_once __DIR__ . '/notify.php';
    fabo_notify((int) $person['id'], 'Your password is set',
                'You chose it yourself from the link in your invitation.', '');

    return [true, 'Your password is set.', fabo_one('SELECT * FROM clients WHERE id = ?',
                                                    'i', [(int) $person['id']])];
}

/** Is there a live invitation for this account? For the manage page to say so. */
function fabo_invite_live(int $clientId): ?array
{
    try {
        return fabo_one('SELECT * FROM password_invites
                         WHERE client_id = ? AND used_at IS NULL AND expires_at > NOW()
                         ORDER BY id DESC LIMIT 1', 'i', [$clientId]);
    } catch (Throwable $e) {
        return null;
    }
}

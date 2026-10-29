<?php
/**
 * Forgotten passwords, by one-time code.
 *
 * Three steps, each guarded on its own: ask for the address, prove you can
 * read that mailbox, then set a new password. A few rules make that safe:
 *
 *   - The answer to "is this address on file?" is always the same sentence,
 *     so the page cannot be used to find out who has an account.
 *   - Only a hash of the code is stored; the code itself exists in the email.
 *   - It expires, it is single use, and it counts its own attempts.
 *   - Which reset is in progress lives in the session, not in the URL, so a
 *     code alone is not enough to reach the new-password step.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/notify.php';

const FABO_RESET_TTL      = 600;   // ten minutes
const FABO_RESET_ATTEMPTS = 5;
const FABO_RESET_COOLDOWN = 60;    // between one code and the next

/** The sentence every "send me a code" attempt gets, found or not. */
function fabo_reset_sent_message(): string
{
    return 'If that address has an account, a 6-digit code is on its way. '
         . 'It is good for 10 minutes.';
}

/** A fresh code. Digits only — it gets typed off a phone screen. */
function fabo_reset_code(): string
{
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

/**
 * Starts a reset. Returns [ok, message, resetId].
 *
 * `ok` says whether to move the person to the code step — which is true even
 * for an address with no account, because saying otherwise would give the game
 * away. In that case no code exists and no email is sent.
 */
function fabo_reset_start(string $email): array
{
    $email = trim($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return [false, 'Enter the email address you sign in with.', 0];
    }

    $person = fabo_one('SELECT * FROM clients WHERE email = ? ORDER BY id LIMIT 1', 's', [$email]);
    if (!$person || fabo_client_state($person) !== 'ok') {
        return [true, fabo_reset_sent_message(), 0];
    }

    // One code a minute, so the form cannot be used to flood a mailbox.
    $recent = fabo_one('SELECT created_at FROM password_resets
                        WHERE client_id = ? ORDER BY id DESC LIMIT 1', 'i', [(int) $person['id']]);
    if ($recent && (time() - strtotime($recent['created_at'])) < FABO_RESET_COOLDOWN) {
        return [true, fabo_reset_sent_message(), 0];
    }

    // Any code still outstanding for this person stops working now.
    fabo_exec('UPDATE password_resets SET used_at = NOW()
               WHERE client_id = ? AND used_at IS NULL', 'i', [(int) $person['id']]);

    $code = fabo_reset_code();
    $id   = fabo_exec('INSERT INTO password_resets (client_id, email, code_hash, expires_at)
                       VALUES (?,?,?,?)', 'isss',
                      [(int) $person['id'], $email, password_hash($code, PASSWORD_DEFAULT),
                       date('Y-m-d H:i:s', time() + FABO_RESET_TTL)]);

    [$subject, $body, $html] = fabo_reset_message($person, $code);
    fabo_send_mail($email, $subject, $body, null, $html);   // the portal's own mailbox

    return [true, fabo_reset_sent_message(), $id];
}

/** The email carrying the code. */
function fabo_reset_message(array $person, string $code): array
{
    $brand = fabo_owner_brand($person);
    $mins  = (int) (FABO_RESET_TTL / 60);

    $subject = 'Your ' . $brand . ' sign-in code: ' . $code;

    require_once __DIR__ . '/mailtpl.php';

    /* ---- plain text ---- */
    $body = 'Hi ' . $person['name'] . ",\n\n"
          . "Someone asked to reset the password on your account. Here is the code:\n\n"
          . '    ' . $code . "\n\n"
          . 'It is good for ' . $mins . " minutes and can only be used once.\n\n"
          . "Type it on the page you started from, then choose a new password.\n\n"
          . "If this was not you, you can ignore this message — nothing has changed,\n"
          . 'and your current password still works.'
          . fabo_sign_off(fabo_app_name());

    /*
     * No link at all on this one, deliberately. A reset message is exactly
     * what a phishing attempt imitates, so the only safe instruction is to
     * go back to the page you already had open — a link here would train
     * people to click links in messages of this shape.
     */
    $inner = fabo_mail_p('Hi ' . e((string) $person['name']) . ',')
           . fabo_mail_p('Someone asked to reset the password on your account. '
                       . 'Here is the code:')
           . fabo_mail_big($code, 'Good for ' . $mins . ' minutes, and only once')
           . fabo_mail_p('Type it on the page you started from, then choose a new password.')
           . fabo_mail_note('If this was not you, ignore this message. Nothing has changed '
                          . 'and your current password still works.');

    return [$subject, $body,
            fabo_mail_wrap(fabo_app_name(), 'Your sign-in code', $inner,
                           'Code ' . $code . ', good for ' . $mins . ' minutes')];
}

/** The reset in progress, if it is still good for anything. */
function fabo_reset_load(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    $r = fabo_one('SELECT * FROM password_resets WHERE id = ?', 'i', [$id]);
    if (!$r || $r['used_at'] !== null || strtotime($r['expires_at']) < time()) {
        return null;
    }
    return $r;
}

/**
 * Checks a typed code. Returns [ok, message].
 * A wrong code is counted; too many and the code is spent.
 */
function fabo_reset_check(int $id, string $code): array
{
    /* Every failure gets the same sentence. It used to say "expired" for an
       address with no account and "N tries left" for a real one, so typing
       any code told a stranger whether an address was registered. */
    $nope = [false, 'That code is not right, or it has expired. Check the email, or ask for a new code.'];
    $code = preg_replace('/\D+/', '', $code);
    if ($code === '') {
        return [false, 'Enter the 6-digit code from the email.'];
    }
    $r = fabo_reset_load($id);
    if (!$r) {
        // The same work as a real check, so the answer takes as long.
        static $standIn = null;
        $standIn ??= password_hash('stand-in', PASSWORD_DEFAULT);
        password_verify($code, $standIn);
        return $nope;
    }

    /* The try is counted BEFORE the code is checked, in one statement that
       only succeeds while tries remain. Reading the count, checking, then
       adding one let parallel requests all see "4 left" and guess at once. */
    $counted = fabo_exec('UPDATE password_resets SET attempts = attempts + 1
                           WHERE id = ? AND used_at IS NULL AND attempts < ?', 'ii', [$id, FABO_RESET_ATTEMPTS]);
    if ($counted < 1) {
        fabo_exec('UPDATE password_resets SET used_at = NOW() WHERE id = ?', 'i', [$id]);
        return $nope;
    }
    if (!password_verify($code, $r['code_hash'])) {
        return $nope;
    }

    fabo_exec('UPDATE password_resets SET verified_at = NOW(), attempts = 0 WHERE id = ?', 'i', [$id]);
    return [true, 'Code accepted. Choose your new password.'];
}

/** Has this reset been proved? Only then may a password be set. */
function fabo_reset_verified(int $id): ?array
{
    $r = fabo_reset_load($id);
    return ($r && $r['verified_at'] !== null) ? $r : null;
}

/**
 * Sets the new password and spends the code. Returns [ok, message, person].
 */
function fabo_reset_complete(int $id, string $new, string $again): array
{
    $r = fabo_reset_verified($id);
    if (!$r) {
        return [false, 'That code has expired. Start again.', null];
    }
    if (mb_strlen($new) < 8) {
        return [false, 'Choose a password of at least 8 characters.', null];
    }
    if ($new !== $again) {
        return [false, 'The two passwords do not match.', null];
    }
    $person = fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [(int) $r['client_id']]);
    if (!$person || fabo_client_state($person) !== 'ok') {
        return [false, 'That account is no longer active.', null];
    }
    /* A password another account on this address already uses is allowed —
       sign-in is by user ID, and that means one account whatever the address
       and the password say. It is worth mentioning, though: from then on the
       address alone will not get them in. */
    $shared = function_exists('fabo_password_taken')
            && fabo_password_taken((string) $person['email'], $new, (int) $person['id']);

    fabo_exec('UPDATE clients SET password_hash = ?, must_change = 0 WHERE id = ?', 'si',
              [password_hash($new, PASSWORD_DEFAULT), (int) $person['id']]);
    fabo_exec('UPDATE password_resets SET used_at = NOW() WHERE id = ?', 'i', [$id]);

    // Tell them on their own dashboard, so a reset they did not ask for is
    // visible the next time they sign in.
    fabo_notify((int) $person['id'], 'Your password was changed',
                'It was reset with a code sent to ' . $person['email'] . '.', '');

    return [true, 'Your password has been changed.'
        . ($shared
           ? ' Somebody else on this email address uses the same password, so sign in '
             . 'with your user ID (' . (string) ($person['login_id'] ?? '') . ') rather '
             . 'than the address — the address alone cannot tell the two apart.'
           : ''), $person];
}

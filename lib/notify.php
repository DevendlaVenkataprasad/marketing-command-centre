<?php
/**
 * Portal notifications and outgoing mail.
 *
 * Every notice is written to the recipient's portal first — that always works
 * and is what they see on their dashboard. Email is then attempted; on a local
 * box with no mail transport that fails, and the caller is told so it can offer
 * a manual send. Nothing is ever silently lost.
 *
 * Two names matter in every message and they are not the same one:
 *   - the portal the recipient is being sent to, which carries THEIR
 *     organisation (a client never reads our name on their own dashboard);
 *   - who the message is from, which is the portal for anything we send, and
 *     the client's own organisation for anything they send to their people.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/brand.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/rules.php';      // fabo_sheet_label()
require_once __DIR__ . '/approvals.php';  // fabo_approval_label()
require_once __DIR__ . '/ui.php';         // fabo_portal_url()
require_once __DIR__ . '/mailtpl.php';    // the pieces every message is built from

/*
 * A stand-in, used only if lib/mailtpl.php is older than this file.
 *
 * Uploading one file without the other should cost a plain-looking button,
 * not a fatal error on the page that was trying to send. The real one, when
 * it is there, is defined first and this is skipped.
 */
if (!function_exists('fabo_mail_own_link')) {
    function fabo_mail_own_link(string $url, string $label, string $note = ''): string
    {
        if (trim($url) === '') {
            return '';
        }
        return '<p style="margin:0 0 6px;font-size:14px;"><a href="' . e($url) . '">'
             . e($label) . '</a></p>'
             . ($note !== '' ? '<p style="margin:0 0 6px;font-size:12.5px;color:#68707f;">'
                             . e($note) . '</p>' : '')
             . '<p style="margin:0 0 18px;font-size:12px;color:#68707f;word-break:break-all;">'
             . e($url) . '</p>';
    }
}

/**
 * The admin has no row in `clients`, so their notices are filed under id 0.
 * Nothing else can ever be 0, which keeps the one inbox table serving everyone.
 */
const FABO_ADMIN_INBOX = 0;

/** Whose inbox a viewer reads: their own id, or the admin's. */
function fabo_inbox_id(array $viewer): int
{
    return !empty($viewer['is_admin']) ? FABO_ADMIN_INBOX : (int) $viewer['client']['id'];
}

/**
 * Where a notice about one row should take its reader.
 *
 * Two things decide it, and both are easy to get wrong:
 *
 *   WHOSE inbox it is. The admin's grid is admin/sheet.php; a client's is
 *   c.php. There is no sheet.php at the root - that is the sheet LIST.
 *
 *   WHICH tab the row is on. Events opens on its Dashboard unless the link
 *   says otherwise, so a link without a view lands somewhere the row is not.
 *
 * $inbox is the client id the notice is filed under, with 0 meaning ours
 * (FABO_ADMIN_INBOX).
 */
function fabo_row_link(int $inbox, string $sheetKey, int $rowId, string $view = ''): string
{
    $q = ['sheet' => $sheetKey, 'row' => $rowId];

    /* Sheets with tabs need to be told which one. Events is the only one so
       far, and its rows live under Programs Data. */
    if ($view !== '') {
        $q['view'] = $view;
    } elseif ($sheetKey === 'events') {
        $q['view'] = 'programs';
    }

    return ($inbox === FABO_ADMIN_INBOX ? 'admin/sheet.php?' : 'c.php?')
         . http_build_query($q);
}

/** Adds a notice to someone's portal. Returns the new id. */
function fabo_notify(int $clientId, string $title, string $body = '', string $link = ''): int
{
    return fabo_exec('INSERT INTO notifications (client_id, title, body, link) VALUES (?,?,?,?)',
                     'isss', [$clientId, mb_substr($title, 0, 190), $body, mb_substr($link, 0, 255)]);
}

/** The same, for the admin's own inbox. */
function fabo_notify_admin(string $title, string $body = '', string $link = ''): int
{
    return fabo_notify(FABO_ADMIN_INBOX, $title, $body, $link);
}

function fabo_notifications(int $clientId, bool $unreadOnly = false, int $limit = 50): array
{
    $sql = 'SELECT * FROM notifications WHERE client_id = ?'
         . ($unreadOnly ? ' AND read_at IS NULL' : '')
         . ' ORDER BY created_at DESC, id DESC LIMIT ' . max(1, min(500, $limit));
    return fabo_all($sql, 'i', [$clientId]);
}

function fabo_unread_count(int $clientId): int
{
    $r = fabo_one('SELECT COUNT(*) AS n FROM notifications WHERE client_id = ? AND read_at IS NULL',
                  'i', [$clientId]);
    return (int) ($r['n'] ?? 0);
}

/** Marks everything in one inbox as read. */
function fabo_mark_read(int $clientId): void
{
    fabo_exec('UPDATE notifications SET read_at = NOW() WHERE client_id = ? AND read_at IS NULL',
              'i', [$clientId]);
}

/**
 * Marks one notice read or unread. The inbox id is part of the WHERE, so a
 * guessed notification id belonging to someone else does nothing.
 */
function fabo_mark_one(int $clientId, int $noticeId, bool $read): void
{
    fabo_exec('UPDATE notifications SET read_at = ' . ($read ? 'NOW()' : 'NULL')
            . ' WHERE id = ? AND client_id = ?', 'ii', [$noticeId, $clientId]);
}

/** Deletes one notice from an inbox, or every notice in it. */
function fabo_delete_notice(int $clientId, int $noticeId): void
{
    fabo_exec('DELETE FROM notifications WHERE id = ? AND client_id = ?', 'ii', [$noticeId, $clientId]);
}

function fabo_clear_notices(int $clientId, bool $readOnly = true): void
{
    fabo_exec('DELETE FROM notifications WHERE client_id = ?'
            . ($readOnly ? ' AND read_at IS NOT NULL' : ''), 'i', [$clientId]);
}

/* ------------------------------------------------------------- senders */

/**
 * Which mailbox a message leaves from.
 *
 * $actor is the client sending it, or null when the portal itself is. A client
 * with their own SMTP details sends as themselves; otherwise we relay from the
 * portal mailbox but put their organisation on the From line and their address
 * on Reply-To, so the recipient still sees and answers them.
 *
 * $actor['send_as_name'], when set, is the name the From line carries instead
 * of the organisation (Incubation Management mails as the organisation's owner).
 */
function fabo_sender_for(?array $actor): array
{
    if (!$actor) {
        return fabo_portal_sender();
    }
    // A sub-account sends through whatever its parent set up, so a whole client
    // team writes from one mailbox without each person configuring it.
    $own = $actor;
    if (trim((string) ($actor['smtp_host'] ?? '')) === '' && !empty($actor['parent_id'])) {
        $parent = fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [(int) $actor['parent_id']]);
        if ($parent) {
            $own = $parent;
        }
    }

    $brand = trim((string) ($actor['send_as_name'] ?? '')) ?: fabo_owner_brand($actor);
    $host  = trim((string) ($own['smtp_host'] ?? ''));

    // Sending from their own mailbox is a granted right, exactly like a sheet.
    // Without it the details are ignored and the portal relays for them.
    if (empty($own['can_use_own_mail'])) {
        $host = '';
    }

    if ($host !== '' && trim((string) ($own['smtp_user'] ?? '')) !== '') {
        $from = trim((string) ($own['smtp_from'] ?? '')) ?: trim((string) ($own['smtp_user'] ?? ''));
        return [
            'host'      => $host,
            'port'      => (int) ($own['smtp_port'] ?? 587) ?: 587,
            'user'      => (string) $own['smtp_user'],
            'pass'      => (string) ($own['smtp_pass'] ?? ''),
            'from'      => $from,
            'from_name' => trim((string) ($own['smtp_from_name'] ?? '')) ?: $brand,
            'reply_to'  => trim((string) ($actor['email'] ?? '')),
            // Whose message it is, so the portal's name in it becomes theirs (fabo_mail_brand_for()).
            'brand'     => fabo_owner_brand($actor),
        ];
    }

    $portal = fabo_portal_sender();
    $portal['from_name'] = $brand;
    $portal['reply_to']  = trim((string) ($actor['email'] ?? ''));
    $portal['brand']     = fabo_owner_brand($actor);
    return $portal;
}

/**
 * Who a calendar invitation is sent as. Always the portal's own mailbox,
 * because that address is the invitation's ORGANIZER: Gmail shows its
 * Yes / No / Maybe card for an invitation sent by its organiser, and the
 * guest's answer is mailed to the organiser, which is the mailbox the
 * portal reads replies from. The client's name and reply address are kept,
 * so it still reads as theirs.
 */
function fabo_invite_sender(?array $actor): array
{
    $theirs = fabo_sender_for($actor);
    $portal = fabo_portal_sender();
    $portal['from_name'] = (string) ($theirs['from_name'] ?? $portal['from_name']);
    $reply = trim((string) ($theirs['reply_to'] ?? '')) ?: (string) ($theirs['from'] ?? '');
    $portal['reply_to'] = strcasecmp($reply, (string) $portal['from']) === 0 ? '' : $reply;
    $portal['brand'] = (string) ($theirs['brand'] ?? '');
    return $portal;
}

/** The name a message signs off with: the portal, or the client sending it. */
function fabo_sender_brand(?array $actor): string
{
    return $actor ? fabo_owner_brand($actor) : fabo_app_name();
}

/**
 * Tries to send an email. Returns [sent, detail]. Never throws, never stops
 * the page: a portal notice has already been written by the caller.
 */
function fabo_send_mail(string $to, string $subject, string $body, ?array $actor = null,
                        string $html = ''): array
{
    try {
        /* Sent with no organisation in mind, to one of a client organisation's
           people: it is that organisation's message, from its own mailbox when
           it may use one. See fabo_mail_recipient_org(). */
        if (!$actor && ($org = fabo_mail_recipient_org($to))) {
            $actor = $org;
        }
        return fabo_smtp_send($to, $subject, $body, fabo_sender_for($actor), $html);
    } catch (Throwable $e) {
        error_log('FABO mail failed: ' . $e->getMessage());
        return [false, 'the mail server could not be reached'];
    }
}

/* ------------------------------------------------------------ messages */

/** The sheet list that goes into a welcome or access message. */
function fabo_access_lines(array $access): array
{
    $lines = [];
    foreach ($access as $key => $perm) {
        $lines[] = '  - ' . fabo_grantable_label($key)
                 . ($perm === 'edit' ? '  (you can add and edit rows)' : '  (view only)');
    }
    return $lines;
}

/**
 * The message a member or client gets when their sheets change.
 * Lists exactly what they can now open, and links to their portal.
 */
function fabo_access_message(array $person, array $access, string $url, ?array $actor = null): array
{
    require_once __DIR__ . '/mailtpl.php';
    unset($url);        // no dashboard link in a message — see fabo_welcome_message()

    $isTeam = ($person['kind'] ?? 'client') === 'team';
    $brand  = fabo_owner_brand($person);
    $from   = fabo_sender_brand($actor);
    $lines  = fabo_access_lines($access);
    $home   = fabo_mail_home();
    /* Signed in by user ID: an address may belong to several accounts. */
    $loginId   = trim((string) ($person['login_id'] ?? ''));
    $signInUrl = $home . ($loginId !== '' ? '?u=' . rawurlencode($loginId) : '');

    $subject = $isTeam
        ? 'Your ' . $brand . ' work portal — sheets assigned to you'
        : 'Your ' . $brand . ' reporting portal — sheets shared with you';

    $until = !empty($person['expires_at'])
        ? date('d M Y', strtotime((string) $person['expires_at'])) : '';

    /* ---- plain text ---- */
    $body = 'Hi ' . $person['name'] . ",\n\n"
          . ($isTeam ? "What you can open has changed.\n\n"
                     : "What has been shared with you has changed.\n\n")
          . ($lines ? "YOU CAN NOW OPEN\n" . implode("\n", $lines) . "\n"
                    : "Nothing is assigned to you yet — you will get another note when it is.\n")
          . ($isTeam ? "\nYour dashboard opens on My Work: everything assigned to you, with what is due.\n" : '')
          . "
SIGN IN
  " . $signInUrl . "
"
          . ($loginId !== '' ? '  Your user ID is ' . $loginId . ".
"
                             : '  Your email address is ' . $person['email'] . ".
")
          . ($until !== '' ? "\nYour access runs until " . $until . ".\n" : '')
          . fabo_sign_off($from);

    /* ---- the same thing, laid out ---- */
    $htmlLines = [];
    foreach ($access as $key => $perm) {
        $htmlLines[] = '<strong>' . e(fabo_grantable_label($key)) . '</strong>'
                     . ' <span style="color:' . fabo_mail_soft() . ';font-size:13px;">'
                     . ($perm === 'edit' ? 'add and edit rows' : 'view only') . '</span>';
    }
    $inner = fabo_mail_p('Hi ' . e((string) $person['name']) . ',')
           . fabo_mail_p($isTeam
               ? 'What you can open has changed.'
               : 'What has been shared with you has changed.')
           . ($htmlLines
              ? fabo_mail_p('<strong>You can now open</strong>') . fabo_mail_list($htmlLines)
              : fabo_mail_note('Nothing is assigned to you yet — you will get another note when it is.'))
           . ($isTeam
              ? fabo_mail_note('Your dashboard opens on My Work: everything assigned to you, with what is due.')
              : '')
           . fabo_mail_own_link($signInUrl, 'Sign in with your user ID',
               $loginId !== '' ? 'Opens the sign-in page with ' . $loginId . ' filled in.' : '')
           . fabo_mail_note(($loginId !== ''
               ? 'Sign in with your user ID <strong>' . e($loginId) . '</strong> rather than '
                 . 'your email address, which may belong to more than one account.'
               : 'Sign in with <strong>' . e((string) $person['email']) . '</strong>.')
               . ($until !== '' ? ' Your access runs until ' . e($until) . '.' : ''));

    return [$subject, $body, fabo_mail_wrap($from, $isTeam ? 'Your sheets have changed'
                                                          : 'Your access has changed', $inner,
                                            $subject)];
}

/**
 * The welcome message: the portal link AND the sign-in credentials.
 * The password is shown once here, because only its hash is stored.
 */
function fabo_welcome_message(array $person, array $access, string $url, string $loginUrl,
                              ?string $password, ?array $actor = null): array
{
    /* $url and $loginUrl are accepted and unused: they were the account's
       private dashboard link and the plain front door, and no message sends
       either any more. The only link in a message is the sign-in page with
       this account's user ID on it, built below. Kept in the signature so
       the callers do not all have to change, and written down here so the
       reason is where somebody would look before putting them back. */
    unset($url, $loginUrl);
    $isTeam = ($person['kind'] ?? 'client') === 'team';
    $brand  = fabo_owner_brand($person);
    $lines  = fabo_access_lines($access);
    /* An address can belong to more than one account, so the message carries
       the one thing that belongs to this account alone. */
    /* Every account has a user ID, and the welcome is the one message that
       must carry it — so it is resolved here rather than trusted to be on
       the row the caller happened to read. */
    $loginId = trim((string) ($person['login_id'] ?? ''));
    if ($loginId === '' && (int) ($person['id'] ?? 0) > 0) {
        require_once __DIR__ . '/auth.php';
        $loginId = fabo_login_id((int) $person['id']);
    }
    /* No invitation link, and no link of any kind that expires. The account
       is opened with a password printed in this message and the ordinary
       sign-in page, because a link that works once and then dies is a link
       people click late and then write in about.

       The password is temporary in the sense that matters: the sign-in page
       makes them replace it the first time they use it, and only the hash of
       what they choose is ever stored. */
    $pass = (string) ($password ?? '');

    /* The user ID is in the subject because one mailbox can hold several
       accounts: without it, two people sharing an address get two messages
       that look identical in the list, and a mail client may thread or
       collapse them into one. */
    $tag = $loginId !== '' ? ' [' . $loginId . ']' : '';
    $subject = ($isTeam
        ? 'Your ' . $brand . ' work portal — sign-in details'
        : 'Your ' . $brand . ' reporting dashboard — sign-in details') . $tag;

    require_once __DIR__ . '/mailtpl.php';
    $from  = fabo_sender_brand($actor);
    $home  = fabo_mail_home();
    /* The sign-in page, told which account is coming: several accounts
       can share a mailbox, so the link says which one is meant. */
    $signInUrl = $home . ($loginId !== '' ? '?u=' . rawurlencode($loginId) : '');
    $until = !empty($person['expires_at'])
        ? date('d M Y', strtotime((string) $person['expires_at'])) : '';

    /* ---- plain text ---- */
    $body = 'Hi ' . $person['name'] . ",\n\n"
          . ($isTeam ? "Your work portal is ready.\n\n"
                     : "Your reporting dashboard is ready.\n\n")
          . "YOUR DETAILS\n"
          . '  Email:    ' . $person['email'] . "\n"
          . ($loginId !== '' ? '  User ID:  ' . $loginId . "\n" : '')
          . ($pass !== '' ? '  Password: ' . $pass . "\n" : '')
          . ($loginId !== ''
             ? "\nSIGN IN AT\n  " . $signInUrl . "\n" : '')
          . ($pass !== ''
             ? "\nYou will be asked to choose your own password the first time.\n" : '')
          . ($lines ? "\nYOUR SHEETS\n" . implode("\n", $lines) . "\n"
                    : "\nNo sheets have been assigned yet — you will get another note when they are.\n")
          . ($isTeam ? "\nYour dashboard opens on My Work: everything assigned to you, with what is due.\n" : '')
          . "\nNobody else knows the password you choose, including us.\n"
          . ($until !== '' ? "\nYour access runs until " . $until . '.' : '')
          . fabo_sign_off($from);

    /* ---- the same thing, laid out ---- */
    $htmlLines = [];
    foreach ($access as $key => $perm) {
        $htmlLines[] = '<strong>' . e(fabo_grantable_label($key)) . '</strong>'
                     . ' <span style="color:' . fabo_mail_soft() . ';font-size:13px;">'
                     . ($perm === 'edit' ? 'add and edit rows' : 'view only') . '</span>';
    }
    $inner = fabo_mail_p('Hi ' . e((string) $person['name']) . ',')
           . fabo_mail_p($isTeam ? 'Your work portal is ready.'
                                 : 'Your reporting dashboard is ready.')
           . fabo_mail_rows(array_filter([
                 'Email'   => '<strong>' . e((string) $person['email']) . '</strong>',
                 'User ID' => $loginId !== ''
                     ? '<span style="font-family:Consolas,Menlo,monospace;font-size:15px;">'
                       . e($loginId) . '</span>' : '',
             ]))
           /* The password sits with the user ID and the address, because
              all three are the one thing: what to type on the sign-in page.
              It is worth nothing on its own after the first use — that page
              asks for a replacement before it lets anybody through. */
           . ($pass !== ''
              ? fabo_mail_rows([
                    'Password' => '<span style="font-family:Consolas,Menlo,monospace;'
                                . 'font-size:15px;">' . e($pass) . '</span>',
                ])
                . fabo_mail_note('You will be asked to choose your own password the '
                    . 'first time you sign in.')
              : '')
           /* One link, and it is the sign-in page — the front door, which is
              there tomorrow and next year. A link that opens a dashboard
              without asking for anything is a key in an email: anybody the
              message is forwarded to holds the account. And a link that works
              once and then expires is one people click a week late and then
              write in about. So this is the only one, and it is permanent.

              It carries the user ID because several accounts can share a
              mailbox, and a link that says which account is meant is the
              difference between signing in and guessing. */
           . ($loginId !== ''
              ? fabo_mail_own_link($signInUrl, 'Sign in with your user ID',
                  'Sign in with the user ID above rather than your email address: '
                . 'the address may belong to several accounts, the user ID only '
                . 'ever to this one.')
              : '')
           . ($htmlLines
              ? fabo_mail_p('<strong>Your sheets</strong>') . fabo_mail_list($htmlLines)
              : fabo_mail_note('No sheets have been assigned yet — you will get another note when they are.'))
           . ($isTeam
              ? fabo_mail_note('Your dashboard opens on My Work: everything assigned to you, with what is due.')
              : '')
           . fabo_mail_note('Nobody else knows the password you choose, including us.'
               . ($until !== '' ? ' Your access runs until ' . e($until) . '.' : ''));

    return [$subject, $body,
            fabo_mail_wrap($from, $isTeam ? 'Your work portal is ready'
                                          : 'Your dashboard is ready', $inner, $subject)];
}

/**
 * The message for somebody whose name or address has been changed for them.
 *
 * An account's email IS its identity here — it is what they sign in with — so
 * moving it is not an edit to a contact card, it is handing the account to a
 * different address. That address has never seen a password, and only the
 * hash of the old one is stored, so a new one is issued and shown here once.
 */
function fabo_identity_message(array $person, array $access, ?string $password,
                               string $wasEmail, string $wasName, ?array $actor = null,
                               string $ownUrl = ''): array
{
    /* $ownUrl is accepted and deliberately unused: it is the account's
       private dashboard link, and no message sends one any more. Kept in the
       signature so the callers that pass it do not have to change, and so
       the reason is written down where somebody would think to add it back. */
    unset($ownUrl);
    $isTeam = ($person['kind'] ?? 'client') === 'team';
    $brand  = fabo_owner_brand($person);
    $from   = fabo_sender_brand($actor);
    $home   = fabo_mail_home();

    $emailMoved = $wasEmail !== '' && strcasecmp($wasEmail, (string) $person['email']) !== 0;
    $nameMoved  = $wasName !== '' && $wasName !== (string) $person['name'];
    $loginId    = trim((string) ($person['login_id'] ?? ''));
    $signInUrl  = $home . ($loginId !== '' ? '?u=' . rawurlencode($loginId) : '');

    $subject = 'Your ' . $brand . ' sign-in details have changed'
             . ($loginId !== '' ? ' [' . $loginId . ']' : '');

    require_once __DIR__ . '/mailtpl.php';
    $lines = fabo_access_lines($access);

    /* ---- plain text ---- */
    $body = 'Hi ' . $person['name'] . ",\n\n"
          . ($emailMoved
             ? "Your " . ($isTeam ? 'work portal' : 'dashboard') . " account now uses this email address.\n"
             : "Your details on the " . ($isTeam ? 'work portal' : 'dashboard') . " have been updated.\n")
          . ($nameMoved ? 'Your name now reads ' . $person['name'] . ".\n" : '')
          . "\nSIGN IN\n"
          . '  ' . $home . "\n"
          . '  Email:    ' . $person['email'] . "\n"
          . ($loginId !== '' ? '  User ID:  ' . $loginId . "\n" : '')
          . ($password !== null ? '  Password: ' . $password . "\n"
                                : "  Password: (unchanged)\n")
          . ($loginId !== ''
             ? "\nSIGN IN WITH YOUR USER ID\n  " . $signInUrl . "\n" : '')
          . ($lines ? "\nYOUR SHEETS\n" . implode("\n", $lines) . "\n" : '')
          . "\nEverything you had access to before is still there, exactly as it was."
          . "\nPlease change the password once you are in."
          . fabo_sign_off($from);

    /* ---- the same thing, laid out ---- */
    $htmlLines = [];
    foreach ($access as $key => $perm) {
        $htmlLines[] = '<strong>' . e(fabo_grantable_label($key)) . '</strong>'
                     . ' <span style="color:' . fabo_mail_soft() . ';font-size:13px;">'
                     . ($perm === 'edit' ? 'add and edit rows' : 'view only') . '</span>';
    }

    $inner = fabo_mail_p('Hi ' . e((string) $person['name']) . ',')
           . fabo_mail_p($emailMoved
               ? 'Your ' . ($isTeam ? 'work portal' : 'dashboard') . ' account now uses '
                 . '<strong>' . e((string) $person['email']) . '</strong>. '
                 . 'Sign in with the details below.'
               : 'Your details have been updated. Your sign-in details are below.')
           . ($nameMoved
              ? fabo_mail_note('Your name now reads ' . e((string) $person['name'])
                  . ($wasName !== '' ? ' (it was ' . e($wasName) . ').' : '.'))
              : '')
           . fabo_mail_rows(array_filter([
                 'Email'    => '<strong>' . e((string) $person['email']) . '</strong>',
                 'User ID'  => $loginId !== ''
                     ? '<span style="font-family:Consolas,Menlo,monospace;font-size:15px;">'
                       . e($loginId) . '</span>' : '',
                 'Password' => $password !== null
                     ? '<span style="font-family:Consolas,Menlo,monospace;font-size:15px;">'
                       . e($password) . '</span>'
                     : '<span style="color:' . fabo_mail_soft() . ';">unchanged</span>',
             ]))
           . fabo_mail_own_link($signInUrl, 'Sign in with your user ID',
               $loginId !== ''
                 ? 'Opens the sign-in page with ' . $loginId . ' filled in.' : '')
           . ($loginId !== ''
              ? fabo_mail_note('Your user ID is ' . e($loginId) . '. Always sign in '
                  . 'with that rather than the email address: the address may belong '
                  . 'to several accounts, the user ID only ever to this one.')
              : '')
           . ($htmlLines
              ? fabo_mail_p('<strong>What you can open</strong>') . fabo_mail_list($htmlLines)
              : '')
           . fabo_mail_note('Everything you had access to before is still there, exactly '
               . 'as it was. Please change the password once you are in.');

    return [$subject, $body,
            fabo_mail_wrap($from, 'Your sign-in details have changed', $inner, $subject)];
}

/**
 * Name or address changed: issue a fresh password and post the details to
 * wherever the account now lives.
 *
 * Returns [changed?, sent?, detail, password]. $changed is false when neither
 * the name nor the address actually moved, and then nothing is sent — saving
 * a record without touching either should not mail anybody.
 */
function fabo_announce_identity_change(array $before, ?array $actor = null): array
{
    $id    = (int) ($before['id'] ?? 0);
    $after = $id > 0 ? fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [$id]) : null;
    if (!$after) {
        return [false, false, 'that person was not found', null];
    }
    $wasEmail = trim((string) ($before['email'] ?? ''));
    $wasName  = trim((string) ($before['name'] ?? ''));
    $nowEmail = trim((string) ($after['email'] ?? ''));

    $emailMoved = strcasecmp($wasEmail, $nowEmail) !== 0;
    $nameMoved  = $wasName !== trim((string) ($after['name'] ?? ''));
    if (!$emailMoved && !$nameMoved) {
        return [false, false, '', null];
    }
    if ($nowEmail === '' || !filter_var($nowEmail, FILTER_VALIDATE_EMAIL)) {
        return [true, false, 'there is no valid email address on the record', null];
    }

    require_once __DIR__ . '/auth.php';
    $plain  = fabo_set_password($id);          // the old one cannot be read back
    $access = fabo_client_access($id);

    /* No private dashboard link: the only way in from a message is the
       sign-in page, and fabo_identity_message builds that from the user ID. */
    [$subject, $body, $html] = fabo_identity_message($after, $access, $plain,
                                                     $wasEmail, $wasName, $actor);

    fabo_notify($id, 'Your sign-in details have changed',
        'Your details were updated and a new password was emailed to ' . $nowEmail . '.', '');

    require_once __DIR__ . '/outbox.php';
    [$sent, $detail] = fabo_post_or_queue($nowEmail, $subject, $body, $html,
                                          $id, 'sign-in details', $actor);
    return [true, $sent, $detail, $plain];
}

/**
 * Welcomes someone: portal notice + an email carrying the link and credentials.
 * Returns [sent, detail, subject, body].
 */
function fabo_announce_welcome(array $person, array $access, string $url, string $loginUrl,
                               ?string $password, ?array $actor = null): array
{
    [$subject, $body, $html] = fabo_welcome_message($person, $access, $url, $loginUrl, $password, $actor);

    $titles = [];
    foreach ($access as $key => $perm) {
        $titles[] = fabo_grantable_label($key) . ($perm === 'edit' ? ' (edit)' : ' (view)');
    }
    fabo_notify(
        (int) $person['id'],
        'Welcome — your dashboard is ready',
        ($titles ? 'You can open: ' . implode(', ', $titles) . '. ' : '')
            . 'Your sign-in details were emailed to ' . ($person['email'] ?: 'you') . '.',
        ''
    );

    require_once __DIR__ . '/outbox.php';
    [$sent, $detail] = fabo_post_or_queue((string) ($person['email'] ?? ''), $subject, $body,
                                          $html, (int) ($person['id'] ?? 0),
                                          'sign-in details', $actor);
    return [$sent, $detail, $subject, $body];
}

/**
 * Tells someone their sheet access changed: portal notice + email attempt.
 * Returns [sent, detail, subject, body] so the caller can offer a manual send.
 */
function fabo_announce_access(array $person, array $access, string $url, ?array $actor = null): array
{
    [$subject, $body, $html] = fabo_access_message($person, $access, $url, $actor);

    $titles = [];
    foreach ($access as $key => $perm) {
        $titles[] = fabo_grantable_label($key) . ($perm === 'edit' ? ' (edit)' : ' (view)');
    }
    fabo_notify(
        (int) $person['id'],
        $access ? 'Your sheet access has been updated' : 'Your sheet access was cleared',
        $access ? 'You can now open: ' . implode(', ', $titles) : 'No sheets are assigned to you at the moment.',
        ''
    );

    [$sent, $detail] = fabo_send_mail((string) ($person['email'] ?? ''), $subject, $body, $actor, $html);
    return [$sent, $detail, $subject, $body];
}

/* -------------------------------------------------- approval requests */

/**
 * The two links an approval request carries: the row behind a sign-in, and the
 * same row on the recipient's private link. Both land on the row itself.
 */
function fabo_row_links(array $person, string $sheetKey, int $rowId): array
{
    $base = fabo_portal_url();
    $q    = ['sheet' => $sheetKey, 'row' => $rowId];

    // The administrator has no portal account and no private link.
    if ((int) ($person['id'] ?? 0) === 0 || ($person['kind'] ?? '') === 'admin') {
        $url = $base . 'admin/sheet.php?' . http_build_query($q);
        return [$url, $url];
    }
    $deep  = 'c.php?' . http_build_query($q);
    $login = $base . 'login.php?next=' . rawurlencode($deep);
    $token = $base . 'c.php?' . http_build_query(
        array_merge(['k' => (string) ($person['token'] ?? '')], $q));
    return [$login, $token];
}

/**
 * Asks several people to approve or reject one row.
 *
 * Every notice is written first, then all the emails go down a single SMTP
 * session — sending one row to forty clients otherwise costs a second or two
 * per person and the page gives up half way through.
 *
 * Returns [client_id => [sent, detail]].
 */
function fabo_announce_requests(array $people, string $sheetKey, int $rowId, string $title,
                                string $note, ?array $actor = null): array
{
    if (!$people) {
        return [];
    }
    $messages = [];
    foreach ($people as $person) {
        $id = (int) $person['id'];
        [$subject, $body, $html] = fabo_request_message($person, $sheetKey, $rowId, $title, $note, $actor);
        fabo_notify_request($person, $sheetKey, $rowId, $title, $note);
        $messages[$id] = ['to'   => (string) ($person['email'] ?? ''),
                          'subject' => $subject, 'body' => $body, 'html' => $html];
    }
    if (fabo_event_mail_off('', $sheetKey)) {         // the notices above still went
        return array_fill_keys(array_keys($messages), [false, FABO_EVENT_MAIL_OFF_WHY]);
    }
    try {
        return fabo_smtp_send_many($messages, fabo_sender_for($actor));
    } catch (Throwable $e) {
        error_log('FABO batch mail failed: ' . $e->getMessage());
        $out = [];
        foreach (array_keys($messages) as $id) {
            $out[$id] = [false, 'the mail server could not be reached'];
        }
        return $out;
    }
}

/** The dashboard notice that goes with an approval request. */
function fabo_notify_request(array $person, string $sheetKey, int $rowId,
                             string $title, string $note): void
{
    // Read as one sentence however the pieces arrive, with or without a note.
    $tidy = static function (string $s): string {
        $s = trim($s);
        return $s === '' ? '' : (preg_match('/[.!?]$/', $s) ? $s . ' ' : $s . '. ');
    };
    $isAdmin = (int) ($person['id'] ?? 0) === 0 || ($person['kind'] ?? '') === 'admin';
    fabo_notify(
        (int) ($person['id'] ?? 0),
        'Approval needed — ' . fabo_sheet_label($sheetKey),
        $tidy($title) . $tidy($note) . 'Open the row and choose Approve or Reject.',
        ($isAdmin ? 'admin/sheet.php?' : 'c.php?')
            . http_build_query(['sheet' => $sheetKey, 'row' => $rowId])
    );
}

/** The wording of an approval request. Returns [subject, body]. */
function fabo_request_message(array $person, string $sheetKey, int $rowId, string $title,
                              string $note, ?array $actor = null): array
{
    require_once __DIR__ . '/mailtpl.php';

    $brand = fabo_owner_brand($person);
    $from  = fabo_sender_brand($actor);
    $sheet = fabo_sheet_label($sheetKey);
    $item  = $title !== '' ? $title : $sheet . ' row #' . $rowId;

    $subject = 'Your approval is needed — ' . $sheet . ($title !== '' ? ': ' . $title : '');

    /* ---- plain text ---- */
    $body = 'Hi ' . $person['name'] . ",

"
          . 'Your approval is needed on one item in ' . $sheet . ' on your '
          . $brand . " portal.

"
          . "ITEM
  " . $item . "
"
          . ($note !== '' ? "
WHY YOU ARE BEING ASKED
  " . $note . "
" : '')
          . "
SIGN IN
  " . fabo_mail_home() . "
"
          . '  Open ' . $sheet . " and the item will be marked for your approval.
"
          . "
A rejection needs a short remark saying what has to change; that remark
"
          . "comes straight back to us. You will only see Approve and Reject on the items
"
          . 'sent to you — everything else on the sheet stays read-only.'
          . fabo_sign_off($from);

    /* ---- the same thing, laid out ---- */
    $inner = fabo_mail_p('Hi ' . e((string) $person['name']) . ',')
           . fabo_mail_p('Your approval is needed on one item in <strong>' . e($sheet)
                       . '</strong> on your ' . e($brand) . ' portal.')
           . fabo_mail_rows([
                 'Sheet' => e($sheet),
                 'Item'  => '<strong>' . e($item) . '</strong>',
             ])
           . ($note !== ''
              ? fabo_mail_p('<strong>Why you are being asked</strong>') . fabo_mail_quote($note)
              : '')
           . fabo_mail_button('Sign in to review it')
           . fabo_mail_note('Open <strong>' . e($sheet) . '</strong> once you are in and the '
                          . 'item will be marked for your approval. A rejection needs a short '
                          . 'remark saying what has to change &mdash; that comes straight back '
                          . 'to us. You will only see Approve and Reject on the items sent to '
                          . 'you; everything else on the sheet stays read-only.');

    return [$subject, $body, fabo_mail_wrap($from, 'Your approval is needed', $inner, $item)];
}


/**
 * Asks one person to approve or reject one row: portal notice + email.
 * Returns [sent, detail, subject, body].
 */
function fabo_announce_request(array $person, string $sheetKey, int $rowId, string $title,
                               string $note, ?array $actor = null): array
{
    [$subject, $body, $html] = fabo_request_message($person, $sheetKey, $rowId, $title, $note, $actor);
    fabo_notify_request($person, $sheetKey, $rowId, $title, $note);
    if (fabo_event_mail_off('', $sheetKey)) {
        return [false, FABO_EVENT_MAIL_OFF_WHY, $subject, $body];
    }
    [$sent, $detail] = fabo_send_mail((string) ($person['email'] ?? ''), $subject, $body, $actor, $html);
    return [$sent, $detail, $subject, $body];
}

/* ------------------------------------------------ the answer coming back */

/**
 * The team member a row belongs to, or null.
 *
 * Every sheet names its owner in one column, and a team member is tied to rows
 * through their Owner / Team value. That is the relationship — not "everyone
 * who can open the sheet" — so a decision never reaches an unrelated colleague.
 */
function fabo_row_owner_member(string $sheetKey, array $row): ?array
{
    if (!function_exists('fabo_work_sources')) {
        require_once __DIR__ . '/work.php';
    }
    $field = fabo_work_sources()[$sheetKey][0] ?? null;
    if (!$field) {
        return null;
    }
    $owner = trim((string) ($row[$field] ?? ''));
    if ($owner === '') {
        return null;
    }
    return fabo_one("SELECT * FROM clients
                     WHERE kind = 'team' AND owner_name = ? AND revoked_at IS NULL
                     ORDER BY id LIMIT 1", 's', [$owner]);
}

/**
 * Everyone who should hear how a request was answered.
 *
 * Three relationships, and only these three:
 *   - whoever asked for the approval;
 *   - the team member the row is assigned to;
 *   - the parent of a client who answered, since they run that account.
 *
 * Returned as inbox id => person row, with the admin under FABO_ADMIN_INBOX and
 * the person who just decided removed — nobody needs telling what they did.
 */
function fabo_decision_audience(array $request, string $sheetKey, array $row, array $decidedBy): array
{
    $out = [];

    // 1. Whoever asked. An admin request has no client id behind it.
    $askedById = (int) ($request['requested_by_id'] ?? 0);
    if ($askedById > 0) {
        $p = fabo_one('SELECT * FROM clients WHERE id = ? AND revoked_at IS NULL', 'i', [$askedById]);
        if ($p) {
            $out[$askedById] = $p;
        }
    } elseif (($request['requested_by'] ?? '') === 'Admin') {
        $out[FABO_ADMIN_INBOX] = null;          // null means the portal itself
    }

    // 2. The team member the row is assigned to.
    $owner = fabo_row_owner_member($sheetKey, $row);
    if ($owner) {
        $out[(int) $owner['id']] = $owner;
    }

    // 3. The account that runs the client who answered.
    $parentId = (int) ($decidedBy['parent_id'] ?? 0);
    if ($parentId > 0 && !isset($out[$parentId])) {
        $p = fabo_one('SELECT * FROM clients WHERE id = ? AND revoked_at IS NULL', 'i', [$parentId]);
        if ($p) {
            $out[$parentId] = $p;
        }
    }

    unset($out[(int) ($decidedBy['id'] ?? -1)]);
    return $out;
}

/** The wording of a decision notice. Returns [subject, body, short]. */
function fabo_decision_message(?array $person, string $sheetKey, int $rowId, string $title,
                               string $state, string $remarks, array $decidedBy): array
{
    require_once __DIR__ . '/mailtpl.php';

    $sheet    = fabo_sheet_label($sheetKey);
    $label    = fabo_approval_label($state);
    $who      = (string) ($decidedBy['name'] ?? 'The client');
    $org      = trim((string) ($decidedBy['organisation'] ?? ''));
    $whoFull  = $who . ($org !== '' ? ' (' . $org . ')' : '');
    $item     = $title !== '' ? $title : $sheet . ' row #' . $rowId;
    $verb     = $state === 'rejected' ? 'rejected' : ($state === 'approved' ? 'approved' : 'reopened');
    $greeting = $person ? 'Hi ' . $person['name'] . ",

" : "Hi,

";

    $subject = $label . ' — ' . $sheet . ': ' . $item;

    /* ---- plain text. A rejection is the one that needs acting on. ---- */
    $body = $greeting
          . $whoFull . ' has ' . $verb . " an item you sent for approval.

"
          . "ITEM
  " . $item . "
"
          . "SHEET
  " . $sheet . "
"
          . "DECISION
  " . $label . "
"
          . ($remarks !== '' ? "
WHAT THEY SAID
  " . $remarks . "
" : '')
          . ($state === 'rejected'
             ? "
This needs changing before it can go out.
"
             : "
Nothing further is needed on this item.
")
          . "
SIGN IN
  " . fabo_mail_home() . "
"
          . '  Open ' . $sheet . " to see the full history on the row.
"
          . fabo_sign_off(fabo_app_name());

    /* ---- the same thing, laid out ---- */
    $inner = fabo_mail_p($person ? 'Hi ' . e((string) $person['name']) . ',' : 'Hi,')
           . fabo_mail_p('<strong>' . e($whoFull) . '</strong> has ' . e($verb)
                       . ' an item you sent for approval.')
           . fabo_mail_rows([
                 'Item'     => '<strong>' . e($item) . '</strong>',
                 'Sheet'    => e($sheet),
                 'Decision' => '<strong style="color:'
                     . ($state === 'rejected' ? '#c0392b' : '#1a7f44') . ';">'
                     . e($label) . '</strong>',
             ])
           . ($remarks !== ''
              ? fabo_mail_p('<strong>What they said</strong>') . fabo_mail_quote($remarks)
              : '')
           . fabo_mail_p($state === 'rejected'
               ? 'This needs changing before it can go out.'
               : 'Nothing further is needed on this item.')
           . fabo_mail_button('Sign in to the portal')
           . fabo_mail_note('Open <strong>' . e($sheet) . '</strong> to see the full history '
                          . 'on the row.');

    $short = $whoFull . ' ' . $verb . ' "' . $item . '"'
           . ($remarks !== '' ? '. They said: ' . $remarks : '.');

    return [$subject, $body, $short,
            fabo_mail_wrap(fabo_app_name(), $label, $inner, $item)];
}


/**
 * Tells the right people how a request was answered: a notice on each of their
 * dashboards, and one email each, all down a single SMTP session.
 *
 * Returns [inbox id => [sent, detail]] so a caller can report what happened.
 */
function fabo_announce_decision(array $request, string $sheetKey, int $rowId, string $title,
                                string $state, string $remarks, array $decidedBy,
                                ?array $row = null): array
{
    $row      = $row ?? [];
    $audience = fabo_decision_audience($request, $sheetKey, $row, $decidedBy);
    if (!$audience) {
        return [];
    }
    $link     = 'c.php?' . http_build_query(['sheet' => $sheetKey, 'row' => $rowId]);
    $adminLnk = 'admin/sheet.php?' . http_build_query(['sheet' => $sheetKey, 'row' => $rowId]);
    $label    = fabo_approval_label($state);
    $sheet    = fabo_sheet_label($sheetKey);
    $messages = [];

    foreach ($audience as $inbox => $person) {
        [$subject, $body, $short, $html] =
            fabo_decision_message($person, $sheetKey, $rowId, $title, $state, $remarks, $decidedBy);

        fabo_notify((int) $inbox, $label . ' — ' . $sheet, $short,
                    $person === null ? $adminLnk : $link);

        $to = $person === null ? (string) ADMIN_INBOX_EMAIL : (string) ($person['email'] ?? '');
        if (trim($to) !== '') {
            $messages[$inbox] = ['to' => $to, 'subject' => $subject,
                                 'body' => $body, 'html' => $html];
        }
    }

    if (!$messages || fabo_event_mail_off('', $sheetKey)) {   // the notices above still went
        return [];
    }
    try {
        // Always from the portal: this is our own workflow talking to our own
        // people, not a client writing to theirs.
        return fabo_smtp_send_many($messages, fabo_portal_sender());
    } catch (Throwable $e) {
        error_log('FABO decision mail failed: ' . $e->getMessage());
        return [];
    }
}


/* --------------------------------------------------- deletion requests */

/**
 * Tells the administrator that someone wants something deleted, and the
 * requester's own head of organisation when they have one — a client running
 * their own people should see what those people are asking for.
 *
 * Notice on each dashboard, and one email each down a single session.
 */
function fabo_announce_delete_request(array $req, array $viewer): array
{
    require_once __DIR__ . '/deletions.php';

    $what   = fabo_delete_describe($req);
    $who    = (string) $req['requested_by_name'];
    $reason = trim((string) ($req['reason'] ?? ''));

    $short  = $who . ' asked to delete ' . $what
            . ($reason !== '' ? '. Reason: ' . $reason : '.');

    // It goes to the one head who can answer it, and to nobody else: a
    // client's staff asking about their own organisation's record is not
    // our business, and we are not asked about it.
    $headId = (int) ($req['head_id'] ?? 0);
    $head   = $headId > 0
        ? fabo_one('SELECT * FROM clients WHERE id = ? AND revoked_at IS NULL', 'i', [$headId])
        : null;
    if ($headId > 0 && !$head) {
        $headId = 0;   // their head has gone, so it falls to us
    }
    $link = $headId === 0 ? 'admin/deletions.php' : 'deletions.php';

    require_once __DIR__ . '/mailtpl.php';
    $from = $headId === 0 ? fabo_app_name() : fabo_owner_brand($head);

    $subject = 'Deletion requested — ' . $what;
    $body    = "Hi,\n\n"
             . $who . " has asked for something to be deleted, and it needs your decision.\n\n"
             . "WHAT\n  " . $what . "\n"
             . "ASKED BY\n  " . $who . "\n"
             . ($reason !== '' ? "\nWHY THEY ASKED\n  " . $reason . "\n" : '')
             . "\nNothing has been deleted yet.\n"
             . "\nSIGN IN\n  " . fabo_mail_home() . "\n"
             . "  Open Deletions to approve or refuse it.\n"
             . fabo_sign_off($from);

    $html = fabo_mail_wrap($from, 'A deletion needs your decision',
        fabo_mail_p('<strong>' . e($who) . '</strong> has asked for something to be deleted.')
      . fabo_mail_rows(['What' => '<strong>' . e($what) . '</strong>', 'Asked by' => e($who)])
      . ($reason !== ''
         ? fabo_mail_p('<strong>Why they asked</strong>') . fabo_mail_quote($reason) : '')
      . fabo_mail_p('Nothing has been deleted yet.')
      . fabo_mail_button('Sign in to decide')
      . fabo_mail_note('Open <strong>Deletions</strong> once you are in.'),
        $what);

    fabo_notify($headId, 'Deletion requested', $short, $link);

    $to = $headId === 0 ? (string) ADMIN_INBOX_EMAIL : (string) ($head['email'] ?? '');
    $messages = [];
    if (trim($to) !== '') {
        $messages[$headId] = ['to' => $to, 'subject' => $subject,
                              'body' => $body, 'html' => $html];
    }
    if (!$messages || fabo_event_mail_off('', (string) ($req['sheet_key'] ?? ''))) {   // the notice above still went
        return [];
    }
    try {
        return fabo_smtp_send_many($messages, fabo_portal_sender());
    } catch (Throwable $e) {
        error_log('FABO delete-request mail failed: ' . $e->getMessage());
        return [];
    }
}

/** Tells the person who asked what was decided, and why if it was refused. */
function fabo_announce_delete_decision(array $req, string $state, string $note): array
{
    require_once __DIR__ . '/deletions.php';

    $clientId = (int) ($req['requested_by'] ?? 0);
    if ($clientId <= 0) {
        return [];
    }
    $person = fabo_one('SELECT * FROM clients WHERE id = ? AND revoked_at IS NULL', 'i', [$clientId]);
    if (!$person) {
        return [];
    }
    $what = fabo_delete_describe($req);
    $done = $state === 'approved';
    $note = trim($note);

    $title = $done ? 'Deletion approved' : 'Deletion refused';
    $short = $what . ' — ' . ($done ? 'deleted.' : 'not deleted.')
           . ($note !== '' ? ' ' . $note : '');

    fabo_notify($clientId, $title, $short, '');
    if (fabo_event_mail_off('', (string) ($req['sheet_key'] ?? ''))) {
        return [false, FABO_EVENT_MAIL_OFF_WHY];
    }

    require_once __DIR__ . '/mailtpl.php';

    $body = 'Hi ' . $person['name'] . ",\n\n"
          . ($done
             ? "Your deletion request has been approved and the item has been removed.\n\n"
             : "Your deletion request has been refused. Nothing has been deleted.\n\n")
          . "WHAT\n  " . $what . "\n"
          . ($note !== '' ? "\nWHAT THEY SAID\n  " . $note . "\n" : '')
          . fabo_sign_off(fabo_app_name());

    $html = fabo_mail_wrap(fabo_app_name(), $title,
        fabo_mail_p('Hi ' . e((string) $person['name']) . ',')
      . fabo_mail_p($done
          ? 'Your deletion request has been approved and the item has been removed.'
          : 'Your deletion request has been refused. Nothing has been deleted.')
      . fabo_mail_rows([
            'What'     => '<strong>' . e($what) . '</strong>',
            'Decision' => '<strong style="color:' . ($done ? '#c0392b' : '#1a7f44') . ';">'
                        . ($done ? 'Deleted' : 'Kept') . '</strong>',
        ])
      . ($note !== ''
         ? fabo_mail_p('<strong>What they said</strong>') . fabo_mail_quote($note) : ''),
        $what);

    return fabo_send_mail((string) ($person['email'] ?? ''),
                          $title . ' — ' . $what, $body, null, $html);
}

/* ---------------------------------------------------------- invoices */

/**
 * Tells a client about their invoice: raised, sent, or settled.
 *
 * $event is 'sent' or 'paid'. Both write a notice to their dashboard and send
 * an email from the portal — an invoice comes from us, never relayed as if
 * the client had sent it to themselves.
 */
function fabo_announce_invoice(array $inv, string $event): array
{
    require_once __DIR__ . '/invoices.php';

    $clientId = (int) $inv['client_id'];
    $person   = fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [$clientId]);
    $number   = (string) $inv['number'];
    $total    = fabo_money($inv['total']);
    $link     = 'finance.php';   // their Finance tab, where the invoice lives

    require_once __DIR__ . '/mailtpl.php';
    $brand = fabo_app_name();

    if ($event === 'paid') {
        $title = 'Payment received — ' . $number;
        $short = 'Thank you. ' . $number . ' for ' . $total . ' is settled.'
               . ($inv['paid_note'] ? ' ' . $inv['paid_note'] : '');
        $body  = 'Hi ' . ($person['name'] ?? '') . ",\n\n"
               . 'We have recorded payment of ' . $total . " against this invoice. Thank you.\n\n"
               . "INVOICE\n  " . $number . "\n"
               . "AMOUNT\n  " . $total . "\n"
               . ($inv['paid_note'] ? "\nNOTE\n  " . $inv['paid_note'] . "\n" : '')
               . "\nNothing further is needed."
               . fabo_sign_off($brand);

        $html = fabo_mail_wrap($brand, 'Payment received',
            fabo_mail_p('Hi ' . e((string) ($person['name'] ?? '')) . ',')
          . fabo_mail_p('We have recorded payment of <strong>' . e($total)
                      . '</strong> against this invoice. Thank you.')
          . fabo_mail_rows(['Invoice' => '<strong>' . e($number) . '</strong>',
                            'Amount'  => e($total)])
          . ($inv['paid_note']
             ? fabo_mail_p('<strong>Note</strong>') . fabo_mail_quote((string) $inv['paid_note'])
             : '')
          . fabo_mail_p('Nothing further is needed.'),
            $number . ' is settled');
    } else {
        $due   = $inv['due_date'] ? date('d M Y', strtotime((string) $inv['due_date'])) : '';
        $title = 'Invoice ' . $number;
        $short = $total . ' is due' . ($due !== '' ? ' by ' . $due : '')
               . '. Open it from your Finance page.';
        $body  = 'Hi ' . ($person['name'] ?? '') . ",\n\n"
               . "Here are the details of your invoice.\n\n"
               . "INVOICE\n  " . $number . "\n"
               . "AMOUNT\n  " . $total . "\n"
               . "ISSUED\n  " . date('d M Y', strtotime((string) $inv['issue_date'])) . "\n"
               . ($due !== '' ? "DUE\n  " . $due . "\n" : '')
               . ($inv['notes'] ? "\nNOTES\n  " . $inv['notes'] . "\n" : '')
               . "\nSIGN IN\n  " . fabo_mail_home() . "\n"
               . "  Open Finance for the full breakdown, and to print it.\n"
               . fabo_sign_off($brand);

        $html = fabo_mail_wrap($brand, 'Invoice ' . $number,
            fabo_mail_p('Hi ' . e((string) ($person['name'] ?? '')) . ',')
          . fabo_mail_p('Here are the details of your invoice.')
          . fabo_mail_rows([
                'Invoice' => '<strong>' . e($number) . '</strong>',
                'Amount'  => '<strong style="font-size:17px;">' . e($total) . '</strong>',
                'Issued'  => e(date('d M Y', strtotime((string) $inv['issue_date']))),
                'Due'     => $due !== '' ? e($due) : '',
            ])
          . ($inv['notes']
             ? fabo_mail_p('<strong>Notes</strong>') . fabo_mail_quote((string) $inv['notes'])
             : '')
          . fabo_mail_button('Sign in to view it')
          . fabo_mail_note('Open <strong>Finance</strong> for the full breakdown, and to print it.'),
            $total . ($due !== '' ? ' due by ' . $due : ''));
    }

    if ($clientId > 0) {
        fabo_notify($clientId, $title, $short, $link);
    }
    // The portal's own books: the admin keeps a record of both events.
    fabo_notify_admin($title . ' — ' . (string) $inv['client_name'], $short, 'invoices.php');

    if (!$person || trim((string) ($person['email'] ?? '')) === '') {
        return [false, 'no valid email address on file'];
    }
    return fabo_send_mail((string) $person['email'], $title . ' — ' . $total, $body, null, $html);
}

/* ------------------------------------------------------------ meetings */

/**
 * The invitation as one guest reads it.
 *
 * Pulled out of the sender so it can be read back and checked without a
 * mail server in the way — the body is the part that has to be right.
 */
/** Every line after the first indented by two spaces, for a plain-text block. */
function fabo_indent_lines(string $text, string $pad = '  '): string
{
    $lines = preg_split('~
|
|
~', trim($text)) ?: [];
    return implode('@' . $pad, $lines);
}

function fabo_meeting_invite_body(array $meeting, array $guest, string $brand,
                                  bool $isChange = false): array
{
    require_once __DIR__ . '/meetings.php';
    require_once __DIR__ . '/mailtpl.php';

    $when  = fabo_meeting_when($meeting);
    $link  = trim((string) ($meeting['meet_url'] ?? ''));
    $where = trim((string) ($meeting['location'] ?? ''));
    $name  = trim((string) ($guest['name'] ?? ''));
    $agenda = trim((string) ($meeting['agenda'] ?? ''));

    /*
     * An invitation with no way to join is the one thing this must never be.
     * When the meeting is plainly an online one and no link is set, the guest
     * is told so, rather than being left hunting for a link that is not
     * there — which is how "WHERE: Google Meet" alone reads.
     *
     * The joining link is the one exception to the no-links rule: it is not
     * a credential for this portal, and an invitation without it is useless.
     */
    $online = $where !== '' && preg_match('~meet|online|zoom|teams|video|call~i', $where);
    $joinNote = 'The joining link is not set yet. It will be sent before the meeting, '
              . 'and it will appear on your portal.';

    /*
     * No buttons of our own here. The email is a real calendar invitation
     * (fabo_announce_meeting attaches it, or Google Calendar sends it), so
     * Gmail, Outlook and Apple Mail draw their own Yes / Maybe / No card on
     * it and add it to the guest's calendar; the answer comes back to us.
     */
    $rsvpText = '';
    $rsvpHtml = '';

    /* ---- plain text ---- */
    $body = 'Hi' . ($name !== '' ? ' ' . $name : '') . ",@@"
          . ($isChange
             ? "A meeting you are invited to has changed.@@"
             : $meeting['organiser_name'] . " has invited you to a meeting.@@")
          . "WHAT@  " . $meeting['title'] . "@"
          . "@WHEN@  " . $when . "@"
          . ($where !== '' ? "@WHERE@  " . $where . "@" : '')
          // The agenda carries its own line breaks; each one is indented so
          // the block reads as one item rather than running to the margin.
          . ($agenda !== '' ? "@AGENDA@  " . fabo_indent_lines($agenda) . "@" : '')
          . ($link !== '' ? "@JOIN@  " . $link . "@"
                          : ($online ? "@JOIN@  " . $joinNote . "@" : ''))
          . $rsvpText
          . "@SIGN IN@  " . fabo_mail_home() . "@"
          . "  It is on your Meetings page.@"
          . fabo_sign_off($brand);

    /* ---- the same thing, laid out ---- */
    $inner = fabo_mail_p('Hi' . ($name !== '' ? ' ' . e($name) : '') . ',')
           . fabo_mail_p($isChange
               ? 'A meeting you are invited to has changed.'
               : '<strong>' . e((string) $meeting['organiser_name'])
                 . '</strong> has invited you to a meeting.')
           . fabo_mail_rows([
                 'What'  => '<strong>' . e((string) $meeting['title']) . '</strong>',
                 'When'  => '<strong>' . e($when) . '</strong>',
                 'Where' => $where !== '' ? e($where) : '',
             ])
           . ($agenda !== ''
              ? fabo_mail_p('<strong>Agenda</strong>') . fabo_mail_quote($agenda) : '')
           . ($link !== ''
              ? '<table role="presentation" cellpadding="0" cellspacing="0" border="0"'
                . ' style="margin:0 0 18px;"><tr><td style="border-radius:7px;background:'
                . fabo_mail_accent() . ';"><a href="' . e($link)
                . '" style="display:inline-block;padding:11px 22px;font-size:14px;'
                . 'font-weight:600;color:#ffffff;text-decoration:none;">Join the meeting</a>'
                . '</td></tr></table>'
                . '<p style="margin:0 0 18px;font-size:12px;color:' . fabo_mail_soft()
                . ';word-break:break-all;">' . e($link) . '</p>'
              : ($online ? fabo_mail_note($joinNote) : ''))
           . $rsvpHtml
           . fabo_mail_note('It is also on your <strong>Meetings</strong> page: '
                          . '<a href="' . e(fabo_mail_home()) . '" style="color:'
                          . fabo_mail_accent() . ';">sign in to the portal</a>.');

    return [str_replace('@', PHP_EOL, $body),
            fabo_mail_wrap($brand, $isChange ? 'A meeting has changed' : 'You are invited',
                           $inner, $meeting['title'] . ' — ' . $when)];
}


/**
 * Sends the invitation to everybody on the guest list.
 *
 * One SMTP session for the whole list, the same way approval requests go
 * out: a meeting with thirty guests otherwise costs a second each and the
 * request gives up part way through. Guests who are on the portal also get
 * a notice on their dashboard; an address typed into the box gets only the
 * email, which is all we have for them.
 *
 * Returns [sentCount, failedCount].
 */
function fabo_announce_meeting(array $meeting, array $guests, ?array $actor = null,
                               bool $isChange = false): array
{
    require_once __DIR__ . '/meetings.php';

    $when  = fabo_meeting_when($meeting);
    $brand = fabo_sender_brand($actor);
    $link  = trim((string) ($meeting['meet_url'] ?? ''));
    $where = trim((string) ($meeting['location'] ?? ''));

    $subject = ($isChange ? 'Updated: ' : 'Meeting: ') . $meeting['title'] . ' — ' . $when;

    /*
     * On Google Calendar, Google sends the invitation: the card in Gmail with
     * Yes / No / Maybe, "On your Google Calendar", the Meet link. It went out
     * when the event was created (and to anyone added since, when the guest
     * list was pushed to it), so a portal email on top would only be a second,
     * lesser copy. A resend asks Google to send everyone the update again.
     * Answers are read back from Google (fabo_meeting_sync_responses).
     */
    if (trim((string) ($meeting['calendar_event_id'] ?? '')) !== '') {
        require_once __DIR__ . '/google.php';
        if ($isChange) {
            [$gok, $gwhy] = fabo_google_update_event($meeting, $guests);
            if (!$gok) {
                error_log('FABO meeting resend via Google failed: ' . $gwhy);
                return [0, count($guests)];
            }
        }
        $sent = 0;
        foreach ($guests as $g) {
            if (!empty($g['client_id'])) {
                fabo_notify((int) $g['client_id'],
                            ($isChange ? 'Meeting updated' : 'Meeting invitation'),
                            $meeting['title'] . ' — ' . $when,
                            'meetings.php?id=' . (int) $meeting['id']);
            }
            fabo_exec('UPDATE meeting_guests SET invited_at = NOW(), invite_error = NULL WHERE id = ?',
                      'i', [(int) $g['id']]);
            $sent++;
        }
        return [$sent, 0];
    }

    /*
     * The calendar invitation that rides with each email. One file for the
     * whole list (it names every guest, as any calendar invite does). Not
     * attached when the meeting is on Google Calendar: Google has already
     * sent that invitation, and a second one would put the meeting in
     * everybody's calendar twice. The organiser gets a plain calendar entry,
     * since asking them to accept their own meeting makes no sense.
     */
    $onGoogle = trim((string) ($meeting['calendar_event_id'] ?? '')) !== '';
    $invite   = $onGoogle ? '' : fabo_meeting_ics($meeting, 'REQUEST', $guests);
    $entry    = $onGoogle ? '' : fabo_meeting_ics($meeting, 'PUBLISH');

    $messages = [];
    foreach ($guests as $g) {
        $to = trim((string) ($g['email'] ?? ''));
        if ($to === '') {
            continue;
        }
        // Someone with a portal account gets a notice as well as the mail.
        if (!empty($g['client_id'])) {
            fabo_notify((int) $g['client_id'],
                        ($isChange ? 'Meeting updated' : 'Meeting invitation'),
                        $meeting['title'] . ' — ' . $when,
                        'meetings.php?id=' . (int) $meeting['id']);
        }

        [$body, $html] = fabo_meeting_invite_body($meeting, $g, $brand, $isChange);

        $messages[$to] = ['to' => $to, 'subject' => $subject,
                          'body' => $body, 'html' => $html,
                          'ics' => ($g['role'] ?? '') === 'host' ? $entry : $invite];
    }
    if (!$messages) {
        return [0, 0];
    }

    try {
        $results = fabo_smtp_send_many($messages, fabo_invite_sender($actor));
    } catch (Throwable $e) {
        error_log('FABO meeting invite mail failed: ' . $e->getMessage());
        return [0, count($messages)];
    }

    // Record what happened per guest, so the page can show who was reached.
    $sent = 0;
    $bad  = 0;
    foreach ($guests as $g) {
        $to = trim((string) ($g['email'] ?? ''));
        if ($to === '' || !isset($results[$to])) {
            continue;
        }
        [$ok, $detail] = $results[$to];
        $ok ? $sent++ : $bad++;
        fabo_exec('UPDATE meeting_guests SET invited_at = NOW(), invite_error = ?
                   WHERE id = ?', 'si', [$ok ? null : mb_substr((string) $detail, 0, 190),
                                         (int) $g['id']]);
    }
    return [$sent, $bad];
}

/**
 * Tells everyone who was invited that the meeting is off, with a calendar
 * cancellation attached so it disappears from the calendar the invitation
 * put it in. Only guests who were actually sent an invitation are told.
 *
 * Returns [sentCount, failedCount].
 */
function fabo_announce_meeting_cancelled(array $meeting, array $guests, ?array $actor = null): array
{
    require_once __DIR__ . '/meetings.php';
    require_once __DIR__ . '/mailtpl.php';

    $guests = array_values(array_filter($guests, static fn($g) => !empty($g['invited_at'])
                                                              && ($g['role'] ?? '') !== 'host'));
    if (!$guests) {
        return [0, 0];
    }
    $when   = fabo_meeting_when($meeting);
    $brand  = fabo_sender_brand($actor);
    $cancel = fabo_meeting_ics($meeting, 'CANCEL', $guests);
    $why    = trim((string) ($meeting['outcome'] ?? ''));

    $messages = [];
    foreach ($guests as $g) {
        $to   = trim((string) $g['email']);
        $name = trim((string) ($g['name'] ?? ''));
        $body = 'Hi' . ($name !== '' ? ' ' . $name : '') . ',' . PHP_EOL . PHP_EOL
              . 'This meeting has been cancelled:' . PHP_EOL . PHP_EOL
              . '  ' . $meeting['title'] . PHP_EOL . '  ' . $when . PHP_EOL
              . ($why !== '' ? PHP_EOL . 'Reason: ' . $why . PHP_EOL : '')
              . PHP_EOL . 'It has been taken out of your calendar.' . PHP_EOL
              . fabo_sign_off($brand);
        $inner = fabo_mail_p('Hi' . ($name !== '' ? ' ' . e($name) : '') . ',')
               . fabo_mail_p('This meeting has been <strong>cancelled</strong>:')
               . fabo_mail_rows(['What' => '<strong>' . e((string) $meeting['title']) . '</strong>',
                                 'When' => '<s>' . e($when) . '</s>',
                                 'Why'  => $why !== '' ? e($why) : ''])
               . fabo_mail_note('It has been taken out of your calendar.');
        $messages[$to] = ['to' => $to, 'subject' => 'Cancelled: ' . $meeting['title'] . ' — ' . $when,
                          'body' => $body,
                          'html' => fabo_mail_wrap($brand, 'Meeting cancelled', $inner,
                                                   $meeting['title'] . ' — cancelled'),
                          'ics'  => $cancel];
    }
    try {
        $results = fabo_smtp_send_many($messages, fabo_invite_sender($actor));
    } catch (Throwable $e) {
        error_log('FABO meeting cancel mail failed: ' . $e->getMessage());
        return [0, count($messages)];
    }
    $sent = count(array_filter($results, static fn($r) => !empty($r[0])));
    return [$sent, count($results) - $sent];
}

/**
 * Tells the guest list how a meeting ended, and what was minuted.
 * Only people on the portal are told; this is not worth an email.
 */
function fabo_announce_meeting_closed(array $meeting, array $guests): array
{
    require_once __DIR__ . '/meetings.php';

    $label = fabo_meeting_state_label((string) $meeting['state']);
    $short = $meeting['title'] . ' — ' . strtolower($label)
           . (!empty($meeting['minutes']) ? '. Minutes are on the portal.' : '.');

    $told = 0;
    foreach ($guests as $g) {
        if (empty($g['client_id'])) {
            continue;
        }
        fabo_notify((int) $g['client_id'], 'Meeting ' . strtolower($label), $short,
                    'meetings.php?id=' . (int) $meeting['id']);
        $told++;
    }
    return [$told, 0];
}

<?php
/**
 * A small SMTP client — enough to send plain-text mail through Gmail (or any
 * other SMTP server) without Composer or any library.
 *
 * Speaks STARTTLS on port 587 and implicit TLS on 465, authenticates with
 * AUTH LOGIN, and returns a plain-English reason when anything fails so the
 * admin screen can say what went wrong instead of "could not send".
 */

require_once __DIR__ . '/../config.php';

class FaboSmtp
{
    private $sock = null;
    private array $log = [];

    public function __construct(
        private string $host,
        private int $port,
        private string $user,
        private string $pass,
        private int $timeout = 20
    ) {}

    public function transcript(): string
    {
        return implode("\n", $this->log);
    }

    private function say(string $line): void
    {
        // Never let a password reach the log.
        $this->log[] = $line === '' ? '' : mb_substr($line, 0, 200);
    }

    private function read(): string
    {
        $out = '';
        while (($line = fgets($this->sock, 1024)) !== false) {
            $out .= $line;
            // A multi-line reply has a dash after the code; the last has a space.
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        $this->say('S: ' . trim($out));
        return $out;
    }

    private function send(string $cmd, bool $secret = false): string
    {
        $this->say('C: ' . ($secret ? '********' : $cmd));
        fwrite($this->sock, $cmd . "\r\n");
        return $this->read();
    }

    private static function code(string $reply): int
    {
        return (int) substr(trim($reply), 0, 3);
    }

    /**
     * One message, start to finish. Returns [ok, message].
     *
     * Kept for single sends; a batch opens the session once with open() and
     * then calls deliver() per recipient, which is the difference between two
     * seconds per person and two seconds for all of them.
     */
    public function sendMail(string $from, string $fromName, string $to, string $subject,
                             string $body, string $replyTo = '', string $html = ''): array
    {
        [$ok, $why] = $this->open();
        if (!$ok) {
            $this->close();
            return [false, $why];
        }
        try {
            return $this->deliver($from, $fromName, $to, $subject, $body, $replyTo, $html);
        } finally {
            $this->close();
        }
    }

    /**
     * Connects, starts TLS and signs in. Returns [ok, reason]. The session
     * stays open until close(), so several messages can go down it.
     */
    public function open(): array
    {
        if ($this->sock) {
            return [true, 'already open'];
        }
        $scheme = $this->port === 465 ? 'ssl://' : 'tcp://';
        $ssl = [
            'verify_peer'      => true,
            'verify_peer_name' => true,
            'SNI_enabled'      => true,
            'peer_name'        => $this->host,
        ];
        if (defined('SMTP_CAFILE') && SMTP_CAFILE !== '' && is_file(SMTP_CAFILE)) {
            $ssl['cafile'] = SMTP_CAFILE;
        }
        $ctx = stream_context_create(['ssl' => $ssl]);

        $errno = 0; $errstr = '';
        $this->sock = @stream_socket_client(
            $scheme . $this->host . ':' . $this->port,
            $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $ctx
        );
        if (!$this->sock) {
            return [false, 'could not reach ' . $this->host . ':' . $this->port
                         . ($errstr !== '' ? ' — ' . $errstr : '')];
        }
        stream_set_timeout($this->sock, $this->timeout);

        {
            if (self::code($this->read()) !== 220) {
                return [false, 'the mail server refused the connection'];
            }
            $ehlo = $this->send('EHLO ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
            if (self::code($ehlo) !== 250) {
                return [false, 'the mail server rejected the greeting'];
            }

            if ($this->port !== 465) {
                if (self::code($this->send('STARTTLS')) !== 220) {
                    return [false, 'the mail server would not start TLS'];
                }
                // The context set at connect time carries the CA bundle through.
                $ok = @stream_socket_enable_crypto(
                    $this->sock, true,
                    STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT
                );
                if (!$ok) {
                    $why = error_get_last()['message'] ?? '';
                    $this->say('TLS error: ' . $why);
                    return [false, 'the TLS handshake failed'
                        . (str_contains($why, 'certificate') ? ' — certificate could not be verified' : '')];
                }
                $this->say('--- TLS established ---');
                if (self::code($this->send('EHLO ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'))) !== 250) {
                    return [false, 'the mail server rejected the greeting after TLS'];
                }
            }

            if ($this->user !== '') {
                if (self::code($this->send('AUTH LOGIN')) !== 334) {
                    return [false, 'the mail server would not accept a login'];
                }
                if (self::code($this->send(base64_encode($this->user))) !== 334) {
                    return [false, 'the mail server rejected the username'];
                }
                $r = $this->send(base64_encode($this->pass), true);
                if (self::code($r) !== 235) {
                    $hint = str_contains($r, 'BadCredentials') || str_contains($r, '535')
                        ? ' — check the address and the 16-character app password'
                        : '';
                    return [false, 'the mail server rejected the password' . $hint];
                }
            }
            return [true, 'connected'];
        }
    }

    /** Ends the session. Safe to call twice. */
    public function close(): void
    {
        if ($this->sock) {
            @$this->send('QUIT');
            @fclose($this->sock);
            $this->sock = null;
        }
    }

    /**
     * One message down an already-open session. Returns [ok, message].
     * A refused recipient does not end the session — the next one still goes.
     */
    public function deliver(string $from, string $fromName, string $to, string $subject,
                            string $body, string $replyTo = '', string $html = '',
                            string $ics = ''): array
    {
        if (!$this->sock) {
            return [false, 'the connection to the mail server is not open'];
        }
        {
            // Clear anything left by a previous message before starting this one.
            $this->send('RSET');

            if (self::code($this->send('MAIL FROM:<' . $from . '>')) !== 250) {
                return [false, 'the sender address was refused'];
            }
            if (!in_array(self::code($this->send('RCPT TO:<' . $to . '>')), [250, 251], true)) {
                return [false, 'the recipient address was refused'];
            }
            if (self::code($this->send('DATA')) !== 354) {
                return [false, 'the mail server would not accept the message'];
            }

            $headers = [
                'From: ' . ($fromName !== '' ? self::encodeHeader($fromName) . ' ' : '') . '<' . $from . '>',
                'To: <' . $to . '>',
                'Subject: ' . self::encodeHeader($subject),
                'Date: ' . date('r'),
                'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $this->host . '>',
                'MIME-Version: 1.0',
            ];
            /*
             * multipart/alternative when there is an HTML part. The plain
             * text goes FIRST and the HTML second, because a mail client
             * renders the last part it understands — so a client that
             * blocks HTML still gets a readable message, not markup.
             */
            $boundary = 'fabo' . bin2hex(random_bytes(10));
            if (trim($ics) !== '') {
                /*
                 * A calendar invitation. The text/calendar part sits inside
                 * the alternative (that is what makes Gmail and Outlook draw
                 * their own Yes / Maybe / No bar and add it to the calendar),
                 * and the same file rides along as an invite.ics attachment
                 * for every other client.
                 */
                $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . 'm"';
                $body = FaboSmtp::invitation($boundary, $body, $html, $ics);
            } elseif (trim($html) !== '') {
                $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
                $body = FaboSmtp::alternative($boundary, $body, $html);
            } else {
                $headers[] = 'Content-Type: text/plain; charset=UTF-8';
                $headers[] = 'Content-Transfer-Encoding: 8bit';
            }
            // When we relay for a client out of our own mailbox, replies still
            // have to reach them rather than us.
            if ($replyTo !== '' && strcasecmp($replyTo, $from) !== 0) {
                $headers[] = 'Reply-To: <' . $replyTo . '>';
            }
            // Dot-stuffing: a line that is just "." would end the message early.
            $safe = preg_replace('/^\./m', '..', str_replace("\r\n", "\n", $body));
            $data = implode("\r\n", $headers) . "\r\n\r\n" . str_replace("\n", "\r\n", $safe) . "\r\n.";

            $this->say('C: <message, ' . strlen($body) . ' bytes>');
            fwrite($this->sock, $data . "\r\n");
            $said = $this->read();
            if (self::code($said) !== 250) {
                /* The server's own words, not ours. "did not accept the
                   message body" sent people looking for a fault in the
                   message; the answer is usually a rate limit or a quota,
                   and only the server can say which. */
                return [false, 'the mail server refused it: ' . self::tidy($said),
                        self::code($said)];
            }

            return [true, 'sent to ' . $to];
        }
    }

    /** One line of a server's reply, fit to show somebody. */
    public static function tidy(string $reply): string
    {
        $line = trim((string) strtok(trim($reply), "\r\n"));
        $line = preg_replace('~\s+~', ' ', $line);
        return mb_substr($line, 0, 160);
    }

    /**
     * The two-part body. Built here rather than inline so the CRLF handling
     * stays in one place next to the dot-stuffing that follows it.
     */
    public static function alternative(string $boundary, string $text, string $html): string
    {
        $nl = chr(10);
        return 'This message has a plain-text and an HTML part.' . $nl . $nl
             . '--' . $boundary . $nl
             . 'Content-Type: text/plain; charset=UTF-8' . $nl
             . 'Content-Transfer-Encoding: 8bit' . $nl . $nl
             . $text . $nl . $nl
             . '--' . $boundary . $nl
             . 'Content-Type: text/html; charset=UTF-8' . $nl
             . 'Content-Transfer-Encoding: 8bit' . $nl . $nl
             . $html . $nl . $nl
             . '--' . $boundary . '--' . $nl;
    }

    /** The body of a calendar invitation: text, HTML and the event itself. */
    public static function invitation(string $boundary, string $text, string $html, string $ics): string
    {
        $nl     = chr(10);
        $alt    = $boundary . 'a';
        $mixed  = $boundary . 'm';
        $method = preg_match('/^METHOD:([A-Z]+)/m', $ics, $mm) ? $mm[1] : 'REQUEST';
        $b64    = rtrim(chunk_split(base64_encode($ics), 76, $nl));
        return 'This message is a calendar invitation.' . $nl . $nl
             . '--' . $mixed . $nl
             . 'Content-Type: multipart/alternative; boundary="' . $alt . '"' . $nl . $nl
             . '--' . $alt . $nl
             . 'Content-Type: text/plain; charset=UTF-8' . $nl
             . 'Content-Transfer-Encoding: 8bit' . $nl . $nl
             . $text . $nl . $nl
             . (trim($html) !== ''
                ? '--' . $alt . $nl
                  . 'Content-Type: text/html; charset=UTF-8' . $nl
                  . 'Content-Transfer-Encoding: 8bit' . $nl . $nl
                  . $html . $nl . $nl
                : '')
             . '--' . $alt . $nl
             . 'Content-Type: text/calendar; charset=UTF-8; method=' . $method . $nl
             . 'Content-Transfer-Encoding: base64' . $nl . $nl
             . $b64 . $nl . $nl
             . '--' . $alt . '--' . $nl . $nl
             . '--' . $mixed . $nl
             . 'Content-Type: application/ics; name="invite.ics"' . $nl
             . 'Content-Disposition: attachment; filename="invite.ics"' . $nl
             . 'Content-Transfer-Encoding: base64' . $nl . $nl
             . $b64 . $nl . $nl
             . '--' . $mixed . '--' . $nl;
    }

    /** RFC 2047 for anything outside plain ASCII. */
    private static function encodeHeader(string $v): string
    {
        return preg_match('/[^\x20-\x7E]/', $v)
            ? '=?UTF-8?B?' . base64_encode($v) . '?='
            : $v;
    }
}

/** Is outgoing mail configured at all? */
function fabo_mail_configured(): bool
{
    return SMTP_HOST !== '' && SMTP_USER !== '' && SMTP_PASS !== '';
}

/**
 * The standby mailbox, or null when none is configured.
 *
 * Used only after the everyday one has refused a message temporarily. A
 * provider whose whole business is sending will take what a shared-hosting
 * mailbox will not, and the recipient sees the same message either way.
 */
function fabo_standby_sender(): ?array
{
    if (!defined('SMTP2_HOST') || SMTP2_HOST === '' || SMTP2_USER === '') {
        return null;
    }
    return [
        'host'      => SMTP2_HOST,
        'port'      => SMTP2_PORT,
        'user'      => SMTP2_USER,
        'pass'      => SMTP2_PASS,
        'from'      => SMTP2_FROM !== '' ? SMTP2_FROM : SMTP2_USER,
        'from_name' => SMTP2_FROM_NAME !== '' ? SMTP2_FROM_NAME : SMTP_FROM_NAME,
        'reply_to'  => '',
    ];
}

/* ---------------------------------------------- whose name a message carries */

/**
 * The client organisation a recipient belongs to, as its owner's `clients`
 * row, or null. A person is theirs when they are the organisation (a
 * top-level client) or one of its people (parent_id). Our own staff, the
 * administrator and outsiders belong to nobody, and an address shared by
 * people of different organisations is not guessed at.
 */
function fabo_mail_recipient_org(string $to): ?array
{
    static $memo = [];
    $to = strtolower(trim($to));
    if ($to === '' || !function_exists('fabo_all')) {
        return null;
    }
    if (array_key_exists($to, $memo)) {
        return $memo[$to];
    }
    $memo[$to] = null;
    try {
        $orgs = [];
        foreach (fabo_all('SELECT id, kind, parent_id FROM clients WHERE LOWER(email) = ? AND revoked_at IS NULL', 's', [$to]) as $p) {
            $parent = (int) ($p['parent_id'] ?? 0);
            $orgs[$parent > 0 ? $parent : (($p['kind'] ?? 'client') === 'team' ? 0 : (int) $p['id'])] = true;
        }
        if (count($orgs) === 1 && !isset($orgs[0])) {
            $memo[$to] = fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [(int) array_key_first($orgs)]) ?: null;
        }
    } catch (Throwable $e) {
        error_log('FABO mail recipient org: ' . $e->getMessage());
    }
    return $memo[$to];
}

/** The name an organisation's mail carries: its organisation name, else the portal's. */
function fabo_mail_org_brand(array $owner): string
{
    if (function_exists('fabo_owner_brand')) {
        return fabo_owner_brand($owner);
    }
    $o = trim((string) ($owner['organisation'] ?? ''));
    return $o !== '' ? $o : (defined('APP_NAME') && APP_NAME !== '' ? APP_NAME : 'Marketing Command Centre');
}

/**
 * Puts one message under the right name, just before it goes.
 *
 * A message sent on an organisation's behalf already says so: its sender
 * carries 'brand' (fabo_sender_for()). One sent with no sender in mind —
 * the portal's own notices: committee, approvals, HR, digests — is looked
 * at by recipient: going to one of a client organisation's people, it goes
 * out under that organisation's name with replies to the organisation, as
 * the client would expect of their own portal. Either way the portal's name
 * in the subject and body becomes the organisation's. Only the name and
 * Reply-To change here; the mailbox it leaves from is the sender's.
 *
 * Returns [sender, subject, text, html].
 */
function fabo_mail_brand_for(array $s, string $to, string $subject, string $body, string $html): array
{
    $app = defined('APP_NAME') && APP_NAME !== '' ? APP_NAME : 'Marketing Command Centre';
    $brand = trim((string) ($s['brand'] ?? ''));
    $unattributed = $brand === '' && trim((string) ($s['reply_to'] ?? '')) === ''
                 && (string) ($s['from_name'] ?? '') === (string) (defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : '');
    if ($unattributed && ($owner = fabo_mail_recipient_org($to))) {
        $brand = fabo_mail_org_brand($owner);
        if ($brand !== $app) {
            $s['from_name'] = $brand;
            $reply = trim((string) ($owner['email'] ?? ''));
            if ($reply !== '' && filter_var($reply, FILTER_VALIDATE_EMAIL) && strcasecmp($reply, (string) ($s['from'] ?? '')) !== 0
                && strcasecmp($reply, $to) !== 0) {
                $s['reply_to'] = $reply;
            }
        }
    }
    $s['brand'] = $brand;
    if ($brand !== '' && $brand !== $app) {
        $subject = str_replace($app, $brand, $subject);
        $body    = str_replace($app, $brand, $body);
        $html    = str_replace([$app, htmlspecialchars($app, ENT_QUOTES)], htmlspecialchars($brand, ENT_QUOTES), $html);
    }
    return [$s, $subject, $body, $html];
}

/**
 * The portal's own mailbox, as a sender profile.
 */
function fabo_portal_sender(): array
{
    return [
        'host'      => SMTP_HOST,
        'port'      => SMTP_PORT,
        'user'      => SMTP_USER,
        'pass'      => SMTP_PASS,
        'from'      => SMTP_FROM !== '' ? SMTP_FROM : SMTP_USER,
        'from_name' => SMTP_FROM_NAME,
        'reply_to'  => '',
    ];
}

/**
 * Sends one message. Returns [ok, message].
 *
 * $sender is a profile from fabo_portal_sender() or fabo_client_sender(); it
 * decides which mailbox the message leaves from, so a client's invitations
 * carry their name rather than ours. Falls back to PHP's mail() when the
 * chosen profile has no SMTP server of its own.
 */
function fabo_smtp_send(string $to, string $subject, string $body, ?array $sender = null,
                        string $html = ''): array
{
    $to = trim($to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return [false, 'no valid email address on file'];
    }
    $s = $sender ?: fabo_portal_sender();
    // Under the organisation's name when it is theirs (see fabo_mail_brand_for()).
    [$s, $subject, $body, $html] = fabo_mail_brand_for($s, $to, $subject, $body, $html);

    $configured = ($s['host'] ?? '') !== '' && ($s['user'] ?? '') !== '' && ($s['pass'] ?? '') !== '';
    if (!$configured) {
        if (!function_exists('mail')) {
            return [false, 'no mail server is configured'];
        }
        $h = "MIME-Version: 1.0\r\n"
           . (trim($html) !== ''
              ? "Content-Type: text/html; charset=UTF-8\r\n"
              : "Content-Type: text/plain; charset=UTF-8\r\n");
        if (($s['reply_to'] ?? '') !== '') {
            $h .= 'Reply-To: ' . $s['reply_to'] . "\r\n";
        }
        $ok = @mail($to, $subject, trim($html) !== '' ? $html : $body, $h);
        return $ok ? [true, 'handed to the local mail system']
                   : [false, 'no mail server is configured'];
    }

    /*
     * A 4xx from a mail server means "not now", not "no" — a rate limit, a
     * greylist, a momentary quota. Sending two people their details one after
     * the other walks straight into one, and the second person is the one who
     * never hears anything. So a temporary refusal is waited out and tried
     * again rather than reported as a failure.
     */
    /*
     * One attempt. A 4xx means "not now", and asking again a few seconds
     * later spends more of the same quota for the same answer — which is
     * exactly what turned one rate-limited message into three. The caller
     * that cares (fabo_post_or_queue) keeps the message in the outbox and
     * offers it again when somebody says to.
     */
    $smtp = new FaboSmtp((string) $s['host'], (int) $s['port'],
                         (string) $s['user'], (string) $s['pass']);
    [$ok, $msg] = array_pad($smtp->sendMail(
        (string) (($s['from'] ?? '') !== '' ? $s['from'] : $s['user']),
        (string) ($s['from_name'] ?? ''),
        $to, $subject, $body,
        (string) ($s['reply_to'] ?? ''),
        $html
    ), 2, '');

    /*
     * Refused for now, not refused outright: try the standby mailbox before
     * giving up. This is what carries a thousand sign-in messages when the
     * everyday mailbox is rated for a dozen an hour.
     */
    if (!$ok && fabo_smtp_is_temporary($msg)) {
        $standby = fabo_standby_sender();
        if ($standby && ($standby['host'] !== ($s['host'] ?? ''))) {
            $alt = new FaboSmtp((string) $standby['host'], (int) $standby['port'],
                                (string) $standby['user'], (string) $standby['pass']);
            [$ok2, $msg2] = array_pad($alt->sendMail(
                // An organisation's message keeps its name through the standby mailbox too.
                (string) $standby['from'], (string) (($s['brand'] ?? '') !== '' ? $s['from_name'] : $standby['from_name']),
                $to, $subject, $body, (string) ($s['reply_to'] ?? ''), $html
            ), 2, '');
            if ($ok2) {
                return [true, $msg2 . ' (through the standby mailbox)'];
            }
            $msg .= '; the standby mailbox also refused it: ' . $msg2;
            $smtp = $alt;
        }
    }

    if (!$ok) {
        error_log('FABO mail to ' . $to . ' failed: ' . $msg . PHP_EOL . $smtp->transcript());
    }
    return [$ok, $msg];
}

/** Does this refusal read as "not now" rather than "no"? */
function fabo_smtp_is_temporary(string $why): bool
{
    return (bool) preg_match('~\b4\d\d\b|ratelimit|rate limit|too many|try again|greylist~i',
                             $why);
}

/**
 * Sends many messages down one connection. Returns [ok, detail] per message,
 * keyed the same way $messages was.
 *
 * Opening a fresh SMTP session costs a second or two — connect, TLS, sign in —
 * so sending one row to forty clients the naive way takes well over a minute
 * and the page times out half way. Here that cost is paid once.
 *
 * $messages: [key => ['to' => ..., 'subject' => ..., 'body' => ..., 'html' => ...], ...]
 * 'body' is the plain text and is required; 'html' is optional and, when
 * present, is what most recipients will actually see.
 */
function fabo_smtp_send_many(array $messages, ?array $sender = null): array
{
    if (!$messages) {
        return [];
    }
    $s = $sender ?: fabo_portal_sender();
    $out = [];

    $configured = ($s['host'] ?? '') !== '' && ($s['user'] ?? '') !== '' && ($s['pass'] ?? '') !== '';
    if (!$configured) {
        // No SMTP server: fall back message by message through mail().
        foreach ($messages as $k => $m) {
            $out[$k] = fabo_smtp_send((string) $m['to'], (string) $m['subject'],
                                      (string) $m['body'], $s, (string) ($m['html'] ?? ''));
        }
        return $out;
    }

    $smtp = new FaboSmtp((string) $s['host'], (int) $s['port'], (string) $s['user'], (string) $s['pass']);
    [$open, $why] = $smtp->open();
    if (!$open) {
        error_log('FABO batch mail could not start: ' . $why . "\n" . $smtp->transcript());
        $smtp->close();
        // One reason for every message, rather than a silent failure each.
        foreach ($messages as $k => $m) {
            $out[$k] = [false, $why];
        }
        return $out;
    }

    $from     = (string) (($s['from'] ?? '') !== '' ? $s['from'] : $s['user']);
    $fromName = (string) ($s['from_name'] ?? '');
    $replyTo  = (string) ($s['reply_to'] ?? '');

    try {
        foreach ($messages as $k => $m) {
            $to = trim((string) ($m['to'] ?? ''));
            if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
                $out[$k] = [false, 'no valid email address on file'];
                continue;
            }
            // Each message under its recipient's organisation's name where it is theirs.
            [$ms, $mSubj, $mBody, $mHtml] = fabo_mail_brand_for($s, $to, (string) $m['subject'], (string) $m['body'], (string) ($m['html'] ?? ''));
            [$ok, $msg] = $smtp->deliver($from, (string) ($ms['from_name'] ?? $fromName), $to,
                                         $mSubj, $mBody, (string) ($ms['reply_to'] ?? $replyTo),
                                         $mHtml, (string) ($m['ics'] ?? ''));
            if (!$ok) {
                error_log('FABO batch mail to ' . $to . ' failed: ' . $msg);
            }
            $out[$k] = [$ok, $msg];
        }
    } finally {
        $smtp->close();
    }
    return $out;
}

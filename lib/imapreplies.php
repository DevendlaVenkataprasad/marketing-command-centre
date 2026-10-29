<?php
/**
 * Calendar replies, read back from the portal's mailbox.
 *
 * Every meeting invitation the portal sends is a real calendar invitation
 * (lib/meetings.php, fabo_meeting_ics REQUEST) whose ORGANIZER is the
 * portal's own sending address. When a guest presses Yes, Maybe or No in
 * Gmail, Outlook or Apple Mail, their mail app sends a short reply to that
 * address carrying a METHOD:REPLY calendar part. This file signs in to the
 * same mailbox over IMAP, picks those replies out, and records each answer
 * against the guest — so the acceptance comes from the guest's own inbox,
 * with nothing to click on the portal.
 *
 * It uses the mailbox the portal already sends from (SMTP_USER / SMTP_PASS);
 * on Google Workspace an app password works for IMAP as well as SMTP, as long
 * as IMAP is switched on for that mailbox. IMAP_HOST, IMAP_PORT, IMAP_USER
 * and IMAP_PASS in config.local.php override the guesses.
 *
 * Nothing in the mailbox is changed: messages are read with BODY.PEEK, so
 * they stay unread, and the last message looked at is remembered by UID so
 * each one is read once.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/google.php';       // fabo_setting, fabo_env_or
require_once __DIR__ . '/meetings.php';

/* ------------------------------------------------------------ settings */

/** [host, port, user, pass], or null when there is no mailbox to read. */
function fabo_imap_account(): ?array
{
    $user = fabo_env_or('IMAP_USER') ?: (defined('SMTP_USER') ? SMTP_USER : '');
    $pass = fabo_env_or('IMAP_PASS') ?: (defined('SMTP_PASS') ? SMTP_PASS : '');
    $host = fabo_env_or('IMAP_HOST');
    if ($host === '' && defined('SMTP_HOST') && SMTP_HOST !== '') {
        // smtp.gmail.com -> imap.gmail.com, smtp.hostinger.com -> imap.hostinger.com
        $host = preg_match('~^smtp\.~i', SMTP_HOST) ? 'imap.' . substr(SMTP_HOST, 5) : SMTP_HOST;
    }
    if ($host === '' || $user === '' || $pass === '') {
        return null;
    }
    return [$host, (int) (fabo_env_or('IMAP_PORT') ?: 993), $user, str_replace(' ', '', $pass)];
}

/* -------------------------------------------------------------- client */

/** Just enough IMAP to search a mailbox and read messages. */
final class FaboImap
{
    private $sock = null;
    private int $tag = 0;

    public function __construct(private string $host, private int $port, private int $timeout = 12) {}

    /** Connects over TLS. Returns [ok, reason]. */
    public function open(): array
    {
        $ssl = ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $this->host];
        if (defined('SMTP_CAFILE') && SMTP_CAFILE !== '' && is_file(SMTP_CAFILE)) {
            $ssl['cafile'] = SMTP_CAFILE;
        }
        $errno = 0; $errstr = '';
        $this->sock = @stream_socket_client('ssl://' . $this->host . ':' . $this->port, $errno, $errstr,
            $this->timeout, STREAM_CLIENT_CONNECT, stream_context_create(['ssl' => $ssl]));
        if (!$this->sock) {
            return [false, 'could not reach ' . $this->host . ' (' . trim($errstr) . ')'];
        }
        stream_set_timeout($this->sock, $this->timeout);
        $hello = (string) fgets($this->sock, 8192);
        return str_starts_with($hello, '* OK') ? [true, ''] : [false, 'the mail server did not greet us'];
    }

    /**
     * Sends one command. Returns [ok, untaggedLines, literals]; literals are
     * the {n}-counted blocks (message bodies) in the order they arrived.
     */
    public function cmd(string $command): array
    {
        $t = 'F' . (++$this->tag);
        fwrite($this->sock, $t . ' ' . $command . "\r\n");
        $lines = [];
        $lits  = [];
        while (true) {
            $line = fgets($this->sock, 65536);
            if ($line === false) {
                return [false, $lines, $lits];
            }
            // A literal: the next n bytes are data, whatever they contain.
            if (preg_match('/\{(\d+)\}\r\n$/', $line, $mm)) {
                $need = (int) $mm[1];
                $buf  = '';
                while (strlen($buf) < $need) {
                    $chunk = fread($this->sock, min(65536, $need - strlen($buf)));
                    if ($chunk === false || $chunk === '') {
                        return [false, $lines, $lits];
                    }
                    $buf .= $chunk;
                }
                $lits[]  = $buf;
                $lines[] = rtrim($line);
                continue;
            }
            if (str_starts_with($line, $t . ' ')) {
                return [str_starts_with($line, $t . ' OK'), $lines, $lits];
            }
            $lines[] = rtrim($line, "\r\n");
        }
    }

    public static function quote(string $s): string
    {
        return '"' . addcslashes($s, "\"\\") . '"';
    }

    public function close(): void
    {
        if ($this->sock) {
            @fwrite($this->sock, 'F' . (++$this->tag) . " LOGOUT\r\n");
            @fclose($this->sock);
            $this->sock = null;
        }
    }
}

/* ---------------------------------------------------------------- MIME */

/**
 * The first calendar part of a raw message, decoded, or null. Walks nested
 * multiparts, because Gmail and Outlook each wrap their replies differently.
 */
function fabo_mime_calendar(string $raw, int $depth = 0): ?string
{
    if ($depth > 6) {
        return null;
    }
    $raw = str_replace("\r\n", "\n", $raw);
    $cut = strpos($raw, "\n\n");
    $head = $cut === false ? $raw : substr($raw, 0, $cut);
    $body = $cut === false ? '' : substr($raw, $cut + 2);
    $head = preg_replace("/\n[ \t]+/", ' ', $head);          // unfold

    $type = preg_match('/^content-type:\s*([^\n]+)/im', $head, $m) ? $m[1] : 'text/plain';
    $cte  = preg_match('/^content-transfer-encoding:\s*([^\s;]+)/im', $head, $m) ? strtolower($m[1]) : '';

    if (preg_match('~^\s*multipart/~i', $type)) {
        if (!preg_match('/boundary\s*=\s*"?([^";\n]+)"?/i', $type, $b)) {
            return null;
        }
        $parts = explode('--' . trim($b[1]), $body);
        array_shift($parts);                                   // the preamble
        foreach ($parts as $part) {
            if (str_starts_with($part, '--')) {
                break;                                         // the closing marker
            }
            $found = fabo_mime_calendar(ltrim($part, "\n"), $depth + 1);
            if ($found !== null) {
                return $found;
            }
        }
        return null;
    }
    if (!preg_match('~text/calendar|application/ics~i', $type)) {
        return null;
    }
    if ($cte === 'base64') {
        return (string) base64_decode(preg_replace('/\s+/', '', $body));
    }
    if ($cte === 'quoted-printable') {
        return quoted_printable_decode($body);
    }
    return $body;
}

/**
 * The answers in a METHOD:REPLY calendar: [meetingId, [email => PARTSTAT]],
 * or null when it is not a reply to one of our meetings.
 */
function fabo_ics_reply(string $ics): ?array
{
    $ics = preg_replace("/\r?\n[ \t]/", '', str_replace("\r\n", "\n", $ics));   // unfold
    if (!preg_match('/^METHOD:\s*REPLY\s*$/mi', $ics)) {
        return null;
    }
    if (!preg_match('/^UID:\s*meeting-(\d+)@\S*\s*$/mi', $ics, $u)) {
        return null;
    }
    $out = [];
    foreach (preg_split('/\n/', $ics) as $line) {
        if (!preg_match('/^ATTENDEE[;:]/i', $line)) {
            continue;
        }
        // Split at the first colon that is not inside a quoted parameter.
        $inQ = false;
        $at  = -1;
        for ($i = 0, $n = strlen($line); $i < $n; $i++) {
            if ($line[$i] === '"') { $inQ = !$inQ; }
            if ($line[$i] === ':' && !$inQ) { $at = $i; break; }
        }
        if ($at < 0) {
            continue;
        }
        $params = substr($line, 0, $at);
        $value  = trim(substr($line, $at + 1));
        $email  = strtolower(preg_replace('/^mailto:/i', '', $value));
        if (preg_match('/;PARTSTAT=([A-Z-]+)/i', $params, $p) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $out[$email] = strtoupper($p[1]);
        }
    }
    return $out ? [(int) $u[1], $out] : null;
}

/* ----------------------------------------------------------------- run */

/**
 * Reads new calendar replies from the mailbox and records them.
 *
 * Throttled to once every two minutes across the whole portal (the cron run
 * and page loads both call it); $force skips that, for the "Check replies"
 * button. Returns [ok, message, answersRecorded].
 */
function fabo_meeting_poll_replies(bool $force = false): array
{
    $acct = fabo_imap_account();
    if (!$acct) {
        return [false, 'No mailbox is set up to read replies from.', 0];
    }
    $last = (int) fabo_setting('imap_polled_at', '0');
    if (!$force && time() - $last < 120) {
        return [true, '', 0];
    }
    fabo_setting_put('imap_polled_at', (string) time());

    [$host, $port, $user, $pass] = $acct;
    $imap = new FaboImap($host, $port);
    [$ok, $why] = $imap->open();
    if (!$ok) {
        return [false, 'Could not check the mailbox for replies: ' . $why, 0];
    }
    try {
        [$ok] = $imap->cmd('LOGIN ' . FaboImap::quote($user) . ' ' . FaboImap::quote($pass));
        if (!$ok) {
            return [false, 'The mailbox ' . $user . ' refused the sign-in for reading replies. '
                         . 'Make sure IMAP is switched on for it (Gmail: Settings → Forwarding and POP/IMAP).', 0];
        }
        [$ok, $lines] = $imap->cmd('SELECT INBOX');
        if (!$ok) {
            return [false, 'Could not open the inbox to read replies.', 0];
        }
        // A changed UIDVALIDITY means the old UIDs mean nothing: start over.
        $validity = '';
        foreach ($lines as $l) {
            if (preg_match('/UIDVALIDITY (\d+)/i', $l, $m)) { $validity = $m[1]; }
        }
        $seenUid = (int) fabo_setting('imap_last_uid', '0');
        if ($validity !== fabo_setting('imap_uidvalidity')) {
            $seenUid = 0;
            fabo_setting_put('imap_uidvalidity', $validity);
        }

        // New messages only; on the first run, the last fortnight.
        $query = $seenUid > 0
            ? 'UID SEARCH UID ' . ($seenUid + 1) . ':*'
            : 'UID SEARCH SINCE ' . date('d-M-Y', strtotime('-14 days'));
        [$ok, $lines] = $imap->cmd($query);
        $uids = [];
        foreach ($lines as $l) {
            if (str_starts_with($l, '* SEARCH')) {
                foreach (preg_split('/\s+/', trim(substr($l, 8))) as $n) {
                    if ($n !== '' && (int) $n > $seenUid) { $uids[] = (int) $n; }
                }
            }
        }
        sort($uids);
        $uids = array_slice($uids, 0, 150);                    // a backlog is worked through over runs

        $recorded = 0;
        $map      = ['ACCEPTED' => 'yes', 'DECLINED' => 'no', 'TENTATIVE' => 'maybe'];
        foreach ($uids as $uid) {
            // Headers first: only a message that could hold a calendar is read in full.
            [$ok, , $lits] = $imap->cmd('UID FETCH ' . $uid . ' (BODY.PEEK[HEADER.FIELDS (CONTENT-TYPE)])');
            $head = $lits[0] ?? '';
            if ($ok && preg_match('~multipart|text/calendar|application/ics~i', $head)) {
                [$ok, , $lits] = $imap->cmd('UID FETCH ' . $uid . ' (BODY.PEEK[]<0.300000>)');
                $cal   = $ok && isset($lits[0]) ? fabo_mime_calendar($lits[0]) : null;
                $reply = $cal !== null ? fabo_ics_reply($cal) : null;
                if ($reply) {
                    [$mid, $answers] = $reply;
                    $m = fabo_meeting($mid);
                    foreach ($answers as $email => $part) {
                        if (!$m || !isset($map[$part])) {
                            continue;
                        }
                        $g = fabo_one('SELECT * FROM meeting_guests WHERE meeting_id = ? AND email = ?',
                                      'is', [$mid, $email]);
                        if ($g && $g['role'] !== 'host' && fabo_meeting_rsvp_set((int) $g['id'], $map[$part])) {
                            fabo_meeting_rsvp_tell($m, $g, $map[$part]);
                            $recorded++;
                        }
                    }
                }
            }
            fabo_setting_put('imap_last_uid', (string) $uid);
        }
        fabo_setting_put('imap_ok_at', (string) time());
        return [true, $recorded
            ? $recorded . ' new repl' . ($recorded === 1 ? 'y' : 'ies') . ' read from email.'
            : 'No new email replies.', $recorded];
    } catch (Throwable $e) {
        error_log('FABO imap replies: ' . $e->getMessage());
        return [false, 'Checking email replies failed: ' . $e->getMessage(), 0];
    } finally {
        $imap->close();
    }
}

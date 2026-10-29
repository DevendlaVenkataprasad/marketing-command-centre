<?php
/**
 * The outbox: messages the mail server would not take yet.
 *
 * A mail server answering 451 is saying "not now" — a rate limit, a quota,
 * a greylist. Before this, such a message was simply lost: the page said it
 * had failed, and the person it was meant for never heard anything. That is
 * what happened when two clients were updated one after the other.
 *
 * Now a message that cannot go out is kept, with what the server said and
 * how many times it has been offered. It can be sent again by hand, and the
 * count of tries can be reset when whatever was blocking it has passed.
 *
 * What is NOT done here, deliberately: retrying in a tight loop. A rate
 * limit is made worse by hammering it, and the earlier three-tries-with-a-
 * pause turned one refused message into three refusals and spent three
 * times the quota. One attempt, then the outbox.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

/**
 * How long to leave a rate-limited server alone between attempts.
 *
 * Not a blackout. The first version of this refused to try at all for ten
 * minutes, which meant a message sat in the outbox long after the limit had
 * passed and somebody had to press a button to find out. Now the wait only
 * spaces the attempts out: one every minute, which no rate limit minds, and
 * the queue drains itself the moment the server says yes again.
 */
const FABO_OUTBOX_PROBE_SEC = 60;

/** Waiting, sent, or given up on. */
function fabo_outbox_states(): array
{
    return ['waiting' => 'Waiting to send', 'sent' => 'Sent', 'held' => 'Held'];
}

/**
 * Keeps a message that could not go out.
 *
 * Returns the outbox id. The body is kept in full — a message that is sent
 * again a day later has to be the message that was written, not one
 * rebuilt from a record that may have changed since.
 */
function fabo_outbox_put(string $to, string $subject, string $text, string $html,
                         ?int $clientId, string $kind, string $why): int
{
    try {
        /*
         * One pending sign-in message per person, never a pile of them.
         *
         * Each one carries a password, and only the newest is the password
         * the account actually has. Two waiting messages means whichever
         * arrives second is right and the first is a wrong password in
         * somebody's inbox — so an older one for the same person and the
         * same purpose is dropped rather than queued alongside.
         */
        if ($clientId !== null && $kind !== '') {
            fabo_exec('DELETE FROM mail_outbox
                       WHERE client_id = ? AND kind = ? AND state = "waiting"',
                      'is', [$clientId, $kind]);
        }
        return (int) fabo_exec(
            'INSERT INTO mail_outbox
               (client_id, kind, to_email, subject, body_text, body_html,
                tries, last_error, last_try_at, state)
             VALUES (?,?,?,?,?,?,1,?,NOW(),"waiting")',
            'issssss',
            [$clientId, mb_substr($kind, 0, 40), $to, mb_substr($subject, 0, 255),
             $text, $html, mb_substr($why, 0, 255)]);
    } catch (Throwable $e) {
        error_log('FABO outbox put: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Sends a message, and keeps it if the server will not take it yet.
 *
 * Returns [sent?, why, outboxId]. Everything that writes to a person goes
 * through here, so nothing the portal meant to say can be lost quietly.
 */
function fabo_post_or_queue(string $to, string $subject, string $text, string $html,
                            ?int $clientId = null, string $kind = 'message',
                            ?array $actor = null): array
{
    require_once __DIR__ . '/notify.php';

    /* Too soon after the last attempt: queue it rather than make a second
       request the server is only going to refuse. It goes out on the next
       attempt, which the portal makes by itself. */
    if (!fabo_outbox_may_try()) {
        $capped = fabo_outbox_at_cap();
        $id = fabo_outbox_put($to, $subject, $text, $html, $clientId, $kind,
            $capped
                ? 'waiting its turn: the portal has sent its hourly allowance'
                : 'waiting its turn: the last attempt was less than a minute ago');
        return [false, $capped
            ? 'the portal has sent its hourly allowance, so this is queued and goes out '
              . 'as the hour rolls on'
            : 'the mail server is limiting us, so this is queued — it goes out '
              . 'by itself within a minute or two, nothing to press', $id];
    }

    fabo_outbox_touch();
    [$ok, $why] = fabo_send_mail($to, $subject, $text, $actor, $html);
    if ($ok) {
        fabo_outbox_warm();
        /* Recorded even though it worked first time: the hourly pacing counts
           what has actually left, and a send it never saw is a send it cannot
           count. */
        try {
            fabo_exec('INSERT INTO mail_outbox
                         (client_id, kind, to_email, subject, body_text, body_html,
                          tries, last_error, last_try_at, sent_at, state)
                       VALUES (?,?,?,?,"","",1,"",NOW(),NOW(),"sent")',
                      'isss', [$clientId, mb_substr($kind, 0, 40), $to,
                               mb_substr($subject, 0, 255)]);
        } catch (Throwable $e) {
            error_log('FABO outbox record: ' . $e->getMessage());
        }
        return [true, $why, 0];
    }
    if (fabo_outbox_is_temporary($why)) {
        fabo_outbox_cool();
        $id = fabo_outbox_put($to, $subject, $text, $html, $clientId, $kind, $why);
        return [false, $why . ' — queued, and the portal keeps trying by itself', $id];
    }
    $id = fabo_outbox_put($to, $subject, $text, $html, $clientId, $kind, $why);
    return [false, $why, $id];
}

/** Does this refusal read as temporary? */
function fabo_outbox_is_temporary(string $why): bool
{
    return (bool) preg_match('~\b4\d\d\b|ratelimit|rate limit|too many|try again|greylist~i', $why);
}

/** Remembers that an attempt has just been made, whatever came of it. */
function fabo_outbox_touch(): void
{
    require_once __DIR__ . '/google.php';
    fabo_setting_put('mail_last_try', date('Y-m-d H:i:s'));
}

/** Note that the server asked us to slow down. */
function fabo_outbox_cool(): void
{
    require_once __DIR__ . '/google.php';
    fabo_setting_put('mail_limited_at', date('Y-m-d H:i:s'));
    fabo_outbox_touch();
}

/**
 * How many messages have actually gone out in the last hour.
 *
 * Counted from the outbox, which is why every send is recorded there —
 * including the ones that worked first time. Without that the pacing would
 * be guessing.
 */
function fabo_outbox_sent_this_hour(): int
{
    try {
        return (int) (fabo_one('SELECT COUNT(*) c FROM mail_outbox
                                WHERE state = "sent" AND sent_at > (NOW() - INTERVAL 1 HOUR)')['c'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Is the portal at its own hourly limit?
 *
 * MAIL_PER_HOUR is set a little under whatever the mail provider allows, so
 * the portal paces itself rather than being throttled. Nought means no limit
 * of our own — which is right for a handful of clients and wrong for a
 * thousand, hence the setting.
 */
function fabo_outbox_at_cap(): bool
{
    $cap = defined('MAIL_PER_HOUR') ? (int) MAIL_PER_HOUR : 0;
    return $cap > 0 && fabo_outbox_sent_this_hour() >= $cap;
}

/**
 * May the portal try right now?
 *
 * Yes, unless it tried within the last minute. Spacing attempts out is what
 * keeps a rate limit from being re-triggered; refusing to try at all is what
 * kept messages sitting in the outbox after the limit had passed.
 */
function fabo_outbox_may_try(): bool
{
    require_once __DIR__ . '/google.php';
    if (fabo_outbox_at_cap()) {
        return false;               // our own limit, kept below the provider's
    }
    $last = fabo_setting('mail_last_try', '');
    return $last === '' || (time() - strtotime($last)) >= FABO_OUTBOX_PROBE_SEC;
}

/** Was the server limiting us recently? Used only for what the page says. */
function fabo_outbox_cooling(): bool
{
    require_once __DIR__ . '/google.php';
    $at = fabo_setting('mail_limited_at', '');
    return $at !== '' && (time() - strtotime($at)) < 3600;
}

/** What to tell somebody about it, or '' when there is nothing to say. */
function fabo_outbox_cooling_note(): string
{
    if (!fabo_outbox_cooling()) {
        return '';
    }
    require_once __DIR__ . '/google.php';
    $at   = fabo_setting('mail_limited_at', '');
    $mins = max(1, (int) round((time() - strtotime($at)) / 60));
    return 'The mail server was limiting us ' . $mins . ' minute'
         . ($mins === 1 ? '' : 's') . ' ago. The portal keeps trying by itself, about '
         . 'once a minute, and anything waiting goes out as soon as it is allowed '
         . 'through — you do not have to press anything.';
}

/** Forgets the limit and lets the next attempt go immediately. */
function fabo_outbox_warm(): void
{
    require_once __DIR__ . '/google.php';
    fabo_setting_put('mail_limited_at', null);
    fabo_setting_put('mail_last_try', null);
}

/* ------------------------------------------------------------- reading */

/** Everything still waiting, newest first. */
function fabo_outbox_waiting(?int $clientId = null, int $limit = 50): array
{
    try {
        return $clientId === null
            ? fabo_all('SELECT * FROM mail_outbox WHERE state = "waiting"
                        ORDER BY id DESC LIMIT ' . max(1, $limit))
            : fabo_all('SELECT * FROM mail_outbox WHERE state = "waiting" AND client_id = ?
                        ORDER BY id DESC LIMIT ' . max(1, $limit), 'i', [$clientId]);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * One person's mail state, for the line shown on their record.
 *
 * ['waiting' => n, 'tries' => n, 'last_error' => '…', 'last_try' => '…',
 *  'last_sent' => '…'] — everything a page needs to say how they are doing
 * without working any of it out itself.
 */
function fabo_outbox_summary(int $clientId): array
{
    $out = ['waiting' => 0, 'tries' => 0, 'last_error' => '', 'last_try' => '',
            'last_sent' => '', 'subject' => ''];
    try {
        $r = fabo_one('SELECT COUNT(*) c, COALESCE(MAX(tries),0) t
                       FROM mail_outbox WHERE client_id = ? AND state = "waiting"',
                      'i', [$clientId]);
        $out['waiting'] = (int) ($r['c'] ?? 0);
        $out['tries']   = (int) ($r['t'] ?? 0);

        $last = fabo_one('SELECT * FROM mail_outbox WHERE client_id = ?
                          ORDER BY id DESC LIMIT 1', 'i', [$clientId]);
        if ($last) {
            $out['last_error'] = (string) ($last['last_error'] ?? '');
            $out['last_try']   = (string) ($last['last_try_at'] ?? '');
            $out['subject']    = (string) ($last['subject'] ?? '');
        }
        $sent = fabo_one('SELECT sent_at FROM mail_outbox WHERE client_id = ?
                          AND state = "sent" ORDER BY id DESC LIMIT 1', 'i', [$clientId]);
        $out['last_sent'] = (string) ($sent['sent_at'] ?? '');
    } catch (Throwable $e) {
        // The table may not be there on a database that has not booted yet.
    }
    return $out;
}

/* ------------------------------------------------------------- writing */

/**
 * Who a kept message was being sent as, so a later attempt leaves under the
 * same name. Incubation Management tags its kind "inc o<owner id> ..." when it
 * mails under a client organisation's name; anything else goes as the portal.
 */
function fabo_outbox_sent_as(array $m): ?array
{
    if (!preg_match('/^(inc|hr) o(\d+) /', (string) ($m['kind'] ?? ''), $x)) {
        return null;
    }
    $owner = fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [(int) $x[2]]);
    if (!$owner) {
        return null;
    }
    // HR and Incubation mail are both signed by the organisation, never the owner's own name.
    require_once __DIR__ . '/brand.php';
    $owner['send_as_name'] = fabo_owner_brand($owner);
    return $owner;
}

/** Tries one kept message again. Returns [sent?, why]. */
function fabo_outbox_send(int $id, ?array $actor = null): array
{
    require_once __DIR__ . '/notify.php';
    $m = fabo_one('SELECT * FROM mail_outbox WHERE id = ?', 'i', [$id]);
    if (!$m) {
        return [false, 'that message is no longer in the outbox'];
    }
    if ((string) $m['state'] === 'sent') {
        return [true, 'it had already gone'];
    }
    fabo_outbox_touch();
    $actor = $actor ?? fabo_outbox_sent_as($m);
    [$ok, $why] = fabo_send_mail((string) $m['to_email'], (string) $m['subject'],
                                 (string) $m['body_text'], $actor, (string) $m['body_html']);
    if ($ok) {
        fabo_exec('UPDATE mail_outbox SET state = "sent", sent_at = NOW(),
                        tries = tries + 1, last_try_at = NOW(), last_error = "" WHERE id = ?',
                  'i', [$id]);
        fabo_outbox_warm();
        return [true, $why];
    }
    fabo_exec('UPDATE mail_outbox SET tries = tries + 1, last_try_at = NOW(), last_error = ?
               WHERE id = ?', 'si', [mb_substr($why, 0, 255), $id]);
    if (fabo_outbox_is_temporary($why)) {
        fabo_outbox_cool();
    }
    return [false, $why];
}

/**
 * Sends everything waiting, one at a time, stopping at the first temporary
 * refusal. Returns [sent, left, why].
 *
 * Stopping matters: once a server has said "slow down", the rest of the
 * queue is going to hear the same thing, and asking anyway is what got the
 * quota spent in the first place.
 */
function fabo_outbox_flush(?int $clientId = null, int $limit = 10, ?array $actor = null): array
{
    $sent = 0;
    $why  = '';
    foreach (fabo_outbox_waiting($clientId, $limit) as $m) {
        [$ok, $msg] = fabo_outbox_send((int) $m['id'], $actor);
        if ($ok) {
            $sent++;
            continue;
        }
        $why = $msg;
        if (fabo_outbox_is_temporary($msg)) {
            break;
        }
    }
    $left = count(fabo_outbox_waiting($clientId, 999));
    return [$sent, $left, $why];
}

/**
 * Resets the count of tries and lifts any wait.
 *
 * "Tried 3 times" is only useful while it means something. Once whatever was
 * blocking the server has passed, the count is noise — and leaving it
 * standing makes the next genuine failure harder to spot.
 */
function fabo_outbox_reset(?int $clientId = null): int
{
    fabo_outbox_warm();
    try {
        return $clientId === null
            ? fabo_exec('UPDATE mail_outbox SET tries = 0, last_error = "" WHERE state = "waiting"')
            : fabo_exec('UPDATE mail_outbox SET tries = 0, last_error = ""
                         WHERE state = "waiting" AND client_id = ?', 'i', [$clientId]);
    } catch (Throwable $e) {
        return 0;
    }
}

/** Gives up on one kept message. */
function fabo_outbox_drop(int $id): void
{
    fabo_exec('DELETE FROM mail_outbox WHERE id = ?', 'i', [$id]);
}

/* ------------------------------------------------------------ showing it */

/**
 * The mail state for one person, as a block on their record.
 *
 * Shown wherever somebody might ask "did they actually get it?" — the manage
 * pages and their own profile. $can says whether this reader may press the
 * buttons: a client looking at their own profile sees the state but does not
 * get to reset anybody's counters.
 */
function fabo_outbox_block(int $clientId, bool $can = false, string $backTo = ''): void
{
    $s    = fabo_outbox_summary($clientId);
    $cool = fabo_outbox_cooling_note();
    if ($s['waiting'] === 0 && $s['last_sent'] === '' && $s['last_error'] === '' && $cool === '') {
        return;                      // nothing has ever been sent to them
    }
    $when = static fn(string $d) => $d !== '' ? date('d M Y, H:i', strtotime($d)) : '';
    ?>
    <?php /* Queued is not a failure: the account is set up, the password is
             set, and the message leaves when the mail server allows it. The
             block is coloured accordingly — information, not an alarm. */ ?>
    <div class="mailstate<?= $s['waiting'] > 0 ? ' pending' : '' ?>">
      <div class="mailstate-head">
        <strong>Sign-in email</strong>
        <?php if ($s['waiting'] > 0): ?>
          <span class="pill tone-info"><i class="dot"></i><?= (int) $s['waiting'] ?>
            queued &mdash; goes out by itself</span>
        <?php elseif ($s['last_sent'] !== ''): ?>
          <span class="pill tone-good"><i class="dot"></i>sent</span>
        <?php endif; ?>
        <?php if ($s['tries'] > 0): ?>
          <span class="pill tone-muted"><i class="dot"></i><?= (int) $s['tries'] ?>
            <?= $s['tries'] === 1 ? 'try' : 'tries' ?> so far</span>
        <?php endif; ?>
      </div>
      <dl class="mailstate-facts">
        <?php if ($s['last_sent'] !== ''): ?>
          <div><dt>Last sent</dt><dd><?= e($when($s['last_sent'])) ?></dd></div>
        <?php endif; ?>
        <?php if ($s['last_try'] !== '' && $s['waiting'] > 0): ?>
          <div><dt>Last tried</dt><dd><?= e($when($s['last_try'])) ?></dd></div>
        <?php endif; ?>
        <?php if ($s['last_error'] !== ''): ?>
          <div class="wide"><dt>Last word from the mail server</dt>
            <dd class="mailstate-why"><?= e($s['last_error']) ?></dd></div>
        <?php endif; ?>
      </dl>
      <?php if ($cool !== ''): ?>
        <p class="mailstate-cool"><?= e($cool) ?></p>
      <?php endif; ?>
      <?php if ($can && ($s['waiting'] > 0 || $s['tries'] > 0)): ?>
        <div class="mailstate-acts">
          <button class="btn btn-sm btn-primary" name="action" value="mail_send"
                  formnovalidate>Send it again now</button>
          <button class="btn btn-sm" name="action" value="mail_reset"
                  formnovalidate>Reset tries</button>
        </div>
        <p class="hint">The portal keeps trying on its own, about once a minute,
          so anything waiting goes out as soon as the mail server allows it.
          <strong>Send it again now</strong> tries this instant instead of waiting
          for the next attempt; <strong>Reset tries</strong> puts the count back to
          nothing and forgets the limit.</p>
      <?php endif; ?>
    </div>
    <?php
}

/* ---------------------------------------------------- draining by itself */

/**
 * One quiet attempt at the queue, made while somebody happens to be using
 * the portal.
 *
 * This is what makes the outbox drain without anybody pressing anything: a
 * rate limit passes on its own, and the next admin page load after that is
 * what notices. Strictly bounded — at most one message, at most once a
 * minute — because it runs inside a page request somebody is waiting on.
 *
 * Returns the number sent, which is 0 or 1.
 *
 * $oncePerRequest is false only for cron.php, which calls it in a loop to
 * drain several messages; a page request still gets a single attempt.
 */
function fabo_outbox_tick(bool $oncePerRequest = true): int
{
    static $ranThisRequest = false;
    if ($oncePerRequest) {
        if ($ranThisRequest) {
            return 0;
        }
        $ranThisRequest = true;
    }

    try {
        if (!fabo_outbox_may_try()) {
            return 0;
        }
        $next = fabo_one('SELECT id FROM mail_outbox WHERE state = "waiting"
                          ORDER BY id LIMIT 1');
        if (!$next) {
            return 0;
        }
        [$ok] = fabo_outbox_send((int) $next['id']);
        if (!$ok) {
            return 0;
        }
        /* It went. The limit has clearly passed, so the rest may as well go
           too — but still one attempt apiece, spaced by the same rule on
           the next page load rather than in a burst here. */
        fabo_outbox_warm();
        return 1;
    } catch (Throwable $e) {
        error_log('FABO outbox tick: ' . $e->getMessage());
        return 0;
    }
}

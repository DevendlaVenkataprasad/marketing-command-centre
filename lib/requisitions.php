<?php
/**
 * Requisitions, and the queue they wait in.
 *
 * Somebody asks for a creative — a poster, a reel, a video — through the
 * requisition form. The request lands on the Requisitions sheet, and the
 * queue answers the only question the person asking actually has: when will
 * it be ready.
 *
 * The answer is worked out, not promised by hand:
 *
 *   1. Every type of work has a turnaround, in hours, kept in
 *      `requisition_types` and editable on the Sheet Management page.
 *   2. A new requisition joins the back of the queue. Its promised start is
 *      when everything ahead of it finishes; its promised delivery is that
 *      plus its own turnaround.
 *   3. Anything that changes the queue — a new request, one delivered, one
 *      cancelled, one extended — makes the whole queue recalculate, because
 *      a slot is only meaningful relative to what is in front of it.
 *
 * When a promised date moves, the person who asked is told. That is the
 * whole point of promising one.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sheets.php';
require_once __DIR__ . '/compute.php';      // fabo_today
require_once __DIR__ . '/mailtpl.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/google.php';          // fabo_setting / fabo_setting_put

/** The sheet the queue runs on. */
const FABO_REQ_SHEET = 'requisitions';

/** Statuses that take a requisition out of the queue. */
function fabo_req_closed_statuses(): array
{
    return ['Delivered', 'Cancelled', 'Rejected'];
}

/**
 * A turnaround in hours, as working days.
 *
 * The hours are elapsed hours, not hands-on ones: "48-hour turnaround" is
 * what a desk says and what the person asking hears, and it means two days,
 * not six hours of drawing spread over two. Weekends are stepped over when
 * the dates are laid out, so a Friday request with a 48-hour turnaround
 * lands on Tuesday rather than on Sunday.
 */
function fabo_req_days(int $hours): int
{
    return max(1, (int) ceil(max(1, $hours) / 24));
}

/**
 * Where the team that does the work should be written to.
 *
 * Per organisation, keyed on the owner: a client's requests go to their own
 * people, not to ours. Owner 0 keeps the original key, so nothing that was
 * already set up has to be set up again.
 */
function fabo_req_team_emails(int $owner = 0): array
{
    $raw = fabo_setting(fabo_req_team_key($owner), '');
    $out = [];
    foreach (preg_split('~[,;\s]+~', $raw) as $one) {
        $one = trim((string) $one);
        if ($one !== '' && filter_var($one, FILTER_VALIDATE_EMAIL)) {
            $out[] = $one;
        }
    }
    return array_values(array_unique($out));
}

/** The settings key holding an organisation's notification addresses. */
function fabo_req_team_key(int $owner = 0): string
{
    return $owner > 0 ? 'req_team_emails_' . $owner : 'req_team_emails';
}

/* ------------------------------------------------- types and turnarounds */

/**
 * What the portal starts with. Deliberately round numbers: the turnarounds
 * are a starting point for whoever runs the desk to correct, not a claim
 * about how long this team takes.
 */
function fabo_req_default_types(): array
{
    return [
        ['Poster / Creative',      24],
        ['Social Media Post',      24],
        ['Reel / Short Video',     72],
        ['Video (full edit)',     120],
        ['Brochure',               72],
        ['Banner / Standee',       48],
        ['Invitation',             24],
        ['Certificate',            24],
        ['Presentation Deck',      48],
        ['Email Mailer',           24],
        ['Website Page',           72],
        ['Photography',            48],
        ['Print — Other',          48],
        ['Other',                  48],
    ];
}

/** Seeds the type list the first time, and keeps the dropdown in step. */
function fabo_req_install_types(int $owner = 0): void
{
    try {
        /* Asked of this owner, not of the table. "Does anybody have types?"
           answers yes as soon as one client has made some, which would leave
           the shared set empty for good — and would have this page seed or
           sync somebody else's list while a client was looking at it. */
        $have = fabo_one('SELECT id FROM requisition_types WHERE client_id = ? LIMIT 1',
                         'i', [$owner]);
    } catch (Throwable $e) {
        return;                         // the table is not there yet
    }
    /* Only the shared set is ever seeded. An organisation starts by reading
       ours and takes its own copy the moment it changes anything, so seeding
       it here would give it a set it never asked for. */
    if (!$have && $owner === 0) {
        $n = 10;
        foreach (fabo_req_default_types() as [$name, $hours]) {
            fabo_exec('INSERT INTO requisition_types (name, turnaround_hours, sort_order, active)
                       VALUES (?,?,?,1)', 'sii', [$name, $hours, $n]);
            $n += 10;
        }
    }
    if ($have || $owner === 0) {
        fabo_req_sync_list($owner);
    }
}

/**
 * The dropdown on the sheet reads the same names as the queue.
 *
 * One list, two readers: if they were kept apart, somebody would pick a type
 * on the form that the queue has never heard of and get no date at all.
 */
function fabo_req_sync_list(int $owner = 0): void
{
    $want = [];
    foreach (fabo_req_types(true, $owner) as $t) {
        $want[(string) $t['name']] = (int) $t['sort_order'];
    }
    if (!$want) {
        return;
    }
    try {
        $have = [];
        foreach (fabo_all('SELECT id, value, active FROM lists
                           WHERE client_id = 0 AND list_name = "Requisition Type"') as $r) {
            $have[(string) $r['value']] = $r;
        }
        foreach ($want as $name => $sort) {
            if (isset($have[$name])) {
                if ((int) $have[$name]['active'] !== 1) {
                    fabo_exec('UPDATE lists SET active = 1, sort_order = ? WHERE id = ?',
                              'ii', [$sort, (int) $have[$name]['id']]);
                }
                continue;
            }
            fabo_exec('INSERT INTO lists (list_name, value, sort_order, active, client_id)
                       VALUES ("Requisition Type",?,?,1,0)', 'si', [$name, $sort]);
        }
        // A type switched off stops being offered, but is not deleted: rows
        // already carrying it keep reading properly.
        foreach ($have as $name => $r) {
            if (!isset($want[$name]) && (int) $r['active'] === 1) {
                fabo_exec('UPDATE lists SET active = 0 WHERE id = ?', 'i', [(int) $r['id']]);
            }
        }
    } catch (Throwable $e) {
        error_log('FABO requisition list sync: ' . $e->getMessage());
    }
}

/** Every type, or only the ones still offered. */
function fabo_req_types(bool $activeOnly = false, int $owner = 0): array
{
    try {
        $rows = fabo_all('SELECT * FROM requisition_types WHERE client_id = ?'
                       . ($activeOnly ? ' AND active = 1' : '')
                       . ' ORDER BY sort_order, id', 'i', [$owner]);
        /* An organisation that has never set up its own types runs on the
           shared ones, exactly as it did before it had a desk of its own.
           The first type it adds is the point it stops. */
        if (!$rows && $owner > 0) {
            $rows = fabo_all('SELECT * FROM requisition_types WHERE client_id = 0'
                           . ($activeOnly ? ' AND active = 1' : '')
                           . ' ORDER BY sort_order, id');
        }
        return $rows;
    } catch (Throwable $e) {
        return [];
    }
}

/** The turnaround for a type, in hours. An unknown type falls back to one day. */
function fabo_req_turnaround(string $type, int $owner = 0): int
{
    foreach (fabo_req_types(false, $owner) as $t) {
        if (strcasecmp((string) $t['name'], $type) === 0) {
            return max(1, (int) $t['turnaround_hours']);
        }
    }
    return (int) fabo_setting('req_default_hours', '24') ?: 24;
}

function fabo_req_type_add(string $name, int $hours, int $owner = 0): void
{
    $name = trim($name);
    if ($name === '') {
        throw new InvalidArgumentException('Give the type a name.');
    }
    if ($hours < 1) {
        throw new InvalidArgumentException('A turnaround of less than an hour is not a queue.');
    }
    /* Adopt FIRST, then look for a clash.

       The other way round is a trap, and it caught this: an organisation
       with no types of its own passes the clash check on an empty set, the
       adoption then copies every shared type across — including one already
       called what is being added — and the insert dies on the unique key
       with a database error nobody can act on. */
    fabo_req_types_adopt($owner);
    if (fabo_one('SELECT id FROM requisition_types WHERE name = ? AND client_id = ?',
                 'si', [$name, $owner])) {
        throw new InvalidArgumentException('There is already a type called “' . $name . '”.');
    }
    $max = (int) (fabo_one('SELECT MAX(sort_order) m FROM requisition_types
                            WHERE client_id = ?', 'i', [$owner])['m'] ?? 0);
    fabo_exec('INSERT INTO requisition_types (name, turnaround_hours, sort_order, active, client_id)
               VALUES (?,?,?,1,?)', 'siii', [$name, $hours, $max + 10, $owner]);
    fabo_req_sync_list($owner);
}

function fabo_req_type_save(int $id, string $name, int $hours, bool $active,
                            int $owner = 0): void
{
    $name = trim($name);
    if ($name === '') {
        throw new InvalidArgumentException('Give the type a name.');
    }
    if ($hours < 1) {
        throw new InvalidArgumentException('A turnaround of less than an hour is not a queue.');
    }
    $row = fabo_one('SELECT * FROM requisition_types WHERE id = ? AND client_id = ?',
                    'ii', [$id, $owner]);
    if (!$row) {
        throw new InvalidArgumentException('That type was not found.');
    }
    /* Renaming one type to what another is already called is a duplicate
       key, and a duplicate key reaches the reader as a database error. Said
       plainly here instead. */
    $clash = fabo_one('SELECT id FROM requisition_types
                       WHERE name = ? AND client_id = ? AND id <> ?',
                      'sii', [$name, $owner, $id]);
    if ($clash) {
        throw new InvalidArgumentException('There is already a type called “' . $name . '”.');
    }
    fabo_exec('UPDATE requisition_types SET name = ?, turnaround_hours = ?, active = ?
               WHERE id = ? AND client_id = ?',
              'siiii', [$name, $hours, $active ? 1 : 0, $id, $owner]);

    // Rows already asking for the old name must keep pointing at this type,
    // or their turnaround silently becomes the fallback.
    if ((string) $row['name'] !== $name) {
        try {
            /* Only this organisation's requests move across. Renaming a type
               on one desk must not rewrite what another desk's rows say. */
            if ($owner > 0) {
                fabo_exec('UPDATE `requisitions` SET req_type = ?
                           WHERE req_type = ? AND client_id = ?',
                          'ssi', [$name, (string) $row['name'], $owner]);
            } else {
                fabo_exec('UPDATE `requisitions` SET req_type = ? WHERE req_type = ?',
                          'ss', [$name, (string) $row['name']]);
            }
            fabo_exec('UPDATE lists SET value = ? WHERE client_id = ?
                       AND list_name = "Requisition Type" AND value = ?',
                      'sis', [$name, $owner, (string) $row['name']]);
        } catch (Throwable $e) {
            error_log('FABO requisition rename: ' . $e->getMessage());
        }
    }
    fabo_req_sync_list($owner);
}

function fabo_req_type_delete(int $id, int $owner = 0): void
{
    fabo_exec('DELETE FROM requisition_types WHERE id = ? AND client_id = ?', 'ii', [$id, $owner]);
    fabo_req_sync_list($owner);
}

/**
 * Give an organisation its own copy of the shared types.
 *
 * The same trick fabo_list_adopt() plays with dropdown values, and for the
 * same reason: the first change must start from what was already on screen,
 * not from an empty desk.
 */
function fabo_req_types_adopt(int $owner): void
{
    if ($owner <= 0) {
        return;
    }
    if (fabo_one('SELECT id FROM requisition_types WHERE client_id = ?', 'i', [$owner])) {
        return;                                   // already has its own
    }
    foreach (fabo_all('SELECT name, turnaround_hours, sort_order, active
                       FROM requisition_types WHERE client_id = 0
                       ORDER BY sort_order, id') as $r) {
        fabo_exec('INSERT INTO requisition_types
                   (name, turnaround_hours, sort_order, active, client_id)
                   VALUES (?,?,?,?,?)', 'siiii',
                  [$r['name'], (int) $r['turnaround_hours'], (int) $r['sort_order'],
                   (int) $r['active'], $owner]);
    }
}

/* ------------------------------------------------------------- the queue */

/** The requisitions still waiting, oldest request first. */
function fabo_req_open_rows(?int $owner = null): array
{
    $closed = fabo_req_closed_statuses();
    $in     = implode(',', array_fill(0, count($closed), '?'));
    /* null means every organisation's — what the nightly reschedule wants,
       since the whole queue moves together. A desk passes its own id and
       sees only its own requests. */
    if ($owner === null) {
        return fabo_all('SELECT * FROM `requisitions`
                         WHERE (status IS NULL OR status NOT IN (' . $in . '))
                         ORDER BY COALESCE(raised_on, DATE(created_at)), id',
                        str_repeat('s', count($closed)), $closed);
    }
    return fabo_all('SELECT * FROM `requisitions`
                     WHERE (status IS NULL OR status NOT IN (' . $in . '))
                       AND client_id = ?
                     ORDER BY COALESCE(raised_on, DATE(created_at)), id',
                    str_repeat('s', count($closed)) . 'i',
                    array_merge($closed, [$owner]));
}

/**
 * Recalculates the whole queue and writes each row's promise back.
 *
 * Returns the rows whose promised delivery moved, as
 * [row_id => ['row' => …, 'was' => 'Y-m-d']], so the caller can tell the
 * people whose dates changed — and only them.
 */
function fabo_req_reschedule(bool $replan = false): array
{
    $clock = strtotime(fabo_today() . ' 09:00:00');
    if ($clock === false) {
        $clock = time();
    }
    $moved = [];
    $place = 0;

    foreach (fabo_req_open_rows() as $row) {
        $place++;

        /* A date somebody has been given is a promise, and it holds.
         *
         * This used to recalculate every row from today on every run -- each
         * new request, and every few minutes from cron -- so the dates already
         * emailed out kept sliding, and each slide sent the person another
         * "Updated: new delivery date" mail on top of their acknowledgement.
         * Now the queue keeps its order and fits new requests in after the
         * promises already made. A promise moves only when the desk extends
         * it (fabo_req_extend, which tells that one person) or asks for the
         * whole queue to be re-planned ($replan, the desk's "Re-plan" button). */
        $promised = (string) ($row['promised_due'] ?? '');
        if (!$replan && $promised !== '' && strtotime($promised)) {
            fabo_exec('UPDATE `requisitions` SET queue_position = ? WHERE id = ?',
                      'ii', [$place, (int) $row['id']]);
            $clock = max($clock, fabo_req_add_days(strtotime($promised . ' 09:00:00'), 1));
            continue;
        }
        /* The turnaround the row's own organisation set, not ours: the same
           request type can be a day for one client and three for another. */
        $hours = fabo_req_turnaround((string) ($row['req_type'] ?? ''),
                                     (int) ($row['client_id'] ?? 0));

        /* Whole days, weekends stepped over: a 72-hour job asked for on a
           Thursday is due the following Monday, not on the Saturday. */
        $days  = fabo_req_days($hours);
        $start = fabo_req_add_days($clock, 0);      // never start on a weekend
        $due   = fabo_req_add_days($start, $days - 1);

        $wasDue = (string) ($row['promised_due'] ?? '');
        $newDue = date('Y-m-d', $due);

        fabo_exec('UPDATE `requisitions` SET queue_position = ?, promised_start = ?,
                        promised_due = ?, turnaround_hours = ? WHERE id = ?',
                  'issii', [$place, date('Y-m-d', $start), $newDue, $hours, (int) $row['id']]);

        if ($wasDue !== '' && $wasDue !== $newDue) {
            $row['promised_due']   = $newDue;
            $row['promised_start'] = date('Y-m-d', $start);
            $row['queue_position'] = $place;
            $moved[(int) $row['id']] = ['row' => $row, 'was' => $wasDue];
        }
        // The next one starts the working day after this one ends.
        $clock = fabo_req_add_days($due, 1);
    }
    return $moved;
}

/** Adds working days, stepping over Saturday and Sunday. */
function fabo_req_add_days(int $from, int $days): int
{
    $t = $from;
    while ($days > 0) {
        $t += 86400;
        if ((int) date('N', $t) < 6) {
            $days--;
        }
    }
    // Never promise a weekend, even for a same-day job.
    while ((int) date('N', $t) > 5) {
        $t += 86400;
    }
    return $t;
}

/** One requisition, by row id. */
function fabo_req_row(int $id, ?int $owner = null): ?array
{
    if ($owner !== null) {
        return fabo_one('SELECT * FROM `requisitions` WHERE id = ? AND client_id = ?',
                        'ii', [$id, $owner]);
    }
    return fabo_one('SELECT * FROM `requisitions` WHERE id = ?', 'i', [$id]);
}

/**
 * Pushes one requisition's promised delivery back, by hand.
 *
 * The queue is recalculated afterwards because everything behind it moves
 * too, and everybody whose date changed is told — the person who asked for
 * the extension included.
 */
function fabo_req_extend(int $id, string $newDue, string $reason): array
{
    $row = fabo_req_row($id);
    if (!$row) {
        throw new InvalidArgumentException('That requisition was not found.');
    }
    $when = strtotime($newDue);
    if (!$when) {
        throw new InvalidArgumentException('Give the new date.');
    }
    $was = (string) ($row['promised_due'] ?? '');
    if ($was !== '' && $when <= strtotime($was)) {
        throw new InvalidArgumentException('An extension moves the date later than it was.');
    }

    fabo_exec('UPDATE `requisitions` SET promised_due = ?, extended_count = COALESCE(extended_count,0) + 1,
                    extension_reason = ? WHERE id = ?',
              'ssi', [date('Y-m-d', $when), mb_substr(trim($reason), 0, 255), $id]);

    $after = fabo_req_row($id);
    $sent  = fabo_req_tell_requester($after, $was, $reason);
    return [$after, $sent];
}

/* ------------------------------------------------------------- the post */

/** A requisition in words, for a mail or a log line. */
function fabo_req_summary(array $row): array
{
    $out = [];
    $pairs = [
        'Requisition'   => (string) ($row['req_id'] ?? ''),
        'Type'          => (string) ($row['req_type'] ?? ''),
        'For'           => (string) ($row['event_name'] ?? ''),
        'How many'      => (string) ($row['qty'] ?? ''),
        'Needed by'     => fabo_req_day((string) ($row['required_by'] ?? '')),
        'Queue position'=> (string) ($row['queue_position'] ?? ''),
        'Work starts'   => fabo_req_day((string) ($row['promised_start'] ?? '')),
        'Delivery'      => fabo_req_day((string) ($row['promised_due'] ?? '')),
    ];
    foreach ($pairs as $k => $v) {
        if (trim($v) !== '') {
            $out[$k] = $v;
        }
    }
    return $out;
}

/** A date as somebody would say it, or '' when there is none. */
function fabo_req_day(string $d): string
{
    $t = $d !== '' ? strtotime($d) : false;
    return $t ? date('D, d M Y', $t) : '';
}

/**
 * What the person who asked should be told, as [subject, heading, body].
 *
 * Composing and sending are kept apart so the wording can be checked without
 * a mail server in the room — and so a failed send never means a message
 * nobody can see the text of.
 */
function fabo_req_requester_mail(array $row, string $wasDue = '', string $reason = ''): array
{
    $moved = $wasDue !== '' && $wasDue !== (string) ($row['promised_due'] ?? '');
    $head  = $moved ? 'Your requisition has a new delivery date'
                    : 'Your requisition is in the queue';

    $inner  = fabo_mail_p('Hello ' . e(trim((string) ($row['raised_by'] ?? '')) ?: 'there') . ',');
    $inner .= $moved
        ? fabo_mail_p('The date we gave you for <strong>'
            . e((string) ($row['req_id'] ?? 'your requisition'))
            . '</strong> has moved. The new date is below.')
        : fabo_mail_p('Thank you — your requisition has been logged and placed in the queue. '
            . 'The dates below come from the work already ahead of it.');

    $inner .= fabo_mail_big(fabo_req_day((string) ($row['promised_due'] ?? '')) ?: 'to be confirmed',
                            $moved ? 'New delivery date' : 'Expected delivery');
    if ($moved) {
        $inner .= fabo_mail_note('Previously ' . e(fabo_req_day($wasDue)) . '.'
                 . ($reason !== '' ? ' Reason: ' . e($reason) : ''));
    }
    $inner .= fabo_mail_rows(fabo_req_summary($row));
    $inner .= fabo_mail_p('If any of this is wrong, reply to this message and we will correct it.',
                          'color:' . fabo_mail_soft() . ';font-size:13px;');

    $subject = ($moved ? 'Updated: ' : '')
             . trim((string) ($row['req_id'] ?? 'Requisition'))
             . ' — ' . ((string) ($row['req_type'] ?? 'requisition'))
             . ' by ' . (fabo_req_day((string) ($row['promised_due'] ?? '')) ?: 'a date to be confirmed');

    return [$subject, $head, $inner];
}

/**
 * Tells the person who asked where they are in the queue.
 *
 * No links: the requester has no login, and a link they cannot open is worse
 * than no link at all.
 */
function fabo_req_tell_requester(array $row, string $wasDue = '', string $reason = '',
                                 bool $again = false): bool
{
    $to = trim((string) ($row['raised_by_email'] ?? ''));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    /* One acknowledgement per requisition. A second "your requisition is in
       the queue" is only sent when the desk asks for it ($again, the Resend
       button); a moved date is a different message and always goes. */
    $isMove = $wasDue !== '' && $wasDue !== (string) ($row['promised_due'] ?? '');
    if (!$isMove && !$again && (int) ($row['id'] ?? 0) > 0) {
        $state = (string) (fabo_one('SELECT confirm_state FROM `requisitions` WHERE id = ?', 'i',
                                    [(int) $row['id']])['confirm_state'] ?? '');
        if (str_starts_with($state, 'Emailed ')) {
            return true;                    // already told, and that is enough
        }
    }
    /* The address is typed by whoever filled in a public form, so an
       automatic acknowledgement to any one address is capped per day: the
       portal's mailbox is not a way to send mail at a stranger. Messages the
       desk sends on purpose -- a resend, a moved date -- are not limited. */
    if (!$isMove && !$again) {
        require_once __DIR__ . '/throttle.php';
        if (fabo_throttle_blocked('req-mail', $to, 3, 86400)) {
            fabo_req_note_state((int) ($row['id'] ?? 0), false, 'daily limit for this address reached', $to);
            return false;
        }
        fabo_throttle_hit('req-mail', $to);
    }
    [$subject, $head, $inner] = fabo_req_requester_mail($row, $wasDue, $reason);
    [$ok, $why] = fabo_req_post($to, $subject, $head, $inner, (int) ($row['client_id'] ?? 0));

    /* Written on the row itself, so a confirmation that never went is
       visible on the sheet rather than buried in a log nobody reads. */
    fabo_req_note_state((int) ($row['id'] ?? 0), $ok, $why, $to);
    return $ok;
}

/** Records how the last confirmation went, in words, on the row. */
function fabo_req_note_state(int $rowId, bool $ok, string $why, string $to): void
{
    if ($rowId <= 0) {
        return;
    }
    $said = $ok
        ? 'Emailed ' . $to . ' on ' . date('d M Y H:i')
        : 'NOT SENT (' . ($why !== '' ? $why : 'the mail server refused it') . ')';
    try {
        fabo_exec('UPDATE `requisitions` SET confirm_state = ? WHERE id = ?',
                  'si', [mb_substr($said, 0, 255), $rowId]);
    } catch (Throwable $e) {
        error_log('FABO requisition confirm state: ' . $e->getMessage());
    }
}

/** The ones whose confirmation did not go, for the desk to chase. */
function fabo_req_unconfirmed(?int $owner = null): array
{
    try {
        if ($owner !== null) {
            return fabo_all('SELECT * FROM `requisitions`
                             WHERE confirm_state LIKE "NOT SENT%" AND client_id = ?
                             ORDER BY id DESC LIMIT 50', 'i', [$owner]);
        }
        return fabo_all('SELECT * FROM `requisitions`
                         WHERE confirm_state LIKE "NOT SENT%"
                         ORDER BY id DESC LIMIT 50');
    } catch (Throwable $e) {
        return [];
    }
}

/** What the team that does the work should be told, as [subject, heading, body]. */
function fabo_req_team_mail(array $row): array
{
    $inner  = fabo_mail_p('A requisition has been submitted and is now in the queue.');
    $inner .= fabo_mail_rows(fabo_req_summary($row) + [
        'Raised by' => trim((string) ($row['raised_by'] ?? '')) . ' · '
                     . trim((string) ($row['raised_by_phone'] ?? '')),
        'Hub / Spoke' => (string) ($row['spoke'] ?? ''),
    ]);
    $content = trim((string) ($row['content'] ?? ''));
    if ($content !== '') {
        $inner .= fabo_mail_quote($content);
    }
    $inner .= fabo_mail_button('Open the portal');

    return ['New requisition ' . trim((string) ($row['req_id'] ?? ''))
            . ' — ' . (string) ($row['req_type'] ?? ''),
            'A new requisition is in the queue', $inner];
}

/** Sends it to every address the desk has given. */
function fabo_req_tell_team(array $row): int
{
    /* Everyone on the team list except the person who asked: they have
       their own acknowledgement, and the team's message is not for them. */
    $asker = strtolower(trim((string) ($row['raised_by_email'] ?? '')));
    $to    = array_values(array_filter(fabo_req_team_emails((int) ($row['client_id'] ?? 0)),
                                       static fn($e) => strtolower(trim((string) $e)) !== $asker));
    if (!$to) {
        return 0;
    }
    [$subject, $head, $inner] = fabo_req_team_mail($row);
    $sent = 0;
    foreach ($to as $one) {
        [$ok] = fabo_req_post($one, $subject, $head, $inner, (int) ($row['client_id'] ?? 0));
        $sent += $ok ? 1 : 0;
    }
    return $sent;
}

/**
 * One message out, wrapped in the frame of the organisation the requisition
 * is filed under ($owner, its client_id) -- theirs, not ours, when it is a
 * client's form that took it in.
 *
 * Answers [sent?, why], because "why not" is the useful half when a mail
 * server refuses and somebody outside the portal is waiting to hear.
 */
function fabo_req_post(string $to, string $subject, string $heading, string $inner,
                       int $owner = 0): array
{
    require_once __DIR__ . '/notify.php';
    require_once __DIR__ . '/brand.php';
    $html = fabo_mail_wrap(fabo_org_brand($owner)[1], $heading, $inner, strip_tags($subject));
    // A plain-text twin, so the message reads sensibly where HTML does not.
    $breaks = ['</p>' => "\n", '<br>' => "\n", '</tr>' => "\n"];
    $text   = trim(preg_replace('~\n{3,}~', "\n\n",
        strip_tags(strtr($inner, $breaks))));
    // fabo_send_mail answers [sent?, why], never throwing.
    [$ok, $why] = fabo_send_mail($to, $subject, $text, null, $html);
    return [(bool) $ok, (string) $why];
}

/**
 * Everything that happens when a requisition arrives: it joins the queue,
 * the queue is recalculated, and everyone affected is told.
 *
 * Returns what was sent, so the page that called it can say so plainly
 * rather than claiming more than happened.
 */
function fabo_req_accept(int $rowId): array
{
    fabo_req_install_types();
    $moved = fabo_req_reschedule();

    $row = fabo_req_row($rowId);
    if (!$row) {
        return ['ok' => false, 'requester' => false, 'team' => 0, 'moved' => 0];
    }
    $told = fabo_req_tell_requester($row);
    $team = fabo_req_tell_team($row);

    // Everyone already in the queue whose date moved because of this one.
    $also = 0;
    foreach ($moved as $id => $m) {
        if ((int) $id === $rowId) {
            continue;
        }
        $also += fabo_req_tell_requester($m['row'], $m['was']) ? 1 : 0;
    }
    return ['ok' => true, 'requester' => $told, 'team' => $team, 'moved' => $also, 'row' => $row];
}

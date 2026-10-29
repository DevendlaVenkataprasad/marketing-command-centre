<?php
/**
 * Meetings: scheduling them, inviting people, and what came of them.
 *
 * A meeting is the portal's own record. Google Calendar and Meet are an
 * option layered on top (lib/google.php): when the portal is connected, a
 * meeting also becomes a calendar event with a Meet link. Without that it
 * still works — you paste a link, or hold it in a room — and every other
 * part of the module behaves identically. Nothing here depends on Google.
 *
 * Who sees a meeting is deliberately narrow: the administrator sees all, and
 * everyone else sees the ones they organised, the ones they were invited to,
 * and (for a client) the ones held with their organisation. A meeting is not
 * a sheet, so it is not granted — it is a default desk for everybody.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

/* -------------------------------------------------------------- states */

function fabo_meeting_states(): array
{
    return [
        'scheduled' => ['Scheduled', 'draft'],
        'held'      => ['Held',      'paid'],
        'cancelled' => ['Cancelled', 'cancelled'],
        'no_show'   => ['No show',   'overdue'],
    ];
}

function fabo_meeting_state_label(string $s): string
{
    return fabo_meeting_states()[$s][0] ?? $s;
}

function fabo_meeting_state_tone(string $s): string
{
    return fabo_meeting_states()[$s][1] ?? 'draft';
}

/** Scheduled, and the end time has gone by: it needs closing off. */
function fabo_meeting_overdue(array $m): bool
{
    return ($m['state'] ?? '') === 'scheduled'
        && !empty($m['ends_at'])
        && strtotime((string) $m['ends_at']) < time();
}

/** 5 = excellent. Kept as words so the scale means the same to everyone. */
function fabo_meeting_ratings(): array
{
    return [
        5 => 'Excellent — everything we needed',
        4 => 'Good — useful, minor gaps',
        3 => 'Fair — partly useful',
        2 => 'Poor — little came of it',
        1 => 'Wasted — should not have happened',
    ];
}

/* ------------------------------------------------------------- reading */

function fabo_meeting(int $id): ?array
{
    return fabo_one('SELECT * FROM meetings WHERE id = ?', 'i', [$id]);
}

/**
 * The SQL fragment limiting a viewer to the meetings they may see.
 *
 * The administrator gets no restriction. Everyone else gets three ways in:
 * they organised it, they are on the guest list, or it belongs to their
 * organisation. A client's staff see their organisation's meetings too,
 * which is the point of a shared desk.
 */
function fabo_meeting_scope(array $viewer): array
{
    if (!empty($viewer['is_admin'])) {
        return ['sql' => '', 'types' => '', 'params' => []];
    }
    $c  = $viewer['client'] ?? [];
    $me = (int) ($c['id'] ?? 0);

    /*
     * The organisation whose meetings they share in.
     *
     * Anyone a client created — their staff or a sub-account — shares in
     * that client's; a client shares in their own. Our own team has no
     * parent, so they share in nothing by default and see only what they
     * called or were asked to. fabo_row_owner() is not used here because it
     * returns null for every team member, which would leave a client's own
     * staff unable to see their own organisation's diary.
     */
    $org = (int) ($c['parent_id'] ?? 0);
    if ($org === 0 && ($c['kind'] ?? 'client') === 'client') {
        $org = $me;
    }

    $sql = ' AND (m.organiser_id = ?'
         . ' OR EXISTS (SELECT 1 FROM meeting_guests g
                        WHERE g.meeting_id = m.id AND g.client_id = ?)';
    $t   = 'ii';
    $p   = [$me, $me];
    if ($org > 0) {
        $sql .= ' OR m.client_id = ?';
        $t   .= 'i';
        $p[]  = $org;
    }
    $sql .= ')';
    return ['sql' => $sql, 'types' => $t, 'params' => $p];
}

/** One meeting, but only if this viewer may see it. */
function fabo_meeting_for(array $viewer, int $id): ?array
{
    $sc = fabo_meeting_scope($viewer);
    return fabo_one('SELECT m.* FROM meetings m WHERE m.id = ?' . $sc['sql'],
                    'i' . $sc['types'], array_merge([$id], $sc['params']));
}

/**
 * The meetings this viewer may see. $when is 'upcoming', 'past' or '' for
 * both; $clientId narrows to one organisation; $state to one state.
 */
function fabo_meetings(array $viewer, string $when = '', int $clientId = 0,
                       string $state = '', int $limit = 300): array
{
    $sc  = fabo_meeting_scope($viewer);
    $sql = 'SELECT m.*, c.name AS cname, c.organisation AS corg
            FROM meetings m LEFT JOIN clients c ON c.id = m.client_id
            WHERE 1=1' . $sc['sql'];
    $t = $sc['types'];
    $p = $sc['params'];

    if ($when === 'upcoming') {
        $sql .= " AND m.ends_at >= NOW() AND m.state = 'scheduled'";
    } elseif ($when === 'past') {
        $sql .= " AND (m.ends_at < NOW() OR m.state <> 'scheduled')";
    }
    if ($clientId > 0) {
        $sql .= ' AND m.client_id = ?';
        $t   .= 'i';
        $p[]  = $clientId;
    }
    if ($state !== '' && isset(fabo_meeting_states()[$state])) {
        $sql .= ' AND m.state = ?';
        $t   .= 's';
        $p[]  = $state;
    }
    // Upcoming reads soonest first; anything else reads newest first.
    $sql .= $when === 'upcoming'
        ? ' ORDER BY m.starts_at ASC'
        : ' ORDER BY m.starts_at DESC';
    $sql .= ' LIMIT ' . max(1, min(500, $limit));
    return fabo_all($sql, $t, $p);
}

/** The guest list, hosts first. */
function fabo_meeting_guests(int $meetingId): array
{
    return fabo_all("SELECT * FROM meeting_guests WHERE meeting_id = ?
                     ORDER BY role = 'host' DESC, name, email", 'i', [$meetingId]);
}

/** Every address on the list, for the mailer. */
function fabo_meeting_emails(int $meetingId): array
{
    return array_column(fabo_meeting_guests($meetingId), 'email');
}

/**
 * May this viewer change the meeting — reschedule it, invite people, close
 * it off, write the minutes?
 *
 * The administrator, whoever called it, and the head of the organisation it
 * is with. A guest who is none of those can read it and nothing more, which
 * is the right default for a meeting somebody else convened.
 */
function fabo_meeting_may_manage(array $viewer, array $m): bool
{
    if (!empty($viewer['is_admin'])) {
        return true;
    }
    $me = (int) ($viewer['client']['id'] ?? 0);
    if ($me > 0 && (int) $m['organiser_id'] === $me) {
        return true;
    }
    // The head speaks for their organisation, here as everywhere else.
    require_once __DIR__ . '/deletions.php';
    return fabo_may_delete($viewer) && (int) $m['client_id'] === $me;
}

/* ------------------------------------------------------------- writing */

/** Loose but useful: rejects what would certainly bounce. */
function fabo_valid_email(string $e): bool
{
    return (bool) filter_var(trim($e), FILTER_VALIDATE_EMAIL);
}

/**
 * Creates a meeting. Returns [id, message].
 *
 * The times arrive as the browser's datetime-local strings; they are stored
 * as-is in the portal's own timezone, which is the one every other date in
 * the portal already uses.
 */
function fabo_meeting_create(array $viewer, string $title, string $agenda,
                             string $starts, string $ends, int $clientId,
                             string $location = ''): array
{
    $title = trim($title);
    if ($title === '') {
        return [0, 'Give the meeting a title.'];
    }
    $s = fabo_meeting_time($starts);
    $e = fabo_meeting_time($ends);
    if ($s === null) {
        return [0, 'Give the date and time it starts.'];
    }
    if ($e === null) {
        return [0, 'Give the date and time it ends.'];
    }
    if (strtotime($e) <= strtotime($s)) {
        return [0, 'It has to end after it starts.'];
    }
    if ($clientId > 0 && !fabo_one('SELECT id FROM clients WHERE id = ?', 'i', [$clientId])) {
        return [0, 'That client no longer exists.'];
    }

    $who   = !empty($viewer['is_admin']) ? 'Administrator' : (string) $viewer['client']['name'];
    $whoId = !empty($viewer['is_admin']) ? 0 : (int) $viewer['client']['id'];

    $id = fabo_exec('INSERT INTO meetings (client_id, organiser_id, organiser_name, title,
                      agenda, starts_at, ends_at, location)
                     VALUES (?,?,?,?,?,?,?,?)', 'iissssss',
                    [$clientId, $whoId, $who, mb_substr($title, 0, 190),
                     trim($agenda) ?: null, $s, $e, trim($location) ?: null]);

    // Whoever called it is on the list, so the invite reads correctly and
    // they are counted among the people who were there.
    $email = !empty($viewer['is_admin'])
        ? (string) (defined('ADMIN_INBOX_EMAIL') ? ADMIN_INBOX_EMAIL : '')
        : (string) ($viewer['client']['email'] ?? '');
    if (fabo_valid_email($email)) {
        fabo_meeting_guest_add((int) $id, $email, $who, $whoId ?: null, 'host');
    }
    return [(int) $id, 'Meeting created.'];
}

/** Accepts "2026-09-30T14:30" or "2026-09-30 14:30"; null when unusable. */
function fabo_meeting_time(string $v): ?string
{
    $v = trim(str_replace('T', ' ', $v));
    if ($v === '') {
        return null;
    }
    $ts = strtotime($v);
    return $ts === false ? null : date('Y-m-d H:i:00', $ts);
}

/** Returns [ok, message]. Only a scheduled meeting can be rescheduled. */
function fabo_meeting_update(int $id, string $title, string $agenda,
                             string $starts, string $ends, string $location,
                             string $meetUrl): array
{
    $m = fabo_meeting($id);
    if (!$m) {
        return [false, 'That meeting no longer exists.'];
    }
    $title = trim($title);
    if ($title === '') {
        return [false, 'Give the meeting a title.'];
    }
    $s = fabo_meeting_time($starts);
    $e = fabo_meeting_time($ends);
    if ($s === null || $e === null || strtotime($e) <= strtotime($s)) {
        return [false, 'Check the start and end times.'];
    }
    $url = trim($meetUrl);
    if ($url !== '' && !preg_match('~^https://~i', $url)) {
        return [false, 'A meeting link has to start with https://'];
    }
    fabo_exec('UPDATE meetings SET title=?, agenda=?, starts_at=?, ends_at=?,
                 location=?, meet_url=?, updated_at=NOW() WHERE id=?',
              'ssssssi', [mb_substr($title, 0, 190), trim($agenda) ?: null, $s, $e,
                          trim($location) ?: null, $url ?: null, $id]);
    return [true, 'Meeting updated.'];
}

/** Adds one guest. Returns [id, alreadyThere]. */
function fabo_meeting_guest_add(int $meetingId, string $email, string $name = '',
                                ?int $clientId = null, string $role = 'guest'): array
{
    $email = strtolower(trim($email));
    if (!fabo_valid_email($email)) {
        return [0, false];
    }
    $have = fabo_one('SELECT id FROM meeting_guests WHERE meeting_id = ? AND email = ?',
                     'is', [$meetingId, $email]);
    if ($have) {
        return [(int) $have['id'], true];
    }
    $id = fabo_exec('INSERT INTO meeting_guests (meeting_id, client_id, name, email, role)
                     VALUES (?,?,?,?,?)', 'iisss',
                    [$meetingId, $clientId, trim($name) ?: null, $email, $role]);
    return [(int) $id, false];
}

function fabo_meeting_guest_remove(int $meetingId, int $guestId): array
{
    $g = fabo_one('SELECT * FROM meeting_guests WHERE id = ? AND meeting_id = ?',
                  'ii', [$guestId, $meetingId]);
    if (!$g) {
        return [false, 'Already gone.'];
    }
    if ($g['role'] === 'host') {
        return [false, 'The organiser stays on the list.'];
    }
    fabo_exec('DELETE FROM meeting_guests WHERE id = ?', 'i', [$guestId]);
    return [true, 'Removed from the invitation.'];
}

/**
 * Takes a box of addresses — commas, semicolons, spaces or newlines — and
 * adds each one. Returns [addedCount, skippedCount, badAddresses].
 */
function fabo_meeting_guests_add_many(int $meetingId, string $blob): array
{
    $parts = preg_split('/[,;\s]+/', trim($blob)) ?: [];
    $added = 0;
    $dupes = 0;
    $bad   = [];
    foreach ($parts as $raw) {
        $one = trim($raw);
        if ($one === '') {
            continue;
        }
        if (!fabo_valid_email($one)) {
            $bad[] = $one;
            continue;
        }
        [$id, $already] = fabo_meeting_guest_add($meetingId, $one);
        if ($already) { $dupes++; } elseif ($id) { $added++; }
    }
    return [$added, $dupes, $bad];
}

/**
 * Everyone on the portal who could be invited, for the picker.
 *
 * The admin is offered every active client and team member. A client is
 * offered their own people and us. Nobody is offered another client, and
 * committee members are never offered: meetings are not their desk.
 */
function fabo_meeting_invitables(array $viewer): array
{
    if (!empty($viewer['is_admin'])) {
        $rows = fabo_all("SELECT id, name, kind, email, organisation, parent_id FROM clients
                          WHERE revoked_at IS NULL AND email <> '' AND is_committee = 0
                          ORDER BY kind, organisation, name");
    } else {
        $c   = $viewer['client'] ?? [];
        $org = (int) ($c['parent_id'] ?? 0);
        if ($org === 0 && ($c['kind'] ?? 'client') === 'client') {
            $org = (int) ($c['id'] ?? 0);
        }
        if ($org > 0) {
            // A client and their people see each other, and nobody else.
            $rows = fabo_all("SELECT id, name, kind, email, organisation, parent_id FROM clients
                              WHERE revoked_at IS NULL AND email <> '' AND is_committee = 0
                                AND (id = ? OR parent_id = ?)
                              ORDER BY kind, name", 'ii', [$org, $org]);
        } else {
            // Our own team: everyone, the same as the administrator sees.
            $rows = fabo_all("SELECT id, name, kind, email, organisation, parent_id FROM clients
                              WHERE revoked_at IS NULL AND email <> '' AND is_committee = 0
                              ORDER BY kind, organisation, name");
        }
    }

    // Tag each person with the organisation they belong to, so the invite
    // list can follow the "with which client" choice on the form. 0 is us:
    // our own staff have no parent and are not a client of anybody.
    foreach ($rows as &$r) {
        $parent = (int) ($r['parent_id'] ?? 0);
        if ($parent > 0) {
            $r['org'] = $parent;
        } elseif (($r['kind'] ?? 'client') === 'client') {
            $r['org'] = (int) $r['id'];       // heads their own organisation
        } else {
            $r['org'] = 0;                    // our own staff
        }
    }
    unset($r);
    return $rows;
}

/**
 * The people who belong to one organisation, out of a list already fetched.
 * $clientId 0 means ours. Used to keep the invite list in step with the
 * client the meeting is with.
 */
function fabo_meeting_invitables_for(array $people, int $clientId): array
{
    return array_values(array_filter(
        $people,
        static fn($p) => (int) ($p['org'] ?? 0) === $clientId
    ));
}

/**
 * The invitable people split into organisations, ours first.
 *
 * Returns org id => ['label' => ..., 'people' => [...]]. Used by the invite
 * picker so a name is always read under the organisation it belongs to,
 * rather than as one long list where "Sunil" could be anybody's.
 */
function fabo_meeting_people_groups(array $people): array
{
    $out = [];
    foreach ($people as $p) {
        $org = (int) ($p['org'] ?? 0);
        if (!isset($out[$org])) {
            $out[$org] = ['label' => '', 'people' => []];
        }
        $out[$org]['people'][] = $p;
    }
    foreach ($out as $org => &$grp) {
        if ($org === 0) {
            $grp['label'] = 'Our own team';
            continue;
        }
        $c = fabo_one('SELECT name, organisation FROM clients WHERE id = ?', 'i', [$org]);
        $grp['label'] = $c
            ? (($c['organisation'] ?? '') !== '' ? (string) $c['organisation'] : (string) $c['name'])
            : 'A client since removed';
    }
    unset($grp);

    // Ours at the top: it is the group most often wanted, and it is the one
    // that appears whatever client is chosen.
    if (isset($out[0])) {
        $ours = $out[0];
        unset($out[0]);
        $out = [0 => $ours] + $out;
    }
    return $out;
}

/* ------------------------------------------------------ after the event */

/** Records how it went. Returns [ok, message]. */
function fabo_meeting_close(int $id, string $state, ?int $rating,
                            string $ratingNote, string $outcome): array
{
    $m = fabo_meeting($id);
    if (!$m) {
        return [false, 'That meeting no longer exists.'];
    }
    if (!isset(fabo_meeting_states()[$state])) {
        return [false, 'Unknown status.'];
    }
    // A rating is about a meeting that happened; the others have nothing to rate.
    if ($state !== 'held') {
        $rating = null;
    }
    if ($rating !== null && !isset(fabo_meeting_ratings()[$rating])) {
        return [false, 'Choose a rating from the list.'];
    }
    if ($state === 'cancelled' && trim($outcome) === '') {
        return [false, 'Say why it was cancelled, so the record makes sense later.'];
    }
    fabo_exec('UPDATE meetings SET state=?, rating=?, rating_note=?, outcome=?,
                 updated_at=NOW() WHERE id=?', 'sissi',
              [$state, $rating, trim($ratingNote) ?: null, trim($outcome) ?: null, $id]);
    return [true, 'Marked ' . strtolower(fabo_meeting_state_label($state)) . '.'];
}

/** Stores minutes, however they were arrived at. Returns [ok, message]. */
function fabo_meeting_set_minutes(int $id, string $minutes, string $source = 'manual'): array
{
    if (!fabo_meeting($id)) {
        return [false, 'That meeting no longer exists.'];
    }
    if (!in_array($source, ['manual', 'transcript', 'gemini'], true)) {
        $source = 'manual';
    }
    $minutes = trim($minutes);
    if ($minutes === '') {
        fabo_exec('UPDATE meetings SET minutes=NULL, minutes_source=NULL, minutes_at=NULL,
                     updated_at=NOW() WHERE id=?', 'i', [$id]);
        return [true, 'Minutes cleared.'];
    }
    fabo_exec('UPDATE meetings SET minutes=?, minutes_source=?, minutes_at=NOW(),
                 updated_at=NOW() WHERE id=?', 'ssi', [$minutes, $source, $id]);
    return [true, 'Minutes saved.'];
}

function fabo_meeting_set_transcript(int $id, string $text): array
{
    if (!fabo_meeting($id)) {
        return [false, 'That meeting no longer exists.'];
    }
    $text = trim($text);
    fabo_exec('UPDATE meetings SET transcript=?, updated_at=NOW() WHERE id=?',
              'si', [$text ?: null, $id]);
    return [true, $text === '' ? 'Transcript cleared.' : 'Transcript saved.'];
}

function fabo_meeting_delete(int $id): array
{
    $m = fabo_meeting($id);
    if (!$m) {
        return [false, 'Already gone.'];
    }
    if ($m['state'] === 'held') {
        return [false, 'A meeting that was held stays on the record. Cancel it instead if it was logged in error.'];
    }
    fabo_exec('DELETE FROM meeting_guests WHERE meeting_id = ?', 'i', [$id]);
    fabo_exec('DELETE FROM meetings WHERE id = ?', 'i', [$id]);
    return [true, 'Meeting removed.'];
}

/* ------------------------------------------------------------- figures */

/** The tiles at the top of the page, for whoever is looking. */
function fabo_meeting_metrics(array $viewer, int $clientId = 0): array
{
    $sc  = fabo_meeting_scope($viewer);
    $sql = 'SELECT
        COALESCE(SUM(m.state = "scheduled" AND m.ends_at >= NOW()),0)  AS upcoming,
        COALESCE(SUM(m.state = "scheduled" AND m.ends_at <  NOW()),0)  AS to_close,
        COALESCE(SUM(m.state = "held"),0)                              AS held,
        COALESCE(SUM(m.state = "cancelled"),0)                         AS cancelled,
        COALESCE(SUM(m.state = "no_show"),0)                           AS no_show,
        COALESCE(SUM(m.state = "held" AND m.minutes IS NOT NULL),0)    AS minuted,
        AVG(NULLIF(m.rating,0))                                        AS avg_rating,
        COUNT(*)                                                       AS total
        FROM meetings m WHERE 1=1' . $sc['sql'];
    $t = $sc['types'];
    $p = $sc['params'];
    if ($clientId > 0) {
        $sql .= ' AND m.client_id = ?';
        $t   .= 'i';
        $p[]  = $clientId;
    }
    $r = fabo_one($sql, $t, $p);

    $held = (int) $r['held'];
    return [
        'upcoming'   => (int) $r['upcoming'],
        'to_close'   => (int) $r['to_close'],
        'held'       => $held,
        'cancelled'  => (int) $r['cancelled'],
        'no_show'    => (int) $r['no_show'],
        'minuted'    => (int) $r['minuted'],
        'total'      => (int) $r['total'],
        'avg_rating' => $r['avg_rating'] === null ? null : round((float) $r['avg_rating'], 1),
        // Of the meetings that happened, how many have a written record.
        'minuted_pct' => $held > 0 ? (float) (int) $r['minuted'] / $held : null,
    ];
}

/** How a meeting reads in one line, for a notice or a subject. */
function fabo_meeting_when(array $m): string
{
    $s = strtotime((string) $m['starts_at']);
    $e = strtotime((string) $m['ends_at']);
    return date('D d M Y, H:i', $s) . '-' . date('H:i', $e);
}

/* ----------------------------------------------------------- responses */

/*
 * Who is coming. Each guest's answer is kept on their meeting_guests row
 * (response = yes / maybe / no, NULL for no reply yet). It arrives three ways:
 *
 *   - the Going / Maybe / Can't go buttons in the invitation email, which
 *     open rsvp.php with a signed token for that one guest;
 *   - a portal user answering on the meeting page;
 *   - Google Calendar, when the meeting is on it: each attendee's own
 *     accept / decline in their calendar is read back by
 *     fabo_meeting_sync_responses().
 *
 * The organiser can also set an answer by hand, for the ones who reply by
 * phone.
 */

/** answer => [label, tone]. */
function fabo_meeting_responses(): array
{
    return [
        'yes'   => ['Going',    'green'],
        'maybe' => ['Maybe',    'amber'],
        'no'    => ['Declined', 'red'],
    ];
}

/**
 * The token in a guest's RSVP link: an HMAC of their guest id under the
 * portal's own sealing key. It proves the link was issued by us for that
 * guest and cannot be edited to answer for anybody else.
 */
function fabo_rsvp_token(int $guestId): string
{
    require_once __DIR__ . '/cryptolink.php';
    return substr(fabo_b64url(hash_hmac('sha256', 'rsvp|' . $guestId, fabo_seal_key(), true)), 0, 24);
}

/** The guest a link belongs to, or null when the token does not match. */
function fabo_rsvp_guest(int $guestId, string $token): ?array
{
    if ($guestId <= 0 || $token === '' || !hash_equals(fabo_rsvp_token($guestId), $token)) {
        return null;
    }
    return fabo_one('SELECT * FROM meeting_guests WHERE id = ?', 'i', [$guestId]);
}

/** A full link to rsvp.php for one guest, for an email. */
function fabo_rsvp_url(array $guest, array $q = []): string
{
    require_once __DIR__ . '/ui.php';
    return rtrim(fabo_portal_url(), '/') . '/rsvp.php?'
         . http_build_query(['g' => (int) $guest['id'], 't' => fabo_rsvp_token((int) $guest['id'])] + $q);
}

/** Records an answer; null or '' clears it. Returns true when it changed. */
function fabo_meeting_rsvp_set(int $guestId, ?string $answer): bool
{
    $answer = ($answer === null || $answer === '') ? null : $answer;
    if ($answer !== null && !isset(fabo_meeting_responses()[$answer])) {
        return false;
    }
    $g = fabo_one('SELECT response FROM meeting_guests WHERE id = ?', 'i', [$guestId]);
    if (!$g || ($g['response'] ?? null) === $answer) {
        return false;
    }
    fabo_exec('UPDATE meeting_guests SET response = ?, responded_at = ' . ($answer === null ? 'NULL' : 'NOW()')
              . ' WHERE id = ?', 'si', [$answer, $guestId]);
    return true;
}

/** Tells the organiser somebody answered, on their notifications. */
function fabo_meeting_rsvp_tell(array $m, array $guest, string $answer): void
{
    require_once __DIR__ . '/notify.php';
    $who   = trim((string) ($guest['name'] ?? '')) ?: (string) $guest['email'];
    $label = strtolower(fabo_meeting_responses()[$answer][0] ?? $answer);
    $title = $who . ' — ' . ($answer === 'yes' ? 'going' : ($answer === 'no' ? "can't go" : 'maybe'));
    $body  = $m['title'] . ' · ' . fabo_meeting_when($m) . ' (' . $label . ')';
    $link  = 'meetings.php?id=' . (int) $m['id'];
    if ((int) $m['organiser_id'] > 0) {
        fabo_notify((int) $m['organiser_id'], $title, $body, $link);
    } else {
        fabo_notify_admin($title, $body, $link);
    }
}

/**
 * The tally for a guest list. The organiser is left out: they called it,
 * so asking whether they are coming is a question with one answer.
 */
function fabo_meeting_rsvp_counts(array $guests): array
{
    $out = ['yes' => 0, 'maybe' => 0, 'no' => 0, 'none' => 0, 'total' => 0];
    foreach ($guests as $g) {
        if (($g['role'] ?? '') === 'host') {
            continue;
        }
        $out['total']++;
        $r = (string) ($g['response'] ?? '');
        $out[isset(fabo_meeting_responses()[$r]) ? $r : 'none']++;
    }
    return $out;
}

/**
 * Reads each attendee's answer back from Google Calendar.
 *
 * Throttled to once every five minutes per meeting per session, so opening
 * the page repeatedly does not call Google every time; $force skips that
 * (the Refresh button). An attendee who has not answered in Google leaves
 * any answer they gave through the portal alone.
 *
 * Returns [ok, message, changedCount].
 */
function fabo_meeting_sync_responses(array $m, bool $force = false): array
{
    require_once __DIR__ . '/google.php';
    $id = (int) $m['id'];
    if (trim((string) ($m['calendar_event_id'] ?? '')) === '' || !fabo_google_connected()) {
        return [false, 'This meeting is not on Google Calendar.', 0];
    }
    fabo_session();
    $last = (int) ($_SESSION['fabo_rsvp_sync'][$id] ?? 0);
    if (!$force && time() - $last < 300) {
        return [true, '', 0];
    }
    $_SESSION['fabo_rsvp_sync'][$id] = time();

    [$ok, $res] = fabo_google_event_responses($m);
    if (!$ok) {
        return [false, $res, 0];
    }
    /*
     * Only an answer that changed in Google since the last look is applied.
     * Otherwise a guest who accepted in their calendar and later said
     * "maybe" on the portal would be flipped back to accepted on every sync.
     */
    $map     = ['accepted' => 'yes', 'declined' => 'no', 'tentative' => 'maybe'];
    $seen    = json_decode(fabo_setting('rsvp_gcal_' . $id, '{}'), true) ?: [];
    $changed = 0;
    foreach (fabo_meeting_guests($id) as $g) {
        $email  = strtolower((string) $g['email']);
        $status = $res[$email] ?? '';
        if (isset($map[$status]) && ($seen[$email] ?? '') !== $status
            && fabo_meeting_rsvp_set((int) $g['id'], $map[$status])) {
            $changed++;
        }
    }
    fabo_setting_put('rsvp_gcal_' . $id, json_encode($res));
    fabo_setting_put('rsvp_synced_' . $id, (string) time());
    return [true, $changed
        ? $changed . ' answer' . ($changed === 1 ? '' : 's') . ' updated from Google Calendar.'
        : 'Up to date with Google Calendar.', $changed];
}

/* ------------------------------------------------------------ calendar */

/** UTC in the compact form both iCalendar and Google's template link use. */
function fabo_meeting_utc(string $dbDateTime): string
{
    return gmdate('Ymd\THis\Z', strtotime($dbDateTime));
}

/** What goes in a calendar entry's description. */
function fabo_meeting_cal_details(array $m): string
{
    $f = fabo_meeting_cal_facts($m);
    $out = $f['intro'] . "\n";
    if ($f['with'] !== '') {
        $out .= "\nMeeting with: " . $f['with'];
    }
    $out .= "\nWhen: " . $f['when'];
    if ($f['link'] !== '') {
        $out .= "\nJoin: " . $f['link'];
    } elseif ($f['place'] !== '') {
        $out .= "\nWhere: " . $f['place'];
    }
    $out .= "\n\nAGENDA\n" . ($f['agenda'] ? '- ' . implode("\n- ", $f['agenda']) : 'No agenda shared yet.');
    $out .= "\n\nPlease reply Yes, No or Maybe to this invitation so " . $f['who'] . ' knows who is coming.'
          . "\n\nSent from " . $f['brand'];
    return $out;
}

/**
 * The same, as the little HTML Google Calendar allows in a description
 * (b, br, ul, li, a). This is the body of the invitation email Google
 * sends, under the Yes / No / Maybe card.
 */
function fabo_meeting_cal_html(array $m): string
{
    $f = fabo_meeting_cal_facts($m);
    $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $out = $h($f['intro']) . '<br><br>';
    if ($f['with'] !== '') {
        $out .= '<b>Meeting with:</b> ' . $h($f['with']) . '<br>';
    }
    $out .= '<b>When:</b> ' . $h($f['when']) . '<br>';
    if ($f['link'] !== '') {
        $out .= '<b>Join:</b> <a href="' . $h($f['link']) . '">' . $h($f['link']) . '</a><br>';
    } elseif ($f['place'] !== '') {
        $out .= '<b>Where:</b> ' . $h($f['place']) . '<br>';
    }
    $out .= '<br><b>Agenda</b>';
    $out .= $f['agenda']
        ? '<ul>' . implode('', array_map(static fn($l) => '<li>' . $h($l) . '</li>', $f['agenda'])) . '</ul>'
        : '<br>No agenda shared yet.<br>';
    $out .= '<br>Please reply <b>Yes</b>, <b>No</b> or <b>Maybe</b> above so ' . $h($f['who'])
          . ' knows who is coming.<br><br><i>Sent from ' . $h($f['brand']) . '</i>';
    return $out;
}

/** The facts an invitation's description is written from. */
function fabo_meeting_cal_facts(array $m): array
{
    require_once __DIR__ . '/notify.php';
    $who   = trim((string) ($m['organiser_name'] ?? '')) ?: 'The organiser';
    $org   = (int) ($m['organiser_id'] ?? 0) > 0
        ? fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [(int) $m['organiser_id']]) : null;
    $brand = fabo_sender_brand($org ?: null);
    $with  = '';
    if ((int) ($m['client_id'] ?? 0) > 0) {
        $c = fabo_one('SELECT name, organisation FROM clients WHERE id = ?', 'i', [(int) $m['client_id']]);
        $with = $c ? (trim((string) $c['organisation']) ?: (string) $c['name']) : '';
    }
    $agenda = [];
    foreach (preg_split('/\R+/', trim((string) ($m['agenda'] ?? ''))) as $line) {
        $line = trim((string) preg_replace('/^[\s\-\*\x{2022}]+/u', '', $line));   // drop typed bullets
        if ($line !== '') {
            $agenda[] = $line;
        }
    }
    $place = trim((string) ($m['location'] ?? ''));
    return [
        'who'    => $who,
        'brand'  => $brand,
        'intro'  => $who . ' has invited you to "' . $m['title'] . '"'
                  . ($brand !== '' ? ' on behalf of ' . $brand : '') . '.',
        'with'   => $with,
        'when'   => fabo_meeting_when($m),
        'link'   => trim((string) ($m['meet_url'] ?? '')),
        'place'  => in_array($place, ['Google Meet', 'Online'], true) ? '' : $place,
        'agenda' => $agenda,
    ];
}

/** Where, for a calendar entry: the link if there is one, else the place. */
function fabo_meeting_cal_where(array $m): string
{
    $link = trim((string) ($m['meet_url'] ?? ''));
    return $link !== '' ? $link : trim((string) ($m['location'] ?? ''));
}

/** "Add to Google Calendar" — a prefilled event the person saves themselves. */
function fabo_meeting_gcal_link(array $m): string
{
    return 'https://calendar.google.com/calendar/render?' . http_build_query([
        'action'   => 'TEMPLATE',
        'text'     => (string) $m['title'],
        'dates'    => fabo_meeting_utc((string) $m['starts_at']) . '/' . fabo_meeting_utc((string) $m['ends_at']),
        'details'  => fabo_meeting_cal_details($m),
        'location' => fabo_meeting_cal_where($m),
    ]);
}

/**
 * The mailbox calendar replies come back to: the portal's own sending
 * address. It is the ORGANIZER on every invitation, so when a guest presses
 * Yes in Gmail or Outlook their reply is mailed here, and lib/imapreplies.php
 * reads it back.
 */
function fabo_meeting_reply_mailbox(): string
{
    $box = defined('SMTP_FROM') && SMTP_FROM !== '' ? SMTP_FROM : (defined('SMTP_USER') ? SMTP_USER : '');
    return strtolower(trim((string) $box));
}

/**
 * The event's identity in every calendar. Fixed per meeting, so a newer
 * invitation (or .ics) updates the entry instead of adding a second, and a
 * reply can be traced back to the meeting it answers.
 */
function fabo_meeting_uid(array $m): string
{
    $box = fabo_meeting_reply_mailbox();
    $dom = str_contains($box, '@') ? substr($box, strpos($box, '@') + 1) : 'portal.local';
    return 'meeting-' . (int) $m['id'] . '@' . $dom;
}

/**
 * The meeting in iCalendar form.
 *
 *   PUBLISH  a plain calendar file (the Outlook / Apple download).
 *   REQUEST  an invitation: names the organiser and every guest, and asks
 *            each to reply. This is what makes a mail app show its own
 *            Yes / Maybe / No and send the answer back.
 *   CANCEL   takes the entry out of everyone's calendar.
 */
function fabo_meeting_ics(array $m, string $method = 'PUBLISH', array $guests = []): string
{
    $esc  = static fn(string $s): string => str_replace(["\\", "\r\n", "\n", ',', ';'],
                                                         ["\\\\", '\n', '\n', '\,', '\;'], $s);
    $name = static fn(string $s): string => '"' . str_replace(['"', "\r", "\n"], '', $s) . '"';
    $box  = fabo_meeting_reply_mailbox();

    $lines = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//Marketing Command Centre//Meetings//EN',
        'CALSCALE:GREGORIAN',
        'METHOD:' . $method,
        'BEGIN:VEVENT',
        'UID:' . fabo_meeting_uid($m),
        'DTSTAMP:' . gmdate('Ymd\THis\Z'),
        // Grows every time the meeting is changed, so calendars take the newer copy.
        'SEQUENCE:' . max(0, (int) strtotime((string) ($m['updated_at'] ?: $m['created_at']))
                             - (int) strtotime((string) $m['created_at'])),
        'DTSTART:' . fabo_meeting_utc((string) $m['starts_at']),
        'DTEND:' . fabo_meeting_utc((string) $m['ends_at']),
        'SUMMARY:' . $esc((string) $m['title']),
        'DESCRIPTION:' . $esc(fabo_meeting_cal_details($m)),
        'LOCATION:' . $esc(fabo_meeting_cal_where($m)),
        'STATUS:' . ($method === 'CANCEL' || $m['state'] === 'cancelled' ? 'CANCELLED' : 'CONFIRMED'),
    ];
    if ($method !== 'PUBLISH' && $box !== '') {
        $lines[] = 'ORGANIZER;CN=' . $name((string) $m['organiser_name']) . ':mailto:' . $box;
        foreach ($guests as $g) {
            if (($g['role'] ?? '') === 'host' || strtolower((string) $g['email']) === $box) {
                continue;
            }
            $part = ['yes' => 'ACCEPTED', 'no' => 'DECLINED', 'maybe' => 'TENTATIVE'][$g['response'] ?? ''] ?? 'NEEDS-ACTION';
            $lines[] = 'ATTENDEE;CUTYPE=INDIVIDUAL;ROLE=REQ-PARTICIPANT;PARTSTAT=' . $part . ';RSVP=TRUE'
                     . (trim((string) ($g['name'] ?? '')) !== '' ? ';CN=' . $name((string) $g['name']) : '')
                     . ':mailto:' . strtolower((string) $g['email']);
        }
    }
    if (trim((string) ($m['meet_url'] ?? '')) !== '') {
        $lines[] = 'URL:' . trim((string) $m['meet_url']);
    }
    if ($method !== 'CANCEL') {
        $lines[] = 'BEGIN:VALARM';
        $lines[] = 'TRIGGER:-PT10M';
        $lines[] = 'ACTION:DISPLAY';
        $lines[] = 'DESCRIPTION:' . $esc((string) $m['title']);
        $lines[] = 'END:VALARM';
    }
    $lines[] = 'END:VEVENT';
    $lines[] = 'END:VCALENDAR';

    // Lines longer than 75 octets are folded, as the format requires.
    $out = '';
    foreach ($lines as $l) {
        while (strlen($l) > 74) {
            $cut = 74;
            while ($cut > 0 && (ord($l[$cut]) & 0xC0) === 0x80) {
                $cut--;                       // never split a UTF-8 character
            }
            $out .= substr($l, 0, $cut) . "\r\n";
            $l = ' ' . substr($l, $cut);
        }
        $out .= $l . "\r\n";
    }
    return $out;
}

/** Sends the .ics as a download and stops. */
function fabo_meeting_ics_send(array $m): void
{
    $name = trim(preg_replace('~[^a-z0-9]+~i', '-', (string) $m['title']), '-') ?: 'meeting';
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '.ics"');
    header('Cache-Control: no-store');
    echo fabo_meeting_ics($m);
    exit;
}

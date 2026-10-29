<?php
/**
 * The meetings desk, rendered for whoever is looking.
 *
 * One body serves the administrator and the portal, the way the inbox does:
 * admin/meetings.php and meetings.php are thin wrappers that hand over a
 * viewer. Everything that differs between them — the links, who is on the
 * invite picker, which meetings are visible — is decided by the viewer.
 *
 * Two screens live here: the list, and one meeting.
 *
 * Creating a meeting is one step. The form asks what, when, where and who;
 * on submit the meeting is saved, the guests are added, the Google Meet link
 * is made (when the portal is connected to Google) and, if asked, the
 * invitations go out. Nobody has to come back and paste a link by hand.
 *
 * The single-meeting screen opens on a step tracker — scheduled, link,
 * invitations, held, minutes — and a "next step" panel carrying the one
 * button that moves it on, so it is always obvious what is left to do.
 *
 * Every POST redirects back (post/redirect/get) with its message kept in
 * the session, so a refresh never sends anything twice.
 */

require_once __DIR__ . '/ui.php';
require_once __DIR__ . '/meetings.php';
require_once __DIR__ . '/google.php';
require_once __DIR__ . '/notify.php';

function fabo_meeting_page(array $viewer): void
{
    $flash = fabo_meeting_flash_take();

    /* ----------------------------------------------------------- actions */

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        fabo_csrf_check();
        $res = fabo_meeting_handle($viewer);
        // Only a create that failed comes back here: the form is shown again
        // with what was typed, rather than losing it to a redirect.
        if ($res !== null) {
            $flash = $res;
        }
    }

    /* Is Google working, and is anything waiting for it? Both are cheap and
       throttled: a token check every ten minutes, a retry every five. */
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && fabo_google_connected()) {
        try {
            fabo_google_health();
            fabo_meeting_retry_pending();
        } catch (Throwable $e) {
            error_log('FABO meetings google check: ' . $e->getMessage());
        }
    }

    $id = max(0, (int) ($_GET['id'] ?? 0));
    if ($id > 0) {
        $m = fabo_meeting_for($viewer, $id);
        if (!$m) {
            fabo_deny('That meeting is not yours to open.', 404);
        }
        if (!empty($_GET['ics'])) {
            fabo_meeting_ics_send($m);           // "Add to Outlook / Apple"
        }
        fabo_meeting_one($viewer, $m, $flash);
        return;
    }
    fabo_meeting_list($viewer, $flash);
}

/** A link on this desk, for whichever side of the portal we are on. */
function fabo_meet_url(array $viewer, array $q = []): string
{
    return fabo_link($viewer, 'meetings.php', $q);
}

/* ------------------------------------------------------------ flashes */

/** Keeps a message for the page the browser is about to be sent to. */
function fabo_meeting_flash_keep(array $flash): void
{
    fabo_session();
    $_SESSION['fabo_meeting_flash'] = $flash;
}

function fabo_meeting_flash_take(): ?array
{
    fabo_session();
    $f = $_SESSION['fabo_meeting_flash'] ?? null;
    unset($_SESSION['fabo_meeting_flash']);
    return is_array($f) ? $f : null;
}

/** Saves the message and sends the browser on. Never returns. */
function fabo_meeting_redirect(string $url, array $flash): void
{
    fabo_meeting_flash_keep($flash);
    header('Location: ' . $url, true, 303);
    exit;
}

function fabo_meeting_flash(?array $flash): void
{
    if (!$flash) {
        return;
    }
    $tone = in_array($flash[0], ['ok', 'err', 'warn'], true) ? $flash[0] : 'err';
    $icon = ['ok' => '&#10003;', 'warn' => '!', 'err' => '&#10005;'][$tone];
    echo '<div class="mt-flash ' . $tone . '" role="status"><span class="ic">' . $icon
       . '</span><div>' . nl2br(e((string) $flash[1])) . '</div></div>';
}

/* --------------------------------------------------------------- write */

/**
 * Every POST the desk accepts. Redirects on everything except a failed
 * create, which returns the message to show above the form.
 */
function fabo_meeting_handle(array $viewer): ?array
{
    $action = (string) ($_POST['action'] ?? '');
    $id     = max(0, (int) ($_POST['id'] ?? 0));

    if ($action === 'create') {
        return fabo_meeting_handle_create($viewer);
    }

    // Everything past this point is about one meeting the viewer may change.
    $m = $id > 0 ? fabo_meeting_for($viewer, $id) : null;
    if (!$m) {
        fabo_meeting_redirect(fabo_meet_url($viewer), ['err', 'That meeting is not yours to change.']);
    }
    $back = fabo_meet_url($viewer, ['id' => $id]);

    // Answering for yourself is open to every guest, not just the organiser.
    if ($action === 'rsvp_self') {
        $me = fabo_meeting_my_guest($viewer, fabo_meeting_guests($id));
        $a  = (string) ($_POST['answer'] ?? '');
        if (!$me || !isset(fabo_meeting_responses()[$a])) {
            fabo_meeting_redirect($back, ['err', 'You are not on the guest list for this meeting.']);
        }
        if (fabo_meeting_rsvp_set((int) $me['id'], $a)) {
            fabo_meeting_rsvp_tell($m, $me, $a);
        }
        fabo_meeting_redirect($back, ['ok', $a === 'yes' ? "Thanks — you're going."
            : ($a === 'no' ? "Noted — you can't make it." : "Noted — you're a maybe.")]);
    }

    if (!fabo_meeting_may_manage($viewer, $m)) {
        fabo_meeting_redirect($back, ['err', 'Only the organiser can change this meeting.']);
    }
    $hasCal = trim((string) ($m['calendar_event_id'] ?? '')) !== '';

    switch ($action) {

        case 'update':
            [$ok, $msg] = fabo_meeting_update($id,
                (string) ($_POST['title'] ?? ''), (string) ($_POST['agenda'] ?? ''),
                fabo_meeting_form_time('starts'), fabo_meeting_form_time('ends'),
                (string) ($_POST['location'] ?? ''), (string) ($_POST['meet_url'] ?? ''));
            // A time that moved has to move on the calendar too, or the
            // invitation in everyone's diary quietly becomes wrong.
            if ($ok && $hasCal && fabo_google_connected()) {
                [$gok, $gmsg] = fabo_google_update_event(fabo_meeting($id), fabo_meeting_guests($id));
                $msg .= $gok ? ' Google Calendar updated too.' : "\nGoogle Calendar was not updated: " . $gmsg;
            }
            fabo_meeting_redirect($back, [$ok ? 'ok' : 'err', $msg]);

        case 'set_link':
            $url = trim((string) ($_POST['meet_url'] ?? ''));
            if ($url !== '' && !preg_match('~^https://~i', $url)) {
                fabo_meeting_redirect($back, ['err', 'A meeting link has to start with https://']);
            }
            fabo_exec('UPDATE meetings SET meet_url = ?, updated_at = NOW() WHERE id = ?',
                      'si', [$url ?: null, $id]);
            $msg = $url === '' ? 'Link removed.' : 'Link saved.';
            $pend = fabo_meeting_pending_get($id);
            if ($url !== '' && $pend !== null) {
                fabo_meeting_pending_put($id, null);
                if (!empty($pend['send'])) {
                    $msg .= ' ' . fabo_meeting_send_invites($viewer, fabo_meeting($id), false)[1];
                }
            }
            fabo_meeting_redirect($back, ['ok', $msg]);

        case 'guests':
            $added = fabo_meeting_take_guests($id, $viewer);
            if (!$added) {
                fabo_meeting_redirect($back, ['warn', 'Nobody new was added. Tick a name or type an email address.']);
            }
            $msg = $added . ' ' . ($added === 1 ? 'person' : 'people') . ' added.';
            $note = fabo_meeting_sync_calendar($id, $hasCal);
            if ($hasCal && str_contains($note, 'Google Calendar updated.')) {
                // Google has just sent them its calendar invitation; record that.
                fabo_announce_meeting(fabo_meeting($id), fabo_meeting_guests($id),
                                      !empty($viewer['is_admin']) ? null : $viewer['client'], false);
                $msg .= ' Google Calendar has sent them the invitation.';
            } else {
                $msg .= $note . ' Press "Send invitations" so they hear about it.';
            }
            fabo_meeting_redirect($back . '#guests', ['ok', $msg]);

        case 'guest_remove':
            [$ok, $msg] = fabo_meeting_guest_remove($id, (int) ($_POST['guest_id'] ?? 0));
            if ($ok) {
                $msg .= fabo_meeting_sync_calendar($id, $hasCal);
            }
            fabo_meeting_redirect($back . '#guests', [$ok ? 'ok' : 'err', $msg]);

        case 'rsvp_set':
            // The organiser recording an answer given by phone or in person.
            $gid = (int) ($_POST['guest_id'] ?? 0);
            $g   = fabo_one('SELECT * FROM meeting_guests WHERE id = ? AND meeting_id = ?', 'ii', [$gid, $id]);
            if (!$g) {
                fabo_meeting_redirect($back . '#guests', ['err', 'That guest is not on this meeting.']);
            }
            fabo_meeting_rsvp_set($gid, (string) ($_POST['answer'] ?? ''));
            fabo_meeting_redirect($back . '#guests', ['ok', 'Reply updated for '
                . (trim((string) $g['name']) ?: $g['email']) . '.']);

        case 'rsvp_sync':
            // Google Calendar answers when it is on Google; email replies otherwise.
            if ($hasCal) {
                [$ok, $msg] = fabo_meeting_sync_responses($m, true);
            } else {
                require_once __DIR__ . '/imapreplies.php';
                [$ok, $msg] = fabo_meeting_poll_replies(true);
            }
            fabo_meeting_redirect($back . '#guests', [$ok ? 'ok' : 'err', $msg]);

        case 'invite':
            fabo_meeting_redirect($back, fabo_meeting_send_invites($viewer, fabo_meeting($id), !empty($_POST['again'])));

        case 'gcal_create':
            [$ok, $msg] = fabo_meeting_attach_google($m);
            if ($ok) {
                // A link nobody hears about is no use: send it straight away.
                fabo_meeting_pending_put($id, null);
                $fresh   = fabo_meeting($id);
                $already = (bool) array_filter(fabo_meeting_guests($id),
                    static fn($g) => $g['role'] !== 'host' && !empty($g['invited_at']));
                $others  = (bool) array_filter(fabo_meeting_guests($id), static fn($g) => $g['role'] !== 'host');
                if ($others) {
                    $msg .= ' ' . fabo_meeting_send_invites($viewer, $fresh, $already)[1];
                }
            } elseif (!empty($viewer['is_admin'])) {
                $msg .= ' Reconnect Google on the Google page, then press this again.';
            }
            fabo_meeting_redirect($back, [$ok ? 'ok' : 'err', $msg]);

        case 'gcal_cancel':
            [$ok, $msg] = fabo_google_delete_event($m);
            if ($ok) {
                fabo_exec('UPDATE meetings SET calendar_event_id=NULL, conference_id=NULL,
                             updated_at=NOW() WHERE id=?', 'i', [$id]);
            }
            fabo_meeting_redirect($back, [$ok ? 'ok' : 'err', $ok ? $msg : 'Google said: ' . $msg]);

        case 'close':
            $rating = ($_POST['rating'] ?? '') === '' ? null : (int) $_POST['rating'];
            [$ok, $msg] = fabo_meeting_close($id, (string) ($_POST['state'] ?? ''),
                $rating, (string) ($_POST['rating_note'] ?? ''),
                (string) ($_POST['outcome'] ?? ''));
            if ($ok) {
                fabo_announce_meeting_closed(fabo_meeting($id), fabo_meeting_guests($id));
                // Cancelled: take it out of the calendars the invitation put it in.
                if (($_POST['state'] ?? '') === 'cancelled' && $m['state'] !== 'cancelled') {
                    if ($hasCal) {
                        // Google tells everyone and takes it out of their calendars.
                        if (fabo_google_connected() && fabo_google_delete_event($m)[0]) {
                            fabo_exec('UPDATE meetings SET calendar_event_id = NULL WHERE id = ?', 'i', [$id]);
                        }
                    } else {
                        fabo_announce_meeting_cancelled(fabo_meeting($id), fabo_meeting_guests($id),
                                                        !empty($viewer['is_admin']) ? null : $viewer['client']);
                    }
                }
            }
            fabo_meeting_redirect($back . ($ok ? '' : '#close'), [$ok ? 'ok' : 'err', $msg]);

        case 'minutes_save':
            [$ok, $msg] = fabo_meeting_set_minutes($id, (string) ($_POST['minutes'] ?? ''));
            fabo_meeting_redirect($back . '#minutes', [$ok ? 'ok' : 'err', $msg]);

        case 'transcript_save':
            [$ok, $msg] = fabo_meeting_set_transcript($id, (string) ($_POST['transcript'] ?? ''));
            fabo_meeting_redirect($back . '#minutes', [$ok ? 'ok' : 'err', $msg]);

        case 'transcript_fetch':
            [$ok, $res] = fabo_google_fetch_transcript($m);
            if ($ok) {
                fabo_meeting_set_transcript($id, $res);
                $res = 'Transcript fetched from Google Meet ('
                     . number_format(str_word_count($res)) . ' words).';
            }
            fabo_meeting_redirect($back . '#minutes', [$ok ? 'ok' : 'err', $res]);

        case 'gemini_minutes':
            [$ok, $res] = fabo_gemini_minutes($m, trim((string) ($m['transcript'] ?? '')));
            if ($ok) {
                fabo_meeting_set_minutes($id, $res, 'gemini');
                $res = 'Gemini drafted the minutes from the transcript. Read them before you share them.';
            }
            fabo_meeting_redirect($back . '#minutes', [$ok ? 'ok' : 'err', $res]);

        case 'delete':
            $was = fabo_meeting_guests($id);           // read before they are deleted with it
            [$ok, $msg] = fabo_meeting_delete($id);
            if ($ok && !$hasCal && $m['state'] === 'scheduled' && strtotime((string) $m['ends_at']) > time()) {
                fabo_announce_meeting_cancelled($m, $was, !empty($viewer['is_admin']) ? null : $viewer['client']);
            }
            if ($ok && $hasCal && fabo_google_connected()) {
                // Gone here, so gone from everyone's calendar as well.
                fabo_google_delete_event($m);
            }
            fabo_meeting_redirect($ok ? fabo_meet_url($viewer) : $back, [$ok ? 'ok' : 'err', $msg]);
    }
    fabo_meeting_redirect($back, ['err', 'Unknown action.']);
    return null;
}

/**
 * The create form, end to end: save, add guests, make the link, send.
 * Returns a flash only when nothing was created.
 */
function fabo_meeting_handle_create(array $viewer): ?array
{
    $mode = (string) ($_POST['where_mode'] ?? 'google');
    if (!in_array($mode, ['google', 'link', 'room'], true)) {
        $mode = 'google';
    }
    $url      = trim((string) ($_POST['meet_url'] ?? ''));
    $location = trim((string) ($_POST['location'] ?? ''));

    // Checked before anything is saved, so a bad link does not leave a
    // half-made meeting behind.
    if ($mode === 'link') {
        if ($url === '') {
            return ['err', 'Paste the meeting link, or choose Google Meet or In person.'];
        }
        if (!preg_match('~^https://~i', $url)) {
            return ['err', 'A meeting link has to start with https://'];
        }
    }
    if ($mode === 'room' && $location === '') {
        return ['err', 'Say where the meeting is, e.g. "Board room, 2nd floor".'];
    }

    $where = $mode === 'google' ? 'Google Meet' : ($mode === 'link' ? 'Online' : $location);

    [$newId, $msg] = fabo_meeting_create(
        $viewer,
        (string) ($_POST['title'] ?? ''),
        (string) ($_POST['agenda'] ?? ''),
        fabo_meeting_form_time('starts'),
        fabo_meeting_form_time('ends'),
        /* "Meeting with" is the administrator's choice alone -- the picker is
           only drawn for them. Taken from anyone else's post, it put a meeting
           (and its link) into another client's list and named that client. */
        !empty($viewer['is_admin']) ? (int) ($_POST['client_id'] ?? 0) : 0,
        $where
    );
    if ($newId <= 0) {
        return ['err', $msg];
    }

    fabo_meeting_take_guests($newId, $viewer);
    if ($mode === 'link') {
        fabo_exec('UPDATE meetings SET meet_url = ? WHERE id = ?', 'si', [$url, $newId]);
    }

    $tone  = 'ok';
    $lines = ['Meeting created.'];

    $held = false;
    if ($mode === 'google') {
        [$gok, $gmsg] = fabo_google_connected()
            ? fabo_meeting_attach_google(fabo_meeting($newId))
            : [false, 'The portal is not connected to Google.'];
        if ($gok) {
            $lines[] = $gmsg;
        } else {
            /*
             * No link yet, so the invitations wait for it rather than going
             * out without one. The meeting is queued: the moment Google works
             * again (reconnect, cron, the next page load) the link is made
             * and the invitations are sent with it, without anyone coming back.
             */
            $held  = true;
            $tone  = 'warn';
            fabo_meeting_pending_put($newId, !empty($_POST['send_now']), $viewer);
            $lines[] = 'The Google Meet link could not be created yet: the Google connection needs to be '
                     . 'reconnected. ' . (!empty($_POST['send_now']) ? 'The invitations are on hold. ' : '')
                     . 'As soon as Google is reconnected, the link is created'
                     . (!empty($_POST['send_now']) ? ' and the invitations are emailed with it' : '')
                     . ' automatically.'
                     . (!empty($viewer['is_admin'])
                        ? ' Reconnect it on the Google page now.'
                        : ' The administrator has been told. Or use your own link below to send them now.');
        }
    }

    if ($held) {
        // Said above.
    } elseif (!empty($_POST['send_now'])) {
        [$t, $text] = fabo_meeting_send_invites($viewer, fabo_meeting($newId), false);
        $lines[] = $text;
        if ($t !== 'ok' && $tone === 'ok') {
            $tone = 'warn';
        }
    } else {
        $lines[] = 'Invitations have not been sent yet.';
    }

    fabo_meeting_redirect(fabo_meet_url($viewer, ['id' => $newId]), [$tone, implode("\n", $lines)]);
    return null;
}

/**
 * The start or end time off the form. The form sends a date and two clock
 * times; an older form (or a script) may still send starts_at / ends_at,
 * which are accepted as they were.
 */
function fabo_meeting_form_time(string $which): string
{
    $date  = trim((string) ($_POST['date'] ?? ''));
    $clock = trim((string) ($_POST[$which === 'starts' ? 'start_time' : 'end_time'] ?? ''));
    if ($date !== '' && $clock !== '') {
        return $date . ' ' . $clock;
    }
    return (string) ($_POST[$which . '_at'] ?? '');
}

/**
 * Creates the Google Calendar event and its Meet link, and stores both.
 * Returns [ok, message] in words a person can act on.
 */
function fabo_meeting_attach_google(array $m): array
{
    if (!fabo_google_connected()) {
        return [false, 'The portal is not connected to Google yet.'];
    }
    if (trim((string) ($m['calendar_event_id'] ?? '')) !== '') {
        return [true, 'This meeting is already on Google Calendar.'];
    }
    [$ok, $res] = fabo_google_create_event($m, fabo_meeting_guests((int) $m['id']));
    if (!$ok) {
        return [false, 'Google could not create the Meet link: ' . $res];
    }
    $meet = trim((string) $res['meet_url']);
    // Keep a link someone already typed in if Google did not hand one back.
    fabo_exec('UPDATE meetings SET calendar_event_id = ?,
                 meet_url = COALESCE(NULLIF(?, ""), meet_url),
                 conference_id = NULLIF(?, ""),
                 location = COALESCE(NULLIF(location, ""), "Google Meet"),
                 updated_at = NOW() WHERE id = ?', 'sssi',
              [$res['event_id'], $meet, fabo_meet_code($meet, $res['conference_id']), (int) $m['id']]);
    if ($meet === '') {
        return [false, 'The event was added to Google Calendar, but Google did not return a Meet '
                     . 'link. The connected Google account may not be allowed to create Meet calls. '
                     . 'Add your own link below instead.'];
    }
    return [true, 'Google Meet link created, and the meeting is on Google Calendar for everyone invited.'];
}

/* ------------------------------------------------- waiting for Google */

/**
 * A meeting waiting for its Meet link: whether to send the invitations
 * once it has one, and on whose behalf. Kept in app_settings so no schema
 * change is needed; null removes it.
 */
function fabo_meeting_pending_put(int $id, ?bool $send, array $viewer = []): void
{
    fabo_setting_put('meet_pending_' . $id, $send === null ? null : json_encode([
        'send'  => $send ? 1 : 0,
        'actor' => !empty($viewer['is_admin']) ? 0 : (int) ($viewer['client']['id'] ?? 0),
    ]));
}

function fabo_meeting_pending_get(int $id): ?array
{
    $v = fabo_setting('meet_pending_' . $id);
    return $v === '' ? null : (json_decode($v, true) ?: ['send' => 0, 'actor' => 0]);
}

/**
 * Gives every waiting meeting its Meet link and sends the held invitations.
 * Called by cron, after reconnecting on the Google page, and on page loads
 * (at most every five minutes unless forced). Returns how many were done.
 */
function fabo_meeting_retry_pending(bool $force = false): int
{
    if (!$force && time() - (int) fabo_setting('meetlink_retry_at', '0') < 300) {
        return 0;
    }
    fabo_setting_put('meetlink_retry_at', (string) time());
    $rows = fabo_all("SELECT k, v FROM app_settings WHERE k LIKE 'meet\\_pending\\_%'");
    if (!$rows || !fabo_google_connected() || !fabo_google_health(true)[0]) {
        return 0;
    }
    $done = 0;
    foreach ($rows as $r) {
        $id   = (int) substr((string) $r['k'], strlen('meet_pending_'));
        $pend = json_decode((string) $r['v'], true) ?: ['send' => 0, 'actor' => 0];
        $m    = fabo_meeting($id);
        // Gone, over, cancelled or already linked: nothing left to wait for.
        if (!$m || $m['state'] !== 'scheduled' || strtotime((string) $m['ends_at']) < time()
            || trim((string) $m['meet_url']) !== '') {
            fabo_meeting_pending_put($id, null);
            continue;
        }
        [$ok] = fabo_meeting_attach_google($m);
        if (!$ok) {
            break;                       // Google is refusing; try again next time
        }
        fabo_meeting_pending_put($id, null);
        if (!empty($pend['send'])) {
            $actor  = !empty($pend['actor'])
                ? fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [(int) $pend['actor']]) : null;
            $guests = fabo_meeting_guests($id);
            $again  = (bool) array_filter($guests, static fn($g) => $g['role'] !== 'host' && !empty($g['invited_at']));
            fabo_announce_meeting(fabo_meeting($id), $guests, $actor, $again);
        }
        // Tell whoever called it that it went out.
        $title = 'Meet link ready: ' . $m['title'];
        $body  = !empty($pend['send']) ? 'The invitations have been emailed with the link.' : 'The link is on the meeting.';
        if ((int) $m['organiser_id'] > 0) {
            fabo_notify((int) $m['organiser_id'], $title, $body, 'meetings.php?id=' . $id);
        } else {
            fabo_notify_admin($title, $body, 'meetings.php?id=' . $id);
        }
        $done++;
    }
    return $done;
}

/** The banner shown on the desk while the Google connection is down. */
function fabo_meeting_google_banner(array $viewer): void
{
    if (!fabo_google_connected() || fabo_google_ready()) {
        return;
    }
    echo '<div class="mt-flash warn"><span class="ic">!</span><div><strong>Google Meet links are paused '
       . '&mdash; the Google connection has expired.</strong> '
       . (!empty($viewer['is_admin'])
          ? 'Meetings created now are saved and wait for their link. <a href="google.php">Reconnect Google &rarr;</a> '
            . 'and every waiting meeting gets its Meet link and its invitations automatically.'
          : 'Meetings created now are saved, and get their Meet link and invitations automatically as soon as '
            . 'the administrator reconnects it (they have been told). Need it sooner? Use your own link.')
       . '</div></div>';
}

/** Pushes the guest list to Calendar when there is an event. Returns a note. */
function fabo_meeting_sync_calendar(int $id, bool $hasCal): string
{
    if (!$hasCal || !fabo_google_connected()) {
        return '';
    }
    [$ok, $msg] = fabo_google_update_event(fabo_meeting($id), fabo_meeting_guests($id));
    return $ok ? ' Google Calendar updated.' : ' (Google Calendar not updated: ' . $msg . ')';
}

/** Emails everyone on the list. Returns a flash. */
function fabo_meeting_send_invites(array $viewer, array $m, bool $again): array
{
    $guests = fabo_meeting_guests((int) $m['id']);
    if (!$guests) {
        return ['warn', 'Nobody is on the guest list yet, so no invitations were sent.'];
    }
    $actor = !empty($viewer['is_admin']) ? null : $viewer['client'];
    [$sent, $bad] = fabo_announce_meeting($m, $guests, $actor, $again);
    if (!$sent && !$bad) {
        return ['warn', 'No invitations were sent.'];
    }
    $text = $sent . ' invitation' . ($sent === 1 ? '' : 's') . ' emailed';
    if ($bad) {
        $text .= ', ' . $bad . ' could not be delivered (see the guest list for why)';
    }
    return [$bad ? ($sent ? 'warn' : 'err') : 'ok', $text . '.'];
}

/** Adds whoever was ticked, plus anything typed in the box. Returns a count. */
function fabo_meeting_take_guests(int $meetingId, array $viewer): int
{
    $added = 0;
    $known = [];
    foreach (fabo_meeting_invitables($viewer) as $p) {
        $known[(int) $p['id']] = $p;
    }
    foreach ((array) ($_POST['invite'] ?? []) as $pid) {
        $p = $known[(int) $pid] ?? null;
        if (!$p) {
            continue;                     // not someone this viewer may invite
        }
        [$gid, $already] = fabo_meeting_guest_add($meetingId, (string) $p['email'],
                                                  (string) $p['name'], (int) $p['id']);
        if ($gid && !$already) { $added++; }
    }
    $blob = trim((string) ($_POST['extra_emails'] ?? ''));
    if ($blob !== '') {
        [$n] = fabo_meeting_guests_add_many($meetingId, $blob);
        $added += $n;
    }
    return $added;
}

/* ------------------------------------------------------------ helpers */

/**
 * The viewer's own row on the guest list, or null. The organiser is not
 * returned: they are not asked whether they are coming.
 */
function fabo_meeting_my_guest(array $viewer, array $guests): ?array
{
    $myId  = !empty($viewer['is_admin']) ? 0 : (int) ($viewer['client']['id'] ?? 0);
    $email = strtolower(trim(!empty($viewer['is_admin'])
        ? (string) (defined('ADMIN_INBOX_EMAIL') ? ADMIN_INBOX_EMAIL : '')
        : (string) ($viewer['client']['email'] ?? '')));
    foreach ($guests as $g) {
        if ($g['role'] === 'host') {
            continue;
        }
        if (($myId > 0 && (int) ($g['client_id'] ?? 0) === $myId)
            || ($email !== '' && strtolower((string) $g['email']) === $email)) {
            return $g;
        }
    }
    return null;
}

/** "just now", "5 min ago", "2 hr ago". */
function fabo_meeting_ago(int $ts): string
{
    $d = max(0, time() - $ts);
    return $d < 60 ? 'just now' : ($d < 3600 ? floor($d / 60) . ' min ago'
         : ($d < 86400 ? floor($d / 3600) . ' hr ago' : date('j M', $ts)));
}

/** Two letters for an avatar. */
function fabo_meeting_initials(string $name, string $email = ''): string
{
    $name = trim($name) !== '' ? $name : strtok($email, '@');
    $bits = preg_split('/[\s._-]+/', trim((string) $name)) ?: [];
    $out  = '';
    foreach ($bits as $b) {
        if ($b !== '') {
            $out .= mb_strtoupper(mb_substr($b, 0, 1));
        }
        if (mb_strlen($out) >= 2) {
            break;
        }
    }
    return $out !== '' ? $out : '?';
}

/** "In 3 days", "Tomorrow", "Today", "Ended 2 days ago". */
function fabo_meeting_relative(array $m): string
{
    $s   = strtotime((string) $m['starts_at']);
    $e   = strtotime((string) $m['ends_at']);
    $now = time();
    if ($now >= $s && $now <= $e) {
        return 'Happening now';
    }
    $days = (int) round((strtotime(date('Y-m-d', $s)) - strtotime(date('Y-m-d', $now))) / 86400);
    if ($e < $now) {
        return $days === 0 ? 'Earlier today' : ($days === -1 ? 'Yesterday' : abs($days) . ' days ago');
    }
    if ($days === 0) {
        $mins = (int) round(($s - $now) / 60);
        return $mins < 60 ? 'In ' . max(1, $mins) . ' min' : 'Today';
    }
    return $days === 1 ? 'Tomorrow' : 'In ' . $days . ' days';
}

/** The status for display: [label, tone class]. */
function fabo_meeting_badge(array $m): array
{
    if (fabo_meeting_overdue($m)) {
        return ['Needs closing', 'amber'];
    }
    $s = (string) $m['state'];
    if ($s === 'scheduled') {
        $now = time();
        if ($now >= strtotime((string) $m['starts_at']) && $now <= strtotime((string) $m['ends_at'])) {
            return ['Live now', 'live'];
        }
        return ['Scheduled', 'blue'];
    }
    return [fabo_meeting_state_label($s), ['held' => 'green', 'cancelled' => 'red', 'no_show' => 'grey'][$s] ?? 'grey'];
}

/** The little calendar tile: month over day. */
function fabo_meeting_datetile(array $m): string
{
    $s = strtotime((string) $m['starts_at']);
    return '<div class="mt-date"><span class="mo">' . e(date('M', $s)) . '</span>'
         . '<span class="dy">' . e(date('j', $s)) . '</span>'
         . '<span class="wd">' . e(date('D', $s)) . '</span></div>';
}

/* ---------------------------------------------------------- the list */

function fabo_meeting_list(array $viewer, ?array $flash): void
{
    $isAdmin = !empty($viewer['is_admin']);
    $when    = in_array($_GET['when'] ?? '', ['upcoming', 'past'], true)
             ? (string) $_GET['when'] : 'upcoming';
    $client  = max(0, (int) ($_GET['client'] ?? 0));

    $rows    = fabo_meetings($viewer, $when, $client);
    $metrics = fabo_meeting_metrics($viewer, $client);
    $clients = $isAdmin
        ? fabo_all('SELECT id, name, organisation FROM clients
                    WHERE kind = "client" AND parent_id IS NULL
                    ORDER BY organisation, name')
        : [];
    $gOn = fabo_google_connected();

    // Guest counts in one query rather than one per row.
    $counts = [];
    $going  = [];
    if ($rows) {
        $ids = array_map('intval', array_column($rows, 'id'));
        foreach (fabo_all('SELECT meeting_id, COUNT(*) n,
                                  SUM(role <> "host" AND response = "yes") y,
                                  SUM(role <> "host") g
                           FROM meeting_guests WHERE meeting_id IN ('
                          . implode(',', $ids) . ') GROUP BY meeting_id') as $c) {
            $counts[(int) $c['meeting_id']] = (int) $c['n'];
            $going[(int) $c['meeting_id']]  = [(int) $c['y'], (int) $c['g']];
        }
    }

    // The form opens by itself when there is nothing yet, when asked, or
    // when a create just failed and needs correcting.
    $openForm = !empty($_GET['create'])
             || ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create')
             || ((int) $metrics['total'] === 0);

    fabo_head($viewer, 'Meetings', 'meetings');
    ?>
<div class="wrap-page mt">

<div class="mt-head">
  <div>
    <h1>Meetings</h1>
    <p class="sub">Plan a meeting, invite people, join it, and keep the notes &mdash; all in one place.</p>
  </div>
  <button type="button" class="btn btn-primary mt-big" id="mtNewBtn"
          aria-expanded="<?= $openForm ? 'true' : 'false' ?>" aria-controls="mtNew">
    <span aria-hidden="true">&#65291;</span> New meeting</button>
</div>

<?php fabo_meeting_flash($flash); ?>
<?php fabo_meeting_google_banner($viewer); ?>

<?php if (!$gOn): ?>
  <div class="mt-note">
    <span class="ic">i</span>
    <div><strong>Google Meet links are not automatic yet.</strong>
      <?= $isAdmin
          ? 'Connect the portal to Google once and every new meeting gets its Meet link by itself. '
            . '<a href="google.php">Connect Google &rarr;</a>'
          : 'You can still add your own Zoom, Teams or Meet link when you create a meeting.' ?></div>
  </div>
<?php endif; ?>

<section class="mt-panel" id="mtNew"<?= $openForm ? '' : ' hidden' ?>>
  <?php fabo_meeting_create_form($viewer, $gOn); ?>
</section>

<div class="mt-stats">
  <a class="mt-stat" href="<?= e(fabo_meet_url($viewer, array_filter(['when' => 'upcoming', 'client' => $client]))) ?>">
    <span class="v"><?= (int) $metrics['upcoming'] ?></span><span class="k">Upcoming</span></a>
  <a class="mt-stat<?= $metrics['to_close'] ? ' amber' : '' ?>" href="<?= e(fabo_meet_url($viewer, array_filter(['when' => 'past', 'client' => $client]))) ?>">
    <span class="v"><?= (int) $metrics['to_close'] ?></span><span class="k">Need closing</span></a>
  <div class="mt-stat green"><span class="v"><?= (int) $metrics['held'] ?></span><span class="k">Held</span></div>
  <div class="mt-stat"><span class="v"><?= $metrics['minuted_pct'] === null ? '&ndash;'
        : e(fabo_pct($metrics['minuted_pct'], 0)) ?></span><span class="k">With minutes</span></div>
  <div class="mt-stat"><span class="v"><?= $metrics['avg_rating'] === null ? '&ndash;'
        : e((string) $metrics['avg_rating']) . '<small>/5</small>' ?></span><span class="k">Avg rating</span></div>
</div>

<div class="mt-bar">
  <nav class="mt-tabs" aria-label="Which meetings">
    <a class="<?= $when === 'upcoming' ? 'on' : '' ?>"
       href="<?= e(fabo_meet_url($viewer, array_filter(['when' => 'upcoming', 'client' => $client]))) ?>">Upcoming</a>
    <a class="<?= $when === 'past' ? 'on' : '' ?>"
       href="<?= e(fabo_meet_url($viewer, array_filter(['when' => 'past', 'client' => $client]))) ?>">Past
       <?php if ($metrics['to_close']): ?><span class="dot" title="Some need closing"></span><?php endif; ?></a>
  </nav>
  <?php if ($isAdmin && $clients): ?>
  <form method="get" class="mt-filter">
    <input type="hidden" name="when" value="<?= e($when) ?>">
    <label for="mtClient">Client</label>
    <select id="mtClient" name="client" onchange="this.form.submit()">
      <option value="0">All clients</option>
      <?php foreach ($clients as $c): ?>
        <option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === $client ? 'selected' : '' ?>>
          <?= e($c['organisation'] ?: $c['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <noscript><button class="btn btn-sm">Apply</button></noscript>
  </form>
  <?php endif; ?>
</div>

<?php if (!$rows): ?>
  <div class="mt-empty">
    <div class="art" aria-hidden="true">&#128197;</div>
    <h3><?= $when === 'upcoming' ? 'No upcoming meetings' : 'No past meetings yet' ?></h3>
    <p><?= $when === 'upcoming'
        ? 'Create one and it will appear here, with its join link ready.'
        : 'Meetings move here once they are over.' ?></p>
    <?php if ($when === 'upcoming'): ?>
      <button type="button" class="btn btn-primary" data-open-new>Create a meeting</button>
    <?php endif; ?>
  </div>
<?php else: ?>
  <ul class="mt-list">
  <?php foreach ($rows as $r):
      [$lab, $tone] = fabo_meeting_badge($r);
      $open  = fabo_meet_url($viewer, ['id' => (int) $r['id']]);
      $link  = trim((string) ($r['meet_url'] ?? ''));
      $live  = $r['state'] === 'scheduled' && strtotime((string) $r['ends_at']) >= time();
      $n     = $counts[(int) $r['id']] ?? 0; ?>
    <li class="mt-row<?= $r['state'] === 'cancelled' ? ' void' : '' ?>">
      <?= fabo_meeting_datetile($r) ?>
      <div class="main">
        <a class="title stretch" href="<?= e($open) ?>"><?= e($r['title']) ?></a>
        <div class="meta">
          <span><?= e(date('H:i', strtotime((string) $r['starts_at']))) ?>&ndash;<?= e(date('H:i', strtotime((string) $r['ends_at']))) ?></span>
          <span><?= e($r['corg'] ?: ($r['cname'] ?: 'Internal')) ?></span>
          <span><?= $n ?> <?= $n === 1 ? 'person' : 'people' ?></span>
          <?php [$gy, $gt] = $going[(int) $r['id']] ?? [0, 0]; if ($gt > 0): ?>
            <span class="<?= $gy ? 'ok' : '' ?>">&#10003; <?= $gy ?>/<?= $gt ?> going</span>
          <?php endif; ?>
          <?php if ($link !== ''): ?><span class="ok">&#9679; Link ready</span>
          <?php elseif ($live && trim((string) $r['location']) === ''): ?><span class="warn">No link yet</span>
          <?php elseif (trim((string) $r['location']) !== '' && $link === ''): ?><span><?= e((string) $r['location']) ?></span><?php endif; ?>
          <?php if (!empty($r['minutes'])): ?><span class="ok">Minutes</span><?php endif; ?>
          <?php if ($r['rating']): ?><span>&#9733; <?= (int) $r['rating'] ?>/5</span><?php endif; ?>
        </div>
      </div>
      <div class="side">
        <span class="mt-badge <?= e($tone) ?>"><?= e($lab) ?></span>
        <span class="when"><?= e(fabo_meeting_relative($r)) ?></span>
      </div>
      <div class="acts">
        <?php if ($live && $link !== ''): ?>
          <a class="btn btn-primary btn-sm" href="<?= e($link) ?>" target="_blank" rel="noopener">Join</a>
        <?php endif; ?>
        <a class="btn btn-sm" href="<?= e($open) ?>">Open</a>
      </div>
    </li>
  <?php endforeach; ?>
  </ul>
<?php endif; ?>

</div>

<script>
(function () {
  var btn = document.getElementById('mtNewBtn'), panel = document.getElementById('mtNew');
  if (!btn || !panel) { return; }
  function show(open) {
    panel.hidden = !open;
    panel.classList.toggle('anim', open);
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) {
      panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
      var t = panel.querySelector('input[name="title"]');
      if (t) { setTimeout(function () { t.focus(); }, 250); }
    }
  }
  btn.addEventListener('click', function () { show(panel.hidden); });
  document.querySelectorAll('[data-open-new]').forEach(function (b) {
    b.addEventListener('click', function () { show(true); });
  });
  document.querySelectorAll('[data-close-new]').forEach(function (b) {
    b.addEventListener('click', function () { show(false); });
  });
})();
</script>
<?php
    fabo_foot();
}

/** The create form. Re-fills itself from the POST when a create failed. */
function fabo_meeting_create_form(array $viewer, bool $gOn): void
{
    $isAdmin = !empty($viewer['is_admin']);
    $people  = fabo_meeting_invitables($viewer);
    $clients = $isAdmin
        /*
         * Top-level clients only. A meeting is with an organisation, and a
         * sub-account a client created is part of theirs, not one of its
         * own — offering it here would list the same organisation twice.
         */
        ? fabo_all('SELECT id, name, organisation FROM clients
                    WHERE kind = "client" AND parent_id IS NULL AND revoked_at IS NULL
                    ORDER BY organisation, name')
        : [];

    $old    = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') ? $_POST : [];
    $v      = static fn(string $k, string $d = '') => (string) ($old[$k] ?? $d);
    $mode   = $v('where_mode', $gOn ? 'google' : 'link');
    $picked = array_flip(array_map('intval', (array) ($old['invite'] ?? [])));
    $client = (int) $v('client_id', '0');
    $send   = $old ? !empty($old['send_now']) : true;
    ?>
  <form method="post" class="mt-form" id="mtCreate" novalidate>
    <?= fabo_csrf_field() ?>
    <input type="hidden" name="action" value="create">

    <div class="mt-form-head">
      <h2>New meeting</h2>
      <button type="button" class="mt-x" data-close-new aria-label="Close">&times;</button>
    </div>

    <!-- 1. What and when -->
    <fieldset class="mt-step">
      <legend><span class="n">1</span> What &amp; when</legend>
      <div class="field">
        <label for="mtTitle">Meeting title</label>
        <input id="mtTitle" name="title" value="<?= e($v('title')) ?>" maxlength="190"
               placeholder="e.g. Monthly campaign review" required autocomplete="off">
      </div>
      <div class="mt-when">
        <div class="field">
          <label for="mtDate">Date</label>
          <input id="mtDate" type="date" name="date" value="<?= e($v('date', date('Y-m-d', strtotime('tomorrow')))) ?>" required>
        </div>
        <div class="field">
          <label for="mtStart">Starts</label>
          <input id="mtStart" type="time" name="start_time" step="300" value="<?= e($v('start_time', '10:00')) ?>" required>
        </div>
        <div class="field">
          <label for="mtEnd">Ends</label>
          <input id="mtEnd" type="time" name="end_time" step="300" value="<?= e($v('end_time', '11:00')) ?>" required>
        </div>
      </div>
      <div class="mt-chips" role="group" aria-label="Quick duration">
        <span class="lbl">Length:</span>
        <?php foreach ([15 => '15 min', 30 => '30 min', 45 => '45 min', 60 => '1 hr', 90 => '1&frac12; hr', 120 => '2 hr'] as $mins => $lab): ?>
          <button type="button" class="chip" data-mins="<?= $mins ?>"><?= $lab ?></button>
        <?php endforeach; ?>
      </div>
    </fieldset>

    <!-- 2. Where -->
    <fieldset class="mt-step">
      <legend><span class="n">2</span> Where</legend>
      <div class="mt-choices">
        <label class="mt-choice<?= $gOn ? '' : ' off' ?>">
          <input type="radio" name="where_mode" value="google" <?= $mode === 'google' ? 'checked' : '' ?> <?= $gOn ? '' : 'disabled' ?>>
          <span class="box">
            <span class="t"><svg class="ico" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="currentColor" d="M4 6.5A2.5 2.5 0 0 1 6.5 4h7A2.5 2.5 0 0 1 16 6.5v1.6l3.4-2.3A1 1 0 0 1 21 6.6v10.8a1 1 0 0 1-1.6.8L16 15.9v1.6a2.5 2.5 0 0 1-2.5 2.5h-7A2.5 2.5 0 0 1 4 17.5z"/></svg>Google Meet</span>
            <span class="d"><?= !$gOn ? 'Not connected yet' : (fabo_google_ready()
                ? 'Link is created and emailed automatically'
                : 'Paused &mdash; link and invitations follow automatically once Google is reconnected') ?></span>
          </span>
        </label>
        <label class="mt-choice">
          <input type="radio" name="where_mode" value="link" <?= $mode === 'link' ? 'checked' : '' ?>>
          <span class="box">
            <span class="t"><span class="ico link" aria-hidden="true">&#128279;</span>My own link</span>
            <span class="d">Zoom, Teams, or a Meet link you made</span>
          </span>
        </label>
        <label class="mt-choice">
          <input type="radio" name="where_mode" value="room" <?= $mode === 'room' ? 'checked' : '' ?>>
          <span class="box">
            <span class="t"><span class="ico room" aria-hidden="true">&#128205;</span>In person</span>
            <span class="d">A room or an address</span>
          </span>
        </label>
      </div>
      <div class="field" data-mode="link"<?= $mode === 'link' ? '' : ' hidden' ?>>
        <label for="mtUrl">Meeting link</label>
        <input id="mtUrl" name="meet_url" type="url" value="<?= e($v('meet_url')) ?>" placeholder="https://zoom.us/j/… or https://meet.google.com/…">
      </div>
      <div class="field" data-mode="room"<?= $mode === 'room' ? '' : ' hidden' ?>>
        <label for="mtLoc">Place</label>
        <input id="mtLoc" name="location" value="<?= e($v('location')) ?>" placeholder="e.g. Board room, 2nd floor">
      </div>
      <?php if (!$gOn && $isAdmin): ?>
        <p class="mt-hint">Want Meet links made for you? <a href="google.php">Connect Google</a> once &mdash; it takes a minute.</p>
      <?php endif; ?>
    </fieldset>

    <!-- 3. Who -->
    <fieldset class="mt-step">
      <legend><span class="n">3</span> Who&rsquo;s invited</legend>
      <?php if ($isAdmin): ?>
      <div class="field">
        <label for="mtClientSel">Meeting with</label>
        <select id="mtClientSel" name="client_id">
          <option value="0">Our own team (internal)</option>
          <?php foreach ($clients as $c): ?>
            <option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === $client ? 'selected' : '' ?>><?= e($c['organisation'] ?: $c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>

      <?php if ($people): ?>
      <div class="field">
        <div class="mt-pickhead">
          <label for="mtSearch">People on the portal</label>
          <span class="mt-count" id="mtCount">0 selected</span>
        </div>
        <input id="mtSearch" type="search" class="mt-search" placeholder="Search by name&hellip;" autocomplete="off">
        <div class="mt-people" id="pickbox">
          <?php foreach (fabo_meeting_people_groups($people) as $org => $grp): ?>
            <div class="grp" data-org="<?= (int) $org ?>">
              <div class="ghead"><?= e($grp['label']) ?></div>
              <?php foreach ($grp['people'] as $p): ?>
                <label class="person" data-name="<?= e(mb_strtolower($p['name'] . ' ' . $p['email'])) ?>">
                  <input type="checkbox" name="invite[]" value="<?= (int) $p['id'] ?>" <?= isset($picked[(int) $p['id']]) ? 'checked' : '' ?>>
                  <span class="av"><?= e(fabo_meeting_initials((string) $p['name'], (string) $p['email'])) ?></span>
                  <span class="nm"><?= e($p['name']) ?><small><?= e($p['email']) ?></small></span>
                  <span class="tick" aria-hidden="true">&#10003;</span>
                </label>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
          <p class="mt-none" id="picknone" hidden>Nobody matches.</p>
        </div>
        <p class="mt-hint" id="pickhint"></p>
      </div>
      <?php endif; ?>

      <div class="field">
        <label for="mtExtra">Anyone else? Add email addresses</label>
        <textarea id="mtExtra" name="extra_emails" rows="2"
          placeholder="someone@example.com, another@example.com"><?= e($v('extra_emails')) ?></textarea>
        <p class="mt-hint">Separate with commas or new lines. They don&rsquo;t need a portal account.</p>
      </div>
    </fieldset>

    <!-- 4. Agenda -->
    <fieldset class="mt-step">
      <legend><span class="n">4</span> Agenda <span class="opt">optional</span></legend>
      <div class="field">
        <textarea name="agenda" rows="3" aria-label="Agenda"
          placeholder="What you'll cover, one point per line"><?= e($v('agenda')) ?></textarea>
      </div>
    </fieldset>

    <div class="mt-submit">
      <label class="mt-switch">
        <input type="checkbox" name="send_now" value="1" <?= $send ? 'checked' : '' ?>>
        <span class="trk" aria-hidden="true"></span>
        <span>Email the invitations now</span>
      </label>
      <div class="sp"></div>
      <button type="button" class="btn" data-close-new>Cancel</button>
      <button type="submit" class="btn btn-primary mt-big" id="mtGo">Create meeting</button>
    </div>
  </form>

  <script>
  (function () {
    var f = document.getElementById('mtCreate');
    if (!f) { return; }

    /* Where: show the one extra box the choice needs. */
    function where() {
      var m = (f.querySelector('input[name="where_mode"]:checked') || {}).value;
      f.querySelectorAll('[data-mode]').forEach(function (el) {
        el.hidden = el.getAttribute('data-mode') !== m;
      });
    }
    f.querySelectorAll('input[name="where_mode"]').forEach(function (r) { r.addEventListener('change', where); });
    where();

    /* Length chips set the end time from the start. */
    var st = document.getElementById('mtStart'), en = document.getElementById('mtEnd');
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function setLen(mins) {
      if (!st.value) { return; }
      var p = st.value.split(':'), t = (+p[0]) * 60 + (+p[1]) + mins;
      t = Math.min(t, 23 * 60 + 55);
      en.value = pad(Math.floor(t / 60)) + ':' + pad(t % 60);
      mark();
    }
    function len() {
      if (!st.value || !en.value) { return 0; }
      var a = st.value.split(':'), b = en.value.split(':');
      return (+b[0] * 60 + +b[1]) - (+a[0] * 60 + +a[1]);
    }
    function mark() {
      var l = len();
      f.querySelectorAll('.chip[data-mins]').forEach(function (c) {
        c.classList.toggle('on', +c.getAttribute('data-mins') === l);
      });
    }
    f.querySelectorAll('.chip[data-mins]').forEach(function (c) {
      c.addEventListener('click', function () { setLen(+c.getAttribute('data-mins')); });
    });
    // Moving the start keeps the length the same, the way a calendar does.
    var lastLen = len() > 0 ? len() : 60;
    st.addEventListener('change', function () { setLen(lastLen); });
    en.addEventListener('change', function () { if (len() > 0) { lastLen = len(); } mark(); });
    mark();

    /* People: follow the client choice, search, and count. */
    var sel = document.getElementById('mtClientSel'), box = document.getElementById('pickbox'),
        none = document.getElementById('picknone'), hint = document.getElementById('pickhint'),
        q = document.getElementById('mtSearch'), cnt = document.getElementById('mtCount');
    function count() {
      if (!cnt || !box) { return; }
      var n = box.querySelectorAll('input:checked').length;
      cnt.textContent = n + ' selected';
      cnt.classList.toggle('on', n > 0);
    }
    function apply() {
      if (!box) { return; }
      var want = sel ? sel.value : null, term = q ? q.value.trim().toLowerCase() : '', shown = 0;
      box.querySelectorAll('.grp[data-org]').forEach(function (g) {
        var org = g.getAttribute('data-org');
        // The chosen organisation, and always our own team.
        var match = want === null || org === want || org === '0';
        if (!match) { g.querySelectorAll('input').forEach(function (c) { c.checked = false; }); }
        var vis = 0;
        g.querySelectorAll('.person').forEach(function (p) {
          var ok = match && (term === '' || p.getAttribute('data-name').indexOf(term) !== -1);
          p.hidden = !ok; if (ok) { vis++; }
        });
        g.hidden = vis === 0; shown += vis;
      });
      if (none) { none.hidden = shown > 0; }
      if (hint && sel) {
        hint.textContent = sel.value !== '0'
          ? 'Showing ' + sel.options[sel.selectedIndex].text + ' and our own team.'
          : 'Showing our own team. Choose a client above to see their people too.';
      }
      count();
    }
    if (sel) { sel.addEventListener('change', apply); }
    if (q) { q.addEventListener('input', apply); }
    if (box) { box.addEventListener('change', count); }
    apply();

    /* Friendly checks before it goes, and no double submit. */
    f.addEventListener('submit', function (ev) {
      var msg = '';
      if (!f.title.value.trim()) { msg = 'Give the meeting a title.'; f.title.focus(); }
      else if (len() <= 0) { msg = 'The meeting has to end after it starts.'; en.focus(); }
      else {
        var m = (f.querySelector('input[name="where_mode"]:checked') || {}).value;
        if (m === 'link' && !/^https:\/\//i.test(f.meet_url.value.trim())) { msg = 'Paste a meeting link starting with https://'; f.meet_url.focus(); }
        if (m === 'room' && !f.location.value.trim()) { msg = 'Say where the meeting is.'; f.location.focus(); }
      }
      if (msg) { ev.preventDefault(); alert(msg); return; }
      var b = document.getElementById('mtGo');
      b.disabled = true; b.textContent = 'Creating…';
    });
  })();
  </script>
<?php
}

/* ------------------------------------------------------- one meeting */

/**
 * One meeting. A header with the join button, a step tracker, a "next
 * step" panel, then the guest list, the close-off and the minutes. Anyone
 * who may only read it sees the same page without the controls.
 */
function fabo_meeting_one(array $viewer, array $m, ?array $flash): void
{
    $id      = (int) $m['id'];
    $isAdmin = !empty($viewer['is_admin']);
    $may     = fabo_meeting_may_manage($viewer, $m);
    // Pick up accepts and declines guests made in their own Google Calendar.
    $synced  = null;
    if (trim((string) ($m['calendar_event_id'] ?? '')) !== '' && fabo_google_connected()
        && $m['state'] === 'scheduled' && strtotime((string) $m['ends_at']) > time() - 86400) {
        fabo_meeting_sync_responses($m);
        $synced = (int) fabo_setting('rsvp_synced_' . $id, '0');
    }
    // Or read the Yes / Maybe / No guests pressed in their own mail app.
    $mailed  = null;
    if ($synced === null && $m['state'] === 'scheduled' && strtotime((string) $m['ends_at']) > time() - 86400) {
        require_once __DIR__ . '/imapreplies.php';
        if (fabo_imap_account()) {
            try {
                fabo_meeting_poll_replies();
            } catch (Throwable $e) {
                error_log('FABO meeting replies on page: ' . $e->getMessage());
            }
            $mailed = (int) fabo_setting('imap_ok_at', '0');
        }
    }
    $guests  = fabo_meeting_guests($id);
    $people  = fabo_meeting_invitables($viewer);
    $late    = fabo_meeting_overdue($m);
    $past    = strtotime((string) $m['ends_at']) < time();
    $gOn     = fabo_google_connected();
    $hasCal  = trim((string) ($m['calendar_event_id'] ?? '')) !== '';
    $link    = trim((string) ($m['meet_url'] ?? ''));
    $place   = trim((string) ($m['location'] ?? ''));
    $inRoom  = $link === '' && $place !== '' && !in_array($place, ['Google Meet', 'Online'], true);
    $state   = (string) $m['state'];

    $others   = array_values(array_filter($guests, static fn($g) => $g['role'] !== 'host'));
    $invited  = count(array_filter($guests, static fn($g) => !empty($g['invited_at']) && empty($g['invite_error'])));
    $pending  = count(array_filter($others, static fn($g) => empty($g['invited_at'])));
    $failed   = count(array_filter($guests, static fn($g) => !empty($g['invite_error'])));
    [$lab, $tone] = fabo_meeting_badge($m);
    $isMeet  = stripos($link, 'meet.google.com') !== false;
    $rs      = fabo_meeting_rsvp_counts($guests);
    $meG     = fabo_meeting_my_guest($viewer, $guests);
    $open    = $state === 'scheduled' && !$past;          // still worth answering / adding to a calendar
    $answers = fabo_meeting_responses();

    /* ---- the steps, and which one is next */
    $steps = [
        'made'   => ['Scheduled', true],
        'link'   => [$inRoom ? 'Place set' : 'Join link', $link !== '' || $inRoom],
        'invite' => ['Invitations', $others && $pending === 0 && $invited > 0],
        'held'   => ['Held', $state !== 'scheduled'],
        'notes'  => ['Minutes', !empty($m['minutes'])],
    ];
    if ($state === 'cancelled' || $state === 'no_show') {
        $steps['notes'][1] = true;      // nothing to minute
    }
    $next = null;
    foreach ($steps as $k => $s) {
        // Once it is over, a missing link or invitation is history, not a task.
        if (!$s[1] && !($past && in_array($k, ['link', 'invite'], true))) { $next = $k; break; }
    }
    // Before the meeting, "held" and "minutes" are not something to do yet.
    if (!$past && in_array($next, ['held', 'notes'], true) && $state === 'scheduled') {
        $next = 'ready';
    }

    fabo_head($viewer, $m['title'], 'meetings');
    ?>
<div class="wrap-page mt">

<a class="mt-back" href="<?= e(fabo_meet_url($viewer)) ?>">&larr; All meetings</a>

<?php fabo_meeting_flash($flash); ?>
<?php fabo_meeting_google_banner($viewer); ?>

<!-- ------------------------------------------------------------ header -->
<header class="mt-hero<?= $state === 'cancelled' ? ' void' : '' ?>">
  <?= fabo_meeting_datetile($m) ?>
  <div class="main">
    <div class="top">
      <span class="mt-badge <?= e($tone) ?>"><?= e($lab) ?></span>
      <span class="rel"><?= e(fabo_meeting_relative($m)) ?></span>
    </div>
    <h1><?= e($m['title']) ?></h1>
    <div class="meta">
      <span>&#128339; <?= e(date('l, j F Y', strtotime((string) $m['starts_at']))) ?> &middot;
        <?= e(date('H:i', strtotime((string) $m['starts_at']))) ?>&ndash;<?= e(date('H:i', strtotime((string) $m['ends_at']))) ?></span>
      <span>&#128101; <?= e($m['client_id'] ? 'With ' . fabo_meeting_client_name($m) : 'Internal meeting') ?></span>
      <span>Organised by <?= e($m['organiser_name']) ?></span>
    </div>
  </div>
  <div class="join">
    <?php if ($link !== ''): ?>
      <a class="btn btn-primary mt-big mt-joinbtn" href="<?= e($link) ?>" target="_blank" rel="noopener">
        <?= $isMeet ? '<svg class="ico" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="currentColor" d="M4 6.5A2.5 2.5 0 0 1 6.5 4h7A2.5 2.5 0 0 1 16 6.5v1.6l3.4-2.3A1 1 0 0 1 21 6.6v10.8a1 1 0 0 1-1.6.8L16 15.9v1.6a2.5 2.5 0 0 1-2.5 2.5h-7A2.5 2.5 0 0 1 4 17.5z"/></svg>Join Google Meet' : 'Join meeting' ?></a>
      <div class="mt-linkrow">
        <code title="<?= e($link) ?>"><?= e(preg_replace('~^https?://~i', '', $link)) ?></code>
        <button type="button" class="btn btn-sm" data-copy="<?= e($link) ?>">Copy link</button>
      </div>
    <?php elseif ($inRoom): ?>
      <div class="mt-place">&#128205; <strong><?= e($place) ?></strong></div>
    <?php else: ?>
      <div class="mt-place muted">No join link yet</div>
    <?php endif; ?>
    <?php if ($open): ?>
      <div class="mt-addcal">
        <span>Add to calendar:</span>
        <a href="<?= e(fabo_meeting_gcal_link($m)) ?>" target="_blank" rel="noopener">Google</a>
        <a href="<?= e(fabo_meet_url($viewer, ['id' => $id, 'ics' => 1])) ?>">Outlook / Apple</a>
      </div>
    <?php endif; ?>
  </div>
</header>

<!-- ------------------------------------------------------------ steps -->
<ol class="mt-steps" aria-label="Progress">
  <?php $i = 0; foreach ($steps as $k => $s): $i++;
      $cls = $s[1] ? 'done' : ($k === $next ? 'now' : ''); ?>
    <li class="<?= $cls ?>"><span class="dot"><?= $s[1] ? '&#10003;' : $i ?></span><span class="lb"><?= e($s[0]) ?></span></li>
  <?php endforeach; ?>
</ol>

<?php if ($meG && $open): $myAns = (string) ($meG['response'] ?? ''); ?>
<!-- ---------------------------------------------------- your own reply -->
<section class="mt-me">
  <div class="txt"><h3><?= $myAns === '' ? 'Are you going?' : 'Your reply' ?></h3>
    <p><?= $myAns === '' ? 'Let the organiser know.' : 'You can change it any time before the meeting.' ?></p></div>
  <form method="post" class="mt-rsvp">
    <?= fabo_csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="rsvp_self">
    <?php foreach (['yes' => '&#10003; Going', 'maybe' => 'Maybe', 'no' => '&#10005; Can&rsquo;t go'] as $k => $t): ?>
      <button class="r-<?= $k ?><?= $myAns === $k ? ' on' : '' ?>" name="answer" value="<?= $k ?>" aria-pressed="<?= $myAns === $k ? 'true' : 'false' ?>"><?= $t ?></button>
    <?php endforeach; ?>
  </form>
</section>
<?php endif; ?>

<?php if ($may && $state !== 'cancelled'): ?>
<!-- -------------------------------------------------------- next step -->
<section class="mt-next">
  <?php if ($next === 'link' && ($pend = fabo_meeting_pending_get($id)) !== null): ?>
    <div class="txt"><h3>&#8987; Waiting for Google to create the Meet link</h3>
      <p>The Google connection needs reconnecting. As soon as it is, the Meet link is created<?=
        !empty($pend['send']) ? ' and the invitations are emailed with it' : '' ?> automatically &mdash; nothing to do here.
        <?= $isAdmin ? '<a href="google.php">Reconnect Google now &rarr;</a>' : 'The administrator has been told.' ?>
        Can&rsquo;t wait? Use your own link and the invitations go out at once.</p></div>
    <div class="do">
      <button type="button" class="btn" data-reveal="mtOwnLink">Use my own link</button>
    </div>
    <form method="post" class="mt-inline" id="mtOwnLink" hidden>
      <?= fabo_csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
      <input name="meet_url" type="url" placeholder="https://…" required aria-label="Meeting link">
      <button class="btn btn-primary" name="action" value="set_link">Save &amp; send</button>
    </form>

  <?php elseif ($next === 'link'): ?>
    <div class="txt"><h3>Next: get a join link</h3>
      <p><?= $gOn
          ? 'One click makes a Google Meet link, puts the meeting on everyone&rsquo;s Google Calendar and emails the invitations.'
          : 'Paste a Zoom, Teams or Meet link so guests know where to join'
            . ($isAdmin ? ' &mdash; or <a href="google.php">connect Google</a> to make Meet links automatically.' : '.') ?></p></div>
    <div class="do">
      <?php if ($gOn): ?>
        <form method="post"><?= fabo_csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
          <button class="btn btn-primary mt-big" name="action" value="gcal_create" data-busy="Creating…">
            <svg class="ico" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="currentColor" d="M4 6.5A2.5 2.5 0 0 1 6.5 4h7A2.5 2.5 0 0 1 16 6.5v1.6l3.4-2.3A1 1 0 0 1 21 6.6v10.8a1 1 0 0 1-1.6.8L16 15.9v1.6a2.5 2.5 0 0 1-2.5 2.5h-7A2.5 2.5 0 0 1 4 17.5z"/></svg>Create Google Meet link</button></form>
        <button type="button" class="btn" data-reveal="mtOwnLink">Use my own link</button>
      <?php else: ?>
        <button type="button" class="btn btn-primary" data-reveal="mtOwnLink">Add a link</button>
      <?php endif; ?>
    </div>
    <form method="post" class="mt-inline" id="mtOwnLink" hidden>
      <?= fabo_csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
      <input name="meet_url" type="url" placeholder="https://…" required aria-label="Meeting link">
      <button class="btn btn-primary" name="action" value="set_link">Save link</button>
    </form>

  <?php elseif ($next === 'invite'): ?>
    <div class="txt"><h3><?= $others ? 'Next: send the invitations' : 'Next: add people' ?></h3>
      <p><?= $others
          ? $pending . ' of ' . count($others) . ' ' . (count($others) === 1 ? 'guest has' : 'guests have')
            . ' not been emailed yet. Everyone gets the time' . ($link !== '' ? ' and the join link' : '') . '.'
          : 'Nobody is invited yet. Add people in the guest list below.' ?></p></div>
    <div class="do">
      <?php if ($others): ?>
        <form method="post"><?= fabo_csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
          <?php if ($invited): ?><input type="hidden" name="again" value="1"><?php endif; ?>
          <button class="btn btn-primary mt-big" name="action" value="invite" data-busy="Sending…">&#9993; Send invitations</button></form>
      <?php else: ?>
        <a class="btn btn-primary" href="#guests" data-reveal="mtAdd">Add people</a>
      <?php endif; ?>
    </div>

  <?php elseif ($next === 'ready'): ?>
    <div class="txt"><h3>&#10003; All set</h3>
      <p><?= $link !== '' ? 'Link ready and invitations sent. Join from here when it&rsquo;s time.' : 'Invitations sent.' ?>
        Once it&rsquo;s over, come back to mark how it went and add the minutes.</p></div>
    <?php if ($link !== ''): ?><div class="do">
      <a class="btn btn-primary" href="<?= e($link) ?>" target="_blank" rel="noopener">Join</a>
    </div><?php endif; ?>

  <?php elseif ($next === 'held'): ?>
    <div class="txt"><h3>Next: how did it go?</h3>
      <p>The meeting has ended. Mark whether it happened and rate it, so the record is complete.</p></div>
    <div class="do"><a class="btn btn-primary" href="#close">Mark it</a></div>

  <?php elseif ($next === 'notes'): ?>
    <div class="txt"><h3>Next: add the minutes</h3>
      <p>Write them yourself<?= fabo_gemini_configured() ? ', or let Gemini draft them from the transcript' : '' ?>.</p></div>
    <div class="do"><a class="btn btn-primary" href="#minutes">Add minutes</a></div>

  <?php else: ?>
    <div class="txt"><h3>&#10003; Complete</h3><p>This meeting is closed and minuted. Nothing left to do.</p></div>
  <?php endif; ?>
</section>
<?php endif; ?>

<div class="mt-grid">
<div class="mt-col">

<!-- ---------------------------------------------------------- guests -->
<section class="card mt-card" id="guests">
  <div class="mt-card-head">
    <h2>Guests <span class="cnt"><?= count($guests) ?></span></h2>
    <?php if ($may): ?>
      <div class="acts">
        <button type="button" class="btn btn-sm" data-reveal="mtAdd">&#65291; Add people</button>
        <?php if ($others): ?>
        <form method="post"><?= fabo_csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
          <?php if ($invited): ?><input type="hidden" name="again" value="1"><?php endif; ?>
          <button class="btn btn-sm<?= $pending && fabo_meeting_pending_get($id) === null ? ' btn-primary' : '' ?>" name="action" value="invite" data-busy="Sending…"
            <?= $pending ? '' : 'onclick="return confirm(\'Everyone has been invited already. Send an update to all of them?\')"' ?>>
            &#9993; <?= $pending ? 'Send invitations' : 'Resend' ?></button></form>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($rs['total'] > 0): ?>
  <div class="mt-tally">
    <div class="mt-meter" role="img" aria-label="<?= $rs['yes'] ?> going, <?= $rs['maybe'] ?> maybe, <?= $rs['no'] ?> declined, <?= $rs['none'] ?> no reply">
      <?php foreach (['yes', 'maybe', 'no', 'none'] as $k): if ($rs[$k]): ?>
        <i class="<?= $k ?>" style="flex:<?= $rs[$k] ?>"></i>
      <?php endif; endforeach; ?>
    </div>
    <div class="keys">
      <span class="k yes"><b><?= $rs['yes'] ?></b> going</span>
      <span class="k maybe"><b><?= $rs['maybe'] ?></b> maybe</span>
      <span class="k no"><b><?= $rs['no'] ?></b> declined</span>
      <span class="k none"><b><?= $rs['none'] ?></b> no reply</span>
      <?php if (($hasCal && $gOn) || $mailed !== null): ?>
        <span class="sync">
          <?php if ($hasCal && $gOn): ?>
            <?= $synced ? 'Synced with Google Calendar ' . e(fabo_meeting_ago($synced)) : 'Google Calendar replies' ?>
          <?php else: ?>
            <?= $mailed ? 'Email replies checked ' . e(fabo_meeting_ago($mailed)) : 'Email replies not checked yet' ?>
          <?php endif; ?>
          <?php if ($may): ?>
          <form method="post"><?= fabo_csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
            <button class="mt-link" name="action" value="rsvp_sync" data-busy="Refreshing…">&#8635; Refresh</button></form>
          <?php endif; ?>
        </span>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($failed): ?>
    <div class="mt-flash err small"><span class="ic">!</span><div><?= $failed ?> invitation<?= $failed === 1 ? '' : 's' ?> could not be delivered. Check the address and resend.</div></div>
  <?php endif; ?>

  <?php if ($may): ?>
  <form method="post" class="mt-add" id="mtAdd" hidden>
    <?= fabo_csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
    <?php
      // This client and our own team, minus anyone already invited.
      $on   = array_flip(array_map('intval', array_filter(array_column($guests, 'client_id'))));
      $mine = (int) $m['client_id'];
      $left = array_values(array_filter($people, static function ($p) use ($on, $mine) {
          if (isset($on[(int) $p['id']])) {
              return false;
          }
          $org = (int) ($p['org'] ?? 0);
          return $org === $mine || $org === 0;
      }));
    ?>
    <?php if ($left): ?>
      <div class="mt-people compact">
        <?php foreach (fabo_meeting_people_groups($left) as $grp): ?>
          <div class="grp">
            <div class="ghead"><?= e($grp['label']) ?></div>
            <?php foreach ($grp['people'] as $p): ?>
              <label class="person">
                <input type="checkbox" name="invite[]" value="<?= (int) $p['id'] ?>">
                <span class="av"><?= e(fabo_meeting_initials((string) $p['name'], (string) $p['email'])) ?></span>
                <span class="nm"><?= e($p['name']) ?><small><?= e($p['email']) ?></small></span>
                <span class="tick" aria-hidden="true">&#10003;</span>
              </label>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <div class="field" style="margin-top:10px">
      <label>Email addresses</label>
      <textarea name="extra_emails" rows="2" placeholder="someone@example.com, another@example.com"></textarea>
    </div>
    <div class="mt-row-btns">
      <button class="btn btn-primary" name="action" value="guests">Add to the list</button>
      <button type="button" class="btn" data-reveal="mtAdd">Cancel</button>
    </div>
  </form>
  <?php endif; ?>

  <?php if (!$guests): ?>
    <div class="mt-empty small"><p>Nobody on the list yet.</p></div>
  <?php else: ?>
  <ul class="mt-guests">
    <?php foreach ($guests as $g):
        $nm = trim((string) ($g['name'] ?? '')); ?>
      <li>
        <span class="av<?= $g['role'] === 'host' ? ' host' : '' ?>"><?= e(fabo_meeting_initials($nm, (string) $g['email'])) ?></span>
        <span class="who">
          <span class="nm"><?= e($nm !== '' ? $nm : (string) $g['email']) ?>
            <?php if ($g['role'] === 'host'): ?><span class="mt-tag">Organiser</span><?php endif; ?></span>
          <small><?= $nm !== '' ? e((string) $g['email']) . ' &middot; ' : '' ?><?php
            if (!empty($g['invite_error'])): ?><span class="bad" title="<?= e((string) $g['invite_error']) ?>">email not delivered</span><?php
            elseif (!empty($g['invited_at'])): ?>invited <?= e(fabo_short_date((string) $g['invited_at'])) ?><?php
            elseif ($g['role'] !== 'host'): ?>not invited yet<?php
            else: ?>organiser<?php endif; ?></small>
        </span>
        <span class="st">
          <?php if ($g['role'] === 'host'): ?>
            <span class="mt-badge blue">Hosting</span>
          <?php else: $ans = (string) ($g['response'] ?? ''); [$al, $at] = $answers[$ans] ?? ['No reply', 'grey']; ?>
            <?php if ($may && $state === 'scheduled'): ?>
              <form method="post" class="mt-ans">
                <?= fabo_csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="guest_id" value="<?= (int) $g['id'] ?>"><input type="hidden" name="action" value="rsvp_set">
                <select name="answer" class="mt-badge <?= e($at) ?>" onchange="this.form.submit()" aria-label="Reply for <?= e($nm !== '' ? $nm : (string) $g['email']) ?>"
                        title="<?= !empty($g['responded_at']) ? 'Replied ' . e(fabo_short_date((string) $g['responded_at'])) : 'Set their reply by hand' ?>">
                  <option value="" <?= $ans === '' ? 'selected' : '' ?>>No reply</option>
                  <?php foreach ($answers as $k => [$l]): ?>
                    <option value="<?= $k ?>" <?= $ans === $k ? 'selected' : '' ?>><?= e($l) ?></option>
                  <?php endforeach; ?>
                </select>
                <noscript><button class="btn btn-sm">Set</button></noscript>
              </form>
            <?php else: ?>
              <span class="mt-badge <?= e($at) ?>"><?= e($al) ?></span>
            <?php endif; ?>
          <?php endif; ?>
        </span>
        <?php if ($may && $g['role'] !== 'host'): ?>
          <form method="post" onsubmit="return confirm('Remove <?= e(addslashes($nm !== '' ? $nm : (string) $g['email'])) ?> from this meeting?')">
            <?= fabo_csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="guest_id" value="<?= (int) $g['id'] ?>">
            <button class="mt-icon" name="action" value="guest_remove" title="Remove" aria-label="Remove">&times;</button>
          </form>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>

<!-- ---------------------------------------------------- how it went -->
<?php if ($may && ($past || $state !== 'scheduled')): ?>
<section class="card mt-card<?= $late ? ' mt-attn' : '' ?>" id="close">
  <div class="mt-card-head"><h2>How did it go?</h2></div>
  <form method="post" id="mtClose">
    <?= fabo_csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
    <div class="mt-choices three">
      <?php foreach (['held' => ['&#10003;', 'It happened'], 'no_show' => ['&#8856;', 'No show'], 'cancelled' => ['&#10005;', 'Cancelled']] as $k => [$ic, $t]): ?>
        <label class="mt-choice">
          <input type="radio" name="state" value="<?= $k ?>" <?= ($state === $k || ($state === 'scheduled' && $k === 'held')) ? 'checked' : '' ?>>
          <span class="box"><span class="t"><?= $ic ?> <?= $t ?></span></span>
        </label>
      <?php endforeach; ?>
    </div>

    <div class="field" data-state="held">
      <label>Rating</label>
      <div class="mt-stars" role="radiogroup" aria-label="Rating">
        <?php /* Highest first: the row is drawn reversed, so a checked star lights itself and those to its left. */ ?>
        <?php foreach (fabo_meeting_ratings() as $n => $label): ?>
          <input type="radio" id="star<?= (int) $n ?>" name="rating" value="<?= (int) $n ?>" <?= (int) $m['rating'] === (int) $n ? 'checked' : '' ?>>
          <label for="star<?= (int) $n ?>" title="<?= e($label) ?>">&#9733;</label>
        <?php endforeach; ?>
      </div>
      <p class="mt-hint" id="mtStarText"><?= $m['rating'] ? e(fabo_meeting_ratings()[(int) $m['rating']] ?? '') : 'Tap a star' ?></p>
      <input name="rating_note" value="<?= e((string) ($m['rating_note'] ?? '')) ?>" placeholder="What made it good, or what was missing? (optional)">
    </div>

    <div class="field">
      <label for="mtOutcome">Outcome <span class="opt" id="mtOutReq">optional</span></label>
      <textarea id="mtOutcome" name="outcome" rows="3"
        placeholder="The short version: what was agreed, or why it didn't happen"><?= e((string) ($m['outcome'] ?? '')) ?></textarea>
    </div>
    <div class="mt-row-btns">
      <button class="btn btn-primary" name="action" value="close">Save</button>
      <span class="mt-hint">Portal users who were invited get a notice.</span>
    </div>
  </form>
</section>
<?php endif; ?>

<!-- ---------------------------------------------------------- minutes -->
<section class="card mt-card" id="minutes">
  <div class="mt-card-head">
    <h2>Minutes</h2>
    <?php if (!empty($m['minutes'])): ?>
      <span class="mt-hint">Written <?= e(fabo_short_date((string) $m['minutes_at'])) ?>
        <?= $m['minutes_source'] === 'gemini' ? ' &middot; <span class="mt-tag">Drafted by Gemini</span>' : '' ?></span>
    <?php endif; ?>
  </div>

  <?php if (!empty($m['minutes'])): ?>
    <div class="minutes"><?= e((string) $m['minutes']) ?></div>
  <?php elseif (!$may): ?>
    <div class="mt-empty small"><p>No minutes yet.</p></div>
  <?php elseif (!$past && $state === 'scheduled'): ?>
    <div class="mt-empty small"><p>Minutes can be added once the meeting is over.</p></div>
  <?php endif; ?>

  <?php if ($may): ?>
    <?php $hasT = trim((string) ($m['transcript'] ?? '')) !== ''; $gem = fabo_gemini_configured(); ?>
    <?php if ($gem || $gOn || $hasT): ?>
    <div class="mt-ai">
      <div class="lane">
        <span class="n">1</span>
        <div>
          <strong>Transcript</strong>
          <p class="mt-hint"><?= $hasT
              ? number_format(str_word_count((string) $m['transcript'])) . ' words saved.'
              : ($gOn && $isMeet ? 'Fetch it from Google Meet after the call (transcription must have been on), or paste one.' : 'Paste the transcript or your rough notes.') ?></p>
          <div class="mt-row-btns">
            <?php if ($gOn && trim((string) ($m['conference_id'] ?? '')) !== ''): ?>
              <form method="post"><?= fabo_csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
                <button class="btn btn-sm" name="action" value="transcript_fetch" data-busy="Fetching…">Fetch from Meet</button></form>
            <?php endif; ?>
            <button type="button" class="btn btn-sm" data-reveal="mtTranscript"><?= $hasT ? 'View / edit' : 'Paste' ?></button>
          </div>
        </div>
      </div>
      <div class="lane">
        <span class="n">2</span>
        <div>
          <strong>Draft with Gemini</strong>
          <p class="mt-hint"><?= !$gem ? 'No Gemini key is set up.' : ($hasT ? 'Writes a summary, decisions and actions. Check it before sharing.' : 'Needs a transcript first.') ?></p>
          <?php if ($gem && $hasT): ?>
          <form method="post" <?= !empty($m['minutes']) ? 'onsubmit="return confirm(\'Replace the current minutes with a new draft?\')"' : '' ?>>
            <?= fabo_csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
            <button class="btn btn-sm btn-primary" name="action" value="gemini_minutes" data-busy="Writing… (up to a minute)">&#10024; <?= !empty($m['minutes']) ? 'Redraft' : 'Draft minutes' ?></button>
          </form>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <form method="post" id="mtTranscript" class="mt-sub" hidden>
      <?= fabo_csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
      <div class="field"><label>Transcript</label>
        <textarea name="transcript" rows="8" placeholder="Speaker: what they said…"><?= e((string) ($m['transcript'] ?? '')) ?></textarea></div>
      <button class="btn btn-sm btn-primary" name="action" value="transcript_save">Save transcript</button>
    </form>
    <?php endif; ?>

    <?php if ($past || $state !== 'scheduled' || !empty($m['minutes'])): ?>
    <details class="mt-sub"<?= empty($m['minutes']) ? ' open' : '' ?>>
      <summary class="btn btn-sm"><?= !empty($m['minutes']) ? '&#9998; Edit minutes' : '&#9998; Write minutes yourself' ?></summary>
      <form method="post" style="margin-top:10px">
        <?= fabo_csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
        <div class="field"><textarea name="minutes" rows="12" aria-label="Minutes"
          placeholder="SUMMARY&#10;…&#10;&#10;DECISIONS&#10;…&#10;&#10;ACTIONS&#10;Who — what — by when"><?= e((string) ($m['minutes'] ?? '')) ?></textarea></div>
        <button class="btn btn-primary" name="action" value="minutes_save">Save minutes</button>
      </form>
    </details>
    <?php endif; ?>
  <?php endif; ?>
</section>

</div>
<aside class="mt-col side">

<!-- ------------------------------------------------------------ where -->
<section class="card mt-card">
  <div class="mt-card-head"><h2>Where</h2></div>
  <?php if ($link !== ''): ?>
    <p class="mt-kv"><span>Link</span><a href="<?= e($link) ?>" target="_blank" rel="noopener" class="brk"><?= e($link) ?></a></p>
  <?php endif; ?>
  <?php if ($place !== ''): ?>
    <p class="mt-kv"><span>Place</span><strong><?= e($place) ?></strong></p>
  <?php endif; ?>
  <?php if ($hasCal): ?>
    <p class="mt-kv"><span>Calendar</span><strong class="ok">&#10003; On Google Calendar</strong></p>
  <?php endif; ?>
  <?php if ($link === '' && $place === ''): ?>
    <p class="mt-hint">Not set yet.</p>
  <?php endif; ?>

  <?php if ($may): ?>
    <div class="mt-row-btns">
      <?php if ($gOn && !$hasCal && !$past && $state === 'scheduled'): ?>
        <form method="post"><?= fabo_csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
          <button class="btn btn-sm" name="action" value="gcal_create" data-busy="Creating…"><svg class="ico" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="currentColor" d="M4 6.5A2.5 2.5 0 0 1 6.5 4h7A2.5 2.5 0 0 1 16 6.5v1.6l3.4-2.3A1 1 0 0 1 21 6.6v10.8a1 1 0 0 1-1.6.8L16 15.9v1.6a2.5 2.5 0 0 1-2.5 2.5h-7A2.5 2.5 0 0 1 4 17.5z"/></svg>Create Meet link</button></form>
      <?php endif; ?>
      <button type="button" class="btn btn-sm" data-reveal="mtLinkEdit"><?= $link === '' ? 'Add own link' : 'Change link' ?></button>
      <?php if ($hasCal): ?>
        <form method="post" onsubmit="return confirm('Remove this from Google Calendar? Guests will be told it is cancelled.')">
          <?= fabo_csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
          <button class="btn btn-sm btn-danger" name="action" value="gcal_cancel">Remove from calendar</button></form>
      <?php endif; ?>
    </div>
    <form method="post" id="mtLinkEdit" class="mt-sub" hidden>
      <?= fabo_csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
      <div class="field"><input name="meet_url" type="url" value="<?= e($link) ?>" placeholder="https://…" aria-label="Meeting link">
        <p class="mt-hint">Leave empty and save to remove the link.</p></div>
      <button class="btn btn-sm btn-primary" name="action" value="set_link">Save link</button>
    </form>
  <?php endif; ?>
</section>

<!-- ----------------------------------------------------------- agenda -->
<section class="card mt-card">
  <div class="mt-card-head"><h2>Agenda</h2></div>
  <?php if (trim((string) ($m['agenda'] ?? '')) !== ''): ?>
    <ul class="mt-agenda">
      <?php foreach (preg_split('/\R+/', trim((string) $m['agenda'])) as $line): ?>
        <?php if (trim($line) !== ''): ?><li><?= e(ltrim(trim($line), "-•* \t")) ?></li><?php endif; ?>
      <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <p class="mt-hint">No agenda written.</p>
  <?php endif; ?>
  <?php if (!empty($m['outcome'])): ?>
    <div class="mt-card-head" style="margin-top:14px"><h2>Outcome</h2></div>
    <p style="white-space:pre-wrap;margin:0"><?= e((string) $m['outcome']) ?></p>
  <?php endif; ?>
</section>

<!-- ------------------------------------------------------------- edit -->
<?php if ($may): ?>
<details class="card mt-card mt-edit">
  <summary><h2>&#9998; Edit details</h2><span class="chev" aria-hidden="true">&#9662;</span></summary>
  <form method="post" style="margin-top:12px">
    <?= fabo_csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
    <div class="field"><label>Title</label>
      <input name="title" value="<?= e($m['title']) ?>" required></div>
    <div class="field"><label>Date</label>
      <input type="date" name="date" value="<?= e(date('Y-m-d', strtotime((string) $m['starts_at']))) ?>" required></div>
    <div class="mt-when two">
      <div class="field"><label>Starts</label>
        <input type="time" name="start_time" step="300" value="<?= e(date('H:i', strtotime((string) $m['starts_at']))) ?>" required></div>
      <div class="field"><label>Ends</label>
        <input type="time" name="end_time" step="300" value="<?= e(date('H:i', strtotime((string) $m['ends_at']))) ?>" required></div>
    </div>
    <div class="field"><label>Place</label>
      <input name="location" value="<?= e($place) ?>"></div>
    <input type="hidden" name="meet_url" value="<?= e($link) ?>">
    <div class="field"><label>Agenda</label>
      <textarea name="agenda" rows="4"><?= e((string) ($m['agenda'] ?? '')) ?></textarea></div>
    <div class="mt-row-btns">
      <button class="btn btn-primary" name="action" value="update">Save changes</button>
    </div>
    <?php if ($hasCal): ?><p class="mt-hint">Changes are copied to Google Calendar too.</p><?php endif; ?>
  </form>
  <?php if ($state !== 'held'): ?>
    <form method="post" class="mt-danger" onsubmit="return confirm('Delete this meeting for good?<?= $hasCal ? ' It will also be removed from Google Calendar.' : '' ?>')">
      <?= fabo_csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
      <button class="btn btn-sm btn-danger" name="action" value="delete">Delete meeting</button>
    </form>
  <?php endif; ?>
</details>
<?php endif; ?>

</aside>
</div>

</div>

<script>
(function () {
  /* Show / hide the small panels. */
  document.querySelectorAll('[data-reveal]').forEach(function (b) {
    b.addEventListener('click', function (ev) {
      var el = document.getElementById(b.getAttribute('data-reveal'));
      if (!el) { return; }
      if (b.tagName === 'A') { ev.preventDefault(); }
      el.hidden = !el.hidden;
      if (!el.hidden) {
        el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        var i = el.querySelector('input:not([type=hidden]),textarea');
        if (i) { i.focus(); }
      }
    });
  });

  /* Copy the link, with a fallback for older browsers. */
  document.querySelectorAll('[data-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
      var text = b.getAttribute('data-copy'), done = function () {
        var o = b.textContent; b.textContent = 'Copied ✓'; b.classList.add('ok');
        setTimeout(function () { b.textContent = o; b.classList.remove('ok'); }, 1600);
      };
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(done, function () { fallback(); });
      } else { fallback(); }
      function fallback() {
        var t = document.createElement('textarea'); t.value = text; t.style.position = 'fixed'; t.style.opacity = '0';
        document.body.appendChild(t); t.select();
        try { document.execCommand('copy'); done(); } catch (e) { prompt('Copy this link:', text); }
        document.body.removeChild(t);
      }
    });
  });

  /* Buttons that call Google or send mail take a few seconds: say so,
     and stop a second click sending it twice. */
  document.querySelectorAll('button[data-busy]').forEach(function (b) {
    var f = b.form; if (!f) { return; }
    f.addEventListener('submit', function (ev) {
      if (ev.defaultPrevented) { return; }
      setTimeout(function () { b.disabled = true; b.textContent = b.getAttribute('data-busy'); }, 0);
      // The disabled button would not be sent, so carry its value across.
      var h = document.createElement('input'); h.type = 'hidden'; h.name = b.name; h.value = b.value; f.appendChild(h);
    });
  });

  /* "How did it go": rating only for a held meeting; outcome needed to cancel. */
  var cf = document.getElementById('mtClose');
  if (cf) {
    var rate = cf.querySelector('[data-state="held"]'), req = document.getElementById('mtOutReq'),
        out = document.getElementById('mtOutcome'), txt = document.getElementById('mtStarText'),
        words = <?= json_encode(fabo_meeting_ratings(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    var sync = function () {
      var s = (cf.querySelector('input[name="state"]:checked') || {}).value;
      if (rate) { rate.hidden = s !== 'held'; }
      if (req) { req.textContent = s === 'cancelled' ? 'required' : 'optional'; req.classList.toggle('req', s === 'cancelled'); }
      if (out) { out.required = s === 'cancelled'; }
    };
    cf.querySelectorAll('input[name="state"]').forEach(function (r) { r.addEventListener('change', sync); });
    cf.querySelectorAll('input[name="rating"]').forEach(function (r) {
      r.addEventListener('change', function () { if (txt) { txt.textContent = words[r.value] || ''; } });
    });
    sync();
  }
})();
</script>
<?php
    fabo_foot();
}

/** The organisation a meeting is with, however it is named. */
function fabo_meeting_client_name(array $m): string
{
    $c = fabo_one('SELECT name, organisation FROM clients WHERE id = ?',
                  'i', [(int) $m['client_id']]);
    if (!$c) {
        return 'a client since removed';
    }
    return ($c['organisation'] ?? '') !== '' ? (string) $c['organisation'] : (string) $c['name'];
}

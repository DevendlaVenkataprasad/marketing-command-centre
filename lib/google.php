<?php
/**
 * Google Calendar, Google Meet and Gemini — the optional layer under the
 * meetings desk.
 *
 * Everything here is optional and everything degrades. When the portal is
 * not connected to Google, every function returns [false, reason] and the
 * meetings module carries on: you paste a Meet link by hand and type the
 * minutes. Nothing in lib/meetings.php depends on this file.
 *
 * WHAT GOOGLE ACTUALLY ALLOWS, so the buttons do not promise more than they
 * can deliver:
 *
 *   Calendar event + Meet link   fully supported. calendar.events with a
 *                                conferenceData createRequest returns a
 *                                real Meet URL. This is the reliable part.
 *
 *   Meet transcript              supported, with conditions. The Meet REST
 *                                API v2 serves a transcript only if the
 *                                Workspace edition includes transcription
 *                                AND somebody turned it on during the call.
 *                                A personal @gmail.com account cannot.
 *
 *   "Gemini notes"               there is NO public API that triggers
 *                                Gemini's take-notes-for-me, and none that
 *                                reads the notes document it produces. So
 *                                the portal does the equivalent itself: it
 *                                takes the transcript and asks the Gemini
 *                                API to write the minutes. Same result,
 *                                reached by a supported route.
 *
 * Credentials live in two places for a reason. The client id and secret are
 * settings and belong in config.local.php. The refresh token is handed over
 * by Google at the end of the consent flow, so it can only be stored at
 * runtime — it goes in app_settings.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/meetings.php';      // the invitation description

/* --------------------------------------------------------- the settings */

function fabo_setting(string $key, string $default = ''): string
{
    $r = fabo_one('SELECT v FROM app_settings WHERE k = ?', 's', [$key]);
    return $r === null ? $default : (string) ($r['v'] ?? '');
}

function fabo_setting_put(string $key, ?string $value): void
{
    if ($value === null || $value === '') {
        fabo_exec('DELETE FROM app_settings WHERE k = ?', 's', [$key]);
        return;
    }
    fabo_exec('INSERT INTO app_settings (k, v) VALUES (?,?)
               ON DUPLICATE KEY UPDATE v = VALUES(v)', 'ss', [$key, $value]);
}

/** The OAuth client exists, so connecting is at least possible. */
function fabo_google_configured(): bool
{
    return trim((string) fabo_env_or('GOOGLE_CLIENT_ID')) !== ''
        && trim((string) fabo_env_or('GOOGLE_CLIENT_SECRET')) !== '';
}

/** Somebody has been through consent and we hold a refresh token. */
function fabo_google_connected(): bool
{
    return fabo_google_configured() && fabo_setting('google_refresh_token') !== '';
}

/** A constant if defined, else the environment, else ''. */
function fabo_env_or(string $name, string $default = ''): string
{
    if (defined($name)) {
        return (string) constant($name);
    }
    $v = getenv($name);
    return $v === false ? $default : (string) $v;
}

/**
 * Connected AND working. fabo_google_connected() only knows a token is
 * stored; this also knows whether Google last refused to renew it (the
 * seven-day expiry of a consent screen left in Testing), so the meetings
 * desk can say so before anybody presses a button that will fail.
 */
function fabo_google_ready(): bool
{
    return fabo_google_connected() && fabo_setting('google_broken') === '';
}

/**
 * Checks the connection actually works, at most every ten minutes unless
 * forced, by asking for an access token (which renews it when due).
 * Returns [ok, reason].
 */
function fabo_google_health(bool $force = false): array
{
    if (!fabo_google_connected()) {
        return [false, 'The portal is not connected to Google.'];
    }
    if (!$force && time() - (int) fabo_setting('google_health_at', '0') < 600) {
        $err = fabo_setting('google_broken');
        return [$err === '', $err];
    }
    fabo_setting_put('google_health_at', (string) time());
    [$ok, $res] = fabo_google_access_token();
    return [$ok, $ok ? '' : (string) $res];
}

/**
 * Records whether the connection works. The first time it stops, the
 * administrator gets a notification saying so and what to press.
 */
function fabo_google_mark(bool $ok, string $why = ''): void
{
    $was = fabo_setting('google_broken');
    if ($ok) {
        if ($was !== '') {
            fabo_setting_put('google_broken', null);
        }
        return;
    }
    fabo_setting_put('google_broken', $why !== '' ? $why : 'Google refused the connection.');
    if ($was === '') {
        require_once __DIR__ . '/notify.php';
        fabo_notify_admin('Google connection stopped working',
            'New meetings cannot get a Google Meet link until it is reconnected. Open Google and press '
            . 'Reconnect. Meetings waiting for a link get it, and their invitations, automatically after that.',
            'google.php');
    }
}

/** The Google account the portal is connected as, for the settings page. */
function fabo_google_account(): string
{
    return fabo_setting('google_account');
}

/**
 * How long the connection has been in place, in days, or null if unknown.
 *
 * Worth surfacing because a consent screen in Testing expires its refresh
 * token after seven days; a connection quietly stops working on day eight
 * and nothing says why until somebody presses a button.
 */
function fabo_google_connected_days(): ?float
{
    $at = (int) fabo_setting('google_connected_at', '0');
    return $at > 0 ? (time() - $at) / 86400 : null;
}

/**
 * What we ask Google for.
 *
 * calendar.events  creates the event and its Meet conference.
 * meetings.space.readonly  reads back the conference record and its
 *                          transcript after the call.
 * userinfo.email   only so the settings page can say which account is
 *                  connected; nothing else uses it.
 */
function fabo_google_scopes(): array
{
    return [
        'https://www.googleapis.com/auth/calendar.events',
        'https://www.googleapis.com/auth/meetings.space.readonly',
        'https://www.googleapis.com/auth/userinfo.email',
    ];
}

/** Where Google sends the browser back to. Must match the console exactly. */
function fabo_google_redirect(): string
{
    require_once __DIR__ . '/ui.php';
    return fabo_portal_url() . 'admin/google.php';
}

/* ------------------------------------------------------------- the wire */

/**
 * One HTTPS call. Returns [ok, decodedBodyOrError, httpStatus].
 *
 * Certificate checking stays on and uses the same CA bundle the mailer
 * uses, because Windows PHP ships without one.
 */
function fabo_http(string $method, string $url, $body = null, array $headers = [],
                   int $timeout = 25): array
{
    if (!function_exists('curl_init')) {
        return [false, 'PHP on this server has no cURL, so Google cannot be reached.', 0];
    }
    $ch = curl_init($url);
    $opt = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => $headers,
    ];
    if (defined('SMTP_CAFILE') && is_file(SMTP_CAFILE)) {
        $opt[CURLOPT_CAINFO] = SMTP_CAFILE;
    }
    if ($body !== null) {
        $opt[CURLOPT_POSTFIELDS] = is_string($body) ? $body : json_encode($body);
    }
    /* Response headers, captured for the callers that need them. LinkedIn
       returns a created post's id in x-restli-id rather than in the body,
       so without this there is no way to record what was just published. */
    $GLOBALS['fabo_http_headers'] = [];
    $opt[CURLOPT_HEADERFUNCTION] = static function ($ch, string $line): int {
        $trimmed = trim($line);
        if ($trimmed !== '') {
            $GLOBALS['fabo_http_headers'][] = $trimmed;
        }
        return strlen($line);
    };
    curl_setopt_array($ch, $opt);

    $raw    = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    // No curl_close(): it has done nothing since PHP 8.0 and is deprecated
    // in 8.5. The handle is freed when $ch goes out of scope.
    unset($ch);

    if ($raw === false) {
        return [false, 'Could not reach Google: ' . $err, 0];
    }
    $decoded = json_decode((string) $raw, true);
    if ($status >= 200 && $status < 300) {
        return [true, $decoded ?? [], $status];
    }
    /*
     * Google's errors are surfaced verbatim; they say what is wrong. The two
     * shapes are not the same and both parts matter:
     *
     *   the APIs say  {"error": {"message": "..."}}
     *   OAuth says    {"error": "invalid_grant", "error_description": "Bad Request"}
     *
     * On the OAuth shape the code is the diagnostic half and the description
     * is often useless ("Bad Request"), so the code is kept rather than
     * dropped in favour of it.
     */
    $msg = $decoded['error']['message'] ?? null;
    if ($msg === null) {
        $code = is_string($decoded['error'] ?? null) ? $decoded['error'] : '';
        $desc = is_string($decoded['error_description'] ?? null) ? $decoded['error_description'] : '';
        if ($code !== '' && $desc !== '' && stripos($desc, $code) === false) {
            $msg = $code . ': ' . $desc;
        } else {
            $msg = $code ?: ($desc ?: ('HTTP ' . $status));
        }
    }
    return [false, is_string($msg) ? $msg : json_encode($msg), $status];
}

/* --------------------------------------------------------------- OAuth */

/** The URL the administrator is sent to in order to grant access. */
function fabo_google_auth_url(string $state): string
{
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id'     => fabo_env_or('GOOGLE_CLIENT_ID'),
        'redirect_uri'  => fabo_google_redirect(),
        'response_type' => 'code',
        'scope'         => implode(' ', fabo_google_scopes()),
        // offline + consent is what makes Google hand over a refresh token;
        // without both, the connection dies an hour later and stays dead.
        'access_type'   => 'offline',
        'prompt'        => 'consent',
        'include_granted_scopes' => 'true',
        'state'         => $state,
    ]);
}

/** Trades the code Google sent back for tokens. Returns [ok, message]. */
function fabo_google_exchange_code(string $code): array
{
    [$ok, $res] = fabo_http('POST', 'https://oauth2.googleapis.com/token',
        http_build_query([
            'code'          => $code,
            'client_id'     => fabo_env_or('GOOGLE_CLIENT_ID'),
            'client_secret' => fabo_env_or('GOOGLE_CLIENT_SECRET'),
            'redirect_uri'  => fabo_google_redirect(),
            'grant_type'    => 'authorization_code',
        ]),
        ['Content-Type: application/x-www-form-urlencoded']);

    if (!$ok) {
        return [false, 'Google refused the sign-in: ' . $res];
    }
    if (empty($res['refresh_token'])) {
        return [false, 'Google did not return a refresh token. Remove the portal from '
                     . 'your Google account permissions and connect again.'];
    }
    fabo_setting_put('google_refresh_token', (string) $res['refresh_token']);
    fabo_google_mark(true);
    fabo_google_cache_access((string) ($res['access_token'] ?? ''),
                             (int) ($res['expires_in'] ?? 0));

    // Which account this is, purely so the settings page can say so.
    [$who, $info] = fabo_http('GET', 'https://www.googleapis.com/oauth2/v2/userinfo',
                              null, ['Authorization: Bearer ' . ($res['access_token'] ?? '')]);
    fabo_setting_put('google_account', $who ? (string) ($info['email'] ?? '') : '');
    fabo_setting_put('google_connected_at', (string) time());

    return [true, 'Connected to Google' . ($who && !empty($info['email'])
                  ? ' as ' . $info['email'] : '') . '.'];
}

function fabo_google_disconnect(): void
{
    fabo_setting_put('google_broken', null);
    fabo_setting_put('google_refresh_token', null);
    fabo_setting_put('google_access_token', null);
    fabo_setting_put('google_access_expires', null);
    fabo_setting_put('google_account', null);
    fabo_setting_put('google_connected_at', null);
}

function fabo_google_cache_access(string $token, int $expiresIn): void
{
    if ($token === '') {
        return;
    }
    fabo_setting_put('google_access_token', $token);
    // A minute of slack, so a token never expires mid-call.
    fabo_setting_put('google_access_expires', (string) (time() + max(60, $expiresIn - 60)));
}

/** A usable access token, refreshed if need be. Returns [ok, tokenOrError]. */
function fabo_google_access_token(): array
{
    if (!fabo_google_configured()) {
        return [false, 'Google is not set up: GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET '
                     . 'are missing from config.local.php.'];
    }
    $refresh = fabo_setting('google_refresh_token');
    if ($refresh === '') {
        return [false, 'The portal is not connected to Google yet.'];
    }
    $token = fabo_setting('google_access_token');
    $until = (int) fabo_setting('google_access_expires', '0');
    if ($token !== '' && $until > time()) {
        return [true, $token];
    }

    [$ok, $res] = fabo_http('POST', 'https://oauth2.googleapis.com/token',
        http_build_query([
            'client_id'     => fabo_env_or('GOOGLE_CLIENT_ID'),
            'client_secret' => fabo_env_or('GOOGLE_CLIENT_SECRET'),
            'refresh_token' => $refresh,
            'grant_type'    => 'refresh_token',
        ]),
        ['Content-Type: application/x-www-form-urlencoded']);

    if (!$ok) {
        /*
         * The commonest cause by far is the seven-day expiry Google applies
         * to refresh tokens while the OAuth consent screen is still in
         * Testing. It reads as a generic invalid_grant, so it is worth
         * naming — otherwise it looks like something is broken when it is
         * only the publishing status.
         */
        $expired = stripos((string) $res, 'invalid_grant') !== false
                || stripos((string) $res, 'expired') !== false
                || stripos((string) $res, 'revoked') !== false;
        $why = $expired
            ? 'The Google connection has expired. This happens every seven days while '
              . 'the OAuth consent screen is in Testing. Open Admin → Google and press '
              . 'Connect again — or publish the consent screen to stop it recurring.'
            : 'Google would not renew the connection: ' . $res
              . ' — reconnect on the Google page.';
        // Only a refusal is remembered as broken; a network blip is not.
        if ($expired || stripos((string) $res, 'invalid_client') !== false) {
            fabo_google_mark(false, $why);
        }
        return [false, $expired
            ? 'The Google connection has expired. This happens every seven days while '
              . 'the OAuth consent screen is in Testing. Open Admin → Google and press '
              . 'Connect again — or publish the consent screen to stop it recurring.'
            : 'Google would not renew the connection: ' . $res
              . ' — reconnect on the Google page.'];
    }
    fabo_google_cache_access((string) ($res['access_token'] ?? ''),
                             (int) ($res['expires_in'] ?? 0));
    fabo_google_mark(true);
    return [true, (string) ($res['access_token'] ?? '')];
}

/** A Google API call with the token attached. Returns [ok, bodyOrError]. */
function fabo_google_api(string $method, string $url, $body = null): array
{
    [$ok, $token] = fabo_google_access_token();
    if (!$ok) {
        return [false, $token];
    }
    [$ok, $res] = fabo_http($method, $url, $body, [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
    ]);
    return [$ok, $res];
}

/* ------------------------------------------------- Calendar and Meet */

/** RFC3339 in the portal's own timezone, which Calendar needs spelled out. */
function fabo_google_rfc3339(string $dbDateTime): string
{
    return date('c', strtotime($dbDateTime));
}

/**
 * Creates the calendar event and its Meet conference.
 * Returns [ok, ['event_id','meet_url','conference_id'] | errorMessage].
 */
function fabo_google_create_event(array $meeting, array $guests): array
{
    $attendees = [];
    foreach ($guests as $g) {
        $attendees[] = ['email' => $g['email']];
    }
    $payload = [
        'summary'     => $meeting['title'],
        'description' => fabo_meeting_cal_html($meeting),   // the body of Google's invitation email
        'start' => ['dateTime' => fabo_google_rfc3339((string) $meeting['starts_at']),
                    'timeZone' => date_default_timezone_get()],
        'end'   => ['dateTime' => fabo_google_rfc3339((string) $meeting['ends_at']),
                    'timeZone' => date_default_timezone_get()],
        'attendees' => $attendees,
        'conferenceData' => [
            'createRequest' => [
                // Google requires a caller-supplied id it can deduplicate on.
                'requestId' => 'fabo-' . $meeting['id'] . '-' . bin2hex(random_bytes(4)),
                'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
            ],
        ],
    ];

    // conferenceDataVersion=1 is what tells Calendar to honour the request;
    // without it the event is created with no Meet link and no error.
    $url = 'https://www.googleapis.com/calendar/v3/calendars/primary/events'
         . '?conferenceDataVersion=1&sendUpdates=all';

    [$ok, $res] = fabo_google_api('POST', $url, $payload);
    if (!$ok) {
        return [false, $res];
    }

    $meet = $res['hangoutLink'] ?? '';
    if ($meet === '' && !empty($res['conferenceData']['entryPoints'])) {
        foreach ($res['conferenceData']['entryPoints'] as $ep) {
            if (($ep['entryPointType'] ?? '') === 'video' && !empty($ep['uri'])) {
                $meet = (string) $ep['uri'];
                break;
            }
        }
    }
    return [true, [
        'event_id'      => (string) ($res['id'] ?? ''),
        'meet_url'      => (string) $meet,
        'conference_id' => (string) ($res['conferenceData']['conferenceId'] ?? ''),
    ]];
}

/** Pushes a changed time or guest list back to Calendar. [ok, message]. */
function fabo_google_update_event(array $meeting, array $guests): array
{
    $eventId = trim((string) ($meeting['calendar_event_id'] ?? ''));
    if ($eventId === '') {
        return [false, 'This meeting has no calendar event.'];
    }
    $attendees = [];
    foreach ($guests as $g) {
        $attendees[] = ['email' => $g['email']];
    }
    [$ok, $res] = fabo_google_api('PATCH',
        'https://www.googleapis.com/calendar/v3/calendars/primary/events/'
        . rawurlencode($eventId) . '?sendUpdates=all', [
            'summary'     => $meeting['title'],
            'description' => fabo_meeting_cal_html($meeting),   // the body of Google's invitation email
            'start' => ['dateTime' => fabo_google_rfc3339((string) $meeting['starts_at']),
                        'timeZone' => date_default_timezone_get()],
            'end'   => ['dateTime' => fabo_google_rfc3339((string) $meeting['ends_at']),
                        'timeZone' => date_default_timezone_get()],
            'attendees' => $attendees,
        ]);
    return $ok ? [true, 'Calendar updated.'] : [false, $res];
}

/**
 * Each attendee's answer on the calendar event, as email => responseStatus
 * (accepted, declined, tentative or needsAction). [ok, map | errorMessage].
 */
function fabo_google_event_responses(array $meeting): array
{
    $eventId = trim((string) ($meeting['calendar_event_id'] ?? ''));
    if ($eventId === '') {
        return [false, 'This meeting has no calendar event.'];
    }
    [$ok, $res] = fabo_google_api('GET',
        'https://www.googleapis.com/calendar/v3/calendars/primary/events/'
        . rawurlencode($eventId) . '?fields=attendees(email,responseStatus)');
    if (!$ok) {
        return [false, 'Google said: ' . $res];
    }
    $out = [];
    foreach ($res['attendees'] ?? [] as $a) {
        if (!empty($a['email'])) {
            $out[strtolower((string) $a['email'])] = (string) ($a['responseStatus'] ?? 'needsAction');
        }
    }
    return [true, $out];
}

/** Cancels the calendar event. [ok, message]. */
function fabo_google_delete_event(array $meeting): array
{
    $eventId = trim((string) ($meeting['calendar_event_id'] ?? ''));
    if ($eventId === '') {
        return [true, 'Nothing on the calendar to cancel.'];
    }
    [$ok, $res] = fabo_google_api('DELETE',
        'https://www.googleapis.com/calendar/v3/calendars/primary/events/'
        . rawurlencode($eventId) . '?sendUpdates=all');
    return $ok ? [true, 'Removed from the calendar.'] : [false, $res];
}

/**
 * The Meet code out of a link — abc-defg-hij from meet.google.com/abc-defg-hij.
 *
 * It is the code, not the conference id, that the Meet API filters on, so
 * this is what gets stored against the meeting.
 */
function fabo_meet_code(string $url, string $fallback = ''): string
{
    if (preg_match('~meet\.google\.com/([a-z0-9-]+)~i', $url, $mm)) {
        return $mm[1];
    }
    return $fallback;
}

/**
 * Fetches the transcript of a finished Meet call.
 *
 * This is the part that most often has nothing to return, and the reasons
 * are not faults: transcription has to be switched on during the call, and
 * the Workspace edition has to include it. The message says which.
 *
 * Returns [ok, transcriptText | reason].
 */
function fabo_google_fetch_transcript(array $meeting): array
{
    $conf = trim((string) ($meeting['conference_id'] ?? ''));
    if ($conf === '') {
        return [false, 'This meeting has no Google Meet conference attached.'];
    }

    // Find the conference record for that Meet code.
    [$ok, $res] = fabo_google_api('GET',
        'https://meet.googleapis.com/v2/conferenceRecords?filter='
        . rawurlencode('space.meeting_code="' . $conf . '"'));
    if (!$ok) {
        return [false, 'Meet said: ' . $res];
    }
    $records = $res['conferenceRecords'] ?? [];
    if (!$records) {
        return [false, 'Google has no finished call for this meeting yet. A transcript '
                     . 'appears a few minutes after everybody leaves.'];
    }
    $record = $records[0]['name'] ?? '';

    [$ok, $res] = fabo_google_api('GET',
        'https://meet.googleapis.com/v2/' . $record . '/transcripts');
    if (!$ok) {
        return [false, 'Meet said: ' . $res];
    }
    $transcripts = $res['transcripts'] ?? [];
    if (!$transcripts) {
        return [false, 'That call has no transcript. Transcription has to be switched on '
                     . 'during the meeting, and the Workspace plan has to include it.'];
    }
    $tName = $transcripts[0]['name'] ?? '';

    // Entries come a page at a time and in order; joined they read as the call.
    $lines = [];
    $page  = '';
    do {
        $url = 'https://meet.googleapis.com/v2/' . $tName . '/entries?pageSize=1000'
             . ($page !== '' ? '&pageToken=' . rawurlencode($page) : '');
        [$ok, $res] = fabo_google_api('GET', $url);
        if (!$ok) {
            return [false, 'Meet said: ' . $res];
        }
        foreach ($res['transcriptEntries'] ?? [] as $entry) {
            $who  = (string) ($entry['participant'] ?? 'Speaker');
            $who  = substr($who, strrpos($who, '/') !== false ? strrpos($who, '/') + 1 : 0);
            $text = trim((string) ($entry['text'] ?? ''));
            if ($text !== '') {
                $lines[] = $who . ': ' . $text;
            }
        }
        $page = (string) ($res['nextPageToken'] ?? '');
    } while ($page !== '' && count($lines) < 20000);

    if (!$lines) {
        return [false, 'The transcript came back empty.'];
    }
    return [true, implode("\n", $lines)];
}

/* -------------------------------------------------------------- Gemini */

function fabo_gemini_configured(): bool
{
    return trim(fabo_env_or('GEMINI_API_KEY')) !== '';
}

/**
 * Asks Gemini to turn a transcript into minutes.
 *
 * The model is a setting, because model names change faster than this
 * portal will. Returns [ok, minutesText | reason].
 */
function fabo_gemini_minutes(array $meeting, string $transcript): array
{
    $key = trim(fabo_env_or('GEMINI_API_KEY'));
    if ($key === '') {
        return [false, 'No Gemini API key is set. Add GEMINI_API_KEY to config.local.php.'];
    }
    $transcript = trim($transcript);
    if ($transcript === '') {
        return [false, 'There is no transcript to work from. Fetch one from Meet, or paste it in.'];
    }
    // Long calls run past what is sensible to send; the tail is the part
    // with the decisions in it, so that is the end that is kept.
    if (mb_strlen($transcript) > 120000) {
        $transcript = '…(earlier part of the call omitted)…' . "\n"
                    . mb_substr($transcript, -120000);
    }

    $model  = fabo_env_or('GEMINI_MODEL', 'gemini-flash-latest');
    $prompt = "You are writing the minutes of a meeting from its transcript.\n\n"
            . "MEETING: " . $meeting['title'] . "\n"
            . "WHEN: " . fabo_meeting_when($meeting) . "\n"
            . (trim((string) ($meeting['agenda'] ?? '')) !== ''
               ? "AGENDA: " . $meeting['agenda'] . "\n" : '')
            . "\nWrite the minutes in plain text under these headings, and leave out any "
            . "heading you have nothing for:\n"
            . "SUMMARY - three or four sentences.\n"
            . "DECISIONS - one per line.\n"
            . "ACTIONS - one per line, each naming who owns it and any date given.\n"
            . "OPEN QUESTIONS - one per line.\n\n"
            . "Write only what the transcript supports. Do not invent owners, dates or "
            . "decisions. If the transcript is too thin to minute, say so in one line.\n\n"
            . "TRANSCRIPT\n" . $transcript;

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
         . rawurlencode($model) . ':generateContent?key=' . rawurlencode($key);

    $payload = [
        'contents' => [['parts' => [['text' => $prompt]]]],
        'generationConfig' => ['temperature' => 0.2, 'maxOutputTokens' => 2048],
    ];

    /*
     * Gemini answers "this model is experiencing high demand" often enough
     * on a free-tier key that a single attempt is not good enough for a
     * button somebody presses once and walks away from. Three tries with a
     * growing pause clears it in practice. Anything that is not a busy
     * signal — a bad key, a retired model — comes straight back, because
     * retrying those only wastes the caller's time.
     */
    $busy = [429, 500, 502, 503, 504];
    $ok = false;
    $res = 'not attempted';
    for ($try = 1; $try <= 3; $try++) {
        [$ok, $res, $status] = fabo_http('POST', $url, $payload,
                                         ['Content-Type: application/json'], 90);
        if ($ok || !in_array($status, $busy, true)) {
            break;
        }
        if ($try < 3) {
            sleep($try * 3);          // 3s, then 6s
        }
    }

    if (!$ok) {
        return [false, 'Gemini said: ' . $res
                     . (in_array($status ?? 0, $busy, true)
                        ? ' (tried three times). Wait a minute and press it again, '
                          . 'or write the minutes by hand below.' : '')];
    }
    $text = '';
    foreach ($res['candidates'][0]['content']['parts'] ?? [] as $part) {
        $text .= (string) ($part['text'] ?? '');
    }
    $text = trim($text);
    if ($text === '') {
        $why = $res['candidates'][0]['finishReason'] ?? 'no reason given';
        return [false, 'Gemini returned nothing (' . $why . ').'];
    }
    return [true, $text];
}

/**
 * Asks Gemini to write up an event from its rows.
 *
 * Deliberately given the figures rather than asked to compute them: a model
 * asked to add up a column will sometimes get it wrong and always sound
 * certain. The arithmetic is done in PHP and handed over as fact; Gemini's
 * job is the prose around it.
 *
 * Returns [ok, reportText | reason].
 */
function fabo_gemini_event_report(array $event, array $rows, array $facts, int $images): array
{
    $key = trim(fabo_env_or('GEMINI_API_KEY'));
    if ($key === '') {
        return [false, 'No Gemini API key is set. Add GEMINI_API_KEY to config.local.php.'];
    }
    if (!$rows) {
        return [false, 'There are no rows on this event to write about yet.'];
    }

    $model = fabo_env_or('GEMINI_MODEL', 'gemini-flash-latest');

    // The rows, as a small table. Only what is filled in: blank columns say
    // nothing and spend the budget that the filled ones need.
    $lines = [];
    foreach ($rows as $i => $r) {
        $bits = [];
        foreach ($r as $label => $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                $bits[] = $label . ': ' . $value;
            }
        }
        $lines[] = ($i + 1) . '. ' . implode(' | ', $bits);
    }
    $table = implode("\n", $lines);
    if (mb_strlen($table) > 90000) {
        $table = mb_substr($table, 0, 90000) . "\n…(further rows omitted)…";
    }

    $factLines = [];
    foreach ($facts as $label => $value) {
        $factLines[] = $label . ': ' . $value;
    }

    $prompt = "You are writing the report on a marketing event for the people who "
            . "paid for it.\n\n"
            . "EVENT: " . (string) ($event['event_name'] ?? 'Untitled') . "\n"
            . "WHEN: " . (string) ($event['when'] ?? 'not recorded') . "\n"
            . "WHERE: " . (string) ($event['where'] ?? 'not recorded') . "\n"
            . "ROWS RECORDED: " . count($rows) . "\n"
            . "PHOTOGRAPHS ATTACHED: " . $images . "\n\n"
            . "FIGURES ALREADY ADDED UP FOR YOU — use these exactly, do not "
            . "recalculate them and do not contradict them:\n"
            . implode("\n", $factLines) . "\n\n"
            . "THE ROWS\n" . $table . "\n\n"
            . "Write the report in plain text under these headings, leaving out any "
            . "heading you have nothing real to say under:\n"
            . "OVERVIEW - three or four sentences on what the event was and how it went.\n"
            . "WHAT WAS DELIVERED - one line per distinct activity in the rows.\n"
            . "AGAINST PLAN - where achieved figures beat, met or fell short of "
            . "expected ones, naming the numbers. Say plainly if results are not in yet.\n"
            . "PARTICIPATION AND REACH - attendance, leads, partners.\n"
            . "SPEND - budget allocated against spent, if both are known.\n"
            . "OBSERVATIONS - what the data suggests, at most four lines.\n\n"
            . "Rules you must follow:\n"
            . "- Use only what is above. Invent nothing: no names, dates, outcomes or "
            . "figures that are not there.\n"
            . "- Where something is missing, say it is not recorded rather than guessing.\n"
            . "- Do not praise or sell. Report.\n"
            . "- No markdown, no asterisks, no bullet characters. Plain lines.\n";

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
         . rawurlencode($model) . ':generateContent?key=' . rawurlencode($key);

    $payload = [
        'contents' => [['parts' => [['text' => $prompt]]]],
        'generationConfig' => ['temperature' => 0.2, 'maxOutputTokens' => 2048],
    ];

    // The same busy-signal handling the minutes use, and for the same reason.
    $busy = [429, 500, 502, 503, 504];
    $ok = false; $res = 'not attempted'; $status = 0;
    for ($try = 1; $try <= 3; $try++) {
        [$ok, $res, $status] = fabo_http('POST', $url, $payload,
                                         ['Content-Type: application/json'], 90);
        if ($ok || !in_array($status, $busy, true)) {
            break;
        }
        if ($try < 3) {
            sleep($try * 3);
        }
    }
    if (!$ok) {
        return [false, 'Gemini said: ' . (is_string($res) ? $res : json_encode($res))
                     . (in_array($status, $busy, true)
                        ? ' (tried three times). Wait a minute and press it again.' : '')];
    }
    $text = '';
    foreach ($res['candidates'][0]['content']['parts'] ?? [] as $part) {
        $text .= (string) ($part['text'] ?? '');
    }
    $text = trim($text);
    if ($text === '') {
        return [false, 'Gemini returned nothing ('
                     . ($res['candidates'][0]['finishReason'] ?? 'no reason given') . ').'];
    }
    return [true, $text];
}

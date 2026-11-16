<?php
/**
 * Event Organizers — the events themselves, around the main pipeline.
 *
 * Forms, applications, stages and the desk are the organisation's main ones
 * (lib/inc): an event application sits on the one board, with its program name
 * and type of participant. Served as Event Organizers (inc_kind() 'eo') a page
 * sees event applications only. This file is the events, tickets and check-in;
 * lib/eo/commerce.php is money, seats and plans; lib/eo/engage.php everything
 * around the day.
 *
 * An event is never made here. It arrives when its committee approves it on
 * the Events sheet, and if that approval goes away it stops being public.
 *
 * WHO. Owner (the grant) runs everything; View only reads. A member of the
 * Organizers team (a department of the main pipeline) works events and check-in
 * without the grant. People with one job and no account get a link (event-staff.php).
 */

require_once __DIR__ . '/../inc/app.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/qr.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/commerce.php';
require_once __DIR__ . '/engage.php';

/** What is served here: the application pages, and the event pages in place of the rest. */
function eo_pages(array $shared): array
{
    $keep = array_intersect_key($shared, array_flip(['applications', 'application', 'quick', 'new', 'form', 'desk', 'file']));
    return $keep + ['dashboard' => ['Event Organizers', 'eo:dashboard', 'desk'], 'events' => ['Approved events', 'eo:events', 'desk'],
                    'event' => ['Event', 'eo:event', 'desk'], 'checkin' => ['Check-in', 'eo:checkin', 'desk'],
                    'kiosk' => ['Kiosk', 'eo:kiosk', 'desk'], 'badges' => ['Badges', 'eo:badges', 'desk'],
                    'orders' => ['Orders & revenue', 'eo:orders', 'desk'], 'analytics' => ['Analytics', 'eo:analytics', 'desk'],
                    'mentors' => ['Mentors', 'eo:mentors', 'desk'], 'sponsors' => ['Sponsors', 'eo:sponsors', 'desk'],
                    'team' => ['Organizers team', 'eo:team', 'desk'], 'inbox' => ['Messages & feedback', 'eo:inbox', 'desk'],
                    'settings' => ['Organizer settings', 'eo:settings', 'configure'], 'developer' => ['Developer', 'eo:developer', 'configure'],
                    'plans' => ['Plans', 'eo:plans', 'configure']];
}

/**
 * The fence around the main pipeline. Every read through the shared pages is
 * already narrowed (inc_app_where, inc_app, inc_forms_list); this covers what a
 * crafted request could still name by id: bulk actions, a card move, a form, a file.
 */
function eo_scope(string $page): void
{
    if ($page === 'form' && !inc_one('SELECT 1 AS x FROM inc_ev_forms WHERE form_id = ?', 'i', [(int) ($_GET['id'] ?? 0)])) {
        fabo_deny('That form is not an event form.', 403);
    }
    if ($page === 'file') {
        $f = inc_one('SELECT entity, entity_id FROM inc_files WHERE id = ?', 'i', [(int) ($_GET['id'] ?? 0)]);
        if (!$f || $f['entity'] !== 'application' || !inc_app((int) $f['entity_id'])) {
            fabo_deny('That file is not from an event application.', 403);
        }
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    if (isset($_POST['ids'])) {
        $ids = array_map('intval', (array) $_POST['ids']);
        $_POST['ids'] = array_values(array_filter($ids, static fn($id) => (bool) inc_app($id)));
    }
    if (($_POST['action'] ?? '') === 'board_move' && !inc_app((int) ($_POST['id'] ?? 0))) {
        fabo_deny('That application is not an event application.', 403);
    }
}

/** Everything a page of the module needs first: its tables, its wording, the sync. */
function eo_begin(): void
{
    eo_install();
    ob_start(static function (string $buffer): string {
        foreach (headers_list() as $h) {
            if (stripos($h, 'content-type:') === 0 && stripos($h, 'text/html') === false) {
                return $buffer;
            }
        }
        // The page itself, not the menu before it: Incubation's entry there keeps its name.
        $at = strpos($buffer, '<main');
        return $at === false ? inc_words($buffer) : substr($buffer, 0, $at) . inc_words(substr($buffer, $at));
    });
    try {
        if ((int) inc_setting('_eo_synced_at') < time() - 60) {
            eo_sync();
        }
        eo_tick();
    } catch (Throwable $e) {
        error_log('FABO organizers sync: ' . $e->getMessage());
    }
}

/** Is the viewer on the Organizers team itself? */
function eo_on_team(): bool
{
    $c = inc_ctx()['viewer']['client'] ?? null;
    if (!$c || empty($c['id'])) {
        return false;
    }
    return (bool) inc_one('SELECT 1 AS x FROM inc_team_members m JOIN inc_teams t ON t.id = m.team_id AND t.active = 1
                            WHERE m.person_id = ? AND m.team_id = ? LIMIT 1', 'ii', [(int) $c['id'], eo_team_id()]);
}

/** May read events (attendees included): the grant, or the Organizers team. */
function eo_may_read(): bool
{
    return inc_can('view') || eo_on_team();
}

/** May work events: tickets and check-in. Owner, or the Organizers team. */
function eo_may_work(): bool
{
    return inc_can('edit') || eo_on_team();
}

function eo_need_read(): void
{
    if (!eo_may_read()) {
        fabo_deny('This is for the Organizers team.', 403);
    }
}

function eo_need_work(): void
{
    if (!eo_may_work()) {
        if (PHP_SAPI === 'cli') {
            throw new RuntimeException('Not permitted: work events');
        }
        fabo_deny('You can read this event, not change it.', 403);
    }
}

/* ================================================================== sync */

/** The Events sheet's approved events for the organisation in use. */
function eo_source_rows(): array
{
    require_once __DIR__ . '/../departments.php';
    $org = inc_org();
    $where = $org > 0 ? 'e.client_id = ?' : 'e.client_id IS NULL';
    if (fabo_dept_has_submitted_column()) {
        $where .= ' AND (e.department_id IS NULL OR e.dept_submitted_at IS NOT NULL)';
    }
    /* The committee's decision is in approvals. An event with no decision there
       can still be approved by its Sign-off: a completed one is (fabo_signoff_on_complete). */
    return fabo_all("SELECT e.id, e.event_name, e.event_type, e.start_date, e.end_date, e.location
                       FROM `events` e
                       LEFT JOIN approvals a ON a.sheet_key = 'events' AND a.row_id = e.id
                      WHERE " . $where . " AND TRIM(COALESCE(e.event_name, '')) <> ''
                        AND (a.state = 'approved' OR (COALESCE(a.state, 'pending') = 'pending' AND LOWER(TRIM(COALESCE(e.approval_status, ''))) = 'approved'))",
                    $org > 0 ? 'i' : '', $org > 0 ? [$org] : []);
}

/**
 * Brings the list in step with the sheet: new approvals arrive, changes to the
 * name, dates or place follow, and an event no longer approved is taken off
 * its public page with everything the organizers did kept. Returns
 * ['added' => n, 'changed' => n, 'withdrawn' => n].
 */
function eo_sync(): array
{
    $out = ['added' => 0, 'changed' => 0, 'withdrawn' => 0];
    inc_setting_set('_eo_synced_at', (string) time());
    $have = [];
    foreach (inc_all('SELECT * FROM inc_ev_events') as $r) {
        $have[(int) $r['source_id']] = $r;
    }
    $seen = [];
    foreach (eo_source_rows() as $s) {
        $id = (int) $s['id'];
        $seen[$id] = true;
        $name = mb_substr(trim((string) $s['event_name']), 0, 190);
        $vals = [$name, $s['event_type'] ? mb_substr((string) $s['event_type'], 0, 120) : null, $s['start_date'] ?: null, $s['end_date'] ?: null,
                 $s['location'] ? mb_substr((string) $s['location'], 0, 255) : null];
        $row = $have[$id] ?? null;
        if (!$row) {
            inc_exec('INSERT IGNORE INTO inc_ev_events (source_id, token, name, label, event_type, start_date, end_date, location) VALUES (?,?,?,?,?,?,?,?)',
                     'isssssss', [$id, bin2hex(random_bytes(12)), $name, $name, $vals[1], $vals[2], $vals[3], $vals[4]]);
            $out['added']++;
            continue;
        }
        if ([$row['name'], $row['event_type'], $row['start_date'], $row['end_date'], $row['location']] != $vals || (int) $row['approved'] !== 1) {
            inc_exec('UPDATE inc_ev_events SET name = ?, event_type = ?, start_date = ?, end_date = ?, location = ?, approved = 1 WHERE id = ?',
                     'sssssi', array_merge($vals, [(int) $row['id']]));
            $out['changed']++;
        }
    }
    foreach ($have as $src => $row) {
        if (!isset($seen[$src]) && (int) $row['approved'] === 1) {
            inc_exec('UPDATE inc_ev_events SET approved = 0, published = 0 WHERE id = ?', 'i', [(int) $row['id']]);
            $out['withdrawn']++;
        }
    }
    eo_labels_refresh();
    return $out;
}

/**
 * The name applications are filed under (inc_applications.event_name, which
 * the board filters on and shows). Two events may share a name, a yearly one
 * for instance, so those get their date too and never merge on the board.
 */
function eo_labels_refresh(): void
{
    $names = [];
    $rows = inc_all('SELECT id, name, label, start_date FROM inc_ev_events');
    foreach ($rows as $r) {
        $k = mb_strtolower((string) $r['name']);
        $names[$k] = ($names[$k] ?? 0) + 1;
    }
    foreach ($rows as $r) {
        $label = (string) $r['name'];
        if ($names[mb_strtolower($label)] > 1) {
            $label = mb_substr($label, 0, 160) . ' · ' . ($r['start_date'] ? inc_fmt_date($r['start_date']) : 'no date') . ' #' . (int) $r['id'];
        }
        if ($label !== (string) $r['label']) {
            inc_exec('UPDATE inc_ev_events SET label = ? WHERE id = ?', 'si', [$label, (int) $r['id']]);
            inc_exec('UPDATE inc_forms f JOIN inc_ev_forms x ON x.form_id = f.id SET f.event_name = ? WHERE x.event_id = ?', 'si', [$label, (int) $r['id']]);
            inc_exec('UPDATE inc_applications a JOIN inc_ev_forms x ON x.form_id = a.form_id SET a.event_name = ? WHERE x.event_id = ?', 'si', [$label, (int) $r['id']]);
        }
    }
}

/* ================================================================ events */

function eo_event(int $id): ?array
{
    return inc_one('SELECT * FROM inc_ev_events WHERE id = ?', 'i', [$id]);
}

function eo_event_by_token(string $token): ?array
{
    return preg_match('/^[a-f0-9]{24}$/', $token) ? inc_one('SELECT * FROM inc_ev_events WHERE token = ?', 's', [$token]) : null;
}

/** How many days it runs, for check-in and the agenda. Never less than one. */
function eo_days(array $ev): int
{
    if (empty($ev['start_date']) || empty($ev['end_date'])) {
        return 1;
    }
    return max(1, min(60, (int) ((strtotime($ev['end_date']) - strtotime($ev['start_date'])) / 86400) + 1));
}

/** Which day of the event today is, held within the event's days. */
function eo_today_day(array $ev): int
{
    if (empty($ev['start_date'])) {
        return 1;
    }
    return max(1, min(eo_days($ev), (int) ((strtotime(date('Y-m-d')) - strtotime($ev['start_date'])) / 86400) + 1));
}

function eo_dates_text(array $ev): string
{
    if (empty($ev['start_date'])) {
        return 'Dates to be confirmed';
    }
    $a = inc_fmt_date($ev['start_date']);
    return empty($ev['end_date']) || $ev['end_date'] === $ev['start_date'] ? $a : $a . ' – ' . inc_fmt_date($ev['end_date']);
}

/** Upcoming, past or all, with the figures for each row. Returns [rows, total]. */
function eo_events_list(string $when, string $q, int $page, int $per = 25): array
{
    $w = ['1=1'];
    $t = '';
    $p = [];
    $today = date('Y-m-d');
    if ($when === 'upcoming') {
        $w[] = '(COALESCE(e.end_date, e.start_date) >= ? OR COALESCE(e.end_date, e.start_date) IS NULL) AND e.approved = 1';
        $t .= 's';
        $p[] = $today;
    } elseif ($when === 'past') {
        $w[] = 'COALESCE(e.end_date, e.start_date) < ? AND e.approved = 1';
        $t .= 's';
        $p[] = $today;
    } elseif ($when === 'withdrawn') {
        $w[] = 'e.approved = 0';
    }
    if ($q !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $w[] = '(e.name LIKE ? OR e.location LIKE ? OR e.event_type LIKE ?)';
        $t .= 'sss';
        array_push($p, $like, $like, $like);
    }
    $where = implode(' AND ', $w);
    $total = (int) (inc_one('SELECT COUNT(*) AS n FROM inc_ev_events e WHERE ' . $where, $t, $p)['n'] ?? 0);
    $order = $when === 'past' ? 'e.start_date DESC, e.id DESC' : 'e.start_date IS NULL, e.start_date, e.id';
    $rows = inc_all('SELECT e.*,
                            (SELECT COUNT(*) FROM inc_ev_forms x WHERE x.event_id = e.id) AS forms,
                            (SELECT COUNT(*) FROM inc_applications a JOIN inc_ev_forms x ON x.form_id = a.form_id WHERE x.event_id = e.id) AS apps,
                            (SELECT COUNT(*) FROM inc_ev_tickets k WHERE k.event_id = e.id AND k.status = "issued") AS tickets
                       FROM inc_ev_events e WHERE ' . $where . ' ORDER BY ' . $order . ' LIMIT ' . (int) $per . ' OFFSET ' . (int) (($page - 1) * $per), $t, $p);
    return [$rows, $total];
}

function eo_event_save(int $id, array $in): void
{
    eo_need_work();
    $ev = eo_event($id);
    if (!$ev) {
        throw new InvalidArgumentException('That event no longer exists.');
    }
    $mode = (string) ($in['mode'] ?? 'in_person');
    if (!in_array($mode, ['in_person', 'online', 'hybrid'], true)) {
        $mode = 'in_person';
    }
    $link = static function ($v, string $what): ?string {
        $v = inc_str($v ?? '', 500);
        if ($v !== '' && !preg_match('~^https?://~i', $v)) {
            throw new InvalidArgumentException($what . ' must start with https://.');
        }
        return $v !== '' ? $v : null;
    };
    $cap = trim((string) ($in['capacity'] ?? ''));
    if ($cap !== '' && (!ctype_digit($cap) || (int) $cap < 1)) {
        throw new InvalidArgumentException('Capacity is a whole number of seats, or empty for no limit.');
    }
    if ($cap !== '') {
        eo_plan_capacity_check((int) $cap);
    }
    $deadline = trim((string) ($in['reg_deadline'] ?? ''));
    if ($deadline !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline)) {
        throw new InvalidArgumentException('Choose a date for the registration deadline.');
    }
    $time = trim((string) ($in['start_time'] ?? ''));
    if ($time !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
        throw new InvalidArgumentException('The start time is hours and minutes, like 09:30.');
    }
    $paid = !empty($in['is_paid']) ? 1 : 0;
    $price = round((float) ($in['price'] ?? 0), 2);
    if ($paid && $price <= 0) {
        throw new InvalidArgumentException('A paid event needs a ticket price.');
    }
    $currency = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) ($in['currency'] ?? 'INR')));
    $flag = static fn(string $k): int => !empty($in[$k]) ? 1 : 0;
    inc_exec('UPDATE inc_ev_events SET summary = ?, description = ?, venue = ?, city = ?, mode = ?, meeting_url = ?, capacity = ?, reg_deadline = ?, start_time = ?,
                     is_paid = ?, price = ?, currency = ?, category_id = ?, tags = ?, amenities = ?, video_url = ?,
                     approval_required = ?, waitlist_enabled = ?, reminders_enabled = ?, is_featured = ?, food_provided = ?, accommodation_provided = ? WHERE id = ?',
             'ssssssissidsisssiiiiiii',
             [inc_str($in['summary'] ?? '', 300), inc_str($in['description'] ?? '', 10000), inc_str($in['venue'] ?? '', 255), inc_str($in['city'] ?? '', 120), $mode,
              $link($in['meeting_url'] ?? '', 'The meeting link'), $cap !== '' ? (int) $cap : null, $deadline !== '' ? $deadline : null, $time !== '' ? $time : null,
              $paid, $paid ? $price : 0, strlen($currency) === 3 ? $currency : 'INR', inc_int_or_null($in['category_id'] ?? null), inc_str($in['tags'] ?? '', 255), inc_str($in['amenities'] ?? '', 500),
              $link($in['video_url'] ?? '', 'The video link'), $flag('approval_required'), $flag('waitlist_enabled'), $flag('reminders_enabled'), $flag('is_featured'),
              $flag('food_provided'), $flag('accommodation_provided'), $id]);
    inc_audit('update', 'event', $id, (string) $ev['name']);
}

function eo_event_publish(int $id, bool $on): void
{
    eo_need_work();
    $ev = eo_event($id);
    if (!$ev) {
        throw new InvalidArgumentException('That event no longer exists.');
    }
    if ($on && ((int) $ev['approved'] !== 1 || !empty($ev['cancelled_at']))) {
        throw new InvalidArgumentException('This event is not approved or was cancelled, so it cannot go public.');
    }
    inc_exec('UPDATE inc_ev_events SET published = ? WHERE id = ?', 'ii', [$on ? 1 : 0, $id]);
    inc_audit($on ? 'publish' : 'unpublish', 'event', $id, (string) $ev['name']);
}

/** Replaces the banner with an uploaded picture. */
function eo_banner_save(int $id, array $file): void
{
    eo_need_work();
    $ev = eo_event($id);
    if (!$ev) {
        throw new InvalidArgumentException('That event no longer exists.');
    }
    require_once __DIR__ . '/../uploads.php';
    [$ok, $msg, $meta] = fabo_store_upload($file);
    if (!$ok) {
        throw new InvalidArgumentException($msg);
    }
    if (!fabo_is_inline_image((string) $meta['mime'])) {
        fabo_delete_stored((string) $meta['stored_name']);
        throw new InvalidArgumentException('The banner must be a JPG, PNG or WebP picture.');
    }
    if (!empty($ev['banner'])) {
        fabo_delete_stored((string) $ev['banner']);
    }
    inc_exec('UPDATE inc_ev_events SET banner = ?, banner_mime = ? WHERE id = ?', 'ssi', [$meta['stored_name'], $meta['mime'], $id]);
}

/** A public address on this organisation's site. */
function eo_site_url(string $path): string
{
    return rtrim(fabo_portal_url_safe(), '/') . '/' . $path . (inc_org() > 0 ? (str_contains($path, '?') ? '&' : '?') . 'o=' . inc_org() : '');
}

/** The public page of an event, and its address for sharing. */
function eo_public_url(array $ev): string
{
    return eo_site_url('event.php?e=' . $ev['token']);
}

/* ================================================================= forms */

function eo_event_forms(int $eventId): array
{
    return inc_all('SELECT f.*, (SELECT COUNT(*) FROM inc_applications a WHERE a.form_id = f.id) AS apps,
                           (SELECT MAX(v.version_no) FROM inc_form_versions v WHERE v.form_id = f.id AND v.status = "published") AS published_no
                      FROM inc_forms f WHERE f.id IN (SELECT form_id FROM inc_ev_forms WHERE event_id = ?) AND f.archived = 0 ORDER BY f.id', 'i', [$eventId]);
}

/**
 * A new application form for the event. It starts from the event purpose's
 * questions, without the ones the event already answers, files every
 * application under the event on the main pipeline, fills the type of
 * participant from its own question, and reaches the Organizers desk.
 */
function eo_form_create(int $eventId, string $name): int
{
    $ev = eo_event($eventId);
    if (!$ev) {
        throw new InvalidArgumentException('That event no longer exists.');
    }
    $fid = inc_form_create(['name' => $name !== '' ? $name : $ev['name'] . ' — application', 'purpose' => 'event',
                            'event_name' => $ev['label'], 'dept_team_id' => eo_team_id()]);
    $version = inc_one('SELECT id FROM inc_form_versions WHERE form_id = ? ORDER BY id DESC LIMIT 1', 'i', [$fid]);
    inc_exec('DELETE FROM inc_form_fields WHERE version_id = ? AND uid IN ("sec_event","event_name","spoke")', 'i', [(int) $version['id']]);
    inc_exec('UPDATE inc_form_fields SET maps_to = "applicant_type" WHERE version_id = ? AND uid = "participation"', 'i', [(int) $version['id']]);
    inc_exec('INSERT INTO inc_ev_forms (event_id, form_id) VALUES (?,?)', 'ii', [$eventId, $fid]);
    return $fid;
}

/** The event a form belongs to, by the form's public token. */
function eo_event_of_form_token(string $token): ?array
{
    return inc_one('SELECT e.* FROM inc_ev_events e JOIN inc_ev_forms x ON x.event_id = e.id JOIN inc_forms f ON f.id = x.form_id
                     WHERE f.public_token = ?', 's', [$token]);
}

/** Why applications are not being taken, or '' when they are. */
function eo_closed_reason(array $ev): string
{
    if ((int) $ev['approved'] !== 1) {
        return 'This event is not open for applications.';
    }
    if (!empty($ev['cancelled_at'])) {
        return 'This event has been cancelled.';
    }
    if (!empty($ev['reg_deadline']) && $ev['reg_deadline'] < date('Y-m-d')) {
        return 'Registration for this event has closed.';
    }
    $last = $ev['end_date'] ?: $ev['start_date'];
    if ($last && $last < date('Y-m-d')) {
        return 'This event has ended.';
    }
    if (eo_is_full($ev)) {
        return 'This event is full.';
    }
    return '';
}

/* =============================================================== tickets */

function eo_seats_taken(int $eventId): int
{
    return (int) (inc_one('SELECT COUNT(*) AS n FROM inc_ev_tickets WHERE event_id = ? AND status IN ("issued","reserved")', 'i', [$eventId])['n'] ?? 0);
}

function eo_is_full(array $ev): bool
{
    return !empty($ev['capacity']) && eo_seats_taken((int) $ev['id']) >= (int) $ev['capacity'];
}

/** Everyone who applied to the event, with their stage, registration, ticket and check-ins. */
function eo_attendees(int $eventId, array $f = []): array
{
    $w = 'x.event_id = ? AND COALESCE(a.dup_status, "") <> "confirmed"';
    $t = 'i';
    $p = [$eventId];
    if (!empty($f['stage_id'])) {
        $w .= ' AND a.stage_id = ?';
        $t .= 'i';
        $p[] = (int) $f['stage_id'];
    }
    if (($f['q'] ?? '') !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $f['q']) . '%';
        $w .= ' AND (a.applicant_name LIKE ? OR a.applicant_email LIKE ? OR a.applicant_phone LIKE ? OR a.ref LIKE ?)';
        $t .= 'ssss';
        array_push($p, $like, $like, $like, $like);
    }
    return inc_all('SELECT a.id, a.ref, a.applicant_name, a.applicant_email, a.applicant_phone, a.startup_name, a.stage_id, s.name AS stage_name,
                           k.id AS ticket_id, k.code, k.status AS ticket_status, k.emailed_at, g.status AS reg_status, g.flagged, g.flag_reason,
                           (SELECT m.name FROM inc_masters m WHERE m.id = a.applicant_type_id) AS type_name,
                           (SELECT COUNT(*) FROM inc_ev_checkins c WHERE c.ticket_id = k.id) AS days_in
                      FROM inc_applications a
                      JOIN inc_ev_forms x ON x.form_id = a.form_id
                      LEFT JOIN inc_stages s ON s.id = a.stage_id
                      LEFT JOIN inc_ev_tickets k ON k.application_id = a.id AND k.event_id = x.event_id
                      LEFT JOIN inc_ev_regs g ON g.application_id = a.id
                     WHERE ' . $w . ' ORDER BY a.applicant_name, a.id LIMIT 5000', $t, $p);
}

function eo_ticket_url(string $code): string
{
    return eo_site_url('event-ticket.php?t=' . $code);
}

/**
 * Tickets for these applications, as far as the seats go. An application that
 * is not this event's is skipped whatever was posted; one already holding a
 * ticket keeps it. Returns ['issued' => n, 'full' => n, 'mailed' => n].
 */
function eo_tickets_issue(int $eventId, array $appIds, bool $mail = true): array
{
    eo_need_work();
    $ev = eo_event($eventId);
    if (!$ev) {
        throw new InvalidArgumentException('That event no longer exists.');
    }
    if ((int) $ev['approved'] !== 1 || !empty($ev['cancelled_at'])) {
        throw new InvalidArgumentException('This event is no longer approved, so no tickets can be issued.');
    }
    $out = ['issued' => 0, 'full' => 0, 'mailed' => 0];
    foreach (array_unique(array_map('intval', $appIds)) as $appId) {
        $r = eo_ticket_grant($ev, $appId, 'issued', $mail);
        if ($r !== null) {
            $out[$r]++;
            $out['mailed'] += $r === 'issued' && !empty($GLOBALS['eo_last_mailed']) ? 1 : 0;
        }
    }
    return $out;
}

/**
 * One seat for one application: 'issued', 'full', or null when there is nothing
 * to do. $status 'reserved' holds the seat for a payment still to come.
 */
function eo_ticket_grant(array $ev, int $appId, string $status = 'issued', bool $mail = true): ?string
{
    $eventId = (int) $ev['id'];
    $GLOBALS['eo_last_mailed'] = false;
    $app = inc_one('SELECT a.id, a.applicant_name, a.applicant_email, a.applicant_phone FROM inc_applications a JOIN inc_ev_forms x ON x.form_id = a.form_id
                     WHERE a.id = ? AND x.event_id = ?', 'ii', [$appId, $eventId]);
    if (!$app) {
        return null;
    }
    $result = null;
    $code = inc_tx(static function () use ($eventId, $appId, $status, &$result): ?string {
        // Counted under a lock on the event, so two people at once cannot oversell it.
        $locked = inc_one('SELECT * FROM inc_ev_events WHERE id = ? FOR UPDATE', 'i', [$eventId]);
        $has = inc_one('SELECT id, status, code FROM inc_ev_tickets WHERE event_id = ? AND application_id = ?', 'ii', [$eventId, $appId]);
        if ($has && $has['status'] === 'issued') {
            return null;
        }
        if ($has && $has['status'] === 'reserved') {
            if ($status !== 'issued') {
                return null;
            }
            // The seat is already held: confirming it takes no new one.
            inc_exec('UPDATE inc_ev_tickets SET status = "issued", issued_by = ? WHERE id = ?', 'si', [inc_actor(), (int) $has['id']]);
            $result = 'issued';
            return (string) $has['code'];
        }
        if (eo_is_full($locked)) {
            $result = 'full';
            return null;
        }
        $code = bin2hex(random_bytes(12));
        if ($has) {
            // A cancelled ticket comes back under a new code: the old one stays dead.
            inc_exec('UPDATE inc_ev_tickets SET status = ?, code = ?, issued_by = ?, emailed_at = NULL WHERE id = ?', 'sssi', [$status, $code, inc_actor(), (int) $has['id']]);
        } else {
            inc_exec('INSERT INTO inc_ev_tickets (event_id, application_id, code, status, issued_by) VALUES (?,?,?,?,?)', 'iisss', [$eventId, $appId, $code, $status, inc_actor()]);
        }
        $result = 'issued';
        return $code;
    });
    if ($code === null || $status !== 'issued') {
        return $result;
    }
    eo_reg_status($eventId, $appId, 'approved');
    inc_activity($appId, null, 'ticket', 'Ticket issued for ' . $ev['name']);
    if ($mail) {
        $GLOBALS['eo_last_mailed'] = eo_ticket_mail($ev, $app, $code);
    }
    return $result;
}

function eo_ticket_mail(array $ev, array $app, string $code): bool
{
    $url = eo_ticket_url($code);
    $where = trim((string) ($ev['venue'] ?: $ev['location']));
    eo_message_queue($ev, (string) ($app['applicant_phone'] ?? ''), 'ticket', 'Your ticket for ' . $ev['name'] . ': ' . $url);
    eo_notice((string) $app['applicant_email'], 'Your ticket is ready', (string) $ev['name'], $url);
    $sent = eo_mail((string) $app['applicant_email'], 'Your ticket: ' . $ev['name'], (string) $app['applicant_name'],
                    ['Your place at ' . $ev['name'] . ' is confirmed.', 'When: ' . eo_dates_text($ev) . ($where !== '' ? ' · Where: ' . $where : '')],
                    $url, 'Open my ticket', 'Show its QR code at the entrance.');
    if ($sent) {
        inc_exec('UPDATE inc_ev_tickets SET emailed_at = NOW() WHERE code = ?', 's', [$code]);
    }
    return $sent;
}

function eo_ticket_cancel(int $eventId, int $ticketId): void
{
    eo_need_work();
    $k = inc_one('SELECT * FROM inc_ev_tickets WHERE id = ? AND event_id = ?', 'ii', [$ticketId, $eventId]);
    if (!$k || $k['status'] === 'cancelled') {
        throw new InvalidArgumentException('That ticket was already cancelled.');
    }
    eo_ticket_release($eventId, $k);
}

/** Frees a ticket's seat and offers it to the waiting list. */
function eo_ticket_release(int $eventId, array $k): void
{
    inc_exec('UPDATE inc_ev_tickets SET status = "cancelled" WHERE id = ?', 'i', [(int) $k['id']]);
    eo_reg_status($eventId, (int) $k['application_id'], 'cancelled');
    eo_waitlist_promote($eventId);
}

/** A ticket with its event and holder, by the code on it. */
function eo_ticket(string $code): ?array
{
    if (!preg_match('/^[a-f0-9]{24}$/', $code)) {
        return null;
    }
    return inc_one('SELECT k.*, a.applicant_name, a.applicant_email, a.ref FROM inc_ev_tickets k
                      JOIN inc_applications a ON a.id = k.application_id WHERE k.code = ?', 's', [$code]);
}

/** The code inside whatever a scanner read: the ticket's link, or the code typed by hand. */
function eo_code_from(string $scan): string
{
    return preg_match('/\b([a-f0-9]{24})\b/i', $scan, $m) ? strtolower($m[1]) : '';
}

/**
 * Checks a ticket in for one day of the event. Answers with what the person at
 * the door needs: ['state' => ok|again|cancelled|unpaid|other_event|unknown,
 * 'name' => ..., 'at' => when, for a repeat]. $scanId, from a scan made offline,
 * makes replaying the same scan harmless; $at is when it really happened.
 */
function eo_checkin(int $eventId, string $scan, int $day, ?string $scanId = null, ?string $at = null): array
{
    if (empty($GLOBALS['eo_staff'])) {
        eo_need_work();
    }
    $ev = eo_event($eventId);
    if (!$ev) {
        throw new InvalidArgumentException('That event no longer exists.');
    }
    $k = eo_ticket(eo_code_from($scan));
    if (!$k) {
        return ['state' => 'unknown', 'name' => '', 'at' => ''];
    }
    $name = (string) $k['applicant_name'];
    if ((int) $k['event_id'] !== $eventId) {
        return ['state' => 'other_event', 'name' => $name, 'at' => ''];
    }
    if ($k['status'] === 'reserved') {
        return ['state' => 'unpaid', 'name' => $name, 'at' => ''];
    }
    if ($k['status'] !== 'issued') {
        return ['state' => 'cancelled', 'name' => $name, 'at' => ''];
    }
    if ($scanId !== null && inc_one('SELECT 1 AS x FROM inc_ev_checkins WHERE client_scan_id = ?', 's', [$scanId])) {
        return ['state' => 'ok', 'name' => $name, 'at' => '', 'replay' => true];
    }
    $day = max(1, min(eo_days($ev), $day));
    $when = $at !== null && strtotime($at) ? date('Y-m-d H:i:s', strtotime($at)) : date('Y-m-d H:i:s');
    if (inc_exec('INSERT IGNORE INTO inc_ev_checkins (ticket_id, day_no, checked_at, checked_by, scanned_offline, client_scan_id) VALUES (?,?,?,?,?,?)', 'iissis',
                 [(int) $k['id'], $day, $when, inc_actor(), $scanId !== null ? 1 : 0, $scanId]) < 1) {
        $was = inc_one('SELECT checked_at FROM inc_ev_checkins WHERE ticket_id = ? AND day_no = ?', 'ii', [(int) $k['id'], $day]);
        return ['state' => 'again', 'name' => $name, 'at' => inc_fmt_datetime($was['checked_at'] ?? null)];
    }
    inc_exec('UPDATE inc_ev_tickets SET used_at = ? WHERE id = ?', 'si', [$when, (int) $k['id']]);
    eo_webhook_dispatch('ticket.checked_in', ['event_id' => $eventId, 'ticket' => $k['code'], 'day' => $day, 'name' => $name, 'email' => $k['applicant_email'], 'offline' => $scanId !== null]);
    return ['state' => 'ok', 'name' => $name, 'at' => ''];
}

/** What a scanner keeps for working offline: code => name, for tickets that can get in. */
function eo_roster(int $eventId): array
{
    $out = [];
    foreach (inc_all('SELECT k.code, a.applicant_name FROM inc_ev_tickets k JOIN inc_applications a ON a.id = k.application_id WHERE k.event_id = ? AND k.status = "issued"', 'i', [$eventId]) as $r) {
        $out[$r['code']] = $r['applicant_name'];
    }
    return $out;
}

/** Checked in per day: day => count. */
function eo_checkin_counts(int $eventId): array
{
    $out = [];
    foreach (inc_all('SELECT c.day_no, COUNT(*) AS n FROM inc_ev_checkins c JOIN inc_ev_tickets k ON k.id = c.ticket_id
                       WHERE k.event_id = ? GROUP BY c.day_no', 'i', [$eventId]) as $r) {
        $out[(int) $r['day_no']] = (int) $r['n'];
    }
    return $out;
}

/* ============================================================== dashboard */

function eo_dashboard_figures(): array
{
    $n = static fn(string $sql, string $t = '', array $p = []) => (int) (inc_one($sql, $t, $p)['n'] ?? 0);
    $today = date('Y-m-d');
    return [
        'approved'  => $n('SELECT COUNT(*) AS n FROM inc_ev_events WHERE approved = 1'),
        'upcoming'  => $n('SELECT COUNT(*) AS n FROM inc_ev_events WHERE approved = 1 AND (COALESCE(end_date, start_date) >= ? OR COALESCE(end_date, start_date) IS NULL)', 's', [$today]),
        'published' => $n('SELECT COUNT(*) AS n FROM inc_ev_events WHERE published = 1'),
        'no_form'   => $n('SELECT COUNT(*) AS n FROM inc_ev_events e WHERE e.approved = 1 AND COALESCE(e.end_date, e.start_date) >= ?
                            AND NOT EXISTS (SELECT 1 FROM inc_ev_forms x WHERE x.event_id = e.id)', 's', [$today]),
        'apps'      => $n('SELECT COUNT(*) AS n FROM inc_applications a WHERE a.form_id IN (SELECT form_id FROM inc_ev_forms)'),
        'tickets'   => $n('SELECT COUNT(*) AS n FROM inc_ev_tickets WHERE status = "issued"'),
        'checked'   => $n('SELECT COUNT(DISTINCT c.ticket_id) AS n FROM inc_ev_checkins c'),
    ];
}

/** Event applications at each stage of the main pipeline, in its order. name => count. */
function eo_stage_counts(): array
{
    $out = [];
    foreach (inc_all('SELECT s.name, COUNT(a.id) AS n FROM inc_stages s
                        LEFT JOIN inc_applications a ON a.stage_id = s.id AND a.form_id IN (SELECT form_id FROM inc_ev_forms)
                       WHERE s.pipeline = "crm" GROUP BY s.id, s.name ORDER BY s.sort') as $r) {
        $out[(string) $r['name']] = (int) $r['n'];
    }
    return $out;
}

/** The organisations with an open Event Organizers: ours, and any client that has opened it. */
function eo_orgs(): array
{
    $out = [0];
    foreach (fabo_all("SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'eo\\_o%\\_ev\\_events'") as $r) {
        if (preg_match('/^eo_o(\d+)_ev_events$/', (string) $r['t'], $m)) {
            $out[] = (int) $m[1];
        }
    }
    return $out;
}

/** The sync, reminders, waiting list and message queue for every organisation (cron.php). */
function eo_cron_all(): void
{
    inc_use_kind('eo');
    try {
        foreach (eo_orgs() as $org) {
            if (!inc_org_enabled($org)) {
                continue;
            }
            try {
                inc_start(['is_admin' => false, 'client' => ['name' => 'system'], 'access' => null], $org);
                eo_install();
                eo_sync();
                eo_tick(true);
            } catch (Throwable $e) {
                error_log('FABO cron organizers (org ' . $org . '): ' . $e->getMessage());
            }
        }
    } finally {
        inc_use_kind('inc');
        inc_use_org(0);
    }
}

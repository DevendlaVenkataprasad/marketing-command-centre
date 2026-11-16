<?php
/**
 * Event Organizers — the public site: the events to browse and search, one
 * event's page, and the About, Contact, Terms and Privacy pages. Only events the
 * organizers made public, and only while they are approved.
 *
 * ?img=1 sends the banner, ?ics=1 a calendar file, ?go=<id> a sponsor's website.
 */

require_once __DIR__ . '/lib/eo/public.php';
$org = eo_public_start();
header('Cache-Control: no-store, private');
if ($org < 0) {
    eo_public_stop(-1, 404, 'Events', 'This page is not open', 'Please check the link you were given.');
}

/* ---- the fixed pages */
$page = (string) ($_GET['page'] ?? '');
if (in_array($page, ['about', 'terms', 'privacy', 'contact'], true)) {
    $brand = eo_brand($org);
    $sent = false;
    $why = '';
    if ($page === 'contact' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        fabo_csrf_check();
        if (trim((string) ($_POST['website_url'] ?? '')) !== '') {
            $sent = true;
        } elseif (fabo_throttle_blocked('ev-contact', fabo_client_ip(), 5, 3600)) {
            $why = fabo_throttle_words();
        } else {
            fabo_throttle_hit('ev-contact', fabo_client_ip());
            $email = trim((string) ($_POST['email'] ?? ''));
            $text = trim((string) ($_POST['message'] ?? ''));
            if (trim((string) ($_POST['name'] ?? '')) === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $text === '') {
                $why = 'Please give your name, a valid email and your message.';
            } else {
                inc_exec('INSERT INTO inc_ev_contact (name, email, subject, message) VALUES (?,?,?,?)', 'ssss', [mb_substr(trim((string) $_POST['name']), 0, 190), mb_substr($email, 0, 190), mb_substr(trim((string) ($_POST['subject'] ?? '')), 0, 190), mb_substr($text, 0, 5000)]);
                $sent = true;
            }
        }
    }
    $titles = ['about' => 'About', 'terms' => 'Terms', 'privacy' => 'Privacy', 'contact' => 'Contact us'];
    eo_public_head($org, $titles[$page]);
    echo '<div class="fm-head"><div class="fm-eyebrow">' . e($brand) . '</div><h1>' . e($titles[$page]) . '</h1></div>';
    if ($page === 'contact') {
        if ($sent) {
            echo '<p>Thank you. We will get back to you.</p>';
        } else {
            echo $why !== '' ? '<div class="inc-warn" role="alert">' . e($why) . '</div>' : '';
            echo '<form method="post" class="ev-form">' . fabo_csrf_field()
               . '<div class="inc-hp" aria-hidden="true"><label>Leave this empty <input type="text" name="website_url" tabindex="-1" autocomplete="off"></label></div>'
               . '<label>Your name <input type="text" name="name" required maxlength="190"></label><label>Email <input type="email" name="email" required maxlength="190"></label>'
               . '<label>Subject <input type="text" name="subject" maxlength="190"></label><label>Message <textarea name="message" rows="5" required maxlength="5000"></textarea></label>'
               . '<p><button class="btn btn-primary">Send</button></p></form>';
        }
    } else {
        $text = trim(inc_setting('eo_page_' . $page));
        $fallback = ['about' => $brand . ' runs events for its community. Browse what is coming up and apply online.',
                     'terms' => 'By applying for an event you agree to give accurate details and to follow the organizers\' instructions at the venue. A place is confirmed only by a ticket.',
                     'privacy' => 'We use what you give us to run the events you apply for: your application, ticket and attendance. We do not sell it. Contact us to see or remove your details.'][$page];
        echo '<p>' . nl2br(e($text !== '' ? $text : $fallback)) . '</p>';
    }
    eo_public_foot($org);
    exit;
}

/* ---- one event */
$ev = null;
if (isset($_GET['e'])) {
    $ev = eo_event_by_token((string) $_GET['e']);
    if (!$ev || (int) $ev['published'] !== 1 || (int) $ev['approved'] !== 1) {
        eo_public_stop($org, 404, 'Event', 'This event page is not open', 'It may have closed, or the link may be incomplete. Please check the link you were given.');
    }
}
$orgQ = $org > 0 ? '&o=' . $org : '';

if ($ev && isset($_GET['img'])) {
    require_once __DIR__ . '/lib/uploads.php';
    $path = fabo_upload_dir() . DIRECTORY_SEPARATOR . basename((string) $ev['banner']);
    if (empty($ev['banner']) || !is_file($path)) {
        http_response_code(404);
        exit;
    }
    fabo_image_headers((string) $ev['banner_mime']);
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}
if ($ev && isset($_GET['ics'])) {
    $esc = static fn(string $s) => str_replace(["\\", ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $s);
    $start = str_replace('-', '', (string) $ev['start_date']);
    $end = date('Ymd', strtotime(($ev['end_date'] ?: $ev['start_date']) . ' +1 day'));
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="event.ics"');
    echo "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Event Organizers//EN\r\nBEGIN:VEVENT\r\nUID:" . $ev['token'] . "@events\r\nDTSTAMP:" . gmdate('Ymd\THis\Z')
       . "\r\nDTSTART;VALUE=DATE:" . $start . "\r\nDTEND;VALUE=DATE:" . $end . "\r\nSUMMARY:" . $esc((string) $ev['name']) . "\r\nLOCATION:" . $esc((string) ($ev['venue'] ?: $ev['location']))
       . "\r\nDESCRIPTION:" . $esc(mb_substr((string) ($ev['summary'] ?: $ev['description']), 0, 500)) . "\r\nURL:" . eo_public_url($ev) . "\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    exit;
}
if ($ev && isset($_GET['go'])) {
    $s = inc_one('SELECT x.id, s.website FROM inc_ev_event_sponsors x JOIN inc_ev_sponsors s ON s.id = x.sponsor_id WHERE x.id = ? AND x.event_id = ?', 'ii', [(int) $_GET['go'], (int) $ev['id']]);
    if ($s && $s['website']) {
        inc_exec('UPDATE inc_ev_event_sponsors SET clicks = clicks + 1 WHERE id = ?', 'i', [(int) $s['id']]);
        header('Location: ' . $s['website']);
        exit;
    }
}

if (!$ev) {
    /* ---- the list */
    $q = trim((string) ($_GET['q'] ?? ''));
    $cat = (int) ($_GET['cat'] ?? 0);
    $fee = (string) ($_GET['fee'] ?? '');
    $mode = (string) ($_GET['mode'] ?? '');
    $w = 'published = 1 AND approved = 1 AND cancelled_at IS NULL AND (COALESCE(end_date, start_date) >= ? OR COALESCE(end_date, start_date) IS NULL)';
    $t = 's';
    $p = [date('Y-m-d')];
    if ($q !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $w .= ' AND (name LIKE ? OR summary LIKE ? OR tags LIKE ? OR venue LIKE ? OR location LIKE ?)';
        $t .= 'sssss';
        array_push($p, $like, $like, $like, $like, $like);
    }
    if ($cat) {
        $w .= ' AND category_id = ?';
        $t .= 'i';
        $p[] = $cat;
    }
    if ($fee === 'free' || $fee === 'paid') {
        $w .= ' AND is_paid = ' . ($fee === 'paid' ? 1 : 0);
    }
    if ($mode === 'online') {
        $w .= ' AND mode <> "in_person"';
    }
    $list = inc_all('SELECT * FROM inc_ev_events WHERE ' . $w . ' ORDER BY is_featured DESC, start_date IS NULL, start_date, id LIMIT 200', $t, $p);
    $cats = array_column(inc_all('SELECT id, name FROM inc_ev_categories ORDER BY name'), 'name', 'id');
    eo_public_head($org, 'Upcoming events');
    echo '<div class="fm-head"><div class="fm-eyebrow">' . e(eo_brand($org)) . '</div><h1>Upcoming events</h1></div>';
    echo '<form method="get" class="ev-filter">' . ($org > 0 ? '<input type="hidden" name="o" value="' . $org . '">' : '')
       . '<input type="search" name="q" value="' . e($q) . '" placeholder="Search events" aria-label="Search events">'
       . ($cats ? '<select name="cat" aria-label="Category"><option value="">Every category</option>' . inc_options($cats, $cat ?: '') . '</select>' : '')
       . '<select name="fee" aria-label="Price">' . inc_options(['' => 'Free and paid', 'free' => 'Free', 'paid' => 'Paid'], $fee) . '</select>'
       . '<select name="mode" aria-label="Where">' . inc_options(['' => 'Anywhere', 'online' => 'Online'], $mode) . '</select>'
       . '<button class="btn">Search</button></form>';
    if (!$list) {
        echo '<p class="ev-muted">No events are open at the moment.</p>';
    }
    foreach ($list as $r) {
        $where = (string) ($r['venue'] ?: $r['location']);
        echo '<a class="ev-card" href="event.php?e=' . e($r['token'] . $orgQ) . '"><strong>' . e($r['name']) . ((int) $r['is_featured'] === 1 ? ' <span class="pill amber">Featured</span>' : '') . '</strong>'
           . '<span class="ev-muted">' . e(eo_dates_text($r)) . ($where !== '' ? ' · ' . e($where) : '') . ' · ' . ((int) $r['is_paid'] === 1 ? e(eo_money((float) $r['price'], (string) $r['currency'])) : 'Free') . '</span></a>';
    }
    eo_public_foot($org);
    exit;
}

/* ---- the event */
$message = '';
$place = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'waitlist') {
    fabo_csrf_check();
    if (fabo_throttle_blocked('ev-wait', fabo_client_ip(), 10, 3600)) {
        $message = fabo_throttle_words();
    } else {
        fabo_throttle_hit('ev-wait', fabo_client_ip());
        try {
            $place = eo_waitlist_join($ev, (string) ($_POST['name'] ?? ''), (string) ($_POST['email'] ?? ''), (string) ($_POST['phone'] ?? ''));
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
        }
    }
} else {
    inc_exec('UPDATE inc_ev_events SET view_count = view_count + 1 WHERE id = ?', 'i', [(int) $ev['id']]);
}
$closed = eo_closed_reason($ev);
$forms = $closed === '' ? array_filter(eo_event_forms((int) $ev['id']), static fn($f) => (int) $f['public_enabled'] === 1 && $f['published_no'] !== null) : [];
$sessions = eo_sessions((int) $ev['id']);
$people = eo_people((int) $ev['id']);
$sponsors = eo_event_sponsors((int) $ev['id']);
$mentors = eo_event_mentors((int) $ev['id']);
$reviews = array_filter(eo_feedback((int) $ev['id']), static fn($b) => $b['status'] === 'approved');
$modes = ['in_person' => 'In person', 'online' => 'Online', 'hybrid' => 'Hybrid'];
$where = (string) ($ev['venue'] ?: $ev['location']);
$gcal = 'https://calendar.google.com/calendar/render?action=TEMPLATE&' . http_build_query(['text' => $ev['name'], 'dates' => str_replace('-', '', (string) $ev['start_date']) . '/' . date('Ymd', strtotime(($ev['end_date'] ?: $ev['start_date']) . ' +1 day')), 'location' => $where]);

eo_public_head($org, (string) $ev['name'], (string) $ev['summary']);
if ($ev['banner']) {
    echo '<img class="ev-banner" src="event.php?e=' . e($ev['token'] . $orgQ) . '&amp;img=1" alt="">';
}
echo '<div class="fm-head"><div class="fm-eyebrow">' . e((string) ($ev['event_type'] ?: 'Event')) . '</div><h1>' . e($ev['name']) . '</h1>';
if ($ev['cancelled_at']) {
    echo '<div class="inc-warn" role="alert"><strong>This event has been cancelled.</strong> ' . e((string) $ev['cancel_reason']) . '</div>';
}
echo $ev['summary'] ? '<p class="fm-lead">' . e($ev['summary']) . '</p>' : '';
echo '<ul class="ev-meta"><li>' . e(eo_dates_text($ev)) . ($ev['start_time'] ? ' at ' . e($ev['start_time']) : '') . '</li>'
   . ($where !== '' ? '<li>' . e($where) . '</li>' : '') . '<li>' . e($modes[$ev['mode']] ?? '') . '</li>'
   . '<li>' . ((int) $ev['is_paid'] === 1 ? e(eo_money((float) $ev['price'], (string) $ev['currency'])) : 'Free') . '</li>'
   . ($ev['reg_deadline'] ? '<li>Registration closes ' . e(inc_fmt_date($ev['reg_deadline'])) . '</li>' : '') . '</ul>';
if ($forms) {
    echo '<div class="ev-apply">';
    foreach ($forms as $f) {
        echo '<a class="btn btn-primary" href="event-apply.php?f=' . e($f['public_token'] . $orgQ) . '&amp;m=eo">' . e(count($forms) === 1 ? 'Apply now' : $f['name']) . '</a>';
    }
    echo '</div>';
} elseif ($closed !== '') {
    echo '<p class="ev-muted">' . e($closed) . '</p>';
}
if ($ev['start_date']) {
    echo '<p class="ev-muted"><a href="event.php?e=' . e($ev['token'] . $orgQ) . '&amp;ics=1">Add to calendar</a> · <a href="' . e($gcal) . '" target="_blank" rel="noopener">Google Calendar</a></p>';
}
echo '</div>';
if ($closed === 'This event is full.' && (int) $ev['waitlist_enabled'] === 1) {
    echo '<section class="ev-sec"><h2>Join the waiting list</h2>';
    if ($place) {
        echo '<p>You are number ' . (int) $place . ' on the waiting list. We will email you if a seat opens.</p>';
    } else {
        echo $message !== '' ? '<div class="inc-warn" role="alert">' . e($message) . '</div>' : '';
        echo '<form method="post" class="ev-form">' . fabo_csrf_field() . '<input type="hidden" name="action" value="waitlist">'
           . '<label>Your name <input type="text" name="name" required maxlength="190"></label><label>Email <input type="email" name="email" required maxlength="190"></label>'
           . '<label>Phone <input type="tel" name="phone" maxlength="40"></label><p><button class="btn btn-primary">Join the waiting list</button></p></form>';
    }
    echo '</section>';
}
if ($ev['description']) {
    echo '<section class="ev-sec"><h2>About</h2><p>' . nl2br(e($ev['description'])) . '</p></section>';
}
if ($ev['video_url']) {
    echo '<p><a href="' . e($ev['video_url']) . '" target="_blank" rel="noopener nofollow">Watch the video</a></p>';
}
$extras = array_filter([(int) $ev['food_provided'] === 1 ? 'Food provided' : '', (int) $ev['accommodation_provided'] === 1 ? 'Accommodation provided' : '', (string) $ev['amenities']]);
if ($extras) {
    echo '<section class="ev-sec"><h2>Included</h2><p>' . e(implode(' · ', $extras)) . '</p></section>';
}
if ($sessions) {
    echo '<section class="ev-sec"><h2>Agenda</h2><ul class="ev-agenda">';
    foreach ($sessions as $s) {
        $who = trim($s['speaker'] . ($s['speaker'] && $s['room'] ? ' · ' : '') . $s['room']);
        echo '<li><time>' . (eo_days($ev) > 1 ? 'Day ' . (int) $s['day_no'] . ' ' : '') . e(trim($s['starts'] . ($s['ends'] ? ' – ' . $s['ends'] : ''))) . '</time> ' . e($s['title'])
           . ($who !== '' ? ' <span class="ev-muted">' . e($who) . '</span>' : '') . '</li>';
    }
    echo '</ul></section>';
}
foreach (['speaker' => 'Speakers', 'mentor' => 'Mentors', 'sponsor' => 'Sponsors', 'partner' => 'Partners'] as $kind => $title) {
    $some = array_filter($people, static fn($p) => $p['kind'] === $kind);
    $rows = [];
    foreach ($some as $p) {
        $rows[] = ($p['url'] ? '<a href="' . e($p['url']) . '" target="_blank" rel="noopener nofollow">' . e($p['name']) . '</a>' : e($p['name'])) . ($p['detail'] ? ' <span class="ev-muted">' . e($p['detail']) . '</span>' : '');
    }
    if ($kind === 'mentor') {
        foreach ($mentors as $m) {
            $rows[] = e($m['name']) . ($m['expertise'] ? ' <span class="ev-muted">' . e($m['expertise']) . '</span>' : '');
        }
    }
    if ($kind === 'sponsor') {
        foreach ($sponsors as $s) {
            $rows[] = ($s['website'] ? '<a href="event.php?e=' . e($ev['token'] . $orgQ) . '&amp;go=' . (int) $s['id'] . '" target="_blank" rel="noopener nofollow">' . e($s['name']) . '</a>' : e($s['name'])) . ' <span class="ev-muted">' . e(eo_sponsor_tiers()[$s['tier']]) . '</span>';
        }
    }
    if ($rows) {
        echo '<section class="ev-sec"><h2>' . e($title) . '</h2><ul class="ev-people"><li>' . implode('</li><li>', $rows) . '</li></ul></section>';
    }
}
if ($reviews) {
    echo '<section class="ev-sec"><h2>What people said</h2><ul class="ev-people">';
    foreach (array_slice($reviews, 0, 10) as $b) {
        echo '<li>' . str_repeat('★', (int) $b['rating']) . ' ' . e((string) $b['comment']) . '</li>';
    }
    echo '</ul></section>';
}
eo_public_foot($org);

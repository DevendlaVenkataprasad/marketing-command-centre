<?php
/**
 * Event Organizers — what a person holds: a ticket (?t=) with its QR code, the
 * online join link, the live page and feedback; a food token (?f=); a
 * certificate (?c=), which anyone holding the link can verify. The link in the
 * email is the only way here: the codes are random and nothing lists them.
 */

require_once __DIR__ . '/lib/eo/public.php';
$org = eo_public_start('ticket holder');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
if ($org < 0) {
    eo_public_stop(-1, 404, 'Ticket', 'Ticket not found', 'Please use the link in your email.');
}

/* ---- a certificate */
if (isset($_GET['c'])) {
    $token = (string) $_GET['c'];
    $c = preg_match('/^[a-f0-9]{32}$/', $token) ? inc_one('SELECT c.*, a.applicant_name, e.name AS event_name, e.start_date, e.end_date FROM inc_ev_certs c
              JOIN inc_applications a ON a.id = c.application_id JOIN inc_ev_events e ON e.id = c.event_id WHERE c.token = ?', 's', [$token]) : null;
    if (!$c) {
        eo_public_stop($org, 404, 'Certificate', 'Certificate not found', 'This certificate could not be verified.');
    }
    eo_public_head($org, 'Certificate', '', true);
    echo '<div class="ev-cert"><div class="fm-eyebrow">Certificate of participation</div><p>This certifies that</p><h1>' . e($c['applicant_name']) . '</h1><p>attended</p><h2>' . e($c['event_name']) . '</h2>'
       . '<p>' . e(eo_dates_text($c)) . ' · ' . (int) $c['days_attended'] . ' of ' . (int) $c['total_days'] . ' day' . ((int) $c['total_days'] === 1 ? '' : 's') . '</p>'
       . '<p class="ev-code">' . e($c['number']) . '</p><p class="ev-muted">' . e(eo_brand($org)) . ' · issued ' . e(inc_fmt_date(substr((string) $c['issued_at'], 0, 10))) . ' · verified</p>'
       . '<p class="ev-noprint"><button type="button" class="btn" onclick="window.print()">Print or save as PDF</button></p></div>';
    eo_public_foot($org, false);
    exit;
}

/* ---- a food token */
if (isset($_GET['f'])) {
    $token = (string) $_GET['f'];
    $f = preg_match('/^[a-f0-9]{24}$/', $token) ? inc_one('SELECT f.*, a.applicant_name, e.name AS event_name FROM inc_ev_food f JOIN inc_applications a ON a.id = f.application_id JOIN inc_ev_events e ON e.id = f.event_id WHERE f.token = ?', 's', [$token]) : null;
    if (!$f) {
        eo_public_stop($org, 404, 'Food token', 'Token not found', 'Please use the link in your email.');
    }
    eo_public_head($org, 'Food token', '', true);
    echo '<div class="fm-head"><div class="fm-eyebrow">Food token</div><h1>' . e($f['event_name']) . '</h1></div><div class="ev-ticket">' . eo_qr_svg($token, 280)
       . '<p><strong>' . e($f['applicant_name']) . '</strong></p><p>' . max(0, (int) $f['quantity'] - (int) $f['redeemed']) . ' of ' . (int) $f['quantity'] . ' left</p><p class="ev-code">' . e($token) . '</p></div>';
    eo_public_foot($org, false);
    exit;
}

/* ---- a ticket */
$k = eo_ticket((string) ($_GET['t'] ?? ''));
$ev = $k ? eo_event((int) $k['event_id']) : null;
if (!$ev) {
    eo_public_stop($org, 404, 'Ticket', 'Ticket not found', 'Please use the link in your email.');
}
$live = $k['status'] === 'issued' && (int) $ev['approved'] === 1;

if (isset($_GET['join'])) {
    $why = $live ? eo_join_problem($ev) : 'This ticket is not valid.';
    if ($why === '') {
        eo_online_record($ev, (int) $k['id'], (string) $k['applicant_email'], 'join-link');
        header('Location: ' . $ev['meeting_url']);
        exit;
    }
    eo_public_stop($org, 200, (string) $ev['name'], 'You cannot join just yet', $why);
}

$message = '';
$sent = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    fabo_csrf_check();
    if (fabo_throttle_blocked('ev-feedback', fabo_client_ip(), 30, 3600)) {
        $message = fabo_throttle_words();
    } else {
        fabo_throttle_hit('ev-feedback', fabo_client_ip());
        try {
            eo_feedback_save($k, $ev, $_POST);
            $sent = true;
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
        }
    }
}
$began = !empty($ev['start_date']) && $ev['start_date'] <= date('Y-m-d');
$given = $live ? inc_one('SELECT rating FROM inc_ev_feedback WHERE ticket_id = ?', 'i', [(int) $k['id']]) : null;
$here = 'event-ticket.php?t=' . $k['code'] . ($org > 0 ? '&o=' . $org : '');

eo_public_head($org, 'Ticket', '', true);
if ($k['status'] === 'reserved') {
    echo '<div class="fm-head"><div class="fm-eyebrow">' . e($ev['name']) . '</div><h1>Your seat is held</h1><p class="fm-lead">Your ticket appears here as soon as your payment is confirmed.</p></div>';
} elseif (!$live) {
    echo '<div class="fm-head"><div class="fm-eyebrow">' . e($ev['name']) . '</div><h1>This ticket is not valid</h1><p class="fm-lead">It has been cancelled. Please contact the organizers if you think this is a mistake.</p></div>';
} else {
    $where = (string) ($ev['venue'] ?: $ev['location']);
    echo '<div class="fm-head"><div class="fm-eyebrow">Ticket</div><h1>' . e($ev['name']) . '</h1><ul class="ev-meta"><li>' . e(eo_dates_text($ev)) . '</li>' . ($where !== '' ? '<li>' . e($where) . '</li>' : '') . '</ul>';
    if ($ev['cancelled_at']) {
        echo '<div class="inc-warn" role="alert"><strong>This event has been cancelled.</strong> ' . e((string) $ev['cancel_reason']) . '</div>';
    }
    if ($ev['meeting_url'] && $ev['mode'] !== 'in_person') {
        echo '<p><a class="btn" href="' . e($here) . '&amp;join=1">Join online</a></p>';
    }
    echo '</div><div class="ev-ticket">' . eo_qr_svg((string) $k['code'], 280) . '<p><strong>' . e($k['applicant_name']) . '</strong></p><p class="ev-code">' . e($k['code']) . '</p>'
       . '<p class="ev-noprint"><button type="button" class="btn" onclick="window.print()">Print or save as PDF</button>';
    if (eo_sessions((int) $ev['id'])) {
        echo ' <a class="btn" href="' . e(eo_here($org, 'event-live.php?t=' . $k['code'])) . '">Live Q&amp;A and polls</a>';
    }
    echo '</p></div>';
    if ($began) {
        echo '<section class="ev-sec ev-noprint"><h2>How was it?</h2>';
        if ($given || $sent) {
            echo '<p>Thank you for your feedback.</p>';
        } else {
            echo $message !== '' ? '<div class="inc-warn" role="alert">' . e($message) . '</div>' : '';
            echo '<form method="post" class="ev-form">' . fabo_csrf_field() . '<fieldset class="ev-fs"><legend>Your rating</legend><div class="ev-rate">';
            for ($i = 1; $i <= 5; $i++) {
                echo '<label><input type="radio" name="rating" value="' . $i . '" required> ' . $i . '</label>';
            }
            echo '</div></fieldset><label>Anything to add? <textarea name="comment" rows="3" maxlength="3000"></textarea></label><p><button class="btn btn-primary">Send feedback</button></p></form>';
        }
        echo '</section>';
    }
}
eo_public_foot($org, false);

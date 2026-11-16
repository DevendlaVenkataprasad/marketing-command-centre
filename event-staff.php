<?php
/**
 * Event Organizers — the console of someone given one job on an event by a link:
 * a scanner volunteer (check-in, food tokens), a viewer (the attendee list),
 * finance (orders and revenue) or a sponsor's rep (leads). The token decides the
 * role and the event; it reaches nothing else, and revoking it closes this page.
 */

require_once __DIR__ . '/lib/eo/public.php';
$org = eo_public_start('staff');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
$m = $org >= 0 ? eo_staff_by_token((string) ($_GET['k'] ?? '')) : null;
$ev = $m ? eo_event((int) $m['event_id']) : null;
if (!$ev) {
    eo_public_stop($org, 403, 'Staff', 'This link is not active', 'Ask the organizers for a new one.');
}
$GLOBALS['inc_ctx']['actor'] = ($m['name'] ?: $m['email']) . ' (link)';
$role = (string) $m['role'];
$eid = (int) $ev['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    fabo_csrf_check();
    $GLOBALS['eo_staff'] = true;
    $a = (string) ($_POST['action'] ?? '');
    if ($role === 'scanner' && $a === 'scan') {
        echo json_encode(eo_checkin($eid, (string) ($_POST['code'] ?? ''), (int) ($_POST['day'] ?? 1)) + ['counts' => eo_checkin_counts($eid)]);
    } elseif ($role === 'scanner' && $a === 'food') {
        echo json_encode(eo_food_redeem($eid, (string) ($_POST['code'] ?? '')));
    } elseif ($role === 'sponsor_rep' && $a === 'lead') {
        try {
            $k = eo_lead_capture((int) $m['sponsor_id'], (string) ($_POST['code'] ?? ''), inc_int_or_null($_POST['rating'] ?? null), (string) ($_POST['notes'] ?? ''));
            echo json_encode(['state' => 'ok', 'name' => $k['applicant_name']]);
        } catch (InvalidArgumentException $e) {
            echo json_encode(['state' => 'unknown', 'name' => '']);
        }
    } else {
        http_response_code(403);
        echo json_encode(['state' => 'error']);
    }
    exit;
}

eo_public_head($org, 'Staff console', '', true);
$tbl = static function (array $heads, array $rows): string {
    $h = '<div class="ev-scroll"><table class="ev-table"><thead><tr>';
    foreach ($heads as $x) {
        $h .= '<th>' . e($x) . '</th>';
    }
    $h .= '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $h .= '<tr>' . implode('', array_map(static fn($c) => '<td>' . e((string) $c) . '</td>', $r)) . '</tr>';
    }
    return $h . '</tbody></table></div>';
};
echo '<div class="fm-head"><div class="fm-eyebrow">' . e(eo_staff_roles()[$role]) . '</div><h1>' . e($ev['name']) . '</h1><p class="fm-lead">' . e(eo_dates_text($ev)) . '</p></div>';
if ($role === 'scanner' || $role === 'sponsor_rep') {
    $days = eo_days($ev);
    echo '<div id="eo-door" data-url="' . e(eo_here($org, 'event-staff.php?k=' . $m['token'])) . '" data-csrf="' . e(fabo_csrf_token()) . '" data-action="' . ($role === 'scanner' ? 'scan' : 'lead') . '">'
       . '<div id="eo-reader" class="eo-reader"></div><p><button type="button" class="btn btn-primary" id="eo-cam">Start the camera</button></p>';
    if ($role === 'scanner') {
        echo '<p><label><input type="checkbox" id="eo-food"> Scanning food tokens</label>' . ($days > 1 ? ' <label>Day <input type="number" id="eo-day" min="1" max="' . $days . '" value="' . eo_today_day($ev) . '"></label>' : '<input type="hidden" id="eo-day" value="1">') . '</p>';
    } else {
        echo '<input type="hidden" id="eo-day" value="1">';
    }
    echo '<form id="eo-typed" class="ev-row"><input type="text" id="eo-code" autocomplete="off" aria-label="Code" placeholder="Or type the code"><button class="btn">Go</button></form>'
       . '<div id="eo-result" class="eo-result" role="status" aria-live="polite">Waiting for the first scan.</div><span id="eo-in" hidden></span><span id="eo-inday" hidden></span>'
       . '<script type="application/json" id="eo-counts">' . json_encode((object) eo_checkin_counts($eid)) . '</script></div>'
       . '<script src="' . e(fabo_asset('assets/html5-qrcode.min.js')) . '" defer></script><script src="' . e(fabo_asset('assets/eo.js')) . '" defer></script>';
    if ($role === 'sponsor_rep') {
        $rows = array_map(static fn($l) => [$l['applicant_name'], $l['applicant_email'], $l['applicant_phone'], $l['notes']], eo_leads((int) $m['sponsor_id']));
        echo '<section class="ev-sec"><h2>My leads</h2>' . ($rows ? $tbl(['Name', 'Email', 'Phone', 'Notes'], $rows) : '<p class="ev-muted">None yet.</p>') . '</section>';
    }
}
if ($role === 'viewer' || $role === 'finance') {
    $rows = array_map(static fn($a) => [$a['applicant_name'], $a['applicant_email'], $a['type_name'], $a['ticket_status'] ?: 'none', (int) $a['days_in']], eo_attendees($eid));
    echo '<section class="ev-sec"><h2>Attendees</h2>' . ($rows ? $tbl(['Name', 'Email', 'Type', 'Ticket', 'Days in'], $rows) : '<p class="ev-muted">None yet.</p>') . '</section>';
}
if ($role === 'finance') {
    [$gross, $refunded, $net, $paid, $pending] = eo_revenue($eid);
    $cur = (string) $ev['currency'];
    echo '<section class="ev-sec"><h2>Revenue</h2><ul class="ev-meta"><li>Net ' . e(eo_money($net, $cur)) . '</li><li>Refunded ' . e(eo_money($refunded, $cur)) . '</li><li>' . $paid . ' paid</li><li>' . $pending . ' pending</li></ul>'
       . $tbl(['Name', 'Email', 'Amount', 'Method', 'Status'], array_map(static fn($o) => [$o['name'], $o['email'], eo_money((float) $o['amount'], (string) $o['currency']), $o['method'], $o['status']], eo_orders(['event_id' => $eid]))) . '</section>';
}
eo_public_foot($org, false);

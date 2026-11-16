<?php
/**
 * Event Organizers — the public API, read-only: ?r=events and
 * ?r=registrations&event_id=<id>, with page and pageSize. Send the key as
 * "Authorization: Bearer <key>" or, where a host strips that, "X-Api-Key".
 */

require_once __DIR__ . '/lib/eo/public.php';
$org = eo_public_start('API');
header('Content-Type: application/json');
header('Cache-Control: no-store');
$say = static function (int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body);
    exit;
};
if ($org < 0) {
    $say(404, ['error' => 'Not found']);
}
$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
$key = preg_match('/^Bearer\s+(.+)$/i', $auth, $m) ? trim($m[1]) : trim((string) ($_SERVER['HTTP_X_API_KEY'] ?? ''));
if ($key === '') {
    $say(401, ['error' => 'Missing Authorization: Bearer <api key> header']);
}
if (!eo_api_auth($key)) {
    $say(401, ['error' => 'Invalid or revoked API key']);
}
$limitKey = substr(hash('sha256', $key), 0, 24);
if (fabo_throttle_blocked('ev-api', $limitKey, 600, 3600)) {
    $say(429, ['error' => 'Too many requests']);
}
fabo_throttle_hit('ev-api', $limitKey);

$page = max(1, (int) ($_GET['page'] ?? 1));
$size = min(100, max(1, (int) ($_GET['pageSize'] ?? 25)));
$off = ($page - 1) * $size;
$r = (string) ($_GET['r'] ?? '');
if ($r === 'events') {
    $total = (int) inc_one('SELECT COUNT(*) AS n FROM inc_ev_events WHERE approved = 1')['n'];
    $rows = inc_all('SELECT id, name, event_type, published, is_paid, price, currency, start_date, end_date, capacity, created_at FROM inc_ev_events WHERE approved = 1 ORDER BY id DESC LIMIT ' . $size . ' OFFSET ' . $off);
    $say(200, ['data' => $rows, 'page' => $page, 'pageSize' => $size, 'total' => $total]);
}
if ($r === 'registrations') {
    $id = (int) ($_GET['event_id'] ?? 0);
    if (!$id) {
        $say(400, ['error' => 'event_id query parameter is required']);
    }
    if (!eo_event($id)) {
        $say(404, ['error' => 'Event not found']);
    }
    $all = eo_attendees($id);
    $rows = array_map(static fn($a) => ['reference' => $a['ref'], 'name' => $a['applicant_name'], 'email' => $a['applicant_email'], 'type' => $a['type_name'], 'stage' => $a['stage_name'],
                                        'registration' => $a['reg_status'], 'ticket' => $a['ticket_status'], 'days_checked_in' => (int) $a['days_in']], array_slice($all, $off, $size));
    $say(200, ['data' => $rows, 'page' => $page, 'pageSize' => $size, 'total' => count($all)]);
}
$say(404, ['error' => 'Unknown resource: use r=events or r=registrations']);

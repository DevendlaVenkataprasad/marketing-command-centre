<?php
/** Event Organizers — the assistant on the public pages. Throttled per address. */

require_once __DIR__ . '/lib/eo/public.php';
$org = eo_public_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');
if ($org < 0 || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(404);
    echo json_encode(['reply' => '']);
    exit;
}
fabo_csrf_check();
if (fabo_throttle_blocked('ev-chat', fabo_client_ip(), 30, 600)) {
    echo json_encode(['reply' => 'Please wait a little before asking again.']);
    exit;
}
fabo_throttle_hit('ev-chat', fabo_client_ip());
$q = trim((string) ($_POST['q'] ?? ''));
echo json_encode(['reply' => $q === '' ? 'What would you like to know?' : eo_chat($q, eo_brand($org))]);

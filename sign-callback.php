<?php
/**
 * Where the eSign Service Provider sends the signatory's browser back with
 * its signed response. Public by necessity (it is a cross-site POST, so the
 * session cookie may not come with it) — and trusted for nothing: the
 * response is believed only after its XML signature verifies against the
 * configured ESP certificate and it names a transaction started here, and
 * the signature it carries counts only after it verifies over the exact
 * bytes prepared. Then the browser goes on to the result page.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/ds/app.php';
fabo_boot();
ds_install();

header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}
require_once __DIR__ . '/lib/throttle.php';
if (fabo_throttle_blocked('ds-callback', fabo_client_ip(), 60, 600)) {
    http_response_code(429);
    exit('Too many requests.');
}
fabo_throttle_hit('ds-callback', fabo_client_ip());
$raw = '';
foreach (['msg', 'eSignResponse', 'esignResp', 'EsignResp', 'response'] as $k) {
    if (isset($_POST[$k]) && is_string($_POST[$k]) && $_POST[$k] !== '') {
        $raw = $_POST[$k];
        break;
    }
}
if ($raw === '') {
    $raw = (string) file_get_contents('php://input', false, null, 0, 5000000);
}
[$t, $ok, $msg] = ds_esign_callback($raw);
if (!$t) {
    http_response_code(400);
    exit(htmlspecialchars($msg));
}
$to = ((int) $t['initiated_by_id'] === 0 ? 'admin/' : '') . 'sign.php?' . http_build_query(['p' => 'done', 't' => $t['txn_ref']]);
header('Location: ' . $to, true, 303);

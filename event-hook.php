<?php
/**
 * Event Organizers — what outside services call: ?s=stripe (a payment finished)
 * and ?s=zoom (someone joined or left a meeting). Both prove who they are with
 * a signature over the body; nothing is believed without it.
 */

require_once __DIR__ . '/lib/eo/public.php';
fabo_boot();
inc_use_kind('eo');
$payload = (string) file_get_contents('php://input');
$say = static function (int $status, array $body): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body);
    exit;
};

if (($_GET['s'] ?? '') === 'stripe') {
    $secret = eo_env('STRIPE_WEBHOOK_SECRET');
    if ($secret === '') {
        $say(501, ['error' => 'Stripe is not configured']);
    }
    if (!eo_stripe_verify($payload, (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''), $secret)) {
        $say(400, ['error' => 'Invalid signature']);
    }
    $event = json_decode($payload, true) ?: [];
    if (($event['type'] ?? '') === 'checkout.session.completed') {
        $s = $event['data']['object'] ?? [];
        $org = (int) ($s['metadata']['org'] ?? 0);
        if (inc_org_enabled($org)) {
            inc_start(['is_admin' => false, 'client' => ['name' => 'Stripe'], 'access' => null], $org);
            eo_install();
            $o = eo_order((string) ($s['metadata']['order'] ?? ''));
            // Only the session this order opened can pay it.
            if ($o && $o['stripe_session'] === ($s['id'] ?? '')) {
                eo_order_paid($o, (string) ($s['payment_intent'] ?? ''));
            }
        }
    }
    $say(200, ['received' => true]);
}

if (($_GET['s'] ?? '') === 'zoom') {
    $secret = eo_env('ZOOM_WEBHOOK_SECRET_TOKEN');
    if ($secret === '') {
        $say(501, ['error' => 'Zoom is not configured']);
    }
    $event = json_decode($payload, true) ?: [];
    if (($event['event'] ?? '') === 'endpoint.url_validation') {
        $plain = (string) ($event['payload']['plainToken'] ?? '');
        $say(200, ['plainToken' => $plain, 'encryptedToken' => hash_hmac('sha256', $plain, $secret)]);
    }
    $ts = (string) ($_SERVER['HTTP_X_ZM_REQUEST_TIMESTAMP'] ?? '');
    $sig = 'v0=' . hash_hmac('sha256', 'v0:' . $ts . ':' . $payload, $secret);
    if (!hash_equals($sig, (string) ($_SERVER['HTTP_X_ZM_SIGNATURE'] ?? ''))) {
        $say(401, ['error' => 'Invalid signature']);
    }
    if (in_array($event['event'] ?? '', ['meeting.participant_joined', 'meeting.participant_left'], true)) {
        $meeting = (string) ($event['payload']['object']['id'] ?? '');
        $who = (string) ($event['payload']['object']['participant']['email'] ?? '');
        foreach (eo_orgs() as $org) {
            if (!inc_org_enabled($org)) {
                continue;
            }
            inc_start(['is_admin' => false, 'client' => ['name' => 'Zoom'], 'access' => null], $org);
            $ev = inc_one('SELECT * FROM inc_ev_events WHERE zoom_meeting_id = ?', 's', [$meeting]);
            if ($ev) {
                if ($event['event'] === 'meeting.participant_joined') {
                    eo_online_record($ev, null, $who, 'zoom');
                } else {
                    inc_exec('UPDATE inc_ev_online SET left_at = NOW() WHERE event_id = ? AND email = ? AND left_at IS NULL', 'is', [(int) $ev['id'], $who]);
                }
                break;
            }
        }
    }
    $say(200, ['received' => true]);
}

$say(404, ['error' => 'Not found']);

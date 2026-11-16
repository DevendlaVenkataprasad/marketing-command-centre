<?php
/**
 * Event Organizers — paying for a place. The order's token is the only way in.
 * Card goes through Stripe when it is set up; bank transfer and cash always
 * work, and hold the seat until an organizer confirms the money.
 */

require_once __DIR__ . '/lib/eo/public.php';
$org = eo_public_start('applicant');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
$o = $org >= 0 ? eo_order((string) ($_GET['t'] ?? '')) : null;
if (!$o) {
    eo_public_stop($org, 404, 'Order', 'Order not found', 'Please use the link you were given.');
}
$ev = eo_event((int) $o['event_id']);
$self = eo_here($org, 'event-pay.php?t=' . $o['token']);
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    fabo_csrf_check();
    if (fabo_throttle_blocked('ev-pay', fabo_client_ip(), 30, 3600)) {
        $message = fabo_throttle_words();
    } else {
        fabo_throttle_hit('ev-pay', fabo_client_ip());
        try {
            if (($_POST['action'] ?? '') === 'coupon') {
                $message = eo_order_coupon($o, (string) ($_POST['code'] ?? ''));
                if ($message === '') {
                    header('Location: ' . $self);
                    exit;
                }
            } else {
                $go = eo_order_pay($o, (string) ($_POST['method'] ?? ''), (string) ($_POST['bank_ref'] ?? ''));
                header('Location: ' . ($go ?: $self));
                exit;
            }
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
        }
    }
    $o = eo_order((string) $o['token']);
}

$k = inc_one('SELECT code, status FROM inc_ev_tickets WHERE event_id = ? AND application_id = ?', 'ii', [(int) $o['event_id'], (int) $o['application_id']]);
eo_public_head($org, 'Your order', '', true);
echo '<div class="fm-head"><div class="fm-eyebrow">' . e($ev['name']) . '</div>';
if ($o['status'] === 'paid') {
    echo '<h1>Payment received</h1><p class="fm-lead">Your ticket is confirmed.</p></div>';
    if ($k) {
        echo '<p><a class="btn btn-primary" href="' . e(eo_ticket_url((string) $k['code'])) . '">Open my ticket</a></p>';
    }
} elseif ($o['status'] === 'refunded' || $o['status'] === 'failed') {
    echo '<h1>This order is closed</h1><p class="fm-lead">' . ($o['status'] === 'refunded' ? 'It has been refunded.' : 'The payment was not accepted.') . ' Please contact the organizers with any question.</p></div>';
} elseif ($o['method'] !== 'none' && $k && $k['status'] === 'reserved' && $o['method'] !== 'stripe') {
    echo '<h1>Your seat is held</h1><p class="fm-lead">Your ticket will come by email as soon as the organizers confirm your payment of ' . e(eo_money((float) $o['amount'], (string) $o['currency'])) . '.</p></div>';
    $bank = trim(inc_setting('eo_bank_details'));
    if ($o['method'] === 'bank' && $bank !== '') {
        echo '<section class="ev-sec"><h2>Bank details</h2><p>' . nl2br(e($bank)) . '</p></section>';
    }
} elseif (isset($_GET['paid'])) {
    echo '<h1>Thank you</h1><p class="fm-lead">We are confirming your payment. Your ticket will come by email in a moment.</p></div>';
} else {
    echo '<h1>Complete your registration</h1><ul class="ev-meta"><li>' . e(eo_dates_text($ev)) . '</li><li><strong>' . e(eo_money((float) $o['amount'], (string) $o['currency'])) . '</strong>'
       . ((float) $o['discount'] > 0 ? ' <span class="ev-muted">after ' . e(eo_money((float) $o['discount'], (string) $o['currency'])) . ' off</span>' : '') . '</li></ul></div>';
    echo $message !== '' ? '<div class="inc-warn" role="alert">' . e($message) . '</div>' : '';
    echo '<form method="post" class="ev-row">' . fabo_csrf_field() . '<input type="hidden" name="action" value="coupon"><input type="text" name="code" maxlength="40" placeholder="Coupon code" aria-label="Coupon code"><button class="btn">Apply</button></form>';
    echo '<form method="post" class="ev-form">' . fabo_csrf_field() . '<input type="hidden" name="action" value="pay"><fieldset class="ev-fs"><legend>How would you like to pay?</legend>';
    if (eo_stripe_ready()) {
        echo '<label class="ev-opt"><input type="radio" name="method" value="stripe" checked> Card</label>';
    }
    echo '<label class="ev-opt"><input type="radio" name="method" value="bank"' . (eo_stripe_ready() ? '' : ' checked') . '> Bank transfer</label>'
       . '<label class="ev-opt"><input type="radio" name="method" value="cash"> Cash at the venue</label></fieldset>'
       . '<label>Bank transfer reference, if you have one <input type="text" name="bank_ref" maxlength="120"></label><p><button class="btn btn-primary">Continue</button></p></form>';
}
eo_public_foot($org, false);

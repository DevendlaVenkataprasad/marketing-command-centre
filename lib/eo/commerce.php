<?php
/**
 * Event Organizers — registrations, orders, coupons, payment, refunds, the
 * waiting list, and plans. A registration is an application on the main
 * pipeline; what it needs beyond that lives in inc_ev_regs.
 *
 * Paid events take Stripe when STRIPE_SECRET_KEY is set, and bank transfer or
 * cash at the venue always: those hold the seat until an organizer confirms the
 * money. A ticket is only 'issued' once paid; a 'reserved' one does not get in.
 */

/* ========================================================= registrations */

function eo_reg_status(int $eventId, int $appId, string $status): void
{
    inc_exec('INSERT INTO inc_ev_regs (application_id, event_id, status) VALUES (?,?,?) ON DUPLICATE KEY UPDATE status = VALUES(status)', 'iis', [$appId, $eventId, $status]);
}

/** Five registrations an hour from one email is more than a person makes (lib/fraud.ts). */
function eo_velocity_flag(string $email): ?string
{
    if ($email === '') {
        return null;
    }
    $n = (int) (inc_one('SELECT COUNT(*) AS n FROM inc_ev_regs g JOIN inc_applications a ON a.id = g.application_id
                          WHERE a.applicant_email = ? AND g.created_at >= NOW() - INTERVAL 1 HOUR', 's', [$email])['n'] ?? 0);
    return $n >= 5 ? ($n + 1) . ' registrations from this email within the last hour' : null;
}

/**
 * What follows a public application on an event's form. Returns the address to
 * send the applicant on to (payment), or null to stay on the thank-you page.
 */
function eo_after_submit(array $done, array $form): ?string
{
    if (!empty($done['repeat']) || empty($done['id'])) {
        return null;
    }
    $ev = inc_one('SELECT e.* FROM inc_ev_events e JOIN inc_ev_forms x ON x.event_id = e.id WHERE x.form_id = ?', 'i', [(int) $form['id']]);
    if (!$ev) {
        return null;
    }
    $appId = (int) $done['id'];
    $app = inc_one('SELECT id, applicant_name, applicant_email, applicant_phone FROM inc_applications WHERE id = ?', 'i', [$appId]);
    $email = (string) ($app['applicant_email'] ?? '');
    $flag = eo_velocity_flag($email);
    inc_exec('INSERT IGNORE INTO inc_ev_regs (application_id, event_id, flagged, flag_reason) VALUES (?,?,?,?)', 'iiis', [$appId, (int) $ev['id'], $flag ? 1 : 0, $flag]);
    inc_exec('UPDATE inc_ev_waitlist SET status = "converted" WHERE event_id = ? AND email = ? AND status IN ("waiting","notified")', 'is', [(int) $ev['id'], $email]);
    eo_webhook_dispatch('registration.created', ['event_id' => (int) $ev['id'], 'application' => $done['ref'] ?? '', 'name' => $app['applicant_name'] ?? '', 'email' => $email]);
    if ((int) $ev['is_paid'] === 1) {
        return eo_order_url(eo_order_open($ev, $app));
    }
    // A free event that needs nobody's say-so tickets at once.
    if ((int) $ev['approval_required'] === 0 && !$flag) {
        eo_ticket_grant($ev, $appId);
        return null;
    }
    eo_mail($email, 'We received your application: ' . $ev['name'], (string) ($app['applicant_name'] ?? ''),
            ['Thank you for applying to ' . $ev['name'] . '.', 'Your reference is ' . ($done['ref'] ?? '') . '. We will write again when it has been reviewed.']);
    return null;
}

/** Rejects a registration: its seat goes back and the applicant is told. */
function eo_reg_reject(int $eventId, int $appId): void
{
    eo_need_work();
    $ev = eo_event($eventId);
    $app = inc_one('SELECT a.* FROM inc_applications a JOIN inc_ev_forms x ON x.form_id = a.form_id WHERE a.id = ? AND x.event_id = ?', 'ii', [$appId, $eventId]);
    if (!$ev || !$app) {
        throw new InvalidArgumentException('That application is not this event\'s.');
    }
    eo_reg_status($eventId, $appId, 'rejected');
    $k = inc_one('SELECT * FROM inc_ev_tickets WHERE event_id = ? AND application_id = ? AND status <> "cancelled"', 'ii', [$eventId, $appId]);
    if ($k) {
        inc_exec('UPDATE inc_ev_tickets SET status = "cancelled" WHERE id = ?', 'i', [(int) $k['id']]);
        eo_waitlist_promote($eventId);
    }
    inc_activity($appId, null, 'ticket', 'Registration rejected for ' . $ev['name']);
    eo_mail((string) $app['applicant_email'], 'Your application: ' . $ev['name'], (string) $app['applicant_name'],
            ['Thank you for applying to ' . $ev['name'] . '. We are not able to offer you a place this time.']);
}

/* =============================================================== coupons */

/** [discount, final] the way lib/coupon.ts works it out: never below the smallest charge. */
function eo_discount(float $price, string $type, float $value): array
{
    $raw = $type === 'percentage' ? $price * ($value / 100) : $value;
    $off = min(max($raw, 0), $price);
    $final = max($price - $off, 0.5);
    return [round($price - $final, 2), round($final, 2)];
}

function eo_coupons(int $eventId): array
{
    return inc_all('SELECT * FROM inc_ev_coupons WHERE event_id = ? ORDER BY id DESC', 'i', [$eventId]);
}

function eo_coupon_save(int $eventId, array $in): void
{
    eo_need_work();
    $code = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($in['code'] ?? '')));
    if ($code === '') {
        throw new InvalidArgumentException('Give the coupon a code.');
    }
    $type = ($in['type'] ?? '') === 'fixed' ? 'fixed' : 'percentage';
    $value = (float) ($in['value'] ?? 0);
    if ($value <= 0 || ($type === 'percentage' && $value > 100)) {
        throw new InvalidArgumentException('A discount is above zero, and at most 100 percent.');
    }
    if (inc_one('SELECT id FROM inc_ev_coupons WHERE event_id = ? AND code = ?', 'is', [$eventId, $code])) {
        throw new InvalidArgumentException('This event already has that code.');
    }
    $max = trim((string) ($in['max_redemptions'] ?? ''));
    $exp = trim((string) ($in['expires_on'] ?? ''));
    inc_exec('INSERT INTO inc_ev_coupons (event_id, code, type, value, max_redemptions, referrer, expires_on) VALUES (?,?,?,?,?,?,?)', 'issdiss',
             [$eventId, mb_substr($code, 0, 40), $type, $value, $max !== '' ? (int) $max : null, inc_str($in['referrer'] ?? '', 120), $exp !== '' ? $exp : null]);
}

function eo_coupon_toggle(int $eventId, int $id): void
{
    eo_need_work();
    inc_exec('UPDATE inc_ev_coupons SET active = 1 - active WHERE id = ? AND event_id = ?', 'ii', [$id, $eventId]);
}

/** Why a coupon cannot be used, or ''. */
function eo_coupon_problem(?array $c): string
{
    if (!$c) {
        return 'That code is not valid for this event.';
    }
    if ((int) $c['active'] !== 1) {
        return 'This coupon is no longer active.';
    }
    if ($c['expires_on'] && $c['expires_on'] < date('Y-m-d')) {
        return 'This coupon has expired.';
    }
    if ($c['max_redemptions'] !== null && (int) $c['redeemed'] >= (int) $c['max_redemptions']) {
        return 'This coupon has reached its limit.';
    }
    return '';
}

/* ================================================================ orders */

function eo_order_url(array $o): string
{
    return eo_site_url('event-pay.php?t=' . $o['token']);
}

function eo_order(string $token): ?array
{
    return preg_match('/^[a-f0-9]{24}$/', $token) ? inc_one('SELECT * FROM inc_ev_orders WHERE token = ?', 's', [$token]) : null;
}

function eo_order_by_id(int $id): ?array
{
    return inc_one('SELECT o.*, e.name AS event_name FROM inc_ev_orders o JOIN inc_ev_events e ON e.id = o.event_id WHERE o.id = ?', 'i', [$id]);
}

/** The order for an application, made once at the event's price. */
function eo_order_open(array $ev, array $app): array
{
    $has = inc_one('SELECT * FROM inc_ev_orders WHERE event_id = ? AND application_id = ?', 'ii', [(int) $ev['id'], (int) $app['id']]);
    if ($has) {
        return $has;
    }
    $token = bin2hex(random_bytes(12));
    inc_exec('INSERT INTO inc_ev_orders (event_id, application_id, token, name, email, amount, currency) VALUES (?,?,?,?,?,?,?)', 'iisssds',
             [(int) $ev['id'], (int) $app['id'], $token, (string) $app['applicant_name'], (string) $app['applicant_email'], (float) $ev['price'], (string) $ev['currency']]);
    return eo_order($token);
}

/** Applies a coupon to an unpaid order, from the event's full price. Returns the problem, or ''. */
function eo_order_coupon(array $o, string $code): string
{
    if ($o['status'] !== 'pending') {
        return 'This order is already closed.';
    }
    $ev = eo_event((int) $o['event_id']);
    $c = inc_one('SELECT * FROM inc_ev_coupons WHERE event_id = ? AND code = ?', 'is', [(int) $o['event_id'], strtoupper(trim($code))]);
    $why = eo_coupon_problem($c);
    if ($why !== '') {
        return $why;
    }
    [$off, $final] = eo_discount((float) $ev['price'], (string) $c['type'], (float) $c['value']);
    inc_exec('UPDATE inc_ev_orders SET amount = ?, discount = ?, coupon_id = ? WHERE id = ?', 'ddii', [$final, $off, (int) $c['id'], (int) $o['id']]);
    return '';
}

/** Chooses how to pay. Holds the seat; for Stripe, returns the address to pay at. */
function eo_order_pay(array $o, string $method, string $bankRef = ''): ?string
{
    $ev = eo_event((int) $o['event_id']);
    if ($o['status'] !== 'pending') {
        throw new InvalidArgumentException('This order is already closed.');
    }
    if (!in_array($method, ['stripe', 'bank', 'cash'], true)) {
        throw new InvalidArgumentException('Choose how you would like to pay.');
    }
    if ($method === 'stripe' && !eo_stripe_ready()) {
        throw new InvalidArgumentException('Card payment is not available. Please choose another way to pay.');
    }
    if (eo_ticket_grant($ev, (int) $o['application_id'], 'reserved', false) === 'full') {
        throw new InvalidArgumentException('This event has just sold out.');
    }
    inc_exec('UPDATE inc_ev_orders SET method = ?, bank_ref = ? WHERE id = ?', 'ssi', [$method, $bankRef !== '' ? mb_substr($bankRef, 0, 120) : null, (int) $o['id']]);
    if ($method !== 'stripe') {
        eo_mail((string) $o['email'], 'Your seat is held: ' . $ev['name'], (string) $o['name'],
                ['Your seat at ' . $ev['name'] . ' is held. Your ticket will come as soon as the organizers confirm your payment of ' . eo_money((float) $o['amount'], (string) $o['currency']) . '.']);
        return null;
    }
    return eo_stripe_session($o, $ev);
}

function eo_money(float $n, string $currency): string
{
    return ($currency === 'INR' ? '₹' : $currency . ' ') . number_format($n, 2);
}

/** The money is in: the ticket is confirmed, once, whoever says so first. */
function eo_order_paid(array $o, string $intent = '', string $receipt = ''): void
{
    if (inc_exec('UPDATE inc_ev_orders SET status = "paid", paid_at = NOW(), stripe_intent = COALESCE(NULLIF(?, ""), stripe_intent), receipt_url = COALESCE(NULLIF(?, ""), receipt_url)
                   WHERE id = ? AND status = "pending"', 'ssi', [$intent, $receipt, (int) $o['id']]) < 1) {
        return;
    }
    if ($o['coupon_id']) {
        inc_exec('UPDATE inc_ev_coupons SET redeemed = redeemed + 1 WHERE id = ?', 'i', [(int) $o['coupon_id']]);
    }
    $ev = eo_event((int) $o['event_id']);
    eo_ticket_grant($ev, (int) $o['application_id']);
    eo_webhook_dispatch('order.paid', ['event_id' => (int) $o['event_id'], 'amount' => (float) $o['amount'], 'currency' => $o['currency'], 'email' => $o['email']]);
}

/** An organizer confirms a bank or cash payment. */
function eo_order_confirm(int $id): void
{
    eo_need_work();
    $o = eo_order_by_id($id);
    if (!$o || $o['status'] !== 'pending') {
        throw new InvalidArgumentException('That order is not waiting for payment.');
    }
    eo_order_paid($o);
    inc_audit('paid', 'order', $id, $o['email'] . ' ' . $o['amount']);
}

/** An organizer turns a payment down: the seat goes back. */
function eo_order_reject(int $id): void
{
    eo_need_work();
    $o = eo_order_by_id($id);
    if (!$o || $o['status'] !== 'pending') {
        throw new InvalidArgumentException('That order is not waiting for payment.');
    }
    inc_exec('UPDATE inc_ev_orders SET status = "failed" WHERE id = ?', 'i', [$id]);
    $k = inc_one('SELECT * FROM inc_ev_tickets WHERE event_id = ? AND application_id = ? AND status <> "cancelled"', 'ii', [(int) $o['event_id'], (int) $o['application_id']]);
    if ($k) {
        eo_ticket_release((int) $o['event_id'], $k);
    }
    inc_audit('rejected', 'order', $id, (string) $o['email']);
}

/** Gives the money back: through Stripe when it came that way. Takes the ticket back too. */
function eo_order_refund(int $id, ?float $amount = null): void
{
    eo_need_work();
    $o = eo_order_by_id($id);
    if (!$o || $o['status'] !== 'paid') {
        throw new InvalidArgumentException('Only a paid order can be refunded.');
    }
    $amount = $amount === null || $amount <= 0 ? (float) $o['amount'] : min($amount, (float) $o['amount']);
    if ($o['method'] === 'stripe' && $o['stripe_intent']) {
        eo_stripe_refund((string) $o['stripe_intent'], (int) round($amount * 100));
    }
    inc_exec('UPDATE inc_ev_orders SET status = "refunded", refund_amount = ?, refunded_at = NOW() WHERE id = ?', 'di', [$amount, $id]);
    $k = inc_one('SELECT * FROM inc_ev_tickets WHERE event_id = ? AND application_id = ? AND status <> "cancelled"', 'ii', [(int) $o['event_id'], (int) $o['application_id']]);
    if ($k) {
        eo_ticket_release((int) $o['event_id'], $k);
    }
    inc_audit('refund', 'order', $id, $o['email'] . ' ' . $amount);
    eo_mail((string) $o['email'], 'Refund: ' . $o['event_name'], (string) $o['name'], ['We have refunded ' . eo_money($amount, (string) $o['currency']) . ' for ' . $o['event_name'] . '.']);
}

function eo_orders(array $f = [], int $limit = 500): array
{
    $w = '1=1';
    $t = '';
    $p = [];
    if (!empty($f['event_id'])) {
        $w .= ' AND o.event_id = ?';
        $t .= 'i';
        $p[] = (int) $f['event_id'];
    }
    if (!empty($f['status'])) {
        $w .= ' AND o.status = ?';
        $t .= 's';
        $p[] = (string) $f['status'];
    }
    return inc_all('SELECT o.*, e.name AS event_name FROM inc_ev_orders o JOIN inc_ev_events e ON e.id = o.event_id WHERE ' . $w . ' ORDER BY o.id DESC LIMIT ' . (int) $limit, $t, $p);
}

/** Money taken: [gross, refunded, net, paid orders, pending orders]. */
function eo_revenue(?int $eventId = null): array
{
    $w = $eventId ? ' AND event_id = ' . (int) $eventId : '';
    $r = inc_one('SELECT COALESCE(SUM(CASE WHEN status IN ("paid","refunded") THEN amount END), 0) AS gross, COALESCE(SUM(refund_amount), 0) AS refunded,
                         COUNT(CASE WHEN status = "paid" THEN 1 END) AS paid, COUNT(CASE WHEN status = "pending" THEN 1 END) AS pending
                    FROM inc_ev_orders WHERE 1=1' . $w);
    return [(float) $r['gross'], (float) $r['refunded'], (float) $r['gross'] - (float) $r['refunded'], (int) $r['paid'], (int) $r['pending']];
}

/* ================================================================ Stripe */

function eo_env(string $key): string
{
    $v = getenv($key);
    return is_string($v) ? trim($v) : '';
}

function eo_stripe_ready(): bool
{
    return eo_env('STRIPE_SECRET_KEY') !== '';
}

/** One call to Stripe. A test swaps it through $GLOBALS['eo_http_hook']. */
function eo_stripe_call(string $path, array $form): array
{
    if (isset($GLOBALS['eo_http_hook'])) {
        return ($GLOBALS['eo_http_hook'])('stripe', $path, $form);
    }
    require_once __DIR__ . '/../google.php';
    [$ok, $body] = fabo_http('POST', 'https://api.stripe.com/v1/' . $path, http_build_query($form),
                             ['Authorization: Bearer ' . eo_env('STRIPE_SECRET_KEY'), 'Content-Type: application/x-www-form-urlencoded']);
    if (!$ok || !is_array($body)) {
        error_log('FABO organizers stripe: ' . (is_string($body) ? $body : 'no answer'));
        throw new InvalidArgumentException('The card service did not answer. Please try again.');
    }
    return $body;
}

function eo_stripe_session(array $o, array $ev): string
{
    $back = eo_order_url($o);
    $s = eo_stripe_call('checkout/sessions', [
        'mode' => 'payment', 'customer_email' => $o['email'], 'success_url' => $back . '&paid=1', 'cancel_url' => $back,
        'line_items' => [['quantity' => 1, 'price_data' => ['currency' => strtolower((string) $o['currency']), 'unit_amount' => (int) round((float) $o['amount'] * 100),
                                                            'product_data' => ['name' => mb_substr((string) $ev['name'], 0, 120)]]]],
        'metadata' => ['org' => inc_org(), 'order' => $o['token']],
    ]);
    if (empty($s['id']) || empty($s['url'])) {
        throw new InvalidArgumentException('The card service did not answer. Please try again.');
    }
    inc_exec('UPDATE inc_ev_orders SET stripe_session = ? WHERE id = ?', 'si', [$s['id'], (int) $o['id']]);
    return (string) $s['url'];
}

function eo_stripe_refund(string $intent, int $amountMinor): void
{
    eo_stripe_call('refunds', ['payment_intent' => $intent, 'amount' => $amountMinor]);
}

/** Is this Stripe webhook really from Stripe, and recent? */
function eo_stripe_verify(string $payload, string $header, string $secret, ?int $now = null): bool
{
    $t = 0;
    $sigs = [];
    foreach (explode(',', $header) as $part) {
        [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
        if ($k === 't') {
            $t = (int) $v;
        } elseif ($k === 'v1') {
            $sigs[] = $v;
        }
    }
    if (!$t || !$sigs || abs(($now ?? time()) - $t) > 300) {
        return false;
    }
    $want = hash_hmac('sha256', $t . '.' . $payload, $secret);
    foreach ($sigs as $s) {
        if (hash_equals($want, $s)) {
            return true;
        }
    }
    return false;
}

/* ========================================================== waiting list */

function eo_waitlist(int $eventId): array
{
    return inc_all('SELECT * FROM inc_ev_waitlist WHERE event_id = ? ORDER BY position', 'i', [$eventId]);
}

/** Joins the queue for a full event. Returns the person's place. */
function eo_waitlist_join(array $ev, string $name, string $email, string $phone = ''): int
{
    $email = mb_strtolower(trim($email));
    $name = trim($name);
    if ((int) $ev['waitlist_enabled'] !== 1) {
        throw new InvalidArgumentException('This event has no waiting list.');
    }
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Please give your name and a valid email address.');
    }
    $has = inc_one('SELECT position FROM inc_ev_waitlist WHERE event_id = ? AND email = ? AND status = "waiting"', 'is', [(int) $ev['id'], $email]);
    if ($has) {
        return (int) $has['position'];
    }
    $pos = (int) (inc_one('SELECT MAX(position) AS n FROM inc_ev_waitlist WHERE event_id = ?', 'i', [(int) $ev['id']])['n'] ?? 0) + 1;
    inc_exec('INSERT INTO inc_ev_waitlist (event_id, name, email, phone, position) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE status = "waiting", position = VALUES(position), notified_at = NULL', 'isssi',
             [(int) $ev['id'], mb_substr($name, 0, 190), $email, $phone !== '' ? mb_substr($phone, 0, 40) : null, $pos]);
    return $pos;
}

/** A seat came free: the next person in line hears first. */
function eo_waitlist_promote(int $eventId): void
{
    $ev = eo_event($eventId);
    if (!$ev || (int) $ev['waitlist_enabled'] !== 1 || !empty($ev['cancelled_at'])) {
        return;
    }
    $next = inc_one('SELECT * FROM inc_ev_waitlist WHERE event_id = ? AND status = "waiting" ORDER BY position LIMIT 1', 'i', [$eventId]);
    if (!$next) {
        return;
    }
    inc_exec('UPDATE inc_ev_waitlist SET status = "notified", notified_at = NOW() WHERE id = ?', 'i', [(int) $next['id']]);
    eo_notice((string) $next['email'], 'A spot opened up!', 'A spot is now available for ' . $ev['name'] . '. Register before it is taken.', eo_public_url($ev));
    eo_mail((string) $next['email'], 'A spot opened up: ' . $ev['name'], (string) $next['name'],
            ['A spot is now available for ' . $ev['name'] . ' on ' . eo_dates_text($ev) . '. Register now before it is taken.'], eo_public_url($ev), 'Register now');
}

/** Whoever was told and did not come in 48 hours loses the turn to the next person. */
function eo_waitlist_expire(): int
{
    $rows = inc_all('SELECT id, event_id FROM inc_ev_waitlist WHERE status = "notified" AND notified_at < NOW() - INTERVAL 48 HOUR LIMIT 50');
    foreach ($rows as $r) {
        inc_exec('UPDATE inc_ev_waitlist SET status = "expired" WHERE id = ?', 'i', [(int) $r['id']]);
        eo_waitlist_promote((int) $r['event_id']);
    }
    return count($rows);
}

/* ================================================================= plans */

/*
 * A plan limits what an organisation may do here: seats per event, and whether
 * it has mentors, AI reports and the Excel export. Plans are the administrator's,
 * so these two tables are shared. An organisation with no plan has no limits:
 * nothing is taken from anyone until the administrator assigns one.
 */
function eo_plans_install(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    fabo_db()->query("CREATE TABLE IF NOT EXISTS eo_plans (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, description VARCHAR(255) NULL, price DECIMAL(12,2) NOT NULL DEFAULT 0, currency VARCHAR(3) NOT NULL DEFAULT 'INR',
      billing ENUM('monthly','yearly','custom') NOT NULL DEFAULT 'monthly', max_events INT UNSIGNED NULL, max_attendees INT UNSIGNED NULL, mentors TINYINT(1) NOT NULL DEFAULT 1, ai_reports TINYINT(1) NOT NULL DEFAULT 1, excel TINYINT(1) NOT NULL DEFAULT 1, active TINYINT(1) NOT NULL DEFAULT 1) $t");
    fabo_db()->query("CREATE TABLE IF NOT EXISTS eo_subscriptions (org_id INT UNSIGNED NOT NULL PRIMARY KEY, plan_id INT UNSIGNED NOT NULL, status ENUM('active','trial','expired','cancelled') NOT NULL DEFAULT 'active', period_end DATE NULL,
      custom_max_events INT UNSIGNED NULL, custom_max_attendees INT UNSIGNED NULL, notes VARCHAR(255) NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) $t");
}

function eo_plans(): array
{
    eo_plans_install();
    return fabo_all('SELECT * FROM eo_plans ORDER BY price, id');
}

function eo_plan_save(?int $id, array $in): void
{
    eo_plans_install();
    $num = static fn($k) => trim((string) ($in[$k] ?? '')) !== '' ? (int) $in[$k] : null;
    $billing = in_array($in['billing'] ?? '', ['monthly', 'yearly', 'custom'], true) ? $in['billing'] : 'monthly';
    $vals = [inc_str($in['name'] ?? '', 120, true, 'Plan name'), inc_str($in['description'] ?? '', 255), (float) ($in['price'] ?? 0), $billing, $num('max_events'), $num('max_attendees'),
             !empty($in['mentors']) ? 1 : 0, !empty($in['ai_reports']) ? 1 : 0, !empty($in['excel']) ? 1 : 0];
    if ($id) {
        fabo_exec('UPDATE eo_plans SET name=?, description=?, price=?, billing=?, max_events=?, max_attendees=?, mentors=?, ai_reports=?, excel=? WHERE id=?', 'ssdsiiiiii', array_merge($vals, [$id]));
    } else {
        fabo_exec('INSERT INTO eo_plans (name, description, price, billing, max_events, max_attendees, mentors, ai_reports, excel) VALUES (?,?,?,?,?,?,?,?,?)', 'ssdsiiiii', $vals);
    }
}

/** Assigns a plan to an organisation, or takes it away ($planId 0). */
function eo_plan_assign(int $org, int $planId, array $in = []): void
{
    eo_plans_install();
    if ($planId <= 0) {
        fabo_exec('DELETE FROM eo_subscriptions WHERE org_id = ?', 'i', [$org]);
        return;
    }
    $num = static fn($k) => trim((string) ($in[$k] ?? '')) !== '' ? (int) $in[$k] : null;
    fabo_exec('INSERT INTO eo_subscriptions (org_id, plan_id, status, period_end, custom_max_events, custom_max_attendees) VALUES (?,?,?,?,?,?)
               ON DUPLICATE KEY UPDATE plan_id = VALUES(plan_id), status = VALUES(status), period_end = VALUES(period_end), custom_max_events = VALUES(custom_max_events), custom_max_attendees = VALUES(custom_max_attendees)',
              'iissii', [$org, $planId, in_array($in['status'] ?? '', ['active', 'trial', 'expired', 'cancelled'], true) ? $in['status'] : 'active',
                         ($in['period_end'] ?? '') !== '' ? $in['period_end'] : null, $num('custom_max_events'), $num('custom_max_attendees')]);
}

/** The organisation's plan with its limits, or null for none. */
function eo_plan_of(?int $org = null): ?array
{
    eo_plans_install();
    return fabo_one('SELECT p.*, s.status AS sub_status, s.period_end, s.custom_max_events, s.custom_max_attendees FROM eo_subscriptions s JOIN eo_plans p ON p.id = s.plan_id WHERE s.org_id = ?', 'i', [$org ?? inc_org()]);
}

function eo_plan_capacity_check(int $seats): void
{
    $p = eo_plan_of();
    $max = $p ? ($p['custom_max_attendees'] ?? $p['max_attendees']) : null;
    if ($max !== null && $seats > (int) $max) {
        throw new InvalidArgumentException('Your ' . $p['name'] . ' plan allows up to ' . (int) $max . ' attendees per event.');
    }
}

/** Does the plan include it? 'mentors' | 'ai_reports' | 'excel'. No plan means yes. */
function eo_plan_has(string $feature): bool
{
    $p = eo_plan_of();
    return !$p || (int) $p[$feature] === 1;
}

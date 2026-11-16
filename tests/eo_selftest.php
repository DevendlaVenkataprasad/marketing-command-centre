<?php
/**
 * Event Organizers self-test, in a THROWAWAY database: php tests/eo_selftest.php
 * Refuses to run unless the database is named eo_test. Never deploy this.
 * Stripe, messages, webhooks and AI are replaced by fakes: nothing leaves the machine.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
chdir(dirname(__DIR__));
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SCRIPT_NAME'] = '/organizers.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once 'lib/ui.php';
require_once 'lib/eo/app.php';
if (DB_NAME !== 'eo_test') {
    fwrite(STDERR, "Refusing: this test only runs on a database named eo_test.\n");
    exit(2);
}
fabo_boot();

$pass = 0;
$fail = [];
function ok(bool $c, string $what): void
{
    global $pass, $fail;
    if ($c) {
        $pass++;
    } else {
        $fail[] = $what;
        echo "FAIL  $what\n";
    }
}
function eq($got, $want, string $what): void
{
    ok($got == $want, $what . ' (got ' . json_encode($got) . ', want ' . json_encode($want) . ')');
}
function refused(callable $fn, string $what): void
{
    try {
        $fn();
        ok(false, $what . ' (was allowed)');
    } catch (Throwable $e) {
        ok(true, $what);
    }
}
$ahead = static fn(int $d) => date('Y-m-d', strtotime("+$d days"));
$ago = static fn(int $d) => date('Y-m-d', strtotime("-$d days"));

/* ---------------------------------------------------------------- reset */
fabo_exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (fabo_all('SHOW TABLES') as $r) {
    $t = array_values($r)[0];
    if (preg_match('/^(eo_|inc_)/', $t)) {
        fabo_exec("DROP TABLE `$t`");
    }
}
foreach (['events', 'approvals', 'client_sheet_access', 'clients'] as $t) {
    fabo_exec("DELETE FROM `$t`");
}
fabo_exec('SET FOREIGN_KEY_CHECKS = 1');

function person(string $name, string $kind, ?int $parent, int $id): int
{
    fabo_exec('INSERT INTO clients (id, name, kind, email, token, parent_id, organisation, password_hash) VALUES (?,?,?,?,?,?,?,?)', 'issssiss',
              [$id, $name, $kind, strtolower(str_replace(' ', '.', $name)) . '@example.test', bin2hex(random_bytes(16)), $parent, 'Org ' . ($parent ?: $id), 'x']);
    return $id;
}
const ORG = 77;
const OTHER = 88;
const OLD = 99;
person('Hub Owner', 'client', null, ORG);
person('Other Head', 'client', null, OTHER);
person('Old Head', 'client', null, OLD);
$organizer = person('Olga Organizer', 'team', ORG, 701);
$reader = person('Vikram Viewer', 'team', ORG, 702);
$teamOnly = person('Tina Team', 'team', ORG, 703);
$otherDept = person('Dev Department', 'team', ORG, 704);
$nobody = person('Nobody Special', 'team', ORG, 705);
$grant = static function (int $id, string $key, string $perm = 'edit'): void {
    fabo_exec('INSERT INTO client_sheet_access (client_id, sheet_key, permission) VALUES (?,?,?)', 'iss', [$id, $key, $perm]);
};
foreach ([ORG, OLD] as $o) {
    $grant($o, 'organizers');
    $grant($o, 'incubation');
}
$grant($organizer, 'organizers');
$grant($reader, 'organizers', 'view');
$viewer = static fn(int $id): array => fabo_person_viewer(fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [$id]));

function event(string $name, ?string $start, ?string $end, ?int $client, ?string $state, string $signoff = ''): int
{
    $id = fabo_exec('INSERT INTO `events` (event_id, event_name, start_date, end_date, location, client_id, approval_status) VALUES (?,?,?,?,?,?,?)', 'sssssis',
                    ['EVT-' . bin2hex(random_bytes(3)), $name, $start, $end, 'Amaravati', $client, $signoff]);
    if ($state) {
        fabo_exec('INSERT INTO approvals (sheet_key, row_id, state) VALUES ("events",?,?)', 'is', [$id, $state]);
    }
    return $id;
}
$approved = event('Demo Day', $ahead(10), $ahead(11), ORG, 'approved');
$pending = event('Still Waiting', $ahead(12), $ahead(12), ORG, 'pending');
event('Turned Down', $ahead(12), $ahead(12), ORG, 'rejected');
$signedOff = event('Signed Off Earlier', $ago(30), $ago(30), ORG, null, 'Approved');
event('Rejected With Stale Sign-off', $ahead(5), $ahead(5), ORG, 'rejected', 'Approved');
event('Another Org Event', $ahead(9), $ahead(9), OTHER, 'approved');

$GLOBALS['mails'] = [];
$GLOBALS['inc_mail_hook'] = static function ($to, $s, $t) {
    $GLOBALS['mails'][] = [$to, $s, $t];
    return [true, ''];
};
$GLOBALS['calls'] = [];
$GLOBALS['eo_http_hook'] = static function (string $kind, $what, $data) {
    $GLOBALS['calls'][] = [$kind, $what, $data];
    return $kind === 'stripe' ? ['id' => 'cs_test_1', 'url' => 'https://stripe.test/pay'] : [];
};

/* ------------------------------------------------- tables */
inc_use_org(ORG);
eq(inc_sql('SELECT * FROM inc_forms f JOIN inc_ev_forms x'), 'SELECT * FROM inc_o77_forms f JOIN eo_o77_ev_forms x', 'forms are the main ones, the event layer its own');
inc_use_org(0);
eq(inc_sql('SELECT * FROM inc_forms f JOIN inc_ev_forms x'), 'SELECT * FROM inc_forms f JOIN eo_ev_forms x', 'the same for us');
ok(!inc_org_enabled(OTHER, 'eo'), 'an organisation without the grant has no Event Organizers');

/* ------------------------------------------------------------------- sync */
inc_use_kind('eo');
inc_start($viewer(ORG));
eo_begin();
ob_end_clean();
$GLOBALS['inc_ctx']['grant'] = 'edit';
eo_sync();
eq(array_column(inc_all('SELECT name FROM inc_ev_events ORDER BY name'), 'name'), ['Demo Day', 'Signed Off Earlier'], 'only the organisation\'s approved events arrive');
$eid = (int) inc_one('SELECT id FROM inc_ev_events WHERE source_id = ?', 'i', [$approved])['id'];
fabo_exec('UPDATE `events` SET event_name = "Demo Day 2026" WHERE id = ?', 'i', [$approved]);
eq(eo_sync()['changed'], 1, 'a change on the sheet is picked up');
fabo_exec('UPDATE approvals SET state = "approved" WHERE sheet_key = "events" AND row_id = ?', 'i', [$pending]);
eq(eo_sync()['added'], 1, 'approving an event brings it in');
fabo_exec('UPDATE approvals SET state = "pending" WHERE sheet_key = "events" AND row_id = ?', 'i', [$pending]);
eq(eo_sync()['withdrawn'], 1, 'taking the approval away takes it out');

/* ---------------------------------------------- one pipeline */
$save = ['capacity' => '2', 'mode' => 'hybrid', 'venue' => 'Hall 1', 'reg_deadline' => $ahead(9), 'approval_required' => '1', 'waitlist_enabled' => '1', 'reminders_enabled' => '1'];
eo_event_save($eid, $save);
$fid = eo_form_create($eid, '');
$ver = inc_one('SELECT * FROM inc_form_versions WHERE form_id = ? ORDER BY id DESC LIMIT 1', 'i', [$fid]);
$fields = array_column(inc_all('SELECT uid, maps_to FROM inc_form_fields WHERE version_id = ?', 'i', [(int) $ver['id']]), 'maps_to', 'uid');
eq($fields['participation'] ?? '', 'applicant_type', 'the participation question fills the type of participant');
ok(!isset($fields['event_name']) && !isset($fields['spoke']), 'the form drops what the event already answers');
inc_form_publish($fid);
inc_exec('UPDATE inc_forms SET public_enabled = 1 WHERE id = ?', 'i', [$fid]);
$open = inc_form($fid);
$pub = inc_form_published($fid);
$apply = static function (string $name, string $as = 'Attendee') use (&$open, &$pub): array {
    $raw = ['applicant_name' => $name, 'applicant_email' => strtolower($name) . '@example.test', 'applicant_phone' => '9876543210', 'participation' => $as];
    $done = inc_app_submit($open, $pub, $raw, [], ['channel' => 'public', 'ticket' => bin2hex(random_bytes(8)), 'ip' => '127.0.0.1']);
    $done['next'] = eo_after_submit($done, $open);
    return $done;
};
$r1 = $apply('Asha', 'Speaker');
$a1 = (int) $r1['id'];
$a2 = (int) $apply('Bala')['id'];
$a3 = (int) $apply('Chetan')['id'];
$label = eo_event($eid)['label'];
$row = fabo_one('SELECT a.event_name, m.name AS type FROM inc_o77_applications a JOIN inc_o77_masters m ON m.id = a.applicant_type_id WHERE a.id = ?', 'i', [$a1]);
eq($row, ['event_name' => $label, 'type' => 'Speaker'], 'the application is in the MAIN tables with its program name and participant type');
eq(count(eo_attendees($eid)), 3, 'attendees come from the event\'s forms');

// The main pipeline, as Incubation sees it.
inc_use_kind('inc');
inc_start($viewer(ORG));
$GLOBALS['inc_ctx']['grant'] = 'edit';
$ids = inc_app_ids_matching(inc_app_filters([]));
ok(in_array($a1, $ids, true), 'Incubation\'s main pipeline shows the event application');
require_once 'lib/inc/board.php';
$cols = inc_board_apps([]);
$html = inc_board_html($cols, 'app', static fn($s) => '#', '', null);
ok(str_contains($html, 'kb-prog') && str_contains($html, e($label)), 'the board card shows the program name');
ok(str_contains($html, 'title="Type of participant">Speaker'), 'the board card shows the type of participant');
eq(count(inc_app_ids_matching(inc_app_filters(['event' => $label]))), 3, 'the board filters by program');

// A non-event application: Incubation has it, Organizers does not.
$plain = inc_form_create(['name' => 'Walk in', 'purpose' => 'walk_in']);
inc_exec('UPDATE inc_form_fields SET active = 0 WHERE required = 1 AND uid NOT IN ("applicant_name","applicant_email","applicant_phone") AND version_id = (SELECT id FROM inc_form_versions WHERE form_id = ? LIMIT 1)', 'i', [$plain]);
inc_form_publish($plain);
inc_exec("UPDATE inc_forms SET public_enabled = 1 WHERE id = ?", "i", [$plain]);
$done = inc_app_submit(inc_form($plain), inc_form_published($plain), ['applicant_name' => 'Zed', 'applicant_email' => 'zed@example.test', 'applicant_phone' => '9876543211'], [], ['channel' => 'public', 'ticket' => bin2hex(random_bytes(8)), 'ip' => '127.0.0.1']);
$walkIn = (int) $done['id'];
ok((bool) inc_app($walkIn), 'Incubation opens its own application');
inc_use_kind('eo');
inc_start($viewer(ORG));
$GLOBALS['inc_ctx']['grant'] = 'edit';
ok(!inc_app($walkIn), 'Organizers cannot open an Incubation application');
ok(!in_array($walkIn, inc_app_ids_matching(inc_app_filters([])), true), 'nor see it on its board');
ok(in_array($a1, inc_app_ids_matching(inc_app_filters([])), true), 'but sees the event ones');
ok(!in_array('Walk in', array_column(inc_forms_list(), 'name'), true), 'nor list its forms');
refused(static fn() => inc_stage_post('stage_add'), 'Organizers cannot change the main pipeline\'s stages');
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['ids' => [$a1, $walkIn]];
eo_scope('applications');
eq($_POST['ids'], [$a1], 'a crafted bulk action loses the application that is not an event\'s');
$_SERVER['REQUEST_METHOD'] = 'GET';
$_POST = [];

/* ---------------------------------------------------------------- tickets */
$GLOBALS['mails'] = [];
$r = eo_tickets_issue($eid, [$a1, $a2, $a3]);
eq([$r['issued'], $r['full'], $r['mailed']], [2, 1, 2], 'seats stop the third ticket');
ok(str_contains($GLOBALS['mails'][0][2], 'event-ticket.php?t='), 'the email carries the ticket link');
eq(inc_one('SELECT status FROM inc_ev_regs WHERE application_id = ?', 'i', [$a1])['status'], 'approved', 'a ticket approves the registration');
eq(eo_tickets_issue($eid, [$walkIn])['issued'], 0, 'an application that is not the event\'s is skipped');
eq(eo_closed_reason(eo_event($eid)), 'This event is full.', 'a full event stops taking applications');
$t1 = inc_one('SELECT * FROM inc_ev_tickets WHERE application_id = ?', 'i', [$a1]);
$t2 = inc_one('SELECT * FROM inc_ev_tickets WHERE application_id = ?', 'i', [$a2]);

/* ----------------------------------------------------------- waiting list */
eq(eo_waitlist_join(eo_event($eid), 'Wes', 'wes@example.test'), 1, 'the first in line is number one');
eq(eo_waitlist_join(eo_event($eid), 'Wes', 'wes@example.test'), 1, 'joining twice keeps the place');
eq(eo_waitlist_join(eo_event($eid), 'Xia', 'xia@example.test'), 2, 'the next is number two');
$GLOBALS['mails'] = [];
eo_ticket_cancel($eid, (int) $t2['id']);
eq($GLOBALS['mails'][0][0] ?? '', 'wes@example.test', 'a freed seat goes to the first in line');
eq(inc_one('SELECT status FROM inc_ev_waitlist WHERE email = "wes@example.test"')['status'], 'notified', 'who is marked as told');
inc_exec('UPDATE inc_ev_waitlist SET notified_at = NOW() - INTERVAL 3 DAY WHERE email = "wes@example.test"');
eq(eo_waitlist_expire(), 1, 'a turn left unused for two days passes on');
eq(inc_one('SELECT status FROM inc_ev_waitlist WHERE email = "xia@example.test"')['status'], 'notified', 'to the next person');

/* --------------------------------------------------------------- check-in */
eq(eo_checkin($eid, eo_ticket_url($t1['code']), 1)['state'], 'ok', 'scanning the link checks in');
eq(eo_checkin($eid, $t1['code'], 1)['state'], 'again', 'a second scan the same day is caught');
eq(eo_checkin($eid, $t1['code'], 2)['state'], 'ok', 'the next day is a new check-in');
eq(eo_checkin($eid, str_repeat('a', 24), 1)['state'], 'unknown', 'a made-up code is refused');
eq(eo_checkin($eid, $t2['code'], 1)['state'], 'cancelled', 'a cancelled ticket does not get in');
eq(count(eo_roster($eid)), 1, 'the offline roster holds only tickets that may get in');

// Offline: a scan arrives late, and may arrive twice.
$a4 = (int) $apply('Dia')['id'];
eo_event_save($eid, ['capacity' => '9'] + $save);
eo_tickets_issue($eid, [$a4]);
$t4 = inc_one('SELECT * FROM inc_ev_tickets WHERE application_id = ?', 'i', [$a4]);
$at = date('Y-m-d H:i:s', time() - 3600);
eq(eo_checkin($eid, $t4['code'], 1, 'scan-1', $at)['state'], 'ok', 'an offline scan syncs');
ok(!empty(eo_checkin($eid, $t4['code'], 1, 'scan-1', $at)['replay']), 'replaying it does nothing');
eq(inc_one('SELECT checked_at, scanned_offline FROM inc_ev_checkins WHERE client_scan_id = "scan-1"'), ['checked_at' => $at, 'scanned_offline' => 1], 'and it keeps when it really happened');

/* ------------------------------------------------------- paid events */
$paidSrc = event('Paid Summit', $ahead(20), $ahead(20), ORG, 'approved');
eo_sync();
$pid = (int) inc_one('SELECT id FROM inc_ev_events WHERE source_id = ?', 'i', [$paidSrc])['id'];
refused(static fn() => eo_event_save($pid, ['is_paid' => '1', 'price' => '0']), 'a paid event needs a price');
eo_event_save($pid, ['is_paid' => '1', 'price' => '1000', 'capacity' => '1', 'approval_required' => '1', 'waitlist_enabled' => '1']);
$pf = eo_form_create($pid, '');
inc_form_publish($pf);
inc_exec("UPDATE inc_forms SET public_enabled = 1 WHERE id = ?", "i", [$pf]);
$open = inc_form($pf);
$pub = inc_form_published($pf);
$p1 = $apply('Priya');
ok(str_contains((string) $p1['next'], 'event-pay.php?t='), 'a paid event sends the applicant to pay');
$token = substr((string) strstr((string) $p1['next'], 't='), 2, 24);
$o = eo_order($token);
eq((float) $o['amount'], 1000.0, 'the order is at the event\'s price');
eq(eo_discount(1000, 'percentage', 20), [200.0, 800.0], 'a percentage coupon');
eq(eo_discount(10, 'fixed', 50), [9.5, 0.5], 'a discount never passes the smallest charge');
eo_coupon_save($pid, ['code' => 'early', 'type' => 'percentage', 'value' => '20', 'max_redemptions' => '1']);
refused(static fn() => eo_coupon_save($pid, ['code' => 'bad', 'type' => 'percentage', 'value' => '120']), 'a discount over 100 percent is refused');
ok(eo_order_coupon($o, 'nope') !== '', 'an unknown coupon is refused');
eq(eo_order_coupon($o, 'EARLY'), '', 'a good coupon applies');
$o = eo_order($token);
eq((float) $o['amount'], 800.0, 'and lowers the amount');
eq(eo_order_coupon($o, 'early'), '', 'applying it twice does not stack');
eq((float) eo_order($token)['amount'], 800.0, 'the amount stays');
eo_order_pay($o, 'bank', 'REF123');
$k = inc_one('SELECT * FROM inc_ev_tickets WHERE event_id = ?', 'i', [$pid]);
eq($k['status'], 'reserved', 'a bank payment holds the seat');
eq(eo_checkin($pid, $k['code'], 1)['state'], 'unpaid', 'a held seat does not get in');
ok(eo_is_full(eo_event($pid)), 'but it does take the seat');
eo_order_confirm((int) $o['id']);
$k = inc_one('SELECT * FROM inc_ev_tickets WHERE event_id = ?', 'i', [$pid]);
eq($k['status'], 'issued', 'confirming the money confirms the ticket');
eq((int) inc_one('SELECT redeemed FROM inc_ev_coupons WHERE code = "EARLY"')['redeemed'], 1, 'the coupon is counted once paid');
eq(eo_coupon_problem(inc_one('SELECT * FROM inc_ev_coupons WHERE code = "EARLY"')), 'This coupon has reached its limit.', 'and runs out');
eq(eo_revenue($pid)[0], 800.0, 'the money shows as revenue');
eo_order_refund((int) $o['id']);
eq(eo_order($token)['status'], 'refunded', 'a refund closes the order');
eq(inc_one('SELECT status FROM inc_ev_tickets WHERE event_id = ?', 'i', [$pid])['status'], 'cancelled', 'and takes the ticket back');
eq(eo_revenue($pid)[2], 0.0, 'net revenue goes back to nothing');
ok(!eo_is_full(eo_event($pid)), 'and the seat is free');

// Stripe, with a fake Stripe.
putenv('STRIPE_SECRET_KEY=sk_test_x');
$p2 = $apply('Quinn');
$o2 = eo_order(substr((string) strstr((string) $p2['next'], 't='), 2, 24));
eq(eo_order_pay($o2, 'stripe'), 'https://stripe.test/pay', 'card payment goes to Stripe');
$call = end($GLOBALS['calls']);
eq($call[2]['line_items'][0]['price_data']['unit_amount'], 100000, 'Stripe is asked for the amount in paise');
$body = '{"x":1}';
$t = time();
$sig = 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, 'whsec');
ok(eo_stripe_verify($body, $sig, 'whsec'), 'a true Stripe signature passes');
ok(!eo_stripe_verify($body . ' ', $sig, 'whsec'), 'a changed body fails');
ok(!eo_stripe_verify($body, $sig, 'other'), 'a wrong secret fails');
ok(!eo_stripe_verify($body, 't=' . ($t - 900) . ',v1=' . hash_hmac('sha256', ($t - 900) . '.' . $body, 'whsec'), 'whsec'), 'an old signature fails');
$o2 = eo_order($o2['token']);
eo_order_paid($o2, 'pi_1');
eo_order_paid($o2, 'pi_1');
eq((float) eo_revenue($pid)[0], 1800.0, 'a repeated webhook pays only once');
$GLOBALS['calls'] = [];
eo_order_refund((int) $o2['id'], 400);
eq($GLOBALS['calls'][0][2] ?? [], ['payment_intent' => 'pi_1', 'amount' => 40000], 'a card refund goes back through Stripe');
putenv('STRIPE_SECRET_KEY');

/* --------------------------------------------- free event with no review */
$freeSrc = event('Open Meetup', $ahead(15), $ahead(15), ORG, 'approved');
eo_sync();
$fev = (int) inc_one('SELECT id FROM inc_ev_events WHERE source_id = ?', 'i', [$freeSrc])['id'];
eo_event_save($fev, ['reminders_enabled' => '1', 'start_time' => '10:00']);
$ff = eo_form_create($fev, '');
inc_form_publish($ff);
inc_exec("UPDATE inc_forms SET public_enabled = 1 WHERE id = ?", "i", [$ff]);
$open = inc_form($ff);
$pub = inc_form_published($ff);
$m1 = (int) $apply('Rehan')['id'];
eq(inc_one('SELECT status FROM inc_ev_tickets WHERE application_id = ?', 'i', [$m1])['status'], 'issued', 'a free event with no review tickets at once');
inc_exec('UPDATE inc_forms SET block_duplicates = 0 WHERE id = ?', 'i', [$ff]);
$open = inc_form($ff);
for ($i = 0; $i < 6; $i++) {
    $apply('Spam');

}
ok((bool) inc_one('SELECT 1 AS x FROM inc_ev_regs WHERE flagged = 1'), 'five registrations from one email in an hour are flagged');
$flagged = inc_one('SELECT application_id FROM inc_ev_regs WHERE flagged = 1 LIMIT 1');
ok(!inc_one('SELECT 1 AS x FROM inc_ev_tickets WHERE application_id = ?', 'i', [(int) $flagged['application_id']]), 'and a flagged one gets no automatic ticket');
eo_reg_reject($fev, (int) $flagged['application_id']);
eq(inc_one('SELECT status FROM inc_ev_regs WHERE application_id = ?', 'i', [(int) $flagged['application_id']])['status'], 'rejected', 'a registration can be rejected');

/* -------------------------------------------------------------- reminders */
$tomorrow = strtotime(eo_event($fev)['start_date'] . ' 10:00') - 20 * 3600;
$GLOBALS['mails'] = [];
ok(eo_reminders_run($tomorrow) >= 1, 'the day-before reminder goes to ticket holders');
ok(str_contains($GLOBALS['mails'][0][1], 'starts tomorrow'), 'and says so');
eq(eo_reminders_run($tomorrow), 0, 'and only once');
ok(eo_reminders_run(strtotime(eo_event($fev)['start_date'] . ' 09:30')) >= 1, 'the hour-before reminder is its own');

/* ---------------------------------------------------- live Q&A and polls */
eo_session_add($fev, ['title' => 'Keynote', 'starts' => '10:00', 'day_no' => 9]);
$sid = (int) eo_sessions($fev)[0]['id'];
eq((int) eo_sessions($fev)[0]['day_no'], 1, 'a session day stays within the event');
refused(static fn() => eo_session_add($fev, ['title' => 'Bad', 'starts' => '9am']), 'a bad time is refused');
$tk = eo_ticket(inc_one('SELECT code FROM inc_ev_tickets WHERE application_id = ?', 'i', [$m1])['code']);
eo_question_ask($tk, $sid, 'When is lunch?');
$qid = (int) eo_questions($sid)[0]['id'];
eo_question_upvote($tk, $qid);
eo_question_upvote($tk, $qid);
eq((int) eo_questions($sid)[0]['votes'], 1, 'one ticket gives one upvote');
refused(static fn() => eo_question_ask($t1 + ['event_id' => $eid], $sid, 'Sneaky'), 'a ticket for another event cannot ask here');
eo_poll_add($fev, $sid, 'Tea or coffee?', ['Tea', 'Coffee']);
refused(static fn() => eo_poll_add($fev, $sid, 'One?', ['Only']), 'a poll needs two options');
$poll = eo_polls($sid)[0];
eo_poll_vote($tk, (int) $poll['id'], (int) $poll['options'][0]['id']);
eo_poll_vote($tk, (int) $poll['id'], (int) $poll['options'][1]['id']);
$after = eo_polls($sid)[0];
eq([(int) $after['options'][0]['votes'], (int) $after['options'][1]['votes']], [1, 0], 'one ticket gives one vote, and the first stands');
eo_poll_toggle($fev, (int) $poll['id']);
refused(static fn() => eo_poll_vote($tk, (int) $poll['id'], (int) $poll['options'][0]['id']), 'a closed poll takes no votes');

/* ------------------------------------------------ online attendance */
$on = eo_event($fev);
$on['mode'] = 'online';
$on['meeting_url'] = 'https://meet.example.test/x';
$start = strtotime($on['start_date'] . ' 10:00');
ok(eo_join_problem($on, $start - 7200) !== '', 'joining is closed two hours before');
eq(eo_join_problem($on, $start - 600), '', 'and open ten minutes before');
ok(eo_join_problem(['mode' => 'in_person', 'meeting_url' => 'x'] + $on) !== '', 'an in-person event has nothing to join');
eo_online_record($on, (int) $tk['id'], 'a@example.test', 'join-link');
eq(eo_online_count($fev), 1, 'joining online is counted');

/* ------------------------------------------------- mentors and sponsors */
eo_mentor_save(['name' => 'Meena', 'email' => 'meena@example.test']);
$mid = (int) eo_mentors()[0]['id'];
$GLOBALS['mails'] = [];
eo_mentor_assign($fev, $mid, 'Lead');
eq($GLOBALS['mails'][0][0] ?? '', 'meena@example.test', 'an assigned mentor is told');
$as = (int) eo_event_mentors($fev)[0]['id'];
eo_mentor_task_add($fev, $as, ['title' => 'Prep']);
$task = (int) eo_event_mentors($fev)[0]['tasks'][0]['id'];
eo_mentor_task_step($fev, $task);
eq(eo_event_mentors($fev)[0]['tasks'][0]['status'], 'in_progress', 'a task moves on');
refused(static fn() => eo_mentor_task_add($eid, $as, ['title' => 'No']), 'a task needs a mentor of this event');
eo_sponsor_save(['name' => 'Acme', 'website' => 'https://acme.test']);
refused(static fn() => eo_sponsor_save(['name' => 'X', 'website' => 'javascript:alert(1)']), 'a script link is refused');
eo_sponsor_assign($fev, (int) eo_sponsors()[0]['id'], 'gold');
$es = (int) eo_event_sponsors($fev)[0]['id'];
eo_lead_capture($es, $tk['code'], 5, 'Keen');
eo_lead_capture($es, $tk['code'], 4, 'Keener');
eq(count(eo_leads($es)), 1, 'one person is one lead');
refused(static fn() => eo_lead_capture($es, $t1['code'], 5, ''), 'a ticket for another event is not a lead');

/* ------------------------------------------- food tokens and certificates */
ok(eo_food_issue($fev, 2) >= 1, 'food tokens go to ticket holders');
eq(eo_food_issue($fev, 2), 0, 'once');
$food = inc_one('SELECT * FROM inc_ev_food WHERE event_id = ?', 'i', [$fev]);
eq(eo_food_redeem($fev, $food['token'])['left'], 1, 'a meal is taken');
eq(eo_food_redeem($fev, $food['token'])['state'], 'ok', 'and the second');
eq(eo_food_redeem($fev, $food['token'])['state'], 'used', 'and no third');
refused(static fn() => eo_certs_issue($fev), 'no certificates before the event ends');
eo_checkin($fev, $tk['code'], 1);
fabo_exec('UPDATE `events` SET start_date = ?, end_date = ? WHERE id = ?', 'ssi', [$ago(2), $ago(2), $freeSrc]);
eo_sync();
eq(eo_certs_issue($fev), 1, 'whoever came gets a certificate');
eq(eo_certs_issue($fev), 0, 'once');
eo_feedback_save($tk, eo_event($fev), ['rating' => 4, 'comment' => 'Good']);
eo_feedback_save($tk, eo_event($fev), ['rating' => 1]);
eq(array_column(eo_feedback($fev), 'rating'), [4], 'one ticket gives one rating');
eq(eo_feedback($fev)[0]['status'], 'pending', 'and it waits for review before going public');

/* ---------------------------------------------------- staff, API, webhooks */
$url = eo_staff_invite($fev, ['email' => 'vol@example.test', 'role' => 'scanner']);
$staff = eo_staff_by_token(substr((string) strstr($url, 'k='), 2, 40));
eq($staff['role'], 'scanner', 'a staff link carries its role');
refused(static fn() => eo_staff_invite($fev, ['email' => 'r@example.test', 'role' => 'sponsor_rep']), 'a sponsor rep needs a sponsor');
eo_staff_revoke($fev, (int) $staff['id']);
ok(!eo_staff_by_token((string) $staff['token']), 'a closed link stops working');
$key = eo_api_key_create('test');
ok(eo_api_auth($key) && !eo_api_auth($key . 'x'), 'an API key opens the API, a wrong one does not');
ok(!inc_one('SELECT 1 AS x FROM inc_ev_api_keys WHERE hash = ?', 's', [$key]), 'the key itself is never stored');
eo_api_key_revoke((int) eo_api_keys()[0]['id']);
ok(!eo_api_auth($key), 'a revoked key stops working');
refused(static fn() => eo_webhook_save('http://insecure.test', ['order.paid']), 'a webhook must be https');
$secret = eo_webhook_save('https://hook.test', ['ticket.checked_in']);
$GLOBALS['calls'] = [];
eo_webhook_dispatch('order.paid', []);
eq(count($GLOBALS['calls']), 0, 'a webhook hears only what it asked for');
eo_webhook_dispatch('ticket.checked_in', ['a' => 1]);
$c = $GLOBALS['calls'][0];
eq($c[2]['signature'], hash_hmac('sha256', $c[2]['body'], $secret), 'each call is signed with its secret');

/* ------------------------------------------- messages, cancelling, accounts */
eq(eo_phone('98765 43210'), '919876543210', 'a ten-digit number gets the country code');
eq(eo_phone('123'), '', 'a short one is refused');
putenv('WHATSAPP_TOKEN=x');
putenv('WHATSAPP_PHONE_NUMBER_ID=1');
eo_message_queue(eo_event($fev), '9876543210', 'ticket', 'Hello');
$GLOBALS['calls'] = [];
eq(eo_messages_process(), 1, 'a queued message goes');
eq($GLOBALS['calls'][0][1] ?? '', 'whatsapp', 'on its channel');
putenv('WHATSAPP_TOKEN');
putenv('WHATSAPP_PHONE_NUMBER_ID');
eo_message_queue(eo_event($fev), '9876543210', 'ticket', 'Hello');
eq((int) inc_one('SELECT COUNT(*) AS n FROM inc_ev_messages WHERE status = "queued"')['n'], 0, 'with no keys, nothing is queued');

eo_event_publish($eid, true);
$GLOBALS['mails'] = [];
$told = eo_event_cancel($eid, 'Venue flooded');
ok($told >= 2 && str_contains($GLOBALS['mails'][0][1], 'Cancelled'), 'cancelling tells ticket holders');
eq(eo_closed_reason(eo_event($eid)), 'This event has been cancelled.', 'and closes applications');
refused(static fn() => eo_tickets_issue($eid, [$a3]), 'a cancelled event issues no tickets');
refused(static fn() => eo_event_cancel($eid, 'Again'), 'an event is cancelled once');

$u = eo_user_signup(['name' => 'Rehan', 'email' => 'REHAN@example.test', 'password' => 'long-enough']);
refused(static fn() => eo_user_signup(['name' => 'R', 'email' => 'rehan@example.test', 'password' => 'long-enough']), 'one account per email');
refused(static fn() => eo_user_signup(['name' => 'S', 'email' => 's@example.test', 'password' => 'short']), 'a short password is refused');
ok((bool) eo_user_signin('rehan@example.test', 'long-enough'), 'the right password signs in');
ok(!eo_user_signin('rehan@example.test', 'wrong-one'), 'the wrong one does not');
$hub = eo_user_hub((string) $u['email']);
eq(count($hub['tickets']), 1, 'an account opened after applying still finds the ticket');
eq(count($hub['certs']), 1, 'and the certificate');
$GLOBALS['mails'] = [];
eo_user_forgot('rehan@example.test');
preg_match('/k=([a-f0-9]{48})/', $GLOBALS['mails'][0][2] ?? '', $m);
ok(eo_user_reset($m[1], 'a-new-password'), 'a reset link changes the password');
ok(!eo_user_reset($m[1], 'another-password'), 'and works once');
ok((bool) eo_user_signin('rehan@example.test', 'a-new-password'), 'the new password signs in');

/* -------------------------------------------------------------------- AI */
require_once 'lib/webcontent.php';
fabo_ai_transport(static fn($p, $n) => [true, ['report' => 'All went well.', 'description' => 'Come along.', 'reply' => 'Tuesday.']]);
eq(eo_ai_write(eo_event($fev)), 'Come along.', 'the AI writer drafts a description');
eq(eo_ai_report($fev), 'All went well.', 'the AI writes the report');
eq(count(eo_ai_reports($fev)), 1, 'and it is kept');
eq(eo_chat('When?', 'Hub'), 'Tuesday.', 'the assistant answers');
fabo_ai_transport(static fn($p, $n) => [false, 'no']);
ok(str_contains(eo_chat('When?', 'Hub'), 'not available'), 'and says so when it cannot');
fabo_ai_transport(false);

/* ----------------------------------------------------------------- plans */
eq(eo_plan_of(), null, 'no plan to start with');
eo_event_save($fev, ['capacity' => '5000']);
eo_plan_save(null, ['name' => 'Small', 'max_attendees' => '50', 'mentors' => '', 'ai_reports' => '1', 'excel' => '']);
eo_plan_assign(ORG, (int) eo_plans()[0]['id']);
refused(static fn() => eo_event_save($fev, ['capacity' => '100']), 'a plan limits the seats');
ok(!eo_plan_has('excel') && !eo_plan_has('mentors') && eo_plan_has('ai_reports'), 'and the features');
refused(static fn() => eo_mentor_save(['name' => 'No']), 'mentors need a plan that has them');
eo_plan_assign(ORG, 0);
ok(eo_plan_has('excel'), 'taking the plan away lifts the limits');

/* ----------------------------------------------------------------- access */
foreach ([[$organizer, 'edit', true, true], [$reader, 'view', true, false], [$nobody, 'none', false, false]] as [$id, $g, $read, $w]) {
    $v = $viewer($id);
    eq(eo_grant($v), $g, 'grant for person ' . $id);
    inc_start($v);
    eq([eo_may_read(), eo_may_work()], [$read, $w], 'read/work for person ' . $id);
}
inc_start($viewer($reader));
refused(static fn() => eo_tickets_issue($fev, [$m1]), 'View only cannot issue tickets');
refused(static fn() => eo_checkin($fev, $tk['code'], 1), 'View only cannot check in');
refused(static fn() => eo_order_refund(1), 'View only cannot refund');
refused(static fn() => eo_form_create($fev, 'No'), 'View only cannot create forms');
inc_start($viewer(ORG));
inc_exec('INSERT INTO inc_team_members (team_id, person_id, added_by) VALUES (?,?,"test")', 'ii', [eo_team_id(), $teamOnly]);
$dept = inc_exec('INSERT INTO inc_teams (name) VALUES ("Catering")');
inc_exec('INSERT INTO inc_team_members (team_id, person_id, added_by) VALUES (?,?,"test")', 'ii', [$dept, $otherDept]);
ok(eo_desk_member($viewer($teamOnly)), 'the Organizers team is in');
ok(!eo_desk_member($viewer($otherDept)), 'an Incubation department is not');
eq(inc_grant($viewer($teamOnly)), 'none', 'the team gives no Incubation grant');
inc_start($viewer($teamOnly));
ok(eo_may_read() && eo_may_work() && !inc_can('configure'), 'the team works events but creates no forms');
fabo_exec('DELETE FROM client_sheet_access WHERE client_id = ? AND sheet_key = "organizers"', 'i', [ORG]);
eq(eo_grant($viewer($organizer)), 'none', 'taking it from the head takes it from the team');
ok(!inc_org_enabled(ORG, 'eo') && inc_org_enabled(ORG, 'inc'), 'closes it, and leaves Incubation alone');
$grant(ORG, 'organizers');

/* --------------------------------------- the first version's data moves over */
inc_use_kind('inc');
inc_start($viewer(OLD));
$GLOBALS['inc_ctx']['grant'] = 'edit';
inc_exec("INSERT INTO inc_masters (kind, name, sort) VALUES ('applicant_type','Exhibitor',50)");
$stage = (int) inc_one('SELECT id FROM inc_stages WHERE pipeline = "crm" ORDER BY sort LIMIT 1')['id'];
foreach (['settings', 'forms', 'form_versions', 'form_fields', 'applications', 'app_values', 'activities', 'files', 'stages', 'masters', 'teams', 'team_members'] as $tbl) {
    fabo_exec('CREATE TABLE eo_o99_' . $tbl . ' LIKE inc_o99_' . $tbl);
}
fabo_exec('INSERT INTO eo_o99_stages SELECT * FROM inc_o99_stages');
fabo_exec('INSERT INTO eo_o99_masters SELECT * FROM inc_o99_masters');
$oldTeam = fabo_exec('INSERT INTO eo_o99_teams (name) VALUES ("Organizers")');
fabo_exec('INSERT INTO eo_o99_team_members (team_id, person_id, added_by) VALUES (?,?,"x")', 'ii', [$oldTeam, 1]);
$oldForm = fabo_exec('INSERT INTO eo_o99_forms (id, name, purpose, event_name, public_token, public_enabled, created_by) VALUES (500,"Old form","event","Legacy Event","legacytoken123","1","x")');
$oldVer = fabo_exec('INSERT INTO eo_o99_form_versions (id, form_id, version_no, status, created_by) VALUES (600,500,1,"published","x")');
fabo_exec('INSERT INTO eo_o99_form_fields (version_id, uid, type, label, sort) VALUES (600,"participation","radio","How?",1)');
$typeId = (int) fabo_one('SELECT id FROM eo_o99_masters WHERE name = "Exhibitor"')['id'];
fabo_exec('INSERT INTO eo_o99_applications (id, ref, form_id, form_version_id, purpose, event_name, channel, applicant_name, applicant_email, stage_id, applicant_type_id, startup_name, submitted_by) VALUES (700,"OLD-1",500,600,"event","Legacy Event","public","Old Applicant","old@example.test",?,?, "", "x")', 'ii', [$stage, $typeId]);
fabo_exec('INSERT INTO eo_o99_app_values (application_id, field_uid, label, type, value_text, value_short) VALUES (700,"participation","How?","radio","Exhibitor","Exhibitor")');
fabo_db()->query('CREATE TABLE eo_o99_ev_events LIKE eo_o77_ev_events');
fabo_exec('INSERT INTO eo_o99_ev_events (id, source_id, token, name, label, published, summary) VALUES (1, 9001, ?, "Legacy Event", "Legacy Event", 1, "Kept")', 's', [bin2hex(random_bytes(12))]);
fabo_db()->query('CREATE TABLE eo_o99_ev_forms (event_id INT UNSIGNED NOT NULL, form_id INT UNSIGNED NOT NULL, PRIMARY KEY (event_id, form_id), UNIQUE KEY u_form (form_id),
    CONSTRAINT fk_eo_o99_evf_form FOREIGN KEY (form_id) REFERENCES eo_o99_forms(id) ON DELETE CASCADE) ENGINE=InnoDB');
fabo_exec('INSERT INTO eo_o99_ev_forms VALUES (1,500)');
fabo_db()->query('CREATE TABLE eo_o99_ev_tickets (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, event_id INT UNSIGNED NOT NULL, application_id INT UNSIGNED NOT NULL, code CHAR(24) NOT NULL, status ENUM("issued","cancelled") NOT NULL DEFAULT "issued",
    issued_by VARCHAR(190) NOT NULL DEFAULT "", emailed_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY u_code (code), UNIQUE KEY u_app (event_id, application_id),
    CONSTRAINT fk_eo_o99_evt_app FOREIGN KEY (application_id) REFERENCES eo_o99_applications(id) ON DELETE CASCADE) ENGINE=InnoDB');
$oldCode = bin2hex(random_bytes(12));
fabo_exec('INSERT INTO eo_o99_ev_tickets (event_id, application_id, code) VALUES (1,700,?)', 's', [$oldCode]);

inc_use_kind('eo');
inc_start($viewer(OLD));
$GLOBALS['inc_ctx']['grant'] = 'edit';
eo_install();
$moved = fabo_one('SELECT a.id, a.ref, a.applicant_name, a.event_name, m.name AS type, f.public_token FROM inc_o99_applications a JOIN inc_o99_forms f ON f.id = a.form_id LEFT JOIN inc_o99_masters m ON m.id = a.applicant_type_id WHERE a.applicant_email = "old@example.test"');
eq([$moved['applicant_name'] ?? '', $moved['event_name'] ?? '', $moved['type'] ?? ''], ['Old Applicant', 'Legacy Event', 'Exhibitor'], 'a first-version application moves onto the main pipeline');
eq($moved['public_token'] ?? '', 'legacytoken123', 'its form keeps its public link');
ok(preg_match('/-\d{5}$/', (string) ($moved['ref'] ?? '')) === 1, 'it gets a reference in the main series');
eq((int) inc_one('SELECT COUNT(*) AS n FROM inc_app_values WHERE application_id = ?', 'i', [(int) $moved['id']])['n'], 1, 'its answers come with it');
$oldTicket = eo_ticket($oldCode);
eq((int) ($oldTicket['application_id'] ?? 0), (int) $moved['id'], 'its ticket still works, on the new application');
eq((string) inc_one('SELECT summary FROM inc_ev_events WHERE id = 1')['summary'], 'Kept', 'the event keeps what the organizers wrote');
ok(eo_event_of_form_token('legacytoken123') !== null, 'the form still belongs to its event');
eq(inc_one('SELECT maps_to FROM inc_form_fields WHERE uid = "participation" ORDER BY id DESC LIMIT 1')['maps_to'], 'applicant_type', 'the moved form fills the type of participant');
ok((bool) inc_one('SELECT 1 AS x FROM inc_team_members WHERE team_id = ? AND person_id = 1', 'i', [eo_team_id()]), 'the old team members stay on the team');
ok(eo_table_exists('eo_o99_applications'), 'the old tables are left in place');
$n = (int) inc_one('SELECT COUNT(*) AS n FROM inc_applications')['n'];



eq(inc_setting('_eo_moved'), '1', 'the move is marked done, so it runs once');

/* --------------------------------------------------------- cron, nav, QR */
inc_use_kind('inc');
inc_use_org(0);
eo_cron_all();
eq([inc_kind(), inc_org()], ['inc', 0], 'cron leaves Incubation as it found it');
$html = inc_nav_html('eo-events');
ok(str_contains($html, 'incubation.php?'), 'Incubation menu links');
inc_use_kind('eo');
$html = inc_nav_html('eo-events');
ok(str_contains($html, 'incubation.php?') && !str_contains($html, 'organizers.php'), 'stay Incubation\'s on an Organizers page');
eq(inc_url('events'), 'organizers.php?p=events', 'links stay in Event Organizers');
eq(inc_words('The incubation team will call about Incubation Bootcamp'), 'The organizers team will call about Incubation Bootcamp', 'wording never touches an event\'s own name');
foreach ([10, 60, 100, 140, 170, 200] as $n) {
    $m = eo_qr_matrix(str_repeat('a', $n));
    ok(count($m) === count($m[0]) && (count($m) - 17) % 4 === 0, "a QR code of $n bytes is a valid size");
}
refused(static fn() => eo_qr_matrix(str_repeat('a', 300)), 'too long for a QR code is refused');

echo "\n$pass passed, " . count($fail) . " failed\n";
exit($fail ? 1 : 0);

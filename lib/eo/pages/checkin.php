<?php
/**
 * Event Organizers — check-in at the door. The camera or a typed code posts here
 * and the answer is JSON, so the page never reloads between people. It keeps the
 * roster on the device and holds scans made without a network until it returns.
 */

eo_need_work();
$id = (int) ($_GET['id'] ?? 0);
$ev = eo_event($id);
if (!$ev) {
    inc_redirect(inc_url('events'), ['err', 'That event no longer exists.']);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        switch ((string) ($_POST['action'] ?? '')) {
            case 'roster':
                echo json_encode(['roster' => eo_roster($id)]);
                break;
            case 'sync':
                $out = [];
                foreach (array_slice((array) json_decode((string) ($_POST['scans'] ?? '[]'), true), 0, 2000) as $s) {
                    $out[] = eo_checkin($id, (string) ($s['code'] ?? ''), (int) ($s['day'] ?? 1), mb_substr((string) ($s['id'] ?? ''), 0, 64) ?: null, (string) ($s['at'] ?? ''))['state'];
                }
                echo json_encode(['synced' => count($out), 'counts' => eo_checkin_counts($id)]);
                break;
            case 'food':
                echo json_encode(eo_food_redeem($id, (string) ($_POST['code'] ?? '')));
                break;
            default:
                echo json_encode(eo_checkin($id, (string) ($_POST['code'] ?? ''), (int) ($_POST['day'] ?? 1)) + ['counts' => eo_checkin_counts($id)]);
        }
    } catch (Throwable $e) {
        error_log('FABO organizers check-in: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['state' => 'error', 'name' => '', 'at' => '']);
    }
    exit;
}

$days = eo_days($ev);
$today = eo_today_day($ev);
$counts = eo_checkin_counts($id);
$seats = eo_seats_taken($id);
inc_layout_start('Check-in', e((string) $ev['name']) . ' · ' . e(eo_dates_text($ev)), ['Approved events' => inc_url('events'), (string) $ev['name'] => inc_url('event', ['id' => $id]), 'Check-in' => null]);
?>
<div class="inc-grid2" id="eo-door" data-url="<?= e(inc_url('checkin', ['id' => $id])) ?>" data-csrf="<?= e(fabo_csrf_token()) ?>">
  <section class="card">
    <h2>Scan a ticket</h2>
    <div id="eo-reader" class="eo-reader"></div>
    <div class="inc-row">
      <button type="button" class="btn btn-primary" id="eo-cam">Start the camera</button>
      <label><input type="checkbox" id="eo-food"> Food tokens</label>
      <?php if ($days > 1): ?>
        <label>Day <select id="eo-day"><?php for ($d = 1; $d <= $days; $d++): ?><option value="<?= $d ?>"<?= $d === $today ? ' selected' : '' ?>><?= $d ?></option><?php endfor; ?></select></label>
      <?php else: ?>
        <input type="hidden" id="eo-day" value="1">
      <?php endif; ?>
    </div>
    <form id="eo-typed" class="inc-row">
      <input type="text" id="eo-code" class="grow" autocomplete="off" aria-label="Ticket code" placeholder="Or type or paste the ticket code">
      <button class="btn">Check in</button>
    </form>
    <p class="muted small" id="eo-offline" role="status"></p>
  </section>
  <section class="card">
    <h2>Result</h2>
    <div id="eo-result" class="eo-result" role="status" aria-live="polite"><span class="muted">Waiting for the first ticket.</span></div>
    <dl class="eo-facts">
      <dt>Tickets issued</dt><dd><?= number_format($seats) ?></dd>
      <dt>Checked in</dt><dd><span id="eo-in"><?= number_format($counts[$today] ?? 0) ?></span> <span class="muted small" id="eo-inday">on day <?= $today ?></span></dd>
    </dl>
    <script type="application/json" id="eo-counts"><?= json_encode((object) $counts) ?></script>
  </section>
</div>
<script src="<?= e(fabo_asset(fabo_root_prefix() . 'assets/html5-qrcode.min.js')) ?>" defer></script>
<script src="<?= e(fabo_asset(fabo_root_prefix() . 'assets/eo.js')) ?>" defer></script>
<?php
inc_layout_end();

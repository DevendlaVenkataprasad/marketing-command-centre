<?php
/**
 * Event Organizers — the self check-in kiosk: a screen at the entrance where
 * people scan their own ticket and can print their badge. Signed in by an
 * organizer, so it uses their session; it shows a name, never a list.
 */

eo_need_work();
$id = (int) ($_GET['id'] ?? 0);
$ev = eo_event($id);
if (!$ev) {
    inc_redirect(inc_url('events'), ['err', 'That event no longer exists.']);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(eo_checkin($id, (string) ($_POST['code'] ?? ''), eo_today_day($ev)));
    exit;
}
$pre = fabo_root_prefix();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Check-in · <?= e($ev['name']) ?></title>
<link rel="stylesheet" href="<?= e(fabo_asset($pre . 'assets/app.css')) ?>">
<link rel="stylesheet" href="<?= e(fabo_asset($pre . 'assets/eo.css')) ?>">
</head>
<body class="eo-kiosk">
<main id="eo-door" data-url="<?= e(inc_url('kiosk', ['id' => $id])) ?>" data-csrf="<?= e(fabo_csrf_token()) ?>">
  <h1><?= e($ev['name']) ?></h1>
  <p>Welcome. Show your ticket to the camera.</p>
  <div id="eo-reader" class="eo-reader"></div>
  <p><button type="button" class="btn btn-primary" id="eo-cam">Start the camera</button></p>
  <form id="eo-typed"><input type="text" id="eo-code" autocomplete="off" aria-label="Ticket code" placeholder="Or type your ticket code"> <button class="btn">Check in</button></form>
  <input type="hidden" id="eo-day" value="<?= eo_today_day($ev) ?>">
  <div id="eo-result" class="eo-result" role="status" aria-live="polite"></div>
</main>
<script src="<?= e(fabo_asset($pre . 'assets/html5-qrcode.min.js')) ?>" defer></script>
<script src="<?= e(fabo_asset($pre . 'assets/eo.js')) ?>" defer></script>
</body>
</html>

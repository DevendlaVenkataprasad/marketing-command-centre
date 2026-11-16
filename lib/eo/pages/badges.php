<?php
/** Event Organizers — a sheet of badges to print: name, type of participant and the ticket's QR code. */

eo_need_read();
$id = (int) ($_GET['id'] ?? 0);
$ev = eo_event($id);
if (!$ev) {
    inc_redirect(inc_url('events'), ['err', 'That event no longer exists.']);
}
$people = array_filter(eo_attendees($id), static fn($a) => $a['ticket_status'] === 'issued');
$pre = fabo_root_prefix();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Badges · <?= e($ev['name']) ?></title>
<link rel="stylesheet" href="<?= e(fabo_asset($pre . 'assets/eo.css')) ?>">
</head>
<body class="eo-badges">
<p class="ev-noprint"><button type="button" onclick="window.print()">Print</button> <?= number_format(count($people)) ?> badges</p>
<div class="eo-sheet">
<?php foreach ($people as $a): ?>
  <article class="eo-badge">
    <div class="ev"><?= e($ev['name']) ?></div>
    <strong><?= e($a['applicant_name']) ?></strong>
    <div class="ty"><?= e((string) ($a['type_name'] ?: 'Participant')) ?></div>
    <?= eo_qr_svg((string) $a['code'], 110) ?>
  </article>
<?php endforeach; ?>
</div>
</body>
</html>

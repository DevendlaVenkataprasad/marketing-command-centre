<?php
/**
 * Incubation Management — one stage certificate, from the link in its email.
 *
 * ?c=<24-character code>&o=<organisation>. Shows the certificate as issued
 * (the snapshot saved with it), a button to save it as PDF or print it (A4
 * landscape), and a line confirming it is genuine — or that it was revoked.
 * The same page is the verification anyone can open. See lib/inc/certs.php.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/inc/app.php';
require_once __DIR__ . '/lib/inc/certs.php';
fabo_boot();
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');

// Checking an ID typed into the box: find which organisation issued it, then show it.
if (isset($_GET['id'])) {
    $hit = inc_cert_find((string) $_GET['id']);
    if ($hit) {
        header('Location: certificate.php?c=' . $hit[1] . ($hit[0] > 0 ? '&o=' . $hit[0] : ''));
        exit;
    }
}
$org = max(0, (int) ($_GET['o'] ?? 0));
$code = (string) ($_GET['c'] ?? '');
$c = null;
if (preg_match('/^[a-f0-9]{24}$/', $code) && inc_org_enabled($org)) {
    try {
        inc_start(['is_admin' => false, 'client' => ['name' => 'certificate'], 'access' => null], $org);
        $c = inc_one('SELECT * FROM inc_certificates WHERE code = ?', 's', [$code]);
    } catch (Throwable $e) {
        $c = null;
    }
}
$snap = $c ? (json_decode((string) $c['snapshot'], true) ?: null) : null;
$self = rtrim(fabo_portal_url(), '/') . '/certificate.php?c=' . $code . ($org > 0 ? '&o=' . $org : '');
$title = $snap ? $snap['startup'] . ' — ' . $c['stage_name'] : 'Certificate';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($title) ?></title>
<?= inc_cert_fonts() ?>
<style>
  @page { size: A4 landscape; margin: 0; }
  body { margin: 0; background: #e9edf3; font-family: Inter, system-ui, "Segoe UI", Arial, sans-serif; color: #1c2333; }
  .bar { position: sticky; top: 0; z-index: 5; display: flex; flex-wrap: wrap; gap: 10px 16px; align-items: center; justify-content: space-between;
         padding: 12px 20px; background: #fff; border-bottom: 1px solid #dde2ea; }
  .ok { display: flex; align-items: center; gap: 8px; font-size: 14px; }
  .ok b { color: #1a7f44; }
  .bad b { color: #b3261e; }
  .btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; border-radius: 9px; border: 0; background: #1d4ed8; color: #fff; font: inherit; font-weight: 650; cursor: pointer; }
  .stage { padding: 24px 12px 40px; overflow: auto; }
  .sheet { width: 297mm; margin: 0 auto; box-shadow: 0 10px 40px rgba(20, 30, 60, .18); transform-origin: top center; }
  .msg { max-width: 560px; margin: 12vh auto; padding: 28px; background: #fff; border-radius: 14px; text-align: center; }
  @media (max-width: 1150px) { .sheet { zoom: .6; } }
  @media (max-width: 700px) { .sheet { zoom: .32; } }
  @media print {
    body { background: #fff; }
    .bar { display: none; }
    .stage { padding: 0; overflow: visible; }
    .sheet { zoom: 1 !important; box-shadow: none; margin: 0; }
    * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  }
</style>
</head>
<body>
<?php if (!$c || !$snap): ?>
  <div class="msg"><h1 style="margin-top:0"><?= isset($_GET['id']) || $code !== '' ? 'Certificate not found' : 'Verify a certificate' ?></h1>
  <p><?= isset($_GET['id']) || $code !== '' ? 'No certificate has that ID. Check it against the certificate and try again.' : 'Enter the Certificate ID printed at the foot of the certificate.' ?></p>
  <form method="get" style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap;margin-top:14px">
    <input name="id" value="<?= e((string) ($_GET['id'] ?? '')) ?>" placeholder="e.g. BC51-4B27-84EE-E438-CC15-73A5" required style="flex:1;min-width:240px;padding:10px 12px;border:1px solid #cfd6df;border-radius:9px;font:inherit">
    <button class="btn">Check</button></form></div>
<?php else: ?>
  <div class="bar">
    <?php if ($c['revoked_at']): ?>
      <div class="ok bad"><b>✕ Revoked.</b> This certificate was withdrawn on <?= e(date('d M Y', strtotime((string) $c['revoked_at']))) ?> and is no longer valid.</div>
    <?php else: ?>
      <div class="ok"><b>✓ Genuine certificate</b> issued by <?= e((string) $snap['org']) ?> on <?= e(date('d M Y', strtotime((string) $c['issued_at']))) ?> · ID <?= e(strtoupper(implode('-', str_split($c['code'], 4)))) ?></div>
      <button class="btn" type="button" onclick="window.print()">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 4v11M7 10l5 5 5-5M5 20h14"/></svg>
        Download PDF / Print</button>
    <?php endif; ?>
  </div>
  <?php if (!$c['revoked_at']): ?>
    <div class="stage"><div class="sheet"><?= inc_cert_render($snap, (string) $c['code'], $self, date('d F Y', strtotime((string) $c['issued_at']))) ?></div></div>
  <?php endif; ?>
<?php endif; ?>
</body>
</html>

<?php
/**
 * One invoice, laid out to be read and printed.
 *
 * The administrator can open any invoice. A client can open their own, and
 * only once it has been sent — a draft is not yet a thing that exists as far
 * as they are concerned.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/invoices.php';
fabo_boot();

header('X-Robots-Tag: noindex, nofollow');

$id = (int) ($_GET['id'] ?? 0);

/* Who is asking comes first. Loading the invoice first answered "no such
   invoice" for a missing id and "please sign in" for a real one, which let
   anybody list which invoice numbers exist without signing in. */
$viewer = fabo_admin_signed_in()
        ? fabo_admin_viewer()
        : fabo_portal_viewer((string) ($_GET['k'] ?? ''));

if (!$viewer) {
    header('Location: login.php?next=' . rawurlencode('invoice.php?id=' . $id));
    exit;
}
$inv = fabo_invoice($id);
if (empty($viewer['is_admin'])) {
    // Finance is a module: taken away, it takes the invoices with it.
    $mine = $inv && (int) $viewer['client']['id'] === (int) $inv['client_id'];
    if (!$mine || $inv['state'] === 'draft' || fabo_module_permission($viewer, 'finance') === 'none') {
        fabo_deny('That invoice is not available.', 404);
    }
}
if (!$inv) {
    fabo_deny('That invoice is not available.', 404);
}

$items = fabo_invoice_items($id);
$from  = fabo_app_name();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($inv['number']) ?> &middot; <?= e($from) ?></title>
<link rel="stylesheet" href="<?= e(fabo_asset('assets/app.css')) ?>">
</head>
<body class="invbody">

<div class="invtools">
  <a class="btn btn-sm" href="<?= e($viewer['is_admin']
        ? 'admin/invoices.php?id=' . $id
        : fabo_link($viewer, 'finance.php')) ?>">&larr; Back</a>
  <button class="btn btn-primary btn-sm" onclick="window.print()">Print / save as PDF</button>
</div>

<div class="invoice">
  <header class="invhead">
    <div>
      <div class="invfrom"><?= e($from) ?></div>
      <div class="invsub"><?= e(fabo_app_tagline()) ?></div>
      <?php if (APP_ADDRESS !== ''): ?>
        <div class="invaddr"><?= nl2br(e(APP_ADDRESS)) ?></div>
      <?php endif; ?>
      <?php if (APP_TAXID !== ''): ?>
        <div class="invaddr"><?= e(APP_TAXID) ?></div>
      <?php endif; ?>
    </div>
    <div class="invmeta">
      <h1>Invoice</h1>
      <div class="num"><?= e($inv['number']) ?></div>
      <span class="pill tone-<?= e(fabo_invoice_tone((string) $inv['state'])) ?>">
        <i class="dot"></i><?= e(fabo_invoice_label((string) $inv['state'])) ?></span>
    </div>
  </header>

  <section class="invparties">
    <div>
      <h3>Billed to</h3>
      <div class="big"><?= e($inv['client_name']) ?></div>
      <?php if ($inv['client_org']): ?><div><?= e($inv['client_org']) ?></div><?php endif; ?>
      <?php if (!empty($inv['client_address'])): ?>
        <div class="invaddr"><?= nl2br(e($inv['client_address'])) ?></div>
      <?php endif; ?>
      <?php if ($inv['client_email']): ?><div class="muted"><?= e($inv['client_email']) ?></div><?php endif; ?>
    </div>
    <div>
      <h3>Dates</h3>
      <div><span class="muted">Issued</span> <?= e(date('d M Y', strtotime((string) $inv['issue_date']))) ?></div>
      <?php if ($inv['due_date']): ?>
        <div><span class="muted">Due</span> <?= e(date('d M Y', strtotime((string) $inv['due_date']))) ?></div>
      <?php endif; ?>
      <?php if ($inv['paid_at']): ?>
        <div><span class="muted">Paid</span> <?= e(date('d M Y', strtotime((string) $inv['paid_at']))) ?></div>
      <?php endif; ?>
    </div>
  </section>

  <table class="data invlines">
    <thead><tr><th>Description</th><th class="num">Qty</th><th class="num">Rate</th><th class="num">Amount</th></tr></thead>
    <tbody>
    <?php if (!$items): ?>
      <tr><td colspan="4" class="muted">No lines on this invoice.</td></tr>
    <?php endif; ?>
    <?php foreach ($items as $it): ?>
      <tr>
        <td><?= e($it['description']) ?></td>
        <td class="num"><?= e(rtrim(rtrim(number_format((float) $it['quantity'], 2), '0'), '.')) ?></td>
        <td class="num"><?= e(fabo_money($it['rate'])) ?></td>
        <td class="num"><?= e(fabo_money($it['amount'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <div class="invtotals">
    <div><span>Subtotal</span><strong><?= e(fabo_money($inv['subtotal'])) ?></strong></div>
    <?php if ((float) $inv['tax_pct'] > 0): ?>
      <div><span>Tax <?= e(rtrim(rtrim(number_format((float) $inv['tax_pct'], 3), '0'), '.')) ?>%</span>
           <strong><?= e(fabo_money($inv['tax_amount'])) ?></strong></div>
    <?php endif; ?>
    <div class="grand"><span>Total</span><strong><?= e(fabo_money($inv['total'])) ?></strong></div>
  </div>

  <?php if (APP_BANK !== '' && $inv['state'] !== 'paid'): ?>
    <section class="invbank">
      <h3>Payment details</h3>
      <pre><?= e(APP_BANK) ?></pre>
    </section>
  <?php endif; ?>

  <?php if ($inv['notes']): ?>
    <section class="invnotes">
      <h3>Notes</h3>
      <p><?= nl2br(e($inv['notes'])) ?></p>
    </section>
  <?php endif; ?>

  <footer class="invfoot">
    <?= e($inv['number']) ?> · <?= e($from) ?>
    <?php if ($inv['state'] === 'paid'): ?> · Settled, thank you<?php endif; ?>
  </footer>
</div>

</body>
</html>

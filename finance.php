<?php
/**
 * A client's own Finance page.
 *
 * Granted like a sheet, and the two levels mean different things:
 *
 *   view  - the reading side. What we have billed them, and any statutory
 *           filing we handle on their behalf.
 *   edit  - the same, plus their own books: the spend they record against
 *           their organisation, and a filing register they keep themselves.
 *
 * One thing edit deliberately does not reach: an invoice WE raised against
 * them. A client marking their own bill as paid is not something a grant can
 * offer, so those stay read-only at both levels and this page enforces it.
 * Their own staff are never billed and never see any of it.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/finance.php';
require_once __DIR__ . '/lib/charts.php';
fabo_boot();

header('X-Robots-Tag: noindex, nofollow');

$viewer = fabo_portal_viewer((string) ($_GET['k'] ?? ''));
if (!$viewer) {
    header('Location: login.php?next=' . rawurlencode('finance.php'));
    exit;
}
// Invoices are raised against a client, not against their staff.
if (($viewer['client']['kind'] ?? 'client') === 'team') {
    fabo_deny('Finance is not part of your account.', 403);
}
fabo_require_module($viewer, 'finance');

$me    = $viewer['client'];
$cid   = (int) $me['id'];
$mayEdit = fabo_module_permission($viewer, 'finance') === 'edit';
$flash = null;

/* ------------------------------------------------------------- actions */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    fabo_csrf_check();
    if (!$mayEdit) {
        fabo_deny('Your Finance access is read-only.', 403);
    }
    $action = (string) ($_POST['action'] ?? '');

    // Every write is pinned to $cid, so nothing here can touch another
    // organisation's books however the form is submitted.
    if ($action === 'expense_add') {
        [$id, $msg] = fabo_expense_create(
            $cid,
            (string) ($_POST['spent_on'] ?? ''),
            (string) ($_POST['category'] ?? ''),
            (string) ($_POST['vendor'] ?? ''),
            (string) ($_POST['description'] ?? ''),
            (float) ($_POST['amount'] ?? 0),
            (float) ($_POST['tax_amount'] ?? 0),
            !empty($_POST['gst_credit']),
            false,                      // billing it on is ours to decide
            !empty($_POST['paid']),
            (string) ($_POST['notes'] ?? '')
        );
        $flash = [$id ? 'ok' : 'err', $msg];

    } elseif ($action === 'expense_delete') {
        [$ok, $msg] = fabo_expense_delete((int) ($_POST['id'] ?? 0), $cid);
        $flash = [$ok ? 'ok' : 'err', $msg];

    } elseif ($action === 'filing_add') {
        [$id, $msg] = fabo_filing_create(
            $cid,
            (string) ($_POST['kind'] ?? ''),
            (string) ($_POST['period_label'] ?? ''),
            (string) ($_POST['due_date'] ?? ''),
            (string) ($_POST['period_start'] ?? ''),
            (string) ($_POST['period_end'] ?? ''),
            (string) ($_POST['notes'] ?? '')
        );
        $flash = [$id ? 'ok' : 'err', $msg];

    } elseif ($action === 'filing_generate') {
        [$n, $msg] = fabo_filing_generate($cid, (string) ($_POST['kind'] ?? ''),
                                          (int) ($_POST['year'] ?? date('Y')));
        $flash = [$n ? 'ok' : 'err', $msg];

    } elseif ($action === 'filing_state') {
        [$ok, $msg] = fabo_filing_set_state(
            (int) ($_POST['id'] ?? 0),
            (string) ($_POST['state'] ?? ''),
            (string) ($_POST['ack_no'] ?? ''),
            (float) ($_POST['tax_amount'] ?? 0),
            (float) ($_POST['penalty'] ?? 0),
            (string) ($_POST['filed_on'] ?? ''),
            $cid
        );
        $flash = [$ok ? 'ok' : 'err', $msg];

    } elseif ($action === 'filing_delete') {
        [$ok, $msg] = fabo_filing_delete((int) ($_POST['id'] ?? 0), $cid);
        $flash = [$ok ? 'ok' : 'err', $msg];
    }
}

$list    = fabo_invoices_for($cid);
$metrics = fabo_invoice_metrics($cid);
$filings = fabo_filings($cid);
$fil     = fabo_filing_metrics($cid);
$exp     = fabo_expense_metrics($cid);

/** The filing being updated, when one was picked from the register. */
$editing = null;
if ($mayEdit && !empty($_GET['edit'])) {
    $f = fabo_filing((int) $_GET['edit']);
    if ($f && (int) $f['client_id'] === $cid) {
        $editing = $f;
    }
}

fabo_head($viewer, 'Finance', 'finance');
?>
<div class="wrap-page">

<div class="pagehead">
  <h1>FINANCE</h1>
  <div class="sub"><?= $metrics['outstanding'] > 0
      ? 'You have <strong>' . e(fabo_money($metrics['outstanding'])) . '</strong> outstanding.'
      : 'Nothing outstanding.' ?>
    <?= $mayEdit ? 'You can also keep your own spend and filing register here.' : '' ?></div>
</div>

<?php if ($flash): ?>
  <div class="flash <?= $flash[0] === 'ok' ? 'ok' : 'err' ?>"><?= e($flash[1]) ?></div>
<?php endif; ?>

<div class="band">
  <h3>Billed to you</h3>
  <div class="tiles">
    <div class="tile"><div class="k">Billed to date</div>
      <div class="v"><?= e(fabo_money($metrics['raised'])) ?></div></div>
    <div class="tile green"><div class="k">Paid</div>
      <div class="v"><?= e(fabo_money($metrics['paid'])) ?></div></div>
    <div class="tile amber"><div class="k">Outstanding</div>
      <div class="v"><?= e(fabo_money($metrics['outstanding'])) ?></div></div>
    <div class="tile <?= $metrics['overdue_count'] ? 'red' : '' ?>"><div class="k">Overdue</div>
      <div class="v"><?= e(fabo_money($metrics['overdue_value'])) ?></div>
      <div class="k"><?= (int) $metrics['overdue_count'] ?> invoice<?= $metrics['overdue_count'] === 1 ? '' : 's' ?></div></div>
  </div>
</div>

<div class="card">
  <h2>Invoices from us</h2>
  <p class="lead">What we have billed you. These are ours to change &mdash; tell us
     if anything looks wrong and we will correct it.</p>
  <?php if (!$list): ?>
    <div class="empty">No invoices yet. When one is raised you will hear about it by email
      and it will appear here.</div>
  <?php else: ?>
    <div class="scrollx">
    <table class="ledger">
      <thead><tr><th>Number</th><th>Issued</th><th>Due</th>
        <th class="num">Total</th><th>Status</th><th class="acts"></th></tr></thead>
      <tbody>
      <?php foreach ($list as $r): $late = fabo_invoice_overdue($r); ?>
        <tr>
          <td class="ref"><?= e($r['number']) ?></td>
          <td class="when"><?= e(fabo_short_date((string) $r['issue_date'])) ?></td>
          <td class="<?= $late ? 'late' : 'when' ?>"><?= e(fabo_due_phrase($r['due_date'])) ?></td>
          <td class="num tot"><?= e(fabo_money_exact($r['total'])) ?></td>
          <td><span class="tag <?= e($late && $r['state'] === 'sent' ? 'overdue' : $r['state']) ?>"><?=
                e($late && $r['state'] === 'sent' ? 'Overdue' : fabo_invoice_label((string) $r['state'])) ?></span></td>
          <td class="acts"><a class="btn btn-sm btn-primary"
             href="<?= e(fabo_link($viewer, 'invoice.php', ['id' => (int) $r['id']])) ?>">View</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>

<?php if (count($list) > 1): ?>
<div class="card">
  <h2>Billing history</h2>
  <div class="chartgrid">
    <?php
      $monthly = fabo_invoice_monthly($cid, 6);
      fabo_chart_bar('Invoiced vs paid — last 6 months', array_keys($monthly), [
          ['name' => 'Invoiced', 'color' => 'var(--s1)',
           'values' => array_map(static fn($v) => $v['invoiced'], array_values($monthly))],
          ['name' => 'Paid',     'color' => 'var(--s3)',
           'values' => array_map(static fn($v) => $v['paid'], array_values($monthly))],
      ], 'money');
    ?>
  </div>
</div>
<?php endif; ?>


<?php /* ------------------------------------------------ the filing register */ ?>
<?php if ($filings || $mayEdit): ?>

<?php if ($editing): ?>
<div class="card editpanel">
  <h2><?= e(fabo_filing_label($editing['kind'])) ?> &mdash; <?= e($editing['period_label']) ?></h2>
  <p class="lead">Due <?= e(date('d M Y', strtotime($editing['due_date']))) ?>
     (<?= e(fabo_due_phrase($editing['due_date'])) ?>)</p>
  <form method="post">
    <?= fabo_csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
    <div class="cols">
      <div>
        <div class="field"><label>Status</label>
          <select name="state">
            <?php foreach (fabo_filing_states() as $k => $v): ?>
              <option value="<?= e($k) ?>" <?= $editing['state'] === $k ? 'selected' : '' ?>><?= e($v[0]) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="field"><label>Filed on</label>
          <input type="date" name="filed_on" value="<?= e($editing['filed_on'] ?: date('Y-m-d')) ?>">
          <div class="hint">Later than the due date is recorded as late.</div></div>
        <div class="field"><label>Acknowledgement number</label>
          <input name="ack_no" value="<?= e($editing['ack_no'] ?? '') ?>" placeholder="ARN / token from the portal"></div>
      </div>
      <div>
        <div class="field"><label>Tax paid</label>
          <input name="tax_amount" type="number" step="0.01" min="0" value="<?= e((string) $editing['tax_amount']) ?>"></div>
        <div class="field"><label>Penalty or late fee</label>
          <input name="penalty" type="number" step="0.01" min="0" value="<?= e((string) $editing['penalty']) ?>"></div>
      </div>
    </div>
    <div class="formfoot">
      <button class="btn btn-primary" name="action" value="filing_state">Save</button>
      <a class="btn" href="<?= e(fabo_link($viewer, 'finance.php')) ?>">Cancel</a>
      <?php if ($editing['state'] === 'pending'): ?>
        <button class="btn btn-danger" name="action" value="filing_delete"
                onclick="return confirm('Remove this filing from your register?')">Remove</button>
      <?php endif; ?>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <h2>Filings</h2>
  <p class="lead">
    <?= $mayEdit
        ? 'Your statutory register &mdash; GST, income tax, TDS, PF and ESI. Anything we file on your behalf appears here too.'
        : 'The statutory returns we handle on your behalf. We file them; this is here so you can see where each one stands.' ?>
  </p>
  <?php if ($filings): ?>
  <div class="band">
    <div class="tiles">
      <div class="tile <?= $fil['overdue'] ? 'red' : '' ?>"><div class="k">Overdue</div>
        <div class="v"><?= (int) $fil['overdue'] ?></div></div>
      <div class="tile <?= $fil['due_soon'] ? 'amber' : '' ?>"><div class="k">Due in 15 days</div>
        <div class="v"><?= (int) $fil['due_soon'] ?></div></div>
      <div class="tile green"><div class="k">Filed on time</div>
        <div class="v"><?= (int) $fil['filed'] ?></div></div>
      <div class="tile <?= $fil['late'] ? 'red' : '' ?>"><div class="k">Filed late</div>
        <div class="v"><?= (int) $fil['late'] ?></div></div>
      <div class="tile"><div class="k">Tax paid</div>
        <div class="v"><?= e(fabo_money($fil['tax_paid'])) ?></div></div>
      <div class="tile <?= $fil['penalty'] > 0 ? 'red' : '' ?>"><div class="k">Penalties</div>
        <div class="v"><?= e(fabo_money($fil['penalty'])) ?></div></div>
    </div>
  </div>
  <div class="scrollx">
  <table class="ledger">
    <thead><tr><th>Due</th><th>Return</th><th>Period</th><th>Status</th>
      <th class="num">Tax</th><th>Acknowledgement</th><?= $mayEdit ? '<th class="acts"></th>' : '' ?></tr></thead>
    <tbody>
    <?php foreach ($filings as $f):
        $late = fabo_filing_overdue($f);
        $tone = $late ? 'overdue' : ($f['state'] === 'pending' ? 'draft'
              : ($f['state'] === 'late' ? 'cancelled' : 'paid')); ?>
      <tr<?= $f['state'] === 'na' ? ' class="void"' : '' ?>>
        <td class="<?= $late ? 'late' : 'when' ?>">
          <?= e(date('d M Y', strtotime($f['due_date']))) ?>
          <div style="font-size:11.5px"><?= e(fabo_due_phrase($f['due_date'])) ?></div></td>
        <td class="ref"><?= e(fabo_filing_label($f['kind'])) ?>
          <div class="muted" style="font-size:11.5px;font-weight:400"><?= e(fabo_filing_group($f['kind'])) ?></div></td>
        <td><?= e($f['period_label']) ?></td>
        <td><span class="tag <?= e($tone) ?>"><?= e($late ? 'Overdue' : fabo_filing_state_label($f['state'])) ?></span>
            <?php if ($f['filed_on']): ?>
              <div class="muted" style="font-size:11.5px">on <?= e(date('d M Y', strtotime($f['filed_on']))) ?></div>
            <?php endif; ?></td>
        <td class="num"><?= $f['tax_amount'] > 0 ? e(fabo_money_exact($f['tax_amount'])) : '&mdash;' ?></td>
        <td><?= e($f['ack_no'] ?? '') ?: '<span class="muted">&mdash;</span>' ?></td>
        <?php if ($mayEdit): ?>
          <td class="acts"><a class="btn btn-sm<?= $f['state'] === 'pending' ? ' btn-primary' : '' ?>"
             href="<?= e(fabo_link($viewer, 'finance.php', ['edit' => (int) $f['id']])) ?>">
             <?= $f['state'] === 'pending' ? 'Record filing' : 'Update' ?></a></td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php else: ?>
    <div class="empty">Nothing on the register yet.
      <?= $mayEdit ? 'Lay out a year below, or add a single filing.' : '' ?></div>
  <?php endif; ?>
</div>

<?php if ($mayEdit): ?>
<div class="cols">
  <div class="card">
    <h2>Lay out a year</h2>
    <p class="lead">GSTR-3B alone is twelve rows a year. Pick the return and the
       financial year; the periods and due dates are worked out for you and each
       one stays editable. Running it twice adds nothing.</p>
    <form method="post">
      <?= fabo_csrf_field() ?>
      <div class="field"><label>Return</label>
        <select name="kind">
          <?php foreach (fabo_filing_kinds() as $k => $v): ?>
            <option value="<?= e($k) ?>"><?= e($v[0]) ?> &mdash; <?= e($v[2]) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="field"><label>Financial year</label>
        <select name="year">
          <?php $fy = (int) date('n') >= 4 ? (int) date('Y') : (int) date('Y') - 1;
                for ($y = $fy + 1; $y >= $fy - 2; $y--): ?>
            <option value="<?= $y ?>" <?= $y === $fy ? 'selected' : '' ?>>
              FY <?= $y ?>&ndash;<?= substr((string) ($y + 1), 2) ?></option>
          <?php endfor; ?>
        </select>
        <div class="hint">April to March, so FY <?= $fy ?>&ndash;<?= substr((string) ($fy + 1), 2) ?>
          runs Apr <?= $fy ?> to Mar <?= $fy + 1 ?>.</div></div>
      <div class="formfoot">
        <button class="btn btn-primary" name="action" value="filing_generate">Lay out the year</button>
      </div>
    </form>
  </div>

  <div class="card">
    <h2>Add one filing</h2>
    <p class="lead">For anything the year generator does not cover &mdash; a one-off
       return, a revised filing, or a due date that has been extended.</p>
    <form method="post">
      <?= fabo_csrf_field() ?>
      <div class="field"><label>Return</label>
        <select name="kind">
          <?php foreach (fabo_filing_kinds() as $k => $v): ?>
            <option value="<?= e($k) ?>"><?= e($v[0]) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="field"><label>Period</label>
        <input name="period_label" placeholder="Sep-2026, Q2 FY 2026-27, FY 2026-27" required></div>
      <div class="field"><label>Due on</label>
        <input type="date" name="due_date" required></div>
      <div class="field"><label>Covers from</label>
        <input type="date" name="period_start"></div>
      <div class="field"><label>Covers to</label>
        <input type="date" name="period_end"></div>
      <div class="formfoot">
        <button class="btn btn-primary" name="action" value="filing_add">Add to the register</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>


<?php /* ------------------------------------------------------- their own spend */ ?>
<?php if ($mayEdit): $rows = fabo_expenses($cid); ?>
<div class="card">
  <h2>Your spend</h2>
  <p class="lead">Your own book, kept by you. Nothing here is billed to you by
     us &mdash; it is what you record against your own organisation.</p>
  <div class="band">
    <div class="tiles">
      <div class="tile"><div class="k">Total recorded</div>
        <div class="v"><?= e(fabo_money($exp['spent'])) ?></div>
        <div class="k"><?= (int) $exp['count'] ?> entr<?= $exp['count'] === 1 ? 'y' : 'ies' ?></div></div>
      <div class="tile amber"><div class="k">Unpaid</div>
        <div class="v"><?= e(fabo_money($exp['unpaid'])) ?></div></div>
      <div class="tile green"><div class="k">GST input credit</div>
        <div class="v"><?= e(fabo_money($exp['input_credit'])) ?></div></div>
    </div>
  </div>

  <?php if (!$rows): ?>
    <div class="empty">Nothing recorded yet. Add the first entry below.</div>
  <?php else: ?>
    <div class="scrollx">
    <table class="ledger">
      <thead><tr><th>Date</th><th>Category</th><th>Vendor</th><th>Description</th>
        <th class="num">Amount</th><th class="num">Tax</th><th>Status</th><th class="acts"></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="when"><?= e(fabo_short_date((string) $r['spent_on'])) ?></td>
          <td class="ref"><?= e($r['category']) ?></td>
          <td><?= e($r['vendor'] ?? '') ?: '<span class="muted">&mdash;</span>' ?></td>
          <td><?= e($r['description'] ?? '') ?: '<span class="muted">&mdash;</span>' ?></td>
          <td class="num tot"><?= e(fabo_money_exact($r['amount'])) ?></td>
          <td class="num"><?= $r['tax_amount'] > 0 ? e(fabo_money_exact($r['tax_amount'])) : '&mdash;' ?>
              <?= $r['gst_credit'] ? ' <span class="pill green">credit</span>' : '' ?></td>
          <td><span class="tag <?= $r['paid'] ? 'paid' : 'draft' ?>"><?= $r['paid'] ? 'Paid' : 'Unpaid' ?></span></td>
          <td class="acts">
            <form method="post" onsubmit="return confirm('Remove this entry?')">
              <?= fabo_csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <button class="btn btn-danger btn-sm" name="action" value="expense_delete">Remove</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Record an expense</h2>
  <form method="post">
    <?= fabo_csrf_field() ?>
    <div class="cols">
      <div>
        <div class="field"><label>Date</label>
          <input type="date" name="spent_on" value="<?= e(date('Y-m-d')) ?>" required></div>
        <div class="field"><label>Category</label>
          <select name="category">
            <?php foreach (fabo_expense_categories() as $c): ?>
              <option value="<?= e($c) ?>"><?= e($c) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="field"><label>Vendor</label>
          <input name="vendor" placeholder="Who was paid"></div>
      </div>
      <div>
        <div class="field"><label>Description</label>
          <input name="description" placeholder="What it was for"></div>
        <div class="field"><label>Amount</label>
          <input name="amount" type="number" step="0.01" min="0" required></div>
        <div class="field"><label>Tax within it</label>
          <input name="tax_amount" type="number" step="0.01" min="0" value="0"></div>
        <div class="field">
          <label><input type="checkbox" name="gst_credit" value="1"> GST input credit can be claimed</label><br>
          <label><input type="checkbox" name="paid" value="1" checked> Already paid</label>
        </div>
      </div>
    </div>
    <div class="field"><label>Notes</label>
      <textarea name="notes" placeholder="Invoice reference, approval, anything worth keeping"></textarea></div>
    <div class="formfoot">
      <button class="btn btn-primary" name="action" value="expense_add">Record it</button>
    </div>
  </form>
</div>
<?php endif; ?>

</div>
<?php fabo_foot();

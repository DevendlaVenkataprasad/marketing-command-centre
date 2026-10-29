<?php
/**
 * Admin — Finance. The accounts desk: what we earn, what we spend, and what
 * we owe the department.
 *
 * Three tabs live here — Overview, Compliance and Expenses. Invoices keeps
 * its own page because the register there is substantial; the tab strip is
 * what makes all four read as one desk. Every tab takes ?client=<id> and
 * narrows to that client, so the same page answers "how are we doing" and
 * "how is this account doing" without a second screen.
 *
 * The Overview opens with a card per tab. A desk with four tabs has to say
 * what is behind each one, or the three that are not open go unread.
 */

require_once __DIR__ . '/../lib/ui.php';
require_once __DIR__ . '/../lib/finance.php';
require_once __DIR__ . '/../lib/charts.php';
require_once __DIR__ . '/../lib/notify.php';
fabo_boot();
fabo_require_admin();

header('X-Robots-Tag: noindex, nofollow');

$viewer = fabo_admin_viewer();
$tab    = (string) ($_GET['tab'] ?? 'overview');
if (!in_array($tab, ['overview', 'compliance', 'expenses'], true)) {
    $tab = 'overview';
}
$client = max(0, (int) ($_GET['client'] ?? 0));
$flash  = null;

$clients = fabo_all('SELECT id, name, organisation FROM clients
                     WHERE kind = "client" ORDER BY organisation, name');
$picked = null;
foreach ($clients as $c) {
    if ((int) $c['id'] === $client) { $picked = $c; }
}
if ($client > 0 && !$picked) {
    $client = 0;      // a client that has since been removed
}
/** null means "everyone"; an id narrows every figure on the page. */
$scope     = $client > 0 ? $client : null;
$scopeName = $picked ? ($picked['organisation'] ?: $picked['name']) : '';

/**
 * The period every figure is read through. null is all time. Invoices go in
 * by issue date, spend by the date it was spent, filings by when they fall
 * due — so one choice narrows the whole desk consistently.
 */
$period = (string) ($_GET['period'] ?? 'all');
if (!array_key_exists($period, fabo_finance_periods())) {
    $period = 'all';
}
$win      = fabo_finance_window($period);
$winLabel = $win['label'] ?? 'All time';

/* ------------------------------------------------------------- actions */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    fabo_csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'filing_add') {
        [$id, $msg] = fabo_filing_create(
            (int) ($_POST['client_id'] ?? 0),
            (string) ($_POST['kind'] ?? ''),
            (string) ($_POST['period_label'] ?? ''),
            (string) ($_POST['due_date'] ?? ''),
            (string) ($_POST['period_start'] ?? ''),
            (string) ($_POST['period_end'] ?? ''),
            (string) ($_POST['notes'] ?? '')
        );
        $flash = [$id ? 'ok' : 'err', $msg];

    } elseif ($action === 'filing_generate') {
        [$n, $msg] = fabo_filing_generate(
            (int) ($_POST['client_id'] ?? 0),
            (string) ($_POST['kind'] ?? ''),
            (int) ($_POST['year'] ?? date('Y'))
        );
        $flash = [$n ? 'ok' : 'err', $msg];

    } elseif ($action === 'filing_state') {
        [$ok, $msg] = fabo_filing_set_state(
            (int) ($_POST['id'] ?? 0),
            (string) ($_POST['state'] ?? ''),
            (string) ($_POST['ack_no'] ?? ''),
            (float) ($_POST['tax_amount'] ?? 0),
            (float) ($_POST['penalty'] ?? 0),
            (string) ($_POST['filed_on'] ?? '')
        );
        $flash = [$ok ? 'ok' : 'err', $msg];

    } elseif ($action === 'filing_delete') {
        [$ok, $msg] = fabo_filing_delete((int) ($_POST['id'] ?? 0));
        $flash = [$ok ? 'ok' : 'err', $msg];

    } elseif ($action === 'expense_add') {
        [$id, $msg] = fabo_expense_create(
            (int) ($_POST['client_id'] ?? 0),
            (string) ($_POST['spent_on'] ?? ''),
            (string) ($_POST['category'] ?? ''),
            (string) ($_POST['vendor'] ?? ''),
            (string) ($_POST['description'] ?? ''),
            (float) ($_POST['amount'] ?? 0),
            (float) ($_POST['tax_amount'] ?? 0),
            !empty($_POST['gst_credit']),
            !empty($_POST['billable']),
            !empty($_POST['paid']),
            (string) ($_POST['notes'] ?? '')
        );
        $flash = [$id ? 'ok' : 'err', $msg];

    } elseif ($action === 'expense_delete') {
        [$ok, $msg] = fabo_expense_delete((int) ($_POST['id'] ?? 0));
        $flash = [$ok ? 'ok' : 'err', $msg];
    }
}

$sum = fabo_finance_summary($scope, $win);
$fin = $sum['invoice'];
$exp = $sum['expense'];
$fil = $sum['filing'];

/** A link inside this page, keeping whichever client is being looked at. */
function fin_url(string $tab, int $client, array $extra = []): string
{
    global $period;
    $q = ['tab' => $tab] + $extra;
    if ($client > 0)      { $q['client'] = $client; }
    if ($period !== 'all') { $q['period'] = $period; }
    return 'finance.php?' . http_build_query($q);
}

/** One tile. Keeps the six-across rows honest about what each figure is. */
function fin_tile(string $label, string $value, string $tone = '', string $foot = ''): void
{
    echo '<div class="tile' . ($tone ? ' ' . e($tone) : '') . '">'
       . '<div class="k">' . e($label) . '</div>'
       . '<div class="v">' . $value . '</div>'
       . ($foot !== '' ? '<div class="k">' . $foot . '</div>' : '')
       . '</div>';
}

/** The filing being updated, when one was picked from the register. */
$editing = null;
if ($tab === 'compliance' && !empty($_GET['edit'])) {
    $editing = fabo_filing((int) $_GET['edit']);
}

fabo_head($viewer, 'Finance', 'finance');
?>
<div class="wrap-page">

<div class="pagehead">
  <h1>FINANCE<?= $scopeName ? ' &mdash; ' . strtoupper(e($scopeName)) : '' ?></h1>
  <div class="sub">
    <?= $scopeName
        ? 'Every figure below is <strong>' . e($scopeName) . '</strong> only'
        : 'Money in, money out, and the statutory filing calendar &mdash; across every client' ?><?=
       $win ? ', for <strong>' . e($winLabel) . '</strong>.' : '.' ?>
  </div>
</div>

<?php fabo_finance_tabs($tab, $client, '', $period); ?>

<?php if ($flash): ?>
  <div class="flash <?= $flash[0] === 'ok' ? 'ok' : 'err' ?>"><?= e($flash[1]) ?></div>
<?php endif; ?>

<form method="get" class="card filterbar">
  <input type="hidden" name="tab" value="<?= e($tab) ?>">
  <span class="grp">
    <label>Client</label>
    <select name="client" onchange="this.form.submit()">
      <option value="0">Every client</option>
      <?php foreach ($clients as $c): ?>
        <option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === $client ? 'selected' : '' ?>>
          <?= e($c['organisation'] ?: $c['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </span>
  <span class="grp">
    <label>Period</label>
    <select name="period" onchange="this.form.submit()">
      <?php foreach (fabo_finance_periods() as $k => $label): ?>
        <option value="<?= e($k) ?>" <?= $k === $period ? 'selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
  </span>
  <?php if ($win): ?>
    <span class="pill view"><?= e($winLabel) ?> &middot;
      <?= e(date('d M Y', strtotime($win['from']))) ?> to
      <?= e(date('d M Y', strtotime($win['to']))) ?></span>
  <?php endif; ?>
  <?php if ($client > 0 || $period !== 'all'): ?>
    <a class="btn btn-sm" href="finance.php?tab=<?= e($tab) ?>">Clear</a>
  <?php endif; ?>
  <noscript><button class="btn btn-sm btn-primary">Apply</button></noscript>
</form>


<?php if ($tab === 'overview'): ?>
<!-- ============================================================ OVERVIEW -->

<div class="card">
  <h2>What this desk holds</h2>
  <div class="deskguide">
    <a href="invoices.php<?= $client ? '?client=' . $client : '' ?>">
      <div class="t">Invoices</div>
      <div class="d">Raise a bill against a client, send it, and mark it paid.
        Each step emails them and posts to their portal.</div>
      <div class="s <?= $fin['overdue_count'] ? 'bad' : 'good' ?>">
        <?= $fin['overdue_count']
            ? (int) $fin['overdue_count'] . ' overdue &middot; ' . e(fabo_money($fin['outstanding'])) . ' outstanding'
            : e(fabo_money($fin['outstanding'])) . ' outstanding' ?></div>
    </a>
    <a href="<?= e(fin_url('compliance', $client)) ?>">
      <div class="t">Compliance</div>
      <div class="d">The filing calendar &mdash; GSTR-1 and 3B, income tax, TDS,
        PF and ESI, ROC. Lay a whole year out in one click.</div>
      <div class="s <?= $fil['overdue'] ? 'bad' : ($fil['due_soon'] ? 'warn' : 'good') ?>">
        <?= $fil['count'] === 0 ? 'Nothing on the register yet'
            : ((int) $fil['overdue'] . ' overdue &middot; ' . (int) $fil['due_soon'] . ' due in 15 days') ?></div>
    </a>
    <a href="<?= e(fin_url('expenses', $client)) ?>">
      <div class="t">Expenses</div>
      <div class="d">What we spend, booked to a client or to overhead.
        Booking it to a client is what makes their margin real.</div>
      <div class="s"><?= $exp['count'] === 0 ? 'Nothing recorded yet'
            : e(fabo_money($exp['spent'])) . ' across ' . (int) $exp['count'] . ' entries' ?></div>
    </a>
  </div>
</div>

<div class="card">
  <h2>Where the money stands</h2>

  <div class="band">
    <h3>Money in</h3>
    <div class="tiles">
      <?php
        fin_tile('Revenue', e(fabo_money($sum['revenue'])), '', 'invoiced, drafts excluded');
        fin_tile('Collected', e(fabo_money($fin['paid'])), 'green',
                 $fin['collected_pct'] === null ? '&mdash;'
                   : e(fabo_pct($fin['collected_pct'], 0)) . ' of revenue');
        fin_tile('Outstanding', e(fabo_money($fin['outstanding'])), 'amber', 'sent, not yet paid');
        fin_tile('Overdue', e(fabo_money($fin['overdue_value'])),
                 $fin['overdue_count'] ? 'red' : '',
                 (int) $fin['overdue_count'] . ' invoice' . ($fin['overdue_count'] === 1 ? '' : 's'));
        fin_tile('Drafts', (string) (int) $fin['drafts'], '', 'not sent yet');
      ?>
    </div>
  </div>

  <div class="band">
    <h3>Money out</h3>
    <div class="tiles">
      <?php
        fin_tile('Spend', e(fabo_money($exp['spent'])), '',
                 (int) $exp['count'] . ' entr' . ($exp['count'] === 1 ? 'y' : 'ies'));
        fin_tile('Unpaid bills', e(fabo_money($exp['unpaid'])),
                 $exp['unpaid'] > 0 ? 'amber' : '', 'we still owe');
        fin_tile('Billable on', e(fabo_money($exp['billable'])), '', 'recoverable from clients');
        fin_tile('GST input credit', e(fabo_money($exp['input_credit'])), 'green', 'claimable');
      ?>
    </div>
  </div>

  <div class="band">
    <h3>What is left</h3>
    <div class="tiles">
      <?php
        fin_tile('Gross profit', e(fabo_money($sum['profit'])),
                 $sum['profit'] >= 0 ? 'green' : 'red',
                 $sum['margin_pct'] === null ? '&mdash;'
                   : e(fabo_pct($sum['margin_pct'], 0)) . ' margin');
        fin_tile('Still to collect', e(fabo_money($fin['outstanding'])), '', 'cash not yet in');
        fin_tile('Tax paid', e(fabo_money($fil['tax_paid'])), '', 'on filed returns');
        fin_tile('Penalties', e(fabo_money($fil['penalty'])),
                 $fil['penalty'] > 0 ? 'red' : '', 'late fees incurred');
      ?>
    </div>
  </div>
</div>

<div class="card">
  <h2>Trends</h2>
  <div class="chartgrid">
    <?php
      $pnl   = fabo_finance_pnl($scope, 6, $win);
      $mKeys = array_keys($pnl);
      fabo_chart_bar('Revenue vs spend — last 6 months', $mKeys, [
          ['name' => 'Revenue', 'color' => 'var(--s1)',
           'values' => array_map(static fn($v) => $v['revenue'], array_values($pnl))],
          ['name' => 'Spend',   'color' => 'var(--s4)',
           'values' => array_map(static fn($v) => $v['expense'], array_values($pnl))],
      ], 'money');

      fabo_chart_line('Gross profit — last 6 months', $mKeys, [
          ['name' => 'Profit', 'color' => 'var(--s3)',
           'values' => array_map(static fn($v) => $v['margin'], array_values($pnl))],
      ], 'money');

      $monthly = fabo_invoice_monthly($scope, 6, $win);
      fabo_chart_bar('Invoiced vs collected — last 6 months', array_keys($monthly), [
          ['name' => 'Invoiced',  'color' => 'var(--s1)',
           'values' => array_map(static fn($v) => $v['invoiced'], array_values($monthly))],
          ['name' => 'Collected', 'color' => 'var(--s3)',
           'values' => array_map(static fn($v) => $v['paid'], array_values($monthly))],
      ], 'money');

      $cats = fabo_expense_by_category($scope, $win);
      if ($cats) {
          $tones  = ['var(--s1)', 'var(--s2)', 'var(--s3)', 'var(--s4)', 'var(--s5)',
                     'var(--ink-mute)', 'var(--amber)', 'var(--green)', 'var(--red)', 'var(--line)'];
          $slices = [];
          $i = 0;
          foreach ($cats as $name => $val) {
              $slices[] = ['label' => $name, 'value' => $val,
                           'color' => $tones[$i++ % count($tones)]];
          }
          fabo_chart_pie('Spend by category', $slices, 'money');
      }
    ?>
  </div>
</div>

<?php if ($client === 0): $byClient = fabo_revenue_by_client(12, $win); if ($byClient): ?>
<div class="card">
  <h2>Revenue by client</h2>
  <p class="lead">What each account has billed, what has come in, and what it cost
     to serve. Pick a name to narrow the whole desk to it.</p>
  <div class="chartgrid" style="margin-bottom:14px">
    <?php
      $names = array_map(static fn($r) => $r['org'] !== '' ? $r['org'] : $r['name'], $byClient);
      fabo_chart_bar('Collected against still due', $names, [
          ['name' => 'Collected', 'color' => 'var(--s3)',
           'values' => array_map(static fn($r) => $r['collected'], $byClient)],
          ['name' => 'Still due', 'color' => 'var(--s4)',
           'values' => array_map(static fn($r) => $r['due'], $byClient)],
      ], 'money');
      fabo_chart_bar('Revenue against cost to serve', $names, [
          ['name' => 'Revenue', 'color' => 'var(--s1)',
           'values' => array_map(static fn($r) => $r['revenue'], $byClient)],
          ['name' => 'Cost',    'color' => 'var(--s5)',
           'values' => array_map(static fn($r) => $r['cost'], $byClient)],
      ], 'money');
    ?>
  </div>
  <div class="scrollx">
  <table class="ledger">
    <thead><tr><th>Client</th><th class="num">Invoices</th><th class="num">Revenue</th>
      <th class="num">Collected</th><th class="num">Still due</th><th class="num">Avg invoice</th>
      <th class="num">Cost</th><th class="num">Margin</th></tr></thead>
    <tbody>
    <?php foreach ($byClient as $r): ?>
      <tr>
        <td class="who"><a href="<?= e(fin_url('overview', $r['id'])) ?>"><?= e($r['org'] !== '' ? $r['org'] : $r['name']) ?></a></td>
        <td class="num"><?= (int) $r['invoices'] ?></td>
        <td class="num tot"><?= e(fabo_money_exact($r['revenue'])) ?></td>
        <td class="num"><?= e(fabo_money_exact($r['collected'])) ?></td>
        <td class="num"><?= $r['due'] > 0 ? e(fabo_money_exact($r['due'])) : '&mdash;' ?></td>
        <td class="num"><?= e(fabo_money_exact($r['avg'])) ?></td>
        <td class="num"><?= $r['cost'] > 0 ? e(fabo_money_exact($r['cost'])) : '&mdash;' ?></td>
        <td class="num"><?= e(fabo_money_exact($r['margin'])) ?>
          <?= $r['margin_pct'] === null ? '' : ' <span class="pill '
              . ($r['margin_pct'] >= 0 ? 'green' : 'red') . '">'
              . e(fabo_pct($r['margin_pct'], 0)) . '</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; endif; ?>

<?php
  // The GST position over whatever period is chosen; the financial year
  // that is running now when that period is "all time".
  if ($win) {
      $gstFrom  = $win['from'];
      $gstTo    = $win['to'];
      $gstLabel = $winLabel;
  } else {
      $fy       = fabo_fy_of();
      $gstFrom  = sprintf('%04d-04-01', $fy);
      $gstTo    = sprintf('%04d-03-31', $fy + 1);
      $gstLabel = 'FY ' . $fy . '-' . substr((string) ($fy + 1), 2);
  }
  $gst = fabo_gst_summary($gstFrom, $gstTo, $scope);
?>
<div class="cols">
  <div class="card">
    <h2>GST position &mdash; <?= e($gstLabel) ?></h2>
    <p class="lead">Tax charged out against credit on what we bought. An indication
       to file against, not the return itself.</p>
    <table class="ledger">
      <tbody>
        <tr><td>Taxable value</td><td class="num"><?= e(fabo_money_exact($gst['taxable'])) ?></td></tr>
        <tr><td>Output tax &mdash; charged on our invoices</td>
            <td class="num"><?= e(fabo_money_exact($gst['output'])) ?></td></tr>
        <tr><td>Input credit &mdash; on what we bought</td>
            <td class="num">&minus; <?= e(fabo_money_exact($gst['credit'])) ?></td></tr>
        <tr><td class="tot">Net payable</td>
            <td class="num tot"><?= e(fabo_money_exact($gst['payable'])) ?></td></tr>
      </tbody>
    </table>
  </div>

  <?php $soon = fabo_filings_upcoming(45, $scope); ?>
  <div class="card">
    <h2>Falling due in the next 45 days</h2>
    <?php if (!$soon): ?>
      <p class="muted">Nothing due in the next 45 days.
        <a href="<?= e(fin_url('compliance', $client)) ?>">Open the register</a>
        to lay a year out.</p>
    <?php else: ?>
    <table class="ledger">
      <thead><tr><th>Due</th><th>Return</th><th>Period</th><th>Whose</th></tr></thead>
      <tbody>
      <?php foreach ($soon as $f): $late = fabo_filing_overdue($f); ?>
        <tr>
          <td class="<?= $late ? 'late' : 'when' ?>"><?= e(fabo_due_phrase($f['due_date'])) ?></td>
          <td><a href="<?= e(fin_url('compliance', (int) $f['client_id'], ['edit' => (int) $f['id']])) ?>"><?= e(fabo_filing_label($f['kind'])) ?></a></td>
          <td><?= e($f['period_label']) ?></td>
          <td><?= e($f['cname'] ?: 'Our own') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>


<?php elseif ($tab === 'compliance'): ?>
<!-- ========================================================== COMPLIANCE -->

<?php if ($editing): ?>
<div class="card editpanel">
  <h2><?= e(fabo_filing_label($editing['kind'])) ?> &mdash; <?= e($editing['period_label']) ?></h2>
  <p class="lead">
    <?= e($editing['client_name'] ?: 'Our own') ?> &middot;
    due <?= e(date('d M Y', strtotime($editing['due_date']))) ?>
    (<?= e(fabo_due_phrase($editing['due_date'])) ?>)
  </p>
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
          <div class="hint">Later than the due date is recorded as late, whatever the status says.</div></div>
        <div class="field"><label>Acknowledgement number</label>
          <input name="ack_no" value="<?= e($editing['ack_no'] ?? '') ?>" placeholder="ARN / token from the portal"></div>
      </div>
      <div>
        <div class="field"><label>Tax paid</label>
          <input name="tax_amount" type="number" step="0.01" min="0" value="<?= e((string) $editing['tax_amount']) ?>"></div>
        <div class="field"><label>Penalty or late fee</label>
          <input name="penalty" type="number" step="0.01" min="0" value="<?= e((string) $editing['penalty']) ?>"></div>
        <?php if ($editing['period_start']): ?>
          <div class="field"><label>Covers</label>
            <div class="muted"><?= e(date('d M Y', strtotime($editing['period_start']))) ?>
              &ndash; <?= e(date('d M Y', strtotime((string) $editing['period_end']))) ?></div></div>
        <?php endif; ?>
      </div>
    </div>
    <div class="formfoot">
      <button class="btn btn-primary" name="action" value="filing_state">Save</button>
      <a class="btn" href="<?= e(fin_url('compliance', $client)) ?>">Cancel</a>
      <?php if ($editing['state'] === 'pending'): ?>
        <button class="btn btn-danger" name="action" value="filing_delete"
                onclick="return confirm('Remove this filing from the register?')">Remove</button>
      <?php endif; ?>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <h2>How the register stands</h2>
  <div class="band">
    <div class="tiles">
      <?php
        fin_tile('Overdue', (string) (int) $fil['overdue'], $fil['overdue'] ? 'red' : '', 'past the due date');
        fin_tile('Due in 15 days', (string) (int) $fil['due_soon'], $fil['due_soon'] ? 'amber' : '', 'coming up');
        fin_tile('Still open', (string) (int) $fil['pending'], '', 'not yet filed');
        fin_tile('Filed on time', (string) (int) $fil['filed'], 'green',
                 $fil['on_time_pct'] === null ? '&mdash;' : e(fabo_pct($fil['on_time_pct'], 0)) . ' of those due');
        fin_tile('Filed late', (string) (int) $fil['late'], $fil['late'] ? 'red' : '', 'after the due date');
        fin_tile('Tax paid', e(fabo_money($fil['tax_paid'])), '', 'on filed returns');
      ?>
    </div>
  </div>

  <?php $groups = fabo_filing_by_group($scope, $win); if ($groups): ?>
  <?php // The same split as the chart that used to sit here, in a quarter
        // of the height: a handful of families needs a line each, not axes. ?>
  <table class="ledger" style="max-width:520px">
    <thead><tr><th>Family</th><th class="num">Completed</th>
      <th class="num">Still open</th><th class="num">Overdue</th></tr></thead>
    <tbody>
    <?php foreach ($groups as $name => $g): ?>
      <tr>
        <td class="ref"><?= e($name) ?></td>
        <td class="num"><?= (int) $g['done'] ?></td>
        <td class="num"><?= (int) $g['pending'] ?></td>
        <td class="num"><?= $g['overdue']
            ? '<span class="tag overdue">' . (int) $g['overdue'] . '</span>' : '&mdash;' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<div class="card">
  <h2>The register<?= $scopeName ? ' &mdash; ' . e($scopeName) : '' ?></h2>
  <?php $rows = fabo_filings($scope, '', '', 400, $win); if (!$rows): ?>
    <div class="empty">Nothing on the register yet. Use <strong>Lay out a year</strong>
      below to generate a whole year of a return at once, or add a single filing.</div>
  <?php else: ?>
  <div class="scrollx">
  <table class="ledger">
    <thead><tr><th>Due</th><th>Return</th><th>Period</th><th>Whose</th>
      <th>Status</th><th class="num">Tax</th><th class="num">Penalty</th>
      <th>Acknowledgement</th><th class="acts"></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $f):
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
        <td><?= e($f['cname'] ?: 'Our own') ?></td>
        <td><span class="tag <?= e($tone) ?>"><?= e($late ? 'Overdue' : fabo_filing_state_label($f['state'])) ?></span>
            <?php if ($f['filed_on']): ?>
              <div class="muted" style="font-size:11.5px">on <?= e(date('d M Y', strtotime($f['filed_on']))) ?></div>
            <?php endif; ?></td>
        <td class="num"><?= $f['tax_amount'] > 0 ? e(fabo_money_exact($f['tax_amount'])) : '&mdash;' ?></td>
        <td class="num"><?= $f['penalty'] > 0 ? e(fabo_money_exact($f['penalty'])) : '&mdash;' ?></td>
        <td><?= e($f['ack_no'] ?? '') ?: '<span class="muted">&mdash;</span>' ?></td>
        <td class="acts">
          <a class="btn btn-sm<?= $f['state'] === 'pending' ? ' btn-primary' : '' ?>"
             href="<?= e(fin_url('compliance', $client, ['edit' => (int) $f['id']])) ?>">
            <?= $f['state'] === 'pending' ? 'Record filing' : 'Update' ?></a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

<div class="cols">
  <div class="card">
    <h2>Lay out a year</h2>
    <p class="lead">GSTR-3B alone is twelve rows a year. Pick the return and the
       financial year; the periods and due dates are worked out for you and each
       one stays editable. Running it twice adds nothing.</p>
    <form method="post">
      <?= fabo_csrf_field() ?>
      <div class="field"><label>Whose</label>
        <select name="client_id">
          <option value="0">Our own</option>
          <?php foreach ($clients as $c): ?>
            <option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === $client ? 'selected' : '' ?>>
              <?= e($c['organisation'] ?: $c['name']) ?></option>
          <?php endforeach; ?>
        </select></div>
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
        <div class="hint">April to March, so FY 2026&ndash;27 runs Apr 2026 to Mar 2027.</div></div>
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
      <div class="field"><label>Whose</label>
        <select name="client_id">
          <option value="0">Our own</option>
          <?php foreach ($clients as $c): ?>
            <option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === $client ? 'selected' : '' ?>>
              <?= e($c['organisation'] ?: $c['name']) ?></option>
          <?php endforeach; ?>
        </select></div>
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
      <div class="field"><label>Notes</label>
        <textarea name="notes" placeholder="Anything worth remembering at filing time"></textarea></div>
      <div class="formfoot">
        <button class="btn btn-primary" name="action" value="filing_add">Add to the register</button>
      </div>
    </form>
  </div>
</div>


<?php else: ?>
<!-- ============================================================ EXPENSES -->

<div class="card">
  <h2>What we spend<?= $scopeName ? ' on ' . e($scopeName) : '' ?></h2>
  <div class="band">
    <div class="tiles">
      <?php
        fin_tile('Total spend', e(fabo_money($exp['spent'])), '',
                 (int) $exp['count'] . ' entr' . ($exp['count'] === 1 ? 'y' : 'ies'));
        fin_tile('Unpaid', e(fabo_money($exp['unpaid'])), $exp['unpaid'] > 0 ? 'amber' : '', 'we still owe');
        fin_tile('Billable on', e(fabo_money($exp['billable'])), '', 'recoverable from clients');
        fin_tile('GST input credit', e(fabo_money($exp['input_credit'])), 'green', 'claimable');
        fin_tile('Revenue', e(fabo_money($sum['revenue'])), '', 'to compare against');
        fin_tile('Gross profit', e(fabo_money($sum['profit'])),
                 $sum['profit'] >= 0 ? 'green' : 'red',
                 $sum['margin_pct'] === null ? '&mdash;'
                   : e(fabo_pct($sum['margin_pct'], 0)) . ' margin');
      ?>
    </div>
  </div>

  <?php $cats = fabo_expense_by_category($scope, $win); if ($cats): ?>
  <div class="chartgrid">
    <?php
      fabo_chart_bar('Spend by category', array_keys($cats), [
          ['name' => 'Spend', 'color' => 'var(--s4)', 'values' => array_values($cats)],
      ], 'money');
      $pnl = fabo_finance_pnl($scope, 6, $win);
      fabo_chart_bar('Revenue vs spend — last 6 months', array_keys($pnl), [
          ['name' => 'Revenue', 'color' => 'var(--s1)',
           'values' => array_map(static fn($v) => $v['revenue'], array_values($pnl))],
          ['name' => 'Spend',   'color' => 'var(--s4)',
           'values' => array_map(static fn($v) => $v['expense'], array_values($pnl))],
      ], 'money');
    ?>
  </div>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Entries</h2>
  <?php $rows = fabo_expenses($scope, 300, $win); if (!$rows): ?>
    <div class="empty">Nothing recorded yet. Add the first entry below.</div>
  <?php else: ?>
  <div class="scrollx">
  <table class="ledger">
    <thead><tr><th>Date</th><th>Category</th><th>Vendor</th><th>Description</th>
      <th>Booked to</th><th class="num">Amount</th><th class="num">Tax</th>
      <th>Status</th><th class="acts"></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="when"><?= e(fabo_short_date((string) $r['spent_on'])) ?></td>
        <td class="ref"><?= e($r['category']) ?></td>
        <td><?= e($r['vendor'] ?? '') ?: '<span class="muted">&mdash;</span>' ?></td>
        <td><?= e($r['description'] ?? '') ?: '<span class="muted">&mdash;</span>' ?></td>
        <td><?= e($r['cname'] ?: 'Overhead') ?>
            <?= $r['billable'] ? ' <span class="pill green">billable</span>' : '' ?></td>
        <td class="num tot"><?= e(fabo_money_exact($r['amount'])) ?></td>
        <td class="num"><?= $r['tax_amount'] > 0 ? e(fabo_money_exact($r['tax_amount'])) : '&mdash;' ?>
            <?= $r['gst_credit'] ? ' <span class="pill green">credit</span>' : '' ?></td>
        <td><span class="tag <?= $r['paid'] ? 'paid' : 'draft' ?>"><?= $r['paid'] ? 'Paid' : 'Unpaid' ?></span></td>
        <td class="acts">
          <form method="post" onsubmit="return confirm('Remove this expense?')">
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
        <div class="field"><label>Description</label>
          <input name="description" placeholder="What it was for"></div>
      </div>
      <div>
        <div class="field"><label>Booked to</label>
          <select name="client_id">
            <option value="0">Overhead &mdash; not one client</option>
            <?php foreach ($clients as $c): ?>
              <option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === $client ? 'selected' : '' ?>>
                <?= e($c['organisation'] ?: $c['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="hint">Booking it to a client is what makes that client's margin real.</div></div>
        <div class="field"><label>Amount</label>
          <input name="amount" type="number" step="0.01" min="0" required></div>
        <div class="field"><label>Tax within it</label>
          <input name="tax_amount" type="number" step="0.01" min="0" value="0"></div>
        <div class="field">
          <label><input type="checkbox" name="gst_credit" value="1"> GST input credit can be claimed</label><br>
          <label><input type="checkbox" name="billable" value="1"> Billable on to the client</label><br>
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

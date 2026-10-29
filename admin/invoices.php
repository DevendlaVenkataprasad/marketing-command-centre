<?php
/**
 * Admin — Invoices. Raise one against a client, send it, mark it paid.
 *
 * A draft is yours to edit. Sending fixes the figures and tells the client;
 * marking it paid tells them too. Both events also land in your own inbox, so
 * the portal keeps a record of what was sent and when it settled.
 */

require_once __DIR__ . '/../lib/ui.php';
require_once __DIR__ . '/../lib/invoices.php';
require_once __DIR__ . '/../lib/finance.php';   // the Finance tab strip
require_once __DIR__ . '/../lib/notify.php';
fabo_boot();
fabo_require_admin();

header('X-Robots-Tag: noindex, nofollow');

$viewer = fabo_admin_viewer();
$flash  = null;

/** One label / value line. The value is already-escaped markup. */
function fabo_profile_row_safe(string $label, string $value): string
{
    return '<div class="prow"><span class="k">' . e($label) . '</span>'
         . '<span class="v">' . ($value !== '' ? $value : '<span class="none">—</span>')
         . '</span></div>';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    fabo_csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $id     = (int) ($_POST['id'] ?? 0);

    if ($action === 'create') {
        [$newId, $msg] = fabo_invoice_create(
            (int) ($_POST['client_id'] ?? 0),
            (string) ($_POST['issue_date'] ?? ''),
            (string) ($_POST['due_date'] ?? ''),
            (string) ($_POST['notes'] ?? ''),
            (float) ($_POST['tax_pct'] ?? 0)
        );
        if ($newId > 0) {
            header('Location: invoices.php?id=' . $newId);
            exit;
        }
        $flash = ['err', $msg];

    } elseif ($action === 'bulk_send' || $action === 'bulk_paid') {
        // The tick boxes on the register act on a whole selection at once —
        // sending a month's invoices one click at a time is the main reason
        // a billing run gets left half done.
        $want = $action === 'bulk_send' ? 'sent' : 'paid';
        $from = $action === 'bulk_send' ? 'draft' : 'sent';
        $done = 0;
        $skip = 0;
        foreach ((array) ($_POST['pick'] ?? []) as $pid) {
            $inv = fabo_invoice((int) $pid);
            if (!$inv || $inv['state'] !== $from) {
                $skip++;
                continue;
            }
            [$ok] = fabo_invoice_set_state((int) $pid, $want);
            $ok ? $done++ : $skip++;
        }
        $flash = $done
            ? ['ok', $done . ' invoice' . ($done === 1 ? '' : 's') . ' marked '
                     . $want . ($skip ? ', ' . $skip . ' skipped' : '') . '.']
            : ['err', 'Nothing to do — tick invoices that are ' . $from . '.'];

    } elseif ($id > 0) {
        switch ($action) {
            case 'items':
                $lines = [];
                foreach ((array) ($_POST['desc'] ?? []) as $i => $d) {
                    $lines[] = [
                        'description' => (string) $d,
                        'quantity'    => (float) (($_POST['qty'][$i] ?? 1) ?: 1),
                        'rate'        => (float) ($_POST['rate'][$i] ?? 0),
                    ];
                }
                [$ok, $msg] = fabo_invoice_save_items($id, $lines, (float) ($_POST['tax_pct'] ?? 0));
                $flash = [$ok ? 'ok' : 'err', $msg];
                break;

            case 'send':
                [$ok, $msg] = fabo_invoice_set_state($id, 'sent');
                if ($ok) {
                    [$mailed, $detail] = fabo_announce_invoice(fabo_invoice($id), 'sent');
                    $msg .= $mailed
                        ? ' Emailed — ' . $detail . ', and a notice is on their portal.'
                        : ' A notice is on their portal, but the email did not go out (' . $detail . ').';
                }
                $flash = [$ok ? 'ok' : 'err', $msg];
                break;

            case 'paid':
                [$ok, $msg] = fabo_invoice_set_state($id, 'paid', (string) ($_POST['paid_note'] ?? ''));
                if ($ok) {
                    [$mailed, $detail] = fabo_announce_invoice(fabo_invoice($id), 'paid');
                    $msg .= $mailed
                        ? ' The client has been emailed — ' . $detail . '.'
                        : ' A notice is on their portal; the email did not go out (' . $detail . ').';
                }
                $flash = [$ok ? 'ok' : 'err', $msg];
                break;

            case 'cancel':
                [$ok, $msg] = fabo_invoice_set_state($id, 'cancelled');
                $flash = [$ok ? 'ok' : 'err', $msg];
                break;

            case 'delete':
                [$ok, $msg] = fabo_invoice_delete($id);
                $flash = [$ok ? 'ok' : 'err', $msg];
                if ($ok) {
                    header('Location: invoices.php');
                    exit;
                }
                break;
        }
    }
}

$editing = !empty($_GET['id']) ? fabo_invoice((int) $_GET['id']) : null;
$show    = in_array($_GET['show'] ?? '', array_keys(fabo_invoice_states()), true)
         ? (string) $_GET['show'] : '';

// The register reads through the same client and period as the rest of
// the Finance desk, so switching tabs does not silently change the scope.
$period = (string) ($_GET['period'] ?? 'all');
if (!array_key_exists($period, fabo_finance_periods())) {
    $period = 'all';
}
$win      = fabo_finance_window($period);
$onClient = max(0, (int) ($_GET['client'] ?? 0)) ?: null;
$list     = fabo_invoices($show, 300, $win, $onClient);
$sums    = fabo_invoice_totals();
$clients = fabo_all("SELECT id, name, organisation FROM clients
                     WHERE kind = 'client' AND revoked_at IS NULL
                     ORDER BY organisation, name");
$addOpen = is_array($flash) && $flash[0] === 'err' && ($_POST['action'] ?? '') === 'create';

fabo_head($viewer, 'Finance — Invoices', 'invoices');
?>
<div class="wrap-page">

<?php if ($flash): ?>
  <div class="flash <?= e($flash[0]) ?>"><?= e($flash[1]) ?></div>
<?php endif; ?>

<div class="pagehead">
  <h1>FINANCE &mdash; INVOICES</h1>
  <div class="sub">Raise an invoice against a client, send it, and mark it paid.
    Every step tells them by email and on their portal.</div>
</div>

<?php fabo_finance_tabs('invoices', (int) $onClient, '', $period); ?>

<div class="tiles">
  <div class="tile amber"><div class="k">Outstanding</div><div class="v"><?= e(fabo_money($sums['outstanding'])) ?></div></div>
  <div class="tile green"><div class="k">Paid</div><div class="v"><?= e(fabo_money($sums['paid'])) ?></div></div>
  <div class="tile red"><div class="k">Overdue</div><div class="v"><?= (int) $sums['overdue'] ?></div></div>
</div>

<?php if ($editing): ?>
  <?php
    $items  = fabo_invoice_items((int) $editing['id']);
    $isDraft = $editing['state'] === 'draft';
    $viewUrl = '../invoice.php?id=' . (int) $editing['id'];
  ?>
  <div class="card invcard">
    <div class="invtop">
      <div>
        <div class="invnum"><?= e($editing['number']) ?></div>
        <div class="invwho"><?= e($editing['client_name']) ?><?php
          if ($editing['client_org']): ?> · <?= e($editing['client_org']) ?><?php endif; ?></div>
      </div>
      <div class="invamt">
        <div class="k">Total</div>
        <div class="v"><?= e(fabo_money($editing['total'])) ?></div>
      </div>
    </div>

    <?php
      // Where this invoice is, and what happens next. Three steps, always in
      // the same order, so the flow is readable at a glance.
      $state = (string) $editing['state'];
      $at    = ['draft' => 0, 'sent' => 1, 'paid' => 2][$state] ?? 0;
      $steps = [
        ['Draft',  'Add the lines and check the figures'],
        ['Sent',   'The client has it, by email and on their portal'],
        ['Paid',   'Settled, and the client has been thanked'],
      ];
    ?>
    <?php if ($state === 'cancelled'): ?>
      <div class="flash err" style="margin:18px 0 0">This invoice was cancelled.</div>
    <?php else: ?>
    <ol class="invsteps">
      <?php foreach ($steps as $i => [$name, $hint]): ?>
        <li class="<?= $i < $at ? 'done' : ($i === $at ? 'now' : 'todo') ?>">
          <span class="dot"><?= $i < $at ? '&#10003;' : (int) ($i + 1) ?></span>
          <span class="txt"><strong><?= e($name) ?></strong><em><?= e($hint) ?></em></span>
        </li>
      <?php endforeach; ?>
    </ol>

    <div class="invnext">
      <?php if ($state === 'draft'): ?>
        <div class="say"><strong>Next:</strong> add the lines below, then send it.
          Once sent, the figures cannot change.</div>
        <form method="post" class="act">
          <?= fabo_csrf_field() ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
          <button class="btn btn-primary" name="action" value="send"
                  onclick="return confirm('Send this invoice to the client? The figures are fixed once sent.')">Send to client</button>
        </form>
      <?php elseif ($state === 'sent'): ?>
        <div class="say"><strong>Next:</strong> when the money arrives, mark it paid.
          Sent <?= e(date('d M Y', strtotime((string) $editing['sent_at']))) ?><?php
            if (fabo_invoice_overdue($editing)): ?> — <span class="late">now overdue</span><?php endif; ?>.</div>
        <form method="post" class="act">
          <?= fabo_csrf_field() ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
          <input name="paid_note" placeholder="Payment reference — optional" maxlength="180">
          <button class="btn btn-primary" name="action" value="paid">Mark as paid</button>
        </form>
      <?php else: ?>
        <div class="say">Settled on <?= e(date('d M Y', strtotime((string) $editing['paid_at']))) ?><?php
          if ($editing['paid_note']): ?> · <?= e($editing['paid_note']) ?><?php endif; ?>.
          Nothing further to do.</div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="proftable" style="margin-top:20px">
      <?= fabo_profile_row_safe('Email', $editing['client_email']
            ? e($editing['client_email'])
            : '<span class="none">none on file — they will only get the portal notice</span>') ?>
      <?= fabo_profile_row_safe('Issued', e(date('d M Y', strtotime((string) $editing['issue_date'])))) ?>
      <?= fabo_profile_row_safe('Due', $editing['due_date']
            ? e(date('d M Y', strtotime((string) $editing['due_date']))) : '—') ?>
    </div>

    <div class="formsec">
      <h3>Lines</h3>
      <?php if (!$isDraft): ?>
        <p class="lead">This invoice has been sent, so its figures are fixed.</p>
        <table class="data">
          <thead><tr><th>Description</th><th class="num">Qty</th><th class="num">Rate</th><th class="num">Amount</th></tr></thead>
          <tbody>
          <?php foreach ($items as $it): ?>
            <tr><td><?= e($it['description']) ?></td>
                <td class="num"><?= e(rtrim(rtrim(number_format((float) $it['quantity'], 2), '0'), '.')) ?></td>
                <td class="num"><?= e(fabo_money($it['rate'])) ?></td>
                <td class="num"><?= e(fabo_money($it['amount'])) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?>
        <form method="post">
          <?= fabo_csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
          <table class="data invitems">
            <thead><tr><th>Description</th><th class="num">Qty</th><th class="num">Rate</th></tr></thead>
            <tbody>
            <?php
              $rows = $items ?: [];
              for ($i = 0; $i < max(6, count($rows) + 2); $i++):
                $it = $rows[$i] ?? null;
            ?>
              <tr>
                <td><input name="desc[]" value="<?= e($it['description'] ?? '') ?>" placeholder="What is being charged"></td>
                <td class="num"><input name="qty[]" type="number" step="any" value="<?= $it ? e(rtrim(rtrim(number_format((float) $it['quantity'], 2, '.', ''), '0'), '.')) : '' ?>" placeholder="1"></td>
                <td class="num"><input name="rate[]" type="number" step="any" value="<?= $it ? e(rtrim(rtrim(number_format((float) $it['rate'], 2, '.', ''), '0'), '.')) : '' ?>" placeholder="0"></td>
              </tr>
            <?php endfor; ?>
            </tbody>
          </table>
          <div class="fieldrow" style="max-width:420px;margin-top:12px">
            <div class="field"><label>Tax %</label>
              <input name="tax_pct" type="number" step="any" value="<?= e(rtrim(rtrim(number_format((float) $editing['tax_pct'], 3, '.', ''), '0'), '.')) ?>"></div>
          </div>
          <div class="formfoot">
            <button class="btn btn-primary" name="action" value="items">Save lines</button>
            <span class="note">Blank descriptions are ignored.</span>
          </div>
        </form>
      <?php endif; ?>
    </div>

    <div class="formfoot">
      <a class="btn" href="<?= e($viewUrl) ?>" target="_blank" rel="noopener">View / print</a>
      <div class="spacer" style="flex:1"></div>
      <?php if ($isDraft): ?>
        <form method="post" style="display:inline">
          <?= fabo_csrf_field() ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
          <button class="btn btn-danger" name="action" value="delete"
                  onclick="return confirm('Delete this draft?')">Delete draft</button>
        </form>
      <?php elseif ($editing['state'] !== 'paid'): ?>
        <form method="post" style="display:inline">
          <?= fabo_csrf_field() ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
          <button class="btn btn-danger" name="action" value="cancel"
                  onclick="return confirm('Cancel this invoice?')">Cancel invoice</button>
        </form>
      <?php endif; ?>
      <a class="btn" href="invoices.php">Done</a>
    </div>
  </div>
<?php endif; ?>

<form method="get" class="card filterbar">
  <span class="grp">
    <label>Client</label>
    <select name="client" onchange="this.form.submit()">
      <option value="0">Every client</option>
      <?php foreach ($clients as $c): ?>
        <option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === (int) $onClient ? 'selected' : '' ?>>
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
  <?php if ($show !== ''): ?><input type="hidden" name="show" value="<?= e($show) ?>"><?php endif; ?>
  <?php if ($win): ?>
    <span class="pill view"><?= e($win['label']) ?> &middot;
      <?= e(date('d M Y', strtotime($win['from']))) ?> to
      <?= e(date('d M Y', strtotime($win['to']))) ?></span>
  <?php endif; ?>
  <?php if ($onClient || $period !== 'all'): ?>
    <a class="btn btn-sm" href="invoices.php<?= $show !== '' ? '?show=' . e($show) : '' ?>">Clear</a>
  <?php endif; ?>
  <noscript><button class="btn btn-sm btn-primary">Apply</button></noscript>
</form>

<div class="card">
  <h2>All invoices</h2>
  <?php
    /** A state link that keeps whichever client and period are being read. */
    $stateUrl = static function (string $k) use ($onClient, $period): string {
        $q = [];
        if ($k !== '')          { $q['show']   = $k; }
        if ($onClient)          { $q['client'] = (int) $onClient; }
        if ($period !== 'all')  { $q['period'] = $period; }
        return 'invoices.php' . ($q ? '?' . http_build_query($q) : '');
    };
  ?>
  <div class="inboxbar">
    <a class="btn btn-sm<?= $show === '' ? ' btn-primary' : '' ?>" href="<?= e($stateUrl('')) ?>">All</a>
    <?php foreach (fabo_invoice_states() as $k => $v): ?>
      <a class="btn btn-sm<?= $show === $k ? ' btn-primary' : '' ?>" href="<?= e($stateUrl($k)) ?>"><?= e($v[0]) ?></a>
    <?php endforeach; ?>
  </div>
  <?php if (!$list): ?>
    <div class="empty">No invoices yet. Use <strong>Raise an invoice</strong> below.</div>
  <?php else: ?>
  <form method="post" id="regform">
    <?= fabo_csrf_field() ?>
    <div class="scrollx">
    <table class="ledger">
      <thead><tr>
        <th class="pick"><input type="checkbox" id="pickall" title="Select every invoice below"></th>
        <th>Number</th><th>Customer</th><th>Invoice Date</th><th>Due Date</th>
        <th>Sent On</th><th class="num">Tax Excluded</th><th class="num">Total</th>
        <th class="num">Amount Due</th><th>Status</th><th class="acts"></th>
      </tr></thead>
      <tbody>
      <?php foreach ($list as $r):
          $late = fabo_invoice_overdue($r);
          $due  = $r['state'] === 'paid' ? 0.0 : (float) $r['total'];
          $open = 'invoices.php?id=' . (int) $r['id'];
      ?>
        <tr<?= $r['state'] === 'cancelled' ? ' class="void"' : '' ?>>
          <td class="pick"><input type="checkbox" name="pick[]" value="<?= (int) $r['id'] ?>"></td>
          <td class="ref"><a href="<?= e($open) ?>"><?= e($r['number']) ?></a></td>
          <td class="who"><a href="<?= e($open) ?>"><?= e($r['client_org'] ?: $r['client_name']) ?></a></td>
          <td class="when"><?= e(fabo_short_date((string) $r['issue_date'])) ?></td>
          <td class="<?= $late ? 'late' : 'when' ?>"><?= e(fabo_due_phrase($r['due_date'])) ?></td>
          <td class="when"><?= $r['sent_at']
                ? e(fabo_short_date((string) $r['sent_at'])) : '' ?></td>
          <td class="num"><?= e(fabo_money_exact($r['subtotal'])) ?></td>
          <td class="num tot"><?= e(fabo_money_exact($r['total'])) ?></td>
          <td class="num"><?= e(fabo_money_exact($due)) ?></td>
          <td><span class="tag <?= e($late && $r['state'] === 'sent' ? 'overdue' : $r['state']) ?>"><?=
                e($late && $r['state'] === 'sent' ? 'Overdue' : fabo_invoice_label((string) $r['state'])) ?></span></td>
          <td class="acts">
            <a class="btn btn-sm" href="<?= e($open) ?>">Open</a>
            <?php if ($r['state'] === 'sent'): ?>
              <button class="btn btn-primary btn-sm" name="action" value="paid"
                      formnovalidate onclick="this.form.querySelector('#id_one').value=<?= (int) $r['id'] ?>;
                      return confirm('Mark <?= e($r['number']) ?> as paid?')">Mark paid</button>
            <?php elseif ($r['state'] === 'draft'): ?>
              <button class="btn btn-sm" name="action" value="send"
                      formnovalidate onclick="this.form.querySelector('#id_one').value=<?= (int) $r['id'] ?>;
                      return confirm('Send <?= e($r['number']) ?> to the client?')">Send</button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <input type="hidden" name="id" id="id_one" value="0">
    <div class="bulkbar" id="bulkbar" hidden>
      <span class="n"><span id="pickn">0</span> selected</span>
      <button class="btn btn-sm" name="action" value="bulk_send"
              onclick="return confirm('Send every selected draft to its client?')">Send drafts</button>
      <button class="btn btn-sm btn-primary" name="action" value="bulk_paid"
              onclick="return confirm('Mark every selected invoice as paid?')">Mark paid</button>
      <button class="btn btn-sm" type="button" id="pickclear">Clear</button>
    </div>
  </form>
  <script>
  /* The tick boxes only earn their column if they can act on a selection,
     so the bar appears as soon as one is ticked and hides when none are. */
  (function () {
    var form = document.getElementById('regform');
    if (!form) { return; }
    var all = document.getElementById('pickall'),
        bar = document.getElementById('bulkbar'),
        n   = document.getElementById('pickn');
    function boxes() { return form.querySelectorAll('input[name="pick[]"]'); }
    function sync() {
      var on = form.querySelectorAll('input[name="pick[]"]:checked').length;
      n.textContent = on;
      bar.hidden = on === 0;
      if (all) { all.checked = on > 0 && on === boxes().length; }
    }
    if (all) {
      all.addEventListener('change', function () {
        boxes().forEach(function (b) { b.checked = all.checked; });
        sync();
      });
    }
    form.addEventListener('change', function (e) {
      if (e.target.name === 'pick[]') { sync(); }
    });
    document.getElementById('pickclear').addEventListener('click', function () {
      boxes().forEach(function (b) { b.checked = false; });
      if (all) { all.checked = false; }
      sync();
    });
    sync();
  })();
  </script>
  <?php endif; ?>
</div>

<details class="addbox"<?= $addOpen ? ' open' : '' ?>>
  <summary><span class="chev">&#43;</span> Raise an invoice</summary>
  <div class="card">
    <form method="post">
      <?= fabo_csrf_field() ?>
      <div class="fieldrow">
        <div class="field"><label>Client</label>
          <select name="client_id" required>
            <option value="">— choose —</option>
            <?php foreach ($clients as $c): ?>
              <option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?><?= $c['organisation'] ? ' · ' . e($c['organisation']) : '' ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field"><label>Issue date</label>
          <input name="issue_date" type="date" value="<?= e(date('Y-m-d')) ?>"></div>
        <div class="field"><label>Due date</label>
          <input name="due_date" type="date" value="<?= e(date('Y-m-d', strtotime('+15 days'))) ?>"></div>
        <div class="field"><label>Tax %</label>
          <input name="tax_pct" type="number" step="any" value="18"></div>
      </div>
      <div class="field"><label>Notes on the invoice</label>
        <textarea name="notes" placeholder="Payment terms, bank details, a reference — anything the client should read"></textarea></div>
      <div class="formfoot">
        <button class="btn btn-primary" name="action" value="create">Create draft</button>
        <span class="note">You add the lines on the next screen, then send it.</span>
      </div>
    </form>
  </div>
</details>

</div>
<?php fabo_foot();

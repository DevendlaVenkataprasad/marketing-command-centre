<?php
/**
 * Deletion requests waiting on the head of an organisation.
 *
 * A client heads their own organisation, so their staff and their
 * sub-accounts ask them, not us, when they want a record destroyed. This is
 * the same desk the administrator has, for their own people only: a request
 * raised inside one organisation is never seen by another, and never
 * reaches us.
 *
 * Only a head opens this page. Anyone else has nothing to decide.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/deletions.php';
require_once __DIR__ . '/lib/notify.php';
fabo_boot();

header('X-Robots-Tag: noindex, nofollow');

$viewer = fabo_portal_viewer((string) ($_GET['k'] ?? ''));
if (!$viewer) {
    header('Location: login.php?next=' . rawurlencode('deletions.php'));
    exit;
}
if (!fabo_may_delete($viewer)) {
    fabo_deny('Only the head of your organisation answers deletion requests.', 403);
}

$me    = $viewer['client'];
$mine  = (int) $me['id'];
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    fabo_csrf_check();
    $id    = (int) ($_POST['id'] ?? 0);
    $state = (string) ($_POST['do'] ?? '');
    $note  = (string) ($_POST['note'] ?? '');

    $req = fabo_delete_request($id);
    // fabo_delete_decide checks the request is ours before it destroys
    // anything; this page never reaches another organisation's queue.
    [$ok, $msg] = fabo_delete_decide($id, $state, $note, (string) $me['name'], $viewer);
    if ($ok && $req) {
        fabo_announce_delete_decision($req, $state, $note);
    }
    $flash = [$ok ? 'ok' : 'err', $msg];
}

$show    = in_array($_GET['show'] ?? '', ['approved', 'rejected'], true)
         ? (string) $_GET['show'] : 'pending';
$items   = fabo_delete_requests($show, 200, $mine);
$pending = fabo_delete_pending_count($mine);

fabo_head($viewer, 'Deletion requests', 'deletions');
?>
<div class="wrap-page">

<div class="pagehead">
  <h1>DELETION REQUESTS</h1>
  <div class="sub">
    <?= $pending
        ? '<strong>' . (int) $pending . '</strong> waiting on you.'
        : 'Nothing waiting on you.' ?>
    Your team and the accounts you created ask here before a record is destroyed.
  </div>
</div>

<?php if ($flash): ?>
  <div class="flash <?= $flash[0] === 'ok' ? 'ok' : 'err' ?>"><?= e($flash[1]) ?></div>
<?php endif; ?>

<div class="card">
  <div class="inboxbar">
    <?php foreach (['pending' => 'Waiting', 'approved' => 'Approved', 'rejected' => 'Refused'] as $k => $label): ?>
      <a class="btn btn-sm<?= $show === $k ? ' btn-primary' : '' ?>"
         href="<?= e(fabo_link($viewer, 'deletions.php', $k === 'pending' ? [] : ['show' => $k])) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if (!$items): ?>
    <div class="empty">
      <?= $show === 'pending'
          ? 'Nothing waiting. When someone in your organisation asks to delete a record, it appears here.'
          : 'Nothing here yet.' ?>
    </div>
  <?php else: ?>
    <div class="scrollx">
    <table class="ledger">
      <thead><tr><th>Asked</th><th>What</th><th>Who asked</th><th>Why</th>
        <th>Status</th><th class="acts"></th></tr></thead>
      <tbody>
      <?php foreach ($items as $r): ?>
        <tr>
          <td class="when"><?= e(fabo_short_date((string) $r['created_at'])) ?></td>
          <td class="ref"><?= e(fabo_delete_describe($r)) ?></td>
          <td><?= e($r['requested_by_name']) ?></td>
          <td><?= e((string) ($r['reason'] ?? '')) ?: '<span class="muted">&mdash;</span>' ?></td>
          <td>
            <?php if ($r['state'] === 'pending'): ?>
              <span class="tag draft">Waiting</span>
            <?php elseif ($r['state'] === 'approved'): ?>
              <span class="tag paid">Deleted</span>
            <?php else: ?>
              <span class="tag cancelled">Refused</span>
            <?php endif; ?>
            <?php if ($r['decided_at']): ?>
              <div class="muted" style="font-size:11.5px">
                by <?= e((string) $r['decided_by']) ?>
                on <?= e(date('d M Y', strtotime((string) $r['decided_at']))) ?></div>
              <?php if ($r['decision_note']): ?>
                <div class="muted" style="font-size:11.5px"><?= e((string) $r['decision_note']) ?></div>
              <?php endif; ?>
            <?php endif; ?>
          </td>
          <td class="acts">
            <?php if ($r['state'] === 'pending'): ?>
              <details>
                <summary class="btn btn-sm btn-primary">Decide</summary>
                <form method="post" style="margin-top:8px;min-width:240px">
                  <?= fabo_csrf_field() ?>
                  <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                  <div class="field"><label>Note</label>
                    <textarea name="note" placeholder="Needed if you refuse, so they know what to do next"></textarea></div>
                  <button class="btn btn-danger btn-sm" name="do" value="approved"
                          onclick="return confirm('Delete this for good? It cannot be undone.')">Delete it</button>
                  <button class="btn btn-sm" name="do" value="rejected">Refuse</button>
                </form>
              </details>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>

</div>
<?php fabo_foot();

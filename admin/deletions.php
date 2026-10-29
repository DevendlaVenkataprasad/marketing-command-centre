<?php
/**
 * Admin — Deletion requests.
 *
 * Nothing on a sheet is destroyed except from here. Each request says what,
 * who asked, and why; approving carries the deletion out, refusing needs a
 * reason so the person knows what to do next. Either way they are told.
 */

require_once __DIR__ . '/../lib/ui.php';
require_once __DIR__ . '/../lib/deletions.php';
require_once __DIR__ . '/../lib/notify.php';
fabo_boot();
fabo_require_admin();

header('X-Robots-Tag: noindex, nofollow');

$viewer = fabo_admin_viewer();
$flash  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    fabo_csrf_check();
    $id    = (int) ($_POST['id'] ?? 0);
    $state = (string) ($_POST['do'] ?? '');
    $note  = (string) ($_POST['note'] ?? '');

    $req = fabo_delete_request($id);
    // Only our own queue: a client's staff asking their own head is theirs.
    [$ok, $msg] = fabo_delete_decide($id, $state, $note, 'Admin', $viewer);
    if ($ok && $req) {
        fabo_announce_delete_decision($req, $state, $note);
    }
    $flash = [$ok ? 'ok' : 'err', $msg];
}

$show     = in_array($_GET['show'] ?? '', ['approved', 'rejected'], true)
          ? (string) $_GET['show'] : 'pending';
$items    = fabo_delete_requests($show, 200, 0);
$pending  = fabo_delete_pending_count(0);

fabo_head($viewer, 'Deletion requests', 'deletions');
?>
<div class="wrap-page">

<?php if ($flash): ?>
  <div class="flash <?= e($flash[0]) ?>"><?= e($flash[1]) ?></div>
<?php endif; ?>

<div class="pagehead">
  <h1>DELETION REQUESTS</h1>
  <div class="sub">Rows and documents are only ever removed from here.
    <?= $pending
        ? '<strong>' . (int) $pending . ' waiting on you.</strong>'
        : 'Nothing is waiting.' ?></div>
</div>

<div class="inboxbar">
  <?php foreach (['pending' => 'Waiting', 'approved' => 'Approved', 'rejected' => 'Refused'] as $k => $lab): ?>
    <a class="btn btn-sm<?= $show === $k ? ' btn-primary' : '' ?>"
       href="deletions.php?show=<?= e($k) ?>"><?= e($lab) ?><?= $k === 'pending' && $pending ? ' (' . (int) $pending . ')' : '' ?></a>
  <?php endforeach; ?>
</div>

<?php if (!$items): ?>
  <div class="card"><div class="empty">
    <?= $show === 'pending'
        ? 'No deletion requests are waiting. When a client or team member asks to remove a row or a document, it appears here.'
        : 'Nothing in this list yet.' ?>
  </div></div>
<?php else: ?>
  <div class="card inboxlist">
  <?php foreach ($items as $r): ?>
    <div class="notice<?= $r['state'] === 'pending' ? ' new' : '' ?>">
      <div class="body">
        <div class="t"><?= $r['state'] === 'pending' ? '<i class="dot"></i>' : '' ?><?= e(fabo_delete_describe($r)) ?></div>
        <div class="b">
          Asked by <strong><?= e($r['requested_by_name']) ?></strong>
          <?php if ($r['reason']): ?>— “<?= e($r['reason']) ?>”<?php endif; ?>
        </div>
        <div class="when"><?= e(date('d M Y, H:i', strtotime($r['created_at']))) ?>
          <?php if ($r['state'] !== 'pending'): ?>
            · <?= e($r['state'] === 'approved' ? 'approved' : 'refused') ?>
            by <?= e((string) $r['decided_by']) ?>
            <?= $r['decided_at'] ? e(date('d M Y, H:i', strtotime($r['decided_at']))) : '' ?>
            <?php if ($r['decision_note']): ?>— “<?= e($r['decision_note']) ?>”<?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
      <?php if ($r['state'] === 'pending'): ?>
        <div class="acts">
          <a class="btn btn-sm" href="<?= e($r['kind'] === 'file' ? 'sheet.php?sheet=' . rawurlencode($r['sheet_key']) . '&row=' . (int) $r['row_id']
                                                                  : 'sheet.php?sheet=' . rawurlencode($r['sheet_key']) . '&row=' . (int) $r['row_id']) ?>">Look at it</a>
          <form method="post" class="delform">
            <?= fabo_csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <input name="note" placeholder="Reason — required to refuse" maxlength="300">
            <button class="btn btn-danger btn-sm" name="do" value="approved"
                    onclick="return confirm('Delete this permanently? It cannot be undone.')">Approve &amp; delete</button>
            <button class="btn btn-sm" name="do" value="rejected">Refuse</button>
          </form>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  </div>
<?php endif; ?>

</div>
<?php fabo_foot();

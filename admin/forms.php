<?php
/**
 * Admin — Requisition Form Management.
 *
 * One link, shared with whoever needs a creative: a poster, a reel, a video.
 * What comes back lands on the Requisitions sheet and joins a queue, and the
 * queue tells the person who asked when their work will be delivered.
 *
 * This page is the desk for that queue: the link to hand out, what is waiting,
 * where each one sits, and the one thing the queue cannot work out by itself
 * — that a date has to move, and who needs telling.
 *
 * The turnaround each type of work gets is not set here. It belongs with the
 * rest of what a sheet is made of, on Sheet Management.
 */

require_once __DIR__ . '/../lib/ui.php';
require_once __DIR__ . '/../lib/forms.php';
require_once __DIR__ . '/../lib/rules.php';
require_once __DIR__ . '/../lib/requisitions.php';
require_once __DIR__ . '/../lib/manage.php';
fabo_boot();

/*
 * This file is both a page and a block.
 *
 * On its own it is the Requisition Forms desk. Included from the requisition
 * sheet — see lib/sheetpage.php — it is the lower half of that page instead,
 * so the queue and the links that fill it are in one place rather than two.
 * The markup is written once either way; two copies would drift, and the one
 * that drifted would be the one nobody was looking at.
 */
$fabo_forms_embed = !empty($GLOBALS['fabo_forms_embed']);

if ($fabo_forms_embed) {
    /* The host page has already worked out who this is and already refused
       anybody without the sheet. Access to the requisition sheet IS the
       right to manage its forms: they are one job, and needing a second
       permission to see the link that feeds a queue you already own was a
       distinction nobody asked for. */
    $viewer = $GLOBALS['fabo_forms_viewer'];
    $scope  = (int) $GLOBALS['fabo_forms_scope'];
} else {
    /* Reached directly — an old bookmark, or a link from before the merge.
       There is one place this work happens now, and it is the Requisition
       sheet, so that is where the visitor goes. Sending them rather than
       rendering a second copy is what stops the two drifting. */
    require_once __DIR__ . '/../lib/auth.php';
    $viewer = fabo_admin_signed_in()
        ? fabo_admin_viewer()
        : fabo_portal_viewer((string) ($_GET['k'] ?? ''));
    if (!$viewer) {
        header('Location: ../login.php');
        exit;
    }
    header('Location: ' . fabo_link($viewer,
        !empty($viewer['is_admin']) ? 'sheet.php' : '../c.php',
        ['sheet' => 'requisitions']));
    exit;
}
$flash  = null;

/* What this viewer may do, not just what they may see. Somebody holding the
   Requisition sheet read-only gets the queue and the promised dates; the
   controls that change them are not theirs and are not drawn. */
$fabo_forms_may = !$fabo_forms_embed
    || (string) ($GLOBALS['fabo_forms_perm'] ?? 'view') === 'edit';

/* What the queue readers below should be shown. null is everything, which
   is ours; a client passes their own id and sees only their own requests. */
$only = $scope > 0 ? $scope : null;

function fabo_form_expiry(string $choice): ?string
{
    switch ($choice) {
        case '7d':  return date('Y-m-d H:i:s', strtotime('+7 days'));
        case '30d': return date('Y-m-d H:i:s', strtotime('+30 days'));
        case '90d': return date('Y-m-d H:i:s', strtotime('+90 days'));
        default:    return null;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    fabo_csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $id     = (int) ($_POST['id'] ?? 0);

    try {
        if ($action === 'create') {
            $label = trim((string) ($_POST['label'] ?? ''));
            if ($label === '') {
                throw new InvalidArgumentException(
                    'Give the form a label — it appears on the form and in the audit trail.');
            }
            fabo_exec('INSERT INTO form_links (sheet_key, label, token, active, expires_at, client_id)
                       VALUES (?,?,?,1,?,?)', 'ssssi',
                      [FABO_REQ_SHEET, $label, fabo_form_new_token(),
                       fabo_form_expiry((string) ($_POST['duration'] ?? 'never')), $scope]);
            $flash = ['ok', 'Form created. Copy its link below and share it.'];

        } elseif ($action === 'extend') {
            [$row, $sent] = fabo_req_extend($id, (string) ($_POST['new_due'] ?? ''),
                                            (string) ($_POST['reason'] ?? ''));
            fabo_req_reschedule();
            $flash = ['ok', 'Moved to ' . fabo_req_day((string) $row['promised_due']) . '. '
                          . ($sent ? 'The person who asked has been told.'
                                   : 'Nobody was told — there is no email address on the row.')];

        } elseif ($action === 'resend') {
            $row = fabo_req_row($id, $only);
            if (!$row) {
                throw new InvalidArgumentException('That requisition is not yours.');
            }
            if (!$row) {
                throw new InvalidArgumentException('That requisition was not found.');
            }
            $ok    = fabo_req_tell_requester($row, '', '', true);   // the desk asked: send again
            $flash = $ok
                ? ['ok',  'Sent to ' . (string) $row['raised_by_email'] . '.']
                : ['err', 'It still would not send. The row says why, under Confirmation.'];

        } elseif ($action === 'requeue') {
            /* The desk asking for the whole queue to be planned afresh: the one
               time promised dates may move by themselves, and everybody whose
               date moved is told. */
            $moved = fabo_req_reschedule(true);
            $told  = 0;
            foreach ($moved as $m) {
                $told += fabo_req_tell_requester($m['row'], $m['was']) ? 1 : 0;
            }
            $flash = ['ok', $moved
                ? count($moved) . ' date' . (count($moved) === 1 ? '' : 's') . ' moved, '
                  . $told . ' told.'
                : 'The queue was already right — nothing moved.'];

        } elseif ($id > 0 && fabo_one('SELECT id FROM form_links WHERE id = ? AND client_id = ?',
                                      'ii', [$id, $scope])) {
            /* Matched on the scope as well as the id, so a form belonging to
               another organisation is simply not found rather than quietly
               acted on. */
            switch ($action) {
                case 'toggle':
                    fabo_exec('UPDATE form_links SET active = 1 - active WHERE id = ?', 'i', [$id]);
                    $flash = ['ok', 'Updated.'];
                    break;
                case 'rotate':
                    fabo_exec('UPDATE form_links SET token = ? WHERE id = ?', 'si',
                              [fabo_form_new_token(), $id]);
                    $flash = ['ok', 'New link generated — the old one no longer works.'];
                    break;
                case 'extend_link':
                    fabo_exec('UPDATE form_links SET expires_at = ?, active = 1 WHERE id = ?', 'si',
                              [fabo_form_expiry((string) ($_POST['duration'] ?? 'never')), $id]);
                    $flash = ['ok', 'Validity updated.'];
                    break;
                case 'delete':
                    fabo_exec('DELETE FROM form_links WHERE id = ?', 'i', [$id]);
                    $flash = ['ok', 'Form deleted. Requisitions it already collected stay on the sheet.'];
                    break;
            }
        }
    } catch (InvalidArgumentException $e) {
        $flash = ['err', $e->getMessage()];
    } catch (Throwable $e) {
        /* Anything the code did not see coming — a unique key most often.
           Caught here so it becomes a sentence on the page rather than a
           blank five-hundred with the work half done. */
        $flash = ['err', fabo_db_words($e)];
    }
    fabo_session();
    if ($flash) {
        $_SESSION['fabo_req_flash'] = $flash;
    }
    header('Location: ' . fabo_manage_back($viewer,
        (string) ($GLOBALS['fabo_forms_back'] ?? 'forms.php')));
    exit;
}

fabo_session();
if (!empty($_SESSION['fabo_req_flash'])) {
    $flash = $_SESSION['fabo_req_flash'];
    unset($_SESSION['fabo_req_flash']);
}

fabo_req_install_types($scope);

/* Only this sheet's forms. A link that writes somewhere else is not a
   requisition form, and this page does not pretend to manage one. */
$forms = fabo_all('SELECT * FROM form_links WHERE sheet_key = ? AND client_id = ?
                   ORDER BY created_at DESC',
                  'si', [FABO_REQ_SHEET, $scope]);
$queue = fabo_req_open_rows($only);
$done  = $only === null
    ? fabo_all('SELECT * FROM `requisitions`
                WHERE status IN ("Delivered","Cancelled","Rejected")
                ORDER BY COALESCE(delivered_on, updated_at) DESC LIMIT 12')
    : fabo_all('SELECT * FROM `requisitions`
                WHERE status IN ("Delivered","Cancelled","Rejected") AND client_id = ?
                ORDER BY COALESCE(delivered_on, updated_at) DESC LIMIT 12', 'i', [$only]);
$today = fabo_today();
$late  = 0;
foreach ($queue as $q) {
    if (!empty($q['promised_due']) && $q['promised_due'] < $today) { $late++; }
}
$baseUrl = fabo_portal_url();
$team    = fabo_req_team_emails($scope);

if (!$fabo_forms_embed) {
    fabo_head($viewer, 'Requisition Forms', 'forms');
}
?>
<?php if (!$fabo_forms_embed): ?>
<div class="wrap-page">
  <div class="pagehead">
    <h1>REQUISITION FORM MANAGEMENT</h1>
    <div class="sub">Share one link; what comes back joins a queue that answers
      the only question the person asking has &mdash; when will it be ready.</div>
  </div>
<?php else: ?>
<div class="formsembed">
  <h2 class="formsembed-head">Requisition forms &amp; queue</h2>
  <div class="hint">The link people fill in, what it promises, and where every
    request has got to. Everything here belongs to the sheet above.</div>
<?php endif; ?>

<?php if ($flash): ?>
  <div class="flash <?= e($flash[0]) ?>"><?= e($flash[1]) ?></div>
<?php endif; ?>

<?php if (!$team): ?>
  <div class="flash err">No team address is set, so nobody is being told when a
    requisition arrives. Set one under <strong>Requisition types</strong> on
    <a href="sheets.php?tab=requisitions">Sheet Management</a>.</div>
<?php endif; ?>

  <?php $unsent = fabo_req_unconfirmed($only); ?>
  <?php if ($unsent): ?>
    <!-- ================================ confirmations that did not go out -->
    <div class="card">
      <h2>Not confirmed <span class="ro"><?= count($unsent) ?></span></h2>
      <p class="rq-lead">These people asked for something and were never told
        when it lands. The reason the message bounced is on each row.</p>
      <div class="scrollx">
        <table class="data">
          <thead><tr><th>Requisition</th><th>Who</th><th>What went wrong</th>
            <th>Send it again</th></tr></thead>
          <tbody>
          <?php foreach ($unsent as $u): ?>
            <tr>
              <td><strong><?= e((string) ($u['req_id'] ?? '')) ?></strong>
                <div class="meta"><?= e((string) ($u['req_type'] ?? '')) ?></div></td>
              <td><?= e((string) ($u['raised_by'] ?? '')) ?>
                <div class="meta"><?= e((string) ($u['raised_by_email'] ?? 'no email')) ?></div></td>
              <td class="rq-why"><?= e((string) ($u['confirm_state'] ?? '')) ?></td>
              <td>
                <?php if ($fabo_forms_may): ?>
                <form method="post" class="sb-inline">
                  <?= fabo_csrf_field() ?>
                  <input type="hidden" name="action" value="resend">
                  <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                  <button class="btn btn-sm btn-primary">Send again</button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <!-- ================================================= the queue itself -->
  <div class="card">
    <div class="rq-head">
      <div>
        <h2>The queue <span class="ro"><?= count($queue) ?> waiting<?=
          $late ? ' · ' . $late . ' past its date' : '' ?></span></h2>
        <p class="rq-lead">Each new requisition is fitted in after the work already
          ahead of it. A date once given is kept &mdash; it moves only when you push it
          back, or re-plan the whole queue, and then the person who asked is told.</p>
      </div>
      <?php if ($fabo_forms_may): ?>
      <form method="post" class="sb-inline">
        <?= fabo_csrf_field() ?>
        <input type="hidden" name="action" value="requeue">
        <button class="btn btn-sm"
          onclick="return confirm('Re-plan the whole queue from today? Dates already promised may move, and everyone whose date moves is emailed the new one.');"
          title="Work every date out again from today, and email everyone whose date moves">Re-plan the queue</button>
      </form>
      <?php endif; ?>
    </div>

    <?php if (!$queue): ?>
      <div class="empty">Nothing is waiting. Requisitions submitted through the
        link below appear here, in the order they arrive.</div>
    <?php else: ?>
      <div class="scrollx">
        <table class="data rq-queue">
          <thead><tr>
            <th style="width:4%">#</th>
            <th style="width:12%">Requisition</th>
            <th style="width:16%">What &amp; for whom</th>
            <th style="width:14%">Raised by</th>
            <th style="width:9%">Needed by</th>
            <th style="width:9%">Starts</th>
            <th style="width:11%">Delivery</th>
            <th style="width:9%">Standing</th>
            <th style="width:16%">Move the date</th>
          </tr></thead>
          <tbody>
          <?php foreach ($queue as $i => $r):
              $due   = (string) ($r['promised_due'] ?? '');
              $over  = $due !== '' && $due < $today;
              $tight = !$over && $due !== '' && !empty($r['required_by'])
                       && $due > $r['required_by'];
              $tone  = $over ? 'rag-red' : ($tight ? 'rag-amber' : 'rag-green');
              $word  = $over ? 'past its date' : ($tight ? 'later than asked for' : 'on track');
          ?>
            <tr class="<?= $over || $tight ? 'rq-slipped' : '' ?>">
              <td class="num"><?= (int) ($r['queue_position'] ?? $i + 1) ?></td>
              <td>
                <strong><?= e((string) ($r['req_id'] ?? '')) ?></strong>
                <div class="meta"><?= e((string) ($r['req_type'] ?? 'no type')) ?>
                  <?php if ((int) ($r['extended_count'] ?? 0) > 0): ?>
                    &middot; <span class="rq-ext">extended
                      <?= (int) $r['extended_count'] ?>&times;</span>
                  <?php endif; ?>
                </div>
              </td>
              <td>
                <?= e((string) ($r['event_name'] ?? '')) ?>
                <div class="meta"><?= e(trim((string) ($r['qty'] ?? '') . ' '
                      . (string) ($r['required_for'] ?? ''))) ?></div>
              </td>
              <td>
                <?= e((string) ($r['raised_by'] ?? '')) ?>
                <div class="meta"><?= e((string) ($r['raised_by_email'] ?? 'no email')) ?></div>
              </td>
              <td><?= e(fabo_req_day((string) ($r['required_by'] ?? '')) ?: '—') ?></td>
              <td><?= e(fabo_req_day((string) ($r['promised_start'] ?? '')) ?: '—') ?></td>
              <td class="<?= e($tone) ?>"><?= e(fabo_req_day($due) ?: '—') ?></td>
              <td><span class="pill tone-<?= $over ? 'risk' : ($tight ? 'warn' : 'good') ?>">
                <i class="dot"></i><?= e($word) ?></span></td>
              <td>
                <?php if ($fabo_forms_may): ?>
                <form method="post" class="rq-extend">
                  <?= fabo_csrf_field() ?>
                  <input type="hidden" name="action" value="extend">
                  <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                  <input type="date" name="new_due" required
                         min="<?= e($due !== '' ? date('Y-m-d', strtotime($due . ' +1 day')) : $today) ?>">
                  <input type="text" name="reason" maxlength="255" placeholder="why">
                  <button class="btn btn-sm">Extend</button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="hint">Extending a date moves everything behind it too, and
        everybody whose delivery day changed is emailed the new one. The
        Requisitions sheet turns the row red the moment a promised date passes
        with the work still open.</p>
    <?php endif; ?>
  </div>

  <!-- ============================================== recently closed out -->
  <?php if ($done): ?>
    <div class="card">
      <h2>Closed out <span class="ro">last <?= count($done) ?></span></h2>
      <div class="scrollx">
        <table class="data">
          <thead><tr><th>Requisition</th><th>Type</th><th>For</th>
            <th>Promised</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($done as $r): ?>
            <tr>
              <td><?= e((string) ($r['req_id'] ?? '')) ?></td>
              <td><?= e((string) ($r['req_type'] ?? '')) ?></td>
              <td><?= e((string) ($r['event_name'] ?? '')) ?></td>
              <td><?= e(fabo_req_day((string) ($r['promised_due'] ?? '')) ?: '—') ?></td>
              <td><span class="pill tone-muted"><i class="dot"></i><?=
                e((string) ($r['status'] ?? '')) ?></span></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <!-- =================================================== the form links -->
  <div class="card">
    <h2>The form link <span class="ro"><?= count($forms) ?></span></h2>
    <?php if (!$forms): ?>
      <div class="empty">No link yet. Create one below and share it with whoever
        raises requisitions.</div>
    <?php else: ?>
      <div class="scrollx">
        <table class="data">
          <thead><tr>
            <th style="width:20%">Label</th><th style="width:36%">Link</th>
            <th style="width:12%">State</th><th class="num" style="width:10%">Submitted</th>
            <th style="width:22%">Actions</th>
          </tr></thead>
          <tbody>
          <?php foreach ($forms as $f):
              $state = fabo_form_state($f);
              // form.php reads the token as 'k'; anything else is a 404 to the
              // person holding the link.
              $url   = rtrim($baseUrl, '/') . '/form.php?k=' . $f['token'];
          ?>
            <tr>
              <td><?= e((string) $f['label']) ?>
                <div class="meta">made <?= e(date('d M Y', strtotime((string) $f['created_at']))) ?></div>
              </td>
              <td><input class="rq-url" value="<?= e($url) ?>" readonly
                         onclick="this.select()"></td>
              <td>
                <span class="pill tone-<?= $state === 'ok' ? 'good' : 'muted' ?>"><i class="dot"></i>
                  <?= $state === 'ok' ? 'open' : ($state === 'off' ? 'closed' : 'expired') ?></span>
                <?php if (!empty($f['expires_at'])): ?>
                  <div class="meta">until <?= e(date('d M Y', strtotime((string) $f['expires_at']))) ?></div>
                <?php endif; ?>
              </td>
              <td class="num"><?= (int) $f['submit_count'] ?></td>
              <td class="sb-acts">
                <a class="btn btn-sm" href="<?= e($url) ?>" target="_blank" rel="noopener">Open</a>
                <?php foreach (($fabo_forms_may ? [['toggle', $state === 'ok' ? 'Close' : 'Open up'],
                                ['rotate', 'New link'],
                                ['delete', 'Delete']] : []) as [$a, $lab]): ?>
                  <form method="post" class="sb-inline"<?= $a === 'delete'
                        ? ' onsubmit="return confirm(\'Delete this link? Requisitions already collected stay on the sheet.\');"' : '' ?>>
                    <?= fabo_csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                    <button class="btn btn-sm<?= $a === 'delete' ? ' danger' : '' ?>"
                            name="action" value="<?= e($a) ?>"><?= e($lab) ?></button>
                  </form>
                <?php endforeach; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <?php if ($fabo_forms_may): ?>
    <form method="post" class="sb-add">
      <?= fabo_csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <div class="sb-row">
        <div class="sb-field grow"><label>New requisition form</label>
          <input name="label" required maxlength="120"
                 placeholder="e.g. Design Requisition — Ananthapuramu"></div>
        <div class="sb-field"><label>Open for</label>
          <select name="duration">
            <option value="never">As long as it is open</option>
            <option value="7d">7 days</option>
            <option value="30d">30 days</option>
            <option value="90d">90 days</option>
          </select></div>
        <div class="sb-field"><label>&nbsp;</label>
          <button class="btn btn-primary">Create the link</button></div>
      </div>
      <p class="sb-note">The form asks for everything on the requisition sheet:
        who is asking, what they need, the event it is for, the content that goes
        on it, the registration link, when it is needed, and any reference files.
        No login &mdash; every submission is audited under the form's label.</p>
    </form>
    <?php endif; ?>
  </div>
</div>
<?php
if (!$fabo_forms_embed) {
    fabo_foot();
}

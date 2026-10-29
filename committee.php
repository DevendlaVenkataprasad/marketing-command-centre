<?php
/**
 * The committee desk: a committee member's dashboard.
 *
 * The events sent to the committees this person sits on: a strip of counts
 * (each one filters the list), then ONE list of the events -- opened on
 * "Awaiting you" when anything is -- whose button opens the event in a pop-up
 * (fields, attachments, every member's decision -- fabo_cm_details_modal() in
 * lib/cmblock.php). Where they are still to decide they approve or reject
 * there; a rejection always carries remarks.
 *
 * A committee member (the Committee member tick) lands here, and otherwise
 * opens only the sheets assigned to them, their notifications and profile --
 * see fabo_head().
 * See lib/committees.php.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/committees.php';
require_once __DIR__ . '/lib/cmblock.php';      // fabo_cm_details_modal(): the View details / Review & decide pop-up
fabo_boot();

header('X-Robots-Tag: noindex, nofollow');

$viewer = fabo_portal_viewer((string) ($_GET['k'] ?? ''));
if (!$viewer) {
    header('Location: login.php?next=' . rawurlencode('committee.php'));
    exit;
}
if (!fabo_cm_only($viewer) && !fabo_cm_is_member($viewer)) {
    header('Location: ' . fabo_link($viewer, 'c.php'));
    exit;
}

/* Upcoming events waiting to go to the organisation's automatic committee go
   now, before the list is read, so the dashboard never lags the sheet. */
try {
    fabo_cm_autoassign_scope(fabo_cm_scope($viewer));
} catch (Throwable $e) {
    error_log('FABO committee auto-assign on dashboard: ' . $e->getMessage());
}

$rows = fabo_cm_programs($viewer);
$m    = fabo_cm_metrics($rows);
$open = (int) ($_GET['open'] ?? 0);
$api  = fabo_link($viewer, 'api/cmdesk.php');

/* The row's filter groups, as the tabs and the counts above the list name them. */
$today = date('Y-m-d');
$soon  = date('Y-m-d', strtotime('+30 days'));
$group = static function (array $r) use ($today, $soon): string {
    $g = [$r['_approval']];
    if ($r['_mine'] === 'pending' && $r['_cm'] && $r['_cm']['state'] === 'pending' && $r['_approval'] === 'pending') {
        $g[] = 'mine';
    }
    $start = (string) ($r['start_date'] ?? '');
    if ($start !== '' && $start >= $today && $start <= $soon) {
        $g[] = 'soon';
    }
    return implode(' ', $g);
};
$approvalWords = ['approved' => 'Approved', 'rejected' => 'Rejected', 'pending' => 'Pending'];

/* Events still waiting on this person come first, soonest first within each
   part; and when each was sent to the committee. */
$isMine = static fn(array $r): bool => str_contains($group($r), 'mine');
usort($rows, static function ($a, $b) use ($isMine) {
    return [$isMine($a) ? 0 : 1, (string) ($a['start_date'] ?: '9999-12-31'), (int) $a['id']]
       <=> [$isMine($b) ? 0 : 1, (string) ($b['start_date'] ?: '9999-12-31'), (int) $b['id']];
});
$sentAt = [];
$mineIds = array_map(static fn($r) => (int) $r['id'], array_filter($rows, $isMine));
if ($mineIds) {
    foreach (fabo_all('SELECT row_id, MAX(created_at) at FROM approval_log
                        WHERE sheet_key = "events" AND state = "pending" AND row_id IN (' . implode(',', $mineIds) . ')
                        GROUP BY row_id') as $l) {
        $sentAt[(int) $l['row_id']] = (string) $l['at'];
    }
}
$startTab = $m['awaiting_me'] ? 'mine' : 'all';

fabo_head($viewer, 'Committee Dashboard', 'committee');
?>
<div class="wrap-page cmdesk">
  <div class="pagehead cmx-head">
    <div>
      <h1>COMMITTEE DASHBOARD</h1>
      <div class="sub"><?= $m['committees'] ? e(implode(', ', array_keys($m['committees']))) : 'Your committees' ?>
        &middot; as of <?= e(date('d M Y')) ?></div>
    </div>
    <?php if ($m['awaiting_me']): ?>
      <div class="cmx-due"><span class="cmx-dot"></span><?= $m['awaiting_me'] ?> event<?= $m['awaiting_me'] === 1 ? '' : 's' ?> waiting for your decision</div>
    <?php elseif ($rows): ?>
      <div class="cmx-due ok">✓ You are up to date</div>
    <?php endif; ?>
  </div>

  <?php /* The counts, each a shortcut to that part of the list. */ ?>
  <div class="cmx-stats" id="cmxStats">
    <button type="button" class="cmx-stat due" data-f="mine">
      <span class="k">Awaiting your decision</span><span class="v"><?= $m['awaiting_me'] ?></span>
      <span class="s">Open each one to approve or reject</span></button>
    <button type="button" class="cmx-stat" data-f="all">
      <span class="k">Assigned events</span><span class="v"><?= $m['total'] ?></span>
      <span class="s"><?= $m['pending'] ?> pending committee approval</span></button>
    <button type="button" class="cmx-stat good" data-f="approved">
      <span class="k">Approved</span><span class="v"><?= $m['approved'] ?></span>
      <span class="s">You approved <?= $m['i_approved'] ?></span></button>
    <button type="button" class="cmx-stat bad" data-f="rejected">
      <span class="k">Rejected</span><span class="v"><?= $m['rejected'] ?></span>
      <span class="s">You rejected <?= $m['i_rejected'] ?></span></button>
    <button type="button" class="cmx-stat" data-f="soon">
      <span class="k">Starting in 30 days</span><span class="v"><?= $m['upcoming'] ?></span>
      <span class="s">Upcoming on the calendar</span></button>
  </div>

  <?php if (count($m['committees']) > 1): ?>
  <div class="block">
    <h2>By committee</h2>
    <div class="scrollx"><table class="data">
      <thead><tr><th>Committee</th><th>Decides by</th><th class="num">Sent to it</th>
        <th class="num">Approved</th><th class="num">Rejected</th><th class="num">Pending</th></tr></thead>
      <tbody>
      <?php foreach ($m['committees'] as $name => $c): ?>
        <tr>
          <td><strong><?= e($name) ?></strong></td>
          <td><?= e($c['rule']) ?></td>
          <td class="num"><?= $c['total'] ?></td>
          <td class="num tone-good"><?= $c['approved'] ?></td>
          <td class="num<?= $c['rejected'] ? ' tone-risk' : '' ?>"><?= $c['rejected'] ?></td>
          <td class="num<?= $c['pending'] ? ' tone-warn' : '' ?>"><?= $c['pending'] ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <?php endif; ?>

  <div class="cmx-panel">
    <div class="cmx-bar">
      <div class="cmx-tabs" id="cmdChips" role="tablist">
        <?php foreach (['mine' => ['Awaiting you', $m['awaiting_me']], 'all' => ['All', $m['total']],
                        'pending' => ['Pending', $m['pending']], 'approved' => ['Approved', $m['approved']],
                        'rejected' => ['Rejected', $m['rejected']]] as $f => [$label, $n]): ?>
          <button type="button" role="tab" data-f="<?= $f ?>" class="<?= $f === $startTab ? 'on' : '' ?>"><?= $label ?> <b><?= $n ?></b></button>
        <?php endforeach; ?>
        <button type="button" role="tab" data-f="soon" hidden>Starting in 30 days</button>
      </div>
      <input type="search" id="cmdFind" placeholder="Search by name, ID, type, place…" autocomplete="off" aria-label="Search events">
    </div>

    <?php if (!$rows): ?>
      <div class="cmx-empty"><strong>No events yet.</strong>
        You will be notified as soon as an event is sent to your committee.</div>
    <?php else: ?>
    <ul class="cmx-list" id="cmdTable">
      <?php foreach ($rows as $r):
          $s    = $r['_cm'];
          $mine = $isMine($r);
          $d    = $r['start_date'] ? strtotime((string) $r['start_date']) : null;
          $endd = !empty($r['end_date']) && $r['end_date'] !== $r['start_date'] ? strtotime((string) $r['end_date']) : null;
          $at   = $sentAt[(int) $r['id']] ?? '';
          $where = trim((string) ($r['location'] ?? '')) ?: trim((string) ($r['city'] ?? ''));
          $you  = $r['_mine'] === null ? ($s ? $s['label'] : '')
                : ($r['_mine'] === 'approved' ? 'You approved'
                : ($r['_mine'] === 'rejected' ? 'You rejected'
                : ($s && $s['state'] === 'pending' ? '' : 'You did not vote'))); ?>
        <li class="cmx-row<?= $mine ? ' mine' : '' ?>" data-id="<?= (int) $r['id'] ?>" data-g="<?= e($group($r)) ?>"
            data-find="<?= e(mb_strtolower(implode(' ', [$r['event_id'], $r['event_name'], $r['event_type'] ?? '',
                                                          $r['hub_spoke'] ?? '', $r['city'] ?? '', $r['location'] ?? '',
                                                          $r['status'] ?? '', $s['committee']['name'] ?? '']))) ?>">
          <div class="cmx-date"<?= $d ? '' : ' aria-label="No date"' ?>>
            <?php if ($d): ?>
              <span class="dd"><?= date('d', $d) ?></span><span class="mm"><?= date('M Y', $d) ?></span>
            <?php else: ?><span class="mm">No date</span><?php endif; ?>
          </div>
          <div class="cmx-main">
            <strong class="cmx-title" title="<?= e((string) ($r['event_name'] ?: '')) ?>"><?= e((string) ($r['event_name'] ?: $r['event_id'] ?: '—')) ?></strong>
            <span class="cmx-meta"><?= e(implode('  ·  ', array_filter([
                (string) $r['event_id'],
                (string) ($r['event_type'] ?? ''),
                $where,
                $endd ? date('d M', $d) . ' – ' . date('d M Y', $endd) : '',
                (int) $r['_files'] ? (int) $r['_files'] . ' file' . ((int) $r['_files'] === 1 ? '' : 's') : '',
            ]))) ?></span>
            <?php if ($mine && $at !== ''): ?>
              <span class="cmx-note">Sent to <?= e($s['committee']['name'] ?? 'your committee') ?> on <?= e(date('d M Y, H:i', strtotime($at))) ?></span>
            <?php elseif ($s): ?>
              <span class="cmx-note"><?= e($s['committee']['name']) ?></span>
            <?php endif; ?>
          </div>
          <div class="cmx-state">
            <?php if ($mine): ?>
              <span class="cmx-pill due">Awaiting you</span>
            <?php else: ?>
              <span class="cmx-pill <?= e($r['_approval']) ?>"><?= $approvalWords[$r['_approval']] ?></span>
            <?php endif; ?>
            <?php if ($you !== ''): ?><small><?= e($you) ?></small><?php endif; ?>
          </div>
          <div class="cmx-act">
            <button type="button" class="btn btn-sm<?= $mine ? ' btn-primary' : '' ?>" data-cmd-open="<?= (int) $r['id'] ?>"><?= $mine ? 'Review &amp; decide' : 'View details' ?></button>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
    <div class="cmx-empty" id="cmdNone" hidden>No events match.</div>
    <?php endif; ?>
  </div>
</div>

<?php fabo_cm_details_modal($viewer, $open); ?>
<script>
(function () {
  var $ = function (id) { return document.getElementById(id); };
  var tabs = $('cmdChips'), stats = $('cmxStats');

  /* ---- the list: tabs, the counts above it, and search */
  var filter = <?= json_encode($startTab) ?>;
  function applyFilter() {
    var q = ($('cmdFind').value || '').trim().toLowerCase(), shown = 0;
    document.querySelectorAll('#cmdTable > li').forEach(function (li) {
      var g = li.getAttribute('data-g').split(' ');
      var ok = (filter === 'all' || g.indexOf(filter) >= 0)
            && (q === '' || li.getAttribute('data-find').indexOf(q) >= 0);
      li.hidden = !ok;
      shown += ok ? 1 : 0;
    });
    if ($('cmdNone')) { $('cmdNone').hidden = shown > 0; }
    tabs.querySelectorAll('button').forEach(function (x) {
      var on = x.getAttribute('data-f') === filter;
      x.classList.toggle('on', on);
      x.setAttribute('aria-selected', on ? 'true' : 'false');
      if (x.getAttribute('data-f') === 'soon') { x.hidden = !on; }
    });
    stats.querySelectorAll('.cmx-stat').forEach(function (x) { x.classList.toggle('on', x.getAttribute('data-f') === filter); });
  }
  function pick(ev) {
    var b = ev.target.closest('[data-f]');
    if (!b) { return; }
    filter = b.getAttribute('data-f');
    applyFilter();
    if (b.classList.contains('cmx-stat') && tabs.scrollIntoView) { tabs.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
  }
  tabs.addEventListener('click', pick);
  stats.addEventListener('click', pick);
  $('cmdFind').addEventListener('input', applyFilter);
  applyFilter();
})();
</script>
<?php
fabo_foot();

<?php
/**
 * The sheet's own briefing, under its dashboard.
 *
 * The picture above it counts and charts what is on the sheet. That answers
 * "how much", and stops there. The three questions left over are the ones
 * somebody scrolling to the bottom of a sheet is usually carrying:
 *
 *   what on THIS sheet needs doing      — named, not counted
 *   has anybody touched it lately       — a sheet can look healthy and be dead
 *   who is carrying it                  — and is that one person
 *
 * Rows come from the caller, already scoped by fabo_rows(), so this says the
 * same thing at every level: ours, one client's, or a team member's own.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sheets.php';
require_once __DIR__ . '/rules.php';

/** Which column on this sheet names a person. */
function fabo_brief_owner_field(array $sheet): string
{
    $have = array_flip(array_column($sheet['columns'], 1));
    foreach (['owner', 'designer', 'coordinator', 'spoc_name', 'raised_by'] as $f) {
        if (isset($have[$f])) {
            return $f;
        }
    }
    return '';
}

/** Which column reads as the row's name. */
function fabo_brief_title_field(array $sheet): string
{
    foreach ($sheet['columns'] as $c) {
        if (in_array($c[2], ['text', 'long'], true)) {
            return $c[1];
        }
    }
    return (string) ($sheet['id_field'] ?? '');
}

/**
 * Everything the briefing needs, worked out in one pass over the rows.
 *
 * One pass because this runs under every sheet on every load, and a sheet
 * with two thousand rows should not be walked five times to answer five
 * questions about it.
 */
function fabo_sheet_brief(string $key, array $sheet, array $rows): array
{
    $today  = fabo_today();
    $soon   = date('Y-m-d', strtotime($today . ' +7 days'));
    $week   = date('Y-m-d H:i:s', strtotime('-7 days'));
    $month  = date('Y-m-d H:i:s', strtotime('-30 days'));
    $driver = (string) ($sheet['date_driver'] ?? '');
    $ownerF = fabo_brief_owner_field($sheet);
    $titleF = fabo_brief_title_field($sheet);

    $need    = [];
    $owners  = [];
    $touched = ['week' => 0, 'month' => 0];
    $added   = ['week' => 0, 'month' => 0];
    $last    = null;
    $noOwner = 0;
    $waiting = 0;

    foreach ($rows as $r) {
        $closed = fabo_is_closed($r['status'] ?? null);

        /* What wants doing. */
        $d   = $driver !== '' ? (string) ($r[$driver] ?? '') : '';
        $why = '';
        if (!$closed && $d !== '' && $d < $today)                     { $why = 'late'; }
        elseif (!$closed && $d !== '' && $d >= $today && $d <= $soon)  { $why = 'due'; }
        elseif (($r['approval_state'] ?? '') === 'Awaiting')           { $why = 'waiting'; }

        if (($r['approval_state'] ?? '') === 'Awaiting') {
            $waiting++;
        }
        if ($why !== '') {
            $name = $titleF !== '' ? trim((string) ($r[$titleF] ?? '')) : '';
            $need[] = [
                'id'    => (int) $r['id'],
                'name'  => $name !== '' ? $name : 'Untitled',
                'owner' => $ownerF !== '' ? trim((string) ($r[$ownerF] ?? '')) : '',
                'when'  => $d,
                'why'   => $why,
                'rank'  => ['late' => 0, 'due' => 1, 'waiting' => 2][$why],
                'days'  => $d !== '' ? (int) floor((strtotime($d) - strtotime($today)) / 86400) : null,
            ];
        }

        /* Who is carrying it. Open rows only — a name against forty finished
           jobs is history, and the question here is who is busy now. */
        if (!$closed && $ownerF !== '') {
            $o = trim((string) ($r[$ownerF] ?? ''));
            if ($o === '') { $noOwner++; }
            else           { $owners[$o] = ($owners[$o] ?? 0) + 1; }
        } elseif (!$closed && $ownerF === '') {
            $noOwner++;
        }

        /* Whether anybody has been near it. */
        $u = (string) ($r['updated_at'] ?? '');
        $c = (string) ($r['created_at'] ?? '');
        if ($u !== '') {
            if ($u >= $week)  { $touched['week']++; }
            if ($u >= $month) { $touched['month']++; }
            if ($last === null || $u > $last) { $last = $u; }
        }
        if ($c !== '') {
            if ($c >= $week)  { $added['week']++; }
            if ($c >= $month) { $added['month']++; }
        }
    }

    usort($need, static function ($a, $b) {
        if ($a['rank'] !== $b['rank']) {
            return $a['rank'] <=> $b['rank'];
        }
        /* Late reads most-recent-first, due soonest-first. The recent slip
           is the one still worth chasing; a date long gone is history. */
        return $a['rank'] === 0
            ? strcmp((string) $b['when'], (string) $a['when'])
            : strcmp((string) $a['when'], (string) $b['when']);
    });
    arsort($owners);

    return [
        'need'     => array_slice($need, 0, 8),
        'need_all' => count($need),
        'late'     => count(array_filter($need, static fn($n) => $n['why'] === 'late')),
        'due'      => count(array_filter($need, static fn($n) => $n['why'] === 'due')),
        'waiting'  => $waiting,
        'owners'   => array_slice($owners, 0, 6, true),
        'owner_n'  => count($owners),
        'no_owner' => $noOwner,
        'touched'  => $touched,
        'added'    => $added,
        'last'     => $last,
        'quiet'    => $last === null ? null : (int) floor((time() - strtotime($last)) / 86400),
        'rows'     => count($rows),
        'has_owner_column' => $ownerF !== '',
    ];
}

/** The briefing, drawn under the sheet's dashboard. */
function fabo_sheet_brief_block(string $key, array $sheet, array $rows, array $viewer): void
{
    if (!$rows) {
        return;                             // an empty sheet needs no briefing
    }
    $b = fabo_sheet_brief($key, $sheet, $rows);
    ?>
  <div class="block sbrief">
    <h2>What this sheet is telling you</h2>

    <div class="sbrief-cols">

      <?php /* ----------------------------------------------- what wants doing */ ?>
      <div class="sbrief-one">
        <h3>Needs doing</h3>
        <?php if (!$b['need']): ?>
          <p class="hint">Nothing here is late, due this week, or waiting on
            sign-off.</p>
        <?php else: ?>
          <p class="hint">
            <?= (int) $b['late'] ?> late &middot; <?= (int) $b['due'] ?> due this week
            <?php if ($b['waiting']): ?>&middot; <?= (int) $b['waiting'] ?> awaiting sign-off<?php endif; ?>
            <?php if ($b['need_all'] > count($b['need'])): ?>
              &mdash; the <?= count($b['need']) ?> most pressing
            <?php endif; ?>
          </p>
          <ul class="sbrief-list">
            <?php foreach ($b['need'] as $n): ?>
              <li>
                <span class="pill tone-<?= e(['late' => 'risk', 'due' => 'warn',
                                              'waiting' => 'info'][$n['why']]) ?>"><i class="dot"></i><?=
                  e(['late' => 'late', 'due' => 'due', 'waiting' => 'sign-off'][$n['why']]) ?></span>
                <a href="#row-<?= (int) $n['id'] ?>"><?= e(mb_strimwidth($n['name'], 0, 46, '…')) ?></a>
                <span class="sbrief-meta"><?php
                  if ($n['when'] !== '') {
                      echo e(date('d M', strtotime($n['when'])));
                      if ($n['days'] !== null && $n['days'] < 0) {
                          echo ' (' . abs($n['days']) . 'd ago)';
                      }
                  }
                  if ($n['owner'] !== '') { echo ' &middot; ' . e($n['owner']); }
                ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>

      <?php /* ------------------------------------------- has anybody been near it */ ?>
      <div class="sbrief-one">
        <h3>Movement</h3>
        <?php if ($b['quiet'] === null): ?>
          <p class="hint">Nothing here records when it was last changed.</p>
        <?php else: ?>
          <ul class="sbrief-facts">
            <li><strong><?= (int) $b['added']['week'] ?></strong> added and
                <strong><?= (int) $b['touched']['week'] ?></strong> changed
                <span class="sbrief-meta">in the last 7 days</span></li>
            <li><strong><?= (int) $b['added']['month'] ?></strong> added and
                <strong><?= (int) $b['touched']['month'] ?></strong> changed
                <span class="sbrief-meta">in the last 30 days</span></li>
            <li><strong><?= $b['quiet'] === 0 ? 'today'
                          : ($b['quiet'] === 1 ? 'yesterday' : (int) $b['quiet'] . ' days ago') ?></strong>
                <span class="sbrief-meta">last touched</span></li>
          </ul>
          <?php if ($b['quiet'] > 30): ?>
            <p class="hint sbrief-warn">Nobody has touched this sheet in over a month.
              The figures above are as old as that.</p>
          <?php elseif ($b['added']['month'] === 0 && $b['touched']['month'] === 0): ?>
            <p class="hint sbrief-warn">Nothing has moved here in 30 days.</p>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <?php /* ------------------------------------------------ who is carrying it */ ?>
      <div class="sbrief-one">
        <h3>Who is carrying it</h3>
        <?php if (!$b['has_owner_column']): ?>
          <p class="hint">This sheet has no owner column, so nothing on it can be
            assigned. Work here counts towards whoever entered it.</p>
        <?php elseif (!$b['owners']): ?>
          <p class="hint">Nothing open is assigned to anybody.</p>
        <?php else: ?>
          <ul class="sbrief-list">
            <?php $top = max($b['owners']); ?>
            <?php foreach ($b['owners'] as $who => $n): ?>
              <li>
                <span class="sbrief-who"><?= e($who) ?></span>
                <span class="sbrief-bar"><i style="width:<?= (int) round($n / $top * 100) ?>%"></i></span>
                <strong><?= (int) $n ?></strong>
              </li>
            <?php endforeach; ?>
          </ul>
          <?php if ($b['no_owner'] > 0): ?>
            <p class="hint"><strong><?= (int) $b['no_owner'] ?></strong> open
              row<?= $b['no_owner'] === 1 ? '' : 's' ?> with nobody's name on
              <?= $b['no_owner'] === 1 ? 'it' : 'them' ?>.</p>
          <?php endif; ?>
          <?php if ($b['owner_n'] === 1 && $b['no_owner'] === 0): ?>
            <p class="hint sbrief-warn">Everything open here sits with one person.</p>
          <?php endif; ?>
        <?php endif; ?>
      </div>

    </div>
  </div>
<?php
}

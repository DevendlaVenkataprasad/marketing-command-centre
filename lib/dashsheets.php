<?php
/**
 * What is actually on the sheets, and what needs doing about it.
 *
 * The dashboards counted activities and added up budgets. True, and not much
 * use: a number like "194 activities" says nothing about whether the Content
 * sheet has been touched this month, or which four things are late. Somebody
 * opening a dashboard is asking one of two questions —
 *
 *   where does the work stand?   and   what needs me today?
 *
 * — and neither was answered. This answers both, from the sheets themselves
 * rather than from the summary log, so it says Content and Design and Events
 * instead of Function A and Function B.
 *
 * Everything here goes through fabo_rows() and fabo_permission(), so it shows
 * exactly what the person looking is entitled to see: ours, one client's, or
 * a team member's own. The same code serves every level.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sheets.php';
require_once __DIR__ . '/grid.php';
require_once __DIR__ . '/rules.php';

/**
 * One sheet's rows, read once per request however many blocks ask for them.
 *
 * fabo_rows() goes to the database every time it is called, and the dashboard
 * has three blocks that each want all eleven sheets: the per-sheet table, the
 * attention list, and the charts. Three passes for one answer. This makes it
 * one.
 *
 * Scoped exactly as fabo_rows() scopes it, so this changes how often the
 * database is asked and nothing whatever about who may see what. Keyed by
 * viewer as well as sheet, because a single request can render more than one
 * viewer's data — the admin looking at one client through the picker.
 */
function fabo_dash_read(string $key, array $viewer, int $clientId = 0): array
{
    static $cache = [];

    $who = !empty($viewer['is_admin'])
        ? 'admin'
        : 'c' . (int) ($viewer['client']['id'] ?? 0);
    $ck = $key . '|' . $who . '|' . $clientId;

    if (!isset($cache[$ck])) {
        try {
            $cache[$ck] = fabo_rows($key, $viewer, ['client' => $clientId]);
        } catch (Throwable $e) {
            $cache[$ck] = [];        // a sheet mid-migration is empty, not a crash
        }
    }
    return $cache[$ck];
}

/** Sheets worth summarising, in the order the nav shows them. */
function fabo_dash_sheet_keys(): array
{
    $skip = ['dashboard', 'lists', 'master'];
    return array_values(array_filter(fabo_sheet_order(),
        static fn($k) => !in_array($k, $skip, true) && fabo_sheet($k) !== null));
}

/**
 * How each sheet stands: how much is on it, how much is open, how much is
 * late, and when it was last touched.
 *
 * The last-touched date is the one people actually react to. A sheet with
 * forty rows and nothing added for six weeks is a different problem from a
 * sheet with four rows added yesterday, and a count alone hides that.
 */
function fabo_dash_sheet_health(array $viewer, int $clientId = 0): array
{
    $today = fabo_today();
    $soon  = date('Y-m-d', strtotime($today . ' +7 days'));
    $out   = [];

    foreach (fabo_dash_sheet_keys() as $key) {
        if (fabo_permission($viewer, $key) === 'none') {
            continue;                       // not theirs to see
        }
        $sheet = fabo_sheet($key);
        if (!$sheet || empty($sheet['table'])) {
            continue;
        }
        /* Shared with the attention list and the charts — all three walk the
           same eleven sheets, and fabo_rows() goes to the database every time
           it is asked. One pass now serves the lot. */
        $rows = fabo_dash_read($key, $viewer, $clientId);

        $open = 0; $done = 0; $late = 0; $due = 0; $waiting = 0;
        $last = null;
        $driver = (string) ($sheet['date_driver'] ?? '');

        foreach ($rows as $r) {
            $closed = fabo_is_closed($r['status'] ?? null);
            if ($closed) { $done++; } else { $open++; }

            if (!$closed && $driver !== '') {
                $d = (string) ($r[$driver] ?? '');
                if ($d !== '' && $d < $today)                    { $late++; }
                elseif ($d !== '' && $d >= $today && $d <= $soon) { $due++; }
            }
            if (($r['approval_state'] ?? '') === 'Awaiting') {
                $waiting++;
            }
            $t = (string) ($r['updated_at'] ?? $r['created_at'] ?? '');
            if ($t !== '' && ($last === null || $t > $last)) {
                $last = $t;
            }
        }

        $out[$key] = [
            'key'     => $key,
            'label'   => (string) ($sheet['label'] ?? $key),
            'rows'    => count($rows),
            'open'    => $open,
            'done'    => $done,
            'late'    => $late,
            'due'     => $due,
            'waiting' => $waiting,
            'pct'     => count($rows) ? (int) round($done / count($rows) * 100) : 0,
            'last'    => $last,
            'quiet'   => $last === null ? null
                       : (int) floor((time() - strtotime($last)) / 86400),
        ];
    }
    return $out;
}

/**
 * The rows that want doing something about, soonest first.
 *
 * Deliberately a list of actual rows rather than another count. "Four
 * overdue" tells somebody they have a problem; naming the four tells them
 * what it is, and the link takes them to it.
 *
 * Late first, then due this week, then waiting on an approval — which is the
 * order somebody would work through them.
 *
 * Within the late ones, most recently late first. Oldest-first sounds right
 * and reads terribly: a fixed date nine months gone fills the list with
 * things nobody can act on, and buries last week's slip underneath them.
 *
 * And no sheet may take the whole list, however far behind it is. This is
 * the one page meant to show the portal rather than a corner of it.
 */
function fabo_dash_attention(array $viewer, int $clientId = 0, int $max = 12): array
{
    $today = fabo_today();
    $soon  = date('Y-m-d', strtotime($today . ' +7 days'));
    $items = [];

    foreach (fabo_dash_sheet_keys() as $key) {
        if (fabo_permission($viewer, $key) === 'none') {
            continue;
        }
        $sheet = fabo_sheet($key);
        if (!$sheet || empty($sheet['table'])) {
            continue;
        }
        $driver = (string) ($sheet['date_driver'] ?? '');
        $rows   = fabo_dash_read($key, $viewer, $clientId);

        /* What to call a row. The sheet's own first text column reads far
           better than an id: "Diwali campaign" against "DM-0042". */
        $title = '';
        foreach ($sheet['columns'] as $c) {
            if (in_array($c[2], ['text', 'long'], true)) { $title = $c[1]; break; }
        }

        foreach ($rows as $r) {
            $closed = fabo_is_closed($r['status'] ?? null);
            $d      = $driver !== '' ? (string) ($r[$driver] ?? '') : '';
            $why    = '';
            $rank   = 9;

            if (!$closed && $d !== '' && $d < $today) {
                $why  = 'late';
                $rank = 0;
            } elseif (!$closed && $d !== '' && $d >= $today && $d <= $soon) {
                $why  = 'due';
                $rank = 1;
            } elseif (($r['approval_state'] ?? '') === 'Awaiting') {
                $why  = 'waiting';
                $rank = 2;
            }
            if ($why === '') {
                continue;
            }
            $name = $title !== '' ? trim((string) ($r[$title] ?? '')) : '';
            if ($name === '' && !empty($sheet['id_field'])) {
                $name = (string) ($r[$sheet['id_field']] ?? '');
            }
            $items[] = [
                'sheet' => (string) ($sheet['label'] ?? $key),
                'key'   => $key,
                'id'    => (int) $r['id'],
                'name'  => $name !== '' ? $name : 'Untitled',
                'owner' => trim((string) ($r['owner'] ?? $r['designer'] ?? $r['coordinator'] ?? '')),
                'when'  => $d,
                'why'   => $why,
                'rank'  => $rank,
                'days'  => $d !== '' ? (int) floor((strtotime($d) - strtotime($today)) / 86400) : null,
            ];
        }
    }

    usort($items, static function ($a, $b) {
        if ($a['rank'] !== $b['rank']) {
            return $a['rank'] <=> $b['rank'];
        }
        /* Late: nearest the present first. Due: soonest first. Opposite
           directions, because in one case the date is a failure already and
           in the other it is a deadline still standing. */
        $when = $a['rank'] === 0
            ? strcmp((string) $b['when'], (string) $a['when'])
            : strcmp((string) $a['when'], (string) $b['when']);
        return $when ?: strcmp($a['sheet'], $b['sheet']);
    });

    return fabo_dash_spread($items, $max);
}

/**
 * Take the top $max, letting no single sheet crowd the others out.
 *
 * Each sheet gets an equal share of the room, worked out from how many
 * sheets actually have something wanting doing. Whatever is left over after
 * that is filled from the rest in order, so the list is never short when
 * there is more to show — it just leads with breadth.
 */
function fabo_dash_spread(array $items, int $max): array
{
    if (count($items) <= $max) {
        return $items;
    }
    $sheets = count(array_unique(array_column($items, 'key')));
    $share  = max(2, (int) ceil($max / max(1, $sheets)));

    $take = [];
    $held = [];
    $seen = [];
    foreach ($items as $i => $it) {
        $k = $it['key'];
        if (($seen[$k] = ($seen[$k] ?? 0) + 1) <= $share) {
            $take[] = $it;
            if (count($take) >= $max) {
                return $take;
            }
        } else {
            $held[] = $it;
        }
    }
    foreach ($held as $it) {                     // room to spare: fill it
        if (count($take) >= $max) {
            break;
        }
        $take[] = $it;
    }
    return $take;
}

/**
 * One person's own work, broken down by sheet.
 *
 * The tiles on My Work say how much somebody is carrying; this says where it
 * is. "Eleven open" reads very differently once you can see that nine of the
 * eleven sit on one sheet.
 *
 * Built from the work items the page already holds, so this is a group-by and
 * not another pass over the database.
 */
function fabo_dash_my_sheets(array $items): array
{
    $out = [];
    foreach ($items as $it) {
        $label = (string) ($it['sheet'] ?? '');
        if ($label === '') {
            continue;
        }
        if (!isset($out[$label])) {
            $out[$label] = ['label' => $label, 'key' => (string) ($it['key'] ?? ''),
                            'rows' => 0, 'open' => 0, 'done' => 0, 'late' => 0, 'due' => 0];
        }
        $r = &$out[$label];
        $r['rows']++;
        if (!empty($it['closed']))   { $r['done']++; }
        else                         { $r['open']++; }
        if (!empty($it['overdue']))  { $r['late']++; }
        if (!empty($it['due_soon'])) { $r['due']++; }
        unset($r);
    }
    foreach ($out as &$r) {
        $r['pct'] = $r['rows'] ? (int) round($r['done'] / $r['rows'] * 100) : 0;
    }
    unset($r);

    /* Heaviest first, and within that the one most behind — which is the
       order somebody would decide what to open. */
    uasort($out, static fn($a, $b) => $b['open'] <=> $a['open'] ?: $b['late'] <=> $a['late']);
    return $out;
}

/** That rollup, drawn on My Work. */
function fabo_dash_my_block(array $viewer, array $items): void
{
    $by = fabo_dash_my_sheets($items);
    if (count($by) < 2) {
        return;        // one sheet: the table above already says everything
    }
    ?>
  <div class="block">
    <h2>Where your work sits</h2>
    <p class="sb-lead">Your rows by sheet, the busiest first. Useful when the
      totals above look heavy and you want to know which sheet they are on.</p>
    <div class="scrollx">
      <table class="data">
        <thead><tr><th>Sheet</th><th class="num">Yours</th><th class="num">Open</th>
          <th class="num">Done</th><th class="num">Late</th><th class="num">Due 7d</th>
          <th style="width:22%">Progress</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($by as $r): ?>
          <tr>
            <td><strong><?= e($r['label']) ?></strong></td>
            <td class="num"><?= (int) $r['rows'] ?></td>
            <td class="num"><?= (int) $r['open'] ?></td>
            <td class="num"><?= (int) $r['done'] ?></td>
            <td class="num <?= $r['late'] ? 'tone-risk' : '' ?>"><?= $r['late'] ?: '—' ?></td>
            <td class="num <?= $r['due'] ? 'tone-warn' : '' ?>"><?= $r['due'] ?: '—' ?></td>
            <td><div style="display:flex;align-items:center;gap:8px">
              <div class="bar <?= $r['late'] ? 'risk' : ($r['pct'] >= 70 ? '' : 'warn') ?>" style="flex:1">
                <i style="width:<?= (int) $r['pct'] ?>%"></i></div>
              <span style="font-size:12px;color:var(--ink-soft);min-width:34px"><?= (int) $r['pct'] ?>%</span>
            </div></td>
            <td><?php if ($r['key'] !== ''): ?>
              <a class="btn btn-sm" href="<?= e(fabo_link($viewer, 'c.php',
                    ['sheet' => $r['key']])) ?>">Open</a><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php
}

/** How many of those there are in total, which the list is only the top of. */
function fabo_dash_attention_counts(array $health): array
{
    $late = 0; $due = 0; $waiting = 0;
    foreach ($health as $h) {
        $late    += (int) $h['late'];
        $due     += (int) $h['due'];
        $waiting += (int) $h['waiting'];
    }
    return ['late' => $late, 'due' => $due, 'waiting' => $waiting];
}

/* ------------------------------------------------------------- drawing it */

/** A link to one row on its sheet. */
function fabo_dash_row_link(array $viewer, string $key, int $id): string
{
    return fabo_link($viewer, $viewer['is_admin'] ? 'sheet.php' : 'c.php',
                     ['sheet' => $key]) . '#row-' . $id;
}

/** The sheets, side by side, with how each one stands. */
function fabo_dash_sheets_block(array $viewer, array $health): void
{
    if (!$health) {
        return;
    }
    ?>
  <div class="block">
    <h2>Where the work stands</h2>
    <p class="sb-lead">Every sheet you can see, what is on it, and when it was last
      touched. A sheet nobody has added to in weeks is worth a look even when
      nothing on it is late.</p>
    <div class="scrollx">
      <table class="data">
        <thead><tr>
          <th style="width:24%">Sheet</th>
          <th class="num">Rows</th><th class="num">Open</th><th class="num">Done</th>
          <th class="num">Late</th><th class="num">Due 7d</th><th class="num">Awaiting</th>
          <th style="min-width:130px">Progress</th>
          <th>Last touched</th>
        </tr></thead>
        <tbody>
        <?php foreach ($health as $h): ?>
          <tr>
            <td><a href="<?= e(fabo_link($viewer, $viewer['is_admin'] ? 'sheet.php' : 'c.php',
                                          ['sheet' => $h['key']])) ?>"><?= e($h['label']) ?></a></td>
            <td class="num"><?= number_format($h['rows']) ?></td>
            <td class="num"><?= number_format($h['open']) ?></td>
            <td class="num tone-good"><?= number_format($h['done']) ?></td>
            <td class="num<?= $h['late'] ? ' tone-risk' : '' ?>"><?= number_format($h['late']) ?></td>
            <td class="num<?= $h['due'] ? ' tone-warn' : '' ?>"><?= number_format($h['due']) ?></td>
            <td class="num<?= $h['waiting'] ? ' tone-warn' : '' ?>"><?= number_format($h['waiting']) ?></td>
            <td><div style="display:flex;align-items:center;gap:8px">
              <div class="bar <?= $h['late'] ? 'risk' : ($h['pct'] >= 70 ? '' : 'warn') ?>" style="flex:1">
                <i style="width:<?= (int) $h['pct'] ?>%"></i></div>
              <span style="font-size:12px;color:var(--ink-soft);min-width:34px"><?= (int) $h['pct'] ?>%</span>
            </div></td>
            <td><?php
              if ($h['quiet'] === null) {
                  echo '<span style="color:var(--ink-mute)">nothing on it yet</span>';
              } elseif ($h['quiet'] === 0) {
                  echo 'today';
              } elseif ($h['quiet'] === 1) {
                  echo 'yesterday';
              } else {
                  $tone = $h['quiet'] > 30 ? ' style="color:#8a5a10"' : '';
                  echo '<span' . $tone . '>' . (int) $h['quiet'] . ' days ago</span>';
              }
            ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php
}

/** The handful of rows somebody should look at, named. */
function fabo_dash_attention_block(array $viewer, array $items, array $counts): void
{
    $total = $counts['late'] + $counts['due'] + $counts['waiting'];
    ?>
  <div class="block">
    <h2>What needs you</h2>
    <?php if ($total === 0): ?>
      <p class="sb-lead">Nothing is late, nothing is due this week, and nothing is
        waiting on an approval. That is the whole list.</p>
    <?php else: ?>
      <p class="sb-lead">
        <strong class="<?= $counts['late'] ? 'tone-risk' : '' ?>"><?= (int) $counts['late'] ?></strong> late,
        <strong><?= (int) $counts['due'] ?></strong> due within a week,
        <strong><?= (int) $counts['waiting'] ?></strong> waiting on sign-off<?php
          if ($total > count($items)) {
              echo ' &mdash; the ' . count($items) . ' most pressing are below';
          } ?>.
      </p>
      <div class="scrollx">
        <table class="data">
          <thead><tr><th style="width:8%">Why</th><th style="width:34%">What</th>
            <th style="width:20%">Sheet</th><th style="width:18%">Owner</th><th>When</th></tr></thead>
          <tbody>
          <?php foreach ($items as $it): ?>
            <tr>
              <td><?php
                $tone = ['late' => 'risk', 'due' => 'warn', 'waiting' => 'info'][$it['why']] ?? '';
                $word = ['late' => 'late', 'due' => 'due', 'waiting' => 'sign-off'][$it['why']] ?? '';
              ?><span class="pill tone-<?= e($tone) ?>"><i class="dot"></i><?= e($word) ?></span></td>
              <td><a href="<?= e(fabo_dash_row_link($viewer, $it['key'], $it['id'])) ?>">
                <?= e(mb_strimwidth($it['name'], 0, 60, '…')) ?></a></td>
              <td><?= e($it['sheet']) ?></td>
              <td><?= $it['owner'] !== '' ? e($it['owner'])
                    : '<span style="color:var(--ink-mute)">nobody</span>' ?></td>
              <td><?php
                if ($it['when'] === '') {
                    echo '<span style="color:var(--ink-mute)">&mdash;</span>';
                } else {
                    $d = date('d M', strtotime($it['when']));
                    if ($it['days'] === null)   { echo e($d); }
                    elseif ($it['days'] < 0)    { echo e($d) . ' <span style="color:#8a2b2b">('
                                                      . abs($it['days']) . 'd ago)</span>'; }
                    elseif ($it['days'] === 0)  { echo e($d) . ' <strong>(today)</strong>'; }
                    else                        { echo e($d) . ' <span style="color:var(--ink-soft)">(in '
                                                      . $it['days'] . 'd)</span>'; }
                }
              ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
<?php
}

<?php
/**
 * The main dashboard's picture of the work, drawn from every sheet.
 *
 * WHY THIS EXISTS
 *
 * The dashboard was built when this was a campaign workbook: its tiles and
 * charts read budget_allocated, budget_spent, leads_generated,
 * revenue_attributed and rag_status. The portal has since become an
 * eleven-sheet delivery system, and the Master Activity Log it all reads from
 * carries none of those columns. Not "carries zeros" — the columns are not in
 * the table. So five of the nine headline tiles, the Budget Allocated vs
 * Spent chart and the Monthly Budget Spent chart could only ever render zero,
 * no matter how much work the team did. That is the whole of "the dashboard
 * is not relevant": most of it was measuring something this portal no longer
 * is.
 *
 * What it IS, is eleven sheets of dated work with owners and statuses. So
 * that is what gets measured here, and every figure below is counted from the
 * sheets themselves rather than from the log — which is also the only way
 * "all sheets have to reflect" can be true, since a sheet missing from the
 * log's mapping would otherwise be invisible.
 *
 * The five questions the charts answer, in the order somebody asks them:
 *
 *   are we keeping up            delivery trend, due against completed
 *   where is the work            per sheet, done / open / late
 *   what state is it all in       status mix across everything
 *   what is coming at us          the next eight weeks of due dates
 *   who is carrying it            open work per person
 *
 * ONE PASS
 *
 * fabo_rows() goes to the database every time it is called, and the dashboard
 * already walked all eleven sheets twice — once for the per-sheet table, once
 * for the attention list. Adding graphs would have made it three. So reads go
 * through fabo_dash_read(), which remembers each sheet for the life of the
 * request; the three blocks now share one pass instead of taking one each.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sheets.php';
require_once __DIR__ . '/rules.php';
require_once __DIR__ . '/grid.php';
require_once __DIR__ . '/charts.php';
require_once __DIR__ . '/work.php';
require_once __DIR__ . '/dashsheets.php';   // sheet keys, and fabo_dash_read()

/**
 * Which date on each sheet means "due", which means "finished", and which
 * column names the person.
 *
 * fabo_work_sources() already knows this for all eleven — it is what My Work
 * and the team desk are built on — so the answer is taken from there rather
 * than written down a second place that can drift out of step with it.
 */
function fabo_graph_sources(): array
{
    $src = fabo_work_sources();
    $out = [];

    foreach (fabo_dash_sheet_keys() as $key) {
        if (isset($src[$key])) {
            [$ownerF, $dueF, $doneF] = $src[$key];
            $out[$key] = ['owner' => $ownerF, 'due' => $dueF, 'done' => $doneF];
            continue;
        }
        /* A sheet nobody has added to the work map still has to appear, or
           "every sheet reflects here" quietly stops being true the next time
           one is added. The sheet's own date driver is what the grid already
           sorts and colours by, so it is the right date to fall back on, and
           the owner column is found the same way the sheet briefing finds it. */
        $sheet = fabo_sheet($key);
        if (!$sheet || empty($sheet['table'])) {
            continue;
        }
        $have   = array_flip(array_column($sheet['columns'] ?? [], 1));
        $ownerF = null;
        foreach (['owner', 'designer', 'coordinator', 'spoc_name', 'raised_by'] as $f) {
            if (isset($have[$f])) { $ownerF = $f; break; }
        }
        $doneF = isset($have['completion_date']) ? 'completion_date' : null;
        $out[$key] = ['owner' => $ownerF,
                      'due'   => (string) ($sheet['date_driver'] ?? '') ?: null,
                      'done'  => $doneF];
    }
    return $out;
}

/** The last $n months, oldest first, as ['2026-09' => 'Sep 26', ...]. */
function fabo_graph_months(int $n = 9): array
{
    $out = [];
    for ($i = $n - 1; $i >= 0; $i--) {
        $t = strtotime('first day of -' . $i . ' month');
        $out[date('Y-m', $t)] = date('M y', $t);
    }
    return $out;
}

/** The next $n weeks from this Monday, as ['2026-09-28' => '28 Sep', ...]. */
function fabo_graph_weeks(int $n = 8): array
{
    $out = [];
    $mon = strtotime('monday this week');
    for ($i = 0; $i < $n; $i++) {
        $t = strtotime('+' . $i . ' week', $mon);
        $out[date('Y-m-d', $t)] = date('j M', $t);
    }
    return $out;
}

/**
 * Every figure the block draws, counted in one walk of every sheet.
 *
 * Returns the per-sheet totals in the same shape fabo_dash_sheet_health()
 * uses, so the table block above can be fed from this scan rather than
 * repeating it.
 */
function fabo_graph_scan(array $viewer, int $clientId = 0): array
{
    $today  = fabo_today();
    $soon   = date('Y-m-d', strtotime($today . ' +7 days'));
    $months = fabo_graph_months();
    $weeks  = fabo_graph_weeks();
    $wkEnd  = date('Y-m-d', strtotime(array_key_last($weeks) . ' +6 days'));

    $due  = array_fill_keys(array_keys($months), 0);
    $done = array_fill_keys(array_keys($months), 0);
    $land = array_fill_keys(array_keys($weeks), 0);

    $sheets = [];
    $status = [];
    $owners = [];
    $tot = ['rows' => 0, 'open' => 0, 'done' => 0, 'late' => 0, 'due7' => 0,
            'ontime' => 0, 'judged' => 0, 'unassigned' => 0,
            'no_status_col' => 0, 'status_blank' => 0];

    foreach (fabo_graph_sources() as $key => $f) {
        if (fabo_permission($viewer, $key) === 'none') {
            continue;                  // not theirs to see, not theirs to count
        }
        $sheet = fabo_sheet($key);
        if (!$sheet || empty($sheet['table'])) {
            continue;
        }
        $rows = fabo_dash_read($key, $viewer, $clientId);

        /* Some sheets have no status column at all — the social calendar is
           thirty-nine rows of occasions, not jobs with a state. Rows from
           those must not be reported as "nobody filled the status in", which
           is a different problem with a different fix. */
        $hasStatus = isset(array_flip(array_column($sheet['columns'] ?? [], 1))['status']);

        $s = ['key' => $key, 'label' => (string) ($sheet['label'] ?? $key),
              'rows' => 0, 'open' => 0, 'done' => 0, 'late' => 0, 'due' => 0,
              'waiting' => 0, 'pct' => 0, 'last' => null, 'quiet' => null];

        foreach ($rows as $r) {
            $closed = fabo_is_closed($r['status'] ?? null);
            $d      = $f['due']  !== null ? trim((string) ($r[$f['due']]  ?? '')) : '';
            $c      = $f['done'] !== null ? trim((string) ($r[$f['done']] ?? '')) : '';

            $s['rows']++;
            $tot['rows']++;
            if ($closed) { $s['done']++; $tot['done']++; }
            else         { $s['open']++; $tot['open']++; }

            if (!$closed && $d !== '' && $d < $today)                    { $s['late']++; $tot['late']++; }
            elseif (!$closed && $d !== '' && $d >= $today && $d <= $soon) { $s['due']++;  $tot['due7']++; }
            if (($r['approval_state'] ?? '') === 'Awaiting') {
                $s['waiting']++;
            }

            /* --------------------------------------------- the trend */
            if ($d !== '' && isset($due[substr($d, 0, 7)])) {
                $due[substr($d, 0, 7)]++;
            }
            /* A sheet with a completion column says when it was finished.
               One without it (campaigns, BTL, events, the calendar, the store
               tracker) only says that it IS finished, so the last time anybody
               touched the row stands in for the date. It is a proxy, and the
               chart says so underneath rather than pretending otherwise. */
            $fin = $c !== '' ? $c : ($closed ? substr((string) ($r['updated_at'] ?? ''), 0, 10) : '');
            if ($closed && $fin !== '' && isset($done[substr($fin, 0, 7)])) {
                $done[substr($fin, 0, 7)]++;
            }

            /* ------------------------------------------ what is coming */
            if (!$closed && $d >= $today && $d <= $wkEnd && $d !== '') {
                $mon = date('Y-m-d', strtotime('monday this week', strtotime($d)));
                if (isset($land[$mon])) {
                    $land[$mon]++;
                }
            }

            /* ------------------------------------- was it done on time */
            if ($closed && $d !== '' && $c !== '') {
                $tot['judged']++;
                if ($c <= $d) { $tot['ontime']++; }
            }

            /* ------------------------------------------- the state of it */
            /* A blank status is counted, not skipped. Half the rows here have
               none, and dropping them made a pie that claimed to show
               "everything" while covering barely half of it — and hid the
               fact that fifty items are sitting in no state at all, which is
               worth knowing on its own. */
            $st = trim((string) ($r['status'] ?? ''));
            if ($st === '') {
                if ($hasStatus) { $tot['status_blank']++; }
                else            { $tot['no_status_col']++; }
                $st = 'No status set';
            }
            $status[$st] = ($status[$st] ?? 0) + 1;

            /* --------------------------------------- who is carrying it */
            if (!$closed) {
                $o = $f['owner'] !== null ? trim((string) ($r[$f['owner']] ?? '')) : '';
                if ($o === '') { $tot['unassigned']++; }
                else           { $owners[$o] = ($owners[$o] ?? 0) + 1; }
            }

            $u = (string) ($r['updated_at'] ?? '');
            if ($u !== '' && ($s['last'] === null || $u > $s['last'])) {
                $s['last'] = $u;
            }
        }

        $s['pct']   = $s['rows'] ? (int) round($s['done'] / $s['rows'] * 100) : 0;
        $s['quiet'] = $s['last'] === null ? null
                    : (int) floor((time() - strtotime($s['last'])) / 86400);
        $sheets[$key] = $s;
    }

    arsort($status);
    arsort($owners);

    return [
        'sheets' => $sheets,
        'months' => $months, 'due' => $due, 'done' => $done,
        'weeks'  => $weeks,  'land' => $land,
        'status' => $status, 'owners' => $owners,
        'totals' => $tot,
    ];
}

/**
 * The same picture for one person, on My Work.
 *
 * Built from the work items that page already holds rather than from another
 * scan — those are the rows that are actually theirs (assigned to them or
 * entered by them), which is a narrower thing than everything their account
 * is allowed to read, and it is the narrower thing this page is about.
 */
function fabo_graph_my_block(array $viewer, array $items): void
{
    if (count($items) < 3) {
        return;          // a chart of two bars is a worse table
    }
    $weeks = fabo_graph_weeks();
    $land  = array_fill_keys(array_keys($weeks), 0);
    $end   = date('Y-m-d', strtotime(array_key_last($weeks) . ' +6 days'));
    $today = fabo_today();
    $mix   = [];

    foreach ($items as $it) {
        $st = trim((string) ($it['status'] ?? ''));
        $st = $st !== '' ? $st : 'No status set';
        $mix[$st] = ($mix[$st] ?? 0) + 1;

        $d = trim((string) ($it['due'] ?? ''));
        if (!empty($it['closed']) || $d === '' || $d < $today || $d > $end) {
            continue;
        }
        $mon = date('Y-m-d', strtotime('monday this week', strtotime($d)));
        if (isset($land[$mon])) {
            $land[$mon]++;
        }
    }
    arsort($mix);

    $blank = (int) ($mix['No status set'] ?? 0);
    unset($mix['No status set']);
    $slices = [];
    $i = 0;
    foreach ($mix as $label => $n) {
        if ($i >= count(FABO_SERIES)) { break; }
        $slices[] = ['label' => $label, 'value' => $n, 'color' => FABO_SERIES[$i++]];
    }
    if ($blank > 0) {
        $slices[] = ['label' => 'No status set', 'value' => $blank, 'color' => FABO_NEUTRAL];
    }
    ?>
  <div class="block">
    <h2>Your work at a glance</h2>
    <div class="chartgrid">
      <?php
        fabo_chart_pie('Your items by status', $slices);
        fabo_chart_bar('Your next 8 weeks', array_values($weeks), [
            ['name' => 'Due', 'color' => FABO_BLUE, 'values' => array_values($land)],
        ]);
      ?>
    </div>
  </div>
<?php
}

/** The headline numbers — only ones this portal actually records. */
function fabo_graph_tiles(array $scan): array
{
    $t = $scan['totals'];
    $out = [
        'Items across all sheets' => ['v' => $t['rows'], 'k' => 'n'],
        'Completed'               => ['v' => $t['done'], 'k' => 'n', 'c' => 'green'],
        'Still open'              => ['v' => $t['open'], 'k' => 'n'],
        'Overdue'                 => ['v' => $t['late'], 'k' => 'n', 'c' => 'red'],
        'Due in 7 days'           => ['v' => $t['due7'], 'k' => 'n', 'c' => 'amber'],
        'Completion rate'         => ['v' => $t['rows'] ? $t['done'] / $t['rows'] : 0, 'k' => 'pct'],
    ];
    /* Only shown where it can be worked out: it needs sheets recording both a
       due date and a completion date, and a tile reading 0% because nothing
       is measurable says the opposite of what it means.

       The label carries how many rows it was judged on, because "100%" off
       six rows and "100%" off six hundred are different claims and the tile
       cannot otherwise tell them apart. */
    if ($t['judged'] > 0) {
        $rate = $t['ontime'] / $t['judged'];
        $out['On time (of ' . number_format($t['judged']) . ' rated)'] =
            ['v' => $rate, 'k' => 'pct', 'c' => $rate >= 0.8 ? 'green' : 'amber'];
    }
    return $out;
}

/** Tiles, in the dashboard's own markup. */
function fabo_graph_tiles_html(array $tiles): void
{
    echo '<div class="tiles">';
    foreach ($tiles as $label => $t) {
        $v = ($t['k'] ?? 'n') === 'pct'
            ? round((float) $t['v'] * 100) . '%'
            : number_format((float) $t['v']);
        echo '<div class="tile' . (isset($t['c']) ? ' ' . e($t['c']) : '') . '">'
           . '<div class="k">' . e($label) . '</div>'
           . '<div class="v">' . e($v) . '</div></div>';
    }
    echo '</div>';
}

/**
 * The block itself: the real numbers, then the five charts.
 *
 * Drawn for whoever is looking, from what they are allowed to see — the same
 * function serves us, a client and a team member, because every figure came
 * through fabo_rows() and is already scoped to them.
 */
function fabo_graphs_block(array $viewer, array $scan): void
{
    $t = $scan['totals'];
    if ($t['rows'] === 0) {
        return;                        // nothing on any sheet yet
    }

    /* Sheets worth charting: an empty one is a row of zeros that squeezes the
       ones with work on them. It is still in the table above. */
    $busy = array_filter($scan['sheets'], static fn($s) => $s['rows'] > 0);
    uasort($busy, static fn($a, $b) => $b['rows'] <=> $a['rows']);
    $busy = array_slice($busy, 0, 8, true);
    ?>
  <div class="block">
    <h2>How the work is going</h2>
    <p class="sb-lead">Counted from every sheet you can see, not from the
      Master Activity Log &mdash; so a sheet appears here the moment anybody
      puts a row on it.</p>
    <?php fabo_graph_tiles_html(fabo_graph_tiles($scan)); ?>

    <div class="chartgrid" style="margin-top:14px">
      <?php
        /* 1. Are we keeping up? */
        fabo_chart_line('Delivery trend — last 9 months', array_values($scan['months']), [
            ['name' => 'Due',       'color' => FABO_BLUE,  'values' => array_values($scan['due'])],
            ['name' => 'Completed', 'color' => FABO_AQUA,  'values' => array_values($scan['done'])],
        ]);

        /* 2. Where is the work? */
        fabo_chart_bar('Work by sheet', array_column($busy, 'label'), [
            ['name' => 'Completed', 'color' => FABO_AQUA,   'values' => array_column($busy, 'done')],
            ['name' => 'Open',      'color' => FABO_BLUE,   'values' => array_column($busy, 'open')],
            ['name' => 'Overdue',   'color' => FABO_ORANGE, 'values' => array_column($busy, 'late')],
        ]);

        /* 3. What state is it all in? "No status set" is grey rather than
              taking a hue slot: it is the absence of a state, not one more
              kind of state, and colouring it like the others reads as though
              somebody chose it. */
        $mix   = $scan['status'];
        $blank = (int) ($mix['No status set'] ?? 0);
        unset($mix['No status set']);

        $slices = [];
        $i = 0;
        foreach ($mix as $label => $n) {
            if ($i >= count(FABO_SERIES)) { break; }
            $slices[] = ['label' => $label, 'value' => $n, 'color' => FABO_SERIES[$i++]];
        }
        $shown = array_sum(array_column($slices, 'value'));
        $all   = array_sum($mix);
        if ($all > $shown) {
            $slices[] = ['label' => 'Other', 'value' => $all - $shown, 'color' => '#b6bdc8'];
        }
        if ($blank > 0) {
            $slices[] = ['label' => 'No status set', 'value' => $blank, 'color' => FABO_NEUTRAL];
        }
        fabo_chart_pie('Everything by status', $slices);

        /* 4. What is coming at us? */
        fabo_chart_bar('Landing in the next 8 weeks', array_values($scan['weeks']), [
            ['name' => 'Due', 'color' => FABO_BLUE, 'values' => array_values($scan['land'])],
        ]);

        /* 5. Who is carrying it? */
        if ($scan['owners']) {
            $top = array_slice($scan['owners'], 0, 8, true);
            fabo_chart_bar('Open work by owner', array_keys($top), [
                ['name' => 'Open items', 'color' => FABO_BLUE, 'values' => array_values($top)],
            ]);
        }
      ?>
    </div>

    <p class="hint" style="margin-top:10px">
      <?php if ($t['unassigned'] > 0): ?>
        <strong><?= number_format($t['unassigned']) ?></strong> open
        item<?= $t['unassigned'] === 1 ? '' : 's' ?>
        <?= $t['unassigned'] === 1 ? 'has' : 'have' ?> nobody's name on
        <?= $t['unassigned'] === 1 ? 'it' : 'them' ?>, so
        <?= $t['unassigned'] === 1 ? 'it does' : 'they do' ?> not appear in the
        owner chart.
      <?php endif; ?>
      <?php if ($t['status_blank'] > 0): ?>
        <strong><?= number_format($t['status_blank']) ?></strong>
        <?= $t['status_blank'] === 1 ? 'row has' : 'rows have' ?> a status
        column left empty &mdash; those are worth filling in.
      <?php endif; ?>
      <?php if ($t['no_status_col'] > 0): ?>
        A further <strong><?= number_format($t['no_status_col']) ?></strong>
        sit on sheets that track no status at all, so they are grey by design
        rather than by neglect.
      <?php endif; ?>
      Sheets that record no completion date count a row as finished when it was
      last changed, which is the closest date they hold.
    </p>
  </div>
<?php
}

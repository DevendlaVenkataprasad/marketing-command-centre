<?php
/**
 * The Event Management dashboard.
 *
 * Three parts, top to bottom:
 *
 *   1. The fixed figures every client and owner sees, always in the same
 *      place: total events, approved, pending approval, budget allocated,
 *      budget spent, participants, ideas generated.
 *   2. The charts chosen for this sheet in Sheet Management -> Dashboard
 *      (lib/dashwidgets.php): any column, split any way, drawn any way.
 *   3. Spoke-wise: every hub and spoke with the same fixed figures, a table
 *      and charts, including spokes with nothing on them yet.
 *   4. The same fixed figures split by owner, type and so on.
 *
 * It draws the rows the page already fetched, so it is scoped exactly as the
 * grid is -- every client for the admin (or the one picked), a client's own
 * organisation for a client and their team -- with the filters on top.
 */

require_once __DIR__ . '/grid.php';
require_once __DIR__ . '/compute.php';
require_once __DIR__ . '/dashwidgets.php';

/**
 * The fixed figures: [label, metric key (see fabo_dw_value), kind, compare].
 * `compare` is the planned figure a note under the tile measures against.
 */
function fabo_evdash_fixed(): array
{
    return [
        ['Total Events',            'rows',                      'num',   ''],
        ['Approved',                'approved',                  'num',   ''],
        ['Pending Approval',        'pending',                   'num',   ''],
        ['Total Budget Allocated',  'sum:budget_allocated',      'money', ''],
        ['Total Budget Spent',      'sum:budget_spent',          'money', 'sum:budget_allocated'],
        ['No. of Participants',     'sum:achieved_participants', 'num',   'sum:expected_participants'],
        ["No. of Ideas Generated",  'sum:achieved_ideas',        'num',   'sum:expected_ideas'],
    ];
}

/** What each spoke card shows, so nothing beside it shows it again. */
function fabo_evdash_card_metrics(): array
{
    return ['rows', 'sum:achieved_participants'];
}

/**
 * Does this chart repeat something the Events dashboard already shows?
 *
 * Returns the reason, or '' when it does not. A chart that repeats is not
 * drawn on the dashboard -- the same figure twice reads as two different
 * figures -- and Sheet Management says why beside it rather than hiding it
 * there too. Three ways to repeat:
 *
 *   - one figure, not split, that is already a headline figure;
 *   - approval counts split by approval: the Approved and Pending tiles;
 *   - anything split by hub / spoke: that is the Hub / Spoke-wise section.
 */
function fabo_evdash_repeats(array $w, array $sheet): string
{
    $fixed = array_map(static fn($f) => $f[1], fabo_evdash_fixed());
    foreach (fabo_evdash_fixed() as $f) {
        if ($f[3] !== '') { $fixed[] = $f[3]; }        // "% of planned" is on the tile too
    }
    $want  = fabo_dw_measures($w);
    $group = (string) ($w['group_by'] ?? '');
    $chart = (string) ($w['chart'] ?? '');
    $split = (fabo_dw_charts()[$chart][1] ?? false) && $group !== '';

    if (!$split && $want && !array_diff($want, $fixed)) {
        return 'repeats the headline figures';
    }
    if ($group === 'approval' && !array_diff($want, ['rows', 'approved', 'pending', 'rejected'])) {
        return 'repeats the Approved / Pending figures';
    }
    $col = fabo_evdash_spoke_col($sheet);
    if ($split && $col && $group === $col['field']) {
        return 'repeats the ' . $col['label'] . '-wise section';
    }
    return '';
}

/** Columns the fixed figures can be split by, in the order offered.
 *  District is not one, and Hub / Spoke has a section of its own. */
function fabo_evdash_dimensions(): array
{
    return ['owner', 'event_type', 'sector', 'target_audience',
            'funding_source', 'brand', 'business_unit', 'status'];
}

/**
 * The column this organisation keeps its hub / spoke in, as
 * ['field', 'label', 'list'], or null when the sheet has none.
 *
 * Not always the shipped Hub / Spoke column: an organisation may take that
 * one off its sheet and add a column of its own instead, and then every
 * spoke figure has to read theirs -- reading the hidden one put every event
 * under "(not set)" and drew no spoke cards at all. Looked for in this order:
 * the shipped column if it is still on the sheet, then a dropdown on the
 * Hub / Spoke list, then any column whose heading says hub or spoke.
 */
function fabo_evdash_spoke_col(array $sheet): ?array
{
    require_once __DIR__ . '/departments.php';
    $pick = static fn(array $c): array => ['field' => (string) $c[1], 'label' => (string) $c[0],
                                           'list' => (string) ($c[3]['list'] ?? '')];
    foreach ($sheet['columns'] as $c) {
        if ($c[1] === 'hub_spoke') { return $pick($c); }
    }
    foreach ($sheet['columns'] as $c) {
        if (($c[3]['list'] ?? '') === FABO_HUB_LIST) { return $pick($c); }
    }
    foreach ($sheet['columns'] as $c) {
        if (in_array($c[2], ['select', 'text'], true)
            && preg_match('/\bhub\b|spoke/i', (string) $c[0])) {
            return $pick($c);
        }
    }
    return null;
}

/** The district column the same way: the shipped one, or one headed District. */
function fabo_evdash_district_col(array $sheet): ?array
{
    foreach ($sheet['columns'] as $c) {
        if ($c[1] === 'city') { return ['field' => 'city', 'label' => (string) $c[0]]; }
    }
    foreach ($sheet['columns'] as $c) {
        if (in_array($c[2], ['select', 'text'], true) && preg_match('/district/i', (string) $c[0])) {
            return ['field' => (string) $c[1], 'label' => (string) $c[0]];
        }
    }
    return null;
}

/**
 * Every hub and spoke: the ones on the column's own list, in list order, then
 * any other value a row carries. A spoke with no events still gets a line --
 * "nothing from Ongole yet" is a finding, and a missing line hides it.
 */
function fabo_evdash_spokes(array $rows, ?array $col = null): array
{
    require_once __DIR__ . '/departments.php';
    $field = $col['field'] ?? 'hub_spoke';
    $list  = ($col['list'] ?? '') !== '' ? (string) $col['list'] : FABO_HUB_LIST;
    $out = [];
    foreach (fabo_list_values($list) as $v) {
        /* "All" (or "Both") on the list means every spoke, not a spoke of its
           own, so it gets no card and no line. */
        if (preg_match('/^(all|both|any)$/i', trim($v))) {
            continue;
        }
        $out[mb_strtolower($v)] = $v;
    }
    foreach ($rows as $r) {
        $v = trim((string) ($r[$field] ?? ''));
        if ($v !== '' && !isset($out[mb_strtolower($v)]) && !preg_match('/^(all|both|any)$/i', $v)) {
            $out[mb_strtolower($v)] = $v;
        }
    }
    return array_values($out);
}

/**
 * The spoke cards across the top: one card per hub / spoke on the Hub /
 * Spoke list, each saying how many events it has run, how many people took
 * part and how many districts it reached.
 *
 * One card per list value, so adding a spoke on Sheet Management adds a card,
 * and a spoke with nothing yet still has one -- showing zeros is the point.
 * Rows with no spoke get a card of their own only when there are some.
 */
function fabo_evdash_spoke_cards(array $sheet, array $rows): void
{
    $col = fabo_evdash_spoke_col($sheet);
    if (!$col) {
        return;                          // no hub / spoke column on this sheet
    }
    $spokes = fabo_evdash_spokes($rows, $col);
    if (!$spokes) {
        return;
    }
    $dcol = fabo_evdash_district_col($sheet);
    $sets = [];
    foreach ($spokes as $sp) { $sets[mb_strtolower($sp)] = ['name' => $sp, 'rows' => []]; }
    foreach ($rows as $r) {
        $v = mb_strtolower(trim((string) ($r[$col['field']] ?? '')));
        if ($v === '') {
            $sets['~'] ??= ['name' => 'Spoke not set', 'rows' => []];
            $sets['~']['rows'][] = $r;
        } elseif (isset($sets[$v])) {
            $sets[$v]['rows'][] = $r;
        }
    }
    $district = $dcol['label'] ?? 'District';
    $total    = max(1, count($rows));
    ?>
  <div class="evrow-head"><span><?= e($col['label']) ?>-wise</span>
    <small><?= count($spokes) ?> <?= count($spokes) === 1 ? 'spoke' : 'spokes' ?></small></div>
  <div class="spokecards">
    <?php $i = 0; foreach ($sets as $k => $set):
        $n     = count($set['rows']);
        $ppl   = fabo_dw_value('sum:achieved_participants', $set['rows']);
        $dists = $dcol ? fabo_dw_value('distinct:' . $dcol['field'], $set['rows']) : 0.0;
        $share = round($n / $total * 100);
        $color = $k === '~' ? '#eda100' : FABO_SERIES[$i++ % count(FABO_SERIES)];
    ?>
      <div class="spokecard<?= $n ? '' : ' empty' ?>" style="--c:<?= e($color) ?>">
        <div class="sc-top">
          <span class="sc-dot"></span>
          <span class="sc-name" title="<?= e($set['name']) ?>"><?= e($set['name']) ?></span>
          <span class="sc-share" title="Share of all events in view"><?= $share ?>%</span>
        </div>
        <div class="sc-bar"><i style="width:<?= $share ?>%"></i></div>
        <div class="sc-figs">
          <div><b><?= e(fabo_dw_fmt((float) $n, 'num')) ?></b><span>Events</span></div>
          <div><b><?= e(fabo_dw_fmt($ppl, 'num', true)) ?></b><span>Participants</span></div>
          <div><b><?= e(fabo_dw_fmt($dists, 'num')) ?></b><span><?= e($district) ?>s</span></div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php
}

/** Section 3: the fixed figures for every hub and spoke. */
function fabo_evdash_spoke_block(array $viewer, array $sheet, array $rows): void
{
    $col = fabo_evdash_spoke_col($sheet);
    if (!$col) {
        return;                          // no hub / spoke column on this sheet
    }
    $label  = $col['label'];
    $spokes = fabo_evdash_spokes($rows, $col);
    if (!$spokes) {
        return;
    }
    $sets = [];
    foreach ($spokes as $sp) { $sets[$sp] = []; }
    $blank = [];
    foreach ($rows as $r) {
        $v = trim((string) ($r[$col['field']] ?? ''));
        $hit = null;
        foreach ($spokes as $sp) {
            if (mb_strtolower($sp) === mb_strtolower($v)) { $hit = $sp; break; }
        }
        if ($hit === null) { $blank[] = $r; } else { $sets[$hit][] = $r; }
    }
    if ($blank) { $sets['(not set)'] = $blank; }
    /* On a client's dashboard the spoke cards above already give each spoke's
       events and participants, so the table carries the rest, not those again. */
    $fixed = fabo_evdash_fixed();
    if (empty($viewer['is_admin'])) {
        $fixed = array_values(array_filter($fixed, static fn($f) =>
            !in_array($f[1], fabo_evdash_card_metrics(), true)));
    }
    ?>
  <div class="block evdash" id="spokes">
    <div class="calhead"><h2 style="margin:0;flex:1"><?= e($label) ?>-wise</h2>
      <span class="hint"><?= count($spokes) ?> on the list &middot; every one shown, even with no events yet</span></div>
    <div class="scrollx"><table class="data">
      <thead><tr><th><?= e($label) ?></th>
        <?php foreach ($fixed as [$l]): ?><th class="num"><?= e($l) ?></th><?php endforeach; ?>
      </tr></thead>
      <tbody>
      <?php foreach ($sets as $sp => $set): ?>
        <tr<?= $set ? '' : ' class="dwzero"' ?>>
          <td><strong><?= e((string) $sp) ?></strong></td>
          <?php foreach ($fixed as [, $metric, $kind]): ?>
            <td class="num"><?= e(fabo_dw_fmt(fabo_dw_value($metric, $set), $kind)) ?></td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
        <tr class="dwtotal"><td>Total</td>
          <?php foreach ($fixed as [, $metric, $kind]): ?>
            <td class="num"><?= e(fabo_dw_fmt(fabo_dw_value($metric, $rows), $kind)) ?></td>
          <?php endforeach; ?>
        </tr>
      </tbody>
    </table></div>

  </div>
<?php
}

/** Renders the whole block. */
function fabo_event_dashboard(array $viewer, array $sheet, array $rows): void
{
    $today  = fabo_today();
    $fields = [];
    foreach ($sheet['columns'] as $c) { $fields[$c[1]] = $c[0]; }
    $n = count($rows);

    /* ---------------------------------------------- 1. fixed figures */
    $tiles = [];
    foreach (fabo_evdash_fixed() as [$label, $metric, $kind, $cmp]) {
        $v    = fabo_dw_value($metric, $rows);
        $note = '';
        if ($metric === 'approved' || $metric === 'pending') {
            $note = $n ? round($v / $n * 100) . '% of events' : '';
        } elseif ($cmp !== '') {
            $plan = fabo_dw_value($cmp, $rows);
            $note = $plan > 0
                ? round($v / $plan * 100, 1) . '% of ' . fabo_dw_fmt($plan, $kind)
                  . ($metric === 'sum:budget_spent' ? ' allocated' : ' expected')
                : '';
        }
        $tone = '';
        if ($metric === 'pending' && $v > 0)  { $tone = 'amber'; }
        if ($metric === 'approved' && $v > 0) { $tone = 'green'; }
        if ($metric === 'sum:budget_spent') {
            $alloc = fabo_dw_value('sum:budget_allocated', $rows);
            if ($alloc > 0 && $v > $alloc) { $tone = 'red'; $note .= ' · over budget'; }
        }
        $tiles[] = [$label, fabo_dw_fmt($v, $kind), $tone, $note];
    }

    /* --------------------------------------- 4. the split, prepared */
    $dims = [];
    foreach (fabo_evdash_dimensions() as $f) {
        if (!isset($fields[$f])) { continue; }
        $vals = [];
        foreach ($rows as $r) {
            $v = trim((string) ($r[$f] ?? ''));
            $vals[$v === '' ? '(not set)' : $v][] = $r;
        }
        if (!$vals || ($f !== 'owner' && count($vals) < 2)) {
            continue;   // one value splits nothing; Owner is always worth seeing
        }
        uasort($vals, static fn($x, $y) => count($y) <=> count($x));
        $dims[$f] = $vals;
    }
    $dimPick = (string) ($_GET['evby'] ?? '');
    if (!isset($dims[$dimPick])) {
        $dimPick = (string) (array_key_first($dims) ?? '');
    }
    /* What waits on a committee member's vote lives on their Committee
       Dashboard (committee.php), not here. */
    ?>
  <div class="block evdash">
    <h2>Programme dashboard</h2>
    <div class="hint" style="margin:-4px 0 12px">
      <?= number_format($n) ?> event<?= $n === 1 ? '' : 's' ?> in view
      &middot; as of <?= e(date('d M Y', strtotime($today))) ?>
      &middot; follows the filters above</div>

    <!-- fabo-eventdash: spoke-cards v7 -->
    <div class="evrow-head"><span>Headline figures</span></div>
    <div class="evfixed">
      <?php foreach ($tiles as $i => [$k, $v, $tone, $note]): ?>
        <div class="evtile<?= $tone !== '' ? ' t-' . e($tone) : '' ?>" style="--c:<?= e(FABO_SERIES[$i % count(FABO_SERIES)]) ?>">
          <div class="k"><?= e($k) ?></div>
          <div class="v"><?= e($v) ?></div>
          <div class="tnote"><?= $note !== '' ? e($note) : '&nbsp;' ?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <?php /* The spoke cards are the clients' own requirement: shown on their
             dashboards, not on ours. */
          if (empty($viewer['is_admin'])) { fabo_evdash_spoke_cards($sheet, $rows); } ?>

    <?php if (!$rows): ?>
      <div class="empty">No events to show yet. Add them on the Programs Data tab
        &mdash; this dashboard fills itself from those rows.</div>
    <?php endif; ?>
  </div>

  <?php /* ---------------------------------- 2. the chosen charts */ ?>
  <?php fabo_dw_render($viewer, 'events', $sheet, $rows,
                       static fn(array $w) => fabo_evdash_repeats($w, $sheet) !== ''); ?>

  <?php /* ---------------------------------------- 3. spoke-wise */ ?>
  <?php fabo_evdash_spoke_block($viewer, $sheet, $rows); ?>

  <?php if ($rows && $dims): ?>
  <div class="block evdash">
    <div class="calhead" style="gap:10px;flex-wrap:wrap">
      <h2 style="margin:0;flex:1">By <?= e(mb_strtolower($fields[$dimPick])) ?></h2>
      <nav class="evtabs" style="margin:0" aria-label="Split by">
        <?php foreach ($dims as $f => $_): ?>
          <a class="evtab<?= $f === $dimPick ? ' on' : '' ?>"
             href="<?= e(fabo_event_url($viewer, ['evby' => $f])) ?>#evby"><?= e($fields[$f]) ?></a>
        <?php endforeach; ?>
      </nav>
    </div>
    <div class="scrollx" id="evby"><table class="data">
      <thead><tr>
        <th><?= e($fields[$dimPick]) ?></th>
        <?php foreach (fabo_evdash_fixed() as [$label]): ?>
          <th class="num"><?= e($label) ?></th>
        <?php endforeach; ?>
      </tr></thead>
      <tbody>
      <?php foreach ($dims[$dimPick] as $val => $set): ?>
        <tr>
          <td><strong><?= e((string) $val) ?></strong></td>
          <?php foreach (fabo_evdash_fixed() as [, $metric, $kind]): ?>
            <td class="num"><?= e(fabo_dw_fmt(fabo_dw_value($metric, $set), $kind)) ?></td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <?php endif; ?>
<?php
}

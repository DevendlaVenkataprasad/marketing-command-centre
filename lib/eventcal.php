<?php
/**
 * The Programme Calendar on the Events sheet.
 *
 * Four ways to look at the same programmes — Month, Week, Day and Agenda —
 * with one filter bar over all of them, the next programmes alongside, and a
 * detail panel behind every chip. The six tiles across the top carry the
 * figures; the charts that used to sit below them are gone, because the
 * tiles and the sheet dashboard already said the same things.
 *
 * It reads the rows the sheet already fetched, so it inherits the viewer's
 * scope and never issues a query of its own. Chips from the other dated
 * sheets (content going live, campaigns starting, design due) ride along on
 * the Month view, because the point of a calendar is to show the whole load
 * of a month, not one sheet's slice of it.
 */

require_once __DIR__ . '/programs.php';
require_once __DIR__ . '/events_blocks.php';   // fabo_calendar_sources / _items

/** The views, in the order the tabs show them. */
function fabo_cal_views(): array
{
    return ['month' => 'Month', 'week' => 'Week', 'day' => 'Day', 'agenda' => 'Agenda'];
}

/** A URL back to this page with some parameters changed. */
function fabo_cal_url(array $viewer, array $over = []): string
{
    $p = array_merge($_GET, $over);
    $p['sheet'] = 'events';
    $p['view']  = 'calendar';     // stay on the Calendar tab
    unset($p['k']);               // fabo_link() adds the token back when needed
    foreach ($p as $k => $v) {
        if ($v === '' || $v === null) { unset($p[$k]); }
    }
    unset($p['row']);
    return fabo_link($viewer, $viewer['is_admin'] ? 'sheet.php' : 'c.php', $p) . '#cal';
}

/**
 * Everything the detail panel shows for one programme, as label => value.
 * Empty fields are dropped rather than shown as dashes: a panel of twenty
 * dashes tells you nothing, and hides the four fields that were filled in.
 */
function fabo_cal_detail(array $r): array
{
    $money = static fn($v) => ($v === null || $v === '') ? '' : fabo_money($v);
    $plan  = static function ($actual, $planned) {
        $a = ($actual === null || $actual === '') ? null : (int) $actual;
        $p = ($planned === null || $planned === '') ? null : (int) $planned;
        if ($a === null && $p === null) { return ''; }
        if ($p === null)                { return (string) $a; }
        if ($a === null)                { return $p . ' planned'; }
        $pct = fabo_prog_pct((float) $a, (float) $p);
        return $a . ' of ' . $p . ' planned' . ($pct !== null ? ' · ' . $pct . '%' : '');
    };

    $when = '';
    if (!empty($r['start_date'])) {
        $when = date('D d M Y', strtotime((string) $r['start_date']));
        if (!empty($r['end_date']) && $r['end_date'] !== $r['start_date']) {
            $when .= ' – ' . date('D d M Y', strtotime((string) $r['end_date']));
        }
    }

    return array_filter([
        'When'              => $when,
        'Status'            => (string) ($r['status'] ?? ''),
        'Sign-off'          => (string) ($r['approval_status'] ?? ''),
        'Sign-off note'     => (string) ($r['approval_note'] ?? ''),
        'Brand'             => (string) ($r['brand'] ?? ''),
        'Business unit'     => (string) ($r['business_unit'] ?? ''),
        'Event type'        => (string) ($r['event_type'] ?? ''),
        'Priority'          => (string) ($r['priority'] ?? ''),
        'Owner'             => (string) ($r['owner'] ?? ''),
        'Location'          => trim(((string) ($r['location'] ?? '')) .
                                    (($r['city'] ?? '') ? ', ' . $r['city'] : ''), ' ,'),
        'Sector'            => (string) ($r['sector'] ?? ''),
        'Target audience'   => (string) ($r['target_audience'] ?? ''),
        'Partners'          => (string) ($r['partners'] ?? ''),
        'Partners count'    => ($r['partners_count'] ?? '') === '' ? '' : (string) (int) $r['partners_count'],
        'Attendance'        => $plan($r['actual_attendees'] ?? null, $r['expected_attendees'] ?? null),
        'Leads'             => $plan($r['leads_generated'] ?? null, $r['leads_expected'] ?? null),
        'Budget allocated'  => $money($r['budget_allocated'] ?? null),
        'Budget spent'      => $money($r['budget_spent'] ?? null),
        'Funding source'    => (string) ($r['funding_source'] ?? ''),
        'Duration (hrs)'    => (string) ($r['duration_hours'] ?? ''),
        'Creative due'      => !empty($r['creative_due_date'])
                               ? date('d M Y', strtotime((string) $r['creative_due_date'])) : '',
        'Linked campaign'   => (string) ($r['linked_campaign_id'] ?? ''),
        'SPOC'              => trim(((string) ($r['spoc_name'] ?? '')) .
                                    (($r['spoc_contact'] ?? '') ? ' · ' . $r['spoc_contact'] : '') .
                                    (($r['spoc_email'] ?? '') ? ' · ' . $r['spoc_email'] : ''), ' ·'),
        'Programme details' => (string) ($r['programme_details'] ?? ''),
        'Partner details'   => (string) ($r['partner_details'] ?? ''),
        'Local resources'   => (string) ($r['local_resources'] ?? ''),
        'Case studies'      => (string) ($r['case_studies'] ?? ''),
        'Remarks'           => (string) ($r['remarks'] ?? ''),
    ], static fn($v) => trim((string) $v) !== '');
}

/** The fields that get a full-width row in the panel, being prose not a value. */
function fabo_cal_wide(): array
{
    return ['Programme details', 'Partner details', 'Local resources',
            'Case studies', 'Remarks', 'Sign-off note'];
}

/** One programme chip. `$link` opens the row in the grid. */
function fabo_cal_chip(array $viewer, array $r): string
{
    $id   = (int) $r['id'];
    $name = (string) ($r['event_name'] ?? '');
    if ($name === '') { $name = 'Untitled programme'; }

    $detail = fabo_cal_detail($r);

    // The raw values too, so Edit can fill the form without another request.
    // Only the fields the form actually has — there is no point shipping the
    // computed columns to a form that cannot write them.
    $edit = [];
    foreach (['event_name', 'event_type', 'brand', 'business_unit', 'start_date', 'end_date',
              'location', 'city', 'owner', 'status', 'priority', 'budget_allocated',
              'budget_spent', 'expected_attendees', 'actual_attendees', 'leads_expected',
              'leads_generated', 'linked_campaign_id', 'creative_due_date',
              'programme_details', 'sector', 'target_audience', 'partners', 'partner_details',
              'partners_count', 'duration_hours', 'duration_hours_expected', 'funding_source',
              'local_resources', 'case_studies', 'spoc_name', 'spoc_email', 'spoc_contact',
              'remarks'] as $f) {
        $edit[$f] = $r[$f] ?? '';
    }

    $payload = json_encode([
        'title' => $name,
        'sub'   => trim(((string) ($r['brand'] ?? '')) .
                        (($r['event_type'] ?? '') ? ' · ' . $r['event_type'] : ''), ' ·'),
        'rows'  => $detail,
        'wide'  => array_values(array_intersect(fabo_cal_wide(), array_keys($detail))),
        'href'  => fabo_link($viewer, $viewer['is_admin'] ? 'sheet.php' : 'c.php',
                             // Programs Data: the tab every row is on (no view opens the Dashboard).
                             ['sheet' => 'events', 'view' => 'programs', 'row' => $id]),
        'id'    => $id,
        'edit'  => $edit,
    ], JSON_UNESCAPED_UNICODE);

    return '<button type="button" class="ec-chip tone-' . e(fabo_prog_tone($r)) . '"'
         . ' data-prog="' . e((string) $payload) . '" title="' . e($name) . '">'
         . e($name) . '</button>';
}

/**
 * The whole module. Called from the Events sheet page, below the grid.
 */
function fabo_event_calendar(array $viewer, array $rows): void
{
    $today = fabo_today();
    $perm  = fabo_permission($viewer, 'events');

    /* `cv`, not `view`: `view` is the Event Management tab (Dashboard /
       Calendar / Programs / Departments). Sharing the one parameter meant
       pressing Week, Day or Agenda threw the page back to the Dashboard. */
    $view = (string) ($_GET['cv'] ?? 'month');
    if (!isset(fabo_cal_views()[$view])) { $view = 'month'; }

    $f        = fabo_prog_filters();
    $filtered = fabo_prog_sort(fabo_prog_match($rows, $f));

    /* This month, or all time. All time lists every programme that matches
       the filters, undated ones included, and the figures above it count all
       of them -- the month grid can only ever show one month. */
    $range = (string) ($_GET['cr'] ?? '') === 'all' ? 'all' : 'month';

    $on = (string) ($_GET['on'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $on) || !strtotime($on)) {
        $on = $today;
    }

    // What the tabs step by, and what the heading calls the step.
    [$step, $heading, $from, $to] = match ($view) {
        'week' => (static function () use ($on) {
            $mon = date('Y-m-d', strtotime('monday this week', strtotime($on)));
            $sun = date('Y-m-d', strtotime($mon . ' +6 days'));
            return ['1 week',
                    date('d M', strtotime($mon)) . ' – ' . date('d M Y', strtotime($sun)),
                    $mon, $sun];
        })(),
        'day'  => ['1 day', date('D d M Y', strtotime($on)), $on, $on],
        default => (static function () use ($on) {
            $first = date('Y-m-01', strtotime($on));
            return ['1 month', date('F Y', strtotime($first)),
                    $first, date('Y-m-t', strtotime($first))];
        })(),
    };

    // The Agenda navigates by period, not by the < > arrows.
    if ($view === 'agenda') {
        $w       = fabo_prog_window($f['period'], $f['from'], $f['to']);
        $from    = $w['from'];
        $to      = $w['to'];
        $heading = $w['label'];
    }
    if ($range === 'all') {
        $view    = 'agenda';
        $from    = '1970-01-01';
        $to      = '2999-12-31';
        $heading = 'All time';
    }

    $inRange = fabo_prog_in_range($filtered, $from, $to);

    $prev = $view === 'agenda' ? '' : date('Y-m-d', strtotime($on . ' -' . $step));
    $next = $view === 'agenda' ? '' : date('Y-m-d', strtotime($on . ' +' . $step));

    echo '<div class="block eventcal" id="cal">';
    echo '<h2>Programme Calendar</h2>';

    // All time beside the period, so a quiet month never hides the totals.
    // The dashboard's own seven figures, for the period on screen and all time.
    fabo_cal_tiles($inRange, $heading, $range === 'all' ? null : $filtered);
    fabo_cal_filters($viewer, $rows, $f, $view);
    fabo_cal_tabs($viewer, $view, $heading, $prev, $next, $today, count($inRange),
                  $perm === 'edit', $f, $range);
    if ($range === 'month' && $view === 'month' && !$inRange && $filtered) {
        echo '<div class="ec-empty"><strong>No programmes in ' . e($heading) . '</strong>'
           . '<span><a href="' . e(fabo_cal_url($viewer, ['cr' => 'all'])) . '">Show all time</a>'
           . ' to see all ' . count($filtered) . ', or step to another month with the arrows.</span></div>';
    }

    echo '<div class="ec-layout">';
    echo '<div class="ec-main">';
    match ($view) {
        'week'   => fabo_cal_week($viewer, $inRange, $from, $today),
        'day'    => fabo_cal_day($viewer, $inRange, $from, $today),
        'agenda' => fabo_cal_agenda($viewer, $inRange, $today),
        default  => fabo_cal_month($viewer, $inRange, $from, $to, $today, $f),
    };
    echo '</div>';
    fabo_cal_upcoming($viewer, $filtered, $today);
    echo '</div>';   // .ec-layout

    echo '</div>';   // .block
    fabo_cal_dialog();
    if ($perm === 'edit') {
        fabo_cal_form($viewer, $rows, $viewer['is_admin'] ? '../api/' : 'api/');
    }
}

/**
 * The headline figures for the period on screen: the same seven the Events
 * dashboard shows -- Total Events, Approved, Pending Approval, Budget
 * Allocated, Budget Spent, Participants, Ideas Generated -- worked out the
 * same way (lib/eventdash.php), so the calendar and the dashboard never
 * disagree about a number. With $all, each tile also carries the figure for
 * all time, within the same filters.
 */
function fabo_cal_tiles(array $rows, string $heading, ?array $all = null): void
{
    require_once __DIR__ . '/eventdash.php';

    $note = static function (string $metric, string $cmp, string $kind, array $set): string {
        if ($metric === 'approved' || $metric === 'pending') {
            $n = count($set);
            return $n ? round(fabo_dw_value($metric, $set) / $n * 100) . '% of events' : '';
        }
        if ($cmp === '') {
            return '';
        }
        $plan = fabo_dw_value($cmp, $set);
        return $plan > 0
            ? 'of ' . fabo_dw_fmt($plan, $kind) . ($metric === 'sum:budget_spent' ? ' allocated' : ' expected')
            : '';
    };

    echo '<div class="ec-tiles ec-tiles7">';
    foreach (fabo_evdash_fixed() as $i => [$label, $metric, $kind, $cmp]) {
        $v   = fabo_dw_value($metric, $rows);
        $sub = $i === 0 ? $heading : $note($metric, $cmp, $kind, $rows);
        /* The same colour per figure as the dashboard's tiles, and the same
           green / amber for approved / pending, so the two read as one set. */
        $tone = $metric === 'approved' ? ' ec-good' : ($metric === 'pending' && $v > 0 ? ' ec-warn' : '');
        if ($metric === 'sum:budget_spent' && $v > fabo_dw_value('sum:budget_allocated', $rows)
            && fabo_dw_value('sum:budget_allocated', $rows) > 0) {
            $tone = ' ec-risk';
        }
        echo '<div class="ec-tile ec-fig' . $tone . '" style="--c:' . e(FABO_SERIES[$i % count(FABO_SERIES)]) . '">'
           . '<span>' . e($label) . '</span>'
           . '<strong>' . e(fabo_dw_fmt($v, $kind)) . '</strong>'
           . '<small>' . ($sub !== '' ? e($sub) : '&nbsp;') . '</small>';
        if ($all !== null) {
            $an = $i === 0 ? '' : $note($metric, $cmp, $kind, $all);
            echo '<div class="ec-alltime"><em>All time</em> <b>'
               . e(fabo_dw_fmt(fabo_dw_value($metric, $all), $kind)) . '</b>'
               . ($an !== '' ? ' <small>' . e($an) . '</small>' : '') . '</div>';
        }
        echo '</div>';
    }
    echo '</div>';
}

/** The filter bar: search plus the five menus, and a Clear when it is doing
 *  something. Every control is a plain form field, so it works with no JS. */
function fabo_cal_filters(array $viewer, array $rows, array $f, string $view): void
{
    $menus = [
        ['fbrand',  'Brand',    'brand',           $f['brand'],   'All brands'],
        ['ftype',   'Type',     'event_type',      $f['type'],    'All types'],
        ['fstatus', 'Status',   'status',          $f['status'],  'All statuses'],
        ['fowner',  'Owner',    'owner',           $f['owner'],   'Anyone'],
    ];
    $action = fabo_link($viewer, $viewer['is_admin'] ? 'sheet.php' : 'c.php', []);
    ?>
  <form class="ec-filters" method="get" action="<?= e($action) ?>">
    <?php
      // Whatever identifies the viewer and the page has to survive the submit:
      // the Calendar tab, the calendar's own view, the client picked on the
      // admin side and the Event Management filters above.
      $own  = ['q', 'fbrand', 'ftype', 'fstatus', 'fsign', 'fowner', 'period', 'from', 'to'];
      $keep = ['sheet' => 'events', 'view' => 'calendar', 'cv' => $view];
      foreach ($_GET as $k => $v) {
          if (is_string($v) && $v !== '' && !isset($keep[$k]) && !in_array($k, $own, true)) {
              $keep[$k] = $v;
          }
      }
      if (!$viewer['is_admin'] && !fabo_signed_in()) {
          $keep['k'] = (string) $viewer['client']['token'];
      }
      foreach ($keep as $k => $v) {
          echo '<input type="hidden" name="' . e((string) $k) . '" value="' . e((string) $v) . '">';
      }
    ?>
    <div class="ec-search">
      <input type="search" name="q" value="<?= e($f['q']) ?>"
             placeholder="Search a programme, place, owner, partner or SPOC…"
             aria-label="Search programmes">
    </div>
    <div class="ec-filterrow">
      <?php foreach ($menus as [$name, $label, $field, $current, $blank]): ?>
        <label class="ec-pick"><span><?= e($label) ?></span>
          <select name="<?= e($name) ?>">
            <option value=""><?= e($blank) ?></option>
            <?php foreach (fabo_prog_values($rows, $field) as $v): ?>
              <option value="<?= e($v) ?>"<?= $current === $v ? ' selected' : '' ?>><?= e($v) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      <?php endforeach; ?>
      <label class="ec-pick"><span>Period <small>(Agenda)</small></span>
        <select name="period" id="ecPeriod">
          <?php foreach (fabo_prog_periods() as $k => $lab): ?>
            <option value="<?= e($k) ?>"<?= $f['period'] === $k ? ' selected' : '' ?>><?= e($lab) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="ec-pick ec-when"<?= $f['period'] === 'custom' ? '' : ' hidden' ?>><span>From</span>
        <input type="date" name="from" value="<?= e($f['from']) ?>"></label>
      <label class="ec-pick ec-when"<?= $f['period'] === 'custom' ? '' : ' hidden' ?>><span>To</span>
        <input type="date" name="to" value="<?= e($f['to']) ?>"></label>
      <div class="ec-filteractions">
        <button class="btn btn-primary btn-sm" type="submit">Apply</button>
        <?php if (fabo_prog_filtered($f)): ?>
          <a class="btn btn-sm" href="<?= e(fabo_cal_url($viewer,
                ['q' => '', 'fbrand' => '', 'ftype' => '', 'fstatus' => '', 'fsign' => '',
                 'fowner' => '', 'period' => '', 'from' => '', 'to' => ''])) ?>">Clear</a>
        <?php endif; ?>
      </div>
    </div>
    <p class="ec-hint">Month, Week and Day step with the arrows below. The
       <strong>Period</strong> menu sets the Agenda's range — quarters and
       half-years run April to March, like the rest of the portal.</p>
  </form>
    <?php
}

/** The view tabs and the < Today > navigator. */
function fabo_cal_tabs(array $viewer, string $view, string $heading,
                       string $prev, string $next, string $today, int $count,
                       bool $mayEdit, array $f = [], string $range = 'month'): void
{
    ?>
  <div class="ec-bar">
    <div class="ec-tabs" role="tablist" aria-label="Range">
      <?php $thisMonth = $range === 'month' && $view === 'month'
                         && $heading === date('F Y', strtotime($today)); ?>
      <a role="tab" aria-selected="<?= $thisMonth ? 'true' : 'false' ?>"
         class="<?= $thisMonth ? 'active' : '' ?>"
         href="<?= e(fabo_cal_url($viewer, ['cr' => '', 'cv' => 'month', 'on' => $today])) ?>">This month</a>
      <a role="tab" aria-selected="<?= $range === 'all' ? 'true' : 'false' ?>"
         class="<?= $range === 'all' ? 'active' : '' ?>"
         href="<?= e(fabo_cal_url($viewer, ['cr' => 'all'])) ?>">All time</a>
    </div>
    <div class="ec-tabs" role="tablist" aria-label="Calendar view">
      <?php foreach (fabo_cal_views() as $k => $label): ?>
        <a role="tab" aria-selected="<?= $view === $k ? 'true' : 'false' ?>"
           class="<?= $range !== 'all' && $view === $k ? 'active' : '' ?>"
           href="<?= e(fabo_cal_url($viewer, ['cv' => $k, 'cr' => ''])) ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </div>
    <?php if ($view !== 'agenda' && $range !== 'all'): ?>
      <div class="ec-nav">
        <a href="<?= e(fabo_cal_url($viewer, ['on' => $prev])) ?>" aria-label="Previous">&lsaquo;</a>
        <a href="<?= e(fabo_cal_url($viewer, ['on' => $today])) ?>">Today</a>
        <a href="<?= e(fabo_cal_url($viewer, ['on' => $next])) ?>" aria-label="Next">&rsaquo;</a>
      </div>
    <?php endif; ?>
    <h3 class="ec-heading"><?= e($heading) ?></h3>
    <span class="ec-count"><?= $count ?> <?= $count === 1 ? 'programme' : 'programmes' ?></span>
    <a class="btn btn-sm" href="<?= e(fabo_cal_export_url($viewer)) ?>"
       title="The programmes this period and these filters are showing">&#8595; Export</a>
    <a class="btn btn-sm" target="_blank" rel="noopener"
       href="<?= e(fabo_root_link($viewer, 'report.php', array_filter([
             'period' => $range === 'all' ? 'all' : ($f['period'] ?? ''), 'from' => $f['from'] ?? '',
             'to' => $f['to'] ?? '']))) ?>"
       title="Every event in this period, written up and ready to print">Report</a>
    <?php if ($mayEdit): ?>
      <button type="button" class="btn btn-primary btn-sm" id="ecNew">&#65291; New programme</button>
    <?php endif; ?>
  </div>
    <?php
}

/**
 * The export link: the same filters and period the page is using, so what
 * downloads is what is on screen.
 */
function fabo_cal_export_url(array $viewer): string
{
    $p = ['scope' => 'programmes'];
    foreach (['q', 'fbrand', 'ftype', 'fstatus', 'fsign', 'fowner',
              'period', 'from', 'to', 'client'] as $k) {
        if (($_GET[$k] ?? '') !== '') {
            $p[$k] = (string) $_GET[$k];
        }
    }
    if (($_GET['cr'] ?? '') === 'all') {
        $p['period'] = 'all';      // All time downloads all time
        unset($p['from'], $p['to']);
    }
    if (!$viewer['is_admin'] && !fabo_signed_in()) {
        $p['k'] = (string) $viewer['client']['token'];
    }
    // export.php sits at the portal root; an admin page is one level down.
    return ($viewer['is_admin'] ? '../' : '') . 'export.php?' . http_build_query($p);
}

/** Month: seven columns, whole weeks, other sheets' chips riding along. */
function fabo_cal_month(array $viewer, array $rows, string $from, string $to,
                        string $today, array $f): void
{
    // Programmes, laid on every day they run.
    $byDay = [];
    foreach ($rows as $r) {
        $s = (string) ($r['start_date'] ?? '');
        if ($s === '') { continue; }
        $e = (string) ($r['end_date'] ?? '') ?: $s;
        if ($e < $s) { $e = $s; }
        for ($d = max($s, $from); $d <= min($e, $to); $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $byDay[$d][] = fabo_cal_chip($viewer, $r);
        }
    }

    // The other dated sheets, unless a filter is narrowing to programmes.
    $other = [];
    if (!fabo_prog_filtered($f)) {
        foreach (fabo_calendar_items($viewer, substr($from, 0, 7)) as $d => $chips) {
            foreach ($chips as $c) {
                if ($c['src'] === 'event') { continue; }   // already drawn above
                $other[$d][] = '<span class="ec-chip ec-other src-' . e($c['src']) . '"'
                             . ' title="' . e($c['title']) . '">' . e($c['title']) . '</span>';
            }
        }
    }

    $t0       = strtotime($from);
    $padStart = (int) date('N', $t0) - 1;
    $days     = (int) date('t', $t0);
    $cells    = [];
    for ($i = $padStart; $i > 0; $i--) {
        $cells[] = [date('Y-m-d', strtotime("-{$i} day", $t0)), true];
    }
    for ($i = 0; $i < $days; $i++) {
        $cells[] = [date('Y-m-d', strtotime("+{$i} day", $t0)), false];
    }
    while (count($cells) % 7) {
        $n = count($cells) - $padStart;
        $cells[] = [date('Y-m-d', strtotime("+{$n} day", $t0)), true];
    }

    echo '<div class="ec-month">';
    foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $w) {
        echo '<div class="ec-dow">' . $w . '</div>';
    }
    foreach ($cells as [$d, $isOther]) {
        $chips = array_merge($byDay[$d] ?? [], $other[$d] ?? []);
        echo '<div class="ec-cell' . ($isOther ? ' other' : '') . ($d === $today ? ' today' : '') . '">'
           . '<span class="ec-date">' . (int) substr($d, 8, 2) . '</span>';
        foreach (array_slice($chips, 0, 3) as $c) {
            echo $c;
        }
        if (count($chips) > 3) {
            echo '<details class="ec-more"><summary>+' . (count($chips) - 3) . ' more</summary>';
            foreach (array_slice($chips, 3) as $c) { echo $c; }
            echo '</details>';
        }
        echo '</div>';
    }
    echo '</div>';

    if (!fabo_prog_filtered($f)) {
        echo '<div class="ec-legend">';
        foreach (fabo_calendar_sources() as $src => [$label, $key]) {
            if ($src === 'event' || fabo_permission($viewer, $key) === 'none') { continue; }
            echo '<span><i class="src-' . e($src) . '"></i>' . e($label) . '</span>';
        }
        echo '<span class="ec-legend-note">Programmes are the coloured chips; '
           . 'a filter hides the rest.</span></div>';
    }
}

/** Week: seven tall columns, everything on the day visible. */
function fabo_cal_week(array $viewer, array $rows, string $mon, string $today): void
{
    echo '<div class="ec-week">';
    for ($i = 0; $i < 7; $i++) {
        $d    = date('Y-m-d', strtotime($mon . " +{$i} days"));
        $day  = array_values(array_filter($rows, static function ($r) use ($d) {
            $s = (string) ($r['start_date'] ?? '');
            if ($s === '') { return false; }
            $e = (string) ($r['end_date'] ?? '') ?: $s;
            return $d >= $s && $d <= max($s, $e);
        }));
        echo '<div class="ec-weekcell' . ($d === $today ? ' today' : '') . '">'
           . '<div class="ec-weekhead"><strong>' . e(date('D', strtotime($d))) . '</strong>'
           . '<span>' . e(date('d M', strtotime($d))) . '</span></div>';
        if (!$day) {
            echo '<p class="ec-none">—</p>';
        }
        foreach ($day as $r) { echo fabo_cal_chip($viewer, $r); }
        echo '</div>';
    }
    echo '</div>';
}

/** Day: one column, as cards, because there is room to say more. */
function fabo_cal_day(array $viewer, array $rows, string $d, string $today): void
{
    $day = array_values(array_filter($rows, static function ($r) use ($d) {
        $s = (string) ($r['start_date'] ?? '');
        if ($s === '') { return false; }
        $e = (string) ($r['end_date'] ?? '') ?: $s;
        return $d >= $s && $d <= max($s, $e);
    }));
    if (!$day) {
        echo '<div class="ec-empty"><strong>Nothing on '
           . e(date('D d M Y', strtotime($d))) . '</strong>'
           . '<span>Use the arrows above, or Today to come back.</span></div>';
        return;
    }
    echo '<div class="ec-cards">';
    foreach ($day as $r) { echo fabo_cal_card($viewer, $r, $today); }
    echo '</div>';
}

/** Agenda: the period's programmes as a list, grouped by month. */
function fabo_cal_agenda(array $viewer, array $rows, string $today): void
{
    if (!$rows) {
        echo '<div class="ec-empty"><strong>No programmes in this period</strong>'
           . '<span>Widen the Period menu above, or clear the filters.</span></div>';
        return;
    }
    $month = '';
    echo '<div class="ec-cards">';
    foreach ($rows as $r) {
        $s   = (string) ($r['start_date'] ?? '');
        $m   = $s === '' ? 'No date yet' : date('F Y', strtotime($s));
        if ($m !== $month) {
            echo '<h4 class="ec-monthmark">' . e($m) . '</h4>';
            $month = $m;
        }
        echo fabo_cal_card($viewer, $r, $today);
    }
    echo '</div>';
}

/** One programme as a card, for the Day and Agenda views. */
function fabo_cal_card(array $viewer, array $r, string $today): string
{
    $name = (string) ($r['event_name'] ?? '') ?: 'Untitled programme';
    $s    = (string) ($r['start_date'] ?? '');
    $when = $s === '' ? 'No date yet' : date('D d M Y', strtotime($s));
    if (!empty($r['end_date']) && $r['end_date'] !== $s) {
        $when .= ' – ' . date('D d M Y', strtotime((string) $r['end_date']));
    }
    $bits = array_filter([
        (string) ($r['brand'] ?? ''),
        (string) ($r['event_type'] ?? ''),
        trim(((string) ($r['location'] ?? '')) . (($r['city'] ?? '') ? ', ' . $r['city'] : ''), ' ,'),
        ($r['owner'] ?? '') ? 'Owner: ' . $r['owner'] : '',
    ], static fn($v) => trim((string) $v) !== '');

    $late = $s !== '' && $s < $today && !fabo_is_closed($r['status'] ?? null);

    $chip = fabo_cal_chip($viewer, $r);          // carries the detail payload
    $open = str_replace('class="ec-chip', 'class="ec-cardhit ec-chip', $chip);

    return '<div class="ec-card tone-' . e(fabo_prog_tone($r)) . '">'
         . '<div class="ec-cardmain">'
         . '<strong>' . e($name) . '</strong>'
         . '<small>' . e($when) . ($late ? ' · <em>past, still open</em>' : '') . '</small>'
         . ($bits ? '<small>' . e(implode(' · ', $bits)) . '</small>' : '')
         . '</div>'
         . '<div class="ec-cardside">'
         . ($r['status'] ?? '' ? fabo_pill('status', $r['status']) : '')
         . ($r['approval_status'] ?? ''
             ? '<span class="ec-sign tone-' . e(fabo_prog_tone($r)) . '">'
               . e((string) $r['approval_status']) . '</span>' : '')
         . $open
         . '</div></div>';
}

/** The next programmes, whatever view is on screen. */
function fabo_cal_upcoming(array $viewer, array $rows, string $today): void
{
    $soon = array_values(array_filter($rows, static fn($r) =>
        !empty($r['start_date']) && $r['start_date'] >= $today));
    $soon = array_slice(fabo_prog_sort($soon), 0, 12);
    ?>
  <aside class="ec-side">
    <h3>Coming up</h3>
    <?php if (!$soon): ?>
      <p class="ec-none">Nothing ahead that matches these filters.</p>
    <?php else: ?>
      <ul class="ec-upcoming">
        <?php foreach ($soon as $r):
            $days = (int) floor((strtotime((string) $r['start_date']) - strtotime($today)) / 86400); ?>
          <li>
            <?= fabo_cal_chip($viewer, $r) ?>
            <small><?= e(date('d M', strtotime((string) $r['start_date']))) ?>
              · <?= $days === 0 ? 'today' : ($days === 1 ? 'tomorrow' : 'in ' . $days . ' days') ?>
              <?= ($r['brand'] ?? '') ? ' · ' . e((string) $r['brand']) : '' ?></small>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </aside>
    <?php
}

/**
 * The programme form: every field of a programme on one screen.
 *
 * The grid can edit any of these one cell at a time, and for a quick
 * correction that is the faster way. This is for the other case — writing a
 * programme down from scratch, where twenty cells across a very wide grid is
 * the wrong shape entirely.
 *
 * Only rendered for someone who may edit the sheet; there is no read-only
 * version, because the detail panel already is one.
 */
function fabo_cal_form(array $viewer, array $rows, string $api): void
{
    $L = static fn(string $list): array => fabo_list_values($list);

    /* [field, label, kind, options] — kind is text | long | date | int |
       money | dec | select. Grouped the way someone fills a programme in. */
    $groups = [
        'What it is' => [
            ['event_name', 'Programme name', 'text', []],
            ['event_type', 'Type', 'select', $L('Event Type')],
            ['brand', 'Brand', 'select', $L('Brand')],
            ['business_unit', 'Business unit', 'select', $L('Business Unit')],
            ['programme_details', 'Programme details', 'long', []],
        ],
        'When and where' => [
            ['start_date', 'Starts', 'date', []],
            ['end_date', 'Ends', 'date', []],
            ['duration_hours_expected', 'Duration planned (hrs)', 'dec', []],
            ['duration_hours', 'Duration actual (hrs)', 'dec', []],
            ['location', 'Location / store', 'text', []],
            ['city', 'City', 'select', $L('Geography')],
        ],
        'Who it is for' => [
            ['sector', 'Sector', 'select', $L('Sector')],
            ['target_audience', 'Target audience', 'select', $L('Target Audience')],
            ['partners', 'Partners', 'select', $L('Partner')],
            ['partners_count', 'How many partners', 'int', []],
            ['partner_details', 'Partner details', 'long', []],
        ],
        'What it should achieve' => [
            ['expected_attendees', 'Attendance planned', 'int', []],
            ['actual_attendees', 'Attendance actual', 'int', []],
            ['leads_expected', 'Leads planned', 'int', []],
            ['leads_generated', 'Leads actual', 'int', []],
            ['budget_allocated', 'Budget allocated', 'money', []],
            ['budget_spent', 'Budget spent', 'money', []],
            ['funding_source', 'Funding source', 'select', $L('Funding Source')],
        ],
        'Who is running it' => [
            ['owner', 'Owner', 'select', $L('Owner / Team')],
            ['spoc_name', 'SPOC name', 'text', []],
            ['spoc_contact', 'SPOC phone', 'text', []],
            ['spoc_email', 'SPOC email', 'text', []],
        ],
        'Where it stands' => [
            ['status', 'Status', 'select', $L('Status')],
            ['priority', 'Priority', 'select', $L('Priority')],
            // Sign-off is not here: the committee's decision sets it (lib/committees.php).
            ['creative_due_date', 'Creative due', 'date', []],
            ['linked_campaign_id', 'Linked campaign ID', 'text', []],
            ['local_resources', 'Local resources', 'long', []],
            ['case_studies', 'Case studies', 'long', []],
            ['remarks', 'Remarks', 'long', []],
        ],
    ];
    ?>
  <dialog class="ec-dialog ec-formdlg" id="ecForm">
    <form method="post" id="ecFormEl">
      <div class="ec-dhead">
        <div><span id="ecFsub">Programme</span><h2 id="ecFtitle">New programme</h2></div>
        <button type="button" class="ec-dx" data-ecf-close aria-label="Close">&times;</button>
      </div>
      <div class="ec-dbody ec-formbody">
        <input type="hidden" name="id" id="ecFid" value="0">
        <?php foreach ($groups as $legend => $fields): ?>
          <h4 class="ec-formgroup"><?= e($legend) ?></h4>
          <?php foreach ($fields as [$field, $label, $kind, $opts]):
              $wide = in_array($kind, ['long'], true); ?>
            <div class="ec-field<?= $wide ? ' wide' : '' ?>">
              <label for="ecf_<?= e($field) ?>"><?= e($label) ?></label>
              <?php if ($kind === 'long'): ?>
                <textarea id="ecf_<?= e($field) ?>" name="<?= e($field) ?>" rows="2"></textarea>
              <?php elseif ($kind === 'select'): ?>
                <select id="ecf_<?= e($field) ?>" name="<?= e($field) ?>">
                  <option value="">—</option>
                  <?php foreach ($opts as $o): ?>
                    <option value="<?= e($o) ?>"><?= e($o) ?></option>
                  <?php endforeach; ?>
                </select>
              <?php else: ?>
                <input id="ecf_<?= e($field) ?>" name="<?= e($field) ?>"
                       type="<?= $kind === 'date' ? 'date' : ($kind === 'text' ? 'text' : 'number') ?>"
                       <?= $kind === 'money' || $kind === 'dec' ? 'step="0.01"' : '' ?>
                       <?= $kind === 'int' ? 'step="1"' : '' ?>>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </div>
      <div class="ec-dfoot">
        <span class="ec-formmsg" id="ecFmsg"></span>
        <button type="button" class="btn btn-sm" data-ecf-close>Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm" id="ecFsave">Save programme</button>
      </div>
    </form>
  </dialog>
  <script>
  (function () {
    var dlg  = document.getElementById('ecForm');
    var form = document.getElementById('ecFormEl');
    if (!dlg || !form) { return; }
    var msg  = document.getElementById('ecFmsg');
    var API  = <?= json_encode($api) ?>;
    var CSRF = <?= json_encode(fabo_csrf_token()) ?>;
    var TOKEN = <?= json_encode(($viewer['is_admin'] || fabo_signed_in())
                                ? '' : (string) $viewer['client']['token']) ?>;

    function open(id, values, title) {
      document.getElementById('ecFid').value = id || 0;
      document.getElementById('ecFtitle').textContent = title;
      msg.textContent = '';
      msg.className = 'ec-formmsg';
      /* Clear first: the form is reused, and a field the new programme does
         not set must not keep the last one's value. */
      Array.prototype.forEach.call(form.querySelectorAll('input,select,textarea'), function (el) {
        if (el.name && el.name !== 'id') { el.value = ''; }
      });
      Object.keys(values || {}).forEach(function (k) {
        var el = form.querySelector('[name="' + k + '"]');
        if (el) { el.value = values[k] == null ? '' : values[k]; }
      });
      if (typeof dlg.showModal === 'function') { dlg.showModal(); } else { dlg.open = true; }
      var first = form.querySelector('[name="event_name"]');
      if (first) { first.focus(); }
    }
    function close() {
      if (typeof dlg.close === 'function') { dlg.close(); } else { dlg.open = false; }
    }
    window.ecOpenForm = open;

    document.addEventListener('click', function (ev) {
      if (!ev.target.closest) { return; }
      if (ev.target.closest('[data-ecf-close]')) { ev.preventDefault(); close(); return; }
      if (ev.target.closest('#ecNew')) { ev.preventDefault(); open(0, {}, 'New programme'); }
    });
    dlg.addEventListener('click', function (ev) { if (ev.target === dlg) { close(); } });

    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var body = new URLSearchParams(new FormData(form));
      body.set('csrf', CSRF);
      body.set('sheet', 'events');
      if (TOKEN) { body.set('k', TOKEN); }
      var client = document.getElementById('clientpick');
      if (client && client.value) { body.set('client', client.value); }

      var save = document.getElementById('ecFsave');
      save.disabled = true;
      msg.className = 'ec-formmsg';
      msg.textContent = 'Saving…';

      fetch(API + 'programme.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded',
                   'X-Requested-With': 'fetch', 'Accept': 'application/json' },
        body: body.toString()
      }).then(function (r) { return r.json(); }).then(function (res) {
        save.disabled = false;
        if (!res.ok) {
          msg.className = 'ec-formmsg bad';
          msg.textContent = res.error || 'That did not save.';
          return;
        }
        /* Reload so the calendar, the figures and the grid all show it —
           they are all built server-side from the same rows. */
        location.reload();
      }).catch(function () {
        save.disabled = false;
        msg.className = 'ec-formmsg bad';
        msg.textContent = 'Could not reach the server.';
      });
    });
  })();
  </script>
    <?php
}

/** The detail panel. One dialog, filled from whichever chip was pressed. */
function fabo_cal_dialog(): void
{
    ?>
  <dialog class="ec-dialog" id="ecDialog">
    <div class="ec-dhead">
      <div><span id="ecDsub"></span><h2 id="ecDtitle"></h2></div>
      <button type="button" class="ec-dx" data-ec-close aria-label="Close">&times;</button>
    </div>
    <div class="ec-dbody" id="ecDbody"></div>
    <div class="ec-dfoot">
      <a class="btn btn-sm" id="ecDopen" href="#">Open the row</a>
      <button type="button" class="btn btn-sm" id="ecDedit" hidden>Edit programme</button>
      <button type="button" class="btn btn-primary btn-sm" data-ec-close>Close</button>
    </div>
  </dialog>
  <script>
  (function () {
    var dlg = document.getElementById('ecDialog');
    if (!dlg) { return; }
    var body = document.getElementById('ecDbody');

    function esc(s) {
      return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
      });
    }

    document.addEventListener('click', function (ev) {
      var chip = ev.target.closest ? ev.target.closest('[data-prog]') : null;
      if (chip) {
        var d;
        try { d = JSON.parse(chip.getAttribute('data-prog')); } catch (e) { return; }
        document.getElementById('ecDtitle').textContent = d.title || '';
        document.getElementById('ecDsub').textContent = d.sub || '';
        document.getElementById('ecDopen').href = d.href || '#';
        var wide = d.wide || [], h = '';
        Object.keys(d.rows || {}).forEach(function (k) {
          h += '<div class="ec-detail' + (wide.indexOf(k) >= 0 ? ' wide' : '') + '">' +
               '<span>' + esc(k) + '</span><strong>' + esc(d.rows[k]) + '</strong></div>';
        });
        body.innerHTML = h || '<p class="ec-none">Nothing filled in yet.</p>';
        /* Edit is offered only where the form was rendered, which is only
           for someone who may edit the sheet. */
        var edit = document.getElementById('ecDedit');
        if (edit) {
          edit.hidden = typeof window.ecOpenForm !== 'function' || !d.edit;
          edit.onclick = function () {
            if (typeof dlg.close === 'function') { dlg.close(); } else { dlg.open = false; }
            window.ecOpenForm(d.id, d.edit, 'Edit ' + (d.title || 'programme'));
          };
        }
        /* showModal needs the dialog in the top layer; older Safari has only
           the open attribute, and the panel still reads correctly there. */
        if (typeof dlg.showModal === 'function') { dlg.showModal(); } else { dlg.open = true; }
        return;
      }
      if (ev.target.closest && ev.target.closest('[data-ec-close]')) {
        if (typeof dlg.close === 'function') { dlg.close(); } else { dlg.open = false; }
      }
    });

    /* A click on the backdrop lands on the dialog itself, not its contents. */
    dlg.addEventListener('click', function (ev) {
      if (ev.target === dlg) {
        if (typeof dlg.close === 'function') { dlg.close(); } else { dlg.open = false; }
      }
    });

    /* The From / To boxes only mean anything for a custom range. */
    var period = document.getElementById('ecPeriod');
    if (period) {
      period.addEventListener('change', function () {
        var custom = period.value === 'custom';
        Array.prototype.forEach.call(document.querySelectorAll('.ec-when'), function (el) {
          el.hidden = !custom;
        });
      });
    }
  })();
  </script>
    <?php
}

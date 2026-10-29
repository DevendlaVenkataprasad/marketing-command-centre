<?php
/**
 * Event Management, in four rooms rather than one long page.
 *
 * Everything about events used to be stacked down a single scroll: the grid,
 * then the dashboard, then the calendar, then the departments. Each is a
 * different job — reviewing figures, planning dates, entering data, handing
 * work out — and stacking them meant every job began by scrolling past the
 * other three.
 *
 * So four views, one at a time, and one set of filters that applies to all of
 * them. The filtering happens here, once, before any view is drawn, which is
 * what makes "Q3, Amaravati Hub, workshops only" mean the same thing on the
 * calendar as it does in the figures.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sheets.php';

/**
 * The four rooms, in the order they are offered. A committee member is not
 * offered Department Assigning: handing units their sheets is not theirs.
 */
function fabo_event_views(?array $viewer = null): array
{
    $views = [
        'dashboard'   => ['Dashboard',           'The figures, as they stand'],
        'calendar'    => ['Calendar',            'What is on, and when'],
        'programs'    => ['Programs Data',       'Every row, to read and edit'],
        /* The same rows as Programs Data, cut by whether the event has
           happened, each with only the columns that question needs. See
           lib/eventstages.php. */
        'upcoming'    => ['Upcoming Events',     'Still to come: planning, budget and approvals'],
        'completed'   => ['Completed Events',    'Finished: the plan for reference, and the results to record'],
        'departments' => ['Department Assigning', 'Hand a unit its own sheet to fill in'],
    ];
    require_once __DIR__ . '/committees.php';
    if (fabo_cm_only($viewer)) {
        unset($views['departments']);
    }
    return $views;
}

/** Which one is being looked at. */
function fabo_event_view(?array $viewer = null): string
{
    $v = strtolower(trim((string) ($_GET['view'] ?? '')));
    /* A link to one row (?row=) that names no view means "show me that row":
       Programs Data, where every row is, rather than the Dashboard, which has
       none to scroll to. */
    if ($v === '' && (int) ($_GET['row'] ?? 0) > 0) {
        return 'programs';
    }
    return isset(fabo_event_views($viewer)[$v]) ? $v : 'dashboard';
}

/**
 * The filters offered, and where each one reads from.
 *
 * Every filter is a column on the sheet, so what is offered is whatever the
 * rows actually contain rather than a list somebody has to keep in step.
 * A filter with one value or none is not shown: it can only ever be a no-op.
 */
function fabo_event_filters(): array
{
    return [
        'brand'           => 'Brand',
        'business_unit'   => 'Business Unit',
        'event_type'      => 'Event Type',
        'sector'          => 'Sector',
        'target_audience' => 'Audience',
        'city'            => 'District',
        'owner'           => 'Owner',
        'status'          => 'Status',
        'priority'        => 'Priority',
    ];
}

/** What is actually in the rows for each filter, sorted, without the blanks. */
function fabo_event_filter_values(array $rows): array
{
    $out = [];
    foreach (array_keys(fabo_event_filters()) as $f) {
        $seen = [];
        foreach ($rows as $r) {
            $v = trim((string) ($r[$f] ?? ''));
            if ($v !== '') {
                $seen[$v] = true;
            }
        }
        $vals = array_keys($seen);
        sort($vals, SORT_NATURAL | SORT_FLAG_CASE);
        if (count($vals) > 1) {          // one value filters nothing
            $out[$f] = $vals;
        }
    }
    return $out;
}

/** What the person has actually chosen, from the query string. */
function fabo_event_chosen(): array
{
    $on = [];
    foreach (array_keys(fabo_event_filters()) as $f) {
        $v = trim((string) ($_GET['f_' . $f] ?? ''));
        if ($v !== '') {
            $on[$f] = $v;
        }
    }
    foreach (['from', 'to', 'q'] as $k) {
        $v = trim((string) ($_GET[$k] ?? ''));
        if ($v !== '') {
            $on[$k] = $v;
        }
    }
    return $on;
}

/**
 * The rows that survive the filters.
 *
 * Dates are compared on the event's span rather than its start, so an event
 * that runs from September into October appears when either month is asked
 * for. Filtering on the start alone hides exactly the long programmes people
 * are most often looking for.
 */
function fabo_event_filter_rows(array $rows, array $on): array
{
    if (!$on) {
        return $rows;
    }
    $from = isset($on['from']) ? (strtotime($on['from']) ?: 0) : 0;
    $to   = isset($on['to'])   ? (strtotime($on['to'])   ?: 0) : 0;
    $q    = isset($on['q'])    ? mb_strtolower($on['q'])       : '';

    return array_values(array_filter($rows, static function (array $r) use ($on, $from, $to, $q) {
        foreach (fabo_event_filters() as $f => $_) {
            if (isset($on[$f]) && trim((string) ($r[$f] ?? '')) !== $on[$f]) {
                return false;
            }
        }
        if ($from || $to) {
            $s = strtotime((string) ($r['start_date'] ?? '')) ?: 0;
            $e = strtotime((string) ($r['end_date'] ?? '')) ?: $s;
            if (!$s) {
                return false;            // undated rows cannot be in a window
            }
            if ($from && $e && $e < $from) {
                return false;
            }
            if ($to && $s > $to) {
                return false;
            }
        }
        if ($q !== '') {
            $hay = mb_strtolower(implode(' ', [
                (string) ($r['event_name'] ?? ''), (string) ($r['event_id'] ?? ''),
                (string) ($r['location'] ?? ''),   (string) ($r['programme_details'] ?? ''),
                (string) ($r['remarks'] ?? ''),    (string) ($r['partner_details'] ?? ''),
            ]));
            if (!str_contains($hay, $q)) {
                return false;
            }
        }
        return true;
    }));
}

/** This page's address, with one thing changed. */
function fabo_event_url(array $viewer, array $change = []): string
{
    $q = [];
    foreach ($_GET as $k => $v) {
        if (is_string($v) && $v !== '' && $k !== 'k') {
            $q[$k] = $v;
        }
    }
    foreach ($change as $k => $v) {
        if ($v === null || $v === '') {
            unset($q[$k]);
        } else {
            $q[$k] = $v;
        }
    }
    $q['sheet'] = 'events';
    return fabo_link($viewer, $viewer['is_admin'] ? 'sheet.php' : 'c.php', $q);
}

/** The tabs. */
function fabo_event_tabs(array $viewer, string $current, array $counts): void
{
    ?>
    <nav class="evtabs" aria-label="Event Management">
      <?php foreach (fabo_event_views($viewer) as $key => [$label, $hint]): ?>
        <a class="evtab<?= $key === $current ? ' on' : '' ?>"
           href="<?= e(fabo_event_url($viewer, ['view' => $key])) ?>"
           title="<?= e($hint) ?>">
          <?= e($label) ?>
          <?php if (isset($counts[$key])): ?>
            <span class="evtab-n"><?= (int) $counts[$key] ?></span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <?php
}

/**
 * The filter bar.
 *
 * A plain GET form: the filters end up in the address, so a filtered view can
 * be sent to somebody, bookmarked, or reloaded without losing where you were.
 */
function fabo_event_filterbar(array $viewer, array $rows, array $on, int $shown, int $total): void
{
    $values = fabo_event_filter_values($rows);
    $labels = fabo_event_filters();
    $page   = $viewer['is_admin'] ? 'sheet.php' : 'c.php';
    ?>
    <form class="evfilters" method="get" action="<?= e($page) ?>">
      <input type="hidden" name="sheet" value="events">
      <input type="hidden" name="view" value="<?= e(fabo_event_view($viewer)) ?>">
      <?php if (!$viewer['is_admin'] && !fabo_signed_in()): ?>
        <input type="hidden" name="k" value="<?= e((string) $viewer['client']['token']) ?>">
      <?php endif; ?>
      <?php if (!empty($_GET['client'])): ?>
        <input type="hidden" name="client" value="<?= (int) $_GET['client'] ?>">
      <?php endif; ?>

      <input class="evsearch" type="search" name="q" placeholder="Search name, place, details&hellip;"
             value="<?= e((string) ($on['q'] ?? '')) ?>">

      <?php foreach ($values as $f => $vals): ?>
        <select name="f_<?= e($f) ?>" aria-label="<?= e($labels[$f]) ?>">
          <option value=""><?= e($labels[$f]) ?>: any</option>
          <?php foreach ($vals as $v): ?>
            <option value="<?= e($v) ?>"<?= ($on[$f] ?? '') === $v ? ' selected' : '' ?>>
              <?= e(mb_strimwidth($v, 0, 34, '…')) ?></option>
          <?php endforeach; ?>
        </select>
      <?php endforeach; ?>

      <label class="evdate">From <input type="date" name="from"
             value="<?= e((string) ($on['from'] ?? '')) ?>"></label>
      <label class="evdate">To <input type="date" name="to"
             value="<?= e((string) ($on['to'] ?? '')) ?>"></label>

      <button class="btn btn-sm btn-primary" type="submit">Apply</button>
      <?php if ($on): ?>
        <a class="btn btn-sm" href="<?= e(fabo_event_url($viewer, array_merge(
              array_fill_keys(array_map(static fn($f) => 'f_' . $f, array_keys($labels)), null),
              ['from' => null, 'to' => null, 'q' => null]))) ?>">Clear</a>
      <?php endif; ?>

      <span class="evcount">
        <?php if ($shown === $total): ?>
          <?= (int) $total ?> event<?= $total === 1 ? '' : 's' ?>
        <?php else: ?>
          <strong><?= (int) $shown ?></strong> of <?= (int) $total ?>
        <?php endif; ?>
      </span>
    </form>
    <?php
}

/** A line saying nothing matched, rather than an empty room. */
function fabo_event_nothing(array $viewer, string $what): void
{
    ?>
    <div class="evempty">
      <strong>Nothing matches those filters.</strong>
      <div class="hint">There are no <?= e($what) ?> for the brand, unit, dates or
        search you have chosen. Widen them, or
        <a href="<?= e(fabo_event_url($viewer, array_merge(
             array_fill_keys(array_map(static fn($f) => 'f_' . $f,
                             array_keys(fabo_event_filters())), null),
             ['from' => null, 'to' => null, 'q' => null]))) ?>">clear them all</a>.</div>
    </div>
    <?php
}

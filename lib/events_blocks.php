<?php
/**
 * Events: the sources behind the Programme Calendar, and the table beside it.
 *
 * fabo_calendar_sources() and fabo_calendar_items() gather every dated
 * marketing moment — events, festivals, content going live, campaigns
 * starting, design and creative due dates — so the calendar can show the
 * whole load of a month rather than one sheet's slice. The calendar itself
 * lives in lib/eventcal.php and reads from here.
 */

require_once __DIR__ . '/grid.php';
require_once __DIR__ . '/rules.php';

/** Every calendar source: [label, css class, sheet key, date field(s), title field]. */
function fabo_calendar_sources(): array
{
    return [
        'event'    => ['Events',                 'events',    ['start_date', 'end_date'], 'event_name'],
        'creative' => ['Creative assets due',    'events',    ['creative_due_date'],      'event_name'],
        'festival' => ['Festivals & key dates',  'calendar',  ['occasion_date'],          'occasion'],
        'content'  => ['Content going live',     'content',   ['planned_publish_date'],   'title_topic'],
        'campaign' => ['Campaigns starting',     'campaigns', ['start_date'],             'campaign_name'],
        'design'   => ['Design due',             'design',    ['due_date'],               'project_title'],
    ];
}

/**
 * Items for one month, keyed by 'Y-m-d'. Multi-day events appear on every day
 * they span. Respects the viewer's scope, since fabo_rows() does.
 */
function fabo_calendar_items(array $viewer, string $ym): array
{
    $first = $ym . '-01';
    $last  = date('Y-m-t', strtotime($first));
    $items = [];

    foreach (fabo_calendar_sources() as $src => [$label, $key, $dates, $titleF]) {
        if (fabo_permission($viewer, $key) === 'none') {
            continue;   // a client never sees a sheet they were not given
        }
        // The admin's client picker narrows these too, as it does the events.
        foreach (fabo_rows($key, $viewer, ['client' => (int) ($_GET['client'] ?? 0)]) as $r) {
            $start = $r[$dates[0]] ?? null;
            $end   = isset($dates[1]) ? ($r[$dates[1]] ?? null) : null;
            if (!$start) {
                continue;
            }
            $end = $end ?: $start;
            if ($end < $first || $start > $last) {
                continue;
            }
            $title = (string) ($r[$titleF] ?? '');
            $brand = (string) ($r['brand'] ?? '');
            $chip  = [
                'src'   => $src,
                'title' => $title,
                'brand' => $brand,
                'key'   => $key,
                'id'    => (int) $r['id'],
                'tone'  => fabo_tone('status', $r['status'] ?? null),
                'sub'   => $src === 'creative' ? 'creative due' : ($src === 'festival' ? ($r['category'] ?? '') : ($r['status'] ?? '')),
            ];
            for ($d = max($start, $first); $d <= min($end, $last); $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
                $items[$d][] = $chip;
            }
        }
    }
    return $items;
}

/**
 * The events still to come, in the detail a table can carry and a calendar
 * chip cannot: how many days are left, whether the creative is late, what
 * the budget was.
 *
 * The month grid that used to live here is now the Programme Calendar above
 * (lib/eventcal.php), which does the same job with four views and a filter
 * bar. This is what was worth keeping beside it, not a second grid.
 */
function fabo_creative_calendar_blocks(array $viewer, array $rows): void
{
    $today = fabo_today();
    // Upcoming events list — the next 60 days, by brand.
    $soon = array_values(array_filter($rows, static function ($r) use ($today) {
        return !empty($r['start_date']) && $r['start_date'] >= $today
            && $r['start_date'] <= date('Y-m-d', strtotime($today . ' +60 days'));
    }));
    usort($soon, static fn($a, $b) => strcmp($a['start_date'], $b['start_date']));
    ?>
  <div class="block">
    <h2>Next 60 days</h2>
    <?php if (!$soon): ?>
      <div class="empty">No events in the next 60 days.</div>
    <?php else: ?>
    <div class="scrollx"><table class="data">
      <thead><tr><th>Event</th><th>Brand</th><th>Type</th><th>Starts</th><th class="num">Days to go</th>
                 <th>Owner</th><th>Status</th><th>Creative due</th><th class="num">Budget</th></tr></thead>
      <tbody>
      <?php foreach ($soon as $r):
          $days = (int) $r['days_to_event'];
          $creativeLate = !empty($r['creative_due_date']) && $r['creative_due_date'] < $today
                          && !fabo_is_closed($r['status'] ?? null);
      ?>
        <tr>
          <td><strong><?= e($r['event_name']) ?></strong><br><span style="font-size:12px;color:var(--ink-soft)"><?= e($r['location']) ?></span></td>
          <td><?= e($r['brand']) ?></td>
          <td><?= e($r['event_type']) ?></td>
          <td><?= e(date('d M', strtotime($r['start_date']))) ?><?= $r['end_date'] && $r['end_date'] !== $r['start_date'] ? ' – ' . e(date('d M', strtotime($r['end_date']))) : '' ?></td>
          <td class="num <?= e(fabo_cell_tone('days_to_event', $days)) ?>"><?= $days ?></td>
          <td><?= e($r['owner']) ?></td>
          <td><?= fabo_pill('status', $r['status']) ?></td>
          <td><?= $r['creative_due_date'] ? e(date('d M', strtotime($r['creative_due_date']))) : '—' ?>
              <?= $creativeLate ? ' <span class="pill tone-risk"><i class="dot"></i>late</span>' : '' ?></td>
          <td class="num"><?= e(fabo_money($r['budget_allocated'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>
<?php
}

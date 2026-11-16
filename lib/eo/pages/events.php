<?php
/** Event Organizers — the approved events. They arrive from the Events sheet; nothing is added here. */

eo_need_read();
$when = in_array($_GET['when'] ?? '', ['past', 'all', 'withdrawn'], true) ? (string) $_GET['when'] : 'upcoming';
$q = trim((string) ($_GET['q'] ?? ''));
$pg = max(1, (int) ($_GET['pg'] ?? 1));

// Opening the list always shows the latest decisions, however recent.
try {
    eo_sync();
} catch (Throwable $e) {
    error_log('FABO organizers sync: ' . $e->getMessage());
}
[$rows, $total] = eo_events_list($when, $q, $pg);

inc_layout_start('Approved events', number_format($total) . ' event' . ($total === 1 ? '' : 's') . ' · new ones arrive when a committee approves them on the Events sheet');

echo '<nav class="inc-apptabs" aria-label="Events">';
foreach (['upcoming' => 'Upcoming', 'past' => 'Past', 'all' => 'All', 'withdrawn' => 'No longer approved'] as $k => $label) {
    echo '<a href="' . e(inc_url('events', ['when' => $k] + ($q !== '' ? ['q' => $q] : []))) . '"' . ($k === $when ? ' class="on" aria-current="page"' : '') . '>' . e($label) . '</a>';
}
echo '</nav>';
echo '<form method="get" class="inc-row eo-search"><input type="hidden" name="p" value="events"><input type="hidden" name="when" value="' . e($when) . '">'
   . '<input type="search" name="q" value="' . e($q) . '" placeholder="Search name, place or type" aria-label="Search events" class="grow">'
   . '<button class="btn">Search</button></form>';

if (!$rows) {
    echo inc_empty($q !== '' ? 'No events match.' : 'No events here.', $q !== '' ? 'Try another search.' : 'Approved events appear here by themselves.');
} else {
    echo '<section class="card"><table class="data"><thead><tr><th>Event</th><th>Dates</th><th>Place</th><th>Public page</th>'
       . '<th class="num">Forms</th><th class="num">Applications</th><th class="num">Tickets</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $state = (int) $r['approved'] !== 1 ? '<span class="pill red">Not approved</span>'
               : ((int) $r['published'] === 1 ? '<span class="pill green">Live</span>' : '<span class="pill grey">Not public</span>');
        echo '<tr><td data-l="Event"><a href="' . e(inc_url('event', ['id' => (int) $r['id']])) . '">' . e($r['name']) . '</a>'
           . ($r['event_type'] ? '<div class="muted small">' . e($r['event_type']) . '</div>' : '') . '</td>'
           . '<td data-l="Dates" class="nowrap">' . e(eo_dates_text($r)) . '</td>'
           . '<td data-l="Place">' . e((string) ($r['venue'] ?: $r['location'])) . '</td>'
           . '<td data-l="Public page">' . $state . '</td>'
           . '<td data-l="Forms" class="num">' . number_format((int) $r['forms']) . '</td>'
           . '<td data-l="Applications" class="num">' . number_format((int) $r['apps']) . '</td>'
           . '<td data-l="Tickets" class="num">' . number_format((int) $r['tickets']) . '</td></tr>';
    }
    echo '</tbody></table></section>';
    echo inc_pager($total, 25, $pg, ['when' => $when] + ($q !== '' ? ['q' => $q] : []), 'events');
}
inc_layout_end();

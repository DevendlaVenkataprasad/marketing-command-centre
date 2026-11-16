<?php
/** Event Organizers — dashboard. Every figure is counted from saved records. */

if (!eo_may_read()) {
    inc_redirect(inc_url('desk'));
}
$d = eo_dashboard_figures();
[, , $net, , $pending] = eo_revenue();
inc_layout_start('Event Organizers', 'Events approved by committee, their applications, tickets and check-in', [], '<a class="btn btn-primary" href="' . e(inc_url('events')) . '">Approved events</a>');

if ($d['approved'] === 0) {
    echo '<div class="inc-note"><strong>No approved events yet.</strong> An event appears here by itself as soon as its committee approves it on the Events sheet.</div>';
} elseif ($d['no_form'] > 0 && inc_can('configure')) {
    echo '<div class="inc-note">' . number_format($d['no_form']) . ' upcoming event' . ($d['no_form'] === 1 ? ' has' : 's have') . ' no application form yet. Open an event to create one.</div>';
}
if ($pending > 0) {
    echo '<div class="inc-warn">' . number_format($pending) . ' order' . ($pending === 1 ? ' is' : 's are') . ' waiting for payment to be confirmed. <a href="' . e(inc_url('orders', ['status' => 'pending'])) . '">Review them</a>.</div>';
}
echo '<div class="eo-tiles">' . inc_tile('Approved events', number_format($d['approved']), inc_url('events', ['when' => 'all'])) . inc_tile('Upcoming', number_format($d['upcoming']), inc_url('events'))
   . inc_tile('Public pages', number_format($d['published'])) . (inc_can('view') ? inc_tile('Applications', number_format($d['apps']), inc_url('applications')) : inc_tile('Applications', number_format($d['apps'])))
   . inc_tile('Tickets issued', number_format($d['tickets'])) . inc_tile('Checked in', number_format($d['checked'])) . inc_tile('Revenue', eo_money($net, 'INR')) . '</div>';

[$next] = eo_events_list('upcoming', '', 1, 8);
echo '<div class="inc-grid2 wide-left"><section class="card"><h2>Coming up</h2>';
if (!$next) {
    echo inc_empty('Nothing coming up.', 'Upcoming approved events will be listed here.');
} else {
    echo '<table class="data"><thead><tr><th>Event</th><th>Dates</th><th class="num">Applications</th><th class="num">Tickets</th></tr></thead><tbody>';
    foreach ($next as $r) {
        echo '<tr><td data-l="Event"><a href="' . e(inc_url('event', ['id' => (int) $r['id']])) . '">' . e($r['name']) . '</a></td><td data-l="Dates" class="nowrap">' . e(eo_dates_text($r)) . '</td>'
           . '<td data-l="Applications" class="num">' . number_format((int) $r['apps']) . '</td><td data-l="Tickets" class="num">' . number_format((int) $r['tickets']) . '</td></tr>';
    }
    echo '</tbody></table>';
}
echo '</section><section class="card"><h2>Event applications by stage</h2>' . inc_bars(eo_stage_counts(), 'No applications yet.') . '</section></div>';
inc_layout_end();

<?php
/** Event Organizers — how the events are doing, all together. Per-event figures are on each event's Insights. */

eo_need_read();
$n = static fn(string $sql) => (int) (inc_one($sql)['n'] ?? 0);
$rate = $n('SELECT COUNT(DISTINCT k.id) AS n FROM inc_ev_tickets k WHERE k.status = "issued"');
$came = $n('SELECT COUNT(DISTINCT c.ticket_id) AS n FROM inc_ev_checkins c');
$types = [];
foreach (inc_all('SELECT COALESCE(m.name, "Not given") AS k, COUNT(*) AS n FROM inc_applications a LEFT JOIN inc_masters m ON m.id = a.applicant_type_id WHERE a.form_id IN (SELECT form_id FROM inc_ev_forms) GROUP BY k ORDER BY n DESC') as $r) {
    $types[(string) $r['k']] = (int) $r['n'];
}
$programs = [];
foreach (inc_all('SELECT a.event_name AS k, COUNT(*) AS n FROM inc_applications a WHERE a.form_id IN (SELECT form_id FROM inc_ev_forms) GROUP BY k ORDER BY n DESC LIMIT 12') as $r) {
    $programs[(string) $r['k']] = (int) $r['n'];
}
$months = [];
foreach (inc_all('SELECT DATE_FORMAT(a.submitted_at, "%b %Y") AS k, COUNT(*) AS n, MIN(a.submitted_at) AS s FROM inc_applications a WHERE a.form_id IN (SELECT form_id FROM inc_ev_forms) GROUP BY k ORDER BY s DESC LIMIT 12') as $r) {
    $months[(string) $r['k']] = (int) $r['n'];
}
$feedback = inc_one('SELECT AVG(rating) AS a, COUNT(*) AS n FROM inc_ev_feedback');
inc_layout_start('Analytics', 'Across all approved events');
echo '<div class="eo-tiles">' . inc_tile('Tickets issued', number_format($rate)) . inc_tile('Came', number_format($came)) . inc_tile('Show-up rate', $rate ? round($came * 100 / $rate) . '%' : '—')
   . inc_tile('Average rating', (int) $feedback['n'] ? number_format((float) $feedback['a'], 1) . ' / 5' : '—') . '</div>';
echo '<div class="inc-grid2"><section class="card"><h2>Applications by program</h2>' . inc_bars($programs, 'None yet.') . '</section><section class="card"><h2>Types of participant</h2>' . inc_bars($types, 'None yet.') . '</section></div>'
   . '<section class="card"><h2>Applications by month</h2>' . inc_bars(array_reverse($months, true), 'None yet.') . '</section>';
inc_layout_end();

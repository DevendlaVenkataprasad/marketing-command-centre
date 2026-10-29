<?php
/** IT Management — dashboard. Every figure is counted from saved records. */

$one = static fn(string $sql, string $t = '', array $p = []) => (int) (it_one($sql, $t, $p)['n'] ?? 0);
$days = (int) it_setting('remind_days');
$open = $one("SELECT COUNT(*) n FROM it_tickets WHERE status NOT IN ('resolved','closed')");
$overdue = $one("SELECT COUNT(*) n FROM it_tickets WHERE status NOT IN ('resolved','closed') AND due_at < NOW()");
$unassigned = $one("SELECT COUNT(*) n FROM it_tickets WHERE status NOT IN ('resolved','closed') AND assignee_id IS NULL");
$waiting = $one("SELECT COUNT(*) n FROM it_tickets WHERE status = 'waiting'");
$resolved30 = it_one("SELECT COUNT(*) n, AVG(TIMESTAMPDIFF(MINUTE, created_at, resolved_at)) m FROM it_tickets WHERE resolved_at >= NOW() - INTERVAL 30 DAY");
$inUse = $one("SELECT COUNT(*) n FROM it_assets WHERE status = 'assigned'");
$inStock = $one("SELECT COUNT(*) n FROM it_assets WHERE status = 'in_stock'");
$repair = $one("SELECT COUNT(*) n FROM it_assets WHERE status = 'repair'");
$warranty = $one("SELECT COUNT(*) n FROM it_assets WHERE status NOT IN ('retired','lost') AND warranty_end IS NOT NULL AND warranty_end <= CURDATE() + INTERVAL ? DAY", 'i', [$days]);
$due = it_all("SELECT * FROM it_renewals WHERE status = 'active' AND renews_on IS NOT NULL AND renews_on <= CURDATE() + INTERVAL ? DAY ORDER BY renews_on", 'i', [$days]);
$monthly = 0.0;
foreach (it_all("SELECT cost, billing FROM it_renewals WHERE status = 'active' AND cost IS NOT NULL") as $r) {
    $monthly += it_monthly_cost($r);
}

$acts = '<a class="btn" href="' . e(it_url('tickets')) . it_w('">Ticket board</a> <a class="btn btn-primary" href="') . e(it_url('tickets', ['new' => 1])) . it_w('">+ New ticket</a>');
it_layout_start(it_w('IT Management'), it_w('Helpdesk, devices, renewals and vendors in one place.'), $acts);

$avg = $resolved30['m'] !== null ? (float) $resolved30['m'] : null;
echo '<div class="inc-kpis">'
   . inc_kpi(it_w('Open tickets'), $open, it_url('tickets'), $unassigned . ' unassigned · ' . $waiting . ' waiting on requester', $open ? 'amber' : '')
   . inc_kpi('Overdue', $overdue, it_url('tickets', ['view' => 'list', 'overdue' => 1]), 'Past their target time', $overdue ? 'amber' : 'green')
   . inc_kpi('Resolved (30 days)', (int) $resolved30['n'], it_url('tickets', ['view' => 'list', 'status' => 'resolved']),
             $avg !== null ? 'Average ' . ($avg < 120 ? round($avg) . ' min' : round($avg / 60, 1) . ' h') . ' to resolve' : 'Nothing resolved yet', 'green')
   . inc_kpi(it_w('Devices in use'), $inUse, it_url('assets', ['status' => 'assigned']), $inStock . ' in stock · ' . $repair . ' in repair')
   . '</div>';

$todo = array_filter([
    [$overdue, it_w('tickets past their target time'), it_url('tickets', ['view' => 'list', 'overdue' => 1])],
    [$unassigned, it_w('open tickets with nobody assigned'), it_url('tickets', ['view' => 'list', 'assignee' => 'none'])],
    [count($due), it_w('renewals due in the next ') . $days . ' days', it_url('renewals', ['due' => 1])],
    [$warranty, 'warranties ending in the next ' . $days . ' days', it_url('assets', ['warranty' => 1])],
    [$repair, it_w('devices in repair'), it_url('assets', ['status' => 'repair'])],
], static fn($r) => $r[0] > 0);

/* Admin & Facilities: today on the premises — people on duty, housekeeping, stock. */
if (it_desk() === 'fa') {
    $active = $one('SELECT COUNT(*) n FROM it_people WHERE active = 1');
    $present = $one("SELECT COUNT(*) n FROM it_duty WHERE day = CURDATE() AND status IN ('present','replaced')");
    $unmarked = $one('SELECT COUNT(*) n FROM it_people p WHERE p.active = 1 AND NOT EXISTS (SELECT 1 FROM it_duty d WHERE d.person_id = p.id AND d.day = CURDATE())');
    $tasks = it_all('SELECT t.id, l.done_at FROM it_tasks t LEFT JOIN it_task_log l ON l.task_id = t.id AND l.day = CURDATE()
                     WHERE t.active = 1 AND (t.frequency = "daily" OR (t.frequency = "weekly" AND t.weekday = ?) OR (t.frequency = "monthly" AND t.monthday = ?))', 'ii', [(int) date('N'), (int) date('j')]);
    $tasksDone = count(array_filter($tasks, static fn($t) => $t['done_at']));
    $lowStock = $one('SELECT COUNT(*) n FROM it_stock WHERE active = 1 AND qty <= reorder_level');
    echo '<div class="inc-kpis">'
       . inc_kpi('On duty today', $present, it_url('duty'), $active . ' support staff · ' . $unmarked . ' not marked', $unmarked ? 'amber' : 'green')
       . inc_kpi('Housekeeping today', $tasksDone, it_url('checklists'), 'of ' . count($tasks) . ' tasks done', $tasks && $tasksDone < count($tasks) ? 'amber' : 'green')
       . inc_kpi('Supplies to reorder', $lowStock, it_url('stock'), 'at or below reorder level', $lowStock ? 'amber' : 'green')
       . '</div>';
    $todo = array_merge(array_filter([
        [$unmarked, 'support staff not marked for today', it_url('duty')],
        [count($tasks) - $tasksDone, 'housekeeping tasks not done today', it_url('checklists')],
        [$lowStock, 'supplies at or below reorder level', it_url('stock')],
    ], static fn($r) => $r[0] > 0), $todo);
}

echo it_w('<div class="inc-grid2 wide-left"><section class="card"><div class="inc-cardhead"><h2>Open tickets by category</h2><a class="small" href="') . e(it_url('tickets')) . '">Board →</a></div>';
$cat = [];
foreach (it_all("SELECT COALESCE(category, 'Uncategorised') k, COUNT(*) n FROM it_tickets WHERE status NOT IN ('resolved','closed') GROUP BY k ORDER BY n DESC") as $r) {
    $cat[$r['k']] = (int) $r['n'];
}
echo inc_bars($cat, it_w('No open tickets.'), static fn($l) => it_url('tickets', ['view' => 'list'] + ($l === 'Uncategorised' ? [] : ['category' => $l])));
echo '</section><section class="card"><h2>Needs attention</h2>';
if (!$todo) {
    echo '<p class="inc-allclear">All clear — nothing is waiting.</p>';
} else {
    echo '<ul class="inc-todo">';
    foreach ($todo as [$n, $label, $href]) {
        echo '<li><a href="' . e($href) . '"><strong>' . number_format($n) . '</strong> ' . e($label) . '<span aria-hidden="true">→</span></a></li>';
    }
    echo '</ul>';
}
echo '</section></div>';

echo it_w('<div class="inc-grid2"><section class="card"><div class="inc-cardhead"><h2>Devices by type</h2><a class="small" href="') . e(it_url('assets')) . '">Assets →</a></div>';
$types = [];
foreach (it_all("SELECT COALESCE(type, 'Other') k, COUNT(*) n FROM it_assets WHERE status NOT IN ('retired','lost') GROUP BY k ORDER BY n DESC") as $r) {
    $types[$r['k']] = (int) $r['n'];
}
echo inc_bars($types, it_w('No devices recorded yet.'), static fn($l) => it_url('assets', ['type' => $l]));
echo it_w('</section><section class="card"><div class="inc-cardhead"><h2>Upcoming renewals</h2><a class="small" href="') . e(it_url('renewals')) . it_w('">Renewals →</a></div>')
   . '<p class="muted small" style="margin-top:-6px">Running cost about <strong>' . e(it_money($monthly)) . '</strong> a month (' . e(it_money($monthly * 12)) . ' a year).</p>';
if (!$due) {
    echo '<p class="muted small">Nothing renews in the next ' . $days . ' days.</p>';
} else {
    echo '<ul class="inc-todo">';
    foreach (array_slice($due, 0, 8) as $r) {
        $d = (int) floor((strtotime($r['renews_on']) - strtotime(date('Y-m-d'))) / 86400);
        echo '<li><a href="' . e(it_url('renewals', ['edit' => $r['id']])) . '"><strong>' . ($d < 0 ? 'late' : ($d === 0 ? 'today' : $d . 'd')) . '</strong> '
           . e($r['name']) . ' · ' . e(it_money($r['cost'])) . '<span aria-hidden="true">→</span></a></li>';
    }
    echo '</ul>';
}
echo '</section></div>';

echo it_w('<section class="card"><h2>Latest tickets</h2>');
$latest = it_all('SELECT * FROM it_tickets ORDER BY created_at DESC, id DESC LIMIT 8');
if (!$latest) {
    echo inc_empty(it_w('No tickets yet.'), it_w('Log one with <strong>+ New ticket</strong>, or switch on the request link in Settings so staff can raise their own.'));
} else {
    echo it_w('<div class="scrollx"><table class="data stack"><thead><tr><th>Ticket</th><th>Requester</th><th>Priority</th><th>Status</th><th>Assigned</th><th>Opened</th></tr></thead><tbody>');
    foreach ($latest as $t) {
        echo it_w('<tr><td data-l="Ticket"><a data-popup href="') . e(it_url('ticket', ['id' => $t['id']])) . '"><strong>' . e($t['title']) . '</strong></a><div class="muted small">' . e($t['ref']) . '</div></td>'
           . '<td data-l="Requester">' . e($t['requester_name']) . '</td><td data-l="Priority">' . it_priority_badge($t['priority']) . '</td>'
           . '<td data-l="Status">' . it_status_badge($t['status']) . (it_is_overdue($t) ? ' ' . inc_badge('Overdue', 'red') : '') . '</td>'
           . '<td data-l="Assigned">' . e(it_person_name($t['assignee_id'] ? (int) $t['assignee_id'] : null) ?: '—') . '</td>'
           . '<td data-l="Opened" class="nowrap">' . e(inc_fmt_datetime($t['created_at'])) . '</td></tr>';
    }
    echo '</tbody></table></div>';
}
echo '</section>';

it_layout_end();

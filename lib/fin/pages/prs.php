<?php
/** Purchase requisitions: what people have asked to buy — all of them for procurement, a manager's own cost centres, otherwise one's own. */

$q = array_intersect_key($_GET, array_flip(['q', 'status']));
$where = ' WHERE 1=1';
$t = '';
$p = [];
if (!fp_can('proc.view')) {
    $ccs = fp_managed_ccs(fp_me());
    $where .= ' AND (r.requester_person_id = ' . fp_me() . ($ccs ? ' OR r.cost_centre_id IN (' . implode(',', $ccs) . ')' : '') . ')';
}
$statuses = ['draft' => ['Draft', ''], 'submitted' => ['Waiting for approval', 'amber'], 'approved' => ['Approved — to order', 'green'], 'ordered' => ['Ordered', 'blue'],
             'rejected' => ['Rejected', 'red'], 'closed' => ['Closed', ''], 'cancelled' => ['Cancelled', '']];
if (isset($statuses[$q['status'] ?? ''])) {
    $where .= ' AND r.status = ?';
    $t .= 's';
    $p[] = $q['status'];
}
if (trim((string) ($q['q'] ?? '')) !== '') {
    $where .= ' AND (r.number LIKE ? OR r.purpose LIKE ? OR r.requester LIKE ?)';
    $like = '%' . trim((string) $q['q']) . '%';
    $t .= 'sss';
    array_push($p, $like, $like, $like);
}
$rows = fp_all('SELECT r.*, c.code AS cc FROM fp_requisitions r LEFT JOIN fp_cost_centres c ON c.id = r.cost_centre_id' . $where . ' ORDER BY r.id DESC LIMIT 300', $t, $p);
fp_layout_start('Purchase requisitions', 'Approved requisitions become purchase orders.', fp_self_writable() ? '<a class="btn btn-primary" href="' . e(fp_url('pr', ['new' => 1])) . '">+ New requisition</a>' : '');
echo '<section class="card fp-filtercard">' . fp_filters('prs', ['q', 'status'], $q, $statuses) . '</section>';
if (!$rows) {
    echo inc_empty('No requisitions.');
} else {
    echo '<div class="scrollx"><table class="data inc-table stack"><thead><tr><th>Number</th><th>Requested by</th><th>For</th><th>Cost centre</th><th>Needed by</th><th class="num">Estimate</th><th>Status</th><th></th></tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr><td data-l="Number"><a href="' . e(fp_url('pr', ['id' => $r['id']])) . '"><strong>' . e($r['number']) . '</strong></a></td><td data-l="Requested by">' . e($r['requester']) . '</td>'
           . '<td data-l="For">' . e(mb_strimwidth($r['purpose'], 0, 70, '…')) . '</td><td data-l="Cost centre">' . e($r['cc'] ?? '') . '</td><td data-l="Needed by">' . e(fp_fmt_date($r['needed_by'])) . '</td>'
           . fp_money_td($r['est_total'], 'Estimate') . '<td data-l="Status">' . fp_badge($statuses, $r['status']) . '</td><td>'
           . ($r['status'] === 'approved' && fp_can('proc.prepare') ? '<a class="btn btn-sm btn-primary" href="' . e(fp_url('po', ['new' => 1, 'pr' => $r['id']])) . '">Raise order</a>' : '') . '</td></tr>';
    }
    echo '</tbody></table></div>';
}
fp_layout_end();

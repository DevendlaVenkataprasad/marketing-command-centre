<?php
/** Purchase orders, with how much of each has been received and billed. */

$q = array_intersect_key($_GET, array_flip(['q', 'status', 'vendor']));
$where = ' WHERE 1=1';
$t = '';
$p = [];
if (isset(fp_po_statuses()[$q['status'] ?? ''])) {
    $where .= ' AND o.status = ?';
    $t .= 's';
    $p[] = $q['status'];
}
if (!empty($q['vendor'])) {
    $where .= ' AND o.vendor_id = ' . (int) $q['vendor'];
}
if (trim((string) ($q['q'] ?? '')) !== '') {
    $where .= ' AND (o.number LIKE ? OR v.name LIKE ?)';
    $like = '%' . trim((string) $q['q']) . '%';
    $t .= 'ss';
    array_push($p, $like, $like);
}
$rows = fp_all('SELECT o.*, v.name AS vendor, (SELECT SUM(qty) FROM fp_po_lines WHERE po_id = o.id) q, (SELECT SUM(received_qty) FROM fp_po_lines WHERE po_id = o.id) rq,
                       (SELECT SUM(billed_amount) FROM fp_po_lines WHERE po_id = o.id) billed
                  FROM fp_pos o JOIN fp_vendors v ON v.id = o.vendor_id' . $where . ' ORDER BY o.id DESC LIMIT 300', $t, $p);
fp_layout_start('Purchase orders', 'Approved orders commit budget until they are billed or closed.', fp_can('proc.prepare') ? '<a class="btn btn-primary" href="' . e(fp_url('po', ['new' => 1])) . '">+ New order</a>' : '');
echo '<section class="card fp-filtercard">' . fp_filters('pos', ['q', 'status'], $q, fp_po_statuses()) . '</section>';
if (!$rows) {
    echo inc_empty('No purchase orders.');
} else {
    echo '<div class="scrollx"><table class="data inc-table stack"><thead><tr><th>Order</th><th>Vendor</th><th>Date</th><th class="num">Total</th><th>Received</th><th class="num">Billed</th><th>Status</th></tr></thead><tbody>';
    foreach ($rows as $o) {
        $pct = (float) $o['q'] > 0 ? (int) round((float) $o['rq'] / (float) $o['q'] * 100) : 0;
        echo '<tr><td data-l="Order"><a href="' . e(fp_url('po', ['id' => $o['id']])) . '"><strong>' . e($o['number']) . '</strong></a>' . ($o['amendment_no'] ? ' <span class="muted small">rev ' . (int) $o['amendment_no'] . '</span>' : '') . '</td>'
           . '<td data-l="Vendor">' . e($o['vendor']) . '</td><td data-l="Date">' . e(fp_fmt_date($o['po_date'])) . '</td>' . fp_money_td($o['total'], 'Total')
           . '<td data-l="Received"><div class="inc-mini-bar"><i style="width:' . $pct . '%"></i></div> <span class="small">' . $pct . '%</span></td>' . fp_money_td($o['billed'], 'Billed')
           . '<td data-l="Status">' . fp_badge(fp_po_statuses(), $o['status']) . '</td></tr>';
    }
    echo '</tbody></table></div>';
}
fp_layout_end();

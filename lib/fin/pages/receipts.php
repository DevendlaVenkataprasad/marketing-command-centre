<?php
/** Receipts: money received from customers, and how much of each is still unallocated. */

$q = array_intersect_key($_GET, array_flip(['q', 'from', 'to', 'customer']));
$where = ' WHERE 1=1';
$t = '';
$p = [];
foreach (['from' => 'r.receipt_date >= ?', 'to' => 'r.receipt_date <= ?'] as $k => $sql) {
    if (fp_safe_date($q[$k] ?? '')) {
        $where .= ' AND ' . $sql;
        $t .= 's';
        $p[] = $q[$k];
    }
}
if (!empty($q['customer'])) {
    $where .= ' AND r.customer_id = ' . (int) $q['customer'];
}
if (trim((string) ($q['q'] ?? '')) !== '') {
    $where .= ' AND (r.number LIKE ? OR r.reference LIKE ? OR c.name LIKE ?)';
    $like = '%' . trim((string) $q['q']) . '%';
    $t .= 'sss';
    array_push($p, $like, $like, $like);
}
$rows = fp_all('SELECT r.*, c.name AS customer, b.name AS bank, (SELECT COALESCE(SUM(a.amount),0) FROM fp_ar_allocs a WHERE a.receipt_id = r.id) allocated
                  FROM fp_receipts r JOIN fp_customers c ON c.id = r.customer_id JOIN fp_bank_accounts b ON b.id = r.bank_account_id' . $where . ' ORDER BY r.receipt_date DESC, r.id DESC LIMIT 300', $t, $p);
fp_layout_start('Receipts', count($rows) . ' shown', fp_can('ar.receipt') ? '<a class="btn btn-primary" href="' . e(fp_url('receipt', ['new' => 1])) . '">+ Record receipt</a>' : '');
echo '<section class="card fp-filtercard">' . fp_filters('receipts', ['q', 'from', 'to'], $q) . '</section>';
if (!$rows) {
    echo inc_empty('No receipts.');
} else {
    echo '<div class="scrollx"><table class="data inc-table stack"><thead><tr><th>Receipt</th><th>Date</th><th>Customer</th><th>Into</th><th>Reference</th><th class="num">Amount</th><th class="num">Unallocated</th><th>Status</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $un = $r['status'] === 'posted' ? fp_r2((float) $r['amount'] + (float) $r['tds_amount'] - (float) $r['allocated']) : 0;
        echo '<tr><td data-l="Receipt"><a href="' . e(fp_url('receipt', ['id' => $r['id']])) . '"><strong>' . e($r['number']) . '</strong></a></td><td data-l="Date">' . e(fp_fmt_date($r['receipt_date'])) . '</td>'
           . '<td data-l="Customer">' . e($r['customer']) . '</td><td data-l="Into">' . e($r['bank']) . '</td><td data-l="Reference">' . e($r['reference'] ?? '') . '</td>' . fp_money_td($r['amount'], 'Amount')
           . '<td class="num' . ($un > 0.004 ? ' late' : '') . '" data-l="Unallocated">' . ($un > 0.004 ? e(fp_money($un)) : '') . '</td><td data-l="Status">' . inc_badge(ucfirst($r['status']), $r['status'] === 'posted' ? 'green' : '') . '</td></tr>';
    }
    echo '</tbody></table></div>';
}
fp_layout_end();

<?php
/** Customers, with what each owes, how much is overdue, and their credit limit. */

$q = array_intersect_key($_GET, array_flip(['q']));
$where = ' WHERE 1=1';
$t = '';
$p = [];
if (trim((string) ($q['q'] ?? '')) !== '') {
    $where .= ' AND (c.name LIKE ? OR c.code LIKE ? OR c.gstin LIKE ? OR c.email LIKE ?)';
    $like = '%' . trim((string) $q['q']) . '%';
    $t = 'ssss';
    $p = [$like, $like, $like, $like];
}
$rows = fp_all('SELECT c.*, (SELECT COALESCE(SUM((i.total - i.received_amount) * i.fx_rate),0) FROM fp_invoices i WHERE i.customer_id = c.id AND i.kind = "invoice" AND i.status IN ("issued","partially_paid")) open_amt,
                       (SELECT COALESCE(SUM((i.total - i.received_amount) * i.fx_rate),0) FROM fp_invoices i WHERE i.customer_id = c.id AND i.kind = "invoice" AND i.status IN ("issued","partially_paid") AND i.due_date < CURDATE()) late_amt
                  FROM fp_customers c' . $where . ' ORDER BY open_amt DESC, c.name LIMIT 500', $t, $p);
fp_layout_start('Customers', count($rows) . ' customers', fp_can('customer.manage') ? '<a class="btn btn-primary" href="' . e(fp_url('customer', ['new' => 1])) . '">+ Add customer</a>' : '');
echo '<section class="card fp-filtercard">' . fp_filters('customers', ['q'], $q) . '</section>';
if (!$rows) {
    echo inc_empty('No customers yet.');
} else {
    echo '<div class="scrollx"><table class="data inc-table stack"><thead><tr><th>Customer</th><th>GSTIN</th><th>Terms</th><th class="num">Owes</th><th class="num">Overdue</th><th class="num">Credit limit</th><th>Status</th></tr></thead><tbody>';
    foreach ($rows as $c) {
        $over = $c['credit_limit'] !== null && (float) $c['open_amt'] > (float) $c['credit_limit'];
        echo '<tr><td data-l="Customer"><a href="' . e(fp_url('customer', ['id' => $c['id']])) . '"><strong>' . e($c['name']) . '</strong></a> <span class="muted small">' . e($c['code']) . '</span></td>'
           . '<td data-l="GSTIN" class="small">' . e($c['gstin'] ?? '') . '</td><td data-l="Terms">' . (int) $c['payment_terms_days'] . ' days</td>' . fp_money_td((float) $c['open_amt'] ?: null, 'Owes')
           . '<td class="num' . ((float) $c['late_amt'] > 0 ? ' late' : '') . '" data-l="Overdue">' . ((float) $c['late_amt'] > 0 ? e(fp_money($c['late_amt'])) : '') . '</td>'
           . fp_money_td($c['credit_limit'], 'Credit limit') . '<td data-l="Status">' . inc_badge(ucfirst($c['status']), $c['status'] === 'blocked' ? 'red' : 'green') . ($over ? ' ' . inc_badge('Over limit', 'red') : '') . '</td></tr>';
    }
    echo '</tbody></table></div>';
}
fp_layout_end();

<?php
/** Event Organizers — every order and the money, across events. Refunds and confirmations are on the event. */

eo_need_read();
$status = in_array($_GET['status'] ?? '', ['pending', 'paid', 'failed', 'refunded'], true) ? (string) $_GET['status'] : '';
$rows = eo_orders(['status' => $status]);
[$gross, $refunded, $net, $paid, $pending] = eo_revenue();
if (($_GET['download'] ?? '') === 'xlsx') {
    $out = [['Event', 'Name', 'Email', 'Amount', 'Discount', 'Currency', 'Method', 'Status', 'Paid at']];
    foreach ($rows as $o) {
        $out[] = [$o['event_name'], $o['name'], $o['email'], (float) $o['amount'], (float) $o['discount'], $o['currency'], $o['method'], $o['status'], $o['paid_at']];
    }
    inc_send_xlsx('orders-' . date('Ymd'), ['Orders' => $out]);
    exit;
}
inc_layout_start('Orders & revenue', number_format(count($rows)) . ' orders', [], '<a class="btn" href="' . e(inc_url('orders', ['download' => 'xlsx'] + ($status !== '' ? ['status' => $status] : []))) . '">Excel</a>');
echo '<div class="eo-tiles">' . inc_tile('Gross', eo_money($gross, 'INR')) . inc_tile('Refunded', eo_money($refunded, 'INR')) . inc_tile('Net', eo_money($net, 'INR')) . inc_tile('Paid', number_format($paid), inc_url('orders', ['status' => 'paid'])) . inc_tile('Waiting for payment', number_format($pending), inc_url('orders', ['status' => 'pending'])) . '</div>';
if (!$rows) {
    echo inc_empty('No orders here.', 'Orders appear when someone applies to a paid event.');
} else {
    echo '<section class="card"><table class="data"><thead><tr><th>Event</th><th>Who</th><th>Amount</th><th>Method</th><th>Status</th><th>When</th></tr></thead><tbody>';
    foreach ($rows as $o) {
        echo '<tr><td data-l="Event"><a href="' . e(inc_url('event', ['id' => (int) $o['event_id'], 'v' => 'money'])) . '">' . e($o['event_name']) . '</a></td><td data-l="Who">' . e($o['name']) . '<div class="muted small">' . e($o['email']) . '</div></td>'
           . '<td data-l="Amount">' . e(eo_money((float) $o['amount'], (string) $o['currency'])) . '</td><td data-l="Method">' . e($o['method']) . '</td><td data-l="Status">' . e(ucfirst((string) $o['status'])) . '</td><td data-l="When" class="nowrap">' . e(inc_fmt_datetime($o['created_at'])) . '</td></tr>';
    }
    echo '</tbody></table></section>';
}
inc_layout_end();

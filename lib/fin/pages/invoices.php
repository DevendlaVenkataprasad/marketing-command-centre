<?php
/** Customer invoices and credit notes; and, for our own organisation, bringing in the portal's billing. */

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_portal') {
    $flash = fp_action(static function () {
        [$made, $paid, $skipped] = fp_import_portal_invoices((int) ($_POST['bank_account_id'] ?? 0), (int) ($_POST['revenue_account_id'] ?? 0));
        return $made . ' invoice(s) and ' . $paid . ' payment(s) brought in.' . ($skipped ? ' Skipped: ' . implode('; ', array_slice($skipped, 0, 5)) : '');
    }, fp_url('invoices'));
}
$q = array_intersect_key($_GET, array_flip(['q', 'status', 'from', 'to', 'customer', 'sort']));
$s = (string) ($q['status'] ?? '');
$where = ' WHERE 1=1';
$t = '';
$p = [];
if ($s === 'overdue') {
    $where .= ' AND i.kind = "invoice" AND i.status IN ("issued","partially_paid") AND i.due_date < CURDATE()';
} elseif ($s === 'open') {
    $where .= ' AND i.kind = "invoice" AND i.status IN ("issued","partially_paid")';
} elseif ($s === 'disputed') {
    $where .= ' AND i.disputed = 1';
} elseif (isset(fp_invoice_statuses()[$s])) {
    $where .= ' AND i.status = ?';
    $t .= 's';
    $p[] = $s;
}
foreach (['from' => 'i.invoice_date >= ?', 'to' => 'i.invoice_date <= ?'] as $k => $sql) {
    if (fp_safe_date($q[$k] ?? '')) {
        $where .= ' AND ' . $sql;
        $t .= 's';
        $p[] = $q[$k];
    }
}
if (!empty($q['customer'])) {
    $where .= ' AND i.customer_id = ' . (int) $q['customer'];
}
if (trim((string) ($q['q'] ?? '')) !== '') {
    $where .= ' AND (i.number LIKE ? OR c.name LIKE ? OR i.customer_po LIKE ?)';
    $like = '%' . trim((string) $q['q']) . '%';
    $t .= 'sss';
    array_push($p, $like, $like, $like);
}
$from = ' FROM fp_invoices i JOIN fp_customers c ON c.id = i.customer_id' . $where;
$total = (int) fp_val('SELECT COUNT(*)' . $from, $t, $p);
$per = 50;
$pg = max(1, (int) ($_GET['pg'] ?? 1));
$order = fp_sort_sql(['date' => 'i.invoice_date', 'due' => 'i.due_date', 'customer' => 'c.name', 'amount' => 'i.total', 'status' => 'i.status'], 'i.invoice_date DESC, i.id DESC');
$rows = fp_all('SELECT i.*, c.name AS customer' . $from . ' ORDER BY ' . $order . ' LIMIT ' . $per . ' OFFSET ' . (($pg - 1) * $per), $t, $p);
$acts = fp_can('ar.prepare') ? '<a class="btn" href="' . e(fp_url('invoice', ['new' => 1, 'kind' => 'credit_note'])) . '">+ Credit note</a> <a class="btn btn-primary" href="' . e(fp_url('invoice', ['new' => 1])) . '">+ New invoice</a>' : '';
fp_layout_start('Customer invoices', number_format($total) . ' shown', $acts, [], $flash);
echo '<nav class="subtabs">';
foreach (['' => 'All', 'draft' => 'Drafts', 'submitted' => 'Awaiting approval', 'open' => 'Unpaid', 'overdue' => 'Overdue', 'disputed' => 'Disputed', 'paid' => 'Paid'] as $k => $l) {
    echo '<a href="' . e(fp_url('invoices', ['status' => $k] + array_diff_key($q, ['status' => 1]))) . '"' . ($s === $k ? ' class="on"' : '') . '>' . e($l) . '</a>';
}
echo '</nav><section class="card fp-filtercard">' . fp_filters('invoices', ['q', 'from', 'to'], $q, [], array_filter(['status' => $s])) . '</section>';
if (!$rows) {
    echo inc_empty('No invoices here.');
} else {
    echo '<div class="scrollx"><table class="data inc-table stack"><thead><tr><th>Number</th>' . fp_sort_th('Customer', 'customer', 'invoices', $q) . fp_sort_th('Date', 'date', 'invoices', $q)
       . fp_sort_th('Due', 'due', 'invoices', $q) . fp_sort_th('Total', 'amount', 'invoices', $q, 'num') . '<th class="num">Unpaid</th>' . fp_sort_th('Status', 'status', 'invoices', $q) . '</tr></thead><tbody>';
    foreach ($rows as $i) {
        $openAmt = in_array($i['status'], ['issued', 'partially_paid'], true) ? (float) $i['total'] - (float) $i['received_amount'] : null;
        $late = $openAmt && $i['due_date'] && $i['due_date'] < date('Y-m-d') && $i['kind'] === 'invoice';
        echo '<tr><td data-l="Number"><a href="' . e(fp_url('invoice', ['id' => $i['id']])) . '"><strong>' . e($i['number']) . '</strong></a>' . ($i['kind'] === 'credit_note' ? ' ' . inc_badge('Credit note', 'blue') : '') . '</td>'
           . '<td data-l="Customer">' . e($i['customer']) . '</td><td data-l="Date">' . e(fp_fmt_date($i['invoice_date'])) . '</td><td data-l="Due" class="' . ($late ? 'late' : '') . '">' . e(fp_fmt_date($i['due_date'])) . '</td>'
           . fp_money_td($i['total'], 'Total') . fp_money_td($openAmt, 'Unpaid') . '<td data-l="Status">' . fp_badge(fp_invoice_statuses(), $i['status']) . ($late ? ' ' . inc_badge('Overdue', 'red') : '')
           . ($i['disputed'] ? ' ' . inc_badge('Disputed', 'amber') : '') . '</td></tr>';
    }
    echo '</tbody></table></div>' . fp_pager($total, $per, $pg, 'invoices', $q);
}

// Our organisation also bills its clients through the portal's own invoices: bring them into receivables.
if (fp_org() === 0 && fp_can('ar.prepare') && fp_writable()) {
    $waiting = (int) (fabo_one('SELECT COUNT(*) n FROM invoices i WHERE i.state IN ("sent","paid")')['n'] ?? 0)
             - (int) fp_val('SELECT COUNT(*) FROM fp_invoices WHERE legacy_invoice_id IS NOT NULL');
    echo '<section class="card"><h2>Portal billing</h2><p class="muted small">The invoices raised to clients on <a href="finance.php">Client billing</a> can be brought into receivables, with their payments, so the ledger and the reports include them. '
       . 'Each is brought in once; run it again to bring in what is new or newly paid. The originals are not changed.</p>'
       . inc_form_open() . '<input type="hidden" name="action" value="import_portal"><div class="inc-row">'
       . inc_field('Payments went to', inc_select('bank_account_id', fp_bank_options(), '', 'Choose…', 'required'), '', true)
       . inc_field('Revenue account', inc_select('revenue_account_id', fp_account_options(['income']), fp_acct('revenue'), '', 'required'), '', true)
       . '</div><button class="btn">Bring in portal invoices' . ($waiting > 0 ? ' (' . $waiting . ' not yet in)' : '') . '</button></form></section>';
}
fp_layout_end();

<?php
/** Vendor bills and debit notes: the payables register. */

$q = array_intersect_key($_GET, array_flip(['q', 'status', 'from', 'to', 'vendor', 'kind', 'sort']));
$st = fp_bill_statuses() + ['overdue' => ['Overdue', 'red'], 'open' => ['Open (to be paid)', '']];
$where = ' WHERE 1=1';
$t = '';
$p = [];
$s = (string) ($q['status'] ?? '');
if ($s === 'overdue') {
    $where .= ' AND b.status IN ("approved","partially_paid") AND b.due_date < CURDATE()';
} elseif ($s === 'open') {
    $where .= ' AND b.status IN ("approved","partially_paid")';
} elseif (isset(fp_bill_statuses()[$s])) {
    $where .= ' AND b.status = ?';
    $t .= 's';
    $p[] = $s;
}
foreach (['from' => 'b.bill_date >= ?', 'to' => 'b.bill_date <= ?'] as $k => $sql) {
    if (fp_safe_date($q[$k] ?? '')) {
        $where .= ' AND ' . $sql;
        $t .= 's';
        $p[] = $q[$k];
    }
}
if (!empty($q['vendor'])) {
    $where .= ' AND b.vendor_id = ' . (int) $q['vendor'];
}
if (($q['kind'] ?? '') === 'debit_note') {
    $where .= ' AND b.kind = "debit_note"';
}
if (trim((string) ($q['q'] ?? '')) !== '') {
    $where .= ' AND (b.number LIKE ? OR b.bill_no LIKE ? OR v.name LIKE ? OR b.description LIKE ?)';
    $like = '%' . trim((string) $q['q']) . '%';
    $t .= 'ssss';
    array_push($p, $like, $like, $like, $like);
}
$from = ' FROM fp_bills b JOIN fp_vendors v ON v.id = b.vendor_id' . $where;
$total = (int) fp_val('SELECT COUNT(*)' . $from, $t, $p);
$sum = fp_one('SELECT COALESCE(SUM(b.total * b.fx_rate),0) t, COALESCE(SUM((b.total - b.paid_amount) * b.fx_rate),0) o' . $from, $t, $p);
$per = 50;
$pg = max(1, (int) ($_GET['pg'] ?? 1));
$order = fp_sort_sql(['date' => 'b.bill_date', 'due' => 'b.due_date', 'vendor' => 'v.name', 'amount' => 'b.total', 'status' => 'b.status'], 'b.bill_date DESC, b.id DESC');
$rows = fp_all('SELECT b.*, v.name AS vendor' . $from . ' ORDER BY ' . $order . ' LIMIT ' . $per . ' OFFSET ' . (($pg - 1) * $per), $t, $p);

fp_layout_start('Vendor bills', number_format($total) . ' · ' . e(fp_money($sum['t'], false)) . ' billed · ' . e(fp_money($sum['o'], false)) . ' still open',
                fp_can('ap.prepare') ? '<a class="btn" href="' . e(fp_url('bill', ['new' => 1, 'kind' => 'debit_note'])) . '">+ Debit note</a> <a class="btn btn-primary" href="' . e(fp_url('bill', ['new' => 1])) . '">+ Enter bill</a>' : '');
echo '<nav class="subtabs">';
foreach (['' => 'All', 'submitted' => 'To validate', 'under_review' => 'Under review', 'awaiting_approval' => 'Awaiting approval', 'open' => 'To pay', 'overdue' => 'Overdue', 'on_hold' => 'On hold'] as $k => $l) {
    echo '<a href="' . e(fp_url('bills', ['status' => $k] + array_diff_key($q, ['status' => 1]))) . '"' . ($s === $k ? ' class="on"' : '') . '>' . e($l) . '</a>';
}
echo '</nav>';
echo '<section class="card fp-filtercard">' . fp_filters('bills', ['q', 'from', 'to'], $q, [], array_filter(['status' => $s])) . '</section>';
if (!$rows) {
    echo inc_empty('No bills here.', fp_can('ap.prepare') ? 'Enter a vendor\'s invoice with “Enter bill”.' : '');
} else {
    echo '<div class="scrollx"><table class="data inc-table stack"><thead><tr><th>Number</th>' . fp_sort_th('Vendor', 'vendor', 'bills', $q) . '<th>Invoice no.</th>'
       . fp_sort_th('Date', 'date', 'bills', $q) . fp_sort_th('Due', 'due', 'bills', $q) . fp_sort_th('Total', 'amount', 'bills', $q, 'num') . '<th class="num">Open</th>'
       . fp_sort_th('Status', 'status', 'bills', $q) . '</tr></thead><tbody>';
    foreach ($rows as $b) {
        $late = in_array($b['status'], ['approved', 'partially_paid'], true) && $b['due_date'] && $b['due_date'] < date('Y-m-d');
        echo '<tr><td data-l="Number"><a href="' . e(fp_url('bill', ['id' => $b['id']])) . '"><strong>' . e($b['number']) . '</strong></a>' . ($b['kind'] === 'debit_note' ? ' ' . inc_badge('Debit note', 'blue') : '') . '</td>'
           . '<td data-l="Vendor">' . e($b['vendor']) . '</td><td data-l="Invoice no.">' . e($b['bill_no']) . '</td><td data-l="Date">' . e(fp_fmt_date($b['bill_date'])) . '</td>'
           . '<td data-l="Due" class="' . ($late ? 'late' : '') . '">' . e(fp_fmt_date($b['due_date'])) . '</td>' . fp_money_td($b['total'], 'Total')
           . fp_money_td(in_array($b['status'], ['approved', 'partially_paid'], true) ? (float) $b['total'] - (float) $b['paid_amount'] : null, 'Open')
           . '<td data-l="Status">' . fp_badge(fp_bill_statuses(), $b['status']) . ($b['match_status'] === 'exception' ? ' ' . inc_badge('Match exception', 'red') : '') . ($b['dup_note'] ? ' ' . inc_badge('Possible duplicate', 'amber') : '') . '</td></tr>';
    }
    echo '</tbody></table></div>' . fp_pager($total, $per, $pg, 'bills', $q);
}
fp_layout_end();

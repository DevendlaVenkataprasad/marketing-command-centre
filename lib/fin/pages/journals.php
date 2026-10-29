<?php
/** Journal entries: every journal, manual and system, filtered and paged. */

$q = array_intersect_key($_GET, array_flip(['q', 'status', 'from', 'to', 'entity', 'type', 'source', 'sort']));
$where = ' WHERE 1=1';
$t = '';
$p = [];
if (($q['status'] ?? '') !== '' && isset(fp_journal_statuses()[$q['status']])) {
    $where .= ' AND status = ?';
    $t .= 's';
    $p[] = $q['status'];
}
foreach (['from' => 'jdate >= ?', 'to' => 'jdate <= ?'] as $k => $sql) {
    if (fp_safe_date($q[$k] ?? '')) {
        $where .= ' AND ' . $sql;
        $t .= 's';
        $p[] = $q[$k];
    }
}
if (!empty($q['entity'])) {
    $where .= ' AND entity_id = ' . (int) $q['entity'];
}
if (($q['type'] ?? '') !== '' && isset(fp_journal_types()[$q['type']])) {
    $where .= ' AND type = ?';
    $t .= 's';
    $p[] = $q['type'];
}
if (trim((string) ($q['q'] ?? '')) !== '') {
    $where .= ' AND (number LIKE ? OR description LIKE ? OR reference LIKE ?)';
    $like = '%' . trim((string) $q['q']) . '%';
    $t .= 'sss';
    array_push($p, $like, $like, $like);
}
$total = (int) fp_val('SELECT COUNT(*) FROM fp_journals' . $where, $t, $p);
$per = 50;
$pg = max(1, (int) ($_GET['pg'] ?? 1));
$order = fp_sort_sql(['date' => 'jdate', 'number' => 'number', 'amount' => 'total', 'status' => 'status'], 'jdate DESC, id DESC');
$rows = fp_all('SELECT * FROM fp_journals' . $where . ' ORDER BY ' . $order . ' LIMIT ' . $per . ' OFFSET ' . (($pg - 1) * $per), $t, $p);

fp_layout_start('Journal entries', 'Manual entries are prepared and approved; entries from bills, invoices, receipts, payments, claims and depreciation are posted by those documents.',
                fp_can('gl.prepare') ? '<a class="btn btn-primary" href="' . e(fp_url('journal', ['new' => 1])) . '">+ New journal</a>' : '');
echo '<section class="card fp-filtercard">' . fp_filters('journals', ['q', 'from', 'to', 'entity', 'status'], $q, fp_journal_statuses()) . '</section>';
if (!$rows) {
    echo inc_empty('No journals match.', fp_can('gl.prepare') ? 'Start one with “New journal”.' : '');
} else {
    echo '<div class="scrollx"><table class="data inc-table stack"><thead><tr>' . fp_sort_th('Date', 'date', 'journals', $q) . fp_sort_th('Number', 'number', 'journals', $q)
       . '<th>Type</th><th>Description</th>' . fp_sort_th('Amount', 'amount', 'journals', $q, 'num') . fp_sort_th('Status', 'status', 'journals', $q) . '<th>Prepared by</th></tr></thead><tbody>';
    foreach ($rows as $j) {
        echo '<tr><td data-l="Date">' . e(fp_fmt_date($j['jdate'])) . '</td><td data-l="Number"><a href="' . e(fp_url('journal', ['id' => $j['id']])) . '"><strong>' . e($j['number'] ?: 'Draft #' . $j['id']) . '</strong></a></td>'
           . '<td data-l="Type">' . e(fp_journal_types()[$j['type']] ?? $j['type']) . ($j['source'] && $j['source'] !== 'manual' ? ' <span class="muted small">' . e($j['source']) . '</span>' : '') . '</td>'
           . '<td data-l="Description">' . e($j['description']) . ($j['reversed_by'] ? ' ' . inc_badge('Reversed') : '') . '</td>' . fp_money_td($j['total'], 'Amount')
           . '<td data-l="Status">' . fp_badge(fp_journal_statuses(), $j['status']) . '</td><td data-l="Prepared by" class="small">' . e($j['prepared_by']) . '</td></tr>';
    }
    echo '</tbody></table></div>' . fp_pager($total, $per, $pg, 'journals', $q);
}
fp_layout_end();

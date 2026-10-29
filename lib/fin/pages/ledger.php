<?php
/** General ledger inquiry: one account's posted entries with the running balance, filtered by period, entity, cost centre and project. */

$f = fp_report_filters($_GET);
$acc = $f['account'];
$q = array_filter(['account' => $acc, 'from' => $f['from'], 'to' => $f['to'], 'entity' => $f['entity'], 'cc' => $f['cc'], 'project' => $f['project']]);
fp_layout_start('General ledger', $acc ? '' : 'Choose an account to see every posted entry on it.', fp_can('reports.export') && $acc
    ? '<a class="btn btn-sm" href="' . e(fp_url('reports', ['r' => 'gl', 'export' => 'xlsx'] + $q)) . '">Excel</a> <a class="btn btn-sm" href="' . e(fp_url('reports', ['r' => 'gl', 'export' => 'csv'] + $q)) . '">CSV</a>' : '');
echo '<section class="card fp-filtercard">' . fp_filters('ledger', ['account', 'from', 'to', 'entity', 'cc', 'project'], $q) . '</section>';

if (!$acc) {
    $tb = fp_trial_balance(['from' => $f['from'], 'to' => $f['to'], 'entity' => $f['entity'], 'cc' => $f['cc'], 'project' => $f['project']]);
    if (!$tb['rows']) {
        echo inc_empty('Nothing posted in this period.');
    } else {
        echo '<div class="scrollx"><table class="data inc-table stack"><thead><tr><th>Account</th><th class="num">Opening</th><th class="num">Debits</th><th class="num">Credits</th><th class="num">Closing</th></tr></thead><tbody>';
        foreach ($tb['rows'] as $r) {
            $open = $r['open_dr'] - $r['open_cr'];
            $close = $r['close_dr'] - $r['close_cr'];
            echo '<tr><td data-l="Account"><a href="' . e(fp_url('ledger', ['account' => $r['id']] + $q)) . '">' . e($r['code'] . ' ' . $r['name']) . '</a></td>'
               . fp_money_td($open, 'Opening (Dr+)') . fp_money_td($r['dr'], 'Debits') . fp_money_td($r['cr'], 'Credits') . fp_money_td($close, 'Closing (Dr+)') . '</tr>';
        }
        echo '</tbody></table></div><p class="muted small">Balances are shown debit-positive.</p>';
    }
    fp_layout_end();
    return;
}

$per = 200;
$pg = max(1, (int) ($_GET['pg'] ?? 1));
$g = fp_gl_account($acc, $f, $per, ($pg - 1) * $per);
if (!$g['account']) {
    echo inc_empty('That account does not exist.');
    fp_layout_end();
    return;
}
$a = $g['account'];
echo '<section class="card"><div class="inc-cardhead"><h2>' . e($a['code'] . ' · ' . $a['name']) . '</h2><span class="muted small">' . e(fp_account_types()[$a['type']]) . ' · balance shown '
   . (in_array($a['type'], ['asset', 'expense'], true) ? 'debit' : 'credit') . '-positive</span></div>';
echo '<div class="scrollx"><table class="data inc-table stack"><thead><tr><th>Date</th><th>Journal</th><th>Description</th><th>Cost centre</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Balance</th></tr></thead><tbody>';
echo '<tr class="fp-sub"><td colspan="6">' . ($pg > 1 ? 'Brought forward' : 'Opening balance') . '</td>' . fp_money_td($g['opening']) . '</tr>';
foreach ($g['rows'] as $l) {
    echo '<tr><td data-l="Date">' . e(fp_fmt_date($l['jdate'])) . '</td><td data-l="Journal"><a href="' . e(fp_url('journal', ['id' => $l['journal_id']])) . '">' . e($l['number']) . '</a></td>'
       . '<td data-l="Description">' . e($l['description'] ?: $l['jdesc']) . '</td><td data-l="Cost centre">' . e($l['cc_code'] ?? '') . '</td>'
       . fp_money_td((float) $l['debit_base'] ?: null, 'Debit') . fp_money_td((float) $l['credit_base'] ?: null, 'Credit') . fp_money_td($l['running'], 'Balance') . '</tr>';
}
echo '</tbody></table></div>' . fp_pager($g['total'], $per, $pg, 'ledger', $q) . '</section>';
fp_layout_end();

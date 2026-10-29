<?php
/** Finance dashboard. Every figure is read from posted journals and the documents behind them, and each one opens what it is made of. */

require_once __DIR__ . '/../../charts.php';

$f = fp_report_filters($_GET);
$base = ['fy' => $f['fy'], 'from' => $f['from'], 'to' => $f['to'], 'entity' => $f['entity'], 'cc' => $f['cc'], 'project' => $f['project']];
$lf = ['from' => $f['from'], 'to' => $f['to'], 'entity' => $f['entity'], 'cc' => $f['cc'], 'project' => $f['project']];

$pl = fp_profit_loss($lf);
$cash = array_sum(array_map(static fn($b) => $b['kind'] !== 'card' ? $b['balance'] : 0, fp_cash_position($f['to'], ['entity' => $f['entity']])));
$bal = fp_balances(['to' => $f['to'], 'entity' => $f['entity']], false);
[$apd, $apc] = $bal[fp_acct('ap')] ?? [0, 0];
[$ard, $arc] = $bal[fp_acct('ar')] ?? [0, 0];
$ap = fp_r2($apc - $apd);
$ar = fp_r2($ard - $arc);
$bva = fp_budget_vs_actual($f['fy'], ['entity' => $f['entity'], 'cc' => $f['cc'], 'project' => $f['project']]);
$bTot = array_sum(array_column($bva, 'budget'));
$bUsed = array_sum(array_column($bva, 'actual')) + array_sum(array_column($bva, 'committed'));
$bAvail = array_sum(array_column($bva, 'available'));
$pending = array_sum(array_map(static fn($r) => (int) $r['n'], fp_wf_pending_all()));
$mine = fp_wf_inbox(50);
$overdue = array_values(array_filter(fp_ar_open(), static fn($i) => $i['due_date'] && $i['due_date'] < date('Y-m-d')));
$soon = date('Y-m-d', strtotime('+14 day'));
$payable = fp_bills_payable(null, $soon);
$upcoming = array_sum(array_map(static fn($b) => $b['open'] * (float) $b['fx_rate'], $payable))
          + fp_val('SELECT COALESCE(SUM(amount * fx_rate),0) FROM fp_payments WHERE status IN ("submitted","authorized","released") AND pay_date <= ?', 's', [$soon]);
$cf = fp_cash_flow($lf);
$fc = fp_can('pay.view') ? fp_cash_forecast(13, $f['entity']) : null;

$acts = (fp_can('gl.prepare') ? '<a class="btn" href="' . e(fp_url('journal', ['new' => 1])) . '">+ Journal</a> ' : '')
      . (fp_can('ap.prepare') ? '<a class="btn" href="' . e(fp_url('bill', ['new' => 1])) . '">+ Bill</a> ' : '')
      . (fp_can('ar.prepare') ? '<a class="btn btn-primary" href="' . e(fp_url('invoice', ['new' => 1])) . '">+ Invoice</a>' : '');
fp_layout_start('Finance dashboard', fp_fmt_date($f['from']) . ' – ' . fp_fmt_date($f['to']) . ' · amounts in ' . e(fp_setting('base_currency')) . ' (base currency)', $acts);

echo '<section class="card fp-filtercard">' . fp_filters('dashboard', ['fy', 'from', 'to', 'entity', 'cc', 'project'], $base) . '</section>';

// Nobody can approve what the only finance person submits: say so before it bites.
if (fp_setting('sod_strict') === '1' && !fp_role_holders(['finance_manager', 'controller', 'cfo']) && fp_is_sysadmin()) {
    echo '<div class="inc-warn"><strong>No approvers yet.</strong> Nobody holds the Finance Manager, Controller or CFO role, so you approve everything — but strict separation of duties stops you approving what you submit yourself. '
       . '<a href="' . e(fp_url('settings', ['tab' => 'roles'])) . '">Give people finance roles</a> or, for a small team, turn strict separation off in <a href="' . e(fp_url('settings')) . '">Settings</a>.</div>';
}

$rpt = static fn(string $r, array $x = []) => fp_url('reports', ['r' => $r] + $base + $x);
echo '<div class="inc-kpis fp-kpis">'
   . fp_kpi('Cash and bank', fp_money($cash, false), fp_url('banks'), 'At ' . fp_fmt_date($f['to']), $cash < 0 ? 'red' : '')
   . fp_kpi('Revenue', fp_money($pl['sum']['revenue'], false), $rpt('pnl'), 'Operating revenue in the period')
   . fp_kpi('Operating expenses', fp_money($pl['sum']['opex'] + $pl['sum']['cogs'], false), $rpt('pnl'), 'Direct and operating costs')
   . fp_kpi('Net profit', fp_money($pl['net'], false), $rpt('pnl'), $pl['margin'] !== null ? 'Margin ' . fp_pct($pl['margin'], 1) : 'No revenue yet', $pl['net'] < 0 ? 'red' : 'green')
   . fp_kpi('Payables outstanding', fp_money($ap, false), $rpt('ap_aging'), count(fp_bills_payable()) . ' bills to pay', $ap > 0 ? 'amber' : '')
   . fp_kpi('Receivables outstanding', fp_money($ar, false), $rpt('ar_aging'), 'DSO ' . (($d = fp_dso(90, $f['entity'])) === null ? '—' : $d . ' days'))
   . fp_kpi('Budget used', $bTot > 0 ? fp_pct($bUsed / $bTot) : '—', fp_url('budgets', ['tab' => 'bva', 'fy' => $f['fy']]), $bTot > 0 ? 'Actual + committed of ' . fp_money($bTot, false) : 'No approved budget for FY ' . fp_fy_label($f['fy']),
             $bTot > 0 && $bUsed / $bTot >= (float) fp_setting('budget_warn_pct') / 100 ? 'amber' : '')
   . fp_kpi('Budget available', $bTot > 0 ? fp_money($bAvail, false) : '—', fp_url('budgets', ['tab' => 'bva', 'fy' => $f['fy']]), 'Budget less actual and committed', $bAvail < 0 ? 'red' : '')
   . fp_kpi('Pending approvals', (string) $pending, fp_url('approvals'), count($mine) . ' waiting for you', count($mine) ? 'amber' : '')
   . fp_kpi('Overdue invoices', (string) count($overdue), fp_url('invoices', ['status' => 'overdue']), fp_money(array_sum(array_column($overdue, 'open_base')), false) . ' past due', $overdue ? 'red' : 'green')
   . fp_kpi('Upcoming payments', fp_money($upcoming, false), fp_url('payments', ['tab' => 'propose']), 'Due or scheduled in 14 days')
   . fp_kpi('Cash flow', fp_money($cf['net'], false), $rpt('cf'), $fc ? '13-week forecast: ' . fp_money(end($fc['weeks'])['closing'], false) : 'Net change in the period', $cf['net'] < 0 ? 'amber' : '')
   . '</div>';

/* --------------------------------------------------------------- charts */
echo '<section class="card"><div class="inc-cardhead"><h2>Trends — FY ' . e(fp_fy_label($f['fy'])) . '</h2><a class="small" href="' . e($rpt('pnl')) . '">Profit and loss →</a></div><div class="chartgrid">';
$mpl = fp_monthly_pl($f['fy'], ['entity' => $f['entity'], 'cc' => $f['cc'], 'project' => $f['project']]);
$mon = array_map(static fn($m) => date('M', strtotime($m . '-01')), array_keys($mpl));
fabo_chart_bar('Income and expenses by month', $mon, [
    ['name' => 'Income', 'color' => 'var(--s3)', 'values' => array_map(static fn($v) => max(0, $v['income']), array_values($mpl))],
    ['name' => 'Expenses', 'color' => 'var(--s2)', 'values' => array_map(static fn($v) => max(0, $v['expense']), array_values($mpl))],
], 'money');
$mc = fp_monthly_cash($f['fy'], ['entity' => $f['entity']]);
fabo_chart_bar('Cash in and cash out', $mon, [
    ['name' => 'In', 'color' => 'var(--s1)', 'values' => array_map(static fn($v) => $v['in'], array_values($mc))],
    ['name' => 'Out', 'color' => 'var(--s4)', 'values' => array_map(static fn($v) => $v['out'], array_values($mc))],
], 'money');
$run = 0;
$cum = [];
foreach ($mpl as $v) {
    $run += $v['income'] - $v['expense'];
    $cum[] = $run;
}
fabo_chart_line('Revenue and profit (year to date)', $mon, [
    ['name' => 'Revenue YTD', 'color' => 'var(--s1)', 'values' => array_reduce(array_values($mpl), static function ($c, $v) { $c[] = (end($c) ?: 0) + $v['income']; return $c; }, [])],
    ['name' => 'Profit YTD', 'color' => 'var(--s3)', 'values' => $cum],
], 'money');
echo '</div></section>';

echo '<section class="card"><div class="inc-cardhead"><h2>Where the money goes</h2><a class="small" href="' . e($rpt('dept')) . '">By department →</a></div><div class="chartgrid">';
$dept = fp_report_run('dept', $f + ['cc' => null]);
$slices = [];
foreach ($dept['rows'] as $r) {
    if (empty($r['_cls']) && ($r['expense'] ?? 0) > 0) {
        $slices[] = ['label' => $r['name'], 'value' => $r['expense']];
    }
}
$colors = ['var(--s1)', 'var(--s2)', 'var(--s3)', 'var(--s4)', '#8b5cf6', '#14b8a6', '#64748b'];
$pie = [];
foreach (array_slice($slices, 0, 6) as $i => $s) {
    $pie[] = ['label' => $s['label'], 'value' => $s['value'], 'color' => $colors[$i]];
}
if (count($slices) > 6) {
    $pie[] = ['label' => 'Others', 'value' => array_sum(array_column(array_slice($slices, 6), 'value')), 'color' => $colors[6]];
}
if ($pie) {
    fabo_chart_pie('Expenses by cost centre', $pie, 'money');
} else {
    echo '<div class="chartcard"><h3>Expenses by cost centre</h3><p class="muted small">No expenses posted in the period.</p></div>';
}
$top = $bva;
usort($top, static fn($a, $b) => $b['budget'] <=> $a['budget']);
$top = array_slice($top, 0, 6);
if ($top) {
    fabo_chart_bar('Budget against actual (largest lines)', array_map(static fn($l) => $l['account_name'] . ($l['cc_code'] ? ' ' . $l['cc_code'] : ''), $top), [
        ['name' => 'Budget', 'color' => 'var(--s1)', 'values' => array_column($top, 'budget')],
        ['name' => 'Actual', 'color' => 'var(--s2)', 'values' => array_column($top, 'actual')],
        ['name' => 'Committed', 'color' => 'var(--s4)', 'values' => array_column($top, 'committed')],
    ], 'money');
    $byCc = [];
    foreach ($bva as $l) {
        $k = $l['cc_code'] ?: 'All';
        $byCc[$k]['b'] = ($byCc[$k]['b'] ?? 0) + $l['budget'];
        $byCc[$k]['u'] = ($byCc[$k]['u'] ?? 0) + $l['actual'] + $l['committed'];
    }
    fabo_chart_bar('Budget used by department (%)', array_keys($byCc), [
        ['name' => 'Used %', 'color' => 'var(--s3)', 'values' => array_map(static fn($v) => $v['b'] > 0 ? round($v['u'] / $v['b'] * 100, 1) : 0, array_values($byCc))],
    ]);
} else {
    echo '<div class="chartcard"><h3>Budget against actual</h3><p class="muted small">No approved budget for FY ' . e(fp_fy_label($f['fy'])) . '. '
       . (fp_can('budget.prepare') ? '<a href="' . e(fp_url('budget', ['new' => 1])) . '">Prepare one</a>.' : '') . '</p></div>';
}
$apA = fp_can('ap.view') ? fp_ap_aging($f['to'], $f['entity']) : [];
$arA = fp_can('ar.view') ? fp_ar_aging($f['to'], $f['entity']) : [];
fabo_chart_bar('Payables and receivables by age', array_values(fp_aging_buckets()), [
    ['name' => 'Payables', 'color' => 'var(--s2)', 'values' => array_map(static fn($k) => array_sum(array_column($apA, $k)), array_keys(fp_aging_buckets()))],
    ['name' => 'Receivables', 'color' => 'var(--s1)', 'values' => array_map(static fn($k) => array_sum(array_column($arA, $k)), array_keys(fp_aging_buckets()))],
], 'money');
echo '</div></section>';

/* -------------------------------------------------------------- the lists */
echo '<div class="inc-grid2">';
echo '<section class="card"><div class="inc-cardhead"><h2>Waiting for you</h2><a class="small" href="' . e(fp_url('approvals')) . '">All approvals →</a></div>';
if (!$mine) {
    echo '<p class="inc-allclear">Nothing is waiting for your approval.</p>';
} else {
    echo '<ul class="inc-todo">';
    foreach (array_slice($mine, 0, 8) as $m) {
        echo '<li><a href="' . e(fp_url(fp_doc_types()[$m['type']]['page'], ['id' => $m['doc']['id']])) . '"><strong>' . e(fp_doc_types()[$m['type']]['label']) . '</strong> ' . e($m['title'])
           . ' <span class="muted small">since ' . e(fp_fmt_dt($m['since'])) . '</span><span aria-hidden="true">→</span></a></li>';
    }
    echo '</ul>';
}
echo '</section>';

// What needs doing: overdue invoices and bills, payments coming up, budget alerts, the period close.
$todo = [];
$prevMonth = date('Y-m', strtotime('first day of last month'));
$pp = fp_period_for($prevMonth . '-01');
if ($pp['status'] === 'open' && fp_can('gl.view')) {
    $todo[] = [1, $pp['name'] . ' is still open — review the month-end checklist and close it', fp_url('close', ['month' => $prevMonth])];
}
if ($n = (int) fp_val('SELECT COUNT(*) FROM fp_journals WHERE status = "draft" AND jdate <= CURDATE()')) {
    $todo[] = [$n, 'draft journals not yet submitted', fp_url('journals', ['status' => 'draft'])];
}
if ($overdue && fp_can('ar.view')) {
    $todo[] = [count($overdue), 'customer invoices overdue — follow up', fp_url('invoices', ['status' => 'overdue'])];
}
$late = array_filter(fp_bills_payable(), static fn($b) => $b['due_date'] && $b['due_date'] < date('Y-m-d'));
if ($late && fp_can('ap.view')) {
    $todo[] = [count($late), 'vendor bills past their due date', fp_url('payments', ['tab' => 'propose'])];
}
if ($payable && fp_can('pay.view')) {
    $todo[] = [count($payable), 'bills due in the next 14 days', fp_url('payments', ['tab' => 'propose'])];
}
if (($n = (int) fp_val('SELECT COUNT(*) FROM fp_payments WHERE status = "authorized"')) && fp_can('pay.view')) {
    $todo[] = [$n, 'authorised payments waiting to be released to the bank', fp_url('payments', ['status' => 'authorized'])];
}
if (($n = (int) fp_val('SELECT COUNT(*) FROM fp_payments WHERE status = "released"')) && fp_can('pay.view')) {
    $todo[] = [$n, 'payments sent to the bank and not yet confirmed', fp_url('payments', ['status' => 'released'])];
}
if (($n = (int) fp_val('SELECT COUNT(*) FROM fp_bank_lines WHERE status = "unmatched"')) && fp_can('bank.reconcile')) {
    $todo[] = [$n, 'bank statement lines to reconcile', fp_url('banks')];
}
if (($n = count(fp_assets_to_capitalise())) && fp_can('assets.manage')) {
    $todo[] = [$n, 'bill lines charged to fixed assets, not yet on the register', fp_url('assets', ['tab' => 'capitalise'])];
}
foreach (fp_all('SELECT a.*, l.account_id, ac.code, ac.name, c.code cc FROM fp_budget_alerts a JOIN fp_budget_lines l ON l.id = a.budget_line_id JOIN fp_accounts ac ON ac.id = l.account_id
                  LEFT JOIN fp_cost_centres c ON c.id = l.cost_centre_id WHERE a.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) ORDER BY a.id DESC LIMIT 5') as $al) {
    if (fp_can('budget.view')) {
        $todo[] = [(int) $al['utilisation'] . '%', 'budget used: ' . $al['code'] . ' ' . $al['name'] . ($al['cc'] ? ' (' . $al['cc'] . ')' : ''), fp_url('budgets', ['tab' => 'bva'])];
    }
}
foreach (fp_filings_upcoming_safe() as $fl) {
    $todo[] = [fp_fmt_date($fl['due_date']), fabo_filing_label($fl['kind']) . ' ' . $fl['period_label'] . ' due', fp_url('tax', ['tab' => 'calendar'])];
}
echo '<section class="card"><h2>Needs attention</h2>';
if (!$todo) {
    echo '<p class="inc-allclear">All clear — nothing is waiting.</p>';
} else {
    echo '<ul class="inc-todo">';
    foreach ($todo as [$n, $label, $href]) {
        echo '<li><a href="' . e($href) . '"><strong>' . e(is_int($n) ? number_format($n) : (string) $n) . '</strong> ' . e($label) . '<span aria-hidden="true">→</span></a></li>';
    }
    echo '</ul>';
}
echo '</section></div>';

if (fp_can('gl.view')) {
    $recent = fp_all('SELECT * FROM fp_journals WHERE status = "posted"' . ($f['entity'] ? ' AND entity_id = ' . (int) $f['entity'] : '') . ' ORDER BY posted_at DESC, id DESC LIMIT 10');
    echo '<section class="card"><div class="inc-cardhead"><h2>Recent transactions</h2><a class="small" href="' . e(fp_url('journals')) . '">All journals →</a></div>';
    if (!$recent) {
        echo inc_empty('Nothing posted yet.', 'Bills, invoices, receipts, payments and journals appear here as they are posted.');
    } else {
        echo '<div class="scrollx"><table class="data inc-table stack"><thead><tr><th>Date</th><th>Journal</th><th>Description</th><th class="num">Amount</th><th>Posted</th></tr></thead><tbody>';
        foreach ($recent as $j) {
            echo '<tr><td data-l="Date">' . e(fp_fmt_date($j['jdate'])) . '</td><td data-l="Journal"><a href="' . e(fp_url('journal', ['id' => $j['id']])) . '">' . e($j['number']) . '</a></td>'
               . '<td data-l="Description">' . e($j['description']) . '</td>' . fp_money_td($j['total'], 'Amount') . '<td data-l="Posted" class="muted small">' . e($j['posted_by'] . ' · ' . fp_fmt_dt($j['posted_at'])) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</section>';
}

fp_layout_end();

/** The statutory filings due in the next 15 days, if the viewer may see them. */
function fp_filings_upcoming_safe(): array
{
    if (!fp_can('tax.view')) {
        return [];
    }
    try {
        return array_slice(fabo_filings_upcoming(15, fp_org()), 0, 5);
    } catch (Throwable $e) {
        return [];
    }
}

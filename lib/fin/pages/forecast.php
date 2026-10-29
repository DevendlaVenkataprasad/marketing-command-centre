<?php
/** Cash position now, and the forecast for the coming weeks. A management estimate, built from what is due in and out. */

require_once __DIR__ . '/../../charts.php';

$entity = (int) ($_GET['entity'] ?? 0) ?: null;
$weeks = max(4, min(26, (int) ($_GET['weeks'] ?? 13)));
$fc = fp_cash_forecast($weeks, $entity);
$pos = fp_cash_position(null, $entity ? ['entity' => $entity] : []);
fp_layout_start('Cash forecast', 'Management estimate: today\'s cash, plus customer invoices by due date, less bills, approved claims and payments in progress. Overdue items are assumed to clear in the first week.');
echo '<section class="card fp-filtercard"><form method="get" class="fp-filters"><input type="hidden" name="p" value="forecast">'
   . (count(fp_entity_options()) > 1 ? '<label><span>Entity</span>' . inc_select('entity', fp_entity_options(), $entity, 'All') . '</label>' : '')
   . '<label><span>Weeks</span>' . inc_select('weeks', [8 => '8', 13 => '13', 26 => '26'], $weeks) . '</label><button class="btn btn-primary">Apply</button></form></section>';
echo '<div class="inc-grid2 wide-left"><section class="card"><h2>Week by week</h2><div class="chartgrid">';
fabo_chart_line('Projected cash at the end of each week', array_map(static fn($w) => date('d M', strtotime($w['from'])), $fc['weeks']), [
    ['name' => 'Cash', 'color' => 'var(--s1)', 'values' => array_column($fc['weeks'], 'closing')],
], 'money');
fabo_chart_bar('Expected in and out', array_map(static fn($w) => date('d M', strtotime($w['from'])), $fc['weeks']), [
    ['name' => 'In', 'color' => 'var(--s3)', 'values' => array_column($fc['weeks'], 'in')],
    ['name' => 'Out', 'color' => 'var(--s2)', 'values' => array_column($fc['weeks'], 'out')],
], 'money');
echo '</div><div class="scrollx"><table class="data inc-table stack"><thead><tr><th>Week from</th><th class="num">Expected in</th><th class="num">Expected out</th><th class="num">Closing cash</th></tr></thead><tbody>'
   . '<tr class="fp-sub"><td>Cash and bank today</td><td></td><td></td>' . fp_money_td($fc['opening']) . '</tr>';
foreach ($fc['weeks'] as $w) {
    echo '<tr><td data-l="Week from">' . e(fp_fmt_date($w['from'])) . '</td>' . fp_money_td($w['in'] ?: null, 'In') . fp_money_td($w['out'] ?: null, 'Out')
       . '<td class="num' . ($w['closing'] < 0 ? ' neg late' : '') . '" data-l="Closing">' . e(fp_money($w['closing'])) . '</td></tr>';
}
echo '</tbody></table></div></section><section class="card"><h2>Cash position now</h2><table class="data inc-table"><tbody>';
foreach ($pos as $b) {
    echo '<tr><td><a href="' . e(fp_url('banks', ['bank' => $b['id']])) . '">' . e($b['name']) . '</a> <span class="muted small">' . e($b['kind']) . '</span></td>' . fp_money_td($b['kind'] === 'card' ? -$b['balance'] : $b['balance']) . '</tr>';
}
echo '<tr class="fp-total"><td>Cash and bank</td>' . fp_money_td($fc['opening']) . '</tr></tbody></table>'
   . '<h3 class="fp-h3">Liquidity</h3>' . fp_facts([
       'Receivables due in 30 days' => e(fp_money(array_sum(array_map(static fn($i) => !$i['disputed'] && $i['due_date'] <= date('Y-m-d', strtotime('+30 day')) ? $i['open_base'] : 0, fp_ar_open())))),
       'Payables due in 30 days' => e(fp_money(array_sum(array_map(static fn($b) => ($b['scheduled_on'] ?: $b['due_date']) <= date('Y-m-d', strtotime('+30 day')) ? $b['open'] * (float) $b['fx_rate'] : 0, fp_bills_payable())))),
       'Lowest projected balance' => e(fp_money(min(array_merge([$fc['opening']], array_column($fc['weeks'], 'closing'))))),
   ]) . '</section></div>';
fp_layout_end();

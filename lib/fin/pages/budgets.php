<?php
/** Budgets and forecasts: the list, budget vs actual, scenarios side by side, and transfers. */

$fy = (int) ($_GET['fy'] ?? fp_fy_of());
$tab = in_array($_GET['tab'] ?? '', ['list', 'bva', 'compare', 'transfers'], true) ? $_GET['tab'] : 'list';
fp_layout_start('Budgets & forecasts', 'FY ' . e(fp_fy_label($fy)) . ' · thresholds: warn at ' . e(fp_setting('budget_warn_pct')) . '%, ' . (fp_setting('budget_control') === 'block' ? 'stop' : 'flag') . ' at ' . e(fp_setting('budget_block_pct')) . '%',
                (fp_can('budget.prepare') ? '<a class="btn btn-primary" href="' . e(fp_url('budget', ['new' => 1, 'fy' => $fy])) . '">+ New budget or forecast</a>' : ''));
echo '<nav class="subtabs">';
foreach (['list' => 'Budgets', 'bva' => 'Budget vs actual', 'compare' => 'Scenarios', 'transfers' => 'Transfers'] as $k => $l) {
    echo '<a href="' . e(fp_url('budgets', ['tab' => $k, 'fy' => $fy])) . '"' . ($tab === $k ? ' class="on"' : '') . '>' . e($l) . '</a>';
}
echo '</nav><form method="get" class="inc-row"><input type="hidden" name="p" value="budgets"><input type="hidden" name="tab" value="' . e($tab) . '">' . inc_field('Financial year', inc_select('fy', fp_fy_options(), $fy, '', 'data-autosubmit')) . '</form>';

if ($tab === 'list') {
    $rows = fp_all('SELECT * FROM fp_budgets WHERE fy = ? ORDER BY scenario = "budget" DESC, id DESC', 'i', [$fy]);
    if (!$rows) {
        echo inc_empty('No budgets for FY ' . fp_fy_label($fy) . '.', fp_can('budget.prepare') ? 'Prepare one with department, cost-centre and project lines, phased by month.' : '');
    } else {
        echo '<div class="scrollx"><table class="data inc-table stack"><thead><tr><th>Budget</th><th>Name</th><th>Kind</th><th>Scenario</th><th class="num">Total</th><th>Status</th><th>Prepared by</th></tr></thead><tbody>';
        foreach ($rows as $b) {
            echo '<tr><td data-l="Budget"><a href="' . e(fp_url('budget', ['id' => $b['id']])) . '"><strong>' . e($b['number']) . '</strong></a>' . ($b['revision'] ? ' <span class="muted small">rev ' . (int) $b['revision'] . '</span>' : '') . '</td>'
               . '<td data-l="Name">' . e($b['name']) . '</td><td data-l="Kind">' . e(ucfirst($b['kind'])) . '</td><td data-l="Scenario">' . e(fp_budget_scenarios()[$b['scenario']] ?? $b['scenario']) . '</td>'
               . fp_money_td($b['total'], 'Total') . '<td data-l="Status">' . fp_badge(fp_budget_statuses(), $b['status']) . '</td><td data-l="Prepared by" class="small">' . e($b['prepared_by']) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
} elseif ($tab === 'bva') {
    $f = ['fy' => $fy, 'from' => fp_fy_range($fy)[0], 'to' => min(fp_fy_range($fy)[1], date('Y-m-d'))] + fp_report_filters($_GET + ['fy' => $fy]);
    $f['fy'] = $fy;
    echo '<section class="card fp-filtercard">' . fp_filters('budgets', ['from', 'to', 'entity', 'cc', 'project'], $f, [], ['tab' => 'bva', 'fy' => $fy]) . '</section>';
    $rep = fp_report_run('bva', $f);
    echo '<section class="card"><div class="inc-cardhead"><h2>' . e($rep['sub']) . '</h2>'
       . (fp_can('reports.export') ? '<span><a class="btn btn-sm" href="' . e(fp_url('reports', ['r' => 'bva', 'export' => 'xlsx'] + array_filter($f))) . '">Excel</a></span>' : '') . '</div>' . fp_report_html($rep)
       . '<p class="muted small">' . e($rep['basis']) . ' Available = budget − actual − committed. Each posting counts once, against the most specific line.</p></section>';
} elseif ($tab === 'compare') {
    $c = fp_budget_compare($fy);
    if (!$c['docs']) {
        echo inc_empty('Nothing to compare.', 'Prepare a budget and a forecast (or best and worst cases) for the same year.');
    } else {
        $actual = [];
        [$from, $to] = fp_fy_range($fy);
        foreach (fp_balances(['from' => $from, 'to' => min($to, date('Y-m-d')), 'exclude_closing' => 1]) as $aid => [$dr, $cr]) {
            $a = fp_account($aid);
            $actual[$a['code'] . ' ' . $a['name']] = fp_natural($a['type'], $dr, $cr);
        }
        echo '<div class="scrollx"><table class="data inc-table stack"><thead><tr><th>Account</th>';
        foreach ($c['docs'] as $d) {
            echo '<th class="num"><a href="' . e(fp_url('budget', ['id' => $d['id']])) . '">' . e($d['name']) . '</a><div class="muted small">' . e(fp_budget_scenarios()[$d['scenario']] ?? $d['scenario']) . ' · ' . e($d['status']) . '</div></th>';
        }
        echo '<th class="num">Actual to date</th></tr></thead><tbody>';
        $tot = [];
        foreach ($c['rows'] as $acc => $vals) {
            echo '<tr><td data-l="Account">' . e($acc) . '</td>';
            foreach ($c['docs'] as $d) {
                $v = $vals[(int) $d['id']] ?? null;
                $tot[(int) $d['id']] = ($tot[(int) $d['id']] ?? 0) + (float) $v;
                echo fp_money_td($v, $d['name']);
            }
            echo fp_money_td($actual[$acc] ?? null, 'Actual') . '</tr>';
        }
        echo '<tr class="fp-total"><td>Total</td>' . implode('', array_map(static fn($d) => fp_money_td($tot[(int) $d['id']] ?? 0), $c['docs'])) . '<td></td></tr></tbody></table></div>';
        foreach ($c['docs'] as $d) {
            if ($d['assumptions']) {
                echo '<section class="card"><h3 class="fp-h3">Assumptions — ' . e($d['name']) . '</h3><p class="small">' . nl2br(e($d['assumptions'])) . '</p></section>';
            }
        }
    }
} else {
    $rows = fp_all('SELECT t.*, b.number AS bnum, af.code fcode, at.code tcode, cf.code fcc, ct.code tcc FROM fp_budget_transfers t JOIN fp_budgets b ON b.id = t.budget_id
                     JOIN fp_budget_lines lf ON lf.id = t.from_line_id JOIN fp_budget_lines lt ON lt.id = t.to_line_id JOIN fp_accounts af ON af.id = lf.account_id JOIN fp_accounts at ON at.id = lt.account_id
                     LEFT JOIN fp_cost_centres cf ON cf.id = lf.cost_centre_id LEFT JOIN fp_cost_centres ct ON ct.id = lt.cost_centre_id WHERE b.fy = ? ORDER BY t.id DESC', 'i', [$fy]);
    if (!$rows) {
        echo inc_empty('No transfers in FY ' . fp_fy_label($fy) . '.', 'Transfers are made from an approved budget\'s page.');
    } else {
        echo '<div class="scrollx"><table class="data inc-table stack"><thead><tr><th>Transfer</th><th>Budget</th><th>From</th><th>To</th><th>Month</th><th class="num">Amount</th><th>Reason</th><th>Status</th></tr></thead><tbody>';
        foreach ($rows as $t) {
            echo '<tr><td data-l="Transfer">' . e($t['number']) . '</td><td data-l="Budget"><a href="' . e(fp_url('budget', ['id' => $t['budget_id']])) . '">' . e($t['bnum']) . '</a></td>'
               . '<td data-l="From">' . e($t['fcode'] . ($t['fcc'] ? '/' . $t['fcc'] : '')) . '</td><td data-l="To">' . e($t['tcode'] . ($t['tcc'] ? '/' . $t['tcc'] : '')) . '</td>'
               . '<td data-l="Month">' . e(date('M Y', strtotime(fp_fy_month($fy, (int) $t['month_no']) . '-01'))) . '</td>' . fp_money_td($t['amount'], 'Amount') . '<td data-l="Reason">' . e($t['reason']) . '</td>'
               . '<td data-l="Status">' . fp_badge(['submitted' => ['Awaiting approval', 'amber'], 'approved' => ['Applied', 'green'], 'rejected' => ['Rejected', 'red'], 'cancelled' => ['Withdrawn', ''], 'draft' => ['Draft', '']], $t['status'])
               . ($t['status'] === 'submitted' ? ' <a class="small" href="' . e(fp_url('transfer', ['id' => $t['id']])) . '">review</a>' : '') . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
}
fp_layout_end();

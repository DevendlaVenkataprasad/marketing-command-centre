<?php
/**
 * One budget or forecast: lines by account, cost centre and project, phased
 * by month. Prepared, approved, frozen and released; changed by a transfer
 * or a revision; compared with actual and committed spend.
 */

$id = (int) ($_GET['id'] ?? 0);
$b = $id ? fp_budget($id) : null;
if ($id && !$b) {
    fabo_deny('That budget does not exist.', 404);
}
if ($b && !fp_doc_can_view('budget', $b)) {
    fabo_deny('You do not have access to budgets.', 403);
}
$back = $b ? fp_url('budget', ['id' => $id]) : fp_url('budgets');
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    if ($b && ($x = fp_wf_post('budget', $id, $back))) {
        $flash = $x;
    } elseif ($b && ($x = fp_files_post('budget', $id, $back, fp_can('budget.prepare')))) {
        $flash = $x;
    } elseif ($a === 'save' || $a === 'save_submit') {
        $flash = fp_action(static function () use ($b, $a) {
            if (!$b) {
                fp_nonce_check();
            }
            $nid = fp_budget_save($b ? (int) $b['id'] : null, $_POST);
            if ($a === 'save_submit') {
                fp_submit_or_draft(static fn() => fp_budget_submit($nid, fp_budget($nid)['version']), fp_url('budget', ['id' => $nid]));
            }
            inc_redirect(fp_url('budget', ['id' => $nid]), ['ok', 'Draft saved.']);
        }, $back);
    } elseif ($b && $a === 'submit') {
        $flash = fp_action(static fn() => fp_budget_submit($id, $_POST['version'] ?? 0), $back);
    } elseif ($b && ($a === 'freeze' || $a === 'release')) {
        $flash = fp_action(static fn() => fp_budget_freeze($id, $a === 'freeze', (string) ($_POST['note'] ?? ''), $_POST['version'] ?? 0), $back);
    } elseif ($b && $a === 'revise') {
        $flash = fp_action(static function () use ($id) {
            $nid = fp_budget_revise($id, (string) ($_POST['reason'] ?? ''));
            inc_redirect(fp_url('budget', ['id' => $nid, 'edit' => 1]), ['ok', 'Revision started as a draft. It replaces the current budget once approved.']);
        }, $back);
    } elseif ($b && $a === 'transfer') {
        $flash = fp_action(static function () use ($id) {
            $tid = fp_transfer_create(['budget_id' => $id] + $_POST);
            return 'Transfer requested; it applies once approved.';
        }, $back);
    }
}

$editable = fp_can('budget.prepare') && (!$b || $b['status'] === 'draft');
if ($editable && (!$b || isset($_GET['edit']) || $flash)) {
    $v = $b ?? ['fy' => (int) ($_GET['fy'] ?? fp_fy_of()), 'name' => '', 'kind' => 'operating', 'scenario' => 'budget', 'entity_id' => 1, 'assumptions' => ''];
    $lines = $b ? fp_budget_lines($id) : [];
    if ($flash && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $v = $_POST + $v;
        $lines = array_values((array) ($_POST['lines'] ?? []));
    }
    for ($k = 0; $k < 3; $k++) {
        $lines[] = [];
    }
    $fy = (int) $v['fy'];
    $months = array_map(static fn($n) => date('M', strtotime(fp_fy_month($fy, $n) . '-01')), range(1, 12));
    fp_layout_start($b ? 'Edit ' . $b['name'] : 'New budget or forecast', 'Give each line an annual figure (spread evenly) or month by month.', '', ['Budgets' => fp_url('budgets', ['fy' => $fy]), $b ? $b['number'] : 'New' => null], $flash);
    if ($b && $b['return_note']) {
        echo '<div class="inc-warn"><strong>Returned for correction:</strong> ' . e($b['return_note']) . '</div>';
    }
    $accs = $v['kind'] === 'capital' ? fp_account_options(['asset']) : fp_account_options(['expense', 'income']);
    echo '<section class="card">' . inc_form_open('', true) . ($b ? '<input type="hidden" name="version" value="' . (int) $b['version'] . '">' : fp_nonce_field())
       . '<div class="inc-row">' . inc_field('Name', '<input name="name" maxlength="160" required value="' . e($v['name']) . '" placeholder="e.g. Operating budget FY ' . e(fp_fy_label($fy)) . '">', '', true, 'grow')
       . inc_field('Financial year', inc_select('fy', fp_fy_options(1, 2), $fy)) . inc_field('Kind', inc_select('kind', ['operating' => 'Operating (income and expenses)', 'capital' => 'Capital expenditure'], $v['kind']))
       . inc_field('Scenario', inc_select('scenario', fp_budget_scenarios(), $v['scenario']))
       . (count(fp_entity_options()) > 1 ? inc_field('Entity', inc_select('entity_id', fp_entity_options(), $v['entity_id'])) : '<input type="hidden" name="entity_id" value="' . (int) $v['entity_id'] . '">') . '</div>'
       . '<p class="muted small">Change the kind and save to see capital accounts. Only the "Budget" scenario is used for control; the others are for comparison.</p>'
       . '<div class="scrollx"><table class="data inc-table fp-lines fp-budlines" data-lines="budget"><thead><tr><th>Account</th><th>Cost centre</th><th>Project</th><th class="num">Annual</th>';
    foreach ($months as $m) {
        echo '<th class="num">' . e($m) . '</th>';
    }
    echo '<th></th></tr></thead><tbody>';
    foreach ($lines as $i => $l) {
        $n = 'lines[' . $i . ']';
        echo '<tr><td>' . inc_select($n . '[account_id]', $accs, $l['account_id'] ?? '', 'Choose…') . '</td><td>' . inc_select($n . '[cost_centre_id]', fp_cc_options(), $l['cost_centre_id'] ?? '', 'All') . '</td>'
           . '<td>' . inc_select($n . '[project_id]', fp_project_options(), $l['project_id'] ?? '', '—') . '</td>'
           . '<td class="num"><input name="' . $n . '[annual]" size="9" inputmode="decimal" placeholder="spread" value="' . e(isset($l['annual']) ? (string) $l['annual'] : '') . '"></td>';
        for ($m = 1; $m <= 12; $m++) {
            echo '<td class="num"><input name="' . $n . '[m' . $m . ']" size="7" inputmode="decimal" value="' . e(isset($l['m' . $m]) && (float) $l['m' . $m] != 0 ? fp_amt($l['m' . $m]) : '') . '"></td>';
        }
        echo '<td><button type="button" class="btn btn-sm" data-row-del>×</button></td></tr>';
    }
    echo '</tbody></table></div><button type="button" class="btn btn-sm" data-row-add>+ Add line</button>'
       . inc_field('Assumptions', '<textarea name="assumptions" rows="3" maxlength="5000" placeholder="Headcount, prices, growth, timing — what the figures rest on">' . e($v['assumptions'] ?? '') . '</textarea>')
       . '<div class="inc-formfoot"><button class="btn" name="action" value="save">Save draft</button> <button class="btn btn-primary" name="action" value="save_submit">Submit for approval</button> <a class="btn" href="' . e($back) . '">Cancel</a></div></form></section>';
    fp_layout_end();
    return;
}
if (!$b) {
    fabo_deny('You cannot prepare budgets.', 403);
}

$fy = (int) $b['fy'];
$lines = fp_budget_lines($id);
$bva = [];
if (in_array($b['status'], ['approved', 'frozen'], true) && $b['scenario'] === 'budget') {
    foreach (fp_budget_vs_actual($fy) as $l) {
        if ((int) $l['budget_id'] === $id) {
            $bva[(int) $l['id']] = $l;
        }
    }
}
$acts = '';
if (fp_writable()) {
    if ($b['status'] === 'draft' && fp_can('budget.prepare')) {
        $acts .= '<a class="btn" href="' . e(fp_url('budget', ['id' => $id, 'edit' => 1])) . '">Edit</a> ' . inc_button('Submit for approval', ['action' => 'submit', 'version' => $b['version']], 'btn btn-primary') . ' ';
    }
    if ($b['status'] === 'approved' && fp_can('budget.control')) {
        $acts .= inc_button('Freeze', ['action' => 'freeze', 'version' => $b['version']], 'btn', 'Freeze this budget? No transfers or revisions until it is released.') . ' ';
    }
    if ($b['status'] === 'frozen' && fp_can('budget.control')) {
        $acts .= inc_button('Release', ['action' => 'release', 'version' => $b['version']], 'btn') . ' ';
    }
}
fp_layout_start($b['name'], e($b['number']) . ' · FY ' . e(fp_fy_label($fy)) . ' · ' . e(fp_budget_scenarios()[$b['scenario']] ?? $b['scenario']) . ($b['revision'] ? ' · revision ' . (int) $b['revision'] : ''), $acts,
                ['Budgets' => fp_url('budgets', ['fy' => $fy]), $b['number'] => null], $flash);
if (in_array($b['status'], ['rejected'], true) && $b['return_note']) {
    echo '<div class="inc-warn"><strong>Rejected:</strong> ' . e($b['return_note']) . '</div>';
}
echo '<section class="card">' . fp_facts([
    'Status' => fp_badge(fp_budget_statuses(), $b['status']), 'Kind' => e(ucfirst($b['kind'])), 'Total' => e(fp_money($b['total'])),
    'Revises' => $b['parent_id'] ? '<a href="' . e(fp_url('budget', ['id' => $b['parent_id']])) . '">' . e(fp_budget((int) $b['parent_id'])['number']) . '</a>' : '',
    'Approved by' => $b['approved_by'] ? e($b['approved_by'] . ' · ' . fp_fmt_dt($b['approved_at'])) : '', 'Prepared by' => e($b['prepared_by'] . ' · ' . fp_fmt_dt($b['created_at'])),
    'Assumptions' => nl2br(e($b['assumptions'] ?? '')),
]) . '</section>';

$months = array_map(static fn($n) => date('M', strtotime(fp_fy_month($fy, $n) . '-01')), range(1, 12));
echo '<section class="card"><h2>Lines</h2><div class="scrollx"><table class="data inc-table fp-budlines"><thead><tr><th>Account</th><th>Cost centre</th>';
foreach ($months as $m) {
    echo '<th class="num">' . e($m) . '</th>';
}
echo '<th class="num">Year</th>' . ($bva ? '<th class="num">Actual</th><th class="num">Committed</th><th class="num">Available (year)</th><th class="num">Used</th>' : '') . '</tr></thead><tbody>';
foreach ($lines as $l) {
    echo '<tr><td>' . e($l['account_code'] . ' ' . $l['account_name']) . ($l['project_code'] ? ' <span class="muted small">' . e($l['project_code']) . '</span>' : '') . '</td><td>' . e($l['cc_code'] ?? 'All') . '</td>';
    for ($m = 1; $m <= 12; $m++) {
        echo '<td class="num small">' . ((float) $l['m' . $m] ? e(number_format((float) $l['m' . $m], 0)) : '') . '</td>';
    }
    echo fp_money_td($l['total']);
    if ($bva) {
        $x = $bva[(int) $l['id']] ?? null;
        $avail = $x ? fp_r2((float) $l['total'] - $x['actual'] - $x['committed']) : null;
        $util = $x && (float) $l['total'] > 0 ? ($x['actual'] + $x['committed']) / (float) $l['total'] : null;
        echo fp_money_td($x['actual'] ?? null) . fp_money_td($x['committed'] ?? null) . '<td class="num' . ($avail !== null && $avail < 0 ? ' neg late' : '') . '">' . e(fp_money($avail)) . '</td>'
           . '<td class="num' . ($util !== null && $util * 100 >= (float) fp_setting('budget_warn_pct') ? ' late' : '') . '">' . e(fp_pct($util)) . '</td>';
    }
    echo '</tr>';
}
echo '</tbody></table></div></section>';

if ($b['status'] === 'approved' && fp_can('budget.prepare') && fp_writable() && count($lines) > 1) {
    $opts = [];
    foreach ($lines as $l) {
        $opts[(int) $l['id']] = $l['account_code'] . ' ' . $l['account_name'] . ' / ' . ($l['cc_code'] ?? 'All');
    }
    $mo = [];
    foreach (range(1, 12) as $n) {
        $mo[$n] = date('F Y', strtotime(fp_fy_month($fy, $n) . '-01'));
    }
    echo '<div class="inc-grid2"><section class="card"><h2>Transfer between lines</h2>' . inc_form_open() . '<input type="hidden" name="action" value="transfer"><div class="inc-row">'
       . inc_field('From', inc_select('from_line_id', $opts, '', 'Choose…', 'required'), '', true) . inc_field('To', inc_select('to_line_id', $opts, '', 'Choose…', 'required'), '', true)
       . inc_field('Month', inc_select('month_no', $mo, fp_fy_month_no(date('Y-m-d')))) . inc_field('Amount', '<input name="amount" inputmode="decimal" required>', '', true) . '</div>'
       . inc_field('Reason', '<input name="reason" maxlength="500" required>', '', true) . '<button class="btn">Request transfer</button></form></section>'
       . '<section class="card"><h2>Revise</h2><p class="muted small">A revision is a new draft copy; once approved it replaces this budget, which is kept as superseded.</p>'
       . inc_form_open() . '<input type="hidden" name="action" value="revise"><input name="reason" maxlength="500" required placeholder="Why the budget is being revised"><button class="btn">Start revision</button></form></section></div>';
}
echo fp_wf_panel('budget', $b, $back);
echo fp_files_panel('budget', $b, fp_can('budget.prepare'), $back);
echo fp_audit_panel('budget', $id);
fp_layout_end();

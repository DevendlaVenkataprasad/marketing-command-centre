<?php
/** One budget transfer, with its approval. */

$id = (int) ($_GET['id'] ?? 0);
$t = fp_doc('transfer', $id);
if (!$t) {
    fabo_deny('That transfer does not exist.', 404);
}
$back = fp_url('transfer', ['id' => $id]);
$flash = fp_wf_post('transfer', $id, $back);
$b = fp_budget((int) $t['budget_id']);
$lf = fp_one('SELECT l.*, a.code, a.name, c.code cc FROM fp_budget_lines l JOIN fp_accounts a ON a.id = l.account_id LEFT JOIN fp_cost_centres c ON c.id = l.cost_centre_id WHERE l.id = ?', 'i', [(int) $t['from_line_id']]);
$lt = fp_one('SELECT l.*, a.code, a.name, c.code cc FROM fp_budget_lines l JOIN fp_accounts a ON a.id = l.account_id LEFT JOIN fp_cost_centres c ON c.id = l.cost_centre_id WHERE l.id = ?', 'i', [(int) $t['to_line_id']]);
$month = date('F Y', strtotime(fp_fy_month((int) $b['fy'], (int) $t['month_no']) . '-01'));
fp_layout_start('Budget transfer ' . $t['number'], e(fp_money($t['amount'])) . ' in ' . e($month), '', ['Budgets' => fp_url('budgets', ['tab' => 'transfers', 'fy' => $b['fy']]), $t['number'] => null], $flash);
echo '<section class="card">' . fp_facts([
    'Status' => fp_badge(['submitted' => ['Awaiting approval', 'amber'], 'approved' => ['Applied', 'green'], 'rejected' => ['Rejected', 'red'], 'cancelled' => ['Withdrawn', ''], 'draft' => ['Draft', '']], $t['status']),
    'Budget' => '<a href="' . e(fp_url('budget', ['id' => $b['id']])) . '">' . e($b['number'] . ' ' . $b['name']) . '</a>',
    'From' => e($lf['code'] . ' ' . $lf['name'] . ' / ' . ($lf['cc'] ?? 'All')) . ' — ' . e(fp_money($lf['m' . (int) $t['month_no']])) . ' in ' . e($month) . ' now',
    'To' => e($lt['code'] . ' ' . $lt['name'] . ' / ' . ($lt['cc'] ?? 'All')) . ' — ' . e(fp_money($lt['m' . (int) $t['month_no']])) . ' now',
    'Amount' => e(fp_money($t['amount'])), 'Reason' => e($t['reason']), 'Requested by' => e($t['prepared_by'] . ' · ' . fp_fmt_dt($t['created_at'])),
]) . '</section>';
echo fp_wf_panel('transfer', $t, $back);
echo fp_audit_panel('transfer', $id);
fp_layout_end();

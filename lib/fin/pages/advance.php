<?php
/** One travel advance: asked for, approved, paid through Treasury, settled against the trip's claim. */

$id = (int) ($_GET['id'] ?? 0);
$a = fp_doc('advance', $id);
if (!$a) {
    fabo_deny('That advance does not exist.', 404);
}
if (!fp_doc_can_view('advance', $a)) {
    fabo_deny('That advance is not yours to see.', 403);
}
$back = fp_url('advance', ['id' => $id]);
$flash = fp_wf_post('advance', $id, $back);
$st = ['submitted' => ['Waiting for approval', 'amber'], 'approved' => ['Approved — to be paid', 'blue'], 'paid' => ['Paid — settle it with your claim', 'amber'],
       'settled' => ['Settled', 'green'], 'rejected' => ['Rejected', 'red'], 'cancelled' => ['Cancelled', ''], 'draft' => ['Draft', '']];
$acts = $a['status'] === 'approved' && fp_can('pay.prepare') ? '<a class="btn btn-primary" href="' . e(fp_url('payment', ['new' => 'advance'])) . '">Pay the advance</a>' : '';
if ($a['status'] === 'paid' && (int) $a['employee_person_id'] === fp_me()) {
    $acts .= '<a class="btn btn-primary" href="' . e(fp_url('claim', ['new' => 1])) . '">Claim the trip\'s expenses</a>';
}
fp_layout_start('Travel advance ' . $a['number'], e($a['employee_name']) . ' · ' . e(fp_money($a['amount'])), $acts, ['My finance' => fp_url('me'), $a['number'] => null], $flash);
$why = in_array($a['status'], ['rejected', 'cancelled'], true) ? fp_wf_last_reason('advance', $id) : null;
echo '<section class="card">' . fp_facts([
    'Status' => fp_badge($st, $a['status']), 'Purpose' => e($a['purpose']), 'Amount' => e(fp_money($a['amount'])), 'Needed by' => e(fp_fmt_date($a['needed_by'])),
    'Paid' => e(fp_money($a['paid_amount'])), 'Settled by claims' => e(fp_money($a['settled_amount'])), 'Reason' => $why ? e($why['actor'] . ': ' . $why['comment']) : '',
]) . '</section>';
echo fp_wf_panel('advance', $a, $back);
echo fp_audit_panel('advance', $id);
fp_layout_end();

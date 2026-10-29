<?php
/** One payment batch: authorised as a whole, released as one bank upload file, completed payment by payment. */

$id = (int) ($_GET['id'] ?? 0);
$b = fp_batch($id);
if (!$b) {
    fabo_deny('That batch does not exist.', 404);
}
if (!fp_doc_can_view('batch', $b)) {
    fabo_deny('You do not have access to payments.', 403);
}
$back = fp_url('batch', ['id' => $id]);
if (!empty($_GET['file'])) {
    $rows = fp_batch_file($id);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9]+/', '-', $b['number']) . '-bank-upload.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    foreach ($rows as $r) {
        fputcsv($out, $r);
    }
    fclose($out);
    exit;
}
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    if ($x = fp_wf_post('batch', $id, $back)) {
        $flash = $x;
    } elseif ($a === 'submit') {
        $flash = fp_action(static fn() => fp_batch_submit($id, $_POST['version'] ?? 0), $back);
    } elseif ($a === 'release') {
        $flash = fp_action(static function () use ($id) {
            $n = fp_payment_release([], $id);
            return $n . ' payment(s) released. Download the bank file, upload it to your bank, then record each result.';
        }, $back);
    } elseif ($a === 'remove') {
        $flash = fp_action(static function () use ($id, $b) {
            fp_need('pay.prepare');
            if ($b['status'] !== 'draft') {
                throw new InvalidArgumentException('Payments can be taken out of a draft batch only.');
            }
            fp_exec('UPDATE fp_payments SET batch_id = NULL WHERE id = ? AND batch_id = ?', 'ii', [(int) ($_POST['pid'] ?? 0), $id]);
            fp_batch_refresh($id);
            return 'Taken out of the batch.';
        }, $back);
    }
}
$b = fp_batch($id);
$pays = fp_all('SELECT * FROM fp_payments WHERE batch_id = ? ORDER BY id', 'i', [$id]);
$bst = ['draft' => ['Draft', ''], 'submitted' => ['Awaiting authorisation', 'amber'], 'authorized' => ['Authorised', 'blue'], 'released' => ['Sent to bank', 'blue'],
        'completed' => ['Completed', 'green'], 'partly_failed' => ['Partly failed', 'red'], 'rejected' => ['Rejected', 'red'], 'cancelled' => ['Cancelled', '']];
$acts = '';
if (fp_writable()) {
    if ($b['status'] === 'draft' && fp_can('pay.prepare') && $pays) {
        $acts .= inc_button('Submit for authorisation', ['action' => 'submit', 'version' => $b['version']], 'btn btn-primary');
    }
    if ($b['status'] === 'authorized' && fp_can('pay.execute')) {
        $acts .= inc_button('Release to bank', ['action' => 'release'], 'btn btn-primary', 'Release every payment in this batch to the bank?');
    }
    if (in_array($b['status'], ['released', 'completed', 'partly_failed'], true) && fp_can('pay.execute')) {
        $acts .= '<a class="btn" href="' . e(fp_url('batch', ['id' => $id, 'file' => 1])) . '">Download bank file (CSV)</a>';
    }
}
fp_layout_start('Payment batch ' . $b['number'], count($pays) . ' payments · ' . e(fp_money($b['total'])) . ' · from ' . e(fp_bank_account((int) $b['bank_account_id'])['name']), $acts,
                ['Payments' => fp_url('payments', ['tab' => 'batches']), $b['number'] => null], $flash);
echo fp_steps_bar(['draft' => 'Prepared', 'submitted' => 'Authorisation', 'authorized' => 'Authorised', 'released' => 'Sent to bank', 'completed' => 'Completed'], $b['status']);
if ($b['status'] === 'released') {
    echo '<div class="inc-note">Upload the bank file to your bank\'s portal. No bank is connected to this portal, so each payment stays "sent to bank" until its reference is recorded — open each one to mark it completed or failed.</div>';
}
echo '<section class="card"><p>' . fp_badge($bst, $b['status']) . ' · pay on ' . e(fp_fmt_date($b['pay_date'])) . ' · prepared by ' . e($b['prepared_by']) . ($b['released_by'] ? ' · released by ' . e($b['released_by'] . ' ' . fp_fmt_dt($b['released_at'])) : '') . '</p>'
   . '<div class="scrollx"><table class="data inc-table stack"><thead><tr><th>Payment</th><th>Payee</th><th>Purpose</th><th class="num">Amount</th><th>Status</th><th>Bank ref</th><th></th></tr></thead><tbody>';
foreach ($pays as $p) {
    echo '<tr><td data-l="Payment"><a href="' . e(fp_url('payment', ['id' => $p['id']])) . '">' . e($p['number']) . '</a></td><td data-l="Payee">' . e($p['payee_name']) . '</td><td data-l="Purpose">' . e($p['purpose']) . '</td>'
       . fp_money_td($p['amount'], 'Amount') . '<td data-l="Status">' . fp_badge(fp_payment_statuses(), $p['status']) . '</td><td data-l="Bank ref" class="small">' . e($p['bank_ref'] ?? ($p['failure_reason'] ?? '')) . '</td><td>'
       . ($b['status'] === 'draft' && fp_can('pay.prepare') ? inc_button('Take out', ['action' => 'remove', 'pid' => $p['id']], 'btn btn-sm') : '') . '</td></tr>';
}
echo '</tbody></table></div></section>';
echo fp_wf_panel('batch', $b, $back);
echo fp_audit_panel('batch', $id);
fp_layout_end();

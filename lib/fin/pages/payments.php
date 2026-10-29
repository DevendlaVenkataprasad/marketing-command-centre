<?php
/**
 * Payments: the register; the payment proposal (bills, claims and advances
 * due, to prepare in one go and batch); and payment batches.
 */

$tab = in_array($_GET['tab'] ?? '', ['list', 'propose', 'batches'], true) ? $_GET['tab'] : 'list';
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'prepare') {
    $flash = fp_action(static function () {
        fp_need('pay.prepare');
        fp_nonce_check();
        $bank = (int) ($_POST['bank_account_id'] ?? 0);
        $date = (string) ($_POST['pay_date'] ?? date('Y-m-d'));
        $made = [];
        // One payment per vendor (and per employee), settling what was ticked.
        $byVendor = [];
        foreach ((array) ($_POST['bill'] ?? []) as $bid => $amt) {
            if (!isset($_POST['pick']['bill'][$bid])) {
                continue;
            }
            $b = fp_bill((int) $bid);
            if ($b) {
                $byVendor[(int) $b['vendor_id'] . '|' . $b['currency']][(int) $bid] = $amt;
            }
        }
        foreach ($byVendor as $k => $alloc) {
            [, $cur] = explode('|', $k);
            $made[] = fp_payment_create(['kind' => 'vendor', 'pay_date' => $date, 'bank_account_id' => $bank, 'currency' => $cur, 'method' => $_POST['method'] ?? 'neft',
                                         'purpose' => 'Payment of bills', 'alloc' => $alloc]);
        }
        foreach (['claim', 'advance'] as $kind) {
            foreach ((array) ($_POST[$kind] ?? []) as $did => $amt) {
                if (isset($_POST['pick'][$kind][$did])) {
                    $made[] = fp_payment_create(['kind' => $kind, 'pay_date' => $date, 'bank_account_id' => $bank, 'method' => $_POST['method'] ?? 'neft',
                                                 'purpose' => $kind === 'claim' ? 'Expense reimbursement' : 'Travel advance', 'alloc' => [(int) $did => $amt]]);
                }
            }
        }
        if (!$made) {
            throw new InvalidArgumentException('Tick what to pay.');
        }
        if (!empty($_POST['batch'])) {
            $bid = fp_batch_create(['bank_account_id' => $bank, 'pay_date' => $date, 'payment_ids' => $made]);
            inc_redirect(fp_url('batch', ['id' => $bid]), ['ok', count($made) . ' payment(s) prepared in a batch. Submit the batch for authorisation.']);
        }
        inc_redirect(fp_url('payments', ['status' => 'draft']), ['ok', count($made) . ' draft payment(s) prepared. Submit each for authorisation.']);
    }, fp_url('payments', ['tab' => 'propose']));
}

$acts = fp_can('pay.prepare') ? '<a class="btn" href="' . e(fp_url('payment', ['new' => 'tax'])) . '">+ Tax payment</a> <a class="btn btn-primary" href="' . e(fp_url('payments', ['tab' => 'propose'])) . '">Pay bills and claims</a>' : '';
if (fp_self_writable()) {
    $acts = '<a class="btn" href="' . e(fp_url('payment', ['new' => 'request'])) . '">+ Payment request</a> ' . $acts;
}
fp_layout_start('Payments', 'Prepared, authorised by someone else, released to the bank, and completed only when the bank\'s reference is recorded.', $acts, [], $flash);
echo '<nav class="subtabs">';
foreach (['list' => 'Payments', 'propose' => 'Payment proposal', 'batches' => 'Batches'] as $k => $l) {
    echo '<a href="' . e(fp_url('payments', ['tab' => $k])) . '"' . ($tab === $k ? ' class="on"' : '') . '>' . e($l) . '</a>';
}
echo '</nav>';

if ($tab === 'propose') {
    if (!fp_can('pay.prepare')) {
        echo inc_empty('Preparing payments needs the "prepare payments" permission.');
        fp_layout_end();
        return;
    }
    $by = fp_safe_date($_GET['by'] ?? '') ?? date('Y-m-d', strtotime('+14 day'));
    $bills = fp_bills_payable(null, $by);
    $claims = fp_all('SELECT * FROM fp_claims WHERE status = "approved" AND net_payable - paid_amount > 0.004 ORDER BY id');
    $advs = fp_all('SELECT * FROM fp_advances WHERE status = "approved" ORDER BY id');
    echo '<section class="card"><form method="get" class="inc-row"><input type="hidden" name="p" value="payments"><input type="hidden" name="tab" value="propose">'
       . inc_field('Due or scheduled by', '<input type="date" name="by" value="' . e($by) . '" data-autosubmit>') . '</form>'
       . inc_form_open() . fp_nonce_field() . '<input type="hidden" name="action" value="prepare">';
    if (!$bills && !$claims && !$advs) {
        echo '<p class="inc-allclear">Nothing is due to be paid by ' . e(fp_fmt_date($by)) . '.</p>';
    }
    if ($bills) {
        echo '<h3 class="fp-h3">Vendor bills</h3><table class="data inc-table stack"><thead><tr><th class="cb"><input type="checkbox" data-check-all="bill" aria-label="Tick all bills"></th><th>Bill</th><th>Vendor</th><th>Due</th><th class="num">Open</th><th class="num">Pay now</th></tr></thead><tbody>';
        foreach ($bills as $b) {
            $late = $b['due_date'] && $b['due_date'] < date('Y-m-d');
            echo '<tr><td class="cb"><input type="checkbox" name="pick[bill][' . (int) $b['id'] . ']" value="1" data-group="bill"' . ($b['vendor_status'] !== 'active' ? ' disabled title="Vendor not active"' : '') . '></td>'
               . '<td data-l="Bill"><a href="' . e(fp_url('bill', ['id' => $b['id']])) . '">' . e($b['number']) . '</a> <span class="muted small">' . e($b['bill_no']) . '</span></td><td data-l="Vendor">' . e($b['vendor'])
               . ($b['vendor_status'] !== 'active' ? ' ' . inc_badge(ucfirst($b['vendor_status']), 'red') : '') . '</td><td data-l="Due" class="' . ($late ? 'late' : '') . '">' . e(fp_fmt_date($b['scheduled_on'] ?: $b['due_date'])) . '</td>'
               . fp_money_td($b['open'], 'Open') . '<td class="num" data-l="Pay now"><input name="bill[' . (int) $b['id'] . ']" size="10" inputmode="decimal" value="' . e(fp_amt($b['open'])) . '"></td></tr>';
        }
        echo '</tbody></table>';
    }
    foreach (['claim' => [$claims, 'Expense claims to reimburse', 'net_payable'], 'advance' => [$advs, 'Approved travel advances', 'amount']] as $kind => [$rows, $title, $col]) {
        if (!$rows) {
            continue;
        }
        echo '<h3 class="fp-h3">' . e($title) . '</h3><table class="data inc-table stack"><tbody>';
        foreach ($rows as $d) {
            $due = fp_r2((float) $d[$col] - (float) $d['paid_amount']);
            echo '<tr><td class="cb"><input type="checkbox" name="pick[' . $kind . '][' . (int) $d['id'] . ']" value="1"></td><td data-l="Document"><a href="' . e(fp_url($kind, ['id' => $d['id']])) . '">' . e($d['number']) . '</a></td>'
               . '<td data-l="Employee">' . e($d['employee_name']) . '</td><td data-l="Purpose">' . e(mb_strimwidth($d['purpose'], 0, 60, '…')) . '</td>' . fp_money_td($due, 'Due')
               . '<td class="num"><input name="' . $kind . '[' . (int) $d['id'] . ']" size="10" value="' . e(fp_amt($due)) . '"></td></tr>';
        }
        echo '</tbody></table>';
    }
    if ($bills || $claims || $advs) {
        echo '<div class="inc-row">' . inc_field('Pay from', inc_select('bank_account_id', fp_bank_options(), '', 'Choose…', 'required'), '', true)
           . inc_field('Pay on', '<input type="date" name="pay_date" value="' . e(date('Y-m-d')) . '" required>', '', true)
           . inc_field('Method', inc_select('method', ['neft' => 'NEFT', 'rtgs' => 'RTGS', 'imps' => 'IMPS', 'upi' => 'UPI', 'cheque' => 'Cheque', 'cash' => 'Cash'], 'neft'))
           . '<label class="check"><input type="checkbox" name="batch" value="1" checked> Put them in one batch (one authorisation, one bank file)</label></div>'
           . '<button class="btn btn-primary">Prepare payments</button>';
    }
    echo '</form></section>';
    fp_layout_end();
    return;
}

if ($tab === 'batches') {
    $rows = fp_all('SELECT b.*, a.name AS bank FROM fp_payment_batches b JOIN fp_bank_accounts a ON a.id = b.bank_account_id ORDER BY b.id DESC LIMIT 100');
    $bst = ['draft' => ['Draft', ''], 'submitted' => ['Awaiting authorisation', 'amber'], 'authorized' => ['Authorised', 'blue'], 'released' => ['Sent to bank', 'blue'],
            'completed' => ['Completed', 'green'], 'partly_failed' => ['Partly failed', 'red'], 'rejected' => ['Rejected', 'red'], 'cancelled' => ['Cancelled', '']];
    if (!$rows) {
        echo inc_empty('No batches yet.', 'Prepare payments from the proposal and tick "put them in one batch".');
    } else {
        echo '<div class="scrollx"><table class="data inc-table stack"><thead><tr><th>Batch</th><th>From</th><th>Pay on</th><th class="num">Payments</th><th class="num">Total</th><th>Status</th></tr></thead><tbody>';
        foreach ($rows as $b) {
            echo '<tr><td data-l="Batch"><a href="' . e(fp_url('batch', ['id' => $b['id']])) . '"><strong>' . e($b['number']) . '</strong></a></td><td data-l="From">' . e($b['bank']) . '</td>'
               . '<td data-l="Pay on">' . e(fp_fmt_date($b['pay_date'])) . '</td><td class="num" data-l="Payments">' . (int) $b['count'] . '</td>' . fp_money_td($b['total'], 'Total') . '<td data-l="Status">' . fp_badge($bst, $b['status']) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    fp_layout_end();
    return;
}

$q = array_intersect_key($_GET, array_flip(['q', 'status', 'from', 'to', 'vendor']));
$where = ' WHERE 1=1';
$t = '';
$p = [];
if (!fp_can('pay.view')) {
    $where .= ' AND p.requester_person_id = ' . fp_me();
}
if (isset(fp_payment_statuses()[$q['status'] ?? ''])) {
    $where .= ' AND p.status = ?';
    $t .= 's';
    $p[] = $q['status'];
}
foreach (['from' => 'p.pay_date >= ?', 'to' => 'p.pay_date <= ?'] as $k => $sql) {
    if (fp_safe_date($q[$k] ?? '')) {
        $where .= ' AND ' . $sql;
        $t .= 's';
        $p[] = $q[$k];
    }
}
if (!empty($q['vendor'])) {
    $where .= ' AND p.payee_type = "vendor" AND p.payee_id = ' . (int) $q['vendor'];
}
if (trim((string) ($q['q'] ?? '')) !== '') {
    $where .= ' AND (p.number LIKE ? OR p.payee_name LIKE ? OR p.bank_ref LIKE ?)';
    $like = '%' . trim((string) $q['q']) . '%';
    $t .= 'sss';
    array_push($p, $like, $like, $like);
}
$rows = fp_all('SELECT p.*, b.name AS bank FROM fp_payments p LEFT JOIN fp_bank_accounts b ON b.id = p.bank_account_id' . $where . ' ORDER BY p.id DESC LIMIT 300', $t, $p);
echo '<section class="card fp-filtercard">' . fp_filters('payments', ['q', 'from', 'to', 'status'], $q, fp_payment_statuses()) . '</section>';
if (!$rows) {
    echo inc_empty('No payments here.');
} else {
    echo '<div class="scrollx"><table class="data inc-table stack"><thead><tr><th>Payment</th><th>Kind</th><th>Payee</th><th>From</th><th>Pay on</th><th class="num">Amount</th><th>Status</th><th>Bank ref</th></tr></thead><tbody>';
    foreach ($rows as $x) {
        echo '<tr><td data-l="Payment"><a href="' . e(fp_url('payment', ['id' => $x['id']])) . '"><strong>' . e($x['number']) . '</strong></a>' . ($x['batch_id'] ? ' <span class="muted small">in batch</span>' : '') . '</td>'
           . '<td data-l="Kind">' . e(fp_payment_kinds()[$x['kind']]) . '</td><td data-l="Payee">' . e($x['payee_name']) . '</td><td data-l="From">' . e($x['bank'] ?? '—') . '</td>'
           . '<td data-l="Pay on">' . e(fp_fmt_date($x['pay_date'])) . '</td>' . fp_money_td($x['amount'], 'Amount') . '<td data-l="Status">' . fp_badge(fp_payment_statuses(), $x['status']) . '</td>'
           . '<td data-l="Bank ref" class="small">' . e($x['bank_ref'] ?? ($x['failure_reason'] ?? '')) . '</td></tr>';
    }
    echo '</tbody></table></div>';
}
fp_layout_end();

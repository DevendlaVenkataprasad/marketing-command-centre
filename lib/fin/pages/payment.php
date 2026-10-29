<?php
/**
 * One payment: prepared here (?new=vendor|claim|advance|request|tax), then
 * submitted, authorised through approval, released to the bank, and
 * completed or failed by recording what the bank said.
 */

$id = (int) ($_GET['id'] ?? 0);
$pay = $id ? fp_payment($id) : null;
if ($id && !$pay) {
    fabo_deny('That payment does not exist.', 404);
}
if ($pay && !fp_doc_can_view('payment', $pay)) {
    fabo_deny('That payment is not yours to see.', 403);
}
$back = $pay ? fp_url('payment', ['id' => $id]) : fp_url('payments');
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    if ($pay && ($x = fp_wf_post('payment', $id, $back))) {
        $flash = $x;
    } elseif ($pay && ($x = fp_files_post('payment', $id, $back, fp_can('pay.prepare') || (int) $pay['requester_person_id'] === fp_me()))) {
        $flash = $x;
    } elseif (!$pay && $a === 'create') {
        $flash = fp_action(static function () {
            fp_nonce_check();
            $nid = fp_payment_create($_POST);
            if (!empty($_POST['submit_now'])) {
                inc_redirect(fp_url('payment', ['id' => $nid]), ['ok', fp_payment_submit($nid, fp_payment($nid)['version'])]);
            }
            inc_redirect(fp_url('payment', ['id' => $nid]), ['ok', 'Payment prepared as a draft. Submit it for authorisation.']);
        }, $back);
    } elseif ($pay && $a === 'submit') {
        $flash = fp_action(static fn() => fp_payment_submit($id, $_POST['version'] ?? 0), $back);
    } elseif ($pay && $a === 'bank') {
        $flash = fp_action(static function () use ($id) {
            fp_payment_set_bank($id, (int) ($_POST['bank_account_id'] ?? 0));
            return 'Account chosen.';
        }, $back);
    } elseif ($pay && $a === 'release') {
        $flash = fp_action(static function () use ($id) {
            fp_payment_release([$id]);
            return 'Released. Record the bank\'s reference when it is confirmed — until then it is not paid.';
        }, $back);
    } elseif ($pay && $a === 'complete') {
        $flash = fp_action(static fn() => fp_payment_complete($id, (string) ($_POST['bank_ref'] ?? ''), (string) ($_POST['completed_on'] ?? '')), $back);
    } elseif ($pay && $a === 'fail') {
        $flash = fp_action(static fn() => fp_payment_fail($id, (string) ($_POST['reason'] ?? '')), $back);
    } elseif ($pay && $a === 'retry') {
        $flash = fp_action(static function () use ($id) {
            $nid = fp_payment_retry($id);
            inc_redirect(fp_url('payment', ['id' => $nid]), ['ok', 'A fresh draft has been prepared. Submit it for authorisation.']);
        }, $back);
    } elseif ($pay && $a === 'cancel') {
        $flash = fp_action(static function () use ($id) {
            fp_payment_cancel($id, (string) ($_POST['reason'] ?? ''), $_POST['version'] ?? 0);
            return 'Payment cancelled.';
        }, $back);
    }
}

if (!$pay) {
    $kind = (string) ($_GET['new'] ?? ($_POST['kind'] ?? 'request'));
    if (!isset(fp_payment_kinds()[$kind])) {
        $kind = 'request';
    }
    if ($kind !== 'request') {
        fp_need('pay.prepare');
    } elseif (!fp_self_writable()) {
        fabo_deny('Finance is view only here.', 403);
    }
    fp_layout_start('New ' . strtolower(fp_payment_kinds()[$kind]), $kind === 'request' ? 'Ask Finance to pay something that has no bill. It is authorised before it is paid.' : 'Prepared as a draft; someone else authorises it.', '',
                    ['Payments' => fp_url('payments'), 'New' => null], $flash);
    $common = '<div class="inc-row">' . inc_field('Pay on', '<input type="date" name="pay_date" value="' . e($_POST['pay_date'] ?? date('Y-m-d')) . '" required>', '', true)
            . ($kind !== 'request' || fp_can('pay.prepare') ? inc_field('Pay from', inc_select('bank_account_id', fp_bank_options(), $_POST['bank_account_id'] ?? '', $kind === 'request' ? 'Finance decides' : 'Choose…', $kind === 'request' ? '' : 'required'), '', $kind !== 'request') : '')
            . inc_field('Method', inc_select('method', ['neft' => 'NEFT', 'rtgs' => 'RTGS', 'imps' => 'IMPS', 'upi' => 'UPI', 'cheque' => 'Cheque', 'cash' => 'Cash', 'card' => 'Card', 'other' => 'Other'], $_POST['method'] ?? 'neft'))
            . inc_field('Currency', '<input name="currency" maxlength="3" size="4" value="' . e($_POST['currency'] ?? fp_setting('base_currency')) . '">') . '</div>';
    echo '<section class="card">';
    if (in_array($kind, ['vendor', 'claim', 'advance'], true)) {
        $rows = [];
        if ($kind === 'vendor') {
            $vendor = (int) ($_GET['vendor'] ?? ($_POST['vendor'] ?? 0));
            echo '<form method="get" class="inc-row"><input type="hidden" name="p" value="payment"><input type="hidden" name="new" value="vendor">'
               . inc_field('Vendor', inc_select('vendor', fp_vendor_options(), $vendor, 'Choose…', 'data-autosubmit'), '', true, 'grow') . '</form>';
            foreach ($vendor ? fp_bills_payable($vendor) : [] as $b) {
                $rows[(int) $b['id']] = [$b['number'] . ' — invoice ' . $b['bill_no'] . ', due ' . fp_fmt_date($b['due_date']) . ($b['currency'] !== fp_setting('base_currency') ? ' (' . $b['currency'] . ')' : ''), $b['open']];
            }
        } elseif ($kind === 'claim') {
            foreach (fp_all('SELECT * FROM fp_claims WHERE status = "approved" ORDER BY id') as $c) {
                [$open] = fp_payable_open('claim', (int) $c['id']);
                if ($open > 0.004) {
                    $rows[(int) $c['id']] = [$c['number'] . ' — ' . $c['employee_name'] . ': ' . $c['purpose'], $open];
                }
            }
        } else {
            foreach (fp_all('SELECT * FROM fp_advances WHERE status = "approved" ORDER BY id') as $d) {
                [$open] = fp_payable_open('advance', (int) $d['id']);
                if ($open > 0.004) {
                    $rows[(int) $d['id']] = [$d['number'] . ' — ' . $d['employee_name'] . ': ' . $d['purpose'], $open];
                }
            }
        }
        if (!$rows) {
            echo '<p class="muted small">' . ($kind === 'vendor' && empty($vendor) ? 'Choose a vendor to see its bills to pay.' : 'Nothing is open to pay.') . '</p>';
        } else {
            $pre = (int) ($_GET['bill'] ?? 0);
            echo inc_form_open('', true) . fp_nonce_field() . '<input type="hidden" name="action" value="create"><input type="hidden" name="kind" value="' . e($kind) . '">' . $common
               . '<table class="data inc-table stack"><thead><tr><th>Settles</th><th class="num">Open</th><th class="num">Pay</th></tr></thead><tbody>';
            foreach ($rows as $did => [$label, $open]) {
                echo '<tr><td data-l="Settles">' . e($label) . '</td>' . fp_money_td($open, 'Open') . '<td class="num" data-l="Pay"><input name="alloc[' . $did . ']" size="10" inputmode="decimal" value="'
                   . e($_POST['alloc'][$did] ?? (count($rows) === 1 || $pre === $did ? fp_amt($open) : '')) . '"></td></tr>';
            }
            echo '</tbody></table>' . inc_field('Purpose / narration', '<input name="purpose" maxlength="500" value="' . e($_POST['purpose'] ?? '') . '">')
               . '<label class="check"><input type="checkbox" name="submit_now" value="1" checked> Submit for authorisation now</label>'
               . '<div class="inc-formfoot"><button class="btn btn-primary">Prepare payment</button></div></form>';
        }
    } else {
        $accounts = $kind === 'tax' ? fp_account_options(['liability', 'expense']) : fp_account_options(['expense', 'asset']);
        echo inc_form_open('', true) . fp_nonce_field() . '<input type="hidden" name="action" value="create"><input type="hidden" name="kind" value="' . e($kind) . '">'
           . '<div class="inc-row">' . inc_field('Pay to', '<input name="payee_name" maxlength="190" required value="' . e($_POST['payee_name'] ?? ($kind === 'tax' ? 'Income Tax Department' : '')) . '">', '', true, 'grow')
           . ($kind === 'request' ? inc_field('…or a vendor on file', inc_select('payee_vendor_id', fp_vendor_options(), $_POST['payee_vendor_id'] ?? '', '—')) : '')
           . inc_field('Amount', '<input name="amount" inputmode="decimal" required value="' . e($_POST['amount'] ?? '') . '">', '', true) . '</div>'
           . '<div class="inc-row">' . inc_field($kind === 'tax' ? 'Settles (tax payable account)' : 'Charge to', inc_select('account_id', $accounts, $_POST['account_id'] ?? ($kind === 'tax' ? fp_acct('tds_payable') : ''), 'Choose…', 'required'), '', true, 'grow')
           . inc_field('Cost centre', inc_select('cost_centre_id', fp_cc_options(), $_POST['cost_centre_id'] ?? '', $kind === 'request' ? 'Choose…' : '—', $kind === 'request' ? 'required' : ''), '', $kind === 'request')
           . (fp_project_options() ? inc_field('Project', inc_select('project_id', fp_project_options(), $_POST['project_id'] ?? '', '—')) : '') . '</div>'
           . inc_field('What it is for', '<input name="purpose" maxlength="500" required value="' . e($_POST['purpose'] ?? '') . '">', $kind === 'tax' ? 'e.g. TDS deposit for September (challan 281)' : '', true)
           . $common . inc_field('Supporting documents', '<input type="file" name="files[]" multiple>')
           . '<label class="check"><input type="checkbox" name="submit_now" value="1" checked> Submit for authorisation now</label>'
           . '<div class="inc-formfoot"><button class="btn btn-primary">' . ($kind === 'request' ? 'Send request' : 'Prepare payment') . '</button></div></form>';
    }
    echo '</section>';
    fp_layout_end();
    return;
}

$st = $pay['status'];
$allocs = fp_payment_allocs($id);
$acts = '';
$ver = (int) $pay['version'];
$own = $pay['kind'] === 'request' && (int) $pay['requester_person_id'] === fp_me();
if (fp_writable()) {
    if ($st === 'draft' && !$pay['batch_id'] && (fp_can('pay.prepare') || $own)) {
        $acts .= inc_button('Submit for authorisation', ['action' => 'submit', 'version' => $ver], 'btn btn-primary') . ' ';
    }
    if ($st === 'authorized' && fp_can('pay.execute') && !$pay['batch_id'] && $pay['bank_account_id']) {
        $acts .= inc_button('Release to bank', ['action' => 'release'], 'btn btn-primary', 'Release this payment to the bank?') . ' ';
    }
    if ($st === 'failed' && fp_can('pay.prepare')) {
        $acts .= inc_button('Retry as a new payment', ['action' => 'retry'], 'btn btn-primary');
    }
}
fp_layout_start(fp_payment_kinds()[$pay['kind']] . ' ' . $pay['number'], e($pay['payee_name']) . ' · ' . e(fp_money($pay['amount'])), $acts, ['Payments' => fp_url('payments'), $pay['number'] => null], $flash);
echo fp_steps_bar(['draft' => 'Prepared', 'submitted' => 'Authorisation', 'authorized' => 'Authorised', 'released' => 'Sent to bank', 'completed' => 'Completed'], $st);
if ($st === 'failed') {
    echo '<div class="inc-warn"><strong>Failed:</strong> ' . e($pay['failure_reason']) . '. Nothing was posted; what it was paying is open again.</div>';
}
if (in_array($st, ['rejected'], true) || ($st === 'draft' && $pay['return_note'])) {
    echo '<div class="inc-warn"><strong>' . ($st === 'rejected' ? 'Rejected' : 'Returned') . ':</strong> ' . e($pay['return_note']) . '</div>';
}
$bank = $pay['bank_account_id'] ? fp_bank_account((int) $pay['bank_account_id']) : null;
echo '<div class="inc-grid2 wide-left"><section class="card">' . fp_facts([
    'Status' => fp_badge(fp_payment_statuses(), $st), 'Payee' => $pay['payee_type'] === 'vendor' && $pay['payee_id'] ? '<a href="' . e(fp_url('vendor', ['id' => $pay['payee_id']])) . '">' . e($pay['payee_name']) . '</a>' : e($pay['payee_name']),
    'Amount' => e($pay['currency'] . ' ' . fp_amt($pay['amount'])) . ($pay['currency'] !== fp_setting('base_currency') ? ' at ' . (float) $pay['fx_rate'] : ''), 'Pay on' => e(fp_fmt_date($pay['pay_date'])),
    'From' => $bank ? e($bank['name']) : '<span class="muted">not chosen yet</span>', 'Method' => e(strtoupper($pay['method'])), 'Purpose' => e($pay['purpose']),
    'Charged to' => $pay['account_id'] ? e(fp_account((int) $pay['account_id'])['code'] . ' ' . fp_account((int) $pay['account_id'])['name']) : '',
    'Batch' => $pay['batch_id'] ? '<a href="' . e(fp_url('batch', ['id' => $pay['batch_id']])) . '">' . e(fp_batch((int) $pay['batch_id'])['number']) . '</a>' : '',
    'Authorised by' => $pay['authorized_by'] ? e($pay['authorized_by'] . ' · ' . fp_fmt_dt($pay['authorized_at'])) : '', 'Bank reference' => e($pay['bank_ref'] ?? ''),
    'Completed on' => e(fp_fmt_date($pay['completed_on'])), 'Ledger' => $pay['journal_id'] ? '<a href="' . e(fp_url('journal', ['id' => $pay['journal_id']])) . '">Posted journal</a>' : 'Not posted (posted on completion)',
    'Retry of' => $pay['retry_of'] ? '<a href="' . e(fp_url('payment', ['id' => $pay['retry_of']])) . '">earlier failed payment</a>' : '',
    'Prepared by' => e($pay['prepared_by'] . ' · ' . fp_fmt_dt($pay['created_at'])),
]);
if ($allocs) {
    echo '<h3 class="fp-h3">Settles</h3><ul class="fp-files">';
    foreach ($allocs as $a) {
        $page = ['bill' => 'bill', 'claim' => 'claim', 'advance' => 'advance'][$a['doc_type']];
        echo '<li><a href="' . e(fp_url($page, ['id' => $a['doc_id']])) . '">' . e(fp_doc_label_no($a['doc_type'] === 'bill' ? 'vendor' : $a['doc_type'], (int) $a['doc_id'])) . '</a> · ' . e(fp_money($a['amount'])) . '</li>';
    }
    echo '</ul>';
}
echo '</section><div>';
if (fp_writable() && fp_can('pay.execute')) {
    if (in_array($st, ['authorized', 'draft'], true) && !$pay['batch_id']) {
        echo '<section class="card"><h2>Pay from</h2>' . inc_form_open() . '<input type="hidden" name="action" value="bank">' . inc_select('bank_account_id', fp_bank_options(), $pay['bank_account_id'] ?? '', 'Choose…', 'required')
           . ' <button class="btn btn-sm">Save</button></form></section>';
    }
    $cashLike = in_array($pay['method'], ['cash', 'cheque'], true);
    if ($st === 'released' || ($st === 'authorized' && $cashLike)) {
        echo '<section class="card"><h2>What the bank said</h2>' . inc_form_open() . '<input type="hidden" name="action" value="complete">'
           . inc_field('Bank reference (UTR / cheque no.)', '<input name="bank_ref" maxlength="120" required>', '', true)
           . inc_field('Paid on', '<input type="date" name="completed_on" value="' . e(date('Y-m-d')) . '" required>', '', true)
           . '<button class="btn btn-primary">Mark completed</button></form>';
        if ($st === 'released') {
            echo '<details><summary class="btn btn-sm">The bank refused or returned it</summary>' . inc_form_open() . '<input type="hidden" name="action" value="fail">'
               . '<input name="reason" maxlength="500" required placeholder="What the bank said"><button class="btn btn-sm btn-danger">Mark failed</button></form></details>';
        }
        echo '</section>';
    }
}
if (fp_writable() && in_array($st, ['draft', 'rejected', 'authorized', 'submitted'], true) && (fp_can('pay.prepare') || ($own && in_array($st, ['draft', 'submitted'], true)))) {
    echo '<section class="card"><details><summary class="btn btn-sm">Cancel payment</summary>' . inc_form_open('data-confirm="Cancel this payment?"') . '<input type="hidden" name="action" value="cancel"><input type="hidden" name="version" value="' . $ver . '">'
       . '<input name="reason" maxlength="500" required placeholder="Why"><button class="btn btn-sm btn-danger">Cancel</button></form></details></section>';
}
echo '</div></div>';
echo fp_wf_panel('payment', $pay, $back);
echo fp_files_panel('payment', $pay, fp_can('pay.prepare') || $own, $back);
echo fp_audit_panel('payment', $id);
fp_layout_end();

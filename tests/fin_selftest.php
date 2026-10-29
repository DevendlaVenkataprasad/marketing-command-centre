<?php
/**
 * Finance Portal — end-to-end self-test of the accounting rules and the
 * workflows, run from the command line against a LOCAL database only:
 *
 *     php tests/fin_selftest.php
 *
 * It drops and recreates our organisation's fp_* tables and adds test
 * people, so it refuses to run anywhere but a local Windows/XAMPP copy.
 * Each check prints PASS or FAIL; the exit code is the number of failures.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (PHP_OS_FAMILY !== 'Windows' || getenv('FP_TEST_ALLOW') === 'no') {
    fwrite(STDERR, "Refusing to run: this test rebuilds the finance tables and is for a local copy only.\n");
    exit(99);
}
chdir(dirname(__DIR__));
require __DIR__ . '/../lib/ui.php';
fabo_boot();
require __DIR__ . '/../lib/fin/app.php';

$db = fabo_db();
$dbName = (string) fabo_one('SELECT DATABASE() d')['d'];
if (!in_array(strtolower((string) DB_HOST), ['localhost', '127.0.0.1'], true) || fabo_one('SELECT COUNT(*) n FROM clients WHERE token NOT LIKE "fptest%"')['n'] > 0) {
    fwrite(STDERR, "Refusing to run: database $dbName has real clients in it.\n");
    exit(99);
}

$fails = 0;
$passes = 0;
function ok(bool $cond, string $what): void
{
    global $fails, $passes;
    if ($cond) {
        $passes++;
        echo "  PASS  $what\n";
    } else {
        $fails++;
        echo "  FAIL  $what\n";
    }
}
function throws(callable $fn, string $what, string $contains = ''): void
{
    try {
        $fn();
        ok(false, $what . ' (no error raised)');
    } catch (InvalidArgumentException $e) {
        ok($contains === '' || stripos($e->getMessage(), $contains) !== false, $what . ' — "' . $e->getMessage() . '"');
    }
}
function section(string $s): void
{
    echo "\n== $s\n";
}

/* ------------------------------------------------------------- set-up */
section('Fresh install');
foreach (fabo_all("SELECT table_name t FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'fp\\_%' AND table_name NOT LIKE 'fp\\_o%'") as $t) {
    $db->query('SET FOREIGN_KEY_CHECKS = 0');
    $db->query('DROP TABLE `' . $t['t'] . '`');
    $db->query('SET FOREIGN_KEY_CHECKS = 1');
}
fabo_exec('DELETE FROM clients WHERE token LIKE "fptest%"');
$people = [];
foreach (['asha' => 'Asha AP', 'bala' => 'Bala FM', 'chitra' => 'Chitra Employee', 'dev' => 'Dev CFO', 'esha' => 'Esha Auditor'] as $k => $name) {
    $people[$k] = fabo_exec('INSERT INTO clients (name, email, token, kind) VALUES (?,?,?,"team")', 'sss', [$name, $k . '@example.test', 'fptest' . substr(md5($k), 0, 26)]);
}
function act(?string $who): void
{
    global $people;
    $GLOBALS['fp_viewer'] = $who === null ? fabo_admin_viewer() : fabo_person_viewer(fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [$people[$who]]));
    $GLOBALS['fp_grant'] = 'edit';
}
fp_use(0);
act(null);
fp_install();
fp_setting_put('mail_notify', '0');
ok((int) fp_val('SELECT COUNT(*) FROM fp_accounts') > 60, 'chart of accounts seeded');
ok((int) fp_val('SELECT COUNT(*) FROM fp_periods WHERE fy = ?', 'i', [fp_fy_of()]) === 12, 'twelve periods for the current year');
ok((int) fp_val('SELECT COUNT(*) FROM fp_wf_rules') > 10, 'approval rules seeded');
foreach (['asha' => 'ap_accountant', 'bala' => 'finance_manager', 'dev' => 'cfo', 'esha' => 'auditor'] as $k => $role) {
    fp_exec('INSERT INTO fp_role_members (person_id, role) VALUES (?,?)', 'is', [$people[$k], $role]);
}
fp_exec('UPDATE fp_cost_centres SET manager_person_id = ? WHERE code = "GEN"', 'i', [$people['bala']]);
$gen = (int) fp_one('SELECT id FROM fp_cost_centres WHERE code = "GEN"')['id'];
$today = date('Y-m-d');

section('Permissions');
act('asha');
ok(fp_can('ap.prepare') && fp_can('vendor.manage'), 'AP accountant may enter bills and vendors');
ok(!fp_can('gl.close') && !fp_can('settings.manage') && !fp_can('pay.execute'), 'AP accountant may not close periods, change settings or release payments');
act('esha');
ok(fp_can('gl.view') && fp_can('audit.view') && !fp_can('gl.prepare') && !fp_can('ap.prepare'), 'auditor reads everything and changes nothing');
act('chitra');
ok(!fp_can('gl.view') && !fp_can('dashboard.view'), 'an employee has no desk, only self-service');
$GLOBALS['fp_grant'] = 'view';
act('bala');
$GLOBALS['fp_grant'] = 'view';
ok(!fp_can('gl.prepare') && fp_can('gl.view'), 'a View-only organisation grant blocks every write');

/* ------------------------------------------------------------ ledger */
section('Journals: double entry, approval, posting, reversal');
act(null);
$exp = fp_acct('professional');
$bank = (int) fp_one('SELECT id, account_id FROM fp_bank_accounts WHERE kind = "bank"')['account_id'];
$bankAcc = (int) fp_one('SELECT id FROM fp_bank_accounts WHERE kind = "bank"')['id'];
throws(static fn() => fp_journal_save(null, ['jdate' => $today, 'description' => 'One line', 'lines' => [['account_id' => $exp, 'debit' => '100']]]), 'a one-line journal is refused', 'two lines');
throws(static fn() => fp_journal_save(null, ['jdate' => $today, 'description' => 'Both sides', 'lines' => [['account_id' => $exp, 'debit' => '100', 'credit' => '100'], ['account_id' => $bank, 'credit' => '100']]]), 'a line with debit and credit is refused', 'both');
throws(static fn() => fp_journal_save(null, ['jdate' => $today, 'description' => 'Group', 'lines' => [['account_id' => (int) fp_one('SELECT id FROM fp_accounts WHERE code = "6000"')['id'], 'debit' => '100'], ['account_id' => $bank, 'credit' => '100']]]), 'a group account is refused', 'group');
$jid = fp_journal_save(null, ['jdate' => $today, 'description' => 'Opening capital', 'type' => 'opening', 'lines' => [['account_id' => $bank, 'debit' => '500000'], ['account_id' => fp_acct('capital'), 'credit' => '400000']]]);
throws(static fn() => fp_journal_submit($jid, fp_journal($jid)['version']), 'an unbalanced journal cannot be submitted', 'balance');
fp_journal_save($jid, ['jdate' => $today, 'description' => 'Opening capital', 'type' => 'opening', 'version' => fp_journal($jid)['version'],
                       'lines' => [['account_id' => $bank, 'debit' => '500000'], ['account_id' => fp_acct('capital'), 'credit' => '500000']]]);
fp_journal_submit($jid, fp_journal($jid)['version']);
ok(fp_journal($jid)['status'] === 'submitted', 'balanced journal goes for approval, not straight to the books');
$step = fp_one('SELECT * FROM fp_wf_steps WHERE doc_type = "journal" AND doc_id = ? AND status = "pending"', 'i', [$jid]);
act(null);
ok(!fp_wf_can_act($step)[0], 'the preparer (administrator) cannot approve their own journal');
act('asha');
ok(!fp_wf_can_act($step)[0], 'an AP accountant is not a journal approver');
act('bala');
throws(static fn() => fp_wf_act('journal', $jid, 'reject', '', fp_journal($jid)['version']), 'rejecting without a reason is refused', 'reason');
throws(static fn() => fp_wf_act('journal', $jid, 'approve', '', 0), 'approving a stale version is refused', 'changed');
fp_wf_act('journal', $jid, 'approve', 'OK', fp_journal($jid)['version']);
$j = fp_journal($jid);
ok($j['status'] === 'posted' && $j['number'] !== null && str_starts_with($j['number'], 'JV/'), 'approved journal is posted and numbered ' . $j['number']);
throws(static fn() => fp_journal_save($jid, ['jdate' => $today, 'description' => 'x', 'version' => $j['version'], 'lines' => [['account_id' => $bank, 'debit' => '1'], ['account_id' => $exp, 'credit' => '1']]]), 'a posted journal cannot be edited', 'draft');
act(null);
$adj = fp_journal_save(null, ['jdate' => $today, 'description' => 'Wrong fee', 'lines' => [['account_id' => $exp, 'debit' => '1000'], ['account_id' => $bank, 'credit' => '1000']]]);
fp_journal_submit($adj, fp_journal($adj)['version']);
act('bala');
fp_wf_act('journal', $adj, 'approve', '', fp_journal($adj)['version']);
act(null);
$rev = fp_journal_reverse_draft($adj, $today, 'Booked in error');
fp_journal_submit($rev, fp_journal($rev)['version']);
act('bala');
fp_wf_act('journal', $rev, 'approve', '', fp_journal($rev)['version']);
ok((int) fp_journal($adj)['reversed_by'] === $rev, 'a reversal is its own approved journal, linked to the original');
throws(static fn() => fp_journal_reverse_draft($adj, $today, 'Again'), 'a journal cannot be reversed twice', 'already');
act(null);
$retJ = fp_journal_save(null, ['jdate' => $today, 'description' => 'Return me', 'lines' => [['account_id' => $exp, 'debit' => '10'], ['account_id' => $bank, 'credit' => '10']]]);
fp_journal_submit($retJ, fp_journal($retJ)['version']);
act('bala');
fp_wf_act('journal', $retJ, 'return', 'Wrong account', fp_journal($retJ)['version']);
ok(fp_journal($retJ)['status'] === 'draft' && fp_journal($retJ)['return_note'] === 'Wrong account', 'return for correction sends it back to draft with the reason');

/* ------------------------------------------------------------ vendors */
section('Vendors: verification and controlled bank changes');
act('asha');
[$vid] = fp_vendor_save(null, ['name' => 'Acme Consulting', 'gstin' => '29ABCDE1234F1Z5', 'pan' => 'ABCDE1234F', 'bank_account' => '123456789012', 'bank_ifsc' => 'HDFC0001234', 'payment_terms_days' => 30]);
throws(static fn() => fp_vendor_save(null, ['name' => 'Bad', 'gstin' => '12345']), 'an invalid GSTIN is refused', 'GSTIN');
ok(fp_vendor($vid)['status'] === 'draft', 'a new vendor starts as a draft');
fp_vendor_submit($vid, fp_vendor($vid)['version']);
act('bala');
fp_wf_act('vendor', $vid, 'approve', '', fp_vendor($vid)['version']);
ok(fp_vendor($vid)['status'] === 'active', 'verified vendor is active');
act('asha');
fp_vendor_save($vid, array_merge(fp_vendor($vid), ['bank_account' => '999999999999']));
ok(fp_vendor($vid)['bank_account'] === '123456789012' && fp_vendor($vid)['pending_json'] !== null, 'a bank change waits for approval; the old account stays in use');
act('bala');
fp_wf_act('vendor', $vid, 'approve', '', fp_vendor($vid)['version']);
ok(fp_vendor($vid)['bank_account'] === '999999999999' && fp_vendor($vid)['pending_json'] === null, 'approved bank change takes effect');
ok(!str_contains((string) fp_one('SELECT before_json FROM fp_audit WHERE entity = "vendor" AND action = "change_request"')['before_json'], '123456789012'), 'the audit trail masks account numbers');

/* ------------------------------------------------------------ bills */
section('Bills: GST, TDS, duplicates, statuses, posting');
act('asha');
$gst18 = (int) fp_one('SELECT id FROM fp_tax_codes WHERE code = "GST18"')['id'];
$tdsJ = (int) fp_one('SELECT id FROM fp_tax_codes WHERE code = "TDS194J"')['id'];
$billIn = ['vendor_id' => $vid, 'bill_no' => 'AC/001', 'bill_date' => $today, 'tds_code_id' => $tdsJ,
           'lines' => [['description' => 'Advisory', 'account_id' => $exp, 'cost_centre_id' => $gen, 'qty' => '1', 'rate' => '10000', 'tax_code_id' => $gst18]]];
[$bid] = fp_bill_save(null, $billIn);
$b = fp_bill($bid);
ok(fp_paise($b['subtotal']) === 1000000 && fp_paise($b['tax_total']) === 180000 && fp_paise($b['tds_total']) === 100000 && fp_paise($b['total']) === 1080000,
   'bill: 10,000 + GST 1,800 − TDS 1,000 = 10,800 payable');
throws(static fn() => fp_bill_save(null, ['bill_no' => 'ac-001'] + $billIn), 'the same invoice number from the same vendor is refused', 'already entered');
throws(static fn() => fp_bill_move($bid, 'review', fp_bill($bid)['version']), 'a draft cannot jump to review', 'cannot');
fp_bill_move($bid, 'submit', fp_bill($bid)['version']);
fp_bill_move($bid, 'review', fp_bill($bid)['version']);
fp_bill_move($bid, 'approval', fp_bill($bid)['version']);
ok(fp_bill($bid)['status'] === 'awaiting_approval', 'submitted → under review → awaiting approval');
act('asha');
ok(!fp_wf_can_act(fp_one('SELECT * FROM fp_wf_steps WHERE doc_type = "bill" AND doc_id = ? AND status = "pending"', 'i', [$bid]))[0], 'the preparer cannot approve their own bill');
act('bala');
fp_wf_act('bill', $bid, 'approve', '', fp_bill($bid)['version']);
$b = fp_bill($bid);
ok($b['status'] === 'approved' && $b['journal_id'], 'approved bill is posted');
$jl = [];
foreach (fp_journal_lines((int) $b['journal_id']) as $l) {
    $jl[$l['code']] = [fp_paise($l['debit_base']), fp_paise($l['credit_base'])];
}
ok(($jl['6600'] ?? null) === [1000000, 0] && ($jl['1310'] ?? null) === [90000, 0] && ($jl['1320'] ?? null) === [90000, 0] && ($jl['2240'] ?? null) === [0, 100000] && ($jl['2100'] ?? null) === [0, 1080000],
   'bill journal: Dr fees 10,000, Dr CGST 900, Dr SGST 900, Cr TDS 1,000, Cr payables 10,800');
act('asha');
[$sim, $dupNote] = fp_bill_save(null, ['bill_no' => 'AC-002-DIFF'] + $billIn);
ok($dupNote !== null, 'a different number, same amount and vendor is flagged as a possible duplicate');
throws(static fn() => fp_bill_move($sim, 'submit', fp_bill($sim)['version']), 'a flagged bill needs "not a duplicate" ticked', 'duplicate');
fp_bill_move($sim, 'cancel', fp_bill($sim)['version'], ['reason' => 'Test duplicate']);

/* ------------------------------------------------------------ procurement */
section('Purchase order, receipt and three-way match');
act('asha');
$poId = fp_po_save(null, ['vendor_id' => $vid, 'po_date' => $today, 'cost_centre_id' => $gen,
                          'lines' => [['description' => 'Laptops', 'account_id' => fp_acct('fa_computers'), 'qty' => '10', 'rate' => '50000', 'tax_code_id' => $gst18]]]);
fp_po_submit($poId, fp_po($poId)['version']);
act('bala');
fp_wf_act('po', $poId, 'approve', 'Budget holder ok', fp_po($poId)['version']);
$pending = fp_one('SELECT * FROM fp_wf_steps WHERE doc_type = "po" AND doc_id = ? AND status = "pending"', 'i', [$poId]);
if ($pending) {
    act('dev');
    fp_wf_act('po', $poId, 'approve', 'Large order ok', fp_po($poId)['version']);
}
ok(fp_po($poId)['status'] === 'approved', 'order approved by the budget holder (and the CFO above the limit)');
act('asha');
$poLine = fp_po_lines($poId)[0];
throws(static fn() => fp_grn_create($poId, ['grn_date' => date('Y-m-d'), 'qty' => [$poLine['id'] => '10']]), 'whoever raised the order cannot confirm its receipt', 'someone else');
act('bala');
throws(static fn() => fp_grn_create($poId, ['grn_date' => date('Y-m-d'), 'qty' => [$poLine['id'] => '11']]), 'cannot receive more than ordered', 'remain');
fp_grn_create($poId, ['grn_date' => date('Y-m-d'), 'qty' => [$poLine['id'] => '8']]);
ok(fp_po($poId)['status'] === 'partially_received', '8 of 10 received → partly received');
act('asha');
[$pb] = fp_bill_save(null, ['vendor_id' => $vid, 'bill_no' => 'AC/LAP/1', 'bill_date' => $today, 'po_id' => $poId,
                            'lines' => [['description' => 'Laptops', 'account_id' => fp_acct('fa_computers'), 'qty' => '10', 'rate' => '50000', 'tax_code_id' => $gst18, 'po_line_id' => $poLine['id']]]]);
fp_bill_move($pb, 'submit', fp_bill($pb)['version']);
fp_bill_move($pb, 'review', fp_bill($pb)['version']);
ok(fp_bill($pb)['match_status'] === 'exception', 'billing 10 when 8 arrived is a matching exception');
throws(static fn() => fp_bill_move($pb, 'approval', fp_bill($pb)['version']), 'an exception cannot go for approval without a Finance Manager\'s note', 'exception');
fp_bill_move($pb, 'hold', fp_bill($pb)['version'], ['reason' => 'Short delivery']);
act('bala');
fp_bill_move($pb, 'release', fp_bill($pb)['version']);
act('asha');
fp_bill_move($pb, 'cancel', fp_bill($pb)['version'], ['reason' => 'Vendor to reissue for 8']);
[$pb2] = fp_bill_save(null, ['vendor_id' => $vid, 'bill_no' => 'AC/LAP/2', 'bill_date' => $today, 'po_id' => $poId,
                             'lines' => [['description' => 'Laptops', 'account_id' => fp_acct('fa_computers'), 'qty' => '8', 'rate' => '50000', 'tax_code_id' => $gst18, 'po_line_id' => $poLine['id']]]]);
fp_bill_move($pb2, 'submit', fp_bill($pb2)['version']);
fp_bill_move($pb2, 'review', fp_bill($pb2)['version']);
ok(fp_bill($pb2)['match_status'] === 'matched', 'order, receipt and bill agree for 8');
fp_bill_move($pb2, 'approval', fp_bill($pb2)['version']);
act('bala');
fp_wf_act('bill', $pb2, 'approve', '', fp_bill($pb2)['version']);
if (fp_bill($pb2)['status'] === 'awaiting_approval') {
    act('dev');
    fp_wf_act('bill', $pb2, 'approve', '', fp_bill($pb2)['version']);
}
ok(fp_bill($pb2)['status'] === 'approved' && (float) fp_po_lines($poId)[0]['billed_qty'] == 8.0, 'bill posted; the order line shows 8 billed');

/* ------------------------------------------------------------ payments */
section('Payments: preparation ≠ authorisation ≠ completion; partial and failed payments');
act('asha');
$pay = fp_payment_create(['kind' => 'vendor', 'pay_date' => $today, 'bank_account_id' => $bankAcc, 'alloc' => [$bid => '5000']]);
throws(static fn() => fp_payment_create(['kind' => 'vendor', 'pay_date' => $today, 'bank_account_id' => $bankAcc, 'alloc' => [$bid => '6000']]), 'cannot prepare more than is still open (5,800)', 'open');
fp_payment_submit($pay, fp_payment($pay)['version']);
ok(!fp_wf_can_act(fp_one('SELECT * FROM fp_wf_steps WHERE doc_type = "payment" AND doc_id = ? AND status = "pending"', 'i', [$pay]))[0], 'whoever prepared a payment cannot authorise it');
act('bala');
fp_wf_act('payment', $pay, 'approve', '', fp_payment($pay)['version']);
ok(fp_payment($pay)['status'] === 'authorized' && !fp_payment($pay)['journal_id'], 'authorised is not paid: nothing posted yet');
throws(static fn() => fp_payment_complete($pay, 'UTR1', $today), 'an authorised bank payment cannot be completed before release', 'released');
fp_payment_release([$pay]);
throws(static fn() => fp_payment_complete($pay, '', $today), 'completion needs the bank\'s reference', 'reference');
fp_payment_complete($pay, 'UTR123456', $today);
ok(fp_payment($pay)['status'] === 'completed' && fp_bill($bid)['status'] === 'partially_paid' && fp_paise(fp_bill($bid)['paid_amount']) === 500000, 'completed: bill partly paid (5,000 of 10,800)');
act('asha');
$pay2 = fp_payment_create(['kind' => 'vendor', 'pay_date' => $today, 'bank_account_id' => $bankAcc, 'alloc' => [$bid => '5800']]);
fp_payment_submit($pay2, fp_payment($pay2)['version']);
act('bala');
fp_wf_act('payment', $pay2, 'approve', '', fp_payment($pay2)['version']);
fp_payment_release([$pay2]);
fp_payment_fail($pay2, 'Beneficiary account closed');
ok(fp_payment($pay2)['status'] === 'failed' && fp_paise(fp_bills_payable($vid)[0]['open'] ?? 0) === 580000, 'a failed payment posts nothing and frees the 5,800 again');
act('asha');
$retry = fp_payment_retry($pay2);
ok(fp_payment($retry)['status'] === 'draft' && (int) fp_payment($retry)['retry_of'] === $pay2, 'a retry is a fresh draft to authorise');

/* ------------------------------------------------------------ receivables */
section('Receivables: invoice, partial and excess receipts, ageing');
act('bala');
$cust = fp_customer_save(null, ['name' => 'Globex Ltd', 'email' => 'ap@globex.test', 'payment_terms_days' => 15, 'credit_limit' => '100000']);
$inv = fp_invoice_save(null, ['customer_id' => $cust, 'invoice_date' => $today, 'lines' => [['description' => 'Campaign', 'account_id' => fp_acct('revenue'), 'qty' => '1', 'rate' => '20000', 'tax_code_id' => $gst18]]]);
fp_invoice_submit($inv, fp_invoice($inv)['version']);
act('dev');
fp_wf_act('invoice', $inv, 'approve', '', fp_invoice($inv)['version']);
ok(fp_invoice($inv)['status'] === 'issued' && fp_paise(fp_invoice($inv)['total']) === 2360000, 'invoice 20,000 + GST 3,600 issued and posted');
act('bala');
$r1 = fp_receipt_create(['customer_id' => $cust, 'receipt_date' => $today, 'bank_account_id' => $bankAcc, 'amount' => '10000', 'reference' => 'NEFT1', 'alloc' => [$inv => '10000']]);
ok(fp_invoice($inv)['status'] === 'partially_paid', 'receipt of 10,000 → partly paid');
throws(static fn() => fp_receipt_create(['customer_id' => $cust, 'receipt_date' => $today, 'bank_account_id' => $bankAcc, 'amount' => '1', 'reference' => 'NEFT1']), 'the same receipt reference twice is refused', 'already');
$r2 = fp_receipt_create(['customer_id' => $cust, 'receipt_date' => $today, 'bank_account_id' => $bankAcc, 'amount' => '15000', 'reference' => 'NEFT2', 'alloc' => [$inv => '13600']]);
ok(fp_invoice($inv)['status'] === 'paid' && fp_paise(fp_receipt_unallocated(fp_receipt($r2))) === 140000, 'excess payment: invoice paid, 1,400 left unallocated as credit');
$cb = fp_customer_balance($cust);
ok(fp_paise($cb['balance']) === -140000, 'the customer\'s account shows the 1,400 credit');

/* ------------------------------------------------------------ budgets */
section('Budgets: approval, actual, committed, available');
act('bala');
$bud = fp_budget_save(null, ['fy' => fp_fy_of(), 'name' => 'Test budget', 'lines' => [
    ['account_id' => $exp, 'cost_centre_id' => $gen, 'annual' => '120000'],
    ['account_id' => fp_acct('travel'), 'cost_centre_id' => $gen, 'annual' => '60000'],
]]);
ok(fp_paise(fp_budget($bud)['total']) === 18000000 && fp_paise(fp_budget_lines($bud)[0]['m12']) > 0, 'annual figures spread over twelve months');
fp_budget_submit($bud, fp_budget($bud)['version']);
act(null);
fp_wf_act('budget', $bud, 'approve', 'nobody else holds the role', fp_budget($bud)['version']);
act('dev');
fp_wf_act('budget', $bud, 'approve', '', fp_budget($bud)['version']);
ok(fp_budget($bud)['status'] === 'approved', 'two-level budget approval');
$line = array_values(array_filter(fp_budget_vs_actual(fp_fy_of()), static fn($l) => (int) $l['account_id'] === $exp))[0] ?? null;
ok($line && fp_paise($line['actual']) === 1000000 && fp_paise($line['available']) === 11000000, 'actual 10,000 against 1,20,000 → 1,10,000 available (the reversed 1,000 nets to nil)');

/* ------------------------------------------------------------ claims */
section('Employee claims: policy, two approvals, posting');
act(null);
fp_setting_put('claim_receipt_above', '2000');
act('chitra');
$travel = (int) fp_one('SELECT id FROM fp_expense_categories WHERE kind = "travel"')['id'];
$cl = fp_claim_save(null, ['purpose' => 'Client visit', 'cost_centre_id' => $gen, 'lines' => [
    ['expense_date' => $today, 'category_id' => $travel, 'description' => 'Cab', 'amount' => '1200'],
    ['expense_date' => $today, 'category_id' => $travel, 'description' => 'Train', 'amount' => '2500'],
]]);
throws(static fn() => fp_claim_submit($cl, fp_claim($cl)['version']), 'a 2,500 expense without a receipt cannot be submitted', 'receipt');
fp_claim_save($cl, ['version' => fp_claim($cl)['version'], 'purpose' => 'Client visit', 'cost_centre_id' => $gen, 'lines' => [
    ['expense_date' => $today, 'category_id' => $travel, 'description' => 'Cab', 'amount' => '1200'],
    ['expense_date' => $today, 'category_id' => $travel, 'description' => 'Train', 'amount' => '1800'],
]]);
fp_claim_submit($cl, fp_claim($cl)['version']);
act('asha');
ok(!fp_wf_can_act(fp_one('SELECT * FROM fp_wf_steps WHERE doc_type = "claim" AND doc_id = ? AND status = "pending"', 'i', [$cl]))[0], 'the manager step is the cost centre owner\'s, not finance\'s');
act('bala');
fp_wf_act('claim', $cl, 'approve', '', fp_claim($cl)['version']);
act('bala');
ok(!fp_wf_can_act(fp_one('SELECT * FROM fp_wf_steps WHERE doc_type = "claim" AND doc_id = ? AND status = "pending"', 'i', [$cl]))[0], 'the same person cannot also do the finance review');
act('asha');
fp_wf_act('claim', $cl, 'approve', 'Receipts fine', fp_claim($cl)['version']);
$c = fp_claim($cl);
ok($c['status'] === 'approved' && $c['journal_id'] && fp_paise($c['net_payable']) === 300000, 'claim approved and posted: 3,000 owed to the employee');
ok(str_contains(fp_claim_explain($c), 'Approved for'), 'the employee is told where it stands: "' . fp_claim_explain($c) . '"');

/* ------------------------------------------------------------ assets */
section('Fixed assets: capitalise, depreciate once a month');
act(null);
$cap = fp_assets_to_capitalise();
ok(count($cap) === 1, 'the laptop bill line is waiting to be capitalised');
$asset = fp_asset_add(['source' => 'bill', 'bill_line_id' => $cap[0]['id'], 'name' => '8 laptops', 'category_id' => (int) fp_one('SELECT id FROM fp_asset_categories WHERE name LIKE "Computers%"')['id'],
                       'acquired_on' => $today]);
$a = fp_asset($asset);
ok(fp_paise($a['cost']) === 40000000 && fp_paise($a['salvage']) === 2000000, 'capitalised at 4,00,000 with 5% salvage, no second entry');
[$n, $t] = fp_dep_run(date('Y-m'));
ok($n === 1 && fp_paise($t) === fp_paise((400000 - 20000) / 36), 'straight line: (4,00,000 − 20,000) ÷ 36 = ' . fp_money($t) . ' this month');
throws(static fn() => fp_dep_run(date('Y-m')), 'depreciation cannot be run twice for a month', 'already');

/* ------------------------------------------------------------ bank */
section('Bank statement import and matching');
act('bala');
[$added] = fp_bank_import_rows($bankAcc, [['Txn Date', 'Narration', 'Ref', 'Withdrawal', 'Deposit', 'Balance'],
    [date('d/m/Y'), 'NEFT ACME CONSULTING UTR123456', 'UTR123456', '5000.00', '', ''],
    [date('d/m/Y'), 'NEFT GLOBEX', 'NEFT1', '', '10000.00', ''],
    [date('d/m/Y'), 'BANK CHARGES', '', '59.00', '', '']], 'test.csv');
ok($added === 3, 'three statement lines imported');
[$again] = fp_bank_import_rows($bankAcc, [['Txn Date', 'Narration', 'Ref', 'Withdrawal', 'Deposit', 'Balance'], [date('d/m/Y'), 'NEFT ACME CONSULTING UTR123456', 'UTR123456', '5000.00', '', '']], 'test.csv');
ok($again === 0, 're-importing the same line adds nothing');
$m = fp_bank_automatch($bankAcc);
ok($m >= 2, $m . ' lines matched automatically (payment and receipt)');
$charge = (int) fp_one('SELECT id FROM fp_bank_lines WHERE description = "BANK CHARGES"')['id'];
fp_bank_entry_from_line($charge, fp_acct('bank_charges'), 'Bank charges');
ok(fp_one('SELECT status FROM fp_bank_lines WHERE id = ?', 'i', [$charge])['status'] === 'matched', 'bank charges posted from the statement and matched');

/* ------------------------------------------------------------ the books */
section('The books agree');
act('esha');
$tb = fp_trial_balance(['from' => fp_fy_range(fp_fy_of())[0], 'to' => $today]);
ok($tb['balanced'], 'trial balance: debits ' . fp_money($tb['totals']['cd']) . ' = credits ' . fp_money($tb['totals']['cc']));
$bs = fp_balance_sheet(['to' => $today]);
ok(fp_paise($bs['difference']) === 0, 'balance sheet: assets ' . fp_money($bs['sum']['asset']) . ' = liabilities + equity');
$cf = fp_cash_flow(['from' => fp_fy_range(fp_fy_of())[0], 'to' => $today]);
$cash = array_sum(array_map(static fn($b) => $b['balance'], fp_cash_position($today)));
ok(fp_paise($cf['closing']) === fp_paise($cash), 'cash-flow statement closes at the cash and bank balance (' . fp_money($cash) . ')');
$pl = fp_profit_loss(['from' => fp_fy_range(fp_fy_of())[0], 'to' => $today]);
ok(fp_paise($pl['sum']['revenue']) === 2000000, 'P&L revenue 20,000');
$ap = fp_report_run('ap_aging', ['to' => $today] + fp_report_filters([]));
ok(($ap['checks'][0][0] ?? '') === 'ok', 'payables ageing agrees with the payables control account');
$ar = fp_report_run('ar_aging', ['to' => $today] + fp_report_filters([]));
ok(in_array($ar['checks'][0][0] ?? '', ['ok', 'warn'], true), 'receivables ageing reconciles (the 1,400 credit is explained)');
foreach (fp_report_list() as $grp) {
    foreach ($grp as $k => $meta) {
        try {
            act(null);
            $rep = fp_report_run($k, fp_report_filters([]));
            ok(isset($rep['cols'], $rep['rows']), 'report runs: ' . $meta[0]);
        } catch (Throwable $e) {
            ok(false, 'report ' . $k . ' failed: ' . $e->getMessage());
        }
    }
}

section('Period close');
act('bala');
$p = fp_period_for($today);
fp_exec('INSERT INTO fp_journals (jdate, description, status, prepared_by) VALUES (?, "Left as draft", "draft", "test")', 's', [$today]);
throws(static fn() => fp_period_set((int) $p['id'], 'closed', ''), 'a month with unposted journals is not closed without a reason', 'not posted');
fp_period_set((int) $p['id'], 'closed', 'Test close');
act(null);
throws(static fn() => fp_journal_system(['jdate' => $today, 'description' => 'Late'], [['account_id' => $exp, 'debit' => 1], ['account_id' => $bank, 'credit' => 1]]), 'nothing posts into a closed month', 'closed');
act('bala');
throws(static fn() => fp_period_set((int) $p['id'], 'open', ''), 'reopening needs a reason', 'why');
fp_period_set((int) $p['id'], 'open', 'Test reopen');

section('Escalation');
act(null);
$esc = fp_journal_save(null, ['jdate' => $today, 'description' => 'Waiting', 'lines' => [['account_id' => $exp, 'debit' => '5'], ['account_id' => $bank, 'credit' => '5']]]);
fp_journal_submit($esc, fp_journal($esc)['version']);
fp_exec('UPDATE fp_wf_steps SET activated_at = DATE_SUB(NOW(), INTERVAL 100 HOUR) WHERE status = "pending"');
$GLOBALS['fp_system'] = true;
ok(fp_wf_escalate() > 0, 'approvals waiting past the escalation time are escalated');
$GLOBALS['fp_system'] = false;

echo "\n$passes passed, $fails failed.\n";
exit($fails);

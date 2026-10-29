<?php
/**
 * Finance Portal — payments, bank accounts and reconciliation.
 *
 * A payment goes through four hands, and none of them can stand in for
 * another:
 *
 *   prepared    (pay.prepare) what is to be paid, to whom, against which
 *               bills, claims or advances — or a payment request anyone may
 *               raise for something with no bill;
 *   authorised  through approval (Settings → Approval rules), never by
 *               whoever prepared it;
 *   released    (pay.execute) sent to the bank — a bank upload file for a
 *               batch, or handed over for a cheque or cash;
 *   completed   only when the bank's own reference is recorded. Approval is
 *               not payment, and release is not payment either: nothing is
 *               posted to the ledger and no bill is marked paid until then.
 *               A payment the bank refuses is marked failed, frees what it
 *               was paying, and can be retried as a fresh payment.
 *
 * This portal does not move money itself. No bank interface is connected:
 * release produces a file to upload to the bank's portal, and the result is
 * recorded here. Bank statements come in as CSV files and are matched to
 * the ledger, by rule and by hand.
 */

function fp_bank_account(int $id): ?array
{
    return fp_one('SELECT * FROM fp_bank_accounts WHERE id = ?', 'i', [$id]);
}

function fp_bank_options(bool $cashToo = true): array
{
    $out = [];
    foreach (fp_all('SELECT id, name, kind, currency FROM fp_bank_accounts WHERE active = 1' . ($cashToo ? '' : ' AND kind = "bank"') . ' ORDER BY kind, name') as $r) {
        $out[(int) $r['id']] = $r['name'] . ($r['currency'] !== fp_setting('base_currency') ? ' (' . $r['currency'] . ')' : '');
    }
    return $out;
}

/** Adds a bank, cash or card account, with its own ledger account under Cash and bank. */
function fp_bank_account_save(?int $id, array $p): int
{
    fp_need('bank.manage');
    $name = fp_str($p['name'] ?? '', 120, true, 'Account name');
    $kind = in_array($p['kind'] ?? '', ['bank', 'cash', 'card'], true) ? $p['kind'] : 'bank';
    $bank = fp_str($p['bank'] ?? '', 120);
    $no = preg_replace('/\s+/', '', fp_str($p['account_no'] ?? '', 40));
    $ifsc = strtoupper(fp_str($p['ifsc'] ?? '', 15));
    $currency = fp_currency($p['currency'] ?? '');
    $entity = fp_ref($p['entity_id'] ?? 1, 'fp_entities', 'entity', true, true);
    $active = !isset($p['active']) || !empty($p['active']) ? 1 : 0;
    if ($id) {
        $b = fp_bank_account($id);
        if (!$b) {
            throw new InvalidArgumentException('That account no longer exists.');
        }
        if (!$active && fp_one('SELECT 1 FROM fp_payments WHERE bank_account_id = ? AND status IN ("submitted","authorized","released") LIMIT 1', 'i', [$id])) {
            throw new InvalidArgumentException('Payments from this account are in progress; finish them before deactivating it.');
        }
        fp_exec('UPDATE fp_bank_accounts SET name=?, kind=?, bank=?, account_no=?, ifsc=?, active=?, entity_id=? WHERE id=?', 'sssssiii',
                [$name, $kind, $bank ?: null, $no ?: null, $ifsc ?: null, $active, $entity, $id]);
        fp_exec('UPDATE fp_accounts SET name = ? WHERE id = ?', 'si', [$name, (int) $b['account_id']]);
        fp_audit('bank', $id, 'update', 'Bank account ' . $name . ' updated');
        return $id;
    }
    // Its own ledger account: under Cash and bank, or (a corporate card) under Liabilities.
    $parent = fp_one('SELECT id FROM fp_accounts WHERE code = ? AND is_group = 1', 's', [$kind === 'card' ? '2000' : '1100']);
    $code = fp_next_account_code($kind === 'card' ? '2141' : '1121');
    $acc = fp_exec('INSERT INTO fp_accounts (code, name, type, subtype, parent_id) VALUES (?,?,?,?,?)', 'ssssi',
                   [$code, $name, $kind === 'card' ? 'liability' : 'asset', $kind === 'card' ? 'current_liability' : ($kind === 'cash' ? 'cash' : 'bank'),
                    $parent ? (int) $parent['id'] : null]);
    $id = fp_exec('INSERT INTO fp_bank_accounts (entity_id, name, kind, bank, account_no, ifsc, currency, account_id) VALUES (?,?,?,?,?,?,?,?)', 'issssssi',
                  [$entity, $name, $kind, $bank ?: null, $no ?: null, $ifsc ?: null, $currency, $acc]);
    fp_audit('bank', $id, 'create', ucfirst($kind) . ' account ' . $name . ' added (ledger account ' . $code . ')');
    return $id;
}

/** The next free numeric code after $start, e.g. 1122 after 1121. */
function fp_next_account_code(string $start): string
{
    $n = (int) $start;
    while (fp_one('SELECT id FROM fp_accounts WHERE code = ?', 's', [(string) $n])) {
        $n++;
    }
    return (string) $n;
}

/* ================================================================ payments */

function fp_payment(int $id): ?array
{
    return fp_one('SELECT * FROM fp_payments WHERE id = ?', 'i', [$id]);
}

function fp_payment_allocs(int $id): array
{
    return fp_all('SELECT * FROM fp_payment_allocs WHERE payment_id = ? ORDER BY id', 'i', [$id]);
}

function fp_payment_statuses(): array
{
    return ['draft' => ['Draft', ''], 'submitted' => ['Awaiting authorisation', 'amber'], 'authorized' => ['Authorised — not sent', 'blue'],
            'released' => ['Sent to bank', 'blue'], 'completed' => ['Completed', 'green'], 'failed' => ['Failed', 'red'], 'rejected' => ['Rejected', 'red'],
            'cancelled' => ['Cancelled', '']];
}

function fp_payment_kinds(): array
{
    return ['vendor' => 'Vendor payment', 'claim' => 'Expense reimbursement', 'advance' => 'Travel advance', 'request' => 'Payment request', 'tax' => 'Tax / statutory payment'];
}

/**
 * Prepares a payment. $p['alloc'] is doc id => amount for vendor bills,
 * claims or advances. A payment request (kind 'request') may be raised by
 * anyone in the organisation; everything else needs pay.prepare.
 */
function fp_payment_create(array $p): int
{
    $kind = (string) ($p['kind'] ?? 'vendor');
    if (!isset(fp_payment_kinds()[$kind])) {
        throw new InvalidArgumentException('Choose what kind of payment this is.');
    }
    if ($kind === 'request') {
        if (!fp_self_writable()) {
            fabo_deny('Finance is view only here.', 403);
        }
    } else {
        fp_need('pay.prepare');
    }
    $date = fp_date($p['pay_date'] ?? date('Y-m-d'), true, 'Pay on');
    $bank = fp_ref($p['bank_account_id'] ?? null, 'fp_bank_accounts', 'bank account', $kind !== 'request', true);
    $method = in_array($p['method'] ?? '', ['neft', 'rtgs', 'imps', 'upi', 'cheque', 'cash', 'card', 'other'], true) ? $p['method'] : 'neft';
    $currency = fp_currency($p['currency'] ?? '');
    if ($bank && fp_bank_account($bank)['currency'] !== $currency) {
        throw new InvalidArgumentException('The bank account is in ' . fp_bank_account($bank)['currency'] . '; the payment must be too.');
    }
    $rate = fp_fx($currency, $p['fx_rate'] ?? '', $date);
    $purpose = fp_str($p['purpose'] ?? '', 500, $kind === 'request' || $kind === 'tax', 'What the payment is for');
    $allocs = [];
    $total = 0;
    $payeeType = 'other';
    $payeeId = null;
    $payeeName = '';
    $account = null;
    $cc = null;
    $project = null;
    if (in_array($kind, ['vendor', 'claim', 'advance'], true)) {
        foreach ((array) ($p['alloc'] ?? []) as $docId => $amt) {
            $a = fp_dec($amt, false, 'Amount', 0);
            if ($a === null || (float) $a <= 0) {
                continue;
            }
            [$open, $doc, $who] = fp_payable_open($kind, (int) $docId);
            if ((float) $a > $open + 0.004) {
                throw new InvalidArgumentException('Only ' . fp_money($open) . ' of ' . ($doc['number'] ?? 'that document') . ' is still open to pay.');
            }
            if (($doc['currency'] ?? $currency) !== $currency) {
                throw new InvalidArgumentException($doc['number'] . ' is in ' . $doc['currency'] . '; pay it in the same currency.');
            }
            if ($payeeId !== null && $payeeId !== $who[1]) {
                throw new InvalidArgumentException('One payment goes to one payee. Use a batch to pay several.');
            }
            [$payeeType, $payeeId, $payeeName] = $who;
            $allocs[] = [(int) $docId, $a];
            $total += fp_paise($a);
        }
        if (!$allocs) {
            throw new InvalidArgumentException('Choose what this payment settles, and how much of each.');
        }
        if ($kind === 'vendor' && fp_vendor($payeeId)['status'] !== 'active') {
            throw new InvalidArgumentException('This vendor is not active; it cannot be paid.');
        }
    } else {
        $total = fp_paise(fp_dec($p['amount'] ?? '', true, 'Amount', 0.01));
        $account = fp_ref($p['account_id'] ?? null, 'fp_accounts', 'account', true, true);
        $acc = fp_account($account);
        if ((int) $acc['is_group'] === 1 || in_array($acc['subtype'], ['bank', 'cash'], true)) {
            throw new InvalidArgumentException('Charge the payment to an expense, payable or tax account (not a group or a bank account).');
        }
        if ($kind === 'request' && $acc['type'] !== 'expense' && $acc['type'] !== 'asset') {
            throw new InvalidArgumentException('A payment request is charged to an expense or asset account.');
        }
        $cc = fp_ref($p['cost_centre_id'] ?? null, 'fp_cost_centres', 'cost centre', $kind === 'request', true);
        $project = fp_ref($p['project_id'] ?? null, 'fp_projects', 'project');
        $payeeName = fp_str($p['payee_name'] ?? '', 190, true, 'Who is paid');
        $vendor = fp_ref($p['payee_vendor_id'] ?? null, 'fp_vendors', 'vendor');
        if ($vendor) {
            if (fp_vendor($vendor)['status'] !== 'active') {
                throw new InvalidArgumentException('That vendor is not active.');
            }
            $payeeType = 'vendor';
            $payeeId = $vendor;
            $payeeName = fp_vendor($vendor)['name'];
        }
    }
    return fp_tx(static function () use ($kind, $payeeType, $payeeId, $payeeName, $bank, $date, $method, $currency, $rate, $total, $purpose, $account, $cc, $project, $allocs) {
        $id = fp_exec('INSERT INTO fp_payments (number, kind, payee_type, payee_id, payee_name, bank_account_id, pay_date, method, currency, fx_rate, amount, purpose, account_id,
                       cost_centre_id, project_id, requester_person_id, prepared_by_id, prepared_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', 'sssisissssssiiiiis',
                      [fp_next_no('PAY', $date), $kind, $payeeType, $payeeId, mb_substr($payeeName, 0, 190), $bank, $date, $method, $currency, $rate, fp_amt($total / 100),
                       $purpose, $account, $cc, $project, $kind === 'request' ? fp_me() : null, fp_me(), fp_actor()]);
        foreach ($allocs as [$docId, $a]) {
            fp_exec('INSERT INTO fp_payment_allocs (payment_id, doc_type, doc_id, amount) VALUES (?,?,?,?)', 'isis', [$id, $kind === 'vendor' ? 'bill' : $kind, $docId, $a]);
        }
        fp_attach_many('payment', $id);
        fp_audit('payment', $id, 'create', fp_payment_kinds()[$kind] . ' prepared: ' . $payeeName . ' ' . fp_money($total / 100));
        return $id;
    });
}

/**
 * What is still open to pay on a bill, claim or advance, and who it is
 * paid to: [open, doc, [payee_type, payee_id, name]]. Open excludes what
 * other payments in progress already cover.
 */
function fp_payable_open(string $kind, int $docId): array
{
    if ($kind === 'vendor') {
        $b = fp_bill($docId);
        if (!$b || $b['kind'] !== 'bill' || !in_array($b['status'], ['approved', 'partially_paid'], true)) {
            throw new InvalidArgumentException('Only approved, unpaid bills can be paid (a bill on hold is not).');
        }
        $v = fp_vendor((int) $b['vendor_id']);
        return [fp_r2((float) $b['total'] - (float) $b['paid_amount'] - fp_ap_pending($docId)), $b, ['vendor', (int) $b['vendor_id'], $v['name']]];
    }
    $type = $kind === 'claim' ? 'claim' : 'advance';
    $d = fp_doc($type, $docId);
    if (!$d || $d['status'] !== 'approved') {
        throw new InvalidArgumentException('Only an approved ' . ($kind === 'claim' ? 'claim' : 'advance') . ' can be paid.');
    }
    $due = $type === 'claim' ? (float) $d['net_payable'] : (float) $d['amount'];
    $pending = fp_val('SELECT COALESCE(SUM(a.amount),0) FROM fp_payment_allocs a JOIN fp_payments p ON p.id = a.payment_id
                        WHERE a.doc_type = ? AND a.doc_id = ? AND p.status IN ("draft","submitted","authorized","released")', 'si', [$type, $docId]);
    return [fp_r2($due - (float) $d['paid_amount'] - $pending), $d + ['currency' => fp_setting('base_currency')], ['employee', (int) $d['employee_person_id'], $d['employee_name']]];
}

function fp_payment_submit(int $id, $version): string
{
    $pay = fp_payment($id);
    if (!$pay || $pay['status'] !== 'draft' || $pay['batch_id']) {
        throw new InvalidArgumentException('Only a draft payment outside a batch can be submitted on its own.');
    }
    if ($pay['kind'] === 'request' ? ((int) $pay['requester_person_id'] !== fp_me() && !fp_can('pay.prepare')) : !fp_can('pay.prepare')) {
        fabo_deny('You cannot submit this payment.', 403);
    }
    return fp_tx(static function () use ($id, $pay, $version) {
        fp_payment_recheck($pay);
        fp_bump('fp_payments', $id, $version);
        fp_exec('UPDATE fp_payments SET status = "submitted", return_note = NULL WHERE id = ?', 'i', [$id]);
        fp_audit('payment', $id, 'submit', 'Payment submitted for authorisation');
        fp_wf_start('payment', $id, (float) $pay['amount'] * (float) $pay['fx_rate'], $pay['cost_centre_id'] ? (int) $pay['cost_centre_id'] : null, (int) $pay['entity_id'],
                    (int) ($pay['requester_person_id'] ?? $pay['prepared_by_id']));
        return 'Submitted for authorisation.';
    });
}

/** The documents a payment settles must still be open for what it pays. */
function fp_payment_recheck(array $pay): void
{
    $kind = (string) $pay['kind'];
    foreach (fp_payment_allocs((int) $pay['id']) as $a) {
        [$open] = fp_payable_open($kind === 'vendor' ? 'vendor' : $kind, (int) $a['doc_id']);
        // Its own allocation is among the "pending" ones, so add it back.
        if ((float) $a['amount'] > $open + (float) $a['amount'] + 0.004) {
            throw new InvalidArgumentException('Something this payment settles has since been paid. Prepare it again.');
        }
    }
    if ($kind === 'vendor' && $pay['payee_id'] && fp_vendor((int) $pay['payee_id'])['status'] !== 'active') {
        throw new InvalidArgumentException('The vendor is no longer active.');
    }
}

function fp_wf_on_payment(string $event, array $pay, string $comment): void
{
    $id = (int) $pay['id'];
    if ($event === 'approved') {
        fp_exec('UPDATE fp_payments SET status = "authorized", authorized_by = ?, authorized_at = NOW() WHERE id = ?', 'si', [fp_actor(), $id]);
        fp_notify(fp_role_holders(['finance_manager', 'ap_accountant']) ?: [fp_head_id()], 'Payment authorised — ready to release',
                  $pay['number'] . ' to ' . $pay['payee_name'] . ' (' . fp_money($pay['amount']) . ')', 'payment', ['id' => $id]);
        return;
    }
    fp_exec('UPDATE fp_payments SET status = ?, return_note = ? WHERE id = ?', 'ssi', [$event === 'rejected' ? 'rejected' : 'draft', $comment, $id]);
}

/** Batches: several draft payments from one bank account, authorised together and released as one bank file. */
function fp_batch_create(array $p): int
{
    fp_need('pay.prepare');
    $bank = fp_ref($p['bank_account_id'] ?? null, 'fp_bank_accounts', 'bank account', true, true);
    $date = fp_date($p['pay_date'] ?? '', true, 'Pay on');
    $ids = array_values(array_unique(array_map('intval', (array) ($p['payment_ids'] ?? []))));
    if (count($ids) < 1) {
        throw new InvalidArgumentException('Choose the draft payments to put in the batch.');
    }
    return fp_tx(static function () use ($bank, $date, $ids) {
        $total = 0;
        foreach ($ids as $pid) {
            $pay = fp_one('SELECT * FROM fp_payments WHERE id = ? FOR UPDATE', 'i', [$pid]);
            if (!$pay || $pay['status'] !== 'draft' || $pay['batch_id']) {
                throw new InvalidArgumentException('Only draft payments not already in a batch can be batched.');
            }
            if ((int) $pay['bank_account_id'] !== $bank && $pay['bank_account_id'] !== null) {
                throw new InvalidArgumentException($pay['number'] . ' is to be paid from a different account.');
            }
            $total += fp_paise($pay['amount']);
        }
        $id = fp_exec('INSERT INTO fp_payment_batches (number, bank_account_id, pay_date, total, count, prepared_by_id, prepared_by) VALUES (?,?,?,?,?,?,?)', 'sissiis',
                      [fp_next_no('PB', $date), $bank, $date, fp_amt($total / 100), count($ids), fp_me(), fp_actor()]);
        foreach ($ids as $pid) {
            fp_exec('UPDATE fp_payments SET batch_id = ?, bank_account_id = ?, pay_date = ? WHERE id = ?', 'iisi', [$id, $bank, $date, $pid]);
        }
        fp_audit('batch', $id, 'create', 'Payment batch of ' . count($ids) . ' (' . fp_money($total / 100) . ')');
        return $id;
    });
}

function fp_batch(int $id): ?array
{
    return fp_one('SELECT * FROM fp_payment_batches WHERE id = ?', 'i', [$id]);
}

function fp_batch_submit(int $id, $version): string
{
    fp_need('pay.prepare');
    $b = fp_batch($id);
    if (!$b || $b['status'] !== 'draft') {
        throw new InvalidArgumentException('Only a draft batch can be submitted.');
    }
    return fp_tx(static function () use ($id, $b, $version) {
        foreach (fp_all('SELECT * FROM fp_payments WHERE batch_id = ?', 'i', [$id]) as $pay) {
            fp_payment_recheck($pay);
        }
        fp_bump('fp_payment_batches', $id, $version);
        fp_exec('UPDATE fp_payment_batches SET status = "submitted" WHERE id = ?', 'i', [$id]);
        fp_exec('UPDATE fp_payments SET status = "submitted" WHERE batch_id = ?', 'i', [$id]);
        fp_audit('batch', $id, 'submit', 'Batch submitted for authorisation');
        fp_wf_start('batch', $id, (float) $b['total'], null, 1, (int) $b['prepared_by_id']);
        return 'Batch submitted for authorisation.';
    });
}

function fp_wf_on_batch(string $event, array $b, string $comment): void
{
    $id = (int) $b['id'];
    if ($event === 'approved') {
        fp_exec('UPDATE fp_payment_batches SET status = "authorized" WHERE id = ?', 'i', [$id]);
        fp_exec('UPDATE fp_payments SET status = "authorized", authorized_by = ?, authorized_at = NOW() WHERE batch_id = ?', 'si', [fp_actor(), $id]);
        return;
    }
    fp_exec('UPDATE fp_payment_batches SET status = ?, return_note = ? WHERE id = ?', 'ssi', [$event === 'rejected' ? 'rejected' : 'draft', $comment, $id]);
    if ($event === 'rejected') {
        fp_exec('UPDATE fp_payments SET status = "draft", batch_id = NULL WHERE batch_id = ?', 'i', [$id]);
    } else {
        fp_exec('UPDATE fp_payments SET status = "draft" WHERE batch_id = ?', 'i', [$id]);
    }
}

/** Sends authorised payments to the bank. Nothing is paid yet: that waits for the bank's reference. */
function fp_payment_release(array $ids, ?int $batchId = null): int
{
    fp_need('pay.execute');
    return fp_tx(static function () use ($ids, $batchId) {
        $n = 0;
        if ($batchId) {
            $b = fp_one('SELECT * FROM fp_payment_batches WHERE id = ? FOR UPDATE', 'i', [$batchId]);
            if (!$b || $b['status'] !== 'authorized') {
                throw new InvalidArgumentException('Only an authorised batch can be released.');
            }
            $ids = array_map(static fn($r) => (int) $r['id'], fp_all('SELECT id FROM fp_payments WHERE batch_id = ? AND status = "authorized"', 'i', [$batchId]));
            fp_exec('UPDATE fp_payment_batches SET status = "released", released_at = NOW(), released_by = ? WHERE id = ?', 'si', [fp_actor(), $batchId]);
        }
        foreach ($ids as $pid) {
            $pay = fp_one('SELECT * FROM fp_payments WHERE id = ? FOR UPDATE', 'i', [(int) $pid]);
            if (!$pay || $pay['status'] !== 'authorized') {
                throw new InvalidArgumentException('Only authorised payments can be released.');
            }
            if (!$pay['bank_account_id']) {
                throw new InvalidArgumentException($pay['number'] . ' has no bank account to pay from. Choose one first.');
            }
            fp_exec('UPDATE fp_payments SET status = "released" WHERE id = ?', 'i', [(int) $pid]);
            fp_audit('payment', (int) $pid, 'release', 'Released to the bank');
            $n++;
        }
        return $n;
    });
}

/** Sets the bank account of an authorised payment request (Treasury decides where it is paid from). */
function fp_payment_set_bank(int $id, int $bank): void
{
    fp_need('pay.execute');
    $pay = fp_payment($id);
    $acc = fp_bank_account($bank);
    if (!$pay || !in_array($pay['status'], ['authorized', 'draft'], true) || !$acc || (int) $acc['active'] !== 1) {
        throw new InvalidArgumentException('Choose an active account for a payment not yet released.');
    }
    if ($acc['currency'] !== $pay['currency']) {
        throw new InvalidArgumentException('The account is in ' . $acc['currency'] . '; the payment is in ' . $pay['currency'] . '.');
    }
    fp_exec('UPDATE fp_payments SET bank_account_id = ? WHERE id = ?', 'ii', [$bank, $id]);
    fp_audit('payment', $id, 'bank', 'To be paid from ' . $acc['name']);
}

/**
 * Records the bank's confirmation: the one way a payment becomes completed.
 * Posts it to the ledger and settles what it paid.
 */
function fp_payment_complete(int $id, string $bankRef, string $date): string
{
    fp_need('pay.execute');
    $bankRef = fp_str($bankRef, 120, true, 'The bank reference (UTR, cheque number or receipt)');
    $date = fp_date($date, true, 'Paid on');
    if ($date > date('Y-m-d')) {
        throw new InvalidArgumentException('A payment cannot be completed in the future.');
    }
    return fp_tx(static function () use ($id, $bankRef, $date) {
        $pay = fp_one('SELECT * FROM fp_payments WHERE id = ? FOR UPDATE', 'i', [$id]);
        $cashLike = in_array($pay['method'] ?? '', ['cash', 'cheque'], true);
        if (!$pay || !($pay['status'] === 'released' || ($cashLike && $pay['status'] === 'authorized'))) {
            throw new InvalidArgumentException('Only a payment released to the bank (or an authorised cash or cheque payment) can be completed.');
        }
        $bank = fp_bank_account((int) $pay['bank_account_id']);
        if (!$bank) {
            throw new InvalidArgumentException('Choose the account it was paid from first.');
        }
        $rate = (float) $pay['fx_rate'];
        $lines = [];
        $base = fp_r2((float) $pay['amount'] * $rate);
        $debited = 0.0;
        if (in_array($pay['kind'], ['vendor', 'claim', 'advance'], true)) {
            $acct = ['vendor' => fp_acct('ap'), 'claim' => fp_acct('emp_payable'), 'advance' => fp_acct('emp_adv')][$pay['kind']];
            foreach (fp_payment_allocs($id) as $a) {
                $docRate = $pay['kind'] === 'vendor' ? (float) fp_bill((int) $a['doc_id'])['fx_rate'] : 1.0;
                $amt = fp_r2((float) $a['amount'] * $docRate);
                $debited += $amt;
                $lines[] = ['account_id' => $acct, 'debit' => $amt, 'credit' => 0, 'description' => 'Settles ' . fp_doc_label_no($pay['kind'], (int) $a['doc_id']),
                            'party_type' => $pay['kind'] === 'vendor' ? 'vendor' : 'employee', 'party_id' => (int) $pay['payee_id']];
            }
        } else {
            $debited = $base;
            $lines[] = ['account_id' => (int) $pay['account_id'], 'debit' => $base, 'credit' => 0, 'cost_centre_id' => $pay['cost_centre_id'], 'project_id' => $pay['project_id'],
                        'description' => $pay['purpose'], 'party_type' => $pay['payee_type'] === 'vendor' ? 'vendor' : null, 'party_id' => $pay['payee_type'] === 'vendor' ? (int) $pay['payee_id'] : null];
        }
        $lines[] = ['account_id' => (int) $bank['account_id'], 'debit' => 0, 'credit' => $base, 'description' => 'Paid ' . $pay['payee_name'] . ' — ' . $bankRef];
        $fx = fp_r2($base - $debited);
        if (fp_paise($fx) !== 0) {
            // Paid at a different rate from the one the bill was booked at.
            $lines[] = ['account_id' => fp_acct('fx_gain_loss'), 'debit' => $fx > 0 ? $fx : 0, 'credit' => $fx < 0 ? -$fx : 0, 'description' => 'Exchange difference'];
        }
        $jid = fp_journal_system(['entity_id' => (int) $pay['entity_id'], 'jdate' => $date, 'type' => 'system', 'source' => 'payment', 'source_id' => $id,
                                  'reference' => $bankRef, 'description' => fp_payment_kinds()[$pay['kind']] . ' ' . $pay['number'] . ' — ' . $pay['payee_name']], $lines);
        fp_exec('UPDATE fp_payments SET status = "completed", bank_ref = ?, completed_on = ?, journal_id = ? WHERE id = ?', 'ssii', [$bankRef, $date, $jid, $id]);
        foreach (fp_payment_allocs($id) as $a) {
            if ($a['doc_type'] === 'bill') {
                fp_bill_recalc((int) $a['doc_id']);
            } elseif ($a['doc_type'] === 'claim') {
                fp_claim_paid((int) $a['doc_id'], (float) $a['amount'], $bankRef, $date);
            } elseif ($a['doc_type'] === 'advance') {
                fp_exec('UPDATE fp_advances SET paid_amount = paid_amount + ?, status = IF(paid_amount + 0.001 >= amount, "paid", status) WHERE id = ?', 'si', [$a['amount'], (int) $a['doc_id']]);
            }
        }
        if ($pay['batch_id']) {
            fp_batch_refresh((int) $pay['batch_id']);
        }
        fp_audit('payment', $id, 'complete', 'Completed: bank reference ' . $bankRef . ', ' . fp_money($pay['amount']));
        if ($pay['requester_person_id']) {
            fp_notify([(int) $pay['requester_person_id']], 'Payment made', $pay['number'] . ' to ' . $pay['payee_name'] . ' was paid (ref ' . $bankRef . ').', 'payment', ['id' => $id]);
        }
        return 'Payment completed and posted to the ledger.';
    });
}

function fp_doc_label_no(string $kind, int $docId): string
{
    $type = $kind === 'vendor' ? 'bill' : $kind;
    $d = fp_doc($type, $docId);
    return $d ? (string) ($d['number'] . ($type === 'bill' && $d['bill_no'] ? ' (' . $d['bill_no'] . ')' : '')) : '#' . $docId;
}

/** The bank refused or returned it. Nothing was posted; what it paid is open again. */
function fp_payment_fail(int $id, string $reason): string
{
    fp_need('pay.execute');
    $reason = fp_str($reason, 500, true, 'What the bank said');
    return fp_tx(static function () use ($id, $reason) {
        $pay = fp_one('SELECT * FROM fp_payments WHERE id = ? FOR UPDATE', 'i', [$id]);
        if (!$pay || $pay['status'] !== 'released') {
            throw new InvalidArgumentException('Only a payment sent to the bank can fail.');
        }
        fp_exec('UPDATE fp_payments SET status = "failed", failure_reason = ? WHERE id = ?', 'si', [$reason, $id]);
        if ($pay['batch_id']) {
            fp_batch_refresh((int) $pay['batch_id']);
        }
        fp_audit('payment', $id, 'fail', 'Failed at the bank: ' . $reason);
        fp_notify(array_filter([(int) $pay['prepared_by_id']]), 'Payment failed', $pay['number'] . ' to ' . $pay['payee_name'] . ' failed: ' . $reason, 'payment', ['id' => $id]);
        return 'Marked failed. What it was paying is open again; retry it as a new payment when the problem is fixed.';
    });
}

/** A fresh draft of a failed payment, to be authorised again. */
function fp_payment_retry(int $id): int
{
    fp_need('pay.prepare');
    $pay = fp_payment($id);
    if (!$pay || $pay['status'] !== 'failed') {
        throw new InvalidArgumentException('Only a failed payment can be retried.');
    }
    if (fp_one('SELECT id FROM fp_payments WHERE retry_of = ? AND status NOT IN ("failed","cancelled","rejected")', 'i', [$id])) {
        throw new InvalidArgumentException('A retry of this payment already exists.');
    }
    $alloc = [];
    foreach (fp_payment_allocs($id) as $a) {
        $alloc[(int) $a['doc_id']] = $a['amount'];
    }
    $new = fp_payment_create(['kind' => $pay['kind'], 'pay_date' => max(date('Y-m-d'), (string) $pay['pay_date']), 'bank_account_id' => $pay['bank_account_id'], 'method' => $pay['method'],
                              'currency' => $pay['currency'], 'fx_rate' => $pay['fx_rate'], 'purpose' => $pay['purpose'], 'alloc' => $alloc, 'amount' => $pay['amount'],
                              'account_id' => $pay['account_id'], 'cost_centre_id' => $pay['cost_centre_id'], 'project_id' => $pay['project_id'], 'payee_name' => $pay['payee_name'],
                              'payee_vendor_id' => $pay['kind'] !== 'vendor' && $pay['payee_type'] === 'vendor' ? $pay['payee_id'] : null]);
    fp_exec('UPDATE fp_payments SET retry_of = ? WHERE id = ?', 'ii', [$id, $new]);
    return $new;
}

function fp_payment_cancel(int $id, string $reason, $version): void
{
    $pay = fp_payment($id);
    if (!$pay || !in_array($pay['status'], ['draft', 'rejected', 'authorized', 'submitted'], true)) {
        throw new InvalidArgumentException('A payment already sent to the bank cannot be cancelled here; record it as failed if the bank returned it.');
    }
    $own = $pay['kind'] === 'request' && (int) $pay['requester_person_id'] === fp_me() && in_array($pay['status'], ['draft', 'submitted'], true);
    if (!$own) {
        fp_need('pay.prepare');
    }
    fp_bump('fp_payments', $id, $version);
    fp_exec('UPDATE fp_payments SET status = "cancelled", batch_id = NULL WHERE id = ?', 'i', [$id]);
    fp_wf_cancel('payment', $id, 'Cancelled');
    if ($pay['batch_id']) {
        fp_batch_refresh((int) $pay['batch_id']);
    }
    fp_audit('payment', $id, 'cancel', 'Payment cancelled: ' . fp_str($reason, 500, true, 'The reason'));
}

function fp_batch_refresh(int $id): void
{
    $r = fp_one('SELECT COUNT(*) n, SUM(status = "completed") done, SUM(status = "failed") failed, SUM(status IN ("released","authorized")) open, COALESCE(SUM(amount),0) t
                   FROM fp_payments WHERE batch_id = ?', 'i', [$id]);
    $b = fp_batch($id);
    $status = $b['status'];
    if ((int) $r['n'] > 0 && (int) $r['open'] === 0 && in_array($b['status'], ['released', 'authorized', 'partly_failed'], true)) {
        $status = (int) $r['failed'] > 0 ? 'partly_failed' : 'completed';
    }
    fp_exec('UPDATE fp_payment_batches SET status = ?, count = ?, total = ? WHERE id = ?', 'sisi', [$status, (int) $r['n'], fp_amt($r['t']), $id]);
}

/** The bank upload file for a released batch (CSV: one line per payment, with the payee's bank details). */
function fp_batch_file(int $id): array
{
    fp_need('pay.execute');
    $b = fp_batch($id);
    if (!$b || !in_array($b['status'], ['released', 'completed', 'partly_failed'], true)) {
        throw new InvalidArgumentException('The bank file is made once the batch is released.');
    }
    $rows = [['Payment ref', 'Payee', 'Account number', 'IFSC', 'UPI', 'Method', 'Amount', 'Currency', 'Value date', 'Narration']];
    foreach (fp_all('SELECT * FROM fp_payments WHERE batch_id = ? AND status IN ("released","completed","failed") ORDER BY id', 'i', [$id]) as $p) {
        $acct = ['', '', ''];
        if ($p['payee_type'] === 'vendor' && $p['payee_id']) {
            $v = fp_vendor((int) $p['payee_id']);
            $acct = [(string) $v['bank_account'], (string) $v['bank_ifsc'], (string) $v['upi']];
        }
        $rows[] = [$p['number'], $p['payee_name'], $acct[0], $acct[1], $acct[2], strtoupper($p['method']), fp_amt($p['amount']), $p['currency'], $b['pay_date'],
                   mb_substr(preg_replace('/[^A-Za-z0-9 \-\/]/', ' ', $p['purpose'] ?: $p['number']), 0, 30)];
    }
    fp_audit('batch', $id, 'bank_file', 'Bank upload file downloaded');
    return $rows;
}

/* ====================================================== bank statements */

/** Reads a bank statement CSV into statement lines. Duplicates of lines already imported are skipped. */
function fp_bank_import(int $bankId, array $file): array
{
    fp_need('bank.manage');
    $b = fp_bank_account($bankId);
    if (!$b) {
        throw new InvalidArgumentException('Choose the bank account the statement is for.');
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
        throw new InvalidArgumentException('Choose the statement file (CSV) to import.');
    }
    if ((int) $file['size'] > 5 * 1024 * 1024) {
        throw new InvalidArgumentException('A statement file must be 5 MB or smaller.');
    }
    $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv', 'txt'], true)) {
        throw new InvalidArgumentException('Export the statement from your bank as CSV and import that.');
    }
    $rows = fp_csv_rows((string) file_get_contents($file['tmp_name']));
    return fp_bank_import_rows($bankId, $rows, (string) $file['name']);
}

function fp_csv_rows(string $raw): array
{
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    $rows = [];
    $fh = fopen('php://memory', 'r+');
    fwrite($fh, $raw);
    rewind($fh);
    $first = fgets($fh);
    $delim = substr_count((string) $first, ';') > substr_count((string) $first, ',') ? ';' : (substr_count((string) $first, "\t") > substr_count((string) $first, ',') ? "\t" : ',');
    rewind($fh);
    while (($r = fgetcsv($fh, 0, $delim, '"', '\\')) !== false) {
        if ($r !== [null]) {
            $rows[] = array_map(static fn($c) => trim((string) $c), $r);
        }
    }
    fclose($fh);
    return $rows;
}

/** Statement rows (first matching header row decides the columns) into fp_bank_lines. Returns [added, skipped]. */
function fp_bank_import_rows(int $bankId, array $rows, string $name): array
{
    $map = null;
    $start = 0;
    foreach ($rows as $i => $r) {
        $h = array_map(static fn($c) => strtolower(preg_replace('/[^a-z]/i', '', $c)), $r);
        $find = static function (array $names) use ($h) {
            foreach ($h as $k => $v) {
                foreach ($names as $n) {
                    if ($v === $n || ($v !== '' && str_starts_with($v, $n))) {
                        return $k;
                    }
                }
            }
            return null;
        };
        $date = $find(['txndate', 'transactiondate', 'valuedate', 'date', 'postdate', 'trandate']);
        $desc = $find(['description', 'narration', 'particulars', 'details', 'remarks', 'transactiondetails']);
        if ($date !== null && $desc !== null) {
            $map = ['date' => $date, 'desc' => $desc, 'ref' => $find(['chequeno', 'chqno', 'refno', 'reference', 'utr', 'chqrefno', 'ref']),
                    'out' => $find(['withdrawal', 'withdrawals', 'debit', 'dr', 'withdrawalamt', 'debitamount', 'amountout']),
                    'in' => $find(['deposit', 'deposits', 'credit', 'cr', 'depositamt', 'creditamount', 'amountin']),
                    'amt' => $find(['amount', 'amt']), 'bal' => $find(['balance', 'closingbalance', 'runningbalance'])];
            $start = $i + 1;
            break;
        }
    }
    if (!$map || ($map['out'] === null && $map['in'] === null && $map['amt'] === null)) {
        throw new InvalidArgumentException('The statement needs a header row with a date, a description and withdrawal/deposit (or amount) columns.');
    }
    $num = static fn($v) => $v === null || trim((string) $v) === '' ? 0.0 : (float) str_replace([',', '₹', ' ', 'CR', 'DR', 'Cr', 'Dr'], '', (string) $v);
    $added = 0;
    $skipped = 0;
    fp_tx(static function () use ($bankId, $rows, $start, $map, $num, $name, &$added, &$skipped) {
        $imp = fp_exec('INSERT INTO fp_bank_imports (bank_account_id, file_name, imported_by) VALUES (?,?,?)', 'iss', [$bankId, mb_substr($name, 0, 190), fp_actor()]);
        for ($i = $start, $n = count($rows); $i < $n; $i++) {
            $r = $rows[$i];
            $d = fp_parse_date((string) ($r[$map['date']] ?? ''));
            if (!$d) {
                $skipped++;
                continue;
            }
            $out = $map['out'] !== null ? abs($num($r[$map['out']] ?? '')) : 0.0;
            $in = $map['in'] !== null ? abs($num($r[$map['in']] ?? '')) : 0.0;
            if ($map['out'] === null && $map['in'] === null) {
                $a = $num($r[$map['amt']] ?? '');
                $out = $a < 0 ? -$a : 0;
                $in = $a > 0 ? $a : 0;
            }
            if ($out == 0 && $in == 0) {
                $skipped++;
                continue;
            }
            $desc = mb_substr((string) ($r[$map['desc']] ?? ''), 0, 255);
            $ref = $map['ref'] !== null ? mb_substr((string) ($r[$map['ref']] ?? ''), 0, 120) : '';
            $bal = $map['bal'] !== null && trim((string) ($r[$map['bal']] ?? '')) !== '' ? $num($r[$map['bal']]) : null;
            $hash = sha1($d . '|' . $desc . '|' . $ref . '|' . fp_amt($out) . '|' . fp_amt($in) . '|' . ($bal === null ? '' : fp_amt($bal)) . '|' . $i);
            $hashNoRow = sha1($d . '|' . $desc . '|' . $ref . '|' . fp_amt($out) . '|' . fp_amt($in) . '|' . ($bal === null ? '' : fp_amt($bal)));
            if (fp_one('SELECT id FROM fp_bank_lines WHERE bank_account_id = ? AND (hash = ? OR hash = ?)', 'iss', [$bankId, $hashNoRow, $hash])) {
                $skipped++;
                continue;
            }
            fp_exec('INSERT INTO fp_bank_lines (bank_account_id, import_id, txn_date, description, ref, withdrawal, deposit, balance, hash) VALUES (?,?,?,?,?,?,?,?,?)', 'iisssssss',
                    [$bankId, $imp, $d, $desc, $ref ?: null, fp_amt($out), fp_amt($in), $bal === null ? null : fp_amt($bal), $bal === null ? $hash : $hashNoRow]);
            $added++;
        }
        fp_exec('UPDATE fp_bank_imports SET line_count = ?, skipped = ? WHERE id = ?', 'iii', [$added, $skipped, $imp]);
    });
    fp_audit('bank', $bankId, 'import', 'Statement ' . $name . ' imported: ' . $added . ' lines, ' . $skipped . ' skipped');
    return [$added, $skipped];
}

/** Dates as banks write them: 2026-10-21, 21/10/2026, 21-10-26, 21 Oct 2026, 21-Oct-2026. */
function fp_parse_date(string $s): ?string
{
    $s = trim($s);
    if ($s === '') {
        return null;
    }
    foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'd/m/y', 'd-m-y', 'd M Y', 'd-M-Y', 'd-M-y', 'd M y', 'j/n/Y', 'Y/m/d', 'd/m/Y H:i:s', 'd-m-Y H:i:s', 'Y-m-d H:i:s'] as $fmt) {
        $d = DateTime::createFromFormat('!' . $fmt, $s);
        if ($d && $d->format($fmt) === $s) {
            return $d->format('Y-m-d');
        }
    }
    return null;
}

/** Ledger lines on a bank's account not yet matched to the statement. */
function fp_bank_unmatched_gl(int $bankId, ?string $to = null): array
{
    $b = fp_bank_account($bankId);
    return fp_all('SELECT l.id, l.debit_base, l.credit_base, l.description, j.jdate, j.number, j.reference, j.description AS jdesc
                     FROM fp_journal_lines l JOIN fp_journals j ON j.id = l.journal_id
                    WHERE l.account_id = ? AND j.status = "posted"' . ($to ? ' AND j.jdate <= ?' : '') . '
                      AND NOT EXISTS (SELECT 1 FROM fp_bank_lines s WHERE s.matched_line_id = l.id AND s.status = "matched")
                    ORDER BY j.jdate, l.id', $to ? 'is' : 'i', $to ? [(int) $b['account_id'], $to] : [(int) $b['account_id']]);
}

/**
 * Matches statement lines to the ledger by rule: same direction and amount
 * within five days, and only where exactly one ledger line qualifies (or one
 * whose reference appears in the statement narration). Anything uncertain is
 * left for a person.
 */
function fp_bank_automatch(int $bankId): int
{
    fp_need('bank.reconcile');
    $gl = fp_bank_unmatched_gl($bankId);
    $n = 0;
    $taken = [];
    foreach (fp_all('SELECT * FROM fp_bank_lines WHERE bank_account_id = ? AND status = "unmatched" ORDER BY txn_date, id', 'i', [$bankId]) as $s) {
        $isIn = (float) $s['deposit'] > 0;
        $amt = fp_paise($isIn ? $s['deposit'] : $s['withdrawal']);
        $cands = [];
        foreach ($gl as $g) {
            if (isset($taken[$g['id']])) {
                continue;
            }
            $gAmt = fp_paise($isIn ? $g['debit_base'] : $g['credit_base']);
            if ($gAmt !== $amt || abs(strtotime($g['jdate']) - strtotime($s['txn_date'])) > 5 * 86400) {
                continue;
            }
            $cands[] = $g;
        }
        if (count($cands) > 1) {
            $hay = strtoupper($s['description'] . ' ' . $s['ref']);
            $byRef = array_values(array_filter($cands, static fn($g) => ($g['reference'] && str_contains($hay, strtoupper((string) $g['reference'])))
                                                                       || ($g['number'] && str_contains($hay, strtoupper((string) $g['number'])))));
            $cands = count($byRef) === 1 ? $byRef : $cands;
        }
        if (count($cands) === 1) {
            fp_exec('UPDATE fp_bank_lines SET status = "matched", matched_line_id = ?, matched_by = ?, matched_at = NOW() WHERE id = ?', 'isi', [(int) $cands[0]['id'], 'Auto-match', (int) $s['id']]);
            $taken[$cands[0]['id']] = true;
            $n++;
        }
    }
    if ($n) {
        fp_audit('bank', $bankId, 'automatch', $n . ' statement line(s) matched automatically');
    }
    return $n;
}

function fp_bank_match(int $lineId, int $glLineId): void
{
    fp_need('bank.reconcile');
    $s = fp_one('SELECT * FROM fp_bank_lines WHERE id = ?', 'i', [$lineId]);
    if (!$s || $s['status'] !== 'unmatched') {
        throw new InvalidArgumentException('That statement line is already dealt with.');
    }
    $b = fp_bank_account((int) $s['bank_account_id']);
    $g = fp_one('SELECT l.* FROM fp_journal_lines l JOIN fp_journals j ON j.id = l.journal_id WHERE l.id = ? AND j.status = "posted"', 'i', [$glLineId]);
    if (!$g || (int) $g['account_id'] !== (int) $b['account_id']) {
        throw new InvalidArgumentException('Match to a posted entry on this bank account.');
    }
    if (fp_one('SELECT id FROM fp_bank_lines WHERE matched_line_id = ? AND status = "matched"', 'i', [$glLineId])) {
        throw new InvalidArgumentException('That ledger entry is already matched to another statement line.');
    }
    $isIn = (float) $s['deposit'] > 0;
    if (fp_paise($isIn ? $s['deposit'] : $s['withdrawal']) !== fp_paise($isIn ? $g['debit_base'] : $g['credit_base'])) {
        throw new InvalidArgumentException('The amounts differ. Post the difference (bank charges, interest) as its own entry, then match.');
    }
    fp_exec('UPDATE fp_bank_lines SET status = "matched", matched_line_id = ?, matched_by = ?, matched_at = NOW() WHERE id = ?', 'isi', [$glLineId, fp_actor(), $lineId]);
    fp_audit('bank', (int) $s['bank_account_id'], 'match', 'Statement line of ' . $s['txn_date'] . ' matched by hand');
}

function fp_bank_unmatch(int $lineId): void
{
    fp_need('bank.reconcile');
    fp_exec('UPDATE fp_bank_lines SET status = "unmatched", matched_line_id = NULL, matched_by = NULL, matched_at = NULL WHERE id = ?', 'i', [$lineId]);
    fp_audit('bank', null, 'unmatch', 'Statement line #' . $lineId . ' unmatched');
}

function fp_bank_ignore(int $lineId, string $reason): void
{
    fp_need('bank.reconcile');
    $reason = fp_str($reason, 255, true, 'Why it is ignored');
    fp_exec('UPDATE fp_bank_lines SET status = "ignored", matched_by = ?, matched_at = NOW() WHERE id = ? AND status = "unmatched"', 'si', [fp_actor() . ': ' . $reason, $lineId]);
    fp_audit('bank', null, 'ignore', 'Statement line #' . $lineId . ' ignored: ' . $reason);
}

/** Posts what only the bank knew about (charges, interest) from a statement line, and matches it. */
function fp_bank_entry_from_line(int $lineId, int $accountId, string $desc): int
{
    fp_need('bank.reconcile');
    $s = fp_one('SELECT * FROM fp_bank_lines WHERE id = ?', 'i', [$lineId]);
    if (!$s || $s['status'] !== 'unmatched') {
        throw new InvalidArgumentException('That statement line is already dealt with.');
    }
    $b = fp_bank_account((int) $s['bank_account_id']);
    $a = fp_account($accountId);
    if (!$a || (int) $a['is_group'] === 1 || (int) $a['active'] !== 1 || $accountId === (int) $b['account_id']) {
        throw new InvalidArgumentException('Choose the account the bank item belongs to (bank charges, interest income …).');
    }
    $isIn = (float) $s['deposit'] > 0;
    $amt = (float) ($isIn ? $s['deposit'] : $s['withdrawal']);
    $desc = fp_str($desc, 255) ?: $s['description'];
    return fp_tx(static function () use ($s, $b, $accountId, $isIn, $amt, $desc, $lineId) {
        $jid = fp_journal_system(['entity_id' => (int) $b['entity_id'], 'jdate' => $s['txn_date'], 'type' => 'adjustment', 'source' => 'bank', 'source_id' => $lineId,
                                  'reference' => $s['ref'], 'description' => 'Bank statement: ' . $desc],
                                 [['account_id' => (int) $b['account_id'], 'debit' => $isIn ? $amt : 0, 'credit' => $isIn ? 0 : $amt, 'description' => $desc],
                                  ['account_id' => $accountId, 'debit' => $isIn ? 0 : $amt, 'credit' => $isIn ? $amt : 0, 'description' => $desc]]);
        $gl = fp_one('SELECT id FROM fp_journal_lines WHERE journal_id = ? AND account_id = ?', 'ii', [$jid, (int) $b['account_id']]);
        fp_exec('UPDATE fp_bank_lines SET status = "matched", matched_line_id = ?, matched_by = ?, matched_at = NOW() WHERE id = ?', 'isi', [(int) $gl['id'], fp_actor(), $lineId]);
        return $jid;
    });
}

/**
 * The reconciliation at a date: the ledger balance, the statement balance,
 * and what explains the difference — payments and receipts in the books
 * not yet on the statement, and statement items not yet in the books.
 */
function fp_bank_rec(int $bankId, string $asOf): array
{
    $b = fp_bank_account($bankId);
    $book = fp_balances(['to' => $asOf], false)[(int) $b['account_id']] ?? [0, 0];
    $bookBal = fp_r2($book[0] - $book[1]);
    $last = fp_one('SELECT balance, txn_date FROM fp_bank_lines WHERE bank_account_id = ? AND txn_date <= ? AND balance IS NOT NULL ORDER BY txn_date DESC, id DESC LIMIT 1', 'is', [$bankId, $asOf]);
    $gl = fp_bank_unmatched_gl($bankId, $asOf);
    $notInBank = ['in' => 0.0, 'out' => 0.0];
    foreach ($gl as $g) {
        $notInBank['in'] += (float) $g['debit_base'];
        $notInBank['out'] += (float) $g['credit_base'];
    }
    $notInBook = fp_one('SELECT COALESCE(SUM(deposit),0) i, COALESCE(SUM(withdrawal),0) o, COUNT(*) n FROM fp_bank_lines WHERE bank_account_id = ? AND status = "unmatched" AND txn_date <= ?',
                        'is', [$bankId, $asOf]);
    $stmt = $last ? (float) $last['balance'] : null;
    $expected = $stmt !== null ? fp_r2($stmt + $notInBank['in'] - $notInBank['out'] - (float) $notInBook['i'] + (float) $notInBook['o']) : null;
    return ['book' => $bookBal, 'statement' => $stmt, 'statement_date' => $last['txn_date'] ?? null, 'gl_open' => $gl,
            'not_in_bank_in' => fp_r2($notInBank['in']), 'not_in_bank_out' => fp_r2($notInBank['out']),
            'not_in_book_in' => fp_r2($notInBook['i']), 'not_in_book_out' => fp_r2($notInBook['o']), 'not_in_book_n' => (int) $notInBook['n'],
            'difference' => $expected !== null ? fp_r2($bookBal - $expected) : null];
}

/* ========================================================== cash forecast */

/**
 * Cash over the next weeks: today's cash and bank, plus customer invoices
 * by due date, less bills, approved claims and payments in progress by when
 * they fall due. A management estimate, not accounting data — overdue items
 * are assumed to clear in the first week.
 */
function fp_cash_forecast(int $weeks = 13, ?int $entity = null): array
{
    $f = $entity ? ['entity' => $entity] : [];
    $opening = array_sum(array_map(static fn($b) => $b['balance'], array_filter(fp_cash_position(null, $f), static fn($b) => $b['kind'] !== 'card')));
    $start = strtotime('monday this week');
    $out = [];
    for ($w = 0; $w < $weeks; $w++) {
        $out[$w] = ['from' => date('Y-m-d', $start + $w * 7 * 86400), 'in' => 0.0, 'out' => 0.0];
    }
    $slot = static function (?string $d) use ($start, $weeks) {
        if (!$d) {
            return 0;
        }
        $w = (int) floor((strtotime($d) - $start) / (7 * 86400));
        return $w < 0 ? 0 : ($w >= $weeks ? null : $w);
    };
    foreach (fp_ar_open() as $i) {
        if (($w = $slot($i['due_date'])) !== null && !$i['disputed']) {
            $out[$w]['in'] += $i['open_base'];
        }
    }
    foreach (fp_bills_payable() as $b) {
        if (($w = $slot($b['scheduled_on'] ?: $b['due_date'])) !== null) {
            $out[$w]['out'] += $b['open'] * (float) $b['fx_rate'];
        }
    }
    foreach (fp_all('SELECT pay_date, amount * fx_rate amt FROM fp_payments WHERE status IN ("submitted","authorized","released")') as $p) {
        if (($w = $slot($p['pay_date'])) !== null) {
            $out[$w]['out'] += (float) $p['amt'];
        }
    }
    foreach (fp_all('SELECT net_payable - paid_amount amt FROM fp_claims WHERE status = "approved"') as $c) {
        $out[0]['out'] += max(0, (float) $c['amt']);
    }
    $bal = $opening;
    foreach ($out as &$o) {
        $o['in'] = fp_r2($o['in']);
        $o['out'] = fp_r2($o['out']);
        $bal += $o['in'] - $o['out'];
        $o['closing'] = fp_r2($bal);
    }
    unset($o);
    return ['opening' => fp_r2($opening), 'weeks' => $out];
}

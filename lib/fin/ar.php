<?php
/**
 * Finance Portal — receivables.
 *
 *   Customer   with payment terms and an optional credit limit; for our own
 *              organisation, linked to the portal client it bills.
 *   Invoice    drafted, approved, then issued: posted as the customer's
 *              receivable against revenue and output GST. A credit note is
 *              the mirror image and is applied against an invoice.
 *   Receipt    money in, posted to the bank against the customer's
 *              receivable at once; allocated to invoices now or later. What is
 *              not allocated is the customer's credit (an excess or advance
 *              payment), shown as unallocated until it is.
 *
 * Invoice status: draft → submitted → issued → partially_paid → paid;
 * rejected from approval, returned to draft, cancelled before issue. A
 * dispute is a flag on an open invoice (reminders stop), not a status.
 *
 * Bringing in the portal's own billing: our organisation's invoices to its
 * clients (lib/invoices.php) can be brought into receivables, and the spend
 * log (lib/finance.php) into the ledger, once each — see the bottom of this
 * file.
 */

function fp_customer(int $id): ?array
{
    return fp_one('SELECT * FROM fp_customers WHERE id = ?', 'i', [$id]);
}

function fp_customer_options(bool $activeOnly = true): array
{
    $out = [];
    foreach (fp_all('SELECT id, code, name FROM fp_customers' . ($activeOnly ? ' WHERE status = "active"' : '') . ' ORDER BY name') as $r) {
        $out[(int) $r['id']] = $r['name'] . ' (' . $r['code'] . ')';
    }
    return $out;
}

function fp_customer_save(?int $id, array $p): int
{
    fp_need('customer.manage');
    $v = [
        'name' => fp_str($p['name'] ?? '', 190, true, 'Customer name'),
        'legal_name' => fp_str($p['legal_name'] ?? '', 190),
        'gstin' => strtoupper(fp_str($p['gstin'] ?? '', 20)),
        'pan' => strtoupper(fp_str($p['pan'] ?? '', 12)),
        'email' => fp_str($p['email'] ?? '', 190),
        'phone' => fp_str($p['phone'] ?? '', 40),
        'address' => fp_str($p['address'] ?? '', 500),
        'state' => fp_str($p['state'] ?? '', 60),
        'payment_terms_days' => (string) max(0, min(365, (int) ($p['payment_terms_days'] ?? 30))),
        'credit_limit' => fp_dec($p['credit_limit'] ?? '', false, 'Credit limit', 0),
        'status' => ($p['status'] ?? 'active') === 'blocked' ? 'blocked' : 'active',
        'notes' => fp_str($p['notes'] ?? '', 500),
    ];
    fp_check_gstin($v['gstin']);
    fp_check_pan($v['pan']);
    if ($v['email'] !== '' && !filter_var($v['email'], FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('That email address is not valid.');
    }
    $null = static fn($x) => $x === '' ? null : $x;
    $cols = array_keys($v);
    if ($id) {
        $before = fp_customer($id);
        if (!$before) {
            throw new InvalidArgumentException('That customer no longer exists.');
        }
        fp_bump('fp_customers', $id, $p['version'] ?? 0);
        fp_exec('UPDATE fp_customers SET ' . implode(', ', array_map(static fn($c) => $c . ' = ?', $cols)) . ' WHERE id = ?', str_repeat('s', count($cols)) . 'i',
                array_merge(array_map($null, array_values($v)), [$id]));
        fp_audit('customer', $id, 'update', 'Customer ' . $before['code'] . ' updated', $before, fp_customer($id));
        return $id;
    }
    $code = fp_next_code('fp_customers', 'C');
    $id = fp_exec('INSERT INTO fp_customers (code, ' . implode(', ', $cols) . ', client_id, prepared_by_id, prepared_by) VALUES (?' . str_repeat(',?', count($cols)) . ',?,?,?)',
                  's' . str_repeat('s', count($cols)) . 'iis', array_merge([$code], array_map($null, array_values($v)), [!empty($p['client_id']) ? (int) $p['client_id'] : null, fp_me(), fp_actor()]));
    fp_audit('customer', $id, 'create', 'Customer ' . $code . ' ' . $v['name'] . ' created');
    return $id;
}

/** What a customer owes now (in base): invoices less credit notes and receipts. Negative is credit in their favour. */
function fp_customer_balance(int $id): array
{
    $ar = fp_acct('ar');
    $r = fp_one('SELECT COALESCE(SUM(l.debit_base - l.credit_base),0) b FROM fp_journal_lines l JOIN fp_journals j ON j.id = l.journal_id
                  WHERE j.status = "posted" AND l.account_id = ? AND l.party_type = "customer" AND l.party_id = ?', 'ii', [$ar, $id]);
    $unalloc = fp_val('SELECT COALESCE(SUM((r.amount + r.tds_amount - COALESCE((SELECT SUM(a.amount) FROM fp_ar_allocs a WHERE a.receipt_id = r.id),0)) * r.fx_rate),0)
                         FROM fp_receipts r WHERE r.customer_id = ? AND r.status = "posted"', 'i', [$id]);
    $open = fp_val('SELECT COALESCE(SUM((total - received_amount) * fx_rate),0) FROM fp_invoices WHERE customer_id = ? AND kind = "invoice" AND status IN ("issued","partially_paid")', 'i', [$id]);
    $overdue = fp_val('SELECT COALESCE(SUM((total - received_amount) * fx_rate),0) FROM fp_invoices WHERE customer_id = ? AND kind = "invoice" AND status IN ("issued","partially_paid") AND due_date < CURDATE()', 'i', [$id]);
    return ['balance' => fp_r2($r['b']), 'unallocated' => fp_r2($unalloc), 'open' => fp_r2($open), 'overdue' => fp_r2($overdue)];
}

/* ================================================================ invoices */

function fp_invoice(int $id): ?array
{
    return fp_one('SELECT * FROM fp_invoices WHERE id = ?', 'i', [$id]);
}

function fp_invoice_lines(int $id): array
{
    return fp_all('SELECT l.*, a.code AS account_code, a.name AS account_name FROM fp_invoice_lines l JOIN fp_accounts a ON a.id = l.account_id WHERE l.invoice_id = ? ORDER BY l.line_no', 'i', [$id]);
}

function fp_invoice_statuses(): array
{
    return ['draft' => ['Draft', ''], 'submitted' => ['Awaiting approval', 'amber'], 'issued' => ['Issued — unpaid', 'blue'], 'partially_paid' => ['Partly paid', 'amber'],
            'paid' => ['Paid', 'green'], 'cancelled' => ['Cancelled', ''], 'rejected' => ['Rejected', 'red']];
}

function fp_invoice_save(?int $id, array $p): int
{
    fp_need('ar.prepare');
    $kind = ($p['kind'] ?? 'invoice') === 'credit_note' ? 'credit_note' : 'invoice';
    $cust = fp_ref($p['customer_id'] ?? null, 'fp_customers', 'customer', true);
    $c = fp_customer($cust);
    if ($c['status'] === 'blocked' && $kind === 'invoice') {
        throw new InvalidArgumentException('This customer is blocked; no new invoices can be raised.');
    }
    $date = fp_date($p['invoice_date'] ?? date('Y-m-d'), true, 'Invoice date');
    $due = fp_date($p['due_date'] ?? '', false, 'Due date') ?? date('Y-m-d', strtotime($date . ' +' . (int) $c['payment_terms_days'] . ' day'));
    if ($due < $date) {
        throw new InvalidArgumentException('The due date is before the invoice date.');
    }
    $currency = fp_currency($p['currency'] ?? '');
    $rate = fp_fx($currency, $p['fx_rate'] ?? '', $date);
    $entity = fp_ref($p['entity_id'] ?? 1, 'fp_entities', 'entity', true, true);
    $ref = $kind === 'credit_note' ? fp_ref($p['ref_invoice_id'] ?? null, 'fp_invoices', 'original invoice', true) : null;
    if ($ref) {
        $o = fp_invoice($ref);
        if ((int) $o['customer_id'] !== $cust || $o['kind'] !== 'invoice' || !$o['journal_id']) {
            throw new InvalidArgumentException('A credit note corrects an issued invoice of the same customer.');
        }
    }
    [$lines, $sub, $tax] = fp_doc_lines($p['lines'] ?? [], 'output', ['income']);
    $total = fp_r2($sub + $tax);
    $vals = [$entity, $kind, $ref, $cust, $date, $due, $currency, $rate, fp_str($p['customer_po'] ?? '', 60) ?: null, fp_str($p['place_of_supply'] ?? '', 60) ?: ($c['state'] ?: null),
             fp_amt($sub), fp_amt($tax), fp_amt($total), fp_str($p['notes'] ?? '', 1000) ?: null];
    return fp_tx(static function () use ($id, $p, $vals, $lines, $kind, $date, $total) {
        if ($id) {
            $i = fp_invoice($id);
            if (!$i || $i['status'] !== 'draft') {
                throw new InvalidArgumentException('Only a draft invoice can be changed.');
            }
            fp_bump('fp_invoices', $id, $p['version'] ?? 0);
            fp_exec('UPDATE fp_invoices SET entity_id=?, kind=?, ref_invoice_id=?, customer_id=?, invoice_date=?, due_date=?, currency=?, fx_rate=?, customer_po=?, place_of_supply=?,
                     subtotal=?, tax_total=?, total=?, notes=? WHERE id=?', 'isiissssssssssi', array_merge($vals, [$id]));
            fp_exec('DELETE FROM fp_invoice_lines WHERE invoice_id = ?', 'i', [$id]);
            fp_audit('invoice', $id, 'update', 'Draft updated (' . fp_money($total) . ')', $i, null);
        } else {
            $id = fp_exec('INSERT INTO fp_invoices (entity_id, kind, ref_invoice_id, customer_id, invoice_date, due_date, currency, fx_rate, customer_po, place_of_supply, subtotal, tax_total, total, notes,
                           number, prepared_by_id, prepared_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', 'isiisssssssssssis',
                          array_merge($vals, [fp_next_no($kind === 'invoice' ? 'INV' : 'CN', $date), fp_me(), fp_actor()]));
            fp_audit('invoice', $id, 'create', ($kind === 'invoice' ? 'Invoice' : 'Credit note') . ' drafted (' . fp_money($total) . ')');
        }
        foreach ($lines as $n => $l) {
            fp_exec('INSERT INTO fp_invoice_lines (invoice_id, line_no, description, account_id, cost_centre_id, project_id, hsn, qty, rate, amount, tax_code_id, tax_amount) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                    'iisiiissssis', [$id, $n + 1, $l['description'], $l['account_id'], $l['cost_centre_id'], $l['project_id'], $l['hsn'] ?: null, (string) $l['qty'],
                                     fp_amt($l['rate']), fp_amt($l['amount']), $l['tax_code_id'], fp_amt($l['tax_amount'])]);
        }
        fp_attach_many('invoice', $id);
        return $id;
    });
}

/** Exposure against the credit limit if this invoice is issued: [over?, message]. */
function fp_credit_check(array $inv): array
{
    $c = fp_customer((int) $inv['customer_id']);
    if ($inv['kind'] !== 'invoice' || $c['credit_limit'] === null) {
        return [false, ''];
    }
    $bal = fp_customer_balance((int) $c['id']);
    $exposure = $bal['balance'] + (float) $inv['total'] * (float) $inv['fx_rate'];
    if ($exposure > (float) $c['credit_limit'] + 0.004) {
        return [true, 'Issuing this takes ' . $c['name'] . ' to ' . fp_money($exposure) . ' against a credit limit of ' . fp_money($c['credit_limit']) . '.'];
    }
    return [false, ''];
}

function fp_invoice_submit(int $id, $version, string $override = ''): string
{
    fp_need('ar.prepare');
    $i = fp_invoice($id);
    if (!$i || $i['status'] !== 'draft') {
        throw new InvalidArgumentException('Only a draft invoice can be submitted.');
    }
    [$over, $msg] = fp_credit_check($i);
    if ($over && fp_setting('ar_credit_control') === 'block' && !($override !== '' && fp_can('customer.manage') && fp_can('ap.override'))) {
        throw new InvalidArgumentException($msg . ' Credit control is set to stop this; a Finance Manager may accept it with a note.');
    }
    return fp_tx(static function () use ($id, $i, $version, $msg, $override) {
        fp_bump('fp_invoices', $id, $version);
        fp_exec('UPDATE fp_invoices SET status = "submitted", return_note = NULL WHERE id = ?', 'i', [$id]);
        fp_audit('invoice', $id, 'submit', 'Submitted for approval' . ($msg ? ' — ' . $msg : '') . ($override ? ' — accepted: ' . $override : ''));
        $cc = fp_one('SELECT cost_centre_id FROM fp_invoice_lines WHERE invoice_id = ? AND cost_centre_id IS NOT NULL LIMIT 1', 'i', [$id]);
        $state = fp_wf_start('invoice', $id, (float) $i['total'] * (float) $i['fx_rate'], $cc ? (int) $cc['cost_centre_id'] : null, (int) $i['entity_id'], (int) $i['prepared_by_id']);
        return $state === 'approved' ? 'Issued.' : 'Submitted for approval.' . ($msg ? ' Note: ' . $msg : '');
    });
}

function fp_wf_on_invoice(string $event, array $i, string $comment): void
{
    if ($event === 'approved') {
        fp_invoice_post((int) $i['id']);
        return;
    }
    fp_exec('UPDATE fp_invoices SET status = ?, return_note = ? WHERE id = ?', 'ssi', [$event === 'rejected' ? 'rejected' : 'draft', $comment, (int) $i['id']]);
}

/** Issues an approved invoice (or credit note) to the ledger: receivable against revenue and output tax. */
function fp_invoice_post(int $id): void
{
    fp_tx(static function () use ($id) {
        $i = fp_one('SELECT * FROM fp_invoices WHERE id = ? FOR UPDATE', 'i', [$id]);
        if ($i['journal_id']) {
            return;
        }
        $sign = $i['kind'] === 'credit_note' ? -1 : 1;
        $c = fp_customer((int) $i['customer_id']);
        $jl = [['account_id' => fp_acct('ar'), 'debit' => $sign * (float) $i['total'], 'credit' => 0, 'description' => 'Receivable from ' . $c['name'], 'party_type' => 'customer', 'party_id' => (int) $c['id']]];
        $taxBy = [];
        foreach (fp_invoice_lines($id) as $l) {
            $jl[] = ['account_id' => (int) $l['account_id'], 'debit' => 0, 'credit' => $sign * (float) $l['amount'], 'cost_centre_id' => $l['cost_centre_id'], 'project_id' => $l['project_id'],
                     'description' => $l['description'], 'tax_code_id' => $l['tax_code_id'], 'party_type' => 'customer', 'party_id' => (int) $c['id']];
            [, $parts] = fp_tax_split($l['tax_code_id'] ? (int) $l['tax_code_id'] : null, (float) $l['amount'], 'output');
            foreach ($parts as [$acc, $amt]) {
                $taxBy[$acc] = ($taxBy[$acc] ?? 0) + $amt;
            }
        }
        foreach ($taxBy as $acc => $amt) {
            $jl[] = ['account_id' => $acc, 'debit' => 0, 'credit' => $sign * $amt, 'description' => 'Output tax'];
        }
        $jid = fp_journal_system(['entity_id' => (int) $i['entity_id'], 'jdate' => $i['invoice_date'], 'type' => 'system', 'source' => 'invoice', 'source_id' => $id,
                                  'reference' => $i['number'], 'description' => ($i['kind'] === 'invoice' ? 'Invoice ' : 'Credit note ') . $i['number'] . ' — ' . $c['name'],
                                  'currency' => $i['currency'], 'fx_rate' => $i['fx_rate']], $jl);
        fp_exec('UPDATE fp_invoices SET journal_id = ?, status = "issued" WHERE id = ?', 'ii', [$jid, $id]);
        fp_audit('invoice', $id, 'issue', ($i['kind'] === 'invoice' ? 'Invoice' : 'Credit note') . ' issued and posted');
    });
}

function fp_invoice_cancel(int $id, string $reason, $version): void
{
    fp_need('ar.prepare');
    $i = fp_invoice($id);
    if (!$i || !in_array($i['status'], ['draft', 'submitted', 'rejected'], true)) {
        throw new InvalidArgumentException('An issued invoice is not cancelled — raise a credit note against it.');
    }
    fp_bump('fp_invoices', $id, $version);
    fp_exec('UPDATE fp_invoices SET status = "cancelled" WHERE id = ?', 'i', [$id]);
    fp_wf_cancel('invoice', $id, 'Cancelled');
    fp_audit('invoice', $id, 'cancel', 'Cancelled: ' . fp_str($reason, 500, true, 'The reason'));
}

function fp_invoice_recalc(int $id): void
{
    $got = fp_val('SELECT COALESCE(SUM(amount),0) FROM fp_ar_allocs WHERE invoice_id = ?', 'i', [$id]);
    fp_exec('UPDATE fp_invoices SET received_amount = ? WHERE id = ?', 'si', [fp_amt($got), $id]);
    $i = fp_invoice($id);
    if ($i['kind'] === 'invoice' && in_array($i['status'], ['issued', 'partially_paid', 'paid'], true)) {
        $s = $got + 0.001 >= (float) $i['total'] ? 'paid' : ($got > 0 ? 'partially_paid' : 'issued');
        fp_exec('UPDATE fp_invoices SET status = ? WHERE id = ?', 'si', [$s, $id]);
    }
}

function fp_invoice_dispute(int $id, bool $on, string $note): void
{
    fp_need('ar.prepare');
    $i = fp_invoice($id);
    if (!$i || !in_array($i['status'], ['issued', 'partially_paid'], true)) {
        throw new InvalidArgumentException('Only an open, issued invoice can be disputed.');
    }
    $note = fp_str($note, 500, true, $on ? 'What the customer disputes' : 'How the dispute was settled');
    fp_exec('UPDATE fp_invoices SET disputed = ?, dispute_note = ? WHERE id = ?', 'isi', [$on ? 1 : 0, $note, $id]);
    fp_exec('INSERT INTO fp_followups (customer_id, invoice_id, kind, note, actor) VALUES (?,?,?,?,?)', 'iisss',
            [(int) $i['customer_id'], $id, $on ? 'dispute' : 'resolved', $note, fp_actor()]);
    fp_audit('invoice', $id, $on ? 'dispute' : 'dispute_resolved', ($on ? 'Disputed: ' : 'Dispute settled: ') . $note);
}

/* ================================================================ receipts */

function fp_receipt(int $id): ?array
{
    return fp_one('SELECT * FROM fp_receipts WHERE id = ?', 'i', [$id]);
}

function fp_receipt_unallocated(array $r): float
{
    $a = fp_val('SELECT COALESCE(SUM(amount),0) FROM fp_ar_allocs WHERE receipt_id = ?', 'i', [(int) $r['id']]);
    return fp_r2((float) $r['amount'] + (float) $r['tds_amount'] - $a);
}

/**
 * Records money received. Posted at once — a receipt is a fact, not a
 * request — to the bank (and TDS the customer withheld to TDS receivable),
 * against the customer's receivable. $p['alloc']: invoice id => amount.
 */
function fp_receipt_create(array $p): int
{
    fp_need('ar.receipt');
    $cust = fp_ref($p['customer_id'] ?? null, 'fp_customers', 'customer', true);
    $date = fp_date($p['receipt_date'] ?? date('Y-m-d'), true, 'Received on');
    if ($date > date('Y-m-d')) {
        throw new InvalidArgumentException('Money cannot be received in the future.');
    }
    $bank = fp_ref($p['bank_account_id'] ?? null, 'fp_bank_accounts', 'bank account', true, true);
    $currency = fp_bank_account($bank)['currency'];
    $rate = fp_fx($currency, $p['fx_rate'] ?? '', $date);
    $amount = (float) fp_dec($p['amount'] ?? '', true, 'Amount received', 0.01);
    $tds = (float) (fp_dec($p['tds_amount'] ?? '', false, 'TDS deducted by the customer', 0) ?? 0);
    $method = in_array($p['method'] ?? '', ['neft', 'rtgs', 'imps', 'upi', 'cheque', 'cash', 'card', 'other'], true) ? $p['method'] : 'neft';
    $ref = fp_str($p['reference'] ?? '', 120);
    if ($ref !== '' && ($dup = fp_one('SELECT number FROM fp_receipts WHERE customer_id = ? AND reference = ? AND status = "posted"', 'is', [$cust, $ref]))) {
        throw new InvalidArgumentException('A receipt with reference ' . $ref . ' from this customer is already recorded (' . $dup['number'] . ').');
    }
    $allocs = fp_alloc_clean((array) ($p['alloc'] ?? []), $cust, $currency);
    if (array_sum(array_column($allocs, 1)) > $amount + $tds + 0.004) {
        throw new InvalidArgumentException('More is allocated than was received.');
    }
    return fp_tx(static function () use ($cust, $date, $bank, $currency, $rate, $amount, $tds, $method, $ref, $p, $allocs) {
        $number = fp_next_no('RCPT', $date);
        $id = fp_exec('INSERT INTO fp_receipts (entity_id, number, customer_id, receipt_date, bank_account_id, currency, fx_rate, amount, tds_amount, method, reference, notes, prepared_by_id, prepared_by)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)', 'isisisssssssis',
                      [(int) fp_bank_account($bank)['entity_id'], $number, $cust, $date, $bank, $currency, $rate, fp_amt($amount), fp_amt($tds), $method, $ref ?: null,
                       fp_str($p['notes'] ?? '', 500) ?: null, fp_me(), fp_actor()]);
        fp_receipt_post($id);
        foreach ($allocs as [$inv, $a]) {
            fp_exec('INSERT INTO fp_ar_allocs (invoice_id, receipt_id, amount, created_by) VALUES (?,?,?,?)', 'iiss', [$inv, $id, fp_amt($a), fp_actor()]);
            fp_invoice_recalc($inv);
        }
        fp_attach_many('receipt', $id);
        fp_audit('receipt', $id, 'create', 'Receipt ' . $number . ' recorded: ' . fp_money($amount) . ($tds > 0 ? ' + TDS ' . fp_money($tds) : ''));
        return $id;
    });
}

/** Allocation lines: invoice id => amount, each no more than that invoice still has open. */
function fp_alloc_clean(array $raw, int $cust, string $currency): array
{
    $out = [];
    foreach ($raw as $inv => $a) {
        $a = fp_dec($a, false, 'Amount', 0);
        if ($a === null || (float) $a <= 0) {
            continue;
        }
        $i = fp_invoice((int) $inv);
        if (!$i || (int) $i['customer_id'] !== $cust || $i['kind'] !== 'invoice' || !in_array($i['status'], ['issued', 'partially_paid'], true)) {
            throw new InvalidArgumentException('Allocate only to this customer\'s open invoices.');
        }
        if ($i['currency'] !== $currency) {
            throw new InvalidArgumentException($i['number'] . ' is in ' . $i['currency'] . '; allocate money received in the same currency.');
        }
        $open = fp_r2((float) $i['total'] - (float) $i['received_amount']);
        if ((float) $a > $open + 0.004) {
            throw new InvalidArgumentException($i['number'] . ' has only ' . fp_money($open) . ' open.');
        }
        $out[] = [(int) $inv, (float) $a];
    }
    return $out;
}

function fp_receipt_post(int $id): void
{
    $r = fp_receipt($id);
    $b = fp_bank_account((int) $r['bank_account_id']);
    $c = fp_customer((int) $r['customer_id']);
    $lines = [['account_id' => (int) $b['account_id'], 'debit' => (float) $r['amount'], 'credit' => 0, 'description' => 'Received from ' . $c['name']]];
    if ((float) $r['tds_amount'] > 0) {
        $lines[] = ['account_id' => fp_acct('tds_recv'), 'debit' => (float) $r['tds_amount'], 'credit' => 0, 'description' => 'TDS deducted by ' . $c['name'], 'party_type' => 'customer', 'party_id' => (int) $c['id']];
    }
    $lines[] = ['account_id' => fp_acct('ar'), 'debit' => 0, 'credit' => (float) $r['amount'] + (float) $r['tds_amount'], 'description' => 'Receipt ' . $r['number'], 'party_type' => 'customer', 'party_id' => (int) $c['id']];
    $jid = fp_journal_system(['entity_id' => (int) $r['entity_id'], 'jdate' => $r['receipt_date'], 'type' => 'system', 'source' => 'receipt', 'source_id' => $id,
                              'reference' => $r['reference'] ?: $r['number'], 'description' => 'Receipt ' . $r['number'] . ' — ' . $c['name'], 'currency' => $r['currency'], 'fx_rate' => $r['fx_rate']], $lines);
    fp_exec('UPDATE fp_receipts SET journal_id = ? WHERE id = ?', 'ii', [$jid, $id]);
}

/** Allocates what is left of a receipt to an invoice. */
function fp_receipt_allocate(int $receiptId, array $alloc): string
{
    fp_need('ar.receipt');
    return fp_tx(static function () use ($receiptId, $alloc) {
        $r = fp_one('SELECT * FROM fp_receipts WHERE id = ? FOR UPDATE', 'i', [$receiptId]);
        if (!$r || $r['status'] !== 'posted') {
            throw new InvalidArgumentException('Allocate from a posted receipt.');
        }
        $lines = fp_alloc_clean($alloc, (int) $r['customer_id'], (string) $r['currency']);
        $sum = array_sum(array_column($lines, 1));
        if ($sum > fp_receipt_unallocated($r) + 0.004) {
            throw new InvalidArgumentException('Only ' . fp_money(fp_receipt_unallocated($r)) . ' of this receipt is unallocated.');
        }
        foreach ($lines as [$inv, $a]) {
            fp_exec('INSERT INTO fp_ar_allocs (invoice_id, receipt_id, amount, created_by) VALUES (?,?,?,?)', 'iiss', [$inv, $receiptId, fp_amt($a), fp_actor()]);
            fp_invoice_recalc($inv);
        }
        fp_audit('receipt', $receiptId, 'allocate', fp_money($sum) . ' allocated');
        return fp_money($sum) . ' allocated.';
    });
}

/** Applies an issued credit note to an open invoice of the same customer. */
function fp_ar_note_apply(int $noteId, int $invoiceId, $amount): string
{
    fp_need('ar.prepare');
    return fp_tx(static function () use ($noteId, $invoiceId, $amount) {
        $n = fp_one('SELECT * FROM fp_invoices WHERE id = ? FOR UPDATE', 'i', [$noteId]);
        if (!$n || $n['kind'] !== 'credit_note' || !$n['journal_id']) {
            throw new InvalidArgumentException('Apply an issued credit note.');
        }
        $lines = fp_alloc_clean([$invoiceId => $amount], (int) $n['customer_id'], (string) $n['currency']);
        $left = fp_r2((float) $n['total'] - (float) $n['received_amount']);
        if ($lines[0][1] > $left + 0.004) {
            throw new InvalidArgumentException('Only ' . fp_money($left) . ' of the credit note is left to apply.');
        }
        fp_exec('INSERT INTO fp_ar_allocs (invoice_id, note_id, amount, created_by) VALUES (?,?,?,?)', 'iiss', [$invoiceId, $noteId, fp_amt($lines[0][1]), fp_actor()]);
        fp_exec('UPDATE fp_invoices SET received_amount = received_amount + ?, status = IF(received_amount + 0.001 >= total, "paid", "partially_paid") WHERE id = ?', 'si', [fp_amt($lines[0][1]), $noteId]);
        fp_invoice_recalc($invoiceId);
        fp_audit('invoice', $invoiceId, 'apply_note', 'Credit note ' . $n['number'] . ' applied: ' . fp_money($lines[0][1]));
        return 'Credit note applied.';
    });
}

/** Cancels a receipt recorded in error: the posting is reversed and its allocations undone. */
function fp_receipt_cancel(int $id, string $reason): string
{
    fp_need('ar.receipt');
    $reason = fp_str($reason, 500, true, 'The reason');
    return fp_tx(static function () use ($id, $reason) {
        $r = fp_one('SELECT * FROM fp_receipts WHERE id = ? FOR UPDATE', 'i', [$id]);
        if (!$r || $r['status'] !== 'posted') {
            throw new InvalidArgumentException('This receipt is not posted.');
        }
        if (fp_one('SELECT 1 FROM fp_bank_lines s JOIN fp_journal_lines l ON l.id = s.matched_line_id WHERE l.journal_id = ? AND s.status = "matched"', 'i', [(int) $r['journal_id']])) {
            throw new InvalidArgumentException('This receipt is matched to the bank statement. Unmatch it first — if the money really arrived, it should not be cancelled.');
        }
        $lines = array_map(static fn($l) => ['account_id' => $l['account_id'], 'debit' => $l['credit'], 'credit' => $l['debit'], 'description' => 'Cancelled: ' . $l['description'],
                                             'party_type' => $l['party_type'], 'party_id' => $l['party_id']], fp_journal_lines((int) $r['journal_id']));
        $rev = fp_journal_system(['entity_id' => (int) $r['entity_id'], 'jdate' => max((string) $r['receipt_date'], date('Y-m-d')), 'type' => 'reversal', 'source' => 'receipt', 'source_id' => $id,
                                  'reference' => $r['number'], 'description' => 'Receipt ' . $r['number'] . ' cancelled: ' . $reason, 'currency' => $r['currency'], 'fx_rate' => $r['fx_rate']], $lines);
        fp_exec('UPDATE fp_journals SET reversed_by = ? WHERE id = ?', 'ii', [$rev, (int) $r['journal_id']]);
        fp_exec('UPDATE fp_journals SET reversal_of = ? WHERE id = ?', 'ii', [(int) $r['journal_id'], $rev]);
        $invs = fp_all('SELECT invoice_id FROM fp_ar_allocs WHERE receipt_id = ?', 'i', [$id]);
        fp_exec('DELETE FROM fp_ar_allocs WHERE receipt_id = ?', 'i', [$id]);
        foreach ($invs as $x) {
            fp_invoice_recalc((int) $x['invoice_id']);
        }
        fp_exec('UPDATE fp_receipts SET status = "cancelled", notes = CONCAT(COALESCE(notes, ""), ?) WHERE id = ?', 'si', [' Cancelled: ' . $reason, $id]);
        fp_audit('receipt', $id, 'cancel', 'Receipt cancelled: ' . $reason);
        return 'Receipt cancelled and its posting reversed.';
    });
}

/* ======================================================= collections */

/** Open customer invoices with what is still due, in base. */
function fp_ar_open(?int $customer = null): array
{
    $rows = fp_all('SELECT i.*, c.name AS customer, c.email AS customer_email FROM fp_invoices i JOIN fp_customers c ON c.id = i.customer_id
                     WHERE i.kind = "invoice" AND i.status IN ("issued","partially_paid")' . ($customer ? ' AND i.customer_id = ' . (int) $customer : '') . ' ORDER BY i.due_date, i.id');
    foreach ($rows as &$r) {
        $r['open'] = fp_r2((float) $r['total'] - (float) $r['received_amount']);
        $r['open_base'] = fp_r2($r['open'] * (float) $r['fx_rate']);
    }
    unset($r);
    return $rows;
}

function fp_ar_aging(string $asOf, ?int $entity = null): array
{
    $rows = [];
    $sql = 'SELECT i.*, c.name AS customer FROM fp_invoices i JOIN fp_customers c ON c.id = i.customer_id
             WHERE i.kind = "invoice" AND i.journal_id IS NOT NULL AND i.status NOT IN ("cancelled","rejected") AND i.invoice_date <= ?';
    $t = 's';
    $p = [$asOf];
    if ($entity) {
        $sql .= ' AND i.entity_id = ?';
        $t .= 'i';
        $p[] = $entity;
    }
    foreach (fp_all($sql, $t, $p) as $i) {
        $got = fp_val('SELECT COALESCE(SUM(a.amount),0) FROM fp_ar_allocs a LEFT JOIN fp_receipts r ON r.id = a.receipt_id
                        WHERE a.invoice_id = ? AND (a.receipt_id IS NULL OR r.receipt_date <= ?) AND DATE(a.created_at) <= ?', 'iss', [(int) $i['id'], $asOf, $asOf]);
        $open = fp_r2(((float) $i['total'] - $got) * (float) $i['fx_rate']);
        if ($open <= 0.004) {
            continue;
        }
        $k = (int) $i['customer_id'];
        $rows[$k] ??= ['id' => $k, 'name' => $i['customer'], 'current' => 0, 'd30' => 0, 'd60' => 0, 'd90' => 0, 'd90p' => 0, 'total' => 0, 'docs' => 0];
        $rows[$k][fp_bucket_of($i['due_date'], $asOf)] += $open;
        $rows[$k]['total'] += $open;
        $rows[$k]['docs']++;
    }
    usort($rows, static fn($a, $b) => $b['total'] <=> $a['total']);
    return $rows;
}

/** Days sales outstanding: receivables now against credit sales of the last $days days. */
function fp_dso(int $days = 90, ?int $entity = null): ?float
{
    $ar = fp_balances(['to' => date('Y-m-d'), 'entity' => $entity], false)[fp_acct('ar')] ?? [0, 0];
    $sales = fp_val('SELECT COALESCE(SUM(CASE WHEN kind = "invoice" THEN total ELSE -total END * fx_rate),0) FROM fp_invoices
                      WHERE journal_id IS NOT NULL AND invoice_date > DATE_SUB(CURDATE(), INTERVAL ? DAY)' . ($entity ? ' AND entity_id = ' . (int) $entity : ''), 'i', [$days]);
    if ($sales <= 0) {
        return null;
    }
    return round(max(0, $ar[0] - $ar[1]) / $sales * $days, 1);
}

/** The customer's statement: every posted movement on their account, with the running balance. */
function fp_customer_statement(int $id, ?string $from = null, ?string $to = null): array
{
    $ar = fp_acct('ar');
    $open = 0.0;
    if ($from) {
        $open = fp_val('SELECT COALESCE(SUM(l.debit_base - l.credit_base),0) FROM fp_journal_lines l JOIN fp_journals j ON j.id = l.journal_id
                         WHERE j.status = "posted" AND l.account_id = ? AND l.party_type = "customer" AND l.party_id = ? AND j.jdate < ?', 'iis', [$ar, $id, $from]);
    }
    $rows = fp_all('SELECT j.jdate, j.number, j.description, j.source, j.source_id, j.reference, l.debit_base, l.credit_base FROM fp_journal_lines l JOIN fp_journals j ON j.id = l.journal_id
                     WHERE j.status = "posted" AND l.account_id = ? AND l.party_type = "customer" AND l.party_id = ?' . ($from ? ' AND j.jdate >= ?' : '') . ($to ? ' AND j.jdate <= ?' : '') . '
                     ORDER BY j.jdate, j.id', 'ii' . ($from ? 's' : '') . ($to ? 's' : ''), array_values(array_filter([$ar, $id, $from, $to], static fn($v) => $v !== null)));
    $run = $open;
    foreach ($rows as &$r) {
        $run += (float) $r['debit_base'] - (float) $r['credit_base'];
        $r['balance'] = fp_r2($run);
    }
    unset($r);
    return ['opening' => fp_r2($open), 'rows' => $rows, 'closing' => fp_r2($run)];
}

function fp_followup_add(int $customerId, ?int $invoiceId, string $note, ?string $next): void
{
    fp_need('ar.receipt');
    fp_exec('INSERT INTO fp_followups (customer_id, invoice_id, kind, note, next_date, actor) VALUES (?,?,"note",?,?,?)', 'iisss',
            [$customerId, $invoiceId, fp_str($note, 1000, true, 'The note'), fp_date($next, false, 'Next follow-up'), fp_actor()]);
}

/** Emails the customer a reminder of what is overdue on one invoice. */
function fp_ar_remind(int $invoiceId): string
{
    $i = fp_invoice($invoiceId);
    if (!$i || !in_array($i['status'], ['issued', 'partially_paid'], true)) {
        throw new InvalidArgumentException('Only an open invoice can be followed up.');
    }
    $c = fp_customer((int) $i['customer_id']);
    if (!$c['email'] || !filter_var($c['email'], FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('This customer has no email address on file.');
    }
    require_once __DIR__ . '/../notify.php';
    $open = fp_r2((float) $i['total'] - (float) $i['received_amount']);
    $late = $i['due_date'] && $i['due_date'] < date('Y-m-d');
    $body = 'Dear ' . $c['name'] . ",\n\n" . 'This is a reminder that invoice ' . $i['number'] . ' dated ' . fp_fmt_date($i['invoice_date']) . ' has '
          . fp_money($open) . ' outstanding' . ($i['due_date'] ? ($late ? ', which was due on ' : ', due on ') . fp_fmt_date($i['due_date']) : '') . ".\n\n"
          . "If you have already paid, please send us the payment reference so we can match it. If anything on the invoice is in question, reply to this email and we will look into it.\n\nThank you.\n";
    $actor = fp_org() > 0 ? fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [fp_org()]) : null;
    [$ok, $why] = fabo_send_mail((string) $c['email'], 'Payment reminder: invoice ' . $i['number'], $body, $actor);
    if (!$ok) {
        throw new InvalidArgumentException('The reminder could not be sent: ' . $why);
    }
    fp_exec('UPDATE fp_invoices SET last_reminder_at = NOW(), reminders = reminders + 1 WHERE id = ?', 'i', [$invoiceId]);
    fp_exec('INSERT INTO fp_followups (customer_id, invoice_id, kind, note, actor) VALUES (?,?,"reminder",?,?)', 'iiss',
            [(int) $c['id'], $invoiceId, 'Reminder emailed to ' . $c['email'] . ' (' . fp_money($open) . ' outstanding)', fp_actor()]);
    fp_audit('invoice', $invoiceId, 'remind', 'Payment reminder emailed');
    return 'Reminder sent to ' . $c['email'] . '.';
}

/** Automatic reminders at the overdue days set in Settings (e.g. 3, 15, 30). Disputed invoices are left alone. */
function fp_ar_auto_remind(): int
{
    $days = array_values(array_filter(array_map('intval', explode(',', fp_setting('ar_reminder_days')))));
    sort($days);
    if (!$days) {
        return 0;
    }
    $n = 0;
    foreach (fp_ar_open() as $i) {
        if ($i['disputed'] || !$i['due_date'] || $i['due_date'] >= date('Y-m-d') || !$i['customer_email']) {
            continue;
        }
        $late = (int) ((time() - strtotime($i['due_date'])) / 86400);
        $due = count(array_filter($days, static fn($d) => $late >= $d));
        if ($due > (int) $i['reminders']) {
            try {
                fp_ar_remind((int) $i['id']);
                $n++;
            } catch (Throwable $e) {
                error_log('FABO finance reminder ' . $i['number'] . ': ' . $e->getMessage());
            }
        }
    }
    return $n;
}

/* ===================================================== bringing in the old */

/**
 * Brings the portal's own invoices to its clients into receivables (our
 * organisation only): each sent or paid invoice becomes an issued invoice
 * posted to revenue, and a paid one gets its receipt into $bankId. Each is
 * brought in once; running it again brings only what is new, or a payment
 * marked since. The originals are not changed.
 */
function fp_import_portal_invoices(int $bankId, int $revenueAccount): array
{
    fp_need('ar.prepare');
    if (fp_org() !== 0) {
        throw new InvalidArgumentException('Only our own organisation bills through the portal\'s invoices.');
    }
    $bank = fp_bank_account($bankId);
    $rev = fp_account($revenueAccount);
    if (!$bank || !$rev || $rev['type'] !== 'income' || (int) $rev['is_group'] === 1) {
        throw new InvalidArgumentException('Choose the bank account receipts went to and a revenue account.');
    }
    $made = 0;
    $paid = 0;
    $skipped = [];
    foreach (fabo_all('SELECT * FROM invoices WHERE state IN ("sent","paid") ORDER BY issue_date, id') as $inv) {
        try {
            fp_tx(static function () use ($inv, $bankId, $revenueAccount, &$made, &$paid) {
                $have = fp_one('SELECT * FROM fp_invoices WHERE legacy_invoice_id = ?', 'i', [(int) $inv['id']]);
                if (!$have) {
                    $cust = fp_one('SELECT id FROM fp_customers WHERE client_id = ?', 'i', [(int) $inv['client_id']]);
                    $custId = $cust ? (int) $cust['id'] : fp_exec('INSERT INTO fp_customers (code, name, email, address, client_id, prepared_by_id, prepared_by) VALUES (?,?,?,?,?,?,?)', 'ssssiis',
                        [fp_next_code('fp_customers', 'C'), mb_substr($inv['client_org'] ?: $inv['client_name'], 0, 190), $inv['client_email'] ?: null,
                         $inv['client_address'] ? mb_substr((string) $inv['client_address'], 0, 500) : null, (int) $inv['client_id'], fp_me(), fp_actor()]);
                    $code = null;
                    if ((float) $inv['tax_pct'] > 0) {
                        $code = fp_one('SELECT id FROM fp_tax_codes WHERE kind = "gst" AND ABS(rate - ?) < 0.001 AND active = 1 LIMIT 1', 'd', [(float) $inv['tax_pct']]);
                    }
                    $id = fp_exec('INSERT INTO fp_invoices (number, customer_id, invoice_date, due_date, subtotal, tax_total, total, status, notes, legacy_invoice_id, prepared_by_id, prepared_by)
                                   VALUES (?,?,?,?,?,?,?,"issued",?,?,?,?)', 'sisssssssis',
                                  [mb_substr('P-' . $inv['number'], 0, 30), $custId, $inv['issue_date'], $inv['due_date'], $inv['subtotal'], $inv['tax_amount'], $inv['total'],
                                   'Brought in from portal invoice ' . $inv['number'], (int) $inv['id'], fp_me(), fp_actor()]);
                    $items = fabo_all('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY sort_order, id', 'i', [(int) $inv['id']]) ?: [['description' => 'As invoiced', 'quantity' => 1, 'rate' => $inv['subtotal'], 'amount' => $inv['subtotal']]];
                    foreach ($items as $n => $it) {
                        $taxAmt = $code ? fp_tax_split((int) $code['id'], (float) $it['amount'], 'output')[0] : 0;
                        fp_exec('INSERT INTO fp_invoice_lines (invoice_id, line_no, description, account_id, qty, rate, amount, tax_code_id, tax_amount) VALUES (?,?,?,?,?,?,?,?,?)', 'iisisssis',
                                [$id, $n + 1, mb_substr((string) $it['description'], 0, 255), $revenueAccount, (string) $it['quantity'], $it['rate'], $it['amount'], $code ? (int) $code['id'] : null, fp_amt($taxAmt)]);
                    }
                    // The tax as invoiced is the tax owed, whatever the line rounding says.
                    $c = fp_customer($custId);
                    $jl = [['account_id' => fp_acct('ar'), 'debit' => (float) $inv['total'], 'credit' => 0, 'party_type' => 'customer', 'party_id' => $custId, 'description' => 'Receivable from ' . $c['name']],
                           ['account_id' => $revenueAccount, 'debit' => 0, 'credit' => (float) $inv['subtotal'], 'party_type' => 'customer', 'party_id' => $custId, 'description' => 'Portal invoice ' . $inv['number']]];
                    if ((float) $inv['tax_amount'] > 0) {
                        $half = fp_r2((float) $inv['tax_amount'] / 2);
                        $jl[] = ['account_id' => fp_acct('gst_out_cgst'), 'debit' => 0, 'credit' => $half, 'description' => 'Output CGST'];
                        $jl[] = ['account_id' => fp_acct('gst_out_sgst'), 'debit' => 0, 'credit' => fp_r2((float) $inv['tax_amount'] - $half), 'description' => 'Output SGST'];
                    }
                    $jid = fp_journal_system(['jdate' => $inv['issue_date'], 'type' => 'system', 'source' => 'invoice', 'source_id' => $id, 'reference' => $inv['number'],
                                              'description' => 'Portal invoice ' . $inv['number'] . ' — ' . $c['name']], $jl);
                    fp_exec('UPDATE fp_invoices SET journal_id = ? WHERE id = ?', 'ii', [$jid, $id]);
                    fp_wf_event('invoice', $id, 1, null, null, 'import', 'Issued in the portal\'s billing before the Finance Portal; brought in as issued');
                    $have = fp_invoice($id);
                    $made++;
                }
                if ($inv['state'] === 'paid' && !fp_one('SELECT id FROM fp_receipts WHERE legacy_invoice_id = ?', 'i', [(int) $inv['id']])) {
                    $date = $inv['paid_at'] ? substr((string) $inv['paid_at'], 0, 10) : $inv['issue_date'];
                    $rid = fp_exec('INSERT INTO fp_receipts (number, customer_id, receipt_date, bank_account_id, amount, reference, notes, legacy_invoice_id, prepared_by_id, prepared_by)
                                    VALUES (?,?,?,?,?,?,?,?,?,?)', 'sisisssiis',
                                   [fp_next_no('RCPT', $date), (int) $have['customer_id'], $date, $bankId, fp_amt($have['total']), $inv['paid_note'] ? mb_substr((string) $inv['paid_note'], 0, 120) : null,
                                    'Marked paid in the portal\'s billing', (int) $inv['id'], fp_me(), fp_actor()]);
                    fp_receipt_post($rid);
                    fp_exec('INSERT INTO fp_ar_allocs (invoice_id, receipt_id, amount, created_by) VALUES (?,?,?,?)', 'iiss', [(int) $have['id'], $rid, fp_amt($have['total']), fp_actor()]);
                    fp_invoice_recalc((int) $have['id']);
                    $paid++;
                }
            });
        } catch (InvalidArgumentException $e) {
            $skipped[] = $inv['number'] . ': ' . $e->getMessage();
        }
    }
    fp_audit('invoice', null, 'import', 'Portal invoices brought in: ' . $made . ' invoices, ' . $paid . ' payments' . ($skipped ? ', ' . count($skipped) . ' skipped' : ''));
    return [$made, $paid, $skipped];
}

/**
 * Brings the organisation's spend log (the simple expense book kept before
 * the Finance Portal) into the ledger, one journal per entry: the category's
 * expense (net of claimable GST, which goes to input credit) against the
 * bank if paid, or accrued expenses if not. Once each.
 */
function fp_import_spend_log(int $bankId, int $inputTaxAccount): array
{
    fp_need('gl.prepare');
    $bank = fp_bank_account($bankId);
    if (!$bank) {
        throw new InvalidArgumentException('Choose the bank account the spend was paid from.');
    }
    $tax = fp_account($inputTaxAccount);
    if (!$tax || $tax['subtype'] !== 'tax_input') {
        throw new InvalidArgumentException('Choose the input tax account the GST credit goes to.');
    }
    $map = ['Salaries' => 'salary_exp', 'Contractors' => null, 'Ad spend' => 'marketing', 'Software' => 'software', 'Production' => 'cogs', 'Travel' => 'travel',
            'Office' => 'office', 'Professional fees' => 'professional', 'Bank charges' => 'bank_charges', 'Other' => 'misc'];
    $made = 0;
    $skipped = [];
    foreach (fabo_all('SELECT * FROM expenses WHERE client_id = ? ORDER BY spent_on, id', 'i', [fp_org()]) as $e) {
        if (fp_one('SELECT id FROM fp_imports WHERE source = "spend_log" AND source_id = ?', 'i', [(int) $e['id']])) {
            continue;
        }
        try {
            fp_tx(static function () use ($e, $map, $bank, $inputTaxAccount, &$made) {
                $key = $map[$e['category']] ?? 'misc';
                $acc = $key ? fp_acct($key) : (int) (fp_one('SELECT id FROM fp_accounts WHERE code = "6610"')['id'] ?? fp_acct('misc'));
                $credit = (int) $e['gst_credit'] === 1 ? (float) $e['tax_amount'] : 0.0;
                $lines = [['account_id' => $acc, 'debit' => fp_r2((float) $e['amount'] - $credit), 'credit' => 0, 'description' => trim(($e['vendor'] ? $e['vendor'] . ': ' : '') . (string) $e['description'])]];
                if ($credit > 0) {
                    $lines[] = ['account_id' => $inputTaxAccount, 'debit' => $credit, 'credit' => 0, 'description' => 'GST input credit'];
                }
                $lines[] = ['account_id' => (int) $e['paid'] === 1 ? (int) $bank['account_id'] : fp_acct('accrued'), 'debit' => 0, 'credit' => (float) $e['amount'],
                            'description' => (int) $e['paid'] === 1 ? 'Paid' : 'Not yet paid'];
                $jid = fp_journal_system(['jdate' => $e['spent_on'], 'type' => 'system', 'source' => 'spend_log', 'source_id' => (int) $e['id'], 'reference' => 'Spend log #' . $e['id'],
                                          'description' => $e['category'] . ($e['vendor'] ? ' — ' . $e['vendor'] : '')], $lines);
                fp_exec('INSERT INTO fp_imports (source, source_id, target, target_id) VALUES ("spend_log", ?, "journal", ?)', 'ii', [(int) $e['id'], $jid]);
                $made++;
            });
        } catch (InvalidArgumentException $ex) {
            $skipped[] = fp_fmt_date($e['spent_on']) . ' ' . $e['category'] . ': ' . $ex->getMessage();
        }
    }
    fp_audit('journal', null, 'import', 'Spend log brought in: ' . $made . ' entries' . ($skipped ? ', ' . count($skipped) . ' skipped' : ''));
    return [$made, $skipped];
}

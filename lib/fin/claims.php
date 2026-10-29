<?php
/**
 * Finance Portal — employee expenses, travel advances, and HR's figures.
 *
 * CLAIMS. An employee lists what they spent (each line its own date,
 * category and receipt; mileage and per diem worked out from the rates in
 * Settings), and submits. The claim is checked against policy as it is
 * saved — limits per category, receipts above a threshold, how far back a
 * claim may go, the same expense claimed twice — and every finding is shown
 * to the employee and the approvers in plain words. Their manager approves
 * (from HR, else the cost centre's owner), then Finance reviews; either can
 * approve, reject or send it back with a reason the employee sees on the
 * claim. An approved claim is posted (expense against reimbursements
 * payable, less any advance being settled) and paid through Treasury.
 * Lines paid on the company card are recorded but not reimbursed.
 *
 * ADVANCES. Asked for, approved, paid through Treasury, and settled against
 * the claim for the trip.
 *
 * HR. A claim already approved in HR (lib/hr) can be brought in at the
 * Finance review step; when Finance pays it, HR's claim is marked paid too.
 * A finalised payroll run can be posted to the ledger as one journal.
 */

function fp_claim(int $id): ?array
{
    return fp_one('SELECT * FROM fp_claims WHERE id = ?', 'i', [$id]);
}

function fp_claim_lines(int $id): array
{
    return fp_all('SELECT l.*, c.name AS category, c.kind AS category_kind, c.account_id FROM fp_claim_lines l JOIN fp_expense_categories c ON c.id = l.category_id
                    WHERE l.claim_id = ? ORDER BY l.expense_date, l.id', 'i', [$id]);
}

function fp_claim_statuses(): array
{
    return ['draft' => ['Draft', ''], 'submitted' => ['Waiting for approval', 'amber'], 'returned' => ['Returned to you for correction', 'red'],
            'approved' => ['Approved — to be paid', 'green'], 'paid' => ['Paid', 'green'], 'rejected' => ['Rejected', 'red'], 'cancelled' => ['Cancelled', '']];
}

function fp_category_options(): array
{
    $out = [];
    foreach (fp_all('SELECT id, name FROM fp_expense_categories WHERE active = 1 ORDER BY name') as $r) {
        $out[(int) $r['id']] = $r['name'];
    }
    return $out;
}

/** May the viewer change this claim? Its employee, while it is a draft or returned to them. */
function fp_claim_mine(array $c): bool
{
    return fp_self_writable() && (int) $c['employee_person_id'] === fp_me() && in_array($c['status'], ['draft', 'returned'], true);
}

/**
 * Saves a claim (new, or the employee's draft / returned one). Lines carry
 * their own receipts: files[<line index>]. Returns the claim id.
 */
function fp_claim_save(?int $id, array $p): int
{
    if (!fp_self_writable() || fp_me() <= 0) {
        fabo_deny('Expense claims are made by a signed-in member of the organisation.', 403);
    }
    $purpose = fp_str($p['purpose'] ?? '', 500, true, 'What the claim is for');
    $cc = fp_ref($p['cost_centre_id'] ?? null, 'fp_cost_centres', 'cost centre', true, true);
    $project = fp_ref($p['project_id'] ?? null, 'fp_projects', 'project');
    $from = fp_date($p['trip_from'] ?? '', false, 'Trip from');
    $to = fp_date($p['trip_to'] ?? '', false, 'Trip to');
    if ($from && $to && $to < $from) {
        throw new InvalidArgumentException('The trip ends before it starts.');
    }
    $advance = fp_ref($p['advance_id'] ?? null, 'fp_advances', 'advance');
    if ($advance) {
        $adv = fp_doc('advance', $advance);
        if ((int) $adv['employee_person_id'] !== fp_me() || $adv['status'] !== 'paid') {
            throw new InvalidArgumentException('Settle only an advance paid to you and not yet settled.');
        }
    }
    $lines = [];
    foreach ((array) ($p['lines'] ?? []) as $i => $l) {
        $cat = (int) ($l['category_id'] ?? 0);
        $desc = trim((string) ($l['description'] ?? ''));
        if (!$cat && $desc === '' && trim((string) ($l['amount'] ?? '')) === '' && trim((string) ($l['km'] ?? '')) === '') {
            continue;
        }
        $n = count($lines) + 1;
        $c = $cat ? fp_one('SELECT * FROM fp_expense_categories WHERE id = ? AND active = 1', 'i', [$cat]) : null;
        if (!$c) {
            throw new InvalidArgumentException('Line ' . $n . ': choose a category.');
        }
        $date = fp_date($l['expense_date'] ?? '', true, 'Date on line ' . $n);
        $km = null;
        $days = null;
        if ($c['kind'] === 'mileage') {
            $rate = (float) fp_setting('mileage_rate');
            if ($rate <= 0) {
                throw new InvalidArgumentException('Mileage is not set up: ask Finance to set the rate per km in Settings.');
            }
            $km = (float) fp_dec($l['km'] ?? '', true, 'Distance (km) on line ' . $n, 0.1, 100000);
            $amount = fp_r2($km * $rate);
        } elseif ($c['kind'] === 'per_diem') {
            $rate = (float) fp_setting('per_diem_rate');
            if ($rate <= 0) {
                throw new InvalidArgumentException('Per diem is not set up: ask Finance to set the daily rate in Settings.');
            }
            $days = (float) fp_dec($l['days'] ?? '', true, 'Days on line ' . $n, 0.5, 366);
            $amount = fp_r2($days * $rate);
        } else {
            $amount = (float) fp_dec($l['amount'] ?? '', true, 'Amount on line ' . $n, 0.01, 10000000);
        }
        $lines[] = ['i' => $i, 'category' => $c, 'date' => $date, 'description' => mb_substr($desc ?: $c['name'], 0, 255), 'merchant' => fp_str($l['merchant'] ?? '', 160),
                    'amount' => $amount, 'tax' => (float) (fp_dec($l['tax_amount'] ?? '', false, 'GST on line ' . $n, 0) ?? 0), 'km' => $km, 'days' => $days,
                    'paid_by' => ($l['paid_by'] ?? '') === 'card' ? 'card' : 'employee', 'keep_file' => (int) ($l['receipt_file_id'] ?? 0) ?: null];
    }
    if (!$lines) {
        throw new InvalidArgumentException('Add at least one expense.');
    }
    return fp_tx(static function () use ($id, $p, $purpose, $cc, $project, $from, $to, $advance, $lines) {
        if ($id) {
            $c = fp_claim($id);
            if (!$c || !fp_claim_mine($c)) {
                throw new InvalidArgumentException('Only your own draft or returned claim can be changed.');
            }
            fp_bump('fp_claims', $id, $p['version'] ?? 0);
            fp_exec('UPDATE fp_claims SET purpose=?, cost_centre_id=?, project_id=?, trip_from=?, trip_to=?, advance_id=? WHERE id=?', 'siissii', [$purpose, $cc, $project, $from, $to, $advance, $id]);
            fp_exec('DELETE FROM fp_claim_lines WHERE claim_id = ?', 'i', [$id]);
        } else {
            $id = fp_exec('INSERT INTO fp_claims (number, employee_person_id, employee_name, cost_centre_id, project_id, purpose, trip_from, trip_to, advance_id, prepared_by_id, prepared_by)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?)', 'sisiisssiis', [fp_next_no('CLM', date('Y-m-d')), fp_me(), fp_actor(), $cc, $project, $purpose, $from, $to, $advance, fp_me(), fp_actor()]);
            fp_audit('claim', $id, 'create', 'Expense claim started: ' . $purpose);
        }
        foreach ($lines as $l) {
            $fileId = null;
            $f = $_FILES['receipt'] ?? null;
            if ($f && is_array($f['name']) && isset($f['name'][$l['i']]) && ($f['error'][$l['i']] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $fileId = fp_attach('claim', $id, ['name' => $f['name'][$l['i']], 'type' => $f['type'][$l['i']], 'tmp_name' => $f['tmp_name'][$l['i']],
                                                   'error' => $f['error'][$l['i']], 'size' => $f['size'][$l['i']]], 'Receipt: ' . $l['description']);
            } elseif ($l['keep_file'] && fp_one('SELECT id FROM fp_files WHERE id = ? AND doc_type = "claim" AND doc_id = ?', 'ii', [$l['keep_file'], $id])) {
                $fileId = $l['keep_file'];
            }
            fp_exec('INSERT INTO fp_claim_lines (claim_id, expense_date, category_id, description, merchant, amount, tax_amount, km, days, paid_by, receipt_file_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                    'isisssssssi', [$id, $l['date'], (int) $l['category']['id'], $l['description'], $l['merchant'] ?: null, fp_amt($l['amount']), fp_amt($l['tax']),
                                    $l['km'] !== null ? (string) $l['km'] : null, $l['days'] !== null ? (string) $l['days'] : null, $l['paid_by'], $fileId]);
        }
        fp_claim_policy($id);
        return $id;
    });
}

/**
 * Checks a claim against policy and writes the findings on each line and on
 * the claim: [findings, blocking]. Blocking findings stop submission.
 */
function fp_claim_policy(int $id): array
{
    $c = fp_claim($id);
    $find = [];
    $block = [];
    $back = (int) fp_setting('claim_backdate_days');
    $receiptAbove = fp_setting('claim_receipt_above');
    $total = 0;
    $card = 0;
    foreach (fp_claim_lines($id) as $l) {
        $flags = [];
        $cat = fp_one('SELECT * FROM fp_expense_categories WHERE id = ?', 'i', [(int) $l['category_id']]);
        if ($l['expense_date'] > date('Y-m-d')) {
            $block[] = $flags[] = 'dated in the future';
        }
        if ($back > 0 && $l['expense_date'] < date('Y-m-d', strtotime('-' . $back . ' day'))) {
            $block[] = $flags[] = 'older than the ' . $back . ' days a claim may go back';
        }
        if ($cat['limit_per_item'] !== null && (float) $l['amount'] > (float) $cat['limit_per_item']) {
            $flags[] = 'over the ' . $cat['name'] . ' limit of ' . fp_money($cat['limit_per_item']);
        }
        $need = $cat['receipt_above'] !== null ? (float) $cat['receipt_above'] : ($receiptAbove !== '' ? (float) $receiptAbove : null);
        if ($need !== null && !in_array($cat['kind'], ['mileage', 'per_diem'], true) && (float) $l['amount'] > $need && !$l['receipt_file_id']) {
            $block[] = $flags[] = 'needs a receipt (above ' . fp_money($need) . ')';
        }
        if ($c['trip_from'] && $c['trip_to'] && ($l['expense_date'] < $c['trip_from'] || $l['expense_date'] > $c['trip_to']) && in_array($cat['kind'], ['travel', 'accommodation', 'per_diem'], true)) {
            $flags[] = 'outside the trip dates';
        }
        // The same expense on another claim: same person, date, category and amount.
        $dup = fp_one('SELECT c.number FROM fp_claim_lines l JOIN fp_claims c ON c.id = l.claim_id WHERE c.employee_person_id = ? AND c.id <> ? AND c.status NOT IN ("rejected","cancelled")
                        AND l.expense_date = ? AND l.category_id = ? AND ABS(l.amount - ?) < 0.01 LIMIT 1', 'iisid',
                      [(int) $c['employee_person_id'], $id, $l['expense_date'], (int) $l['category_id'], (float) $l['amount']]);
        if ($dup) {
            $block[] = $flags[] = 'looks like the same expense already claimed on ' . $dup['number'];
        }
        if ($l['receipt_file_id'] && ($same = fp_one('SELECT f2.doc_id FROM fp_files f1 JOIN fp_files f2 ON f2.original_name = f1.original_name AND f2.size = f1.size AND f2.id <> f1.id
                                                      WHERE f1.id = ? AND f2.doc_type = "claim" AND f2.doc_id <> ? LIMIT 1', 'ii', [(int) $l['receipt_file_id'], $id]))) {
            $flags[] = 'the same receipt file is attached to claim ' . (fp_claim((int) $same['doc_id'])['number'] ?? '#' . $same['doc_id']);
        }
        fp_exec('UPDATE fp_claim_lines SET flags = ? WHERE id = ?', 'si', [$flags ? implode('; ', $flags) : null, (int) $l['id']]);
        foreach ($flags as $fl) {
            $find[] = fp_fmt_date($l['expense_date']) . ' ' . $l['category'] . ' ' . fp_money($l['amount']) . ': ' . $fl;
        }
        $total += fp_paise($l['amount']);
        if ($l['paid_by'] === 'card') {
            $card += fp_paise($l['amount']);
        }
    }
    $adv = $c['advance_id'] ? fp_doc('advance', (int) $c['advance_id']) : null;
    $reimb = ($total - $card) / 100;
    $advAdj = $adv ? min($reimb, (float) $adv['paid_amount'] - (float) $adv['settled_amount']) : 0.0;
    fp_exec('UPDATE fp_claims SET total = ?, card_total = ?, advance_adjusted = ?, net_payable = ?, policy_flags = ? WHERE id = ?', 'sssssi',
            [fp_amt($total / 100), fp_amt($card / 100), fp_amt($advAdj), fp_amt($reimb - $advAdj), $find ? implode("\n", $find) : null, $id]);
    return [$find, $block];
}

function fp_claim_submit(int $id, $version): string
{
    $c = fp_claim($id);
    if (!$c || !fp_claim_mine($c)) {
        throw new InvalidArgumentException('Only your own draft or returned claim can be submitted.');
    }
    [, $block] = fp_claim_policy($id);
    if ($block) {
        throw new InvalidArgumentException('Fix these first: ' . implode('; ', array_unique($block)) . '.');
    }
    return fp_tx(static function () use ($id, $c, $version) {
        fp_bump('fp_claims', $id, $version);
        fp_exec('UPDATE fp_claims SET status = "submitted", submitted_at = NOW() WHERE id = ?', 'i', [$id]);
        fp_audit('claim', $id, $c['status'] === 'returned' ? 'resubmit' : 'submit', 'Claim ' . ($c['status'] === 'returned' ? 're' : '') . 'submitted (' . fp_money(fp_claim($id)['total']) . ')');
        $fresh = fp_claim($id);
        fp_wf_start('claim', $id, (float) $fresh['total'], $fresh['cost_centre_id'] ? (int) $fresh['cost_centre_id'] : null, (int) $fresh['entity_id'], (int) $fresh['employee_person_id'],
                    $fresh['hr_claim_id'] ? ['manager'] : []);
        return 'Submitted. You can follow it here; you will be told when it is decided.';
    });
}

function fp_wf_on_claim(string $event, array $c, string $comment): void
{
    $id = (int) $c['id'];
    if ($event === 'approved') {
        fp_claim_post($id);
        return;
    }
    fp_exec('UPDATE fp_claims SET status = ?, return_note = ? WHERE id = ?', 'ssi', [$event === 'rejected' ? 'rejected' : 'returned', $comment, $id]);
}

/** Posts an approved claim: expense (and input GST where receipts carry it) against what is owed to the employee. */
function fp_claim_post(int $id): void
{
    fp_tx(static function () use ($id) {
        $c = fp_one('SELECT * FROM fp_claims WHERE id = ? FOR UPDATE', 'i', [$id]);
        if ($c['journal_id']) {
            return;
        }
        $jl = [];
        foreach (fp_claim_lines($id) as $l) {
            $amt = (float) ($l['approved_amount'] ?? $l['amount']);
            $tax = min((float) $l['tax_amount'], $amt);
            $jl[] = ['account_id' => (int) $l['account_id'], 'debit' => fp_r2($amt - $tax), 'credit' => 0, 'cost_centre_id' => $c['cost_centre_id'], 'project_id' => $c['project_id'],
                     'description' => $l['category'] . ': ' . $l['description'], 'party_type' => 'employee', 'party_id' => (int) $c['employee_person_id']];
            if ($tax > 0) {
                $jl[] = ['account_id' => fp_acct('gst_in_igst'), 'debit' => $tax, 'credit' => 0, 'description' => 'GST on ' . $l['description']];
            }
        }
        if ((float) $c['card_total'] > 0) {
            $jl[] = ['account_id' => fp_acct('card_payable'), 'debit' => 0, 'credit' => (float) $c['card_total'], 'description' => 'Paid on the company card'];
        }
        if ((float) $c['advance_adjusted'] > 0) {
            $jl[] = ['account_id' => fp_acct('emp_adv'), 'debit' => 0, 'credit' => (float) $c['advance_adjusted'], 'description' => 'Advance settled', 'party_type' => 'employee', 'party_id' => (int) $c['employee_person_id']];
            fp_exec('UPDATE fp_advances SET settled_amount = settled_amount + ?, status = IF(settled_amount + 0.001 >= paid_amount, "settled", status) WHERE id = ?', 'si', [$c['advance_adjusted'], (int) $c['advance_id']]);
        }
        $jl[] = ['account_id' => fp_acct('emp_payable'), 'debit' => 0, 'credit' => (float) $c['net_payable'], 'description' => 'Owed to ' . $c['employee_name'], 'party_type' => 'employee', 'party_id' => (int) $c['employee_person_id']];
        $jid = fp_journal_system(['entity_id' => (int) $c['entity_id'], 'jdate' => date('Y-m-d'), 'type' => 'system', 'source' => 'claim', 'source_id' => $id, 'reference' => $c['number'],
                                  'description' => 'Expense claim ' . $c['number'] . ' — ' . $c['employee_name']], $jl);
        $status = (float) $c['net_payable'] <= 0.004 ? 'paid' : 'approved';
        fp_exec('UPDATE fp_claims SET journal_id = ?, status = ?, approved_total = total, return_note = NULL WHERE id = ?', 'isi', [$jid, $status, $id]);
        fp_audit('claim', $id, 'post', 'Claim approved and posted' . ($status === 'paid' ? ' (nothing to reimburse)' : ''));
    });
}

/** Called when a reimbursement payment completes. */
function fp_claim_paid(int $id, float $amount, string $ref, string $date): void
{
    fp_exec('UPDATE fp_claims SET paid_amount = paid_amount + ? WHERE id = ?', 'si', [fp_amt($amount), $id]);
    $c = fp_claim($id);
    if ((float) $c['paid_amount'] + 0.001 >= (float) $c['net_payable']) {
        fp_exec('UPDATE fp_claims SET status = "paid" WHERE id = ?', 'i', [$id]);
        if ($c['hr_claim_id']) {
            // HR's own record of the claim is settled too.
            fabo_exec("UPDATE hr_claims SET status = 'paid', paid_via = 'direct', paid_on = ?, paid_ref = ? WHERE id = ? AND org_id = ? AND status = 'approved'",
                      'ssii', [$date, mb_substr('Finance ' . $ref, 0, 120), (int) $c['hr_claim_id'], fp_org()]);
        }
        fp_notify([(int) $c['employee_person_id']], 'Expense claim paid', $c['number'] . ' was reimbursed (' . fp_money($c['net_payable']) . ', ref ' . $ref . ').', 'claim', ['id' => $id]);
    }
}

function fp_claim_cancel(int $id, $version): void
{
    $c = fp_claim($id);
    if (!$c || (int) $c['employee_person_id'] !== fp_me() || !in_array($c['status'], ['draft', 'returned', 'submitted'], true)) {
        throw new InvalidArgumentException('You can withdraw only your own claim before it is approved.');
    }
    fp_bump('fp_claims', $id, $version);
    fp_exec('UPDATE fp_claims SET status = "cancelled" WHERE id = ?', 'i', [$id]);
    fp_wf_cancel('claim', $id, 'Withdrawn by the employee');
    fp_audit('claim', $id, 'cancel', 'Claim withdrawn');
}

/**
 * Where a claim stands, in the words the employee needs: what happens next,
 * who has it, and — when it came back — exactly why.
 */
function fp_claim_explain(array $c): string
{
    $s = $c['status'];
    if ($s === 'draft') {
        return 'Not submitted yet. Add your expenses and receipts, then submit.';
    }
    if ($s === 'returned' || $s === 'rejected') {
        $r = fp_wf_last_reason('claim', (int) $c['id']);
        return ($s === 'returned' ? 'Sent back to you by ' : 'Rejected by ') . ($r['actor'] ?? 'the approver') . ($r ? ' on ' . fp_fmt_dt($r['created_at']) : '')
             . '. Reason: “' . ($r['comment'] ?? $c['return_note']) . '”' . ($s === 'returned' ? ' Correct it and submit again.' : '');
    }
    if ($s === 'submitted') {
        $step = fp_one('SELECT * FROM fp_wf_steps WHERE doc_type = "claim" AND doc_id = ? AND status = "pending" ORDER BY level LIMIT 1', 'i', [(int) $c['id']]);
        if ($step) {
            [$people, $fallback] = fp_wf_approvers($step);
            return 'Waiting for ' . ($step['label'] ?: 'approval') . ' — ' . ($people ? implode(', ', array_map('fp_person_name', $people)) : 'the finance administrator')
                 . ' since ' . fp_fmt_dt($step['activated_at']) . '.' . ($fallback ? ' (Nobody holds that role, so the finance administrator decides.)' : '');
        }
        return 'Waiting for approval.';
    }
    if ($s === 'approved') {
        $pay = fp_one('SELECT p.* FROM fp_payments p JOIN fp_payment_allocs a ON a.payment_id = p.id WHERE a.doc_type = "claim" AND a.doc_id = ? AND p.status NOT IN ("cancelled","rejected","failed") ORDER BY p.id DESC LIMIT 1', 'i', [(int) $c['id']]);
        return 'Approved for ' . fp_money($c['net_payable']) . '. ' . ($pay ? 'Payment ' . $pay['number'] . ' is ' . strtolower(fp_payment_statuses()[$pay['status']][0]) . '.' : 'Finance will pay it in the next payment run.');
    }
    if ($s === 'paid') {
        return 'Paid' . ((float) $c['net_payable'] > 0 ? ' — ' . fp_money($c['net_payable']) : ' (nothing was owed after the card and advance)') . '.';
    }
    return 'Withdrawn.';
}

/* ================================================================ advances */

function fp_advance_save(array $p): int
{
    if (!fp_self_writable() || fp_me() <= 0) {
        fabo_deny('Advances are asked for by a signed-in member of the organisation.', 403);
    }
    $purpose = fp_str($p['purpose'] ?? '', 500, true, 'What the advance is for');
    $amt = fp_dec($p['amount'] ?? '', true, 'Amount', 1);
    $cc = fp_ref($p['cost_centre_id'] ?? null, 'fp_cost_centres', 'cost centre', true, true);
    $need = fp_date($p['needed_by'] ?? '', false, 'Needed by');
    if (fp_one('SELECT id FROM fp_advances WHERE employee_person_id = ? AND status = "paid"', 'i', [fp_me()])) {
        throw new InvalidArgumentException('Settle your open advance with a claim before asking for another.');
    }
    return fp_tx(static function () use ($purpose, $amt, $cc, $need) {
        $id = fp_exec('INSERT INTO fp_advances (number, employee_person_id, employee_name, cost_centre_id, purpose, needed_by, amount, status, prepared_by_id, prepared_by) VALUES (?,?,?,?,?,?,?,"submitted",?,?)',
                      'sisisssis', [fp_next_no('ADV', date('Y-m-d')), fp_me(), fp_actor(), $cc, $purpose, $need, $amt, fp_me(), fp_actor()]);
        fp_audit('advance', $id, 'create', 'Travel advance requested: ' . fp_money($amt));
        fp_wf_start('advance', $id, (float) $amt, $cc, 1, fp_me());
        return $id;
    });
}

function fp_wf_on_advance(string $event, array $a, string $comment): void
{
    fp_exec('UPDATE fp_advances SET status = ?, return_note = ? WHERE id = ?', 'ssi',
            [$event === 'approved' ? 'approved' : ($event === 'rejected' ? 'rejected' : 'cancelled'), $event === 'approved' ? null : $comment, (int) $a['id']]);
}

/* ================================================================ from HR */

/**
 * Brings in expense claims HR has approved but not paid, at the Finance
 * review step. Each comes in once; HR's category becomes the nearest
 * Finance category (Other when none matches).
 */
function fp_import_hr_claims(): array
{
    fp_need('claims.view');
    try {
        $rows = fabo_all("SELECT c.*, e.person_id, e.full_name, m.name AS category FROM hr_claims c JOIN hr_employees e ON e.id = c.employee_id
                           LEFT JOIN hr_masters m ON m.id = c.category_id WHERE c.org_id = ? AND c.status = 'approved'", 'i', [fp_org()]);
    } catch (Throwable $e) {
        throw new InvalidArgumentException('HR is not in use for this organisation, so there are no HR claims to bring in.');
    }
    $made = 0;
    $skipped = [];
    $other = (int) (fp_one('SELECT id FROM fp_expense_categories WHERE name = "Other"')['id'] ?? fp_one('SELECT id FROM fp_expense_categories ORDER BY id LIMIT 1')['id']);
    $gen = (int) (fp_one('SELECT id FROM fp_cost_centres WHERE active = 1 ORDER BY id LIMIT 1')['id'] ?? 0) ?: null;
    foreach ($rows as $h) {
        if (fp_one('SELECT id FROM fp_claims WHERE hr_claim_id = ?', 'i', [(int) $h['id']])) {
            continue;
        }
        if (!$h['person_id']) {
            $skipped[] = $h['number'] . ': ' . $h['full_name'] . ' has no portal sign-in, so the reimbursement cannot be addressed to them';
            continue;
        }
        $cat = $h['category'] ? fp_one('SELECT id FROM fp_expense_categories WHERE name = ? OR name LIKE ? LIMIT 1', 'ss', [$h['category'], $h['category'] . '%']) : null;
        fp_tx(static function () use ($h, $cat, $other, $gen, &$made) {
            $amt = (float) ($h['approved_amount'] ?? $h['amount']);
            $cc = $gen;
            try {
                $dept = fabo_one('SELECT department_id FROM hr_employees WHERE id = ?', 'i', [(int) $h['employee_id']]);
                if ($dept && $dept['department_id']) {
                    $c = fp_one('SELECT id FROM fp_cost_centres WHERE hr_department_id = ? AND active = 1', 'i', [(int) $dept['department_id']]);
                    $cc = $c ? (int) $c['id'] : $cc;
                }
            } catch (Throwable $e) {
            }
            $id = fp_exec('INSERT INTO fp_claims (number, employee_person_id, employee_name, cost_centre_id, purpose, total, net_payable, status, hr_claim_id, submitted_at, prepared_by_id, prepared_by)
                           VALUES (?,?,?,?,?,?,?,"draft",?,NOW(),?,?)', 'sisisssiis',
                          [fp_next_no('CLM', date('Y-m-d')), (int) $h['person_id'], $h['full_name'], $cc, mb_substr('HR claim ' . $h['number'] . ': ' . $h['description'], 0, 500),
                           fp_amt($amt), fp_amt($amt), (int) $h['id'], (int) $h['person_id'], 'HR (' . $h['decided_by'] . ')']);
            $file = null;
            if ($h['receipt_file']) {
                $file = fp_exec('INSERT INTO fp_files (doc_type, doc_id, stored_name, original_name, mime, note, uploaded_by) VALUES ("claim",?,?,?,?,?,?)', 'isssss',
                                [$id, $h['receipt_file'], $h['receipt_name'] ?: 'receipt', $h['receipt_mime'], 'Receipt from HR', 'HR']);
            }
            fp_exec('INSERT INTO fp_claim_lines (claim_id, expense_date, category_id, description, amount, receipt_file_id) VALUES (?,?,?,?,?,?)', 'isissi',
                    [$id, $h['expense_date'], $cat ? (int) $cat['id'] : $other, mb_substr((string) $h['description'], 0, 255), fp_amt($amt), $file]);
            fp_exec('UPDATE fp_claims SET status = "submitted" WHERE id = ?', 'i', [$id]);
            fp_wf_event('claim', $id, 1, null, null, 'import', 'Approved in HR by ' . $h['decided_by'] . ' on ' . $h['decided_at']);
            fp_wf_start('claim', $id, $amt, $cc, 1, (int) $h['person_id'], ['manager']);
            fp_audit('claim', $id, 'import', 'Brought in from HR claim ' . $h['number']);
            $made++;
        });
    }
    return [$made, $skipped];
}

/**
 * Posts a finalised HR payroll month to the ledger: gross pay and the
 * employer's contributions as expense; net pay as salaries payable; the
 * deductions and contributions as statutory dues payable. Once per month.
 */
function fp_import_payroll(int $runId, ?int $cc): int
{
    fp_need('gl.prepare');
    try {
        $run = fabo_one("SELECT * FROM hr_payroll_runs WHERE id = ? AND org_id = ?", 'ii', [$runId, fp_org()]);
    } catch (Throwable $e) {
        throw new InvalidArgumentException('HR payroll is not in use for this organisation.');
    }
    if (!$run || !in_array($run['status'], ['finalized', 'finalised', 'paid', 'locked'], true) && !$run['finalized_at']) {
        throw new InvalidArgumentException('Post a payroll month once HR has finalised it.');
    }
    if (fp_one('SELECT id FROM fp_imports WHERE source = "payroll" AND source_id = ?', 'i', [$runId])) {
        throw new InvalidArgumentException('Payroll for ' . $run['period'] . ' is already in the ledger.');
    }
    $t = fabo_one('SELECT COALESCE(SUM(gross),0) g, COALESCE(SUM(deductions),0) d, COALESCE(SUM(employer_cost),0) ec, COALESCE(SUM(net),0) n, COUNT(*) c
                    FROM hr_payroll_items WHERE run_id = ? AND hold = 0', 'i', [$runId]);
    if ((int) $t['c'] === 0) {
        throw new InvalidArgumentException('That payroll month has nobody on it.');
    }
    $cc = fp_ref($cc, 'fp_cost_centres', 'cost centre');
    return fp_tx(static function () use ($run, $t, $cc, $runId) {
        $jid = fp_journal_system(['jdate' => $run['period_end'], 'type' => 'system', 'source' => 'payroll', 'source_id' => $runId, 'reference' => 'Payroll ' . $run['period'],
                                  'description' => 'Payroll for ' . date('F Y', strtotime($run['period_start'])) . ' (' . (int) $t['c'] . ' employees, from HR)'], [
            ['account_id' => fp_acct('salary_exp'), 'debit' => (float) $t['g'], 'credit' => 0, 'cost_centre_id' => $cc, 'description' => 'Gross pay'],
            ['account_id' => fp_acct('employer_contrib'), 'debit' => (float) $t['ec'], 'credit' => 0, 'cost_centre_id' => $cc, 'description' => 'Employer contributions'],
            ['account_id' => fp_acct('salary_payable'), 'debit' => 0, 'credit' => (float) $t['n'], 'description' => 'Net pay'],
            // Gross less net is what was deducted; with the employer's share it is owed to the authorities.
            ['account_id' => fp_acct('statutory_payable'), 'debit' => 0, 'credit' => fp_r2((float) $t['g'] - (float) $t['n'] + (float) $t['ec']), 'description' => 'Deductions and contributions'],
        ]);
        fp_exec('INSERT INTO fp_imports (source, source_id, target, target_id) VALUES ("payroll", ?, "journal", ?)', 'ii', [$runId, $jid]);
        fp_audit('journal', $jid, 'import', 'Payroll ' . $run['period'] . ' posted from HR');
        return $jid;
    });
}

/** HR payroll months that are final and not yet in the ledger. */
function fp_payroll_runs_open(): array
{
    try {
        $runs = fabo_all("SELECT r.*, (SELECT COALESCE(SUM(net),0) FROM hr_payroll_items i WHERE i.run_id = r.id AND i.hold = 0) net
                            FROM hr_payroll_runs r WHERE r.org_id = ? AND r.finalized_at IS NOT NULL ORDER BY r.period DESC LIMIT 24", 'i', [fp_org()]);
    } catch (Throwable $e) {
        return [];
    }
    return array_values(array_filter($runs, static fn($r) => !fp_one('SELECT id FROM fp_imports WHERE source = "payroll" AND source_id = ?', 'i', [(int) $r['id']])));
}

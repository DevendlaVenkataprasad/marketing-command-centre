<?php
/**
 * One expense claim. The employee builds it line by line, each with its own
 * receipt, sees every policy note as they go, and submits; the page always
 * says in plain words where it stands and, when it came back, why.
 */

$id = (int) ($_GET['id'] ?? 0);
$c = $id ? fp_claim($id) : null;
if ($id && !$c) {
    fabo_deny('That claim does not exist.', 404);
}
if ($c && !fp_doc_can_view('claim', $c)) {
    fabo_deny('That claim is not yours to see.', 403);
}
$back = $c ? fp_url('claim', ['id' => $id]) : fp_url('me');
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    if ($c && ($x = fp_wf_post('claim', $id, $back))) {
        $flash = $x;
    } elseif ($a === 'save' || $a === 'save_submit') {
        $flash = fp_action(static function () use ($c, $a) {
            if (!$c) {
                fp_nonce_check();
            }
            $nid = fp_claim_save($c ? (int) $c['id'] : null, $_POST);
            if ($a === 'save_submit') {
                fp_submit_or_draft(static fn() => fp_claim_submit($nid, fp_claim($nid)['version']), fp_url('claim', ['id' => $nid]));
            }
            inc_redirect(fp_url('claim', ['id' => $nid]), ['ok', 'Saved. Submit it when every expense is in.']);
        }, $back);
    } elseif ($c && $a === 'submit') {
        $flash = fp_action(static fn() => fp_claim_submit($id, $_POST['version'] ?? 0), $back);
    } elseif ($c && $a === 'cancel') {
        $flash = fp_action(static function () use ($id) {
            fp_claim_cancel($id, $_POST['version'] ?? 0);
            return 'Claim withdrawn.';
        }, $back);
    }
}

$editable = !$c ? fp_self_writable() && fp_me() > 0 : fp_claim_mine($c);
if ($editable && (!$c || isset($_GET['edit']) || $flash)) {
    $v = $c ?? ['purpose' => '', 'cost_centre_id' => '', 'trip_from' => '', 'trip_to' => '', 'advance_id' => ''];
    if (!$c && ($hr = fp_one('SELECT id FROM fp_cost_centres WHERE active = 1 ORDER BY id LIMIT 1'))) {
        $v['cost_centre_id'] = $hr['id'];
    }
    $lines = $c ? fp_claim_lines($id) : [];
    if ($flash && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $v = $_POST + $v;
        $lines = array_values((array) ($_POST['lines'] ?? []));
    }
    $lines[] = [];
    if (count($lines) < 3) {
        $lines[] = [];
    }
    $cats = [];
    foreach (fp_all('SELECT * FROM fp_expense_categories WHERE active = 1 ORDER BY name') as $x) {
        $cats[(int) $x['id']] = $x;
    }
    $advs = [];
    foreach (fp_all('SELECT * FROM fp_advances WHERE employee_person_id = ? AND status = "paid"', 'i', [fp_me()]) as $x) {
        $advs[(int) $x['id']] = $x['number'] . ' — ' . fp_money((float) $x['paid_amount'] - (float) $x['settled_amount']) . ' to settle';
    }
    fp_layout_start($c ? 'Edit claim ' . $c['number'] : 'New expense claim', 'One line per expense, each with its receipt. Policy notes appear as you save.', '', ['My finance' => fp_url('me'), $c ? $c['number'] : 'New claim' => null], $flash);
    if ($c && $c['status'] === 'returned') {
        echo '<div class="inc-warn"><strong>Returned to you.</strong> ' . e(fp_claim_explain($c)) . '</div>';
    }
    $rate = fp_setting('mileage_rate');
    $pd = fp_setting('per_diem_rate');
    echo '<section class="card">' . inc_form_open('', true) . ($c ? '<input type="hidden" name="version" value="' . (int) $c['version'] . '">' : fp_nonce_field())
       . inc_field('What the claim is for', '<input name="purpose" maxlength="500" required value="' . e($v['purpose']) . '" placeholder="e.g. Client visit to Chennai, 12–14 Oct">', '', true)
       . '<div class="inc-row">' . inc_field('Cost centre', inc_select('cost_centre_id', fp_cc_options(), $v['cost_centre_id'], 'Choose…', 'required'), '', true)
       . (fp_project_options() ? inc_field('Project', inc_select('project_id', fp_project_options(), $v['project_id'] ?? '', '—')) : '')
       . inc_field('Trip from', '<input type="date" name="trip_from" value="' . e($v['trip_from'] ?? '') . '">') . inc_field('Trip to', '<input type="date" name="trip_to" value="' . e($v['trip_to'] ?? '') . '">')
       . ($advs ? inc_field('Settle an advance', inc_select('advance_id', $advs, $v['advance_id'] ?? '', 'None')) : '') . '</div>'
       . '<div class="scrollx"><table class="data inc-table fp-lines" data-lines="claim"><thead><tr><th>Date</th><th>Category</th><th>Description</th><th>Merchant</th><th class="num">Amount</th><th class="num">km / days</th>'
       . '<th class="num">GST in it</th><th>Paid by</th><th>Receipt</th><th></th></tr></thead><tbody>';
    foreach ($lines as $i => $l) {
        $n = 'lines[' . $i . ']';
        $catSel = '<select name="' . $n . '[category_id]"><option value="">Choose…</option>';
        foreach ($cats as $cid => $cat) {
            $catSel .= '<option value="' . $cid . '" data-kind="' . e($cat['kind']) . '"' . ((int) ($l['category_id'] ?? 0) === $cid ? ' selected' : '') . '>' . e($cat['name']) . '</option>';
        }
        $catSel .= '</select>';
        $file = !empty($l['receipt_file_id']) ? fp_one('SELECT * FROM fp_files WHERE id = ?', 'i', [(int) $l['receipt_file_id']]) : null;
        echo '<tr><td data-l="Date"><input type="date" name="' . $n . '[expense_date]" value="' . e($l['expense_date'] ?? '') . '"></td><td data-l="Category">' . $catSel . '</td>'
           . '<td data-l="Description"><input name="' . $n . '[description]" maxlength="255" value="' . e($l['description'] ?? '') . '"></td><td data-l="Merchant"><input name="' . $n . '[merchant]" maxlength="160" size="10" value="' . e($l['merchant'] ?? '') . '"></td>'
           . '<td class="num" data-l="Amount"><input name="' . $n . '[amount]" size="8" inputmode="decimal" value="' . e(fp_inp($l['amount'] ?? '')) . '"></td>'
           . '<td class="num" data-l="km / days"><input name="' . $n . '[km]" size="5" placeholder="km" value="' . e((string) ($l['km'] ?? '')) . '"><input name="' . $n . '[days]" size="4" placeholder="days" value="' . e((string) ($l['days'] ?? '')) . '"></td>'
           . '<td class="num" data-l="GST in it"><input name="' . $n . '[tax_amount]" size="6" inputmode="decimal" value="' . e(isset($l['tax_amount']) && (float) $l['tax_amount'] ? fp_amt($l['tax_amount']) : '') . '"></td>'
           . '<td data-l="Paid by">' . inc_select($n . '[paid_by]', ['employee' => 'Me', 'card' => 'Company card'], $l['paid_by'] ?? 'employee') . '</td>'
           . '<td data-l="Receipt">' . ($file ? '<a href="' . e(fp_url('file', ['id' => $file['id']])) . '" class="small">' . e(mb_strimwidth($file['original_name'], 0, 18, '…')) . '</a><input type="hidden" name="' . $n . '[receipt_file_id]" value="' . (int) $file['id'] . '">' : '')
           . '<input type="file" name="receipt[' . $i . ']" aria-label="Receipt"></td>'
           . '<td><button type="button" class="btn btn-sm" data-row-del>×</button>' . ($l['flags'] ?? '' ? '<div class="late small">' . e($l['flags']) . '</div>' : '') . '</td></tr>';
    }
    echo '</tbody></table></div><button type="button" class="btn btn-sm" data-row-add>+ Add expense</button>'
       . '<p class="muted small">Mileage: enter km (' . ($rate !== '' ? e(fp_money($rate)) . ' per km' : 'not set up yet') . '). Per diem: enter days (' . ($pd !== '' ? e(fp_money($pd)) . ' a day' : 'not set up yet') . '). '
       . 'Receipts are needed above ' . e(fp_setting('claim_receipt_above') !== '' ? fp_money(fp_setting('claim_receipt_above')) : 'any amount') . '; claims may go back ' . (int) fp_setting('claim_backdate_days') . ' days. Company-card lines are recorded but not reimbursed.</p>'
       . '<div class="inc-formfoot"><button class="btn" name="action" value="save">Save</button> <button class="btn btn-primary" name="action" value="save_submit">Submit for approval</button> <a class="btn" href="' . e($back) . '">Cancel</a></div></form></section>';
    if ($c && $c['policy_flags']) {
        echo '<section class="card"><h2>Policy notes</h2><ul class="small">' . implode('', array_map(static fn($x) => '<li>' . e($x) . '</li>', explode("\n", (string) $c['policy_flags']))) . '</ul></section>';
    }
    fp_layout_end();
    return;
}
if (!$c) {
    fabo_deny('Expense claims are made by a signed-in member of the organisation.', 403);
}

$lines = fp_claim_lines($id);
$acts = '';
if (fp_claim_mine($c)) {
    $acts .= '<a class="btn" href="' . e(fp_url('claim', ['id' => $id, 'edit' => 1])) . '">Edit</a> ' . inc_button('Submit for approval', ['action' => 'submit', 'version' => $c['version']], 'btn btn-primary') . ' ';
}
if ((int) $c['employee_person_id'] === fp_me() && in_array($c['status'], ['draft', 'returned', 'submitted'], true) && fp_writable()) {
    $acts .= inc_button('Withdraw', ['action' => 'cancel', 'version' => $c['version']], 'btn btn-danger', 'Withdraw this claim?');
}
if ($c['status'] === 'approved' && fp_can('pay.prepare')) {
    $acts .= ' <a class="btn btn-primary" href="' . e(fp_url('payment', ['new' => 'claim'])) . '">Reimburse</a>';
}
fp_layout_start('Expense claim ' . $c['number'], e($c['employee_name']) . ' · ' . e($c['purpose']), $acts, [((int) $c['employee_person_id'] === fp_me() ? 'My finance' : 'Expense claims') => fp_url((int) $c['employee_person_id'] === fp_me() ? 'me' : 'claims'), $c['number'] => null], $flash);
echo '<div class="fp-explain ' . e($c['status']) . '"><strong>' . e(fp_claim_statuses()[$c['status']][0]) . '.</strong> ' . e(fp_claim_explain($c)) . '</div>';
echo fp_steps_bar(['draft' => 'Draft', 'submitted' => 'Approval', 'approved' => 'Approved', 'paid' => 'Paid'], $c['status'] === 'returned' ? 'draft' : $c['status']);
echo '<section class="card">' . fp_facts([
    'Employee' => e($c['employee_name']), 'Cost centre' => e(fp_one('SELECT CONCAT(code, " · ", name) n FROM fp_cost_centres WHERE id = ?', 'i', [(int) $c['cost_centre_id']])['n'] ?? ''),
    'Trip' => $c['trip_from'] ? e(fp_fmt_date($c['trip_from']) . ' – ' . fp_fmt_date($c['trip_to'])) : '', 'From HR' => $c['hr_claim_id'] ? 'Approved in HR first; brought in for Finance review' : '',
    'Advance settled' => $c['advance_id'] ? '<a href="' . e(fp_url('advance', ['id' => $c['advance_id']])) . '">' . e(fp_doc('advance', (int) $c['advance_id'])['number'] ?? '') . '</a> · ' . e(fp_money($c['advance_adjusted'])) : '',
    'Ledger' => $c['journal_id'] ? '<a href="' . e(fp_url('journal', ['id' => $c['journal_id']])) . '">Posted journal</a>' : '',
]) . '<div class="scrollx"><table class="data inc-table stack"><thead><tr><th>Date</th><th>Category</th><th>Description</th><th class="num">Amount</th><th>Paid by</th><th>Receipt</th><th>Notes</th></tr></thead><tbody>';
foreach ($lines as $l) {
    echo '<tr><td data-l="Date">' . e(fp_fmt_date($l['expense_date'])) . '</td><td data-l="Category">' . e($l['category']) . '</td><td data-l="Description">' . e($l['description'])
       . ($l['merchant'] ? ' <span class="muted small">' . e($l['merchant']) . '</span>' : '') . ($l['km'] ? ' <span class="muted small">' . (float) $l['km'] . ' km</span>' : '') . ($l['days'] ? ' <span class="muted small">' . (float) $l['days'] . ' days</span>' : '') . '</td>'
       . fp_money_td($l['amount'], 'Amount') . '<td data-l="Paid by">' . ($l['paid_by'] === 'card' ? 'Company card' : 'Employee') . '</td>'
       . '<td data-l="Receipt">' . ($l['receipt_file_id'] ? '<a href="' . e(fp_url('file', ['id' => $l['receipt_file_id']])) . '">View</a>' : '<span class="muted">—</span>') . '</td>'
       . '<td data-l="Notes" class="small' . ($l['flags'] ? ' late' : '') . '">' . e($l['flags'] ?? '') . '</td></tr>';
}
echo '</tbody></table></div><div class="fp-totals"><span>Claimed ' . e(fp_money($c['total'])) . '</span>' . ((float) $c['card_total'] > 0 ? '<span>On company card −' . e(fp_money($c['card_total'])) . '</span>' : '')
   . ((float) $c['advance_adjusted'] > 0 ? '<span>Advance −' . e(fp_money($c['advance_adjusted'])) . '</span>' : '') . '<span class="g">To reimburse ' . e(fp_money($c['net_payable'])) . '</span>'
   . ((float) $c['paid_amount'] > 0 ? '<span>Paid ' . e(fp_money($c['paid_amount'])) . '</span>' : '') . '</div></section>';
echo fp_wf_panel('claim', $c, $back);
echo fp_files_panel('claim', $c, false, $back);
echo fp_audit_panel('claim', $id);
fp_layout_end();

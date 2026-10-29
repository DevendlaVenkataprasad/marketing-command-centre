<?php
/**
 * One vendor bill (or debit note): entered as a draft, then moved through
 * validation and approval to the ledger, and paid through Treasury. Each
 * button is offered only when the bill's status allows the move, and the
 * server checks the move again.
 */

$id = (int) ($_GET['id'] ?? 0);
$b = $id ? fp_bill($id) : null;
if ($id && !$b) {
    fabo_deny('That bill does not exist.', 404);
}
if ($b && !fp_doc_can_view('bill', $b)) {
    fabo_deny('You do not have access to vendor bills.', 403);
}
if (!$b && !fp_can('ap.prepare')) {
    fabo_deny('You cannot enter bills.', 403);
}
$back = $b ? fp_url('bill', ['id' => $id]) : fp_url('bills');
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    if ($b && ($r = fp_wf_post('bill', $id, $back))) {
        $flash = $r;
    } elseif ($b && ($r = fp_files_post('bill', $id, $back, fp_can('ap.prepare')))) {
        $flash = $r;
    } elseif ($a === 'save' || $a === 'save_submit') {
        $flash = fp_action(static function () use ($b, $a) {
            if (!$b) {
                fp_nonce_check();
            }
            [$nid, $dup] = fp_bill_save($b ? (int) $b['id'] : null, $_POST);
            if ($a === 'save_submit') {
                fp_submit_or_draft(static fn() => fp_bill_move($nid, 'submit', fp_bill($nid)['version'], $_POST), fp_url('bill', ['id' => $nid]));
            }
            inc_redirect(fp_url('bill', ['id' => $nid]), [$dup ? 'err' : 'ok', $dup ? 'Saved — but check: ' . $dup : 'Draft saved.']);
        }, $back);
    } elseif ($b && in_array($a, ['submit', 'review', 'approval', 'hold', 'release', 'cancel'], true)) {
        $flash = fp_action(static fn() => fp_bill_move($id, $a, $_POST['version'] ?? 0, $_POST), $back);
    } elseif ($b && $a === 'schedule') {
        $flash = fp_action(static function () use ($id) {
            fp_bill_schedule($id, $_POST['scheduled_on'] ?? null);
            return 'Payment date saved.';
        }, $back);
    } elseif ($b && $a === 'apply_note') {
        $flash = fp_action(static fn() => fp_ap_note_apply($id, (int) ($_POST['bill_id'] ?? 0), $_POST['amount'] ?? ''), $back);
    }
}

$editable = fp_can('ap.prepare') && (!$b || $b['status'] === 'draft');
if ($editable) {
    $kind = $b['kind'] ?? (($_GET['kind'] ?? '') === 'debit_note' ? 'debit_note' : 'bill');
    $po = $b ? ($b['po_id'] ? fp_po((int) $b['po_id']) : null) : (!empty($_GET['po']) ? fp_po((int) $_GET['po']) : null);
    $v = $b ?? ['vendor_id' => $po['vendor_id'] ?? ($_GET['vendor'] ?? ''), 'bill_date' => date('Y-m-d'), 'currency' => $po['currency'] ?? fp_setting('base_currency'), 'fx_rate' => $po['fx_rate'] ?? 1,
                'entity_id' => $po['entity_id'] ?? 1, 'po_id' => $po['id'] ?? null, 'kind' => $kind, 'ref_bill_id' => $_GET['ref'] ?? null, 'bill_no' => '', 'description' => ''];
    $lines = $b ? fp_bill_lines($id) : [];
    $poLines = [];
    if ($po) {
        foreach (fp_po_lines((int) $po['id']) as $pl) {
            $poLines[(int) $pl['id']] = '#' . $pl['line_no'] . ' ' . mb_strimwidth($pl['description'], 0, 40, '…') . ' — received ' . (float) $pl['received_qty'] . ', billed ' . (float) $pl['billed_qty'];
            $left = (float) $pl['received_qty'] - (float) $pl['billed_qty'];
            if (!$b && $left > 0) {
                $lines[] = ['description' => $pl['description'], 'account_id' => $pl['account_id'], 'cost_centre_id' => $pl['cost_centre_id'], 'project_id' => $pl['project_id'],
                            'qty' => $left, 'rate' => $pl['rate'], 'tax_code_id' => $pl['tax_code_id'], 'po_line_id' => $pl['id']];
            }
        }
    }
    if ($kind === 'debit_note' && !$b && !empty($_GET['ref']) && ($orig = fp_bill((int) $_GET['ref']))) {
        $v['vendor_id'] = $orig['vendor_id'];
        $v['currency'] = $orig['currency'];
        $v['fx_rate'] = $orig['fx_rate'];
    }
    if ($flash && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $v = $_POST + $v;
        $lines = array_values((array) ($_POST['lines'] ?? []));
    }
    $vendors = fp_vendor_options(false);
    $tds = fp_tax_options(['tds']);
    $tdsDefault = $v['tds_code_id'] ?? ($v['vendor_id'] ? (fp_vendor((int) $v['vendor_id'])['tds_code_id'] ?? '') : '');
    $title = $kind === 'debit_note' ? ($b ? 'Edit debit note' : 'New debit note') : ($b ? 'Edit draft bill' : 'Enter a vendor bill');
    fp_layout_start($title, $po ? 'Against purchase order ' . e($po['number']) . ' — lines must match what was received.' : 'Enter it as the vendor\'s invoice shows it; the figures are checked again when saved.',
                    '', ['Vendor bills' => fp_url('bills'), $b ? $b['number'] : 'New' => null], $flash);
    if ($b && $b['return_note']) {
        echo '<div class="inc-warn"><strong>Returned for correction:</strong> ' . e($b['return_note']) . '</div>';
    }
    $refBills = [];
    if ($kind === 'debit_note' && $v['vendor_id']) {
        foreach (fp_all('SELECT id, number, bill_no FROM fp_bills WHERE vendor_id = ? AND kind = "bill" AND journal_id IS NOT NULL ORDER BY bill_date DESC LIMIT 100', 'i', [(int) $v['vendor_id']]) as $r) {
            $refBills[(int) $r['id']] = $r['number'] . ' (' . $r['bill_no'] . ')';
        }
    }
    echo '<section class="card">' . inc_form_open('data-fp-form data-fp-tds', true) . ($b ? '<input type="hidden" name="version" value="' . (int) $b['version'] . '">' : fp_nonce_field())
       . '<input type="hidden" name="kind" value="' . e($kind) . '">' . ($po ? '<input type="hidden" name="po_id" value="' . (int) $po['id'] . '">' : '')
       . '<div class="inc-row">' . inc_field('Vendor', inc_select('vendor_id', $vendors, $v['vendor_id'], 'Choose…', 'required' . ($po ? ' disabled' : '')) . ($po ? '<input type="hidden" name="vendor_id" value="' . (int) $po['vendor_id'] . '">' : ''), fp_can('vendor.manage') ? '<a href="' . e(fp_url('vendor', ['new' => 1])) . '">Add a vendor</a>' : '', true, 'grow')
       . inc_field($kind === 'debit_note' ? 'Vendor\'s credit note no.' : 'Vendor\'s invoice no.', '<input name="bill_no" maxlength="60" value="' . e($v['bill_no']) . '"' . ($kind === 'bill' ? ' required' : '') . '>', '', $kind === 'bill')
       . ($kind === 'debit_note' ? inc_field('Corrects bill', inc_select('ref_bill_id', $refBills, $v['ref_bill_id'] ?? '', 'Choose…', 'required'), '', true) : '') . '</div>'
       . '<div class="inc-row">' . inc_field('Invoice date', '<input type="date" name="bill_date" value="' . e($v['bill_date']) . '" required>', '', true)
       . inc_field('Due date', '<input type="date" name="due_date" value="' . e($v['due_date'] ?? '') . '">', 'Blank: from the vendor\'s payment terms')
       . inc_field('Posting date', '<input type="date" name="posting_date" value="' . e($v['posting_date'] ?? '') . '">', 'Blank: the invoice date')
       . inc_field('Currency', '<input name="currency" maxlength="3" size="4" value="' . e($v['currency']) . '">')
       . inc_field('Rate', '<input name="fx_rate" size="8" inputmode="decimal" value="' . e((string) (float) $v['fx_rate']) . '">')
       . (count(fp_entity_options()) > 1 ? inc_field('Entity', inc_select('entity_id', fp_entity_options(), $v['entity_id'])) : '<input type="hidden" name="entity_id" value="' . (int) ($v['entity_id'] ?? 1) . '">') . '</div>'
       . fp_lines_editor($lines, 'input', ['po_lines' => $poLines, 'tds' => $kind === 'bill', 'default_cc' => $po['cost_centre_id'] ?? ''])
       . '<div class="inc-row">' . ($kind === 'bill' ? inc_field('TDS to withhold', '<select name="tds_code_id" data-f="tdscode"><option value="" data-rate="0">None</option>'
           . implode('', array_map(static fn($tid, $lab) => '<option value="' . $tid . '" data-rate="' . e((string) (float) fp_tax_code($tid)['rate']) . '"' . ((string) $tdsDefault === (string) $tid ? ' selected' : '') . '>' . e($lab) . '</option>', array_keys($tds), $tds))
           . '</select>', 'Withheld from the vendor and owed to the government; worked out on the amount before GST.') : '')
       . inc_field('Notes', '<input name="description" maxlength="500" value="' . e($v['description'] ?? '') . '">', '', false, 'grow') . '</div>'
       . inc_field('The invoice (PDF or image)', '<input type="file" name="files[]" multiple>', 'Attach the vendor\'s invoice so the approver can see it.')
       . '<div class="inc-formfoot"><button class="btn" name="action" value="save">Save draft</button> <button class="btn btn-primary" name="action" value="save_submit">Save and submit</button> '
       . '<label class="check small"><input type="checkbox" name="confirm_unique" value="1"> This is not a duplicate</label> <a class="btn" href="' . e($back) . '">Cancel</a></div></form></section>';
    if ($b) {
        echo '<div class="inc-row">' . inc_button('Cancel this draft', ['action' => 'cancel', 'version' => $b['version'], 'reason' => 'Draft discarded'], 'btn btn-danger btn-sm', 'Cancel this draft bill?') . '</div>';
    }
    fp_layout_end();
    return;
}

$v = fp_vendor((int) $b['vendor_id']);
$lines = fp_bill_lines($id);
$st = $b['status'];
$acts = '';
$ver = (int) $b['version'];
if (fp_can('ap.prepare') && fp_writable()) {
    if ($st === 'submitted') {
        $acts .= inc_button('Start review', ['action' => 'review', 'version' => $ver], 'btn btn-primary');
    }
    if ($st === 'under_review') {
        $acts .= inc_button('Validate again', ['action' => 'review', 'version' => $ver], 'btn') . ' ';
        if (!in_array($b['match_status'], ['exception'], true) || !fp_can('ap.override')) {
            $acts .= inc_button('Send for approval', ['action' => 'approval', 'version' => $ver], 'btn btn-primary');
        }
    }
    if (in_array($st, ['approved', 'partially_paid'], true) && $b['kind'] === 'bill' && fp_can('pay.prepare')) {
        $acts .= '<a class="btn btn-primary" href="' . e(fp_url('payment', ['new' => 'vendor', 'vendor' => $b['vendor_id'], 'bill' => $id])) . '">Pay this bill</a> ';
    }
    if (in_array($st, ['approved', 'partially_paid', 'paid'], true) && $b['kind'] === 'bill') {
        $acts .= '<a class="btn" href="' . e(fp_url('bill', ['new' => 1, 'kind' => 'debit_note', 'ref' => $id])) . '">Raise debit note</a>';
    }
}
fp_layout_start(($b['kind'] === 'debit_note' ? 'Debit note ' : 'Bill ') . $b['number'], e($v['name']) . ' · invoice ' . e($b['bill_no']), $acts, ['Vendor bills' => fp_url('bills'), $b['number'] => null], $flash);
if ($b['return_note'] && in_array($st, ['rejected'], true)) {
    echo '<div class="inc-warn"><strong>Rejected:</strong> ' . e($b['return_note']) . '</div>';
}
if ($st === 'on_hold') {
    echo '<div class="inc-warn"><strong>On hold:</strong> ' . e($b['hold_reason']) . ' — it will not be approved or paid until released.</div>';
}
echo fp_steps_bar(['draft' => 'Draft', 'submitted' => 'Submitted', 'under_review' => 'Review', 'awaiting_approval' => 'Approval', 'approved' => 'Approved', 'partially_paid' => 'Part paid', 'paid' => 'Paid'], $st);

$open = fp_r2((float) $b['total'] - (float) $b['paid_amount']);
echo '<div class="inc-grid2 wide-left"><section class="card">' . fp_facts([
    'Status' => fp_badge(fp_bill_statuses(), $st), 'Vendor' => '<a href="' . e(fp_url('vendor', ['id' => $v['id']])) . '">' . e($v['name']) . '</a> ' . fp_badge(fp_vendor_statuses(), $v['status']),
    'Invoice date' => e(fp_fmt_date($b['bill_date'])), 'Due' => e(fp_fmt_date($b['due_date'])) . ($b['due_date'] && $b['due_date'] < date('Y-m-d') && $open > 0 && in_array($st, ['approved', 'partially_paid'], true) ? ' ' . inc_badge('Overdue', 'red') : ''),
    'Purchase order' => $b['po_id'] ? '<a href="' . e(fp_url('po', ['id' => $b['po_id']])) . '">' . e(fp_po((int) $b['po_id'])['number']) . '</a>' : '',
    'Corrects' => $b['ref_bill_id'] ? '<a href="' . e(fp_url('bill', ['id' => $b['ref_bill_id']])) . '">' . e(fp_bill((int) $b['ref_bill_id'])['number']) . '</a>' : '',
    'Currency' => $b['currency'] !== fp_setting('base_currency') ? e($b['currency'] . ' at ' . (float) $b['fx_rate']) : '',
    'Ledger' => $b['journal_id'] ? '<a href="' . e(fp_url('journal', ['id' => $b['journal_id']])) . '">Posted journal</a>' : 'Not posted yet',
    'Entered by' => e($b['prepared_by'] . ' · ' . fp_fmt_dt($b['created_at'])), 'Notes' => e($b['description'] ?? ''),
]) . fp_lines_table($lines, true, $b['po_id'] ? ['Order line' => static fn($l) => $l['po_line_id'] ? '#' . (int) (fp_one('SELECT line_no FROM fp_po_lines WHERE id = ?', 'i', [(int) $l['po_line_id']])['line_no'] ?? 0) : '—'] : [])
   . '<div class="fp-totals"><span>Subtotal ' . e(fp_money($b['subtotal'])) . '</span><span>GST ' . e(fp_money($b['tax_total'])) . '</span>'
   . ((float) $b['tds_total'] > 0 ? '<span>TDS withheld −' . e(fp_money($b['tds_total'])) . '</span>' : '') . '<span class="g">Payable ' . e(fp_money($b['total'])) . '</span>'
   . (in_array($st, ['approved', 'partially_paid', 'paid'], true) ? '<span>Paid ' . e(fp_money($b['paid_amount'])) . '</span><span class="g">Open ' . e(fp_money($open)) . '</span>' : '') . '</div></section>';

echo '<div>';
// Validation findings: duplicates, matching, budget.
if ($b['dup_note'] || $b['match_note'] || $b['budget_note']) {
    echo '<section class="card"><h2>Checks</h2>';
    if ($b['dup_note']) {
        echo '<div class="inc-warn small">' . e($b['dup_note']) . '</div>';
    }
    if ($b['match_note']) {
        echo '<p class="small">' . inc_badge(['matched' => 'Three-way match', 'exception' => 'Match exception', 'overridden' => 'Exception accepted', 'na' => 'No order'][$b['match_status']],
                                            ['matched' => 'green', 'exception' => 'red', 'overridden' => 'amber', 'na' => ''][$b['match_status']]) . ' ' . e($b['match_note']) . '</p>';
    }
    if ($b['budget_note']) {
        echo '<p class="small">' . inc_badge('Budget', 'amber') . ' ' . e($b['budget_note']) . '</p>';
    }
    if ($st === 'under_review' && fp_can('ap.override') && fp_writable() && ($b['match_status'] === 'exception' || $b['budget_note'])) {
        echo inc_form_open() . '<input type="hidden" name="action" value="approval"><input type="hidden" name="version" value="' . $ver . '">'
           . inc_field('Accept the exception and send for approval', '<textarea name="override_note" rows="2" required placeholder="Why it is acceptable (kept on record)"></textarea>')
           . '<button class="btn btn-primary">Accept and send for approval</button></form>';
    }
    echo '</section>';
}
if ($st === 'draft' && fp_can('ap.prepare')) {
    echo '<section class="card">' . inc_form_open() . '<input type="hidden" name="action" value="submit"><input type="hidden" name="version" value="' . $ver . '">'
       . ($b['dup_note'] ? '<label class="check"><input type="checkbox" name="confirm_unique" value="1"> This is not a duplicate</label><br>' : '') . '<button class="btn btn-primary">Submit</button></form></section>';
}
if (fp_can('ap.prepare') && fp_writable()) {
    if (in_array($st, array_merge(fp_bill_moves()['hold'][0]), true)) {
        echo '<section class="card"><details><summary class="btn btn-sm">Put on hold</summary>' . inc_form_open() . '<input type="hidden" name="action" value="hold"><input type="hidden" name="version" value="' . $ver . '">'
           . '<input name="reason" maxlength="500" required placeholder="Why (e.g. goods disputed)"><button class="btn btn-sm">Hold</button></form></details>';
        if (in_array($st, fp_bill_moves()['cancel'][0], true)) {
            echo ' <details><summary class="btn btn-sm">Cancel bill</summary>' . inc_form_open('data-confirm="Cancel this bill?"') . '<input type="hidden" name="action" value="cancel"><input type="hidden" name="version" value="' . $ver . '">'
               . '<input name="reason" maxlength="500" required placeholder="Why"><button class="btn btn-sm btn-danger">Cancel bill</button></form></details>';
        }
        echo '</section>';
    }
    if ($st === 'on_hold' && fp_can('ap.override')) {
        echo '<section class="card">' . inc_button('Release hold', ['action' => 'release', 'version' => $ver], 'btn btn-primary') . '</section>';
    }
}
if (in_array($st, ['approved', 'partially_paid'], true) && $b['kind'] === 'bill' && fp_can('pay.prepare') && fp_writable()) {
    echo '<section class="card"><h2>Payment schedule</h2>' . inc_form_open() . '<input type="hidden" name="action" value="schedule"><div class="inc-row">'
       . inc_field('Pay on', '<input type="date" name="scheduled_on" value="' . e($b['scheduled_on'] ?? '') . '">', 'Used by the payment proposal and the cash forecast') . '</div><button class="btn btn-sm">Save</button></form>';
    $pays = fp_all('SELECT p.*, a.amount AS alloc FROM fp_payment_allocs a JOIN fp_payments p ON p.id = a.payment_id WHERE a.doc_type = "bill" AND a.doc_id = ? ORDER BY p.id', 'i', [$id]);
    echo '</section>';
}
$pays = fp_all('SELECT p.*, a.amount AS alloc FROM fp_payment_allocs a JOIN fp_payments p ON p.id = a.payment_id WHERE a.doc_type = "bill" AND a.doc_id = ? ORDER BY p.id', 'i', [$id]);
$notes = fp_all('SELECT a.*, b.number FROM fp_ap_allocs a JOIN fp_bills b ON b.id = a.note_id WHERE a.bill_id = ?', 'i', [$id]);
if ($pays || $notes) {
    echo '<section class="card"><h2>Payments and credits</h2><ul class="fp-files">';
    foreach ($pays as $p) {
        echo '<li><a href="' . e(fp_url('payment', ['id' => $p['id']])) . '">' . e($p['number']) . '</a> ' . fp_badge(fp_payment_statuses(), $p['status']) . ' ' . e(fp_money($p['alloc']))
           . ($p['bank_ref'] ? ' · ref ' . e($p['bank_ref']) : '') . '</li>';
    }
    foreach ($notes as $n) {
        echo '<li>Debit note <a href="' . e(fp_url('bill', ['id' => $n['note_id']])) . '">' . e($n['number']) . '</a> applied ' . e(fp_money($n['amount'])) . '</li>';
    }
    echo '</ul></section>';
}
if ($b['kind'] === 'debit_note' && $b['journal_id'] && (float) $b['total'] - (float) $b['paid_amount'] > 0.004 && fp_can('ap.prepare') && fp_writable()) {
    $openBills = [];
    foreach (fp_bills_payable((int) $b['vendor_id']) as $ob) {
        if ($ob['currency'] === $b['currency']) {
            $openBills[(int) $ob['id']] = $ob['number'] . ' (' . $ob['bill_no'] . ') — open ' . fp_money($ob['open']);
        }
    }
    echo '<section class="card"><h2>Apply to a bill</h2>' . ($openBills ? inc_form_open() . '<input type="hidden" name="action" value="apply_note"><div class="inc-row">'
       . inc_field('Bill', inc_select('bill_id', $openBills, $b['ref_bill_id'] ?? '', 'Choose…', 'required'), '', true)
       . inc_field('Amount', '<input name="amount" inputmode="decimal" value="' . e(fp_amt((float) $b['total'] - (float) $b['paid_amount'])) . '" required>', '', true) . '</div><button class="btn">Apply</button></form>'
       : '<p class="muted small">No open bill from this vendor to apply it to.</p>') . '</section>';
}
echo '</div></div>';

echo fp_wf_panel('bill', $b, $back);
echo fp_files_panel('bill', $b, fp_can('ap.prepare'), $back);
echo fp_audit_panel('bill', $id);
fp_layout_end();


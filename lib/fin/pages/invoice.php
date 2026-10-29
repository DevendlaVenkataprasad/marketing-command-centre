<?php
/**
 * One customer invoice or credit note: drafted, approved and issued; then
 * followed up, disputed, credited and paid. ?print=1 is the printable copy
 * (print or save as PDF from the browser).
 */

$id = (int) ($_GET['id'] ?? 0);
$i = $id ? fp_invoice($id) : null;
if ($id && !$i) {
    fabo_deny('That invoice does not exist.', 404);
}
if ($i && !fp_doc_can_view('invoice', $i)) {
    fabo_deny('You do not have access to customer invoices.', 403);
}
if (!$i && !fp_can('ar.prepare')) {
    fabo_deny('You cannot raise invoices.', 403);
}
$back = $i ? fp_url('invoice', ['id' => $id]) : fp_url('invoices');
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    if ($i && ($x = fp_wf_post('invoice', $id, $back))) {
        $flash = $x;
    } elseif ($i && ($x = fp_files_post('invoice', $id, $back, fp_can('ar.prepare')))) {
        $flash = $x;
    } elseif ($a === 'save' || $a === 'save_submit') {
        $flash = fp_action(static function () use ($i, $a) {
            if (!$i) {
                fp_nonce_check();
            }
            $nid = fp_invoice_save($i ? (int) $i['id'] : null, $_POST);
            if ($a === 'save_submit') {
                fp_submit_or_draft(static fn() => fp_invoice_submit($nid, fp_invoice($nid)['version'], (string) ($_POST['override'] ?? '')), fp_url('invoice', ['id' => $nid]));
            }
            inc_redirect(fp_url('invoice', ['id' => $nid]), ['ok', 'Draft saved.']);
        }, $back);
    } elseif ($i && $a === 'submit') {
        $flash = fp_action(static fn() => fp_invoice_submit($id, $_POST['version'] ?? 0, (string) ($_POST['override'] ?? '')), $back);
    } elseif ($i && $a === 'cancel') {
        $flash = fp_action(static function () use ($id) {
            fp_invoice_cancel($id, (string) ($_POST['reason'] ?? ''), $_POST['version'] ?? 0);
            return 'Cancelled.';
        }, $back);
    } elseif ($i && ($a === 'dispute' || $a === 'resolve')) {
        $flash = fp_action(static function () use ($id, $a) {
            fp_invoice_dispute($id, $a === 'dispute', (string) ($_POST['note'] ?? ''));
            return $a === 'dispute' ? 'Marked disputed; automatic reminders stop.' : 'Dispute settled.';
        }, $back);
    } elseif ($i && $a === 'remind') {
        $flash = fp_action(static fn() => fp_ar_remind($id), $back);
    } elseif ($i && $a === 'followup') {
        $flash = fp_action(static function () use ($i, $id) {
            fp_followup_add((int) $i['customer_id'], $id, (string) ($_POST['note'] ?? ''), $_POST['next_date'] ?? null);
            return 'Note added.';
        }, $back);
    } elseif ($i && $a === 'apply_note') {
        $flash = fp_action(static fn() => fp_ar_note_apply($id, (int) ($_POST['invoice_id'] ?? 0), $_POST['amount'] ?? ''), $back);
    }
}

$editable = fp_can('ar.prepare') && (!$i || $i['status'] === 'draft');
if ($editable && (!$i || isset($_GET['edit']) || $flash)) {
    $kind = $i['kind'] ?? (($_GET['kind'] ?? '') === 'credit_note' ? 'credit_note' : 'invoice');
    $v = $i ?? ['customer_id' => $_GET['customer'] ?? '', 'invoice_date' => date('Y-m-d'), 'currency' => fp_setting('base_currency'), 'fx_rate' => 1, 'entity_id' => 1, 'ref_invoice_id' => $_GET['ref'] ?? ''];
    $lines = $i ? fp_invoice_lines($id) : [];
    if (!$i && $kind === 'credit_note' && !empty($_GET['ref']) && ($o = fp_invoice((int) $_GET['ref']))) {
        $v['customer_id'] = $o['customer_id'];
        $v['currency'] = $o['currency'];
        $v['fx_rate'] = $o['fx_rate'];
    }
    if ($flash && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $v = $_POST + $v;
        $lines = array_values((array) ($_POST['lines'] ?? []));
    }
    $refs = [];
    if ($kind === 'credit_note' && $v['customer_id']) {
        foreach (fp_all('SELECT id, number FROM fp_invoices WHERE customer_id = ? AND kind = "invoice" AND journal_id IS NOT NULL ORDER BY invoice_date DESC LIMIT 100', 'i', [(int) $v['customer_id']]) as $r) {
            $refs[(int) $r['id']] = $r['number'];
        }
    }
    fp_layout_start($kind === 'credit_note' ? ($i ? 'Edit credit note' : 'New credit note') : ($i ? 'Edit draft invoice' : 'New customer invoice'), 'Approved before it is issued; once issued it is changed only by a credit note.', '',
                    ['Customer invoices' => fp_url('invoices'), $i ? $i['number'] : 'New' => null], $flash);
    if ($i && $i['return_note']) {
        echo '<div class="inc-warn"><strong>Returned for correction:</strong> ' . e($i['return_note']) . '</div>';
    }
    echo '<section class="card">' . inc_form_open('data-fp-form', true) . ($i ? '<input type="hidden" name="version" value="' . (int) $i['version'] . '">' : fp_nonce_field())
       . '<input type="hidden" name="kind" value="' . e($kind) . '"><div class="inc-row">'
       . inc_field('Customer', inc_select('customer_id', fp_customer_options(false), $v['customer_id'], 'Choose…', 'required'), fp_can('customer.manage') ? '<a href="' . e(fp_url('customer', ['new' => 1])) . '">Add a customer</a>' : '', true, 'grow')
       . ($kind === 'credit_note' ? inc_field('Corrects invoice', inc_select('ref_invoice_id', $refs, $v['ref_invoice_id'] ?? '', 'Choose…', 'required'), '', true) : '')
       . inc_field('Date', '<input type="date" name="invoice_date" value="' . e($v['invoice_date']) . '" required>', '', true)
       . inc_field('Due', '<input type="date" name="due_date" value="' . e($v['due_date'] ?? '') . '">', 'Blank: the customer\'s terms') . '</div><div class="inc-row">'
       . inc_field('Customer PO', '<input name="customer_po" maxlength="60" value="' . e($v['customer_po'] ?? '') . '">')
       . inc_field('Place of supply', '<input name="place_of_supply" maxlength="60" value="' . e($v['place_of_supply'] ?? '') . '">', 'Same state: GST; other state: IGST')
       . inc_field('Currency', '<input name="currency" maxlength="3" size="4" value="' . e($v['currency']) . '">') . inc_field('Rate', '<input name="fx_rate" size="8" value="' . e((string) (float) $v['fx_rate']) . '">')
       . (count(fp_entity_options()) > 1 ? inc_field('Entity', inc_select('entity_id', fp_entity_options(), $v['entity_id'])) : '<input type="hidden" name="entity_id" value="' . (int) ($v['entity_id'] ?? 1) . '">') . '</div>'
       . fp_lines_editor($lines, 'output', ['default_account' => fp_acct('revenue')])
       . inc_field('Notes on the invoice', '<textarea name="notes" rows="2" maxlength="1000">' . e($v['notes'] ?? '') . '</textarea>')
       . inc_field('Attachments', '<input type="file" name="files[]" multiple>')
       . '<div class="inc-formfoot"><button class="btn" name="action" value="save">Save draft</button> <button class="btn btn-primary" name="action" value="save_submit">Submit for approval</button> <a class="btn" href="' . e($back) . '">Cancel</a></div></form></section>';
    fp_layout_end();
    return;
}
if (!$i) {
    fabo_deny('You cannot raise invoices.', 403);
}

$c = fp_customer((int) $i['customer_id']);
$lines = fp_invoice_lines($id);
$ent = fp_one('SELECT * FROM fp_entities WHERE id = ?', 'i', [(int) $i['entity_id']]);
if (!empty($_GET['print'])) {
    // The printable copy: the customer's invoice as issued.
    fabo_head(fp_viewer(), $i['number'], 'fin-invoice');
    echo '<link rel="stylesheet" href="' . e(fabo_asset(fabo_root_prefix() . 'assets/fin.css')) . '"><main class="fp-print"><div class="fp-print-acts"><button class="btn btn-primary" onclick="window.print()">Print / save as PDF</button> <a class="btn" href="' . e($back) . '">Back</a></div>'
       . '<header><div><h1>' . e($ent['name']) . '</h1><div class="small">' . e($ent['address'] ?? '') . ($ent['gstin'] ? '<br>GSTIN ' . e($ent['gstin']) : '') . '</div></div>'
       . '<div class="r"><h2>' . ($i['kind'] === 'credit_note' ? 'Credit note' : 'Tax invoice') . '</h2><div>' . e($i['number']) . '</div><div class="small">Date ' . e(fp_fmt_date($i['invoice_date'])) . ($i['due_date'] ? '<br>Due ' . e(fp_fmt_date($i['due_date'])) : '') . '</div></div></header>'
       . '<section class="small"><strong>Bill to</strong><br>' . e($c['legal_name'] ?: $c['name']) . '<br>' . nl2br(e($c['address'] ?? '')) . ($c['gstin'] ? '<br>GSTIN ' . e($c['gstin']) : '') . ($i['place_of_supply'] ? '<br>Place of supply: ' . e($i['place_of_supply']) : '') . ($i['customer_po'] ? '<br>Your PO: ' . e($i['customer_po']) : '') . '</section>'
       . '<table><thead><tr><th>Description</th><th>HSN/SAC</th><th class="num">Qty</th><th class="num">Rate</th><th class="num">Taxable</th><th>GST</th><th class="num">Tax</th></tr></thead><tbody>';
    foreach ($lines as $l) {
        echo '<tr><td>' . e($l['description']) . '</td><td>' . e($l['hsn'] ?? '') . '</td><td class="num">' . e(rtrim(rtrim((string) $l['qty'], '0'), '.')) . '</td><td class="num">' . e(fp_amt($l['rate'])) . '</td>'
           . '<td class="num">' . e(fp_amt($l['amount'])) . '</td><td>' . e(fp_tax_code($l['tax_code_id'] ? (int) $l['tax_code_id'] : null)['code'] ?? '') . '</td><td class="num">' . e(fp_amt($l['tax_amount'])) . '</td></tr>';
    }
    echo '</tbody></table><div class="fp-totals"><span>Taxable value ' . e(fp_money($i['subtotal'])) . '</span><span>GST ' . e(fp_money($i['tax_total'])) . '</span><span class="g">Total ' . e($i['currency'] . ' ' . fp_amt($i['total'])) . '</span></div>'
       . ($i['notes'] ? '<p class="small">' . nl2br(e($i['notes'])) . '</p>' : '') . '</main>';
    fabo_foot();
    return;
}

$st = $i['status'];
$openAmt = fp_r2((float) $i['total'] - (float) $i['received_amount']);
$acts = '';
if ($st === 'draft' && fp_can('ar.prepare')) {
    $acts .= '<a class="btn" href="' . e(fp_url('invoice', ['id' => $id, 'edit' => 1])) . '">Edit</a> ' . inc_button('Submit for approval', ['action' => 'submit', 'version' => $i['version']], 'btn btn-primary') . ' ';
}
if ($i['journal_id']) {
    $acts .= '<a class="btn" href="' . e(fp_url('invoice', ['id' => $id, 'print' => 1])) . '">Print / PDF</a> ';
}
if (in_array($st, ['issued', 'partially_paid'], true) && $i['kind'] === 'invoice' && fp_can('ar.receipt')) {
    $acts .= '<a class="btn btn-primary" href="' . e(fp_url('receipt', ['new' => 1, 'customer' => $i['customer_id'], 'invoice' => $id])) . '">Record payment</a> ';
}
if ($i['journal_id'] && $i['kind'] === 'invoice' && fp_can('ar.prepare')) {
    $acts .= '<a class="btn" href="' . e(fp_url('invoice', ['new' => 1, 'kind' => 'credit_note', 'ref' => $id])) . '">Credit note</a>';
}
fp_layout_start(($i['kind'] === 'credit_note' ? 'Credit note ' : 'Invoice ') . $i['number'], e($c['name']), $acts, ['Customer invoices' => fp_url('invoices'), $i['number'] => null], $flash);
if ($i['disputed']) {
    echo '<div class="inc-warn"><strong>Disputed:</strong> ' . e($i['dispute_note']) . ' — automatic reminders are paused.</div>';
}
if ($st === 'rejected' && $i['return_note']) {
    echo '<div class="inc-warn"><strong>Rejected:</strong> ' . e($i['return_note']) . '</div>';
}
echo fp_steps_bar(['draft' => 'Draft', 'submitted' => 'Approval', 'issued' => 'Issued', 'partially_paid' => 'Part paid', 'paid' => 'Paid'], $st);
[$over, $creditMsg] = $st === 'draft' ? fp_credit_check($i) : [false, ''];
if ($over) {
    echo '<div class="inc-warn">' . e($creditMsg) . '</div>';
}
echo '<div class="inc-grid2 wide-left"><section class="card">' . fp_facts([
    'Status' => fp_badge(fp_invoice_statuses(), $st) . ($st !== 'paid' && $i['due_date'] && $i['due_date'] < date('Y-m-d') && in_array($st, ['issued', 'partially_paid'], true) ? ' ' . inc_badge('Overdue', 'red') : ''),
    'Customer' => '<a href="' . e(fp_url('customer', ['id' => $c['id']])) . '">' . e($c['name']) . '</a>', 'Date' => e(fp_fmt_date($i['invoice_date'])), 'Due' => e(fp_fmt_date($i['due_date'])),
    'Corrects' => $i['ref_invoice_id'] ? '<a href="' . e(fp_url('invoice', ['id' => $i['ref_invoice_id']])) . '">' . e(fp_invoice((int) $i['ref_invoice_id'])['number']) . '</a>' : '',
    'Customer PO' => e($i['customer_po'] ?? ''), 'Place of supply' => e($i['place_of_supply'] ?? ''),
    'Ledger' => $i['journal_id'] ? '<a href="' . e(fp_url('journal', ['id' => $i['journal_id']])) . '">Posted journal</a>' : 'Not issued yet',
    'Brought in from' => $i['legacy_invoice_id'] ? 'Portal billing' : '', 'Reminders sent' => (int) $i['reminders'] ? (int) $i['reminders'] . ' (last ' . e(fp_fmt_dt($i['last_reminder_at'])) . ')' : '',
]) . fp_lines_table($lines) . '<div class="fp-totals"><span>Taxable ' . e(fp_money($i['subtotal'])) . '</span><span>GST ' . e(fp_money($i['tax_total'])) . '</span><span class="g">Total ' . e(fp_money($i['total'])) . '</span>'
   . ($i['journal_id'] ? '<span>' . ($i['kind'] === 'invoice' ? 'Received' : 'Applied') . ' ' . e(fp_money($i['received_amount'])) . '</span><span class="g">Open ' . e(fp_money($openAmt)) . '</span>' : '') . '</div></section><div>';

$allocs = fp_all('SELECT a.*, r.number AS rnum, n.number AS nnum FROM fp_ar_allocs a LEFT JOIN fp_receipts r ON r.id = a.receipt_id LEFT JOIN fp_invoices n ON n.id = a.note_id WHERE a.invoice_id = ? ORDER BY a.id', 'i', [$id]);
if ($allocs) {
    echo '<section class="card"><h2>Payments and credits</h2><ul class="fp-files">';
    foreach ($allocs as $a) {
        echo '<li>' . ($a['receipt_id'] ? 'Receipt <a href="' . e(fp_url('receipt', ['id' => $a['receipt_id']])) . '">' . e($a['rnum']) . '</a>' : 'Credit note <a href="' . e(fp_url('invoice', ['id' => $a['note_id']])) . '">' . e($a['nnum']) . '</a>')
           . ' · ' . e(fp_money($a['amount'])) . ' · ' . e(fp_fmt_dt($a['created_at'])) . '</li>';
    }
    echo '</ul></section>';
}
if (in_array($st, ['issued', 'partially_paid'], true) && $i['kind'] === 'invoice' && fp_writable()) {
    echo '<section class="card"><h2>Collection</h2>';
    if (fp_can('ar.receipt')) {
        echo inc_button('Email a payment reminder', ['action' => 'remind'], 'btn btn-sm', 'Email ' . ($c['email'] ?: 'the customer') . ' a reminder now?')
           . inc_form_open() . '<input type="hidden" name="action" value="followup"><div class="inc-row">' . inc_field('Follow-up note', '<input name="note" maxlength="1000" required placeholder="e.g. Spoke to accounts; payment on Friday">', '', false, 'grow')
           . inc_field('Next follow-up', '<input type="date" name="next_date">') . '</div><button class="btn btn-sm">Add note</button></form>';
    }
    if (fp_can('ar.prepare')) {
        echo '<details><summary class="btn btn-sm">' . ($i['disputed'] ? 'Settle the dispute' : 'Mark disputed') . '</summary>' . inc_form_open() . '<input type="hidden" name="action" value="' . ($i['disputed'] ? 'resolve' : 'dispute') . '">'
           . '<textarea name="note" rows="2" maxlength="500" required placeholder="' . ($i['disputed'] ? 'How it was settled' : 'What the customer disputes') . '"></textarea><button class="btn btn-sm">Save</button></form></details>';
    }
    $fu = fp_all('SELECT * FROM fp_followups WHERE invoice_id = ? ORDER BY id DESC LIMIT 20', 'i', [$id]);
    if ($fu) {
        echo '<ol class="inc-timeline">';
        foreach ($fu as $x) {
            echo '<li><span class="k">' . e($x['kind']) . '</span><div>' . e($x['note']) . ($x['next_date'] ? ' <span class="muted small">next: ' . e(fp_fmt_date($x['next_date'])) . '</span>' : '') . '<div class="muted small">' . e($x['actor'] . ' · ' . fp_fmt_dt($x['created_at'])) . '</div></div></li>';
        }
        echo '</ol>';
    }
    echo '</section>';
}
if ($i['kind'] === 'credit_note' && $i['journal_id'] && $openAmt > 0.004 && fp_can('ar.prepare') && fp_writable()) {
    $opts = [];
    foreach (fp_ar_open((int) $i['customer_id']) as $o) {
        if ($o['currency'] === $i['currency']) {
            $opts[(int) $o['id']] = $o['number'] . ' — open ' . fp_money($o['open']);
        }
    }
    echo '<section class="card"><h2>Apply to an invoice</h2>' . ($opts ? inc_form_open() . '<input type="hidden" name="action" value="apply_note"><div class="inc-row">'
       . inc_field('Invoice', inc_select('invoice_id', $opts, $i['ref_invoice_id'] ?? '', 'Choose…', 'required'), '', true) . inc_field('Amount', '<input name="amount" inputmode="decimal" value="' . e(fp_amt($openAmt)) . '" required>', '', true)
       . '</div><button class="btn">Apply</button></form>' : '<p class="muted small">This customer has no open invoice to apply it to; it stays as their credit.</p>') . '</section>';
}
if (in_array($st, ['draft', 'submitted', 'rejected'], true) && fp_can('ar.prepare') && fp_writable()) {
    echo '<section class="card"><details><summary class="btn btn-sm">Cancel</summary>' . inc_form_open('data-confirm="Cancel this invoice?"') . '<input type="hidden" name="action" value="cancel"><input type="hidden" name="version" value="' . (int) $i['version'] . '">'
       . '<input name="reason" maxlength="500" required placeholder="Why"><button class="btn btn-sm btn-danger">Cancel invoice</button></form></details></section>';
}
echo '</div></div>';
echo fp_wf_panel('invoice', $i, $back);
echo fp_files_panel('invoice', $i, fp_can('ar.prepare'), $back);
echo fp_audit_panel('invoice', $id);
fp_layout_end();

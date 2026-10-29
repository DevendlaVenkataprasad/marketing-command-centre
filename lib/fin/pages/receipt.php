<?php
/** Record money received (allocated to invoices now or later), or look at one receipt. */

$id = (int) ($_GET['id'] ?? 0);
$r = $id ? fp_receipt($id) : null;
if ($id && !$r) {
    fabo_deny('That receipt does not exist.', 404);
}
$back = $r ? fp_url('receipt', ['id' => $id]) : fp_url('receipts');
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    if ($r && ($x = fp_files_post('receipt', $id, $back, fp_can('ar.receipt')))) {
        $flash = $x;
    } elseif (!$r && $a === 'create') {
        $flash = fp_action(static function () {
            fp_nonce_check();
            $nid = fp_receipt_create($_POST);
            inc_redirect(fp_url('receipt', ['id' => $nid]), ['ok', 'Receipt recorded and posted.']);
        }, $back);
    } elseif ($r && $a === 'allocate') {
        $flash = fp_action(static fn() => fp_receipt_allocate($id, (array) ($_POST['alloc'] ?? [])), $back);
    } elseif ($r && $a === 'cancel') {
        $flash = fp_action(static fn() => fp_receipt_cancel($id, (string) ($_POST['reason'] ?? '')), $back);
    }
}

/** The allocation grid: each open invoice of the customer, with a box for how much this receipt pays. */
$allocGrid = static function (int $cust, string $currency, array $pre = []): string {
    $open = array_values(array_filter(fp_ar_open($cust), static fn($i) => $i['currency'] === $currency));
    if (!$open) {
        return '<p class="muted small">This customer has no open invoices in ' . e($currency) . '; the whole amount stays unallocated as their credit.</p>';
    }
    $h = '<table class="data inc-table stack" data-alloc><thead><tr><th>Invoice</th><th>Due</th><th class="num">Open</th><th class="num">Allocate</th></tr></thead><tbody>';
    foreach ($open as $i) {
        $h .= '<tr><td data-l="Invoice">' . e($i['number']) . ($i['disputed'] ? ' ' . inc_badge('Disputed', 'amber') : '') . '</td><td data-l="Due" class="' . ($i['due_date'] < date('Y-m-d') ? 'late' : '') . '">' . e(fp_fmt_date($i['due_date'])) . '</td>'
            . fp_money_td($i['open'], 'Open') . '<td class="num" data-l="Allocate"><input name="alloc[' . (int) $i['id'] . ']" size="10" inputmode="decimal" data-open="' . e(fp_amt($i['open'])) . '" value="' . e((string) ($pre[(int) $i['id']] ?? '')) . '"></td></tr>';
    }
    return $h . '</tbody></table>';
};

if (!$r) {
    fp_need('ar.receipt');
    $cust = (int) ($_POST['customer_id'] ?? ($_GET['customer'] ?? 0));
    $c = $cust ? fp_customer($cust) : null;
    fp_layout_start('Record a receipt', 'Posted to the bank at once. Allocate it to invoices now, or later from the receipt.', '', ['Receipts' => fp_url('receipts'), 'New' => null], $flash);
    echo '<section class="card"><form method="get" class="inc-row"><input type="hidden" name="p" value="receipt"><input type="hidden" name="new" value="1">'
       . inc_field('Customer', inc_select('customer', fp_customer_options(false), $cust, 'Choose…', 'data-autosubmit'), '', true, 'grow') . '<noscript><button class="btn">Go</button></noscript></form>';
    if ($c) {
        $bank = (int) ($_POST['bank_account_id'] ?? (array_key_first(fp_bank_options()) ?? 0));
        $pre = [];
        if (!empty($_GET['invoice']) && ($inv = fp_invoice((int) $_GET['invoice']))) {
            $pre[(int) $inv['id']] = fp_amt((float) $inv['total'] - (float) $inv['received_amount']);
        }
        echo inc_form_open('data-fp-receipt', true) . fp_nonce_field() . '<input type="hidden" name="action" value="create"><input type="hidden" name="customer_id" value="' . (int) $c['id'] . '">'
           . '<div class="inc-row">' . inc_field('Received on', '<input type="date" name="receipt_date" value="' . e($_POST['receipt_date'] ?? date('Y-m-d')) . '" required>', '', true)
           . inc_field('Into', inc_select('bank_account_id', fp_bank_options(), $bank, '', 'required'), '', true)
           . inc_field('Amount received', '<input name="amount" inputmode="decimal" required value="' . e($_POST['amount'] ?? (count($pre) ? reset($pre) : '')) . '" data-f="rcpt">', '', true)
           . inc_field('TDS deducted by customer', '<input name="tds_amount" inputmode="decimal" value="' . e($_POST['tds_amount'] ?? '') . '">', 'Counts towards the invoice; claimed back as TDS receivable')
           . inc_field('Method', inc_select('method', ['neft' => 'NEFT', 'rtgs' => 'RTGS', 'imps' => 'IMPS', 'upi' => 'UPI', 'cheque' => 'Cheque', 'cash' => 'Cash', 'card' => 'Card', 'other' => 'Other'], $_POST['method'] ?? 'neft'))
           . inc_field('Reference (UTR / cheque no.)', '<input name="reference" maxlength="120" value="' . e($_POST['reference'] ?? '') . '">') . '</div>'
           . '<h3 class="fp-h3">Allocate to invoices</h3>' . $allocGrid((int) $c['id'], fp_bank_account($bank)['currency'] ?? fp_setting('base_currency'), $pre)
           . inc_field('Notes', '<input name="notes" maxlength="500" value="' . e($_POST['notes'] ?? '') . '">') . inc_field('Remittance advice', '<input type="file" name="files[]" multiple>')
           . '<div class="inc-formfoot"><button class="btn btn-primary">Record and post</button></div></form>';
    }
    echo '</section>';
    fp_layout_end();
    return;
}

$c = fp_customer((int) $r['customer_id']);
$un = fp_receipt_unallocated($r);
fp_layout_start('Receipt ' . $r['number'], e($c['name']) . ' · ' . e(fp_money($r['amount'])), '', ['Receipts' => fp_url('receipts'), $r['number'] => null], $flash);
echo '<section class="card">' . fp_facts([
    'Status' => inc_badge(ucfirst($r['status']), $r['status'] === 'posted' ? 'green' : ''), 'Customer' => '<a href="' . e(fp_url('customer', ['id' => $c['id']])) . '">' . e($c['name']) . '</a>',
    'Received on' => e(fp_fmt_date($r['receipt_date'])), 'Into' => e(fp_bank_account((int) $r['bank_account_id'])['name']), 'Amount' => e(fp_money($r['amount'])),
    'TDS deducted' => (float) $r['tds_amount'] > 0 ? e(fp_money($r['tds_amount'])) : '', 'Method' => e(strtoupper($r['method'])), 'Reference' => e($r['reference'] ?? ''),
    'Unallocated' => $r['status'] === 'posted' ? e(fp_money($un)) : '', 'Ledger' => $r['journal_id'] ? '<a href="' . e(fp_url('journal', ['id' => $r['journal_id']])) . '">Posted journal</a>' : '',
    'Recorded by' => e($r['prepared_by'] . ' · ' . fp_fmt_dt($r['created_at'])), 'Notes' => e($r['notes'] ?? ''),
]) . '</section>';
$allocs = fp_all('SELECT a.*, i.number FROM fp_ar_allocs a JOIN fp_invoices i ON i.id = a.invoice_id WHERE a.receipt_id = ?', 'i', [$id]);
echo '<section class="card"><h2>Allocated to</h2>' . ($allocs ? '<ul class="fp-files">' . implode('', array_map(static fn($a) => '<li><a href="' . e(fp_url('invoice', ['id' => $a['invoice_id']])) . '">' . e($a['number']) . '</a> · ' . e(fp_money($a['amount'])) . ' · ' . e($a['created_by']) . '</li>', $allocs)) . '</ul>' : '<p class="muted small">Not allocated yet.</p>');
if ($r['status'] === 'posted' && $un > 0.004 && fp_can('ar.receipt') && fp_writable()) {
    echo inc_form_open() . '<input type="hidden" name="action" value="allocate"><h3 class="fp-h3">Allocate the remaining ' . e(fp_money($un)) . '</h3>' . $allocGrid((int) $c['id'], (string) $r['currency']) . '<button class="btn btn-primary">Allocate</button></form>';
}
echo '</section>';
if ($r['status'] === 'posted' && fp_can('ar.receipt') && fp_writable()) {
    echo '<section class="card"><details><summary class="btn btn-sm">Cancel this receipt</summary><p class="muted small">Only for a receipt recorded in error. Its posting is reversed and its allocations undone.</p>'
       . inc_form_open('data-confirm="Cancel this receipt and reverse its posting?"') . '<input type="hidden" name="action" value="cancel"><input name="reason" maxlength="500" required placeholder="Why"><button class="btn btn-sm btn-danger">Cancel receipt</button></form></details></section>';
}
echo fp_files_panel('receipt', $r, fp_can('ar.receipt'), $back);
echo fp_audit_panel('receipt', $id);
fp_layout_end();

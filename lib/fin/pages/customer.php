<?php
/** One customer: details, what they owe and how old it is, their statement, collection history. */

$id = (int) ($_GET['id'] ?? 0);
$c = $id ? fp_customer($id) : null;
if ($id && !$c) {
    fabo_deny('That customer does not exist.', 404);
}
$back = $c ? fp_url('customer', ['id' => $id]) : fp_url('customers');
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    if ($a === 'save') {
        $flash = fp_action(static function () use ($c) {
            if (!$c) {
                fp_nonce_check();
            }
            $nid = fp_customer_save($c ? (int) $c['id'] : null, $_POST);
            inc_redirect(fp_url('customer', ['id' => $nid]), ['ok', 'Customer saved.']);
        }, $back);
    } elseif ($c && $a === 'followup') {
        $flash = fp_action(static function () use ($id) {
            fp_followup_add($id, null, (string) ($_POST['note'] ?? ''), $_POST['next_date'] ?? null);
            return 'Note added.';
        }, $back);
    }
}
if (fp_can('customer.manage') && fp_writable() && (!$c || isset($_GET['edit']) || ($flash && ($_POST['action'] ?? '') === 'save'))) {
    $x = $c ?? ['payment_terms_days' => 30, 'status' => 'active'];
    if ($flash) {
        $x = $_POST + $x;
    }
    $in = static fn(string $n, int $max, string $extra = '') => '<input name="' . $n . '" maxlength="' . $max . '" value="' . e((string) ($x[$n] ?? '')) . '"' . $extra . '>';
    fp_layout_start($c ? 'Edit ' . $c['name'] : 'Add a customer', '', '', ['Customers' => fp_url('customers'), $c ? $c['code'] : 'New' => null], $flash);
    echo '<section class="card">' . inc_form_open() . '<input type="hidden" name="action" value="save">' . ($c ? '<input type="hidden" name="version" value="' . (int) $c['version'] . '">' : fp_nonce_field())
       . '<div class="inc-row">' . inc_field('Name', $in('name', 190, ' required'), '', true, 'grow') . inc_field('Legal name', $in('legal_name', 190), '', false, 'grow') . '</div>'
       . '<div class="inc-row">' . inc_field('GSTIN', $in('gstin', 20)) . inc_field('PAN', $in('pan', 12)) . inc_field('State', $in('state', 60))
       . inc_field('Payment terms (days)', '<input type="number" name="payment_terms_days" min="0" max="365" value="' . (int) $x['payment_terms_days'] . '">')
       . inc_field('Credit limit', $in('credit_limit', 20, ' inputmode="decimal"'), 'Blank: no limit') . inc_field('Status', inc_select('status', ['active' => 'Active', 'blocked' => 'Blocked'], $x['status'])) . '</div>'
       . '<div class="inc-row">' . inc_field('Email (for invoices and reminders)', $in('email', 190, ' type="email"')) . inc_field('Phone', $in('phone', 40)) . inc_field('Address', $in('address', 500), '', false, 'grow') . '</div>'
       . inc_field('Notes', $in('notes', 500)) . '<div class="inc-formfoot"><button class="btn btn-primary">Save</button> <a class="btn" href="' . e($back) . '">Cancel</a></div></form></section>';
    fp_layout_end();
    return;
}
if (!$c) {
    fabo_deny('You cannot add customers.', 403);
}
$bal = fp_customer_balance($id);
$from = fp_safe_date($_GET['from'] ?? '') ?? date('Y-m-d', strtotime('-12 month'));
$stm = fp_customer_statement($id, $from);
$acts = (fp_can('customer.manage') ? '<a class="btn" href="' . e(fp_url('customer', ['id' => $id, 'edit' => 1])) . '">Edit</a> ' : '')
      . (fp_can('ar.prepare') ? '<a class="btn" href="' . e(fp_url('invoice', ['new' => 1, 'customer' => $id])) . '">+ Invoice</a> ' : '')
      . (fp_can('ar.receipt') ? '<a class="btn btn-primary" href="' . e(fp_url('receipt', ['new' => 1, 'customer' => $id])) . '">Record payment</a>' : '');
fp_layout_start($c['name'], e($c['code']) . ($c['status'] === 'blocked' ? ' · blocked' : ''), $acts, ['Customers' => fp_url('customers'), $c['code'] => null], $flash);
echo '<div class="inc-kpis">' . fp_kpi('Owes', fp_money($bal['open'], false), fp_url('invoices', ['customer' => $id, 'status' => 'open']))
   . fp_kpi('Overdue', fp_money($bal['overdue'], false), fp_url('invoices', ['customer' => $id, 'status' => 'overdue']), '', $bal['overdue'] > 0 ? 'red' : '')
   . fp_kpi('Unallocated payments', fp_money($bal['unallocated'], false), fp_url('receipts', ['customer' => $id]), 'Received, not yet matched to invoices')
   . fp_kpi('Credit limit', $c['credit_limit'] !== null ? fp_money($c['credit_limit'], false) : 'None', '', $c['credit_limit'] !== null ? 'Exposure ' . fp_money($bal['balance'], false) : '',
            $c['credit_limit'] !== null && $bal['balance'] > (float) $c['credit_limit'] ? 'red' : '') . '</div>';
echo '<div class="inc-grid2"><section class="card"><h2>Details</h2>' . fp_facts([
    'Legal name' => e($c['legal_name'] ?? ''), 'GSTIN' => e($c['gstin'] ?? ''), 'PAN' => e($c['pan'] ?? ''), 'State' => e($c['state'] ?? ''), 'Terms' => (int) $c['payment_terms_days'] . ' days',
    'Email' => $c['email'] ? '<a href="mailto:' . e($c['email']) . '">' . e($c['email']) . '</a>' : '<span class="muted">none — reminders cannot be emailed</span>', 'Phone' => e($c['phone'] ?? ''), 'Address' => e($c['address'] ?? ''),
    'Portal client' => $c['client_id'] ? 'Linked to the portal client it is billed as' : '', 'Notes' => e($c['notes'] ?? ''),
]) . '</section><section class="card"><h2>Collection history</h2>';
if (fp_can('ar.receipt') && fp_writable()) {
    echo inc_form_open() . '<input type="hidden" name="action" value="followup"><div class="inc-row">' . inc_field('Note', '<input name="note" maxlength="1000" required>', '', false, 'grow')
       . inc_field('Next follow-up', '<input type="date" name="next_date">') . '</div><button class="btn btn-sm">Add</button></form>';
}
$fu = fp_all('SELECT f.*, i.number FROM fp_followups f LEFT JOIN fp_invoices i ON i.id = f.invoice_id WHERE f.customer_id = ? ORDER BY f.id DESC LIMIT 30', 'i', [$id]);
echo $fu ? '<ol class="inc-timeline">' . implode('', array_map(static fn($x) => '<li><span class="k">' . e($x['kind']) . '</span><div>' . ($x['number'] ? e($x['number']) . ': ' : '') . e($x['note'])
     . ($x['next_date'] ? ' <span class="muted small">next ' . e(fp_fmt_date($x['next_date'])) . '</span>' : '') . '<div class="muted small">' . e($x['actor'] . ' · ' . fp_fmt_dt($x['created_at'])) . '</div></div></li>', $fu)) . '</ol>'
     : '<p class="muted small">No follow-ups yet.</p>';
echo '</section></div>';
echo '<section class="card"><div class="inc-cardhead"><h2>Statement of account</h2><form method="get" class="inc-inline"><input type="hidden" name="p" value="customer"><input type="hidden" name="id" value="' . $id . '">'
   . '<label class="small">From <input type="date" name="from" value="' . e($from) . '" data-autosubmit></label></form></div>'
   . '<div class="scrollx"><table class="data inc-table stack"><thead><tr><th>Date</th><th>Entry</th><th class="num">Invoiced</th><th class="num">Received / credited</th><th class="num">Balance</th></tr></thead><tbody>'
   . '<tr class="fp-sub"><td colspan="4">Opening balance</td>' . fp_money_td($stm['opening']) . '</tr>';
foreach ($stm['rows'] as $r) {
    $page = ['invoice' => 'invoice', 'receipt' => 'receipt'][$r['source']] ?? null;
    echo '<tr><td data-l="Date">' . e(fp_fmt_date($r['jdate'])) . '</td><td data-l="Entry">' . ($page ? '<a href="' . e(fp_url($page, ['id' => $r['source_id']])) . '">' . e($r['description']) . '</a>' : e($r['description'])) . '</td>'
       . fp_money_td((float) $r['debit_base'] ?: null, 'Invoiced') . fp_money_td((float) $r['credit_base'] ?: null, 'Received') . fp_money_td($r['balance'], 'Balance') . '</tr>';
}
echo '<tr class="fp-total"><td colspan="4">Closing balance</td>' . fp_money_td($stm['closing']) . '</tr></tbody></table></div></section>';
echo fp_audit_panel('customer', $id);
fp_layout_end();

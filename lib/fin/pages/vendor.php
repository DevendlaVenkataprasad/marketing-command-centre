<?php
/**
 * One vendor: its details (bank details shown in full only to those who
 * maintain vendors), verification, statement of account with a check
 * against the vendor's own statement, documents, and history.
 */

$id = (int) ($_GET['id'] ?? 0);
$v = $id ? fp_vendor($id) : null;
if ($id && !$v) {
    fabo_deny('That vendor does not exist.', 404);
}
if ($v && !fp_doc_can_view('vendor', $v)) {
    fabo_deny('You do not have access to vendors.', 403);
}
if (!$v && !fp_can('vendor.manage')) {
    fabo_deny('You cannot add vendors.', 403);
}
$back = $v ? fp_url('vendor', ['id' => $id]) : fp_url('vendors');
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    if ($v && ($r = fp_wf_post('vendor', $id, $back))) {
        $flash = $r;
    } elseif ($v && ($r = fp_files_post('vendor', $id, $back, fp_can('vendor.manage')))) {
        $flash = $r;
    } elseif ($a === 'save') {
        $flash = fp_action(static function () use ($v) {
            if (!$v) {
                fp_nonce_check();
            }
            [$nid, $msg] = fp_vendor_save($v ? (int) $v['id'] : null, $_POST);
            fp_attach_many('vendor', $nid);
            inc_redirect(fp_url('vendor', ['id' => $nid]), ['ok', $msg]);
        }, $back);
    } elseif ($v && $a === 'submit') {
        $flash = fp_action(static fn() => fp_vendor_submit($id, $_POST['version'] ?? 0), $back);
    } elseif ($v && ($a === 'block' || $a === 'unblock')) {
        $flash = fp_action(static fn() => fp_vendor_block($id, $a === 'block', (string) ($_POST['reason'] ?? ''), $_POST['version'] ?? 0), $back);
    }
}

$canEdit = fp_can('vendor.manage') && fp_writable();
$showEdit = $canEdit && (!$v || isset($_GET['edit']) || ($flash && ($flash[0] ?? '') === 'err' && ($_POST['action'] ?? '') === 'save'));
if ($showEdit) {
    $x = $v ?? ['payment_terms_days' => 30];
    if ($flash && ($_POST['action'] ?? '') === 'save') {
        $x = $_POST + $x;
    }
    fp_layout_start($v ? 'Edit ' . $v['name'] : 'Add a vendor', $v && in_array($v['status'], ['active', 'blocked'], true)
        ? 'Changes to the name, GSTIN, PAN or bank details are approved before they take effect.' : 'A new vendor is a draft until it is verified.', '', ['Vendors' => fp_url('vendors'), $v ? $v['code'] : 'New' => null], $flash);
    $in = static fn(string $n, int $max, string $extra = '') => '<input name="' . $n . '" maxlength="' . $max . '" value="' . e((string) ($x[$n] ?? '')) . '"' . $extra . '>';
    echo '<section class="card">' . inc_form_open('', true) . '<input type="hidden" name="action" value="save">' . ($v ? '<input type="hidden" name="version" value="' . (int) $v['version'] . '">' : fp_nonce_field())
       . '<div class="inc-row">' . inc_field('Name', $in('name', 190, ' required'), '', true, 'grow') . inc_field('Legal name', $in('legal_name', 190), '', false, 'grow') . inc_field('Category', $in('category', 80)) . '</div>'
       . '<div class="inc-row">' . inc_field('GSTIN', $in('gstin', 20, ' style="text-transform:uppercase"')) . inc_field('PAN', $in('pan', 12, ' style="text-transform:uppercase"'))
       . inc_field('State', $in('state', 60)) . inc_field('Payment terms (days)', '<input name="payment_terms_days" type="number" min="0" max="365" value="' . (int) ($x['payment_terms_days'] ?? 30) . '">')
       . inc_field('TDS normally withheld', inc_select('tds_code_id', fp_tax_options(['tds']), $x['tds_code_id'] ?? '', 'None')) . '</div>'
       . '<div class="inc-row">' . inc_field('Email', $in('email', 190, ' type="email"')) . inc_field('Phone', $in('phone', 40)) . inc_field('Address', $in('address', 500), '', false, 'grow') . '</div>'
       . '<h3 class="fp-h3">Bank details</h3><div class="inc-row">' . inc_field('Bank', $in('bank_name', 120)) . inc_field('Account number', $in('bank_account', 40, ' autocomplete="off"'))
       . inc_field('IFSC', $in('bank_ifsc', 15, ' style="text-transform:uppercase"')) . inc_field('UPI', $in('upi', 80)) . '</div>'
       . '<label class="check"><input type="checkbox" name="msme" value="1"' . (!empty($x['msme']) ? ' checked' : '') . '> Registered MSME (payments are due within the statutory limit)</label>'
       . inc_field('Notes', $in('notes', 500)) . inc_field('Documents (PAN card, GST certificate, cancelled cheque …)', '<input type="file" name="files[]" multiple>')
       . '<div class="inc-formfoot"><button class="btn btn-primary">Save</button> <a class="btn" href="' . e($back) . '">Cancel</a></div></form></section>';
    fp_layout_end();
    return;
}
if (!$v) {
    fabo_deny('You cannot add vendors.', 403);
}

$bal = fp_vendor_balance($id);
$acts = '';
if ($canEdit) {
    $acts .= '<a class="btn" href="' . e(fp_url('vendor', ['id' => $id, 'edit' => 1])) . '">Edit</a> ';
    if (in_array($v['status'], ['draft', 'rejected'], true)) {
        $acts .= inc_button('Submit for verification', ['action' => 'submit', 'version' => $v['version']], 'btn btn-primary');
    }
}
if ($v['status'] === 'active' && fp_can('ap.prepare')) {
    $acts .= ' <a class="btn" href="' . e(fp_url('bill', ['new' => 1, 'vendor' => $id])) . '">+ Bill</a>';
}
if ($v['status'] === 'active' && fp_can('proc.prepare')) {
    $acts .= ' <a class="btn" href="' . e(fp_url('po', ['new' => 1, 'vendor' => $id])) . '">+ Purchase order</a>';
}
fp_layout_start($v['name'], e($v['code']) . ' · ' . e($v['category'] ?? 'Vendor'), $acts, ['Vendors' => fp_url('vendors'), $v['code'] => null], $flash);
if ($v['pending_json']) {
    $ch = json_decode((string) $v['pending_json'], true) ?: [];
    echo '<div class="inc-warn"><strong>Change awaiting approval:</strong> ' . e(implode(', ', array_map(static fn($k, $val) => str_replace('_', ' ', $k) . ' → ' . ($k === 'bank_account' ? fp_mask((string) $val) : ($val === '' ? '(blank)' : $val)), array_keys($ch), $ch)))
       . '. Until it is approved, the current details are used.</div>';
}
$showBank = fp_can('vendor.manage');
echo '<div class="inc-kpis">' . fp_kpi('Owed now', fp_money($bal['outstanding'], false), fp_url('bills', ['vendor' => $id, 'status' => 'open'])) . fp_kpi('Billed', fp_money($bal['billed'], false), fp_url('bills', ['vendor' => $id]))
   . fp_kpi('Paid', fp_money($bal['paid'], false), fp_url('payments', ['vendor' => $id])) . fp_kpi('Debit notes', fp_money($bal['notes'], false)) . '</div>';
echo '<div class="inc-grid2"><section class="card"><h2>Details</h2>' . fp_facts([
    'Status' => fp_badge(fp_vendor_statuses(), $v['status']) . ($v['verified_by'] ? ' <span class="muted small">verified by ' . e($v['verified_by']) . ' ' . e(fp_fmt_dt($v['verified_at'])) . '</span>' : ''),
    'Legal name' => e($v['legal_name'] ?? ''), 'GSTIN' => e($v['gstin'] ?? ''), 'PAN' => e($v['pan'] ?? ''), 'State' => e($v['state'] ?? ''),
    'Payment terms' => (int) $v['payment_terms_days'] . ' days', 'TDS' => $v['tds_code_id'] ? e(fp_tax_code((int) $v['tds_code_id'])['name']) : '', 'MSME' => $v['msme'] ? 'Yes' : '',
    'Email' => $v['email'] ? '<a href="mailto:' . e($v['email']) . '">' . e($v['email']) . '</a>' : '', 'Phone' => e($v['phone'] ?? ''), 'Address' => e($v['address'] ?? ''),
    'Bank' => e(trim(($v['bank_name'] ?? '') . ' ' . ($v['bank_account'] ? ($showBank ? $v['bank_account'] : fp_mask($v['bank_account'])) : '') . ' ' . ($v['bank_ifsc'] ?? ''))),
    'UPI' => e($v['upi'] ?? ''), 'Brought in from' => $v['source_ref'] ? e(str_starts_with($v['source_ref'], 'it:') ? 'IT desk' : 'Facilities desk') : '', 'Notes' => e($v['notes'] ?? ''),
]) . '</section>';
// The statement, and a check against what the vendor says.
$led = fp_vendor_ledger($id);
$theirs = fp_dec($_GET['theirs'] ?? '', false, 'Their balance', -1e12);
echo '<section class="card"><h2>Statement of account</h2>';
if (!$led) {
    echo '<p class="muted small">Nothing posted with this vendor yet.</p>';
} else {
    echo '<div class="scrollx"><table class="data inc-table stack"><thead><tr><th>Date</th><th>Entry</th><th class="num">Billed</th><th class="num">Paid / credited</th><th class="num">We owe</th></tr></thead><tbody>';
    foreach (array_slice($led, -40) as $l) {
        $page = ['bill' => 'bill', 'payment' => 'payment'][$l['source']] ?? 'journal';
        echo '<tr><td data-l="Date">' . e(fp_fmt_date($l['jdate'])) . '</td><td data-l="Entry"><a href="' . e(fp_url($page, ['id' => $page === 'journal' ? $l['jid'] : $l['source_id']])) . '">' . e($l['description']) . '</a></td>'
           . fp_money_td((float) $l['credit_base'] ?: null, 'Billed') . fp_money_td((float) $l['debit_base'] ?: null, 'Paid / credited') . fp_money_td($l['balance'], 'We owe') . '</tr>';
    }
    echo '</tbody></table></div>';
}
$ours = $led ? end($led)['balance'] : 0.0;
echo '<form method="get" class="inc-row"><input type="hidden" name="p" value="vendor"><input type="hidden" name="id" value="' . $id . '">'
   . inc_field('Balance on the vendor\'s statement', '<input name="theirs" inputmode="decimal" value="' . e((string) ($theirs ?? '')) . '">') . '<button class="btn btn-sm">Reconcile</button></form>';
if ($theirs !== null) {
    $d = fp_r2((float) $theirs - $ours);
    echo fp_paise($d) === 0 ? '<div class="flash ok">Agrees: both show ' . e(fp_money($ours)) . '.</div>'
        : '<div class="inc-warn">Difference of ' . e(fp_money($d)) . ' (they show ' . e(fp_money($theirs)) . ', we show ' . e(fp_money($ours)) . '). Look for bills not yet entered, payments in transit, or debit notes they have not booked.</div>';
}
echo '</section></div>';

if ($canEdit && in_array($v['status'], ['active', 'blocked'], true)) {
    $blk = $v['status'] === 'active';
    echo '<section class="card"><details><summary class="btn btn-sm">' . ($blk ? 'Block this vendor' : 'Unblock') . '</summary>' . inc_form_open() . '<input type="hidden" name="action" value="' . ($blk ? 'block' : 'unblock') . '">'
       . '<input type="hidden" name="version" value="' . (int) $v['version'] . '"><input name="reason" maxlength="500" required placeholder="Why"><button class="btn btn-sm' . ($blk ? ' btn-danger' : '') . '">' . ($blk ? 'Block' : 'Unblock') . '</button></form></details></section>';
}
echo fp_wf_panel('vendor', $v, $back);
echo fp_files_panel('vendor', $v, $canEdit, $back);
echo fp_audit_panel('vendor', $id);
fp_layout_end();

<?php
/**
 * One purchase order: drafted (from a requisition or on its own), approved,
 * received against, billed against, amended through re-approval, closed.
 */

$id = (int) ($_GET['id'] ?? 0);
$po = $id ? fp_po($id) : null;
if ($id && !$po) {
    fabo_deny('That order does not exist.', 404);
}
if ($po && !fp_doc_can_view('po', $po)) {
    fabo_deny('You do not have access to purchase orders.', 403);
}
$back = $po ? fp_url('po', ['id' => $id]) : fp_url('pos');
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    if ($po && ($x = fp_wf_post('po', $id, $back))) {
        $flash = $x;
    } elseif ($po && ($x = fp_files_post('po', $id, $back, fp_can('proc.prepare')))) {
        $flash = $x;
    } elseif ($a === 'save' || $a === 'save_submit') {
        $flash = fp_action(static function () use ($po, $a) {
            if (!$po) {
                fp_nonce_check();
            }
            $nid = fp_po_save($po ? (int) $po['id'] : null, $_POST);
            fp_attach_many('po', $nid);
            if ($a === 'save_submit') {
                fp_submit_or_draft(static fn() => fp_po_submit($nid, fp_po($nid)['version'], (string) ($_POST['override'] ?? '')), fp_url('po', ['id' => $nid]));
            }
            inc_redirect(fp_url('po', ['id' => $nid]), ['ok', 'Saved as a draft.']);
        }, $back);
    } elseif ($po && $a === 'submit') {
        $flash = fp_action(static fn() => fp_po_submit($id, $_POST['version'] ?? 0, (string) ($_POST['override'] ?? '')), $back);
    } elseif ($po && $a === 'grn') {
        $flash = fp_action(static function () use ($id) {
            fp_grn_create($id, $_POST);
            return 'Receipt recorded.';
        }, $back);
    } elseif ($po && $a === 'amend') {
        $flash = fp_action(static fn() => fp_po_amend($id, (string) ($_POST['reason'] ?? ''), $_POST['version'] ?? 0), $back);
    } elseif ($po && $a === 'close') {
        $flash = fp_action(static fn() => fp_po_close($id, (string) ($_POST['reason'] ?? ''), $_POST['version'] ?? 0), $back);
    }
}

$editable = fp_can('proc.prepare') && (!$po || $po['status'] === 'draft');
if ($editable && (!$po || isset($_GET['edit']) || $flash)) {
    $pr = !$po && !empty($_GET['pr']) ? fp_pr((int) $_GET['pr']) : null;
    $v = $po ?? ['vendor_id' => $_GET['vendor'] ?? ($pr['suggested_vendor_id'] ?? ''), 'po_date' => date('Y-m-d'), 'currency' => fp_setting('base_currency'), 'fx_rate' => 1,
                 'cost_centre_id' => $pr['cost_centre_id'] ?? '', 'project_id' => $pr['project_id'] ?? '', 'requisition_id' => $pr['id'] ?? '', 'delivery_date' => $pr['needed_by'] ?? '', 'terms' => ''];
    $lines = [];
    if ($po) {
        foreach (fp_po_lines($id) as $l) {
            $lines[] = $l + ['line_id' => $l['id']];
        }
    } elseif ($pr) {
        foreach (fp_all('SELECT * FROM fp_requisition_lines WHERE requisition_id = ?', 'i', [(int) $pr['id']]) as $l) {
            $lines[] = ['description' => $l['description'], 'account_id' => $l['account_id'], 'qty' => $l['qty'], 'rate' => $l['est_rate'], 'cost_centre_id' => $pr['cost_centre_id']];
        }
    }
    if ($flash && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $v = $_POST + $v;
        $lines = array_values((array) ($_POST['lines'] ?? []));
    }
    fp_layout_start($po ? ($po['amendment_no'] ? 'Amendment ' . (int) $po['amendment_no'] . ' of ' . $po['number'] : 'Edit order ' . $po['number']) : 'New purchase order',
                    $pr ? 'From requisition ' . e($pr['number']) . ': ' . e($pr['purpose']) : 'Raise it against a verified vendor; it is approved before it is sent.', '', ['Purchase orders' => fp_url('pos'), $po ? $po['number'] : 'New' => null], $flash);
    if ($po && $po['return_note']) {
        echo '<div class="inc-warn"><strong>Returned:</strong> ' . e($po['return_note']) . '</div>';
    }
    echo '<section class="card">' . inc_form_open('data-fp-form', true) . ($po ? '<input type="hidden" name="version" value="' . (int) $po['version'] . '">' : fp_nonce_field())
       . '<input type="hidden" name="requisition_id" value="' . e((string) ($v['requisition_id'] ?? '')) . '">'
       . '<div class="inc-row">' . inc_field('Vendor', inc_select('vendor_id', fp_vendor_options(false), $v['vendor_id'], 'Choose…', 'required'), '', true, 'grow')
       . inc_field('Order date', '<input type="date" name="po_date" value="' . e($v['po_date']) . '" required>', '', true)
       . inc_field('Deliver by', '<input type="date" name="delivery_date" value="' . e($v['delivery_date'] ?? '') . '">')
       . inc_field('Cost centre', inc_select('cost_centre_id', fp_cc_options(), $v['cost_centre_id'], 'Choose…', 'required'), 'Its owner approves the order', true)
       . (fp_project_options() ? inc_field('Project', inc_select('project_id', fp_project_options(), $v['project_id'] ?? '', '—')) : '')
       . inc_field('Currency', '<input name="currency" maxlength="3" size="4" value="' . e($v['currency']) . '">') . inc_field('Rate', '<input name="fx_rate" size="8" value="' . e((string) (float) $v['fx_rate']) . '">') . '</div>'
       . fp_lines_editor($lines, 'input', ['default_cc' => $v['cost_centre_id']])
       . inc_field('Terms', '<textarea name="terms" rows="2" maxlength="1000">' . e($v['terms'] ?? '') . '</textarea>')
       . inc_field('Quotes and attachments', '<input type="file" name="files[]" multiple>')
       . (fp_can('ap.override') ? inc_field('Budget exception note', '<input name="override" maxlength="300" placeholder="Only if the budget stops it and you accept the exception">') : '')
       . '<div class="inc-formfoot"><button class="btn" name="action" value="save">Save draft</button> <button class="btn btn-primary" name="action" value="save_submit">Submit for approval</button> <a class="btn" href="' . e($back) . '">Cancel</a></div></form></section>';
    fp_layout_end();
    return;
}
if (!$po) {
    fabo_deny('You cannot raise purchase orders.', 403);
}

$v = fp_vendor((int) $po['vendor_id']);
$lines = fp_po_lines($id);
$acts = '';
$open = in_array($po['status'], ['approved', 'partially_received'], true);
if ($po['status'] === 'draft' && fp_can('proc.prepare')) {
    $acts .= '<a class="btn" href="' . e(fp_url('po', ['id' => $id, 'edit' => 1])) . '">Edit</a> ' . inc_button('Submit for approval', ['action' => 'submit', 'version' => $po['version']], 'btn btn-primary') . ' ';
}
if (in_array($po['status'], ['approved', 'partially_received', 'received'], true) && fp_can('ap.prepare')) {
    $acts .= '<a class="btn btn-primary" href="' . e(fp_url('bill', ['new' => 1, 'po' => $id])) . '">Enter bill against this order</a> ';
}
fp_layout_start('Purchase order ' . $po['number'], e($v['name']), $acts, ['Purchase orders' => fp_url('pos'), $po['number'] => null], $flash);
if ($po['status'] === 'draft' && $po['return_note']) {
    echo '<div class="inc-warn"><strong>Returned:</strong> ' . e($po['return_note']) . '</div>';
}
echo fp_steps_bar(['draft' => 'Draft', 'submitted' => 'Approval', 'approved' => 'Open', 'partially_received' => 'Part received', 'received' => 'Received', 'closed' => 'Closed'], $po['status']);
$commit = 0.0;
foreach ($lines as $l) {
    $commit += max(0, (float) $l['amount'] * (float) $po['fx_rate'] - (float) $l['billed_amount']);
}
echo '<section class="card">' . fp_facts([
    'Status' => fp_badge(fp_po_statuses(), $po['status']) . ($po['amendment_no'] ? ' <span class="muted small">amendment ' . (int) $po['amendment_no'] . '</span>' : ''),
    'Vendor' => '<a href="' . e(fp_url('vendor', ['id' => $v['id']])) . '">' . e($v['name']) . '</a>', 'Order date' => e(fp_fmt_date($po['po_date'])), 'Deliver by' => e(fp_fmt_date($po['delivery_date'])),
    'Cost centre' => e(fp_one('SELECT CONCAT(code, " · ", name) n FROM fp_cost_centres WHERE id = ?', 'i', [(int) $po['cost_centre_id']])['n'] ?? ''),
    'Requisition' => $po['requisition_id'] ? '<a href="' . e(fp_url('pr', ['id' => $po['requisition_id']])) . '">' . e(fp_pr((int) $po['requisition_id'])['number']) . '</a>' : '',
    'Still committed' => in_array($po['status'], ['approved', 'partially_received', 'received', 'submitted'], true) ? e(fp_money($commit)) : '—',
    'Terms' => nl2br(e($po['terms'] ?? '')), 'Raised by' => e($po['prepared_by'] . ' · ' . fp_fmt_dt($po['created_at'])),
]) . fp_lines_table($lines, true, ['Received' => static fn($l) => e(rtrim(rtrim((string) $l['received_qty'], '0'), '.') ?: '0'), 'Billed' => static fn($l) => e(rtrim(rtrim((string) $l['billed_qty'], '0'), '.') ?: '0')])
   . '<div class="fp-totals"><span>Subtotal ' . e(fp_money($po['subtotal'])) . '</span><span>GST ' . e(fp_money($po['tax_total'])) . '</span><span class="g">Total ' . e(fp_money($po['total'])) . '</span></div></section>';

if ($open && fp_can('proc.receive') && fp_writable()) {
    echo '<section class="card"><h2>Record goods / services received</h2>' . inc_form_open() . '<input type="hidden" name="action" value="grn">'
       . '<div class="inc-row">' . inc_field('Received on', '<input type="date" name="grn_date" value="' . e(date('Y-m-d')) . '" required>') . inc_field('Note', '<input name="notes" maxlength="500">', '', false, 'grow') . '</div>'
       . '<table class="data inc-table stack"><thead><tr><th>Line</th><th class="num">Still to come</th><th class="num">Received now</th><th class="num">Rejected</th><th>Remarks</th></tr></thead><tbody>';
    foreach ($lines as $l) {
        $left = (float) $l['qty'] - (float) $l['received_qty'];
        echo '<tr><td data-l="Line">#' . (int) $l['line_no'] . ' ' . e($l['description']) . '</td><td class="num" data-l="Still to come">' . e(rtrim(rtrim(number_format($left, 3, '.', ''), '0'), '.')) . '</td>'
           . '<td class="num" data-l="Received now"><input name="qty[' . (int) $l['id'] . ']" size="6" inputmode="decimal"' . ($left <= 0 ? ' disabled' : '') . '></td>'
           . '<td class="num" data-l="Rejected"><input name="rejected[' . (int) $l['id'] . ']" size="5" inputmode="decimal"></td><td data-l="Remarks"><input name="remarks[' . (int) $l['id'] . ']" maxlength="255"></td></tr>';
    }
    echo '</tbody></table><button class="btn btn-primary">Record receipt</button></form></section>';
}
$grns = fp_all('SELECT * FROM fp_grns WHERE po_id = ? ORDER BY id', 'i', [$id]);
$bills = fp_all('SELECT id, number, bill_no, status, total FROM fp_bills WHERE po_id = ? ORDER BY id', 'i', [$id]);
if ($grns || $bills) {
    echo '<div class="inc-grid2"><section class="card"><h2>Receipts</h2>';
    foreach ($grns as $g) {
        echo '<p><strong>' . e($g['number']) . '</strong> · ' . e(fp_fmt_date($g['grn_date'])) . ' · ' . e($g['received_by']) . ($g['notes'] ? ' — ' . e($g['notes']) : '') . '<br><span class="small">'
           . e(implode('; ', array_map(static fn($x) => '#' . $x['line_no'] . ' ×' . (float) $x['qty'] . ((float) $x['rejected_qty'] > 0 ? ' (rejected ' . (float) $x['rejected_qty'] . ')' : ''),
                                       fp_all('SELECT g.*, l.line_no FROM fp_grn_lines g JOIN fp_po_lines l ON l.id = g.po_line_id WHERE g.grn_id = ?', 'i', [(int) $g['id']])))) . '</span></p>';
    }
    echo $grns ? '' : '<p class="muted small">Nothing received yet.</p>';
    echo '</section><section class="card"><h2>Bills</h2>';
    foreach ($bills as $b) {
        echo '<p><a href="' . e(fp_url('bill', ['id' => $b['id']])) . '">' . e($b['number']) . '</a> (' . e($b['bill_no']) . ') ' . fp_badge(fp_bill_statuses(), $b['status']) . ' ' . e(fp_money($b['total'])) . '</p>';
    }
    echo $bills ? '' : '<p class="muted small">No bills yet.</p>';
    echo '</section></div>';
}
if (fp_can('proc.prepare') && fp_writable() && in_array($po['status'], ['approved', 'partially_received', 'received', 'draft', 'rejected'], true)) {
    echo '<section class="card fp-actions">';
    if ($open) {
        echo '<details><summary class="btn btn-sm">Amend</summary>' . inc_form_open() . '<input type="hidden" name="action" value="amend"><input type="hidden" name="version" value="' . (int) $po['version'] . '">'
           . '<input name="reason" maxlength="500" required placeholder="What changes and why"><button class="btn btn-sm">Open amendment</button></form></details> ';
    }
    echo '<details><summary class="btn btn-sm">Close order</summary>' . inc_form_open('data-confirm="Close this order? Its remaining commitment is released."') . '<input type="hidden" name="action" value="close"><input type="hidden" name="version" value="' . (int) $po['version'] . '">'
       . '<input name="reason" maxlength="500" required placeholder="Why"><button class="btn btn-sm btn-danger">Close</button></form></details></section>';
}
$revs = fp_all('SELECT * FROM fp_po_revisions WHERE po_id = ? ORDER BY id DESC', 'i', [$id]);
if ($revs) {
    echo '<section class="card"><details><summary><strong>Earlier revisions</strong> (' . count($revs) . ')</summary><ul class="fp-files">';
    foreach ($revs as $rv) {
        $snap = json_decode((string) $rv['snapshot'], true);
        echo '<li>Revision ' . (int) $rv['amendment_no'] . ' — ' . e(fp_money($snap['po']['total'] ?? 0)) . ', ' . count($snap['lines'] ?? []) . ' line(s). Amended by ' . e($rv['created_by']) . ' ' . e(fp_fmt_dt($rv['created_at'])) . ': ' . e($rv['reason']) . '</li>';
    }
    echo '</ul></details></section>';
}
echo fp_wf_panel('po', $po, $back);
echo fp_files_panel('po', $po, fp_can('proc.prepare'), $back);
echo fp_audit_panel('po', $id);
fp_layout_end();

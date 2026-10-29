<?php
/** One purchase requisition: raised by anyone, approved by their manager, then turned into a purchase order by procurement. */

$id = (int) ($_GET['id'] ?? 0);
$r = $id ? fp_pr($id) : null;
if ($id && !$r) {
    fabo_deny('That requisition does not exist.', 404);
}
if ($r && !fp_doc_can_view('pr', $r)) {
    fabo_deny('That requisition is not yours to see.', 403);
}
$back = $r ? fp_url('pr', ['id' => $id]) : fp_url('prs');
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    $mine = $r && ((int) $r['requester_person_id'] === fp_me() || fp_can('proc.prepare'));
    if ($r && ($x = fp_wf_post('pr', $id, $back))) {
        $flash = $x;
    } elseif ($r && ($x = fp_files_post('pr', $id, $back, $mine))) {
        $flash = $x;
    } elseif ($a === 'save' || $a === 'save_submit') {
        $flash = fp_action(static function () use ($r, $a) {
            if (!$r) {
                fp_nonce_check();
            }
            $nid = fp_pr_save($r ? (int) $r['id'] : null, $_POST);
            fp_attach_many('pr', $nid);
            if ($a === 'save_submit') {
                fp_submit_or_draft(static fn() => fp_pr_submit($nid, fp_pr($nid)['version']), fp_url('pr', ['id' => $nid]));
            }
            inc_redirect(fp_url('pr', ['id' => $nid]), ['ok', 'Saved as a draft.']);
        }, $back);
    } elseif ($r && $a === 'submit') {
        $flash = fp_action(static fn() => fp_pr_submit($id, $_POST['version'] ?? 0), $back);
    } elseif ($r && $a === 'cancel') {
        $flash = fp_action(static function () use ($id) {
            fp_pr_cancel($id, $_POST['version'] ?? 0);
            return 'Requisition cancelled.';
        }, $back);
    }
}

$editable = fp_self_writable() && (!$r || ($r['status'] === 'draft' && ((int) $r['requester_person_id'] === fp_me() || fp_can('proc.prepare'))));
if ($editable && (!$r || isset($_GET['edit']) || $flash)) {
    $v = $r ?? ['purpose' => '', 'cost_centre_id' => '', 'needed_by' => ''];
    $lines = $r ? fp_all('SELECT * FROM fp_requisition_lines WHERE requisition_id = ?', 'i', [$id]) : [];
    if ($flash && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $v = $_POST + $v;
        $lines = array_values((array) ($_POST['lines'] ?? []));
    }
    $lines[] = [];
    $lines[] = [];
    fp_layout_start($r ? 'Edit requisition ' . $r['number'] : 'New purchase requisition', 'Say what is needed and roughly what it costs; your manager approves it, then procurement orders it.', '',
                    ['Requisitions' => fp_url('prs'), $r ? $r['number'] : 'New' => null], $flash);
    if ($r && $r['return_note']) {
        echo '<div class="inc-warn"><strong>Returned to you:</strong> ' . e($r['return_note']) . '</div>';
    }
    echo '<section class="card">' . inc_form_open('', true) . ($r ? '<input type="hidden" name="version" value="' . (int) $r['version'] . '">' : fp_nonce_field())
       . inc_field('What it is for', '<input name="purpose" maxlength="500" required value="' . e($v['purpose']) . '">', '', true)
       . '<div class="inc-row">' . inc_field('Cost centre', inc_select('cost_centre_id', fp_cc_options(), $v['cost_centre_id'], 'Choose…', 'required'), '', true)
       . (fp_project_options() ? inc_field('Project', inc_select('project_id', fp_project_options(), $v['project_id'] ?? '', '—')) : '')
       . inc_field('Needed by', '<input type="date" name="needed_by" value="' . e($v['needed_by'] ?? '') . '">')
       . inc_field('Suggested vendor', inc_select('suggested_vendor_id', fp_vendor_options(false), $v['suggested_vendor_id'] ?? '', 'No preference')) . '</div>'
       . '<table class="data inc-table fp-lines" data-lines="pr"><thead><tr><th>Item</th><th>Account (if known)</th><th class="num">Qty</th><th class="num">Estimated price</th><th></th></tr></thead><tbody>';
    $accs = fp_account_options(['expense', 'asset']);
    foreach ($lines as $i => $l) {
        echo '<tr><td><input name="lines[' . $i . '][description]" maxlength="255" value="' . e($l['description'] ?? '') . '"></td><td>' . inc_select('lines[' . $i . '][account_id]', $accs, $l['account_id'] ?? '', '—') . '</td>'
           . '<td class="num"><input name="lines[' . $i . '][qty]" size="5" value="' . e(isset($l['qty']) ? rtrim(rtrim((string) $l['qty'], '0'), '.') : '1') . '"></td>'
           . '<td class="num"><input name="lines[' . $i . '][est_rate]" size="9" inputmode="decimal" value="' . e(fp_inp($l['est_rate'] ?? '')) . '"></td><td><button type="button" class="btn btn-sm" data-row-del>×</button></td></tr>';
    }
    echo '</tbody></table><button type="button" class="btn btn-sm" data-row-add>+ Add item</button>'
       . inc_field('Quotes or specifications', '<input type="file" name="files[]" multiple>')
       . '<div class="inc-formfoot"><button class="btn" name="action" value="save">Save draft</button> <button class="btn btn-primary" name="action" value="save_submit">Submit for approval</button> <a class="btn" href="' . e($back) . '">Cancel</a></div></form></section>';
    fp_layout_end();
    return;
}
if (!$r) {
    fabo_deny('Finance is view only here.', 403);
}
$statuses = ['draft' => ['Draft', ''], 'submitted' => ['Waiting for approval', 'amber'], 'approved' => ['Approved — to order', 'green'], 'ordered' => ['Ordered', 'blue'],
             'rejected' => ['Rejected', 'red'], 'closed' => ['Closed', ''], 'cancelled' => ['Cancelled', '']];
$acts = '';
if ($editable) {
    $acts .= '<a class="btn" href="' . e(fp_url('pr', ['id' => $id, 'edit' => 1])) . '">Edit</a> ' . inc_button('Submit for approval', ['action' => 'submit', 'version' => $r['version']], 'btn btn-primary') . ' ';
}
if ($r['status'] === 'approved' && fp_can('proc.prepare')) {
    $acts .= '<a class="btn btn-primary" href="' . e(fp_url('po', ['new' => 1, 'pr' => $id])) . '">Raise purchase order</a> ';
}
if (in_array($r['status'], ['draft', 'submitted', 'approved'], true) && ((int) $r['requester_person_id'] === fp_me() || fp_can('proc.prepare')) && fp_writable()) {
    $acts .= inc_button('Cancel', ['action' => 'cancel', 'version' => $r['version']], 'btn btn-danger', 'Cancel this requisition?');
}
fp_layout_start('Requisition ' . $r['number'], e($r['purpose']), $acts, ['Requisitions' => fp_url('prs'), $r['number'] => null], $flash);
if ($r['status'] === 'draft' && $r['return_note']) {
    echo '<div class="inc-warn"><strong>Returned to you:</strong> ' . e($r['return_note']) . '</div>';
}
$pos = fp_all('SELECT id, number, status, total FROM fp_pos WHERE requisition_id = ?', 'i', [$id]);
echo '<section class="card">' . fp_facts([
    'Status' => fp_badge($statuses, $r['status']), 'Requested by' => e($r['requester'] . ' · ' . fp_fmt_dt($r['created_at'])),
    'Cost centre' => e(fp_one('SELECT CONCAT(code, " · ", name) n FROM fp_cost_centres WHERE id = ?', 'i', [(int) $r['cost_centre_id']])['n'] ?? ''),
    'Needed by' => e(fp_fmt_date($r['needed_by'])), 'Suggested vendor' => $r['suggested_vendor_id'] ? e(fp_vendor((int) $r['suggested_vendor_id'])['name']) : '',
    'Orders' => implode(', ', array_map(static fn($p) => '<a href="' . e(fp_url('po', ['id' => $p['id']])) . '">' . e($p['number']) . '</a>', $pos)),
]) . '<table class="data inc-table stack"><thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Estimated price</th><th class="num">Amount</th></tr></thead><tbody>';
foreach (fp_all('SELECT * FROM fp_requisition_lines WHERE requisition_id = ?', 'i', [$id]) as $l) {
    echo '<tr><td data-l="Item">' . e($l['description']) . '</td><td class="num" data-l="Qty">' . e(rtrim(rtrim((string) $l['qty'], '0'), '.')) . '</td>' . fp_money_td($l['est_rate'], 'Price') . fp_money_td($l['amount'], 'Amount') . '</tr>';
}
echo '<tr class="fp-total"><td colspan="3">Estimated total</td>' . fp_money_td($r['est_total']) . '</tr></tbody></table></section>';
echo fp_wf_panel('pr', $r, $back);
echo fp_files_panel('pr', $r, (int) $r['requester_person_id'] === fp_me() || fp_can('proc.prepare'), $back);
echo fp_audit_panel('pr', $id);
fp_layout_end();

<?php
/**
 * Expense Claims — submit, approve, pay.
 *
 *   ?id=ID   one claim
 */

require_once __DIR__ . '/parts.php';

$self = hr_self_emp();
$hr = hr_is_admin() || hr_scope('claims', 'edit') === 'org';
$canCfg = hr_is_admin() || hr_scope('claims', 'configure') === 'org';
$orgCreate = hr_is_admin() || hr_scope('claims', 'create') === 'org';
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    $cid = (int) ($_POST['id'] ?? 0);
    $back = $cid ? hr_url('claims', ['id' => $cid]) : hr_url('claims', ['tab' => (string) ($_GET['tab'] ?? '')]);
    if (in_array($a, ['master_save', 'master_toggle', 'master_delete', 'master_import', 'settings_apply'], true)) {
        $flash = hr_parts_post(hr_url('claims', ['tab' => 'setup']));
    } elseif ($a === 'create') {
        hr_need('claims', 'create');
        $flash = hr_action(static function () use ($self) {
            $empId = (int) ($_POST['employee_id'] ?? ($self['id'] ?? 0));
            $id = hr_claim_create($empId, $_POST, $_FILES['receipt'] ?? null);
            hr_redirect(hr_url('claims', ['id' => $id]), ['ok', 'Claim submitted. You will be told when it is decided.']);
        }, $back);
    } elseif ($a === 'decide') {
        $flash = hr_action(static function () use ($cid) {
            return hr_claim_decide($cid, (string) ($_POST['decision'] ?? ''), $_POST);
        }, (string) ($_POST['back'] ?? '') === 'approvals' ? hr_url('claims', ['tab' => 'approvals']) : $back);
    } elseif ($a === 'pay') {
        $flash = hr_action(static function () use ($cid) { return hr_claim_pay($cid, $_POST); }, $back);
    } elseif ($a === 'cancel') {
        $flash = hr_action(static function () use ($cid) { hr_claim_cancel($cid); return 'Claim cancelled.'; }, $back);
    }
}

/** The new-claim form. */
function hr_claim_form(?array $self, bool $forOthers, string $action): string
{
    $cats = [];
    foreach (fabo_all("SELECT id, name, amount_limit FROM hr_masters WHERE org_id = ? AND kind = 'claim_category' AND active = 1 ORDER BY name", 'i', [hr_org()]) as $c) {
        $cats[(int) $c['id']] = $c['name'] . ($c['amount_limit'] !== null ? ' (up to ' . fabo_money_exact($c['amount_limit']) . ')' : '');
    }
    if (!$cats) {
        return hr_empty('No claim categories yet.', ' HR adds them (travel, meals, phone…) under Claims → Categories & rules.');
    }
    $need = hr_setting('claim_receipt_above');
    $hint = $need === null || $need === '' ? 'Optional.' : ((float) $need > 0 ? 'Required above ' . fabo_money_exact($need) . '.' : 'Required.');
    return hr_form_open($action, 'class="hr-form"', true) . '<input type="hidden" name="action" value="create"><div class="formgrid">'
         . ($forOthers ? hr_field('Employee', hr_select('employee_id', hr_employee_choices(true, 'claims', 'create'), $self['id'] ?? '', 'Choose…', ['required' => true]), true)
                       : '<input type="hidden" name="employee_id" value="' . (int) ($self['id'] ?? 0) . '">')
         . hr_field('Category', hr_select('category_id', $cats, $_POST['category_id'] ?? '', 'Choose…', ['required' => true]), true)
         . hr_field('Date of expense', hr_input('expense_date', $_POST['expense_date'] ?? date('Y-m-d'), 'date', ['required' => true, 'max' => date('Y-m-d')]), true)
         . hr_field('Amount (₹)', hr_input('amount', $_POST['amount'] ?? '', 'number', ['required' => true, 'step' => '0.01', 'min' => '0.01']), true)
         . hr_field('Receipt', '<input type="file" name="receipt" accept="image/*,.pdf">', false, 'Photo or PDF. ' . $hint)
         . hr_field('What was it for?', hr_input('description', $_POST['description'] ?? '', 'text', ['required' => true, 'maxlength' => 500, 'placeholder' => 'e.g. Cab to client meeting at Andheri']), true, '', 'full')
         . '</div><button class="btn btn-primary">Submit claim</button></form>';
}

/** A list of claims. */
function hr_render_claims(array $rows, bool $who, bool $decide = false): void
{
    if (!$rows) {
        echo hr_empty('No claims.');
        return;
    }
    echo '<div class="scrollx"><table class="data hr-table stack"><thead><tr><th>Claim</th>' . ($who ? '<th>Employee</th>' : '') . '<th>Category</th><th>Spent on</th>'
       . '<th class="num">Amount</th><th>Status</th><th></th></tr></thead><tbody>';
    $total = 0.0;
    foreach ($rows as $c) {
        $total += (float) ($c['approved_amount'] ?? $c['amount']);
        echo '<tr><td data-l="Claim"><a class="nm" href="' . e(hr_url('claims', ['id' => $c['id']])) . '"><strong>' . e($c['number']) . '</strong></a>'
           . '<div class="muted small">' . e(mb_strimwidth($c['description'], 0, 70, '…')) . '</div></td>'
           . ($who ? '<td data-l="Employee">' . hr_person_cell($c) . '</td>' : '')
           . '<td data-l="Category">' . e($c['category'] ?? '—') . '</td><td data-l="Spent on" class="nowrap">' . e(hr_fmt_date($c['expense_date'])) . '</td>'
           . '<td class="num" data-l="Amount">' . e(fabo_money_exact($c['amount']))
           . ($c['approved_amount'] !== null && (float) $c['approved_amount'] !== (float) $c['amount'] ? '<div class="small muted">approved ' . e(fabo_money_exact($c['approved_amount'])) . '</div>' : '') . '</td>'
           . '<td data-l="Status">' . hr_badge($c['status'], hr_claim_statuses()[$c['status']]) . '</td><td class="hr-acts">';
        if ($decide && hr_claim_can_decide($c)) {
            echo hr_button_form('Approve', ['action' => 'decide', 'id' => $c['id'], 'decision' => 'approve', 'back' => 'approvals'], 'btn btn-sm btn-approve')
               . '<a class="btn btn-sm" href="' . e(hr_url('claims', ['id' => $c['id']])) . '">Review</a>';
        } else {
            echo '<a class="btn btn-sm" href="' . e(hr_url('claims', ['id' => $c['id']])) . '">Open</a>';
        }
        echo '</td></tr>';
    }
    echo '</tbody><tfoot><tr><th colspan="' . ($who ? 4 : 3) . '">Total</th><th class="num">' . e(fabo_money_exact($total)) . '</th><th colspan="2"></th></tr></tfoot></table></div>';
}

/* ------------------------------------------------------------- one claim */

$cid = (int) ($_GET['id'] ?? 0);
if ($cid) {
    $c = hr_claim($cid);
    if (!$c) {
        fabo_deny('That claim is not available to you.', 404);
    }
    hr_layout_start('Claim ' . $c['number'], e($c['full_name']) . ' · ' . e($c['category'] ?? '') . ' · ' . hr_badge($c['status'], hr_claim_statuses()[$c['status']]),
                    ['Expense Claims' => hr_url('claims'), $c['number'] => '']);
    echo hr_flash_html($flash);
    if (!in_array($c['status'], ['rejected', 'cancelled'], true)) {
        echo hr_steps_html(['submitted' => 'Submitted', 'approved' => 'Approved', 'paid' => 'Paid'], $c['status']);
    }
    echo '<div class="hr-two"><div class="card"><h2>The expense</h2><dl class="hr-kv">'
       . '<dt>Employee</dt><dd>' . e($c['full_name'] . ' (' . $c['emp_code'] . ')') . '</dd><dt>Category</dt><dd>' . e($c['category'] ?? '—') . '</dd>'
       . '<dt>Spent on</dt><dd>' . e(hr_fmt_date($c['expense_date'])) . '</dd><dt>Amount claimed</dt><dd><strong>' . e(fabo_money_exact($c['amount'])) . '</strong></dd>'
       . '<dt>For</dt><dd>' . e($c['description']) . '</dd>'
       . '<dt>Receipt</dt><dd>' . ($c['receipt_file'] ? '<a href="' . e(hr_url('file', ['kind' => 'claim', 'id' => $cid])) . '">' . e($c['receipt_name']) . '</a>' : 'None attached') . '</dd>'
       . '<dt>Submitted</dt><dd>' . e(date('d M Y H:i', strtotime($c['created_at']))) . ' by ' . e($c['created_by']) . '</dd>';
    if ($c['decided_by']) {
        echo '<dt>' . ($c['status'] === 'rejected' ? 'Rejected' : 'Approved') . '</dt><dd>' . e($c['decided_by']) . ' · ' . e(hr_fmt_date($c['decided_at']))
           . ($c['approved_amount'] !== null ? ' · ' . e(fabo_money_exact($c['approved_amount'])) : '') . ($c['decision_note'] ? '<div class="muted small">' . e($c['decision_note']) . '</div>' : '') . '</dd>';
    }
    if ($c['status'] === 'paid') {
        echo '<dt>Paid</dt><dd>' . ($c['paid_via'] === 'payroll' ? 'With salary for ' . e(date('F Y', strtotime($c['paid_on']))) . ($c['applied_run_id'] ? '' : ' (not yet in a payroll run)')
                                                               : e(hr_fmt_date($c['paid_on'])) . ($c['paid_ref'] ? ' · ref ' . e($c['paid_ref']) : '')) . '</dd>';
    }
    echo '</dl>';
    $self = hr_self_emp();
    if ($c['status'] === 'submitted' && (($self && (int) $self['id'] === (int) $c['employee_id']) || $hr)) {
        echo '<div style="margin-top:12px">' . hr_button_form('Cancel claim', ['action' => 'cancel', 'id' => $cid], 'btn btn-sm', 'Cancel this claim?') . '</div>';
    }
    echo '</div><div class="card"><h2>Next step</h2>';
    if (hr_claim_can_decide($c)) {
        echo hr_form_open(hr_url('claims', ['id' => $cid]), 'class="hr-form"') . '<input type="hidden" name="action" value="decide"><input type="hidden" name="id" value="' . $cid . '"><div class="formgrid">'
           . hr_field('Approve amount (₹)', hr_input('approved_amount', $c['amount'], 'number', ['step' => '0.01', 'min' => '0.01', 'max' => $c['amount']]), false, 'Lower it to approve part of the claim.')
           . hr_field('Note', hr_input('note', '', 'text', ['maxlength' => 500, 'placeholder' => 'Required when rejecting or approving less']), false, '', 'full')
           . '</div><div class="hr-formfoot"><button class="btn btn-approve" name="decision" value="approve">Approve</button><button class="btn btn-reject" name="decision" value="reject">Reject</button></div></form>';
    } elseif ($c['status'] === 'approved' && $hr) {
        $payroll = hr_is_admin() || hr_can('payroll', 'process_payroll');
        echo '<p class="mt0">Approved for <strong>' . e(fabo_money_exact($c['approved_amount'] ?? $c['amount'])) . '</strong>. Record how it is paid:</p>'
           . hr_form_open(hr_url('claims', ['id' => $cid]), 'class="hr-form"') . '<input type="hidden" name="action" value="pay"><input type="hidden" name="id" value="' . $cid . '"><div class="formgrid">'
           . hr_field('Paid', hr_select('via', ['direct' => 'Directly (bank transfer, cash…)'] + ($payroll ? ['payroll' => 'With salary — add to payroll'] : []), 'direct'))
           . hr_field('Paid on (direct)', hr_input('paid_on', date('Y-m-d'), 'date'))
           . hr_field('Reference (direct)', hr_input('paid_ref', '', 'text', ['maxlength' => 120, 'placeholder' => 'UTR / cheque no.']))
           . ($payroll ? hr_field('Payroll month (with salary)', hr_select('period', hr_month_choices(1, 2), date('Y-m'))) : '')
           . '</div><button class="btn btn-primary">Record payment</button></form>'
           . ($payroll ? '<p class="hr-hint">“With salary” adds it to that month\'s payroll as a reimbursement; it is paid and shown on the payslip when payroll runs.</p>' : '');
    } else {
        echo hr_empty(['submitted' => 'Waiting for approval.', 'approved' => 'Waiting to be paid.', 'paid' => 'Paid. Nothing more to do.',
                       'rejected' => 'Rejected.', 'cancelled' => 'Cancelled.'][$c['status']]);
    }
    echo '</div></div>';
    hr_layout_end();
    return;
}

/* -------------------------------------------------------------- the desk */

$tabs = [];
if ($self) { $tabs['mine'] = 'My claims'; }
$waiting = hr_claims_awaiting_me();
if (hr_can('claims', 'approve')) { $tabs['approvals'] = 'Approvals' . ($waiting ? ' <span class="badge">' . count($waiting) . '</span>' : ''); }
if (hr_scope('claims', 'view') !== 'self' || hr_is_admin()) { $tabs['all'] = hr_scope('claims', 'view') === 'team' ? 'My team\'s claims' : 'All claims'; }
if ($canCfg) { $tabs['setup'] = 'Categories & rules'; }
$tab = hr_tab($tabs, $waiting ? 'approvals' : ($self ? 'mine' : 'all'));
hr_layout_start('Expense Claims', 'Claim business expenses, approve them, and pay them — directly or with salary.', ['Expense Claims' => ''],
                hr_report_allowed('claims_register') ? '<a class="btn btn-sm" href="' . e(hr_url('reports', ['report' => 'claims_register'])) . '">Claims report</a>' : '');
echo hr_flash_html($flash);
hr_tabs('claims', $tabs, $tab);

if ($tab === 'mine') {
    echo hr_guide('How claims work', [
        'Submit the expense with a photo of the receipt.',
        'Your manager (or HR) approves it — possibly for a lower amount, with a reason.',
        'It is then paid, either directly or with your next salary, and you are told at each step.',
    ]);
    $rows = hr_claims_list(['employee' => (int) $self['id']]);
    $sum = static fn(string $st) => array_sum(array_map(static fn($c) => (float) ($c['approved_amount'] ?? $c['amount']), array_filter($rows, static fn($c) => $c['status'] === $st)));
    echo '<div class="tiles">' . hr_tile('Waiting for approval', e(fabo_money_exact($sum('submitted'))), 'amber') . hr_tile('Approved, to be paid', e(fabo_money_exact($sum('approved'))))
       . hr_tile('Paid this year', e(fabo_money_exact(array_sum(array_map(static fn($c) => (float) ($c['approved_amount'] ?? $c['amount']),
             array_filter($rows, static fn($c) => $c['status'] === 'paid' && substr((string) $c['paid_on'], 0, 4) === date('Y')))))), 'green') . '</div>';
    if (hr_can('claims', 'create')) {
        echo '<div class="card"><h2>New claim</h2>' . hr_claim_form($self, false, hr_url('claims', ['tab' => 'mine'])) . '</div>';
    }
    echo '<div class="card"><h2>My claims</h2>';
    hr_render_claims($rows, false);
    echo '</div>';
}

if ($tab === 'approvals') {
    echo '<div class="card"><h2>Waiting for your approval (' . count($waiting) . ')</h2>';
    hr_render_claims($waiting, true, true);
    echo '</div>';
}

if ($tab === 'all') {
    $f = ['status' => (string) ($_GET['status'] ?? ''), 'employee' => (int) ($_GET['employee'] ?? 0), 'category' => (int) ($_GET['category'] ?? 0),
          'from' => hr_date($_GET['from'] ?? '') ?? '', 'to' => hr_date($_GET['to'] ?? '') ?? ''];
    $rows = hr_claims_list($f);
    echo '<form method="get" class="card hr-filters"><input type="hidden" name="p" value="claims"><input type="hidden" name="tab" value="all">'
       . '<div class="grp"><label for="cs">Status</label><select id="cs" name="status" data-autosubmit>' . hr_options(hr_claim_statuses(), $f['status'], 'All') . '</select></div>'
       . '<div class="grp"><label for="ce">Employee</label><select id="ce" name="employee" data-autosubmit>' . hr_options(hr_employee_choices(false, 'claims', 'view'), $f['employee'], 'All') . '</select></div>'
       . '<div class="grp"><label for="cc">Category</label><select id="cc" name="category" data-autosubmit>' . hr_options(hr_master_map('claim_category', false), $f['category'], 'All') . '</select></div>'
       . '<div class="grp"><label for="cf">Spent from</label><input id="cf" type="date" name="from" value="' . e($f['from']) . '"></div>'
       . '<div class="grp"><label for="ct">to</label><input id="ct" type="date" name="to" value="' . e($f['to']) . '"></div>'
       . '<button class="btn btn-sm btn-primary">Show</button></form>';
    if ($orgCreate) {
        echo '<details class="card"><summary><strong>+ Record a claim for an employee</strong></summary><div style="margin-top:12px">' . hr_claim_form($self, true, hr_url('claims', ['tab' => 'all'])) . '</div></details>';
    }
    echo '<div class="card">';
    hr_render_claims($rows, true, true);
    echo '</div>';
}

if ($tab === 'setup') {
    $back = hr_url('claims', ['tab' => 'setup']);
    hr_part_masters('claim_category', $back, ['tab' => 'setup']);
    hr_part_settings('claims', $back, 'Claim rules');
}

hr_layout_end();

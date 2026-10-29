<?php
/**
 * Payroll Management — runs (Draft → Calculated → Reviewed → Approved →
 * Finalized → Payslips), the per-employee breakdown, salary structures and
 * adjustments. Payment is recorded here; no bank transfer is made by the
 * portal.
 */

$flash = null;
$orgPay = hr_is_admin() || hr_scope('payroll', 'view') === 'org';
if (!$orgPay) {
    // Self or team scope: payslips only, on My HR / the employee profile.
    $self = hr_self_emp();
    hr_redirect($self ? hr_url('me') . '#payslips' : hr_url('dashboard'));
}

/* --------------------------------------------------------------- actions */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    $runId = (int) ($_POST['run'] ?? 0);
    $runBack = hr_url('payroll', ['run' => $runId]);
    switch ($a) {
        case 'create':
            hr_need('payroll', 'process_payroll');
            $flash = hr_action(static function () {
                $id = hr_run_create((string) ($_POST['period'] ?? ''));
                hr_redirect(hr_url('payroll', ['run' => $id]), ['ok', 'Payroll run created. Press Calculate to work out every employee.']);
            }, hr_url('payroll'));
            break;
        case 'calculate':
            hr_need('payroll', 'process_payroll');
            $flash = hr_action(static function () use ($runId) {
                $s = hr_run_calculate($runId);
                return 'Calculated for ' . $s['employees'] . ' employee(s)' . ($s['with_warnings'] ? '; ' . $s['with_warnings'] . ' need a look (see warnings).' : '.');
            }, $runBack);
            break;
        case 'advance':
            $flash = hr_action(static function () use ($runId) {
                return hr_run_advance($runId, (string) ($_POST['to'] ?? ''), (string) ($_POST['confirm'] ?? ''));
            }, $runBack);
            break;
        case 'delete':
            hr_need('payroll', 'process_payroll');
            $flash = hr_action(static function () use ($runId) { hr_run_delete($runId); return 'Run deleted.'; }, hr_url('payroll'));
            break;
        case 'hold':
            hr_need('payroll', 'process_payroll');
            $flash = hr_action(static function () {
                hr_item_hold((int) ($_POST['item'] ?? 0), !empty($_POST['hold']));
                return !empty($_POST['hold']) ? 'Pay held. It will not get a payslip until released.' : 'Pay released.';
            }, $runBack);
            break;
        case 'paid':
            hr_need('payroll', 'process_payroll');
            $flash = hr_action(static function () {
                hr_item_mark_paid((int) ($_POST['item'] ?? 0), (string) ($_POST['status'] ?? ''), (string) ($_POST['paid_on'] ?? ''), (string) ($_POST['ref'] ?? ''));
                return 'Payment status recorded.';
            }, hr_url('payroll', ['run' => $runId, 'item' => (int) ($_POST['item'] ?? 0)]));
            break;
        case 'paid_all':
            hr_need('payroll', 'process_payroll');
            $flash = hr_action(static function () use ($runId) {
                $n = 0;
                foreach (hr_run_items($runId, false) as $it) {
                    if (!$it['hold'] && $it['payment_status'] !== 'paid' && $it['structure_id']) {
                        hr_item_mark_paid((int) $it['id'], 'paid', (string) ($_POST['paid_on'] ?? ''), (string) ($_POST['ref'] ?? ''));
                        $n++;
                    }
                }
                return $n . ' marked as paid.';
            }, $runBack);
            break;
        case 'structure':
            $empId = (int) ($_POST['employee_id'] ?? 0);
            hr_need_emp($empId, 'payroll', 'process_payroll');
            if (!hr_emp_allowed($empId, 'confidential', 'edit') && !hr_is_admin()) {
                fabo_deny('Salary structures need the confidential-data edit right.', 403);
            }
            $flash = hr_action(static function () use ($empId) {
                hr_structure_save($empId, $_POST);
                return 'Salary structure saved. It is used from the next payroll calculation.';
            }, hr_url('payroll', ['tab' => 'structures', 'employee' => $empId]));
            break;
        case 'adjust':
            $flash = hr_action(static function () { hr_adjustment_save($_POST); return 'Adjustment recorded. It is included the next time that month is calculated.'; },
                               hr_url('payroll', ['tab' => 'adjustments']));
            break;
        case 'adjust_delete':
            hr_need('payroll', 'process_payroll');
            $flash = hr_action(static function () { hr_adjustment_delete((int) ($_POST['id'] ?? 0)); return 'Adjustment removed.'; }, hr_url('payroll', ['tab' => 'adjustments']));
            break;
    }
}

$runId  = (int) ($_GET['run'] ?? 0);
$itemId = (int) ($_GET['item'] ?? 0);

/* ------------------------------------------------------- one employee */

if ($runId && $itemId) {
    $run = hr_run($runId);
    $it = hr_item($itemId);
    if (!$run || !$it || (int) $it['run_id'] !== $runId || !hr_emp_allowed((int) $it['employee_id'], 'payroll', 'view')) {
        fabo_deny('That payroll record is not available.', 404);
    }
    $lines = hr_item_lines($itemId);
    hr_layout_start($it['full_name'] . ' — ' . date('F Y', strtotime($run['period'] . '-01')), 'Employee ID ' . e($it['emp_code']) . ' · run ' . hr_badge($run['status'], hr_run_statuses()[$run['status']]),
                    ['Payroll' => hr_url('payroll'), date('F Y', strtotime($run['period'] . '-01')) => hr_url('payroll', ['run' => $runId]), $it['full_name'] => ''],
                    '<a class="btn btn-sm" href="' . e(hr_url('file', ['kind' => 'payslip', 'item' => $itemId])) . '">' . (in_array($run['status'], ['payslips'], true) ? 'Payslip PDF' : 'Preview payslip (PDF)') . '</a>');
    echo hr_flash_html($flash);
    if ($it['warnings']) {
        echo '<div class="hr-warn"><strong>Check before approving:</strong><ul>';
        foreach (explode("\n", $it['warnings']) as $w) { echo '<li>' . e($w) . '</li>'; }
        echo '</ul></div>';
    }
    echo '<div class="card"><h2>Days</h2><div class="tiles">'
       . hr_tile('Days in month', hr_fmt_days($it['calendar_days'])) . hr_tile('Working days', hr_fmt_days($it['working_days']))
       . hr_tile('Present', hr_fmt_days($it['present_days']), 'green') . hr_tile('Paid leave', hr_fmt_days($it['paid_leave_days']))
       . hr_tile('Unpaid leave', hr_fmt_days($it['unpaid_leave_days']), $it['unpaid_leave_days'] > 0 ? 'amber' : '')
       . hr_tile('Absent', hr_fmt_days($it['absent_days']), $it['absent_days'] > 0 ? 'red' : '')
       . hr_tile('Holidays / weekly offs', hr_fmt_days($it['holidays']) . ' / ' . hr_fmt_days($it['weekly_offs']))
       . hr_tile('Not employed', hr_fmt_days($it['not_employed_days']))
       . hr_tile('Loss of pay', hr_fmt_days($it['lop_days']), $it['lop_days'] > 0 ? 'amber' : '')
       . hr_tile('Payable days', hr_fmt_days($it['payable_days']) . ' <small class="muted">of ' . hr_fmt_days($it['divisor_days']) . '</small>')
       . hr_tile('Overtime (h)', hr_fmt_days($it['overtime_hours'])) . '</div>'
       . '<p class="hr-hint">Pay for components marked “reduces with unpaid days” = full monthly amount × payable days ÷ ' . hr_fmt_days($it['divisor_days'])
       . '. Paid leave, holidays and weekly offs are not deducted.</p></div>';
    $grp = static function (array $kinds) use ($lines) { return array_values(array_filter($lines, static fn($l) => in_array($l['kind'], $kinds, true))); };
    $tbl = static function (string $title, array $rows) {
        $t = 0.0;
        $h = '<div class="card"><h2>' . e($title) . '</h2>';
        if (!$rows) { return $h . hr_empty('None.') . '</div>'; }
        $h .= '<table class="data hr-table"><thead><tr><th>Component</th><th class="num">Full month</th><th class="num">This month</th></tr></thead><tbody>';
        foreach ($rows as $l) {
            $t += (float) $l['amount'];
            $h .= '<tr><td>' . e($l['name']) . '<div class="hr-basis">' . e($l['basis']) . '</div></td><td class="num">' . e(fabo_money_exact($l['full_amount'])) . '</td>'
                . '<td class="num"><strong>' . e(fabo_money_exact($l['amount'])) . '</strong></td></tr>';
        }
        return $h . '</tbody><tfoot><tr><th>Total</th><th></th><th class="num">' . e(fabo_money_exact($t)) . '</th></tr></tfoot></table></div>';
    };
    echo '<div class="hr-pay">' . $tbl('Earnings', $grp(['earning', 'reimbursement', 'earning_round'])) . $tbl('Deductions', $grp(['deduction', 'deduction_round'])) . '</div>';
    echo '<div class="hr-net"><span>Net pay</span><strong>' . e(fabo_money_exact($it['net'])) . '</strong></div>';
    if ($emp = $grp(['employer'])) {
        echo '<div style="margin-top:14px">' . $tbl('Employer contributions (not deducted from pay)', $emp) . '</div>';
    }
    if (in_array($run['status'], ['finalized', 'payslips'], true)) {
        echo '<div class="card" style="margin-top:14px"><h2>Payment</h2><p class="mt0">Recorded here for tracking only — the portal does not send money.</p>'
           . hr_form_open('', 'class="hr-form"') . '<input type="hidden" name="action" value="paid"><input type="hidden" name="run" value="' . $runId . '"><input type="hidden" name="item" value="' . $itemId . '"><div class="formgrid">'
           . hr_field('Status', hr_select('status', ['pending' => 'Pending', 'paid' => 'Paid', 'on_hold' => 'On hold'], $it['payment_status']))
           . hr_field('Paid on', hr_input('paid_on', $it['paid_on'] ?? date('Y-m-d'), 'date'))
           . hr_field('Reference (UTR / cheque no.)', hr_input('ref', $it['payment_ref'] ?? '', 'text', ['maxlength' => 120]))
           . '</div><button class="btn btn-primary">Save payment status</button></form></div>';
    }
    hr_layout_end();
    return;
}

/* ---------------------------------------------------------------- a run */

if ($runId) {
    $run = hr_run($runId);
    if (!$run) {
        fabo_deny('That payroll run is not available.', 404);
    }
    $items = hr_run_items($runId);
    $st = $run['status'];
    $label = date('F Y', strtotime($run['period'] . '-01'));
    hr_layout_start('Payroll — ' . $label, hr_fmt_date($run['period_start']) . ' to ' . hr_fmt_date($run['period_end']) . ' · ' . hr_badge($st, hr_run_statuses()[$st]),
                    ['Payroll' => hr_url('payroll'), $label => ''],
                    $items ? '<a class="btn btn-sm" href="' . e(hr_url('file', ['kind' => 'report', 'report' => 'payroll_register', 'fmt' => 'xlsx', 'run' => $runId])) . '">Payroll register (Excel)</a>'
                           . '<a class="btn btn-sm" href="' . e(hr_url('file', ['kind' => 'report', 'report' => 'salary_deductions', 'fmt' => 'xlsx', 'run' => $runId])) . '">Deductions (Excel)</a>' : '');
    echo hr_flash_html($flash);

    $order = ['draft', 'calculated', 'reviewed', 'approved', 'finalized', 'payslips'];
    $pos = array_search($st, $order, true);
    echo '<ol class="hr-steps">';
    foreach ($order as $i => $s) {
        if ($s === 'reviewed' && hr_setting('pay_needs_review') !== '1' && $st !== 'reviewed') { continue; }
        echo '<li class="' . ($i < $pos ? 'done' : ($i === $pos ? 'now' : '')) . '">' . e(hr_run_statuses()[$s]) . '</li>';
    }
    echo '</ol>';

    if ($b = hr_payroll_blockers()) {
        echo '<div class="hr-warn"><strong>Payroll cannot be calculated yet:</strong><ul>';
        foreach ($b as $x) { echo '<li>' . e($x) . '</li>'; }
        echo '</ul><a class="btn btn-sm" href="' . e(hr_url('sheets', ['tab' => 'payroll'])) . '">Open payroll rules</a></div>';
    }

    $tot = ['gross' => 0, 'ded' => 0, 'net' => 0, 'emp' => 0, 'warn' => 0, 'hold' => 0];
    foreach ($items as $it) {
        $tot['gross'] += $it['gross']; $tot['ded'] += $it['deductions']; $tot['net'] += $it['net']; $tot['emp'] += $it['employer_cost'];
        $tot['warn'] += $it['warnings'] ? 1 : 0; $tot['hold'] += $it['hold'];
    }
    echo '<div class="tiles">' . hr_tile('Employees', (string) count($items)) . hr_tile('Gross pay', e(fabo_money($tot['gross'])))
       . hr_tile('Deductions', e(fabo_money($tot['ded']))) . hr_tile('Net pay', e(fabo_money($tot['net'])), 'green')
       . hr_tile('Employer contributions', e(fabo_money($tot['emp']))) . hr_tile('Need a look', (string) $tot['warn'], $tot['warn'] ? 'amber' : '')
       . hr_tile('On hold', (string) $tot['hold'], $tot['hold'] ? 'amber' : '') . '</div>';

    // What can be done next.
    echo '<div class="card"><h2>Next step</h2><div class="hr-acts">';
    $proc = hr_can('payroll', 'process_payroll');
    $appr = hr_can('payroll', 'approve');
    if (in_array($st, ['draft', 'calculated', 'reviewed'], true) && $proc) {
        echo hr_button_form($st === 'draft' ? 'Calculate payroll' : 'Recalculate', ['action' => 'calculate', 'run' => $runId], 'btn btn-primary',
                            $st === 'draft' ? '' : 'Recalculate every employee from current attendance, leave and salary? Holds are kept.');
    }
    if ($st === 'calculated' && hr_setting('pay_needs_review') === '1' && $proc) {
        echo hr_button_form('Mark as reviewed', ['action' => 'advance', 'run' => $runId, 'to' => 'reviewed'], 'btn', 'Have the figures and warnings been checked?');
    }
    if (($st === 'reviewed' || ($st === 'calculated' && hr_setting('pay_needs_review') !== '1')) && $appr) {
        echo hr_button_form('Approve payroll', ['action' => 'advance', 'run' => $runId, 'to' => 'approved'], 'btn btn-approve',
                            'Approve ' . $label . ' payroll: ' . fabo_money($tot['net']) . ' net for ' . count($items) . ' employees?');
    }
    if (in_array($st, ['reviewed', 'approved'], true) && $proc) {
        echo hr_button_form('Send back for changes', ['action' => 'advance', 'run' => $runId, 'to' => 'back'], 'btn');
    }
    if (in_array($st, ['draft', 'calculated', 'reviewed'], true) && $proc) {
        echo hr_button_form('Delete run', ['action' => 'delete', 'run' => $runId], 'btn btn-danger', 'Delete this payroll run? Adjustments stay and can be used again.');
    }
    if (in_array($st, ['finalized', 'payslips'], true) && $proc) {
        echo hr_button_form($st === 'payslips' ? 'Generate missing payslips' : 'Generate payslips', ['action' => 'advance', 'run' => $runId, 'to' => 'payslips'], 'btn btn-primary');
    }
    echo '</div>';
    if ($st === 'approved' && $appr) {
        echo '<div class="hr-warn" style="margin-top:12px"><strong>Finalizing cannot be undone.</strong> The numbers are frozen, attendance for ' . e($label)
           . ' is locked, and leave or holidays in that month can no longer change. Later corrections go into a later month as adjustments.'
           . hr_form_open('', 'class="hr-filters" style="padding:10px 0 0"') . '<input type="hidden" name="action" value="advance"><input type="hidden" name="run" value="' . $runId . '"><input type="hidden" name="to" value="finalized">'
           . '<div class="grp"><label for="fc">Type FINALIZE to confirm</label><input id="fc" name="confirm" required autocomplete="off" pattern="[Ff][Ii][Nn][Aa][Ll][Ii][Zz][Ee]"></div>'
           . '<button class="btn btn-primary">Finalize payroll</button></form></div>';
    }
    if (in_array($st, ['finalized', 'payslips'], true) && $proc && $items) {
        echo hr_form_open('', 'class="hr-filters" style="padding:12px 0 0" data-confirm="Mark every unpaid, not-held employee as paid?"') . '<input type="hidden" name="action" value="paid_all"><input type="hidden" name="run" value="' . $runId . '">'
           . '<div class="grp"><label for="pd">Paid on</label><input id="pd" type="date" name="paid_on" value="' . date('Y-m-d') . '"></div>'
           . '<div class="grp"><label for="pr">Batch reference</label><input id="pr" name="ref" maxlength="120"></div><button class="btn btn-sm">Mark all as paid</button></form>'
           . '<p class="hr-hint">Payment is recorded for tracking. The portal does not transfer money.</p>';
    }
    echo '<p class="hr-hint">' . e(implode(' · ', array_filter([
        $run['calculated_at'] ? 'Calculated ' . date('d M H:i', strtotime($run['calculated_at'])) . ' by ' . $run['calculated_by'] : '',
        $run['reviewed_at'] ? 'Reviewed by ' . $run['reviewed_by'] : '', $run['approved_at'] ? 'Approved by ' . $run['approved_by'] : '',
        $run['finalized_at'] ? 'Finalized ' . date('d M Y', strtotime($run['finalized_at'])) . ' by ' . $run['finalized_by'] : '']))) . '</p></div>';

    echo '<div class="card"><h2>Employees</h2>';
    if (!$items) {
        echo hr_empty($st === 'draft' ? 'Not calculated yet.' : 'No employees in this period.', $st === 'draft' ? ' Press Calculate payroll.' : '');
    } else {
        echo '<div class="scrollx"><table class="data hr-table stack"><thead><tr><th>Employee</th><th class="num">Payable days</th><th class="num">LOP</th><th class="num">Gross</th>'
           . '<th class="num">Deductions</th><th class="num">Net pay</th><th>Checks</th><th>Payment</th><th></th></tr></thead><tbody>';
        foreach ($items as $it) {
            echo '<tr><td data-l="Employee">' . hr_person_cell(['id' => $it['employee_id'], 'full_name' => $it['full_name'], 'emp_code' => $it['emp_code']]) . '<div class="muted small">' . e($it['department'] ?? '') . '</div></td>'
               . '<td class="num" data-l="Payable">' . hr_fmt_days($it['payable_days']) . '/' . hr_fmt_days($it['divisor_days']) . '</td>'
               . '<td class="num" data-l="LOP">' . hr_fmt_days($it['lop_days']) . '</td><td class="num" data-l="Gross">' . e(fabo_money_exact($it['gross'])) . '</td>'
               . '<td class="num" data-l="Deductions">' . e(fabo_money_exact($it['deductions'])) . '</td><td class="num" data-l="Net"><strong>' . e(fabo_money_exact($it['net'])) . '</strong></td>'
               . '<td data-l="Checks">' . ($it['hold'] ? hr_badge('on hold', 'On hold') . ' ' : '') . ($it['warnings'] ? '<span class="pill amber" title="' . e($it['warnings']) . '">'
                    . count(explode("\n", $it['warnings'])) . ' warning(s)</span>' : '<span class="pill green">OK</span>') . '</td>'
               . '<td data-l="Payment">' . ($it['payslip_number'] ? '<div class="small">' . e($it['payslip_number']) . '</div>' : '') . e(ucfirst(str_replace('_', ' ', $it['payment_status']))) . '</td>'
               . '<td class="hr-acts"><a class="btn btn-sm" href="' . e(hr_url('payroll', ['run' => $runId, 'item' => $it['id']])) . '">Breakdown</a>';
            if (!in_array($st, ['finalized', 'payslips'], true) && $proc) {
                echo hr_button_form($it['hold'] ? 'Release' : 'Hold', ['action' => 'hold', 'run' => $runId, 'item' => $it['id'], 'hold' => $it['hold'] ? 0 : 1]);
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';
    hr_layout_end();
    return;
}

/* ----------------------------------------------------------------- tabs */

$tabs = ['runs' => 'Payroll runs', 'structures' => 'Salary structures', 'adjustments' => 'Adjustments & bonuses'];
$tab = hr_tab($tabs, 'runs');
$acts = hr_can('payroll', 'configure') || hr_can('sheets', 'configure') ? '<a class="btn btn-sm" href="' . e(hr_url('sheets', ['tab' => 'payroll'])) . '">Payroll rules & components</a>' : '';
hr_layout_start('Payroll Management', 'Monthly payroll from approved attendance, leave and each employee\'s salary structure. Every amount shows how it was worked out.',
                ['Payroll' => ''], $acts);
echo hr_flash_html($flash);
hr_tabs('payroll', $tabs, $tab);

if ($tab === 'runs') {
    if ($b = hr_payroll_blockers()) {
        echo '<div class="hr-warn"><strong>Before the first payroll:</strong><ul>';
        foreach ($b as $x) { echo '<li>' . e($x) . '</li>'; }
        echo '</ul></div>';
    }
    if (hr_setting('pay_cutoff_day')) {
        echo '<div class="hr-note">Payroll cut-off: day ' . (int) hr_setting('pay_cutoff_day') . ' of each month.</div>';
    }
    if (hr_can('payroll', 'process_payroll')) {
        $taken = array_column(hr_runs(60), 'period');
        $choices = array_diff_key(hr_month_choices(12), array_flip($taken));
        echo '<div class="card"><h2>Start a payroll run</h2>' . hr_form_open('', 'class="hr-filters" style="padding:0"') . '<input type="hidden" name="action" value="create">'
           . '<div class="grp"><label for="np">Month</label><select id="np" name="period">' . hr_options($choices, date('Y-m', strtotime('first day of last month'))) . '</select></div>'
           . '<button class="btn btn-primary">Create run</button></form></div>';
    }
    $runs = hr_runs();
    echo '<div class="card"><h2>Runs</h2>';
    if (!$runs) {
        echo hr_empty('No payroll yet.');
    } else {
        echo '<table class="data hr-table stack"><thead><tr><th>Month</th><th>Status</th><th class="num">Employees</th><th class="num">Gross</th><th class="num">Net pay</th><th class="num">Employer cost</th><th></th></tr></thead><tbody>';
        foreach ($runs as $r) {
            echo '<tr><td data-l="Month"><strong>' . e(date('F Y', strtotime($r['period'] . '-01'))) . '</strong></td><td data-l="Status">' . hr_badge($r['status'], hr_run_statuses()[$r['status']]) . '</td>'
               . '<td class="num" data-l="Employees">' . (int) $r['items'] . '</td><td class="num" data-l="Gross">' . e(fabo_money($r['gross_total'])) . '</td>'
               . '<td class="num" data-l="Net">' . e(fabo_money($r['net_total'])) . '</td><td class="num" data-l="Employer">' . e(fabo_money($r['employer_total'])) . '</td>'
               . '<td><a class="btn btn-sm" href="' . e(hr_url('payroll', ['run' => $r['id']])) . '">Open</a></td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';
}

if ($tab === 'structures') {
    $empId = (int) ($_GET['employee'] ?? 0);
    echo '<form method="get" class="card hr-filters"><input type="hidden" name="p" value="payroll"><input type="hidden" name="tab" value="structures">'
       . '<div class="grp"><label for="se">Employee</label><select id="se" name="employee" data-autosubmit>' . hr_options(hr_employee_choices(true, 'payroll', 'process_payroll'), $empId, 'Choose an employee…') . '</select></div>'
       . '<noscript><button class="btn btn-sm">Open</button></noscript></form>';
    if (!$empId) {
        // An overview of who has no structure.
        [$emps] = hr_employees_list(['status' => 'working'], 'name', 'asc', 0, 2000);
        $missing = array_filter($emps, static fn($e) => !hr_structure_on((int) $e['id'], date('Y-m-d')));
        echo '<div class="card"><h2>Employees without a salary structure (' . count($missing) . ')</h2>';
        if (!$missing) {
            echo hr_empty('Everyone current has a salary structure.');
        } else {
            echo '<ul class="hr-list">';
            foreach ($missing as $e) {
                echo '<li><span>' . e($e['full_name']) . ' <span class="muted small">' . e($e['emp_code']) . '</span></span><a class="btn btn-sm" href="'
                   . e(hr_url('payroll', ['tab' => 'structures', 'employee' => $e['id']])) . '">Set salary</a></li>';
            }
            echo '</ul>';
        }
        echo '</div>';
    } else {
        hr_need_emp($empId, 'payroll', 'process_payroll');
        $emp = hr_employee($empId);
        $cur = hr_structure_on($empId, date('Y-m-d'));
        $latest = hr_structures($empId)[0] ?? null;
        $latestLines = $latest ? hr_structure_lines((int) $latest['id']) : [];
        $byComp = [];
        foreach ($latestLines as $l) { $byComp[(int) $l['component_id']] = $l; }
        $comps = hr_components();
        echo '<div class="card"><h2>' . e($emp['full_name']) . ' — ' . ($latest ? 'revise salary' : 'set salary') . '</h2>';
        if ($latest) {
            echo '<p class="mt0">Current: <strong>' . e(fabo_money_exact($latest['monthly_gross'])) . '</strong> monthly gross from ' . e(hr_fmt_date($latest['effective_from']))
               . '. A new “effective from” date creates a revision and keeps the old one; the same date corrects it (allowed until a finalized payroll has used it).</p>';
        }
        if (!$comps) {
            echo '<div class="hr-warn">Add salary components first (Policies & Rules → Payroll).</div></div>';
        } else {
            echo hr_form_open('', 'class="hr-form"') . '<input type="hidden" name="action" value="structure"><input type="hidden" name="employee_id" value="' . $empId . '">'
               . '<div class="formgrid">' . hr_field('Effective from', hr_input('effective_from', $latest ? date('Y-m-01', strtotime('first day of next month')) : ($emp['date_of_joining'] ?: date('Y-m-d')), 'date', ['required' => true]), true)
               . hr_field('Monthly gross (₹)', hr_input('monthly_gross', $latest['monthly_gross'] ?? '', 'number', ['step' => '0.01', 'min' => 0]), false,
                          'Needed when a component takes the “balance of gross”.')
               . hr_field('Note', hr_input('note', '', 'text', ['maxlength' => 255, 'placeholder' => 'e.g. Annual increment']), false, '', 'full') . '</div>'
               . '<table class="data hr-table"><thead><tr><th>Use</th><th>Component</th><th>Type</th><th>Calculated as</th><th>Amount or %</th></tr></thead><tbody>';
            foreach ($comps as $c) {
                $cid = (int) $c['id'];
                $have = $byComp[$cid] ?? null;
                $use = $have !== null || (!$latest && in_array($c['calc'], ['balance'], true)) || (!$latest && $c['is_basic']);
                echo '<tr><td><input type="checkbox" name="use[' . $cid . ']" value="1"' . ($use ? ' checked' : '') . ' aria-label="Use ' . e($c['name']) . '"></td>'
                   . '<td><strong>' . e($c['name']) . '</strong> <span class="muted small">' . e($c['code']) . '</span></td><td>' . e(hr_component_kinds()[$c['kind']]) . '</td>'
                   . '<td>' . e(hr_component_calcs()[$c['calc']]) . '</td><td>'
                   . ($c['calc'] === 'balance' ? '<span class="muted">Worked out</span>'
                        : hr_input('value[' . $cid . ']', $have['value'] ?? $c['default_value'], 'number', ['step' => '0.01', 'min' => 0, 'aria-label' => $c['name'] . ' value']) . ' ' . ($c['calc'] === 'fixed' ? '₹ / month' : '%'))
                   . '</td></tr>';
            }
            echo '</tbody></table><div class="hr-formfoot"><button class="btn btn-primary" data-confirm="Save this salary structure for ' . e($emp['full_name']) . '?">Save salary structure</button></div></form></div>';
        }
        if ($cur) {
            echo '<p><a class="btn btn-sm" href="' . e(hr_url('employee', ['id' => $empId, 'tab' => 'payroll'])) . '">Revision history and payslips</a></p>';
        }
    }
}

if ($tab === 'adjustments') {
    if (hr_can('payroll', 'process_payroll')) {
        echo '<div class="card"><h2>Add a bonus, incentive, reimbursement or deduction</h2><p class="mt0 hr-hint">Included when that month\'s payroll is next calculated. '
           . 'A month that is already approved or finalized cannot take new adjustments — put a correction in the next open month.</p>'
           . hr_form_open('', 'class="hr-form"') . '<input type="hidden" name="action" value="adjust"><div class="formgrid">'
           . hr_field('Employee', hr_select('employee_id', hr_employee_choices(true, 'payroll', 'process_payroll'), '', 'Choose…', ['required' => true]), true)
           . hr_field('Payroll month', hr_select('period', hr_month_choices(3, 3), date('Y-m')), true)
           . hr_field('Kind', hr_select('kind', ['earning' => 'Adds to pay (bonus, incentive, arrears)', 'reimbursement' => 'Reimbursement (not taxable)', 'deduction' => 'Deduction (advance recovery, fine)'], 'earning'), true)
           . hr_field('Description', hr_input('label', '', 'text', ['required' => true, 'maxlength' => 120, 'placeholder' => 'e.g. Diwali bonus']), true)
           . hr_field('Amount (₹)', hr_input('amount', '', 'number', ['step' => '0.01', 'min' => '0.01', 'required' => true]), true)
           . hr_field('Reason', hr_input('reason', '', 'text', ['required' => true, 'maxlength' => 255]), true)
           . '</div><button class="btn btn-primary">Add adjustment</button></form></div>';
    }
    $rows = hr_adjustments();
    echo '<div class="card"><h2>Adjustments</h2>';
    if (!$rows) {
        echo hr_empty('No adjustments yet.');
    } else {
        echo '<table class="data hr-table stack"><thead><tr><th>Month</th><th>Employee</th><th>Description</th><th>Kind</th><th class="num">Amount</th><th>Reason</th><th>Used in</th><th></th></tr></thead><tbody>';
        foreach ($rows as $a) {
            $locked = $a['run_status'] && !in_array($a['run_status'], ['draft', 'calculated', 'reviewed'], true);
            echo '<tr><td data-l="Month">' . e(date('M Y', strtotime($a['period'] . '-01'))) . '</td><td data-l="Employee">' . e($a['full_name']) . '</td><td data-l="Description">' . e($a['label']) . '</td>'
               . '<td data-l="Kind">' . e(ucfirst($a['kind'])) . '</td><td class="num" data-l="Amount">' . e(fabo_money_exact($a['amount'])) . '</td><td data-l="Reason" class="small">' . e($a['reason']) . '</td>'
               . '<td data-l="Used in">' . ($a['run_period'] ? hr_badge($a['run_status'], hr_run_statuses()[$a['run_status']]) : '<span class="muted">Not yet</span>') . '</td><td>'
               . (!$locked && hr_can('payroll', 'process_payroll') ? hr_button_form('Remove', ['action' => 'adjust_delete', 'id' => $a['id']], 'btn btn-sm btn-danger', 'Remove this adjustment?') : '') . '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';
}

hr_layout_end();

<?php
/**
 * Leave Management — requests, approvals, applying, the leave calendar,
 * balances and the year-end close.
 */

require_once __DIR__ . '/leave_parts.php';

$self    = hr_self_emp();
$privileged = hr_scope('leave', 'create') === 'org' || hr_is_admin();
$flash   = null;
$old     = [];
$reqId   = (int) ($_GET['id'] ?? 0);

/* --------------------------------------------------------------- actions */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    $rid = (int) ($_POST['id'] ?? 0);
    $back = $rid ? hr_url('leave', ['id' => $rid]) : hr_url('leave', ['tab' => (string) ($_GET['tab'] ?? 'requests')]);
    if ($a === 'apply') {
        hr_need('leave', 'create');
        $submit = !empty($_POST['submit']);
        $old = $_POST;
        $flash = hr_action(static function () use ($submit, $privileged) {
            $empId = (int) ($_POST['employee_id'] ?? 0);
            $own = ($s = hr_self_emp()) && (int) $s['id'] === $empId;
            $id = hr_leave_create($_POST, $submit, $_FILES['attachment'] ?? null, $privileged && !$own);
            hr_redirect(hr_url('leave', ['id' => $id]), ['ok', $submit ? 'Request submitted. You will be notified of the decision.' : 'Saved as a draft. Submit it when ready.']);
        }, $back);
    } elseif ($a === 'submit') {
        $flash = hr_action(static function () use ($rid, $privileged) { hr_leave_submit($rid, $privileged); return 'Request submitted.'; }, $back);
    } elseif ($a === 'decide') {
        hr_need('leave', 'approve');
        $flash = hr_action(static function () use ($rid) {
            $r = hr_leave_decide($rid, (string) ($_POST['decision'] ?? ''), (string) ($_POST['comment'] ?? ''));
            return ['approved' => 'Approved. The days were deducted from the balance.', 'rejected' => 'Rejected. Nothing was deducted.',
                    'level' => 'Approved at this level. It now waits for the next approver.'][$r];
        }, (string) ($_POST['back'] ?? '') === 'approvals' ? hr_url('leave', ['tab' => 'approvals']) : $back);
    } elseif ($a === 'cancel') {
        hr_need('leave', 'view');
        $flash = hr_action(static function () use ($rid) {
            $r = hr_leave_request($rid);
            if (!$r) { throw new InvalidArgumentException('That request no longer exists.'); }
            $own = ($s = hr_self_emp()) && (int) $s['id'] === (int) $r['employee_id'];
            $priv = !$own && (hr_emp_allowed((int) $r['employee_id'], 'leave', 'approve') || hr_emp_allowed((int) $r['employee_id'], 'leave', 'edit'));
            hr_leave_cancel($rid, (string) ($_POST['comment'] ?? ''), $priv || hr_is_admin());
            return $r['status'] === 'approved' ? 'Cancelled. The days were returned to the balance.' : 'Cancelled.';
        }, $back);
    } elseif ($a === 'correct') {
        $flash = hr_action(static function () use ($rid) {
            hr_leave_correct($rid, $_POST, (string) ($_POST['comment'] ?? ''));
            return 'Corrected. The old days were returned and the new days deducted.';
        }, $back);
    } elseif ($a === 'year_close') {
        if (!hr_is_admin() && !hr_can('leave', 'configure')) {
            fabo_deny('You cannot close a leave year.', 403);
        }
        $flash = hr_action(static function () {
            [$n, $wait] = hr_leave_year_close((int) ($_POST['year'] ?? 0));
            return $n . ' balance(s) closed and carried forward.' . ($wait ? ' ' . $wait . ' were skipped because they still have pending requests — decide those, then close again.' : '');
        }, hr_url('leave', ['tab' => 'yearend', 'year' => (int) ($_POST['year'] ?? 0)]));
    }
}

/* ---------------------------------------------------------- one request */

if ($reqId) {
    $r = hr_leave_request($reqId);
    if (!$r || (!hr_emp_allowed((int) $r['employee_id'], 'leave', 'view') && !hr_leave_is_named_approver($r))) {
        fabo_deny('That leave request is not available.', 404);
    }
    $own = $self && (int) $self['id'] === (int) $r['employee_id'];
    hr_layout_start('Leave request #' . $reqId, e($r['full_name']) . ' (' . e($r['emp_code']) . ') · ' . e($r['type_name']),
                    ['Leave Management' => hr_url('leave'), 'Request #' . $reqId => '']);
    echo hr_flash_html($flash);
    $emp = hr_employee((int) $r['employee_id']);
    $rule = hr_emp_rule($emp, (int) $r['leave_type_id'], $r['start_date']);
    $cnt = hr_leave_count($emp, $rule, $r['start_date'], $r['end_date'], (bool) $r['start_half'], (bool) $r['end_half']);
    echo '<div class="hr-two"><div class="card"><h2>Details</h2><dl class="hr-kv">'
       . '<dt>Status</dt><dd>' . hr_badge($r['status'], hr_leave_statuses()[$r['status']]) . ((int) $r['levels_needed'] > 1 && $r['status'] === 'submitted'
            ? ' <span class="muted small">approved at ' . (int) $r['level_done'] . ' of ' . (int) $r['levels_needed'] . ' levels</span>' : '') . '</dd>'
       . '<dt>Employee</dt><dd>' . hr_person_cell(['id' => $r['employee_id'], 'full_name' => $r['full_name'], 'emp_code' => $r['emp_code']]) . '</dd>'
       . '<dt>Leave type</dt><dd>' . e($r['type_name']) . ($r['is_paid'] ? '' : ' (unpaid)') . '</dd>'
       . '<dt>From</dt><dd>' . e(hr_fmt_date($r['start_date'])) . ($r['start_half'] && $r['start_date'] !== $r['end_date'] ? ' (second half)' : '') . '</dd>'
       . '<dt>To</dt><dd>' . e(hr_fmt_date($r['end_date'])) . ($r['end_half'] ? ' (first half)' : '') . ($r['half_session'] ? ' — ' . ($r['half_session'] === 'first' ? 'first half' : 'second half') . ' only' : '') . '</dd>'
       . '<dt>Leave days</dt><dd><strong>' . hr_fmt_days($r['days']) . '</strong>' . ($cnt['skipped'] ? ' <span class="muted small">(' . $cnt['skipped'] . ' weekly off / holiday day(s) not counted)</span>' : '') . '</dd>'
       . '<dt>Reason</dt><dd>' . nl2br(e($r['reason'] ?? '—')) . '</dd>'
       . '<dt>Attachment</dt><dd>' . ($r['attachment_file'] ? '<a href="' . e(hr_url('file', ['kind' => 'leave_att', 'id' => $reqId])) . '">' . e($r['attachment_name']) . '</a>' : '—') . '</dd>'
       . '<dt>Submitted</dt><dd>' . e($r['submitted_at'] ? date('d M Y H:i', strtotime($r['submitted_at'])) : 'Not yet (draft)') . '</dd>'
       . ($r['decision_comment'] ? '<dt>Comment</dt><dd>' . e($r['decision_comment']) . '</dd>' : '')
       . '</dl><details style="margin-top:10px"><summary class="btn btn-sm">Day-by-day</summary><ul class="hr-list">';
    foreach ($cnt['detail'] as $d => $what) {
        echo '<li><span>' . e(date('D j M Y', strtotime($d))) . '</span><span class="muted">' . e($what) . '</span></li>';
    }
    echo '</ul></details></div>';

    echo '<div class="card"><h2>What happens next</h2>';
    $bal = hr_leave_balances($emp, hr_leave_year_of($r['start_date']))[(int) $r['leave_type_id']] ?? null;
    if ($bal && !$bal['type']['unlimited']) {
        echo '<p class="mt0">' . e($r['type_name']) . ' balance now: <strong>' . hr_fmt_days($bal['available']) . ' day(s) available</strong>'
           . ($bal['pending'] ? ' (' . hr_fmt_days($bal['pending']) . ' pending, including this request if it is waiting)' : '') . '.</p>';
    }
    if (hr_leave_can_decide($r)) {
        echo hr_form_open('', 'class="hr-form"') . '<input type="hidden" name="action" value="decide"><input type="hidden" name="id" value="' . $reqId . '">'
           . hr_field('Comment', '<textarea name="comment" rows="2" maxlength="500" placeholder="Required when rejecting"></textarea>')
           . '<div class="hr-formfoot"><button class="btn btn-approve" name="decision" value="approve">Approve</button>'
           . '<button class="btn btn-reject" name="decision" value="reject">Reject</button></div></form>';
    }
    if ($r['status'] === 'draft' && ($own || hr_emp_allowed((int) $r['employee_id'], 'leave', 'create'))) {
        echo '<p>' . hr_button_form('Submit for approval', ['action' => 'submit', 'id' => $reqId], 'btn btn-primary') . '</p>';
    }
    $mayCancel = in_array($r['status'], ['draft', 'submitted', 'approved'], true)
              && (($own && hr_can('leave', 'edit') && ($r['status'] !== 'approved' || $r['start_date'] > date('Y-m-d')))
                  || (!$own && (hr_emp_allowed((int) $r['employee_id'], 'leave', 'approve') || hr_emp_allowed((int) $r['employee_id'], 'leave', 'edit'))));
    if ($mayCancel) {
        echo '<details style="margin-top:10px"><summary class="btn btn-sm btn-danger">Cancel this request</summary>' . hr_form_open('', 'class="hr-form" data-confirm="Cancel this leave request?"')
           . '<input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="' . $reqId . '">'
           . hr_field('Reason', '<textarea name="comment" rows="2" maxlength="500"' . ($r['status'] === 'approved' ? ' required' : '') . '></textarea>', $r['status'] === 'approved',
                      $r['status'] === 'approved' ? 'The ' . hr_fmt_days($r['days']) . ' day(s) go back to the balance.' : '')
           . '<button class="btn btn-danger">Cancel request</button></form></details>';
    }
    if ($r['status'] === 'approved' && hr_emp_allowed((int) $r['employee_id'], 'leave', 'approve') && !$own) {
        echo '<details style="margin-top:10px"><summary class="btn btn-sm">Correct dates</summary>' . hr_form_open('', 'class="hr-form" id="hr-leave-form"')
           . '<input type="hidden" name="action" value="correct"><input type="hidden" name="id" value="' . $reqId . '">'
           . '<input type="hidden" name="employee_id" value="' . (int) $r['employee_id'] . '"><input type="hidden" name="leave_type_id" value="' . (int) $r['leave_type_id'] . '">'
           . '<div class="formgrid">' . hr_field('From', hr_input('start_date', $r['start_date'], 'date', ['required' => true]), true)
           . hr_field('To', hr_input('end_date', $r['end_date'], 'date')) . '</div><div class="formgrid"><div class="field" data-oneday><label>For that day</label>'
           . hr_select('day_type', ['full' => 'Full day', 'first' => 'First half', 'second' => 'Second half'], $r['half_session'] ?: 'full') . '</div>'
           . '<div class="field" data-manyday hidden>' . hr_checkbox('start_half', (bool) $r['start_half'], 'Start from the second half') . hr_checkbox('end_half', (bool) $r['end_half'], 'End after the first half') . '</div></div>'
           . '<div id="hr-leave-preview" class="hr-note" hidden aria-live="polite"></div>'
           . hr_field('Reason for the correction', '<textarea name="comment" rows="2" required maxlength="500"></textarea>', true)
           . '<button class="btn btn-primary" data-confirm="Correct this approved leave? The balance is adjusted once.">Save correction</button></form></details>';
    }
    echo '</div></div>';

    $chain = hr_leave_chain($r);
    echo '<div class="card"><h2>Approval steps</h2><p class="mt0 hr-hint">The leave is granted only after every step approves.</p><ol class="hr-steps">';
    foreach ($chain as $i => $st) {
        $state = $r['status'] === 'approved' || $i < (int) $r['level_done'] ? 'done'
               : ($r['status'] === 'submitted' && $i === (int) $r['level_done'] ? 'now' : '');
        $as = ['manager' => 'Reporting manager', 'skip' => 'Manager\'s manager', 'person' => 'Named approver', 'hr' => 'HR', 'admin' => 'Administrator'][$st['as'] ?? $st['type']] ?? '';
        echo '<li class="' . $state . '">' . e($st['label']) . ($as !== '' && $as !== $st['label'] ? '<div class="small">' . e($as) . '</div>' : '')
           . ($state === 'now' ? '<div class="small">Waiting</div>' : '') . '</li>';
    }
    echo '</ol>' . (in_array($r['status'], ['rejected', 'cancelled'], true) ? '<p class="muted">This request was ' . e($r['status']) . '.</p>' : '') . '</div>';

    echo '<div class="card"><h2>Approval history</h2><ul class="hr-list">';
    foreach (hr_leave_actions($reqId) as $act) {
        echo '<li><span><strong>' . e(ucfirst($act['action'])) . '</strong>' . ($act['level'] ? ' (level ' . (int) $act['level'] . ')' : '') . ' by ' . e($act['actor_label'])
           . ($act['comment'] ? ' — ' . e($act['comment']) : '') . '</span><span class="muted">' . e(date('d M Y H:i', strtotime($act['created_at']))) . '</span></li>';
    }
    echo '</ul></div>';
    hr_layout_end();
    return;
}

/* ----------------------------------------------------------------- tabs */

$tabs = ['requests' => 'Requests'];
if (hr_can('leave', 'approve')) {
    $n = count(hr_leave_awaiting_me());
    $tabs['approvals'] = 'Approvals' . ($n ? ' <span class="badge">' . $n . '</span>' : '');
}
if (hr_can('leave', 'create')) { $tabs['apply'] = $privileged ? 'Record leave' : 'Apply for leave'; }
$tabs['calendar'] = 'Leave calendar';
$tabs['balances'] = 'Balances';
if (hr_is_admin() || (hr_can('leave', 'configure') && hr_scope('leave', 'configure') === 'org')) { $tabs['yearend'] = 'Year-end close'; }
$tab = hr_tab($tabs, isset($tabs['approvals']) && !empty($n) ? 'approvals' : 'requests');

$acts = hr_can('leave', 'create') ? '<a class="btn btn-sm btn-primary" href="' . e(hr_url('leave', ['tab' => 'apply'])) . '">' . ($privileged ? '+ Record leave' : '+ Apply for leave') . '</a>' : '';
if (hr_can('leave', 'configure') || hr_can('sheets', 'configure')) {
    $acts .= '<a class="btn btn-sm" href="' . e(hr_url('sheets', ['tab' => 'leave'])) . '#chain">Approval steps</a>'
           . '<a class="btn btn-sm" href="' . e(hr_url('sheets', ['tab' => 'leave'])) . '">Leave policies</a>';
}
hr_layout_start('Leave Management', 'Apply, approve and track leave. Balances update automatically: approval deducts once, cancellation gives the days back.',
                ['Leave Management' => ''], $acts);
echo hr_flash_html($flash);
hr_tabs('leave', $tabs, $tab);

if ($tab === 'requests') {
    $f = ['status' => (string) ($_GET['status'] ?? ''), 'type' => (int) ($_GET['type'] ?? 0), 'department' => (int) ($_GET['department'] ?? 0),
          'employee' => (int) ($_GET['employee'] ?? 0), 'from' => hr_date($_GET['from'] ?? '') ?? '', 'to' => hr_date($_GET['to'] ?? '') ?? '', 'q' => trim((string) ($_GET['q'] ?? ''))];
    $q = array_filter($f) + ['tab' => 'requests'];
    [, $total] = hr_leave_list($f, 0, 1);
    [$offset, $pager] = hr_pager($total, 30, 'leave', $q);
    [$rows] = hr_leave_list($f, $offset, 30);
    $types = [];
    foreach (hr_leave_types(false) as $t) { $types[(int) $t['id']] = $t['name']; }
    echo '<form method="get" class="card hr-filters"><input type="hidden" name="p" value="leave"><input type="hidden" name="tab" value="requests">'
       . '<div class="grp"><label for="lq">Search</label><input id="lq" type="search" name="q" value="' . e($f['q']) . '" placeholder="Name or employee ID"></div>'
       . '<div class="grp"><label for="ls">Status</label><select id="ls" name="status" data-autosubmit>' . hr_options(hr_leave_statuses(), $f['status'], 'All') . '</select></div>'
       . '<div class="grp"><label for="lt">Leave type</label><select id="lt" name="type" data-autosubmit>' . hr_options($types, $f['type'], 'All') . '</select></div>'
       . '<div class="grp"><label for="ld">Department</label><select id="ld" name="department" data-autosubmit>' . hr_options(hr_departments_map(false), $f['department'], 'All') . '</select></div>'
       . '<div class="grp"><label for="lf">From</label><input id="lf" type="date" name="from" value="' . e($f['from']) . '"></div>'
       . '<div class="grp"><label for="lto">To</label><input id="lto" type="date" name="to" value="' . e($f['to']) . '"></div>'
       . '<button class="btn btn-sm btn-primary">Filter</button>' . (count($q) > 1 ? '<a class="btn btn-sm" href="' . e(hr_url('leave', ['tab' => 'requests'])) . '">Clear</a>' : '')
       . (hr_report_allowed('leave_requests') ? '<a class="btn btn-sm" href="' . e(hr_url('file', ['kind' => 'report', 'report' => 'leave_requests', 'fmt' => 'xlsx',
            'from' => $f['from'] ?: date('Y-01-01'), 'to' => $f['to'] ?: date('Y-12-31'), 'status' => $f['status'], 'leave_type' => $f['type'], 'department' => $f['department']])) . '">Export</a>' : '')
       . '</form><div class="card">';
    hr_render_leave_rows($rows);
    echo $pager . '</div>';
}

if ($tab === 'approvals') {
    $wait = hr_leave_awaiting_me();
    echo '<div class="card"><h2>Waiting for your decision (' . count($wait) . ')</h2>';
    if (!$wait) {
        echo hr_empty('Nothing is waiting for you.', ' New requests appear here and in your notifications.');
    } else {
        echo '<div class="scrollx"><table class="data hr-table stack"><thead><tr><th>Employee</th><th>Leave</th><th>Dates</th><th class="num">Days</th><th>Balance after</th><th>Reason</th><th>Decision</th></tr></thead><tbody>';
        foreach ($wait as $r) {
            $emp = hr_employee((int) $r['employee_id']);
            $b = hr_leave_balances($emp, hr_leave_year_of($r['start_date']))[(int) $r['leave_type_id']] ?? null;
            $after = $b && !$b['type']['unlimited'] ? hr_fmt_days((float) $b['balance'] - (float) $r['days']) . ' day(s)' : '—';
            $overlap = (int) (fabo_one("SELECT COUNT(*) n FROM hr_leave_requests r JOIN hr_employees e ON e.id = r.employee_id WHERE r.org_id = ? AND r.status = 'approved'
                                        AND e.department_id <=> ? AND r.start_date <= ? AND r.end_date >= ? AND r.employee_id <> ?", 'iissi',
                                       [hr_org(), $emp['department_id'], $r['end_date'], $r['start_date'], (int) $r['employee_id']])['n'] ?? 0);
            echo '<tr><td data-l="Employee">' . hr_person_cell(['id' => $r['employee_id'], 'full_name' => $r['full_name'], 'emp_code' => $r['emp_code']]) . '</td>'
               . '<td data-l="Leave">' . e($r['type_name']) . '</td><td data-l="Dates">' . e(hr_fmt_date($r['start_date']) . ($r['end_date'] !== $r['start_date'] ? ' – ' . hr_fmt_date($r['end_date']) : ''))
               . ($overlap ? '<div class="muted small">' . $overlap . ' colleague(s) in the department also off</div>' : '') . '</td>'
               . '<td class="num" data-l="Days">' . hr_fmt_days($r['days']) . '</td><td data-l="Balance after">' . e($after) . '</td>'
               . '<td data-l="Reason" class="small">' . e(mb_strimwidth((string) $r['reason'], 0, 80, '…')) . '</td><td>'
               . hr_form_open('', 'class="hr-acts"') . '<input type="hidden" name="action" value="decide"><input type="hidden" name="back" value="approvals"><input type="hidden" name="id" value="' . (int) $r['id'] . '">'
               . '<input type="text" name="comment" placeholder="Comment (needed to reject)" maxlength="500" aria-label="Comment" style="min-width:150px">'
               . '<button class="btn btn-sm btn-approve" name="decision" value="approve">Approve</button><button class="btn btn-sm btn-reject" name="decision" value="reject">Reject</button>'
               . '<a class="btn btn-sm" href="' . e(hr_url('leave', ['id' => $r['id']])) . '">Details</a></form></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';
}

if ($tab === 'apply') {
    hr_need('leave', 'create');
    $scope = hr_scope('leave', 'create');
    $choices = $scope === 'self' ? [] : hr_employee_choices(true, 'leave', 'create');
    if ($scope === 'self' && !$self) {
        fabo_deny('Your account is not linked to an employee record.', 403);
    }
    $empId = (int) ($_GET['employee'] ?? ($self['id'] ?? 0));
    echo '<div class="card"><h2>' . ($privileged ? 'Record or apply for leave' : 'Apply for leave') . '</h2>'
       . '<p class="mt0 hr-hint">The number of leave days is worked out for you: weekly offs and holidays inside the dates are not counted unless the policy says they are.</p>';
    hr_render_leave_form($choices, $empId, $old);
    echo '</div>';
    if ($self && ($scope === 'self' || $empId === (int) $self['id'])) {
        hr_render_balance_cards(hr_leave_balances($self));
    }
}

if ($tab === 'calendar') {
    $month = hr_month_param();
    $from = $month . '-01';
    $to = date('Y-m-t', strtotime($from));
    $dept = (int) ($_GET['department'] ?? 0);
    $emps = hr_att_employees(['department' => $dept], $from, $to);
    $emps = array_values(array_filter($emps, static fn($e) => hr_emp_allowed((int) $e['id'], 'leave', 'view')));
    $leaves = hr_approved_leave_days(array_map(static fn($e) => (int) $e['id'], $emps), $from, $to);
    $pending = [];
    foreach (fabo_all("SELECT employee_id, start_date, end_date FROM hr_leave_requests WHERE org_id = ? AND status = 'submitted' AND start_date <= ? AND end_date >= ?",
                      'iss', [hr_org(), $to, $from]) as $p) {
        for ($d = max($p['start_date'], $from); $d <= min($p['end_date'], $to); $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $pending[(int) $p['employee_id']][$d] = true;
        }
    }
    echo '<form method="get" class="card hr-filters"><input type="hidden" name="p" value="leave"><input type="hidden" name="tab" value="calendar">'
       . '<div class="grp"><label for="cm">Month</label><select id="cm" name="month" data-autosubmit>' . hr_options(hr_month_choices(12, 6), $month) . '</select></div>'
       . '<div class="grp"><label for="cd">Department</label><select id="cd" name="department" data-autosubmit>' . hr_options(hr_departments_map(), $dept, 'All') . '</select></div></form>';
    echo '<div class="hr-legend"><span><b class="st-L">CL</b> Approved leave (code)</span><span><b class="st-HD">½</b> Half day</span><span><b class="st-MP">?</b> Pending</span>'
       . '<span><b class="st-H">H</b> Holiday</span><span><b class="st-WO">WO</b> Weekly off</span></div>';
    if (!$emps) {
        echo '<div class="card">' . hr_empty('No employees to show.') . '</div>';
    } else {
        echo '<div class="card scrollx" style="padding:0"><table class="hr-cal"><thead><tr><th class="nm">Employee</th>';
        for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            echo '<th' . (in_array((int) date('w', strtotime($d)), [0, 6], true) ? ' class="we"' : '') . ' title="' . e(date('l j F', strtotime($d))) . '">'
               . date('j', strtotime($d)) . '<br><span class="muted">' . substr(date('D', strtotime($d)), 0, 2) . '</span></th>';
        }
        echo '<th>Days</th></tr></thead><tbody>';
        foreach ($emps as $e) {
            $map = hr_day_map($e, $from, $to);
            $tot = 0;
            echo '<tr><td class="nm">' . e($e['full_name']) . '</td>';
            foreach ($map as $d => $info) {
                $l = $leaves[(int) $e['id']][$d] ?? null;
                if ($l) {
                    $tot += $l['half'] ? 0.5 : 1;
                    echo '<td class="c ' . ($l['half'] ? 'st-HD' : 'st-L') . '" title="' . e($l['name'] . ($l['half'] ? ' (half day)' : '')) . '"><a href="' . e(hr_url('leave', ['id' => $l['request_id']])) . '">'
                       . e($l['half'] ? '½' : $l['code']) . '</a></td>';
                } elseif (!empty($pending[(int) $e['id']][$d])) {
                    echo '<td class="c st-MP" title="Pending request">?</td>';
                } elseif ($info['holiday']) {
                    echo '<td class="c st-H" title="' . e($info['holiday']) . '">H</td>';
                } elseif ($info['off']) {
                    echo '<td class="c st-WO" title="Weekly off">·</td>';
                } else {
                    echo '<td class="c"></td>';
                }
            }
            echo '<td class="tot">' . hr_fmt_days($tot) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
}

if ($tab === 'balances') {
    $scope = hr_scope('leave', 'view');
    $year = (int) ($_GET['year'] ?? 0) ?: hr_leave_year_of(date('Y-m-d'));
    $years = [];
    for ($y = hr_leave_year_of(date('Y-m-d')) + 1; $y >= hr_leave_year_of(date('Y-m-d')) - 4; $y--) { $years[$y] = hr_leave_year_label($y); }
    $empId = (int) ($_GET['employee'] ?? 0);
    if ($scope === 'self') {
        $empId = (int) ($self['id'] ?? 0);
    }
    echo '<form method="get" class="card hr-filters"><input type="hidden" name="p" value="leave"><input type="hidden" name="tab" value="balances">'
       . ($scope !== 'self' ? '<div class="grp"><label for="be">Employee</label><select id="be" name="employee" data-autosubmit>' . hr_options(hr_employee_choices(true, 'leave'), $empId, 'Everyone (summary)') . '</select></div>' : '')
       . '<div class="grp"><label for="by">Leave year</label><select id="by" name="year" data-autosubmit>' . hr_options($years, $year) . '</select></div>'
       . (hr_report_allowed('leave_balance') ? '<a class="btn btn-sm" href="' . e(hr_url('file', ['kind' => 'report', 'report' => 'leave_balance', 'fmt' => 'xlsx', 'year' => $year])) . '">Export all balances</a>' : '')
       . '</form>';
    if ($empId) {
        hr_need_emp($empId, 'leave', 'view');
        $emp = hr_employee($empId);
        echo '<h2 style="font-size:15px">' . e($emp['full_name']) . ' — ' . e(hr_leave_year_label($year)) . '</h2>';
        $bal = hr_leave_balances($emp, $year);
        hr_render_balance_cards($bal);
        hr_render_balance_table($bal);
        echo '<p><a class="btn btn-sm" href="' . e(hr_url('employee', ['id' => $empId, 'tab' => 'leave', 'year' => $year])) . '">Ledger and adjustments</a></p>';
    } else {
        [$emps] = hr_employees_list(['status' => 'working'], 'name', 'asc', 0, 500);
        $emps = array_values(array_filter($emps, static fn($e) => hr_emp_allowed((int) $e['id'], 'leave', 'view')));
        $types = array_values(array_filter(hr_leave_types(), static fn($t) => !$t['unlimited']));
        echo '<div class="card"><h2>Available balance by employee</h2>';
        if (!$emps || !$types) {
            echo hr_empty(!$types ? 'No leave types with balances yet.' : 'No employees.');
        } else {
            echo '<div class="scrollx"><table class="data hr-table"><thead><tr><th>Employee</th>';
            foreach ($types as $t) { echo '<th class="num" title="' . e($t['name']) . '">' . e($t['code']) . '</th>'; }
            echo '</tr></thead><tbody>';
            foreach ($emps as $e) {
                $bal = hr_leave_balances($e, $year);
                echo '<tr><td><a href="' . e(hr_url('leave', ['tab' => 'balances', 'employee' => $e['id'], 'year' => $year])) . '">' . e($e['full_name']) . '</a> <span class="muted small">' . e($e['emp_code']) . '</span></td>';
                foreach ($types as $t) {
                    $b = $bal[(int) $t['id']] ?? null;
                    echo '<td class="num">' . ($b ? ((float) $b['available'] < 0 ? '<span class="req">' . hr_fmt_days($b['available']) . '</span>' : hr_fmt_days($b['available'])) : '<span class="muted">—</span>') . '</td>';
                }
                echo '</tr>';
            }
            echo '</tbody></table></div><p class="hr-hint">Days available to take now (pending requests held). — means the type is not in the employee\'s policy.</p>';
        }
        echo '</div>';
    }
}

if ($tab === 'yearend') {
    $year = (int) ($_GET['year'] ?? 0) ?: hr_leave_year_of(date('Y-m-d')) - 1;
    [, $yTo] = hr_leave_year_bounds($year);
    $years = [];
    for ($y = hr_leave_year_of(date('Y-m-d')); $y >= hr_leave_year_of(date('Y-m-d')) - 4; $y--) { $years[$y] = hr_leave_year_label($y); }
    echo '<form method="get" class="card hr-filters"><input type="hidden" name="p" value="leave"><input type="hidden" name="tab" value="yearend">'
       . '<div class="grp"><label for="yy">Leave year to close</label><select id="yy" name="year" data-autosubmit>' . hr_options($years, $year) . '</select></div></form>';
    $prev = hr_leave_year_close_preview($year);
    echo '<div class="card"><h2>Preview — closing ' . e(hr_leave_year_label($year)) . ' (ended ' . e(hr_fmt_date($yTo)) . ')</h2>'
       . '<p class="mt0">Unused days carry forward up to each policy\'s limit; the rest lapse. Negative balances carry forward as negative. Nothing is posted until you press Close. '
       . 'Encashable days are listed so they can be paid through a payroll adjustment.</p>';
    if (!$prev) {
        echo hr_empty('No balances to close.');
    } else {
        echo '<div class="scrollx"><table class="data hr-table stack"><thead><tr><th>Employee</th><th>Leave type</th><th class="num">Closing</th><th class="num">Carry forward</th>'
           . '<th class="num">Lapses</th><th class="num">Encashable</th><th>State</th></tr></thead><tbody>';
        foreach ($prev as $p) {
            echo '<tr><td data-l="Employee">' . e($p['emp']['full_name']) . '</td><td data-l="Type">' . e($p['type']['name']) . '</td>'
               . '<td class="num" data-l="Closing">' . hr_fmt_days($p['closing']) . '</td><td class="num" data-l="Carry">' . hr_fmt_days($p['carry']) . '</td>'
               . '<td class="num" data-l="Lapses">' . hr_fmt_days($p['lapse']) . '</td><td class="num" data-l="Encashable">' . hr_fmt_days($p['encashable']) . '</td>'
               . '<td data-l="State">' . ($p['done'] ? hr_badge('finalized', 'Closed') : ($p['pending'] ? hr_badge('pending', 'Pending requests') : hr_badge('draft', 'Ready'))) . '</td></tr>';
        }
        echo '</tbody></table></div>';
        if ($yTo < date('Y-m-d')) {
            echo hr_form_open('', 'style="margin-top:12px" data-confirm="Close leave year ' . e(hr_leave_year_label($year)) . '? Carry-forward and lapses are posted once and cannot be undone except by manual adjustment."')
               . '<input type="hidden" name="action" value="year_close"><input type="hidden" name="year" value="' . $year . '"><button class="btn btn-primary">Close ' . e(hr_leave_year_label($year)) . '</button></form>';
        } else {
            echo '<p class="muted">This leave year has not ended yet; it can be closed after ' . e(hr_fmt_date($yTo)) . '.</p>';
        }
    }
    echo '</div>';
}

hr_layout_end();

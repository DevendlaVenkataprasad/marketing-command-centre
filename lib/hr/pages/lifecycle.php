<?php
/**
 * Onboarding & Exits — joiners' checklists, resignations and clearance, the
 * tasks everyone owes, and the checklists themselves.
 *
 *   ?emp=ID   one employee's onboarding and exit
 *   ?sep=ID   one exit, with its decision, clearance and letters
 */

require_once __DIR__ . '/parts.php';

$self   = hr_self_emp();
$hrDesk = hr_is_admin() || hr_scope('lifecycle', 'view') !== 'self';
$orgEdit = hr_is_admin() || hr_scope('lifecycle', 'edit') === 'org';
$canCfg = hr_is_admin() || hr_scope('lifecycle', 'configure') === 'org';
$flash  = null;

/* --------------------------------------------------------------- actions */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    $back = (string) ($_POST['back'] ?? '');
    $back = preg_match('/^hr\.php\?p=lifecycle[&A-Za-z0-9=_%-]*$/', $back) ? $back : hr_url('lifecycle', ['tab' => (string) ($_GET['tab'] ?? '')]);
    if (in_array($a, ['settings_apply'], true)) {
        $flash = hr_parts_post($back);
    } elseif ($a === 'start') {
        $empId = (int) ($_POST['employee_id'] ?? 0);
        hr_need_emp($empId, 'lifecycle', 'create');
        if (!$orgEdit) { fabo_deny('Only HR starts a checklist.', 403); }
        $flash = hr_action(static function () use ($empId) {
            $kind = ($_POST['kind'] ?? '') === 'offboarding' ? 'offboarding' : 'onboarding';
            $n = hr_lifecycle_start($empId, $kind, (int) ($_POST['checklist_id'] ?? 0) ?: null,
                                    null, hr_date($_POST['base'] ?? '', false, 'Start date'));
            return $n . ' task(s) added.';
        }, $back);
    } elseif ($a === 'task') {
        $flash = hr_action(static function () {
            hr_task_set((int) ($_POST['id'] ?? 0), (string) ($_POST['status'] ?? ''), (string) ($_POST['note'] ?? ''));
            return ['done' => 'Marked done.', 'skipped' => 'Marked not needed.', 'pending' => 'Reopened.'][$_POST['status']] ?? 'Saved.';
        }, $back);
    } elseif ($a === 'task_add') {
        $empId = (int) ($_POST['employee_id'] ?? 0);
        hr_need_emp($empId, 'lifecycle', 'edit');
        if (!$orgEdit) { fabo_deny('Only HR adds tasks.', 403); }
        $flash = hr_action(static function () use ($empId) { hr_task_add($empId, (string) ($_POST['kind'] ?? ''), $_POST); return 'Task added.'; }, $back);
    } elseif ($a === 'resign') {
        hr_need('lifecycle', 'create');
        $flash = hr_action(static function () {
            $empId = (int) ($_POST['employee_id'] ?? 0);
            $id = hr_separation_submit($empId, $_POST);
            hr_redirect(hr_url('lifecycle', ['sep' => $id]), ['ok', 'Recorded. ' . (hr_separation($id)['status'] === 'approved'
                ? 'The employee is now on notice and the exit checklist has started.' : 'It now waits for approval.')]);
        }, $back);
    } elseif ($a === 'decide') {
        $flash = hr_action(static function () {
            $d = (string) ($_POST['decision'] ?? '');
            hr_separation_decide((int) ($_POST['id'] ?? 0), $d, (string) ($_POST['approved_lwd'] ?? ''), (string) ($_POST['note'] ?? ''));
            return $d === 'approve' ? 'Accepted. The employee is on notice and the exit checklist has started.' : 'Rejected.';
        }, $back);
    } elseif ($a === 'withdraw') {
        $flash = hr_action(static function () { hr_separation_withdraw((int) ($_POST['id'] ?? 0), (string) ($_POST['note'] ?? '')); return 'Exit withdrawn.'; }, $back);
    } elseif ($a === 'sep_update') {
        $flash = hr_action(static function () { hr_separation_update((int) ($_POST['id'] ?? 0), $_POST); return 'Exit details saved.'; }, $back);
    } elseif ($a === 'complete') {
        $flash = hr_action(static function () { hr_separation_complete((int) ($_POST['id'] ?? 0)); return 'Exit completed. The employee is now marked as left; every record is kept.'; }, $back);
    } elseif ($a === 'checklist_save') {
        if (!$canCfg) { fabo_deny('You cannot change checklists.', 403); }
        $flash = hr_action(static function () {
            hr_checklist_save((int) ($_POST['id'] ?? 0) ?: null, $_POST);
            return 'Checklist saved. It applies to checklists started from now on.';
        }, hr_url('lifecycle', ['tab' => 'setup']));
    } elseif ($a === 'checklist_delete') {
        if (!$canCfg) { fabo_deny('You cannot change checklists.', 403); }
        $flash = hr_action(static function () { hr_checklist_delete((int) ($_POST['id'] ?? 0)); return 'Checklist deleted. Task lists already started keep their tasks.'; },
                           hr_url('lifecycle', ['tab' => 'setup']));
    } elseif ($a === 'checklist_template') {
        if (!$canCfg) { fabo_deny('You cannot change checklists.', 403); }
        $flash = hr_action(static function () { hr_checklist_add_template((string) ($_POST['kind'] ?? '')); return 'Added. Review the tasks and change anything that does not fit.'; },
                           hr_url('lifecycle', ['tab' => 'setup']));
    }
}

/* ---------------------------------------------------------- one exit */

$sepId = (int) ($_GET['sep'] ?? 0);
if ($sepId) {
    $s = hr_separation($sepId);
    if (!$s || !hr_emp_allowed((int) $s['employee_id'], 'lifecycle', 'view')) {
        fabo_deny('That exit is not available to you.', 404);
    }
    $back = hr_url('lifecycle', ['sep' => $sepId]);
    $acts = '';
    if (in_array($s['status'], ['approved', 'completed'], true) && $orgEdit) {
        $acts .= '<a class="btn btn-sm" href="' . e(hr_url('file', ['kind' => 'letter', 'what' => 'relieving', 'sep' => $sepId])) . '">Relieving letter (PDF)</a>'
               . '<a class="btn btn-sm" href="' . e(hr_url('file', ['kind' => 'letter', 'what' => 'experience', 'sep' => $sepId])) . '">Experience letter (PDF)</a>';
    }
    hr_layout_start('Exit — ' . $s['full_name'], e($s['emp_code']) . ' · ' . e($s['designation'] ?? '') . ' · ' . e($s['department'] ?? '') . ' · '
                  . hr_badge(hr_separation_statuses()[$s['status']], hr_separation_statuses()[$s['status']]),
                    ['Onboarding & Exits' => hr_url('lifecycle', ['tab' => 'exits']), $s['full_name'] => ''], $acts);
    echo hr_flash_html($flash);
    echo hr_steps_html(['submitted' => 'Notice given', 'approved' => 'Accepted · on notice', 'clear' => 'Clearance', 'completed' => 'Left'],
                       $s['status'] === 'approved' ? 'clear' : $s['status'], in_array($s['status'], ['rejected', 'withdrawn'], true) ? ['clear', 'completed'] : []);

    echo '<div class="hr-two"><div class="card"><h2>The exit</h2><dl class="hr-kv">'
       . '<dt>Type</dt><dd>' . e(hr_separation_types()[$s['type']]) . '</dd>'
       . '<dt>Notice given on</dt><dd>' . e(hr_fmt_date($s['notice_date'])) . '</dd>'
       . '<dt>Requested last day</dt><dd>' . e(hr_fmt_date($s['requested_lwd'])) . '</dd>'
       . '<dt>Agreed last day</dt><dd>' . ($s['approved_lwd'] ? '<strong>' . e(hr_fmt_date($s['approved_lwd'])) . '</strong> · ' . e(fabo_due_phrase($s['approved_lwd'])) : '—') . '</dd>'
       . '<dt>Joined</dt><dd>' . e(hr_fmt_date($s['date_of_joining'])) . '</dd>'
       . '<dt>Reason</dt><dd>' . nl2br(e($s['reason'] ?? '—')) . '</dd>'
       . ($s['decided_by'] ? '<dt>Decided by</dt><dd>' . e($s['decided_by']) . ' · ' . e(hr_fmt_date($s['decided_at'])) . ($s['decision_note'] ? '<div class="muted small">' . e($s['decision_note']) . '</div>' : '') . '</dd>' : '')
       . '</dl>';
    if (hr_separation_can_decide($s)) {
        echo '<h3 style="margin-top:16px">Your decision</h3>' . hr_form_open($back, 'class="hr-form"') . '<input type="hidden" name="action" value="decide"><input type="hidden" name="id" value="' . $sepId . '">'
           . '<input type="hidden" name="back" value="' . e($back) . '"><div class="formgrid">'
           . hr_field('Agreed last working day', hr_input('approved_lwd', $s['requested_lwd'], 'date', ['min' => $s['notice_date']]), true, 'Defaults to the date they asked for.')
           . hr_field('Note', hr_input('note', '', 'text', ['maxlength' => 500, 'placeholder' => 'Required when rejecting']), false, '', 'full')
           . '</div><div class="hr-formfoot"><button class="btn btn-approve" name="decision" value="approve" data-confirm="Accept this resignation? The employee goes on notice and the exit checklist starts.">Accept</button>'
           . '<button class="btn btn-reject" name="decision" value="reject">Reject</button></div></form>';
    }
    $own = $self && (int) $self['id'] === (int) $s['employee_id'];
    if (in_array($s['status'], ['submitted', 'approved'], true) && (($own && $s['status'] === 'submitted') || hr_is_admin() || (!$own && hr_emp_allowed((int) $s['employee_id'], 'lifecycle', 'approve')))) {
        echo '<details style="margin-top:12px"><summary class="btn btn-sm">Withdraw this exit</summary>' . hr_form_open($back, 'class="hr-form" style="margin-top:10px"')
           . '<input type="hidden" name="action" value="withdraw"><input type="hidden" name="id" value="' . $sepId . '"><input type="hidden" name="back" value="' . e($back) . '">'
           . hr_field('Why', hr_input('note', '', 'text', ['maxlength' => 500])) . '<button class="btn btn-sm btn-danger" data-confirm="Withdraw the exit? The employee stays employed as before.">Withdraw</button></form></details>';
    }
    echo '</div>';

    [$pending, $assets] = hr_separation_blockers($s);
    echo '<div class="card"><h2>Clearance</h2>';
    if ($s['status'] === 'approved' || $s['status'] === 'completed') {
        [$d, $n] = hr_task_progress((int) $s['employee_id'], 'offboarding');
        echo '<dl class="hr-kv"><dt>Exit checklist</dt><dd>' . ($n ? hr_progress_html($d, $n) : '<span class="muted">Not started</span>') . '</dd>'
           . '<dt>Assets still held</dt><dd>' . ($assets ? '<span class="req">' . count($assets) . '</span> — ' . e(implode(', ', array_map(static fn($a) => $a['tag'] . ' ' . $a['name'], $assets)))
                                                    . (hr_can('assets', 'edit') ? ' · <a href="' . e(hr_url('assets', ['employee' => $s['employee_id']])) . '">Take back</a>' : '') : 'None') . '</dd>'
           . '<dt>Full &amp; final</dt><dd>' . hr_badge(hr_fnf_statuses()[$s['fnf_status']], hr_fnf_statuses()[$s['fnf_status']])
           . ($s['fnf_amount'] !== null ? ' ' . e(fabo_money_exact($s['fnf_amount'])) : '') . '</dd></dl>';
        if ($s['status'] === 'approved' && $orgEdit) {
            echo '<div class="hr-formfoot" style="margin-top:12px">'
               . hr_button_form('Complete exit', ['action' => 'complete', 'id' => $sepId, 'back' => $back], 'btn btn-primary',
                                'Complete the exit? ' . $s['full_name'] . ' will be marked as left on ' . hr_fmt_date($s['approved_lwd'])
                                . '.' . ($pending ? ' ' . $pending . ' checklist task(s) are still open.' : ''))
               . '<span class="muted small">' . ($pending ? $pending . ' task(s) still open. ' : 'Checklist complete. ') . 'Leave, attendance and payroll records are kept.</span></div>';
        }
    } else {
        echo hr_empty('Clearance starts once the exit is accepted.');
    }
    echo '</div></div>';

    if ($s['status'] === 'approved' || $s['status'] === 'completed') {
        echo '<div class="card"><h2>Exit checklist</h2>';
        hr_render_tasks(hr_tasks_for((int) $s['employee_id'], 'offboarding'), $back);
        if ($orgEdit && $s['status'] === 'approved') {
            if (!hr_tasks_for((int) $s['employee_id'], 'offboarding')) {
                echo hr_form_open($back, 'class="hr-filters" style="padding:10px 0 0"') . '<input type="hidden" name="action" value="start"><input type="hidden" name="kind" value="offboarding">'
                   . '<input type="hidden" name="employee_id" value="' . (int) $s['employee_id'] . '"><input type="hidden" name="base" value="' . e($s['approved_lwd']) . '"><input type="hidden" name="back" value="' . e($back) . '">'
                   . '<div class="grp"><label for="cl">Checklist</label><select id="cl" name="checklist_id">' . hr_options(array_column(hr_checklists('offboarding', true), 'name', 'id'), '') . '</select></div>'
                   . '<button class="btn btn-primary">Start exit checklist</button></form>';
            }
            echo '<details style="margin-top:10px"><summary class="btn btn-sm">+ Add a task</summary>' . hr_form_open($back, 'class="hr-form" style="margin-top:10px"')
               . '<input type="hidden" name="action" value="task_add"><input type="hidden" name="kind" value="offboarding"><input type="hidden" name="employee_id" value="' . (int) $s['employee_id'] . '">'
               . '<input type="hidden" name="back" value="' . e($back) . '"><div class="formgrid">' . hr_field('Task', hr_input('title', '', 'text', ['required' => true, 'maxlength' => 190]), true)
               . hr_field('Who', hr_select('owner', hr_task_owners(), 'hr')) . hr_field('Due', hr_input('due_date', $s['approved_lwd'], 'date')) . '</div><button class="btn btn-sm btn-primary">Add</button></form></details>';
        }
        echo '</div>';
    }

    if ($orgEdit && in_array($s['status'], ['approved', 'completed'], true)) {
        echo '<div class="card"><h2>Exit interview and settlement</h2>' . hr_form_open($back, 'class="hr-form"') . '<input type="hidden" name="action" value="sep_update">'
           . '<input type="hidden" name="id" value="' . $sepId . '"><input type="hidden" name="back" value="' . e($back) . '"><div class="formgrid">'
           . ($s['status'] === 'approved' ? hr_field('Agreed last working day', hr_input('approved_lwd', $s['approved_lwd'], 'date')) : '')
           . hr_field('Overall experience (1–5)', hr_select('exit_rating', [1 => '1 — Poor', 2 => '2', 3 => '3 — Average', 4 => '4', 5 => '5 — Excellent'], $s['exit_rating'], 'Not given'))
           . hr_field('Eligible for rehire', hr_select('rehire_eligible', ['1' => 'Yes', '0' => 'No'], $s['rehire_eligible'] === null ? '' : (string) $s['rehire_eligible'], 'Not decided'))
           . hr_field('Exit interview notes', '<textarea name="exit_interview" rows="4" maxlength="5000">' . e($s['exit_interview'] ?? '') . '</textarea>', false,
                      'Why they are leaving, what could be better. Visible only to HR.', 'full')
           . hr_field('Full & final settlement', hr_select('fnf_status', hr_fnf_statuses(), $s['fnf_status']))
           . hr_field('Settlement amount (₹)', hr_input('fnf_amount', $s['fnf_amount'] ?? '', 'number', ['step' => '0.01']), false, 'Negative if the employee owes the company.')
           . hr_field('Settled on', hr_input('fnf_settled_on', $s['fnf_settled_on'] ?? '', 'date'))
           . hr_field('Settlement notes', hr_input('fnf_note', $s['fnf_note'] ?? '', 'text', ['maxlength' => 500, 'placeholder' => 'e.g. leave encashment 6 days, notice recovery 5 days']), false, '', 'full')
           . '</div><button class="btn btn-primary">Save</button></form></div>';
    }
    hr_layout_end();
    return;
}

/* -------------------------------------------------------- one employee */

$empId = (int) ($_GET['emp'] ?? 0);
if ($empId) {
    hr_need_emp($empId, 'lifecycle', 'view');
    $emp = hr_employee_full($empId);
    $back = hr_url('lifecycle', ['emp' => $empId]);
    hr_layout_start('Onboarding & exit — ' . $emp['full_name'], e($emp['emp_code']) . ' · joined ' . e(hr_fmt_date($emp['date_of_joining'])) . ' · ' . hr_badge(hr_status_label($emp['status'])),
                    ['Onboarding & Exits' => hr_url('lifecycle'), $emp['full_name'] => ''],
                    hr_can('employees', 'view') ? '<a class="btn btn-sm" href="' . e(hr_url('employee', ['id' => $empId])) . '">Employee profile</a>' : '');
    echo hr_flash_html($flash);
    $on = hr_tasks_for($empId, 'onboarding');
    echo '<div class="card"><h2>Onboarding</h2>';
    if ($on) {
        [$d, $n] = hr_task_progress($empId, 'onboarding');
        echo '<p class="mt0">' . hr_progress_html($d, $n) . '</p>';
        hr_render_tasks($on, $back);
    } elseif ($orgEdit) {
        $lists = array_column(hr_checklists('onboarding', true), 'name', 'id');
        echo $lists ? hr_form_open($back, 'class="hr-filters" style="padding:0"') . '<input type="hidden" name="action" value="start"><input type="hidden" name="kind" value="onboarding">'
                   . '<input type="hidden" name="employee_id" value="' . $empId . '"><input type="hidden" name="back" value="' . e($back) . '">'
                   . '<div class="grp"><label for="cl">Checklist</label><select id="cl" name="checklist_id">' . hr_options($lists, '') . '</select></div>'
                   . '<div class="grp"><label for="bd">Counted from</label><input id="bd" type="date" name="base" value="' . e($emp['date_of_joining'] ?: date('Y-m-d')) . '"></div>'
                   . '<button class="btn btn-primary">Start onboarding</button></form>'
                   : hr_empty('No onboarding checklist yet.', $canCfg ? ' Add one under the Setup tab.' : '');
    } else {
        echo hr_empty('No onboarding checklist was started.');
    }
    if ($orgEdit && $on) {
        echo '<details style="margin-top:10px"><summary class="btn btn-sm">+ Add a task</summary>' . hr_form_open($back, 'class="hr-form" style="margin-top:10px"')
           . '<input type="hidden" name="action" value="task_add"><input type="hidden" name="kind" value="onboarding"><input type="hidden" name="employee_id" value="' . $empId . '">'
           . '<input type="hidden" name="back" value="' . e($back) . '"><div class="formgrid">' . hr_field('Task', hr_input('title', '', 'text', ['required' => true, 'maxlength' => 190]), true)
           . hr_field('Who', hr_select('owner', hr_task_owners(), 'hr')) . hr_field('Due', hr_input('due_date', '', 'date')) . '</div><button class="btn btn-sm btn-primary">Add</button></form></details>';
    }
    echo '</div>';
    $seps = fabo_all('SELECT * FROM hr_separations WHERE employee_id = ? AND org_id = ? ORDER BY id DESC', 'ii', [$empId, hr_org()]);
    echo '<div class="card"><h2>Exit</h2>';
    if ($seps) {
        echo '<ul class="hr-list">';
        foreach ($seps as $x) {
            echo '<li><span><a href="' . e(hr_url('lifecycle', ['sep' => $x['id']])) . '">' . e(hr_separation_types()[$x['type']]) . '</a> · notice ' . e(hr_fmt_date($x['notice_date']))
               . ' · last day ' . e(hr_fmt_date($x['approved_lwd'] ?: $x['requested_lwd'])) . '</span>' . hr_badge(hr_separation_statuses()[$x['status']], hr_separation_statuses()[$x['status']]) . '</li>';
        }
        echo '</ul>';
    } else {
        echo hr_empty('No exit recorded.');
    }
    if (!hr_separation_open($empId) && in_array($emp['status'], hr_working_statuses(), true) && (hr_is_admin() || hr_scope('lifecycle', 'create') === 'org')) {
        echo '<p><a class="btn btn-sm" href="' . e(hr_url('lifecycle', ['tab' => 'exits', 'new' => $empId])) . '#record">Record an exit</a></p>';
    }
    echo '</div>';
    hr_layout_end();
    return;
}

/* ------------------------------------------------------------- the desk */

$tabs = [];
if ($self) { $tabs['mine'] = 'Mine'; }
if ($hrDesk) {
    $tabs['onboarding'] = 'Onboarding';
    $tabs['exits'] = 'Exits' . (($n = hr_attention()['resignations'][1] ?? 0) ? ' <span class="badge">' . $n . '</span>' : '');
    $tabs['tasks'] = 'Tasks';
}
if ($canCfg) { $tabs['setup'] = 'Checklists & rules'; }
$tab = hr_tab($tabs, $hrDesk ? 'onboarding' : 'mine');
$here = hr_url('lifecycle', ['tab' => $tab]);

hr_layout_start('Onboarding & Exits', 'Joiners\' first days and leavers\' last days: who does what, by when.', ['Onboarding & Exits' => '']);
echo hr_flash_html($flash);
hr_tabs('lifecycle', $tabs, $tab);

if ($tab === 'mine') {
    $id = (int) $self['id'];
    echo hr_guide('How this works', [
        'When you join, HR starts an onboarding checklist. Tasks marked <em>The employee</em> are yours to tick off.',
        'To resign, use the form below. Your manager (or HR) accepts it and agrees your last working day.',
        'Once accepted, your exit checklist appears here: hand over work, return company assets, exit interview.',
    ]);
    foreach (['onboarding' => 'My onboarding', 'offboarding' => 'My exit checklist'] as $k => $t) {
        $list = hr_tasks_for($id, $k);
        if ($list) {
            [$d, $n] = hr_task_progress($id, $k);
            echo '<div class="card"><h2>' . e($t) . ' <span class="muted small">' . $d . ' of ' . $n . ' done</span></h2>';
            hr_render_tasks($list, $here);
            echo '</div>';
        }
    }
    $open = hr_separation_open($id);
    $last = fabo_one('SELECT * FROM hr_separations WHERE employee_id = ? ORDER BY id DESC LIMIT 1', 'i', [$id]);
    echo '<div class="card"><h2>Resignation</h2>';
    if ($open) {
        echo '<p class="mt0">Your ' . e(strtolower(hr_separation_types()[$open['type']])) . ' of ' . e(hr_fmt_date($open['notice_date'])) . ' is '
           . hr_badge(hr_separation_statuses()[$open['status']], strtolower(hr_separation_statuses()[$open['status']])) . '. Last working day: <strong>'
           . e(hr_fmt_date($open['approved_lwd'] ?: $open['requested_lwd'])) . '</strong>' . ($open['approved_lwd'] ? '' : ' (requested)') . '.</p>'
           . '<a class="btn btn-sm" href="' . e(hr_url('lifecycle', ['sep' => $open['id']])) . '">Open</a>';
    } elseif (hr_can('lifecycle', 'create') && in_array($self['status'], hr_working_statuses(), true)) {
        $days = (int) hr_setting('exit_notice_days');
        echo ($last ? '<p class="muted mt0">Your last request (' . e(hr_fmt_date($last['notice_date'])) . ') was ' . e(strtolower(hr_separation_statuses()[$last['status']])) . '.</p>' : '')
           . '<p class="mt0">The standard notice period is <strong>' . $days . ' days</strong>. Talk to your manager before you submit.</p>'
           . hr_form_open($here, 'class="hr-form" data-confirm="Submit your resignation? Your manager and HR are told straight away."')
           . '<input type="hidden" name="action" value="resign"><input type="hidden" name="employee_id" value="' . $id . '"><div class="formgrid">'
           . hr_field('Last working day you are asking for', hr_input('requested_lwd', date('Y-m-d', strtotime('+' . $days . ' day')), 'date', ['min' => date('Y-m-d'), 'required' => true]), true)
           . hr_field('Reason', '<textarea name="reason" rows="3" maxlength="2000" required></textarea>', true, 'Seen by your manager and HR.', 'full')
           . '</div><button class="btn btn-danger">Submit resignation</button></form>';
    } else {
        echo hr_empty('Nothing to show.');
    }
    echo '</div>';
}

if ($tab === 'onboarding') {
    echo hr_guide('How onboarding works', [
        'Set up a checklist once under <em>Checklists &amp; rules</em> (one click adds a standard one).',
        'When an employee is added — by hand or by hiring a candidate — the default checklist starts by itself, with due dates counted from the joining date.',
        'Each task has an owner: HR, IT, admin, finance, the manager or the employee. Everyone sees their own tasks on My HR and ticks them off.',
    ]);
    $rows = hr_lifecycle_in_progress('onboarding');
    echo '<div class="card"><h2>Joiners in progress (' . count($rows) . ')</h2>';
    if (!$rows) {
        echo hr_empty('Nobody is being onboarded right now.', hr_checklist_default('onboarding') ? '' : ' Add an onboarding checklist first, under Checklists & rules.');
    } else {
        echo '<div class="scrollx"><table class="data hr-table stack"><thead><tr><th>Employee</th><th>Department</th><th>Joins / joined</th><th>Progress</th><th>Next due</th><th></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr><td data-l="Employee">' . hr_person_cell($r) . '</td><td data-l="Department">' . e($r['department'] ?? '—') . '</td>'
               . '<td data-l="Joins" class="nowrap">' . e(hr_fmt_date($r['date_of_joining'])) . '</td><td data-l="Progress">' . hr_progress_html((int) $r['done'], (int) $r['total'])
               . ($r['overdue'] ? ' ' . hr_badge('overdue', $r['overdue'] . ' overdue') : '') . '</td><td data-l="Next due" class="nowrap">' . e(hr_fmt_date($r['next_due'])) . '</td>'
               . '<td><a class="btn btn-sm" href="' . e(hr_url('lifecycle', ['emp' => $r['id']])) . '">Open</a></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';
    if ($orgEdit) {
        $recent = fabo_all("SELECT e.id, e.full_name, e.emp_code FROM hr_employees e WHERE e.org_id = ? AND e.status IN ('active','probation')
                            AND e.date_of_joining >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
                            AND NOT EXISTS (SELECT 1 FROM hr_lifecycle_tasks t WHERE t.employee_id = e.id AND t.kind = 'onboarding') ORDER BY e.date_of_joining DESC", 'i', [hr_org()]);
        if ($recent) {
            echo '<div class="card"><h2>Recent joiners without a checklist</h2><ul class="hr-list">';
            foreach ($recent as $r) {
                echo '<li><span>' . e($r['full_name']) . ' <span class="muted small">' . e($r['emp_code']) . '</span></span><a class="btn btn-sm" href="' . e(hr_url('lifecycle', ['emp' => $r['id']])) . '">Start onboarding</a></li>';
            }
            echo '</ul></div>';
        }
    }
}

if ($tab === 'exits') {
    echo hr_guide('How exits work', [
        'An employee resigns from My HR, or HR records a resignation, termination or retirement here.',
        'The manager (or HR) accepts it and agrees the last working day. The employee goes on notice and the exit checklist starts.',
        'HR records the exit interview and full &amp; final settlement, and downloads the relieving and experience letters.',
        '<strong>Complete exit</strong> marks them as left on their last day. Every record is kept. It waits until company assets are returned.',
    ]);
    $rows = hr_separations_list();
    $groups = ['submitted' => 'Waiting for a decision', 'approved' => 'On notice / in clearance', 'completed' => 'Completed', 'other' => 'Rejected or withdrawn'];
    foreach ($groups as $g => $label) {
        $list = array_filter($rows, static fn($r) => $g === 'other' ? in_array($r['status'], ['rejected', 'withdrawn'], true) : $r['status'] === $g);
        if (!$list && $g !== 'submitted') {
            continue;
        }
        echo '<div class="card"><h2>' . e($label) . ' (' . count($list) . ')</h2>';
        if (!$list) {
            echo hr_empty('Nothing waiting.');
        } else {
            echo '<div class="scrollx"><table class="data hr-table stack"><thead><tr><th>Employee</th><th>Type</th><th>Notice</th><th>Last day</th><th>Settlement</th><th></th></tr></thead><tbody>';
            foreach (array_slice($list, 0, $g === 'completed' || $g === 'other' ? 25 : 500) as $r) {
                $lwd = $r['approved_lwd'] ?: $r['requested_lwd'];
                echo '<tr><td data-l="Employee">' . hr_person_cell($r) . '</td><td data-l="Type">' . e(hr_separation_types()[$r['type']]) . '</td>'
                   . '<td data-l="Notice" class="nowrap">' . e(hr_fmt_date($r['notice_date'])) . '</td>'
                   . '<td data-l="Last day" class="nowrap">' . e(hr_fmt_date($lwd)) . ($r['status'] === 'approved' && $lwd < date('Y-m-d') ? ' ' . hr_badge('overdue', 'Passed') : '') . '</td>'
                   . '<td data-l="Settlement">' . hr_badge(hr_fnf_statuses()[$r['fnf_status']], hr_fnf_statuses()[$r['fnf_status']]) . '</td>'
                   . '<td><a class="btn btn-sm' . (hr_separation_can_decide($r) ? ' btn-primary' : '') . '" href="' . e(hr_url('lifecycle', ['sep' => $r['id']])) . '">'
                   . (hr_separation_can_decide($r) ? 'Review' : 'Open') . '</a></td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</div>';
    }
    if (hr_is_admin() || hr_scope('lifecycle', 'create') === 'org') {
        $pre = (int) ($_GET['new'] ?? 0);
        echo '<div class="card" id="record"><h2>Record an exit</h2>' . hr_form_open($here, 'class="hr-form"') . '<input type="hidden" name="action" value="resign"><div class="formgrid">'
           . hr_field('Employee', hr_select('employee_id', hr_employee_choices(true, 'lifecycle', 'create'), $pre, 'Choose…', ['required' => true]), true)
           . hr_field('Type', hr_select('type', hr_separation_types(), 'resignation'), true)
           . hr_field('Notice given on', hr_input('notice_date', date('Y-m-d'), 'date'), true)
           . hr_field('Last working day', hr_input('requested_lwd', date('Y-m-d', strtotime('+' . (int) hr_setting('exit_notice_days') . ' day')), 'date'), true)
           . hr_field('Reason', '<textarea name="reason" rows="2" maxlength="2000"></textarea>', false, '', 'full')
           . '</div><div class="field">' . hr_checkbox('approve_now', true, 'Accept it now (the employee goes on notice and the exit checklist starts)') . '</div>'
           . '<button class="btn btn-primary">Record exit</button></form></div>';
    }
}

if ($tab === 'tasks') {
    $f = ['kind' => (string) ($_GET['kind'] ?? ''), 'owner' => (string) ($_GET['owner'] ?? ''), 'status' => array_key_exists('status', $_GET) ? (string) $_GET['status'] : 'pending',
          'due' => (string) ($_GET['due'] ?? '')];
    $mine = !empty($_GET['mine']);
    $rows = $mine ? hr_tasks_mine() : hr_tasks_list($f);
    echo '<form method="get" class="card hr-filters"><input type="hidden" name="p" value="lifecycle"><input type="hidden" name="tab" value="tasks">'
       . '<div class="grp"><label for="tm">Show</label><select id="tm" name="mine" data-autosubmit>' . hr_options(['' => 'Everyone\'s tasks', '1' => 'Only tasks I have to do'], $mine ? '1' : '') . '</select></div>'
       . (!$mine ? '<div class="grp"><label for="tk">List</label><select id="tk" name="kind" data-autosubmit>' . hr_options(['onboarding' => 'Onboarding', 'offboarding' => 'Exit'], $f['kind'], 'Both') . '</select></div>'
       . '<div class="grp"><label for="to">Who</label><select id="to" name="owner" data-autosubmit>' . hr_options(hr_task_owners(), $f['owner'], 'Anyone') . '</select></div>'
       . '<div class="grp"><label for="ts">Status</label><select id="ts" name="status" data-autosubmit>' . hr_options(['pending' => 'To do', 'done' => 'Done', 'skipped' => 'Not needed'], $f['status'], 'All') . '</select></div>'
       . '<div class="grp"><label for="td">Due</label><select id="td" name="due" data-autosubmit>' . hr_options(['overdue' => 'Overdue', 'week' => 'Within a week'], $f['due'], 'Any time') . '</select></div>' : '')
       . '<noscript><button class="btn btn-sm">Apply</button></noscript></form>';
    echo '<div class="card"><h2>' . ($mine ? 'Tasks I have to do' : 'Tasks') . ' (' . count($rows) . ')</h2>';
    hr_render_tasks($rows, hr_url('lifecycle', ['tab' => 'tasks'] + array_filter($f + ['mine' => $mine ? 1 : ''])), true);
    echo '</div>';
}

if ($tab === 'setup') {
    $editId = (int) ($_GET['edit'] ?? 0);
    $edit = $editId ? hr_checklist($editId) : null;
    $lists = hr_checklists();
    echo '<div class="card"><h2>Checklists</h2>';
    if (!$lists) {
        echo '<p class="mt0">No checklists yet. Start from a standard one and change it to fit:</p>';
    } else {
        echo '<table class="data hr-table stack"><thead><tr><th>Checklist</th><th>For</th><th class="num">Tasks</th><th>Default</th><th></th></tr></thead><tbody>';
        foreach ($lists as $c) {
            echo '<tr><td data-l="Checklist"><strong>' . e($c['name']) . '</strong>' . ($c['active'] ? '' : ' ' . hr_badge('inactive', 'Inactive')) . '</td>'
               . '<td data-l="For">' . ($c['kind'] === 'onboarding' ? 'Joiners' : 'Leavers') . '</td><td class="num" data-l="Tasks">' . (int) $c['items'] . '</td>'
               . '<td data-l="Default">' . ($c['is_default'] ? hr_badge('active', 'Default') : '') . '</td><td class="hr-acts">'
               . '<a class="btn btn-sm" href="' . e(hr_url('lifecycle', ['tab' => 'setup', 'edit' => $c['id']])) . '#cl-form">Edit</a>'
               . hr_button_form('Delete', ['action' => 'checklist_delete', 'id' => $c['id']], 'btn btn-sm btn-danger', 'Delete this checklist? Task lists already started keep their tasks.') . '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '<div class="hr-acts" style="margin-top:10px">';
    foreach (hr_checklist_templates() as $k => [$nm, $items]) {
        if (!fabo_one('SELECT id FROM hr_checklists WHERE org_id = ? AND name = ?', 'is', [hr_org(), $nm])) {
            echo hr_button_form('+ Add “' . $nm . '” (' . count($items) . ' tasks)', ['action' => 'checklist_template', 'kind' => $k], 'btn btn-sm');
        }
    }
    echo '</div></div>';

    $items = $edit ? hr_checklist_items($editId) : [];
    $rowsHtml = '';
    foreach (array_merge($items, array_fill(0, $edit ? 3 : 6, ['title' => '', 'owner' => 'hr', 'due_days' => 0])) as $it) {
        $rowsHtml .= '<tr><td><input type="text" name="item_title[]" value="' . e($it['title']) . '" maxlength="190" aria-label="Task"></td>'
                   . '<td><select name="item_owner[]" aria-label="Who">' . hr_options(hr_task_owners(), $it['owner']) . '</select></td>'
                   . '<td><input type="number" name="item_due[]" value="' . (int) $it['due_days'] . '" min="-365" max="365" style="width:90px" aria-label="Days"></td></tr>';
    }
    echo '<div class="card" id="cl-form"><h2>' . ($edit ? 'Edit checklist: ' . e($edit['name']) : 'New checklist') . '</h2>' . hr_form_open(hr_url('lifecycle', ['tab' => 'setup']), 'class="hr-form"')
       . '<input type="hidden" name="action" value="checklist_save">' . ($edit ? '<input type="hidden" name="id" value="' . $editId . '"><input type="hidden" name="active" value="1">' : '')
       . '<div class="formgrid">' . hr_field('Name', hr_input('name', $edit['name'] ?? '', 'text', ['required' => true, 'maxlength' => 120, 'placeholder' => 'e.g. Sales team onboarding']), true)
       . hr_field('For', hr_select('kind', ['onboarding' => 'Joiners (onboarding)', 'offboarding' => 'Leavers (exit)'], $edit['kind'] ?? 'onboarding'), true)
       . '</div><div class="field">' . hr_checkbox('is_default', (bool) ($edit['is_default'] ?? !$lists), 'Use this checklist by default') . '</div>'
       . '<p class="hr-hint">Days are counted from the joining date (joiners) or the last working day (leavers). Use a minus number for before, e.g. −1 is the day before. Blank rows are ignored.</p>'
       . '<div class="scrollx"><table class="data hr-table hr-edit"><thead><tr><th>Task</th><th>Who does it</th><th>Days</th></tr></thead><tbody>' . $rowsHtml . '</tbody></table></div>'
       . '<div class="hr-formfoot"><button class="btn btn-primary">Save checklist</button>' . ($edit ? '<a class="btn" href="' . e(hr_url('lifecycle', ['tab' => 'setup'])) . '">Cancel</a>' : '') . '</div></form></div>';

    hr_part_settings('lifecycle', hr_url('lifecycle', ['tab' => 'setup']), 'Onboarding and exit rules');
}

hr_layout_end();

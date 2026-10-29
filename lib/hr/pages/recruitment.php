<?php
/**
 * Recruitment — openings, each opening's pipeline as a board, candidates
 * with their interviews and offer, and the interviewer's own list.
 *
 *   ?opening=ID      one opening and its board
 *   ?cand=ID         one candidate
 *   ?edit=ID / new=1 the opening form
 */

$canView = hr_can('recruitment', 'view');
$mineIv  = hr_interviews_mine(false);
if (!$canView && !$mineIv) {
    fabo_deny('You do not have access to Recruitment.', 403);
}
$canCreate = hr_scope('recruitment', 'create') === 'org';
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    $oid = (int) ($_POST['opening_id'] ?? 0);
    $cid = (int) ($_POST['cand'] ?? 0);
    if ($a === 'opening_save') {
        $flash = hr_action(static function () {
            $id = hr_opening_save((int) ($_POST['id'] ?? 0) ?: null, $_POST);
            hr_redirect(hr_url('recruitment', ['opening' => $id]), ['ok', 'Opening saved.']);
        }, hr_url('recruitment'));
    } elseif ($a === 'opening_delete') {
        $flash = hr_action(static function () { hr_opening_delete((int) ($_POST['id'] ?? 0)); return 'Opening deleted.'; }, hr_url('recruitment'));
    } elseif ($a === 'cand_save') {
        $flash = hr_action(static function () {
            $id = hr_candidate_save((int) ($_POST['cand'] ?? 0) ?: null, $_POST, $_FILES['resume'] ?? null);
            hr_redirect(hr_url('recruitment', ['cand' => $id]), ['ok', 'Candidate saved.']);
        }, hr_url('recruitment', ['opening' => $oid]));
    } elseif ($a === 'stage') {
        $flash = hr_action(static function () use ($cid) {
            hr_candidate_stage($cid, (string) ($_POST['stage'] ?? ''), $_POST);
            return 'Moved to ' . hr_candidate_stages()[$_POST['stage']] . '.';
        }, hr_url('recruitment', ['cand' => $cid]));
    } elseif ($a === 'hire') {
        $flash = hr_action(static function () use ($cid) {
            $emp = hr_candidate_hire($cid, $_POST);
            hr_redirect(hr_url('employee', ['id' => $emp, 'edit' => 1]),
                        ['ok', 'Hired. The employee record is created' . (hr_tasks_for($emp, 'onboarding') ? ' and onboarding has started' : '') . '. Complete the remaining details below.']);
        }, hr_url('recruitment', ['cand' => $cid]));
    } elseif ($a === 'interview') {
        $flash = hr_action(static function () use ($cid) { hr_interview_save($cid, $_POST); return 'Interview booked. The interviewer has been told.'; },
                           hr_url('recruitment', ['cand' => $cid]));
    } elseif ($a === 'feedback') {
        $back = (string) ($_POST['back'] ?? '') === 'mine' ? hr_url('recruitment', ['tab' => 'myinterviews']) : hr_url('recruitment', ['cand' => $cid]);
        $flash = hr_action(static function () { hr_interview_feedback((int) ($_POST['id'] ?? 0), $_POST); return 'Feedback saved. Thank you.'; }, $back);
    }
}

/* ------------------------------------------------------------ candidate */

$cid = (int) ($_GET['cand'] ?? 0);
if ($cid && $canView) {
    $c = hr_candidate($cid);
    if (!$c) {
        fabo_deny('That candidate is not available to you.', 404);
    }
    $mayEdit = (bool) hr_candidate($cid, 'edit');
    $acts = '';
    if ($c['resume_file']) {
        $acts .= '<a class="btn btn-sm" href="' . e(hr_url('file', ['kind' => 'resume', 'id' => $cid])) . '">Résumé</a>';
    }
    if ($mayEdit && in_array($c['stage'], ['offer', 'hired'], true)) {
        $acts .= '<a class="btn btn-sm" href="' . e(hr_url('file', ['kind' => 'letter', 'what' => 'offer', 'cand' => $cid])) . '">Offer letter (PDF)</a>';
    }
    if ($c['employee_id'] && hr_emp_allowed((int) $c['employee_id'], 'employees', 'view')) {
        $acts .= '<a class="btn btn-sm btn-primary" href="' . e(hr_url('employee', ['id' => $c['employee_id']])) . '">Employee record</a>';
    }
    hr_layout_start($c['full_name'], 'Candidate for <a href="' . e(hr_url('recruitment', ['opening' => $c['opening_id']])) . '">' . e($c['opening']) . '</a> · '
                  . hr_badge(hr_candidate_stages()[$c['stage']], hr_candidate_stages()[$c['stage']]),
                    ['Recruitment' => hr_url('recruitment'), $c['opening'] => hr_url('recruitment', ['opening' => $c['opening_id']]), $c['full_name'] => ''], $acts);
    echo hr_flash_html($flash);
    if (!in_array($c['stage'], ['rejected', 'withdrawn'], true)) {
        echo hr_steps_html(['applied' => 'Applied', 'screening' => 'Screening', 'interview' => 'Interview', 'offer' => 'Offer', 'hired' => 'Hired'], $c['stage']);
    }
    echo '<div class="hr-two"><div class="card"><h2>Profile</h2><dl class="hr-kv">'
       . '<dt>Email</dt><dd>' . e($c['email'] ?? '—') . '</dd><dt>Phone</dt><dd>' . e($c['phone'] ?? '—') . '</dd>'
       . '<dt>Source</dt><dd>' . e($c['source'] ?? '—') . '</dd><dt>Current company</dt><dd>' . e($c['current_company'] ?? '—') . '</dd>'
       . '<dt>Experience</dt><dd>' . ($c['experience_years'] !== null ? e(hr_fmt_days($c['experience_years'])) . ' years' : '—') . '</dd>'
       . '<dt>Expected salary</dt><dd>' . ($c['expected_salary'] !== null ? e(fabo_money_exact($c['expected_salary'])) . ' a year' : '—') . '</dd>'
       . '<dt>Notice period</dt><dd>' . ($c['notice_days'] !== null ? (int) $c['notice_days'] . ' days' : '—') . '</dd>'
       . '<dt>Rating</dt><dd>' . (hr_stars($c['rating']) ?: '—') . '</dd>'
       . ($c['offer_salary'] !== null || $c['offer_join_date'] ? '<dt>Offer</dt><dd>' . ($c['offer_salary'] !== null ? e(fabo_money_exact($c['offer_salary'])) . ' a year' : '')
            . ($c['offer_join_date'] ? ' · joining ' . e(hr_fmt_date($c['offer_join_date'])) : '') . '</dd>' : '')
       . '<dt>Notes</dt><dd>' . nl2br(e($c['notes'] ?? '—')) . '</dd></dl>';
    if ($mayEdit) {
        echo '<details style="margin-top:12px"><summary class="btn btn-sm">Edit profile</summary>' . hr_form_open(hr_url('recruitment', ['cand' => $cid]), 'class="hr-form" style="margin-top:10px"', true)
           . '<input type="hidden" name="action" value="cand_save"><input type="hidden" name="cand" value="' . $cid . '"><input type="hidden" name="opening_id" value="' . (int) $c['opening_id'] . '"><div class="formgrid">'
           . hr_field('Name', hr_input('full_name', $c['full_name'], 'text', ['required' => true, 'maxlength' => 160]), true)
           . hr_field('Email', hr_input('email', $c['email'] ?? '', 'email', ['maxlength' => 190]))
           . hr_field('Phone', hr_input('phone', $c['phone'] ?? '', 'tel', ['maxlength' => 40]))
           . hr_field('Source', hr_select('source', array_combine(hr_candidate_sources(), hr_candidate_sources()), $c['source'] ?? '', 'Not known'))
           . hr_field('Current company', hr_input('current_company', $c['current_company'] ?? '', 'text', ['maxlength' => 120]))
           . hr_field('Experience (years)', hr_input('experience_years', $c['experience_years'] ?? '', 'number', ['step' => '0.5', 'min' => 0]))
           . hr_field('Expected salary (₹ a year)', hr_input('expected_salary', $c['expected_salary'] ?? '', 'number', ['step' => '1', 'min' => 0]))
           . hr_field('Notice period (days)', hr_input('notice_days', $c['notice_days'] ?? '', 'number', ['min' => 0]))
           . hr_field('Rating', hr_select('rating', [1 => '★', 2 => '★★', 3 => '★★★', 4 => '★★★★', 5 => '★★★★★'], $c['rating'], 'Not rated'))
           . hr_field('Résumé', '<input type="file" name="resume" accept=".pdf,.doc,.docx,.odt,.rtf,.txt">', false, $c['resume_name'] ? 'Replaces ' . e($c['resume_name']) . '.' : '')
           . hr_field('Notes', '<textarea name="notes" rows="3" maxlength="5000">' . e($c['notes'] ?? '') . '</textarea>', false, '', 'full')
           . '</div><button class="btn btn-primary">Save</button></form></details>';
    }
    echo '</div><div class="card"><h2>Next step</h2>';
    if ($mayEdit && $c['stage'] !== 'hired') {
        $stages = hr_candidate_stages();
        unset($stages['hired'], $stages[$c['stage']]);
        echo hr_form_open(hr_url('recruitment', ['cand' => $cid]), 'class="hr-form"') . '<input type="hidden" name="action" value="stage"><input type="hidden" name="cand" value="' . $cid . '">'
           . '<div class="formgrid">' . hr_field('Move to', hr_select('stage', $stages, '', 'Choose…', ['required' => true, 'data-offer-toggle' => '1']), true)
           . hr_field('Note', hr_input('note', '', 'text', ['maxlength' => 255]))
           . hr_field('Offered salary (₹ a year)', hr_input('offer_salary', $c['offer_salary'] ?? '', 'number', ['step' => '1', 'min' => 0]), false, 'Only for an offer.')
           . hr_field('Joining date', hr_input('offer_join_date', $c['offer_join_date'] ?? '', 'date'), false, 'Only for an offer.')
           . '</div><button class="btn btn-primary">Move</button></form>';
        if (hr_scope('employees', 'create') === 'org' && in_array($c['stage'], ['offer', 'interview'], true)) {
            echo '<h3 style="margin-top:18px">Hire</h3><p class="hr-hint mt0">Creates the employee record from this candidate and the opening (department, designation, manager, location), '
               . 'marks the opening filled when every position is taken, and starts onboarding.</p>'
               . hr_form_open(hr_url('recruitment', ['cand' => $cid]), 'class="hr-filters" style="padding:0"') . '<input type="hidden" name="action" value="hire"><input type="hidden" name="cand" value="' . $cid . '">'
               . '<div class="grp"><label for="hd">Date of joining</label><input id="hd" type="date" name="date_of_joining" value="' . e($c['offer_join_date'] ?? '') . '" required></div>'
               . '<button class="btn btn-approve" data-confirm="Hire ' . e($c['full_name']) . ' and create their employee record?">Hire and create employee</button></form>';
        }
    } elseif ($c['stage'] === 'hired') {
        echo '<p class="mt0">' . hr_badge('hired', 'Hired') . ' Joining ' . e(hr_fmt_date($c['offer_join_date'])) . '.</p>';
    } else {
        echo hr_empty('You can view this candidate but not move them.');
    }
    echo '<h3 style="margin-top:18px">History</h3><ul class="hr-list">';
    foreach (hr_candidate_events($cid) as $ev) {
        echo '<li><span>' . e($ev['summary']) . '</span><span class="muted small">' . e($ev['created_by']) . ' · ' . e(date('d M Y H:i', strtotime($ev['created_at']))) . '</span></li>';
    }
    echo '</ul></div></div>';

    $ivs = hr_interviews_for($cid);
    echo '<div class="card"><h2>Interviews</h2>';
    if (!$ivs) {
        echo hr_empty('No interviews yet.');
    } else {
        echo '<div class="scrollx"><table class="data hr-table stack"><thead><tr><th>Round</th><th>When</th><th>Interviewer</th><th>Status</th><th>Result</th><th>Feedback</th></tr></thead><tbody>';
        foreach ($ivs as $i) {
            echo '<tr><td data-l="Round"><strong>' . e($i['round']) . '</strong><div class="muted small">' . e(hr_interview_modes()[$i['mode']] ?? $i['mode']) . ($i['location'] ? ' · ' . e($i['location']) : '') . '</div></td>'
               . '<td data-l="When" class="nowrap">' . e(date('D j M Y, H:i', strtotime($i['scheduled_at']))) . '</td><td data-l="Interviewer">' . e($i['interviewer'] ?? '—') . '</td>'
               . '<td data-l="Status">' . hr_badge(str_replace('_', ' ', $i['status']), ucfirst(str_replace('_', ' ', $i['status']))) . '</td>'
               . '<td data-l="Result">' . ($i['result'] ? e(hr_interview_results()[$i['result']]) . ' ' . hr_stars($i['rating']) : '—') . '</td>'
               . '<td data-l="Feedback" class="small">' . nl2br(e($i['feedback'] ?? '')) . '</td></tr>';
            if ($i['status'] === 'scheduled' && hr_interview_can_feedback($i)) {
                echo '<tr class="hr-editrow"><td colspan="6">' . hr_render_feedback_form($i, $cid, '') . '</td></tr>';
            }
        }
        echo '</tbody></table></div>';
    }
    if ($mayEdit && !in_array($c['stage'], ['hired', 'rejected', 'withdrawn'], true)) {
        echo '<details style="margin-top:12px"><summary class="btn btn-sm btn-primary">Book an interview</summary>' . hr_form_open(hr_url('recruitment', ['cand' => $cid]), 'class="hr-form" style="margin-top:10px"')
           . '<input type="hidden" name="action" value="interview"><input type="hidden" name="cand" value="' . $cid . '"><div class="formgrid">'
           . hr_field('Round', hr_input('round', count($ivs) ? 'Round ' . (count($ivs) + 1) : 'First round', 'text', ['required' => true, 'maxlength' => 60]), true)
           . hr_field('Date and time', hr_input('scheduled_at', date('Y-m-d\T10:00', strtotime('+1 weekday')), 'datetime-local', ['required' => true]), true)
           . hr_field('Mode', hr_select('mode', hr_interview_modes(), 'in_person'))
           . hr_field('Interviewer', hr_select('interviewer_id', hr_employee_choices(true, 'employees', 'view') ?: hr_employee_choices(true, 'recruitment', 'view'), '', 'Choose…'), false,
                      'They are told, and record their feedback from My HR.')
           . hr_field('Place or link', hr_input('location', '', 'text', ['maxlength' => 190]))
           . '</div><button class="btn btn-primary">Book</button></form></details>';
    }
    echo '</div>';
    hr_layout_end();
    return;
}

/** The interviewer's feedback form. */
function hr_render_feedback_form(array $i, int $cid, string $back): string
{
    return hr_form_open('', 'class="hr-form"') . '<input type="hidden" name="action" value="feedback"><input type="hidden" name="id" value="' . (int) $i['id'] . '">'
         . '<input type="hidden" name="cand" value="' . $cid . '"><input type="hidden" name="back" value="' . e($back) . '"><div class="formgrid">'
         . hr_field('What happened', hr_select('status', ['completed' => 'Interview took place', 'no_show' => 'Candidate did not attend', 'cancelled' => 'Cancelled'], 'completed'))
         . hr_field('Recommendation', hr_select('result', hr_interview_results(), '', 'Choose…'))
         . hr_field('Rating', hr_select('rating', [1 => '1 — Poor', 2 => '2', 3 => '3 — Good', 4 => '4', 5 => '5 — Excellent'], '', 'Not rated'))
         . hr_field('Feedback', '<textarea name="feedback" rows="3" maxlength="5000" placeholder="Strengths, concerns, and why you recommend this"></textarea>', false, '', 'full')
         . '</div><button class="btn btn-sm btn-primary">Save feedback</button></form>';
}

/* --------------------------------------------------------- opening form */

$editId = (int) ($_GET['edit'] ?? 0);
if (($editId || !empty($_GET['new'])) && $canView) {
    $o = $editId ? hr_opening($editId, 'edit') : null;
    if ($editId ? !$o : !$canCreate) {
        fabo_deny('You cannot change this opening.', 403);
    }
    $v = $o ?? ($flash ? $_POST : []);
    hr_layout_start($o ? 'Edit opening' : 'New job opening', 'Fields marked <span class="req">*</span> are required.',
                    ['Recruitment' => hr_url('recruitment'), $o ? $o['title'] : 'New' => '']);
    echo hr_flash_html($flash);
    echo '<div class="card">' . hr_form_open(hr_url('recruitment', $o ? ['edit' => $editId] : ['new' => 1]), 'class="hr-form"') . '<input type="hidden" name="action" value="opening_save">'
       . ($o ? '<input type="hidden" name="id" value="' . $editId . '">' : '') . '<div class="formsec"><h3>The role</h3><div class="formgrid">'
       . hr_field('Job title', hr_input('title', $v['title'] ?? '', 'text', ['required' => true, 'maxlength' => 160, 'placeholder' => 'e.g. Senior Accountant']), true)
       . hr_field('Department', hr_select('department_id', hr_departments_map(), $v['department_id'] ?? '', 'Choose…'))
       . hr_field('Designation', hr_select('designation_id', hr_designations_map(), $v['designation_id'] ?? '', 'Choose…'))
       . hr_field('Location', hr_select('location_id', hr_master_map('location'), $v['location_id'] ?? '', 'Choose…'))
       . hr_field('Employment type', hr_select('employment_type_id', hr_master_map('employment_type'), $v['employment_type_id'] ?? '', 'Choose…'))
       . hr_field('Hiring manager', hr_select('hiring_manager_id', hr_employee_choices(true, 'employees', 'view'), $v['hiring_manager_id'] ?? '', 'Choose…'), false,
                  'With a Manager role they see this opening and its candidates. New hires report to them.')
       . hr_field('Positions', hr_input('positions', $v['positions'] ?? 1, 'number', ['min' => 1, 'max' => 999]), true)
       . hr_field('Experience', hr_input('experience', $v['experience'] ?? '', 'text', ['maxlength' => 80, 'placeholder' => 'e.g. 3–5 years']))
       . hr_field('Salary range', hr_input('salary_range', $v['salary_range'] ?? '', 'text', ['maxlength' => 80, 'placeholder' => 'e.g. ₹6–8 lakh a year']))
       . hr_field('Fill by', hr_input('target_date', $v['target_date'] ?? '', 'date'))
       . hr_field('Status', hr_select('status', hr_opening_statuses(), $v['status'] ?? 'open'), true)
       . hr_field('Job description', '<textarea name="description" rows="6" maxlength="10000">' . e($v['description'] ?? '') . '</textarea>', false, '', 'full')
       . '</div></div><div class="hr-formfoot"><button class="btn btn-primary">Save opening</button><a class="btn" href="' . e($o ? hr_url('recruitment', ['opening' => $editId]) : hr_url('recruitment')) . '">Cancel</a></div></form></div>';
    hr_layout_end();
    return;
}

/* ---------------------------------------------------- one opening's board */

$oid = (int) ($_GET['opening'] ?? 0);
if ($oid && $canView) {
    $o = hr_opening($oid);
    if (!$o) {
        fabo_deny('That opening is not available to you.', 404);
    }
    $mayEdit = (bool) hr_opening($oid, 'edit');
    $acts = $mayEdit ? '<a class="btn btn-sm" href="' . e(hr_url('recruitment', ['edit' => $oid])) . '">Edit opening</a>' : '';
    hr_layout_start($o['title'], e(implode(' · ', array_filter([$o['department'], $o['location'], $o['positions'] . ' position' . ((int) $o['positions'] === 1 ? '' : 's'),
                    $o['hiring_manager'] ? 'Hiring manager: ' . $o['hiring_manager'] : null]))) . ' · ' . hr_badge(hr_opening_statuses()[$o['status']], hr_opening_statuses()[$o['status']]),
                    ['Recruitment' => hr_url('recruitment'), $o['title'] => ''], $acts);
    echo hr_flash_html($flash);
    $cands = hr_candidates(['opening' => $oid]);
    $cols = ['applied' => 'Applied', 'screening' => 'Screening', 'interview' => 'Interview', 'offer' => 'Offer', 'hired' => 'Hired'];
    echo '<div class="hr-board">';
    foreach ($cols as $k => $label) {
        $list = array_filter($cands, static fn($c) => $c['stage'] === $k);
        echo '<section class="col"><h3>' . e($label) . ' <span class="muted">' . count($list) . '</span></h3>';
        foreach ($list as $c) {
            echo '<a class="hr-cardlet" href="' . e(hr_url('recruitment', ['cand' => $c['id']])) . '"><strong>' . e($c['full_name']) . '</strong>'
               . '<span class="muted small">' . e(implode(' · ', array_filter([$c['source'], $c['experience_years'] !== null ? hr_fmt_days($c['experience_years']) . ' yrs' : null]))) . '</span>'
               . ($c['next_interview'] ? '<span class="small">Interview ' . e(date('j M, H:i', strtotime($c['next_interview']))) . '</span>' : '') . hr_stars($c['rating']) . '</a>';
        }
        echo $list ? '' : '<p class="muted small">None</p>';
        echo '</section>';
    }
    echo '</div>';
    $out = array_filter($cands, static fn($c) => in_array($c['stage'], ['rejected', 'withdrawn'], true));
    if ($out) {
        echo '<details class="card"><summary><strong>Not progressing (' . count($out) . ')</strong></summary><ul class="hr-list" style="margin-top:8px">';
        foreach ($out as $c) {
            echo '<li><a href="' . e(hr_url('recruitment', ['cand' => $c['id']])) . '">' . e($c['full_name']) . '</a>' . hr_badge($c['stage'], hr_candidate_stages()[$c['stage']]) . '</li>';
        }
        echo '</ul></details>';
    }
    if ($mayEdit) {
        echo '<div class="card"><h2>Add a candidate</h2>' . hr_form_open(hr_url('recruitment', ['opening' => $oid]), 'class="hr-form"', true)
           . '<input type="hidden" name="action" value="cand_save"><input type="hidden" name="opening_id" value="' . $oid . '"><div class="formgrid">'
           . hr_field('Name', hr_input('full_name', '', 'text', ['required' => true, 'maxlength' => 160]), true)
           . hr_field('Email', hr_input('email', '', 'email', ['maxlength' => 190]))
           . hr_field('Phone', hr_input('phone', '', 'tel', ['maxlength' => 40]))
           . hr_field('Source', hr_select('source', array_combine(hr_candidate_sources(), hr_candidate_sources()), '', 'Not known'))
           . hr_field('Current company', hr_input('current_company', '', 'text', ['maxlength' => 120]))
           . hr_field('Experience (years)', hr_input('experience_years', '', 'number', ['step' => '0.5', 'min' => 0]))
           . hr_field('Expected salary (₹ a year)', hr_input('expected_salary', '', 'number', ['min' => 0]))
           . hr_field('Notice period (days)', hr_input('notice_days', '', 'number', ['min' => 0]))
           . hr_field('Résumé', '<input type="file" name="resume" accept=".pdf,.doc,.docx,.odt,.rtf,.txt">')
           . '</div><button class="btn btn-primary">Add candidate</button></form></div>';
    }
    if ($o['description']) {
        echo '<div class="card"><h2>Job description</h2><div class="hr-prose">' . nl2br(e($o['description'])) . '</div></div>';
    }
    if (hr_scope('recruitment', 'delete') === 'org' && !$cands) {
        echo hr_button_form('Delete opening', ['action' => 'opening_delete', 'id' => $oid], 'btn btn-sm btn-danger', 'Delete this opening?');
    }
    hr_layout_end();
    return;
}

/* -------------------------------------------------------------- the desk */

$tabs = [];
if ($canView) {
    $tabs['openings'] = 'Openings';
    $tabs['candidates'] = 'All candidates';
}
if ($mineIv) {
    $n = count(array_filter($mineIv, static fn($i) => $i['status'] === 'scheduled'));
    $tabs['myinterviews'] = 'My interviews' . ($n ? ' <span class="badge">' . $n . '</span>' : '');
}
$tab = hr_tab($tabs, $canView ? 'openings' : 'myinterviews');
hr_layout_start('Recruitment', 'Open positions, candidates and interviews — from application to the first day.', ['Recruitment' => ''],
                $canCreate ? '<a class="btn btn-sm btn-primary" href="' . e(hr_url('recruitment', ['new' => 1])) . '">+ New opening</a>' : '');
echo hr_flash_html($flash);
hr_tabs('recruitment', $tabs, $tab);

if ($tab === 'openings') {
    echo hr_guide('How recruitment works', [
        'Create an <strong>opening</strong> for each role, with its department, hiring manager and number of positions.',
        'Add <strong>candidates</strong> to the opening and move them along the board: Applied → Screening → Interview → Offer.',
        'Book <strong>interviews</strong> with an employee as interviewer; they record feedback from their own My HR page.',
        'At the offer stage, download the <strong>offer letter</strong>. Then press <strong>Hire</strong>: the employee record is created and onboarding starts.',
    ]);
    $rows = hr_openings();
    $active = array_filter($rows, static fn($o) => in_array($o['status'], ['open', 'on_hold', 'draft'], true));
    $positions = array_sum(array_map(static fn($o) => max(0, (int) $o['positions'] - (int) $o['hired']), $active));
    $pipeline = array_sum(array_map(static fn($o) => (int) $o['active'], $active));
    echo '<div class="tiles">' . hr_tile('Open positions', (string) $positions, $positions ? 'amber' : '') . hr_tile('Candidates in progress', (string) $pipeline)
       . hr_tile('Hired this year', (string) (int) (fabo_one("SELECT COUNT(*) n FROM hr_candidates WHERE org_id = ? AND stage = 'hired' AND YEAR(updated_at) = YEAR(CURDATE())", 'i', [hr_org()])['n'] ?? 0), 'green')
       . hr_tile('Interviews this week', (string) (int) (fabo_one("SELECT COUNT(*) n FROM hr_interviews WHERE org_id = ? AND status = 'scheduled' AND scheduled_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)", 'i', [hr_org()])['n'] ?? 0))
       . '</div>';
    echo '<div class="card">';
    if (!$rows) {
        echo hr_empty('No openings yet.', $canCreate ? ' Press “New opening” to add the first role you are hiring for.' : '');
    } else {
        echo '<div class="scrollx"><table class="data hr-table stack"><thead><tr><th>Opening</th><th>Department</th><th>Hiring manager</th><th class="num">Positions</th><th class="num">In pipeline</th><th class="num">Hired</th><th>Fill by</th><th>Status</th><th></th></tr></thead><tbody>';
        foreach ($rows as $o) {
            echo '<tr><td data-l="Opening"><a class="nm" href="' . e(hr_url('recruitment', ['opening' => $o['id']])) . '"><strong>' . e($o['title']) . '</strong></a>'
               . ($o['location'] ? '<div class="muted small">' . e($o['location']) . '</div>' : '') . '</td>'
               . '<td data-l="Department">' . e($o['department'] ?? '—') . '</td><td data-l="Manager">' . e($o['hiring_manager'] ?? '—') . '</td>'
               . '<td class="num" data-l="Positions">' . (int) $o['positions'] . '</td><td class="num" data-l="In pipeline">' . (int) $o['active'] . '</td>'
               . '<td class="num" data-l="Hired">' . (int) $o['hired'] . '</td><td data-l="Fill by" class="nowrap">' . e(hr_fmt_date($o['target_date'])) . '</td>'
               . '<td data-l="Status">' . hr_badge(strtolower(str_replace('_', ' ', $o['status'])), hr_opening_statuses()[$o['status']]) . '</td>'
               . '<td><a class="btn btn-sm" href="' . e(hr_url('recruitment', ['opening' => $o['id']])) . '">Board</a></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';
}

if ($tab === 'candidates') {
    $f = ['q' => trim((string) ($_GET['q'] ?? '')), 'stage' => array_key_exists('stage', $_GET) ? (string) $_GET['stage'] : 'active', 'opening' => (int) ($_GET['opening_f'] ?? 0)];
    $rows = hr_candidates($f);
    echo '<form method="get" class="card hr-filters" role="search"><input type="hidden" name="p" value="recruitment"><input type="hidden" name="tab" value="candidates">'
       . '<div class="grp"><label for="cq">Search</label><input id="cq" type="search" name="q" value="' . e($f['q']) . '" placeholder="Name, email or phone"></div>'
       . '<div class="grp"><label for="cs">Stage</label><select id="cs" name="stage" data-autosubmit>' . hr_options(['active' => 'In progress', '' => 'All'] + hr_candidate_stages(), $f['stage']) . '</select></div>'
       . '<div class="grp"><label for="co">Opening</label><select id="co" name="opening_f" data-autosubmit>' . hr_options(array_column(hr_openings(), 'title', 'id'), $f['opening'], 'All') . '</select></div>'
       . '<button class="btn btn-sm btn-primary">Search</button></form><div class="card">';
    if (!$rows) {
        echo hr_empty('No candidates match.');
    } else {
        echo '<div class="scrollx"><table class="data hr-table stack"><thead><tr><th>Candidate</th><th>Opening</th><th>Stage</th><th>Source</th><th>Rating</th><th>Next interview</th></tr></thead><tbody>';
        foreach ($rows as $c) {
            echo '<tr><td data-l="Candidate"><a class="nm" href="' . e(hr_url('recruitment', ['cand' => $c['id']])) . '"><strong>' . e($c['full_name']) . '</strong></a><div class="muted small">' . e($c['email'] ?? '') . '</div></td>'
               . '<td data-l="Opening">' . e($c['opening']) . '</td><td data-l="Stage">' . hr_badge($c['stage'], hr_candidate_stages()[$c['stage']]) . '</td>'
               . '<td data-l="Source">' . e($c['source'] ?? '—') . '</td><td data-l="Rating">' . (hr_stars($c['rating']) ?: '—') . '</td>'
               . '<td data-l="Next interview" class="nowrap">' . e($c['next_interview'] ? date('d M H:i', strtotime($c['next_interview'])) : '—') . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';
}

if ($tab === 'myinterviews') {
    echo '<div class="card"><h2>Interviews you are taking</h2>';
    if (!$mineIv) {
        echo hr_empty('None.');
    } else {
        echo '<div class="scrollx"><table class="data hr-table stack"><thead><tr><th>Candidate</th><th>Role</th><th>Round</th><th>When</th><th>Status</th></tr></thead><tbody>';
        foreach ($mineIv as $i) {
            echo '<tr><td data-l="Candidate"><strong>' . e($i['candidate']) . '</strong></td><td data-l="Role">' . e($i['opening']) . '</td><td data-l="Round">' . e($i['round'])
               . '<div class="muted small">' . e(hr_interview_modes()[$i['mode']] ?? '') . ($i['location'] ? ' · ' . e($i['location']) : '') . '</div></td>'
               . '<td data-l="When" class="nowrap">' . e(date('D j M, H:i', strtotime($i['scheduled_at']))) . '</td>'
               . '<td data-l="Status">' . hr_badge(str_replace('_', ' ', $i['status']), ucfirst(str_replace('_', ' ', $i['status']))) . '</td></tr>';
            if ($i['status'] === 'scheduled') {
                echo '<tr class="hr-editrow"><td colspan="5">' . hr_render_feedback_form($i, (int) $i['cand_id'], 'mine') . '</td></tr>';
            }
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';
}

hr_layout_end();

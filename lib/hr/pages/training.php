<?php
/**
 * Training & Development — the session calendar, who is enrolled, outcomes,
 * and each employee's own training record.
 *
 *   ?id=ID   one session
 */

require_once __DIR__ . '/parts.php';

$self = hr_self_emp();
$hr = hr_is_admin() || hr_scope('training', 'edit') === 'org';
$canAdd = hr_is_admin() || hr_scope('training', 'create') === 'org';
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    $tid = (int) ($_POST['training'] ?? 0);
    $back = $tid ? hr_url('training', ['id' => $tid]) : hr_url('training', ['tab' => (string) ($_GET['tab'] ?? '')]);
    if (in_array($a, ['master_save', 'master_toggle', 'master_delete', 'master_import'], true)) {
        $flash = hr_parts_post(hr_url('training', ['tab' => 'setup']));
    } elseif ($a === 'save') {
        $flash = hr_action(static function () use ($tid) {
            $id = hr_training_save($tid ?: null, $_POST);
            hr_redirect(hr_url('training', ['id' => $id]), ['ok', 'Session saved.']);
        }, $back);
    } elseif ($a === 'delete') {
        $flash = hr_action(static function () use ($tid) { hr_training_delete($tid); return 'Session deleted.'; }, hr_url('training'));
    } elseif ($a === 'enrol') {
        $flash = hr_action(static function () use ($tid) {
            $ids = array_map('intval', (array) ($_POST['emp'] ?? []));
            if (!$ids && !empty($_POST['department'])) {
                $ids = array_column(fabo_all("SELECT id FROM hr_employees WHERE org_id = ? AND department_id = ? AND status IN ('active','probation','notice')",
                                             'ii', [hr_org(), (int) $_POST['department']]), 'id');
            }
            if (!$ids) { throw new InvalidArgumentException('Choose who to enrol.'); }
            $n = hr_enrol($tid, $ids);
            return $n . ' enrolled.';
        }, $back);
    } elseif ($a === 'self_enrol') {
        $flash = hr_action(static function () use ($tid, $self) { hr_enrol($tid, [(int) $self['id']]); return 'You are enrolled.'; }, $back);
    } elseif ($a === 'enrolment') {
        $flash = hr_action(static function () { return hr_enrolment_update((int) ($_POST['id'] ?? 0), $_POST); }, $back);
    } elseif ($a === 'complete_all') {
        $flash = hr_action(static function () use ($tid) { $n = hr_training_complete_all($tid); return 'Session completed. ' . $n . ' attendee(s) marked completed.'; }, $back);
    }
}

/* -------------------------------------------------------------- one session */

$tid = (int) ($_GET['id'] ?? 0);
if ($tid || !empty($_GET['new'])) {
    $t = $tid ? hr_training($tid) : null;
    if ($tid && !$t) {
        fabo_deny('That session no longer exists.', 404);
    }
    $editing = $hr && (!$tid || !empty($_GET['edit']));
    if (!$tid && !$canAdd) {
        fabo_deny('Only HR plans training.', 403);
    }
    if ($editing) {
        $v = $t ?? ($flash ? $_POST : []);
        hr_layout_start($t ? 'Edit session' : 'Plan a training session', '', ['Training' => hr_url('training'), $t ? $t['title'] : 'New' => '']);
        echo hr_flash_html($flash);
        echo '<div class="card">' . hr_form_open($t ? hr_url('training', ['id' => $tid, 'edit' => 1]) : hr_url('training', ['new' => 1]), 'class="hr-form"')
           . '<input type="hidden" name="action" value="save"><input type="hidden" name="training" value="' . $tid . '"><div class="formgrid">'
           . hr_field('Title', hr_input('title', $v['title'] ?? '', 'text', ['required' => true, 'maxlength' => 160, 'placeholder' => 'e.g. POSH awareness']), true)
           . hr_field('Category', hr_select('category_id', hr_master_map('training_category'), $v['category_id'] ?? '', 'None'))
           . hr_field('Delivered as', hr_select('mode', hr_training_modes(), $v['mode'] ?? 'classroom'), true)
           . hr_field('Trainer', hr_input('trainer', $v['trainer'] ?? '', 'text', ['maxlength' => 160]))
           . hr_field('Place or link', hr_input('location', $v['location'] ?? '', 'text', ['maxlength' => 190]))
           . hr_field('Starts', hr_input('start_date', $v['start_date'] ?? '', 'date', ['required' => true]), true)
           . hr_field('Ends', hr_input('end_date', $v['end_date'] ?? '', 'date'))
           . hr_field('Hours', hr_input('hours', $v['hours'] ?? '', 'number', ['step' => '0.5', 'min' => 0]))
           . hr_field('Places', hr_input('capacity', $v['capacity'] ?? '', 'number', ['min' => 1, 'placeholder' => 'No limit']))
           . hr_field('Status', hr_select('status', hr_training_statuses(), $v['status'] ?? 'planned'), true)
           . hr_field('Description', '<textarea name="description" rows="4" maxlength="5000">' . e($v['description'] ?? '') . '</textarea>', false, '', 'full')
           . '</div><div class="field">' . hr_checkbox('mandatory', !empty($v['mandatory']), 'Mandatory for those enrolled') . '</div>'
           . '<div class="field">' . hr_checkbox('open_enrol', !empty($v['open_enrol']), 'Employees may enrol themselves from My HR') . '</div>'
           . '<div class="hr-formfoot"><button class="btn btn-primary">Save</button><a class="btn" href="' . e($t ? hr_url('training', ['id' => $tid]) : hr_url('training')) . '">Cancel</a></div></form></div>';
        hr_layout_end();
        return;
    }
    $acts = $hr ? '<a class="btn btn-sm" href="' . e(hr_url('training', ['id' => $tid, 'edit' => 1])) . '">Edit</a>' : '';
    hr_layout_start($t['title'], e(hr_training_modes()[$t['mode']]) . ' · ' . e(hr_fmt_date($t['start_date'])) . ($t['end_date'] && $t['end_date'] !== $t['start_date'] ? ' – ' . e(hr_fmt_date($t['end_date'])) : '')
                  . ' · ' . hr_badge(strtolower(hr_training_statuses()[$t['status']]), hr_training_statuses()[$t['status']]), ['Training' => hr_url('training'), $t['title'] => ''], $acts);
    echo hr_flash_html($flash);
    echo '<div class="hr-two"><div class="card"><h2>About</h2><dl class="hr-kv">'
       . '<dt>Category</dt><dd>' . e($t['category'] ?? '—') . '</dd><dt>Trainer</dt><dd>' . e($t['trainer'] ?? '—') . '</dd>'
       . '<dt>Place</dt><dd>' . e($t['location'] ?? '—') . '</dd><dt>Hours</dt><dd>' . ($t['hours'] !== null ? e(hr_fmt_days($t['hours'])) : '—') . '</dd>'
       . '<dt>Places</dt><dd>' . (int) $t['enrolled'] . ($t['capacity'] ? ' of ' . (int) $t['capacity'] . ' taken' : ' enrolled · no limit') . '</dd>'
       . '<dt>Mandatory</dt><dd>' . ($t['mandatory'] ? 'Yes' : 'No') . '</dd><dt>Self-enrolment</dt><dd>' . ($t['open_enrol'] ? 'Open' : 'HR enrols people') . '</dd>'
       . '</dl>' . ($t['description'] ? '<div class="hr-prose" style="margin-top:12px">' . nl2br(e($t['description'])) . '</div>' : '') . '</div>';
    echo '<div class="card"><h2>Your place</h2>';
    $mine = $self ? fabo_one('SELECT * FROM hr_training_enrolments WHERE training_id = ? AND employee_id = ?', 'ii', [$tid, (int) $self['id']]) : null;
    if ($mine && $mine['status'] !== 'cancelled') {
        echo '<p class="mt0">' . hr_badge(strtolower(hr_enrolment_statuses()[$mine['status']]), hr_enrolment_statuses()[$mine['status']]) . '</p>';
        if ($mine['status'] === 'enrolled' && !$t['mandatory']) {
            echo hr_button_form('Cancel my place', ['action' => 'enrolment', 'id' => $mine['id'], 'what' => 'cancel', 'training' => $tid], 'btn btn-sm', 'Give up your place?');
        }
        if ($mine['status'] === 'completed') {
            echo hr_form_open(hr_url('training', ['id' => $tid]), 'class="hr-form"') . '<input type="hidden" name="action" value="enrolment"><input type="hidden" name="what" value="feedback">'
               . '<input type="hidden" name="id" value="' . (int) $mine['id'] . '"><input type="hidden" name="training" value="' . $tid . '"><div class="formgrid">'
               . hr_field('How useful was it?', hr_select('rating', [1 => '1 — Not useful', 2 => '2', 3 => '3 — Useful', 4 => '4', 5 => '5 — Very useful'], $mine['rating'], 'Rate…'))
               . hr_field('Feedback', '<textarea name="feedback" rows="2" maxlength="1000">' . e($mine['feedback'] ?? '') . '</textarea>', false, '', 'full')
               . '</div><button class="btn btn-sm btn-primary">Send feedback</button></form>';
        }
    } elseif ($self && $t['open_enrol'] && in_array($t['status'], ['planned', 'open'], true) && hr_can('training', 'create')) {
        echo hr_button_form('Enrol me', ['action' => 'self_enrol', 'training' => $tid], 'btn btn-primary');
    } else {
        echo hr_empty($t['open_enrol'] ? 'Enrolment is closed.' : 'HR enrols people in this session.');
    }
    echo '</div></div>';

    $rows = hr_enrolments($tid);
    echo '<div class="card"><h2>Attendees (' . count(array_filter($rows, static fn($r) => $r['status'] !== 'cancelled')) . ')</h2>';
    if (!$rows) {
        echo hr_empty('Nobody enrolled yet.');
    } else {
        echo '<div class="scrollx"><table class="data hr-table stack"><thead><tr><th>Employee</th><th>Department</th><th>Status</th><th>Score</th><th>Feedback</th>' . ($hr ? '<th>Record outcome</th>' : '') . '</tr></thead><tbody>';
        foreach ($rows as $x) {
            echo '<tr><td data-l="Employee">' . hr_person_cell($x) . '</td><td data-l="Department">' . e($x['department'] ?? '—') . '</td>'
               . '<td data-l="Status">' . hr_badge(strtolower(str_replace('_', ' ', hr_enrolment_statuses()[$x['status']])), hr_enrolment_statuses()[$x['status']]) . '</td>'
               . '<td data-l="Score">' . ($x['score'] !== null ? e(hr_fmt_days($x['score'])) : '—') . '</td>'
               . '<td data-l="Feedback" class="small">' . ($x['rating'] ? hr_stars($x['rating']) . ' ' : '') . e($x['feedback'] ?? '') . '</td>';
            if ($hr) {
                echo '<td data-l="Outcome">' . hr_form_open(hr_url('training', ['id' => $tid]), 'class="hr-inline-form"') . '<input type="hidden" name="action" value="enrolment"><input type="hidden" name="what" value="outcome">'
                   . '<input type="hidden" name="id" value="' . (int) $x['id'] . '"><input type="hidden" name="training" value="' . $tid . '">'
                   . '<select name="status" aria-label="Outcome">' . hr_options(hr_enrolment_statuses(), $x['status']) . '</select>'
                   . '<input type="number" name="score" value="' . e($x['score'] ?? '') . '" step="0.1" min="0" placeholder="Score" style="width:80px" aria-label="Score">'
                   . '<button class="btn btn-sm">Save</button></form></td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        if ($hr && $t['status'] !== 'completed') {
            echo '<div class="hr-formfoot" style="margin-top:10px">' . hr_button_form('Mark the session completed', ['action' => 'complete_all', 'training' => $tid], 'btn btn-sm btn-approve',
                 'Mark the session completed and everyone still “Enrolled” as completed?') . '</div>';
        }
    }
    if ($hr && in_array($t['status'], ['planned', 'open'], true)) {
        $taken = array_column(array_filter($rows, static fn($r) => $r['status'] !== 'cancelled'), 'employee_id');
        $choices = array_diff_key(hr_employee_choices(true, 'training', 'edit'), array_flip(array_map('intval', $taken)));
        echo '<h3 style="margin-top:16px">Enrol people</h3>' . hr_form_open(hr_url('training', ['id' => $tid]), 'class="hr-form"') . '<input type="hidden" name="action" value="enrol"><input type="hidden" name="training" value="' . $tid . '">'
           . '<div class="formgrid">' . hr_field('Employees', '<select name="emp[]" multiple size="8">' . hr_options($choices, '') . '</select>', false, 'Hold Ctrl (or ⌘) to choose several.')
           . hr_field('Or a whole department', hr_select('department', hr_departments_map(), '', 'Choose…')) . '</div><button class="btn btn-primary">Enrol</button></form>';
    }
    echo '</div>';
    if ($hr && hr_scope('training', 'delete') === 'org') {
        echo hr_button_form('Delete session', ['action' => 'delete', 'training' => $tid], 'btn btn-sm btn-danger', 'Delete this session and its enrolments?');
    }
    hr_layout_end();
    return;
}

/* --------------------------------------------------------------- the desk */

$tabs = ['upcoming' => 'Upcoming', 'past' => 'Past'];
if ($self) { $tabs['mine'] = 'My training'; }
if ($hr) { $tabs['setup'] = 'Categories'; }
$tab = hr_tab($tabs, 'upcoming');
hr_layout_start('Training & Development', 'Sessions, who attends, and everyone\'s training record.', ['Training' => ''],
                $canAdd ? '<a class="btn btn-sm btn-primary" href="' . e(hr_url('training', ['new' => 1])) . '">+ Plan a session</a>' : '');
echo hr_flash_html($flash);
hr_tabs('training', $tabs, $tab);

if ($tab === 'upcoming' || $tab === 'past') {
    $rows = hr_trainings($tab);
    if ($tab === 'upcoming') {
        echo hr_guide('How training works', [
            'HR plans a session with its dates, trainer and number of places, and enrols people — one by one or a whole department.',
            'Tick “Employees may enrol themselves” to let people join from My HR until the places run out.',
            'After the session, HR records who completed it (and a score if there was a test). Attendees can then rate it.',
        ]);
    }
    echo '<div class="card">';
    if (!$rows) {
        echo hr_empty($tab === 'upcoming' ? 'No sessions coming up.' : 'No past sessions.', $canAdd && $tab === 'upcoming' ? ' Press “Plan a session” to add one.' : '');
    } else {
        echo '<div class="scrollx"><table class="data hr-table stack"><thead><tr><th>Session</th><th>When</th><th>Mode</th><th class="num">Enrolled</th><th>Status</th><th></th></tr></thead><tbody>';
        foreach ($rows as $t) {
            echo '<tr><td data-l="Session"><a class="nm" href="' . e(hr_url('training', ['id' => $t['id']])) . '"><strong>' . e($t['title']) . '</strong></a>'
               . '<div class="muted small">' . e(implode(' · ', array_filter([$t['category'], $t['trainer'], $t['mandatory'] ? 'Mandatory' : null, $t['open_enrol'] ? 'Open enrolment' : null]))) . '</div></td>'
               . '<td data-l="When" class="nowrap">' . e(hr_fmt_date($t['start_date'])) . ($t['end_date'] && $t['end_date'] !== $t['start_date'] ? ' – ' . e(hr_fmt_date($t['end_date'])) : '') . '</td>'
               . '<td data-l="Mode">' . e(hr_training_modes()[$t['mode']] ?? $t['mode']) . '</td>'
               . '<td class="num" data-l="Enrolled">' . (int) $t['enrolled'] . ($t['capacity'] ? ' / ' . (int) $t['capacity'] : '') . '</td>'
               . '<td data-l="Status">' . hr_badge(strtolower(hr_training_statuses()[$t['status']]), hr_training_statuses()[$t['status']]) . '</td>'
               . '<td><a class="btn btn-sm" href="' . e(hr_url('training', ['id' => $t['id']])) . '">Open</a></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';
}

if ($tab === 'mine') {
    $rows = hr_emp_trainings((int) $self['id']);
    $hours = array_sum(array_map(static fn($r) => $r['status'] === 'completed' ? (float) $r['hours'] : 0, $rows));
    echo '<div class="tiles">' . hr_tile('Sessions completed', (string) count(array_filter($rows, static fn($r) => $r['status'] === 'completed')), 'green')
       . hr_tile('Training hours', hr_fmt_days($hours)) . hr_tile('Coming up', (string) count(array_filter($rows, static fn($r) => $r['status'] === 'enrolled'))) . '</div>';
    echo '<div class="card"><h2>My training record</h2>';
    if (!$rows) {
        echo hr_empty('No training yet.');
    } else {
        echo '<ul class="hr-list">';
        foreach ($rows as $r) {
            echo '<li><span><a href="' . e(hr_url('training', ['id' => $r['training_id']])) . '">' . e($r['title']) . '</a> <span class="muted small">' . e(hr_fmt_date($r['start_date']))
               . ($r['mandatory'] ? ' · Mandatory' : '') . '</span></span>' . hr_badge(strtolower(str_replace('_', ' ', hr_enrolment_statuses()[$r['status']])), hr_enrolment_statuses()[$r['status']]) . '</li>';
        }
        echo '</ul>';
    }
    echo '</div>';
    $open = array_filter(hr_trainings('upcoming'), static fn($t) => $t['open_enrol']);
    if ($open) {
        echo '<div class="card"><h2>Open for enrolment</h2><ul class="hr-list">';
        foreach ($open as $t) {
            echo '<li><span><a href="' . e(hr_url('training', ['id' => $t['id']])) . '">' . e($t['title']) . '</a> <span class="muted small">' . e(hr_fmt_date($t['start_date']))
               . ($t['capacity'] ? ' · ' . max(0, (int) $t['capacity'] - (int) $t['enrolled']) . ' places left' : '') . '</span></span>'
               . '<a class="btn btn-sm" href="' . e(hr_url('training', ['id' => $t['id']])) . '">View</a></li>';
        }
        echo '</ul></div>';
    }
}

if ($tab === 'setup') {
    hr_part_masters('training_category', hr_url('training', ['tab' => 'setup']), ['tab' => 'setup']);
}

hr_layout_end();

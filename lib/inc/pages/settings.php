<?php
/**
 * Incubation Management — administration: forms, development stages,
 * evaluation templates, lists,
 * teams, notifications, general rules and the audit trail. (Pipeline and
 * lifecycle stages are managed on the boards: lib/inc/stages_ui.php.)
 */

require_once __DIR__ . '/../certs.php';   // the Certificates tab's actions run before the page is drawn

$tabs = ['forms' => 'Forms', 'devstages' => 'Development stages',
         'evaluation' => 'Evaluation templates', 'lists' => 'Lists', 'teams' => 'Departments', 'notifications' => 'Notifications', 'certificates' => 'Certificates', 'general' => 'General', 'audit' => 'Audit trail'];
if (inc_kind() === 'eo') {
    unset($tabs['devstages']);
}
// Stages are added and edited on the boards themselves now; old links land there.
if (in_array($_GET['tab'] ?? '', ['pipeline', 'lifecycle'], true)) {
    inc_redirect($_GET['tab'] === 'pipeline' ? inc_url('applications', ['view' => 'board']) : inc_url('workspaces'));
}
$tab = isset($tabs[$_GET['tab'] ?? '']) ? (string) $_GET['tab'] : 'forms';
$self = inc_url('settings', ['tab' => $tab] + (isset($_GET['kind']) ? ['kind' => (string) $_GET['kind']] : []));
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    $flash = inc_action(static function () use ($a, $tab) {
        switch ($a) {
            case 'form_create':
                $fid = inc_form_create($_POST);
                inc_redirect(inc_url('form', ['id' => $fid]), ['ok', 'Form created with the fields its purpose needs. Review them, then publish.']);
            case 'purpose_save':
                $code = (string) ($_POST['code'] ?? '');
                $saved = inc_purpose_save($code !== '' ? $code : null, $_POST);
                if ($code === '') {
                    // A new purpose gets its own starting questions, ready to edit.
                    $tpl = inc_purpose_template($saved, true);
                    inc_redirect(inc_url('form', ['id' => $tpl]), ['ok', 'Purpose added. These are its own starting questions: change them as you like, and every new form of this purpose starts with them.']);
                }
                return 'Purpose saved.';
            case 'purpose_questions':
                $tpl = inc_purpose_template((string) ($_POST['code'] ?? ''), true);
                if (!$tpl) {
                    throw new InvalidArgumentException('That purpose no longer exists.');
                }
                inc_redirect(inc_url('form', ['id' => $tpl]));
            case 'purpose_delete':
                inc_purpose_delete((string) ($_POST['code'] ?? ''));
                return 'Purpose removed.';
            case 'dev_save':
                inc_need('configure');
                $code = (string) ($_POST['code'] ?? '');
                if (!isset(inc_dev_stages()[$code])) {
                    throw new InvalidArgumentException('Unknown stage.');
                }
                $name = inc_str($_POST['name'] ?? '', 80, true, 'Name');
                $desc = inc_str($_POST['description'] ?? '', 500);
                $before = inc_one('SELECT * FROM inc_dev_stages WHERE code = ?', 's', [$code]);
                inc_exec('UPDATE inc_dev_stages SET name = ?, description = ?, active = ? WHERE code = ?', 'ssis', [$name, $desc, !empty($_POST['active']) ? 1 : 0, $code]);
                inc_audit('update', 'dev_stage', null, $code, $before, ['name' => $name, 'description' => $desc]);
                return 'Development stage saved.';
            case 'master_save':
                inc_master_save((string) ($_POST['kind'] ?? ''), inc_int_or_null($_POST['id'] ?? null), $_POST);
                return 'Saved.';
            case 'team_save':
                $tid = inc_team_save(inc_int_or_null($_POST['id'] ?? null), $_POST);
                // Its people: ticked from the organisation's existing team, nobody created here.
                inc_dept_members_save($tid, (array) ($_POST['people'] ?? []));
                inc_dept_stages($tid);                       // a new department starts with New / In progress / Completed
                inc_redirect(inc_url('settings', ['tab' => 'teams', 'team' => $tid]), ['ok', inc_dept_kinds()[inc_dept_kind_of(inc_team($tid))][0] . ' saved.']);
            case 'member_add':
                inc_team_member_add((int) ($_POST['team_id'] ?? 0), (int) ($_POST['person_id'] ?? 0), (string) ($_POST['role'] ?? ''));
                inc_redirect(inc_url('settings', ['tab' => 'teams', 'team' => (int) $_POST['team_id']]), ['ok', 'Member added.']);
            case 'member_remove':
                inc_team_member_remove((int) ($_POST['team_id'] ?? 0), (int) ($_POST['person_id'] ?? 0), (string) ($_POST['role'] ?? ''));
                inc_redirect(inc_url('settings', ['tab' => 'teams', 'team' => (int) $_POST['team_id']]), ['ok', 'Member removed.']);
            case 'mail_save':
                inc_mail_template_save((string) ($_POST['event'] ?? ''), $_POST);
                return 'Notification saved.';
            case 'retry':
                [, $msg] = inc_notify_retry((int) ($_POST['log_id'] ?? 0));
                return $msg;
            case 'cancel_queued':
                $n = inc_notify_cancel_queued();
                return $n ? number_format($n) . ' queued email' . ($n === 1 ? '' : 's') . ' cancelled. They will not be sent to anyone.' : 'No emails were waiting in the queue.';
            case 'general':
                inc_need('configure');
                $d = (string) ($_POST['dup_window_days'] ?? '180');
                if (!ctype_digit($d) || (int) $d < 1 || (int) $d > 3650) {
                    throw new InvalidArgumentException('The duplicate window must be between 1 and 3650 days.');
                }
                $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($_POST['ref_prefix'] ?? 'INC')));
                if ($prefix === '' || strlen($prefix) > 8) {
                    throw new InvalidArgumentException('The reference prefix must be 1–8 letters or digits.');
                }
                inc_setting_set('dup_window_days', (string) (int) $d);
                inc_setting_set('ref_prefix', $prefix);
                inc_setting_set('move_crm_on_assign', !empty($_POST['move_crm_on_assign']) ? '1' : '0');
                inc_setting_set('notify_prev_owner', !empty($_POST['notify_prev_owner']) ? '1' : '0');
                inc_setting_set('public_index', !empty($_POST['public_index']) ? '1' : '0');
                $labels = [];
                foreach (['1', '2', '3', '4', '5', 'na'] as $k) {
                    $labels[$k] = inc_str($_POST['rl_' . $k] ?? '', 160, true, 'Rating definition ' . $k);
                }
                inc_setting_set('rating_labels', json_encode($labels));
                return 'Settings saved.';
            case 'cert_save':
                inc_cert_template_save($_POST);
                return 'Certificate template saved.';
            case 'cert_logo_add':
                inc_cert_logo_add($_FILES['logo'] ?? null);
                return 'Logo added.';
            case 'cert_logo':
                inc_cert_logo_change((int) ($_POST['i'] ?? -1), (string) ($_POST['do'] ?? ''), $_FILES['logo'] ?? null);
                return ['replace' => 'Logo replaced.', 'remove' => 'Logo removed.'][$_POST['do'] ?? ''] ?? 'Logo moved.';
            case 'cert_sig':
                inc_cert_signatory_save((int) ($_POST['i'] ?? -1), $_POST, $_FILES['sig'] ?? null);
                return !empty($_POST['remove']) ? 'Signatory removed.' : 'Signatory saved.';
            case 'cert_stamp':
                inc_cert_stamp_change((string) ($_POST['do'] ?? ''), $_FILES['stamp'] ?? null);
                return ($_POST['do'] ?? '') === 'remove' ? 'Seal removed — certificates use the digital stamp.' : 'Seal saved.';
            case 'cert_stages':
                inc_cert_stages_save($_POST);
                return 'Saved. Applications reaching the ticked stages are sent their certificate automatically.';
            case 'cert_resend':
                inc_need('edit');
                $cr = inc_one('SELECT * FROM inc_certificates WHERE id = ?', 'i', [(int) ($_POST['id'] ?? 0)]);
                if (!$cr || $cr['revoked_at']) {
                    throw new InvalidArgumentException('That certificate cannot be sent.');
                }
                [, $st, $why] = inc_cert_issue((int) $cr['application_id'], (int) $cr['stage_id']);
                return $st === 'sent' || $st === 'queued' ? 'Certificate emailed again.' : 'Not sent: ' . $why;
            case 'cert_revoke':
                inc_cert_revoke((int) ($_POST['id'] ?? 0), (string) ($_POST['reason'] ?? ''));
                return 'Certificate revoked. Its link now says it is no longer valid.';
            case 'tpl_new':
                $tid = inc_template_new_version((int) ($_POST['from'] ?? 0));
                inc_redirect(inc_url('template', ['id' => $tid]), ['ok', 'Draft version created. Edit it, then publish.']);
        }
        throw new InvalidArgumentException('Unknown action.');
    }, $self);
}

// The certificate preview, on its own page (shown in the frame on the Certificates tab).
if ($tab === 'certificates' && !empty($_GET['cert_preview'])) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8">' . inc_cert_fonts()
       . '<style>body{margin:0;background:#e9edf3}.w{transform:scale(var(--k,.5));transform-origin:top left;width:297mm}</style></head><body>'
       . '<div class="w" id="w">' . inc_cert_render(inc_cert_preview_snapshot()) . '</div>'
       . '<script>function f(){var k=document.documentElement.clientWidth/document.getElementById("w").offsetWidth;document.getElementById("w").style.setProperty("--k",k);}addEventListener("resize",f);f();</script></body></html>';
    exit;
}

inc_layout_start('Incubation settings', 'Everything here belongs to Incubation Management only — the main CRM is not affected.', ['Settings' => null], '', $flash);
echo '<nav class="subtabs" aria-label="Settings sections">';
foreach ($tabs as $k => $lab) {
    echo '<a href="' . e(inc_url('settings', ['tab' => $k])) . '"' . ($k === $tab ? ' class="on" aria-current="page"' : '') . '>' . e($lab) . '</a>';
}
echo '</nav>';

if ($tab === 'forms') {
    $forms = inc_forms_list(true);
    echo '<section class="card"><h2>Application forms</h2>';
    if (!$forms) {
        echo inc_empty('No forms yet.', 'Create one below — choosing its purpose loads the fields that purpose needs.');
    } else {
        echo '<div class="scrollx"><table class="data stack"><thead><tr><th>Form</th><th>Purpose</th><th>Published</th><th>Draft</th><th>Public link</th><th class="num">Applications</th><th></th></tr></thead><tbody>';
        foreach ($forms as $f) {
            echo '<tr' . ($f['archived'] ? ' class="muted"' : '') . '><td data-l="Form"><strong>' . e($f['name']) . '</strong>' . ($f['archived'] ? ' ' . inc_badge('Archived') : '') . '</td>'
               . '<td data-l="Purpose">' . e(inc_purpose_text($f['purpose'], $f['event_name'] ?? null)) . '</td>'
               . '<td data-l="Published">' . ($f['published_no'] ? 'v' . (int) $f['published_no'] : '<span class="muted">—</span>') . '</td>'
               . '<td data-l="Draft">' . ($f['draft_no'] ? 'v' . (int) $f['draft_no'] : '<span class="muted">—</span>') . '</td>'
               . '<td data-l="Public link">' . ($f['public_enabled'] ? inc_badge('Open', 'green') : inc_badge('Off')) . '</td>'
               . '<td data-l="Applications" class="num">' . (int) $f['app_count'] . '</td>'
               . '<td><a class="btn btn-sm" href="' . e(inc_url('form', ['id' => $f['id']])) . '">Open builder</a></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    // Which purposes ask for an event or program name, and what it is called (assets/inc.js).
    $asks = array_filter(array_map(static fn($d) => $d['ask'], inc_purpose_defs()), static fn($a) => $a !== '');
    echo '</section><section class="card"><h2>Create a form</h2>' . inc_form_open() . '<input type="hidden" name="action" value="form_create">'
       . '<div class="inc-row">' . inc_field('Form name', '<input type="text" name="name" maxlength="160" required>', '', true)
       . inc_field('Purpose', inc_select('purpose', inc_purposes(true), (string) ($_POST['purpose'] ?? ''), 'Choose what the form is for', 'required data-event-purpose="' . e((string) json_encode($asks ?: new stdClass(), JSON_UNESCAPED_UNICODE)) . '"'),
                   'Decides which sections and fields the form starts with. Add your own under <a href="#purposes">Form purposes</a>.', true) . '</div>'
       . '<div data-event-name' . (inc_purpose_event_label((string) ($_POST['purpose'] ?? '')) === '' ? ' hidden' : '') . '>'
       . inc_field(inc_purpose_event_label((string) ($_POST['purpose'] ?? '')) ?: 'Event or program name',
                   '<input type="text" name="event_name" maxlength="190" value="' . e((string) ($_POST['event_name'] ?? '')) . '" placeholder="e.g. Startup Summit 2026">',
                   'The event or program this form takes applications for. Every application carries it, so you can filter and report by it.', true) . '</div>'
       . inc_field('Assign to team', inc_select('dept_team_id', inc_team_picker(), (string) ($_POST['dept_team_id'] ?? ''), 'None'),
                   'Applications always land in the main pipeline. With a team chosen, each one is also put on that team’s Department desk as it arrives and tagged with the team’s name on the board — no manual assigning. The teams listed are those under Settings → Departments.')
       . inc_field('Description shown to applicants', inc_textarea('description', '', 2))
       . '<button class="btn btn-primary">Create form</button></form></section>';

    /* ---- Form purposes: the choices "Purpose" offers ---- */
    $used = [];
    $tplIds = inc_purpose_template_ids();
    foreach (inc_all('SELECT purpose, COUNT(*) n FROM inc_forms' . ($tplIds ? ' WHERE id NOT IN (' . implode(',', $tplIds) . ')' : '') . ' GROUP BY purpose') as $r) {
        $used[(string) $r['purpose']] = (int) $r['n'];
    }
    $bases = array_map(static fn($b) => $b[0], inc_purpose_builtins());
    $canCfg = inc_can('configure');
    echo '<section class="card" id="purposes"><h2>Form purposes</h2>'
       . '<p class="muted small">The choices offered under <strong>Purpose</strong> when you create a form, and what each is called on forms, applications, filters and reports. '
       . 'Add your own; rename any; hide one you do not use (forms already made with it keep it). '
       . '"Asks for" names the event or program a form of this purpose collects for, e.g. <em>Event name</em> — leave it blank for none.</p>'
       . '<div class="scrollx"><table class="data stack pur-table"><thead><tr><th>Purpose</th><th>Asks for</th><th>Starting questions</th><th>Offered</th><th class="num">Forms</th><th></th></tr></thead><tbody>';
    foreach (inc_purpose_defs() as $code => $d) {
        $fid = 'pur_' . $code;
        echo '<tr><td data-l="Purpose">' . inc_form_open('id="' . e($fid) . '"') . '<input type="hidden" name="action" value="purpose_save"><input type="hidden" name="code" value="' . e($code) . '"></form>'
           . '<input type="text" name="label" form="' . e($fid) . '" value="' . e($d['label']) . '" maxlength="80" required aria-label="Purpose name"' . ($canCfg ? '' : ' disabled') . '>'
           . ($d['builtin'] ? ' <span class="muted small">built-in</span>' : '') . '</td>'
           . '<td data-l="Asks for"><input type="text" name="ask" form="' . e($fid) . '" value="' . e($d['ask']) . '" maxlength="60" placeholder="Nothing" aria-label="Name it asks for"' . ($canCfg ? '' : ' disabled') . '></td>'
           . '<td data-l="Starting questions"><span class="muted small">Its own</span> '
           . ($canCfg ? inc_button('Edit questions', ['action' => 'purpose_questions', 'code' => $code], 'btn btn-sm') : '') . '</td>'
           . '<td data-l="Offered"><label class="check"><input type="checkbox" name="hidden" value="1" form="' . e($fid) . '" class="pur-hide"' . ($d['hidden'] ? ' checked' : '') . ($canCfg ? '' : ' disabled') . '> Hidden</label></td>'
           . '<td data-l="Forms" class="num">' . (int) ($used[$code] ?? 0) . '</td><td class="nowrap">'
           . ($canCfg ? '<button class="btn btn-sm btn-primary" form="' . e($fid) . '">Save</button> '
                      . (!$d['builtin'] && empty($used[$code]) ? inc_button('Remove', ['action' => 'purpose_delete', 'code' => $code], 'btn btn-sm btn-reject', 'Remove the purpose "' . $d['label'] . '"?') : '') : '')
           . '</td></tr>';
    }
    echo '</tbody></table></div>';
    if ($canCfg) {
        echo '<h3 style="margin:16px 0 8px">Add a purpose</h3>' . inc_form_open('class="inc-row"') . '<input type="hidden" name="action" value="purpose_save">'
           . inc_field('Purpose name', '<input type="text" name="label" maxlength="80" required placeholder="e.g. Hackathon registration">', '', true, 'grow')
           . inc_field('Asks for (optional)', '<input type="text" name="ask" maxlength="60" placeholder="e.g. Hackathon name">')
           . inc_field('Start its questions from', inc_select('base', $bases, 'other'), 'A copy you then edit: it becomes the purpose\'s own.')
           . '<div class="field"><label>&nbsp;</label><button class="btn btn-primary">Add purpose</button></div></form>';
    }
    echo '</section>';
}

if ($tab === 'devstages') {
    echo '<div class="inc-note">The five development stages. Their codes are fixed because evaluation questions map to them; names and descriptions are yours. A startup\'s stage is changed on its application, with a reason, and the history is kept.</div><section class="card">';
    foreach (inc_all('SELECT * FROM inc_dev_stages ORDER BY sort') as $d) {
        echo inc_form_open('class="inc-devstage"') . '<input type="hidden" name="action" value="dev_save"><input type="hidden" name="code" value="' . e($d['code']) . '">'
           . '<div class="inc-row">' . inc_field('Name (' . $d['code'] . ')', '<input type="text" name="name" value="' . e($d['name']) . '" maxlength="80" required>')
           . '<label class="check"><input type="checkbox" name="active" value="1"' . ((int) $d['active'] === 1 ? ' checked' : '') . '> Active</label></div>'
           . inc_field('What is expected at this stage', inc_textarea('description', (string) $d['description'], 2, 'maxlength="500"')) . '<button class="btn btn-sm">Save</button></form>';
    }
    echo '</section>';
}

if ($tab === 'evaluation') {
    $tpls = inc_templates();
    echo '<div class="inc-note">One version is published at a time; new evaluations use it. Publishing a new version never recalculates evaluations already approved — they keep their own version and saved scores.</div>';
    echo '<section class="card"><h2>Evaluation templates</h2><table class="data stack"><thead><tr><th>Version</th><th>Name</th><th>Status</th><th class="num">Pillars</th><th class="num">Evaluations</th><th>Published</th><th></th></tr></thead><tbody>';
    foreach ($tpls as $t) {
        echo '<tr><td data-l="Version">v' . (int) $t['version_no'] . '</td><td data-l="Name">' . e($t['name']) . '<div class="muted small">' . e((string) $t['note']) . '</div></td>'
           . '<td data-l="Status">' . inc_badge(ucfirst($t['status']), ['published' => 'green', 'draft' => 'amber'][$t['status']] ?? '') . '</td>'
           . '<td data-l="Pillars" class="num">' . (int) $t['pillars'] . '</td><td data-l="Evaluations" class="num">' . (int) $t['evals'] . '</td>'
           . '<td data-l="Published">' . e($t['published_at'] ? inc_fmt_date($t['published_at']) : '—') . '</td>'
           . '<td><a class="btn btn-sm" href="' . e(inc_url('template', ['id' => $t['id']])) . '">' . ($t['status'] === 'draft' ? 'Edit' : 'View') . '</a> '
           . (!inc_template_draft() ? inc_button('New version from this', ['action' => 'tpl_new', 'from' => $t['id']], 'btn btn-sm') : '') . '</td></tr>';
    }
    echo '</tbody></table></section>';
}

if ($tab === 'lists') {
    $kinds = ['applicant_type' => 'Applicant types', 'spoke' => 'Spokes', 'sector' => 'Sectors', 'source' => 'Sources'];
    echo '<div class="inc-grid3">';
    foreach ($kinds as $k => $lab) {
        echo '<section class="card"><h2>' . e($lab) . '</h2>';
        foreach (inc_all('SELECT * FROM inc_masters WHERE kind = ? ORDER BY sort, name', 's', [$k]) as $m) {
            echo inc_form_open('class="inc-row"') . '<input type="hidden" name="action" value="master_save"><input type="hidden" name="kind" value="' . e($k) . '"><input type="hidden" name="id" value="' . (int) $m['id'] . '">'
               . '<input type="text" name="name" value="' . e($m['name']) . '" maxlength="120" required class="grow"> <label class="check"><input type="checkbox" name="active" value="1"' . ((int) $m['active'] === 1 ? ' checked' : '') . '> Active</label>'
               . ' <button class="btn btn-sm">Save</button></form>';
        }
        echo inc_form_open('class="inc-row"') . '<input type="hidden" name="action" value="master_save"><input type="hidden" name="kind" value="' . e($k) . '"><input type="hidden" name="active" value="1">'
           . '<input type="text" name="name" placeholder="Add…" maxlength="120" required class="grow"> <button class="btn btn-sm btn-primary">Add</button></form></section>';
    }
    echo '</div>';
}

if ($tab === 'teams') {
    $teamId = inc_int_or_null($_GET['team'] ?? null);
    $teams = inc_all('SELECT t.*, (SELECT COUNT(DISTINCT person_id) FROM inc_team_members m WHERE m.team_id = t.id) AS members,
                             (SELECT COUNT(*) FROM inc_dept_assignments d WHERE d.team_id = t.id AND d.state = "open") AS open_apps
                        FROM inc_teams t ORDER BY t.name');
    echo '<div class="inc-note"><strong>Departments</strong> are made from your existing team — the people on '
       . (inc_org() > 0 ? 'your My Team page' : 'Team Management') . '. Nobody is created here: give the department a name and tick its people. '
       . 'Each department has its own stages. From an application, <strong>Departments</strong> tab, assign it to one or more departments: it shows on their '
       . '<a href="' . e(inc_url('desk')) . '">department desk</a>, and they return it to you when done, with their remarks. '
       . 'Choose the <strong>Type</strong> <em>Mentor</em> or <em>Jury</em> to make mentors or a jury the same way: they get a desk of their own (Mentor desk, Jury desk) '
       . 'with only the applications you assign them, their own stages and Gem stages. Only you add their people and assign them work.</div>';
    echo '<div class="inc-grid2"><section class="card"><h2>Departments</h2>';
    if (!$teams) {
        echo '<p class="muted small">No departments yet. Create the first on the right.</p>';
    }
    $kindsAll = inc_dept_kinds();
    usort($teams, static fn($a, $b) => [array_search(inc_dept_kind_of($a), array_keys($kindsAll), true), $a['name']] <=> [array_search(inc_dept_kind_of($b), array_keys($kindsAll), true), $b['name']]);
    $lastKind = null;
    foreach ($teams as $t) {
        $names = array_values(inc_dept_people((int) $t['id']));
        $tk = inc_dept_kind_of($t);
        if ($tk !== $lastKind) {
            echo '<h3 class="inc-kindhead">' . e($kindsAll[$tk][1]) . '</h3>';
            $lastKind = $tk;
        }
        echo '<div class="inc-teamrow"><a href="' . e(inc_url('settings', ['tab' => 'teams', 'team' => $t['id']])) . '"><strong>' . e($t['name']) . '</strong></a>' . ((int) $t['active'] !== 1 ? ' ' . inc_badge('Inactive') : '')
           . '<div class="muted small">' . (int) $t['members'] . ' people' . ($names ? ': ' . e(implode(', ', array_slice($names, 0, 6)) . (count($names) > 6 ? '…' : '')) : '')
           . ' · ' . (int) $t['open_apps'] . ' open application' . ((int) $t['open_apps'] === 1 ? '' : 's')
           . ' · <a href="' . e(inc_url('desk', ['dept' => $t['id'], 'kind' => $tk])) . '">desk</a></div></div>';
    }
    $team = $teamId ? inc_team($teamId) : null;
    $spokes = inc_masters('spoke', false);
    $cur = $team ? inc_team_spokes((int) $team['id']) : [];
    $people = inc_people();
    $mine = $team ? inc_dept_people((int) $team['id']) : [];
    echo '</section><section class="card"><h2>' . ($team ? 'Edit ' . e($team['name']) : 'New department, mentor or jury') . '</h2>' . inc_form_open() . '<input type="hidden" name="action" value="team_save">'
       . ($team ? '<input type="hidden" name="id" value="' . (int) $team['id'] . '">' : '')
       . inc_field('Type', inc_select('kind', array_map(static fn($k) => $k[0], inc_dept_kinds()), $team ? inc_dept_kind_of($team) : (string) ($_GET['kind'] ?? 'department'), '', 'required'),
                   '<strong>Department</strong>: works applications and may add its own. <strong>Mentor</strong> or <strong>Jury</strong>: a desk of their own with only the applications you assign them.', true)
       . inc_field('Name', '<input type="text" name="name" value="' . e($team['name'] ?? '') . '" maxlength="120" required placeholder="e.g. Technical Review, Legal, Mentor panel — AgriTech, Final jury">', '', true)
       . inc_field('Description', '<input type="text" name="description" value="' . e((string) ($team['description'] ?? '')) . '" maxlength="255">')
       . '<fieldset class="inc-fs"><legend>People (from your team) — the department’s members, the mentors or the jurors</legend>';
    if (!$people) {
        echo '<span class="muted small">There are no active people in your team yet — add them on ' . (inc_org() > 0 ? 'My Team' : 'Team Management') . ' first.</span>';
    }
    foreach ($people as $pid => $pn) {
        echo '<label class="check"><input type="checkbox" name="people[]" value="' . (int) $pid . '"' . (isset($mine[$pid]) ? ' checked' : '') . '> ' . e($pn) . '</label>';
    }
    echo '</fieldset><details><summary class="muted small">Spokes (optional)</summary><fieldset class="inc-fs"><legend>Spokes covered (none ticked = any spoke)</legend>';
    foreach ($spokes as $sid => $sn) {
        echo '<label class="check"><input type="checkbox" name="spokes[]" value="' . $sid . '"' . (in_array($sid, $cur, true) ? ' checked' : '') . '> ' . e($sn) . '</label>';
    }
    echo ($spokes ? '' : '<span class="muted small">Add spokes under Lists.</span>') . '</fieldset></details>'
       . '<label class="check"><input type="checkbox" name="active" value="1"' . (!$team || (int) $team['active'] === 1 ? ' checked' : '') . '> Active</label> '
       . '<button class="btn btn-primary">Save</button>' . ($team ? ' <a class="btn" href="' . e(inc_url('settings', ['tab' => 'teams'])) . '">New</a>' : '') . '</form>';
    if ($team) {
        echo '<h3>Its stages</h3><p class="muted small">' . e(implode(' → ', array_column(inc_dept_stages((int) $team['id']), 'name')))
           . ' · <a href="' . e(inc_url('desk', ['dept' => $team['id'], 'kind' => inc_dept_kind_of($team)])) . '">change them, and choose Gem stages, on its desk</a></p>';
    }
    echo '</section></div>';
}

if ($tab === 'notifications') {
    echo '<div class="inc-note">Notifications use the portal\'s shared notification centre and mail delivery. Each business event reaches each person once. Team members receive the details by email and in their portal notifications; links to Incubation Management are included only for the administrator, who is the only one who can open it.</div>';
    echo '<section class="card"><h2>Templates</h2><p class="muted small">Placeholders: {ref} {startup} {applicant} {form} {workspace} (incubation ID) {team} {owner} {stage} {from} {date} {task} {due} {evaluator} {score} {uniqueness} {note} {history}</p>';
    foreach (array_keys(inc_mail_defaults()) as $ev) {
        $t = inc_mail_template($ev);
        echo '<details><summary><strong>' . e(ucfirst(str_replace('_', ' ', $ev))) . '</strong>' . ($t['custom'] ? ' ' . inc_badge('Edited', 'blue') : '')
           . (!$t['email_on'] ? ' ' . inc_badge('Email off') : '') . (!$t['portal_on'] ? ' ' . inc_badge('Portal off') : '') . '</summary>'
           . inc_form_open() . '<input type="hidden" name="action" value="mail_save"><input type="hidden" name="event" value="' . e($ev) . '">'
           . inc_field('Subject', '<input type="text" name="subject" value="' . e($t['subject']) . '" maxlength="255" required>')
           . inc_field('Message', inc_textarea('body', $t['body'], 6, 'required'))
           . '<label class="check"><input type="checkbox" name="email_on" value="1"' . ($t['email_on'] ? ' checked' : '') . '> Send by email</label> '
           . '<label class="check"><input type="checkbox" name="portal_on" value="1"' . ($t['portal_on'] ? ' checked' : '') . '> Post to the portal notification centre</label> '
           . '<button class="btn btn-sm">Save</button></form></details>';
    }
    echo '</section>';
    $log = inc_all('SELECT * FROM inc_notify_log ORDER BY created_at DESC, id DESC LIMIT 100');
    $queued = (int) (inc_one("SELECT COUNT(*) AS n FROM inc_notify_log WHERE channel = 'email' AND status = 'queued'")['n'] ?? 0);
    echo '<section class="card"><h2>Delivery log</h2>';
    if ($queued && inc_can('configure')) {
        echo '<div class="inc-warn"><strong>' . number_format($queued) . ' email' . ($queued === 1 ? ' is' : 's are') . ' waiting to be sent.</strong> '
           . 'Cancel them to make sure they never go to anyone; portal notifications are not affected. '
           . inc_button('Cancel queued emails', ['action' => 'cancel_queued'], 'btn btn-sm btn-reject', 'Cancel all ' . number_format($queued) . ' queued emails? They will never be sent.') . '</div>';
    }
    if (!$log) {
        echo '<p class="muted small">Nothing sent yet.</p>';
    } else {
        echo '<div class="scrollx"><table class="data stack"><thead><tr><th>When</th><th>Event</th><th>Channel</th><th>To</th><th>Subject</th><th>Status</th><th>Detail</th><th></th></tr></thead><tbody>';
        foreach ($log as $l) {
            echo '<tr><td data-l="When">' . e(inc_fmt_datetime($l['last_try_at'])) . '</td><td data-l="Event">' . e($l['event']) . '</td><td data-l="Channel">' . e($l['channel']) . '</td>'
               . '<td data-l="To">' . e($l['recipient_id'] ? inc_person_name((int) $l['recipient_id']) : 'Administrator') . '<div class="muted small">' . e((string) $l['recipient_email']) . '</div></td>'
               . '<td data-l="Subject">' . e($l['subject']) . '</td><td data-l="Status">' . inc_badge($l['status'], ['sent' => 'green', 'failed' => 'red', 'queued' => 'amber'][$l['status']] ?? '')
               . ($l['tries'] > 1 ? ' <span class="muted small">' . (int) $l['tries'] . ' tries</span>' : '') . '</td><td data-l="Detail" class="small">' . e((string) $l['detail']) . '</td><td>'
               . ($l['channel'] === 'email' && in_array($l['status'], ['failed', 'queued'], true) ? inc_button('Retry', ['action' => 'retry', 'log_id' => $l['id']]) : '') . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</section>';
}

if ($tab === 'certificates') {
    $t = inc_cert_template();
    $canCfg = inc_can('configure');
    echo '<div class="inc-note">When an application reaches a stage, its startup can be sent a <strong>certificate</strong> for it: by email, with a link to view and download it (PDF). '
       . 'Each has a unique ID and its link is the proof that it is genuine. Send one from an application, from the selection bar for many at once, or automatically for the stages ticked below.</div>';
    echo '<div class="inc-grid2"><div>';

    // Logos.
    echo '<section class="card"><h2>Logos <span class="muted small">(up to 6, shown across the top in this order)</span></h2>';
    if (!$t['logos']) {
        echo '<p class="muted small">No logos yet.</p>';
    }
    foreach ($t['logos'] as $i => $l) {
        $src = inc_cert_img($l);
        echo '<div class="inc-row" style="align-items:center;border-bottom:1px solid var(--line-soft);padding:8px 0">'
           . '<div style="width:120px;height:52px;display:grid;place-items:center;background:#f6f7f9;border-radius:8px">' . ($src ? '<img src="' . $src . '" alt="" style="max-width:110px;max-height:46px">' : '—') . '</div>'
           . '<span class="small" style="flex:1">' . e((string) ($l['n'] ?? 'Logo')) . '</span>';
        if ($canCfg) {
            echo inc_button('←', ['action' => 'cert_logo', 'i' => $i, 'do' => 'left']) . inc_button('→', ['action' => 'cert_logo', 'i' => $i, 'do' => 'right'])
               . inc_form_open('style="display:inline-flex;gap:6px;align-items:center"', true) . '<input type="hidden" name="action" value="cert_logo"><input type="hidden" name="i" value="' . $i . '"><input type="hidden" name="do" value="replace">'
               . '<input type="file" name="logo" accept="image/png,image/jpeg,image/webp" required style="max-width:190px"><button class="btn btn-sm">Replace</button></form>'
               . inc_button('Remove', ['action' => 'cert_logo', 'i' => $i, 'do' => 'remove'], 'btn btn-sm btn-reject', 'Remove this logo from the certificate?');
        }
        echo '</div>';
    }
    if ($canCfg && count($t['logos']) < 6) {
        echo inc_form_open('class="inc-row" style="margin-top:10px;align-items:center"', true) . '<input type="hidden" name="action" value="cert_logo_add">'
           . '<input type="file" name="logo" accept="image/png,image/jpeg,image/webp" required><button class="btn btn-primary btn-sm">+ Add logo</button>'
           . '<span class="muted small">PNG with a transparent background looks best. Up to 2 MB.</span></form>';
    }
    echo '</section>';

    // Wording and colour.
    echo '<section class="card"><h2>Wording</h2>' . inc_form_open() . '<input type="hidden" name="action" value="cert_save">'
       . inc_field('Title', '<input type="text" name="title" maxlength="120" required value="' . e($t['title']) . '">')
       . inc_field('Line above the name', '<input type="text" name="intro" maxlength="200" value="' . e($t['intro']) . '">')
       . inc_field('Main text', inc_textarea('body', $t['body'], 3, 'maxlength="1000" required'),
                   'Fill-ins: {startup}, {name} (the applicant), {stage}, {program}, {org}, {date}, {ref}. A stage can have its own text below.')
       . inc_field('Closing line', inc_textarea('note', $t['note'], 2, 'maxlength="600"'))
       . '<div class="inc-row">' . inc_field('Colour', '<input type="color" name="accent" value="' . e($t['accent']) . '" style="width:72px;height:38px;padding:2px">')
       . '<label class="check" style="align-self:center"><input type="checkbox" name="show_person" value="1"' . ($t['show_person'] === '1' ? ' checked' : '') . '> Show the applicant\'s name under the startup</label></div>'
       . ($canCfg ? '<button class="btn btn-primary">Save wording</button>' : '') . '</form></section>';

    // Signatories.
    echo '<section class="card"><h2>Signatures <span class="muted small">(up to 3)</span></h2><p class="muted small">Each signatory appears at the bottom right with their signature image above the line. '
       . 'Upload a scan or photo of the signature, ideally a PNG with a transparent background.</p>';
    for ($i = 0; $i < 3; $i++) {
        $sg = $t['signatories'][$i] ?? null;
        $img = $sg ? inc_cert_img($sg['sig'] ?? null) : '';
        echo '<details class="inc-fieldrow"' . (!$sg && $i === 0 ? ' open' : '') . '><summary><strong>Signatory ' . ($i + 1) . '</strong> '
           . ($sg ? e($sg['name']) . ($sg['role'] ?? '' ? ' · <span class="muted">' . e($sg['role']) . '</span>' : '') . (!$img ? ' ' . inc_badge('no signature image', 'amber') : '') : '<span class="muted">empty</span>') . '</summary>'
           . inc_form_open('', true) . '<input type="hidden" name="action" value="cert_sig"><input type="hidden" name="i" value="' . $i . '">'
           . '<div class="inc-row">' . inc_field('Name', '<input type="text" name="sig_name" maxlength="120" value="' . e((string) ($sg['name'] ?? '')) . '">')
           . inc_field('Designation', '<input type="text" name="sig_role" maxlength="160" value="' . e((string) ($sg['role'] ?? '')) . '" placeholder="e.g. Chief Executive Officer">') . '</div>'
           . '<div class="inc-row" style="align-items:center">' . ($img ? '<img src="' . $img . '" alt="Signature" style="max-height:48px;max-width:160px;background:#fff;border:1px solid var(--line-soft);border-radius:6px;padding:4px">' : '')
           . inc_field($img ? 'Replace signature image' : 'Signature image', '<input type="file" name="sig" accept="image/png,image/jpeg,image/webp">') . '</div>'
           . ($img ? '<label class="check"><input type="checkbox" name="clear_sig" value="1"> Remove the signature image</label><br>' : '')
           . ($canCfg ? '<div class="inc-row"><button class="btn btn-primary btn-sm">Save signatory</button>' . ($sg ? '<button class="btn btn-sm btn-reject" name="remove" value="1" data-confirm="Remove this signatory?">Remove signatory</button>' : '') . '</div>' : '')
           . '</form></details>';
    }
    echo '</section>';

    // Stamp.
    $stampSrc = inc_cert_img($t['stamp'] ?? null);
    echo '<section class="card"><h2>Stamp</h2><p class="muted small">Printed at the bottom centre. Upload your organisation\'s seal (a PNG with a transparent background looks best), '
       . 'or leave it empty to use the <strong>digital stamp</strong>: your organisation\'s name, "Digitally verified", the date and the certificate ID, in the certificate\'s colour.</p>'
       . '<div class="inc-row" style="align-items:center;gap:16px"><div style="width:120px;height:120px;display:grid;place-items:center;background:#fff;border:1px solid var(--line-soft);border-radius:10px">'
       . ($stampSrc !== '' ? '<img src="' . $stampSrc . '" alt="Stamp" style="max-width:110px;max-height:110px">' : inc_cert_stamp_svg(inc_cert_org_name(), date('d F Y'), '', $t['accent']))
       . '</div><div><strong>' . ($stampSrc !== '' ? 'Your uploaded seal' : 'Digital stamp (drawn)') . '</strong>';
    if ($canCfg) {
        echo inc_form_open('class="inc-row" style="margin-top:8px;align-items:center"', true) . '<input type="hidden" name="action" value="cert_stamp"><input type="hidden" name="do" value="upload">'
           . '<input type="file" name="stamp" accept="image/png,image/jpeg,image/webp" required><button class="btn btn-primary btn-sm">' . ($stampSrc !== '' ? 'Replace seal' : 'Upload seal') . '</button></form>'
           . ($stampSrc !== '' ? inc_button('Use the digital stamp instead', ['action' => 'cert_stamp', 'do' => 'remove'], 'btn btn-sm', 'Remove the uploaded seal and use the drawn digital stamp?') : '');
    }
    echo '</div></div></section>';

    // Stages.
    $auto = inc_cert_stages();
    echo '<section class="card"><h2>Send automatically</h2><p class="muted small">Tick the stages whose certificate goes out by itself when an application reaches them. '
       . 'Leave the text empty to use the main text above.</p>' . inc_form_open() . '<input type="hidden" name="action" value="cert_stages">'
       . '<table class="data"><thead><tr><th>Send</th><th>Stage</th><th>Its own text (optional)</th></tr></thead><tbody>';
    foreach (inc_stages('crm') as $st) {
        $sid = (int) $st['id'];
        echo '<tr><td><input type="checkbox" name="auto[' . $sid . ']" value="1"' . (isset($auto[$sid]) ? ' checked' : '') . ' aria-label="Send automatically at ' . e($st['name']) . '"></td>'
           . '<td><strong>' . e($st['name']) . '</strong>' . ((int) $st['active'] !== 1 ? ' ' . inc_badge('inactive') : '') . '</td>'
           . '<td><input type="text" name="line[' . $sid . ']" maxlength="500" value="' . e((string) ($auto[$sid] ?? '')) . '" placeholder="e.g. for being shortlisted for {program}." style="width:100%"></td></tr>';
    }
    echo '</tbody></table>' . ($canCfg ? '<button class="btn btn-primary" style="margin-top:10px">Save</button>' : '') . '</form></section>';
    echo '</div><div>';

    // Preview.
    echo '<section class="card" style="position:sticky;top:12px"><h2>Preview</h2><p class="muted small">With sample details. Save a change to see it here.</p>'
       . '<iframe src="' . e(inc_url('settings', ['tab' => 'certificates', 'cert_preview' => 1])) . '" title="Certificate preview" style="width:100%;aspect-ratio:297/210;border:1px solid var(--line);border-radius:8px;background:#fff"></iframe></section>';
    echo '</div></div>';

    // Issued.
    $issued = inc_all('SELECT c.*, a.ref FROM inc_certificates c JOIN inc_applications a ON a.id = c.application_id ORDER BY c.id DESC LIMIT 200');
    echo '<section class="card"><h2>Certificates sent <span class="muted small">(latest 200)</span></h2>';
    if (!$issued) {
        echo '<p class="muted small">None yet.</p>';
    } else {
        echo '<div class="scrollx"><table class="data stack"><thead><tr><th>Startup</th><th>Stage</th><th>Issued</th><th>Email</th><th>ID</th><th></th></tr></thead><tbody>';
        foreach ($issued as $cr) {
            echo '<tr><td data-l="Startup"><a href="' . e(inc_url('application', ['id' => $cr['application_id']])) . '"><strong>' . e($cr['startup']) . '</strong></a><div class="muted small">' . e($cr['ref']) . '</div></td>'
               . '<td data-l="Stage">' . e($cr['stage_name']) . '</td><td data-l="Issued" class="nowrap">' . e(inc_fmt_datetime($cr['issued_at'])) . '</td>'
               . '<td data-l="Email">' . ($cr['revoked_at'] ? inc_badge('Revoked', 'red') : inc_badge(ucfirst((string) ($cr['mail_status'] ?: 'not sent')), ['sent' => 'green', 'queued' => 'amber', 'failed' => 'red'][$cr['mail_status']] ?? '')) . '</td>'
               . '<td data-l="ID" class="small">' . e($cr['code']) . '</td><td class="nowrap"><a class="btn btn-sm" target="_blank" rel="noopener" href="' . e(inc_cert_link($cr)) . '">View</a> '
               . (!$cr['revoked_at'] && inc_can('edit') ? inc_button('Email again', ['action' => 'cert_resend', 'id' => $cr['id']]) : '')
               . (!$cr['revoked_at'] && $canCfg ? inc_button('Revoke', ['action' => 'cert_revoke', 'id' => $cr['id']], 'btn btn-sm btn-reject', 'Revoke this certificate? Its link will say it is no longer valid.') : '')
               . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</section>';
}

if ($tab === 'general') {
    $labels = inc_rating_labels();
    echo '<section class="card"><h2>General</h2>' . inc_form_open() . '<input type="hidden" name="action" value="general">'
       . '<div class="inc-row">' . inc_field('Application reference prefix', '<input type="text" name="ref_prefix" value="' . e(inc_setting('ref_prefix')) . '" maxlength="8" required>', 'References look like PREFIX-2026-00042.')
       . inc_field('Duplicate look-back (days)', '<input type="number" name="dup_window_days" min="1" max="3650" value="' . e(inc_setting('dup_window_days')) . '" required>', 'Applications sharing an email, phone or startup name within this window are flagged for review.') . '</div>'
       . '<label class="check"><input type="checkbox" name="move_crm_on_assign" value="1"' . (inc_setting('move_crm_on_assign') === '1' ? ' checked' : '') . '> On first assignment, move the application to the "Assigned Application" pipeline stage (when the transition rules allow it)</label><br>'
       . '<label class="check"><input type="checkbox" name="notify_prev_owner" value="1"' . (inc_setting('notify_prev_owner') === '1' ? ' checked' : '') . '> Tell the previous owner when a startup is reassigned</label><br>'
       . '<label class="check"><input type="checkbox" name="public_index" value="1"' . (inc_setting('public_index') === '1' ? ' checked' : '') . '> List open public forms on the public application page (incubation-apply.php)</label>'
       . '<h3>Rating definitions</h3><p class="muted small">Shown beside every rating control.</p>';
    foreach (['1', '2', '3', '4', '5', 'na'] as $k) {
        echo inc_field($k === 'na' ? 'N/A' : $k, '<input type="text" name="rl_' . $k . '" value="' . e((string) ($labels[$k] ?? '')) . '" maxlength="160" required>');
    }
    echo '<p class="muted small">Pending and Insufficient Evidence are separate statuses and are never counted as a rating.</p><button class="btn btn-primary">Save settings</button></form></section>';
}

if ($tab === 'audit') {
    $rows = inc_all('SELECT * FROM inc_audit ORDER BY created_at DESC, id DESC LIMIT 300');
    echo '<section class="card"><h2>Audit trail</h2><p class="muted small">The latest 300 changes made in Incubation Management.</p>';
    if (!$rows) {
        echo '<p class="muted small">Nothing recorded yet.</p>';
    } else {
        echo '<div class="scrollx"><table class="data stack"><thead><tr><th>When</th><th>Who</th><th>Action</th><th>Record</th><th>Summary</th><th>Reason</th><th>Before → after</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr><td data-l="When" class="nowrap">' . e(inc_fmt_datetime($r['created_at'])) . '</td><td data-l="Who">' . e($r['actor']) . '</td><td data-l="Action">' . e($r['action']) . '</td>'
               . '<td data-l="Record">' . e($r['entity']) . ($r['entity_id'] ? ' #' . (int) $r['entity_id'] : '') . '</td><td data-l="Summary">' . e((string) $r['summary']) . '</td>'
               . '<td data-l="Reason">' . e((string) $r['reason']) . '</td><td data-l="Change" class="small">'
               . ($r['before_json'] || $r['after_json'] ? '<details><summary>Show</summary><pre class="inc-pre">' . e((string) $r['before_json']) . "\n→\n" . e((string) $r['after_json']) . '</pre></details>' : '') . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</section>';
}
inc_layout_end();

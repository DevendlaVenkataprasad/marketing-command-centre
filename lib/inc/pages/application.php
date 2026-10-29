<?php
/**
 * Incubation Management — one application: its answers, its pipeline stage, the
 * startup it belongs to and that startup's development stage, evaluations,
 * recommendations, assignment, and its activity.
 */

require_once __DIR__ . '/parts.php';
require_once __DIR__ . '/../appreport.php';

$id = (int) ($_GET['id'] ?? 0);
try {
    $app = inc_app_need($id);
} catch (InvalidArgumentException $e) {
    fabo_deny($e->getMessage(), 404);
}
$tabs = ['overview' => 'Overview', 'evaluation' => 'Evaluation', 'depts' => 'Departments', 'assign' => 'Assignment', 'activity' => 'Activity & notes'];
$tab = isset($tabs[$_GET['tab'] ?? '']) ? (string) $_GET['tab'] : 'overview';
$self = inc_url('application', ['id' => $id, 'tab' => $tab]);
$flash = null;

// This application's report: a full PDF, or Excel (its row, answers, Gem scores, evaluations).
if (in_array($_GET['download'] ?? '', ['xlsx', 'pdf'], true) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        inc_report_download((string) $_GET['download'], [$id], (string) $app['ref']);
    } catch (InvalidArgumentException $e) {
        $flash = ['err', $e->getMessage()];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    $flash = inc_action(static function () use ($a, $id, $app) {
        switch ($a) {
            case 'stage':
                inc_app_move_stage($id, (int) ($_POST['stage_id'] ?? 0), trim((string) ($_POST['reason'] ?? '')));
                return 'Pipeline stage changed.';
            case 'cert_send':
                inc_need('edit');
                require_once __DIR__ . '/../certs.php';
                [$cert, $st, $why] = inc_cert_issue($id);
                return $st === 'sent' ? 'Certificate for "' . $cert['stage_name'] . '" emailed to ' . $app['applicant_email'] . '.'
                     : ($st === 'queued' ? 'Certificate for "' . $cert['stage_name'] . '" queued to ' . $app['applicant_email'] . ' — it goes out shortly.'
                     : 'Certificate issued, but not emailed: ' . $why . '. Open it from the list below and share the link.');
            case 'gem_send':
                $r = inc_gem_send((int) ($_POST['gem_id'] ?? 0), [$id]);
                if ($r['failed'] || ($r['skipped'] && !$r['sent'] && !$r['queued'])) {
                    throw new InvalidArgumentException('Not sent: ' . implode('; ', array_merge($r['failed'], $r['skipped'])));
                }
                return 'Form ' . ($r['queued'] ? 'queued for email' : 'emailed') . ($r['skipped'] ? ' (' . implode('; ', $r['skipped']) . ')' : '') . '.';
            case 'gem_score':
                inc_need('edit');
                $req = inc_gem_requests_for_app($id)[(int) ($_POST['gem_id'] ?? 0)] ?? null;
                [$ok, $msg] = $req ? inc_gem_score((int) $req['id']) : [false, 'Nothing to score yet.'];
                if (!$ok) {
                    throw new InvalidArgumentException($msg);
                }
                return $msg;
            case 'link_startup':
                $sid = (string) ($_POST['startup_id'] ?? '');
                if ($sid === '') {
                    throw new InvalidArgumentException('Choose a startup, or create a new one.');
                }
                inc_app_link_startup($id, $sid === 'new' ? 0 : (int) $sid, trim((string) ($_POST['reason'] ?? '')));
                return 'Startup linked.';
            case 'dev_stage':
                if (!$app['startup_id']) {
                    throw new InvalidArgumentException('Link a startup first.');
                }
                inc_startup_set_stage((int) $app['startup_id'], (string) ($_POST['dev_stage'] ?? ''), trim((string) ($_POST['reason'] ?? '')));
                return 'Development stage changed.';
            case 'dup':
                inc_app_dup_resolve($id, (string) ($_POST['decision'] ?? ''), (string) ($_POST['reason'] ?? ''));
                return 'Duplicate review recorded.';
            case 'edit':
                $n = inc_app_update($id, $_POST, trim((string) ($_POST['reason'] ?? '')));
                return $n ? $n . ' change' . ($n === 1 ? '' : 's') . ' saved.' : 'Nothing changed.';
            case 'owner':
                inc_app_set_owner($id, inc_int_or_null($_POST['team_id'] ?? null), inc_int_or_null($_POST['owner_id'] ?? null));
                return 'Screening owner saved.';
            case 'assign':
                if (empty($_POST['confirm'])) {
                    throw new InvalidArgumentException('Tick the confirmation to assign.');
                }
                $r = inc_assign($id, (int) ($_POST['team_id'] ?? 0), (int) ($_POST['owner_id'] ?? 0), (string) ($_POST['reason'] ?? ''), (string) ($_POST['request_key'] ?? ''));
                $n = $r['notified']['owner']['email'] ?? '';
                return ['created' => 'Assigned. Incubation record created at "Assigned Application".', 'reassigned' => 'Reassigned. The incubation record, its stage and its history are kept.',
                        'unchanged' => 'Already assigned to that team and owner — nothing changed.', 'repeat' => 'That assignment was already recorded — nothing changed.'][$r['result']]
                     . ($n === 'failed' || $n === 'queued' ? ' The email could not be sent yet; retry it under Settings → Notifications.' : '');
            case 'dept_assign':
                $n = inc_dept_assign($id, (array) ($_POST['dept'] ?? []), (string) ($_POST['note'] ?? ''));
                return $n ? 'Assigned to ' . $n . ' department' . ($n === 1 ? '' : 's') . '. They have been told. Its pipeline stage is unchanged.'
                          : 'Those departments already have it.';
            case 'note':
                inc_note_add('application', $id, (string) ($_POST['body'] ?? ''), (string) ($_POST['kind'] ?? 'note'));
                return 'Note added.';
            case 'task':
                inc_task_add('application', $id, $_POST);
                return 'Task added.';
            case 'task_set':
                inc_task_set((int) ($_POST['task_id'] ?? 0), (string) ($_POST['status'] ?? ''));
                return 'Task updated.';
            case 'file':
                if (empty($_FILES['upload']) || ($_FILES['upload']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    throw new InvalidArgumentException('Choose a file.');
                }
                inc_file_add('application', $id, $_FILES['upload'], (string) ($_POST['label'] ?? ''));
                return 'Document added.';
            case 'eval_create':
                $eid = inc_eval_create($id, inc_int_or_null($_POST['evaluator_id'] ?? null));
                inc_redirect(inc_url('evaluate', ['id' => $eid]));
            case 'delete':
                $ref = inc_app_delete($id, trim((string) ($_POST['reason'] ?? '')));
                inc_redirect(inc_url('applications'), ['ok', 'Application ' . $ref . ' deleted.']);
            case 'rec':
                inc_rec_save(inc_int_or_null($_POST['rec_id'] ?? null), $id, $_POST);
                return 'Recommendation saved.';
        }
        throw new InvalidArgumentException('Unknown action.');
    }, $self);
    $app = inc_app($id);
}

$name = $app['startup_name'] !== '' ? $app['startup_name'] : $app['applicant_name'];
$startup = $app['startup_id'] ? inc_startup((int) $app['startup_id']) : null;
$acts = (inc_can('export') ? '<a class="btn btn-primary" href="' . e(inc_url('application', ['id' => $id, 'download' => 'pdf'])) . '">' . inc_dl_icon() . ' Download report (PDF)</a> '
         . '<a class="btn" href="' . e(inc_url('application', ['id' => $id, 'download' => 'xlsx'])) . '">Excel</a> ' : '')
      . (inc_can('edit') ? inc_button('Send certificate', ['action' => 'cert_send'], 'btn',
                                      'Send ' . ($app['applicant_email'] ?: 'the applicant') . ' a certificate for reaching "' . (inc_stage((int) $app['stage_id'])['name'] ?? 'this stage') . '"?') . ' ' : '')
      . '<a class="btn" href="' . e(inc_url('applications')) . '">All applications</a>'
      . (inc_can('configure') && !$app['workspace_id'] ? ' ' . inc_button('Delete', ['action' => 'delete'], 'btn btn-danger',
                                      'Delete ' . $app['ref'] . ' permanently, with its answers, Gem scores, evaluations, notes and documents? This cannot be undone.') : '')
      . ($app['workspace_id'] ? ' <a class="btn btn-primary" href="' . e(inc_url('workspace', ['id' => $app['workspace_id']])) . '">Open incubation record ' . e($app['ws_ref']) . '</a>' : '');
inc_layout_start($name, e($app['ref']) . (!empty($app['applicant_type_id']) ? ' · ' . e(inc_master_name((int) $app['applicant_type_id'])) : '') . ' · ' . e($app['form_name']) . ' · ' . e(inc_purpose_text($app['purpose'], $app['event_name'] ?? null)) . ' · submitted ' . e(inc_fmt_datetime($app['submitted_at'])),
                 ['Applications' => inc_url('applications'), $app['ref'] => null], $acts, $flash);

/* Certificates sent for this application's stages. */
require_once __DIR__ . '/../certs.php';
$certs = inc_all('SELECT * FROM inc_certificates WHERE application_id = ? ORDER BY id', 'i', [$id]);
if ($certs) {
    echo '<div class="inc-note" style="margin-bottom:10px"><strong>Certificates:</strong> ';
    foreach ($certs as $i => $cr) {
        echo ($i ? ' · ' : '') . '<a target="_blank" rel="noopener" href="' . e(inc_cert_link($cr)) . '">' . e($cr['stage_name']) . '</a> <span class="muted small">('
           . ($cr['revoked_at'] ? 'revoked' : e(inc_fmt_date($cr['issued_at'])) . ($cr['mail_status'] ? ', ' . e($cr['mail_status']) : '')) . ')</span>';
    }
    echo '</div>';
}

/* Where it is in the pipeline: every active stage, the current one marked. */
$cur = inc_stage((int) $app['stage_id']);
echo '<ol class="inc-steps" aria-label="Pipeline">';
foreach (inc_stages('crm', true) as $st) {
    $cls = (int) $st['id'] === (int) $app['stage_id'] ? 'now' : ((int) $st['sort'] < (int) ($cur['sort'] ?? 0) ? 'done' : '');
    echo '<li class="' . $cls . '"' . ($cls === 'now' ? ' aria-current="step"' : '') . '><span>' . e($st['name']) . '</span></li>';
}
echo '</ol>';

/* The four independent states, side by side. */
echo '<div class="inc-states">'
   . '<div><span>Pipeline stage</span><strong>' . e($app['stage_name']) . '</strong></div>'
   . '<div><span>Development stage</span><strong>' . e($app['dev_stage'] ? (inc_dev_stages()[$app['dev_stage']] ?? '') : 'Not set') . '</strong></div>'
   . '<div><span>Evaluation</span><strong>' . inc_eval_badge($app['eval_status']) . '</strong></div>'
   . '<div><span>Official score</span><strong>' . inc_score_html($app) . '</strong></div>'
   . '<div><span>Uniqueness</span><strong>' . inc_uniq_html($app['uniqueness_rating']) . '</strong></div>'
   . '<div><span>Incubation</span><strong>' . ($app['workspace_id'] ? e($app['ws_stage_name']) : 'Not assigned') . '</strong></div>'
   . '</div>';

if ($app['dup_status'] === 'suspected') {
    $orig = $app['dup_of_id'] ? inc_one('SELECT id, ref, startup_name, applicant_name, submitted_at FROM inc_applications WHERE id = ?', 'i', [(int) $app['dup_of_id']]) : null;
    echo '<div class="inc-warn"><strong>Possible duplicate.</strong> ' . ($orig ? 'It shares an email, phone or startup name with <a href="' . e(inc_url('application', ['id' => $orig['id']])) . '">'
       . e($orig['ref']) . '</a> (' . e($orig['startup_name'] ?: $orig['applicant_name']) . ', ' . e(inc_fmt_date($orig['submitted_at'])) . ').' : '')
       . inc_form_open('class="inc-row"') . '<input type="hidden" name="action" value="dup">'
       . '<input type="text" name="reason" placeholder="Reason (required to confirm)" maxlength="500">'
       . '<button class="btn btn-sm" name="decision" value="cleared">Not a duplicate</button> '
       . '<button class="btn btn-sm btn-reject" name="decision" value="confirmed" data-confirm="Mark this application as a confirmed duplicate?">Confirm duplicate</button></form></div>';
}
if (!$app['startup_id']) {
    $sug = $app['startup_suggest_id'] ? inc_startup((int) $app['startup_suggest_id']) : null;
    echo '<div class="inc-warn"><strong>No startup linked yet.</strong> '
       . ($sug ? 'A startup called "' . e($sug['name']) . '" (#' . (int) $sug['id'] . ') already exists with a matching name or email. Confirm whether this application belongs to it.' : 'Link it to a startup before evaluating or assigning it.')
       . inc_form_open('class="inc-row"') . '<input type="hidden" name="action" value="link_startup">'
       . '<select name="startup_id" required><option value="">Choose…</option>' . ($sug ? '<option value="' . (int) $sug['id'] . '" selected>Existing: ' . e($sug['name']) . '</option>' : '')
       . '<option value="new">Create a new startup from this application</option>';
    foreach (inc_startup_search($app['startup_name'] ?: $app['applicant_name']) as $s) {
        if (!$sug || (int) $s['id'] !== (int) $sug['id']) {
            echo '<option value="' . (int) $s['id'] . '">Existing: ' . e($s['name']) . ($s['email'] ? ' (' . e($s['email']) . ')' : '') . '</option>';
        }
    }
    echo '</select> <input type="text" name="reason" placeholder="Note (optional)"> <button class="btn btn-sm btn-primary">Link</button></form></div>';
}

echo '<nav class="subtabs" aria-label="Sections">';
foreach ($tabs as $k => $lab) {
    echo '<a href="' . e(inc_url('application', ['id' => $id, 'tab' => $k])) . '"' . ($k === $tab ? ' class="on" aria-current="page"' : '') . '>' . e($lab) . '</a>';
}
echo '</nav>';

if ($tab === 'overview') {
    echo inc_gem_app_html($app);
    echo '<div class="inc-grid2 wide-left"><section class="card"><h2>Answers</h2>';
    $ver = inc_form_version((int) $app['form_version_id']);
    echo '<p class="muted small">Form "' . e($app['form_name']) . '", version ' . (int) $ver['version_no'] . '. Labels are as the applicant saw them.</p>'
       . inc_app_answers_html($app) . '</section><div>';

    echo '<section class="card"><h2>Pipeline stage</h2>' . inc_form_open() . '<input type="hidden" name="action" value="stage">'
       . inc_field('Move to', inc_select('stage_id', inc_stage_map('crm', true), $app['stage_id']))
       . inc_field('Reason', '<input type="text" name="reason" maxlength="500">', 'Some stages require a reason.')
       . '<button class="btn btn-primary">Change stage</button></form>'
       . '<p class="muted small">Changing the pipeline stage does not change the development stage, the evaluation or the incubation stage.</p></section>';

    if ($startup) {
        echo '<section class="card"><h2>Startup</h2><p><strong>' . e($startup['name']) . '</strong> <span class="muted small">#' . (int) $startup['id'] . '</span></p>'
           . inc_form_open() . '<input type="hidden" name="action" value="dev_stage">'
           . inc_field('Development stage', inc_select('dev_stage', inc_dev_stages(true), $startup['dev_stage'], 'Choose…'), $startup['dev_stage'] ? e(inc_dev_stage_desc((string) $startup['dev_stage'])) : '')
           . inc_field('Reason for the change', '<input type="text" name="reason" maxlength="500">')
           . '<button class="btn">Change development stage</button></form>';
        $hist = inc_dev_history((int) $startup['id']);
        if ($hist) {
            echo '<details><summary class="small">Development stage history (' . count($hist) . ')</summary><ul class="small">';
            foreach (array_reverse($hist) as $h) {
                echo '<li>' . e(inc_fmt_datetime($h['created_at'])) . ': ' . e($h['from_stage'] ? (inc_dev_stages()[$h['from_stage']] ?? $h['from_stage']) : '—') . ' → '
                   . e(inc_dev_stages()[$h['to_stage']] ?? $h['to_stage']) . ' · ' . e($h['actor']) . ($h['reason'] ? ' · ' . e($h['reason']) : '') . '</li>';
            }
            echo '</ul></details>';
        }
        $others = inc_all('SELECT id, ref, submitted_at FROM inc_applications WHERE startup_id = ? AND id <> ? ORDER BY submitted_at DESC', 'ii', [(int) $startup['id'], $id]);
        if ($others) {
            echo '<p class="small">Other applications from this startup: ';
            foreach ($others as $o) {
                echo '<a href="' . e(inc_url('application', ['id' => $o['id']])) . '">' . e($o['ref']) . '</a> ';
            }
            echo '</p>';
        }
        echo '</section>';
    }

    echo '<section class="card"><details><summary><strong>Correct the application\'s details</strong></summary>' . inc_form_open() . '<input type="hidden" name="action" value="edit">'
       . inc_field('Applicant name', '<input type="text" name="applicant_name" value="' . e($app['applicant_name']) . '" maxlength="190">')
       . inc_field('Applicant email', '<input type="email" name="applicant_email" value="' . e((string) $app['applicant_email']) . '" maxlength="190">')
       . inc_field('Applicant phone', '<input type="tel" name="applicant_phone" value="' . e((string) $app['applicant_phone']) . '" maxlength="40">')
       . inc_field('Startup name', '<input type="text" name="startup_name" value="' . e($app['startup_name']) . '" maxlength="190">')
       . inc_field('Applicant type', inc_select('applicant_type_id', inc_masters('applicant_type', false), $app['applicant_type_id'] ?? null, 'Not set'))
       . inc_field('Spoke', inc_select('spoke_id', inc_masters('spoke', false), $app['spoke_id'], 'None'))
       . inc_field('Sector', inc_select('sector_id', inc_masters('sector', false), $app['sector_id'], 'None'))
       . inc_field('Source', inc_select('source_id', inc_masters('source', false), $app['source_id'], 'None'))
       . inc_field('Reason for the correction', '<input type="text" name="reason" maxlength="500">')
       . '<button class="btn">Save corrections</button></form>'
       . '<p class="muted small">The applicant\'s original answers are kept; corrections are recorded in the modification history.</p></details></section>';
    echo '</div></div>';
}

if ($tab === 'evaluation') {
    $evals = inc_evals_for_app($id);
    $tpl = inc_template_published();
    echo '<section class="card"><h2>Evaluations</h2>';
    if (!$evals) {
        echo '<p class="muted">Not Evaluated.</p>';
    } else {
        echo '<div class="scrollx"><table class="data stack"><thead><tr><th>#</th><th>Evaluator</th><th>Development stage</th><th>Template</th><th>Status</th><th class="num">Score</th>'
           . '<th class="num">Uniqueness</th><th class="num">Coverage</th><th>Updated</th><th></th></tr></thead><tbody>';
        foreach ($evals as $ev) {
            $final = $ev['status'] === 'approved';
            echo '<tr><td data-l="#">' . (int) $ev['id'] . '</td><td data-l="Evaluator">' . e($ev['evaluator_label']) . '</td><td data-l="Development stage">' . e(inc_dev_stages()[$ev['dev_stage']] ?? $ev['dev_stage'])
               . '</td><td data-l="Template">v' . (int) $ev['version_no'] . '</td><td data-l="Status">' . inc_eval_badge($ev['status'] === 'draft' || $ev['status'] === 'in_progress' ? $ev['status'] : $ev['status'])
               . '</td><td data-l="Score" class="num">' . ($final ? e(inc_fmt_score($ev['score'])) : '<span class="muted" title="Shown once approved">—</span>')
               . '</td><td data-l="Uniqueness" class="num">' . ($final ? e(inc_fmt_rating($ev['uniqueness_rating'])) : '<span class="muted">—</span>')
               . '</td><td data-l="Coverage" class="num">' . e(number_format((float) $ev['coverage'], 0)) . '%</td><td data-l="Updated">' . e(inc_fmt_datetime($ev['updated_at'])) . '</td><td>'
               . (in_array($ev['status'], ['draft', 'in_progress', 'returned'], true) ? '<a class="btn btn-sm btn-primary" href="' . e(inc_url('evaluate', ['id' => $ev['id']])) . '">Continue</a> ' : '')
               . '<a class="btn btn-sm" href="' . e(inc_url('evaluation', ['id' => $ev['id']])) . '">' . ($final ? 'Results' : 'View') . '</a></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    if ($tpl && $app['startup_id'] && $app['dev_stage']) {
        echo inc_form_open('class="inc-row"') . '<input type="hidden" name="action" value="eval_create">'
           . '<label>Evaluator ' . inc_select('evaluator_id', inc_people_with_role('evaluator'), '', 'Administrator (me)') . '</label> '
           . '<button class="btn btn-primary">Start evaluation</button> <span class="muted small">Uses template v' . (int) $tpl['version_no'] . ' at the '
           . e(inc_dev_stages()[$app['dev_stage']] ?? '') . '.</span></form>';
    } else {
        echo '<p class="muted small">' . (!$app['startup_id'] ? 'Link a startup to start an evaluation.' : (!$app['dev_stage'] ? 'Set the startup\'s development stage (Overview tab) to start an evaluation.' : 'Publish an evaluation template first.')) . '</p>';
    }
    echo '</section>';

    $hist = inc_evals_history($app['startup_id'] ? (int) $app['startup_id'] : null, $id);
    if (count($hist) > 1) {
        echo '<section class="card"><h2>Progress over time</h2><p class="muted small">Approved evaluations of this startup. Each score is judged against the development stage it was made at.</p><div class="inc-bars">';
        foreach ($hist as $h) {
            echo '<div class="row"><div class="l">' . e(inc_fmt_date($h['approved_at'])) . ' · ' . e(inc_dev_stages()[$h['dev_stage']] ?? '') . '</div><div class="b"><i style="width:'
               . (int) round((float) $h['score']) . '%"></i></div><div class="n">' . e(inc_fmt_score($h['score'])) . '</div></div>';
        }
        echo '</div></section>';
    }

    $recs = inc_recs_for_app($id);
    $people = inc_people();
    echo '<section class="card"><h2>Recommendations and improvement plan</h2>';
    if (!$recs) {
        echo '<p class="muted small">Recommendations are generated from low-rated pillars when an evaluation is approved, and can be added by hand.</p>';
    }
    foreach ($recs as $r) {
        echo '<details class="inc-rec"><summary>' . inc_badge(str_replace('_', ' ', $r['status']), ['done' => 'green', 'open' => 'amber', 'in_progress' => 'blue', 'dropped' => ''][$r['status']])
           . ' ' . e(mb_strimwidth($r['text'], 0, 140, '…')) . ($r['due_date'] ? ' <span class="muted small">due ' . e(inc_fmt_date($r['due_date'])) . '</span>' : '')
           . ($r['owner_id'] ? ' <span class="muted small">· ' . e(inc_person_name((int) $r['owner_id'])) . '</span>' : '') . '</summary>'
           . inc_form_open() . '<input type="hidden" name="action" value="rec"><input type="hidden" name="rec_id" value="' . (int) $r['id'] . '">'
           . inc_field('Recommendation', inc_textarea('text', $r['text'], 3, 'required'))
           . '<div class="inc-row">' . inc_field('Owner', inc_select('owner_id', $people, $r['owner_id'], 'None'))
           . inc_field('Due date', '<input type="date" name="due_date" value="' . e((string) $r['due_date']) . '">')
           . inc_field('Status', inc_select('status', ['open' => 'Open', 'in_progress' => 'In progress', 'done' => 'Done', 'dropped' => 'Dropped'], $r['status'])) . '</div>'
           . inc_field('Progress note', '<input type="text" name="progress_note" maxlength="500" value="' . e((string) $r['progress_note']) . '">')
           . '<button class="btn btn-sm">Save</button> <span class="muted small">' . e($r['source'] === 'auto' ? 'Generated from evaluation #' . $r['evaluation_id'] . ' (' . (inc_dev_stages()[$r['dev_stage']] ?? '') . ')' : 'Added by ' . $r['created_by']) . '</span></form></details>';
    }
    echo '<details><summary class="btn btn-sm">Add a recommendation</summary>' . inc_form_open() . '<input type="hidden" name="action" value="rec">'
       . inc_field('Recommendation', inc_textarea('text', '', 3, 'required'))
       . '<div class="inc-row">' . inc_field('Pillar', inc_select('pillar_code', array_map(static fn($p) => $p[0], inc_default_pillars()), '', 'General'))
       . inc_field('Owner', inc_select('owner_id', $people, '', 'None')) . inc_field('Due date', '<input type="date" name="due_date">') . '</div>'
       . '<button class="btn btn-sm btn-primary">Add</button></form></details></section>';
}

if ($tab === 'depts') {
    $depts = inc_teams(true);
    $with = inc_dept_assignments_for_app($id);
    $openWith = [];
    foreach ($with as $w) {
        if ($w['state'] === 'open') {
            $openWith[(int) $w['team_id']] = true;
        }
    }
    echo '<div class="inc-grid2"><section class="card"><h2>Assign to departments</h2>';
    if (!$depts) {
        echo inc_empty('No departments yet.', 'Create them under <a href="' . e(inc_url('settings', ['tab' => 'teams'])) . '">Settings → Departments</a> from your existing team.');
    } elseif (!inc_can('assign')) {
        echo '<p class="muted small">Only the owner of Incubation Management assigns applications to departments.</p>';
    } else {
        echo '<p class="muted small">Tick one or more. Each department sees it on its desk, works it in its own stages, and returns it to you; '
           . 'the application stays at its pipeline stage. Their members are told by email.</p>'
           . inc_form_open() . '<input type="hidden" name="action" value="dept_assign"><fieldset class="inc-fs"><legend>Departments</legend>';
        foreach ($depts as $tid => $tn) {
            $n = count(inc_dept_people($tid));
            echo '<label class="check"><input type="checkbox" name="dept[]" value="' . (int) $tid . '"' . (isset($openWith[$tid]) ? ' disabled' : '') . '> ' . e($tn)
               . ' <span class="muted small">' . $n . ' ' . ($n === 1 ? 'person' : 'people') . (isset($openWith[$tid]) ? ' · already has it' : '') . '</span></label>';
        }
        echo '</fieldset>' . inc_field('Note for the departments', inc_textarea('note', '', 2, 'placeholder="What you want them to check (optional)"'))
           . '<button class="btn btn-primary">Assign</button></form>';
    }
    echo '</section><section class="card"><h2>With departments</h2>';
    if (!$with) {
        echo '<p class="muted small">Not assigned to a department yet.</p>';
    } else {
        echo '<table class="data stack"><thead><tr><th>Department</th><th>Where it is</th><th>Assigned</th><th>Returned</th></tr></thead><tbody>';
        foreach ($with as $w) {
            echo '<tr><td data-l="Department"><strong>' . e($w['team_name']) . '</strong></td>'
               . '<td data-l="Where it is">' . ($w['state'] === 'open' ? inc_badge((string) ($w['stage_name'] ?? 'Open'), 'blue') : inc_badge('Returned', 'green')) . '</td>'
               . '<td data-l="Assigned">' . e(inc_fmt_datetime($w['assigned_at'])) . '<div class="muted small">' . e($w['assigned_by']) . ($w['note'] ? ' — ' . e($w['note']) : '') . '</div></td>'
               . '<td data-l="Returned">' . ($w['state'] === 'returned' ? e(inc_fmt_datetime($w['returned_at'])) . '<div class="muted small">' . e((string) $w['returned_by']) . '</div><div>' . nl2br(e((string) $w['return_note'])) . '</div>' : '—') . '</td></tr>';
        }
        echo '</tbody></table><p class="muted small">A returned department can be ticked again on the left to send it back to them. Their notes are on the Activity tab.</p>';
    }
    echo '</section></div>';
}

if ($tab === 'assign') {
    $teams = inc_teams();
    $people = inc_people_with_role();
    if ($app['workspace_id']) {
        $ws = inc_workspace((int) $app['workspace_id']);
        echo '<section class="card"><h2>Incubation record</h2><p><a href="' . e(inc_url('workspace', ['id' => $ws['id']])) . '"><strong>' . e($ws['ref']) . '</strong></a> · '
           . e($ws['stage_name']) . ' · ' . e(inc_team_name((int) $ws['team_id'])) . ' · ' . e(inc_person_name((int) $ws['owner_id'])) . '</p></section>';
    }
    if (!$teams) {
        echo inc_empty('No departments yet.', 'Create one under <a href="' . e(inc_url('settings', ['tab' => 'teams'])) . '">Settings → Departments</a>.');
    } else {
        echo '<section class="card"><h2>' . ($app['workspace_id'] ? 'Reassign' : 'Assign for incubation') . '</h2>'
           . '<p class="muted small">' . ($app['workspace_id'] ? 'Reassigning keeps the existing incubation record, its incubation stage and all its history. The new owner is notified.'
                                                             : 'Assigning creates one incubation record at "Assigned Application" and notifies the owner by email and in the portal.') . '</p>'
           . inc_form_open() . '<input type="hidden" name="action" value="assign"><input type="hidden" name="request_key" value="' . e(bin2hex(random_bytes(12))) . '">'
           . '<div class="inc-row">' . inc_field('Department', inc_select('team_id', $teams, $app['team_id'], 'Choose department', 'required'), '', true)
           . inc_field('Owner', inc_select('owner_id', $people, $app['owner_id'], 'Choose owner', 'required'), 'Anyone in your team. If the department covers only some spokes, the application\'s spoke must be one of them.', true) . '</div>'
           . inc_field('Reason (recorded with the assignment)', '<input type="text" name="reason" maxlength="500">')
           . '<label class="check"><input type="checkbox" name="confirm" value="1" required> Confirm ' . ($app['workspace_id'] ? 'reassignment' : 'assignment') . '</label> '
           . '<button class="btn btn-primary">' . ($app['workspace_id'] ? 'Reassign' : 'Assign') . '</button></form></section>';
        if (!$app['workspace_id']) {
            echo '<section class="card"><h2>Screening owner</h2><p class="muted small">Who looks after the application while it is screened. This does not start incubation.</p>'
               . inc_form_open('class="inc-row"') . '<input type="hidden" name="action" value="owner">' . inc_select('team_id', $teams, $app['team_id'], 'No team') . ' '
               . inc_select('owner_id', $people, $app['owner_id'], 'No owner') . ' <button class="btn">Save</button></form></section>';
        }
    }
    if ($app['workspace_id']) {
        $as = inc_assignments_for((int) $app['workspace_id']);
        echo '<section class="card"><h2>Assignment history</h2><table class="data stack"><thead><tr><th>When</th><th>Event</th><th>From</th><th>To</th><th>Stage at the time</th><th>Reason</th><th>By</th></tr></thead><tbody>';
        foreach ($as as $x) {
            echo '<tr><td data-l="When">' . e(inc_fmt_datetime($x['created_at'])) . '</td><td data-l="Event">' . ($x['kind'] === 'assign' ? 'Assigned' : 'Reassigned Application') . '</td><td data-l="From">'
               . e($x['prev_owner_id'] ? inc_person_name((int) $x['prev_owner_id']) . ' (' . inc_team_name((int) $x['prev_team_id']) . ')' : '—') . '</td><td data-l="To">'
               . e(inc_person_name((int) $x['owner_id']) . ' (' . inc_team_name((int) $x['team_id']) . ')') . '</td><td data-l="Stage">' . e((string) (inc_stage((int) $x['stage_id_at'])['name'] ?? ''))
               . '</td><td data-l="Reason">' . e((string) $x['reason']) . '</td><td data-l="By">' . e($x['actor']) . '</td></tr>';
        }
        echo '</tbody></table></section>';
    }
}

if ($tab === 'activity') {
    echo '<div class="inc-grid2 wide-left"><section class="card"><h2>Timeline</h2>' . inc_timeline_html(inc_timeline($id, $app['workspace_id'] ? (int) $app['workspace_id'] : null)) . '</section><div>';
    echo '<section class="card"><h2>Add a note</h2>' . inc_form_open() . '<input type="hidden" name="action" value="note">'
       . inc_field('Type', inc_select('kind', ['note' => 'Internal note', 'comment' => 'Comment', 'call' => 'Call log', 'email' => 'Email log'], 'note'))
       . inc_textarea('body', '', 3, 'required') . ' <button class="btn btn-sm btn-primary">Add</button></form>';
    foreach (inc_notes('application', $id) as $n) {
        echo '<div class="inc-note-item"><span class="muted small">' . e(ucfirst($n['kind'])) . ' · ' . e($n['actor']) . ' · ' . e(inc_fmt_datetime($n['created_at'])) . '</span><div>' . nl2br(e($n['body'])) . '</div></div>';
    }
    echo '</section>';
    echo '<section class="card"><h2>Tasks and follow-ups</h2>' . inc_tasks_html(inc_tasks('application', $id)) . inc_task_form() . '</section>';
    echo '<section class="card"><h2>Documents</h2>' . inc_files_html(inc_files('application', $id)) . inc_form_open('', true) . '<input type="hidden" name="action" value="file">'
       . '<input type="file" name="upload" required> <input type="text" name="label" placeholder="Label (optional)"> <button class="btn btn-sm">Upload</button></form></section>';
    $ch = inc_app_changes($id);
    echo '<section class="card"><h2>Modification history</h2>';
    if (!$ch) {
        echo '<p class="muted small">No corrections.</p>';
    }
    foreach ($ch as $c) {
        echo '<div class="small">' . e(inc_fmt_datetime($c['created_at'])) . ' · ' . e($c['actor']) . ': ' . e($c['field']) . ' "' . e((string) $c['old_value']) . '" → "' . e((string) $c['new_value']) . '"' . ($c['reason'] ? ' (' . e($c['reason']) . ')' : '') . '</div>';
    }
    echo '<h3>Pipeline stage history</h3>';
    foreach (inc_stage_history('crm', $id) as $h) {
        echo '<div class="small">' . e(inc_fmt_datetime($h['created_at'])) . ': ' . e($h['from_name'] ?? 'start') . ' → ' . e($h['to_name']) . ' · ' . e($h['actor']) . ($h['reason'] ? ' · ' . e($h['reason']) : '') . '</div>';
    }
    echo '</section></div></div>';
}

inc_layout_end();

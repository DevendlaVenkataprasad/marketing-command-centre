<?php
/**
 * Incubation Management — pieces shared by the Applications pages:
 * the application card, the table row, and the bulk-action panel; and the
 * task and document lists shared with the workspace.
 */

require_once __DIR__ . '/../../uploads.php';

/** One application card. Shows only what was saved; an unapproved score is never shown as final. */
function inc_card_html(array $a): string
{
    $name = $a['startup_name'] !== '' ? $a['startup_name'] : $a['applicant_name'];
    $id = (int) $a['id'];
    $dup = $a['dup_status'] === 'suspected' ? inc_badge('Possible duplicate', 'red') : '';
    $owner = $a['ws_owner_id'] ?? $a['owner_id'];
    $team = $a['ws_team_id'] ?? $a['team_id'];
    $h = '<article class="inc-card" aria-labelledby="c' . $id . '">'
       . '<label class="sel"><input type="checkbox" class="inc-sel" name="ids[]" value="' . $id . '" aria-label="Select ' . e($a['ref']) . '"></label>'
       . '<header><div><a id="c' . $id . '" class="nm" href="' . e(inc_url('application', ['id' => $id])) . '">' . e($name) . '</a>'
       . '<div class="muted small">' . e($a['ref']) . (!empty($a['applicant_type_id']) ? ' · ' . e(inc_master_name((int) $a['applicant_type_id'])) : '') . ' · ' . e(inc_purpose_text($a['purpose'], $a['event_name'] ?? null)) . ' · ' . e(inc_fmt_date($a['submitted_at']))
       . ($a['source_id'] ? ' · ' . e(inc_master_name((int) $a['source_id'])) : '') . '</div></div>'
       . '<div class="score">' . inc_score_html($a) . implode('', array_map(static fn($x) => '<div>' . inc_gem_score_html($x[0], $x[1]) . '</div>', inc_gem_scores_for_rows([$a])[$id] ?? [])) . '</div></header>'
       . '<dl class="facts">'
       . '<div><dt>Pipeline</dt><dd>' . e($a['stage_name']) . '</dd></div>'
       . '<div><dt>Development</dt><dd>' . e($a['dev_stage'] ? (inc_dev_stages()[$a['dev_stage']] ?? $a['dev_stage']) : 'Not set') . '</dd></div>'
       . '<div><dt>Evaluation</dt><dd>' . inc_eval_badge($a['eval_status']) . '</dd></div>'
       . '<div><dt>Uniqueness</dt><dd>' . inc_uniq_html($a['uniqueness_rating']) . '</dd></div>'
       . '<div><dt>Coverage</dt><dd>' . ($a['official_coverage'] !== null ? e(number_format((float) $a['official_coverage'], 0)) . '%' : '<span class="muted">—</span>') . '</dd></div>'
       . '<div><dt>Spoke · sector</dt><dd>' . e(trim(inc_master_name($a['spoke_id'] ? (int) $a['spoke_id'] : null) . ' · ' . inc_master_name($a['sector_id'] ? (int) $a['sector_id'] : null), ' ·') ?: '—') . '</dd></div>'
       . '<div class="wide"><dt>Team · owner</dt><dd>' . e($team ? inc_team_name((int) $team) . ($owner ? ' · ' . inc_person_name((int) $owner) : '') : 'Unassigned') . '</dd></div>'
       . '</dl>' . ($dup ? '<div>' . $dup . '</div>' : '')
       . '<footer>'
       . '<a class="btn btn-sm" href="' . e(inc_url('application', ['id' => $id])) . '">View</a>'
       . ($a['eval_status'] === 'approved' && $a['official_eval_id'] ? '<a class="btn btn-sm" href="' . e(inc_url('evaluation', ['id' => $a['official_eval_id']])) . '">Results</a>'
          : '<a class="btn btn-sm" href="' . e(inc_url('application', ['id' => $id, 'tab' => 'evaluation'])) . '">Evaluate</a>')
       . ($a['workspace_id'] ? '<a class="btn btn-sm" href="' . e(inc_url('workspace', ['id' => $a['workspace_id']])) . '">Workspace ' . e($a['ws_ref']) . '</a>'
          : '<a class="btn btn-sm" href="' . e(inc_url('application', ['id' => $id, 'tab' => 'assign'])) . '">Assign</a>')
       . '</footer></article>';
    return $h;
}

function inc_table_html(array $rows): string
{
    $h = '<div class="scrollx"><table class="data stack inc-table"><thead><tr><th class="cb"><input type="checkbox" data-checkall="ids[]" aria-label="Select all on this page"></th>'
       . '<th>Application</th><th>Form · source</th><th>Submitted</th><th>Spoke · sector</th><th>Pipeline</th><th>Development</th><th>Evaluation</th>'
       . '<th class="num">Score</th><th class="num">Gem score</th><th class="num">Uniqueness</th><th>Team · owner</th><th>Incubation</th></tr></thead><tbody>';
    $gemScores = inc_gem_scores_for_rows($rows);
    $deptTags = inc_dept_tags_for_rows($rows);
    foreach ($rows as $a) {
        $id = (int) $a['id'];
        $owner = $a['ws_owner_id'] ?? $a['owner_id'];
        $team = $a['ws_team_id'] ?? $a['team_id'];
        $h .= '<tr><td class="cb"><input type="checkbox" class="inc-sel" name="ids[]" value="' . $id . '" aria-label="Select ' . e($a['ref']) . '"></td>'
            . '<td data-l="Application"><a data-popup="' . e(inc_url('quick', ['id' => $id])) . '" href="' . e(inc_url('application', ['id' => $id])) . '"><strong>' . e($a['startup_name'] !== '' ? $a['startup_name'] : $a['applicant_name']) . '</strong></a>'
            . '<div class="muted small">' . e($a['ref']) . (!empty($a['applicant_type_id']) ? ' · <span class="kb-type">' . e(inc_master_name((int) $a['applicant_type_id'])) . '</span>' : '') . ($a['startup_name'] !== '' ? ' · ' . e($a['applicant_name']) : '') . '</div>'
            . (!empty($deptTags[$id]) ? '<div class="kb-tags">' . inc_dept_tags_html($deptTags[$id]) . '</div>' : '')
            . ($a['dup_status'] === 'suspected' ? inc_badge('Possible duplicate', 'red') : '') . '</td>'
            . '<td data-l="Form · source">' . e($a['form_name']) . '<div class="muted small">' . e(inc_came_from($a)) . '</div></td>'
            . '<td data-l="Submitted" class="nowrap">' . e(inc_fmt_date($a['submitted_at'])) . '</td>'
            . '<td data-l="Spoke · sector">' . e(inc_master_name($a['spoke_id'] ? (int) $a['spoke_id'] : null) ?: '—') . '<div class="muted small">' . e(inc_master_name($a['sector_id'] ? (int) $a['sector_id'] : null)) . '</div></td>'
            . '<td data-l="Pipeline">' . e($a['stage_name']) . '</td>'
            . '<td data-l="Development">' . e($a['dev_stage'] ? (inc_dev_stages()[$a['dev_stage']] ?? '') : '—') . '</td>'
            . '<td data-l="Evaluation">' . inc_eval_badge($a['eval_status']) . '</td>'
            . '<td data-l="Score" class="num">' . ($a['official_score'] !== null ? inc_score_html($a) : '<span class="muted">—</span>') . '</td>'
            . '<td data-l="Gem score" class="num">' . (isset($gemScores[$id]) ? implode('<br>', array_map(static fn($x) => inc_gem_score_html($x[0], $x[1]), $gemScores[$id])) : '<span class="muted">—</span>') . '</td>'
            . '<td data-l="Uniqueness" class="num">' . inc_uniq_html($a['uniqueness_rating']) . '</td>'
            . '<td data-l="Team · owner">' . e($team ? inc_team_name((int) $team) : '—') . '<div class="muted small">' . e($owner ? inc_person_name((int) $owner) : '') . '</div></td>'
            . '<td data-l="Incubation">' . ($a['workspace_id'] ? '<a href="' . e(inc_url('workspace', ['id' => $a['workspace_id']])) . '">' . e($a['ws_ref']) . '</a><div class="muted small">' . e($a['ws_stage_name']) . '</div>' : '<span class="muted">—</span>') . '</td></tr>';
    }
    return $h . '</tbody></table></div>';
}

/**
 * The bulk panel. Checked boxes on this page, plus anything picked on other
 * pages (inc.js keeps those in sessionStorage), or every record that matches
 * the filters.
 */
function inc_bulk_panel(array $f, int $total, string $selKey, array $criteria = []): string
{
    $gems = inc_gems(true);
    $edit = inc_can('edit');
    $stageNames = inc_stage_map('crm');
    // [action, label, needs a form?] — what the bar offers, most used first.
    $main = [];
    if ($edit && $gems) {
        $main[] = ['gem_send', 'Email Gem form'];
        $main[] = ['scorecard', 'Email score card'];
    }
    if ($edit) {
        $main[] = ['email', 'Send email'];
    }
    if (inc_can('stage')) {
        $main[] = ['stage', 'Move to stage'];
    }
    if ($edit) {
        $main[] = ['cert_send', 'Send certificate'];
    }
    $more = [];
    if (inc_can('assign')) {
        $more[] = ['owner', 'Set team and owner'];
        $more[] = ['assign', 'Assign for incubation'];
    }
    if ($edit) {
        $more[] = ['type', 'Set applicant type'];
        $more[] = ['eval_task', 'Create evaluation tasks'];
    }
    if (inc_can('configure')) {
        $more[] = ['delete', 'Delete applications'];
    }
    $downloads = inc_can('export') ? [['export_xlsx', 'Excel — list, answers, Gem scores'], ['export_full', 'PDF — full report of each'], ['export_pdf', 'PDF — summary list']] : [];
    $titles = ['gem_send' => 'Email a Gem form', 'scorecard' => 'Email each applicant their score card', 'email' => 'Send an email', 'stage' => 'Move to another stage', 'cert_send' => 'Send a certificate for the stage each one has reached', 'owner' => 'Set team and owner',
               'assign' => 'Assign for incubation', 'eval_task' => 'Create evaluation tasks', 'type' => 'Set the applicant type', 'delete' => 'Delete the selected applications'];
    $opts = '';
    foreach (array_merge($main, $more, $downloads) as [$k, $l]) {
        $opts .= '<option value="' . e($k) . '">' . e($l) . '</option>';
    }
    $gemMoves = [];
    foreach ($gems as $g) {
        if ($g['send_stage_id'] && isset($stageNames[(int) $g['send_stage_id']])) {
            $gemMoves[] = $stageNames[(int) $g['send_stage_id']];
        }
    }

    $h = '<div class="inc-bulk" data-selkey="' . e($selKey) . '" data-titles="' . e(json_encode($titles)) . '">'
       . inc_hidden_filters($f)
       . '<select name="bulk_action" data-bulkaction hidden aria-hidden="true"><option value=""></option>' . $opts . '</select>'
       . '<input type="hidden" name="action" value="bulk"><input type="hidden" name="confirm" value="1">'
       . '<input type="hidden" name="request_key" value="' . e(bin2hex(random_bytes(12))) . '">'
       // The sheet: what the chosen action needs, above the bar.
       . '<div class="inc-bulk-sheet" hidden><div class="inc-bulk-sheethead"><h3 data-sheettitle></h3><button type="button" class="linkbtn" data-sheetclose aria-label="Close">✕</button></div>'
       . '<p class="inc-bulk-who" data-sheetwho></p><div class="inc-bulk-row">'
       . ($gems ? '<label data-for="gem_send scorecard"><span>Gem</span>' . inc_select('gem_id', array_column($gems, 'name', 'id'), count($gems) === 1 ? (int) array_key_first($gems) : '', 'Choose Gem') . '</label>'
                . '<p class="muted small" data-for="gem_send">Each applicant gets a personal link by email' . ($gemMoves ? ' and moves to ' . e(implode(' / ', array_unique($gemMoves))) . ' when they submit it' : '')
                . '. Anyone who has already answered is skipped.</p>' : '')
       . '<p class="muted small" data-for="scorecard">Each applicant gets their own score: the total, the summary, and every criterion with its reason. Anyone not yet scored is skipped.</p>'
       . '<label data-for="scorecard" class="grow"><span>Subject</span><input type="text" name="card_subject" maxlength="190" value="' . e(inc_gem_default_scorecard()[0]) . '"></label>'
       . '<label data-for="scorecard" class="inc-bulk-msg"><span>Message — {name}, {startup}, {ref} and {gem} are filled in; the score card goes where {scorecard} is</span>'
       . '<textarea name="card_intro" rows="5" maxlength="4000">' . e(inc_gem_default_scorecard()[1]) . '</textarea></label>'
       . '<p class="muted small" data-for="cert_send">Each applicant is emailed a certificate for the stage their application is at now, with a link to view and download it. '
       . 'One already sent for that stage is emailed again rather than issued twice. Design it under Settings → Certificates.</p>'
       . '<label data-for="email" class="grow"><span>Subject</span><input type="text" name="mail_subject" maxlength="190" placeholder="e.g. Your application: next steps"></label>'
       . '<label data-for="email" class="inc-bulk-msg"><span>Message — {name}, {startup} and {ref} are filled in for each person</span>'
       . '<textarea name="mail_body" rows="6" maxlength="5000">' . "Hello {name},\n\n\n\nRegards,\nThe Incubation Team" . '</textarea></label>'
       . '<label data-for="type"><span>Applicant type</span>' . inc_select('applicant_type_id', inc_masters('applicant_type'), '', 'Choose type') . '</label>'
       . '<label data-for="stage"><span>Move to</span>' . inc_select('stage_id', inc_stage_map('crm', true), '', 'Choose stage') . '</label>'
       . '<label data-for="owner assign"><span>Team</span>' . inc_select('team_id', inc_teams(), '', 'Choose team') . '</label>'
       . '<label data-for="owner assign"><span>Owner</span>' . inc_select('owner_id', inc_people_with_role(), '', 'Choose owner') . '</label>'
       . '<label data-for="eval_task"><span>Task</span><input type="text" name="title" value="Evaluate application"></label>'
       . '<label data-for="eval_task"><span>Evaluator</span>' . inc_select('assignee_id', inc_people_with_role('evaluator'), '', 'Unassigned') . '</label>'
       . '<label data-for="eval_task"><span>Due</span><input type="date" name="due_date"></label>'
       . '<p class="inc-warn" data-for="delete"><strong>This cannot be undone.</strong> Each application is removed with its answers, Gem forms and scores, evaluations, notes, documents and certificates. One already in incubation is skipped.</p>'
       . '<label data-for="delete"><span>Type DELETE to confirm</span><input type="text" name="delete_confirm" autocomplete="off" placeholder="DELETE" required pattern="\s*[Dd][Ee][Ll][Ee][Tt][Ee]\s*" title="Type DELETE to confirm"></label>'
       . '<label data-for="stage assign owner type delete" class="grow"><span>Reason (optional, recorded)</span><input type="text" name="reason" maxlength="500"></label>'
       . '</div><div class="inc-bulk-sheetfoot"><button type="button" class="btn" data-sheetclose>Cancel</button>'
       . '<button class="btn btn-primary" data-confirm="Go ahead with the selected applications?" data-sheetgo>Go</button></div></div>'
       // The bar itself.
       . '<div class="inc-bulk-bar" role="toolbar" aria-label="Selected applications">'
       . '<span class="inc-bulk-count"><strong data-selcount>0</strong> selected <span class="inc-bulk-note" data-selnote></span></span>'
       . '<label class="inc-bulk-all"><input type="checkbox" name="all_matching" value="1" data-allmatch> All ' . number_format($total) . ' matching</label>'
       . '<span class="inc-bulk-acts">';
    foreach ($main as [$k, $l]) {
        $h .= '<button type="button" class="inc-bulk-btn' . ($k === 'gem_send' ? ' primary' : '') . '" data-act="' . e($k) . '">' . e($l) . '</button>';
    }
    if ($downloads) {
        $h .= '<details class="inc-bulk-more"><summary class="inc-bulk-btn">' . (function_exists('inc_dl_icon') ? inc_dl_icon() : '') . ' Download ▾</summary><div class="inc-bulk-menu">';
        foreach ($downloads as [$k, $l]) {
            $h .= '<button type="button" data-act="' . e($k) . '">' . e($l) . '</button>';
        }
        $h .= '</div></details>';
    }
    if ($more) {
        $h .= '<details class="inc-bulk-more"><summary class="inc-bulk-btn">More ▾</summary><div class="inc-bulk-menu">';
        foreach ($more as [$k, $l]) {
            $h .= '<button type="button" data-act="' . e($k) . '">' . e($l) . '</button>';
        }
        $h .= '</div></details>';
    }
    return $h . '</span><button type="button" class="inc-bulk-clear" data-selclear aria-label="Clear selection">✕</button></div></div>';
}

/**
 * Handles a bulk POST from either desk. Downloads stream and stop; changes
 * run and redirect with a summary.
 */
function inc_bulk_handle(string $backUrl): ?array
{
    $action = (string) ($_POST['bulk_action'] ?? '');
    $flt = inc_app_filters((array) ($_POST['flt'] ?? []));
    $ids = !empty($_POST['all_matching']) ? inc_app_ids_matching($flt) : array_map('intval', (array) ($_POST['ids'] ?? []));
    $ids = array_values(array_unique(array_filter($ids)));
    // Only what the filters show: a card ticked before the filters changed is left out.
    if ($ids && empty($_POST['all_matching'])) {
        $ids = inc_app_ids_matching($flt + ['_ids' => $ids]);
    }
    if (!$ids) {
        return ['err', 'Select at least one application, or tick "Apply to all matching".'];
    }
    try {
        if (in_array($action, ['export_xlsx', 'export_pdf', 'export_full'], true)) {
            require_once __DIR__ . '/../appreport.php';
            inc_report_download(['export_xlsx' => 'xlsx', 'export_pdf' => 'list', 'export_full' => 'pdf'][$action], $ids, count($ids) . ' selected');
        }
        if (in_array($action, ['export_xlsx', 'export_pdf', 'screen_report'], true)) {
            inc_need('export');
            $apps = inc_report_apps([], $ids);
            if ($action === 'export_xlsx') {
                inc_send_xlsx('incubation-selected-' . date('Ymd-His') . '.xlsx', ['Applications' => inc_report_applications_rows($apps),
                              'Evaluations' => inc_report_evaluations_rows($apps)]);
            }
            if ($action === 'export_pdf') {
                inc_send_pdf_table('Incubation applications', count($apps) . ' selected · ' . date('d M Y H:i'), inc_report_applications_rows($apps),
                    [[0, 'Ref', 80, 'left'], [2, 'Startup', 120, 'left'], [4, 'Form', 90, 'left'], [7, 'Submitted', 60, 'left'], [8, 'Spoke', 70, 'left'],
                     [10, 'Pipeline', 90, 'left'], [11, 'Dev. stage', 75, 'left'], [12, 'Evaluation', 85, 'left'], [13, 'Score', 40, 'right'], [14, 'Uniq.', 35, 'right'],
                     [17, 'Owner', 80, 'left']], 'incubation-selected-' . date('Ymd-His') . '.pdf');
            }
            $crit = inc_screen_criteria((array) ($_POST['crit'] ?? []));
            $rows = [['Ref', 'Startup', 'Dev. stage', 'Evaluation', 'Score', 'Uniqueness', 'Coverage', 'Result']];
            $pass = 0;
            foreach ($apps as $a) {
                $why = inc_screen_check($a, $crit);
                $pass += $why === null ? 1 : 0;
                $rows[] = [$a['ref'], $a['startup_name'] ?: $a['applicant_name'], $a['dev_stage'] ? (inc_dev_stages()[$a['dev_stage']] ?? '') : '',
                           inc_eval_status_labels()[$a['eval_status']] ?? '', inc_fmt_score($a['official_score']), inc_fmt_rating($a['uniqueness_rating']),
                           $a['official_coverage'] !== null ? number_format((float) $a['official_coverage'], 0) . '%' : '—', $why === null ? 'Meets criteria' : 'Not met: ' . $why];
            }
            $desc = [];
            foreach ($crit as $k => $v) {
                $desc[] = $k . '=' . (is_array($v) ? json_encode($v) : $v);
            }
            inc_send_pdf_table('Screening report', $pass . ' of ' . count($apps) . ' meet the criteria · ' . ($desc ? implode(', ', $desc) : 'no criteria set') . ' · ' . date('d M Y H:i'),
                $rows, [[0, 'Ref', 80, 'left'], [1, 'Startup', 120, 'left'], [2, 'Dev. stage', 80, 'left'], [3, 'Evaluation', 90, 'left'], [4, 'Score', 40, 'right'],
                        [5, 'Uniq.', 40, 'right'], [6, 'Cover.', 40, 'right'], [7, 'Result', 260, 'left']], 'screening-report-' . date('Ymd-His') . '.pdf');
        }
        if (empty($_POST['confirm'])) {
            return ['err', 'Tick "I have checked the selection" to confirm a bulk change.'];
        }
        if ($action === 'email') {
            if (count($ids) > 500) {
                return ['err', 'Email up to 500 applications at a time.'];
            }
            $r = inc_apps_email($ids, (string) ($_POST['mail_subject'] ?? ''), (string) ($_POST['mail_body'] ?? ''));
            inc_redirect($backUrl, [$r['failed'] ? 'err' : 'ok', inc_gem_send_summary($r)]);
        }
        if ($action === 'cert_send') {
            if (count($ids) > 500) {
                return ['err', 'Send up to 500 certificates at a time.'];
            }
            require_once __DIR__ . '/../certs.php';
            $r = inc_cert_bulk($ids);
            inc_redirect($backUrl, [$r['failed'] ? 'err' : 'ok', 'Certificates: ' . inc_gem_send_summary($r)]);
        }
        if ($action === 'scorecard') {
            if (count($ids) > 500) {
                return ['err', 'Email up to 500 score cards at a time.'];
            }
            $r = inc_gem_scorecard_send((int) ($_POST['gem_id'] ?? 0), $ids, (string) ($_POST['card_subject'] ?? ''), (string) ($_POST['card_intro'] ?? ''));
            inc_redirect($backUrl, [$r['failed'] ? 'err' : 'ok', 'Score cards: ' . inc_gem_send_summary($r)]);
        }
        if ($action === 'gem_send') {
            if (count($ids) > 500) {
                return ['err', 'Email a Gem form to up to 500 applications at a time.'];
            }
            $r = inc_gem_send((int) ($_POST['gem_id'] ?? 0), $ids);
            inc_redirect($backUrl, [$r['failed'] ? 'err' : 'ok', inc_gem_send_summary($r)]);
        }
        $params = $_POST;
        $params['criteria'] = inc_screen_criteria((array) ($_POST['crit'] ?? []));
        $r = inc_bulk_run($action, $ids, $params);
        $msg = inc_bulk_actions()[$action] . ': ' . count($r['done']) . ' done, ' . count($r['skipped']) . ' skipped, ' . count($r['failed']) . ' failed.';
        if ($r['skipped']) {
            $first = array_slice($r['skipped'], 0, 3, true);
            $msg .= ' Skipped: ' . implode('; ', array_map(static fn($k, $v) => $k . ' — ' . $v, array_keys($first), $first)) . (count($r['skipped']) > 3 ? '; …' : '') . ' (details under Recent bulk actions).';
        }
        inc_redirect($backUrl, [$r['failed'] ? 'err' : 'ok', $msg]);
    } catch (InvalidArgumentException $e) {
        return ['err', $e->getMessage()];
    }
    return null;
}

function inc_bulk_runs_html(): string
{
    $runs = inc_bulk_runs(10);
    if (!$runs) {
        return '';
    }
    $h = '<details class="card inc-runs"><summary>Recent bulk actions (' . count($runs) . ')</summary><div class="scrollx"><table class="data stack"><thead><tr><th>When</th><th>Action</th><th class="num">Records</th>'
       . '<th class="num">Done</th><th class="num">Skipped</th><th class="num">Failed</th><th>Details</th></tr></thead><tbody>';
    foreach ($runs as $r) {
        $d = json_decode((string) $r['details_json'], true) ?: [];
        $det = '';
        foreach (['skipped' => 'Skipped', 'failed' => 'Failed'] as $k => $lab) {
            foreach (array_slice($d[$k] ?? [], 0, 50, true) as $ref => $why) {
                $det .= '<li>' . e($lab) . ' ' . e((string) $ref) . ': ' . e((string) $why) . '</li>';
            }
        }
        $h .= '<tr><td data-l="When">' . e(inc_fmt_datetime($r['created_at'])) . '</td><td data-l="Action">' . e(inc_bulk_actions()[$r['action']] ?? $r['action']) . '</td>'
            . '<td data-l="Records" class="num">' . (int) $r['total'] . '</td><td data-l="Done" class="num">' . (int) $r['done'] . '</td>'
            . '<td data-l="Skipped" class="num">' . (int) $r['skipped'] . '</td><td data-l="Failed" class="num">' . (int) $r['failed'] . '</td>'
            . '<td data-l="Details">' . ($det ? '<details><summary>Show</summary><ul class="small">' . $det . '</ul></details>' : '—') . '</td></tr>';
    }
    return $h . '</tbody></table></div></details>';
}

/* ------------------------------------------- tasks and documents */

function inc_tasks_html(array $tasks): string
{
    if (!$tasks) {
        return '<p class="muted small">No tasks.</p>';
    }
    $h = '<ul class="inc-tasks">';
    foreach ($tasks as $t) {
        $over = $t['status'] === 'open' && $t['due_date'] && $t['due_date'] < date('Y-m-d');
        $h .= '<li class="' . e($t['status']) . '"><div><strong>' . e($t['title']) . '</strong> ' . inc_badge(str_replace('_', '-', $t['kind'])) . ($over ? ' ' . inc_badge('Overdue', 'red') : '')
            . '<div class="muted small">' . e($t['assignee_id'] ? inc_person_name((int) $t['assignee_id']) : 'Unassigned') . ($t['due_date'] ? ' · due ' . e(inc_fmt_date($t['due_date'])) : '')
            . ($t['status'] !== 'open' ? ' · ' . e($t['status']) . ' by ' . e((string) $t['done_by']) : '') . '</div></div><div>'
            . ($t['status'] === 'open' ? inc_button('Done', ['action' => 'task_set', 'task_id' => $t['id'], 'status' => 'done'], 'btn btn-sm btn-approve')
                                       . inc_button('Cancel', ['action' => 'task_set', 'task_id' => $t['id'], 'status' => 'cancelled'])
                                       : inc_button('Reopen', ['action' => 'task_set', 'task_id' => $t['id'], 'status' => 'open'])) . '</div></li>';
    }
    return $h . '</ul>';
}

function inc_task_form(): string
{
    return '<details><summary class="btn btn-sm">Add a task</summary>' . inc_form_open() . '<input type="hidden" name="action" value="task">'
         . inc_field('Task', '<input type="text" name="title" maxlength="255" required>', '', true)
         . '<div class="inc-row">' . inc_field('Type', inc_select('kind', ['task' => 'Task', 'follow_up' => 'Follow-up', 'evaluation' => 'Evaluation', 'reminder' => 'Reminder'], 'task'))
         . inc_field('Assign to', inc_select('assignee_id', inc_people(), '', 'Unassigned')) . inc_field('Due', '<input type="date" name="due_date">', 'Required for follow-ups and reminders.') . '</div>'
         . inc_field('Detail', inc_textarea('detail', '', 2)) . '<button class="btn btn-sm btn-primary">Add task</button></form></details>';
}

function inc_files_html(array $files): string
{
    if (!$files) {
        return '<p class="muted small">No documents.</p>';
    }
    $h = '<ul class="inc-files">';
    foreach ($files as $f) {
        $h .= '<li><a href="' . e(inc_url('file', ['id' => $f['id']])) . '" target="_blank" rel="noopener">' . e($f['label'] ?: $f['original_name']) . '</a> <span class="muted small">' . e(fabo_human_size((int) $f['size']))
            . ' · ' . e($f['uploaded_by']) . ' · ' . e(inc_fmt_date($f['created_at'])) . '</span></li>';
    }
    return $h . '</ul>';
}

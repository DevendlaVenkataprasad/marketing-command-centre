<?php
/**
 * Incubation Management — filling in an evaluation. The questions are those the
 * evaluation's template maps to the startup's development stage; each pillar
 * shows what is expected at that stage beside the ratings.
 */

$id = (int) ($_GET['id'] ?? 0);
$ev = inc_eval($id);
if (!$ev) {
    fabo_deny('That evaluation no longer exists.', 404);
}
$app = inc_app((int) $ev['application_id']);
$self = inc_url('evaluate', ['id' => $id]);
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $files = [];
    foreach ((array) ($_FILES['evidence']['name'] ?? []) as $qid => $n) {
        $files[(int) $qid] = ['name' => $n, 'type' => $_FILES['evidence']['type'][$qid], 'tmp_name' => $_FILES['evidence']['tmp_name'][$qid],
                              'error' => $_FILES['evidence']['error'][$qid], 'size' => $_FILES['evidence']['size'][$qid]];
    }
    $submit = ($_POST['action'] ?? '') === 'submit';
    $flash = inc_action(static function () use ($id, $files, $submit) {
        $res = inc_eval_save($id, $_POST, $files);
        if ($submit) {
            inc_eval_submit($id);
            inc_redirect(inc_url('evaluation', ['id' => $id]), ['ok', 'Submitted for review.']);
        }
        return 'Saved. ' . ($res['eligible'] ? 'Complete — ready to submit.' : 'Still incomplete: ' . implode(' ', $res['reasons']));
    }, $self);
    $ev = inc_eval($id);
}

$editable = in_array($ev['status'], ['draft', 'in_progress', 'returned'], true);
if (!$editable) {
    inc_redirect(inc_url('evaluation', ['id' => $id]));
}
$tpl = inc_template((int) $ev['template_id']);
$pillars = inc_template_effective_pillars((int) $ev['template_id'], (string) $ev['dev_stage']);
$questions = inc_eval_questions($ev);
$resp = inc_eval_responses($id);
$res = inc_eval_result($ev);
$u = inc_eval_uniqueness($id);
$labels = inc_rating_labels();
$stageName = inc_dev_stages()[$ev['dev_stage']] ?? $ev['dev_stage'];
$byPillar = [];
foreach ($questions as $q) {
    $byPillar[$q['pillar_code']][] = $q;
}

inc_layout_start('Evaluate ' . ($app['startup_name'] ?: $app['applicant_name']),
    e($app['ref']) . ' · ' . e($stageName) . ' · template v' . (int) $tpl['version_no'] . ' · evaluator ' . e($ev['evaluator_label']),
    ['Applications' => inc_url('applications'), $app['ref'] => inc_url('application', ['id' => $app['id'], 'tab' => 'evaluation']), 'Evaluation #' . $id => null], '', $flash);

if ($ev['status'] === 'returned' && $ev['review_note']) {
    echo '<div class="inc-warn"><strong>Returned for clarification.</strong> ' . nl2br(e($ev['review_note'])) . '</div>';
}
if ($app['dev_stage'] && $app['dev_stage'] !== $ev['dev_stage']) {
    echo '<div class="inc-warn">The startup is now at the ' . e(inc_dev_stages()[$app['dev_stage']] ?? '') . '. This evaluation stays at the ' . e($stageName)
       . ' it was started at. Start a new evaluation to assess the new stage.</div>';
}
echo '<div class="inc-note"><strong>Assessed against the ' . e($stageName) . '.</strong> ' . e(inc_dev_stage_desc((string) $ev['dev_stage']))
   . ' Use <em>Insufficient evidence</em> when something should be there but is not shown, and <em>N/A</em> only when it is not expected at this stage. Neither counts as zero.</div>';

echo '<div class="inc-evalgrid"><form method="post" enctype="multipart/form-data" class="inc-evalform" id="inc-evalform">' . fabo_csrf_field();
foreach ($pillars as $p) {
    $qs = $byPillar[$p['code']] ?? [];
    $pr = $res['pillars'][$p['code']] ?? null;
    echo '<section class="card inc-pillar" id="p-' . e($p['code']) . '"><header><h2>' . e($p['name']) . '</h2><span class="w">' . e(rtrim(rtrim(number_format((float) $p['weight'], 2), '0'), '.')) . '% weight</span>'
       . ($pr ? '<span class="st">' . ($pr['state'] === 'rated' ? 'Pillar rating ' . e(number_format((float) $pr['rating'], 2)) : ($pr['state'] === 'na' ? 'N/A' : 'Not yet rated')) . '</span>' : '') . '</header>';
    if ($p['guidance'] !== '') {
        echo '<p class="inc-expect"><strong>Expected at this stage:</strong> ' . e($p['guidance']) . '</p>';
    }
    if (!$qs) {
        echo '<p class="muted small">No question for this pillar at the ' . e($stageName) . '; it counts as not applicable.</p>';
    }
    foreach ($qs as $q) {
        $qid = (int) $q['id'];
        $r = $resp[$qid] ?? ['status' => 'pending', 'rating' => null, 'comment' => '', 'evidence_url' => '', 'evidence_file_id' => null, 'auto_na' => 0];
        $cur = $r['status'] === 'rated' ? (string) $r['rating'] : $r['status'];
        $locked = (int) $r['auto_na'] === 1;
        echo '<fieldset class="inc-q' . ((int) $q['required'] === 1 ? ' req' : '') . '"' . ($locked ? ' disabled' : '') . '><legend>' . e($q['text'])
           . ((int) $q['required'] === 1 ? ' <span class="req" title="Required">*</span>' : '') . ($q['evidence'] === 'required' ? ' ' . inc_badge('Evidence required', 'amber') : '') . '</legend>';
        if ($q['explanation']) {
            echo '<p class="muted small">' . e($q['explanation']) . '</p>';
        }
        if ($q['guidance']) {
            echo '<p class="inc-guide small"><strong>Guidance:</strong> ' . e($q['guidance']) . '</p>';
        }
        echo '<div class="inc-rate" role="radiogroup" aria-label="Response">';
        $opts = ['pending' => 'Pending', 'insufficient' => 'Insufficient evidence'];
        foreach ([1, 2, 3, 4, 5] as $n) {
            $opts[(string) $n] = (string) $n;
        }
        if ((int) $q['allow_na'] === 1 || $locked) {
            $opts['na'] = 'N/A';
        }
        foreach ($opts as $val => $lab) {
            $title = $labels[$val] ?? ($val === 'pending' ? 'Not yet assessed' : ($val === 'insufficient' ? 'Expected at this stage but not evidenced' : ''));
            echo '<label class="o o-' . e((string) $val) . '" title="' . e($title) . '"><input type="radio" name="resp[' . $qid . '][status]" value="' . e((string) $val) . '"'
               . ($cur === (string) $val ? ' checked' : '') . '><span>' . e($lab) . '</span></label>';
        }
        echo '</div>';
        if ($locked) {
            echo '<p class="muted small">' . e((string) $r['comment']) . '</p>';
        } else {
            echo '<div class="inc-row"><label class="grow">Comment ' . inc_textarea('resp[' . $qid . '][comment]', (string) $r['comment'], 2) . '</label></div>';
            if ($q['evidence'] !== 'none') {
                echo '<div class="inc-row"><label class="grow">Evidence link <input type="url" name="resp[' . $qid . '][evidence_url]" value="' . e((string) $r['evidence_url']) . '" placeholder="https://"></label>'
                   . '<label>or file <input type="file" name="evidence[' . $qid . ']"></label>'
                   . ($r['evidence_file_id'] ? '<a class="small" href="' . e(inc_url('file', ['id' => $r['evidence_file_id']])) . '">Current file</a>' : '') . '</div>';
            }
        }
        echo '</fieldset>';
    }
    if ($p['code'] === 'uniqueness') {
        echo '<div class="inc-uniqblock"><h3>Differentiation evidence</h3><p class="muted small">A claim is not evidence. Record the alternatives considered and what supports the difference. Patents or proprietary technology are not required.</p>'
           . '<table class="data inc-comp"><thead><tr><th>Competitor or alternative</th><th>Type</th><th>How the startup differs</th><th>Evidence</th></tr></thead><tbody>';
        $comps = $u['competitors'] ?? [];
        for ($i = 0; $i < max(3, count($comps) + 2); $i++) {
            $c = $comps[$i] ?? ['name' => '', 'type' => '', 'difference' => '', 'evidence' => ''];
            echo '<tr><td><input type="text" name="comp[' . $i . '][name]" value="' . e($c['name']) . '" aria-label="Competitor ' . ($i + 1) . '"></td>'
               . '<td>' . inc_select('comp[' . $i . '][type]', ['Direct competitor' => 'Direct competitor', 'Substitute' => 'Substitute', 'Current workaround' => 'Current workaround', 'Other' => 'Other'], $c['type'], '—') . '</td>'
               . '<td><input type="text" name="comp[' . $i . '][difference]" value="' . e($c['difference']) . '"></td><td><input type="text" name="comp[' . $i . '][evidence]" value="' . e($c['evidence']) . '" placeholder="link or source"></td></tr>';
        }
        echo '</tbody></table>'
           . inc_field('Unique value proposition (as evidenced)', inc_textarea('u_uvp', (string) ($u['uvp'] ?? ''), 2))
           . inc_field('Evidence links (one per line)', inc_textarea('u_evidence_links', (string) ($u['evidence_links'] ?? ''), 2))
           . '<div class="inc-row">' . inc_field('Strengths', inc_textarea('u_strengths', (string) ($u['strengths'] ?? ''), 2), '', false, 'grow')
           . inc_field('Concerns', inc_textarea('u_concerns', (string) ($u['concerns'] ?? ''), 2), '', false, 'grow') . '</div>'
           . inc_field('Improvement recommendations', inc_textarea('u_improvements', (string) ($u['improvements'] ?? ''), 2)) . '</div>';
    }
    echo '</section>';
}
echo '<section class="card"><h2>Overall</h2>'
   . inc_field('Strengths', inc_textarea('strengths', (string) $ev['strengths'], 3))
   . inc_field('Improvement areas', inc_textarea('improvements', (string) $ev['improvements'], 3))
   . inc_field('Summary', inc_textarea('summary', (string) $ev['summary'], 3))
   . inc_field('Next evaluation date', '<input type="date" name="next_eval_date" value="' . e((string) $ev['next_eval_date']) . '">')
   . '<div class="inc-formfoot"><button class="btn" name="action" value="save">Save draft</button> '
   . '<button class="btn btn-primary" name="action" value="submit" data-confirm="Submit this evaluation for review? It cannot be edited unless it is returned.">Save and submit for review</button></div></section>';
echo '</form>';

$c = $res['counts'] ?? [];
echo '<aside class="card inc-evalside" aria-label="Progress"><h2>Progress</h2>'
   . '<p class="muted small">Recalculated each time you save. A score becomes official only when the evaluation is approved.</p><dl>'
   . '<div><dt>Status</dt><dd>' . inc_eval_badge($ev['status']) . '</dd></div>'
   . '<div><dt>Completion</dt><dd>' . ($res['eligible'] ? inc_badge('Complete', 'green') : inc_badge('Evaluation Incomplete', 'amber')) . '</dd></div>'
   . '<div><dt>Applicable-weight coverage</dt><dd>' . e(number_format((float) ($res['coverage'] ?? 0), 1)) . '%</dd></div>'
   . '<div><dt>Rated</dt><dd>' . (int) ($c['rated'] ?? 0) . '</dd></div><div><dt>Pending</dt><dd>' . (int) ($c['pending'] ?? 0) . '</dd></div>'
   . '<div><dt>Insufficient evidence</dt><dd>' . (int) ($c['insufficient'] ?? 0) . '</dd></div><div><dt>N/A</dt><dd>' . (int) ($c['na'] ?? 0) . '</dd></div>'
   . '<div><dt>Rated pillars</dt><dd>' . (int) ($res['rated_pillars'] ?? 0) . ' of ' . count($pillars) . '</dd></div></dl>';
if (!$res['eligible']) {
    echo '<ul class="small inc-reasons">';
    foreach ($res['reasons'] as $r) {
        echo '<li>' . e($r) . '</li>';
    }
    echo '</ul>';
}
echo '<nav class="small"><strong>Pillars</strong><ul>';
foreach ($pillars as $p) {
    $pr = $res['pillars'][$p['code']] ?? null;
    echo '<li><a href="#p-' . e($p['code']) . '">' . e($p['name']) . '</a> <span class="muted">' . ($pr ? ($pr['state'] === 'rated' ? e(number_format((float) $pr['rating'], 2)) : ($pr['state'] === 'na' ? 'N/A' : '—')) : '') . '</span></li>';
}
echo '</ul></nav></aside></div>';
inc_layout_end();

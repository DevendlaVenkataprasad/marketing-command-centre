<?php
/**
 * Incubation Management — an evaluation's results and its review. An approved
 * evaluation shows the values saved at approval; anything else shows its
 * status and progress, never a score presented as final.
 */

$id = (int) ($_GET['id'] ?? 0);
$ev = inc_eval($id);
if (!$ev) {
    fabo_deny('That evaluation no longer exists.', 404);
}
$self = inc_url('evaluation', ['id' => $id]);
$flash = null;
if (($_GET['pdf'] ?? '') === '1') {
    inc_eval_pdf($id)->send('evaluation-' . $id . '.pdf');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    $flash = inc_action(static function () use ($a, $id) {
        switch ($a) {
            case 'review':
                inc_eval_start_review($id);
                return 'Taken into review.';
            case 'approve':
                $r = inc_eval_approve($id, trim((string) ($_POST['note'] ?? '')));
                return 'Approved. Official score ' . number_format((float) $r['score'], 1) . '/100.';
            case 'return':
                inc_eval_return($id, (string) ($_POST['note'] ?? ''));
                return 'Returned to the evaluator for clarification.';
        }
        throw new InvalidArgumentException('Unknown action.');
    }, $self);
    $ev = inc_eval($id);
}

$app = inc_app((int) $ev['application_id']);
$tpl = inc_template((int) $ev['template_id']);
$res = inc_eval_result($ev);
$final = $ev['status'] === 'approved';
$stageName = inc_dev_stages()[$ev['dev_stage']] ?? $ev['dev_stage'];
$acts = '<a class="btn" href="' . e(inc_url('evaluation', ['id' => $id, 'pdf' => 1])) . '">Download PDF</a>'
      . (in_array($ev['status'], ['draft', 'in_progress', 'returned'], true) ? ' <a class="btn btn-primary" href="' . e(inc_url('evaluate', ['id' => $id])) . '">Continue evaluating</a>' : '');
inc_layout_start('Evaluation #' . $id . ' · ' . ($app['startup_name'] ?: $app['applicant_name']),
    e($app['ref']) . ' · ' . e($stageName) . ' · template v' . (int) $tpl['version_no'] . ' · calculation v' . (int) $ev['calc_version'] . ' · evaluator ' . e($ev['evaluator_label']),
    ['Applications' => inc_url('applications'), $app['ref'] => inc_url('application', ['id' => $app['id'], 'tab' => 'evaluation']), 'Evaluation #' . $id => null], $acts, $flash);

$c = $res['counts'] ?? [];
echo '<div class="inc-states">'
   . '<div><span>Status</span><strong>' . inc_eval_badge($ev['status']) . '</strong></div>'
   . '<div><span>Official score</span><strong>' . ($final && $ev['score'] !== null ? '<span class="inc-score">' . e(inc_fmt_score($ev['score'])) . '<small>/100</small></span>'
       : '<span class="inc-noscore">' . ($res['eligible'] ? 'Shown once approved' : 'Evaluation Incomplete') . '</span>') . '</strong></div>'
   . '<div><span>Uniqueness &amp; Differentiation</span><strong>' . ($final ? inc_uniq_html($ev['uniqueness_rating']) : '<span class="muted">—</span>') . '</strong></div>'
   . '<div><span>Applicable-weight coverage</span><strong>' . e(number_format((float) ($res['coverage'] ?? 0), 1)) . '%</strong></div>'
   . '<div><span>Questions</span><strong class="small">' . (int) ($c['rated'] ?? 0) . ' rated · ' . (int) ($c['pending'] ?? 0) . ' pending · ' . (int) ($c['insufficient'] ?? 0)
   . ' insufficient · ' . (int) ($c['na'] ?? 0) . ' N/A</strong></div>'
   . '<div><span>Rated pillars</span><strong>' . (int) ($res['rated_pillars'] ?? 0) . ' of ' . count($res['pillars'] ?? []) . '</strong></div>'
   . '</div>';
if (!$res['eligible']) {
    echo '<div class="inc-warn"><strong>Evaluation Incomplete.</strong> ' . e(implode(' ', $res['reasons'])) . '</div>';
}
if ($ev['review_note']) {
    echo '<div class="inc-note"><strong>Reviewer\'s note' . ($ev['reviewed_by'] ? ' (' . e($ev['reviewed_by']) . ', ' . e(inc_fmt_datetime($ev['reviewed_at'])) . ')' : '') . ':</strong> ' . nl2br(e($ev['review_note'])) . '</div>';
}

echo '<div class="inc-grid2 wide-left"><section class="card"><h2>Pillars</h2><p class="muted small">Assessed against the expectations of the ' . e($stageName)
   . '. Compare scores only between startups at the same stage.</p>'
   . '<div class="scrollx"><table class="data inc-pillars"><thead><tr><th>Pillar</th><th class="num">Weight %</th><th class="num">Rating /5</th><th class="num">Rating × weight</th><th>Answers</th><th></th></tr></thead><tbody>';
foreach ($res['pillars'] ?? [] as $p) {
    $pc = $p['counts'];
    $bar = $p['rating'] !== null ? '<div class="inc-mini-bar" aria-hidden="true"><i style="width:' . (int) round($p['rating'] * 20) . '%"></i></div>' : '';
    echo '<tr' . ($p['code'] === 'uniqueness' ? ' class="hl"' : '') . '><td>' . e($p['name']) . '</td><td class="num">' . e(rtrim(rtrim(number_format((float) $p['weight'], 2), '0'), '.')) . '</td>'
       . '<td class="num">' . ($p['state'] === 'rated' ? e(number_format((float) $p['rating'], 2)) : ($p['state'] === 'na' ? 'N/A' : '<span class="muted">not rated</span>')) . '</td>'
       . '<td class="num">' . ($p['contribution'] !== null ? e(number_format((float) $p['contribution'], 2)) : '—') . '</td>'
       . '<td class="small">' . (int) $pc['rated'] . ' rated' . ($pc['pending'] ? ', ' . (int) $pc['pending'] . ' pending' : '') . ($pc['insufficient'] ? ', ' . (int) $pc['insufficient'] . ' insufficient' : '')
       . ($pc['na'] ? ', ' . (int) $pc['na'] . ' N/A' : '') . '</td><td>' . $bar . '</td></tr>';
}
echo '</tbody></table></div>'
   . '<p class="muted small">Final Score = (Σ pillar rating × weight ÷ Σ weight of rated pillars) × 20. Numerator ' . e(number_format((float) ($res['numerator'] ?? 0), 2))
   . ' ÷ ' . e(rtrim(rtrim(number_format((float) ($res['rated_weight'] ?? 0), 2), '0'), '.')) . ' × 20' . ($res['score'] !== null ? ' = ' . e(number_format((float) $res['score'], 2)) . ($final ? '' : ' (provisional — not official)') : '') . '.'
   . ' N/A pillars leave the denominator; Pending and Insufficient Evidence never count as zero.</p></section><div>';

if (in_array($ev['status'], ['submitted', 'under_review'], true)) {
    echo '<section class="card"><h2>Review</h2>';
    if ($ev['status'] === 'submitted') {
        echo inc_button('Take into review', ['action' => 'review'], 'btn');
    }
    echo inc_form_open() . inc_field('Note to the evaluator', inc_textarea('note', '', 3), 'Required when returning for clarification.')
       . '<button class="btn btn-approve" name="action" value="approve" data-confirm="Approve this evaluation? Its score becomes official and is frozen.">Approve</button> '
       . '<button class="btn btn-reject" name="action" value="return">Return for clarification</button></form></section>';
}
$u = inc_eval_uniqueness($id);
echo '<section class="card"><h2>Uniqueness &amp; Differentiation</h2><p>' . ($res['pillars']['uniqueness'] ?? null ? 'Pillar rating: <strong>'
   . (($res['pillars']['uniqueness']['state'] ?? '') === 'rated' ? e(number_format((float) $res['pillars']['uniqueness']['rating'], 2)) . '/5' : (($res['pillars']['uniqueness']['state'] ?? '') === 'na' ? 'N/A' : 'not rated')) . '</strong>'
   : 'This template version has no Uniqueness & Differentiation pillar.') . '</p>';
foreach (['Unique value proposition' => 'uvp', 'Strengths' => 'strengths', 'Concerns' => 'concerns', 'Improvement recommendations' => 'improvements', 'Evidence links' => 'evidence_links'] as $lab => $k) {
    if (trim((string) ($u[$k] ?? '')) !== '') {
        echo '<h3>' . e($lab) . '</h3><p class="small">' . nl2br(e((string) $u[$k])) . '</p>';
    }
}
if (!empty($u['competitors'])) {
    echo '<h3>Alternatives compared</h3><ul class="small">';
    foreach ($u['competitors'] as $cp) {
        echo '<li><strong>' . e($cp['name']) . '</strong>' . ($cp['type'] ? ' (' . e($cp['type']) . ')' : '') . ($cp['difference'] ? ' — ' . e($cp['difference']) : '') . ($cp['evidence'] ? ' <span class="muted">[' . e($cp['evidence']) . ']</span>' : '') . '</li>';
    }
    echo '</ul>';
}
echo '</section>';
foreach (['Strengths' => 'strengths', 'Improvement areas' => 'improvements', 'Summary' => 'summary'] as $lab => $k) {
    if (trim((string) $ev[$k]) !== '') {
        echo '<section class="card"><h2>' . e($lab) . '</h2><p>' . nl2br(e((string) $ev[$k])) . '</p></section>';
    }
}
if ($ev['next_eval_date']) {
    echo '<section class="card"><h2>Next evaluation</h2><p>' . e(inc_fmt_date($ev['next_eval_date'])) . '</p></section>';
}
$recs = inc_all('SELECT * FROM inc_recommendations WHERE evaluation_id = ? ORDER BY id', 'i', [$id]);
if ($recs) {
    echo '<section class="card"><h2>Recommended next actions</h2><ul class="small">';
    foreach ($recs as $r) {
        echo '<li>' . e($r['text']) . ' ' . inc_badge(str_replace('_', ' ', $r['status'])) . '</li>';
    }
    echo '</ul><a class="btn btn-sm" href="' . e(inc_url('application', ['id' => $app['id'], 'tab' => 'evaluation'])) . '">Manage recommendations</a></section>';
}
echo '</div></div>';

echo '<section class="card"><h2>Responses</h2>';
$resp = inc_eval_responses($id);
$names = array_column(inc_template_pillars((int) $ev['template_id'], false), 'name', 'code');
$last = '';
$labels = ['pending' => 'Pending', 'insufficient' => 'Insufficient evidence', 'rated' => 'Rated', 'na' => 'N/A'];
foreach (inc_eval_questions($ev) as $q) {
    if ($q['pillar_code'] !== $last) {
        echo ($last !== '' ? '</ul>' : '') . '<h3>' . e($names[$q['pillar_code']] ?? $q['pillar_code']) . '</h3><ul class="inc-resp">';
        $last = $q['pillar_code'];
    }
    $r = $resp[(int) $q['id']] ?? ['status' => 'pending'];
    echo '<li><span class="r r-' . e($r['status']) . '">' . e($r['status'] === 'rated' ? $r['rating'] . '/5' : $labels[$r['status']]) . '</span><div>' . e($q['text'])
       . (trim((string) ($r['comment'] ?? '')) !== '' ? '<div class="muted small">' . nl2br(e((string) $r['comment'])) . '</div>' : '')
       . (!empty($r['evidence_url']) ? '<div class="small"><a href="' . e((string) $r['evidence_url']) . '" rel="noopener noreferrer" target="_blank">Evidence link</a></div>' : '')
       . (!empty($r['evidence_file_id']) ? '<div class="small"><a href="' . e(inc_url('file', ['id' => $r['evidence_file_id']])) . '">Evidence file</a></div>' : '') . '</div></li>';
}
echo ($last !== '' ? '</ul>' : '') . '</section>';

$hist = inc_evals_history($app['startup_id'] ? (int) $app['startup_id'] : null, (int) $app['id']);
if ($hist) {
    echo '<section class="card"><h2>Approved evaluations over time</h2><table class="data stack"><thead><tr><th>Approved</th><th>Application</th><th>Development stage</th><th>Template</th>'
       . '<th class="num">Score</th><th class="num">Uniqueness</th><th class="num">Coverage</th><th></th></tr></thead><tbody>';
    foreach ($hist as $h) {
        echo '<tr' . ((int) $h['id'] === $id ? ' class="hl"' : '') . '><td data-l="Approved">' . e(inc_fmt_date($h['approved_at'])) . '</td><td data-l="Application">' . e($h['ref']) . '</td><td data-l="Stage">'
           . e(inc_dev_stages()[$h['dev_stage']] ?? '') . '</td><td data-l="Template">v' . (int) $h['version_no'] . '</td><td data-l="Score" class="num">' . e(inc_fmt_score($h['score']))
           . '</td><td data-l="Uniqueness" class="num">' . e(inc_fmt_rating($h['uniqueness_rating'])) . '</td><td data-l="Coverage" class="num">' . e(number_format((float) $h['coverage'], 0)) . '%</td>'
           . '<td><a class="btn btn-sm" href="' . e(inc_url('evaluation', ['id' => $h['id']])) . '">Open</a></td></tr>';
    }
    echo '</tbody></table></section>';
}
inc_layout_end();

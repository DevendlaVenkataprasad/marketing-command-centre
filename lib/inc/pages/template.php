<?php
/**
 * Incubation Management — one evaluation template version: completion rules, the
 * nine pillars and their weights (default and per development stage), stage
 * guidance, and the question bank. Only a draft can be changed.
 */

$id = (int) ($_GET['id'] ?? 0);
$tpl = inc_template($id);
if (!$tpl) {
    fabo_deny('That template no longer exists.', 404);
}
$fp = (string) ($_GET['pillar'] ?? '');
$fs = (string) ($_GET['stage'] ?? '');
$self = inc_url('template', ['id' => $id] + ($fp !== '' ? ['pillar' => $fp] : []) + ($fs !== '' ? ['stage' => $fs] : []));
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    $flash = inc_action(static function () use ($a, $id) {
        switch ($a) {
            case 'rules':
                inc_template_settings_save($id, $_POST);
                return 'Rules saved.';
            case 'weights':
                inc_template_weights_save($id, (array) ($_POST['w'] ?? []));
                return 'Weights saved.';
            case 'pillar':
                inc_template_pillar_save($id, (int) ($_POST['pillar_id'] ?? 0), $_POST);
                return 'Pillar saved.';
            case 'question':
                inc_question_save($id, inc_int_or_null($_POST['question_id'] ?? null), $_POST);
                return 'Question saved.';
            case 'qmove':
                inc_question_move($id, (int) ($_POST['question_id'] ?? 0), (int) ($_POST['dir'] ?? 0));
                return 'Order changed.';
            case 'publish':
                inc_template_publish($id);
                return 'Published. New evaluations use this version. Approved evaluations keep their own versions and scores.';
            case 'delete':
                inc_template_delete_draft($id);
                inc_redirect(inc_url('settings', ['tab' => 'evaluation']), ['ok', 'Draft deleted.']);
        }
        throw new InvalidArgumentException('Unknown action.');
    }, $self);
    $tpl = inc_template($id);
}

$draft = $tpl['status'] === 'draft';
$rules = inc_template_rules($tpl);
$pillars = inc_template_pillars($id, false);
$stages = inc_dev_stages();
[$problems, $warnings] = inc_template_problems($id);
$sum = array_sum(array_map(static fn($p) => (int) $p['active'] === 1 ? (float) $p['weight'] : 0, $pillars));

$acts = $draft ? inc_button('Publish', ['action' => 'publish'], 'btn btn-primary', 'Publish this version? It replaces the current one for new evaluations. Approved evaluations are not recalculated.')
               . ' ' . inc_button('Delete draft', ['action' => 'delete'], 'btn btn-reject', 'Delete this draft version?') : '';
inc_layout_start('Evaluation template v' . (int) $tpl['version_no'], e($tpl['name']) . ' · ' . e(ucfirst($tpl['status'])) . ($tpl['published_at'] ? ' · published ' . e(inc_fmt_date($tpl['published_at'])) : ''),
    ['Settings' => inc_url('settings', ['tab' => 'evaluation']), 'Template v' . (int) $tpl['version_no'] => null], $acts, $flash);
if (!$draft) {
    echo '<div class="inc-note">This version is ' . e($tpl['status']) . ' and read-only. To change it, start a new version from Settings → Evaluation templates.</div>';
}
if ($problems) {
    echo '<div class="inc-warn"><strong>Cannot be published yet:</strong><ul>';
    foreach ($problems as $p) {
        echo '<li>' . e($p) . '</li>';
    }
    echo '</ul></div>';
}
if ($warnings) {
    echo '<details class="inc-note"><summary>' . count($warnings) . ' note' . (count($warnings) === 1 ? '' : 's') . ' on coverage</summary><ul class="small">';
    foreach ($warnings as $w) {
        echo '<li>' . e($w) . '</li>';
    }
    echo '</ul></details>';
}
$dis = $draft ? '' : ' disabled';

echo '<div class="inc-grid2"><section class="card"><h2>Pillar weights</h2>' . inc_form_open() . '<input type="hidden" name="action" value="weights">'
   . '<table class="data"><thead><tr><th>Pillar</th><th class="num">Default weight %</th>';
foreach ($stages as $code => $name) {
    echo '<th class="num small" title="Stage override">' . e(explode(' ', $name)[0]) . '</th>';
}
echo '</tr></thead><tbody>';
foreach ($pillars as $p) {
    echo '<tr' . ((int) $p['active'] !== 1 ? ' class="muted"' : '') . ($p['code'] === 'uniqueness' ? ' class="hl"' : '') . '><td>' . e($p['name']) . ((int) $p['active'] !== 1 ? ' (inactive)' : '') . '</td>'
       . '<td class="num"><input type="number" name="w[' . (int) $p['id'] . ']" value="' . e(rtrim(rtrim(number_format((float) $p['weight'], 2), '0'), '.')) . '" min="0" max="100" step="0.01" class="inc-w" data-weight' . $dis . '></td>';
    foreach (array_keys($stages) as $s) {
        echo '<td class="num small">' . (isset($p['stage_weights'][$s]) ? e((string) $p['stage_weights'][$s]) : '<span class="muted">—</span>') . '</td>';
    }
    echo '</tr>';
}
echo '</tbody><tfoot><tr><th>Total</th><th class="num"><span data-weight-total>' . e(rtrim(rtrim(number_format($sum, 2), '0'), '.')) . '</span>%</th><th colspan="5" class="small muted">Must be exactly 100%. Stage overrides are set per pillar below; each stage that has any override must also total 100%.</th></tr></tfoot></table>'
   . ($draft ? '<button class="btn btn-primary">Save weights</button>' : '') . '</form></section>';

echo '<section class="card"><h2>Completion rules</h2>' . inc_form_open() . '<input type="hidden" name="action" value="rules">'
   . inc_field('Template name', '<input type="text" name="name" value="' . e($tpl['name']) . '" maxlength="160" required' . $dis . '>')
   . '<div class="inc-row">' . inc_field('Minimum applicable-weight coverage %', '<input type="number" name="min_coverage" min="0" max="100" step="1" value="' . e((string) $rules['min_coverage']) . '"' . $dis . '>', 'Rated pillar weight as a share of the total.')
   . inc_field('Approved evaluations needed for an official score', '<input type="number" name="min_approved" min="1" max="10" value="' . (int) $rules['min_approved'] . '"' . $dis . '>', 'With more than one, the official score is the mean of the latest round.') . '</div>'
   . '<label class="check"><input type="checkbox" name="require_all_pillars" value="1"' . (!empty($rules['require_all_pillars']) ? ' checked' : '') . $dis . '> Every applicable pillar must be rated</label><br>'
   . '<label class="check"><input type="checkbox" name="enforce_required" value="1"' . (!empty($rules['enforce_required']) ? ' checked' : '') . $dis . '> Required questions may not be left Pending</label><br>'
   . '<label class="check"><input type="checkbox" name="enforce_evidence" value="1"' . (!empty($rules['enforce_evidence']) ? ' checked' : '') . $dis . '> "Evidence required" questions need a link or file when rated</label>'
   . inc_field('Recommend actions for pillars rated at or below', '<input type="number" name="low_threshold" min="1" max="5" step="0.1" value="' . e((string) $rules['low_threshold']) . '"' . $dis . '>')
   . ($draft ? '<button class="btn">Save rules</button>' : '') . '</form><p class="muted small">Submission and approval: an evaluation can be submitted only when these rules are met; a reviewer then approves it or returns it for clarification.</p></section></div>';

echo '<section class="card"><h2>Pillars</h2>';
foreach ($pillars as $p) {
    echo '<details class="inc-pillaredit"><summary><strong>' . e($p['name']) . '</strong> <span class="muted small">' . e($p['code']) . ' · ' . e(rtrim(rtrim(number_format((float) $p['weight'], 2), '0'), '.')) . '%</span></summary>'
       . inc_form_open() . '<input type="hidden" name="action" value="pillar"><input type="hidden" name="pillar_id" value="' . (int) $p['id'] . '">'
       . '<div class="inc-row">' . inc_field('Name', '<input type="text" name="name" value="' . e($p['name']) . '" maxlength="120" required' . $dis . '>', '', false, 'grow')
       . inc_field('Default weight %', '<input type="number" name="weight" value="' . e((string) (float) $p['weight']) . '" min="0" max="100" step="0.01"' . $dis . '>')
       . '<label class="check"><input type="checkbox" name="active" value="1"' . ((int) $p['active'] === 1 ? ' checked' : '') . $dis . '> Active</label></div>'
       . inc_field('Description', inc_textarea('description', (string) $p['description'], 3, $dis));
    echo '<table class="data"><thead><tr><th>Stage</th><th>Weight override %</th><th>What is expected at this stage (shown to evaluators)</th></tr></thead><tbody>';
    foreach ($stages as $s => $sn) {
        echo '<tr><td>' . e($sn) . '</td><td><input type="number" name="sw_' . e($s) . '" value="' . e((string) ($p['stage_weights'][$s] ?? '')) . '" min="0" max="100" step="0.01" placeholder="default"' . $dis . '></td>'
           . '<td>' . inc_textarea('g_' . $s, (string) ($p['guidance'][$s] ?? ''), 2, $dis) . '</td></tr>';
    }
    echo '</tbody></table>' . ($draft ? '<button class="btn btn-sm btn-primary">Save pillar</button>' : '') . '</form></details>';
}
echo '</section>';

$qs = inc_template_questions($id, null, false);
$pnames = array_column($pillars, 'name', 'code');
$shown = array_filter($qs, static fn($q) => ($fp === '' || $q['pillar_code'] === $fp) && ($fs === '' || inc_question_applies_to_stage($q, $fs)));
echo '<section class="card"><h2>Questions</h2><form method="get" class="inc-row"><input type="hidden" name="p" value="template"><input type="hidden" name="id" value="' . $id . '">'
   . inc_select('pillar', $pnames, $fp, 'All pillars', 'data-autosubmit') . ' ' . inc_select('stage', $stages, $fs, 'All stages', 'data-autosubmit')
   . ' <noscript><button class="btn btn-sm">Filter</button></noscript><span class="muted small">' . count($shown) . ' of ' . count($qs) . ' questions</span></form>';
foreach ($shown as $q) {
    $qst = trim((string) $q['stages']) === 'all' ? 'All stages' : implode(', ', array_map(static fn($s) => $stages[$s] ?? $s, explode(',', (string) $q['stages'])));
    echo '<details class="inc-qrow' . ((int) $q['active'] !== 1 ? ' off' : '') . '"><summary>' . e($q['text']) . ' <span class="muted small">' . e($pnames[$q['pillar_code']] ?? $q['pillar_code']) . ' · ' . e($qst)
       . ((int) $q['required'] === 1 ? ' · required' : '') . ((int) $q['allow_na'] === 1 ? ' · N/A allowed' : '') . ($q['evidence'] === 'required' ? ' · evidence required' : '') . '</span>'
       . ((int) $q['active'] !== 1 ? ' ' . inc_badge('Inactive') : '') . '</summary>';
    if ($draft) {
        echo '<div class="inc-row">' . inc_button('↑', ['action' => 'qmove', 'question_id' => $q['id'], 'dir' => -1]) . inc_button('↓', ['action' => 'qmove', 'question_id' => $q['id'], 'dir' => 1]) . '</div>';
    }
    echo inc_question_editor($q, $pnames, $stages, $draft) . '</details>';
}
if ($draft) {
    echo '<details class="inc-qrow add"><summary><strong>+ Add a question</strong></summary>' . inc_question_editor(null, $pnames, $stages, true, $fp, $fs) . '</details>';
}
echo '</section>';
inc_layout_end();

function inc_question_editor(?array $q, array $pnames, array $stages, bool $draft, string $fp = '', string $fs = ''): string
{
    $dis = $draft ? '' : ' disabled';
    $cur = $q ? (trim((string) $q['stages']) === 'all' ? array_keys($stages) : explode(',', (string) $q['stages'])) : ($fs !== '' ? [$fs] : []);
    $h = inc_form_open() . '<input type="hidden" name="action" value="question">' . ($q ? '<input type="hidden" name="question_id" value="' . (int) $q['id'] . '">' : '')
       . '<div class="inc-row">' . inc_field('Pillar', inc_select('pillar_code', $pnames, $q['pillar_code'] ?? $fp, 'Choose', 'required' . $dis))
       . inc_field('Question', '<input type="text" name="text" value="' . e($q['text'] ?? '') . '" maxlength="500" required' . $dis . '>', '', true, 'grow') . '</div>'
       . '<fieldset class="inc-fs"><legend>Development stages it applies to</legend>';
    foreach ($stages as $s => $sn) {
        $h .= '<label class="check"><input type="checkbox" name="stages[]" value="' . e($s) . '"' . (in_array($s, $cur, true) ? ' checked' : '') . $dis . '> ' . e($sn) . '</label>';
    }
    $h .= '</fieldset>' . inc_field('Explanation', inc_textarea('explanation', (string) ($q['explanation'] ?? ''), 2, $dis))
        . inc_field('Example answer or rating guidance', inc_textarea('guidance', (string) ($q['guidance'] ?? ''), 2, $dis))
        . '<div class="inc-row"><label class="check"><input type="checkbox" name="required" value="1"' . (!empty($q['required']) ? ' checked' : '') . $dis . '> Required</label>'
        . '<label class="check"><input type="checkbox" name="allow_na" value="1"' . (!$q || (int) $q['allow_na'] === 1 ? ' checked' : '') . $dis . '> N/A allowed (not expected at some stages)</label>'
        . '<label class="check"><input type="checkbox" name="active" value="1"' . (!$q || (int) $q['active'] === 1 ? ' checked' : '') . $dis . '> Active</label>'
        . inc_field('Evidence', inc_select('evidence', ['none' => 'No evidence field', 'optional' => 'Optional', 'required' => 'Required when rated'], $q['evidence'] ?? 'optional', '', $dis)) . '</div>'
        . '<div class="inc-row">' . inc_field('Applies only if application field (uid)', '<input type="text" name="applies_field" value="' . e((string) ($q['applies_field'] ?? '')) . '" maxlength="40" placeholder="e.g. prototype"' . $dis . '>')
        . inc_field('has the value', '<input type="text" name="applies_value" value="' . e((string) ($q['applies_value'] ?? '')) . '" maxlength="190" placeholder="e.g. Yes"' . $dis . '>',
                    'Otherwise the question is marked N/A automatically for that application.') . '</div>'
        . ($draft ? '<button class="btn btn-sm btn-primary">' . ($q ? 'Save question' : 'Add question') . '</button>' : '') . '</form>';
    return $h;
}

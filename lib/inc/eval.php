<?php
/**
 * Incubation Management — evaluation templates, evaluations and recommendations.
 *
 * TEMPLATES are versioned. One is published at a time; it is what new
 * evaluations use. A published template is frozen: editing means starting a
 * new draft version (a copy), changing that, and publishing it — which
 * retires the old one but leaves every evaluation made with it pointing at
 * it. Approved results are snapshots (inc_evaluations.result_json and the
 * score columns) and are never recalculated by a later template.
 *
 * EVALUATIONS move Draft → In Progress → Submitted → Under Review →
 * Approved, or back to the evaluator as Returned (with the reviewer's note)
 * and on to a resubmission. Only a complete evaluation (the template's
 * completion rules) can be submitted, so a score is never presented as
 * official while information is missing.
 */

require_once __DIR__ . '/core.php';
require_once __DIR__ . '/scoring.php';

/* ============================================================= templates */

function inc_template(int $id): ?array
{
    return inc_one('SELECT * FROM inc_eval_templates WHERE id = ?', 'i', [$id]);
}

function inc_template_published(): ?array
{
    return inc_one("SELECT * FROM inc_eval_templates WHERE status = 'published' ORDER BY version_no DESC LIMIT 1");
}

function inc_template_draft(): ?array
{
    return inc_one("SELECT * FROM inc_eval_templates WHERE status = 'draft' ORDER BY version_no DESC LIMIT 1");
}

function inc_templates(): array
{
    return inc_all('SELECT t.*, (SELECT COUNT(*) FROM inc_evaluations e WHERE e.template_id = t.id) AS evals,
                            (SELECT COUNT(*) FROM inc_eval_pillars p WHERE p.template_id = t.id AND p.active = 1) AS pillars
                     FROM inc_eval_templates t ORDER BY t.version_no DESC');
}

function inc_template_rules(array $tpl): array
{
    $r = json_decode((string) $tpl['rules_json'], true);
    return (is_array($r) ? $r : []) + inc_default_rules();
}

function inc_template_pillars(int $tid, bool $activeOnly = true): array
{
    $rows = inc_all('SELECT * FROM inc_eval_pillars WHERE template_id = ?' . ($activeOnly ? ' AND active = 1' : '') . ' ORDER BY sort, id', 'i', [$tid]);
    foreach ($rows as &$r) {
        $r['stage_weights'] = json_decode((string) $r['stage_weights_json'], true) ?: [];
        $r['guidance'] = json_decode((string) $r['guidance_json'], true) ?: [];
    }
    return $rows;
}

/** The pillars with the weight that applies at $stage (a stage override when one is set). */
function inc_template_effective_pillars(int $tid, string $stage): array
{
    $out = [];
    foreach (inc_template_pillars($tid) as $p) {
        $w = isset($p['stage_weights'][$stage]) && $p['stage_weights'][$stage] !== '' ? (float) $p['stage_weights'][$stage] : (float) $p['weight'];
        $out[] = ['code' => $p['code'], 'name' => $p['name'], 'weight' => $w, 'description' => $p['description'],
                  'guidance' => (string) ($p['guidance'][$stage] ?? '')];
    }
    return $out;
}

/** Does any pillar override the weights at this stage? */
function inc_template_has_stage_weights(int $tid, string $stage): bool
{
    foreach (inc_template_pillars($tid) as $p) {
        if (isset($p['stage_weights'][$stage]) && $p['stage_weights'][$stage] !== '') {
            return true;
        }
    }
    return false;
}

function inc_question_applies_to_stage(array $q, string $stage): bool
{
    $s = trim((string) $q['stages']);
    return $s === '' || $s === 'all' || in_array($stage, explode(',', $s), true);
}

function inc_template_questions(int $tid, ?string $stage = null, bool $activeOnly = true): array
{
    $rows = inc_all('SELECT * FROM inc_eval_questions WHERE template_id = ?' . ($activeOnly ? ' AND active = 1' : '') . ' ORDER BY pillar_code, sort, id', 'i', [$tid]);
    if ($stage !== null) {
        $rows = array_values(array_filter($rows, static fn($q) => inc_question_applies_to_stage($q, $stage)));
    }
    return $rows;
}

/** Problems that stop a template from being published (blocking), and warnings. */
function inc_template_problems(int $tid): array
{
    $pillars = inc_template_pillars($tid);
    $problems = [];
    $warnings = [];
    $base = [];
    foreach ($pillars as $p) {
        $base[$p['name']] = $p['weight'];
    }
    $problems = array_merge($problems, inc_weights_problems($base, 'Default weights'));
    foreach (inc_dev_stages(true) as $code => $name) {
        if (inc_template_has_stage_weights($tid, $code)) {
            $w = [];
            foreach (inc_template_effective_pillars($tid, $code) as $p) {
                $w[$p['name']] = $p['weight'];
            }
            $problems = array_merge($problems, inc_weights_problems($w, $name . ' weights'));
        }
        $qs = inc_template_questions($tid, $code);
        $have = array_unique(array_column($qs, 'pillar_code'));
        foreach ($pillars as $p) {
            if ((float) $p['weight'] > 0 && !in_array($p['code'], $have, true)) {
                $warnings[] = $p['name'] . ' has no active question for the ' . $name . '; it will count as not applicable there.';
            }
        }
    }
    return [$problems, $warnings];
}

function inc_template_guard_draft(int $tid): array
{
    $t = inc_template($tid);
    if (!$t) {
        throw new InvalidArgumentException('That template no longer exists.');
    }
    if ($t['status'] !== 'draft') {
        throw new InvalidArgumentException('Version ' . $t['version_no'] . ' is ' . $t['status'] . ' and cannot be changed. Start a new version to make changes.');
    }
    return $t;
}

/** Copies a template into a new draft version. Only one draft may exist. */
function inc_template_new_version(int $fromId, string $note = ''): int
{
    inc_need('configure');
    if (inc_template_draft()) {
        throw new InvalidArgumentException('A draft version already exists. Publish or delete it first.');
    }
    $from = inc_template($fromId);
    if (!$from) {
        throw new InvalidArgumentException('That template no longer exists.');
    }
    return inc_tx(static function () use ($from, $note) {
        $no = (int) inc_one('SELECT MAX(version_no) AS n FROM inc_eval_templates')['n'] + 1;
        $tid = inc_exec("INSERT INTO inc_eval_templates (version_no, name, status, rules_json, note, created_by) VALUES (?,?,'draft',?,?,?)",
                         'issss', [$no, $from['name'], $from['rules_json'], $note !== '' ? mb_substr($note, 0, 500) : 'Copied from version ' . $from['version_no'], inc_actor()]);
        inc_exec('INSERT INTO inc_eval_pillars (template_id, code, name, description, weight, stage_weights_json, guidance_json, sort, active)
                   SELECT ?, code, name, description, weight, stage_weights_json, guidance_json, sort, active FROM inc_eval_pillars WHERE template_id = ?',
                  'ii', [$tid, (int) $from['id']]);
        inc_exec('INSERT INTO inc_eval_questions (template_id, uid, pillar_code, stages, text, explanation, guidance, required, evidence, allow_na, applies_field, applies_value, sort, active)
                   SELECT ?, uid, pillar_code, stages, text, explanation, guidance, required, evidence, allow_na, applies_field, applies_value, sort, active
                   FROM inc_eval_questions WHERE template_id = ?', 'ii', [$tid, (int) $from['id']]);
        inc_audit('new_version', 'template', $tid, 'Version ' . $no . ' drafted from version ' . $from['version_no']);
        return $tid;
    });
}

function inc_template_delete_draft(int $tid): void
{
    inc_need('configure');
    inc_template_guard_draft($tid);
    if (inc_one('SELECT id FROM inc_evaluations WHERE template_id = ? LIMIT 1', 'i', [$tid])) {
        throw new InvalidArgumentException('Evaluations use this version, so it cannot be deleted.');
    }
    inc_exec('DELETE FROM inc_eval_templates WHERE id = ?', 'i', [$tid]);
    inc_audit('delete', 'template', $tid, 'Draft deleted');
}

function inc_template_publish(int $tid): void
{
    inc_need('configure');
    $t = inc_template_guard_draft($tid);
    [$problems] = inc_template_problems($tid);
    if ($problems) {
        throw new InvalidArgumentException('Cannot publish: ' . implode(' ', $problems));
    }
    inc_tx(static function () use ($tid) {
        inc_exec("UPDATE inc_eval_templates SET status = 'retired' WHERE status = 'published'");
        inc_exec("UPDATE inc_eval_templates SET status = 'published', published_at = NOW(), published_by = ? WHERE id = ?", 'si', [inc_actor(), $tid]);
    });
    inc_audit('publish', 'template', $tid, 'Version ' . $t['version_no'] . ' published. Existing evaluations keep their own versions; approved scores are not recalculated.');
}

function inc_template_settings_save(int $tid, array $in): void
{
    inc_need('configure');
    $t = inc_template_guard_draft($tid);
    $name = inc_str($in['name'] ?? '', 160, true, 'Template name');
    $rules = inc_template_rules($t);
    $cov = (string) ($in['min_coverage'] ?? $rules['min_coverage']);
    if (!is_numeric($cov) || $cov < 0 || $cov > 100) {
        throw new InvalidArgumentException('Minimum coverage must be between 0 and 100.');
    }
    $minApproved = (string) ($in['min_approved'] ?? $rules['min_approved']);
    if (!ctype_digit($minApproved) || (int) $minApproved < 1 || (int) $minApproved > 10) {
        throw new InvalidArgumentException('Approved evaluations needed must be between 1 and 10.');
    }
    $low = (string) ($in['low_threshold'] ?? $rules['low_threshold']);
    if (!is_numeric($low) || $low < 1 || $low > 5) {
        throw new InvalidArgumentException('The recommendation threshold must be between 1 and 5.');
    }
    $new = ['min_coverage' => (float) $cov, 'require_all_pillars' => !empty($in['require_all_pillars']) ? 1 : 0,
            'enforce_required' => !empty($in['enforce_required']) ? 1 : 0, 'enforce_evidence' => !empty($in['enforce_evidence']) ? 1 : 0,
            'min_approved' => (int) $minApproved, 'low_threshold' => (float) $low];
    inc_exec('UPDATE inc_eval_templates SET name = ?, rules_json = ? WHERE id = ?', 'ssi', [$name, json_encode($new), $tid]);
    inc_audit('update', 'template', $tid, 'Rules', $rules, $new);
}

function inc_template_pillar_save(int $tid, int $pillarId, array $in): void
{
    inc_need('configure');
    inc_template_guard_draft($tid);
    $p = inc_one('SELECT * FROM inc_eval_pillars WHERE id = ? AND template_id = ?', 'ii', [$pillarId, $tid]);
    if (!$p) {
        throw new InvalidArgumentException('That pillar is not in this version.');
    }
    $name = inc_str($in['name'] ?? '', 120, true, 'Pillar name');
    $desc = inc_str($in['description'] ?? '', 3000);
    $w = (string) ($in['weight'] ?? '');
    if (!is_numeric($w) || $w < 0 || $w > 100) {
        throw new InvalidArgumentException($name . ': weight must be between 0 and 100.');
    }
    $sw = [];
    $guide = [];
    foreach (array_keys(inc_dev_stages()) as $s) {
        $v = trim((string) ($in['sw_' . $s] ?? ''));
        if ($v !== '') {
            if (!is_numeric($v) || $v < 0 || $v > 100) {
                throw new InvalidArgumentException($name . ': stage weights must be between 0 and 100.');
            }
            $sw[$s] = (float) $v;
        }
        $g = inc_str($in['g_' . $s] ?? '', 1000);
        if ($g !== '') {
            $guide[$s] = $g;
        }
    }
    $active = !empty($in['active']) ? 1 : 0;
    inc_exec('UPDATE inc_eval_pillars SET name = ?, description = ?, weight = ?, stage_weights_json = ?, guidance_json = ?, active = ? WHERE id = ?',
              'ssdssii', [$name, $desc, (float) $w, $sw ? json_encode($sw) : null, json_encode($guide), $active, $pillarId]);
    inc_audit('update', 'pillar', $pillarId, $name, ['weight' => $p['weight'], 'stage_weights' => $p['stage_weights_json']], ['weight' => $w, 'stage_weights' => $sw]);
}

/** Saves all the default weights of a draft at once (the usual way weights are rebalanced). */
function inc_template_weights_save(int $tid, array $weights): void
{
    inc_need('configure');
    inc_template_guard_draft($tid);
    $pillars = inc_template_pillars($tid, false);
    $clean = [];
    foreach ($pillars as $p) {
        $v = (string) ($weights[$p['id']] ?? $p['weight']);
        if (!is_numeric($v) || $v < 0 || $v > 100) {
            throw new InvalidArgumentException($p['name'] . ': weight must be between 0 and 100.');
        }
        $clean[(int) $p['id']] = (float) $v;
    }
    inc_tx(static function () use ($clean) {
        foreach ($clean as $id => $w) {
            inc_exec('UPDATE inc_eval_pillars SET weight = ? WHERE id = ?', 'di', [$w, $id]);
        }
    });
    inc_audit('weights', 'template', $tid, 'Weights: ' . json_encode(array_values($clean)));
}

function inc_question_save(int $tid, ?int $qid, array $in): int
{
    inc_need('configure');
    inc_template_guard_draft($tid);
    $pillar = (string) ($in['pillar_code'] ?? '');
    if (!inc_one('SELECT id FROM inc_eval_pillars WHERE template_id = ? AND code = ?', 'is', [$tid, $pillar])) {
        throw new InvalidArgumentException('Choose the pillar this question belongs to.');
    }
    $stages = array_values(array_intersect((array) ($in['stages'] ?? []), array_keys(inc_dev_stages())));
    if (!$stages) {
        throw new InvalidArgumentException('Choose at least one development stage for the question.');
    }
    $stagesStr = count($stages) === count(inc_dev_stages()) ? 'all' : implode(',', $stages);
    $text = inc_str($in['text'] ?? '', 500, true, 'Question');
    $expl = inc_str($in['explanation'] ?? '', 3000);
    $guide = inc_str($in['guidance'] ?? '', 3000);
    $ev = (string) ($in['evidence'] ?? 'optional');
    if (!in_array($ev, ['none', 'optional', 'required'], true)) {
        $ev = 'optional';
    }
    $af = inc_str($in['applies_field'] ?? '', 40);
    $av = inc_str($in['applies_value'] ?? '', 190);
    if (($af === '') !== ($av === '')) {
        throw new InvalidArgumentException('An applicability rule needs both the application field and the value.');
    }
    $row = [$pillar, $stagesStr, $text, $expl ?: null, $guide ?: null, !empty($in['required']) ? 1 : 0, $ev, !empty($in['allow_na']) ? 1 : 0,
            $af ?: null, $av ?: null, !empty($in['active']) ? 1 : 0];
    if ($qid) {
        $before = inc_one('SELECT * FROM inc_eval_questions WHERE id = ? AND template_id = ?', 'ii', [$qid, $tid]);
        if (!$before) {
            throw new InvalidArgumentException('That question is not in this version.');
        }
        inc_exec('UPDATE inc_eval_questions SET pillar_code = ?, stages = ?, text = ?, explanation = ?, guidance = ?, required = ?, evidence = ?, allow_na = ?, applies_field = ?, applies_value = ?, active = ? WHERE id = ?',
                  'sssssisisssi', array_merge($row, [$qid]));
        inc_audit('update', 'question', $qid, $text, $before, $row);
    } else {
        $sort = (int) (inc_one('SELECT COALESCE(MAX(sort),0) + 1 AS n FROM inc_eval_questions WHERE template_id = ? AND pillar_code = ?', 'is', [$tid, $pillar])['n'] ?? 1);
        $qid = inc_exec('INSERT INTO inc_eval_questions (template_id, uid, pillar_code, stages, text, explanation, guidance, required, evidence, allow_na, applies_field, applies_value, active, sort)
                          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)', 'issssssisisssi', array_merge([$tid, 'q_' . bin2hex(random_bytes(5))], $row, [$sort]));
        inc_audit('create', 'question', $qid, $text);
    }
    return $qid;
}

function inc_question_move(int $tid, int $qid, int $dir): void
{
    inc_need('configure');
    inc_template_guard_draft($tid);
    $q = inc_one('SELECT * FROM inc_eval_questions WHERE id = ? AND template_id = ?', 'ii', [$qid, $tid]);
    if (!$q) {
        return;
    }
    $ids = array_map(static fn($r) => (int) $r['id'],
        inc_all('SELECT id FROM inc_eval_questions WHERE template_id = ? AND pillar_code = ? ORDER BY sort, id', 'is', [$tid, $q['pillar_code']]));
    $pos = array_search($qid, $ids, true);
    $swap = $pos + ($dir < 0 ? -1 : 1);
    if ($swap < 0 || $swap >= count($ids)) {
        return;
    }
    [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]];
    inc_tx(static function () use ($ids) {
        foreach ($ids as $i => $id) {
            inc_exec('UPDATE inc_eval_questions SET sort = ? WHERE id = ?', 'ii', [$i + 1, $id]);
        }
    });
}

/**
 * A published template that predates the ninth pillar is left exactly as it
 * is (its evaluations and approved scores stay valid). A new DRAFT version
 * is prepared with Uniqueness & Differentiation added at the default
 * weights, for an administrator to review and publish.
 */
function inc_ninth_pillar_upgrade(): ?int
{
    $pub = inc_template_published();
    if (!$pub || inc_one("SELECT id FROM inc_eval_pillars WHERE template_id = ? AND code = 'uniqueness'", 'i', [(int) $pub['id']]) || inc_template_draft()) {
        return null;
    }
    // Offered once per published version: a draft the administrator deleted is not recreated.
    if (inc_setting('ninth_pillar_offered') === (string) $pub['id']) {
        return null;
    }
    inc_setting_set('ninth_pillar_offered', (string) $pub['id']);
    $tid = inc_template_new_version((int) $pub['id'], 'Adds the ninth pillar, Uniqueness & Differentiation, at the default weights');
    $defaults = inc_default_pillars();
    inc_tx(static function () use ($tid, $defaults) {
        foreach ($defaults as $code => [$name, $w]) {
            inc_exec('UPDATE inc_eval_pillars SET weight = ? WHERE template_id = ? AND code = ?', 'dis', [$w, $tid, $code]);
        }
        [$name, $w, $desc, $guide] = $defaults['uniqueness'];
        inc_exec('INSERT INTO inc_eval_pillars (template_id, code, name, description, weight, guidance_json, sort) VALUES (?,?,?,?,?,?,?)',
                  'isssdsi', [$tid, 'uniqueness', $name, $desc, $w, json_encode($guide), 99]);
        $n = 0;
        foreach (inc_default_questions()['uniqueness'] as $stage => $qs) {
            foreach ($qs as [$text, $expl, $g, $req, $ev, $na]) {
                $n++;
                inc_exec('INSERT INTO inc_eval_questions (template_id, uid, pillar_code, stages, text, explanation, guidance, required, evidence, allow_na, sort)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?)', 'issssssisii', [$tid, 'q_uniqueness_' . $stage . '_' . $n, 'uniqueness', $stage, $text, $expl, $g, $req, $ev, $na, $n]);
            }
        }
    });
    return $tid;
}

/* =========================================================== evaluations */

function inc_eval(int $id): ?array
{
    return inc_one('SELECT * FROM inc_evaluations WHERE id = ?', 'i', [$id]);
}

function inc_evals_for_app(int $appId): array
{
    return inc_all('SELECT e.*, t.version_no FROM inc_evaluations e JOIN inc_eval_templates t ON t.id = e.template_id
                     WHERE e.application_id = ? ORDER BY e.created_at DESC, e.id DESC', 'i', [$appId]);
}

/** Approved evaluations of a startup across all its applications, oldest first: its progress over time. */
function inc_evals_history(?int $startupId, int $appId): array
{
    if ($startupId) {
        return inc_all("SELECT e.*, a.ref, t.version_no FROM inc_evaluations e JOIN inc_applications a ON a.id = e.application_id JOIN inc_eval_templates t ON t.id = e.template_id
                         WHERE (e.startup_id = ? OR e.application_id = ?) AND e.status = 'approved' ORDER BY e.approved_at, e.id", 'ii', [$startupId, $appId]);
    }
    return inc_all("SELECT e.*, a.ref, t.version_no FROM inc_evaluations e JOIN inc_applications a ON a.id = e.application_id JOIN inc_eval_templates t ON t.id = e.template_id
                     WHERE e.application_id = ? AND e.status = 'approved' ORDER BY e.approved_at, e.id", 'i', [$appId]);
}

function inc_eval_responses(int $evalId): array
{
    $out = [];
    foreach (inc_all('SELECT * FROM inc_eval_responses WHERE evaluation_id = ?', 'i', [$evalId]) as $r) {
        $out[(int) $r['question_id']] = $r;
    }
    return $out;
}

/** The questions an evaluation asks: those its template maps to its development stage, in the order it was created with. */
function inc_eval_questions(array $ev): array
{
    $ids = array_keys(inc_eval_responses((int) $ev['id']));
    if (!$ids) {
        return [];
    }
    return inc_all('SELECT q.* FROM inc_eval_questions q JOIN inc_eval_pillars p ON p.template_id = q.template_id AND p.code = q.pillar_code
                     WHERE q.id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY p.sort, q.sort, q.id');
}

/** Recomputes an evaluation from its saved responses with its own template. Pure read. */
function inc_eval_compute(array $ev): array
{
    $tpl = inc_template((int) $ev['template_id']);
    return inc_score_calculate(
        inc_template_effective_pillars((int) $ev['template_id'], (string) $ev['dev_stage']),
        inc_eval_questions($ev),
        inc_eval_responses((int) $ev['id']),
        inc_template_rules($tpl)
    );
}

/** Saves the current calculation onto the evaluation row. */
function inc_eval_store_result(int $evalId): array
{
    $ev = inc_eval($evalId);
    $res = inc_eval_compute($ev);
    inc_exec('UPDATE inc_evaluations SET score = ?, coverage = ?, eligible = ?, uniqueness_rating = ?, result_json = ?, calc_version = ? WHERE id = ?',
              'ddidsii', [$res['score'] === null ? null : round($res['score'], 2), round($res['coverage'], 2), $res['eligible'] ? 1 : 0,
                          $res['uniqueness'] === null ? null : round($res['uniqueness'], 3), json_encode($res), INC_CALC_VERSION, $evalId]);
    return $res;
}

/** The saved calculation of an evaluation (never recomputed for an approved one). */
function inc_eval_result(array $ev): array
{
    $r = json_decode((string) $ev['result_json'], true);
    if (is_array($r)) {
        return $r;
    }
    return $ev['status'] === 'approved' ? ['pillars' => [], 'counts' => [], 'score' => null, 'eligible' => false, 'reasons' => ['No saved result.'], 'coverage' => 0]
                                        : inc_eval_compute($ev);
}

/** Does an application response satisfy a question's applicability rule? */
function inc_question_applies_to_app(array $q, array $appValues): bool
{
    if (empty($q['applies_field'])) {
        return true;
    }
    $v = $appValues[$q['applies_field']]['value'] ?? null;
    $vals = is_array($v) ? array_map('strval', $v) : ($v === null || $v === '' ? [] : [(string) $v]);
    return in_array((string) $q['applies_value'], $vals, true);
}

/**
 * Starts an evaluation of an application at its startup's current
 * development stage, with the published template. An evaluator who already
 * has an unfinished evaluation of this application gets that one back.
 */
function inc_eval_create(int $appId, ?int $evaluatorId): int
{
    inc_need('evaluate');
    require_once __DIR__ . '/forms.php';
    $app = inc_one('SELECT * FROM inc_applications WHERE id = ?', 'i', [$appId]);
    if (!$app) {
        throw new InvalidArgumentException('That application no longer exists.');
    }
    if (!$app['startup_id']) {
        throw new InvalidArgumentException('Link the application to a startup before evaluating it, so the evaluation follows the startup\'s development stage.');
    }
    $startup = inc_one('SELECT * FROM inc_startups WHERE id = ?', 'i', [(int) $app['startup_id']]);
    if (!$startup['dev_stage'] || !isset(inc_dev_stages(true)[$startup['dev_stage']])) {
        throw new InvalidArgumentException('Set the startup\'s development stage before evaluating it.');
    }
    $label = 'Administrator';
    if ($evaluatorId) {
        if (!isset(inc_people_with_role('evaluator')[$evaluatorId])) {
            throw new InvalidArgumentException('The chosen evaluator does not hold the evaluator role in an active incubation team.');
        }
        $label = inc_person_name($evaluatorId);
    }
    $tpl = inc_template_published();
    if (!$tpl) {
        throw new InvalidArgumentException('No evaluation template is published.');
    }
    $open = inc_one("SELECT id FROM inc_evaluations WHERE application_id = ? AND " . ($evaluatorId ? 'evaluator_id = ?' : 'evaluator_id IS NULL')
                     . " AND status IN ('draft','in_progress','returned','submitted','under_review') LIMIT 1",
                     $evaluatorId ? 'ii' : 'i', $evaluatorId ? [$appId, $evaluatorId] : [$appId]);
    if ($open) {
        return (int) $open['id'];
    }
    $stage = (string) $startup['dev_stage'];
    $questions = inc_template_questions((int) $tpl['id'], $stage);
    if (!$questions) {
        throw new InvalidArgumentException('The published template has no questions for the ' . inc_dev_stages()[$stage] . '.');
    }
    $values = inc_app_values($appId);
    $id = inc_tx(static function () use ($app, $startup, $tpl, $stage, $evaluatorId, $label, $questions, $values) {
        $id = inc_exec('INSERT INTO inc_evaluations (application_id, startup_id, template_id, dev_stage, evaluator_id, evaluator_label, status, created_by) VALUES (?,?,?,?,?,?,"draft",?)',
                        'iiisiss', [(int) $app['id'], (int) $startup['id'], (int) $tpl['id'], $stage, $evaluatorId, $label, inc_actor()]);
        foreach ($questions as $q) {
            $applies = inc_question_applies_to_app($q, $values);
            inc_exec('INSERT INTO inc_eval_responses (evaluation_id, question_id, status, auto_na, comment) VALUES (?,?,?,?,?)', 'iisis',
                      [$id, (int) $q['id'], $applies ? 'pending' : 'na', $applies ? 0 : 1, $applies ? null : 'Not applicable: the application does not meet this question\'s applicability rule.']);
        }
        inc_exec('INSERT INTO inc_eval_uniqueness (evaluation_id) VALUES (?)', 'i', [$id]);
        inc_activity((int) $app['id'], $app['workspace_id'] ? (int) $app['workspace_id'] : null, 'evaluation',
                     'Evaluation #' . $id . ' started by ' . $label . ' (' . inc_dev_stages()[$stage] . ', template v' . $tpl['version_no'] . ').');
        return $id;
    });
    inc_eval_store_result($id);
    inc_audit('create', 'evaluation', $id, 'Application ' . $app['ref']);
    inc_app_refresh_eval($appId);
    return $id;
}

function inc_eval_guard_editable(array $ev): void
{
    if (!in_array($ev['status'], ['draft', 'in_progress', 'returned'], true)) {
        throw new InvalidArgumentException('This evaluation is ' . str_replace('_', ' ', $ev['status']) . ' and can no longer be edited.');
    }
}

/**
 * Saves responses and the evaluation's narrative.
 * $in: resp[qid][status|rating|comment|evidence_url], strengths, improvements,
 *      summary, next_eval_date, u_* (uniqueness block), competitors[...].
 * $files: qid => uploaded file (evidence).
 */
function inc_eval_save(int $evalId, array $in, array $files = []): array
{
    inc_need('evaluate');
    $ev = inc_eval($evalId);
    if (!$ev) {
        throw new InvalidArgumentException('That evaluation no longer exists.');
    }
    inc_eval_guard_editable($ev);
    $questions = [];
    foreach (inc_eval_questions($ev) as $q) {
        $questions[(int) $q['id']] = $q;
    }
    $current = inc_eval_responses($evalId);
    $updates = [];
    foreach ((array) ($in['resp'] ?? []) as $qid => $r) {
        $qid = (int) $qid;
        if (!isset($questions[$qid], $current[$qid]) || (int) $current[$qid]['auto_na'] === 1) {
            continue;
        }
        $q = $questions[$qid];
        $status = (string) ($r['status'] ?? 'pending');
        $rating = null;
        if (ctype_digit($status) && (int) $status >= 1 && (int) $status <= 5) {
            $rating = (int) $status;
            $status = 'rated';
        }
        if (!in_array($status, ['pending', 'insufficient', 'rated', 'na'], true)) {
            throw new InvalidArgumentException('An answer status is not recognised.');
        }
        if ($status === 'rated' && $rating === null) {
            $rating = (int) ($r['rating'] ?? 0);
            if ($rating < 1 || $rating > 5) {
                throw new InvalidArgumentException('Ratings run from 1 to 5: "' . $q['text'] . '".');
            }
        }
        if ($status === 'na' && (int) $q['allow_na'] !== 1) {
            throw new InvalidArgumentException('"' . $q['text'] . '" cannot be marked N/A at this stage. Use Insufficient Evidence or Pending instead.');
        }
        $comment = inc_str($r['comment'] ?? '', 5000);
        $url = inc_str($r['evidence_url'] ?? '', 500);
        if ($url !== '' && !preg_match('~^https?://~i', $url)) {
            $url = 'https://' . $url;
        }
        if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('The evidence link for "' . $q['text'] . '" is not a valid web address.');
        }
        $updates[$qid] = [$status, $status === 'rated' ? $rating : null, $comment ?: null, $url ?: null];
    }
    $u = [
        'uvp' => inc_str($in['u_uvp'] ?? '', 5000), 'evidence_links' => inc_str($in['u_evidence_links'] ?? '', 5000),
        'strengths' => inc_str($in['u_strengths'] ?? '', 5000), 'concerns' => inc_str($in['u_concerns'] ?? '', 5000),
        'improvements' => inc_str($in['u_improvements'] ?? '', 5000),
    ];
    $competitors = [];
    foreach ((array) ($in['comp'] ?? []) as $c) {
        $name = inc_str($c['name'] ?? '', 190);
        if ($name === '') {
            continue;
        }
        $competitors[] = ['name' => $name, 'type' => inc_str($c['type'] ?? '', 60), 'difference' => inc_str($c['difference'] ?? '', 1000),
                          'evidence' => inc_str($c['evidence'] ?? '', 500)];
        if (count($competitors) >= 20) {
            break;
        }
    }
    $next = inc_date($in['next_eval_date'] ?? '', false, 'Next evaluation date');
    $stored = [];
    try {
        foreach ($files as $qid => $up) {
            $qid = (int) $qid;
            if (!isset($questions[$qid]) || ($up['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            require_once __DIR__ . '/../uploads.php';
            [$ok, $msg, $meta] = fabo_store_upload($up);
            if (!$ok) {
                throw new InvalidArgumentException('Evidence for "' . $questions[$qid]['text'] . '": ' . $msg);
            }
            $stored[$qid] = $meta;
        }
        inc_tx(static function () use ($evalId, $ev, $updates, $u, $competitors, $in, $next, $stored, $questions) {
            foreach ($updates as $qid => [$status, $rating, $comment, $url]) {
                inc_exec('UPDATE inc_eval_responses SET status = ?, rating = ?, comment = ?, evidence_url = ?, updated_by = ? WHERE evaluation_id = ? AND question_id = ?',
                          'sisssii', [$status, $rating, $comment, $url, inc_actor(), $evalId, $qid]);
            }
            foreach ($stored as $qid => $m) {
                $fid = inc_exec('INSERT INTO inc_files (entity, entity_id, label, stored_name, original_name, mime, size, uploaded_by) VALUES ("evaluation",?,?,?,?,?,?,?)',
                                 'issssis', [$evalId, 'Evidence: ' . mb_substr($questions[$qid]['text'], 0, 170), $m['stored_name'], $m['original_name'], $m['mime'], $m['size'], inc_actor()]);
                inc_exec('UPDATE inc_eval_responses SET evidence_file_id = ? WHERE evaluation_id = ? AND question_id = ?', 'iii', [$fid, $evalId, $qid]);
            }
            inc_exec('INSERT INTO inc_eval_uniqueness (evaluation_id, competitors_json, uvp, evidence_links, strengths, concerns, improvements) VALUES (?,?,?,?,?,?,?)
                       ON DUPLICATE KEY UPDATE competitors_json = VALUES(competitors_json), uvp = VALUES(uvp), evidence_links = VALUES(evidence_links),
                       strengths = VALUES(strengths), concerns = VALUES(concerns), improvements = VALUES(improvements)',
                      'issssss', [$evalId, json_encode($competitors, JSON_UNESCAPED_UNICODE), $u['uvp'], $u['evidence_links'], $u['strengths'], $u['concerns'], $u['improvements']]);
            inc_exec('UPDATE inc_evaluations SET strengths = ?, improvements = ?, summary = ?, next_eval_date = ?, status = IF(status = "draft", "in_progress", status) WHERE id = ?',
                      'ssssi', [inc_str($in['strengths'] ?? '', 5000), inc_str($in['improvements'] ?? '', 5000), inc_str($in['summary'] ?? '', 5000), $next, $evalId]);
        });
    } catch (Throwable $e) {
        require_once __DIR__ . '/../uploads.php';
        foreach ($stored as $m) {
            fabo_delete_stored($m['stored_name']);
        }
        throw $e;
    }
    $res = inc_eval_store_result($evalId);
    inc_app_refresh_eval((int) $ev['application_id']);
    return $res;
}

function inc_eval_uniqueness(int $evalId): array
{
    $r = inc_one('SELECT * FROM inc_eval_uniqueness WHERE evaluation_id = ?', 'i', [$evalId]) ?: [];
    $r['competitors'] = json_decode((string) ($r['competitors_json'] ?? ''), true) ?: [];
    return $r;
}

function inc_eval_submit(int $evalId): array
{
    inc_need('evaluate');
    $ev = inc_eval($evalId);
    if (!$ev) {
        throw new InvalidArgumentException('That evaluation no longer exists.');
    }
    inc_eval_guard_editable($ev);
    $res = inc_eval_store_result($evalId);
    if (!$res['eligible']) {
        throw new InvalidArgumentException('Evaluation Incomplete — it cannot be submitted yet. ' . implode(' ', $res['reasons']));
    }
    inc_exec("UPDATE inc_evaluations SET status = 'submitted', submitted_at = NOW() WHERE id = ?", 'i', [$evalId]);
    $app = inc_one('SELECT * FROM inc_applications WHERE id = ?', 'i', [(int) $ev['application_id']]);
    inc_activity((int) $app['id'], $app['workspace_id'] ? (int) $app['workspace_id'] : null, 'evaluation', 'Evaluation #' . $evalId . ' submitted for review by ' . $ev['evaluator_label'] . '.');
    inc_audit('submit', 'evaluation', $evalId, $app['ref']);
    inc_app_refresh_eval((int) $app['id']);
    inc_notify('review_request', 'eval' . $evalId . ':s' . time(), 0, ['startup' => $app['startup_name'] ?: $app['applicant_name'], 'ref' => $app['ref'], 'evaluator' => $ev['evaluator_label']],
               (int) $app['id'], null, inc_admin_link('evaluation', ['id' => $evalId]));
    return $res;
}

function inc_eval_start_review(int $evalId): void
{
    inc_need('review');
    $ev = inc_eval($evalId);
    if (!$ev || $ev['status'] !== 'submitted') {
        throw new InvalidArgumentException('Only a submitted evaluation can be taken into review.');
    }
    inc_exec("UPDATE inc_evaluations SET status = 'under_review', review_started_at = NOW(), reviewed_by = ? WHERE id = ?", 'si', [inc_actor(), $evalId]);
    inc_activity((int) $ev['application_id'], null, 'evaluation', 'Evaluation #' . $evalId . ' taken into review.');
    inc_audit('review', 'evaluation', $evalId, 'Under review');
    inc_app_refresh_eval((int) $ev['application_id']);
}

/**
 * Approves an evaluation. Its result is recalculated one last time with its
 * own template, then frozen: from here on the saved values are the record.
 */
function inc_eval_approve(int $evalId, string $note = ''): array
{
    inc_need('review');
    $ev = inc_eval($evalId);
    if (!$ev || !in_array($ev['status'], ['submitted', 'under_review'], true)) {
        throw new InvalidArgumentException('Only a submitted evaluation, or one under review, can be approved.');
    }
    $res = inc_eval_store_result($evalId);
    if (!$res['eligible']) {
        throw new InvalidArgumentException('It cannot be approved: ' . implode(' ', $res['reasons']));
    }
    inc_exec("UPDATE inc_evaluations SET status = 'approved', approved_at = NOW(), reviewed_at = NOW(), reviewed_by = ?, review_note = ? WHERE id = ?",
              'ssi', [inc_actor(), mb_substr($note, 0, 5000) ?: null, $evalId]);
    $app = inc_one('SELECT * FROM inc_applications WHERE id = ?', 'i', [(int) $ev['application_id']]);
    inc_activity((int) $app['id'], $app['workspace_id'] ? (int) $app['workspace_id'] : null, 'evaluation',
                 'Evaluation #' . $evalId . ' approved. Score ' . number_format((float) $res['score'], 1) . '/100'
                 . ($res['uniqueness'] !== null ? '; Uniqueness & Differentiation ' . inc_fmt_rating($res['uniqueness']) . '/5' : '') . '.');
    inc_audit('approve', 'evaluation', $evalId, $app['ref'] . ' score ' . round((float) $res['score'], 2), null, ['score' => $res['score'], 'coverage' => $res['coverage']], $note);
    inc_recs_generate($evalId);
    inc_app_refresh_eval((int) $app['id']);
    $vars = ['startup' => $app['startup_name'] ?: $app['applicant_name'], 'ref' => $app['ref'], 'score' => number_format((float) $res['score'], 1) . '/100',
             'uniqueness' => $res['uniqueness'] === null ? 'not rated' : inc_fmt_rating($res['uniqueness']) . '/5'];
    if ($ev['evaluator_id']) {
        inc_notify('approved', 'eval' . $evalId, (int) $ev['evaluator_id'], $vars, (int) $app['id']);
    }
    return $res;
}

function inc_eval_return(int $evalId, string $note): void
{
    inc_need('review');
    $note = inc_str($note, 5000, true, 'A note explaining what needs clarifying');
    $ev = inc_eval($evalId);
    if (!$ev || !in_array($ev['status'], ['submitted', 'under_review'], true)) {
        throw new InvalidArgumentException('Only a submitted evaluation, or one under review, can be returned.');
    }
    inc_exec("UPDATE inc_evaluations SET status = 'returned', reviewed_at = NOW(), reviewed_by = ?, review_note = ? WHERE id = ?", 'ssi', [inc_actor(), $note, $evalId]);
    $app = inc_one('SELECT * FROM inc_applications WHERE id = ?', 'i', [(int) $ev['application_id']]);
    inc_activity((int) $app['id'], null, 'evaluation', 'Evaluation #' . $evalId . ' returned for clarification: ' . mb_substr($note, 0, 300));
    inc_audit('return', 'evaluation', $evalId, $app['ref'], null, null, $note);
    inc_app_refresh_eval((int) $app['id']);
    if ($ev['evaluator_id']) {
        inc_notify('returned', 'eval' . $evalId . ':r' . md5($note . microtime()), (int) $ev['evaluator_id'],
                   ['startup' => $app['startup_name'] ?: $app['applicant_name'], 'ref' => $app['ref'], 'note' => $note], (int) $app['id']);
    }
}

/**
 * Rewrites an application's cached evaluation status, official score and
 * uniqueness rating from its saved evaluations.
 *
 * Official = the latest approved, eligible evaluation; when the template
 * asks for more than one approved evaluation, the mean of the saved results
 * of the approved evaluations in the latest round (same template version and
 * development stage, latest per evaluator) — and nothing at all until there
 * are enough of them.
 */
function inc_app_refresh_eval(int $appId): void
{
    $approved = inc_all("SELECT * FROM inc_evaluations WHERE application_id = ? AND status = 'approved' AND eligible = 1 AND score IS NOT NULL ORDER BY approved_at DESC, id DESC", 'i', [$appId]);
    $officialId = null;
    $score = null;
    $uniq = null;
    $coverage = null;
    if ($approved) {
        $latest = $approved[0];
        $rules = inc_template_rules(inc_template((int) $latest['template_id']));
        $round = [];
        foreach ($approved as $e) {
            $who = $e['evaluator_id'] ? 'p' . $e['evaluator_id'] : 'admin';
            if ((int) $e['template_id'] === (int) $latest['template_id'] && $e['dev_stage'] === $latest['dev_stage'] && !isset($round[$who])) {
                $round[$who] = $e;
            }
        }
        if (count($round) >= max(1, (int) $rules['min_approved'])) {
            $officialId = (int) $latest['id'];
            $score = array_sum(array_map(static fn($e) => (float) $e['score'], $round)) / count($round);
            $coverage = array_sum(array_map(static fn($e) => (float) $e['coverage'], $round)) / count($round);
            $u = array_filter(array_map(static fn($e) => $e['uniqueness_rating'], $round), static fn($v) => $v !== null);
            $uniq = $u ? array_sum(array_map('floatval', $u)) / count($u) : null;
        }
    }
    $latestAny = inc_one('SELECT status FROM inc_evaluations WHERE application_id = ? ORDER BY created_at DESC, id DESC LIMIT 1', 'i', [$appId]);
    if ($officialId) {
        $status = 'approved';
    } elseif (!$latestAny) {
        $status = 'none';
    } elseif (in_array($latestAny['status'], ['submitted', 'under_review'], true)) {
        $status = $latestAny['status'];
    } elseif ($latestAny['status'] === 'returned') {
        $status = 'returned';
    } else {
        $status = 'incomplete';   // draft, in progress, or approved without enough approvals yet
    }
    inc_exec('UPDATE inc_applications SET eval_status = ?, official_eval_id = ?, official_score = ?, uniqueness_rating = ?, official_coverage = ? WHERE id = ?',
              'sidddi', [$status, $officialId, $score === null ? null : round($score, 2), $uniq === null ? null : round($uniq, 3),
                         $coverage === null ? null : round($coverage, 2), $appId]);
}

/* ======================================================= recommendations */

/**
 * Creates stage-aware recommendations for the low-rated pillars of an
 * approved evaluation (once per pillar per evaluation), drawing on the
 * library text for the stage and the evaluator's own comments.
 */
function inc_recs_generate(int $evalId): int
{
    $ev = inc_eval($evalId);
    if (!$ev) {
        return 0;
    }
    $res = inc_eval_result($ev);
    $rules = inc_template_rules(inc_template((int) $ev['template_id']));
    $lib = inc_default_recommendations();
    $comments = [];
    foreach (inc_eval_questions($ev) as $q) {
        $r = inc_eval_responses($evalId)[(int) $q['id']] ?? null;
        if ($r && trim((string) $r['comment']) !== '' && ($r['status'] === 'insufficient' || ($r['status'] === 'rated' && (int) $r['rating'] <= 2))) {
            $comments[$q['pillar_code']][] = trim((string) $r['comment']);
        }
    }
    $u = inc_eval_uniqueness($evalId);
    $made = 0;
    foreach ($res['pillars'] ?? [] as $code => $p) {
        $low = $p['state'] === 'rated' && $p['rating'] !== null && $p['rating'] <= (float) $rules['low_threshold'];
        $missing = $p['state'] === 'unrated';
        if (!$low && !$missing) {
            continue;
        }
        $text = $lib[$code][$ev['dev_stage']] ?? ('Strengthen ' . $p['name'] . '.');
        if ($missing) {
            $text = 'Gather evidence for ' . $p['name'] . ': ' . $text;
        }
        if (!empty($comments[$code])) {
            $text .= ' Evaluator notes: ' . mb_substr(implode(' | ', array_slice($comments[$code], 0, 3)), 0, 800);
        }
        if ($code === 'uniqueness' && trim((string) ($u['improvements'] ?? '')) !== '') {
            $text .= ' Suggested improvements: ' . mb_substr(trim((string) $u['improvements']), 0, 800);
        }
        $made += inc_exec('INSERT IGNORE INTO inc_recommendations (application_id, evaluation_id, startup_id, pillar_code, dev_stage, text, source, created_by)
                            VALUES (?,?,?,?,?,?,"auto",?)', 'iiissss', [(int) $ev['application_id'], $evalId, $ev['startup_id'], $code, $ev['dev_stage'], $text, 'system']) > 0 ? 1 : 0;
    }
    return $made;
}

function inc_rec_save(?int $id, int $appId, array $in): int
{
    inc_need('evaluate');
    $text = inc_str($in['text'] ?? '', 3000, true, 'Recommendation');
    $owner = inc_int_or_null($in['owner_id'] ?? null);
    if ($owner && !inc_person_active(inc_person($owner))) {
        throw new InvalidArgumentException('The chosen owner is not an active team member.');
    }
    $due = inc_date($in['due_date'] ?? '', false, 'Due date');
    $status = (string) ($in['status'] ?? 'open');
    if (!in_array($status, ['open', 'in_progress', 'done', 'dropped'], true)) {
        $status = 'open';
    }
    $note = inc_str($in['progress_note'] ?? '', 500);
    $pillar = (string) ($in['pillar_code'] ?? '');
    if ($pillar !== '' && !isset(inc_default_pillars()[$pillar]) && !inc_one('SELECT id FROM inc_eval_pillars WHERE code = ? LIMIT 1', 's', [$pillar])) {
        $pillar = '';
    }
    if ($id) {
        $before = inc_one('SELECT * FROM inc_recommendations WHERE id = ? AND application_id = ?', 'ii', [$id, $appId]);
        if (!$before) {
            throw new InvalidArgumentException('That recommendation is not on this application.');
        }
        inc_exec('UPDATE inc_recommendations SET text = ?, owner_id = ?, due_date = ?, status = ?, progress_note = ? WHERE id = ?',
                  'sisssi', [$text, $owner, $due, $status, $note ?: null, $id]);
        inc_audit('update', 'recommendation', $id, mb_substr($text, 0, 120), $before, ['status' => $status, 'owner' => $owner, 'due' => $due]);
    } else {
        $app = inc_one('SELECT startup_id FROM inc_applications WHERE id = ?', 'i', [$appId]);
        if (!$app) {
            throw new InvalidArgumentException('That application no longer exists.');
        }
        $dev = $app['startup_id'] ? (inc_one('SELECT dev_stage FROM inc_startups WHERE id = ?', 'i', [(int) $app['startup_id']])['dev_stage'] ?? null) : null;
        $id = inc_exec('INSERT INTO inc_recommendations (application_id, startup_id, pillar_code, dev_stage, text, source, owner_id, due_date, status, progress_note, created_by)
                         VALUES (?,?,?,?,?,"manual",?,?,?,?,?)', 'iisssissss',
                        [$appId, $app['startup_id'], $pillar ?: null, $dev, $text, $owner, $due, $status, $note ?: null, inc_actor()]);
        inc_audit('create', 'recommendation', $id, mb_substr($text, 0, 120));
    }
    return $id;
}

function inc_recs_for_app(int $appId): array
{
    return inc_all('SELECT * FROM inc_recommendations WHERE application_id = ? ORDER BY FIELD(status, "open","in_progress","done","dropped"), due_date IS NULL, due_date, id', 'i', [$appId]);
}

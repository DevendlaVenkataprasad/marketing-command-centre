<?php
/**
 * Incubation Management — screening criteria, bulk actions and dashboard figures.
 *
 * Screening never invents a number: an application without an official
 * score, or without a uniqueness rating, does not "score zero" — it is
 * SKIPPED with the reason, and the run records what was done, skipped and
 * failed so the outcome can be checked afterwards.
 */

require_once __DIR__ . '/assign.php';

/** Criteria from a request (or a saved preset). Only what is set is applied. */
function inc_screen_criteria(array $q): array
{
    $c = [];
    foreach (['min_score' => [0, 100], 'min_uniq' => [0, 5], 'min_coverage' => [0, 100]] as $k => [$lo, $hi]) {
        $v = (string) ($q[$k] ?? '');
        if ($v !== '' && is_numeric($v) && $v >= $lo && $v <= $hi) {
            $c[$k] = (float) $v;
        }
    }
    $c['require_approved'] = !empty($q['require_approved']) ? 1 : 0;
    $c['exclude_duplicates'] = !empty($q['exclude_duplicates']) ? 1 : 0;
    $stages = array_values(array_intersect((array) ($q['stages'] ?? []), array_keys(inc_dev_stages())));
    if ($stages) {
        $c['stages'] = $stages;
    }
    $pillarMins = [];
    foreach ((array) ($q['pmin'] ?? []) as $code => $v) {
        $v = (string) $v;
        if ($v !== '' && is_numeric($v) && $v >= 1 && $v <= 5 && preg_match('/^[a-z_]{2,30}$/', (string) $code)) {
            $pillarMins[$code] = (float) $v;
        }
    }
    if ($pillarMins) {
        $c['pillar_min'] = $pillarMins;
    }
    return $c;
}

/**
 * Does an application meet the criteria? Returns null when it does, or the
 * reason it does not. Missing values are a reason, never a zero.
 */
function inc_screen_check(array $app, array $c): ?string
{
    if (!empty($c['exclude_duplicates']) && in_array($app['dup_status'], ['suspected', 'confirmed'], true)) {
        return 'flagged as a possible duplicate';
    }
    $needsOfficial = !empty($c['require_approved']) || isset($c['min_score']) || isset($c['min_uniq']) || isset($c['pillar_min']) || isset($c['min_coverage']);
    if ($needsOfficial && $app['official_score'] === null) {
        return $app['eval_status'] === 'none' ? 'not evaluated' : 'no approved official score (' . (inc_eval_status_labels()[$app['eval_status']] ?? $app['eval_status']) . ')';
    }
    if (!empty($c['stages'])) {
        $dev = $app['dev_stage'] ?? null;
        if (!$dev || !in_array($dev, $c['stages'], true)) {
            return 'development stage ' . ($dev ? (inc_dev_stages()[$dev] ?? $dev) : 'not set') . ' is not eligible';
        }
    }
    if (isset($c['min_score']) && (float) $app['official_score'] < $c['min_score']) {
        return 'score ' . inc_fmt_score($app['official_score']) . ' is below ' . $c['min_score'];
    }
    if (isset($c['min_coverage']) && $app['official_coverage'] !== null && (float) $app['official_coverage'] < $c['min_coverage']) {
        return 'coverage ' . number_format((float) $app['official_coverage'], 1) . '% is below ' . $c['min_coverage'] . '%';
    }
    if (isset($c['min_uniq'])) {
        if ($app['uniqueness_rating'] === null) {
            return 'no Uniqueness & Differentiation rating';
        }
        if ((float) $app['uniqueness_rating'] < $c['min_uniq']) {
            return 'uniqueness ' . inc_fmt_rating($app['uniqueness_rating']) . ' is below ' . $c['min_uniq'];
        }
    }
    if (!empty($c['pillar_min'])) {
        $ev = $app['official_eval_id'] ? inc_eval((int) $app['official_eval_id']) : null;
        $res = $ev ? inc_eval_result($ev) : [];
        foreach ($c['pillar_min'] as $code => $min) {
            $p = $res['pillars'][$code] ?? null;
            if (!$p || $p['state'] === 'na') {
                continue;   // not applicable at this stage: neither a pass nor a fail
            }
            if ($p['rating'] === null) {
                return $p['name'] . ' has no rating';
            }
            if ((float) $p['rating'] < $min) {
                return $p['name'] . ' ' . inc_fmt_rating($p['rating']) . ' is below ' . $min;
            }
        }
    }
    return null;
}

function inc_screen_presets(): array
{
    return inc_all('SELECT * FROM inc_screen_presets ORDER BY name');
}

function inc_screen_preset_save(string $name, array $criteria): int
{
    inc_need('configure');
    $name = inc_str($name, 120, true, 'Preset name');
    $id = inc_exec('INSERT INTO inc_screen_presets (name, criteria_json, created_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE criteria_json = VALUES(criteria_json)',
                    'sss', [$name, json_encode($criteria), inc_actor()]);
    inc_audit('save', 'screen_preset', $id ?: null, $name, null, $criteria);
    return $id;
}

function inc_screen_preset_delete(int $id): void
{
    inc_need('configure');
    inc_exec('DELETE FROM inc_screen_presets WHERE id = ?', 'i', [$id]);
}

/* ================================================================== bulk */

function inc_bulk_actions(): array
{
    return [
        'stage'     => 'Change pipeline stage',
        'owner'     => 'Set screening team and owner',
        'assign'    => 'Assign for incubation',
        'eval_task' => 'Create evaluation tasks',
        'shortlist' => 'Shortlist those meeting the criteria',
        'type'      => 'Set applicant type',
        'delete'    => 'Delete applications',
    ];
}

/**
 * Deletes one application and everything recorded against it: its answers,
 * Gem forms and scores, evaluations, tasks, notes, documents, department
 * hand-offs, certificates and history. One already in incubation is refused:
 * its workspace carries the startup's own record. The audit keeps a copy of
 * the application row and its answers.
 */
function inc_app_delete(int $id, string $reason = ''): string
{
    inc_need('configure');
    $app = inc_one('SELECT * FROM inc_applications WHERE id = ?', 'i', [$id]);
    if (!$app) {
        throw new InvalidArgumentException('That application no longer exists.');
    }
    if ($app['workspace_id'] || inc_one('SELECT id FROM inc_workspaces WHERE application_id = ? LIMIT 1', 'i', [$id])) {
        throw new InvalidArgumentException('it is already in incubation');
    }
    $values = inc_all('SELECT field_uid, label, value_text FROM inc_app_values WHERE application_id = ?', 'i', [$id]);
    $evalIds = array_map('intval', array_column(inc_all('SELECT id FROM inc_evaluations WHERE application_id = ?', 'i', [$id]), 'id'));
    $stored = array_column(inc_all('SELECT stored_name FROM inc_files WHERE entity = "application" AND entity_id = ?', 'i', [$id]), 'stored_name');
    if ($evalIds) {
        $stored = array_merge($stored, array_column(inc_all('SELECT stored_name FROM inc_files WHERE entity = "evaluation" AND entity_id IN (' . implode(',', $evalIds) . ')'), 'stored_name'));
    }
    inc_tx(static function () use ($id, $evalIds) {
        if ($evalIds) {
            inc_exec('DELETE FROM inc_files WHERE entity = "evaluation" AND entity_id IN (' . implode(',', $evalIds) . ')');
        }
        inc_exec('DELETE FROM inc_files WHERE entity = "application" AND entity_id = ?', 'i', [$id]);
        inc_exec('DELETE FROM inc_notes WHERE entity = "application" AND entity_id = ?', 'i', [$id]);
        inc_exec('DELETE FROM inc_tasks WHERE application_id = ? OR (entity = "application" AND entity_id = ?)', 'ii', [$id, $id]);
        inc_exec('DELETE FROM inc_stage_history WHERE pipeline = "crm" AND entity_id = ?', 'i', [$id]);
        foreach (['inc_recommendations', 'inc_evaluations', 'inc_app_values', 'inc_app_changes', 'inc_activities', 'inc_dept_assignments',
                  'inc_assignments', 'inc_notify_log', 'inc_gem_requests', 'inc_certificates'] as $tbl) {
            // Gems and certificates install their tables on first use.
            if (!fabo_db()->query("SHOW TABLES LIKE '" . inc_sql($tbl) . "'")->num_rows) {
                continue;
            }
            inc_exec('DELETE FROM ' . $tbl . ' WHERE application_id = ?', 'i', [$id]);
        }
        // Others flagged as its duplicate now point at nothing.
        inc_exec('UPDATE inc_applications SET dup_of_id = NULL WHERE dup_of_id = ?', 'i', [$id]);
        inc_exec('DELETE FROM inc_applications WHERE id = ?', 'i', [$id]);
    });
    foreach ($stored as $s) {
        if (function_exists('fabo_delete_stored')) {
            fabo_delete_stored((string) $s);
        }
    }
    inc_audit('delete', 'application', $id, 'Deleted ' . $app['ref'] . ' (' . ($app['startup_name'] ?: $app['applicant_name']) . ')',
              ['application' => $app, 'answers' => $values], null, $reason !== '' ? $reason : null);
    return (string) $app['ref'];
}

/**
 * Runs one bulk action over the chosen applications. Each record is handled
 * on its own: one that cannot be processed is skipped (with the reason) or
 * counted as failed, and the rest carry on. The run is recorded.
 */
function inc_bulk_run(string $action, array $ids, array $params): array
{
    inc_need('bulk');
    if (!isset(inc_bulk_actions()[$action])) {
        throw new InvalidArgumentException('Choose a bulk action.');
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        throw new InvalidArgumentException('Select at least one application.');
    }
    if (count($ids) > 5000) {
        throw new InvalidArgumentException('Bulk actions are limited to 5,000 applications at a time.');
    }
    $need = ['stage' => 'stage', 'owner' => 'assign', 'assign' => 'assign', 'eval_task' => 'edit', 'shortlist' => 'stage', 'type' => 'edit', 'delete' => 'configure'][$action];
    inc_need($need);
    $reason = inc_str($params['reason'] ?? '', 500);
    // Validate the parameters once, before touching any record.
    switch ($action) {
        case 'stage':
            $stage = inc_stage((int) ($params['stage_id'] ?? 0));
            if (!$stage || $stage['pipeline'] !== 'crm') {
                throw new InvalidArgumentException('Choose the pipeline stage to move to.');
            }
            break;
        case 'owner':
        case 'assign':
            $team = (int) ($params['team_id'] ?? 0);
            $owner = (int) ($params['owner_id'] ?? 0);
            if (!$team || !$owner) {
                throw new InvalidArgumentException('Choose a team and an owner.');
            }
            break;
        case 'eval_task':
            $title = inc_str($params['title'] ?? 'Evaluate application', 255, true, 'Task title');
            $assignee = inc_int_or_null($params['assignee_id'] ?? null);
            if ($assignee && !isset(inc_people_with_role('evaluator')[$assignee])) {
                throw new InvalidArgumentException('Evaluation tasks go to people with the evaluator role.');
            }
            $due = inc_date($params['due_date'] ?? '', false, 'Due date');
            break;
        case 'type':
            $typeId = inc_int_or_null($params['applicant_type_id'] ?? null);
            if (!$typeId || !isset(inc_masters('applicant_type', false)[$typeId])) {
                throw new InvalidArgumentException('Choose the applicant type.');
            }
            break;
        case 'delete':
            if (strtoupper(trim((string) ($params['delete_confirm'] ?? ''))) !== 'DELETE') {
                throw new InvalidArgumentException('Type DELETE to confirm deleting the selected applications.');
            }
            break;
        case 'shortlist':
            $criteria = (array) ($params['criteria'] ?? []);
            $stage = inc_stage_by_code('crm', 'shortlisted');
            if (!$stage) {
                throw new InvalidArgumentException('The pipeline has no active "Shortlisted" stage.');
            }
            break;
    }
    $done = [];
    $skipped = [];
    $failed = [];
    foreach ($ids as $id) {
        $app = inc_one('SELECT a.*, s.dev_stage FROM inc_applications a LEFT JOIN inc_startups s ON s.id = a.startup_id WHERE a.id = ?', 'i', [$id]);
        if (!$app) {
            $skipped[$id] = 'no longer exists';
            continue;
        }
        $ref = (string) $app['ref'];
        try {
            switch ($action) {
                case 'stage':
                    if ((int) $app['stage_id'] === (int) $stage['id']) {
                        $skipped[$ref] = 'already at ' . $stage['name'];
                        break;
                    }
                    inc_app_move_stage($id, (int) $stage['id'], $reason);
                    $done[] = $ref;
                    break;
                case 'owner':
                    inc_app_set_owner($id, $team, $owner);
                    $done[] = $ref;
                    break;
                case 'assign':
                    $r = inc_assign($id, $team, $owner, $reason, sha1('bulk' . $id . '|' . ($params['request_key'] ?? '')));
                    if ($r['result'] === 'unchanged' || $r['result'] === 'repeat') {
                        $skipped[$ref] = 'already assigned to that owner';
                    } else {
                        $done[] = $ref . ' (' . $r['result'] . ')';
                    }
                    break;
                case 'eval_task':
                    if ($app['eval_status'] === 'approved') {
                        $skipped[$ref] = 'already has an approved evaluation';
                        break;
                    }
                    if (inc_one('SELECT id FROM inc_tasks WHERE application_id = ? AND kind = "evaluation" AND status = "open" LIMIT 1', 'i', [$id])) {
                        $skipped[$ref] = 'an evaluation task is already open';
                        break;
                    }
                    inc_task_add('application', $id, ['title' => $title, 'kind' => 'evaluation', 'assignee_id' => $assignee, 'due_date' => $due]);
                    $done[] = $ref;
                    break;
                case 'type':
                    if ((int) ($app['applicant_type_id'] ?? 0) === $typeId) {
                        $skipped[$ref] = 'already ' . inc_master_name($typeId);
                        break;
                    }
                    inc_app_update($id, ['applicant_type_id' => $typeId], $reason);
                    $done[] = $ref;
                    break;
                case 'delete':
                    inc_app_delete($id, $reason);
                    $done[] = $ref;
                    break;
                case 'shortlist':
                    if ((int) $app['stage_id'] === (int) $stage['id']) {
                        $skipped[$ref] = 'already shortlisted';
                        break;
                    }
                    $why = inc_screen_check($app, $criteria);
                    if ($why !== null) {
                        $skipped[$ref] = $why;
                        break;
                    }
                    inc_app_move_stage($id, (int) $stage['id'], $reason !== '' ? $reason : 'Bulk shortlist: met the screening criteria');
                    $done[] = $ref;
                    break;
            }
        } catch (InvalidArgumentException $e) {
            $skipped[$ref] = $e->getMessage();
        } catch (Throwable $e) {
            error_log('FABO incubation bulk ' . $action . ' #' . $id . ': ' . $e->getMessage());
            $failed[$ref] = 'unexpected error';
        }
    }
    $runId = inc_exec('INSERT INTO inc_bulk_runs (action, params_json, total, done, skipped, failed, details_json, actor) VALUES (?,?,?,?,?,?,?,?)',
                       'ssiiiiss', [$action, json_encode(array_diff_key($params, ['request_key' => 1])), count($ids), count($done), count($skipped), count($failed),
                                    json_encode(['done' => $done, 'skipped' => $skipped, 'failed' => $failed], JSON_UNESCAPED_UNICODE), inc_actor()]);
    inc_audit('bulk', 'bulk_run', $runId, inc_bulk_actions()[$action] . ': ' . count($done) . ' done, ' . count($skipped) . ' skipped, ' . count($failed) . ' failed');
    return ['run_id' => $runId, 'done' => $done, 'skipped' => $skipped, 'failed' => $failed];
}

function inc_bulk_runs(int $limit = 20): array
{
    return inc_all('SELECT * FROM inc_bulk_runs ORDER BY created_at DESC, id DESC LIMIT ' . (int) $limit);
}

/* ============================================================ dashboard */

function inc_dashboard_figures(): array
{
    inc_need('view');
    $one = static fn(string $sql) => (int) (inc_one($sql)['n'] ?? 0);
    $group = static function (string $sql): array {
        $out = [];
        foreach (inc_all($sql) as $r) {
            $out[(string) $r['k']] = (int) $r['n'];
        }
        return $out;
    };
    $screenStage = array_filter([inc_stage_by_code('crm', 'new'), inc_stage_by_code('crm', 'screening')]);
    $screenIds = $screenStage ? implode(',', array_map(static fn($s) => (int) $s['id'], $screenStage)) : '0';
    $done = inc_stage_by_code('lifecycle', 'completed', false);
    $hold = inc_stage_by_code('lifecycle', 'on_hold', false);
    return [
        'total'        => $one('SELECT COUNT(*) AS n FROM inc_applications'),
        'by_form'      => $group('SELECT f.name AS k, COUNT(*) AS n FROM inc_applications a JOIN inc_forms f ON f.id = a.form_id GROUP BY f.id, f.name ORDER BY n DESC'),
        'by_event'     => $group('SELECT event_name AS k, COUNT(*) AS n FROM inc_applications WHERE event_name IS NOT NULL AND event_name <> "" GROUP BY event_name ORDER BY n DESC'),
        'by_purpose'   => $group('SELECT purpose AS k, COUNT(*) AS n FROM inc_applications GROUP BY purpose ORDER BY n DESC'),
        'by_source'    => $group('SELECT COALESCE(m.name, "Not recorded") AS k, COUNT(*) AS n FROM inc_applications a LEFT JOIN inc_masters m ON m.id = a.source_id GROUP BY k ORDER BY n DESC'),
        'by_type'      => inc_all('SELECT a.applicant_type_id AS id, COALESCE(m.name, "Not recorded") AS name, COUNT(*) AS n FROM inc_applications a
                                   LEFT JOIN inc_masters m ON m.id = a.applicant_type_id GROUP BY a.applicant_type_id, name ORDER BY n DESC'),
        'came_from'    => inc_all('SELECT source_id, purpose, COUNT(*) AS n FROM inc_applications GROUP BY source_id, purpose'),
        // Applicants' own submissions by day (staff entries and bulk loads would drown the trend).
        'online_daily' => inc_all('SELECT DATE(submitted_at) AS d, COUNT(*) AS n FROM inc_applications WHERE channel = "public" AND submitted_at >= CURDATE() - INTERVAL 100 DAY GROUP BY d'),
        'staff_entered' => $one('SELECT COUNT(*) AS n FROM inc_applications WHERE channel <> "public"'),
        'new_30'       => $one('SELECT COUNT(*) AS n FROM inc_applications WHERE submitted_at >= NOW() - INTERVAL 30 DAY'),
        'by_spoke'     => $group('SELECT COALESCE(m.name, "No spoke") AS k, COUNT(*) AS n FROM inc_applications a LEFT JOIN inc_masters m ON m.id = a.spoke_id GROUP BY k ORDER BY n DESC'),
        'by_stage'     => inc_all('SELECT st.id, st.name, COUNT(a.id) AS n FROM inc_stages st LEFT JOIN inc_applications a ON a.stage_id = st.id WHERE st.pipeline = "crm" GROUP BY st.id, st.name, st.sort ORDER BY st.sort'),
        'by_lifecycle' => inc_all('SELECT st.id, st.name, COUNT(w.id) AS n FROM inc_stages st LEFT JOIN inc_workspaces w ON w.stage_id = st.id WHERE st.pipeline = "lifecycle" GROUP BY st.id, st.name, st.sort ORDER BY st.sort'),
        'awaiting_screen' => $one('SELECT COUNT(*) AS n FROM inc_applications WHERE stage_id IN (' . $screenIds . ')'),
        'incomplete'   => $one('SELECT COUNT(*) AS n FROM inc_applications WHERE eval_status IN ("incomplete","returned")'),
        'in_review'    => $one('SELECT COUNT(*) AS n FROM inc_applications WHERE eval_status IN ("submitted","under_review")'),
        'approved'     => $one('SELECT COUNT(*) AS n FROM inc_applications WHERE official_score IS NOT NULL'),
        'avg_score'    => inc_one('SELECT AVG(official_score) AS v FROM inc_applications WHERE official_score IS NOT NULL')['v'],
        'score_bands'  => $group('SELECT CASE WHEN official_score >= 80 THEN "80–100" WHEN official_score >= 60 THEN "60–79" WHEN official_score >= 40 THEN "40–59" ELSE "Below 40" END AS k,
                                  COUNT(*) AS n FROM inc_applications WHERE official_score IS NOT NULL GROUP BY k'),
        'uniq_bands'   => $group('SELECT CASE WHEN uniqueness_rating >= 4.5 THEN "4.5–5" WHEN uniqueness_rating >= 3.5 THEN "3.5–4.4" WHEN uniqueness_rating >= 2.5 THEN "2.5–3.4" ELSE "Below 2.5" END AS k,
                                  COUNT(*) AS n FROM inc_applications WHERE uniqueness_rating IS NOT NULL GROUP BY k'),
        'shortlisted'  => $one('SELECT COUNT(*) AS n FROM inc_applications a JOIN inc_stages s ON s.id = a.stage_id AND s.code = "shortlisted"'),
        'awaiting_assignment' => $one('SELECT COUNT(*) AS n FROM inc_applications a JOIN inc_stages s ON s.id = a.stage_id AND s.code IN ("shortlisted","approved") WHERE a.workspace_id IS NULL'),
        'duplicates'   => $one('SELECT COUNT(*) AS n FROM inc_applications WHERE dup_status = "suspected"'),
        'active_inc'   => $one('SELECT COUNT(*) AS n FROM inc_workspaces WHERE closed_at IS NULL' . ($hold ? ' AND stage_id <> ' . (int) $hold['id'] : '')),
        'completed'    => $done ? $one('SELECT COUNT(*) AS n FROM inc_workspaces WHERE stage_id = ' . (int) $done['id']) : 0,
        'on_hold'      => $hold ? $one('SELECT COUNT(*) AS n FROM inc_workspaces WHERE stage_id = ' . (int) $hold['id']) : 0,
        'recent_assign' => inc_all('SELECT s.*, w.ref AS ws_ref, a.ref AS app_ref, a.startup_name, a.applicant_name FROM inc_assignments s
                                     JOIN inc_workspaces w ON w.id = s.workspace_id JOIN inc_applications a ON a.id = s.application_id ORDER BY s.created_at DESC, s.id DESC LIMIT 10'),
        'notify_failed' => $one('SELECT COUNT(*) AS n FROM inc_notify_log WHERE channel = "email" AND status IN ("failed","queued")'),
    ];
}

<?php
/**
 * Incubation Management — applications: listing and filtering, pipeline moves,
 * startups and their development stage, duplicates, edits, notes, tasks and
 * files.
 *
 * Three stage concepts are kept apart on purpose and nothing here moves one
 * because another moved:
 *   - the application's CRM pipeline stage (inc_applications.stage_id),
 *   - the startup's development stage (inc_startups.dev_stage),
 *   - the workspace's incubation lifecycle stage (inc_workspaces.stage_id).
 * The evaluation status is a fourth, derived from the evaluations themselves.
 */

require_once __DIR__ . '/core.php';
require_once __DIR__ . '/forms.php';
require_once __DIR__ . '/eval.php';

function inc_app(int $id): ?array
{
    return inc_one('SELECT a.*, s.dev_stage, s.name AS startup_profile_name, f.name AS form_name, st.name AS stage_name,
                            w.ref AS ws_ref, w.stage_id AS ws_stage_id, ls.name AS ws_stage_name
                     FROM inc_applications a
                     JOIN inc_forms f ON f.id = a.form_id
                     JOIN inc_stages st ON st.id = a.stage_id
                     LEFT JOIN inc_startups s ON s.id = a.startup_id
                     LEFT JOIN inc_workspaces w ON w.id = a.workspace_id
                     LEFT JOIN inc_stages ls ON ls.id = w.stage_id
                     WHERE a.id = ?' . (inc_kind() === 'eo' ? ' AND a.form_id IN (SELECT form_id FROM inc_ev_forms)' : ''), 'i', [$id]);
}

function inc_app_need(int $id): array
{
    inc_need('view');
    $a = inc_app($id);
    if (!$a) {
        throw new InvalidArgumentException('That application no longer exists.');
    }
    return $a;
}

/* ============================================================== filtering */

/** The filters the list understands, read from a request array. */
function inc_app_filters(array $q): array
{
    $f = [];
    foreach (['q' => 120, 'purpose' => 30, 'event' => 190, 'sector' => 190, 'dev_stage' => 20, 'eval_status' => 20, 'dup' => 12, 'cf_uid' => 40, 'cf_val' => 190] as $k => $max) {
        $v = trim((string) ($q[$k] ?? ''));
        if ($v !== '') {
            $f[$k] = mb_substr($v, 0, $max);
        }
    }
    foreach (['form_id', 'source_id', 'applicant_type_id', 'stage_id', 'spoke_id', 'sector_id', 'owner_id', 'team_id', 'ws_stage_id'] as $k) {
        $v = inc_int_or_null($q[$k] ?? null);
        if ($v) {
            $f[$k] = $v;
        }
    }
    foreach (['from', 'to'] as $k) {
        $v = (string) ($q[$k] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            $f[$k] = $v;
        }
    }
    foreach (['score_min', 'score_max'] as $k) {
        $v = (string) ($q[$k] ?? '');
        if ($v !== '' && is_numeric($v) && $v >= 0 && $v <= 100) {
            $f[$k] = (float) $v;
        }
    }
    foreach (['uniq_min', 'uniq_max'] as $k) {
        $v = (string) ($q[$k] ?? '');
        if ($v !== '' && is_numeric($v) && $v >= 0 && $v <= 5) {
            $f[$k] = (float) $v;
        }
    }
    if (!empty($q['ws']) && in_array($q['ws'], ['yes', 'no'], true)) {
        $f['ws'] = $q['ws'];
    }
    // Whose form it came in on: the incubation team's own, or a department's (inc_forms.dept_team_id).
    if (!empty($q['route']) && in_array($q['route'], ['inc', 'dept', 'all'], true)) {
        $f['route'] = $q['route'];
    }
    $v = inc_int_or_null($q['route_team'] ?? null);
    if ($v) {
        $f['route_team'] = $v;
    }
    return $f;
}

/**
 * The WHERE clause for a set of filters, on an application aliased a,
 * startup s and workspace w. Every value is bound, never interpolated.
 * A score or uniqueness filter matches only applications that HAVE an
 * official value: missing is never treated as zero.
 */
function inc_app_where(array $f): array
{
    $w = ['1=1'];
    if (inc_kind() === 'eo') {
        $w[] = 'a.form_id IN (SELECT form_id FROM inc_ev_forms)';
    }
    $t = '';
    $p = [];
    if (isset($f['q'])) {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $f['q']) . '%';
        $w[] = '(a.ref LIKE ? OR a.applicant_name LIKE ? OR a.applicant_email LIKE ? OR a.startup_name LIKE ? OR a.applicant_phone LIKE ?)';
        $t .= 'sssss';
        array_push($p, $like, $like, $like, $like, $like);
    }
    $eq = ['form_id' => 'a.form_id', 'source_id' => 'a.source_id', 'applicant_type_id' => 'a.applicant_type_id', 'stage_id' => 'a.stage_id', 'spoke_id' => 'a.spoke_id',
           'sector_id' => 'a.sector_id', 'owner_id' => 'a.owner_id', 'team_id' => 'a.team_id', 'ws_stage_id' => 'w.stage_id'];
    foreach ($eq as $k => $col) {
        if (isset($f[$k])) {
            $w[] = $col . ' = ?';
            $t .= 'i';
            $p[] = $f[$k];
        }
    }
    if (isset($f['purpose'])) {
        $w[] = 'a.purpose = ?';
        $t .= 's';
        $p[] = $f['purpose'];
    }
    if (isset($f['event'])) {
        $w[] = 'a.event_name = ?';
        $t .= 's';
        $p[] = $f['event'];
    }
    // A sector by name: chosen from the Sectors list, or answered on the form's own Sector question.
    if (isset($f['sector'])) {
        $w[] = '(EXISTS (SELECT 1 FROM inc_masters sm WHERE sm.id = a.sector_id AND sm.kind = "sector" AND sm.name = ?)
                 OR EXISTS (SELECT 1 FROM inc_app_values sv WHERE sv.application_id = a.id AND sv.label = "Sector" AND sv.value_short = ?))';
        $t .= 'ss';
        array_push($p, $f['sector'], $f['sector']);
    }
    if (isset($f['dev_stage'])) {
        $w[] = 's.dev_stage = ?';
        $t .= 's';
        $p[] = $f['dev_stage'];
    }
    if (isset($f['eval_status'])) {
        $w[] = 'a.eval_status = ?';
        $t .= 's';
        $p[] = $f['eval_status'];
    }
    if (isset($f['from'])) {
        $w[] = 'a.submitted_at >= ?';
        $t .= 's';
        $p[] = $f['from'] . ' 00:00:00';
    }
    if (isset($f['to'])) {
        $w[] = 'a.submitted_at <= ?';
        $t .= 's';
        $p[] = $f['to'] . ' 23:59:59';
    }
    foreach (['score_min' => ['a.official_score', '>='], 'score_max' => ['a.official_score', '<='],
              'uniq_min' => ['a.uniqueness_rating', '>='], 'uniq_max' => ['a.uniqueness_rating', '<=']] as $k => [$col, $op]) {
        if (isset($f[$k])) {
            $w[] = $col . ' IS NOT NULL AND ' . $col . ' ' . $op . ' ?';
            $t .= 'd';
            $p[] = $f[$k];
        }
    }
    if (isset($f['dup'])) {
        $w[] = 'a.dup_status = ?';
        $t .= 's';
        $p[] = $f['dup'];
    }
    if (isset($f['ws'])) {
        $w[] = $f['ws'] === 'yes' ? 'a.workspace_id IS NOT NULL' : 'a.workspace_id IS NULL';
    }
    /* Incubation applications: those on the incubation team's own forms (they
       stay theirs even when handed to a department). Department applications:
       those on a department's form, and those handed to a department. */
    if (isset($f['route']) && $f['route'] === 'inc') {
        $w[] = 'NOT EXISTS (SELECT 1 FROM inc_forms rf WHERE rf.id = a.form_id AND rf.dept_team_id IS NOT NULL)';
    } elseif (isset($f['route']) && $f['route'] === 'dept') {
        $w[] = '(EXISTS (SELECT 1 FROM inc_forms rf WHERE rf.id = a.form_id AND rf.dept_team_id IS NOT NULL)
                 OR EXISTS (SELECT 1 FROM inc_dept_assignments rd WHERE rd.application_id = a.id))';
    }
    // One department: handed to it (open or returned), or on its form.
    if (isset($f['route_team'])) {
        $w[] = '(EXISTS (SELECT 1 FROM inc_dept_assignments rd2 WHERE rd2.application_id = a.id AND rd2.team_id = ?)
                 OR EXISTS (SELECT 1 FROM inc_forms rf2 WHERE rf2.id = a.form_id AND rf2.dept_team_id = ?))';
        $t .= 'ii';
        array_push($p, $f['route_team'], $f['route_team']);
    }
    if (isset($f['cf_uid'], $f['cf_val'])) {
        $w[] = 'EXISTS (SELECT 1 FROM inc_app_values v WHERE v.application_id = a.id AND v.field_uid = ? AND v.value_short LIKE ?)';
        $t .= 'ss';
        array_push($p, $f['cf_uid'], '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $f['cf_val']) . '%');
    }
    // Within a given set of ids (the cards ticked for a bulk action).
    if (isset($f['_ids'])) {
        $ids = array_values(array_filter(array_map('intval', (array) $f['_ids'])));
        $w[] = $ids ? 'a.id IN (' . implode(',', $ids) . ')' : '0';
    }
    // Scope: the module is the administrator's; a person-scoped view (the
    // team dashboard) narrows to that person's teams and ownership.
    if (isset($f['_person'])) {
        $teams = inc_person_team_ids((int) $f['_person']);
        $w[] = '(a.owner_id = ? OR w.owner_id = ?' . ($teams ? ' OR w.team_id IN (' . implode(',', array_map('intval', $teams)) . ')' : '') . ')';
        $t .= 'ii';
        array_push($p, (int) $f['_person'], (int) $f['_person']);
    }
    return [implode(' AND ', $w), $t, $p];
}

function inc_app_sorts(): array
{
    return [
        'newest' => 'a.submitted_at DESC, a.id DESC',
        'oldest' => 'a.submitted_at ASC, a.id ASC',
        'score'  => 'a.official_score IS NULL, a.official_score DESC, a.id DESC',
        'uniq'   => 'a.uniqueness_rating IS NULL, a.uniqueness_rating DESC, a.id DESC',
        'name'   => 'a.startup_name = "", a.startup_name, a.applicant_name',
        'stage'  => 'st.sort, a.submitted_at DESC',
        'gem'    => '(SELECT MAX(gr.score) FROM inc_gem_requests gr WHERE gr.application_id = a.id) IS NULL, (SELECT MAX(gr.score) FROM inc_gem_requests gr WHERE gr.application_id = a.id) DESC, a.submitted_at DESC',
    ];
}

/** One page of applications. Returns [rows, total]. */
function inc_app_list(array $f, string $sort = 'newest', int $page = 1, int $per = 25): array
{
    inc_need('view');
    [$where, $t, $p] = inc_app_where($f);
    $from = ' FROM inc_applications a JOIN inc_stages st ON st.id = a.stage_id JOIN inc_forms fm ON fm.id = a.form_id
              LEFT JOIN inc_startups s ON s.id = a.startup_id LEFT JOIN inc_workspaces w ON w.id = a.workspace_id
              LEFT JOIN inc_stages ls ON ls.id = w.stage_id WHERE ' . $where;
    $total = (int) (inc_one('SELECT COUNT(*) AS n' . $from, $t, $p)['n'] ?? 0);
    $order = inc_app_sorts()[$sort] ?? inc_app_sorts()['newest'];
    $per = max(5, min(200, $per));
    $page = max(1, $page);
    $rows = inc_all('SELECT a.*, st.name AS stage_name, fm.name AS form_name, s.dev_stage, w.ref AS ws_ref, w.owner_id AS ws_owner_id,
                             w.team_id AS ws_team_id, ls.name AS ws_stage_name' . $from . ' ORDER BY ' . $order
                     . ' LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per), $t, $p);
    return [$rows, $total];
}

/** Every id matching the filters, up to $cap — for "select all matching" bulk actions. */
function inc_app_ids_matching(array $f, int $cap = 5000): array
{
    inc_need('view');
    [$where, $t, $p] = inc_app_where($f);
    $rows = inc_all('SELECT a.id FROM inc_applications a JOIN inc_stages st ON st.id = a.stage_id LEFT JOIN inc_startups s ON s.id = a.startup_id
                      LEFT JOIN inc_workspaces w ON w.id = a.workspace_id WHERE ' . $where . ' ORDER BY a.id LIMIT ' . (int) $cap, $t, $p);
    return array_map(static fn($r) => (int) $r['id'], $rows);
}

/* ============================================================== pipeline */

/** Facts a stage requirement is checked against. */
function inc_app_facts(array $app, string $reason = ''): array
{
    return ['eval_approved' => $app['official_score'] !== null, 'workspace' => !empty($app['workspace_id']), 'reason' => $reason];
}

/**
 * Moves an application to a pipeline stage on the system's behalf (a Gem
 * sent, a department hand-off): no permission or transition check, but the
 * history and activity are written as for any move.
 */
function inc_app_system_move(int $appId, int $stageId, string $reason): void
{
    $to = inc_stage($stageId);
    $app = inc_one('SELECT id, stage_id FROM inc_applications WHERE id = ?', 'i', [$appId]);
    if (!$to || $to['pipeline'] !== 'crm' || !$app || (int) $app['stage_id'] === $stageId) {
        return;
    }
    $from = inc_stage((int) $app['stage_id']);
    inc_exec('UPDATE inc_applications SET stage_id = ? WHERE id = ?', 'ii', [$stageId, $appId]);
    inc_exec('INSERT INTO inc_stage_history (pipeline, entity_id, from_stage_id, to_stage_id, actor, reason) VALUES ("crm",?,?,?,?,?)',
              'iiiss', [$appId, (int) $app['stage_id'], $stageId, inc_actor(), mb_substr($reason, 0, 500)]);
    inc_activity($appId, null, 'stage', 'Pipeline stage: ' . ($from['name'] ?? '?') . ' → ' . $to['name'] . ' (' . mb_substr($reason, 0, 200) . ').');
    // A stage that sends a certificate sends it now.
    require_once __DIR__ . '/certs.php';
    inc_cert_on_stage($appId, $stageId);
}

/** Moves an application to another CRM stage. Only the CRM stage changes. */
function inc_app_move_stage(int $appId, int $stageId, string $reason = '', bool $notify = true): void
{
    inc_need('stage');
    $res = inc_tx(static function () use ($appId, $stageId, $reason) {
        $app = inc_one('SELECT * FROM inc_applications WHERE id = ? FOR UPDATE', 'i', [$appId]);
        if (!$app) {
            throw new InvalidArgumentException('That application no longer exists.');
        }
        $to = inc_stage($stageId);
        if (!$to || $to['pipeline'] !== 'crm') {
            throw new InvalidArgumentException('Choose a stage of the application pipeline.');
        }
        $from = inc_stage((int) $app['stage_id']);
        $problem = inc_transition_problem($from, $to, inc_app_facts($app, $reason));
        if ($problem) {
            throw new InvalidArgumentException($problem);
        }
        inc_exec('UPDATE inc_applications SET stage_id = ? WHERE id = ?', 'ii', [$stageId, $appId]);
        inc_exec('INSERT INTO inc_stage_history (pipeline, entity_id, from_stage_id, to_stage_id, actor, reason) VALUES ("crm",?,?,?,?,?)',
                  'iiiss', [$appId, (int) $app['stage_id'], $stageId, inc_actor(), $reason !== '' ? mb_substr($reason, 0, 500) : null]);
        inc_activity($appId, null, 'stage', 'Pipeline stage: ' . $from['name'] . ' → ' . $to['name'] . ($reason !== '' ? ' (' . mb_substr($reason, 0, 200) . ')' : '') . '.');
        inc_audit('stage', 'application', $appId, $app['ref'] . ': ' . $from['name'] . ' → ' . $to['name'], ['stage' => $from['name']], ['stage' => $to['name']], $reason);
        return ['app' => $app, 'from' => $from, 'to' => $to];
    });
    // A stage that sends a certificate sends it now, once the move is saved.
    require_once __DIR__ . '/certs.php';
    inc_cert_on_stage($appId, $stageId);
    // "Notify the owner on entry" — only when the application has an owner, never the administrator by default.
    $ownerId = (int) ($res['app']['owner_id'] ?? 0);
    if ($notify && (int) $res['to']['notify'] === 1 && $ownerId > 0) {
        $app = $res['app'];
        inc_notify('stage_changed', 'cs' . $appId . ':' . $stageId . ':' . time(), $ownerId, [
            'startup' => $app['startup_name'] ?: $app['applicant_name'], 'workspace' => $app['ref'], 'ref' => $app['ref'],
            'from' => $res['from']['name'], 'stage' => $res['to']['name'], 'date' => inc_fmt_datetime(date('Y-m-d H:i:s')),
        ], $appId);
    }
}

function inc_stage_history(string $pipeline, int $entityId): array
{
    return inc_all('SELECT h.*, f.name AS from_name, COALESCE(t.name, "(deleted stage)") AS to_name FROM inc_stage_history h LEFT JOIN inc_stages f ON f.id = h.from_stage_id
                     LEFT JOIN inc_stages t ON t.id = h.to_stage_id WHERE h.pipeline = ? AND h.entity_id = ? ORDER BY h.created_at, h.id', 'si', [$pipeline, $entityId]);
}

/* =============================================================== startups */

function inc_startup(int $id): ?array
{
    return inc_one('SELECT * FROM inc_startups WHERE id = ?', 'i', [$id]);
}

/**
 * Links an application to a startup explicitly. $startupId 0 creates a new
 * startup from the application's own details (for when the suggested match
 * turned out to be a different company).
 */
function inc_app_link_startup(int $appId, int $startupId, string $reason = ''): int
{
    inc_need('edit');
    return inc_tx(static function () use ($appId, $startupId, $reason) {
        $app = inc_one('SELECT * FROM inc_applications WHERE id = ? FOR UPDATE', 'i', [$appId]);
        if (!$app) {
            throw new InvalidArgumentException('That application no longer exists.');
        }
        if ($app['workspace_id'] && $app['startup_id'] && (int) $app['startup_id'] !== $startupId) {
            throw new InvalidArgumentException('This application is already in incubation; its startup cannot be changed.');
        }
        if ($startupId === 0) {
            $name = trim((string) $app['startup_name']) !== '' ? (string) $app['startup_name'] : (string) $app['applicant_name'];
            if ($name === '') {
                throw new InvalidArgumentException('The application has no startup name to create a startup from.');
            }
            $dev = inc_one('SELECT v.value_text FROM inc_app_values v JOIN inc_form_fields f ON f.version_id = ? AND f.uid = v.field_uid AND f.maps_to = "dev_stage"
                             WHERE v.application_id = ?', 'ii', [(int) $app['form_version_id'], $appId]);
            $devStage = $dev && isset(inc_dev_stages()[$dev['value_text']]) ? (string) $dev['value_text'] : null;
            $startupId = inc_exec('INSERT INTO inc_startups (name, name_norm, email, phone, spoke_id, sector_id, dev_stage, dev_stage_at, created_by) VALUES (?,?,?,?,?,?,?,' . ($devStage ? 'NOW()' : 'NULL') . ',?)',
                                   'ssssiiss', [$name, inc_norm_name($name), $app['applicant_email'], $app['applicant_phone'], $app['spoke_id'], $app['sector_id'], $devStage, inc_actor()]);
            if ($devStage) {
                inc_exec('INSERT INTO inc_dev_history (startup_id, from_stage, to_stage, actor, reason) VALUES (?,NULL,?,?,?)', 'isss', [$startupId, $devStage, inc_actor(), 'Stated on application']);
            }
        } elseif (!inc_startup($startupId)) {
            throw new InvalidArgumentException('That startup does not exist.');
        }
        inc_exec('UPDATE inc_applications SET startup_id = ?, startup_suggest_id = NULL WHERE id = ?', 'ii', [$startupId, $appId]);
        // Evaluations follow the application's startup.
        inc_exec('UPDATE inc_evaluations SET startup_id = ? WHERE application_id = ? AND status <> "approved"', 'ii', [$startupId, $appId]);
        $name = (string) inc_startup($startupId)['name'];
        inc_activity($appId, $app['workspace_id'] ? (int) $app['workspace_id'] : null, 'startup', 'Linked to startup "' . $name . '" (#' . $startupId . ').');
        inc_audit('link_startup', 'application', $appId, $app['ref'] . ' → startup #' . $startupId, ['startup_id' => $app['startup_id']], ['startup_id' => $startupId], $reason);
        return $startupId;
    });
}

function inc_startup_search(string $q, int $limit = 20): array
{
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    return inc_all('SELECT id, name, email, dev_stage FROM inc_startups WHERE name LIKE ? OR email LIKE ? ORDER BY name LIMIT ' . (int) $limit, 'ss', [$like, $like]);
}

/**
 * Changes a startup's development stage, keeping the previous stage, the
 * new one, who, when and why. Nothing else moves: not the CRM stage, not the
 * lifecycle stage, and not any evaluation already started (it stays on the
 * stage it was opened at).
 */
function inc_startup_set_stage(int $startupId, string $stage, string $reason = ''): void
{
    inc_need('edit');
    if (!isset(inc_dev_stages(true)[$stage])) {
        throw new InvalidArgumentException('Choose a development stage.');
    }
    inc_tx(static function () use ($startupId, $stage, $reason) {
        $s = inc_one('SELECT * FROM inc_startups WHERE id = ? FOR UPDATE', 'i', [$startupId]);
        if (!$s) {
            throw new InvalidArgumentException('That startup no longer exists.');
        }
        if ($s['dev_stage'] === $stage) {
            throw new InvalidArgumentException('The startup is already at the ' . inc_dev_stages()[$stage] . '.');
        }
        inc_exec('UPDATE inc_startups SET dev_stage = ?, dev_stage_at = NOW() WHERE id = ?', 'si', [$stage, $startupId]);
        inc_exec('INSERT INTO inc_dev_history (startup_id, from_stage, to_stage, actor, reason) VALUES (?,?,?,?,?)',
                  'issss', [$startupId, $s['dev_stage'], $stage, inc_actor(), $reason !== '' ? mb_substr($reason, 0, 500) : null]);
        foreach (inc_all('SELECT id, workspace_id FROM inc_applications WHERE startup_id = ?', 'i', [$startupId]) as $a) {
            inc_activity((int) $a['id'], $a['workspace_id'] ? (int) $a['workspace_id'] : null, 'dev_stage',
                         'Development stage: ' . ($s['dev_stage'] ? inc_dev_stages()[$s['dev_stage']] ?? $s['dev_stage'] : 'not set') . ' → ' . inc_dev_stages()[$stage] . ($reason !== '' ? ' (' . mb_substr($reason, 0, 200) . ')' : '') . '.');
        }
        inc_audit('dev_stage', 'startup', $startupId, $s['name'], ['dev_stage' => $s['dev_stage']], ['dev_stage' => $stage], $reason);
    });
}

function inc_dev_history(int $startupId): array
{
    return inc_all('SELECT * FROM inc_dev_history WHERE startup_id = ? ORDER BY created_at, id', 'i', [$startupId]);
}

/* ============================================================= duplicates */

function inc_app_dup_resolve(int $appId, string $decision, string $reason): void
{
    inc_need('edit');
    if (!in_array($decision, ['cleared', 'confirmed'], true)) {
        throw new InvalidArgumentException('Choose whether it is a duplicate.');
    }
    $reason = inc_str($reason, 500, $decision === 'confirmed', 'A reason');
    $app = inc_app_need($appId);
    if ($app['dup_status'] !== 'suspected') {
        throw new InvalidArgumentException('This application is not flagged as a possible duplicate.');
    }
    if ($decision === 'confirmed' && $app['workspace_id']) {
        throw new InvalidArgumentException('An application that is in incubation cannot be marked a duplicate.');
    }
    inc_exec('UPDATE inc_applications SET dup_status = ? WHERE id = ?', 'si', [$decision, $appId]);
    inc_activity($appId, null, 'duplicate', $decision === 'confirmed' ? 'Confirmed as a duplicate. ' . $reason : 'Duplicate flag cleared. ' . $reason);
    inc_audit('duplicate', 'application', $appId, $app['ref'] . ': ' . $decision, ['dup_status' => 'suspected'], ['dup_status' => $decision], $reason);
}

/* ================================================================== edits */

/** Fields staff may correct on an application, with their labels. */
function inc_app_editable(): array
{
    return ['applicant_name' => 'Applicant name', 'applicant_email' => 'Applicant email', 'applicant_phone' => 'Applicant phone',
            'startup_name' => 'Startup name', 'spoke_id' => 'Spoke', 'sector_id' => 'Sector', 'source_id' => 'Source',
            'applicant_type_id' => 'Applicant type'];
}

function inc_app_update(int $appId, array $in, string $reason = ''): int
{
    inc_need('edit');
    $app = inc_app_need($appId);
    $new = [
        'applicant_name'  => inc_str($in['applicant_name'] ?? $app['applicant_name'], 190, false, 'Applicant name'),
        'applicant_email' => mb_strtolower(inc_str($in['applicant_email'] ?? $app['applicant_email'], 190)),
        'applicant_phone' => inc_str($in['applicant_phone'] ?? $app['applicant_phone'], 40),
        'startup_name'    => inc_str($in['startup_name'] ?? $app['startup_name'], 190),
        'spoke_id'        => inc_int_or_null($in['spoke_id'] ?? $app['spoke_id']),
        'sector_id'       => inc_int_or_null($in['sector_id'] ?? $app['sector_id']),
        'source_id'       => inc_int_or_null($in['source_id'] ?? $app['source_id']),
        'applicant_type_id' => inc_int_or_null($in['applicant_type_id'] ?? ($app['applicant_type_id'] ?? null)),
    ];
    if ($new['applicant_name'] === '' && $new['startup_name'] === '') {
        throw new InvalidArgumentException('An application needs an applicant name or a startup name.');
    }
    if ($new['applicant_email'] !== '' && !filter_var($new['applicant_email'], FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Enter a valid email address.');
    }
    foreach (['spoke_id' => 'spoke', 'sector_id' => 'sector', 'source_id' => 'source', 'applicant_type_id' => 'applicant_type'] as $k => $kind) {
        if ($new[$k] && !isset(inc_masters($kind, false)[$new[$k]])) {
            throw new InvalidArgumentException('Choose a ' . str_replace('_', ' ', $kind) . ' from the list.');
        }
    }
    if ($app['workspace_id'] && $new['spoke_id'] !== ($app['spoke_id'] ? (int) $app['spoke_id'] : null)) {
        $ws = inc_one('SELECT team_id FROM inc_workspaces WHERE id = ?', 'i', [(int) $app['workspace_id']]);
        $spokes = inc_team_spokes((int) $ws['team_id']);
        if ($spokes && (!$new['spoke_id'] || !in_array($new['spoke_id'], $spokes, true))) {
            throw new InvalidArgumentException('The assigned team does not cover that spoke. Reassign the startup first.');
        }
    }
    $changed = 0;
    inc_tx(static function () use ($app, $appId, $new, $reason, &$changed) {
        foreach ($new as $k => $v) {
            $old = $app[$k];
            $oldCmp = $old === null || $old === '' ? null : (string) $old;
            $newCmp = $v === null || $v === '' ? null : (string) $v;
            if ($oldCmp === $newCmp) {
                continue;
            }
            $changed++;
            $show = static fn($x) => in_array($k, ['spoke_id', 'sector_id', 'source_id', 'applicant_type_id'], true) ? inc_master_name($x ? (int) $x : null) : (string) $x;
            inc_exec('INSERT INTO inc_app_changes (application_id, field, old_value, new_value, actor, reason) VALUES (?,?,?,?,?,?)',
                      'isssss', [$appId, inc_app_editable()[$k], $show($old), $show($v), inc_actor(), $reason ?: null]);
        }
        if ($changed) {
            inc_exec('UPDATE inc_applications SET applicant_name = ?, applicant_email = ?, applicant_phone = ?, startup_name = ?, spoke_id = ?, sector_id = ?, source_id = ?, applicant_type_id = ? WHERE id = ?',
                      'ssssiiiii', [$new['applicant_name'], $new['applicant_email'] ?: null, $new['applicant_phone'] ?: null, $new['startup_name'],
                                   $new['spoke_id'], $new['sector_id'], $new['source_id'], $new['applicant_type_id'], $appId]);
            inc_activity($appId, $app['workspace_id'] ? (int) $app['workspace_id'] : null, 'edit', $changed . ' detail' . ($changed === 1 ? '' : 's') . ' corrected.');
            inc_audit('update', 'application', $appId, $app['ref'], array_intersect_key($app, $new), $new, $reason);
        }
    });
    return $changed;
}

function inc_app_changes(int $appId): array
{
    return inc_all('SELECT * FROM inc_app_changes WHERE application_id = ? ORDER BY created_at DESC, id DESC', 'i', [$appId]);
}

/**
 * Sets who in Incubation Management looks after an application while it is
 * being screened (before, or apart from, incubation assignment).
 */
function inc_app_set_owner(int $appId, ?int $teamId, ?int $ownerId): void
{
    inc_need('assign');
    $app = inc_app_need($appId);
    if ($app['workspace_id']) {
        throw new InvalidArgumentException('This application is in incubation; change its owner by reassigning it from its incubation record.');
    }
    if ($ownerId && !$teamId) {
        throw new InvalidArgumentException('Choose the team the owner works in.');
    }
    if ($teamId) {
        $team = inc_team($teamId);
        if (!$team || (int) $team['active'] !== 1) {
            throw new InvalidArgumentException('Choose an active incubation team.');
        }
        if ($ownerId) {
            $problem = inc_assignment_problem($teamId, $ownerId, $app['spoke_id'] ? (int) $app['spoke_id'] : null);
            if ($problem) {
                throw new InvalidArgumentException($problem);
            }
        }
    }
    if ((int) $app['team_id'] === (int) $teamId && (int) $app['owner_id'] === (int) $ownerId) {
        return;
    }
    inc_exec('UPDATE inc_applications SET team_id = ?, owner_id = ? WHERE id = ?', 'iii', [$teamId, $ownerId, $appId]);
    inc_activity($appId, null, 'owner', 'Screening owner: ' . ($ownerId ? inc_person_name($ownerId) : 'none') . ($teamId ? ' (' . inc_team_name($teamId) . ')' : '') . '.');
    inc_audit('owner', 'application', $appId, $app['ref'], ['team' => $app['team_id'], 'owner' => $app['owner_id']], ['team' => $teamId, 'owner' => $ownerId]);
    if ($teamId) {
        // The department chosen here gets it on its desk; the pipeline stage is left as it is.
        require_once __DIR__ . '/depts.php';
        inc_dept_sync((int) $teamId);
    }
}

/* ============================================== notes, tasks, files, timeline */

function inc_entity_check(string $entity, int $id): void
{
    $table = ['application' => 'inc_applications', 'workspace' => 'inc_workspaces'][$entity] ?? null;
    if (!$table || !inc_one('SELECT id FROM ' . $table . ' WHERE id = ?', 'i', [$id])) {
        throw new InvalidArgumentException('That record no longer exists.');
    }
}

function inc_note_add(string $entity, int $id, string $body, string $kind = 'note'): int
{
    inc_need('edit');
    inc_entity_check($entity, $id);
    $body = inc_str($body, 10000, true, 'The note');
    if (!in_array($kind, ['note', 'comment', 'call', 'email'], true)) {
        $kind = 'note';
    }
    $nid = inc_exec('INSERT INTO inc_notes (entity, entity_id, kind, body, actor) VALUES (?,?,?,?,?)', 'sisss', [$entity, $id, $kind, $body, inc_actor()]);
    [$appId, $wsId] = inc_entity_pair($entity, $id);
    inc_activity($appId, $wsId, $kind, ucfirst($kind === 'note' ? 'internal note' : $kind) . ' added: ' . mb_substr($body, 0, 160));
    return $nid;
}

/** The application id and workspace id behind an entity. */
function inc_entity_pair(string $entity, int $id): array
{
    if ($entity === 'workspace') {
        $w = inc_one('SELECT application_id FROM inc_workspaces WHERE id = ?', 'i', [$id]);
        return [$w ? (int) $w['application_id'] : null, $id];
    }
    $a = inc_one('SELECT workspace_id FROM inc_applications WHERE id = ?', 'i', [$id]);
    return [$id, $a && $a['workspace_id'] ? (int) $a['workspace_id'] : null];
}

function inc_notes(string $entity, int $id): array
{
    return inc_all('SELECT * FROM inc_notes WHERE entity = ? AND entity_id = ? ORDER BY created_at DESC, id DESC', 'si', [$entity, $id]);
}

function inc_task_add(string $entity, int $id, array $in): int
{
    inc_need('edit');
    inc_entity_check($entity, $id);
    $title = inc_str($in['title'] ?? '', 255, true, 'Task');
    $kind = (string) ($in['kind'] ?? 'task');
    if (!in_array($kind, ['task', 'follow_up', 'evaluation', 'reminder'], true)) {
        $kind = 'task';
    }
    $assignee = inc_int_or_null($in['assignee_id'] ?? null);
    if ($assignee && !inc_person_active(inc_person($assignee))) {
        throw new InvalidArgumentException('Tasks can only be given to active team members.');
    }
    $due = inc_date($in['due_date'] ?? '', $kind === 'follow_up' || $kind === 'reminder', 'Due date');
    [$appId, $wsId] = inc_entity_pair($entity, $id);
    $tid = inc_exec('INSERT INTO inc_tasks (entity, entity_id, application_id, kind, title, detail, assignee_id, due_date, created_by) VALUES (?,?,?,?,?,?,?,?,?)',
                     'siisssiss', [$entity, $id, $appId, $kind, $title, inc_str($in['detail'] ?? '', 5000) ?: null, $assignee, $due, inc_actor()]);
    inc_activity($appId, $wsId, 'task', str_replace('_', '-', ucfirst($kind)) . ' created: ' . $title . ($assignee ? ' → ' . inc_person_name($assignee) : '') . ($due ? ', due ' . inc_fmt_date($due) : '') . '.');
    if ($assignee) {
        $app = $appId ? inc_one('SELECT ref, startup_name, applicant_name FROM inc_applications WHERE id = ?', 'i', [$appId]) : null;
        inc_notify('task', 'task' . $tid, $assignee, ['task' => $title, 'due' => $due ? inc_fmt_date($due) : 'no date',
                   'startup' => $app ? ($app['startup_name'] ?: $app['applicant_name']) : '', 'ref' => $app['ref'] ?? ''], $appId, $wsId);
    }
    return $tid;
}

function inc_task_set(int $taskId, string $status): void
{
    inc_need('edit');
    if (!in_array($status, ['open', 'done', 'cancelled'], true)) {
        throw new InvalidArgumentException('Unknown task status.');
    }
    $t = inc_one('SELECT * FROM inc_tasks WHERE id = ?', 'i', [$taskId]);
    if (!$t) {
        throw new InvalidArgumentException('That task no longer exists.');
    }
    inc_exec('UPDATE inc_tasks SET status = ?, done_at = ' . ($status === 'open' ? 'NULL' : 'NOW()') . ', done_by = ? WHERE id = ?',
              'ssi', [$status, $status === 'open' ? null : inc_actor(), $taskId]);
    [$appId, $wsId] = inc_entity_pair($t['entity'], (int) $t['entity_id']);
    inc_activity($appId, $wsId, 'task', 'Task "' . $t['title'] . '" ' . ['open' => 'reopened', 'done' => 'completed', 'cancelled' => 'cancelled'][$status] . '.');
}

/**
 * Reminds assignees of open tasks and follow-ups due tomorrow. Run by
 * cron.php; each task is reminded once per due date (inc_notify de-duplicates),
 * so running it more than once a day is harmless. Returns the number reminded.
 */
function inc_reminders_run(?string $today = null): int
{
    $day = date('Y-m-d', strtotime(($today ?? date('Y-m-d')) . ' +1 day'));
    $n = 0;
    foreach (inc_all('SELECT t.*, a.ref, a.startup_name, a.applicant_name FROM inc_tasks t LEFT JOIN inc_applications a ON a.id = t.application_id
                       WHERE t.status = "open" AND t.assignee_id IS NOT NULL AND t.due_date = ?', 's', [$day]) as $t) {
        [$appId, $wsId] = inc_entity_pair($t['entity'], (int) $t['entity_id']);
        $r = inc_notify('reminder', 'task' . $t['id'] . ':' . $t['due_date'], (int) $t['assignee_id'], [
            'task' => $t['title'], 'due' => 'tomorrow (' . inc_fmt_date($t['due_date']) . ')',
            'startup' => (string) ($t['startup_name'] ?: $t['applicant_name']), 'ref' => (string) $t['ref'],
        ], $appId, $wsId);
        $n += in_array('sent', $r, true) || in_array('queued', $r, true) ? 1 : 0;
    }
    return $n;
}

function inc_tasks(string $entity, int $id): array
{
    return inc_all('SELECT * FROM inc_tasks WHERE entity = ? AND entity_id = ? ORDER BY status = "open" DESC, due_date IS NULL, due_date, id', 'si', [$entity, $id]);
}

function inc_file_add(string $entity, int $id, array $upload, string $label = ''): int
{
    inc_need('edit');
    inc_entity_check($entity, $id);
    require_once __DIR__ . '/../uploads.php';
    [$ok, $msg, $m] = fabo_store_upload($upload);
    if (!$ok) {
        throw new InvalidArgumentException($msg);
    }
    $fid = inc_exec('INSERT INTO inc_files (entity, entity_id, label, stored_name, original_name, mime, size, uploaded_by) VALUES (?,?,?,?,?,?,?,?)',
                     'sissssis', [$entity, $id, inc_str($label, 190) ?: null, $m['stored_name'], $m['original_name'], $m['mime'], $m['size'], inc_actor()]);
    [$appId, $wsId] = inc_entity_pair($entity, $id);
    inc_activity($appId, $wsId, 'document', 'Document added: ' . $m['original_name'] . '.');
    return $fid;
}

function inc_files(string $entity, int $id): array
{
    return inc_all('SELECT * FROM inc_files WHERE entity = ? AND entity_id = ? ORDER BY created_at DESC, id DESC', 'si', [$entity, $id]);
}

/** A stored file, if the viewer may have it. Every incubation file is the module's own. */
function inc_file_for_download(int $fileId): ?array
{
    if (!inc_can('view')) {
        /* A department member: only the application's own documents, and
           only for an application handed to one of their departments. */
        inc_need('desk');
        require_once __DIR__ . '/depts.php';
        $f = inc_one('SELECT * FROM inc_files WHERE id = ? AND entity = "application"', 'i', [$fileId]);
        return $f && inc_dept_sees_app((int) $f['entity_id']) ? $f : null;
    }
    return inc_one('SELECT * FROM inc_files WHERE id = ?', 'i', [$fileId]);
}

function inc_timeline(?int $appId, ?int $wsId, int $limit = 200): array
{
    if ($appId && $wsId) {
        return inc_all('SELECT * FROM inc_activities WHERE application_id = ? OR workspace_id = ? ORDER BY created_at DESC, id DESC LIMIT ' . (int) $limit, 'ii', [$appId, $wsId]);
    }
    if ($wsId) {
        return inc_all('SELECT * FROM inc_activities WHERE workspace_id = ? ORDER BY created_at DESC, id DESC LIMIT ' . (int) $limit, 'i', [$wsId]);
    }
    return inc_all('SELECT * FROM inc_activities WHERE application_id = ? ORDER BY created_at DESC, id DESC LIMIT ' . (int) $limit, 'i', [(int) $appId]);
}

/**
 * The departments each application is with, as tags for the board and the
 * list: [application id => [team name, ...]]. An application keeps its place
 * in the main pipeline; these say which department desks also have it (on the
 * department's form, or handed to it and not returned).
 */
function inc_dept_tags_for_rows(array $rows): array
{
    $ids = array_values(array_unique(array_filter(array_map(static fn($r) => (int) ($r['id'] ?? 0), $rows))));
    if (!$ids) {
        return [];
    }
    $in = implode(',', $ids);
    $teams = inc_teams(false);
    $out = [];
    foreach (inc_all('SELECT application_id, team_id FROM inc_dept_assignments WHERE state = "open" AND application_id IN (' . $in . ')') as $r) {
        $out[(int) $r['application_id']][(int) $r['team_id']] = $teams[(int) $r['team_id']] ?? '';
    }
    foreach (inc_all('SELECT a.id, f.dept_team_id FROM inc_applications a JOIN inc_forms f ON f.id = a.form_id
                       WHERE f.dept_team_id IS NOT NULL AND a.id IN (' . $in . ')') as $r) {
        $out[(int) $r['id']][(int) $r['dept_team_id']] = $teams[(int) $r['dept_team_id']] ?? '';
    }
    return array_map(static fn($t) => array_values(array_filter($t, static fn($n) => $n !== '')), $out);
}

/** The department tags, as HTML. */
function inc_dept_tags_html(array $names): string
{
    $h = '';
    foreach ($names as $n) {
        $h .= '<span class="kb-dept" title="Also on this department\'s desk">' . e($n) . '</span>';
    }
    return $h;
}

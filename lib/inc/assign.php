<?php
/**
 * Incubation Management — assignment, reassignment and incubation workspaces.
 *
 * FIRST ASSIGNMENT creates exactly one workspace for the application, at the
 * lifecycle stage "Assigned Application", owned by the chosen team member.
 * REASSIGNMENT finds that workspace (by the application, or by the startup's
 * open workspace) and changes only its team and owner: the lifecycle stage,
 * tasks, notes, documents, meetings and evaluations stay exactly as they
 * were, and the change is recorded as a "Reassigned Application" event.
 *
 * Safety:
 *   - the application row is locked (SELECT ... FOR UPDATE) for the whole
 *     decision, so two clicks cannot both create a workspace;
 *   - inc_workspaces has UNIQUE(application_id) and UNIQUE(open_startup), so
 *     the database itself refuses a second workspace;
 *   - each request carries a key; a repeat of the same request finds its
 *     assignment row (UNIQUE event_key) and changes nothing, notifies nobody;
 *   - notifications are sent only after the transaction commits, and a
 *     failed email never undoes the assignment (inc_notify_retry() resends).
 */

require_once __DIR__ . '/apps.php';

function inc_workspace(int $id): ?array
{
    return inc_one('SELECT w.*, a.ref AS app_ref, a.startup_name, a.applicant_name, a.applicant_email, a.spoke_id, a.sector_id, a.official_score,
                            a.uniqueness_rating, a.eval_status, a.stage_id AS app_stage_id, st.name AS stage_name, st.is_terminal, s.dev_stage, s.name AS startup_profile
                     FROM inc_workspaces w JOIN inc_applications a ON a.id = w.application_id JOIN inc_stages st ON st.id = w.stage_id
                     LEFT JOIN inc_startups s ON s.id = w.startup_id WHERE w.id = ?', 'i', [$id]);
}

function inc_workspace_need(int $id): array
{
    inc_need('view');
    $w = inc_workspace($id);
    if (!$w) {
        throw new InvalidArgumentException('That incubation record no longer exists.');
    }
    return $w;
}

/**
 * Assigns an application for incubation, or reassigns its workspace.
 *
 * $requestKey identifies the request (the form prints a fresh one each time
 * it is drawn); a repeat with the same key is recognised and does nothing.
 *
 * Returns ['result' => created|reassigned|unchanged|repeat, 'workspace_id', 'assignment_id', 'notified' => [...]].
 */
function inc_assign(int $appId, int $teamId, int $ownerId, string $reason = '', string $requestKey = ''): array
{
    inc_need('assign');
    $reason = inc_str($reason, 500);
    $requestKey = preg_match('/^[A-Za-z0-9]{8,64}$/', $requestKey) ? $requestKey : bin2hex(random_bytes(12));
    $eventKey = sha1('assign|' . $appId . '|' . $teamId . '|' . $ownerId . '|' . $requestKey);

    $attempt = static function () use ($appId, $teamId, $ownerId, $reason, $eventKey) {
        return inc_tx(static function () use ($appId, $teamId, $ownerId, $reason, $eventKey) {
            $app = inc_one('SELECT * FROM inc_applications WHERE id = ? FOR UPDATE', 'i', [$appId]);
            if (!$app) {
                throw new InvalidArgumentException('That application no longer exists.');
            }
            $seen = inc_one('SELECT * FROM inc_assignments WHERE event_key = ?', 's', [$eventKey]);
            if ($seen) {
                return ['result' => 'repeat', 'workspace_id' => (int) $seen['workspace_id'], 'assignment_id' => (int) $seen['id'], 'app' => $app];
            }
            if ($app['dup_status'] === 'confirmed') {
                throw new InvalidArgumentException('This application is a confirmed duplicate and cannot be assigned.');
            }
            if (!$app['startup_id']) {
                throw new InvalidArgumentException('Link the application to a startup before assigning it, so the incubation record belongs to the right startup.');
            }
            $problem = inc_assignment_problem($teamId, $ownerId, $app['spoke_id'] ? (int) $app['spoke_id'] : null);
            if ($problem) {
                throw new InvalidArgumentException($problem);
            }
            // The canonical workspace: this application's, or the startup's open one.
            $ws = null;
            if ($app['workspace_id']) {
                $ws = inc_one('SELECT * FROM inc_workspaces WHERE id = ? FOR UPDATE', 'i', [(int) $app['workspace_id']]);
            }
            if (!$ws) {
                $ws = inc_one('SELECT * FROM inc_workspaces WHERE open_startup = ? FOR UPDATE', 'i', [(int) $app['startup_id']]);
                if ($ws) {
                    inc_exec('UPDATE inc_applications SET workspace_id = ? WHERE id = ?', 'ii', [(int) $ws['id'], $appId]);
                    inc_activity($appId, (int) $ws['id'], 'workspace', 'Linked to the startup\'s existing incubation record ' . $ws['ref'] . '.');
                }
            }

            if (!$ws) {
                $stage = inc_stage_by_code('lifecycle', 'assigned') ?: inc_first_stage('lifecycle');
                $wsId = inc_exec('INSERT INTO inc_workspaces (application_id, startup_id, open_startup, team_id, owner_id, stage_id, created_by) VALUES (?,?,?,?,?,?,?)',
                                  'iiiiiis', [$appId, (int) $app['startup_id'], (int) $app['startup_id'], $teamId, $ownerId, (int) $stage['id'], inc_actor()]);
                $ref = 'WS-' . str_pad((string) $wsId, 5, '0', STR_PAD_LEFT);
                inc_exec('UPDATE inc_workspaces SET ref = ? WHERE id = ?', 'si', [$ref, $wsId]);
                inc_exec('INSERT INTO inc_stage_history (pipeline, entity_id, from_stage_id, to_stage_id, actor, reason) VALUES ("lifecycle",?,NULL,?,?,?)',
                          'iiss', [$wsId, (int) $stage['id'], inc_actor(), 'Incubation record created on assignment']);
                $aid = inc_exec('INSERT INTO inc_assignments (workspace_id, application_id, kind, team_id, owner_id, stage_id_at, reason, event_key, actor) VALUES (?,?,"assign",?,?,?,?,?,?)',
                                 'iiiiisss', [$wsId, $appId, $teamId, $ownerId, (int) $stage['id'], $reason ?: null, $eventKey, inc_actor()]);
                inc_exec('UPDATE inc_applications SET workspace_id = ?, team_id = ?, owner_id = ? WHERE id = ?', 'iiii', [$wsId, $teamId, $ownerId, $appId]);
                inc_activity($appId, $wsId, 'assigned', 'Assigned for incubation to ' . inc_person_name($ownerId) . ' (' . inc_team_name($teamId) . '). Incubation record ' . $ref . ' created at "' . $stage['name'] . '".');
                inc_audit('assign', 'workspace', $wsId, $app['ref'] . ' → ' . inc_person_name($ownerId), null, ['team' => $teamId, 'owner' => $ownerId], $reason);
                // The CRM pipeline records the assignment too, when its stage allows it.
                if (inc_setting('move_crm_on_assign') === '1') {
                    $to = inc_stage_by_code('crm', 'assigned');
                    $from = inc_stage((int) $app['stage_id']);
                    if ($to && (int) $to['id'] !== (int) $app['stage_id']) {
                        $why = inc_transition_problem($from, $to, ['eval_approved' => $app['official_score'] !== null, 'workspace' => true, 'reason' => 'Assigned for incubation']);
                        if ($why === null) {
                            inc_exec('UPDATE inc_applications SET stage_id = ? WHERE id = ?', 'ii', [(int) $to['id'], $appId]);
                            inc_exec('INSERT INTO inc_stage_history (pipeline, entity_id, from_stage_id, to_stage_id, actor, reason) VALUES ("crm",?,?,?,?,?)',
                                      'iiiss', [$appId, (int) $app['stage_id'], (int) $to['id'], inc_actor(), 'Assigned for incubation']);
                            inc_activity($appId, $wsId, 'stage', 'Pipeline stage: ' . $from['name'] . ' → ' . $to['name'] . ' (assigned for incubation).');
                            require_once __DIR__ . '/certs.php';
                            inc_cert_on_stage($appId, (int) $to['id']);
                        } else {
                            inc_activity($appId, $wsId, 'stage', 'Pipeline stage left at "' . $from['name'] . '": ' . $why);
                        }
                    }
                }
                return ['result' => 'created', 'workspace_id' => $wsId, 'assignment_id' => $aid, 'app' => $app, 'prev_owner' => null, 'prev_team' => null];
            }

            $wsId = (int) $ws['id'];
            if ((int) $ws['team_id'] === $teamId && (int) $ws['owner_id'] === $ownerId) {
                return ['result' => 'unchanged', 'workspace_id' => $wsId, 'assignment_id' => null, 'app' => $app];
            }
            $aid = inc_exec('INSERT INTO inc_assignments (workspace_id, application_id, kind, team_id, owner_id, prev_team_id, prev_owner_id, stage_id_at, reason, event_key, actor)
                              VALUES (?,?,"reassign",?,?,?,?,?,?,?,?)', 'iiiiiiisss',
                             [$wsId, $appId, $teamId, $ownerId, (int) $ws['team_id'], (int) $ws['owner_id'], (int) $ws['stage_id'], $reason ?: null, $eventKey, inc_actor()]);
            inc_exec('UPDATE inc_workspaces SET team_id = ?, owner_id = ?, last_reassigned_at = NOW() WHERE id = ?', 'iii', [$teamId, $ownerId, $wsId]);
            inc_exec('UPDATE inc_applications SET team_id = ?, owner_id = ? WHERE workspace_id = ?', 'iii', [$teamId, $ownerId, $wsId]);
            $stageName = (string) (inc_stage((int) $ws['stage_id'])['name'] ?? '');
            inc_activity($appId, $wsId, 'reassigned', 'Reassigned Application: ' . inc_person_name((int) $ws['owner_id']) . ' (' . inc_team_name((int) $ws['team_id']) . ') → '
                         . inc_person_name($ownerId) . ' (' . inc_team_name($teamId) . '). Incubation stage stays "' . $stageName . '".' . ($reason !== '' ? ' Reason: ' . $reason : ''));
            inc_audit('reassign', 'workspace', $wsId, $app['ref'], ['team' => (int) $ws['team_id'], 'owner' => (int) $ws['owner_id']], ['team' => $teamId, 'owner' => $ownerId], $reason);
            return ['result' => 'reassigned', 'workspace_id' => $wsId, 'assignment_id' => $aid, 'app' => $app,
                    'prev_owner' => (int) $ws['owner_id'], 'prev_team' => (int) $ws['team_id']];
        });
    };

    try {
        $out = $attempt();
    } catch (mysqli_sql_exception $e) {
        // Another request created the startup's workspace a moment ago: the
        // second attempt finds it and reassigns instead of creating.
        if ((int) $e->getCode() !== 1062) {
            throw $e;
        }
        $out = $attempt();
    }

    $out['notified'] = [];
    if (in_array($out['result'], ['created', 'reassigned'], true)) {
        $out['notified'] = inc_assignment_notify((int) $out['assignment_id']);
        // The department chosen here gets it on its desk; the pipeline stage is left as it is.
        require_once __DIR__ . '/depts.php';
        inc_dept_sync($teamId);
    }
    unset($out['app']);
    return $out;
}

/** Sends the notices for one assignment event. Safe to call again: inc_notify() de-duplicates. */
function inc_assignment_notify(int $assignmentId): array
{
    $as = inc_one('SELECT * FROM inc_assignments WHERE id = ?', 'i', [$assignmentId]);
    if (!$as) {
        return [];
    }
    $ws = inc_workspace((int) $as['workspace_id']);
    $vars = [
        'startup' => $ws['startup_name'] ?: $ws['applicant_name'], 'ref' => $ws['app_ref'], 'workspace' => $ws['ref'],
        'date' => inc_fmt_datetime($as['created_at']), 'team' => inc_team_name((int) $as['team_id']), 'owner' => inc_person_name((int) $as['owner_id']),
        'stage' => $ws['stage_name'], 'history' => '',
    ];
    $out = [];
    if ($as['kind'] === 'assign') {
        $out['owner'] = inc_notify('assigned', 'as' . $assignmentId, (int) $as['owner_id'], $vars, (int) $as['application_id'], (int) $as['workspace_id']);
    } else {
        $vars['history'] = 'Previously with ' . inc_person_name((int) $as['prev_owner_id']) . ' (' . inc_team_name((int) $as['prev_team_id']) . ').'
                         . ($as['reason'] ? "\nReason: " . $as['reason'] : '');
        $out['owner'] = inc_notify('reassigned', 'as' . $assignmentId, (int) $as['owner_id'], $vars, (int) $as['application_id'], (int) $as['workspace_id']);
        if (inc_setting('notify_prev_owner') === '1' && $as['prev_owner_id'] && (int) $as['prev_owner_id'] !== (int) $as['owner_id']) {
            $out['previous'] = inc_notify('reassigned_from', 'as' . $assignmentId, (int) $as['prev_owner_id'], $vars, (int) $as['application_id'], (int) $as['workspace_id']);
        }
    }
    return $out;
}

function inc_assignments_for(int $wsId): array
{
    return inc_all('SELECT * FROM inc_assignments WHERE workspace_id = ? ORDER BY created_at, id', 'i', [$wsId]);
}

/* ============================================================== lifecycle */

/**
 * Moves a workspace along the incubation lifecycle. Only the lifecycle stage
 * changes. A terminal stage closes the workspace (freeing the startup to be
 * incubated again later); leaving it reopens the workspace.
 */
function inc_ws_move_stage(int $wsId, int $stageId, string $reason = ''): void
{
    inc_need('stage');
    $res = inc_tx(static function () use ($wsId, $stageId, $reason) {
        $ws = inc_one('SELECT * FROM inc_workspaces WHERE id = ? FOR UPDATE', 'i', [$wsId]);
        if (!$ws) {
            throw new InvalidArgumentException('That incubation record no longer exists.');
        }
        $to = inc_stage($stageId);
        if (!$to || $to['pipeline'] !== 'lifecycle') {
            throw new InvalidArgumentException('Choose a stage of the incubation lifecycle.');
        }
        $from = inc_stage((int) $ws['stage_id']);
        $problem = inc_transition_problem($from, $to, ['workspace' => true, 'eval_approved' => true, 'reason' => $reason]);
        if ($problem) {
            throw new InvalidArgumentException($problem);
        }
        $closing = (int) $to['is_terminal'] === 1;
        if (!$closing && $ws['closed_at'] !== null && $ws['startup_id']) {
            $other = inc_one('SELECT ref FROM inc_workspaces WHERE open_startup = ? AND id <> ?', 'ii', [(int) $ws['startup_id'], $wsId]);
            if ($other) {
                throw new InvalidArgumentException('The startup already has another open incubation (' . $other['ref'] . '). Close it before reopening this one.');
            }
        }
        inc_exec('UPDATE inc_workspaces SET stage_id = ?, stage_since = NOW(), closed_at = ' . ($closing ? 'COALESCE(closed_at, NOW())' : 'NULL')
                . ', open_startup = ' . ($closing ? 'NULL' : 'startup_id') . ' WHERE id = ?', 'ii', [$stageId, $wsId]);
        inc_exec('INSERT INTO inc_stage_history (pipeline, entity_id, from_stage_id, to_stage_id, actor, reason) VALUES ("lifecycle",?,?,?,?,?)',
                  'iiiss', [$wsId, (int) $ws['stage_id'], $stageId, inc_actor(), $reason !== '' ? mb_substr($reason, 0, 500) : null]);
        inc_activity((int) $ws['application_id'], $wsId, 'lifecycle', 'Incubation stage: ' . $from['name'] . ' → ' . $to['name'] . ($reason !== '' ? ' (' . mb_substr($reason, 0, 200) . ')' : '') . '.');
        inc_audit('lifecycle', 'workspace', $wsId, $ws['ref'] . ': ' . $from['name'] . ' → ' . $to['name'], ['stage' => $from['name']], ['stage' => $to['name']], $reason);
        return ['ws' => $ws, 'from' => $from, 'to' => $to, 'hist' => (int) fabo_db()->insert_id];
    });
    if ((int) $res['to']['notify'] === 1) {
        $w = inc_workspace($wsId);
        inc_notify('stage_changed', 'ls' . $wsId . ':' . $stageId . ':' . time(), (int) $w['owner_id'], [
            'startup' => $w['startup_name'] ?: $w['applicant_name'], 'workspace' => $w['ref'], 'ref' => $w['app_ref'],
            'from' => $res['from']['name'], 'stage' => $res['to']['name'], 'date' => inc_fmt_datetime(date('Y-m-d H:i:s')),
        ], (int) $w['application_id'], $wsId);
    }
}

/** The lifecycle stage a workspace was at before its current one (for "return to previous stage"). */
function inc_ws_previous_stage(int $wsId): ?array
{
    $h = inc_one('SELECT from_stage_id FROM inc_stage_history WHERE pipeline = "lifecycle" AND entity_id = ? AND from_stage_id IS NOT NULL ORDER BY created_at DESC, id DESC LIMIT 1', 'i', [$wsId]);
    return $h ? inc_stage((int) $h['from_stage_id']) : null;
}

function inc_ws_set_target(int $wsId, ?string $date): void
{
    inc_need('edit');
    $d = inc_date($date, false, 'Target date');
    $ws = inc_workspace_need($wsId);
    inc_exec('UPDATE inc_workspaces SET target_date = ? WHERE id = ?', 'si', [$d, $wsId]);
    inc_activity((int) $ws['application_id'], $wsId, 'target', 'Target date ' . ($d ? 'set to ' . inc_fmt_date($d) : 'cleared') . '.');
}

function inc_meeting_add(int $wsId, array $in): int
{
    inc_need('edit');
    $ws = inc_workspace_need($wsId);
    $title = inc_str($in['title'] ?? '', 190, true, 'Meeting title');
    $date = inc_date($in['met_on'] ?? '', true, 'Meeting date');
    $id = inc_exec('INSERT INTO inc_meetings (workspace_id, title, met_on, attendees, notes, next_steps, actor) VALUES (?,?,?,?,?,?,?)',
                    'issssss', [$wsId, $title, $date, inc_str($in['attendees'] ?? '', 500) ?: null, inc_str($in['notes'] ?? '', 10000) ?: null,
                                inc_str($in['next_steps'] ?? '', 5000) ?: null, inc_actor()]);
    inc_activity((int) $ws['application_id'], $wsId, 'meeting', 'Meeting recorded: ' . $title . ' (' . inc_fmt_date($date) . ').');
    return $id;
}

function inc_meetings(int $wsId): array
{
    return inc_all('SELECT * FROM inc_meetings WHERE workspace_id = ? ORDER BY met_on DESC, id DESC', 'i', [$wsId]);
}

function inc_checklist(int $stageId): array
{
    return inc_all('SELECT * FROM inc_checklist_items WHERE stage_id = ? AND active = 1 ORDER BY sort, id', 'i', [$stageId]);
}

function inc_ws_checks(int $wsId): array
{
    $out = [];
    foreach (inc_all('SELECT * FROM inc_workspace_checks WHERE workspace_id = ?', 'i', [$wsId]) as $r) {
        $out[(int) $r['item_id']] = $r;
    }
    return $out;
}

function inc_ws_check(int $wsId, int $itemId, bool $done): void
{
    inc_need('edit');
    $ws = inc_workspace_need($wsId);
    $item = inc_one('SELECT * FROM inc_checklist_items WHERE id = ?', 'i', [$itemId]);
    if (!$item) {
        throw new InvalidArgumentException('That checklist item no longer exists.');
    }
    if ($done) {
        inc_exec('INSERT IGNORE INTO inc_workspace_checks (workspace_id, item_id, done_by) VALUES (?,?,?)', 'iis', [$wsId, $itemId, inc_actor()]);
    } else {
        inc_exec('DELETE FROM inc_workspace_checks WHERE workspace_id = ? AND item_id = ?', 'ii', [$wsId, $itemId]);
    }
    inc_activity((int) $ws['application_id'], $wsId, 'checklist', ($done ? 'Ticked: ' : 'Unticked: ') . $item['text'] . '.');
}

function inc_checklist_item_save(int $stageId, ?int $itemId, string $text, bool $active = true): void
{
    inc_need('configure');
    $s = inc_stage($stageId);
    if (!$s || $s['pipeline'] !== 'lifecycle') {
        throw new InvalidArgumentException('Checklists belong to lifecycle stages.');
    }
    $text = inc_str($text, 255, true, 'Checklist item');
    if ($itemId) {
        inc_exec('UPDATE inc_checklist_items SET text = ?, active = ? WHERE id = ? AND stage_id = ?', 'siii', [$text, $active ? 1 : 0, $itemId, $stageId]);
    } else {
        $sort = (int) (inc_one('SELECT COALESCE(MAX(sort),0)+1 AS n FROM inc_checklist_items WHERE stage_id = ?', 'i', [$stageId])['n'] ?? 1);
        inc_exec('INSERT INTO inc_checklist_items (stage_id, text, sort) VALUES (?,?,?)', 'isi', [$stageId, $text, $sort]);
    }
    inc_audit('checklist', 'stage', $stageId, $text);
}

/** Progress across checklists (all stages so far), tasks and recommendations. */
function inc_ws_progress(int $wsId): array
{
    $w = inc_workspace($wsId);
    $checksDone = (int) inc_one('SELECT COUNT(*) AS n FROM inc_workspace_checks c JOIN inc_checklist_items i ON i.id = c.item_id AND i.active = 1 WHERE c.workspace_id = ?', 'i', [$wsId])['n'];
    $checksTotal = (int) inc_one('SELECT COUNT(*) AS n FROM inc_checklist_items i JOIN inc_stages s ON s.id = i.stage_id WHERE i.active = 1 AND s.active = 1
                                   AND s.sort <= (SELECT sort FROM inc_stages WHERE id = ?)', 'i', [(int) $w['stage_id']])['n'];
    $t = inc_one('SELECT SUM(status = "done") AS d, SUM(status <> "cancelled") AS n FROM inc_tasks WHERE (entity = "workspace" AND entity_id = ?) OR application_id = ?',
                  'ii', [$wsId, (int) $w['application_id']]);
    $r = inc_one('SELECT SUM(status = "done") AS d, SUM(status <> "dropped") AS n FROM inc_recommendations WHERE application_id = ?', 'i', [(int) $w['application_id']]);
    $done = $checksDone + (int) $t['d'] + (int) $r['d'];
    $total = $checksTotal + (int) $t['n'] + (int) $r['n'];
    return ['checks' => [$checksDone, $checksTotal], 'tasks' => [(int) $t['d'], (int) $t['n']], 'recs' => [(int) $r['d'], (int) $r['n']],
            'pct' => $total ? (int) round($done * 100 / $total) : 0];
}

function inc_workspaces_list(array $f = []): array
{
    inc_need('view');
    $w = ['1=1'];
    $t = '';
    $p = [];
    foreach (['owner_id' => 'w.owner_id', 'team_id' => 'w.team_id', 'stage_id' => 'w.stage_id'] as $k => $col) {
        if (!empty($f[$k])) {
            $w[] = $col . ' = ?';
            $t .= 'i';
            $p[] = (int) $f[$k];
        }
    }
    if (($f['open'] ?? '') === 'open') {
        $w[] = 'w.closed_at IS NULL';
    } elseif (($f['open'] ?? '') === 'closed') {
        $w[] = 'w.closed_at IS NOT NULL';
    }
    if (!empty($f['q'])) {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $f['q']) . '%';
        $w[] = '(w.ref LIKE ? OR a.ref LIKE ? OR a.startup_name LIKE ? OR a.applicant_name LIKE ?)';
        $t .= 'ssss';
        array_push($p, $like, $like, $like, $like);
    }
    return inc_all('SELECT w.*, a.ref AS app_ref, a.startup_name, a.applicant_name, a.official_score, a.uniqueness_rating, a.eval_status, st.name AS stage_name, s.dev_stage
                     FROM inc_workspaces w JOIN inc_applications a ON a.id = w.application_id JOIN inc_stages st ON st.id = w.stage_id
                     LEFT JOIN inc_startups s ON s.id = w.startup_id WHERE ' . implode(' AND ', $w) . ' ORDER BY w.closed_at IS NOT NULL, st.sort, w.updated_at DESC LIMIT 500', $t, $p);
}

/* ========================================================= team dashboard */

/** What one team member is looking after — only their own and their teams' records. */
function inc_person_dashboard(int $personId): array
{
    inc_need('view');
    $teams = inc_person_team_ids($personId);
    $teamSql = $teams ? ' OR w.team_id IN (' . implode(',', array_map('intval', $teams)) . ')' : '';
    $ws = inc_all('SELECT w.*, a.ref AS app_ref, a.startup_name, a.applicant_name, st.name AS stage_name, s.dev_stage
                    FROM inc_workspaces w JOIN inc_applications a ON a.id = w.application_id JOIN inc_stages st ON st.id = w.stage_id
                    LEFT JOIN inc_startups s ON s.id = w.startup_id WHERE (w.owner_id = ?' . $teamSql . ') ORDER BY w.closed_at IS NOT NULL, st.sort, w.updated_at DESC', 'i', [$personId]);
    $wsIds = array_map(static fn($r) => (int) $r['id'], $ws);
    $in = $wsIds ? implode(',', $wsIds) : '0';
    return [
        'workspaces' => $ws,
        'new'        => inc_all('SELECT s.*, w.ref AS ws_ref, a.ref AS app_ref, a.startup_name, a.applicant_name FROM inc_assignments s JOIN inc_workspaces w ON w.id = s.workspace_id
                                  JOIN inc_applications a ON a.id = s.application_id WHERE s.owner_id = ? AND s.kind = "assign" AND s.created_at >= NOW() - INTERVAL 14 DAY ORDER BY s.created_at DESC', 'i', [$personId]),
        'reassigned' => inc_all('SELECT s.*, w.ref AS ws_ref, a.ref AS app_ref, a.startup_name, a.applicant_name FROM inc_assignments s JOIN inc_workspaces w ON w.id = s.workspace_id
                                  JOIN inc_applications a ON a.id = s.application_id WHERE (s.owner_id = ? OR s.prev_owner_id = ?) AND s.kind = "reassign" AND s.created_at >= NOW() - INTERVAL 30 DAY ORDER BY s.created_at DESC', 'ii', [$personId, $personId]),
        'tasks'      => inc_all('SELECT t.*, a.ref AS app_ref, a.startup_name FROM inc_tasks t LEFT JOIN inc_applications a ON a.id = t.application_id
                                  WHERE t.assignee_id = ? AND t.status = "open" ORDER BY t.due_date IS NULL, t.due_date, t.id', 'i', [$personId]),
        'followups'  => inc_all('SELECT t.*, a.ref AS app_ref, a.startup_name FROM inc_tasks t LEFT JOIN inc_applications a ON a.id = t.application_id
                                  WHERE t.status = "open" AND t.kind IN ("follow_up","reminder") AND t.due_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 14 DAY
                                  AND (t.assignee_id = ? OR (t.entity = "workspace" AND t.entity_id IN (' . $in . '))) ORDER BY t.due_date', 'i', [$personId]),
        'evals'      => inc_all('SELECT e.*, a.ref AS app_ref, a.startup_name, a.applicant_name FROM inc_evaluations e JOIN inc_applications a ON a.id = e.application_id
                                  WHERE e.evaluator_id = ? AND e.status IN ("submitted","under_review","returned","draft","in_progress") ORDER BY e.updated_at DESC', 'i', [$personId]),
        'activity'   => inc_all('SELECT * FROM inc_activities WHERE workspace_id IN (' . $in . ') ORDER BY created_at DESC, id DESC LIMIT 30'),
    ];
}

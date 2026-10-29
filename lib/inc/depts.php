<?php
/**
 * Incubation Management — departments.
 *
 * THE FLOW
 *
 *   1. The incubation owner makes departments (Settings → Departments): a
 *      name, and people ticked from the organisation's existing team (Team
 *      Management, or a client's My Team). Nobody is created twice.
 *   2. Each department keeps its own stages -- New, In progress, Completed to
 *      start with -- and its members may change them.
 *   3. From an application, the owner assigns it to one or more departments,
 *      and every member is told and emailed. A form can also belong to a
 *      department (its settings): every application on it goes straight to
 *      that department's desk. Either way the application stays at its stage
 *      in the main pipeline (New, for a new one) -- departments never move it.
 *   4. Members see it on their department desk (?p=desk), even without the
 *      Incubation Management itself: the answers and documents, their own stages,
 *      notes. They move it along and, when done, return it to the incubation
 *      team with a remark.
 *   5. When one is returned the owner is told, and the remark is on its
 *      timeline. It can be assigned to a department again at any time.
 *
 * A department is an incubation team (inc_teams / inc_team_members, role
 * "member"); its stages and hand-offs are inc_dept_stages and
 * inc_dept_assignments. Everything is in the organisation's own tables
 * (lib/inc/org.php).
 */

require_once __DIR__ . '/apps.php';

function inc_dept_default_stages(): array
{
    return [['New', 0], ['In progress', 0], ['Completed', 1]];
}

/** The signed-in person behind the request (0 for the administrator or a script). */
function inc_me(): int
{
    $v = inc_ctx()['viewer'] ?? [];
    return !empty($v['is_admin']) ? 0 : (int) ($v['client']['id'] ?? 0);
}

/** Active departments, id => name: all of them for whoever runs the CRM, else the person's own. */
function inc_my_depts(): array
{
    $out = [];
    if (inc_can('view')) {
        return inc_teams(true);
    }
    foreach (inc_all('SELECT DISTINCT t.id, t.name FROM inc_teams t JOIN inc_team_members m ON m.team_id = t.id
                       WHERE t.active = 1 AND m.person_id = ? ORDER BY t.name', 'i', [inc_me()]) as $r) {
        $out[(int) $r['id']] = $r['name'];
    }
    return $out;
}

/** May the viewer work this department's desk? Its members, and whoever assigns work. */
function inc_dept_may_work(int $teamId): bool
{
    if (inc_can('assign')) {
        return true;
    }
    return isset(inc_my_depts()[$teamId]);
}

function inc_dept_need(int $teamId): array
{
    $t = inc_team($teamId);
    if (!$t || !inc_dept_may_work($teamId)) {
        throw new InvalidArgumentException('That department is not one of yours.');
    }
    return $t;
}

/** A department's members, id => name. */
function inc_dept_people(int $teamId): array
{
    $out = [];
    foreach (inc_team_members($teamId) as $m) {
        $out[(int) $m['person_id']] = $m['name'];
    }
    return $out;
}

/**
 * Who sits on a department: exactly the people ticked, from the
 * organisation's existing team. Earlier role rows (lead, evaluator...) of
 * people still ticked are kept; people unticked leave the department.
 */
function inc_dept_members_save(int $teamId, array $personIds): void
{
    inc_need('configure');
    if (!inc_team($teamId)) {
        throw new InvalidArgumentException('That department no longer exists.');
    }
    $allowed = inc_people();
    $want = array_values(array_unique(array_filter(array_map('intval', $personIds), static fn($p) => isset($allowed[$p]))));
    inc_tx(static function () use ($teamId, $want) {
        $have = array_map('intval', array_column(inc_all('SELECT DISTINCT person_id FROM inc_team_members WHERE team_id = ?', 'i', [$teamId]), 'person_id'));
        foreach (array_diff($have, $want) as $p) {
            inc_exec('DELETE FROM inc_team_members WHERE team_id = ? AND person_id = ?', 'ii', [$teamId, $p]);
        }
        foreach (array_diff($want, $have) as $p) {
            inc_exec('INSERT IGNORE INTO inc_team_members (team_id, person_id, role, added_by) VALUES (?,?,"member",?)', 'iis', [$teamId, $p, inc_actor()]);
        }
    });
    inc_audit('members', 'team', $teamId, count($want) . ' people');
}

/* ---------------------------------------------------------------- stages */

/** A department's stages, in order; a department that has none gets the defaults. */
function inc_dept_stages(int $teamId): array
{
    $rows = inc_all('SELECT * FROM inc_dept_stages WHERE team_id = ? ORDER BY sort, id', 'i', [$teamId]);
    if ($rows || !inc_team($teamId)) {
        return $rows;
    }
    $i = 0;
    foreach (inc_dept_default_stages() as [$name, $final]) {
        inc_exec('INSERT IGNORE INTO inc_dept_stages (team_id, name, sort, is_final) VALUES (?,?,?,?)', 'isii', [$teamId, $name, ++$i, $final]);
    }
    return inc_all('SELECT * FROM inc_dept_stages WHERE team_id = ? ORDER BY sort, id', 'i', [$teamId]);
}

function inc_dept_stage_save(int $teamId, ?int $id, string $name, bool $final): void
{
    inc_dept_need($teamId);
    $name = inc_str($name, 80, true, 'Stage name');
    if (inc_one('SELECT id FROM inc_dept_stages WHERE team_id = ? AND name = ? AND id <> ?', 'isi', [$teamId, $name, (int) $id])) {
        throw new InvalidArgumentException('This department already has a stage called "' . $name . '".');
    }
    if ($id) {
        inc_exec('UPDATE inc_dept_stages SET name = ?, is_final = ? WHERE id = ? AND team_id = ?', 'siii', [$name, $final ? 1 : 0, $id, $teamId]);
    } else {
        $sort = (int) (inc_one('SELECT MAX(sort) m FROM inc_dept_stages WHERE team_id = ?', 'i', [$teamId])['m'] ?? 0);
        inc_exec('INSERT INTO inc_dept_stages (team_id, name, sort, is_final) VALUES (?,?,?,?)', 'isii', [$teamId, $name, $sort + 1, $final ? 1 : 0]);
    }
    inc_audit('dept_stage', 'team', $teamId, $name);
}

function inc_dept_stage_move(int $teamId, int $id, int $dir): void
{
    inc_dept_need($teamId);
    $rows = inc_dept_stages($teamId);
    $ids = array_map(static fn($r) => (int) $r['id'], $rows);
    $i = array_search($id, $ids, true);
    $j = $i === false ? false : $i + ($dir < 0 ? -1 : 1);
    if ($i === false || $j < 0 || $j >= count($ids)) {
        return;
    }
    [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
    foreach ($ids as $k => $sid) {
        inc_exec('UPDATE inc_dept_stages SET sort = ? WHERE id = ?', 'ii', [$k + 1, $sid]);
    }
}

function inc_dept_stage_delete(int $teamId, int $id): void
{
    inc_dept_need($teamId);
    if (inc_one('SELECT id FROM inc_dept_assignments WHERE team_id = ? AND stage_id = ? AND state = "open" LIMIT 1', 'ii', [$teamId, $id])) {
        throw new InvalidArgumentException('Applications are in this stage. Move them to another stage first.');
    }
    if (count(inc_dept_stages($teamId)) <= 1) {
        throw new InvalidArgumentException('A department needs at least one stage.');
    }
    inc_exec('DELETE FROM inc_dept_stages WHERE id = ? AND team_id = ?', 'ii', [$id, $teamId]);
}

/* --------------------------------------------------------- the hand-off */

/** Moves an application in the main pipeline because of a hand-off; the pipeline's own rules are for people, not for this. */
function inc_app_system_stage(int $appId, string $code, string $reason): void
{
    $to = inc_stage_by_code('crm', $code, false);
    if ($to) {
        inc_app_system_move($appId, (int) $to['id'], $reason);
    }
}

function inc_dept_vars(array $app, array $team, string $note): array
{
    return ['ref' => $app['ref'], 'startup' => $app['startup_name'] ?: $app['applicant_name'], 'applicant' => $app['applicant_name'],
            'team' => $team['name'], 'note' => $note !== '' ? $note : '—', 'date' => inc_fmt_datetime(date('Y-m-d H:i:s')), 'owner' => inc_actor()];
}

/**
 * Hands an application to departments. A department it was already with
 * keeps it (open) or takes it back (returned -> open again, at its first
 * stage). Returns how many departments it went to.
 */
function inc_dept_assign(int $appId, array $teamIds, string $note): int
{
    inc_need('assign');
    $app = inc_app($appId);
    if (!$app) {
        throw new InvalidArgumentException('That application no longer exists.');
    }
    $active = inc_teams(true);
    $teamIds = array_values(array_unique(array_filter(array_map('intval', $teamIds), static fn($t) => isset($active[$t]))));
    if (!$teamIds) {
        throw new InvalidArgumentException('Tick at least one department.');
    }
    $note = inc_str($note, 2000);
    $done = [];
    inc_tx(static function () use ($appId, $teamIds, $note, &$done) {
        foreach ($teamIds as $tid) {
            $first = inc_dept_stages($tid)[0]['id'] ?? null;
            $was = inc_one('SELECT * FROM inc_dept_assignments WHERE application_id = ? AND team_id = ?', 'ii', [$appId, $tid]);
            if ($was && $was['state'] === 'open') {
                continue;                                   // already with them
            }
            if ($was) {
                inc_exec('UPDATE inc_dept_assignments SET state = "open", stage_id = ?, note = ?, return_note = NULL, returned_by = NULL,
                                 returned_at = NULL, assigned_by = ?, assigned_at = NOW() WHERE id = ?', 'issi',
                         [$first, $note !== '' ? $note : null, inc_actor(), (int) $was['id']]);
                $done[$tid] = (int) $was['id'];
            } else {
                $done[$tid] = inc_exec('INSERT INTO inc_dept_assignments (application_id, team_id, stage_id, note, assigned_by) VALUES (?,?,?,?,?)',
                                       'iiiss', [$appId, $tid, $first, $note !== '' ? $note : null, inc_actor()]);
            }
        }
        if ($done) {
            $names = array_map(static fn($t) => inc_team_name($t), array_keys($done));
            inc_activity($appId, null, 'department', 'Assigned to ' . implode(', ', $names) . ($note !== '' ? ' — ' . mb_substr($note, 0, 200) : '') . '.');
            inc_audit('dept_assign', 'application', $appId, implode(', ', $names));
        }
    });
    // Told after the commit: a failed email never undoes the hand-off.
    foreach ($done as $tid => $aid) {
        $team = inc_team($tid);
        foreach (array_keys(inc_dept_people($tid)) as $pid) {
            inc_notify('dept_assigned', 'da' . $aid . ':' . time(), $pid, inc_dept_vars($app, $team, $note), $appId, null, '',
                       inc_url('desk', ['dept' => $tid, 'a' => $aid]));
        }
    }
    return count($done);
}

function inc_dept_assignment(int $id): ?array
{
    return inc_one('SELECT d.*, t.name AS team_name FROM inc_dept_assignments d JOIN inc_teams t ON t.id = d.team_id WHERE d.id = ?', 'i', [$id]);
}

/** One assignment the viewer may work, or an exception. */
function inc_dept_assignment_need(int $id): array
{
    $d = inc_dept_assignment($id);
    if (!$d || !inc_dept_may_work((int) $d['team_id'])) {
        throw new InvalidArgumentException('That application is not with one of your departments.');
    }
    return $d;
}

function inc_dept_set_stage(int $assignId, int $stageId): void
{
    $d = inc_dept_assignment_need($assignId);
    if ($d['state'] !== 'open') {
        throw new InvalidArgumentException('It has been returned to the incubation team.');
    }
    $st = inc_one('SELECT * FROM inc_dept_stages WHERE id = ? AND team_id = ?', 'ii', [$stageId, (int) $d['team_id']]);
    if (!$st) {
        throw new InvalidArgumentException('Choose one of this department\'s stages.');
    }
    inc_exec('UPDATE inc_dept_assignments SET stage_id = ? WHERE id = ?', 'ii', [$stageId, $assignId]);
    inc_activity((int) $d['application_id'], null, 'department', $d['team_name'] . ': moved to ' . $st['name'] . '.');
}

/** A note from the department, kept on the application's timeline where the incubation team reads it. */
function inc_dept_note(int $assignId, string $note): void
{
    $d = inc_dept_assignment_need($assignId);
    $note = inc_str($note, 1500, true, 'Note');
    inc_activity((int) $d['application_id'], null, 'department', $d['team_name'] . ' note: ' . $note);
}

/** The department hands the application back to the incubation team, with a remark. */
function inc_dept_return(int $assignId, string $note): void
{
    $d = inc_dept_assignment_need($assignId);
    if ($d['state'] !== 'open') {
        throw new InvalidArgumentException('It has already been returned.');
    }
    $note = inc_str($note, 2000, true, 'Remark for the incubation team');
    $appId = (int) $d['application_id'];
    inc_tx(static function () use ($d, $assignId, $note, $appId) {
        inc_exec('UPDATE inc_dept_assignments SET state = "returned", return_note = ?, returned_by = ?, returned_at = NOW() WHERE id = ?',
                 'ssi', [$note, inc_actor(), $assignId]);
        inc_activity($appId, null, 'department', $d['team_name'] . ' returned it to the incubation team: ' . mb_substr($note, 0, 400));
        inc_audit('dept_return', 'application', $appId, $d['team_name']);
    });
    $app = inc_app($appId);
    if ($app) {
        inc_notify('dept_returned', 'dr' . $assignId . ':' . time(), 0, inc_dept_vars($app, ['name' => $d['team_name']], $note) + ['by' => inc_actor()],
                   $appId, null, inc_admin_link('application', ['id' => $appId]));
    }
}

/** Where an application stands with each department, newest first. */
function inc_dept_assignments_for_app(int $appId): array
{
    return inc_all('SELECT d.*, t.name AS team_name, s.name AS stage_name FROM inc_dept_assignments d
                      JOIN inc_teams t ON t.id = d.team_id LEFT JOIN inc_dept_stages s ON s.id = d.stage_id
                     WHERE d.application_id = ? ORDER BY d.assigned_at DESC, d.id DESC', 'i', [$appId]);
}

/**
 * Puts one application on a department's desk, open at its first stage, and
 * tells the members when $notify. Nothing changes in the incubation team's
 * pipeline: the application stays at the stage it is at (New, for a new one).
 * Returns the hand-off id, or 0 when the department already has it (an open
 * one, or one it returned -- that does not come back by itself).
 */
function inc_dept_route_app(int $appId, int $teamId, string $by, bool $notify, ?string $at = null): int
{
    $team = inc_team($teamId);
    if (!$team || (int) $team['active'] !== 1) {
        return 0;
    }
    $first = inc_dept_stages($teamId)[0]['id'] ?? null;
    $aid = inc_exec('INSERT IGNORE INTO inc_dept_assignments (application_id, team_id, stage_id, assigned_by, assigned_at) VALUES (?,?,?,?,COALESCE(?, NOW()))',
                    'iiiss', [$appId, $teamId, $first, mb_substr($by, 0, 190), $at]);
    if (!$aid) {
        return 0;
    }
    inc_activity($appId, null, 'department', 'With ' . $team['name'] . ' (' . $by . ').');
    $app = $notify ? inc_app($appId) : null;
    if ($app) {
        foreach (array_keys(inc_dept_people($teamId)) as $pid) {
            inc_notify('dept_assigned', 'da' . $aid . ':' . time(), $pid, inc_dept_vars($app, $team, ''), $appId, null, '',
                       inc_url('desk', ['dept' => $teamId, 'a' => $aid]));
        }
    }
    return $aid;
}

/**
 * Brings a department's desk up to date with the two other ways an
 * application becomes the department's:
 *
 *   - the form belongs to the department (inc_forms.dept_team_id). Ones
 *     submitted from now on are routed as they arrive; the backlog of a form
 *     just given to a department is added here in one statement, quietly.
 *   - "Assign for incubation" or "Screening owner" chose the department
 *     (inc_applications.team_id). Those are added one by one, and the members
 *     are told.
 *
 * A hand-off the department returned keeps its row, so it does not come back
 * by itself. The incubation pipeline's stages are never touched. Runs on
 * assignment, when a form changes hands, and whenever the desk opens.
 */
function inc_dept_sync(int $teamId): void
{
    if ($teamId <= 0 || !inc_team($teamId)) {
        return;
    }
    $first = inc_dept_stages($teamId)[0]['id'] ?? null;
    inc_exec('INSERT IGNORE INTO inc_dept_assignments (application_id, team_id, stage_id, assigned_by, assigned_at)
              SELECT a.id, f.dept_team_id, ?, CONCAT("Form: ", f.name), a.submitted_at
                FROM inc_applications a JOIN inc_forms f ON f.id = a.form_id
               WHERE f.dept_team_id = ? AND COALESCE(a.dup_status, "") <> "confirmed"
                 AND NOT EXISTS (SELECT 1 FROM inc_dept_assignments d WHERE d.application_id = a.id AND d.team_id = f.dept_team_id)',
             'ii', [$first, $teamId]);
    $new = inc_all('SELECT a.id,
                           (SELECT s.actor FROM inc_assignments s WHERE s.application_id = a.id AND s.team_id = a.team_id ORDER BY s.id DESC LIMIT 1) AS actor,
                           (SELECT MAX(s.created_at) FROM inc_assignments s WHERE s.application_id = a.id AND s.team_id = a.team_id) AS at
                      FROM inc_applications a
                     WHERE a.team_id = ? AND COALESCE(a.dup_status, "") <> "confirmed"
                       AND NOT EXISTS (SELECT 1 FROM inc_dept_assignments d WHERE d.application_id = a.id AND d.team_id = a.team_id)
                     LIMIT 500', 'i', [$teamId]);
    foreach ($new as $r) {
        inc_dept_route_app((int) $r['id'], $teamId, (string) ($r['actor'] ?: inc_actor() ?: 'Incubation team'), true, $r['at']);
    }
}

/** A department's applications: open ones for the board, and the recently returned. */
function inc_dept_board(int $teamId, int $returnedLimit = 20, array $f = []): array
{
    inc_dept_sync($teamId);
    $where = '';
    $types = '';
    $params = [];
    if (isset($f['q'])) {
        $like = '%' . $f['q'] . '%';
        $where .= ' AND (a.applicant_name LIKE ? OR a.startup_name LIKE ? OR a.applicant_email LIKE ? OR a.applicant_phone LIKE ? OR a.ref LIKE ?)';
        $types .= 'sssss';
        array_push($params, $like, $like, $like, $like, $like);
    }
    if (isset($f['form'])) {
        $where .= ' AND a.form_id = ?';
        $types .= 'i';
        $params[] = (int) $f['form'];
    }
    if (isset($f['docs'])) {
        $where .= ($f['docs'] === 'yes' ? ' AND' : ' AND NOT') . ' EXISTS (SELECT 1 FROM inc_files fl WHERE fl.entity = "application" AND fl.entity_id = a.id)';
    }
    if (isset($f['dup'])) {
        $where .= $f['dup'] === 'suspected' ? ' AND a.dup_status = "suspected"' : ' AND a.dup_status <> "suspected"';
    }
    if (isset($f['from'])) {
        $where .= ' AND a.submitted_at >= ?';
        $types .= 's';
        $params[] = $f['from'];
    }
    if (isset($f['to'])) {
        $where .= ' AND a.submitted_at < ? + INTERVAL 1 DAY';
        $types .= 's';
        $params[] = $f['to'];
    }
    if (isset($f['ans'])) {
        $where .= ' AND EXISTS (SELECT 1 FROM inc_app_values v WHERE v.application_id = a.id AND v.value_text LIKE ?)';
        $types .= 's';
        $params[] = '%' . $f['ans'] . '%';
    }
    $order = ['newest' => 'a.submitted_at DESC, d.id DESC', 'oldest' => 'a.submitted_at, d.id',
              'name' => 'COALESCE(NULLIF(a.startup_name, ""), a.applicant_name), d.id'][$f['sort'] ?? ''] ?? 'd.assigned_at, d.id';
    $sql = 'SELECT d.*, a.ref, a.applicant_name, a.applicant_email, a.applicant_phone, a.startup_name, a.submitted_at, a.dup_status, f.name AS form_name
              FROM inc_dept_assignments d JOIN inc_applications a ON a.id = d.application_id JOIN inc_forms f ON f.id = a.form_id
             WHERE d.team_id = ? AND d.state = ?' . $where;
    return [
        'open'     => inc_all($sql . ' ORDER BY ' . $order, 'is' . $types, array_merge([$teamId, 'open'], $params)),
        'returned' => inc_all($sql . ' ORDER BY d.returned_at DESC LIMIT ' . max(1, $returnedLimit), 'is' . $types, array_merge([$teamId, 'returned'], $params)),
    ];
}

/** The desk's filters from the query string: only the ones set, cleaned. */
function inc_dept_filters(array $get): array
{
    $f = [];
    foreach (['q' => 100, 'ans' => 100] as $k => $max) {
        $v = trim((string) ($get[$k] ?? ''));
        if ($v !== '') {
            $f[$k] = mb_substr($v, 0, $max);
        }
    }
    foreach (['form', 'stage'] as $k) {
        if ((int) ($get[$k] ?? 0) > 0) {
            $f[$k] = (string) (int) $get[$k];
        }
    }
    if (in_array($get['docs'] ?? '', ['yes', 'no'], true)) {
        $f['docs'] = $get['docs'];
    }
    if (in_array($get['dup'] ?? '', ['suspected', 'none'], true)) {
        $f['dup'] = $get['dup'];
    }
    foreach (['from', 'to'] as $k) {
        $d = DateTime::createFromFormat('!Y-m-d', (string) ($get[$k] ?? ''));
        if ($d && $d->format('Y-m-d') === ($get[$k] ?? '')) {
            $f[$k] = $get[$k];
        }
    }
    if (in_array($get['sort'] ?? '', ['newest', 'oldest', 'name'], true)) {
        $f['sort'] = $get['sort'];
    }
    return $f;
}

/** Each application's documents, for a page of desk rows: application id => files. */
function inc_dept_docs(array $rows): array
{
    $ids = array_values(array_unique(array_map(static fn($r) => (int) $r['application_id'], $rows)));
    $out = [];
    if ($ids) {
        foreach (inc_all('SELECT id, entity_id, label, original_name FROM inc_files WHERE entity = "application" AND entity_id IN (' . implode(',', $ids) . ') ORDER BY id') as $fl) {
            $out[(int) $fl['entity_id']][] = $fl;
        }
    }
    return $out;
}

/** Open applications per department the viewer works, for the dashboard line. */
function inc_dept_open_counts(): array
{
    $out = [];
    foreach (inc_my_depts() as $tid => $name) {
        inc_dept_sync($tid);
        $out[$tid] = (int) (inc_one('SELECT COUNT(*) n FROM inc_dept_assignments WHERE team_id = ? AND state = "open"', 'i', [$tid])['n'] ?? 0);
    }
    return $out;
}

/** May the viewer see this application's answers and files through a department? */
function inc_dept_sees_app(int $appId): bool
{
    foreach (array_keys(inc_my_depts()) as $tid) {
        if (inc_one('SELECT id FROM inc_dept_assignments WHERE application_id = ? AND team_id = ?', 'ii', [$appId, $tid])) {
            return true;
        }
    }
    return false;
}

/**
 * A department emails applicants on its desk: written on the spot, with
 * {name}, {startup} and {ref} filled in for each. Only applications the
 * department holds (open or returned); each send goes on the application's
 * timeline under the department's name, where the incubation team reads it.
 */
function inc_dept_email(int $teamId, array $appIds, string $subject, string $body): array
{
    $team = inc_dept_need($teamId);
    $ids = array_values(array_unique(array_filter(array_map('intval', $appIds))));
    if (!$ids) {
        throw new InvalidArgumentException('Choose at least one application to email.');
    }
    if (count($ids) > 500) {
        throw new InvalidArgumentException('Email up to 500 applications at a time.');
    }
    $held = array_map('intval', array_column(inc_all('SELECT application_id FROM inc_dept_assignments WHERE team_id = ? AND application_id IN ('
                                                     . implode(',', $ids) . ')', 'i', [$teamId]), 'application_id'));
    if (count($held) !== count($ids)) {
        throw new InvalidArgumentException('Only applications on ' . $team['name'] . '\'s desk can be emailed from here.');
    }
    $r = inc_apps_email_send($ids, $subject, $body, $team['name'] . ': ');
    inc_audit('dept_email', 'team', $teamId, count($ids) . ' application(s): ' . mb_substr($subject, 0, 120));
    return $r;
}

/**
 * Files an application a department member entered (a walk-in, a call, a
 * referral) on their department's desk. It is an ordinary application: it
 * sits at the first stage of the main pipeline, where the incubation team
 * sees it and is told, and it is open with the department at once.
 */
function inc_dept_take_new(int $teamId, int $appId): int
{
    $team = inc_dept_need($teamId);
    $aid = inc_dept_route_app($appId, $teamId, 'entered by ' . inc_actor(), false);
    if (!$aid) {
        $aid = (int) (inc_one('SELECT id FROM inc_dept_assignments WHERE application_id = ? AND team_id = ?', 'ii', [$appId, $teamId])['id'] ?? 0);
    }
    return $aid;
}

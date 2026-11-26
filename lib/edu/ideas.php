<?php
/**
 * Academic — student ideas and the road into Incubation (Phase 4).
 *
 * A student writes an idea (problem, solution, stage, team). When it is
 * ready they apply to the organisation's own Incubation Management with
 * it: the portal shows the incubation form the organisation chose for
 * students, filled in from the idea and their profile, and files it with
 * inc_app_submit() -- the same pipeline, tables, checks and acknowledgement
 * email as anyone applying through the public link. Nothing is copied into
 * the incubation tables by hand. The idea then shows where the application
 * is in the pipeline.
 *
 * Also here: the 'council' role (a government or council officer who sees
 * every institution, read-only) added to edu_roles.
 */

require_once __DIR__ . '/core.php';
require_once __DIR__ . '/game.php';
require_once __DIR__ . '/../throttle.php';   // fabo_client_ip()

const EDU_P4_VERSION = 4;

function edu_ideas_install(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    edu_game_install();
    if ((int) fabo_setting('edu_p4_schema', '0') >= EDU_P4_VERSION) {
        return;
    }
    $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    fabo_exec("CREATE TABLE IF NOT EXISTS edu_ideas (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      org_id INT UNSIGNED NOT NULL,
      person_id INT UNSIGNED NOT NULL,
      title VARCHAR(190) NOT NULL,
      problem TEXT NULL,
      solution TEXT NULL,
      sector VARCHAR(120) NOT NULL DEFAULT '',
      stage ENUM('idea','prototype','pilot','revenue') NOT NULL DEFAULT 'idea',
      team TEXT NULL,
      inc_app_id INT UNSIGNED NULL,
      inc_ref VARCHAR(40) NULL,
      applied_at DATETIME NULL,
      created_at DATETIME NOT NULL,
      updated_at DATETIME NOT NULL,
      KEY k_org (org_id, created_at),
      KEY k_person (person_id)
    ) $t");
    fabo_exec("ALTER TABLE edu_roles MODIFY role ENUM('student','faculty','mentor','inst_admin','council') NOT NULL");
    fabo_exec('INSERT INTO app_settings (k, v) VALUES ("edu_p4_schema", ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', 's', [(string) EDU_P4_VERSION]);
}

function edu_idea_stages(): array
{
    return ['idea' => 'Just an idea', 'prototype' => 'Prototype', 'pilot' => 'Pilot with users', 'revenue' => 'Earning revenue'];
}

function edu_idea(int $org, int $id): ?array
{
    return fabo_one('SELECT * FROM edu_ideas WHERE org_id = ? AND id = ?', 'ii', [$org, $id]);
}

function edu_ideas_of(int $org, int $pid): array
{
    return fabo_all('SELECT * FROM edu_ideas WHERE org_id = ? AND person_id = ? ORDER BY updated_at DESC', 'ii', [$org, $pid]);
}

/** A student's idea, new or changed. Once applied, it stays as it was sent. */
function edu_idea_save(int $org, array $person, array $d, ?int $id = null): int
{
    $pid = (int) $person['id'];
    if (!edu_is_student($org, $pid)) {
        throw new InvalidArgumentException('Ideas are for students.');
    }
    $title = edu_clean($d['title'] ?? '', 190);
    if ($title === '') {
        throw new InvalidArgumentException('Give your idea a name.');
    }
    $problem = mb_substr(trim((string) ($d['problem'] ?? '')), 0, 4000);
    if (mb_strlen($problem) < 20) {
        throw new InvalidArgumentException('Describe the problem in a sentence or two (at least 20 characters).');
    }
    $stage = isset(edu_idea_stages()[$d['stage'] ?? '']) ? $d['stage'] : 'idea';
    $vals = [$title, $problem, mb_substr(trim((string) ($d['solution'] ?? '')), 0, 4000), edu_clean($d['sector'] ?? '', 120), $stage, mb_substr(trim((string) ($d['team'] ?? '')), 0, 2000)];
    if ($id) {
        $i = edu_idea($org, $id);
        if (!$i || (int) $i['person_id'] !== $pid) {
            throw new InvalidArgumentException('That is not your idea.');
        }
        if ($i['inc_app_id']) {
            throw new InvalidArgumentException('This idea has been sent to incubation; the incubation team holds it now.');
        }
        fabo_exec('UPDATE edu_ideas SET title=?, problem=?, solution=?, sector=?, stage=?, team=?, updated_at=? WHERE org_id = ? AND id = ?', 'sssssssii', array_merge($vals, [edu_now(), $org, $id]));
        return $id;
    }
    if ((int) (fabo_one('SELECT COUNT(*) AS n FROM edu_ideas WHERE org_id = ? AND person_id = ?', 'ii', [$org, $pid])['n'] ?? 0) >= 20) {
        throw new InvalidArgumentException('You have 20 ideas already. Develop one of them first.');
    }
    $id = fabo_exec('INSERT INTO edu_ideas (title, problem, solution, sector, stage, team, org_id, person_id, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
                    'ssssssiiss', array_merge($vals, [$org, $pid, edu_now(), edu_now()]));
    edu_award($org, $pid, 'idea', 'idea:' . $id, $title);
    return $id;
}

function edu_idea_delete(int $org, array $person, int $id): void
{
    $i = edu_idea($org, $id);
    if (!$i || (int) $i['person_id'] !== (int) $person['id']) {
        throw new InvalidArgumentException('That is not your idea.');
    }
    if ($i['inc_app_id']) {
        throw new InvalidArgumentException('An idea sent to incubation cannot be removed here.');
    }
    fabo_exec('DELETE FROM edu_ideas WHERE org_id = ? AND id = ?', 'ii', [$org, $id]);
}

/* ========================================================= the incubation bridge */

/** Does this organisation run Incubation Management (so students can apply)? */
function edu_inc_available(int $org): bool
{
    if (!is_file(__DIR__ . '/../inc/core.php')) {
        return false;
    }
    require_once __DIR__ . '/../inc/org.php';
    return inc_org_enabled($org);
}

/** Opens the organisation's Incubation Management for the portal (as a public applicant), or false. */
function edu_inc_open(int $org): bool
{
    if (!edu_inc_available($org)) {
        return false;
    }
    require_once __DIR__ . '/../inc/render.php';
    inc_use_kind('inc');
    inc_start(['is_admin' => false, 'client' => ['name' => 'applicant'], 'access' => null], $org);
    return true;
}

/** The incubation forms students may be pointed to: published ones. id => [name, public?]. */
function edu_inc_forms(int $org): array
{
    if (!edu_inc_open($org)) {
        return [];
    }
    $out = [];
    foreach (inc_forms_list() as $f) {
        if ($f['published_no'] !== null) {
            $out[(int) $f['id']] = ['name' => (string) $f['name'], 'public' => (int) $f['public_enabled'] === 1];
        }
    }
    return $out;
}

/** The form students apply with, ready to use, or [null, why not]. */
function edu_inc_form(int $org): array
{
    $fid = (int) edu_get($org, 'inc_form_id', '0');
    if (!edu_inc_open($org)) {
        return [null, null, 'Your organisation does not run Incubation Management here yet.'];
    }
    $form = $fid ? inc_form($fid) : null;
    $version = $form ? inc_form_published($fid) : null;
    if (!$form || !$version || (int) $form['archived'] === 1) {
        return [null, null, 'Applications from the Campus portal are not open yet. Ask your programme team.'];
    }
    if ((int) $form['public_enabled'] !== 1) {
        return [null, null, 'The incubation form is closed right now. Ask your programme team.'];
    }
    return [$form, $version, ''];
}

/** Answers for the form, filled in from the idea and the student's profile, keyed by field uid. */
function edu_inc_prefill(array $fields, array $idea, array $person, string $instName): array
{
    $v = [];
    foreach ($fields as $f) {
        $uid = (string) $f['uid'];
        $label = mb_strtolower((string) $f['label']);
        $map = (string) ($f['maps_to'] ?? '');
        if ($map === 'applicant_name') {
            $v[$uid] = $person['name'];
        } elseif ($map === 'applicant_email') {
            $v[$uid] = (string) $person['email'];
        } elseif ($map === 'applicant_phone') {
            $v[$uid] = (string) $person['phone'];
        } elseif ($map === 'startup_name') {
            $v[$uid] = $idea['title'];
        } elseif ($map === 'dev_stage') {
            // The idea's stage, in the organisation's own development stages (when it keeps that one).
            $code = ['idea' => 'idea', 'prototype' => 'prototype', 'pilot' => 'prototype', 'revenue' => 'early_market'][$idea['stage']] ?? 'idea';
            if (isset(inc_field_choices($f)[$code])) {
                $v[$uid] = $code;
            }
        } elseif ($map === 'applicant_type' && !inc_is_choice((string) $f['type'])) {
            $v[$uid] = 'Student';
        } elseif (in_array($f['type'], ['textarea', 'text'], true) && $map === '') {
            if (preg_match('/problem|pain|challenge/', $label)) {
                $v[$uid] = $idea['problem'];
            } elseif (preg_match('/solution|idea|describe|product|innovation/', $label) && $f['type'] === 'textarea') {
                $v[$uid] = (string) $idea['solution'];
            } elseif (preg_match('/team|co-?founder|member/', $label) && $f['type'] === 'textarea') {
                $v[$uid] = (string) $idea['team'];
            } elseif (preg_match('/college|institution|university/', $label)) {
                $v[$uid] = $instName;
            } elseif (preg_match('/roll|registration|hall ticket/', $label)) {
                $v[$uid] = (string) $person['roll_no'];
            } elseif (preg_match('/sector|domain|industry/', $label)) {
                $v[$uid] = (string) $idea['sector'];
            }
        }
    }
    return $v;
}

/**
 * Files the application. $raw / $files as posted. Returns the incubation
 * reference. A second press with the same ticket returns the first.
 */
function edu_idea_apply(int $org, array $person, int $ideaId, array $raw, array $files, string $ticket): string
{
    $idea = edu_idea($org, $ideaId);
    if (!$idea || (int) $idea['person_id'] !== (int) $person['id']) {
        throw new InvalidArgumentException('That is not your idea.');
    }
    if ($idea['inc_app_id']) {
        return (string) $idea['inc_ref'];
    }
    [$form, $version, $why] = edu_inc_form($org);
    if (!$form) {
        throw new InvalidArgumentException($why);
    }
    $res = inc_app_submit($form, $version, $raw, $files, ['channel' => 'public', 'ticket' => $ticket, 'ip' => fabo_client_ip()]);
    fabo_exec('UPDATE edu_ideas SET inc_app_id = ?, inc_ref = ?, applied_at = ?, updated_at = ? WHERE org_id = ? AND id = ?',
              'isssii', [(int) $res['id'], (string) $res['ref'], edu_now(), edu_now(), $org, $ideaId]);
    try {
        inc_activity((int) $res['id'], null, 'note', 'Sent from the Campus portal by ' . $person['name'] . ' (student' . ($person['roll_no'] !== '' ? ', ' . $person['roll_no'] : '') . '), from their idea "' . $idea['title'] . '".');
    } catch (Throwable $e) {
        error_log('FABO edu inc note: ' . $e->getMessage());
    }
    edu_award($org, (int) $person['id'], 'apply', 'idea:' . $ideaId, $idea['title']);
    return (string) $res['ref'];
}

/** Where each sent idea is in the incubation pipeline: app id => ['stage', 'terminal']. */
function edu_inc_status(int $org, array $appIds): array
{
    $ids = array_values(array_filter(array_map('intval', $appIds)));
    if (!$ids || !edu_inc_open($org)) {
        return [];
    }
    $out = [];
    foreach (inc_all('SELECT a.id, s.name, s.is_terminal, s.sort, a.startup_id FROM inc_applications a JOIN inc_stages s ON s.id = a.stage_id WHERE a.id IN (' . implode(',', $ids) . ')') as $r) {
        $out[(int) $r['id']] = ['stage' => (string) $r['name'], 'terminal' => (int) $r['is_terminal'] === 1, 'startup' => (int) $r['startup_id']];
    }
    return $out;
}

/** Ideas across the organisation (or some institutions), with the student and institution. */
function edu_ideas_list(int $org, ?array $instIds = null, ?int $mentorId = null): array
{
    $w = 'i.org_id = ?';
    $types = 'i';
    $p = [$org];
    if ($instIds !== null) {
        $ids = array_values(array_filter(array_map('intval', $instIds)));
        if (!$ids) {
            return [];
        }
        $w .= ' AND EXISTS (SELECT 1 FROM edu_roles r WHERE r.person_id = i.person_id AND r.role = "student" AND r.institution_id IN (' . implode(',', $ids) . '))';
    }
    if ($mentorId !== null) {
        $w .= ' AND EXISTS (SELECT 1 FROM edu_mentor_links l WHERE l.student_id = i.person_id AND l.mentor_id = ?)';
        $types .= 'i';
        $p[] = $mentorId;
    }
    return fabo_all('SELECT i.*, p.name AS student_name, p.email AS student_email, p.roll_no,
                            (SELECT ins.name FROM edu_roles r JOIN edu_institutions ins ON ins.id = r.institution_id WHERE r.person_id = i.person_id AND r.role = "student" LIMIT 1) AS inst_name
                       FROM edu_ideas i JOIN edu_people p ON p.id = i.person_id WHERE ' . $w . ' ORDER BY i.applied_at IS NULL, i.updated_at DESC LIMIT 500', $types, $p);
}

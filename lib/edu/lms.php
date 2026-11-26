<?php
/**
 * Academic — courses (Phase 2): the learning side of the Campus portal.
 *
 * A course belongs to an organisation and is written on the staff desk. It
 * is offered to everyone, to one institution, or to one department, and can
 * be narrowed to a year of study. It is a list of lessons; a lesson is
 * reading, a video, a link, or a quiz of single-answer questions. A student
 * enrols, works through the lessons, passes the quizzes (the course's pass
 * mark), and gets a certificate with a code anyone can verify.
 *
 * Every time is written on PHP's clock (edu_now()), never the database's.
 */

require_once __DIR__ . '/core.php';
require_once __DIR__ . '/game.php';     // rewards: lessons, quizzes and courses pay XP and coins

const EDU_LMS_VERSION = 2;

function edu_lms_schema_sql(): array
{
    $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
"CREATE TABLE IF NOT EXISTS edu_courses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  title VARCHAR(190) NOT NULL,
  summary TEXT NULL,
  category VARCHAR(80) NOT NULL DEFAULT '',
  level ENUM('Beginner','Intermediate','Advanced') NOT NULL DEFAULT 'Beginner',
  colour TINYINT UNSIGNED NOT NULL DEFAULT 1,
  audience ENUM('all','institution','department') NOT NULL DEFAULT 'all',
  institution_id INT UNSIGNED NOT NULL DEFAULT 0,
  department_id INT UNSIGNED NOT NULL DEFAULT 0,
  years VARCHAR(20) NOT NULL DEFAULT '',
  pass_pct TINYINT UNSIGNED NOT NULL DEFAULT 60,
  status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  created_by VARCHAR(190) NOT NULL DEFAULT '',
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  KEY k_org (org_id, status)
) $t",

"CREATE TABLE IF NOT EXISTS edu_lessons (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  course_id INT UNSIGNED NOT NULL,
  sort SMALLINT NOT NULL DEFAULT 0,
  title VARCHAR(190) NOT NULL,
  kind ENUM('text','video','link','quiz') NOT NULL DEFAULT 'text',
  body MEDIUMTEXT NULL,
  url VARCHAR(500) NOT NULL DEFAULT '',
  minutes SMALLINT UNSIGNED NOT NULL DEFAULT 5,
  KEY k_course (course_id, sort)
) $t",

"CREATE TABLE IF NOT EXISTS edu_questions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  lesson_id INT UNSIGNED NOT NULL,
  sort SMALLINT NOT NULL DEFAULT 0,
  question TEXT NOT NULL,
  options_json TEXT NOT NULL,
  correct TINYINT UNSIGNED NOT NULL DEFAULT 0,
  explain_text TEXT NULL,
  KEY k_lesson (lesson_id, sort)
) $t",

"CREATE TABLE IF NOT EXISTS edu_enrolments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  course_id INT UNSIGNED NOT NULL,
  person_id INT UNSIGNED NOT NULL,
  enrolled_at DATETIME NOT NULL,
  progress TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_at DATETIME NULL,
  completed_at DATETIME NULL,
  cert_code CHAR(12) NULL,
  UNIQUE KEY u_enrol (course_id, person_id),
  UNIQUE KEY u_cert (cert_code),
  KEY k_person (org_id, person_id)
) $t",

"CREATE TABLE IF NOT EXISTS edu_lesson_done (
  lesson_id INT UNSIGNED NOT NULL,
  person_id INT UNSIGNED NOT NULL,
  org_id INT UNSIGNED NOT NULL,
  course_id INT UNSIGNED NOT NULL,
  done_at DATETIME NOT NULL,
  score TINYINT UNSIGNED NULL,
  PRIMARY KEY (lesson_id, person_id),
  KEY k_course (course_id, person_id)
) $t",

"CREATE TABLE IF NOT EXISTS edu_attempts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  lesson_id INT UNSIGNED NOT NULL,
  person_id INT UNSIGNED NOT NULL,
  answers_json TEXT NOT NULL,
  score TINYINT UNSIGNED NOT NULL,
  passed TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  KEY k_lesson (lesson_id, person_id)
) $t",
    ];
}

function edu_lms_install(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    edu_install();
    if ((int) fabo_setting('edu_lms_schema', '0') < EDU_LMS_VERSION) {
        edu_lms_create();
    }
    // The course builder's files, sections and outcomes (files.php).
    require_once __DIR__ . '/files.php';
    edu_builder_install();
    // Lessons as stacks of content blocks (blocks.php).
    require_once __DIR__ . '/blocks.php';
    edu_blocks_install();
}

function edu_lms_create(): void
{
    foreach (edu_lms_schema_sql() as $sql) {
        fabo_exec($sql);
    }
    require_once __DIR__ . '/mentoring.php';
    foreach (edu_mentoring_schema_sql() as $sql) {
        fabo_exec($sql);
    }
    fabo_exec('INSERT INTO app_settings (k, v) VALUES ("edu_lms_schema", ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', 's', [(string) EDU_LMS_VERSION]);
}

/* ================================================================ courses */

function edu_lesson_kinds(): array
{
    return ['text' => 'Reading', 'video' => 'Video', 'document' => 'Document', 'link' => 'Link', 'quiz' => 'Quiz'];
}

function edu_course(int $org, int $id): ?array
{
    return fabo_one('SELECT * FROM edu_courses WHERE org_id = ? AND id = ?', 'ii', [$org, $id]);
}

/** Every course in an organisation, with lesson, enrolment and completion counts. */
function edu_courses(int $org, ?string $status = null): array
{
    edu_lms_install();
    $rows = fabo_all('SELECT c.*,
                             (SELECT COUNT(*) FROM edu_lessons l WHERE l.course_id = c.id) AS lessons,
                             (SELECT COALESCE(SUM(l.minutes), 0) FROM edu_lessons l WHERE l.course_id = c.id) AS minutes_total,
                             (SELECT COUNT(*) FROM edu_enrolments e WHERE e.course_id = c.id) AS enrolled,
                             (SELECT COUNT(*) FROM edu_enrolments e WHERE e.course_id = c.id AND e.completed_at IS NOT NULL) AS completed
                        FROM edu_courses c WHERE c.org_id = ?' . ($status !== null ? ' AND c.status = ?' : '') . '
                       ORDER BY FIELD(c.status, "published", "draft", "archived"), c.title', $status !== null ? 'is' : 'i', $status !== null ? [$org, $status] : [$org]);
    return $rows;
}

function edu_course_save(int $org, array $d, string $actor, ?int $id = null): int
{
    $title = edu_clean($d['title'] ?? '', 190);
    if ($title === '') {
        throw new InvalidArgumentException('Give the course a title.');
    }
    $aud = in_array($d['audience'] ?? '', ['all', 'institution', 'department'], true) ? $d['audience'] : 'all';
    $inst = (int) ($d['institution_id'] ?? 0);
    $dept = (int) ($d['department_id'] ?? 0);
    if ($aud === 'all') {
        $inst = $dept = 0;
    } else {
        if (!$inst || !edu_institution($org, $inst)) {
            throw new InvalidArgumentException('Pick the institution the course is for.');
        }
        if ($aud === 'department') {
            $dd = $dept ? edu_department($org, $dept) : null;
            if (!$dd || (int) $dd['institution_id'] !== $inst) {
                throw new InvalidArgumentException('Pick a department of that institution.');
            }
        } else {
            $dept = 0;
        }
    }
    $years = implode(',', array_values(array_unique(array_filter(array_map('intval', (array) ($d['years'] ?? [])), static fn($y) => $y >= 1 && $y <= 6))));
    $pass = max(0, min(100, (int) ($d['pass_pct'] ?? 60)));
    $level = in_array($d['level'] ?? '', ['Beginner', 'Intermediate', 'Advanced'], true) ? $d['level'] : 'Beginner';
    $colour = max(1, min(8, (int) ($d['colour'] ?? 1)));
    $vals = [$title, mb_substr(trim((string) ($d['summary'] ?? '')), 0, 4000), edu_clean($d['category'] ?? '', 80), $level, $colour, $aud, $inst, $dept, $years, $pass];
    if ($id) {
        if (!edu_course($org, $id)) {
            throw new InvalidArgumentException('That course is not in this organisation.');
        }
        fabo_exec('UPDATE edu_courses SET title=?, summary=?, category=?, level=?, colour=?, audience=?, institution_id=?, department_id=?, years=?, pass_pct=?, updated_at=?
                    WHERE org_id = ? AND id = ?', 'ssssisiisisii', array_merge($vals, [edu_now(), $org, $id]));
    } else {
        $id = fabo_exec('INSERT INTO edu_courses (title, summary, category, level, colour, audience, institution_id, department_id, years, pass_pct, org_id, created_by, created_at, updated_at)
                          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)', 'ssssisiisiisss', array_merge($vals, [$org, mb_substr($actor, 0, 190), edu_now(), edu_now()]));
    }
    // What students will be able to do, and what they need first: one per line, only when the form sends them.
    foreach (['outcomes', 'prerequisites'] as $k) {
        if (array_key_exists($k, $d)) {
            $lines = array_slice(array_values(array_filter(array_map(static fn($x) => edu_clean($x, 200), preg_split('/\R/', (string) $d[$k])))), 0, 12);
            fabo_exec("UPDATE edu_courses SET `$k` = ? WHERE org_id = ? AND id = ?", 'sii', [implode("
", $lines), $org, $id]);
        }
    }
    return $id;
}

/** Why a course cannot be published yet, or null when it can. */
function edu_course_unready(int $org, int $id): ?string
{
    $lessons = edu_lessons($org, $id);
    if (!$lessons) {
        return 'Add at least one lesson first.';
    }
    foreach ($lessons as $l) {
        if ($why = edu_lesson_problem($org, $l)) {
            return $why;
        }
    }
    return null;
}

function edu_course_status(int $org, int $id, string $status): void
{
    if (!in_array($status, ['draft', 'published', 'archived'], true) || !edu_course($org, $id)) {
        throw new InvalidArgumentException('Nothing to change.');
    }
    if ($status === 'published' && ($why = edu_course_unready($org, $id))) {
        throw new InvalidArgumentException('Not published: ' . $why);
    }
    fabo_exec('UPDATE edu_courses SET status = ?, updated_at = ? WHERE org_id = ? AND id = ?', 'ssii', [$status, edu_now(), $org, $id]);
}

/** Removes a course with its lessons and everyone's progress. Only a course nobody has finished, or with the word typed. */
function edu_course_delete(int $org, int $id): void
{
    if (!edu_course($org, $id)) {
        throw new InvalidArgumentException('That course is not in this organisation.');
    }
    $lessonIds = array_map(static fn($l) => (int) $l['id'], edu_lessons($org, $id));
    if ($lessonIds) {
        $in = implode(',', $lessonIds);
        fabo_exec("DELETE FROM edu_questions WHERE org_id = ? AND lesson_id IN ($in)", 'i', [$org]);
        fabo_exec("DELETE FROM edu_attempts WHERE org_id = ? AND lesson_id IN ($in)", 'i', [$org]);
    }
    fabo_exec('DELETE FROM edu_lesson_done WHERE org_id = ? AND course_id = ?', 'ii', [$org, $id]);
    edu_files_purge($org, $id);
    fabo_exec('DELETE b FROM edu_blocks b JOIN edu_lessons l ON l.id = b.lesson_id WHERE l.org_id = ? AND l.course_id = ?', 'ii', [$org, $id]);
    fabo_exec('DELETE FROM edu_lessons WHERE org_id = ? AND course_id = ?', 'ii', [$org, $id]);
    fabo_exec('DELETE FROM edu_enrolments WHERE org_id = ? AND course_id = ?', 'ii', [$org, $id]);
    fabo_exec('DELETE FROM edu_courses WHERE org_id = ? AND id = ?', 'ii', [$org, $id]);
}

/** Who a course is for, in words. */
function edu_course_audience_words(int $org, array $c): string
{
    $w = 'Everyone';
    if ($c['audience'] === 'institution') {
        $w = (string) (edu_institution($org, (int) $c['institution_id'])['name'] ?? 'One institution');
    } elseif ($c['audience'] === 'department') {
        $d = edu_department($org, (int) $c['department_id']);
        $w = ($d['name'] ?? 'One department') . ', ' . (edu_institution($org, (int) $c['institution_id'])['name'] ?? '');
    }
    if ($c['years'] !== '') {
        $w .= ' · year ' . str_replace(',', ', ', $c['years']);
    }
    return $w;
}

/* ================================================================ lessons */

function edu_lessons(int $org, int $courseId): array
{
    return fabo_all('SELECT * FROM edu_lessons WHERE org_id = ? AND course_id = ? ORDER BY sort, id', 'ii', [$org, $courseId]);
}

function edu_lesson(int $org, int $id): ?array
{
    return fabo_one('SELECT * FROM edu_lessons WHERE org_id = ? AND id = ?', 'ii', [$org, $id]);
}

function edu_lesson_save(int $org, int $courseId, array $d, ?int $id = null): int
{
    if (!edu_course($org, $courseId)) {
        throw new InvalidArgumentException('That course is not in this organisation.');
    }
    $title = edu_clean($d['title'] ?? '', 190);
    if ($title === '') {
        throw new InvalidArgumentException('Give the lesson a title.');
    }
    $kind = isset(edu_lesson_kinds()[$d['kind'] ?? '']) ? $d['kind'] : 'text';
    $url = trim((string) ($d['url'] ?? ''));
    if ($url !== '' && !preg_match('#^https://[^\s<>"]+$#i', $url)) {
        throw new InvalidArgumentException('Addresses must start with https://');
    }
    if ($kind === 'video' && $url !== '' && edu_video_embed($url) === null) {
        throw new InvalidArgumentException('Use a YouTube or Vimeo address for a video lesson (or make it a Link lesson).');
    }
    $minutes = max(1, min(600, (int) ($d['minutes'] ?? 5)));
    $body = mb_substr((string) ($d['body'] ?? ''), 0, 60000);
    if ($id) {
        $l = edu_lesson($org, $id);
        if (!$l || (int) $l['course_id'] !== $courseId) {
            throw new InvalidArgumentException('That lesson is not in this course.');
        }
        fabo_exec('UPDATE edu_lessons SET title=?, kind=?, body=?, url=?, minutes=? WHERE org_id = ? AND id = ?', 'ssssiii', [$title, $kind, $body, mb_substr($url, 0, 500), $minutes, $org, $id]);
        if (array_key_exists('section', $d)) {
            fabo_exec('UPDATE edu_lessons SET section = ? WHERE org_id = ? AND id = ?', 'sii', [edu_clean((string) $d['section'], 120), $org, $id]);
        }
    } else {
        $sort = (int) (fabo_one('SELECT COALESCE(MAX(sort), 0) + 1 AS s FROM edu_lessons WHERE course_id = ?', 'i', [$courseId])['s'] ?? 1);
        $id = fabo_exec('INSERT INTO edu_lessons (org_id, course_id, sort, title, kind, body, url, minutes) VALUES (?,?,?,?,?,?,?,?)',
                        'iiissssi', [$org, $courseId, $sort, $title, $kind, $body, mb_substr($url, 0, 500), $minutes]);
        // A new lesson joins the section it is added to, or the last section.
        $sec = array_key_exists('section', $d) ? edu_clean((string) $d['section'], 120)
             : (string) (fabo_one('SELECT section FROM edu_lessons WHERE org_id = ? AND course_id = ? AND id <> ? ORDER BY sort DESC, id DESC LIMIT 1', 'iii', [$org, $courseId, $id])['section'] ?? '');
        fabo_exec('UPDATE edu_lessons SET section = ? WHERE id = ?', 'si', [$sec, $id]);
    }
    edu_recount_course($org, $courseId);
    return $id;
}

function edu_lesson_delete(int $org, int $id): int
{
    $l = edu_lesson($org, $id);
    if (!$l) {
        throw new InvalidArgumentException('That lesson is not here.');
    }
    fabo_exec('DELETE FROM edu_questions WHERE org_id = ? AND lesson_id = ?', 'ii', [$org, $id]);
    fabo_exec('DELETE FROM edu_attempts WHERE org_id = ? AND lesson_id = ?', 'ii', [$org, $id]);
    fabo_exec('DELETE FROM edu_lesson_done WHERE org_id = ? AND lesson_id = ?', 'ii', [$org, $id]);
    edu_files_purge($org, (int) $l['course_id'], $id);
    edu_blocks_purge($org, $id);
    fabo_exec('DELETE FROM edu_lessons WHERE org_id = ? AND id = ?', 'ii', [$org, $id]);
    edu_recount_course($org, (int) $l['course_id']);
    return (int) $l['course_id'];
}

/** Moves a lesson one place up (-1) or down (+1). */
function edu_lesson_move(int $org, int $id, int $dir): int
{
    $l = edu_lesson($org, $id);
    if (!$l) {
        throw new InvalidArgumentException('That lesson is not here.');
    }
    $all = edu_lessons($org, (int) $l['course_id']);
    $ids = array_map(static fn($x) => (int) $x['id'], $all);
    $i = array_search($id, $ids, true);
    $j = $i + ($dir < 0 ? -1 : 1);
    if ($j >= 0 && $j < count($ids)) {
        [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
        foreach ($ids as $n => $lid) {
            fabo_exec('UPDATE edu_lessons SET sort = ? WHERE id = ?', 'ii', [$n + 1, $lid]);
        }
    }
    return (int) $l['course_id'];
}

/** A YouTube or Vimeo address as an embeddable player address, or null. */
function edu_video_embed(string $url): ?string
{
    if (preg_match('#^https://(?:www\.|m\.)?(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})#', $url, $m)) {
        return 'https://www.youtube-nocookie.com/embed/' . $m[1] . '?rel=0';
    }
    if (preg_match('#^https://(?:www\.|player\.)?vimeo\.com/(?:video/)?(\d{5,12})#', $url, $m)) {
        return 'https://player.vimeo.com/video/' . $m[1];
    }
    return null;
}

/**
 * Lesson text as safe HTML: escaped first, then a small set of marks --
 * "# " and "## " headings, "- " and "1. " lists, **bold**, *italic*,
 * `code`, and https links written [words](https://...) or bare.
 */
function edu_render_text(string $src): string
{
    $out = '';
    $list = '';
    $para = [];
    $flushPara = static function () use (&$para, &$out) {
        if ($para) {
            $out .= '<p>' . implode('<br>', $para) . '</p>';
            $para = [];
        }
    };
    $closeList = static function () use (&$list, &$out) {
        if ($list !== '') {
            $out .= '</' . $list . '>';
            $list = '';
        }
    };
    $inline = static function (string $s): string {
        $s = htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $s = preg_replace('/`([^`]+)`/', '<code>$1</code>', $s);
        $s = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $s);
        $s = preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/', '<em>$1</em>', $s);
        $s = preg_replace('#\[([^\]]+)\]\((https://[^\s)&]+(?:&amp;[^\s)&]+)*)\)#', '<a href="$2" target="_blank" rel="noopener nofollow">$1</a>', $s);
        $s = preg_replace('#(?<!href=")(?<!">)\b(https://[^\s<]+[^\s<.,;:!?)])#', '<a href="$1" target="_blank" rel="noopener nofollow">$1</a>', $s);
        return $s;
    };
    foreach (preg_split('/\R/', $src) as $line) {
        $t = rtrim($line);
        if (trim($t) === '') {
            $flushPara();
            $closeList();
            continue;
        }
        if (preg_match('/^(#{1,3})\s+(.+)$/', $t, $m)) {
            $flushPara();
            $closeList();
            $lvl = strlen($m[1]) + 1;
            $out .= '<h' . $lvl . '>' . $inline($m[2]) . '</h' . $lvl . '>';
            continue;
        }
        if (preg_match('/^\s*(?:[-*•])\s+(.+)$/u', $t, $m) || preg_match('/^\s*\d+[.)]\s+(.+)$/', $t, $n)) {
            $flushPara();
            $want = isset($n[1]) && !isset($m[1]) ? 'ol' : 'ul';
            if ($list !== $want) {
                $closeList();
                $out .= '<' . $want . '>';
                $list = $want;
            }
            $out .= '<li>' . $inline($m[1] ?? $n[1]) . '</li>';
            unset($m, $n);
            continue;
        }
        unset($m, $n);
        $closeList();
        $para[] = $inline($t);
    }
    $flushPara();
    $closeList();
    return $out;
}

/** The lesson's content as a student sees it (without the controls). */
function edu_lesson_preview(array $l): void
{
    if ($l['kind'] === 'video' && ($src = edu_video_embed((string) $l['url']))) {
        echo '<div class="cp-video"><iframe src="' . e($src) . '" title="' . e($l['title']) . '" allow="accelerometer; encrypted-media; gyroscope; picture-in-picture; fullscreen" allowfullscreen loading="lazy" referrerpolicy="strict-origin-when-cross-origin"></iframe></div>';
    }
    if ($l['kind'] === 'link' && $l['url'] !== '') {
        echo '<p><a class="btn btn-primary" href="' . e($l['url']) . '" target="_blank" rel="noopener nofollow">Open ' . e(parse_url($l['url'], PHP_URL_HOST) ?: 'the page') . ' ↗</a></p>';
    }
    echo edu_render_text((string) $l['body']);
}

/* ============================================================== questions */

function edu_questions(int $org, int $lessonId): array
{
    $rows = fabo_all('SELECT * FROM edu_questions WHERE org_id = ? AND lesson_id = ? ORDER BY sort, id', 'ii', [$org, $lessonId]);
    foreach ($rows as &$r) {
        $r['options'] = json_decode((string) $r['options_json'], true) ?: [];
    }
    unset($r);
    return $rows;
}

function edu_question_save(int $org, int $lessonId, array $d, ?int $id = null): int
{
    $l = edu_lesson($org, $lessonId);
    if (!$l || $l['kind'] !== 'quiz') {
        throw new InvalidArgumentException('Questions go on a quiz lesson.');
    }
    $q = trim((string) ($d['question'] ?? ''));
    if ($q === '') {
        throw new InvalidArgumentException('Write the question.');
    }
    $opts = [];
    $correctIn = (int) ($d['correct'] ?? -1);
    $correct = -1;
    foreach (array_values((array) ($d['options'] ?? [])) as $i => $o) {
        $o = edu_clean((string) $o, 300);
        if ($o !== '') {
            if ($i === $correctIn) {
                $correct = count($opts);
            }
            $opts[] = $o;
        }
    }
    if (count($opts) < 2) {
        throw new InvalidArgumentException('Give at least two answers.');
    }
    if ($correct < 0) {
        throw new InvalidArgumentException('Mark which answer is right.');
    }
    $vals = [mb_substr($q, 0, 2000), json_encode($opts, JSON_UNESCAPED_UNICODE), $correct, mb_substr(trim((string) ($d['explain'] ?? '')), 0, 2000)];
    if ($id) {
        fabo_exec('UPDATE edu_questions SET question=?, options_json=?, correct=?, explain_text=? WHERE org_id = ? AND lesson_id = ? AND id = ?', 'ssisiii', array_merge($vals, [$org, $lessonId, $id]));
        return $id;
    }
    $sort = (int) (fabo_one('SELECT COALESCE(MAX(sort), 0) + 1 AS s FROM edu_questions WHERE lesson_id = ?', 'i', [$lessonId])['s'] ?? 1);
    return fabo_exec('INSERT INTO edu_questions (question, options_json, correct, explain_text, org_id, lesson_id, sort) VALUES (?,?,?,?,?,?,?)', 'ssisiii', array_merge($vals, [$org, $lessonId, $sort]));
}

function edu_question_delete(int $org, int $lessonId, int $id): void
{
    fabo_exec('DELETE FROM edu_questions WHERE org_id = ? AND lesson_id = ? AND id = ?', 'iii', [$org, $lessonId, $id]);
}

/* ================================================ students and their courses */

/** A student's student roles: [[institution_id, department_id], ...] and their year. */
function edu_student_places(int $org, array $person): array
{
    $out = [];
    foreach (fabo_all('SELECT institution_id, department_id FROM edu_roles WHERE org_id = ? AND person_id = ? AND role = "student"', 'ii', [$org, (int) $person['id']]) as $r) {
        $out[] = [(int) $r['institution_id'], (int) $r['department_id']];
    }
    return $out;
}

/** May this student see (and enrol on) this course? Published, for them, their year. */
function edu_course_for(int $org, array $course, array $person): bool
{
    if ($course['status'] !== 'published' || (int) $course['org_id'] !== $org) {
        return false;
    }
    $places = edu_student_places($org, $person);
    if (!$places) {
        return false;
    }
    if ($course['years'] !== '' && !in_array((int) ($person['year_of_study'] ?? 0), array_map('intval', explode(',', $course['years'])), true)) {
        return false;
    }
    foreach ($places as [$inst, $dept]) {
        if ($course['audience'] === 'all'
            || ($course['audience'] === 'institution' && $inst === (int) $course['institution_id'])
            || ($course['audience'] === 'department' && $dept === (int) $course['department_id'])) {
            return true;
        }
    }
    return false;
}

/** The published courses a student may take, each with their enrolment if they have one. */
function edu_catalogue(int $org, array $person): array
{
    $out = [];
    $mine = [];
    foreach (fabo_all('SELECT * FROM edu_enrolments WHERE org_id = ? AND person_id = ?', 'ii', [$org, (int) $person['id']]) as $e) {
        $mine[(int) $e['course_id']] = $e;
    }
    foreach (edu_courses($org, 'published') as $c) {
        if (edu_course_for($org, $c, $person)) {
            $c['enrolment'] = $mine[(int) $c['id']] ?? null;
            $out[] = $c;
        }
    }
    return $out;
}

function edu_enrolment(int $org, int $courseId, int $pid): ?array
{
    return fabo_one('SELECT * FROM edu_enrolments WHERE org_id = ? AND course_id = ? AND person_id = ?', 'iii', [$org, $courseId, $pid]);
}

function edu_enrol(int $org, array $course, array $person): void
{
    if (!edu_course_for($org, $course, $person)) {
        throw new InvalidArgumentException('This course is not open to you.');
    }
    fabo_exec('INSERT IGNORE INTO edu_enrolments (org_id, course_id, person_id, enrolled_at, last_at) VALUES (?,?,?,?,?)',
              'iiiss', [$org, (int) $course['id'], (int) $person['id'], edu_now(), edu_now()]);
}

/** The lessons this student has finished in a course: lesson_id => row. */
function edu_done_map(int $org, int $courseId, int $pid): array
{
    $out = [];
    foreach (fabo_all('SELECT * FROM edu_lesson_done WHERE org_id = ? AND course_id = ? AND person_id = ?', 'iii', [$org, $courseId, $pid]) as $r) {
        $out[(int) $r['lesson_id']] = $r;
    }
    return $out;
}

/**
 * Works out a student's progress again, and completes the course (with a
 * certificate code) when every lesson is done. Returns the enrolment.
 */
function edu_progress_update(int $org, int $courseId, int $pid): ?array
{
    $e = edu_enrolment($org, $courseId, $pid);
    if (!$e) {
        return null;
    }
    $lessons = edu_lessons($org, $courseId);
    $done = edu_done_map($org, $courseId, $pid);
    $n = count($lessons);
    $have = count(array_filter($lessons, static fn($l) => isset($done[(int) $l['id']])));
    $pct = $n ? (int) floor($have * 100 / $n) : 0;
    $complete = $n > 0 && $have === $n;
    if ($complete && $e['completed_at'] === null) {
        do {
            $code = strtoupper(substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(12))), 0, 12));
        } while (fabo_one('SELECT id FROM edu_enrolments WHERE cert_code = ?', 's', [$code]));
        fabo_exec('UPDATE edu_enrolments SET progress = 100, last_at = ?, completed_at = ?, cert_code = ? WHERE id = ?', 'sssi', [edu_now(), edu_now(), $code, (int) $e['id']]);
        edu_award($org, $pid, 'course', 'course:' . $courseId);
    } else {
        // A finished course stays finished (and its certificate valid) when a lesson is added later.
        fabo_exec('UPDATE edu_enrolments SET progress = ?, last_at = ? WHERE id = ?', 'isi', [$e['completed_at'] !== null ? 100 : $pct, edu_now(), (int) $e['id']]);
    }
    return edu_enrolment($org, $courseId, $pid);
}

/** After a lesson is added or removed: progress for everyone on the course. */
function edu_recount_course(int $org, int $courseId): void
{
    foreach (fabo_all('SELECT person_id FROM edu_enrolments WHERE org_id = ? AND course_id = ? AND completed_at IS NULL', 'ii', [$org, $courseId]) as $r) {
        edu_progress_update($org, $courseId, (int) $r['person_id']);
    }
}

/** Marks a reading, video or link lesson done. */
function edu_lesson_complete(int $org, array $lesson, array $person): array
{
    if ($lesson['kind'] === 'quiz') {
        throw new InvalidArgumentException('A quiz is done by passing it.');
    }
    if (!edu_enrolment($org, (int) $lesson['course_id'], (int) $person['id'])) {
        throw new InvalidArgumentException('Enrol on the course first.');
    }
    fabo_exec('INSERT IGNORE INTO edu_lesson_done (lesson_id, person_id, org_id, course_id, done_at) VALUES (?,?,?,?,?)',
              'iiiis', [(int) $lesson['id'], (int) $person['id'], $org, (int) $lesson['course_id'], edu_now()]);
    edu_game_day($org, (int) $person['id']);
    edu_award($org, (int) $person['id'], 'lesson', 'lesson:' . (int) $lesson['id']);
    return edu_progress_update($org, (int) $lesson['course_id'], (int) $person['id']);
}

/**
 * Marks a quiz. $answers: question id => chosen index. Returns
 * ['score' => %, 'passed' => bool, 'right' => [qid => bool], 'enrolment' => row].
 * The best score is kept; once passed, a quiz stays passed.
 */
function edu_quiz_submit(int $org, array $lesson, array $person, array $answers): array
{
    if ($lesson['kind'] !== 'quiz') {
        throw new InvalidArgumentException('That is not a quiz.');
    }
    $course = edu_course($org, (int) $lesson['course_id']);
    if (!$course || !edu_enrolment($org, (int) $course['id'], (int) $person['id'])) {
        throw new InvalidArgumentException('Enrol on the course first.');
    }
    $qs = edu_questions($org, (int) $lesson['id']);
    if (!$qs) {
        throw new InvalidArgumentException('This quiz has no questions yet.');
    }
    $right = [];
    $clean = [];
    foreach ($qs as $q) {
        $a = isset($answers[$q['id']]) && $answers[$q['id']] !== '' ? (int) $answers[$q['id']] : -1;
        $clean[(int) $q['id']] = $a;
        $right[(int) $q['id']] = $a === (int) $q['correct'];
    }
    $score = (int) round(count(array_filter($right)) * 100 / count($qs));
    $passed = $score >= (int) $course['pass_pct'];
    fabo_exec('INSERT INTO edu_attempts (org_id, lesson_id, person_id, answers_json, score, passed, created_at) VALUES (?,?,?,?,?,?,?)',
              'iiisiis', [$org, (int) $lesson['id'], (int) $person['id'], json_encode($clean), $score, $passed ? 1 : 0, edu_now()]);
    if ($passed) {
        fabo_exec('INSERT INTO edu_lesson_done (lesson_id, person_id, org_id, course_id, done_at, score) VALUES (?,?,?,?,?,?)
                   ON DUPLICATE KEY UPDATE score = GREATEST(COALESCE(score, 0), VALUES(score))',
                  'iiiisi', [(int) $lesson['id'], (int) $person['id'], $org, (int) $course['id'], edu_now(), $score]);
        edu_award($org, (int) $person['id'], 'quiz', 'lesson:' . (int) $lesson['id']);
        if ($score === 100) {
            edu_award($org, (int) $person['id'], 'perfect', 'lesson:' . (int) $lesson['id']);
        }
    }
    edu_game_day($org, (int) $person['id']);
    return ['score' => $score, 'passed' => $passed, 'right' => $right, 'answers' => $clean,
            'enrolment' => edu_progress_update($org, (int) $course['id'], (int) $person['id'])];
}

/** A student's best score and attempt count on one quiz. */
function edu_quiz_best(int $org, int $lessonId, int $pid): array
{
    $r = fabo_one('SELECT MAX(score) AS best, COUNT(*) AS n FROM edu_attempts WHERE org_id = ? AND lesson_id = ? AND person_id = ?', 'iii', [$org, $lessonId, $pid]);
    return ['best' => $r['best'] === null ? null : (int) $r['best'], 'attempts' => (int) ($r['n'] ?? 0)];
}

/** A certificate by its code, with what it certifies, or null. */
function edu_certificate(string $code): ?array
{
    if (!preg_match('/^[A-Z0-9]{12}$/', $code)) {
        return null;
    }
    edu_lms_install();
    return fabo_one('SELECT e.*, p.name AS person_name, c.title AS course_title, c.org_id AS course_org
                       FROM edu_enrolments e JOIN edu_people p ON p.id = e.person_id JOIN edu_courses c ON c.id = e.course_id
                      WHERE e.cert_code = ? AND e.completed_at IS NOT NULL', 's', [$code]);
}

/* ================================================================ results */

/** Everyone on a course, with their progress and best quiz average. */
function edu_course_results(int $org, int $courseId): array
{
    return fabo_all('SELECT e.*, p.name, p.email, p.roll_no,
                            (SELECT ROUND(AVG(d.score)) FROM edu_lesson_done d WHERE d.course_id = e.course_id AND d.person_id = e.person_id AND d.score IS NOT NULL) AS quiz_avg,
                            (SELECT GROUP_CONCAT(DISTINCT i.name SEPARATOR "; ") FROM edu_roles r JOIN edu_institutions i ON i.id = r.institution_id
                              WHERE r.person_id = e.person_id AND r.role = "student") AS inst_names
                       FROM edu_enrolments e JOIN edu_people p ON p.id = e.person_id
                      WHERE e.org_id = ? AND e.course_id = ?
                   ORDER BY e.completed_at IS NULL, e.progress DESC, p.name', 'ii', [$org, $courseId]);
}

/**
 * Learning for a set of students (faculty and institution admins): per
 * course, how many of them are enrolled and have finished.
 */
function edu_learning_summary(int $org, array $studentIds): array
{
    $ids = array_values(array_filter(array_map('intval', $studentIds)));
    if (!$ids) {
        return [];
    }
    $in = implode(',', $ids);
    return fabo_all("SELECT c.id, c.title, c.colour, COUNT(e.id) AS enrolled, SUM(e.completed_at IS NOT NULL) AS completed, ROUND(AVG(e.progress)) AS avg_progress
                       FROM edu_enrolments e JOIN edu_courses c ON c.id = e.course_id
                      WHERE e.org_id = ? AND e.person_id IN ($in)
                   GROUP BY c.id, c.title, c.colour ORDER BY enrolled DESC, c.title", 'i', [$org]);
}

/** A student's courses, for their mentors and faculty: title, progress, done. */
function edu_student_learning(int $org, int $pid): array
{
    return fabo_all('SELECT c.title, e.progress, e.completed_at FROM edu_enrolments e JOIN edu_courses c ON c.id = e.course_id
                      WHERE e.org_id = ? AND e.person_id = ? ORDER BY e.completed_at IS NULL DESC, e.last_at DESC', 'ii', [$org, $pid]);
}

/* ============================================================ sample course */

/** One finished-looking course to explore: reading, a video, a link and a quiz. Published, for everyone. */
function edu_sample_course(int $org, string $actor): int
{
    $cid = edu_course_save($org, ['title' => 'Startup Foundations: from idea to first customer', 'category' => 'Entrepreneurship', 'level' => 'Beginner', 'colour' => 3,
        'summary' => 'Find a problem worth solving, test it with real people, and take your first step towards incubation. Five short lessons and a quiz.',
        'audience' => 'all', 'pass_pct' => 60], $actor);
    edu_lesson_save($org, $cid, ['title' => 'What makes a good startup idea', 'kind' => 'text', 'minutes' => 6, 'body' =>
        "# Start with a problem, not a product\n\nThe best ideas come from a **painful, frequent problem** that a clear group of people already try to solve.\n\nAsk yourself:\n- Who exactly has this problem?\n- How do they solve it today, and what does that cost them?\n- Would they pay, or change a habit, for something better?\n\n## A quick test\n1. Write the problem in one sentence.\n2. Name ten people who have it.\n3. Talk to five of them this week.\n\nIf nobody recognises the problem, *change the problem*, not the pitch."]);
    edu_lesson_save($org, $cid, ['title' => 'How to talk to users', 'kind' => 'video', 'minutes' => 12, 'url' => 'https://www.youtube.com/watch?v=MT4Ig2uqjTc',
        'body' => 'Watch this talk, then write down three questions you will ask in your own interviews.']);
    edu_lesson_save($org, $cid, ['title' => 'Validate before you build', 'kind' => 'text', 'minutes' => 5, 'body' =>
        "Building is the most expensive way to learn.\n\n## Cheaper ways to test\n- A one-page website with a sign-up button\n- A hand-made version of the service for three customers\n- A pre-order or letter of intent\n\nMeasure what people **do**, not what they say."]);
    edu_lesson_save($org, $cid, ['title' => 'Startup India: recognition and support', 'kind' => 'link', 'minutes' => 8, 'url' => 'https://www.startupindia.gov.in/',
        'body' => 'Read how DPIIT recognition works and which benefits it opens up. Note two schemes that could help your idea.']);
    $quiz = edu_lesson_save($org, $cid, ['title' => 'Check your understanding', 'kind' => 'quiz', 'minutes' => 5, 'body' => 'Five questions. You need 60% to pass; you can try again.']);
    foreach ([
        ['What should a startup idea start from?', ['A product you like', 'A painful, frequent problem', 'A large market report', 'A competitor\'s app'], 1, 'Problems people already feel are what they will pay to solve.'],
        ['What is the cheapest way to learn whether people want something?', ['Build the full product', 'Hire a sales team', 'Test it before building — a landing page or a manual version', 'Run TV ads'], 2, 'Building is the most expensive way to learn.'],
        ['In user interviews you should mostly ask about…', ['Their past behaviour', 'Whether they like your idea', 'What features they want', 'Your pricing'], 0, 'Past behaviour is evidence; opinions about the future are not.'],
        ['What does DPIIT recognition give a startup in India?', ['Guaranteed funding', 'Access to Startup India benefits and schemes', 'A free office', 'A patent'], 1, 'Recognition opens tax, IPR and procurement benefits — not guaranteed money.'],
        ['Which signal is strongest?', ['"I would use this"', 'A like on social media', 'A pre-order or payment', 'A compliment from a friend'], 2, 'Money and commitments beat words.'],
    ] as [$q, $o, $c, $x]) {
        edu_question_save($org, $quiz, ['question' => $q, 'options' => $o, 'correct' => $c, 'explain' => $x]);
    }
    edu_course_status($org, $cid, 'published');
    return $cid;
}

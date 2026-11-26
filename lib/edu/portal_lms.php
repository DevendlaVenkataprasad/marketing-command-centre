<?php
/**
 * Campus portal — learning and mentoring (Phase 2). Loaded by lib/edu/portal.php.
 *
 *   Student            Home · Courses (catalogue, course, lesson, quiz, certificate) · Mentoring (book, cancel, rate)
 *   Mentor             Home (their students and how they are learning) · Sessions (offer, cancel, record)
 *   Faculty / Inst.    Home · Learning (how their students are doing, course by course)
 *
 * ?verify=<code> checks a certificate without signing in.
 */

require_once __DIR__ . '/lms.php';
require_once __DIR__ . '/mentoring.php';
require_once __DIR__ . '/files.php';

/** A course file's address in the Campus portal (access is checked when it is opened). */
function edu_pfile_url(array $f, bool $dl = false): string
{
    return edu_purl(['v' => 'file', 'id' => (int) $f['id']] + ($dl ? ['dl' => 1] : []));
}

/** Sends a course file to a Campus person who may have it, else 404. */
function edu_portal_file(int $org, array $me): void
{
    $f = edu_file($org, (int) ($_GET['id'] ?? 0));
    if (!$f || !edu_file_allowed_for($org, $f, $me)) {
        http_response_code(404);
        exit('This file is not open to you.');
    }
    edu_file_send($f, !empty($_GET['dl']));
}

/** The views each role may open: view => tab label ('' = not a tab). */
function edu_portal_views(string $role): array
{
    return [
        'student'    => ['home' => 'Home', 'courses' => 'Courses', 'mentoring' => 'Mentoring']
                        + (edu_game_on((int) ($GLOBALS['edu_porg'] ?? 0)) ? ['rewards' => 'Rewards', 'rankings' => 'Rankings', 'store' => 'Store'] : [])
                        + ['ideas' => 'Ideas', 'course' => '', 'lesson' => '', 'cert' => '', 'idea' => '', 'apply' => ''],
        'mentor'     => ['home' => 'My students', 'sessions' => 'Sessions'],
        'faculty'    => ['home' => 'Home', 'learning' => 'Learning'],
        'inst_admin' => ['home' => 'Home', 'learning' => 'Learning', 'ideas' => 'Ideas', 'reports' => 'Reports'],
        'council'    => ['home' => 'Overview', 'reports' => 'Reports', 'rankings' => 'Rankings'],
    ][$role] ?? ['home' => 'Home'];
}

function edu_portal_tabs(string $role, string $view): string
{
    $h = '<nav class="cp-tabs" aria-label="Sections">';
    $current = in_array($view, ['course', 'lesson', 'cert'], true) ? 'courses' : (in_array($view, ['idea', 'apply'], true) ? 'ideas' : $view);
    foreach (edu_portal_views($role) as $v => $label) {
        if ($label !== '') {
            $h .= '<a href="' . e(edu_purl($v === 'home' ? [] : ['v' => $v])) . '"' . ($v === $current ? ' class="on" aria-current="page"' : '') . '>' . e($label) . '</a>';
        }
    }
    return $h . '</nav>';
}

/* ================================================================== POST */

/** Learning and mentoring actions. Redirects when it handles one. */
function edu_portal_post_lms(int $org, array $me, array $role, string $a): void
{
    $back = edu_purl(array_filter(['v' => (string) ($_GET['v'] ?? ''), 'id' => (int) ($_GET['id'] ?? 0) ?: null]));
    try {
        if ($role['role'] === 'student') {
            switch ($a) {
                case 'enrol':
                    $c = edu_course($org, (int) ($_POST['course'] ?? 0));
                    if (!$c) {
                        throw new InvalidArgumentException('That course is not here.');
                    }
                    edu_enrol($org, $c, $me);
                    $first = edu_lessons($org, (int) $c['id'])[0] ?? null;
                    edu_pflash('ok', 'You are on the course. Start with the first lesson.');
                    $back = edu_purl($first ? ['v' => 'lesson', 'id' => (int) $first['id']] : ['v' => 'course', 'id' => (int) $c['id']]);
                    break;
                case 'lesson-done':
                    $l = edu_portal_lesson_for($org, $me, (int) ($_POST['lesson'] ?? 0));
                    $e = edu_lesson_complete($org, $l, $me);
                    $next = edu_next_lesson($org, $l);
                    if ($e && $e['completed_at'] !== null && !$next) {
                        edu_pflash('ok', 'Course finished. Your certificate is ready.');
                        $back = edu_purl(['v' => 'cert', 'id' => (int) $l['course_id']]);
                    } else {
                        $back = $next ? edu_purl(['v' => 'lesson', 'id' => (int) $next['id']]) : edu_purl(['v' => 'course', 'id' => (int) $l['course_id']]);
                    }
                    break;
                case 'quiz':
                    $l = edu_portal_lesson_for($org, $me, (int) ($_POST['lesson'] ?? 0));
                    $r = edu_quiz_submit($org, $l, $me, (array) ($_POST['q'] ?? []));
                    $_SESSION['edu_quiz'][(int) $l['id']] = ['answers' => $r['answers'], 'right' => $r['right'], 'score' => $r['score'], 'passed' => $r['passed']];
                    edu_pflash($r['passed'] ? 'ok' : 'err', $r['passed'] ? 'Passed with ' . $r['score'] . '%.' . ($r['enrolment']['completed_at'] ?? null ? ' Course finished: your certificate is ready.' : '')
                                                               : $r['score'] . '%. You need ' . (int) edu_course($org, (int) $l['course_id'])['pass_pct'] . '% to pass. Look at the answers below and try again.');
                    $back = edu_purl(['v' => 'lesson', 'id' => (int) $l['id']]) . '#quiz';
                    break;
                case 'book':
                    $s = edu_slot_book($org, (int) ($_POST['slot'] ?? 0), $me, (string) ($_POST['topic'] ?? ''));
                    edu_pflash('ok', 'Booked: ' . edu_slot_when($s) . '. Your mentor has been told.');
                    $back = edu_purl(['v' => 'mentoring']);
                    break;
                case 'cancel':
                    edu_slot_cancel($org, (int) ($_POST['slot'] ?? 0), $me, 'student');
                    edu_pflash('ok', 'Cancelled. The time is free for others again.');
                    $back = edu_purl(['v' => 'mentoring']);
                    break;
                case 'rate':
                    edu_slot_rate($org, (int) ($_POST['slot'] ?? 0), (int) $me['id'], (int) ($_POST['rating'] ?? 0), (string) ($_POST['feedback'] ?? ''));
                    edu_pflash('ok', 'Thank you for the feedback.');
                    $back = edu_purl(['v' => 'mentoring']);
                    break;
                default:
                    return;
            }
        } elseif ($role['role'] === 'mentor') {
            switch ($a) {
                case 'offer':
                    $n = edu_slot_add($org, (int) $me['id'], trim((string) ($_POST['date'] ?? '')) . ' ' . trim((string) ($_POST['time'] ?? '')),
                                      (int) ($_POST['minutes'] ?? 30), (string) ($_POST['mode'] ?? 'online'), (string) ($_POST['place'] ?? ''), (int) ($_POST['weeks'] ?? 1));
                    edu_pflash('ok', $n === 1 ? 'Session offered. Your students can book it now.' : $n . ' sessions offered. Your students can book them now.');
                    break;
                case 'cancel':
                    edu_slot_cancel($org, (int) ($_POST['slot'] ?? 0), $me, 'mentor');
                    edu_pflash('ok', 'Cancelled. A student who had booked it has been told.');
                    break;
                case 'finish':
                    edu_slot_finish($org, (int) ($_POST['slot'] ?? 0), (int) $me['id'], (string) ($_POST['outcome'] ?? 'done'), (string) ($_POST['notes'] ?? ''));
                    edu_pflash('ok', 'Recorded.');
                    break;
                default:
                    return;
            }
            $back = edu_purl(['v' => 'sessions']);
        } else {
            return;
        }
    } catch (InvalidArgumentException $e) {
        edu_pflash('err', $e->getMessage());
    } catch (mysqli_sql_exception $e) {
        error_log('FABO edu portal lms ' . $a . ': ' . $e->getMessage());
        edu_pflash('err', 'That could not be saved. Try again.');
    }
    header('Location: ' . $back, true, 303);
    exit;
}

/** A lesson this student may open: on a course they are enrolled on (or may take). */
function edu_portal_lesson_for(int $org, array $me, int $lessonId): array
{
    $l = edu_lesson($org, $lessonId);
    $c = $l ? edu_course($org, (int) $l['course_id']) : null;
    if (!$l || !$c || (!edu_enrolment($org, (int) $c['id'], (int) $me['id']) && !edu_course_for($org, $c, $me))) {
        throw new InvalidArgumentException('That lesson is not open to you.');
    }
    if (!edu_enrolment($org, (int) $c['id'], (int) $me['id'])) {
        throw new InvalidArgumentException('Enrol on the course first.');
    }
    return $l;
}

function edu_next_lesson(int $org, array $l): ?array
{
    $all = edu_lessons($org, (int) $l['course_id']);
    foreach ($all as $i => $x) {
        if ((int) $x['id'] === (int) $l['id']) {
            return $all[$i + 1] ?? null;
        }
    }
    return null;
}

/* ================================================================= views */

function edu_portal_view(int $org, array $me, array $role, string $view): void
{
    $fn = 'edu_pv_' . $role['role'] . '_' . $view;
    if ($view === 'home' || !function_exists($fn)) {
        ('edu_portal_home_' . $role['role'])($org, $me, $role);
        return;
    }
    $fn($org, $me, $role);
}

function edu_course_card(int $org, array $c, ?array $enrol): string
{
    $state = $enrol ? ($enrol['completed_at'] ? '<span class="pill green">Finished</span>' : '<span class="pill amber">' . (int) $enrol['progress'] . '%</span>') : '';
    $cover = edu_course_cover($org, (int) $c['id']);
    return '<a class="cp-course c' . max(1, min(8, (int) $c['colour'])) . '" href="' . e(edu_purl(['v' => 'course', 'id' => (int) $c['id']])) . '">'
         . '<span class="cp-course-band' . ($cover ? ' has-img" style="background-image:url(\x27' . e(edu_pfile_url($cover)) . '\x27)' : '') . '"><em>' . e($c['category'] ?: 'Course') . '</em>' . $state . '</span>'
         . '<strong>' . e($c['title']) . '</strong><span class="cp-course-sum">' . e(mb_strimwidth((string) $c['summary'], 0, 140, '…')) . '</span>'
         . '<span class="cp-course-meta">' . (int) $c['lessons'] . ' lessons · ' . (int) $c['minutes_total'] . ' min · ' . e($c['level']) . '</span>'
         . ($enrol && !$enrol['completed_at'] ? '<span class="edu-bar"><i style="width:' . (int) $enrol['progress'] . '%"></i></span>' : '') . '</a>';
}

/** Student home additions: carry on learning, next sessions. Called from edu_portal_home_student(). */
function edu_pv_student_home_extra(int $org, array $me): void
{
    $cat = edu_catalogue($org, $me);
    $going = array_values(array_filter($cat, static fn($c) => $c['enrolment'] && !$c['enrolment']['completed_at']));
    $done = count(array_filter($cat, static fn($c) => $c['enrolment'] && $c['enrolment']['completed_at']));
    edu_pv_rewards_strip($org, $me);
    $next = array_values(array_filter(edu_student_slots($org, (int) $me['id']), static fn($s) => $s['status'] === 'booked' && strtotime($s['starts_at']) > time() - 3600));
    echo '<div class="cp-kpis"><div><b>' . count($going) . '</b><span>Courses in progress</span></div><div><b>' . $done . '</b><span>Finished</span></div>'
       . '<div><b>' . count($next) . '</b><span>Sessions booked</span></div><div><b>' . count($cat) . '</b><span>Courses open to you</span></div></div>';
    if ($going) {
        echo '<section class="cp-card"><div class="cp-card-h"><h2>Continue learning</h2><a href="' . e(edu_purl(['v' => 'courses'])) . '">All courses</a></div><div class="cp-courses">';
        foreach (array_slice($going, 0, 3) as $c) {
            echo edu_course_card($org, $c, $c['enrolment']);
        }
        echo '</div></section>';
    } elseif ($cat) {
        echo '<section class="cp-card"><div class="cp-card-h"><h2>Start a course</h2><a href="' . e(edu_purl(['v' => 'courses'])) . '">All courses</a></div><div class="cp-courses">';
        foreach (array_slice($cat, 0, 3) as $c) {
            echo edu_course_card($org, $c, $c['enrolment']);
        }
        echo '</div></section>';
    }
    if ($next) {
        echo '<section class="cp-card"><div class="cp-card-h"><h2>Next mentoring sessions</h2><a href="' . e(edu_purl(['v' => 'mentoring'])) . '">Mentoring</a></div><ul class="cp-sessions">';
        foreach ($next as $s) {
            echo edu_session_li($s, 'student') . '</li>';
        }
        echo '</ul></section>';
    }
}

function edu_pv_student_courses(int $org, array $me, array $role): void
{
    $cat = edu_catalogue($org, $me);
    $f = (string) ($_GET['f'] ?? 'all');
    $list = array_values(array_filter($cat, static fn($c) => $f === 'mine' ? ($c['enrolment'] && !$c['enrolment']['completed_at'])
                                                          : ($f === 'done' ? ($c['enrolment'] && $c['enrolment']['completed_at']) : true)));
    echo '<div class="cp-pagehead"><h1>Courses</h1><nav class="cp-seg">';
    foreach (['all' => 'All', 'mine' => 'In progress', 'done' => 'Finished'] as $k => $l) {
        echo '<a href="' . e(edu_purl(['v' => 'courses'] + ($k === 'all' ? [] : ['f' => $k]))) . '"' . ($f === $k ? ' class="on"' : '') . '>' . $l . '</a>';
    }
    echo '</nav></div><div class="cp-courses">';
    foreach ($list as $c) {
        echo edu_course_card($org, $c, $c['enrolment']);
    }
    echo '</div>';
    if (!$list) {
        echo '<p class="edu-empty">' . ($f === 'all' ? 'No courses are open to you yet. Your institution adds them.' : 'Nothing here yet.') . '</p>';
    }
}

function edu_pv_student_course(int $org, array $me, array $role): void
{
    $c = edu_course($org, (int) ($_GET['id'] ?? 0));
    $enrol = $c ? edu_enrolment($org, (int) $c['id'], (int) $me['id']) : null;
    if (!$c || (!$enrol && !edu_course_for($org, $c, $me))) {
        echo '<p class="edu-empty">This course is not open to you. <a href="' . e(edu_purl(['v' => 'courses'])) . '">All courses</a></p>';
        return;
    }
    $lessons = edu_lessons($org, (int) $c['id']);
    $done = $enrol ? edu_done_map($org, (int) $c['id'], (int) $me['id']) : [];
    $next = null;
    foreach ($lessons as $l) {
        if (!isset($done[(int) $l['id']])) {
            $next = $l;
            break;
        }
    }
    echo '<p class="edu-back"><a href="' . e(edu_purl(['v' => 'courses'])) . '">← Courses</a></p>'
       . '<section class="cp-coursehead c' . max(1, min(8, (int) $c['colour'])) . (($cover = edu_course_cover($org, (int) $c['id'])) ? ' has-cover" style="background-image:url(\x27' . e(edu_pfile_url($cover)) . '\x27)' : '') . '"><span class="cp-course-band"><em>' . e($c['category'] ?: 'Course') . ' · ' . e($c['level']) . '</em></span>'
       . '<h1>' . e($c['title']) . '</h1><p>' . e((string) $c['summary']) . '</p><p class="cp-course-meta">' . count($lessons) . ' lessons · ' . array_sum(array_column($lessons, 'minutes')) . ' min · pass mark ' . (int) $c['pass_pct'] . '%</p>';
    if (!$enrol) {
        echo '<form method="post">' . fabo_csrf_field() . '<input type="hidden" name="a" value="enrol"><input type="hidden" name="course" value="' . (int) $c['id'] . '"><button class="btn cp-cta">Start this course</button></form>';
    } elseif ($enrol['completed_at']) {
        echo '<a class="btn cp-cta" href="' . e(edu_purl(['v' => 'cert', 'id' => (int) $c['id']])) . '">View your certificate</a>';
    } else {
        echo '<div class="cp-prog"><span class="edu-bar"><i style="width:' . (int) $enrol['progress'] . '%"></i></span><b>' . (int) $enrol['progress'] . '%</b></div>'
           . ($next ? '<a class="btn cp-cta" href="' . e(edu_purl(['v' => 'lesson', 'id' => (int) $next['id']])) . '">Continue: ' . e($next['title']) . '</a>' : '');
    }
    echo '</section>';
    $outs = array_filter(explode("
", (string) ($c['outcomes'] ?? '')));
    $pre = array_filter(explode("
", (string) ($c['prerequisites'] ?? '')));
    if ($outs) {
        echo '<section class="cp-card"><h2>What you will learn</h2><ul class="cp-outcomes">' . implode('', array_map(static fn($o) => '<li>' . e($o) . '</li>', $outs)) . '</ul>'
           . ($pre ? '<p class="muted"><strong>Before you start:</strong> ' . e(implode(' · ', $pre)) . '</p>' : '') . '</section>';
    }
    echo '<section class="cp-card"><h2>Lessons</h2>';
    $files = edu_file_counts($org, (int) $c['id']);
    foreach (edu_lesson_groups($lessons) as [$sec, $group]) {
    if ($sec !== '') {
        echo '<h3 class="cp-secname">' . e($sec) . '</h3>';
    }
    echo '<ol class="cp-lessons">';
    foreach ($group as $l) {
        $is = isset($done[(int) $l['id']]);
        $inner = '<span class="cp-tick' . ($is ? ' on' : '') . '" aria-hidden="true"></span><span><strong>' . e($l['title']) . '</strong><em>' . e(edu_lesson_kinds()[$l['kind']]) . ' · ' . (int) $l['minutes'] . ' min'
               . (!empty($files[(int) $l['id']]) ? ' · ' . $files[(int) $l['id']] . ' file' . ($files[(int) $l['id']] === 1 ? '' : 's') : '')
               . ($is && $done[(int) $l['id']]['score'] !== null ? ' · scored ' . (int) $done[(int) $l['id']]['score'] . '%' : '') . '</em></span>';
        echo '<li class="' . ($is ? 'done' : '') . '">' . ($enrol ? '<a href="' . e(edu_purl(['v' => 'lesson', 'id' => (int) $l['id']])) . '">' . $inner . '</a>' : '<span class="cp-lessonrow">' . $inner . '</span>') . '</li>';
    }
    echo '</ol>';
    }
    echo '</section>';
}

function edu_pv_student_lesson(int $org, array $me, array $role): void
{
    try {
        $l = edu_portal_lesson_for($org, $me, (int) ($_GET['id'] ?? 0));
    } catch (InvalidArgumentException $e) {
        echo '<p class="edu-empty">' . e($e->getMessage()) . ' <a href="' . e(edu_purl(['v' => 'courses'])) . '">Courses</a></p>';
        return;
    }
    $c = edu_course($org, (int) $l['course_id']);
    $all = edu_lessons($org, (int) $c['id']);
    $done = edu_done_map($org, (int) $c['id'], (int) $me['id']);
    $pos = 0;
    foreach ($all as $i => $x) {
        if ((int) $x['id'] === (int) $l['id']) {
            $pos = $i;
        }
    }
    $prev = $all[$pos - 1] ?? null;
    $next = $all[$pos + 1] ?? null;
    $isDone = isset($done[(int) $l['id']]);
    echo '<p class="edu-back"><a href="' . e(edu_purl(['v' => 'course', 'id' => (int) $c['id']])) . '">← ' . e($c['title']) . '</a></p>'
       . '<div class="cp-steps" aria-label="Lesson ' . ($pos + 1) . ' of ' . count($all) . '">';
    foreach ($all as $i => $x) {
        echo '<a href="' . e(edu_purl(['v' => 'lesson', 'id' => (int) $x['id']])) . '" class="' . (isset($done[(int) $x['id']]) ? 'done' : '') . ($i === $pos ? ' on' : '') . '" title="' . e($x['title']) . '"></a>';
    }
    echo '</div><article class="cp-card cp-lesson"><span class="edu-lk k-' . e($l['kind']) . '">' . e(edu_lesson_kinds()[$l['kind']]) . ' · ' . (int) $l['minutes'] . ' min</span>'
       . '<h1>' . e($l['title']) . '</h1>';
    require_once __DIR__ . '/blocks.php';
    echo edu_lesson_render($org, $l, static fn($f, $dl = false) => edu_pfile_url($f, $dl));
    if ($l['kind'] === 'quiz') {
        edu_pv_quiz($org, $me, $l, $c, $isDone);
    } elseif (!$isDone) {
        echo '<form method="post" class="cp-donebar">' . fabo_csrf_field() . '<input type="hidden" name="a" value="lesson-done"><input type="hidden" name="lesson" value="' . (int) $l['id'] . '">'
           . '<button class="btn btn-primary cp-cta-sm">Mark as done' . ($next ? ' and continue →' : '') . '</button></form>';
    } else {
        echo '<p class="cp-donenote">✓ Done</p>';
    }
    echo '</article><nav class="cp-prevnext">' . ($prev ? '<a class="btn" href="' . e(edu_purl(['v' => 'lesson', 'id' => (int) $prev['id']])) . '">← ' . e(mb_strimwidth($prev['title'], 0, 40, '…')) . '</a>' : '<span></span>')
       . ($next ? '<a class="btn" href="' . e(edu_purl(['v' => 'lesson', 'id' => (int) $next['id']])) . '">' . e(mb_strimwidth($next['title'], 0, 40, '…')) . ' →</a>' : '') . '</nav>';
}

function edu_pv_quiz(int $org, array $me, array $l, array $c, bool $passed): void
{
    $qs = edu_questions($org, (int) $l['id']);
    $last = $_SESSION['edu_quiz'][(int) $l['id']] ?? null;
    unset($_SESSION['edu_quiz'][(int) $l['id']]);
    $best = edu_quiz_best($org, (int) $l['id'], (int) $me['id']);
    echo '<div class="cp-quiz" id="quiz"><p class="muted">' . count($qs) . ' question' . (count($qs) === 1 ? '' : 's') . ' · pass mark ' . (int) $c['pass_pct'] . '%'
       . ($best['attempts'] ? ' · best so far ' . (int) $best['best'] . '% in ' . $best['attempts'] . ' attempt' . ($best['attempts'] === 1 ? '' : 's') : '') . ($passed ? ' · <strong class="ok">Passed</strong>' : '') . '</p>'
       . '<form method="post">' . fabo_csrf_field() . '<input type="hidden" name="a" value="quiz"><input type="hidden" name="lesson" value="' . (int) $l['id'] . '">';
    foreach ($qs as $n => $q) {
        $qid = (int) $q['id'];
        $chosen = $last['answers'][$qid] ?? null;
        $mark = $last ? (!empty($last['right'][$qid]) ? ' right' : ' wrong') : '';
        echo '<fieldset class="cp-q' . $mark . '"><legend><span>' . ($n + 1) . '</span>' . e($q['question']) . '</legend>';
        foreach ($q['options'] as $i => $o) {
            $cls = $last && $i === (int) $q['correct'] ? ' is-right' : ($last && $chosen === $i ? ' is-wrong' : '');
            echo '<label class="cp-opt' . $cls . '"><input type="radio" name="q[' . $qid . ']" value="' . $i . '" required' . ($chosen === $i ? ' checked' : '') . '><span>' . e($o) . '</span></label>';
        }
        if ($last && trim((string) $q['explain_text']) !== '') {
            echo '<p class="cp-explain">' . e((string) $q['explain_text']) . '</p>';
        }
        echo '</fieldset>';
    }
    echo '<button class="btn btn-primary cp-cta-sm">' . ($best['attempts'] ? 'Submit again' : 'Submit answers') . '</button></form></div>';
}

function edu_pv_student_cert(int $org, array $me, array $role): void
{
    $c = edu_course($org, (int) ($_GET['id'] ?? 0));
    $e = $c ? edu_enrolment($org, (int) $c['id'], (int) $me['id']) : null;
    if (!$e || !$e['completed_at']) {
        echo '<p class="edu-empty">Finish the course to get its certificate.</p>';
        return;
    }
    $verify = edu_portal_url($org, ['verify' => $e['cert_code']]);
    echo '<p class="edu-back cp-noprint"><a href="' . e(edu_purl(['v' => 'course', 'id' => (int) $c['id']])) . '">← ' . e($c['title']) . '</a></p>'
       . '<div class="cp-certwrap"><article class="cp-cert"><img src="logo.php?edu=' . e(edu_portal_key($org)) . '" alt="" width="56" height="56">'
       . '<span class="cp-cert-k">Certificate of completion</span><p>This is to certify that</p><h1>' . e($me['name']) . '</h1>'
       . '<p>has successfully completed the course</p><h2>' . e($c['title']) . '</h2>'
       . '<p class="cp-cert-meta">' . e(date('j F Y', strtotime($e['completed_at']))) . ' · ' . e(edu_org_name($org)) . '</p>'
       . '<p class="cp-cert-code">Certificate no. <strong>' . e($e['cert_code']) . '</strong><br>Verify at ' . e($verify) . '</p></article></div>'
       . '<p class="cp-noprint cp-certacts"><button type="button" class="btn btn-primary" onclick="window.print()">Print or save as PDF</button>'
       . '<span class="edu-copy"><input readonly value="' . e($verify) . '" aria-label="Verification link"><button type="button" class="btn btn-sm" data-copy>Copy verification link</button></span></p>';
}

/** One session as a list item, for a student or a mentor. */
function edu_session_li(array $s, string $as): string
{
    $who = $as === 'student' ? ($s['mentor_name'] ?? '') : ($s['student_name'] ?? '');
    $over = strtotime($s['starts_at']) + 60 * (int) $s['minutes'] < time();
    $where = $s['mode'] === 'online' ? ($s['place'] !== '' && !$over && in_array($s['status'], ['open', 'booked'], true) ? '<a href="' . e($s['place']) . '" target="_blank" rel="noopener">Join online ↗</a>' : 'Online') : e($s['place'] !== '' ? $s['place'] : 'In person');
    $t = strtotime($s['starts_at']);
    return '<li><span class="cp-date"><b>' . date('j', $t) . '</b>' . date('M', $t) . '</span><span><strong>' . e(date('D, g:i a', $t)) . ' · ' . (int) $s['minutes'] . ' min'
         . ($who !== '' ? ' · ' . e($who) : '') . '</strong><em>' . ($s['topic'] ? e($s['topic']) . ' · ' : '') . $where . '</em></span>';
}

function edu_pv_student_mentoring(int $org, array $me, array $role): void
{
    $mentors = edu_student_mentors($org, (int) $me['id']);
    $open = edu_open_slots_for($org, (int) $me['id']);
    $mine = edu_student_slots($org, (int) $me['id']);
    $ahead = array_values(array_filter($mine, static fn($s) => $s['status'] === 'booked' && strtotime($s['starts_at']) > time() - 3600));
    $past = array_values(array_filter($mine, static fn($s) => !in_array($s, $ahead, true)));
    echo '<div class="cp-pagehead"><h1>Mentoring</h1></div>';
    if (!$mentors) {
        echo '<section class="cp-card"><p>You have no mentor yet. Your institution or the programme team links mentors to students.</p></section>';
        return;
    }
    echo '<section class="cp-card"><h2>Your booked sessions</h2><ul class="cp-sessions">';
    foreach ($ahead as $s) {
        $canCancel = strtotime($s['starts_at']) > time() + EDU_CANCEL_HOURS * 3600;
        echo edu_session_li($s, 'student') . ($canCancel ? '<form method="post" data-confirm="Cancel this session?">' . fabo_csrf_field() . '<input type="hidden" name="a" value="cancel"><input type="hidden" name="slot" value="' . (int) $s['id'] . '"><button class="btn btn-sm">Cancel</button></form>' : '') . '</li>';
    }
    if (!$ahead) {
        echo '<li class="edu-empty">Nothing booked. Pick a time below.</li>';
    }
    echo '</ul></section><section class="cp-card"><h2>Book a time</h2>';
    $by = [];
    foreach ($open as $s) {
        $by[(int) $s['mentor_id']][] = $s;
    }
    foreach ($mentors as $m) {
        $slots = $by[(int) $m['id']] ?? [];
        echo '<div class="cp-mentor"><div class="cp-mentor-h"><span class="cp-av">' . e(edu_initials($m['name'])) . '</span><span><strong>' . e($m['name']) . '</strong><em>' . e((string) $m['title']) . '</em></span></div>';
        if (!$slots) {
            echo '<p class="muted">No open times right now. Check back soon.</p>';
        } else {
            echo '<div class="cp-slots">';
            foreach ($slots as $s) {
                $t = strtotime($s['starts_at']);
                echo '<details class="cp-slot"><summary><b>' . e(date('D j M', $t)) . '</b>' . e(date('g:i a', $t)) . '<em>' . (int) $s['minutes'] . ' min · ' . ($s['mode'] === 'online' ? 'Online' : 'In person') . '</em></summary>'
                   . '<form method="post" class="cp-form">' . fabo_csrf_field() . '<input type="hidden" name="a" value="book"><input type="hidden" name="slot" value="' . (int) $s['id'] . '">'
                   . '<label class="field"><span>What would you like to talk about?</span><input name="topic" required maxlength="300" placeholder="e.g. Feedback on my idea for farmers\' market prices"></label>'
                   . '<button class="btn btn-primary">Book ' . e(date('D j M, g:i a', $t)) . '</button></form></details>';
            }
            echo '</div>';
        }
        echo '</div>';
    }
    echo '</section>';
    if ($past) {
        echo '<section class="cp-card"><h2>Past sessions</h2><ul class="cp-sessions">';
        foreach ($past as $s) {
            echo edu_session_li($s, 'student') . '<span class="pill ' . ($s['status'] === 'done' ? 'green' : ($s['status'] === 'booked' ? 'amber' : 'grey')) . '">'
               . e($s['status'] === 'booked' ? 'Awaiting mentor' : (edu_slot_statuses()[$s['status']] ?? $s['status'])) . '</span>';
            if ($s['status'] === 'done') {
                if ($s['rating']) {
                    echo '<span class="cp-stars" aria-label="' . (int) $s['rating'] . ' of 5">' . str_repeat('★', (int) $s['rating']) . str_repeat('☆', 5 - (int) $s['rating']) . '</span>';
                } else {
                    echo '<details class="cp-rate"><summary class="btn btn-sm">Rate it</summary><form method="post" class="cp-form">' . fabo_csrf_field()
                       . '<input type="hidden" name="a" value="rate"><input type="hidden" name="slot" value="' . (int) $s['id'] . '"><div class="cp-starpick">';
                    for ($i = 5; $i >= 1; $i--) {
                        echo '<input type="radio" id="r' . (int) $s['id'] . '-' . $i . '" name="rating" value="' . $i . '" required><label for="r' . (int) $s['id'] . '-' . $i . '" title="' . $i . ' of 5">★</label>';
                    }
                    echo '</div><input name="feedback" maxlength="2000" placeholder="What helped? (optional)"><button class="btn btn-primary btn-sm">Send</button></form></details>';
                }
            }
            echo '</li>';
        }
        echo '</ul></section>';
    }
}

function edu_pv_mentor_sessions(int $org, array $me, array $role): void
{
    $up = edu_mentor_slots($org, (int) $me['id'], true);
    $past = edu_mentor_slots($org, (int) $me['id'], false);
    echo '<div class="cp-pagehead"><h1>Sessions</h1></div><section class="cp-card"><h2>Offer a time</h2><form method="post" class="edu-form">' . fabo_csrf_field() . '<input type="hidden" name="a" value="offer"><div class="edu-fields">'
       . '<label class="field"><span>Date</span><input type="date" name="date" required min="' . date('Y-m-d') . '" max="' . date('Y-m-d', strtotime('+180 days')) . '"></label>'
       . '<label class="field"><span>Start time</span><input type="time" name="time" required step="300"></label>'
       . '<label class="field"><span>Length</span><select name="minutes">';
    foreach ([15, 20, 30, 45, 60, 90] as $m) {
        echo '<option value="' . $m . '"' . ($m === 30 ? ' selected' : '') . '>' . $m . ' min</option>';
    }
    echo '</select></label><label class="field"><span>Repeat</span><select name="weeks"><option value="1">Just once</option>';
    foreach ([2, 4, 6, 8, 12] as $w) {
        echo '<option value="' . $w . '">Weekly for ' . $w . ' weeks</option>';
    }
    echo '</select></label><label class="field"><span>How</span><select name="mode" data-edu-role><option value="online">Online</option><option value="in_person">In person</option></select></label>'
       . '<label class="field wide" data-for="online"><span>Meeting link (https://…)</span><input name="place" maxlength="300" placeholder="https://meet.google.com/…"></label>'
       . '<label class="field wide" data-for="in_person"><span>Where</span><input name="place" maxlength="300" placeholder="Room, building" disabled></label>'
       . '</div><button class="btn btn-primary">Offer</button></form></section>';
    echo '<section class="cp-card"><h2>Coming up</h2><ul class="cp-sessions">';
    foreach ($up as $s) {
        echo edu_session_li($s, 'mentor') . '<span class="pill ' . ($s['status'] === 'booked' ? 'green' : 'grey') . '">' . ($s['status'] === 'booked' ? 'Booked' : 'Open') . '</span>';
        if (strtotime($s['starts_at']) <= time() && $s['status'] === 'booked') {
            echo edu_finish_form($s);
        } else {
            echo '<form method="post" data-confirm="' . ($s['status'] === 'booked' ? 'Cancel this booked session? The student will be told.' : 'Withdraw this time?') . '">' . fabo_csrf_field()
               . '<input type="hidden" name="a" value="cancel"><input type="hidden" name="slot" value="' . (int) $s['id'] . '"><button class="btn btn-sm">' . ($s['status'] === 'booked' ? 'Cancel' : 'Withdraw') . '</button></form>';
        }
        echo '</li>';
    }
    if (!$up) {
        echo '<li class="edu-empty">Nothing offered yet. Offer a time above.</li>';
    }
    echo '</ul></section>';
    if ($past) {
        echo '<section class="cp-card"><h2>Past sessions</h2><ul class="cp-sessions">';
        foreach ($past as $s) {
            echo edu_session_li($s, 'mentor') . '<span class="pill ' . ($s['status'] === 'done' ? 'green' : ($s['status'] === 'booked' ? 'amber' : 'grey')) . '">'
               . ($s['status'] === 'booked' ? 'To record' : e(edu_slot_statuses()[$s['status']])) . '</span>'
               . ($s['rating'] ? '<span class="cp-stars">' . str_repeat('★', (int) $s['rating']) . '</span>' : '') . edu_finish_form($s) . '</li>';
        }
        echo '</ul></section>';
    }
}

function edu_finish_form(array $s): string
{
    return '<details class="cp-rate"><summary class="btn btn-sm">' . ($s['status'] === 'booked' ? 'Record' : 'Notes') . '</summary><form method="post" class="cp-form">' . fabo_csrf_field()
         . '<input type="hidden" name="a" value="finish"><input type="hidden" name="slot" value="' . (int) $s['id'] . '">'
         . '<select name="outcome" aria-label="Outcome"><option value="done">Held</option><option value="missed"' . ($s['status'] === 'missed' ? ' selected' : '') . '>Missed (student did not come)</option></select>'
         . '<textarea name="notes" rows="3" maxlength="4000" placeholder="Notes for yourself and the programme team (the student does not see these)">' . e((string) $s['mentor_notes']) . '</textarea>'
         . '<button class="btn btn-primary btn-sm">Save</button></form></details>';
}

/** Faculty and institution admins: how their students are learning. */
function edu_pv_learning(int $org, array $role, array $students, string $scope): void
{
    $ids = array_map(static fn($p) => (int) $p['id'], $students);
    $rows = edu_learning_summary($org, $ids);
    echo '<div class="cp-pagehead"><h1>Learning</h1><p class="muted">' . e($scope) . ' · ' . count($ids) . ' students</p></div><section class="cp-card">'
       . '<div class="edu-tablewrap"><table class="edu-table"><thead><tr><th>Course</th><th class="n">Enrolled</th><th class="n">Finished</th><th>Average progress</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr><td><strong>' . e($r['title']) . '</strong></td><td class="n">' . (int) $r['enrolled'] . ' <span class="muted">(' . ($ids ? round($r['enrolled'] * 100 / count($ids)) : 0) . '%)</span></td>'
           . '<td class="n">' . (int) $r['completed'] . '</td><td><span class="edu-bar"><i style="width:' . (int) $r['avg_progress'] . '%"></i></span> <span class="muted">' . (int) $r['avg_progress'] . '%</span></td></tr>';
    }
    if (!$rows) {
        echo '<tr><td colspan="4" class="edu-empty">None of these students has started a course yet.</td></tr>';
    }
    echo '</tbody></table></div></section>';
}

function edu_pv_faculty_learning(int $org, array $me, array $role): void
{
    $students = edu_portal_faculty_students($org, $role);
    edu_pv_learning($org, $role, $students, $role['dept_name'] !== '' ? $role['dept_name'] . ', ' . $role['inst_name'] : $role['inst_name']);
    edu_pv_kudos_form($org, $me, $students);
    $ids = array_flip(array_map(static fn($p) => (int) $p['id'], $students));
    edu_pv_ideas_table($org, array_values(array_filter(edu_ideas_list($org, [(int) $role['institution_id']]), static fn($i) => isset($ids[(int) $i['person_id']]))), 'Your students\x27 ideas');
    edu_pv_top_students($org, (int) $role['department_id'] ? ['dept' => (int) $role['department_id']] : ['inst' => (int) $role['institution_id']], 'Top students this month');
}

function edu_pv_inst_admin_learning(int $org, array $me, array $role): void
{
    edu_pv_learning($org, $role, edu_people($org, ['role' => 'student', 'inst' => (int) $role['institution_id'], 'limit' => 500]), $role['inst_name']);
    edu_pv_top_students($org, ['inst' => (int) $role['institution_id']], 'Top students this month');
}

/* ======================================================= verify (public) */

function edu_portal_verify(int $org, string $code): void
{
    $code = strtoupper(trim($code));
    $c = edu_certificate($code);
    $ok = $c && (int) $c['course_org'] === $org;
    edu_portal_gate($org, 'Verify a certificate', '<h1>Certificate check</h1>' . ($ok
        ? '<div class="edu-flash ok" role="status"><strong>Valid.</strong> Certificate ' . e($code) . '</div><dl class="edu-dl"><dt>Awarded to</dt><dd>' . e($c['person_name']) . '</dd>'
          . '<dt>Course</dt><dd>' . e($c['course_title']) . '</dd><dt>Completed</dt><dd>' . e(date('j F Y', strtotime($c['completed_at']))) . '</dd><dt>Issued by</dt><dd>' . e(edu_org_name($org)) . '</dd></dl>'
        : '<div class="edu-flash err" role="alert">No certificate with the number ' . e($code) . ' was issued here.</div>')
        . '<form method="get" class="cp-form"><input type="hidden" name="o" value="' . e(edu_portal_key($org)) . '"><label class="field"><span>Certificate number</span><input name="verify" maxlength="12" value="' . e($code) . '" required></label><button class="btn">Check</button></form>');
}

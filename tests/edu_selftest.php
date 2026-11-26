<?php
/**
 * Academic self-test, LOCAL ONLY:
 *   php tests/edu_selftest.php          library rules, organisation separation, import, desk pages
 *   php tests/edu_selftest.php seed     leaves org 9911 with sample data and known passwords (for HTTP tests)
 *   php tests/edu_selftest.php clean    removes orgs 9911 / 9912 and everything in them
 * Runs in throwaway organisations 9911 and 9912 on the local database and
 * refuses anywhere else. Never deploy this. No email leaves the machine.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
chdir(dirname(__DIR__));
ob_start();
$_SERVER['HTTP_HOST'] = 'localhost:8765';
$_SERVER['SCRIPT_NAME'] = '/academic.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once 'lib/ui.php';
require_once 'lib/edu/app.php';
if (str_contains(str_replace('\\', '/', __DIR__), '/home/') || !in_array(DB_HOST, ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "Refusing: this test only runs on a local development copy.\n");
    exit(2);
}
fabo_boot();
edu_install();

const A = 9911;
const B = 9912;
$GLOBALS['edu_mail_hook'] = static function ($to, $s, $t) {
    $GLOBALS['mails'][] = [$to, $s, $t];
};
$GLOBALS['mails'] = [];

function cleanup(): void
{
    require_once 'lib/edu/lms.php';
    edu_lms_install();
    require_once 'lib/edu/game.php';
    edu_game_install();
    require_once 'lib/edu/ideas.php';
    require_once 'lib/edu/privacy.php';
    edu_ideas_install();
    edu_privacy_install();
    require_once 'lib/edu/files.php';
    edu_builder_install();
    foreach (fabo_all('SELECT stored_name FROM edu_files WHERE org_id IN (' . A . ',' . B . ')') as $sf) {
        fabo_delete_stored((string) $sf['stored_name']);
    }
    require_once 'lib/edu/blocks.php';
    edu_blocks_install();
    foreach (['edu_blocks', 'edu_files', 'edu_consents', 'edu_requests', 'edu_access_log', 'edu_security_log', 'edu_login_codes', 'edu_ideas', 'edu_ledger', 'edu_badges', 'edu_orders', 'edu_store_items', 'edu_slots', 'edu_attempts', 'edu_lesson_done', 'edu_enrolments', 'edu_questions', 'edu_lessons', 'edu_courses', 'edu_mentor_links', 'edu_roles', 'edu_departments', 'edu_institutions', 'edu_people', 'edu_settings', 'edu_audit'] as $t) {
        fabo_exec("DELETE FROM `$t` WHERE org_id IN (" . A . ',' . B . ')');
    }
    // Incubation Management made for the test organisation (its own tables), and what it sent.
    fabo_exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (fabo_all('SHOW TABLES') as $r) {
        $t = array_values($r)[0];
        if (preg_match('/^(inc|eo)_o(' . A . '|' . B . ')_/', $t)) {
            fabo_exec("DROP TABLE `$t`");
        }
    }
    fabo_exec('SET FOREIGN_KEY_CHECKS = 1');
    try {
        fabo_exec('DELETE FROM notifications WHERE client_id IN (' . A . ',' . B . ',991101)');
    } catch (Throwable $e) {
    }
    fabo_exec('DELETE FROM client_sheet_access WHERE client_id IN (' . A . ',' . B . ',991101)');
    fabo_exec('DELETE FROM clients WHERE id IN (' . A . ',' . B . ',991101)');
}
function client(int $id, string $name, ?int $parent = null, string $kind = 'client'): void
{
    fabo_exec('INSERT INTO clients (id, name, kind, email, token, parent_id, organisation, password_hash) VALUES (?,?,?,?,?,?,?,?)', 'issssiss',
              [$id, $name, $kind, 'edu' . $id . '@example.test', bin2hex(random_bytes(16)), $parent, $parent ? '' : $name, 'x']);
}

$mode = $argv[1] ?? 'test';
cleanup();
if ($mode === 'clean') {
    echo "cleaned\n";
    exit(0);
}
client(A, 'Edu Test Council');
client(B, 'Other Edu Org');
client(991101, 'Council Staffer', A, 'team');
fabo_exec('INSERT INTO client_sheet_access (client_id, sheet_key, permission) VALUES (?,?,?), (?,?,?), (?,?,?)', 'issississ',
          [A, 'academic', 'edit', B, 'academic', 'edit', 991101, 'academic', 'edit']);

if ($mode === 'seed') {
    edu_sample_load(A);
    $set = static function (string $email, string $pw) {
        $p = edu_person_by_email(A, $email);
        edu_password_set(A, (int) $p['id'], $pw);
        return (int) $p['id'];
    };
    $stu = $set('student1@gec-gnt.example', 'student-pass-1');
    $set('principal@gec-gnt.example', 'college-head-pass');
    $set('faculty1@gec-gnt.example', 'faculty-pass-1');
    $mentor = $set('mentor1@mentors.example', 'mentor-pass-1');
    // One person with two roles: the first mentor also teaches.
    edu_role_add(A, $mentor, 'faculty', (int) edu_institutions(A)[0]['id'], 0, 'Visiting faculty');
    $inv = edu_invite_make(A, (int) edu_person_by_email(A, 'student2@gec-gnt.example')['id']);
    // A session that already happened, booked by student1 with mentor1 (linked by the sample), to record and rate.
    require_once 'lib/edu/mentoring.php';
    $past = fabo_exec('INSERT INTO edu_slots (org_id, mentor_id, starts_at, minutes, mode, place, status, student_id, topic, booked_at, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                      'iisisssisss', [A, $mentor, date('Y-m-d H:i:s', time() - 86400), 30, 'online', 'https://meet.example/x', 'booked', $stu, 'Market sizing', edu_now(), edu_now()]);
    $course = (int) fabo_one('SELECT id FROM edu_courses WHERE org_id = ? LIMIT 1', 'i', [A])['id'];
    // Rewards: the starter shelf on, and some coins to spend.
    require_once 'lib/edu/game.php';
    edu_store_starter(A);
    fabo_exec('UPDATE edu_store_items SET active = 1 WHERE org_id = ?', 'i', [A]);
    edu_adjust(A, $stu, 'coin', 500, 'Welcome bonus', 'Seed');
    // Phase 4: Incubation Management with a public form students apply with, and a council officer.
    require_once 'lib/edu/ideas.php';
    require_once 'lib/inc/render.php';
    edu_ideas_install();
    fabo_exec('INSERT INTO client_sheet_access (client_id, sheet_key, permission) VALUES (?,?,?)', 'iss', [A, 'incubation', 'edit']);
    inc_use_kind('inc');
    inc_start(fabo_admin_viewer(), A);
    $fid = inc_form_create(['name' => 'Student incubation', 'purpose' => 'incubation']);
    inc_exec('UPDATE inc_form_fields SET required = 0 WHERE version_id = ? AND uid IN ("spoke", "sector")', 'i', [(int) inc_form_draft_version($fid)['id']]);
    inc_form_publish($fid);
    inc_exec('UPDATE inc_forms SET public_enabled = 1 WHERE id = ?', 'i', [$fid]);
    edu_set(A, 'inc_form_id', (string) $fid);
    $cn = edu_person_save(A, ['name' => 'Council Officer', 'email' => 'council@board.example']);
    edu_role_add(A, $cn, 'council');
    edu_password_set(A, $cn, 'board-view-pass');
    // The older HTTP suites sign straight in: consent recorded and no sign-in codes. The Phase 5 suite turns both on.
    require_once 'lib/edu/privacy.php';
    edu_privacy_install();
    edu_set(A, 'mfa_mode', 'off');
    // The staff desk over HTTP (the course builder suite): a real password for the test client.
    fabo_exec('UPDATE clients SET password_hash = ?, must_change = 0 WHERE id = ?', 'si', [password_hash('staff-pass-9911', PASSWORD_DEFAULT), A]);
    foreach (fabo_all('SELECT * FROM edu_people WHERE org_id = ?', 'i', [A]) as $pp) {
        edu_consent_give(A, $pp, false);
    }
    // Answers for every required question the prefill leaves empty, as a query string for curl.
    $ans = [];
    foreach (inc_form_fields((int) inc_form_published($fid)['id'], true) as $f) {
        if ((int) $f['required'] !== 1 || $f['type'] === 'section' || $f['maps_to'] || in_array($f['uid'], ['problem', 'solution'], true)) {
            continue;
        }
        $ch = inc_field_choices($f);
        $ans['f[' . $f['uid'] . ']' . (in_array($f['type'], ['checkbox', 'multiselect'], true) ? '[]' : '')] = match (true) {
            $f['type'] === 'yesno' => 'No',
            inc_is_choice($f['type']) => (string) array_key_first($ch),
            $f['type'] === 'number' => '1',
            $f['type'] === 'date' => date('Y-m-d'),
            $f['type'] === 'url' => 'https://example.org',
            default => 'An answer long enough for this question: ' . $f['label'],
        };
    }
    $dev = inc_form_fields((int) inc_form_published($fid)['id'], true);
    ob_end_clean();
    echo json_encode(['key' => edu_portal_key(A), 'keyB' => edu_portal_key(B), 'invite' => $inv, 'past' => $past, 'course' => $course, 'answers' => http_build_query($ans)]) . "\n";
    exit(0);
    echo json_encode(['key' => edu_portal_key(A), 'keyB' => edu_portal_key(B), 'invite' => $inv, 'past' => $past, 'course' => $course]) . "\n";
    exit(0);
}

$pass = 0;
$fail = [];
function ok(bool $c, string $what): void
{
    global $pass, $fail;
    if ($c) {
        $pass++;
    } else {
        $fail[] = $what;
        fwrite(STDOUT, "FAIL  $what\n");
    }
}
function eq($got, $want, string $what): void
{
    ok($got == $want, $what . ' (got ' . json_encode($got) . ', want ' . json_encode($want) . ')');
}
function refused(callable $fn, string $what): void
{
    try {
        $fn();
        ok(false, $what . ' (was allowed)');
    } catch (InvalidArgumentException $e) {
        ok(true, $what);
    }
}

try {
    /* --------------------------------------------------- institutions */
    $i1 = edu_institution_save(A, ['name' => 'Alpha College', 'kind' => 'College', 'code' => 'ALP']);
    $i2 = edu_institution_save(A, ['name' => 'Beta Polytechnic', 'kind' => 'Polytechnic']);
    $iB = edu_institution_save(B, ['name' => 'Alpha College']);        // the same name in another organisation is fine
    ok($i1 > 0 && $i2 > 0 && $iB > 0, 'institutions are added');
    refused(static fn() => edu_institution_save(A, ['name' => 'Alpha College']), 'a second institution with the same name is refused');
    refused(static fn() => edu_institution_save(A, ['name' => '']), 'an institution needs a name');
    refused(static fn() => edu_institution_save(A, ['name' => 'X', 'contact_email' => 'nope']), 'a bad contact email is refused');
    refused(static fn() => edu_institution_save(A, ['name' => 'Hijack'], $iB), 'another organisation\'s institution cannot be edited');
    ok(edu_institution(B, $i1) === null, 'organisation B cannot read A\'s institution');

    $d1 = edu_department_save(A, $i1, 'Computer Science');
    $d2 = edu_department_save(A, $i2, 'Civil');
    refused(static fn() => edu_department_save(A, $i1, 'Computer Science'), 'a duplicate department is refused');
    refused(static fn() => edu_department_save(A, $iB, 'Sneaky'), 'a department in another organisation\'s institution is refused');

    /* ---------------------------------------------------------- people */
    $s = edu_person_save(A, ['name' => 'Asha Student', 'email' => 'Asha@Example.test', 'roll_no' => 'R1', 'year_of_study' => 2]);
    eq(edu_person(A, $s)['email'], 'asha@example.test', 'email is kept in lower case');
    refused(static fn() => edu_person_save(A, ['name' => 'Copy', 'email' => 'asha@example.test']), 'one email, one person in an organisation');
    $sB = edu_person_save(B, ['name' => 'Asha Elsewhere', 'email' => 'asha@example.test']);
    ok($sB > 0, 'the same email may exist in another organisation');
    edu_role_add(A, $s, 'student', $i1, $d1);
    refused(static fn() => edu_role_add(A, $s, 'student', 0), 'a student needs an institution');
    refused(static fn() => edu_role_add(A, $s, 'student', $i2, $d1), 'the department must be in that institution');
    refused(static fn() => edu_role_add(A, $s, 'student', $iB), 'a role in another organisation\'s institution is refused');
    refused(static fn() => edu_role_add(B, $s, 'student', $iB), 'organisation B cannot give A\'s person a role');
    refused(static fn() => edu_role_add(A, $s, 'wizard', $i1), 'an unknown role is refused');
    $m = edu_person_save(A, ['name' => 'Mira Mentor', 'email' => 'mira@example.test']);
    edu_role_add(A, $m, 'mentor', 0, 0, 'Agritech');
    $f = edu_person_save(A, ['name' => 'Farid Faculty', 'email' => 'farid@example.test']);
    edu_role_add(A, $f, 'faculty', $i1, $d1, 'HOD');
    $adm = edu_person_save(A, ['name' => 'Ina Admin', 'email' => 'ina@example.test']);
    edu_role_add(A, $adm, 'inst_admin', $i1, $d1);
    eq((int) edu_person_roles(A, $adm)[0]['department_id'], 0, 'an institution admin is never limited to a department');
    $again = edu_role_add(A, $s, 'student', $i1, $d1);
    eq(count(edu_person_roles(A, $s)), 1, 'adding the same role twice keeps one');

    eq(edu_totals(A)['student'], 1, 'one student in A');
    eq(edu_totals(B)['student'], 0, 'none in B');
    eq(count(edu_people(A, ['role' => 'student'])), 1, 'people filter by role');
    eq(count(edu_people(A, ['inst' => $i2])), 0, 'people filter by institution');
    eq(count(edu_people(A, ['q' => 'asha'])), 1, 'search by name');
    eq(count(edu_people(A, ['q' => 'R1'])), 1, 'search by roll no');
    eq(count(edu_people(A, ['insts' => []])), 0, 'an empty institution scope sees nobody');
    eq(count(edu_people(B, ['q' => 'Mira'])), 0, 'B never lists A\'s people');

    /* --------------------------------------------------------- mentors */
    ok(edu_mentor_assign(A, $m, $s), 'a mentor is linked to a student');
    ok(!edu_mentor_assign(A, $f, $s), 'only someone holding the mentor role can mentor');
    ok(!edu_mentor_assign(A, $m, $f), 'only a student can be mentored');
    ok(!edu_mentor_assign(B, $m, $s), 'B cannot link A\'s people');
    eq(count(edu_mentor_students(A, $m)), 1, 'the mentor sees their student');
    eq(edu_student_mentors(A, $s)[0]['title'], 'Agritech', 'the student sees their mentor with expertise');
    eq((int) edu_find_student(A, 'R1')['id'], $s, 'a student is found by roll no');
    ok(edu_find_student(A, 'mira@example.test') === null, 'a mentor is not found as a student');

    /* ---------------------------------------------- departments removed */
    edu_role_add(A, $f, 'faculty', $i1, 0, 'Also institution-wide');
    edu_department_delete(A, $d1);
    $fr = edu_person_roles(A, $f);
    eq(count($fr), 1, 'removing a department folds a duplicate role into the institution one');
    eq((int) edu_person_roles(A, $s)[0]['department_id'], 0, 'a student in a removed department stays in the institution');

    /* ------------------------------------------------------- accounts */
    $url = edu_invite_make(A, $s);
    ok(str_contains($url, 'campus.php?o=' . edu_portal_key(A)) && str_contains($url, 'invite='), 'the sign-in link carries the portal key');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    eq((int) edu_invite_person($q['invite'])['id'], $s, 'the link finds its person');
    ok(edu_invite_person(str_repeat('a', 48)) === null, 'a made-up link finds nobody');
    eq(edu_org_by_key(edu_portal_key(A)), A, 'the portal key finds its organisation');
    ok(edu_portal_key(A) !== edu_portal_key(B), 'each organisation has its own key');
    refused(static fn() => edu_password_set(A, $s, 'short'), 'a short password is refused');
    edu_password_set(A, $s, 'a-good-password');
    ok(edu_invite_person($q['invite']) === null, 'setting a password ends the link');
    $fp1 = edu_fingerprint(edu_person(A, $s));
    ok(edu_check_login(A, 'ASHA@example.test', 'a-good-password') !== null, 'sign-in works, email in any case');
    ok(edu_check_login(A, 'asha@example.test', 'wrong') === null, 'a wrong password is refused');
    ok(edu_check_login(B, 'asha@example.test', 'a-good-password') === null, 'A\'s password does not open B');
    edu_person_status(A, $s, 'suspended');
    ok(edu_check_login(A, 'asha@example.test', 'a-good-password') === null, 'a suspended person cannot sign in');
    edu_person_status(A, $s, 'active');
    edu_password_set(A, $s, 'another-password', true);
    ok($fp1 !== edu_fingerprint(edu_person(A, $s)), 'a new password changes the session fingerprint');
    eq((int) edu_person(A, $s)['must_change'], 1, 'a temporary password must be changed');
    [$sent] = edu_invite_send(A, $m);
    ok($sent && count($GLOBALS['mails']) === 1 && $GLOBALS['mails'][0][0] === 'mira@example.test', 'the invite goes to the person (hooked)');
    ok(str_contains($GLOBALS['mails'][0][2], 'campus.php?o='), 'the invite carries the link');
    $noMail = edu_person_save(A, ['name' => 'No Mail']);
    refused(static fn() => edu_invite_make(A, $noMail), 'no email, no sign-in link');

    /* ---------------------------------------------------------- import */
    $csv = tempnam(sys_get_temp_dir(), 'edu');
    file_put_contents($csv, "\xEF\xBB\xBFStudent Name,Email ID,College,Branch,Hall Ticket,Year,Role\n"
        . "Ravi Kumar,ravi@example.test,Alpha College,Mechanical,H100,3,Student\n"
        . "Asha Student,asha@example.test,Alpha College,,,4,\n"
        . "Gamma Person,gamma@example.test,Gamma Institute,ECE,,1,student\n"
        . "Bad Role,bad@example.test,Alpha College,,,,wizard\n"
        . ",nobody@example.test,Alpha College,,,,\n"
        . "Teacher T,teach@example.test,Alpha College,Mechanical,,,Professor\n"
        . "\n"
        . "Rollonly Kid,,Beta Polytechnic,,RB9,,\n");
    $rep = edu_import_csv(A, $csv, ['create' => false]);
    eq([$rep['added'], $rep['updated'], count($rep['skipped'])], [1, 1, 5], 'import without create: only the roll-no row is added, Asha updated, the rest reported');
    $rep2 = edu_import_csv(A, $csv, ['create' => true]);
    eq([$rep2['added'], $rep2['updated'], count($rep2['skipped'])], [3, 2, 2], 'import with create adds Ravi, Gamma and the teacher; nobody twice');
    eq(edu_person(A, $s)['year_of_study'], 4, 'an existing email is updated, not added twice');
    ok((bool) fabo_one('SELECT id FROM edu_institutions WHERE org_id = ? AND name = "Gamma Institute"', 'i', [A]), 'a missing institution is made when asked');
    ok(!fabo_one('SELECT id FROM edu_institutions WHERE org_id = ? AND name = "Gamma Institute"', 'i', [B]), '... in this organisation only');
    $teach = edu_person_by_email(A, 'teach@example.test');
    eq(edu_person_roles(A, (int) $teach['id'])[0]['role'], 'faculty', '"Professor" is read as Faculty');
    eq((int) fabo_one('SELECT COUNT(*) AS n FROM edu_people WHERE org_id = ? AND roll_no = "RB9"', 'i', [A])['n'], 1, 'a row with no email matches on roll no the second time');
    $whys = implode(' | ', array_column($rep2['skipped'], 1));
    ok(str_contains($whys, 'wizard'), 'a bad role is reported');
    ok(str_contains($whys, 'name'), 'a row with no name is reported');
    file_put_contents($csv, "Email,Phone\nx@example.test,1\n");
    refused(static fn() => edu_import_csv(A, $csv), 'a file with no Name column is refused');
    file_put_contents($csv, "Name;Email;Institution\nSemi Colon;semi@example.test;Alpha College\n");
    eq(edu_import_csv(A, $csv)['added'], 1, 'semicolon CSVs are read');
    unlink($csv);

    /* ------------------------------------------------------ sample data */
    refused(static fn() => edu_sample_load(A), 'sample data refuses a non-empty Academic');
    edu_institution_delete(B, $iB);
    $r = edu_sample_load(B);
    eq($r['institutions'], 3, 'sample: three institutions');
    eq(edu_totals(B)['student'], 36, 'sample: 36 students');
    ok(edu_totals(B)['mentor'] === 4 && count(edu_mentor_students(B, (int) edu_person_by_email(B, 'mentor1@mentors.example')['id'])) === 9, 'sample: mentors with nine students each');
    $n = edu_sample_remove(B);
    ok($n >= 40 && (int) fabo_one('SELECT COUNT(*) AS n FROM edu_people WHERE org_id = ? AND email LIKE "%.example"', 'i', [B])['n'] === 0, 'sample removal takes the sample people');
    ok((bool) edu_person(B, $sB), '... and leaves real records');
    eq(count(edu_institutions(B)), 0, '... and removes the sample institutions');
    $iB = edu_institution_save(B, ['name' => 'Alpha College']);

    /* -------------------------------------------------------- courses */
    require_once 'lib/edu/lms.php';
    require_once 'lib/edu/mentoring.php';
    edu_lms_install();
    $iA = edu_institutions(A);
    $alpha = (int) fabo_one('SELECT id FROM edu_institutions WHERE org_id = ? AND name = "Alpha College"', 'i', [A])['id'];
    $beta = (int) fabo_one('SELECT id FROM edu_institutions WHERE org_id = ? AND name = "Beta Polytechnic"', 'i', [A])['id'];
    refused(static fn() => edu_course_save(A, ['title' => ''], 't'), 'a course needs a title');
    refused(static fn() => edu_course_save(A, ['title' => 'X', 'audience' => 'institution', 'institution_id' => $iB], 't'), 'a course cannot target another organisation\'s institution');
    $cAll = edu_course_save(A, ['title' => 'For everyone', 'audience' => 'all', 'pass_pct' => 60], 't');
    $cBeta = edu_course_save(A, ['title' => 'Beta only', 'audience' => 'institution', 'institution_id' => $beta], 't');
    $cY3 = edu_course_save(A, ['title' => 'Year 3 only', 'audience' => 'all', 'years' => [3]], 't');
    refused(static fn() => edu_course_status(A, $cAll, 'published'), 'an empty course cannot be published');
    $l1 = edu_lesson_save(A, $cAll, ['title' => 'Read me', 'kind' => 'text', 'body' => "# Hi\n- one\n- two\n**bold** <script>x</script> [site](https://example.org)"]);
    refused(static fn() => edu_lesson_save(A, $cAll, ['title' => 'Vid', 'kind' => 'video', 'url' => 'https://example.org/v.mp4']), 'a video lesson needs YouTube or Vimeo');
    refused(static fn() => edu_lesson_save(A, $cAll, ['title' => 'Bad', 'kind' => 'link', 'url' => 'javascript:alert(1)']), 'a link must be https');
    $l2 = edu_lesson_save(A, $cAll, ['title' => 'Watch', 'kind' => 'video', 'url' => 'https://youtu.be/MT4Ig2uqjTc']);
    $lq = edu_lesson_save(A, $cAll, ['title' => 'Quiz', 'kind' => 'quiz']);
    refused(static fn() => edu_course_status(A, $cAll, 'published'), 'a quiz without questions blocks publishing');
    refused(static fn() => edu_question_save(A, $l1, ['question' => 'Q', 'options' => ['a', 'b'], 'correct' => 0]), 'questions only go on a quiz');
    refused(static fn() => edu_question_save(A, $lq, ['question' => 'Q', 'options' => ['a', ''], 'correct' => 0]), 'a question needs two answers');
    refused(static fn() => edu_question_save(A, $lq, ['question' => 'Q', 'options' => ['a', 'b']]), 'a question needs its right answer marked');
    $q1 = edu_question_save(A, $lq, ['question' => 'Two plus two?', 'options' => ['3', '', '4'], 'correct' => 2]);
    eq(edu_questions(A, $lq)[0]['correct'], 1, 'the right answer follows when an empty answer is dropped');
    $q2 = edu_question_save(A, $lq, ['question' => 'Sky?', 'options' => ['Blue', 'Green'], 'correct' => 0]);
    edu_course_status(A, $cAll, 'published');
    eq(edu_course(A, $cAll)['status'], 'published', 'a ready course publishes');
    $html = edu_render_text("# Hi\n- one\n**bold** <script>x</script> [site](https://example.org) javascript:alert(1)");
    ok(str_contains($html, '<h2>Hi</h2>') && str_contains($html, '<li>one</li>') && str_contains($html, '<strong>bold</strong>'), 'lesson text: headings, lists, bold');
    ok(!str_contains($html, '<script>') && str_contains($html, '&lt;script&gt;'), 'lesson text: HTML is escaped');
    ok(str_contains($html, '<a href="https://example.org" target="_blank" rel="noopener nofollow">site</a>'), 'lesson text: https links');
    ok(!str_contains($html, 'href="javascript'), 'lesson text: no javascript links');
    eq(edu_video_embed('https://www.youtube.com/watch?v=MT4Ig2uqjTc&t=5'), 'https://www.youtube-nocookie.com/embed/MT4Ig2uqjTc?rel=0', 'YouTube becomes a privacy-mode embed');
    eq(edu_video_embed('https://vimeo.com/76979871'), 'https://player.vimeo.com/video/76979871', 'Vimeo embeds');
    edu_lesson_move(A, $lq, -1);
    eq(array_column(edu_lessons(A, $cAll), 'title'), ['Read me', 'Quiz', 'Watch'], 'a lesson moves up');
    edu_lesson_move(A, $lq, 1);

    // Who sees what
    $ravi = edu_person_by_email(A, 'ravi@example.test');       // Alpha, year 3, from the import
    $asha = edu_person(A, $s);                                  // Alpha, year 4
    $kid = fabo_one('SELECT * FROM edu_people WHERE org_id = ? AND roll_no = "RB9"', 'i', [A]);     // Beta
    edu_lesson_save(A, $cBeta, ['title' => 'x', 'kind' => 'text', 'body' => 'Beta lesson']);
    edu_course_status(A, $cBeta, 'published');
    edu_lesson_save(A, $cY3, ['title' => 'x', 'kind' => 'text', 'body' => 'Year 3 lesson']);
    edu_course_status(A, $cY3, 'published');
    eq(array_column(edu_catalogue(A, $ravi), 'title'), ['For everyone', 'Year 3 only'], 'Ravi (Alpha, year 3) sees the all and year-3 courses');
    eq(array_column(edu_catalogue(A, $asha), 'title'), ['For everyone'], 'Asha (year 4) does not see the year-3 course');
    eq(array_column(edu_catalogue(A, $kid), 'title'), ['Beta only', 'For everyone'], 'a Beta student sees the Beta course');
    ok(edu_catalogue(A, edu_person(A, $m)) === [], 'a mentor with no student role sees no courses');
    refused(static fn() => edu_enrol(A, edu_course(A, $cBeta), $asha), 'a student cannot enrol on a course not for them');
    refused(static fn() => edu_lesson_complete(A, edu_lesson(A, $l1), $asha), 'a lesson cannot be done before enrolling');

    // Learning
    edu_enrol(A, edu_course(A, $cAll), $asha);
    edu_enrol(A, edu_course(A, $cAll), $asha);
    eq((int) fabo_one('SELECT COUNT(*) AS n FROM edu_enrolments WHERE course_id = ? AND person_id = ?', 'ii', [$cAll, $s])['n'], 1, 'enrolling twice keeps one');
    $e = edu_lesson_complete(A, edu_lesson(A, $l1), $asha);
    eq((int) $e['progress'], 33, 'one of three lessons: 33%');
    refused(static fn() => edu_lesson_complete(A, edu_lesson(A, $lq), $asha), 'a quiz is not "marked done"');
    $r = edu_quiz_submit(A, edu_lesson(A, $lq), $asha, [$q1 => 0, $q2 => 1]);
    ok($r['score'] === 0 && !$r['passed'], 'a wrong quiz fails');
    $r = edu_quiz_submit(A, edu_lesson(A, $lq), $asha, [$q1 => 1]);
    ok($r['score'] === 50 && !$r['passed'], 'half right, under a 60% mark, fails');
    $r = edu_quiz_submit(A, edu_lesson(A, $lq), $asha, [$q1 => 1, $q2 => 0]);
    ok($r['score'] === 100 && $r['passed'], 'all right passes');
    eq(edu_quiz_best(A, $lq, $s), ['best' => 100, 'attempts' => 3], 'attempts and the best score are kept');
    eq((int) $r['enrolment']['progress'], 66, 'two of three: 66%');
    ok($r['enrolment']['completed_at'] === null, 'not complete yet');
    $e = edu_lesson_complete(A, edu_lesson(A, $l2), $asha);
    ok($e['completed_at'] !== null && preg_match('/^[A-Z0-9]{12}$/', (string) $e['cert_code']) === 1, 'the last lesson completes the course with a certificate code');
    $cert = edu_certificate($e['cert_code']);
    ok($cert && $cert['person_name'] === 'Asha Student' && $cert['course_title'] === 'For everyone', 'the certificate verifies');
    ok(edu_certificate('AAAAAAAAAAAA') === null, 'a made-up certificate does not');
    edu_lesson_save(A, $cAll, ['title' => 'Added later', 'kind' => 'text']);
    $e2 = edu_enrolment(A, $cAll, $s);
    ok($e2['completed_at'] !== null && (int) $e2['progress'] === 100 && $e2['cert_code'] === $e['cert_code'], 'a lesson added later does not take a finished course away');
    edu_enrol(A, edu_course(A, $cAll), $ravi);
    edu_lesson_complete(A, edu_lesson(A, $l1), $ravi);
    $late = (int) fabo_one('SELECT id FROM edu_lessons WHERE course_id = ? AND title = "Added later"', 'i', [$cAll])['id'];
    eq((int) edu_enrolment(A, $cAll, (int) $ravi['id'])['progress'], 25, 'an unfinished student\'s progress counts the new lesson');
    edu_lesson_delete(A, $late);
    eq((int) edu_enrolment(A, $cAll, (int) $ravi['id'])['progress'], 33, '... and removing it recounts');
    eq(count(edu_course_results(A, $cAll)), 2, 'results list both students');
    $sum = edu_learning_summary(A, [$s, (int) $ravi['id']]);
    ok($sum && (int) $sum[0]['enrolled'] === 2 && (int) $sum[0]['completed'] === 1, 'learning summary per course');
    eq(edu_learning_summary(B, [$s]), [], 'B sees nothing of A\'s learning');

    /* ------------------------------------------------------ mentoring */
    $GLOBALS['mails'] = [];
    $when = date('Y-m-d', strtotime('+3 days')) . ' 10:00';
    refused(static fn() => edu_slot_add(A, $f, $when, 30, 'online', ''), 'only a mentor offers sessions');
    refused(static fn() => edu_slot_add(A, $m, date('Y-m-d H:i', time() - 3600), 30, 'online', ''), 'no sessions in the past');
    refused(static fn() => edu_slot_add(A, $m, $when, 30, 'online', 'http://insecure'), 'an online link must be https');
    eq(edu_slot_add(A, $m, $when, 30, 'online', 'https://meet.example/x', 3), 3, 'weekly repeats make three slots');
    refused(static fn() => edu_slot_add(A, $m, date('Y-m-d', strtotime('+3 days')) . ' 10:15', 30, 'online', ''), 'an overlapping slot is refused');
    $slots = edu_mentor_slots(A, $m, true);
    eq(count($slots), 3, 'the mentor sees their three slots');
    $sl = (int) $slots[0]['id'];
    eq(count(edu_open_slots_for(A, $s)), 3, 'Asha (linked to Mira) sees Mira\'s open slots');
    eq(count(edu_open_slots_for(A, (int) $ravi['id'])), 0, 'Ravi (not linked) sees none');
    refused(static fn() => edu_slot_book(A, $sl, $ravi, 'Hi'), 'a student cannot book a mentor who is not theirs');
    refused(static fn() => edu_slot_book(A, $sl, $asha, ''), 'a booking needs a topic');
    edu_slot_book(A, $sl, $asha, 'My idea');
    eq(edu_slot(A, $sl)['status'], 'booked', 'booked');
    ok(count($GLOBALS['mails']) === 1 && $GLOBALS['mails'][0][0] === 'mira@example.test', 'the mentor is emailed about the booking');
    edu_mentor_assign(A, $m, (int) $ravi['id']);
    refused(static fn() => edu_slot_book(A, $sl, $ravi, 'Me too'), 'a booked slot cannot be booked again');
    refused(static fn() => edu_slot_book(B, $sl, $asha, 'x'), 'B cannot book A\'s slot');
    refused(static fn() => edu_slot_finish(A, $sl, $m, 'done', ''), 'a session is recorded only once it has started');
    refused(static fn() => edu_slot_rate(A, $sl, $s, 5, ''), 'a session is rated only once held');
    edu_slot_cancel(A, $sl, $asha, 'student');
    ok(edu_slot(A, $sl)['status'] === 'open' && edu_slot(A, $sl)['student_id'] === null, 'a student cancelling opens the slot again');
    edu_slot_book(A, $sl, $asha, 'Again');
    $GLOBALS['mails'] = [];
    edu_slot_cancel(A, $sl, edu_person(A, $m), 'mentor');
    ok(edu_slot(A, $sl)['status'] === 'cancelled' && count($GLOBALS['mails']) === 1 && $GLOBALS['mails'][0][0] === 'asha@example.test', 'a mentor cancelling closes it and tells the student');
    refused(static fn() => edu_slot_cancel(A, (int) $slots[1]['id'], $asha, 'mentor'), 'a student cannot cancel as the mentor');
    // A session that has started: record and rate.
    $sl2 = (int) $slots[1]['id'];
    edu_slot_book(A, $sl2, $asha, 'Pitch practice');
    fabo_exec('UPDATE edu_slots SET starts_at = ? WHERE id = ?', 'si', [date('Y-m-d H:i:s', time() - 7200), $sl2]);
    refused(static fn() => edu_slot_cancel(A, $sl2, $asha, 'student'), 'a student cannot cancel inside the cut-off');
    refused(static fn() => edu_slot_finish(A, $sl2, $f, 'done', ''), 'only its mentor records a session');
    edu_slot_finish(A, $sl2, $m, 'done', 'Good progress');
    eq(edu_slot(A, $sl2)['status'], 'done', 'recorded as held');
    refused(static fn() => edu_slot_rate(A, $sl2, (int) $ravi['id'], 5, ''), 'only the student who came rates it');
    refused(static fn() => edu_slot_rate(A, $sl2, $s, 9, ''), 'a rating is one to five');
    edu_slot_rate(A, $sl2, $s, 5, 'Very helpful');
    eq((int) edu_slot(A, $sl2)['rating'], 5, 'rated');
    $tot = edu_mentoring_totals(A);
    ok((int) $tot['held_30'] === 1 && (float) $tot['rating'] === 5.0, 'mentoring totals');
    eq(count(edu_org_sessions(B)), 0, 'B sees none of A\'s sessions');
    // Removing a person takes their learning and sessions.
    $tmp = edu_person_save(A, ['name' => 'Temp Student', 'email' => 'temp@example.test']);
    edu_role_add(A, $tmp, 'student', $alpha);
    edu_mentor_assign(A, $m, $tmp);
    $tp = edu_person(A, $tmp);
    edu_enrol(A, edu_course(A, $cAll), $tp);
    $sl3 = (int) $slots[2]['id'];
    edu_slot_book(A, $sl3, $tp, 'x');
    edu_person_delete(A, $tmp);
    ok(!edu_enrolment(A, $cAll, $tmp) && edu_slot(A, $sl3)['status'] === 'open', 'removing a student removes their enrolments and frees their bookings');

    /* -------------------------------------------------------- rewards */
    require_once 'lib/edu/game.php';
    edu_game_install();
    // Asha so far: 2 lessons (10+2 each), quiz (20+5), perfect (10+5), course (100+25), session (30+10), rated (5), one learning day (5).
    eq(edu_balance(A, $s, 'xp'), 190, 'Asha\'s XP adds up from what she did');
    eq(edu_balance(A, $s, 'coin'), 49, 'Asha\'s coins add up');
    eq(edu_award(A, $s, 'lesson', 'lesson:' . $l1), [0, 0], 'the same lesson never pays twice');
    eq(edu_award(A, $m, 'lesson', 'lesson:' . $l1), [0, 0], 'only students earn');
    eq(edu_balance(B, $s, 'xp'), 0, 'A\'s XP is not B\'s');
    $bad = array_keys(edu_badges_of(A, $s));
    ok(!array_diff(['first_lesson', 'quiz_ace', 'first_course', 'mentored', 'feedback'], $bad), 'badges earned: ' . implode(',', $bad));
    eq([edu_level(0)['level'], edu_level(99)['level'], edu_level(100)['level'], edu_level(299)['level'], edu_level(300)['level']], [1, 1, 2, 2, 3], 'level thresholds');
    eq(edu_level(190)['name'], 'Learner', 'level 2 is Learner');
    eq(edu_league(5)['key'], 'gold', 'level 5 is Gold league');
    edu_set(A, 'game_coin_cap', '50');
    eq(edu_award(A, $s, 'course', 'course:999'), [100, 1], 'the daily coin cap stops coins, never XP');
    edu_set(A, 'game_coin_cap', '60');
    refused(static fn() => edu_adjust(A, $s, 'coin', -1000, 'oops', 'staff'), 'coins cannot go below zero');
    refused(static fn() => edu_adjust(A, $s, 'coin', 10, '', 'staff'), 'an adjustment needs a reason');
    edu_adjust(A, $s, 'coin', 100, 'Hackathon winner', 'staff');
    eq(edu_balance(A, $s, 'coin'), 150, 'a staff adjustment adds coins');
    eq(edu_balance(A, $s, 'xp'), 290, '... and leaves XP alone');

    // Store
    $it = edu_store_item_save(A, ['title' => 'Lab hour', 'price' => 60, 'stock' => 1, 'per_person' => 1, 'kind' => 'benefit', 'active' => 1]);
    $dig = edu_store_item_save(A, ['title' => 'Profile badge', 'price' => 10, 'kind' => 'digital', 'per_person' => 2, 'active' => 1]);
    $betaOnly = edu_store_item_save(A, ['title' => 'Beta mug', 'price' => 5, 'institution_id' => $beta, 'active' => 1]);
    $off = edu_store_item_save(A, ['title' => 'Hidden', 'price' => 5, 'active' => 0]);
    refused(static fn() => edu_store_item_save(A, ['title' => 'Free', 'price' => 0]), 'an item needs a price');
    refused(static fn() => edu_store_item_save(A, ['title' => 'X', 'price' => 5, 'institution_id' => $iB]), 'an item cannot target another organisation\'s institution');
    eq(array_column(edu_store_items(A, [$alpha]), 'title'), ['Profile badge', 'Lab hour'], 'Alpha students see active items for them');
    $o1 = edu_store_buy(A, $asha, $it, 'Tuesday please');
    eq(edu_balance(A, $s, 'coin'), 90, 'buying spends coins');
    eq(edu_balance(A, $s, 'xp'), 290, 'spending never lowers XP');
    eq((int) edu_store_item(A, $it)['stock'], 0, 'stock goes down');
    refused(static fn() => edu_store_buy(A, $asha, $it), 'one per person');
    $ravi2 = edu_person(A, (int) $ravi['id']);
    edu_adjust(A, (int) $ravi['id'], 'coin', 200, 'test coins', 'staff');
    refused(static fn() => edu_store_buy(A, $ravi2, $it), 'the last one cannot be sold twice (sold out)');
    refused(static fn() => edu_store_buy(A, $asha, $betaOnly), 'an item for another institution is refused');
    refused(static fn() => edu_store_buy(A, $asha, $off), 'an item off the shelf is refused');
    refused(static fn() => edu_store_buy(A, edu_person(A, $m), $dig), 'a mentor cannot shop');
    refused(static fn() => edu_store_buy(B, $asha, $dig), 'B cannot sell A\'s items');
    $o2 = edu_store_buy(A, $asha, $dig);
    eq(fabo_one('SELECT status FROM edu_orders WHERE id = ?', 'i', [$o2])['status'], 'fulfilled', 'a digital item is given at once');
    refused(static fn() => edu_order_decide(A, $o1, 'cancelled', 'x', '', (int) $ravi['id']), 'a student cannot cancel someone else\'s order');
    edu_order_decide(A, $o1, 'rejected', 'staff', 'Lab closed');
    eq(edu_balance(A, $s, 'coin'), 140, 'a rejected order refunds (90 - 10 badge + 60 back = 140)');
    eq((int) edu_store_item(A, $it)['stock'], 1, '... and puts the stock back');
    refused(static fn() => edu_order_decide(A, $o1, 'fulfilled', 'staff'), 'a decided order cannot be decided again');
    $o3 = edu_store_buy(A, $ravi2, $it);
    edu_order_decide(A, $o3, 'cancelled', 'Ravi', '', (int) $ravi['id']);
    eq(edu_balance(A, (int) $ravi['id'], 'coin'), 200 + 2, 'a student cancelling gets the coins back');
    $poor = edu_person_save(A, ['name' => 'Poor Student', 'email' => 'poor@example.test']);
    edu_role_add(A, $poor, 'student', $alpha);
    refused(static fn() => edu_store_buy(A, edu_person(A, $poor), $it), 'not enough coins');
    edu_set(A, 'game_on', '0');
    refused(static fn() => edu_store_buy(A, $ravi2, $dig), 'rewards switched off: no store');
    eq(edu_award(A, $s, 'lesson', 'lesson:77777'), [0, 0], 'rewards switched off: nothing is earned');
    edu_set(A, 'game_on', '1');

    // Kudos
    $GLOBALS['mails'] = [];
    edu_kudos(A, edu_person(A, $f), 'faculty', $s, 'Great demo');
    eq(edu_balance(A, $s, 'xp'), 305, 'kudos pay recognition XP');
    refused(static fn() => edu_kudos(A, edu_person(A, $f), 'faculty', $s, 'Again'), 'one kudos a day for the same student from the same giver');
    refused(static fn() => edu_kudos(A, edu_person(A, $f), 'faculty', (int) $kid['id'], 'x'), 'faculty cannot give kudos to another institution\'s student');
    refused(static fn() => edu_kudos(A, edu_person(A, $f), 'faculty', (int) $ravi['id'], ''), 'kudos need a note');
    refused(static fn() => edu_kudos(A, edu_person(A, $m), 'mentor', $poor, 'x'), 'a mentor gives kudos only to their own students');
    edu_set(A, 'game_kudos_cap', '1');
    refused(static fn() => edu_kudos(A, edu_person(A, $f), 'faculty', (int) $ravi['id'], 'Nice'), 'the monthly kudos allowance holds');
    edu_set(A, 'game_kudos_cap', '10');
    edu_kudos(A, edu_person(A, $m), 'mentor', $s, 'Thoughtful questions');
    ok(isset(edu_badges_of(A, $s)['kudos']), 'kudos earn the Recognised badge');
    eq(edu_kudos_left(A, $f), 9, 'kudos left this month');

    // Streak
    $rid = (int) $ravi['id'];
    fabo_exec('DELETE FROM edu_ledger WHERE person_id = ? AND reason = "day"', 'i', [$rid]);
    for ($d = 1; $d <= 6; $d++) {
        edu_ledger_add(A, $rid, 'xp', 5, 'day', 'day:' . date('Y-m-d', strtotime("-$d days")), '', '', date('Y-m-d 10:00:00', strtotime("-$d days")));
    }
    eq(edu_streak(A, $rid), 6, 'a run ending yesterday still counts');
    edu_game_day(A, $rid);
    eq(edu_streak(A, $rid), 7, 'today makes seven');
    ok((bool) fabo_one('SELECT id FROM edu_ledger WHERE person_id = ? AND reason = "streak7"', 'i', [$rid]) && isset(edu_badges_of(A, $rid)['streak_7']), 'seven days pay the streak bonus and badge');
    edu_game_day(A, $rid);
    eq((int) fabo_one('SELECT COUNT(*) AS n FROM edu_ledger WHERE person_id = ? AND currency = "xp" AND reason = "streak7"', 'i', [$rid])['n'], 1, 'a second visit the same day pays nothing more');

    // Rankings
    $lb = edu_leaderboard(A, 'all', ['inst' => $alpha]);
    eq([(int) $lb[0]['person_id'], $lb[0]['display']], [$s, 'Asha S.'], 'Asha tops Alpha, shown as first name and initial');
    eq(edu_leaderboard(A, 'all', ['inst' => $beta]), [], 'Beta has no XP yet');
    eq(edu_leaderboard(B, 'all'), [], 'B has its own (empty) rankings');
    fabo_exec('UPDATE edu_people SET board_hidden = 1 WHERE id = ?', 'i', [$s]);
    eq(edu_leaderboard(A, 'all', ['inst' => $alpha])[0]['display'], 'A student', 'a hidden student shows as "A student"');
    fabo_exec('UPDATE edu_people SET board_hidden = 0 WHERE id = ?', 'i', [$s]);
    edu_set(A, 'game_names', 'full');
    eq(edu_leaderboard(A, 'all', ['inst' => $alpha])[0]['display'], 'Asha Student', 'full names when the organisation chooses');
    edu_set(A, 'game_names', 'initial');
    $ib = edu_institution_board(A, 'all');
    ok($ib && $ib[0]['name'] === 'Alpha College' && (int) $ib[0]['active'] >= 2, 'institutions ranked by average XP');
    fabo_exec('UPDATE edu_ledger SET created_at = ? WHERE person_id = ? AND reason = "adjust" AND currency = "coin"', 'si', [date('Y-m-d H:i:s', strtotime('-40 days')), $s]);
    ok(count(edu_leaderboard(A, 'week')) >= 1, 'this week\'s table');

    // Backfill: rewards for work done before rewards existed.
    fabo_exec('DELETE FROM edu_ledger WHERE person_id = ? AND reason IN ("lesson","quiz","perfect","course","session","feedback") AND ref NOT LIKE "%999%" AND ref NOT LIKE "%77777%"', 'i', [$s]);
    $before = edu_balance(A, $s, 'xp');
    $paid = edu_game_backfill(A);
    eq(edu_balance(A, $s, 'xp') - $before, 185, 'backfill pays Asha\'s earlier lessons, quiz, perfect score, course, session and rating once');
    eq(edu_game_backfill(A), 0, 'backfill again pays nothing');
    $gt = edu_game_totals(A);
    ok($gt['xp'] > 0 && $gt['coins_spent'] > 0 && $gt['coins_refunded'] > 0, 'reward totals');

    /* ---------------------------------------------- ideas and incubation */
    require_once 'lib/edu/ideas.php';
    require_once 'lib/edu/reports.php';
    edu_ideas_install();
    $GLOBALS['inc_mail_hook'] = static function ($to, $s, $t) {
        $GLOBALS['mails'][] = [$to, $s, $t];
        return [true, ''];
    };
    refused(static fn() => edu_idea_save(A, $asha, ['title' => '', 'problem' => str_repeat('x', 30)]), 'an idea needs a name');
    refused(static fn() => edu_idea_save(A, $asha, ['title' => 'Short', 'problem' => 'too short']), 'an idea needs a real problem statement');
    refused(static fn() => edu_idea_save(A, edu_person(A, $m), ['title' => 'X', 'problem' => str_repeat('x', 30)]), 'ideas are for students');
    $xpBefore = edu_balance(A, $s, 'xp');
    $idea = edu_idea_save(A, $asha, ['title' => 'FarmPrice', 'problem' => 'Small farmers sell below fair price because they cannot see mandi rates.', 'solution' => 'A daily SMS of the nearest mandi prices, in Telugu, for every registered farmer.',
                                     'sector' => 'Agritech', 'stage' => 'prototype', 'team' => "Asha (lead)\nRavi (tech)"]);
    eq(edu_balance(A, $s, 'xp') - $xpBefore, 25, 'writing an idea earns XP');
    ok(isset(edu_badges_of(A, $s)['ideator']), 'the Ideator badge');
    refused(static fn() => edu_idea_save(A, $ravi2, ['title' => 'Hijack', 'problem' => str_repeat('x', 30)], $idea), 'another student cannot edit the idea');
    refused(static fn() => edu_idea_delete(A, $ravi2, $idea), 'another student cannot remove the idea');
    eq(count(edu_ideas_of(A, $s)), 1, 'the student sees their idea');
    eq(count(edu_ideas_list(B)), 0, 'B sees none of A\'s ideas');
    eq(count(edu_ideas_list(A, null, $m)), 1, 'the mentor sees their student\'s idea');
    eq(count(edu_ideas_list(A, [$beta])), 0, 'Beta\'s admin does not see an Alpha idea');
    // No Incubation Management yet: ideas, but no applying.
    ok(!edu_inc_available(A) && edu_inc_form(A)[0] === null, 'without Incubation Management students cannot apply');
    refused(static fn() => edu_idea_apply(A, $asha, $idea, [], [], ''), 'apply is refused without Incubation Management');
    // Give A Incubation Management with a published, public form.
    fabo_exec('INSERT INTO client_sheet_access (client_id, sheet_key, permission) VALUES (?,?,?)', 'iss', [A, 'incubation', 'edit']);
    ok(edu_inc_available(A), 'with the module, incubation is available');
    require_once 'lib/inc/render.php';
    inc_use_kind('inc');
    inc_start(fabo_admin_viewer(), A);
    $fid = inc_form_create(['name' => 'Student incubation', 'purpose' => 'incubation']);
    // A brand-new organisation has no spokes or sectors set up yet: those two questions optional for the test.
    inc_exec('UPDATE inc_form_fields SET required = 0 WHERE version_id = ? AND uid IN ("spoke", "sector")', 'i', [(int) inc_form_draft_version($fid)['id']]);
    inc_form_publish($fid);
    eq(edu_inc_form(A)[0], null, 'a form not yet chosen is not used');
    edu_set(A, 'inc_form_id', (string) $fid);
    ok(str_contains((string) edu_inc_form(A)[2], 'closed'), 'a form without its public link is reported closed');
    inc_exec('UPDATE inc_forms SET public_enabled = 1 WHERE id = ?', 'i', [$fid]);
    [$form, $version] = edu_inc_form(A);
    ok($form && $version, 'the chosen, published, public form is ready');
    ok(isset(edu_inc_forms(A)[$fid]), 'the desk lists the form');
    $fields = inc_form_fields((int) $version['id'], true);
    $pre = edu_inc_prefill($fields, edu_idea(A, $idea), edu_person(A, $s), 'Alpha College');
    $byMap = [];
    foreach ($fields as $f) {
        if ($f['maps_to']) {
            $byMap[$f['maps_to']] = $pre[$f['uid']] ?? null;
        }
    }
    ok(($byMap['applicant_name'] ?? '') === 'Asha Student' && ($byMap['applicant_email'] ?? '') === 'asha@example.test' && ($byMap['startup_name'] ?? '') === 'FarmPrice',
       'the form is filled in from the profile and idea: ' . json_encode($byMap));
    // A required question left empty is caught by Incubation's own checks.
    $raw = $pre;
    $need = null;
    foreach ($fields as $f) {
        if ((int) $f['required'] === 1 && $f['type'] !== 'section' && ($raw[$f['uid']] ?? '') === '') {
            $need = $f;
        }
    }
    if ($need) {
        try {
            edu_idea_apply(A, $asha, $idea, $raw, [], bin2hex(random_bytes(16)));
            ok(false, 'a missing required answer is refused');
        } catch (InvalidArgumentException $e) {
            ok(str_starts_with($e->getMessage(), 'fields:'), 'a missing required answer comes back as field errors');
        }
    }
    foreach ($fields as $f) {
        if ((int) $f['required'] !== 1 || $f['type'] === 'section' || ($raw[$f['uid']] ?? '') !== '' && ($raw[$f['uid']] ?? []) !== []) {
            continue;
        }
        $ch = inc_field_choices($f);
        $raw[$f['uid']] = match (true) {
            in_array($f['type'], ['checkbox', 'multiselect'], true) => [array_key_first($ch)],
            $f['type'] === 'yesno' => 'No',
            inc_is_choice($f['type']) => (string) array_key_first($ch),
            $f['type'] === 'email' => 'asha@example.test',
            $f['type'] === 'phone' => '9876543210',
            $f['type'] === 'number' => '1',
            $f['type'] === 'date' => date('Y-m-d'),
            $f['type'] === 'url' => 'https://example.org',
            default => 'Test answer for ' . $f['label'],
        };
    }
    $ticket = bin2hex(random_bytes(16));
    $GLOBALS['mails'] = [];
    $xpBefore = edu_balance(A, $s, 'xp');
    $ref = edu_idea_apply(A, $asha, $idea, $raw, [], $ticket);
    ok(preg_match('/-\d{4}-\d{5}$/', $ref) === 1, 'applying returns the incubation reference ' . $ref);
    $iRow = edu_idea(A, $idea);
    $app = inc_one('SELECT * FROM inc_applications WHERE id = ?', 'i', [(int) $iRow['inc_app_id']]);
    ok($app && $app['applicant_email'] === 'asha@example.test' && $app['startup_name'] === 'FarmPrice' && $app['channel'] === 'public', 'the application is in A\'s incubation pipeline');
    ok((bool) fabo_one("SHOW TABLES LIKE 'inc_o" . A . "_applications'"), '... in A\'s own incubation tables');
    ok((bool) inc_one('SELECT id FROM inc_activities WHERE application_id = ? AND summary LIKE "%Campus portal%"', 'i', [(int) $app['id']]), 'its timeline says it came from the Campus portal');
    eq(edu_balance(A, $s, 'xp') - $xpBefore, 75, 'applying earns XP');
    ok(isset(edu_badges_of(A, $s)['applicant']), 'the Founder in the making badge');
    ok(count(array_filter($GLOBALS['mails'], static fn($x) => $x[0] === 'asha@example.test')) <= 1, 'at most the incubation acknowledgement went to the student (hooked)');
    eq(edu_idea_apply(A, $asha, $idea, $raw, [], $ticket), $ref, 'applying twice gives the same reference');
    eq((int) inc_one('SELECT COUNT(*) AS n FROM inc_applications WHERE applicant_email = "asha@example.test"')['n'], 1, '... and one application');
    refused(static fn() => edu_idea_save(A, $asha, ['title' => 'Changed', 'problem' => str_repeat('x', 30)], $idea), 'a sent idea cannot be changed');
    refused(static fn() => edu_idea_delete(A, $asha, $idea), 'a sent idea cannot be removed');
    $st = edu_inc_status(A, [(int) $iRow['inc_app_id']]);
    eq($st[(int) $iRow['inc_app_id']]['stage'] ?? '', inc_first_stage('crm')['name'], 'the idea shows its pipeline stage');

    // Reports
    $rep = edu_report(A, date('Y-m-01'), date('Y-m-d'));
    $ar = $rep[$alpha];
    ok($ar['students'] >= 3 && $ar['ideas'] === 1 && $ar['applied'] === 1 && $ar['completions'] >= 1 && $ar['sessions'] === 1 && (float) $ar['rating'] === 5.0 && $ar['kudos'] >= 2,
       'report figures for Alpha: ' . json_encode(array_intersect_key($ar, array_flip(['students', 'ideas', 'applied', 'completions', 'sessions', 'rating', 'kudos', 'active']))));
    eq($ar['progressed'], 0, 'a new application has not passed first screening');
    $second = inc_one('SELECT id FROM inc_stages WHERE pipeline = "crm" AND active = 1 ORDER BY sort, id LIMIT 1 OFFSET 1');
    if ($second) {
        inc_exec('UPDATE inc_applications SET stage_id = ? WHERE id = ?', 'ii', [(int) $second['id'], (int) $app['id']]);
        eq(edu_report(A, date('Y-m-01'), date('Y-m-d'))[$alpha]['progressed'], 1, 'moving the application on counts it as progressed');
    }
    eq(array_keys(edu_report(A, date('Y-m-01'), date('Y-m-d'), [$beta])), [$beta], 'an institution admin\'s report has their institution only');
    eq(edu_report(A, '2001-01-01', '2001-12-31')[$alpha]['ideas'], 0, 'a period with nothing in it');
    $rb = edu_report(B, date('Y-m-01'), date('Y-m-d'));
    ok(array_keys($rb) === [$iB] && $rb[$iB]['ideas'] === 0 && $rb[$iB]['students'] === 0, 'B\'s report has only B\'s own institution, none of A');
    $tot = edu_report_total($rep);
    ok($tot['students'] >= $ar['students'], 'report totals');
    eq(edu_report_period(['from' => '2026-01-01', 'to' => '2026-03-31'])[0], 'custom', 'a custom period');
    eq(edu_report_period(['period' => 'nope'])[0], 'ay', 'the academic year by default');

    // The council role: organisation-wide, no institution.
    $cn = edu_person_save(A, ['name' => 'Council Officer', 'email' => 'council@example.test']);
    edu_role_add(A, $cn, 'council', $alpha, $d2);
    $cr = edu_person_roles(A, $cn)[0];
    ok($cr['role'] === 'council' && (int) $cr['institution_id'] === 0, 'a council role is organisation-wide');
    eq(edu_import_role('Government officer', 'student'), 'council', 'import reads "government" as Council');

    /* ---------------------------------------- data protection and security */
    require_once 'lib/edu/privacy.php';
    edu_privacy_install();
    // Passwords
    ok(edu_password_problem('short') !== null, 'a short password is refused');
    ok(edu_password_problem('password12345') !== null, 'a common password is refused');
    ok(edu_password_problem('aaaaaaaaaaaa') !== null, 'one repeated character is refused');
    ok(edu_password_problem('asha-rocks-2026', edu_person(A, $s)) !== null, 'a password with the person\'s name is refused');
    ok(edu_password_problem('river-mango-lantern', edu_person(A, $s)) === null, 'three unrelated words are fine');
    refused(static fn() => edu_password_set(A, $s, 'asha12345678'), 'setting a password checks the rules');
    $GLOBALS['mails'] = [];
    edu_password_set(A, $s, 'river-mango-lantern');
    ok(count(array_filter($GLOBALS['mails'], static fn($x) => $x[0] === 'asha@example.test' && str_contains($x[1], 'password was changed'))) === 1, 'a changed password is announced by email');
    // Two-step sign-in
    edu_set(A, 'mfa_mode', 'admins');
    ok(edu_mfa_required(A, edu_person(A, $adm)), 'an institution admin needs a code');
    ok(!edu_mfa_required(A, edu_person(A, $s)), 'a student does not, by default');
    ok(edu_mfa_required(A, edu_person(A, $cn)), 'the council needs a code');
    edu_set(A, 'mfa_mode', 'everyone');
    ok(edu_mfa_required(A, edu_person(A, $s)), '"everyone" asks every sign-in');
    edu_set(A, 'mfa_mode', 'admins');
    $samp = edu_person_save(A, ['name' => 'Sample Admin', 'email' => 'sample@demo.example']);
    edu_role_add(A, $samp, 'inst_admin', $alpha);
    ok(!edu_mfa_required(A, edu_person(A, $samp)), 'a sample (.example) admin is not asked (it cannot receive mail)');
    $GLOBALS['mails'] = [];
    [$sent] = edu_mfa_send(A, edu_person(A, $adm));
    $code = preg_match('/^(\d{6}) is your/', (string) ($GLOBALS['mails'][0][1] ?? ''), $mm) ? $mm[1] : '';
    ok($sent && strlen($code) === 6, 'a six-digit code is emailed');
    ok(!edu_mfa_check(A, edu_person(A, $adm), '000000' === $code ? '111111' : '000000'), 'a wrong code fails');
    ok(!edu_mfa_check(B, edu_person(A, $adm), $code), 'the code does not work in another organisation');
    ok(edu_mfa_check(A, edu_person(A, $adm), $code), 'the right code works');
    ok(!edu_mfa_check(A, edu_person(A, $adm), $code), '... once');
    edu_mfa_send(A, edu_person(A, $adm));
    for ($k = 0; $k < 5; $k++) {
        edu_mfa_check(A, edu_person(A, $adm), '999999' . $k);
    }
    $code2 = preg_match('/^(\d{6}) is your/', (string) (end($GLOBALS['mails'])[1] ?? ''), $mm) ? $mm[1] : '';
    ok(!edu_mfa_check(A, edu_person(A, $adm), $code2), 'five wrong codes spend the code');
    ok((bool) fabo_one('SELECT id FROM edu_security_log WHERE person_id = ? AND event = "code_locked"', 'i', [$adm]), 'the lock is in the security log');
    eq(edu_session_limits(A, $adm), [1800, 43200], 'admins: 30 minutes idle, 12 hours at most');
    eq(edu_session_limits(A, $s), [28800, 604800], 'students: 8 hours idle, 7 days at most');
    // Consent
    eq(edu_consent_gate(A, edu_person(A, $s)), 'consent', 'nobody has agreed yet: the notice comes first');
    edu_consent_give(A, edu_person(A, $s), false);
    eq(edu_consent_gate(A, edu_person(A, $s)), '', 'agreed: the portal opens');
    edu_consent_give(A, edu_person(A, $m), true);
    eq((int) edu_consent_of(A, $m)['minor'], 0, 'only a student can be recorded as under 18');
    refused(static fn() => edu_consent_give(A, $ravi2, true), 'a minor must name a guardian');
    refused(static fn() => edu_consent_give(A, $ravi2, true, 'Me', 'ravi@example.test'), 'the guardian\'s email is not the student\'s own');
    $GLOBALS['mails'] = [];
    edu_consent_give(A, $ravi2, true, 'Lakshmi Kumar', 'parent@example.test');
    eq(edu_consent_gate(A, edu_person(A, (int) $ravi['id'])), 'guardian', 'a minor waits for the guardian');
    $glink = preg_match('/guardian=([0-9a-f]{48})/', (string) ($GLOBALS['mails'][0][2] ?? ''), $gm) ? $gm[1] : '';
    ok($GLOBALS['mails'][0][0] === 'parent@example.test' && $glink !== '', 'the guardian is emailed a link');
    edu_set(A, 'guardian_required', '0');
    eq(edu_consent_gate(A, edu_person(A, (int) $ravi['id'])), '', 'with guardian consent switched off, the portal opens');
    edu_set(A, 'guardian_required', '1');
    ok(edu_guardian_consent('f' . substr($glink, 1)) === null || substr($glink, 0, 1) === 'f', 'a made-up guardian link finds nothing');
    ok(edu_guardian_confirm($glink, true) !== null, 'the guardian agrees');
    eq(edu_consent_gate(A, edu_person(A, (int) $ravi['id'])), '', 'then the portal opens');
    ok(edu_guardian_confirm($glink, true) === null, 'the guardian link works once');
    $v1 = edu_notice_version(A);
    edu_set(A, 'privacy_notice', edu_notice_text(A) . "\n\nA new line.");
    ok(edu_notice_version(A) !== $v1 && edu_consent_gate(A, edu_person(A, $s)) === 'consent', 'a changed notice asks everyone again');
    edu_consent_give(A, edu_person(A, (int) $ravi['id']), true, 'Lakshmi Kumar', 'parent@example.test');
    eq(edu_consent_gate(A, edu_person(A, (int) $ravi['id'])), '', 'a guardian who already agreed is not asked again for a new notice');
    edu_set(A, 'privacy_notice', '');
    $ctt = edu_consent_totals(A);
    ok(isset($ctt['people'], $ctt['current'], $ctt['minors'], $ctt['guardian_ok']) && $ctt['current'] <= $ctt['people'], 'consent totals');
    // Rights
    $ex = edu_person_export(A, $s);
    ok(isset($ex['person'], $ex['courses'], $ex['rewards_ledger'], $ex['ideas'], $ex['consents']) && !isset($ex['person']['pass_hash']) && !isset($ex['person']['invite_hash']),
       'the data export has everything, and no password hash');
    ok(count($ex['courses']) >= 1 && count($ex['ideas']) === 1, 'the export carries their courses and ideas');
    refused(static fn() => edu_request_make(A, $asha, 'correct', 'x'), 'a correction must say what is wrong');
    $rq = edu_request_make(A, $asha, 'correct', 'My year is 3, not 4');
    refused(static fn() => edu_request_make(A, $asha, 'correct', 'Again please'), 'one open request of a kind');
    refused(static fn() => edu_request_decide(A, $rq, 'rejected', 'staff'), 'declining needs a reason');
    edu_request_decide(A, $rq, 'done', 'staff', 'Fixed');
    eq(fabo_one('SELECT status FROM edu_requests WHERE id = ?', 'i', [$rq])['status'], 'done', 'a correction is marked done');
    refused(static fn() => edu_request_decide(B, $rq, 'done', 'staff'), 'B cannot act on A\'s requests');
    $er = edu_person_save(A, ['name' => 'Erase Me', 'email' => 'erase.me@example.test']);
    edu_role_add(A, $er, 'student', $alpha);
    edu_consent_give(A, edu_person(A, $er), false);
    $GLOBALS['mails'] = [];
    $rqe = edu_request_make(A, edu_person(A, $er), 'erase', 'Leaving');
    edu_request_decide(A, $rqe, 'done', 'staff');
    ok(edu_person(A, $er) === null && !edu_consent_of(A, $er), 'erasing removes the person and their consents');
    $row = fabo_one('SELECT who, details FROM edu_requests WHERE id = ?', 'i', [$rqe]);
    ok($row['details'] === null && !str_contains($row['who'], 'erase.me@') && str_contains($row['who'], '•'), 'only a masked trace of an erased person stays: ' . $row['who']);
    ok(count(array_filter($GLOBALS['mails'], static fn($x) => $x[0] === 'erase.me@example.test' && str_contains($x[1], 'erased'))) === 1, 'the erased person is told');
    // Access log, retention
    edu_access(A, 'Staff tester', 'view', $s);
    ok((bool) fabo_one('SELECT id FROM edu_access_log WHERE org_id = ? AND person_id = ? AND actor = "Staff tester"', 'ii', [A, $s]), 'access is logged');
    edu_person_status(A, $poor, 'suspended');
    ok(in_array($poor, array_map('intval', array_column(edu_retention_review(A, 24), 'id')), true), 'a suspended account comes up for retention review');
    ok(!in_array($samp, array_map('intval', array_column(edu_retention_review(A, 6), 'id')), true), 'sample people are left out of the review');
    fabo_exec('INSERT INTO edu_security_log (org_id, person_id, event, created_at) VALUES (?,?,?,?)', 'iiss', [A, $s, 'old', edu_now(-400 * 86400)]);
    edu_privacy_trim();
    ok(!fabo_one('SELECT id FROM edu_security_log WHERE org_id = ? AND event = "old"', 'i', [A]), 'security events older than a year are trimmed');

    /* ------------------------------------------------ course builder v2 */
    require_once 'lib/edu/files.php';
    edu_builder_install();
    ok((bool) fabo_one("SHOW COLUMNS FROM edu_lessons LIKE 'section'") && (bool) fabo_one("SHOW TABLES LIKE 'edu_files'"), 'builder schema: sections and files');
    $fake = static function (string $name, string $bytes): array {
        $p = tempnam(sys_get_temp_dir(), 'edf');
        file_put_contents($p, $bytes);
        fabo_upload_assembled($p, true);       // as if it had arrived in pieces
        return ['name' => $name, 'tmp_name' => $p, 'error' => UPLOAD_ERR_OK, 'size' => strlen($bytes), 'type' => ''];
    };
    $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    $cb = edu_course_save(A, ['title' => 'Builder course', 'audience' => 'all', 'outcomes' => "Pitch an idea\n\n  Test with users  \n", 'prerequisites' => 'None'], 't');
    eq(edu_course(A, $cb)['outcomes'], "Pitch an idea\nTest with users", 'outcomes are kept one per line, trimmed');
    $s1 = edu_lesson_save(A, $cb, ['title' => 'Intro', 'kind' => 'text', 'section' => 'Week 1']);
    $s2 = edu_lesson_save(A, $cb, ['title' => 'Slides', 'kind' => 'document']);
    eq(edu_lesson(A, $s2)['section'], 'Week 1', 'a new lesson joins the last section');
    $s3 = edu_lesson_save(A, $cb, ['title' => 'Wrap', 'kind' => 'text', 'section' => 'Week 2', 'body' => 'Summary']);
    eq(array_map(static fn($g) => [$g[0], count($g[1])], edu_lesson_groups(edu_lessons(A, $cb))), [['Week 1', 2], ['Week 2', 1]], 'lessons group by section');
    refused(static fn() => edu_course_status(A, $cb, 'published'), 'a document lesson without a file blocks publishing');
    [$chk, $pct] = edu_course_checklist(A, edu_course(A, $cb));
    ok(!$chk[2][1] && str_contains($chk[2][2], 'Slides') && $pct < 100, 'the checklist names the document lesson without a file');
    refused(static fn() => edu_file_add(A, $cb, $s2, $fake('virus.exe', 'MZ'), 't'), 'an .exe is refused');
    refused(static fn() => edu_file_add(A, $cb, $s1 + 999999, $fake('a.pdf', $pdf), 't'), 'a file needs a lesson of the course');
    refused(static fn() => edu_file_add(B, $cb, $s2, $fake('a.pdf', $pdf), 't'), 'B cannot add files to A\'s course');
    $fd = edu_file_add(A, $cb, $s2, $fake('Week 1 deck.pdf', $pdf), 't');
    ok($fd && $fd['mime'] === 'application/pdf' && $fd['original_name'] === 'Week 1 deck.pdf', 'a PDF is stored with its name and type');
    ok(is_file(fabo_upload_dir() . '/' . $fd['stored_name']) && preg_match('/^[0-9a-f]{32}\.pdf$/', $fd['stored_name']) === 1, 'stored under a random name in uploads');
    $fx = edu_file_add(A, $cb, $s1, $fake('Brief.docx', 'PK' . str_repeat("\0", 40)), 't');
    eq([edu_file_tag($fd), edu_file_tag($fx), edu_file_inline($fd), edu_file_inline($fx)], ['pdf', 'doc', true, false], 'PDF opens inline, Word downloads');
    edu_course_status(A, $cb, 'published');
    eq(edu_course(A, $cb)['status'], 'published', 'with its file, the course publishes');
    refused(static fn() => edu_file_add(A, $cb, 0, $fake('cover.pdf', $pdf), 't', 'cover'), 'a cover must be an image');
    $cv1 = edu_file_add(A, $cb, 0, $fake('cover.png', $png), 't', 'cover');
    $cv2 = edu_file_add(A, $cb, 0, $fake('cover2.png', $png), 't', 'cover');
    ok((int) edu_course_cover(A, $cb)['id'] === (int) $cv2['id'] && !edu_file(A, (int) $cv1['id']) && !is_file(fabo_upload_dir() . '/' . $cv1['stored_name']), 'a new cover replaces the old one, file and all');
    // Who may open what
    $joined = edu_person_save(A, ['name' => 'Joined Student', 'email' => 'joined@example.test']);
    edu_role_add(A, $joined, 'student', $alpha);
    $outsider = edu_person_save(A, ['name' => 'Not Joined', 'email' => 'notjoined@example.test']);
    edu_role_add(A, $outsider, 'student', $alpha);
    edu_enrol(A, edu_course(A, $cb), edu_person(A, $joined));
    ok(edu_file_allowed_for(A, $fd, edu_person(A, $joined)), 'an enrolled student opens the lesson file');
    ok(!edu_file_allowed_for(A, $fd, edu_person(A, $outsider)), 'a student not on the course cannot');
    ok(edu_file_allowed_for(A, edu_course_cover(A, $cb), edu_person(A, $outsider)), '... but sees the cover of a course open to them');
    ok(!edu_file_allowed_for(A, $fd, edu_person(A, $m)), 'a mentor cannot open course files');
    ok(edu_file(B, (int) $fd['id']) === null, 'B cannot even find A\'s file');
    $html = edu_lesson_files_html(A, edu_lesson(A, $s2), static fn($f, $dl = false) => 'u' . $f['id'] . ($dl ? 'd' : ''));
    ok(str_contains($html, '<iframe src="u' . $fd['id'] . '#view=FitH"') && str_contains($html, 'Week 1 deck.pdf'), 'a document lesson shows its PDF in a viewer');
    $html = edu_lesson_files_html(A, edu_lesson(A, $s1), static fn($f, $dl = false) => 'u' . $f['id'] . ($dl ? 'd' : ''));
    ok(str_contains($html, 'Resources') && str_contains($html, 'href="u' . $fx['id'] . 'd"'), 'other lessons list files as downloadable resources');
    // Order and sections
    edu_lessons_reorder(A, $cb, [$s3, $s1, $s2]);
    eq(array_column(edu_lessons(A, $cb), 'title'), ['Wrap', 'Intro', 'Slides'], 'drag order is saved');
    edu_lessons_reorder(A, $cb, [$s1, 999999]);
    eq(array_column(edu_lessons(A, $cb), 'title'), ['Intro', 'Wrap', 'Slides'], 'unknown ids are ignored; missing lessons go last');
    // Removing takes the files
    $stored = fabo_upload_dir() . '/' . $fd['stored_name'];
    edu_lesson_delete(A, $s2);
    ok(!edu_file(A, (int) $fd['id']) && !is_file($stored), 'removing a lesson removes its files from disk');
    $storedC = fabo_upload_dir() . '/' . $cv2['stored_name'];
    edu_course_delete(A, $cb);
    ok(!edu_file(A, (int) $fx['id']) && !is_file($storedC), 'removing a course removes every file');

    /* ------------------------------------------------- content blocks */
    require_once 'lib/edu/blocks.php';
    edu_blocks_install();
    $cm = edu_course_save(A, ['title' => 'Mixed lessons', 'audience' => 'all'], 't');
    $lm = edu_lesson_save(A, $cm, ['title' => 'Class 1', 'kind' => 'text']);
    eq(edu_lesson_problem(A, edu_lesson(A, $lm)), '"Class 1" is empty: add some content.', 'an empty lesson cannot be published');
    $bt = edu_block_add(A, $lm, 'text', 0, ['body' => "## Welcome\nRead this first."]);
    $bv = edu_block_add(A, $lm, 'video');
    eq(edu_lesson_problem(A, edu_lesson(A, $lm)), 'A video in "Class 1" has no address yet.', 'a video block without its address blocks publishing');
    refused(static fn() => edu_block_save(A, $bv, ['url' => 'https://example.org/v.mp4']), 'a video block takes YouTube or Vimeo');
    refused(static fn() => edu_block_save(A, $bv, ['url' => 'javascript:alert(1)']), 'a block address must be https');
    edu_block_save(A, $bv, ['url' => 'https://youtu.be/MT4Ig2uqjTc', 'title' => 'Watch this']);
    $bf = edu_block_add_file(A, $lm, $fake('Slides.pdf', $pdf), 't');
    $bf2 = edu_block_add_file(A, $lm, $fake('Notes.docx', 'PK' . str_repeat("\0", 30)), 't', $bt);
    $bc = edu_block_add(A, $lm, 'callout', 0, ['style' => 'warning', 'title' => 'Deadline', 'body' => 'Submit by **Friday**.']);
    $bl = edu_block_add(A, $lm, 'link', $bt, ['url' => 'https://www.startupindia.gov.in/', 'title' => 'Startup India', 'body' => 'Read the scheme list']);
    refused(static fn() => edu_block_add(A, $lm, 'wizard'), 'an unknown block type is refused');
    refused(static fn() => edu_block_add(B, $lm, 'text'), 'B cannot add blocks to A\'s lesson');
    refused(static fn() => edu_block_save(B, $bt, ['body' => 'x']), 'B cannot change A\'s block');
    eq(array_map(static fn($b) => $b['type'], edu_blocks(A, $lm)), ['text', 'link', 'file', 'video', 'file', 'callout'], 'blocks sit where they were added (after a block, or at the end)');
    ok(edu_lesson_problem(A, edu_lesson(A, $lm)) === null, 'a lesson with complete blocks is ready');
    eq(edu_block_words(edu_block_summary(A, $cm)[$lm]), 'text · video · 2 documents · link · callout', 'the outline says what a lesson holds');
    edu_blocks_reorder(A, $lm, [$bv, $bt]);
    eq((int) edu_blocks(A, $lm)[0]['id'], $bv, 'blocks can be reordered');
    $html = edu_lesson_render(A, edu_lesson(A, $lm), static fn($f, $dl = false) => 'f' . $f['id'] . ($dl ? 'd' : ''));
    ok(str_contains($html, 'youtube-nocookie.com/embed/MT4Ig2uqjTc') && str_contains($html, '<h3>Welcome</h3>') && str_contains($html, 'cp-viewer')
       && str_contains($html, 'Notes.docx') && str_contains($html, 's-warning') && str_contains($html, '<strong>Friday</strong>') && str_contains($html, 'Startup India'),
       'students see every block: video, text, PDF viewer, document card, callout and link');
    ok(strpos($html, 'youtube-nocookie') < strpos($html, 'Welcome'), '... in the order set');
    $fileOfBlock = (int) edu_block(A, $bf)['file_id'];
    edu_block_delete(A, $bf);
    ok(!edu_file(A, $fileOfBlock), 'removing a document block removes its file');
    // A lesson from before blocks becomes blocks once, nothing lost.
    $old = edu_lesson_save(A, $cm, ['title' => 'Old video lesson', 'kind' => 'video', 'url' => 'https://youtu.be/MT4Ig2uqjTc', 'body' => 'Watch, then answer.']);
    edu_file_add(A, $cm, $old, $fake('Worksheet.pdf', $pdf), 't');
    ok(str_contains(edu_lesson_render(A, edu_lesson(A, $old), static fn($f, $dl = false) => 'x'), 'Watch, then answer.'), 'an old lesson still shows before it is converted');
    edu_blocks_migrate(A, edu_lesson(A, $old));
    eq(array_map(static fn($b) => $b['type'], edu_blocks(A, $old)), ['video', 'text', 'file'], 'converting keeps its video, text and file, in that order');
    edu_blocks_migrate(A, edu_lesson(A, $old));
    eq(count(edu_blocks(A, $old)), 3, 'converting twice adds nothing');
    $nb = count(edu_blocks(A, $lm));
    edu_lesson_delete(A, $lm);
    eq((int) fabo_one('SELECT COUNT(*) AS n FROM edu_blocks WHERE lesson_id = ?', 'i', [$lm])['n'], 0, 'removing a lesson removes its ' . $nb . ' blocks');
    edu_course_delete(A, $cm);
    eq((int) fabo_one('SELECT COUNT(*) AS n FROM edu_blocks WHERE lesson_id = ?', 'i', [$old])['n'], 0, 'removing a course removes its blocks');

    /* --------------------------------------------------------- access */
    $head = fabo_person_viewer(fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [A]));
    $staff = fabo_person_viewer(fabo_one('SELECT * FROM clients WHERE id = 991101'));
    fabo_session();
    $_SESSION['fabo_person'] = A;
    $_SESSION['fabo_pwv'] = fabo_person_fingerprint(A);
    eq(edu_grant($head), 'edit', 'the head holds Academic');
    eq(edu_org($head), A, 'the head works in their own organisation');
    $_SESSION['fabo_person'] = 991101;
    $_SESSION['fabo_pwv'] = fabo_person_fingerprint(991101);
    eq(edu_grant($staff), 'edit', 'a team member given it holds it');
    eq(edu_org($staff), A, '... in the head\'s organisation');
    fabo_exec('UPDATE client_sheet_access SET permission = "view" WHERE client_id = ? AND sheet_key = "academic"', 'i', [A]);
    eq(edu_grant($staff), 'view', 'a team member never holds more than the head');
    fabo_exec('DELETE FROM client_sheet_access WHERE client_id = ? AND sheet_key = "academic"', 'i', [A]);
    eq(edu_grant($staff), 'none', '... and loses it with the head');
    fabo_exec('INSERT INTO client_sheet_access (client_id, sheet_key, permission) VALUES (?,?,?)', 'iss', [A, 'academic', 'edit']);
    unset($_SESSION['fabo_person'], $_SESSION['fabo_pwv']);
    eq(edu_grant($head), 'none', 'a link holder without a session does not get Academic');
    ok(isset(edu_orgs()[A]) && isset(edu_orgs()[B]), 'the administrator sees both organisations');

    /* ------------------------------------------------------ desk pages */
    $_SESSION['fabo_person'] = A;
    $_SESSION['fabo_pwv'] = fabo_person_fingerprint(A);
    foreach (['overview' => 'Academic overview', 'institutions' => 'Alpha College', 'people' => 'Asha Student', 'import' => 'Download the template',
              'settings' => 'Open the portal to', 'courses' => 'New course', 'mentoring' => 'Pitch practice', 'rewards' => 'Asha Student', 'store' => 'Lab hour', 'ideas' => 'FarmPrice', 'reports' => 'Past first screening', 'privacy' => 'Requests to act on'] as $p => $want) {
        $_GET = ['p' => $p];
        $_REQUEST = $_GET;
        ob_start();
        try {
            edu_main($head);
        } catch (Throwable $e) {
            echo 'THREW ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine();
        }
        $html = (string) ob_get_clean();
        ok(str_contains($html, $want) && !str_contains($html, 'THREW') && !preg_match('/(Warning|Notice|Deprecated|Fatal error)/', $html),
           "desk page $p renders" . (str_contains($html, 'THREW') ? ' -- ' . substr($html, strpos($html, 'THREW'), 200) : ''));
        ok(!str_contains($html, 'Asha Elsewhere'), "desk page $p shows nothing of B");
    }
    $_GET = ['p' => 'person', 'id' => (string) $m];
    $_REQUEST = $_GET;
    ob_start();
    edu_main($head);
    $html = (string) ob_get_clean();
    ok(str_contains($html, 'Students they mentor') && str_contains($html, 'Asha Student'), 'a mentor\'s page lists their students');
    $_GET = ['p' => 'person', 'id' => (string) $sB];
    $_REQUEST = $_GET;
    ob_start();
    edu_main($head);
    $html = (string) ob_get_clean();
    foreach ([['course', $cAll, 'Read me'], ['course', $cAll, 'Asha Student', 'results'], ['lesson', $lq, 'Two plus two?'], ['lesson', $l2, 'youtube-nocookie']] as $t) {
        $_GET = ['p' => $t[0], 'id' => (string) $t[1]] + (isset($t[3]) ? ['tab' => $t[3]] : []);
        $_REQUEST = $_GET;
        ob_start();
        edu_main($head);
        $h2 = (string) ob_get_clean();
        ok(str_contains($h2, $t[2]) && !preg_match('/(Warning|Notice|Deprecated|Fatal error)/', $h2), "desk {$t[0]} " . ($t[3] ?? '') . " shows {$t[2]}");
    }
    $_GET = ['p' => 'course', 'id' => (string) $cAll];
    $_REQUEST = $_GET;
    ob_start();
    edu_main(fabo_person_viewer(fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [B])));
    ok(str_contains((string) ob_get_clean(), 'That course is not here'), 'B cannot open A\x27s course');
    ok(str_contains($html, 'That person is not here') && !str_contains($html, 'Asha Elsewhere'), 'A cannot open B\'s person by id');
} catch (Throwable $e) {
    ok(false, 'threw: ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
}

while (ob_get_level() > 0) {
    ob_end_clean();
}
cleanup();
echo "\n$pass passed, " . count($fail) . " failed\n";
exit($fail ? 1 : 0);

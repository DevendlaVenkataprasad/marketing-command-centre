<?php
/**
 * Incubation mentors / jury / department Gem stages self-test, LOCAL ONLY:
 *   php tests/inc_panels_selftest.php
 * Runs in a throwaway organisation (id 9901, tables inc_o9901_*) on the local
 * XAMPP database, and removes its own people afterwards. Refuses anywhere
 * else. Never deploy this. No email leaves the machine (inc_mail_hook).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
chdir(dirname(__DIR__));
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SCRIPT_NAME'] = '/incubation.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once 'lib/ui.php';
require_once 'lib/inc/app.php';
// Never on the live server (Hostinger keeps every site under /home/), only a development copy's own MySQL.
if (str_contains(str_replace('\\', '/', __DIR__), '/home/') || !in_array(DB_HOST, ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "Refusing: this test only runs on a local development copy.\n");
    exit(2);
}
fabo_boot();

$pass = 0;
$fail = [];
function ok(bool $c, string $what): void
{
    global $pass, $fail;
    if ($c) {
        $pass++;
    } else {
        $fail[] = $what;
        echo "FAIL  $what\n";
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
    } catch (Throwable $e) {
        ok(true, $what);
    }
}

const ORG = 9901;
$ids = [ORG, 990101, 990102, 990103, 990104];
$cleanup = static function () use ($ids): void {
    fabo_exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (fabo_all('SHOW TABLES') as $r) {
        $t = array_values($r)[0];
        if (str_starts_with($t, 'inc_o' . ORG . '_') || str_starts_with($t, 'eo_o' . ORG . '_')) {
            fabo_exec("DROP TABLE `$t`");
        }
    }
    $in = implode(',', $ids);
    fabo_exec("DELETE FROM client_sheet_access WHERE client_id IN ($in)");
    foreach (['notifications' => 'client_id'] as $t => $c) {
        try {
            fabo_exec("DELETE FROM `$t` WHERE $c IN ($in)");
        } catch (Throwable $e) {
        }
    }
    fabo_exec("DELETE FROM clients WHERE id IN ($in)");
    fabo_exec('SET FOREIGN_KEY_CHECKS = 1');
};
$cleanup();
register_shutdown_function(static function () use ($cleanup) {
    global $pass, $fail;
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (isset($GLOBALS['after_exit'])) {
        ($GLOBALS['after_exit'])();
    }
    $cleanup();
    echo "
$pass passed, " . count($fail) . " failed
";
    exit($fail ? 1 : 0);
});

function person(string $name, string $kind, ?int $parent, int $id): int
{
    fabo_exec('INSERT INTO clients (id, name, kind, email, token, parent_id, organisation, password_hash) VALUES (?,?,?,?,?,?,?,?)', 'issssiss',
              [$id, $name, $kind, strtolower(str_replace(' ', '.', $name)) . '@example.test', bin2hex(random_bytes(16)), $parent, 'Test Org', 'x']);
    return $id;
}
person('Panel Test Head', 'client', null, ORG);
$deptP = person('Dina Department', 'team', ORG, 990101);
$mentorP = person('Manoj Mentor', 'team', ORG, 990102);
$juryP = person('Jaya Juror', 'team', ORG, 990103);
$nobody = person('Nora Nobody', 'team', ORG, 990104);
fabo_exec('INSERT INTO client_sheet_access (client_id, sheet_key, permission) VALUES (?,?,?)', 'iss', [ORG, 'incubation', 'edit']);
$viewer = static fn(int $id): array => fabo_person_viewer(fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [$id]));

$GLOBALS['mails'] = [];
$GLOBALS['inc_mail_hook'] = static function ($to, $s, $t) {
    $GLOBALS['mails'][] = [$to, $s, $t];
    return [true, ''];
};
$as = static function (int $id) use ($viewer): void {
    inc_start($viewer($id));
};

try {
    /* ------------------------------------------------------------ schema */
    $as(ORG);
    eq(inc_ctx()['grant'], 'edit', 'the head owns their Incubation Management');
    eq((int) inc_setting('_schema'), INC_SCHEMA_VERSION, 'schema is at the current version');
    ok((bool) fabo_db()->query('SHOW COLUMNS FROM inc_o' . ORG . "_teams LIKE 'kind'")->num_rows, 'inc_teams.kind exists');
    ok((bool) fabo_db()->query('SHOW COLUMNS FROM inc_o' . ORG . "_dept_stages LIKE 'gem_ids'")->num_rows, 'inc_dept_stages.gem_ids exists');

    // The upgrade on an older table: drop the columns, step the version back, install again.
    fabo_exec('ALTER TABLE inc_o' . ORG . '_teams DROP COLUMN kind');
    fabo_exec('ALTER TABLE inc_o' . ORG . '_dept_stages DROP COLUMN gem_ids');
    inc_exec("UPDATE inc_settings SET v = '12' WHERE k = '_schema'");
    $GLOBALS['inc_settings_cache'] = [];
    inc_dept_kind_upgrade();
    ok((bool) fabo_db()->query('SHOW COLUMNS FROM inc_o' . ORG . "_teams LIKE 'kind'")->num_rows, 'the upgrade adds inc_teams.kind to an older table');
    ok((bool) fabo_db()->query('SHOW COLUMNS FROM inc_o' . ORG . "_dept_stages LIKE 'gem_ids'")->num_rows, 'the upgrade adds inc_dept_stages.gem_ids');
    inc_exec("UPDATE inc_settings SET v = ? WHERE k = '_schema'", 's', [(string) INC_SCHEMA_VERSION]);

    /* ------------------------------------------------- making the three */
    $dept = inc_team_save(null, ['name' => 'Legal', 'active' => 1, 'kind' => 'department']);
    $mentor = inc_team_save(null, ['name' => 'AgriTech mentors', 'active' => 1, 'kind' => 'mentor']);
    $jury = inc_team_save(null, ['name' => 'Final jury', 'active' => 1, 'kind' => 'jury']);
    refused(static fn() => inc_team_save(null, ['name' => 'Odd', 'active' => 1, 'kind' => 'wizard']), 'an unknown type is refused');
    inc_dept_members_save($dept, [$deptP]);
    inc_dept_members_save($mentor, [$mentorP]);
    inc_dept_members_save($jury, [$juryP]);
    eq(inc_dept_kind_of(inc_team($mentor)), 'mentor', 'saved as mentor');
    eq(inc_dept_kind_of(inc_team($jury)), 'jury', 'saved as jury');
    eq(array_keys(inc_teams_grouped()), ['department', 'mentor', 'jury'], 'grouped in order');
    eq(array_keys(inc_team_picker()), ['Departments', 'Mentors', 'Jury'], 'the picker groups once mentors or a jury exist');
    ok(str_contains(inc_options(inc_team_picker(), $jury), '<optgroup label="Jury"><option value="' . $jury . '" selected>'), 'optgroups render, with the selection');
    eq(count(inc_dept_stages($mentor)), 3, 'mentors start with their own three stages');

    /* --------------------------------------------- an application to assign */
    $form = inc_exec('INSERT INTO inc_forms (name, purpose, public_token) VALUES ("Test form", "incubation", ?)', 's', [bin2hex(random_bytes(16))]);
    $ver = inc_exec('INSERT INTO inc_form_versions (form_id, version_no) VALUES (?, 1)', 'i', [$form]);
    $first = (int) inc_one('SELECT id FROM inc_stages WHERE pipeline = "crm" ORDER BY sort, id LIMIT 1')['id'];
    $mk = static fn(string $n, int $k) => inc_exec('INSERT INTO inc_applications (ref, form_id, form_version_id, purpose, stage_id, applicant_name, applicant_email, startup_name, submitted_at)
                                                    VALUES (?,?,?,?,?,?,?,?,NOW())', 'siisisss', ['TST-' . $k, $form, $ver, 'incubation', $first, $n, 'app' . $k . '@example.test', $n . ' Labs']);
    $a1 = $mk('Asha', 1);
    $a2 = $mk('Bala', 2);
    $a3 = $mk('Chitra', 3);

    $GLOBALS['mails'] = [];
    eq(inc_dept_assign($a1, [$mentor, $jury], 'Please look'), 2, 'one application to the mentors and the jury, as from its Departments tab');
    eq((int) inc_one('SELECT stage_id FROM inc_applications WHERE id = ?', 'i', [$a1])['stage_id'], $first, 'its pipeline stage is unchanged');
    $to = array_column($GLOBALS['mails'], 0);
    ok(in_array('manoj.mentor@example.test', $to, true) && in_array('jaya.juror@example.test', $to, true), 'the mentor and the juror are emailed');

    $GLOBALS['mails'] = [];
    $r = inc_dept_assign_many([$a1, $a2, $a3], [$jury], 'Round 2');
    eq($r, ['done' => 2, 'already' => 1], 'bulk: two new, one already with the jury');
    eq(count(array_filter($GLOBALS['mails'], static fn($m) => $m[0] === 'jaya.juror@example.test')), 1, 'bulk: the juror gets one email, not one per application');

    /* ---------------------------------------------------------- the desks */
    $as($mentorP);
    ok(inc_can('desk') && !inc_can('view'), 'a mentor has the desk only');
    eq(array_keys(inc_my_desk_kinds()), ['mentor'], 'a mentor sees the mentor desk only');
    eq(array_keys(inc_my_depts('mentor')), [$mentor], 'and their own panel');
    eq(inc_my_depts('jury'), [], 'not the jury');
    ok(!inc_dept_may_intake($mentor), 'a mentor does not add applications: only the owner assigns them');
    refused(static fn() => inc_dept_set_stage((int) inc_one('SELECT id FROM inc_dept_assignments WHERE team_id = ? LIMIT 1', 'i', [$jury])['id'], 0), 'a mentor cannot work the jury\'s assignment');
    eq(array_column(inc_dept_board($mentor)['open'], 'application_id'), [$a1], 'the mentor desk holds exactly what was assigned');

    $as($juryP);
    eq(count(inc_dept_board($jury)['open']), 3, 'the jury desk holds its three');
    ok(inc_nav_desk_kinds($viewer($juryP), true) === ['jury'], 'the menu offers a juror the jury desk');

    $as($deptP);
    ok(inc_dept_may_intake($dept), 'a department may still add its own applications');

    $as($nobody);
    ok(!inc_can('desk'), 'someone on no panel has no desk');

    /* ----------------------------------------------- Gem stages on a desk */
    $as($juryP);
    $gem = (int) array_key_first(inc_gems(true));
    ok($gem > 0, 'the organisation has a Gem (Gem 1 seeded)');
    inc_dept_stage_save($jury, null, 'Gem scoring', false, [$gem, 999999]);
    $gs = inc_one('SELECT * FROM inc_dept_stages WHERE team_id = ? AND name = "Gem scoring"', 'i', [$jury]);
    eq(inc_dept_stage_gem_ids($gs), [$gem], 'the jury adds its own Gem stage (an unknown Gem is dropped)');
    inc_dept_stage_save($jury, (int) $gs['id'], 'Gem scoring', false, null);
    eq(inc_dept_stage_gem_ids(inc_one('SELECT * FROM inc_dept_stages WHERE id = ?', 'i', [(int) $gs['id']])), [$gem], 'saving without the Gem ticks leaves them');

    $asg = (int) inc_one('SELECT id FROM inc_dept_assignments WHERE team_id = ? AND application_id = ?', 'ii', [$jury, $a2])['id'];
    $GLOBALS['mails'] = [];
    $r = inc_dept_gem_send($jury, $gem, [$asg]);
    eq($r['sent'] + $r['queued'], 1, 'the jury emails the Gem form');
    eq($GLOBALS['mails'][0][0] ?? '', 'app2@example.test', 'to the applicant');
    $mentorAsg = (int) inc_one('SELECT id FROM inc_dept_assignments WHERE team_id = ? LIMIT 1', 'i', [$mentor])['id'];
    $r = inc_dept_gem_send($jury, $gem, [$mentorAsg]);
    eq($r['sent'] + $r['queued'], 0, 'another panel\'s application is not sent from the jury desk');

    // An answered, scored request: the score shows only at the Gem stage.
    inc_exec('UPDATE inc_gem_requests SET submitted_at = NOW(), answers_json = ?, score = 72, breakdown_json = "[]", scored_at = NOW() WHERE gem_id = ? AND application_id = ?',
             'sii', [json_encode([['label' => 'Problem', 'value' => 'Water']]), $gem, $a2]);
    $stages = inc_dept_stages($jury);
    $row = inc_one('SELECT * FROM inc_dept_assignments WHERE id = ?', 'i', [$asg]);
    eq(inc_dept_gem_scores([$row], $stages), [], 'no score at the first stage');
    ok(!str_contains(inc_dept_gem_html(inc_app($a2), $row, $stages), 'inc-gemscore'), 'the opened application hides the score away from a Gem stage');
    inc_dept_set_stage($asg, (int) $gs['id']);
    $row = inc_one('SELECT * FROM inc_dept_assignments WHERE id = ?', 'i', [$asg]);
    eq(count(inc_dept_gem_scores([$row], $stages)[$asg] ?? []), 1, 'the score shows at the Gem stage');
    ok(str_contains(inc_dept_gem_html(inc_app($a2), $row, $stages), 'inc-gemscore'), 'the opened application shows the score at the Gem stage');

    /* ------------------------------------------------- pages render */
    $render = static function (array $get) : string {
        $_GET = $get + ['p' => 'desk'];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $GLOBALS['inc_page'] = 'desk';
        ob_start();
        try {
            include 'lib/inc/pages/desk.php';
        } finally {
            $h = ob_get_clean();
        }
        return $h;
    };
    $h = $render(['kind' => 'jury']);
    ok(str_contains($h, 'Final jury') && str_contains($h, '◆ Gem'), 'the jury desk renders, with its Gem stage flagged');
    ok(!str_contains($h, '+ Add application'), 'no Add application on the jury desk');
    ok(str_contains($h, 'Send Gem form'), 'the selection bar can send a Gem form');
    $h = $render(['kind' => 'jury', 'dept' => $jury, 'a' => $asg]);
    ok(str_contains($h, 'Return with your verdict'), 'an opened jury application asks for a verdict');

    $as(ORG);
    $h = $render(['kind' => 'mentor']);
    ok(str_contains($h, 'Mentor desk') && str_contains($h, 'AgriTech mentors'), 'the owner opens the mentor desk');
    ok(str_contains(inc_nav_html('inc-desk', true, false, $viewer(ORG)), 'Jury desk'), 'the owner\'s menu lists the Jury desk');

    $page = static function (string $p, array $get): string {
        $_GET = $get + ['p' => $p];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $GLOBALS['inc_page'] = $p;
        ob_start();
        try {
            include 'lib/inc/pages/' . $p . '.php';
        } finally {
            $h = ob_get_clean();
        }
        return $h;
    };
    $h = $page('application', ['id' => $a3, 'tab' => 'depts']);
    ok(str_contains($h, '<legend>Mentors</legend>') && str_contains($h, '<legend>Jury</legend>'), 'the application\'s tab groups departments, mentors and jury');
    $h = $page('applications', []);
    ok(str_contains($h, 'Assign to department / mentor / jury') && str_contains($h, '<optgroup label="Mentors">'), 'the Applications bulk bar can assign to mentors or jury');
    $h = $page('settings', ['tab' => 'teams']);
    ok(str_contains($h, 'name="kind"') && str_contains($h, '>Mentor</option>') && str_contains($h, '>Jury</option>'), 'Settings → Departments has the Type dropdown');

    // A real POST through the desk: the owner ticks a Gem on the mentors' first stage.
    $ms = inc_dept_stages($mentor)[0];
    $_POST = ['action' => 'stage_save', 'id' => $ms['id'], 'name' => $ms['name'], 'gem_form' => '1', 'gem_ids' => [$gem], 'csrf' => fabo_csrf_token()];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_GET = ['p' => 'desk', 'dept' => $mentor];
    // inc_redirect() exits on success: the check runs from the shutdown handler, before the clean-up.
    $GLOBALS['after_exit'] = static fn() => eq(inc_dept_stage_gem_ids(inc_one('SELECT * FROM inc_dept_stages WHERE id = ?', 'i', [(int) $ms['id']])), [$gem], 'the desk form saves a Gem stage');
    ob_start();
    include 'lib/inc/pages/desk.php';
    ob_end_clean();
    ok(false, 'the desk POST should have redirected (flash: ' . json_encode($flash ?? null) . ')');
} catch (Throwable $e) {
    $fail[] = 'threw: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
    echo 'FAIL  threw: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
}
// The results and the clean-up: the shutdown handler near the top.

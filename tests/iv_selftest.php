<?php
/**
 * Startup Discovery self-test, LOCAL ONLY:
 *   php tests/iv_selftest.php         rules, uniqueness, limits, pipeline, organisation separation, pages
 *   php tests/iv_selftest.php seed    leaves org 9921 with a round, a HoD (password 'hod-pass-9921x') and a form (for HTTP tests)
 *   php tests/iv_selftest.php clean   removes orgs 9921 / 9922 and everything in them
 * Runs in throwaway organisations 9921 and 9922 on the local database and
 * refuses anywhere else. Never deploy this. No email or AI call leaves the machine.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
chdir(dirname(__DIR__));
ob_start();
$_SERVER['HTTP_HOST'] = 'localhost:8766';
$_SERVER['SCRIPT_NAME'] = '/idea-validation.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once 'lib/ui.php';
require_once 'lib/iv/app.php';
require_once 'lib/iv/portal.php';
if (str_contains(str_replace('\\', '/', __DIR__), '/home/') || !in_array(DB_HOST, ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "Refusing: this test only runs on a local development copy.\n");
    exit(2);
}
fabo_boot();
iv_install();

const A = 9921;
const B = 9922;
$GLOBALS['iv_mail_hook'] = static function ($to, $s, $t) {
    $GLOBALS['mails'][] = [$to, $s, $t];
};
$GLOBALS['mails'] = [];

function cleanup(): void
{
    foreach (['iv_settings', 'iv_colleges', 'iv_departments', 'iv_hods', 'iv_students', 'iv_rounds', 'iv_dept_windows', 'iv_forms', 'iv_ideas', 'iv_ai_runs', 'iv_team', 'iv_log', 'iv_audit', 'iv_imports', 'iv_import_rows'] as $t) {
        fabo_exec("DELETE FROM `$t` WHERE org_id IN (" . A . ',' . B . ')');
    }
    fabo_exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (fabo_all('SHOW TABLES') as $r) {
        $t = array_values($r)[0];
        if (preg_match('/^(inc|eo)_o(' . A . '|' . B . ')_/', $t)) {
            fabo_exec("DROP TABLE `$t`");
        }
    }
    fabo_exec('SET FOREIGN_KEY_CHECKS = 1');
    fabo_exec('DELETE FROM client_sheet_access WHERE client_id IN (' . A . ',' . B . ',992101)');
    fabo_exec('DELETE FROM clients WHERE id IN (' . A . ',' . B . ',992101)');
    fabo_exec("DELETE FROM app_settings WHERE k LIKE 'throttle%' AND 0");
}
function client(int $id, string $name, ?int $parent = null, string $kind = 'client'): void
{
    fabo_exec('INSERT INTO clients (id, name, kind, email, token, parent_id, organisation, password_hash) VALUES (?,?,?,?,?,?,?,?)', 'issssiss',
              [$id, $name, $kind, 'iv' . $id . '@example.test', bin2hex(random_bytes(16)), $parent, $parent ? '' : $name, 'x']);
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
    ok($got === $want, $what . ($got === $want ? '' : ' (got ' . var_export($got, true) . ', want ' . var_export($want, true) . ')'));
}
function throws(callable $fn, string $what, string $contains = ''): void
{
    try {
        $fn();
        ok(false, $what . ' (did not throw)');
    } catch (InvalidArgumentException $e) {
        ok($contains === '' || stripos($e->getMessage(), $contains) !== false, $what . ($contains !== '' && stripos($e->getMessage(), $contains) === false ? ' (said: ' . $e->getMessage() . ')' : ''));
    }
}

$mode = $argv[1] ?? 'test';
cleanup();
if ($mode === 'clean') {
    ob_end_clean();
    echo "cleaned\n";
    exit(0);
}
client(A, 'IV Test Board');
client(B, 'Other IV Org');
client(992101, 'Board Staffer', A, 'team');
fabo_exec('INSERT INTO client_sheet_access (client_id, sheet_key, permission) VALUES (?,?,?), (?,?,?), (?,?,?), (?,?,?)', 'ississississ',
          [A, 'ideaval', 'edit', B, 'ideaval', 'edit', A, 'incubation', 'edit', 992101, 'ideaval', 'view']);

// Incubation for org A: its own tables, with the built-in Gem 1 seeded.
require_once 'lib/inc/render.php';
inc_use_kind('inc');
inc_start(fabo_admin_viewer(), A);
$gem = null;
foreach (iv_gems(A) as $g) {
    $gem = $g;
    break;
}

/* ============================================================ dashboards */
$c1 = iv_college_find(A, '155', 'GOVT POLYTECHNIC, PILLARIPATTU', 'Chittoor', true, 'Polytechnic');
$cm = iv_department_save(A, $c1, '', 'CM');
$ec = iv_department_save(A, $c1, 'Electronics and Communication Engineering', 'EC');
$hCm = iv_hod_save(A, $cm, ['name' => 'Dr. Kumar', 'email' => 'Hod.CM@poly155.example', 'phone' => '9876543210']);
if ($mode !== 'seed') {
    eq(iv_department(A, $cm)['name'], 'Computer Engineering', 'a department made from a branch code is named for it');
    eq(iv_hod(A, $hCm)['email'], 'hod.cm@poly155.example', 'HoD email is kept lower-case');
    throws(static fn() => iv_hod_save(A, $ec, ['name' => 'Same Person', 'email' => 'hod.cm@poly155.example']), 'one email cannot head two departments', 'already the Head');
    throws(static fn() => iv_department_save(A, $c1, 'Computer Engineering'), 'no second department with one name in a college', 'already has');
    throws(static fn() => iv_hod_save(A, $ec, ['name' => 'X', 'email' => 'not-an-email']), 'a HoD needs a real email', 'valid email');
    // The same address can head a department in another organisation.
    $cB = iv_college_save(B, ['name' => 'B College']);
    $dB = iv_department_save(B, $cB, 'Computer Engineering', 'CM');
    ok(iv_hod_save(B, $dB, ['name' => 'Dr. Kumar', 'email' => 'hod.cm@poly155.example']) > 0, 'one email may head a department in each organisation');

    // Bulk dashboards: twice changes nothing the second time.
    $sheet = [
        ['college_code' => '155', 'college' => 'GOVT POLYTECHNIC, PILLARIPATTU', 'district' => 'Chittoor', 'department' => 'Electronics and Communication Engineering', 'branch' => 'EC', 'hod_name' => 'Smt. Lakshmi', 'hod_email' => 'hod.ec@poly155.example', 'hod_phone' => '9876543211'],
        ['college' => '284 ADITYA INSTITUTE OF TECHNOLOGY AND MGMT, TEKKALI', 'district' => 'Srikakulam', 'department' => '', 'branch' => 'M', 'hod_name' => 'Sri Rao', 'hod_email' => 'hod.m@aditya.example'],
        ['college' => '', 'department' => 'Civil', 'hod_name' => 'Nobody', 'hod_email' => 'n@x.example'],
        ['college_code' => '999', 'department' => 'Civil', 'hod_name' => 'Nobody', 'hod_email' => 'n2@x.example'],
    ];
    $r1 = iv_dashboards_import(A, $sheet, true);
    eq([$r1['colleges'], $r1['depts'], $r1['hods'], $r1['invited'], count($r1['skipped'])], [1, 1, 2, 2, 2], 'bulk dashboards: one new college, one new department, two Heads, two links, two bad rows');
    $r2 = iv_dashboards_import(A, $sheet, true);
    eq([$r2['colleges'], $r2['depts'], $r2['invited']], [0, 0, 0], 'the same sheet again makes nothing new and sends nothing');
    eq((int) fabo_one('SELECT COUNT(*) n FROM iv_colleges WHERE org_id = ?', 'i', [A])['n'], 2, 'colleges are not repeated');
    ok(str_contains($GLOBALS['mails'][0][2] ?? '', 'hod.php?o='), 'the HoD invitation carries the portal link');
    $m = iv_department(A, iv_department_find(A, iv_college_find(A, '284', '', '', false), 'M', '', false));
    eq($m['name'] ?? '', 'Mechanical Engineering', 'branch M is Mechanical Engineering');
}

/* ============================================================== students */
$s1 = iv_student_save(A, ['name' => 'Ravi', 'roll_no' => '24155-cm-001', 'email' => 'Ravi@x.example', 'phone' => '+91 90000 00001']);
if ($mode !== 'seed') {
    eq($s1[1], 'added', 'a new student is added');
    eq(iv_student(A, $s1[0])['roll_no'], '24155-CM-001', 'PIN is written the one way');
    eq(iv_student(A, $s1[0])['phone'], '9000000001', 'phone keeps its last ten digits');
    eq(iv_student_save(A, ['name' => 'Ravi Teja', 'roll_no' => '24155CM001'])[0], $s1[0], 'the same PIN written differently is the same student');
    eq(iv_student(A, $s1[0])['name'], 'Ravi', 'a later import keeps the name already on the record (no flip-flopping between spellings)');
    $sib = iv_student_save(A, ['name' => 'Ravi\'s sister', 'roll_no' => '24155-CM-002', 'email' => 'ravi@x.example']);
    ok($sib[0] !== $s1[0] && $sib[1] === 'added', 'a sibling sharing an email but with her own PIN is her own record');
    $e1 = iv_student_save(A, ['name' => 'Email Only', 'email' => 'only@x.example']);
    eq(iv_student_save(A, ['name' => 'Email Only', 'email' => 'only@x.example', 'roll_no' => '24155-EC-010'])[0], $e1[0], 'a PIN arriving later joins the email-only record');
    eq(iv_student($e1[0] ? A : A, $e1[0])['ukey'], 'r:24155-EC-010', 'its key becomes the PIN');
    throws(static fn() => iv_student_save(A, ['name' => 'No id']), 'a student needs a PIN, email or phone', 'roll number');

    // Upload: department from the PIN's branch, college from "code name".
    $imp = iv_students_import(A, [
        ['name' => 'Asha', 'roll_no' => '24155-EC-020', 'college' => '155 GOVT POLYTECHNIC, PILLARIPATTU', 'email' => 'asha@x.example'],
        ['name' => 'Asha', 'roll_no' => '24155-EC-020', 'college' => '155 GOVT POLYTECHNIC, PILLARIPATTU', 'email' => 'asha@x.example'],
        ['name' => 'Bala', 'roll_no' => '24155-ME-021', 'college' => '155 GOVT POLYTECHNIC, PILLARIPATTU'],
        ['name' => 'Chitra', 'roll_no' => '24777-CM-001', 'college' => '777 NEW POLY'],
        ['name' => '', 'roll_no' => ''],
    ], ['make' => false]);
    eq([$imp['added'], $imp['same'], count($imp['skipped'])], [2, 1, 2], 'upload: a repeated row is filed once; an unknown college and an empty row are skipped');
    $asha = iv_student_match(A, '24155-EC-020', '', '');
    eq((int) $asha['department_id'], $ec, 'the EC in the PIN puts Asha in Electronics and Communication');
    eq((int) iv_student_match(A, '24155-ME-021', '', '')['department_id'], 0, 'a branch with no department stays unplaced when not making');

    // From Incubation: form 6 style, with duplicates.
    $fid = inc_form_create(['name' => 'POLY TECHFEST team leads', 'purpose' => 'program', 'event_name' => 'POLY TECHFEST']);
    $ver = (int) inc_form_draft_version($fid)['id'];
    $stage = (int) inc_first_stage('crm')['id'];
    $rowsIn = [['Pujari S', 'p1@x.example', '6302572839', '24155-CM-050', '155 GOVT POLYTECHNIC, PILLARIPATTU', 'Chittoor'],
               ['Pujari S', 'p1@x.example', '6302572839', '24155-CM-050', '155 GOVT POLYTECHNIC, PILLARIPATTU', 'Chittoor'],
               ['Kiran', 'k@x.example', '6302500000', '24284-M-007', '284 ADITYA INSTITUTE OF TECHNOLOGY AND MGMT, TEKKALI', 'Srikakulam'],
               ['New Poly Kid', 'npk@x.example', '', '25311-EE-003', '311 GOVT POLYTECHNIC, NEWTOWN', 'Guntur']];
    foreach ($rowsIn as $n => $r) {
        $aid = inc_exec('INSERT INTO inc_applications (ref, form_id, form_version_id, purpose, applicant_name, applicant_email, applicant_phone, stage_id) VALUES (?,?,?,?,?,?,?,?)',
                        'siissssi', ['T-' . $n, $fid, $ver, 'program', $r[0], $r[1], $r[2], $stage]);
        foreach ([['f_pin', 'PIN (XXXXX-XX-XXX)', $r[3]], ['f_col', 'Code and Name of the Polytechnic', $r[4]], ['f_dis', 'Name of the District', $r[5]]] as [$u, $l, $v]) {
            inc_exec('INSERT INTO inc_app_values (application_id, field_uid, label, type, value_short) VALUES (?,?,?,?,?)', 'issss', [$aid, $u, $l, 'text', $v]);
        }
    }
    $forms = iv_inc_forms(A);
    ok((bool) array_filter($forms, static fn($f) => (int) $f['id'] === $fid && (int) $f['n'] === 4), 'the Incubation form is offered with its 4 applications');
    $fields = iv_inc_fields(A, $fid);
    eq([$fields['f_pin'][1], $fields['f_col'][1], $fields['f_dis'][1], $fields['_name'][1]], ['roll_no', 'college', 'district', 'name'], 'PIN, polytechnic and district questions are recognised');
    $map = array_map(static fn($x) => $x[1], $fields);
    $ir = iv_inc_import(A, $fid, $map, ['make' => true]);
    eq([$ir['rows'], $ir['added'], $ir['same'], count($ir['skipped'])], [4, 3, 1, 0], 'Incubation import: 4 rows, 3 students (the repeat filed once)');
    $ir2 = iv_inc_import(A, $fid, $map, ['make' => true]);
    eq([$ir2['added'], $ir2['updated']], [0, 0], 'running it again adds nothing');
    $np = iv_student_match(A, '25311-EE-003', '', '');
    eq([iv_college(A, (int) $np['college_id'])['code'] ?? '', (int) $np['department_id']], ['311', 0], 'a new polytechnic is made, but no department: departments are HoD dashboards');
    $ee311 = iv_department_save(A, (int) $np['college_id'], '', 'EE');
    eq((int) iv_student_match(A, '25311-EE-003', '', '')['department_id'], $ee311, 'creating the EE dashboard brings in its student at once');
    eq(iv_student_match(A, '24155-CM-050', '', '')['source'], 'incubation', 'the source is kept');
    throws(static fn() => iv_inc_import(A, $fid, ['f_pin' => 'roll_no']), 'import needs a name column', 'name');

    /* ------------------------------------- the user's own two sheets, as CSV */
    $tmp = sys_get_temp_dir() . '/iv_t_' . bin2hex(random_bytes(4));
    // Students: the POLY TECHFEST columns, with a line break inside the Phone Number header, as Excel saves it.
    file_put_contents($tmp . 's.csv', "\xEF\xBB\xBF" . 'Email Address,Code and Name of the Polytechnic,Name of the District,Full Name of the Student,PIN (XXXXX-XX-XXX),Email ID,"' . "\n" . 'Phone Number  ",Remarks' . "\r\n"
        . 'form@x.example,"56 VASAVI POLYTECHNIC, BANAGANAPALLI",Nandyal,Meena,24056-EC-011,meena@x.example,9123456780,ok' . "\r\n"
        . 'form2@x.example,"56 VASAVI POLYTECHNIC, BANAGANAPALLI",Nandyal,Gopi,24056-CM-012,,9123456781,' . "\r\n");
    [$h, $rows] = iv_read_table($tmp . 's.csv', 'students.csv');
    [$cols, $recs] = iv_table_records($h, $rows);
    eq(array_values(array_filter($cols)), ['email', 'college', 'district', 'name', 'roll_no', 'email', 'phone', 'notes'], 'the student sheet\'s headers are all recognised, Remarks too');
    eq([$recs[0]['email'], $recs[0]['phone'], $recs[1]['email']], ['meena@x.example', '9123456780', 'form2@x.example'], 'Email ID wins; Email Address fills in when it is empty');
    $sr = iv_students_import(A, $recs, ['make' => true]);
    eq([$sr['added'], count($sr['skipped'])], [2, 0], 'both students are added from the sheet');
    $meena = iv_student_match(A, '24056-EC-011', '', '');
    eq([iv_college(A, (int) $meena['college_id'])['code'] ?? '', (int) $meena['department_id']], ['56', 0], 'college 56 from the sheet; no department until a HoD dashboard exists');
    eq((int) fabo_one('SELECT COUNT(*) n FROM iv_departments WHERE org_id = ? AND college_id = ?', 'ii', [A, (int) $meena['college_id']])['n'], 0, 'the student sheet made no departments');
    // Heads: CODE, POLYTECHNIC, BRANCH, NAME OF THE HEAD OF SECTION, CONTACT NUMBER, EMAIL ID; the code written with a leading zero, branches as codes and names.
    file_put_contents($tmp . 'h.csv', "CODE,POLYTECHNIC,BRANCH,NAME OF THE HEAD OF SECTION,CONTACT NUMBER,EMAIL ID\n"
        . "056,VASAVI POLYTECHNIC,ECE,Sri Prasad,9000011111,prasad@vasavi.example\n"
        . "56,\"VASAVI POLYTECHNIC, BANAGANAPALLI\",DCME,Smt. Rani,9000011112,rani@vasavi.example\n"
        . "56,VASAVI POLYTECHNIC,Mechanical Engg.,Sri Rao,9000011113,rao@vasavi.example\n"
        . "56,VASAVI POLYTECHNIC,Electrical & Electronics,Sri Babu,9000011114,babu@vasavi.example\n");
    [$h, $rows] = iv_read_table($tmp . 'h.csv', 'heads.csv');
    [$cols, $recs] = iv_table_records($h, $rows, 'dashboards');
    eq($cols, ['college_code', 'college', 'department', 'hod_name', 'hod_phone', 'hod_email'], 'the Head sheet\'s headers are all recognised');
    $hr = iv_dashboards_import(A, $recs);
    eq([$hr['colleges'], $hr['depts'], $hr['hods'], count($hr['skipped'])], [0, 4, 4, 0], 'Heads join the imported polytechnic (056 = 56) and make its four dashboards');
    eq(iv_department(A, (int) iv_student_match(A, '24056-EC-011', '', '')['department_id'])['name'] ?? '', 'Electronics and Communication Engineering', 'Meena joined the EC dashboard when it was made');
    $vas = (int) $meena['college_id'];
    $names = array_column(iv_departments(A, $vas), 'hod_name', 'branch');
    ksort($names);
    eq($names, ['CM' => 'Smt. Rani', 'EC' => 'Sri Prasad', 'EE' => 'Sri Babu', 'M' => 'Sri Rao'], 'each branch got its Head, codes and names alike');
    eq((int) iv_student_match(A, '24056-CM-012', '', '')['department_id'], (int) iv_department_find(A, $vas, 'CM', '', false), 'the student is in the department the Head runs');
    foreach (['DCME' => 'CM', 'Computer Engineering' => 'CM', 'E.C.E' => 'EC', 'Civil' => 'C', 'CIVIL' => 'C', 'AI & ML' => 'AIM', 'Pharmacy' => 'PH', 'Electronics & Communication Engg' => 'EC', 'XYZ' => 'XYZ'] as $in => $want) {
        eq(iv_branch_split($in)[0], $want, 'branch "' . $in . '" reads as ' . $want);
    }
    eq(iv_branch_split('Fashion Design'), ['', 'Fashion Design'], 'an unknown department name stays a name');
    @unlink($tmp . 's.csv');
    @unlink($tmp . 'h.csv');

    /* ------------- the cases found in the live POLY TECHFEST data (2026-11-24) */
    $meenaRec = iv_student(A, (int) $meena['id']);
    eq([$meenaRec['district'], $meenaRec['notes'], $meenaRec['alt_email']], ['Nandyal', 'ok', 'form@x.example'], 'district, remarks and the second email are kept');
    // PINs however they were typed.
    foreach (['24029_ec_039' => '24029-EC-039', '24019--EC--002' => '24019-EC-002', '(24199-ec-049)' => '24199-EC-049', 'DME(24045-M-001)' => '24045-M-001',
              '24045CM42' => '24045-CM-042', '24057-ec-3' => '24057-EC-003', '24400-EE-001,002,003' => '24400-EE-001', '24030-.CM-135' => '24030-CM-135'] as $in => $want) {
        eq(iv_norm_roll($in), $want, 'PIN "' . $in . '" reads as ' . $want);
    }
    foreach (['515301', '0', '1', '1234567890', '2008-12-29', '013', '056'] as $junk) {
        eq(iv_roll_is_key(iv_norm_roll($junk)), false, '"' . $junk . '" is not used to tell students apart');
    }
    eq(iv_roll_is_key('HT2024A0012'), true, 'a roll number with letters and digits is');
    // Names.
    foreach ([['Vempatibabu', 'Vempati Babu', true], ['B.Mounika', 'BOYA MOUNIKA', true], ['G Naga Ganesh Eswar', 'Gangisetty Naga Ganesh Eswar', true],
              ['P SASIDHAR', 'P SPANDANA', false], ['Vinnakota Akash', 'Pusa manoj kumar', false], ['NIDRABINGI LOKESH', 'N. LOKESH', true]] as [$x, $y, $same]) {
        eq(iv_names_alike($x, $y), $same, '"' . $x . '" and "' . $y . '" ' . ($same ? 'could be one person' : 'are two people'));
    }
    // Rows as they came: the same student twice is one record; different students on one PIN are two; a postal code is no PIN.
    $live = iv_students_import(A, [
        ['name' => 'Vempatibabu', 'roll_no' => '24030-EE-259', 'email' => 'vb@x.example', 'college' => '30 GOVT POLY'],
        ['name' => 'Vempati Babu', 'roll_no' => '24030_ee_259', 'email' => 'vb@x.example', 'college' => '30 GOVT POLY'],
        ['name' => 'PITTA.SASIDHAR', 'roll_no' => '24008-EE-043', 'email' => 'ps@x.example', 'college' => '8 GOVT POLY'],
        ['name' => 'P SASIDHAR', 'roll_no' => '24008-EE-043', 'email' => 'ps@x.example', 'college' => '8 GOVT POLY'],
        ['name' => 'P SPANDANA', 'roll_no' => '24008-EE-043', 'email' => 'sp@x.example', 'college' => '8 GOVT POLY'],
        ['name' => 'BOYA MOUNIKA', 'roll_no' => '515301', 'email' => 'bm@x.example', 'college' => '8 GOVT POLY'],
        ['name' => 'S.Navya', 'roll_no' => '515301', 'email' => 'sn@x.example', 'college' => '8 GOVT POLY'],
        ['name' => 'Shaik Naziya banu', 'roll_no' => '515301', 'email' => 'snb@x.example', 'college' => '8 GOVT POLY'],
        ['name' => 'B.Mounika', 'roll_no' => '515301', 'email' => 'bm@x.example', 'college' => '8 GOVT POLY'],
        ['name' => 'Sibling One', 'roll_no' => '', 'email' => 'family@x.example', 'phone' => '9000000099', 'college' => '8 GOVT POLY'],
        ['name' => 'Sibling Two', 'roll_no' => '', 'email' => 'family@x.example', 'phone' => '9000000099', 'college' => '8 GOVT POLY'],
    ], ['make' => true]);
    eq([$live['added'], $live['updated'] + $live['same'], count($live['skipped'])], [7, 4, 0], 'the 11 rows are 7 people: 2 Babu → 1, 2 Sasidhar → 1, Spandana, Mounika ×2 → 1, Navya, Naziya, two siblings');
    eq([$live['students'], $live['repeats']], [7, 4], 'the upload says: 11 rows = 7 students + 4 repeat registrations');
    $lastImp = iv_last_import(A);
    eq([(int) $lastImp['rows_read'], (int) $lastImp['students'], (int) $lastImp['repeats']], [11, 7, 4], 'and keeps that for the Students page');
    $rep = fabo_all('SELECT line, first_line, name FROM iv_import_rows WHERE import_id = ? AND outcome = "repeat" ORDER BY line', 'i', [(int) $lastImp['id']]);
    eq(array_map(static fn($x) => $x['name'] . '→' . $x['first_line'], $rep), ['Vempati Babu→2', 'P SASIDHAR→4', 'B.Mounika→7', 'Sibling Two→11'], 'each repeat row points at the earlier sheet row of the same student (row 1 is the header)');
    eq((int) fabo_one('SELECT COUNT(*) n FROM iv_import_rows WHERE import_id = ?', 'i', [(int) $lastImp['id']])['n'], 11, 'every row of the upload is recorded');
    eq((int) fabo_one('SELECT COUNT(*) n FROM iv_students WHERE org_id = ? AND roll_no = "24008-EE-043"', 'i', [A])['n'], 2, 'Sasidhar and Spandana both keep PIN 24008-EE-043');
    eq((int) fabo_one('SELECT COUNT(*) n FROM iv_students WHERE org_id = ? AND flag <> ""', 'i', [A])['n'], 0, 'no student is marked: what was typed is taken as correct');
    eq((int) fabo_one('SELECT COUNT(*) n FROM iv_students WHERE org_id = ? AND roll_no = "515301"', 'i', [A])['n'], 3, 'three students from one place who typed 515301 are three students');
    [$shownRows] = iv_students(A, ['q' => '24030']);
    eq(array_column($shownRows, 'roll_no'), ['24030-EE-259'], 'the PIN is shown as it was first typed (the second spelling of the same student is not)');
    iv_student_save(A, ['name' => 'Typed Pin', 'roll_no' => '24155_cm_088', 'email' => 'tp@x.example']);
    [$shownRows] = iv_students(A, ['q' => 'Typed Pin']);
    eq(array_column($shownRows, 'roll_no'), ['24155_cm_088'], 'a PIN typed with underscores is shown exactly so');
    $bm = iv_student_match(A, '', 'bm@x.example', '', 'BOYA MOUNIKA');
    eq(iv_roll_shown(iv_student(A, (int) $bm['id'])), '515301', 'a postal-code style value is shown as typed, unchanged');
    $vb = iv_student_match(A, '24030-EE-259', '', '');
    eq((int) $vb['department_id'], 0, 'no dashboard for EE at college 30 yet: no department');
    $ee30 = iv_department_save(A, (int) $vb['college_id'], 'Electrical and Electronics Engineering', '');
    eq((int) iv_student_match(A, '24030-EE-259', '', '')['department_id'], $ee30, 'a dashboard named for the branch (no code given) still takes the underscored-PIN student');
    // Departments nobody uses go; their students stay in their college.
    $spare = iv_department_save(A, (int) $vb['college_id'], 'Unused Dept', 'ZZ');
    $pruned = iv_departments_prune(A);
    eq([iv_department(A, $spare), $pruned > 0, iv_department(A, $cm) !== null], [null, true, true], 'pruning removes departments with no Head, form or ideas, and keeps HoD dashboards');
    $tmpCount = (int) fabo_one('SELECT COUNT(*) n FROM iv_students WHERE org_id = ?', 'i', [A])['n'];
    $again = iv_students_import(A, [['name' => 'P SPANDANA', 'roll_no' => '24008-EE-043', 'email' => 'sp@x.example', 'college' => '8 GOVT POLY']], ['make' => true]);
    eq([$again['added'], (int) fabo_one('SELECT COUNT(*) n FROM iv_students WHERE org_id = ?', 'i', [A])['n']], [0, $tmpCount], 'uploading again adds nobody');
}

/* ================================================================ rounds */
$rid = iv_round_save(A, ['name' => 'Idea round 2026-27', 'gem_id' => $gem['id'] ?? 0, 'opens_at' => date('Y-m-d H:i', time() - 3600), 'closes_at' => date('Y-m-d H:i', time() + 86400 * 7),
                         'review_by' => date('Y-m-d H:i', time() + 86400 * 10), 'ai_limit' => 2, 'top_n' => 3, 'allow_new' => 1, 'status' => 'live']);
$round = iv_round(A, $rid);
$form = iv_form_for(A, $round, $cm);
if ($mode === 'seed') {
    iv_password_set(A, $hCm, 'hod-pass-9921x');
    $ecH = iv_hod_save(A, $ec, ['name' => 'Smt. Lakshmi', 'email' => 'hod.ec@poly155.example']);
    $inv = iv_invite_make(A, $ecH);
    ob_end_clean();
    echo json_encode(['key' => iv_portal_key(A), 'form' => $form['token'], 'formEc' => iv_form_for(A, $round, $ec)['token'], 'invite' => $inv, 'gem' => (int) ($gem['id'] ?? 0), 'round' => $rid]) . "\n";
    exit(0);
}
ok($gem !== null && count($gem['criteria']) === 8, 'the organisation\'s Incubation Gem is offered (8 criteria)');
throws(static fn() => iv_round_save(A, ['name' => 'Bad', 'opens_at' => '2026-12-10 10:00', 'closes_at' => '2026-12-01 10:00']), 'closing before opening is refused', 'close after');
throws(static fn() => iv_round_save(A, ['name' => 'Other', 'gem_id' => 99999, 'opens_at' => '2026-12-01 10:00', 'closes_at' => '2026-12-10 10:00']), 'an unknown Gem is refused', 'Gem');
throws(static fn() => iv_round_save(B, ['name' => 'B round', 'gem_id' => $gem['id'], 'opens_at' => '2026-12-01 10:00', 'closes_at' => '2026-12-10 10:00']), 'another organisation\'s Gem is refused', 'Gem');
$qs = iv_form_questions($form);
eq(count($qs), 4, 'a department form starts with the Gem\'s 4 questions');
ok(!empty($qs[0]['gem']) && $qs[0]['required'], 'Gem questions are marked and required');
eq(iv_form_for(A, $round, $cm)['id'], $form['id'], 'one form per department per round');

// The HoD rewords a Gem question, adds one, cannot drop a Gem one.
$rows = array_map(static fn($q) => ['uid' => $q['uid'], 'label' => $q['label'], 'help' => $q['help'], 'type' => $q['type'], 'required' => 1], $qs);
$rows[0]['label'] = 'What problem are you solving?';
$rows[] = ['uid' => '', 'label' => 'Team size', 'type' => 'number', 'required' => 0];
iv_form_save(A, (int) $form['id'], 'CM ideas', 'Hello students', $rows, true);
$form = iv_form_for(A, $round, $cm);
eq([count(iv_form_questions($form)), iv_form_questions($form)[0]['label'], (bool) iv_form_questions($form)[0]['gem']], [5, 'What problem are you solving?', true], 'reworded, one added, still a Gem question');
$drop = $rows;
array_shift($drop);
throws(static fn() => iv_form_save(A, (int) $form['id'], 'x', '', $drop, true), 'a Gem question cannot be removed', 'Gem scores');

/* ================================================================= ideas */
$ans = [];
foreach (iv_form_questions($form) as $q) {
    $ans[$q['uid']] = $q['type'] === 'number' ? '3' : 'A clear answer to ' . $q['label'];
}
$sub = static fn(array $over = []) => iv_idea_submit(iv_form_for(A, $round, $cm), $over + ['name' => 'Ravi Teja', 'roll_no' => '24155-CM-001', 'email' => 'ravi@x.example', 'phone' => '', 'title' => 'Smart irrigation', 'q' => $ans]);
[$i1, $how] = $sub();
eq($how, 'added', 'a student sends an idea');
eq(iv_idea(A, $i1)['college_id'], $c1, 'the idea is mapped to the department\'s college');
[$i1b, $how] = $sub(['title' => 'Smart irrigation v2']);
eq([$i1b, $how, iv_idea(A, $i1)['title']], [$i1, 'updated', 'Smart irrigation v2'], 'sending again updates the same idea (one per student per round)');
eq(iv_student(A, iv_idea(A, $i1)['student_id'])['name'], 'Ravi', 'the student\'s own form does not rename the roster record');
throws(static fn() => iv_idea_submit(iv_form_for(A, $round, $cm), ['name' => 'Asha', 'roll_no' => '24155-EC-020', 'email' => 'asha@x.example', 'title' => 'Wrong door', 'q' => $ans]), 'a student of another department is sent to her own form', 'registered with');
$qMissing = $ans;
$qMissing[array_key_first($qMissing)] = '';
throws(static fn() => $sub(['q' => $qMissing, 'roll_no' => '24155-CM-090', 'email' => 'new90@x.example', 'name' => 'New']), 'a Gem question must be answered', 'Answer');
[$i2] = $sub(['name' => 'New Student', 'roll_no' => '24155-CM-091', 'email' => 'new91@x.example', 'title' => 'Waste sorter']);
eq((int) iv_student_match(A, '24155-CM-091', '', '')['department_id'], $cm, 'a student not on the list is added to the department');
fabo_exec('UPDATE iv_rounds SET allow_new = 0 WHERE id = ?', 'i', [$rid]);
throws(static fn() => $sub(['name' => 'Stranger', 'roll_no' => '24155-CM-099', 'email' => 's99@x.example']), 'when the round says so, only listed students may send', 'not on your department');
fabo_exec('UPDATE iv_rounds SET allow_new = 1 WHERE id = ?', 'i', [$rid]);
[$i3] = $sub(['name' => 'Third', 'roll_no' => '24155-CM-092', 'email' => 'n92@x.example', 'title' => 'Solar dryer']);
[$i4] = $sub(['name' => 'Fourth', 'roll_no' => '24155-CM-093', 'email' => 'n93@x.example', 'title' => 'Bus tracker']);

// The window, and a later deadline for one department.
fabo_exec('UPDATE iv_rounds SET closes_at = ? WHERE id = ?', 'si', [iv_now(-60), $rid]);
$round = iv_round(A, $rid);
throws(static fn() => $sub(['name' => 'Late', 'roll_no' => '24155-CM-094', 'email' => 'late@x.example']), 'after closing nobody can send', 'closed');
iv_dept_window_set(A, $rid, $cm, iv_now(3600));
ok(iv_window_problem(iv_round(A, $rid), $cm) === null && iv_window_problem(iv_round(A, $rid), $ec) !== null, 'an extension opens one department only');
[$i5] = $sub(['name' => 'Late', 'roll_no' => '24155-CM-094', 'email' => 'late@x.example', 'title' => 'Late but in']);
ok($i5 > 0, 'the extended department still takes ideas');
fabo_exec('UPDATE iv_forms SET is_open = 0 WHERE id = ?', 'i', [(int) $form['id']]);
throws(static fn() => $sub(['name' => 'Paused', 'roll_no' => '24155-CM-095', 'email' => 'p95@x.example']), 'a paused form takes nothing', 'paused');
fabo_exec('UPDATE iv_forms SET is_open = 1 WHERE id = ?', 'i', [(int) $form['id']]);

/* ============================================================= AI checks */
$round = iv_round(A, $rid);
$hod = iv_hod(A, $hCm);
$reply = ['criteria' => array_map(static fn($n) => ['n' => $n, 'score' => 99, 'reason' => 'r' . $n], range(1, 8)), 'summary' => 'Strong.', 'improvements' => ['- Add sensors', '2) Cheaper pump']];
$calls = 0;
$GLOBALS['iv_gemini_hook'] = static function ($prompt) use (&$calls, $reply) {
    $calls++;
    return [true, $reply];
};
$idea = iv_idea(A, $i1);
$msg = iv_run_portal(A, $idea, $round, $hod, 'Dr. Kumar (HoD)');
$idea = iv_idea(A, $i1);
eq([(float) $idea['ai_score'], (float) $idea['ai_max']], [100.0, 100.0], 'marks are clamped to each criterion\'s weight (99 asked, 100 total)');
eq(json_decode(iv_runs(A, $i1)[0]['improvements_json'], true), ['Add sensors', 'Cheaper pump'], 'improvement points are cleaned');
throws(static fn() => $sub(['title' => 'Changed after review']), 'after an AI check the student can no longer change the idea', 'already started');
// A failed AI call is not counted.
$GLOBALS['iv_gemini_hook'] = static fn($p) => [false, 'HTTP 503 busy'];
throws(static fn() => iv_run_portal(A, iv_idea(A, $i1), $round, $hod, 'x'), 'a busy AI is reported', 'not counted');
eq(iv_runs_used($i1), 1, 'and the failed try does not count');
$GLOBALS['iv_gemini_hook'] = static fn($p) => [true, ['criteria' => [['n' => 1, 'score' => 5]]]];
throws(static fn() => iv_run_portal(A, iv_idea(A, $i1), $round, $hod, 'x'), 'an incomplete reply is refused', 'could not be read');
eq(iv_runs_used($i1), 1, 'and is not counted either');
// The own-tool path: a chatty paste that carries this idea's check code.
$reply = static fn(array $idea, array $scores, ?string $code = null) => "Sure! Here is the evaluation:\n```json\n"
    . json_encode(['check' => $code ?? iv_check_code(A, $idea), 'criteria' => array_map(static fn($n, $s) => ['n' => $n, 'score' => $s, 'reason' => 'because ' . $n], range(1, count($scores)), $scores), 'summary' => 'Okay.']) . "\n```\nHope it helps.";
$code1 = iv_check_code(A, iv_idea(A, $i1));
ok(str_contains(iv_prompt($gem, iv_idea(A, $i1), $code1), '"check":"' . $code1 . '"'), 'the own-tool prompt carries the check code');
ok($code1 !== iv_check_code(A, iv_idea(A, $i2)), 'each idea has its own check code');
throws(static fn() => iv_run_own(A, iv_idea(A, $i1), $round, $hod, 'ChatGPT', $reply(iv_idea(A, $i2), array_fill(0, 8, 2.3)), 'x'), 'a reply written for another idea is refused', 'check code');
throws(static fn() => iv_run_own(A, iv_idea(A, $i1), $round, $hod, 'ChatGPT', $reply(iv_idea(A, $i1), array_fill(0, 8, 2.3), ''), 'x'), 'a reply without the code (typed up by hand) is refused', 'check code');
throws(static fn() => iv_run_own(A, iv_idea(A, $i1), $round, $hod, 'ChatGPT', 'no json here', 'x'), 'an unreadable paste is refused', 'no marks');
throws(static fn() => iv_run_own(A, iv_idea(A, $i1), $round, $hod, 'ChatGPT', $reply(iv_idea(A, $i1), [5, 5]), 'x'), 'a reply that skips criteria is refused', 'leaves out');
eq(iv_runs_used($i1), 1, 'refused pastes are not counted');
fabo_exec('UPDATE iv_rounds SET own_ai = 0 WHERE id = ?', 'i', [$rid]);
throws(static fn() => iv_run_own(A, iv_idea(A, $i1), iv_round(A, $rid), $hod, 'ChatGPT', $reply(iv_idea(A, $i1), array_fill(0, 8, 2.3)), 'x'), 'a portal-only round refuses own-tool checks', 'portal only');
fabo_exec('UPDATE iv_rounds SET own_ai = 1 WHERE id = ?', 'i', [$rid]);
$round = iv_round(A, $rid);
$paste1 = $reply(iv_idea(A, $i1), array_fill(0, 8, 2.3));
iv_run_own(A, iv_idea(A, $i1), $round, $hod, 'ChatGPT', $paste1, 'Dr. Kumar (HoD)');
eq((float) iv_idea(A, $i1)['ai_score'], 60.0, 'the idea\'s score is the average of its checks (100 and 20 → 60), so a second check cannot just replace the first');
eq([iv_runs(A, $i1)[0]['tool'], iv_runs(A, $i1)[0]['mode'], iv_runs(A, $i1)[0]['reply_text']], ['ChatGPT', 'own', $paste1], 'the tool and the reply as pasted are kept');
ok(iv_check_code(A, iv_idea(A, $i1)) !== $code1, 'the code changes after a check, so an old reply cannot be pasted again');
throws(static fn() => iv_run_own(A, iv_idea(A, $i1), $round, $hod, 'Gemini', $reply(iv_idea(A, $i1), array_fill(0, 8, 9)), 'x'), 'the third check is refused at a limit of 2', 'had its 2');
// The owner's verify check: outside the limit, not counted against the HoD.
$GLOBALS['iv_gemini_hook'] = static fn($p) => [true, ['criteria' => array_map(static fn($n) => ['n' => $n, 'score' => 0, 'reason' => ''], range(1, 8))]];
iv_run_portal(A, iv_idea(A, $i1), $round, null, 'Owner', true);
eq([iv_runs_used($i1), count(iv_runs(A, $i1)), (float) iv_idea(A, $i1)['ai_score']], [2, 3, 40.0], 'the owner\'s verify check runs past the limit, is not counted, and joins the average');
$GLOBALS['iv_gemini_hook'] = static fn($p) => [true, $reply];
// The HoD's own limit for this HoD.
iv_hod_limit_set(A, $hCm, 3);
$marks = [15, 10, 10, 10, 10, 5, 3, 2];
iv_run_own(A, iv_idea(A, $i2), $round, iv_hod(A, $hCm), 'Gemini', $reply(iv_idea(A, $i2), $marks), 'x');
eq((float) iv_idea(A, $i2)['ai_score'], 65.0, 'the pasted marks are added up');
eq(iv_ai_limit($round, iv_hod(A, $hCm)), 3, 'a HoD\'s own limit wins over the round\'s');
iv_hod_limit_set(A, $hCm, 0);
throws(static fn() => iv_run_own(A, iv_idea(A, $i3), $round, iv_hod(A, $hCm), 'Gemini', $reply(iv_idea(A, $i3), $marks), 'x'), 'a limit of 0 switches AI off', 'switched off');
iv_hod_limit_set(A, $hCm, null);
$prompt = iv_prompt($gem, iv_idea(A, $i3));
ok(str_contains($prompt, 'RUBRIC (total 100 points)') && str_contains($prompt, 'Solar dryer') && str_contains($prompt, 'What problem are you solving?'), 'the own-tool prompt holds the rubric, the title and the answers');

/* ============================================================ decisions */
$actor = 'Dr. Kumar (HoD)';
iv_idea_decide(A, $i1, $cm, 'validated', 'Good', $actor);
[$n, $probs] = iv_ideas_decide_many(A, [$i2, $i3, $i4], $cm, 'validated', $actor);
eq([$n, $probs], [3, []], 'several ideas are validated at once');
eq(iv_idea(A, $i1)['hod_note'], 'Good', 'and a decision made alone keeps its note');
[$n] = iv_ideas_decide_many(A, [$i2, $i3], $cm, 'validated', $actor);
eq($n, 0, 'deciding again what is already decided changes nothing');
[$n, $probs] = iv_ideas_decide_many(A, [$i5, iv_idea(B, $i1) ? $i1 : 999999], $ec, 'validated', $actor);
eq([$n, count($probs)], [0, 1], 'another department\'s ideas are refused in a bulk decision too');
iv_ideas_decide_many(A, [$i5], $cm, 'rejected', $actor);
eq(iv_idea(A, $i5)['status'], 'rejected', 'and Not taken works in bulk');
throws(static fn() => iv_idea_decide(A, $i1, $ec, 'validated', '', 'x'), 'another department\'s HoD cannot decide', 'not in your department');
throws(static fn() => iv_ranks_set(A, $round, $cm, [$i5 => 1], $actor), 'a rejected idea cannot be ranked', 'Only validated');
throws(static fn() => iv_ranks_set(A, $round, $cm, [$i1 => 1, $i2 => 1], $actor), 'a rank cannot be given twice', 'twice');
throws(static fn() => iv_ranks_set(A, $round, $cm, [$i1 => 4], $actor), 'rank beyond top 3 is refused', '1 to 3');
throws(static fn() => iv_forward(A, $round, $cm, $actor, $hod), 'nothing ranked, nothing sent', 'Rank at least');
iv_ranks_set(A, $round, $cm, [$i1 => 1, $i2 => 2, $i3 => 3], $actor);
throws(static fn() => iv_forward(A, $round, $cm, $actor, $hod), 'a pick never checked with AI cannot be sent', 'Check "Solar dryer"');
iv_run_own(A, iv_idea(A, $i3), $round, $hod, 'Gemini', $reply(iv_idea(A, $i3), [20, 10, 10, 10, 10, 10, 10, 10]), 'x');   // 90
eq(count(iv_picks_against_scores(iv_ideas(A, ['round' => $rid, 'dept' => $cm]))), 3, 'ranking 40% and 65% above 90% goes against the scores');
throws(static fn() => iv_forward(A, $round, $cm, $actor, $hod, 'ok'), 'an order against the scores needs a reason', 'Say why');
iv_ranks_set(A, $round, $cm, [$i3 => 1, $i2 => 2, $i1 => 3], $actor);
eq(iv_picks_against_scores(iv_ideas(A, ['round' => $rid, 'dept' => $cm])), [], 'ranked by score: nothing to explain');
iv_ranks_set(A, $round, $cm, [$i1 => 1, $i2 => 2, $i3 => 3], $actor);
eq(iv_forward(A, $round, $cm, $actor, $hod, 'Ravi built a working prototype in the lab; the AI only read his short answers.'), 3, 'with a reason, the top 3 are sent forward');
eq(iv_idea(A, $i1)['pick_note'], 'Ravi built a working prototype in the lab; the AI only read his short answers.', 'the reason travels with the pick');
$fk = array_column(iv_idea_flags(A, iv_idea(A, $i1)), 'k');
ok(in_array('order', $fk, true), 'the owner sees the order was against the scores');
ok(in_array('new_student', array_column(iv_idea_flags(A, iv_idea(A, $i2)), 'k'), true), 'and that a student came in through the form, not the list');
ok(in_array('own_only', array_column(iv_idea_flags(A, iv_idea(A, $i3)), 'k'), true), 'and that a score came only from the HoD\'s own tool');
eq(iv_idea(A, $i1)['stage'], 'forwarded', 'they enter the pipeline at Forwarded by HoD');
eq(iv_idea(A, $i4)['stage'], null, 'the fourth stays with the HoD');
throws(static fn() => iv_idea_decide(A, $i4, $cm, 'rejected', '', $actor), 'decisions are final once sent', 'final');
throws(static fn() => iv_forward(A, $round, $cm, $actor, $hod, 'a long enough reason here'), 'cannot send twice', 'already');
eq(array_column(iv_team(A, $i1), 'role'), ['lead'], 'the student whose idea it is leads its team');

/* ============================================================ the team */
$own = 'Owner';
$s4 = (int) iv_idea(A, $i4)['student_id'];
$asha = (int) iv_student_match(A, '24155-EC-020', '', '')['id'];
iv_team_add(A, $i1, $asha, $own);
eq(iv_idea(A, $i1)['stage'], 'team', 'adding a member moves the idea to Team assigned');
throws(static fn() => iv_team_add(A, $i2, $asha, $own), 'a student sits on one team per round', 'already on the team');
throws(static fn() => iv_team_add(A, $i1, (int) iv_idea(A, $i2)['student_id'], $own), 'another project\'s lead cannot join', 'already on');
throws(static fn() => iv_team_add(A, $i4, $s4, $own), 'only ideas in the pipeline get teams', 'pipeline');
throws(static fn() => iv_team_remove(A, $i1, (int) iv_idea(A, $i1)['student_id'], $own), 'the lead stays on the team', 'leads');
ok(!array_filter(iv_team_candidates(A, iv_idea(A, $i2), 'Asha'), static fn($c) => (int) $c['id'] === $asha), 'the picker leaves out students already on a team');
throws(static fn() => iv_return_dept(A, $rid, $cm, $own), 'picks with a team cannot be returned', 'Remove the team');
iv_team_remove(A, $i1, $asha, $own);
eq(iv_return_dept(A, $rid, $cm, $own), 3, 'the owner hands the picks back');
eq([iv_idea(A, $i1)['stage'], iv_team(A, $i1)], [null, []], 'out of the pipeline, team cleared');
eq(iv_forward(A, $round, $cm, $actor, $hod, 'Same order as before: the prototype matters most.'), 3, 'and the HoD can send again');
iv_stage_set(A, $i1, 'selected', 'Great', $own);
eq([iv_idea(A, $i1)['stage'], iv_idea(A, $i1)['owner_note']], ['selected', 'Great'], 'the owner moves an idea along');
throws(static fn() => iv_stage_set(A, $i4, 'review', '', $own), 'an idea not sent cannot be moved', 'not in the pipeline');
ok(count(fabo_all('SELECT id FROM iv_log WHERE idea_id = ?', 'i', [$i1])) >= 8, 'the idea keeps its history');

/* ======================================================= separation */
eq(iv_idea(B, $i1), null, 'another organisation cannot open this idea');
eq(iv_student(B, $asha), null, 'or this student');
throws(static fn() => iv_team_add(B, $i1, $asha, 'B'), 'or touch this team', 'pipeline');
throws(static fn() => iv_department_delete(A, $cm), 'a department with ideas stays', 'has ideas');
throws(static fn() => iv_student_delete(A, (int) iv_idea(A, $i1)['student_id']), 'a student with an idea stays', 'stays');
eq(iv_totals(A, $rid)['forwarded'], 3, 'totals count what is in the pipeline');
eq(iv_dept_progress(A, $rid)[$cm]['ideas'], 5, 'department progress counts its ideas');

/* =========================================================== HoD login */
iv_password_set(A, $hCm, 'long enough pass 1');
ok(iv_check_login(A, 'HOD.CM@poly155.example', 'long enough pass 1') !== null, 'a HoD signs in with their email, any case');
ok(iv_check_login(B, 'hod.cm@poly155.example', 'long enough pass 1') === null, 'that password does not open the other organisation');
throws(static fn() => iv_password_set(A, $hCm, 'short'), 'short passwords are refused', '10 characters');
$link = iv_invite_make(A, $hCm);
preg_match('/invite=([0-9a-f]{48})/', $link, $mm);
eq((int) (iv_invite_hod($mm[1])['id'] ?? 0), $hCm, 'the sign-in link finds its HoD');
iv_hod_status(A, $hCm, 'suspended');
ok(iv_invite_hod($mm[1]) === null && iv_check_login(A, 'hod.cm@poly155.example', 'long enough pass 1') === null, 'a suspended HoD cannot sign in');
iv_hod_status(A, $hCm, 'active');
$fp = iv_fingerprint(iv_hod(A, $hCm));
iv_hod_save(A, $cm, ['name' => 'New Head', 'email' => 'newhead@poly155.example']);
ok(iv_hod(A, $hCm)['pass_hash'] === null && iv_fingerprint(iv_hod(A, $hCm)) !== $fp, 'a new person on the dashboard ends the old password');

/* ============================================================ access */
$viewerB = ['is_admin' => false, 'client' => fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [B]), 'access' => ['ideaval' => 'edit'], 'state' => 'ok'];
eq(iv_org($viewerB), B, 'a client works in their own organisation');
$_REQUEST['org'] = A;
eq(iv_org($viewerB), B, 'and cannot ask for another');
eq(iv_org(fabo_admin_viewer()), A, 'the administrator can pick a client holding the module');
$_REQUEST['org'] = 777777;
eq(iv_org(fabo_admin_viewer()), 0, 'but not one that does not hold it');
unset($_REQUEST['org']);

/* ============================================================ pages */
$GLOBALS['iv_viewer'] = fabo_admin_viewer();
$GLOBALS['iv_grant'] = 'edit';
$GLOBALS['iv_org'] = A;
foreach (['overview' => [], 'dashboards' => [], 'dashboard' => ['id' => $cm], 'students' => [], 'student' => ['id' => $asha], 'rounds' => ['round' => $rid], 'ideas' => [],
          'pipeline' => [], 'idea' => ['id' => $i1, 'find' => 'a'], 'import' => ['form' => $fid ?? 0], 'settings' => [], 'gems' => [], 'gems ' => ['id' => 'new']] as $p => $q) {
    $_GET = $q;
    ob_start();
    try {
        ('iv_page_' . trim($p))(A);
        $h = ob_get_clean();
        ok(strlen($h) > 200 && !str_contains($h, 'Warning') && !str_contains($h, 'Notice'), "owner page $p renders");
    } catch (Throwable $e) {
        ob_end_clean();
        ok(false, "owner page $p renders (" . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
    }
}
$GLOBALS['iv_porg'] = A;
$me = iv_hod(A, (int) iv_hod_for_dept(A, $ec)['id']);
$dEc = iv_department(A, $ec);
foreach (['home' => [], 'form' => [], 'students' => [], 'profile' => []] as $v => $q) {
    $_GET = $q;
    ob_start();
    try {
        ('iv_portal_view_' . $v)(A, $me, $dEc, $round);
        $h = ob_get_clean();
        ok(strlen($h) > 200, "HoD view $v renders");
    } catch (Throwable $e) {
        ob_end_clean();
        ok(false, "HoD view $v renders (" . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
    }
}
$meCm = iv_hod(A, $hCm);
$_GET = ['id' => $i4];
ob_start();
iv_portal_view_idea(A, $meCm, iv_department(A, $cm), $round);
$h = ob_get_clean();
ok(str_contains($h, 'own AI tool') && str_contains($h, 'RUBRIC') && str_contains($h, 'data-code="IV-'), 'the HoD idea page offers both AI ways and the prompt');
$_GET = ['id' => $i1];
ob_start();
iv_portal_view_idea(A, $me, $dEc, $round);
$h = ob_get_clean();
ok(str_contains($h, 'not in your department'), 'a HoD cannot open another department\'s idea');

// The round's own-tool switch: the form's unticked box turns it off; other callers keep it.
$rv = iv_round(A, $rid);
$base = ['name' => $rv['name'], 'gem_id' => $rv['gem_id'], 'opens_at' => $rv['opens_at'], 'closes_at' => $rv['closes_at'], 'ai_limit' => 2, 'top_n' => 3, 'status' => 'live', 'allow_new' => 1];
iv_round_save(A, $base + ['own_ai_form' => '1'], $rid);
eq((int) iv_round(A, $rid)['own_ai'], 0, 'the rounds form with the box unticked makes the round portal-only');
iv_round_save(A, $base, $rid);
eq((int) iv_round(A, $rid)['own_ai'], 0, 'a save that does not mention it keeps the setting');
iv_round_save(A, $base + ['own_ai_form' => '1', 'own_ai' => '1'], $rid);
eq((int) iv_round(A, $rid)['own_ai'], 1, 'ticking it lets HoDs use their own tool again');
$round = iv_round(A, $rid);

// The HoD Students page and the student panel.
$dCm = iv_department(A, $cm);
$_GET = ['f' => 'validated'];
ob_start();
iv_portal_view_students(A, $meCm, $dCm, $round);
$h = ob_get_clean();
ok(str_contains($h, 'hp-seg') && str_contains($h, 'Smart irrigation') && !str_contains($h, 'Late but in'), 'the Validated filter shows validated ideas only');
ok(!str_contains($h, 'data-bulk') && str_contains($h, 'decisions are final'), 'after sending, the Students page has no decide buttons');
$_GET = ['q' => 'Fourth'];
ob_start();
iv_portal_view_students(A, $meCm, $dCm, $round);
$h = ob_get_clean();
ok(str_contains($h, 'Bus tracker') && !str_contains($h, 'Smart irrigation'), 'search narrows the list');
$panel = iv_portal_student_panel(A, $meCm, $dCm, $round, iv_student(A, (int) iv_idea(A, $i1)['student_id']));
ok(str_contains($panel, 'Smart irrigation') && str_contains($panel, 'Sent #1') && str_contains($panel, 'average of'), 'the panel leads with the student\'s idea, its rank and score');
ok(!str_contains($panel, 'name="do"'), 'and offers no decision once the picks are sent');
eq(iv_portal_back_q('v=students&f=todo&q=ra&open=5&evil=1', ['round' => 3]), ['v' => 'students', 'q' => 'ra', 'f' => 'todo', 'open' => 5, 'round' => 3], 'a decision comes back to the same list, nothing else');

// The owner's idea page shows what to check.
$_GET = ['id' => $i1];
$_SESSION['iv_flash'] = null;
ob_start();
iv_page_idea(A);
$h = ob_get_clean();
ok(str_contains($h, 'Integrity check') && str_contains($h, 'Ravi built a working prototype') && str_contains($h, 'The reply as pasted') && str_contains($h, 'Own tool'), 'the owner sees the flags, the HoD\'s reason and the pasted reply');
$_GET = [];

/* ============================================================ the Gems page */
$ctxBefore = $GLOBALS['inc_ctx'] ?? null;
$orgBefore = inc_org();
ok(!iv_gems_available(B), 'an organisation without Incubation has no Gem tables');
iv_gems_prepare(B, 'Owner B');
$gb = iv_gems_all(B);
ok(iv_gems_available(B) && count($gb) === 1 && count(reset($gb)['criteria']) === 8, 'Gems page start: Gem 1 made for it (8 criteria)');
ok(inc_org() === $orgBefore && ($GLOBALS['inc_ctx'] ?? null) === $ctxBefore, 'Incubation\'s organisation and context are put back');

$g1 = iv_gem(A, (int) $gem['id']);
$mailBefore = $g1['mail_body'];
$qsIn = [];
foreach ($g1['fields'] as $n => $f) {
    $qsIn[] = ['uid' => $f['uid'], 'label' => $n === 0 ? 'Which problem do you solve, for whom?' : $f['label'], 'help' => $f['help'] ?? '', 'type' => $f['type'], 'required' => $f['required'] ? 1 : null];
}
$qsIn[] = ['uid' => '', 'label' => 'Have you met any users?', 'help' => 'Name one.', 'type' => 'short', 'required' => 1];
[$tot0, $behind0] = iv_gem_forms_behind(A, (int) $gem['id'], $g1);
ok($tot0 >= 1, 'the rounds\' forms are counted');
$crit = inc_gem_criteria_text($g1);
iv_gem_save(A, (int) $gem['id'], ['name' => $g1['name'], 'instructions' => $g1['instructions'], 'criteria' => $crit, 'q' => $qsIn, 'active' => 1], 'Owner A');
$g1b = iv_gem(A, (int) $gem['id']);
eq([count($g1b['fields']), $g1b['fields'][0]['label'], $g1b['fields'][0]['uid'], $g1b['mail_body']], [5, 'Which problem do you solve, for whom?', $g1['fields'][0]['uid'], $mailBefore],
   'saved: reworded keeps its uid, one added, Incubation\'s email untouched');
[$tot1, $behind1] = iv_gem_forms_behind(A, (int) $gem['id'], $g1b);
ok($behind1 === $tot1 && $tot1 > 0, 'the forms are now behind the Gem');
$formCm = iv_form_for(A, $round, $cm);
$ownBefore = array_values(array_filter(iv_form_questions($formCm), static fn($q) => empty($q['gem'])));
eq(iv_gem_sync_forms(A, (int) $gem['id']), $tot1, 'every form of the round is updated');
$qsAfter = iv_form_questions(iv_form_for(A, $round, $cm));
eq([count(array_filter($qsAfter, static fn($q) => !empty($q['gem']))), $qsAfter[0]['label'], array_values(array_filter($qsAfter, static fn($q) => empty($q['gem'])))],
   [5, 'Which problem do you solve, for whom?', $ownBefore], 'the form asks the 5 Gem questions; the HoD\'s own question stays');
eq(iv_gem_forms_behind(A, (int) $gem['id'], $g1b)[1], 0, 'no form is behind after the update');
eq(iv_gem_sync_forms(A, (int) $gem['id']), 0, 'updating again changes nothing');
ok(str_contains(iv_idea_answers(iv_idea(A, $i1))[0]['label'] ?? '', 'problem') || count(iv_idea_answers(iv_idea(A, $i1))) > 0, 'an idea already sent keeps its answers');

throws(static fn() => iv_gem_save(A, null, ['name' => 'Bad', 'criteria' => 'No weight here', 'q' => [['label' => 'Q']]], 'x'), 'a criterion without a weight is refused', 'weight');
throws(static fn() => iv_gem_save(A, null, ['name' => '', 'criteria' => 'A | 5 | x', 'q' => [['label' => 'Q']]], 'x'), 'a Gem needs a name', 'name');
throws(static fn() => iv_gem_save(A, null, ['name' => 'No Qs', 'criteria' => 'A | 5 | x', 'q' => [['label' => '']]], 'x'), 'a Gem needs a question', 'question');
$g2 = iv_gem_save(A, null, ['name' => 'Gem 2 – Validation', 'instructions' => 'Be strict.', 'criteria' => "Evidence | 20 | Did they test it?\nMarket | 10 | Who pays?", 'q' => [['label' => 'What did you test?', 'type' => 'long', 'required' => 1], ['label' => 'Who pays?', 'type' => 'short']]], 'Owner A');
$g2r = iv_gem(A, $g2);
eq([$g2r['max'], count($g2r['fields']), (int) $g2r['active'], $g2r['fields'][1]['type'], $g2r['fields'][1]['required']], [30.0, 2, 0, 'short', false], 'a new Gem: 30 points, 2 questions, off until ticked');
ok(!isset(iv_gems(A)[$g2]) && isset(iv_gems_all(A)[$g2]), 'an off Gem is not offered to new rounds but is listed on Gems');
$g3 = iv_gem_copy(A, (int) $gem['id'], 'Owner A');
$g3r = iv_gem(A, $g3);
eq([$g3r['name'], (int) $g3r['active'], $g3r['criteria_json'], $g3r['fields_json']], ['Copy of ' . $g1b['name'], 0, $g1b['criteria_json'], $g1b['fields_json']], 'a copy: same rubric and questions, switched off');
ok(!isset(iv_gems_all(B)[$g3]) || iv_gem(B, $g3)['name'] !== $g3r['name'], 'organisation B does not see A\'s Gems');
$usage = iv_gem_usage(A);
ok(count($usage[(int) $gem['id']]['rounds'] ?? []) >= 1 && ($usage[(int) $gem['id']]['checks'] ?? 0) >= 1, 'usage: rounds and checks counted per Gem');

$tried = [];
$GLOBALS['iv_gemini_hook'] = static function ($p) use (&$tried) {
    $tried[] = $p;
    return [true, ['criteria' => [['n' => 1, 'score' => 15, 'reason' => 'tested'], ['n' => 2, 'score' => 99, 'reason' => 'too many']], 'summary' => 'Good.', 'improvements' => ['Ask more users']]];
};
$runsBefore = (int) fabo_one('SELECT COUNT(*) n FROM iv_ai_runs WHERE org_id = ?', 'i', [A])['n'];
[$tt, $tb, $ts, $ti] = iv_gem_try(A, $g2, ['try_title' => 'Solar dryer', 'try' => [$g2r['fields'][0]['uid'] => 'We dried 20 kg of chilli.']]);
eq([$tt, $tb[1]['score'], $ts, $ti], [25.0, 10.0, 'Good.', ['Ask more users']], 'trial run: marks clamped to the weights, summary and tips');
ok(str_contains($tried[0] ?? '', 'We dried 20 kg of chilli.') && str_contains($tried[0], 'Be strict.'), 'the trial prompt carries the Gem and the sample answer');
eq((int) fabo_one('SELECT COUNT(*) n FROM iv_ai_runs WHERE org_id = ?', 'i', [A])['n'], $runsBefore, 'a trial run saves no check');
throws(static fn() => iv_gem_try(A, $g2, ['try' => []]), 'a trial needs an answer', 'sample answer');

$_GET = ['id' => (string) $g2];
ob_start();
iv_page_gems(A);
$h = ob_get_clean();
ok(str_contains($h, 'Gem 2 – Validation') && str_contains($h, 'Try it on a sample idea') && str_contains($h, 'data-crit-total') && str_contains($h, 'Make a copy'), 'the Gems page shows the editor, copy and trial');
$GLOBALS['iv_grant'] = 'view';
ob_start();
iv_page_gems(A);
$h = ob_get_clean();
ok(!str_contains($h, 'name="a" value="gem-save"') && str_contains($h, 'Rubric'), 'view only: the rubric, no editor');
$GLOBALS['iv_grant'] = 'edit';
$_GET = [];

cleanup();
ob_end_clean();
echo $pass . ' passed, ' . count($fail) . " failed\n";
foreach ($fail as $f) {
    echo "  - $f\n";
}
exit($fail ? 1 : 0);

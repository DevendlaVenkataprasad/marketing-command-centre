<?php
/**
 * CEO Dashboard self-test. Builds two organisations with known data in a
 * THROWAWAY database and checks the figures, the access rules and the
 * separation between organisations:
 *
 *     php tests/ceo_selftest.php
 *
 * Refuses to run unless the database is named ceo_test. Never deploy this.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
chdir(dirname(__DIR__));
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SCRIPT_NAME'] = '/ceo.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once 'lib/ui.php';
require_once 'lib/ceo/app.php';
if (DB_NAME !== 'ceo_test') {
    fwrite(STDERR, "Refusing: this test only runs on a database named ceo_test.\n");
    exit(2);
}
fabo_boot();
require_once 'lib/hr/core.php';
require_once 'lib/inc/core.php';
require_once 'lib/inc/schema.php';
require_once 'lib/it/app.php';
require_once 'lib/fin/app.php';
require_once 'lib/ds/core.php';
require_once 'lib/cw/core.php';

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

$today = date('Y-m-d');
$ago = static fn(int $d) => date('Y-m-d', strtotime("-$d days"));
$ahead = static fn(int $d) => date('Y-m-d', strtotime("+$d days"));

/* ---------------------------------------------------------------- reset */
foreach (fabo_all('SHOW TABLES') as $r) {
    $t = array_values($r)[0];
    if (preg_match('/^(ceo_|inc_o|it_o|fa_o|fp_o)/', $t)) {
        fabo_exec('SET FOREIGN_KEY_CHECKS = 0');
        fabo_exec("DROP TABLE `$t`");
    }
}
fabo_exec('SET FOREIGN_KEY_CHECKS = 1');
fabo_exec('DELETE FROM app_settings WHERE k = "ceo_schema"');
foreach (['events', 'requisitions', 'master_log', 'client_sheet_access', 'clients', 'hr_employees', 'hr_designations', 'hr_departments', 'hr_masters', 'hr_leave_requests', 'hr_claims', 'hr_tickets', 'hr_attendance', 'hr_lifecycle_tasks', 'hr_job_openings'] as $t) {
    try {
        fabo_exec('SET FOREIGN_KEY_CHECKS = 0');
        fabo_exec("DELETE FROM `$t`");
    } catch (Throwable $e) {
    }
}
hr_install();
ds_install();
cw_install();
foreach (['cw_centres', 'cw_spaces', 'cw_startups', 'cw_waiting', 'ds_documents', 'ds_signature_requests'] as $t) {
    fabo_exec("DELETE FROM `$t`");
}
fabo_exec('SET FOREIGN_KEY_CHECKS = 1');

/* ---------------------------------------------------------------- people */
function person(string $name, string $kind, ?int $parent, ?int $id = null): int
{
    return fabo_exec('INSERT INTO clients (id, name, kind, email, token, parent_id, organisation, password_hash) VALUES (?,?,?,?,?,?,?,?)', 'issssiss',
                     [$id, $name, $kind, strtolower(str_replace(' ', '.', $name)) . '@example.test', bin2hex(random_bytes(16)), $parent, $parent === null ? $name : '', password_hash('Test-pass-1', PASSWORD_DEFAULT)]);
}
const ORG = 77;
const OTHER = 88;
person('Hub Owner', 'client', null, ORG);
person('Other Org Head', 'client', null, OTHER);
$cfo = person('Chitra CFO', 'team', ORG, 701);
$hrm = person('Hari Manager', 'team', ORG, 702);
$lead = person('Vani Spoke Lead', 'team', ORG, 703);
$none = person('Nobody Special', 'team', ORG, 704);
$foreign = person('Foreign Person', 'team', OTHER, 801);
$ctoA = person('Tara CTO', 'team', ORG, 705);
$ctoB = person('Tarun CTO', 'team', ORG, 706);
$grant = static function (int $id, array $keys) {
    foreach ($keys as $k) {
        fabo_exec('INSERT INTO client_sheet_access (client_id, sheet_key, permission) VALUES (?,?,"edit")', 'is', [$id, $k]);
    }
};
$grant(ORG, ['ceo', 'incubation', 'hr', 'it', 'facilities', 'coworking', 'esign', 'finance', 'events', 'requisitions', 'campaigns']);
$grant(OTHER, ['ceo', 'hr']);          // holds HR only

/* ---------------------------------------------------------------- spokes */
foreach (['Amaravati', 'Vishakapatnam', 'Vijayawada', 'Rajamahendravaram', 'Tirupati', 'Ananthapuram'] as $i => $n) {
    fabo_exec('DELETE FROM lists WHERE list_name = "Hub / Spoke" AND client_id = ? AND value = ?', 'is', [ORG, $n]);
    fabo_exec('INSERT INTO lists (list_name, value, sort_order, active, client_id) VALUES ("Hub / Spoke",?,?,1,?)', 'sii', [$n, $i, ORG]);
    fabo_exec('INSERT INTO cw_centres (org_id, name, kind, manager, sort) VALUES (?,?,?,"RTIH",?)', 'issi', [ORG, $n, $i === 0 ? 'hub' : 'spoke', $i]);
}
$centre = (int) fabo_one('SELECT id FROM cw_centres WHERE org_id = ? AND name = "Amaravati"', 'i', [ORG])['id'];
$vij = (int) fabo_one('SELECT id FROM cw_centres WHERE org_id = ? AND name = "Vijayawada"', 'i', [ORG])['id'];

/* ---------------------------------------------------------------- HR */
$dep = fabo_exec('INSERT INTO hr_departments (org_id, name) VALUES (?, "Operations")', 'i', [ORG]);
$dCfo = fabo_exec('INSERT INTO hr_designations (org_id, name) VALUES (?, "CFO")', 'i', [ORG]);
$dCto = fabo_exec('INSERT INTO hr_designations (org_id, name) VALUES (?, "CTO")', 'i', [ORG]);
$dExec = fabo_exec('INSERT INTO hr_designations (org_id, name) VALUES (?, "Executive")', 'i', [ORG]);
$locV = fabo_exec('INSERT INTO hr_masters (org_id, kind, name) VALUES (?, "location", "Vijayawada Spoke")', 'i', [ORG]);
$locH = fabo_exec('INSERT INTO hr_masters (org_id, kind, name) VALUES (?, "location", "Amaravati Hub")', 'i', [ORG]);
$emp = static fn(string $code, string $name, ?int $person, int $des, int $loc, string $status = 'active', ?string $join = null)
    => fabo_exec('INSERT INTO hr_employees (org_id, emp_code, full_name, person_id, department_id, designation_id, location_id, status, date_of_joining) VALUES (?,?,?,?,?,?,?,?,?)', 'issiiiiss',
                 [ORG, $code, $name, $person, $dep, $des, $loc, $status, $join]);
$e1 = $emp('E1', 'Chitra CFO', $cfo, $dCfo, $locH);
$e2 = $emp('E2', 'Tara CTO', $ctoA, $dCto, $locH);
$e3 = $emp('E3', 'Tarun CTO', $ctoB, $dCto, $locV);
$e4 = $emp('E4', 'Noor Exec', null, $dExec, $locV, 'probation', $today);
$emp('E5', 'Gone Person', null, $dExec, $locV, 'exited');
fabo_exec("INSERT INTO hr_leave_requests (org_id, employee_id, leave_type_id, start_date, end_date, days, status, submitted_at) VALUES (?,?,1,?,?,2,'submitted',NOW())", 'iiss', [ORG, $e4, $ahead(3), $ahead(4)]);
$hrClaim = fabo_exec("INSERT INTO hr_claims (org_id, employee_id, number, expense_date, amount, description, status) VALUES (?,?,'CL-1',?,500,'Travel','submitted')", 'iis', [ORG, $e3, $today]);
fabo_exec("INSERT INTO hr_tickets (org_id, employee_id, number, subject, body, status) VALUES (?,?,'T-1','Salary query about my deduction','x','open')", 'ii', [ORG, $e3]);
fabo_exec("INSERT INTO hr_attendance (org_id, employee_id, work_date, status) VALUES (?,?,?,'Present'),(?,?,?,'Absent'),(?,?,?,'Weekly Off')", 'iisiisiis', [ORG, $e1, $today, ORG, $e2, $today, ORG, $e3, $today]);

/* ---------------------------------------------------------------- incubation */
inc_use_org(ORG);
$GLOBALS['inc_settings_cache'] = [];
inc_install();
$A = ceo_t('inc_applications', ORG);
$S = ceo_t('inc_startups', ORG);
$G = ceo_t('inc_stages', ORG);
$M = ceo_t('inc_masters', ORG);
$F = ceo_t('inc_forms', ORG);
$V = ceo_t('inc_form_versions', ORG);
$form = fabo_exec("INSERT INTO `$F` (name, purpose, public_token) VALUES ('Apply','incubation',?)", 's', [bin2hex(random_bytes(16))]);
$ver = fabo_exec("INSERT INTO `$V` (form_id, version_no, status) VALUES (?,1,'published')", 'i', [$form]);
$stageOpen = (int) fabo_one("SELECT id FROM `$G` WHERE pipeline = 'crm' AND is_terminal = 0 ORDER BY sort LIMIT 1")['id'];
$stageEnd = fabo_exec("INSERT INTO `$G` (pipeline, code, name, sort, is_terminal) VALUES ('crm','rejected','Rejected',99,1)");
fabo_exec("DELETE FROM `$M` WHERE kind = 'spoke'");
$spV = fabo_exec("INSERT INTO `$M` (kind, name) VALUES ('spoke','RTIH Vijayawada')");
$spH = fabo_exec("INSERT INTO `$M` (kind, name) VALUES ('spoke','Amaravati')");
$startup = static fn(string $n, int $spoke) => fabo_exec("INSERT INTO `$S` (name, name_norm, spoke_id) VALUES (?,?,?)", 'ssi', [$n, strtolower($n), $spoke]);
$app = static fn(string $name, ?int $startupId, int $spoke, int $stage, string $when, string $dup = 'none', string $purpose = 'incubation')
    => fabo_exec("INSERT INTO `$A` (form_id, form_version_id, purpose, startup_name, startup_id, spoke_id, stage_id, submitted_at, dup_status) VALUES (?,?,?,?,?,?,?,?,?)", 'iissiiiss',
                 [$form, $ver, $purpose, $name, $startupId, $spoke, $stage, $when . ' 10:00:00', $dup]);
$s1 = $startup('Alpha', $spV);
$s2 = $startup('Beta', $spH);
$s3 = $startup('Gamma', $spH);
$a1 = $app('Alpha', $s1, $spV, $stageOpen, $today);
$app('Alpha', $s1, $spV, $stageOpen, $today);                        // second application, same startup
$a3 = $app('Beta', $s2, $spH, $stageOpen, $today);
$app('Gamma', $s3, $spH, $stageEnd, $today);                         // final stage: not active
$app('Beta', $s2, $spH, $stageOpen, $today, 'confirmed');            // a confirmed duplicate
$app('Beta', $s2, $spH, $stageOpen, date('Y-m-d', strtotime(date('Y-m-01') . ' -5 days')));  // last month
$app('Event guest', null, $spV, $stageOpen, $today, 'none', 'event');
$lifeStage = (int) fabo_one("SELECT id FROM `$G` WHERE pipeline = 'lifecycle' ORDER BY sort LIMIT 1")['id'];
fabo_exec('INSERT INTO `' . ceo_t('inc_workspaces', ORG) . "` (application_id, startup_id, team_id, owner_id, stage_id, target_date) VALUES (?,?,1,1,?,?)", 'iiis', [$a1, $s1, $lifeStage, $ago(10)]);
fabo_exec('INSERT INTO `' . ceo_t('inc_tasks', ORG) . "` (entity, entity_id, application_id, title, due_date, status) VALUES ('application',?,?,'Follow up',?,'open')", 'iis', [$a3, $a3, $ago(2)]);

/* ---------------------------------------------------------------- marketing */
$ev = static fn(string $name, string $status, ?string $spoke, string $start, ?string $end, int $att = 0, float $spent = 0, int $org = ORG)
    => fabo_exec('INSERT INTO events (event_name, status, hub_spoke, start_date, end_date, actual_attendees, budget_spent, client_id) VALUES (?,?,?,?,?,?,?,?)', 'sssssidi', [$name, $status, $spoke, $start, $end, $att, $spent, $org]);
$ev('Demo Day', 'Completed', 'Vijayawada', $today, $today, 120, 5000);
$ev('Founders Meet', 'Completed', 'Amaravati', $today, $today, 80, 3000);
$ev('Next Summit', 'Planned', 'Vishakapatnam', $ahead(5), $ahead(6));
$ev('Cancelled Thing', 'Cancelled', 'Amaravati', $today, $today, 999);
$ev('Foreign Event', 'Completed', 'Amaravati', $today, $today, 5000, 9999, OTHER);

/* ---------------------------------------------------------------- IT + facilities */
foreach (['it', 'fa'] as $desk) {
    it_use($desk, ORG);
    it_install();
    $T = ceo_t($desk . '_tickets', ORG);
    $tk = static fn(string $title, string $st, ?string $due, ?string $resolved) => fabo_exec("INSERT INTO `$T` (token, title, status, due_at, resolved_at) VALUES (?,?,?,?,?)", 'sssss', [bin2hex(random_bytes(16)), $title, $st, $due, $resolved]);
    $tk('Printer', 'new', date('Y-m-d H:i:s', strtotime('-2 days')), null);
    $tk('Wifi', 'in_progress', date('Y-m-d H:i:s', strtotime('+2 days')), null);
    $tk('Done', 'resolved', date('Y-m-d H:i:s', strtotime('+1 day')), date('Y-m-d H:i:s'));
    $tk('Late fix', 'resolved', date('Y-m-d H:i:s', strtotime('-1 day')), date('Y-m-d H:i:s'));
}
$IA = ceo_t('it_assets', ORG);
fabo_exec("INSERT INTO `$IA` (tag, name, status) VALUES ('A1','Laptop','assigned'),('A2','Laptop','in_stock'),('A3','Laptop','in_stock')");

/* ---------------------------------------------------------------- co-working */
for ($i = 1; $i <= 4; $i++) {
    fabo_exec("INSERT INTO cw_spaces (centre_id, space_id, type, managed_by, occupied_by, occupant) VALUES (?,?,'Desk','RTIH',?,?)", 'isss', [$centre, 'D' . $i, $i <= 3 ? 'Startup' : 'Vacant', $i <= 2 ? 'Alpha' : ($i === 3 ? 'Beta' : '')]);
}
fabo_exec("INSERT INTO cw_startups (centre_id, name, due_amount) VALUES (?, 'Alpha', 2000), (?, 'Beta', 1000)", 'ii', [$centre, $centre]);
fabo_exec("INSERT INTO cw_spaces (centre_id, space_id, type, managed_by, occupied_by, occupant) VALUES (?,'V1','Desk','RTIH','Startup','Gamma'),(?,'V2','Desk','RTIH','Vacant','')", 'ii', [$vij, $vij]);
fabo_exec("INSERT INTO cw_startups (centre_id, name, due_amount) VALUES (?, 'Gamma', 700)", 'i', [$vij]);

/* ---------------------------------------------------------------- signatures */
$doc = static fn(string $ref, string $title, string $unit, string $status, ?string $done, string $conf = 'internal') => fabo_exec('INSERT INTO ds_documents (org_id, ref_no, tracking_code, title, unit, status, completed_at, confidentiality) VALUES (?,?,?,?,?,?,?,?)', 'isssssss', [ORG, $ref, substr(md5($ref), 0, 16), $title, $unit, $status, $done, $conf]);
$d1 = $doc('D-1', 'Lease renewal', 'Vijayawada', 'pending', null);
$d2 = $doc('D-2', 'Board secret memo', 'Amaravati', 'pending', null, 'confidential');
$doc('D-3', 'Signed thing', 'Amaravati', 'signed', date('Y-m-d H:i:s'));
$req = static fn(int $d, string $due) => fabo_exec("INSERT INTO ds_signature_requests (org_id, doc_id, version_id, cycle, stage_seq, person_id, status, due_at, activated_at) VALUES (?,?,1,1,1,?,'pending',?,NOW())", 'iiis', [ORG, $d, 701, $due]);
fabo_exec('SET FOREIGN_KEY_CHECKS = 0');
$req($d1, date('Y-m-d H:i:s', strtotime('-1 day')));
$req($d2, date('Y-m-d H:i:s', strtotime('+3 days')));
fabo_exec('SET FOREIGN_KEY_CHECKS = 1');

/* ---------------------------------------------------------------- finance */
fp_use(ORG);
fp_install();
$W = ceo_t('fp_wf_steps', ORG);
$C = ceo_t('fp_claims', ORG);
$B = ceo_t('fp_bills', ORG);
$P = ceo_t('fp_payments', ORG);
$mirror = fabo_exec("INSERT INTO `$C` (number, employee_person_id, employee_name, purpose, status, hr_claim_id) VALUES ('C-1',703,'Tarun','Travel','submitted',?)", 'i', [$hrClaim]);
$step = static fn(string $t, int $id) => fabo_exec("INSERT INTO `$W` (doc_type, doc_id, level, approver_type, status, activated_at) VALUES (?,?,1,'role','pending',NOW())", 'si', [$t, $id]);
$step('claim', $mirror);
$step('bill', 1);
fabo_exec("INSERT INTO `$B` (number, vendor_id, bill_no, bill_no_key, bill_date, due_date, total, status) VALUES ('B-1',1,'x','x',?,?,10000,'approved'), ('B-2',1,'y','y',?,?,20000,'draft')", 'ssss', [$today, $ago(3), $today, $ago(3)]);
fabo_exec("INSERT INTO `$P` (number, payee_name, pay_date, amount, status) VALUES ('P-1','V',?,4000,'completed'), ('P-2','V',?,9000,'draft')", 'ss', [$today, $today]);

/* ---------------------------------------------------------------- helpers */
$vOf = static function (int $id): array {
    return fabo_person_viewer(fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [$id]));
};
$f = ['from' => date('Y-m-01'), 'to' => $today, 'spoke' => '', 'module' => '', 'dept' => 0];
$grantRow = static function (string $subject, int $person, string $pos, int $profile) {
    fabo_exec('INSERT INTO ceo_access (org_id, subject, person_id, position, profile_id, status, granted_by) VALUES (?,?,?,?,?,"active","test")
               ON DUPLICATE KEY UPDATE profile_id = VALUES(profile_id), status = "active"', 'isisi', [ORG, $subject, $person, $pos, $profile]);
};
$profile = static function (string $name, array $scope) {
    return fabo_exec('INSERT INTO ceo_profiles (org_id, name, scope_json) VALUES (?,?,?)', 'iss', [ORG, $name, json_encode(ceo_scope_clean($scope))]);
};
function flush_cache(): void
{
    fabo_exec('DELETE FROM ceo_cache');
}

/* ================================================================ access */
$owner = $vOf(ORG);
$a = ceo_access($owner, ORG);
ok($a !== null && $a['owner'], 'the head is the owner');
eq($a['sensitive'], [], 'the owner holds no sensitive category until switched on');
ok(ceo_access($vOf($none), ORG) === null, 'a member with no grant has no access');
ok(ceo_access($vOf($foreign), ORG) === null, 'a member of another organisation has no access');
ok(ceo_access($vOf(OTHER), ORG) === null, 'the head of another organisation has no access here');

$profiles = ceo_profiles(ORG);
$exec = array_values(array_filter($profiles, static fn($p) => $p['name'] === 'Executive overview'))[0];
$fin = array_values(array_filter($profiles, static fn($p) => $p['name'] === 'Executive with finance'))[0];
eq($exec['scope']['sensitive'], [], 'Executive overview opens nothing sensitive');

$grantRow('person', $cfo, '', (int) $fin['id']);
$acfo = ceo_access($vOf($cfo), ORG);
ok($acfo && in_array('finance', $acfo['sensitive'], true), 'a named person gets the profile');
eq($acfo['via'], 'person', 'and it says how');

// Position grant: reaches exactly its holders.
$reach = ceo_position_people(ORG, 'CTO');
eq(count($reach), 2, 'the CTO position reaches two people');
$grantRow('position', 0, 'CTO', (int) $exec['id']);
ok(ceo_access($vOf($ctoA), ORG) !== null && ceo_access($vOf($ctoB), ORG) !== null, 'both CTOs hold access through the position');
ok(ceo_access($vOf($none), ORG) === null, 'a position grant reaches nobody else');
fabo_exec('UPDATE hr_employees SET status = "exited" WHERE id = ?', 'i', [$e3]);
ok(ceo_access($vOf($ctoB), ORG) === null, 'leaving the job ends position access at once');
fabo_exec('UPDATE hr_employees SET status = "active" WHERE id = ?', 'i', [$e3]);

// Revoke takes effect on the next call, no cached answer.
fabo_exec('UPDATE ceo_access SET status = "revoked" WHERE org_id = ? AND subject = "position"', 'i', [ORG]);
ok(ceo_access($vOf($ctoA), ORG) === null, 'revoking applies at once');
fabo_exec('UPDATE ceo_access SET status = "active" WHERE org_id = ? AND subject = "position"', 'i', [ORG]);

// A person row beats the position row.
$hrOnly = $profile('HR only', ['modules' => ['hr'], 'spokes' => '*', 'actions' => ['view']]);
$grantRow('person', $ctoA, '', $hrOnly);
eq(ceo_access($vOf($ctoA), ORG)['modules'], ['hr'], 'a named row beats the position');
$grantRow('person', $hrm, '', $hrOnly);

// Spoke-limited.
$vijOnly = $profile('Vijayawada lead', ['modules' => array_keys(ceo_modules()), 'spokes' => ['vijayawada'], 'actions' => ['view', 'filter', 'export']]);
$grantRow('person', $lead, '', $vijOnly);

/* ================================================================ figures */
$kv = static function (array $viewer, ?array $acc, array $filter) {
    flush_cache();
    return ceo_kpi_values($viewer, ORG, $acc, $filter, true);
};
$k = $kv($vOf($cfo), $acfo, $f);
eq($k['inc_new']['value'], 5, 'new applications: the month, confirmed duplicate left out, last month left out');
eq($k['inc_registrations']['value'], 1, 'event registrations from event forms');
eq($k['inc_active']['value'], 2, 'active startups: Alpha once though it has two applications; Gamma final, not counted');
eq($k['inc_incubating']['value'], 1, 'startups under incubation');
eq($k['inc_delayed']['value'], 1, 'delayed milestone');
eq($k['mk_events']['value'], 2, 'events conducted: completed only, this organisation only');
eq($k['mk_attendance']['value'], 200, 'attendance: cancelled and foreign rows left out');
eq($k['mk_programs']['value'], 1, 'active programmes');
eq($k['hr_active']['value'], 4, 'active employees: active, probation; exited not counted');
eq($k['hr_pending']['value'], 3, 'pending HR requests: leave + claim + ticket');
eq($k['hr_attendance']['value'], 50.0, 'attendance rate ignores weekly offs');
eq($k['it_open']['value'], 2, 'open IT tickets');
eq($k['it_resolved']['value'], 2, 'IT resolved');
eq($k['it_sla']['value'], 50.0, 'IT on time');
eq($k['it_assigned']['value'], 1, 'assets assigned');
eq($k['it_available']['value'], 2, 'assets available');
eq($k['fa_open']['value'], 2, 'facilities open is its own desk');
eq($k['cw_occupancy']['value'], 66.7, 'occupancy across centres: 4 of 6');
eq($k['cw_members']['value'], 3, 'memberships: a startup with two desks counts once');
eq($k['cw_dues']['value'], 3700, 'dues with finance access');
eq($k['ds_pending']['value'], 2, 'pending signatures');
eq($k['ds_overdue']['value'], 1, 'overdue signatures');
eq($k['ds_signed']['value'], 1, 'documents signed');
eq($k['fin_approvals']['value'], 2, 'finance card counts every step');
eq($k['fin_expenses']['value'], 10000, 'expenses: approved bills only');
eq($k['fin_payments']['value'], 4000, 'payments: completed only');
eq($k['fin_overdue_ap']['value'], 1, 'bills overdue');
eq($k['x_approvals']['value'], 3, 'pending approvals: HR leave + HR claim + finance bill; the mirrored claim counts once');
eq($k['x_overdue']['value'], 4, 'overdue: one each from incubation, IT, facilities and signatures');

/* ================================================================ restrictions */
$ko = $kv($owner, $a, $f);
eq($ko['fin_expenses']['state'], 'restricted', 'the owner does not see money until switched on');
ok($ko['fin_expenses']['value'] === null, 'and no value is sent');
ok($ko['cw_dues']['value'] === null, 'dues are finance too');
ok($ko['hr_payroll']['value'] === null, 'salary needs its own tick');
eq($k['hr_payroll']['state'], 'restricted', 'finance access does not open salary');

$ah = ceo_access($vOf($hrm), ORG);
$kh = $kv($vOf($hrm), $ah, $f);
ok(!isset($kh['inc_new']) && !isset($kh['fin_expenses']), 'a one-module profile never sees another module');

$al = ceo_access($vOf($lead), ORG);
$fl = ['spoke' => 'vijayawada'] + $f;
$kl = $kv($vOf($lead), $al, $fl);
eq($kl['inc_new']['value'], 3, 'spoke cut: applications with spelling RTIH Vijayawada');
eq($kl['mk_attendance']['value'], 120, 'spoke cut: events');
eq($kl['cw_occupancy']['value'], 50.0, 'spoke cut: co-working');
eq($kl['ds_pending']['value'], 1, 'spoke cut: signatures');
ok(!isset($kl['it_open']) && !isset($kl['fin_approvals']), 'someone limited to a spoke never sees an organisation-wide figure');

/* ================================================================ items */
flush_cache();
$acts = ceo_actions($vOf($lead), ORG, $al, $fl);
foreach ($acts['items'] as $i) {
    ok($i['spoke'] === 'vijayawada', 'spoke lead queue stays in the spoke: ' . $i['title']);
}
$json = json_encode(ceo_actions($owner, ORG, $a, $f, true));
ok(!str_contains($json, 'Salary query'), 'an HR ticket subject never shows without personal access');
ok(!str_contains($json, 'Noor Exec'), 'an employee name never shows without personal access');
ok(!str_contains($json, 'Board secret memo'), 'a confidential document title never shows without contracts access');
ok(str_contains($json, 'Lease renewal'), 'an ordinary document title does show to someone who can open the module');
$fromLead = json_encode(ceo_actions($vOf($lead), ORG, $al, $fl, true));
ok(!str_contains($fromLead, 'Lease renewal'), 'someone who cannot open the module sees only the neutral label');
ok(!str_contains($fromLead, 'sign.php?') || !ceo_can_open($vOf($lead), 'marketing', ORG), 'and no link');

/* ================================================================ isolation */
$other = $vOf(OTHER);
$ao = ceo_access($other, OTHER);
flush_cache();
$k2 = ceo_kpi_values($other, OTHER, $ao, $f, true);
eq($k2['hr_active']['value'], 0, 'another organisation reads none of these employees');
eq($k2['mk_events']['state'], 'not_granted', 'a module it does not hold says so');
eq($k2['inc_new']['state'], 'not_granted', 'and is never a zero');
eq($k2['fin_approvals']['state'], 'not_granted', 'Finance too');
ok(ceo_t('inc_applications', 88) !== ceo_t('inc_applications', ORG), 'per-organisation tables differ');

/* ================================================================ cut states, targets, snapshots */
$kc = $kv($owner, $a, ['spoke' => 'vijayawada'] + $f);
eq($kc['it_open']['state'], 'not_split', 'IT under a spoke filter is said not to be split, not zero');

fabo_exec('INSERT INTO ceo_targets (org_id, kpi_key, period_type, target_value, owner_label) VALUES (?,"inc_new","monthly",10,"Incubation head"), (?,"it_open","weekly",1,"IT lead"), (?,"fin_expenses","monthly",99,"CFO")', 'iii', [ORG, ORG, ORG]);
$t = ceo_targets_eval($owner, ORG, $a, $f);
eq(count($t), 2, 'a target on a figure you may not see is left out');
$byKey = array_column($t, null, 'kpi');
eq($byKey['inc_new']['actual'], 5, 'target actual uses the same KPI');
eq($byKey['inc_new']['gap'], 5.0, 'target gap');
eq($byKey['inc_new']['pct'], 50.0, 'achievement');
eq($byKey['it_open']['status'], 'behind', 'lower-is-better target past 125% is behind');
eq(ceo_target_status(90.0, 100.0, 'up', 'flow', .5), 'on_track', 'a flow ahead of pace is on track');
eq(ceo_target_status(10.0, 100.0, 'up', 'flow', .5), 'behind', 'a flow well behind pace is behind');
eq(ceo_target_status(null, 100.0, 'up', 'flow', .5), 'no_data', 'no figure is no data, never a status');

eq(ceo_spoke_key('Vishakapatnam'), ceo_spoke_key('Visakhapatnam'), 'spelling of Visakhapatnam');
eq(ceo_spoke_key('RTIH Ananthapuram Spoke'), 'anantapur', 'spelling of Anantapur');
eq(ceo_spoke_key('Rajamahendravaram'), 'rajahmundry', 'spelling of Rajahmundry');
eq(count(ceo_spokes(ORG)), 6, 'six places, no duplicates');
eq(array_values(ceo_spokes(ORG))[0]['kind'], 'hub', 'the hub first');

ceo_setting_put(ORG, 'snapshot_day', '');
ok(ceo_snapshot_take(ORG) > 0, 'daily reading taken');
eq(ceo_snapshot_take(ORG), 0, 'and once a day only');
fabo_exec('UPDATE ceo_snapshots SET day = ? WHERE org_id = ? AND kpi_key = "it_open" AND spoke_key = ""', 'si', [$ago(40), ORG]);
$prev = ['from' => $ago(45), 'to' => $ago(30)];
ok(isset(ceo_snapshot_history(ORG, '', $prev + $f)['prev']['it_open']), 'a stock figure compares with its reading');

/* ================================================================ reports */
$r = ceo_report_build($vOf($cfo), ORG, $acfo, 'monthly', $f);
foreach (['kpis', 'departments', 'hub', 'completed', 'pending', 'exceptions', 'targets', 'coverage'] as $s) {
    ok(array_key_exists($s, $r), 'report has ' . $s);
}
$pdf = ceo_report_pdf($r)->output();
ok(str_starts_with($pdf, '%PDF-') && strlen($pdf) > 3000, 'PDF builds');
$rl = ceo_report_build($vOf($lead), ORG, $al, 'custom', $fl);
$labels = array_column($rl['kpis'], 'label');
ok(!in_array('Open IT tickets', $labels, true), 'a spoke lead\'s report has no organisation-wide figure');
ok(count($rl['hub']['spokes']) === 1 && $rl['hub']['spokes'][0]['key'] === 'vijayawada', 'nor another spoke');
ok(!str_contains(json_encode($r), 'Foreign Event'), 'a report holds nothing of another organisation');
$ro = ceo_report_build($owner, ORG, $a, 'monthly', $f);
ok(!str_contains(json_encode(array_column($ro['kpis'], 'value', 'label')['Expenses booked'] ?? 'x'), '10000'), 'owner export has no money without the tick');

/* ================================================================ schedule */
eq(ceo_next_run('weekly', '2026-11-04'), '2026-11-09', 'weekly runs on Monday');
eq(ceo_next_run('monthly', '2026-11-04'), '2026-12-01', 'monthly on the first');
eq(ceo_next_run('quarterly', '2026-11-04'), '2027-01-01', 'quarterly on the next quarter');
eq(ceo_period_window('annual', '2026-02-10'), ['2025-04-01', '2026-03-31'], 'the year runs April to March');
eq(ceo_period_window('quarterly', '2026-11-04'), ['2026-10-01', '2026-12-31'], 'quarter');

echo "\n" . $pass . ' passed, ' . count($fail) . " failed\n";
exit($fail ? 1 : 0);

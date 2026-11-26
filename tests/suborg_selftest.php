<?php
/**
 * A client's own client is an organisation of its own, in every module. LOCAL ONLY:
 *   php tests/suborg_selftest.php
 * Throwaway accounts 9941 (a client), 9942 (a client 9941 made), 9943 (9942's
 * team member), 9944 (9941's team member); everything they make is removed.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
chdir(dirname(__DIR__));
ob_start();
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SCRIPT_NAME'] = '/people.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once 'lib/ui.php';
require_once 'lib/people.php';
require_once 'lib/inc/org.php';
require_once 'lib/inc/nav.php';
require_once 'lib/inc/render.php';
require_once 'lib/it/core.php';
require_once 'lib/cw/core.php';
require_once 'lib/edu/core.php';
require_once 'lib/iv/core.php';
require_once 'lib/ds/core.php';
require_once 'lib/fin/core.php';
require_once 'lib/ceo/core.php';
require_once 'lib/crm.php';
require_once 'lib/hr/core.php';
if (str_contains(str_replace('\\', '/', __DIR__), '/home/') || !in_array(DB_HOST, ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "Refusing: local only.\n");
    exit(2);
}
fabo_boot();

const A = 9941;  // RTIH-like client
const S = 9942;  // a client A made ("Higher Education Department")
const ST = 9943; // S's team member
const AT = 9944; // A's team member
$ids = implode(',', [A, S, ST, AT]);

function cleanup(): void
{
    global $ids;
    fabo_exec("DELETE FROM client_sheet_access WHERE client_id IN ($ids)");
    fabo_exec("DELETE FROM clients WHERE id IN ($ids)");
    foreach (['iv_settings', 'iv_colleges', 'iv_departments', 'iv_students', 'iv_rounds'] as $t) {
        try {
            fabo_exec("DELETE FROM `$t` WHERE org_id IN (" . A . ',' . S . ')');
        } catch (Throwable $e) {
        }
    }
    fabo_exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (fabo_all('SHOW TABLES') as $r) {
        $t = array_values($r)[0];
        if (preg_match('/^[a-z]+_o(' . A . '|' . S . ')_/', $t)) {
            fabo_exec("DROP TABLE `$t`");
        }
    }
    fabo_exec('SET FOREIGN_KEY_CHECKS = 1');
}
function person(int $id, string $name, string $kind, ?int $parent): array
{
    fabo_exec('INSERT INTO clients (id, name, kind, email, token, parent_id, organisation, password_hash) VALUES (?,?,?,?,?,?,?,?)', 'issssiss',
              [$id, $name, $kind, 'so' . $id . '@example.test', bin2hex(random_bytes(16)), $parent, $kind === 'client' ? $name : '', 'x']);
    return fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [$id]);
}
function grant(int $id, string $key, string $perm): void
{
    fabo_exec('INSERT INTO client_sheet_access (client_id, sheet_key, permission) VALUES (?,?,?) ON DUPLICATE KEY UPDATE permission = VALUES(permission)', 'iss', [$id, $key, $perm]);
}
function viewer(int $id): array
{
    $c = fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [$id]);
    return ['is_admin' => false, 'client' => $c, 'access' => fabo_client_access($id), 'state' => 'ok'];
}
$pass = 0;
$fail = [];
function eq($got, $want, string $what): void
{
    global $pass, $fail;
    if ($got === $want) {
        $pass++;
    } else {
        $fail[] = $what . ' (got ' . var_export($got, true) . ', want ' . var_export($want, true) . ')';
    }
}

cleanup();
person(A, 'Hub', 'client', null);
person(S, 'Higher Education Department', 'client', A);
person(ST, 'HE Staffer', 'team', S);
person(AT, 'Hub Staffer', 'team', A);
$modules = ['finance', 'crm', 'hr', 'incubation', 'organizers', 'it', 'coworking', 'esign', 'ceo', 'academic', 'ideaval', 'facilities'];
foreach ($modules as $k) {
    grant(A, $k, 'edit');
}

/* -------------------------------------------- whose organisation */
eq(fabo_module_org(viewer(A)), A, 'a client is its own organisation');
eq(fabo_module_org(viewer(S)), S, 'a client\'s client is its own organisation, not its parent\'s');
eq(fabo_module_org(viewer(ST)), S, 'its team member works in it');
eq(fabo_module_org(viewer(AT)), A, 'the parent\'s team member still works in the parent');
eq(fabo_viewer_org(viewer(S)), A, 'sheets keep their old rule (the parent\'s rows)');

/* -------------------------------------------- what may be offered */
$offerClient = fabo_delegatable_modules(fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [A]), 'client');
eq(array_keys($offerClient) == array_merge(array_values(array_diff($modules, ['ceo'])), ['ceo']) || count($offerClient) === count($modules), true, 'A may offer every module it holds to a client of its own, CEO Dashboard included');
eq(isset(fabo_delegatable_modules(fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [A]), 'team')['ceo']), false, 'the CEO Dashboard is not handed to team members here');
$labels = fabo_child_module_labels('client');
eq($labels['ideaval'][0], 'Startup Discovery', 'the client desk names modules without "— their own"');
grant(A, 'hr', 'view');
eq(fabo_delegatable_modules(fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [A]), 'client')['hr'], 'view', 'never more than A holds');
grant(A, 'hr', 'edit');

/* -------------------------------------------- saving S's modules */
$saved = fabo_save_child_modules(S, ['ideaval' => 'edit', 'incubation' => 'edit', 'ceo' => 'edit', 'it' => 'view', 'esign' => 'edit', 'finance' => 'edit', 'academic' => 'edit',
                                     'coworking' => 'edit', 'crm' => 'edit', 'hr' => 'edit', 'organizers' => 'view', 'facilities' => 'edit', 'made_up' => 'edit'], $offerClient, 'client');
eq(isset($saved['made_up']), false, 'only real modules are stored');
eq($saved['it'], 'view', 'stored as given');
grant(ST, 'ideaval', 'edit');
grant(ST, 'incubation', 'view');

/* -------------------------------------------- each module answers for S */
$vS = viewer(S);
$vST = viewer(ST);
eq(fabo_module_permission($vS, 'ideaval'), 'edit', 'S runs its Startup Discovery');
eq(iv_org($vS), S, 'Startup Discovery: S\'s own data');
eq(fabo_module_permission($vS, 'ideaval'), 'edit', 'Startup Discovery grant for S');
eq(iv_org($vST), S, 'Startup Discovery: S\'s team member works in S\'s data');
eq(iv_org(viewer(A)), A, 'Startup Discovery: A still works in A\'s');
eq(inc_org_of($vS), S, 'Incubation: S\'s own');
eq(inc_grant($vS), 'edit', 'Incubation grant for S');
eq(inc_grant($vST), 'view', 'Incubation grant for S\'s team member');
eq(inc_org_enabled(S), true, 'Incubation is enabled for S (public forms work)');
eq(inc_grant($vS, 'organizers'), 'view', 'Event Organizers grant for S');
eq(it_grant($vS, 'it'), 'view', 'IT Management for S');
eq(it_grant($vS, 'fa'), 'edit', 'Admin & Facilities for S');
eq(it_org_enabled('it', S), true, 'IT desk enabled for S');
eq(it_self_org($vST, 'it'), S, 'S\'s team member raises IT requests in S\'s helpdesk');
eq(cw_org($vS), S, 'Co-working: S\'s own');
eq(fabo_module_permission($vS, 'coworking'), 'edit', 'Co-working grant for S');
eq(edu_org($vS), S, 'Academic: S\'s own');
eq(ds_org_of($vS), S, 'Digital Signatures: S\'s own');
eq(ds_org_grant(S), 'edit', 'Digital Signatures grant for S');
eq(fp_org_of($vS), S, 'Finance: S\'s own');
eq(fp_org_grant(S), 'edit', 'Finance grant for S');
eq(ceo_org_of($vS), S, 'CEO Dashboard: S\'s own');
eq(ceo_org_enabled(S), true, 'CEO Dashboard enabled for S');
eq(ceo_is_owner($vS, S), true, 'S owns its CEO Dashboard');
eq(fabo_crm_is_head($vS), true, 'CRM: S heads its own');
eq(fabo_crm_module_org($vS), S, 'CRM: S\'s own boards');
eq(fabo_crm_person_org(fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [S])), S, 'CRM: S\'s people belong to S');
eq(fabo_hr_grant($vS), 'edit', 'HR for S');
eq(fabo_module_org_of($vS), S, 'HR: S is its own company');
eq(isset(iv_orgs()[S]) && isset(cw_orgs()[S]) && isset(edu_orgs()[S]) && isset(ds_orgs_available()[S]) && isset(ceo_orgs()[S]), true, 'the administrator can pick S in each module\'s switcher');

/* -------------------------------------------- separate data */
iv_install();
$ca = iv_college_save(A, ['name' => 'Hub college']);
$cs = iv_college_save(S, ['name' => 'HE college']);
eq(array_column(iv_colleges(S), 'name'), ['HE college'], 'S sees only its own Startup Discovery data');
eq(array_column(iv_colleges(A), 'name'), ['Hub college'], 'A sees only its own');
inc_use_kind('inc');
inc_start(['is_admin' => false, 'client' => $vS['client'], 'access' => $vS['access'], 'state' => 'ok']);
eq(inc_org(), S, 'Incubation opens S\'s own tables');
eq((bool) fabo_one("SELECT 1 y FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'inc_o" . S . "_settings'"), true, 'S gets its own Incubation tables');

/* -------------------------------------------- caps from above */
grant(A, 'ideaval', 'view');
eq(fabo_module_permission(viewer(S), 'ideaval'), 'view', 'A drops to View only: S drops with it');
eq(fabo_module_permission(viewer(ST), 'ideaval'), 'view', 'and so does S\'s team member');
fabo_exec('DELETE FROM client_sheet_access WHERE client_id = ? AND sheet_key = ?', 'is', [A, 'incubation']);
eq(inc_grant(viewer(S)), 'none', 'A loses Incubation: S loses it at once');
eq(inc_org_enabled(S), false, 'and S\'s public forms stop');
eq(inc_grant(viewer(ST)), 'none', 'and S\'s team member too');
fabo_exec('DELETE FROM client_sheet_access WHERE client_id = ? AND sheet_key = ?', 'is', [A, 'ceo']);
eq(ceo_org_enabled(S), false, 'A loses the CEO Dashboard: so does S');
// The stored grants are brought back within A's too.
fabo_recap_descendants(A);
$after = fabo_client_access(S);
eq([$after['ideaval'] ?? 'none', $after['incubation'] ?? 'none', $after['ceo'] ?? 'none'], ['view', 'none', 'none'], 'recap stores S\'s grants within A\'s');
fabo_exec('UPDATE clients SET revoked_at = NOW() WHERE id = ?', 'i', [A]);
eq(fabo_module_permission(viewer(S), 'esign'), 'none', 'A revoked: S runs nothing handed down');
fabo_exec('UPDATE clients SET revoked_at = NULL WHERE id = ?', 'i', [A]);

/* -------------------------------------------- unchanged for others */
eq(fabo_module_org(fabo_admin_viewer()), 0, 'the administrator: ours');
$staff = ['is_admin' => false, 'client' => ['id' => 1, 'kind' => 'team', 'parent_id' => null], 'access' => [], 'state' => 'ok'];
eq(fabo_module_org($staff), 0, 'our own staff: ours');
eq(fabo_module_org(viewer(AT)), A, 'A\'s team member: A');

cleanup();
ob_end_clean();
echo $pass . ' passed, ' . count($fail) . " failed\n";
foreach ($fail as $f) {
    echo "  - $f\n";
}
exit($fail ? 1 : 0);

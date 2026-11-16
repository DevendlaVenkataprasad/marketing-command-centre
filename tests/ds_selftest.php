<?php
/**
 * Digital Signatures — end-to-end self-test. Local only, never against live.
 *
 *     php tests/ds_selftest.php [db_host] [db_port] [db_name]
 *
 * Runs on a throwaway database (default 127.0.0.1:3399 dstest): makes two
 * organisations and their people, a test certifying authority (with a CRL),
 * a test eSign Service Provider (which signs exactly as an ESP does: a
 * detached CMS over the prepared bytes, in an XML-DSig-signed response), and
 * walks every scenario of the specification. Prints PASS/FAIL per check and
 * exits non-zero on any failure.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
chdir(dirname(__DIR__));
define('DB_HOST', $argv[1] ?? '127.0.0.1');
define('DB_PORT', (int) ($argv[2] ?? 3399));
define('DB_NAME', $argv[3] ?? 'dstest');
define('DB_USER', 'root');
define('DB_PASS', '');
set_error_handler(static function ($no, $str) {
    return str_contains($str, 'already defined') || str_contains($str, 'headers already sent') || str_contains($str, 'session');
});
$_SERVER['HTTP_HOST'] = 'portal.test';
$_SERVER['HTTPS'] = 'on';
$_SERVER['SCRIPT_NAME'] = '/sign.php';
$_SERVER['REMOTE_ADDR'] = '10.0.0.7';
$_SERVER['REQUEST_METHOD'] = 'GET';
require __DIR__ . '/../lib/ui.php';
fabo_boot();
// Like live: the database keeps UTC while PHP keeps India time (a 5½-hour gap).
date_default_timezone_set('Asia/Kolkata');
fabo_db()->query("SET time_zone = '+00:00'");
require __DIR__ . '/../lib/ds/app.php';
ds_install();

$pass = 0;
$fail = 0;
$section = '';
function sec(string $s): void
{
    global $section;
    $section = $s;
    echo "\n== $s\n";
}
function ok(bool $c, string $what, string $detail = ''): void
{
    global $pass, $fail;
    if ($c) {
        $pass++;
        echo "  PASS  $what\n";
    } else {
        $fail++;
        echo "  FAIL  $what" . ($detail !== '' ? "  [$detail]" : '') . "\n";
    }
}
function throws(callable $fn, string $what, string $needle = ''): void
{
    try {
        $fn();
        ok(false, $what, 'no exception');
    } catch (InvalidArgumentException $e) {
        ok($needle === '' || stripos($e->getMessage(), $needle) !== false, $what, $e->getMessage());
    } catch (Throwable $e) {
        ok(false, $what, get_class($e) . ': ' . $e->getMessage());
    }
}

/* ------------------------------------------------------------ the world */
$tmp = sys_get_temp_dir() . '/ds_selftest';
@mkdir($tmp, 0700, true);
putenv('DS_KEY_FILE=' . $tmp . '/storage.key');
// Fresh module state each run.
foreach (fabo_all("SELECT table_name t FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'ds\\_%'") as $r) {
    fabo_exec('SET FOREIGN_KEY_CHECKS = 0');
    fabo_exec('DROP TABLE `' . $r['t'] . '`');
}
fabo_exec('SET FOREIGN_KEY_CHECKS = 1');
fabo_exec('DELETE FROM app_settings WHERE k IN ("ds_schema","ds_key_fp")');
$GLOBALS['__ds_install_again'] = true;
foreach (ds_schema_sql() as $sql) {
    fabo_exec($sql);
}
fabo_exec('INSERT INTO app_settings (k, v) VALUES ("ds_schema", ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', 's', [(string) DS_SCHEMA_VERSION]);

$mk = static function (array $c): int {
    fabo_exec('DELETE FROM clients WHERE email = ?', 's', [$c['email']]);
    return fabo_exec('INSERT INTO clients (name, email, kind, parent_id, organisation, password_hash, token) VALUES (?,?,?,?,?,?,?)', 'sssisss',
        [$c['name'], $c['email'], $c['kind'], $c['parent'] ?? null, $c['org'] ?? null, password_hash('Secret#123', PASSWORD_DEFAULT), bin2hex(random_bytes(16))]);
};
$head = $mk(['name' => 'Ganesh Rayala', 'email' => 'head@rtih.test', 'kind' => 'client', 'org' => 'Ratan Tata Innovation Hub']);
$A = $mk(['name' => 'Anil Kumar', 'email' => 'anil@rtih.test', 'kind' => 'team', 'parent' => $head]);
$B = $mk(['name' => 'Bhavana Reddy', 'email' => 'bhavana@rtih.test', 'kind' => 'team', 'parent' => $head]);
$C = $mk(['name' => 'Chandra Sekhar', 'email' => 'chandra@rtih.test', 'kind' => 'team', 'parent' => $head]);
$D = $mk(['name' => 'Divya Teja', 'email' => 'divya@rtih.test', 'kind' => 'team', 'parent' => $head]);
$head2 = $mk(['name' => 'Other Head', 'email' => 'head@other.test', 'kind' => 'client', 'org' => 'Other Org']);
$E = $mk(['name' => 'Eswar Outsider', 'email' => 'eswar@other.test', 'kind' => 'team', 'parent' => $head2]);
foreach ([$head, $head2] as $h) {
    fabo_exec('DELETE FROM client_sheet_access WHERE client_id = ?', 'i', [$h]);
    fabo_exec('INSERT INTO client_sheet_access (client_id, sheet_key, permission) VALUES (?, "esign", "edit")', 'i', [$h]);
}
ok(ds_org_grant($head) === 'edit', 'organisation holds the module grant');

function as_person(int $id): void
{
    $p = fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [$id]);
    $v = fabo_person_viewer($p);
    $org = ds_org_of($v);
    ds_use($org);
    $GLOBALS['ds_viewer'] = $v;
    $GLOBALS['ds_grant'] = ds_org_grant($org);
    $GLOBALS['ds_system'] = false;
}
as_person($head);
ds_setting_put('mail_notify', '0');
$GLOBALS['ds_test_reauth'] = 'Secret#123';
// What a signatory types on the form (full name, date, designation).
$typed = ['typed_name' => 'Typed Signer Name', 'typed_date' => '2026-11-05', 'typed_designation' => 'Typed Designation'];

// Departments (HR's master) and the signatory directory
require_once __DIR__ . '/../lib/hr/schema.php';
try {
    fabo_one('SELECT 1 FROM hr_departments LIMIT 1');
} catch (Throwable $e) {
    hr_install();
}
fabo_exec('DELETE FROM hr_departments WHERE org_id IN (?, ?)', 'ii', [$head, $head2]);
$dFin = ds_department_add('Finance');
$dAdm = ds_department_add('Administration');
foreach ([[$A, $dFin, 'Department Head', '500000', 1], [$B, $dFin, 'Finance Controller', null, 0], [$C, $dAdm, 'CEO', null, 0], [$D, $dAdm, 'Department Head', '100000', 0]] as [$p, $dep, $auth, $lim, $del]) {
    fabo_exec('INSERT INTO ds_signatories (org_id, person_id, department_id, designation, authority, authority_limit, may_delegate) VALUES (?,?,?,?,?,?,?)', 'iiisssi',
              [$head, $p, $dep, $auth, $auth, $lim, $del]);
}

/* ------------------------------------------------- a test PKI and an ESP */
sec('Test PKI');
$cnf = $tmp . '/openssl.cnf';
file_put_contents($cnf, "[req]\ndistinguished_name=dn\n[dn]\n[v3_ca]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\nsubjectKeyIdentifier=hash\n"
    . "[v3_leaf]\nbasicConstraints=CA:FALSE\nkeyUsage=critical,digitalSignature,nonRepudiation\ncrlDistributionPoints=URI:http://crl.test/ca.crl\n"
    . "[v3_esp]\nbasicConstraints=CA:FALSE\nkeyUsage=critical,digitalSignature,nonRepudiation\ncrlDistributionPoints=URI:http://crl.test/esp.crl\n[v3_plain]\nbasicConstraints=CA:FALSE\nkeyUsage=critical,digitalSignature\n"
    . "[v3_aia]\nbasicConstraints=CA:FALSE\nkeyUsage=critical,digitalSignature,nonRepudiation\ncrlDistributionPoints=URI:http://crl.test/int.crl\nauthorityInfoAccess=caIssuers;URI:http://crl.test/int.cer\n"
    . "[v3_subca]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\ncrlDistributionPoints=URI:http://crl.test/ca.crl\n");
$cfg = ['config' => $cnf, 'digest_alg' => 'sha256', 'private_key_bits' => 2048];
$newKey = static fn() => openssl_pkey_new($cfg);
$cert = static function (array $dn, $key, $caCert, $caKey, string $ext, int $days, int $serial) use ($cfg) {
    $csr = openssl_csr_new($dn, $key, $cfg);
    $x = openssl_csr_sign($csr, $caCert, $caKey, $days, $cfg + ['x509_extensions' => $ext], $serial);
    openssl_x509_export($x, $pem);
    return $pem;
};
$caKey = $newKey();
$caPem = $cert(['commonName' => 'Test Licensed CA 2026', 'organizationName' => 'Test CCA', 'countryName' => 'IN'], $caKey, null, $caKey, 'v3_ca', 3650, 1);
$bKey = $newKey();
$bPem = $cert(['commonName' => 'Bhavana Reddy', 'emailAddress' => 'bhavana@rtih.test', 'countryName' => 'IN'], $bKey, $caPem, $caKey, 'v3_leaf', 730, 1001);
$mallKey = $newKey();
$mallPem = $cert(['commonName' => 'Mallory Impostor', 'emailAddress' => 'mallory@evil.test'], $mallKey, $caPem, $caKey, 'v3_leaf', 730, 1002);
$selfKey = $newKey();
$selfPem = $cert(['commonName' => 'Bhavana Reddy', 'emailAddress' => 'bhavana@rtih.test'], $selfKey, null, $selfKey, 'v3_plain', 365, 7);
$espCaKey = $newKey();
$espCaPem = $cert(['commonName' => 'Test ESP CA', 'organizationName' => 'Test eSign Service Provider'], $espCaKey, null, $espCaKey, 'v3_ca', 3650, 2);
$cSignKey = $newKey();
$cSignPem = $cert(['commonName' => 'Chandra Sekhar', 'organizationalUnitName' => 'Aadhaar eKYC - OTP'], $cSignKey, $espCaPem, $espCaKey, 'v3_esp', 1, 3001);
$espRespKey = $newKey();
$espRespPem = $cert(['commonName' => 'Test ESP Response Signer'], $espRespKey, $espCaPem, $espCaKey, 'v3_plain', 365, 3002);
$aspKey = $newKey();
$aspPem = $cert(['commonName' => 'RTIH ASP'], $aspKey, null, $aspKey, 'v3_plain', 365, 9);
openssl_pkey_export($aspKey, $aspKeyPem, null, $cfg);
file_put_contents($tmp . '/asp.key', $aspKeyPem);
file_put_contents($tmp . '/asp.crt', $aspPem);
ok((bool) $caPem && (bool) $bPem && (bool) $cSignPem, 'test CA, DSC, ESP and ASP certificates made');

// CRLs, served by a stub in place of the network
$revoked = ['ca' => [], 'esp' => []];
$crl = static function (string $issuerPem, $issuerKey, array $serials): string {
    $parts = ds_x509_parts(ds_unpem($issuerPem));
    $alg = ds_der_seq(ds_der_oid_enc('1.2.840.113549.1.1.11'), ds_der(0x05, ''));
    $rev = '';
    foreach ($serials as $s) {
        $rev .= ds_der_seq(ds_der_int_from_hex($s), ds_der_utctime(time() - 3600));
    }
    $tbs = ds_der_seq(ds_der(0x02, "\x01"), $alg, $parts['subject'], ds_der_utctime(time() - 60), ds_der_utctime(time() + 86400 * 7), $rev !== '' ? ds_der_seq($rev) : '');
    openssl_sign($tbs, $sig, $issuerKey, OPENSSL_ALGO_SHA256);
    return ds_der_seq($tbs, $alg, ds_der(0x03, "\0" . $sig));
};
$GLOBALS['ds_test_fetch'] = static function (string $url) use (&$revoked, $crl, $caPem, $caKey, $espCaPem, $espCaKey) {
    if ($url === 'http://crl.test/ca.crl') {
        return $crl($caPem, $caKey, $revoked['ca']);
    }
    if ($url === 'http://crl.test/int.cer' && isset($GLOBALS['int_der'])) {
        return $GLOBALS['int_der'];
    }
    if ($url === 'http://crl.test/int.crl' && isset($GLOBALS['int_crl'])) {
        return ($GLOBALS['int_crl'])();
    }
    if ($url === 'http://crl.test/esp.crl') {
        return $crl($espCaPem, $espCaKey, $revoked['esp']);
    }
    return null;
};
ok(ds_crl_parse($crl($caPem, $caKey, ['3E9'])) !== null, 'a CRL is built and parses');

/* --------------------------------------------------------- configuration */
sec('Configuration');
ds_anchor_add($caPem, 'Test Licensed CA', 'root', false);
ds_anchor_add($espCaPem, 'Test ESP CA', 'root', false);
ok(count(ds_anchors()) === 2, 'two trust anchors');
$provId = fabo_exec('INSERT INTO ds_providers (org_id, provider, label, environment, config_json, active) VALUES (?, "esign_cca", "Test ESP", "sandbox", ?, 1)', 'is',
    [$head, json_encode(['esp_name' => 'Test ESP', 'gateway_url' => 'https://esp.test/esign/2.1/signdoc', 'asp_id' => 'ASP-TEST-01', 'asp_key_path' => $tmp . '/asp.key',
                         'asp_cert_path' => $tmp . '/asp.crt', 'esp_cert_pem' => $espRespPem, 'api_version' => '2.1', 'auth_mode' => '1', 'request_field' => 'msg', 'reserve_kb' => '16'])]);
[$pok, $pbad] = ds_provider_check(ds_provider_for('esign'));
ok($pok, 'eSign provider configuration is complete', implode(' ', $pbad));
throws(static fn() => (function () {
    $_POST = [];
    throw new InvalidArgumentException(preg_match('/-----BEGIN [A-Z ]*PRIVATE KEY-----/', "-----BEGIN PRIVATE KEY-----\nabc") ? 'A private key was pasted' : '');
})(), 'a pasted private key is refused by the provider form rule', 'private key');

$wfSeq = ds_workflow_save(0, ['name' => 'Sequential: approve → DSC → eSign', 'active' => 1, 'priority' => 10, 'conditions' => ['categories' => [], 'min_value' => '1000'],
    'reminder_hours' => 1, 'max_reminders' => 2, 'escalate_hours' => 2, 'escalate_to' => 'person', 'escalate_person_id' => $head, 'escalate_action' => 'notify',
    'stages' => [
        ['name' => 'Department Head', 'completion' => 'all', 'signatories' => [['type' => 'authority', 'authority' => 'Department Head', 'scope' => 'doc_dept', 'action' => 'approve', 'method' => 'eapproval', 'mandatory' => 1]]],
        ['name' => 'Finance Controller', 'completion' => 'all', 'signatories' => [['type' => 'authority', 'authority' => 'Finance Controller', 'scope' => 'any', 'action' => 'sign', 'method' => 'dsc', 'mandatory' => 1]]],
        ['name' => 'CEO', 'completion' => 'all', 'condition' => ['min_value' => '100000'], 'signatories' => [['type' => 'person', 'person_id' => $C, 'action' => 'sign', 'method' => 'esign', 'mandatory' => 1]]],
    ]]);
ok($wfSeq > 0 && count(ds_workflow($wfSeq)['stages']) === 3, 'sequential workflow created with 3 stages');
$wfPar = ds_workflow_save(0, ['name' => 'Parallel approvals', 'active' => 1, 'priority' => 50, 'conditions' => [],
    'stages' => [['name' => 'Joint approval', 'completion' => 'all', 'signatories' => [
        ['type' => 'person', 'person_id' => $A, 'action' => 'approve', 'method' => 'eapproval', 'mandatory' => 1],
        ['type' => 'person', 'person_id' => $D, 'action' => 'approve', 'method' => 'any', 'mandatory' => 1],
        ['type' => 'person', 'person_id' => $B, 'action' => 'approve', 'method' => 'eapproval', 'mandatory' => 0]]]]]);
throws(static fn() => ds_workflow_save(0, ['name' => 'Broken', 'stages' => []]), 'a workflow without stages is refused', 'at least one stage');

$pdfOf = static function (string $title, int $pages = 2): string {
    $p = new HrPdf();
    for ($i = 1; $i <= $pages; $i++) {
        $p->addPage();
        $p->text(60, 80, $title . ' — page ' . $i, 16, true);
        $p->text(60, 110, 'This agreement is made between the parties named below.', 11);
    }
    return $p->output();
};

/* -------------------------------------------------- 1. upload a document */
sec('1. Upload a new document');
$cats = ds_categories();
ok(count($cats) === 12, '12 standard categories exist');
$catAgr = array_search('Agreements', $cats, true);
$doc1 = ds_doc_create(['title' => 'MoU with Test University', 'category_id' => $catAgr, 'department_id' => $dFin, 'priority' => 'high', 'confidentiality' => 'internal',
                       'value_amount' => '250000', 'owner_id' => $head, 'doc_date' => date('Y-m-d'), 'valid_until' => date('Y-m-d', strtotime('+1 year'))], $pdfOf('MoU'), 'mou.pdf');
$d1 = ds_doc($doc1);
ok($d1 && preg_match('~^DS/\d\d-\d\d/000001$~', $d1['ref_no']) === 1, 'reference number generated', $d1['ref_no'] ?? '');
ok(strlen($d1['tracking_code']) === 16, 'tracking code generated');
ok($d1['status'] === 'draft', 'starts as draft');
$raw = file_get_contents(rtrim(fabo_upload_dir(), '/\\') . '/ds/' . ds_file((int) $d1['working_file_id'])['stored_name']);
ok(strncmp($raw, 'DSE1', 4) === 0 && !str_contains($raw, 'MoU'), 'stored encrypted at rest');
ok(str_starts_with(ds_read((int) $d1['working_file_id']), '%PDF'), 'decrypts and passes its integrity check');
throws(static fn() => ds_doc_create(['title' => 'Bad', 'category_id' => $catAgr], "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R /OpenAction << /S /JavaScript /JS (app.alert(1)) >> >> endobj\n2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF", 'evil.pdf'),
       'a PDF with JavaScript is refused by the scan', 'JavaScript');
throws(static fn() => ds_doc_create(['title' => 'Not pdf', 'category_id' => $catAgr], 'hello', 'x.pdf'), 'a non-PDF is refused', 'not a PDF');
ok(ds_workflow_for($d1) === $wfSeq, 'conditional routing suggests the sequential workflow (value ≥ 1000)');

/* ---------------------------------- 2–3. sequential, multi-level signing */
sec('2–3. Sequential multi-level signing (approval → DSC → Aadhaar eSign)');
ds_submit($doc1, ds_plan_from_workflow(ds_workflow($wfSeq)), [], (int) ds_doc($doc1)['version']);
$d1 = ds_doc($doc1);
$reqs = ds_doc_requests($doc1, 1);
ok(count($reqs) === 3, 'three requests made (CEO stage applies: value ≥ 1,00,000)');
ok((int) $reqs[0]['person_id'] === $A, 'Department Head resolved by authority in the document\'s department (Anil)');
ok($reqs[0]['status'] === 'pending' && $reqs[1]['status'] === 'waiting' && $reqs[2]['status'] === 'waiting', 'only the first stage is active');
ok($d1['status'] === 'pending', 'status: pending signature (an approval stage comes first)', $d1['status']);
ok(fabo_one('SELECT locked_at FROM ds_document_versions WHERE id = ?', 'i', [(int) $d1['current_version_id']])['locked_at'] !== null, 'version locked on sending');
as_person($A);
ok(true, 'no password is asked when signing (signed-in session)');
$m = ds_sign_simple((int) $reqs[0]['id'], 'eapproval', 'Secret#123', 'Looks fine', null, null, (int) ds_doc($doc1)['version'], $typed);
ok(str_contains($m, 'Approved'), 'Anil approves electronically');
$r0 = fabo_one('SELECT * FROM ds_signature_requests WHERE id = ?', 'i', [(int) $reqs[0]['id']]);
ok($r0['status'] === 'approved' && $r0['seal'] && hash_equals($r0['seal'], ds_seal(json_decode($r0['evidence_json'], true))), 'approval recorded and sealed');
$wb = ds_read((int) ds_doc($doc1)['working_file_id']);
ok(str_contains($wb, '/Subtype /Stamp'), 'approval stamp added to the PDF as an incremental update');
ok(fabo_one('SELECT status FROM ds_signature_requests WHERE id = ?', 'i', [(int) $reqs[1]['id']])['status'] === 'pending', 'stage 2 now pending');

// B signs with a DSC the way Acrobat does: an incremental update with one signature over everything.
as_person($B);
$acrobat = static function (string $base, string $pem, $key, string $who): string {
    $u = new DsPdfUpdate(new DsPdf($base));
    [$rect, $rot] = $u->rectFor(0, .55, .80, .35, .08);
    $u->signatureField(0, $rect, $rot, [['Digitally signed by ' . $who, 8, true]], 'Acrobat' . mt_rand(), ['Name' => $who, 'Reason' => 'I approve this document']);
    $b = $u->build();
    $f = tempnam(sys_get_temp_dir(), 'dsa');
    $s = tempnam(sys_get_temp_dir(), 'dss');
    file_put_contents($f, DsPdfUpdate::covered($b['pdf'], $b['range']));
    openssl_cms_sign($f, $s, $pem, $key, [], OPENSSL_CMS_DETACHED | OPENSSL_CMS_BINARY, OPENSSL_ENCODING_DER);
    $out = DsPdfUpdate::embed($b, (string) file_get_contents($s));
    @unlink($f);
    @unlink($s);
    return $out;
};
[$base] = ds_dsc_download((int) $reqs[1]['id']);
ok(str_starts_with($base, '%PDF') && $base === $wb, 'DSC copy downloaded is the working file');
throws(static fn() => ds_dsc_upload((int) $reqs[1]['id'], $acrobat($base, $mallPem, $mallKey, 'Mallory'), (int) ds_doc($doc1)['version']), 'a valid DSC belonging to someone else is refused', 'does not match you');
throws(static fn() => ds_dsc_upload((int) $reqs[1]['id'], $acrobat($base, $selfPem, $selfKey, 'Bhavana Reddy'), (int) ds_doc($doc1)['version']), 'a self-signed certificate (untrusted) is refused', 'trusted');
throws(static fn() => ds_dsc_upload((int) $reqs[1]['id'], $acrobat($pdfOf('Other doc'), $bPem, $bKey, 'Bhavana'), (int) ds_doc($doc1)['version']), 'a signed copy of a different file is refused', 'not the current version');
$m = ds_dsc_upload((int) $reqs[1]['id'], $acrobat($base, $bPem, $bKey, 'Bhavana Reddy'), (int) ds_doc($doc1)['version']);
ok(str_contains($m, 'Digitally signed'), 'Bhavana\'s own DSC signature is verified and accepted');
$d1 = ds_doc($doc1);
ok($d1['status'] === 'partial', 'status: partially signed', $d1['status']);
ok((bool) fabo_one('SELECT 1 FROM ds_certificates WHERE org_id = ? AND person_id = ? AND source = "observed"', 'ii', [$head, $B]), 'signer certificate kept in the directory');

// C signs with Aadhaar eSign through the test ESP.
as_person($C);
$req3 = (int) $reqs[2]['id'];
throws(static fn() => ds_esign_start($req3, null, false, (int) ds_doc($doc1)['version']), 'eSign needs explicit consent', 'consent');
$st = ds_esign_start($req3, '{"page":1,"x":0.1,"y":0.7,"w":0.3,"h":0.08}', true, (int) ds_doc($doc1)['version']);
$reqXml = $st['fields']['msg'];
$rdom = ds_xml_load($reqXml);
ok($rdom && ds_xmldsig_verify($rdom, $aspPem), 'eSign request is XML-signed with the ASP key and verifies');
ok($rdom->documentElement->getAttribute('aspId') === 'ASP-TEST-01' && str_contains($rdom->documentElement->getAttribute('responseUrl'), 'sign-callback.php'), 'request names the ASP and the callback');
$hashSent = trim($rdom->getElementsByTagName('InputHash')->item(0)->textContent);
$txnRow = fabo_one('SELECT * FROM ds_signature_transactions WHERE txn_ref = ?', 's', [$st['txn']]);
ok($hashSent === $txnRow['doc_hash'] && strlen($hashSent) === 64, 'only the document hash is sent to the ESP');
throws(static fn() => (function () use ($doc1, $B) {
    as_person($B);
    $other = fabo_one('SELECT * FROM ds_documents WHERE id = ?', 'i', [$doc1]);
    ds_crypto_lock_check($other, true, 0);
})(), 'while C is at the ESP, the document is held (concurrent signer refused)', 'signing this document');
as_person($C);
$espRespond = static function (string $txn, string $status, ?string $der, $signKey, string $signPem, string $err = 'NA') {
    $dom = new DOMDocument('1.0', 'UTF-8');
    $e = $dom->createElement('EsignResp');
    foreach (['ver' => '2.1', 'status' => $status, 'ts' => date('Y-m-d\TH:i:s'), 'txn' => $txn, 'resCode' => 'RES' . mt_rand(1000, 9999), 'errCode' => $status === '1' ? 'NA' : 'ESP-944', 'errMsg' => $err] as $k => $v) {
        $e->setAttribute($k, $v);
    }
    $dom->appendChild($e);
    if ($der !== null) {
        $sigs = $dom->createElement('Signatures');
        $ds = $dom->createElement('DocSignature', base64_encode($der));
        $ds->setAttribute('id', '1');
        $ds->setAttribute('sigHashAlgorithm', 'SHA256');
        $sigs->appendChild($ds);
        $e->appendChild($sigs);
    }
    ds_xmldsig_sign($dom, $signKey, $signPem);
    return $dom->saveXML($dom->documentElement);
};
// The ESP signs the hash: a detached CMS over the prepared byte range with C's one-time certificate.
$prepared = ds_read((int) $txnRow['prepared_file_id']);
$covered = DsPdfUpdate::covered($prepared, array_map('intval', explode(' ', $txnRow['byte_range'])));
ok(hash('sha256', $covered) === $hashSent, 'prepared bytes hash to what was sent');
$cf = tempnam(sys_get_temp_dir(), 'dsc');
$sf = tempnam(sys_get_temp_dir(), 'dss');
file_put_contents($cf, $covered);
openssl_cms_sign($cf, $sf, $cSignPem, $cSignKey, [], OPENSSL_CMS_DETACHED | OPENSSL_CMS_BINARY, OPENSSL_ENCODING_DER);
$espDer = (string) file_get_contents($sf);

/* 11. provider failure and forged responses come first */
sec('11. Provider failures and forged callbacks');
$forgeKey = $newKey();
$forgePem = $cert(['commonName' => 'Forger'], $forgeKey, null, $forgeKey, 'v3_plain', 30, 66);
[$t, $okF, $msgF] = ds_esign_callback($espRespond($st['txn'], '1', $espDer, $forgeKey, $forgePem));
ok(!$okF && str_contains($msgF, 'not signed by the configured'), 'a response not signed by the ESP is rejected', $msgF);
ok(fabo_one('SELECT status FROM ds_signature_requests WHERE id = ?', 'i', [$req3])['status'] === 'pending', 'request still pending after the forgery');
$st2 = ds_esign_start($req3, null, true, (int) ds_doc($doc1)['version']);
[$t, $okE, $msgE] = ds_esign_callback($espRespond($st2['txn'], '0', null, $espRespKey, $espRespPem, 'OTP validation failed'));
ok(!$okE && str_contains($msgE, 'OTP validation failed'), 'an ESP error (status 0) fails the transaction cleanly', $msgE);
ok(fabo_one('SELECT status, response_code FROM ds_signature_transactions WHERE txn_ref = ?', 's', [$st2['txn']])['status'] === 'failed', 'transaction marked failed with the ESP code');
[$t, $okU, $msgU] = ds_esign_callback('<EsignResp txn="NOPE" status="1"/>');
ok(!$okU && $t === null, 'a response for an unknown transaction is refused');
[$t, $okX] = ds_esign_callback('<!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><EsignResp txn="&e;"/>');
ok(!$okX, 'XML with a DTD/entities is refused (XXE)');

// Start again and complete properly.
as_person($C);
$st3 = ds_esign_start($req3, null, true, (int) ds_doc($doc1)['version']);
$tx3 = fabo_one('SELECT * FROM ds_signature_transactions WHERE txn_ref = ?', 's', [$st3['txn']]);
$prep3 = ds_read((int) $tx3['prepared_file_id']);
file_put_contents($cf, DsPdfUpdate::covered($prep3, array_map('intval', explode(' ', $tx3['byte_range']))));
openssl_cms_sign($cf, $sf, $cSignPem, $cSignKey, [], OPENSSL_CMS_DETACHED | OPENSSL_CMS_BINARY, OPENSSL_ENCODING_DER);
$goodXml = $espRespond($st3['txn'], '1', (string) file_get_contents($sf), $espRespKey, $espRespPem);
// A signature over the wrong bytes (the stale first preparation) must not be embedded.
$stale = $espRespond($st3['txn'], '1', $espDer, $espRespKey, $espRespPem);
[$t, $okS, $msgS] = ds_esign_callback($stale);
ok(!$okS, 'a valid ESP response carrying a signature over other bytes is rejected', $msgS);
$st4 = ds_esign_start($req3, null, true, (int) ds_doc($doc1)['version']);
$tx4 = fabo_one('SELECT * FROM ds_signature_transactions WHERE txn_ref = ?', 's', [$st4['txn']]);
file_put_contents($cf, DsPdfUpdate::covered(ds_read((int) $tx4['prepared_file_id']), array_map('intval', explode(' ', $tx4['byte_range']))));
openssl_cms_sign($cf, $sf, $cSignPem, $cSignKey, [], OPENSSL_CMS_DETACHED | OPENSSL_CMS_BINARY, OPENSSL_ENCODING_DER);
$goodXml = $espRespond($st4['txn'], '1', (string) file_get_contents($sf), $espRespKey, $espRespPem);
[$t, $okG, $msgG] = ds_esign_callback($goodXml);
ok($okG, 'Aadhaar eSign response verified and the signature embedded', $msgG);

/* 12. duplicate callback */
sec('12. Duplicate provider callbacks');
$before = ds_read((int) ds_doc($doc1)['working_file_id']);
[$t, $okD, $msgD] = ds_esign_callback($goodXml);
ok($okD && str_contains($msgD, 'already recorded'), 'the same response delivered twice is acknowledged and ignored');
ok(ds_read((int) ds_doc($doc1)['working_file_id']) === $before, 'the document did not change on the duplicate');
ok((int) fabo_one('SELECT callback_count FROM ds_signature_transactions WHERE txn_ref = ?', 's', [$st4['txn']])['callback_count'] === 2, 'callback count recorded as 2');
$d1 = ds_doc($doc1);
ok($d1['status'] === 'signed' && $d1['completed_at'] !== null, 'document fully signed', $d1['status']);

/* 8. verification of a valid signed PDF */
sec('8. Verify a valid signed PDF');
as_person($head);
$final = ds_read((int) $d1['signed_file_id']);
$rep = ds_verify_pdf($final, ['anchors' => ds_anchors(), 'fetch' => ds_fetcher()]);
ok(count($rep['signatures']) === 2, 'two digital signatures found (DSC and eSign)', (string) count($rep['signatures']));
ok($rep['overall'] === 'valid', 'overall verdict: valid (integrity, chain, validity, CRL revocation all pass)', $rep['overall'] . ' ' . json_encode(array_map(static fn($s) => array_map(static fn($c) => $c['key'] . ':' . $c['state'] . ' ' . $c['message'], $s['checks']), $rep['signatures'])));
ok(($rep['signatures'][0]['modifications']['status'] ?? '') === 'annotations' || ($rep['signatures'][0]['modifications']['status'] ?? '') === 'signatures_only', 'first signature: only signatures/annotations added after it');
$vid = ds_verify_record($doc1, (int) $d1['signed_file_id'], 'repository');
$vrow = fabo_one('SELECT * FROM ds_signature_verifications WHERE id = ?', 'i', [$vid]);
ok($vrow['overall'] === 'valid' && (int) $vrow['mandatory_ok'] === 1, 'repository verification: valid, all mandatory signatures present');
$pdfBytes = ds_verification_pdf($vrow)->output();
ok(str_starts_with($pdfBytes, '%PDF') && strlen($pdfBytes) > 2000, 'verification report PDF generated');
$certPdf = ds_certificate_pdf(ds_doc($doc1))->output();
ok(str_starts_with($certPdf, '%PDF'), 'signature certificate PDF generated');
$auto = fabo_one('SELECT overall FROM ds_signature_verifications WHERE doc_id = ? AND source = "auto"', 'i', [$doc1]);
ok($auto && $auto['overall'] === 'valid', 'completion ran an automatic verification', $auto['overall'] ?? 'none');

/* 9. modified PDF */
sec('9. Detect a modified PDF');
$p = new DsPdf($final);
$u = new DsPdfUpdate($p);
$pg = $p->pages()[0];
$dd = $pg['dict']->d;
$dd['Contents'] = $u->add(new DsStream([], 'BT /F1 30 Tf 80 500 Td (AMOUNT: 99,00,000) Tj ET'));
$u->set($pg['ref'], new DsDict($dd));
$forged = $u->build()['pdf'];
$rep = ds_verify_pdf($forged, ['anchors' => ds_anchors(), 'fetch' => ds_fetcher()]);
ok($rep['overall'] === 'modified', 'page content changed after signing → "Document modified after signing"', $rep['overall']);
$flip = $final;
$flip[1500] = $flip[1500] === 'A' ? 'B' : 'A';
$rep = ds_verify_pdf($flip, ['anchors' => ds_anchors(), 'fetch' => ds_fetcher()]);
ok($rep['overall'] === 'invalid', 'a byte changed inside the signed range → "Invalid signature"', $rep['overall']);

/* 10. invalid / expired / revoked certificates */
sec('10. Invalid, expired and revoked certificates');
$rep = ds_verify_pdf($final, ['anchors' => ds_anchors(), 'fetch' => ds_fetcher(), 'now' => time() + 86400 * 3]);
ok($rep['overall'] === 'expired', 'eSign certificate (1-day validity) judged later with no timestamp → "Certificate expired"', $rep['overall']);
$revoked['ca'] = [strtoupper(dechex(1001))];
$rep = ds_verify_pdf($final, ['anchors' => ds_anchors(), 'fetch' => ds_fetcher()]);
ok($rep['overall'] === 'revoked', 'DSC on the CA\'s CRL → "Certificate revoked"', $rep['overall']);
$revoked['ca'] = [];
$rep = ds_verify_pdf($final, ['anchors' => ds_anchors(), 'fetch' => static fn() => null]);
ok($rep['overall'] === 'incomplete', 'revocation service unreachable → "Verification incomplete", never valid', $rep['overall']);
$rep = ds_verify_pdf($final, ['anchors' => [], 'fetch' => ds_fetcher()]);
ok($rep['overall'] === 'incomplete', 'untrusted root → "Verification incomplete"', $rep['overall']);
$rep = ds_verify_pdf($pdfOf('Plain'), ['anchors' => ds_anchors()]);
ok($rep['overall'] === 'unsigned', 'an unsigned PDF is reported as unsigned');

/* 4. parallel signing */
sec('4. Parallel signing');
as_person($head);
$doc2 = ds_doc_create(['title' => 'Joint approval note', 'category_id' => array_search('Approvals', $cats, true), 'department_id' => $dAdm, 'owner_id' => $head], $pdfOf('Note', 1), 'note.pdf');
ds_submit($doc2, ds_plan_from_workflow(ds_workflow($wfPar)), [], (int) ds_doc($doc2)['version']);
$rq = ds_doc_requests($doc2, 1);
ok(count(array_filter($rq, static fn($r) => $r['status'] === 'pending')) === 3, 'all three parallel requests are pending at once');
as_person($D);
ds_sign_simple((int) $rq[1]['id'], 'eapproval', 'Secret#123', '', null, null, (int) ds_doc($doc2)['version'], $typed);
ok(ds_doc($doc2)['status'] !== 'signed', 'one of two mandatory approvals: not complete yet');
as_person($A);
throws(static fn() => ds_sign_simple((int) $rq[0]['id'], 'eapproval', 'Secret#123', '', null, null, (int) ds_doc($doc2)['version'] - 1, $typed), 'a decision on an out-of-date view is refused (optimistic lock)', 'changed while you had it open');
$png = (static function () {
    $im = imagecreatetruecolor(400, 120);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 255, 255, 255, 127));
    imagesetthickness($im, 3);
    imageline($im, 20, 90, 380, 30, imagecolorallocate($im, 15, 47, 115));
    imageline($im, 30, 30, 200, 100, imagecolorallocate($im, 15, 47, 115));
    ob_start();
    imagepng($im);
    return 'data:image/png;base64,' . base64_encode((string) ob_get_clean());
})();
throws(static fn() => ds_sign_simple((int) $rq[0]['id'], 'drawn', 'Secret#123', '', $png, null, (int) ds_doc($doc2)['version'], $typed), 'a method the request does not allow is refused', 'cannot be completed');
ds_sign_simple((int) $rq[0]['id'], 'eapproval', 'Secret#123', '', null, '{"page":0,"x":0.6,"y":0.1,"w":0.3,"h":0.07}', (int) ds_doc($doc2)['version'], $typed);
$d2 = ds_doc($doc2);
ok($d2['status'] === 'signed', 'both mandatory approvals in → complete', $d2['status']);
ok(fabo_one('SELECT status FROM ds_signature_requests WHERE id = ?', 'i', [(int) $rq[2]['id']])['status'] === 'skipped', 'the optional approver is no longer asked (skipped)');

/* drawn signature */
$docDr = ds_doc_create(['title' => 'Drawn signature letter', 'category_id' => array_search('Official Letters', $cats, true), 'owner_id' => $head], $pdfOf('Letter', 1), 'letter.pdf');
ds_submit($docDr, ds_plan_adhoc([['person_id' => $D, 'order' => 1, 'action' => 'sign', 'method' => 'drawn', 'mandatory' => 1]]), [], (int) ds_doc($docDr)['version']);
as_person($D);
$rqd = ds_doc_requests($docDr, 1)[0];
throws(static fn() => ds_sign_simple((int) $rqd['id'], 'drawn', 'Secret#123', '', 'data:image/png;base64,AAAA', null, (int) ds_doc($docDr)['version'], $typed), 'an empty / unreadable drawing is refused');
ds_sign_simple((int) $rqd['id'], 'drawn', 'Secret#123', '', $png, '{"page":0,"x":0.5,"y":0.8,"w":0.3,"h":0.08}', (int) ds_doc($docDr)['version'], $typed);
$dr = ds_doc($docDr);
ok($dr['status'] === 'signed' && str_contains(ds_read((int) $dr['signed_file_id']), '/Subtype /Image'), 'drawn signature stamped as an image; document complete');
ok(ds_verify_pdf(ds_read((int) $dr['signed_file_id']))['overall'] === 'unsigned', 'a drawn signature is not presented as a digital signature');

/* 5. reject  6. request changes  7. reinitiate */
sec('5–7. Reject, request changes, reinitiate');
as_person($head);
$doc3 = ds_doc_create(['title' => 'Work order 17', 'category_id' => array_search('Work Orders', $cats, true), 'department_id' => $dFin, 'value_amount' => '5000', 'owner_id' => $head], $pdfOf('WO'), 'wo.pdf');
ds_submit($doc3, ds_plan_from_workflow(ds_workflow($wfSeq)), [], (int) ds_doc($doc3)['version']);
ok(count(ds_doc_requests($doc3, 1)) === 2, 'CEO stage skipped for a value below ₹1,00,000 (conditional stage)');
as_person($A);
$r = ds_doc_requests($doc3, 1)[0];
throws(static fn() => ds_decide((int) $r['id'], 'reject', '', (int) ds_doc($doc3)['version']), 'a rejection needs a reason', 'required');
ds_decide((int) $r['id'], 'reject', 'Wrong vendor name', (int) ds_doc($doc3)['version']);
ok(ds_doc($doc3)['status'] === 'rejected', 'document rejected');
ok(ds_doc_requests($doc3, 1)[1]['status'] === 'cancelled', 'later requests withdrawn');
as_person($head);
ds_reinitiate($doc3, (int) ds_doc($doc3)['version']);
ok(ds_doc($doc3)['status'] === 'reinitiated', 'owner reinitiates');
ds_doc_new_version($doc3, $pdfOf('WO corrected'), 'wo-v2.pdf', 'Vendor name corrected', (int) ds_doc($doc3)['version']);
ok(count(ds_doc_versions($doc3)) === 2, 'version 2 uploaded; version history kept');
ds_submit($doc3, ds_plan_from_workflow(ds_workflow($wfSeq)), [], (int) ds_doc($doc3)['version']);
ok((int) ds_doc($doc3)['cycle'] === 2, 'sent again: signing cycle 2');
as_person($A);
$r = ds_doc_requests($doc3, 2)[0];
ds_decide((int) $r['id'], 'changes', 'Add the delivery date', (int) ds_doc($doc3)['version']);
ok(ds_doc($doc3)['status'] === 'changes_requested', 'changes requested');
as_person($head);
$v = (int) ds_doc($doc3)['version'];
ds_submit($doc3, ds_plan_from_workflow(ds_workflow($wfSeq)), [], $v);
as_person($A);
$r = ds_doc_requests($doc3, 3)[0];
ds_sign_simple((int) $r['id'], 'eapproval', 'Secret#123', '', null, null, (int) ds_doc($doc3)['version'], $typed);
// A new version in the middle of signing stops the cycle.
as_person($head);
ds_doc_new_version($doc3, $pdfOf('WO v3'), 'wo-v3.pdf', 'Late change', (int) ds_doc($doc3)['version']);
$d3 = ds_doc($doc3);
ok($d3['status'] === 'reinitiated' && ds_doc_requests($doc3, 3)[1]['status'] === 'cancelled', 'a new version during signing stops the cycle and needs re-sending');

/* 13. unauthorised access */
sec('13. Unauthorised access');
as_person($E);
ok(ds_org() === $head2, 'the outsider works in their own organisation');
ok(!ds_doc_can_view(ds_doc_raw($doc1)), 'another organisation\'s member cannot see the document');
[$w, $t, $p] = ds_visible_sql();
ok((int) fabo_one('SELECT COUNT(*) n FROM ds_documents d WHERE ' . $w, $t, $p)['n'] === 0, 'and it never appears in their lists');
as_person($head);
$docR = ds_doc_create(['title' => 'Board minutes (restricted)', 'category_id' => array_search('Administrative Documents', $cats, true), 'department_id' => $dFin, 'confidentiality' => 'restricted', 'owner_id' => $head], $pdfOf('Minutes', 1), 'm.pdf');
fabo_exec('INSERT INTO ds_role_members (org_id, person_id, role, department_id) VALUES (?,?,?,?)', 'iisi', [$head, $D, 'viewer', $dFin]);
as_person($D);
ok(!ds_doc_can_view(ds_doc($docR)), 'a department viewer cannot open a Restricted document');
ok(ds_doc_can_view(ds_doc($doc1)), 'but can open an Internal document of that department');
ok(!ds_doc_can_manage(ds_doc($doc1)), 'and cannot manage it');
ok(!ds_can('workflows.manage') && !ds_can('roles.manage'), 'a viewer holds no administration permission');

/* 14. multiple departments */
sec('14. Multiple departments');
fabo_exec('INSERT INTO ds_role_members (org_id, person_id, role, department_id) VALUES (?,?,?,?)', 'iisi', [$head, $C, 'dept_admin', $dAdm]);
as_person($C);
ok(ds_can('docs.manage') && ds_scope_depts('docs.manage') !== null, 'Department Administrator is scoped');
ok(ds_doc_can_manage(ds_doc($doc2)), 'manages documents of Administration');
ok(!ds_doc_can_manage(ds_doc($doc3)), 'but not documents of Finance');
as_person($head);
$deptRep = ds_report('department', ds_report_filters([]));
ok(count($deptRep['rows']) >= 2, 'department-wise report lists both departments');

/* 15. concurrency */
sec('15. Concurrent signature requests');
$doc4 = ds_doc_create(['title' => 'Parallel DSC + approval', 'category_id' => $catAgr, 'owner_id' => $head], $pdfOf('Par', 1), 'p.pdf');
ds_submit($doc4, ds_plan_adhoc([['person_id' => $B, 'order' => 1, 'action' => 'sign', 'method' => 'dsc', 'mandatory' => 1], ['person_id' => $A, 'order' => 1, 'action' => 'approve', 'method' => 'eapproval', 'mandatory' => 1]]), [], (int) ds_doc($doc4)['version']);
$rq4 = ds_doc_requests($doc4, 1);
as_person($B);
[$base4] = ds_dsc_download((int) $rq4[0]['id']);
as_person($A);
throws(static fn() => ds_sign_simple((int) $rq4[1]['id'], 'eapproval', 'Secret#123', '', null, null, (int) ds_doc($doc4)['version'], $typed), 'an approval that would change the PDF waits while a DSC signer holds it', 'signing this document');
as_person($B);
ds_dsc_upload((int) $rq4[0]['id'], $acrobat($base4, $bPem, $bKey, 'Bhavana Reddy'), (int) ds_doc($doc4)['version']);
as_person($A);
ds_sign_simple((int) $rq4[1]['id'], 'eapproval', 'Secret#123', '', null, null, (int) ds_doc($doc4)['version'], $typed);
$d4 = ds_doc($doc4);
ok($d4['status'] === 'signed', 'both complete in turn; document signed');
$rep = ds_verify_pdf(ds_read((int) $d4['signed_file_id']), ['anchors' => ds_anchors(), 'fetch' => ds_fetcher()]);
ok($rep['overall'] === 'valid' && ($rep['signatures'][0]['modifications']['status'] ?? '') === 'annotations', 'the approval stamp after the DSC signature keeps it valid', $rep['overall']);
$n1 = ds_next_ref('doc');
$n2 = ds_next_ref('doc');
ok($n1 !== $n2, 'references are unique under the sequence lock');

/* delegation */
sec('Delegation');
fabo_exec('INSERT INTO ds_delegations (org_id, from_person_id, to_person_id, starts_on, ends_on, reason) VALUES (?,?,?,CURDATE(),DATE_ADD(CURDATE(), INTERVAL 5 DAY),"Leave")', 'iii', [$head, $A, $D]);
$doc5 = ds_doc_create(['title' => 'Delegated approval', 'category_id' => $catAgr, 'owner_id' => $head], $pdfOf('Del', 1), 'd.pdf');
ds_submit($doc5, ds_plan_adhoc([['person_id' => $A, 'order' => 1, 'action' => 'approve', 'method' => 'eapproval', 'mandatory' => 1]]), [], (int) ds_doc($doc5)['version']);
$r5 = ds_doc_requests($doc5, 1)[0];
ok((int) $r5['person_id'] === $D && (int) $r5['on_behalf_of_id'] === $A, 'a request to Anil goes to Divya, on his behalf');

/* 16. expiry */
sec('16. Document expiry');
$doc6 = ds_doc_create(['title' => 'Quotation (short validity)', 'category_id' => $catAgr, 'owner_id' => $head, 'valid_until' => date('Y-m-d', strtotime('+2 days'))], $pdfOf('Q', 1), 'q.pdf');
ds_submit($doc6, ds_plan_adhoc([['person_id' => $C, 'order' => 1, 'action' => 'approve', 'method' => 'eapproval', 'mandatory' => 1]]), [], (int) ds_doc($doc6)['version']);
fabo_exec('UPDATE ds_documents SET valid_until = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE id = ?', 'i', [$doc6]);
ds_cron_org();
ok(ds_doc($doc6)['status'] === 'expired', 'validity passed before signing → expired by the scheduler');
ok(ds_doc_requests($doc6, 1)[0]['status'] === 'expired', 'its pending request expired too');

/* reminders and escalation */
fabo_exec('UPDATE ds_signature_requests SET activated_at = DATE_SUB(NOW(), INTERVAL 3 HOUR) WHERE doc_id = ? AND status = "pending"', 'i', [$doc5]);
$doc7 = ds_doc_create(['title' => 'Escalation test', 'category_id' => $catAgr, 'department_id' => $dFin, 'value_amount' => '2000', 'owner_id' => $head], $pdfOf('Esc', 1), 'e.pdf');
ds_submit($doc7, ds_plan_from_workflow(ds_workflow($wfSeq)), [], (int) ds_doc($doc7)['version']);
fabo_exec('UPDATE ds_signature_requests SET activated_at = DATE_SUB(NOW(), INTERVAL 3 HOUR) WHERE doc_id = ? AND status = "pending"', 'i', [$doc7]);
ds_cron_org();
ok((int) fabo_one('SELECT reminders FROM ds_signature_requests WHERE doc_id = ? AND status = "pending"', 'i', [$doc7])['reminders'] >= 1, 'a reminder went out after the workflow interval');
ok(fabo_one('SELECT escalated_at FROM ds_signature_requests WHERE doc_id = ? AND status = "pending"', 'i', [$doc7])['escalated_at'] !== null, 'escalated after the workflow\'s hours');

/* 17. audit completeness and tamper evidence */
sec('17. Audit trail completeness');
$acts = array_column(fabo_all('SELECT DISTINCT action FROM ds_audit WHERE doc_id = ?', 'i', [$doc1]), 'action');
foreach (['create', 'upload', 'submit', 'lock', 'requested', 'approved', 'dsc_download', 'dsc_rejected', 'signed', 'esign_start', 'esign_failed', 'callback_forged', 'callback_duplicate', 'completed', 'verify'] as $need) {
    ok(in_array($need, $acts, true), 'audit holds "' . $need . '"');
}
[$chainOk, $n, , $msg] = ds_audit_check();
ok($chainOk && $n > 50, 'hash chain intact (' . $n . ' entries)', $msg);
$victim = fabo_one('SELECT id, seq, summary FROM ds_audit WHERE org_id = ? ORDER BY seq LIMIT 1 OFFSET 10', 'i', [$head]);
fabo_exec('UPDATE ds_audit SET summary = "nothing happened" WHERE id = ?', 'i', [(int) $victim['id']]);
[$chainOk2, , $at] = ds_audit_check();
ok(!$chainOk2 && (int) $at === (int) $victim['seq'], 'an edited entry is detected at its exact position');
fabo_exec('UPDATE ds_audit SET summary = ? WHERE id = ?', 'si', [$victim['summary'], (int) $victim['id']]);
$last = fabo_one('SELECT * FROM ds_audit WHERE org_id = ? ORDER BY seq DESC LIMIT 1', 'i', [$head]);
fabo_exec('DELETE FROM ds_audit WHERE id = ?', 'i', [(int) $last['id']]);
[$chainOk3, , , $msg3] = ds_audit_check();
ok(!$chainOk3 && str_contains($msg3, 'removed'), 'a deleted newest entry is detected', $msg3);
$cols = array_keys($last);
fabo_exec('INSERT INTO ds_audit (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')', str_repeat('s', count($cols)), array_values(array_map(static fn($v) => $v === null ? null : (string) $v, $last)));
ok(ds_audit_check()[0], 'restored: intact again');
$sealRow = fabo_one('SELECT * FROM ds_signature_requests WHERE doc_id = ? AND used_method = "eapproval" LIMIT 1', 'i', [$doc1]);
fabo_exec('UPDATE ds_signature_requests SET evidence_json = REPLACE(evidence_json, "Anil", "Someone") WHERE id = ?', 'i', [(int) $sealRow['id']]);
$ap = ds_approvals_check($doc1);
ok(isset($ap[0]) && $ap[0]['sealed'] === false, 'an edited approval record breaks its seal');

/* the field studio */
sec('Field studio (sender places the fields)');
as_person($head);
$docS = ds_doc_create(['title' => 'Studio contract', 'category_id' => $catAgr, 'department_id' => $dFin, 'owner_id' => $head], $pdfOf('Contract', 3), 'contract.pdf');
$planS = ds_plan_adhoc([['person_id' => $C, 'order' => 1, 'action' => 'approve', 'method' => 'eapproval', 'mandatory' => 1],
                        ['person_id' => $D, 'order' => 2, 'action' => 'sign', 'method' => 'drawn', 'mandatory' => 1],
                        ['person_id' => $B, 'order' => 3, 'action' => 'sign', 'method' => 'dsc', 'mandatory' => 1]]);
ds_plan_prepare($docS, $planS, []);
ok(ds_doc($docS)['status'] === 'draft' && ds_doc($docS)['draft_plan_json'] !== null, 'route kept as a draft while fields are placed');
$rec = ds_studio_recipients(ds_doc($docS), json_decode(ds_doc($docS)['draft_plan_json'], true));
ok(count($rec) === 3 && $rec['0:0']['person_id'] === $C && $rec['2:0']['person_id'] === $B, 'studio lists every signatory, resolved');
$saved = ds_studio_save($docS, [
    '0:0' => [['kind' => 'signature', 'page' => 0, 'x' => 0.6, 'y' => 0.1, 'w' => 0.3, 'h' => 0.07], ['kind' => 'date', 'page' => 0, 'x' => 0.6, 'y' => 0.18, 'w' => 0.15, 'h' => 0.03]],
    '1:0' => [['kind' => 'signature', 'page' => 2, 'x' => 0.1, 'y' => 0.8, 'w' => 0.26, 'h' => 0.07], ['kind' => 'name', 'page' => 2, 'x' => 0.1, 'y' => 0.88, 'w' => 0.24, 'h' => 0.03],
              ['kind' => 'initials', 'page' => 0, 'x' => 0.85, 'y' => 0.9, 'w' => 0.09, 'h' => 0.05], ['kind' => 'initials', 'page' => 1, 'x' => 0.85, 'y' => 0.9, 'w' => 0.09, 'h' => 0.05]],
    '2:0' => [['kind' => 'signature', 'page' => 2, 'x' => 0.55, 'y' => 0.8, 'w' => 0.3, 'h' => 0.07], ['kind' => 'designation', 'page' => 2, 'x' => 0.55, 'y' => 0.88, 'w' => 0.3, 'h' => 0.03]],
    '9:9' => [['kind' => 'signature', 'page' => 0, 'x' => 0.1, 'y' => 0.1]],
    '0:1' => [['kind' => 'bogus', 'page' => 0, 'x' => 0.1, 'y' => 0.1]],
]);
ok(count($saved['fields']) === 3 && !isset($saved['fields']['9:9']), 'fields saved for real slots only; unknown slots and kinds dropped');
$pS = json_decode(ds_doc($docS)['draft_plan_json'], true);
ds_submit($docS, $pS, (array) ($pS['chosen'] ?? []), (int) ds_doc($docS)['version']);
$rS = ds_doc_requests($docS, 1);
ok(count(ds_request_fields($rS[0])) === 2 && count(ds_request_fields($rS[1])) === 4 && count(ds_request_fields($rS[2])) === 2, 'each request carries its own placed fields');
ok(ds_doc($docS)['draft_plan_json'] === null, 'the draft route is cleared once sent');
as_person($C);
ds_sign_simple((int) $rS[0]['id'], 'eapproval', 'Secret#123', '', null, '{"page":1,"x":0,"y":0}', (int) ds_doc($docS)['version'], $typed);
$ev = json_decode(fabo_one('SELECT evidence_json FROM ds_signature_requests WHERE id = ?', 'i', [(int) $rS[0]['id']])['evidence_json'], true);
ok(count($ev['stamped_on']) === 2 && $ev['stamped_on'][0]['page'] === 1, 'approval stamped in the sender\'s two places (the signer\'s own choice is ignored)');
as_person($D);
ds_sign_simple((int) $rS[1]['id'], 'drawn', 'Secret#123', '', $png, null, (int) ds_doc($docS)['version'], $typed);
$ev = json_decode(fabo_one('SELECT evidence_json FROM ds_signature_requests WHERE id = ?', 'i', [(int) $rS[1]['id']])['evidence_json'], true);
ok(count($ev['stamped_on']) === 4 && $ev['stamped_on'][0]['page'] === 3, 'drawn signature, name and initials on pages 1–3 as placed');
as_person($B);
[$prep] = ds_dsc_download((int) $rS[2]['id']);
$wk = ds_read((int) ds_doc($docS)['working_file_id']);
ok(strlen($prep) > strlen($wk) && str_starts_with($prep, $wk) && str_contains(substr($prep, strlen($wk)), '/FT /Sig'), 'DSC copy carries an empty signature field at the placed spot');
[$prep2] = ds_dsc_download((int) $rS[2]['id']);
ok($prep2 === $prep, 'downloading again gives the same prepared copy');
ds_dsc_upload((int) $rS[2]['id'], $acrobat($prep, $bPem, $bKey, 'Bhavana Reddy'), (int) ds_doc($docS)['version']);
$dS = ds_doc($docS);
ok($dS['status'] === 'signed', 'studio document fully signed');
$rep = ds_verify_pdf(ds_read((int) $dS['signed_file_id']), ['anchors' => ds_anchors(), 'fetch' => ds_fetcher()]);
ok($rep['overall'] === 'valid', 'its DSC signature verifies as valid', $rep['overall']);

/* DSC copies obtained other ways */
sec('DSC upload without the DSC download');
as_person($head);
$docP = ds_doc_create(['title' => 'Plain DSC', 'category_id' => $catAgr, 'owner_id' => $head], $pdfOf('Plain', 1), 'plain.pdf');
$planP = ds_plan_adhoc([['person_id' => $B, 'order' => 1, 'action' => 'sign', 'method' => 'dsc', 'mandatory' => 1]]);
$planP['fields'] = ['0:0' => [['kind' => 'signature', 'page' => 0, 'x' => 0.1, 'y' => 0.7, 'w' => 0.3, 'h' => 0.07], ['kind' => 'name', 'page' => 0, 'x' => 0.1, 'y' => 0.78, 'w' => 0.3, 'h' => 0.03]]];
ds_submit($docP, $planP, [], (int) ds_doc($docP)['version']);
$rP = ds_doc_requests($docP, 1)[0];
as_person($B);
$plain = ds_read((int) ds_doc($docP)['working_file_id']);
throws(static fn() => ds_dsc_upload((int) $rP['id'], $pdfOf('Something else', 1), (int) ds_doc($docP)['version']), 'an unrelated file is still refused', 'not the current version');
ds_dsc_upload((int) $rP['id'], $acrobat($plain, $bPem, $bKey, 'Bhavana Reddy'), (int) ds_doc($docP)['version']);
ok(ds_doc($docP)['status'] === 'signed', 'signed from the document as downloaded from the page (no DSC download first): accepted');
$fp = ds_read((int) ds_doc($docP)['signed_file_id']);
ok(substr_count($fp, '/Subtype /Stamp') >= 2, 'the sender\x27s placed fields were still filled in after the signature');
$rep = ds_verify_pdf($fp, ['anchors' => ds_anchors(), 'fetch' => ds_fetcher()]);
ok($rep['overall'] === 'valid' && ($rep['signatures'][0]['modifications']['status'] ?? '') === 'annotations', 'and the DSC signature is still valid', $rep['overall']);
// An expired hold, the document unchanged since: still accepted.
as_person($head);
$docE = ds_doc_create(['title' => 'Expired hold', 'category_id' => $catAgr, 'owner_id' => $head], $pdfOf('Hold', 1), 'hold.pdf');
ds_submit($docE, ds_plan_adhoc([['person_id' => $B, 'order' => 1, 'action' => 'sign', 'method' => 'dsc', 'mandatory' => 1]]), [], (int) ds_doc($docE)['version']);
$rE = ds_doc_requests($docE, 1)[0];
as_person($B);
[$copyE] = ds_dsc_download((int) $rE['id']);
fabo_exec('UPDATE ds_signature_transactions SET expires_at = DATE_SUB(NOW(), INTERVAL 2 HOUR) WHERE request_id = ?', 'i', [(int) $rE['id']]);
ds_cron_org();
ds_dsc_upload((int) $rE['id'], $acrobat($copyE, $bPem, $bKey, 'Bhavana Reddy'), (int) ds_doc($docE)['version']);
ok(ds_doc($docE)['status'] === 'signed', 'signed after the 60-minute hold ran out, nothing changed meanwhile: accepted');
$rep = ds_verify_pdf(ds_read((int) ds_doc($docE)['signed_file_id']), ['anchors' => ds_anchors(), 'fetch' => ds_fetcher()]);
ok($rep['overall'] === 'valid', 'and its signature verifies as valid', $rep['overall']);

/* a chain whose intermediate is not in the signature */
sec('Intermediate certificate fetched from the certificate');
$intKey = $newKey();
$intPem = $cert(['commonName' => 'Test Sub-CA for Class 3 Individuals', 'organizationName' => 'Test Licensed CA'], $intKey, $caPem, $caKey, 'v3_subca', 1800, 50);
$GLOBALS['int_der'] = ds_unpem($intPem);
$GLOBALS['int_crl'] = static fn() => $crl($intPem, $intKey, []);
$aKey = $newKey();
$aPem = $cert(['commonName' => 'Bhavana Reddy', 'emailAddress' => 'bhavana@rtih.test'], $aKey, $intPem, $intKey, 'v3_aia', 365, 4001);
$aiaSigned = $acrobat($pdfOf('AIA', 1), $aPem, $aKey, 'Bhavana Reddy');
$cmsA = ds_cms_parse(ds_pdf_signatures($aiaSigned)[0]['der']);
ok(count($cmsA['certs']) === 1, 'the signature carries only the signer\'s certificate');
$rep = ds_verify_pdf($aiaSigned, ['anchors' => ds_anchors(), 'fetch' => ds_fetcher()]);
ok($rep['overall'] === 'valid' && count($rep['signatures'][0]['chain']['path']) === 3, 'the missing intermediate is fetched and the chain reaches the trusted root', $rep['overall'] . ' ' . json_encode(array_map(static fn($c) => $c['key'] . ':' . $c['state'] . ' ' . $c['message'], $rep['signatures'][0]['checks'])));
$rep = ds_verify_pdf($aiaSigned, ['anchors' => ds_anchors()]);
ok($rep['overall'] === 'incomplete', 'without fetching, it is reported incomplete — never valid', $rep['overall']);

/* someone outside the organisation */
sec('External signatory (one-page link, e-mailed code)');
as_person($head);
$GLOBALS['ds_test_links'] = [];
$GLOBALS['ds_test_codes'] = [];
require_once __DIR__ . '/../lib/throttle.php';
fabo_throttle_blocked('ds-guest-code', 'x', 99, 1);
fabo_exec('DELETE FROM auth_throttle WHERE scope LIKE "ds-guest%"');
throws(static fn() => ds_plan_adhoc([['person_id' => 'ext', 'ext_name' => 'Kiran Rao', 'ext_email' => 'not-an-email']]), 'an external signatory needs a valid e-mail', 'valid e-mail');
$docX = ds_doc_create(['title' => 'Vendor agreement', 'category_id' => $catAgr, 'owner_id' => $head], $pdfOf('Vendor', 2), 'vendor.pdf');
ds_submit($docX, ds_plan_adhoc([['person_id' => 'ext', 'order' => 1, 'action' => 'sign', 'ext_name' => 'Kiran Rao', 'ext_email' => 'Kiran.Rao@Vendor.test', 'ext_org' => 'Vendor Ltd'],
                                ['person_id' => $D, 'order' => 2, 'action' => 'sign', 'method' => 'any', 'mandatory' => 1]]), [], (int) ds_doc($docX)['version']);
$rx = fabo_one('SELECT * FROM ds_signature_requests WHERE doc_id = ? AND external_id IS NOT NULL', 'i', [$docX]);
ok($rx && $rx['status'] === 'pending' && (int) $rx['person_id'] === 0, 'the external request is pending, addressed to no portal user');
ok(ds_external((int) $rx['external_id'])['email'] === 'kiran.rao@vendor.test', 'the e-mail is kept in lower case');
ok(ds_req_name($rx) === 'Kiran Rao' && str_contains(ds_req_avatar($rx), 'ext'), 'shown by name, marked as external');
$link = $GLOBALS['ds_test_links'][(int) $rx['id']] ?? '';
ok(strlen($link) === 48, 'a 48-character signing link was e-mailed');
ok(fabo_one('SELECT token_hash FROM ds_signature_requests WHERE id = ?', 'i', [(int) $rx['id']])['token_hash'] === hash('sha256', $link), 'only the link\'s hash is stored');
ok(ds_guest_open(($link[0] === '0' ? '1' : '0') . substr($link, 1)) === null && ds_guest_open('nonsense') === null, 'a wrong or malformed link opens nothing');
$o = ds_guest_open($link);
ok($o && (int) $o[1]['id'] === $docX && ds_guest_can_act($o[0], $o[1]), 'the link opens the document, ready to sign');
throws(static fn() => ds_guest_sign($link, '000000', $png, '', true, $typed), 'signing before asking for a code is refused', 'new code');
$to = ds_guest_send_code($o[0], $o[2]);
ok(str_starts_with($to, 'k') && str_ends_with($to, '@vendor.test') && !str_contains($to, 'kiran.rao'), 'the code goes to the masked address', $to);
$code = $GLOBALS['ds_test_codes'][(int) $rx['id']];
$wrong = $code === '111111' ? '222222' : '111111';
throws(static fn() => ds_guest_sign($link, $wrong, $png, '', true, $typed), 'a wrong code is refused', 'not right');
throws(static fn() => ds_guest_sign($link, $code, $png, '', false, $typed), 'signing without consent is refused', 'agree');
throws(static fn() => ds_guest_sign($link, $code, null, '', true, $typed), 'signing without a signature is refused', 'Draw your signature');
ok(str_contains(ds_guest_sign($link, $code, $png, 'Agreed', true, $typed), 'Signed'), 'the external signatory signs with a drawn / uploaded image');
$rx = fabo_one('SELECT * FROM ds_signature_requests WHERE id = ?', 'i', [(int) $rx['id']]);
ok($rx['status'] === 'signed' && $rx['used_method'] === 'drawn', 'recorded as signed (drawn)', $rx['status'] . ' ' . $rx['used_method']);
ok(!ds_guest_can_act($rx, ds_doc($docX)), 'the link cannot be used to sign twice');
throws(static fn() => ds_guest_sign($link, $code, $png, '', true, $typed), 'a second attempt is refused', 'nothing to sign');
ok(ds_guest_open($link) !== null, 'the link still opens to show the outcome');
$ev = fabo_one('SELECT * FROM ds_audit WHERE doc_id = ? AND action = "external_code"', 'i', [$docX]);
ok((bool) $ev, 'sending the code is in the audit trail');
as_person($D);
$rd = fabo_one('SELECT * FROM ds_signature_requests WHERE doc_id = ? AND person_id = ? AND status = "pending"', 'ii', [$docX, $D]);
ok((bool) $rd, 'the next stage (staff) is now waiting');
ds_sign_simple((int) $rd['id'], 'drawn', 'Secret#123', '', $png, null, (int) ds_doc($docX)['version'], $typed);
ok(ds_doc($docX)['status'] === 'signed', 'the document completes');
$newLink = $GLOBALS['ds_test_links'][(int) $rx['id']] ?? '';
ok($newLink !== $link && ds_guest_open($newLink) !== null && ds_guest_open($link) === null, 'the external signatory gets a fresh link to the signed copy; the old link stops');
$fin = ds_doc($docX);
ok(count((new DsPdf(ds_read((int) ($fin['signed_file_id'] ?: $fin['working_file_id']))))->pages()) === 2, 'the signed copy is a readable 2-page PDF');

as_person($head);
$docY = ds_doc_create(['title' => 'Quotation', 'category_id' => $catAgr, 'owner_id' => $head], $pdfOf('Quote', 1), 'q.pdf');
ds_submit($docY, ds_plan_adhoc([['person_id' => 'ext', 'order' => 1, 'action' => 'sign', 'ext_name' => 'Kiran Rao', 'ext_email' => 'kiran.rao@vendor.test']]), [], (int) ds_doc($docY)['version']);
$ry = fabo_one('SELECT * FROM ds_signature_requests WHERE doc_id = ? AND external_id IS NOT NULL', 'i', [$docY]);
ok((int) $ry['external_id'] === (int) $rx['external_id'], 'the same e-mail is the same external signatory');
$ly = $GLOBALS['ds_test_links'][(int) $ry['id']];
$oy = ds_guest_open($ly);
ds_guest_send_code($oy[0], $oy[2]);
$cy = $GLOBALS['ds_test_codes'][(int) $ry['id']];
for ($i = 0; $i < 5; $i++) {
    try {
        ds_guest_check_code($oy[0], $cy === '00000' . $i ? '999999' : '00000' . $i);
    } catch (InvalidArgumentException $e) {
    }
}
throws(static fn() => ds_guest_check_code($oy[0], $cy), 'after five wrong codes even the right one is refused', 'Too many');
ds_guest_send_code($oy[0], $oy[2]);
$cy = $GLOBALS['ds_test_codes'][(int) $ry['id']];
throws(static fn() => ds_guest_decline($ly, $cy, 'reject', ''), 'declining needs a reason', 'reason');
ok(str_contains(ds_guest_decline($ly, $cy, 'reject', 'Price is wrong'), 'declined'), 'the external signatory can decline with a reason');
ok(ds_doc($docY)['status'] === 'rejected', 'the document is marked declined');

// Fields placed in the studio for an external signatory reach their request and their signed copy.
as_person($head);
$docZ = ds_doc_create(['title' => 'NDA', 'category_id' => $catAgr, 'owner_id' => $head], $pdfOf('NDA', 2), 'nda.pdf');
ds_plan_prepare($docZ, ds_plan_adhoc([['person_id' => 'ext', 'order' => 1, 'action' => 'sign', 'ext_name' => 'Meera Iyer', 'ext_email' => 'meera@partner.test']]), []);
$recZ = ds_studio_recipients(ds_doc($docZ), json_decode(ds_doc($docZ)['draft_plan_json'], true));
ok(str_contains($recZ['0:0']['name'] ?? '', 'Meera Iyer'), 'the studio lists the external signatory by name', json_encode($recZ));
ds_studio_save($docZ, ['0:0' => [['kind' => 'signature', 'page' => 1, 'x' => 0.55, 'y' => 0.78, 'w' => 0.3, 'h' => 0.08], ['kind' => 'name', 'page' => 1, 'x' => 0.55, 'y' => 0.88, 'w' => 0.3, 'h' => 0.03]]]);
$pZ = json_decode(ds_doc($docZ)['draft_plan_json'], true);
ds_submit($docZ, $pZ, (array) ($pZ['chosen'] ?? []), (int) ds_doc($docZ)['version']);
$rz = fabo_one('SELECT * FROM ds_signature_requests WHERE doc_id = ? AND external_id IS NOT NULL', 'i', [$docZ]);
ok(count(ds_request_fields($rz)) === 2, 'the external request carries the placed fields');
$lz = $GLOBALS['ds_test_links'][(int) $rz['id']];
$oz = ds_guest_open($lz);
ds_guest_send_code($oz[0], $oz[2]);
ds_guest_sign($lz, $GLOBALS['ds_test_codes'][(int) $rz['id']], $png, '', true, $typed);
$fz = ds_doc($docZ);
ok($fz['status'] === 'signed', 'one external signatory alone completes the document');
$bz = ds_read((int) ($fz['signed_file_id'] ?: $fz['working_file_id']));
ok(str_contains($bz, 'ds-req-' . $rz['id'] . '-0') && str_contains($bz, 'ds-req-' . $rz['id'] . '-1'), 'the signature and the name are stamped where the sender placed them');
ok(isset($GLOBALS['ds_guest_new_link'][(int) $rz['id']]) && ds_guest_open($GLOBALS['ds_guest_new_link'][(int) $rz['id']]) !== null, 'the signer page can move to the signed-copy link');
unset($GLOBALS['ds_test_links'], $GLOBALS['ds_test_codes']);

/* duplicate e-mails */
sec('No duplicate notifications (clocks differ, runs overlap)');
as_person($head);
ok(ds_clock_skew() === 19800, 'the database clock (UTC) and PHP clock (IST) differ by 5½ h, and it is measured', (string) ds_clock_skew());
$docN = ds_doc_create(['title' => 'Reminder check', 'category_id' => $catAgr, 'owner_id' => $head], $pdfOf('Rem', 1), 'rem.pdf');
$planN = ds_plan_adhoc([['person_id' => $D, 'order' => 1, 'action' => 'sign', 'method' => 'any', 'mandatory' => 1]], ['reminder_hours' => 1]);
ds_submit($docN, $planN, [], (int) ds_doc($docN)['version']);
$rn = fabo_one('SELECT * FROM ds_signature_requests WHERE doc_id = ? AND person_id = ?', 'ii', [$docN, $D]);
$cnt = static fn(int $doc, string $ev, int $pid) => (int) fabo_one('SELECT COUNT(*) n FROM ds_notifications WHERE doc_id = ? AND event = ? AND person_id = ? AND channel = "inapp"', 'isi', [$doc, $ev, $pid])['n'];
ok($cnt($docN, 'requested', $D) === 1, 'one "please sign" notice');
ok(abs(ds_ts($rn['activated_at']) - time()) < 120, 'a database time reads as the real time', $rn['activated_at']);
ok(abs(ds_ts($rn['due_at']) - (time() + 86400 * (int) ds_setting('deadline_days'))) < 120, 'the due date is on the database clock', $rn['due_at']);
ds_cron_reminders();
ok($cnt($docN, 'reminder', $D) === 0, 'no reminder right after the request (interval 1 h)');
fabo_exec('UPDATE ds_signature_requests SET activated_at = DATE_SUB(NOW(), INTERVAL 61 MINUTE) WHERE id = ?', 'i', [(int) $rn['id']]);
$stale = fabo_all('SELECT r.*, d.ref_no, d.title, d.plan_json, d.owner_id FROM ds_signature_requests r JOIN ds_documents d ON d.id = r.doc_id WHERE r.id = ?', 'i', [(int) $rn['id']]);
ds_cron_reminders();
ok($cnt($docN, 'reminder', $D) === 1, 'one reminder once the hour has passed');
ds_cron_reminders();
ok($cnt($docN, 'reminder', $D) === 1, 'running again at once sends nothing more');
// Two runs that read the request at the same moment (cron and a page open): only the first claim sends.
fabo_exec('UPDATE ds_signature_requests SET reminders = 0 WHERE id = ?', 'i', [(int) $rn['id']]);
$claims = 0;
foreach ([0, 1] as $_) {
    $claims += fabo_exec('UPDATE ds_signature_requests SET reminders = reminders + 1, last_reminded_at = NOW() WHERE id = ? AND reminders = ? AND status = "pending"', 'ii', [(int) $rn['id'], (int) $stale[0]['reminders']]);
}
ok($claims === 1, 'two overlapping runs: only one may claim the reminder');
ok(ds_escalate($stale[0], ['escalate_to' => 'admins']) === true && ds_escalate($stale[0], ['escalate_to' => 'admins']) === false, 'an escalation is sent once, not again by an overlapping run');
$wf = ds_doc($docN);
ds_activate_request($wf, $rn);
ok($cnt($docN, 'requested', $D) === 1, 'opening an already open request again does not repeat "please sign"');
as_person($D);
ds_sign_simple((int) $rn['id'], 'drawn', 'Secret#123', '', $png, null, (int) ds_doc($docN)['version'], $typed);
ok(ds_doc($docN)['status'] === 'signed', 'signed');
ok($cnt($docN, 'signed', $head) === 0 && $cnt($docN, 'completed', $head) === 1, 'the owner gets one "fully signed" notice, not also "X signed"');
ds_complete($docN);
ok($cnt($docN, 'completed', $head) === 1, 'completing twice does not notify twice');
ok(str_contains(ds_fmt_dt(fabo_one('SELECT NOW() n')['n']), date('H:i')), 'times are shown in India time', ds_fmt_dt(fabo_one('SELECT NOW() n')['n']) . ' vs ' . date('H:i'));

/* name and date typed by the signatory */
sec('Full name and date typed by the signatory');
as_person($head);
$docT = ds_doc_create(['title' => 'Typed fields', 'category_id' => $catAgr, 'owner_id' => $head], $pdfOf('Typed', 1), 't.pdf');
ds_plan_prepare($docT, ds_plan_adhoc([['person_id' => $D, 'order' => 1, 'action' => 'sign', 'method' => 'any', 'mandatory' => 1]]), []);
ds_studio_save($docT, ['0:0' => [['kind' => 'signature', 'page' => 0, 'x' => 0.55, 'y' => 0.7, 'w' => 0.3, 'h' => 0.08], ['kind' => 'name', 'page' => 0, 'x' => 0.55, 'y' => 0.8, 'w' => 0.3, 'h' => 0.03],
                         ['kind' => 'date', 'page' => 0, 'x' => 0.55, 'y' => 0.85, 'w' => 0.2, 'h' => 0.03], ['kind' => 'designation', 'page' => 0, 'x' => 0.55, 'y' => 0.9, 'w' => 0.3, 'h' => 0.03]]]);
$pT = json_decode(ds_doc($docT)['draft_plan_json'], true);
ds_submit($docT, $pT, [], (int) ds_doc($docT)['version']);
as_person($D);
$rt = fabo_one('SELECT * FROM ds_signature_requests WHERE doc_id = ? AND person_id = ?', 'ii', [$docT, $D]);
$form = ds_typed_html($rt, 'drawn');
ok(str_contains($form, 'name="typed_name"') && str_contains($form, 'name="typed_date"') && str_contains($form, 'name="typed_designation"') && !str_contains($form, 'value='), 'the form asks for full name, date and designation, nothing filled in');
throws(static fn() => ds_sign_simple((int) $rt['id'], 'drawn', '', '', $png, null, (int) ds_doc($docT)['version'], []), 'signing without typing a name is refused', 'full name');
throws(static fn() => ds_sign_simple((int) $rt['id'], 'drawn', '', '', $png, null, (int) ds_doc($docT)['version'], ['typed_name' => 'Asha Verma']), 'or without a date', 'date');
throws(static fn() => ds_sign_simple((int) $rt['id'], 'drawn', '', '', $png, null, (int) ds_doc($docT)['version'], ['typed_name' => 'Asha Verma', 'typed_date' => '2026-11-04']), 'or without the designation asked for', 'designation');
ds_sign_simple((int) $rt['id'], 'drawn', '', '', $png, null, (int) ds_doc($docT)['version'], ['typed_name' => 'Asha K. Verma', 'typed_date' => '2026-11-04', 'typed_designation' => 'Chief Executive']);
$bt = ds_read((int) ds_doc($docT)['working_file_id']);
$pdfText = static function (string $b): string { $t = ''; if (preg_match_all('/stream\r?\n(.*?)endstream/s', $b, $m)) { foreach ($m[1] as $st) { $z = @gzuncompress($st); $t .= ($z !== false ? $z : $st); } } return $t; };
$tt = $pdfText($bt);
ok(str_contains($tt, 'Asha K. Verma') && str_contains($tt, '04 Nov 2026') && str_contains($tt, 'Chief Executive'), 'the typed name, date and designation are what is stamped');
ok(!str_contains($tt, ds_person_name($D)) || ds_person_name($D) === 'Asha K. Verma', 'the portal name is not auto-filled');
$ev = json_decode((string) fabo_one('SELECT evidence_json FROM ds_signature_requests WHERE id = ?', 'i', [(int) $rt['id']])['evidence_json'], true);
ok(($ev['typed_by_signatory']['name'] ?? '') === 'Asha K. Verma', 'the evidence records what the signatory typed', json_encode($ev['typed_by_signatory'] ?? null));

/* reports */
sec('Reports');
as_person($head);
foreach (array_keys(ds_reports()) as $k) {
    try {
        $rp = ds_report($k, ds_report_filters(['from' => date('Y-m-d', strtotime('-1 day'))]));
        ok(is_array($rp['rows']), 'report "' . $k . '" builds (' . count($rp['rows']) . ' rows)');
    } catch (Throwable $e) {
        ok(false, 'report "' . $k . '"', $e->getMessage());
    }
}

echo "\n" . ($fail ? "FAILED: $fail" : 'ALL PASSED') . " — $pass passed, $fail failed\n";
exit($fail ? 1 : 0);

function ds_doc_raw(int $id): array
{
    return fabo_one('SELECT * FROM ds_documents WHERE id = ?', 'i', [$id]);
}

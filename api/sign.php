<?php
/**
 * Digital Signatures REST API.
 *
 *   Authorization: Bearer dsk_…   (a token from Settings → API access; it acts
 *                                  as the person who made it, within their
 *                                  permissions and its scopes)
 *   or the portal session, with X-CSRF-Token on writes.
 *
 *   GET  ?r=documents[&status=&q=&page=]      GET  ?r=document&id=
 *   GET  ?r=requests                          GET  ?r=workflows
 *   GET  ?r=signatories                       GET  ?r=audit&id=
 *   GET  ?r=reports&key=&from=&to=            GET  ?r=notifications
 *   GET  ?r=verification&id=
 *   POST ?r=documents   (multipart: pdf + fields)          scope write
 *   POST ?r=submit      (id, workflow_id | signers[])      scope write
 *   POST ?r=decide      (request_id, decision review|reject|changes, remarks) scope write
 *   POST ?r=verify      (multipart: pdf | id)              scope verify
 *
 * Signing (approval, drawn, eSign, DSC) is deliberately not offered here:
 * it needs the signatory in person — their password, Aadhaar OTP or token.
 * Responses: {"ok":true,"data":…} or {"ok":false,"error":"…"} with the HTTP status.
 */

require_once __DIR__ . '/../lib/ui.php';
require_once __DIR__ . '/../lib/ds/app.php';
require_once __DIR__ . '/../lib/ds/pages/_routing.php';
require_once __DIR__ . '/../lib/throttle.php';
fabo_boot();
ds_install();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function ds_api_out($data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($code < 400 ? ['ok' => true, 'data' => $data] : ['ok' => false, 'error' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

$ip = fabo_client_ip();
if (fabo_throttle_blocked('ds-api', $ip, 300, 300)) {
    ds_api_out('Rate limit exceeded. Slow down.', 429);
}
fabo_throttle_hit('ds-api', $ip);

/* ---- who */
$scopes = ['read', 'write', 'verify'];
$viewer = null;
$org = null;
$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if (preg_match('/^Bearer\s+(dsk_[a-f0-9]{48})$/', trim($auth), $m)) {
    $tok = fabo_one('SELECT * FROM ds_api_tokens WHERE token_hash = ? AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > NOW())', 's', [hash('sha256', $m[1])]);
    if (!$tok) {
        fabo_throttle_hit('ds-api-bad', $ip);
        ds_api_out('Invalid or expired token.', 401);
    }
    fabo_exec('UPDATE ds_api_tokens SET last_used_at = NOW() WHERE id = ?', 'i', [(int) $tok['id']]);
    $scopes = explode(',', (string) $tok['scopes']);
    $org = (int) $tok['org_id'];
    if ((int) $tok['person_id'] === 0) {
        $viewer = fabo_admin_viewer();
    } else {
        $p = fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [(int) $tok['person_id']]);
        if (!$p) {
            ds_api_out('The token\'s owner no longer exists.', 401);
        }
        $viewer = fabo_person_viewer($p);
        if (($viewer['state'] ?? 'ok') !== 'ok') {
            ds_api_out('The token\'s owner cannot use the portal now.', 403);
        }
    }
} elseif ($auth !== '') {
    ds_api_out('Malformed Authorization header.', 401);
} else {
    $p = fabo_signed_in();
    if ($p) {
        $viewer = fabo_person_viewer($p);
    } elseif (fabo_admin_signed_in()) {
        $viewer = fabo_admin_viewer();
        fabo_session();
        $org = (int) ($_SESSION['ds_admin_org'] ?? 0);
    } else {
        ds_api_out('Sign in or send a bearer token.', 401);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        fabo_session();
        $sent = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf'] ?? '');
        if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sent)) {
            ds_api_out('Missing or wrong CSRF token.', 403);
        }
    }
}
$org = $org ?? ds_org_of($viewer);
$grant = !empty($viewer['is_admin']) ? 'edit' : ds_org_grant($org);
if ($org < 0 || $grant === 'none') {
    ds_api_out('Digital Signatures is not available to this account.', 403);
}
ds_use($org);
$GLOBALS['ds_viewer'] = $viewer;
$GLOBALS['ds_grant'] = $grant;

$r = (string) ($_GET['r'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];
$need = static function (string $scope) use ($scopes) {
    if (!in_array($scope, $scopes, true)) {
        ds_api_out('This token lacks the "' . $scope . '" scope.', 403);
    }
};
$docOut = static function (array $d, bool $full = false): array {
    $o = ['id' => (int) $d['id'], 'ref_no' => $d['ref_no'], 'tracking_code' => $d['tracking_code'], 'title' => $d['title'], 'status' => $d['status'],
          'status_label' => ds_doc_statuses()[$d['status']][0] ?? $d['status'], 'category' => ds_category_name($d['category_id'] ? (int) $d['category_id'] : null),
          'department' => $d['department_id'] ? ds_dept_name((int) $d['department_id']) : null, 'unit' => $d['unit'], 'owner' => ds_person_name((int) $d['owner_id']),
          'priority' => $d['priority'], 'confidentiality' => $d['confidentiality'], 'value' => $d['value_amount'] === null ? null : (float) $d['value_amount'],
          'doc_date' => $d['doc_date'], 'valid_until' => $d['valid_until'], 'deadline' => $d['deadline'], 'cycle' => (int) $d['cycle'],
          'created_at' => $d['created_at'], 'submitted_at' => $d['submitted_at'], 'completed_at' => $d['completed_at'], 'last_activity_at' => $d['last_activity_at']];
    if ($full) {
        $o['description'] = $d['description'];
        $o['versions'] = array_map(static fn($v) => ['version' => (int) $v['version_no'], 'sha256' => $v['sha256'], 'pages' => (int) $v['pages'], 'size' => (int) $v['size'],
                                                      'uploaded_at' => $v['created_at'], 'locked_at' => $v['locked_at']], ds_doc_versions((int) $d['id']));
        $o['requests'] = array_map(static fn($q) => ['id' => (int) $q['id'], 'cycle' => (int) $q['cycle'], 'stage' => (int) $q['stage_seq'], 'stage_name' => $q['stage_name'],
            'person' => ds_person_name((int) $q['person_id']), 'on_behalf_of' => $q['on_behalf_of_id'] ? ds_person_name((int) $q['on_behalf_of_id']) : null,
            'action' => $q['action'], 'method' => $q['method'], 'used_method' => $q['used_method'], 'mandatory' => (bool) $q['mandatory'], 'status' => $q['status'],
            'due_at' => $q['due_at'], 'acted_at' => $q['acted_at'], 'remarks' => $q['remarks']], ds_doc_requests((int) $d['id']));
        $f = ds_file((int) ($d['signed_file_id'] ?: $d['working_file_id']));
        $o['current_pdf_sha256'] = $f['sha256'] ?? null;
    }
    return $o;
};

try {
    switch ($method . ' ' . $r) {
        case 'GET documents':
            $need('read');
            [$w, $t, $p] = ds_visible_sql();
            if (isset(ds_doc_statuses()[$_GET['status'] ?? ''])) {
                $w .= ' AND d.status = ?';
                $t .= 's';
                $p[] = $_GET['status'];
            }
            if (($q = trim((string) ($_GET['q'] ?? ''))) !== '') {
                $w .= ' AND (d.ref_no LIKE ? OR d.title LIKE ?)';
                $t .= 'ss';
                $p[] = '%' . $q . '%';
                $p[] = '%' . $q . '%';
            }
            $page = max(1, (int) ($_GET['page'] ?? 1));
            $total = (int) fabo_one('SELECT COUNT(*) n FROM ds_documents d WHERE ' . $w, $t, $p)['n'];
            $rows = fabo_all('SELECT d.* FROM ds_documents d WHERE ' . $w . ' ORDER BY d.last_activity_at DESC LIMIT 50 OFFSET ' . (($page - 1) * 50), $t, $p);
            ds_api_out(['total' => $total, 'page' => $page, 'per_page' => 50, 'items' => array_map($docOut, $rows)]);
        case 'GET document':
            $need('read');
            $d = ds_doc((int) ($_GET['id'] ?? 0));
            if (!$d || !ds_doc_can_view($d)) {
                ds_api_out('Not found.', 404);
            }
            ds_audit((int) $d['id'], 'document', (int) $d['id'], 'view', 'Read ' . $d['ref_no'] . ' through the API');
            ds_api_out($docOut($d, true));
        case 'GET requests':
            $need('read');
            $rows = fabo_all('SELECT r.*, d.ref_no, d.title FROM ds_signature_requests r JOIN ds_documents d ON d.id = r.doc_id WHERE r.org_id = ? AND r.person_id = ? AND r.status = "pending" AND d.deleted_at IS NULL ORDER BY r.due_at',
                             'ii', [ds_org(), ds_me()]);
            ds_api_out(array_map(static fn($q) => ['id' => (int) $q['id'], 'document_id' => (int) $q['doc_id'], 'ref_no' => $q['ref_no'], 'title' => $q['title'], 'stage' => $q['stage_name'],
                                                   'action' => $q['action'], 'method' => $q['method'], 'due_at' => $q['due_at'], 'sign_url' => ds_abs_url('sign.php?p=sign&id=' . $q['id'])], $rows));
        case 'GET workflows':
            $need('read');
            ds_api_out(array_map(static function ($w) {
                $f = ds_workflow((int) $w['id']);
                return ['id' => (int) $w['id'], 'name' => $w['name'], 'active' => (bool) $w['active'], 'priority' => (int) $w['priority'], 'conditions' => $f['conditions'],
                        'stages' => array_map(static fn($s) => ['name' => $s['name'], 'completion' => $s['completion'], 'signatories' => count($s['signatories'])], $f['stages'])];
            }, ds_workflows()));
        case 'GET signatories':
            $need('read');
            if (!ds_can('signatories.manage') && !ds_can('docs.create')) {
                ds_api_out('Not allowed.', 403);
            }
            ds_api_out(array_map(static fn($s) => ['person_id' => (int) $s['person_id'], 'name' => ds_person_name((int) $s['person_id']), 'designation' => $s['designation'],
                'department' => $s['department_id'] ? ds_dept_name((int) $s['department_id']) : null, 'authority' => $s['authority'],
                'authority_limit' => $s['authority_limit'] === null ? null : (float) $s['authority_limit'], 'status' => $s['status']],
                fabo_all('SELECT * FROM ds_signatories WHERE org_id = ? ORDER BY id', 'i', [ds_org()])));
        case 'GET audit':
            $need('read');
            $d = ds_doc((int) ($_GET['id'] ?? 0));
            if (!$d || !ds_doc_can_view($d)) {
                ds_api_out('Not found.', 404);
            }
            ds_api_out(array_map(static fn($a) => ['seq' => (int) $a['seq'], 'at' => $a['created_at'], 'actor' => $a['actor'], 'designation' => $a['designation'], 'action' => $a['action'],
                                                   'summary' => $a['summary'], 'ip' => $a['ip'], 'hash' => $a['hash']], ds_doc_timeline((int) $d['id'])));
        case 'GET reports':
            $need('read');
            ds_need('reports.view');
            $rep = ds_report((string) ($_GET['key'] ?? ''), ds_report_filters($_GET));
            ds_api_out(['title' => $rep['title'], 'filters' => $rep['sub'], 'columns' => array_map(static fn($c) => $c[0], $rep['cols']),
                        'rows' => array_map(static fn($row) => array_intersect_key($row, $rep['cols']), $rep['rows'])]);
        case 'GET notifications':
            $need('read');
            ds_api_out(fabo_all('SELECT event, channel, subject, status, doc_id, created_at FROM ds_notifications WHERE org_id = ? AND person_id = ? ORDER BY id DESC LIMIT 100', 'ii', [ds_org(), ds_me()]));
        case 'GET verification':
            $need('verify');
            $v = fabo_one('SELECT * FROM ds_signature_verifications WHERE id = ? AND org_id = ?', 'ii', [(int) ($_GET['id'] ?? 0), ds_org()]);
            if (!$v || ($v['doc_id'] && !ds_doc_can_view(ds_doc((int) $v['doc_id']) ?? ['org_id' => -1]))) {
                ds_api_out('Not found.', 404);
            }
            ds_api_out(['report_ref' => $v['report_ref'], 'overall' => $v['overall'], 'result' => json_decode((string) $v['report_json'], true)]);
        case 'POST documents':
            $need('write');
            $up = ds_upload_bytes('pdf');
            if (!$up) {
                ds_api_out('Send the PDF in the "pdf" field.', 422);
            }
            $id = ds_doc_create($_POST, $up[0], $up[1]);
            ds_api_out($docOut(ds_doc($id), true), 201);
        case 'POST submit':
            $need('write');
            $d = ds_doc((int) ($_POST['id'] ?? 0));
            if (!$d) {
                ds_api_out('Not found.', 404);
            }
            if (!empty($_POST['workflow_id'])) {
                $_POST['route'] = 'workflow';
            } else {
                $_POST['route'] = 'adhoc';
            }
            [$plan, $chosen] = ds_plan_from_post($_POST);
            ds_submit((int) $d['id'], $plan, $chosen, (int) $d['version']);
            ds_api_out($docOut(ds_doc((int) $d['id']), true));
        case 'POST decide':
            $need('write');
            $req = fabo_one('SELECT * FROM ds_signature_requests WHERE id = ? AND org_id = ?', 'ii', [(int) ($_POST['request_id'] ?? 0), ds_org()]);
            if (!$req) {
                ds_api_out('Not found.', 404);
            }
            $dec = (string) ($_POST['decision'] ?? '');
            if (!in_array($dec, ['review', 'reject', 'changes'], true)) {
                ds_api_out('Signing and approving need the signatory in person (password, Aadhaar OTP or DSC token) and are done in the signing workspace: '
                           . ds_abs_url('sign.php?p=sign&id=' . $req['id']), 403);
            }
            $d = ds_doc((int) $req['doc_id']);
            ds_api_out(['message' => ds_decide((int) $req['id'], $dec, (string) ($_POST['remarks'] ?? ''), (int) $d['version'])]);
        case 'POST verify':
            $need('verify');
            ds_need('verify.use');
            if (!empty($_POST['id'])) {
                $d = ds_doc((int) $_POST['id']);
                if (!$d || !ds_doc_can_view($d)) {
                    ds_api_out('Not found.', 404);
                }
                $vid = ds_verify_record((int) $d['id'], (int) ($d['signed_file_id'] ?: $d['working_file_id']), 'api');
            } else {
                $up = ds_upload_bytes('pdf');
                if (!$up || !str_contains(substr($up[0], 0, 1024), '%PDF-')) {
                    ds_api_out('Send a PDF in the "pdf" field, or a document "id".', 422);
                }
                $vid = ds_verify_record(null, null, 'api', $up[0], $up[1]);
            }
            $v = fabo_one('SELECT * FROM ds_signature_verifications WHERE id = ?', 'i', [$vid]);
            ds_api_out(['report_ref' => $v['report_ref'], 'overall' => $v['overall'], 'result' => json_decode((string) $v['report_json'], true)]);
    }
    ds_api_out('Unknown resource or method.', 404);
} catch (InvalidArgumentException $e) {
    ds_api_out($e->getMessage(), 422);
} catch (Throwable $e) {
    error_log('FABO esign api: ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
    ds_api_out('The request could not be completed.', 500);
}

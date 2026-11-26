<?php
/**
 * Work Management API. Session only (the administrator's, or a signed-in
 * member's); never a ?k= link. Every call re-reads who is asking and what they
 * hold (wm_ctx); nothing the page says about itself is believed.
 *
 *   GET  ?r=boot | overview | tasks | task | departments | access | audit | report | export
 *   POST ?r=task_save | task_status | task_delete | comment_add | log_add | log_delete
 *        | task_shares | dept_save | dept_delete | member_invite | member_update
 *        | member_active | member_resend
 *
 * POSTs are forms: csrf + payload (JSON). Records outside someone's scope are
 * 404; actions above their role are 403. Errors are friendly; details go to
 * the error log only.
 */

require_once __DIR__ . '/../lib/ui.php';
require_once __DIR__ . '/../lib/wm/core.php';
require_once __DIR__ . '/../lib/wm/access.php';
require_once __DIR__ . '/../lib/wm/report.php';
require_once __DIR__ . '/../lib/throttle.php';

function wm_out(array $payload, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

function wm_api_fail(string $message, int $code = 400): void
{
    wm_out(['ok' => false, 'error' => $message], $code);
}

fabo_boot();
$route = (string) ($_GET['r'] ?? '');

// From the admin portal it is the administrator's call, provided they really are signed in.
$fromAdmin = ($_GET['as'] ?? '') === 'admin';
$viewer = null;
if ($fromAdmin && fabo_admin_signed_in()) {
    $viewer = fabo_admin_viewer();
} elseif ($person = fabo_signed_in()) {
    if ((int) ($person['must_change'] ?? 0) === 1) {
        wm_api_fail('Set your new password first.', 403);
    }
    $viewer = fabo_person_viewer($person);
} elseif (fabo_admin_signed_in()) {
    $viewer = fabo_admin_viewer();
}
if (!$viewer) {
    wm_api_fail('Please sign in again.', 401);
}

try {
    $ctx = wm_ctx($viewer);
} catch (Throwable $e) {
    error_log('WM ctx: ' . $e->getMessage());
    wm_api_fail('Work Management could not start. Try again shortly.', 500);
}
if (!$ctx) {
    wm_api_fail('You do not have access to Work Management.', 403);
}
$tkey = $ctx['role'] . ':' . $ctx['id'];
if (fabo_throttle_blocked('wm-api', $tkey, 600, 60)) {
    wm_api_fail('Too many requests. Wait a moment and try again.', 429);
}
fabo_throttle_hit('wm-api', $tkey);

$in = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    fabo_csrf_check();
    $raw = (string) ($_POST['payload'] ?? '{}');
    if (strlen($raw) > 200000) {
        wm_api_fail('That request is too large.', 413);
    }
    $in = json_decode($raw, true);
    if (!is_array($in)) {
        wm_api_fail('That request could not be read.');
    }
}
$posts = ['task_save', 'task_status', 'task_delete', 'comment_add', 'log_add', 'log_delete', 'task_shares', 'dept_save',
          'dept_delete', 'member_invite', 'member_update', 'member_active', 'member_resend'];
if (in_array($route, $posts, true) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    wm_api_fail('Use POST for this.', 405);
}

function wm_deptmap(array $ctx): array
{
    $out = [];
    foreach (wm_departments($ctx, true) as $d) {
        $out[] = ['id' => (int) $d['id'], 'name' => (string) $d['name'], 'color' => (string) $d['color'],
                  'description' => (string) ($d['description'] ?? ''), 'is_active' => (int) $d['is_active'] === 1,
                  'total' => (int) ($d['total'] ?? 0), 'open' => (int) ($d['open'] ?? 0), 'done' => (int) ($d['done'] ?? 0)];
    }
    return $out;
}

try {
    switch ($route) {
        case 'boot':
            $out = ['ok' => true, 'role' => $ctx['role'], 'me' => $ctx['id'], 'today' => wm_today(),
                    'departments' => wm_deptmap($ctx),
                    'team' => $ctx['role'] === 'client' ? [] : array_map(static fn($p) => ['id' => (int) $p['id'], 'name' => (string) $p['name']], wm_team())];
            if (wm_is_admin($ctx)) {
                $out['clients'] = array_map(static fn($m) => ['id' => $m['id'], 'name' => $m['name']],
                    array_values(array_filter(wm_members($ctx), static fn($m) => $m['role'] === 'client')));
            }
            wm_out($out);

        case 'overview':
            if ($ctx['role'] === 'client') {
                wm_api_fail('Not available.', 403);
            }
            $today = wm_today();
            $from = wm_date_in($_GET['from'] ?? '', 'From') ?? date('Y-m-d', strtotime($today . ' -' . ((int) date('N', strtotime($today)) - 1) . ' days'));
            $to = wm_date_in($_GET['to'] ?? '', 'To') ?? $today;
            if ($from > $to) {
                wm_api_fail('The start of the period is after its end.');
            }
            if (strtotime($to) - strtotime($from) > 86400 * 370) {
                wm_api_fail('Choose a period of a year or less.');
            }
            $dept = (int) ($_GET['dept'] ?? 0);
            if ($dept > 0 && !wm_dept_may_use($ctx, $dept)) {
                wm_api_fail('That department was not found.', 404);
            }
            wm_out(['ok' => true] + wm_overview($ctx, $dept, $from, $to));

        case 'tasks':
            wm_out(['ok' => true, 'tasks' => wm_tasks($ctx, [
                'dept' => (int) ($_GET['dept'] ?? 0), 'status' => (string) ($_GET['status'] ?? ''),
                'priority' => (string) ($_GET['priority'] ?? ''), 'assignee' => (string) ($_GET['assignee'] ?? ''),
                'due' => (string) ($_GET['due'] ?? ''), 'q' => (string) ($_GET['q'] ?? '')])]);

        case 'task':
            wm_out(['ok' => true] + wm_task_detail($ctx, (int) ($_GET['id'] ?? 0)));

        case 'departments':
            wm_out(['ok' => true, 'departments' => wm_deptmap($ctx)]);

        case 'access':
            wm_need_admin($ctx);
            $tasks = array_map(static fn($t) => ['id' => (int) $t['id'], 'code' => (string) $t['task_code'], 'title' => (string) $t['title'],
                                                  'department_id' => (int) $t['department_id']],
                fabo_all('SELECT id, task_code, title, department_id FROM wm_tasks WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 2000'));
            wm_out(['ok' => true, 'members' => wm_members($ctx), 'candidates' => wm_candidates($ctx), 'tasks' => $tasks]);

        case 'audit':
            wm_out(['ok' => true, 'audit' => wm_audit_list($ctx)]);

        case 'report':
            $rep = wm_report($ctx, $_GET);
            wm_out(['ok' => true, 'columns' => WM_REPORT_COLUMNS, 'filename' => wm_report_filename($rep)] + $rep);

        case 'export':
            $rep = wm_report($ctx, $_GET);
            wm_audit($ctx, 'report_exported', wm_report_filename($rep), null, $rep['filters'] + ['rows' => count($rep['rows'])]);
            wm_report_xlsx($rep, $ctx['name'])->download(wm_report_filename($rep));

        /* ------------------------------------------------------------ changes */
        case 'task_save':
            $t = wm_task_save($ctx, $in);
            wm_out(['ok' => true] + wm_task_detail($ctx, (int) $t['id']));

        case 'task_status':
            $t = wm_task_status($ctx, (int) ($in['id'] ?? 0), (string) ($in['status'] ?? ''), $in['progress'] ?? null, (string) ($in['note'] ?? ''));
            wm_out(['ok' => true, 'task' => wm_task_public($t, $ctx)]);

        case 'task_delete':
            wm_task_delete($ctx, (int) ($in['id'] ?? 0));
            wm_out(['ok' => true]);

        case 'comment_add':
            $c = wm_comment_add($ctx, (int) ($in['task_id'] ?? 0), (string) ($in['body'] ?? ''), !empty($in['internal']));
            wm_out(['ok' => true, 'comment' => $c]);

        case 'log_add':
            $l = wm_log_add($ctx, (int) ($in['task_id'] ?? 0), (string) ($in['date'] ?? ''), (int) ($in['minutes'] ?? 0), (string) ($in['description'] ?? ''));
            wm_out(['ok' => true, 'log' => $l]);

        case 'log_delete':
            wm_log_delete($ctx, (int) ($in['id'] ?? 0));
            wm_out(['ok' => true]);

        case 'task_shares':
            wm_out(['ok' => true, 'shares' => wm_task_shares_set($ctx, (int) ($in['task_id'] ?? 0), (array) ($in['clients'] ?? []))]);

        case 'dept_save':
            $d = wm_dept_save($ctx, $in);
            wm_out(['ok' => true, 'department' => ['id' => (int) $d['id'], 'name' => $d['name'], 'color' => $d['color'],
                    'description' => (string) $d['description'], 'is_active' => (int) $d['is_active'] === 1], 'departments' => wm_deptmap($ctx)]);

        case 'dept_delete':
            wm_dept_delete($ctx, (int) ($in['id'] ?? 0));
            wm_out(['ok' => true, 'departments' => wm_deptmap($ctx)]);

        case 'member_invite':
            wm_out(['ok' => true] + wm_member_invite($ctx, $in));

        case 'member_update':
            wm_out(['ok' => true, 'member' => wm_member_update($ctx, (int) ($in['id'] ?? 0), $in)]);

        case 'member_active':
            wm_out(['ok' => true, 'member' => wm_member_active($ctx, (int) ($in['id'] ?? 0), !empty($in['active']), !empty($in['revoke']))]);

        case 'member_resend':
            wm_need_admin($ctx);
            if (fabo_throttle_blocked('wm-invite', 'admin:' . fabo_client_ip(), 30, 3600)) {
                wm_api_fail('Too many invitations in the last hour. Wait a little and try again.', 429);
            }
            fabo_throttle_hit('wm-invite', 'admin:' . fabo_client_ip());
            $id = (int) ($in['id'] ?? 0);
            wm_member_find($ctx, $id);
            $sent = wm_send_invite($id);
            wm_audit($ctx, 'user_invited', (string) (wm_member_row($id)['name'] ?? ''), null, ['person' => $id, 'resend' => true]);
            wm_out(['ok' => true, 'member' => wm_member_find($ctx, $id)] + $sent);

        default:
            wm_api_fail('Not found.', 404);
    }
} catch (WmError $e) {
    wm_api_fail($e->getMessage(), $e->getCode() ?: 400);
} catch (Throwable $e) {
    error_log('WM api ' . $route . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    wm_api_fail('Something went wrong. Please try again.', 500);
}

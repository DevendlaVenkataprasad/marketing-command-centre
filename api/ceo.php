<?php
/**
 * CEO Dashboard API. Session only, never a ?k= link. Every request re-reads
 * who is asking and what they hold; nothing the page sends about itself is
 * believed. Reads are GET; every change is POST with the CSRF token, runs in a
 * transaction where it touches access, and is audited.
 *
 *   ?r=boot | overview | live | spokes | actions | activity | targets | reports
 *      | report | export | access | people | position | registry | audit
 *   POST ?r=grant | revoke | profile_save | profile_delete | target_save
 *      | target_delete | schedule_save | schedule_delete | prefs_save
 */

require_once __DIR__ . '/../lib/ui.php';
require_once __DIR__ . '/../lib/ceo/app.php';
require_once __DIR__ . '/../lib/throttle.php';

function ceo_api_out(array $payload, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

function ceo_api_fail(string $message, int $code = 400): void
{
    ceo_api_out(['ok' => false, 'error' => $message], $code);
}

function ceo_need(?array $a, string $action): void
{
    if (!ceo_may($a, $action)) {
        ceo_api_fail('Your access does not include this.', 403);
    }
}

function ceo_need_owner(array $a): void
{
    if (empty($a['owner'])) {
        ceo_api_fail('Only the owner can do this.', 403);
    }
}

function ceo_date_in($v, string $default): string
{
    $v = (string) $v;
    if ($v === '') {
        return $default;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4))) {
        return $v;
    }
    ceo_api_fail('That date is not valid.');
}

/** The filters on this request, checked against what the person holds. A filter they may not use is refused, not ignored. */
function ceo_filters(array $a, int $org): array
{
    $today = ceo_today();
    $f = ['from' => date('Y-m-01', strtotime($today)), 'to' => $today, 'spoke' => '', 'module' => '', 'dept' => 0, 'type' => '', 'status' => '', 'assignee' => 0, 'manager' => 0];
    if (!ceo_may($a, 'filter')) {
        return ceo_force_spoke($a, $f);
    }
    $f['from'] = ceo_date_in($_GET['from'] ?? '', $f['from']);
    $f['to'] = ceo_date_in($_GET['to'] ?? '', $f['to']);
    if ($f['from'] > $f['to']) {
        ceo_api_fail('The start of the period is after its end.');
    }
    if (strtotime($f['to']) - strtotime($f['from']) > 86400 * 1100) {
        ceo_api_fail('Choose a period of three years or less.');
    }
    $spoke = (string) ($_GET['spoke'] ?? '');
    if ($spoke !== '') {
        if (!isset(ceo_spokes($org)[$spoke])) {
            ceo_api_fail('That hub or spoke is not known.');
        }
        if (!ceo_allowed_spoke($a, $spoke)) {
            ceo_api_fail('That hub or spoke is outside your access.', 403);
        }
        $f['spoke'] = $spoke;
    }
    $module = (string) ($_GET['module'] ?? '');
    if ($module !== '') {
        if (!in_array($module, $a['modules'], true)) {
            ceo_api_fail('That module is outside your access.', 403);
        }
        $f['module'] = $module;
    }
    $f['dept'] = max(0, (int) ($_GET['dept'] ?? 0));
    if ($f['dept'] > 0 && !fabo_one('SELECT id FROM hr_departments WHERE id = ? AND org_id = ?', 'ii', [$f['dept'], $org])) {
        ceo_api_fail('That department is not known.');
    }
    $type = (string) ($_GET['type'] ?? '');
    $f['type'] = isset(ceo_action_types()[$type]) ? $type : '';
    $f['status'] = mb_substr(trim((string) ($_GET['status'] ?? '')), 0, 30);
    $f['assignee'] = max(0, (int) ($_GET['assignee'] ?? 0));
    $f['manager'] = max(0, (int) ($_GET['manager'] ?? 0));
    return ceo_force_spoke($a, $f);
}

/** Someone limited to a single spoke is on it whatever they asked for. */
function ceo_force_spoke(array $a, array $f): array
{
    if ($a['spokes'] !== '*' && count($a['spokes']) === 1) {
        $f['spoke'] = $a['spokes'][0];
    }
    return $f;
}

function ceo_ordered_cards(array $kpis, int $org, int $pid): array
{
    $prefs = json_decode((string) (fabo_one('SELECT prefs_json FROM ceo_prefs WHERE org_id = ? AND person_id = ?', 'ii', [$org, $pid])['prefs_json'] ?? ''), true);
    $default = json_decode(ceo_setting($org, 'default_cards', ''), true);
    $keys = is_array($prefs['cards'] ?? null) ? $prefs['cards'] : (is_array($default) ? $default : ceo_default_cards());
    $out = [];
    foreach ($keys as $k) {
        if (isset($kpis[$k])) {
            $out[] = $kpis[$k];
        }
    }
    return $out;
}

/** What the live poll returns: figures, the newest activity, the queue's size. */
function ceo_live(array $viewer, int $org, array $a, array $f, bool $force): array
{
    $kpis = ceo_kpi_values($viewer, $org, $a, $f, $force);
    $actions = ceo_actions($viewer, $org, $a, $f, $force);
    $feed = ceo_feed($viewer, $org, $a, $f, $force, 14);
    return ['cards' => ceo_ordered_cards($kpis, $org, ceo_viewer_id($viewer)), 'actions_total' => $actions['total'], 'action_counts' => $actions['counts'],
            'action_top' => array_slice($actions['items'], 0, 8), 'feed' => $feed['items'], 'coverage' => ceo_coverage($org, $a, $f), 'server_time' => date('Y-m-d H:i:s')];
}

/** The rows of the access screen. */
function ceo_access_rows(int $org): array
{
    $profiles = ceo_profiles($org);
    $spokes = ceo_spokes($org);
    $mods = ceo_modules();
    $people = ceo_people($org, '', 1000);
    $out = [];
    foreach (fabo_all('SELECT * FROM ceo_access WHERE org_id = ? ORDER BY status, subject, granted_at DESC', 'i', [$org]) as $r) {
        $p = $profiles[(int) $r['profile_id']] ?? null;
        $s = $p['scope'] ?? ['modules' => [], 'spokes' => [], 'sensitive' => [], 'actions' => []];
        $person = $people[(int) $r['person_id']] ?? null;
        $isPos = $r['subject'] === 'position';
        $out[] = [
            'id' => (int) $r['id'], 'subject' => $r['subject'], 'person_id' => (int) $r['person_id'],
            'name' => $isPos ? (string) $r['position'] : ((int) $r['person_id'] === 0 ? 'Administrator' : (string) ($person['name'] ?? 'Former member #' . $r['person_id'])),
            'emp_code' => (string) ($person['emp_code'] ?? ''), 'position' => $isPos ? (string) $r['position'] : (string) ($person['position'] ?? ''), 'department' => (string) ($person['department'] ?? ''),
            'holders' => $isPos ? count(array_filter(ceo_position_people($org, (string) $r['position']), static fn($x) => $x['can_sign_in'])) : null,
            'profile' => (string) ($p['name'] ?? 'Deleted profile'), 'profile_id' => (int) $r['profile_id'],
            'modules' => array_map(static fn($m) => $mods[$m]['label'], $s['modules']),
            'spokes' => $s['spokes'] === '*' ? ['All'] : array_map(static fn($k) => $spokes[$k]['name'] ?? $k, $s['spokes']),
            'sensitive' => array_map(static fn($k) => ceo_sensitive()[$k][0], $s['sensitive']),
            'view' => true, 'export' => in_array('export', $s['actions'], true), 'report' => in_array('report', $s['actions'], true), 'configure' => in_array('configure', $s['actions'], true),
            'status' => $r['status'], 'granted_by' => (string) $r['granted_by'], 'granted_at' => (string) $r['granted_at'], 'revoked_by' => (string) $r['revoked_by'], 'revoked_at' => (string) $r['revoked_at'],
        ];
    }
    return $out;
}

/* ================================================================ who */

fabo_boot();
$route = (string) ($_GET['r'] ?? '');

// A call from the admin portal is the administrator's, provided they really are signed in.
$fromAdmin = str_contains((string) (parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_PATH) ?? ''), '/admin/') || ($_GET['as'] ?? '') === 'admin';
$viewer = null;
if ($fromAdmin && fabo_admin_signed_in()) {
    $viewer = fabo_admin_viewer();
} elseif ($person = fabo_signed_in()) {
    if ((int) ($person['must_change'] ?? 0) === 1) {
        ceo_api_fail('Set your new password first.', 403);
    }
    $viewer = fabo_person_viewer($person);
} elseif (fabo_admin_signed_in()) {
    $viewer = fabo_admin_viewer();
}
if (!$viewer) {
    ceo_api_fail('Please sign in again.', 401);
}
$org = ceo_pick_org($viewer);
$a = ceo_access($viewer, $org);
if (!$a) {
    ceo_api_fail('You do not have access to the CEO Dashboard.', 403);
}
$pid = ceo_viewer_id($viewer);
$key = $org . ':' . $pid . ':' . (empty($viewer['is_admin']) ? 'p' : 'a');
if (fabo_throttle_blocked('ceo-api', $key, 300, 60)) {
    ceo_api_fail('Too many requests. Wait a moment and try again.', 429);
}
fabo_throttle_hit('ceo-api', $key);

/* ================================================================ changes */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    fabo_csrf_check();
    $in = json_decode((string) ($_POST['payload'] ?? ''), true);
    $in = is_array($in) ? $in : [];
    $db = fabo_db();
    switch ($route) {
        case 'grant':
            ceo_need_owner($a);
            $profiles = ceo_profiles($org);
            $profileId = (int) ($in['profile_id'] ?? 0);
            if (!isset($profiles[$profileId])) {
                ceo_api_fail('Choose an access profile.');
            }
            $position = trim((string) ($in['position'] ?? ''));
            $subject = 'person';
            $target = (int) ($in['person_id'] ?? -1);
            $label = '';
            if ($position !== '') {
                $subject = 'position';
                $target = 0;
                if (!in_array($position, array_column(ceo_positions($org), 'name'), true)) {
                    ceo_api_fail('That position is not in this organisation\'s HR records.');
                }
                $reach = ceo_position_people($org, $position);
                // Who this reaches must be exactly the list that was shown and confirmed.
                if ((string) ($in['confirm'] ?? '') !== ceo_position_hash($reach)) {
                    ceo_api_out(['ok' => false, 'error' => 'The people in this position changed. Review the list again.', 'people' => $reach, 'confirm' => ceo_position_hash($reach)], 409);
                }
                $label = 'Position: ' . $position;
            } elseif ($target === 0 && !empty($viewer['is_admin'])) {
                $label = 'Administrator';
            } else {
                $p = ceo_person_in_org($target, $org);
                if (!$p) {
                    ceo_api_fail('That person is not a member of this organisation.');
                }
                $label = (string) $p['name'];
            }
            $actor = ceo_actor($viewer);
            $db->begin_transaction();
            try {
                $before = fabo_one('SELECT id, profile_id, status FROM ceo_access WHERE org_id = ? AND subject = ? AND person_id = ? AND position = ? FOR UPDATE', 'isis', [$org, $subject, $target, $position]);
                fabo_exec('INSERT INTO ceo_access (org_id, subject, person_id, position, profile_id, status, granted_by, granted_at) VALUES (?,?,?,?,?,"active",?,NOW())
                           ON DUPLICATE KEY UPDATE profile_id = VALUES(profile_id), status = "active", granted_by = VALUES(granted_by), granted_at = NOW(), revoked_by = NULL, revoked_at = NULL',
                          'isisis', [$org, $subject, $target, $position, $profileId, $actor]);
                ceo_audit($org, $viewer, $before && $before['status'] === 'active' ? 'access_changed' : 'access_granted', $label, ['profile' => $profiles[$profileId]['name'], 'before' => $before ? ($profiles[(int) $before['profile_id']]['name'] ?? '') : null]);
                $db->commit();
            } catch (Throwable $e) {
                $db->rollback();
                throw $e;
            }
            ceo_api_out(['ok' => true, 'rows' => ceo_access_rows($org)]);

        case 'revoke':
            ceo_need_owner($a);
            $id = (int) ($in['id'] ?? 0);
            $row = fabo_one('SELECT * FROM ceo_access WHERE id = ? AND org_id = ?', 'ii', [$id, $org]);
            if (!$row) {
                ceo_api_fail('That access entry is not there.', 404);
            }
            $db->begin_transaction();
            try {
                fabo_exec('UPDATE ceo_access SET status = "revoked", revoked_by = ?, revoked_at = NOW() WHERE id = ?', 'si', [ceo_actor($viewer), $id]);
                ceo_audit($org, $viewer, 'access_revoked', $row['subject'] === 'position' ? 'Position: ' . $row['position'] : 'Person #' . $row['person_id']);
                $db->commit();
            } catch (Throwable $e) {
                $db->rollback();
                throw $e;
            }
            ceo_api_out(['ok' => true, 'rows' => ceo_access_rows($org)]);

        case 'profile_save':
            ceo_need_owner($a);
            $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 120);
            if ($name === '') {
                ceo_api_fail('Give the profile a name.');
            }
            $scope = ceo_scope_clean((array) ($in['scope'] ?? []));
            if ($scope['spokes'] !== '*') {
                $scope['spokes'] = array_values(array_intersect($scope['spokes'], array_keys(ceo_spokes($org))));
                if (!$scope['spokes']) {
                    ceo_api_fail('Choose at least one hub or spoke, or All.');
                }
            }
            if (!$scope['modules']) {
                ceo_api_fail('Choose at least one module.');
            }
            $id = (int) ($in['id'] ?? 0);
            $profiles = ceo_profiles($org);
            if ($id > 0 && !isset($profiles[$id])) {
                ceo_api_fail('That profile is not there.', 404);
            }
            $desc = mb_substr(trim((string) ($in['description'] ?? '')), 0, 255);
            $db->begin_transaction();
            try {
                if ($id > 0) {
                    fabo_exec('UPDATE ceo_profiles SET name = ?, description = ?, scope_json = ? WHERE id = ? AND org_id = ?', 'sssii', [$name, $desc, json_encode($scope), $id, $org]);
                } else {
                    $id = fabo_exec('INSERT INTO ceo_profiles (org_id, name, description, scope_json, created_by) VALUES (?,?,?,?,?)', 'issss', [$org, $name, $desc, json_encode($scope), ceo_actor($viewer)]);
                }
                ceo_audit($org, $viewer, 'profile_saved', $name, ['scope' => $scope, 'before' => $profiles[$id]['scope'] ?? null]);
                $db->commit();
            } catch (Throwable $e) {
                $db->rollback();
                throw $e;
            }
            ceo_api_out(['ok' => true, 'profiles' => array_values(ceo_profiles($org)), 'rows' => ceo_access_rows($org)]);

        case 'profile_delete':
            ceo_need_owner($a);
            $id = (int) ($in['id'] ?? 0);
            $profiles = ceo_profiles($org);
            if (!isset($profiles[$id])) {
                ceo_api_fail('That profile is not there.', 404);
            }
            if (fabo_one('SELECT id FROM ceo_access WHERE org_id = ? AND profile_id = ? AND status = "active" LIMIT 1', 'ii', [$org, $id])) {
                ceo_api_fail('People still hold this profile. Move them to another one first.');
            }
            fabo_exec('UPDATE ceo_profiles SET deleted_at = NOW() WHERE id = ? AND org_id = ?', 'ii', [$id, $org]);
            ceo_audit($org, $viewer, 'profile_deleted', (string) $profiles[$id]['name']);
            ceo_api_out(['ok' => true, 'profiles' => array_values(ceo_profiles($org))]);

        case 'target_save':
            ceo_need($a, 'targets');
            $kpi = (string) ($in['kpi'] ?? '');
            $def = ceo_kpis()[$kpi] ?? null;
            if (!$def || !in_array($def['module'], $a['modules'], true)) {
                ceo_api_fail('Choose a KPI you have access to.');
            }
            if ($def['sensitive'] !== null && !in_array($def['sensitive'], $a['sensitive'], true)) {
                ceo_api_fail('That KPI needs access you do not hold.', 403);
            }
            $period = (string) ($in['period'] ?? '');
            if (!in_array($period, $def['periods'], true)) {
                ceo_api_fail('That KPI is not measured over that period.');
            }
            $spoke = (string) ($in['spoke'] ?? '');
            if ($spoke !== '' && (!isset(ceo_spokes($org)[$spoke]) || !$def['spoke'])) {
                ceo_api_fail('That KPI is not recorded for this spoke.');
            }
            if (!ceo_allowed_spoke($a, $spoke)) {
                ceo_api_fail('That scope is outside your access.', 403);
            }
            if (!is_numeric($in['target'] ?? null) || (float) $in['target'] < 0) {
                ceo_api_fail('Enter the target as a number.');
            }
            $owner = mb_substr(trim((string) ($in['owner'] ?? '')), 0, 160);
            fabo_exec('INSERT INTO ceo_targets (org_id, kpi_key, period_type, spoke_key, target_value, owner_label, active, created_by) VALUES (?,?,?,?,?,?,1,?)
                       ON DUPLICATE KEY UPDATE target_value = VALUES(target_value), owner_label = VALUES(owner_label), active = 1', 'isssdss',
                      [$org, $kpi, $period, $spoke, (float) $in['target'], $owner, ceo_actor($viewer)]);
            ceo_audit($org, $viewer, 'target_saved', $def['label'], ['period' => $period, 'spoke' => $spoke, 'target' => (float) $in['target']]);
            ceo_api_out(['ok' => true]);

        case 'target_delete':
            ceo_need($a, 'targets');
            $id = (int) ($in['id'] ?? 0);
            $t = fabo_one('SELECT * FROM ceo_targets WHERE id = ? AND org_id = ?', 'ii', [$id, $org]);
            if (!$t || !ceo_allowed_spoke($a, (string) $t['spoke_key'])) {
                ceo_api_fail('That target is not there.', 404);
            }
            fabo_exec('UPDATE ceo_targets SET active = 0 WHERE id = ?', 'i', [$id]);
            ceo_audit($org, $viewer, 'target_removed', (string) $t['kpi_key'], ['period' => $t['period_type'], 'spoke' => $t['spoke_key']]);
            ceo_api_out(['ok' => true]);

        case 'schedule_save':
            ceo_need($a, 'report');
            $kind = (string) ($in['kind'] ?? '');
            $freq = (string) ($in['frequency'] ?? '');
            if (!in_array($kind, ['daily', 'weekly', 'monthly', 'quarterly', 'annual', 'pending', 'targets'], true) || !in_array($freq, ['daily', 'weekly', 'monthly', 'quarterly'], true)) {
                ceo_api_fail('Choose a report and how often.');
            }
            $spoke = (string) ($in['spoke'] ?? '');
            if ($spoke !== '' && !isset(ceo_spokes($org)[$spoke])) {
                ceo_api_fail('That hub or spoke is not known.');
            }
            if (!ceo_allowed_spoke($a, $spoke)) {
                ceo_api_fail('That scope is outside your access.', 403);
            }
            $ok = [];
            foreach ((array) ($in['recipients'] ?? []) as $r) {
                $p = ceo_person_in_org((int) $r, $org);
                $pa = $p ? ceo_access(fabo_person_viewer($p), $org) : null;
                if (!$pa || !ceo_may($pa, 'report')) {
                    ceo_api_fail(($p['name'] ?? 'One recipient') . ' does not have report access. Only people authorised for reports can receive them.');
                }
                $ok[] = (int) $r;
            }
            if (!$ok) {
                ceo_api_fail('Choose at least one recipient.');
            }
            $next = ceo_next_run($freq, ceo_today());
            fabo_exec('INSERT INTO ceo_schedules (org_id, kind, frequency, spoke_key, recipients_json, next_run, created_by) VALUES (?,?,?,?,?,?,?)', 'issssss',
                      [$org, $kind, $freq, $spoke, json_encode(array_values(array_unique($ok))), $next, ceo_actor($viewer)]);
            ceo_audit($org, $viewer, 'schedule_saved', ceo_report_kinds()[$kind], ['frequency' => $freq, 'recipients' => $ok, 'spoke' => $spoke]);
            ceo_api_out(['ok' => true]);

        case 'schedule_delete':
            ceo_need($a, 'report');
            $id = (int) ($in['id'] ?? 0);
            $s = fabo_one('SELECT * FROM ceo_schedules WHERE id = ? AND org_id = ?', 'ii', [$id, $org]);
            if (!$s) {
                ceo_api_fail('That schedule is not there.', 404);
            }
            fabo_exec('DELETE FROM ceo_schedules WHERE id = ?', 'i', [$id]);
            ceo_audit($org, $viewer, 'schedule_removed', (string) $s['kind']);
            ceo_api_out(['ok' => true]);

        case 'prefs_save':
            ceo_need($a, 'configure');
            $known = array_merge(array_keys(ceo_kpis()), array_keys(ceo_rollups()));
            $cards = array_values(array_intersect(array_map('strval', (array) ($in['cards'] ?? [])), $known));
            $orgWide = !empty($in['org_default']) && !empty($a['owner']);
            if ($orgWide) {
                ceo_setting_put($org, 'default_cards', json_encode($cards));
            } else {
                fabo_exec('INSERT INTO ceo_prefs (org_id, person_id, prefs_json) VALUES (?,?,?) ON DUPLICATE KEY UPDATE prefs_json = VALUES(prefs_json)', 'iis', [$org, $pid, json_encode(['cards' => $cards])]);
            }
            ceo_audit($org, $viewer, 'layout_saved', $orgWide ? 'Organisation default' : 'Personal', ['cards' => count($cards)]);
            ceo_api_out(['ok' => true]);
    }
    ceo_api_fail('Unknown action.', 404);
}

/* ================================================================ reads */

// Nothing below changes the session, and a held session would queue this person's other requests.
session_write_close();
$force = !empty($_GET['force']);
if ($force) {
    if (fabo_throttle_blocked('ceo-force', $key, 8, 60)) {
        $force = false;
    } else {
        fabo_throttle_hit('ceo-force', $key);
    }
}
$f = ceo_filters($a, $org);

try {
    switch ($route) {
        case 'boot':
            $spokes = array_values(array_filter(ceo_spokes($org), static fn($s) => ceo_allowed_spoke($a, $s['key'])));
            $mods = [];
            foreach ($a['modules'] as $m) {
                $mods[] = ['key' => $m, 'label' => ceo_modules()[$m]['label'], 'color' => ceo_modules()[$m]['color'], 'state' => ceo_module_state($org, $m)];
            }
            $depts = ceo_has_table('hr_departments') ? fabo_all('SELECT id, name FROM hr_departments WHERE org_id = ? AND active = 1 ORDER BY name', 'i', [$org]) : [];
            ceo_api_out(['ok' => true, 'spokes' => $spokes, 'modules' => $mods, 'depts' => $depts, 'action_types' => ceo_action_types(),
                         'access' => ['owner' => !empty($a['owner']), 'via' => $a['via'], 'profile' => $a['profile'], 'actions' => $a['actions'], 'sensitive' => $a['sensitive'], 'limited' => $a['spokes'] !== '*'],
                         'today' => ceo_today(), 'refresh' => ['live' => 30, 'kpi' => CEO_TTL]]);

        case 'overview':
            $live = ceo_live($viewer, $org, $a, $f, $force);
            ceo_api_out(['ok' => true] + $live + [
                'trend' => ceo_trend($org, $a, $f), 'funnel' => ceo_funnel($org, $a, $f), 'departments' => ceo_departments($viewer, $org, $a, $f),
                'hub' => ceo_hub_spoke($viewer, $org, $a, $f), 'targets' => ceo_targets_eval($viewer, $org, $a, $f),
            ]);

        case 'live':
            register_shutdown_function(static function () use ($org) {
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }
                try {
                    ceo_tick($org);
                } catch (Throwable $e) {
                    error_log('FABO ceo tick: ' . $e->getMessage());
                }
            });
            ceo_api_out(['ok' => true] + ceo_live($viewer, $org, $a, $f, $force));

        case 'spokes':
            ceo_api_out(['ok' => true, 'hub' => ceo_hub_spoke($viewer, $org, $a, $f), 'targets' => ceo_targets_eval($viewer, $org, $a, $f), 'departments' => ceo_departments($viewer, $org, $a, $f), 'server_time' => date('Y-m-d H:i:s')]);

        case 'actions':
            $r = ceo_actions($viewer, $org, $a, $f, $force);
            $page = max(1, (int) ($_GET['page'] ?? 1));
            $per = 40;
            $r['pages'] = max(1, (int) ceil($r['total'] / $per));
            $r['page'] = min($page, $r['pages']);
            $r['items'] = array_slice($r['items'], ($r['page'] - 1) * $per, $per);
            $r['coverage'] = ceo_coverage($org, $a, $f);
            ceo_api_out(['ok' => true, 'server_time' => date('Y-m-d H:i:s')] + $r);

        case 'activity':
            ceo_api_out(['ok' => true, 'server_time' => date('Y-m-d H:i:s')] + ceo_feed($viewer, $org, $a, $f, $force, 150));

        case 'targets':
            $defs = [];
            foreach (ceo_kpis() as $k => $d) {
                if (in_array($d['module'], $a['modules'], true) && ($d['sensitive'] === null || in_array($d['sensitive'], $a['sensitive'], true))) {
                    $defs[] = ['key' => $k, 'label' => $d['label'], 'module' => ceo_modules()[$d['module']]['label'], 'unit' => $d['unit'], 'periods' => $d['periods'], 'spoke' => $d['spoke']];
                }
            }
            ceo_api_out(['ok' => true, 'targets' => ceo_targets_eval($viewer, $org, $a, $f), 'kpis' => $defs, 'server_time' => date('Y-m-d H:i:s')]);

        case 'reports':
            ceo_need($a, 'report');
            $recipients = [];
            foreach (ceo_people($org) as $p) {
                $full = ceo_person_in_org((int) $p['id'], $org);
                $pa = $full ? ceo_access(fabo_person_viewer($full), $org) : null;
                if ($pa && ceo_may($pa, 'report')) {
                    $recipients[] = ['id' => (int) $p['id'], 'name' => $p['name'], 'email' => $p['email']];
                }
            }
            $names = array_column($recipients, 'name', 'id');
            $schedules = [];
            foreach (fabo_all('SELECT * FROM ceo_schedules WHERE org_id = ? ORDER BY created_at DESC', 'i', [$org]) as $s) {
                $s['recipient_names'] = array_values(array_map(static fn($i) => $names[(int) $i] ?? 'No longer authorised', (array) json_decode((string) $s['recipients_json'], true)));
                unset($s['recipients_json']);
                $s['kind_label'] = ceo_report_kinds()[$s['kind']] ?? $s['kind'];
                $schedules[] = $s;
            }
            ceo_api_out(['ok' => true, 'kinds' => ceo_report_kinds(), 'recipients' => $recipients, 'schedules' => $schedules, 'can_export' => ceo_may($a, 'export'),
                         'history' => fabo_all('SELECT kind, format, date_from, date_to, spoke_key, actor, created_at FROM ceo_reports WHERE org_id = ? ORDER BY id DESC LIMIT 30', 'i', [$org]),
                         'mail_ready' => function_exists('fabo_mail_configured') ? fabo_mail_configured() : true]);

        case 'report':
            ceo_need($a, 'report');
            $kind = (string) ($_GET['kind'] ?? 'custom');
            if (!isset(ceo_report_kinds()[$kind])) {
                ceo_api_fail('Choose a report.');
            }
            $r = ceo_report_build($viewer, $org, $a, $kind, $f);
            $r['activity'] = array_slice($r['activity'], 0, 30);
            $r['pending'] = array_slice($r['pending'], 0, 30);
            ceo_api_out(['ok' => true, 'report' => $r]);

        case 'export':
            ceo_need($a, 'export');
            if (fabo_throttle_blocked('ceo-export', $key, 30, 3600)) {
                ceo_api_fail('Too many exports this hour. Try again later.', 429);
            }
            fabo_throttle_hit('ceo-export', $key);
            $kind = (string) ($_GET['kind'] ?? 'custom');
            $fmt = (string) ($_GET['format'] ?? 'xlsx');
            if (!isset(ceo_report_kinds()[$kind]) || !in_array($fmt, ['xlsx', 'pdf'], true)) {
                ceo_api_fail('Choose a report and a format.');
            }
            $r = ceo_report_build($viewer, $org, $a, $kind, $f);
            ceo_report_logged($org, $viewer, $r, $fmt, $f['spoke']);
            $name = 'CEO-' . preg_replace('/[^A-Za-z0-9]+/', '-', $r['title']) . '-' . $r['from'] . '-to-' . $r['to'];
            if ($fmt === 'pdf') {
                ceo_report_pdf($r)->send($name . '.pdf');
            }
            ceo_report_xlsx($r)->download($name . '.xlsx');
            exit;

        case 'access':
            ceo_need_owner($a);
            $self = fabo_one('SELECT * FROM ceo_access WHERE org_id = ? AND subject = "person" AND person_id = ? AND status = "active"', 'ii', [$org, $pid]);
            ceo_api_out(['ok' => true, 'rows' => ceo_access_rows($org), 'profiles' => array_values(ceo_profiles($org)), 'positions' => ceo_positions($org),
                         'sensitive' => ceo_sensitive(), 'actions' => ceo_actions_all(), 'modules' => ceo_modules(), 'spokes' => array_values(ceo_spokes($org)), 'self_id' => $pid, 'self_profile' => (int) ($self['profile_id'] ?? 0), 'is_admin' => !empty($viewer['is_admin'])]);

        case 'people':
            ceo_need_owner($a);
            ceo_api_out(['ok' => true, 'people' => array_values(ceo_people($org, mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 80), 25))]);

        case 'assignees':
            ceo_api_out(['ok' => true, 'people' => array_values(array_map(static fn($p) => ['id' => (int) $p['id'], 'name' => $p['name']], ceo_people($org, '', 500)))]);

        case 'position':
            ceo_need_owner($a);
            $position = trim((string) ($_GET['name'] ?? ''));
            $people = ceo_position_people($org, $position);
            ceo_api_out(['ok' => true, 'people' => $people, 'confirm' => ceo_position_hash($people)]);

        case 'registry':
            ceo_need($a, 'configure');
            $rows = [];
            foreach (ceo_kpis() as $k => $d) {
                $rows[] = ['key' => $k, 'label' => $d['label'], 'module' => ceo_modules()[$d['module']]['label'], 'color' => ceo_modules()[$d['module']]['color'], 'unit' => $d['unit'], 'kind' => $d['kind'], 'calc' => $d['calc'], 'source' => $d['source'], 'spoke' => $d['spoke'],
                           'sensitive' => $d['sensitive'] !== null ? ceo_sensitive()[$d['sensitive']][0] : '', 'refresh' => CEO_TTL[$d['refresh']], 'periods' => $d['periods']];
            }
            foreach (ceo_rollups() as $k => [$label, , $calc]) {
                $rows[] = ['key' => $k, 'label' => $label, 'module' => 'All modules', 'color' => 0, 'unit' => 'count', 'kind' => 'stock', 'calc' => $calc, 'source' => 'Sum of the module sources', 'spoke' => false, 'sensitive' => '', 'refresh' => 60, 'periods' => []];
            }
            $cards = json_decode((string) (fabo_one('SELECT prefs_json FROM ceo_prefs WHERE org_id = ? AND person_id = ?', 'ii', [$org, $pid])['prefs_json'] ?? ''), true);
            ceo_api_out(['ok' => true, 'kpis' => $rows, 'coverage' => ceo_coverage($org, $a, $f), 'cards' => $cards['cards'] ?? (json_decode(ceo_setting($org, 'default_cards', ''), true) ?: ceo_default_cards())]);

        case 'audit':
            ceo_need($a, 'configure');
            ceo_api_out(['ok' => true, 'rows' => fabo_all('SELECT actor, action, subject, detail_json, ip, created_at FROM ceo_audit WHERE org_id = ? ORDER BY id DESC LIMIT 200', 'i', [$org])]);
    }
    ceo_api_fail('Unknown request.', 404);
} catch (Throwable $e) {
    error_log('FABO ceo api ' . $route . ': ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
    ceo_api_fail('That could not be loaded. Try again in a moment.', 500);
}

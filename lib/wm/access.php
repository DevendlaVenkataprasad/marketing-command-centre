<?php
/**
 * Work Management → Access (administrator only).
 *
 * A member is an existing portal account: one of our staff for the Team role,
 * a client account for the Client role. Inviting somebody new creates that
 * account the ordinary way (a row in clients, as Team Management and Clients
 * do) and sends a one-time link to choose their password (password_invites,
 * lib/invite.php). They then sign in at the portal's own login page.
 *
 * Deactivating or revoking takes effect on their next request: wm_ctx() reads
 * the membership fresh every time, so there is no session to wait out.
 */

require_once __DIR__ . '/core.php';
require_once __DIR__ . '/../invite.php';
require_once __DIR__ . '/../throttle.php';
require_once __DIR__ . '/../brand.php';

/** Everyone with a membership, plus their departments and shares. */
function wm_members(array $ctx): array
{
    wm_need_admin($ctx);
    $rows = fabo_all("SELECT m.client_id AS id, m.role, m.is_active, m.can_comment, m.invited_at, m.added_at,
                             c.name, c.email, c.kind, c.parent_id, c.revoked_at, c.last_login_at,
                             (c.password_hash IS NOT NULL AND c.password_hash <> '') AS has_password
                      FROM wm_members m JOIN clients c ON c.id = m.client_id ORDER BY m.is_active DESC, m.role, c.name");
    $ids = array_map(static fn($r) => (int) $r['id'], $rows);
    $depts = $shares = [];
    if ($ids) {
        $in = implode(',', $ids);
        foreach (fabo_all("SELECT user_id, department_id FROM wm_user_department_access WHERE user_id IN ($in)") as $r) {
            $depts[(int) $r['user_id']][] = (int) $r['department_id'];
        }
        foreach (fabo_all("SELECT s.client_user_id, s.task_id FROM wm_client_task_shares s JOIN wm_tasks t ON t.id = s.task_id
                           WHERE t.deleted_at IS NULL AND s.client_user_id IN ($in)") as $r) {
            $shares[(int) $r['client_user_id']][] = (int) $r['task_id'];
        }
    }
    foreach ($rows as &$r) {
        $id = (int) $r['id'];
        $r['id'] = $id;
        $r['is_active'] = (int) $r['is_active'] === 1;
        $r['can_comment'] = (int) $r['can_comment'] === 1;
        $r['has_password'] = (int) $r['has_password'] === 1;
        $r['account_ok'] = $r['revoked_at'] === null && $r['kind'] === $r['role'] && $r['parent_id'] === null;
        $r['depts'] = $depts[$id] ?? [];
        $r['shares'] = $shares[$id] ?? [];
        $live = fabo_invite_live($id);
        $r['invite_pending'] = (bool) $live;
        unset($r['kind'], $r['parent_id'], $r['revoked_at']);
    }
    unset($r);
    return $rows;
}

/** Portal accounts that could be given access but are not members yet. */
function wm_candidates(array $ctx): array
{
    wm_need_admin($ctx);
    return fabo_all("SELECT c.id, c.name, c.email, c.kind FROM clients c
                     LEFT JOIN wm_members m ON m.client_id = c.id
                     WHERE m.client_id IS NULL AND c.parent_id IS NULL AND c.kind IN ('team','client') AND c.revoked_at IS NULL
                     ORDER BY c.kind DESC, c.name LIMIT 1000");
}

function wm_member_row(int $id): ?array
{
    return fabo_one('SELECT m.*, c.name, c.email, c.kind, c.password_hash FROM wm_members m JOIN clients c ON c.id = m.client_id
                     WHERE m.client_id = ?', 'i', [$id]);
}

/** Department ids (validated) from input. */
function wm_dept_ids_in($v): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', is_array($v) ? $v : []))));
    if (!$ids) {
        return [];
    }
    $have = array_map(static fn($r) => (int) $r['id'],
        fabo_all('SELECT id FROM wm_departments WHERE id IN (' . implode(',', $ids) . ')'));
    if (count($have) !== count($ids)) {
        wm_fail('One of those departments was not found.', 404);
    }
    return $have;
}

function wm_task_ids_in($v): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', is_array($v) ? $v : []))));
    if (!$ids) {
        return [];
    }
    $have = array_map(static fn($r) => (int) $r['id'],
        fabo_all('SELECT id FROM wm_tasks WHERE deleted_at IS NULL AND id IN (' . implode(',', $ids) . ')'));
    if (count($have) !== count($ids)) {
        wm_fail('One of those tasks was not found.', 404);
    }
    return $have;
}

/**
 * Invites (or adds) a member. $in: role, and either person_id (an existing
 * account) or name + email (a new one); depts[], shares[] (client), can_comment.
 * Returns ['member' => row, 'link' => invite or sign-in link, 'mailed' => bool, 'mail_detail' => string].
 */
function wm_member_invite(array $ctx, array $in): array
{
    wm_need_admin($ctx);
    $ipKey = 'admin:' . fabo_client_ip();
    if (fabo_throttle_blocked('wm-invite', $ipKey, 30, 3600)) {
        wm_fail('Too many invitations in the last hour. Wait a little and try again.', 429);
    }
    $role = (string) ($in['role'] ?? '');
    if (!in_array($role, ['team', 'client'], true)) {
        wm_fail('Choose a role: Team or Client.');
    }
    $depts = wm_dept_ids_in($in['depts'] ?? []);
    $shares = $role === 'client' ? wm_task_ids_in($in['shares'] ?? []) : [];
    $personId = (int) ($in['person_id'] ?? 0);
    $created = false;
    if ($personId > 0) {
        $p = fabo_one('SELECT * FROM clients WHERE id = ? AND parent_id IS NULL', 'i', [$personId]);
        if (!$p || $p['kind'] !== $role) {
            wm_fail($role === 'team' ? 'Choose one of our team members.' : 'Choose a client account.', 404);
        }
        if ($p['revoked_at'] !== null) {
            wm_fail('That account is revoked. Restore it on ' . ($role === 'team' ? 'Team Management' : 'Clients') . ' first.');
        }
    } else {
        $name = wm_str($in['name'] ?? '', 160, true, 'Name');
        $email = mb_strtolower(wm_str($in['email'] ?? '', 190, true, 'Email'));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            wm_fail('That email address is not valid.');
        }
        // The same person invited twice is the same account, not a second one.
        $p = fabo_one('SELECT * FROM clients WHERE LOWER(email) = ? AND kind = ? AND parent_id IS NULL AND revoked_at IS NULL ORDER BY id LIMIT 1',
                      'ss', [$email, $role]);
        if (!$p) {
            $personId = fabo_exec('INSERT INTO clients (name, kind, email, contact_note, token, expires_at) VALUES (?,?,?,?,?,NULL)',
                                  'sssss', [$name, $role, $email, 'Added from Work Management', fabo_new_token()]);
            $p = fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [$personId]);
            $created = true;
        }
        $personId = (int) $p['id'];
    }
    if (fabo_one('SELECT client_id FROM wm_members WHERE client_id = ?', 'i', [$personId])) {
        wm_fail('This person already has Work access. Edit them in the list instead.', 409);
    }
    fabo_throttle_hit('wm-invite', $ipKey);
    $now = wm_now();
    $db = fabo_db();
    $db->begin_transaction();
    try {
        fabo_exec('INSERT INTO wm_members (client_id, role, is_active, can_comment, invited_at, added_at, updated_at) VALUES (?,?,1,?,?,?,?)',
                  'isisss', [$personId, $role, !empty($in['can_comment']) && $role === 'client' ? 1 : 0, $now, $now, $now]);
        wm_set_depts($personId, $depts);
        wm_set_shares($personId, $shares);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
    wm_audit($ctx, 'user_invited', (string) $p['name'], null,
             ['person' => $personId, 'role' => $role, 'new_account' => $created, 'depts' => $depts, 'shares' => $shares]);
    $sent = wm_send_invite($personId);
    return ['member' => wm_member_find($ctx, $personId)] + $sent;
}

function wm_member_find(array $ctx, int $id): array
{
    foreach (wm_members($ctx) as $m) {
        if ($m['id'] === $id) {
            return $m;
        }
    }
    wm_fail('That person was not found.', 404);
}

function wm_set_depts(int $personId, array $depts): void
{
    fabo_exec('DELETE FROM wm_user_department_access WHERE user_id = ?', 'i', [$personId]);
    foreach ($depts as $d) {
        fabo_exec('INSERT INTO wm_user_department_access (user_id, department_id) VALUES (?,?)', 'ii', [$personId, (int) $d]);
    }
}

function wm_set_shares(int $personId, array $tasks): void
{
    fabo_exec('DELETE FROM wm_client_task_shares WHERE client_user_id = ?', 'i', [$personId]);
    foreach ($tasks as $t) {
        fabo_exec('INSERT INTO wm_client_task_shares (client_user_id, task_id) VALUES (?,?)', 'ii', [$personId, (int) $t]);
    }
}

/**
 * Sends (or re-sends) the invitation. Someone without a password yet gets a
 * one-time link to choose one (expires in WM_INVITE_DAYS); someone who already
 * signs in gets a note with the sign-in address. Either way the link comes
 * back so the administrator can pass it on when mail is not set up.
 */
function wm_send_invite(int $personId): array
{
    require_once __DIR__ . '/../mailer.php';
    require_once __DIR__ . '/../mailtpl.php';
    require_once __DIR__ . '/../ui.php';
    $m = wm_member_row($personId);
    if (!$m) {
        wm_fail('That person was not found.', 404);
    }
    $base = rtrim(fabo_portal_url(), '/') . '/';
    $hasPw = (string) ($m['password_hash'] ?? '') !== '';
    $link = $hasPw ? $base . 'login.php?next=' . rawurlencode('work.php') : fabo_invite_link($personId, WM_INVITE_DAYS);
    if ($link === '') {
        wm_fail('The invitation link could not be made. Try again.', 500);
    }
    fabo_exec('UPDATE wm_members SET invited_at = ?, updated_at = ? WHERE client_id = ?', 'ssi', [wm_now(), wm_now(), $personId]);
    $roleWords = $m['role'] === 'team' ? 'to work on tasks' : 'to follow the work we are doing for you';
    $inner = fabo_mail_p('Hello ' . e((string) $m['name']) . ',')
           . fabo_mail_p('You have been given access to <b>Work Management</b> ' . e($roleWords) . '.')
           . ($hasPw
               ? fabo_mail_p('Sign in with your usual email and password, then open Work Management from the menu.')
               : fabo_mail_p('Use the button below to choose your password. The link works once and expires in ' . WM_INVITE_DAYS . ' days.'))
           . fabo_mail_own_link($link, $hasPw ? 'Sign in' : 'Choose your password');
    $html = fabo_mail_wrap(fabo_app_name(), 'Work Management access', $inner, 'You have been given access to Work Management');
    $text = "Hello {$m['name']},\n\nYou have been given access to Work Management {$roleWords}.\n\n"
          . ($hasPw ? "Sign in here: $link\n" : "Choose your password here (works once, expires in " . WM_INVITE_DAYS . " days):\n$link\n");
    $email = (string) ($m['email'] ?? '');
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        [$ok, $detail] = [false, 'no valid email address on file'];
    } elseif (!empty($GLOBALS['wm_mail_hook']) && is_callable($GLOBALS['wm_mail_hook'])) {
        [$ok, $detail] = ($GLOBALS['wm_mail_hook'])($email, 'Your Work Management access', $text, $html);
    } elseif (!fabo_mail_configured()) {
        [$ok, $detail] = [false, 'email is not configured on this server'];
    } else {
        [$ok, $detail] = fabo_smtp_send($email, 'Your Work Management access', $text, null, $html);
    }
    return ['link' => $link, 'mailed' => (bool) $ok, 'mail_detail' => (string) $detail, 'kind' => $hasPw ? 'signin' : 'invite'];
}

/** Saves role-independent settings: departments, shares, can_comment. */
function wm_member_update(array $ctx, int $id, array $in): array
{
    wm_need_admin($ctx);
    $m = wm_member_row($id);
    if (!$m) {
        wm_fail('That person was not found.', 404);
    }
    $depts = wm_dept_ids_in($in['depts'] ?? []);
    $shares = $m['role'] === 'client' ? wm_task_ids_in($in['shares'] ?? []) : [];
    $before = wm_member_find($ctx, $id);
    $db = fabo_db();
    $db->begin_transaction();
    try {
        wm_set_depts($id, $depts);
        wm_set_shares($id, $shares);
        fabo_exec('UPDATE wm_members SET can_comment = ?, updated_at = ? WHERE client_id = ?', 'isi',
                  [$m['role'] === 'client' && !empty($in['can_comment']) ? 1 : 0, wm_now(), $id]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
    $after = wm_member_find($ctx, $id);
    wm_audit($ctx, 'access_changed', (string) $m['name'], null, [
        'person' => $id, 'depts' => [$before['depts'], $after['depts']], 'shares' => [$before['shares'], $after['shares']],
        'can_comment' => [$before['can_comment'], $after['can_comment']]]);
    return $after;
}

/** Turns access on or off. $revoke also clears their departments and shares. */
function wm_member_active(array $ctx, int $id, bool $active, bool $revoke = false): array
{
    wm_need_admin($ctx);
    $m = wm_member_row($id);
    if (!$m) {
        wm_fail('That person was not found.', 404);
    }
    fabo_exec('UPDATE wm_members SET is_active = ?, updated_at = ? WHERE client_id = ?', 'isi', [$active ? 1 : 0, wm_now(), $id]);
    if ($revoke) {
        wm_set_depts($id, []);
        wm_set_shares($id, []);
    }
    wm_audit($ctx, $revoke ? 'access_revoked' : ($active ? 'user_activated' : 'user_deactivated'), (string) $m['name'], null, ['person' => $id]);
    return wm_member_find($ctx, $id);
}

/** Shares or unshares one task with clients (from the task panel). */
function wm_task_shares_set(array $ctx, int $taskId, array $clientIds): array
{
    wm_need_admin($ctx);
    $t = wm_task_get($ctx, $taskId);
    $ids = array_values(array_unique(array_filter(array_map('intval', $clientIds))));
    if ($ids) {
        $ok = array_map(static fn($r) => (int) $r['client_id'],
            fabo_all("SELECT client_id FROM wm_members WHERE role = 'client' AND client_id IN (" . implode(',', $ids) . ')'));
        if (count($ok) !== count($ids)) {
            wm_fail('One of those clients was not found.', 404);
        }
    }
    $before = array_map(static fn($r) => (int) $r['client_user_id'],
        fabo_all('SELECT client_user_id FROM wm_client_task_shares WHERE task_id = ?', 'i', [$taskId]));
    fabo_exec('DELETE FROM wm_client_task_shares WHERE task_id = ?', 'i', [$taskId]);
    foreach ($ids as $c) {
        fabo_exec('INSERT INTO wm_client_task_shares (client_user_id, task_id) VALUES (?,?)', 'ii', [$c, $taskId]);
    }
    sort($before);
    sort($ids);
    if ($before !== $ids) {
        wm_audit($ctx, 'access_changed', (string) $t['task_code'], $taskId, ['shared_with' => [$before, $ids]]);
    }
    return $ids;
}

function wm_audit_list(array $ctx, int $limit = 200): array
{
    wm_need_admin($ctx);
    return fabo_all('SELECT id, at, actor_name, action, task_id, target, detail FROM wm_audit ORDER BY id DESC LIMIT ' . max(1, min(1000, $limit)));
}

/**
 * Team Management's Work Management switch for one of our staff: $on gives
 * them the Team role (adding the membership and sending the usual note the
 * first time, re-activating it after), with exactly $depts; off deactivates
 * it and keeps their departments, so switching back on restores them.
 * Returns a short sentence for the page's flash, '' when nothing changed.
 */
function wm_team_member_set(array $ctx, int $personId, bool $on, array $depts): string
{
    wm_need_admin($ctx);
    $m = wm_member_row($personId);
    if ($m && $m['role'] !== 'team') {
        return '';
    }
    if (!$on) {
        if ($m && (int) $m['is_active'] === 1) {
            wm_member_active($ctx, $personId, false);
            return ' Work Management access turned off.';
        }
        return '';
    }
    if (!$m) {
        $r = wm_member_invite($ctx, ['role' => 'team', 'person_id' => $personId, 'depts' => $depts]);
        return ' Work Management access given' . ($r['mailed'] ? ' and emailed.' : ' (email not sent: ' . $r['mail_detail'] . ').');
    }
    $before = wm_member_find($ctx, $personId);
    $after = wm_member_update($ctx, $personId, ['depts' => $depts]);
    $note = '';
    if ((int) $m['is_active'] !== 1) {
        wm_member_active($ctx, $personId, true);
        $note = ' Work Management access turned back on.';
    }
    $b = $before['depts'];
    $a = $after['depts'];
    sort($b);
    sort($a);
    return $note !== '' ? $note : ($a !== $b ? ' Work Management departments updated.' : '');
}

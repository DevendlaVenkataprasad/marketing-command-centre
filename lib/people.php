<?php
/**
 * People a client runs themselves.
 *
 * A client can create their own clients and team members. Two rules keep that
 * safe, and both are enforced here rather than in the forms:
 *
 *   1. A parent can never hand out access they do not hold. Every permission
 *      is capped against their own, and the child inherits the parent's brand
 *      and business-unit scope, so a client cannot widen their own reach by
 *      creating an account and signing in as it.
 *   2. Invitations they send leave from their mailbox, not ours — see
 *      fabo_sender_for() in notify.php.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/brand.php';
require_once __DIR__ . '/notify.php';

/** The sheets a parent may delegate, and the strongest permission on each. */
function fabo_delegatable(array $viewer): array
{
    $cap = [];
    foreach (fabo_visible_sheets($viewer) as $key => $perm) {
        if (!empty(fabo_sheet($key)['admin_only'])) {
            continue;
        }
        $cap[$key] = $perm;   // 'view' or 'edit'
    }
    return $cap;
}

/** Caps one requested permission against what the parent actually holds. */
function fabo_cap_permission(string $want, string $cap): string
{
    if ($cap === 'none' || $want === 'none') {
        return 'none';
    }
    if ($want === 'edit' && $cap !== 'edit') {
        return 'view';
    }
    return $want === 'edit' ? 'edit' : 'view';
}

/**
 * Writes a child's sheet access, never exceeding the parent's own.
 * Returns the access map that was actually stored.
 */
function fabo_save_child_access(int $childId, array $wanted, array $cap): array
{
    /* Only the SHEET grants are rewritten here. Modules (Finance, Intake,
       CRM) are granted by the administrator, not by a parent, and deleting
       every row used to wipe them silently whenever a parent saved the page. */
    $sheetKeys = fabo_sheet_order();
    if ($sheetKeys) {
        $in = implode(',', array_fill(0, count($sheetKeys), '?'));
        fabo_exec('DELETE FROM client_sheet_access WHERE client_id = ? AND sheet_key IN (' . $in . ')',
                  'i' . str_repeat('s', count($sheetKeys)), array_merge([$childId], $sheetKeys));
    }
    $saved = [];
    foreach ($wanted as $key => $perm) {
        if (!isset($cap[$key])) {
            continue;                       // not the parent's to give
        }
        $perm = fabo_cap_permission((string) $perm, $cap[$key]);
        if ($key === 'dashboard' && $perm === 'edit') {
            $perm = 'view';                 // the Dashboard is read-only for everyone
        }
        if ($perm === 'view' || $perm === 'edit') {
            fabo_exec('INSERT INTO client_sheet_access (client_id, sheet_key, permission) VALUES (?,?,?)',
                      'iss', [$childId, $key, $perm]);
            $saved[$key] = $perm;
        }
    }
    return $saved;
}

/**
 * The modules a client may pass on. To their team ('team'): every module
 * marked 'team' in fabo_modules(), to run inside the client's own
 * organisation. To a client of their own ('client'): the same modules plus
 * the CEO Dashboard -- and there each is the new client's OWN, separate
 * module with its own data (a client's client is an organisation of its own;
 * see fabo_module_org()). Stored 'edit' reads as Owner.
 */
function fabo_child_module_keys(string $kind = 'team'): array
{
    $keys = array_keys(array_filter(fabo_modules(), static fn(array $m) => isset($m['team'])));
    if ($kind === 'client' && isset(fabo_modules()['ceo'])) {
        $keys[] = 'ceo';
    }
    return $keys;
}

/** What the desk calls each of them, and what it means: key => [name, description]. */
function fabo_child_module_labels(string $kind = 'team'): array
{
    $out = [];
    foreach (fabo_child_module_keys($kind) as $k) {
        $m = fabo_modules()[$k];
        $out[$k] = $kind === 'client'
            ? [preg_replace('/\s+—\s+their own$/u', '', (string) $m['label']), 'Their own, separate from yours: ' . lcfirst((string) ($m['hint'] ?? '')) . ' Owner runs it; View only reads it.']
            : $m['team'];
    }
    return $out;
}

/**
 * Modules a client may pass on ($kind 'team' to their own team members,
 * 'client' to clients of their own), never more than they hold now
 * themselves -- which is itself capped by every client above them.
 * key => strongest permission.
 */
function fabo_delegatable_modules(array $person, string $kind = 'team'): array
{
    if (($person['kind'] ?? 'client') !== 'client' || !empty($person['is_committee']) || empty($person['id'])) {
        return [];
    }
    $out = [];
    foreach (fabo_child_module_keys($kind) as $k) {
        $p = fabo_head_module_permission((int) $person['id'], $k);
        if ($p !== 'none') {
            $out[$k] = $p;
        }
    }
    return $out;
}

/** Writes a team member's or a client's module grants, capped by $modCap. Returns what was stored. */
function fabo_save_child_modules(int $childId, array $wanted, array $modCap, string $kind = 'team'): array
{
    $keys = fabo_child_module_keys($kind);
    fabo_exec('DELETE FROM client_sheet_access WHERE client_id = ? AND sheet_key IN ('
              . implode(',', array_fill(0, count($keys), '?')) . ')',
              'i' . str_repeat('s', count($keys)), array_merge([$childId], $keys));
    $saved = [];
    foreach ($modCap as $key => $cap) {
        $perm = fabo_cap_hr((string) ($wanted[$key] ?? 'none'), $cap);
        if ($perm !== 'none') {
            fabo_exec('INSERT INTO client_sheet_access (client_id, sheet_key, permission) VALUES (?,?,?)',
                      'iss', [$childId, $key, $perm]);
            $saved[$key] = $perm;
        }
    }
    return $saved;
}

/**
 * What a parent may hand on, read from their stored grants rather than from a
 * live viewer -- so it answers the same while the parent's link happens to be
 * expired, instead of collapsing to nothing and wiping every child's access.
 */
function fabo_cap_of(int $parentId): array
{
    $cap = [];
    foreach (fabo_client_access($parentId) as $key => $perm) {
        if (!in_array($key, fabo_sheet_order(), true) || !empty(fabo_sheet($key)['admin_only'])) {
            continue;
        }
        $cap[$key] = $key === 'dashboard' ? 'view' : $perm;
    }
    return $cap;
}

/**
 * Brings every account below $parentId back within what $parentId now holds:
 * sheet access, account rights, and link expiry. Run whenever the parent's own
 * access is reduced, because the caps used to be applied only at the moment of
 * granting -- take a sheet or a right away from a client and their
 * sub-accounts kept it.
 */
function fabo_recap_descendants(int $parentId, int $depth = 0): void
{
    if ($depth > 8) {
        return;
    }
    $parent = fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [$parentId]);
    if (!$parent) {
        return;
    }
    $cap = fabo_cap_of($parentId);
    foreach (fabo_all('SELECT * FROM clients WHERE parent_id = ?', 'i', [$parentId]) as $child) {
        $cid = (int) $child['id'];
        fabo_save_child_access($cid, fabo_client_access($cid), $cap);
        // Modules, on their own team members and on clients of their own, back within what the parent holds.
        if (($parent['kind'] ?? '') === 'client') {
            $ck = ($child['kind'] ?? '') === 'team' ? 'team' : 'client';
            fabo_save_child_modules($cid, fabo_client_access($cid), fabo_delegatable_modules($parent, $ck), $ck);
        }
        // Their current rights, re-saved through the parent's cap.
        fabo_save_rights($cid, $child, $parent);
        // A child's link never outlives its parent's.
        if (!empty($parent['expires_at'])
            && (empty($child['expires_at']) || strtotime($child['expires_at']) > strtotime($parent['expires_at']))) {
            fabo_exec('UPDATE clients SET expires_at = ? WHERE id = ?', 'si', [$parent['expires_at'], $cid]);
        }
        fabo_recap_descendants($cid, $depth + 1);
    }
}

/**
 * The other direction: sheets the administrator gives a client reach the
 * client's whole team. Each of the head's regular team members (not
 * committee-only accounts, whose portal is their committee; not sub-clients,
 * which are organisations of their own) is raised to the head's level on
 * each sheet in $keys, or on
 * every sheet the head holds when $keys is null. Sheets only: HR, IT,
 * Facilities, Incubation and CRM stay the head's to hand out person by
 * person. Nothing a member holds is lowered here -- taking away is
 * fabo_recap_descendants()'s job.
 *
 * Each member whose access changed is told, as the head is. Returns
 * [member id => [sheet key => permission given]].
 */
function fabo_flow_sheets_to_team(int $headId, ?array $keys = null, bool $tell = true): array
{
    $head = fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [$headId]);
    if (!$head || ($head['kind'] ?? '') !== 'client' || (int) ($head['parent_id'] ?? 0) !== 0) {
        return [];
    }
    $cap = fabo_cap_of($headId);                     // sheets only, Dashboard as view
    if ($keys !== null) {
        $cap = array_intersect_key($cap, array_flip($keys));
    }
    if (!$cap) {
        return [];
    }
    $rank = ['none' => 0, 'view' => 1, 'edit' => 2];
    $out = [];
    foreach (fabo_all("SELECT * FROM clients WHERE parent_id = ? AND kind = 'team' AND revoked_at IS NULL
                         AND (is_committee IS NULL OR is_committee = 0)", 'i', [$headId]) as $m) {
        $mid = (int) $m['id'];
        $has = fabo_client_access($mid);
        foreach ($cap as $key => $perm) {
            if (($rank[$has[$key] ?? 'none'] ?? 0) < ($rank[$perm] ?? 0)) {
                fabo_exec('INSERT INTO client_sheet_access (client_id, sheet_key, permission) VALUES (?,?,?)
                           ON DUPLICATE KEY UPDATE permission = VALUES(permission)', 'iss', [$mid, $key, $perm]);
                $out[$mid][$key] = $perm;
            }
        }
        if ($tell && !empty($out[$mid])) {
            try {
                $fresh = fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [$mid]);
                fabo_announce_access($fresh, fabo_client_access($mid), fabo_portal_url() . 'login.php');
            } catch (Throwable $e) {
                error_log('FABO team access notice: ' . $e->getMessage());
            }
        }
    }
    return $out;
}

/**
 * People one account has created, newest first. Pass 'client' or 'team' to
 * get just that half — the two are managed on separate pages, as they are in
 * the admin portal.
 */
function fabo_children(int $parentId, string $kind = ''): array
{
    if ($kind === 'client' || $kind === 'team') {
        return fabo_all('SELECT * FROM clients WHERE parent_id = ? AND kind = ?
                         ORDER BY created_at DESC', 'is', [$parentId, $kind]);
    }
    return fabo_all('SELECT * FROM clients WHERE parent_id = ? ORDER BY kind, created_at DESC',
                    'i', [$parentId]);
}

/** One of my people, or null — never anyone else's. */
function fabo_child(int $parentId, int $childId): ?array
{
    return fabo_one('SELECT * FROM clients WHERE id = ? AND parent_id = ?', 'ii', [$childId, $parentId]);
}

/* --------------------------------------------------------- account rights */

/*
 * The rights that are not a sheet. They are granted the same way sheet access
 * is — ticked on the person's page — and they mean the same thing for a client
 * and for a team member, so one definition serves every screen that shows them.
 */
function fabo_account_rights(): array
{
    return [
        'can_manage_clients' => [
            'Create their own clients',
            'Adds a <strong>My Clients</strong> tab. Whatever they hand out is capped at their own '
                . 'access and stays inside their brand and business-unit scope.',
        ],
        'can_manage_team' => [
            'Create their own team members',
            'Adds a <strong>My Team</strong> tab, for staff of their own. Granted separately from '
                . 'clients, because plenty of accounts should run a client list and no team at all.',
        ],
        'can_manage_sheets' => [
            'Manage their own sheets',
            'Adds a <strong>Sheet Management</strong> tab covering the sheets they can edit: '
                . 'what the columns are called, what the dropdowns offer, and columns of their '
                . 'own. Nothing they change there is seen by us or by another client.',
        ],
        /* Intake Forms is not a right here: it is granted in the "Sheet and
           module access" table, like Finance -- see fabo_modules(). */
        /* There was a "Manage their own requisition forms" right here. The
           forms desk now lives on the Requisition sheet, so access to that
           sheet is what grants it — a second permission governing half of
           one page was a distinction nobody asked for. The column stays in
           the table; nothing reads it. */
        'can_use_own_mail' => [
            'Send from their own mailbox',
            'Their invitations and approval requests leave from their own SMTP server. Without this '
                . 'the portal still sends for them, under their organisation name.',
        ],
    ];
}

/**
 * May this account create people, and of which kind?
 *
 * $kind is 'client', 'team', or '' for either. One function rather than a
 * test repeated in eight places, because the eight places drifting apart is
 * how somebody ends up with a tab they cannot use or a page that lets them
 * do what the navigation says they cannot.
 *
 * can_manage_people is still consulted. It is the column these two replaced,
 * and a portal updated one file at a time may have this code before it has
 * the migration that fills them in — in which case the old answer is the
 * right one, and losing a working account is not.
 */
function fabo_may_make(?array $person, string $kind = ''): bool
{
    if (!$person) {
        return false;
    }
    /* Our own staff work inside the portal; running people is a client's
       job, whatever a form once posted. */
    if (($person['kind'] ?? 'client') === 'team') {
        return false;
    }
    /* The retired column answers only when the two that replaced it are not
       on the row at all — which happens on a portal that has this code but
       not yet the migration that adds them. Once they exist, a zero in them
       is an answer, not an absence: reading the old column then would mean
       granting clients silently granted team as well, which is the whole
       thing this split exists to stop. */
    $split   = array_key_exists('can_manage_clients', $person)
            || array_key_exists('can_manage_team', $person);
    $legacy  = !$split && !empty($person['can_manage_people']);
    $clients = !empty($person['can_manage_clients']) || $legacy;
    $team    = !empty($person['can_manage_team'])    || $legacy;

    if ($kind === 'client') {
        return $clients;
    }
    if ($kind === 'team') {
        return $team;
    }
    return $clients || $team;
}

/** The same question about whoever is signed in. */
function fabo_viewer_may_make(?array $viewer, string $kind = ''): bool
{
    if (!$viewer || !empty($viewer['is_admin'])) {
        return !empty($viewer['is_admin']);
    }
    return fabo_may_make($viewer['client'] ?? null, $kind);
}

/** May this account send through a mailbox of its own? */
function fabo_may_use_own_mail(array $person): bool
{
    if (!empty($person['can_use_own_mail'])) {
        return true;
    }
    // A sub-account inherits the right along with the mailbox itself.
    if (!empty($person['parent_id'])) {
        $parent = fabo_one('SELECT can_use_own_mail FROM clients WHERE id = ?', 'i',
                           [(int) $person['parent_id']]);
        return !empty($parent['can_use_own_mail']);
    }
    return false;
}

/**
 * Stores the rights. $granter is the account handing them out, or null for the
 * admin: nobody can pass on a right they do not hold themselves.
 */
function fabo_save_rights(int $personId, array $post, ?array $granter = null): void
{
    // Running your own clients and sending from your own mailbox belong to a
    // client. Our own team works inside the portal and sends through it, so
    // these never apply to them however the form was submitted.
    $person = fabo_one('SELECT kind FROM clients WHERE id = ?', 'i', [$personId]);
    $isTeam = ($person['kind'] ?? 'client') === 'team';

    $held = [];
    foreach (array_keys(fabo_account_rights()) as $right) {
        $want = ($isTeam || empty($post[$right])) ? 0 : 1;
        if ($granter !== null && empty($granter[$right])) {
            $want = 0;
        }
        $held[$right] = $want;
        fabo_exec('UPDATE clients SET `' . $right . '` = ? WHERE id = ?', 'ii', [$want, $personId]);
    }

    /* The column the two above replaced, kept in step. Anything not yet
       updated still reads it, and a half-uploaded portal that disagrees with
       itself about who may do what is worse than one that is merely old. */
    $either = (int) (!empty($held['can_manage_clients']) || !empty($held['can_manage_team']));
    try {
        fabo_exec('UPDATE clients SET can_manage_people = ? WHERE id = ?', 'ii',
                  [$either, $personId]);
    } catch (Throwable $e) {
        // The column has been dropped. Nothing depends on it any more.
    }
}

/**
 * The rights block, laid out like the sheet-access table beside it.
 * $granter is null for the admin; a right the granter lacks is shown disabled
 * rather than hidden, so it is clear why it cannot be given.
 */
function fabo_rights_table(array $person, ?array $granter = null): string
{
    $h = '<table class="data permtable"><thead><tr><th>Right</th><th class="permcol">Granted</th></tr></thead><tbody>';
    foreach (fabo_account_rights() as $key => [$label, $why]) {
        $held    = !empty($person[$key]);
        $canGive = $granter === null || !empty($granter[$key]);
        $h .= '<tr><td><strong>' . $label . '</strong><div class="hint">' . $why
            . ($canGive ? '' : ' <em>You do not hold this yourself, so you cannot pass it on.</em>')
            . '</div></td>'
            . '<td class="permcol"><input type="checkbox" name="' . e($key) . '" value="1"'
            . ($held ? ' checked' : '') . ($canGive ? '' : ' disabled') . '></td></tr>';
    }
    return $h . '</tbody></table>';
}

/* -------------------------------------------------------------- mailbox */

/** The mailbox fields, saved for whoever sends their own invitations. */
function fabo_save_mailbox(int $personId, array $post): void
{
    $host = trim((string) ($post['smtp_host'] ?? ''));
    $user = trim((string) ($post['smtp_user'] ?? ''));
    $from = trim((string) ($post['smtp_from'] ?? ''));
    $name = trim((string) ($post['smtp_from_name'] ?? ''));
    $port = (int) ($post['smtp_port'] ?? 587) ?: 587;

    // An empty password box means "keep the one already stored", so that the
    // form can be re-saved without re-typing the app password every time.
    $pass = (string) ($post['smtp_pass'] ?? '');
    if ($pass === '') {
        fabo_exec('UPDATE clients SET smtp_host=?, smtp_port=?, smtp_user=?, smtp_from=?, smtp_from_name=?
                   WHERE id=?', 'sisssi', [$host, $port, $user, $from, $name, $personId]);
        return;
    }
    fabo_exec('UPDATE clients SET smtp_host=?, smtp_port=?, smtp_user=?, smtp_pass=?, smtp_from=?,
               smtp_from_name=? WHERE id=?', 'sissssi',
              [$host, $port, $user, $pass, $from, $name, $personId]);
}

/** Does this account have a mailbox of its own to send from? */
function fabo_has_mailbox(array $person): bool
{
    return trim((string) ($person['smtp_host'] ?? '')) !== ''
        && trim((string) ($person['smtp_user'] ?? '')) !== ''
        && trim((string) ($person['smtp_pass'] ?? '')) !== '';
}

/**
 * The mailbox form. Shown on the admin's client page and on a client's own
 * settings, so both edit the same thing in the same words.
 */
function fabo_mailbox_fields(array $person): string
{
    $brand = fabo_owner_brand($person);
    $set   = fabo_has_mailbox($person);
    $h  = '<div class="fieldrow">';
    $h .= '<div class="field"><label>SMTP server</label><input name="smtp_host" value="'
        . e($person['smtp_host'] ?? '') . '" placeholder="smtp.gmail.com"></div>';
    $h .= '<div class="field"><label>Port</label><input name="smtp_port" value="'
        . e($person['smtp_port'] ?: 587) . '" placeholder="587"></div>';
    $h .= '</div><div class="fieldrow">';
    $h .= '<div class="field"><label>Mailbox / username</label><input name="smtp_user" value="'
        . e($person['smtp_user'] ?? '') . '" placeholder="you@yourcompany.com"></div>';
    $h .= '<div class="field"><label>Password</label><input name="smtp_pass" type="password" placeholder="'
        . ($set ? 'set — leave blank to keep it' : 'app password') . '" autocomplete="new-password"></div>';
    $h .= '</div><div class="fieldrow">';
    $h .= '<div class="field"><label>From address</label><input name="smtp_from" value="'
        . e($person['smtp_from'] ?? '') . '" placeholder="same as the mailbox"></div>';
    $h .= '<div class="field"><label>From name</label><input name="smtp_from_name" value="'
        . e($person['smtp_from_name'] ?? '') . '" placeholder="' . e($brand) . '"></div>';
    $h .= '</div>';
    $allowed = fabo_may_use_own_mail($person);
    $h .= '<div class="hint">';
    if (!$allowed) {
        $h .= 'This account does not hold <strong>Send from their own mailbox</strong>, so these '
            . 'details are stored but not used. Mail for them goes out through the portal mailbox, '
            . 'shown as <strong>' . e($brand) . '</strong>.';
    } elseif ($set) {
        $h .= 'Invitations and approval requests sent from this account go out from this mailbox.';
    } else {
        $h .= 'Leave this empty and mail still goes out, but through the portal mailbox — shown as '
            . '<strong>' . e($brand) . '</strong> with replies going to '
            . e($person['email'] ?: 'this account') . '.';
    }
    $h .= ' For Gmail use smtp.gmail.com on port 587 and a 16-character app password.</div>';
    return $h;
}

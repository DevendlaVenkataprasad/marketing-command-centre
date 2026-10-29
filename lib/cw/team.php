<?php
/**
 * Co-working Space — who runs each centre, and what they changed.
 *
 * The owner (the administrator, the organisation's head, or anyone given the
 * whole module on My Team) puts people on a centre's team on the Centre
 * teams page:
 *
 *   Manager   keeps that centre's registers, waiting list, monthly log and
 *             settings, exactly as the owner does -- for that centre only.
 *   Viewer    reads that centre's dashboards, seat map and registers.
 *
 * They sign in to the portal as usual and see Co-working Space with their own
 * centres and nobody else's (cw_assigned() in nav.php). Everything they change
 * lands in the same tables, so the owner sees it at once on All centres, with
 * the activity feed saying who did what where.
 *
 * A centre's people are team members of the organisation: picked from My
 * Team, or added here in one step (name and email) and sent their sign-in.
 */

require_once __DIR__ . '/core.php';
require_once __DIR__ . '/nav.php';       // cw_assigned()
require_once __DIR__ . '/../people.php';  // fabo_viewer_may_make()

/** The organisation's people who can be put on a centre: id => [name, email]. Not the head (they see everything), not committee members. */
function cw_people(int $org): array
{
    $where = $org > 0 ? 'kind = \'team\' AND parent_id = ' . $org : "kind = 'team' AND (parent_id IS NULL OR parent_id = 0)";
    $out = [];
    foreach (fabo_all("SELECT id, name, email FROM clients WHERE $where AND COALESCE(is_committee, 0) = 0
                         AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY name") as $r) {
        $out[(int) $r['id']] = ['name' => (string) $r['name'], 'email' => (string) $r['email']];
    }
    return $out;
}

/** Every centre's team: centre id => list of [person_id, name, email, role, added_by, created_at, gone]. */
function cw_teams(int $org): array
{
    $out = [];
    foreach (fabo_all("SELECT p.centre_id, p.person_id, p.role, p.added_by, p.created_at, k.name, k.email,
                              (k.revoked_at IS NOT NULL OR (k.expires_at IS NOT NULL AND k.expires_at <= NOW())) AS gone
                         FROM cw_centre_people p JOIN cw_centres c ON c.id = p.centre_id LEFT JOIN clients k ON k.id = p.person_id
                        WHERE c.org_id = ? ORDER BY p.role, k.name", 'i', [$org]) as $r) {
        $out[(int) $r['centre_id']][] = $r;
    }
    return $out;
}

/** The managers' names of each centre, for the overview cards: centre id => "A, B". */
function cw_team_names(int $org): array
{
    $out = [];
    foreach (cw_teams($org) as $cid => $list) {
        $names = array_column(array_filter($list, static fn($r) => $r['role'] === 'edit' && !$r['gone'] && $r['name'] !== null), 'name');
        if ($names) {
            $out[$cid] = implode(', ', $names);
        }
    }
    return $out;
}

/** The email addresses of a centre's managers, for the new-application notice. */
function cw_centre_manager_emails(int $centreId): array
{
    $out = [];
    foreach (fabo_all("SELECT k.email FROM cw_centre_people p JOIN clients k ON k.id = p.person_id
                        WHERE p.centre_id = ? AND p.role = 'edit' AND k.revoked_at IS NULL
                          AND (k.expires_at IS NULL OR k.expires_at > NOW())", 'i', [$centreId]) as $r) {
        if (filter_var((string) $r['email'], FILTER_VALIDATE_EMAIL)) {
            $out[] = strtolower((string) $r['email']);
        }
    }
    return array_values(array_unique($out));
}

/** The ids of a centre's people still able to sign in, with their role. */
function cw_centre_people_ids(int $centreId, string $role = ''): array
{
    return array_map('intval', array_column(fabo_all("SELECT p.person_id FROM cw_centre_people p JOIN clients k ON k.id = p.person_id
                                                       WHERE p.centre_id = ? AND (? = '' OR p.role = ?) AND k.revoked_at IS NULL",
                                                      'iss', [$centreId, $role, $role]), 'person_id'));
}

function cw_role_label(string $role): string
{
    return $role === 'edit' ? 'Manager' : 'Viewer';
}

/**
 * Puts one of the organisation's people on a centre (or changes their role
 * there) and tells them: a portal notice and an email with the way in.
 * Returns what to say about the email.
 */
function cw_team_put(int $org, array $centre, int $personId, string $role, string $by, ?array $actor): string
{
    $role = $role === 'view' ? 'view' : 'edit';
    if (!isset(cw_people($org)[$personId])) {
        throw new InvalidArgumentException('Pick someone from your team.');
    }
    $was = fabo_one('SELECT role FROM cw_centre_people WHERE centre_id = ? AND person_id = ?', 'ii', [(int) $centre['id'], $personId]);
    if ($was && $was['role'] === $role) {
        return '';
    }
    fabo_exec('INSERT INTO cw_centre_people (centre_id, person_id, role, added_by) VALUES (?,?,?,?)
               ON DUPLICATE KEY UPDATE role = VALUES(role)', 'iiss', [(int) $centre['id'], $personId, $role, mb_substr($by, 0, 190)]);
    return cw_team_tell($org, $centre, $personId, $role, $actor);
}

/** Takes someone off a centre. Their account stays; only this centre goes from their portal. */
function cw_team_drop(array $centre, int $personId): ?string
{
    $p = fabo_one('SELECT k.name FROM cw_centre_people p LEFT JOIN clients k ON k.id = p.person_id WHERE p.centre_id = ? AND p.person_id = ?',
                  'ii', [(int) $centre['id'], $personId]);
    if (!$p) {
        return null;
    }
    fabo_exec('DELETE FROM cw_centre_people WHERE centre_id = ? AND person_id = ?', 'ii', [(int) $centre['id'], $personId]);
    require_once __DIR__ . '/../notify.php';
    fabo_notify($personId, 'Co-working Space: ' . $centre['name'] . ' is no longer on your portal',
                'You were taken off ' . $centre['name'] . '\'s team.', '');
    return (string) ($p['name'] ?? 'Them');
}

/**
 * A new team member made from the Centre teams page, put on the centre at
 * once: the same account My Team makes (kind team, under the head), with a
 * password and the welcome email carrying it. Returns [person id, what was sent].
 */
function cw_team_invite(int $org, array $centre, string $name, string $email, string $role, string $by, ?array $actor): array
{
    $name = mb_substr(trim($name), 0, 190);
    $email = strtolower(trim($email));
    if ($org <= 0) {
        throw new InvalidArgumentException('Add our own staff on Team Management, then put them on a centre here.');
    }
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Give their name and a working email address: their sign-in goes there.');
    }
    $head = fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [$org]);
    if (!$head) {
        throw new InvalidArgumentException('That organisation is not here any more.');
    }
    foreach (cw_people($org) as $id => $p) {
        if (strtolower($p['email']) === $email) {
            throw new InvalidArgumentException($p['name'] . ' already uses ' . $email . ' — pick them from your team instead.');
        }
    }
    // No expiry of their own, but never past the head's: their access ends with the organisation's.
    $newId = fabo_exec('INSERT INTO clients (name, kind, email, organisation, contact_note, token, expires_at, parent_id)
                        VALUES (?,?,?,?,?,?,?,?)', 'sssssssi',
                       [$name, 'team', $email, (string) ($head['organisation'] ?? ''), 'Co-working: ' . $centre['name'],
                        fabo_new_token(), $head['expires_at'] ?? null, $org]);
    $plain = fabo_set_password($newId);
    $fresh = fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [$newId]);
    require_once __DIR__ . '/../notify.php';
    $base = fabo_portal_url();
    [$sent, $detail] = fabo_announce_welcome($fresh, [], $base . 'c.php?k=' . $fresh['token'], $base . 'login.php', $plain, $actor);
    fabo_exec('INSERT INTO cw_centre_people (centre_id, person_id, role, added_by) VALUES (?,?,?,?)', 'iiss',
              [(int) $centre['id'], $newId, $role === 'view' ? 'view' : 'edit', mb_substr($by, 0, 190)]);
    cw_team_tell($org, $centre, $newId, $role, $actor, false);
    if ($sent || str_contains((string) $detail, 'queued')) {
        return [$newId, $sent ? 'sign-in details emailed to ' . $email : 'sign-in details on their way to ' . $email . ' (' . $detail . ')'];
    }
    return [$newId, 'the email did not go (' . $detail . ') — their password is ' . $plain . ', pass it on yourself'];
}

/** Tells one person which centre is now theirs. $mail false: a notice only (the welcome email has just gone). */
function cw_team_tell(int $org, array $centre, int $personId, string $role, ?array $actor, bool $mail = true): string
{
    require_once __DIR__ . '/../notify.php';
    $what = $role === 'edit' ? 'You manage it: spaces, startups, dues, renewals, the waiting list and the monthly log.'
                             : 'You can read its dashboard, seat map and registers.';
    $title = 'Co-working Space: ' . $centre['name'] . ' is on your portal';
    fabo_notify($personId, $title, $what, 'coworking.php?p=dashboard&c=' . (int) $centre['id']);
    if (!$mail) {
        return '';
    }
    $p = fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [$personId]);
    $to = (string) ($p['email'] ?? '');
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return 'no email address on file, so a portal notice only';
    }
    $link = fabo_portal_url() . 'login.php?next=' . rawurlencode('coworking.php?p=dashboard&c=' . (int) $centre['id']);
    $kind = $centre['kind'] === 'hub' ? 'hub' : 'spoke';
    $text = 'Hi ' . $p['name'] . ",\n\n" . cw_org_name($org) . ' has put you on the team of ' . $centre['name'] . ' (' . $kind . ') in Co-working Space, as '
          . cw_role_label($role) . ".\n\n" . $what . "\n\nSign in and open Co-working Space in the sidebar:\n  " . $link . "\n";
    require_once __DIR__ . '/../outbox.php';
    [$sent, $detail] = fabo_post_or_queue($to, $title, $text, '', $personId, 'cw centre team', $actor);
    return $sent ? 'emailed to ' . $to : 'email not sent (' . $detail . '), a portal notice is there';
}

/* ============================================================ activity */

function cw_activity_log(int $org, int $centreId, string $actor, string $detail): void
{
    try {
        fabo_exec('INSERT INTO cw_activity (org_id, centre_id, actor, detail) VALUES (?,?,?,?)', 'iiss',
                  [$org, $centreId, mb_substr($actor, 0, 190), mb_substr($detail, 0, 500)]);
    } catch (Throwable $e) {
        error_log('FABO cw activity: ' . $e->getMessage());
    }
}

/** The latest changes: in $centreIds only when given (a centre's own people), else the whole organisation. */
function cw_activity_recent(int $org, ?array $centreIds, int $limit = 12): array
{
    if ($centreIds !== null && !$centreIds) {
        return [];
    }
    $in = $centreIds !== null ? ' AND centre_id IN (' . implode(',', array_map('intval', $centreIds)) . ')' : '';
    return fabo_all('SELECT * FROM cw_activity WHERE org_id = ?' . $in . ' ORDER BY id DESC LIMIT ' . max(1, $limit), 'i', [$org]);
}

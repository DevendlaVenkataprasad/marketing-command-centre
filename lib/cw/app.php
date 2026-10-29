<?php
/**
 * Co-working Space — the pages.
 *
 *   overview   every centre at a glance (the hub and its spokes)
 *   dashboard  one centre's headline numbers, the workbook's Dashboard tab
 *   seats      the Seat Map: who sits where, desk by desk and cabin by cabin
 *   spaces     Space Register (input)
 *   startups   Startup Register (input) with the Startup Tracker beside it
 *   waiting    Waiting List (input), as a pipeline
 *   renewals   every agreement with its end date and days left
 *   actions    everything that needs follow-up, by priority
 *   log        Monthly Log: month-end snapshots and the trend
 *   forms      the public application form (link, embed code) and the stage emails
 *   team       Centre teams: who manages or reads each centre (the owner only)
 *   settings   the centre's name, rates and term; add spaces; import the workbook; centres
 *
 * ?pdf=centre (or all) on any page downloads the report (lib/cw/report.php).
 *
 * coworking.php (a client granted the module) and admin/coworking.php (the
 * administrator, for any organisation holding it) both land in cw_main().
 * The numbers all come from cw_model() in core.php.
 */

require_once __DIR__ . '/../ui.php';
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/mail.php';      // the website form and the applicant emails
require_once __DIR__ . '/team.php';      // who runs each centre, and the activity feed

function cw_pages(): array
{
    return [
        'overview' => 'Overview', 'dashboard' => 'Dashboard', 'seats' => 'Seat map', 'spaces' => 'Space register',
        'startups' => 'Startups', 'waiting' => 'Waiting list', 'renewals' => 'Renewals', 'actions' => 'Action items',
        'log' => 'Monthly log', 'forms' => 'Form & emails', 'team' => 'Centre teams', 'settings' => 'Settings',
    ];
}

/* ================================================================ helpers */

function cw_url(string $page, array $q = []): string
{
    $q = ['p' => $page] + $q;
    if (!empty($GLOBALS['cw_viewer']['is_admin'])) {
        $q['org'] = $GLOBALS['cw_org'];
    }
    if (!isset($q['c']) && !empty($GLOBALS['cw_centre']) && $page !== 'overview') {
        $q['c'] = (int) $GLOBALS['cw_centre']['id'];
    }
    return 'coworking.php?' . http_build_query($q);
}

function cw_d(?string $d, string $fmt = 'd M Y'): string
{
    return $d ? date($fmt, strtotime($d)) : '';
}

function cw_pct(float $f): string
{
    return round($f * 100) . '%';
}

/** What the viewer may do at one centre (the open one by default): the module grant, or their role on its team, whichever is more. */
function cw_role(?int $centreId = null): string
{
    $centreId ??= (int) ($GLOBALS['cw_centre']['id'] ?? 0);
    $grant = $GLOBALS['cw_grant'] ?? 'none';
    $mine = $GLOBALS['cw_assigned'][$centreId] ?? 'none';
    return $grant === 'edit' || $mine === 'edit' ? 'edit' : ($grant === 'view' || $mine === 'view' ? 'view' : 'none');
}

function cw_can_edit(): bool
{
    return cw_role() === 'edit';
}

/** Runs the whole module: every centre, Centre teams, Form & emails, adding and removing centres. */
function cw_is_owner(): bool
{
    return ($GLOBALS['cw_grant'] ?? 'none') === 'edit';
}

/** Sees every centre (owner or the whole module view only), rather than only the ones on their team. */
function cw_sees_all(): bool
{
    return ($GLOBALS['cw_grant'] ?? 'none') !== 'none';
}

/** Who is acting, as the logs and the applicant history name them. */
function cw_who(): string
{
    return !empty($GLOBALS['cw_viewer']['is_admin']) ? 'Admin' : (string) ($GLOBALS['cw_viewer']['client']['name'] ?? '');
}

function cw_flash(string $kind, string $msg): void
{
    fabo_session();
    $_SESSION['cw_flash'] = [$kind, $msg];
}

function cw_redirect(string $page, array $q = []): void
{
    header('Location: ' . cw_url($page, $q));
    exit;
}

/** The tone a status, state or label wears: good | warn | risk | info | mute. */
function cw_tone(string $s): string
{
    return [
        'OK' => 'good', 'Active' => 'good', 'Startup' => 'good', 'Moved in' => 'good',
        'Billing to confirm' => 'risk', 'Past tenant - dues open' => 'risk', 'Expired' => 'risk', 'High' => 'risk',
        'Due in 30 days' => 'warn', 'Medium' => 'warn', 'Renewal Due' => 'risk', 'Renewal Upcoming' => 'warn', 'To be Vacated' => 'warn',
        'Give Notice' => 'warn', 'Notice Given' => 'warn', 'Due in 31–60 days' => 'info', 'Vendor' => 'info',
        'Offer sent' => 'info', 'Approved – waiting for space' => 'info', 'In review' => 'warn', 'New' => 'info',
    ][$s] ?? (str_ends_with($s, '-managed') ? 'info' : 'mute');
}

function cw_pill(string $label, ?string $tone = null): string
{
    return $label === '' ? '' : '<span class="cw-pill ' . ($tone ?? cw_tone($label)) . '">' . e($label) . '</span>';
}

/** A number that counts up when it comes into view. $fmt: int | inr | pct. */
function cw_num($v, string $fmt = 'int'): string
{
    $shown = $fmt === 'inr' ? cw_inr($v) : ($fmt === 'pct' ? cw_pct((float) $v) : number_format((float) $v));
    return '<span class="cw-count" data-count="' . e((string) (float) $v) . '" data-fmt="' . $fmt . '">' . e($shown) . '</span>';
}

/** The animated occupancy ring. */
function cw_ring(float $f, string $label = '', int $size = 120): string
{
    $f = max(0.0, min(1.0, $f));
    $r = 52;
    $c = 2 * M_PI * $r;
    $tone = $f >= 0.85 ? 'good' : ($f >= 0.5 ? 'info' : 'warn');
    return '<div class="cw-ring ' . $tone . '" style="width:' . $size . 'px;height:' . $size . 'px" role="img" aria-label="' . e(cw_pct($f) . ' ' . $label) . '">'
         . '<svg viewBox="0 0 120 120"><circle class="bg" cx="60" cy="60" r="' . $r . '"/>'
         . '<circle class="fg" cx="60" cy="60" r="' . $r . '" style="stroke-dasharray:' . round($c, 2) . ';--c:' . round($c, 2) . ';--off:' . round($c * (1 - $f), 2) . '"/></svg>'
         . '<div class="cw-ring-t"><b>' . cw_num($f, 'pct') . '</b>' . ($label !== '' ? '<small>' . e($label) . '</small>' : '') . '</div></div>';
}

/** One horizontal bar: label, track, fill, value. */
function cw_bar(string $label, float $value, float $max, string $shown, int $i = 0, string $tip = '', string $tone = ''): string
{
    $w = $max > 0 ? max(0.0, min(100.0, $value / $max * 100)) : 0.0;
    return '<div class="cw-barrow" style="--i:' . $i . '"' . ($tip !== '' ? ' data-tip="' . e($tip) . '"' : '') . '>'
         . '<span class="l" title="' . e($label) . '">' . e($label) . '</span>'
         . '<span class="cw-track"><i class="' . $tone . '" style="width:' . round($w, 2) . '%"></i></span>'
         . '<span class="v">' . e($shown) . '</span></div>';
}

/* ============================================================ controller */

function cw_main(array $viewer): void
{
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store, private');
    $grant = cw_grant($viewer);
    cw_install();
    // Someone on a centre's team without the whole module: those centres, nothing else.
    $assigned = $grant === 'edit' ? [] : cw_assigned($viewer);
    if ($grant === 'none' && !$assigned) {
        fabo_deny('You do not have access to Co-working Space.', 403);
    }
    $org = cw_org($viewer);
    $GLOBALS['cw_viewer']   = $viewer;
    $GLOBALS['cw_grant']    = $grant;
    $GLOBALS['cw_assigned'] = $assigned;
    $GLOBALS['cw_org']      = $org;

    $centres = cw_centres($org);
    if ($grant === 'none') {
        $centres = array_values(array_filter($centres, static fn($c) => isset($assigned[(int) $c['id']])));
        if (!$centres) {
            fabo_deny('You do not have access to Co-working Space.', 403);
        }
    }
    fabo_session();
    $want = (int) ($_REQUEST['c'] ?? ($_SESSION['cw_c'][$org] ?? 0));
    $centre = null;
    foreach ($centres as $c) {
        if ((int) $c['id'] === $want) {
            $centre = $c;
        }
    }
    $centre ??= $centres[0];
    $_SESSION['cw_c'][$org] = (int) $centre['id'];
    $GLOBALS['cw_centre'] = $centre;

    // One centre of their own: it opens on that centre's dashboard.
    $page = (string) ($_GET['p'] ?? (count($centres) === 1 ? 'dashboard' : 'overview'));
    if (!isset(cw_pages()[$page]) || (in_array($page, ['forms', 'team'], true) && !cw_is_owner())) {
        $page = 'overview';
    }
    $GLOBALS['cw_page'] = $page;

    $orgName = $org === 0 ? fabo_app_name() : (string) (cw_orgs()[$org] ?? cw_org_name($org));
    if (isset($_GET['pdf']) && $_SERVER['REQUEST_METHOD'] === 'GET') {
        require_once __DIR__ . '/report.php';
        if ($_GET['pdf'] === 'all') {
            cw_report_all(array_map('cw_model', $centres), $orgName)->send('Coworking-All-centres-' . date('Y-m-d') . '.pdf');
        }
        cw_report_centre(cw_model($centre), $orgName)->send('Coworking-' . $centre['name'] . '-' . date('Y-m-d') . '.pdf');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        fabo_csrf_check();
        if (!cw_can_edit()) {
            fabo_deny('View only: you can read ' . $centre['name'] . ' but not change it.', 403);
        }
        cw_post($centre, $page);
    }

    fabo_head($viewer, cw_pages()[$page] . ' · Co-working Space', 'cw-' . $page);
    echo '<link rel="stylesheet" href="' . e(fabo_asset(fabo_root_prefix() . 'assets/coworking.css')) . '">';
    echo '<main class="wrap-page cw' . (cw_can_edit() ? '' : ' cw-ro') . '" id="main">';
    cw_header($centres, $centre, $page, $orgName);
    $flash = $_SESSION['cw_flash'] ?? null;
    unset($_SESSION['cw_flash']);
    if ($flash) {
        echo '<div class="cw-flash ' . e($flash[0]) . '" role="status">' . e($flash[1]) . '</div>';
    }
    if (!cw_can_edit()) {
        echo '<div class="cw-flash info"><strong>View only.</strong> You can read and filter everything here; changes are made by the centre\x27s managers.</div>';
    }
    if ($page === 'overview') {
        cw_page_overview($centres);
    } else {
        $m = cw_model($centre);
        ('cw_page_' . $page)($m);
    }
    echo '</main>';
    echo '<div class="cw-tip" id="cwTip" role="tooltip" hidden></div>';
    echo '<script src="' . e(fabo_asset(fabo_root_prefix() . 'assets/coworking.js')) . '" defer></script>';
    fabo_foot();
}

function cw_header(array $centres, array $centre, string $page, string $orgName): void
{
    $viewer = $GLOBALS['cw_viewer'];
    // The page itself is picked in the sidebar; here only the centre is.
    echo '<header class="cw-hero"><div class="cw-hero-in"><div>'
       . '<span class="cw-eyebrow">Co-working Space' . ($orgName !== '' ? ' · ' . e($orgName) : '') . '</span>'
       . '<h1>' . ($page === 'team' ? 'Centre teams' : ($page === 'overview' ? (cw_sees_all() ? 'All centres' : 'My centres') : e($centre['name']) . ' <span class="cw-kind">' . ($centre['kind'] === 'hub' ? 'Hub' : 'Spoke') . '</span>')) . '</h1>'
       . '<p>' . ($page === 'team' ? 'Who manages and who reads each centre. They sign in to their own portal; what they change shows here.' : ($page === 'overview' ? (cw_sees_all() ? 'The hub and its spokes at a glance — occupancy, billing, dues and what needs attention.'
                                                     : 'The centres on your team — occupancy, billing, dues and what needs attention.')
                                       : e(cw_pages()[$page]) . ' · as of ' . e(date('d M Y')))) . '</p></div><div class="cw-hero-acts">'
       . '<a class="btn cw-pdf" href="' . e(cw_url($page, ['pdf' => $page === 'overview' ? 'all' : 'centre'])) . '">'
       . '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v11M7 10l5 5 5-5M5 20h14"/></svg>Download report (PDF)</a>';
    if (!empty($viewer['is_admin'])) {
        echo '<form method="get" class="cw-org"><input type="hidden" name="p" value="' . e($page) . '"><label>Organisation '
           . '<select name="org" onchange="this.form.submit()">';
        foreach (cw_orgs() as $id => $n) {
            echo '<option value="' . $id . '"' . ($id === $GLOBALS['cw_org'] ? ' selected' : '') . '>' . e($n) . '</option>';
        }
        echo '</select></label></form>';
    }
    echo '</div></div><nav class="cw-centres" aria-label="Centres"><a class="' . ($page === 'overview' ? 'on' : '') . '" href="' . e(cw_url('overview')) . '">'
       . '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>' . (cw_sees_all() ? 'All centres' : 'My centres') . '</a>';
    foreach ($centres as $c) {
        $on = !in_array($page, ['overview', 'team'], true) && (int) $c['id'] === (int) $centre['id'];
        echo '<a class="' . ($on ? 'on' : '') . '"' . ($on ? ' aria-current="true"' : '') . ' href="' . e(cw_url(in_array($page, ['overview', 'team'], true) ? 'dashboard' : $page, ['c' => (int) $c['id']])) . '">'
           . e($c['name']) . ($c['kind'] === 'hub' ? ' <em>Hub</em>' : '') . '</a>';
    }
    echo '</nav></header>';
}

/* ================================================================== POST */

function cw_post(array $centre, string $page): void
{
    $cid = (int) $centre['id'];
    $a = (string) ($_POST['a'] ?? '');
    $id = (int) ($_POST['id'] ?? 0) ?: null;
    // What is the whole organisation's, rather than one centre's, stays with the owner.
    if (!cw_is_owner() && in_array($a, ['form_save', 'form_renew', 'tpl_save', 'centre_add', 'centre_delete', 'centre_move',
                                          'team_put', 'team_drop', 'team_invite'], true)) {
        fabo_deny('Only the owner of Co-working Space can change that.', 403);
    }
    $org = (int) $GLOBALS['cw_org'];
    $logAt = $cid;          // the centre the activity feed files this under (0: the organisation)
    try {
        switch ($a) {
            case 'space_save':
                cw_space_save($cid, $id, $_POST);
                cw_flash('ok', 'Space ' . trim((string) $_POST['space_id']) . ' saved.');
                break;
            case 'space_delete':
                fabo_exec('DELETE FROM cw_spaces WHERE id = ? AND centre_id = ?', 'ii', [(int) $id, $cid]);
                cw_flash('ok', 'Space removed.');
                break;
            case 'spaces_add':
                $n = cw_spaces_add($cid, cw_pick($_POST['type'] ?? '', ['Desk', 'Cabin'], 'Desk'), mb_substr(trim((string) ($_POST['zone'] ?? '')), 0, 60),
                                   trim((string) ($_POST['prefix'] ?? '')), max(1, (int) ($_POST['start'] ?? 1)), (int) ($_POST['count'] ?? 0),
                                   mb_substr(trim((string) ($_POST['managed_by'] ?? '')), 0, 60));
                cw_flash('ok', $n . ' space' . ($n === 1 ? '' : 's') . ' added' . ($n ? ', each marked Vacant until someone moves in.' : ' — those IDs are already taken.'));
                break;
            case 'startup_save':
                cw_startup_save($cid, $id, $_POST);
                cw_flash('ok', trim((string) $_POST['name']) . ' saved.');
                break;
            case 'startup_delete':
                fabo_exec('DELETE FROM cw_startups WHERE id = ? AND centre_id = ?', 'ii', [(int) $id, $cid]);
                cw_flash('ok', 'Startup removed from the register. Its spaces keep the name until you change them.');
                break;
            case 'wait_save':
                $who = cw_who();
                $before = $id ? fabo_one('SELECT * FROM cw_waiting WHERE id = ? AND centre_id = ?', 'ii', [$id, $cid]) : null;
                $wid = cw_wait_save($cid, $id, $_POST);
                if (!$before) {
                    fabo_exec('UPDATE cw_waiting SET ref = ?, channel = ? WHERE id = ?', 'ssi', [cw_next_ref($org), 'Manual', $wid]);
                    cw_event($wid, 'created', 'Added on the Waiting list', $who);
                }
                $w = fabo_one('SELECT * FROM cw_waiting WHERE id = ?', 'i', [$wid]);
                $stage = null;
                if (!$before) {
                    $stage = $w['status'] === 'New' ? 'received' : $w['status'];
                } elseif ($before['status'] !== $w['status']) {
                    cw_event($wid, 'stage', $before['status'] . ' → ' . $w['status'], $who);
                    $stage = $w['status'] === 'New' ? null : $w['status'];
                }
                $msg = trim((string) $_POST['applicant']) . ' saved.';
                if ($stage !== null && !empty($_POST['mail'])) {
                    $msg .= ' Email to the applicant: ' . cw_stage_mail($org, $centre, $w, $stage, $who) . '.';
                }
                cw_flash('ok', $msg);
                break;
            case 'form_save':
                $org = (int) $GLOBALS['cw_org'];
                $bad = array_filter(array_map('trim', preg_split('/[,;\s]+/', (string) ($_POST['notify'] ?? ''))),
                                    static fn($x) => $x !== '' && !filter_var($x, FILTER_VALIDATE_EMAIL));
                if ($bad) {
                    throw new InvalidArgumentException('Not an email address: ' . implode(', ', $bad) . '.');
                }
                cw_org_setting_put($org, 'form_on', !empty($_POST['on']) ? '1' : '0');
                cw_org_setting_put($org, 'form_intro', mb_substr(trim((string) ($_POST['intro'] ?? '')), 0, 600) ?: null);
                cw_org_setting_put($org, 'notify', mb_substr(trim((string) ($_POST['notify'] ?? '')), 0, 1000) ?: null);
                cw_flash('ok', !empty($_POST['on']) ? 'Form settings saved. The form is accepting applications.'
                                                    : 'Form settings saved. The form is closed to new applications.');
                break;
            case 'form_renew':
                cw_form_key((int) $GLOBALS['cw_org'], true);
                cw_flash('ok', 'New link made. Replace the old link or embed code on your website with the new one.');
                break;
            case 'tpl_save':
                $org = (int) $GLOBALS['cw_org'];
                if (!empty($_POST['reset'])) {
                    fabo_exec('DELETE FROM cw_mail_tpl WHERE org_id = ?', 'i', [$org]);
                    cw_flash('ok', 'Every email is back to the standard wording.');
                    break;
                }
                foreach ((array) ($_POST['stage'] ?? []) as $k => $stage) {
                    cw_mail_tpl_save($org, (string) $stage, !empty($_POST['on'][$k]), (string) ($_POST['subject'][$k] ?? ''), (string) ($_POST['body'][$k] ?? ''));
                }
                cw_flash('ok', 'Emails saved.');
                break;
            case 'wait_delete':
                fabo_exec('DELETE FROM cw_waiting WHERE id = ? AND centre_id = ?', 'ii', [(int) $id, $cid]);
                cw_flash('ok', 'Applicant removed.');
                break;
            case 'snapshot':
                cw_snapshot_take($centre, trim((string) ($_POST['notes'] ?? '')));
                cw_flash('ok', 'Month-end snapshot for ' . date('F Y') . ' saved to the Monthly Log.');
                break;
            case 'log_delete':
                fabo_exec('DELETE FROM cw_monthly WHERE id = ? AND centre_id = ?', 'ii', [(int) $id, $cid]);
                cw_flash('ok', 'Snapshot removed.');
                break;
            case 'centre_save':
                if (!cw_is_owner()) {     // a centre's manager keeps its rates and address; its name and place in the network are the owner's
                    $_POST['name'] = $centre['name'];
                    $_POST['kind'] = $centre['kind'];
                }
                $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 120);
                if ($name === '') {
                    throw new InvalidArgumentException('Give the centre a name.');
                }
                fabo_exec('UPDATE cw_centres SET name = ?, kind = ?, manager = ?, desk_rate = ?, cabin_rate = ?, open_rate = ?, term_months = ?, address = ? WHERE id = ?',
                          'sssdddisi', [$name, cw_pick($_POST['kind'] ?? '', ['hub', 'spoke'], 'spoke'),
                                       mb_substr(trim((string) ($_POST['manager'] ?? '')), 0, 60) ?: 'RTIH',
                                       max(0, (float) cw_num_in($_POST['desk_rate'] ?? 0)), max(0, (float) cw_num_in($_POST['cabin_rate'] ?? 0)),
                                       max(0, (float) cw_num_in($_POST['open_rate'] ?? 0)),
                                       max(1, min(60, (int) ($_POST['term_months'] ?? 11))), trim((string) ($_POST['address'] ?? '')) ?: null, $cid]);
                cw_flash('ok', 'Centre settings saved.');
                break;
            case 'centre_add':
                $newId = cw_centre_add((int) $GLOBALS['cw_org'], (string) ($_POST['name'] ?? ''), (string) ($_POST['kind'] ?? 'spoke'));
                cw_flash('ok', trim((string) $_POST['name']) . ' added. Set up its desks and cabins here, or import its workbook.');
                cw_activity_log($org, 0, cw_who(), $_SESSION['cw_flash'][1]);
                cw_redirect('settings', ['c' => $newId]);
                break;
            case 'centre_delete':
                $gone = cw_centre_delete((int) $GLOBALS['cw_org'], (int) ($_POST['centre'] ?? 0), ($_POST['with_data'] ?? '') === '1');
                cw_flash('ok', $gone . ' removed.');
                if ((int) ($_POST['centre'] ?? 0) === $cid) {
                    unset($_SESSION['cw_c'][$GLOBALS['cw_org']]);
                    cw_activity_log($org, 0, cw_who(), $_SESSION['cw_flash'][1]);
                    cw_redirect('overview');
                }
                break;
            case 'centre_move':
                cw_centre_move((int) $GLOBALS['cw_org'], (int) ($_POST['centre'] ?? 0), (int) ($_POST['dir'] ?? 0));
                $logAt = -1;      // not worth a line in the feed
                break;
            case 'team_put':
            case 'team_drop':
            case 'team_invite':
                $on = cw_centre($org, (int) ($_POST['centre'] ?? 0));
                if (!$on) {
                    throw new InvalidArgumentException('That centre is not here any more.');
                }
                $logAt = (int) $on['id'];
                $role = ($_POST['role'] ?? '') === 'view' ? 'view' : 'edit';
                $actor = !empty($GLOBALS['cw_viewer']['is_admin']) ? cw_org_actor($org) : ($GLOBALS['cw_viewer']['client'] ?? null);
                if ($a === 'team_put') {
                    $pid = (int) ($_POST['person'] ?? 0);
                    $sent = cw_team_put($org, $on, $pid, $role, cw_who(), $actor);
                    $name = cw_people($org)[$pid]['name'] ?? 'They';
                    cw_flash('ok', $name . ' is ' . ($role === 'edit' ? 'a manager' : 'a viewer') . ' of ' . $on['name'] . '.' . ($sent !== '' ? ' Told them: ' . $sent . '.' : ''));
                } elseif ($a === 'team_drop') {
                    $gone = cw_team_drop($on, (int) ($_POST['person'] ?? 0));
                    cw_flash('ok', ($gone ?? 'They') . ' is off ' . $on['name'] . '\x27s team. Their account stays.');
                } else {
                    if (empty($GLOBALS['cw_viewer']['is_admin']) && !fabo_viewer_may_make($GLOBALS['cw_viewer'], 'team')) {
                        throw new InvalidArgumentException('Your account cannot add team members. Ask the administrator, or pick someone already on your team.');
                    }
                    [, $sent] = cw_team_invite($org, $on, (string) ($_POST['name'] ?? ''), (string) ($_POST['email'] ?? ''), $role, cw_who(), $actor);
                    cw_flash('ok', trim((string) $_POST['name']) . ' added to your team as ' . ($role === 'edit' ? 'a manager' : 'a viewer') . ' of ' . $on['name'] . ' — ' . $sent . '.');
                }
                $page = 'team';
                break;
            case 'import':
                $f = $_FILES['book'] ?? null;
                if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
                    throw new InvalidArgumentException('Choose the workbook (.xlsx) to import.');
                }
                if (strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION)) !== 'xlsx' || $f['size'] > 15 * 1024 * 1024) {
                    throw new InvalidArgumentException('Import an .xlsx workbook of up to 15 MB.');
                }
                if (($_POST['confirm'] ?? '') !== 'replace') {
                    throw new InvalidArgumentException('Tick the box to confirm this replaces the centre\'s registers.');
                }
                $n = cw_import($centre, $f['tmp_name']);
                cw_flash('ok', 'Imported into ' . $centre['name'] . ': ' . $n['spaces'] . ' spaces, ' . $n['startups'] . ' startups, '
                               . (!empty($n['kept']) ? 'from its allocation sheet (waiting list and monthly log kept).'
                                                     : $n['waiting'] . ' applicants and ' . $n['log'] . ' monthly snapshots.'));
                $page = 'dashboard';
                break;
            default:
                throw new InvalidArgumentException('Unknown action.');
        }
    } catch (InvalidArgumentException $e) {
        cw_flash('err', $e->getMessage());
    }
    // What was done goes in the activity feed, in the words the person was shown.
    $done = $_SESSION['cw_flash'] ?? null;
    if ($logAt >= 0 && is_array($done) && $done[0] === 'ok') {
        $line = preg_replace('/ — their password is \S+.*$/u', '', (string) $done[1]);
        cw_activity_log($org, in_array($a, ['form_save', 'form_renew', 'tpl_save', 'centre_add', 'centre_delete'], true) ? 0 : $logAt, cw_who(), $line);
    }
    cw_redirect($page);
}

/* ================================================================= pages */

function cw_page_overview(array $centres): void
{
    $models = array_map('cw_model', $centres);
    $t = ['desks' => 0, 'taken' => 0, 'cabins' => 0, 'cabins_taken' => 0, 'billed' => 0, 'dues' => 0, 'startups' => 0, 'actions' => 0];
    foreach ($models as $m) {
        $t['desks'] += $m['desks_total'];
        $t['taken'] += $m['desks_taken'];
        $t['cabins'] += $m['cabins_total'];
        $t['cabins_taken'] += $m['cabins_taken'];
        $t['billed'] += $m['billed'];
        $t['dues'] += $m['dues'];
        $t['startups'] += $m['paperwork']['Startups using space'];
        $t['actions'] += $m['action_count'];
    }
    echo '<section class="cw-kpis">'
       . cw_kpi('Network desk occupancy', cw_num($t['desks'] ? $t['taken'] / $t['desks'] : 0, 'pct'), $t['taken'] . ' of ' . $t['desks'] . ' desks taken', 'accent', 0)
       . cw_kpi('Cabins occupied', cw_num($t['cabins_taken']) . ' <small>/ ' . $t['cabins'] . '</small>', ($t['cabins'] - $t['cabins_taken']) . ' vacant', '', 1)
       . cw_kpi('Startups in residence', cw_num($t['startups']), 'across ' . count($centres) . ' centres', '', 2)
       . cw_kpi('Billed rent / month', cw_num($t['billed'], 'inr'), 'all centres', 'good', 3)
       . cw_kpi('Dues outstanding', cw_num($t['dues'], 'inr'), 'all centres', $t['dues'] > 0 ? 'risk' : '', 4)
       . cw_kpi('Open action items', cw_num($t['actions']), 'to follow up', $t['actions'] ? 'warn' : '', 5)
       . '</section>';
    $runBy = cw_team_names((int) $GLOBALS['cw_org']);
    echo '<section class="cw-centregrid">';
    foreach ($models as $i => $m) {
        $c = $m['centre'];
        $mine = cw_role((int) $c['id']);
        $empty = !$m['spaces'] && !$m['startups'];
        echo '<a class="cw-centre-card cw-rise' . ($c['kind'] === 'hub' ? ' hub' : '') . '" style="--i:' . $i . '" href="' . e(cw_url('dashboard', ['c' => (int) $c['id']])) . '">'
           . '<div class="cc-top"><div><span class="cc-kind">' . ($c['kind'] === 'hub' ? 'Hub' : 'Spoke') . '</span><h2>' . e($c['name']) . '</h2></div>'
           . '<svg class="cc-go" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg></div>'
           . '<p class="cc-team">' . (isset($runBy[(int) $c['id']]) ? '<span>Managed by</span> ' . e($runBy[(int) $c['id']])
                                                       : '<span>No centre manager yet</span>')
           . (!cw_is_owner() ? ' ' . cw_pill($mine === 'edit' ? 'You manage it' : 'View only', $mine === 'edit' ? 'good' : 'mute') : '') . '</p>';
        if ($empty) {
            echo '<div class="cc-empty"><span class="cc-empty-ic" aria-hidden="true"></span><strong>Not set up yet</strong>'
               . '<span>Add its desks and cabins, or import its sheet in the Coworking Master template.</span></div>';
        } else {
            echo '<div class="cc-body">' . cw_ring($m['occupancy'], 'desks', 108)
               . '<dl><div><dt>Desks</dt><dd>' . $m['desks_taken'] . ' / ' . $m['desks_total'] . '</dd></div>'
               . '<div><dt>Cabins</dt><dd>' . $m['cabins_taken'] . ' / ' . $m['cabins_total'] . '</dd></div>'
               . '<div><dt>Billed / month</dt><dd>' . e(cw_inr($m['billed'])) . '</dd></div>'
               . '<div><dt>Dues</dt><dd class="' . ($m['dues'] > 0 ? 'risk' : '') . '">' . e(cw_inr($m['dues'])) . '</dd></div></dl></div>'
               . '<div class="cc-foot">' . ($m['action_count'] ? cw_pill($m['action_count'] . ' action items', 'warn') : cw_pill('All clear', 'good'))
               . ($m['renewals']['Expired'] ? cw_pill($m['renewals']['Expired'] . ' agreements expired', 'risk') : '') . '</div>';
        }
        echo '</a>';
    }
    echo '</section>';
    cw_activity_card(cw_sees_all() ? null : array_map(static fn($c) => (int) $c['id'], $centres), $centres);
}

/** What was changed lately, where and by whom: the whole organisation for the owner, their own centres for a centre's team. */
function cw_activity_card(?array $centreIds, array $centres): void
{
    $rows = cw_activity_recent((int) $GLOBALS['cw_org'], $centreIds, 15);
    $names = array_column($centres, 'name', 'id');
    echo '<section class="cw-card cw-rise cw-activity" style="--i:6"><div class="cw-card-h"><h2>Recent activity</h2><span class="hint">'
       . ($centreIds === null ? 'every centre, every hand' : 'your centres') . '</span></div>';
    if (!$rows) {
        echo '<p class="cw-muted">Nothing changed yet. Whatever a centre\x27s team adds or updates shows here, with who and when.</p></section>';
        return;
    }
    echo '<ol class="cw-feed">';
    foreach ($rows as $r) {
        $where = (int) $r['centre_id'] > 0 ? (string) ($names[(int) $r['centre_id']] ?? 'a removed centre') : 'All centres';
        echo '<li><span class="cw-feed-who">' . e(cw_initials((string) $r['actor'] ?: '?')) . '</span><div><p>' . e((string) $r['detail']) . '</p>'
           . '<small><b>' . e((string) $r['actor'] ?: 'Someone') . '</b> · ' . e($where) . ' · ' . e(date('d M, H:i', strtotime((string) $r['created_at']))) . '</small></div></li>';
    }
    echo '</ol></section>';
}

function cw_kpi(string $k, string $v, string $sub, string $tone = '', int $i = 0, string $href = ''): string
{
    $tag = $href !== '' ? 'a href="' . e($href) . '"' : 'div';
    return '<' . $tag . ' class="cw-kpi cw-rise ' . $tone . '" style="--i:' . $i . '"><span class="k">' . e($k) . '</span><span class="v">' . $v . '</span>'
         . '<span class="s">' . $sub . '</span></' . ($href !== '' ? 'a' : 'div') . '>';
}

function cw_empty_centre(array $m): bool
{
    if ($m['spaces'] || $m['startups']) {
        return false;
    }
    echo '<section class="cw-card cw-zero cw-rise"><div class="cw-zero-art" aria-hidden="true">'
       . str_repeat('<i></i>', 10) . '</div><h2>' . e($m['centre']['name']) . ' has no spaces yet</h2>'
       . '<p>Every centre keeps the same template as the Coworking Master workbook. Start by adding its desks and cabins, '
       . 'or import this centre\'s own sheet in that template.</p>'
       . '<div class="cw-acts"><a class="btn btn-primary cw-edit" href="' . e(cw_url('settings')) . '#add">Add desks &amp; cabins</a>'
       . '<a class="btn cw-edit" href="' . e(cw_url('settings')) . '#import">Import a workbook</a></div></section>';
    return true;
}

function cw_page_dashboard(array $m): void
{
    if (cw_empty_centre($m)) {
        return;
    }
    $c = $m['centre'];
    echo '<section class="cw-kpis">'
       . cw_kpi('Desk occupancy', cw_num($m['occupancy'], 'pct'), $m['desks_taken'] . ' of ' . $m['desks_total'] . ' taken · ' . $m['desks_vendor'] . ' vendor · ' . $m['desks_vacant'] . ' free', 'accent', 0, cw_url('seats'))
       . cw_kpi('Cabins occupied', cw_num($m['cabins_taken']) . ' <small>/ ' . $m['cabins_total'] . '</small>', ($m['cabins_total'] - $m['cabins_taken']) . ' vacant', '', 1, cw_url('seats'))
       . cw_kpi('Billed rent / month', cw_num($m['billed'], 'inr'), 'Expected ' . e(cw_inr($m['expected'])), 'good', 2, cw_url('startups'))
       . cw_kpi('Billing to confirm / month', cw_num($m['confirm'], 'inr'), $m['confirm_n'] . ' startup' . ($m['confirm_n'] === 1 ? '' : 's') . ' to confirm', $m['confirm'] > 0 ? 'warn' : '', 3, cw_url('actions'))
       . cw_kpi('Dues outstanding', cw_num($m['dues'], 'inr'), e(cw_inr($m['dues_past'])) . ' from past tenants', $m['dues'] > 0 ? 'risk' : '', 4, cw_url('startups'))
       . cw_kpi('Open action items', cw_num($m['action_count']), 'View the list →', $m['action_count'] ? 'warn' : 'good', 5, cw_url('actions'))
       . '</section>';

    echo '<div class="cw-grid2">';
    // Occupancy by area: one series, so no legend; each bar is labelled and carries its own tooltip.
    echo '<section class="cw-card cw-rise" style="--i:1"><div class="cw-card-h"><h2>Occupancy by area</h2><span class="hint">occupied of total</span></div>'
       . '<div class="cw-occ">' . cw_ring($m['occupancy'], 'all desks', 132) . '<div class="cw-bars">';
    foreach ($m['area'] as $i => $a) {
        $f = $a['total'] ? $a['occupied'] / $a['total'] : 0;
        echo cw_bar($a['label'], $f, 1, $a['occupied'] . '/' . $a['total'] . ' · ' . cw_pct($f), $i,
                    $a['label'] . ': ' . $a['occupied'] . ' occupied, ' . ($a['total'] - $a['occupied']) . ' vacant of ' . $a['total'], !empty($a['sum']) ? 'strong' : '');
    }
    echo '</div></div>';
    if ($m['partners']) {
        echo '<p class="cw-note">Occupied = startups, plus vendors on ' . e(implode(', ', $m['partners'])) . ' desks (they collect their own payment). All '
           . $m['desks_total'] . ' desks count towards ' . e($c['manager']) . ' occupancy.</p>';
    }
    echo '</section>';

    // Startups by billing status: status colours, always with the label beside them.
    echo '<section class="cw-card cw-rise" style="--i:2"><div class="cw-card-h"><h2>Startups by billing status</h2></div><ul class="cw-statlist">';
    $order = ['OK', 'Billing to confirm', 'Not billable', 'Past tenant - dues open', 'Past tenant'];
    $by = $m['by_status'];
    foreach (array_unique(array_merge($order, array_keys($by))) as $st) {
        if (!isset($by[$st]) && !in_array($st, $order, true)) {
            continue;
        }
        echo '<li>' . cw_pill($st) . '<b>' . (int) ($by[$st] ?? 0) . '</b></li>';
    }
    echo '</ul></section></div>';

    echo '<div class="cw-grid2">';
    echo '<section class="cw-card cw-rise" style="--i:3"><div class="cw-card-h"><h2>Top 10 dues</h2><a class="hint" href="' . e(cw_url('startups')) . '">All startups →</a></div>';
    if (!$m['top_dues']) {
        echo '<p class="cw-muted">No dues outstanding.</p>';
    } else {
        $max = (float) $m['top_dues'][0]['due_amount'];
        echo '<div class="cw-bars">';
        foreach ($m['top_dues'] as $i => $s) {
            echo cw_bar($s['name'], (float) $s['due_amount'], $max, cw_inr($s['due_amount']), $i,
                        $s['name'] . ': ' . cw_inr($s['due_amount']) . ($s['dues_as_on'] ? ' as on ' . cw_d($s['dues_as_on']) : '') . ' · ' . $s['_status'], 'risk');
        }
        echo '</div>';
    }
    echo '</section>';
    echo '<section class="cw-card cw-rise" style="--i:4"><div class="cw-card-h"><h2>Paperwork &amp; deposits</h2></div><dl class="cw-dl">';
    foreach ($m['paperwork'] as $k => $v) {
        echo '<div><dt>' . e($k) . '</dt><dd>' . (int) $v . '</dd></div>';
    }
    echo '<div><dt>Security deposits held</dt><dd>' . e(cw_inr($m['deposits'])) . '</dd></div></dl></section></div>';

    echo '<div class="cw-grid2">';
    echo '<section class="cw-card cw-rise" style="--i:5"><div class="cw-card-h"><h2>True vacancy</h2><span class="hint">from Space Register</span></div>'
       . '<div class="cw-scroll"><table class="cw-table"><thead><tr><th>Area</th><th class="num">Desks</th><th class="num">Startups</th><th class="num">Vendors</th><th class="num">Truly vacant</th><th class="num">Not checked</th></tr></thead><tbody>';
    $tot = ['total' => 0, 'startups' => 0, 'vendors' => 0, 'vacant' => 0, 'notchecked' => 0];
    foreach ($m['zones'] as $z => $v) {
        foreach ($tot as $k => $_) {
            $tot[$k] += $v[$k];
        }
        echo '<tr><td>' . e($z) . '</td><td class="num">' . $v['total'] . '</td><td class="num">' . $v['startups'] . '</td><td class="num">' . $v['vendors']
           . '</td><td class="num">' . $v['vacant'] . '</td><td class="num">' . $v['notchecked'] . '</td></tr>';
    }
    echo '<tr class="sum"><td>All desks</td><td class="num">' . $tot['total'] . '</td><td class="num">' . $tot['startups'] . '</td><td class="num">' . $tot['vendors']
       . '</td><td class="num">' . $tot['vacant'] . '</td><td class="num">' . $tot['notchecked'] . '</td></tr></tbody></table></div>';
    $ck = $m['check'];
    $pf = $ck['pool'] ? $ck['checked'] / $ck['pool'] : 1;
    echo '<div class="cw-progress"><div class="cw-progress-h"><span>Vacancy check progress</span><b>' . $ck['checked'] . ' of ' . $ck['pool'] . ' checked · ' . cw_pct($pf) . '</b></div>'
       . '<span class="cw-track big"><i style="width:' . round($pf * 100, 1) . '%"></i></span>'
       . '<p class="cw-note">"Not checked" desks may free up once vendors are confirmed. Vendor people on desks: ' . $ck['vendor_people'] . '.</p></div></section>';

    // Renewals: status colours with labels; the next agreements to run out.
    $rn = $m['renewals'];
    $total = array_sum($rn);
    echo '<section class="cw-card cw-rise" style="--i:6"><div class="cw-card-h"><h2>Renewals</h2><span class="hint">' . (int) $c['term_months'] . '-month agreements</span></div>';
    if ($total) {
        echo '<div class="cw-stack" role="img" aria-label="' . e(implode(', ', array_map(static fn($k, $v) => $k . ' ' . $v, array_keys($rn), $rn))) . '">';
        foreach ($rn as $k => $v) {
            if ($v) {
                echo '<i class="' . cw_tone($k) . '" style="flex:' . $v . '" data-tip="' . e($k . ': ' . $v . ' agreement' . ($v === 1 ? '' : 's')) . '"></i>';
            }
        }
        echo '</div><ul class="cw-legend">';
        foreach ($rn as $k => $v) {
            echo '<li><span class="sw ' . cw_tone($k) . '"></span>' . e($k) . ' <b>' . $v . '</b></li>';
        }
        echo '</ul>';
    }
    echo '<h3 class="cw-sub">Next up for renewal</h3><ul class="cw-mini">';
    foreach (array_slice($m['agreements'], 0, 5) as $g) {
        echo '<li><span>' . e($g['startup']) . '<small>Ends ' . e(cw_d($g['end'])) . '</small></span>' . cw_pill($g['days'] < 0 ? abs($g['days']) . ' days over' : $g['days'] . ' days left', cw_tone($g['status'])) . '</li>';
    }
    if (!$m['agreements']) {
        echo '<li class="cw-muted">No dated agreements yet.</li>';
    }
    echo '</ul><a class="hint" href="' . e(cw_url('renewals')) . '">All renewals →</a></section></div>';

    echo '<div class="cw-grid2">';
    echo '<section class="cw-card cw-rise" style="--i:7"><div class="cw-card-h"><h2>Waiting list &amp; pipeline</h2><a class="hint" href="' . e(cw_url('waiting')) . '">Open →</a></div><dl class="cw-dl">';
    foreach ($m['pipeline'] as $k => $v) {
        echo '<div><dt>' . e($k) . '</dt><dd>' . $v . '</dd></div>';
    }
    echo '<div class="sum"><dt>Total open</dt><dd>' . array_sum($m['pipeline']) . '</dd></div></dl>'
       . '<h3 class="cw-sub">Space for the pipeline</h3><dl class="cw-dl">'
       . '<div><dt>Desks needed (approved / offer sent)</dt><dd>' . $m['needed'] . '</dd></div>'
       . '<div><dt>Desks free now</dt><dd>' . $m['free'] . '</dd></div>'
       . '<div><dt>Desks not checked yet</dt><dd>' . $m['desks_notchecked'] . '</dd></div>'
       . '<div class="' . ($m['short'] ? 'risk' : '') . '"><dt>Short by</dt><dd>' . $m['short'] . '</dd></div></dl></section>';
    echo '<section class="cw-card cw-rise" style="--i:8"><div class="cw-card-h"><h2>Monthly trend</h2><a class="hint" href="' . e(cw_url('log')) . '">Monthly log →</a></div>';
    cw_trend($m);
    echo '</section></div>';
}

/** Desk occupancy month by month: the Monthly Log's snapshots, then now. One series, so no legend. */
function cw_trend(array $m): void
{
    $pts = [];
    foreach ($m['log'] as $r) {
        if ($r['occupancy'] !== null) {
            $pts[] = [date('M Y', strtotime($r['month'])), (float) $r['occupancy'], 'Snapshot ' . date('M Y', strtotime($r['month']))];
        }
    }
    $pts[] = ['Now', $m['occupancy'], 'Live, ' . date('d M Y')];
    if (count($pts) < 2) {
        echo '<p class="cw-muted">The trend starts with the first month-end snapshot on the Monthly Log.</p>';
        return;
    }
    $w = 520;
    $h = 180;
    $pl = 40;
    $pr = 16;
    $pt = 14;
    $pb = 28;
    $n = count($pts);
    $x = static fn($i) => $pl + ($n === 1 ? 0 : $i * ($w - $pl - $pr) / ($n - 1));
    $y = static fn($v) => $pt + (1 - $v) * ($h - $pt - $pb);
    $path = '';
    foreach ($pts as $i => $p) {
        $path .= ($i ? 'L' : 'M') . round($x($i), 1) . ' ' . round($y($p[1]), 1);
    }
    echo '<svg class="cw-trend" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="Desk occupancy by month">';
    foreach ([0, 0.5, 1] as $g) {
        echo '<line class="grid" x1="' . $pl . '" x2="' . ($w - $pr) . '" y1="' . $y($g) . '" y2="' . $y($g) . '"/>'
           . '<text class="ax" x="' . ($pl - 6) . '" y="' . ($y($g) + 4) . '" text-anchor="end">' . ($g * 100) . '%</text>';
    }
    echo '<path class="line" d="' . $path . '"/>';
    foreach ($pts as $i => $p) {
        echo '<text class="ax" x="' . round($x($i), 1) . '" y="' . ($h - 8) . '" text-anchor="middle">' . e($p[0]) . '</text>'
           . '<circle class="hit" cx="' . round($x($i), 1) . '" cy="' . round($y($p[1]), 1) . '" r="14" data-tip="' . e($p[2] . ': ' . cw_pct($p[1]) . ' desk occupancy') . '"/>'
           . '<circle class="dot" cx="' . round($x($i), 1) . '" cy="' . round($y($p[1]), 1) . '" r="4.5"/>';
    }
    echo '</svg>';
}

/** The state a seat is drawn in, and its words. */
function cw_seat_state(array $s): array
{
    if ($s['occupied_by'] === 'Startup' && $s['action'] !== '') {
        return ['leaving', 'To be vacated'];
    }
    return [
        'Startup' => ['startup', 'Startup'], 'Staff' => ['staff', 'Staff'], 'Vendor' => ['vendor', 'Vendor'],
        'Vacant' => ['free', 'Available'], 'Not checked' => ['unchecked', 'Not checked'],
    ][$s['occupied_by']];
}

/** Two letters for a desk: DEEPLURE RESEARCH (OPC) PRIVATE LIMITED -> DR. */
function cw_initials(string $name): string
{
    $skip = ['private', 'limited', 'pvt', 'ltd', 'pvt.', 'ltd.', '(opc)', 'opc', 'and', '&', 'the', 'india', 'llp', 'inc'];
    $out = '';
    foreach (preg_split('/\s+/', trim($name)) as $w) {
        if ($w === '' || in_array(mb_strtolower($w), $skip, true)) {
            continue;
        }
        $out .= mb_strtoupper(mb_substr(preg_replace('/[^\p{L}\p{N}]/u', '', $w), 0, 1));
        if (mb_strlen($out) === 2) {
            break;
        }
    }
    return $out !== '' ? $out : mb_strtoupper(mb_substr($name, 0, 2));
}

/**
 * The Seat Map, drawn as a floor plan: each zone a room, its desks in pods of
 * two facing rows of five, every cabin a room of its own. Choosing a seat
 * opens it in the panel beside the plan; pointing at one lights up every
 * seat the same startup holds.
 */
function cw_page_seats(array $m): void
{
    if (cw_empty_centre($m)) {
        return;
    }
    $states = ['startup' => 'Startup', 'staff' => 'Staff', 'vendor' => 'Vendor', 'free' => 'Available', 'unchecked' => 'Not checked', 'leaving' => 'To be vacated'];
    $counts = array_fill_keys(array_keys($states), 0);
    $partner = 0;
    $rooms = [];
    $byKey = [];
    foreach ($m['startups'] as $st) {
        $byKey[cw_key($st['name'])] = $st;
    }
    $holders = [];
    foreach ($m['spaces'] as $s) {
        $counts[cw_seat_state($s)[0]]++;
        $partner += $s['_own'] ? 0 : 1;
        $rooms[$s['type'] === 'Cabin' ? "\x7fCabins" : ($s['type'] === 'Open space' ? "\x7fOpen space" : ($s['zone'] !== '' ? $s['zone'] : 'Desks'))][] = $s;
        if (in_array($s['occupied_by'], ['Startup', 'Vendor'], true) && $s['occupant'] !== '') {
            $k = cw_key($s['occupant']);
            $holders[$k] ??= ['name' => $s['occupant'], 'n' => 0, 'vendor' => $s['occupied_by'] === 'Vendor'];
            $holders[$k]['n']++;
        }
    }
    uasort($holders, static fn($a, $b) => [$b['n'], $a['name']] <=> [$a['n'], $b['name']]);
    $total = count($m['spaces']);
    $inUse = $counts['startup'] + $counts['staff'] + $counts['vendor'] + $counts['leaving'];

    /* What the panel shows for a seat. */
    $info = static function (array $s) use ($byKey, $holders, $states): array {
        [$cls, $word] = cw_seat_state($s);
        $st = $byKey[cw_key($s['occupant'])] ?? null;
        return [
            'space' => $s['space_id'] . ($s['type'] === 'Open space' && $s['area'] ? ' · ' . (float) $s['area'] . ' sq ft' : ''), 'type' => $s['type'],
            'zone' => $s['type'] === 'Desk' ? $s['zone'] : $s['type'], 'state' => $cls, 'word' => $word,
            'occupant' => $s['occupant'], 'kind' => $s['occupied_by'], 'managed' => $s['managed_by'] ?: '', 'partner' => !$s['_own'],
            'agreement' => $s['agreement'], 'signed' => cw_d($s['agreement_date']), 'ends' => cw_d($s['_end']),
            'days' => $s['_days'], 'renewal' => $s['_renew'], 'action' => $s['action'],
            'vendor' => trim(implode(' · ', array_filter([(string) $s['vendor_what'], (string) $s['vendor_contact'], (string) $s['vendor_phone'],
                                                          $s['people'] ? $s['people'] . ' people' : '']))),
            'checked' => trim(implode(' · ', array_filter([(string) $s['checked_by'], cw_d($s['checked_on'])]))), 'notes' => (string) $s['notes'],
            'seats' => $holders[cw_key($s['occupant'])]['n'] ?? 0,
            'status' => $st['_status'] ?? '', 'rent' => $st ? cw_inr($st['_rent']) : '', 'dues' => $st && (float) $st['due_amount'] > 0 ? cw_inr($st['due_amount']) : '',
            'email' => (string) ($st['email'] ?? ''), 'phone' => (string) ($st['phone'] ?? ''),
        ];
    };

    echo '<div class="cw-fp">';
    echo '<div class="cw-fp-main">';
    echo '<section class="cw-card cw-fp-bar"><div class="cw-chips" id="cwSeatFilter" role="group" aria-label="Show seats">'
       . '<button type="button" class="on" data-seat="">All <b>' . $total . '</b></button>';
    foreach ($states as $k => $l) {
        echo '<button type="button" data-seat="' . $k . '"><span class="sw s-' . $k . '"></span>' . e($l) . ' <b>' . $counts[$k] . '</b></button>';
    }
    if ($partner) {
        echo '<button type="button" data-seat="partner"><span class="sw s-partner"></span>Partner-managed <b>' . $partner . '</b></button>';
    }
    echo '</div><div class="cw-fp-find"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>'
       . '<input type="search" id="cwSeatFind" placeholder="Find a startup or seat" aria-label="Find a startup or seat"></div></section>';

    $ri = 0;
    foreach ($rooms as $room => $list) {
        $isOpen = $room === "\x7fOpen space";
        $isCabins = $room === "\x7fCabins" || $isOpen;
        $label = $isOpen ? 'Open space' : ($isCabins ? 'Cabins' : $room);
        $taken = count(array_filter($list, static fn($s) => in_array($s['occupied_by'], ['Startup', 'Staff', 'Vendor'], true)));
        $partners = array_values(array_unique(array_filter(array_map(static fn($s) => $s['_own'] ? '' : $s['managed_by'], $list))));
        $f = count($list) ? $taken / count($list) : 0;
        echo '<section class="cw-room cw-rise" style="--i:' . min($ri++, 6) . '"><header class="cw-room-h"><div><h2>' . e($label) . '</h2>'
           . '<span>' . count($list) . ' ' . ($isOpen ? 'open spaces · ' . (float) array_sum(array_column($list, 'area')) . ' sq ft' : ($isCabins ? 'cabins' : 'desks')) . ($partners ? ' · partner-managed seats: ' . e(implode(', ', $partners)) : '') . '</span></div>'
           . '<div class="cw-room-occ"><b>' . $taken . '<small>/' . count($list) . '</small></b><span class="cw-track"><i style="width:' . round($f * 100, 1) . '%"></i></span></div></header>';
        if ($isCabins) {
            echo '<div class="cw-floor cw-cabins">';
            foreach ($list as $j => $s) {
                [$cls] = cw_seat_state($s);
                $num = preg_replace('~^.*?(\d+)$~', '$1', $s['space_id']);
                echo '<button type="button" class="cw-cabin s-' . $cls . ($s['_own'] ? '' : ' partner') . '" style="--i:' . $j . '"'
                   . ' data-state="' . $cls . ($s['_own'] ? '' : ' partner') . '" data-occ="' . e(cw_key($s['occupant'])) . '"'
                   . ' data-find="' . e(mb_strtolower($s['space_id'] . ' ' . $s['occupant'])) . '" data-info="' . e(json_encode($info($s))) . '"'
                   . ' data-row="' . e(json_encode(cw_space_row($s))) . '" aria-label="' . e($s['space_id'] . ', ' . cw_seat_state($s)[1] . ($s['occupant'] !== '' ? ', ' . $s['occupant'] : '')) . '">'
                   . '<span class="door" aria-hidden="true"></span><span class="num">'
                   . e($s['type'] === 'Open space' ? $s['space_id'] . ($s['area'] ? ' · ' . (float) $s['area'] . ' sq ft' : '') : 'Cabin ' . $num) . '</span>'
                   . '<span class="who">' . e($s['occupant'] !== '' ? $s['occupant'] : cw_seat_state($s)[1]) . '</span></button>';
            }
            echo '</div>';
        } else {
            // Pods of ten: two facing rows of five around one bench.
            echo '<div class="cw-floor">';
            foreach (array_chunk($list, 10) as $pi => $pod) {
                echo '<div class="cw-pod">';
                foreach ([array_slice($pod, 0, 5), array_slice($pod, 5, 5)] as $side => $row) {
                    if (!$row) {
                        continue;
                    }
                    echo '<div class="cw-pod-row ' . ($side ? 'b' : 't') . '">';
                    foreach ($row as $j => $s) {
                        [$cls, $word] = cw_seat_state($s);
                        $num = preg_replace('~^.*?(\d+)$~', '$1', $s['space_id']);
                        $mono = $s['occupant'] !== '' ? cw_initials($s['occupant']) : ($cls === 'free' ? '+' : '·');
                        echo '<button type="button" class="cw-desk s-' . $cls . ($s['_own'] ? '' : ' partner') . '" style="--i:' . ($pi * 10 + $side * 5 + $j) . '"'
                           . ' data-state="' . $cls . ($s['_own'] ? '' : ' partner') . '" data-occ="' . e(cw_key($s['occupant'])) . '"'
                           . ' data-find="' . e(mb_strtolower($s['space_id'] . ' ' . $s['occupant'])) . '" data-info="' . e(json_encode($info($s))) . '"'
                           . ' data-row="' . e(json_encode(cw_space_row($s))) . '" data-tip="' . e($s['space_id'] . ' · ' . ($s['occupant'] !== '' ? $s['occupant'] : $word)) . '"'
                           . ' aria-label="' . e($s['space_id'] . ', ' . $word . ($s['occupant'] !== '' ? ', ' . $s['occupant'] : '')) . '">'
                           . '<span class="chair" aria-hidden="true"></span><span class="num">' . e($num) . '</span><span class="mono">' . e($mono) . '</span></button>';
                    }
                    echo '</div>';
                }
                echo '</div>';
            }
            echo '</div>';
        }
        echo '</section>';
    }
    echo '</div>';

    /* The panel: the chosen seat, or -- with none chosen -- the key, each room and who holds the most seats. */
    echo '<aside class="cw-fp-side" aria-live="polite"><div class="cw-card cw-inspect" id="cwInspect" data-edit="' . (cw_can_edit() ? '1' : '') . '">'
       . '<div class="cw-inspect-empty"><h2>Floor summary</h2><div class="cw-fp-ring">' . cw_ring($total ? $inUse / $total : 0, 'seats in use', 112)
       . '<dl><div><dt>In use</dt><dd>' . $inUse . ' / ' . $total . '</dd></div><div><dt>Available</dt><dd>' . $counts['free'] . '</dd></div>'
       . '<div><dt>Not checked</dt><dd>' . $counts['unchecked'] . '</dd></div></dl></div>'
       . '<h3 class="cw-sub">Key</h3><ul class="cw-key">';
    foreach ($states as $k => $l) {
        echo '<li><span class="cw-desk mini s-' . $k . '"></span>' . e($l) . '</li>';
    }
    if ($partner) {
        echo '<li><span class="cw-desk mini s-free partner"></span>Partner-managed</li>';
    }
    echo '</ul><h3 class="cw-sub">Who holds the most seats</h3><ul class="cw-holders">';
    $max = $holders ? max(array_column($holders, 'n')) : 1;
    foreach (array_slice($holders, 0, 12, true) as $k => $h) {
        echo '<li data-occ="' . e($k) . '" tabindex="0"><span class="nm">' . e($h['name']) . ($h['vendor'] ? ' <small>vendor</small>' : '') . '</span>'
           . '<span class="cw-track"><i style="width:' . round($h['n'] / $max * 100, 1) . '%"></i></span><b>' . $h['n'] . '</b></li>';
    }
    echo '</ul><p class="cw-note">Choose a seat to see who holds it, their agreement and dues.</p></div>'
       . '<div class="cw-inspect-seat" hidden></div></div></aside>';
    echo '</div>';
    cw_space_dialog($m);
}

/** A space as its edit form wants it. */
function cw_space_row(array $s): array
{
    return array_intersect_key($s, array_flip(['id', 'space_id', 'type', 'zone', 'managed_by', 'occupied_by', 'occupant', 'agreement',
        'agreement_date', 'action', 'vendor_what', 'vendor_contact', 'vendor_phone', 'people', 'using_since', 'checked_by', 'checked_on', 'notes', 'area']));
}

function cw_options(array $opts, string $blank = ''): string
{
    $h = '';
    foreach ($opts as $o) {
        $h .= '<option value="' . e($o) . '">' . e($o === '' ? $blank : $o) . '</option>';
    }
    return $h;
}

function cw_space_dialog(array $m): void
{
    if (!cw_can_edit()) {
        return;
    }
    $c = cw_choices();
    $names = array_column($m['startups'], 'name');
    echo '<datalist id="cwStartupNames">' . implode('', array_map(static fn($n) => '<option value="' . e($n) . '">', $names)) . '</datalist>';
    echo '<dialog class="cw-dlg" id="dlg-space" aria-labelledby="dlg-space-h"><form method="post" class="cw-form">' . fabo_csrf_field()
       . '<input type="hidden" name="a" value="space_save"><input type="hidden" name="id">'
       . '<div class="cw-dlg-h"><h2 id="dlg-space-h" data-new="Add a space" data-edit="Edit space">Space</h2><button type="button" class="cw-x" data-close aria-label="Close">×</button></div>'
       . '<div class="cw-fields">'
       . '<label>Space ID<input name="space_id" required maxlength="80"></label>'
       . '<label>Type<select name="type">' . cw_options($c['type']) . '</select></label>'
       . '<label>Zone<input name="zone" maxlength="60" list="cwZones"></label>'
       . '<label>Area (sq ft)<input inputmode="decimal" name="area"><small>Open space only: rent is area × the open-space rate.</small></label>'
       . '<label>Managed by<input name="managed_by" maxlength="60" placeholder="' . e($m['centre']['manager']) . '"></label>'
       . '<label>Occupied by<select name="occupied_by" data-toggle-vendor>' . cw_options($c['occupied_by']) . '</select></label>'
       . '<label class="wide">Startup / vendor name<input name="occupant" maxlength="190" list="cwStartupNames"></label>'
       . '<label>Agreement<select name="agreement">' . cw_options($c['agreement'], '—') . '</select></label>'
       . '<label>Agreement date<input type="date" name="agreement_date"></label>'
       . '<label>Action / notice<select name="action">' . cw_options($c['action'], 'None') . '</select></label>'
       . '<fieldset class="wide cw-vendor"><legend>Vendor details</legend><div class="cw-fields">'
       . '<label>What they do<input name="vendor_what"></label><label>Contact<input name="vendor_contact"></label>'
       . '<label>Phone<input name="vendor_phone"></label><label>No. of people<input type="number" min="0" name="people"></label>'
       . '<label>Using since<input type="date" name="using_since"></label></div></fieldset>'
       . '<label>Checked by<input name="checked_by"></label><label>Checked on<input type="date" name="checked_on"></label>'
       . '<label class="wide">Notes<textarea name="notes" rows="2"></textarea></label></div>'
       . '<div class="cw-dlg-f"><button type="submit" class="btn btn-primary">Save space</button><button type="button" class="btn" data-close>Cancel</button></div></form>'
       . '<form method="post" class="cw-del" data-confirm="Remove this space from the register?">' . fabo_csrf_field()
       . '<input type="hidden" name="a" value="space_delete"><input type="hidden" name="id"><button class="btn btn-sm cw-danger">Remove space</button></form></dialog>';
    $zones = array_unique(array_filter(array_column($m['spaces'], 'zone')));
    echo '<datalist id="cwZones">' . implode('', array_map(static fn($z) => '<option value="' . e($z) . '">', $zones)) . '</datalist>';
}

function cw_page_spaces(array $m): void
{
    $zones = array_values(array_unique(array_filter(array_column($m['spaces'], 'zone'))));
    $known = [];
    foreach ($m['startups'] as $s) {
        $known[cw_key($s['name'])] = true;
    }
    echo '<section class="cw-card"><div class="cw-toolbar">'
       . '<input type="search" data-find-in="#cwSpaces" placeholder="Search spaces, names…" aria-label="Search spaces">'
       . '<select data-filter-in="#cwSpaces" data-key="zone" aria-label="Zone"><option value="">All zones</option>' . cw_options($zones) . '<option value="Cabins">Cabins</option><option value="Open space">Open space</option></select>'
       . '<select data-filter-in="#cwSpaces" data-key="occ" aria-label="Occupied by"><option value="">Anyone</option>' . cw_options(cw_choices()['occupied_by']) . '</select>'
       . '<span class="cw-grow"></span><span class="hint">' . count($m['spaces']) . ' spaces</span>'
       . '<button type="button" class="btn btn-primary btn-sm cw-edit" data-dlg="space" data-row="{}">+ Add space</button></div>';
    echo '<div class="cw-scroll"><table class="cw-table cw-hover" id="cwSpaces"><thead><tr><th>Space</th><th>Zone</th><th>Managed by</th><th>Occupied by</th><th>Startup / vendor</th>'
       . '<th>Agreement</th><th>Ends</th><th>Renewal</th><th>Action</th><th>Check</th><th class="cw-edit"></th></tr></thead><tbody>';
    foreach ($m['spaces'] as $s) {
        $check = $s['occupied_by'] === 'Not checked' ? cw_pill('To check', 'warn')
               : ($s['occupied_by'] === 'Startup' && ($s['occupant'] === '' || !isset($known[cw_key($s['occupant'])])) ? cw_pill('Name not on register', 'risk') : cw_pill('OK', 'good'));
        $zone = $s['type'] === 'Cabin' ? 'Cabins' : ($s['type'] === 'Open space' ? 'Open space' : $s['zone']);
        echo '<tr data-zone="' . e($zone) . '" data-occ="' . e($s['occupied_by']) . '" data-find="' . e(mb_strtolower($s['space_id'] . ' ' . $s['occupant'] . ' ' . $s['notes'])) . '">'
           . '<td><strong>' . e($s['space_id']) . '</strong><small>' . e($s['type'] . ($s['area'] ? ' · ' . (float) $s['area'] . ' sq ft' : '')) . '</small></td><td>' . e($zone) . '</td>'
           . '<td>' . e($s['managed_by'] ?: $m['centre']['manager']) . '</td><td>' . cw_pill($s['occupied_by'], $s['occupied_by'] === 'Vacant' ? 'mute' : null) . '</td>'
           . '<td>' . e($s['occupant']) . '</td><td>' . e($s['agreement']) . ($s['agreement_date'] ? '<small>' . e(cw_d($s['agreement_date'])) . '</small>' : '') . '</td>'
           . '<td>' . e(cw_d($s['_end'])) . '</td><td>' . cw_pill($s['_renew']) . '</td><td>' . cw_pill($s['action']) . '</td><td>' . $check . '</td>'
           . '<td class="cw-edit"><button type="button" class="btn btn-sm" data-dlg="space" data-row="' . e(json_encode(cw_space_row($s))) . '">Edit</button></td></tr>';
    }
    if (!$m['spaces']) {
        echo '<tr><td colspan="11" class="cw-muted">No spaces yet. Add them here, in bulk from Settings, or import the workbook.</td></tr>';
    }
    echo '</tbody></table><p class="cw-none" hidden>No spaces match.</p></div></section>';
    cw_space_dialog($m);
}

function cw_page_startups(array $m): void
{
    $statuses = array_keys($m['by_status']);
    echo '<section class="cw-card"><div class="cw-toolbar">'
       . '<input type="search" data-find-in="#cwStartups" placeholder="Search startups…" aria-label="Search startups">'
       . '<select data-filter-in="#cwStartups" data-key="st" aria-label="Status"><option value="">Any status</option>' . cw_options($statuses) . '</select>'
       . '<span class="cw-grow"></span><span class="hint">Rates: desk ' . e(cw_inr($m['centre']['desk_rate'])) . ' · cabin ' . e(cw_inr($m['centre']['cabin_rate']))
       . ((float) $m['centre']['open_rate'] > 0 ? ' · open space ' . e(cw_inr($m['centre']['open_rate'])) . ' per sq ft' : '') . ' / month</span>'
       . '<button type="button" class="btn btn-primary btn-sm cw-edit" data-dlg="startup" data-row="{}">+ Add startup</button></div>';
    echo '<div class="cw-scroll"><table class="cw-table cw-hover" id="cwStartups"><thead><tr><th class="cw-name">Startup</th><th>Status</th><th>Billable</th><th class="num">Desks</th><th class="num">Cabins</th>'
       . '<th class="num">Rent at full rate</th><th class="num">Rent charged</th><th class="num">Dues</th><th class="num">Deposit</th><th>Agreement</th><th>Email</th><th class="cw-edit"></th></tr></thead><tbody>';
    $tot = ['d' => 0, 'c' => 0, 'f' => 0, 'r' => 0, 'du' => 0, 'de' => 0];
    foreach ($m['startups'] as $s) {
        $tot['d'] += $s['_desks'];
        $tot['c'] += $s['_cabins'];
        $tot['f'] += $s['_full'];
        $tot['r'] += $s['_rent'];
        $tot['du'] += (float) $s['due_amount'];
        $tot['de'] += (float) $s['deposit'];
        $dep = $s['deposit'] !== null ? cw_inr($s['deposit']) : '';
        $depNote = (int) $s['deposit_not_paid'] ? '<small class="risk">Not paid</small>' : ($s['deposit_paid_on'] ? '<small>paid ' . e(cw_d($s['deposit_paid_on'])) . '</small>' : ($dep !== '' ? '<small class="warn">date missing</small>' : ''));
        $row = array_intersect_key($s, array_flip(['id', 'name', 'type', 'email', 'phone', 'billable', 'due_amount', 'dues_as_on', 'deposit', 'deposit_paid_on', 'deposit_not_paid', 'notes']));
        echo '<tr data-st="' . e($s['_status']) . '" data-find="' . e(mb_strtolower($s['name'] . ' ' . $s['email'] . ' ' . $s['phone'])) . '">'
           . '<td><strong>' . e($s['name']) . '</strong><small>' . e(implode(' · ', array_filter([$s['type'], $s['phone']]))) . ($s['_partner'] ? ' · ' . $s['_partner'] . ' partner desk(s)' : '') . '</small></td>'
           . '<td>' . cw_pill($s['_status']) . '</td>'
           . '<td>' . cw_pill($s['billable'], $s['billable'] === 'Yes' ? 'good' : ($s['billable'] === 'To confirm' ? 'risk' : 'mute')) . '</td>'
           . '<td class="num">' . $s['_desks'] . '</td><td class="num">' . $s['_cabins'] . ($s['_open'] > 0 ? '<small>+ ' . (float) $s['_open'] . ' sq ft</small>' : '') . '</td>'
           . '<td class="num">' . e(cw_inr($s['_full'])) . '</td><td class="num">' . e(cw_inr($s['_rent'])) . '</td>'
           . '<td class="num' . ((float) $s['due_amount'] > 0 ? ' risk' : '') . '">' . e($s['due_amount'] !== null ? cw_inr($s['due_amount']) : '') . ($s['dues_as_on'] ? '<small>as on ' . e(cw_d($s['dues_as_on'])) . '</small>' : '') . '</td>'
           . '<td class="num">' . e($dep) . $depNote . '</td>'
           . '<td>' . ($s['_signed'] ? 'Signed' : ($s['_own_n'] ? '<span class="warn">Not signed</span>' : '')) . ($s['_first'] ? '<small>since ' . e(cw_d($s['_first'], 'M Y')) . ' · ' . (int) $s['_months'] . ' mo</small>' : '') . '</td>'
           . '<td>' . (trim((string) $s['email']) !== '' ? '<a class="cw-mail" title="' . e($s['email']) . '" href="mailto:' . e($s['email']) . '">' . e($s['email']) . '</a>' : '<span class="cw-muted">—</span>') . '</td>'
           . '<td class="cw-edit"><button type="button" class="btn btn-sm" data-dlg="startup" data-row="' . e(json_encode($row)) . '">Edit</button></td></tr>';
    }
    if (!$m['startups']) {
        echo '<tr><td colspan="12" class="cw-muted">No startups yet.</td></tr>';
    }
    echo '</tbody><tfoot><tr class="sum"><td>Totals</td><td></td><td></td><td class="num">' . $tot['d'] . '</td><td class="num">' . $tot['c'] . '</td><td class="num">' . e(cw_inr($tot['f']))
       . '</td><td class="num">' . e(cw_inr($tot['r'])) . '</td><td class="num">' . e(cw_inr($tot['du'])) . '</td><td class="num">' . e(cw_inr($tot['de'])) . '</td><td colspan="3"></td></tr></tfoot>'
       . '</table><p class="cw-none" hidden>No startups match.</p></div></section>';
    if (!cw_can_edit()) {
        return;
    }
    $c = cw_choices();
    echo '<dialog class="cw-dlg" id="dlg-startup" aria-labelledby="dlg-startup-h"><form method="post" class="cw-form">' . fabo_csrf_field()
       . '<input type="hidden" name="a" value="startup_save"><input type="hidden" name="id">'
       . '<div class="cw-dlg-h"><h2 id="dlg-startup-h" data-new="Add a startup" data-edit="Edit startup">Startup</h2><button type="button" class="cw-x" data-close aria-label="Close">×</button></div>'
       . '<div class="cw-fields"><label class="wide">Startup name<input name="name" required maxlength="190"></label>'
       . '<label>Type<select name="type">' . cw_options($c['startup_type']) . '</select></label>'
       . '<label>Billable<select name="billable">' . cw_options($c['billable']) . '</select></label>'
       . '<label>Email<input type="email" name="email"></label><label>Phone<input name="phone"></label>'
       . '<label>Due amount (₹)<input inputmode="decimal" name="due_amount"></label><label>Dues as on<input type="date" name="dues_as_on"></label>'
       . '<label>Security deposit (₹)<input inputmode="decimal" name="deposit"></label><label>Deposit paid on<input type="date" name="deposit_paid_on"></label>'
       . '<label class="cw-check"><input type="checkbox" name="deposit_not_paid" value="1"> Deposit not paid</label>'
       . '<label class="wide">Notes<textarea name="notes" rows="2"></textarea></label></div>'
       . '<p class="cw-note">Desks, cabins and rent come from the Space Register: give each space this exact name there.</p>'
       . '<div class="cw-dlg-f"><button type="submit" class="btn btn-primary">Save startup</button><button type="button" class="btn" data-close>Cancel</button></div></form>'
       . '<form method="post" class="cw-del" data-confirm="Remove this startup from the register? Keep it until its dues are settled.">' . fabo_csrf_field()
       . '<input type="hidden" name="a" value="startup_delete"><input type="hidden" name="id"><button class="btn btn-sm cw-danger">Remove startup</button></form></dialog>';
}

function cw_page_waiting(array $m): void
{
    $c = cw_choices();
    $hist = cw_events_for(array_column($m['waiting'], 'id'));
    $fmtHist = static fn(array $h) => array_map(static fn($e) => date('d M Y, H:i', strtotime($e[0])) . ' · ' . $e[2] . ($e[3] !== '' ? ' (' . $e[3] . ')' : ''), $h);
    echo '<section class="cw-card"><div class="cw-toolbar"><span class="hint">Move each applicant through New → In review → Approved → Offer sent → Moved in (or Dropped). '
       . 'Applications from the website form arrive here as New; each stage can email the applicant (wording on Form &amp; emails).</span><span class="cw-grow"></span>'
       . '<a class="btn btn-sm cw-edit" href="' . e(cw_url('forms')) . '">Form &amp; emails</a>'
       . '<button type="button" class="btn btn-primary btn-sm cw-edit" data-dlg="wait" data-row="' . e(json_encode(['mail' => '1'])) . '">+ Add applicant</button></div></section>';
    echo '<div class="cw-board">';
    foreach ($c['wait_status'] as $i => $st) {
        $list = array_values(array_filter($m['waiting'], static fn($w) => $w['status'] === $st));
        echo '<section class="cw-col cw-rise" style="--i:' . $i . '"><h2>' . cw_pill($st) . ' <b>' . count($list) . '</b></h2>';
        foreach ($list as $w) {
            $row = array_intersect_key($w, array_flip(['id', 'applicant', 'contact', 'email', 'phone', 'sector', 'desks_needed', 'cabin_needed',
                'preferred_zone', 'source', 'applied_on', 'status', 'expected_start', 'next_step', 'owner', 'notes']));
            $row['mail'] = '1';
            $row['_history'] = $fmtHist($hist[(int) $w['id']] ?? []);
            $row['_title'] = trim(($w['ref'] ?? '') . ' · ' . ($w['channel'] ?? ''), ' ·');
            echo '<button type="button" class="cw-wcard"' . (cw_can_edit() ? ' data-dlg="wait" data-row="' . e(json_encode($row)) . '"' : '') . '>'
               . '<span class="cw-wref">' . e((string) ($w['ref'] ?? '')) . (($w['channel'] ?? '') === 'Website' ? ' <i>Website</i>' : '') . '</span>'
               . '<strong>' . e($w['applicant']) . '</strong><span>' . e(implode(' · ', array_filter([$w['sector'], $w['desks_needed'] ? $w['desks_needed'] . ' desk(s)' : '', $w['cabin_needed'] === 'Yes' ? 'cabin' : ''])))
               . '</span><small>' . ($w['_days'] !== null ? 'Waiting ' . $w['_days'] . ' days' : '') . ($w['expected_start'] ? ' · start ' . e(cw_d($w['expected_start'])) : '') . '</small>'
               . ($w['next_step'] ? '<em>Next: ' . e($w['next_step']) . '</em>' : '') . '</button>';
        }
        if (!$list) {
            echo '<p class="cw-muted">None</p>';
        }
        echo '</section>';
    }
    echo '</div>';
    if (!cw_can_edit()) {
        return;
    }
    $zones = array_values(array_unique(array_filter(array_column($m['spaces'], 'zone'))));
    echo '<dialog class="cw-dlg" id="dlg-wait" aria-labelledby="dlg-wait-h"><form method="post" class="cw-form">' . fabo_csrf_field()
       . '<input type="hidden" name="a" value="wait_save"><input type="hidden" name="id">'
       . '<div class="cw-dlg-h"><div><h2 id="dlg-wait-h" data-new="Add an applicant" data-edit="Edit applicant">Applicant</h2><small class="cw-dlg-sub" data-title></small></div>'
       . '<button type="button" class="cw-x" data-close aria-label="Close">×</button></div>'
       . '<div class="cw-fields"><label class="wide">Applicant / startup<input name="applicant" required maxlength="190"></label>'
       . '<label>Contact person<input name="contact"></label><label>Email<input type="email" name="email"></label><label>Phone<input name="phone"></label>'
       . '<label>Sector<input name="sector"></label><label>Desks needed<input type="number" min="0" name="desks_needed"></label>'
       . '<label>Cabin needed<select name="cabin_needed">' . cw_options(['', 'Yes', 'No'], '—') . '</select></label>'
       . '<label>Preferred zone<input name="preferred_zone" list="cwZones2"></label><label>Source / referred by<input name="source"></label>'
       . '<label>Applied on<input type="date" name="applied_on"></label><label>Status<select name="status">' . cw_options($c['wait_status']) . '</select></label>'
       . '<label>Expected start<input type="date" name="expected_start"></label><label>Owner<input name="owner"></label>'
       . '<label class="wide">Next step<input name="next_step"><small>Shown in the Approved and Offer sent emails as {next_step}.</small></label>'
       . '<label class="wide">Notes<textarea name="notes" rows="2"></textarea></label>'
       . '<label class="wide cw-check cw-mailbox"><input type="checkbox" name="mail" value="1"> Email the applicant when the stage changes (or, for a new applicant, the acknowledgement)</label>'
       . '<div class="wide cw-history" data-history hidden><h3 class="cw-sub">History</h3><ul></ul></div></div>'
       . '<div class="cw-dlg-f"><button type="submit" class="btn btn-primary">Save applicant</button><button type="button" class="btn" data-close>Cancel</button></div></form>'
       . '<form method="post" class="cw-del" data-confirm="Remove this applicant?">' . fabo_csrf_field()
       . '<input type="hidden" name="a" value="wait_delete"><input type="hidden" name="id"><button class="btn btn-sm cw-danger">Remove applicant</button></form></dialog>'
       . '<datalist id="cwZones2">' . implode('', array_map(static fn($z) => '<option value="' . e($z) . '">', $zones)) . '</datalist>';
}

/** The portal's own address, for links that leave it (the public form). */
function cw_portal_base(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    $dir = preg_replace('~/admin$~', '', $dir);
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir . '/';
}

/**
 * Form & emails: the public application form (its link, the code to embed
 * it on a website, who hears of new applications) and the email each stage
 * sends, in the organisation's own words.
 */
function cw_page_forms(array $m): void
{
    $org = (int) $GLOBALS['cw_org'];
    $key = cw_form_key($org);
    $url = cw_portal_base() . 'coworking-apply.php?k=' . $key;
    $on = cw_form_on($org);
    $embed = '<iframe src="' . $url . '&embed=1" title="Co-working application" style="width:100%;min-height:1150px;border:0" loading="lazy"></iframe>';
    $recent = fabo_all("SELECT w.*, c.name centre FROM cw_waiting w JOIN cw_centres c ON c.id = w.centre_id
                         WHERE c.org_id = ? AND w.channel = 'Website' ORDER BY w.id DESC LIMIT 8", 'i', [$org]);
    $csrf = fabo_csrf_field();
    $ro = !cw_can_edit();

    echo '<div class="cw-grid2"><section class="cw-card cw-rise"><div class="cw-card-h"><h2>Website application form</h2>'
       . cw_pill($on ? 'Open' : 'Closed', $on ? 'good' : 'mute') . '</div>'
       . '<p>Put this form on your website. Every application lands on the chosen centre\'s <strong>Waiting list</strong> as <em>New</em>, '
       . 'the applicant gets the acknowledgement email, and your team is told.</p>'
       . '<label class="cw-copy"><span>Link to the form</span><span class="cw-copy-row"><input readonly value="' . e($url) . '" id="cwFormUrl">'
       . '<button type="button" class="btn btn-sm" data-copy="#cwFormUrl">Copy</button><a class="btn btn-sm" href="' . e($url) . '" target="_blank" rel="noopener">Open</a></span></label>'
       . '<label class="cw-copy"><span>Embed on a web page</span><span class="cw-copy-row"><textarea readonly rows="3" id="cwFormEmbed">' . e($embed) . '</textarea>'
       . '<button type="button" class="btn btn-sm" data-copy="#cwFormEmbed">Copy</button></span></label>'
       . '<p class="cw-note">Paste the embed code where the form should appear (for example a WordPress "Custom HTML" block), or link a button to the address above.</p>';
    if (!$ro) {
        echo '<form method="post" class="cw-form">' . $csrf . '<input type="hidden" name="a" value="form_save"><div class="cw-fields">'
           . '<label class="wide cw-check"><input type="checkbox" name="on" value="1"' . ($on ? ' checked' : '') . '> Accept applications</label>'
           . '<label class="wide">Welcome text at the top of the form<textarea name="intro" rows="2" maxlength="600">' . e(cw_org_setting($org, 'form_intro')) . '</textarea></label>'
           . '<label class="wide">Tell these people about each new application<input name="notify" value="' . e(cw_org_setting($org, 'notify')) . '" placeholder="' . e(implode(', ', cw_notify_emails($org)) ?: 'name@example.com') . '">'
           . '<small>Separate several addresses with commas. Left empty, it goes to the organisation\'s own email.</small></label></div>'
           . '<div class="cw-dlg-f"><button class="btn btn-primary">Save form settings</button></div></form>'
           . '<form method="post" class="cw-del cw-renew" data-confirm="Make a new link? The old link and any embedded form stop working until you replace them.">' . $csrf
           . '<input type="hidden" name="a" value="form_renew"><button class="btn btn-sm">Make a new link</button></form>';
    }
    echo '</section>';

    echo '<section class="cw-card cw-rise" style="--i:1"><div class="cw-card-h"><h2>Latest website applications</h2><a class="hint" href="' . e(cw_url('waiting')) . '">Waiting list →</a></div>';
    if (!$recent) {
        echo '<p class="cw-muted">None yet. They appear here, and on the centre\'s Waiting list, the moment someone applies.</p>';
    } else {
        echo '<ul class="cw-mini">';
        foreach ($recent as $w) {
            echo '<li><span>' . e($w['applicant']) . '<small>' . e($w['ref'] . ' · ' . $w['centre'] . ' · ' . cw_d($w['applied_on'])) . '</small></span>' . cw_pill($w['status']) . '</li>';
        }
        echo '</ul>';
    }
    echo '</section></div>';

    // The emails, one per stage.
    echo '<section class="cw-card cw-rise" style="--i:2"><div class="cw-card-h"><h2>Emails to applicants</h2><span class="hint">sent as ' . e(cw_org_name($org)) . '; replies go to you</span></div>'
       . '<p>The acknowledgement goes out when someone applies; the others when you move an application to that stage on the Waiting list. '
       . 'Turn any of them off, or write them in your own words. These words are replaced for each applicant: '
       . '<code>{contact}</code> <code>{applicant}</code> <code>{centre}</code> <code>{organisation}</code> <code>{reference}</code> '
       . '<code>{desks}</code> <code>{cabin}</code> <code>{expected_start}</code> <code>{status}</code> <code>{next_step}</code>.</p>';
    echo '<form method="post" class="cw-form">' . $csrf . '<input type="hidden" name="a" value="tpl_save"><div class="cw-tpls">';
    foreach (cw_mail_stages() as $stage => $label) {
        [$en, $subject, $body] = cw_mail_tpl($org, $stage);
        $k = md5($stage);
        echo '<details class="cw-tpl"' . ($stage === 'received' ? ' open' : '') . '><summary><span class="cw-tpl-t">' . e($label) . '</span>'
           . cw_pill($en ? 'On' : 'Off', $en ? 'good' : 'mute') . '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg></summary><div class="cw-fields">'
           . '<input type="hidden" name="stage[' . $k . ']" value="' . e($stage) . '">'
           . '<label class="wide cw-check"><input type="checkbox" name="on[' . $k . ']" value="1"' . ($en ? ' checked' : '') . ($ro ? ' disabled' : '') . '> Send this email</label>'
           . '<label class="wide">Subject<input name="subject[' . $k . ']" maxlength="255" value="' . e($subject) . '"' . ($ro ? ' readonly' : '') . '></label>'
           . '<label class="wide">Message<textarea name="body[' . $k . ']" rows="8"' . ($ro ? ' readonly' : '') . '>' . e($body) . '</textarea></label></div></details>';
    }
    echo '</div>' . ($ro ? '' : '<div class="cw-dlg-f"><button class="btn btn-primary">Save emails</button>'
       . '<button class="btn" name="reset" value="1" formnovalidate data-confirm-btn="Put every email back to the standard wording?">Restore standard wording</button></div>') . '</form></section>';
}

function cw_page_renewals(array $m): void
{
    $rn = $m['renewals'];
    echo '<section class="cw-kpis four">';
    $i = 0;
    foreach ($rn as $k => $v) {
        echo cw_kpi($k, cw_num($v), $v === 1 ? 'agreement' : 'agreements', cw_tone($k) === 'mute' ? '' : cw_tone($k), $i++);
    }
    echo '</section>';
    echo '<section class="cw-card"><div class="cw-toolbar"><input type="search" data-find-in="#cwRenew" placeholder="Search startups…" aria-label="Search">'
       . '<select data-filter-in="#cwRenew" data-key="st" aria-label="Status"><option value="">Any status</option>' . cw_options(array_keys($rn)) . '</select>'
       . '<span class="cw-grow"></span><span class="hint">Agreements run ' . (int) $m['centre']['term_months'] . ' months. To renew, enter the new Agreement date on the space.</span></div>'
       . '<div class="cw-scroll"><table class="cw-table cw-hover" id="cwRenew"><thead><tr><th>Startup</th><th class="num">Spaces</th><th>Agreement date</th><th>Ends on</th><th>Days left</th><th>Status</th><th>Remarks</th></tr></thead><tbody>';
    foreach ($m['agreements'] as $g) {
        $w = max(0, min(100, ($g['days'] + 30) / 365 * 100));
        echo '<tr data-st="' . e($g['status']) . '" data-find="' . e(mb_strtolower($g['startup'] . ' ' . implode(' ', $g['space_ids']))) . '"><td><strong>' . e($g['startup']) . '</strong><small>' . e(implode(', ', $g['space_ids'])) . '</small></td>'
           . '<td class="num">' . $g['spaces'] . '</td><td>' . e(cw_d($g['date'])) . '</td><td>' . e(cw_d($g['end'])) . '</td>'
           . '<td><span class="cw-days ' . cw_tone($g['status']) . '"><i style="width:' . round($w, 1) . '%"></i><b>' . ($g['days'] < 0 ? abs($g['days']) . ' over' : $g['days']) . '</b></span></td>'
           . '<td>' . cw_pill($g['status']) . '</td><td>' . e(implode(', ', array_unique($g['remarks']))) . '</td></tr>';
    }
    if (!$m['agreements']) {
        echo '<tr><td colspan="7" class="cw-muted">No dated agreements on the Space Register yet.</td></tr>';
    }
    echo '</tbody></table><p class="cw-none" hidden>No agreements match.</p></div></section>';
}

function cw_page_actions(array $m): void
{
    echo '<section class="cw-kpis three">';
    $i = 0;
    foreach ($m['actions'] as $prio => $issues) {
        $n = array_sum(array_map('count', $issues));
        echo cw_kpi($prio . ' priority', cw_num($n), count($issues) . ' kind' . (count($issues) === 1 ? '' : 's') . ' of issue', $n ? cw_tone($prio === 'Low' ? 'Vendor' : $prio) : 'good', $i++);
    }
    echo '</section>';
    if (!$m['action_count']) {
        echo '<section class="cw-card cw-zero"><h2>All clear</h2><p>Nothing needs following up at ' . e($m['centre']['name']) . '.</p></section>';
        return;
    }
    $i = 0;
    foreach ($m['actions'] as $prio => $issues) {
        foreach ($issues as $issue => $items) {
            echo '<details class="cw-issue cw-rise ' . cw_tone($prio === 'Low' ? 'Vendor' : $prio) . '" style="--i:' . min($i++, 12) . '"' . ($prio === 'High' ? ' open' : '') . '>'
               . '<summary><span class="cw-prio">' . e($prio) . '</span><strong>' . e($issue) . '</strong><b>' . count($items) . '</b>'
               . '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg></summary><ul>';
            foreach ($items as [$who, $detail]) {
                echo '<li><strong>' . e($who) . '</strong><span>' . e($detail) . '</span></li>';
            }
            echo '</ul></details>';
        }
    }
}

function cw_page_log(array $m): void
{
    $live = cw_snapshot_row($m);
    echo '<div class="cw-grid2"><section class="cw-card cw-rise"><div class="cw-card-h"><h2>Desk occupancy trend</h2></div>';
    cw_trend($m);
    echo '</section><section class="cw-card cw-rise" style="--i:1"><div class="cw-card-h"><h2>Month-end snapshot</h2></div>'
       . '<p>On the last working day of the month, keep this month\'s numbers so the trend has them. Taking it again in the same month replaces that month\'s row.</p>'
       . '<form method="post" class="cw-edit cw-inline">' . fabo_csrf_field() . '<input type="hidden" name="a" value="snapshot">'
       . '<input name="notes" placeholder="Note (optional)" maxlength="500" aria-label="Note"><button class="btn btn-primary">Take snapshot for ' . e(date('F Y')) . '</button></form></section></div>';
    $cols = ['desks_occupied' => 'Desks occupied', 'occupancy' => 'Occupancy', 'cabins_occupied' => 'Cabins', 'vendors' => 'Vendors', 'truly_vacant' => 'Truly vacant',
             'rent' => 'Rent charged', 'billing_confirm' => 'Billing to confirm', 'dues' => 'Dues', 'action_items' => 'Action items', 'waiting_open' => 'Waiting list',
             'renewals_due' => 'Renewals due'];
    $cell = static function (string $k, $v): string {
        if ($v === null || $v === '') {
            return '';
        }
        return in_array($k, ['rent', 'billing_confirm', 'dues'], true) ? cw_inr($v) : ($k === 'occupancy' ? cw_pct((float) $v) : (string) (int) $v);
    };
    echo '<section class="cw-card"><div class="cw-scroll"><table class="cw-table"><thead><tr><th>Month</th>';
    foreach ($cols as $l) {
        echo '<th class="num">' . e($l) . '</th>';
    }
    echo '<th>Notes</th><th class="cw-edit"></th></tr></thead><tbody><tr class="live"><td><strong>Now</strong><small>live</small></td>';
    foreach ($cols as $k => $l) {
        echo '<td class="num">' . e($cell($k, $live[$k])) . '</td>';
    }
    echo '<td class="cw-muted">Updates itself</td><td class="cw-edit"></td></tr>';
    foreach (array_reverse($m['log']) as $r) {
        echo '<tr><td>' . e(cw_d($r['month'], 'M Y')) . '</td>';
        foreach ($cols as $k => $l) {
            echo '<td class="num">' . e($cell($k, $r[$k])) . '</td>';
        }
        echo '<td>' . e((string) $r['notes']) . '</td><td class="cw-edit"><form method="post" data-confirm="Remove this snapshot?">' . fabo_csrf_field()
           . '<input type="hidden" name="a" value="log_delete"><input type="hidden" name="id" value="' . (int) $r['id'] . '"><button class="btn btn-sm">Remove</button></form></td></tr>';
    }
    echo '</tbody></table></div></section>';
}

function cw_page_settings(array $m): void
{
    $c = $m['centre'];
    if (!cw_can_edit()) {
        echo '<section class="cw-card"><dl class="cw-dl"><div><dt>Desk rate</dt><dd>' . e(cw_inr($c['desk_rate'])) . ' / month</dd></div>'
           . '<div><dt>Cabin rate</dt><dd>' . e(cw_inr($c['cabin_rate'])) . ' / month</dd></div>'
           . '<div><dt>Open-space rate</dt><dd>' . e(cw_inr($c['open_rate'])) . ' per sq ft / month</dd></div><div><dt>Agreement term</dt><dd>' . (int) $c['term_months'] . ' months</dd></div></dl></section>';
        return;
    }
    echo '<div class="cw-grid2"><section class="cw-card cw-rise"><div class="cw-card-h"><h2>Centre</h2></div><form method="post" class="cw-form">' . fabo_csrf_field()
       . '<input type="hidden" name="a" value="centre_save"><div class="cw-fields">'
       . '<label class="wide">Name<input name="name" required maxlength="120" value="' . e($c['name']) . '"' . (cw_is_owner() ? '' : ' readonly') . '></label>'
       . '<label>Hub or spoke<select name="kind"' . (cw_is_owner() ? '' : ' disabled') . '><option value="hub"' . ($c['kind'] === 'hub' ? ' selected' : '') . '>Hub</option><option value="spoke"' . ($c['kind'] === 'spoke' ? ' selected' : '') . '>Spoke</option></select></label>'
       . '<label>Run by<input name="manager" maxlength="60" value="' . e($c['manager']) . '"><small>Spaces "Managed by" anyone else are a partner\'s: no rent or renewals tracked here.</small></label>'
       . '<label>Desk rate (₹ / month)<input inputmode="decimal" name="desk_rate" value="' . e((string) (float) $c['desk_rate']) . '"></label>'
       . '<label>Cabin rate (₹ / month)<input inputmode="decimal" name="cabin_rate" value="' . e((string) (float) $c['cabin_rate']) . '"></label>'
       . '<label>Open-space rate (₹ per sq ft / month)<input inputmode="decimal" name="open_rate" value="' . e((string) (float) $c['open_rate']) . '"></label>'
       . '<label>Agreement term (months)<input type="number" min="1" max="60" name="term_months" value="' . (int) $c['term_months'] . '"></label>'
       . '<label class="wide">Address<input name="address" maxlength="255" value="' . e((string) $c['address']) . '"></label></div>'
       . '<div class="cw-dlg-f"><button class="btn btn-primary">Save centre</button></div></form></section>';
    echo '<section class="cw-card cw-rise" style="--i:1" id="add"><div class="cw-card-h"><h2>Add desks &amp; cabins</h2></div>'
       . '<p>Adds a run of spaces in one go, each Vacant until someone moves in. Example: zone <code>Co-working Space 1</code>, prefix <code>Co-working Space 1 · Desk </code>, from 1, count 60.</p>'
       . '<form method="post" class="cw-form">' . fabo_csrf_field() . '<input type="hidden" name="a" value="spaces_add"><div class="cw-fields">'
       . '<label>Type<select name="type"><option>Desk</option><option>Cabin</option></select></label>'
       . '<label>Zone<input name="zone" maxlength="60" placeholder="Co-working Space 1"></label>'
       . '<label class="wide">ID prefix<input name="prefix" maxlength="60" placeholder="Co-working Space 1 · Desk "></label>'
       . '<label>Start at<input type="number" min="1" name="start" value="1"></label><label>How many<input type="number" min="1" max="200" name="count" value="10" required></label>'
       . '<label>Managed by<input name="managed_by" maxlength="60" placeholder="' . e($c['manager']) . '"></label></div>'
       . '<div class="cw-dlg-f"><button class="btn btn-primary">Add spaces</button></div></form></section></div>';
    echo '<section class="cw-card cw-rise cw-import" style="--i:2" id="import"><div class="cw-card-h"><h2>Import a workbook</h2></div>'
       . '<p>Load <strong>' . e($c['name']) . '</strong> from a workbook in the <em>Coworking Master</em> template: its Space Register, Startup Register '
       . '(with the rates and term at the top), Waiting List and Monthly Log history — or from a <em>Co-working Space Allocation</em> sheet '
       . '(one row per startup with its room and Co-working ID), which replaces only the spaces and startups. Columns are found by their headings.</p>'
       . '<form method="post" enctype="multipart/form-data" class="cw-form">' . fabo_csrf_field() . '<input type="hidden" name="a" value="import">'
       . '<label class="cw-drop"><input type="file" name="book" accept=".xlsx" required><span>Choose the .xlsx workbook</span></label>'
       . '<label class="cw-check"><input type="checkbox" name="confirm" value="replace" required> This replaces ' . e($c['name']) . '\'s spaces, startups, waiting list and monthly log.</label>'
       . '<div class="cw-dlg-f"><button class="btn btn-primary">Import into ' . e($c['name']) . '</button></div></form></section>';
    if (cw_is_owner()) {
        cw_centres_card();
    }
}

/** Every centre of the organisation: add one, remove one, change their order. */
function cw_centres_card(): void
{
    $org = (int) $GLOBALS['cw_org'];
    $list = cw_centres($org);
    $size = cw_centre_sizes($org);
    $csrf = fabo_csrf_field();
    echo '<section class="cw-card cw-rise" style="--i:3" id="centres"><div class="cw-card-h"><h2>Centres</h2><span class="hint">' . count($list)
       . ' centre' . (count($list) === 1 ? '' : 's') . ' · shown in this order everywhere</span></div>'
       . '<p>The hub and its spokes. Each keeps its own spaces, startups, waiting list and monthly log in the same template.</p>'
       . '<div class="cw-scroll"><table class="cw-table"><thead><tr><th>Centre</th><th>Hub / spoke</th><th class="num">Spaces</th><th class="num">Startups</th><th class="num">Applicants</th><th></th></tr></thead><tbody>';
    foreach ($list as $i => $c) {
        $id = (int) $c['id'];
        $s = $size[$id] ?? ['spaces' => 0, 'startups' => 0, 'waiting' => 0, 'log' => 0];
        $has = $s['spaces'] + $s['startups'] + $s['waiting'] + $s['log'] > 0;
        $move = static fn(int $dir, string $label, bool $off) => '<form method="post" class="cw-inline-f">' . $csrf
            . '<input type="hidden" name="a" value="centre_move"><input type="hidden" name="centre" value="' . $id . '"><input type="hidden" name="dir" value="' . $dir . '">'
            . '<button class="btn btn-sm cw-icon" aria-label="' . e($label . ' ' . $c['name']) . '"' . ($off ? ' disabled' : '') . '>' . ($dir < 0 ? '↑' : '↓') . '</button></form>';
        echo '<tr><td><a href="' . e(cw_url('dashboard', ['c' => $id])) . '"><strong>' . e($c['name']) . '</strong></a></td>'
           . '<td>' . cw_pill($c['kind'] === 'hub' ? 'Hub' : 'Spoke', $c['kind'] === 'hub' ? 'info' : 'mute') . '</td>'
           . '<td class="num">' . $s['spaces'] . '</td><td class="num">' . $s['startups'] . '</td><td class="num">' . $s['waiting'] . '</td>'
           . '<td class="cw-row-acts">' . $move(-1, 'Move up', $i === 0) . $move(1, 'Move down', $i === count($list) - 1);
        if (count($list) > 1) {
            echo '<form method="post" class="cw-inline-f" data-confirm="' . e('Remove ' . $c['name'] . ($has ? ' and everything it holds (' . $s['spaces'] . ' spaces, ' . $s['startups'] . ' startups)? This cannot be undone.' : '?')) . '">'
               . $csrf . '<input type="hidden" name="a" value="centre_delete"><input type="hidden" name="centre" value="' . $id . '">'
               . ($has ? '<input type="hidden" name="with_data" value="1">' : '')
               . '<button class="btn btn-sm cw-danger">Remove</button></form>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table></div>'
       . '<form method="post" class="cw-form cw-addcentre">' . $csrf . '<input type="hidden" name="a" value="centre_add"><div class="cw-fields">'
       . '<label class="wide2">New centre<input name="name" required maxlength="120" placeholder="e.g. Guntur"></label>'
       . '<label>Hub or spoke<select name="kind"><option value="spoke">Spoke</option><option value="hub">Hub</option></select></label></div>'
       . '<div class="cw-dlg-f"><button class="btn btn-primary">Add centre</button></div></form></section>';
}

/**
 * Centre teams (the owner only): who manages or reads each centre. Each
 * centre's people sign in to their own portal and find just their centres
 * under Co-working Space; what they change shows here and on All centres.
 */
function cw_page_team(array $m): void
{
    $org = (int) $GLOBALS['cw_org'];
    $centres = cw_centres($org);
    $teams = cw_teams($org);
    $people = cw_people($org);
    $mayAdd = $org > 0 && (!empty($GLOBALS['cw_viewer']['is_admin']) || fabo_viewer_may_make($GLOBALS['cw_viewer'], 'team'));
    $csrf = fabo_csrf_field();
    $roles = '<option value="edit">Manager</option><option value="view">Viewer</option>';

    echo '<section class="cw-card cw-rise cw-team-intro"><div class="cw-card-h"><h2>Give each centre to its own team</h2></div>'
       . '<p>Put the people who run a centre on its team. They sign in to their own portal and find <strong>Co-working Space</strong> with '
       . 'their centres only. Everything they record lands here at once — on All centres, in each centre\'s dashboard, the PDF report and Recent activity.</p>'
       . '<div class="cw-roles"><div><b>Manager</b><span>Spaces, startups, rent and dues, renewals, the waiting list, applicant emails, monthly log, rates and imports — for that centre.</span></div>'
       . '<div><b>Viewer</b><span>Reads that centre\'s dashboard, seat map, registers and report. Changes nothing.</span></div>'
       . '<div><b>You (owner)</b><span>Every centre, plus adding and removing centres, Centre teams and the website form.</span></div></div>'
       . (!$people && !$mayAdd ? '<p class="cw-muted">Nobody is on your team yet. Add people on <a href="' . e(fabo_root_prefix() . 'people.php?kind=team') . '">My Team</a>, then put them on a centre here.</p>' : '')
       . '</section>';

    echo '<section class="cw-teamgrid">';
    foreach ($centres as $i => $c) {
        $cid = (int) $c['id'];
        $list = $teams[$cid] ?? [];
        $onIt = array_map('intval', array_column($list, 'person_id'));
        echo '<article class="cw-card cw-rise cw-teamcard' . ($c['kind'] === 'hub' ? ' hub' : '') . '" style="--i:' . ($i + 1) . '" id="centre-' . $cid . '">'
           . '<div class="cw-card-h"><h2>' . e($c['name']) . ' <span class="cw-kind">' . ($c['kind'] === 'hub' ? 'Hub' : 'Spoke') . '</span></h2>'
           . '<a class="hint" href="' . e(cw_url('dashboard', ['c' => $cid])) . '">Open dashboard →</a></div>';
        if (!$list) {
            echo '<p class="cw-muted">No one on this centre\'s team yet — only you can see and change it.</p>';
        } else {
            echo '<ul class="cw-people">';
            foreach ($list as $r) {
                $pid = (int) $r['person_id'];
                $name = $r['name'] ?? '(removed account)';
                $keep = static fn(string $act, string $extra, string $btn, string $cls = '', string $confirm = '') => '<form method="post" class="cw-inline-f"'
                    . ($confirm !== '' ? ' data-confirm="' . e($confirm) . '"' : '') . '>' . $csrf
                    . '<input type="hidden" name="a" value="' . $act . '"><input type="hidden" name="centre" value="' . $cid . '"><input type="hidden" name="person" value="' . $pid . '">'
                    . $extra . '<button class="btn btn-sm ' . $cls . '">' . $btn . '</button></form>';
                echo '<li><span class="cw-feed-who">' . e(cw_initials((string) $name)) . '</span><div class="who"><b>' . e((string) $name) . '</b>'
                   . '<small>' . e((string) ($r['email'] ?? '')) . ($r['gone'] ? ' · cannot sign in (revoked or expired)' : '') . '</small></div>'
                   . cw_pill(cw_role_label((string) $r['role']), $r['role'] === 'edit' ? 'good' : 'mute') . '<span class="acts">'
                   . (isset($people[$pid]) ? $keep('team_put', '<input type="hidden" name="role" value="' . ($r['role'] === 'edit' ? 'view' : 'edit') . '">',
                                                   $r['role'] === 'edit' ? 'Make viewer' : 'Make manager') : '')
                   . $keep('team_drop', '', 'Remove', 'cw-danger', 'Take ' . $name . ' off ' . $c['name'] . '? Their account stays; this centre goes from their portal.')
                   . '</span></li>';
            }
            echo '</ul>';
        }
        $free = array_diff_key($people, array_flip($onIt));
        if ($free) {
            echo '<form method="post" class="cw-form cw-teamadd">' . $csrf . '<input type="hidden" name="a" value="team_put"><input type="hidden" name="centre" value="' . $cid . '">'
               . '<div class="cw-fields"><label class="wide">From your team<select name="person" required><option value="">Choose a person…</option>';
            foreach ($free as $pid => $p) {
                echo '<option value="' . $pid . '">' . e($p['name'] . ($p['email'] !== '' ? ' · ' . $p['email'] : '')) . '</option>';
            }
            echo '</select></label><label>As<select name="role">' . $roles . '</select></label></div>'
               . '<div class="cw-dlg-f"><button class="btn btn-primary">Add to ' . e($c['name']) . '</button></div></form>';
        }
        if ($mayAdd) {
            echo '<details class="cw-invite"' . (!$list && !$free ? ' open' : '') . '><summary>Someone new — create their sign-in</summary>'
               . '<form method="post" class="cw-form">' . $csrf . '<input type="hidden" name="a" value="team_invite"><input type="hidden" name="centre" value="' . $cid . '">'
               . '<div class="cw-fields"><label>Name<input name="name" required maxlength="190" placeholder="e.g. Centre manager\'s name"></label>'
               . '<label>Email<input type="email" name="email" required maxlength="190" placeholder="name@example.com"></label>'
               . '<label>As<select name="role">' . $roles . '</select></label></div>'
               . '<p class="cw-muted">They join My Team with nothing else opened, and get an email with their user ID and a password to change on first sign-in.</p>'
               . '<div class="cw-dlg-f"><button class="btn">Create and add</button></div></form></details>';
        }
        echo '</article>';
    }
    echo '</section>';
}

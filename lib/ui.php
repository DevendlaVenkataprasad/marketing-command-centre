<?php
/**
 * Shared page chrome. The same nav renders for admin and for a client; a
 * client's strip simply holds only the sheets that client was assigned.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/rules.php';
require_once __DIR__ . '/brand.php';

function e($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function fabo_money($v): string
{
    if ($v === null || $v === '') {
        return '';
    }
    // The sign belongs outside the symbol: -₹59,000, never ₹-59,000.
    $v = (float) $v;
    return ($v < 0 ? '-' : '') . '₹' . fabo_thousands(abs($v));
}

/**
 * The same amount to the paisa, the way an invoice states it: ₹ 1,77,000.00.
 * Used wherever the figure is the record itself rather than a summary.
 */
function fabo_money_exact($v): string
{
    if ($v === null || $v === '') {
        return '';
    }
    $v = (float) $v;
    return ($v < 0 ? '-' : '') . '₹ ' . fabo_thousands(abs($v), 2);
}

/**
 * Indian grouping: the last three digits, then twos. 1234567 reads 12,34,567,
 * which is how every figure in this portal is read aloud and how the
 * accounting software these numbers are reconciled against prints them.
 */
function fabo_thousands($v, int $dp = 0): string
{
    $v   = (float) $v;
    $neg = $v < 0;
    $s   = number_format(abs($v), $dp, '.', '');

    $dec = '';
    if (str_contains($s, '.')) {
        [$s, $dec] = explode('.', $s, 2);
        $dec = '.' . $dec;
    }
    if (strlen($s) > 3) {
        $last3 = substr($s, -3);
        $rest  = substr($s, 0, -3);
        // Everything above the last three digits is grouped in pairs.
        $rest  = preg_replace('/\B(?=(\d{2})+$)/', ',', $rest);
        $s     = $rest . ',' . $last3;
    }
    return ($neg ? '-' : '') . $s . $dec;
}

/**
 * A date the way a register prints it: "1 Sept", and the year as well once
 * it is not this one, because "1 Sept" two years running is a trap.
 */
function fabo_short_date($d): string
{
    if (!$d) {
        return '';
    }
    $ts = strtotime((string) $d);
    if ($ts === false) {
        return '';
    }
    return date('j M', $ts) . (date('Y', $ts) === date('Y') ? '' : date(' Y', $ts));
}

/**
 * How a due date reads on the register: "14 days ago" once it has gone by,
 * "in 6 days" before that, "today" on the day. Relative, because what the
 * eye wants from this column is how bad it is, not the calendar date.
 */
function fabo_due_phrase($d): string
{
    if (!$d) {
        return '';
    }
    $ts = strtotime((string) $d);
    if ($ts === false) {
        return '';
    }
    $days = (int) floor((strtotime(date('Y-m-d', $ts)) - strtotime(date('Y-m-d'))) / 86400);
    if ($days === 0)  { return 'today'; }
    if ($days === -1) { return 'yesterday'; }
    if ($days === 1)  { return 'tomorrow'; }
    if ($days < 0)    { return abs($days) . ' days ago'; }
    return 'in ' . $days . ' days';
}

function fabo_pct($v, int $dp = 1): string
{
    if ($v === null || $v === '') {
        return '';
    }
    return number_format((float) $v * 100, $dp, '.', ',') . '%';
}

/** Cell classes for the workbook's RAG colouring. */
function fabo_rag_class(string $rag): string
{
    switch ($rag) {
        case 'Red - Overdue':    return 'rag-red';
        case 'Amber - Due Soon': return 'rag-amber';
        case 'Green - On Track': return 'rag-green';
        case 'Done':             return 'rag-done';
        default:                 return '';
    }
}

/** Tone class for a grid cell, by field + value. '' when the field has no tone. */
function fabo_cell_tone(string $field, $value, array $row = []): string
{
    // A sheet-level computation (campaign scoring) may have decided already.
    if (!empty($row['_tone'][$field])) {
        return $row['_tone'][$field];
    }
    if ($field === 'days_to_deadline' || $field === 'days_to_event') {
        $t = fabo_days_tone($value);
        return $t ? 'tone-' . $t : '';
    }
    $fam = fabo_tone_family($field);
    if ($fam === null) {
        return '';
    }
    $t = fabo_tone($fam, $value === null ? null : (string) $value);
    return $t ? 'tone-' . $t : '';
}

/** A status pill with a dot, so meaning never rests on colour alone. */
function fabo_pill(string $family, ?string $value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    $t = fabo_tone($family, $value) ?: 'neutral';
    return '<span class="pill tone-' . e($t) . '"><i class="dot"></i>' . e($value) . '</span>';
}

/** The legend of tones, rendered once above a grid that uses them. */
function fabo_tone_key(): string
{
    $items = [
        'good'  => 'On track / done',
        'info'  => 'In progress',
        'warn'  => 'Attention / due soon',
        'risk'  => 'Overdue / at risk',
        'muted' => 'Not started / closed',
    ];
    $h = '<div class="tonekey"><span style="font-weight:600;color:var(--ink)">Colour key</span>';
    foreach ($items as $t => $lab) {
        $h .= '<span class="pill tone-' . $t . '"><i class="dot"></i>' . e($lab) . '</span>';
    }
    return $h . '</div>';
}

/**
 * A stylesheet or script URL with the file's own timestamp on it.
 *
 * The .htaccess tells browsers to keep CSS and JS for a week, which is right
 * for speed and wrong the moment either changes: an uploaded stylesheet would
 * sit unused behind a cached copy, and the page would look half-styled. The
 * timestamp changes when the file does, so a new upload is fetched at once and
 * an unchanged one is still served from cache.
 */
function fabo_asset(string $href): string
{
    // The href is relative to the page ('assets/app.css' or '../assets/app.css');
    // the file itself is always under the project root.
    $rel  = ltrim(preg_replace('~^(\.\./)+~', '', $href), '/');
    $file = dirname(__DIR__) . '/' . $rel;
    $t    = @filemtime($file);
    return $href . ($t ? '?v=' . $t : '');
}

/**
 * How far the portal root is from the page being rendered.
 *
 * The admin pages live in admin/, everything else at the root, and links
 * between them are relative. A page under admin/ linking to report.php or
 * download.php therefore asks for admin/report.php, which is not there —
 * which is exactly the 404 this exists to stop.
 *
 * Taken from the running script rather than from the viewer, because an
 * administrator reads root-level pages too and it is the page's own
 * location that decides, not who is looking at it.
 */
function fabo_root_prefix(): string
{
    // SCRIPT_NAME is a URL path, so it is forward slashes on every platform.
    return str_contains((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '/admin/') ? '../' : '';
}

/** A link to a page at the portal root, correct from wherever it is called. */
function fabo_root_link(array $viewer, string $page, array $q = []): string
{
    return fabo_root_prefix() . fabo_link($viewer, $page, $q);
}

/**
 * A standalone message page, for somewhere there is no viewer to build a
 * shell around — a share link that has expired, say. Deliberately says one
 * thing and offers no navigation: whoever is reading it has no account here.
 */
function fabo_simple_page(string $head, string $body): void
{
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: noindex, nofollow');
    }
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<meta name="robots" content="noindex,nofollow">'
       . '<title>' . e($head) . '</title><style>'
       . 'body{font:15px/1.6 system-ui,Segoe UI,sans-serif;background:#f6f9f8;color:#16201d;'
       . 'display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;padding:20px}'
       . 'div{background:#fff;border:1px solid #dde6e3;border-radius:12px;padding:30px 34px;max-width:460px}'
       . 'h1{margin:0 0 8px;font-size:19px}p{margin:0;color:#667069}'
       . '</style></head><body><div><h1>' . e($head) . '</h1><p>' . e($body) . '</p></div></body></html>';
}

/**
 * The portal's base URL, e.g. http://localhost/Fabo/ — used when sending links.
 * Links made for a client organisation with its own working address carry
 * that address instead (lib/orghost.php), wherever they are made from.
 */
function fabo_portal_url(): string
{
    $own = fabo_link_host();
    if ($own !== '') {
        return 'https://' . $own . '/';
    }
    $scheme = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/\\');
    return $scheme . '://' . $host . $dir . '/';
}

function fabo_head(array $viewer, string $title, string $current = ''): void
{
    // Start the session before a byte of output, so the CSRF cookie is issued.
    fabo_csrf_token();

    /* A committee member has their committee dashboard, the sheets they were
       given, and their notifications and profile. Any other page (meetings,
       the people desks...) sends them back to the dashboard. */
    require_once __DIR__ . '/committees.php';
    $cmOnly = fabo_cm_only($viewer);
    // The IT / Facilities helpdesk (raise and follow their own requests) is open to them too.
    $cmHelp = (bool) preg_match('/^(it|fa)-(my|myticket|queue|ticket|work)$/', $current);
    if ($cmOnly && !$cmHelp && !in_array($current, ['committee', 'notifications', 'profile'], true)
        && fabo_permission($viewer, $current) === 'none') {
        header('Location: ' . fabo_root_link($viewer, 'committee.php'));
        exit;
    }

    /* A message the mail server would not take earlier gets one quiet
       attempt here, at most one a minute. A rate limit passes on its own and
       something has to notice; a page somebody is already loading is the
       cheapest place to do it, and it means nobody has to remember to press
       Send it again. */
    if (!empty($viewer['is_admin'])) {
        require_once __DIR__ . '/outbox.php';
        fabo_outbox_tick();
    }
    /* The same courtesy for the job queue. A portal whose cron has not been
       set up still moves forward while somebody is using it — slowly, and
       one job at a time, so the page load that pays for it does not drag.
       With cron running this almost always finds nothing to do. */
    if (is_file(__DIR__ . '/jobs.php')) {
        require_once __DIR__ . '/jobs.php';
        fabo_job_nudge();
    }
    /* And a slice of the Master Activity Log, for the same reason: without
       cron the sweep never runs, and the log fills only with what somebody
       has typed since it was installed. A few rows per page load works
       through the backlog on its own. */
    if (is_file(__DIR__ . '/masterlog.php')) {
        require_once __DIR__ . '/masterlog.php';
        fabo_master_nudge();
    }
    /* And CRM bulk email: a few at a time, once a minute at most, so a send
       keeps going after the person who sent it has closed the page. */
    if (is_file(__DIR__ . '/crm_mail.php')) {
        require_once __DIR__ . '/crm_mail.php';
        fabo_crm_mail_nudge();
    }
    /* And the event-results reminders, at most every half hour, so they go
       out on a portal whose cron has not been set up. See lib/evresults.php. */
    if (is_file(__DIR__ . '/evresults.php')) {
        try {
            require_once __DIR__ . '/evresults.php';
            fabo_evres_sweep();
        } catch (Throwable $e) {
            error_log('FABO evres nudge: ' . $e->getMessage());
        }
    }
    /* And the daily committee reminders, for the same reason. A cheap
       setting check on every page but the first after 10:00 each day. */
    try {
        fabo_cm_autoremind_sweep();
    } catch (Throwable $e) {
        error_log('FABO committee remind nudge: ' . $e->getMessage());
    }
    /* And upcoming events to each organisation's automatic committee, after
       the page has gone out, once a minute at most. */
    try {
        fabo_cm_autoassign_later();
    } catch (Throwable $e) {
        error_log('FABO committee auto-assign nudge: ' . $e->getMessage());
    }
    // Dropdown options and column wording belong to an organisation, so the
    // page has to know whose before it renders any of either.
    require_once __DIR__ . '/lists.php';
    fabo_view_owner(fabo_customiser($viewer));
    $base = $viewer['is_admin'] ? 'admin/' : '';
    // A client's portal is their own: it carries their organisation, not ours.
    $brand = fabo_viewer_brand($viewer);
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($title) ?> &middot; <?= e($brand) ?></title>
<?php
  /* The mark in the tab. A client's own where they have one, so somebody with
     several portals open can tell them apart; the portal's own otherwise.
     PNG rather than the WebP it is made from, because a few browsers still
     will not take a WebP favicon. */
  $logo = fabo_logo_src($viewer, (bool) $viewer['is_admin']);
?>
<link rel="icon" href="<?= e($logo) ?>">
<link rel="apple-touch-icon" href="<?= e($logo) ?>">
<link rel="stylesheet" href="<?= e(fabo_asset($viewer['is_admin'] ? '../assets/app.css' : 'assets/app.css')) ?>">
<?php
  /* The Programme Calendar carries its own palette. Only the Events sheet
     needs it, so it is not made part of app.css for every other page. */
  if ($current === 'events') {
      $ec = $viewer['is_admin'] ? '../assets/eventcal.css' : 'assets/eventcal.css';
      echo '<link rel="stylesheet" href="' . e(fabo_asset($ec)) . '">' . "\n";
  }
  // The HR desks carry their own small sheet on top of app.css.
  if (str_starts_with($current, 'hr-')) {
      $hc = $viewer['is_admin'] ? '../assets/hr.css' : 'assets/hr.css';
      echo '<link rel="stylesheet" href="' . e(fabo_asset($hc)) . '">' . "\n";
  }
  // So do Incubation Management desks.
  if (str_starts_with($current, 'inc-') || str_starts_with($current, 'it-') || str_starts_with($current, 'fa-') || in_array($current, ['mywork', 'people-team'], true)) {   // My Work and My Team carry incubation tiles and bars
      $ic = $viewer['is_admin'] ? '../assets/inc.css' : 'assets/inc.css';
      echo '<link rel="stylesheet" href="' . e(fabo_asset($ic)) . '">' . "\n";
  }
?>
</head>
<body class="has-sidenav">
<script>
/* The sidebar remembers whether it was left open or closed, and is set
   before anything draws so the page does not jump on every load. */
(function () {
  try {
    if (localStorage.getItem('fabo.nav') === 'closed') {
      document.documentElement.classList.add('nav-closed');
    }
  } catch (e) {}
})();
</script>
<header class="apphead">
<div class="topbar">
  <button type="button" class="navtoggle" id="navtoggle" title="Show or hide the menu"
          aria-label="Show or hide the menu" aria-controls="sidenav">
    <span></span><span></span><span></span>
  </button>
  <div class="brandmark">
    <img class="brandlogo" src="<?= e($logo) ?>" alt="" width="28" height="28">
    <span class="brandname"><?= e($brand) ?>
      <span>&middot; <?= e(fabo_app_tagline()) ?></span></span>
  </div>
  <div class="spacer"></div>
<?php
    /* The top bar, the same in both portals: the desks about people and
       messages as tabs (the admin: Clients, Team Management, Meetings,
       Notifications; a portal: My Clients, My Team, Meetings, Notifications),
       then a divider and the actions (Export all sheets for the admin, Sign
       out). The sidebar keeps the work itself -- sheets and modules. */
    require_once __DIR__ . '/notify.php';
    $unread = fabo_unread_count(fabo_inbox_id($viewer));
    $tab    = static fn(string $href, string $label, bool $on, string $extra = '')
                => '<a class="btn-top' . ($on ? ' on' : '') . '" href="' . e($href) . '"' . ($on ? ' aria-current="page"' : '') . '>'
                 . e($label) . $extra . '</a>';
    $badge  = $unread ? ' <span class="badge">' . (int) $unread . '</span>' : '';
    echo '<nav class="topnav" aria-label="Main">';
    if ($viewer['is_admin']) {
        // Admin pages live in admin/; the same links work from a root page too.
        $adm = fabo_root_prefix() === '../' ? '' : 'admin/';
        echo $tab($adm . 'clients.php', 'Clients', $current === 'clients')
           . $tab($adm . 'team.php', 'Team Management', $current === 'team')
           . $tab($adm . 'meetings.php', 'Meetings', $current === 'meetings')
           . $tab($adm . 'notifications.php', 'Notifications', $current === 'notifications', $badge);
    } else {
        require_once __DIR__ . '/people.php';
        if (!$cmOnly && fabo_may_make($viewer['client'] ?? null, 'client')) {
            echo $tab(fabo_link($viewer, 'people.php'), 'My Clients', $current === 'people');
        }
        if (!$cmOnly && fabo_may_make($viewer['client'] ?? null, 'team')) {
            echo $tab(fabo_link($viewer, 'people.php', ['kind' => 'team']), 'My Team', $current === 'people-team');
        }
        if (!$cmOnly) {
            echo $tab(fabo_link($viewer, 'meetings.php'), 'Meetings', $current === 'meetings');
        }
        echo $tab(fabo_link($viewer, 'notifications.php'), 'Notifications', $current === 'notifications', $badge);
    }
    echo '</nav><span class="topsep" aria-hidden="true"></span><div class="topacts">';
    if ($viewer['is_admin']) {
        echo '<a class="btn-top" href="' . e(fabo_root_prefix() === '../' ? '../export.php?scope=all' : 'export.php?scope=all') . '">Export all sheets</a>';
        if (fabo_admin_signed_in()) {
            echo '<a class="btn-top danger" href="' . e(fabo_root_prefix() . 'logout.php') . '">Sign out</a>';
        }
    } else {
        // Always offered. Someone on their private link has no session to end, but
        // they still want to leave the portal on a shared machine — and that also
        // clears the cookie that remembers which link was last opened here.
        echo '<a class="btn-top danger" href="logout.php">Sign out</a>';
    }
    echo '</div>';
?>
</div>
<?php
    if ($cmOnly) {
        echo '<nav class="navstrip" id="sidenav">';
        ob_start();                      // decorated with icons and sections by fabo_nav_decorate()
        fabo_nav_head($viewer);
        echo '<a' . ($current === 'committee' ? ' class="on"' : '') . ' href="'
           . e(fabo_link($viewer, 'committee.php')) . '">Committee Dashboard</a>';
        foreach (fabo_visible_sheets($viewer) as $key => $perm) {
            $q = ['sheet' => $key];
            echo '<a' . ($key === $current ? ' class="on"' : '') . ' href="'
               . e(fabo_link($viewer, 'c.php', $q)) . '">' . e(fabo_sheet_label($key))
               . ($perm === 'view' && $key !== 'dashboard' ? ' <span class="ro">view</span>' : '') . '</a>';
        }
        // Raising an IT / facilities request: the helpdesk, and nothing else of those desks.
        require_once __DIR__ . '/it/core.php';
        require_once __DIR__ . '/it/nav.php';
        foreach (['it', 'fa'] as $itDesk) {
            if ($itHelp = it_helpdesk_info($viewer, $itDesk)) {
                echo it_nav_html($current, $itDesk, $itHelp);
            }
        }
        fabo_nav_tail($viewer, $current);
        echo fabo_nav_decorate((string) ob_get_clean());
        echo '</nav></header>';
        fabo_nav_script();
        return;
    }
    $sheets = fabo_visible_sheets($viewer);
    $isTeam = !$viewer['is_admin'] && (($viewer['client']['kind'] ?? 'client') === 'team');
    {
        // The strip always renders: even with no sheets yet, a person still
        // needs their notifications and their profile.
        echo '<nav class="navstrip" id="sidenav">';
        ob_start();                      // decorated with icons and sections by fabo_nav_decorate()
        fabo_nav_head($viewer);
        if ($isTeam) {
            $on = $current === 'mywork' ? ' class="on"' : '';
            echo '<a' . $on . ' href="' . e(fabo_link($viewer, 'c.php', ['sheet' => 'mywork'])) . '">My Work</a>';
        }
        // Somebody who also sits on a committee gets its desk beside their own.
        if (!$viewer['is_admin'] && fabo_cm_is_member($viewer)) {
            echo '<a' . ($current === 'committee' ? ' class="on"' : '') . ' href="'
               . e(fabo_link($viewer, 'committee.php')) . '">Committee</a>';
        }
        $sheetLink = static function (string $key, string $perm, string $cls = '') use ($viewer, $current): string {
            $page = $key === 'dashboard'
                  ? ($viewer['is_admin'] ? 'dashboard.php' : 'c.php')
                  : ($viewer['is_admin'] ? 'sheet.php'     : 'c.php');
            $q = $key === 'dashboard' ? ($viewer['is_admin'] ? [] : ['sheet' => 'dashboard'])
                                      : ['sheet' => $key];
            $cls = trim($cls . ($key === $current ? ' on' : ''));
            return '<a' . ($cls !== '' ? ' class="' . $cls . '"' : '') . ' href="' . e(fabo_link($viewer, $page, $q)) . '">'
                 . e(fabo_sheet_label($key))
                 . ($perm === 'view' && $key !== 'dashboard' ? ' <span class="ro">view</span>' : '') . '</a>';
        };
        /* Marketing Management: the marketing sheets under one heading, drawn
           where the first of them would have been. Open while one of them is
           the page; a click opens or closes it. */
        $mkKeys = fabo_marketing_sheet_keys();
        $mkHere = array_intersect_key($sheets, array_flip($mkKeys));
        $mkDone = false;
        foreach ($sheets as $key => $perm) {
            if (isset($mkHere[$key])) {
                if (!$mkDone) {
                    $mkDone = true;
                    echo fabo_nav_group('Marketing Management', 'megaphone', isset($mkHere[$current]),
                        implode('', array_map(static fn($k) => $sheetLink($k, $mkHere[$k], 'mk-sub'),
                                              array_keys($mkHere))));
                }
                continue;
            }
            echo $sheetLink($key, $perm);
        }
        /* HR Management and Incubation Management belong with the work, so they
           follow the sheets under Workspace (fabo_nav_decorate() starts Modules
           after them), each with its desks nested under it while one is open. */
        require_once __DIR__ . '/hr/nav.php';
        require_once __DIR__ . '/inc/nav.php';
        if ($viewer['is_admin']) {
            echo hr_nav_html($viewer, $current);
            echo inc_nav_html($current);
            require_once __DIR__ . '/it/nav.php';
            echo it_nav_html($current, 'it');             // IT Management
            echo it_nav_html($current, 'fa');             // Admin & Facilities
            require_once __DIR__ . '/cw/nav.php';
            echo cw_nav_html($current);                 // Co-working Space
        } elseif (fabo_signed_in()) {
            // HR appears only for someone the administrator has given an HR role.
            echo hr_nav_html($viewer, $current);
            // Incubation: ours for our staff granted it, a client's own for them and their team.
            // IT Management and Admin & Facilities: a client's own, as granted on Clients (or passed on by the head).
            require_once __DIR__ . '/it/core.php';
            require_once __DIR__ . '/it/nav.php';
            /* Everyone else in an organisation whose desk is on gets its helpdesk:
               raise and follow their own requests, and work them if on the team. */
            foreach (['it', 'fa'] as $itDesk) {
                if (it_grant($viewer, $itDesk) !== 'none') {
                    echo it_nav_html($current, $itDesk, null, true);
                } elseif ($itHelp = it_helpdesk_info($viewer, $itDesk)) {
                    echo it_nav_html($current, $itDesk, $itHelp);
                }
            }
            // Co-working Space: a client's own centres, as granted on Clients (or passed on by the head),
            // or just the centres someone was put on in Centre teams.
            require_once __DIR__ . '/cw/nav.php';
            $cwGrant = fabo_module_permission($viewer, 'coworking');
            if ($cwGrant !== 'none' || cw_assigned($viewer)) {
                echo cw_nav_html($current, $cwGrant === 'edit', $cwGrant !== 'none');
            }
            $incGrant = inc_grant($viewer);
            if ($incGrant !== 'none') {
                echo inc_nav_html($current, $incGrant === 'edit');
            } elseif (inc_desk_member($viewer)) {
                echo inc_nav_html($current, false, true);   // a department member: their desk only
            }
        }
        /* Finance sits with the work too, under Main Menu: the Finance Portal
           (lib/fin) for anyone signed in to an organisation that holds it, its
           desks shown by finance role, with the portal's own billing (Client
           billing for us, "Billed by us" for a client) inside the same group.
           Someone reading through a ?k= link has no portal: they keep the
           billing page alone. */
        $onFin = in_array($current, ['finance', 'invoices'], true) ? ' class="on"' : '';
        require_once __DIR__ . '/fin/nav.php';
        $finNav = $viewer['is_admin'] || fabo_signed_in() ? fp_nav_html($viewer, $current) : '';
        if ($finNav !== '') {
            echo $finNav;
        } elseif ($viewer['is_admin']) {
            echo '<a' . $onFin . ' href="finance.php">Finance</a>';
        } elseif (($viewer['client']['kind'] ?? 'client') !== 'team'
                  && fabo_module_permission($viewer, 'finance') !== 'none') {
            echo '<a' . $onFin . ' href="' . e(fabo_link($viewer, 'finance.php')) . '">Finance</a>';
        }
        if ($viewer['is_admin']) {
            // Team Management, Meetings and Notifications are in the top bar.
            echo '<a' . ($current === 'sheets' ? ' class="on"' : '')
               . ' href="sheets.php">Sheet Management</a>';
            echo '<a' . ($current === 'intake' ? ' class="on"' : '')
               . ' href="intake.php">Intake Forms</a>';
            // One entry: its dashboard and its list of CRMs are tabs inside it.
            echo '<a' . (in_array($current, ['crm', 'crm-dash'], true) ? ' class="on"' : '')
               . ' href="crm.php">CRM</a>';
            require_once __DIR__ . '/deletions.php';
            $waiting = fabo_delete_pending_count(0);
            echo '<a' . ($current === 'deletions' ? ' class="on"' : '') . ' href="deletions.php">'
               . 'Deletions' . ($waiting ? ' <span class="badge">' . (int) $waiting . '</span>' : '') . '</a>';
        }
        // A portal's My Clients and My Team are in the top bar (see above).
        /* The two management desks, granted one at a time like any other
           right. They are the same pages the admin uses, working at this
           organisation's scope — see lib/manage.php. Outside the elseif
           above because a client may hold these without running people. */
        if (!$viewer['is_admin']) {
            require_once __DIR__ . '/manage.php';
            if (fabo_may_manage($viewer, 'sheets')) {
                echo '<a' . ($current === 'sheets' ? ' class="on"' : '') . ' href="'
                   . e(fabo_link($viewer, 'sheets.php')) . '">Sheet Management</a>';
            }
            if (fabo_may_manage($viewer, 'intake')) {
                echo '<a' . ($current === 'intake' ? ' class="on"' : '') . ' href="'
                   . e(fabo_link($viewer, 'intake.php')) . '">Intake Forms</a>';
            }
            /* Anyone holding a CRM: their organisation's (heads, through the
               CRM module) or one shared with them on its own. */
            require_once __DIR__ . '/crm.php';
            if (fabo_crm_has_any($viewer)) {
                echo '<a' . (in_array($current, ['crm', 'crm-dash'], true) ? ' class="on"' : '') . ' href="'
                   . e(fabo_link($viewer, 'crm.php')) . '">CRM</a>';
            }
            if (!fabo_may_manage($viewer, 'sheets') && fabo_may_chart($viewer)) {
                /* Without the sheets right, the charts alone: a head of an
                   organisation still decides what their dashboards draw. */
                echo '<a' . ($current === 'charts' ? ' class="on"' : '') . ' href="'
                   . e(fabo_link($viewer, 'charts.php')) . '">Dashboard Charts</a>';
            }
        }
        // Meetings is a default desk for everyone; in a portal it sits in the top bar.
        // HR and Incubation are drawn with the work, under Workspace (above).
        // A head answers their own organisation's deletion requests, so the
        // desk appears for them exactly as it does for the administrator.
        if (!$viewer['is_admin']) {
            require_once __DIR__ . '/deletions.php';
            if (fabo_may_delete($viewer)) {
                $waiting = fabo_delete_pending_count((int) $viewer['client']['id']);
                echo '<a' . ($current === 'deletions' ? ' class="on"' : '') . ' href="'
                   . e(fabo_link($viewer, 'deletions.php')) . '">Deletions'
                   . ($waiting ? ' <span class="badge">' . (int) $waiting . '</span>' : '')
                   . '</a>';
            }
        }
        // Finance is under Main Menu (above).
        fabo_nav_tail($viewer, $current);
        echo fabo_nav_decorate((string) ob_get_clean());
        echo '</nav>';
    }
    echo '</header>';
    fabo_nav_script();
}

/** The sheets the sidebar gathers under "Marketing Management", in this order. */
function fabo_marketing_sheet_keys(): array
{
    return ['calendar', 'website', 'campaigns', 'influencers', 'btl', 'events', 'requisitions', 'webcontent'];
}

/**
 * A collapsible sidebar group: a heading with an icon and a chevron, and its
 * entries indented under it. A <details> element, so it opens and closes
 * without script; open from the start when $open (the page is one of them).
 */
function fabo_nav_group(string $label, string $icon, bool $open, string $body): string
{
    $icons = fabo_nav_icons();
    return '<details class="navgrp"' . ($open ? ' open' : '') . '><summary class="navgrp-h' . ($open ? ' has-on' : '') . '">'
         . '<svg class="ni" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . ($icons[$icon] ?? $icons['doc']) . '</svg>'
         . '<span class="nl">' . e($label) . '</span>'
         . '<svg class="chev" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 6l6 6-6 6"/></svg>'
         . '</summary><div class="navgrp-b">' . $body . '</div></details>';
}

/** Line icons for the sidebar (24×24, drawn with the text colour). key => SVG body. */
function fabo_nav_icons(): array
{
    return [
        'grid'     => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
        'list'     => '<path d="M9 6h12M9 12h12M9 18h12M4 6h.01M4 12h.01M4 18h.01"/>',
        'store'    => '<path d="M3 9l1.6-5h14.8L21 9"/><path d="M4 9v11h16V9"/><path d="M3 9a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0"/><path d="M10 20v-6h4v6"/>',
        'megaphone'=> '<path d="M3 11v2a1 1 0 0 0 1 1h3l7 4V6L7 10H4a1 1 0 0 0-1 1z"/><path d="M18 8.5a5 5 0 0 1 0 7"/>',
        'file'     => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/>',
        'globe'    => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
        'pen'      => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
        'target'   => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/>',
        'star'     => '<path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1-4.4-4.3 6.1-.9z"/>',
        'flag'     => '<path d="M5 21V4M5 4h12l-2 4 2 4H5"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/>',
        'clipboard'=> '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4h6v3H9zM9 12h6M9 16h4"/>',
        'briefcase'=> '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2M3 13h18"/>',
        'sliders'  => '<path d="M4 6h9M17 6h3M4 12h3M11 12h9M4 18h11M19 18h1"/><circle cx="15" cy="6" r="2"/><circle cx="9" cy="12" r="2"/><circle cx="17" cy="18" r="2"/>',
        'inbox'    => '<path d="M3 13l2.5-8h13L21 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M3 13h5l1 3h6l1-3h5"/>',
        'contacts' => '<circle cx="9" cy="8" r="3.5"/><path d="M3 20a6 6 0 0 1 12 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14.5a6 6 0 0 1 3 5.5"/>',
        'rocket'   => '<path d="M5 15c-1.5 1.5-2 5-2 5s3.5-.5 5-2"/><path d="M9 15l-3-3a12 12 0 0 1 9-9c2 0 4 0 6 1 1 2 1 4 1 6a12 12 0 0 1-9 9z"/><circle cx="15" cy="9" r="1.6"/>',
        'wallet'   => '<path d="M3 7a2 2 0 0 1 2-2h12v4"/><rect x="3" y="7" width="18" height="13" rx="2"/><path d="M16.5 13.5h.01"/>',
        'trash'    => '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
        'user'     => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'check'    => '<rect x="3" y="3" width="18" height="18" rx="3"/><path d="M8 12l3 3 5-6"/>',
        'shield'   => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
        'chart'    => '<path d="M4 20V11M10 20V5M16 20v-7M2 20h20"/>',
        'monitor'  => '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>',
        'building' => '<rect x="4" y="3" width="16" height="18" rx="1.5"/><path d="M9 7h2M13 7h2M9 11h2M13 11h2M9 15h2M13 15h2M10 21v-3h4v3"/>',
        'doc'      => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/>',
        'cowork'   => '<circle cx="8" cy="6" r="2.2"/><circle cx="16" cy="6" r="2.2"/><path d="M4.5 13a3.5 3.5 0 0 1 7 0M12.5 13a3.5 3.5 0 0 1 7 0"/><rect x="2.5" y="13" width="19" height="2.6" rx="1"/><path d="M5 15.6V21M19 15.6V21"/>',
    ];
}

/** Which icon a sidebar entry gets, from its label. First match wins. */
function fabo_nav_icon_for(string $label): string
{
    $l = mb_strtolower($label);
    foreach ([
        'dashboard chart' => 'chart', 'dashboard' => 'grid', 'master activity' => 'list', 'store' => 'store',
        'social' => 'megaphone', 'website' => 'globe', 'requisition' => 'clipboard', 'content' => 'file',
        'design' => 'pen', 'campaign' => 'target', 'influencer' => 'star', 'btl' => 'flag', 'event' => 'calendar',
        'hr ' => 'briefcase', 'hr' => 'briefcase', 'sheet management' => 'sliders', 'intake' => 'inbox',
        'incubation' => 'rocket', 'crm' => 'contacts', 'finance' => 'wallet', 'deletion' => 'trash',
        'profile' => 'user', 'my work' => 'check', 'committee' => 'shield',
    ] as $needle => $icon) {
        if ($needle === 'hr' ? ($l === 'hr' || str_starts_with($l, 'hr ')) : str_contains($l, $needle)) {
            return $icon;
        }
    }
    return 'doc';
}

/**
 * The sidebar as drawn, given a modern finish: an icon on every main entry,
 * the label and its badge laid out apart, and two section headings --
 * Modules before the first module, Account before Profile. Sub-entries (the
 * HR and Incubation desks) are left as they are; their own styles indent them.
 * Done here, once, so none of the many places that draw entries change.
 */
function fabo_nav_decorate(string $html): string
{
    $icons = fabo_nav_icons();
    $modules = ['sliders', 'inbox', 'contacts', 'trash', 'chart', 'shield'];   // HR, Incubation and Finance stay under Main Menu
    $seenModules = false;
    $seenAccount = false;
    return preg_replace_callback('~<a\b([^>]*)>(.*?)</a>~s', static function ($m) use ($icons, $modules, &$seenModules, &$seenAccount) {
        $attrs = $m[1];
        if (preg_match('~class="[^"]*\b(inc-sub|hr-sub|mk-sub|it-sub)\b~', $attrs)) {
            return $m[0];                                    // an entry nested under its module or group
        }
        $extras = '';
        $label = preg_replace_callback('~\s*<span class="(?:badge|ro)">.*?</span>~s', static function ($s) use (&$extras) {
            $extras .= $s[0];
            return '';
        }, $m[2]);
        $icon = fabo_nav_icon_for(trim(html_entity_decode(strip_tags($label))));
        $head = '';
        if ($icon === 'user' && !$seenAccount) {
            $seenAccount = true;
            $head = '<div class="sidelabel">Account</div>';
        } elseif (in_array($icon, $modules, true) && !$seenModules) {
            $seenModules = true;
            $head = '<div class="sidelabel">Tools &amp; Settings</div>';
        }
        return $head . '<a' . $attrs . '><svg class="ni" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $icons[$icon] . '</svg>'
             . '<span class="nl">' . trim($label) . '</span>' . $extras . '</a>';
    }, $html) ?? $html;
}

/**
 * The top of the sidebar: who is signed in, and the organisation they are
 * working for, under a round badge with their initial.
 */
function fabo_nav_head(array $viewer): void
{
    if (!empty($viewer['is_admin'])) {
        $name = 'Administrator';
        $org  = 'Admin portal';
    } else {
        $c    = $viewer['client'] ?? [];
        $name = (string) ($c['name'] ?? '');
        $org  = (string) ($c['organisation'] ?? '');
        if ($org === '') { $org = fabo_viewer_brand($viewer); }
        if (($c['kind'] ?? 'client') === 'team') { $org = 'Team · ' . $org; }
    }
    $initial = mb_strtoupper(mb_substr(trim($name) !== '' ? trim($name) : $org, 0, 1));
    // Their own photograph where they have one (image.php), the initial otherwise.
    $photo = '';
    if (empty($viewer['is_admin']) && !empty($viewer['client']['image_file'])) {
        $src   = fabo_link($viewer, 'image.php',
                           ['v' => substr(md5((string) $viewer['client']['image_file']), 0, 8)]);
        $photo = '<img src="' . e($src) . '" alt="" onerror="this.remove()">';
    }
    echo '<div class="sidehead" title="' . e($name) . '">'
       . '<span class="sideavatar" aria-hidden="true">' . e($initial) . $photo . '</span>'
       . '<span class="sidewho"><strong>' . e($name) . '</strong>'
       . '<small title="' . e($org) . '">' . e($org) . '</small></span>'
       . '</div>'
       . '<div class="sidelabel">Main Menu</div>';
}

/**
 * Opens and closes the sidebar. On a wide screen it pushes the page over and
 * the choice is remembered; on a narrow one it slides over the page and a tap
 * outside it, or on a link, puts it away again.
 */
function fabo_nav_script(): void
{
    ?>
<div class="navshade" id="navshade"></div>
<script>
(function () {
  var root = document.documentElement;
  var btn = document.getElementById('navtoggle');
  var shade = document.getElementById('navshade');
  var narrow = window.matchMedia('(max-width: 900px)');
  function fit() {
    var top = document.querySelector('.topbar');
    root.style.setProperty('--topbar-h', (top ? top.offsetHeight : 52) + 'px');
  }
  function toggle() {
    if (narrow.matches) {
      root.classList.toggle('nav-open');
      return;
    }
    root.classList.toggle('nav-closed');
    try {
      localStorage.setItem('fabo.nav', root.classList.contains('nav-closed') ? 'closed' : 'open');
    } catch (e) {}
    window.dispatchEvent(new Event('resize'));
  }
  if (btn) { btn.addEventListener('click', toggle); }
  if (shade) { shade.addEventListener('click', function () { root.classList.remove('nav-open'); }); }
  fit();
  window.addEventListener('resize', fit);
})();
</script>
<?php
}

/**
 * The two entries every portal ends with: the notification centre, carrying an
 * unread count, and the viewer's own profile.
 */
function fabo_nav_tail(array $viewer, string $current): void
{
    require_once __DIR__ . '/notify.php';
    $admin  = !empty($viewer['is_admin']);
    $unread = fabo_unread_count(fabo_inbox_id($viewer));

    $notices = $admin ? 'notifications.php' : fabo_link($viewer, 'notifications.php');
    $profile = $admin ? 'profile.php'       : fabo_link($viewer, 'profile.php');

    // Notifications is in the top bar, for the administrator and every portal alike.
    unset($notices, $unread);
    echo '<a' . ($current === 'profile' ? ' class="on"' : '') . ' href="' . e($profile) . '">Profile</a>';
}

function fabo_foot(): void
{
    echo "\n</body>\n</html>\n";
}

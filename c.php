<?php
/**
 * The client portal. One unguessable link per client, showing only that
 * client's assigned sheets and nothing else.
 */

require_once __DIR__ . '/lib/sheetpage.php';
require_once __DIR__ . '/lib/notify.php';
require_once __DIR__ . '/lib/brand.php';
fabo_boot();

header('X-Robots-Tag: noindex, nofollow');

$token  = (string) ($_GET['k'] ?? '');

// Either a signed-in session or the private link. No link and not signed in
// means the sign-in page.
$viewer = fabo_portal_viewer($token, true);
if (!$viewer) {
    header('Location: login.php');
    exit;
}
$client = $viewer['client'];
$state  = $viewer['state'];

/* ------------------------------------------------- expired or revoked */

if ($state !== 'ok') {
    $sent = false;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        fabo_csrf_check();
        $note = trim((string) ($_POST['reason'] ?? ''));
        fabo_exec('UPDATE clients SET access_requested_at = NOW(), access_request_note = ?
                   WHERE id = ?', 'si', [$note, (int) $client['id']]);
        $sent = true;
    } elseif (!empty($client['access_requested_at'])) {
        $sent = true;
    }
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Request access</title>
<link rel="stylesheet" href="<?= e(fabo_asset('assets/app.css')) ?>">
</head>
<body>
<div class="topbar">
  <div class="brandmark"><?= e(fabo_person_brand($client)) ?> <span>&middot; <?= e(fabo_app_tagline()) ?></span></div>
</div>
<div class="wrap-narrow" style="max-width:560px">
  <div class="card">
    <h2><?= $state === 'revoked' ? 'This link has been withdrawn' : 'This link has expired' ?></h2>
    <?php if ($sent): ?>
      <div class="flash ok">
        Your request has been sent. Once it is approved this same link will work again —
        keep the bookmark and reload this page.
      </div>
    <?php else: ?>
      <p style="color:var(--ink-soft);font-size:13.5px;margin-top:0">
        Hello <?= e($client['name']) ?> — your access to these sheets is no longer active.
        Tell us why you need it back and we will review it.
      </p>
      <form method="post">
        <?= fabo_csrf_field() ?>
        <div class="field">
          <label>Reason for the request</label>
          <textarea name="reason" required placeholder="e.g. Still reporting on the Whitefield launch through October."></textarea>
        </div>
        <button class="btn btn-primary">Request access</button>
      </form>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
<?php
    exit;
}

/* ------------------------------------------------------------- active */

fabo_exec('UPDATE clients SET open_count = open_count + 1, last_opened_at = NOW()
           WHERE id = ?', 'i', [(int) $client['id']]);

/* The private link used to be copied into a 30-day cookie that nothing ever
   read -- a second, long-lived copy of a credential for no benefit. It is no
   longer set; logout.php still clears any old one. */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['mark_read'])) {
    fabo_csrf_check();
    fabo_mark_read((int) $client['id']);
    // Back to this page, rebuilt from what is known rather than echoed back.
    header('Location: ' . fabo_link($viewer, 'c.php', array_diff_key($_GET, ['k' => 1])));
    exit;
}

/* A committee member's portal opens on their committee dashboard; the
   sheets they were given open from the menu as usual. A notice pointing at
   an event sent to their committee opens that event's details there. */
require_once __DIR__ . '/lib/committees.php';
if (fabo_cm_only($viewer)) {
    $want = (string) ($_GET['sheet'] ?? '');
    $row  = (int) ($_GET['row'] ?? 0);
    $cmS  = $want === 'events' && $row > 0 ? fabo_cm_status($row) : null;
    $mine = $cmS && in_array(fabo_cm_person($viewer), array_column($cmS['members'], 'id'), true);
    if ($want === '' || $want === 'mywork' || $mine || fabo_permission($viewer, $want) === 'none') {
        header('Location: ' . fabo_link($viewer, 'committee.php', $mine ? ['open' => $row] : []));
        exit;
    }
}

/* Someone whose portal is only HR — an employee given their own HR
   dashboard — opens straight on it, not on an empty My Work page. */
if ($token === '' && !isset($_GET['sheet']) && !fabo_visible_sheets($viewer)) {
    require_once __DIR__ . '/lib/hr/nav.php';
    if (hr_nav_person_has_access($viewer)) {
        header('Location: hr.php');
        exit;
    }
    /* So does someone who only organizes events. Holding Incubation too, they keep
       My Work, which carries its figures. */
    require_once __DIR__ . '/lib/eo/nav.php';
    if (inc_grant($viewer) === 'none' && !inc_desk_member($viewer) && (eo_grant($viewer) !== 'none' || eo_desk_member($viewer))) {
        header('Location: organizers.php');
        exit;
    }
}

$sheets = fabo_visible_sheets($viewer);
$isTeam = ($client['kind'] ?? 'client') === 'team';

if ($isTeam && (($_GET['sheet'] ?? 'mywork') === 'mywork')) {
    require_once __DIR__ . '/lib/work.php';
    fabo_mywork_page($viewer);
    exit;
}

if (!$sheets) {
    /* A client given Incubation Management and no sheets has a portal all
       the same: My Work carries their incubation figures. */
    require_once __DIR__ . '/lib/inc/nav.php';
    if (inc_grant($viewer) !== 'none' || inc_desk_member($viewer)) {
        require_once __DIR__ . '/lib/work.php';
        fabo_mywork_page($viewer);
        exit;
    }
    // Nor one given only IT Management or Admin & Facilities: straight to that desk.
    require_once __DIR__ . '/lib/it/core.php';
    foreach (['it' => 'it.php', 'fa' => 'facilities.php'] as $itDesk => $itPage) {
        if (it_grant($viewer, $itDesk) !== 'none') {
            header('Location: ' . $itPage);
            exit;
        }
    }
    fabo_deny('No sheets have been shared with you yet.', 403);
}

$key = (string) ($_GET['sheet'] ?? array_key_first($sheets));
if (!isset($sheets[$key])) {
    fabo_deny('You do not have access to this sheet.', 403);
}

if ($key === 'dashboard') {
    require_once __DIR__ . '/lib/dashboard.php';
    fabo_dashboard_page($viewer);
    exit;
}

fabo_sheet_page($viewer, $key);

/**
 * A team member's own list: what was assigned to them, and what they entered.
 *
 * Owner alone was too narrow, and it has to agree with what the team desk
 * says about them — a person reading "nothing to do" while their manager
 * reads "eleven open" is worse than either number being wrong.
 */
function fabo_mywork_page(array $viewer): void
{
    $items = fabo_work_for_member(fabo_work_items($viewer),
                                  (array) ($viewer['client'] ?? []));
    $sm    = fabo_work_summary($items);

    /* Worst first: overdue, then due this week, then the rest still open,
       with anything finished at the bottom. The list came out in whatever
       order the sheets were read, which makes a long one useless — the
       person has to search it instead of working down it. */
    usort($items, static function ($a, $b) {
        $rank = static fn($i) => !empty($i['closed']) ? 3
                               : (!empty($i['overdue']) ? 0
                               : (!empty($i['due_soon']) ? 1 : 2));
        $ra = $rank($a);
        $rb = $rank($b);
        if ($ra !== $rb) {
            return $ra <=> $rb;
        }
        /* Overdue reads most-recent-first — a date long gone cannot be
           chased. Everything else reads soonest-first. */
        $da = (string) ($a['due'] ?? '');
        $db = (string) ($b['due'] ?? '');
        if ($da === '' || $db === '') {
            return $da === $db ? strcmp((string) $a['sheet'], (string) $b['sheet'])
                               : ($da === '' ? 1 : -1);
        }
        return $ra === 0 ? strcmp($db, $da) : strcmp($da, $db);
    });

    /* Incubation Management, for whoever holds it: their own startups, tasks
       and evaluations, and their organisation's pipeline. Someone who works
       only in incubation has no marketing rows, so the marketing tiles —
       six zeros — give way to it rather than sit above it. */
    require_once __DIR__ . '/lib/inc/nav.php';
    $incGrant  = inc_grant($viewer);
    $marketing = $items || array_diff(array_keys(fabo_visible_sheets($viewer)), ['dashboard']);
    $incOnly   = !$marketing && ($incGrant !== 'none' || inc_desk_member($viewer));

    fabo_head($viewer, 'My Work', 'mywork');
    ?>
<div class="wrap">
  <div class="pagehead">
    <h1>MY WORK</h1>
    <div class="sub"><?php
      $me = (string) ($viewer['client']['owner_name'] ?? '');
      echo $incOnly
          ? 'Your incubation work and your organisation\'s pipeline'
          : ($me !== ''
          ? 'Rows you entered, and rows whose Owner is <strong>' . e($me) . '</strong>'
          : 'Rows you entered. Your account is not linked to an Owner / Team value, '
          . 'so nothing can be assigned to you &mdash; ask the admin.');
    ?> &middot; as of <?= e(date('d-M-Y')) ?></div>
  </div>
  <?php /* Incubation applications handed to a department this person sits on
           (lib/inc/depts.php): one line each, straight to the desk. */
        require_once __DIR__ . '/lib/inc/nav.php';
        $incDesks = fabo_signed_in() ? inc_desk_open_counts($viewer) : [];
        if ($incDesks): ?>
  <div class="block cmw">
    <div class="cmw-head"><div><h2>Incubation applications for your department, mentoring or jury</h2>
      <div class="hint">Applications the incubation team handed to your department, or assigned you as a mentor or juror. Work them in your stages and return them when done.</div></div></div>
    <ul class="cmw-list">
      <?php foreach ($incDesks as $tid => $d): ?>
        <li class="cmw-item"><div class="cmw-main"><strong><?= e($d['name']) ?></strong><?php if (($d['kind'] ?? 'department') !== 'department'): ?> <span class="cmw-state"><?= $d['kind'] === 'jury' ? 'Jury' : 'Mentor' ?></span><?php endif; ?>
          <span class="cmw-state"><?= $d['open'] ? (int) $d['open'] . ' open application' . ($d['open'] === 1 ? '' : 's') : 'Nothing open right now' ?></span></div>
          <div class="cmw-act"><a class="btn btn-sm<?= $d['open'] ? ' btn-primary' : '' ?>" href="incubation.php?<?= e(http_build_query(['p' => 'desk', 'dept' => $tid])) ?>">Open the desk</a></div></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>
  <?php if ($incGrant !== 'none') { fabo_mywork_inc_block($viewer); } ?>
  <?php if ($incOnly) { echo '</div>'; fabo_foot(); return; } ?>
  <?php if ($incGrant !== 'none'): ?><div class="block"><h2>Marketing work</h2></div><?php endif; ?>
  <div class="tiles">
    <div class="tile"><div class="k">Assigned</div><div class="v"><?= $sm['assigned'] ?></div></div>
    <div class="tile green"><div class="k">Completed</div><div class="v"><?= $sm['completed'] ?></div></div>
    <div class="tile"><div class="k">Open</div><div class="v"><?= $sm['open'] ?></div></div>
    <div class="tile red"><div class="k">Overdue</div><div class="v"><?= $sm['overdue'] ?></div></div>
    <div class="tile amber"><div class="k">Due in 7 days</div><div class="v"><?= $sm['due_soon'] ?></div></div>
    <div class="tile"><div class="k">Done</div><div class="v"><?= $sm['pct_done'] ?>%</div></div>
  </div>
  <?php if (!$items): ?>
    <div class="card"><div class="empty">Nothing assigned to you right now.</div></div>
  <?php else: ?>
  <div class="scrollx"><table class="data">
    <thead><tr><th>Sheet</th><th>ID</th><th>Item</th><th>Brand</th><th>Status</th><th>Due</th><th class="num">Days</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($items as $it):
        $days = $it['due'] ? (int) round((strtotime($it['due']) - strtotime(fabo_today())) / 86400) : null;
        $dt   = $it['closed'] ? '' : ($it['overdue'] ? 'tone-risk' : ($it['due_soon'] ? 'tone-warn' : ''));
    ?>
      <tr>
        <td style="color:var(--ink-soft)"><?= e($it['sheet']) ?></td>
        <td><?= e($it['biz_id']) ?></td>
        <td><strong><?= e($it['title']) ?></strong></td>
        <td><?= e($it['brand']) ?></td>
        <td><?= fabo_pill('status', $it['status']) ?></td>
        <td><?= $it['due'] ? e(date('d M Y', strtotime($it['due']))) : '—' ?></td>
        <td class="num <?= $dt ?>"><?= $it['closed'] ? '-' : ($days === null ? '' : $days) ?></td>
        <td><a class="btn btn-sm" href="<?= e(fabo_link($viewer, 'c.php', ['sheet' => $it['key']])) ?>">Open sheet</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php
    require_once __DIR__ . '/lib/dashsheets.php';
    require_once __DIR__ . '/lib/dashgraphs.php';
    fabo_dash_my_block($viewer, $items);
    fabo_graph_my_block($viewer, $items);
  ?>
  <?php endif; ?>
</div>
<?php
    fabo_foot();
}

/**
 * The Incubation Management part of My Work. Applications carry applicants'
 * personal details, so — as in incubation.php — nothing is read for a ?k=
 * link holder; they are asked to sign in instead.
 */
function fabo_mywork_inc_block(array $viewer): void
{
    if (!fabo_signed_in()) {
        $next = 'c.php?sheet=mywork';
        echo '<div class="block cmw"><div class="cmw-head"><div><h2>Incubation Management</h2>'
           . '<div class="hint">Your incubation figures — startups, tasks, evaluations and the application pipeline — '
           . 'are shown only when you are signed in, because applications carry applicants\' personal details.</div></div>'
           . '<div class="cmw-act"><a class="btn btn-primary" href="' . e('login.php?next=' . rawurlencode($next)) . '">Sign in to see them</a></div></div></div>';
        return;
    }
    require_once __DIR__ . '/lib/inc/app.php';
    try {
        inc_start($viewer);
        $d   = inc_dashboard_figures();
        $pid = (int) $viewer['client']['id'];
        $me  = inc_person_dashboard($pid);
    } catch (Throwable $e) {
        error_log('FABO my work incubation: ' . get_class($e) . ': ' . $e->getMessage());
        echo '<div class="card"><div class="empty">Incubation figures could not be loaded just now. '
           . '<a href="' . e(inc_url('dashboard')) . '">Open Incubation Management</a></div></div>';
        return;
    }
    $today   = fabo_today();
    $open    = array_filter($me['workspaces'], static fn($w) => $w['closed_at'] === null);
    $owned   = array_filter($open, static fn($w) => (int) $w['owner_id'] === $pid);
    $overdue = array_filter($me['tasks'], static fn($t) => $t['due_date'] && $t['due_date'] < $today);
    $n = static fn($v) => number_format(is_array($v) ? count($v) : (int) $v);
    $stageId = static fn(string $pipe, string $code) => (int) (inc_stage_by_code($pipe, $code, false)['id'] ?? 0);

    echo '<div class="block"><h2>Incubation — your work</h2><div class="tiles inc-tiles">'
       . inc_tile('Startups you own', $n($owned), inc_url('workspaces', ['open' => 'open']))
       . inc_tile('Team startups (open)', $n($open), inc_url('workspaces', ['open' => 'open']))
       . inc_tile('New to you (14 days)', $n($me['new']), '', $me['new'] ? 'green' : '')
       . inc_tile('Open tasks', $n($me['tasks']), '', $me['tasks'] ? 'amber' : '')
       . inc_tile('Overdue tasks', $n($overdue), '', $overdue ? 'red' : '')
       . inc_tile('Follow-ups in 14 days', $n($me['followups']))
       . inc_tile('Your evaluations open', $n($me['evals']), inc_url('applications'))
       . '</div></div>';

    echo '<div class="block"><h2>Incubation — pipeline</h2><div class="tiles inc-tiles">'
       . inc_tile('Total applications', $n($d['total']), inc_url('applications'))
       . inc_tile('Awaiting screening', $n($d['awaiting_screen']), inc_url('applications', ['stage_id' => $stageId('crm', 'new')]), $d['awaiting_screen'] ? 'amber' : '')
       . inc_tile('Evaluation incomplete', $n($d['incomplete']), inc_url('applications', ['eval_status' => 'incomplete']), $d['incomplete'] ? 'amber' : '')
       . inc_tile('Awaiting review', $n($d['in_review']), inc_url('applications', ['eval_status' => 'submitted']))
       . inc_tile('Approved evaluations', $n($d['approved']), inc_url('applications', ['eval_status' => 'approved']), 'green',
                  $d['avg_score'] !== null ? 'Average score ' . number_format((float) $d['avg_score'], 1) : '')
       . inc_tile('Awaiting assignment', $n($d['awaiting_assignment']), inc_url('applications', ['ws' => 'no', 'eval_status' => 'approved']), $d['awaiting_assignment'] ? 'amber' : '')
       . inc_tile('Active incubations', $n($d['active_inc']), inc_url('workspaces', ['open' => 'open']), 'green')
       . inc_tile('Completed', $n($d['completed']), inc_url('workspaces', ['open' => 'closed']))
       . '</div></div>';

    $rows = static function (array $list): array {
        $out = [];
        foreach ($list as $s) {
            $out[$s['name']] = (int) $s['n'];
        }
        return $out;
    };
    $sids = array_column($d['by_stage'], 'id', 'name');
    $lids = array_column($d['by_lifecycle'], 'id', 'name');
    $mine = [];
    foreach ($open as $w) {
        $mine[$w['stage_name']] = ($mine[$w['stage_name']] ?? 0) + 1;
    }
    echo '<div class="inc-grid2" style="margin-bottom:16px">'
       . '<section class="card"><h2>Applications by pipeline stage</h2>'
       . inc_bars($rows($d['by_stage']), 'No applications yet.', static fn($l) => inc_url('applications', ['stage_id' => (int) $sids[$l]])) . '</section>'
       . '<section class="card"><h2>Incubation lifecycle</h2>'
       . inc_bars($rows($d['by_lifecycle']), 'No incubation workspaces yet.', static fn($l) => inc_url('workspaces', ['stage_id' => (int) $lids[$l]])) . '</section>'
       . '<section class="card"><h2>Your startups by stage</h2>' . inc_bars($mine, 'No open startups assigned to you or your teams.') . '</section>'
       . '<section class="card"><h2>Applications by source</h2>' . inc_bars($d['by_source'], 'No applications yet.') . '</section>'
       . '</div>';

    if ($me['tasks']) {
        echo '<section class="card"><h2>Your open incubation tasks</h2><div class="scrollx"><table class="data stack"><thead><tr><th>Task</th><th>Startup</th><th>Due</th></tr></thead><tbody>';
        foreach (array_slice($me['tasks'], 0, 10) as $t) {
            $late = $t['due_date'] && $t['due_date'] < $today;
            echo '<tr><td data-l="Task"><strong>' . e($t['title']) . '</strong></td><td data-l="Startup">' . e($t['startup_name'] ?: (string) $t['app_ref']) . '</td>'
               . '<td data-l="Due" class="' . ($late ? 'tone-risk' : '') . '">' . ($t['due_date'] ? e(inc_fmt_date($t['due_date'])) : '—') . ($late ? ' ' . inc_badge('Overdue', 'red') : '') . '</td></tr>';
        }
        echo '</tbody></table></div></section>';
    }
    echo '<p style="margin:4px 0 22px"><a class="btn btn-primary" href="' . e(inc_url('dashboard')) . '">Open Incubation Management</a></p>';
}

/** Unread notices for whoever is viewing, shown at the top of their portal. */
function fabo_notice_panel(array $viewer): string
{
    if ($viewer['is_admin']) {
        return '';
    }
    $id    = (int) $viewer['client']['id'];
    $items = fabo_notifications($id, true);
    if (!$items) {
        return '';
    }
    $h = '<div class="notifybar"><h3>New for you</h3>';
    foreach ($items as $n) {
        // A notice about one row carries its link; make it the way in.
        $link = trim((string) ($n['link'] ?? ''));
        $href = '';
        if ($link !== '' && ($safe = fabo_safe_next($link)) !== '') {
            $q    = [];
            parse_str((string) parse_url($safe, PHP_URL_QUERY), $q);
            $href = fabo_link($viewer, explode('?', $safe, 2)[0], $q);
        }
        $h .= '<div class="item"><strong>' . e($n['title']) . '</strong>'
            . ($n['body'] ? '<br>' . e($n['body']) : '')
            . ($href !== '' ? '<br><a class="btn btn-sm" style="margin-top:6px" href="' . e($href) . '">Open it</a>' : '')
            . '<div class="when">' . e(date('d M Y, H:i', strtotime($n['created_at']))) . '</div></div>';
    }
    $h .= '<form method="post" style="margin-top:10px">' . fabo_csrf_field()
        . '<input type="hidden" name="mark_read" value="1">'
        . '<button class="btn btn-sm">Got it</button></form></div>';
    return $h;
}

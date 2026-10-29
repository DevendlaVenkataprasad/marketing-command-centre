<?php
/**
 * A shared live sheet -- the page a share link opens (s.php?k=...).
 *
 * Any sheet of the portal, shared from the Intake Forms desk with one
 * department and the person filling it in. Every column of the sheet is on
 * it, read from the sheet as it stands, and the rows on it are real rows on
 * that sheet from the moment they are added -- the team sees them as they
 * are typed, and what the team changes shows up here within seconds.
 *
 * No sign-in: the link is the credential. Everything is bound to the one
 * share the token names (lib/shares.php), and api/sharesheet.php decides
 * what may be written, not this page.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/shares.php';
require_once __DIR__ . '/lib/grid.php';
fabo_boot();

/* Never stored by any cache: the page carries its own link inside it. */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
header('Pragma: no-cache');
header('Expires: 0');
header('X-LiteSpeed-Cache-Control: no-cache');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');

$token = (string) ($_GET['k'] ?? '');
$share = $token !== '' ? fabo_share_open($token) : null;

if (!$share) {
    http_response_code(404);
    fabo_simple_page('This link is not available',
        'It may have been closed, or it may have expired. Ask whoever sent it to you for a new one.');
    exit;
}

$sheet = fabo_share_sheet($share);
fabo_share_touch((int) $share['id']);

$cols  = fabo_share_columns($sheet, $share);
$rows  = [];
foreach (fabo_share_rows($share, $sheet) as $r) {
    $rows[] = fabo_share_payload($share, $sheet, $r);
}
$cmeta = [];
foreach ($cols as $f => $m) {
    $cmeta[] = ['field' => $f, 'label' => $m['label'], 'type' => $m['type'],
                'list' => $m['list'], 'mode' => $m['mode'], 'note' => $m['note']];
}
$counts = ['edit' => 0, 'read' => 0];
foreach ($cols as $m) {
    $counts[$m['mode'] === 'read' ? 'read' : 'edit']++;
}

$org = (int) $share['filed_under'] > 0
    ? fabo_one('SELECT name, organisation FROM clients WHERE id = ?', 'i', [(int) $share['filed_under']])
    : null;
$orgName = $org ? (string) (($org['organisation'] ?? '') ?: $org['name']) : fabo_app_name();

/* On the Events sheet, + Add row first asks whether the event is Upcoming or
   Completed, and offers that kind's columns (lib/eventstages.php): each
   column's place, so the page knows which to offer. */
$stages = [];
if ((string) $share['sheet_key'] === 'events') {
    require_once __DIR__ . '/lib/eventstages.php';
    foreach ($cols as $f => $m) {
        $stages[$f] = fabo_evstage_of($f);
    }
}

$docAccept = '.pdf,.doc,.docx,.xls,.xlsx,.csv,.ppt,.pptx,.txt,.zip,.mp4,.mov';
$label     = (string) ($sheet['label'] ?? $share['sheet_key']);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($label) ?> &middot; <?= e((string) $share['department']) ?></title>
<link rel="stylesheet" href="<?= e(fabo_asset('assets/app.css')) ?>">
<link rel="stylesheet" href="<?= e(fabo_asset('assets/livesheet.css')) ?>">
<link rel="stylesheet" href="<?= e(fabo_asset('assets/sharesheet.css')) ?>">
</head>
<body class="ls-body">
<div class="ls-wrap">

  <header class="ls-head">
    <div class="ls-headmain">
      <span class="ls-eyebrow">Live sheet &middot; <?= e($orgName) ?></span>
      <h1><?= e($label) ?> <span class="ls-hub">&middot; <?= e((string) $share['department'])
          ?><?= fabo_share_hub($share) !== '' ? ' &middot; ' . e(fabo_share_hub($share)) : '' ?></span></h1>
      <p>
        Shared with <strong><?= e((string) $share['contact_name']) ?></strong>.
        This is the live <?= e($label) ?> sheet &mdash; every one of its
        <?= count($cols) ?> columns. Click a cell to edit it; each change saves as
        you leave the cell and reaches the team straight away.
        <?php if ($counts['read']): ?>
          Columns marked <span class="ss-lock">auto</span> are filled in by the portal.
        <?php endif; ?>
        Under <strong>Attachments</strong>, add photographs in <strong>Images</strong> and
        files in <strong>Documents</strong> (PDF, Word, Excel, PowerPoint&hellip;), up to
        <?= e(fabo_upload_limit_words()) ?> each &mdash; both appear on the team's sheet
        with the row.
      </p>
    </div>
    <button type="button" class="btn btn-primary" id="ssadd">+ Add row</button>
  </header>

  <div class="ls-flash bad" id="ssgone" hidden></div>

  <div class="ls-bar">
    <div>
      <strong id="sscount"><?= count($rows) ?></strong>
      <span id="sscountword"><?= count($rows) === 1 ? 'row' : 'rows' ?></span>
      <span class="ls-barnote">
        <?= (string) $share['sees'] === 'all'
            ? 'You see every row on this sheet, including the team\'s. You can remove only the rows you add.'
            : 'You see the rows added through this link.' ?>
        <?php if (!empty($share['expires_at'])): ?>
          This link works until <?= e(date('d M Y', strtotime((string) $share['expires_at']))) ?>.
        <?php endif; ?>
        <span class="ss-sync" id="sssync">Updated <?= e(date('H:i:s')) ?></span>
      </span>
    </div>
    <div class="ls-baract">
      <span class="ls-state" id="ssstate"><i></i><span>Live &mdash; everything saved</span></span>
      <button type="button" class="btn" id="sssave">Save all</button>
    </div>
  </div>

  <?php if ($stages): ?>
  <!-- Events of this sheet that have ended and still owe their results
       (lib/evresults.php). Drawn, and kept live, by sharesheet.js. -->
  <section class="ss-due" id="ssdue" hidden>
    <div class="ss-duehead">
      <strong>Results due <span class="ss-duen" id="ssduen"></span></strong>
      <span>These events have ended. Add their results: each opens a short form for that one event.</span>
    </div>
    <ul class="ss-duelist" id="ssduelist"></ul>
  </section>
  <?php endif; ?>

  <div class="ls-gridbox">
    <table class="ls-grid ss-grid" id="ssgrid">
      <thead id="sshead"></thead>
      <tbody id="ssbody"></tbody>
    </table>
    <div class="ls-empty" id="ssempty"<?= $rows ? ' hidden' : '' ?>>
      Nothing yet. Press <strong>+ Add row</strong> to start.
    </div>
  </div>

  <footer class="ls-foot">
    <?= e(fabo_app_name()) ?> &middot; shared with <?= e((string) $share['department']) ?>.
    Anyone with this link can edit this sheet &mdash; keep it to your department.
  </footer>
</div>

<div class="ss-toast" id="sstoast" role="status" aria-live="polite"></div>

<noscript><div class="ls-flash bad" style="margin:20px">This sheet needs JavaScript switched on.</div></noscript>

<script>
window.FABO_SS = <?= json_encode([
    'k'         => (string) $share['token'],
    'api'       => 'api/sharesheet.php',
    'dl'        => 'download.php',
    'docAccept' => $docAccept,
    'maxBytes'  => fabo_upload_limit_bytes(),
    'maxWords'  => fabo_upload_limit_words(),
    // Files bigger than this go in pieces; see fabo_upload_take_chunk().
    'chunkBytes' => fabo_chunk_bytes(),
    'columns'   => $cmeta,
    'lists'     => fabo_share_lists($cols),
    'sig'       => md5((string) json_encode($cmeta)),
    'rows'      => $rows,
    'stages'    => $stages ?: null,
    // Who owes an event's results, unless the person adding it says otherwise.
    'contact'   => ['name' => (string) $share['contact_name'], 'email' => (string) ($share['contact_email'] ?? '')],
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="<?= e(fabo_asset('assets/sharesheet.js')) ?>"></script>
</body>
</html>

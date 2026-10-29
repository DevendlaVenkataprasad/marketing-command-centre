<?php
/**
 * A guest's answer to a meeting invitation: Going, Maybe or Can't go.
 *
 * Opened from the buttons in the invitation email. No sign-in: the link
 * carries a token made for that one guest (lib/meetings.php,
 * fabo_rsvp_token), so it can only ever answer for them.
 *
 * Opening the link does not record anything by itself. Mail scanners
 * (Outlook's Safe Links and the like) open every link in a message to check
 * it, and if a GET counted they would answer Going, Maybe and Can't go on
 * the guest's behalf in a second. So the page shows the choice the guest
 * clicked, already selected, and one tap confirms it.
 *
 * ?a=ics serves the meeting as a calendar file instead.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/meetings.php';
require_once __DIR__ . '/lib/notify.php';
fabo_boot();

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$gid   = (int) ($_REQUEST['g'] ?? 0);
$tok   = (string) ($_REQUEST['t'] ?? '');
$guest = fabo_rsvp_guest($gid, $tok);
$m     = $guest ? fabo_meeting((int) $guest['meeting_id']) : null;
if (!$m) {
    http_response_code(404);
}

$answers = fabo_meeting_responses();
$done    = false;
$wanted  = (string) ($_GET['a'] ?? '');

if ($m && $wanted === 'ics') {
    fabo_meeting_ics_send($m);
}

if ($m && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['answer'] ?? '');
    /* Each change tells the organiser, so a guest's link cannot be used to
       flood them: a handful of changes an hour is plenty for a real person. */
    require_once __DIR__ . '/lib/throttle.php';
    if (fabo_throttle_blocked('rsvp', (string) $gid, 10, 3600)) {
        $a = '';
    } else {
        fabo_throttle_hit('rsvp', (string) $gid);
    }
    if (isset($answers[$a]) && $m['state'] === 'scheduled') {
        if (fabo_meeting_rsvp_set($gid, $a)) {
            fabo_meeting_rsvp_tell($m, $guest, $a);
        }
        $guest['response'] = $a;
        $done = true;
    }
}

$current = (string) ($guest['response'] ?? '');
$pick    = $done ? $current : (isset($answers[$wanted]) ? $wanted : $current);
$over    = $m && (strtotime((string) $m['ends_at']) < time() || $m['state'] !== 'scheduled');
$link    = $m ? trim((string) ($m['meet_url'] ?? '')) : '';
$place   = $m ? trim((string) ($m['location'] ?? '')) : '';
$self    = 'rsvp.php?' . http_build_query(['g' => $gid, 't' => $tok]);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= $m ? e($m['title']) . ' &middot; ' : '' ?>Your reply</title>
<style>
  :root { --ink:#1b2230; --soft:#5f6979; --mute:#8a93a3; --line:#dde2ea; --bg:#f4f6f9; --accent:#1d5fd0;
          --green:#1a7f44; --green-bg:#e6f5ec; --amber:#a76a00; --amber-bg:#fdf3e0; --red:#b3261e; --red-bg:#fdecea;
          color-scheme: light; }
  * { box-sizing: border-box; }
  body { margin:0; font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; background:var(--bg); color:var(--ink);
         min-height:100vh; display:flex; align-items:flex-start; justify-content:center; padding:40px 16px; }
  .card { width:100%; max-width:480px; background:#fff; border:1px solid var(--line); border-radius:16px;
          box-shadow:0 10px 30px rgba(20,32,54,.08); overflow:hidden; }
  .top { padding:24px 24px 18px; border-bottom:1px solid #eceff4; display:flex; gap:16px; align-items:center; }
  .date { flex:0 0 60px; border:1px solid var(--line); border-radius:10px; overflow:hidden; text-align:center; }
  .date .mo { background:var(--accent); color:#fff; font-size:11px; font-weight:700; text-transform:uppercase; padding:2px 0; }
  .date .dy { font-size:24px; font-weight:700; line-height:1.2; padding:2px 0 4px; }
  h1 { margin:0 0 4px; font-size:19px; line-height:1.3; }
  .meta { color:var(--soft); font-size:13.5px; }
  .body { padding:20px 24px 24px; }
  .q { font-weight:650; margin:0 0 12px; }
  .opts { display:grid; grid-template-columns:repeat(3,1fr); gap:8px; }
  .opt { position:relative; }
  .opt input { position:absolute; opacity:0; pointer-events:none; }
  .opt span { display:block; text-align:center; padding:14px 6px; border:1.5px solid var(--line); border-radius:10px;
              font-weight:650; cursor:pointer; font-size:14px; transition:all .15s; }
  .opt span i { display:block; font-style:normal; font-size:20px; margin-bottom:2px; }
  .opt input:checked + span.yes   { border-color:var(--green); background:var(--green-bg); color:var(--green); }
  .opt input:checked + span.maybe { border-color:var(--amber); background:var(--amber-bg); color:var(--amber); }
  .opt input:checked + span.no    { border-color:var(--red);   background:var(--red-bg);   color:var(--red); }
  .opt input:focus-visible + span { outline:2px solid var(--accent); outline-offset:2px; }
  button { width:100%; margin-top:14px; padding:12px; font:inherit; font-weight:650; color:#fff; background:var(--accent);
           border:0; border-radius:10px; cursor:pointer; }
  button:hover { background:#1a54b8; }
  .ok { display:flex; gap:10px; align-items:center; padding:12px 14px; border-radius:10px; margin-bottom:16px; font-weight:600; }
  .ok.yes { background:var(--green-bg); color:var(--green); } .ok.maybe { background:var(--amber-bg); color:var(--amber); }
  .ok.no { background:var(--red-bg); color:var(--red); }
  .links { margin-top:18px; padding-top:16px; border-top:1px solid #eceff4; display:flex; flex-wrap:wrap; gap:8px; }
  .links a { flex:1; min-width:130px; text-align:center; padding:9px 10px; border:1px solid var(--line); border-radius:9px;
             color:var(--ink); text-decoration:none; font-size:13.5px; font-weight:600; }
  .links a:hover { background:var(--bg); }
  .links a.join { background:#128a3e; border-color:#128a3e; color:#fff; flex-basis:100%; }
  .note { color:var(--mute); font-size:13px; margin:12px 0 0; }
  .bad { padding:28px 24px; text-align:center; }
  .bad h1 { margin-bottom:8px; }
</style>
</head>
<body>
<div class="card">
<?php if (!$m): ?>
  <div class="bad">
    <h1>This link doesn&rsquo;t work</h1>
    <p class="meta">It may have been copied only partly, or the meeting was removed.
      Ask the organiser to send the invitation again.</p>
  </div>
<?php else: $s = strtotime((string) $m['starts_at']); ?>
  <div class="top">
    <div class="date"><div class="mo"><?= e(date('M', $s)) ?></div><div class="dy"><?= e(date('j', $s)) ?></div></div>
    <div>
      <h1><?= e($m['title']) ?></h1>
      <div class="meta"><?= e(date('l, j F', $s)) ?> &middot; <?= e(date('H:i', $s)) ?>&ndash;<?= e(date('H:i', strtotime((string) $m['ends_at']))) ?></div>
      <div class="meta">Organised by <?= e($m['organiser_name']) ?><?= $place !== '' && $link === '' ? ' &middot; ' . e($place) : '' ?></div>
    </div>
  </div>
  <div class="body">
    <?php if ($done): ?>
      <div class="ok <?= e($current) ?>"><?= $current === 'yes' ? '&#10003; Thanks! You&rsquo;re going.'
          : ($current === 'no' ? 'Thanks for letting us know you can&rsquo;t make it.' : 'Thanks. We&rsquo;ve marked you as maybe.') ?></div>
    <?php endif; ?>

    <?php if ($m['state'] === 'cancelled'): ?>
      <p class="q">This meeting has been cancelled.</p>
    <?php elseif ($over): ?>
      <p class="q">This meeting has already taken place.</p>
    <?php else: ?>
      <form method="post" action="<?= e($self) ?>">
        <input type="hidden" name="g" value="<?= $gid ?>"><input type="hidden" name="t" value="<?= e($tok) ?>">
        <p class="q"><?= $done ? 'Changed your mind? Pick again.' : 'Hi' . (trim((string) $guest['name']) !== '' ? ' ' . e((string) $guest['name']) : '') . ', will you come?' ?></p>
        <div class="opts">
          <label class="opt"><input type="radio" name="answer" value="yes" <?= $pick === 'yes' ? 'checked' : '' ?> required><span class="yes"><i>&#10003;</i>Going</span></label>
          <label class="opt"><input type="radio" name="answer" value="maybe" <?= $pick === 'maybe' ? 'checked' : '' ?>><span class="maybe"><i>?</i>Maybe</span></label>
          <label class="opt"><input type="radio" name="answer" value="no" <?= $pick === 'no' ? 'checked' : '' ?>><span class="no"><i>&#10005;</i>Can&rsquo;t go</span></label>
        </div>
        <?php if (!$done): ?>
          <button type="submit"><?= $pick !== '' && $pick !== $current ? 'Confirm my reply' : 'Send my reply' ?></button>
        <?php else: ?>
          <button type="submit">Update my reply</button>
        <?php endif; ?>
      </form>
      <?php if ($current !== '' && !$done): ?>
        <p class="note">Your current reply: <strong><?= e($answers[$current][0] ?? '') ?></strong></p>
      <?php endif; ?>
    <?php endif; ?>

    <?php if (!$over && $m['state'] !== 'cancelled'): ?>
    <div class="links">
      <?php if ($link !== ''): ?><a class="join" href="<?= e($link) ?>" target="_blank" rel="noopener">Join the meeting</a><?php endif; ?>
      <a href="<?= e(fabo_meeting_gcal_link($m)) ?>" target="_blank" rel="noopener">+ Google Calendar</a>
      <a href="<?= e($self . '&a=ics') ?>">+ Outlook / Apple</a>
    </div>
    <?php endif; ?>
  </div>
<?php endif; ?>
</div>
</body>
</html>

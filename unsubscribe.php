<?php
/**
 * Unsubscribe from a CRM's bulk emails. Opened from the link at the foot of
 * every bulk message; no sign-in, the link carries a token made for that one
 * message (lib/crm_mail.php, fabo_crm_mail_token).
 *
 * Opening the link changes nothing: mail scanners open every link in a
 * message, and a GET that unsubscribed would take people off lists they never
 * asked to leave. One tap on the button confirms.
 *
 * It applies to the whole organisation that sent it — every one of its CRMs —
 * because that is what somebody asking to stop means.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/crm_mail.php';
fabo_boot();

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$mid  = (int) ($_REQUEST['m'] ?? 0);
$tok  = (string) ($_REQUEST['t'] ?? '');
$q    = fabo_crm_mail_by_token($mid, $tok);
if (!$q) {
    http_response_code(404);
}
$brand = $q ? fabo_org_brand((int) $q['org_id'])[1] : '';
$done  = $q ? fabo_crm_mail_opted_out((int) $q['org_id'], (string) $q['to_email']) : false;
$just  = false;

if ($q && !$done && $_SERVER['REQUEST_METHOD'] === 'POST') {
    fabo_crm_mail_optout($q);
    $done = $just = true;
}
$self = 'unsubscribe.php?' . http_build_query(['m' => $mid, 't' => $tok]);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Unsubscribe</title>
<style>
  :root { --ink:#1b2230; --soft:#5f6979; --line:#dde2ea; --bg:#f4f6f9; --accent:#1d5fd0;
          --green:#1a7f44; --green-bg:#e6f5ec; color-scheme: light; }
  * { box-sizing: border-box; }
  body { margin:0; font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; background:var(--bg); color:var(--ink);
         min-height:100vh; display:flex; align-items:flex-start; justify-content:center; padding:40px 16px; }
  .card { width:100%; max-width:460px; background:#fff; border:1px solid var(--line); border-radius:16px;
          box-shadow:0 10px 30px rgba(20,32,54,.08); padding:26px 24px; }
  h1 { margin:0 0 8px; font-size:20px; line-height:1.3; }
  p { margin:0 0 12px; color:var(--soft); }
  b { color:var(--ink); }
  button { width:100%; margin-top:8px; padding:12px; font:inherit; font-weight:650; color:#fff; background:var(--accent);
           border:0; border-radius:10px; cursor:pointer; }
  button:hover { background:#1a54b8; }
  button:focus-visible { outline:2px solid var(--accent); outline-offset:2px; }
  .ok { padding:12px 14px; border-radius:10px; background:var(--green-bg); color:var(--green); font-weight:600; margin-bottom:12px; }
</style>
</head>
<body>
<div class="card">
<?php if (!$q): ?>
  <h1>This link doesn&rsquo;t work</h1>
  <p>It may have been copied only partly. Reply to the email you received and ask to be taken off the list.</p>
<?php elseif ($done): ?>
  <div class="ok" role="status">&#10003; <?= $just ? 'You&rsquo;re unsubscribed.' : 'You&rsquo;re already unsubscribed.' ?></div>
  <p><b><?= e($q['to_email']) ?></b> will not get any more bulk emails from <b><?= e($brand) ?></b>.</p>
  <p>Messages that are part of something you are already doing with them, like a reply to your own email, may still reach you.</p>
<?php else: ?>
  <h1>Stop these emails?</h1>
  <p><b><?= e($q['to_email']) ?></b> will no longer get bulk emails from <b><?= e($brand) ?></b>.</p>
  <form method="post" action="<?= e($self) ?>">
    <input type="hidden" name="m" value="<?= $mid ?>"><input type="hidden" name="t" value="<?= e($tok) ?>">
    <button type="submit">Unsubscribe</button>
  </form>
<?php endif; ?>
</div>
</body>
</html>

<?php
/**
 * IT Management and Admin & Facilities — the staff side of the helpdesk
 * (?d=fa for facilities; ?o=<client id> for a client organisation's own desk).
 *
 *   ?k=<request token>   raise a new IT request (only while the
 *                        administrator has the link switched on)
 *   ?t=<ticket token>    follow one request: its status and the IT team's
 *                        replies, and write back
 *
 * Both tokens are 32 random hex characters. Internal notes are never shown
 * here. Throttled per address; CSRF-protected; a hidden field catches bots.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/it/app.php';
require_once __DIR__ . '/lib/throttle.php';
fabo_boot();
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, private');

$desk = ($_GET['d'] ?? '') === 'fa' ? 'fa' : 'it';
$org = max(0, (int) ($_GET['o'] ?? 0));
// Only an organisation that holds the desk; a made-up number opens nothing (and creates no tables).
$valid = it_org_enabled($desk, $org);
it_use($desk, $valid ? $org : 0);
if ($valid) {
    it_install();
}
$here = static fn(array $q) => 'it-request.php?' . http_build_query(array_filter(['d' => $desk === 'it' ? null : $desk, 'o' => $org ?: null] + $q));

$k = (string) ($_GET['k'] ?? '');
$t = $valid ? it_ticket_by_token((string) ($_GET['t'] ?? '')) : null;
$open = $valid && $k !== '' && it_setting('request_enabled') === '1' && hash_equals(it_setting('request_token'), $k);
$errors = '';
$done = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($open || $t)) {
    fabo_csrf_check();
    if (trim((string) ($_POST['website_url'] ?? '')) !== '') {
        $done = 'ok';                                               // the honeypot: quietly ignore
    } elseif (fabo_throttle_blocked('it-ip', fabo_client_ip(), 40, 3600)) {
        $errors = fabo_throttle_words();
    } else {
        fabo_throttle_hit('it-ip', fabo_client_ip());
        try {
            if ($t) {
                it_requester_reply($t, (string) ($_POST['body'] ?? ''));
                header('Location: ' . $here(['t' => $t['token'], 'sent' => 1]));
                exit;
            }
            $new = it_ticket_create($_POST, 'link');
            header('Location: ' . $here(['t' => $new['token'], 'new' => 1]));
            exit;
        } catch (InvalidArgumentException $e) {
            $errors = $e->getMessage();
        } catch (Throwable $e) {
            error_log('FABO IT request: ' . $e->getMessage());
            $errors = 'That could not be saved just now. Please try again in a few minutes.';
        }
    }
}
fabo_csrf_token();
$brand = $org > 0 && $valid ? fabo_org_brand($org)[1] : fabo_app_name();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e(($t ? $t['ref'] . ' · ' : '') . it_cfg('support')) ?> &middot; <?= e($brand) ?></title>
<link rel="stylesheet" href="<?= e(fabo_asset('assets/form.css')) ?>">
<link rel="stylesheet" href="<?= e(fabo_asset('assets/inc.css')) ?>">
</head>
<body class="fm-body">
<header class="fm-top"><div class="brandmark"><span><?= e($brand) ?> · <?= e(it_cfg('support')) ?></span></div></header>
<main class="fm-wrap" id="main"><div class="fm-card">
<?php if ($t): $t = it_ticket((int) $t['id']); ?>
  <div class="fm-head"><div class="fm-eyebrow"><?= e($t['ref']) ?> · <?= e(it_statuses()[$t['status']]) ?></div><h1><?= e($t['title']) ?></h1>
  <?php if (!empty($_GET['new'])): ?><p class="fm-lead">Thank you — your request is logged. We have emailed you the reference. Keep this page's link to follow it.</p><?php endif; ?>
  <?php if (!empty($_GET['sent'])): ?><p class="fm-lead">Your message has been added; the <?= e(it_cfg('team')) ?> has been told.</p><?php endif; ?></div>
  <div class="it-public">
    <?php if ($t['description']): ?><div class="it-msg it-msg-requester"><div class="it-msg-h"><strong>You</strong> · <?= e(inc_fmt_datetime($t['created_at'])) ?></div><?= nl2br(e($t['description'])) ?></div><?php endif; ?>
    <?php foreach (it_notes((int) $t['id']) as $n): if ($n['kind'] === 'internal') { continue; } ?>
      <?php if ($n['kind'] === 'system'): ?><div class="it-msg it-msg-system"><?= e($n['body']) ?> · <?= e(inc_fmt_datetime($n['created_at'])) ?></div>
      <?php else: ?><div class="it-msg it-msg-<?= e($n['kind']) ?>"><div class="it-msg-h"><strong><?= e($n['kind'] === 'requester' ? 'You' : ucfirst(it_cfg('team'))) ?></strong> · <?= e(inc_fmt_datetime($n['created_at'])) ?></div><?= nl2br(e($n['body'])) ?></div><?php endif; ?>
    <?php endforeach; ?>
  </div>
  <?php if ($errors !== ''): ?><div class="inc-warn" role="alert"><?= e($errors) ?></div><?php endif; ?>
  <?php if ($t['status'] !== 'closed'): ?>
  <form method="post" class="inc-applyform" style="margin-top:14px"><?= fabo_csrf_field() ?>
    <div class="inc-hp" aria-hidden="true"><label>Leave this empty <input type="text" name="website_url" tabindex="-1" autocomplete="off"></label></div>
    <div class="inc-f"><label for="body"><?= $t['status'] === 'resolved' ? 'Not fixed? Tell us and we will reopen it' : 'Add a message' ?></label><textarea id="body" name="body" rows="4" required maxlength="8000"></textarea></div>
    <div class="inc-formfoot"><button class="btn btn-primary">Send</button></div>
  </form>
  <?php else: ?><p class="fm-lead">This request is closed. If the problem comes back, raise a new request.</p><?php endif; ?>
<?php elseif ($open): ?>
  <div class="fm-head"><div class="fm-eyebrow"><?= e(it_cfg('support')) ?></div><h1><?= e(it_cfg('request_title')) ?></h1><p class="fm-lead">Tell us what is wrong or what you need. You will get a reference by email and a link to follow it.</p></div>
  <?php if ($errors !== ''): ?><div class="inc-warn" role="alert"><?= e($errors) ?></div><?php endif; ?>
  <form method="post" class="inc-applyform inc-form" novalidate><?= fabo_csrf_field() ?>
    <div class="inc-hp" aria-hidden="true"><label>Leave this empty <input type="text" name="website_url" tabindex="-1" autocomplete="off"></label></div>
    <div class="inc-f"><label for="n">Your name <span class="req">*</span></label><input id="n" type="text" name="requester_name" maxlength="160" required value="<?= e($_POST['requester_name'] ?? '') ?>"></div>
    <div class="inc-f"><label for="em">Your email <span class="req">*</span></label><input id="em" type="email" name="requester_email" maxlength="190" required value="<?= e($_POST['requester_email'] ?? '') ?>"></div>
    <div class="inc-f"><label for="c">What is it about?</label><select id="c" name="category"><option value="">Choose…</option><?= inc_options(it_list('ticket_category'), $_POST['category'] ?? '') ?></select></div>
    <div class="inc-f"><label for="pr">How urgent?</label><select id="pr" name="priority"><?= inc_options(['low' => 'Low — whenever possible', 'medium' => 'Normal', 'high' => 'High — it stops my work', 'urgent' => 'Urgent — several people are affected'], $_POST['priority'] ?? 'medium') ?></select></div>
    <div class="inc-f wide"><label for="ti">Subject <span class="req">*</span></label><input id="ti" type="text" name="title" maxlength="200" required placeholder="<?= e(it_cfg('request_hint')) ?>" value="<?= e($_POST['title'] ?? '') ?>"></div>
    <div class="inc-f wide"><label for="d">Details <span class="req">*</span></label><textarea id="d" name="description" rows="5" required maxlength="8000" placeholder="What happens, since when, any error message"><?= e($_POST['description'] ?? '') ?></textarea></div>
    <div class="inc-f"><label for="tag"><?= e(it_cfg('asset')) ?> tag (if you know it)</label><input id="tag" type="text" name="asset_tag" maxlength="40" placeholder="e.g. <?= e(it_cfg('asset_tag')) ?>-00012" value="<?= e($_POST['asset_tag'] ?? '') ?>"></div>
    <div class="inc-formfoot wide"><button class="btn btn-primary">Send request</button></div>
  </form>
<?php else: ?>
  <div class="fm-head"><div class="fm-eyebrow"><?= e(it_cfg('support')) ?></div><h1>This link is not open</h1><p class="fm-lead">Ask your <?= e(it_cfg('team')) ?> for the current request link.</p></div>
<?php endif; ?>
</div></main>
</body>
</html>

<?php
/**
 * Co-working Space — the public application form.
 *
 *   coworking-apply.php?k=<the organisation's form key>[&embed=1]
 *
 * The page an organisation links to, or embeds on its website (embed=1 drops
 * the outer page so it sits in an iframe; .htaccess lets this one page be
 * framed by another site). An application goes onto the chosen centre's
 * Waiting List as New; the applicant is emailed an acknowledgement and the
 * organisation is told (cw_apply() in lib/cw/mail.php).
 *
 * A page inside another site's iframe gets no portal cookie, so this form
 * guards itself without one: a signed, time-limited stamp (cw_form_stamp()),
 * a honeypot field, throttling per address and per form, and a one-time
 * ticket so a double click or a refresh files one application, not two.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/cw/mail.php';
require_once __DIR__ . '/lib/throttle.php';
fabo_boot();
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, private');

$key   = (string) ($_GET['k'] ?? '');
$embed = !empty($_GET['embed']);
cw_install();
$org = cw_form_org($key);
$open = $org !== null && cw_form_on($org);
$centres = $open ? cw_centres($org) : [];
$orgName = $org !== null ? cw_org_name($org) : '';
$errors = '';
$done = null;
$v = static fn(string $k, string $d = '') => (string) ($_POST[$k] ?? $d);

if ($open && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $ip = fabo_client_ip();
    if (trim($v('company_url')) !== '') {
        $errors = 'Something went wrong. Please try again.';                // the honeypot: a script filled the hidden field
    } elseif (!cw_form_stamp_ok($org, $v('stamp'))) {
        $errors = 'This form was open for too long or sent too quickly. Please check your details and send it again.';
    } elseif (fabo_throttle_blocked('cw-apply-ip', $key . '|' . $ip, 8, 3600) || fabo_throttle_blocked('cw-apply-all', $key, 300, 3600)) {
        $errors = fabo_throttle_words();
    } elseif ($v('consent') !== '1') {
        $errors = 'Please tick the box to agree to be contacted about your application.';
    } else {
        fabo_throttle_hit('cw-apply-ip', $key . '|' . $ip);
        fabo_throttle_hit('cw-apply-all', $key);
        try {
            $done = cw_apply($org, $_POST, $v('ticket'));
        } catch (InvalidArgumentException $e) {
            $errors = $e->getMessage();
        } catch (Throwable $e) {
            error_log('FABO cw apply: ' . $e->getMessage());
            $errors = 'Your application could not be saved just now. Please try again in a minute.';
        }
    }
}

$logo = $org !== null && $org > 0 ? 'logo.php?cw=' . rawurlencode($key) : 'logo.php';
$intro = $org !== null ? cw_org_setting($org, 'form_intro') : '';
$heard = ['Our website', 'Social media', 'A friend or colleague', 'An event or workshop', 'Search engine', 'Incubation / mentor network', 'Other'];
$pickedCentre = (int) $v('centre', (string) ($centres[0]['id'] ?? 0));
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($open ? 'Apply for co-working space · ' . $orgName : 'Co-working application') ?></title>
<style>
:root { --ink: #101828; --soft: #475467; --mute: #667085; --line: #e4e7ec; --bg: #f4f6fa; --panel: #fff; --accent: #1d5fd0; --accent-sf: #eaf1fd; --ok: #12805c; --err: #b42318; }
* { box-sizing: border-box; }
body { margin: 0; font: 15px/1.55 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: var(--ink); background: <?= $embed ? 'transparent' : 'var(--bg)' ?>; }
.wrap { max-width: 760px; margin: 0 auto; padding: <?= $embed ? '8px' : '40px 16px 56px' ?>; }
.card { background: var(--panel); border: 1px solid var(--line); border-radius: 18px; box-shadow: 0 1px 2px rgba(16,24,40,.04), 0 12px 32px rgba(16,24,40,.06); overflow: hidden; animation: rise .5s cubic-bezier(.2,.8,.2,1) both; }
.top { position: relative; padding: 28px 32px 22px; border-bottom: 1px solid var(--line); }
.top::before { content: ""; position: absolute; inset: 0 0 auto 0; height: 4px; background: linear-gradient(90deg, var(--accent), #5b4bc4); }
.brand { display: flex; align-items: center; gap: 12px; margin-bottom: 18px; }
.brand img { width: 44px; height: 44px; border-radius: 10px; object-fit: contain; background: #fff; border: 1px solid var(--line); }
.brand span { font-weight: 700; font-size: 14px; color: var(--soft); }
h1 { margin: 0 0 6px; font-size: 26px; line-height: 1.2; letter-spacing: -.015em; }
.lead { margin: 0; color: var(--soft); }
form { padding: 8px 32px 32px; }
fieldset { border: 0; padding: 0; margin: 22px 0 0; }
legend { font-size: 12px; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; color: var(--accent); margin-bottom: 12px; }
.grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px 16px; }
.wide { grid-column: 1 / -1; }
label.f, .f > .lab { display: block; font-size: 13.5px; font-weight: 600; color: var(--soft); }
label.f > input, label.f > select, label.f > textarea { display: block; margin-top: 6px; }
.f > .lab { margin-bottom: 6px; }
label.f b { color: var(--err); font-weight: 700; }
input, select, textarea { width: 100%; padding: 11px 13px; border: 1px solid #d0d5dd; border-radius: 10px; font: inherit; font-size: 15px; color: var(--ink); background: #fff; transition: border-color .15s, box-shadow .15s; }
input:focus, select:focus, textarea:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 4px var(--accent-sf); }
textarea { resize: vertical; min-height: 96px; }
.centres { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
.centre { display: flex; }
.centre input { position: absolute; opacity: 0; pointer-events: none; }
.centre span { flex: 1; display: flex; flex-direction: column; justify-content: center; gap: 2px; min-height: 66px; padding: 12px 14px; overflow-wrap: break-word; border: 1.5px solid #d0d5dd; border-radius: 12px; cursor: pointer; font-weight: 650; color: var(--ink); transition: border-color .15s, background .15s, box-shadow .15s; }
.centre span small { font-size: 11px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--mute); }
.centre:hover span { border-color: #98a2b3; }
.centre input:checked + span { border-color: var(--accent); background: var(--accent-sf); box-shadow: 0 0 0 3px var(--accent-sf); }
.centre input:checked + span small { color: var(--accent); }
.centre input:focus-visible + span { outline: 2px solid var(--accent); outline-offset: 2px; }
.stepper { display: flex; align-items: center; border: 1px solid #d0d5dd; border-radius: 10px; overflow: hidden; width: fit-content; }
.stepper button { width: 42px; height: 44px; border: 0; background: #f9fafb; font-size: 20px; color: var(--soft); cursor: pointer; }
.stepper button:hover { background: var(--accent-sf); color: var(--accent); }
.stepper input { width: 70px; border: 0; border-radius: 0; text-align: center; font-weight: 700; -moz-appearance: textfield; }
.stepper input::-webkit-outer-spin-button, .stepper input::-webkit-inner-spin-button { -webkit-appearance: none; }
.seg { display: inline-flex; padding: 4px; background: #f2f4f7; border-radius: 10px; gap: 4px; }
.seg input { position: absolute; opacity: 0; pointer-events: none; }
.seg span { display: inline-block; padding: 8px 18px; border-radius: 8px; font-weight: 600; color: var(--soft); cursor: pointer; }
.seg input:checked + span { background: #fff; color: var(--ink); box-shadow: 0 1px 3px rgba(16,24,40,.12); }
.seg input:focus-visible + span { outline: 2px solid var(--accent); }
.consent { display: flex; gap: 10px; align-items: flex-start; margin-top: 22px; font-size: 14px; color: var(--soft); }
.consent input { width: 18px; height: 18px; margin-top: 2px; flex: 0 0 auto; }
.send { margin-top: 22px; width: 100%; padding: 14px; border: 0; border-radius: 12px; background: var(--accent); color: #fff; font: inherit; font-size: 16px; font-weight: 700; cursor: pointer; transition: transform .15s, box-shadow .15s, background .15s; }
.send:hover { background: #174ea6; box-shadow: 0 10px 22px rgba(29,95,208,.28); transform: translateY(-1px); }
.send[disabled] { opacity: .7; cursor: wait; transform: none; }
.err { margin: 18px 32px 0; padding: 12px 14px; border-radius: 10px; background: #fef3f2; border: 1px solid #fecdca; color: var(--err); font-size: 14px; }
.hp { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }
.done { padding: 44px 32px 40px; text-align: center; }
.tick { width: 72px; height: 72px; margin: 0 auto 18px; border-radius: 50%; background: #ecfdf3; display: grid; place-items: center; animation: pop .5s cubic-bezier(.2,.8,.2,1) both; }
.tick svg { width: 36px; height: 36px; fill: none; stroke: var(--ok); stroke-width: 3; stroke-linecap: round; stroke-linejoin: round; stroke-dasharray: 40; stroke-dashoffset: 40; animation: draw .6s .25s ease forwards; }
.ref { display: inline-block; margin: 14px 0 6px; padding: 8px 16px; border-radius: 10px; background: var(--accent-sf); color: var(--accent); font-weight: 800; letter-spacing: .04em; font-size: 18px; }
.small { color: var(--mute); font-size: 13px; }
.closed { padding: 40px 32px; text-align: center; color: var(--soft); }
.foot { text-align: center; color: var(--mute); font-size: 12px; margin-top: 16px; }
@keyframes rise { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }
@keyframes pop { from { transform: scale(.6); opacity: 0; } to { transform: none; opacity: 1; } }
@keyframes draw { to { stroke-dashoffset: 0; } }
@media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation-duration: 1ms !important; animation-delay: 0ms !important; transition: none !important; } }
@media (max-width: 600px) { .top, form { padding-left: 18px; padding-right: 18px; } .err { margin: 16px 18px 0; } .grid { grid-template-columns: 1fr; } .centres { grid-template-columns: 1fr 1fr; } h1 { font-size: 22px; } }
</style>
</head>
<body>
<div class="wrap">
<div class="card">
<?php if ($org === null): ?>
  <div class="closed"><h1>Form not found</h1><p>This application link is not valid. Please check the address, or contact the organisation.</p></div>
<?php elseif (!$open): ?>
  <div class="top"><div class="brand"><img src="<?= e($logo) ?>" alt="" onerror="this.remove()"><span><?= e($orgName) ?></span></div><h1>Applications are closed</h1>
    <p class="lead">We are not taking new co-working applications at the moment. Please check back soon.</p></div>
<?php elseif ($done): ?>
  <div class="done">
    <div class="tick"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></div>
    <h1>Thank you, <?= e(trim((string) $done['contact']) ?: (string) $done['applicant']) ?>!</h1>
    <p class="lead">Your application for co-working space at <?= e($orgName) ?> has been received.</p>
    <div class="ref"><?= e((string) $done['ref']) ?></div>
    <p class="small">Your application reference. We have sent a confirmation to <strong><?= e((string) $done['email']) ?></strong>,<br>and our team will be in touch about the next step.</p>
  </div>
<?php else: ?>
  <div class="top">
    <div class="brand"><img src="<?= e($logo) ?>" alt="" onerror="this.remove()"><span><?= e($orgName) ?></span></div>
    <h1>Apply for co-working space</h1>
    <p class="lead"><?= e($intro !== '' ? $intro : 'Tell us about your startup and the space you need. We will confirm by email and get back to you about the next step.') ?></p>
  </div>
  <?php if ($errors !== ''): ?><div class="err" role="alert"><?= e($errors) ?></div><?php endif; ?>
  <form method="post" action="coworking-apply.php?k=<?= e(rawurlencode($key)) . ($embed ? '&amp;embed=1' : '') ?>" id="cwApply" novalidate>
    <input type="hidden" name="stamp" value="<?= e(cw_form_stamp($org)) ?>">
    <input type="hidden" name="ticket" value="<?= e(preg_match('/^[a-f0-9]{32}$/', $v('ticket')) ? $v('ticket') : bin2hex(random_bytes(16))) ?>">
    <div class="hp" aria-hidden="true"><label>Company URL <input name="company_url" tabindex="-1" autocomplete="off"></label></div>

    <fieldset><legend>Your startup</legend><div class="grid">
      <label class="f wide">Startup / company name <b>*</b><input name="applicant" required maxlength="190" value="<?= e($v('applicant')) ?>" autocomplete="organization"></label>
      <label class="f">Sector<input name="sector" maxlength="120" value="<?= e($v('sector')) ?>" placeholder="e.g. AgriTech, HealthTech"></label>
      <label class="f">Website<input name="website" maxlength="255" value="<?= e($v('website')) ?>" placeholder="yourstartup.com" inputmode="url"></label>
      <label class="f wide">Tell us about your startup<textarea name="about" maxlength="4000" placeholder="What you do, your stage and team size."><?= e($v('about')) ?></textarea></label>
    </div></fieldset>

    <fieldset><legend>Contact</legend><div class="grid">
      <label class="f">Your name <b>*</b><input name="contact" required maxlength="160" value="<?= e($v('contact')) ?>" autocomplete="name"></label>
      <label class="f">Phone <b>*</b><input name="phone" required maxlength="20" value="<?= e($v('phone')) ?>" inputmode="tel" autocomplete="tel"></label>
      <label class="f wide">Email <b>*</b><input type="email" name="email" required maxlength="190" value="<?= e($v('email')) ?>" autocomplete="email"></label>
    </div></fieldset>

    <fieldset><legend>The space you need</legend>
      <div class="f" style="font-size:13.5px;font-weight:600;color:var(--soft);margin-bottom:8px">Centre <b style="color:var(--err)">*</b></div>
      <div class="centres" role="radiogroup" aria-label="Centre">
        <?php foreach ($centres as $c): ?>
          <label class="centre"><input type="radio" name="centre" value="<?= (int) $c['id'] ?>"<?= (int) $c['id'] === $pickedCentre ? ' checked' : '' ?>>
            <span><small><?= $c['kind'] === 'hub' ? 'Hub' : 'Spoke' ?></small><?= e($c['name']) ?></span></label>
        <?php endforeach; ?>
      </div>
      <div class="grid" style="margin-top:16px">
        <div class="f"><span class="lab">Desks needed</span>
          <div class="stepper"><button type="button" data-step="-1" aria-label="One fewer desk">−</button>
            <input type="number" name="desks" min="0" max="200" value="<?= e($v('desks', '1')) ?>" aria-label="Desks needed">
            <button type="button" data-step="1" aria-label="One more desk">+</button></div></div>
        <div class="f"><span class="lab">Need a private cabin?</span>
          <div class="seg" role="radiogroup" aria-label="Need a private cabin">
            <label><input type="radio" name="cabin" value="No"<?= $v('cabin', 'No') !== 'Yes' ? ' checked' : '' ?>><span>No</span></label>
            <label><input type="radio" name="cabin" value="Yes"<?= $v('cabin') === 'Yes' ? ' checked' : '' ?>><span>Yes</span></label></div></div>
        <label class="f">When would you like to start?<input type="date" name="start" value="<?= e($v('start')) ?>" min="<?= e(date('Y-m-d')) ?>"></label>
        <label class="f">How did you hear about us?<select name="heard"><option value="">Choose…</option>
          <?php foreach ($heard as $h): ?><option<?= $v('heard') === $h ? ' selected' : '' ?>><?= e($h) ?></option><?php endforeach; ?></select></label>
      </div>
    </fieldset>

    <label class="consent"><input type="checkbox" name="consent" value="1"<?= $v('consent') === '1' ? ' checked' : '' ?> required>
      <span>I agree that <?= e($orgName) ?> may contact me about this application, and keep these details for that purpose.</span></label>
    <button class="send" type="submit">Submit application</button>
  </form>
<?php endif; ?>
</div>
<?php if (!$embed): ?><p class="foot"><?= e($orgName) ?></p><?php endif; ?>
</div>
<script>
(function () {
  var f = document.getElementById('cwApply');
  if (!f) { return; }
  f.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-step]');
    if (!b) { return; }
    var i = f.querySelector('input[name=desks]');
    i.value = Math.max(0, Math.min(200, (parseInt(i.value, 10) || 0) + parseInt(b.getAttribute('data-step'), 10)));
  });
  f.addEventListener('submit', function (ev) {
    if (!f.checkValidity()) { ev.preventDefault(); f.reportValidity(); return; }
    var s = f.querySelector('.send');
    s.disabled = true;
    s.textContent = 'Sending…';
  });
  // Inside a website's iframe: tell the page how tall the form is, for a page that listens.
  if (window.parent !== window) {
    var tell = function () { window.parent.postMessage({ coworkingFormHeight: document.documentElement.scrollHeight }, '*'); };
    window.addEventListener('load', tell);
    window.addEventListener('resize', tell);
  }
})();
</script>
</body>
</html>

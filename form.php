<?php
/**
 * Intake form. One unguessable link.
 *
 * A universal form is a wizard: one step per sheet, every column of that sheet
 * on its step. Fill in the steps you need and skip the rest — on Finish, each
 * step you filled becomes a row on its own sheet. A single-sheet form is just
 * that sheet's step on its own.
 *
 * Answers are held in the session between steps, so nothing is written until
 * you finish.
 */

require_once __DIR__ . '/lib/forms.php';
require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/requisitions.php';     // fabo_req_day, for the slot
fabo_boot();

header('X-Robots-Tag: noindex, nofollow');

$form = fabo_form_by_token((string) ($_GET['k'] ?? ''));
if (!$form) {
    fabo_deny('This form link is not valid.', 404);
}
/* Whose form this is. A form link has no session behind it, so without this
   the wizard is built from the shipped sheets: the shipped column names, our
   dropdown options rather than theirs, and -- because a sheet built in the
   portal belongs to one organisation -- a step that quietly disappears. */
require_once __DIR__ . '/lib/lists.php';
fabo_view_owner((int) ($form['client_id'] ?? 0));

$state     = fabo_form_state($form);
$universal = fabo_form_is_universal($form);
$token     = (string) $form['token'];

fabo_session();
$bag = 'fabo_form_' . $token;          // this form's answers, this visitor only
if (!isset($_SESSION[$bag]) || !empty($_GET['restart'])) {
    $_SESSION[$bag] = [];
}

/** The steps this form walks through, in sheet order. */
$steps = $universal ? fabo_form_sheet_keys() : [(string) $form['sheet_key']];
$steps = array_values(array_filter($steps, static fn($k) => fabo_sheet($k) !== null));
if (!$steps) {
    fabo_deny('This form is not available.', 404);
}

$step = (int) ($_REQUEST['step'] ?? 0);
$step = max(0, min($step, count($steps) - 1));

$done    = null;    // the rows created, once finished
$errors  = [];

/* ------------------------------------------------------------ actions */

if ($state === 'ok' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    fabo_csrf_check();
    $action = (string) ($_POST['action'] ?? 'next');

    // Keep whatever was typed on this step.
    $key   = $steps[$step];
    $sheet = fabo_sheet($key);
    $kept  = [];
    foreach (fabo_form_fields($sheet) as $c) {
        if (fabo_form_is_file_col($c)) {
            continue;                   // a file travels with the Finish press itself
        }
        $v = $_POST[$c[1]] ?? '';
        $v = is_string($v) ? trim($v) : '';
        if ($c[2] === 'checkbox') {
            $v = $v !== '' ? '1' : '';  // unticked is "not answered", not "no"
        }
        $kept[$c[1]] = $v;
    }
    $_SESSION[$bag][$key] = $kept;

    if ($action === 'back') {
        header('Location: form.php?' . http_build_query(['k' => $token, 'step' => max(0, $step - 1)]));
        exit;
    }
    if ($action === 'skip') {
        unset($_SESSION[$bag][$key]);
        header('Location: form.php?' . http_build_query(['k' => $token, 'step' => min(count($steps) - 1, $step + 1)]));
        exit;
    }
    if ($action === 'next') {
        header('Location: form.php?' . http_build_query(['k' => $token, 'step' => min(count($steps) - 1, $step + 1)]));
        exit;
    }

    /* Anyone with the link can finish this form, so finishing is limited: per
       address the request came from, and per form overall -- enough for real
       use, too few to flood the sheet, the team's notifications, or anyone's
       inbox with confirmation emails. */
    require_once __DIR__ . '/lib/throttle.php';
    if ($action === 'finish'
        && (fabo_throttle_blocked('form-ip', $token . '|' . fabo_client_ip(), 20, 3600)
            || fabo_throttle_blocked('form-all', $token, 300, 3600))) {
        $errors[] = fabo_throttle_words();
        $action   = '';
    }
    if ($action === 'finish') {
        fabo_throttle_hit('form-ip', $token . '|' . fabo_client_ip());
        fabo_throttle_hit('form-all', $token);
        // Write one row per step that actually holds something.
        $created = [];
        foreach ($steps as $k) {
            $vals = $_SESSION[$bag][$k] ?? [];
            if (!array_filter($vals, static fn($v) => $v !== '')) {
                continue;                       // an untouched step creates nothing
            }
            $res = fabo_form_submit($form, $vals + ['sheet_key' => $k]);
            if (!$res[0]) {
                $errors[] = fabo_sheet_label($k) . ': ' . $res[1];
                continue;
            }
            $created[] = $res[3] + ['biz_id' => $res[2]];
        }
        if (!$created && !$errors) {
            $errors[] = 'Nothing was filled in, so nothing was saved.';
        }
        if ($created && !$errors) {
            $_SESSION[$bag] = [];
        }
        /* No redirect, whatever was created. This link is handed to people
           outside the portal: sending them to a sheet they cannot open lands
           them on a sign-in page that looks like the form failed. They stay
           here and read what happened. */
        $done = $created;
    }
}

$key      = $steps[$step];
$sheet    = fabo_sheet($key);
$fields   = fabo_form_fields($sheet);
$lists    = fabo_sheet_lists($sheet);
$required = fabo_form_required($sheet);
$saved    = $_SESSION[$bag][$key] ?? [];
$filled   = array_keys(array_filter($_SESSION[$bag], static fn($v) => (bool) array_filter($v, static fn($x) => $x !== '')));
$last     = $step === count($steps) - 1;
$isReq    = ($sheet['table'] ?? '') === 'requisitions';
/* The gallery column, if this sheet has one: it becomes the file picker at
   the foot of the form rather than a question in the grid of them. */
$gallery  = null;
foreach ($sheet['columns'] as $c) {
    if ($c[2] === 'images') { $gallery = $c; break; }
}
fabo_csrf_token();

/* Whose form this is, said in its header: the organisation that created the
   link, with their logo -- not ours. The logo comes through logo.php by this
   form's own token, so it only ever shows the mark of the form's owner. */
[$brandOrg, $brandName, $brandHasLogo] = fabo_org_brand((int) ($form['client_id'] ?? 0));
$brandLogo = $brandHasLogo ? 'logo.php?f=' . rawurlencode($token) : 'assets/logo.png';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($form['label']) ?> &middot; <?= e($brandName) ?></title>
<link rel="icon" href="<?= e($brandLogo) ?>">
<link rel="stylesheet" href="<?= e(fabo_asset('assets/app.css')) ?>">
<link rel="stylesheet" href="<?= e(fabo_asset('assets/form.css')) ?>">
</head>
<body class="fm-body">
<div class="fm-top">
  <div class="brandmark">
    <img class="brandlogo" src="<?= e($brandLogo) ?>" alt="" width="32" height="32">
    <span><?= e($brandName) ?><?php if ($brandOrg === 0): ?>
      <span>&middot; <?= e(fabo_app_tagline()) ?></span><?php endif; ?></span>
  </div>
</div>
<div class="fm-wrap">
  <div class="fm-card">
    <div class="fm-head">
      <span class="fm-eyebrow"><?= $isReq ? 'Requisition' : 'Submission' ?></span>
      <h1><?= e($form['label']) ?></h1>
      <p class="fm-lead">
        <?php if ($universal): ?>
          Step <?= $step + 1 ?> of <?= count($steps) ?>. Fill in the steps you need
          and skip the rest — each one is saved when you finish.
        <?php elseif ($isReq): ?>
          Tell us what you need and when. We will put it in the queue and email
          you the date it will be delivered.
        <?php else: ?>
          Adds one entry to <strong><?= e($sheet['label']) ?></strong>.
        <?php endif; ?>
      </p>
    </div>

<?php if ($state !== 'ok'): ?>
    <div class="flash err"><?= $state === 'expired' ? 'This form has expired.' : 'This form is currently closed.' ?>
      Please contact the marketing team for a new link.</div>

<?php elseif ($done !== null && !$errors): ?>
    <?php
      /* What the queue promised, if this form feeds one. Read from the first
         row created, because a requisition form writes exactly one. */
      $q = null;
      foreach ($done as $d) {
          if (!empty($d['queue'])) { $q = $d['queue']; break; }
      }
    ?>
    <div class="fm-done">
      <div class="fm-tick" aria-hidden="true">&#10003;</div>
      <h2>Thank you — it is in.</h2>
      <p class="fm-donelead">
        <?php if ($q): ?>
          Your requisition has been logged and placed in the queue. Nothing more
          is needed from you; the details are below.
        <?php else: ?>
          Your entry has been recorded.
          <?= count($done) === 1 ? '' : count($done) . ' rows were created.' ?>
        <?php endif; ?>
      </p>

      <div class="fm-refs">
        <?php foreach ($done as $d): ?>
          <div class="fm-ref">
            <span>Your reference</span>
            <strong><?= e($d['biz_id'] ?: ('row ' . $d['row_id'])) ?></strong>
            <small><?= e($d['label']) ?></small>
          </div>
        <?php endforeach; ?>
        <?php if ($q && $q['position'] > 0): ?>
          <div class="fm-ref">
            <span>Place in the queue</span>
            <strong><?= (int) $q['position'] ?></strong>
            <small><?= e($q['type'] ?: 'requisition') ?></small>
          </div>
        <?php endif; ?>
        <?php if ($q && $q['due'] !== ''): ?>
          <div class="fm-ref accent">
            <span>Expected delivery</span>
            <strong><?= e(fabo_req_day($q['due'])) ?></strong>
            <small><?= $q['starts'] !== ''
                ? 'work starts ' . e(fabo_req_day($q['starts'])) : '' ?></small>
          </div>
        <?php endif; ?>
      </div>

      <?php $files = 0; $fileBad = [];
            foreach ($done as $d) {
                $files   += (int) ($d['files'] ?? 0);
                $fileBad  = array_merge($fileBad, (array) ($d['file_problems'] ?? []));
            } ?>
      <?php if ($files > 0): ?>
        <div class="fm-note good"><?= (int) $files ?>
          file<?= $files === 1 ? '' : 's' ?> came with it and
          <?= $files === 1 ? 'is' : 'are' ?> attached to the record.</div>
      <?php endif; ?>
      <?php foreach ($fileBad as $bad): ?>
        <div class="fm-note warn"><?= e($bad) ?> Everything else was saved.</div>
      <?php endforeach; ?>

      <?php if ($q): ?>
        <div class="fm-note <?= $q['emailed'] ? 'good' : 'warn' ?>">
          <?php if ($q['emailed']): ?>
            We have emailed the details to <strong><?= e($q['to']) ?></strong>.
            If the date moves, you will be emailed again with the new one.
          <?php else: ?>
            We could not send the confirmation email just now, but your
            requisition is safely logged under the reference above and the team
            can see it. Please keep that reference.
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php /* No link to the row. This page is read by people with no account,
               and a button that only works for the few who have one is worse
               than no button: it looks broken to everybody else. */ ?>
      <div class="fm-after">
        <a class="btn btn-primary" href="form.php?k=<?= e($token) ?>&amp;restart=1">
          Submit another</a>
      </div>
    </div>

<?php else: ?>

  <?php foreach ($errors as $err): ?>
    <div class="flash err"><?= e($err) ?></div>
  <?php endforeach; ?>

  <?php if ($universal): ?>
    <div class="steps">
      <?php foreach ($steps as $i => $k):
          $cls = $i === $step ? 'now' : (in_array($k, $filled, true) ? 'filled' : ''); ?>
        <a class="stepchip <?= $cls ?>" href="form.php?k=<?= e($token) ?>&amp;step=<?= $i ?>">
          <span class="n"><?= in_array($k, $filled, true) && $i !== $step ? '&#10003;' : ($i + 1) ?></span>
          <?= e(fabo_sheet_label($k)) ?>
        </a>
      <?php endforeach; ?>
    </div>
    <div class="hint" style="margin:-4px 0 16px">
      <?= $filled ? count($filled) . ' step' . (count($filled) === 1 ? '' : 's') . ' filled so far — nothing is saved until you press Finish.'
                  : 'Nothing filled yet. You can jump to any step above.' ?>
    </div>
  <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
      <?= fabo_csrf_field() ?>
      <input type="hidden" name="step" value="<?= $step ?>">
      <h2 class="fm-secttl"><?= $isReq ? 'What do you need?' : e($sheet['label']) ?></h2>
      <p class="fm-hint"><?= count($fields) ?> field<?= count($fields) === 1 ? '' : 's' ?>.
        The ones marked <span class="req">*</span> are needed; the rest help us
        get it right first time.</p>

      <div class="formgrid">
      <?php foreach ($fields as $c):
          [, $field, $type] = $c;
          $label = fabo_form_label($c);
          $req  = in_array($field, $required, true);
          $prev = e($saved[$field] ?? '');
          $full = $type === 'long' ? ' full' : '';
      ?>
        <div class="field<?= $full ?><?= $req ? ' needed' : '' ?>">
          <label for="f_<?= e($field) ?>"><?= e($label) ?><?= $req ? '<span class="req">*</span>' : '' ?></label>
          <?php if ($type === 'checkbox'): ?>
            <label class="fm-check"><input id="f_<?= e($field) ?>" type="checkbox" name="<?= e($field) ?>"
                   value="1"<?= ($saved[$field] ?? '') === '1' ? ' checked' : '' ?>> Yes</label>
          <?php elseif (fabo_form_is_file_col($c)): ?>
            <input id="f_<?= e($field) ?>" type="file" name="cell_<?= e($field) ?>"<?=
              $type === 'image' ? ' accept="image/*"' : '' ?>>
            <small class="fm-help"><?= $type === 'image' ? 'One image.' : 'One file, up to 25 MB.' ?>
              Attach it on the step you press Finish on.</small>
          <?php elseif ($type === 'select'): ?>
            <select id="f_<?= e($field) ?>" name="<?= e($field) ?>"<?= $req ? ' required' : '' ?>>
              <option value="">— choose —</option>
              <?php foreach ($lists[$c[3]['list']] ?? [] as $v): ?>
                <option value="<?= e($v) ?>"<?= ($saved[$field] ?? '') === $v ? ' selected' : '' ?>><?= e($v) ?></option>
              <?php endforeach; ?>
            </select>
          <?php elseif ($type === 'long'): ?>
            <textarea id="f_<?= e($field) ?>" name="<?= e($field) ?>" rows="4"<?=
              $req ? ' required' : '' ?>><?= $prev ?></textarea>
          <?php elseif ($type === 'date'): ?>
            <input id="f_<?= e($field) ?>" type="date" name="<?= e($field) ?>"
                   value="<?= $prev ?>"<?= $req ? ' required' : '' ?>>
          <?php elseif (in_array($type, ['int', 'money', 'dec'], true)): ?>
            <input id="f_<?= e($field) ?>" type="number" step="<?= $type === 'int' ? '1' : 'any' ?>"
                   name="<?= e($field) ?>" value="<?= $prev ?>"<?= $req ? ' required' : '' ?>>
          <?php elseif (fabo_form_is_email_field($field)): ?>
            <?php /* The browser checks the shape; fabo_form_submit checks it again,
                     because a form posted without a browser is still a form. */ ?>
            <input id="f_<?= e($field) ?>" type="email" name="<?= e($field) ?>"
                   value="<?= $prev ?>" placeholder="name@example.com"
                   autocomplete="email" spellcheck="false"<?= $req ? ' required' : '' ?>>
            <small class="fm-help">We send the confirmation, and any change of date, here.</small>
          <?php elseif (str_contains($field, 'phone') || str_contains($field, 'contact')): ?>
            <input id="f_<?= e($field) ?>" type="tel" name="<?= e($field) ?>"
                   value="<?= $prev ?>" autocomplete="tel"<?= $req ? ' required' : '' ?>>
          <?php else: ?>
            <input id="f_<?= e($field) ?>" type="<?= $type === 'url' ? 'url' : 'text' ?>"
                   name="<?= e($field) ?>" value="<?= $prev ?>"<?= $req ? ' required' : '' ?>>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      </div>

      <?php if ($gallery !== null): ?>
        <div class="fm-files">
          <label for="f_files"><?= e($gallery[0]) ?></label>
          <input id="f_files" type="file" name="row_files[]" multiple>
          <small class="fm-help">Logos, references, a rough sketch — anything that
            helps. Several at once is fine.</small>
        </div>
      <?php endif; ?>

      <div class="fm-actions">
        <?php if ($step > 0): ?>
          <button class="btn" name="action" value="back">&larr; Back</button>
        <?php endif; ?>
        <span style="flex:1"></span>
        <?php if ($universal && !$last): ?>
          <button class="btn" name="action" value="skip">Skip this sheet</button>
        <?php endif; ?>
        <?php if (!$last): ?>
          <button class="btn btn-primary" name="action" value="next">Save &amp; continue &rarr;</button>
        <?php endif; ?>
        <button class="btn <?= $last ? 'btn-primary' : '' ?>" name="action" value="finish">
          Finish<?= $universal ? ' &amp; create rows' : '' ?>
        </button>
      </div>
      <p class="fm-hint fm-foot">
        <?php if ($isReq): ?>
          Please give us as much notice as you can: 15 days before the event for
          anything designed, and at least 2 days for something urgent. Attach
          high-resolution partner logos where you have them.
        <?php else: ?>
          <span class="req">*</span> needed before this can be saved &middot;
          only the steps you filled are saved.
        <?php endif; ?>
      </p>
    </form>
<?php endif; ?>
  </div>
  <p class="fm-sig"><?= e($brandName) ?></p>
</div>
</body>
</html>

<?php
/**
 * An event's results form -- the page a results link opens (r.php?k=...).
 *
 * One event, only its results columns (the ones that sit in Completed only:
 * Achieved figures, spend, leads, photographs, case studies), with the
 * event's own details above for reference and each target beside its
 * result. Sent the day after the event ends to whoever owes the results,
 * and linked from the Results due list on both live sheets -- so nobody has
 * to find the sheet or their row in it, and a department can report the
 * results of an event it has already handed in. See lib/evresults.php.
 *
 * No sign-in: the link is the credential, and it names one event. Every
 * value goes through the same checks as the sheet it came from (the share's
 * or the department's): dropdowns, cells shut until the event ends, history.
 *
 * Save draft keeps what is typed; Submit results also marks them in, which
 * stops the reminders and tells the team.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/shares.php';
require_once __DIR__ . '/lib/grid.php';
require_once __DIR__ . '/lib/evresults.php';
require_once __DIR__ . '/lib/eventstages.php';
require_once __DIR__ . '/lib/endpoint.php';
fabo_boot();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
header('Pragma: no-cache');
header('Expires: 0');
header('X-LiteSpeed-Cache-Control: no-cache');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');

/** Pictures and documents one event may carry through this form. */
const FABO_RES_MAX_IMAGES = 20;
const FABO_RES_MAX_FILES  = 15;

$token = (string) ($_GET['k'] ?? $_POST['k'] ?? '');
$rec   = fabo_evres_by_token($token);
$ctx   = $rec ? fabo_evres_context($rec) : null;
$row   = $ctx ? fabo_evres_ctx_row($ctx, (int) $rec['row_id']) : null;

if (!$row) {
    http_response_code(404);
    fabo_simple_page('This link is not available',
        'The event may have been removed, or its sheet closed. Ask whoever sent it to you for a new one.');
    exit;
}

$sheet  = $ctx['sheet'];
$fields = fabo_evres_ctx_fields($ctx);
/* Beside each Achieved, its Expected: the planning column of the same metric. */
$targets = [];
foreach ($sheet['columns'] as $c) {
    $g = (string) ($c[3]['group'] ?? '');
    if ($g !== '' && !isset($fields[$c[1]]) && fabo_evstage_of((string) $c[1]) !== 'result') {
        $targets[$g] ??= $c;
    }
}

$end    = fabo_evres_end($row);
$ended  = $end !== '' && $end <= fabo_today();
$errors = [];
$notice = '';
$who    = trim((string) ($rec['owner_name'] ?? '')) ?: $ctx['contact'];

/* -------------------------------------------------------------- saving */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ended) {
    if (fabo_upload_body_lost()) {
        $errors[] = fabo_upload_too_big_words();
    } elseif (!hash_equals((string) $rec['token'], (string) ($_POST['k'] ?? ''))) {
        $errors[] = 'This form is out of date. Open the link again.';
    } else {
        $posted = (array) ($_POST['v'] ?? []);
        $saved  = 0;
        foreach ($fields as $f => $m) {
            if ($m['mode'] !== 'edit') {
                continue;
            }
            $now = $m['type'] === 'checkbox' ? (isset($posted[$f]) ? '1' : '0') : trim((string) ($posted[$f] ?? ''));
            $was = (string) fabo_raw($m['col'], $row);
            if ($m['type'] === 'checkbox' ? $now === ($was === '1' ? '1' : '0') : $now === $was) {
                continue;
            }
            try {
                $row = fabo_evres_ctx_save($ctx, $row, $fields, $f, $now);
                $saved++;
            } catch (InvalidArgumentException $e) {
                $errors[] = $m['label'] . ': ' . $e->getMessage();
            }
        }

        /* Pictures, documents, and any single-file results column. */
        $have = ['img' => 0, 'doc' => 0];
        foreach (fabo_share_attachments('events', (int) $row['id']) as $a) {
            $isDoc = (string) $a['caption'] === 'file' || !fabo_is_image((string) $a['mime']);
            $have[$isDoc ? 'doc' : 'img']++;
        }
        foreach (['images' => true, 'files' => false] as $name => $pics) {
            $up = $_FILES[$name] ?? null;
            if (!$up || !is_array($up['name'] ?? null)) {
                continue;
            }
            foreach ($up['name'] as $i => $n) {
                $err = (int) ($up['error'][$i] ?? UPLOAD_ERR_NO_FILE);
                if ($err === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                if (in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                    $errors[] = $n . ': ' . fabo_upload_too_big_words();
                    continue;
                }
                $k = $pics ? 'img' : 'doc';
                if ($have[$k] >= ($pics ? FABO_RES_MAX_IMAGES : FABO_RES_MAX_FILES)) {
                    $errors[] = $n . ': this event already has as many ' . ($pics ? 'pictures' : 'documents') . ' as it can carry.';
                    continue;
                }
                try {
                    fabo_evres_ctx_store($ctx, (int) $row['id'],
                                         ['name' => $n, 'type' => $up['type'][$i], 'tmp_name' => $up['tmp_name'][$i],
                                          'error' => $err, 'size' => $up['size'][$i]], $pics ? '' : 'file', $pics);
                    $have[$k]++;
                    $saved++;
                    fabo_evres_ctx_audit($ctx, (int) $row['id'], $pics ? '(images)' : '(attachments)', null, '1 added (results form)');
                } catch (InvalidArgumentException $e) {
                    $errors[] = $e->getMessage();
                }
            }
        }
        foreach ($fields as $f => $m) {
            $up = $_FILES['cell_' . $f] ?? null;
            if ($m['mode'] !== 'file' || !$up || (int) ($up['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            try {
                $aid = fabo_evres_ctx_store($ctx, (int) $row['id'], $up, '__cell:' . $f, $m['type'] === 'image');
                $old = (int) ($row[$f] ?? 0);
                fabo_exec('UPDATE `' . $sheet['table'] . '` SET `' . $f . '` = ? WHERE id = ?', 'ii', [$aid, (int) $row['id']]);
                if ($old > 0) {
                    fabo_share_drop($old);
                }
                fabo_evres_ctx_audit($ctx, (int) $row['id'], $f, $old ?: null, (string) $aid);
                $saved++;
            } catch (InvalidArgumentException $e) {
                $errors[] = $m['label'] . ': ' . $e->getMessage();
            }
        }
        if ($saved) {
            fabo_share_after_change('events', (int) $row['id']);
        }

        $row = fabo_evres_ctx_row($ctx, (int) $row['id']) ?? $row;
        $res = $row['_results'] ?? ['started' => false];
        $rec = fabo_evres_by_token($token) ?? $rec;
        if (($_POST['go'] ?? '') === 'submit') {
            if ($errors) {
                $errors[] = 'Nothing was submitted yet: put the above right, then press Submit results again.';
            } elseif (empty($res['started'])) {
                $errors[] = 'Fill in at least one result before submitting.';
            } else {
                if ((string) $rec['state'] !== 'submitted') {   // an Update after it is in tells nobody again
                    fabo_evres_submit($rec, $ctx, $row, $who);
                }
                header('Location: r.php?k=' . rawurlencode($token) . '&done=1', true, 303);
                exit;
            }
        } elseif (!$errors) {
            if ($saved) {
                fabo_evres_touch($rec);
            }
            header('Location: r.php?k=' . rawurlencode($token) . '&saved=' . ($saved ? 1 : 0), true, 303);
            exit;
        }
    }
}

if (isset($_GET['done'])) {
    $notice = 'Thank you — the results are in. The team can see them now. You can still correct anything below and press Update.';
} elseif (isset($_GET['saved'])) {
    $notice = $_GET['saved'] ? 'Draft saved. Come back to this link any time, and press Submit results when everything is in.'
                             : 'Nothing had changed, so there was nothing to save.';
}

$submitted = (string) ($rec['state'] ?? '') === 'submitted';
$orgName   = fabo_org_brand((int) $ctx['org'])[1];
$title     = trim((string) ($row['event_name'] ?? '')) ?: 'Event ' . ($row['event_id'] ?? '');

/* The event, for reference: what it was, when, where. */
$byField = [];
foreach ($sheet['columns'] as $c) {
    $byField[$c[1]] = $c;
}
$about = [];
foreach (['event_id', 'event_type', 'start_date', 'end_date', 'location', 'city', 'hub_spoke', 'business_unit'] as $f) {
    if (isset($byField[$f]) && trim((string) ($row[$f] ?? '')) !== '') {
        $v = $byField[$f][2] === 'date' ? date('d M Y', strtotime((string) $row[$f])) : (string) $row[$f];
        $about[$byField[$f][0]] = $v;
    }
}
$pics = [];
$docs = [];
foreach (fabo_share_attachments('events', (int) $row['id']) as $a) {
    if (str_starts_with((string) $a['caption'], '__cell:')) {
        continue;
    }
    if ((string) $a['caption'] === 'file' || !fabo_is_image((string) $a['mime'])) {
        $docs[] = (string) $a['original_name'];
    } else {
        $pics[] = (string) $a['original_name'];
    }
}

$lists = [];
foreach ($fields as $m) {
    if (!empty($m['list'])) {
        $lists[$m['list']] = fabo_list_values((string) $m['list']);
    }
}

/** One input for one results column, filled with what is there (or was just posted). */
function fabo_res_input(string $f, array $m, array $row, array $lists): string
{
    $v = isset($_POST['v'][$f]) && is_string($_POST['v'][$f]) ? (string) $_POST['v'][$f] : (string) fabo_raw($m['col'], $row);
    $name = 'v[' . e($f) . ']';
    $id   = 'f_' . e($f);
    if ($m['type'] === 'checkbox') {
        return '<input type="checkbox" id="' . $id . '" name="' . $name . '" value="1"' . ($v === '1' ? ' checked' : '') . '>';
    }
    if (!empty($m['list'])) {
        $opts = $lists[$m['list']] ?? [];
        $h = '<select id="' . $id . '" name="' . $name . '"><option value="">— select —</option>';
        foreach ($opts as $o) {
            $h .= '<option' . ((string) $o === $v ? ' selected' : '') . '>' . e($o) . '</option>';
        }
        if ($v !== '' && !in_array($v, $opts, true)) {
            $h .= '<option selected value="' . e($v) . '">' . e($v) . ' (not in list)</option>';
        }
        return $h . '</select>';
    }
    if ($m['type'] === 'long') {
        return '<textarea id="' . $id . '" name="' . $name . '" rows="4">' . e($v) . '</textarea>';
    }
    $type = $m['type'] === 'date' ? 'date' : (in_array($m['type'], ['int', 'dec', 'money'], true) ? 'number' : ($m['type'] === 'url' ? 'url' : 'text'));
    $step = in_array($m['type'], ['dec', 'money'], true) ? ' step="any"' : '';
    $mode = $type === 'number' ? ' inputmode="' . ($m['type'] === 'int' ? 'numeric' : 'decimal') . '" min="0"' : '';
    return '<input type="' . $type . '"' . $mode . ' id="' . $id . '" name="' . $name . '" value="' . e($v) . '"' . $step . '>';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Results · <?= e($title) ?></title>
<link rel="stylesheet" href="<?= e(fabo_asset('assets/app.css')) ?>">
<link rel="stylesheet" href="<?= e(fabo_asset('assets/results.css')) ?>">
</head>
<body class="rs-body">
<main class="rs-wrap">
  <header class="rs-head">
    <span class="rs-eyebrow"><?= e($orgName) ?> &middot; Event results<?= $ctx['unit'] !== '' ? ' &middot; ' . e($ctx['unit']) : '' ?></span>
    <h1><?= e($title) ?></h1>
    <?php if ($about): ?>
      <dl class="rs-about">
        <?php foreach ($about as $k => $v): ?>
          <div><dt><?= e($k) ?></dt><dd><?= e($v) ?></dd></div>
        <?php endforeach; ?>
      </dl>
    <?php endif; ?>
  </header>

  <?php if (!$ended): ?>
    <div class="rs-flash">
      This event has not ended yet. The results open here
      <?= $end !== '' ? 'on <strong>' . e(date('l, d M Y', strtotime($end))) . '</strong>' : 'once it has an End Date' ?>
      &mdash; you will get an email with this link the day after.
    </div>
  <?php else: ?>

    <?php if ($notice !== ''): ?><div class="rs-flash ok"><?= e($notice) ?></div><?php endif; ?>
    <?php if ($errors): ?>
      <div class="rs-flash bad"><strong>Not everything was saved:</strong>
        <ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>
    <?php if ($submitted && $notice === ''): ?>
      <div class="rs-flash ok">Results submitted<?= !empty($rec['submitted_at']) ? ' on ' . e(date('d M Y', strtotime((string) $rec['submitted_at']))) : '' ?>.
        You can still correct anything below and press Update.</div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="rs-form" action="r.php?k=<?= e($token) ?>">
      <input type="hidden" name="k" value="<?= e($token) ?>">
      <p class="rs-lead">Fill in what the event achieved. Leave a box empty if it does not apply.
        <strong>Save draft</strong> keeps it for later; <strong>Submit results</strong> sends it to the team and stops the reminders.</p>

      <?php foreach ($fields as $f => $m): if ($m['mode'] !== 'edit') { continue; } ?>
        <?php $t = $targets[(string) ($m['col'][3]['group'] ?? '')] ?? null; ?>
        <div class="rs-field">
          <label for="f_<?= e($f) ?>"><?= e($m['label']) ?></label>
          <?= fabo_res_input($f, $m, $row, $lists) ?>
          <?php if ($t && trim((string) ($row[$t[1]] ?? '')) !== ''): ?>
            <small>Expected: <?= e(fabo_display($t, $row)) ?></small>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>

      <?php foreach ($fields as $f => $m): if ($m['mode'] !== 'file') { continue; } ?>
        <div class="rs-field">
          <label for="c_<?= e($f) ?>"><?= e($m['label']) ?></label>
          <?php if ((int) ($row[$f] ?? 0) > 0): ?><small>A file is already attached; choosing another replaces it.</small><?php endif; ?>
          <input type="file" id="c_<?= e($f) ?>" name="cell_<?= e($f) ?>"<?= $m['type'] === 'image' ? ' accept="image/*"' : '' ?>>
        </div>
      <?php endforeach; ?>

      <div class="rs-field">
        <label for="rsimg">Photographs</label>
        <?php if ($pics): ?><small><?= count($pics) ?> already added: <?= e(implode(', ', array_slice($pics, 0, 6))) ?><?= count($pics) > 6 ? '…' : '' ?></small><?php endif; ?>
        <input type="file" id="rsimg" name="images[]" accept="image/*" multiple>
      </div>
      <div class="rs-field">
        <label for="rsdoc">Documents <span class="rs-opt">report, attendance sheet&hellip;</span></label>
        <?php if ($docs): ?><small><?= count($docs) ?> already added: <?= e(implode(', ', array_slice($docs, 0, 6))) ?><?= count($docs) > 6 ? '…' : '' ?></small><?php endif; ?>
        <input type="file" id="rsdoc" name="files[]" multiple
               accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.ppt,.pptx,.txt,.zip,.mp4,.mov">
        <small>Up to <?= e(fabo_upload_limit_words()) ?> each.</small>
      </div>

      <div class="rs-actions">
        <?php if ($submitted): ?>
          <button type="submit" name="go" value="submit" class="btn btn-primary">Update results</button>
        <?php else: ?>
          <button type="submit" name="go" value="draft" class="btn">Save draft</button>
          <button type="submit" name="go" value="submit" class="btn btn-primary">Submit results</button>
        <?php endif; ?>
      </div>
    </form>
  <?php endif; ?>

  <footer class="rs-foot"><?= e($orgName) ?> &middot; this link is for this one event &mdash; keep it to yourself and your team.</footer>
</main>
</body>
</html>

<?php
/**
 * A department's live sheet — the page a sealed link opens.
 *
 * No sign-in: the link is the credential, so it does the whole job. What
 * that buys is a caterer or a security lead filling in their own numbers
 * without an account, and what it costs is that this page must be careful:
 *
 *   - Everything is scoped to the one department the token names. There is
 *     no id in the URL to change, because the department comes out of the
 *     sealed token, not out of a parameter.
 *   - Only the columns in fabo_dept_fields() can be written, whatever is
 *     posted. api/deptsheet.php enforces that, not this page.
 *   - A bad, altered or expired token gets one plain sentence and no clue
 *     about which of those it was.
 *
 * It is a grid, not a stack of forms: a department filling in twenty rows
 * wants to see them at once and tab across, the same as any spreadsheet.
 * Cells save themselves as they are left; the Save button is there for
 * people who do not trust that, and costs nothing to offer.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/departments.php';
require_once __DIR__ . '/lib/endpoint.php';
fabo_boot();

/* Never stored by a cache -- the browser's, the host's (LiteSpeed) or a CDN's.
   This page carries the department's own link inside it, so a stored copy
   handed to a second department would show them the first one's sheet and
   send everything they typed there. Each link must get its own fresh page. */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
header('Pragma: no-cache');
header('Expires: 0');
header('X-LiteSpeed-Cache-Control: no-cache');

$token = (string) ($_GET['t'] ?? '');
$dept  = $token !== '' ? fabo_dept_open($token) : null;

if (!$dept) {
    http_response_code(404);
    fabo_simple_page('This link is not available',
        'It may have been withdrawn, or it may have expired. Ask whoever sent '
      . 'it to you for a new one.');
    exit;
}

/* A department is no longer created against an event: the sheet is theirs
   and the events on it come from them. Links issued before that still name
   one, and still say so at the top — but a sheet without one is the normal
   case now, not a broken link. */
$event = (int) ($dept['event_row_id'] ?? 0) > 0
    ? fabo_one('SELECT * FROM `events` WHERE id = ?', 'i', [(int) $dept['event_row_id']])
    : null;

fabo_dept_touch((int) $dept['id']);

$cols = fabo_dept_columns();
/* + Add row asks first whether the event is Upcoming or Completed, and
   offers that kind's columns: each column's place, so the page knows which
   (lib/eventstages.php). */
require_once __DIR__ . '/lib/eventstages.php';
$stages = [];
foreach (array_keys($cols) as $f) {
    $stages[$f] = fabo_evstage_of($f);
}

/* Budget Required: the event's budget form, for Upcoming events. It is not a
   typed column -- each head the admin allows gets one amount, with its
   quotations -- so it is drawn here as a button where the column sits in the
   main sheet's order, after whichever of this sheet's columns comes before
   it. Off when the column has been taken off the Events sheet. */
require_once __DIR__ . '/lib/budget.php';
$budgetOn    = false;
$budgetAfter = '';
foreach (fabo_sheet('events')['columns'] as $c) {
    if (($c[3]['calc'] ?? '') === 'budget') { $budgetOn = true; break; }
    if (isset($cols[$c[1]])) { $budgetAfter = (string) $c[1]; }
}
$budgetScope = (int) ($dept['client_id'] ?? 0);

/** The Budget Required cell of one row: what has been asked for, or the way in. */
function fabo_d_budget_cell(array $r, int $scope): string
{
    if (fabo_evstage_is_done($r)) {
        return '<td class="ls-budgetcol" data-budget><span class="ls-budgetoff" '
             . 'title="A budget is entered for upcoming events only.">Upcoming events only</span></td>';
    }
    $t = fabo_budget_totals('events', (int) $r['id'], $scope);
    $label = $t['filled']
        ? e(fabo_money((float) $t['total'])) . '<small>' . (int) $t['filled'] . ' head'
          . ($t['filled'] === 1 ? '' : 's')
          . ($t['files'] ? ' · ' . (int) $t['files'] . ' file' . ($t['files'] === 1 ? '' : 's') : '')
          . '</small>'
        : 'Enter budget';
    return '<td class="ls-budgetcol" data-budget><button type="button" class="ls-budget" '
         . 'title="Budget for this event">' . $label . '</button></td>';
}
$rows = fabo_dept_rows((int) $dept['id']);
/* How many are waiting, and how many have already gone. The second is worth
   showing: a sheet that comes back empty after submitting looks like work
   lost unless the page says where it went. */
$tally = fabo_dept_submit_counts((int) $dept['id']);

/* Handing in is a plain form post rather than one of this page's quiet
   background calls. It is the one thing here that cannot be undone, and a
   reload showing an empty sheet is the honest picture of what happened. */
$submitFlash = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && (string) ($_POST['do'] ?? '') === 'submit') {
    try {
        $n = fabo_dept_submit((int) $dept['id']);
        $submitFlash = $n > 0
            ? ['ok', $n === 1
                ? 'One row submitted. It is with the organisers now, and this sheet is ready for your next entries.'
                : $n . ' rows submitted. They are with the organisers now, and this sheet is ready for your next entries.']
            : ['err', 'There was nothing to submit. Add a row first.'];
    } catch (Throwable $e) {
        /* Said rather than thrown. A blank five-hundred on this button reads
           as "Submit does nothing", and whoever is filling the sheet in has
           no way to tell that from their work being lost. It is not: every
           cell was saved as it was typed. */
        error_log('FABO submit: ' . $e->getMessage());
        $submitFlash = ['err', fabo_user_error($e)
            . ' Nothing was lost — every cell you typed is still saved.'];
    }
    $rows  = fabo_dept_rows((int) $dept['id']);
    $tally = fabo_dept_submit_counts((int) $dept['id']);
}

/* Events this department has handed in that have ended and still owe their
   results. Handed-in rows are off this sheet and cannot be changed here, so
   each gets its own results form (r.php, lib/evresults.php). */
$resultsDue = [];
try {
    require_once __DIR__ . '/lib/evresults.php';
    $resultsDue = fabo_evres_dept_due($dept);
} catch (Throwable $e) {
    error_log('FABO d.php results due: ' . $e->getMessage());
}

/** Every image on a row, for the Images column -- not the documents. */
function fabo_d_images(int $rowId): array
{
    return fabo_all("SELECT id, original_name FROM attachments
                     WHERE sheet_key = 'events' AND row_id = ?
                       AND mime LIKE 'image/%' AND COALESCE(caption, '') <> 'file'
                     ORDER BY id", 'i', [$rowId]);
}

/** Every document on a row, for the Attachments column. See api/deptsheet.php. */
function fabo_d_files(int $rowId): array
{
    return fabo_all("SELECT id, original_name, size_bytes FROM attachments
                     WHERE sheet_key = 'events' AND row_id = ?
                       AND (COALESCE(caption, '') = 'file' OR mime NOT LIKE 'image/%')
                     ORDER BY id", 'i', [$rowId]);
}

/** A small badge naming a document's kind, from its extension. */
function fabo_d_kind(string $name): string
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $map = ['pdf' => 'PDF', 'doc' => 'DOC', 'docx' => 'DOC', 'xls' => 'XLS', 'xlsx' => 'XLS',
            'csv' => 'CSV', 'ppt' => 'PPT', 'pptx' => 'PPT', 'txt' => 'TXT', 'zip' => 'ZIP',
            'mp4' => 'VID', 'mov' => 'VID'];
    return $map[$ext] ?? strtoupper(substr($ext, 0, 3) ?: 'FILE');
}

/** The extensions the Attachments column offers, for the file picker. */
$docAccept = '.pdf,.doc,.docx,.xls,.xlsx,.csv,.ppt,.pptx,.txt,.zip,.mp4,.mov';

/* The department's own name heads the sheet when there is no event: it is
   their sheet, and "Event" as a title told them nothing. */
$title = $event
    ? (string) ($event['event_name'] ?? 'Event')
    : (string) ($dept['name'] ?? 'Your sheet');
$when  = '';
if ($event && !empty($event['start_date'])) {
    $when = date('D d M Y', strtotime((string) $event['start_date']));
    if (!empty($event['end_date']) && $event['end_date'] !== $event['start_date']) {
        $when .= ' – ' . date('D d M Y', strtotime((string) $event['end_date']));
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($dept['name']) ?> &middot; <?= e($title) ?></title>
<link rel="stylesheet" href="<?= e(fabo_asset('assets/app.css')) ?>">
<link rel="stylesheet" href="<?= e(fabo_asset('assets/eventcal.css')) ?>">
<link rel="stylesheet" href="<?= e(fabo_asset('assets/livesheet.css')) ?>">
</head>
<body class="ls-body">
<div class="ls-wrap">

  <header class="ls-head">
    <div class="ls-headmain">
      <span class="ls-eyebrow">Live sheet</span>
      <h1><?= e($dept['name']) ?><?= !empty($dept['hub_spoke'])
            ? ' <span class="ls-hub">&middot; ' . e((string) $dept['hub_spoke']) . '</span>' : '' ?></h1>
      <p>
        Rows for <strong><?= e($title) ?></strong><?= $when ? ' · ' . e($when) : '' ?>.
        Click any cell to edit it &mdash; changes save as you go, so nothing is
        lost. Use <strong>+ Add row</strong> for each entry your department is
        reporting &mdash; it asks whether the event is <strong>Upcoming</strong>
        or <strong>Completed</strong>, and opens the columns for that kind.
        <?php if ($budgetOn): ?>
          An upcoming event's <strong>Budget Required</strong> &mdash; an amount for each
          head, with its quotations &mdash; is filled in the same box; it goes to the admin
          for review when you submit.
        <?php endif; ?> Under <strong>Attachments</strong>, use <strong>Images</strong> for
        photographs and <strong>Documents</strong> for PDFs, Word, Excel, PowerPoint and
        the like &mdash; up to <?= e(fabo_upload_limit_words()) ?> each. Both reach the
        organisers' sheet with the row.
        When the sheet is complete, press <strong>Submit</strong> to send it to
        the organisers. Once an upcoming event is over, its results are added through
        its own link &mdash; emailed the day after it ends, and listed under
        <strong>Results due</strong> here.
      </p>
    </div>
    <button type="button" class="btn btn-primary" id="lsadd">+ Add row</button>
  </header>

  <?php if ($submitFlash): ?>
    <div class="ls-flash <?= $submitFlash[0] === 'ok' ? 'good' : 'bad' ?>">
      <?= e($submitFlash[1]) ?>
    </div>
  <?php endif; ?>

  <?php if ($resultsDue): ?>
    <section class="ss-due">
      <div class="ss-duehead">
        <strong>Results due (<?= count($resultsDue) ?>)</strong>
        <span>These events you submitted have ended. Add their results &mdash; each opens a short form for that one event.</span>
      </div>
      <ul class="ss-duelist">
        <?php foreach ($resultsDue as $it): ?>
          <li class="ss-due-<?= e($it['key']) ?>">
            <div class="ss-duetxt">
              <strong><?= e($it['title']) ?></strong>
              <small><?= e(implode(' · ', array_filter([$it['ref'], $it['ended'] !== '' ? 'ended ' . $it['ended'] : '',
                                                         $it['owner'] !== '' ? 'results from ' . $it['owner'] : '']))) ?></small>
            </div>
            <span class="ss-duepill"><?= e($it['key'] === 'overdue' ? 'Overdue · ' . $it['days'] . ' days'
                                          : ($it['key'] === 'started' ? 'In progress — not submitted' : 'Due')) ?></span>
            <?php if ($it['url'] !== ''): ?>
              <a class="btn btn-primary ss-duego" href="<?= e($it['url']) ?>" target="_blank" rel="noopener">
                <?= $it['key'] === 'started' ? 'Finish results' : 'Add results' ?></a>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endif; ?>

  <div class="ls-bar">
    <div>
      <strong id="lscount"><?= count($rows) ?></strong>
      <span id="lscountword"><?= count($rows) === 1 ? 'row' : 'rows' ?> open for editing.</span>
      <span class="ls-barnote">Saved as you type, but not sent until you press Submit.
        <?php if ($tally['sent'] > 0): ?>
          <strong><?= (int) $tally['sent'] ?></strong>
          already submitted.
        <?php endif; ?>
        <?php if (!empty($dept['expires_at'])): ?>
          This link works until <?= e(date('d M Y', strtotime((string) $dept['expires_at']))) ?>.
        <?php endif; ?>
      </span>
    </div>
    <div class="ls-baract">
      <span class="ls-state" id="lsstate"><i></i>Live &mdash; everything saved</span>
      <button type="button" class="btn" id="lssave">Save all</button>
      <?php /* A plain form, so it works with the script off and so the page
               comes back showing an empty sheet -- which is what submitting
               actually did. The question is asked because it cannot be undone
               from here. */ ?>
      <form method="post" class="ls-submitform"
            onsubmit="return confirm('Submit this sheet to the organisers? The rows go onto their sheet and you will not be able to change them here afterwards.');">
        <input type="hidden" name="do" value="submit">
        <button class="btn btn-primary" id="lssubmit"<?= $rows ? '' : ' disabled' ?>>
          Submit<?= $rows ? ' ' . count($rows) . ' row' . (count($rows) === 1 ? '' : 's') : '' ?>
        </button>
      </form>
    </div>
  </div>

  <div class="ls-gridbox">
    <table class="ls-grid" id="lsgrid">
      <thead>
        <tr class="ls-h1">
          <th class="ls-num" rowspan="2">#</th>
          <?php if ($budgetOn && $budgetAfter === ''): ?>
            <th class="ls-budgetcol" rowspan="2">Budget Required</th>
          <?php endif; ?>
          <?php foreach ($cols as $field => $meta): ?>
            <th data-field="<?= e($field) ?>" rowspan="2"><?= e($meta['label']) ?></th>
            <?php if ($budgetOn && $field === $budgetAfter): ?>
              <th class="ls-budgetcol" rowspan="2">Budget Required</th>
            <?php endif; ?>
          <?php endforeach; ?>
          <th class="ls-attgroup" colspan="2">Attachments</th>
          <th class="ls-rowact" rowspan="2"></th>
        </tr>
        <tr class="ls-h2">
          <th class="ls-imgcol">Images</th>
          <th class="ls-filecol">Documents</th>
        </tr>
      </thead>
      <tbody id="lsbody">
        <?php foreach ($rows as $n => $r): ?>
          <tr data-id="<?= (int) $r['id'] ?>">
            <td class="ls-num"><span class="ls-n"><?= $n + 1 ?></span>
              <small><?= e($r['event_id'] ?? '') ?></small></td>
            <?php if ($budgetOn && $budgetAfter === '') { echo fabo_d_budget_cell($r, $budgetScope); } ?>
            <?php foreach ($cols as $field => $meta):
                $v = (string) ($r[$field] ?? ''); ?>
              <td data-field="<?= e($field) ?>">
                <?php if ($meta['type'] === 'long'): ?>
                  <textarea class="ls-cell" rows="1"><?= e($v) ?></textarea>
                <?php elseif ($meta['list']):
                    $vals = fabo_list_values((string) $meta['list']); ?>
                  <select class="ls-cell">
                    <option value="">— select —</option>
                    <?php foreach ($vals as $o): ?>
                      <option value="<?= e($o) ?>"<?= $o === $v ? ' selected' : '' ?>><?= e($o) ?></option>
                    <?php endforeach; ?>
                    <?php if ($v !== '' && !in_array($v, $vals, true)): ?>
                      <option value="<?= e($v) ?>" selected><?= e($v) ?> (not in list)</option>
                    <?php endif; ?>
                  </select>
                <?php else: ?>
                  <input class="ls-cell" value="<?= e($v) ?>"
                         type="<?= $meta['type'] === 'date' ? 'date'
                                 : (in_array($meta['type'], ['int','dec','money'], true) ? 'number' : 'text') ?>"
                         <?= in_array($meta['type'], ['dec','money'], true) ? 'step="0.01"' : '' ?>>
                <?php endif; ?>
              </td>
              <?php if ($budgetOn && $field === $budgetAfter) { echo fabo_d_budget_cell($r, $budgetScope); } ?>
            <?php endforeach; ?>
            <td class="ls-imgcol" data-images>
              <div class="ls-imgs">
                <?php foreach (fabo_d_images((int) $r['id']) as $a): ?>
                  <figure data-image="<?= (int) $a['id'] ?>">
                    <a href="<?= e('download.php?a=' . (int) $a['id'] . '&t=' . rawurlencode($token)) ?>" target="_blank" rel="noopener"><img
                       src="<?= e('download.php?a=' . (int) $a['id'] . '&thumb=1&t=' . rawurlencode($token)) ?>"
                       alt="<?= e($a['original_name']) ?>" loading="lazy" decoding="async"></a>
                    <button type="button" class="ls-imgx" title="Remove this image">&times;</button>
                  </figure>
                <?php endforeach; ?>
                <label class="ls-imgadd" title="Any image type">+
                  <input type="file" accept="image/*" multiple hidden></label>
              </div>
            </td>
            <td class="ls-filecol" data-files>
              <ul class="ls-files">
                <?php foreach (fabo_d_files((int) $r['id']) as $a): ?>
                  <li data-file="<?= (int) $a['id'] ?>">
                    <span class="ls-kind"><?= e(fabo_d_kind((string) $a['original_name'])) ?></span>
                    <a href="<?= e('download.php?a=' . (int) $a['id'] . '&t=' . rawurlencode($token)) ?>"
                       title="<?= e($a['original_name']) ?>"><?= e($a['original_name']) ?></a>
                    <small><?= e(fabo_human_size((int) $a['size_bytes'])) ?></small>
                    <button type="button" class="ls-filex" title="Remove this document">&times;</button>
                  </li>
                <?php endforeach; ?>
              </ul>
              <label class="ls-fileadd" title="PDF, Word, Excel, PowerPoint, CSV, text or ZIP — up to <?= e(fabo_upload_limit_words()) ?> each">
                + Add document
                <input type="file" accept="<?= e($docAccept) ?>" multiple hidden></label>
            </td>
            <td class="ls-rowact">
              <button type="button" class="ls-del" title="Remove this row">&times;</button>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <div class="ls-empty" id="lsempty"<?= $rows ? ' hidden' : '' ?>>
      <?php if ($tally['sent'] > 0): ?>
        Nothing waiting. Your <?= (int) $tally['sent'] ?>
        submitted row<?= $tally['sent'] === 1 ? ' is' : 's are' ?> with the organisers.
        Press <strong>+ Add row</strong> to report something else.
      <?php else: ?>
        Nothing yet. Press <strong>+ Add row</strong> to start.
      <?php endif; ?>
    </div>
  </div>

  <footer class="ls-foot">
    <?= e(fabo_app_name()) ?> &middot; this sheet is private to your department.
  </footer>
</div>

<script>
window.FABO_LS = {
  t: <?= json_encode($token) ?>,
  <?php /* Which department this page was drawn for. Every answer from the
           endpoint names its department too, and the script stops if the two
           ever differ -- see assets/livesheet.js. */ ?>
  dept: <?= (int) $dept['id'] ?>,
  deptName: <?= json_encode((string) $dept['name'] . (!empty($dept['hub_spoke']) ? ' · ' . $dept['hub_spoke'] : '')) ?>,
  api: 'api/deptsheet.php',
  dl: 'download.php',
  docAccept: <?= json_encode($docAccept) ?>,
  <?php /* The biggest file this server takes, so a bigger one is refused
           before it is sent rather than lost on the way. */ ?>
  maxBytes: <?= (int) fabo_upload_limit_bytes() ?>,
  maxWords: <?= json_encode(fabo_upload_limit_words()) ?>,
  <?php /* Pictures and documents of any size: bigger than this, they go in
           pieces (fabo_upload_take_chunk). The budget's files still keep maxBytes. */ ?>
  chunkBytes: <?= (int) fabo_chunk_bytes() ?>,
  <?php /* Upcoming / Completed / Both, per column, for the Add row box. */ ?>
  stages: <?= json_encode($stages) ?>,
  <?php /* Who owes an Upcoming event's results, unless the box says otherwise. */ ?>
  contact: <?= json_encode(['name' => (string) ($dept['contact_name'] ?? ''),
                             'email' => (string) ($dept['contact_email'] ?? '')]) ?>,
  <?php /* Budget Required: on, and which column it follows ('' = first). */ ?>
  budget: <?= json_encode($budgetOn) ?>,
  budgetAfter: <?= json_encode($budgetAfter) ?>,
  <?php /* The heads the Add row box offers under Budget Required: names only.
           No allocation or limit ever reaches this page. */ ?>
  budgetHeads: <?= json_encode($budgetOn ? array_map(static fn($h) => ['id' => (int) $h['id'],
                                                                       'name' => (string) $h['name']],
                                                     fabo_budget_items($budgetScope, true)) : []) ?>,
  fields: <?= json_encode(array_map(static fn($m) => ['label' => $m['label'],
                                                      'type' => $m['type'],
                                                      'list' => $m['list']], $cols)) ?>,
  lists: <?= json_encode(array_reduce(array_keys($cols), static function ($acc, $f) use ($cols) {
      $l = $cols[$f]['list'];
      if ($l) { $acc[$l] = fabo_list_values((string) $l); }
      return $acc;
  }, [])) ?>
};
</script>
<script src="<?= e(fabo_asset('assets/livesheet.js')) ?>"></script>
</body>
</html>

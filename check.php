<?php
/**
 * What is actually on this server.
 *
 * The portal is uploaded as loose files, so for a while after an upload some
 * are the new version and some are not — and a half-finished upload looks
 * exactly like a broken feature. A page whose Submit button does nothing tells
 * you nothing about which of four files did not arrive.
 *
 * So this says. It names each piece a recent change needs and whether it is
 * there, and nothing else: no data, no settings, no credentials.
 *
 * Admin only, because "which version is this" is a question about our
 * deployment, not about anybody's marketing.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/departments.php';
fabo_boot();
fabo_require_admin();

/** Whether a file defines a function, without loading it. */
function fabo_chk_has(string $rel, string $needle): bool
{
    $p = __DIR__ . '/' . $rel;
    return is_file($p) && str_contains((string) file_get_contents($p), $needle);
}

/**
 * Whether a table has a column.
 *
 * The column is compared here rather than passed to LIKE: SHOW COLUMNS takes
 * no placeholder, and putting the name straight into the statement is how
 * injections get written. Both names come from this file, but the habit is
 * the point.
 */
function fabo_chk_col(string $table, string $col): bool
{
    if (!preg_match('~^[a-z0-9_]+$~i', $table)) {
        return false;
    }
    try {
        foreach (fabo_all('SHOW COLUMNS FROM `' . $table . '`') as $r) {
            if (strcasecmp((string) $r['Field'], $col) === 0) {
                return true;
            }
        }
    } catch (Throwable $e) {
        // No such table: the answer is simply no.
    }
    return false;
}

$checks = [
    'Handing a shared sheet in' => [
        ['lib/departments.php carries fabo_dept_submit()',
         fabo_chk_has('lib/departments.php', 'function fabo_dept_submit('),
         'Without it, pressing Submit is a blank page.'],
        ['d.php has the Submit button',
         fabo_chk_has('d.php', "'submit'"),
         'Without it, there is nothing to press.'],
        ['api/deptsheet.php answers submit',
         fabo_chk_has('api/deptsheet.php', "action === 'submit'"),
         'Only needed by the background call; the button works without it.'],
        ['lib/grid.php keeps drafts off the main sheet',
         fabo_chk_has('lib/grid.php', 'dept_submitted_at'),
         'Without it, rows appear on the main sheet before they are submitted.'],
        ['the events table has dept_submitted_at',
         fabo_chk_col('events', 'dept_submitted_at'),
         'Added on the next page load once lib/db.php is uploaded.'],
    ],
    'Shared sheets stay in their own portal' => [
        ['lib/departments.php carries fabo_dept_portal()',
         fabo_chk_has('lib/departments.php', 'function fabo_dept_portal('),
         'Without it, the admin portal lists every client\'s shared sheets.'],
        ['lib/deptblock.php uses it',
         fabo_chk_has('lib/deptblock.php', 'fabo_dept_portal('),
         ''],
    ],
    'Hub and spoke' => [
        ['event_departments has hub_spoke', fabo_chk_col('event_departments', 'hub_spoke'), ''],
        ['the events sheet has hub_spoke',  fabo_chk_col('events', 'hub_spoke'), ''],
        ['the Hub / Spoke list has options',
         count(fabo_hub_values()) > 0,
         'Add them on Sheet Management → Dropdown options.'],
    ],
    'Logos' => [
        ['logo.php is here', is_file(__DIR__ . '/logo.php'), ''],
        ['assets/logo.png is here', is_file(__DIR__ . '/assets/logo.png'), ''],
        ['lib/brand.php resolves the organisation',
         fabo_chk_has('lib/brand.php', 'function fabo_brand_client_id('),
         'Without it, a team member sees their own photograph as the logo.'],
    ],
    'Columns' => [
        ['column_order exists', fabo_chk_col('column_order', 'position'), ''],
        ['column_hidden exists', fabo_chk_col('column_hidden', 'field'), ''],
        ['assets/colorder.js is here', is_file(__DIR__ . '/assets/colorder.js'), ''],
    ],
];

/*
 * Is the server running the files that are on it?
 *
 * PHP caches compiled code (opcache). On most shared hosting that cache is
 * re-checked every few seconds, but on some it is not re-checked at all until
 * the server is restarted -- so a file can be uploaded, sit on disk correct,
 * and go on running as the old version for hours.
 *
 * That failure is invisible: every check above reads the file from disk and
 * says yes, while PHP runs something else. So the two are compared. A
 * disagreement here is the answer to "I uploaded the fix and nothing changed".
 */
$runtime = [
    ['fabo_dept_submit',     'lib/departments.php', 'Submit on a shared sheet'],
    ['fabo_dept_portal',     'lib/departments.php', 'Shared sheets staying in their portal'],
    ['fabo_brand_client_id', 'lib/brand.php',       'A client portal showing its own logo'],
    ['fabo_column_order',    'lib/sheets.php',      'Dragging columns into order'],
];
$stale = [];
foreach ($runtime as [$fn, $file, $what]) {
    $onDisk  = fabo_chk_has($file, 'function ' . $fn . '(');
    $running = function_exists($fn);
    if ($onDisk && !$running) {
        $stale[] = [$fn, $file, $what];
    }
}

/* Clearing it, for the case above. Admin only, and it changes no data -- it
   only tells PHP to read the files again. */
$cleared = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['reset_opcache'])) {
    fabo_csrf_check();
    if (function_exists('opcache_reset') && @opcache_reset()) {
        $cleared = ['ok', 'Cleared. Reload this page — the checks below now '
                        . 'describe the code actually running.'];
    } else {
        $cleared = ['err', 'This server does not let a page clear the cache. '
                         . 'Ask Hostinger to restart PHP, or wait a few minutes.'];
    }
}

$bad = 0;
foreach ($checks as $rows) {
    foreach ($rows as $r) { if (!$r[1]) { $bad++; } }
}

fabo_head($viewer = fabo_admin_viewer(), 'Installation check', '');
?>
<div class="block">
  <h2>Installation check</h2>
  <p class="sb-lead">
    <?php if ($bad === 0): ?>
      Everything a recent change needs is on this server.
    <?php else: ?>
      <strong><?= $bad ?> piece<?= $bad === 1 ? '' : 's' ?></strong> not here yet.
      Upload the file named beside each one and reload this page — the database
      columns add themselves once <code>lib/db.php</code> is up.
    <?php endif; ?>
  </p>

  <?php if ($cleared): ?>
    <p class="sb-lead"><strong><?= e($cleared[1]) ?></strong></p>
  <?php endif; ?>

  <?php if ($stale): ?>
    <div class="deptwarn">
      <strong>This server is running older code than the files on it.</strong>
      <p>Everything below reads the files from disk. PHP, though, is still running
        a compiled copy of an earlier version, so uploading the fix changed nothing.
        This affects:</p>
      <ul>
        <?php foreach ($stale as [$fn, $file, $what]): ?>
          <li><?= e($what) ?> &mdash; <code><?= e($file) ?></code></li>
        <?php endforeach; ?>
      </ul>
      <form method="post" class="sb-inline">
        <?= fabo_csrf_field() ?>
        <button class="btn btn-sm btn-primary" name="reset_opcache" value="1">
          Make PHP read the files again</button>
      </form>
    </div>
  <?php endif; ?>

  <?php foreach ($checks as $group => $rows): ?>
    <h3><?= e($group) ?></h3>
    <div class="scrollx">
      <table class="data">
        <thead><tr><th style="width:56%">What</th><th style="width:12%">There</th>
          <th style="width:32%">If not</th></tr></thead>
        <tbody>
          <?php foreach ($rows as [$what, $ok, $why]): ?>
            <tr>
              <td><?= e($what) ?></td>
              <td><span class="pill tone-<?= $ok ? 'good' : 'risk' ?>"><i class="dot"></i><?=
                    $ok ? 'yes' : 'no' ?></span></td>
              <td><?= $ok ? '<span class="ro">&mdash;</span>' : e($why) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endforeach; ?>
</div>
<?php fabo_foot();

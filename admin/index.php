<?php
/**
 * Admin — landing. Every sheet, the Clients area and the exports.
 */

require_once __DIR__ . '/../lib/ui.php';
fabo_boot();
fabo_require_admin();

$viewer = fabo_admin_viewer();
$flash  = null;

/**
 * Clears the sample rows the portal shipped with, so the sheets start empty
 * and hold only your own data. Reference Lists are kept, because the dropdowns
 * are built from them; clients, team members and uploads are untouched.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    fabo_csrf_check();
    if (($_POST['action'] ?? '') === 'clear_rows' && ($_POST['confirm'] ?? '') === 'DELETE') {
        $keepLists = !empty($_POST['keep_lists']);
        $cleared   = [];
        foreach (fabo_sheets() as $key => $sheet) {
            if ($key === 'lists' && $keepLists) {
                continue;
            }
            $n = (int) (fabo_one('SELECT COUNT(*) AS n FROM `' . $sheet['table'] . '`')['n'] ?? 0);
            if ($n > 0) {
                fabo_exec('DELETE FROM `' . $sheet['table'] . '`');
                $cleared[] = $sheet['label'] . ' (' . $n . ')';
            }
        }
        // Row-scoped extras become orphans otherwise.
        fabo_exec('DELETE FROM approvals');
        fabo_exec('DELETE FROM approval_log');
        fabo_exec('DELETE FROM activity_audit');
        $flash = ['ok', $cleared
            ? 'Cleared: ' . implode(', ', $cleared) . '. The sheets are now empty and ready for your own data.'
            : 'Nothing to clear — the sheets were already empty.'];
    } elseif (($_POST['action'] ?? '') === 'clear_rows') {
        $flash = ['err', 'Type DELETE exactly to confirm. Nothing was changed.'];
    }
}

$counts = [];
foreach (fabo_sheets() as $key => $sheet) {
    $row = fabo_one('SELECT COUNT(*) AS n FROM `' . $sheet['table'] . '`');
    $counts[$key] = (int) ($row['n'] ?? 0);
}
$clientCount  = (int) (fabo_one('SELECT COUNT(*) AS n FROM clients')['n'] ?? 0);
$requestCount = (int) (fabo_one('SELECT COUNT(*) AS n FROM clients WHERE access_requested_at IS NOT NULL')['n'] ?? 0);

fabo_head($viewer, 'Admin', '');
?>
<div class="wrap-page">

  <div class="pagehead">
    <h1>Admin portal</h1>
    <div class="sub">Every sheet is live. Formulas are computed server-side; nothing needs refreshing.</div>
  </div>

<?php if ($flash): ?>
  <div class="flash <?= e($flash[0]) ?>"><?= e($flash[1]) ?></div>
<?php endif; ?>

<?php if ($requestCount): ?>
  <div class="flash err">
    <?= $requestCount ?> access request<?= $requestCount === 1 ? '' : 's' ?> waiting —
    <a href="clients.php">open the Clients page</a>.
  </div>
<?php endif; ?>

  <div class="card">
    <h2>Sheets</h2>
    <table class="data">
      <thead><tr><th>Sheet</th><th class="num">Rows</th><th></th></tr></thead>
      <tbody>
        <tr>
          <td><a href="dashboard.php">Dashboard</a></td>
          <td class="num" style="color:var(--ink-mute)">live</td>
          <td><a class="btn btn-sm" href="../export.php?scope=sheet&amp;sheet=dashboard">Export</a></td>
        </tr>
      <?php foreach (fabo_sheet_order() as $key):
            if ($key === 'dashboard') { continue; } ?>
        <tr>
          <td><a href="sheet.php?sheet=<?= e($key) ?>"><?= e(fabo_sheet_label($key)) ?></a></td>
          <td class="num"><?= (int) $counts[$key] ?></td>
          <td><a class="btn btn-sm" href="../export.php?scope=sheet&amp;sheet=<?= e($key) ?>">Export</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="card">
    <h2>Client links</h2>
    <p style="margin:0 0 12px;color:var(--ink-soft);font-size:13.5px">
      <?= $clientCount ?> client<?= $clientCount === 1 ? '' : 's' ?>.
      Each gets one unguessable URL showing only the sheets you assign, filtered to their brand and business unit.
    </p>
    <a class="btn btn-primary" href="clients.php">Manage clients</a>
    <a class="btn" href="../export.php?scope=all">Export all sheets (.xlsx)</a>
  </div>

  <div class="card" style="border-color:#f0c8c8">
    <h2>Start with your own data</h2>
    <p style="margin:0 0 12px;color:var(--ink-soft);font-size:13.5px">
      The portal ships with the workbook's sample rows so you can see it working. When you are
      ready to use only your own real data, clear them here. This empties every sheet listed
      above and cannot be undone — <strong>export first</strong> if you want a copy.
      Your clients, team members, uploaded files and dropdown options are not touched.
    </p>
    <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <?= fabo_csrf_field() ?>
      <label style="font-size:13px;color:var(--ink-soft)">
        <input type="checkbox" name="keep_lists" value="1" checked> Keep the reference Lists (recommended — the dropdowns read them)
      </label>
      <span style="flex-basis:100%"></span>
      <input name="confirm" placeholder="Type DELETE to confirm" autocomplete="off"
             style="font:inherit;font-size:13px;padding:7px 10px;border:1px solid var(--line);border-radius:6px;width:210px">
      <button class="btn btn-danger" name="action" value="clear_rows"
              onclick="return confirm('Clear every sample row from all sheets? This cannot be undone.')">Clear all sheet rows</button>
    </form>
  </div>

</div>
<?php fabo_foot();

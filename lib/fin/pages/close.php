<?php
/**
 * Period close: the month-end checklist (what the system can verify, and
 * what a person signs off with a note and evidence), closing and reopening
 * months, and the year-end close that moves profit to retained earnings and
 * locks the year.
 */

$fy = (int) ($_GET['fy'] ?? fp_fy_of());
fp_periods_ensure($fy);
$month = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['month'] ?? '')) ? $_GET['month'] : date('Y-m', strtotime('first day of last month'));
$back = fp_url('close', ['fy' => $fy, 'month' => $month]);
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    if ($a === 'period') {
        $flash = fp_action(static fn() => fp_period_set((int) ($_POST['id'] ?? 0), (string) ($_POST['status'] ?? ''), (string) ($_POST['note'] ?? '')), $back);
    } elseif ($a === 'control') {
        $flash = fp_action(static function () {
            fp_control_record((int) ($_POST['control_id'] ?? 0), (string) ($_POST['period'] ?? ''), (string) ($_POST['status'] ?? ''), (string) ($_POST['note'] ?? ''));
            return 'Recorded.';
        }, $back);
    } elseif ($a === 'year_close') {
        $flash = fp_action(static fn() => fp_year_close((int) ($_POST['fy'] ?? 0)), $back);
    }
}

$periods = fp_all('SELECT * FROM fp_periods WHERE fy = ? ORDER BY period_no', 'i', [$fy]);
fp_layout_start('Period close', 'FY ' . e(fp_fy_label($fy)), '<form method="get" class="inc-inline"><input type="hidden" name="p" value="close">' . inc_select('fy', fp_fy_options(), $fy, '', 'data-autosubmit') . '</form>', [], $flash);

echo '<div class="inc-grid2 wide-left"><section class="card"><h2>Month-end checklist — ' . e(date('F Y', strtotime($month . '-01'))) . '</h2>'
   . '<form method="get" class="inc-inline"><input type="hidden" name="p" value="close"><input type="hidden" name="fy" value="' . $fy . '"><select name="month" data-autosubmit>';
foreach ($periods as $p) {
    $ym = substr($p['start_date'], 0, 7);
    echo '<option value="' . e($ym) . '"' . ($ym === $month ? ' selected' : '') . '>' . e($p['name']) . '</option>';
}
echo '</select></form>';
$controls = fp_all('SELECT * FROM fp_controls WHERE active = 1 ORDER BY sort, id');
echo '<table class="data inc-table stack fp-check"><thead><tr><th>Check</th><th>System</th><th>Signed off</th><th></th></tr></thead><tbody>';
foreach ($controls as $c) {
    $period = fp_control_period($c['frequency'], $month . '-01');
    $run = fp_one('SELECT * FROM fp_control_runs WHERE control_id = ? AND period = ?', 'is', [(int) $c['id'], $period]);
    $auto = $month . '-01' <= date('Y-m-d') ? fp_control_auto($c['check_key'], $month) : null;
    echo '<tr><td data-l="Check"><strong>' . e($c['name']) . '</strong><div class="muted small">' . e($c['area']) . ' · ' . e($c['frequency']) . ($c['owner_role'] ? ' · ' . e(fp_roles()[$c['owner_role']] ?? $c['owner_role']) : '') . '</div></td>'
       . '<td data-l="System">' . ($auto ? inc_badge($auto[0] ? 'OK' : 'Check', $auto[0] ? 'green' : 'amber') . ' <span class="small">' . e($auto[1]) . '</span>' : '<span class="muted small">—</span>') . '</td>'
       . '<td data-l="Signed off">' . ($run ? inc_badge(['done' => 'Done', 'exception' => 'Exception', 'na' => 'Not applicable'][$run['status']], $run['status'] === 'exception' ? 'red' : 'green')
         . '<div class="muted small">' . e($run['actor'] . ' · ' . fp_fmt_dt($run['created_at'])) . '</div>' . ($run['note'] ? '<div class="small">' . e($run['note']) . '</div>' : '')
         . (($files = fp_files('control', (int) $run['id'])) ? '<div class="small">' . implode(', ', array_map(static fn($f) => '<a href="' . e(fp_url('file', ['id' => $f['id']])) . '">' . e($f['original_name']) . '</a>', $files)) . '</div>' : '') : '<span class="muted small">Not yet</span>') . '</td><td>';
    if (fp_can('controls.manage') && fp_writable()) {
        echo '<details><summary class="btn btn-sm">Record</summary>' . inc_form_open('', true) . '<input type="hidden" name="action" value="control"><input type="hidden" name="control_id" value="' . (int) $c['id'] . '">'
           . '<input type="hidden" name="period" value="' . e($period) . '">' . inc_select('status', ['done' => 'Done', 'exception' => 'Exception found', 'na' => 'Not applicable'], $run['status'] ?? 'done')
           . '<textarea name="note" rows="2" placeholder="Note (required for an exception)"></textarea><input type="file" name="files[]" multiple aria-label="Evidence"><button class="btn btn-sm btn-primary">Save</button></form></details>';
    }
    echo '</td></tr>';
}
echo '</tbody></table></section>';

echo '<section class="card"><h2>Periods</h2><table class="data inc-table"><thead><tr><th>Month</th><th>Status</th><th></th></tr></thead><tbody>';
foreach ($periods as $p) {
    echo '<tr><td><a href="' . e(fp_url('close', ['fy' => $fy, 'month' => substr($p['start_date'], 0, 7)])) . '">' . e($p['name']) . '</a></td><td>'
       . inc_badge(ucfirst($p['status']), ['open' => 'green', 'closed' => 'blue', 'locked' => ''][$p['status']]) . ($p['closed_by'] ? '<div class="muted small">' . e($p['closed_by']) . '</div>' : '') . '</td><td>';
    if (fp_can('gl.close') && fp_writable() && $p['status'] !== 'locked') {
        echo '<details><summary class="btn btn-sm">' . ($p['status'] === 'open' ? 'Close' : 'Reopen') . '</summary>' . inc_form_open() . '<input type="hidden" name="action" value="period"><input type="hidden" name="id" value="' . (int) $p['id'] . '">'
           . '<input type="hidden" name="status" value="' . ($p['status'] === 'open' ? 'closed' : 'open') . '"><input name="note" maxlength="300" placeholder="' . ($p['status'] === 'open' ? 'Note (needed if journals are unposted)' : 'Why it is reopened (required)') . '">'
           . '<button class="btn btn-sm">' . ($p['status'] === 'open' ? 'Close month' : 'Reopen month') . '</button></form></details>';
    }
    echo '</td></tr>';
}
echo '</tbody></table>';
$closed = fp_one('SELECT id FROM fp_journals WHERE type = "closing" AND jdate = ? AND status = "posted"', 's', [fp_fy_range($fy)[1]]);
if ($closed) {
    echo '<p class="muted small">FY ' . e(fp_fy_label($fy)) . ' is closed: profit moved to retained earnings (<a href="' . e(fp_url('journal', ['id' => $closed['id']])) . '">closing journal</a>) and the year locked.</p>';
} elseif (fp_can('gl.close') && fp_writable()) {
    echo '<h3 class="fp-h3">Year-end close</h3><p class="muted small">When every month is closed and the accounts are final, this posts the year\'s profit or loss to retained earnings and locks the year. It cannot be undone.</p>'
       . inc_form_open('data-confirm="Close FY ' . e(fp_fy_label($fy)) . ' for good? The year will be locked."') . '<input type="hidden" name="action" value="year_close"><input type="hidden" name="fy" value="' . $fy . '">'
       . '<button class="btn btn-danger">Close FY ' . e(fp_fy_label($fy)) . '</button></form>';
}
echo '</section></div>';
fp_layout_end();

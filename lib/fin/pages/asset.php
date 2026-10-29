<?php
/** One fixed asset: its figures, depreciation to come, history; transfer and disposal. */

$id = (int) ($_GET['id'] ?? 0);
$a = fp_asset($id);
if (!$a) {
    fabo_deny('That asset does not exist.', 404);
}
$back = fp_url('asset', ['id' => $id]);
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string) ($_POST['action'] ?? '');
    if ($x = fp_files_post('asset', $id, $back, fp_can('assets.manage'))) {
        $flash = $x;
    } elseif ($act === 'transfer') {
        $flash = fp_action(static function () use ($id) {
            fp_asset_transfer($id, $_POST);
            return 'Transfer recorded.';
        }, $back);
    } elseif ($act === 'dispose') {
        $flash = fp_action(static fn() => fp_asset_dispose($id, $_POST), $back);
    }
}
$a = fp_asset($id);
$cat = fp_asset_category((int) $a['category_id']);
fp_layout_start($a['tag'] . ' · ' . $a['name'], e($cat['name']), '', ['Fixed assets' => fp_url('assets'), $a['tag'] => null], $flash);
$book = fp_r2((float) $a['cost'] - (float) $a['accumulated']);
echo '<div class="inc-kpis">' . fp_kpi('Cost', fp_money($a['cost'], false)) . fp_kpi('Depreciated', fp_money($a['accumulated'], false)) . fp_kpi('Book value', fp_money($book, false), '', 'Salvage ' . fp_money($a['salvage'], false)) . '</div>';
echo '<div class="inc-grid2"><section class="card">' . fp_facts([
    'Status' => inc_badge(ucfirst(str_replace('_', ' ', $a['status'])), $a['status'] === 'active' ? 'green' : ''), 'Acquired' => e(fp_fmt_date($a['acquired_on'])), 'In service from' => e(fp_fmt_date($a['in_service_on'])),
    'Method' => $a['method'] === 'wdv' ? 'Written-down value at ' . e((string) (float) $a['wdv_rate']) . '% a year' : 'Straight line over ' . (int) $a['life_months'] . ' months',
    'Cost centre' => e(fp_one('SELECT CONCAT(code, " · ", name) n FROM fp_cost_centres WHERE id = ?', 'i', [(int) $a['cost_centre_id']])['n'] ?? ''), 'Location' => e($a['location'] ?? ''),
    'Custodian' => e($a['custodian'] ?? ''), 'Serial' => e($a['serial'] ?? ''), 'From bill' => $a['bill_id'] ? '<a href="' . e(fp_url('bill', ['id' => $a['bill_id']])) . '">' . e(fp_bill((int) $a['bill_id'])['number'] ?? '') . '</a>' : '',
    'Acquisition entry' => $a['journal_id'] ? '<a href="' . e(fp_url('journal', ['id' => $a['journal_id']])) . '">View</a>' : '',
    'Disposed' => $a['disposed_on'] ? e(fp_fmt_date($a['disposed_on'])) . ' for ' . e(fp_money($a['disposal_proceeds'])) . ' (<a href="' . e(fp_url('journal', ['id' => $a['disposal_journal_id']])) . '">entry</a>)' : '',
    'Brought in from' => $a['source_ref'] ? e(str_starts_with($a['source_ref'], 'it:') ? 'IT register' : 'Facilities register') : '', 'Notes' => e($a['notes'] ?? ''),
]) . '</section><section class="card"><h2>Depreciation ahead</h2>';
$sch = $a['status'] === 'active' ? fp_asset_schedule($a, 12) : [];
echo $sch ? '<table class="data inc-table"><thead><tr><th>Month</th><th class="num">Charge</th><th class="num">Book value after</th></tr></thead><tbody>' . implode('', array_map(static fn($s) => '<tr><td>' . e(date('M Y', strtotime($s['month'] . '-01'))) . '</td>' . fp_money_td($s['amount']) . fp_money_td($s['book']) . '</tr>', $sch)) . '</tbody></table><p class="muted small">Management estimate; posted only by a depreciation run.</p>'
     : '<p class="muted small">Nothing more to depreciate.</p>';
echo '</section></div>';
if ($a['status'] === 'active' && fp_can('assets.manage') && fp_writable()) {
    echo '<div class="inc-grid2"><section class="card"><h2>Transfer</h2>' . inc_form_open() . '<input type="hidden" name="action" value="transfer"><input type="hidden" name="version" value="' . (int) $a['version'] . '"><div class="inc-row">'
       . inc_field('Cost centre', inc_select('cost_centre_id', fp_cc_options(), $a['cost_centre_id'] ?? '', '—')) . inc_field('Location', '<input name="location" maxlength="120" value="' . e($a['location'] ?? '') . '">')
       . inc_field('Custodian', '<input name="custodian" maxlength="160" value="' . e($a['custodian'] ?? '') . '">') . '</div><button class="btn">Save transfer</button></form></section>'
       . '<section class="card"><h2>Dispose</h2>' . inc_form_open('data-confirm="Take this asset off the books?"') . '<input type="hidden" name="action" value="dispose"><div class="inc-row">'
       . inc_field('Disposed on', '<input type="date" name="disposed_on" value="' . e(date('Y-m-d')) . '" required>') . inc_field('Sold for', '<input name="proceeds" inputmode="decimal" placeholder="0 to write off">')
       . inc_field('Proceeds into', inc_select('bank_account_id', fp_bank_options(), '', '—')) . '</div>' . inc_field('Reason', '<input name="reason" maxlength="500" required>', '', true)
       . '<button class="btn btn-danger">Dispose</button></form></section></div>';
}
$ev = fp_all('SELECT * FROM fp_asset_events WHERE asset_id = ? ORDER BY id DESC', 'i', [$id]);
if ($ev) {
    echo '<section class="card"><h2>History</h2><ol class="inc-timeline">' . implode('', array_map(static fn($x) => '<li><span class="k">' . e($x['kind']) . '</span><div>' . e($x['summary']) . '<div class="muted small">' . e($x['actor'] . ' · ' . fp_fmt_dt($x['created_at'])) . '</div></div></li>', $ev)) . '</ol></section>';
}
echo fp_files_panel('asset', $a, fp_can('assets.manage'), $back);
fp_layout_end();

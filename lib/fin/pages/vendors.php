<?php
/** Vendors: the vendor master, with what is owed to each. */

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import') {
    $flash = fp_action(static function () {
        [$made, $seen] = fp_vendor_import_desks();
        return $made ? $made . ' vendor(s) brought in from the IT and Facilities desks as drafts. Add their PAN/GSTIN and bank details, then submit each for verification.'
                     : ($seen ? 'Every vendor on the IT and Facilities desks is already here.' : 'No vendors found on the IT or Facilities desks.');
    }, fp_url('vendors'));
}
$q = array_intersect_key($_GET, array_flip(['q', 'status']));
$where = ' WHERE 1=1';
$t = '';
$p = [];
if (isset(fp_vendor_statuses()[$q['status'] ?? ''])) {
    $where .= ' AND v.status = ?';
    $t .= 's';
    $p[] = $q['status'];
}
if (trim((string) ($q['q'] ?? '')) !== '') {
    $where .= ' AND (v.name LIKE ? OR v.code LIKE ? OR v.gstin LIKE ? OR v.pan LIKE ? OR v.category LIKE ?)';
    $like = '%' . trim((string) $q['q']) . '%';
    $t .= 'sssss';
    array_push($p, $like, $like, $like, $like, $like);
}
$rows = fp_all('SELECT v.*, (SELECT COALESCE(SUM((b.total - b.paid_amount) * b.fx_rate),0) FROM fp_bills b WHERE b.vendor_id = v.id AND b.kind = "bill" AND b.status IN ("approved","partially_paid")) AS open_amt,
                       (SELECT COUNT(*) FROM fp_bills b WHERE b.vendor_id = v.id AND b.journal_id IS NOT NULL) AS bills
                  FROM fp_vendors v' . $where . ' ORDER BY v.status = "active" DESC, v.name LIMIT 500', $t, $p);
$acts = fp_can('vendor.manage') ? inc_button('Bring in from IT / Facilities', ['action' => 'import'], 'btn') . ' <a class="btn btn-primary" href="' . e(fp_url('vendor', ['new' => 1])) . '">+ Add vendor</a>' : '';
fp_layout_start('Vendors', count($rows) . ' shown · a vendor is verified before it can be ordered from or paid', $acts, [], $flash);
echo '<section class="card fp-filtercard">' . fp_filters('vendors', ['q', 'status'], $q, fp_vendor_statuses()) . '</section>';
if (!$rows) {
    echo inc_empty('No vendors yet.', 'Add the companies and people you buy from.');
} else {
    echo '<div class="scrollx"><table class="data inc-table stack"><thead><tr><th>Vendor</th><th>Category</th><th>GSTIN / PAN</th><th>Terms</th><th class="num">Bills</th><th class="num">Owed now</th><th>Status</th></tr></thead><tbody>';
    foreach ($rows as $v) {
        echo '<tr><td data-l="Vendor"><a href="' . e(fp_url('vendor', ['id' => $v['id']])) . '"><strong>' . e($v['name']) . '</strong></a> <span class="muted small">' . e($v['code']) . '</span>'
           . ($v['pending_json'] ? ' ' . inc_badge('Change awaiting approval', 'amber') : '') . ($v['msme'] ? ' ' . inc_badge('MSME', 'blue') : '') . '</td>'
           . '<td data-l="Category">' . e($v['category'] ?? '') . '</td><td data-l="GSTIN / PAN" class="small">' . e(trim(($v['gstin'] ?? '') . ' ' . ($v['pan'] ?? ''))) . '</td>'
           . '<td data-l="Terms">' . (int) $v['payment_terms_days'] . ' days</td><td class="num" data-l="Bills">' . (int) $v['bills'] . '</td>' . fp_money_td((float) $v['open_amt'] ?: null, 'Owed now')
           . '<td data-l="Status">' . fp_badge(fp_vendor_statuses(), $v['status']) . '</td></tr>';
    }
    echo '</tbody></table></div>';
}
fp_layout_end();

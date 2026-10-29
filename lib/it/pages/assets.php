<?php
/** IT Management — the device inventory: filters, totals, and adding a device. */

$f = ['q' => trim((string) ($_GET['q'] ?? '')), 'type' => (string) ($_GET['type'] ?? ''), 'status' => (string) ($_GET['status'] ?? ''),
      'location' => (string) ($_GET['location'] ?? ''), 'warranty' => !empty($_GET['warranty']) ? '1' : ''];
$fq = array_filter($f, static fn($v) => $v !== '');
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $flash = inc_action(static function () {
        $id = it_asset_save(null, $_POST);
        inc_redirect(it_url('asset', ['id' => $id]), ['ok', it_w('Device added as ') . it_asset($id)['tag'] . '. Assign it below when it goes to someone.']);
    }, it_url('assets', $fq));
}
$rows = it_assets($f);
$count = static fn($s) => count(array_filter($rows, static fn($a) => $a['status'] === $s));
$acts = '<a class="btn btn-primary" href="' . e(it_url('assets', ['new' => 1] + $fq)) . it_w('">+ Add device</a>');
it_layout_start('Assets', count($rows) . it_w(' device') . (count($rows) === 1 ? '' : 's') . ($fq ? ' match your filters' : '') . ' · ' . $count('assigned') . ' in use · '
                . $count('in_stock') . ' in stock · ' . $count('repair') . ' in repair', $acts, $flash);

if (!empty($_GET['new']) || $flash) {
    $p = $_POST;
    echo it_w('<section class="card"><h2>Add a device</h2>') . inc_form_open() . '<input type="hidden" name="action" value="create">'
       . '<div class="inc-row">' . inc_field('Name', '<input type="text" name="name" maxlength="160" required value="' . e($p['name'] ?? '') . '" placeholder="e.g. Dell Latitude 5440">', '', true, 'grow')
       . inc_field('Asset tag', '<input type="text" name="tag" maxlength="40" value="' . e($p['tag'] ?? '') . '" placeholder="Leave blank to number it">')
       . inc_field('Type', inc_select('type', it_list('asset_type'), $p['type'] ?? '', 'Choose…')) . '</div>'
       . '<div class="inc-row">' . inc_field('Brand', '<input type="text" name="brand" maxlength="80" value="' . e($p['brand'] ?? '') . '">')
       . inc_field('Model', '<input type="text" name="model" maxlength="120" value="' . e($p['model'] ?? '') . '">')
       . inc_field('Serial number', '<input type="text" name="serial" maxlength="120" value="' . e($p['serial'] ?? '') . '">')
       . inc_field('Location', inc_select('location', it_list('location'), $p['location'] ?? '', 'Choose…')) . '</div>'
       . '<div class="inc-row">' . inc_field('Purchase date', '<input type="date" name="purchase_date" value="' . e($p['purchase_date'] ?? '') . '">')
       . inc_field('Cost (₹)', '<input type="number" name="cost" min="0" step="0.01" value="' . e($p['cost'] ?? '') . '">')
       . inc_field('Vendor', inc_select('vendor_id', it_vendors(true), $p['vendor_id'] ?? '', 'None'))
       . inc_field('Warranty ends', '<input type="date" name="warranty_end" value="' . e($p['warranty_end'] ?? '') . '">') . '</div>'
       . inc_field('Notes', inc_textarea('notes', (string) ($p['notes'] ?? ''), 2))
       . it_w('<button class="btn btn-primary">Add device</button> <a class="btn" href="') . e(it_url('assets', $fq)) . '">Cancel</a></form></section>';
}

echo it_filter_bar('assets', $f, ['type' => ['Type', it_list('asset_type', false)], 'status' => ['Status', it_asset_statuses()],
                                  'location' => ['Location', it_list('location', false)], 'warranty' => ['Warranty', ['1' => 'Ending soon or ended']]]);

if (!$rows) {
    echo inc_empty($fq ? it_w('No devices match.') : it_w('No devices yet.'), $fq ? 'Try clearing some filters.' : it_w('Add the first one with + Add device.'));
} else {
    $soon = strtotime('+' . (int) it_setting('remind_days') . ' days');
    echo it_w('<div class="scrollx"><table class="data stack inc-table"><thead><tr><th>Device</th><th>Type</th><th>Status</th><th>With</th><th>Location</th><th>Warranty</th><th class="num">Cost</th><th class="num">Open tickets</th></tr></thead><tbody>');
    foreach ($rows as $a) {
        $w = $a['warranty_end'] ? strtotime($a['warranty_end']) : null;
        echo it_w('<tr><td data-l="Device"><a data-popup href="') . e(it_url('asset', ['id' => $a['id']])) . '"><strong>' . e($a['name']) . '</strong></a><div class="muted small">' . e($a['tag'])
           . ($a['serial'] ? ' · SN ' . e($a['serial']) : '') . '</div></td><td data-l="Type">' . e($a['type'] ?: '—') . '</td><td data-l="Status">' . it_asset_badge($a['status']) . '</td>'
           . '<td data-l="With">' . e($a['assigned_name'] ?: '—') . '</td><td data-l="Location">' . e($a['location'] ?: '—') . '</td>'
           . '<td data-l="Warranty" class="nowrap' . ($w && $w < $soon && !in_array($a['status'], ['retired', 'lost'], true) ? ' tone-risk' : '') . '">' . ($w ? e(inc_fmt_date($a['warranty_end'])) : '—') . '</td>'
           . '<td data-l="Cost" class="num">' . e(it_money($a['cost'])) . it_w('</td><td data-l="Open tickets" class="num">') . ((int) $a['open_tickets'] ?: '—') . '</td></tr>';
    }
    echo '</tbody></table></div>';
}

it_layout_end();

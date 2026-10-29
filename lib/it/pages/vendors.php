<?php
/** IT Management — vendors: who supplies and services what, and what each one is linked to. */

$editId = (int) ($_GET['edit'] ?? 0);
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $flash = inc_action(static function () {
        $id = it_vendor_save(inc_int_or_null($_POST['id'] ?? null), $_POST);
        inc_redirect(it_url('vendors'), ['ok', 'Vendor saved.']);
    }, it_url('vendors'));
}
$rows = it_all('SELECT v.*, (SELECT COUNT(*) FROM it_assets a WHERE a.vendor_id = v.id) AS assets,
                         (SELECT COUNT(*) FROM it_renewals r WHERE r.vendor_id = v.id AND r.status = "active") AS renewals
                  FROM it_vendors v ORDER BY v.active DESC, v.name');
it_layout_start('Vendors', count($rows) . ' vendor' . (count($rows) === 1 ? '' : 's') . ' · suppliers, service partners and AMC providers',
                '<a class="btn btn-primary" href="' . e(it_url('vendors', ['edit' => 'new'])) . '">+ Add vendor</a>', $flash);

if ($editId || ($_GET['edit'] ?? '') === 'new' || $flash) {
    $v = $editId ? (it_one('SELECT * FROM it_vendors WHERE id = ?', 'i', [$editId]) ?? []) : [];
    $v = $flash ? $_POST + $v : $v;
    echo '<section class="card"><h2>' . ($editId ? 'Edit vendor' : 'Add a vendor') . '</h2>' . inc_form_open() . '<input type="hidden" name="action" value="save">'
       . ($editId ? '<input type="hidden" name="id" value="' . $editId . '">' : '')
       . '<div class="inc-row">' . inc_field('Name', '<input type="text" name="name" maxlength="160" required value="' . e($v['name'] ?? '') . '">', '', true, 'grow')
       . inc_field('Contact person', '<input type="text" name="contact" maxlength="160" value="' . e((string) ($v['contact'] ?? '')) . '">') . '</div>'
       . '<div class="inc-row">' . inc_field('Email', '<input type="email" name="email" maxlength="190" value="' . e((string) ($v['email'] ?? '')) . '">')
       . inc_field('Phone', '<input type="text" name="phone" maxlength="40" value="' . e((string) ($v['phone'] ?? '')) . '">')
       . inc_field('Services', '<input type="text" name="services" maxlength="255" value="' . e((string) ($v['services'] ?? '')) . '" placeholder="e.g. laptops, AMC, networking">', '', false, 'grow') . '</div>'
       . inc_field('Notes', inc_textarea('notes', (string) ($v['notes'] ?? ''), 2))
       . ($editId ? '<label class="check"><input type="checkbox" name="active" value="1"' . (!isset($v['active']) || (int) $v['active'] === 1 ? ' checked' : '') . '> Active</label><br>' : '')
       . '<button class="btn btn-primary">Save</button> <a class="btn" href="' . e(it_url('vendors')) . '">Cancel</a></form></section>';
}

if (!$rows) {
    echo inc_empty('No vendors yet.', it_w('Add the companies you buy devices, licences and services from; link them on devices and renewals.'));
} else {
    echo it_w('<div class="scrollx"><table class="data stack inc-table"><thead><tr><th>Vendor</th><th>Contact</th><th>Services</th><th class="num">Devices</th><th class="num">Active renewals</th></tr></thead><tbody>');
    foreach ($rows as $v) {
        echo '<tr' . ((int) $v['active'] !== 1 ? ' class="muted"' : '') . '><td data-l="Vendor"><a href="' . e(it_url('vendors', ['edit' => $v['id']])) . '"><strong>' . e($v['name']) . '</strong></a>'
           . ((int) $v['active'] !== 1 ? ' ' . inc_badge('Inactive') : '') . '</td>'
           . '<td data-l="Contact">' . e((string) $v['contact']) . '<div class="muted small">' . ($v['email'] ? '<a href="mailto:' . e($v['email']) . '">' . e($v['email']) . '</a> ' : '') . e((string) $v['phone']) . '</div></td>'
           . '<td data-l="Services">' . e((string) $v['services'] ?: '—') . '</td>'
           . it_w('<td data-l="Devices" class="num">') . (int) $v['assets'] . '</td>'
           . it_w('<td data-l="Active renewals" class="num">') . (int) $v['renewals'] . '</td></tr>';
    }
    echo '</tbody></table></div>';
}

it_layout_end();

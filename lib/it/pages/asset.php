<?php
/** IT Management — one device: what it is, who has it, its next step, its tickets and its history. */

$id = (int) ($_GET['id'] ?? 0);
$a = it_asset($id);
if (!$a) {
    fabo_deny(it_w('That device does not exist.'), 404);
}
$self = it_url('asset', ['id' => $id]);
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string) ($_POST['action'] ?? '');
    $flash = inc_action(static function () use ($act, $id) {
        if ($act === 'save') {
            it_asset_save($id, $_POST);
            return it_w('Device saved.');
        }
        if ($act === 'ticket') {
            $a = it_asset($id);
            $t = it_ticket_create(['title' => (string) ($_POST['title'] ?? ''), 'description' => (string) ($_POST['description'] ?? ''), 'priority' => (string) ($_POST['priority'] ?? 'medium'),
                                   'category' => 'Hardware', 'asset_id' => $id, 'requester_name' => $a['assigned_name'] ?: it_w('IT desk'),
                                   'requester_email' => $a['assigned_person_id'] ? (it_staff()[(int) $a['assigned_person_id']]['email'] ?? '') : ''], 'desk');
            return it_w('Ticket ') . $t['ref'] . it_w(' opened for this device.');
        }
        return it_asset_action($id, $act, $_POST);
    }, $self);
    $a = it_asset($id);
}

it_layout_start($a['name'], e($a['tag']) . ' · ' . it_asset_badge($a['status']) . ($a['assigned_name'] ? ' · with <strong>' . e($a['assigned_name']) . '</strong>' : '') . ($a['location'] ? ' · ' . e($a['location']) : ''),
                '', $flash, ['Assets' => it_url('assets'), $a['tag'] => null]);

echo '<div class="inc-grid2 wide-left"><div>';
// The next step for this device, in the words of what happens.
echo '<section class="card"><h2>Next step</h2>';
if (in_array($a['status'], ['in_stock', 'repair'], true) || $a['status'] === 'assigned') {
    echo '<details class="it-step"' . ($a['status'] === 'in_stock' ? ' open' : '') . '><summary><strong>' . ($a['status'] === 'assigned' ? 'Hand it to someone else' : 'Assign it') . '</strong></summary>'
       . inc_form_open() . '<input type="hidden" name="action" value="assign"><div class="inc-row">'
       . inc_field('Team member', inc_select('person_id', array_map(static fn($p) => $p['name'], it_staff()), $a['assigned_person_id'], 'Choose…'))
       . inc_field('…or another person / startup', '<input type="text" name="person_name" maxlength="160" placeholder="Name">')
       . inc_field('Location', inc_select('location', it_list('location'), $a['location'], 'Keep as is')) . '</div>'
       . inc_field('Note', '<input type="text" name="note" maxlength="500" placeholder="e.g. charger and bag included">')
       . '<button class="btn btn-primary">Assign</button></form></details>';
}
echo '<div class="inc-row" style="margin-top:10px">';
if ($a['status'] === 'assigned' || $a['status'] === 'repair') {
    echo inc_button('Return to stock', ['action' => 'return'], 'btn', it_w('Return this device to stock?'));
}
if (in_array($a['status'], ['assigned', 'in_stock'], true)) {
    echo inc_button('Send for repair', ['action' => 'repair'], 'btn');
}
if (!in_array($a['status'], ['retired', 'lost'], true)) {
    echo inc_button('Retire', ['action' => 'retire'], 'btn btn-reject', it_w('Retire this device? It leaves the active inventory.'))
       . inc_button('Mark lost', ['action' => 'lost'], 'btn btn-reject', it_w('Mark this device as lost?'));
} else {
    echo inc_button('Bring back to stock', ['action' => 'return'], 'btn');
}
echo '</div></section>';

$tickets = it_all('SELECT * FROM it_tickets WHERE asset_id = ? ORDER BY created_at DESC', 'i', [$id]);
echo it_w('<section class="card"><div class="inc-cardhead"><h2>Tickets for this device</h2></div>');
if (!$tickets) {
    echo '<p class="muted small">None yet.</p>';
} else {
    echo '<ul class="inc-todo">';
    foreach ($tickets as $t) {
        echo '<li><a href="' . e(it_url('ticket', ['id' => $t['id']])) . '"><strong>' . e($t['ref']) . '</strong> ' . e($t['title']) . ' ' . it_status_badge($t['status']) . '<span aria-hidden="true">→</span></a></li>';
    }
    echo '</ul>';
}
echo it_w('<details><summary class="small">Report a problem with this device</summary>') . inc_form_open() . it_w('<input type="hidden" name="action" value="ticket">')
   . inc_field('What is wrong', '<input type="text" name="title" maxlength="200" required>') . inc_field('Details', inc_textarea('description', '', 3))
   . inc_field('Priority', inc_select('priority', it_priorities(), 'medium')) . it_w('<button class="btn btn-sm btn-primary">Open ticket</button></form></details></section>');

echo '<section class="card"><h2>History</h2>';
$hist = it_all("SELECT * FROM it_activity WHERE entity = 'asset' AND entity_id = ? ORDER BY created_at DESC, id DESC", 'i', [$id]);
echo $hist ? '<ol class="inc-timeline">' . implode('', array_map(static fn($h) => '<li><span class="k">' . e(date('d M Y', strtotime($h['created_at']))) . '</span><div>' . e($h['summary'])
                                                              . '<div class="muted small">' . e($h['actor']) . ' · ' . e(inc_fmt_datetime($h['created_at'])) . '</div></div></li>', $hist)) . '</ol>'
           : '<p class="muted small">No history yet.</p>';
echo '</section></div><div>';

echo '<section class="card"><h2>Details</h2>' . inc_form_open() . '<input type="hidden" name="action" value="save">'
   . inc_field('Name', '<input type="text" name="name" maxlength="160" required value="' . e($a['name']) . '">')
   . inc_field('Asset tag', '<input type="text" name="tag" maxlength="40" value="' . e($a['tag']) . '">')
   . inc_field('Type', inc_select('type', it_list('asset_type', false) + ($a['type'] ? [$a['type'] => $a['type']] : []), $a['type'], 'Choose…'))
   . inc_field('Brand', '<input type="text" name="brand" maxlength="80" value="' . e((string) $a['brand']) . '">')
   . inc_field('Model', '<input type="text" name="model" maxlength="120" value="' . e((string) $a['model']) . '">')
   . inc_field('Serial number', '<input type="text" name="serial" maxlength="120" value="' . e((string) $a['serial']) . '">')
   . inc_field('Location', inc_select('location', it_list('location', false) + ($a['location'] ? [$a['location'] => $a['location']] : []), $a['location'], 'Choose…'))
   . inc_field('Purchase date', '<input type="date" name="purchase_date" value="' . e((string) $a['purchase_date']) . '">')
   . inc_field('Cost (₹)', '<input type="number" name="cost" min="0" step="0.01" value="' . e((string) $a['cost']) . '">')
   . inc_field('Vendor', inc_select('vendor_id', it_vendors(), $a['vendor_id'], 'None'))
   . inc_field('Warranty ends', '<input type="date" name="warranty_end" value="' . e((string) $a['warranty_end']) . '">')
   . inc_field('Notes', inc_textarea('notes', (string) $a['notes'], 3))
   . '<button class="btn btn-primary">Save details</button></form></section>';
echo '</div></div>';

it_layout_end();

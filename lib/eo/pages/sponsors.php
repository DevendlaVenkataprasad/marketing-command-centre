<?php
/** Event Organizers — the organisation's sponsors. Each event picks its own, with a tier, on its Sponsors tab. */

eo_need_read();
$self = inc_url('sponsors');
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $flash = inc_action(static function () {
        if (($_POST['action'] ?? '') === 'delete') {
            eo_sponsor_delete((int) ($_POST['row'] ?? 0));
            return 'Removed.';
        }
        eo_sponsor_save($_POST);
        return 'Sponsor added.';
    }, $self);
}
inc_layout_start('Sponsors', 'Everyone who can sponsor an event', [], '', $flash);
echo '<div class="inc-grid2 wide-left"><section class="card"><h2>Sponsors</h2>';
$rows = eo_sponsors();
if (!$rows) {
    echo inc_empty('No sponsors yet.', 'Add them here, then give each event its own.');
} else {
    echo '<table class="data"><thead><tr><th>Name</th><th>Website</th><th></th></tr></thead><tbody>';
    foreach ($rows as $s) {
        echo '<tr><td data-l="Name">' . e($s['name']) . '<div class="muted small">' . e((string) $s['description']) . '</div></td><td data-l="Website">' . e((string) $s['website']) . '</td><td>'
           . (eo_may_work() ? inc_form_open('class="inc-inline"') . '<input type="hidden" name="action" value="delete"><input type="hidden" name="row" value="' . (int) $s['id'] . '"><button class="btn btn-sm">Remove</button></form>' : '') . '</td></tr>';
    }
    echo '</tbody></table>';
}
echo '</section>';
if (eo_may_work()) {
    echo '<section class="card"><h2>Add a sponsor</h2>' . inc_form_open() . '<input type="hidden" name="action" value="add">' . inc_field('Name', '<input type="text" name="name" maxlength="190" required>', '', true)
       . inc_field('Website', '<input type="url" name="website" maxlength="500">') . inc_field('About', '<input type="text" name="description" maxlength="500">') . '<button class="btn btn-primary">Add sponsor</button></form></section>';
}
echo '</div>';
inc_layout_end();

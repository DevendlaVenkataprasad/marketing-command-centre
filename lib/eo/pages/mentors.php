<?php
/** Event Organizers — the organisation's mentors. They are assigned to events on each event's Mentors tab. */

eo_need_read();
$self = inc_url('mentors');
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $flash = inc_action(static function () {
        if (($_POST['action'] ?? '') === 'toggle') {
            eo_mentor_toggle((int) ($_POST['row'] ?? 0));
            return 'Done.';
        }
        eo_mentor_save($_POST);
        return 'Mentor added.';
    }, $self);
}
inc_layout_start('Mentors', 'Everyone who can be assigned to an event', [], '', $flash);
echo '<div class="inc-grid2 wide-left"><section class="card"><h2>Mentors</h2>';
$rows = eo_mentors();
if (!$rows) {
    echo inc_empty('No mentors yet.', 'Add them here, then assign them to events.');
} else {
    echo '<table class="data"><thead><tr><th>Name</th><th>Expertise</th><th>Contact</th><th>State</th><th></th></tr></thead><tbody>';
    foreach ($rows as $m) {
        echo '<tr><td data-l="Name">' . e($m['name']) . '<div class="muted small">' . e((string) $m['organization']) . '</div></td><td data-l="Expertise">' . e((string) $m['expertise']) . '</td><td data-l="Contact">' . e((string) $m['email']) . '<div class="muted small">' . e((string) $m['phone']) . '</div></td>'
           . '<td data-l="State">' . ((int) $m['active'] === 1 ? 'Active' : 'Off') . '</td><td>' . (eo_may_work() ? inc_form_open('class="inc-inline"') . '<input type="hidden" name="action" value="toggle"><input type="hidden" name="row" value="' . (int) $m['id'] . '"><button class="btn btn-sm">' . ((int) $m['active'] === 1 ? 'Turn off' : 'Turn on') . '</button></form>' : '') . '</td></tr>';
    }
    echo '</tbody></table>';
}
echo '</section>';
if (eo_may_work()) {
    echo '<section class="card"><h2>Add a mentor</h2>' . inc_form_open() . '<input type="hidden" name="action" value="add">' . inc_field('Name', '<input type="text" name="name" maxlength="190" required>', '', true)
       . inc_field('Email', '<input type="email" name="email" maxlength="190">') . inc_field('Phone', '<input type="tel" name="phone" maxlength="40">') . inc_field('Expertise', '<input type="text" name="expertise" maxlength="255">') . inc_field('Organisation', '<input type="text" name="organization" maxlength="190">')
       . '<button class="btn btn-primary">Add mentor</button></form></section>';
}
echo '</div>';
inc_layout_end();

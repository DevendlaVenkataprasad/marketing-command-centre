<?php
/**
 * Event Organizers — the Organizers team: portal people who work events. It is a
 * department of the main pipeline, so its members also get the Organizers desk.
 * They need no other access; Owner and View only are granted on My Team or Team
 * Management. People with no account get a link on the event's Staff links tab.
 */

eo_need_read();
$team = eo_team_id();
$self = inc_url('team');
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $flash = inc_action(static function () use ($team) {
        inc_need('configure');
        $person = (int) ($_POST['person'] ?? 0);
        if (($_POST['action'] ?? '') === 'remove') {
            inc_team_member_remove($team, $person, 'member');
            return 'Removed from the team.';
        }
        inc_team_member_add($team, $person, 'member');
        return 'Added to the team.';
    }, $self);
}
$members = inc_team_members($team);
$ids = array_map(static fn($m) => (int) ($m['person_id'] ?? $m['id']), $members);
$people = inc_all('SELECT c.id, c.name, c.email FROM clients c WHERE ' . inc_people_cond('c') . ' AND c.revoked_at IS NULL ORDER BY c.name');
inc_layout_start('Organizers team', number_format(count($ids)) . ' member' . (count($ids) === 1 ? '' : 's'), [], '', $flash);
echo '<div class="inc-grid2 wide-left"><section class="card"><h2>Members</h2><p class="muted small">Members work events, tickets, check-in and the Organizers desk. Creating forms and Settings need Owner, given on My Team or Team Management.</p>';
if (!$ids) {
    echo inc_empty('Nobody yet.', 'Add people from your team.');
} else {
    echo '<table class="data"><thead><tr><th>Name</th><th></th></tr></thead><tbody>';
    foreach (array_unique($ids) as $pid) {
        echo '<tr><td data-l="Name">' . e(inc_person_name($pid)) . '</td><td>' . (inc_can('configure') ? inc_form_open('class="inc-inline"') . '<input type="hidden" name="action" value="remove"><input type="hidden" name="person" value="' . $pid . '"><button class="btn btn-sm">Remove</button></form>' : '') . '</td></tr>';
    }
    echo '</tbody></table>';
}
echo '</section>';
if (inc_can('configure')) {
    $free = array_filter($people, static fn($p) => !in_array((int) $p['id'], $ids, true));
    echo '<section class="card"><h2>Add someone</h2>' . ($free ? inc_form_open() . '<input type="hidden" name="action" value="add"><select name="person" aria-label="Person">' . inc_options(array_column($free, 'name', 'id'), '') . '</select> <button class="btn btn-primary">Add</button></form>'
                                                               : '<p class="muted small">Everyone on your team is already here. New people are added on My Team or Team Management.</p>') . '</section>';
}
echo '</div>';
inc_layout_end();

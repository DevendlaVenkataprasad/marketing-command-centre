<?php
/**
 * Event Organizers — plans. The administrator writes them and assigns them to
 * organisations; an organisation's Owner sees theirs. With no plan assigned,
 * nothing is limited.
 */

$admin = !empty(inc_ctx()['is_admin']);
$self = inc_url('plans');
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $flash = inc_action(static function () use ($admin) {
        $a = (string) ($_POST['action'] ?? '');
        if ($a === 'request') {
            $p = eo_plans()[array_search((int) ($_POST['plan'] ?? 0), array_map('intval', array_column(eo_plans(), 'id')), true)] ?? null;
            require_once __DIR__ . '/../../notify.php';
            fabo_notify_admin('Plan request', inc_actor() . ' asked for the ' . ($p['name'] ?? 'plan') . ' plan for Event Organizers.');
            return 'Your request has been sent.';
        }
        if (!$admin) {
            fabo_deny('Plans are set by the administrator.', 403);
        }
        if ($a === 'plan') {
            eo_plan_save(inc_int_or_null($_POST['id'] ?? null), $_POST);
            return 'Plan saved.';
        }
        if ($a === 'assign') {
            eo_plan_assign((int) ($_POST['org'] ?? 0), (int) ($_POST['plan'] ?? 0), $_POST);
            return 'Saved.';
        }
        throw new InvalidArgumentException('Unknown action.');
    }, $self);
}
$plans = eo_plans();
$mine = eo_plan_of();
inc_layout_start('Plans', $admin ? 'What each organisation may do here. No plan means no limits.' : '', [], '', $flash);
if (!$admin) {
    echo '<section class="card"><h2>Your plan</h2><p>' . ($mine ? e($mine['name']) . ' · ' . e((string) $mine['sub_status']) . ($mine['period_end'] ? ' · until ' . e(inc_fmt_date($mine['period_end'])) : '') : 'No plan: nothing is limited.') . '</p></section>';
}
echo '<section class="card"><h2>Plans</h2>';
if (!$plans) {
    echo '<p class="muted small">None yet.</p>';
} else {
    echo '<table class="data"><thead><tr><th>Plan</th><th>Price</th><th>Attendees per event</th><th>Includes</th><th></th></tr></thead><tbody>';
    foreach ($plans as $p) {
        $has = array_filter(['Mentors' => (int) $p['mentors'], 'AI reports' => (int) $p['ai_reports'], 'Excel' => (int) $p['excel']]);
        echo '<tr><td data-l="Plan">' . e($p['name']) . '<div class="muted small">' . e((string) $p['description']) . '</div></td><td data-l="Price">' . e(eo_money((float) $p['price'], (string) $p['currency'])) . ' ' . e($p['billing']) . '</td>'
           . '<td data-l="Attendees">' . ($p['max_attendees'] !== null ? number_format((int) $p['max_attendees']) : 'No limit') . '</td><td data-l="Includes">' . e(implode(', ', array_keys($has))) . '</td><td>'
           . (!$admin ? inc_form_open('class="inc-inline"') . '<input type="hidden" name="action" value="request"><input type="hidden" name="plan" value="' . (int) $p['id'] . '"><button class="btn btn-sm">Request</button></form>' : '') . '</td></tr>';
    }
    echo '</tbody></table>';
}
echo '</section>';
if ($admin) {
    $orgs = array_column(fabo_all('SELECT id, COALESCE(NULLIF(organisation, ""), name) AS n FROM clients WHERE kind = "client" AND revoked_at IS NULL ORDER BY n'), 'n', 'id');     // a client's own clients run their own events too
    echo '<div class="inc-grid2"><section class="card"><h2>New plan</h2>' . inc_form_open() . '<input type="hidden" name="action" value="plan">' . inc_field('Name', '<input type="text" name="name" maxlength="120" required>', '', true) . inc_field('Description', '<input type="text" name="description" maxlength="255">')
       . '<div class="inc-row">' . inc_field('Price', '<input type="number" step="0.01" name="price" value="0">') . inc_field('Billing', '<select name="billing">' . inc_options(['monthly' => 'Monthly', 'yearly' => 'Yearly', 'custom' => 'Custom'], 'monthly') . '</select>') . '</div>'
       . '<div class="inc-row">' . inc_field('Most attendees per event', '<input type="number" name="max_attendees" min="1">') . '</div>'
       . '<label class="check"><input type="checkbox" name="mentors" value="1" checked> Mentors</label> <label class="check"><input type="checkbox" name="ai_reports" value="1" checked> AI reports</label> <label class="check"><input type="checkbox" name="excel" value="1" checked> Excel export</label>'
       . '<p><button class="btn btn-primary">Save plan</button></p></form></section>';
    echo '<section class="card"><h2>Assign a plan</h2>' . inc_form_open() . '<input type="hidden" name="action" value="assign">' . inc_field('Organisation', '<select name="org">' . inc_options($orgs, '') . '</select>')
       . inc_field('Plan', '<select name="plan"><option value="0">No plan: no limits</option>' . inc_options(array_column($plans, 'name', 'id'), '') . '</select>') . '<div class="inc-row">' . inc_field('Until', '<input type="date" name="period_end">') . inc_field('Own attendee limit', '<input type="number" name="custom_max_attendees" min="1">') . '</div>'
       . '<button class="btn btn-primary">Save</button></form></section></div>';
}
inc_layout_end();

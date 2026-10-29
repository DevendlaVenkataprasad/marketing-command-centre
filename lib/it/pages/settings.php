<?php
/** IT Management — settings: the IT team, response times, the request link, emails and the lists. */

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    $flash = inc_action(static function () use ($a) {
        switch ($a) {
            case 'general':
                $ids = array_intersect(array_map('intval', (array) ($_POST['team_ids'] ?? [])), array_keys(it_staff()));
                it_setting_set('team_ids', implode(',', $ids));
                foreach (['urgent', 'high', 'medium', 'low'] as $p) {
                    it_setting_set('sla_' . $p, (string) max(1, min(720, (int) ($_POST['sla_' . $p] ?? 24))));
                }
                it_setting_set('auto_close_days', (string) max(1, min(60, (int) ($_POST['auto_close_days'] ?? 3))));
                it_setting_set('remind_days', (string) max(1, min(180, (int) ($_POST['remind_days'] ?? 30))));
                it_setting_set('notify_requester', !empty($_POST['notify_requester']) ? '1' : '0');
                it_setting_set('notify_team', !empty($_POST['notify_team']) ? '1' : '0');
                it_log('settings', null, 'Settings saved.');
                return 'Settings saved.';
            case 'link':
                it_setting_set('request_enabled', !empty($_POST['request_enabled']) ? '1' : '0');
                if (!empty($_POST['new_link'])) {
                    it_setting_set('request_token', bin2hex(random_bytes(16)));
                    return 'A new request link was made; the old one no longer works.';
                }
                return !empty($_POST['request_enabled']) ? 'The request link is on.' : 'The request link is off.';
            case 'list_add':
                $kind = (string) ($_POST['kind'] ?? '');
                if (!isset(it_list_kinds()[$kind])) {
                    throw new InvalidArgumentException('Unknown list.');
                }
                $name = inc_str($_POST['name'] ?? '', 120, true, 'Name');
                $max = (int) (it_one('SELECT MAX(sort) m FROM it_lists WHERE kind = ?', 's', [$kind])['m'] ?? 0);
                it_exec('INSERT INTO it_lists (kind, name, sort) VALUES (?,?,?) ON DUPLICATE KEY UPDATE active = 1', 'ssi', [$kind, $name, $max + 1]);
                return 'Added "' . $name . '".';
            case 'list_toggle':
                it_exec('UPDATE it_lists SET active = 1 - active WHERE id = ?', 'i', [(int) ($_POST['id'] ?? 0)]);
                return 'List updated.';
        }
        throw new InvalidArgumentException('Unknown action.');
    }, it_url('settings'));
}

it_layout_start(it_w('IT settings'), it_w('Who works the tickets, how fast, and the lists the desk uses.'), '', $flash);

$team = array_filter(array_map('intval', explode(',', it_setting('team_ids'))));
echo '<div class="inc-grid2"><div>';
echo '<section class="card"><h2>Team and response times</h2>' . inc_form_open() . '<input type="hidden" name="action" value="general">'
   . it_w('<fieldset class="inc-fs"><legend>IT team — who tickets can be assigned to and who is emailed about new ones</legend>');
foreach (it_staff() as $sid => $p) {
    echo '<label class="check"><input type="checkbox" name="team_ids[]" value="' . $sid . '"' . (in_array($sid, $team, true) ? ' checked' : '') . '> ' . e($p['name'])
       . ($p['email'] === '' ? ' <span class="muted small">(no email)</span>' : '') . '</label>';
}
echo it_w('<p class="muted small">None ticked: every team member can be assigned, and new-ticket emails go to ' . (it_org() > 0 ? 'the account owner' : 'the admin inbox') . '.</p></fieldset>')
   . '<fieldset class="inc-fs"><legend>Target time to resolve, in hours</legend><div class="inc-row">';
foreach (it_priorities() as $k => $label) {
    echo inc_field($label, '<input type="number" name="sla_' . $k . '" min="1" max="720" value="' . e(it_setting('sla_' . $k)) . '" class="inc-w">');
}
echo '</div></fieldset><div class="inc-row">'
   . inc_field(it_w('Close resolved tickets after (days)'), '<input type="number" name="auto_close_days" min="1" max="60" value="' . e(it_setting('auto_close_days')) . '" class="inc-w">', 'If the requester does not reply.')
   . inc_field(it_w('Flag renewals and warranties (days ahead)'), '<input type="number" name="remind_days" min="1" max="180" value="' . e(it_setting('remind_days')) . '" class="inc-w">') . '</div>'
   . '<label class="check"><input type="checkbox" name="notify_requester" value="1"' . (it_setting('notify_requester') === '1' ? ' checked' : '') . '> Email requesters (reference, replies, resolved)</label><br>'
   . '<label class="check"><input type="checkbox" name="notify_team" value="1"' . (it_setting('notify_team') === '1' ? ' checked' : '') . it_w('> Email the IT team (new tickets, assignments, requester replies, daily reminders)</label><br>')
   . '<button class="btn btn-primary">Save</button></form></section>';

$on = it_setting('request_enabled') === '1';
echo it_w('<section class="card"><h2>Request link for staff</h2><p class="muted small">A private page where anyone you share it with can raise an IT request. They get a reference and a link to follow and reply to it.</p>')
   . inc_form_open() . '<input type="hidden" name="action" value="link">'
   . '<label class="check"><input type="checkbox" name="request_enabled" value="1"' . ($on ? ' checked' : '') . '> The request link is on</label><br>'
   . ($on ? '<input type="text" readonly class="inc-copy" value="' . e(it_request_link()) . '" onclick="this.select()" style="margin:8px 0">' : '')
   . '<button class="btn btn-primary">Save</button> <button class="btn" name="new_link" value="1" data-confirm="Make a new link? The current one will stop working.">Make a new link</button></form></section>';
echo '</div><div>';

foreach (it_list_kinds() as $kind => $label) {
    echo '<section class="card"><h2>' . e($label) . '</h2><div class="it-listrows">';
    foreach (it_all('SELECT * FROM it_lists WHERE kind = ? ORDER BY active DESC, sort, name', 's', [$kind]) as $r) {
        echo '<div class="it-listrow' . ((int) $r['active'] !== 1 ? ' off' : '') . '"><span>' . e($r['name']) . '</span>'
           . inc_button((int) $r['active'] === 1 ? 'Hide' : 'Show', ['action' => 'list_toggle', 'id' => $r['id']], 'linkbtn') . '</div>';
    }
    echo '</div>' . inc_form_open('class="inc-row"') . '<input type="hidden" name="action" value="list_add"><input type="hidden" name="kind" value="' . e($kind) . '">'
       . '<input type="text" name="name" maxlength="120" required placeholder="Add…" class="grow"> <button class="btn btn-sm">Add</button></form></section>';
}
echo '</div></div>';

it_layout_end();

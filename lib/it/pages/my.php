<?php
/**
 * The helpdesk, for the person who needs help: their requests (open ones
 * first, each saying what happens next), and — with ?new=1 — the three-step
 * form to raise one. Their requests are the ones they raised here, or that
 * carry their email address. See it_self_org() in lib/it/core.php.
 */

require_once __DIR__ . '/../helpdesk.php';

$me = it_me();
$person = $GLOBALS['it_viewer']['client'] ?? null;
if ($me <= 0 || !$person) {
    // The administrator works the desk itself.
    header('Location: ' . it_url('tickets'));
    exit;
}
$email = trim((string) ($person['email'] ?? ''));
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'raise') {
    $flash = inc_action(static function () use ($person, $me) {
        if (trim((string) ($_POST['category'] ?? '')) === '') {
            throw new InvalidArgumentException('Choose what the request is about (step 1).');
        }
        $in = ['title' => $_POST['title'] ?? '', 'description' => $_POST['description'] ?? '', 'category' => $_POST['category'] ?? '',
               'priority' => $_POST['priority'] ?? 'medium', 'asset_id' => $_POST['asset_id'] ?? '',
               'requester_name' => (string) $person['name'], 'requester_email' => (string) ($person['email'] ?? ''), '_requester_id' => $me];
        if (trim((string) $in['description']) === '') {
            throw new InvalidArgumentException('Describe the problem (step 2) so the ' . it_cfg('team') . ' knows where to start.');
        }
        $t = it_ticket_create($in, 'portal');
        inc_redirect(it_url('myticket', ['id' => $t['id']]), ['ok', 'Request ' . $t['ref'] . ' sent. The ' . it_cfg('team') . ' has been told; you will see every reply here.']);
    }, it_url('my', ['new' => 1]));
}

/* ---------------------------------------------------------- the new form */
if (!empty($_GET['new']) || $flash) {
    $p = $flash ? $_POST : [];
    it_layout_start(it_cfg('request_title'), '', '', $flash);
    echo '<a class="hd-back" href="' . e(it_url('my')) . '">&larr; Back to my requests</a>'
       . '<div class="hd-top"><div><h1>' . e(it_cfg('request_title')) . '</h1><p>Three quick steps. The ' . e(it_cfg('team')) . ' gets it straight away.</p></div></div>';
    echo inc_form_open('class="hd-form"') . '<input type="hidden" name="action" value="raise">';

    // 1. What is it about?
    echo '<div class="hd-step"><h2><span>1</span>What is it about?</h2><div class="hd-choices">';
    foreach (it_list('ticket_category') as $c) {
        echo '<label class="hd-choice"><input type="radio" name="category" value="' . e($c) . '" required' . (($p['category'] ?? '') === $c ? ' checked' : '') . '><span>' . e($c) . '</span></label>';
    }
    echo '</div></div>';

    // 2. Describe it.
    $devices = array_column(it_all("SELECT id, CONCAT(tag, ' — ', name) AS l FROM it_assets WHERE status NOT IN ('retired','lost') ORDER BY tag"), 'l', 'id');
    echo '<div class="hd-step"><h2><span>2</span>Describe the problem</h2>'
       . '<div class="hd-field"><label for="hd-title">In a few words</label><input id="hd-title" type="text" name="title" maxlength="200" required value="' . e($p['title'] ?? '') . '" placeholder="' . e(it_cfg('request_hint')) . '"></div>'
       . '<div class="hd-field"><label for="hd-desc">What is happening?</label><textarea id="hd-desc" name="description" maxlength="8000" required placeholder="What you see, since when, where, and anything you have already tried.">' . e($p['description'] ?? '') . '</textarea></div>'
       . ($devices ? '<div class="hd-field"><label for="hd-dev">' . e(it_w('Which device?')) . ' <span class="hd-card-meta">(optional)</span></label>'
                   . '<select id="hd-dev" name="asset_id"><option value="">' . e(it_w('Not about a particular device')) . '</option>' . inc_options($devices, $p['asset_id'] ?? '') . '</select></div>' : '')
       . '</div>';

    // 3. How urgent?
    echo '<div class="hd-step"><h2><span>3</span>How urgent is it?</h2><div class="hd-choices urg">';
    foreach (it_urgency_plain() as $k => [$label, $mean]) {
        echo '<label class="hd-choice"><input type="radio" name="priority" value="' . e($k) . '"' . (($p['priority'] ?? 'medium') === $k ? ' checked' : '') . '><span><strong>' . e($label) . '</strong><small>' . e($mean) . '</small></span></label>';
    }
    echo '</div></div>';

    echo '<div class="hd-actions"><button class="btn btn-primary hd-btn-big">Send request</button><a class="btn" href="' . e(it_url('my')) . '">Cancel</a>'
       . '<span class="hd-note">Sent as ' . e((string) $person['name']) . ($email !== '' ? ' · replies also go to ' . e($email) : '') . '</span></div></form>';
    it_layout_end();
    return;
}

/* -------------------------------------------------------- my requests */
$rows = it_all('SELECT t.*, a.tag AS asset_tag FROM it_tickets t LEFT JOIN it_assets a ON a.id = t.asset_id
                 WHERE t.requester_id = ?' . ($email !== '' ? ' OR LOWER(t.requester_email) = LOWER(?)' : '') . '
                 ORDER BY FIELD(t.status, \'waiting\', \'resolved\', \'new\', \'in_progress\', \'closed\'), t.updated_at DESC LIMIT 300',
               $email !== '' ? 'is' : 'i', $email !== '' ? [$me, $email] : [$me]);
$active = array_values(array_filter($rows, static fn($t) => $t['status'] !== 'closed'));
$closed = array_values(array_filter($rows, static fn($t) => $t['status'] === 'closed'));
$tab = ($_GET['tab'] ?? '') === 'closed' ? 'closed' : 'active';
$needYou = count(array_filter($active, static fn($t) => in_array($t['status'], ['waiting', 'resolved'], true)));

it_layout_start(it_cfg('short') . ' Helpdesk', '', '', $flash);
echo '<div class="hd-top"><div><h1>' . e(it_cfg('short')) . ' Helpdesk</h1><p>'
   . ($needYou ? '<strong style="color:var(--red)">' . $needYou . ' request' . ($needYou === 1 ? ' needs' : 's need') . ' your answer.</strong> '
               : 'Need help? Raise a request and follow it here until it is fixed.')
   . '</p></div><div style="display:flex;gap:8px;flex-wrap:wrap">'
   . (!empty($GLOBALS['it_agent']) ? '<a class="btn hd-btn-big" href="' . e(it_url('queue')) . '">My queue</a>' : '')
   . '<a class="btn btn-primary hd-btn-big" href="' . e(it_url('my', ['new' => 1])) . '">+ New request</a></div></div>';
echo it_hd_tabs('my', ['active' => ['Open', count($active)], 'closed' => ['Closed', count($closed)]], $tab);

$list = $tab === 'closed' ? $closed : $active;
if (!$list) {
    echo $tab === 'closed'
        ? '<div class="hd-empty"><strong>No closed requests yet.</strong>Requests move here once they are fixed and closed.</div>'
        : '<div class="hd-empty"><strong>You have no open requests.</strong>Something not working? Tell the ' . e(it_cfg('team')) . ' and they will pick it up.'
          . '<br><a class="btn btn-primary hd-btn-big" href="' . e(it_url('my', ['new' => 1])) . '">+ New request</a></div>';
} else {
    echo '<div class="hd-list">';
    foreach ($list as $t) {
        $side = $t['status'] === 'waiting' ? '<a class="btn btn-sm btn-primary" href="' . e(it_url('myticket', ['id' => $t['id']])) . '">Reply</a>'
              : ($t['status'] === 'resolved' ? '<a class="btn btn-sm btn-primary" href="' . e(it_url('myticket', ['id' => $t['id']])) . '">Confirm</a>' : '');
        echo it_hd_card($t, it_url('myticket', ['id' => $t['id']]), false, $side);
    }
    echo '</div>';
}
it_layout_end();

<?php
/**
 * The helpdesk — the team's queue: requests assigned to the person, the
 * unassigned ones to take (for the team chosen in Settings, and the desk's
 * owners), and what they have finished lately. Each opens on the work page.
 * Works without the full desk (see it_agent_may()).
 */

require_once __DIR__ . '/../helpdesk.php';

$me = it_me();
$team = array_filter(array_map('intval', explode(',', it_setting('team_ids'))));
$onTeam = $me > 0 && in_array($me, $team, true);
$deskEdit = ($GLOBALS['it_desk_grant'] ?? 'none') === 'edit';
if ($me <= 0) {
    header('Location: ' . it_url('tickets'));
    exit;
}
$canTake = $onTeam || $deskEdit;
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'take') {
    $id = (int) ($_POST['id'] ?? 0);
    $flash = inc_action(static function () use ($id, $me, $canTake) {
        if (!$canTake) {
            throw new InvalidArgumentException('Only the ' . it_cfg('team') . ' can take requests.');
        }
        it_hd_take($id, $me);
        inc_redirect(it_url('work', ['id' => $id]), ['ok', 'It is yours now. Reply to the requester to let them know you are on it.']);
    }, it_url('queue', ['tab' => 'free']));
}

$open = "t.status NOT IN ('resolved','closed')";
$mine = it_all("SELECT t.* FROM it_tickets t WHERE t.assignee_id = ? AND $open
                 ORDER BY t.status = 'waiting', FIELD(t.priority,'urgent','high','medium','low'), t.due_at IS NULL, t.due_at", 'i', [$me]);
$free = $canTake ? it_all("SELECT t.* FROM it_tickets t WHERE t.assignee_id IS NULL AND $open
                            ORDER BY FIELD(t.priority,'urgent','high','medium','low'), t.created_at") : [];
$done = it_all("SELECT t.* FROM it_tickets t WHERE t.assignee_id = ? AND t.status IN ('resolved','closed') AND t.updated_at > NOW() - INTERVAL 30 DAY
                 ORDER BY t.updated_at DESC LIMIT 100", 'i', [$me]);
$tabs = ['mine' => ['Assigned to me', count($mine)]] + ($canTake ? ['free' => ['Waiting to be taken', count($free)]] : []) + ['done' => ['Done (30 days)', count($done)]];
$tab = (string) ($_GET['tab'] ?? '');
if (!isset($tabs[$tab])) {
    $tab = !$mine && $free ? 'free' : 'mine';
}
$late = count(array_filter($mine, 'it_is_overdue'));

it_layout_start('My queue', '', '', $flash);
echo '<div class="hd-top"><div><h1>My queue</h1><p>'
   . ($late ? '<strong style="color:var(--red)">' . $late . ' of your requests ' . ($late === 1 ? 'is' : 'are') . ' overdue.</strong> ' : '')
   . 'Requests for you to solve. Open one to reply, ask the requester something, or mark it resolved.</p></div>'
   . '<a class="btn hd-btn-big" href="' . e(it_url('my')) . '">My own requests</a></div>';
echo it_hd_tabs('queue', $tabs, $tab);

$list = ['mine' => $mine, 'free' => $free, 'done' => $done][$tab];
if (!$list) {
    echo '<div class="hd-empty"><strong>' . e(['mine' => 'Nothing assigned to you.', 'free' => 'Every open request has someone on it.', 'done' => 'Nothing finished in the last 30 days.'][$tab]) . '</strong>'
       . ($tab === 'mine' && $canTake ? 'Take one from <a href="' . e(it_url('queue', ['tab' => 'free'])) . '">Waiting to be taken</a>.' : '') . '</div>';
} else {
    echo '<div class="hd-list">';
    foreach ($list as $t) {
        $side = $tab === 'free' ? inc_button('Take it', ['action' => 'take', 'id' => $t['id']], 'btn btn-sm btn-primary')
                                : '<a class="btn btn-sm" href="' . e(it_url('work', ['id' => $t['id']])) . '">Open</a>';
        echo it_hd_card($t, it_url('work', ['id' => $t['id']]), true, $side);
    }
    echo '</div>';
}
it_layout_end();

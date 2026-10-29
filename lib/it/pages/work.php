<?php
/**
 * The helpdesk — the team's view of one request. The conversation (with
 * internal notes), one message box with the three things the team does —
 * reply, ask the requester something, mark it resolved — and beside it who
 * has it and how urgent it is. Opened from the queue and from bell notices.
 *
 * Who: the desk's owners, anyone on the team chosen in Settings, and the
 * person it is assigned to (it_agent_may()). Desk viewers may read it.
 */

require_once __DIR__ . '/../helpdesk.php';

$id = (int) ($_GET['id'] ?? 0);
$t = it_ticket($id);
$me = it_me();
$deskGrant = (string) ($GLOBALS['it_desk_grant'] ?? 'none');
$canAct = $t && ($deskGrant === 'edit' || it_agent_may($t));
if (!$t || (!$canAct && $deskGrant === 'none')) {
    fabo_deny(it_w('That request is not one you work on.'), 404);
}
$team = array_filter(array_map('intval', explode(',', it_setting('team_ids'))));
$canTake = $me > 0 && !$t['assignee_id'] && ($deskGrant === 'edit' || in_array($me, $team, true));
$self = it_url('work', ['id' => $id]);
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    $flash = inc_action(static function () use ($a, $id, $me, $canAct, $canTake) {
        if ($a === 'take') {
            if (!$canTake) {
                throw new InvalidArgumentException('You cannot take this request.');
            }
            it_hd_take($id, $me);
            return 'It is yours now. Reply to let the requester know you are on it.';
        }
        if (!$canAct) {
            throw new InvalidArgumentException('You can read this request but not work on it.');
        }
        switch ($a) {
            case 'reply':
                $internal = ($_POST['mode'] ?? 'reply') === 'internal';
                $then = $internal ? '' : (string) ($_POST['then'] ?? '');
                $msg = it_ticket_reply($id, (string) ($_POST['body'] ?? ''), $internal, in_array($then, ['waiting', 'resolved'], true) ? $then : '');
                return $internal ? 'Internal note added — the requester does not see it.'
                     : ($then === 'resolved' ? 'Marked resolved. The requester has been asked to confirm.'
                     : ($then === 'waiting' ? 'Sent. The request waits for the requester’s answer.' : 'Reply sent to the requester.'));
            case 'reopen':
                return it_ticket_status($id, 'in_progress', false) . ' Reopened.';
            case 'details':
                $t = it_ticket($id);
                it_ticket_update($id, ['title' => $t['title'], 'priority' => $_POST['priority'] ?? $t['priority'], 'category' => $t['category'],
                                      'assignee_id' => $_POST['assignee_id'] ?? $t['assignee_id'], 'asset_id' => $t['asset_id']]);
                return 'Saved.';
        }
        throw new InvalidArgumentException('Unknown action.');
    }, $self);
    $t = it_ticket($id);
}

it_layout_start($t['title'], '', '', $flash);
echo $deskGrant !== 'none' ? '<a class="hd-back" href="' . e(it_url('tickets')) . '">&larr; ' . e(it_cfg('tickets')) . '</a>'
                            : '<a class="hd-back" href="' . e(it_url('queue')) . '">&larr; My queue</a>';
echo '<div class="hd-head"><h1>' . e($t['title']) . '</h1>' . it_hd_pill($t['status'], false) . it_hd_urgency((string) $t['priority']) . '</div>'
   . '<div class="hd-ref">' . e($t['ref']) . ' · from ' . e($t['requester_name']) . ($t['requester_email'] ? ' (' . e($t['requester_email']) . ')' : '') . '</div>';
echo it_hd_track($t);

// What now, for the team.
if (!$t['assignee_id']) {
    echo '<div class="hd-callout red"><p><strong>Nobody has taken this yet.</strong>Take it so the requester knows someone is on it.</p>'
       . ($canTake ? '<div class="hd-cbtns">' . inc_button('Take it', ['action' => 'take'], 'btn btn-primary') . '</div>' : '') . '</div>';
} elseif ($t['status'] === 'waiting') {
    echo '<div class="hd-callout grey"><p><strong>Waiting for ' . e($t['requester_name']) . '.</strong>It comes back to you as soon as they reply.</p></div>';
} elseif ($t['status'] === 'resolved') {
    echo '<div class="hd-callout green"><p><strong>Resolved — waiting for ' . e($t['requester_name']) . ' to confirm.</strong>It closes by itself in '
       . (int) it_setting('auto_close_days') . ' days if they do not answer.</p></div>';
} elseif ($t['status'] === 'closed') {
    echo '<div class="hd-callout grey"><p><strong>Closed.</strong>Reopen it if the problem is back.</p>'
       . ($canAct ? '<div class="hd-cbtns">' . inc_button('Reopen', ['action' => 'reopen'], 'btn') . '</div>' : '') . '</div>';
} else {
    echo '<div class="hd-callout"><p><strong>' . e(it_person_name((int) $t['assignee_id'])) . ' is working on it.</strong>'
       . 'Reply, ask the requester something, or mark it resolved when it is fixed.</p></div>';
}

echo '<div class="hd-grid"><div><section class="hd-panel"><h2>Conversation</h2>' . it_hd_chat($t, true);
if ($canAct && $t['status'] !== 'closed') {
    echo inc_form_open('class="hd-compose"') . '<input type="hidden" name="action" value="reply">'
       . '<div class="hd-mode" role="radiogroup" aria-label="Message type">'
       . '<label><input type="radio" name="mode" value="reply" checked onchange="this.form.classList.remove(\'is-note\')"><span>Message to ' . e(strtok((string) $t['requester_name'], ' ') ?: 'requester') . '</span></label>'
       . '<label><input type="radio" name="mode" value="internal" onchange="this.form.classList.add(\'is-note\')"><span>Internal note</span></label></div>'
       . '<textarea name="body" required maxlength="8000" placeholder="Write your message…"></textarea>'
       . '<div class="hd-actions"><button class="btn btn-primary" name="then" value="">Send</button>'
       . '<button class="btn hd-ask" name="then" value="waiting" title="Send, and wait for their answer">Send &amp; ask for a reply</button>'
       . '<button class="btn hd-res" name="then" value="resolved" style="border-color:var(--green);color:var(--green)" title="Send, and ask them to confirm it is fixed">Send &amp; mark resolved</button></div>'
       . '<p class="hd-card-meta" style="margin:6px 0 0">Messages are emailed to the requester and shown on their helpdesk. Internal notes are only for the ' . e(it_cfg('team')) . '.</p>'
       . '<style>.hd-compose.is-note .hd-ask,.hd-compose.is-note .hd-res{display:none}.hd-compose.is-note textarea{background:#fff8e6}</style></form>';
}
echo '</section></div><aside class="hd-side"><section class="hd-panel"><h2>Details</h2>' . it_hd_facts($t) . '</section>';
if ($canAct) {
    $people = it_team() + ($t['assignee_id'] ? [(int) $t['assignee_id'] => it_person_name((int) $t['assignee_id'])] : []);
    echo '<section class="hd-panel"><h2>Change</h2>' . inc_form_open() . '<input type="hidden" name="action" value="details">'
       . '<div class="hd-field"><label for="hd-as">Handled by</label><select id="hd-as" name="assignee_id">' . inc_options($people, $t['assignee_id'], 'Nobody yet') . '</select></div>'
       . '<div class="hd-field"><label for="hd-pr">Urgency</label><select id="hd-pr" name="priority">'
       . inc_options(array_map(static fn($u) => $u[0] . ' — ' . $u[1], it_urgency_plain()), $t['priority']) . '</select></div>'
       . '<button class="btn">Save</button></form>'
       . ($deskGrant === 'edit' ? '<p class="hd-card-meta" style="margin:10px 0 0"><a href="' . e(it_url('ticket', ['id' => $id])) . '">Open in the full desk view</a> for category, device and target time.</p>' : '')
       . '</section>';
}
echo '</aside></div>';

it_layout_end();

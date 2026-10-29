<?php
/**
 * The helpdesk — one of the person's own requests: where it is (the tracker),
 * what they need to do now, the conversation with the team (internal notes
 * are never shown), and a reply. Once the team marks it resolved: "Yes, it is
 * fixed" closes it, "No, still a problem" reopens it with a reason.
 */

require_once __DIR__ . '/../helpdesk.php';

$me = it_me();
$person = $GLOBALS['it_viewer']['client'] ?? null;
$id = (int) ($_GET['id'] ?? 0);
$t = it_ticket($id);
$email = trim((string) ($person['email'] ?? ''));
$mine = $t && $me > 0 && ((int) $t['requester_id'] === $me || ($email !== '' && strcasecmp((string) $t['requester_email'], $email) === 0));
if (!$mine) {
    fabo_deny(it_w('That request is not one of yours.'), 404);
}
$self = it_url('myticket', ['id' => $id]);
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    $flash = inc_action(static function () use ($a, $t, $id) {
        $name = (string) ($t['requester_name'] ?: 'Requester');
        switch ($a) {
            case 'reply':
                if ($t['status'] === 'closed') {
                    throw new InvalidArgumentException('This request is closed. Use "Reopen" if the problem is back.');
                }
                it_requester_reply($t, (string) ($_POST['body'] ?? ''));
                return 'Message sent to the ' . it_cfg('team') . '.';
            case 'fixed':
                if ($t['status'] !== 'resolved') {
                    throw new InvalidArgumentException('Only a request marked resolved can be confirmed.');
                }
                it_note($id, 'requester', 'Confirmed it is fixed. Thank you!', false, $name);
                it_ticket_status($id, 'closed', false);
                foreach (it_team_people($t) as $pid) {
                    it_bell($pid, $t['ref'] . ' confirmed fixed', $name . ' closed it.', 'work', $id);
                }
                return 'Thank you — the request is closed.';
            case 'reopen':
                if (!in_array($t['status'], ['resolved', 'closed'], true)) {
                    throw new InvalidArgumentException('This request is still open.');
                }
                $why = inc_str($_POST['body'] ?? '', 8000, true, 'What is still wrong');
                it_requester_reply($t, 'Still not fixed: ' . $why);    // back to the team, and they are told
                if ($t['status'] === 'closed') {
                    it_ticket_status($id, 'in_progress', false);
                }
                return 'Reopened. The ' . it_cfg('team') . ' has been told.';
        }
        throw new InvalidArgumentException('Unknown action.');
    }, $self);
    $t = it_ticket($id);
}

it_layout_start($t['title'], '', '', $flash);
echo '<a class="hd-back" href="' . e(it_url('my')) . '">&larr; My requests</a>';
echo '<div class="hd-head"><h1>' . e($t['title']) . '</h1>' . it_hd_pill($t['status']) . '</div><div class="hd-ref">' . e($t['ref']) . '</div>';
echo it_hd_track($t);

// What now: the one thing to do, said plainly.
if ($t['status'] === 'resolved') {
    echo '<div class="hd-callout green"><p><strong>The ' . e(it_cfg('team')) . ' says this is fixed.</strong>Is it working for you now? '
       . 'If you do not answer, it closes by itself in ' . (int) it_setting('auto_close_days') . ' days.</p><div class="hd-cbtns">'
       . inc_button('Yes, it is fixed', ['action' => 'fixed'], 'btn btn-primary')
       . '<a class="btn" href="#hd-reopen">No, still a problem</a></div></div>';
} elseif ($t['status'] === 'waiting') {
    echo '<div class="hd-callout red"><p><strong>The ' . e(it_cfg('team')) . ' needs your answer.</strong>Read their last message below and reply — the request waits until you do.</p>'
       . '<div class="hd-cbtns"><a class="btn btn-primary" href="#hd-reply">Reply now</a></div></div>';
} elseif ($t['status'] === 'closed') {
    echo '<div class="hd-callout grey"><p><strong>This request is closed.</strong>Problem back? Reopen it below and it goes straight to the ' . e(it_cfg('team')) . '.</p></div>';
} else {
    echo '<div class="hd-callout"><p><strong>' . e(it_next_plain($t)) . '</strong>You will get a notice and an email when they reply. Nothing for you to do right now.</p></div>';
}

echo '<div class="hd-grid"><div><section class="hd-panel"><h2>Conversation</h2>' . it_hd_chat($t, false);
if (in_array($t['status'], ['resolved', 'closed'], true)) {
    echo inc_form_open('class="hd-compose" id="hd-reopen"') . '<input type="hidden" name="action" value="reopen">'
       . '<label class="hd-field" style="margin:0"><span style="display:block;margin:0 0 6px;font-weight:650;font-size:13.5px">Still a problem? Tell them what is wrong</span>'
       . '<textarea name="body" required maxlength="8000" placeholder="e.g. It worked for a while, but it stopped again this morning."></textarea></label>'
       . '<div class="hd-actions"><button class="btn">Reopen request</button></div></form>';
} else {
    echo inc_form_open('class="hd-compose" id="hd-reply"') . '<input type="hidden" name="action" value="reply">'
       . '<textarea name="body" required maxlength="8000" placeholder="' . ($t['status'] === 'waiting' ? 'Answer the question above…' : 'Add details or a message for the ' . e(it_cfg('team')) . '…') . '"></textarea>'
       . '<div class="hd-actions"><button class="btn btn-primary">Send message</button><span class="hd-note">The ' . e(it_cfg('team')) . ' is told straight away.</span></div></form>';
}
echo '</section></div><aside><section class="hd-panel"><h2>Details</h2>' . it_hd_facts($t) . '</section></aside></div>';

it_layout_end();

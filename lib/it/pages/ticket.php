<?php
/**
 * IT Management — one ticket: the conversation with the requester (replies
 * are emailed; internal notes are not), its status, and its details.
 */

$id = (int) ($_GET['id'] ?? 0);
$t = it_ticket($id);
if (!$t) {
    fabo_deny(it_w('That ticket does not exist.'), 404);
}
$self = it_url('ticket', ['id' => $id]);
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    $flash = inc_action(static function () use ($a, $id) {
        switch ($a) {
            case 'reply':
                return it_ticket_reply($id, (string) ($_POST['body'] ?? ''), ($_POST['kind'] ?? '') === 'internal', (string) ($_POST['then'] ?? ''));
            case 'status':
                return it_ticket_status($id, (string) ($_POST['status'] ?? ''));
            case 'save':
                it_ticket_update($id, $_POST);
                return it_w('Ticket saved.');
        }
        throw new InvalidArgumentException('Unknown action.');
    }, $self);
    $t = it_ticket($id);
}

$sub = e($t['ref']) . ' · ' . it_status_badge($t['status']) . ' ' . it_priority_badge($t['priority']) . (it_is_overdue($t) ? ' ' . inc_badge('Overdue', 'red') : '')
     . ' · opened ' . e(inc_fmt_datetime($t['created_at'])) . ($t['channel'] === 'link' ? ' through the request link' : '');
// Someone working it from the helpdesk (no desk grant) goes back to their queue, not the desk's board.
$back = ($GLOBALS['it_desk_grant'] ?? 'edit') === 'none' ? ['My queue' => it_url('queue')] : ['Tickets' => it_url('tickets')];
it_layout_start($t['title'], $sub, '', $flash, $back + [$t['ref'] => null]);

// The next steps, as buttons: only the ones that make sense from here.
$next = ['new' => ['in_progress' => 'Start work', 'resolved' => 'Resolve'], 'in_progress' => ['waiting' => 'Waiting on requester', 'resolved' => 'Resolve'],
         'waiting' => ['in_progress' => 'Back to work', 'resolved' => 'Resolve'], 'resolved' => ['closed' => 'Close', 'in_progress' => 'Reopen'],
         'closed' => ['in_progress' => 'Reopen']][$t['status']] ?? [];
echo '<div class="inc-row" style="margin-bottom:14px">';
foreach ($next as $st => $label) {
    echo inc_button($label, ['action' => 'status', 'status' => $st], $st === 'resolved' || $st === 'closed' ? 'btn btn-primary' : 'btn',
                    $st === 'resolved' && $t['requester_email'] ? 'Mark resolved? The requester is emailed and can reply within ' . (int) it_setting('auto_close_days') . ' days to reopen it.' : '');
}
echo '</div>';

echo '<div class="inc-grid2 wide-left"><div>';
echo '<section class="card"><h2>Conversation</h2>';
if ($t['description']) {
    echo '<div class="it-msg it-msg-requester"><div class="it-msg-h"><strong>' . e($t['requester_name']) . '</strong> · ' . e(inc_fmt_datetime($t['created_at'])) . '</div>' . nl2br(e($t['description'])) . '</div>';
}
foreach (it_notes($id) as $n) {
    if ($n['kind'] === 'system') {
        echo '<div class="it-msg it-msg-system">' . e($n['body']) . ' <span class="muted">· ' . e(inc_fmt_datetime($n['created_at'])) . '</span></div>';
        continue;
    }
    $label = ['reply' => 'Reply to requester' . ($n['emailed'] ? ' · emailed' : ''), 'internal' => 'Internal note', 'requester' => 'Requester'][$n['kind']];
    echo '<div class="it-msg it-msg-' . e($n['kind']) . '"><div class="it-msg-h"><strong>' . e($n['actor']) . '</strong> · ' . e($label) . ' · ' . e(inc_fmt_datetime($n['created_at'])) . '</div>'
       . nl2br(e($n['body'])) . '</div>';
}
echo inc_form_open('class="it-reply"') . '<input type="hidden" name="action" value="reply">'
   . '<div class="inc-tabs" role="radiogroup"><label class="check"><input type="radio" name="kind" value="reply" checked> Reply to requester' . ($t['requester_email'] ? ' (emailed)' : ' (no email on record)') . '</label>'
   . '<label class="check"><input type="radio" name="kind" value="internal"> Internal note</label></div>'
   . inc_textarea('body', '', 4, 'required placeholder="Write a message…"')
   . '<div class="inc-row"><label class="small">and then ' . inc_select('then', ['waiting' => 'set Waiting on requester', 'resolved' => 'set Resolved'], '', 'keep the status') . '</label>'
   . '<button class="btn btn-primary">Send</button></div></form></section>';
echo '</div><div>';

echo '<section class="card"><h2>Details</h2>' . inc_form_open() . '<input type="hidden" name="action" value="save">'
   . inc_field('Subject', '<input type="text" name="title" maxlength="200" value="' . e($t['title']) . '" required>')
   . inc_field('Assigned to', inc_select('assignee_id', it_team() + ($t['assignee_id'] ? [(int) $t['assignee_id'] => it_person_name((int) $t['assignee_id'])] : []), $t['assignee_id'], 'Nobody yet'))
   . inc_field('Priority', inc_select('priority', it_priorities(), $t['priority']), 'Changing it resets the target time.')
   . inc_field('Category', inc_select('category', it_list('ticket_category', false), $t['category'], 'Choose…'))
   . inc_field(it_w('Device'), inc_select('asset_id', array_column(it_all("SELECT id, CONCAT(tag, ' — ', name) AS l FROM it_assets ORDER BY tag"), 'l', 'id'), $t['asset_id'], 'None'))
   . inc_field('Target time', '<input type="datetime-local" name="due_at" value="' . e($t['due_at'] ? date('Y-m-d\TH:i', strtotime((string) $t['due_at'])) : '') . '">')
   . '<button class="btn btn-primary">Save details</button></form></section>';

echo '<section class="card"><h2>Requester</h2><p><strong>' . e($t['requester_name']) . '</strong>' . ($t['requester_email'] ? '<br><a href="mailto:' . e($t['requester_email']) . '">' . e($t['requester_email']) . '</a>' : '') . '</p>'
   . '<p class="muted small">Their tracking link (they can follow and reply here):</p><input type="text" readonly class="inc-copy" value="' . e(it_ticket_link($t)) . '" onclick="this.select()">'
   . ($t['asset_id'] ? it_w('<p class="small" style="margin-top:10px">Device: <a href="') . e(it_url('asset', ['id' => $t['asset_id']])) . '" target="_top">' . e($t['asset_tag'] . ' — ' . $t['asset_name']) . '</a></p>' : '')
   . '</section>';

echo '<section class="card"><h2>Timing</h2><dl class="inc-quickfacts">'
   . '<div><dt>Target</dt><dd>' . ($t['due_at'] ? e(inc_fmt_datetime($t['due_at'])) : '—') . '</dd></div>'
   . '<div><dt>First reply</dt><dd>' . ($t['first_reply_at'] ? e(inc_fmt_datetime($t['first_reply_at'])) : '—') . '</dd></div>'
   . '<div><dt>Resolved</dt><dd>' . ($t['resolved_at'] ? e(inc_fmt_datetime($t['resolved_at'])) : '—') . '</dd></div>'
   . '<div><dt>Closed</dt><dd>' . ($t['closed_at'] ? e(inc_fmt_datetime($t['closed_at'])) : '—') . '</dd></div></dl></section>';
echo '</div></div>';

it_layout_end();

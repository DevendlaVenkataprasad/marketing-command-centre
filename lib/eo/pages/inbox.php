<?php
/** Event Organizers — what the public sends in: contact messages to answer, feedback to approve, and the people signed up. */

eo_need_read();
$self = inc_url('inbox');
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $flash = inc_action(static function () {
        eo_need_work();
        $a = (string) ($_POST['action'] ?? '');
        $row = (int) ($_POST['row'] ?? 0);
        if ($a === 'reply') {
            $m = inc_one('SELECT * FROM inc_ev_contact WHERE id = ?', 'i', [$row]);
            $text = trim((string) ($_POST['reply'] ?? ''));
            if (!$m || $text === '') {
                throw new InvalidArgumentException('Write a reply first.');
            }
            inc_exec('UPDATE inc_ev_contact SET reply = ?, replied_at = NOW(), replied_by = ?, is_read = 1 WHERE id = ?', 'ssi', [$text, inc_actor(), $row]);
            eo_mail((string) $m['email'], 'Re: ' . ($m['subject'] ?: 'your message'), (string) $m['name'], [$text]);
            return 'Reply sent.';
        }
        if ($a === 'approve' || $a === 'reject') {
            inc_exec('UPDATE inc_ev_feedback SET status = ?, moderated_by = ? WHERE ticket_id = ?', 'ssi', [$a === 'approve' ? 'approved' : 'rejected', inc_actor(), $row]);
            return 'Done.';
        }
        if ($a === 'user') {
            inc_exec('UPDATE inc_ev_users SET active = 1 - active WHERE id = ?', 'i', [$row]);
            return 'Done.';
        }
        throw new InvalidArgumentException('Unknown action.');
    }, $self);
}
$messages = inc_all('SELECT * FROM inc_ev_contact ORDER BY replied_at IS NOT NULL, id DESC LIMIT 100');
$reviews = inc_all('SELECT b.*, a.applicant_name, e.name AS event_name FROM inc_ev_feedback b JOIN inc_ev_tickets k ON k.id = b.ticket_id JOIN inc_applications a ON a.id = k.application_id JOIN inc_ev_events e ON e.id = k.event_id WHERE b.status = "pending" ORDER BY b.created_at DESC LIMIT 100');
$users = inc_all('SELECT id, name, email, phone, active, created_at FROM inc_ev_users ORDER BY id DESC LIMIT 200');
$log = inc_can('view') ? inc_all('SELECT * FROM inc_audit WHERE entity IN ("event","order") ORDER BY id DESC LIMIT 40') : [];
inc_layout_start('Messages & feedback', '', [], '', $flash);
$work = eo_may_work();
echo '<section class="card"><h2>Contact messages</h2>';
if (!$messages) {
    echo '<p class="muted small">None yet.</p>';
}
foreach ($messages as $m) {
    echo '<div class="eo-msg"><strong>' . e($m['subject'] ?: 'Message') . '</strong> <span class="muted small">' . e($m['name']) . ' · ' . e($m['email']) . ' · ' . e(inc_fmt_datetime($m['created_at'])) . '</span><p>' . nl2br(e($m['message'])) . '</p>';
    if ($m['reply']) {
        echo '<p class="muted small">Replied by ' . e((string) $m['replied_by']) . ': ' . nl2br(e($m['reply'])) . '</p>';
    } elseif ($work) {
        echo inc_form_open() . '<input type="hidden" name="action" value="reply"><input type="hidden" name="row" value="' . (int) $m['id'] . '"><textarea name="reply" rows="2" aria-label="Reply"></textarea><p><button class="btn btn-sm">Send reply</button></p></form>';
    }
    echo '</div>';
}
echo '</section><section class="card"><h2>Feedback waiting for review</h2><p class="muted small">Approved feedback shows on the event\'s public page.</p>';
if (!$reviews) {
    echo '<p class="muted small">Nothing waiting.</p>';
}
foreach ($reviews as $b) {
    echo '<div class="eo-msg"><strong>' . (int) $b['rating'] . ' / 5</strong> <span class="muted small">' . e($b['applicant_name']) . ' · ' . e($b['event_name']) . '</span><p>' . nl2br(e((string) $b['comment'])) . '</p>';
    if ($work) {
        foreach (['approve' => 'Approve', 'reject' => 'Reject'] as $k => $label) {
            echo inc_form_open('class="inc-inline"') . '<input type="hidden" name="action" value="' . $k . '"><input type="hidden" name="row" value="' . (int) $b['ticket_id'] . '"><button class="btn btn-sm">' . $label . '</button></form> ';
        }
    }
    echo '</div>';
}
echo '</section><section class="card"><h2>Attendee accounts</h2>';
if (!$users) {
    echo '<p class="muted small">None yet.</p>';
} else {
    echo '<table class="data"><thead><tr><th>Name</th><th>Email</th><th>Joined</th><th></th></tr></thead><tbody>';
    foreach ($users as $u) {
        echo '<tr><td data-l="Name">' . e($u['name']) . '</td><td data-l="Email">' . e($u['email']) . '</td><td data-l="Joined" class="nowrap">' . e(inc_fmt_date(substr((string) $u['created_at'], 0, 10))) . '</td><td>'
           . ($work ? inc_form_open('class="inc-inline"') . '<input type="hidden" name="action" value="user"><input type="hidden" name="row" value="' . (int) $u['id'] . '"><button class="btn btn-sm">' . ((int) $u['active'] === 1 ? 'Block' : 'Unblock') . '</button></form>' : '') . '</td></tr>';
    }
    echo '</tbody></table>';
}
echo '</section>';
if ($log) {
    echo '<section class="card"><h2>Audit log</h2><table class="data"><thead><tr><th>When</th><th>Who</th><th>What</th></tr></thead><tbody>';
    foreach ($log as $l) {
        echo '<tr><td data-l="When" class="nowrap">' . e(inc_fmt_datetime($l['created_at'] ?? $l['at'] ?? null)) . '</td><td data-l="Who">' . e($l['actor']) . '</td><td data-l="What">' . e($l['action'] . ' ' . $l['entity'] . ': ' . $l['summary']) . '</td></tr>';
    }
    echo '</tbody></table></section>';
}
inc_layout_end();

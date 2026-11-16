<?php
/**
 * Event Organizers — one event. Applications are worked on the main pipeline,
 * opened here already filtered to this event; everything else about the event is
 * a tab here. Each POST action is checked again by the function it calls.
 */

eo_need_read();
$id = (int) ($_GET['id'] ?? 0);
$ev = eo_event($id);
if (!$ev) {
    inc_redirect(inc_url('events'), ['err', 'That event no longer exists.']);
}
$tabs = ['overview' => 'Overview & forms', 'people' => 'Attendees', 'money' => 'Tickets & money', 'agenda' => 'Agenda & people', 'live' => 'Live', 'mentors' => 'Mentors',
         'sponsors' => 'Sponsors', 'onsite' => 'On the day', 'staff' => 'Staff links', 'insights' => 'Insights', 'feedback' => 'Feedback'];
$tab = isset($tabs[$_GET['v'] ?? '']) ? (string) $_GET['v'] : 'overview';
$self = inc_url('event', ['id' => $id, 'v' => $tab]);
$work = eo_may_work();
$open = (int) $ev['approved'] === 1 && empty($ev['cancelled_at']);
$flash = null;

if (($_GET['download'] ?? '') === 'xlsx' && $tab === 'people') {
    if (!eo_plan_has('excel')) {
        fabo_deny('Your plan does not include the Excel export.', 403);
    }
    $rows = [['Reference', 'Program', 'Type of participant', 'Name', 'Email', 'Phone', 'Stage', 'Registration', 'Ticket', 'Days checked in']];
    foreach (eo_attendees($id) as $a) {
        $rows[] = [$a['ref'], $ev['label'], $a['type_name'], $a['applicant_name'], $a['applicant_email'], $a['applicant_phone'], $a['stage_name'], $a['reg_status'], $a['ticket_status'] ?: 'none', (int) $a['days_in']];
    }
    inc_send_xlsx('attendees-' . $id . '-' . date('Ymd'), ['Attendees' => $rows]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    $row = (int) ($_POST['row'] ?? 0);
    $flash = inc_action(static function () use ($a, $id, $ev, $row) {
        switch ($a) {
            case 'save':
                eo_event_save($id, $_POST);
                if (!empty($_FILES['banner']['name'])) {
                    eo_banner_save($id, $_FILES['banner']);
                }
                return 'Event details saved.';
            case 'ai_write':
                eo_need_work();
                inc_exec('UPDATE inc_ev_events SET description = ? WHERE id = ?', 'si', [eo_ai_write($ev), $id]);
                return 'Draft written. Read it through before making the page public.';
            case 'publish':
                eo_event_publish($id, !empty($_POST['on']));
                return !empty($_POST['on']) ? 'The public page is live.' : 'The public page is closed.';
            case 'cancel_event':
                return 'Event cancelled. ' . eo_event_cancel($id, trim((string) ($_POST['reason'] ?? ''))) . ' people told.';
            case 'zoom':
                eo_zoom_create($id);
                return 'Zoom meeting created.';
            case 'form':
                $fid = eo_form_create($id, trim((string) ($_POST['name'] ?? '')));
                inc_redirect(inc_url('form', ['id' => $fid]), ['ok', 'Form created. Review the questions, publish it, then open its public link.']);
            case 'issue':
                $ids = array_map('intval', (array) ($_POST['app'] ?? []));
                if (!$ids) {
                    throw new InvalidArgumentException('Tick the people to issue tickets to.');
                }
                $r = eo_tickets_issue($id, $ids);
                return $r['issued'] . ' ticket' . ($r['issued'] === 1 ? '' : 's') . ' issued, ' . $r['mailed'] . ' emailed.' . ($r['full'] ? ' ' . $r['full'] . ' not issued: the event is full.' : '');
            case 'reject':
                eo_reg_reject($id, $row);
                return 'Registration rejected and the applicant told.';
            case 'cancel':
                eo_ticket_cancel($id, $row);
                return 'Ticket cancelled. Its QR code no longer gets anyone in.';
            case 'session':
                eo_session_add($id, $_POST);
                return 'Session added.';
            case 'session_del':
                eo_session_delete($id, $row);
                return 'Session removed.';
            case 'person':
                eo_person_add($id, $_POST);
                return 'Added.';
            case 'person_del':
                eo_person_delete($id, $row);
                return 'Removed.';
            case 'q_answer':
            case 'q_delete':
                eo_question_mark($id, $row, $a === 'q_delete' ? 'delete' : 'answer');
                return 'Done.';
            case 'poll':
                eo_poll_add($id, (int) ($_POST['session'] ?? 0), (string) ($_POST['question'] ?? ''), explode("\n", (string) ($_POST['options'] ?? '')));
                return 'Poll opened.';
            case 'poll_toggle':
                eo_poll_toggle($id, $row);
                return 'Done.';
            case 'coupon':
                eo_coupon_save($id, $_POST);
                return 'Coupon added.';
            case 'coupon_toggle':
                eo_coupon_toggle($id, $row);
                return 'Done.';
            case 'order_paid':
                eo_order_confirm($row);
                return 'Payment confirmed and the ticket sent.';
            case 'order_reject':
                eo_order_reject($row);
                return 'Payment turned down. The seat is free again.';
            case 'order_refund':
                eo_order_refund($row, (float) ($_POST['amount'] ?? 0));
                return 'Refunded.';
            case 'mentor':
                eo_mentor_assign($id, (int) ($_POST['mentor'] ?? 0), trim((string) ($_POST['role'] ?? '')));
                return 'Mentor assigned and told.';
            case 'mentor_del':
                eo_mentor_unassign($id, $row);
                return 'Removed.';
            case 'task':
                eo_mentor_task_add($id, $row, $_POST);
                return 'Task added.';
            case 'task_step':
                eo_mentor_task_step($id, $row);
                return 'Task updated.';
            case 'sponsor':
                eo_sponsor_assign($id, (int) ($_POST['sponsor'] ?? 0), (string) ($_POST['tier'] ?? ''));
                return 'Sponsor added to the event.';
            case 'sponsor_del':
                eo_sponsor_unassign($id, $row);
                return 'Removed.';
            case 'food':
                return eo_food_issue($id, (int) ($_POST['quantity'] ?? 1)) . ' food tokens sent.';
            case 'certs':
                return eo_certs_issue($id) . ' certificates issued.';
            case 'staff':
                eo_staff_invite($id, $_POST);
                return 'Their link has been emailed.';
            case 'staff_del':
                eo_staff_revoke($id, $row);
                return 'Link closed.';
            case 'report':
                eo_ai_report($id);
                return 'Report written.';
        }
        throw new InvalidArgumentException('Unknown action.');
    }, $self);
    $ev = eo_event($id);
}

$seats = eo_seats_taken($id);
$acts = ($work && $open ? '<a class="btn" href="' . e(inc_url('checkin', ['id' => $id])) . '">Check-in</a> ' : '')
      . (inc_can('view') ? '<a class="btn btn-primary" href="' . e(inc_url('applications', ['event' => $ev['label']])) . '">Open the board</a>' : '');
inc_layout_start((string) $ev['name'], e(eo_dates_text($ev)) . ($ev['location'] ? ' · ' . e((string) $ev['location']) : '') . ($ev['event_type'] ? ' · ' . e((string) $ev['event_type']) : ''),
                 ['Approved events' => inc_url('events'), (string) $ev['name'] => null], $acts, $flash);
if ((int) $ev['approved'] !== 1) {
    echo '<div class="inc-warn"><strong>No longer approved.</strong> The approval on the Events sheet was withdrawn, so the public page is closed. Everything here is kept.</div>';
}
if ($ev['cancelled_at']) {
    echo '<div class="inc-warn"><strong>Cancelled.</strong> ' . e((string) $ev['cancel_reason']) . '</div>';
}
echo '<nav class="inc-apptabs" aria-label="Event">';
foreach ($tabs as $k => $label) {
    echo '<a href="' . e(inc_url('event', ['id' => $id, 'v' => $k])) . '"' . ($k === $tab ? ' class="on" aria-current="page"' : '') . '>' . e($label) . '</a>';
}
echo '</nav>';

$act = static fn(string $action, string $label, int $row = 0, string $confirm = '', string $cls = 'btn btn-sm'): string => inc_form_open('class="inc-inline"') . '<input type="hidden" name="action" value="' . e($action) . '"><input type="hidden" name="row" value="' . $row . '">'
    . '<button class="' . e($cls) . '"' . ($confirm !== '' ? ' data-confirm="' . e($confirm) . '"' : '') . '>' . e($label) . '</button></form>';
$table = static function (array $heads, array $rows): string {
    $h = '<table class="data"><thead><tr>';
    foreach ($heads as $x) {
        $h .= '<th>' . e($x) . '</th>';
    }
    $h .= '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $h .= '<tr>';
        foreach ($r as $i => $c) {
            $h .= '<td data-l="' . e($heads[$i] ?? '') . '">' . $c . '</td>';
        }
        $h .= '</tr>';
    }
    return $h . '</tbody></table>';
};
$cur = (string) $ev['currency'];

if ($tab === 'overview') {
    $forms = eo_event_forms($id);
    echo '<div class="inc-grid2 wide-left"><div><section class="card"><h2>Application forms</h2>';
    echo '<p class="muted small">Applications from these forms go onto the main pipeline with this program\'s name and the type of participant, and to the Organizers desk.</p>';
    if ($forms) {
        $rows = [];
        foreach ($forms as $f) {
            $live = (int) $f['public_enabled'] === 1 && $f['published_no'] !== null;
            $link = eo_site_url('event-apply.php?f=' . $f['public_token']);
            $rows[] = [e($f['name']) . ($live ? '<div class="small"><a href="' . e($link) . '" target="_blank" rel="noopener">Public link</a></div>' : ''),
                       $live ? '<span class="pill green">Open</span>' : ($f['published_no'] !== null ? '<span class="pill grey">Link closed</span>' : '<span class="pill amber">Draft</span>'),
                       number_format((int) $f['apps']), inc_can('configure') ? '<a class="btn btn-sm" href="' . e(inc_url('form', ['id' => (int) $f['id']])) . '">Edit form</a>' : ''];
        }
        echo $table(['Form', 'State', 'Applications', ''], $rows);
    }
    if (inc_can('configure') && $open) {
        echo inc_form_open() . '<input type="hidden" name="action" value="form"><div class="inc-row"><input type="text" name="name" maxlength="160" class="grow" aria-label="Form name" placeholder="' . e($ev['name'] . ' — application') . '">'
           . '<button class="btn btn-primary">+ Create form</button></div></form>';
    }
    echo '</section>';
    if ($work) {
        $cats = array_column(inc_all('SELECT id, name FROM inc_ev_categories ORDER BY name'), 'name', 'id');
        $chk = static fn(string $k, string $label) => '<label class="check"><input type="checkbox" name="' . $k . '" value="1"' . ((int) $ev[$k] === 1 ? ' checked' : '') . '> ' . e($label) . '</label>';
        echo '<section class="card"><h2>Public page details</h2>' . inc_form_open('', true) . '<input type="hidden" name="action" value="save">'
           . inc_field('One-line summary', '<input type="text" name="summary" maxlength="300" value="' . e((string) $ev['summary']) . '">')
           . inc_field('About the event', '<textarea name="description" rows="6">' . e((string) $ev['description']) . '</textarea>')
           . '<div class="inc-row">' . inc_field('Venue', '<input type="text" name="venue" maxlength="255" value="' . e((string) $ev['venue']) . '">', '', false, 'grow')
           . inc_field('City', '<input type="text" name="city" maxlength="120" value="' . e((string) $ev['city']) . '">')
           . inc_field('Mode', '<select name="mode">' . inc_options(['in_person' => 'In person', 'online' => 'Online', 'hybrid' => 'Hybrid'], $ev['mode']) . '</select>') . '</div>'
           . '<div class="inc-row">' . inc_field('Starts at', '<input type="time" name="start_time" value="' . e((string) $ev['start_time']) . '">')
           . inc_field('Seats', '<input type="number" name="capacity" min="1" value="' . e((string) $ev['capacity']) . '">', 'Empty for no limit.')
           . inc_field('Registration closes', '<input type="date" name="reg_deadline" value="' . e((string) $ev['reg_deadline']) . '">') . '</div>'
           . '<div class="inc-row">' . $chk('is_paid', 'Paid event') . inc_field('Ticket price', '<input type="number" step="0.01" min="0" name="price" value="' . e((string) $ev['price']) . '">')
           . inc_field('Currency', '<input type="text" name="currency" maxlength="3" value="' . e((string) $ev['currency']) . '">') . '</div>'
           . '<div class="inc-row">' . inc_field('Category', '<select name="category_id">' . inc_options($cats, $ev['category_id'] ?? '', 'None') . '</select>')
           . inc_field('Tags', '<input type="text" name="tags" maxlength="255" value="' . e((string) $ev['tags']) . '">', 'Separated by commas.', false, 'grow') . '</div>'
           . inc_field('Included', '<input type="text" name="amenities" maxlength="500" value="' . e((string) $ev['amenities']) . '">', 'Wi-Fi, parking, kits...')
           . '<div class="inc-row">' . $chk('approval_required', 'Review every application before a ticket') . $chk('waitlist_enabled', 'Waiting list when full') . $chk('reminders_enabled', 'Send reminders') . $chk('is_featured', 'Featured')
           . $chk('food_provided', 'Food') . $chk('accommodation_provided', 'Accommodation') . '</div>'
           . inc_field('Meeting link', '<input type="url" name="meeting_url" maxlength="500" value="' . e((string) $ev['meeting_url']) . '">', 'For online and hybrid events.')
           . inc_field('Video link', '<input type="url" name="video_url" maxlength="500" value="' . e((string) $ev['video_url']) . '">')
           . inc_field('Banner', '<input type="file" name="banner" accept="image/jpeg,image/png,image/webp">', $ev['banner'] ? 'A banner is set. Choose another to replace it.' : 'JPG, PNG or WebP.')
           . '<button class="btn btn-primary">Save details</button></form> ' . $act('ai_write', 'Draft the description with AI', 0, '', 'btn') . '</section>';
    }
    echo '</div><div><section class="card"><h2>Public page</h2>';
    echo (int) $ev['published'] === 1 ? '<p><span class="pill green">Live</span></p><p class="small"><a href="' . e(eo_public_url($ev)) . '" target="_blank" rel="noopener">Open the public page</a></p>' : '<p class="muted small">Closed. Nobody outside can see this event.</p>';
    if ($work && $open) {
        echo inc_form_open('class="inc-inline"') . '<input type="hidden" name="action" value="publish">' . ((int) $ev['published'] === 1 ? '' : '<input type="hidden" name="on" value="1">')
           . '<button class="btn' . ((int) $ev['published'] === 1 ? '' : ' btn-primary') . '">' . ((int) $ev['published'] === 1 ? 'Close the page' : 'Make it public') . '</button></form>';
    }
    echo '</section><section class="card"><h2>At a glance</h2><dl class="eo-facts"><dt>Seats</dt><dd>' . ($ev['capacity'] ? number_format($seats) . ' of ' . number_format((int) $ev['capacity']) . ' taken' : number_format($seats) . ' taken, no limit') . '</dd>'
       . '<dt>Registration</dt><dd>' . e(eo_closed_reason($ev) ?: 'Open') . '</dd><dt>Page views</dt><dd>' . number_format((int) $ev['view_count']) . '</dd>'
       . '<dt>From the sheet</dt><dd class="muted small">Name, dates, type and place follow the Events sheet.</dd></dl></section>';
    if ($work && $open) {
        echo '<section class="card"><h2>Online session</h2>' . (eo_zoom_ready() ? $act('zoom', 'Create a Zoom meeting') : '<p class="muted small">Zoom is not set up. Paste any meeting link into the details instead.</p>') . '</section>';
        echo '<section class="card"><h2>Cancel the event</h2>' . inc_form_open() . '<input type="hidden" name="action" value="cancel_event"><textarea name="reason" rows="2" aria-label="Reason" placeholder="Reason, sent to everyone with a ticket"></textarea>'
           . '<p><button class="btn" data-confirm="Cancel this event and tell everyone?">Cancel event</button></p></form></section>';
    }
    echo '</div></div>';
}

if ($tab === 'people') {
    $f = ['stage_id' => (int) ($_GET['stage_id'] ?? 0), 'q' => trim((string) ($_GET['q'] ?? ''))];
    $rows = eo_attendees($id, $f);
    $days = eo_days($ev);
    echo '<form method="get" class="inc-row eo-search"><input type="hidden" name="p" value="event"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="v" value="people">'
       . '<input type="search" name="q" value="' . e($f['q']) . '" placeholder="Search name, email, phone or reference" aria-label="Search attendees" class="grow">'
       . '<select name="stage_id" aria-label="Stage">' . inc_options(inc_stage_map('crm'), $f['stage_id'] ?: '', 'Every stage') . '</select><button class="btn">Filter</button>'
       . (eo_plan_has('excel') ? ' <a class="btn" href="' . e(inc_url('event', ['id' => $id, 'v' => 'people', 'download' => 'xlsx'])) . '">Excel</a>' : '') . ' <a class="btn" href="' . e(inc_url('badges', ['id' => $id])) . '">Badges</a></form>';
    if (!$rows) {
        echo inc_empty('Nobody here yet.', 'People who apply through this event\'s forms are listed here.');
    } else {
        echo inc_form_open('id="eo-issue"') . '<input type="hidden" name="action" value="issue"></form>';
        echo '<section class="card"><table class="data"><thead><tr>' . ($work ? '<th><input type="checkbox" id="eo-all" aria-label="Select everyone without a ticket"></th>' : '')
           . '<th>Name</th><th>Type</th><th>Contact</th><th>Stage</th><th>Ticket</th><th class="num">Days in</th>' . ($work ? '<th></th>' : '') . '</tr></thead><tbody>';
        foreach ($rows as $a) {
            $live = $a['ticket_status'] === 'issued';
            $ticket = $live ? '<span class="pill green">Issued</span>' . ($a['emailed_at'] ? '' : ' <span class="muted small">not emailed</span>')
                    : ($a['ticket_status'] === 'reserved' ? '<span class="pill amber">Awaiting payment</span>' : ($a['ticket_status'] === 'cancelled' ? '<span class="pill red">Cancelled</span>' : ($a['reg_status'] === 'rejected' ? '<span class="pill red">Rejected</span>' : '<span class="muted">None</span>')));
            echo '<tr>' . ($work ? '<td>' . ($live ? '' : '<input type="checkbox" class="eo-pick" form="eo-issue" name="app[]" value="' . (int) $a['id'] . '" aria-label="' . e('Select ' . $a['applicant_name']) . '">') . '</td>' : '')
               . '<td data-l="Name">' . (inc_can('view') ? '<a href="' . e(inc_url('application', ['id' => (int) $a['id']])) . '">' . e($a['applicant_name']) . '</a>' : e($a['applicant_name'])) . '<div class="muted small">' . e($a['ref']) . '</div>'
               . ((int) $a['flagged'] === 1 ? '<span class="pill red" title="' . e((string) $a['flag_reason']) . '">Check this one</span>' : '') . '</td>'
               . '<td data-l="Type">' . e((string) $a['type_name']) . '</td><td data-l="Contact">' . e($a['applicant_email']) . '<div class="muted small">' . e((string) $a['applicant_phone']) . '</div></td>'
               . '<td data-l="Stage">' . e((string) $a['stage_name']) . '</td><td data-l="Ticket">' . $ticket . '</td><td data-l="Days in" class="num">' . (int) $a['days_in'] . ' of ' . $days . '</td>';
            if ($work) {
                echo '<td>' . ($live ? '<a class="btn btn-sm" href="' . e(eo_ticket_url((string) $a['code'])) . '" target="_blank" rel="noopener">Ticket</a> ' . $act('cancel', 'Cancel', (int) $a['ticket_id'], 'Cancel this ticket?') : '')
                   . (!$live && $a['reg_status'] !== 'rejected' ? $act('reject', 'Reject', (int) $a['id'], 'Reject this registration and tell the applicant?') : '') . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
        if ($work && $open) {
            echo '<div class="inc-formfoot"><button class="btn btn-primary" form="eo-issue">Issue and email tickets</button> <span class="muted small">To the people ticked. Filter by stage first to ticket only those accepted.</span></div>';
        }
        echo '</section>';
    }
    $wait = eo_waitlist($id);
    if ($wait) {
        echo '<section class="card"><h2>Waiting list</h2>' . $table(['No.', 'Name', 'Email', 'State'], array_map(static fn($w) => [(int) $w['position'], e($w['name']), e($w['email']), e(ucfirst((string) $w['status']))], $wait)) . '</section>';
    }
}

if ($tab === 'money') {
    [$gross, $refunded, $net, $paid, $pending] = eo_revenue($id);
    echo '<div class="eo-tiles">' . inc_tile('Net revenue', eo_money($net, $cur)) . inc_tile('Refunded', eo_money($refunded, $cur)) . inc_tile('Paid orders', number_format($paid)) . inc_tile('Waiting for payment', number_format($pending)) . '</div>';
    if ((int) $ev['is_paid'] !== 1) {
        echo '<div class="inc-note">This is a free event. Tick Paid event under Overview to sell tickets.</div>';
    }
    echo '<section class="card"><h2>Orders</h2>';
    $orders = eo_orders(['event_id' => $id]);
    if (!$orders) {
        echo inc_empty('No orders yet.', 'Orders appear when someone applies to a paid event.');
    } else {
        $rows = [];
        foreach ($orders as $o) {
            $btn = '';
            if ($work && $o['status'] === 'pending' && $o['method'] !== 'none') {
                $btn = $act('order_paid', 'Mark paid', (int) $o['id']) . ' ' . $act('order_reject', 'Reject', (int) $o['id'], 'Turn this payment down and free the seat?');
            } elseif ($work && $o['status'] === 'paid') {
                $btn = $act('order_refund', 'Refund', (int) $o['id'], 'Refund this order in full and cancel the ticket?');
            }
            $rows[] = [e($o['name']) . '<div class="muted small">' . e($o['email']) . '</div>', e(eo_money((float) $o['amount'], (string) $o['currency'])), e($o['method']) . ($o['bank_ref'] ? '<div class="muted small">' . e($o['bank_ref']) . '</div>' : ''), e(ucfirst((string) $o['status'])), $btn];
        }
        echo $table(['Who', 'Amount', 'Method', 'Status', ''], $rows);
    }
    echo '</section><div class="inc-grid2 wide-left"><section class="card"><h2>Coupons</h2>';
    $coupons = eo_coupons($id);
    if ($coupons) {
        $rows = [];
        foreach ($coupons as $c) {
            $rows[] = [e($c['code']), e($c['type'] === 'percentage' ? (float) $c['value'] . '%' : eo_money((float) $c['value'], $cur)), (int) $c['redeemed'] . ($c['max_redemptions'] !== null ? ' of ' . (int) $c['max_redemptions'] : ''), e((string) $c['referrer']),
                       (int) $c['active'] === 1 ? '<span class="pill green">Active</span>' : '<span class="pill grey">Off</span>', $work ? $act('coupon_toggle', (int) $c['active'] === 1 ? 'Turn off' : 'Turn on', (int) $c['id']) : ''];
        }
        echo $table(['Code', 'Discount', 'Used', 'Referrer', 'State', ''], $rows);
    } else {
        echo '<p class="muted small">None yet.</p>';
    }
    echo '</section>';
    if ($work) {
        echo '<section class="card"><h2>Add a coupon</h2>' . inc_form_open() . '<input type="hidden" name="action" value="coupon">' . inc_field('Code', '<input type="text" name="code" maxlength="40" required>', '', true)
           . '<div class="inc-row">' . inc_field('Type', '<select name="type">' . inc_options(['percentage' => 'Percentage', 'fixed' => 'Fixed amount'], 'percentage') . '</select>') . inc_field('Value', '<input type="number" step="0.01" name="value" required>', '', true) . '</div>'
           . '<div class="inc-row">' . inc_field('Most uses', '<input type="number" name="max_redemptions" min="1">') . inc_field('Expires', '<input type="date" name="expires_on">') . '</div>' . inc_field('Referrer', '<input type="text" name="referrer" maxlength="120">')
           . '<button class="btn btn-primary">Add coupon</button></form></section>';
    }
    echo '</div>';
}

if ($tab === 'agenda') {
    $sessions = eo_sessions($id);
    $people = eo_people($id);
    $days = eo_days($ev);
    $kinds = eo_people_kinds();
    echo '<div class="inc-grid2 wide-left"><section class="card"><h2>Agenda</h2>';
    if (!$sessions) {
        echo inc_empty('No sessions yet.', 'Sessions added here show on the public page.');
    } else {
        echo $table(['Day', 'Time', 'Session', 'Room', ''], array_map(static fn($s) => [(int) $s['day_no'], e(trim($s['starts'] . ($s['ends'] ? ' – ' . $s['ends'] : ''))), e($s['title']) . ($s['speaker'] ? '<div class="muted small">' . e($s['speaker']) . '</div>' : ''), e((string) $s['room']), $work ? $act('session_del', 'Remove', (int) $s['id']) : ''], $sessions));
    }
    echo '</section>';
    if ($work) {
        echo '<section class="card"><h2>Add a session</h2>' . inc_form_open() . '<input type="hidden" name="action" value="session">' . inc_field('Title', '<input type="text" name="title" maxlength="190" required>', '', true)
           . '<div class="inc-row">' . ($days > 1 ? inc_field('Day', '<input type="number" name="day_no" min="1" max="' . $days . '" value="1">') : '') . inc_field('Starts', '<input type="time" name="starts">') . inc_field('Ends', '<input type="time" name="ends">') . '</div>'
           . inc_field('Speaker', '<input type="text" name="speaker" maxlength="190">') . inc_field('Room', '<input type="text" name="room" maxlength="120">') . '<button class="btn btn-primary">Add session</button></form></section>';
    }
    echo '</div><div class="inc-grid2 wide-left"><section class="card"><h2>Speakers and guests</h2>';
    echo $people ? $table(['Name', 'As', 'Details', ''], array_map(static fn($p) => [$p['url'] ? '<a href="' . e($p['url']) . '" target="_blank" rel="noopener">' . e($p['name']) . '</a>' : e($p['name']), e($kinds[$p['kind']]), e((string) $p['detail']), $work ? $act('person_del', 'Remove', (int) $p['id']) : ''], $people)) : '<p class="muted small">Nobody added yet.</p>';
    echo '</section>';
    if ($work) {
        echo '<section class="card"><h2>Add someone</h2>' . inc_form_open() . '<input type="hidden" name="action" value="person">' . inc_field('Name', '<input type="text" name="name" maxlength="190" required>', '', true)
           . inc_field('As', '<select name="kind">' . inc_options($kinds, 'speaker') . '</select>') . inc_field('Details', '<input type="text" name="detail" maxlength="255">') . inc_field('Link', '<input type="url" name="url" maxlength="500">') . '<button class="btn btn-primary">Add</button></form></section>';
    }
    echo '</div>';
}

if ($tab === 'live') {
    $sessions = eo_sessions($id);
    if (!$sessions) {
        echo inc_empty('No sessions yet.', 'Add sessions under Agenda & people. Ticket holders then ask questions and vote from their ticket.');
    }
    foreach ($sessions as $s) {
        echo '<section class="card"><h2>' . e($s['title']) . '</h2><div class="inc-grid2"><div><h3>Questions</h3>';
        $qs = eo_questions((int) $s['id']);
        echo $qs ? $table(['Question', 'Votes', ''], array_map(static fn($q) => [e($q['question']) . '<div class="muted small">' . e((string) $q['applicant_name']) . '</div>', (int) $q['votes'], $work ? $act('q_answer', (int) $q['answered'] === 1 ? 'Reopen' : 'Answered', (int) $q['id']) . ' ' . $act('q_delete', 'Remove', (int) $q['id']) : ''], $qs)) : '<p class="muted small">None yet.</p>';
        echo '</div><div><h3>Polls</h3>';
        foreach (eo_polls((int) $s['id']) as $p) {
            $total = array_sum(array_column($p['options'], 'votes'));
            echo '<p><strong>' . e($p['question']) . '</strong> ' . ((int) $p['open_now'] === 1 ? '<span class="pill green">Open</span>' : '<span class="pill grey">Closed</span>') . ' ' . ($work ? $act('poll_toggle', (int) $p['open_now'] === 1 ? 'Close' : 'Open', (int) $p['id']) : '') . '</p>';
            echo inc_bars(array_column($p['options'], 'votes', 'label'), 'No votes yet.');
            echo '<p class="muted small">' . (int) $total . ' votes</p>';
        }
        if ($work) {
            echo inc_form_open() . '<input type="hidden" name="action" value="poll"><input type="hidden" name="session" value="' . (int) $s['id'] . '">' . inc_field('New poll', '<input type="text" name="question" maxlength="300" required>')
               . inc_field('Options', '<textarea name="options" rows="3" required></textarea>', 'One per line.') . '<button class="btn">Open poll</button></form>';
        }
        echo '</div></div></section>';
    }
}

if ($tab === 'mentors') {
    if (!eo_plan_has('mentors')) {
        echo '<div class="inc-warn">Your plan does not include mentors.</div>';
    }
    foreach (eo_event_mentors($id) as $m) {
        echo '<section class="card"><h2>' . e($m['name']) . '</h2><p class="muted small">' . e(trim((string) $m['role_label'] . ' ' . (string) $m['expertise'])) . '</p>';
        if ($m['tasks']) {
            echo $table(['Task', 'Day', 'Due', 'State', ''], array_map(static fn($t) => [e($t['title']), e((string) $t['day_no']), e(inc_fmt_date($t['due_on'])), e(str_replace('_', ' ', (string) $t['status'])), $work ? $act('task_step', 'Next state', (int) $t['id']) : ''], $m['tasks']));
        }
        if ($work) {
            echo inc_form_open() . '<input type="hidden" name="action" value="task"><input type="hidden" name="row" value="' . (int) $m['id'] . '"><div class="inc-row"><input type="text" name="title" class="grow" required maxlength="190" aria-label="Task" placeholder="New task">'
               . '<input type="date" name="due_on" aria-label="Due"><button class="btn">Add task</button></div></form> ' . $act('mentor_del', 'Remove from event', (int) $m['id']);
        }
        echo '</section>';
    }
    $choices = array_column(array_filter(eo_mentors(), static fn($m) => (int) $m['active'] === 1), 'name', 'id');
    if ($work) {
        echo '<section class="card"><h2>Assign a mentor</h2>' . ($choices ? inc_form_open() . '<input type="hidden" name="action" value="mentor"><div class="inc-row"><select name="mentor" aria-label="Mentor">' . inc_options($choices, '') . '</select><input type="text" name="role" maxlength="120" placeholder="Role" aria-label="Role"><button class="btn btn-primary">Assign</button></div></form>'
                                                                          : '<p class="muted small">Add mentors first, under <a href="' . e(inc_url('mentors')) . '">Mentors</a>.</p>') . '</section>';
    }
}

if ($tab === 'sponsors') {
    $assigned = eo_event_sponsors($id);
    echo '<section class="card"><h2>Sponsors of this event</h2>';
    echo $assigned ? $table(['Sponsor', 'Tier', 'Clicks', 'Leads', ''], array_map(static fn($s) => [e($s['name']), e(eo_sponsor_tiers()[$s['tier']]), (int) $s['clicks'], (int) $s['leads'], $work ? $act('sponsor_del', 'Remove', (int) $s['id']) : ''], $assigned)) : '<p class="muted small">None yet.</p>';
    if ($work) {
        $choices = array_column(eo_sponsors(), 'name', 'id');
        echo $choices ? inc_form_open() . '<input type="hidden" name="action" value="sponsor"><div class="inc-row"><select name="sponsor" aria-label="Sponsor">' . inc_options($choices, '') . '</select><select name="tier" aria-label="Tier">' . inc_options(eo_sponsor_tiers(), 'partner') . '</select><button class="btn btn-primary">Add</button></div></form>'
                      : '<p class="muted small">Add sponsors first, under <a href="' . e(inc_url('sponsors')) . '">Sponsors</a>.</p>';
    }
    echo '</section>';
    foreach ($assigned as $s) {
        $leads = eo_leads((int) $s['id']);
        if ($leads) {
            echo '<section class="card"><h2>Leads for ' . e($s['name']) . '</h2>' . $table(['Name', 'Email', 'Phone', 'Rating', 'Notes'], array_map(static fn($l) => [e($l['applicant_name']), e($l['applicant_email']), e((string) $l['applicant_phone']), e((string) $l['rating']), e((string) $l['notes'])], $leads)) . '</section>';
        }
    }
}

if ($tab === 'onsite') {
    $food = eo_food_list($id);
    $certs = eo_certs($id);
    echo '<div class="inc-grid2"><section class="card"><h2>At the door</h2><p><a class="btn btn-primary" href="' . e(inc_url('checkin', ['id' => $id])) . '">Scanner</a> <a class="btn" href="' . e(inc_url('kiosk', ['id' => $id])) . '">Self check-in kiosk</a> <a class="btn" href="' . e(inc_url('badges', ['id' => $id])) . '">Print badges</a></p>'
       . '<p class="muted small">The scanner keeps working without a network and syncs when it returns.</p></section><section class="card"><h2>Food tokens</h2>';
    $used = array_sum(array_column($food, 'redeemed'));
    echo '<p>' . number_format(count($food)) . ' sent, ' . number_format($used) . ' meals redeemed.</p>';
    if ($work) {
        echo inc_form_open() . '<input type="hidden" name="action" value="food"><div class="inc-row">' . inc_field('Meals each', '<input type="number" name="quantity" min="1" value="1">') . '<button class="btn">Send to ticket holders</button></div></form>';
    }
    echo '</section></div><section class="card"><h2>Certificates</h2><p>' . number_format(count($certs)) . ' issued. Everyone checked in gets one once the event has ended.</p>' . ($work ? $act('certs', 'Issue certificates', 0, '', 'btn') : '');
    if ($certs) {
        echo $table(['Name', 'Number', 'Days', ''], array_map(static fn($c) => [e($c['applicant_name']), e($c['number']), (int) $c['days_attended'] . ' of ' . (int) $c['total_days'], '<a class="btn btn-sm" href="' . e(eo_cert_url((string) $c['token'])) . '" target="_blank" rel="noopener">View</a>'], $certs));
    }
    echo '</section>';
}

if ($tab === 'staff') {
    $list = eo_staff_list($id);
    echo '<div class="inc-grid2 wide-left"><section class="card"><h2>Staff links</h2><p class="muted small">For people with one job and no portal account. Each gets a private link. Portal staff are on <a href="' . e(inc_url('team')) . '">Organizers team</a>.</p>';
    echo $list ? $table(['Who', 'Role', 'State', ''], array_map(static fn($m) => [e($m['name'] ?: $m['email']) . '<div class="muted small">' . e($m['email']) . '</div>', e(strtok(eo_staff_roles()[$m['role']], ':')), e(ucfirst((string) $m['status'])), $work && $m['status'] === 'active' ? $act('staff_del', 'Close link', (int) $m['id']) : ''], $list)) : '<p class="muted small">None yet.</p>';
    echo '</section>';
    if ($work) {
        echo '<section class="card"><h2>Invite someone</h2>' . inc_form_open() . '<input type="hidden" name="action" value="staff">' . inc_field('Name', '<input type="text" name="name" maxlength="190">') . inc_field('Email', '<input type="email" name="email" required maxlength="190">', '', true)
           . inc_field('Role', '<select name="role">' . inc_options(eo_staff_roles(), 'scanner') . '</select>') . inc_field('Sponsor', '<select name="sponsor_id">' . inc_options(array_column(eo_event_sponsors($id), 'name', 'id'), '', 'For sponsor reps') . '</select>') . '<button class="btn btn-primary">Send link</button></form></section>';
    }
    echo '</div>';
}

if ($tab === 'insights') {
    $d = eo_insights($id);
    echo '<div class="eo-tiles">' . inc_tile('Page views', number_format($d['views'])) . inc_tile('Applied', number_format($d['applied'])) . inc_tile('Tickets', number_format($d['tickets'])) . inc_tile('Came', number_format($d['came'])) . inc_tile('Online', number_format($d['online']))
       . inc_tile('Rating', $d['rating'] !== null ? $d['rating'] . ' / 5' : '—') . inc_tile('Net revenue', eo_money($d['revenue'][2], $cur)) . '</div>';
    echo '<div class="inc-grid2"><section class="card"><h2>Types of participant</h2>' . inc_bars($d['types'], 'Nobody yet.') . '</section><section class="card"><h2>Check-ins by day</h2>' . inc_bars(array_combine(array_map(static fn($k) => 'Day ' . $k, array_keys($d['days'])), array_values($d['days'])) ?: [], 'None yet.') . '</section></div>';
    echo '<section class="card"><h2>Event report</h2>' . ($work ? $act('report', 'Write the report with AI', 0, '', 'btn') : '');
    foreach (eo_ai_reports($id) as $r) {
        echo '<p class="muted small">' . e(inc_fmt_datetime($r['created_at'])) . ' · ' . e($r['created_by']) . '</p><div class="eo-report">' . nl2br(e($r['content'])) . '</div>';
    }
    echo '</section>';
}

if ($tab === 'feedback') {
    $rows = eo_feedback($id);
    if (!$rows) {
        echo inc_empty('No feedback yet.', 'Ticket holders can leave a rating on their ticket page once the event begins. Review it under Messages & feedback.');
    } else {
        $avg = array_sum(array_column($rows, 'rating')) / count($rows);
        echo '<div class="eo-tiles">' . inc_tile('Average rating', number_format($avg, 1) . ' / 5') . inc_tile('Responses', number_format(count($rows))) . '</div><section class="card">'
           . $table(['From', 'Rating', 'Comment', 'State', 'When'], array_map(static fn($r) => [e($r['applicant_name']), (int) $r['rating'], nl2br(e((string) $r['comment'])), e(ucfirst((string) $r['status'])), e(inc_fmt_datetime($r['created_at']))], $rows)) . '</section>';
    }
}

echo '<script src="' . e(fabo_asset(fabo_root_prefix() . 'assets/eo.js')) . '" defer></script>';
inc_layout_end();

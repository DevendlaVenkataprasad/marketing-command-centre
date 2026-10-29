<?php
/**
 * Helpdesk & Announcements — the notice board, and questions to HR.
 *
 *   ?id=ID   one query, with its conversation
 */

require_once __DIR__ . '/parts.php';

$self = hr_self_emp();
$agent = hr_is_helpdesk_agent();
$canPost = hr_is_admin() || hr_scope('helpdesk', 'create') === 'org';
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    $tid = (int) ($_POST['id'] ?? 0);
    $back = $tid ? hr_url('helpdesk', ['id' => $tid]) : hr_url('helpdesk', ['tab' => (string) ($_GET['tab'] ?? '')]);
    if (in_array($a, ['master_save', 'master_toggle', 'master_delete', 'master_import'], true)) {
        $flash = hr_parts_post(hr_url('helpdesk', ['tab' => 'setup']));
    } elseif ($a === 'announce') {
        $flash = hr_action(static function () {
            hr_announcement_save((int) ($_POST['ann'] ?? 0) ?: null, $_POST);
            return 'Announcement saved.' . (!empty($_POST['notify']) ? ' Everyone it is for has been notified.' : '');
        }, hr_url('helpdesk', ['tab' => 'notices']));
    } elseif ($a === 'announce_delete') {
        $flash = hr_action(static function () { hr_announcement_delete((int) ($_POST['ann'] ?? 0)); return 'Announcement removed.'; }, hr_url('helpdesk', ['tab' => 'notices']));
    } elseif ($a === 'ticket') {
        hr_need('helpdesk', 'create');
        $flash = hr_action(static function () {
            $id = hr_ticket_create($_POST);
            hr_redirect(hr_url('helpdesk', ['id' => $id]), ['ok', 'Your query was sent to HR. You will be notified when they reply.']);
        }, $back);
    } elseif ($a === 'reply') {
        $flash = hr_action(static function () use ($tid) { return hr_ticket_reply($tid, $_POST); }, $back);
    }
}

/* -------------------------------------------------------------- one query */

$tid = (int) ($_GET['id'] ?? 0);
if ($tid) {
    $t = hr_ticket($tid);
    if (!$t) {
        fabo_deny('That query is not available to you.', 404);
    }
    hr_layout_start($t['number'] . ' — ' . $t['subject'], e($t['full_name']) . ' · ' . e($t['category'] ?? 'General') . ' · '
                  . hr_badge(strtolower(hr_ticket_statuses()[$t['status']]), hr_ticket_statuses()[$t['status']]) . ' ' . hr_badge($t['priority'], hr_ticket_priorities()[$t['priority']]),
                    ['Helpdesk' => hr_url('helpdesk', ['tab' => 'queries']), $t['number'] => '']);
    echo hr_flash_html($flash);
    echo '<div class="card"><div class="hr-thread">';
    echo '<div class="msg"><div class="who">' . e($t['created_by'] ?? $t['full_name']) . ' <span class="muted small">' . e(date('d M Y H:i', strtotime($t['created_at']))) . '</span></div>'
       . '<div class="body">' . nl2br(e($t['body'])) . '</div></div>';
    foreach (hr_ticket_replies($tid, $agent) as $r) {
        echo '<div class="msg' . ($r['from_hr'] ? ' hr' : '') . ($r['internal'] ? ' internal' : '') . '"><div class="who">' . e($r['author'])
           . ($r['internal'] ? ' · <strong>internal note</strong>' : ($r['from_hr'] ? ' · HR' : '')) . ' <span class="muted small">' . e(date('d M Y H:i', strtotime($r['created_at']))) . '</span></div>'
           . '<div class="body">' . nl2br(e($r['body'])) . '</div></div>';
    }
    echo '</div>';
    if ($t['status'] !== 'closed' || $agent) {
        echo hr_form_open(hr_url('helpdesk', ['id' => $tid]), 'class="hr-form" style="margin-top:14px"') . '<input type="hidden" name="action" value="reply"><input type="hidden" name="id" value="' . $tid . '">'
           . hr_field('Reply', '<textarea name="body" rows="4" maxlength="10000"></textarea>', false, '', 'full');
        if ($agent) {
            echo '<div class="formgrid">' . hr_field('Status', hr_select('status', hr_ticket_statuses(), $t['status']))
               . hr_field('Priority', hr_select('priority', hr_ticket_priorities(), $t['priority']))
               . hr_field('Handled by', hr_input('assigned_to', $t['assigned_to'] ?? hr_actor(), 'text', ['maxlength' => 160])) . '</div>'
               . '<div class="field">' . hr_checkbox('internal', false, 'Internal note (the employee does not see it)') . '</div>';
        }
        echo '<div class="hr-formfoot"><button class="btn btn-primary">' . ($agent ? 'Send / update' : 'Send reply') . '</button>'
           . (!$agent && $t['status'] === 'resolved' ? '<button class="btn" name="close" value="1">My question is answered — close it</button>' : '') . '</div></form>';
    }
    echo '</div>';
    hr_layout_end();
    return;
}

/* -------------------------------------------------------------- the desk */

$tabs = ['notices' => 'Announcements', 'queries' => $agent ? 'Queries' : 'My queries'];
if ($agent && hr_can_any(hr_master_rights('ticket_category', 'create'))) { $tabs['setup'] = 'Categories'; }
$tab = hr_tab($tabs, 'notices');
hr_layout_start('Helpdesk & Announcements', 'News from HR, and a place to ask HR anything. Your queries are seen only by you and HR.', ['Helpdesk' => '']);
echo hr_flash_html($flash);
hr_tabs('helpdesk', $tabs, $tab);

if ($tab === 'notices') {
    $live = hr_announcements_live($agent ? null : $self);
    echo '<div class="hr-notices">';
    if (!$live) {
        echo '<div class="card">' . hr_empty('No announcements right now.') . '</div>';
    }
    foreach ($live as $a) {
        echo '<article class="card hr-notice' . ($a['pinned'] ? ' pinned' : '') . '"><h2>' . ($a['pinned'] ? '<span class="pin" aria-label="Pinned">📌</span> ' : '') . e($a['title']) . '</h2>'
           . '<div class="muted small">' . e(hr_fmt_date($a['publish_from'])) . ' · ' . e($a['department'] ? 'For ' . $a['department'] : 'For everyone') . ' · ' . e($a['created_by']) . '</div>'
           . '<div class="hr-prose">' . nl2br(e($a['body'])) . '</div></article>';
    }
    echo '</div>';
    if ($canPost) {
        $editId = (int) ($_GET['ann'] ?? 0);
        $ed = $editId ? fabo_one('SELECT * FROM hr_announcements WHERE id = ? AND org_id = ?', 'ii', [$editId, hr_org()]) : null;
        echo '<div class="card" id="post"><h2>' . ($ed ? 'Edit announcement' : 'Post an announcement') . '</h2>' . hr_form_open(hr_url('helpdesk', ['tab' => 'notices']), 'class="hr-form"')
           . '<input type="hidden" name="action" value="announce"><input type="hidden" name="ann" value="' . $editId . '"><div class="formgrid">'
           . hr_field('Title', hr_input('title', $ed['title'] ?? '', 'text', ['required' => true, 'maxlength' => 190]), true, '', 'full')
           . hr_field('Message', '<textarea name="body" rows="5" maxlength="10000" required>' . e($ed['body'] ?? '') . '</textarea>', true, '', 'full')
           . hr_field('For', hr_select('department_id', hr_departments_map(), $ed['department_id'] ?? '', 'Everyone'))
           . hr_field('Show from', hr_input('publish_from', $ed['publish_from'] ?? date('Y-m-d'), 'date'))
           . hr_field('Show until', hr_input('publish_to', $ed['publish_to'] ?? '', 'date'), false, 'Blank keeps it up until removed.')
           . '</div><div class="field">' . hr_checkbox('pinned', (bool) ($ed['pinned'] ?? false), 'Pin it to the top') . '</div>'
           . (!$ed ? '<div class="field">' . hr_checkbox('notify', true, 'Notify everyone it is for') . '</div>' : '')
           . '<button class="btn btn-primary">' . ($ed ? 'Save' : 'Post') . '</button></form>';
        $all = hr_announcements_all();
        if ($all) {
            echo '<h3 style="margin-top:18px">All announcements</h3><table class="data hr-table stack"><thead><tr><th>Title</th><th>For</th><th>Shown</th><th></th></tr></thead><tbody>';
            foreach ($all as $a) {
                $liveNow = $a['publish_from'] <= date('Y-m-d') && (!$a['publish_to'] || $a['publish_to'] >= date('Y-m-d'));
                echo '<tr><td data-l="Title"><strong>' . e($a['title']) . '</strong>' . ($a['pinned'] ? ' 📌' : '') . '</td><td data-l="For">' . e($a['department'] ?? 'Everyone') . '</td>'
                   . '<td data-l="Shown" class="nowrap">' . e(hr_fmt_date($a['publish_from'])) . ' – ' . e($a['publish_to'] ? hr_fmt_date($a['publish_to']) : 'until removed') . ' '
                   . ($liveNow ? hr_badge('active', 'Live') : hr_badge('draft', 'Not showing')) . '</td><td class="hr-acts">'
                   . '<a class="btn btn-sm" href="' . e(hr_url('helpdesk', ['tab' => 'notices', 'ann' => $a['id']])) . '#post">Edit</a>'
                   . (hr_scope('helpdesk', 'delete') === 'org' || hr_is_admin() ? hr_button_form('Remove', ['action' => 'announce_delete', 'ann' => $a['id']], 'btn btn-sm btn-danger', 'Remove this announcement?') : '')
                   . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }
}

if ($tab === 'queries') {
    $f = ['status' => array_key_exists('status', $_GET) ? (string) $_GET['status'] : 'active', 'category' => (int) ($_GET['category'] ?? 0)];
    $rows = hr_tickets_list($f);
    if (hr_can('helpdesk', 'create') && ($self || $agent)) {
        echo '<details class="card"' . (!$agent && !$rows ? ' open' : '') . '><summary><strong>+ Ask HR a question</strong></summary>'
           . hr_form_open(hr_url('helpdesk', ['tab' => 'queries']), 'class="hr-form" style="margin-top:12px"') . '<input type="hidden" name="action" value="ticket"><div class="formgrid">'
           . ($agent ? hr_field('For employee', hr_select('employee_id', hr_employee_choices(true, 'employees', 'view') ?: hr_employee_choices(true, 'helpdesk', 'view'), $self['id'] ?? '', 'Choose…', ['required' => true]), true) : '')
           . hr_field('Topic', hr_select('category_id', hr_master_map('ticket_category'), '', 'General'))
           . hr_field('How urgent', hr_select('priority', hr_ticket_priorities(), 'normal'))
           . hr_field('Subject', hr_input('subject', '', 'text', ['required' => true, 'maxlength' => 190, 'placeholder' => 'e.g. Form 16 for last year']), true, '', 'full')
           . hr_field('Details', '<textarea name="body" rows="4" maxlength="10000" required></textarea>', true, 'Only you and HR can see this.', 'full')
           . '</div><button class="btn btn-primary">Send to HR</button></form></details>';
    }
    echo '<form method="get" class="card hr-filters"><input type="hidden" name="p" value="helpdesk"><input type="hidden" name="tab" value="queries">'
       . '<div class="grp"><label for="ts">Status</label><select id="ts" name="status" data-autosubmit>' . hr_options(['active' => 'Open, in progress or waiting', '' => 'All'] + hr_ticket_statuses(), $f['status']) . '</select></div>'
       . '<div class="grp"><label for="tc">Topic</label><select id="tc" name="category" data-autosubmit>' . hr_options(hr_master_map('ticket_category', false), $f['category'], 'All') . '</select></div>'
       . '<noscript><button class="btn btn-sm">Apply</button></noscript></form><div class="card">';
    if (!$rows) {
        echo hr_empty($agent ? 'No queries match.' : 'You have not asked HR anything yet.');
    } else {
        echo '<div class="scrollx"><table class="data hr-table stack"><thead><tr><th>Query</th>' . ($agent ? '<th>Employee</th>' : '') . '<th>Topic</th><th>Priority</th><th>Status</th><th>Raised</th></tr></thead><tbody>';
        foreach ($rows as $t) {
            echo '<tr><td data-l="Query"><a class="nm" href="' . e(hr_url('helpdesk', ['id' => $t['id']])) . '"><strong>' . e($t['subject']) . '</strong></a>'
               . '<div class="muted small">' . e($t['number']) . ' · ' . (int) $t['replies'] . ' repl' . ((int) $t['replies'] === 1 ? 'y' : 'ies') . ($t['assigned_to'] ? ' · ' . e($t['assigned_to']) : '') . '</div></td>'
               . ($agent ? '<td data-l="Employee">' . hr_person_cell($t, false) . '</td>' : '')
               . '<td data-l="Topic">' . e($t['category'] ?? 'General') . '</td><td data-l="Priority">' . hr_badge($t['priority'], hr_ticket_priorities()[$t['priority']]) . '</td>'
               . '<td data-l="Status">' . hr_badge(strtolower(hr_ticket_statuses()[$t['status']]), hr_ticket_statuses()[$t['status']]) . '</td>'
               . '<td data-l="Raised" class="nowrap">' . e(date('d M Y', strtotime($t['created_at']))) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';
}

if ($tab === 'setup') {
    hr_part_masters('ticket_category', hr_url('helpdesk', ['tab' => 'setup']), ['tab' => 'setup']);
    if (hr_can_any(hr_master_rights('training_category', 'create'))) {
        echo '<p class="hr-hint">Training categories are kept on the <a href="' . e(hr_url('training', ['tab' => 'setup'])) . '">Training</a> page.</p>';
    }
}

hr_layout_end();

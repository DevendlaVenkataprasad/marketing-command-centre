<?php
/**
 * IT Management — tickets. A board by status (drag a card to change it) or a
 * list, the same filters on both, and the form to log a new ticket.
 */

$f = ['q' => trim((string) ($_GET['q'] ?? '')), 'priority' => (string) ($_GET['priority'] ?? ''), 'category' => (string) ($_GET['category'] ?? ''),
      'assignee' => (string) ($_GET['assignee'] ?? ''), 'status' => (string) ($_GET['status'] ?? ''), 'overdue' => !empty($_GET['overdue']) ? '1' : ''];
$view = ($_GET['view'] ?? '') === 'list' || $f['status'] !== '' || $f['overdue'] ? 'list' : 'board';
$keep = ['view' => $view];
$fq = array_filter($f, static fn($v) => $v !== '');
$self = it_url('tickets', $keep + $fq);
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    if ($a === 'board_move') {
        header('Content-Type: application/json');
        try {
            $msg = it_ticket_status((int) ($_POST['id'] ?? 0), (string) ($_POST['stage_id'] ?? ''));
            echo json_encode(['ok' => true, 'msg' => $msg]);
        } catch (InvalidArgumentException $e) {
            echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
        }
        exit;
    }
    $flash = inc_action(static function () use ($a) {
        if ($a === 'create') {
            $t = it_ticket_create($_POST, 'desk');
            inc_redirect(it_url('work', ['id' => $t['id']]), ['ok', it_w('Ticket ') . $t['ref'] . ' opened.' . ($t['requester_email'] ? ' The requester has been emailed their reference.' : '')]);
        }
        throw new InvalidArgumentException('Unknown action.');
    }, $self);
}

$rows = it_tickets($f + ['with_closed' => $view === 'list' ? 1 : 0]);
$team = it_team();
$selects = ['priority' => ['Priority', it_priorities()], 'category' => ['Category', it_list('ticket_category', false)],
            'assignee' => ['Assigned to', ['none' => 'Nobody yet'] + $team], 'status' => ['Status', it_statuses()], 'overdue' => ['Overdue', ['1' => 'Overdue only']]];

$acts = it_view_switch('tickets', $view, $fq) . ' <a class="btn btn-primary" href="' . e(it_url('tickets', ['new' => 1] + $keep)) . it_w('">+ New ticket</a>');
it_layout_start(it_w('Tickets'), count($rows) . it_w(' ticket') . (count($rows) === 1 ? '' : 's') . ($fq ? ' match your filters' : '') . ($view === 'board' ? ' · drag a card to change its status' : ''), $acts, $flash);

/* ------------------------------------------------------------ new ticket */
if (!empty($_GET['new']) || ($flash && ($_POST['action'] ?? '') === 'create')) {
    $p = $_POST;
    echo it_w('<section class="card"><h2>New ticket</h2>') . inc_form_open() . '<input type="hidden" name="action" value="create">'
       . '<div class="inc-row">' . inc_field('Subject', '<input type="text" name="title" maxlength="200" required value="' . e($p['title'] ?? '') . '" placeholder="e.g. Laptop will not connect to Wi-Fi">', '', true, 'grow') . '</div>'
       . '<div class="inc-row">'
       . inc_field('Requester', '<input type="text" name="requester_name" maxlength="160" required list="it-staff-names" value="' . e($p['requester_name'] ?? '') . '">', '', true)
       . inc_field('Requester email', '<input type="email" name="requester_email" maxlength="190" list="it-staff-emails" value="' . e($p['requester_email'] ?? '') . '">', 'They get the reference and every reply.')
       . inc_field('Category', inc_select('category', it_list('ticket_category'), $p['category'] ?? '', 'Choose…'))
       . inc_field('Priority', inc_select('priority', it_priorities(), $p['priority'] ?? 'medium'))
       . inc_field('Assign to', inc_select('assignee_id', $team, $p['assignee_id'] ?? '', 'Nobody yet'))
       . inc_field(it_w('Device'), inc_select('asset_id', array_column(it_all("SELECT id, CONCAT(tag, ' — ', name) AS l FROM it_assets WHERE status NOT IN ('retired','lost') ORDER BY tag"), 'l', 'id'), $p['asset_id'] ?? '', 'None'))
       . '</div>' . inc_field('Description', inc_textarea('description', (string) ($p['description'] ?? ''), 4))
       . '<datalist id="it-staff-names">' . implode('', array_map(static fn($s) => '<option value="' . e($s['name']) . '">', it_staff())) . '</datalist>'
       . '<datalist id="it-staff-emails">' . implode('', array_map(static fn($s) => $s['email'] ? '<option value="' . e($s['email']) . '">' : '', it_staff())) . '</datalist>'
       . it_w('<button class="btn btn-primary">Open ticket</button> <a class="btn" href="') . e(it_url('tickets', $keep)) . '">Cancel</a></form></section>';
}

echo it_filter_bar('tickets', $f, $view === 'board' ? array_diff_key($selects, ['status' => 1]) : $selects, $keep);

$card = static function (array $t): string {
    $age = (int) floor((time() - strtotime((string) $t['created_at'])) / 86400);
    $who = it_person_name($t['assignee_id'] ? (int) $t['assignee_id'] : null);
    return '<article class="kb-card" draggable="true" data-id="' . (int) $t['id'] . '">'
         . '<a class="kb-title" data-popup href="' . e(it_url('work', ['id' => $t['id']])) . '">' . e($t['title']) . '</a>'
         . '<div class="kb-sub">' . e($t['requester_name']) . ($t['category'] ? ' · ' . e($t['category']) : '') . '</div>'
         . '<div class="kb-chips">' . it_priority_badge($t['priority']) . (it_is_overdue($t) ? inc_badge('Overdue', 'red') : '')
         . ($t['asset_tag'] ? '<span class="kb-chip">' . e($t['asset_tag']) . '</span>' : '') . '</div>'
         . '<footer class="kb-foot"><span>' . e($t['ref']) . '</span><span title="Age">' . ($age <= 0 ? 'today' : $age . 'd') . '</span>'
         . ($who !== '' ? '<span class="kb-av" title="' . e($who) . '">' . e(inc_board_initials($who)) . '</span>' : '') . '</footer></article>';
};

if ($view === 'board') {
    require_once __DIR__ . '/../../inc/board.php';
    $cols = [];
    foreach (it_statuses() as $k => $label) {
        $cols[$k] = [];
    }
    foreach ($rows as $t) {
        $cols[$t['status']][] = $t;
    }
    echo '<div class="kb" data-kind="it" data-move="1">' . fabo_csrf_field() . '<div class="kb-flash" role="status" aria-live="polite" hidden></div><div class="kb-cols">';
    foreach (it_statuses() as $k => $label) {
        echo '<section class="kb-col' . ($k === 'closed' ? ' kb-final' : '') . '" data-stage="' . e($k) . '" aria-label="' . e($label) . '">'
           . '<header class="kb-colhead"><span class="kb-colname">' . e($label) . '</span><span class="kb-count">' . count($cols[$k]) . '</span></header><div class="kb-list">';
        foreach (array_slice($cols[$k], 0, 80) as $t) {
            echo $card($t);
        }
        echo !$cols[$k] ? '<div class="kb-empty">Nothing here</div>' : '';
        echo '</div>' . ($k === 'closed' ? '<a class="kb-more" href="' . e(it_url('tickets', ['view' => 'list', 'status' => 'closed'])) . '">All closed →</a>' : '') . '</section>';
    }
    echo '</div></div>';
} elseif (!$rows) {
    echo inc_empty(it_w('No tickets match.'), $fq ? 'Try clearing some filters.' : it_w('Log the first one with + New ticket.'));
} else {
    echo it_w('<div class="scrollx"><table class="data stack inc-table"><thead><tr><th>Ticket</th><th>Requester</th><th>Category</th><th>Priority</th><th>Status</th><th>Assigned</th><th>Target</th><th>Opened</th></tr></thead><tbody>');
    foreach ($rows as $t) {
        echo it_w('<tr><td data-l="Ticket"><a data-popup href="') . e(it_url('work', ['id' => $t['id']])) . '"><strong>' . e($t['title']) . '</strong></a><div class="muted small">' . e($t['ref']) . '</div></td>'
           . '<td data-l="Requester">' . e($t['requester_name']) . '<div class="muted small">' . e((string) $t['requester_email']) . '</div></td>'
           . '<td data-l="Category">' . e($t['category'] ?: '—') . '</td><td data-l="Priority">' . it_priority_badge($t['priority']) . '</td>'
           . '<td data-l="Status">' . it_status_badge($t['status']) . '</td><td data-l="Assigned">' . e(it_person_name($t['assignee_id'] ? (int) $t['assignee_id'] : null) ?: '—') . '</td>'
           . '<td data-l="Target" class="nowrap' . (it_is_overdue($t) ? ' tone-risk' : '') . '">' . ($t['due_at'] ? e(inc_fmt_datetime($t['due_at'])) : '—') . '</td>'
           . '<td data-l="Opened" class="nowrap">' . e(inc_fmt_date($t['created_at'])) . '</td></tr>';
    }
    echo '</tbody></table></div>';
}

it_layout_end();

<?php
/**
 * The helpdesk's shared pieces: the progress tracker, the conversation, and
 * the request cards. Used by pages my, myticket (the person who asked) and
 * queue, work (the team). See it_self_org() in core.php for who sees them.
 */

function it_hd_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $i = mb_strtoupper(mb_substr($parts[0] ?? '?', 0, 1) . (count($parts) > 1 ? mb_substr((string) end($parts), 0, 1) : ''));
    return $i !== '' ? $i : '?';
}

function it_hd_pill(string $status, bool $plain = true): string
{
    return '<span class="hd-pill ' . e(it_status_tone($status)) . '">' . e($plain ? it_status_plain($status) : (it_statuses()[$status] ?? $status)) . '</span>';
}

function it_hd_urgency(string $p): string
{
    return '<span class="hd-urg ' . e($p) . '">' . e(it_urgency_plain()[$p][0] ?? $p) . '</span>';
}

/** Submitted → In progress → Resolved → Closed, with the current step lit. */
function it_hd_track(array $t): string
{
    $steps = ['Submitted', 'In progress', 'Resolved', 'Closed'];
    $at = ['new' => 0, 'in_progress' => 1, 'waiting' => 1, 'resolved' => 2, 'closed' => 4][$t['status']] ?? 0;
    $h = '<ol class="hd-track" aria-label="Progress">';
    foreach ($steps as $i => $label) {
        $cls = $i < $at ? 'done' : ($i === $at ? 'now' : '');
        if ($i === 1 && $t['status'] === 'waiting') {
            $label = 'Needs your reply';
        }
        $h .= '<li class="' . $cls . '"' . ($cls === 'now' ? ' aria-current="step"' : '') . '>' . e($label) . '</li>';
    }
    return $h . '</ol>';
}

/**
 * The conversation, oldest first. $team: the viewer is on the team side
 * (sees internal notes; the team's messages are "mine"). Status changes show
 * as small events; the rest of the system's housekeeping is left out.
 */
function it_hd_chat(array $t, bool $team): string
{
    $h = '<div class="hd-chat">';
    $msg = static function (string $who, string $role, string $when, string $body, string $cls, string $tag = '') {
        return '<div class="hd-msg ' . $cls . '"><div class="hd-av" aria-hidden="true">' . e(it_hd_initials($who)) . '</div><div>'
             . '<div class="hd-who"><strong>' . e($who) . '</strong> · ' . e($role) . ' · ' . e(inc_fmt_datetime($when)) . ($tag !== '' ? ' · ' . $tag : '') . '</div>'
             . '<div class="hd-bub">' . nl2br(e($body)) . '</div></div></div>';
    };
    // The title is the page's heading already; the first message is what they wrote.
    $h .= $msg((string) ($t['requester_name'] ?: 'Requester'), 'Raised the request', (string) $t['created_at'],
               (string) ($t['description'] ?: $t['title']), $team ? 'rq' : 'rq me');
    foreach (it_notes((int) $t['id']) as $n) {
        switch ($n['kind']) {
            case 'requester':
                $h .= $msg((string) $n['actor'], 'Requester', (string) $n['created_at'], (string) $n['body'], $team ? 'rq' : 'rq me');
                break;
            case 'reply':
                $h .= $msg((string) $n['actor'], (string) it_cfg('support'), (string) $n['created_at'], (string) $n['body'], $team ? 'team me' : 'team');
                break;
            case 'internal':
                if ($team) {
                    $h .= $msg((string) $n['actor'], 'Internal note', (string) $n['created_at'], (string) $n['body'], 'team me note', 'only the team sees this');
                }
                break;
            default:
                // Only the moments a reader cares about: who picked it up, fixed, reopened, closed.
                $b = (string) $n['body'];
                if (preg_match('/^Status: (.*) → (.*)\.$/u', $b, $m)) {
                    $from = (string) array_search($m[1], it_statuses(), true);
                    $to = (string) array_search($m[2], it_statuses(), true);
                    if ($to === 'resolved') {
                        $b = 'Marked as fixed';
                    } elseif ($to === 'closed') {
                        $b = 'Closed';
                    } elseif (in_array($from, ['resolved', 'closed'], true)) {
                        $b = 'Reopened';
                    } else {
                        break;               // new → in progress, waiting and back: the messages say it
                    }
                } elseif (preg_match('/^(Taken by .*|Assigned to .*)\.$/u', $b, $m)) {
                    $b = $m[1];
                } else {
                    break;
                }
                $h .= '<div class="hd-event">' . e(rtrim($b, '.')) . ' · ' . e(inc_fmt_datetime((string) $n['created_at'])) . '</div>';
        }
    }
    return $h . '</div>';
}

/** One request as a card. $href opens it; $side is extra HTML on the right (a button). */
function it_hd_card(array $t, string $href, bool $team, string $side = ''): string
{
    $tone = it_status_tone($t['status']);
    $late = it_is_overdue($t);
    $meta = e($t['ref']) . ($t['category'] ? ' · ' . e($t['category']) : '') . ' · ' . ($team ? 'from ' . e($t['requester_name']) . ' · ' : '')
          . 'updated ' . e(inc_fmt_datetime((string) $t['updated_at']));
    $next = $team
        ? ($t['assignee_id'] ? 'With <strong>' . e(it_person_name((int) $t['assignee_id'])) . '</strong>' : '<strong>Nobody has taken it yet</strong>')
          . ($t['due_at'] && !in_array($t['status'], ['resolved', 'closed'], true) ? ' · target ' . e(inc_fmt_datetime((string) $t['due_at'])) : '')
          . ($late ? ' · <strong style="color:var(--red)">overdue</strong>' : '')
        : e(it_next_plain($t));
    return '<div class="hd-card t-' . e($tone) . '"><div><a class="hd-card-title" href="' . e($href) . '">' . e($t['title']) . '</a>'
         . '<div class="hd-card-meta">' . $meta . '</div></div>'
         . '<div class="hd-card-side">' . it_hd_pill($t['status'], !$team) . ($team ? it_hd_urgency((string) $t['priority']) : '') . $side . '</div>'
         . '<div class="hd-card-next">' . $next . '</div></div>';
}

/** The tabs over a list: key => [label, count]. */
function it_hd_tabs(string $page, array $tabs, string $on, array $keep = []): string
{
    $h = '<nav class="hd-tabs" aria-label="Lists">';
    foreach ($tabs as $k => [$label, $n]) {
        $h .= '<a href="' . e(it_url($page, ['tab' => $k] + $keep)) . '"' . ($k === $on ? ' class="on" aria-current="page"' : '') . '>' . e($label) . '<b>' . (int) $n . '</b></a>';
    }
    return $h . '</nav>';
}

/** The facts beside a request. */
function it_hd_facts(array $t): string
{
    $u = it_urgency_plain()[$t['priority']] ?? [$t['priority'], ''];
    $rows = ['Reference' => e($t['ref']), 'Category' => e($t['category'] ?: '—'), 'Urgency' => it_hd_urgency((string) $t['priority']) . ' <span class="hd-card-meta">' . e($u[1]) . '</span>',
             'Handled by' => e($t['assignee_id'] ? it_person_name((int) $t['assignee_id']) : 'Not taken yet'),
             'Raised' => e(inc_fmt_datetime((string) $t['created_at'])) . ' by ' . e($t['requester_name'])];
    if (!empty($t['asset_tag'])) {
        $rows[it_w('Device')] = e($t['asset_tag'] . ($t['asset_name'] ? ' — ' . $t['asset_name'] : ''));
    }
    if ($t['due_at'] && !in_array($t['status'], ['resolved', 'closed'], true)) {
        $rows['Aim to fix by'] = e(inc_fmt_datetime((string) $t['due_at'])) . (it_is_overdue($t) ? ' <span class="hd-pill red">Overdue</span>' : '');
    }
    if ($t['resolved_at']) {
        $rows['Resolved'] = e(inc_fmt_datetime((string) $t['resolved_at']));
    }
    $h = '<dl class="hd-facts">';
    foreach ($rows as $k => $v) {
        $h .= '<div><dt>' . e($k) . '</dt><dd>' . $v . '</dd></div>';
    }
    return $h . '</dl>';
}

/** Someone on the team takes an unassigned request: it is theirs, in progress, and the requester hears who has it. */
function it_hd_take(int $id, int $personId): void
{
    $t = it_ticket($id) ?? throw new InvalidArgumentException('That request no longer exists.');
    if ($t['assignee_id']) {
        throw new InvalidArgumentException(((int) $t['assignee_id'] === $personId ? 'It is already yours.' : it_person_name((int) $t['assignee_id']) . ' has already taken ' . $t['ref'] . '.'));
    }
    it_exec('UPDATE it_tickets SET assignee_id = ? WHERE id = ? AND assignee_id IS NULL', 'ii', [$personId, $id]);
    it_note($id, 'system', 'Taken by ' . it_actor() . '.');
    if ($t['status'] === 'new') {
        it_ticket_status($id, 'in_progress', false);
    }
    it_bell($t['requester_id'] ? (int) $t['requester_id'] : null, it_actor() . ' is working on ' . $t['ref'], $t['title'], 'myticket', $id);
}

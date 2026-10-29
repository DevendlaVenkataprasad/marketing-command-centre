<?php
/**
 * Incubation Management — a team member's dashboard: the startups they own or their
 * teams hold, new and reassigned work, tasks, follow-ups and evaluations.
 * Scoped to that person — nothing outside their own and their teams' records.
 */

$people = inc_people_with_role();
$pid = inc_int_or_null($_GET['person'] ?? null);
if ($pid && !isset($people[$pid])) {
    $pid = null;
}
inc_layout_start('Team dashboard', 'What one team member is looking after.', ['Team dashboard' => null]);
echo '<form method="get" class="card inc-row"><input type="hidden" name="p" value="team"><label>Team member '
   . inc_select('person', $people, $pid, 'Choose…', 'data-autosubmit') . '</label><noscript><button class="btn">Show</button></noscript></form>';
if (!$people) {
    echo inc_empty('No team members in incubation teams yet.', 'Add them under <a href="' . e(inc_url('settings', ['tab' => 'teams'])) . '">Settings → Teams</a>.');
}
if ($pid) {
    $d = inc_person_dashboard($pid);
    $open = array_filter($d['workspaces'], static fn($w) => $w['closed_at'] === null);
    $mine = array_filter($open, static fn($w) => (int) $w['owner_id'] === $pid);
    echo '<div class="tiles inc-tiles">' . inc_tile('Startups owned', (string) count($mine)) . inc_tile('Team startups (open)', (string) count($open))
       . inc_tile('New assignments (14 days)', (string) count($d['new']), '', count($d['new']) ? 'green' : '')
       . inc_tile('Reassignments (30 days)', (string) count($d['reassigned'])) . inc_tile('Open tasks', (string) count($d['tasks']), '', count($d['tasks']) ? 'amber' : '')
       . inc_tile('Follow-ups due in 14 days', (string) count($d['followups'])) . inc_tile('Their evaluations in progress', (string) count($d['evals'])) . '</div>';

    echo '<div class="inc-grid2"><section class="card"><h2>Assigned startups</h2>';
    if (!$d['workspaces']) {
        echo inc_empty('Nothing assigned.');
    } else {
        echo '<table class="data stack"><thead><tr><th>Startup</th><th>Incubation stage</th><th>Development</th><th>Owner</th></tr></thead><tbody>';
        foreach ($d['workspaces'] as $w) {
            echo '<tr><td data-l="Startup"><a href="' . e(inc_url('workspace', ['id' => $w['id']])) . '">' . e($w['startup_name'] ?: $w['applicant_name']) . '</a> <span class="muted small">' . e($w['ref']) . '</span>'
               . ($w['closed_at'] ? ' ' . inc_badge('Closed') : '') . ($w['last_reassigned_at'] ? ' ' . inc_badge('Reassigned', 'blue') : '') . '</td>'
               . '<td data-l="Stage">' . e($w['stage_name']) . '</td><td data-l="Development">' . e($w['dev_stage'] ? (inc_dev_stages()[$w['dev_stage']] ?? '') : '—') . '</td>'
               . '<td data-l="Owner">' . e(inc_person_name((int) $w['owner_id'])) . '</td></tr>';
        }
        echo '</tbody></table>';
    }
    $stages = [];
    foreach ($open as $w) {
        $stages[$w['stage_name']] = ($stages[$w['stage_name']] ?? 0) + 1;
    }
    echo '<h3>By incubation stage</h3>' . inc_bars($stages, 'No startups in incubation.') . '</section>';

    echo '<section class="card"><h2>New and reassigned</h2>';
    if (!$d['new'] && !$d['reassigned']) {
        echo '<p class="muted small">No recent assignments.</p>';
    }
    foreach ($d['new'] as $a) {
        echo '<div class="small">' . inc_badge('New', 'green') . ' <a href="' . e(inc_url('workspace', ['id' => $a['workspace_id']])) . '">' . e($a['startup_name'] ?: $a['applicant_name']) . '</a> · ' . e(inc_fmt_datetime($a['created_at'])) . '</div>';
    }
    foreach ($d['reassigned'] as $a) {
        $in = (int) $a['owner_id'] === $pid;
        echo '<div class="small">' . inc_badge($in ? 'Reassigned to them' : 'Reassigned away', 'blue') . ' <a href="' . e(inc_url('workspace', ['id' => $a['workspace_id']])) . '">' . e($a['startup_name'] ?: $a['applicant_name'])
           . '</a> · ' . e($in ? 'from ' . inc_person_name((int) $a['prev_owner_id']) : 'to ' . inc_person_name((int) $a['owner_id'])) . ' · ' . e(inc_fmt_datetime($a['created_at'])) . '</div>';
    }
    echo '<h3>Pending tasks</h3>';
    if (!$d['tasks']) {
        echo '<p class="muted small">None.</p>';
    }
    foreach ($d['tasks'] as $t) {
        $over = $t['due_date'] && $t['due_date'] < date('Y-m-d');
        echo '<div class="small">' . e($t['title']) . ($t['app_ref'] ? ' · ' . e($t['startup_name'] ?: $t['app_ref']) : '') . ($t['due_date'] ? ' · due ' . e(inc_fmt_date($t['due_date'])) : '') . ($over ? ' ' . inc_badge('Overdue', 'red') : '') . '</div>';
    }
    echo '<h3>Upcoming follow-ups</h3>';
    if (!$d['followups']) {
        echo '<p class="muted small">None in the next 14 days.</p>';
    }
    foreach ($d['followups'] as $t) {
        echo '<div class="small">' . e(inc_fmt_date($t['due_date'])) . ' · ' . e($t['title']) . ($t['startup_name'] ? ' · ' . e($t['startup_name']) : '') . '</div>';
    }
    echo '<h3>Their evaluations</h3>';
    if (!$d['evals']) {
        echo '<p class="muted small">None open.</p>';
    }
    foreach ($d['evals'] as $e) {
        echo '<div class="small"><a href="' . e(inc_url('evaluation', ['id' => $e['id']])) . '">#' . (int) $e['id'] . '</a> ' . e($e['startup_name'] ?: $e['applicant_name']) . ' · ' . inc_eval_badge($e['status']) . '</div>';
    }
    echo '</section></div>';
    echo '<section class="card"><h2>Recent activity on their startups</h2>' . inc_timeline_html($d['activity']) . '</section>';
}
inc_layout_end();

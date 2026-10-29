<?php
/**
 * Incubation Management — incubation workspaces. A board by lifecycle stage
 * (drag to move) by default; the list keeps the detail columns.
 */

require_once __DIR__ . '/../board.php';
require_once __DIR__ . '/../stages_ui.php';

$f = [
    'q'        => trim((string) ($_GET['q'] ?? '')),
    'owner_id' => inc_int_or_null($_GET['owner_id'] ?? null),
    'team_id'  => inc_int_or_null($_GET['team_id'] ?? null),
    'stage_id' => inc_int_or_null($_GET['stage_id'] ?? null),
    'open'     => in_array($_GET['open'] ?? '', ['open', 'closed'], true) ? (string) $_GET['open'] : '',
];
$view = ($_GET['view'] ?? '') === 'list' ? 'list' : 'board';
$fq = array_filter($f, static fn($v) => $v !== '' && $v !== null);
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    if ($a === 'board_move') {
        inc_board_move('ws');
    }
    // Lifecycle stages are added and edited from this board's columns.
    $flash = inc_action(static function () use ($a) {
        $msg = inc_stage_post($a);
        if ($msg === null) {
            throw new InvalidArgumentException('Unknown action.');
        }
        return $msg;
    }, inc_url('workspaces', ($view === 'list' ? ['view' => 'list'] : []) + $fq));
}
$rows = inc_workspaces_list($f);
inc_layout_start('Applications', 'Incubations: ' . count($rows) . ' startup' . (count($rows) === 1 ? '' : 's') . ' in incubation, each with its own lifecycle'
                 . ($view === 'board' && inc_can('stage') ? ' · drag a card to move it' : ''), [], inc_view_switch('workspaces', $view, $fq), $flash);
echo inc_apps_tabs('workspaces');

$n = count(array_diff_key($fq, ['q' => 1]));
echo '<form method="get" class="inc-filters"><input type="hidden" name="p" value="workspaces">' . ($view === 'list' ? '<input type="hidden" name="view" value="list">' : '')
   . '<div class="inc-fbar"><input type="search" name="q" value="' . e($f['q']) . '" placeholder="Search incubation ID, application or startup" aria-label="Search">'
   . '<button class="btn btn-primary">Search</button>'
   . '<details class="inc-fmore"><summary class="btn">Filters' . ($n ? ' <span class="inc-fcount">' . $n . '</span>' : '') . '</summary><div class="inc-fpanel"><div class="inc-fgrid">'
   . ($view === 'list' ? '<label><span>Lifecycle stage</span>' . inc_select('stage_id', inc_stage_map('lifecycle'), $f['stage_id'], 'Any') . '</label>' : '')
   . '<label><span>Team</span>' . inc_select('team_id', inc_teams(false), $f['team_id'], 'Any') . '</label>'
   . '<label><span>Owner</span>' . inc_select('owner_id', inc_people_with_role(), $f['owner_id'], 'Any') . '</label>'
   . '<label><span>Open or closed</span>' . inc_select('open', ['open' => 'Open', 'closed' => 'Closed'], $f['open'], 'Both') . '</label>'
   . '</div><div class="inc-factions"><a class="btn" href="' . e(inc_url('workspaces', $view === 'list' ? ['view' => 'list'] : [])) . '">Clear all</a><button class="btn btn-primary">Apply filters</button></div></div></details></div></form>';

if (!$rows && !$fq && $view === 'list') {
    echo inc_empty('No startups in incubation yet.', 'An incubation record is created when an application is assigned for incubation.');
} elseif ($view === 'board') {
    echo inc_board_html(inc_board_group('lifecycle', $rows), 'ws', static fn($sid) => inc_url('workspaces', ['view' => 'list', 'stage_id' => $sid] + $fq));
} elseif (!$rows) {
    echo inc_empty('No incubations match.', 'Try clearing some filters.');
} else {
    echo '<div class="scrollx"><table class="data stack"><thead><tr><th>Startup</th><th>Incubation stage</th><th>Development</th><th>Team · owner</th>'
       . '<th class="num">Score</th><th>Since</th><th>Progress</th></tr></thead><tbody>';
    foreach ($rows as $w) {
        $p = inc_ws_progress((int) $w['id']);
        echo '<tr><td data-l="Startup"><a href="' . e(inc_url('workspace', ['id' => $w['id']])) . '"><strong>' . e($w['startup_name'] ?: $w['applicant_name']) . '</strong></a>'
           . '<div class="muted small">' . e($w['ref']) . ' · ' . e($w['app_ref']) . '</div>'
           . ($w['closed_at'] ? inc_badge('Closed') . ' ' : '') . ($w['last_reassigned_at'] ? inc_badge('Reassigned', 'blue') : '') . '</td>'
           . '<td data-l="Stage">' . e($w['stage_name']) . '</td><td data-l="Development">' . e($w['dev_stage'] ? (inc_dev_stages()[$w['dev_stage']] ?? '') : '—') . '</td>'
           . '<td data-l="Team · owner">' . e(inc_team_name((int) $w['team_id'])) . '<div class="muted small">' . e(inc_person_name((int) $w['owner_id'])) . '</div></td>'
           . '<td data-l="Score" class="num">' . ($w['official_score'] !== null ? e(inc_fmt_score($w['official_score'])) : '<span class="muted">—</span>') . '</td>'
           . '<td data-l="Since" class="nowrap">' . e(inc_fmt_date($w['stage_since'])) . '</td>'
           . '<td data-l="Progress"><div class="inc-mini-bar" title="' . (int) $p['pct'] . '%"><i style="width:' . (int) $p['pct'] . '%"></i></div> <span class="small muted">' . (int) $p['pct'] . '%</span></td></tr>';
    }
    echo '</tbody></table></div>';
}
inc_layout_end();

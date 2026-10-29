<?php
/**
 * Incubation Management — applications. The board (one column per pipeline
 * stage: drag a card to move it, tick cards to act on them, add or edit
 * stages from the columns) is the default; the list keeps sorting and paging.
 * Incubations open as a tab of this page (inc_apps_tabs()).
 */

require_once __DIR__ . '/parts.php';
require_once __DIR__ . '/../board.php';
require_once __DIR__ . '/../stages_ui.php';
require_once __DIR__ . '/../appreport.php';

$f = inc_app_filters($_GET);
// One list for everything; a department's applications are a filter (Department), not a tab of their own.
unset($f['route']);
$fx = $f;
$sort = isset(inc_app_sorts()[$_GET['sort'] ?? '']) ? (string) $_GET['sort']
      // A Gem stage reads best highest score first.
      : (!empty($f['stage_id']) && function_exists('inc_stage_gem_ids') && inc_stage_gem_ids(inc_stage((int) $f['stage_id'])) ? 'gem' : 'newest');
// Board unless the list is asked for -- or a link narrows to one stage, which reads better as a list.
$want = (string) ($_GET['view'] ?? '');
$view = $want === 'board' ? 'board' : (in_array($want, ['list', 'cards', 'table'], true) || !empty($_GET['stage_id']) ? 'list' : 'board');
$pg = max(1, (int) ($_GET['pg'] ?? 1));
$keep = ['view' => $view];
// Ticked cards are remembered per set of filters: change a filter and the selection starts afresh.
$selKey = 'apps.' . substr(md5(json_encode(inc_filter_query($f, 'newest'))), 0, 10);
$q = inc_filter_query($f, $sort) + $keep;
$self = inc_url('applications', $q + ($pg > 1 ? ['pg' => $pg] : []));

$flash = null;
/* A download of what the page shows: the current filters, or one stage (?stage_id=). */
if (in_array($_GET['download'] ?? '', ['xlsx', 'stages', 'pdf', 'list'], true) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $st = !empty($f['stage_id']) ? inc_stage((int) $f['stage_id']) : null;
        inc_report_download((string) $_GET['download'], inc_app_ids_matching($f), $st ? (string) $st['name'] : ($fx ? 'filtered' : 'all'));
    } catch (InvalidArgumentException $e) {
        $flash = ['err', $e->getMessage()];
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    if ($a === 'board_move') {
        inc_board_move('app');
    } elseif ($a === 'bulk') {
        $flash = inc_bulk_handle($self);
        // Back to a GET, so a refresh neither resubmits nor keeps showing the message.
        if ($flash) {
            inc_redirect($self, $flash);
        }
    } else {
        $flash = inc_action(static function () use ($a) {
            $msg = inc_stage_post($a);
            if ($msg === null) {
                throw new InvalidArgumentException('Unknown action.');
            }
            return $msg;
        }, $self);
    }
}

if ($view === 'board') {
    $cols = inc_board_apps($f);
    $total = array_sum(array_column($cols, 'total'));
} else {
    [$rows, $total] = inc_app_list($f, $sort, $pg, 25);
}

$dlStage = !empty($f['stage_id']) ? inc_stage((int) $f['stage_id']) : null;
$acts = inc_view_switch('applications', $view, inc_filter_query($f, $sort))
      . ' ' . inc_download_menu(static fn($k) => inc_url('applications', ['download' => $k] + inc_filter_query($f, $sort)),
                                $dlStage ? 'All ' . number_format($total) . ' at "' . $dlStage['name'] . '"' : 'All ' . number_format($total) . ($fx ? ' matching the filters' : ' applications'),
                                '', !$dlStage)
      . (inc_can('edit') ? ' <a class="btn btn-primary" href="' . e(inc_url('new')) . '">+ New application</a>' : '');
inc_layout_start('Applications', number_format($total) . ' application' . ($total === 1 ? '' : 's') . ($fx ? ' match your filters' : '')
                 . ($view === 'board' ? ' · drag a card to move it, tick cards to email or update them' : ''), [], $acts, $flash);
echo inc_apps_tabs('applications');
echo inc_filter_bar('applications', $f, $sort, true, $keep);

if ($view === 'board') {
    // The action bar is its own form; the cards' tick boxes join it with form="…", so the
    // stage menus on the columns can stay forms of their own.
    echo '<form method="post" id="inc-bulkform" class="inc-bulkform">' . fabo_csrf_field() . inc_bulk_panel($f, $total, $selKey) . '</form>';
    echo inc_board_html($cols, 'app', static fn($sid) => inc_url('applications', ['view' => 'list', 'stage_id' => $sid] + inc_filter_query($f, $sort)), 'inc-bulkform',
                        static fn($sid, $k) => inc_url('applications', ['download' => $k, 'stage_id' => $sid] + inc_filter_query($f, $sort)));
} else {
    echo '<form method="post" class="inc-bulkform">' . fabo_csrf_field();
    echo inc_bulk_panel($f, $total, $selKey);
    echo !$rows ? inc_empty('No applications match.', $fx ? 'Try clearing some filters.' : 'Applications appear here as soon as they are submitted.') : inc_table_html($rows);
    echo '</form>';
    echo inc_pager($total, 25, $pg, $q, 'applications');
}
echo inc_bulk_runs_html();

inc_layout_end();

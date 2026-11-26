<?php
/**
 * Admin — Sheets. Everything a sheet is made of, managed in one place.
 *
 * Three things get maintained here, and they are three tabs because they
 * are three different questions:
 *
 *   Sheets   — what each sheet is called, and which ones exist at all.
 *   Columns  — what the columns of one sheet are called, what they hold,
 *              and (where the sheet was built here) which ones there are.
 *   Options  — what each dropdown offers, in the order it offers it.
 *
 * A shipped sheet can be renamed but not restructured; one built here can
 * be anything. That is the only difference between them, and the page says
 * so rather than hiding one kind from the other.
 */

require_once __DIR__ . '/../lib/ui.php';
require_once __DIR__ . '/../lib/sheetbuilder.php';
require_once __DIR__ . '/../lib/sheetadmin.php';
require_once __DIR__ . '/../lib/lists.php';
require_once __DIR__ . '/../lib/requisitions.php';
require_once __DIR__ . '/../lib/budget.php';
require_once __DIR__ . '/../lib/manage.php';
require_once __DIR__ . '/../lib/dashwidgets.php';
require_once __DIR__ . '/../lib/committees.php';
require_once __DIR__ . '/../lib/mailer.php';     // fabo_event_mail_off()
fabo_boot();

/* Ours at scope 0, a client's at the id of their organisation. Everything
   below carries $scope; a query that does not is a leak between tenants. */
[$viewer, $scope] = fabo_manage_open('sheets');
$flash  = null;

/* Opened from charts.php: the Dashboard tab only, and only chart actions. */
$chartsOnly = !empty($GLOBALS['fabo_charts_only']);
$desk       = $chartsOnly ? 'charts.php' : 'sheets.php';

/* The chart builder's live preview: one chart drawn from the sheet's real
   rows, as HTML, for the Dashboard tab to drop into its preview box. Behind
   the same gate as the rest of this desk, and it changes nothing. */
if (($_GET['dwpreview'] ?? '') !== '') {
    header('Content-Type: text/html; charset=utf-8');
    $pk = (string) ($_GET['sheet'] ?? '');
    $ps = $pk !== '' ? fabo_sheet($pk) : null;
    if (!$ps || !fabo_manage_sheet_ok($viewer, $scope, $pk)) {
        http_response_code(403);
        echo '<div class="empty">That sheet is not yours to chart.</div>';
        exit;
    }
    try {
        $c = fabo_dw_clean($pk, $_GET);
        echo '<h3>' . e($c['title']) . '</h3>'
           . fabo_dw_draw(['chart' => $c['chart'], 'metric' => $c['metric'],
                           'compare_metric' => $c['compare'], 'group_by' => $c['group'],
                           'top_n' => $c['top']], $ps, fabo_rows($pk, $viewer));
    } catch (InvalidArgumentException $e) {
        echo '<div class="empty">' . e($e->getMessage()) . '</div>';
    }
    exit;
}

/* ------------------------------------------------------------- actions */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    fabo_csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $back   = (string) ($_POST['back'] ?? $desk);
    /* Back to this desk only. The value arrives in the form, so anything that
       is not one of its own pages -- "//elsewhere", "https://..." -- is
       replaced rather than handed to the Location header. */
    if (!preg_match('~^(sheets|charts)\.php(\?[^\r\n#]*)?$~', $back)) {
        $back = $desk;
    }
    try {
        if ($chartsOnly && !str_starts_with($action, 'dw_')) {
            throw new InvalidArgumentException('Only dashboard charts can be changed here.');
        }
        /* A chart is changed only by someone who may edit the sheet it draws
           from -- the same rule dw_add already applied. */
        if (str_starts_with($action, 'dw_') && $action !== 'dw_add') {
            $dwChk = fabo_dw_get((int) ($_POST['widget_id'] ?? 0), $scope);
            if (!$dwChk || !fabo_manage_sheet_ok($viewer, $scope, (string) $dwChk['sheet_key'])) {
                throw new InvalidArgumentException('That chart is not yours to change.');
            }
        }
        switch ($action) {

            /* ------------------------------------------------- sheets */

            case 'rename_builtin':
                $key   = (string) ($_POST['sheet_key'] ?? '');
                $label = trim((string) ($_POST['label'] ?? ''));
                if (!fabo_sheet($key)) {
                    throw new InvalidArgumentException('That sheet was not found.');
                }
                if ($label === '') {
                    throw new InvalidArgumentException('Give the sheet a name.');
                }
                if (!fabo_manage_sheet_ok($viewer, $scope, $key)) {
                    throw new InvalidArgumentException('That sheet is not yours to rename.');
                }
                fabo_exec('INSERT INTO sheet_labels (sheet_key, client_id, label, subtitle)
                           VALUES (?,?,?,?)
                           ON DUPLICATE KEY UPDATE label = VALUES(label),
                                                   subtitle = VALUES(subtitle)',
                          'siss', [$key, $scope, mb_substr($label, 0, 120),
                                   mb_substr(trim((string) ($_POST['subtitle'] ?? '')), 0, 255)]);
                $flash = ['ok', 'Renamed. It reads that way everywhere now.'];
                break;

            case 'reset_builtin':
                fabo_exec('DELETE FROM sheet_labels WHERE sheet_key = ? AND client_id = ?',
                          'si', [(string) ($_POST['sheet_key'] ?? ''), $scope]);
                $flash = ['ok', 'Put back to its original name.'];
                break;

            case 'create':
                $id = fabo_builder_create((string) ($_POST['label'] ?? ''), $scope,
                                          (string) ($_POST['subtitle'] ?? ''));
                $sheet = fabo_custom_sheet($id);
                header('Location: sheets.php?tab=columns&sheet='
                       . urlencode((string) ($sheet['sheet_key'] ?? '')));
                exit;

            case 'rename':
                fabo_builder_require((int) $_POST['sheet_id'], $scope);
                fabo_builder_rename((int) $_POST['sheet_id'], (string) ($_POST['label'] ?? ''),
                                    (string) ($_POST['subtitle'] ?? ''));
                $flash = ['ok', 'Saved.'];
                break;

            case 'archive':
                fabo_builder_require((int) $_POST['sheet_id'], $scope);
                fabo_builder_archive((int) $_POST['sheet_id']);
                $flash = ['ok', 'Put away. Its data is untouched and it can come back.'];
                $back  = 'sheets.php';
                break;

            case 'restore':
                fabo_builder_require((int) $_POST['sheet_id'], $scope);
                fabo_builder_restore((int) $_POST['sheet_id']);
                $flash = ['ok', 'Back in the navigation.'];
                $back  = 'sheets.php';
                break;

            /* ------------------------------------------------ columns */

            case 'rename_column':
                if (!fabo_manage_sheet_ok($viewer, $scope, (string) ($_POST['sheet_key'] ?? ''))) {
                    throw new InvalidArgumentException('That sheet is not yours to change.');
                }
                $flash = ['ok', fabo_admin_rename_column((string) ($_POST['sheet_key'] ?? ''),
                                                         (string) ($_POST['field'] ?? ''),
                                                         (string) ($_POST['label'] ?? ''),
                                                         $scope)];
                break;

            case 'reset_column':
                if (!fabo_manage_sheet_ok($viewer, $scope, (string) ($_POST['sheet_key'] ?? ''))) {
                    throw new InvalidArgumentException('That sheet is not yours to change.');
                }
                fabo_admin_reset_column((string) ($_POST['sheet_key'] ?? ''),
                                        (string) ($_POST['field'] ?? ''), $scope);
                $flash = ['ok', 'Put back to the name it shipped with.'];
                break;

            case 'save_order':
                /* The order the columns were dragged into. The whole order
                   is sent, so it replaces whatever was saved rather than
                   being patched into it. */
                $ok = (string) ($_POST['sheet_key'] ?? '');
                if (!fabo_manage_sheet_ok($viewer, $scope, $ok)) {
                    throw new InvalidArgumentException('That sheet is not yours to change.');
                }
                fabo_save_column_order($ok,
                    array_filter(array_map('trim',
                        explode(',', (string) ($_POST['order'] ?? '')))),
                    $scope);
                $flash = ['ok', 'Column order saved. The shared live sheet follows it too.'];
                break;

            case 'reset_order':
                $ok = (string) ($_POST['sheet_key'] ?? '');
                if (!fabo_manage_sheet_ok($viewer, $scope, $ok)) {
                    throw new InvalidArgumentException('That sheet is not yours to change.');
                }
                fabo_reset_column_order($ok, $scope);
                $flash = ['ok', 'Columns are back in the order they were built in.'];
                break;

            case 'hide_column':
            case 'show_column':
                /* Taking a shipped column off a sheet. The column and its
                   data stay in the table — this only stops it being shown,
                   so putting it back brings everything with it. */
                $hk = (string) ($_POST['sheet_key'] ?? '');
                if (!fabo_manage_sheet_ok($viewer, $scope, $hk)) {
                    throw new InvalidArgumentException('That sheet is not yours to change.');
                }
                fabo_hide_column($hk, (string) ($_POST['field'] ?? ''),
                                 $action === 'hide_column', $scope);
                $flash = ['ok', $action === 'hide_column'
                    ? 'Column taken off the sheet. What is already in it is kept, and '
                      . 'putting it back shows it again.'
                    : 'Column back on the sheet.'];
                break;

            case 'event_stage':
                /* Which of Upcoming and Completed Events a column is shown
                   on. The column itself is untouched: it stays on Programs
                   Data, the export and the shared live sheet as it was. */
                if (!fabo_manage_sheet_ok($viewer, $scope, 'events')) {
                    throw new InvalidArgumentException('That sheet is not yours to change.');
                }
                require_once __DIR__ . '/../lib/eventstages.php';
                fabo_evstage_save((string) ($_POST['field'] ?? ''),
                                  (string) ($_POST['stage'] ?? ''), $scope);
                $flash = ['ok', 'Saved. Upcoming and Completed Events show it that way from now on.'];
                break;

            case 'ev_recommended_order':
                /* The Events sheet put in the recommended order: what the
                   event is, when, where and who, the programme, partners,
                   money, sign-off and approvals, then each result beside its
                   target. Saved as this organisation's column order, so it is
                   the order of Programs Data, Upcoming, Completed and the
                   shared live sheet alike -- and can still be dragged after. */
                if (!fabo_manage_sheet_ok($viewer, $scope, 'events')) {
                    throw new InvalidArgumentException('That sheet is not yours to change.');
                }
                require_once __DIR__ . '/../lib/eventstages.php';
                fabo_save_column_order('events', fabo_evstage_recommended(fabo_sheet('events')), $scope);
                $flash = ['ok', 'Events is now in the recommended order on Programs Data, Upcoming, '
                              . 'Completed and the shared live sheet. Drag on the Columns tab to adjust it.'];
                break;

            case 'add_extra':
                if (!fabo_manage_sheet_ok($viewer, $scope, (string) ($_POST['sheet_key'] ?? ''))) {
                    throw new InvalidArgumentException('That sheet is not yours to change.');
                }
                fabo_extra_add((string) ($_POST['sheet_key'] ?? ''), $_POST, $scope);
                $flash = ['ok', 'Column added to the sheet, and to the table underneath it.'];
                break;

            case 'edit_extra':
                fabo_extra_edit((int) ($_POST['extra_id'] ?? 0), $_POST, $scope);
                $flash = ['ok', 'Column saved.'];
                break;

            case 'remove_extra':
                fabo_extra_remove((int) ($_POST['extra_id'] ?? 0), $scope);
                $flash = ['ok', 'Column taken off the sheet. Anything already in it is kept.'];
                break;

            case 'move_extra':
                fabo_extra_move((int) ($_POST['extra_id'] ?? 0), (string) ($_POST['dir'] ?? 'up'), $scope);
                break;

            case 'add_column':
                fabo_builder_require((int) $_POST['sheet_id'], $scope);
                fabo_builder_add_column((int) $_POST['sheet_id'], $_POST);
                $flash = ['ok', 'Column added.'];
                break;

            case 'edit_column':
                fabo_builder_require_column((int) $_POST['column_id'], $scope);
                fabo_builder_edit_column((int) $_POST['column_id'], $_POST);
                $flash = ['ok', 'Column saved.'];
                break;

            case 'move_column':
                fabo_builder_require_column((int) $_POST['column_id'], $scope);
                fabo_builder_move_column((int) $_POST['column_id'], (string) ($_POST['dir'] ?? 'up'));
                break;

            case 'remove_column':
                fabo_builder_require_column((int) $_POST['column_id'], $scope);
                fabo_builder_remove_column((int) $_POST['column_id']);
                $flash = ['ok', 'Column taken off the sheet. Anything already in it is kept.'];
                break;

            /* ---------------------------------------- dashboard charts */

            case 'dw_add':
                $dk = (string) ($_POST['sheet_key'] ?? '');
                if (!fabo_manage_sheet_ok($viewer, $scope, $dk)) {
                    throw new InvalidArgumentException('That sheet is not yours to change.');
                }
                fabo_dw_add($dk, $_POST, $scope);
                $flash = ['ok', 'Chart added. It is on the sheet dashboard now.'];
                break;

            case 'dw_save':
                fabo_dw_save((int) ($_POST['widget_id'] ?? 0), $_POST, $scope);
                $flash = ['ok', 'Chart saved.'];
                break;

            case 'dw_restore':
                fabo_dw_restore_shared((int) ($_POST['widget_id'] ?? 0), $scope);
                $flash = ['ok', 'Chart put back on the dashboard.'];
                break;

            case 'dw_toggle':
                fabo_dw_toggle((int) ($_POST['widget_id'] ?? 0), $scope);
                $flash = ['ok', 'Done.'];
                break;

            case 'dw_delete':
                fabo_dw_delete((int) ($_POST['widget_id'] ?? 0), $scope);
                $flash = ['ok', $scope > 0
                    ? 'Chart removed from your dashboard. No data was touched.'
                    : 'Chart removed from the dashboard. No data was touched.'];
                break;

            case 'dw_move':
                fabo_dw_move((int) ($_POST['widget_id'] ?? 0), (string) ($_POST['dir'] ?? 'up'), $scope);
                break;

            /* ------------------------------------------- requisitions */

            case 'req_type_add':
                fabo_req_type_add((string) ($_POST['name'] ?? ''),
                                  (int) ($_POST['hours'] ?? 0), $scope);
                $flash = ['ok', 'Type added. It is on the requisition form now.'];
                break;

            case 'req_type_save':
                fabo_req_type_save((int) ($_POST['type_id'] ?? 0),
                                   (string) ($_POST['name'] ?? ''),
                                   (int) ($_POST['hours'] ?? 0),
                                   !empty($_POST['active']), $scope);
                $flash = ['ok', 'Saved. Recalculate the queue to apply it to what is waiting.'];
                break;

            case 'req_type_delete':
                fabo_req_type_delete((int) ($_POST['type_id'] ?? 0), $scope);
                $flash = ['ok', 'Type deleted.'];
                break;

            /* --------------------------------------------- budget heads */

            case 'budget_add':
                fabo_budget_add((string) ($_POST['name'] ?? ''),
                                (float) ($_POST['allocated'] ?? 0),
                                (float) ($_POST['pct'] ?? 0), $scope);
                $flash = ['ok', 'Budget head added. It is on the event budget form now.'];
                break;

            case 'budget_save':
                fabo_budget_save((int) ($_POST['item_id'] ?? 0),
                                 (string) ($_POST['name'] ?? ''),
                                 (float) ($_POST['allocated'] ?? 0),
                                 (float) ($_POST['pct'] ?? 0),
                                 !empty($_POST['active']), $scope);
                $flash = ['ok', 'Saved. New requests are checked against it straight away; '
                              . 'what was already asked keeps the figures it was judged on.'];
                break;

            case 'budget_delete':
                fabo_budget_delete((int) ($_POST['item_id'] ?? 0), $scope);
                $flash = ['ok', 'Head removed from the form. What was already asked for is kept.'];
                break;

            case 'budget_move':
                fabo_budget_move((int) ($_POST['item_id'] ?? 0),
                                 (string) ($_POST['dir'] ?? 'up'), $scope);
                $flash = ['ok', 'Moved.'];
                break;

            /* ------------------------------------------------ committees */

            case 'cm_save':
                $cmId = (int) ($_POST['committee_id'] ?? 0);
                fabo_cm_save($scope, $cmId ?: null, (string) ($_POST['name'] ?? ''),
                             (string) ($_POST['rule'] ?? 'all'), (array) ($_POST['members'] ?? []),
                             $viewer['is_admin'] ? 'Admin' : (string) ($viewer['client']['name'] ?? ''),
                             (int) ($_POST['required'] ?? 0));
                $flash = ['ok', $cmId ? 'Committee saved.'
                                      : 'Committee created. Choose it in an event\'s Committee column to send that event to it.'];
                break;

            case 'cm_delete':
                fabo_cm_delete((int) ($_POST['committee_id'] ?? 0), $scope);
                $flash = ['ok', 'Committee deleted.'];
                break;

            case 'cm_remind':
                $rc = fabo_cm_remind_cfg_save($scope, [
                    'on'    => !empty($_POST['on']),
                    'after' => (int) ($_POST['after'] ?? 2),
                    'every' => (int) ($_POST['every'] ?? 2),
                    'hour'  => (int) ($_POST['hour'] ?? 10),
                ]);
                $flash = ['ok', $rc['on']
                    ? 'Reminders saved. Members who have not voted are reminded once a day at most' . (fabo_event_mail_off('committee reminder') ? ' (dashboard notice only; Event Management emails are off)' : '') . ', from '
                      . sprintf('%02d:00', $rc['hour']) . ', every ' . $rc['every'] . ' day' . ($rc['every'] === 1 ? '' : 's') . '.'
                    : 'Automatic reminders are off. You can still remind from the Events sheet.'];
                break;

            case 'cm_auto':
                $ac = fabo_cm_auto_save($scope, (int) ($_POST['committee_id'] ?? 0));
                $flash = ['ok', $ac
                    ? 'Upcoming events now go to ' . fabo_cm_title((string) $ac['name']) . ' on their own. Those waiting are sent within a few minutes; ' . (fabo_event_mail_off('committee') ? 'members see them on their Committee Dashboard (Event Management emails are off).' : 'each member gets one email listing them all.')
                    : 'Automatic assignment is off. Choose a committee in an event\'s Committee column to send it.'];
                break;

            case 'req_settings':
                fabo_setting_put(fabo_req_team_key($scope),
                                 mb_substr(trim((string) ($_POST['team_emails'] ?? '')), 0, 2000));
                $named   = count(fabo_req_team_emails($scope));
                $ignored = array_filter(preg_split('~[,;\s]+~', (string) ($_POST['team_emails'] ?? '')),
                                        static fn($x) => $x !== '' && !filter_var($x, FILTER_VALIDATE_EMAIL));
                $flash = [$ignored ? 'err' : 'ok', 'Saved. ' . ($named
                    ? $named . ' address' . ($named === 1 ? '' : 'es') . ' will be told.'
                    : 'No valid address given, so nobody will be told.')
                    . ($ignored ? ' Not an email address, so left out: ' . implode(', ', $ignored) . '.' : '')];
                break;

            /* ------------------------------------------------ options */

            case 'list_add':
                $flash = ['ok', fabo_admin_list_add((string) ($_POST['list'] ?? ''),
                                                    (string) ($_POST['value'] ?? ''), $scope)];
                break;

            case 'list_create':
                $name  = trim((string) ($_POST['list'] ?? ''));
                $flash = ['ok', fabo_admin_list_create($name, (string) ($_POST['value'] ?? ''), $scope)];
                $back  = 'sheets.php?tab=options&list=' . urlencode($name);
                break;

            case 'list_rename':
                $flash = ['ok', fabo_admin_list_rename((int) ($_POST['option_id'] ?? 0),
                                                       (string) ($_POST['value'] ?? ''), $scope)];
                break;

            case 'list_remove':
                $flash = ['ok', fabo_admin_list_remove((int) ($_POST['option_id'] ?? 0), $scope)];
                break;

            case 'list_restore':
                fabo_admin_list_restore((int) ($_POST['option_id'] ?? 0), $scope);
                $flash = ['ok', 'Back on the list.'];
                break;

            case 'list_move':
                fabo_admin_list_move((int) ($_POST['option_id'] ?? 0),
                                     (string) ($_POST['dir'] ?? 'up'), $scope);
                break;
        }
    } catch (InvalidArgumentException $e) {
        $flash = ['err', $e->getMessage()];
    } catch (Throwable $e) {
        /* Anything the code did not see coming — a unique key most often.
           Caught here so it becomes a sentence on the page rather than a
           blank five-hundred with the work half done. */
        $flash = ['err', fabo_db_words($e)];
    }
    fabo_session();
    if ($flash) {
        $_SESSION['fabo_sheets_flash'] = $flash;
    }
    /* Back where they were, still carrying whatever identified them. A
       client who arrived on a link has a token in the URL and nothing else;
       a redirect that forgets it sends them to the sign-in page after every
       action they take. */
    header('Location: ' . fabo_manage_back($viewer, $back));
    exit;
}

fabo_session();
if (!empty($_SESSION['fabo_sheets_flash'])) {
    $flash = $_SESSION['fabo_sheets_flash'];
    unset($_SESSION['fabo_sheets_flash']);
}

/* --------------------------------------------------------- what to show */

$all   = fabo_admin_sheet_index($scope);
/* A client sees the sheets they may edit and no others: managing the columns
   of a sheet you cannot open is not a thing anybody needs to do, and the list
   itself would say more about what exists than they are entitled to know. */
if ($scope > 0) {
    $mine = fabo_manage_sheets($viewer, $scope);
    $all  = array_values(array_filter($all, static function ($r) use ($mine) {
        return isset($mine[(string) ($r['key'] ?? '')]);
    }));
}
$byKey = [];
foreach ($all as $s) {
    $byKey[$s['key']] = $s;
}

$tab = (string) ($_GET['tab'] ?? 'sheets');
if (!in_array($tab, ['sheets', 'columns', 'dashboard', 'options', 'requisitions', 'budget',
                     'committees', 'evviews'], true)
    || ($tab === 'evviews' && !isset($byKey['events']))) {
    $tab = 'sheets';
}
if ($chartsOnly) {
    $tab = 'dashboard';
}
// An old link said ?edit=<id>; it meant the columns of that built sheet.
if (!empty($_GET['edit'])) {
    $one = fabo_custom_sheet((int) $_GET['edit']);
    if ($one) {
        $tab         = 'columns';
        $_GET['sheet'] = (string) $one['sheet_key'];
    }
}

/* --- the columns tab works on one sheet at a time, named in the query --- */
$pickKey = (string) ($_GET['sheet'] ?? '');
if ($pickKey === '' || !isset($byKey[$pickKey])) {
    $pickKey = $all ? $all[0]['key'] : '';
}
$pick    = $byKey[$pickKey] ?? null;
$cols    = $pick ? fabo_admin_columns($pickKey) : [];
/* Whether these columns have been arranged, so the button that undoes
   it is offered only when there is something to undo. */
$ordered = $pick && fabo_column_order($pickKey) !== [];
$builtOn = ($pick && $pick['built'] && $pick['row'])
         ? fabo_custom_columns((int) $pick['row']['id']) : [];
/* Columns added to a shipped sheet, by the field they are stored in, so the
   table below can find the row behind a column it is already drawing. */
$extras  = [];
if ($pick && !$pick['built']) {
    foreach (fabo_sheet_extras($pickKey, $scope) as $x) {
        $extras[(string) $x['field']] = $x;
    }
}
$words   = fabo_admin_type_words();
/* On Events, each column also says which of Upcoming and Completed Events
   it is shown on. See lib/eventstages.php. */
$evStages = $pick && $pickKey === 'events';
if ($evStages) {
    require_once __DIR__ . '/../lib/eventstages.php';
}

/* --------- the dashboard tab: the charts on the picked sheet ------------ */
$dwSheet   = $pick ? fabo_sheet($pickKey) : null;
$dwList    = $dwSheet ? fabo_dw_list($pickKey, $scope) : [];
$dwMetrics = $dwSheet ? fabo_dw_metrics($dwSheet) : [];
$dwGroups  = $dwSheet ? fabo_dw_groupings($dwSheet) : [];
$dwCharts  = fabo_dw_charts();
$dwWidths  = fabo_dw_widths();

/* --------- the options tab works on one list at a time, likewise -------- */
$lists   = fabo_admin_lists($scope);
$usage   = fabo_admin_list_usage();
$pickList = (string) ($_GET['list'] ?? '');
if ($pickList === '' || !isset($lists[$pickList])) {
    $pickList = $lists ? (string) array_key_first($lists) : '';
}
$options = $lists[$pickList] ?? [];
$usedBy  = $usage[$pickList] ?? [];

/* --------- the requisition queue's own settings, on the fourth tab ----- */
fabo_req_install_types($scope);
$reqTypes   = fabo_req_types(false, $scope);
$budgetItems = fabo_budget_items($scope);

/* How much has already been asked for against each head, so deleting one is
   an informed decision rather than a surprise. */
$budgetUse = [];
foreach (fabo_all('SELECT item_name, COUNT(*) n, SUM(required) total
                   FROM budget_lines GROUP BY item_name') as $r) {
    $budgetUse[(string) $r['item_name']] = ['n' => (int) $r['n'],
                                            'total' => (float) $r['total']];
}
/* The same per-organisation key the Save writes and the mailer reads. Reading
   the bare key here showed the portal-wide list instead, so a saved list
   looked as if it had not been kept. */
$reqTeamRaw  = fabo_setting(fabo_req_team_key($scope), '');
$reqTeamList = fabo_req_team_emails($scope);
/* This organisation's approval committees, for the Committees tab. */
$cmList = [];
try {
    $cmList = fabo_cm_list($scope);
} catch (Throwable $e) {
    error_log('FABO committees list: ' . $e->getMessage());
}
/* How many requisitions are waiting on each type, so a turnaround is never
   changed blind to what it is about to move. */
$reqUse = [];
foreach (fabo_req_open_rows() as $r) {
    $k = (string) ($r['req_type'] ?? '');
    $reqUse[$k] = ($reqUse[$k] ?? 0) + 1;
}

/* --------- the Event Views tab: Upcoming and Completed Events ----------- */
$evvView = in_array((string) ($_GET['view'] ?? ''), ['upcoming', 'completed'], true)
         ? (string) $_GET['view'] : 'upcoming';

/** Self-link that keeps whichever tab the reader is on. */
$here = static function (array $q = []) use ($tab, $pickKey, $pickList, $evvView): string {
    $base = ['tab' => $tab];
    if ($tab === 'columns' || $tab === 'dashboard') { $base['sheet'] = $pickKey; }
    if ($tab === 'options') { $base['list']  = $pickList; }
    if ($tab === 'evviews') { $base['view']  = $evvView; }
    return 'sheets.php?' . http_build_query(array_filter(array_merge($base, $q),
        static fn($v) => $v !== '' && $v !== null));
};

fabo_head($viewer, $chartsOnly ? 'Dashboard Charts' : 'Sheet Management', $chartsOnly ? 'charts' : 'sheets');
?>
<div class="wrap-page">
  <div class="pagehead">
    <?php if ($chartsOnly): ?>
      <h1>DASHBOARD CHARTS</h1>
      <div class="sub">What each sheet's dashboard draws &middot; seen only by your organisation</div>
    <?php else: ?>
      <h1>SHEET MANAGEMENT</h1>
      <div class="sub">Everything a sheet is made of &middot; its name, its columns, and what its dropdowns offer</div>
    <?php endif; ?>
  </div>

<?php if ($flash): ?>
  <div class="flash <?= e($flash[0]) ?>"><?= e($flash[1]) ?></div>
<?php endif; ?>

  <?php if (!$chartsOnly): ?>
  <nav class="sb-tabs">
    <a class="sb-tab<?= $tab === 'sheets' ? ' on' : '' ?>" href="sheets.php?tab=sheets">
      Sheets <span><?= count($all) ?></span></a>
    <a class="sb-tab<?= $tab === 'columns' ? ' on' : '' ?>"
       href="sheets.php?tab=columns&amp;sheet=<?= e($pickKey) ?>">
      Columns <span><?= count($cols) ?></span></a>
    <a class="sb-tab<?= $tab === 'dashboard' ? ' on' : '' ?>"
       href="sheets.php?tab=dashboard&amp;sheet=<?= e($pickKey) ?>">
      Dashboard <span><?= count($dwList) ?></span></a>
    <a class="sb-tab<?= $tab === 'options' ? ' on' : '' ?>"
       href="sheets.php?tab=options&amp;list=<?= e($pickList) ?>">
      Dropdown options <span><?= count($lists) ?></span></a>
    <a class="sb-tab<?= $tab === 'requisitions' ? ' on' : '' ?>"
       href="sheets.php?tab=requisitions">
      Requisition types <span><?= count($reqTypes) ?></span></a>
    <a class="sb-tab<?= $tab === 'budget' ? ' on' : '' ?>"
       href="sheets.php?tab=budget">
      Budget heads <span><?= count($budgetItems) ?></span></a>
    <a class="sb-tab<?= $tab === 'committees' ? ' on' : '' ?>"
       href="sheets.php?tab=committees">
      Committees <span><?= count($cmList) ?></span></a>
    <?php if (isset($byKey['events'])): ?>
      <a class="sb-tab<?= $tab === 'evviews' ? ' on' : '' ?>"
         href="sheets.php?tab=evviews&amp;view=<?= e($evvView) ?>"
         title="The columns, and their order, on Upcoming and Completed Events">
        Event Views</a>
    <?php endif; ?>
  </nav>
  <?php endif; ?>

<?php /* ============================================== tab: dashboard */ ?>
<?php if ($tab === 'dashboard' && $pick && $dwSheet): ?>
  <?php
    /* The chart being edited, if any; otherwise the builder adds a new one. */
    $dwEdit = null;
    if (!empty($_GET['edit'])) {
        $dwEdit = fabo_dw_get((int) $_GET['edit'], $scope);
        if ($dwEdit && (string) $dwEdit['sheet_key'] !== $pickKey) {
            $dwEdit = null;
        }
    }
    $dwOn      = $dwEdit ? fabo_dw_measures($dwEdit) : [];
    $dwChartOn = $dwEdit ? (string) $dwEdit['chart'] : 'column';
    $dwFamilies = [];
    foreach ($dwMetrics as $mk => [$ml, $mkind, $mfam]) {
        $dwFamilies[$mfam][$mk] = $ml;
    }
    $dwRows  = fabo_rows($pickKey, $viewer);
    /* Charts the Events dashboard leaves out because they repeat it: said on
       the card, so a chart never seems to vanish for no reason. */
    $dwRepeat = static fn(array $w): string => '';
    if ($pickKey === 'events') {
        require_once __DIR__ . '/../lib/eventdash.php';
        $dwRepeat = static fn(array $w): string => fabo_evdash_repeats($w, $dwSheet);
    }
    $dwShown = [];
    foreach (fabo_dw_visible($dwList, static fn(array $w) => $dwRepeat($w) !== '') as $v) {
        $dwShown[(int) $v['id']] = true;
    }
    $tokenQ  = !empty($_GET['k']) ? ['k' => (string) $_GET['k']] : [];
    $dwLink  = static fn(array $q) => $desk . '?' . http_build_query(array_merge(
                   ['tab' => 'dashboard', 'sheet' => $pickKey], $tokenQ, $q));
  ?>
  <div class="block">
    <div class="sb-head">
      <div>
        <span class="eyebrow">Dashboard charts</span>
        <h2><?= e($pick['label']) ?></h2>
        <div class="sb-meta"><span class="ro"><?= count($dwList) ?> chart<?= count($dwList) === 1 ? '' : 's' ?>
          &middot; drawn from <?= number_format(count($dwRows)) ?> row<?= count($dwRows) === 1 ? '' : 's' ?></span></div>
      </div>
      <form method="get" class="sb-row">
        <input type="hidden" name="tab" value="dashboard">
        <?php foreach ($tokenQ as $tk => $tv): ?>
          <input type="hidden" name="<?= e($tk) ?>" value="<?= e($tv) ?>">
        <?php endforeach; ?>
        <div class="sb-field"><label>Show another sheet</label>
          <select name="sheet" onchange="this.form.submit()">
            <?php foreach ($all as $s): ?>
              <option value="<?= e($s['key']) ?>"<?= $s['key'] === $pickKey ? ' selected' : '' ?>>
                <?= e($s['label']) ?></option>
            <?php endforeach; ?>
          </select></div>
        <noscript><div class="sb-field"><label>&nbsp;</label><button class="btn btn-sm">Show</button></div></noscript>
      </form>
    </div>

    <?php if ($pickKey === 'events'): ?>
      <p class="sb-lead">The seven fixed figures and the Hub / Spoke-wise section are
        always on the Events dashboard. Charts made here are added between them.</p>
    <?php endif; ?>

    <?php /* ------------------------------------------- the builder */ ?>
    <form method="post" class="dwbuilder" id="dwbuilder"
          data-preview="<?= e($dwLink(['dwpreview' => 1])) ?>">
      <?= fabo_csrf_field() ?>
      <input type="hidden" name="back" value="<?= e($dwLink([])) ?>">
      <input type="hidden" name="action" value="<?= $dwEdit ? 'dw_save' : 'dw_add' ?>">
      <input type="hidden" name="sheet_key" value="<?= e($pickKey) ?>">
      <?php if ($dwEdit): ?>
        <input type="hidden" name="widget_id" value="<?= (int) $dwEdit['id'] ?>">
      <?php endif; ?>

      <div class="dwb-head">
        <h3><?= $dwEdit ? 'Edit chart' : 'Add a chart' ?></h3>
        <?php if ($dwEdit && fabo_dw_is_shared_for($dwEdit, $scope)): ?>
          <span class="ro">Shared chart &mdash; saving makes your own copy; other organisations keep theirs.</span>
        <?php endif; ?>
        <?php if ($dwEdit): ?>
          <a class="btn btn-sm" href="<?= e($dwLink([])) ?>">Cancel editing</a>
        <?php endif; ?>
      </div>

      <div class="dwb-cols">
        <div class="dwb-steps">
          <div class="dwb-step">
            <div class="dwb-n">1</div>
            <div class="dwb-body">
              <label class="dwb-label">How should it look?</label>
              <div class="dwb-types">
                <?php foreach ($dwCharts as $ck => [$cl, $split, $multi, $cwhat]): ?>
                  <label class="dwb-type" title="<?= e($cwhat) ?>">
                    <input type="radio" name="chart" value="<?= e($ck) ?>"
                           data-split="<?= $split ? 1 : 0 ?>" data-multi="<?= $multi ? 1 : 0 ?>"
                           <?= $ck === $dwChartOn ? 'checked' : '' ?>>
                    <span><?= fabo_dw_icon($ck) ?><b><?= e($cl) ?></b></span>
                  </label>
                <?php endforeach; ?>
              </div>
              <div class="dwb-note" id="dwbtypenote"></div>
            </div>
          </div>

          <div class="dwb-step">
            <div class="dwb-n">2</div>
            <div class="dwb-body">
              <label class="dwb-label">What should it measure?
                <small id="dwbcount">Tick one, or up to <?= FABO_DW_MAX_MEASURES ?> to compare them side by side.</small></label>
              <input type="search" class="dwb-find" id="dwbfind" placeholder="Find a column&hellip; e.g. budget, participants">
              <div class="dwb-measures" id="dwbmeasures">
                <?php foreach ($dwFamilies as $fam => $opts): ?>
                  <div class="dwb-fam"><span><?= e($fam) ?></span>
                    <?php foreach ($opts as $mk => $ml): ?>
                      <label class="dwb-m"><input type="checkbox" name="metrics[]" value="<?= e($mk) ?>"
                             <?= in_array($mk, $dwOn, true) ? 'checked' : '' ?>> <?= e($ml) ?></label>
                    <?php endforeach; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>

          <div class="dwb-step" id="dwbsplit">
            <div class="dwb-n">3</div>
            <div class="dwb-body">
              <label class="dwb-label">Split it by</label>
              <div class="sb-row">
                <div class="sb-field grow">
                  <select name="group_by">
                    <option value="">— choose a column —</option>
                    <?php foreach ($dwGroups as $gk => $gl): ?>
                      <option value="<?= e($gk) ?>"<?= $dwEdit && (string) $dwEdit['group_by'] === $gk ? ' selected' : '' ?>><?= e($gl) ?></option>
                    <?php endforeach; ?>
                  </select></div>
                <div class="sb-field"><label>Show the top</label>
                  <input type="number" name="top_n" min="3" max="20" style="width:76px"
                         value="<?= (int) ($dwEdit['top_n'] ?? 8) ?>"></div>
              </div>
            </div>
          </div>

          <div class="dwb-step" id="dwbcompare">
            <div class="dwb-n">3</div>
            <div class="dwb-body">
              <label class="dwb-label">Compare with <small>(optional for tiles, needed for a progress bar)</small></label>
              <select name="compare_metric">
                <option value="">— none —</option>
                <?php foreach ($dwMetrics as $mk => [$ml]): ?>
                  <option value="<?= e($mk) ?>"<?= $dwEdit && (string) $dwEdit['compare_metric'] === $mk ? ' selected' : '' ?>><?= e($ml) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="dwb-step">
            <div class="dwb-n">4</div>
            <div class="dwb-body">
              <div class="sb-row">
                <div class="sb-field grow"><label>Title <small>(left blank, it is made from your choices)</small></label>
                  <input name="title" maxlength="120" value="<?= e((string) ($dwEdit['title'] ?? '')) ?>"
                         placeholder="e.g. Budget allocated vs spent by spoke"></div>
                <div class="sb-field"><label>Width</label>
                  <select name="width">
                    <?php foreach ($dwWidths as $wk => $wl): ?>
                      <option value="<?= e($wk) ?>"<?= (string) ($dwEdit['width'] ?? 'half') === $wk ? ' selected' : '' ?>><?= e($wl) ?></option>
                    <?php endforeach; ?>
                  </select></div>
              </div>
            </div>
          </div>
        </div>

        <div class="dwb-preview">
          <span class="eyebrow">Live preview</span>
          <div class="dwcard" id="dwbpreview"><div class="empty">Pick how it looks and what to measure.</div></div>
          <button class="btn btn-primary" type="submit"><?= $dwEdit ? 'Save changes' : 'Add to dashboard' ?></button>
        </div>
      </div>
    </form>

    <?php /* ------------------------------------- the charts already on it */ ?>
    <h3 style="margin:22px 0 10px">On the dashboard now</h3>
    <?php if (!$dwList): ?>
      <div class="empty">No charts on this sheet yet. Build the first one above.</div>
    <?php else: ?>
      <div class="dwgrid dwadmin">
        <?php $n = 0; foreach ($dwList as $w):
            $n++;
            $shared = fabo_dw_is_shared_for($w, $scope); ?>
          <div class="dwcard dw-<?= e((string) $w['width']) ?><?= (int) $w['active'] === 1 ? '' : ' dwoff' ?>">
            <div class="dwcard-top">
              <h3><?= $n ?>. <?= e((string) $w['title']) ?></h3>
              <span class="ro"><?= e($dwCharts[(string) $w['chart']][0] ?? '') ?><?= (int) $w['active'] === 1 ? '' : ' · hidden' ?></span>
            </div>
            <?php if ((int) $w['active'] === 1 && empty($dwShown[(int) $w['id']])):
                $why = $dwRepeat($w) ?: 'the same as another chart above'; ?>
              <div class="dwnote">Not shown on the dashboard &mdash; <?= e($why) ?>.
                Change what it measures or splits by, or delete it.</div>
            <?php endif; ?>
            <?= fabo_dw_draw($w, $dwSheet, $dwRows) ?>
            <div class="sb-acts dwcard-acts">
                <a class="btn btn-sm btn-primary" href="<?= e($dwLink(['edit' => (int) $w['id']])) ?>#dwbuilder">Edit</a>
                <?php foreach (['dw_toggle' => (int) $w['active'] === 1 ? 'Hide' : 'Show',
                                'dw_move:up' => '&uarr;', 'dw_move:down' => '&darr;'] as $act => $txt):
                    [$a, $dir] = array_pad(explode(':', $act), 2, ''); ?>
                  <form method="post" class="sb-inline">
                    <?= fabo_csrf_field() ?>
                    <input type="hidden" name="back" value="<?= e($dwLink([])) ?>">
                    <input type="hidden" name="action" value="<?= e($a) ?>">
                    <input type="hidden" name="widget_id" value="<?= (int) $w['id'] ?>">
                    <?php if ($dir !== ''): ?><input type="hidden" name="dir" value="<?= e($dir) ?>"><?php endif; ?>
                    <button class="btn btn-sm"><?= $txt ?></button>
                  </form>
                <?php endforeach; ?>
                <form method="post" class="sb-inline"
                      onsubmit="return confirm(<?= e(json_encode($shared
                          ? 'Take this shared chart off your dashboard? Other organisations keep it, and you can put it back below.'
                          : 'Remove this chart from the dashboard? No data is touched.')) ?>);">
                  <?= fabo_csrf_field() ?>
                  <input type="hidden" name="back" value="<?= e($dwLink([])) ?>">
                  <input type="hidden" name="action" value="dw_delete">
                  <input type="hidden" name="widget_id" value="<?= (int) $w['id'] ?>">
                  <button class="btn btn-sm danger">Delete</button>
                </form>
                <?php if ($shared): ?><span class="ro">shared</span><?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php $dwGone = fabo_dw_hidden_shared($pickKey, $scope); if ($dwGone): ?>
      <h3 style="margin:22px 0 8px">Shared charts you removed</h3>
      <div class="sb-acts">
        <?php foreach ($dwGone as $g): ?>
          <form method="post" class="sb-inline">
            <?= fabo_csrf_field() ?>
            <input type="hidden" name="back" value="<?= e($dwLink([])) ?>">
            <input type="hidden" name="action" value="dw_restore">
            <input type="hidden" name="widget_id" value="<?= (int) $g['id'] ?>">
            <button class="btn btn-sm" title="Put it back on your dashboard">&#8634; <?= e((string) $g['title']) ?></button>
          </form>
        <?php endforeach; ?>
      </div>
      <p class="hint">Charts you edited are listed here too: putting one back shows the
        shared original beside your own copy.</p>
    <?php endif; ?>
  </div>

  <script>
  (function () {
    var form = document.getElementById('dwbuilder');
    if (!form) { return; }
    var max = <?= (int) FABO_DW_MAX_MEASURES ?>;
    var box = document.getElementById('dwbpreview');
    var note = document.getElementById('dwbtypenote');
    var count = document.getElementById('dwbcount');
    var timer = null;

    function chosen() { return form.querySelector('input[name="chart"]:checked'); }

    /* Which steps apply to the chart type picked, and how many ticks it takes. */
    function steps() {
      var c = chosen();
      var split = c && c.getAttribute('data-split') === '1';
      var multi = c && c.getAttribute('data-multi') === '1';
      document.getElementById('dwbsplit').hidden = !split;
      document.getElementById('dwbcompare').hidden = split;
      note.textContent = c ? c.closest('label').title : '';
      var ticks = form.querySelectorAll('input[name="metrics[]"]:checked').length;
      count.textContent = multi
        ? (ticks ? ticks + ' of up to ' + max + ' ticked.' : 'Tick one, or up to ' + max + ' to compare them side by side.')
        : 'This chart shows one figure — the first one ticked.';
      Array.prototype.forEach.call(form.querySelectorAll('input[name="metrics[]"]'), function (b) {
        b.disabled = !b.checked && ((multi && ticks >= max) || (!multi && ticks >= 1));
      });
    }

    /* The preview is drawn by the server from the sheet's real rows. */
    function preview() {
      clearTimeout(timer);
      timer = setTimeout(function () {
        var q = new URLSearchParams();
        Array.prototype.forEach.call(form.elements, function (el) {
          if (!el.name || ['csrf', 'back', 'action'].indexOf(el.name) >= 0) { return; }
          if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) { return; }
          q.append(el.name, el.value);
        });
        var url = form.getAttribute('data-preview') + '&' + q.toString();
        box.style.opacity = '.55';
        fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.text(); })
          .then(function (html) { box.innerHTML = html; box.style.opacity = ''; })
          .catch(function () { box.style.opacity = ''; });
      }, 250);
    }

    form.addEventListener('change', function () { steps(); preview(); });
    form.addEventListener('input', function (ev) { if (ev.target.name === 'title') { preview(); } });

    document.getElementById('dwbfind').addEventListener('input', function () {
      var t = this.value.trim().toLowerCase();
      Array.prototype.forEach.call(form.querySelectorAll('.dwb-m'), function (l) {
        l.hidden = t !== '' && l.textContent.toLowerCase().indexOf(t) < 0 && !l.querySelector('input').checked;
      });
      Array.prototype.forEach.call(form.querySelectorAll('.dwb-fam'), function (f) {
        f.hidden = !f.querySelector('.dwb-m:not([hidden])');
      });
    });

    steps();
    preview();
  })();
  </script>
<?php endif; ?>

<?php /* =========================================== tab: event views */ ?>
<?php if ($tab === 'evviews' && isset($byKey['events'])):
    require_once __DIR__ . '/../lib/eventstages.php';
    $evvSheet = fabo_sheet('events');
    $evvUp    = fabo_evstage_layout($evvSheet, 'upcoming');
    $evvDone  = fabo_evstage_layout($evvSheet, 'completed');
    $evvOk    = fabo_evstage_in_order($evvSheet);
    $evvChoices = fabo_evstage_choices();

    /* How a column behaves on one view, in a word. */
    $evvSays = static function (array $c, array $lay): string {
        if (!isset($lay['fields'][$c[1]])) {
            return '<span class="ro">&mdash;</span>';
        }
        if (($c[2] ?? '') === 'id') {
            return '<span class="ro">always first</span>';
        }
        if (isset($lay['readonly'][$c[1]])) {
            return '<span class="ro">read-only</span>';
        }
        if (in_array($c[2], ['computed'], true) || fabo_col_kept($c) !== '') {
            return '<span class="ro">worked out</span>';
        }
        return 'editable';
    };
?>
  <div class="block">
    <div class="sb-head">
      <div>
        <span class="eyebrow">Event Management</span>
        <h2>Programs Data, Upcoming &amp; Completed Events</h2>
        <div class="sb-meta"><span class="ro"><?= count($evvSheet['columns']) ?> columns &middot;
          one order on every page</span></div>
      </div>
      <div class="sb-row">
        <div class="sb-field"><label>&nbsp;</label>
          <a class="btn btn-sm" href="<?= e(fabo_link($viewer, $viewer['is_admin'] ? 'sheet.php' : 'c.php',
                ['sheet' => 'events', 'view' => 'programs'])) ?>">Open Programs Data</a></div>
      </div>
    </div>

    <p class="sb-lead">The Events sheet reads in <strong>one order</strong> on Programs Data,
      Upcoming Events, Completed Events and the shared live sheet: the two views only leave
      columns out, they never move them. <strong>Programs view</strong> decides which view a
      column is on &mdash; <em>Upcoming</em> columns are planning (read-only once the event is
      over), <em>Completed only</em> columns are results, <em>Both</em> are editable on both.
      On the shared live sheet, an <em>Upcoming</em> row is added with the Upcoming columns,
      a <em>Completed</em> row with all of them.</p>

    <div class="sb-order">
      <span class="sb-orderhint"><?= $evvOk
          ? 'The sheet is in the recommended order.'
          : 'The sheet is not in the recommended order: Committee Approval, Hub / Spoke, Event ID, '
            . 'name, type, programme, dates, funding and budget, sector, department, the expected '
            . 'figures (each result beside its target), partners, place, SPOC, approvals, files, '
            . 'status, remarks, checklist, priority.' ?>
        To move a single column, drag it on the
        <a href="sheets.php?tab=columns&amp;sheet=events">Columns</a> tab.</span>
      <form method="post" class="sb-inline"
            onsubmit="return confirm('Put every Events column in the recommended order? This is the order of Programs Data, Upcoming, Completed and the shared live sheet. Nothing is deleted.');">
        <?= fabo_csrf_field() ?>
        <input type="hidden" name="back" value="<?= e($here()) ?>">
        <input type="hidden" name="action" value="ev_recommended_order">
        <button class="btn btn-sm<?= $evvOk ? '' : ' btn-primary' ?>">Apply the recommended order</button>
      </form>
    </div>

    <div class="scrollx">
      <table class="data sb-cols">
        <thead><tr>
          <th style="width:5%">#</th>
          <th style="width:30%">Column</th>
          <th style="width:25%">Programs view</th>
          <th style="width:12%">Upcoming</th>
          <th style="width:12%">Completed</th>
          <th style="width:16%">Stored as</th>
        </tr></thead>
        <tbody>
        <?php foreach ($evvSheet['columns'] as $i => $c):
            $f  = (string) $c[1];
            $g  = (string) ($c[3]['group'] ?? '');
            $id = ($c[2] ?? '') === 'id';
        ?>
          <tr>
            <td class="num ro"><?= $i + 1 ?></td>
            <td><strong><?= e($c[0]) ?></strong>
              <?php if ($g !== ''): ?><div class="sb-meta"><span class="sb-group">under <?= e($g) ?></span></div><?php endif; ?></td>
            <td>
              <?php if ($id): ?>
                <span class="ro">always shown</span>
              <?php else: $now = fabo_evstage_of($f); ?>
                <form method="post" class="sb-inline">
                  <?= fabo_csrf_field() ?>
                  <input type="hidden" name="back" value="<?= e($here()) ?>">
                  <input type="hidden" name="action" value="event_stage">
                  <input type="hidden" name="field" value="<?= e($f) ?>">
                  <select name="stage" onchange="this.form.submit()" aria-label="Programs view for <?= e($c[0]) ?>">
                    <?php foreach ($evvChoices as $sv => $sl): ?>
                      <option value="<?= e($sv) ?>"<?= $sv === $now ? ' selected' : '' ?>><?= e($sl) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <noscript><button class="btn btn-sm">Save</button></noscript>
                </form>
                <?php if ($now !== fabo_evstage_default($f)): ?>
                  <div class="sb-stage-ro">changed from <?= e($evvChoices[fabo_evstage_default($f)]) ?></div>
                <?php endif; ?>
              <?php endif; ?>
            </td>
            <td><?= $evvSays($c, $evvUp) ?></td>
            <td><?= $evvSays($c, $evvDone) ?></td>
            <td><code class="sb-key"><?= e($f) ?></code></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <p class="hint" style="margin-top:16px">Columns taken off the Events sheet on the
      <a href="sheets.php?tab=columns&amp;sheet=events">Columns</a> tab are off every page.
      The &#9998; button on each row of Upcoming and Completed opens that event with only its
      empty columns of that view.</p>
  </div>
<?php endif; ?>

<?php /* ================================================= tab 1: sheets */ ?>
<?php if ($tab === 'sheets'): ?>
  <div class="block">
    <div class="sb-head">
      <div>
        <span class="eyebrow">Every sheet in the portal</span>
        <h2>Names and sub-headings</h2>
      </div>
      <p class="sb-lead">Type over a name and press Save. A sheet that shipped
        with the portal can be renamed and its columns relabelled; one built
        here can have columns added, moved and taken off.</p>
    </div>

    <div class="scrollx">
      <table class="data sb-all">
        <thead><tr>
          <th style="width:26%">Sheet</th><th style="width:24%">Sub-heading</th>
          <th style="width:12%">Kind</th>
          <th class="num" style="width:8%">Columns</th><th class="num" style="width:8%">Rows</th>
          <th style="width:22%">Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach ($all as $s):
            $b    = $s['row'];
            $rows = fabo_admin_sheet_rows($s['key'], $s['built'] ? $b : null);
            $fid  = 'sh_' . preg_replace('~[^a-z0-9]~', '_', $s['key']);
        ?>
          <tr<?= $s['live'] ? '' : ' class="sb-away"' ?>>
            <td>
              <form method="post" id="<?= e($fid) ?>" class="sb-inline">
                <?= fabo_csrf_field() ?>
                <input type="hidden" name="back" value="sheets.php?tab=sheets">
                <?php if ($s['built']): ?>
                  <input type="hidden" name="action" value="rename">
                  <input type="hidden" name="sheet_id" value="<?= (int) $b['id'] ?>">
                <?php else: ?>
                  <input type="hidden" name="action" value="rename_builtin">
                  <input type="hidden" name="sheet_key" value="<?= e($s['key']) ?>">
                <?php endif; ?>
                <input name="label" value="<?= e($s['label']) ?>" maxlength="120" required>
              </form>
              <div class="sb-meta">
                <code class="sb-key"><?= e($s['key']) ?></code>
                <?php if ($s['renamed']): ?>
                  <span class="ro">was &ldquo;<?= e($s['original']) ?>&rdquo;</span>
                <?php endif; ?>
              </div>
            </td>
            <td><input form="<?= e($fid) ?>" name="subtitle" maxlength="255"
                       value="<?= e($s['subtitle']) ?>" placeholder="optional"></td>
            <td>
              <span class="pill tone-<?= $s['built'] ? 'info' : 'muted' ?>"><i class="dot"></i>
                <?= $s['built'] ? 'built here' : 'shipped' ?></span>
              <?php if (!$s['live']): ?>
                <div class="meta">put away</div>
              <?php endif; ?>
            </td>
            <td class="num"><?= (int) $s['columns'] ?></td>
            <td class="num"><?= $rows ?></td>
            <td class="sb-acts">
              <button form="<?= e($fid) ?>" class="btn btn-sm btn-primary">Save</button>
              <a class="btn btn-sm" href="sheets.php?tab=columns&amp;sheet=<?= e($s['key']) ?>">Columns</a>
              <?php if ($s['live']): ?>
                <a class="btn btn-sm" href="<?= e(fabo_link($viewer, $viewer['is_admin'] ? 'sheet.php' : 'c.php', ['sheet' => $s['key']])) ?>">Open</a>
              <?php endif; ?>
              <?php if ($s['built']): ?>
                <form method="post" class="sb-inline">
                  <?= fabo_csrf_field() ?>
                  <input type="hidden" name="sheet_id" value="<?= (int) $b['id'] ?>">
                  <button class="btn btn-sm" name="action"
                          value="<?= $s['live'] ? 'archive' : 'restore' ?>">
                    <?= $s['live'] ? 'Put away' : 'Bring back' ?></button>
                </form>
              <?php elseif ($s['renamed']): ?>
                <form method="post" class="sb-inline">
                  <?= fabo_csrf_field() ?>
                  <input type="hidden" name="back" value="sheets.php?tab=sheets">
                  <input type="hidden" name="action" value="reset_builtin">
                  <input type="hidden" name="sheet_key" value="<?= e($s['key']) ?>">
                  <button class="btn btn-sm">Undo rename</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <form method="post" class="sb-add">
      <?= fabo_csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <div class="sb-row">
        <div class="sb-field grow"><label>Build a new sheet</label>
          <input name="label" required placeholder="e.g. Vendor Register" maxlength="120"></div>
        <div class="sb-field grow"><label>Sub-heading</label>
          <input name="subtitle" placeholder="optional" maxlength="255"></div>
        <div class="sb-field"><label>&nbsp;</label>
          <button class="btn btn-primary">Create &amp; add columns</button></div>
      </div>
      <p class="sb-note">A sheet you build gets its own table, grid, permissions
        and export, and appears in the navigation beside the rest.</p>
    </form>
  </div>
<?php endif; ?>

<?php /* ================================================ tab 2: columns */ ?>
<?php if ($tab === 'columns' && $pick): ?>
  <div class="block">
    <div class="sb-head">
      <div>
        <span class="eyebrow"><?= $pick['built'] ? 'Built here' : 'Shipped with the portal' ?></span>
        <h2><?= e($pick['label']) ?></h2>
        <div class="sb-meta">
          <code class="sb-key"><?= e($pick['key']) ?></code>
          <span class="ro"><?= count($cols) ?> column<?= count($cols) === 1 ? '' : 's' ?></span>
        </div>
      </div>
      <form method="get" class="sb-row">
        <input type="hidden" name="tab" value="columns">
        <div class="sb-field"><label>Show another sheet</label>
          <select name="sheet" onchange="this.form.submit()">
            <?php foreach ($all as $s): ?>
              <option value="<?= e($s['key']) ?>"<?= $s['key'] === $pickKey ? ' selected' : '' ?>>
                <?= e($s['label']) ?><?= $s['live'] ? '' : ' (put away)' ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="sb-field"><label>&nbsp;</label>
          <noscript><button class="btn btn-sm">Show</button></noscript>
          <a class="btn btn-sm" href="<?= e(fabo_link($viewer, $viewer['is_admin'] ? 'sheet.php' : 'c.php', ['sheet' => $pickKey])) ?>">Open sheet</a></div>
      </form>
    </div>

    <p class="sb-lead">The name in the first box is what the column is called
      on the grid, on the export and in every report. Renaming it changes the
      words, never the data underneath &mdash; which is the name in the
      <em>Stored as</em> column, and that one never changes.</p>
    <?php if ($evStages): ?>
      <p class="sb-lead"><strong>Programs view</strong> decides where a column appears on
        Event Management's <em>Upcoming Events</em> and <em>Completed Events</em> tabs;
        change it on the <a href="sheets.php?tab=evviews">Event Views</a> tab. The order
        below is the order of Programs Data, both views and the shared live sheet.</p>
      <?php if (!fabo_evstage_in_order(fabo_sheet('events'))): ?>
        <form method="post" class="sb-order"
              onsubmit="return confirm('Put every Events column in the recommended order? Nothing is deleted.');">
          <?= fabo_csrf_field() ?>
          <input type="hidden" name="back" value="<?= e($here()) ?>">
          <input type="hidden" name="action" value="ev_recommended_order">
          <span class="sb-orderhint">Not in the recommended order: what the event is, when,
            where and who, the programme, partners, money, approvals, then each result
            beside its target.</span>
          <button class="btn btn-sm btn-primary">Apply the recommended order</button>
        </form>
      <?php endif; ?>
    <?php endif; ?>

    <?php /* Arranging the order. The button stays dead until something has
             actually moved, so there is nothing to press by mistake, and the
             order is only written when it is pressed. */ ?>
    <form method="post" id="sborder" class="sb-order">
      <?= fabo_csrf_field() ?>
      <input type="hidden" name="back" value="<?= e($here()) ?>">
      <input type="hidden" name="action" value="save_order">
      <input type="hidden" name="sheet_key" value="<?= e($pickKey) ?>">
      <input type="hidden" name="order" value="<?= e(implode(',', array_map(
            static fn($c) => $c['field'],
            array_values(array_filter($cols,
                static fn($c) => ($c['type'] ?? '') !== 'id'))))) ?>">
      <span class="sb-orderhint">Drag a column by its
        <span class="sb-gripmark" aria-hidden="true">&#8942;&#8942;</span> handle to move
        it, or focus a handle and use the arrow keys. The order applies to the grid,
        the export, the reports and the shared live sheet.</span>
      <button class="btn btn-sm btn-primary" data-role="save" disabled>Save order</button>
      <noscript><span class="ro">Arranging columns needs JavaScript.</span></noscript>
    </form>

    <?php /* Undoing it is its own form: a form holds one action, and putting a
             second button in the one above would depend on which of two fields
             of the same name the server read last. */ ?>
    <?php if ($ordered): ?>
      <form method="post" class="sb-order sb-orderundo">
        <?= fabo_csrf_field() ?>
        <input type="hidden" name="back" value="<?= e($here()) ?>">
        <input type="hidden" name="action" value="reset_order">
        <input type="hidden" name="sheet_key" value="<?= e($pickKey) ?>">
        <button class="btn btn-sm">Back to the order it was built in</button>
      </form>
    <?php endif; ?>

    <div class="scrollx">
      <table class="data sb-cols" id="sbcols">
        <thead><tr>
          <th style="width:6%">#</th>
          <th style="width:28%">Column name</th>
          <th style="width:13%">Holds</th>
          <th style="width:17%">Dropdown options</th>
          <th style="width:16%">Stored as</th>
          <?php if ($evStages): ?>
            <th style="width:16%" title="Where this column appears on Event Management">Programs view</th>
          <?php endif; ?>
          <th style="width:22%">Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach ($cols as $i => $c):
            $fid = 'cl_' . preg_replace('~[^a-z0-9]~', '_', $pickKey . '_' . $c['field']);
            // A built sheet's columns are rows of their own, so the builder
            // controls attach to that row rather than to the field name.
            $bc = null;
            foreach ($builtOn as $one) {
                if ($one['field'] === $c['field']) { $bc = $one; break; }
            }
            // A column added to a shipped sheet is edited through its own row
            // in sheet_extras, not through a label override.
            $ex = $extras[$c['field']] ?? null;
        ?>
          <?php /* The id column is the row's identity and is always shown
                   first, so it has no handle and nothing moves above it.
                   Events is the exception: its agreed order puts Committee
                   Approval and Hub / Spoke ahead of the Event ID, so there
                   the id moves like any other column (see fabo_id_rank()). */
                 $pinned = ($c['type'] ?? '') === 'id' && $pickKey !== 'events'; ?>
          <tr data-field="<?= e($c['field']) ?>"<?= $pinned ? ' data-pinned="1"' : '' ?>>
            <td class="num ro sb-gripcell">
              <?php if ($pinned): ?>
                <span class="sb-grip-none" title="The id column is always first">&#8226;</span>
              <?php else: ?>
                <span class="sb-grip" data-role="grip" draggable="true" tabindex="0"
                      role="button" aria-label="Move <?= e($c['label']) ?>"
                      title="Drag to move, or use the arrow keys">&#8942;&#8942;</span>
              <?php endif; ?>
              <span data-role="n"><?= $i + 1 ?></span>
            </td>
            <td>
              <?php if ($c['readonly'] && !$bc): ?>
                <span class="sb-fixed"><?= e($c['label']) ?></span>
                <div class="sb-meta"><span class="ro">worked out by the portal</span></div>
              <?php else: ?>
                <form method="post" id="<?= e($fid) ?>" class="sb-inline">
                  <?= fabo_csrf_field() ?>
                  <input type="hidden" name="back" value="<?= e($here()) ?>">
                  <?php if ($bc): ?>
                    <input type="hidden" name="action" value="edit_column">
                    <input type="hidden" name="column_id" value="<?= (int) $bc['id'] ?>">
                    <input type="hidden" name="sheet_id" value="<?= (int) $pick['row']['id'] ?>">
                  <?php elseif ($ex): ?>
                    <input type="hidden" name="action" value="edit_extra">
                    <input type="hidden" name="extra_id" value="<?= (int) $ex['id'] ?>">
                  <?php else: ?>
                    <input type="hidden" name="action" value="rename_column">
                    <input type="hidden" name="sheet_key" value="<?= e($pickKey) ?>">
                    <input type="hidden" name="field" value="<?= e($c['field']) ?>">
                  <?php endif; ?>
                  <input name="label" value="<?= e($c['label']) ?>" maxlength="120" required>
                </form>
                <div class="sb-meta">
                  <?php if ($ex): ?>
                    <span class="sb-added">added here</span>
                  <?php endif; ?>
                  <?php if ($c['group'] !== ''): ?>
                    <span class="sb-group">under <?= e($c['group']) ?></span>
                  <?php endif; ?>
                  <?php if ($c['renamed']): ?>
                    <span class="ro">was &ldquo;<?= e($c['original']) ?>&rdquo;</span>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </td>
            <td>
              <span class="sb-type"><?= e($words[$c['type']] ?? $c['type']) ?></span>
              <?php if ($c['unlock'] !== ''): ?>
                <div class="meta">opens after <?= e(str_replace('_', ' ', $c['unlock'])) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($c['type'] === 'select' && ($bc || $ex)): ?>
                <input form="<?= e($fid) ?>" name="list_name"
                       value="<?= e((string) (($bc['list_name'] ?? $ex['list_name']) ?? '')) ?>"
                       maxlength="80" placeholder="which list">
                <?php if ($ex): ?>
                  <a class="sb-uses" href="sheets.php?tab=options&amp;list=<?=
                     e(urlencode((string) $ex['list_name'])) ?>">its options</a>
                <?php endif; ?>
              <?php elseif ($c['type'] === 'select' && $c['list'] !== ''): ?>
                <?php if (fabo_is_dynamic_list($c['list'])): ?>
                  <span class="ro"><?= e(ltrim($c['list'], '@')) ?> &middot; from live data</span>
                <?php else: ?>
                  <a class="sb-listlink"
                     href="sheets.php?tab=options&amp;list=<?= e(urlencode($c['list'])) ?>">
                    <?= e($c['list']) ?>
                    <span><?= count(array_filter($lists[$c['list']] ?? [],
                                     static fn($o) => $o['active'])) ?></span></a>
                <?php endif; ?>
              <?php else: ?>
                <span class="ro">&mdash;</span>
              <?php endif; ?>
            </td>
            <td><code class="sb-key"><?= e($c['field']) ?></code></td>
            <?php if ($evStages): ?>
              <td>
                <?php /* Shown here, changed on the Event Views tab, where the
                         order of each view is arranged too. */ ?>
                <?php if ($pinned): ?>
                  <span class="ro">always shown</span>
                <?php else: ?>
                  <a class="sb-uses" href="sheets.php?tab=evviews"><?=
                     e(fabo_evstage_choices()[fabo_evstage_of($c['field'])]) ?></a>
                <?php endif; ?>
              </td>
            <?php endif; ?>
            <td class="sb-acts">
              <?php if ($c['readonly'] && !$bc): ?>
                <span class="ro">fixed</span>
              <?php else: ?>
                <button form="<?= e($fid) ?>" class="btn btn-sm btn-primary">Save</button>
                <?php if ($c['renamed'] && !$bc): ?>
                  <form method="post" class="sb-inline">
                    <?= fabo_csrf_field() ?>
                    <input type="hidden" name="back" value="<?= e($here()) ?>">
                    <input type="hidden" name="action" value="reset_column">
                    <input type="hidden" name="sheet_key" value="<?= e($pickKey) ?>">
                    <input type="hidden" name="field" value="<?= e($c['field']) ?>">
                    <button class="btn btn-sm">Undo</button>
                  </form>
                <?php endif; ?>
                <?php /* A shipped column can be taken off too. Not dropped:
                         hidden, so the data survives and putting it back
                         brings it with it. The id column is never offered —
                         a row with nothing to identify it by is not a row. */ ?>
                <?php if (!$ex && !$bc && $c['type'] !== 'id'): ?>
                  <?php /* No confirmation here: nothing is deleted. The
                         column and everything in it stay in the table, and
                         the button reads Put back once it is off. */ ?>
                  <form method="post" class="sb-inline">
                    <?= fabo_csrf_field() ?>
                    <input type="hidden" name="back" value="<?= e($here()) ?>">
                    <input type="hidden" name="action" value="<?= empty($c['hidden'])
                          ? 'hide_column' : 'show_column' ?>">
                    <input type="hidden" name="sheet_key" value="<?= e($pickKey) ?>">
                    <input type="hidden" name="field" value="<?= e($c['field']) ?>">
                    <button class="btn btn-sm<?= empty($c['hidden']) ? ' danger' : ' btn-primary' ?>"><?=
                      empty($c['hidden']) ? 'Remove' : 'Put back' ?></button>
                  </form>
                <?php endif; ?>
                <?php /* No up/down arrows here any more: they moved an added
                         column among the other added ones only, and the handle
                         in the first cell moves any column anywhere. */ ?>
                <?php if ($ex): ?>
                  <form method="post" class="sb-inline"
                        onsubmit="return confirm('Take this column off the sheet? What is already in it is kept.');">
                    <?= fabo_csrf_field() ?>
                    <input type="hidden" name="back" value="<?= e($here()) ?>">
                    <input type="hidden" name="action" value="remove_extra">
                    <input type="hidden" name="extra_id" value="<?= (int) $ex['id'] ?>">
                    <button class="btn btn-sm danger">Remove</button>
                  </form>
                <?php endif; ?>
                <?php if ($bc): ?>
                  <?php foreach ([['up', '&uarr;', 'Move up'], ['down', '&darr;', 'Move down']] as [$d, $ar, $t]): ?>
                    <form method="post" class="sb-inline">
                      <?= fabo_csrf_field() ?>
                      <input type="hidden" name="back" value="<?= e($here()) ?>">
                      <input type="hidden" name="action" value="move_column">
                      <input type="hidden" name="sheet_id" value="<?= (int) $pick['row']['id'] ?>">
                      <input type="hidden" name="column_id" value="<?= (int) $bc['id'] ?>">
                      <input type="hidden" name="dir" value="<?= e($d) ?>">
                      <button class="btn btn-sm" title="<?= e($t) ?>"><?= $ar ?></button>
                    </form>
                  <?php endforeach; ?>
                  <form method="post" class="sb-inline"
                        onsubmit="return confirm('Take this column off the sheet? What is already in it is kept.');">
                    <?= fabo_csrf_field() ?>
                    <input type="hidden" name="back" value="<?= e($here()) ?>">
                    <input type="hidden" name="action" value="remove_column">
                    <input type="hidden" name="sheet_id" value="<?= (int) $pick['row']['id'] ?>">
                    <input type="hidden" name="column_id" value="<?= (int) $bc['id'] ?>">
                    <button class="btn btn-sm danger">Remove</button>
                  </form>
                <?php endif; ?>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($pick['built'] && $pick['row']): ?>
      <form method="post" class="sb-add">
        <?= fabo_csrf_field() ?>
        <input type="hidden" name="back" value="<?= e($here()) ?>">
        <input type="hidden" name="action" value="add_column">
        <input type="hidden" name="sheet_id" value="<?= (int) $pick['row']['id'] ?>">
        <div class="sb-row">
          <div class="sb-field grow"><label>New column</label>
            <input name="label" required placeholder="e.g. Contract" maxlength="120"></div>
          <div class="sb-field"><label>Type</label>
            <select name="type" id="coltype">
              <?php foreach (fabo_builder_types() as $t => $lab): ?>
                <option value="<?= e($t) ?>"><?= e($lab) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="sb-field" id="collistwrap" hidden><label>Dropdown list</label>
            <input name="list_name" placeholder="defaults to the column name" maxlength="80"></div>
          <div class="sb-field"><label>&nbsp;</label>
            <button class="btn btn-primary">Add column</button></div>
        </div>
        <p class="sb-note" id="coltypenote"></p>
        <?php foreach (fabo_builder_type_notes() as $t => $note): ?>
          <template data-type="<?= e($t) ?>"><?= e($note) ?></template>
        <?php endforeach; ?>
      </form>
      <p class="hint">Removing a column takes it off the sheet but leaves the data
        in the table, so nothing is lost by a mis-click. A column's type never
        changes once it exists &mdash; add a new one and copy across if you need
        a different kind.</p>
    <?php else: ?>
      <form method="post" class="sb-add">
        <?= fabo_csrf_field() ?>
        <input type="hidden" name="back" value="<?= e($here()) ?>">
        <input type="hidden" name="action" value="add_extra">
        <input type="hidden" name="sheet_key" value="<?= e($pickKey) ?>">
        <div class="sb-row">
          <div class="sb-field grow"><label>Add a column to <?= e($pick['label']) ?></label>
            <input name="label" required placeholder="e.g. Purchase Order" maxlength="120"></div>
          <div class="sb-field"><label>Type</label>
            <select name="type" id="coltype">
              <?php foreach (fabo_builder_types() as $t => $lab): ?>
                <option value="<?= e($t) ?>"><?= e($lab) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="sb-field" id="collistwrap" hidden><label>Dropdown list</label>
            <input name="list_name" placeholder="defaults to the column name" maxlength="80"></div>
          <div class="sb-field"><label>&nbsp;</label>
            <button class="btn btn-primary">Add column</button></div>
        </div>
        <p class="sb-note" id="coltypenote"></p>
        <?php foreach (fabo_builder_type_notes() as $t => $note): ?>
          <template data-type="<?= e($t) ?>"><?= e($note) ?></template>
        <?php endforeach; ?>
      </form>
      <p class="hint">A column added here goes on the end of the sheet and is a
        column like any other from that point on: the grid edits it, the export
        carries it, and a report that walks the columns finds it. The columns
        the sheet shipped with stay where they are &mdash; they can be renamed,
        but not reordered or removed, because the portal works them out and
        writes to them by name. One marked <em>worked out by the portal</em> is
        calculated from the others and has no name of its own to change.</p>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php /* ================================================ tab 3: options */ ?>
<?php if ($tab === 'options'): ?>
  <div class="block">
    <div class="sb-head">
      <div>
        <span class="eyebrow">Dropdown options</span>
        <h2><?= $pickList !== '' ? e($pickList) : 'No lists yet' ?></h2>
        <div class="sb-meta">
          <span class="ro"><?= count(array_filter($options, static fn($o) => $o['active'])) ?> in use</span>
          <?php if ($usedBy): ?>
            <span class="ro">&middot; read by <?= count($usedBy) ?>
              column<?= count($usedBy) === 1 ? '' : 's' ?></span>
          <?php else: ?>
            <span class="ro">&middot; no column reads it</span>
          <?php endif; ?>
        </div>
      </div>
      <form method="get" class="sb-row">
        <input type="hidden" name="tab" value="options">
        <div class="sb-field"><label>Show another list</label>
          <select name="list" onchange="this.form.submit()">
            <?php foreach ($lists as $name => $opts): ?>
              <option value="<?= e($name) ?>"<?= $name === $pickList ? ' selected' : '' ?>>
                <?= e($name) ?> (<?= count(array_filter($opts, static fn($o) => $o['active'])) ?>)
              </option>
            <?php endforeach; ?>
          </select></div>
        <div class="sb-field"><label>&nbsp;</label>
          <noscript><button class="btn btn-sm">Show</button></noscript></div>
      </form>
    </div>

    <?php if ($usedBy): ?>
      <p class="sb-lead">Offered by
        <?php foreach ($usedBy as $i => $u): ?>
          <a class="sb-uses" href="sheets.php?tab=columns&amp;sheet=<?= e($u['sheet']) ?>"><?=
            e($u['sheet_label']) ?> &middot; <?= e($u['column']) ?></a><?= $i < count($usedBy) - 1 ? ' ' : '' ?>
        <?php endforeach; ?>
      </p>
    <?php endif; ?>

    <?php if (!$options): ?>
      <div class="empty">This list has no options yet. Add the first one below and
        it appears in every dropdown that reads it.</div>
    <?php else: ?>
      <div class="scrollx">
        <table class="data sb-opts">
          <thead><tr>
            <th style="width:6%">#</th><th style="width:44%">Option</th>
            <th style="width:16%">Shown</th><th style="width:34%">Order &amp; removal</th>
          </tr></thead>
          <tbody>
          <?php $live = array_values(array_filter($options, static fn($o) => $o['active']));
                $seen = 0;
                foreach ($options as $o):
                    $oid = 'op' . $o['id'];
                    if ($o['active']) { $seen++; }
          ?>
            <tr<?= $o['active'] ? '' : ' class="sb-away"' ?>>
              <td class="num ro"><?= $o['active'] ? $seen : '&mdash;' ?></td>
              <td>
                <form method="post" id="<?= e($oid) ?>" class="sb-inline">
                  <?= fabo_csrf_field() ?>
                  <input type="hidden" name="back" value="<?= e($here()) ?>">
                  <input type="hidden" name="action" value="list_rename">
                  <input type="hidden" name="option_id" value="<?= (int) $o['id'] ?>">
                  <input name="value" value="<?= e($o['value']) ?>" maxlength="255" required>
                  <button class="btn btn-sm btn-primary">Save</button>
                </form>
              </td>
              <td>
                <?php if ($o['active']): ?>
                  <span class="pill tone-ok"><i class="dot"></i>in the dropdown</span>
                <?php else: ?>
                  <span class="pill tone-muted"><i class="dot"></i>taken off</span>
                <?php endif; ?>
              </td>
              <td class="sb-acts">
                <?php if ($o['active']): ?>
                  <?php foreach ([['up', '&uarr;', 'Move up'], ['down', '&darr;', 'Move down']] as [$d, $ar, $t]): ?>
                    <form method="post" class="sb-inline">
                      <?= fabo_csrf_field() ?>
                      <input type="hidden" name="back" value="<?= e($here()) ?>">
                      <input type="hidden" name="action" value="list_move">
                      <input type="hidden" name="option_id" value="<?= (int) $o['id'] ?>">
                      <input type="hidden" name="dir" value="<?= e($d) ?>">
                      <button class="btn btn-sm" title="<?= e($t) ?>"><?= $ar ?></button>
                    </form>
                  <?php endforeach; ?>
                  <form method="post" class="sb-inline">
                    <?= fabo_csrf_field() ?>
                    <input type="hidden" name="back" value="<?= e($here()) ?>">
                    <input type="hidden" name="action" value="list_remove">
                    <input type="hidden" name="option_id" value="<?= (int) $o['id'] ?>">
                    <button class="btn btn-sm danger">Take off</button>
                  </form>
                <?php else: ?>
                  <form method="post" class="sb-inline">
                    <?= fabo_csrf_field() ?>
                    <input type="hidden" name="back" value="<?= e($here()) ?>">
                    <input type="hidden" name="action" value="list_restore">
                    <input type="hidden" name="option_id" value="<?= (int) $o['id'] ?>">
                    <button class="btn btn-sm">Put back</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <?php if ($pickList !== ''): ?>
      <form method="post" class="sb-add">
        <?= fabo_csrf_field() ?>
        <input type="hidden" name="back" value="<?= e($here()) ?>">
        <input type="hidden" name="action" value="list_add">
        <input type="hidden" name="list" value="<?= e($pickList) ?>">
        <div class="sb-row">
          <div class="sb-field grow"><label>Add an option to <?= e($pickList) ?></label>
            <input name="value" required placeholder="what the dropdown should offer" maxlength="255"></div>
          <div class="sb-field"><label>&nbsp;</label>
            <button class="btn btn-primary">Add option</button></div>
        </div>
        <p class="sb-note">It goes to the end of the list; use the arrows to move it.</p>
      </form>
    <?php endif; ?>

    <form method="post" class="sb-add">
      <?= fabo_csrf_field() ?>
      <input type="hidden" name="action" value="list_create">
      <div class="sb-row">
        <div class="sb-field grow"><label>Start a new list</label>
          <input name="list" required placeholder="e.g. Vendor Type" maxlength="80"></div>
        <div class="sb-field grow"><label>Its first option</label>
          <input name="value" required placeholder="e.g. Printing" maxlength="255"></div>
        <div class="sb-field"><label>&nbsp;</label>
          <button class="btn btn-primary">Create list</button></div>
      </div>
      <p class="sb-note">Point a dropdown column at the list by name, on the
        Columns tab, and it starts offering these options.</p>
    </form>

    <p class="hint">You are editing the shared defaults, which every client
      starts from. A client who has already changed a list of their own keeps
      their version. Renaming an option also moves the rows that hold it, so
      nothing is left holding a value the dropdown no longer offers; taking one
      off leaves those rows alone and only stops it being offered again.</p>
  </div>
<?php endif; ?>
<?php /* ============================================ tab 4: requisitions */ ?>
<?php if ($tab === 'requisitions'): ?>
  <div class="block">
    <div class="sb-head">
      <div>
        <span class="eyebrow">Requisition queue</span>
        <h2>How long each kind of work takes</h2>
      </div>
      <p class="sb-lead">This is what the queue promises with. Hours are
        elapsed hours &mdash; 48 means two days, the way a desk says it &mdash;
        and weekends are stepped over. A requisition starts when everything
        ahead of it finishes, so changing a number here changes every date
        behind it the next time the queue is worked out.</p>
    </div>

    <div class="scrollx">
      <table class="data sb-types">
        <thead><tr>
          <th style="width:6%">#</th><th style="width:34%">Type of work</th>
          <th style="width:16%">Turnaround</th><th style="width:16%">Reads as</th>
          <th style="width:12%">Offered</th><th style="width:16%">Actions</th>
        </tr></thead>
        <tbody>
        <?php $n = 0; foreach ($reqTypes as $t):
            $n++;
            $tid   = 'rt' . (int) $t['id'];
            $hours = (int) $t['turnaround_hours'];
            $days  = fabo_req_days($hours);
            $used  = (int) ($reqUse[(string) $t['name']] ?? 0);
        ?>
          <tr<?= (int) $t['active'] === 1 ? '' : ' class="sb-away"' ?>>
            <td class="num ro"><?= $n ?></td>
            <td>
              <form method="post" id="<?= e($tid) ?>" class="sb-inline">
                <?= fabo_csrf_field() ?>
                <input type="hidden" name="back" value="sheets.php?tab=requisitions">
                <input type="hidden" name="action" value="req_type_save">
                <input type="hidden" name="type_id" value="<?= (int) $t['id'] ?>">
                <input name="name" value="<?= e((string) $t['name']) ?>" maxlength="80" required>
              </form>
              <?php if ($used): ?>
                <div class="sb-meta"><span class="ro"><?= $used ?> requisition<?=
                  $used === 1 ? '' : 's' ?> waiting</span></div>
              <?php endif; ?>
            </td>
            <td><input form="<?= e($tid) ?>" name="hours" type="number" min="1" max="2000"
                       value="<?= $hours ?>"></td>
            <td class="ro"><?= $days ?> working day<?= $days === 1 ? '' : 's' ?></td>
            <td>
              <label class="sb-switch">
                <input form="<?= e($tid) ?>" type="checkbox" name="active" value="1"
                       <?= (int) $t['active'] === 1 ? 'checked' : '' ?>>
                <span><?= (int) $t['active'] === 1 ? 'on the form' : 'hidden' ?></span>
              </label>
            </td>
            <td class="sb-acts">
              <button form="<?= e($tid) ?>" class="btn btn-sm btn-primary">Save</button>
              <form method="post" class="sb-inline"
                    onsubmit="return confirm('Delete this type? Requisitions already asking for it keep the name but fall back to the default turnaround.');">
                <?= fabo_csrf_field() ?>
                <input type="hidden" name="back" value="sheets.php?tab=requisitions">
                <input type="hidden" name="action" value="req_type_delete">
                <input type="hidden" name="type_id" value="<?= (int) $t['id'] ?>">
                <button class="btn btn-sm danger">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <form method="post" class="sb-add">
      <?= fabo_csrf_field() ?>
      <input type="hidden" name="back" value="sheets.php?tab=requisitions">
      <input type="hidden" name="action" value="req_type_add">
      <div class="sb-row">
        <div class="sb-field grow"><label>Another kind of work</label>
          <input name="name" required placeholder="e.g. Podcast Edit" maxlength="80"></div>
        <div class="sb-field"><label>Turnaround (hours)</label>
          <input name="hours" type="number" min="1" max="2000" value="24" required></div>
        <div class="sb-field"><label>&nbsp;</label>
          <button class="btn btn-primary">Add type</button></div>
      </div>
      <p class="sb-note">It appears on the requisition form straight away, as an
        option under <em>Type of Requirement</em>.</p>
    </form>

    <!-- ------------------------------------------------- the queue's own settings -->
    <form method="post" class="sb-add">
      <?= fabo_csrf_field() ?>
      <input type="hidden" name="back" value="sheets.php?tab=requisitions">
      <input type="hidden" name="action" value="req_settings">
      <div class="sb-row">
        <div class="sb-field grow"><label>Tell this team when one arrives</label>
          <input name="team_emails" value="<?= e($reqTeamRaw) ?>"
                 placeholder="design@example.com, social@example.com"></div>
        <div class="sb-field"><label>&nbsp;</label>
          <button class="btn btn-primary">Save</button></div>
      </div>
      <p class="sb-note">Addresses are separated by a comma; each one gets the
        requisition and where it sits in the queue, the moment it arrives.</p>
      <?php if ($reqTeamList): ?>
        <p class="sb-note"><strong><?= count($reqTeamList) ?>
          address<?= count($reqTeamList) === 1 ? '' : 'es' ?> will be told:</strong></p>
        <ul style="display:flex;flex-wrap:wrap;gap:6px;list-style:none;margin:6px 0 0;padding:0">
          <?php foreach ($reqTeamList as $one): ?>
            <li style="padding:3px 10px;border-radius:999px;background:var(--line, #eef1f5);font-size:13px"><?= e($one) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="sb-note" style="color:#b42318">No address is saved, so nobody is told when a requisition arrives.</p>
      <?php endif; ?>
    </form>

    <p class="hint">The person who raised a requisition is emailed when it lands
      and again whenever its delivery date moves &mdash; including when it moves
      because something ahead of it was extended. On the Requisitions sheet the
      row turns red once a promised date has passed with the work still open,
      so a slipped promise is visible without anybody going looking for it.</p>
  </div>
<?php endif; ?>

<?php /* ========================================== tab 5: budget heads */ ?>
<?php if ($tab === 'budget'): ?>
  <div class="block">
    <div class="sb-head">
      <div>
        <span class="eyebrow">Event budgets</span>
        <h2>What each head allows</h2>
      </div>
      <p class="sb-lead">These are the heads the team sees when they open
        <em>Budget Required</em> on an event. Against each one you set what is
        allocated to it and how far over that a request may still go. The team
        enters what they need and is told green or red &mdash;
        <strong>they never see the allocation, the percentage or the ceiling
        it works out to</strong>. Those numbers are not hidden on their screen;
        they are never sent to it.</p>
    </div>

    <div class="scrollx">
      <table class="data sb-types bqadmin">
        <thead><tr>
          <th style="width:5%">#</th>
          <th style="width:26%">Budget head</th>
          <th style="width:15%">Allocated</th>
          <th style="width:11%">Allowed over</th>
          <th style="width:14%">Ceiling</th>
          <th style="width:11%">On the form</th>
          <th style="width:18%">Actions</th>
        </tr></thead>
        <tbody>
        <?php $n = 0; foreach ($budgetItems as $b):
            $n++;
            $bid  = 'bq' . (int) $b['id'];
            $alloc = (float) $b['allocated'];
            $pct   = (float) $b['threshold_pct'];
            $use   = $budgetUse[(string) $b['name']] ?? null;
            $mine  = (int) $b['client_id'] === $scope;
        ?>
          <tr<?= (int) $b['active'] === 1 ? '' : ' class="sb-away"' ?>>
            <td class="num ro"><?= $n ?></td>
            <td>
              <form method="post" id="<?= e($bid) ?>" class="sb-inline">
                <?= fabo_csrf_field() ?>
                <input type="hidden" name="back" value="sheets.php?tab=budget">
                <input type="hidden" name="action" value="budget_save">
                <input type="hidden" name="item_id" value="<?= (int) $b['id'] ?>">
                <input type="text" name="name" value="<?= e((string) $b['name']) ?>"
                       maxlength="120" required>
              </form>
              <?php if ($use): ?>
                <div class="sb-meta"><span class="ro"><?= (int) $use['n'] ?>
                  request<?= $use['n'] === 1 ? '' : 's' ?> &middot;
                  <?= e(fabo_money($use['total'])) ?> asked for</span></div>
              <?php endif; ?>
              <?php if (!$mine && $scope > 0): ?>
                <div class="sb-meta"><span class="ro">shared &mdash; saving makes
                  it yours</span></div>
              <?php endif; ?>
            </td>
            <td><input form="<?= e($bid) ?>" name="allocated" type="number" min="0"
                       step="0.01" value="<?= e(rtrim(rtrim(number_format($alloc, 2, '.', ''), '0'), '.')) ?>"
                       placeholder="0"></td>
            <td><input form="<?= e($bid) ?>" name="pct" type="number" min="0" max="1000"
                       step="0.01" value="<?= e(rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.')) ?>">
                <span class="ro">%</span></td>
            <td class="ro">
              <?php if ($alloc > 0): ?>
                <strong><?= e(fabo_money($alloc + $alloc * $pct / 100)) ?></strong>
                <div class="sb-meta">anything above this is refused</div>
              <?php else: ?>
                &mdash;
                <div class="sb-meta">no allocation set, so nothing is judged</div>
              <?php endif; ?>
            </td>
            <td>
              <label class="sb-switch">
                <input form="<?= e($bid) ?>" type="checkbox" name="active" value="1"
                       <?= (int) $b['active'] === 1 ? 'checked' : '' ?>>
                <span><?= (int) $b['active'] === 1 ? 'on the form' : 'hidden' ?></span>
              </label>
            </td>
            <td class="sb-acts">
              <button form="<?= e($bid) ?>" class="btn btn-sm btn-primary">Save</button>
              <span class="bqmove">
                <?php foreach (['up' => '&uarr;', 'down' => '&darr;'] as $dir => $arrow): ?>
                  <form method="post" class="sb-inline">
                    <?= fabo_csrf_field() ?>
                    <input type="hidden" name="back" value="sheets.php?tab=budget">
                    <input type="hidden" name="action" value="budget_move">
                    <input type="hidden" name="item_id" value="<?= (int) $b['id'] ?>">
                    <input type="hidden" name="dir" value="<?= e($dir) ?>">
                    <button class="btn btn-sm" title="Move <?= e($dir) ?>"><?= $arrow ?></button>
                  </form>
                <?php endforeach; ?>
              </span>
              <form method="post" class="sb-inline"
                    onsubmit="return confirm('Remove this head from the budget form? Amounts already asked for against it are kept on their events.');">
                <?= fabo_csrf_field() ?>
                <input type="hidden" name="back" value="sheets.php?tab=budget">
                <input type="hidden" name="action" value="budget_delete">
                <input type="hidden" name="item_id" value="<?= (int) $b['id'] ?>">
                <button class="btn btn-sm danger">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$budgetItems): ?>
          <tr><td colspan="7"><div class="empty">No budget heads yet.</div></td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

    <form method="post" class="sb-add">
      <?= fabo_csrf_field() ?>
      <input type="hidden" name="back" value="sheets.php?tab=budget">
      <input type="hidden" name="action" value="budget_add">
      <div class="sb-row">
        <div class="sb-field grow"><label>Another budget head</label>
          <input name="name" required placeholder="e.g. Security &amp; Housekeeping"
                 maxlength="120"></div>
        <div class="sb-field"><label>Allocated</label>
          <input name="allocated" type="number" min="0" step="0.01" value="0"></div>
        <div class="sb-field"><label>Allowed over (%)</label>
          <input name="pct" type="number" min="0" max="1000" step="0.01" value="10"></div>
        <div class="sb-field"><label>&nbsp;</label>
          <button class="btn btn-primary">Add head</button></div>
      </div>
      <p class="sb-note">It appears on every event's budget form straight away.</p>
    </form>

    <p class="hint">
      A head with no allocation is still offered, and anything asked against it
      is reported as <em>no allocation set</em> rather than as a breach &mdash;
      telling somebody they are over a limit nobody has decided would be a lie.
      <br>
      Deleting a head takes it off the form and leaves history alone: each
      request keeps its own copy of the head's name, allocation and percentage
      as they stood when it was made, so re-costing a head next quarter never
      rewrites a decision already taken.
    </p>
  </div>
<?php endif; ?>

<?php /* ============================================== tab: committees */ ?>
<?php if ($tab === 'committees'): ?>
  <?php
    $cmEdit   = !empty($_GET['edit']) ? fabo_cm_get((int) $_GET['edit'], $scope) : null;
    $cmPeople = fabo_cm_candidates($scope);
    $cmOn     = $cmEdit ? array_map('intval', array_column($cmEdit['members'], 'id')) : [];
    $cmUse    = [];
    try {
        foreach (fabo_all('SELECT committee_id, COUNT(*) n FROM `events` WHERE committee_id IS NOT NULL
                            GROUP BY committee_id') as $u) {
            $cmUse[(int) $u['committee_id']] = (int) $u['n'];
        }
    } catch (Throwable $e) {
        // Before the column exists: nothing is using a committee yet.
    }
  ?>
  <div class="block">
    <div class="sb-head">
      <div>
        <span class="eyebrow">Event approvals</span>
        <h2>Committees</h2>
      </div>
      <p class="sb-lead">A committee is a group of your team who decide on an event
        together. Give an event a committee in its <em>Committee</em> column on the
        Events sheet: every member is told, and approves or rejects it &mdash; a
        rejection always with a justification. The committee's rule turns their
        votes into the event's approval, and you can see who has approved and who
        is still pending on the event. Members find the events assigned to them on
        their own Committee Dashboard. Only people created with the
        <strong>Committee member</strong> box ticked can be chosen.</p>
    </div>

    <?php if ($cmList): ?>
      <div class="cmc-grid">
        <?php foreach ($cmList as $c): $used = $cmUse[(int) $c['id']] ?? 0; ?>
          <div class="cmc<?= $cmEdit && (int) $cmEdit['id'] === (int) $c['id'] ? ' on' : '' ?>">
            <div class="cmc-top">
              <h3><?= e((string) $c['name']) ?></h3>
              <span class="cmc-rule"><?= e(fabo_cm_rule_label($c, count($c['members']))) ?></span>
            </div>
            <div class="cmc-people">
              <?php foreach ($c['members'] as $m): ?>
                <span class="cmc-chip" title="<?= e((string) ($m['email'] ?? '')) ?>"><?= e((string) $m['name']) ?></span>
              <?php endforeach; ?>
              <?php if (!$c['members']): ?><span class="hint">No members — edit to add some.</span><?php endif; ?>
            </div>
            <div class="cmc-foot">
              <span class="hint"><?= count($c['members']) ?> member<?= count($c['members']) === 1 ? '' : 's' ?>
                &middot; <?= $used ?> event<?= $used === 1 ? '' : 's' ?></span>
              <a class="btn btn-sm" href="sheets.php?tab=committees&amp;edit=<?= (int) $c['id'] ?>#cmform">Edit</a>
              <form method="post" class="sb-inline"
                    onsubmit="return confirm(<?= e(json_encode('Delete the ' . $c['name'] . ' committee?'
                        . ($used ? ' ' . $used . ' event' . ($used === 1 ? '' : 's') . ' will be left without a committee, and its votes are removed.' : ''))) ?>);">
                <?= fabo_csrf_field() ?>
                <input type="hidden" name="back" value="sheets.php?tab=committees">
                <input type="hidden" name="action" value="cm_delete">
                <input type="hidden" name="committee_id" value="<?= (int) $c['id'] ?>">
                <button class="btn btn-sm btn-danger">Delete</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="empty">No committees yet. Create the first one below.</div>
    <?php endif; ?>

    <form method="post" class="sb-add cmc-form" id="cmform">
      <?= fabo_csrf_field() ?>
      <input type="hidden" name="back" value="sheets.php?tab=committees">
      <input type="hidden" name="action" value="cm_save">
      <input type="hidden" name="committee_id" value="<?= (int) ($cmEdit['id'] ?? 0) ?>">
      <h3 class="cmc-formtitle"><?= $cmEdit ? 'Edit ' . e((string) $cmEdit['name']) : 'New committee' ?></h3>
      <div class="sb-row">
        <div class="sb-field grow"><label>Committee name</label>
          <input name="name" required maxlength="120" value="<?= e((string) ($cmEdit['name'] ?? '')) ?>"
                 placeholder="e.g. Programme Approval Committee"></div>
        <div class="sb-field"><label>How it decides</label>
          <select name="rule" id="cmcRule">
            <?php foreach (fabo_cm_rules() as $rk => $rl): ?>
              <option value="<?= e($rk) ?>"<?= ($cmEdit['rule'] ?? 'all') === $rk ? ' selected' : '' ?>><?= e($rl) ?></option>
            <?php endforeach; ?>
          </select></div>
        <?php /* Only for "A set number must approve". */ ?>
        <div class="sb-field" id="cmcNeedBox"<?= ($cmEdit['rule'] ?? '') === 'count' ? '' : ' hidden' ?>>
          <label>How many must approve</label>
          <input type="number" name="required" id="cmcNeed" min="1" step="1" style="width:110px"
                 value="<?= (int) ($cmEdit['required'] ?? 0) ?: '' ?>" placeholder="e.g. 3">
          <span class="hint" id="cmcNeedOf"></span></div>
      </div>

      <div class="cmc-pick">
        <div class="cmc-pickhead">
          <label>Members <span class="hint" id="cmcCount"><?= count($cmOn) ?> chosen</span></label>
          <input type="search" id="cmcFind" placeholder="Find a person…" autocomplete="off">
          <button type="button" class="btn btn-sm" id="cmcAll">Select all</button>
          <button type="button" class="btn btn-sm" id="cmcNone">Clear</button>
        </div>
        <?php if (!$cmPeople): ?>
          <div class="empty">There is nobody to put on a committee yet. Add a person with the
            <strong>Committee member</strong> box ticked &mdash; on
            <?= (int) $scope === 0 ? 'Team Management or Clients' : 'My Team or My Clients' ?> &mdash;
            and they appear here.</div>
        <?php else: ?>
          <div class="cmc-people-pick" id="cmcList">
            <?php foreach ($cmPeople as $p): ?>
              <label class="cmc-person" data-find="<?= e(mb_strtolower($p['name'] . ' ' . ($p['email'] ?? '') . ' ' . ($p['organisation'] ?? ''))) ?>">
                <input type="checkbox" name="members[]" value="<?= (int) $p['id'] ?>"
                       <?= in_array((int) $p['id'], $cmOn, true) ? 'checked' : '' ?>>
                <span class="cmav"><?= e(mb_strtoupper(mb_substr(trim((string) $p['name']), 0, 1))) ?></span>
                <span class="cmc-pname"><strong><?= e((string) $p['name']) ?></strong>
                  <small><?= e(trim(($p['kind'] === 'team' ? 'Team' : 'Client') . ' · ' . ($p['email'] ?? ''), ' ·')) ?></small></span>
              </label>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
      <div class="sb-row" style="justify-content:flex-end">
        <?php if ($cmEdit): ?><a class="btn" href="sheets.php?tab=committees">Cancel</a><?php endif; ?>
        <button class="btn btn-primary"<?= $cmPeople ? '' : ' disabled' ?>><?= $cmEdit ? 'Save committee' : 'Create committee' ?></button>
      </div>
      <p class="sb-note">Taking somebody off a committee removes their vote, and its
        events are decided again on the members who remain.</p>
    </form>
  </div>

  <?php $acNow = fabo_cm_auto_committee($scope); $acLast = fabo_cm_auto_last_run($scope); ?>
  <div class="block" id="cmauto">
    <div class="sb-head">
      <div>
        <span class="eyebrow">Event approvals</span>
        <h2>Upcoming events go to a committee on their own</h2>
      </div>
      <p class="sb-lead">Choose a committee and nobody needs to assign one: every upcoming event
        (as Event Management's Upcoming Events counts them) that has <strong>both a Start Date and
        an End Date</strong> is sent to it with its budget, and shows on each member's
        <strong>Committee Dashboard</strong> with a notification, to approve or reject. An event
        without both dates waits until they are filled in. <?= fabo_event_mail_off('committee') ? 'Members are told by a notification only: emails from Event Management are switched off.' : 'Members get <strong>one email</strong>
        listing every event sent at once, never one per event.' ?> An event already approved or
        rejected keeps that decision. Choosing another committee in an event's Committee column
        still overrides it.</p>
    </div>
    <form method="post" class="sb-add">
      <?= fabo_csrf_field() ?>
      <input type="hidden" name="back" value="sheets.php?tab=committees">
      <input type="hidden" name="action" value="cm_auto">
      <div class="sb-row" style="align-items:flex-end">
        <div class="sb-field"><label>Send upcoming events to</label>
          <select name="committee_id">
            <option value="0">Nobody (off — assign by hand)</option>
            <?php foreach ($cmList as $c): ?>
              <option value="<?= (int) $c['id'] ?>"<?= $acNow && (int) $acNow['id'] === (int) $c['id'] ? ' selected' : '' ?>><?= e(fabo_cm_title((string) $c['name'])) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="sb-field"><label>&nbsp;</label><button class="btn btn-primary"<?= $cmList ? '' : ' disabled' ?>>Save</button></div>
      </div>
      <p class="sb-note"><?= $acNow ? 'On: upcoming events go to ' . e(fabo_cm_title((string) $acNow['name'])) . '.' : 'Off.' ?>
        Last automatic run: <?= $acLast !== '' ? e($acLast) : 'none yet.' ?></p>
    </form>
    <?php $acSkip = $acNow ? fabo_cm_auto_skipped($scope, $acNow) : []; if ($acSkip): ?>
      <details class="sb-add" style="margin-top:10px"<?= count($acSkip) <= 15 ? ' open' : '' ?>>
        <summary style="cursor:pointer;font-weight:600"><?= count($acSkip) ?> upcoming event<?= count($acSkip) === 1 ? ' is' : 's are' ?>
          not with <?= e(fabo_cm_title((string) $acNow['name'])) ?> — and why</summary>
        <p class="sb-note">To send one anyway, choose the committee in that event's Committee column on Event Management.</p>
        <div class="scrollx"><table class="data">
          <thead><tr><th>Event ID</th><th>Event</th><th>Starts</th><th>Why it is not with the committee</th></tr></thead>
          <tbody>
          <?php foreach ($acSkip as $sk): ?>
            <tr><td><?= e($sk['event_id']) ?></td><td><?= e($sk['event_name'] !== '' ? $sk['event_name'] : '—') ?></td>
              <td><?= $sk['start_date'] !== '' ? e(date('d M Y', strtotime($sk['start_date']))) : 'No date' ?></td>
              <td><?= e($sk['why']) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      </details>
    <?php endif; ?>
  </div>

  <?php $rc = fabo_cm_remind_cfg($scope); $rcLast = fabo_cm_remind_last_run($scope); ?>
  <div class="block" id="cmremind">
    <div class="sb-head">
      <div>
        <span class="eyebrow">Event approvals</span>
        <h2>Reminders</h2>
      </div>
      <p class="sb-lead">Committee members who have not voted are reminded automatically.
        <?= fabo_event_mail_off('committee reminder') ? 'Each gets a notification on their dashboard (emails from Event Management are switched off).' : 'Each gets <strong>one email</strong> listing every event still waiting on them,
        never one per event.' ?> Reminders stop as soon as they vote or the committee decides.
        To remind about particular events now, tick them on the Events sheet and press
        <em>Remind committee</em>.</p>
    </div>
    <form method="post" class="sb-add">
      <?= fabo_csrf_field() ?>
      <input type="hidden" name="back" value="sheets.php?tab=committees">
      <input type="hidden" name="action" value="cm_remind">
      <div class="sb-row" style="align-items:flex-end">
        <div class="sb-field"><label>Automatic reminders</label>
          <label style="display:flex;align-items:center;gap:6px;font-weight:400;padding:7px 0">
            <input type="checkbox" name="on" value="1"<?= $rc['on'] ? ' checked' : '' ?>> On</label></div>
        <div class="sb-field"><label>First reminder after</label>
          <span style="display:flex;align-items:center;gap:6px">
            <input type="number" name="after" min="0" max="30" step="1" style="width:80px" value="<?= (int) $rc['after'] ?>">
            <span class="hint">days waiting</span></span></div>
        <div class="sb-field"><label>Repeat every</label>
          <span style="display:flex;align-items:center;gap:6px">
            <input type="number" name="every" min="1" max="30" step="1" style="width:80px" value="<?= (int) $rc['every'] ?>">
            <span class="hint">days, until they vote</span></span></div>
        <div class="sb-field"><label>Send from</label>
          <select name="hour">
            <?php for ($h = 6; $h <= 20; $h++): ?>
              <option value="<?= $h ?>"<?= $h === (int) $rc['hour'] ? ' selected' : '' ?>><?= e(date('g:i A', mktime($h, 0))) ?></option>
            <?php endfor; ?>
          </select></div>
        <div class="sb-field"><label>&nbsp;</label><button class="btn btn-primary">Save reminders</button></div>
      </div>
      <p class="sb-note">Last automatic run: <?= $rcLast !== '' ? e($rcLast) : 'none yet.' ?></p>
    </form>
  </div>
  <script>
  (function () {
    var list = document.getElementById('cmcList');
    if (!list) { return; }
    var boxes = list.querySelectorAll('input[type=checkbox]');
    var count = document.getElementById('cmcCount');
    /* "A set number must approve": the number box shows with that rule, and
       can never ask for more approvals than there are members. */
    var rule = document.getElementById('cmcRule');
    var needBox = document.getElementById('cmcNeedBox');
    var need = document.getElementById('cmcNeed');
    var needOf = document.getElementById('cmcNeedOf');
    function tally() {
      var n = Array.prototype.filter.call(boxes, function (b) { return b.checked; }).length;
      count.textContent = n + ' chosen';
      var isCount = rule && rule.value === 'count';
      if (needBox) { needBox.hidden = !isCount; }
      if (need) {
        need.required = isCount;
        need.max = String(Math.max(1, n));
        needOf.textContent = 'of ' + n + ' member' + (n === 1 ? '' : 's');
      }
    }
    list.addEventListener('change', tally);
    if (rule) { rule.addEventListener('change', tally); }
    tally();
    document.getElementById('cmcFind').addEventListener('input', function () {
      var q = this.value.trim().toLowerCase();
      list.querySelectorAll('.cmc-person').forEach(function (l) {
        l.hidden = q !== '' && l.getAttribute('data-find').indexOf(q) === -1;
      });
    });
    function set(on) {
      boxes.forEach(function (b) { if (!b.closest('.cmc-person').hidden) { b.checked = on; } });
      tally();
    }
    document.getElementById('cmcAll').addEventListener('click', function () { set(true); });
    document.getElementById('cmcNone').addEventListener('click', function () { set(false); });
  })();
  </script>
<?php endif; ?>

</div>
<script>
(function () {
  /* The dropdown-list box only means anything for a dropdown column, and the
     note under the picker says what the chosen type actually does. */
  var type = document.getElementById('coltype');
  if (!type) { return; }
  var wrap = document.getElementById('collistwrap');
  var note = document.getElementById('coltypenote');
  function show() {
    if (wrap) { wrap.hidden = type.value !== 'select'; }
    var t = document.querySelector('template[data-type="' + type.value + '"]');
    if (note) { note.textContent = t ? t.innerHTML : ''; }
  }
  type.addEventListener('change', show);
  show();
})();
</script>
<script src="<?= e(fabo_asset($viewer['is_admin'] ? '../assets/colorder.js' : 'assets/colorder.js')) ?>"></script>
<?php fabo_foot();

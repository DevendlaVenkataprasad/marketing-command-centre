<?php
/**
 * Incubation Management — the board (Kanban) views.
 *
 * One column per stage, in pipeline order; one card per application (or
 * workspace). Dragging a card to another column moves it through the same
 * inc_app_move_stage() / inc_ws_move_stage() the rest of the module uses, so
 * every permission, allowed-transition and requirement rule still applies —
 * a refused move springs back with the reason.
 */

const INC_BOARD_PER_COLUMN = 60;

/** Applications matching $f, grouped into the application pipeline's columns. */
function inc_board_apps(array $f): array
{
    inc_need('view');
    [$where, $t, $p] = inc_app_where($f);
    /* Each column is counted, and its cards read, on its own: one busy column
       (thousands loaded at New) must neither cap the others' counts nor push
       their older cards off the board. A Gem column reads all it can, since it
       is ranked by score; any other shows its newest. */
    $totals = [];
    foreach (inc_all('SELECT a.stage_id, COUNT(*) AS n FROM inc_applications a
                       LEFT JOIN inc_startups s ON s.id = a.startup_id LEFT JOIN inc_workspaces w ON w.id = a.workspace_id
                      WHERE ' . $where . ' GROUP BY a.stage_id', $t, $p) as $c) {
        $totals[(int) $c['stage_id']] = (int) $c['n'];
    }
    $rows = [];
    foreach ($totals as $sid => $n) {
        $lim = inc_stage_gem_ids(inc_stage($sid)) ? 1500 : INC_BOARD_PER_COLUMN;
        array_push($rows, ...inc_all('SELECT a.*, st.name AS stage_name, s.dev_stage, w.ref AS ws_ref, w.owner_id AS ws_owner_id, w.team_id AS ws_team_id,
                            (SELECT MAX(h.created_at) FROM inc_stage_history h WHERE h.pipeline = "crm" AND h.entity_id = a.id) AS stage_since
                     FROM inc_applications a JOIN inc_stages st ON st.id = a.stage_id
                     LEFT JOIN inc_startups s ON s.id = a.startup_id LEFT JOIN inc_workspaces w ON w.id = a.workspace_id
                     WHERE (' . $where . ') AND a.stage_id = ? ORDER BY a.submitted_at DESC, a.id DESC LIMIT ' . $lim, $t . 'i', array_merge($p, [$sid])));
    }

    /* In a column that shows Gem scores, the highest score comes first, then
       those still answering, then those not yet sent the form. Other columns
       keep newest first (the sort is stable, and they all rank equal). */
    $state = [];
    if ($rows) {
        foreach (inc_all('SELECT application_id, gem_id, score, submitted_at, sent_at, score_error FROM inc_gem_requests WHERE application_id IN ('
                         . implode(',', array_map(static fn($r) => (int) $r['id'], $rows)) . ')') as $q) {
            $state[(int) $q['application_id']][(int) $q['gem_id']] = $q;
        }
    }
    $gemsOf = [];
    foreach ($rows as &$r) {
        $r['gem_rank'] = -3;
        $r['gem_state'] = '';
        $sid = (int) $r['stage_id'];
        $gemsOf[$sid] ??= inc_stage_gem_ids(inc_stage($sid));
        foreach ($gemsOf[$sid] as $gid) {
            $q = $state[(int) $r['id']][$gid] ?? null;
            if ($q && $q['score'] !== null) {
                $r['gem_rank'] = max($r['gem_rank'], (float) $q['score']);
            } elseif ($q && $q['submitted_at']) {
                $r['gem_rank'] = max($r['gem_rank'], -1);
                $r['gem_state'] = $r['gem_state'] ?: ($q['score_error'] ? 'Score failed · retrying' : 'Answered · scoring');
            } elseif ($q && $q['sent_at']) {
                $r['gem_rank'] = max($r['gem_rank'], -2);
                $r['gem_state'] = $r['gem_state'] ?: 'Awaiting answers';
            } else {
                $r['gem_state'] = $r['gem_state'] ?: 'Form not sent';
            }
        }
    }
    unset($r);
    // Elsewhere (New Applications): say when a Gem's form is out and not yet answered.
    $gemNames = array_column(inc_gems(), 'name', 'id');
    foreach ($rows as &$r) {
        if ($r['gem_state'] === '') {
            foreach ($state[(int) $r['id']] ?? [] as $gid => $q) {
                if ($q['sent_at'] && !$q['submitted_at']) {
                    $r['gem_state'] = ($gemNames[$gid] ?? 'Gem') . ' sent';
                    break;
                }
            }
        }
    }
    unset($r);
    usort($rows, static fn($a, $b) => $b['gem_rank'] <=> $a['gem_rank']);
    return inc_board_group('crm', $rows, $totals);
}

/** Workspaces, grouped into the incubation lifecycle's columns. */
function inc_board_workspaces(array $f): array
{
    return inc_board_group('lifecycle', inc_workspaces_list($f));
}

/** [stage id => ['stage' => row, 'items' => [...], 'total' => n]] — active stages, plus any inactive one still holding records. */
function inc_board_group(string $pipeline, array $rows, ?array $totals = null): array
{
    $cols = [];
    foreach (inc_stages($pipeline) as $s) {
        $cols[(int) $s['id']] = ['stage' => $s, 'items' => [], 'total' => (int) ($totals[(int) $s['id']] ?? 0)];
    }
    foreach ($rows as $r) {
        $sid = (int) $r['stage_id'];
        if (!isset($cols[$sid])) {
            continue;
        }
        if ($totals === null) {
            $cols[$sid]['total']++;
        }
        if (count($cols[$sid]['items']) < INC_BOARD_PER_COLUMN) {
            $cols[$sid]['items'][] = $r;
        }
    }
    return array_filter($cols, static fn($c) => (int) $c['stage']['active'] === 1 || $c['total'] > 0);
}

/** Initials for the owner chip. */
function inc_board_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $i = '';
    foreach (array_slice($parts, 0, 2) as $w) {
        $i .= mb_strtoupper(mb_substr($w, 0, 1));
    }
    return $i;
}

/**
 * The board itself. $kind 'app' or 'ws'; $moreLink(stageId) gives the "see all" link for a long column.
 * $selectForm names the bulk-action form the cards' tick boxes belong to ('' for none).
 */
function inc_board_html(array $cols, string $kind, callable $moreLink, string $selectForm = '', ?callable $dlLink = null): string
{
    require_once __DIR__ . '/stages_ui.php';
    $pipe = $kind === 'ws' ? 'lifecycle' : 'crm';
    $canMove = inc_can('stage');
    $gemScores = [];
    $facts = [];
    if ($kind === 'app') {
        $all = [];
        foreach ($cols as $c) {
            array_push($all, ...$c['items']);
        }
        $gemScores = inc_gem_scores_for_rows($all);
        $facts = inc_card_facts_for_rows($all);
    }
    $h = '<div class="kb" data-kind="' . e($kind) . '"' . ($canMove ? ' data-move="1"' : '') . '>'
       . ($canMove ? fabo_csrf_field() : '')
       . '<div class="kb-flash" role="status" aria-live="polite" hidden></div><div class="kb-cols">';
    foreach ($cols as $sid => $c) {
        $s = $c['stage'];
        $req = inc_stage_requires($s);
        $tone = (int) $s['is_terminal'] === 1 ? ' kb-final' : '';
        $h .= '<section class="kb-col' . $tone . ((int) $s['active'] !== 1 ? ' kb-off' : '') . '" data-stage="' . $sid . '"'
            . (!empty($req['reason']) ? ' data-reason="1"' : '') . ' aria-label="' . e($s['name']) . '">'
            . '<header class="kb-colhead">' . ($selectForm !== '' && $c['items'] ? '<input type="checkbox" class="kb-colall" data-colall title="Select every card in this column" aria-label="Select all in ' . e($s['name']) . '">' : '')
            . '<span class="kb-colname">' . e($s['name']) . '</span><span class="kb-count">' . (int) $c['total'] . '</span>'
            // Download this stage: Excel, full PDF reports, or a summary list.
            . ($dlLink && $c['total'] && function_exists('inc_download_menu')
               ? inc_download_menu(static fn($k) => $dlLink($sid, $k), 'All ' . (int) $c['total'] . ' at "' . $s['name'] . '"', 'kb-dl') : '')
            . inc_stage_menu_html($s, $pipe) . '</header>'
            . '<div class="kb-list">';
        foreach ($c['items'] as $r) {
            $h .= $kind === 'app' ? inc_board_app_card($r, $gemScores[(int) $r['id']] ?? [], $canMove, $selectForm, $facts[(int) $r['id']] ?? []) : inc_board_ws_card($r, $canMove);
        }
        if (!$c['items']) {
            $h .= '<div class="kb-empty">Nothing here</div>';
        }
        $h .= '</div>';
        if ($c['total'] > count($c['items'])) {
            $h .= '<a class="kb-more" href="' . e($moreLink($sid)) . '">See all ' . (int) $c['total'] . ' →</a>';
        }
        $h .= '</section>';
    }
    return $h . inc_stage_add_column_html($pipe, array_keys($cols)) . '</div></div>';
}

/**
 * The answers each application shows on its card: the questions its form
 * version marks "Show on board card as" (rules.card), in form order.
 * Returns app id => [[label, text], ...].
 */
function inc_card_facts_for_rows(array $rows): array
{
    $byVersion = [];
    foreach ($rows as $r) {
        if (!empty($r['form_version_id'])) {
            $byVersion[(int) $r['form_version_id']][] = (int) $r['id'];
        }
    }
    if (!$byVersion) {
        return [];
    }
    $fields = [];
    foreach (inc_all('SELECT * FROM inc_form_fields WHERE version_id IN (' . implode(',', array_keys($byVersion)) . ') AND active = 1
                       AND rules_json LIKE \'%"card"%\' ORDER BY sort, id') as $f) {
        $f['options'] = json_decode((string) $f['options_json'], true) ?: [];
        $f['rules'] = json_decode((string) $f['rules_json'], true) ?: [];
        if (($f['rules']['card'] ?? '') !== '') {
            $fields[(int) $f['version_id']][$f['uid']] = $f;
        }
    }
    $ids = [];
    foreach ($byVersion as $vid => $appIds) {
        if (isset($fields[$vid])) {
            array_push($ids, ...$appIds);
        }
    }
    if (!$ids) {
        return [];
    }
    $versionOf = [];
    foreach ($byVersion as $vid => $appIds) {
        foreach ($appIds as $id) {
            $versionOf[$id] = $vid;
        }
    }
    $vals = [];
    foreach (inc_all('SELECT application_id, field_uid, type, value_text FROM inc_app_values WHERE application_id IN (' . implode(',', $ids) . ')') as $v) {
        $vals[(int) $v['application_id']][$v['field_uid']] = $v;
    }
    $out = [];
    foreach ($ids as $id) {
        foreach ($fields[$versionOf[$id]] as $uid => $f) {
            $v = $vals[$id][$uid] ?? null;
            if (!$v || (string) $v['value_text'] === '') {
                continue;
            }
            $value = in_array($v['type'], ['multiselect', 'checkbox'], true) ? (json_decode((string) $v['value_text'], true) ?: []) : $v['value_text'];
            $text = trim(inc_value_text($f, $value));
            if ($text !== '') {
                $out[$id][] = [(string) $f['rules']['card'], $text];
            }
        }
    }
    return $out;
}

function inc_board_app_card(array $a, array $gems, bool $drag, string $selectForm = '', array $facts = []): string
{
    $id = (int) $a['id'];
    $name = $a['startup_name'] !== '' ? $a['startup_name'] : $a['applicant_name'];
    $owner = (int) ($a['ws_owner_id'] ?? $a['owner_id'] ?? 0);
    $ownerName = $owner ? inc_person_name($owner) : '';
    $since = $a['stage_since'] ?: $a['submitted_at'];
    $days = (int) floor((time() - strtotime((string) $since)) / 86400);
    $chips = '';
    foreach ($gems as [$g, $score]) {
        $chips .= inc_gem_score_html($g, $score);
    }
    if ($a['official_score'] !== null) {
        $chips .= '<span class="kb-chip" title="Official evaluation score">Eval ' . e(inc_fmt_score($a['official_score'])) . '</span>';
    } elseif (!in_array($a['eval_status'], ['none', ''], true)) {
        $chips .= inc_eval_badge($a['eval_status']);
    }
    if (!$gems && ($a['gem_state'] ?? '') !== '') {
        $chips .= inc_badge($a['gem_state'], $a['gem_state'] === 'Form not sent' ? '' : 'amber');
    }
    if ($a['dup_status'] === 'suspected') {
        $chips .= inc_badge('Duplicate?', 'red');
    }
    return '<article class="kb-card"' . ($drag ? ' draggable="true"' : '') . ' data-id="' . $id . '">'
         . ($selectForm !== '' ? '<input type="checkbox" class="inc-sel kb-sel" name="ids[]" value="' . $id . '" form="' . e($selectForm) . '" aria-label="Select ' . e($name) . '">' : '')
         . '<a class="kb-title" data-popup="' . e(inc_url('quick', ['id' => $id])) . '" href="' . e(inc_url('application', ['id' => $id])) . '">' . e($name) . '</a>'
         . '<div class="kb-sub">' . e($a['startup_name'] !== '' ? $a['applicant_name'] : (string) $a['applicant_email']) . '</div>'
         // Who it is from (Student, Startup, Mentor...), then where it came from (Website, Program, Walk-in...).
         . '<div class="kb-tags">' . (!empty($a['applicant_type_id'])
                  ? '<span class="kb-type" title="Applicant type">' . e(inc_master_name((int) $a['applicant_type_id'])) . '</span>' : '')
              . '<span class="kb-src" title="Where it came from">' . e(inc_came_from($a)) . '</span></div>'
         // The answers its form puts on the card (expertise, sectors...).
         . ($facts ? '<dl class="kb-facts">' . implode('', array_map(static fn($x) => '<div><dt>' . e($x[0]) . '</dt><dd title="' . e($x[1]) . '">' . e($x[1]) . '</dd></div>', $facts)) . '</dl>' : '')
         . ($chips !== '' ? '<div class="kb-chips">' . $chips . '</div>' : '')
         . '<footer class="kb-foot"><span>' . e($a['ref']) . '</span><span title="Days at this stage">' . ($days <= 0 ? 'today' : $days . 'd') . '</span>'
         . ($ownerName !== '' ? '<span class="kb-av" title="' . e($ownerName) . '">' . e(inc_board_initials($ownerName)) . '</span>' : '')
         . '</footer></article>';
}

function inc_board_ws_card(array $w, bool $drag): string
{
    $p = inc_ws_progress((int) $w['id']);
    $ownerName = inc_person_name((int) $w['owner_id']);
    return '<article class="kb-card"' . ($drag && !$w['closed_at'] ? ' draggable="true"' : '') . ' data-id="' . (int) $w['id'] . '">'
         . '<a class="kb-title" data-popup href="' . e(inc_url('workspace', ['id' => $w['id']])) . '">' . e($w['startup_name'] ?: $w['applicant_name']) . '</a>'
         . '<div class="kb-sub">' . e(inc_team_name((int) $w['team_id'])) . '</div>'
         . '<div class="kb-progress" title="Checklist ' . (int) $p['pct'] . '% done"><i style="width:' . (int) $p['pct'] . '%"></i></div>'
         . '<footer class="kb-foot"><span>' . e($w['ref']) . '</span>' . ($w['closed_at'] ? inc_badge('Closed') : '<span>' . (int) $p['pct'] . '%</span>')
         . ($ownerName !== '' ? '<span class="kb-av" title="' . e($ownerName) . '">' . e(inc_board_initials($ownerName)) . '</span>' : '')
         . '</footer></article>';
}

/** Handles a drag-and-drop move (POST action=board_move); answers JSON and stops. */
function inc_board_move(string $kind): void
{
    header('Content-Type: application/json');
    try {
        $id = (int) ($_POST['id'] ?? 0);
        $stage = (int) ($_POST['stage_id'] ?? 0);
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if ($kind === 'ws') {
            inc_ws_move_stage($id, $stage, $reason);
        } else {
            inc_app_move_stage($id, $stage, $reason);
        }
        echo json_encode(['ok' => true, 'msg' => 'Moved to ' . (inc_stage($stage)['name'] ?? 'the new stage') . '.']);
    } catch (InvalidArgumentException $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    } catch (Throwable $e) {
        error_log('FABO incubation board move: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'msg' => 'That could not be moved just now.']);
    }
    exit;
}

/** The view switch shown in a page's header: Board | List. */
function inc_view_switch(string $page, string $view, array $q): string
{
    $mk = static fn($v, $label) => '<a class="' . ($view === $v ? 'on' : '') . '" href="' . e(inc_url($page, ['view' => $v] + $q)) . '"'
                                    . ($view === $v ? ' aria-current="page"' : '') . '>' . $label . '</a>';
    return '<nav class="inc-seg" aria-label="View">' . $mk('board', 'Board') . $mk('list', 'List') . '</nav>';
}

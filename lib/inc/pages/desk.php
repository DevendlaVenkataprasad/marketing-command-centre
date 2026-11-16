<?php
/**
 * Incubation Management — a department's desk (lib/inc/depts.php).
 *
 * The applications handed to a department, on a board of the department's
 * own stages. Members open one (its answers and documents), move it along,
 * add notes the incubation team reads on the application's timeline, and
 * return it to the incubation team with a remark. They also keep their own
 * stages. Whoever runs Incubation Management sees every department's desk.
 */

require_once __DIR__ . '/parts.php';
require_once __DIR__ . '/../board.php';
require_once __DIR__ . '/../appreport.php';

/* Departments, mentors and jury share this desk; each kind is a desk of its
   own (?kind=mentor, ?kind=jury), showing only that kind's teams. */
$kindsAll = inc_dept_kinds();
$deskKinds = inc_my_desk_kinds();
$deptId = (int) ($_GET['dept'] ?? 0);
$kind = (string) ($_GET['kind'] ?? '');
foreach ($deskKinds as $k => $list) {
    if ($deptId && isset($list[$deptId])) {
        $kind = $k;                                  // a team's own desk, whatever ?kind says
    }
}
if (!isset($kindsAll[$kind])) {
    $kind = (string) (array_key_first($deskKinds) ?? 'department');
}
$K = $kindsAll[$kind];
$GLOBALS['inc_desk_kind'] = $kind;              // the menu marks the right desk (lib/inc/nav.php)
$depts = $deskKinds[$kind] ?? [];
if (!isset($depts[$deptId])) {
    $deptId = (int) (array_key_first($depts) ?? 0);
}
$openId = (int) ($_GET['a'] ?? 0);
// Board (Kanban, drag to move) by default; the list shows every open one in a table.
$view = ($_GET['view'] ?? '') === 'list' ? 'list' : 'board';
// Filters (the bar above the board and list) ride along on every link, the export included.
$f = inc_dept_filters($_GET);
$fq = $f;
$vq = ($view === 'list' ? ['view' => 'list'] : []) + $fq;
$self = inc_url('desk', ['dept' => $deptId] + $vq + ($openId ? ['a' => $openId] : []));
$retLabel = ['department' => 'Return to the incubation team', 'mentor' => 'Return with your mentoring remarks', 'jury' => 'Return with your verdict'][$kind];
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $deptId) {
    $act = (string) ($_POST['action'] ?? '');
    // "Send Gem form" in the selection bar submits the bulk email form with gem_go.
    if (!empty($_POST['gem_go'])) {
        $act = 'gem_send';
    }
    $aid = (int) ($_POST['assign_id'] ?? 0);
    if ($act === 'board_move') {
        // A card dragged to another column (assets/inc.js); answers JSON and stops.
        header('Content-Type: application/json');
        try {
            inc_dept_set_stage((int) ($_POST['id'] ?? 0), (int) ($_POST['stage_id'] ?? 0));
            echo json_encode(['ok' => true, 'msg' => 'Moved.']);
        } catch (InvalidArgumentException $e) {
            echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
        } catch (Throwable $e) {
            error_log('FABO department board move: ' . $e->getMessage());
            echo json_encode(['ok' => false, 'msg' => 'That could not be moved just now.']);
        }
        exit;
    }
    $flash = inc_action(static function () use ($act, $aid, $deptId, $vq) {
        switch ($act) {
            case 'move':
                inc_dept_set_stage($aid, (int) ($_POST['stage_id'] ?? 0));
                return 'Moved.';
            case 'note':
                inc_dept_note($aid, (string) ($_POST['note'] ?? ''));
                return 'Note added. The incubation team sees it on the application.';
            case 'return':
                inc_dept_return($aid, (string) ($_POST['note'] ?? ''));
                inc_redirect(inc_url('desk', ['dept' => $deptId] + $vq), ['ok', 'Returned to the incubation team, with your remarks.']);
            case 'stage_save':
                inc_dept_stage_save($deptId, inc_int_or_null($_POST['id'] ?? null), (string) ($_POST['name'] ?? ''), !empty($_POST['is_final']),
                                    isset($_POST['gem_form']) ? (array) ($_POST['gem_ids'] ?? []) : null);
                return 'Stage saved.';
            case 'gem_send':
                // One application (opened), or the ones ticked on the board or in the list.
                $r = inc_dept_gem_send($deptId, (int) ($_POST['gem_id'] ?? 0), $aid ? [$aid] : array_map('intval', (array) ($_POST['ids'] ?? [])));
                if (($r['sent'] + $r['queued']) === 0) {
                    throw new InvalidArgumentException('Not sent: ' . inc_gem_send_summary($r));
                }
                return 'Gem form: ' . inc_gem_send_summary($r);
            case 'gem_score':
                [$ok, $msg] = inc_dept_gem_score($aid, (int) ($_POST['gem_id'] ?? 0));
                if (!$ok) {
                    throw new InvalidArgumentException($msg);
                }
                return $msg;
            case 'stage_move':
                inc_dept_stage_move($deptId, (int) ($_POST['id'] ?? 0), (int) ($_POST['dir'] ?? 0));
                return 'Order saved.';
            case 'stage_delete':
                inc_dept_stage_delete($deptId, (int) ($_POST['id'] ?? 0));
                return 'Stage removed.';
            case 'email':
                // One application (opened) or the ones ticked in the list: hand-off ids, turned into this department's applications.
                $ids = $aid ? [$aid] : array_map('intval', (array) ($_POST['ids'] ?? []));
                $apps = $ids ? array_map('intval', array_column(inc_all('SELECT application_id FROM inc_dept_assignments WHERE team_id = ? AND id IN ('
                                                                        . implode(',', $ids) . ')', 'i', [$deptId]), 'application_id')) : [];
                $r = inc_dept_email($deptId, $apps, (string) ($_POST['mail_subject'] ?? ''), (string) ($_POST['mail_body'] ?? ''));
                if (($r['sent'] + $r['queued']) === 0) {
                    throw new InvalidArgumentException('Not sent: ' . inc_gem_send_summary($r));
                }
                return 'Email: ' . inc_gem_send_summary($r) . ' Each one is on the application\'s timeline.';
        }
        throw new InvalidArgumentException('Nothing to do.');
    }, $self);
}

$sub = $kind === 'department'
    ? 'Applications handed to your department by the incubation team, and ones you add yourself. Move them through your stages, add notes, email applicants, and return them when you are done.'
    : 'Applications the owner assigned to you as ' . strtolower($K[1]) . '. Move them through your stages — Gem stages show the Gem scores — add notes, email applicants, and return each one when you are done.';
$crumbs = [$K[2] => null];

/* One tab per kind of desk the viewer has (or, for the owner, every kind). */
$kindTabs = '';
$tabKinds = inc_can('configure') ? array_keys($kindsAll) : array_keys($deskKinds);
if (count($tabKinds) > 1) {
    $kindTabs = '<nav class="inc-tabs dk-kinds" aria-label="Desks">';
    foreach ($tabKinds as $k) {
        $n = count($deskKinds[$k] ?? []);
        $kindTabs .= '<a' . ($k === $kind ? ' class="on" aria-current="page"' : '') . ' href="' . e(inc_url('desk', ['kind' => $k])) . '">' . e($kindsAll[$k][2])
                   . ' <span class="muted small">' . $n . '</span></a>';
    }
    $kindTabs .= '</nav>';
}

if (!$depts) {
    inc_layout_start($K[2], $sub, $crumbs, '', $flash);
    echo $kindTabs;
    echo inc_empty('No ' . strtolower($K[1]) . ' yet.', inc_can('configure')
        ? 'Create one under <a href="' . e(inc_url('settings', ['tab' => 'teams', 'kind' => $kind])) . '">Settings → Departments</a>: choose the type <strong>' . e($K[0]) . '</strong> and tick its people.'
        : 'You are not on one. The owner of Incubation Management adds people.');
    inc_layout_end();
    return;
}

$stages = inc_dept_stages($deptId);
$stageMap = [];
foreach ($stages as $s) {
    $stageMap[(int) $s['id']] = $s['name'];
}
$stageOf = static fn(array $r): int => $r['stage_id'] !== null && isset($stageMap[(int) $r['stage_id']]) ? (int) $r['stage_id'] : (int) $stages[0]['id'];
$byStage = static function (array $board) use ($f, $stageOf): array {
    if (isset($f['stage'])) {
        $board['open'] = array_values(array_filter($board['open'], static fn($r) => $stageOf($r) === (int) $f['stage']));
        $board['returned'] = [];
    }
    return $board;
};
// An opened application is found among all the open ones, whatever the filters.
$board = $byStage(inc_dept_board($deptId, 20, $openId ? [] : $f));

/* Excel: one sheet per department stage (with every form answer and links to
   the documents), the ones returned to the incubation team, and a count of
   each -- only the applications the filters let through. */
if (($_GET['download'] ?? '') === 'xlsx' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $board = $byStage(inc_dept_board($deptId, 5000, $f));   // every one returned, not only the latest
    $all = array_merge($board['open'], $board['returned']);
    $portal = rtrim(fabo_portal_url(), '/') . '/';
    $docLinks = [];
    foreach (inc_dept_docs($all) as $appId => $files) {
        $docLinks[$appId] = implode("\n", array_map(static fn($fl) => $fl['original_name'] . ': ' . $portal . inc_url('file', ['id' => $fl['id']]), $files));
    }
    $answers = [];
    $labels = [];
    $fieldsOf = [];
    foreach ($all as $r) {
        $app = inc_app((int) $r['application_id']);
        $fv = (int) ($app['form_version_id'] ?? 0);
        if (!isset($fieldsOf[$fv])) {
            $fieldsOf[$fv] = [];
            foreach (inc_form_fields($fv) as $fd) {
                $fieldsOf[$fv][$fd['uid']] = $fd;
            }
        }
        foreach (inc_app_values((int) $r['application_id']) as $uid => $v) {
            $labels[$v['label']] = true;
            $answers[(int) $r['id']][$v['label']] = $v['type'] === 'file' ? (string) $v['value'] : inc_value_text($fieldsOf[$fv][$uid] ?? null, $v['value']);
        }
    }
    $labels = array_keys($labels);
    $sheet = static function (array $rows, bool $returned) use ($answers, $labels, $stageMap, $stages, $docLinks): array {
        $out = [array_merge(['Application ref', 'Startup', 'Applicant', 'Email', 'Phone', 'Form', 'Submitted', 'Department stage', 'Assigned on', 'Assigned by', 'Note from the incubation team', 'Documents (sign in to open)'],
                            $returned ? ['Returned on', 'Returned by', 'Remarks'] : [], $labels)];
        foreach ($rows as $r) {
            $st = $stageMap[(int) $r['stage_id']] ?? ($stages[0]['name'] ?? '');
            $out[] = array_merge([$r['ref'], (string) $r['startup_name'], (string) $r['applicant_name'], (string) $r['applicant_email'], (string) $r['applicant_phone'], $r['form_name'],
                                  ['d' => substr((string) $r['submitted_at'], 0, 10)], $returned ? 'Returned' : $st, ['d' => substr((string) $r['assigned_at'], 0, 10)],
                                  (string) $r['assigned_by'], (string) $r['note'], $docLinks[(int) $r['application_id']] ?? ''],
                                 $returned ? [['d' => substr((string) $r['returned_at'], 0, 10)], (string) $r['returned_by'], (string) $r['return_note']] : [],
                                 array_map(static fn($l) => (string) ($answers[(int) $r['id']][$l] ?? ''), $labels));
        }
        return $out;
    };
    $summary = [['Stage', 'Applications']];
    $sheets = [];
    foreach ($stages as $i => $s) {
        $here = array_values(array_filter($board['open'], static fn($r) => (int) ($r['stage_id'] ?? 0) === (int) $s['id']
            || ($i === 0 && !isset($stageMap[(int) ($r['stage_id'] ?? 0)]))));
        $summary[] = [$s['name'], ['n' => count($here)]];
        $sheets[$s['name'] === 'Summary' ? 'Summary stage' : $s['name']] = $sheet($here, false);
    }
    $summary[] = ['Returned to the incubation team', ['n' => count($board['returned'])]];
    $sheets[isset($sheets['Returned']) ? 'Returned to incubation' : 'Returned'] = $sheet($board['returned'], true);
    $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $depts[$deptId]), '-')) ?: 'department';
    inc_send_xlsx($slug . '-applications' . ($f ? '-filtered' : '') . '-' . date('Ymd-His') . '.xlsx', ['Summary' => $summary] + $sheets);
}

$pick = $openId ? '' : (inc_dept_may_intake($deptId) ? '<a class="btn btn-sm btn-primary" data-popup href="' . e(inc_url('new', ['dept' => $deptId])) . '">+ Add application</a> ' : '')
      . inc_view_switch('desk', $view, ['dept' => $deptId] + $fq)
      . ' <a class="btn btn-sm" href="' . e(inc_url('desk', ['dept' => $deptId, 'download' => 'xlsx'] + $fq)) . '" title="' . ($f ? 'The applications the filters show, with every answer and links to their documents' : 'Every application, with every answer and links to their documents') . '">'
      . inc_dl_icon() . ' Export Excel' . ($f ? ' (' . count($board['open']) . ' filtered)' : '') . '</a>';

/**
 * The email fields: for one applicant ($assignId, on an opened application),
 * or for the ones ticked on the board or in the list ($assignId 0: the form
 * "deskMail" inside the dialog, which every card's checkbox belongs to).
 */
$mailBox = static function (int $assignId, string $to): string {
    $tokens = '';
    foreach (['{name}' => 'Applicant name', '{startup}' => 'Startup', '{ref}' => 'Reference'] as $t => $label) {
        $tokens .= '<button type="button" class="dk-token" data-token="' . e($t) . '" title="Insert ' . e($label) . '">' . e($t) . '</button>';
    }
    return inc_form_open($assignId ? 'class="dk-mailform"' : 'id="deskMail" class="dk-mailform"') . '<input type="hidden" name="action" value="email">'
         . ($assignId ? '<input type="hidden" name="assign_id" value="' . $assignId . '">' : '')
         . inc_field('Subject', '<input type="text" name="mail_subject" maxlength="190" required placeholder="e.g. Next step for {startup}">', '', true)
         . inc_field('Message', inc_textarea('mail_body', '', $assignId ? 6 : 9, 'required maxlength="5000" placeholder="Dear {name},"'), '', true)
         . '<div class="dk-tokens"><span>Insert</span>' . $tokens . '<small>filled in for each applicant</small></div>'
         . '<p class="muted small">It goes out under the organisation\'s name and is kept on each application\'s timeline.</p>'
         . '<div class="dk-mailfoot">' . ($assignId ? '' : '<button type="button" class="btn" data-dkclose>Cancel</button>')
         . '<button class="btn btn-primary"><span aria-hidden="true">✉</span> ' . ($assignId ? 'Send to ' . e($to) : 'Send <span data-dkcount>0</span> email<span data-dkpl>s</span>') . '</button></div></form>';
};
if (count($depts) > 1) {
    $pick .= '<form method="get" class="inc-row"><input type="hidden" name="p" value="desk"><input type="hidden" name="kind" value="' . e($kind) . '">' . ($view === 'list' ? '<input type="hidden" name="view" value="list">' : '')
           . inc_select('dept', $depts, $deptId) . ' <button class="btn btn-sm">Open</button></form>';
}
inc_layout_start($depts[$deptId], $sub, $crumbs, $pick, $flash);
echo $kindTabs;
$gemsAll = inc_gems(true);
$gemScores = inc_dept_gem_scores($board['open'], $stages);
$gemBadges = static fn(int $assignId): string => isset($gemScores[$assignId])
    ? '<div class="dk-gems">' . implode(' ', array_map(static fn($x) => inc_gem_score_html($x[0], $x[1]), $gemScores[$assignId])) . '</div>' : '';
/* The Gem stage ticks of a stage form: which Gems' scores it shows. */
$gemTicks = static function (array $on) use ($gemsAll): string {
    if (!$gemsAll) {
        return '';
    }
    $h = '<fieldset class="inc-fs"><legend>Gem stage — show these Gem scores here</legend><input type="hidden" name="gem_form" value="1">';
    foreach ($gemsAll as $gid => $g) {
        $h .= '<label class="check"><input type="checkbox" name="gem_ids[]" value="' . (int) $gid . '"' . (in_array((int) $gid, $on, true) ? ' checked' : '') . '> ' . e($g['name']) . '</label>';
    }
    return $h . '</fieldset>';
};

/* ------------------------------------------------ one application, opened */
if ($openId) {
    $d = null;
    foreach ($board['open'] as $r) {
        if ((int) $r['id'] === $openId) {
            $d = $r;
        }
    }
    if ($d) {
        $app = inc_app((int) $d['application_id']);
        echo '<section class="card"><div class="inc-row" style="justify-content:space-between"><h2 style="margin:0">' . e($app['startup_name'] ?: $app['applicant_name']) . ' <span class="muted small">' . e($app['ref']) . '</span></h2>'
           . '<a class="btn btn-sm" href="' . e(inc_url('desk', ['dept' => $deptId] + $vq)) . '">← Back to the ' . ($view === 'list' ? 'list' : 'board') . '</a></div>';
        if ($d['note']) {
            echo '<p class="inc-note"><strong>From the incubation team:</strong> ' . nl2br(e($d['note'])) . '</p>';
        }
        // Documents first: the pitch deck or CV is usually what the department opens the application for.
        echo '<div class="inc-grid2 wide-left"><div><h3>Documents</h3>' . inc_files_html(inc_files('application', (int) $app['id'])) . '<h3>Answers</h3><dl class="inc-answers">';
        $fields = [];
        foreach (inc_form_fields((int) $app['form_version_id']) as $f) {
            $fields[$f['uid']] = $f;
        }
        foreach (inc_app_values((int) $app['id']) as $uid => $v) {
            echo '<div><dt>' . e($v['label']) . '</dt><dd>';
            if ($v['type'] === 'file' && $v['file_id']) {
                echo '<a href="' . e(inc_url('file', ['id' => $v['file_id']])) . '" target="_blank" rel="noopener">' . e((string) $v['value']) . '</a>';
            } else {
                echo nl2br(e(inc_value_text($fields[$uid] ?? null, $v['value'])));
            }
            echo '</dd></div>';
        }
        echo '</dl></div><div>';

        echo '<h3>Stage</h3>' . inc_form_open('class="inc-row"') . '<input type="hidden" name="action" value="move"><input type="hidden" name="assign_id" value="' . (int) $d['id'] . '">'
           . inc_select('stage_id', $stageMap, $d['stage_id']) . ' <button class="btn btn-sm btn-primary">Move</button></form>';
        echo '<h3>Add a note</h3>' . inc_form_open() . '<input type="hidden" name="action" value="note"><input type="hidden" name="assign_id" value="' . (int) $d['id'] . '">'
           . inc_textarea('note', '', 3, 'required placeholder="What you checked or found — the incubation team reads this on the application."')
           . ' <button class="btn btn-sm">Add note</button></form>';
        echo '<h3>Email the applicant</h3>' . ($app['applicant_email'] ? $mailBox((int) $d['id'], (string) $app['applicant_email'])
                                                                    : '<p class="muted small">No email address on this application.</p>');
        if ($gemsAll) {
            echo '<h3>Gems</h3>' . (inc_dept_gem_html($app, $d, $stages) ?: '<p class="muted small">No Gem sent yet.</p>');
            echo inc_form_open('class="inc-row"') . '<input type="hidden" name="action" value="gem_send"><input type="hidden" name="assign_id" value="' . (int) $d['id'] . '">'
               . inc_select('gem_id', array_map(static fn($g) => $g['name'], $gemsAll), count($gemsAll) === 1 ? (int) array_key_first($gemsAll) : '', 'Choose Gem', 'required')
               . ' <button class="btn btn-sm">Email Gem form</button></form>';
        }
        echo '<h3>' . e($retLabel) . '</h3><p class="muted small">When you are done. It goes back to the incubation team with your '
           . ($kind === 'jury' ? 'verdict' : 'remarks') . '.</p>'
           . inc_form_open() . '<input type="hidden" name="action" value="return"><input type="hidden" name="assign_id" value="' . (int) $d['id'] . '">'
           . inc_textarea('note', '', 3, 'required placeholder="' . ($kind === 'jury' ? 'Your verdict and the reasons for it.' : ($kind === 'mentor' ? 'How the mentoring went, and what the startup should do next.' : 'Your conclusion and anything the incubation team should know.')) . '"')
           . ' <button class="btn btn-primary">' . e($retLabel) . '</button></form>';
        echo '<h3>Timeline</h3>' . inc_timeline_html(array_slice(inc_timeline((int) $app['id'], null), 0, 15));
        echo '</div></div></section>';
        inc_layout_end();
        return;
    }
    echo '<div class="inc-warn">That application is no longer open with this department.</div>';
}

/* ------------------------------------------------------------ the filters */
$keep = ['dept' => $deptId] + ($view === 'list' ? ['view' => 'list'] : []);
$forms = [];
foreach (inc_all('SELECT DISTINCT f.id, f.name FROM inc_dept_assignments d JOIN inc_applications a ON a.id = d.application_id JOIN inc_forms f ON f.id = a.form_id
                   WHERE d.team_id = ? ORDER BY f.name', 'i', [$deptId]) as $r) {
    $forms[(int) $r['id']] = $r['name'];
}
$defs = [
    'Form'          => ['form', $forms],
    'Stage'         => ['stage', $stageMap],
    'Documents'     => ['docs', ['yes' => 'Has a document', 'no' => 'No document']],
    'Duplicate check' => ['dup', ['suspected' => 'Suspected duplicate', 'none' => 'Not a suspected duplicate']],
];
$sorts = ['' => 'Oldest assigned first', 'newest' => 'Newest submitted first', 'oldest' => 'Oldest submitted first', 'name' => 'Name'];
$chips = [];
$chip = static fn(string $label, array $drop): string => '<a class="inc-chip" href="' . e(inc_url('desk', array_diff_key($keep + $fq, array_flip($drop)))) . '" title="Remove this filter">'
    . e($label) . ' <span aria-hidden="true">×</span></a>';
if (isset($f['q'])) {
    $chips[] = $chip('“' . $f['q'] . '”', ['q']);
}
foreach ($defs as $label => [$k, $opts]) {
    if (isset($f[$k])) {
        $chips[] = $chip($label . ': ' . ($opts[$f[$k]] ?? $f[$k]), [$k]);
    }
}
foreach (['from' => 'Submitted from', 'to' => 'Submitted to'] as $k => $l) {
    if (isset($f[$k])) {
        $chips[] = $chip($l . ' ' . inc_fmt_date($f[$k]), [$k]);
    }
}
if (isset($f['ans'])) {
    $chips[] = $chip('Answers contain “' . $f['ans'] . '”', ['ans']);
}
if (isset($f['sort'])) {
    $chips[] = $chip('Sorted: ' . $sorts[$f['sort']], ['sort']);
}
$n = count($chips) - (isset($f['q']) ? 1 : 0);
echo '<form method="get" class="inc-filters" role="search"><input type="hidden" name="p" value="desk">';
foreach ($keep as $k => $v) {
    echo '<input type="hidden" name="' . e($k) . '" value="' . e((string) $v) . '">';
}
echo '<div class="inc-fbar"><input type="search" name="q" value="' . e($f['q'] ?? '') . '" placeholder="Search by name, startup, email, phone or reference" aria-label="Search">'
   . '<button class="btn btn-primary">Search</button>'
   . '<details class="inc-fmore"><summary class="btn">Filters' . ($n ? ' <span class="inc-fcount">' . $n . '</span>' : '') . '</summary>'
   . '<div class="inc-fpanel"><div class="inc-fgrid">';
foreach ($defs as $label => [$k, $opts]) {
    echo '<label><span>' . e($label) . '</span>' . inc_select($k, $opts, $f[$k] ?? '', 'Any') . '</label>';
}
echo '<label><span>Submitted from</span><input type="date" name="from" value="' . e($f['from'] ?? '') . '"></label>'
   . '<label><span>Submitted to</span><input type="date" name="to" value="' . e($f['to'] ?? '') . '"></label>'
   . '<label><span>Any answer contains</span><input type="text" name="ans" maxlength="100" value="' . e($f['ans'] ?? '') . '" placeholder="e.g. AgriTech, Tirupati"></label>'
   . '<label><span>Sort by</span>' . inc_select('sort', $sorts, $f['sort'] ?? '') . '</label>'
   . '</div><div class="inc-factions"><a class="btn" href="' . e(inc_url('desk', $keep)) . '">Clear all</a><button class="btn btn-primary">Apply filters</button></div></div></details>'
   . '<span class="muted small">' . number_format(count($board['open'])) . ' open' . ($f ? ' match' : '') . '</span></div>';
if ($chips) {
    echo '<div class="inc-chips">' . implode('', $chips) . ' <a class="inc-clear" href="' . e(inc_url('desk', $keep)) . '">Clear all</a></div>';
}
echo '</form>';

/* ------------------------------------------------- the board, or the list */
$openLink = static fn(array $r): string => inc_url('desk', ['dept' => $deptId, 'a' => $r['id']] + $vq);
// A document link (the first one, with a count of any more), for a card or a row.
$docsLink = static function (array $files): string {
    if (!$files) {
        return '';
    }
    $fl = $files[0];
    return '<a class="dk-doc" href="' . e(inc_url('file', ['id' => $fl['id']])) . '" target="_blank" rel="noopener" title="Open ' . e($fl['original_name']) . '">📎 '
         . e(preg_replace('/\s*\(optional\)$/i', '', (string) $fl['label']) ?: 'Document') . '</a>' . (count($files) > 1 ? ' <span class="muted small">+' . (count($files) - 1) . '</span>' : '');
};
if ($view === 'board') {
    // The same Kanban as the Applications board (assets/inc.js): drag a card to another column to move it.
    echo '<p class="muted small" style="margin:0 0 8px">Drag a card to another column to move it, or open it to add notes and return it. Tick cards, or a whole column, to email several applicants at once.</p>'
       . '<div class="kb" data-kind="dept" data-move="1">' . fabo_csrf_field()
       . '<div class="kb-flash" role="status" aria-live="polite" hidden></div><div class="kb-cols">';
    /* The department's own stages are edited on the board itself, as on the
       Applications board: each column's ⋯ menu, and an "Add a stage" column. */
    $stageMenu = static fn(array $s, int $i): string => '<details class="kb-menu"><summary title="Edit this stage" aria-label="Edit stage ' . e($s['name']) . '">⋯</summary>'
        . '<div class="kb-dialog" role="dialog" aria-label="Edit stage"><h3>Stage: ' . e($s['name']) . '</h3>'
        . '<div class="inc-row">' . ($i > 0 ? inc_button('← Move left', ['action' => 'stage_move', 'id' => $s['id'], 'dir' => -1], 'btn btn-sm') : '')
        . ($i < count($stages) - 1 ? inc_button('Move right →', ['action' => 'stage_move', 'id' => $s['id'], 'dir' => 1], 'btn btn-sm') : '') . '</div>'
        . inc_form_open() . '<input type="hidden" name="action" value="stage_save"><input type="hidden" name="id" value="' . (int) $s['id'] . '">'
        . inc_field('Name', '<input type="text" name="name" value="' . e($s['name']) . '" maxlength="80" required>')
        . '<label class="check"><input type="checkbox" name="is_final" value="1"' . ((int) $s['is_final'] === 1 ? ' checked' : '') . '> Final stage</label> '
        . $gemTicks(inc_dept_stage_gem_ids($s))
        . '<button class="btn btn-primary btn-sm">Save stage</button></form>'
        . (count($stages) > 1 ? '<div class="kb-danger">' . inc_button('Delete this stage', ['action' => 'stage_delete', 'id' => $s['id']], 'btn btn-sm btn-reject',
                                       'Delete the stage "' . $s['name'] . '"? Only an empty stage can be deleted.')
                                . '<span class="muted small">Only when nothing is in it.</span></div>' : '')
        . '</div></details>';
    foreach ($stages as $i => $s) {
        $here = array_values(array_filter($board['open'], static fn($r) => $stageOf($r) === (int) $s['id']));
        echo '<section class="kb-col' . ((int) $s['is_final'] === 1 ? ' kb-final' : '') . '" data-stage="' . (int) $s['id'] . '" aria-label="' . e($s['name']) . '">'
           . '<header class="kb-colhead">' . ($here ? '<input type="checkbox" class="kb-colall" data-colall title="Select every card in this column" aria-label="Select all in ' . e($s['name']) . '">' : '')
           . '<span class="kb-colname">' . e($s['name']) . '</span>' . (inc_dept_stage_gem_ids($s) ? '<span class="kb-gemflag" title="Gem stage: Gem scores show here">◆ Gem</span>' : '') . '<span class="kb-count">' . count($here) . '</span>' . $stageMenu($s, $i) . '</header><div class="kb-list">';
        // A department fed by a busy form can hold thousands: the board shows the first 100 of each column, the list has them all.
        $docs = inc_dept_docs(array_slice($here, 0, 100));
        foreach (array_slice($here, 0, 100) as $r) {
            $days = (int) floor((time() - strtotime((string) $r['assigned_at'])) / 86400);
            $who = (string) ($r['startup_name'] ?: $r['applicant_name']);
            echo '<article class="kb-card" draggable="true" data-id="' . (int) $r['id'] . '">'
               . '<input type="checkbox" class="inc-sel kb-sel" form="deskMail" name="ids[]" value="' . (int) $r['id'] . '" data-name="' . e($who)
               . '" data-email="' . e((string) $r['applicant_email']) . '" aria-label="Select ' . e($who) . '">'
               . '<a class="kb-title" href="' . e($openLink($r)) . '">' . e($r['startup_name'] ?: $r['applicant_name']) . '</a>'
               . '<div class="kb-sub">' . e($r['form_name']) . '</div>'
               . (isset($docs[(int) $r['application_id']]) ? '<div class="dk-docs">' . $docsLink($docs[(int) $r['application_id']]) . '</div>' : '')
               . $gemBadges((int) $r['id'])
               . ($r['note'] ? '<div class="kb-tag">' . e(mb_strimwidth((string) $r['note'], 0, 110, '…')) . '</div>' : '')
               . '<footer class="kb-foot"><span>' . e($r['ref']) . '</span><span title="Days since it was assigned">' . ($days <= 0 ? 'today' : $days . 'd') . '</span>'
               . '<span class="kb-av" title="Assigned by ' . e($r['assigned_by']) . '">' . e(inc_board_initials((string) $r['assigned_by'])) . '</span></footer></article>';
        }
        echo '<div class="kb-empty"' . ($here ? ' hidden' : '') . '>Nothing here</div></div>'
           . (count($here) > 100 ? '<a class="kb-more" href="' . e(inc_url('desk', ['dept' => $deptId, 'view' => 'list', 'stage' => $s['id']] + $fq)) . '">See all ' . count($here) . ' in the list →</a>' : '') . '</section>';
    }
    echo '<section class="kb-col kb-add" aria-label="Add a stage"><header class="kb-colhead"><span class="kb-colname">Add a stage</span></header><div class="kb-list">'
       . inc_form_open() . '<input type="hidden" name="action" value="stage_save">'
       . '<input type="text" name="name" maxlength="80" required placeholder="Stage name, e.g. Site visit" aria-label="New stage name">'
       . '<label class="check"><input type="checkbox" name="is_final" value="1"> Final stage</label>'
       . $gemTicks([])
       . '<button class="btn btn-primary btn-sm">+ Add stage</button></form>'
       . '<p class="muted small">It goes at the end; move it with ← → in its ⋯ menu.</p></div></section>';
    echo '</div></div>';
} elseif ($board['open']) {
    echo '<section class="card"><div class="scrollx"><table class="data stack"><thead><tr><th><input type="checkbox" form="deskMail" data-checkall="ids[]" aria-label="Tick every application on this page"></th><th>Application</th><th>Form</th><th>Documents</th><th>Stage</th><th class="num">Gem score</th><th>Assigned</th><th>Note from the incubation team</th><th></th></tr></thead><tbody>';
    $pg = max(1, (int) ($_GET['pg'] ?? 1));
    $rows = array_slice($board['open'], ($pg - 1) * 50, 50);
    $docs = inc_dept_docs($rows);
    foreach ($rows as $r) {
        echo '<tr><td><input type="checkbox" class="inc-sel" form="deskMail" name="ids[]" value="' . (int) $r['id'] . '" data-name="' . e((string) ($r['startup_name'] ?: $r['applicant_name']))
           . '" data-email="' . e((string) $r['applicant_email']) . '" aria-label="Tick ' . e($r['ref']) . '"></td>'
           . '<td data-l="Application"><a href="' . e($openLink($r)) . '"><strong>' . e($r['startup_name'] ?: $r['applicant_name']) . '</strong></a><div class="muted small">' . e($r['ref']) . '</div></td>'
           . '<td data-l="Form">' . e($r['form_name']) . '</td>'
           . '<td data-l="Documents">' . (isset($docs[(int) $r['application_id']]) ? $docsLink($docs[(int) $r['application_id']]) : '<span class="muted">—</span>') . '</td>'
           . '<td data-l="Stage">' . inc_form_open('class="inc-row"') . '<input type="hidden" name="action" value="move"><input type="hidden" name="assign_id" value="' . (int) $r['id'] . '">'
           . inc_select('stage_id', $stageMap, $stageOf($r)) . ' <button class="btn btn-sm">Move</button></form></td>'
           . '<td data-l="Gem score" class="num">' . ($gemBadges((int) $r['id']) ?: '<span class="muted">—</span>') . '</td>'
           . '<td data-l="Assigned" class="nowrap">' . e(inc_fmt_date($r['assigned_at'])) . '<div class="muted small">' . e($r['assigned_by']) . '</div></td>'
           . '<td data-l="Note">' . ($r['note'] ? e(mb_strimwidth((string) $r['note'], 0, 160, '…')) : '<span class="muted">—</span>') . '</td>'
           . '<td><a class="btn btn-sm" href="' . e($openLink($r)) . '">Open</a></td></tr>';
    }
    echo '</tbody></table></div>' . inc_pager(count($board['open']), 50, $pg, ['dept' => $deptId, 'view' => 'list'] + $fq, 'desk') . '</section>';
}

/* The selection bar and the email dialog, for the board and the list alike (assets/inc.js, [data-deskbar]). */
if ($board['open']) {
    echo '<div class="dk-bar" data-deskbar hidden role="region" aria-label="Selected applications"><span class="dk-bar-n"><b data-dkcount>0</b> selected</span>'
       . '<button type="button" class="btn btn-sm dk-bar-clear" data-dkclear>Clear</button>'
       . ($gemsAll ? '<span class="dk-bar-gem">' . inc_select('gem_id', array_map(static fn($g) => $g['name'], $gemsAll), count($gemsAll) === 1 ? (int) array_key_first($gemsAll) : '', 'Choose Gem', 'form="deskMail" aria-label="Gem"')
                     . ' <button class="btn btn-sm" form="deskMail" name="gem_go" value="1" formnovalidate>◆ Send Gem form</button></span>' : '')
       . '<button type="button" class="btn btn-sm btn-primary" data-dkopen><span aria-hidden="true">✉</span> Email selected</button></div>';
    echo '<dialog class="dk-dialog" id="deskMailDlg" aria-labelledby="dkTitle"><div class="dk-dialog-h"><div><h2 id="dkTitle">Email <span data-dkcount>0</span> applicant<span data-dkpl>s</span></h2>'
       . '<span class="muted small">From ' . e($depts[$deptId]) . ' · one email each, personalised</span></div>'
       . '<button type="button" class="inc-pop-x" data-dkclose aria-label="Close">✕</button></div>'
       . '<div class="dk-to"><span>To</span><div class="dk-chips" data-dkto></div></div>'
       . $mailBox(0, '') . '</dialog>';
}

if (!$board['open'] && $f) {
    echo '<p class="muted">Nothing open matches these filters. <a href="' . e(inc_url('desk', $keep)) . '">Clear all</a></p>';
} elseif (!$board['open']) {
    echo '<p class="muted">No applications are open with ' . e($depts[$deptId]) . ' right now. When the ' . ($kind === 'department' ? 'incubation team' : 'owner') . ' assigns one, it appears here and you are told by email.</p>';
}

inc_layout_end();

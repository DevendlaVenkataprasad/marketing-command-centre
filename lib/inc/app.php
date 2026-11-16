<?php
/**
 * Incubation Management — front controller, page layout and small renderers.
 *
 * Served by admin/incubation.php (the administrator) and incubation.php (our
 * staff granted the module on Team Management). One page per ?p=; each page file in
 * lib/inc/pages/ handles its own POST actions (post/redirect/get) and calls
 * the service functions, which check permissions themselves.
 */

require_once __DIR__ . '/reports.php';
require_once __DIR__ . '/render.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/depts.php';
require_once __DIR__ . '/gems.php';

/** page => [title, file, permission] */
function inc_pages(): array
{
    if (inc_kind() === 'eo') {
        require_once __DIR__ . '/../eo/app.php';
        return eo_pages(inc_pages_shared());
    }
    return inc_pages_shared();
}

function inc_pages_shared(): array
{
    return [
        'dashboard'    => ['Incubation Management', 'dashboard', 'view'],
        'applications' => ['Applications', 'applications', 'view'],
        'gems'         => ['Gems', 'gems', 'view'],
        'application'  => ['Application', 'application', 'view'],
        'quick'        => ['Application', 'quick', 'view'],
        // 'desk': a department member may file one for their own department (new.php checks which).
        'new'          => ['New application', 'new', 'desk'],
        'evaluate'     => ['Evaluation', 'evaluate', 'evaluate'],
        'evaluation'   => ['Evaluation results', 'evaluation', 'view'],
        'workspaces'   => ['Incubations', 'workspaces', 'view'],
        'workspace'    => ['Incubation record', 'workspace', 'view'],
        'team'         => ['Team dashboard', 'team', 'view'],
        'reports'      => ['Reports & exports', 'reports', 'export'],
        'settings'     => ['Incubation settings', 'settings', 'configure'],
        'form'         => ['Form builder', 'form', 'configure'],
        'template'     => ['Evaluation template', 'template', 'configure'],
        'desk'         => ['Department desk', 'desk', 'desk'],
        // 'desk', not 'view': inc_file_for_download() lets a department member have only their application's papers.
        'file'         => ['Download', 'file', 'desk'],
    ];
}

function inc_app_main(array $viewer): void
{
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store, private');
    inc_start($viewer);
    if (inc_kind() === 'eo') {
        require_once __DIR__ . '/../eo/app.php';
        eo_begin();
        eo_scope((string) ($_GET['p'] ?? 'dashboard'));
    }
    inc_ninth_pillar_upgrade();
    inc_notify_sync();
    // ?embed=1: the page as it shows inside a pop-up, without the portal's menu and header.
    $GLOBALS['inc_embed'] = !empty($_GET['embed']);
    $page = (string) ($_GET['p'] ?? 'dashboard');
    $pages = inc_pages();
    if ($page === 'screening') {
        inc_redirect(inc_url('applications', array_diff_key($_GET, ['p' => 1])));
    }
    if (!isset($pages[$page])) {
        $page = 'dashboard';
    }
    // A department member without the CRM itself lands on their desk rather than a refusal.
    if (!inc_can($pages[$page][2]) && inc_can('desk') && !inc_can('view')) {
        $page = 'desk';
    }
    inc_need($pages[$page][2]);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        fabo_csrf_check();
    }
    $GLOBALS['inc_page'] = $page;
    inc_gem_score_later();
    // A file named eo:… is one of Event Organizers' own pages.
    $file = $pages[$page][1];
    require str_starts_with($file, 'eo:') ? __DIR__ . '/../eo/pages/' . substr($file, 3) . '.php' : __DIR__ . '/pages/' . $file . '.php';
}

/**
 * Scores Gem answers that are still waiting (a first try that Gemini refused),
 * after this page has been sent. cron.php does the same, but the live server
 * may have no cron, and an answer must not sit unscored until somebody presses
 * "Score now". At most two per page, each at most every five minutes.
 */
function inc_gem_score_later(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'GET' || !function_exists('litespeed_finish_request') && !function_exists('fastcgi_finish_request')) {
        return;
    }
    try {
        require_once __DIR__ . '/gems.php';
        $due = inc_one("SELECT id FROM inc_gem_requests WHERE submitted_at IS NOT NULL AND score IS NULL AND score_attempts < 5
                        AND (score_tried_at IS NULL OR score_tried_at < NOW() - INTERVAL 5 MINUTE) LIMIT 1");
    } catch (Throwable $e) {
        return;
    }
    if (!$due) {
        return;
    }
    $org = inc_org();
    register_shutdown_function(static function () use ($org): void {
        // Send the page and free the session first: the person is not kept waiting on Gemini.
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        function_exists('litespeed_finish_request') ? litespeed_finish_request() : fastcgi_finish_request();
        ignore_user_abort(true);
        set_time_limit(300);
        try {
            inc_use_org($org);
            inc_gem_score_pending(2);
        } catch (Throwable $e) {
            error_log('FABO gem score later: ' . $e->getMessage());
        }
    });
}

/* ------------------------------------------------------------- flashing */

function inc_redirect(string $url, ?array $flash = null): void
{
    if ($flash) {
        fabo_session();
        $_SESSION['inc_flash'] = $flash;
    }
    header('Location: ' . $url);
    exit;
}

function inc_take_flash(): ?array
{
    fabo_session();
    $f = $_SESSION['inc_flash'] ?? null;
    unset($_SESSION['inc_flash']);
    return is_array($f) ? $f : null;
}

/** Runs a POST action; success redirects with its message, a refusal stays on the page. */
function inc_action(callable $fn, string $okUrl): ?array
{
    try {
        $msg = $fn();
        inc_redirect($okUrl, ['ok', is_string($msg) ? $msg : 'Saved.']);
    } catch (InvalidArgumentException $e) {
        return ['err', $e->getMessage()];
    } catch (Throwable $e) {
        error_log('FABO incubation action: ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        return ['err', fabo_user_error($e)];
    }
    return null;
}

/* --------------------------------------------------------------- layout */

function inc_layout_start(string $title, string $sub = '', array $crumbs = [], string $actions = '', ?array $flash = null): void
{
    $ctx = inc_ctx();
    $page = (string) ($GLOBALS['inc_page'] ?? 'dashboard');
    if (!empty($GLOBALS['inc_embed'])) {
        $pre = fabo_root_prefix();
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
           . '<title>' . e($title) . '</title><link rel="stylesheet" href="' . e(fabo_asset($pre . 'assets/app.css')) . '">'
           . '<link rel="stylesheet" href="' . e(fabo_asset($pre . 'assets/inc.css')) . '"></head><body class="inc-embed">';
    } else {
        $home = inc_words('Incubation Management');
        fabo_head($ctx['viewer'], $title === $home ? $title : $title . ' · ' . $home, inc_kind() . '-' . $page);
    }
    echo '<main class="wrap-page inc" id="main">';
    $trail = '<a href="' . e(inc_url('dashboard')) . '">' . e(inc_words('Incubation Management')) . '</a>';
    foreach ($crumbs as $label => $href) {
        $trail .= ' &rsaquo; ' . ($href ? '<a href="' . e($href) . '">' . e($label) . '</a>' : e($label));
    }
    // Only on a page below another one (an application, a workspace): there it is the way back.
    if (array_filter($crumbs)) {
        echo '<nav class="inc-crumbs" aria-label="Breadcrumb">' . $trail . '</nav>';
    }
    echo '<div class="inc-head"><div class="pagehead"><h1>' . e($title) . '</h1>' . ($sub !== '' ? '<div class="sub">' . $sub . '</div>' : '')
       . '</div><div class="acts">' . $actions . '</div></div>';
    echo inc_flash_html(inc_take_flash());
    echo inc_flash_html($flash);
}

function inc_layout_end(): void
{
    echo '</main><script src="' . e(fabo_asset(fabo_root_prefix() . 'assets/inc.js')) . '" defer></script>';
    fabo_foot();
}

/* ---------------------------------------------------------- renderers */

function inc_flash_html(?array $f): string
{
    if (!$f) {
        return '';
    }
    return '<div class="flash ' . ($f[0] === 'ok' ? 'ok' : 'err') . '" role="' . ($f[0] === 'ok' ? 'status' : 'alert') . '">' . e($f[1]) . '</div>';
}

function inc_options(array $map, $selected, string $blank = ''): string
{
    $h = $blank !== '' ? '<option value="">' . e($blank) . '</option>' : '';
    $sel = is_array($selected) ? array_map('strval', $selected) : [(string) $selected];
    foreach ($map as $k => $v) {
        // A nested list is a group: label => [value => text] (inc_team_picker()).
        if (is_array($v)) {
            $h .= '<optgroup label="' . e((string) $k) . '">' . inc_options($v, $selected) . '</optgroup>';
            continue;
        }
        $h .= '<option value="' . e((string) $k) . '"' . (in_array((string) $k, $sel, true) ? ' selected' : '') . '>' . e((string) $v) . '</option>';
    }
    return $h;
}

function inc_select(string $name, array $map, $value, string $blank = '', string $attr = ''): string
{
    return '<select name="' . e($name) . '"' . ($attr !== '' ? ' ' . $attr : '') . '>' . inc_options($map, $value, $blank) . '</select>';
}

function inc_field(string $label, string $input, string $hint = '', bool $req = false, string $cls = ''): string
{
    return '<div class="field' . ($cls ? ' ' . e($cls) : '') . '"><label>' . e($label) . ($req ? ' <span class="req" aria-hidden="true">*</span>' : '') . '</label>'
         . $input . ($hint !== '' ? '<div class="inc-hint">' . $hint . '</div>' : '') . '</div>';
}

function inc_form_open(string $attrs = '', bool $files = false): string
{
    return '<form method="post"' . ($files ? ' enctype="multipart/form-data"' : '') . ($attrs !== '' ? ' ' . $attrs : '') . '>' . fabo_csrf_field();
}

/** A one-button POST form; $confirm makes the browser ask first. */
function inc_button(string $label, array $fields, string $cls = 'btn btn-sm', string $confirm = ''): string
{
    $h = '<form method="post" class="inc-inline"' . ($confirm !== '' ? ' data-confirm="' . e($confirm) . '"' : '') . '>' . fabo_csrf_field();
    foreach ($fields as $k => $v) {
        $h .= '<input type="hidden" name="' . e($k) . '" value="' . e((string) $v) . '">';
    }
    return $h . '<button class="' . e($cls) . '">' . e($label) . '</button></form>';
}

function inc_badge(string $text, string $tone = ''): string
{
    return '<span class="inc-badge' . ($tone ? ' ' . e($tone) : '') . '">' . e($text) . '</span>';
}

function inc_eval_badge(string $status): string
{
    $tone = ['approved' => 'green', 'incomplete' => 'amber', 'returned' => 'red', 'submitted' => 'blue', 'under_review' => 'blue',
             'draft' => 'amber', 'in_progress' => 'amber'][$status] ?? '';
    return inc_badge(inc_eval_status_labels()[$status] ?? $status, $tone);
}

/**
 * The score as it may be shown: the official figure only when there is an
 * approved, eligible one; the status otherwise.
 */
function inc_score_html(array $app): string
{
    if ($app['official_score'] !== null) {
        return '<span class="inc-score" title="Official score, approved">' . e(inc_fmt_score($app['official_score'])) . '<small>/100</small></span>';
    }
    return '<span class="inc-noscore">' . e(inc_eval_status_labels()[$app['eval_status']] ?? 'Not Evaluated') . '</span>';
}

function inc_uniq_html($r): string
{
    return $r === null ? '<span class="muted">—</span>' : '<span class="inc-uniq" title="Uniqueness & Differentiation, from the approved evaluation">' . e(inc_fmt_rating($r)) . '<small>/5</small></span>';
}

function inc_tile(string $label, string $value, string $href = '', string $tone = '', string $foot = ''): string
{
    $tag = $href !== '' ? 'a href="' . e($href) . '"' : 'div';
    return '<' . $tag . ' class="tile inc-tile' . ($tone ? ' ' . e($tone) : '') . '"><div class="k">' . e($label) . '</div><div class="v">' . e($value) . '</div>'
         . ($foot !== '' ? '<div class="f">' . e($foot) . '</div>' : '') . '</' . ($href !== '' ? 'a' : 'div') . '>';
}

/**
 * The tabs across the top of Applications. Incubations is part of working
 * applications, so it opens here rather than from the sidebar.
 */
function inc_apps_tabs(string $current): string
{
    $tabs = inc_kind() === 'eo' ? ['applications' => 'Event applications'] : ['applications' => 'All applications', 'workspaces' => 'Incubations'];
    $h = '<nav class="inc-apptabs" aria-label="Applications">';
    foreach ($tabs as $p => $label) {
        $h .= '<a href="' . e(inc_url($p)) . '"' . ($p === $current ? ' class="on" aria-current="page"' : '') . '>' . e($label) . '</a>';
    }
    return $h . '</nav>';
}

/**
 * The applicant's answers to the form they applied with, as the applicant
 * saw the labels. Shared by the application page and its pop-up.
 */
function inc_app_answers_html(array $app): string
{
    $fields = [];
    foreach (inc_form_fields((int) $app['form_version_id']) as $f) {
        $fields[$f['uid']] = $f;
    }
    $values = inc_app_values((int) $app['id']);
    if (!$values) {
        return '<p class="muted small">No form answers on record' . ($app['channel'] === 'admin' ? ' — this application was added by staff.' : '.') . '</p>';
    }
    $h = '<dl class="inc-answers">';
    foreach ($fields as $uid => $f) {
        if ($f['type'] === 'section') {
            $h .= '<h3>' . e($f['label']) . '</h3>';
            continue;
        }
        if (!isset($values[$uid])) {
            continue;
        }
        $v = $values[$uid];
        $h .= '<div><dt>' . e($v['label']) . '</dt><dd>';
        if ($v['type'] === 'file' && $v['file_id']) {
            $h .= '<a href="' . e(inc_url('file', ['id' => $v['file_id']])) . '" target="_blank" rel="noopener">' . e((string) $v['value']) . '</a>';
        } elseif ($v['type'] === 'url') {
            $h .= '<a href="' . e((string) $v['value']) . '" rel="noopener noreferrer" target="_blank">' . e((string) $v['value']) . '</a>';
        } else {
            $h .= nl2br(e(inc_value_text($f, $v['value'])));
        }
        $h .= '</dd></div>';
    }
    return $h . '</dl>';
}

/** A headline number on the dashboard. */
function inc_kpi(string $label, int $value, string $href, string $foot = '', string $tone = ''): string
{
    return '<a class="inc-kpi' . ($tone ? ' ' . e($tone) : '') . '" href="' . e($href) . '"><span class="k">' . e($label) . '</span><strong>' . number_format($value) . '</strong>'
         . ($foot !== '' ? '<span class="f">' . e($foot) . '</span>' : '') . '</a>';
}

/** Horizontal bars for a small count breakdown. Drawn only from real counts. */
function inc_bars(array $data, string $empty = 'Nothing yet.', ?callable $link = null): string
{
    $data = array_filter($data, static fn($n) => $n > 0);
    if (!$data) {
        return '<p class="muted small">' . e($empty) . '</p>';
    }
    $max = max($data);
    $h = '<div class="inc-bars">';
    foreach ($data as $label => $n) {
        $w = (int) round($n * 100 / $max);
        $lab = $link ? '<a href="' . e($link((string) $label)) . '">' . e((string) $label) . '</a>' : e((string) $label);
        $h .= '<div class="row"><div class="l">' . $lab . '</div><div class="b"><i style="width:' . $w . '%"></i></div><div class="n">' . (int) $n . '</div></div>';
    }
    return $h . '</div>';
}

function inc_empty(string $title, string $body = ''): string
{
    return '<div class="inc-empty"><strong>' . e($title) . '</strong>' . ($body !== '' ? '<span>' . $body . '</span>' : '') . '</div>';
}

function inc_pager(int $total, int $per, int $page, array $q, string $p): string
{
    $pages = max(1, (int) ceil($total / $per));
    if ($pages <= 1) {
        return '';
    }
    $h = '<nav class="inc-pager" aria-label="Pages"><span class="muted small">' . number_format($total) . ' records · page ' . $page . ' of ' . $pages . '</span>';
    $mk = static fn($n, $lab) => '<a class="btn btn-sm" href="' . e(inc_url($p, ['pg' => $n] + $q)) . '">' . $lab . '</a>';
    if ($page > 1) {
        $h .= $mk(1, '« First') . $mk($page - 1, '‹ Previous');
    }
    if ($page < $pages) {
        $h .= $mk($page + 1, 'Next ›') . $mk($pages, 'Last »');
    }
    return $h . '</nav>';
}

function inc_timeline_html(array $items): string
{
    if (!$items) {
        return inc_empty('No activity yet.');
    }
    $h = '<ol class="inc-timeline">';
    foreach ($items as $a) {
        $h .= '<li><span class="k k-' . e($a['kind']) . '">' . e(str_replace('_', ' ', $a['kind'])) . '</span><div>' . e($a['summary'])
            . '<div class="muted small">' . e($a['actor']) . ' · ' . e(inc_fmt_datetime($a['created_at'])) . '</div></div></li>';
    }
    return $h . '</ol>';
}

function inc_textarea(string $name, string $value = '', int $rows = 3, string $attr = ''): string
{
    return '<textarea name="' . e($name) . '" rows="' . $rows . '"' . ($attr ? ' ' . $attr : '') . '>' . e($value) . '</textarea>';
}

/** Hidden inputs that carry the current list filters into a form. */
function inc_hidden_filters(array $f): string
{
    $h = '';
    foreach ($f as $k => $v) {
        if (!str_starts_with((string) $k, '_')) {
            $h .= '<input type="hidden" name="flt[' . e((string) $k) . ']" value="' . e((string) $v) . '">';
        }
    }
    return $h;
}

/**
 * The filter bar shared by Applications and Reports: a search box
 * and a "Filters" button that opens the rest. Filters in use show as chips,
 * each with its own ×, so it is always clear what the page is showing.
 */
function inc_filter_bar(string $page, array $f, string $sort, bool $scoreFields = true, array $keep = []): string
{
    $events = inc_event_names();
    $cf = [];
    foreach (inc_all('SELECT DISTINCT f.uid, f.label FROM inc_form_fields f JOIN inc_form_versions v ON v.id = f.version_id AND v.status = "published"
                       WHERE f.type NOT IN ("section","file","textarea") AND f.maps_to IS NULL ORDER BY f.label LIMIT 200') as $r) {
        $cf[$r['uid']] = $r['label'];
    }
    // label => [param, options] — in the order they appear in the panel.
    $defs = [
        'Pipeline stage'    => ['stage_id', inc_stage_map('crm')],
        'Purpose'           => ['purpose', inc_purposes()],
        'Program name'      => ['event', $events + (isset($f['event']) ? [$f['event'] => $f['event']] : [])],
        'Form'              => ['form_id', array_column(inc_forms_list(true), 'name', 'id')],
        'Department'        => ['route_team', inc_teams(false)],
        'Development stage' => ['dev_stage', inc_dev_stages()],
        'Evaluation'        => ['eval_status', array_diff_key(inc_eval_status_labels(), ['draft' => 1, 'in_progress' => 1])],
        'Type of participant' => ['applicant_type_id', inc_masters('applicant_type', false)],
        'Source'            => ['source_id', inc_masters('source', false)],
        'Spoke'             => ['spoke_id', inc_masters('spoke', false)],
        'Sector'            => ['sector', inc_sector_options() + (isset($f['sector']) ? [$f['sector'] => $f['sector']] : [])],
        'Team'              => ['team_id', inc_teams(false)],
        'Owner'             => ['owner_id', inc_people_with_role()],
        'Incubation stage'  => ['ws_stage_id', inc_stage_map('lifecycle')],
        'Incubation'        => ['ws', ['yes' => 'Assigned for incubation', 'no' => 'Not yet assigned']],
        'Duplicate check'   => ['dup', ['suspected' => 'Suspected', 'cleared' => 'Cleared', 'confirmed' => 'Confirmed duplicate']],
    ];
    if (!$events && !isset($f['event'])) {
        unset($defs['Program name']);
    }
    if (inc_kind() === 'eo') {
        unset($defs['Incubation stage'], $defs['Incubation'], $defs['Development stage']);
    }
    $sorts = ['newest' => 'Newest first', 'oldest' => 'Oldest first', 'score' => 'Highest official score', 'uniq' => 'Highest uniqueness', 'name' => 'Name', 'stage' => 'Pipeline stage', 'gem' => 'Highest Gem score'];

    // Chips: one per filter in use, each a link that drops just that one.
    $base = inc_filter_query($f, $sort) + $keep;
    $chips = [];
    $chip = static function (string $label, array $drop) use ($base, $page): string {
        return '<a class="inc-chip" href="' . e(inc_url($page, array_diff_key($base, array_flip($drop)))) . '" title="Remove this filter">' . e($label) . ' <span aria-hidden="true">×</span></a>';
    };
    if (isset($f['q'])) {
        $chips[] = $chip('“' . $f['q'] . '”', ['q']);
    }
    foreach ($defs as $label => [$k, $opts]) {
        if (isset($f[$k])) {
            $chips[] = $chip($label . ': ' . ($opts[$f[$k]] ?? $f[$k]), [$k]);
        }
    }
    foreach (['from' => 'From', 'to' => 'To'] as $k => $l) {
        if (isset($f[$k])) {
            $chips[] = $chip($l . ' ' . inc_fmt_date($f[$k]), [$k]);
        }
    }
    foreach (['score_min' => 'Score ≥ ', 'score_max' => 'Score ≤ ', 'uniq_min' => 'Uniqueness ≥ ', 'uniq_max' => 'Uniqueness ≤ '] as $k => $l) {
        if (isset($f[$k])) {
            $chips[] = $chip($l . $f[$k], [$k]);
        }
    }
    if (isset($f['cf_uid'])) {
        $chips[] = $chip(($cf[$f['cf_uid']] ?? 'Answer') . (isset($f['cf_val']) ? ' contains “' . $f['cf_val'] . '”' : ''), ['cf_uid', 'cf_val']);
    }
    $n = count($chips) - (isset($f['q']) ? 1 : 0);

    $h = '<form method="get" class="inc-filters" role="search"><input type="hidden" name="p" value="' . e($page) . '">';
    foreach ($keep as $k => $v) {
        $h .= '<input type="hidden" name="' . e($k) . '" value="' . e((string) $v) . '">';
    }
    $h .= '<div class="inc-fbar"><input type="search" name="q" value="' . e($f['q'] ?? '') . '" placeholder="Search by name, email, phone or reference" aria-label="Search">'
        . '<button class="btn btn-primary">Search</button>'
        . '<details class="inc-fmore"><summary class="btn">Filters' . ($n ? ' <span class="inc-fcount">' . $n . '</span>' : '') . '</summary>'
        . '<div class="inc-fpanel"><div class="inc-fgrid">';
    foreach ($defs as $label => [$k, $opts]) {
        $h .= '<label><span>' . e($label) . '</span>' . inc_select($k, $opts, $f[$k] ?? '', 'Any') . '</label>';
    }
    $h .= '<label><span>Submitted from</span><input type="date" name="from" value="' . e($f['from'] ?? '') . '"></label>'
        . '<label><span>Submitted to</span><input type="date" name="to" value="' . e($f['to'] ?? '') . '"></label>';
    if ($scoreFields) {
        $h .= '<label><span>Official score</span><span class="inc-range"><input type="number" name="score_min" min="0" max="100" step="0.1" placeholder="min" value="' . e((string) ($f['score_min'] ?? '')) . '">'
            . '<input type="number" name="score_max" min="0" max="100" step="0.1" placeholder="max" value="' . e((string) ($f['score_max'] ?? '')) . '"></span></label>'
            . '<label><span>Uniqueness (0–5)</span><span class="inc-range"><input type="number" name="uniq_min" min="0" max="5" step="0.1" placeholder="min" value="' . e((string) ($f['uniq_min'] ?? '')) . '">'
            . '<input type="number" name="uniq_max" min="0" max="5" step="0.1" placeholder="max" value="' . e((string) ($f['uniq_max'] ?? '')) . '"></span></label>';
    }
    $h .= '<label><span>Answer to a question</span>' . inc_select('cf_uid', $cf, $f['cf_uid'] ?? '', 'Any question') . '</label>'
        . '<label><span>…contains</span><input type="text" name="cf_val" value="' . e($f['cf_val'] ?? '') . '"></label>'
        . '<label><span>Sort by</span>' . inc_select('sort', $sorts, $sort) . '</label>'
        . '</div><div class="inc-factions"><a class="btn" href="' . e(inc_url($page, $keep)) . '">Clear all</a><button class="btn btn-primary">Apply filters</button></div></div></details>'
        . '</div>';
    if ($chips) {
        $h .= '<div class="inc-chips">' . implode('', $chips) . ' <a class="inc-clear" href="' . e(inc_url($page, $keep)) . '">Clear all</a></div>';
    }
    return $h . '</form>';
}

/** The current filter values as query parameters (for links that keep them). */
function inc_filter_query(array $f, string $sort): array
{
    $q = [];
    foreach ($f as $k => $v) {
        if (!str_starts_with((string) $k, '_')) {
            $q[$k] = $v;
        }
    }
    if ($sort !== 'newest') {
        $q['sort'] = $sort;
    }
    return $q;
}

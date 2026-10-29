<?php
/**
 * Finance Portal — the front controller and the page furniture.
 *
 * fin.php (anyone signed in to an organisation with Finance) and
 * admin/fin.php (the administrator) both call fp_main(). Every page is a
 * file in lib/fin/pages/, named by ?p=, with the permission it needs to be
 * opened listed below — and every action inside a page asks again.
 *
 * The furniture — flash messages, buttons, fields, badges — is the
 * incubation module's (lib/inc/app.php), as for IT Management, so the
 * portal's desks look and behave alike.
 */

require_once __DIR__ . '/../ui.php';
require_once __DIR__ . '/../inc/app.php';
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/ledger.php';
require_once __DIR__ . '/workflow.php';
require_once __DIR__ . '/tax.php';
require_once __DIR__ . '/ap.php';
require_once __DIR__ . '/treasury.php';
require_once __DIR__ . '/ar.php';
require_once __DIR__ . '/budget.php';
require_once __DIR__ . '/claims.php';
require_once __DIR__ . '/assets.php';
require_once __DIR__ . '/reports.php';
require_once __DIR__ . '/nav.php';

/**
 * page => [title, permission(s) needed to open it — any one of them; null: decided by the page].
 * The order and grouping here are the sidebar's.
 */
function fp_pages(): array
{
    return [
        'dashboard' => ['Finance dashboard', ['dashboard.view']],
        'me'        => ['My finance', null],
        'approvals' => ['Approvals', null],
        'journals'  => ['Journal entries', ['gl.view']],
        'journal'   => ['Journal entry', ['gl.view']],
        'ledger'    => ['General ledger', ['gl.view']],
        'accounts'  => ['Chart of accounts', ['gl.view']],
        'close'     => ['Period close', ['gl.view']],
        'bills'     => ['Vendor bills', ['ap.view']],
        'bill'      => ['Vendor bill', null],
        'vendors'   => ['Vendors', ['ap.view']],
        'vendor'    => ['Vendor', null],
        'prs'       => ['Purchase requisitions', null],
        'pr'        => ['Purchase requisition', null],
        'pos'       => ['Purchase orders', ['proc.view']],
        'po'        => ['Purchase order', null],
        'invoices'  => ['Customer invoices', ['ar.view']],
        'invoice'   => ['Customer invoice', null],
        'customers' => ['Customers', ['ar.view']],
        'customer'  => ['Customer', ['ar.view']],
        'receipts'  => ['Receipts', ['ar.view']],
        'receipt'   => ['Receipt', ['ar.view']],
        'payments'  => ['Payments', ['pay.view']],
        'payment'   => ['Payment', null],
        'batch'     => ['Payment batch', null],
        'banks'     => ['Bank & cash', ['pay.view', 'bank.reconcile']],
        'forecast'  => ['Cash forecast', ['pay.view']],
        'budgets'   => ['Budgets', ['budget.view']],
        'budget'    => ['Budget', null],
        'transfer'  => ['Budget transfer', ['budget.view']],
        'claims'    => ['Expense claims', ['claims.view']],
        'claim'     => ['Expense claim', null],
        'advance'   => ['Travel advance', null],
        'assets'    => ['Fixed assets', ['assets.view']],
        'asset'     => ['Fixed asset', ['assets.view']],
        'tax'       => ['Tax & compliance', ['tax.view']],
        'controls'  => ['Audit & controls', ['controls.manage', 'audit.view']],
        'reports'   => ['Reports', ['reports.view']],
        'settings'  => ['Finance settings', null],
        'file'      => ['File', null],
        'search'    => ['Search', null],
    ];
}

/**
 * Opens the portal for a viewer: decides whose books, what the grant
 * allows, makes the tables on first use, runs any background work that is
 * due, and hands over to the page.
 */
function fp_main(array $viewer): void
{
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store, private');
    $org = fp_org_of($viewer);
    if (!empty($viewer['is_admin'])) {
        // The administrator works in ours, or — to support a client — in theirs.
        fabo_session();
        if (isset($_GET['org'])) {
            $want = max(0, (int) $_GET['org']);
            $_SESSION['fp_admin_org'] = $want === 0 || fp_org_grant($want) !== 'none' ? $want : 0;
        }
        $org = (int) ($_SESSION['fp_admin_org'] ?? 0);
    }
    if ($org < 0) {
        fabo_deny('Finance is not part of your account.', 403);
    }
    $grant = !empty($viewer['is_admin']) ? 'edit' : fp_org_grant($org);
    if ($grant === 'none') {
        fabo_deny($org === 0 ? 'Finance is not available.' : 'Your organisation does not have the Finance Portal. Ask the administrator to grant Finance on Clients.', 403);
    }
    fp_use($org);
    $GLOBALS['fp_viewer'] = $viewer;
    $GLOBALS['fp_grant'] = $grant;
    fp_install();
    if (!fp_has_access()) {
        fabo_deny('Finance has not been assigned to you. Ask your organisation\'s head to grant it on My Team.', 403);
    }
    fp_cron_lazy();
    $page = (string) ($_GET['p'] ?? '');
    $pages = fp_pages();
    if (!isset($pages[$page])) {
        $page = fp_can('dashboard.view') ? 'dashboard' : 'me';
    }
    [, $perms] = $pages[$page];
    if ($perms !== null && !fp_can_any($perms)) {
        if (in_array($page, ['dashboard'], true)) {
            $page = 'me';
        } else {
            error_log('FABO finance page denied ' . fp_actor() . ' -> ' . $page);
            fabo_deny('You do not have access to ' . strtolower($pages[$page][0]) . '. Ask your finance administrator for the role you need.', 403);
        }
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        fabo_csrf_check();
        if (!fp_writable() && !in_array($page, ['reports'], true)) {
            fabo_deny('View only: your organisation\'s Finance is read-only.', 403);
        }
    }
    $GLOBALS['fp_page'] = $page;
    require __DIR__ . '/pages/' . $page . '.php';
}

/* ============================================================== links */

function fp_url(string $page, array $q = []): string
{
    $q = array_filter($q, static fn($v) => $v !== null && $v !== '' && $v !== false);
    return 'fin.php?' . http_build_query(['p' => $page] + $q);
}

/** Runs a POST action: success redirects with its message; a refusal stays on the page with the form as typed. */
function fp_action(callable $fn, string $okUrl): ?array
{
    return inc_action($fn, $okUrl);
}

/* ============================================================== layout */

function fp_layout_start(string $title, string $sub = '', string $actions = '', array $crumbs = [], ?array $flash = null): void
{
    $page = (string) ($GLOBALS['fp_page'] ?? 'dashboard');
    fabo_head(fp_viewer(), $title . ' · Finance', 'fin-' . $page);
    $pre = fabo_root_prefix();
    echo '<link rel="stylesheet" href="' . e(fabo_asset($pre . 'assets/inc.css')) . '"><link rel="stylesheet" href="' . e(fabo_asset($pre . 'assets/fin.css')) . '">';
    echo '<main class="wrap-page inc fp' . (fp_writable() ? '' : ' fp-ro') . '" id="main">';
    // The context bar: whose books, the year, search, and what is waiting for the viewer.
    $ent = fp_all('SELECT id, code, name FROM fp_entities WHERE active = 1 ORDER BY id');
    $orgName = fp_org() > 0 ? (string) (fabo_one('SELECT COALESCE(NULLIF(organisation, ""), name) n FROM clients WHERE id = ?', 'i', [fp_org()])['n'] ?? '') : (defined('APP_NAME') ? APP_NAME : '');
    $waiting = count(fp_wf_inbox(100));
    echo '<div class="fp-ctx"><div class="fp-ctx-l"><strong>' . e($orgName) . '</strong>'
       . (count($ent) > 1 ? ' <span class="muted small">· ' . count($ent) . ' entities</span>' : '')
       . ' <span class="muted small">· FY ' . e(fp_fy_label(fp_fy_of())) . ' · ' . e(fp_setting('base_currency')) . '</span>';
    if (!empty(fp_viewer()['is_admin'])) {
        $orgs = [0 => 'Our organisation'];
        foreach (fp_orgs_installed() as $o) {
            if (fp_org_grant($o) !== 'none') {
                $orgs[$o] = (string) (fabo_one('SELECT COALESCE(NULLIF(organisation, ""), name) n FROM clients WHERE id = ?', 'i', [$o])['n'] ?? '#' . $o);
            }
        }
        if (count($orgs) > 1) {
            echo ' <form method="get" class="inc-inline fp-orgpick"><input type="hidden" name="p" value="' . e($page) . '"><label class="sr-only" for="fp-org">Organisation</label>'
               . '<select id="fp-org" name="org" data-autosubmit>' . inc_options($orgs, fp_org()) . '</select></form>';
        }
    }
    echo '</div><div class="fp-ctx-r"><form method="get" role="search" class="fp-search"><input type="hidden" name="p" value="search">'
       . '<input type="search" name="q" value="' . e($page === 'search' ? (string) ($_GET['q'] ?? '') : '') . '" placeholder="Search numbers, vendors, customers…" aria-label="Search finance"></form>'
       . '<a class="btn btn-sm' . ($waiting ? ' btn-primary' : '') . '" href="' . e(fp_url('approvals')) . '">Approvals' . ($waiting ? ' <span class="fp-count">' . $waiting . '</span>' : '') . '</a></div></div>';
    if ($crumbs) {
        $trail = '<a href="' . e(fp_url(fp_can('dashboard.view') ? 'dashboard' : 'me')) . '">Finance</a>';
        foreach ($crumbs as $label => $href) {
            $trail .= ' &rsaquo; ' . ($href ? '<a href="' . e($href) . '">' . e($label) . '</a>' : e($label));
        }
        echo '<nav class="inc-crumbs" aria-label="Breadcrumb">' . $trail . '</nav>';
    }
    echo '<div class="inc-head"><div class="pagehead"><h1>' . e($title) . '</h1>' . ($sub !== '' ? '<div class="sub">' . $sub . '</div>' : '') . '</div><div class="acts">' . $actions . '</div></div>';
    if (!fp_writable()) {
        echo '<div class="inc-note"><strong>View only.</strong> Your organisation holds Finance to read; nothing can be changed here.</div>';
    }
    echo inc_flash_html(inc_take_flash());
    echo inc_flash_html($flash);
}

function fp_layout_end(): void
{
    $pre = fabo_root_prefix();
    echo '</main><script src="' . e(fabo_asset($pre . 'assets/inc.js')) . '" defer></script><script src="' . e(fabo_asset($pre . 'assets/fin.js')) . '" defer></script>';
    fabo_foot();
}

/* ============================================================== bits */

/** A status badge from a statuses map (key => [label, tone]). */
function fp_badge(array $map, ?string $status): string
{
    [$label, $tone] = $map[$status] ?? [ucfirst(str_replace('_', ' ', (string) $status)), ''];
    return inc_badge($label, $tone);
}

/** A headline figure linking to what it is made of. $value is ready text. */
function fp_kpi(string $label, string $value, string $href = '', string $foot = '', string $tone = ''): string
{
    $tag = $href !== '' ? 'a href="' . e($href) . '"' : 'div';
    return '<' . $tag . ' class="inc-kpi fp-kpi' . ($tone ? ' ' . e($tone) : '') . '"><span class="k">' . e($label) . '</span><strong>' . e($value) . '</strong>'
         . ($foot !== '' ? '<span class="f">' . e($foot) . '</span>' : '') . '</' . ($href !== '' ? 'a' : 'div') . '>';
}

function fp_money_td($v, string $label = ''): string
{
    return '<td class="num"' . ($label !== '' ? ' data-l="' . e($label) . '"' : '') . '>' . ($v === null || $v === '' ? '' : e(fp_money($v))) . '</td>';
}

/** Options for masters, for pickers. */
function fp_cc_options(bool $activeOnly = true): array
{
    $out = [];
    foreach (fp_all('SELECT id, code, name FROM fp_cost_centres' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY code') as $r) {
        $out[(int) $r['id']] = $r['code'] . ' · ' . $r['name'];
    }
    return $out;
}

function fp_project_options(): array
{
    $out = [];
    foreach (fp_all('SELECT id, code, name FROM fp_projects WHERE status = "active" ORDER BY code') as $r) {
        $out[(int) $r['id']] = $r['code'] . ' · ' . $r['name'];
    }
    return $out;
}

function fp_entity_options(): array
{
    $out = [];
    foreach (fp_all('SELECT id, code, name FROM fp_entities WHERE active = 1 ORDER BY id') as $r) {
        $out[(int) $r['id']] = $r['code'] . ' · ' . $r['name'];
    }
    return $out;
}

function fp_fy_options(int $back = 3, int $ahead = 1): array
{
    $now = fp_fy_of();
    $out = [];
    for ($y = $now + $ahead; $y >= $now - $back; $y--) {
        $out[$y] = 'FY ' . fp_fy_label($y);
    }
    return $out;
}

/**
 * The filter bar shared by lists and reports. $fields picks which appear:
 * q, fy, from, to, entity, cc, project, status (with $statuses), account.
 */
function fp_filters(string $page, array $fields, array $cur, array $statuses = [], array $keep = []): string
{
    $h = '<form method="get" class="fp-filters" role="search"><input type="hidden" name="p" value="' . e($page) . '">';
    foreach ($keep as $k => $v) {
        $h .= '<input type="hidden" name="' . e($k) . '" value="' . e((string) $v) . '">';
    }
    $lab = static fn(string $l, string $in) => '<label><span>' . e($l) . '</span>' . $in . '</label>';
    foreach ($fields as $f) {
        switch ($f) {
            case 'q':
                $h .= $lab('Search', '<input type="search" name="q" value="' . e($cur['q'] ?? '') . '" placeholder="Number, name, reference">');
                break;
            case 'fy':
                $h .= $lab('Financial year', inc_select('fy', fp_fy_options(), $cur['fy'] ?? fp_fy_of()));
                break;
            case 'from':
                $h .= $lab('From', '<input type="date" name="from" value="' . e($cur['from'] ?? '') . '">');
                break;
            case 'to':
                $h .= $lab($fields === ['to'] || in_array('asof', $fields, true) ? 'As at' : 'To', '<input type="date" name="to" value="' . e($cur['to'] ?? '') . '">');
                break;
            case 'entity':
                if (count(fp_entity_options()) > 1) {
                    $h .= $lab('Entity', inc_select('entity', fp_entity_options(), $cur['entity'] ?? '', 'All (consolidated)'));
                }
                break;
            case 'cc':
                $h .= $lab('Cost centre', inc_select('cc', fp_cc_options(false), $cur['cc'] ?? '', 'All'));
                break;
            case 'project':
                if (fp_project_options()) {
                    $h .= $lab('Project', inc_select('project', fp_project_options(), $cur['project'] ?? '', 'All'));
                }
                break;
            case 'status':
                $h .= $lab('Status', inc_select('status', array_map(static fn($s) => is_array($s) ? $s[0] : $s, $statuses), $cur['status'] ?? '', 'Any'));
                break;
            case 'account':
                $h .= $lab('Account', inc_select('account', fp_account_options(), $cur['account'] ?? '', 'All accounts'));
                break;
            case 'eliminate':
                $h .= '<label class="check"><input type="checkbox" name="eliminate" value="1"' . (!empty($cur['eliminate']) ? ' checked' : '') . '> Eliminate intercompany</label>';
                break;
        }
    }
    return $h . '<button class="btn btn-primary">Apply</button> <a class="btn" href="' . e(fp_url($page, $keep)) . '">Clear</a></form>';
}

function fp_pager(int $total, int $per, int $pg, string $page, array $q): string
{
    $pages = max(1, (int) ceil($total / $per));
    if ($pages <= 1) {
        return $total ? '<p class="muted small">' . number_format($total) . ' record' . ($total === 1 ? '' : 's') . '</p>' : '';
    }
    $h = '<nav class="inc-pager" aria-label="Pages"><span class="muted small">' . number_format($total) . ' records · page ' . $pg . ' of ' . $pages . '</span>';
    $mk = static fn($n, $l) => '<a class="btn btn-sm" href="' . e(fp_url($page, ['pg' => $n] + $q)) . '">' . $l . '</a>';
    if ($pg > 1) {
        $h .= $mk(1, '« First') . $mk($pg - 1, '‹ Previous');
    }
    if ($pg < $pages) {
        $h .= $mk($pg + 1, 'Next ›') . $mk($pages, 'Last »');
    }
    return $h . '</nav>';
}

/** A sortable column heading: clicking flips the order. */
function fp_sort_th(string $label, string $key, string $page, array $q, string $cls = ''): string
{
    $cur = (string) ($q['sort'] ?? '');
    $dir = $cur === $key ? '-' . $key : $key;
    $mark = $cur === $key ? ' ▲' : ($cur === '-' . $key ? ' ▼' : '');
    return '<th' . ($cls ? ' class="' . e($cls) . '"' : '') . '><a href="' . e(fp_url($page, ['sort' => $dir] + array_diff_key($q, ['sort' => 1, 'pg' => 1]))) . '">' . e($label) . $mark . '</a></th>';
}

/** ORDER BY from ?sort, limited to $allowed (key => SQL). */
function fp_sort_sql(array $allowed, string $default): string
{
    $s = (string) ($_GET['sort'] ?? '');
    $desc = str_starts_with($s, '-');
    $k = ltrim($s, '-');
    return isset($allowed[$k]) ? $allowed[$k] . ($desc ? ' DESC' : ' ASC') : $default;
}

/* ========================================================= approval panel */

/**
 * The approval box on every document: who has approved, who it waits for,
 * the full history with reasons, and — for whoever may decide — approve,
 * reject or return, against the version on screen.
 */
function fp_wf_panel(string $type, array $doc, string $back): string
{
    $steps = fp_wf_steps($type, (int) $doc['id']);
    $hist = fp_wf_history($type, (int) $doc['id']);
    if (!$steps && !$hist) {
        return '';
    }
    $h = '<section class="card fp-wf"><h2>Approval</h2>';
    if ($steps) {
        $h .= '<ol class="fp-steps">';
        $ver = (int) $doc['version'];
        foreach ($steps as $s) {
            if ((int) $s['doc_version'] < $ver - 1 && $s['status'] !== 'pending') {
                continue;
            }
            [$people, $fallback] = fp_wf_approvers($s);
            $who = $people ? implode(', ', array_map('fp_person_name', $people)) : 'nobody';
            $tone = ['approved' => 'green', 'rejected' => 'red', 'returned' => 'red', 'pending' => 'amber'][$s['status']] ?? '';
            $h .= '<li class="' . e($s['status']) . '"><div><strong>' . e($s['label'] ?: 'Level ' . $s['level']) . '</strong> ' . inc_badge(ucfirst($s['status'] === 'waiting' ? 'next' : $s['status']), $tone)
                . ((int) $s['required'] > 1 ? ' <span class="muted small">' . (int) $s['approvals'] . ' of ' . (int) $s['required'] . ' approvals</span>' : '') . '</div>'
                . '<div class="muted small">' . e($who) . ($fallback ? ' — nobody holds the named role, so the finance administrator decides' : '')
                . ($s['status'] === 'pending' && $s['activated_at'] ? ' · waiting since ' . e(fp_fmt_dt($s['activated_at'])) : '') . ($s['escalated_at'] ? ' · escalated' : '') . '</div></li>';
        }
        $h .= '</ol>';
    }
    $mine = null;
    foreach ($steps as $s) {
        if ($s['status'] === 'pending' && (int) $s['doc_version'] === (int) $doc['version']) {
            [$ok, , $why] = fp_wf_can_act($s);
            if ($ok) {
                $mine = $s;
                break;
            }
        }
    }
    if ($mine) {
        $h .= '<form method="post" class="fp-decide">' . fabo_csrf_field() . '<input type="hidden" name="action" value="wf"><input type="hidden" name="wf_type" value="' . e($type) . '">'
            . '<input type="hidden" name="version" value="' . (int) $doc['version'] . '"><input type="hidden" name="back" value="' . e($back) . '">'
            . '<label for="wf-c">Comment <span class="muted small">(required to reject or return)</span></label><textarea id="wf-c" name="comment" rows="2" maxlength="1000"></textarea>'
            . '<div class="fp-decide-b"><button class="btn btn-approve btn-primary" name="decision" value="approve">Approve</button>'
            . '<button class="btn" name="decision" value="return" data-confirm="Send this back for correction?">Return for correction</button>'
            . '<button class="btn btn-danger" name="decision" value="reject" data-confirm="Reject this? The submitter will be told your reason.">Reject</button></div></form>';
    }
    if ($hist) {
        $h .= '<details class="fp-hist"' . ($mine ? '' : ' open') . '><summary>History (' . count($hist) . ')</summary><ol class="inc-timeline">';
        foreach ($hist as $e) {
            $h .= '<li><span class="k k-' . e($e['action']) . '">' . e(str_replace('_', ' ', $e['action'])) . '</span><div>' . e($e['actor'])
                . ($e['on_behalf_of'] !== null ? ' <span class="muted small">for ' . e(fp_person_name((int) $e['on_behalf_of'])) . '</span>' : '')
                . ($e['level'] ? ' <span class="muted small">level ' . (int) $e['level'] . '</span>' : '')
                . ($e['comment'] ? '<div>“' . e($e['comment']) . '”</div>' : '')
                . '<div class="muted small">' . e(fp_fmt_dt($e['created_at'])) . ' · version ' . (int) $e['doc_version'] . ($e['amount'] !== null ? ' · ' . e(fp_money($e['amount'])) : '') . '</div></div></li>';
        }
        $h .= '</ol></details>';
    }
    return $h . '</section>';
}

/** Handles the approval form posted from any document page. */
function fp_wf_post(string $type, int $id, string $back): ?array
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'wf') {
        return null;
    }
    return fp_action(static fn() => fp_wf_act($type, $id, (string) ($_POST['decision'] ?? ''), (string) ($_POST['comment'] ?? ''), $_POST['version'] ?? 0), $back);
}

/* ========================================================= files & audit */

function fp_files_panel(string $type, array $doc, bool $canAdd, string $back): string
{
    $files = fp_files($type, (int) $doc['id']);
    $h = '<section class="card"><h2>Attachments</h2>';
    if ($files) {
        $h .= '<ul class="fp-files">';
        foreach ($files as $f) {
            $h .= '<li><a href="' . e(fp_url('file', ['id' => $f['id']])) . '">' . e($f['original_name']) . '</a> <span class="muted small">' . e(fabo_human_size((int) $f['size']))
                . ' · ' . e($f['uploaded_by'] ?? '') . ' · ' . e(fp_fmt_dt($f['created_at'])) . ($f['note'] ? ' · ' . e($f['note']) : '') . '</span></li>';
        }
        $h .= '</ul>';
    } else {
        $h .= '<p class="muted small">No files attached.</p>';
    }
    if ($canAdd && fp_writable()) {
        $h .= inc_form_open('class="fp-attach"', true) . '<input type="hidden" name="action" value="attach"><input type="hidden" name="back" value="' . e($back) . '">'
            . '<input type="file" name="files[]" multiple aria-label="Files to attach"> <button class="btn btn-sm">Attach</button></form>';
    }
    return $h . '</section>';
}

function fp_files_post(string $type, int $id, string $back, bool $allowed): ?array
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'attach') {
        return null;
    }
    if (!$allowed) {
        fabo_deny('You cannot add files to this.', 403);
    }
    return fp_action(static function () use ($type, $id) {
        $n = fp_attach_many($type, $id);
        if (!$n) {
            throw new InvalidArgumentException('Choose a file to attach.');
        }
        return $n . ' file' . ($n === 1 ? '' : 's') . ' attached.';
    }, $back);
}

/** The audit trail of one record, for those who may read it. */
function fp_audit_panel(string $entity, int $id): string
{
    if (!fp_can('audit.view') && !fp_can('gl.view')) {
        return '';
    }
    $rows = fp_all('SELECT * FROM fp_audit WHERE entity = ? AND entity_id = ? ORDER BY id DESC LIMIT 100', 'si', [$entity, $id]);
    if (!$rows) {
        return '';
    }
    $h = '<section class="card"><details><summary><strong>Change history</strong> <span class="muted small">(' . count($rows) . ')</span></summary><ol class="inc-timeline">';
    foreach ($rows as $a) {
        $h .= '<li><span class="k">' . e(str_replace('_', ' ', $a['action'])) . '</span><div>' . e($a['summary']) . '<div class="muted small">' . e($a['actor']) . ' · ' . e(fp_fmt_dt($a['created_at'])) . '</div></div></li>';
    }
    return $h . '</ol></details></section>';
}

/* =========================================================== line editors */

/**
 * The editable lines of a bill, order or invoice. Each row: description,
 * account, cost centre, project, quantity, rate, tax code; the browser adds
 * rows and shows totals as you type (assets/fin.js) — the server works the
 * figures out again on save, and its figures are the ones kept.
 */
function fp_lines_editor(array $lines, string $side, array $opts = []): string
{
    $accounts = $side === 'output' ? fp_account_options(['income']) : fp_account_options(['expense', 'asset']);
    $taxes = fp_tax_options();
    $rates = [];
    foreach (array_keys($taxes) as $tid) {
        $rates[$tid] = (float) fp_tax_code($tid)['rate'];
    }
    $ccs = fp_cc_options();
    $projects = fp_project_options();
    $poLines = $opts['po_lines'] ?? [];
    $rows = $lines ?: [[]];
    $rows[] = [];
    $h = '<div class="scrollx"><table class="data inc-table fp-lines" data-lines="doc"><thead><tr><th>Description</th><th>Account</th><th>Cost centre</th>'
       . ($projects ? '<th>Project</th>' : '') . ($poLines ? '<th>Order line</th>' : '') . ($side === 'output' ? '<th>HSN/SAC</th>' : '')
       . '<th class="num">Qty</th><th class="num">Rate</th><th>GST</th><th class="num">Amount</th><th></th></tr></thead><tbody>';
    foreach ($rows as $i => $l) {
        $n = 'lines[' . $i . ']';
        $taxSel = '<select name="' . $n . '[tax_code_id]" data-f="tax"><option value="" data-rate="0">None</option>';
        foreach ($taxes as $tid => $label) {
            $taxSel .= '<option value="' . $tid . '" data-rate="' . e((string) $rates[$tid]) . '"' . ((int) ($l['tax_code_id'] ?? 0) === $tid ? ' selected' : '') . '>' . e($label) . '</option>';
        }
        $taxSel .= '</select>';
        $h .= '<tr><td data-l="Description"><input name="' . $n . '[description]" value="' . e($l['description'] ?? '') . '" maxlength="255"></td>'
            . '<td data-l="Account">' . inc_select($n . '[account_id]', $accounts, $l['account_id'] ?? ($opts['default_account'] ?? ''), 'Choose…') . '</td>'
            . '<td data-l="Cost centre">' . inc_select($n . '[cost_centre_id]', $ccs, $l['cost_centre_id'] ?? ($opts['default_cc'] ?? ''), '—') . '</td>'
            . ($projects ? '<td data-l="Project">' . inc_select($n . '[project_id]', $projects, $l['project_id'] ?? '', '—') . '</td>' : '')
            . ($poLines ? '<td data-l="Order line">' . inc_select($n . '[po_line_id]', $poLines, $l['po_line_id'] ?? '', 'Not on the order') . '</td>' : '')
            . ($side === 'output' ? '<td data-l="HSN/SAC"><input name="' . $n . '[hsn]" value="' . e($l['hsn'] ?? '') . '" maxlength="12" size="7"></td>' : '')
            . '<td data-l="Qty" class="num"><input name="' . $n . '[qty]" value="' . e(isset($l['qty']) ? rtrim(rtrim((string) $l['qty'], '0'), '.') : '1') . '" inputmode="decimal" size="5" data-f="qty"></td>'
            . '<td data-l="Rate" class="num"><input name="' . $n . '[rate]" value="' . e(isset($l['rate']) ? fp_amt($l['rate']) : '') . '" inputmode="decimal" size="9" data-f="rate"></td>'
            . '<td data-l="GST">' . $taxSel . '</td><td class="num" data-l="Amount" data-out="amount">' . (isset($l['amount']) ? e(fp_amt($l['amount'])) : '') . '</td>'
            . '<td>' . (isset($l['line_id']) ? '<input type="hidden" name="' . $n . '[line_id]" value="' . (int) $l['line_id'] . '" data-keep>' : '')
            . '<button type="button" class="btn btn-sm" data-row-del aria-label="Remove line">×</button></td></tr>';
    }
    $h .= '</tbody></table></div><div class="fp-linefoot"><button type="button" class="btn btn-sm" data-row-add>+ Add line</button>'
        . '<div class="fp-tot" data-tot><span>Subtotal <b data-out="sub">0.00</b></span><span>GST <b data-out="tax">0.00</b></span>'
        . (!empty($opts['tds']) ? '<span>TDS <b data-out="tds">0.00</b></span>' : '') . '<span class="g">Total <b data-out="total">0.00</b></span></div></div>';
    return $h;
}

/** The debit/credit grid of a journal, with a running difference. */
function fp_journal_editor(array $lines): string
{
    $accounts = fp_account_options();
    $ccs = fp_cc_options();
    $projects = fp_project_options();
    $rows = $lines ?: [[], []];
    $rows[] = [];
    $h = '<div class="scrollx"><table class="data inc-table fp-lines" data-lines="journal"><thead><tr><th>Account</th><th>Description</th><th>Cost centre</th>'
       . ($projects ? '<th>Project</th>' : '') . '<th class="num">Debit</th><th class="num">Credit</th><th></th></tr></thead><tbody>';
    foreach ($rows as $i => $l) {
        $n = 'lines[' . $i . ']';
        $h .= '<tr><td data-l="Account">' . inc_select($n . '[account_id]', $accounts, $l['account_id'] ?? '', 'Choose…') . '</td>'
            . '<td data-l="Description"><input name="' . $n . '[description]" value="' . e($l['description'] ?? '') . '" maxlength="255"></td>'
            . '<td data-l="Cost centre">' . inc_select($n . '[cost_centre_id]', $ccs, $l['cost_centre_id'] ?? '', '—') . '</td>'
            . ($projects ? '<td data-l="Project">' . inc_select($n . '[project_id]', $projects, $l['project_id'] ?? '', '—') . '</td>' : '')
            . '<td class="num" data-l="Debit"><input name="' . $n . '[debit]" value="' . e(!empty($l['debit']) && (float) $l['debit'] ? fp_amt($l['debit']) : '') . '" inputmode="decimal" size="10" data-f="dr"></td>'
            . '<td class="num" data-l="Credit"><input name="' . $n . '[credit]" value="' . e(!empty($l['credit']) && (float) $l['credit'] ? fp_amt($l['credit']) : '') . '" inputmode="decimal" size="10" data-f="cr"></td>'
            . '<td><button type="button" class="btn btn-sm" data-row-del aria-label="Remove line">×</button></td></tr>';
    }
    return $h . '</tbody></table></div><div class="fp-linefoot"><button type="button" class="btn btn-sm" data-row-add>+ Add line</button>'
         . '<div class="fp-tot" data-tot><span>Debits <b data-out="dr">0.00</b></span><span>Credits <b data-out="cr">0.00</b></span><span class="g" data-diff>Difference <b data-out="diff">0.00</b></span></div></div>';
}

/** A read-only table of document lines. */
function fp_lines_table(array $lines, bool $withTax = true, array $extra = []): string
{
    $h = '<div class="scrollx"><table class="data inc-table stack"><thead><tr><th>#</th><th>Description</th><th>Account</th><th class="num">Qty</th><th class="num">Rate</th>'
       . ($withTax ? '<th>GST</th><th class="num">Tax</th>' : '') . '<th class="num">Amount</th>' . implode('', array_map(static fn($x) => '<th>' . e($x) . '</th>', array_keys($extra))) . '</tr></thead><tbody>';
    foreach ($lines as $l) {
        $tc = $l['tax_code_id'] ? fp_tax_code((int) $l['tax_code_id']) : null;
        $h .= '<tr><td>' . (int) $l['line_no'] . '</td><td data-l="Description">' . e($l['description']) . '</td><td data-l="Account">' . e($l['account_code'] . ' ' . $l['account_name']) . '</td>'
            . '<td class="num" data-l="Qty">' . e(rtrim(rtrim((string) $l['qty'], '0'), '.')) . '</td>' . fp_money_td($l['rate'], 'Rate')
            . ($withTax ? '<td data-l="GST">' . e($tc['code'] ?? '—') . '</td>' . fp_money_td($l['tax_amount'], 'Tax') : '') . fp_money_td($l['amount'], 'Amount');
        foreach ($extra as $label => $fn) {
            $h .= '<td data-l="' . e($label) . '">' . $fn($l) . '</td>';
        }
        $h .= '</tr>';
    }
    return $h . '</tbody></table></div>';
}

/** A definition list of facts about a document. $facts: label => html. */
function fp_facts(array $facts): string
{
    $h = '<dl class="fp-facts">';
    foreach ($facts as $k => $v) {
        if ($v === null || $v === '') {
            continue;
        }
        $h .= '<div><dt>' . e($k) . '</dt><dd>' . $v . '</dd></div>';
    }
    return $h . '</dl>';
}

/** Renders a report (the same shape the exports use). */
function fp_report_html(array $rep): string
{
    $h = '<div class="scrollx"><table class="data inc-table fp-report"><thead><tr>';
    foreach ($rep['cols'] as [$label, $type]) {
        $h .= '<th' . (in_array($type, ['money', 'num', 'pct'], true) ? ' class="num"' : '') . '>' . e($label) . '</th>';
    }
    $h .= '</tr></thead><tbody>';
    if (!$rep['rows']) {
        $h .= '<tr><td colspan="' . count($rep['cols']) . '" class="muted">Nothing in this period.</td></tr>';
    }
    foreach ($rep['rows'] as $r) {
        $h .= '<tr' . (!empty($r['_cls']) ? ' class="fp-' . e($r['_cls']) . '"' : '') . '>';
        $first = true;
        foreach ($rep['cols'] as $k => [$label, $type]) {
            $v = $r[$k] ?? null;
            $txt = $v === null || $v === '' ? '' : match ($type) {
                'money' => fp_money($v), 'pct' => fp_pct((float) $v, 1), 'date' => fp_fmt_date((string) $v), 'num' => is_numeric($v) ? number_format((float) $v, (float) $v == (int) $v ? 0 : 2) : (string) $v,
                default => (string) $v,
            };
            $cell = e($txt);
            if (!empty($r['_href']) && $first && $txt !== '') {
                $cell = '<a href="' . e($r['_href']) . '">' . $cell . '</a>';
            }
            if ($txt !== '') {
                $first = false;
            }
            $h .= '<td' . (in_array($type, ['money', 'num', 'pct'], true) ? ' class="num' . ($type === 'money' && (float) $v < 0 ? ' neg' : '') . '"' : '') . ' data-l="' . e($label) . '">' . $cell . '</td>';
        }
        $h .= '</tr>';
    }
    return $h . '</tbody></table></div>';
}

/** A number formatted for an input box. */
function fp_inp($v): string
{
    return $v === null || $v === '' ? '' : fp_amt($v);
}

/** The status path across the top of a document, the current step marked. */
function fp_steps_bar(array $steps, string $current): string
{
    if (!isset($steps[$current])) {
        return '';
    }
    $keys = array_keys($steps);
    $pos = array_search($current, $keys, true);
    $h = '<ol class="fp-path">';
    foreach ($keys as $i => $k) {
        $h .= '<li class="' . ($i < $pos ? 'done' : ($i === $pos ? 'now' : '')) . '">' . e($steps[$k]) . '</li>';
    }
    return $h . '</ol>';
}

/**
 * "Save and submit": the draft is already saved; if submitting it is
 * refused, go to the saved draft and say why — never back to an empty form
 * that would save it a second time.
 */
function fp_submit_or_draft(callable $submit, string $url): void
{
    try {
        $msg = $submit();
    } catch (InvalidArgumentException $e) {
        inc_redirect($url, ['err', 'Saved as a draft, but not submitted: ' . $e->getMessage()]);
    }
    inc_redirect($url, ['ok', is_string($msg) ? $msg : 'Submitted.']);
}

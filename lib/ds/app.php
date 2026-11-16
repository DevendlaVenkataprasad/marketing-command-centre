<?php
/**
 * Digital Signatures — the front controller and the page furniture.
 *
 * sign.php (anyone signed in to an organisation with the module) and
 * admin/sign.php (the administrator) both call ds_main(). Each page is a
 * file in lib/ds/pages/, named by ?p=, with the permission it needs listed
 * below; every action inside a page asks again.
 */

require_once __DIR__ . '/../ui.php';
require_once __DIR__ . '/../inc/app.php';
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/pdf.php';
require_once __DIR__ . '/verify.php';
require_once __DIR__ . '/docs.php';
require_once __DIR__ . '/workflow.php';
require_once __DIR__ . '/providers.php';
require_once __DIR__ . '/evidence.php';
require_once __DIR__ . '/ops.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/preview.php';
require_once __DIR__ . '/guest.php';
require_once __DIR__ . '/../uploads.php';

/** page => [title, permissions (any one), admin section?]. Null: decided by the page. */
function ds_pages(): array
{
    return [
        'dashboard'   => ['Dashboard', null],
        'inbox'       => ['My signatures', null],
        'documents'   => ['Documents', null],
        'new'         => ['New document', ['docs.create']],
        'doc'         => ['Document', null],
        'sign'        => ['Signing workspace', null],
        'studio'      => ['Signature studio', null],
        'verify'      => ['Verify signatures', ['verify.use']],
        'track'       => ['Track a document', null],
        'reports'     => ['Reports', ['reports.view']],
        'monitor'     => ['Signature monitor', ['monitor.manage']],
        'admin'       => ['Administration', null],
        'workflows'   => ['Workflows', ['workflows.manage']],
        'workflow'    => ['Workflow', ['workflows.manage']],
        'signatories' => ['Signatory directory', ['signatories.manage']],
        'categories'  => ['Categories & retention', ['categories.manage']],
        'providers'   => ['Signing providers', ['providers.manage']],
        'trust'       => ['Trust store', ['providers.manage']],
        'roles'       => ['Roles & permissions', ['roles.manage']],
        'delegations' => ['Delegations', null],
        'settings'    => ['Settings', ['settings.manage']],
        'audit'       => ['Audit trail', ['audit.view']],
        'file'        => ['File', null],
        'done'        => ['Signing result', null],
    ];
}

/** Admin pages and what opens them, in console order. */
function ds_admin_pages(): array
{
    return [
        'workflows'   => ['Workflows', 'Sequential, parallel and conditional signing routes.', 'workflows.manage', 'flow'],
        'signatories' => ['Signatory directory', 'People, signing authority, limits and certificates.', 'signatories.manage', 'users'],
        'categories'  => ['Categories & retention', 'Document categories, default workflows, retention.', 'categories.manage', 'folder'],
        'providers'   => ['Signing providers', 'Aadhaar eSign (ESP) and DSC configuration.', 'providers.manage', 'plug'],
        'trust'       => ['Trust store', 'CCA root and intermediate certificates.', 'providers.manage', 'shield'],
        'roles'       => ['Roles & permissions', 'Who holds which role, and what each role may do.', 'roles.manage', 'key'],
        'delegations' => ['Delegations', 'Temporary signing delegation, where policy allows.', 'delegations.manage', 'swap'],
        'monitor'     => ['Signature monitor', 'Everything pending: remind, escalate, follow up.', 'monitor.manage', 'clock'],
        'audit'       => ['Audit trail', 'Tamper-evident log, with an integrity check.', 'audit.view', 'list'],
        'settings'    => ['Settings', 'Departments, units, notifications, security, API access.', 'settings.manage', 'gear'],
    ];
}

function ds_main(array $viewer): void
{
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store, private');
    ds_install();
    $org = ds_org_of($viewer);
    if (!empty($viewer['is_admin'])) {
        fabo_session();
        if (isset($_GET['org'])) {
            $want = max(0, (int) $_GET['org']);
            $_SESSION['ds_admin_org'] = $want === 0 || ds_org_grant($want) !== 'none' ? $want : 0;
        }
        $org = (int) ($_SESSION['ds_admin_org'] ?? 0);
    }
    if ($org < 0) {
        fabo_deny('Digital Signatures is not part of your account.', 403);
    }
    $grant = !empty($viewer['is_admin']) ? 'edit' : ds_org_grant($org);
    if ($grant === 'none') {
        fabo_deny('Your organisation does not have Digital Signatures. Ask the administrator to grant it on Clients.', 403);
    }
    ds_use($org);
    $GLOBALS['ds_viewer'] = $viewer;
    $GLOBALS['ds_grant'] = $grant;
    ds_cron_lazy();
    $page = (string) ($_GET['p'] ?? '');
    $pages = ds_pages();
    if (!isset($pages[$page])) {
        $page = 'dashboard';
    }
    $perms = $pages[$page][1];
    if ($perms !== null && !ds_can_any($perms)) {
        error_log('FABO esign page denied ' . ds_actor() . ' -> ' . $page);
        fabo_deny('You do not have access to ' . strtolower($pages[$page][0]) . '. Ask your administrator for the role you need.', 403);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        fabo_csrf_check();
        require_once __DIR__ . '/../throttle.php';
        $k = 'p' . ds_me() . '-' . fabo_client_ip();
        if (fabo_throttle_blocked('ds-post', $k, 120, 300)) {
            fabo_deny('Too many requests. Wait a few minutes and try again.', 429);
        }
        fabo_throttle_hit('ds-post', $k);
        if (!ds_writable() && !in_array($page, ['verify', 'reports'], true)) {
            fabo_deny('View only: your organisation holds Digital Signatures read-only.', 403);
        }
    }
    $GLOBALS['ds_page'] = $page;
    require __DIR__ . '/pages/' . $page . '.php';
}

/* ================================================================ links */

function ds_url(string $page, array $q = []): string
{
    $q = array_filter($q, static fn($v) => $v !== null && $v !== '' && $v !== false);
    return 'sign.php?' . http_build_query(['p' => $page] + $q);
}

function ds_action(callable $fn, string $okUrl): ?array
{
    return inc_action($fn, $okUrl);
}

/* =============================================================== layout */

function ds_layout_start(string $title, string $sub = '', string $actions = '', array $crumbs = [], ?array $flash = null, string $eyebrow = 'Digital Signatures'): void
{
    $page = (string) ($GLOBALS['ds_page'] ?? 'dashboard');
    fabo_head(ds_viewer(), $title . ' · Digital Signatures', 'ds-' . $page);
    $pre = fabo_root_prefix();
    echo '<link rel="stylesheet" href="' . e(fabo_asset($pre . 'assets/sign.css')) . '">';
    if ($page === 'new' || (in_array($page, ['doc', 'sign', 'studio'], true) && !ds_gs())) {
        // The PDF viewer: fetched while the page loads rather than after it.
        $cdn = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/';
        echo '<link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin><link rel="preload" as="script" href="' . $cdn . 'pdf.min.js">'
           . '<link rel="prefetch" href="' . $cdn . 'pdf.worker.min.js">';
    }
    echo '<main class="wrap-page ds' . (ds_writable() ? '' : ' ds-ro') . '" id="main" data-page="' . e($page) . '">';
    $mine = ds_my_pending_count();
    echo '<div class="ds-ctx"><div class="ds-ctx-l"><span class="ds-org">' . ds_icon('building') . e(ds_org_name()) . '</span>';
    if (!empty(ds_viewer()['is_admin'])) {
        $orgs = ds_orgs_available();
        if (count($orgs) > 1) {
            echo '<form method="get" class="ds-orgpick"><input type="hidden" name="p" value="' . e($page === 'doc' || $page === 'sign' ? 'dashboard' : $page) . '">'
               . '<label class="sr-only" for="ds-org">Organisation</label><select id="ds-org" name="org" data-autosubmit>' . inc_options($orgs, ds_org()) . '</select></form>';
        }
    }
    echo '</div><div class="ds-ctx-r"><form method="get" role="search" class="ds-search"><input type="hidden" name="p" value="documents">' . ds_icon('search')
       . '<input type="search" name="q" value="' . e($page === 'documents' ? (string) ($_GET['q'] ?? '') : '') . '" placeholder="Search reference, title, tracking code…" aria-label="Search documents"></form>'
       . '<a class="ds-btn ' . ($mine ? 'ds-btn-primary' : 'ds-btn-ghost') . ' ds-btn-sm" href="' . e(ds_url('inbox')) . '">' . ds_icon('pen') . 'Awaiting me'
       . ($mine ? ' <span class="ds-count">' . $mine . '</span>' : '') . '</a></div></div>';
    if ($crumbs) {
        $trail = '<a href="' . e(ds_url('dashboard')) . '">Digital Signatures</a>';
        foreach ($crumbs as $label => $href) {
            $trail .= '<span aria-hidden="true">/</span>' . ($href ? '<a href="' . e($href) . '">' . e($label) . '</a>' : '<span>' . e($label) . '</span>');
        }
        echo '<nav class="ds-crumbs" aria-label="Breadcrumb">' . $trail . '</nav>';
    }
    echo '<header class="ds-hero ds-rise"><div class="ds-hero-in"><div class="ds-hero-t"><div class="ds-eyebrow">' . e($eyebrow) . '</div><h1>' . e($title) . '</h1>'
       . ($sub !== '' ? '<p>' . $sub . '</p>' : '') . '</div><div class="ds-hero-a">' . $actions . '</div></div></header>';
    if (!ds_writable()) {
        echo '<div class="ds-note">' . ds_icon('eye') . '<span><strong>View only.</strong> Your organisation holds Digital Signatures to read; nothing can be changed.</span></div>';
    }
    echo ds_flash_html(inc_take_flash()) . ds_flash_html($flash);
}

function ds_layout_end(): void
{
    $pre = fabo_root_prefix();
    echo '</main><div class="ds-tip" id="ds-tip" role="tooltip" hidden></div>'
       . '<script src="' . e(fabo_asset($pre . 'assets/inc.js')) . '" defer></script><script src="' . e(fabo_asset($pre . 'assets/sign.js')) . '" defer></script>';
    fabo_foot();
}

function ds_flash_html(?array $f): string
{
    if (!$f) {
        return '';
    }
    $ok = $f[0] === 'ok';
    return '<div class="ds-toast ' . ($ok ? 'ok' : 'err') . '" role="' . ($ok ? 'status' : 'alert') . '">' . ds_icon($ok ? 'check-circle' : 'alert') . '<span>' . e($f[1]) . '</span>'
         . '<button type="button" class="ds-toast-x" aria-label="Dismiss">' . ds_icon('x') . '</button></div>';
}

function ds_my_pending_count(): int
{
    static $n = null;
    return $n ??= (int) (fabo_one('SELECT COUNT(*) n FROM ds_signature_requests r JOIN ds_documents d ON d.id = r.doc_id
                                    WHERE r.org_id = ? AND r.person_id = ? AND r.status = "pending" AND d.deleted_at IS NULL', 'ii', [ds_org(), ds_me()])['n'] ?? 0);
}

/* ================================================================ bits */

function ds_badge(string $text, string $tone = '', bool $dot = true): string
{
    return '<span class="ds-pill' . ($tone ? ' t-' . e($tone) : '') . '">' . ($dot ? '<i></i>' : '') . e($text) . '</span>';
}

function ds_status_badge(string $status): string
{
    [$l, $t] = ds_doc_statuses()[$status] ?? [$status, ''];
    return ds_badge($l, $t);
}

function ds_req_badge(string $status): string
{
    [$l, $t] = ds_request_statuses()[$status] ?? [$status, ''];
    return ds_badge($l, $t);
}

function ds_verdict_badge(string $v): string
{
    [$l, $t] = ds_verdicts()[$v] ?? [$v, ''];
    return ds_badge($l, $t);
}

function ds_method_chip(?string $m): string
{
    if (!$m) {
        return '';
    }
    $cls = ds_method_is_digital($m) ? 'digital' : ($m === 'drawn' ? 'drawn' : 'elec');
    $icon = ['esign' => 'fingerprint', 'dsc' => 'usb', 'drawn' => 'pen', 'eapproval' => 'check', 'review' => 'eye', 'digital' => 'shield', 'any' => 'pen'][$m] ?? 'pen';
    return '<span class="ds-chip m-' . $cls . '">' . ds_icon($icon) . e(ds_method_label($m)) . '</span>';
}

function ds_priority_chip(string $p): string
{
    return '<span class="ds-prio p-' . e($p) . '">' . e(ds_priorities()[$p] ?? $p) . '</span>';
}

/** Up to three initials of a name. */
function ds_initials(string $name): string
{
    $parts = array_filter(preg_split('/\s+/', trim($name)));
    return mb_strtoupper(implode('', array_map(static fn($p) => mb_substr($p, 0, 1), array_slice(array_values($parts), 0, 3)))) ?: '?';
}

function ds_avatar(int $personId, string $size = ''): string
{
    $name = ds_person_name($personId);
    $parts = preg_split('/\s+/', trim($name));
    $ini = mb_strtoupper(mb_substr($parts[0] ?? '?', 0, 1) . (count($parts) > 1 ? mb_substr((string) end($parts), 0, 1) : ''));
    $hue = (crc32((string) $personId) % 6) + 1;
    return '<span class="ds-av h' . $hue . ($size ? ' ' . $size : '') . '" title="' . e($name) . '" aria-hidden="true">' . e($ini) . '</span>';
}

function ds_empty(string $title, string $body = '', string $icon = 'inbox', string $action = ''): string
{
    return '<div class="ds-empty">' . '<div class="ds-empty-i">' . ds_icon($icon) . '</div><h3>' . e($title) . '</h3>' . ($body !== '' ? '<p>' . $body . '</p>' : '') . $action . '</div>';
}

function ds_field(string $label, string $input, string $hint = '', bool $req = false, string $cls = ''): string
{
    return '<div class="ds-field' . ($cls ? ' ' . e($cls) : '') . '"><label>' . e($label) . ($req ? ' <span class="ds-reqmark" aria-hidden="true">*</span>' : '') . '</label>'
         . $input . ($hint !== '' ? '<div class="ds-hint">' . $hint . '</div>' : '') . '</div>';
}

function ds_form(string $attrs = '', bool $files = false): string
{
    return '<form method="post"' . ($files ? ' enctype="multipart/form-data"' : '') . ($attrs !== '' ? ' ' . $attrs : '') . '>' . fabo_csrf_field();
}

/** A one-button POST form; $confirm asks first in a dialog. */
function ds_button(string $label, array $fields, string $cls = 'ds-btn ds-btn-sm', string $confirm = '', string $icon = ''): string
{
    $h = '<form method="post" class="ds-inline"' . ($confirm !== '' ? ' data-confirm="' . e($confirm) . '"' : '') . '>' . fabo_csrf_field();
    foreach ($fields as $k => $v) {
        $h .= '<input type="hidden" name="' . e($k) . '" value="' . e((string) $v) . '">';
    }
    return $h . '<button class="' . e($cls) . '">' . ($icon ? ds_icon($icon) : '') . e($label) . '</button></form>';
}

function ds_select(string $name, array $map, $value, string $blank = '', string $attr = ''): string
{
    return inc_select($name, $map, $value, $blank, $attr);
}

/** A headline figure: icon, animated number, label, footnote; a link when $href. */
function ds_kpi(string $label, int $value, string $icon, string $tone, string $href = '', string $foot = '', int $i = 0): string
{
    $tag = $href !== '' ? 'a href="' . e($href) . '"' : 'div';
    return '<' . $tag . ' class="ds-kpi t-' . e($tone) . ' ds-rise" style="--i:' . $i . '"><span class="ds-kpi-i">' . ds_icon($icon) . '</span>'
         . '<span class="ds-kpi-v" data-count="' . $value . '">' . number_format($value) . '</span><span class="ds-kpi-l">' . e($label) . '</span>'
         . ($foot !== '' ? '<span class="ds-kpi-f">' . $foot . '</span>' : '') . '</' . ($href !== '' ? 'a' : 'div') . '>';
}

function ds_card_start(string $title, string $actions = '', string $cls = '', string $icon = ''): string
{
    return '<section class="ds-card ds-rise ' . e($cls) . '"><div class="ds-card-h"><h2>' . ($icon ? ds_icon($icon) : '') . e($title) . '</h2>' . ($actions !== '' ? '<div class="ds-card-a">' . $actions . '</div>' : '') . '</div><div class="ds-card-b">';
}

function ds_card_end(): string
{
    return '</div></section>';
}

/** A filter bar. $fields: q, from, to, dept, cat, status, person, priority, owner. */
function ds_filters(string $page, array $fields, array $cur, array $keep = []): string
{
    $h = '<form method="get" class="ds-filters" role="search"><input type="hidden" name="p" value="' . e($page) . '">';
    foreach ($keep as $k => $v) {
        $h .= '<input type="hidden" name="' . e($k) . '" value="' . e((string) $v) . '">';
    }
    $lab = static fn(string $l, string $in) => '<label><span>' . e($l) . '</span>' . $in . '</label>';
    foreach ($fields as $f) {
        $h .= match ($f) {
            'q' => $lab('Search', '<input type="search" name="q" value="' . e((string) ($cur['q'] ?? '')) . '" placeholder="Reference, title, code">'),
            'from' => $lab('From', '<input type="date" name="from" value="' . e((string) ($cur['from'] ?? '')) . '">'),
            'to' => $lab('To', '<input type="date" name="to" value="' . e((string) ($cur['to'] ?? '')) . '">'),
            'dept' => ds_departments() ? $lab('Department', ds_select('dept', ds_departments(), $cur['dept'] ?? '', 'All departments')) : '',
            'cat' => $lab('Category', ds_select('cat', ds_categories(), $cur['cat'] ?? '', 'All categories')),
            'status' => $lab('Status', ds_select('status', array_map(static fn($s) => $s[0], ds_doc_statuses()), $cur['status'] ?? '', 'Any status')),
            'person' => $lab('Signatory', ds_select('person', ds_people(), $cur['person'] ?? '', 'Anyone')),
            'owner' => $lab('Owner', ds_select('owner', ds_people(), $cur['owner'] ?? '', 'Anyone')),
            'priority' => $lab('Priority', ds_select('priority', ds_priorities(), $cur['priority'] ?? '', 'Any')),
            default => '',
        };
    }
    return $h . '<div class="ds-filters-b"><button class="ds-btn ds-btn-primary ds-btn-sm">' . ds_icon('filter') . 'Apply</button><a class="ds-btn ds-btn-ghost ds-btn-sm" href="' . e(ds_url($page, $keep)) . '">Reset</a></div></form>';
}

function ds_pager(int $total, int $per, int $pg, string $page, array $q): string
{
    $pages = max(1, (int) ceil($total / $per));
    $h = '<nav class="ds-pager" aria-label="Pages"><span>' . number_format($total) . ' record' . ($total === 1 ? '' : 's') . ($pages > 1 ? ' · page ' . $pg . ' of ' . $pages : '') . '</span>';
    if ($pages > 1) {
        $mk = static fn($n, $l, $dis) => $dis ? '<span class="ds-btn ds-btn-sm ds-btn-ghost is-off">' . $l . '</span>' : '<a class="ds-btn ds-btn-sm ds-btn-ghost" href="' . e(ds_url($page, ['pg' => $n] + $q)) . '">' . $l . '</a>';
        $h .= '<span class="ds-pager-b">' . $mk($pg - 1, '‹ Previous', $pg <= 1) . $mk($pg + 1, 'Next ›', $pg >= $pages) . '</span>';
    }
    return $h . '</nav>';
}

/** Timeline entries from audit rows. */
function ds_timeline(array $rows, bool $withDoc = false): string
{
    if (!$rows) {
        return ds_empty('No activity yet', '', 'clock');
    }
    $icon = static function (string $a): array {
        return match (true) {
            in_array($a, ['signed', 'completed'], true) => ['shield-check', 'green'],
            in_array($a, ['approved', 'reviewed'], true) => ['check', 'green'],
            in_array($a, ['rejected', 'access_denied', 'callback_forged', 'esign_failed', 'dsc_rejected', 'reauth_failed'], true) => ['x', 'red'],
            in_array($a, ['changes', 'cycle_cancelled', 'cancel', 'expired', 'escalated'], true) => ['alert', 'amber'],
            in_array($a, ['requested', 'submit', 'resubmit', 'reminder', 'deadline_warning', 'delegated'], true) => ['send', 'blue'],
            in_array($a, ['create', 'upload', 'new_version', 'attach', 'lock'], true) => ['file', 'violet'],
            in_array($a, ['verify'], true) => ['shield', 'blue'],
            in_array($a, ['esign_start', 'dsc_download'], true) => ['fingerprint', 'blue'],
            in_array($a, ['comment'], true) => ['chat', ''],
            default => ['dot', ''],
        };
    };
    $h = '<ol class="ds-tl">';
    foreach ($rows as $i => $r) {
        [$ic, $tone] = $icon((string) $r['action']);
        $h .= '<li class="ds-rise" style="--i:' . min($i, 12) . '"><span class="ds-tl-i t-' . $tone . '">' . ds_icon($ic) . '</span><div class="ds-tl-b"><div class="ds-tl-t">' . e($r['summary']) . '</div>'
            . '<div class="ds-tl-m"><strong>' . e($r['actor']) . '</strong>' . ($r['designation'] ? ' · ' . e($r['designation']) : '') . ' · <time datetime="' . e(date('c', strtotime($r['created_at']))) . '" title="' . e(ds_fmt_local($r['created_at'])) . '">'
            . e(ds_fmt_local($r['created_at'])) . '</time>' . ($r['ip'] ? ' · ' . e($r['ip']) : '') . '<span class="ds-tl-seq" title="Audit entry and its seal">#' . (int) $r['seq'] . ' · ' . e(substr((string) $r['hash'], 0, 10)) . '</span></div></div></li>';
    }
    return $h . '</ol>';
}

/**
 * The workflow as a stepper: each stage, its people and where it stands.
 */
function ds_stepper(array $doc, array $reqs): string
{
    if (!$reqs) {
        $plan = json_decode((string) $doc['plan_json'], true);
        return ds_empty('Not sent yet', 'Choose a workflow or add signatories, then send it for signature.', 'send');
    }
    $stages = [];
    foreach ($reqs as $r) {
        $stages[(int) $r['stage_seq']][] = $r;
    }
    ksort($stages);
    $h = '<ol class="ds-steps">';
    $n = 0;
    foreach ($stages as $seq => $rs) {
        $done = ds_stage_done($rs);
        $active = !$done && array_filter($rs, static fn($r) => $r['status'] === 'pending');
        $bad = array_filter($rs, static fn($r) => in_array($r['status'], ['rejected', 'changes'], true));
        $cls = $bad ? 'is-bad' : ($done ? 'is-done' : ($active ? 'is-active' : 'is-wait'));
        $par = count($rs) > 1;
        $h .= '<li class="ds-step ' . $cls . ' ds-rise" style="--i:' . $n++ . '"><div class="ds-step-k"><span class="ds-step-n">' . ($done && !$bad ? ds_icon('check') : $seq) . '</span></div><div class="ds-step-b">'
            . '<div class="ds-step-t">' . e((string) $rs[0]['stage_name']) . ($par ? ' <span class="ds-step-par">' . ($rs[0]['completion'] === 'any' ? 'any one of ' : 'parallel · ') . count($rs) . '</span>' : '') . '</div><ul>';
        foreach ($rs as $r) {
            $h .= '<li>' . ds_req_avatar($r, 'sm') . '<span class="ds-step-p"><strong>' . e(ds_req_name($r)) . '</strong>'
                . ($r['on_behalf_of_id'] ? ' <em>for ' . e(ds_person_name((int) $r['on_behalf_of_id'])) . '</em>' : '')
                . '<small>' . e(ds_actions()[$r['action']] ?? $r['action']) . ((int) $r['mandatory'] ? '' : ' · optional') . ($r['acted_at'] ? ' · ' . e(ds_fmt_dt($r['acted_at'])) : ($r['due_at'] && $r['status'] === 'pending' ? ' · due ' . e(ds_fmt_dt($r['due_at'])) : '')) . '</small></span>'
                . ds_req_badge((string) $r['status']) . ($r['used_method'] && $r['used_method'] !== 'review' ? ds_method_chip($r['used_method']) : '') . '</li>';
        }
        $h .= '</ul></div></li>';
    }
    return $h . '</ol>';
}

/* =============================================================== charts */

/**
 * Horizontal bars for one measure (magnitude: one hue). $data: label =>
 * value; $link builds a URL for a bar. Each bar has a tooltip and its value
 * printed, so nothing depends on colour.
 */
function ds_hbars(array $data, string $unit = '', ?callable $link = null, string $empty = 'Nothing to show yet.'): string
{
    $data = array_filter($data, static fn($v) => $v > 0);
    if (!$data) {
        return '<p class="ds-muted ds-chart-empty">' . e($empty) . '</p>';
    }
    arsort($data);
    $max = max($data);
    $h = '<ul class="ds-hbars" role="list">';
    $i = 0;
    foreach ($data as $label => $v) {
        $pct = round($v / $max * 100, 1);
        $inner = '<span class="ds-hbar-l">' . e((string) $label) . '</span><span class="ds-hbar-t"><span class="ds-hbar-f" style="--w:' . $pct . '%;--i:' . $i . '"></span></span><span class="ds-hbar-v">' . number_format($v) . e($unit) . '</span>';
        $tip = e($label . ': ' . number_format($v) . $unit);
        $h .= '<li data-tip="' . $tip . '">' . ($link ? '<a href="' . e($link((string) $label)) . '">' . $inner . '</a>' : $inner) . '</li>';
        $i++;
    }
    return $h . '</ul>';
}

/**
 * A trend chart: up to four series over the same periods, one y axis,
 * 2px lines, end labels, a legend, and a hover crosshair (sign.js).
 * $series: name => [period => value]; $periods: period => label.
 */
function ds_linechart(array $series, array $periods, string $label): string
{
    $W = 640;
    $H = 220;
    $pl = 34;
    $pr = 96;
    $pt = 14;
    $pb = 28;
    $keys = array_keys($periods);
    $n = count($keys);
    $max = 1;
    foreach ($series as $s) {
        $max = max($max, ...array_map('intval', array_values($s) ?: [0]));
    }
    $step = $max <= 5 ? 1 : (int) ceil($max / 4 / (10 ** floor(log10(max(1, $max / 4))))) * (10 ** (int) floor(log10(max(1, $max / 4))));
    $top = (int) (ceil($max / $step) * $step);
    $x = static fn(int $i) => $pl + ($n > 1 ? $i * ($W - $pl - $pr) / ($n - 1) : 0);
    $y = static fn(float $v) => $pt + ($H - $pt - $pb) * (1 - $v / max(1, $top));
    $svg = '<svg class="ds-line" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . e($label) . '" preserveAspectRatio="xMidYMid meet">';
    for ($v = 0; $v <= $top; $v += $step) {
        $svg .= '<line class="g" x1="' . $pl . '" x2="' . ($W - $pr) . '" y1="' . round($y($v), 1) . '" y2="' . round($y($v), 1) . '"/><text class="ax" x="' . ($pl - 6) . '" y="' . round($y($v) + 3, 1) . '" text-anchor="end">' . $v . '</text>';
    }
    foreach ($keys as $i => $k) {
        if ($n <= 12 || $i % 2 === 0) {
            $svg .= '<text class="ax" x="' . round($x($i), 1) . '" y="' . ($H - 8) . '" text-anchor="middle">' . e($periods[$k]) . '</text>';
        }
    }
    $si = 0;
    $legend = '<div class="ds-legend">';
    $labels = [];
    foreach ($series as $name => $s) {
        $si++;
        $pts = [];
        foreach ($keys as $i => $k) {
            $pts[] = round($x($i), 1) . ',' . round($y((float) ($s[$k] ?? 0)), 1);
        }
        $svg .= '<polyline class="ln s' . $si . '" points="' . implode(' ', $pts) . '" pathLength="1"/>';
        foreach ($keys as $i => $k) {
            $svg .= '<circle class="pt s' . $si . '" cx="' . round($x($i), 1) . '" cy="' . round($y((float) ($s[$k] ?? 0)), 1) . '" r="3.5"/>';
        }
        $last = (float) ($s[end($keys)] ?? 0);
        $labels[] = [$y($last), $name . ' ' . (int) $last, $si];
        $legend .= '<span><i class="s' . $si . '"></i>' . e($name) . '</span>';
    }
    // End labels, nudged apart when they would collide.
    usort($labels, static fn($a, $b) => $a[0] <=> $b[0]);
    for ($i = 1; $i < count($labels); $i++) {
        if ($labels[$i][0] - $labels[$i - 1][0] < 13) {
            $labels[$i][0] = $labels[$i - 1][0] + 13;
        }
    }
    foreach ($labels as [$ly, $text, $si2]) {
        $svg .= '<text class="lbl" x="' . ($W - $pr + 8) . '" y="' . round($ly + 4, 1) . '">' . e($text) . '</text>';
    }
    // Hover columns: one per period, carrying every series' value.
    foreach ($keys as $i => $k) {
        $vals = [];
        foreach ($series as $name => $s) {
            $vals[] = $name . ': ' . (int) ($s[$k] ?? 0);
        }
        $cw = $n > 1 ? ($W - $pl - $pr) / ($n - 1) : $W;
        $svg .= '<rect class="hit" x="' . round($x($i) - $cw / 2, 1) . '" y="' . $pt . '" width="' . round($cw, 1) . '" height="' . ($H - $pt - $pb) . '" data-x="' . round($x($i), 1) . '" data-tip="' . e($periods[$k] . ' — ' . implode(' · ', $vals)) . '"/>';
    }
    $svg .= '<line class="xh" x1="0" x2="0" y1="' . $pt . '" y2="' . ($H - $pb) . '" hidden/></svg>';
    return '<div class="ds-linewrap">' . $legend . '</div></div>' . $svg;
}

/** A proportion ring for one share (e.g. completion rate). */
function ds_ring(float $frac, string $label, string $tone = 'blue'): string
{
    $frac = max(0, min(1, $frac));
    $c = 2 * M_PI * 34;
    return '<div class="ds-ring t-' . e($tone) . '" role="img" aria-label="' . e($label . ': ' . round($frac * 100) . '%') . '"><svg viewBox="0 0 80 80"><circle class="bg" cx="40" cy="40" r="34"/>'
         . '<circle class="fg" cx="40" cy="40" r="34" style="--c:' . round($c, 2) . ';--o:' . round($c * (1 - $frac), 2) . '"/></svg><span><strong>' . round($frac * 100) . '%</strong><small>' . e($label) . '</small></span></div>';
}

/* ================================================================ icons */

function ds_icon(string $name, string $cls = 'ds-i'): string
{
    static $p = null;
    $p ??= [
        'pen' => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
        'sign' => '<path d="M3 17c3-1 4-6 6-6s1 5 3 5 3-4 5-4 2 3 4 3"/><path d="M3 21h18"/>',
        'file' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/>',
        'files' => '<path d="M15 3H8a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h9a2 2 0 0 0 2-2V7z"/><path d="M15 3v4h4"/><path d="M4 7v12a2 2 0 0 0 2 2h9"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'check' => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
        'check-circle' => '<circle cx="12" cy="12" r="9"/><path d="M8 12.5l3 3 5-6"/>',
        'shield' => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/>',
        'shield-check' => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
        'x' => '<path d="M6 6l12 12M18 6L6 18"/>',
        'x-circle' => '<circle cx="12" cy="12" r="9"/><path d="M9 9l6 6M15 9l-6 6"/>',
        'alert' => '<path d="M12 3l9.5 17h-19z"/><path d="M12 10v4M12 17.5v.01"/>',
        'hourglass' => '<path d="M6 3h12M6 21h12M7 3c0 5 10 6 10 9s-10 4-10 9M17 3c0 5-10 6-10 9"/>',
        'eye' => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/>',
        'filter' => '<path d="M4 5h16l-6 8v5l-4 2v-7z"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'upload' => '<path d="M12 16V4M7 9l5-5 5 5"/><path d="M4 16v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/>',
        'download' => '<path d="M12 4v12M7 11l5 5 5-5"/><path d="M4 16v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/>',
        'send' => '<path d="M21 3L10 14"/><path d="M21 3l-7 18-4-7-7-4z"/>',
        'inbox' => '<path d="M3 13l2.5-8h13L21 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M3 13h5l1 3h6l1-3h5"/>',
        'building' => '<rect x="4" y="3" width="16" height="18" rx="1.5"/><path d="M9 7h2M13 7h2M9 11h2M13 11h2M9 15h2M13 15h2"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M3 20a6 6 0 0 1 12 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14.5a6 6 0 0 1 3 5.5"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'flow' => '<rect x="3" y="3" width="6" height="6" rx="1.5"/><rect x="15" y="15" width="6" height="6" rx="1.5"/><path d="M9 6h4a2 2 0 0 1 2 2v7"/>',
        'folder' => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        'plug' => '<path d="M9 3v5M15 3v5M6 8h12v3a6 6 0 0 1-12 0z"/><path d="M12 17v4"/>',
        'key' => '<circle cx="7.5" cy="15.5" r="4.5"/><path d="M10.7 12.3L20 3M16 7l3 3M14 9l2 2"/>',
        'swap' => '<path d="M7 7h13l-4-4M17 17H4l4 4"/>',
        'list' => '<path d="M9 6h12M9 12h12M9 18h12M4 6h.01M4 12h.01M4 18h.01"/>',
        'gear' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
        'chart' => '<path d="M4 20V11M10 20V5M16 20v-7M2 20h20"/>',
        'gauge' => '<path d="M4 18a8 8 0 1 1 16 0"/><path d="M12 18l4-6"/>',
        'fingerprint' => '<path d="M12 11v3a8 8 0 0 1-1.5 4.7"/><path d="M8.5 9.5A4 4 0 0 1 16 11v1.5a12 12 0 0 1-.9 4.5"/><path d="M5.3 7.5A8 8 0 0 1 20 11v1"/><path d="M5 11a7 7 0 0 0 0 1 12 12 0 0 1-1 4.5"/><path d="M12 7a4 4 0 0 0-4 4 12 12 0 0 1-1.6 6"/>',
        'usb' => '<rect x="8" y="2" width="8" height="7" rx="1"/><path d="M6 9h12v6a6 6 0 0 1-12 0z"/><path d="M10 5h.01M14 5h.01"/>',
        'chat' => '<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1.1-4.6A8 8 0 1 1 21 12z"/>',
        'paperclip' => '<path d="M21 11l-9 9a5 5 0 0 1-7-7l9-9a3.5 3.5 0 0 1 5 5l-9 9a2 2 0 0 1-3-3l8-8"/>',
        'history' => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5M12 7v5l3 2"/>',
        'share' => '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4"/>',
        'refresh' => '<path d="M20 11a8 8 0 0 0-14.9-3M4 13a8 8 0 0 0 14.9 3"/><path d="M5 3v5h5M19 21v-5h-5"/>',
        'bell' => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10 21a2 2 0 0 0 4 0"/>',
        'trash' => '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
        'edit' => '<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="M14 6l4 4"/>',
        'zoom-in' => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4M11 8v6M8 11h6"/>',
        'zoom-out' => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4M8 11h6"/>',
        'chev-l' => '<path d="M15 6l-6 6 6 6"/>',
        'chev-r' => '<path d="M9 6l6 6-6 6"/>',
        'chev-d' => '<path d="M6 9l6 6 6-6"/>',
        'grip' => '<path d="M9 6h.01M15 6h.01M9 12h.01M15 12h.01M9 18h.01M15 18h.01"/>',
        'lock' => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
        'link' => '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/>',
        'dot' => '<circle cx="12" cy="12" r="3"/>',
        'move' => '<path d="M12 3v18M3 12h18M12 3l-3 3M12 3l3 3M12 21l-3-3M12 21l3-3M3 12l3-3M3 12l3 3M21 12l-3-3M21 12l-3 3"/>',
        'qr' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3M21 14v.01M14 21h3M21 17v4h-4"/>',
        'api' => '<path d="M8 9l-4 3 4 3M16 9l4 3-4 3M13.5 6l-3 12"/>',
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
    ];
    return '<svg class="' . e($cls) . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . ($p[$name] ?? $p['dot']) . '</svg>';
}

/** Streams a stored file to the browser, inline or as a download. Logged. */
function ds_send_file(int $fileId, string $name, bool $inline, ?int $docId, string $why, bool $pinned = false): void
{
    $f = ds_file($fileId);
    if (!$f) {
        fabo_deny('That file is not available.', 404);
    }
    // Parallel range requests must not queue behind this request's session lock.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $range = (string) ($_SERVER['HTTP_RANGE'] ?? '');
    if ((!$inline || $why !== 'preview') && $range === '') {
        ds_audit($docId, 'file', $fileId, 'download', ds_actor() . ' downloaded ' . $name . ' (' . $why . ')', ['sha256' => $f['sha256']]);
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    /* A stored file never changes (a new signature is a new file), so its
       SHA-256 is its ETag: the browser keeps a private copy and asks each
       time — every request still passes the access check above — and gets
       "not modified" instead of the whole PDF again. */
    $etag = '"' . $f['sha256'] . '"';
    header('ETag: ' . $etag);
    header('Cache-Control: private, ' . ($pinned ? 'max-age=86400' : 'no-cache'));
    header('Accept-Ranges: bytes');
    header('X-Content-Type-Options: nosniff');
    header('Content-Security-Policy: sandbox; default-src \'none\'');
    if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
        http_response_code(304);
        exit;
    }
    $bytes = ds_read($fileId);
    $size = strlen($bytes);
    header('Content-Type: ' . ($f['mime'] ?: 'application/pdf'));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) . '"');
    // Byte ranges let the viewer draw the first page before the rest has arrived.
    if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m) && ($m[1] !== '' || $m[2] !== '')
        && (!isset($_SERVER['HTTP_IF_RANGE']) || trim($_SERVER['HTTP_IF_RANGE']) === $etag)) {
        if ($m[1] === '') {
            $start = max(0, $size - (int) $m[2]);
            $end = $size - 1;
        } else {
            $start = (int) $m[1];
            $end = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);
        }
        if ($start > $end || $start >= $size) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            exit;
        }
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        header('Content-Length: ' . ($end - $start + 1));
        echo substr($bytes, $start, $end - $start + 1);
        exit;
    }
    header('Content-Length: ' . $size);
    echo $bytes;
    exit;
}

/**
 * The signature box: draw it on the pad, or upload an image of it (its
 * white background is made transparent in the browser). Fills the hidden
 * "signature" field with a PNG. $optional: an empty box is allowed.
 */
function ds_pad_html(bool $optional = false): string
{
    static $n = 0;
    $n++;
    return '<div class="ds-padbox" data-padbox' . ($optional ? ' data-pad-optional' : '') . '>'
         . '<div class="ds-seg small" role="radiogroup" aria-label="How to give your signature">'
         . '<label><input type="radio" name="padmode' . $n . '" value="draw" data-padmode checked><span>' . ds_icon('pen') . 'Draw</span></label>'
         . '<label><input type="radio" name="padmode' . $n . '" value="upload" data-padmode><span>' . ds_icon('upload') . 'Upload image</span></label></div>'
         . '<div class="ds-pad is-empty" data-padpane="draw"><canvas data-pad width="560" height="180" aria-label="Signature pad: draw with mouse, finger or stylus" role="img"></canvas>'
         . '<span class="ds-pad-base">Sign above the line</span><div class="ds-pad-a"><button type="button" class="ds-btn ds-btn-ghost ds-btn-sm" data-pad-undo>Undo</button>'
         . '<button type="button" class="ds-btn ds-btn-ghost ds-btn-sm" data-pad-clear>Clear</button></div></div>'
         . '<div class="ds-padup" data-padpane="upload" hidden><label class="ds-drop small"><input type="file" accept="image/*" data-pad-file>'
         . '<span class="ds-drop-i">' . ds_icon('upload') . '</span><strong>Choose an image of your signature</strong><span>PNG or JPG · a photo or scan of it on white paper works</span></label>'
         . '<img class="ds-padprev" data-pad-preview alt="Your signature" hidden><p class="ds-hint" data-pad-note></p></div>'
         . '<input type="hidden" name="signature" data-pad-out></div>';
}

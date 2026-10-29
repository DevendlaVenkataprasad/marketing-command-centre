<?php
/**
 * HR Management — the front controller shared by admin/hr.php (the
 * administrator) and hr.php (anyone the administrator has granted an HR
 * role). Both call hr_app() with the viewer they resolved; from there the
 * same pages run, and every one of them asks hr_can()/hr_emp_allowed()
 * before it reads or writes anything.
 */

require_once __DIR__ . '/reports.php';
require_once __DIR__ . '/attention.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/assess.php';

/**
 * page => [module that must be viewable, title, file]. A null module means
 * the page decides for itself (My HR, Recruitment for interviewers, Setup).
 */
function hr_pages(): array
{
    return [
        'dashboard'  => ['dashboard', 'HR Dashboard', 'dashboard'],
        'employees'  => ['employees', 'Employees', 'employees'],
        'employee'   => ['employees', 'Employee', 'employee'],
        'orgchart'   => ['employees', 'Org Chart', 'orgchart'],
        'recruitment' => [null, 'Recruitment', 'recruitment'],
        'assessment' => ['recruitment', 'Interview Assessments', 'assessment'],
        'lifecycle'  => ['lifecycle', 'Onboarding & Exits', 'lifecycle'],
        'leave'      => ['leave', 'Leave', 'leave'],
        'attendance' => ['attendance', 'Attendance', 'attendance'],
        'holidays'   => ['holidays', 'Holidays', 'holidays'],
        'payroll'    => ['payroll', 'Payroll', 'payroll'],
        'claims'     => ['claims', 'Expense Claims', 'claims'],
        'performance' => ['performance', 'Performance Reviews', 'performance'],
        'training'   => ['training', 'Training & Development', 'training'],
        'assets'     => ['assets', 'Assets', 'assets'],
        'helpdesk'   => ['helpdesk', 'Helpdesk & Announcements', 'helpdesk'],
        'reports'    => ['reports', 'Reports & Analytics', 'reports'],
        'setup'      => [null, 'HR Setup', 'setup'],
        'sheets'     => ['sheets', 'Policies & Rules', 'sheets'],
        'masters'    => ['masters', 'Organisation Structure', 'masters'],
        'schedules'  => ['schedules', 'Shifts & Work Calendars', 'schedules'],
        'settings'   => ['access', 'Access Control', 'settings'],
        'me'         => [null, 'My HR', 'me'],
        'file'       => [null, 'Download', 'file'],
        'api'        => [null, 'API', 'api'],
    ];
}

/** May the viewer open the HR Setup hub? Anyone who can see at least one setup area. */
function hr_can_setup(): bool
{
    if (hr_is_admin()) {
        return true;
    }
    foreach (['sheets', 'masters', 'schedules'] as $m) {
        if (hr_can($m, 'view')) {
            return true;
        }
    }
    foreach (['leave', 'attendance', 'payroll', 'lifecycle', 'claims', 'performance'] as $m) {
        if (hr_scope($m, 'configure') === 'org') {
            return true;
        }
    }
    return false;
}

function hr_app(array $viewer): void
{
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store, private');
    hr_start($viewer);
    if (!hr_has_any()) {
        error_log('FABO HR denied ' . hr_actor() . ' -> no HR role');
        fabo_deny('You do not have access to HR Management. Ask your administrator if you need it.', 403);
    }
    $page = (string) ($_GET['p'] ?? '');
    $pages = hr_pages();
    if (!isset($pages[$page])) {
        $page = hr_default_page();
    }
    [$module] = $pages[$page];
    if ($module !== null) {
        hr_need($module, 'view');
    }
    if ($page === 'me' && !hr_self_emp()) {
        if (hr_is_admin()) {
            $page = 'dashboard';
        } else {
            fabo_deny('Your sign-in account is not linked to an employee record yet. Ask HR to link it.', 403);
        }
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        fabo_csrf_check();
    }
    $GLOBALS['hr_page'] = $page;
    require __DIR__ . '/pages/' . $pages[$page][2] . '.php';
}

/** Where a viewer lands when no page is named. */
function hr_default_page(): string
{
    if (hr_is_admin()) {
        return 'dashboard';
    }
    if (hr_self_emp() && (hr_scope('leave', 'view') === 'self' || hr_scope('attendance', 'view') === 'self'
        || hr_scope('payroll', 'view') === 'self' || !hr_can('dashboard'))) {
        return 'me';
    }
    foreach (hr_pages() as $p => [$module]) {
        if ($module && hr_can($module, 'view')) {
            return $p;
        }
    }
    return hr_self_emp() ? 'me' : 'dashboard';
}

/* --------------------------------------------------------------- flash */

/** Post/redirect/get: the message survives one redirect. */
function hr_redirect(string $url, ?array $flash = null): void
{
    if ($flash) {
        fabo_session();
        $_SESSION['hr_flash'] = $flash;
    }
    header('Location: ' . $url);
    exit;
}

function hr_take_flash(): ?array
{
    fabo_session();
    $f = $_SESSION['hr_flash'] ?? null;
    unset($_SESSION['hr_flash']);
    return is_array($f) ? $f : null;
}

/** Runs a POST action and redirects with its result; a refusal stays on the page with the form values. */
function hr_action(callable $fn, string $okUrl): ?array
{
    try {
        $msg = $fn();
        hr_redirect($okUrl, ['ok', is_string($msg) ? $msg : 'Saved.']);
    } catch (InvalidArgumentException $e) {
        return ['err', $e->getMessage()];
    } catch (Throwable $e) {
        return ['err', fabo_user_error($e)];
    }
    return null;
}

/* -------------------------------------------------------------- layout */

/**
 * Opens an HR page: the portal shell, then breadcrumbs, title, the
 * organisation switcher (admin) and the page's own actions.
 */
function hr_layout_start(string $title, string $sub = '', array $crumbs = [], string $actions = ''): void
{
    $ctx = hr_ctx();
    $page = (string) ($GLOBALS['hr_page'] ?? 'dashboard');
    fabo_head($ctx['viewer'], $title . ' · HR', 'hr-' . $page);
    echo '<main class="wrap-page hr" id="main">';
    $trail = '<a href="' . e(hr_url(hr_default_page())) . '">HR Management</a>';
    foreach ($crumbs as $label => $href) {
        $trail .= ' &rsaquo; ' . ($href ? '<a href="' . e($href) . '">' . e($label) . '</a>' : e($label));
    }
    echo '<nav class="hr-crumbs" aria-label="Breadcrumb">' . $trail . '</nav>';
    echo '<div class="hr-head"><div class="pagehead"><h1>' . e($title) . '</h1>'
       . ($sub !== '' ? '<div class="sub">' . $sub . '</div>' : '') . '</div><div class="acts">' . $actions;
    if (hr_is_portal_admin()) {
        $choices = hr_org_choices();
        if (count($choices) > 1) {
            $q = $_GET;
            unset($q['org'], $q['pg']);
            echo '<form method="get" class="hr-org"><label for="hr-org">Company</label>';
            foreach ($q as $k => $v) {
                if (is_string($v)) {
                    echo '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
                }
            }
            echo '<select id="hr-org" name="org" data-autosubmit>' . hr_options($choices, hr_org()) . '</select>'
               . '<noscript><button class="btn btn-sm">Go</button></noscript></form>';
        }
    }
    echo '</div></div>';
    echo hr_flash_html(hr_take_flash());
}

function hr_layout_end(): void
{
    $js = hr_is_portal_admin() ? '../assets/hr.js' : 'assets/hr.js';
    echo '</main><script src="' . e(fabo_asset($js)) . '" defer></script>';
    fabo_foot();
}

/**
 * A short, collapsible explanation of how a page works, closed by default so
 * it is there for whoever needs it and out of the way for everyone else.
 */
function hr_guide(string $title, array $points): string
{
    $h = '<details class="hr-guide"><summary>' . e($title) . '</summary><ol>';
    foreach ($points as $p) {
        $h .= '<li>' . $p . '</li>';
    }
    return $h . '</ol></details>';
}

/** A row of numbered steps with the current one marked. $steps: key => label. */
function hr_steps_html(array $steps, string $current, array $skip = []): string
{
    $h = '<ol class="hr-steps">';
    $keys = array_keys($steps);
    $pos = array_search($current, $keys, true);
    foreach ($keys as $i => $k) {
        if (in_array($k, $skip, true)) {
            continue;
        }
        $cls = $pos === false ? '' : ($i < $pos ? 'done' : ($i === $pos ? 'now' : ''));
        $h .= '<li class="' . $cls . '">' . e($steps[$k]) . '</li>';
    }
    return $h . '</ol>';
}

/* -------------------------------------------- shared small renderers */

/** Stars for a 1–5 rating. */
function hr_stars($n): string
{
    $n = (int) $n;
    return $n ? '<span class="hr-stars" aria-label="' . $n . ' of 5">' . str_repeat('★', $n) . '<i>' . str_repeat('★', 5 - $n) . '</i></span>' : '';
}

/** A task list with the right buttons on each row. */
function hr_render_tasks(array $tasks, string $back, bool $showWho = false): void
{
    if (!$tasks) {
        echo hr_empty('No tasks.');
        return;
    }
    $owners = hr_task_owners();
    echo '<div class="scrollx"><table class="data hr-table stack"><thead><tr>' . ($showWho ? '<th>Employee</th>' : '')
       . '<th>Task</th><th>Who</th><th>Due</th><th>Status</th><th></th></tr></thead><tbody>';
    foreach ($tasks as $t) {
        $over = $t['status'] === 'pending' && $t['due_date'] && $t['due_date'] < date('Y-m-d');
        $st = $t['status'] === 'pending' ? ($over ? hr_badge('overdue', 'Overdue') : hr_badge('pending', 'To do'))
            : hr_badge($t['status'] === 'done' ? 'done' : 'skipped', $t['status'] === 'done' ? 'Done' : 'Not needed');
        echo '<tr>' . ($showWho ? '<td data-l="Employee">' . hr_person_cell($t) . '</td>' : '')
           . '<td data-l="Task"><strong>' . e($t['title']) . '</strong>' . ($t['note'] ? '<div class="muted small">' . e($t['note']) . '</div>' : '')
           . ($t['done_by'] ? '<div class="muted small">' . e($t['done_by']) . ' · ' . e(date('d M', strtotime((string) $t['done_at']))) . '</div>' : '') . '</td>'
           . '<td data-l="Who">' . e($owners[$t['owner']] ?? $t['owner']) . '</td>'
           . '<td data-l="Due" class="nowrap">' . e($t['due_date'] ? hr_fmt_date($t['due_date']) : '—') . '</td><td data-l="Status">' . $st . '</td><td class="hr-acts">';
        if (hr_task_can_complete($t)) {
            if ($t['status'] === 'pending') {
                echo hr_button_form('Done', ['action' => 'task', 'id' => $t['id'], 'status' => 'done', 'back' => $back], 'btn btn-sm btn-approve')
                   . hr_button_form('Not needed', ['action' => 'task', 'id' => $t['id'], 'status' => 'skipped', 'back' => $back], 'btn btn-sm');
            } else {
                echo hr_button_form('Reopen', ['action' => 'task', 'id' => $t['id'], 'status' => 'pending', 'back' => $back], 'btn btn-sm');
            }
        }
        echo '</td></tr>';
    }
    echo '</tbody></table></div>';
}

/** Progress bar text. */
function hr_progress_html(int $done, int $total): string
{
    $pct = $total ? (int) round($done * 100 / $total) : 0;
    return '<div class="hr-mini-bar" title="' . $done . ' of ' . $total . ' done"><i style="width:' . $pct . '%"></i></div><span class="muted small">' . $done . ' / ' . $total . '</span>';
}

/** A strip of tabs inside a desk. $tabs: key => label (only those the viewer may open). */
function hr_tabs(string $page, array $tabs, string $current, array $keep = []): void
{
    if (count($tabs) < 2) {
        return;
    }
    echo '<nav class="subtabs" aria-label="Sections">';
    foreach ($tabs as $k => $label) {
        echo '<a href="' . e(hr_url($page, ['tab' => $k] + $keep)) . '"' . ($k === $current ? ' class="on" aria-current="page"' : '')
           . '>' . $label . '</a>';
    }
    echo '</nav>';
}

/** The current tab, constrained to the ones offered. */
function hr_tab(array $tabs, string $default): string
{
    $t = (string) ($_GET['tab'] ?? $default);
    return isset($tabs[$t]) ? $t : (isset($tabs[$default]) ? $default : (string) array_key_first($tabs));
}

/** An employee's name with photo (or initial) and ID, linked to their profile when allowed. */
function hr_person_cell(array $e, bool $link = true): string
{
    $id = (int) ($e['employee_id'] ?? $e['id']);
    $name = (string) ($e['full_name'] ?? '');
    $img = !empty($e['photo_file']) && hr_emp_allowed($id, 'employees', 'view')
         ? '<img src="' . e(hr_url('file', ['kind' => 'photo', 'id' => $id, 'v' => substr(md5((string) $e['photo_file']), 0, 6)])) . '" alt="">'
         : '<span class="ph" aria-hidden="true">' . e(mb_strtoupper(mb_substr($name, 0, 1))) . '</span>';
    $nm = $link && hr_emp_allowed($id, 'employees', 'view')
        ? '<a class="nm" href="' . e(hr_url('employee', ['id' => $id])) . '">' . e($name) . '</a>'
        : '<span class="nm">' . e($name) . '</span>';
    return '<div class="hr-person">' . $img . '<div>' . $nm . '<div class="sub">' . e($e['emp_code'] ?? '') . '</div></div></div>';
}

function hr_empty(string $title, string $body = ''): string
{
    return '<div class="hr-empty"><strong>' . e($title) . '</strong>' . e($body) . '</div>';
}

/** A labelled form field. $input is ready HTML. */
function hr_field(string $label, string $input, bool $required = false, string $hint = '', string $cls = ''): string
{
    return '<div class="field' . ($cls ? ' ' . e($cls) : '') . '"><label>' . e($label) . ($required ? ' <span class="req" aria-hidden="true">*</span>' : '')
         . '</label>' . $input . ($hint !== '' ? '<div class="hr-hint">' . $hint . '</div>' : '') . '</div>';
}

function hr_input(string $name, $value = '', string $type = 'text', array $attr = []): string
{
    $a = '';
    foreach ($attr as $k => $v) {
        $a .= $v === true ? ' ' . e($k) : ($v === false || $v === null ? '' : ' ' . e($k) . '="' . e($v) . '"');
    }
    return '<input type="' . e($type) . '" name="' . e($name) . '" value="' . e((string) $value) . '"' . $a . '>';
}

function hr_select(string $name, array $opts, $value, string $blank = '', array $attr = []): string
{
    $a = '';
    foreach ($attr as $k => $v) {
        $a .= $v === true ? ' ' . e($k) : ' ' . e($k) . '="' . e($v) . '"';
    }
    return '<select name="' . e($name) . '"' . $a . '>' . hr_options($opts, $value, $blank) . '</select>';
}

function hr_checkbox(string $name, bool $checked, string $label, string $value = '1'): string
{
    return '<label class="check"><input type="checkbox" name="' . e($name) . '" value="' . e($value) . '"' . ($checked ? ' checked' : '') . '> '
         . e($label) . '</label>';
}

/** The weekly-off picker: one row per weekday, every week or chosen weeks. */
function hr_offs_picker(?string $pattern, string $prefix = 'off'): string
{
    $cur = [];
    foreach (hr_parse_offs($pattern) as $o) {
        $cur[$o['dow']][] = $o['nth'];
    }
    $opts = ['' => 'Working day', 'all' => 'Off every week', '1,3' => 'Off 1st & 3rd', '2,4' => 'Off 2nd & 4th',
             '1,3,5' => 'Off 1st, 3rd & 5th', '2' => 'Off 2nd only', '4' => 'Off 4th only'];
    $h = '<div class="hr-offs">';
    foreach (hr_weekday_names() as $d => $name) {
        $val = '';
        if (isset($cur[$d])) {
            $val = in_array(0, $cur[$d], true) ? 'all' : implode(',', $cur[$d]);
        }
        $o = $opts;
        if ($val !== '' && !isset($o[$val])) {
            $o[$val] = 'Off weeks ' . $val;
        }
        $h .= '<div><label for="' . e($prefix . '_' . $d) . '">' . e($name) . '</label>'
            . '<select id="' . e($prefix . '_' . $d) . '" name="' . e($prefix . '_' . $d) . '">' . hr_options($o, $val) . '</select></div>';
    }
    return $h . '</div>';
}

/** A POST form with CSRF. $attrs adds attributes such as data-confirm. */
function hr_form_open(string $action = '', string $attrs = '', bool $files = false): string
{
    return '<form method="post"' . ($action !== '' ? ' action="' . e($action) . '"' : '') . ($files ? ' enctype="multipart/form-data"' : '')
         . ($attrs !== '' ? ' ' . $attrs : '') . '>' . fabo_csrf_field();
}

/** A one-button POST form. */
function hr_button_form(string $label, array $fields, string $cls = 'btn btn-sm', string $confirm = ''): string
{
    $h = '<form method="post" class="hr-inline"' . ($confirm !== '' ? ' data-confirm="' . e($confirm) . '"' : '') . '>' . fabo_csrf_field();
    foreach ($fields as $k => $v) {
        $h .= '<input type="hidden" name="' . e($k) . '" value="' . e((string) $v) . '">';
    }
    return $h . '<button class="' . e($cls) . '">' . e($label) . '</button></form>';
}

/** Mini month picker values for a select: last 24 months. */
function hr_month_choices(int $back = 24, int $ahead = 0): array
{
    $out = [];
    for ($i = -$ahead; $i <= $back; $i++) {
        $m = date('Y-m', strtotime(date('Y-m-01') . ' -' . $i . ' month'));
        $out[$m] = date('F Y', strtotime($m . '-01'));
    }
    return $out;
}

/** An upload's temp path and name, or throws. */
function hr_uploaded_table(string $field): array
{
    $f = $_FILES[$field] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        throw new InvalidArgumentException('Choose a .csv or .xlsx file to import.');
    }
    if ((int) $f['size'] > 10 * 1024 * 1024) {
        throw new InvalidArgumentException('Import files must be 10 MB or smaller.');
    }
    $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv', 'xlsx', 'xls', 'txt'], true)) {
        throw new InvalidArgumentException('Upload a .xls, .xlsx or .csv file.');
    }
    return [$f['tmp_name'], (string) $f['name']];
}

/** Renders an import result. */
function hr_import_report(array $res, bool $dry): string
{
    $h = '<div class="' . ($res['errors'] ? 'hr-warn' : 'hr-note') . '"><strong>' . ($dry ? 'Check only — nothing was saved. ' : '')
       . (int) $res['created'] . ' of ' . (int) $res['rows'] . ' row(s) ' . ($dry ? 'can be imported' : 'imported') . '.</strong>';
    if ($res['errors']) {
        $h .= '<ul>';
        $i = 0;
        foreach ($res['errors'] as $line => $msg) {
            if (++$i > 50) {
                $h .= '<li>… and ' . (count($res['errors']) - 50) . ' more.</li>';
                break;
            }
            $h .= '<li>Line ' . (int) $line . ': ' . e($msg) . '</li>';
        }
        $h .= '</ul>';
    }
    return $h . '</div>';
}

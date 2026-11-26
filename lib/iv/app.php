<?php
/**
 * Startup Discovery — the owner's desk.
 *
 *   overview    the current round, every department's progress, the HoD portal link
 *   dashboards  departments and their Heads (one dashboard each): add one, upload many, invite
 *   dashboard   one department: its Head, AI limit, the form link, a later deadline, its ideas
 *   students    every student once: filter, add one, upload many
 *   student     one student: edit, their ideas and team
 *   rounds      submission windows, the Gem, AI checks per idea, top N
 *   gems        the Gems themselves: rubric, questions, a trial run (lib/iv/gempage.php)
 *   ideas       every idea of a round, any department
 *   pipeline    the ideas HoDs sent forward, by stage
 *   idea        one idea: answers, AI checks, HoD decision, stage, its team
 *   import      students from Incubation Management
 *   settings    the HoD portal address, colleges, audit
 *
 * idea-validation.php (a client granted the module, or their team) and
 * admin/idea-validation.php (the administrator, for any organisation holding
 * it) both land in iv_main().
 */

require_once __DIR__ . '/../ui.php';
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/gem.php';
require_once __DIR__ . '/import.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/gempage.php';

function iv_desk_pages(): array
{
    return iv_nav_pages() + ['dashboard' => 'HoD dashboard', 'student' => 'Student', 'idea' => 'Idea'];
}

function iv_url(string $page, array $q = []): string
{
    $q = ['p' => $page] + $q;
    if (!empty($GLOBALS['iv_viewer']['is_admin'])) {
        $q['org'] = $GLOBALS['iv_org'];
    }
    return 'idea-validation.php?' . http_build_query($q);
}

function iv_can_edit(): bool
{
    return ($GLOBALS['iv_grant'] ?? 'none') === 'edit';
}

function iv_actor(): string
{
    $v = $GLOBALS['iv_viewer'] ?? [];
    return !empty($v['is_admin']) ? 'Administrator (owner)' : (string) ($v['client']['name'] ?? '?') . ' (owner)';
}

function iv_flash(string $tone, string $msg, string $extra = ''): void
{
    $_SESSION['iv_flash'] = [$tone, $msg, $extra];
}

function iv_go(string $page, array $q = []): void
{
    header('Location: ' . iv_url($page, $q), true, 303);
    exit;
}

/* ================================================================ pieces */

function iv_kpi(string $label, $value, string $tone = '', string $href = ''): string
{
    $tag = $href !== '' ? 'a href="' . e($href) . '"' : 'div';
    return '<' . $tag . ' class="iv-kpi ' . e($tone) . '"><span class="k">' . e($label) . '</span><strong>' . e(is_int($value) ? fabo_thousands($value) : (string) $value) . '</strong></' . ($href !== '' ? 'a' : 'div') . '>';
}

function iv_pill(string $status): string
{
    return '<span class="iv-pill s-' . e($status) . '">' . e(iv_statuses()[$status] ?? $status) . '</span>';
}

function iv_stage_pill(?string $stage): string
{
    return $stage === null ? '<span class="iv-pill s-none">With HoD</span>' : '<span class="iv-pill g-' . e($stage) . '">' . e(iv_stages()[$stage] ?? $stage) . '</span>';
}

function iv_copy(string $url, string $label = 'Copy'): string
{
    return '<span class="iv-copy"><input type="text" readonly value="' . e($url) . '" aria-label="Link"><button type="button" class="btn btn-sm" data-copy="' . e($url) . '">' . e($label) . '</button></span>';
}

function iv_hod_state(array $d): string
{
    if (empty($d['hod_id'])) {
        return '<span class="iv-pill s-none">No HoD yet</span>';
    }
    if ($d['hod_status'] === 'suspended') {
        return '<span class="iv-pill s-rejected">Suspended</span>';
    }
    if (!(int) $d['hod_ready']) {
        return '<span class="iv-pill s-submitted">' . ($d['invited_at'] ? 'Invited ' . e(iv_date($d['invited_at'])) : 'Not invited') . '</span>';
    }
    return '<span class="iv-pill s-validated">' . ($d['last_login_at'] ? 'Active · ' . e(iv_date($d['last_login_at'])) : 'Password set') . '</span>';
}

/** <select> of departments grouped by college. */
function iv_dept_select(int $org, string $name, int $sel = 0, string $none = '', array $attrs = []): string
{
    $h = '<select name="' . e($name) . '"' . (isset($attrs['required']) ? ' required' : '') . '>';
    if ($none !== '') {
        $h .= '<option value="0">' . e($none) . '</option>';
    }
    $group = null;
    foreach (iv_departments($org) as $d) {
        if ($group !== $d['college_name']) {
            $h .= ($group !== null ? '</optgroup>' : '') . '<optgroup label="' . e($d['college_name']) . '">';
            $group = $d['college_name'];
        }
        $h .= '<option value="' . (int) $d['id'] . '"' . ((int) $d['id'] === $sel ? ' selected' : '') . '>' . e($d['name'] . ($d['branch'] ? ' (' . $d['branch'] . ')' : '')) . '</option>';
    }
    return $h . ($group !== null ? '</optgroup>' : '') . '</select>';
}

function iv_college_select(int $org, string $name, int $sel = 0, string $none = 'Any college'): string
{
    $h = '<select name="' . e($name) . '"><option value="0">' . e($none) . '</option>';
    foreach (iv_colleges($org) as $c) {
        $h .= '<option value="' . (int) $c['id'] . '"' . ((int) $c['id'] === $sel ? ' selected' : '') . '>' . e(($c['code'] ? $c['code'] . ' · ' : '') . $c['name']) . '</option>';
    }
    return $h . '</select>';
}

function iv_round_select(int $org, int $sel, string $name = 'round'): string
{
    $h = '<select name="' . e($name) . '" onchange="this.form.submit()">';
    foreach (iv_rounds($org) as $r) {
        $h .= '<option value="' . (int) $r['id'] . '"' . ((int) $r['id'] === $sel ? ' selected' : '') . '>' . e($r['name'] . ($r['status'] !== 'live' ? ' (' . $r['status'] . ')' : '')) . '</option>';
    }
    return $h . '</select>';
}

/** The round a page shows: ?round=, else the current one. */
function iv_page_round(int $org): ?array
{
    $id = (int) ($_GET['round'] ?? 0);
    return ($id ? iv_round($org, $id) : null) ?? iv_round_current($org);
}

function iv_hidden_org(): string
{
    return !empty($GLOBALS['iv_viewer']['is_admin']) ? '<input type="hidden" name="org" value="' . (int) $GLOBALS['iv_org'] . '">' : '';
}

/**
 * What an import did, adding up to the rows read: new students, rows that were
 * a student already on the list (the same person registering again, filed
 * once), and rows skipped with a reason.
 */
function iv_import_summary(int $rows, array $r, string $what): string
{
    return fabo_thousands($rows) . ' ' . $what . ' read = ' . fabo_thousands((int) ($r['students'] ?? 0)) . ' students + '
         . fabo_thousands((int) ($r['repeats'] ?? 0)) . ' rows that were the same student registering again (kept once) + '
         . fabo_thousands(count($r['skipped'])) . ' skipped. ' . fabo_thousands((int) $r['added']) . ' of the students were new to the list.'
         . ((int) ($r['repeats'] ?? 0) ? ' The repeat rows can be downloaded from the Students page.' : '');
}

function iv_skipped_html(array $skipped): string
{
    if (!$skipped) {
        return '';
    }
    $h = '<details class="iv-skipped"><summary>' . count($skipped) . ' row' . (count($skipped) === 1 ? '' : 's') . ' skipped — see why</summary><ul>';
    foreach (array_slice($skipped, 0, 200) as [$line, $why]) {
        $h .= '<li>Row ' . (int) $line . ': ' . e($why) . '</li>';
    }
    return $h . (count($skipped) > 200 ? '<li>… and ' . (count($skipped) - 200) . ' more.</li>' : '') . '</ul></details>';
}

/* ================================================================== main */

function iv_main(array $viewer): void
{
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store, private');
    iv_install();
    $grant = iv_grant($viewer);
    if ($grant === 'none') {
        fabo_deny('You do not have access to Startup Discovery.', 403);
    }
    $org = iv_org($viewer);
    $GLOBALS['iv_viewer'] = $viewer;
    $GLOBALS['iv_grant'] = $grant;
    $GLOBALS['iv_org'] = $org;
    fabo_session();

    $page = (string) ($_GET['p'] ?? 'overview');
    if (!isset(iv_desk_pages()[$page])) {
        $page = 'overview';
    }
    if (($_GET['csv'] ?? '') !== '') {
        iv_csv($org, (string) $_GET['csv']);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        fabo_csrf_check();
        if (!iv_can_edit()) {
            fabo_deny('View only: you can read Startup Discovery but not change it.', 403);
        }
        iv_post($org, $page);
    }
    // A student opened in the panel over the Students list: their profile alone, no page around it.
    if (in_array($page, ['student', 'dashboard'], true) && !empty($_GET['embed'])) {
        header('Content-Type: text/html; charset=utf-8');
        $page === 'student' ? iv_student_panel($org) : iv_dashboard_panel($org);
        exit;
    }

    fabo_head($viewer, iv_desk_pages()[$page] . ' · Startup Discovery', 'iv-' . $page);
    echo '<link rel="stylesheet" href="' . e(fabo_asset(fabo_root_prefix() . 'assets/ideas.css')) . '">';
    echo '<main class="wrap-page iv' . (iv_can_edit() ? '' : ' iv-ro') . '" id="main">';
    if ($page !== 'student') {          // a student's page has its own header
        iv_hero($page, iv_org_name($org));
    }
    $flash = $_SESSION['iv_flash'] ?? null;
    unset($_SESSION['iv_flash']);
    if ($flash) {
        echo '<div class="iv-flash ' . e($flash[0]) . '" role="status">' . e($flash[1]) . ($flash[2] ?? '') . '</div>';
    }
    if (!iv_can_edit()) {
        echo '<div class="iv-flash info"><strong>View only.</strong> You can read everything here; changes are made by the owner.</div>';
    }
    ('iv_page_' . $page)($org);
    echo '</main>';
    echo '<script src="' . e(fabo_asset(fabo_root_prefix() . 'assets/ideas.js')) . '" defer></script>';
    fabo_foot();
}

function iv_hero(string $page, string $orgName): void
{
    $lead = [
        'overview' => 'Heads of Department validate their students\' ideas with your Gem and send their top picks into one pipeline, where you form the teams.',
        'dashboards' => 'One dashboard per department, for its Head. Add them one at a time or upload a sheet of many, then send their sign-in links.',
        'dashboard' => 'This department\'s Head, their AI allowance, the form they share, and the ideas in it.',
        'students' => 'Every student once, however many times they were imported. Matched on roll number / PIN, then email, then phone.',
        'student' => 'Details, department, ideas and team.',
        'rounds' => 'When students may submit, which Gem validates their ideas, how many AI checks an idea gets, and how many ideas each department sends.',
        'gems' => 'The AI set-ups that score the ideas: each Gem\'s role, its weighted rubric, and the questions every department\'s form asks.',
        'ideas' => 'Every idea of a round, with its department\'s decision and AI score.',
        'pipeline' => 'The ideas Heads of Department sent forward, in one pipeline. Open one to assign its team.',
        'idea' => 'What the student wrote, the AI checks, the HoD\'s decision, and the team.',
        'import' => 'Bring in the students already registered in Incubation Management, or upload a sheet.',
        'settings' => 'The Head of Department sign-in address, colleges and schools, and who changed what.',
    ][$page] ?? '';
    echo '<header class="iv-hero"><div><span class="iv-eyebrow">Startup Discovery' . ($orgName !== '' ? ' · ' . e($orgName) : '') . '</span>'
       . '<h1>' . e($page === 'overview' ? 'Startup Discovery' : iv_desk_pages()[$page]) . '</h1><p>' . e($lead) . '</p></div>';
    if (!empty($GLOBALS['iv_viewer']['is_admin'])) {
        $keep = in_array($page, ['dashboard', 'student', 'idea'], true) ? ['dashboard' => 'dashboards', 'student' => 'students', 'idea' => 'ideas'][$page] : $page;
        echo '<form method="get" class="iv-org"><input type="hidden" name="p" value="' . e($keep) . '"><label>Organisation '
           . '<select name="org" onchange="this.form.submit()">';
        foreach (iv_orgs() as $id => $n) {
            echo '<option value="' . $id . '"' . ($id === $GLOBALS['iv_org'] ? ' selected' : '') . '>' . e($n) . '</option>';
        }
        echo '</select></label></form>';
    }
    echo '</header>';
}

/** The Students list's own filters out of a query string (search, college, page), nothing else. */
function iv_list_query(string $qs): array
{
    parse_str($qs, $q);
    return array_filter(['q' => mb_substr((string) ($q['q'] ?? ''), 0, 120), 'college' => (int) ($q['college'] ?? 0) ?: null, 'pg' => (int) ($q['pg'] ?? 0) > 1 ? (int) $q['pg'] : null],
                        static fn($v) => $v !== null && $v !== '');
}

/* ================================================================== POST */

function iv_post(int $org, string $page): void
{
    $a = (string) ($_POST['a'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $actor = iv_actor();
    $back = [$page, array_filter(['id' => (int) ($_GET['id'] ?? 0) ?: null, 'round' => (int) ($_GET['round'] ?? 0) ?: null])];
    try {
        switch ($a) {
            /* --------------------------------------------- dashboards */
            case 'dash-add':
                $college = (int) ($_POST['college_id'] ?? 0);
                if (!$college) {
                    [$code, $cname] = [iv_clean($_POST['college_code'] ?? '', 20), iv_clean($_POST['college_name'] ?? '', 190)];
                    if ($cname === '') {
                        throw new InvalidArgumentException('Pick the college or school, or type a new one\'s name.');
                    }
                    $college = iv_college_find($org, $code, $cname, (string) ($_POST['district'] ?? ''), true, (string) ($_POST['kind'] ?? 'College'));
                }
                $dept = iv_tx(static function () use ($org, $college) {
                    $d = iv_department_save($org, $college, (string) ($_POST['dept_name'] ?? ''), (string) ($_POST['branch'] ?? ''));
                    if (trim((string) ($_POST['hod_email'] ?? '')) !== '' || trim((string) ($_POST['hod_name'] ?? '')) !== '') {
                        iv_hod_save($org, $d, ['name' => $_POST['hod_name'] ?? '', 'email' => $_POST['hod_email'] ?? '', 'phone' => $_POST['hod_phone'] ?? '',
                                               'ai_limit' => $_POST['ai_limit'] ?? '']);
                    }
                    return $d;
                });
                $extra = '';
                $h = iv_hod_for_dept($org, $dept);
                if ($h && !empty($_POST['invite'])) {
                    [$ok, $words, $link] = iv_invite_send($org, (int) $h['id']);
                    $extra = ' ' . e($words) . ($ok ? '' : iv_copy($link));
                }
                iv_audit($org, $actor, 'dashboard_add', 'department #' . $dept);
                iv_flash('ok', 'Dashboard created.', $extra);
                iv_go('dashboard', ['id' => $dept]);

            case 'dash-bulk':
                $f = $_FILES['file'] ?? null;
                if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) {
                    throw new InvalidArgumentException('Choose a .csv or .xlsx file to upload.');
                }
                [$head, $rows] = iv_read_table((string) $f['tmp_name'], (string) $f['name']);
                [$cols, $recs] = iv_table_records($head, $rows, 'dashboards');
                if (!array_intersect(['college', 'college_code'], $cols) || !array_intersect(['department', 'branch'], $cols)) {
                    throw new InvalidArgumentException('The first row must name the columns: CODE, POLYTECHNIC, BRANCH, NAME OF THE HEAD OF SECTION, CONTACT NUMBER, EMAIL ID. Download the template to see them.');
                }
                @set_time_limit(600);
                $r = iv_dashboards_import($org, $recs, !empty($_POST['invite']), 2, (string) ($_POST['kind'] ?? 'College'));
                iv_audit($org, $actor, 'dashboard_bulk', count($recs) . ' rows: ' . $r['depts'] . ' new departments, ' . $r['hods'] . ' HoDs');
                iv_flash($r['skipped'] && !$r['hods'] && !$r['depts'] ? 'err' : 'ok',
                         $r['colleges'] . ' new college' . ($r['colleges'] === 1 ? '' : 's') . ', ' . $r['depts'] . ' new department dashboard' . ($r['depts'] === 1 ? '' : 's') . ', '
                         . $r['hods'] . ' Head' . ($r['hods'] === 1 ? '' : 's') . ' of Department set' . (!empty($_POST['invite']) ? ', ' . $r['invited'] . ' sign-in link' . ($r['invited'] === 1 ? '' : 's') . ' sent' : '') . '.',
                         iv_skipped_html($r['skipped']));
                iv_go('dashboards');

            case 'invite-all':
                $sent = 0;
                $fail = 0;
                @set_time_limit(600);
                foreach (fabo_all('SELECT id FROM iv_hods WHERE org_id = ? AND status = "active" AND pass_hash IS NULL AND (invited_at IS NULL OR invited_at < ?)', 'is', [$org, iv_now(-86400)]) as $h) {
                    [$ok] = iv_invite_send($org, (int) $h['id']);
                    $ok ? $sent++ : $fail++;
                }
                iv_audit($org, $actor, 'invite_all', $sent . ' sent');
                iv_flash($fail && !$sent ? 'err' : 'ok', $sent . ' sign-in link' . ($sent === 1 ? '' : 's') . ' sent' . ($fail ? ', ' . $fail . ' could not be sent' : '') . '. Heads invited in the last day were left alone.');
                iv_go('dashboards');

            case 'dept-save':
                $d = iv_department($org, $id);
                if (!$d) {
                    throw new InvalidArgumentException('That department is not in this organisation.');
                }
                iv_department_save($org, (int) ($_POST['college_id'] ?? $d['college_id']), (string) ($_POST['dept_name'] ?? ''), (string) ($_POST['branch'] ?? ''), $id);
                iv_audit($org, $actor, 'dept_save', 'department #' . $id);
                iv_flash('ok', 'Department saved.');
                iv_go('dashboard', ['id' => $id]);

            case 'dept-delete':
                iv_department_delete($org, $id);
                iv_audit($org, $actor, 'dept_delete', 'department #' . $id);
                iv_flash('ok', 'Department and its dashboard removed.');
                iv_go('dashboards');

            case 'hod-save':
                iv_hod_save($org, $id, ['name' => $_POST['hod_name'] ?? '', 'email' => $_POST['hod_email'] ?? '', 'phone' => $_POST['hod_phone'] ?? '', 'ai_limit' => $_POST['ai_limit'] ?? '']);
                iv_audit($org, $actor, 'hod_save', 'department #' . $id);
                iv_flash('ok', 'Head of Department saved.');
                iv_go('dashboard', ['id' => $id]);

            case 'hod-invite':
                $h = iv_hod_for_dept($org, $id);
                if (!$h) {
                    throw new InvalidArgumentException('Add the Head of Department first.');
                }
                [$ok, $words, $link] = iv_invite_send($org, (int) $h['id'], $h['pass_hash'] !== null);
                iv_audit($org, $actor, 'hod_invite', $h['email']);
                iv_flash($ok ? 'ok' : 'err', $words, $ok ? '' : '<div>Copy the link and send it yourself:</div>' . iv_copy($link));
                if (isset($_POST['back_q'])) {          // from the panel over the list: back to the list, panel open
                    iv_go('dashboards', iv_dash_query((string) $_POST['back_q']) + ['open' => $id]);
                }
                iv_go('dashboard', ['id' => $id]);

            case 'hod-link':
                $h = iv_hod_for_dept($org, $id);
                if (!$h) {
                    throw new InvalidArgumentException('Add the Head of Department first.');
                }
                $link = iv_invite_make($org, (int) $h['id']);
                iv_audit($org, $actor, 'hod_link', $h['email']);
                iv_flash('ok', 'A new sign-in link for ' . $h['name'] . ' (works for ' . IV_INVITE_DAYS . ' days; any earlier link stops working):', iv_copy($link));
                iv_go('dashboard', ['id' => $id]);

            case 'hod-status':
                $h = iv_hod_for_dept($org, $id);
                if ($h) {
                    iv_hod_status($org, (int) $h['id'], (string) ($_POST['status'] ?? 'active'));
                    iv_audit($org, $actor, 'hod_' . ($_POST['status'] ?? 'active'), $h['email']);
                }
                iv_flash('ok', ($_POST['status'] ?? '') === 'suspended' ? 'Suspended: they are signed out and cannot sign in.' : 'Active again.');
                iv_go('dashboard', ['id' => $id]);

            case 'window-set':
                $closes = iv_dt($_POST['closes_at'] ?? '');
                if (empty($_POST['clear']) && !$closes) {
                    throw new InvalidArgumentException('Set the new closing time.');
                }
                iv_dept_window_set($org, (int) ($_POST['round_id'] ?? 0), $id, empty($_POST['clear']) ? $closes : null);
                iv_audit($org, $actor, 'window_set', 'department #' . $id . ' ' . ($closes ?? 'cleared'));
                iv_flash('ok', empty($_POST['clear']) ? 'This department may now submit until ' . iv_when($closes) . '.' : 'This department follows the round\'s deadline again.');
                iv_go('dashboard', ['id' => $id]);

            case 'return-dept':
                $n = iv_return_dept($org, (int) ($_POST['round_id'] ?? 0), $id, $actor);
                iv_audit($org, $actor, 'return_dept', 'department #' . $id . ': ' . $n . ' ideas');
                iv_flash('ok', $n . ' idea' . ($n === 1 ? '' : 's') . ' handed back to the Head of Department, who can change the picks and send them again.');
                iv_go('dashboard', ['id' => $id]);

            /* ----------------------------------------------- students */
            case 'student-add':
            case 'student-save':
                // The department is not on the form: it stays, unless the student moved to another college.
                $keepDept = (int) ($_POST['department_id'] ?? 0);
                $postCollege = (int) ($_POST['college_id'] ?? 0);
                if ($keepDept && ($kd = iv_department($org, $keepDept)) && $postCollege && (int) $kd['college_id'] !== $postCollege) {
                    $keepDept = 0;
                }
                [$sid, $how] = iv_student_save($org, ['name' => $_POST['name'] ?? '', 'roll_no' => $_POST['roll_no'] ?? '', 'email' => $_POST['email'] ?? '',
                                                      'alt_email' => $_POST['alt_email'] ?? '', 'phone' => $_POST['phone'] ?? '', 'year_of_study' => $_POST['year'] ?? '',
                                                      'district' => $_POST['district'] ?? '', 'notes' => $_POST['notes'] ?? '',
                                                      'department_id' => $keepDept, 'college_id' => $postCollege],
                                               $a === 'student-save' ? $id : null, 'manual');
                iv_audit($org, $actor, 'student_' . $how, 'student #' . $sid);
                iv_flash('ok', $how === 'added' ? 'Student added.' : ($how === 'same' ? 'That student is already here, with the same details.' : 'Student updated' . ($a === 'student-add' ? ' (already here: matched on roll number, email or phone, so no second record was made)' : '') . '.'));
                // From the Students list (its panel or its Add dialog): back to the same list, the student open in the panel.
                if (isset($_POST['back_q'])) {
                    iv_go('students', iv_list_query((string) $_POST['back_q']) + ['open' => $sid]);
                }
                iv_go('student', ['id' => $sid]);

            case 'student-delete':
                iv_student_delete($org, $id);
                iv_audit($org, $actor, 'student_delete', 'student #' . $id);
                iv_flash('ok', 'Student removed.');
                iv_go('students');

            case 'students-clear':
                if (trim((string) ($_POST['confirm'] ?? '')) !== 'REMOVE') {
                    throw new InvalidArgumentException('Type REMOVE to confirm.');
                }
                $n = iv_students_clear($org);
                $kept = (int) (fabo_one('SELECT COUNT(*) n FROM iv_students WHERE org_id = ?', 'i', [$org])['n'] ?? 0);
                iv_audit($org, $actor, 'students_clear', $n . ' removed, ' . $kept . ' kept');
                iv_flash('ok', fabo_thousands($n) . ' students removed' . ($kept ? ' (' . fabo_thousands($kept) . ' with ideas or on teams kept)' : '') . '. Upload the sheet again with “Upload many”.');
                iv_go('students');

            case 'student-bulk':
                $f = $_FILES['file'] ?? null;
                if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) {
                    throw new InvalidArgumentException('Choose a .csv or .xlsx file to upload.');
                }
                [$head, $rows] = iv_read_table((string) $f['tmp_name'], (string) $f['name']);
                [$cols, $recs] = iv_table_records($head, $rows);
                if (!in_array('name', $cols, true) || !array_intersect(['roll_no', 'email', 'phone'], $cols)) {
                    throw new InvalidArgumentException('The first row must name the columns: at least Name, and one of Roll No / PIN, Email or Phone. Download the template to see them.');
                }
                @set_time_limit(600);
                $GLOBALS['iv_actor_name'] = $actor;
                $r = iv_students_import($org, $recs, ['make' => !empty($_POST['make']), 'college' => (int) ($_POST['college_id'] ?? 0), 'label' => 'Upload: ' . mb_substr((string) $f['name'], 0, 100),
                                                      'dept' => (int) ($_POST['department_id'] ?? 0), 'source' => 'upload']);
                iv_audit($org, $actor, 'student_bulk', count($recs) . ' rows: ' . $r['added'] . ' added, ' . $r['updated'] . ' updated, ' . $r['same'] . ' same, ' . count($r['skipped']) . ' skipped');
                iv_flash('ok', iv_import_summary(count($recs), $r, 'rows'), iv_skipped_html($r['skipped']));
                iv_go('students');

            case 'inc-import':
                $form = (int) ($_POST['form_id'] ?? 0);
                $map = array_map('strval', (array) ($_POST['map'] ?? []));
                $GLOBALS['iv_actor_name'] = $actor;
                $r = iv_inc_import($org, $form, $map, ['make' => !empty($_POST['make']), 'kind' => (string) ($_POST['kind'] ?? 'Polytechnic')]);
                iv_audit($org, $actor, 'inc_import', 'form #' . $form . ': ' . $r['added'] . ' added, ' . $r['updated'] . ' updated');
                iv_flash('ok', iv_import_summary((int) $r['rows'], $r, 'applications'), iv_skipped_html($r['skipped']));
                iv_go('students');

            /* ------------------------------------------------- rounds */
            case 'round-save':
                $rid = iv_round_save($org, $_POST, $id ?: null);
                iv_audit($org, $actor, $id ? 'round_edit' : 'round_add', 'round #' . $rid);
                iv_flash('ok', $id ? 'Round saved.' : 'Round created. Heads of Department now see it on their dashboards.');
                iv_go('rounds', ['round' => $rid]);

            case 'round-delete':
                iv_round_delete($org, $id);
                iv_audit($org, $actor, 'round_delete', 'round #' . $id);
                iv_flash('ok', 'Round removed.');
                iv_go('rounds');

            /* ---------------------------------------------------- gems */
            case 'gem-start':
                iv_gems_prepare($org, $actor);
                iv_audit($org, $actor, 'gem_start', 'Gem 1');
                iv_flash('ok', 'Gem 1 is ready. Change anything you like, then pick it on Rounds & timing.');
                iv_go('gems');

            case 'gem-save':
                try {
                    $gid = iv_gem_save($org, $id ?: null, $_POST, $actor);
                } catch (InvalidArgumentException $e) {
                    $_SESSION['iv_gem_draft'] = array_intersect_key($_POST, array_flip(['name', 'instructions', 'criteria', 'q', 'active'])) + ['_id' => $id];
                    iv_flash('err', $e->getMessage());
                    iv_go('gems', ['id' => $id ?: 'new']);
                }
                $synced = !empty($_POST['sync']) ? iv_gem_sync_forms($org, $gid) : 0;
                iv_audit($org, $actor, $id ? 'gem_edit' : 'gem_add', 'gem #' . $gid . ($synced ? ', ' . $synced . ' forms updated' : ''));
                iv_flash('ok', ($id ? 'Gem saved.' : 'Gem created. Pick it on Rounds & timing.') . ($synced ? ' ' . $synced . ' department form' . ($synced === 1 ? '' : 's') . ' now ask the new questions.' : ''));
                iv_go('gems', ['id' => $gid]);

            case 'gem-copy':
                $gid = iv_gem_copy($org, $id, $actor);
                iv_audit($org, $actor, 'gem_copy', 'gem #' . $id . ' -> #' . $gid);
                iv_flash('ok', 'Copy made, switched off. Change it, then switch it on.');
                iv_go('gems', ['id' => $gid]);

            case 'gem-sync':
                $n = iv_gem_sync_forms($org, $id);
                iv_audit($org, $actor, 'gem_sync', 'gem #' . $id . ', ' . $n . ' forms');
                iv_flash('ok', $n . ' department form' . ($n === 1 ? '' : 's') . ' now ask this Gem\'s current questions.');
                iv_go('gems', ['id' => $id]);

            case 'gem-try':
                @set_time_limit(120);
                $try = array_intersect_key($_POST, array_flip(['try_title', 'try']));
                $_SESSION['iv_gem_tried'] = ['gem' => $id, 'post' => $try, 'result' => null];
                try {
                    $_SESSION['iv_gem_tried']['result'] = iv_gem_try($org, $id, $_POST);
                } catch (InvalidArgumentException $e) {
                    iv_flash('err', $e->getMessage());
                }
                header('Location: ' . iv_url('gems', ['id' => $id]) . '#try', true, 303);
                exit;

            /* ------------------------------------------------ pipeline */
            case 'stage':
                iv_stage_set($org, $id, (string) ($_POST['stage'] ?? ''), (string) ($_POST['note'] ?? ''), $actor);
                iv_flash('ok', 'Saved.');
                if (($_POST['back'] ?? '') === 'pipeline') {
                    iv_go('pipeline', array_filter(['round' => (int) ($_POST['round'] ?? 0) ?: null]));
                }
                iv_go('idea', ['id' => $id]);

            case 'verify':
                // The owner's own AI check of an idea: outside the HoD's limit, scored here, never by the HoD's tool.
                @set_time_limit(300);
                $idea = iv_idea($org, $id);
                if (!$idea) {
                    throw new InvalidArgumentException('That idea is not here.');
                }
                iv_flash('ok', iv_run_portal($org, $idea, iv_round($org, (int) $idea['round_id']), null, $actor, true));
                iv_go('idea', ['id' => $id]);

            case 'team-add':
                iv_team_add($org, $id, (int) ($_POST['student_id'] ?? 0), $actor);
                iv_flash('ok', 'Added to the team.');
                iv_go('idea', ['id' => $id]);

            case 'team-remove':
                iv_team_remove($org, $id, (int) ($_POST['student_id'] ?? 0), $actor);
                iv_flash('ok', 'Taken off the team.');
                iv_go('idea', ['id' => $id]);

            /* ------------------------------------------------ colleges */
            case 'college-save':
                iv_college_save($org, $_POST, $id ?: null);
                iv_flash('ok', 'College saved.');
                iv_go('settings');

            case 'college-delete':
                iv_college_delete($org, $id);
                iv_flash('ok', 'College removed.');
                iv_go('settings');
        }
    } catch (InvalidArgumentException $e) {
        iv_flash('err', $e->getMessage());
    } catch (Throwable $e) {
        error_log('FABO iv post ' . $a . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        iv_flash('err', 'That could not be saved. Please try again.');
    }
    iv_go($back[0], $back[1]);
}

/* ============================================================== overview */

function iv_page_overview(int $org): void
{
    $round = iv_page_round($org);
    $t = iv_totals($org, $round ? (int) $round['id'] : null);
    echo '<section class="iv-kpis">'
       . iv_kpi('Colleges & schools', $t['colleges'], '', iv_url('settings'))
       . iv_kpi('HoD dashboards', $t['depts'], '', iv_url('dashboards'))
       . iv_kpi('HoDs signed up', $t['hods_ready'] . ' / ' . $t['hods'], $t['hods'] && $t['hods_ready'] < $t['hods'] ? 'warn' : '', iv_url('dashboards'))
       . iv_kpi('Students', $t['students'], '', iv_url('students'))
       . iv_kpi('Ideas this round', $t['ideas'], '', iv_url('ideas'))
       . iv_kpi('Validated', $t['validated'], 'good')
       . iv_kpi('In the pipeline', $t['forwarded'], 'accent', iv_url('pipeline'))
       . '</section>';

    if (!$t['depts']) {
        echo '<section class="iv-card iv-start"><h2>Getting started</h2><ol>'
           . '<li><a href="' . e(iv_url('dashboards')) . '">Create the HoD dashboards</a> — one per department, singly or from a sheet.</li>'
           . '<li><a href="' . e(iv_url('import')) . '">Bring in the students</a> from Incubation Management, or upload them.</li>'
           . '<li><a href="' . e(iv_url('rounds')) . '">Create a round</a>: the submission window, the Gem, AI checks per idea (2) and top ideas per department (3).</li>'
           . '<li>Send the Heads their sign-in links. They share the form, validate ideas, and send their top picks to your <a href="' . e(iv_url('pipeline')) . '">pipeline</a>.</li></ol></section>';
    }

    echo '<div class="iv-grid2">';
    echo '<section class="iv-card"><div class="iv-card-h"><h2>Round</h2>';
    if ($round) {
        echo '<form method="get">' . iv_hidden_org() . '<input type="hidden" name="p" value="overview">' . iv_round_select($org, (int) $round['id']) . '</form>';
    }
    echo '</div>';
    if (!$round) {
        echo '<p class="muted">No round yet. <a href="' . e(iv_url('rounds')) . '">Create the first one</a> to open idea submission.</p>';
    } else {
        $gem = $round['gem_id'] ? iv_gem($org, (int) $round['gem_id']) : null;
        $now = iv_now();
        $state = $round['status'] !== 'live' ? ucfirst($round['status']) : ($now < $round['opens_at'] ? 'Opens soon' : ($now < $round['closes_at'] ? 'Open for ideas' : 'Submission closed'));
        echo '<dl class="iv-dl"><dt>Status</dt><dd><strong>' . e($state) . '</strong></dd>'
           . '<dt>Submission</dt><dd>' . e(iv_when($round['opens_at'])) . ' → ' . e(iv_when($round['closes_at'])) . '</dd>'
           . '<dt>HoD review by</dt><dd>' . e($round['review_by'] ? iv_when($round['review_by']) : '—') . '</dd>'
           . '<dt>Gem</dt><dd>' . ($gem ? e($gem['name']) . ' <span class="muted">(' . count($gem['criteria']) . ' criteria, ' . e(iv_score($gem['max'], $gem['max'])) . ')</span>' : '<span class="iv-warn">None assigned</span>') . '</dd>'
           . '<dt>AI checks per idea</dt><dd>' . (int) $round['ai_limit'] . '</dd>'
           . '<dt>Top ideas per department</dt><dd>' . (int) $round['top_n'] . '</dd></dl>'
           . '<a class="btn btn-sm" href="' . e(iv_url('rounds', ['round' => (int) $round['id']])) . '">Edit round</a>';
    }
    echo '</section>';
    echo '<section class="iv-card"><h2>Head of Department sign-in</h2><p class="muted">Heads sign in here with their own email and password — never a staff login. Each sees only their own department.</p>'
       . iv_copy(iv_portal_url($org)) . '</section>';
    echo '</div>';

    if ($round && $t['depts']) {
        $prog = iv_dept_progress($org, (int) $round['id']);
        $depts = iv_departments($org);
        usort($depts, static fn($a, $b) => (($prog[(int) $b['id']]['ideas'] ?? 0) <=> ($prog[(int) $a['id']]['ideas'] ?? 0)) ?: strcmp($a['college_name'] . $a['name'], $b['college_name'] . $b['name']));
        $sent = count(array_filter($prog, static fn($p) => $p['forwarded'] > 0));
        echo '<section class="iv-card"><div class="iv-card-h"><h2>Departments this round</h2><span class="muted">' . $sent . ' of ' . count($depts) . ' have sent their top ideas</span></div>'
           . '<div class="iv-tablewrap"><table class="iv-table"><thead><tr><th>Department</th><th>College</th><th>Head</th><th class="n">Ideas</th><th class="n">AI-checked</th><th class="n">Validated</th><th class="n">Sent</th></tr></thead><tbody>';
        foreach (array_slice($depts, 0, 60) as $d) {
            $p = $prog[(int) $d['id']] ?? ['ideas' => 0, 'validated' => 0, 'forwarded' => 0, 'checked' => 0];
            echo '<tr><td><a href="' . e(iv_url('dashboard', ['id' => (int) $d['id']])) . '">' . e($d['name']) . '</a></td><td>' . e($d['college_name']) . '</td><td>' . iv_hod_state($d) . '</td>'
               . '<td class="n">' . (int) $p['ideas'] . '</td><td class="n">' . (int) $p['checked'] . '</td><td class="n">' . (int) $p['validated'] . '</td><td class="n">'
               . ($p['forwarded'] ? '<span class="iv-pill g-forwarded">' . (int) $p['forwarded'] . '</span>' : '—') . '</td></tr>';
        }
        echo '</tbody></table></div>' . (count($depts) > 60 ? '<p class="muted">Showing the 60 busiest of ' . count($depts) . '. <a href="' . e(iv_url('dashboards')) . '">See all</a>.</p>' : '') . '</section>';
    }
}

/* ============================================================ dashboards */

function iv_page_dashboards(int $org): void
{
    $college = (int) ($_GET['college'] ?? 0);
    $state = (string) ($_GET['state'] ?? '');
    $q = trim((string) ($_GET['q'] ?? ''));
    $round = iv_round_current($org);
    $prog = $round ? iv_dept_progress($org, (int) $round['id']) : [];
    $all = iv_departments($org, $college ?: null);
    $rows = array_values(array_filter($all, static function ($d) use ($state, $q) {
        if ($state === 'nohod' && $d['hod_id']) {
            return false;
        }
        if ($state === 'pending' && (!$d['hod_id'] || (int) $d['hod_ready'])) {
            return false;
        }
        if ($state === 'ready' && !(int) $d['hod_ready']) {
            return false;
        }
        if ($q !== '' && stripos($d['name'] . ' ' . $d['college_name'] . ' ' . $d['hod_name'] . ' ' . $d['hod_email'] . ' ' . $d['branch'], $q) === false) {
            return false;
        }
        return true;
    }));
    $pending = (int) (fabo_one('SELECT COUNT(*) n FROM iv_hods WHERE org_id = ? AND status = "active" AND pass_hash IS NULL', 'i', [$org])['n'] ?? 0);
    $per = 50;
    $total = count($rows);
    $pages = max(1, (int) ceil($total / $per));
    $pg = min($pages, max(1, (int) ($_GET['pg'] ?? 1)));
    $pageRows = array_slice($rows, ($pg - 1) * $per, $per);
    $fq = array_filter(['q' => $q, 'college' => $college ?: null, 'state' => $state]);
    $backQ = http_build_query($fq + ($pg > 1 ? ['pg' => $pg] : []));

    if (iv_can_edit()) {
        echo '<div class="iv-actions">'
           . '<button type="button" class="btn btn-primary" data-open="dlgDash">+ New dashboard</button>'
           . '<button type="button" class="btn" data-open="dlgBulk">Upload many</button>'
           . ($pending ? '<form method="post" class="inline" data-confirm="Email a sign-in link to every Head who has not set a password yet?">' . fabo_csrf_field() . '<input type="hidden" name="a" value="invite-all"><button class="btn">Send sign-in links (' . $pending . ' waiting)</button></form>' : '')
           . '</div>';
        iv_dash_dialogs($org);
    }

    echo '<form method="get" class="iv-filters">' . iv_hidden_org() . '<input type="hidden" name="p" value="dashboards">'
       . '<input type="search" name="q" value="' . e($q) . '" placeholder="Search department, college, Head">'
       . iv_college_select($org, 'college', $college)
       . '<select name="state"><option value="">Any Head</option>'
       . '<option value="nohod"' . ($state === 'nohod' ? ' selected' : '') . '>No Head yet</option>'
       . '<option value="pending"' . ($state === 'pending' ? ' selected' : '') . '>Not signed up</option>'
       . '<option value="ready"' . ($state === 'ready' ? ' selected' : '') . '>Signed up</option></select>'
       . '<button class="btn">Filter</button></form>';

    $openId = (int) ($_GET['open'] ?? 0);
    echo '<section class="iv-card"><div class="iv-card-h"><h2>' . fabo_thousands($total) . ' dashboard' . ($total === 1 ? '' : 's') . ($pages > 1 ? ' <span class="muted sm">· showing ' . fabo_thousands(($pg - 1) * $per + 1) . '–' . fabo_thousands(min($total, $pg * $per)) . '</span>' : '') . '</h2>'
       . '<a class="btn btn-sm" href="' . e(iv_url('dashboards', ['csv' => 'dashboards'])) . '">Export</a></div>'
       . '<div class="iv-tablewrap"><table class="iv-table iv-click" data-panels'
       . ($openId ? ' data-open-id="' . $openId . '" data-open-panel="' . e(iv_url('dashboard', ['id' => $openId, 'embed' => 1, 'back_q' => $backQ])) . '"' : '')
       . '><thead><tr><th>Department</th><th>College</th><th>Head of Department</th><th>Sign-in</th><th class="n">AI / idea</th><th class="n">Ideas</th><th></th></tr></thead><tbody>';
    if (!$pageRows) {
        echo '<tr><td colspan="7" class="iv-empty">' . ($all ? 'Nothing matches.' : 'No dashboards yet. Create one, or upload a sheet of departments and their Heads.') . '</td></tr>';
    }
    foreach ($pageRows as $d) {
        $lim = $d['hod_ai_limit'] !== null ? (int) $d['hod_ai_limit'] . ' <span class="muted">own</span>' : ($round ? (int) $round['ai_limit'] : '—');
        $href = iv_url('dashboard', ['id' => (int) $d['id']]);
        $panel = iv_url('dashboard', ['id' => (int) $d['id'], 'embed' => 1, 'back_q' => $backQ]);
        echo '<tr data-href="' . e($href) . '" data-panel="' . e($panel) . '" data-id="' . (int) $d['id'] . '"><td><a href="' . e($href) . '" data-panel-link><strong>' . e($d['name']) . '</strong></a>' . ($d['branch'] ? ' <span class="iv-code">' . e($d['branch']) . '</span>' : '') . '</td>'
           . '<td>' . e($d['college_name']) . ($d['college_code'] ? ' <span class="muted">' . e($d['college_code']) . '</span>' : '') . '</td>'
           . '<td>' . ($d['hod_id'] ? e($d['hod_name']) . '<div class="muted sm">' . e($d['hod_email']) . '</div>' : '<span class="muted">—</span>') . '</td>'
           . '<td>' . iv_hod_state($d) . '</td><td class="n">' . $lim . '</td><td class="n">' . (int) ($prog[(int) $d['id']]['ideas'] ?? 0) . '</td>'
           . '<td class="act"><a class="btn btn-sm" href="' . e($href) . '" data-panel-link>View</a></td></tr>';
    }
    echo '</tbody></table></div>';
    echo iv_pager('dashboards', $fq, $pg, $pages);
    echo '</section>';
}

/** Page links: ← Previous, a few numbers around this one, Next →. */
function iv_pager(string $page, array $q, int $pg, int $pages): string
{
    if ($pages <= 1) {
        return '';
    }
    $link = static fn(int $n, string $label, string $cls = '') => '<a class="btn btn-sm' . $cls . '" href="' . e(iv_url($page, $q + ($n > 1 ? ['pg' => $n] : []))) . '"' . ($cls === ' on' ? ' aria-current="page"' : '') . '>' . $label . '</a>';
    $h = '<nav class="iv-pager" aria-label="Pages">' . ($pg > 1 ? $link($pg - 1, '← Previous') : '<span class="btn btn-sm" aria-disabled="true">← Previous</span>');
    $shown = array_unique(array_filter([1, $pg - 2, $pg - 1, $pg, $pg + 1, $pg + 2, $pages], static fn($n) => $n >= 1 && $n <= $pages));
    sort($shown);
    $prev = 0;
    foreach ($shown as $n) {
        if ($prev && $n > $prev + 1) {
            $h .= '<span class="iv-pager-gap">…</span>';
        }
        $h .= $link($n, (string) $n, $n === $pg ? ' on' : '');
        $prev = $n;
    }
    return $h . ($pg < $pages ? $link($pg + 1, 'Next →') : '<span class="btn btn-sm" aria-disabled="true">Next →</span>') . '</nav>';
}

/**
 * One HoD dashboard for the slide-in panel over the list: the department,
 * its Head and their sign-in, this round's progress and form link, its
 * students and latest ideas, with quick actions.
 */
function iv_dashboard_panel(int $org): void
{
    $d = iv_department($org, (int) ($_GET['id'] ?? 0));
    if (!$d) {
        http_response_code(404);
        echo '<div class="iv-pn-missing"><p>That dashboard is not here any more.</p></div>';
        return;
    }
    $id = (int) $d['id'];
    $h = iv_hod_for_dept($org, $id);
    $round = iv_round_current($org);
    $listQ = (string) ($_GET['back_q'] ?? '');
    $full = iv_url('dashboard', ['id' => $id]);
    $students = (int) (fabo_one('SELECT COUNT(*) n FROM iv_students WHERE org_id = ? AND department_id = ?', 'ii', [$org, $id])['n'] ?? 0);
    $dash = '<span class="muted">—</span>';
    $row = static fn(string $k, string $html) => '<dt>' . e($k) . '</dt><dd>' . ($html !== '' ? $html : $dash) . '</dd>';
    $state = iv_hod_state(['hod_id' => $h['id'] ?? null, 'hod_status' => $h['status'] ?? '', 'hod_ready' => $h && $h['pass_hash'] !== null ? 1 : 0,
                           'invited_at' => $h['invited_at'] ?? null, 'last_login_at' => $h['last_login_at'] ?? null]);

    echo '<div class="iv-pn-view" data-pn-title="' . e($d['name'] . ' · ' . $d['college_name']) . '" data-pn-full="' . e($full) . '">';
    echo '<section class="iv-card iv-phead"><div class="iv-phead-main"><span class="iv-av xl" aria-hidden="true">' . e($d['branch'] ?: mb_strtoupper(mb_substr($d['name'], 0, 1))) . '</span>'
       . '<div class="iv-phead-who"><span class="iv-eyebrow">HoD dashboard</span><h1>' . e($d['name']) . '</h1><div class="iv-chips">'
       . ($d['branch'] ? '<span class="iv-chip mono">' . e($d['branch']) . '</span>' : '')
       . '<span class="iv-chip">' . e(($d['college_code'] ? $d['college_code'] . ' · ' : '') . $d['college_name']) . '</span>'
       . ($d['district'] ? '<span class="iv-chip soft">' . e($d['district']) . '</span>' : '') . '</div></div></div>'
       . '<div class="iv-phead-act"><a class="btn btn-sm btn-primary" href="' . e($full) . '">Open dashboard</a></div></section>';

    echo '<section class="iv-card"><div class="iv-card-h"><h2>Head of Department</h2>' . $state . '</div>';
    if ($h) {
        echo '<dl class="iv-dl">' . $row('Name', e($h['name']))
           . $row('Email', '<a href="mailto:' . e($h['email']) . '">' . e($h['email']) . '</a>')
           . $row('Phone', $h['phone'] !== '' ? '<a href="tel:' . e($h['phone']) . '">' . e($h['phone']) . '</a>' : '')
           . $row('Last sign-in', $h['last_login_at'] ? e(iv_when($h['last_login_at'])) : '<span class="muted">Not yet</span>')
           . $row('AI checks per idea', e($h['ai_limit'] !== null ? (int) $h['ai_limit'] . ' (their own)' : ($round ? (int) $round['ai_limit'] . ' (the round’s)' : '—'))) . '</dl>';
        if (iv_can_edit()) {
            echo '<div class="iv-actions sm"><form method="post" action="' . e(iv_url('dashboards', iv_dash_query($listQ))) . '" class="inline">' . fabo_csrf_field()
               . '<input type="hidden" name="a" value="hod-invite"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="back_q" value="' . e($listQ) . '">'
               . '<button class="btn btn-sm">' . ($h['pass_hash'] !== null ? 'Email a password reset' : 'Email sign-in link') . '</button></form>'
               . '<a class="btn btn-sm" href="mailto:' . e($h['email']) . '">Email</a>' . ($h['phone'] !== '' ? '<a class="btn btn-sm" href="tel:' . e($h['phone']) . '">Call</a>' : '') . '</div>';
        }
    } else {
        echo '<p class="muted iv-quiet">No Head yet. <a href="' . e($full) . '">Add one on the dashboard</a>.</p>';
    }
    echo '</section>';

    if ($round) {
        $p = iv_dept_progress($org, (int) $round['id'])[$id] ?? ['ideas' => 0, 'checked' => 0, 'validated' => 0, 'forwarded' => 0];
        $form = iv_form_for($org, $round, $id);
        echo '<section class="iv-card"><div class="iv-card-h"><h2>' . e($round['name']) . '</h2><span class="muted sm">closes ' . e(iv_when(iv_closes_for($round, $id))) . '</span></div>'
           . '<div class="iv-minis">' . iv_mini('Ideas', (int) $p['ideas']) . iv_mini('AI-checked', (int) $p['checked']) . iv_mini('Validated', (int) $p['validated'], 'good') . iv_mini('Sent forward', (int) $p['forwarded'], 'accent') . '</div>'
           . '<p class="muted sm iv-pn-note">Idea form the Head shares with students</p>' . iv_copy(iv_form_url($org, $form['token'])) . '</section>';
        $ideas = array_slice(iv_ideas($org, ['round' => (int) $round['id'], 'dept' => $id, 'sort' => 'rank']), 0, 5);
        if ($ideas) {
            echo '<section class="iv-card"><h2>Latest ideas</h2><ul class="iv-list">';
            foreach ($ideas as $i) {
                echo '<li><a href="' . e(iv_url('idea', ['id' => (int) $i['id']])) . '"><strong>' . e($i['title']) . '</strong></a> <span class="muted">· ' . e($i['student_name']) . '</span> ' . iv_pill($i['status']) . '</li>';
            }
            echo '</ul></section>';
        }
    }
    echo '<section class="iv-card"><dl class="iv-dl">' . $row('Students in this dashboard', e(fabo_thousands($students))) . '</dl></section>';
    echo '</div>';
}

/** A small number tile inside a panel. */
function iv_mini(string $label, int $n, string $tone = ''): string
{
    return '<div class="iv-mini ' . e($tone) . '"><strong>' . fabo_thousands($n) . '</strong><span>' . e($label) . '</span></div>';
}

/** The HoD dashboards list's own filters out of a query string. */
function iv_dash_query(string $qs): array
{
    parse_str($qs, $q);
    return array_filter(['q' => mb_substr((string) ($q['q'] ?? ''), 0, 120), 'college' => (int) ($q['college'] ?? 0) ?: null,
                         'state' => in_array($q['state'] ?? '', ['nohod', 'pending', 'ready'], true) ? $q['state'] : null, 'pg' => (int) ($q['pg'] ?? 0) > 1 ? (int) $q['pg'] : null],
                        static fn($v) => $v !== null && $v !== '');
}

function iv_dash_dialogs(int $org): void
{
    $colleges = iv_colleges($org);
    echo '<dialog id="dlgDash" class="iv-dlg"><form method="post" class="iv-form">' . fabo_csrf_field() . '<input type="hidden" name="a" value="dash-add">'
       . '<h2>New HoD dashboard</h2><p class="muted">One department of one college, and its Head. The Head signs in with this email.</p>'
       . '<fieldset><legend>College or school</legend><div class="iv-fields">'
       . '<label class="field wide"><span>Existing</span><select name="college_id" data-newcollege><option value="0">— a new one (type it below) —</option>';
    foreach ($colleges as $c) {
        echo '<option value="' . (int) $c['id'] . '">' . e(($c['code'] ? $c['code'] . ' · ' : '') . $c['name']) . '</option>';
    }
    echo '</select></label>'
       . '<label class="field" data-nc><span>New college name</span><input name="college_name" maxlength="190"></label>'
       . '<label class="field" data-nc><span>Code</span><input name="college_code" maxlength="20" placeholder="e.g. 155"></label>'
       . '<label class="field" data-nc><span>District</span><input name="district" maxlength="80"></label>'
       . '<label class="field" data-nc><span>Type</span><select name="kind">';
    foreach (iv_college_kinds() as $k) {
        echo '<option>' . e($k) . '</option>';
    }
    echo '</select></label></div></fieldset>'
       . '<fieldset><legend>Department</legend><div class="iv-fields">'
       . '<label class="field"><span>Name</span><input name="dept_name" maxlength="160" placeholder="e.g. Computer Engineering"></label>'
       . '<label class="field"><span>Branch code</span><input name="branch" maxlength="12" placeholder="e.g. CM"><small>The letters in a student\'s PIN (24045-<b>CM</b>-042): students are placed by it.</small></label>'
       . '</div></fieldset>'
       . '<fieldset><legend>Head of Department</legend><div class="iv-fields">'
       . '<label class="field"><span>Name</span><input name="hod_name" maxlength="160"></label>'
       . '<label class="field"><span>Email</span><input type="email" name="hod_email" maxlength="160"></label>'
       . '<label class="field"><span>Phone</span><input name="hod_phone" maxlength="40"></label>'
       . '<label class="field"><span>AI checks per idea</span><input type="number" name="ai_limit" min="0" max="20" placeholder="Round\'s (2)"><small>Leave empty to follow the round.</small></label>'
       . '</div><label class="iv-check"><input type="checkbox" name="invite" value="1" checked> Email them their sign-in link now</label></fieldset>'
       . '<div class="iv-dlg-f"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Create dashboard</button></div></form></dialog>';

    echo '<dialog id="dlgBulk" class="iv-dlg"><form method="post" enctype="multipart/form-data" class="iv-form">' . fabo_csrf_field() . '<input type="hidden" name="a" value="dash-bulk">'
       . '<h2>Upload many dashboards</h2><p class="muted">A sheet with one row per Head of Section: <strong>CODE, POLYTECHNIC, BRANCH, NAME OF THE HEAD OF SECTION, CONTACT NUMBER, EMAIL ID</strong>. '
       . 'BRANCH may be the code in the PIN (CM, EC, EE, M, C…) or the name (Computer Engineering, DCME, ECE…). '
       . 'Polytechnics are matched on their code and departments on their branch, so they join the students already imported; uploading the same sheet twice changes nothing.</p>'
       . '<p><a href="' . e(iv_url('dashboards', ['csv' => 'dash-template'])) . '">Download the template</a></p>'
       . '<label class="field"><span>File (.csv or .xlsx)</span><input type="file" name="file" accept=".csv,.xlsx" required></label>'
       . '<label class="field"><span>New colleges are</span><select name="kind">';
    foreach (iv_college_kinds() as $k) {
        echo '<option>' . e($k) . '</option>';
    }
    echo '</select></label><label class="iv-check"><input type="checkbox" name="invite" value="1"> Email each new Head their sign-in link</label>'
       . '<div class="iv-dlg-f"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Upload</button></div></form></dialog>';
}

function iv_page_dashboard(int $org): void
{
    $id = (int) ($_GET['id'] ?? 0);
    $d = iv_department($org, $id);
    if (!$d) {
        echo '<section class="iv-card"><p>That dashboard is not here. <a href="' . e(iv_url('dashboards')) . '">Back to all dashboards</a>.</p></section>';
        return;
    }
    $h = iv_hod_for_dept($org, $id);
    $round = iv_page_round($org);
    echo '<p class="iv-crumb"><a href="' . e(iv_url('dashboards')) . '">HoD dashboards</a> › ' . e($d['college_name']) . '</p>';
    echo '<h2 class="iv-title">' . e($d['name']) . ($d['branch'] ? ' <span class="iv-code">' . e($d['branch']) . '</span>' : '') . '</h2>';
    $students = (int) (fabo_one('SELECT COUNT(*) n FROM iv_students WHERE org_id = ? AND department_id = ?', 'ii', [$org, $id])['n'] ?? 0);

    echo '<div class="iv-grid2">';
    // Head of Department
    echo '<section class="iv-card"><h2>Head of Department</h2>';
    if ($h) {
        $state = iv_hod_state(['hod_id' => $h['id'], 'hod_status' => $h['status'], 'hod_ready' => $h['pass_hash'] !== null ? 1 : 0, 'invited_at' => $h['invited_at'], 'last_login_at' => $h['last_login_at']]);
        echo '<p>' . $state . '</p>';
    }
    if (iv_can_edit()) {
        echo '<form method="post" class="iv-form">' . fabo_csrf_field() . '<input type="hidden" name="a" value="hod-save"><input type="hidden" name="id" value="' . $id . '"><div class="iv-fields">'
           . '<label class="field"><span>Name</span><input name="hod_name" required maxlength="160" value="' . e($h['name'] ?? '') . '"></label>'
           . '<label class="field"><span>Email (signs in with)</span><input type="email" name="hod_email" required maxlength="160" value="' . e($h['email'] ?? '') . '"></label>'
           . '<label class="field"><span>Phone</span><input name="hod_phone" maxlength="40" value="' . e($h['phone'] ?? '') . '"></label>'
           . '<label class="field"><span>AI checks per idea</span><input type="number" name="ai_limit" min="0" max="20" value="' . e($h && $h['ai_limit'] !== null ? (string) $h['ai_limit'] : '') . '" placeholder="Round\'s (' . ($round ? (int) $round['ai_limit'] : 2) . ')"><small>Empty follows the round. 0 switches AI off.</small></label>'
           . '</div><button class="btn btn-primary btn-sm">Save Head</button></form>';
        if ($h) {
            echo '<div class="iv-actions sm">'
               . '<form method="post" class="inline">' . fabo_csrf_field() . '<input type="hidden" name="a" value="hod-invite"><input type="hidden" name="id" value="' . $id . '"><button class="btn btn-sm">' . ($h['pass_hash'] !== null ? 'Email a password reset' : 'Email sign-in link') . '</button></form>'
               . '<form method="post" class="inline">' . fabo_csrf_field() . '<input type="hidden" name="a" value="hod-link"><input type="hidden" name="id" value="' . $id . '"><button class="btn btn-sm">Make a link to copy</button></form>'
               . '<form method="post" class="inline"' . ($h['status'] === 'active' ? ' data-confirm="Suspend this Head? They are signed out at once."' : '') . '>' . fabo_csrf_field() . '<input type="hidden" name="a" value="hod-status"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="status" value="' . ($h['status'] === 'active' ? 'suspended' : 'active') . '"><button class="btn btn-sm' . ($h['status'] === 'active' ? ' btn-danger-soft' : '') . '">' . ($h['status'] === 'active' ? 'Suspend' : 'Make active') . '</button></form>'
               . '</div>';
        }
    } elseif ($h) {
        echo '<dl class="iv-dl"><dt>Name</dt><dd>' . e($h['name']) . '</dd><dt>Email</dt><dd>' . e($h['email']) . '</dd><dt>Phone</dt><dd>' . e($h['phone'] ?: '—') . '</dd></dl>';
    }
    echo '</section>';

    // Department and this round
    echo '<section class="iv-card"><h2>Department</h2><dl class="iv-dl"><dt>College</dt><dd>' . e($d['college_name']) . ($d['district'] ? ', ' . e($d['district']) : '') . '</dd>'
       . '<dt>Students</dt><dd><a href="' . e(iv_url('students', ['dept' => $id])) . '">' . fabo_thousands($students) . '</a></dd></dl>';
    if ($round) {
        $form = iv_form_for($org, $round, $id);
        $ext = fabo_one('SELECT closes_at FROM iv_dept_windows WHERE round_id = ? AND department_id = ?', 'ii', [(int) $round['id'], $id]);
        echo '<h3>' . e($round['name']) . '</h3><dl class="iv-dl"><dt>Idea form</dt><dd>' . iv_copy(iv_form_url($org, $form['token'])) . (!(int) $form['is_open'] ? '<div class="iv-warn">Paused by the Head</div>' : '') . '</dd>'
           . '<dt>Submission closes</dt><dd>' . e(iv_when(iv_closes_for($round, $id))) . ($ext ? ' <span class="iv-pill g-review">extended</span>' : '') . '</dd></dl>';
        if (iv_can_edit()) {
            echo '<form method="post" class="iv-inline">' . fabo_csrf_field() . '<input type="hidden" name="a" value="window-set"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="round_id" value="' . (int) $round['id'] . '">'
               . '<label>Give this department until <input type="datetime-local" name="closes_at" value="' . e($ext ? date('Y-m-d\TH:i', strtotime($ext['closes_at'])) : '') . '"></label><button class="btn btn-sm">Set</button>'
               . ($ext ? '<button class="btn btn-sm" name="clear" value="1">Use the round\'s</button>' : '') . '</form>';
        }
    }
    if (iv_can_edit()) {
        echo '<details class="iv-more"><summary>Rename or move this department</summary><form method="post" class="iv-form">' . fabo_csrf_field() . '<input type="hidden" name="a" value="dept-save"><input type="hidden" name="id" value="' . $id . '"><div class="iv-fields">'
           . '<label class="field"><span>Name</span><input name="dept_name" required maxlength="160" value="' . e($d['name']) . '"></label>'
           . '<label class="field"><span>Branch code</span><input name="branch" maxlength="12" value="' . e((string) $d['branch']) . '"></label>'
           . '<label class="field wide"><span>College</span>' . iv_college_select($org, 'college_id', (int) $d['college_id'], '—') . '</label></div>'
           . '<button class="btn btn-sm">Save</button></form>'
           . '<form method="post" data-confirm="Remove this department and its dashboard? Its students stay, without a department.">' . fabo_csrf_field() . '<input type="hidden" name="a" value="dept-delete"><input type="hidden" name="id" value="' . $id . '"><button class="btn btn-sm btn-danger-soft">Remove department</button></form></details>';
    }
    echo '</section></div>';

    if ($round) {
        $ideas = iv_ideas($org, ['round' => (int) $round['id'], 'dept' => $id, 'sort' => 'rank']);
        $sent = iv_dept_forwarded($org, (int) $round['id'], $id);
        echo '<section class="iv-card"><div class="iv-card-h"><h2>Ideas in ' . e($round['name']) . ' (' . count($ideas) . ')</h2>';
        if ($sent && iv_can_edit()) {
            echo '<form method="post" class="inline" data-confirm="Hand this department\'s picks back to its Head? They leave your pipeline until sent again.">' . fabo_csrf_field()
               . '<input type="hidden" name="a" value="return-dept"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="round_id" value="' . (int) $round['id'] . '"><button class="btn btn-sm">Return picks to the Head</button></form>';
        }
        echo '</div>';
        iv_ideas_table($ideas, false);
        echo '</section>';
    }
}

/** A table of ideas. */
function iv_ideas_table(array $ideas, bool $showDept = true): void
{
    echo '<div class="iv-tablewrap"><table class="iv-table"><thead><tr><th>Idea</th><th>Student</th>' . ($showDept ? '<th>Department</th>' : '') . '<th>HoD decision</th><th class="n">AI score</th><th class="n">Rank</th><th>Pipeline</th></tr></thead><tbody>';
    if (!$ideas) {
        echo '<tr><td colspan="' . ($showDept ? 7 : 6) . '" class="iv-empty">No ideas yet.</td></tr>';
    }
    foreach ($ideas as $i) {
        echo '<tr><td><a href="' . e(iv_url('idea', ['id' => (int) $i['id']])) . '"><strong>' . e($i['title']) . '</strong></a><div class="muted sm">' . e(iv_date($i['submitted_at'])) . '</div></td>'
           . '<td>' . e($i['student_name']) . '<div class="muted sm">' . e($i['roll_no']) . '</div></td>'
           . ($showDept ? '<td>' . e($i['dept_name']) . '<div class="muted sm">' . e($i['college_name']) . '</div></td>' : '')
           . '<td>' . iv_pill($i['status']) . '</td><td class="n">' . e(iv_score($i['ai_score'], $i['ai_max'])) . ((int) $i['runs'] ? '<div class="muted sm">' . (int) $i['runs'] . ' check' . ((int) $i['runs'] === 1 ? '' : 's') . '</div>' : '') . '</td>'
           . '<td class="n">' . ($i['hod_rank'] ? '#' . (int) $i['hod_rank'] : '—') . '</td><td>' . iv_stage_pill($i['stage']) . '</td></tr>';
    }
    echo '</tbody></table></div>';
}

/* ============================================================== students */

function iv_page_students(int $org): void
{
    $f = ['college' => (int) ($_GET['college'] ?? 0), 'q' => (string) ($_GET['q'] ?? '')];
    $pg = max(1, (int) ($_GET['pg'] ?? 1));
    [$rows, $total] = iv_students($org, $f, 100, ($pg - 1) * 100);
    $counts = fabo_one('SELECT COUNT(*) n FROM iv_students WHERE org_id = ?', 'i', [$org]);
    $all = (int) ($counts['n'] ?? 0);
    if (iv_can_edit()) {
        echo '<div class="iv-actions"><button type="button" class="btn btn-primary" data-open="dlgStudent">+ Add student</button>'
           . '<button type="button" class="btn" data-open="dlgStudents">Upload many</button>'
           . '<a class="btn" href="' . e(iv_url('import')) . '">Import from Incubation</a>'
           . ($all ? '<button type="button" class="btn btn-danger-soft" data-open="dlgClear">Remove students to load again</button>' : '') . '</div>';
        echo '<dialog id="dlgStudent" class="iv-dlg"><form method="post" class="iv-form">' . fabo_csrf_field() . '<input type="hidden" name="a" value="student-add">'
           . '<h2>Add a student</h2><p class="muted">If this student is already here (same PIN with the same person, or the same email or phone with the same name), that record is updated instead — never a second one.</p>'
           . iv_student_fields($org, null)
           . '<div class="iv-dlg-f"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Add</button></div></form></dialog>';
        echo '<dialog id="dlgStudents" class="iv-dlg"><form method="post" enctype="multipart/form-data" class="iv-form" data-busy="Uploading… thousands of rows take a minute.">' . fabo_csrf_field() . '<input type="hidden" name="a" value="student-bulk">'
           . '<h2>Upload students</h2><p class="muted">One row per student, in the POLY TECHFEST sheet’s columns: <strong>Email Address, Code and Name of the Polytechnic, Name of the District, Full Name of the Student, PIN (XXXXX-XX-XXX), Email ID, Phone Number, Remarks</strong>. '
           . 'Only the name and one of PIN, Email ID or Phone Number are needed. Everything is kept exactly as typed in the sheet. The branch in a PIN (24045-<b>CM</b>-042) puts the student in that branch’s HoD dashboard when one exists. '
           . 'Email ID is the student’s; Email Address is kept beside it. District and Remarks are kept too. The same student registered twice is filed once.</p>'
           . '<p><a href="' . e(iv_url('students', ['csv' => 'student-template'])) . '">Download the template</a></p>'
           . '<label class="field"><span>File (.csv or .xlsx)</span><input type="file" name="file" accept=".csv,.xlsx" required></label>'
           . '<div class="iv-fields"><label class="field"><span>Rows with no college go to</span>' . iv_college_select($org, 'college_id', 0, '— leave unplaced —') . '</label>'
           . '<label class="field"><span>Rows with no department go to</span>' . iv_dept_select($org, 'department_id', 0, '— leave unplaced —') . '</label></div>'
           . '<label class="iv-check"><input type="checkbox" name="make" value="1" checked> Create missing colleges</label><p class="muted sm">Departments are not created from student sheets: they are the HoD dashboards. Each student joins the dashboard for their PIN’s branch as soon as it exists.</p>'
           . '<div class="iv-dlg-f"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Upload</button></div></form></dialog>';
        if ($all) {
            echo '<dialog id="dlgClear" class="iv-dlg"><form method="post" class="iv-form">' . fabo_csrf_field() . '<input type="hidden" name="a" value="students-clear">'
               . '<h2>Remove students to load them again</h2><p>Removes every student who has <strong>no idea and is on no team</strong>, so a corrected sheet can be uploaded from a clean list. '
               . 'Students with ideas or on teams stay. Colleges, departments and HoD dashboards are not touched.</p>'
               . '<label class="field"><span>Type REMOVE to confirm</span><input name="confirm" required pattern="REMOVE" autocomplete="off"></label>'
               . '<div class="iv-dlg-f"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-danger-soft">Remove students</button></div></form></dialog>';
        }
    }
    echo '<form method="get" class="iv-filters">' . iv_hidden_org() . '<input type="hidden" name="p" value="students">'
       . '<input type="search" name="q" value="' . e($f['q']) . '" placeholder="Name, PIN, email or phone">'
       . iv_college_select($org, 'college', $f['college']) . '<button class="btn">Filter</button></form>';

    // How the last upload's rows add up to this list.
    $imp = iv_last_import($org);
    echo '<section class="iv-kpis">' . iv_kpi('Students', $all, '', iv_url('students'));
    if ($imp) {
        echo iv_kpi('Rows in the last upload', (int) $imp['rows_read']) . iv_kpi('Repeat registrations', (int) $imp['repeats'], (int) $imp['repeats'] ? 'warn' : '');
    }
    echo '</section>';
    if ($imp) {
        echo '<div class="iv-flash info">' . e('Last upload (' . $imp['label'] . ', ' . iv_when($imp['created_at']) . '): ' . fabo_thousands((int) $imp['rows_read']) . ' rows = '
           . fabo_thousands((int) $imp['students']) . ' students + ' . fabo_thousands((int) $imp['repeats']) . ' rows where the same student registered again (same PIN and name, kept once)'
           . ((int) $imp['skipped'] ? ' + ' . fabo_thousands((int) $imp['skipped']) . ' skipped' : '') . '.')
           . ((int) $imp['repeats'] || (int) $imp['skipped'] ? ' <a href="' . e(iv_url('students', ['csv' => 'repeats', 'import' => (int) $imp['id']])) . '"><strong>Download those rows</strong></a> to see each one beside the row it repeats.' : '') . '</div>';
    }

    echo '<section class="iv-card"><div class="iv-card-h"><h2>' . fabo_thousands($total) . ' student' . ($total === 1 ? '' : 's') . (array_filter($f) ? ' match' : '') . '</h2>'
       . '<a class="btn btn-sm" href="' . e(iv_url('students', ['csv' => 'students'] + array_filter($f))) . '">Export</a></div>'
       . '<div class="iv-tablewrap"><table class="iv-table iv-click" data-panels'
       . ((int) ($_GET['open'] ?? 0) ? ' data-open-panel="' . e(iv_url('student', ['id' => (int) $_GET['open'], 'embed' => 1, 'back_q' => http_build_query(array_filter($f + ['pg' => $pg > 1 ? $pg : null]))])) . '" data-open-id="' . (int) $_GET['open'] . '"' : '')
       . '><thead><tr><th>Name</th><th>Roll / PIN</th><th>College</th><th>Email</th><th>Phone</th><th></th></tr></thead><tbody>';
    if (!$rows) {
        echo '<tr><td colspan="6" class="iv-empty">No students' . (array_filter($f) ? ' match' : ' yet') . '.</td></tr>';
    }
    // Rows open the student in a panel over the list (assets/ideas.js); the links still open the full page.
    $backQ = http_build_query(array_filter($f + ['pg' => $pg > 1 ? $pg : null]));
    foreach ($rows as $s) {
        $href = iv_url('student', ['id' => (int) $s['id']]);
        $panel = iv_url('student', ['id' => (int) $s['id'], 'embed' => 1, 'back_q' => $backQ]);
        echo '<tr data-href="' . e($href) . '" data-panel="' . e($panel) . '" data-id="' . (int) $s['id'] . '"><td><a href="' . e($href) . '" data-panel-link><strong>' . e($s['name']) . '</strong></a></td>'
           . '<td class="mono">' . e($s['roll_no'] ?: '—') . '</td>'
           . '<td>' . e($s['college_name'] ?? '—') . '</td>'
           . '<td>' . e($s['email'] ?: '—') . '</td><td>' . e($s['phone'] ?: '—') . '</td><td class="act"><a class="btn btn-sm" href="' . e($href) . '" data-panel-link>View</a></td></tr>';
    }
    echo '</tbody></table></div>';
    $pages = (int) ceil($total / 100);
    if ($pages > 1) {
        echo '<nav class="iv-pager">';
        if ($pg > 1) {
            echo '<a class="btn btn-sm" href="' . e(iv_url('students', array_filter($f) + ['pg' => $pg - 1])) . '">← Previous</a>';
        }
        echo '<span>Page ' . $pg . ' of ' . $pages . '</span>';
        if ($pg < $pages) {
            echo '<a class="btn btn-sm" href="' . e(iv_url('students', array_filter($f) + ['pg' => $pg + 1])) . '">Next →</a>';
        }
        echo '</nav>';
    }
    echo '</section>';
}

function iv_student_fields(int $org, ?array $s): string
{
    return '<div class="iv-fields">'
         . '<label class="field"><span>Name</span><input name="name" required maxlength="160" value="' . e($s['name'] ?? '') . '"></label>'
         . '<label class="field"><span>Roll number / PIN</span><input name="roll_no" maxlength="80" value="' . e($s ? iv_roll_shown($s) : '') . '" placeholder="24045-CM-042"></label>'
         . '<label class="field"><span>Email</span><input type="email" name="email" maxlength="160" value="' . e($s['email'] ?? '') . '"></label>'
         . '<label class="field"><span>Second email</span><input type="email" name="alt_email" maxlength="160" value="' . e($s['alt_email'] ?? '') . '"></label>'
         . '<label class="field"><span>Phone</span><input name="phone" maxlength="20" value="' . e($s['phone'] ?? '') . '"></label>'
         . '<label class="field"><span>Year</span><input name="year" maxlength="20" value="' . e($s['year_of_study'] ?? '') . '"></label>'
         . '<label class="field"><span>District</span><input name="district" maxlength="80" value="' . e($s['district'] ?? '') . '"></label>'
         // No department here: students join their branch's HoD dashboard by themselves (kept as it is on save).
         . '<input type="hidden" name="department_id" value="' . (int) ($s['department_id'] ?? 0) . '">'
         . '<label class="field wide"><span>College</span>' . iv_college_select($org, 'college_id', (int) ($s['college_id'] ?? 0), '—') . '</label>'
         . '<label class="field wide"><span>Remarks</span><textarea name="notes" rows="2" maxlength="2000">' . e((string) ($s['notes'] ?? '')) . '</textarea></label>'
         . '</div>';
}

/**
 * Everything known about a student, read-only: a header with who they are
 * and quick actions, their contact and registration details, and their
 * ideas. Shared with the HoD portal. $actions: extra buttons for the header
 * (the owner's Edit).
 */
function iv_student_profile_html(int $org, array $s, callable $ideaUrl, ?callable $studentUrl = null, string $actions = ''): string
{
    $dash = '<span class="muted">—</span>';
    $row = static fn(string $k, string $html) => '<dt>' . e($k) . '</dt><dd>' . ($html !== '' ? $html : $dash) . '</dd>';
    $pin = iv_roll_shown($s);
    $email = (string) $s['email'];
    $phone = (string) $s['phone'];
    $district = (string) ($s['district'] !== '' ? $s['district'] : ($s['college_district'] ?? ''));
    $college = trim((string) ($s['college_name'] ?? ''));
    $code = (string) ($s['college_code'] ?? '');

    $h = '<section class="iv-card iv-phead"><div class="iv-phead-main"><span class="iv-av xl" aria-hidden="true">' . e(mb_strtoupper(mb_substr((string) $s['name'], 0, 1))) . '</span>'
       . '<div class="iv-phead-who"><span class="iv-eyebrow">Student</span><h1>' . e($s['name']) . '</h1><div class="iv-chips">'
       . ($pin !== '' ? '<span class="iv-chip mono" title="PIN / roll number">' . e($pin) . '</span>' : '')
       . ($college !== '' ? '<span class="iv-chip">' . e($college) . '</span>' : '')
       . ($district !== '' ? '<span class="iv-chip soft">' . e($district) . '</span>' : '')
       . '</div></div></div><div class="iv-phead-act">'
       . ($email !== '' ? '<a class="btn btn-sm" href="mailto:' . e($email) . '">Email</a>' : '')
       . ($phone !== '' ? '<a class="btn btn-sm" href="tel:' . e($phone) . '">Call</a>' : '')
       . $actions . '</div></section>';

    $h .= '<div class="iv-grid2"><section class="iv-card"><h2>Contact</h2><dl class="iv-dl">'
        . $row('Email', $email !== '' ? '<a href="mailto:' . e($email) . '">' . e($email) . '</a>' : '')
        . ((string) $s['alt_email'] !== '' ? $row('Second email', '<a href="mailto:' . e($s['alt_email']) . '">' . e($s['alt_email']) . '</a>') : '')
        . $row('Phone', $phone !== '' ? '<a href="tel:' . e($phone) . '">' . e($phone) . '</a>' : '')
        . '</dl></section><section class="iv-card"><h2>Registration</h2><dl class="iv-dl">'
        . $row('PIN / roll number', e($pin))
        . $row('College', e(trim(($code !== '' ? $code . ' · ' : '') . $college)))
        . $row('District', e($district))
        . ((string) $s['year_of_study'] !== '' ? $row('Year', e((string) $s['year_of_study'])) : '')
        . ((string) ($s['notes'] ?? '') !== '' ? $row('Remarks', nl2br(e((string) $s['notes']))) : '')
        . '</dl></section></div>';

    $items = [];
    foreach (fabo_all('SELECT i.id, i.title, i.status, i.stage, r.name AS round_name FROM iv_ideas i JOIN iv_rounds r ON r.id = i.round_id WHERE i.org_id = ? AND i.student_id = ? ORDER BY i.id DESC', 'ii', [$org, (int) $s['id']]) as $i) {
        $items[] = '<li><a href="' . e($ideaUrl((int) $i['id'])) . '"><strong>' . e($i['title']) . '</strong></a> <span class="muted">· ' . e($i['round_name']) . '</span> ' . iv_pill($i['status']) . ' ' . iv_stage_pill($i['stage']) . '</li>';
    }
    foreach (fabo_all('SELECT m.role, i.id, i.title, r.name AS round_name FROM iv_team m JOIN iv_ideas i ON i.id = m.idea_id JOIN iv_rounds r ON r.id = m.round_id WHERE m.org_id = ? AND m.student_id = ? AND m.role = "member"', 'ii', [$org, (int) $s['id']]) as $m) {
        $items[] = '<li>Team member on <a href="' . e($ideaUrl((int) $m['id'])) . '"><strong>' . e($m['title']) . '</strong></a> <span class="muted">· ' . e($m['round_name']) . '</span></li>';
    }
    $h .= '<section class="iv-card"><h2>Ideas and teams</h2>'
        . ($items ? '<ul class="iv-list">' . implode('', $items) . '</ul>' : '<p class="muted iv-quiet">No idea sent yet. Ideas this student sends through their department’s form appear here.</p>')
        . '</section>';
    $h .= '<p class="iv-recordline">Added ' . e(iv_when($s['created_at'])) . ' · last changed ' . e(iv_when($s['updated_at'])) . ' · from ' . e((string) $s['source']) . '</p>';
    return $h;
}

function iv_page_student(int $org): void
{
    $s = iv_student($org, (int) ($_GET['id'] ?? 0));
    if (!$s) {
        echo '<section class="iv-card"><p>That student is not here. <a href="' . e(iv_url('students')) . '">Back to students</a>.</p></section>';
        return;
    }
    echo '<p class="iv-crumb"><a href="' . e(iv_url('students')) . '">← All students</a></p>';
    $edit = iv_can_edit() ? '<button type="button" class="btn btn-sm btn-primary" data-open="dlgEditStudent">Edit</button>' : '';
    echo iv_student_profile_html($org, $s, static fn(int $id) => iv_url('idea', ['id' => $id]), static fn(int $id) => iv_url('student', ['id' => $id]), $edit);
    if (iv_can_edit()) {
        echo '<dialog id="dlgEditStudent" class="iv-dlg"><form method="post" class="iv-form">' . fabo_csrf_field()
           . '<input type="hidden" name="a" value="student-save"><input type="hidden" name="id" value="' . (int) $s['id'] . '">'
           . '<h2>Edit ' . e($s['name']) . '</h2>' . iv_student_fields($org, $s)
           . '<div class="iv-dlg-f"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Save</button></div></form>'
           . '<form method="post" class="iv-dlg-danger" data-confirm="Remove this student from the list?">' . fabo_csrf_field()
           . '<input type="hidden" name="a" value="student-delete"><input type="hidden" name="id" value="' . (int) $s['id'] . '"><button class="btn btn-sm btn-danger-soft">Remove this student</button></form></dialog>';
    }
}

/**
 * One student for the slide-in panel over the Students list: the profile, and
 * (for the owner) an edit view inside the panel. Saving posts to the list
 * (with its filters) and comes back to it with the student open again.
 */
function iv_student_panel(int $org): void
{
    $s = iv_student($org, (int) ($_GET['id'] ?? 0));
    if (!$s) {
        http_response_code(404);
        echo '<div class="iv-pn-missing"><p>That student is not here any more.</p></div>';
        return;
    }
    $listQ = (string) ($_GET['back_q'] ?? '');
    $listUrl = iv_url('students', iv_list_query($listQ));
    $edit = iv_can_edit() ? '<button type="button" class="btn btn-sm btn-primary" data-pn-edit>Edit</button>' : '';
    echo '<div class="iv-pn-view" data-pn-title="' . e($s['name']) . '" data-pn-full="' . e(iv_url('student', ['id' => (int) $s['id']])) . '">'
       . iv_student_profile_html($org, $s, static fn(int $id) => iv_url('idea', ['id' => $id]), null, $edit) . '</div>';
    if (iv_can_edit()) {
        echo '<div class="iv-pn-edit" hidden><form method="post" action="' . e($listUrl) . '" class="iv-form">' . fabo_csrf_field()
           . '<input type="hidden" name="a" value="student-save"><input type="hidden" name="id" value="' . (int) $s['id'] . '"><input type="hidden" name="back_q" value="' . e($listQ) . '">'
           . '<h2 class="iv-pn-h">Edit details</h2>' . iv_student_fields($org, $s)
           . '<div class="iv-pn-bar"><button type="button" class="btn" data-pn-view>Cancel</button><button class="btn btn-primary" data-busy-text="Saving…">Save changes</button></div></form>'
           . '<form method="post" action="' . e($listUrl) . '" class="iv-pn-danger" data-confirm="Remove ' . e($s['name']) . ' from the list?">' . fabo_csrf_field()
           . '<input type="hidden" name="a" value="student-delete"><input type="hidden" name="id" value="' . (int) $s['id'] . '"><button class="btn btn-sm btn-danger-soft">Remove this student</button></form></div>';
    }
}

/* ================================================================ rounds */

function iv_page_rounds(int $org): void
{
    $rounds = iv_rounds($org);
    $edit = isset($_GET['round']) ? iv_round($org, (int) $_GET['round']) : null;
    $gems = iv_gems_all($org);
    echo '<div class="iv-grid2"><section class="iv-card"><h2>Rounds</h2><div class="iv-tablewrap"><table class="iv-table"><thead><tr><th>Round</th><th>Submission</th><th>Gem</th><th class="n">AI / idea</th><th class="n">Top</th><th class="n">Ideas</th></tr></thead><tbody>';
    if (!$rounds) {
        echo '<tr><td colspan="6" class="iv-empty">No rounds yet. Create the first one.</td></tr>';
    }
    foreach ($rounds as $r) {
        $n = (int) (fabo_one('SELECT COUNT(*) n FROM iv_ideas WHERE org_id = ? AND round_id = ?', 'ii', [$org, (int) $r['id']])['n'] ?? 0);
        echo '<tr' . ($edit && (int) $edit['id'] === (int) $r['id'] ? ' class="on"' : '') . '><td><a href="' . e(iv_url('rounds', ['round' => (int) $r['id']])) . '"><strong>' . e($r['name']) . '</strong></a> <span class="iv-pill r-' . e($r['status']) . '">' . e(ucfirst($r['status'])) . '</span></td>'
           . '<td class="sm">' . e(iv_when($r['opens_at'])) . '<br>→ ' . e(iv_when($r['closes_at'])) . '</td><td>' . e($gems[(int) $r['gem_id']]['name'] ?? ($r['gem_id'] ? 'Gem #' . (int) $r['gem_id'] : '—')) . '</td>'
           . '<td class="n">' . (int) $r['ai_limit'] . '</td><td class="n">' . (int) $r['top_n'] . '</td><td class="n">' . $n . '</td></tr>';
    }
    echo '</tbody></table></div>' . ($edit ? '<p><a class="btn btn-sm" href="' . e(iv_url('rounds')) . '">+ New round</a></p>' : '') . '</section>';

    if (!iv_can_edit()) {
        echo '</div>';
        return;
    }
    $v = $edit ?? ['name' => '', 'gem_id' => array_key_first(array_filter($gems, static fn($g) => (int) $g['active'] === 1)), 'opens_at' => date('Y-m-d 09:00:00'), 'closes_at' => date('Y-m-d 18:00:00', strtotime('+14 days')),
                   'review_by' => date('Y-m-d 18:00:00', strtotime('+21 days')), 'ai_limit' => (int) iv_get($org, 'default_ai_limit', '2'), 'top_n' => 3, 'allow_new' => 1, 'own_ai' => 1, 'status' => 'live'];
    $dl = static fn($x) => $x ? date('Y-m-d\TH:i', strtotime($x)) : '';
    echo '<section class="iv-card"><h2>' . ($edit ? 'Edit ' . e($edit['name']) : 'New round') . '</h2><form method="post" class="iv-form">' . fabo_csrf_field()
       . '<input type="hidden" name="a" value="round-save"><input type="hidden" name="id" value="' . (int) ($edit['id'] ?? 0) . '"><div class="iv-fields">'
       . '<label class="field wide"><span>Name</span><input name="name" required maxlength="160" value="' . e($v['name']) . '" placeholder="Idea round 2026-27"></label>'
       . '<label class="field wide"><span>Gem that validates the ideas</span><select name="gem_id"><option value="0">— none yet —</option>';
    foreach ($gems as $gid => $g) {
        if ((int) $g['active'] !== 1 && (int) $v['gem_id'] !== (int) $gid) {
            continue;      // a switched-off Gem stays only on the round already using it
        }
        echo '<option value="' . (int) $gid . '"' . ((int) $v['gem_id'] === (int) $gid ? ' selected' : '') . '>' . e($g['name'] . ((int) $g['active'] !== 1 ? ' — off' : '') . ' (' . count($g['criteria']) . ' criteria, ' . count($g['fields']) . ' questions)') . '</option>';
    }
    echo '</select>' . (!$gems ? '<small class="iv-warn">No Gems yet. <a href="' . e(iv_url('gems')) . '">Make one on Gems</a>.</small>' : '<small>The Gem\'s questions go on every department\'s form; its rubric scores the ideas. <a href="' . e(iv_url('gems', $v['gem_id'] ? ['id' => (int) $v['gem_id']] : [])) . '">Open the Gem</a> to change it.</small>') . '</label>'
       . '<label class="field"><span>Submission opens</span><input type="datetime-local" name="opens_at" required value="' . e($dl($v['opens_at'])) . '"></label>'
       . '<label class="field"><span>Submission closes</span><input type="datetime-local" name="closes_at" required value="' . e($dl($v['closes_at'])) . '"></label>'
       . '<label class="field"><span>HoDs send top ideas by</span><input type="datetime-local" name="review_by" value="' . e($dl($v['review_by'])) . '"></label>'
       . '<label class="field"><span>Status</span><select name="status">';
    foreach (['live' => 'Live', 'draft' => 'Draft (HoDs do not see it)', 'closed' => 'Closed'] as $k => $l) {
        echo '<option value="' . $k . '"' . ($v['status'] === $k ? ' selected' : '') . '>' . e($l) . '</option>';
    }
    echo '</select></label>'
       . '<label class="field"><span>AI checks per idea</span><input type="number" name="ai_limit" min="0" max="20" required value="' . (int) $v['ai_limit'] . '"><small>Portal and own-tool checks both count. An idea\'s score is the average of its checks. A Head can be given their own number on their dashboard.</small></label>'
       . '<label class="field"><span>Top ideas each department sends</span><input type="number" name="top_n" min="1" max="20" required value="' . (int) $v['top_n'] . '"></label>'
       . '</div><label class="iv-check"><input type="checkbox" name="allow_new" value="1"' . ((int) $v['allow_new'] ? ' checked' : '') . '> Students not on the list may submit (they are added to the department)</label>'
       . '<input type="hidden" name="own_ai_form" value="1"><label class="iv-check"><input type="checkbox" name="own_ai" value="1"' . ((int) ($v['own_ai'] ?? 1) ? ' checked' : '') . '> HoDs may also use their own AI tool (they paste its reply; it is kept for you). Untick so every score comes from the portal: no HoD can influence it.</label>'
       . '<div class="iv-actions"><button class="btn btn-primary">' . ($edit ? 'Save round' : 'Create round') . '</button></div></form>';
    if ($edit) {
        echo '<form method="post" data-confirm="Remove this round?">' . fabo_csrf_field() . '<input type="hidden" name="a" value="round-delete"><input type="hidden" name="id" value="' . (int) $edit['id'] . '"><button class="btn btn-sm btn-danger-soft">Remove round</button></form>';
    }
    echo '</section></div>';
}

/* ================================================================= ideas */

function iv_page_ideas(int $org): void
{
    $round = iv_page_round($org);
    if (!$round) {
        echo '<section class="iv-card"><p>No round yet. <a href="' . e(iv_url('rounds')) . '">Create one</a>.</p></section>';
        return;
    }
    $f = ['round' => (int) $round['id'], 'college' => (int) ($_GET['college'] ?? 0), 'dept' => (int) ($_GET['dept'] ?? 0), 'status' => (string) ($_GET['status'] ?? ''), 'q' => (string) ($_GET['q'] ?? ''), 'sort' => (string) ($_GET['sort'] ?? '')];
    $ideas = iv_ideas($org, $f, 1000);
    echo '<form method="get" class="iv-filters">' . iv_hidden_org() . '<input type="hidden" name="p" value="ideas">' . iv_round_select($org, (int) $round['id'])
       . '<input type="search" name="q" value="' . e($f['q']) . '" placeholder="Title, student, PIN">' . iv_college_select($org, 'college', $f['college']) . iv_dept_select($org, 'dept', $f['dept'], 'Any department')
       . '<select name="status"><option value="">Any decision</option>';
    foreach (iv_statuses() as $k => $l) {
        echo '<option value="' . $k . '"' . ($f['status'] === $k ? ' selected' : '') . '>' . e($l) . '</option>';
    }
    echo '</select><select name="sort"><option value="">Newest</option><option value="score"' . ($f['sort'] === 'score' ? ' selected' : '') . '>AI score</option></select><button class="btn">Filter</button></form>';
    echo '<section class="iv-card"><div class="iv-card-h"><h2>' . count($ideas) . ' idea' . (count($ideas) === 1 ? '' : 's') . '</h2><a class="btn btn-sm" href="' . e(iv_url('ideas', ['csv' => 'ideas', 'round' => (int) $round['id']])) . '">Export</a></div>';
    iv_ideas_table($ideas);
    echo '</section>';
}

/* ============================================================== pipeline */

function iv_page_pipeline(int $org): void
{
    $round = iv_page_round($org);
    if (!$round) {
        echo '<section class="iv-card"><p>No round yet. <a href="' . e(iv_url('rounds')) . '">Create one</a>.</p></section>';
        return;
    }
    $f = ['round' => (int) $round['id'], 'stage' => 'any', 'college' => (int) ($_GET['college'] ?? 0), 'q' => (string) ($_GET['q'] ?? ''), 'sort' => 'score'];
    $ideas = iv_ideas($org, $f, 2000);
    echo '<form method="get" class="iv-filters">' . iv_hidden_org() . '<input type="hidden" name="p" value="pipeline">' . iv_round_select($org, (int) $round['id'])
       . '<input type="search" name="q" value="' . e($f['q']) . '" placeholder="Title, student, PIN">' . iv_college_select($org, 'college', $f['college'])
       . '<button class="btn">Filter</button><a class="btn" href="' . e(iv_url('pipeline', ['csv' => 'pipeline', 'round' => (int) $round['id']])) . '">Export with teams</a></form>';
    $by = array_fill_keys(array_keys(iv_stages()), []);
    foreach ($ideas as $i) {
        $by[$i['stage']][] = $i;
    }
    echo '<div class="iv-board">';
    foreach (iv_stages() as $k => $label) {
        echo '<section class="iv-col g-' . e($k) . '"><header><span>' . e($label) . '</span><b>' . count($by[$k]) . '</b></header><div class="iv-col-b">';
        if (!$by[$k]) {
            echo '<p class="iv-empty sm">Nothing here.</p>';
        }
        foreach ($by[$k] as $i) {
            echo '<article class="iv-cardlet"><a href="' . e(iv_url('idea', ['id' => (int) $i['id']])) . '"><strong>' . e($i['title']) . '</strong></a>'
               . '<div class="muted sm">' . e($i['dept_name']) . ' · ' . e($i['college_name']) . '</div>'
               . '<div class="iv-meta"><span class="iv-code">#' . (int) $i['hod_rank'] . ' in dept</span><span>' . e(iv_score($i['ai_score'], $i['ai_max'])) . '</span><span>👥 ' . (int) $i['team_size'] . '</span>'
               . (($fl = iv_idea_flags($org, $i)) ? '<span class="iv-flagc" title="' . e(implode("
", array_column($fl, 'text'))) . '">⚑ ' . count($fl) . ' to check</span>' : '') . '</div>';
            if (iv_can_edit()) {
                echo '<form method="post" class="iv-move">' . fabo_csrf_field() . '<input type="hidden" name="a" value="stage"><input type="hidden" name="id" value="' . (int) $i['id'] . '"><input type="hidden" name="back" value="pipeline"><input type="hidden" name="round" value="' . (int) $round['id'] . '">'
                   . '<select name="stage" aria-label="Move to" onchange="this.form.submit()">';
                foreach (iv_stages() as $sk => $sl) {
                    echo '<option value="' . $sk . '"' . ($sk === $k ? ' selected' : '') . '>' . e($sl) . '</option>';
                }
                echo '</select><noscript><button class="btn btn-sm">Move</button></noscript></form>';
            }
            echo '</article>';
        }
        echo '</div></section>';
    }
    echo '</div>';
}

/* ================================================================== idea */

function iv_page_idea(int $org): void
{
    $i = iv_idea($org, (int) ($_GET['id'] ?? 0));
    if (!$i) {
        echo '<section class="iv-card"><p>That idea is not here. <a href="' . e(iv_url('ideas')) . '">Back to ideas</a>.</p></section>';
        return;
    }
    echo '<p class="iv-crumb"><a href="' . e(iv_url($i['stage'] !== null ? 'pipeline' : 'ideas', ['round' => (int) $i['round_id']])) . '">' . ($i['stage'] !== null ? 'Pipeline' : 'All ideas') . '</a> › '
       . '<a href="' . e(iv_url('dashboard', ['id' => (int) $i['department_id']])) . '">' . e($i['dept_name']) . ', ' . e($i['college_name']) . '</a></p>';
    echo '<h2 class="iv-title">' . e($i['title']) . '</h2><p class="iv-sub">' . iv_pill($i['status']) . ' ' . iv_stage_pill($i['stage']) . ($i['hod_rank'] ? ' <span class="iv-code">#' . (int) $i['hod_rank'] . ' in department</span>' : '')
       . ' <span class="muted">· ' . e($i['round_name']) . ' · submitted ' . e(iv_when($i['submitted_at'])) . '</span></p>';
    echo '<div class="iv-grid2 wide-left"><div>';
    iv_idea_answers_html($i);
    iv_runs_html($org, $i);
    echo '</div><div>';
    // Student, HoD's view
    echo '<section class="iv-card"><h2>Student</h2><dl class="iv-dl"><dt>Name</dt><dd><a href="' . e(iv_url('student', ['id' => (int) $i['student_id']])) . '">' . e($i['student_name']) . '</a></dd>'
       . '<dt>Roll / PIN</dt><dd>' . e($i['roll_no'] ?: '—') . '</dd><dt>Email</dt><dd>' . e($i['student_email'] ?: '—') . '</dd><dt>Phone</dt><dd>' . e($i['student_phone'] ?: '—') . '</dd></dl>'
       . ($i['hod_note'] ? '<h3>Head of Department\'s note</h3><p class="iv-note">' . nl2br(e($i['hod_note'])) . '</p>' : '') . '</section>';
    // Integrity: what to know before trusting the HoD's call, and the owner's own AI check.
    $flags = iv_idea_flags($org, $i);
    echo '<section class="iv-card iv-integ' . ($flags ? ' has' : '') . '"><div class="iv-card-h"><h2>Integrity check</h2>' . ($flags ? '<span class="iv-flagc">⚑ ' . count($flags) . '</span>' : '<span class="iv-pill s-validated">Nothing to flag</span>') . '</div>';
    if ($flags) {
        echo '<ul class="iv-flags">';
        foreach ($flags as $fl) {
            echo '<li class="f-' . e($fl['k']) . '">' . e($fl['text']) . '</li>';
        }
        echo '</ul>';
    } else {
        echo '<p class="muted sm">The HoD\'s decision matches the AI checks.</p>';
    }
    if (iv_can_edit() && iv_portal_ai_ready()) {
        echo '<form method="post" data-busy="Checking… up to a minute">' . fabo_csrf_field() . '<input type="hidden" name="a" value="verify"><input type="hidden" name="id" value="' . (int) $i['id'] . '">'
           . '<button class="btn btn-sm">Verify with AI</button> <small class="muted">Your own check in the portal. It does not use the HoD\'s checks; the idea\'s score becomes the average.</small></form>';
    }
    echo '</section>';
    if ($i['stage'] !== null) {
        echo '<section class="iv-card"><h2>Pipeline</h2>';
        if (iv_can_edit()) {
            echo '<form method="post" class="iv-form">' . fabo_csrf_field() . '<input type="hidden" name="a" value="stage"><input type="hidden" name="id" value="' . (int) $i['id'] . '">'
               . '<label class="field"><span>Stage</span><select name="stage">';
            foreach (iv_stages() as $k => $l) {
                echo '<option value="' . $k . '"' . ($k === $i['stage'] ? ' selected' : '') . '>' . e($l) . '</option>';
            }
            echo '</select></label><label class="field"><span>Your note</span><textarea name="note" rows="3" maxlength="3000">' . e((string) $i['owner_note']) . '</textarea></label><button class="btn btn-primary btn-sm">Save</button></form>';
        } else {
            echo '<p>' . iv_stage_pill($i['stage']) . '</p>' . ($i['owner_note'] ? '<p class="iv-note">' . nl2br(e($i['owner_note'])) . '</p>' : '');
        }
        echo '</section>';
        iv_team_html($org, $i);
    } else {
        echo '<section class="iv-card"><h2>Pipeline</h2><p class="muted">With the Head of Department. It joins your pipeline when they send it as one of their top ' . (int) (iv_round($org, (int) $i['round_id'])['top_n'] ?? 3) . '.</p></section>';
    }
    echo '<section class="iv-card"><h2>History</h2><ol class="iv-log">';
    foreach (fabo_all('SELECT * FROM iv_log WHERE org_id = ? AND idea_id = ? ORDER BY id DESC LIMIT 60', 'ii', [$org, (int) $i['id']]) as $l) {
        echo '<li><span>' . e($l['what']) . '</span><small>' . e($l['actor']) . ' · ' . e(iv_when($l['created_at'])) . '</small></li>';
    }
    echo '</ol></section></div></div>';
}

/** The student's answers. Shared with the HoD portal. */
function iv_idea_answers_html(array $i): void
{
    echo '<section class="iv-card"><h2>The idea</h2><div class="iv-answers">';
    foreach (iv_idea_answers($i) as $a) {
        echo '<div class="iv-ans"><h3>' . e($a['label']) . '</h3>' . ($a['value'] !== '' ? '<p>' . nl2br(e($a['value'])) . '</p>' : '<p class="muted">No answer.</p>') . '</div>';
    }
    echo '</div></section>';
}

/** The AI checks of an idea, newest first. Shared with the HoD portal. */
function iv_runs_html(int $org, array $i): void
{
    $runs = iv_runs($org, (int) $i['id']);
    if (!$runs) {
        echo '<section class="iv-card"><h2>AI checks</h2><p class="muted">Not checked yet.</p></section>';
        return;
    }
    echo '<section class="iv-card"><h2>AI checks (' . count($runs) . ')</h2>';
    foreach ($runs as $n => $r) {
        $bd = json_decode((string) $r['breakdown_json'], true) ?: [];
        $imp = json_decode((string) $r['improvements_json'], true) ?: [];
        $pct = (float) $r['max_score'] > 0 ? (float) $r['score'] / (float) $r['max_score'] * 100 : 0;
        echo '<details class="iv-run"' . ($n === 0 ? ' open' : '') . '><summary><span class="iv-score" style="--p:' . round($pct) . '">' . e(iv_score($r['score'], $r['max_score'])) . '</span>'
           . '<span>' . e($r['tool'] ?: ($r['mode'] === 'portal' ? 'In portal' : 'Own AI tool')) . ' '
           . ($r['mode'] === 'portal' ? '<span class="iv-pill s-validated" title="Scored by the portal itself">Verified</span>' : '<span class="iv-pill s-submitted" title="Scored in the HoD\'s own AI tool and pasted back">Own tool</span>')
           . ' <span class="muted sm">· ' . e($r['actor']) . ' · ' . e(iv_when($r['created_at'])) . '</span></span></summary>';
        if ($r['summary']) {
            echo '<p class="iv-note">' . nl2br(e($r['summary'])) . '</p>';
        }
        echo '<table class="iv-table iv-rubric"><tbody>';
        foreach ($bd as $c) {
            $w = (float) $c['max'] > 0 ? (float) $c['score'] / (float) $c['max'] * 100 : 0;
            echo '<tr><td><strong>' . e($c['name']) . '</strong>' . ($c['reason'] !== '' ? '<div class="muted sm">' . e($c['reason']) . '</div>' : '') . '</td>'
               . '<td class="n nowrap">' . e(iv_score($c['score'], $c['max'])) . '<div class="iv-meter"><i style="width:' . round($w) . '%"></i></div></td></tr>';
        }
        echo '</tbody></table>';
        if ($imp) {
            echo '<h3>What would make it better</h3><ul>';
            foreach ($imp as $p) {
                echo '<li>' . e($p) . '</li>';
            }
            echo '</ul>';
        }
        if ((string) ($r['reply_text'] ?? '') !== '') {
            echo '<details class="iv-reply"><summary>The reply as pasted</summary><pre>' . e((string) $r['reply_text']) . '</pre></details>';
        }
        echo '</details>';
    }
    echo '</section>';
}

function iv_team_html(int $org, array $i): void
{
    $team = iv_team($org, (int) $i['id']);
    echo '<section class="iv-card"><h2>Team (' . count($team) . ')</h2><ul class="iv-team">';
    foreach ($team as $m) {
        echo '<li><span class="iv-av">' . e(mb_strtoupper(mb_substr($m['name'], 0, 1))) . '</span><span><strong>' . e($m['name']) . '</strong>' . ($m['role'] === 'lead' ? ' <span class="iv-pill g-selected">Lead</span>' : '')
           . '<small>' . e(trim($m['roll_no'] . ' · ' . ($m['dept_name'] ?? ''), ' ·')) . '</small></span>';
        if ($m['role'] !== 'lead' && iv_can_edit()) {
            echo '<form method="post">' . fabo_csrf_field() . '<input type="hidden" name="a" value="team-remove"><input type="hidden" name="id" value="' . (int) $i['id'] . '"><input type="hidden" name="student_id" value="' . (int) $m['student_id'] . '"><button class="btn btn-sm" aria-label="Remove ' . e($m['name']) . '">Remove</button></form>';
        }
        echo '</li>';
    }
    echo '</ul>';
    if (iv_can_edit()) {
        $q = trim((string) ($_GET['find'] ?? ''));
        echo '<form method="get" class="iv-inline">' . iv_hidden_org() . '<input type="hidden" name="p" value="idea"><input type="hidden" name="id" value="' . (int) $i['id'] . '">'
           . '<input type="search" name="find" value="' . e($q) . '" placeholder="Find a student by name, PIN or email" aria-label="Find a student"><button class="btn btn-sm">Find</button></form>';
        if ($q !== '') {
            $c = iv_team_candidates($org, $i, $q);
            echo '<ul class="iv-team pick">';
            if (!$c) {
                echo '<li class="iv-empty">No free student matches. (A student sits on one team per round.)</li>';
            }
            foreach ($c as $s) {
                echo '<li><span><strong>' . e($s['name']) . '</strong><small>' . e(trim($s['roll_no'] . ' · ' . ($s['dept_name'] ?? '') . ' · ' . ($s['college_name'] ?? ''), ' ·')) . '</small></span>'
                   . '<form method="post">' . fabo_csrf_field() . '<input type="hidden" name="a" value="team-add"><input type="hidden" name="id" value="' . (int) $i['id'] . '"><input type="hidden" name="student_id" value="' . (int) $s['id'] . '"><button class="btn btn-sm btn-primary">Add</button></form></li>';
            }
            echo '</ul>';
        }
    }
    echo '</section>';
}

/* ================================================================ import */

function iv_page_import(int $org): void
{
    $forms = iv_inc_forms($org);
    $form = (int) ($_GET['form'] ?? 0);
    echo '<section class="iv-card"><h2>Students from Incubation Management</h2>';
    if (!$forms) {
        echo '<p class="muted">This organisation has no Incubation applications to read. Upload a sheet on <a href="' . e(iv_url('students')) . '">Students</a> instead.</p></section>';
        return;
    }
    echo '<p class="muted">Pick the form the students registered through. Each student is filed once, matched on PIN / roll number, then email, then phone, so repeated registrations do not make duplicates, and running it again only updates.</p>'
       . '<form method="get" class="iv-filters">' . iv_hidden_org() . '<input type="hidden" name="p" value="import"><select name="form" onchange="this.form.submit()"><option value="0">— choose a form —</option>';
    foreach ($forms as $f) {
        echo '<option value="' . (int) $f['id'] . '"' . ((int) $f['id'] === $form ? ' selected' : '') . '>' . e($f['name'] . ' (' . fabo_thousands((int) $f['n']) . ' applications)') . '</option>';
    }
    echo '</select><noscript><button class="btn">Open</button></noscript></form>';
    if ($form && in_array($form, array_map(static fn($f) => (int) $f['id'], $forms), true) && iv_can_edit()) {
        $fields = iv_inc_fields($org, $form);
        echo '<form method="post" class="iv-form" data-busy="Importing… this can take a minute for thousands of rows.">' . fabo_csrf_field() . '<input type="hidden" name="a" value="inc-import"><input type="hidden" name="form_id" value="' . $form . '">'
           . '<table class="iv-table"><thead><tr><th>Question on the form</th><th>File it as</th></tr></thead><tbody>';
        foreach ($fields as $uid => [$label, $guess]) {
            echo '<tr><td>' . e($label) . '</td><td><select name="map[' . e($uid) . ']">';
            foreach (iv_inc_targets() as $k => $l) {
                echo '<option value="' . e($k) . '"' . ($k === $guess ? ' selected' : '') . '>' . e($l) . '</option>';
            }
            echo '</select></td></tr>';
        }
        echo '</tbody></table><p class="muted sm">A college answer like "155 GOVT POLYTECHNIC, PILLARIPATTU" is read as code 155 and its name. Students join the HoD dashboard for the branch in their PIN, once it exists (24045-<b>CM</b>-042 → Computer Engineering).</p>'
           . '<label class="iv-check"><input type="checkbox" name="make" value="1" checked> Create missing colleges</label><p class="muted sm">Departments are not created from student sheets: they are the HoD dashboards. Each student joins the dashboard for their PIN’s branch as soon as it exists.</p>'
           . '<label class="field" style="max-width:260px"><span>New colleges are</span><select name="kind">';
        foreach (iv_college_kinds() as $k) {
            echo '<option>' . e($k) . '</option>';
        }
        echo '</select></label><div class="iv-actions"><button class="btn btn-primary">Import students</button></div></form>';
    }
    echo '</section>';
}

/* ============================================================== settings */

function iv_page_settings(int $org): void
{
    echo '<div class="iv-grid2"><section class="iv-card"><h2>Head of Department sign-in</h2><p class="muted">Send this to any Head who lost their link; they can ask for a new one from it.</p>' . iv_copy(iv_portal_url($org)) . '</section>';
    echo '<section class="iv-card"><h2>Gems</h2><p class="muted">' . count(iv_gems($org)) . ' active. Change a Gem\'s rubric or questions, or try it on a sample idea, on <a href="' . e(iv_url('gems')) . '">Gems</a>.</p></section></div>';
    $cols = iv_colleges($org);
    echo '<section class="iv-card"><h2>Colleges and schools (' . count($cols) . ')</h2><div class="iv-tablewrap"><table class="iv-table"><thead><tr><th>Code</th><th>Name</th><th>District</th><th>Type</th><th class="n">Departments</th><th></th></tr></thead><tbody>';
    $dc = [];
    foreach (fabo_all('SELECT college_id, COUNT(*) n FROM iv_departments WHERE org_id = ? GROUP BY college_id', 'i', [$org]) as $r) {
        $dc[(int) $r['college_id']] = (int) $r['n'];
    }
    if (!$cols) {
        echo '<tr><td colspan="6" class="iv-empty">None yet: they are made with the dashboards or on import.</td></tr>';
    }
    foreach ($cols as $c) {
        if (iv_can_edit()) {
            $fid = 'cf' . (int) $c['id'];
            echo '<tr><td><input form="' . $fid . '" name="code" value="' . e((string) $c['code']) . '" maxlength="20" class="iv-in sm" aria-label="Code"></td>'
               . '<td><input form="' . $fid . '" name="name" value="' . e($c['name']) . '" maxlength="190" class="iv-in" required aria-label="Name"></td>'
               . '<td><input form="' . $fid . '" name="district" value="' . e($c['district']) . '" maxlength="80" class="iv-in" aria-label="District"></td><td><select form="' . $fid . '" name="kind" aria-label="Type">';
            foreach (iv_college_kinds() as $k) {
                echo '<option' . ($k === $c['kind'] ? ' selected' : '') . '>' . e($k) . '</option>';
            }
            echo '</select></td><td class="n"><a href="' . e(iv_url('dashboards', ['college' => (int) $c['id']])) . '">' . (int) ($dc[(int) $c['id']] ?? 0) . '</a></td><td class="act">'
               . '<form method="post" id="' . $fid . '" class="inline">' . fabo_csrf_field() . '<input type="hidden" name="a" value="college-save"><input type="hidden" name="id" value="' . (int) $c['id'] . '"><button class="btn btn-sm">Save</button></form>'
               . (empty($dc[(int) $c['id']]) ? '<form method="post" class="inline" data-confirm="Remove this college?">' . fabo_csrf_field() . '<input type="hidden" name="a" value="college-delete"><input type="hidden" name="id" value="' . (int) $c['id'] . '"><button class="btn btn-sm btn-danger-soft">Remove</button></form>' : '')
               . '</td></tr>';
        } else {
            echo '<tr><td>' . e((string) $c['code']) . '</td><td>' . e($c['name']) . '</td><td>' . e($c['district']) . '</td><td>' . e($c['kind']) . '</td><td class="n">' . (int) ($dc[(int) $c['id']] ?? 0) . '</td><td></td></tr>';
        }
    }
    echo '</tbody></table></div></section>';
    echo '<section class="iv-card"><h2>Audit</h2><div class="iv-tablewrap"><table class="iv-table"><thead><tr><th>When</th><th>Who</th><th>What</th><th>Detail</th></tr></thead><tbody>';
    $log = fabo_all('SELECT * FROM iv_audit WHERE org_id = ? ORDER BY id DESC LIMIT 100', 'i', [$org]);
    if (!$log) {
        echo '<tr><td colspan="4" class="iv-empty">Nothing yet.</td></tr>';
    }
    foreach ($log as $l) {
        echo '<tr><td class="nowrap sm">' . e(iv_when($l['created_at'])) . '</td><td>' . e($l['actor']) . '</td><td>' . e(str_replace('_', ' ', $l['action'])) . '</td><td class="sm">' . e($l['subject']) . '</td></tr>';
    }
    echo '</tbody></table></div></section>';
}

/* ================================================================= files */

function iv_csv_out(string $name, array $head, iterable $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    $out = fopen('php://output', 'wb');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $head, ',', '"', '');
    foreach ($rows as $r) {
        // A cell starting with = + - @ would run as a formula in Excel.
        fputcsv($out, array_map(static fn($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : (string) $v, $r), ',', '"', '');
    }
    fclose($out);
    exit;
}

function iv_csv(int $org, string $what): void
{
    $stamp = date('Y-m-d');
    switch ($what) {
        case 'dash-template':
            iv_csv_out('hod-dashboards-template.csv', ['CODE', 'POLYTECHNIC', 'BRANCH', 'NAME OF THE HEAD OF SECTION', 'CONTACT NUMBER', 'EMAIL ID'], [
                ['155', 'GOVT POLYTECHNIC, PILLARIPATTU', 'CM', 'Dr. A. Kumar', '9876543210', 'hod.cm.155@example.org'],
                ['155', 'GOVT POLYTECHNIC, PILLARIPATTU', 'ECE', 'Smt. B. Lakshmi', '9876543211', 'hod.ec.155@example.org'],
            ]);
            // no break: iv_csv_out exits
        case 'student-template':
            iv_csv_out('students-template.csv', ['Email Address', 'Code and Name of the Polytechnic', 'Name of the District', 'Full Name of the Student', 'PIN (XXXXX-XX-XXX)', 'Email ID', 'Phone Number', 'Remarks'], [
                ['ravi@example.org', '155 GOVT POLYTECHNIC, PILLARIPATTU', 'Chittoor', 'Ravi Teja', '24155-CM-001', 'ravi@example.org', '9000000001', ''],
            ]);
        case 'dashboards':
            $round = iv_round_current($org);
            $prog = $round ? iv_dept_progress($org, (int) $round['id']) : [];
            iv_csv_out('hod-dashboards-' . $stamp . '.csv', ['CODE', 'POLYTECHNIC', 'BRANCH', 'NAME OF THE HEAD OF SECTION', 'CONTACT NUMBER', 'EMAIL ID', 'Department', 'District', 'Signed up', 'Last sign-in', 'Ideas', 'Validated', 'Sent forward'],
                array_map(static fn($d) => [$d['college_code'], $d['college_name'], $d['branch'], $d['hod_name'], $d['hod_phone'], $d['hod_email'], $d['name'], $d['district'],
                                            (int) $d['hod_ready'] ? 'yes' : 'no', $d['last_login_at'], $prog[(int) $d['id']]['ideas'] ?? 0, $prog[(int) $d['id']]['validated'] ?? 0, $prog[(int) $d['id']]['forwarded'] ?? 0], iv_departments($org)));
        case 'students':
            $f = ['college' => (int) ($_GET['college'] ?? 0), 'q' => (string) ($_GET['q'] ?? '')];
            [$rows] = iv_students($org, $f, 200000);
            iv_csv_out('students-' . $stamp . '.csv', ['Name', 'PIN / Roll No', 'Email', 'Second email', 'Phone', 'College', 'District', 'Year', 'Remarks', 'Source'],
                array_map(static fn($s) => [$s['name'], $s['roll_no'], $s['email'], $s['alt_email'], $s['phone'], $s['college_name'], $s['district'], $s['year_of_study'],
                                            (string) $s['notes'], $s['source']], $rows));
        case 'repeats':
            // An upload's rows that were the same student as an earlier row of the file, beside that row; and rows skipped.
            $imp = fabo_one('SELECT * FROM iv_imports WHERE org_id = ? AND id = ?', 'ii', [$org, (int) ($_GET['import'] ?? 0)]);
            if (!$imp) {
                fabo_deny('That upload is not here.', 404);
            }
            $rows = fabo_all('SELECT r.*, f.name AS first_name, f.roll_typed AS first_roll, f.email AS first_email, s.name AS student_name
                                FROM iv_import_rows r
                                LEFT JOIN iv_import_rows f ON f.import_id = r.import_id AND f.line = r.first_line
                                LEFT JOIN iv_students s ON s.id = r.student_id AND s.org_id = r.org_id
                               WHERE r.import_id = ? AND r.org_id = ? AND r.outcome IN ("repeat","skipped") ORDER BY r.first_line, r.line', 'ii', [(int) $imp['id'], $org]);
            iv_csv_out('repeat-rows-' . $stamp . '.csv', ['Row in sheet', 'What happened', 'Name (as in this row)', 'PIN (as in this row)', 'Email', 'Phone', 'College',
                                                          'Same student as row', 'Name in that row', 'PIN in that row', 'Email in that row', 'Kept in the list as'],
                array_map(static fn($r) => [$r['line'], $r['outcome'] === 'repeat' ? 'Same student registered again — kept once' : 'Skipped: ' . $r['note'],
                                            $r['name'], $r['roll_typed'], $r['email'], $r['phone'], $r['college'],
                                            $r['outcome'] === 'repeat' ? $r['first_line'] : '', (string) $r['first_name'], (string) $r['first_roll'], (string) $r['first_email'], (string) $r['student_name']], $rows));
        case 'ideas':
        case 'pipeline':
            $rid = (int) ($_GET['round'] ?? 0);
            $f = ['round' => $rid] + ($what === 'pipeline' ? ['stage' => 'any', 'sort' => 'score'] : []);
            $rows = [];
            foreach (iv_ideas($org, $f, 100000) as $i) {
                $team = $what === 'pipeline' ? implode('; ', array_map(static fn($m) => $m['name'] . ($m['roll_no'] ? ' (' . $m['roll_no'] . ')' : '') . ($m['role'] === 'lead' ? ' [lead]' : ''), iv_team($org, (int) $i['id']))) : '';
                $rows[] = [$i['title'], $i['student_name'], $i['roll_no'], $i['college_name'], $i['dept_name'], iv_statuses()[$i['status']] ?? $i['status'],
                           $i['ai_score'] === null ? '' : $i['ai_score'], $i['ai_max'] ?? '', $i['hod_rank'] ?? '', $i['stage'] ? iv_stages()[$i['stage']] : 'With HoD', $team, $i['submitted_at']];
            }
            iv_csv_out($what . '-' . $stamp . '.csv', ['Idea', 'Student', 'Roll No', 'College', 'Department', 'HoD decision', 'AI score', 'Out of', 'Rank in dept', 'Stage', 'Team', 'Submitted'], $rows);
    }
}

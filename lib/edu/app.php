<?php
/**
 * Academic — the staff desk.
 *
 *   overview      every institution at a glance, the Campus portal link
 *   institutions  institutions and their departments (?id= opens one)
 *   people        students, faculty, mentors, institution admins (filters, add)
 *   person        one person: details, roles, mentor links, their account
 *   import        a CSV of people, with a template
 *   settings      which Campus portals are open, the address, sample data, audit
 *
 * academic.php (a client granted the module, or their team) and
 * admin/academic.php (the administrator, for any organisation holding it)
 * both land in edu_main(). The data and rules are in core.php.
 */

require_once __DIR__ . '/../ui.php';
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/app_lms.php';
require_once __DIR__ . '/app_game.php';
require_once __DIR__ . '/app_p4.php';
require_once __DIR__ . '/app_privacy.php';

function edu_desk_pages(): array
{
    return edu_nav_pages() + ['person' => 'Person', 'course' => 'Course', 'lesson' => 'Lesson'];
}

function edu_url(string $page, array $q = []): string
{
    $q = ['p' => $page] + $q;
    if (!empty($GLOBALS['edu_viewer']['is_admin'])) {
        $q['org'] = $GLOBALS['edu_org'];
    }
    return 'academic.php?' . http_build_query($q);
}

function edu_can_edit(): bool
{
    return ($GLOBALS['edu_grant'] ?? 'none') === 'edit';
}

function edu_actor(): string
{
    $v = $GLOBALS['edu_viewer'] ?? [];
    return !empty($v['is_admin']) ? 'Administrator' : (string) ($v['client']['name'] ?? '?') . ' #' . (int) ($v['client']['id'] ?? 0);
}

function edu_flash(string $tone, string $msg, string $extra = ''): void
{
    $_SESSION['edu_flash'] = [$tone, $msg, $extra];
}

function edu_go(string $page, array $q = []): void
{
    header('Location: ' . edu_url($page, $q), true, 303);
    exit;
}

/* ================================================================== main */

function edu_main(array $viewer): void
{
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store, private');
    edu_lms_install();
    edu_game_install();
    edu_ideas_install();
    edu_privacy_install();
    $grant = edu_grant($viewer);
    if ($grant === 'none') {
        fabo_deny('You do not have access to Academic.', 403);
    }
    $org = edu_org($viewer);
    $GLOBALS['edu_viewer'] = $viewer;
    $GLOBALS['edu_grant'] = $grant;
    $GLOBALS['edu_org'] = $org;
    fabo_session();

    $page = (string) ($_GET['p'] ?? 'overview');
    // A course file (cover, lesson document): sent here, before any page is drawn.
    if ($page === 'file' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        edu_desk_file($org);
    }
    if (!isset(edu_desk_pages()[$page])) {
        $page = 'overview';
    }
    if ($page === 'import' && ($_GET['csv'] ?? '') === 'template') {
        edu_csv_template();
    }
    edu_reports_csv_maybe($org, $page);
    if ($page === 'people' && ($_GET['csv'] ?? '') === 'export') {
        edu_csv_export($org);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        fabo_csrf_check();
        if (!edu_can_edit()) {
            fabo_deny('View only: you can read Academic but not change it.', 403);
        }
        edu_post($org, $page);
    }

    $orgName = edu_org_name($org);
    fabo_head($viewer, edu_desk_pages()[$page] . ' · Academic', 'edu-' . $page);
    echo '<link rel="stylesheet" href="' . e(fabo_asset(fabo_root_prefix() . 'assets/academic.css')) . '">';
    echo '<main class="wrap-page edu' . (edu_can_edit() ? '' : ' edu-ro') . '" id="main">';
    // The course builder draws its own header.
    if (!in_array($page, ['course', 'lesson'], true)) {
        edu_hero($page, $orgName);
    }
    $flash = $_SESSION['edu_flash'] ?? null;
    unset($_SESSION['edu_flash']);
    if ($flash) {
        echo '<div class="edu-flash ' . e($flash[0]) . '" role="status">' . e($flash[1]) . ($flash[2] ?? '') . '</div>';
    }
    if (!edu_can_edit()) {
        echo '<div class="edu-flash info"><strong>View only.</strong> You can read everything here; changes are made by the owner.</div>';
    }
    ('edu_page_' . $page)($org);
    echo '</main>';
    echo '<script src="' . e(fabo_asset(fabo_root_prefix() . 'assets/academic.js')) . '" defer></script>';
    if (in_array($page, ['courses', 'course', 'lesson'], true)) {
        echo '<script src="' . e(fabo_asset(fabo_root_prefix() . 'assets/academic-builder.js')) . '" defer></script>';
    }
    fabo_foot();
}

function edu_hero(string $page, string $orgName): void
{
    $lead = [
        'overview' => 'Institutions, departments and the people in them — students, faculty, mentors and institution admins.',
        'institutions' => 'The colleges, universities and polytechnics in this organisation, and their departments.',
        'people' => 'Everyone in Academic. One person, one record, however many roles they hold.',
        'person' => 'Details, roles, mentor links and their Campus portal account.',
        'import' => 'Bring in students, faculty or mentors from a spreadsheet saved as CSV.',
        'settings' => 'Who may sign in to the Campus portal, its address, and sample data to show.',
        'courses' => 'Courses students take in the Campus portal: lessons, videos, links and quizzes, with a certificate at the end.',
        'course' => 'Lessons, who the course is for, and how students are doing.',
        'lesson' => 'What the student reads, watches or answers.',
        'mentoring' => 'Sessions mentors offer and students book, across the organisation.',
        'rewards' => 'XP, levels, coins and badges: what students earn, how institutions and students rank, and the earning rules.',
        'store' => 'What students can spend their coins on, and the orders waiting for you.',
        'ideas' => 'Startup ideas students write, and the ones they have sent to Incubation Management.',
        'reports' => 'Figures per institution for any period, counted from the records: for councils, accreditation returns and reviews.',
        'privacy' => 'Data requests, the privacy notice and consent, two-step sign-in, and who opened whose records.',
    ][$page] ?? '';
    echo '<header class="edu-hero"><div><span class="edu-eyebrow">Academic' . ($orgName !== '' ? ' · ' . e($orgName) : '') . '</span>'
       . '<h1>' . e($page === 'overview' ? 'Academic overview' : edu_desk_pages()[$page]) . '</h1><p>' . e($lead) . '</p></div>';
    if (!empty($GLOBALS['edu_viewer']['is_admin'])) {
        echo '<form method="get" class="edu-org"><input type="hidden" name="p" value="' . e($page === 'person' ? 'people' : $page) . '"><label>Organisation '
           . '<select name="org" onchange="this.form.submit()">';
        foreach (edu_orgs() as $id => $n) {
            echo '<option value="' . $id . '"' . ($id === $GLOBALS['edu_org'] ? ' selected' : '') . '>' . e($n) . '</option>';
        }
        echo '</select></label></form>';
    }
    echo '</header>';
}

/* ================================================================== POST */

function edu_post(int $org, string $page): void
{
    $a = (string) ($_POST['a'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $back = [$page, array_filter(['id' => (int) ($_GET['id'] ?? 0) ?: null])];
    try {
        edu_post_lms($org, $a);
        edu_post_game($org, $a);
        edu_post_p4($org, $a);
        edu_post_privacy($org, $a);
        switch ($a) {
            case 'inst-save':
                $iid = edu_institution_save($org, $_POST, $id ?: null);
                edu_audit($org, edu_actor(), $id ? 'inst-edit' : 'inst-add', (string) $_POST['name']);
                edu_flash('ok', $id ? 'Saved.' : 'Institution added. Add its departments next.');
                edu_go('institutions', ['id' => $iid]);
            case 'inst-delete':
                $i = edu_institution($org, $id);
                if (($_POST['confirm'] ?? '') !== 'DELETE') {
                    throw new InvalidArgumentException('Type DELETE to remove the institution.');
                }
                edu_institution_delete($org, $id);
                edu_audit($org, edu_actor(), 'inst-delete', (string) ($i['name'] ?? $id));
                edu_flash('ok', 'Removed ' . ($i['name'] ?? 'the institution') . '. People who held roles there are kept as records.');
                edu_go('institutions');
            case 'dept-save':
                $inst = (int) ($_POST['institution_id'] ?? 0);
                edu_department_save($org, $inst, (string) ($_POST['name'] ?? ''), $id ?: null);
                edu_flash('ok', $id ? 'Department renamed.' : 'Department added.');
                edu_go('institutions', ['id' => $inst]);
            case 'dept-delete':
                $d = edu_department($org, $id);
                edu_department_delete($org, $id);
                edu_audit($org, edu_actor(), 'dept-delete', (string) ($d['name'] ?? $id));
                edu_flash('ok', 'Department removed. Its people stay in the institution.');
                edu_go('institutions', ['id' => (int) ($d['institution_id'] ?? 0)]);
            case 'person-add':
                $pid = edu_person_save($org, $_POST);
                edu_role_add($org, $pid, (string) ($_POST['role'] ?? ''), (int) ($_POST['institution_id'] ?? 0),
                             (int) ($_POST['department_id'] ?? 0), (string) ($_POST['title'] ?? ''));
                edu_audit($org, edu_actor(), 'person-add', (string) $_POST['name']);
                if (!empty($_POST['invite']) && trim((string) ($_POST['email'] ?? '')) !== '') {
                    [, $words] = edu_invite_send($org, $pid);
                    edu_flash('ok', 'Added. ' . $words);
                } else {
                    edu_flash('ok', 'Added.');
                }
                edu_go('person', ['id' => $pid]);
            case 'person-save':
                edu_person_save($org, $_POST, $id);
                edu_flash('ok', 'Saved.');
                edu_go('person', ['id' => $id]);
            case 'role-add':
                edu_role_add($org, $id, (string) ($_POST['role'] ?? ''), (int) ($_POST['institution_id'] ?? 0),
                             (int) ($_POST['department_id'] ?? 0), (string) ($_POST['title'] ?? ''));
                edu_audit($org, edu_actor(), 'role-add', $id . ' ' . $_POST['role']);
                edu_flash('ok', 'Role added.');
                edu_go('person', ['id' => $id]);
            case 'role-remove':
                $r = edu_role_remove($org, (int) ($_POST['role_id'] ?? 0));
                edu_audit($org, edu_actor(), 'role-remove', $id . ' ' . ($r['role'] ?? ''));
                edu_flash('ok', 'Role removed.');
                edu_go('person', ['id' => $id]);
            case 'mentor-assign':
                $added = 0;
                $missing = [];
                foreach (preg_split('/[\s,;]+/', (string) ($_POST['students'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $w) {
                    $s = edu_find_student($org, $w);
                    if ($s && edu_mentor_assign($org, $id, (int) $s['id'])) {
                        $added++;
                    } else {
                        $missing[] = $w;
                    }
                }
                edu_flash($missing ? 'err' : 'ok', $added . ' student' . ($added === 1 ? '' : 's') . ' linked.'
                          . ($missing ? ' Not found as students: ' . implode(', ', array_slice($missing, 0, 10)) . (count($missing) > 10 ? '…' : '') . '.' : ''));
                edu_go('person', ['id' => $id]);
            case 'mentor-unassign':
                $mentor = (int) ($_POST['mentor_id'] ?? 0);
                $student = (int) ($_POST['student_id'] ?? 0);
                edu_mentor_unassign($org, $mentor, $student);
                edu_flash('ok', 'Link removed.');
                edu_go('person', ['id' => $id]);
            case 'invite':
                [$ok, $words] = edu_invite_send($org, $id);
                edu_audit($org, edu_actor(), 'invite', (string) $id);
                edu_flash($ok ? 'ok' : 'err', $words);
                edu_go('person', ['id' => $id]);
            case 'invite-link':
                $url = edu_invite_make($org, $id);
                edu_audit($org, edu_actor(), 'invite-link', (string) $id);
                edu_flash('ok', 'A new sign-in link (works for ' . EDU_INVITE_DAYS . ' days; any earlier link stops working):',
                          ' <span class="edu-copy"><input readonly value="' . e($url) . '" aria-label="Sign-in link"><button type="button" class="btn btn-sm" data-copy>Copy</button></span>');
                edu_go('person', ['id' => $id]);
            case 'temp-pass':
                $p = edu_person($org, $id);
                if (!$p || (string) $p['email'] === '') {
                    throw new InvalidArgumentException('Add an email address first: it is what they sign in with.');
                }
                $plain = fabo_make_password();
                edu_password_set($org, $id, $plain, true);
                edu_audit($org, edu_actor(), 'temp-pass', (string) $id);
                edu_flash('ok', 'Temporary password for ' . $p['email'] . ' (shown once; they choose their own at first sign-in): ',
                          ' <span class="edu-copy"><input readonly value="' . e($plain) . '" aria-label="Temporary password"><button type="button" class="btn btn-sm" data-copy>Copy</button></span>');
                edu_go('person', ['id' => $id]);
            case 'status':
                edu_person_status($org, $id, (string) ($_POST['status'] ?? 'active'));
                edu_audit($org, edu_actor(), 'status', $id . ' ' . $_POST['status']);
                edu_flash('ok', ($_POST['status'] ?? '') === 'suspended' ? 'Suspended: they cannot sign in.' : 'Active again.');
                edu_go('person', ['id' => $id]);
            case 'person-delete':
                $p = edu_person($org, $id);
                edu_person_delete($org, $id);
                edu_audit($org, edu_actor(), 'person-delete', (string) ($p['name'] ?? $id));
                edu_flash('ok', 'Removed ' . ($p['name'] ?? 'them') . '.');
                edu_go('people');
            case 'invite-list':
                $f = edu_people_filter();
                $sent = 0;
                $skipped = 0;
                foreach (edu_people($org, $f + ['limit' => 100]) as $p) {
                    if ((string) $p['email'] === '' || $p['pass_hash'] !== null || $p['status'] !== 'active' || str_ends_with((string) $p['email'], '.example')) {
                        $skipped++;
                        continue;
                    }
                    [$ok] = edu_invite_send($org, (int) $p['id']);
                    $sent += $ok ? 1 : 0;
                }
                edu_audit($org, edu_actor(), 'invite-list', $sent . ' sent');
                edu_flash('ok', $sent . ' sign-in link' . ($sent === 1 ? '' : 's') . ' sent' . ($skipped ? '; ' . $skipped . ' skipped (no email, already signed up, suspended or a sample address)' : '') . '.');
                header('Location: ' . edu_url('people', $f), true, 303);
                exit;
            case 'import':
                $up = $_FILES['csv'] ?? null;
                if (!$up || ($up['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($up['tmp_name'])) {
                    throw new InvalidArgumentException('Choose a CSV file to import.');
                }
                if (!preg_match('/\.(csv|txt)$/i', (string) $up['name'])) {
                    throw new InvalidArgumentException('Save the sheet as CSV first (File → Save as → CSV), then import that file.');
                }
                $rep = edu_import_csv($org, $up['tmp_name'], ['role' => (string) ($_POST['role'] ?? 'student'),
                                                             'institution' => (int) ($_POST['institution_id'] ?? 0), 'create' => !empty($_POST['create'])]);
                edu_audit($org, edu_actor(), 'import', $rep['added'] . ' added, ' . $rep['updated'] . ' updated, ' . count($rep['skipped']) . ' skipped');
                $_SESSION['edu_import'] = $rep;
                edu_flash(count($rep['skipped']) ? 'info' : 'ok', $rep['added'] . ' added, ' . $rep['updated'] . ' updated'
                          . (count($rep['skipped']) ? ', ' . count($rep['skipped']) . ' skipped (listed below).' : '.'));
                edu_go('import');
            case 'portal':
                foreach (array_keys(edu_roles_all()) as $r) {
                    edu_set($org, 'portal_' . $r, !empty($_POST['portal_' . $r]) ? '1' : '0');
                }
                edu_set($org, 'portal_welcome', mb_substr(trim((string) ($_POST['welcome'] ?? '')), 0, 600));
                edu_audit($org, edu_actor(), 'portal', '');
                edu_flash('ok', 'Saved.');
                edu_go('settings');
            case 'sample-load':
                $r = edu_sample_load($org);
                edu_audit($org, edu_actor(), 'sample-load', '');
                edu_flash('ok', 'Sample data loaded: ' . $r['institutions'] . ' institutions and ' . $r['people'] . ' people. Their emails end in .example, so nothing is ever sent to them.');
                edu_go('overview');
            case 'sample-remove':
                $n = edu_sample_remove($org);
                edu_audit($org, edu_actor(), 'sample-remove', (string) $n);
                edu_flash('ok', 'Sample data removed (' . $n . ' people).');
                edu_go('settings');
        }
        throw new InvalidArgumentException('Nothing to do.');
    } catch (InvalidArgumentException $e) {
        edu_flash('err', $e->getMessage());
    } catch (mysqli_sql_exception $e) {
        error_log('FABO edu post ' . $a . ': ' . $e->getMessage());
        edu_flash('err', 'That could not be saved. ' . (str_contains($e->getMessage(), 'Duplicate') ? 'It is already there.' : 'Try again.'));
    }
    edu_go($back[0], $back[1]);
}

/* =============================================================== helpers */

function edu_people_filter(): array
{
    $src = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
    return array_filter(['role' => (string) ($src['role'] ?? ''), 'inst' => (int) ($src['inst'] ?? 0), 'dept' => (int) ($src['dept'] ?? 0),
                         'q' => trim((string) ($src['q'] ?? ''))]);
}

function edu_role_chip(array $r): string
{
    $where = trim($r['inst_name'] . ($r['dept_name'] !== '' ? ' · ' . $r['dept_name'] : ''), ' ·');
    return '<span class="edu-role r-' . e($r['role']) . '" title="' . e($where !== '' ? $where : 'Across the organisation') . '">'
         . e(edu_role_label($r['role'])) . '</span>';
}

function edu_account_pill(array $p): string
{
    if ($p['status'] === 'suspended') {
        return '<span class="pill red">Suspended</span>';
    }
    if ($p['pass_hash'] !== null) {
        return $p['last_login_at'] ? '<span class="pill green">Signed in ' . e(fabo_short_date($p['last_login_at'])) . '</span>' : '<span class="pill green">Set up</span>';
    }
    if ($p['invite_expires'] && strtotime($p['invite_expires']) > time()) {
        return '<span class="pill amber">Invited</span>';
    }
    return (string) $p['email'] === '' ? '<span class="pill grey">No email</span>' : '<span class="pill grey">Not invited</span>';
}

/** Institution and department pickers, with the department list following the institution (academic.js). */
function edu_inst_dept_fields(int $org, int $inst = 0, int $dept = 0, bool $allowNone = false): string
{
    $insts = edu_institutions($org);
    $map = [];
    foreach (edu_departments($org) as $d) {
        $map[(int) $d['institution_id']][] = [(int) $d['id'], $d['name']];
    }
    $h = '<label class="field"><span>Institution</span><select name="institution_id" data-edu-inst>'
       . '<option value="0">' . ($allowNone ? 'Across the organisation (mentors and council)' : 'Choose…') . '</option>';
    foreach ($insts as $i) {
        $h .= '<option value="' . (int) $i['id'] . '"' . ((int) $i['id'] === $inst ? ' selected' : '') . '>' . e($i['name']) . '</option>';
    }
    $h .= '</select></label><label class="field"><span>Department</span><select name="department_id" data-edu-dept data-value="' . $dept . '">'
        . '<option value="0">None / whole institution</option>';
    foreach ($map[$inst] ?? [] as [$did, $dn]) {
        $h .= '<option value="' . $did . '"' . ($did === $dept ? ' selected' : '') . '>' . e($dn) . '</option>';
    }
    $h .= '</select></label><script type="application/json" data-edu-depts>' . json_encode((object) $map, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . '</script>';
    return $h;
}

function edu_role_select(string $sel = 'student'): string
{
    $h = '<label class="field"><span>Role</span><select name="role" data-edu-role>';
    foreach (edu_roles_all() as $k => $l) {
        $h .= '<option value="' . $k . '"' . ($k === $sel ? ' selected' : '') . '>' . e($l) . '</option>';
    }
    return $h . '</select></label>';
}

/* ================================================================= pages */

function edu_page_overview(int $org): void
{
    $t = edu_totals($org);
    $insts = edu_institutions($org);
    $counts = edu_counts($org);
    echo '<section class="edu-kpis">'
       . edu_kpi('Institutions', $t['institutions'], 't-inst', edu_url('institutions'))
       . edu_kpi('Students', $t['student'], 't-student', edu_url('people', ['role' => 'student']))
       . edu_kpi('Faculty', $t['faculty'], 't-faculty', edu_url('people', ['role' => 'faculty']))
       . edu_kpi('Mentors', $t['mentor'], 't-mentor', edu_url('people', ['role' => 'mentor']))
       . edu_kpi('Accounts set up', $t['accounts'] . ' / ' . $t['people'], 't-acc')
       . edu_kpi('Signed in, last 30 days', $t['recent'], 't-recent')
       . '</section>';

    if (!$insts) {
        echo '<section class="edu-card edu-start"><h2>Get started</h2><ol>'
           . '<li><a href="' . e(edu_url('institutions')) . '">Add your institutions</a> and their departments.</li>'
           . '<li><a href="' . e(edu_url('import')) . '">Import students and faculty</a> from a spreadsheet, or add them one by one on People.</li>'
           . '<li>Send them their sign-in links, and share the <a href="' . e(edu_url('settings')) . '">Campus portal</a> address.</li></ol>';
        if (edu_can_edit()) {
            echo '<form method="post" action="' . e(edu_url('settings')) . '" class="edu-inline">' . fabo_csrf_field()
               . '<input type="hidden" name="a" value="sample-load"><button class="btn">Or load sample data to explore</button>'
               . '<span class="muted">Three colleges, departments, faculty, mentors and 36 students. Removable in one click.</span></form>';
        }
        echo '</section>';
    } else {
        echo '<section class="edu-card"><div class="edu-card-h"><h2>Institutions</h2><a class="btn btn-sm" href="' . e(edu_url('institutions')) . '">Manage</a></div>'
           . '<div class="edu-tablewrap"><table class="edu-table"><thead><tr><th>Institution</th><th>Type</th><th>District</th><th class="n">Departments</th>'
           . '<th class="n">Students</th><th class="n">Faculty</th><th class="n">Admins</th><th>Share of students</th></tr></thead><tbody>';
        $depts = [];
        foreach (edu_departments($org) as $d) {
            $depts[(int) $d['institution_id']] = ($depts[(int) $d['institution_id']] ?? 0) + 1;
        }
        $max = max(1, $t['student']);
        foreach ($insts as $i) {
            $c = $counts[(int) $i['id']] ?? [];
            $s = (int) ($c['student'] ?? 0);
            echo '<tr><td><a href="' . e(edu_url('institutions', ['id' => (int) $i['id']])) . '">' . e($i['name']) . '</a>'
               . ($i['status'] === 'inactive' ? ' <span class="pill grey">Inactive</span>' : '') . '</td><td>' . e($i['kind']) . '</td><td>' . e($i['district']) . '</td>'
               . '<td class="n">' . (int) ($depts[(int) $i['id']] ?? 0) . '</td>'
               . '<td class="n"><a href="' . e(edu_url('people', ['role' => 'student', 'inst' => (int) $i['id']])) . '">' . fabo_thousands($s) . '</a></td>'
               . '<td class="n">' . (int) ($c['faculty'] ?? 0) . '</td><td class="n">' . (int) ($c['inst_admin'] ?? 0) . '</td>'
               . '<td><span class="edu-bar"><i style="width:' . round($s / $max * 100) . '%"></i></span></td></tr>';
        }
        echo '</tbody></table></div></section>';
    }

    $url = edu_portal_url($org);
    echo '<section class="edu-card edu-portalcard"><div><h2>Campus portal</h2><p class="muted">Where students, faculty, mentors and institution admins sign in. '
       . 'Each person only sees what their role allows, in their own institution.</p>'
       . '<span class="edu-copy"><input readonly value="' . e($url) . '" aria-label="Campus portal address"><button type="button" class="btn btn-sm" data-copy>Copy</button>'
       . '<a class="btn btn-sm" href="' . e($url) . '" target="_blank" rel="noopener">Open</a></span></div><ul class="edu-portals">';
    foreach (edu_roles_all() as $k => $l) {
        echo '<li class="' . (edu_portal_on($org, $k) ? 'on' : 'off') . '"><span class="edu-role r-' . e($k) . '">' . e($l) . '</span>' . (edu_portal_on($org, $k) ? 'Open' : 'Switched off') . '</li>';
    }
    echo '</ul></section>';
}

function edu_page_institutions(int $org): void
{
    $id = (int) ($_GET['id'] ?? 0);
    $inst = $id ? edu_institution($org, $id) : null;
    if ($inst) {
        edu_institution_detail($org, $inst);
        return;
    }
    $insts = edu_institutions($org);
    $counts = edu_counts($org);
    $depts = [];
    foreach (edu_departments($org) as $d) {
        $depts[(int) $d['institution_id']][] = $d['name'];
    }
    echo '<div class="edu-grid">';
    foreach ($insts as $i) {
        $c = $counts[(int) $i['id']] ?? [];
        $ds = $depts[(int) $i['id']] ?? [];
        echo '<a class="edu-inst" href="' . e(edu_url('institutions', ['id' => (int) $i['id']])) . '"><span class="edu-inst-k">' . e($i['kind'])
           . ($i['code'] !== '' ? ' · ' . e($i['code']) : '') . ($i['status'] === 'inactive' ? ' · Inactive' : '') . '</span>'
           . '<strong>' . e($i['name']) . '</strong><span class="muted">' . e(trim($i['city'] . ($i['district'] !== '' && $i['district'] !== $i['city'] ? ', ' . $i['district'] : ''), ', ')) . '</span>'
           . '<span class="edu-inst-n"><b>' . (int) ($c['student'] ?? 0) . '</b> students <b>' . (int) ($c['faculty'] ?? 0) . '</b> faculty <b>' . count($ds) . '</b> departments</span></a>';
    }
    if (!$insts) {
        echo '<p class="edu-empty">No institutions yet.</p>';
    }
    echo '</div>';
    if (edu_can_edit()) {
        echo '<section class="edu-card"><h2>Add an institution</h2>';
        edu_institution_form(null);
        echo '</section>';
    }
}

function edu_institution_form(?array $i): void
{
    $v = static fn(string $k) => e((string) ($i[$k] ?? ''));
    echo '<form method="post" class="edu-form">' . fabo_csrf_field() . '<input type="hidden" name="a" value="inst-save">'
       . ($i ? '<input type="hidden" name="id" value="' . (int) $i['id'] . '">' : '')
       . '<div class="edu-fields"><label class="field wide"><span>Name</span><input name="name" required maxlength="190" value="' . $v('name') . '"></label>'
       . '<label class="field"><span>Type</span><select name="kind">';
    foreach (edu_inst_kinds() as $k) {
        echo '<option' . (($i['kind'] ?? 'College') === $k ? ' selected' : '') . '>' . e($k) . '</option>';
    }
    echo '</select></label><label class="field"><span>Code (AISHE / short code)</span><input name="code" maxlength="40" value="' . $v('code') . '"></label>'
       . '<label class="field"><span>City</span><input name="city" maxlength="80" value="' . $v('city') . '"></label>'
       . '<label class="field"><span>District</span><input name="district" maxlength="80" value="' . $v('district') . '"></label>'
       . '<label class="field"><span>Contact person</span><input name="contact_name" maxlength="120" value="' . $v('contact_name') . '"></label>'
       . '<label class="field"><span>Contact email</span><input type="email" name="contact_email" maxlength="160" value="' . $v('contact_email') . '"></label>'
       . '<label class="field"><span>Contact phone</span><input name="contact_phone" maxlength="40" value="' . $v('contact_phone') . '"></label>';
    if ($i) {
        echo '<label class="field"><span>Status</span><select name="status"><option value="active">Active</option><option value="inactive"'
           . ($i['status'] === 'inactive' ? ' selected' : '') . '>Inactive</option></select></label>';
    }
    echo '</div><button class="btn btn-primary">' . ($i ? 'Save' : 'Add institution') . '</button></form>';
}

function edu_institution_detail(int $org, array $i): void
{
    $iid = (int) $i['id'];
    $c = edu_counts($org)[$iid] ?? [];
    echo '<p class="edu-back"><a href="' . e(edu_url('institutions')) . '">← All institutions</a></p>'
       . '<section class="edu-card"><div class="edu-card-h"><div><span class="edu-inst-k">' . e($i['kind']) . ($i['code'] !== '' ? ' · ' . e($i['code']) : '') . '</span><h2>' . e($i['name']) . '</h2></div>'
       . '<a class="btn btn-sm" href="' . e(edu_url('people', ['inst' => $iid])) . '">People here</a></div>'
       . '<div class="edu-kpis four">' . edu_kpi('Students', (int) ($c['student'] ?? 0), 't-student', edu_url('people', ['inst' => $iid, 'role' => 'student']))
       . edu_kpi('Faculty', (int) ($c['faculty'] ?? 0), 't-faculty', edu_url('people', ['inst' => $iid, 'role' => 'faculty']))
       . edu_kpi('Mentors here', (int) ($c['mentor'] ?? 0), 't-mentor') . edu_kpi('Institution admins', (int) ($c['inst_admin'] ?? 0), 't-acc', edu_url('people', ['inst' => $iid, 'role' => 'inst_admin'])) . '</div>';
    if (edu_can_edit()) {
        edu_institution_form($i);
    }
    echo '</section>';

    $deptCounts = [];
    foreach (fabo_all('SELECT department_id, role, COUNT(DISTINCT person_id) AS n FROM edu_roles WHERE org_id = ? AND institution_id = ? GROUP BY department_id, role', 'ii', [$org, $iid]) as $r) {
        $deptCounts[(int) $r['department_id']][$r['role']] = (int) $r['n'];
    }
    echo '<section class="edu-card"><h2>Departments</h2><div class="edu-tablewrap"><table class="edu-table"><thead><tr><th>Department</th><th class="n">Students</th><th class="n">Faculty</th>'
       . (edu_can_edit() ? '<th></th>' : '') . '</tr></thead><tbody>';
    $depts = edu_departments($org, $iid);
    foreach ($depts as $d) {
        $dc = $deptCounts[(int) $d['id']] ?? [];
        echo '<tr><td>' . (edu_can_edit()
                ? '<form method="post" class="edu-rename">' . fabo_csrf_field() . '<input type="hidden" name="a" value="dept-save"><input type="hidden" name="id" value="' . (int) $d['id'] . '">'
                  . '<input type="hidden" name="institution_id" value="' . $iid . '"><input name="name" value="' . e($d['name']) . '" aria-label="Department name" maxlength="160"><button class="btn btn-sm">Rename</button></form>'
                : e($d['name']))
           . '</td><td class="n"><a href="' . e(edu_url('people', ['inst' => $iid, 'role' => 'student', 'dept' => (int) $d['id']])) . '">' . (int) ($dc['student'] ?? 0) . '</a></td><td class="n">' . (int) ($dc['faculty'] ?? 0) . '</td>';
        if (edu_can_edit()) {
            echo '<td class="act"><form method="post" data-confirm="Remove the department ' . e($d['name']) . '? Its people stay in the institution.">' . fabo_csrf_field()
               . '<input type="hidden" name="a" value="dept-delete"><input type="hidden" name="id" value="' . (int) $d['id'] . '"><button class="btn btn-sm btn-danger">Remove</button></form></td>';
        }
        echo '</tr>';
    }
    if (!$depts) {
        echo '<tr><td colspan="4" class="edu-empty">No departments yet.</td></tr>';
    }
    echo '</tbody></table></div>';
    if (edu_can_edit()) {
        echo '<form method="post" class="edu-inline">' . fabo_csrf_field() . '<input type="hidden" name="a" value="dept-save"><input type="hidden" name="institution_id" value="' . $iid . '">'
           . '<input name="name" required maxlength="160" placeholder="New department, e.g. Computer Science" aria-label="New department"><button class="btn btn-primary">Add department</button></form>';
    }
    echo '</section>';
    if (edu_can_edit()) {
        echo '<details class="edu-card edu-danger"><summary>Remove this institution</summary><form method="post" class="edu-inline">' . fabo_csrf_field()
           . '<input type="hidden" name="a" value="inst-delete"><input type="hidden" name="id" value="' . $iid . '">'
           . '<p class="muted">Removes the institution, its departments and every role held in it. The people stay as records. Type DELETE to confirm.</p>'
           . '<input name="confirm" placeholder="DELETE" aria-label="Type DELETE" autocomplete="off"><button class="btn btn-danger">Remove institution</button></form></details>';
    }
}

function edu_page_people(int $org): void
{
    $f = edu_people_filter();
    $per = 50;
    $pg = max(1, (int) ($_GET['pg'] ?? 1));
    $rows = edu_people($org, $f + ['limit' => $per, 'offset' => ($pg - 1) * $per]);
    $total = (int) ($rows[0]['n'] ?? 0);
    $insts = edu_institutions($org);

    echo '<form method="get" class="edu-filters">' . (!empty($GLOBALS['edu_viewer']['is_admin']) ? '<input type="hidden" name="org" value="' . (int) $org . '">' : '')
       . '<input type="hidden" name="p" value="people"><input type="search" name="q" value="' . e($f['q'] ?? '') . '" placeholder="Name, email, roll no or phone" aria-label="Search">'
       . '<select name="role" aria-label="Role"><option value="">Every role</option>';
    foreach (edu_roles_all() as $k => $l) {
        echo '<option value="' . $k . '"' . (($f['role'] ?? '') === $k ? ' selected' : '') . '>' . e($l) . '</option>';
    }
    echo '</select><select name="inst" aria-label="Institution"><option value="">Every institution</option>';
    foreach ($insts as $i) {
        echo '<option value="' . (int) $i['id'] . '"' . ((int) ($f['inst'] ?? 0) === (int) $i['id'] ? ' selected' : '') . '>' . e($i['name']) . '</option>';
    }
    echo '</select><button class="btn">Filter</button>' . ($f ? '<a class="btn" href="' . e(edu_url('people')) . '">Clear</a>' : '')
       . '<span class="spacer"></span><a class="btn" href="' . e(edu_url('people', $f + ['csv' => 'export'])) . '">Export CSV</a></form>';

    echo '<section class="edu-card"><div class="edu-card-h"><h2>' . fabo_thousands($total) . ' ' . ($total === 1 ? 'person' : 'people') . '</h2>';
    if (edu_can_edit() && $total) {
        echo '<form method="post" data-confirm="Email a sign-in link to everyone in this list who has an email and no account yet (up to 100 at a time)?">' . fabo_csrf_field()
           . '<input type="hidden" name="a" value="invite-list">';
        foreach ($f as $k => $v) {
            echo '<input type="hidden" name="' . e($k) . '" value="' . e((string) $v) . '">';
        }
        echo '<button class="btn btn-sm">Email sign-in links to this list</button></form>';
    }
    echo '</div><div class="edu-tablewrap"><table class="edu-table"><thead><tr><th>Name</th><th>Email</th><th>Roll no</th><th>Roles</th><th>Where</th><th>Account</th></tr></thead><tbody>';
    foreach ($rows as $p) {
        $where = [];
        foreach ($p['roles'] as $r) {
            $where[] = trim($r['inst_name'] . ($r['dept_name'] !== '' ? ' · ' . $r['dept_name'] : ''), ' ·') ?: 'Organisation-wide';
        }
        echo '<tr><td><a href="' . e(edu_url('person', ['id' => (int) $p['id']])) . '"><strong>' . e($p['name']) . '</strong></a></td><td>' . e((string) $p['email']) . '</td>'
           . '<td>' . e($p['roll_no']) . '</td><td>' . implode(' ', array_map('edu_role_chip', $p['roles'])) . '</td>'
           . '<td class="muted">' . e(implode('; ', array_unique($where))) . '</td><td>' . edu_account_pill($p) . '</td></tr>';
    }
    if (!$rows) {
        echo '<tr><td colspan="6" class="edu-empty">' . ($f ? 'Nobody matches.' : 'Nobody yet. Add someone below, or import a CSV.') . '</td></tr>';
    }
    echo '</tbody></table></div>';
    if ($total > $per) {
        $last = (int) ceil($total / $per);
        echo '<nav class="edu-pager">' . ($pg > 1 ? '<a class="btn btn-sm" href="' . e(edu_url('people', $f + ['pg' => $pg - 1])) . '">← Previous</a>' : '')
           . '<span>Page ' . $pg . ' of ' . $last . '</span>' . ($pg < $last ? '<a class="btn btn-sm" href="' . e(edu_url('people', $f + ['pg' => $pg + 1])) . '">Next →</a>' : '') . '</nav>';
    }
    echo '</section>';

    if (edu_can_edit()) {
        if (!$insts) {
            echo '<p class="edu-flash info">Add an institution first: students, faculty and institution admins belong to one.</p>';
        }
        echo '<details class="edu-card"' . ($total ? '' : ' open') . '><summary><h2>Add a person</h2></summary><form method="post" class="edu-form">' . fabo_csrf_field()
           . '<input type="hidden" name="a" value="person-add"><div class="edu-fields">'
           . '<label class="field wide"><span>Full name</span><input name="name" required maxlength="160"></label>'
           . '<label class="field"><span>Email (they sign in with it)</span><input type="email" name="email" maxlength="160"></label>'
           . '<label class="field"><span>Phone</span><input name="phone" maxlength="40"></label>'
           . edu_role_select((string) ($f['role'] ?? 'student')) . edu_inst_dept_fields($org, (int) ($f['inst'] ?? 0), 0, true)
           . '<label class="field" data-for="student"><span>Roll / registration no</span><input name="roll_no" maxlength="60"></label>'
           . '<label class="field" data-for="student"><span>Programme</span><input name="programme" maxlength="120" placeholder="B.Tech Computer Science"></label>'
           . '<label class="field" data-for="student"><span>Year of study</span><input type="number" name="year_of_study" min="1" max="10"></label>'
           . '<label class="field" data-for="student"><span>APAAR ID</span><input name="apaar_id" maxlength="40"></label>'
           . '<label class="field" data-for="faculty mentor inst_admin"><span>Designation / expertise</span><input name="title" maxlength="160"></label>'
           . '</div><label class="edu-check"><input type="checkbox" name="invite" value="1"> Email them a sign-in link now</label>'
           . '<button class="btn btn-primary">Add person</button></form></details>';
    }
}

function edu_page_person(int $org): void
{
    $id = (int) ($_GET['id'] ?? 0);
    $p = $id ? edu_person($org, $id) : null;
    if ($p) {
        edu_access($org, edu_actor(), 'view', $id);
    }
    if (!$p) {
        echo '<p class="edu-empty">That person is not here. <a href="' . e(edu_url('people')) . '">Back to People</a></p>';
        return;
    }
    $roles = edu_person_roles($org, $id);
    $has = array_column($roles, 'role');
    $edit = edu_can_edit();
    $hid = fabo_csrf_field() . '<input type="hidden" name="id" value="' . $id . '">';
    echo '<p class="edu-back"><a href="' . e(edu_url('people')) . '">← People</a></p><div class="edu-split"><div>';

    echo '<section class="edu-card"><div class="edu-card-h"><div><h2>' . e($p['name']) . '</h2><div>' . implode(' ', array_map('edu_role_chip', $roles)) . '</div></div>' . edu_account_pill($p) . '</div>';
    $ro = $edit ? '' : ' readonly';
    echo '<form method="post" class="edu-form">' . $hid . '<input type="hidden" name="a" value="person-save"><div class="edu-fields">'
       . '<label class="field wide"><span>Full name</span><input name="name" required maxlength="160" value="' . e($p['name']) . '"' . $ro . '></label>'
       . '<label class="field"><span>Email</span><input type="email" name="email" maxlength="160" value="' . e((string) $p['email']) . '"' . $ro . '></label>'
       . '<label class="field"><span>Phone</span><input name="phone" maxlength="40" value="' . e($p['phone']) . '"' . $ro . '></label>';
    if (in_array('student', $has, true) || $p['roll_no'] !== '' || $p['programme'] !== '') {
        echo '<label class="field"><span>Roll / registration no</span><input name="roll_no" maxlength="60" value="' . e($p['roll_no']) . '"' . $ro . '></label>'
           . '<label class="field"><span>Programme</span><input name="programme" maxlength="120" value="' . e($p['programme']) . '"' . $ro . '></label>'
           . '<label class="field"><span>Year of study</span><input type="number" name="year_of_study" min="1" max="10" value="' . e((string) $p['year_of_study']) . '"' . $ro . '></label>'
           . '<label class="field"><span>APAAR ID</span><input name="apaar_id" maxlength="40" value="' . e($p['apaar_id']) . '"' . $ro . '></label>';
    } else {
        foreach (['roll_no', 'programme', 'year_of_study', 'apaar_id'] as $k) {
            echo '<input type="hidden" name="' . $k . '" value="' . e((string) $p[$k]) . '">';
        }
    }
    echo '</div>' . ($edit ? '<button class="btn btn-primary">Save</button>' : '') . '</form></section>';

    echo '<section class="edu-card"><h2>Roles</h2><div class="edu-tablewrap"><table class="edu-table"><thead><tr><th>Role</th><th>Institution</th><th>Department</th><th>Designation / expertise</th>' . ($edit ? '<th></th>' : '') . '</tr></thead><tbody>';
    foreach ($roles as $r) {
        echo '<tr><td>' . edu_role_chip($r) . '</td><td>' . e($r['inst_name'] !== '' ? $r['inst_name'] : 'Across the organisation') . '</td><td>' . e($r['dept_name']) . '</td><td>' . e($r['title']) . '</td>';
        if ($edit) {
            echo '<td class="act"><form method="post" data-confirm="Remove this role?">' . $hid . '<input type="hidden" name="a" value="role-remove"><input type="hidden" name="role_id" value="' . (int) $r['id'] . '">'
               . '<button class="btn btn-sm btn-danger">Remove</button></form></td>';
        }
        echo '</tr>';
    }
    if (!$roles) {
        echo '<tr><td colspan="5" class="edu-empty">No roles: they cannot use the Campus portal until they have one.</td></tr>';
    }
    echo '</tbody></table></div>';
    if ($edit) {
        echo '<details><summary class="btn btn-sm">+ Add a role</summary><form method="post" class="edu-form">' . $hid . '<input type="hidden" name="a" value="role-add"><div class="edu-fields">'
           . edu_role_select(in_array('student', $has, true) ? 'mentor' : 'student') . edu_inst_dept_fields($org, (int) ($roles[0]['institution_id'] ?? 0), 0, true)
           . '<label class="field"><span>Designation / expertise</span><input name="title" maxlength="160"></label></div><button class="btn btn-primary">Add role</button></form></details>';
    }
    echo '</section>';

    if (in_array('mentor', $has, true)) {
        $st = edu_mentor_students($org, $id);
        echo '<section class="edu-card"><h2>Students they mentor <span class="muted">(' . count($st) . ')</span></h2><ul class="edu-people">';
        foreach ($st as $s) {
            echo '<li><a href="' . e(edu_url('person', ['id' => (int) $s['id']])) . '">' . e($s['name']) . '</a> <span class="muted">' . e($s['roll_no'] ?: (string) $s['email']) . '</span>'
               . ($edit ? '<form method="post">' . $hid . '<input type="hidden" name="a" value="mentor-unassign"><input type="hidden" name="mentor_id" value="' . $id . '"><input type="hidden" name="student_id" value="' . (int) $s['id'] . '"><button class="btn btn-sm" aria-label="Unlink ' . e($s['name']) . '">Unlink</button></form>' : '') . '</li>';
        }
        if (!$st) {
            echo '<li class="edu-empty">Nobody yet.</li>';
        }
        echo '</ul>';
        if ($edit) {
            echo '<form method="post" class="edu-form">' . $hid . '<input type="hidden" name="a" value="mentor-assign">'
               . '<label class="field"><span>Link students by email or roll number (several: separate with spaces, commas or new lines)</span><textarea name="students" rows="2"></textarea></label>'
               . '<button class="btn">Link students</button></form>';
        }
        echo '</section>';
    }
    if (in_array('student', $has, true)) {
        $ms = edu_student_mentors($org, $id);
        echo '<section class="edu-card"><h2>Their mentors</h2><ul class="edu-people">';
        foreach ($ms as $m) {
            echo '<li><a href="' . e(edu_url('person', ['id' => (int) $m['id']])) . '">' . e($m['name']) . '</a> <span class="muted">' . e((string) $m['title']) . '</span>'
               . ($edit ? '<form method="post">' . $hid . '<input type="hidden" name="a" value="mentor-unassign"><input type="hidden" name="mentor_id" value="' . (int) $m['id'] . '"><input type="hidden" name="student_id" value="' . $id . '"><button class="btn btn-sm">Unlink</button></form>' : '') . '</li>';
        }
        if (!$ms) {
            echo '<li class="edu-empty">None yet. Link them from a mentor\'s page.</li>';
        }
        echo '</ul></section>';
        $learn = edu_student_learning($org, $id);
        echo '<section class="edu-card"><h2>Courses</h2><ul class="edu-people">';
        foreach ($learn as $c) {
            echo '<li><strong>' . e($c['title']) . '</strong><span class="edu-bar" style="max-width:160px;margin-left:auto"><i style="width:' . (int) $c['progress'] . '%"></i></span>'
               . '<span class="muted">' . ($c['completed_at'] ? 'Finished ' . e(fabo_short_date($c['completed_at'])) : (int) $c['progress'] . '%') . '</span></li>';
        }
        if (!$learn) {
            echo '<li class="edu-empty">Not enrolled on any course yet.</li>';
        }
        echo '</ul></section>';
    }
    if (in_array('mentor', $has, true)) {
        $up = edu_mentor_slots($org, $id, true);
        $past = edu_mentor_slots($org, $id, false);
        $rated = array_filter(array_column($past, 'rating'));
        echo '<section class="edu-card"><h2>Mentoring sessions</h2><p class="muted">' . count(array_filter($up, static fn($s) => $s['status'] === 'open')) . ' open, '
           . count(array_filter($up, static fn($s) => $s['status'] === 'booked')) . ' booked ahead, ' . count(array_filter($past, static fn($s) => $s['status'] === 'done')) . ' held'
           . ($rated ? ', rated ' . round(array_sum($rated) / count($rated), 1) . ' ★' : '') . '. <a href="' . e(edu_url('mentoring', ['mentor' => $id])) . '">See them</a></p></section>';
    }
    echo '</div><aside>';
    if (in_array('student', $has, true)) {
        edu_person_rewards($org, $id);
    }

    echo '<section class="edu-card"><h2>Campus portal account</h2><dl class="edu-dl">'
       . '<dt>Signs in with</dt><dd>' . e((string) $p['email'] ?: '— (no email)') . '</dd>'
       . '<dt>Password</dt><dd>' . ($p['pass_hash'] !== null ? ($p['must_change'] ? 'Temporary, to be changed' : 'Set ' . e(fabo_short_date($p['pass_set_at']))) : 'Not set') . '</dd>'
       . '<dt>Sign-in link</dt><dd>' . ($p['invite_expires'] && strtotime($p['invite_expires']) > time() ? 'Valid until ' . e(date('d M Y', strtotime($p['invite_expires']))) : 'None open') . '</dd>'
       . '<dt>Last sign-in</dt><dd>' . ($p['last_login_at'] ? e(date('d M Y, H:i', strtotime($p['last_login_at']))) : 'Never') . '</dd></dl>';
    if ($edit && (string) $p['email'] !== '') {
        echo '<div class="edu-acts">'
           . '<form method="post">' . $hid . '<input type="hidden" name="a" value="invite"><button class="btn btn-primary"' . (str_ends_with((string) $p['email'], '.example') ? ' disabled title="Sample address: nothing is sent"' : '') . '>Email a sign-in link</button></form>'
           . '<form method="post">' . $hid . '<input type="hidden" name="a" value="invite-link"><button class="btn">Show a sign-in link</button></form>'
           . '<form method="post" data-confirm="Make a temporary password? Any password they have stops working.">' . $hid . '<input type="hidden" name="a" value="temp-pass"><button class="btn">Set a temporary password</button></form>'
           . '</div>';
    }
    if ($edit) {
        echo '<div class="edu-acts">' . ($p['status'] === 'active'
                ? '<form method="post">' . $hid . '<input type="hidden" name="a" value="status"><input type="hidden" name="status" value="suspended"><button class="btn">Suspend sign-in</button></form>'
                : '<form method="post">' . $hid . '<input type="hidden" name="a" value="status"><input type="hidden" name="status" value="active"><button class="btn">Allow sign-in again</button></form>')
           . '<form method="post" data-confirm="Remove ' . e($p['name']) . ' and every role they hold? This cannot be undone.">' . $hid . '<input type="hidden" name="a" value="person-delete"><button class="btn btn-danger">Remove person</button></form></div>';
    }
    echo '</section></aside></div>';
}

function edu_page_import(int $org): void
{
    $rep = $_SESSION['edu_import'] ?? null;
    unset($_SESSION['edu_import']);
    if ($rep && $rep['skipped']) {
        echo '<section class="edu-card"><h2>Rows not imported</h2><div class="edu-tablewrap"><table class="edu-table"><thead><tr><th class="n">Line</th><th>Why</th></tr></thead><tbody>';
        foreach (array_slice($rep['skipped'], 0, 300) as [$line, $why]) {
            echo '<tr><td class="n">' . (int) $line . '</td><td>' . e($why) . '</td></tr>';
        }
        echo '</tbody></table></div>' . (count($rep['skipped']) > 300 ? '<p class="muted">… and ' . (count($rep['skipped']) - 300) . ' more.</p>' : '') . '</section>';
    }
    echo '<section class="edu-card"><h2>Import people from CSV</h2><ol class="edu-steps">'
       . '<li><a href="' . e(edu_url('import', ['csv' => 'template'])) . '">Download the template</a>. Columns: Name, Email, Phone, Role, Institution, Department, Roll no, Programme, Year, APAAR ID, Title. Only Name is required; common headings like "Student name", "College", "Branch" or "Hall ticket" are understood too.</li>'
       . '<li>Fill it in Excel or Google Sheets and save as <strong>CSV</strong>.</li>'
       . '<li>Upload it here. A row whose email is already here updates that person and adds the role; nobody is added twice.</li></ol>';
    if (edu_can_edit()) {
        echo '<form method="post" enctype="multipart/form-data" class="edu-form">' . fabo_csrf_field() . '<input type="hidden" name="a" value="import"><div class="edu-fields">'
           . '<label class="field wide"><span>CSV file</span><input type="file" name="csv" accept=".csv,text/csv" required></label>'
           . '<label class="field"><span>Role, when the file has no Role column</span><select name="role">';
        foreach (edu_roles_all() as $k => $l) {
            echo '<option value="' . $k . '">' . e($l) . '</option>';
        }
        echo '</select></label><label class="field"><span>Institution, when the file has no Institution column</span><select name="institution_id"><option value="0">From the file</option>';
        foreach (edu_institutions($org) as $i) {
            echo '<option value="' . (int) $i['id'] . '">' . e($i['name']) . '</option>';
        }
        echo '</select></label></div><label class="edu-check"><input type="checkbox" name="create" value="1" checked> Create institutions and departments the file names that are not here yet</label>'
           . '<p class="muted">Nobody is emailed by an import. Send sign-in links afterwards from People, when you are ready.</p>'
           . '<button class="btn btn-primary">Import</button></form>';
    }
    echo '</section>';
}

function edu_page_settings(int $org): void
{
    $edit = edu_can_edit();
    $dis = $edit ? '' : ' disabled';
    $url = edu_portal_url($org);
    echo '<section class="edu-card"><h2>Campus portal</h2><p class="muted">One address for everyone. People sign in with their email and see the portal for each role they hold; someone with two roles switches between them.</p>'
       . '<span class="edu-copy"><input readonly value="' . e($url) . '" aria-label="Campus portal address"><button type="button" class="btn btn-sm" data-copy>Copy</button><a class="btn btn-sm" href="' . e($url) . '" target="_blank" rel="noopener">Open</a></span>'
       . '<form method="post" class="edu-form">' . fabo_csrf_field() . '<input type="hidden" name="a" value="portal"><fieldset class="edu-switches"><legend>Open the portal to</legend>';
    $what = ['student' => 'Their profile, institution, department and mentors. Courses, coins and rankings arrive in the next phases.',
             'faculty' => 'The students and colleagues in their departments.',
             'mentor' => 'The students linked to them.',
             'inst_admin' => 'Their institution: numbers, departments, students and faculty, learning, ideas and reports, and adding people to it.',
             'council' => 'Every institution, read-only: an overview, rankings and reports with CSV download — for a government or council officer.'];
    foreach (edu_roles_all() as $k => $l) {
        echo '<label class="edu-switch"><input type="checkbox" name="portal_' . $k . '" value="1"' . (edu_portal_on($org, $k) ? ' checked' : '') . $dis . '><span><strong>' . e($l) . 's</strong><em>' . e($what[$k]) . '</em></span></label>';
    }
    echo '</fieldset><label class="field"><span>Welcome line on the sign-in page (optional)</span><textarea name="welcome" rows="2" maxlength="600"' . $dis . '>' . e(edu_get($org, 'portal_welcome')) . '</textarea></label>'
       . ($edit ? '<button class="btn btn-primary">Save</button>' : '') . '</form></section>';

    echo '<section class="edu-card"><h2>Sample data</h2>';
    $loaded = edu_get($org, 'sample_loaded');
    if ($loaded !== '') {
        echo '<p>Sample data was loaded on ' . e(date('d M Y, H:i', strtotime($loaded))) . '. Sample people have <code>.example</code> addresses; give one a temporary password on their page to try the Campus portal as them.</p>';
        if ($edit) {
            echo '<form method="post" data-confirm="Remove every sample person and the sample institutions? Real records are not touched.">' . fabo_csrf_field()
               . '<input type="hidden" name="a" value="sample-remove"><button class="btn btn-danger">Remove sample data</button></form>';
        }
    } elseif (!edu_institutions($org)) {
        echo '<p class="muted">Three colleges with departments, faculty, institution admins, mentors and 36 students, to explore or demonstrate before real data goes in.</p>';
        if ($edit) {
            echo '<form method="post">' . fabo_csrf_field() . '<input type="hidden" name="a" value="sample-load"><button class="btn">Load sample data</button></form>';
        }
    } else {
        echo '<p class="muted">Sample data can only be loaded into an Academic with no institutions yet.</p>';
    }
    echo '</section>';

    $log = fabo_all('SELECT * FROM edu_audit WHERE org_id = ? ORDER BY id DESC LIMIT 40', 'i', [$org]);
    echo '<section class="edu-card"><h2>Recent changes</h2><div class="edu-tablewrap"><table class="edu-table"><thead><tr><th>When</th><th>Who</th><th>What</th><th>Detail</th></tr></thead><tbody>';
    foreach ($log as $l) {
        echo '<tr><td>' . e(date('d M, H:i', strtotime($l['created_at']))) . '</td><td>' . e($l['actor']) . '</td><td>' . e($l['action']) . '</td><td class="muted">' . e($l['subject']) . '</td></tr>';
    }
    if (!$log) {
        echo '<tr><td colspan="4" class="edu-empty">Nothing yet.</td></tr>';
    }
    echo '</tbody></table></div></section>';
}

/* ================================================================== CSV */

function edu_csv_template(): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="academic-people-template.csv"');
    $out = fopen('php://output', 'wb');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Name', 'Email', 'Phone', 'Role', 'Institution', 'Department', 'Roll no', 'Programme', 'Year', 'APAAR ID', 'Title'], ',', '"', '');
    fputcsv($out, ['Ananya Reddy', 'ananya@college.edu.in', '9800000000', 'Student', 'Government Engineering College', 'Computer Science', 'GEC-2026-001', 'B.Tech Computer Science', '2', '', ''], ',', '"', '');
    fputcsv($out, ['Prof. K. Rao', 'krao@college.edu.in', '', 'Faculty', 'Government Engineering College', 'Computer Science', '', '', '', '', 'Head of Department'], ',', '"', '');
    fputcsv($out, ['Ravi Teja', 'ravi@mentor.in', '', 'Mentor', '', '', '', '', '', '', 'SaaS & go-to-market'], ',', '"', '');
    fclose($out);
    exit;
}

function edu_csv_export(int $org): void
{
    $f = edu_people_filter();
    edu_access($org, edu_actor(), 'export', 0, 'People CSV ' . json_encode($f));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="academic-people-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'wb');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Name', 'Email', 'Phone', 'Role', 'Institution', 'Department', 'Roll no', 'Programme', 'Year', 'APAAR ID', 'Title', 'Account', 'Last sign-in'], ',', '"', '');
    $cell = static fn($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : (string) $v;    // never a formula in Excel
    for ($off = 0; ; $off += 500) {
        $rows = edu_people($org, $f + ['limit' => 500, 'offset' => $off]);
        foreach ($rows as $p) {
            foreach ($p['roles'] ?: [['role' => '', 'inst_name' => '', 'dept_name' => '', 'title' => '']] as $r) {
                fputcsv($out, array_map($cell, [$p['name'], $p['email'], $p['phone'], $r['role'] !== '' ? edu_role_label($r['role']) : '', $r['inst_name'], $r['dept_name'],
                                   $p['roll_no'], $p['programme'], $p['year_of_study'], $p['apaar_id'], $r['title'],
                                   $p['status'] === 'suspended' ? 'Suspended' : ($p['pass_hash'] !== null ? 'Set up' : 'Not set up'), $p['last_login_at']]), ',', '"', '');
            }
        }
        if (count($rows) < 500) {
            break;
        }
    }
    fclose($out);
    exit;
}

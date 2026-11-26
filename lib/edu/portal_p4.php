<?php
/**
 * Campus portal — ideas, incubation, reports and the council (Phase 4).
 * Loaded by lib/edu/portal.php.
 *
 *   Student            Ideas: write, edit, apply to incubation (the real form, filled in), follow the stage
 *   Mentor             their students' ideas (on My students)
 *   Faculty            their students' ideas (on Learning)
 *   Institution admin  Ideas and Reports for their institution
 *   Council            every institution: overview, Reports, Rankings (read-only)
 */

require_once __DIR__ . '/ideas.php';
require_once __DIR__ . '/reports.php';

function edu_portal_post_p4(int $org, array $me, array $role, string $a): void
{
    if ($role['role'] !== 'student' || !in_array($a, ['idea-save', 'idea-delete', 'idea-apply'], true)) {
        return;
    }
    $id = (int) ($_POST['id'] ?? 0);
    $back = ['v' => 'ideas'];
    try {
        if ($a === 'idea-save') {
            $new = !$id;
            $id = edu_idea_save($org, $me, $_POST, $id ?: null);
            edu_pflash('ok', $new ? 'Idea saved. Keep shaping it, then apply to incubation when it is ready.' : 'Saved.');
            $back = ['v' => 'idea', 'id' => $id];
        } elseif ($a === 'idea-delete') {
            edu_idea_delete($org, $me, $id);
            edu_pflash('ok', 'Idea removed.');
        } else {
            [$form, $version] = edu_inc_form($org);
            if (!$form) {
                throw new InvalidArgumentException(edu_inc_form($org)[2]);
            }
            $fields = inc_form_fields((int) $version['id'], true);
            [$raw, $files] = inc_posted_answers($fields);
            require_once __DIR__ . '/../throttle.php';
            if (fabo_throttle_blocked('edu-apply', (string) $me['id'], 5, 3600)) {
                throw new InvalidArgumentException(fabo_throttle_words());
            }
            fabo_throttle_hit('edu-apply', (string) $me['id']);
            try {
                $ref = edu_idea_apply($org, $me, $id, $raw, $files, (string) ($_POST['ticket'] ?? ''));
            } catch (InvalidArgumentException $e) {
                // Field errors: back to the form with what they typed.
                [$errs, $msg] = inc_submit_errors($e);
                $_SESSION['edu_apply'][$id] = ['values' => $raw, 'errors' => $errs];
                throw new InvalidArgumentException($msg);
            }
            unset($_SESSION['edu_apply'][$id]);
            edu_pflash('ok', 'Sent to incubation. Your reference is ' . $ref . '; the incubation team has it now and you will hear from them by email.');
            $back = ['v' => 'idea', 'id' => $id];
        }
    } catch (InvalidArgumentException $e) {
        edu_pflash('err', $e->getMessage());
        if ($a === 'idea-apply') {
            $back = ['v' => 'apply', 'id' => $id];
        } elseif ($id) {
            $back = ['v' => 'idea', 'id' => $id];
        }
    } catch (Throwable $e) {
        error_log('FABO edu idea ' . $a . ': ' . $e->getMessage());
        edu_pflash('err', 'That could not be saved just now. Please try again in a few minutes.');
    }
    header('Location: ' . edu_purl($back), true, 303);
    exit;
}

/* ================================================================ student */

function edu_idea_form(?array $i): string
{
    $v = static fn(string $k) => e((string) ($i[$k] ?? ''));
    $h = '<form method="post" class="cp-form">' . fabo_csrf_field() . '<input type="hidden" name="a" value="idea-save">' . ($i ? '<input type="hidden" name="id" value="' . (int) $i['id'] . '">' : '')
       . '<label class="field"><span>Name of your idea or startup</span><input name="title" required maxlength="190" value="' . $v('title') . '" placeholder="e.g. FarmPrice — fair mandi prices by SMS"></label>'
       . '<label class="field"><span>What problem does it solve, and for whom?</span><textarea name="problem" rows="4" required minlength="20" maxlength="4000">' . $v('problem') . '</textarea></label>'
       . '<label class="field"><span>Your solution (how it works, what is new)</span><textarea name="solution" rows="4" maxlength="4000">' . $v('solution') . '</textarea></label>'
       . '<div class="edu-fields"><label class="field"><span>Sector</span><input name="sector" maxlength="120" value="' . $v('sector') . '" placeholder="Agritech, Health, EdTech…"></label>'
       . '<label class="field"><span>How far along</span><select name="stage">';
    foreach (edu_idea_stages() as $k => $l) {
        $h .= '<option value="' . $k . '"' . (($i['stage'] ?? 'idea') === $k ? ' selected' : '') . '>' . e($l) . '</option>';
    }
    return $h . '</select></label></div><label class="field"><span>Team (names, roles, emails — one per line)</span><textarea name="team" rows="3" maxlength="2000">' . $v('team') . '</textarea></label>'
         . '<button class="btn btn-primary">' . ($i ? 'Save' : 'Save idea') . '</button></form>';
}

function edu_pv_student_ideas(int $org, array $me, array $role): void
{
    $ideas = edu_ideas_of($org, (int) $me['id']);
    $status = edu_inc_status($org, array_column($ideas, 'inc_app_id'));
    echo '<div class="cp-pagehead"><h1>Ideas</h1><p class="muted">Write down a startup idea, shape it with your mentor, and apply to incubation when it is ready.</p></div><div class="cp-ideas">';
    foreach ($ideas as $i) {
        $st = $i['inc_app_id'] ? ($status[(int) $i['inc_app_id']] ?? null) : null;
        echo '<a class="cp-idea" href="' . e(edu_purl(['v' => 'idea', 'id' => (int) $i['id']])) . '"><span class="cp-idea-k">' . e(edu_idea_stages()[$i['stage']]) . ($i['sector'] !== '' ? ' · ' . e($i['sector']) : '') . '</span>'
           . '<strong>' . e($i['title']) . '</strong><span class="cp-course-sum">' . e(mb_strimwidth((string) $i['problem'], 0, 140, '…')) . '</span>'
           . ($i['inc_app_id'] ? '<span class="pill green">Incubation: ' . e($st['stage'] ?? 'Sent') . '</span>' : '<span class="pill grey">Not sent yet</span>') . '</a>';
    }
    echo '</div><details class="cp-card"' . ($ideas ? '' : ' open') . '><summary><h2>Write a new idea</h2></summary>' . edu_idea_form(null) . '</details>';
}

function edu_pv_student_idea(int $org, array $me, array $role): void
{
    $i = edu_idea($org, (int) ($_GET['id'] ?? 0));
    if (!$i || (int) $i['person_id'] !== (int) $me['id']) {
        echo '<p class="edu-empty">That idea is not here. <a href="' . e(edu_purl(['v' => 'ideas'])) . '">Your ideas</a></p>';
        return;
    }
    echo '<p class="edu-back"><a href="' . e(edu_purl(['v' => 'ideas'])) . '">← Your ideas</a></p>';
    if ($i['inc_app_id']) {
        $st = edu_inc_status($org, [(int) $i['inc_app_id']])[(int) $i['inc_app_id']] ?? null;
        echo '<section class="cp-coursehead c3"><span class="cp-course-band"><em>Sent to incubation · ' . e(fabo_short_date($i['applied_at'])) . '</em></span><h1>' . e($i['title']) . '</h1>'
           . '<p>Reference <strong>' . e((string) $i['inc_ref']) . '</strong>. The incubation team reviews every application and writes to you by email.</p>'
           . '<div class="cp-pipe"><span>Where it is now</span><b>' . e($st['stage'] ?? 'Received') . '</b></div></section>'
           . '<section class="cp-card"><h2>What you sent</h2><dl class="edu-dl"><dt>Problem</dt><dd>' . nl2br(e((string) $i['problem'])) . '</dd>'
           . '<dt>Solution</dt><dd>' . nl2br(e((string) $i['solution'])) . '</dd><dt>Stage</dt><dd>' . e(edu_idea_stages()[$i['stage']]) . '</dd>'
           . '<dt>Team</dt><dd>' . nl2br(e((string) $i['team'])) . '</dd></dl></section>';
        return;
    }
    [$form, , $why] = edu_inc_form($org);
    echo '<section class="cp-card"><div class="cp-card-h"><h2>Your idea</h2>' . ($form ? '<a class="btn btn-primary" href="' . e(edu_purl(['v' => 'apply', 'id' => (int) $i['id']])) . '">Apply to incubation →</a>' : '') . '</div>'
       . (!$form ? '<p class="edu-flash info">' . e($why) . '</p>' : '') . edu_idea_form($i)
       . '<form method="post" data-confirm="Remove this idea?" class="cp-del">' . fabo_csrf_field() . '<input type="hidden" name="a" value="idea-delete"><input type="hidden" name="id" value="' . (int) $i['id'] . '"><button class="btn btn-sm btn-danger">Remove idea</button></form></section>';
}

function edu_pv_student_apply(int $org, array $me, array $role): void
{
    $i = edu_idea($org, (int) ($_GET['id'] ?? 0));
    if (!$i || (int) $i['person_id'] !== (int) $me['id']) {
        echo '<p class="edu-empty">That idea is not here.</p>';
        return;
    }
    if ($i['inc_app_id']) {
        echo '<p>Already sent. <a href="' . e(edu_purl(['v' => 'idea', 'id' => (int) $i['id']])) . '">See it</a></p>';
        return;
    }
    [$form, $version, $why] = edu_inc_form($org);
    echo '<p class="edu-back"><a href="' . e(edu_purl(['v' => 'idea', 'id' => (int) $i['id']])) . '">← ' . e($i['title']) . '</a></p>';
    if (!$form) {
        echo '<p class="edu-flash info">' . e($why) . '</p>';
        return;
    }
    $fields = inc_form_fields((int) $version['id'], true);
    $inst = (string) (fabo_one('SELECT i.name FROM edu_roles r JOIN edu_institutions i ON i.id = r.institution_id WHERE r.person_id = ? AND r.role = "student" LIMIT 1', 'i', [(int) $me['id']])['name'] ?? '');
    $kept = $_SESSION['edu_apply'][(int) $i['id']] ?? null;
    $values = $kept['values'] ?? edu_inc_prefill($fields, $i, $me, $inst);
    $errors = $kept['errors'] ?? [];
    echo '<link rel="stylesheet" href="' . e(fabo_asset('assets/inc.css')) . '">'
       . '<section class="cp-card cp-apply"><span class="edu-lk k-quiz">Incubation application</span><h1>' . e($form['name']) . '</h1>'
       . ($form['description'] ? '<p class="muted">' . nl2br(e($form['description'])) . '</p>' : '')
       . '<p class="edu-flash info">We have filled in what we could from your idea and your profile. Check every answer, complete the rest, then send it. Once sent, the incubation team holds it.</p>'
       . '<form method="post" enctype="multipart/form-data" class="inc-applyform" novalidate' . ((int) ($form['stepwise'] ?? 0) === 1 ? ' data-steps="1"' : '') . '>' . fabo_csrf_field()
       . '<input type="hidden" name="a" value="idea-apply"><input type="hidden" name="id" value="' . (int) $i['id'] . '"><input type="hidden" name="ticket" value="' . e(bin2hex(random_bytes(16))) . '">'
       . inc_render_fields($fields, $values, $errors)
       . '<div class="inc-formfoot"><button class="btn btn-primary">Send to incubation</button></div></form></section>'
       . '<script src="' . e(fabo_asset('assets/inc.js')) . '" defer></script>';
}

/** Ideas as a table, for mentors, faculty, institution admins and the council. */
function edu_pv_ideas_table(int $org, array $ideas, string $title, bool $showInst = false): void
{
    $status = edu_inc_status($org, array_column($ideas, 'inc_app_id'));
    echo '<section class="cp-card"><h2>' . e($title) . ' <span class="muted">(' . count($ideas) . ')</span></h2><div class="edu-tablewrap"><table class="edu-table"><thead><tr><th>Idea</th><th>Student</th>'
       . ($showInst ? '<th>Institution</th>' : '') . '<th>Stage</th><th>Incubation</th></tr></thead><tbody>';
    foreach ($ideas as $i) {
        $st = $i['inc_app_id'] ? ($status[(int) $i['inc_app_id']] ?? null) : null;
        echo '<tr><td><strong>' . e($i['title']) . '</strong><details class="edu-ideamore"><summary>Read</summary><p><b>Problem.</b> ' . nl2br(e((string) $i['problem'])) . '</p>'
           . ($i['solution'] ? '<p><b>Solution.</b> ' . nl2br(e((string) $i['solution'])) . '</p>' : '') . '</details></td>'
           . '<td>' . e($i['student_name']) . ((string) $i['student_email'] !== '' ? '<br><a class="muted" href="mailto:' . e((string) $i['student_email']) . '">' . e((string) $i['student_email']) . '</a>' : '') . '</td>'
           . ($showInst ? '<td class="muted">' . e((string) $i['inst_name']) . '</td>' : '') . '<td>' . e(edu_idea_stages()[$i['stage']]) . '</td>'
           . '<td>' . ($i['inc_app_id'] ? '<code>' . e((string) $i['inc_ref']) . '</code><br><span class="pill green">' . e($st['stage'] ?? 'Sent') . '</span>' : '<span class="muted">Not sent</span>') . '</td></tr>';
    }
    if (!$ideas) {
        echo '<tr><td colspan="5" class="edu-empty">No ideas yet.</td></tr>';
    }
    echo '</tbody></table></div></section>';
}

/* ======================================================= institution admin */

function edu_pv_inst_admin_ideas(int $org, array $me, array $role): void
{
    echo '<div class="cp-pagehead"><h1>Ideas</h1><p class="muted">Startup ideas from ' . e($role['inst_name']) . ' students, and where the ones sent to incubation are now.</p></div>';
    edu_pv_ideas_table($org, edu_ideas_list($org, [(int) $role['institution_id']]), 'Ideas');
}

function edu_pv_inst_admin_reports(int $org, array $me, array $role): void
{
    $period = edu_report_period($_GET);
    $rows = edu_report($org, $period[2], $period[3], [(int) $role['institution_id']]);
    echo '<div class="cp-pagehead"><h1>Reports</h1></div>' . edu_report_html($org, $rows, $period, static function (array $x, bool $base = false) use ($org) {
        $q = ['o' => edu_portal_key($org), 'v' => 'reports'];
        return $base ? $q : 'campus.php?' . http_build_query($q + array_filter($x, static fn($v) => $v !== null));
    }, false);
}

/* ================================================================ council */

function edu_portal_home_council(int $org, array $me, array $role): void
{
    $t = edu_totals($org);
    edu_portal_hello($me, $role, edu_org_name($org) . ' · every institution');
    $period = edu_report_periods()['ay'];
    $rep = edu_report($org, $period[1], $period[2]);
    $tot = edu_report_total($rep);
    echo '<div class="cp-kpis"><div><b>' . $t['institutions'] . '</b><span>Institutions</span></div><div><b>' . fabo_thousands($t['student']) . '</b><span>Students</span></div>'
       . '<div><b>' . fabo_thousands($t['faculty']) . '</b><span>Faculty</span></div><div><b>' . fabo_thousands($t['mentor']) . '</b><span>Mentors</span></div></div>'
       . '<section class="cp-card"><div class="cp-card-h"><h2>' . e($period[0]) . ' so far</h2><a href="' . e(edu_purl(['v' => 'reports'])) . '">Full report</a></div><div class="cp-kpis inner">'
       . '<div><b>' . fabo_thousands((int) $tot['active']) . '</b><span>Students active</span></div><div><b>' . fabo_thousands((int) $tot['completions']) . '</b><span>Courses completed</span></div>'
       . '<div><b>' . fabo_thousands((int) $tot['sessions']) . '</b><span>Mentoring sessions</span></div><div><b>' . fabo_thousands((int) $tot['ideas']) . '</b><span>Startup ideas</span></div>'
       . '<div><b>' . fabo_thousands((int) $tot['applied']) . '</b><span>Applied to incubation</span></div><div><b>' . fabo_thousands((int) $tot['progressed']) . '</b><span>Past first screening</span></div></div></section>';
    echo '<section class="cp-card"><div class="cp-card-h"><h2>Institutions this month</h2><a href="' . e(edu_purl(['v' => 'rankings'])) . '">Rankings</a></div><ol class="cp-board">';
    foreach (array_slice(edu_institution_board($org, 'month'), 0, 10) as $n => $r) {
        echo '<li class="' . ($n < 3 ? 'top' . ($n + 1) : '') . '"><b>' . ($n + 1) . '</b><span><strong>' . e($r['name']) . '</strong><em>' . (int) $r['active'] . ' of ' . (int) $r['students'] . ' students active (' . (int) $r['active_pct'] . '%)</em></span><strong>' . e((string) $r['avg']) . ' XP</strong></li>';
    }
    echo '</ol></section>';
    edu_pv_ideas_table($org, array_slice(edu_ideas_list($org), 0, 15), 'Latest ideas', true);
}

function edu_pv_council_reports(int $org, array $me, array $role): void
{
    $period = edu_report_period($_GET);
    $rows = edu_report($org, $period[2], $period[3]);
    echo '<div class="cp-pagehead"><h1>Reports</h1></div>' . edu_report_html($org, $rows, $period, static function (array $x, bool $base = false) use ($org) {
        $q = ['o' => edu_portal_key($org), 'v' => 'reports'];
        return $base ? $q : 'campus.php?' . http_build_query($q + array_filter($x, static fn($v) => $v !== null));
    });
}

function edu_pv_council_rankings(int $org, array $me, array $role): void
{
    $period = in_array($_GET['period'] ?? '', ['week', 'month', 'all'], true) ? $_GET['period'] : 'month';
    echo '<div class="cp-pagehead"><h1>Rankings</h1><nav class="cp-seg">';
    foreach (['week' => 'This week', 'month' => 'This month', 'all' => 'All time'] as $k => $l) {
        echo '<a href="' . e(edu_purl(['v' => 'rankings', 'period' => $k])) . '"' . ($period === $k ? ' class="on"' : '') . '>' . $l . '</a>';
    }
    echo '</nav></div><section class="cp-card"><h2>Institutions</h2><p class="muted">By average XP per student, so a small college can lead a large one.</p><ol class="cp-board">';
    foreach (edu_institution_board($org, $period) as $n => $r) {
        echo '<li class="' . ($n < 3 ? 'top' . ($n + 1) : '') . '"><b>' . ($n + 1) . '</b><span><strong>' . e($r['name']) . '</strong><em>' . (int) $r['active'] . ' of ' . (int) $r['students'] . ' students active</em></span><strong>' . e((string) $r['avg']) . ' XP</strong></li>';
    }
    echo '</ol></section><section class="cp-card"><h2>Top students</h2><ol class="cp-board">';
    foreach (array_slice(edu_leaderboard($org, $period, [], 20), 0, 20) as $r) {
        echo '<li class="' . ($r['rank'] <= 3 ? 'top' . $r['rank'] : '') . '"><b>' . (int) $r['rank'] . '</b><span><strong>' . e($r['display']) . '</strong><em>' . e((string) $r['inst_name']) . '</em></span><strong>' . fabo_thousands($r['xp']) . ' XP</strong></li>';
    }
    echo '</ol></section>';
}

/** The CSV of a report from the portal (institution admin: their institution; council: all). */
function edu_portal_report_csv_maybe(int $org, array $role, string $view): void
{
    if ($view !== 'reports' || empty($_GET['csv']) || !in_array($role['role'], ['inst_admin', 'council'], true)) {
        return;
    }
    $period = edu_report_period($_GET);
    require_once __DIR__ . '/privacy.php';
    edu_access($org, $GLOBALS['edu_me_name'] ?? 'Campus user', 'export', 0, 'Report CSV ' . $period[1] . ' (' . $role['role'] . ')');
    edu_report_csv($org, edu_report($org, $period[2], $period[3], $role['role'] === 'inst_admin' ? [(int) $role['institution_id']] : null), $period[1]);
}

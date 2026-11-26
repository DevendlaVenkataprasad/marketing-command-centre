<?php
/**
 * Academic desk — Ideas & incubation and Reports (Phase 4). Loaded by lib/edu/app.php.
 */

require_once __DIR__ . '/ideas.php';
require_once __DIR__ . '/reports.php';

function edu_post_p4(int $org, string $a): void
{
    if ($a === 'inc-form') {
        $fid = (int) ($_POST['inc_form_id'] ?? 0);
        $forms = edu_inc_forms($org);
        if ($fid && !isset($forms[$fid])) {
            throw new InvalidArgumentException('Pick one of your published incubation forms.');
        }
        edu_set($org, 'inc_form_id', (string) $fid);
        edu_audit($org, edu_actor(), 'inc-form', (string) $fid);
        edu_flash($fid && !$forms[$fid]['public'] ? 'info' : 'ok', !$fid ? 'Students can write ideas but not apply until you pick a form.'
                  : ($forms[$fid]['public'] ? 'Saved. Students apply with "' . $forms[$fid]['name'] . '".'
                                            : 'Saved, but "' . $forms[$fid]['name'] . '" has no open public link yet: open it in Incubation Management → Forms, or students cannot send.'));
        edu_go('ideas');
    }
}

function edu_page_ideas(int $org): void
{
    $avail = edu_inc_available($org);
    $forms = $avail ? edu_inc_forms($org) : [];
    $fid = (int) edu_get($org, 'inc_form_id', '0');
    echo '<section class="edu-card"><h2>How students apply</h2>';
    if (!$avail) {
        echo '<p class="muted">Students can write ideas, but this organisation does not hold Incubation Management, so they cannot apply yet. '
           . 'The administrator switches it on under Clients (Incubation Management — their own); its pipeline and tables are then the same as everyone else\'s.</p>';
    } else {
        echo '<p class="muted">Students apply from their Campus portal with this incubation form, filled in for them from their idea and profile. '
           . 'Their applications arrive in Incubation Management like any other, at the first stage, with a note that they came from the Campus portal.</p>';
        if (edu_can_edit()) {
            echo '<form method="post" class="edu-inline">' . fabo_csrf_field() . '<input type="hidden" name="a" value="inc-form"><select name="inc_form_id" aria-label="Incubation form"><option value="0">Not open yet</option>';
            foreach ($forms as $id => $f) {
                echo '<option value="' . $id . '"' . ($fid === $id ? ' selected' : '') . '>' . e($f['name']) . ($f['public'] ? '' : ' (public link closed)') . '</option>';
            }
            echo '</select><button class="btn btn-primary">Save</button></form>';
            if (!$forms) {
                echo '<p class="edu-flash info">There is no published incubation form yet. Make one in Incubation Management → Forms (the Incubation preset works well), publish it, and open its public link.</p>';
            }
        } else {
            echo '<p>' . e($forms[$fid]['name'] ?? 'Not open yet') . '</p>';
        }
    }
    echo '</section>';

    $inst = (int) ($_GET['inst'] ?? 0);
    $ideas = edu_ideas_list($org, $inst ? [$inst] : null);
    $status = edu_inc_status($org, array_column($ideas, 'inc_app_id'));
    $applied = count(array_filter($ideas, static fn($i) => $i['inc_app_id']));
    // The application opens in Incubation Management for the organisation's own staff, and for the administrator only in ours.
    $openInc = empty($GLOBALS['edu_viewer']['is_admin']) ? fabo_module_permission($GLOBALS['edu_viewer'], 'incubation') !== 'none' : $org === 0;
    echo '<section class="edu-kpis four">' . edu_kpi('Ideas', count($ideas), 't-acc') . edu_kpi('Sent to incubation', $applied, 't-inst')
       . edu_kpi('Still being developed', count($ideas) - $applied, 't-student') . edu_kpi('Students with an idea', count(array_unique(array_column($ideas, 'person_id'))), 't-faculty') . '</section>'
       . '<form method="get" class="edu-filters">' . (!empty($GLOBALS['edu_viewer']['is_admin']) ? '<input type="hidden" name="org" value="' . $org . '">' : '')
       . '<input type="hidden" name="p" value="ideas"><select name="inst" onchange="this.form.submit()"><option value="0">Every institution</option>';
    foreach (edu_institutions($org) as $i) {
        echo '<option value="' . (int) $i['id'] . '"' . ($inst === (int) $i['id'] ? ' selected' : '') . '>' . e($i['name']) . '</option>';
    }
    echo '</select></form><section class="edu-card"><div class="edu-tablewrap"><table class="edu-table"><thead><tr><th>Idea</th><th>Student</th><th>Institution</th><th>Stage</th><th>Incubation</th><th>Updated</th></tr></thead><tbody>';
    foreach ($ideas as $i) {
        $st = $i['inc_app_id'] ? ($status[(int) $i['inc_app_id']] ?? null) : null;
        echo '<tr><td><strong>' . e($i['title']) . '</strong>' . ($i['sector'] !== '' ? '<br><span class="muted">' . e($i['sector']) . '</span>' : '') . '<details class="edu-ideamore"><summary>Read</summary>'
           . '<p><b>Problem.</b> ' . nl2br(e((string) $i['problem'])) . '</p>' . ($i['solution'] ? '<p><b>Solution.</b> ' . nl2br(e((string) $i['solution'])) . '</p>' : '')
           . ($i['team'] ? '<p><b>Team.</b> ' . nl2br(e((string) $i['team'])) . '</p>' : '') . '</details></td>'
           . '<td><a href="' . e(edu_url('person', ['id' => (int) $i['person_id']])) . '">' . e($i['student_name']) . '</a><br><span class="muted">' . e($i['roll_no']) . '</span></td>'
           . '<td class="muted">' . e((string) $i['inst_name']) . '</td><td>' . e(edu_idea_stages()[$i['stage']]) . '</td>'
           . '<td>' . ($i['inc_app_id'] ? (($openInc ? '<a href="incubation.php?p=application&amp;id=' . (int) $i['inc_app_id'] . '">' : '') . '<code>' . e((string) $i['inc_ref']) . '</code>' . ($openInc ? '</a>' : '') . '<br>'
                . '<span class="pill ' . ($st && $st['terminal'] ? 'grey' : 'green') . '">' . e($st['stage'] ?? 'Sent') . '</span>') : '<span class="muted">Not sent</span>') . '</td>'
           . '<td>' . e(fabo_short_date($i['updated_at'])) . '</td></tr>';
    }
    if (!$ideas) {
        echo '<tr><td colspan="6" class="edu-empty">No ideas yet. Students write them from the Ideas tab of their Campus portal.</td></tr>';
    }
    echo '</tbody></table></div></section>';
}

function edu_page_reports(int $org): void
{
    $period = edu_report_period($_GET);
    $rows = edu_report($org, $period[2], $period[3]);
    echo edu_report_html($org, $rows, $period, static function (array $x, bool $base = false) use ($org) {
        $q = ['p' => 'reports'] + (!empty($GLOBALS['edu_viewer']['is_admin']) ? ['org' => $org] : []);
        return $base ? $q : 'academic.php?' . http_build_query($q + array_filter($x, static fn($v) => $v !== null));
    });
}

/** The CSV download, before any page is drawn. */
function edu_reports_csv_maybe(int $org, string $page): void
{
    if ($page === 'reports' && !empty($_GET['csv'])) {
        $period = edu_report_period($_GET);
        edu_access($org, edu_actor(), 'export', 0, 'Report CSV ' . $period[1]);
        edu_report_csv($org, edu_report($org, $period[2], $period[3]), $period[1]);
    }
}

<?php
/**
 * Academic — reports (Phase 4): one set of figures per institution for a
 * period, for the organisation, the council and each institution admin.
 *
 * Every figure is counted from the module's own records (people, roles,
 * enrolments, sessions, ideas, the rewards ledger and the incubation
 * pipeline), never typed in. They are evidence towards accreditation and
 * ranking returns -- NAAC (Criterion 3, Research, Innovations and
 * Extension), NIRF, and the Ministry of Education's Institution's Innovation
 * Council (IIC) -- but each return defines its own metrics, so the page says
 * to check the definition before copying a number across.
 */

require_once __DIR__ . '/ideas.php';
require_once __DIR__ . '/lms.php';
require_once __DIR__ . '/mentoring.php';

/** The columns of the report, in order: key => [heading, group, help]. */
function edu_report_columns(): array
{
    return [
        'students'     => ['Students', 'People', 'Students on record'],
        'faculty'      => ['Faculty', 'People', 'Faculty on record'],
        'accounts'     => ['Accounts set up', 'People', 'Students who have set a Campus password'],
        'active'       => ['Students active', 'People', 'Students who earned XP in the period (any learning, session or idea)'],
        'enrolments'   => ['Course enrolments', 'Learning', 'Enrolments started in the period'],
        'completions'  => ['Courses completed', 'Learning', 'Completions in the period (each has a verifiable certificate)'],
        'learners_done' => ['Students with a certificate', 'Learning', 'Different students who completed at least one course in the period'],
        'sessions'     => ['Mentoring sessions held', 'Mentoring', 'Sessions marked held in the period'],
        'mentored'     => ['Students mentored', 'Mentoring', 'Different students with a session held in the period'],
        'rating'       => ['Average session rating', 'Mentoring', 'Out of 5'],
        'ideas'        => ['Startup ideas written', 'Innovation', 'Ideas students wrote down in the period'],
        'applied'      => ['Applied to incubation', 'Innovation', 'Ideas sent to Incubation Management in the period'],
        'progressed'   => ['Past first screening', 'Innovation', 'Of those, applications that have moved beyond the first stage'],
        'kudos'        => ['Kudos given', 'Recognition', 'Recognitions from faculty and mentors'],
    ];
}

/**
 * Rows per institution: inst id => ['name', ...columns]. $insts limits to
 * some institutions (an institution admin); $from / $to are dates (Y-m-d).
 */
function edu_report(int $org, string $from, string $to, ?array $insts = null): array
{
    edu_ideas_install();
    $a = $from . ' 00:00:00';
    $b = $to . ' 23:59:59';
    $rows = [];
    foreach (edu_institutions($org) as $i) {
        if ($insts !== null && !in_array((int) $i['id'], array_map('intval', $insts), true)) {
            continue;
        }
        $rows[(int) $i['id']] = ['name' => $i['name'], 'kind' => $i['kind'], 'district' => $i['district']] + array_fill_keys(array_keys(edu_report_columns()), 0);
    }
    if (!$rows) {
        return [];
    }
    $in = implode(',', array_keys($rows));
    // Each student counted at the institution(s) where they study.
    $stu = 'SELECT DISTINCT r.person_id, r.institution_id FROM edu_roles r WHERE r.org_id = ' . $org . ' AND r.role = "student" AND r.institution_id IN (' . $in . ')';
    $put = static function (string $col, array $res) use (&$rows) {
        foreach ($res as $r) {
            if (isset($rows[(int) $r['inst']])) {
                $rows[(int) $r['inst']][$col] = is_numeric($r['n']) ? $r['n'] + 0 : 0;
            }
        }
    };
    $put('students', fabo_all('SELECT institution_id AS inst, COUNT(DISTINCT person_id) AS n FROM edu_roles WHERE org_id = ? AND role = "student" AND institution_id IN (' . $in . ') GROUP BY institution_id', 'i', [$org]));
    $put('faculty', fabo_all('SELECT institution_id AS inst, COUNT(DISTINCT person_id) AS n FROM edu_roles WHERE org_id = ? AND role = "faculty" AND institution_id IN (' . $in . ') GROUP BY institution_id', 'i', [$org]));
    $put('accounts', fabo_all("SELECT s.institution_id AS inst, COUNT(DISTINCT p.id) AS n FROM ($stu) s JOIN edu_people p ON p.id = s.person_id WHERE p.pass_hash IS NOT NULL GROUP BY s.institution_id"));
    $put('active', fabo_all("SELECT s.institution_id AS inst, COUNT(DISTINCT l.person_id) AS n FROM ($stu) s JOIN edu_ledger l ON l.person_id = s.person_id AND l.currency = 'xp' AND l.amount > 0
                              AND l.reason NOT IN ('adjust') AND l.created_at BETWEEN ? AND ? GROUP BY s.institution_id", 'ss', [$a, $b]));
    $put('enrolments', fabo_all("SELECT s.institution_id AS inst, COUNT(*) AS n FROM ($stu) s JOIN edu_enrolments e ON e.person_id = s.person_id AND e.enrolled_at BETWEEN ? AND ? GROUP BY s.institution_id", 'ss', [$a, $b]));
    $put('completions', fabo_all("SELECT s.institution_id AS inst, COUNT(*) AS n FROM ($stu) s JOIN edu_enrolments e ON e.person_id = s.person_id AND e.completed_at BETWEEN ? AND ? GROUP BY s.institution_id", 'ss', [$a, $b]));
    $put('learners_done', fabo_all("SELECT s.institution_id AS inst, COUNT(DISTINCT e.person_id) AS n FROM ($stu) s JOIN edu_enrolments e ON e.person_id = s.person_id AND e.completed_at BETWEEN ? AND ? GROUP BY s.institution_id", 'ss', [$a, $b]));
    $put('sessions', fabo_all("SELECT s.institution_id AS inst, COUNT(*) AS n FROM ($stu) s JOIN edu_slots x ON x.student_id = s.person_id AND x.status = 'done' AND x.starts_at BETWEEN ? AND ? GROUP BY s.institution_id", 'ss', [$a, $b]));
    $put('mentored', fabo_all("SELECT s.institution_id AS inst, COUNT(DISTINCT x.student_id) AS n FROM ($stu) s JOIN edu_slots x ON x.student_id = s.person_id AND x.status = 'done' AND x.starts_at BETWEEN ? AND ? GROUP BY s.institution_id", 'ss', [$a, $b]));
    $put('rating', fabo_all("SELECT s.institution_id AS inst, ROUND(AVG(x.rating), 1) AS n FROM ($stu) s JOIN edu_slots x ON x.student_id = s.person_id AND x.rating IS NOT NULL AND x.starts_at BETWEEN ? AND ? GROUP BY s.institution_id", 'ss', [$a, $b]));
    $put('ideas', fabo_all("SELECT s.institution_id AS inst, COUNT(*) AS n FROM ($stu) s JOIN edu_ideas i ON i.person_id = s.person_id AND i.created_at BETWEEN ? AND ? GROUP BY s.institution_id", 'ss', [$a, $b]));
    $put('applied', fabo_all("SELECT s.institution_id AS inst, COUNT(*) AS n FROM ($stu) s JOIN edu_ideas i ON i.person_id = s.person_id AND i.applied_at BETWEEN ? AND ? GROUP BY s.institution_id", 'ss', [$a, $b]));
    $put('kudos', fabo_all("SELECT s.institution_id AS inst, COUNT(*) AS n FROM ($stu) s JOIN edu_ledger l ON l.person_id = s.person_id AND l.currency = 'xp' AND l.reason = 'kudos' AND l.created_at BETWEEN ? AND ? GROUP BY s.institution_id", 'ss', [$a, $b]));
    // How far the incubation applications have gone: past the pipeline's first stage.
    $apps = fabo_all("SELECT s.institution_id AS inst, i.inc_app_id FROM ($stu) s JOIN edu_ideas i ON i.person_id = s.person_id AND i.inc_app_id IS NOT NULL AND i.applied_at BETWEEN ? AND ?", 'ss', [$a, $b]);
    if ($apps && edu_inc_open($org)) {
        try {
            $first = (int) (inc_first_stage('crm')['id'] ?? 0);
            $stage = [];
            foreach (inc_all('SELECT id, stage_id FROM inc_applications WHERE id IN (' . implode(',', array_map(static fn($x) => (int) $x['inc_app_id'], $apps)) . ')') as $r) {
                $stage[(int) $r['id']] = (int) $r['stage_id'];
            }
            foreach ($apps as $x) {
                if (isset($rows[(int) $x['inst']]) && ($stage[(int) $x['inc_app_id']] ?? $first) !== $first) {
                    $rows[(int) $x['inst']]['progressed']++;
                }
            }
        } catch (Throwable $e) {
            error_log('FABO edu report inc: ' . $e->getMessage());
        }
    }
    return $rows;
}

/** The whole organisation's totals for a report (the average rating is a weighted one). */
function edu_report_total(array $rows): array
{
    $t = array_fill_keys(array_keys(edu_report_columns()), 0);
    $ratingW = 0;
    foreach ($rows as $r) {
        foreach ($t as $k => $_) {
            if ($k !== 'rating') {
                $t[$k] += $r[$k];
            }
        }
        $t['rating'] += $r['rating'] * $r['sessions'];
        $ratingW += $r['rating'] ? $r['sessions'] : 0;
    }
    $t['rating'] = $ratingW ? round($t['rating'] / $ratingW, 1) : 0;
    return $t;
}

/** Common periods: key => [label, from, to]. Academic years run June to May. */
function edu_report_periods(): array
{
    $y = (int) date('Y');
    $ay = (int) date('n') >= 6 ? $y : $y - 1;
    return [
        'month'  => ['This month', date('Y-m-01'), date('Y-m-d')],
        'ay'     => ['Academic year ' . $ay . '–' . substr((string) ($ay + 1), 2), $ay . '-06-01', ($ay + 1) . '-05-31'],
        'lastay' => ['Academic year ' . ($ay - 1) . '–' . substr((string) $ay, 2), ($ay - 1) . '-06-01', $ay . '-05-31'],
        'year'   => ['Calendar year ' . $y, $y . '-01-01', $y . '-12-31'],
        'fy'     => ['Financial year ' . ((int) date('n') >= 4 ? $y : $y - 1) . '–' . substr((string) (((int) date('n') >= 4 ? $y : $y - 1) + 1), 2),
                     ((int) date('n') >= 4 ? $y : $y - 1) . '-04-01', (((int) date('n') >= 4 ? $y : $y - 1) + 1) . '-03-31'],
    ];
}

/** The period asked for: [key, label, from, to], from ?period= or ?from=&to=. */
function edu_report_period(array $q): array
{
    $ps = edu_report_periods();
    $from = (string) ($q['from'] ?? '');
    $to = (string) ($q['to'] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) && $from <= $to) {
        return ['custom', date('j M Y', strtotime($from)) . ' – ' . date('j M Y', strtotime($to)), $from, $to];
    }
    $k = isset($ps[$q['period'] ?? '']) ? $q['period'] : 'ay';
    return [$k, $ps[$k][0], $ps[$k][1], $ps[$k][2]];
}

/** Sends the report as CSV (Excel opens it), with a total row. */
function edu_report_csv(int $org, array $rows, string $label): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="academic-report-' . preg_replace('/[^a-z0-9]+/i', '-', $label) . '.csv"');
    $out = fopen('php://output', 'wb');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [edu_org_name($org) . ' — ' . $label], ',', '"', '');
    $cols = edu_report_columns();
    fputcsv($out, array_merge(['Institution', 'Type', 'District'], array_column($cols, 0)), ',', '"', '');
    $cell = static fn($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : $v;
    foreach ($rows as $r) {
        fputcsv($out, array_map($cell, array_merge([$r['name'], $r['kind'], $r['district']], array_map(static fn($k) => $r[$k], array_keys($cols)))), ',', '"', '');
    }
    $t = edu_report_total($rows);
    fputcsv($out, array_merge(['All institutions', '', ''], array_map(static fn($k) => $t[$k], array_keys($cols))), ',', '"', '');
    fputcsv($out, [], ',', '"', '');
    fputcsv($out, ['Counted from Academic and Incubation Management records. Check each return\'s own metric definitions (NAAC, NIRF, IIC) before copying figures.'], ',', '"', '');
    fclose($out);
    exit;
}

/** The report as a page section (desk, council, institution admin). $base: a function making a link with extra query. */
function edu_report_html(int $org, array $rows, array $period, callable $link, bool $showInst = true): string
{
    [$pk, $label] = $period;
    $cols = edu_report_columns();
    $t = edu_report_total($rows);
    $h = '<form method="get" class="edu-filters edu-repfilter" data-report>';
    foreach ($link([], true) as $k => $v) {
        $h .= '<input type="hidden" name="' . e($k) . '" value="' . e((string) $v) . '">';
    }
    $h .= '<select name="period" aria-label="Period" onchange="this.form.from.value=\'\';this.form.to.value=\'\';this.form.submit()">';
    foreach (edu_report_periods() as $k => [$l]) {
        $h .= '<option value="' . $k . '"' . ($pk === $k ? ' selected' : '') . '>' . e($l) . '</option>';
    }
    $h .= '</select><label class="muted">or from <input type="date" name="from" value="' . e($pk === 'custom' ? $period[2] : '') . '"></label><label class="muted">to <input type="date" name="to" value="' . e($pk === 'custom' ? $period[3] : '') . '"></label>'
        . '<button class="btn">Show</button><span class="spacer"></span><a class="btn" href="' . e($link(['period' => $pk, 'from' => $pk === 'custom' ? $period[2] : null, 'to' => $pk === 'custom' ? $period[3] : null, 'csv' => 1])) . '">Download CSV</a>'
        . '<button type="button" class="btn" onclick="window.print()">Print</button></form>';
    $h .= '<section class="edu-card edu-report"><div class="edu-card-h"><div><h2>' . e($label) . '</h2><p class="muted">' . e(date('j M Y', strtotime($period[2]))) . ' to ' . e(date('j M Y', strtotime($period[3]))) . ' · '
        . count($rows) . ' institution' . (count($rows) === 1 ? '' : 's') . ' · generated ' . e(date('j M Y, g:i a')) . '</p></div></div><div class="edu-kpis">';
    foreach (['students' => 't-student', 'active' => 't-recent', 'completions' => 't-faculty', 'sessions' => 't-mentor', 'ideas' => 't-acc', 'applied' => 't-inst'] as $k => $tone) {
        $h .= edu_kpi($cols[$k][0], is_float($t[$k]) ? (string) $t[$k] : (int) $t[$k], $tone);
    }
    $h .= '</div>';
    $groups = [];
    foreach ($cols as $k => [, $g]) {
        $groups[$g][] = $k;
    }
    $h .= '<div class="edu-tablewrap"><table class="edu-table edu-reptable"><thead><tr><th rowspan="2">Institution</th>';
    foreach ($groups as $g => $ks) {
        $h .= '<th colspan="' . count($ks) . '" class="grp">' . e($g) . '</th>';
    }
    $h .= '</tr><tr>';
    foreach ($cols as $k => [$head, , $help]) {
        $h .= '<th class="n" title="' . e($help) . '">' . e($head) . '</th>';
    }
    $h .= '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $h .= '<tr><td><strong>' . e($r['name']) . '</strong><br><span class="muted">' . e(trim($r['kind'] . ' · ' . $r['district'], ' ·')) . '</span></td>';
        foreach (array_keys($cols) as $k) {
            $h .= '<td class="n">' . ($r[$k] ? e((string) $r[$k]) : '<span class="muted">0</span>') . '</td>';
        }
        $h .= '</tr>';
    }
    if (!$rows) {
        $h .= '<tr><td colspan="' . (count($cols) + 1) . '" class="edu-empty">No institutions.</td></tr>';
    } elseif (count($rows) > 1) {
        $h .= '<tr class="tot"><td><strong>All institutions</strong></td>';
        foreach (array_keys($cols) as $k) {
            $h .= '<td class="n"><strong>' . e((string) $t[$k]) . '</strong></td>';
        }
        $h .= '</tr>';
    }
    $h .= '</tbody></table></div><details class="edu-repnotes"><summary>What each column counts</summary><dl class="edu-dl">';
    foreach ($cols as [$head, , $help]) {
        $h .= '<dt>' . e($head) . '</dt><dd>' . e($help) . '</dd>';
    }
    $h .= '</dl></details><p class="muted edu-repfoot">Counted from Academic and Incubation Management records, not typed in. Useful evidence for NAAC Criterion 3 (Research, Innovations and Extension), '
        . 'NIRF and the Institution\'s Innovation Council (IIC) — check each return\'s own definition of a metric before copying a figure across.</p></section>';
    return $h;
}

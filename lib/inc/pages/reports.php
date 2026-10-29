<?php
/**
 * Incubation Management — reports. Each export uses the filters chosen here, reads
 * saved records only, and keeps historical evaluation values as approved.
 */

$f = inc_app_filters($_GET);
$sort = 'newest';
$kind = (string) ($_GET['export'] ?? '');
$format = ($_GET['format'] ?? '') === 'pdf' ? 'pdf' : 'xlsx';
$stamp = date('Ymd-His');
if ($kind !== '') {
    inc_need('export');
    if ($kind === 'applications') {
        $rows = inc_report_applications_rows(inc_report_apps($f));
        if ($format === 'xlsx') {
            inc_send_xlsx('incubation-applications-' . $stamp . '.xlsx', ['Applications' => $rows]);
        }
        inc_send_pdf_table('Incubation applications', (count($rows) - 1) . ' records · ' . date('d M Y H:i'), $rows,
            [[0, 'Ref', 80, 'left'], [2, 'Startup', 120, 'left'], [4, 'Form', 90, 'left'], [6, 'Source', 60, 'left'], [7, 'Submitted', 60, 'left'], [8, 'Spoke', 60, 'left'],
             [9, 'Sector', 60, 'left'], [10, 'Pipeline', 85, 'left'], [12, 'Evaluation', 80, 'left'], [13, 'Score', 38, 'right'], [14, 'Uniq.', 32, 'right'],
             [16, 'Team', 60, 'left'], [17, 'Owner', 70, 'left']], 'incubation-applications-' . $stamp . '.pdf');
    }
    if ($kind === 'evaluations') {
        $apps = inc_report_apps($f);
        $rows = inc_report_evaluations_rows($apps);
        if ($format === 'xlsx') {
            inc_send_xlsx('incubation-evaluations-' . $stamp . '.xlsx', ['Evaluations' => $rows, 'Responses' => inc_report_responses_rows($apps)]);
        }
        $cols = [[0, 'Ref', 70, 'left'], [1, 'Startup', 95, 'left'], [2, '#', 25, 'right'], [4, 'Dev. stage', 70, 'left'], [6, 'Status', 65, 'left'], [8, 'Approved', 55, 'left'],
                 [9, 'Score', 35, 'right'], [10, 'Cover.', 35, 'right']];
        $i = 16;
        foreach (inc_default_pillars() as $code => $p) {
            $cols[] = [$i, mb_substr($p[0], 0, 8) . ' (' . $p[1] . ')', 40, 'right'];
            $i += 2;
        }
        inc_send_pdf_table('Evaluations', (count($rows) - 1) . ' evaluations · ratings /5, (default weight %) · ' . date('d M Y H:i'), $rows, $cols, 'incubation-evaluations-' . $stamp . '.pdf');
    }
    if ($kind === 'incubation') {
        $wf = ['owner_id' => $f['owner_id'] ?? null, 'team_id' => $f['team_id'] ?? null, 'stage_id' => $f['ws_stage_id'] ?? null, 'q' => $f['q'] ?? ''];
        $sheets = inc_report_incubation_rows($wf);
        if ($format === 'xlsx') {
            inc_send_xlsx('incubations-' . $stamp . '.xlsx', $sheets);
        }
        inc_send_pdf_table('Incubations', (count($sheets['Incubations']) - 1) . ' incubations · ' . date('d M Y H:i'), $sheets['Incubations'],
            [[0, 'Incubation ID', 60, 'left'], [1, 'Application', 75, 'left'], [2, 'Startup', 110, 'left'], [3, 'Stage', 90, 'left'], [4, 'Since', 55, 'left'], [5, 'Team', 70, 'left'],
             [6, 'Owner', 75, 'left'], [8, 'Score', 35, 'right'], [14, 'Assign.', 35, 'right'], [15, 'Open tasks', 45, 'right'], [17, 'Evals', 30, 'right'], [18, 'Progress', 40, 'right']],
            'incubations-' . $stamp . '.pdf');
    }
    fabo_deny('Unknown report.', 404);
}

$count = count(inc_report_apps($f, null, 10000));
inc_layout_start('Reports & exports', 'Filter once, then export. Every export reflects saved records and these filters.', ['Reports' => null]);
echo inc_filter_bar('reports', $f, $sort, true);
$q = inc_filter_query($f, $sort);
$link = static fn($k, $fmt) => inc_url('reports', ['export' => $k, 'format' => $fmt] + $q);
echo '<div class="inc-grid3">'
   . '<section class="card"><h2>Applications</h2><p class="small muted">' . number_format($count) . ' applications match. Reference, applicant, form, source, date, spoke, sector, pipeline stage, evaluation status, official score, uniqueness, team and owner.</p>'
   . '<a class="btn btn-primary" href="' . e($link('applications', 'xlsx')) . '">Excel</a> <a class="btn" href="' . e($link('applications', 'pdf')) . '">PDF</a></section>'
   . '<section class="card"><h2>Evaluations</h2><p class="small muted">Every evaluation of the matching applications (including earlier ones): stage, status, score (approved only), coverage, the nine pillar ratings and weights, response counts, evidence, comments, strengths, improvement areas and recommendations. Excel adds every answer.</p>'
   . '<a class="btn btn-primary" href="' . e($link('evaluations', 'xlsx')) . '">Excel</a> <a class="btn" href="' . e($link('evaluations', 'pdf')) . '">PDF</a>'
   . '<p class="small muted">A single evaluation\'s full report is on its results page.</p></section>'
   . '<section class="card"><h2>Incubation</h2><p class="small muted">Incubations (filtered by team, owner, incubation stage and search): lifecycle stage, team and owner, assignment history, stage dates, tasks, evaluations and progress.</p>'
   . '<a class="btn btn-primary" href="' . e($link('incubation', 'xlsx')) . '">Excel</a> <a class="btn" href="' . e($link('incubation', 'pdf')) . '">PDF</a></section>'
   . '</div>';
inc_layout_end();

<?php
/**
 * Incubation Management — reports and exports (Excel and PDF).
 *
 * Every report is built in two steps: a *_rows() function reads saved data
 * (with the same filters the screen uses) and returns plain rows, then the
 * rows are written as .xlsx (lib/xlsx.php) or PDF (lib/hr/pdf.php). Scores
 * and pillar ratings come from what each evaluation saved — never
 * recalculated — so an export of an old approved evaluation shows exactly
 * what was approved.
 */

require_once __DIR__ . '/screen.php';
require_once __DIR__ . '/../xlsx.php';
require_once __DIR__ . '/../hr/pdf.php';

/** Applications for a report: the filtered set, or exactly the chosen ids. */
function inc_report_apps(array $f, ?array $ids = null, int $cap = 10000): array
{
    inc_need('export');
    if ($ids !== null) {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $f = [];
        $extra = ' AND a.id IN (' . implode(',', array_slice($ids, 0, $cap)) . ')';
    } else {
        $extra = '';
    }
    [$where, $t, $p] = inc_app_where($f);
    return inc_all('SELECT a.*, st.name AS stage_name, fm.name AS form_name, s.dev_stage, w.ref AS ws_ref, ls.name AS ws_stage_name
                     FROM inc_applications a JOIN inc_stages st ON st.id = a.stage_id JOIN inc_forms fm ON fm.id = a.form_id
                     LEFT JOIN inc_startups s ON s.id = a.startup_id LEFT JOIN inc_workspaces w ON w.id = a.workspace_id LEFT JOIN inc_stages ls ON ls.id = w.stage_id
                     WHERE ' . $where . $extra . ' ORDER BY a.submitted_at DESC, a.id DESC LIMIT ' . (int) $cap, $t, $p);
}

function inc_report_applications_rows(array $apps): array
{
    $rows = [['Application ref', 'Applicant', 'Startup', 'Email', 'Form', 'Purpose', 'Event / program', 'Source', 'Submitted', 'Spoke', 'Sector', 'Pipeline stage',
              'Development stage', 'Evaluation status', 'Official score /100', 'Uniqueness /5', 'Coverage %', 'Team', 'Owner', 'Incubation ID', 'Incubation stage', 'Duplicate check', 'Applicant type']];
    foreach ($apps as $a) {
        $rows[] = [
            $a['ref'], $a['applicant_name'], $a['startup_name'], (string) $a['applicant_email'], $a['form_name'], inc_purposes()[$a['purpose']] ?? $a['purpose'], (string) ($a['event_name'] ?? ''),
            inc_master_name($a['source_id'] ? (int) $a['source_id'] : null), ['d' => substr((string) $a['submitted_at'], 0, 10)],
            inc_master_name($a['spoke_id'] ? (int) $a['spoke_id'] : null), inc_master_name($a['sector_id'] ? (int) $a['sector_id'] : null), $a['stage_name'],
            $a['dev_stage'] ? (inc_dev_stages()[$a['dev_stage']] ?? $a['dev_stage']) : '', inc_eval_status_labels()[$a['eval_status']] ?? $a['eval_status'],
            $a['official_score'] === null ? '' : ['n' => round((float) $a['official_score'], 2)],
            $a['uniqueness_rating'] === null ? '' : ['n' => round((float) $a['uniqueness_rating'], 2)],
            $a['official_coverage'] === null ? '' : ['n' => round((float) $a['official_coverage'], 1)],
            inc_team_name($a['team_id'] ? (int) $a['team_id'] : null), inc_person_name($a['owner_id'] ? (int) $a['owner_id'] : null),
            (string) $a['ws_ref'], (string) $a['ws_stage_name'], $a['dup_status'] === 'none' ? '' : $a['dup_status'],
            inc_master_name(!empty($a['applicant_type_id']) ? (int) $a['applicant_type_id'] : null),
        ];
    }
    return $rows;
}

/** Every evaluation (the history, not only the latest) of the given applications. */
function inc_report_evaluations_rows(array $apps): array
{
    $codes = array_keys(inc_default_pillars());
    $pillarNames = inc_default_pillars();
    $head = ['Application ref', 'Startup', 'Evaluation #', 'Template version', 'Development stage', 'Evaluator', 'Status', 'Created', 'Approved',
             'Score /100 (approved only)', 'Coverage %', 'Rated questions', 'Pending', 'Insufficient evidence', 'N/A', 'Rated pillars'];
    foreach ($codes as $c) {
        $head[] = $pillarNames[$c][0] . ' rating';
        $head[] = $pillarNames[$c][0] . ' weight';
    }
    array_push($head, 'Evidence items', 'Strengths', 'Improvement areas', 'Uniqueness: value proposition', 'Uniqueness: competitors', 'Uniqueness: concerns',
               'Reviewer note', 'Recommendations');
    $rows = [$head];
    if (!$apps) {
        return $rows;
    }
    $ids = implode(',', array_map(static fn($a) => (int) $a['id'], $apps));
    $evals = inc_all('SELECT e.*, a.ref, a.startup_name, a.applicant_name, t.version_no FROM inc_evaluations e JOIN inc_applications a ON a.id = e.application_id
                       JOIN inc_eval_templates t ON t.id = e.template_id WHERE e.application_id IN (' . $ids . ') ORDER BY a.id, e.created_at, e.id');
    foreach ($evals as $e) {
        $res = inc_eval_result($e);
        $c = $res['counts'] ?? [];
        $u = inc_eval_uniqueness((int) $e['id']);
        $evidence = (int) inc_one('SELECT COUNT(*) AS n FROM inc_eval_responses WHERE evaluation_id = ? AND (evidence_url IS NOT NULL OR evidence_file_id IS NOT NULL)', 'i', [(int) $e['id']])['n'];
        $recs = implode(' | ', array_map(static fn($r) => $r['text'] . ' [' . $r['status'] . ']',
                        inc_all('SELECT text, status FROM inc_recommendations WHERE evaluation_id = ?', 'i', [(int) $e['id']])));
        $row = [$e['ref'], $e['startup_name'] ?: $e['applicant_name'], ['n' => (int) $e['id']], ['n' => (int) $e['version_no']],
                inc_dev_stages()[$e['dev_stage']] ?? $e['dev_stage'], $e['evaluator_label'], inc_eval_status_labels()[$e['status']] ?? $e['status'],
                ['d' => substr((string) $e['created_at'], 0, 10)], $e['approved_at'] ? ['d' => substr((string) $e['approved_at'], 0, 10)] : '',
                $e['status'] === 'approved' && $e['score'] !== null ? ['n' => round((float) $e['score'], 2)] : '',
                ['n' => round((float) ($res['coverage'] ?? 0), 1)], ['n' => (int) ($c['rated'] ?? 0)], ['n' => (int) ($c['pending'] ?? 0)],
                ['n' => (int) ($c['insufficient'] ?? 0)], ['n' => (int) ($c['na'] ?? 0)], ['n' => (int) ($res['rated_pillars'] ?? 0)]];
        foreach ($codes as $code) {
            $p = $res['pillars'][$code] ?? null;
            $row[] = !$p ? 'not in template' : ($p['state'] === 'na' ? 'N/A' : ($p['rating'] === null ? 'not rated' : ['n' => round((float) $p['rating'], 3)]));
            $row[] = $p ? ['n' => (float) $p['weight']] : '';
        }
        $comps = implode('; ', array_map(static fn($x) => $x['name'] . ($x['difference'] ? ': ' . $x['difference'] : ''), $u['competitors'] ?? []));
        array_push($row, ['n' => $evidence], (string) $e['strengths'], (string) $e['improvements'], (string) ($u['uvp'] ?? ''), $comps,
                   (string) ($u['concerns'] ?? ''), (string) $e['review_note'], $recs);
        $rows[] = $row;
    }
    return $rows;
}

/** Question-level answers of the given applications' evaluations. */
function inc_report_responses_rows(array $apps): array
{
    $rows = [['Application ref', 'Evaluation #', 'Status of evaluation', 'Pillar', 'Question', 'Response', 'Rating', 'Comment', 'Evidence link', 'Evidence file']];
    if (!$apps) {
        return $rows;
    }
    $ids = implode(',', array_map(static fn($a) => (int) $a['id'], $apps));
    $labels = ['pending' => 'Pending', 'insufficient' => 'Insufficient Evidence', 'rated' => 'Rated', 'na' => 'N/A'];
    foreach (inc_all('SELECT a.ref, e.id AS eid, e.status AS estatus, p.name AS pillar, q.text, r.status, r.rating, r.comment, r.evidence_url, f.original_name
                       FROM inc_eval_responses r JOIN inc_evaluations e ON e.id = r.evaluation_id JOIN inc_applications a ON a.id = e.application_id
                       JOIN inc_eval_questions q ON q.id = r.question_id JOIN inc_eval_pillars p ON p.template_id = q.template_id AND p.code = q.pillar_code
                       LEFT JOIN inc_files f ON f.id = r.evidence_file_id WHERE e.application_id IN (' . $ids . ') ORDER BY a.id, e.id, p.sort, q.sort') as $r) {
        $rows[] = [$r['ref'], ['n' => (int) $r['eid']], inc_eval_status_labels()[$r['estatus']] ?? $r['estatus'], $r['pillar'], $r['text'], $labels[$r['status']],
                   $r['rating'] === null ? '' : ['n' => (int) $r['rating']], (string) $r['comment'], (string) $r['evidence_url'], (string) $r['original_name']];
    }
    return $rows;
}

function inc_report_incubation_rows(array $f = []): array
{
    $ws = inc_workspaces_list($f);
    $main = [['Incubation ID', 'Application ref', 'Startup', 'Incubation stage', 'Stage since', 'Team', 'Owner', 'Development stage', 'Official score', 'Uniqueness',
              'Created', 'Last reassigned', 'Closed', 'Target date', 'Assignments', 'Open tasks', 'Done tasks', 'Evaluations', 'Progress %']];
    $assign = [['Incubation ID', 'Date', 'Event', 'Team', 'Owner', 'Previous team', 'Previous owner', 'Incubation stage at the time', 'Reason', 'By']];
    $stages = [['Incubation ID', 'Date', 'From', 'To', 'Reason', 'By']];
    $tasks = [['Incubation ID', 'Task', 'Type', 'Assignee', 'Due', 'Status']];
    foreach ($ws as $w) {
        $id = (int) $w['id'];
        $as = inc_assignments_for($id);
        $t = inc_one('SELECT SUM(status = "open") AS o, SUM(status = "done") AS d FROM inc_tasks WHERE (entity = "workspace" AND entity_id = ?) OR application_id = ?', 'ii', [$id, (int) $w['application_id']]);
        $ev = (int) inc_one('SELECT COUNT(*) AS n FROM inc_evaluations WHERE application_id = ?', 'i', [(int) $w['application_id']])['n'];
        $prog = inc_ws_progress($id);
        $main[] = [$w['ref'], $w['app_ref'], $w['startup_name'] ?: $w['applicant_name'], $w['stage_name'], ['d' => substr((string) $w['stage_since'], 0, 10)],
                   inc_team_name((int) $w['team_id']), inc_person_name((int) $w['owner_id']), $w['dev_stage'] ? (inc_dev_stages()[$w['dev_stage']] ?? '') : '',
                   $w['official_score'] === null ? '' : ['n' => round((float) $w['official_score'], 2)], $w['uniqueness_rating'] === null ? '' : ['n' => round((float) $w['uniqueness_rating'], 2)],
                   ['d' => substr((string) $w['created_at'], 0, 10)], $w['last_reassigned_at'] ? ['d' => substr((string) $w['last_reassigned_at'], 0, 10)] : '',
                   $w['closed_at'] ? ['d' => substr((string) $w['closed_at'], 0, 10)] : '', $w['target_date'] ? ['d' => $w['target_date']] : '',
                   ['n' => count($as)], ['n' => (int) $t['o']], ['n' => (int) $t['d']], ['n' => $ev], ['n' => $prog['pct']]];
        foreach ($as as $a) {
            $assign[] = [$w['ref'], substr((string) $a['created_at'], 0, 16), $a['kind'] === 'assign' ? 'Assigned' : 'Reassigned Application', inc_team_name((int) $a['team_id']),
                         inc_person_name((int) $a['owner_id']), inc_team_name($a['prev_team_id'] ? (int) $a['prev_team_id'] : null),
                         inc_person_name($a['prev_owner_id'] ? (int) $a['prev_owner_id'] : null), (string) (inc_stage((int) $a['stage_id_at'])['name'] ?? ''), (string) $a['reason'], $a['actor']];
        }
        foreach (inc_stage_history('lifecycle', $id) as $h) {
            $stages[] = [$w['ref'], substr((string) $h['created_at'], 0, 16), (string) $h['from_name'], $h['to_name'], (string) $h['reason'], $h['actor']];
        }
        foreach (inc_all('SELECT * FROM inc_tasks WHERE (entity = "workspace" AND entity_id = ?) OR application_id = ? ORDER BY id', 'ii', [$id, (int) $w['application_id']]) as $tk) {
            $tasks[] = [$w['ref'], $tk['title'], $tk['kind'], inc_person_name($tk['assignee_id'] ? (int) $tk['assignee_id'] : null), $tk['due_date'] ? ['d' => $tk['due_date']] : '', $tk['status']];
        }
    }
    return ['Incubations' => $main, 'Assignment history' => $assign, 'Stage history' => $stages, 'Tasks' => $tasks];
}

/* ================================================================ writers */

function inc_send_xlsx(string $filename, array $sheets): void
{
    $x = new FaboXlsx();
    foreach ($sheets as $name => $rows) {
        $x->addSheet($name, $rows);
    }
    inc_audit('export', 'report', null, $filename);
    $x->download($filename);
    exit;
}

/** Plain rows (no typed cells) for the PDF table writer. */
function inc_flat(array $row): array
{
    return array_map(static function ($c) {
        if (is_array($c)) {
            $v = reset($c);
            return is_float($v) ? rtrim(rtrim(number_format($v, 2), '0'), '.') : (string) $v;
        }
        return (string) $c;
    }, $row);
}

/** A PDF table of selected columns of a row set. $cols: [[header index, label, width, align], ...]. */
function inc_send_pdf_table(string $title, string $subtitle, array $rows, array $cols, string $filename): void
{
    $data = [];
    foreach (array_slice($rows, 1) as $r) {
        $flat = inc_flat($r);
        $data[] = array_map(static fn($c) => $flat[$c[0]] ?? '', $cols);
    }
    $pdf = hr_pdf_table($title, $subtitle, array_map(static fn($c) => [$c[1], $c[2], $c[3]], $cols), $data);
    inc_audit('export', 'report', null, $filename);
    $pdf->send($filename);
}

/** A full evaluation report for one evaluation, as PDF. */
function inc_eval_pdf(int $evalId): HrPdf
{
    inc_need('export');
    $ev = inc_eval($evalId);
    if (!$ev) {
        throw new InvalidArgumentException('That evaluation no longer exists.');
    }
    $app = inc_one('SELECT * FROM inc_applications WHERE id = ?', 'i', [(int) $ev['application_id']]);
    $tpl = inc_template((int) $ev['template_id']);
    $res = inc_eval_result($ev);
    $pdf = new HrPdf();
    $W = $pdf->width();
    $H = $pdf->height();
    $y = 0;
    $page = 0;
    $newPage = static function () use ($pdf, &$y, &$page, $app, $W) {
        $pdf->addPage();
        $page++;
        $pdf->text(30, 30, 'Startup evaluation — ' . ($app['startup_name'] ?: $app['applicant_name']) . ' (' . $app['ref'] . ')', 9, true, 'left', 0.35);
        $pdf->text($W - 30, 30, 'Page ' . $page, 8, false, 'right', 0.5);
        $y = 52;
    };
    $need = static function (float $h) use (&$y, $H, $newPage) {
        if ($y + $h > $H - 40) {
            $newPage();
        }
    };
    $para = static function (string $text, float $size = 9, bool $bold = false, float $indent = 0) use ($pdf, &$y, $W, $need) {
        foreach ($pdf->wrap($text, $W - 60 - $indent, $size, $bold) as $line) {
            $need($size + 4);
            $pdf->text(30 + $indent, $y, $line, $size, $bold);
            $y += $size + 4;
        }
    };
    $newPage();
    $pdf->text(30, $y + 8, 'Evaluation #' . $evalId, 16, true);
    $y += 28;
    $final = $ev['status'] === 'approved';
    $para('Status: ' . (inc_eval_status_labels()[$ev['status']] ?? $ev['status']) . '   ·   Development stage: ' . (inc_dev_stages()[$ev['dev_stage']] ?? $ev['dev_stage'])
          . '   ·   Template version ' . $tpl['version_no'] . '   ·   Calculation version ' . $ev['calc_version']);
    $para('Evaluator: ' . $ev['evaluator_label'] . '   ·   Created ' . inc_fmt_date($ev['created_at']) . ($ev['approved_at'] ? '   ·   Approved ' . inc_fmt_date($ev['approved_at']) . ' by ' . $ev['reviewed_by'] : ''));
    $y += 6;
    if ($final && $ev['score'] !== null) {
        $para('Official score: ' . number_format((float) $ev['score'], 1) . ' / 100', 14, true);
    } elseif (!$res['eligible']) {
        $para('Evaluation Incomplete — no official score. ' . implode(' ', $res['reasons']), 11, true);
    } else {
        $para('Not yet approved — no official score is shown until the evaluation is approved.', 11, true);
    }
    $c = $res['counts'] ?? [];
    $para('Applicable-weight coverage ' . number_format((float) ($res['coverage'] ?? 0), 1) . '%   ·   Rated ' . (int) ($c['rated'] ?? 0) . '   ·   Pending ' . (int) ($c['pending'] ?? 0)
          . '   ·   Insufficient evidence ' . (int) ($c['insufficient'] ?? 0) . '   ·   N/A ' . (int) ($c['na'] ?? 0) . '   ·   Rated pillars ' . (int) ($res['rated_pillars'] ?? 0));
    $y += 8;
    $need(30);
    $pdf->rect(30, $y, $W - 60, 16, 0.93);
    foreach ([['Pillar', 34], ['Weight %', 280], ['Rating /5', 350], ['Contribution', 420], ['State', 500]] as [$h, $x]) {
        $pdf->text($x, $y + 11, $h, 8, true);
    }
    $y += 16;
    foreach ($res['pillars'] ?? [] as $p) {
        $need(14);
        $pdf->text(34, $y + 10, $pdf->fit($p['name'], 240, 8.5), 8.5);
        $pdf->text(280, $y + 10, rtrim(rtrim(number_format((float) $p['weight'], 2), '0'), '.'), 8.5);
        $pdf->text(350, $y + 10, $p['rating'] === null ? '—' : number_format((float) $p['rating'], 2), 8.5);
        $pdf->text(420, $y + 10, $p['contribution'] === null ? '—' : number_format((float) $p['contribution'], 2), 8.5);
        $pdf->text(500, $y + 10, ['rated' => 'Rated', 'na' => 'N/A', 'unrated' => 'Not rated'][$p['state']], 8.5);
        $y += 14;
    }
    $y += 4;
    $para('Final Score = (Σ pillar rating × weight ÷ Σ weight of rated pillars) × 20. N/A pillars are left out of the denominator; Pending and Insufficient Evidence are never counted as zero.', 7.5);
    $y += 8;
    foreach (['Strengths' => $ev['strengths'], 'Improvement areas' => $ev['improvements'], 'Summary' => $ev['summary']] as $h => $txt) {
        if (trim((string) $txt) !== '') {
            $para($h, 10, true);
            $para((string) $txt);
            $y += 4;
        }
    }
    $u = inc_eval_uniqueness($evalId);
    $para('Uniqueness & Differentiation', 11, true);
    $para('Rating: ' . ($res['uniqueness'] === null ? 'not rated' : number_format((float) $res['uniqueness'], 2) . ' / 5'));
    foreach (['Unique value proposition' => $u['uvp'] ?? '', 'Evidence links' => $u['evidence_links'] ?? '', 'Strengths' => $u['strengths'] ?? '',
              'Concerns' => $u['concerns'] ?? '', 'Improvement recommendations' => $u['improvements'] ?? ''] as $h => $txt) {
        if (trim((string) $txt) !== '') {
            $para($h . ': ' . $txt);
        }
    }
    foreach ($u['competitors'] ?? [] as $cp) {
        $para('• ' . $cp['name'] . ($cp['type'] ? ' (' . $cp['type'] . ')' : '') . ($cp['difference'] ? ' — ' . $cp['difference'] : '') . ($cp['evidence'] ? ' [' . $cp['evidence'] . ']' : ''), 8.5, false, 8);
    }
    $y += 6;
    $para('Responses', 11, true);
    $labels = ['pending' => 'Pending', 'insufficient' => 'Insufficient Evidence', 'rated' => 'Rated', 'na' => 'N/A'];
    $resp = inc_eval_responses($evalId);
    $pillarNames = array_column(inc_template_pillars((int) $ev['template_id'], false), 'name', 'code');
    $last = '';
    foreach (inc_eval_questions($ev) as $q) {
        if ($q['pillar_code'] !== $last) {
            $last = $q['pillar_code'];
            $y += 3;
            $para($pillarNames[$last] ?? $last, 9.5, true);
        }
        $r = $resp[(int) $q['id']] ?? ['status' => 'pending'];
        $para('• ' . $q['text'] . ' — ' . $labels[$r['status']] . ($r['status'] === 'rated' ? ' ' . $r['rating'] . '/5' : ''), 8.5, false, 6);
        if (trim((string) ($r['comment'] ?? '')) !== '') {
            $para('Comment: ' . $r['comment'], 8, false, 16);
        }
        if (!empty($r['evidence_url'])) {
            $para('Evidence: ' . $r['evidence_url'], 8, false, 16);
        }
    }
    $recs = inc_all('SELECT * FROM inc_recommendations WHERE evaluation_id = ? OR (application_id = ? AND evaluation_id IS NULL) ORDER BY id', 'ii', [$evalId, (int) $app['id']]);
    if ($recs) {
        $y += 6;
        $para('Recommendations', 11, true);
        foreach ($recs as $r) {
            $para('• ' . $r['text'] . ' [' . str_replace('_', ' ', $r['status']) . ($r['due_date'] ? ', due ' . inc_fmt_date($r['due_date']) : '') . ($r['owner_id'] ? ', ' . inc_person_name((int) $r['owner_id']) : '') . ']', 8.5, false, 6);
        }
    }
    $hist = array_filter(inc_evals_history($app['startup_id'] ? (int) $app['startup_id'] : null, (int) $app['id']), static fn($h) => (int) $h['id'] !== $evalId);
    if ($hist) {
        $y += 6;
        $para('Previous approved evaluations', 11, true);
        foreach ($hist as $h) {
            $para('• ' . inc_fmt_date($h['approved_at']) . ' — ' . ($h['ref']) . ', ' . (inc_dev_stages()[$h['dev_stage']] ?? $h['dev_stage']) . ', template v' . $h['version_no']
                  . ': ' . number_format((float) $h['score'], 1) . '/100' . ($h['uniqueness_rating'] !== null ? ', uniqueness ' . number_format((float) $h['uniqueness_rating'], 2) . '/5' : ''), 8.5, false, 6);
        }
    }
    $pdf->text(30, $H - 22, 'Generated ' . date('d M Y H:i') . ' · Confidential · values as saved', 7, false, 'left', 0.5);
    return $pdf;
}

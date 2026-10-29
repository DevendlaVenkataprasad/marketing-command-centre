<?php
/**
 * Incubation Management — downloadable reports of applications, at any
 * stage: one application, a whole stage, the filtered list, or the ones
 * ticked. Excel carries the list, every form answer, the Gem scores and the
 * evaluations; the PDF is a full report of each application on its own pages.
 */

require_once __DIR__ . '/reports.php';

/** Every applicant's form answers, one row each, a column per question (in the order the forms ask them). */
function inc_report_answers_rows(array $apps): array
{
    $cols = [];                                     // uid => label, first seen wins
    $values = [];
    foreach ($apps as $a) {
        $fields = [];
        foreach (inc_form_fields((int) $a['form_version_id']) as $f) {
            if ($f['type'] === 'section') {
                continue;
            }
            $fields[$f['uid']] = $f;
            $cols[$f['uid']] ??= $f['label'];
        }
        foreach (inc_app_values((int) $a['id']) as $uid => $v) {
            $cols[$uid] ??= $v['label'];
            $values[(int) $a['id']][$uid] = $v['type'] === 'file' ? (string) $v['value'] : inc_value_text($fields[$uid] ?? null, $v['value']);
        }
    }
    $gems = inc_gems();
    $rows = [array_merge(['Application ref', 'Startup', 'Applicant', 'Email', 'Pipeline stage', 'Submitted'], array_values($cols),
                         array_map(static fn($g) => $g['name'] . ' score', array_values($gems)))];
    foreach ($apps as $a) {
        $id = (int) $a['id'];
        $reqs = inc_gem_requests_for_app($id);
        $row = [$a['ref'], $a['startup_name'], $a['applicant_name'], (string) $a['applicant_email'], $a['stage_name'], ['d' => substr((string) $a['submitted_at'], 0, 10)]];
        foreach (array_keys($cols) as $uid) {
            $row[] = (string) ($values[$id][$uid] ?? '');
        }
        foreach (array_keys($gems) as $gid) {
            $s = $reqs[$gid]['score'] ?? null;
            $row[] = $s === null ? '' : ['n' => round((float) $s, 2)];
        }
        $rows[] = $row;
    }
    return $rows;
}

/** A Gem request's answers as [[question, answer], ...]: stored as a list of {uid, label, value} (or, older, uid => value). */
function inc_report_gem_answers(array $r, array $g): array
{
    $raw = json_decode((string) ($r['answers_json'] ?? ''), true) ?: [];
    $out = [];
    if (array_is_list($raw)) {
        foreach ($raw as $x) {
            if (is_array($x) && trim((string) ($x['value'] ?? '')) !== '') {
                $out[] = [(string) ($x['label'] ?? $x['uid'] ?? ''), (string) $x['value']];
            }
        }
        return $out;
    }
    foreach ($g['fields'] as $fd) {
        if (isset($raw[$fd['uid']]) && trim((string) $raw[$fd['uid']]) !== '') {
            $out[] = [$fd['label'], (string) $raw[$fd['uid']]];
        }
    }
    return $out;
}

/** "7.5" rather than "7.50". */
function inc_report_num($n): string
{
    return rtrim(rtrim(number_format((float) $n, 1), '0'), '.');
}

/** Every Gem request of the applications: its answers, score, reasons and what to improve. */
function inc_report_gems_rows(array $apps): array
{
    $rows = [['Application ref', 'Startup', 'Gem', 'Sent', 'Answered', 'Score', 'Out of', 'Summary', 'Score by criterion', 'What to improve', 'Answers']];
    $gems = inc_gems();
    foreach ($apps as $a) {
        foreach (inc_gem_requests_for_app((int) $a['id']) as $gid => $r) {
            $g = $gems[$gid] ?? inc_gem((int) $gid);
            if (!$g) {
                continue;
            }
            $crit = [];
            foreach (json_decode((string) $r['breakdown_json'], true) ?: [] as $b) {
                $crit[] = $b['name'] . ': ' . inc_report_num($b['score']) . '/' . inc_report_num($b['max']) . (!empty($b['reason']) ? ' — ' . $b['reason'] : '');
            }
            $imp = [];
            foreach (inc_gem_improvements($r) as $i => $p) {
                $imp[] = ($i + 1) . '. ' . $p;
            }
            $answers = array_map(static fn($qa) => $qa[0] . ': ' . $qa[1], inc_report_gem_answers($r, $g));
            $rows[] = [$a['ref'], $a['startup_name'] ?: $a['applicant_name'], $g['name'], $r['sent_at'] ? ['d' => substr((string) $r['sent_at'], 0, 10)] : '',
                       $r['submitted_at'] ? ['d' => substr((string) $r['submitted_at'], 0, 10)] : '',
                       $r['score'] === null ? '' : ['n' => round((float) $r['score'], 2)], ['n' => (float) $g['max']], (string) $r['summary'],
                       implode("\n", $crit), implode("\n", $imp), implode("\n\n", $answers)];
        }
    }
    return $rows;
}

/**
 * A full report of each application, every one starting on a new page: its
 * details, every form answer, each Gem's score with the reasons and what to
 * improve, its evaluations, and how it moved through the stages.
 */
function inc_app_report_pdf(array $apps, string $title = 'Application report'): HrPdf
{
    inc_need('export');
    $pdf = new HrPdf();
    $W = $pdf->width();
    $H = $pdf->height();
    $y = 0;
    $page = 0;
    $head = '';
    $newPage = static function () use ($pdf, &$y, &$page, &$head, $W, $H, $title) {
        $pdf->addPage();
        $page++;
        $pdf->text(30, 28, $pdf->fit($title . ($head !== '' ? ' — ' . $head : ''), $W - 120, 8.5, true), 8.5, true, 'left', 0.4);
        $pdf->text($W - 30, 28, 'Page ' . $page, 8, false, 'right', 0.5);
        $pdf->line(30, 34, $W - 30, 34, 0.5, 0.85);
        $pdf->text(30, $H - 22, 'Generated ' . date('d M Y H:i') . ' · Confidential', 7, false, 'left', 0.5);
        $y = 54;
    };
    $need = static function (float $h) use (&$y, $H, $newPage) {
        if ($y + $h > $H - 40) {
            $newPage();
        }
    };
    $para = static function (string $text, float $size = 9, bool $bold = false, float $indent = 0, float $gray = 0.1) use ($pdf, &$y, $W, $need) {
        foreach ($pdf->wrap($text, $W - 60 - $indent, $size, $bold) as $line) {
            $need($size + 4);
            $pdf->text(30 + $indent, $y, $line, $size, $bold, 'left', $gray);
            $y += $size + 4;
        }
    };
    $heading = static function (string $text) use ($pdf, &$y, $W, $need) {
        $need(40);
        $y += 10;
        $pdf->rect(30, $y - 12, $W - 60, 18, 0.94);
        $pdf->text(36, $y + 1, $pdf->fit($text, $W - 80, 10.5, true), 10.5, true);
        $y += 18;
    };
    $kv = static function (array $pairs) use ($pdf, &$y, $W, $need) {
        foreach ($pairs as $k => $v) {
            $v = trim((string) $v);
            if ($v === '' || $v === '—') {
                continue;
            }
            $lines = $pdf->wrap($v, $W - 60 - 140, 9);
            $need(13 * count($lines));
            $pdf->text(30, $y, $k, 8.5, false, 'left', 0.45);
            foreach ($lines as $l) {
                $pdf->text(170, $y, $l, 9);
                $y += 13;
            }
        }
    };
    $gems = inc_gems();
    foreach ($apps as $a) {
        $id = (int) $a['id'];
        $name = $a['startup_name'] ?: $a['applicant_name'];
        $head = $name . ' (' . $a['ref'] . ')';
        $newPage();
        $pdf->text(30, $y + 6, $pdf->fit($name, $W - 60, 17, true), 17, true);
        $y += 24;
        $para($a['ref'] . '   ·   ' . $a['stage_name'] . '   ·   submitted ' . inc_fmt_datetime($a['submitted_at']), 9, false, 0, 0.4);
        $team = trim(inc_team_name($a['team_id'] ? (int) $a['team_id'] : null));
        $owner = trim(inc_person_name($a['owner_id'] ? (int) $a['owner_id'] : null));
        $heading('Details');
        $kv(['Applicant' => $a['applicant_name'], 'Applicant type' => inc_master_name(!empty($a['applicant_type_id']) ? (int) $a['applicant_type_id'] : null), 'Email' => (string) $a['applicant_email'], 'Phone' => (string) ($a['applicant_phone'] ?? ''),
             'Startup' => $a['startup_name'], 'Form' => $a['form_name'], 'Program / event' => (string) ($a['event_name'] ?? ''),
             'Pipeline stage' => $a['stage_name'], 'Source' => inc_master_name($a['source_id'] ? (int) $a['source_id'] : null),
             'Spoke' => inc_master_name($a['spoke_id'] ? (int) $a['spoke_id'] : null), 'Sector' => inc_master_name($a['sector_id'] ? (int) $a['sector_id'] : null),
             'Development stage' => $a['dev_stage'] ? (inc_dev_stages()[$a['dev_stage']] ?? $a['dev_stage']) : '',
             'Team / owner' => implode(' / ', array_filter([$team, $owner])),
             'Evaluation' => (inc_eval_status_labels()[$a['eval_status']] ?? $a['eval_status']) . ($a['official_score'] !== null ? ' — official score ' . inc_report_num($a['official_score']) . '/100' : ''),
             'Incubation' => trim((string) $a['ws_ref'] . ($a['ws_stage_name'] ? ' — ' . $a['ws_stage_name'] : ''))]);

        // The form, as the applicant filled it in.
        $heading('Application form');
        $values = inc_app_values($id);
        if (!$values) {
            $para('No form answers on record.', 9, false, 0, 0.45);
        }
        foreach (inc_form_fields((int) $a['form_version_id']) as $f) {
            if ($f['type'] === 'section') {
                $need(26);
                $y += 4;
                $para(mb_strtoupper($f['label']), 8.5, true, 0, 0.35);
                continue;
            }
            if (!isset($values[$f['uid']])) {
                continue;
            }
            $v = $values[$f['uid']];
            $need(28);
            $para($v['label'], 9, true);
            $para($v['type'] === 'file' ? 'File: ' . $v['value'] : (inc_value_text($f, $v['value']) ?: '—'), 9, false, 10);
            $y += 3;
        }

        // Each Gem: the score, why, what to improve, and what they answered.
        foreach (inc_gem_requests_for_app($id) as $gid => $r) {
            $g = $gems[$gid] ?? inc_gem((int) $gid);
            if (!$g) {
                continue;
            }
            $heading($g['name'] . ($r['score'] !== null ? ' — ' . inc_report_num($r['score']) . ' / ' . inc_report_num($g['max'])
                                                         : ($r['submitted_at'] ? ' — answered, not scored yet' : ' — not answered yet')));
            if (trim((string) $r['summary']) !== '') {
                $para(trim((string) $r['summary']));
                $y += 3;
            }
            foreach (json_decode((string) $r['breakdown_json'], true) ?: [] as $b) {
                $need(26);
                $para($b['name'] . ': ' . inc_report_num($b['score']) . ' / ' . inc_report_num($b['max']), 9, true, 6);
                if (!empty($b['reason'])) {
                    $para((string) $b['reason'], 8.5, false, 16, 0.3);
                }
            }
            if ($imp = inc_gem_improvements($r)) {
                $y += 3;
                $para('What to improve', 9, true);
                foreach ($imp as $i => $p) {
                    $para(($i + 1) . '. ' . $p, 8.5, false, 10);
                }
            }
            if ($qa = inc_report_gem_answers($r, $g)) {
                $y += 3;
                $para('What the applicant answered', 9, true);
                foreach ($qa as [$q, $ans]) {
                    $need(26);
                    $para($q, 8.5, true, 6);
                    $para($ans, 8.5, false, 16);
                }
            }
        }

        // Evaluations.
        $evals = inc_all('SELECT e.*, t.version_no FROM inc_evaluations e JOIN inc_eval_templates t ON t.id = e.template_id WHERE e.application_id = ? ORDER BY e.created_at, e.id', 'i', [$id]);
        if ($evals) {
            $heading('Evaluations');
            foreach ($evals as $e) {
                $para('#' . $e['id'] . ' — ' . (inc_eval_status_labels()[$e['status']] ?? $e['status']) . ' · ' . $e['evaluator_label'] . ' · '
                      . inc_fmt_date($e['created_at']) . ($e['status'] === 'approved' && $e['score'] !== null ? ' · score ' . inc_report_num($e['score']) . '/100' : ''), 9, false, 6);
                foreach (['Strengths' => $e['strengths'], 'Improvement areas' => $e['improvements']] as $k => $txt) {
                    if (trim((string) $txt) !== '') {
                        $para($k . ': ' . $txt, 8.5, false, 16, 0.3);
                    }
                }
            }
        }

        // How it moved.
        $hist = inc_stage_history('crm', $id);
        if ($hist) {
            $heading('Stage history');
            foreach ($hist as $h) {
                $para(inc_fmt_datetime($h['created_at']) . ' — ' . ($h['from_name'] ? $h['from_name'] . ' → ' : '') . $h['to_name']
                      . (trim((string) $h['reason']) !== '' ? ' (' . $h['reason'] . ')' : '') . (!empty($h['actor']) ? ' · ' . $h['actor'] : ''), 8.5, false, 6);
            }
        }
    }
    if (!$apps) {
        $newPage();
        $para('No applications.', 10);
    }
    return $pdf;
}

/**
 * One download of a set of applications, then stops: 'xlsx' (list, every
 * answer, Gem scores, evaluations), 'pdf' (a full report of each), or 'list'
 * (a summary table). $label names the file and the PDF's heading.
 */
function inc_report_download(string $kind, array $ids, string $label): void
{
    inc_need('export');
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        throw new InvalidArgumentException('There are no applications to download.');
    }
    if ($kind === 'pdf' && count($ids) > 300) {
        throw new InvalidArgumentException('Full PDF reports are limited to 300 applications at a time (' . count($ids) . ' chosen). Download Excel for more, or narrow the filters.');
    }
    @set_time_limit(300);
    $apps = inc_report_apps([], $ids);
    $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $label), '-')) ?: 'applications';
    $file = 'incubation-' . $slug . '-' . date('Ymd-His');
    if ($kind === 'xlsx') {
        inc_send_xlsx($file . '.xlsx', ['Applications' => inc_report_applications_rows($apps), 'Form answers' => inc_report_answers_rows($apps),
                                        'Gem scores' => inc_report_gems_rows($apps), 'Evaluations' => inc_report_evaluations_rows($apps)]);
    }
    if ($kind === 'stages') {
        // One sheet per pipeline stage, in pipeline order, after a count of each.
        $by = [];
        foreach ($apps as $a) {
            $by[(int) $a['stage_id']][] = $a;
        }
        $summary = [['Pipeline stage', 'Applications']];
        $sheets = [];
        foreach (inc_stages('crm') as $s) {
            $sid = (int) $s['id'];
            if ((int) $s['active'] !== 1 && empty($by[$sid])) {
                continue;
            }
            $summary[] = [$s['name'], ['n' => count($by[$sid] ?? [])]];
            $sheets[$s['name']] = inc_report_applications_rows($by[$sid] ?? []);
        }
        inc_send_xlsx($file . '-by-stage.xlsx', ['Summary' => $summary] + $sheets);
    }
    if ($kind === 'pdf') {
        inc_audit('export', 'report', null, $file . '.pdf');
        inc_app_report_pdf($apps, count($apps) === 1 ? 'Application report' : 'Applications — ' . $label)->send($file . '.pdf');
        exit;
    }
    inc_send_pdf_table('Incubation applications — ' . $label, count($apps) . ' application' . (count($apps) === 1 ? '' : 's') . ' · ' . date('d M Y H:i'),
        inc_report_applications_rows($apps),
        [[0, 'Ref', 80, 'left'], [2, 'Startup', 120, 'left'], [4, 'Form', 90, 'left'], [8, 'Submitted', 60, 'left'], [9, 'Spoke', 70, 'left'],
         [11, 'Pipeline', 90, 'left'], [12, 'Dev. stage', 75, 'left'], [13, 'Evaluation', 85, 'left'], [14, 'Score', 40, 'right'], [15, 'Uniq.', 35, 'right'],
         [18, 'Owner', 80, 'left']], $file . '.pdf');
    exit;
}

/** The "Download" menu: Excel, full PDF reports, or a summary list. $href(kind) gives each link. */
function inc_download_menu(callable $href, string $what, string $cls = '', bool $byStage = false): string
{
    if (!inc_can('export')) {
        return '';
    }
    return '<details class="inc-dl' . ($cls !== '' ? ' ' . e($cls) : '') . '"><summary class="btn btn-sm" title="Download ' . e($what) . '" aria-label="Download ' . e($what) . '">' . (str_contains($cls, 'kb-dl') ? inc_dl_icon() : inc_dl_icon() . ' Download') . '</summary><div class="inc-dl-menu">'
         . '<div class="inc-dl-what">' . e($what) . '</div>'
         . '<a href="' . e($href('xlsx')) . '"><strong>Excel</strong><span>List, every form answer, Gem scores, evaluations</span></a>'
         . ($byStage ? '<a href="' . e($href('stages')) . '"><strong>Excel — sheet per stage</strong><span>One sheet for each pipeline stage, with a summary</span></a>' : '')
         . '<a href="' . e($href('pdf')) . '"><strong>PDF — full reports</strong><span>Each application on its own pages</span></a>'
         . '<a href="' . e($href('list')) . '"><strong>PDF — summary list</strong><span>One table</span></a></div></details>';
}

/** A small download arrow (an emoji arrow is missing from some fonts). */
function inc_dl_icon(): string
{
    return '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-2px"><path d="M12 4v11M7 10l5 5 5-5M5 20h14"/></svg>';
}

<?php
/**
 * CEO Dashboard — reports. One assembled report (ceo_report_build) feeds the
 * screen, the workbook, the PDF and the scheduled email, so they cannot differ.
 * Every build is made for one person, through their access: an export or an
 * emailed report can show nobody more than their dashboard does.
 */

require_once __DIR__ . '/data.php';
require_once __DIR__ . '/../hr/pdf.php';
require_once __DIR__ . '/../xlsx.php';

function ceo_report_kinds(): array
{
    return [
        'daily' => 'Daily operational report', 'weekly' => 'Weekly management report', 'monthly' => 'Monthly executive report',
        'quarterly' => 'Quarterly performance report', 'annual' => 'Annual performance summary',
        'department' => 'Department report', 'spoke' => 'Spoke report', 'pending' => 'Pending actions report',
        'targets' => 'Targets versus achievement report', 'custom' => 'Custom date-range report',
    ];
}

/** The dates a kind of report covers. Custom and the cut reports keep the dates asked for. */
function ceo_report_window(string $kind, string $from, string $to): array
{
    $today = ceo_today();
    switch ($kind) {
        case 'daily':
            return [$today, $today];
        case 'weekly':
            return [date('Y-m-d', strtotime($today . ' -6 days')), $today];
        case 'monthly':
            return [date('Y-m-01', strtotime($today)), $today];
        case 'quarterly':
            return [ceo_period_window('quarterly', $today)[0], $today];
        case 'annual':
            return [ceo_period_window('annual', $today)[0], $today];
    }
    return [$from, $to];
}

function ceo_fmt($v, string $unit, bool $pdf = false): string
{
    if ($v === null) {
        return '—';
    }
    if ($unit === 'pct') {
        return number_format((float) $v, 1) . '%';
    }
    if ($unit === 'money') {
        return ($pdf ? 'Rs. ' : '₹') . fabo_thousands((float) $v);
    }
    return fabo_thousands((float) $v);
}

function ceo_state_words(string $state): string
{
    return ['no_data' => 'No data', 'restricted' => 'Restricted', 'not_split' => 'Not recorded by spoke', 'not_granted' => 'Module not held', 'not_set_up' => 'Module not set up',
            'failed' => 'Source failed', 'unavailable' => 'Unavailable', 'ok' => ''][$state] ?? $state;
}

function ceo_report_build(array $viewer, int $org, array $a, string $kind, array $f): array
{
    $kinds = ceo_report_kinds();
    [$f['from'], $f['to']] = ceo_report_window($kind, $f['from'], $f['to']);
    $kpis = ceo_kpi_values($viewer, $org, $a, $f);
    $spokes = ceo_spokes($org);

    $kpiRows = [];
    $exceptions = [];
    foreach ($kpis as $k) {
        $kpiRows[] = ['module' => $k['module_label'], 'label' => $k['label'], 'unit' => $k['unit'], 'value' => $k['value'], 'prev' => $k['prev'], 'delta' => $k['delta'],
                      'state' => $k['state'], 'state_label' => ceo_state_words($k['state'])];
        if ($k['state'] === 'failed') {
            $exceptions[] = ['severity' => 'High', 'text' => $k['label'] . ': the source could not be read when this report was made.'];
        }
        if ($k['delta'] !== null && abs((float) $k['delta']) >= 25 && $k['better'] !== 'neutral') {
            $bad = ($k['delta'] < 0) === ($k['better'] === 'up');
            $exceptions[] = ['severity' => $bad ? 'High' : 'Note', 'text' => $k['label'] . ($k['delta'] > 0 ? ' up ' : ' down ') . abs((float) $k['delta']) . ($k['unit'] === 'pct' ? ' points' : '%') . ' on the previous period.'];
        }
    }
    $targets = ceo_targets_eval($viewer, $org, $a, $f);
    foreach ($targets as $t) {
        if ($t['status'] === 'behind') {
            $exceptions[] = ['severity' => 'High', 'text' => $t['label'] . ' (' . $t['spoke_name'] . ', ' . $t['period_type'] . ') is behind target: ' . ceo_fmt($t['actual'], $t['unit']) . ' of ' . ceo_fmt($t['target'], $t['unit']) . '.'];
        }
    }
    $actions = ceo_actions($viewer, $org, $a, $f);
    $late = array_values(array_filter($actions['items'], static fn($i) => in_array($i['status'], ['Overdue', 'Delayed', 'Escalated'], true)));
    if ($late) {
        $exceptions[] = ['severity' => 'High', 'text' => count($late) . ' item' . (count($late) === 1 ? ' is' : 's are') . ' overdue, delayed or escalated.'];
    }

    $completedKeys = ['mk_events', 'mk_tasks_done', 'it_resolved', 'fa_resolved', 'ds_signed', 'inc_new', 'hr_joiners'];
    $completed = [];
    foreach ($completedKeys as $k) {
        if (isset($kpis[$k]) && $kpis[$k]['state'] === 'ok') {
            $completed[] = ['label' => $kpis[$k]['label'], 'module' => $kpis[$k]['module_label'], 'value' => $kpis[$k]['value'], 'unit' => $kpis[$k]['unit']];
        }
    }
    $hub = ceo_hub_spoke($viewer, $org, $a, $f);
    return [
        'kind' => $kind, 'title' => $kinds[$kind] ?? 'Report', 'org' => ceo_org_name($org), 'from' => $f['from'], 'to' => $f['to'],
        'generated_at' => date('Y-m-d H:i'), 'generated_by' => ceo_actor($viewer), 'profile' => $a['profile'],
        'scope' => $f['spoke'] !== '' ? ($spokes[$f['spoke']]['name'] ?? $f['spoke']) : ($a['spokes'] === '*' ? 'All hub and spokes' : 'Authorised spokes only'),
        'module' => !empty($f['module']) ? (ceo_modules()[$f['module']]['label'] ?? '') : '',
        'kpis' => $kpiRows,
        'departments' => ceo_departments($viewer, $org, $a, $f),
        'hub' => $hub,
        'completed' => $completed,
        'activity' => array_values(array_filter(ceo_feed($viewer, $org, $a, $f, false, 200)['items'], static fn($i) => substr((string) $i['when'], 0, 10) >= $f['from'] && substr((string) $i['when'], 0, 10) <= $f['to'])),
        'pending' => array_slice($actions['items'], 0, 300), 'pending_total' => $actions['total'], 'pending_counts' => $actions['counts'],
        'exceptions' => $exceptions,
        'targets' => $targets,
        'coverage' => ceo_coverage($org, $a, $f),
    ];
}

/* ================================================================ workbook */

function ceo_report_xlsx(array $r): FaboXlsx
{
    $x = new FaboXlsx();
    $x->addSheet('Summary', [
        [$r['title'], ''], ['Organisation', $r['org']], ['Period', $r['from'] . ' to ' . $r['to']], ['Scope', $r['scope'] . ($r['module'] !== '' ? ' · ' . $r['module'] : '')],
        ['Generated', $r['generated_at'] . ' by ' . $r['generated_by']], ['Access profile', $r['profile']], ['Open actions', ['n' => $r['pending_total']]], ['Exceptions', ['n' => count($r['exceptions'])]],
    ]);
    $cell = static function ($v, string $unit) {
        if ($v === null) {
            return '';
        }
        return $unit === 'money' ? ['m' => (float) $v] : ($unit === 'pct' ? ['p' => (float) $v / 100] : ['n' => (float) $v]);
    };
    $rows = [['Module', 'KPI', 'Value', 'Previous period', 'Change %', 'Note']];
    foreach ($r['kpis'] as $k) {
        $rows[] = [$k['module'], $k['label'], $cell($k['value'], $k['unit']), $cell($k['prev'], $k['unit']), $k['delta'] === null ? '' : ['n' => (float) $k['delta']], $k['state_label']];
    }
    $x->addSheet('KPI summary', $rows);
    $rows = [['Department', 'State', 'Open items', 'Overdue or delayed', 'Delivered in period', 'Measure', 'Targets on track', 'Targets set']];
    foreach ($r['departments'] as $d) {
        $rows[] = [$d['label'], ceo_state_words($d['state'] === 'ok' ? 'ok' : $d['state']), ['n' => $d['open']], ['n' => $d['late']], $d['done'] === null ? '' : ['n' => (float) $d['done']], $d['done_label'], ['n' => $d['targets_ok']], ['n' => $d['targets']]];
    }
    $x->addSheet('Departments', $rows);
    $head = ['Hub / spoke', 'Type'];
    foreach ($r['hub']['columns'] as $c) {
        $head[] = $c['label'];
    }
    $rows = [array_merge($head, ['Open items', 'Overdue', 'Results submitted %'])];
    foreach ($r['hub']['spokes'] as $s) {
        $line = [$s['name'], ucfirst($s['kind'])];
        foreach ($r['hub']['columns'] as $c) {
            $line[] = $cell($s['values'][$c['key']] ?? null, $c['unit']);
        }
        $rate = $s['compliance']['rate'] ?? null;
        $rows[] = array_merge($line, [['n' => $s['open']], ['n' => $s['overdue']], $rate === null ? '' : ['n' => (float) $rate]]);
    }
    $x->addSheet('Hub and spokes', $rows);
    $rows = [['Measure', 'Module', 'Completed in period']];
    foreach ($r['completed'] as $c) {
        $rows[] = [$c['label'], $c['module'], $cell($c['value'], $c['unit'])];
    }
    $rows[] = ['', '', ''];
    $rows[] = ['Recent activity', 'Module', 'When', 'Detail'];
    foreach (array_slice($r['activity'], 0, 200) as $i) {
        $rows[] = [$i['title'], $i['module_label'], (string) $i['when'], $i['detail']];
    }
    $x->addSheet('Completed activities', $rows);
    $rows = [['Item', 'Detail', 'Module', 'Status', 'Due', 'Spoke', 'Owner']];
    foreach ($r['pending'] as $i) {
        $rows[] = [$i['title'], $i['detail'], $i['module_label'], $i['status'], (string) $i['due'], $i['spoke_name'], $i['owner']];
    }
    $x->addSheet('Pending and overdue', $rows);
    $rows = [['Severity', 'What changed']];
    foreach ($r['exceptions'] as $e) {
        $rows[] = [$e['severity'], $e['text']];
    }
    $x->addSheet('Changes and exceptions', $rows);
    $rows = [['KPI', 'Scope', 'Period', 'Target', 'Actual', 'Achieved %', 'Gap', 'Previous', 'Status', 'Owner']];
    foreach ($r['targets'] as $t) {
        $rows[] = [$t['label'], $t['spoke_name'], ucfirst($t['period_type']), $cell($t['target'], $t['unit']), $cell($t['actual'], $t['unit']), $t['pct'] === null ? '' : ['n' => (float) $t['pct']],
                   $cell($t['gap'], $t['unit']), $cell($t['prev'], $t['unit']), ucwords(str_replace('_', ' ', $t['status'])), $t['owner']];
    }
    $x->addSheet('Targets vs actual', $rows);
    $rows = [['Module', 'State', 'KPIs', 'KPIs by spoke', 'Known limitation']];
    foreach ($r['coverage'] as $c) {
        $rows[] = [$c['label'], $c['state_label'], ['n' => $c['kpis']], ['n' => $c['by_spoke']], $c['gap']];
    }
    $x->addSheet('Data coverage', $rows);
    return $x;
}

/* ================================================================ PDF */

/** The pen on a report: title band, page numbers, tables that carry over a page. */
final class CeoPdf
{
    public HrPdf $p;
    public float $y = 0;
    public int $page = 0;
    public float $W;
    public float $H;
    public float $L = 36;
    private const INK = '#14213d';
    private const ACCENT = '#1d5fd0';

    public function __construct(private array $r)
    {
        $this->p = new HrPdf(false);
        $this->W = $this->p->width();
        $this->H = $this->p->height();
        $this->newPage(true);
    }

    private function foot(): void
    {
        $y = $this->H - 22;
        $this->p->line($this->L, $y - 8, $this->W - $this->L, $y - 8, .4, .85);
        $this->p->text($this->L, $y, $this->r['org'] . ' · ' . $this->r['title'] . ' · Confidential', 7.5, false, 'left', .45);
        $this->p->text($this->W - $this->L, $y, 'Page ' . $this->page, 7.5, false, 'right', .45);
    }

    public function newPage(bool $cover = false): void
    {
        $this->p->addPage();
        $this->page++;
        if ($cover) {
            $this->p->rectRgb(0, 0, $this->W, 104, '#0b1f3f');
            $this->p->rectRgb(0, 104, $this->W, 3, self::ACCENT);
            $this->p->textRgb($this->L, 34, 'CEO DASHBOARD · ' . mb_strtoupper($this->r['org']), 9, true, 'left', '#a9c4f5');
            $this->p->textRgb($this->L, 60, $this->r['title'], 20, true, 'left', '#ffffff');
            $this->p->textRgb($this->L, 80, 'Period ' . $this->r['from'] . ' to ' . $this->r['to'] . ' · ' . $this->r['scope'] . ($this->r['module'] !== '' ? ' · ' . $this->r['module'] : ''), 9.5, false, 'left', '#d7e3fa');
            $this->p->textRgb($this->L, 94, 'Generated ' . $this->r['generated_at'] . ' by ' . $this->r['generated_by'] . ' under the access profile ' . $this->r['profile'], 8, false, 'left', '#a9c4f5');
            $this->y = 130;
        } else {
            $this->y = 44;
        }
        $this->foot();
    }

    public function room(float $need): void
    {
        if ($this->y + $need > $this->H - 48) {
            $this->newPage();
        }
    }

    public function heading(string $n, string $t): void
    {
        $this->room(46);
        $this->y += 10;
        $this->p->textRgb($this->L, $this->y, $n . '  ' . $t, 12.5, true, 'left', self::INK);
        $this->y += 6;
        $this->p->rectRgb($this->L, $this->y, 34, 2, self::ACCENT);
        $this->y += 14;
    }

    public function note(string $t): void
    {
        foreach ($this->p->wrap($t, $this->W - 2 * $this->L, 9) as $line) {
            $this->room(14);
            $this->p->text($this->L, $this->y, $line, 9, false, 'left', .35);
            $this->y += 12.5;
        }
        $this->y += 3;
    }

    /** $cols: [title, width share, align]. Rows are strings. Long text is fitted to its column. */
    public function table(array $cols, array $rows): void
    {
        $full = $this->W - 2 * $this->L;
        $share = array_sum(array_column($cols, 1));
        $head = function () use ($cols, $full, $share) {
            $this->room(40);
            $this->p->rectRgb($this->L, $this->y - 10, $full, 16, '#eef2f8');
            $x = $this->L;
            foreach ($cols as [$t, $w, $al]) {
                $cw = $full * $w / $share;
                $this->p->text($al === 'right' ? $x + $cw - 5 : $x + 5, $this->y, $this->p->fit($t, $cw - 8, 8, true), 8, true, $al, .2);
                $x += $cw;
            }
            $this->y += 15;
        };
        $head();
        foreach ($rows as $row) {
            if ($this->y + 15 > $this->H - 48) {
                $this->newPage();
                $head();
            }
            $x = $this->L;
            foreach ($cols as $i => [, $w, $al]) {
                $cw = $full * $w / $share;
                $this->p->text($al === 'right' ? $x + $cw - 5 : $x + 5, $this->y, $this->p->fit((string) ($row[$i] ?? ''), $cw - 8, 8.5), 8.5, false, $al, .15);
                $x += $cw;
            }
            $this->p->line($this->L, $this->y + 4, $this->L + $full, $this->y + 4, .3, .9);
            $this->y += 14.5;
        }
        $this->y += 8;
    }

    /** Horizontal bars, labelled with their values. Rows are [label, value, hex]. */
    public function bars(array $rows, string $unit): void
    {
        $max = max(1, ...array_map(static fn($r) => (float) $r[1], $rows));
        $labelW = 130;
        $full = $this->W - 2 * $this->L - $labelW - 60;
        foreach ($rows as [$label, $v, $hex]) {
            $this->room(18);
            $this->p->text($this->L, $this->y, $this->p->fit((string) $label, $labelW - 8, 8.5), 8.5, false, 'left', .2);
            $w = max(1.5, $full * (float) $v / $max);
            $this->p->rectRgb($this->L + $labelW, $this->y - 8, $w, 10, $hex);
            $this->p->text($this->L + $labelW + $w + 6, $this->y, ceo_fmt($v, $unit, true), 8.5, true, 'left', .2);
            $this->y += 16;
        }
        $this->y += 6;
    }
}

function ceo_report_pdf(array $r): HrPdf
{
    $d = new CeoPdf($r);
    $hex = [1 => '#2a78d6', 2 => '#eb6834', 3 => '#1baf7a', 4 => '#eda100', 5 => '#e87ba4', 6 => '#008300', 7 => '#4a3aa7', 8 => '#e34948'];

    $d->heading('1', 'KPI summary');
    $d->table([['Module', 18, 'left'], ['KPI', 36, 'left'], ['Value', 16, 'right'], ['Previous', 16, 'right'], ['Change', 12, 'right']],
              array_map(static fn($k) => [$k['module'], $k['label'], $k['state'] === 'ok' ? ceo_fmt($k['value'], $k['unit'], true) : $k['state_label'],
                                          ceo_fmt($k['prev'], $k['unit'], true), $k['delta'] === null ? '—' : ($k['delta'] > 0 ? '+' : '') . $k['delta'] . ($k['unit'] === 'pct' ? ' pts' : '%')], $r['kpis']));

    $d->heading('2', 'Department-wise performance');
    $rows = array_values(array_filter($r['departments'], static fn($x) => $x['open'] > 0));
    if ($rows) {
        $d->note('Open items by department');
        $d->bars(array_map(static fn($x) => [$x['label'], $x['open'], $hex[$x['color']] ?? '#2a78d6'], $rows), 'count');
    }
    $d->table([['Department', 30, 'left'], ['Open', 10, 'right'], ['Late', 10, 'right'], ['Delivered', 12, 'right'], ['Measure', 24, 'left'], ['Targets on track', 14, 'right']],
              array_map(static fn($x) => [$x['label'], $x['state'] === 'ok' ? $x['open'] : ceo_state_words($x['state']), $x['late'], $x['done'] === null ? '—' : fabo_thousands((float) $x['done']), $x['done_label'], $x['targets'] ? $x['targets_ok'] . ' of ' . $x['targets'] : '—'], $r['departments']));

    $d->heading('3', 'Hub-and-spoke comparison');
    if ($r['hub']['spokes']) {
        $cols = array_slice($r['hub']['columns'], 0, 5);
        $head = [['Hub / spoke', 26, 'left']];
        foreach ($cols as $c) {
            $head[] = [$c['label'], 15, 'right'];
        }
        $head[] = ['Open', 8, 'right'];
        $head[] = ['Late', 8, 'right'];
        $d->table($head, array_map(static function ($s) use ($cols) {
            $line = [$s['name'] . ($s['kind'] === 'hub' ? ' (hub)' : '')];
            foreach ($cols as $c) {
                $line[] = ceo_fmt($s['values'][$c['key']] ?? null, $c['unit'], true);
            }
            $line[] = $s['open'];
            $line[] = $s['overdue'];
            return $line;
        }, $r['hub']['spokes']));
    } else {
        $d->note('No hub or spokes are authorised for this report.');
    }

    $d->heading('4', 'Completed activities');
    if ($r['completed']) {
        $d->table([['Measure', 56, 'left'], ['Module', 28, 'left'], ['In period', 16, 'right']],
                  array_map(static fn($c) => [$c['label'], $c['module'], ceo_fmt($c['value'], $c['unit'], true)], $r['completed']));
    } else {
        $d->note('Nothing was completed in this period that the dashboard can measure.');
    }

    $d->heading('5', 'Pending and overdue tasks');
    $d->note($r['pending_total'] . ' open item(s)' . ($r['pending_total'] > 60 ? '; the 60 most urgent are listed. The workbook export carries up to 300.' : '.'));
    if ($r['pending']) {
        $d->table([['Item', 34, 'left'], ['Module', 18, 'left'], ['Status', 12, 'left'], ['Due', 12, 'left'], ['Spoke', 12, 'left'], ['Owner', 12, 'left']],
                  array_map(static fn($i) => [$i['detail'] !== '' ? $i['detail'] : $i['title'], $i['module_label'], $i['status'], (string) $i['due'], $i['spoke_name'], $i['owner']], array_slice($r['pending'], 0, 60)));
    }

    $d->heading('6', 'Significant changes and exceptions');
    if ($r['exceptions']) {
        $d->table([['Severity', 14, 'left'], ['What', 86, 'left']], array_map(static fn($e) => [$e['severity'], $e['text']], $r['exceptions']));
    } else {
        $d->note('No exceptions in this period.');
    }

    $d->heading('7', 'Targets versus actual');
    if ($r['targets']) {
        $d->table([['KPI', 30, 'left'], ['Scope', 14, 'left'], ['Period', 10, 'left'], ['Target', 12, 'right'], ['Actual', 12, 'right'], ['Achieved', 10, 'right'], ['Status', 12, 'left']],
                  array_map(static fn($t) => [$t['label'], $t['spoke_name'], ucfirst($t['period_type']), ceo_fmt($t['target'], $t['unit'], true), ceo_fmt($t['actual'], $t['unit'], true),
                                              $t['pct'] === null ? '—' : $t['pct'] . '%', ucwords(str_replace('_', ' ', $t['status']))], $r['targets']));
    } else {
        $d->note('No targets have been set for what this report covers.');
    }

    $d->heading('8', 'Data coverage and unavailable metrics');
    foreach ($r['coverage'] as $c) {
        $d->note($c['label'] . ' — ' . $c['state_label'] . '. ' . $c['gap']);
    }
    return $d->p;
}

/* ================================================================ log */

function ceo_report_logged(int $org, array $viewer, array $r, string $format, string $spoke): void
{
    fabo_exec('INSERT INTO ceo_reports (org_id, kind, format, date_from, date_to, spoke_key, person_id, actor) VALUES (?,?,?,?,?,?,?,?)', 'isssssis',
              [$org, $r['kind'], $format, $r['from'], $r['to'], $spoke, ceo_viewer_id($viewer), ceo_actor($viewer)]);
    ceo_audit($org, $viewer, $format === 'email' ? 'report_emailed' : 'report_download', $r['title'] . ' (' . $format . ')', ['from' => $r['from'], 'to' => $r['to'], 'spoke' => $spoke]);
}

/* ================================================================ schedules */

function ceo_next_run(string $frequency, string $from): string
{
    $t = strtotime($from);
    switch ($frequency) {
        case 'daily':
            return date('Y-m-d', strtotime('+1 day', $t));
        case 'weekly':
            return date('Y-m-d', strtotime('next monday', $t));
        case 'monthly':
            return date('Y-m-01', strtotime('first day of next month', $t));
        default:
            $to = ceo_period_window('quarterly', date('Y-m-d', $t))[1];
            return date('Y-m-d', strtotime($to . ' +1 day'));
    }
}

/**
 * Sends what is due. Each recipient is judged again at the moment of sending:
 * no longer authorised, no report. The mail carries only the figures that
 * person may see and a link they must sign in to follow; it is never a file.
 */
function ceo_schedules_run(int $org): array
{
    $sent = 0;
    $skipped = 0;
    $today = ceo_today();
    foreach (fabo_all('SELECT * FROM ceo_schedules WHERE org_id = ? AND active = 1 AND next_run <= ?', 'is', [$org, $today]) as $s) {
        // Claimed first, so two callers cannot both send it.
        $next = ceo_next_run((string) $s['frequency'], $today);
        if (fabo_exec('UPDATE ceo_schedules SET next_run = ?, last_run_at = NOW() WHERE id = ? AND next_run = ?', 'sis', [$next, (int) $s['id'], $s['next_run']]) < 1) {
            continue;
        }
        $n = 0;
        $skip = 0;
        foreach ((array) json_decode((string) $s['recipients_json'], true) as $pid) {
            $person = ceo_person_in_org((int) $pid, $org);
            $viewer = $person ? fabo_person_viewer($person) : null;
            $a = $viewer ? ceo_access($viewer, $org) : null;
            $spoke = (string) $s['spoke_key'];
            if (!$a || !ceo_may($a, 'report') || trim((string) $person['email']) === '' || ($spoke !== '' && !ceo_allowed_spoke($a, $spoke))) {
                $skip++;
                continue;
            }
            try {
                $r = ceo_report_build($viewer, $org, $a, (string) $s['kind'], ['from' => $today, 'to' => $today, 'spoke' => $spoke, 'module' => '', 'dept' => 0]);
                ceo_report_mail($org, $person, $r, $spoke);
                ceo_report_logged($org, $viewer, $r, 'email', $spoke);
                $n++;
            } catch (Throwable $e) {
                error_log('FABO ceo schedule ' . $s['id'] . ': ' . $e->getMessage());
                $skip++;
            }
        }
        fabo_exec('UPDATE ceo_schedules SET last_result = ? WHERE id = ?', 'si', [$n . ' sent' . ($skip ? ', ' . $skip . ' not sent' : ''), (int) $s['id']]);
        $sent += $n;
        $skipped += $skip;
    }
    return ['sent' => $sent, 'skipped' => $skipped];
}

function ceo_report_mail(int $org, array $person, array $r, string $spoke): void
{
    require_once __DIR__ . '/../outbox.php';
    require_once __DIR__ . '/../mailtpl.php';
    require_once __DIR__ . '/../evresults.php';
    fabo_link_org($org);
    $base = rtrim((string) fabo_evres_base(), '/') . '/';
    $url = $base . 'ceo.php?' . http_build_query(['p' => 'reports', 'kind' => $r['kind'], 'from' => $r['from'], 'to' => $r['to'], 'spoke' => $spoke]);
    $lines = [];
    $html = '';
    foreach (array_slice(array_values(array_filter($r['kpis'], static fn($k) => $k['state'] === 'ok')), 0, 10) as $k) {
        $lines[] = $k['label'] . ': ' . ceo_fmt($k['value'], $k['unit']);
        $html .= '<tr><td style="padding:6px 12px 6px 0;color:#5f6979">' . e($k['label']) . '</td><td style="padding:6px 0;font-weight:700;text-align:right">' . e(ceo_fmt($k['value'], $k['unit'])) . '</td></tr>';
    }
    $subject = $r['title'] . ' · ' . $r['from'] . ($r['from'] !== $r['to'] ? ' to ' . $r['to'] : '');
    $text = $r['title'] . ' for ' . $r['org'] . "\n" . $r['from'] . ' to ' . $r['to'] . "\n\n" . implode("\n", $lines)
          . "\n\n" . $r['pending_total'] . " open items.\n\nFull report, after you sign in: " . $url;
    $inner = '<p style="margin:0 0 14px">' . e($r['from'] . ' to ' . $r['to']) . ' · ' . e($r['scope']) . '</p><table style="border-collapse:collapse;width:100%">' . $html . '</table>'
           . '<p style="margin:16px 0 0">' . (int) $r['pending_total'] . ' open items.</p><p style="margin:16px 0 0"><a href="' . e($url) . '">Open the full report</a> (sign-in needed).</p>';
    fabo_post_or_queue((string) $person['email'], $subject, $text, fabo_mail_wrap($r['org'], $r['title'], $inner), (int) $person['id'], 'message');
}

/* ================================================================ background */

/** Daily reading, due schedules and retention. Hourly at most; cron.php calls it, and so does the page when cron is not set up. */
function ceo_tick(int $org): void
{
    $last = (int) ceo_setting($org, 'tick_at', '0');
    if (time() - $last < 3600) {
        return;
    }
    ceo_setting_put($org, 'tick_at', (string) time());
    ceo_snapshot_take($org);
    ceo_schedules_run($org);
    fabo_exec('DELETE FROM ceo_cache WHERE expires_at < NOW()');
    fabo_exec('DELETE FROM ceo_audit WHERE org_id = ? AND created_at < DATE_SUB(NOW(), INTERVAL 3 YEAR)', 'i', [$org]);
    fabo_exec('DELETE FROM ceo_reports WHERE org_id = ? AND created_at < DATE_SUB(NOW(), INTERVAL 2 YEAR)', 'i', [$org]);
    fabo_exec('DELETE FROM ceo_snapshots WHERE org_id = ? AND day < DATE_SUB(CURDATE(), INTERVAL 3 YEAR)', 'i', [$org]);
}

function ceo_cron_all(): void
{
    ceo_install();
    foreach (array_keys(ceo_orgs()) as $org) {
        try {
            ceo_tick((int) $org);
        } catch (Throwable $e) {
            error_log('FABO ceo cron org ' . $org . ': ' . $e->getMessage());
        }
    }
}

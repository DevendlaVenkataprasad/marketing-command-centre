<?php
/** Reports and MIS: every report with its filters, drill-down, a timestamp, and Excel / CSV export or a print copy. */

$key = (string) ($_GET['r'] ?? '');
$meta = $key !== '' ? fp_report_meta($key) : null;
$f = fp_report_filters($_GET);
if ($meta && !empty($_GET['export']) && in_array($_GET['export'], ['csv', 'xlsx'], true)) {
    try {
        fp_report_export(fp_report_run($key, $f), (string) $_GET['export']);
    } catch (InvalidArgumentException $e) {
        fabo_deny($e->getMessage(), 400);
    }
}
$print = !empty($_GET['print']);
if ($print && $meta) {
    $rep = fp_report_run($key, $f);
    fabo_head(fp_viewer(), $rep['title'], 'fin-reports');
    echo '<link rel="stylesheet" href="' . e(fabo_asset(fabo_root_prefix() . 'assets/inc.css')) . '"><link rel="stylesheet" href="' . e(fabo_asset(fabo_root_prefix() . 'assets/fin.css')) . '">'
       . '<main class="fp-print"><div class="fp-print-acts"><button class="btn btn-primary" onclick="window.print()">Print / save as PDF</button> <a class="btn" href="' . e(fp_url('reports', array_merge($_GET, ['print' => null]))) . '">Back</a></div>'
       . '<h1>' . e($rep['title']) . '</h1><p>' . e($rep['sub']) . '</p>' . fp_report_html($rep) . ($rep['note'] ? '<p class="small">' . e($rep['note']) . '</p>' : '')
       . '<p class="small muted">' . e($rep['basis']) . ' Generated ' . e($rep['generated']) . '.</p></main>';
    fabo_foot();
    return;
}

if (!$meta) {
    fp_layout_start('Reports', 'Statements, registers and MIS — all from posted entries and the documents behind them.');
    echo '<div class="fp-replist">';
    foreach (fp_report_list() as $group => $items) {
        $links = '';
        foreach ($items as $k => [$label, $perm]) {
            if (fp_can($perm)) {
                $links .= '<li><a href="' . e(fp_url('reports', ['r' => $k])) . '">' . e($label) . '</a></li>';
            }
        }
        if ($links !== '') {
            echo '<section class="card"><h2>' . e($group) . '</h2><ul>' . $links . '</ul></section>';
        }
    }
    echo '</div>';
    fp_layout_end();
    return;
}

try {
    $rep = fp_report_run($key, $f);
} catch (InvalidArgumentException $e) {
    fp_layout_start($meta[0], '', '', ['Reports' => fp_url('reports')], ['err', $e->getMessage()]);
    fp_layout_end();
    return;
}
$q = array_filter(['r' => $key, 'fy' => $f['fy'], 'from' => $f['from'], 'to' => $f['to'], 'entity' => $f['entity'], 'cc' => $f['cc'], 'project' => $f['project'], 'account' => $f['account'],
                   'eliminate' => $f['eliminate'] ? 1 : null, 'status' => $f['status'] ?: null]);
$acts = '<a class="btn btn-sm" href="' . e(fp_url('reports', $q + ['print' => 1])) . '">Print / PDF</a>';
if (fp_can('reports.export')) {
    $acts .= ' <a class="btn btn-sm" href="' . e(fp_url('reports', $q + ['export' => 'xlsx'])) . '">Excel</a> <a class="btn btn-sm" href="' . e(fp_url('reports', $q + ['export' => 'csv'])) . '">CSV</a>';
}
fp_layout_start($rep['title'], e($rep['sub']), $acts, ['Reports' => fp_url('reports'), $rep['title'] => null]);
$fields = ['fy', 'from', 'to', 'entity'];
if (in_array($key, ['pnl', 'tb', 'gl', 'cf', 'bva', 'dept', 'project', 'claims'], true)) {
    $fields = array_merge($fields, ['cc', 'project']);
}
if ($key === 'gl') {
    $fields[] = 'account';
}
if ($key === 'consolidated') {
    $fields[] = 'eliminate';
}
if ($key === 'journals') {
    $fields[] = 'status';
}
if (in_array($key, ['bs', 'ap_aging', 'ar_aging'], true)) {
    $fields = ['to', 'entity'];
}
echo '<section class="card fp-filtercard">' . fp_filters('reports', $fields, $f, $key === 'journals' ? fp_journal_statuses() : [], ['r' => $key]) . '</section>';
foreach ($rep['checks'] as [$tone, $msg]) {
    echo '<div class="' . ($tone === 'ok' ? 'flash ok' : ($tone === 'err' ? 'flash err' : 'inc-warn')) . '">' . e($msg) . '</div>';
}
echo '<section class="card">' . fp_report_html($rep) . ($rep['note'] ? '<p class="small">' . e($rep['note']) . '</p>' : '')
   . '<p class="muted small">' . e($rep['basis']) . ' Generated ' . e($rep['generated']) . '. Click a line to see the entries behind it.</p></section>';
fp_layout_end();

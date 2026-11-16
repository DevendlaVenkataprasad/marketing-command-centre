<?php
/** Reports: pick one, filter it, read it, export it (Excel, PDF, CSV). */

$reps = ds_reports();
$key = (string) ($_GET['r'] ?? '');
if ($key !== '' && !isset($reps[$key])) {
    $key = '';
}
if ($key === 'audit' && !ds_can('audit.view')) {
    $key = '';
}
$f = ds_report_filters($_GET);
if ($key !== '' && in_array($_GET['export'] ?? '', ['xlsx', 'pdf', 'csv'], true)) {
    ds_report_export(ds_report($key, $f), (string) $_GET['export']);
}
$keep = array_filter(['r' => $key, 'from' => $_GET['from'] ?? null, 'to' => $_GET['to'] ?? null, 'dept' => $f['dept'], 'cat' => $f['cat'], 'status' => $f['status'], 'person' => $f['person']]);
if ($key === '') {
    ds_layout_start('Reports', 'Every report respects what you may see. Export any of them to Excel or PDF.');
    echo '<div class="ds-tiles">';
    $i = 0;
    foreach ($reps as $k => [$t, $d, $ic]) {
        if ($k === 'audit' && !ds_can('audit.view')) {
            continue;
        }
        $icon = ['x' => 'x-circle', 'hourglass' => 'hourglass'][$ic] ?? $ic;
        echo '<a class="ds-tile ds-rise" style="--i:' . $i++ . '" href="' . e(ds_url('reports', ['r' => $k] + $keep)) . '"><span class="ds-tile-i">' . ds_icon($icon) . '</span><strong>' . e($t) . '</strong><small>' . e($d) . '</small>' . ds_icon('chev-r', 'ds-i ds-tile-go') . '</a>';
    }
    echo '</div>';
    ds_layout_end();
    return;
}
$rep = ds_report($key, $f);
$exp = ds_can('reports.export') ? '<div class="ds-menu"><button type="button" class="ds-btn ds-btn-primary" data-menu aria-haspopup="true" aria-expanded="false">' . ds_icon('download') . 'Export' . ds_icon('chev-d') . '</button><div class="ds-menu-b" role="menu">'
     . '<a role="menuitem" href="' . e(ds_url('reports', ['export' => 'xlsx'] + $keep)) . '">Excel (.xlsx)</a><a role="menuitem" href="' . e(ds_url('reports', ['export' => 'pdf'] + $keep)) . '">PDF</a>'
     . '<a role="menuitem" href="' . e(ds_url('reports', ['export' => 'csv'] + $keep)) . '">CSV</a></div></div>' : '';
ds_layout_start($rep['title'], e($rep['sub']) . ' · ' . number_format(count($rep['rows'])) . ' rows', $exp, ['Reports' => ds_url('reports'), $rep['title'] => null]);
echo ds_filters('reports', ['from', 'to', 'dept', 'cat', 'status', 'person'], ['from' => $f['from'], 'to' => $f['to'], 'dept' => $f['dept'], 'cat' => $f['cat'], 'status' => $f['status'], 'person' => $f['person']], ['r' => $key]);
if (!empty($rep['summary'])) {
    echo '<div class="ds-kpis small">';
    foreach ($rep['summary'] as $l => $v) {
        echo '<div class="ds-kpi t-blue ds-rise"><span class="ds-kpi-v">' . e((string) $v) . '</span><span class="ds-kpi-l">' . e($l) . '</span></div>';
    }
    echo '</div>';
}
if (!$rep['rows']) {
    echo ds_empty('No records for these filters', 'Widen the dates or clear a filter.', 'chart');
} else {
    echo '<div class="ds-card ds-rise"><div class="ds-tablewrap"><table class="ds-table" data-sortable><thead><tr>';
    foreach ($rep['cols'] as [$l, $type]) {
        echo '<th' . (in_array($type, ['num', 'pct'], true) ? ' class="num"' : '') . '>' . e($l) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach (array_slice($rep['rows'], 0, 1000) as $r) {
        $href = isset($r['_id']) && $r['_id'] ? ds_url('doc', ['id' => $r['_id']]) : (isset($r['_vid']) ? ds_url('verify', ['vid' => $r['_vid']]) : '');
        echo '<tr' . ($href ? ' class="ds-rowlink" data-href="' . e($href) . '"' : '') . '>';
        foreach ($rep['cols'] as $k => [$l, $type]) {
            $v = $r[$k] ?? null;
            $cell = $type === 'pct' && $v !== null ? '<span class="ds-meter"><i style="--w:' . round((float) $v * 100) . '%"></i></span> ' . round((float) $v * 100) . '%' : e(ds_report_cell($v, $type));
            echo '<td' . (in_array($type, ['num', 'pct'], true) ? ' class="num"' : '') . ' data-l="' . e($l) . '"' . ($v !== null && in_array($type, ['num', 'pct'], true) ? ' data-v="' . e((string) $v) . '"' : '') . '>' . $cell . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>' . (count($rep['rows']) > 1000 ? '<p class="ds-muted small ds-pad">Showing the first 1,000 rows; export for all ' . number_format(count($rep['rows'])) . '.</p>' : '') . '</div>';
}
ds_layout_end();

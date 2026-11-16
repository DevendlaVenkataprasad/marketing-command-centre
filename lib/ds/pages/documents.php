<?php
/**
 * The repository: every document the viewer may see, searchable and
 * filterable, sortable by its columns.
 */

[$w, $t, $p] = ds_visible_sql();
$cur = $_GET;
$qs = trim((string) ($_GET['q'] ?? ''));
if ($qs !== '') {
    $w .= ' AND (d.ref_no LIKE ? OR d.title LIKE ? OR d.tracking_code = ? OR d.description LIKE ?)';
    $like = '%' . addcslashes($qs, '%_\\') . '%';
    $t .= 'ssss';
    array_push($p, $like, $like, strtoupper($qs), $like);
}
foreach (['dept' => 'department_id', 'cat' => 'category_id', 'owner' => 'owner_id'] as $k => $col) {
    if ((int) ($_GET[$k] ?? 0) > 0) {
        $w .= ' AND d.' . $col . ' = ?';
        $t .= 'i';
        $p[] = (int) $_GET[$k];
    }
}
$status = (string) ($_GET['status'] ?? '');
if ($status === 'pending') {
    $w .= ' AND d.status IN ("submitted","under_review","pending_first","pending","partial")';
} elseif ($status === 'rejected') {
    $w .= ' AND d.status IN ("rejected","changes_requested")';
} elseif ($status === 'partial') {
    $w .= ' AND d.status IN ("partial","signed")';
} elseif (isset(ds_doc_statuses()[$status])) {
    $w .= ' AND d.status = ?';
    $t .= 's';
    $p[] = $status;
}
if (isset(ds_priorities()[$_GET['priority'] ?? ''])) {
    $w .= ' AND d.priority = ?';
    $t .= 's';
    $p[] = $_GET['priority'];
}
if ((int) ($_GET['person'] ?? 0) > 0) {
    $w .= ' AND EXISTS (SELECT 1 FROM ds_signature_requests rp WHERE rp.doc_id = d.id AND rp.person_id = ?)';
    $t .= 'i';
    $p[] = (int) $_GET['person'];
}
foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
    if ($v = ds_date($_GET[$k] ?? '')) {
        $w .= ' AND DATE(d.created_at) ' . $op . ' ?';
        $t .= 's';
        $p[] = $v;
    }
}
$sorts = ['ref' => 'd.ref_no', 'title' => 'd.title', 'status' => 'd.status', 'created' => 'd.created_at', 'activity' => 'd.last_activity_at', 'priority' => 'FIELD(d.priority,"urgent","high","normal","low")', 'due' => 'd.deadline'];
$s = (string) ($_GET['sort'] ?? '-activity');
$key = ltrim($s, '-');
$order = (isset($sorts[$key]) ? $sorts[$key] : 'd.last_activity_at') . (str_starts_with($s, '-') ? ' DESC' : ' ASC');
$per = 25;
$pg = max(1, (int) ($_GET['pg'] ?? 1));
$total = (int) fabo_one('SELECT COUNT(*) n FROM ds_documents d WHERE ' . $w, $t, $p)['n'];
$rows = fabo_all('SELECT d.* FROM ds_documents d WHERE ' . $w . ' ORDER BY ' . $order . ', d.id DESC LIMIT ' . $per . ' OFFSET ' . (($pg - 1) * $per), $t, $p);
$keepQ = array_diff_key(array_filter($_GET, static fn($v) => $v !== '' && $v !== null), ['p' => 1, 'pg' => 1]);

ds_layout_start('Documents', number_format($total) . ' document' . ($total === 1 ? '' : 's') . ($qs !== '' ? ' matching “' . e($qs) . '”' : '') . ' in the repository.',
    ds_can('docs.create') ? '<a class="ds-btn ds-btn-primary" href="' . e(ds_url('new')) . '">' . ds_icon('upload') . 'Upload document</a>' : '');
echo ds_filters('documents', ['q', 'status', 'cat', 'dept', 'owner', 'person', 'priority', 'from', 'to'], $cur);

$th = static function (string $label, string $k, string $cls = '') use ($s, $keepQ) {
    $dir = $s === $k ? '-' . $k : $k;
    $mark = $s === $k ? ' ↑' : ($s === '-' . $k ? ' ↓' : '');
    return '<th' . ($cls ? ' class="' . $cls . '"' : '') . ' aria-sort="' . ($s === $k ? 'ascending' : ($s === '-' . $k ? 'descending' : 'none')) . '"><a href="'
         . e(ds_url('documents', ['sort' => $dir] + array_diff_key($keepQ, ['sort' => 1]))) . '">' . e($label) . $mark . '</a></th>';
};
if (!$rows) {
    echo ds_empty($qs !== '' || count($keepQ) > 1 ? 'No documents match' : 'No documents yet', $qs !== '' ? 'Try another search or clear the filters.' : 'Upload a PDF to start a signing workflow.', 'files');
} else {
    echo '<div class="ds-card ds-rise"><div class="ds-tablewrap"><table class="ds-table ds-table-docs"><thead><tr>' . $th('Reference', 'ref') . $th('Document', 'title') . '<th>Owner</th>'
       . $th('Status', 'status') . '<th>Progress</th>' . $th('Priority', 'priority') . $th('Deadline', 'due') . $th('Last activity', 'activity') . '</tr></thead><tbody>';
    foreach ($rows as $i => $d) {
        $reqs = (int) $d['cycle'] ? ds_doc_requests((int) $d['id'], (int) $d['cycle']) : [];
        $done = count(array_filter($reqs, static fn($r) => in_array($r['status'], ds_done_statuses(), true)));
        $tot = count(array_filter($reqs, static fn($r) => !in_array($r['status'], ['skipped', 'cancelled'], true)));
        $url = ds_url('doc', ['id' => $d['id']]);
        echo '<tr class="ds-rowlink ds-rise" style="--i:' . min($i, 14) . '" data-href="' . e($url) . '"><td class="mono" data-l="Reference"><a href="' . e($url) . '">' . e($d['ref_no']) . '</a></td>'
           . '<td data-l="Document"><strong class="ds-cell-t">' . e($d['title']) . '</strong><small class="ds-muted">' . e(ds_category_name($d['category_id'] ? (int) $d['category_id'] : null))
           . ($d['department_id'] ? ' · ' . e(ds_dept_name((int) $d['department_id'])) : '') . ($d['confidentiality'] !== 'internal' ? ' · ' . ds_icon('lock') . e(ds_confidentiality()[$d['confidentiality']]) : '') . '</small></td>'
           . '<td data-l="Owner"><span class="ds-who">' . ds_avatar((int) $d['owner_id'], 'sm') . e(ds_person_name((int) $d['owner_id'])) . '</span></td>'
           . '<td data-l="Status">' . ds_status_badge($d['status']) . '</td>'
           . '<td data-l="Progress">' . ($tot ? '<span class="ds-meter"><i style="--w:' . round($done / $tot * 100) . '%"></i></span> <small>' . $done . '/' . $tot . '</small>' : '<span class="ds-muted">—</span>') . '</td>'
           . '<td data-l="Priority">' . ds_priority_chip($d['priority']) . '</td><td data-l="Deadline">' . e(ds_fmt_date($d['deadline'])) . '</td>'
           . '<td data-l="Last activity"><span title="' . e(ds_fmt_dt($d['last_activity_at'])) . '">' . e(ds_ago($d['last_activity_at'] ?: $d['created_at'])) . '</span></td></tr>';
    }
    echo '</tbody></table></div>' . ds_pager($total, $per, $pg, 'documents', $keepQ) . '</div>';
}
ds_layout_end();

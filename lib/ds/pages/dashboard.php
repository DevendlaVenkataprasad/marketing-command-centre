<?php
/**
 * Dashboard: the headline figures, what is waiting, and how signing is
 * going — all limited to the documents the viewer may see, and filterable.
 */

$f = [
    'from' => ds_date($_GET['from'] ?? '') ?? date('Y-m-d', strtotime('-11 months', strtotime(date('Y-m-01')))),
    'to' => ds_date($_GET['to'] ?? '') ?? date('Y-m-d'),
    'dept' => (int) ($_GET['dept'] ?? 0) ?: null, 'cat' => (int) ($_GET['cat'] ?? 0) ?: null,
    'status' => isset(ds_doc_statuses()[$_GET['status'] ?? '']) ? (string) $_GET['status'] : '',
    'priority' => isset(ds_priorities()[$_GET['priority'] ?? '']) ? (string) $_GET['priority'] : '',
    'owner' => (int) ($_GET['owner'] ?? 0) ?: null, 'person' => (int) ($_GET['person'] ?? 0) ?: null,
];
[$w, $t, $p] = ds_doc_filter_sql($f + ['status' => $f['status']]);
if ($f['priority'] !== '') {
    $w .= ' AND d.priority = ?';
    $t .= 's';
    $p[] = $f['priority'];
}
if ($f['owner']) {
    $w .= ' AND d.owner_id = ?';
    $t .= 'i';
    $p[] = $f['owner'];
}
if ($f['person']) {
    $w .= ' AND EXISTS (SELECT 1 FROM ds_signature_requests rp WHERE rp.doc_id = d.id AND rp.person_id = ?)';
    $t .= 'i';
    $p[] = $f['person'];
}
$counts = [];
foreach (fabo_all('SELECT d.status, COUNT(*) n FROM ds_documents d WHERE ' . $w . ' GROUP BY d.status', $t, $p) as $r) {
    $counts[$r['status']] = (int) $r['n'];
}
$total = array_sum($counts);
$pendingSig = (int) fabo_one('SELECT COUNT(*) n FROM ds_signature_requests r JOIN ds_documents d ON d.id = r.doc_id WHERE r.status = "pending" AND ' . $w, $t, $p)['n'];
$mine = ds_my_pending_count();
$signedAny = ($counts['partial'] ?? 0) + ($counts['signed'] ?? 0);
$rejected = ($counts['rejected'] ?? 0) + ($counts['changes_requested'] ?? 0);
$expired = ($counts['expired'] ?? 0) + (int) fabo_one('SELECT COUNT(*) n FROM ds_documents d WHERE d.status = "signed" AND d.valid_until < CURDATE() AND ' . $w, $t, $p)['n'];
$q = array_filter(['from' => $_GET['from'] ?? null, 'to' => $_GET['to'] ?? null, 'dept' => $f['dept'], 'cat' => $f['cat']]);

$acts = (ds_can('docs.create') ? '<a class="ds-btn ds-btn-primary" href="' . e(ds_url('new')) . '">' . ds_icon('plus') . 'New document</a>' : '')
      . (ds_can('reports.view') ? '<a class="ds-btn ds-btn-ghost" href="' . e(ds_url('reports', $q)) . '">' . ds_icon('chart') . 'Reports</a>' : '');
ds_layout_start('Signature dashboard', 'Every document, signature and deadline across ' . e(ds_org_name()) . (ds_is_super() || ds_can_all_docs() ? '' : ' that you can see') . '.', $acts);

echo '<details class="ds-filterbox"' . (array_filter([$_GET['from'] ?? '', $_GET['dept'] ?? '', $_GET['cat'] ?? '', $_GET['status'] ?? '', $_GET['priority'] ?? '', $_GET['owner'] ?? '', $_GET['person'] ?? '']) ? ' open' : '')
   . '><summary>' . ds_icon('filter') . 'Filters <span class="ds-muted">' . e(ds_fmt_date($f['from']) . ' – ' . ds_fmt_date($f['to'])) . '</span></summary>'
   . ds_filters('dashboard', ['from', 'to', 'dept', 'cat', 'status', 'priority', 'owner', 'person'], $_GET) . '</details>';

echo '<div class="ds-kpis">'
   . ds_kpi('Total documents', $total, 'files', 'blue', ds_url('documents', $q), '', 0)
   . ds_kpi('Pending signatures', $pendingSig, 'clock', 'amber', ds_can('monitor.manage') ? ds_url('monitor') : ds_url('documents', ['status' => 'pending'] + $q), 'requests open now', 1)
   . ds_kpi('Awaiting my signature', $mine, 'pen', $mine ? 'violet' : 'grey', ds_url('inbox'), $mine ? 'needs you' : 'all clear', 2)
   . ds_kpi('Documents signed', $signedAny, 'sign', 'green', ds_url('documents', ['status' => 'partial'] + $q), 'at least one signature', 3)
   . ds_kpi('Rejected', $rejected, 'x-circle', 'red', ds_url('documents', ['status' => 'rejected'] + $q), 'incl. changes requested', 4)
   . ds_kpi('Expired', $expired, 'hourglass', 'grey', ds_url('documents', ['status' => 'expired'] + $q), '', 5)
   . ds_kpi('Under review', $counts['under_review'] ?? 0, 'eye', 'blue', ds_url('documents', ['status' => 'under_review'] + $q), '', 6)
   . ds_kpi('Completed', $counts['signed'] ?? 0, 'shield-check', 'green', ds_url('documents', ['status' => 'signed'] + $q), 'fully signed', 7)
   . '</div>';

/* ---- trend: 12 months of documents created and completed */
$months = [];
$end = strtotime(date('Y-m-01', strtotime($f['to'])));
for ($i = 11; $i >= 0; $i--) {
    $k = date('Y-m', strtotime("-$i months", $end));
    $months[$k] = date('M', strtotime($k . '-01'));
}
[$vw, $vt, $vp] = ds_visible_sql();
$created = $completed = array_fill_keys(array_keys($months), 0);
$first = array_key_first($months) . '-01';
foreach (fabo_all('SELECT DATE_FORMAT(d.created_at, "%Y-%m") m, COUNT(*) n FROM ds_documents d WHERE ' . $vw . ' AND d.created_at >= ? GROUP BY m', $vt . 's', array_merge($vp, [$first])) as $r) {
    if (isset($created[$r['m']])) {
        $created[$r['m']] = (int) $r['n'];
    }
}
foreach (fabo_all('SELECT DATE_FORMAT(d.completed_at, "%Y-%m") m, COUNT(*) n FROM ds_documents d WHERE ' . $vw . ' AND d.status = "signed" AND d.completed_at >= ? GROUP BY m', $vt . 's', array_merge($vp, [$first])) as $r) {
    if (isset($completed[$r['m']])) {
        $completed[$r['m']] = (int) $r['n'];
    }
}
$st = ds_doc_statuses();
$byStatus = [];
foreach ($counts as $k => $n) {
    $byStatus[$st[$k][0] ?? $k] = $n;
}
$closed = ($counts['signed'] ?? 0) + $rejected + ($counts['expired'] ?? 0) + ($counts['cancelled'] ?? 0);

echo '<div class="ds-grid g-2-1">';
echo ds_card_start('Documents over 12 months', '', '', 'chart') . ds_linechart(['Created' => $created, 'Fully signed' => $completed], $months, 'Documents created and fully signed per month')
   . '<details class="ds-tableview"><summary>Show as table</summary><table class="ds-table compact"><thead><tr><th>Month</th><th class="num">Created</th><th class="num">Fully signed</th></tr></thead><tbody>';
foreach ($months as $k => $l) {
    echo '<tr><td>' . e(date('M Y', strtotime($k . '-01'))) . '</td><td class="num">' . $created[$k] . '</td><td class="num">' . $completed[$k] . '</td></tr>';
}
echo '</tbody></table></details>' . ds_card_end();
echo ds_card_start('Where documents stand', '', '', 'gauge') . '<div class="ds-ringrow">' . ds_ring($closed ? ($counts['signed'] ?? 0) / $closed : 0, 'of closed are signed', 'green')
   . '<div class="ds-ringnote"><strong>' . number_format($total - $closed - ($counts['draft'] ?? 0)) . '</strong> in progress<br><strong>' . number_format($counts['draft'] ?? 0) . '</strong> drafts</div></div>'
   . ds_hbars($byStatus, '', static function (string $label) use ($st, $q) {
       foreach ($st as $k => $v) {
           if ($v[0] === $label) {
               return ds_url('documents', ['status' => $k] + $q);
           }
       }
       return ds_url('documents');
   }) . ds_card_end();
echo '</div>';

/* ---- what is waiting */
$rowsMine = fabo_all('SELECT r.*, d.ref_no, d.title, d.priority, d.owner_id FROM ds_signature_requests r JOIN ds_documents d ON d.id = r.doc_id
                      WHERE r.org_id = ? AND r.person_id = ? AND r.status = "pending" AND d.deleted_at IS NULL ORDER BY r.due_at IS NULL, r.due_at LIMIT 6', 'ii', [ds_org(), ds_me()]);
$deadlines = fabo_all('SELECT r.*, d.ref_no, d.title FROM ds_signature_requests r JOIN ds_documents d ON d.id = r.doc_id WHERE r.status = "pending" AND r.due_at IS NOT NULL
                       AND r.due_at < DATE_ADD(NOW(), INTERVAL 14 DAY) AND ' . $vw . ' ORDER BY r.due_at LIMIT 8', $vt, $vp);
$reqRow = static function (array $r, bool $mineRow): string {
    $over = $r['due_at'] && ds_ts($r['due_at']) < time();
    $soon = $r['due_at'] && !$over && ds_ts($r['due_at']) < time() + 86400 * 2;
    $href = $mineRow ? ds_url('sign', ['id' => $r['id']]) : ds_url('doc', ['id' => $r['doc_id']]);
    return '<li><a href="' . e($href) . '">' . ds_req_avatar($r, 'sm') . '<span class="ds-li-b"><strong>' . e($r['title']) . '</strong><small>' . e($r['ref_no'])
         . ' · ' . e(ds_actions()[$r['action']] ?? '') . ($mineRow ? '' : ' · ' . e(ds_req_name($r))) . '</small></span>'
         . ($r['due_at'] ? '<span class="ds-due' . ($over ? ' over' : ($soon ? ' soon' : '')) . '">' . ds_icon('clock') . ($over ? 'Overdue ' : 'Due ') . e(date('d M', ds_ts($r['due_at']))) . '</span>' : '')
         . '</a></li>';
};
echo '<div class="ds-grid g-2">';
echo ds_card_start('My signature requests', '<a class="ds-link" href="' . e(ds_url('inbox')) . '">All</a>', '', 'pen');
echo $rowsMine ? '<ul class="ds-list">' . implode('', array_map(static fn($r) => $reqRow($r, true), $rowsMine)) . '</ul>' : ds_empty('Nothing waiting for you', 'When someone needs your signature it appears here.', 'check-circle');
echo ds_card_end();
echo ds_card_start('Upcoming signature deadlines', '', '', 'calendar');
echo $deadlines ? '<ul class="ds-list">' . implode('', array_map(static fn($r) => $reqRow($r, (int) $r['person_id'] === ds_me()), $deadlines)) . '</ul>' : ds_empty('No deadlines in the next two weeks', '', 'calendar');
echo ds_card_end();
echo '</div>';

$docList = static function (array $rows, string $empty, string $icon): string {
    if (!$rows) {
        return ds_empty($empty, '', $icon);
    }
    $h = '<ul class="ds-list">';
    foreach ($rows as $d) {
        $h .= '<li><a href="' . e(ds_url('doc', ['id' => $d['id']])) . '"><span class="ds-fileic">' . ds_icon('file') . '</span><span class="ds-li-b"><strong>' . e($d['title']) . '</strong><small>'
            . e($d['ref_no']) . ' · ' . e(ds_person_name((int) $d['owner_id'])) . ' · ' . e(ds_ago($d['last_activity_at'] ?: $d['created_at'])) . '</small></span>' . ds_status_badge($d['status']) . '</a></li>';
    }
    return $h . '</ul>';
};
$recent = fabo_all('SELECT d.* FROM ds_documents d WHERE ' . $w . ' ORDER BY d.created_at DESC LIMIT 6', $t, $p);
$awaiting = fabo_all('SELECT d.* FROM ds_documents d WHERE ' . $w . ' AND d.status IN ("submitted","under_review","pending_first","pending","partial") ORDER BY d.last_activity_at DESC LIMIT 6', $t, $p);
$rej = fabo_all('SELECT d.* FROM ds_documents d WHERE ' . $w . ' AND d.status IN ("rejected","changes_requested") ORDER BY d.last_activity_at DESC LIMIT 5', $t, $p);
$signed = fabo_all('SELECT r.*, d.ref_no, d.title FROM ds_signature_requests r JOIN ds_documents d ON d.id = r.doc_id WHERE r.status IN ("signed","approved") AND ' . $w . ' ORDER BY r.acted_at DESC LIMIT 6', $t, $p);

echo '<div class="ds-grid g-2">';
echo ds_card_start('Recently uploaded', '<a class="ds-link" href="' . e(ds_url('documents', $q)) . '">All documents</a>', '', 'upload') . $docList($recent, 'No documents yet', 'file') . ds_card_end();
echo ds_card_start('Documents awaiting approval', '', '', 'clock') . $docList($awaiting, 'Nothing is in signing', 'clock') . ds_card_end();
echo ds_card_start('Recently signed', '', '', 'shield-check');
if ($signed) {
    echo '<ul class="ds-list">';
    foreach ($signed as $r) {
        echo '<li><a href="' . e(ds_url('doc', ['id' => $r['doc_id']])) . '">' . ds_req_avatar($r, 'sm') . '<span class="ds-li-b"><strong>' . e(ds_req_name($r)) . ' '
           . e($r['status'] === 'approved' ? 'approved' : 'signed') . '</strong><small>' . e($r['ref_no'] . ' · ' . $r['title']) . ' · ' . e(ds_ago($r['acted_at'])) . '</small></span>' . ds_method_chip($r['used_method']) . '</a></li>';
    }
    echo '</ul>';
} else {
    echo ds_empty('No signatures yet', '', 'sign');
}
echo ds_card_end();
echo ds_card_start('Rejected documents', '', '', 'x-circle') . $docList($rej, 'No rejections', 'check-circle') . ds_card_end();
echo '</div>';

/* ---- departments and signatories */
$byDept = [];
foreach (fabo_all('SELECT d.department_id, COUNT(*) n FROM ds_documents d WHERE ' . $w . ' GROUP BY d.department_id', $t, $p) as $r) {
    $byDept[$r['department_id'] ? ds_dept_name((int) $r['department_id']) : 'No department'] = (int) $r['n'];
}
$perf = ds_report('signatory', ['from' => $f['from'], 'to' => $f['to'], 'dept' => $f['dept'], 'cat' => $f['cat'], 'status' => $f['status'], 'person' => $f['person']]);
echo '<div class="ds-grid g-1-2">';
echo ds_card_start('Department-wise documents', '', '', 'building') . ds_hbars($byDept, '', null, 'No documents in this period.') . ds_card_end();
echo ds_card_start('Signatory performance', ds_can('reports.view') ? '<a class="ds-link" href="' . e(ds_url('reports', ['r' => 'signatory'] + $q)) . '">Full report</a>' : '', '', 'users');
if ($perf['rows']) {
    echo '<div class="ds-tablewrap"><table class="ds-table"><thead><tr><th>Signatory</th><th class="num">Requests</th><th class="num">Completed</th><th class="num">Pending</th><th class="num">Avg hours</th><th>On time</th></tr></thead><tbody>';
    foreach (array_slice($perf['rows'], 0, 8) as $r) {
        $on = $r['ontime'];
        echo '<tr><td><span class="ds-who">' . (is_string($r['_person']) ? ds_req_avatar(['external_id' => (int) substr($r['_person'], 1), 'person_id' => 0], 'sm') : ds_avatar((int) $r['_person'], 'sm')) . e($r['signatory']) . '</span></td><td class="num">' . $r['requests'] . '</td><td class="num">' . $r['completed'] . '</td><td class="num">'
           . $r['pending'] . '</td><td class="num">' . ($r['avg'] === null ? '—' : e(number_format($r['avg'], 1))) . '</td><td>'
           . ($on === null ? '—' : '<span class="ds-meter"><i style="--w:' . round($on * 100) . '%"></i></span> ' . round($on * 100) . '%') . '</td></tr>';
    }
    echo '</tbody></table></div>';
} else {
    echo ds_empty('No signing activity in this period', '', 'users');
}
echo ds_card_end() . '</div>';
ds_layout_end();

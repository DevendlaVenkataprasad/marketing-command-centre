<?php
/** The audit trail, searchable, with a check that no entry was altered, removed or reordered. */

$check = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'check') {
    $check = ds_audit_check();
    ds_audit(null, 'audit', null, 'audit_check', 'Checked the audit trail: ' . $check[3]);
}
$w = 'a.org_id = ?';
$t = 'i';
$p = [ds_org()];
$q = trim((string) ($_GET['q'] ?? ''));
if ($q !== '') {
    $w .= ' AND (a.summary LIKE ? OR a.actor LIKE ? OR a.action = ?)';
    $t .= 'sss';
    array_push($p, '%' . addcslashes($q, '%_\\') . '%', '%' . addcslashes($q, '%_\\') . '%', $q);
}
foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
    if ($v = ds_date($_GET[$k] ?? '')) {
        $w .= ' AND DATE(a.created_at) ' . $op . ' ?';
        $t .= 's';
        $p[] = $v;
    }
}
if ((int) ($_GET['person'] ?? 0) > 0) {
    $w .= ' AND a.actor_id = ?';
    $t .= 'i';
    $p[] = (int) $_GET['person'];
}
$scope = ds_is_super() || ds_can_all_docs() ? null : ds_scope_depts('audit.view');
if ($scope !== null) {
    $w .= ' AND (a.actor_id = ' . ds_me() . ' OR a.doc_id IN (SELECT id FROM ds_documents WHERE department_id IN (' . ($scope ? implode(',', array_map('intval', $scope)) : '0') . ')))';
}
$per = 50;
$pg = max(1, (int) ($_GET['pg'] ?? 1));
$total = (int) fabo_one('SELECT COUNT(*) n FROM ds_audit a WHERE ' . $w, $t, $p)['n'];
$rows = fabo_all('SELECT a.* FROM ds_audit a WHERE ' . $w . ' ORDER BY a.seq DESC LIMIT ' . $per . ' OFFSET ' . (($pg - 1) * $per), $t, $p);
$keep = array_filter(['q' => $q, 'from' => $_GET['from'] ?? null, 'to' => $_GET['to'] ?? null, 'person' => $_GET['person'] ?? null]);
ds_layout_start('Audit trail', 'Every action, sealed in a hash chain with a key the database does not hold. Changing, deleting or reordering any entry is detected.',
    ds_button('Check integrity', ['action' => 'check'], 'ds-btn ds-btn-primary', '', 'shield-check')
    . (ds_can('reports.export') ? '<a class="ds-btn ds-btn-ghost" href="' . e(ds_url('reports', ['r' => 'audit', 'export' => 'xlsx'] + $keep)) . '">' . ds_icon('download') . 'Export</a>' : ''),
    ['Administration' => ds_url('admin'), 'Audit trail' => null]);
if ($check) {
    echo '<div class="ds-verdict t-' . ($check[0] ? 'green' : 'red') . ' ds-rise small"><div class="ds-verdict-i"><svg viewBox="0 0 64 64" aria-hidden="true"><circle cx="32" cy="32" r="28"/></svg>' . ds_icon($check[0] ? 'shield-check' : 'alert') . '</div><div>'
       . '<h2>' . ($check[0] ? 'The trail is intact' : 'The trail has been tampered with') . '</h2><p>' . e($check[3]) . '</p></div></div>';
}
echo ds_filters('audit', ['q', 'from', 'to', 'person'], $_GET);
echo '<div class="ds-card ds-rise"><div class="ds-card-b">' . ds_timeline($rows) . '</div>' . ds_pager($total, $per, $pg, 'audit', $keep) . '</div>';
ds_layout_end();

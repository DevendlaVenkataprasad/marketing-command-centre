<?php
/** Expense claims, for Finance: everyone's, with where each stands; and the claims HR has approved, to bring in for Finance review. */

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_hr') {
    $flash = fp_action(static function () {
        [$made, $skipped] = fp_import_hr_claims();
        return $made . ' HR claim(s) brought in for Finance review.' . ($skipped ? ' Not brought in: ' . implode('; ', array_slice($skipped, 0, 5)) . '.' : '');
    }, fp_url('claims'));
}
$q = array_intersect_key($_GET, array_flip(['q', 'status', 'cc', 'from', 'to']));
$where = ' WHERE 1=1';
$t = '';
$p = [];
if (isset(fp_claim_statuses()[$q['status'] ?? ''])) {
    $where .= ' AND c.status = ?';
    $t .= 's';
    $p[] = $q['status'];
}
if (!empty($q['cc'])) {
    $where .= ' AND c.cost_centre_id = ' . (int) $q['cc'];
}
foreach (['from' => 'DATE(c.created_at) >= ?', 'to' => 'DATE(c.created_at) <= ?'] as $k => $sql) {
    if (fp_safe_date($q[$k] ?? '')) {
        $where .= ' AND ' . $sql;
        $t .= 's';
        $p[] = $q[$k];
    }
}
if (trim((string) ($q['q'] ?? '')) !== '') {
    $where .= ' AND (c.number LIKE ? OR c.employee_name LIKE ? OR c.purpose LIKE ?)';
    $like = '%' . trim((string) $q['q']) . '%';
    $t .= 'sss';
    array_push($p, $like, $like, $like);
}
$rows = fp_all('SELECT c.*, cc.code AS cc_code FROM fp_claims c LEFT JOIN fp_cost_centres cc ON cc.id = c.cost_centre_id' . $where . ' ORDER BY FIELD(c.status, "submitted", "approved", "returned", "draft", "paid", "rejected", "cancelled"), c.id DESC LIMIT 300', $t, $p);
$hrWaiting = 0;
try {
    $hrWaiting = (int) (fabo_one("SELECT COUNT(*) n FROM hr_claims WHERE org_id = ? AND status = 'approved'", 'i', [fp_org()])['n'] ?? 0)
               - (int) fp_val('SELECT COUNT(*) FROM fp_claims WHERE hr_claim_id IS NOT NULL AND status NOT IN ("paid","rejected","cancelled")');
} catch (Throwable $e) {
}
$acts = (fp_self_writable() && fp_me() > 0 ? '<a class="btn" href="' . e(fp_url('claim', ['new' => 1])) . '">+ My claim</a> ' : '')
      . (fp_can('pay.prepare') ? '<a class="btn btn-primary" href="' . e(fp_url('payments', ['tab' => 'propose'])) . '">Reimburse approved claims</a>' : '');
fp_layout_start('Expense claims', 'Approved by the employee\'s manager, reviewed by Finance, posted, then reimbursed.', $acts, [], $flash);
if ($hrWaiting > 0 && fp_writable()) {
    echo '<div class="inc-note"><strong>' . $hrWaiting . ' claim(s) approved in HR</strong> are waiting to be reimbursed. Bring them in for Finance review; when Finance pays them, HR is updated too. '
       . inc_button('Bring in HR claims', ['action' => 'import_hr'], 'btn btn-sm btn-primary') . '</div>';
}
echo '<section class="card fp-filtercard">' . fp_filters('claims', ['q', 'from', 'to', 'cc', 'status'], $q, fp_claim_statuses()) . '</section>';
if (!$rows) {
    echo inc_empty('No claims here.');
} else {
    echo '<div class="scrollx"><table class="data inc-table stack"><thead><tr><th>Claim</th><th>Employee</th><th>Cost centre</th><th>Purpose</th><th class="num">Claimed</th><th class="num">To reimburse</th><th>Status</th></tr></thead><tbody>';
    foreach ($rows as $c) {
        echo '<tr><td data-l="Claim"><a href="' . e(fp_url('claim', ['id' => $c['id']])) . '"><strong>' . e($c['number']) . '</strong></a>' . ($c['hr_claim_id'] ? ' <span class="muted small">from HR</span>' : '') . '</td>'
           . '<td data-l="Employee">' . e($c['employee_name']) . '</td><td data-l="Cost centre">' . e($c['cc_code'] ?? '') . '</td><td data-l="Purpose">' . e(mb_strimwidth($c['purpose'], 0, 60, '…')) . '</td>'
           . fp_money_td($c['total'], 'Claimed') . fp_money_td($c['net_payable'], 'To reimburse') . '<td data-l="Status">' . fp_badge(fp_claim_statuses(), $c['status']) . ($c['policy_flags'] ? ' ' . inc_badge('Policy notes', 'amber') : '') . '</td></tr>';
    }
    echo '</tbody></table></div>';
}
fp_layout_end();

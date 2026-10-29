<?php
/** Finds documents and parties by number, name or reference — only among what the viewer may read. */

$q = trim((string) ($_GET['q'] ?? ''));
fp_layout_start('Search', $q !== '' ? '“' . e($q) . '”' : 'Type a number, a name or a reference.');
if (mb_strlen($q) < 2) {
    echo inc_empty('Type at least two characters.');
    fp_layout_end();
    return;
}
$like = '%' . $q . '%';
$groups = [
    ['journal', 'gl.view', 'SELECT id, number t, description d, jdate dt FROM fp_journals WHERE number LIKE ? OR reference LIKE ? OR description LIKE ? ORDER BY id DESC LIMIT 15', 3],
    ['bill', 'ap.view', 'SELECT b.id, b.number t, CONCAT(v.name, " · ", b.bill_no) d, b.bill_date dt FROM fp_bills b JOIN fp_vendors v ON v.id = b.vendor_id WHERE b.number LIKE ? OR b.bill_no LIKE ? OR v.name LIKE ? ORDER BY b.id DESC LIMIT 15', 3],
    ['vendor', 'ap.view', 'SELECT id, name t, CONCAT(code, " ", COALESCE(gstin, "")) d, NULL dt FROM fp_vendors WHERE name LIKE ? OR code LIKE ? OR gstin LIKE ? OR pan LIKE ? LIMIT 15', 4],
    ['po', 'proc.view', 'SELECT p.id, p.number t, v.name d, p.po_date dt FROM fp_pos p JOIN fp_vendors v ON v.id = p.vendor_id WHERE p.number LIKE ? OR v.name LIKE ? ORDER BY p.id DESC LIMIT 15', 2],
    ['invoice', 'ar.view', 'SELECT i.id, i.number t, c.name d, i.invoice_date dt FROM fp_invoices i JOIN fp_customers c ON c.id = i.customer_id WHERE i.number LIKE ? OR c.name LIKE ? OR i.customer_po LIKE ? ORDER BY i.id DESC LIMIT 15', 3],
    ['customer', 'ar.view', 'SELECT id, name t, CONCAT(code, " ", COALESCE(gstin, "")) d, NULL dt FROM fp_customers WHERE name LIKE ? OR code LIKE ? OR gstin LIKE ? LIMIT 15', 3],
    ['receipt', 'ar.view', 'SELECT r.id, r.number t, c.name d, r.receipt_date dt FROM fp_receipts r JOIN fp_customers c ON c.id = r.customer_id WHERE r.number LIKE ? OR r.reference LIKE ? OR c.name LIKE ? ORDER BY r.id DESC LIMIT 15', 3],
    ['payment', 'pay.view', 'SELECT id, number t, payee_name d, pay_date dt FROM fp_payments WHERE number LIKE ? OR payee_name LIKE ? OR bank_ref LIKE ? ORDER BY id DESC LIMIT 15', 3],
    ['claim', 'claims.view', 'SELECT id, number t, CONCAT(employee_name, " · ", purpose) d, DATE(created_at) dt FROM fp_claims WHERE number LIKE ? OR employee_name LIKE ? OR purpose LIKE ? ORDER BY id DESC LIMIT 15', 3],
    ['budget', 'budget.view', 'SELECT id, number t, name d, NULL dt FROM fp_budgets WHERE number LIKE ? OR name LIKE ? LIMIT 15', 2],
    ['asset', 'assets.view', 'SELECT id, tag t, name d, acquired_on dt FROM fp_assets WHERE tag LIKE ? OR name LIKE ? OR serial LIKE ? LIMIT 15', 3],
];
$any = false;
foreach ($groups as [$type, $perm, $sql, $n]) {
    if (!fp_can($perm)) {
        continue;
    }
    $rows = fp_all($sql, str_repeat('s', $n), array_fill(0, $n, $like));
    if (!$rows) {
        continue;
    }
    $any = true;
    $page = ['customer' => 'customer'][$type] ?? fp_doc_types()[$type]['page'];
    echo '<section class="card"><h2>' . e($type === 'customer' ? 'Customers' : fp_doc_types()[$type]['label'] . 's') . '</h2><ul class="fp-files">';
    foreach ($rows as $r) {
        echo '<li><a href="' . e(fp_url($page, ['id' => $r['id']])) . '"><strong>' . e($r['t']) . '</strong></a> ' . e((string) $r['d']) . ($r['dt'] ? ' <span class="muted small">' . e(fp_fmt_date($r['dt'])) . '</span>' : '') . '</li>';
    }
    echo '</ul></section>';
}
// Everyone may find their own claims and requests.
if (!fp_can('claims.view') && fp_me() > 0) {
    $own = fp_all('SELECT id, number, purpose FROM fp_claims WHERE employee_person_id = ? AND (number LIKE ? OR purpose LIKE ?) LIMIT 15', 'iss', [fp_me(), $like, $like]);
    if ($own) {
        $any = true;
        echo '<section class="card"><h2>My claims</h2><ul class="fp-files">' . implode('', array_map(static fn($r) => '<li><a href="' . e(fp_url('claim', ['id' => $r['id']])) . '">' . e($r['number']) . '</a> ' . e($r['purpose']) . '</li>', $own)) . '</ul></section>';
    }
}
if (!$any) {
    echo inc_empty('Nothing found.', 'Try a document number (BILL/26-27/0004), a vendor or customer name, a GSTIN or a bank reference.');
}
fp_layout_end();

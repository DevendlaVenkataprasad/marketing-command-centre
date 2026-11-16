<?php
/** The administrator console: the organisation's whole signing activity and every configuration desk. */

$cards = array_filter(ds_admin_pages(), static fn($a) => ds_can($a[2]));
if (!$cards) {
    fabo_deny('Administration is not part of your role.', 403);
}
$o = ds_org();
$n = static fn(string $sql, array $p = []) => (int) (fabo_one($sql, str_repeat('i', count($p)), $p)['n'] ?? 0);
$stats = [
    'Documents' => $n('SELECT COUNT(*) n FROM ds_documents WHERE org_id = ? AND deleted_at IS NULL', [$o]),
    'In signing' => $n('SELECT COUNT(*) n FROM ds_documents WHERE org_id = ? AND deleted_at IS NULL AND status IN ("submitted","under_review","pending_first","pending","partial")', [$o]),
    'Pending requests' => $n('SELECT COUNT(*) n FROM ds_signature_requests WHERE org_id = ? AND status = "pending"', [$o]),
    'Overdue' => $n('SELECT COUNT(*) n FROM ds_signature_requests WHERE org_id = ? AND status = "pending" AND due_at < NOW()', [$o]),
    'Digital signatures' => $n('SELECT COUNT(*) n FROM ds_signature_requests WHERE org_id = ? AND used_method IN ("esign","dsc") AND status = "signed"', [$o]),
    'Signatories' => $n('SELECT COUNT(*) n FROM ds_signatories WHERE org_id = ? AND status = "active"', [$o]),
];
$esign = ds_provider_for('esign');
$anchors = count(ds_anchors());
$checks = [
    [$esign !== null && ds_provider_check($esign)[0], 'Aadhaar eSign provider configured', 'providers'],
    [$anchors > 0, $anchors . ' trusted root / intermediate certificate' . ($anchors === 1 ? '' : 's') . ' in the trust store', 'trust'],
    [(bool) ds_workflows(true), count(ds_workflows(true)) . ' active workflow(s)', 'workflows'],
    [(bool) ds_departments(), count(ds_departments()) . ' department(s)', 'settings'],
    [ds_setting('last_cron') !== '' && strtotime(ds_setting('last_cron')) > time() - 7200, 'Reminders & escalations ran ' . (ds_setting('last_cron') ? ds_ago(ds_setting('last_cron')) : 'never'), 'monitor'],
];
ds_layout_start('Administration', 'The whole organisation\'s signing activity, and every setting that shapes it.', '', [], null, 'Digital Signatures · ' . ds_org_name());
echo '<div class="ds-kpis small">';
$i = 0;
foreach ($stats as $l => $v) {
    echo ds_kpi($l, $v, ['files', 'clock', 'pen', 'alert', 'shield-check', 'users'][$i], ['blue', 'amber', 'violet', $v ? 'red' : 'grey', 'green', 'blue'][$i], '', '', $i);
    $i++;
}
echo '</div><div class="ds-grid g-2-1"><div class="ds-tiles">';
$i = 0;
foreach ($cards as $k => [$t, $d, $perm, $ic]) {
    echo '<a class="ds-tile ds-rise" style="--i:' . $i++ . '" href="' . e(ds_url($k)) . '"><span class="ds-tile-i">' . ds_icon($ic) . '</span><strong>' . e($t) . '</strong><small>' . e($d) . '</small>' . ds_icon('chev-r', 'ds-i ds-tile-go') . '</a>';
}
echo '</div>' . ds_card_start('Readiness', '', '', 'check-circle') . '<ul class="ds-ready">';
foreach ($checks as [$ok, $txt, $pg]) {
    echo '<li class="' . ($ok ? 'ok' : 'no') . '">' . ds_icon($ok ? 'check-circle' : 'alert') . '<a href="' . e(ds_url($pg)) . '">' . e($txt) . '</a></li>';
}
echo '</ul>' . ds_card_end() . '</div>';
ds_layout_end();

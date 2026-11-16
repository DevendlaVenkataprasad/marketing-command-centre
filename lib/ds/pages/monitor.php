<?php
/** Signature monitor: everything pending in scope — remind, escalate, follow up. */

[$w, $t, $p] = ds_visible_sql();
$scope = ds_scope_depts('monitor.manage');
if ($scope !== null) {
    $w .= ' AND d.department_id IN (' . ($scope ? implode(',', array_map('intval', $scope)) : '0') . ')';
}
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $flash = ds_action(static function () use ($w, $t, $p) {
        $ids = array_map('intval', (array) ($_POST['req'] ?? []));
        if (!$ids) {
            throw new InvalidArgumentException('Tick the requests first.');
        }
        $rows = fabo_all('SELECT r.*, d.ref_no, d.title, d.plan_json FROM ds_signature_requests r JOIN ds_documents d ON d.id = r.doc_id WHERE r.status = "pending" AND r.id IN ('
                         . implode(',', $ids) . ') AND ' . $w, $t, $p);
        $n = 0;
        foreach ($rows as $r) {
            if (($_POST['do'] ?? '') === 'escalate') {
                ds_escalate($r, json_decode((string) $r['plan_json'], true) ?: []);
            } else {
                fabo_exec('UPDATE ds_signature_requests SET reminders = reminders + 1, last_reminded_at = NOW() WHERE id = ?', 'i', [(int) $r['id']]);
                ds_notify_req($r, 'reminder', 'Reminder: ' . $r['ref_no'] . ' is waiting for you', '"' . $r['title'] . '" is waiting for you' . ($r['due_at'] ? ' (due ' . ds_fmt_dt($r['due_at']) . ')' : '') . '.',
                          (int) $r['doc_id'], ['p' => 'sign', 'id' => (int) $r['id']]);
                ds_audit((int) $r['doc_id'], 'request', (int) $r['id'], 'reminder', ds_actor() . ' reminded ' . ds_req_name($r) . ' from the monitor');
            }
            $n++;
        }
        return (($_POST['do'] ?? '') === 'escalate' ? 'Escalated ' : 'Reminded ') . $n . ' request' . ($n === 1 ? '' : 's') . '.';
    }, ds_url('monitor'));
}
$rows = fabo_all('SELECT r.*, d.ref_no, d.title, d.priority, d.department_id FROM ds_signature_requests r JOIN ds_documents d ON d.id = r.doc_id WHERE r.status = "pending" AND ' . $w
                 . ' ORDER BY r.due_at IS NULL, r.due_at LIMIT 500', $t, $p);
$over = count(array_filter($rows, static fn($r) => $r['due_at'] && ds_ts($r['due_at']) < time()));
$soon = count(array_filter($rows, static fn($r) => $r['due_at'] && ds_ts($r['due_at']) >= time() && ds_ts($r['due_at']) < time() + 172800));
$esc = count(array_filter($rows, static fn($r) => $r['escalated_at']));
ds_layout_start('Signature monitor', 'Every request waiting right now' . ($scope !== null ? ' in your departments' : '') . '. Reminders also go out on their own at each workflow\'s interval.', '',
    ds_can_any(['workflows.manage', 'settings.manage']) ? ['Administration' => ds_url('admin'), 'Monitor' => null] : [], $flash);
echo '<div class="ds-kpis small">' . ds_kpi('Pending', count($rows), 'clock', 'amber') . ds_kpi('Overdue', $over, 'alert', $over ? 'red' : 'grey', '', '', 1)
   . ds_kpi('Due in 48 h', $soon, 'calendar', 'violet', '', '', 2) . ds_kpi('Escalated', $esc, 'bell', 'blue', '', '', 3) . '</div>';
if (!$rows) {
    echo ds_empty('Nothing is pending', 'Every document in scope is either signed, closed or a draft.', 'check-circle');
    ds_layout_end();
    return;
}
echo ds_form('data-bulk') . '<div class="ds-card ds-rise"><div class="ds-bulkbar"><label class="ds-check"><input type="checkbox" data-checkall> Select all</label><span data-bulkcount>0 selected</span>'
   . '<button class="ds-btn ds-btn-sm" name="do" value="remind">' . ds_icon('bell') . 'Remind</button><button class="ds-btn ds-btn-sm" name="do" value="escalate" data-confirm="Escalate the selected requests now?">' . ds_icon('alert') . 'Escalate</button></div>'
   . '<div class="ds-tablewrap"><table class="ds-table"><thead><tr><th></th><th>Document</th><th>Waiting on</th><th>Stage</th><th>Since</th><th>Due</th><th class="num">Reminders</th><th>State</th></tr></thead><tbody>';
foreach ($rows as $r) {
    $o = $r['due_at'] && ds_ts($r['due_at']) < time();
    echo '<tr class="' . ($o ? 'is-over' : '') . '"><td><input type="checkbox" name="req[]" value="' . (int) $r['id'] . '" aria-label="Select"></td>'
       . '<td data-l="Document"><a href="' . e(ds_url('doc', ['id' => $r['doc_id']])) . '"><strong class="ds-cell-t">' . e($r['title']) . '</strong></a><small class="ds-muted mono">' . e($r['ref_no']) . '</small></td>'
       . '<td data-l="Waiting on"><span class="ds-who">' . ds_req_avatar($r, 'sm') . e(ds_req_name($r)) . '</span></td><td data-l="Stage">' . e((string) $r['stage_name']) . '</td>'
       . '<td data-l="Since">' . e(ds_ago($r['activated_at'])) . '</td><td data-l="Due">' . ($r['due_at'] ? '<span class="ds-due' . ($o ? ' over' : '') . '">' . e(ds_fmt_dt($r['due_at'])) . '</span>' : '—') . '</td>'
       . '<td class="num" data-l="Reminders">' . (int) $r['reminders'] . '</td><td data-l="State">' . ($r['escalated_at'] ? ds_badge('Escalated', 'violet') : ($o ? ds_badge('Overdue', 'red') : ds_badge('On time', 'green'))) . '</td></tr>';
}
echo '</tbody></table></div></div></form>';
ds_layout_end();

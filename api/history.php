<?php
/** The per-row history panel, straight from activity_audit. */

require_once __DIR__ . '/../lib/endpoint.php';

[$viewer, $key, $sheet, $perm] = fabo_endpoint('view');

$id = (int) ($_REQUEST['id'] ?? 0);
if (!fabo_load_row($viewer, $key, $id)) {
    fabo_json_fail('Row not found.', 404);
}

$labels = [];
foreach ($sheet['columns'] as $c) {
    $labels[$c[1]] = $c[0];
}

$out = [];
foreach (fabo_all('SELECT who, field, old_value, new_value, changed_at
                   FROM activity_audit WHERE table_name = ? AND row_id = ?
                   ORDER BY changed_at DESC, id DESC LIMIT 200',
                  'si', [$sheet['table'], $id]) as $r) {
    $out[] = [
        'who'   => $r['who'],
        'field' => $labels[$r['field']] ?? $r['field'],
        'old'   => $r['old_value'],
        'new'   => $r['new_value'],
        'when'  => date('d M Y, H:i', strtotime($r['changed_at'])),
    ];
}

fabo_json(['ok' => true, 'entries' => $out]);

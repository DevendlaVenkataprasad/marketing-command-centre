<?php
/** Live sync — every row of one sheet, scoped and computed. */

require_once __DIR__ . '/../lib/endpoint.php';

[$viewer, $key, $sheet, $perm] = fabo_endpoint('view');

$rows = fabo_rows($key, $viewer);
$out  = [];
foreach ($rows as $r) {
    $out[] = fabo_row_payload($sheet, $r);
}

fabo_json(['ok' => true, 'perm' => $perm, 'rows' => $out]);

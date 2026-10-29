<?php
/** Admin — one sheet's grid. */

require_once __DIR__ . '/../lib/sheetpage.php';
fabo_boot();
fabo_require_admin();

$viewer = fabo_admin_viewer();
$key    = (string) ($_GET['sheet'] ?? 'master');

if (!fabo_sheet($key)) {
    fabo_deny('Unknown sheet.', 404);
}

fabo_sheet_page($viewer, $key);

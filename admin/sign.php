<?php
/**
 * Admin — Digital Signatures: ours, and (to support a client) theirs. One
 * front controller; the pages live in lib/ds/pages/.
 */

require_once __DIR__ . '/../lib/ui.php';
require_once __DIR__ . '/../lib/ds/app.php';
fabo_boot();
fabo_require_admin();

ds_main(fabo_admin_viewer());

<?php
/**
 * Admin — Finance Portal: our organisation's books (and, to support a
 * client, theirs). One front controller; the pages live in lib/fin/pages/.
 * Client billing — the invoices we raise to clients — stays on finance.php.
 */

require_once __DIR__ . '/../lib/ui.php';
require_once __DIR__ . '/../lib/fin/app.php';
fabo_boot();
fabo_require_admin();

fp_main(fabo_admin_viewer());

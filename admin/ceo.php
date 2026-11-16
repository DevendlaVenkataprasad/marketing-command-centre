<?php
/**
 * Admin — CEO Dashboard: ours, and any organisation holding the module.
 */

require_once __DIR__ . '/../lib/ui.php';
require_once __DIR__ . '/../lib/ceo/app.php';
fabo_boot();
fabo_require_admin();

ceo_main(fabo_admin_viewer());

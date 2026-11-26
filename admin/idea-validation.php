<?php
/**
 * Admin — Startup Discovery, for any organisation that holds the module
 * (picked at the top of the page; ours by default). Administrator only.
 * See lib/iv/.
 */

require_once __DIR__ . '/../lib/ui.php';
require_once __DIR__ . '/../lib/iv/app.php';
fabo_boot();
fabo_require_admin();

iv_main(fabo_admin_viewer());

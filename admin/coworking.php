<?php
/**
 * Admin — Co-working Space, for any organisation that holds the module
 * (picked at the top of the page). Administrator only. See lib/cw/.
 */

require_once __DIR__ . '/../lib/ui.php';
require_once __DIR__ . '/../lib/cw/app.php';
fabo_boot();
fabo_require_admin();

cw_main(fabo_admin_viewer());

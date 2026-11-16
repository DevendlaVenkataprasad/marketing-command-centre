<?php
/**
 * Admin — Event Organizers. The same pages as organizers.php, on our own events
 * (lib/eo on the application engine of lib/inc, tables eo_*).
 */

require_once __DIR__ . '/../lib/ui.php';
require_once __DIR__ . '/../lib/inc/app.php';
fabo_boot();
fabo_require_admin();

inc_use_kind('eo');
inc_app_main(fabo_admin_viewer());

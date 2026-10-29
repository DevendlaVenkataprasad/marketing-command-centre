<?php
/**
 * Admin — Admin & Facilities.
 *
 * The same desk engine as IT Management (lib/it/), on the facilities desk:
 * building requests, support staff, duty, housekeeping, visitors, registers,
 * supplies, facility assets, contracts and vendors. Pages in lib/it/pages/.
 */

require_once __DIR__ . '/../lib/ui.php';
require_once __DIR__ . '/../lib/it/app.php';
fabo_boot();
fabo_require_admin();

it_app_main(fabo_admin_viewer(), 'fa');

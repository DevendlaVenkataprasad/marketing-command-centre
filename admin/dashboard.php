<?php
/** Admin — the live dashboard. */
require_once __DIR__ . '/../lib/dashboard.php';
fabo_boot();
fabo_require_admin();
fabo_dashboard_page(fabo_admin_viewer());

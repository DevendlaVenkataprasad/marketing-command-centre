<?php
/**
 * Admin — Incubation Management.
 *
 * One front controller for every incubation desk (?p=dashboard,
 * applications, screening, application, evaluate, workspaces, team,
 * reports, settings...). It is a separate CRM from admin/crm.php: its own
 * tables (inc_*), pipelines, assignments and reports. The pages live in
 * lib/inc/pages/.
 */

require_once __DIR__ . '/../lib/ui.php';
require_once __DIR__ . '/../lib/inc/app.php';
fabo_boot();
fabo_require_admin();

inc_app_main(fabo_admin_viewer());

<?php
/**
 * Admin — IT Management.
 *
 * One front controller for the IT desk (?p=dashboard, tickets, ticket,
 * assets, asset, renewals, vendors, settings). Administrator only; the
 * pages live in lib/it/pages/. Staff raise requests through the private
 * link it-request.php when the administrator switches it on.
 */

require_once __DIR__ . '/../lib/ui.php';
require_once __DIR__ . '/../lib/it/app.php';
fabo_boot();
fabo_require_admin();

it_app_main(fabo_admin_viewer());

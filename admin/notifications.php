<?php
/** The notification centre for the admin. */

require_once __DIR__ . '/../lib/inbox.php';
fabo_boot();
fabo_require_admin();

header('X-Robots-Tag: noindex, nofollow');

$viewer = fabo_admin_viewer();
$flash  = fabo_inbox_action($viewer);
fabo_inbox_page($viewer, $flash);

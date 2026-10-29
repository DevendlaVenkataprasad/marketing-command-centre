<?php
/** The notification centre for a client or team member. */

require_once __DIR__ . '/lib/inbox.php';
fabo_boot();

header('X-Robots-Tag: noindex, nofollow');

$viewer = fabo_portal_viewer((string) ($_GET['k'] ?? ''));
if (!$viewer) {
    header('Location: login.php?next=' . rawurlencode('notifications.php'));
    exit;
}

$flash = fabo_inbox_action($viewer);
fabo_inbox_page($viewer, $flash);

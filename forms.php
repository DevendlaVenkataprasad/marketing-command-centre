<?php
/**
 * An old way in to the requisition forms desk.
 *
 * The desk is not a page of its own any more: it renders on the Requisition
 * sheet, underneath the queue it fills, because they were always one job
 * being done in two places. This file stays so that a bookmark, or a link
 * sent to somebody last month, still lands somewhere useful.
 */

require_once __DIR__ . '/lib/ui.php';
fabo_boot();

header('X-Robots-Tag: noindex, nofollow');

$viewer = fabo_portal_viewer((string) ($_GET['k'] ?? ''));
if (!$viewer) {
    header('Location: login.php?next=' . rawurlencode('c.php?sheet=requisitions'));
    exit;
}
/* Whoever may open the sheet may use the desk on it. Anybody else is told
   the same thing the sheet would tell them, rather than being sent round a
   loop between two pages that each point at the other. */
if (fabo_permission($viewer, 'requisitions') === 'none') {
    fabo_deny('Your account does not have the Requisitions sheet.', 403);
}
header('Location: ' . fabo_link($viewer, 'c.php', ['sheet' => 'requisitions']));

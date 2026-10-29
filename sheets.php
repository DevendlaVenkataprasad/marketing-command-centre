<?php
/**
 * Sheet Management, for a client running their own organisation.
 *
 * The desk itself is admin/sheets.php. This is not a copy of it — a copy
 * would drift, and the half that drifted would be the half nobody was
 * looking at. It resolves who is asking, leaves them where the desk looks
 * for them, and hands over.
 *
 * What makes the shared page safe for a client is the scope it works at:
 * see fabo_manage_open() in lib/manage.php.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/manage.php';
fabo_boot();

header('X-Robots-Tag: noindex, nofollow');

$viewer = fabo_portal_viewer((string) ($_GET['k'] ?? ''));
if (!$viewer) {
    header('Location: login.php?next=' . rawurlencode('sheets.php'));
    exit;
}
if (!fabo_may_manage($viewer, 'sheets')) {
    fabo_deny('Your account cannot manage sheets.', 403);
}

$GLOBALS['fabo_manage_viewer'] = $viewer;
require __DIR__ . '/admin/sheets.php';

<?php
/**
 * CRM, for a client granted it.
 *
 * The desk itself is admin/crm.php. This is not a copy of it: it works out
 * who is asking, leaves them where the desk looks for them, and hands over.
 * The desk then shows only the CRMs they hold: their organisation's (for its
 * head, through the CRM module), and any shared with them one at a time —
 * see fabo_crm_access() in lib/crm.php.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/crm.php';
fabo_boot();

header('X-Robots-Tag: noindex, nofollow');

$viewer = fabo_portal_viewer((string) ($_GET['k'] ?? ''));
if (!$viewer) {
    header('Location: login.php?next=' . rawurlencode('crm.php'));
    exit;
}
if (!fabo_crm_has_any($viewer)) {
    fabo_deny('You do not have access to any CRM yet. Ask the person who runs one to share it with you.', 403);
}

$GLOBALS['fabo_crm_viewer'] = $viewer;

/* Drawn into a buffer, so a failure shows one plain sentence rather than a
   half-drawn page or a blank 500 -- the same guard intake.php has. */
ob_start();
try {
    require __DIR__ . '/admin/crm.php';
    ob_end_flush();
} catch (Throwable $e) {
    ob_end_clean();
    error_log('FABO crm.php: ' . get_class($e) . ': ' . $e->getMessage()
            . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    fabo_simple_page('The CRM could not open',
        'Something on this page failed. Please let the admin know; the detail is in the server’s error log.');
}

<?php
/**
 * Intake Forms, for a client running their own organisation.
 *
 * The desk itself is admin/intake.php. This is not a copy of it: it works out
 * who is asking, leaves them where the desk looks for them, and hands over.
 * The desk then works at their organisation's scope -- their forms only, for
 * sheets they can edit, with every row filed under them. The right is granted
 * by the admin on the client's page, in "Sheet and module access".
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/manage.php';
fabo_boot();

header('X-Robots-Tag: noindex, nofollow');

$viewer = fabo_portal_viewer((string) ($_GET['k'] ?? ''));
if (!$viewer) {
    header('Location: login.php?next=' . rawurlencode('intake.php'));
    exit;
}
if (!fabo_may_manage($viewer, 'intake')) {
    fabo_deny('Your account does not have Intake Forms. Ask the admin to grant it under Sheet and module access.', 403);
}

$GLOBALS['fabo_manage_viewer'] = $viewer;

/* A failure here used to be a blank "HTTP ERROR 500" with nothing to go on.
   The desk is drawn into a buffer, so if anything on it fails the half-drawn
   page is thrown away and one plain sentence is shown instead, with the full
   detail in the server's error log. The commonest cause is a portal updated
   one file at a time, where this page has arrived before a library it uses. */
ob_start();
try {
    require __DIR__ . '/admin/intake.php';
    ob_end_flush();
} catch (Throwable $e) {
    ob_end_clean();
    error_log('FABO intake.php: ' . get_class($e) . ': ' . $e->getMessage()
            . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    $where = str_replace('\\', '/', substr($e->getFile(), strlen(__DIR__) + 1));
    // The detail (message, file, line) is in the log above; the page says nothing about the server.
    fabo_simple_page('Intake Forms could not open',
        'Something on this page failed. This usually means one of the portal files was not '
      . 'uploaded with the latest update. Please let the admin know; the detail is in the server error log.');
}

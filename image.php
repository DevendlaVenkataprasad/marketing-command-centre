<?php
/**
 * The viewer's own photograph, for their own portal.
 *
 * admin/image.php serves anybody's picture, and only to the administrator.
 * A client or team member asking from their portal gets their own, and
 * nobody else's: there is deliberately NO id in the URL, the same rule
 * logo.php keeps, so nobody can walk the numbers collecting other people's
 * photographs.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/uploads.php';
fabo_boot();

$viewer = null;
try {
    $viewer = fabo_portal_viewer((string) ($_GET['k'] ?? ''));
} catch (Throwable $e) {
    $viewer = null;
}
if (!$viewer || !empty($viewer['is_admin']) || empty($viewer['client']['id'])) {
    http_response_code(404);
    exit;
}

$row = fabo_one('SELECT image_file, image_mime FROM clients WHERE id = ?', 'i',
                [(int) $viewer['client']['id']]);
if (!$row || empty($row['image_file'])) {
    http_response_code(404);
    exit;
}
$path = fabo_upload_dir() . DIRECTORY_SEPARATOR . (string) $row['image_file'];
if (!is_file($path)) {
    http_response_code(404);
    exit;
}

fabo_image_headers((string) $row['image_mime']);
header('Content-Length: ' . filesize($path));
/* Private: one person's photograph, never to be handed on by a shared cache. */
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
readfile($path);

<?php
/** Serves a client's / member's image. Admin area only. */

require_once __DIR__ . '/../lib/endpoint.php';
require_once __DIR__ . '/../lib/uploads.php';
fabo_boot();
fabo_require_admin();

$p = fabo_one('SELECT image_file, image_mime FROM clients WHERE id = ?', 'i', [(int) ($_GET['p'] ?? 0)]);
if (!$p || empty($p['image_file'])) {
    fabo_deny('No image.', 404);
}
$path = fabo_upload_dir() . DIRECTORY_SEPARATOR . (string) $p['image_file'];
if (!is_file($path)) {
    fabo_deny('No image.', 404);
}
fabo_image_headers((string) $p['image_mime']);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
readfile($path);

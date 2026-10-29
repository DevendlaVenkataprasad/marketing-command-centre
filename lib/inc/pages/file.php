<?php
/**
 * Incubation Management — sends one stored document. Files live in uploads/, which
 * the web server never serves directly; this page checks the right to view
 * before a byte is sent, and sends everything except images as a download.
 */

require_once __DIR__ . '/../../uploads.php';

$f = inc_file_for_download((int) ($_GET['id'] ?? 0));
if (!$f || !preg_match('/^[0-9a-f]{32}\.[A-Za-z0-9]{1,8}$/', (string) $f['stored_name'])) {
    fabo_deny('That file is not available.', 404);
}
$path = fabo_upload_dir() . DIRECTORY_SEPARATOR . $f['stored_name'];
if (!is_file($path)) {
    fabo_deny('That file is no longer on the server.', 404);
}
// A PDF opens in the browser's viewer too. Chrome will not draw a PDF under a sandbox policy, so it only keeps the page from being framed elsewhere.
$pdf = (string) $f['mime'] === 'application/pdf';
$inline = $pdf || fabo_is_inline_image((string) $f['mime']);
header('Content-Type: ' . ($inline ? $f['mime'] : 'application/octet-stream'));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . preg_replace('/[^A-Za-z0-9._ -]/', '_', (string) $f['original_name']) . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header($pdf ? "Content-Security-Policy: frame-ancestors 'self'" : "Content-Security-Policy: default-src 'none'; sandbox");
readfile($path);
exit;

<?php
/** Downloads one attachment — only for someone who may read the document it belongs to. */

require_once __DIR__ . '/../../uploads.php';

$f = fp_one('SELECT * FROM fp_files WHERE id = ?', 'i', [(int) ($_GET['id'] ?? 0)]);
if (!$f) {
    fabo_deny('File not found.', 404);
}
$type = (string) $f['doc_type'];
$ok = false;
if (in_array($type, ['control', 'evidence'], true)) {
    $ok = fp_can('controls.manage') || fp_can('audit.view');
} elseif (isset(fp_doc_types()[$type])) {
    $doc = fp_doc($type, (int) $f['doc_id']);
    $ok = $doc && fp_doc_can_view($type, $doc);
}
if (!$ok) {
    error_log('FABO finance file denied ' . fp_actor() . ' -> file ' . $f['id']);
    fabo_deny('File not found.', 404);
}
$path = fabo_upload_dir() . DIRECTORY_SEPARATOR . basename((string) $f['stored_name']);
if (!is_file($path)) {
    fabo_deny('That file is no longer on the server.', 404);
}
$mime = (string) $f['mime'];
$inline = (fabo_is_image($mime) && $mime !== 'image/svg+xml') || $mime === 'application/pdf';
header('Content-Type: ' . ($inline ? $mime : 'application/octet-stream'));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace(['"', "\r", "\n"], '', (string) $f['original_name']) . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: default-src \'none\'; img-src \'self\'; style-src \'unsafe-inline\'; sandbox');
header('Cache-Control: private, no-store');
readfile($path);
exit;

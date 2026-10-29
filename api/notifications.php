<?php
/** A portal viewer's own notices. */

require_once __DIR__ . '/../lib/endpoint.php';
require_once __DIR__ . '/../lib/notify.php';

fabo_boot();
$token = (string) ($_REQUEST['k'] ?? '');
if ($token === '') {
    fabo_json_fail('Not available.', 403);
}
$viewer = fabo_client_viewer($token);
$id     = (int) $viewer['client']['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    fabo_csrf_check();
    fabo_mark_read($id);
}

$out = [];
foreach (fabo_notifications($id) as $n) {
    $out[] = [
        'title' => $n['title'],
        'body'  => $n['body'],
        'when'  => date('d M Y, H:i', strtotime($n['created_at'])),
        'unread'=> $n['read_at'] === null,
    ];
}
fabo_json(['ok' => true, 'unread' => fabo_unread_count($id), 'items' => $out]);

<?php
/**
 * Answers fabo_org_host_check() (lib/orghost.php): is this address really
 * this portal? Only the database behind it knows the one-off number the
 * check just stored, so the right answer proves the address reaches us.
 * Tells a stranger nothing.
 */

require_once __DIR__ . '/lib/ui.php';
fabo_boot();

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
$n = (string) ($_GET['n'] ?? '');
$ok = preg_match('/^[0-9a-f]{32}$/', $n) && fabo_one('SELECT id FROM clients WHERE web_host_nonce = ?', 's', [$n]);
echo $ok ? 'fabo-orghost-ok' : 'no';

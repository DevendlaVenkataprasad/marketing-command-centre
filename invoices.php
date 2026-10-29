<?php
/**
 * Was: a client's invoice list. Now one tab of Finance.
 *
 * Kept as a redirect because emails and portal notices sent before the rename
 * point here, and a client's private ?k= link is how several of them arrive.
 * Anything already in someone's inbox keeps working.
 */

require_once __DIR__ . '/lib/db.php';
fabo_boot();

$q = [];
if (isset($_GET['k']) && $_GET['k'] !== '') {
    $q['k'] = (string) $_GET['k'];
}
header('Location: finance.php' . ($q ? '?' . http_build_query($q) : ''), true, 301);
exit;

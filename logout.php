<?php
/** Ends a portal session. The private link still works afterwards. */
require_once __DIR__ . '/lib/ui.php';
fabo_boot();
header('X-Robots-Tag: noindex, nofollow');
fabo_logout();
@setcookie('fabo_portal', '', ['expires' => time() - 3600, 'path' => '/']);
header('Location: login.php?out=1');

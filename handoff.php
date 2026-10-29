<?php
/**
 * Arriving at an organisation's own address from the main one, still signed
 * in: the one-time link fabo_org_host_steer() made (lib/orghost.php). Signs
 * the person in here and opens the page they were going to. A used, expired
 * or wrong-address link just leads to the sign-in page.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/orghost.php';
fabo_boot();

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
$got = fabo_handoff_take((string) ($_GET['t'] ?? ''));
if (!$got) {
    header('Location: login.php');
    exit;
}
[$person, $next] = $got;
fabo_login($person);
header('Location: ' . (fabo_safe_next($next) ?: 'c.php'));
exit;

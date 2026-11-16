<?php
/**
 * Renders one Digital Signatures page from the command line as one person
 * (or the administrator) against the self-test database, and reports any
 * PHP problem in it. Local only.
 *
 *     php tests/ds_render.php <who: admin|person id> "<query string>" [out.html]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
chdir(dirname(__DIR__));
define('DB_HOST', getenv('DS_TEST_HOST') ?: '127.0.0.1');
define('DB_PORT', (int) (getenv('DS_TEST_PORT') ?: 3399));
define('DB_NAME', getenv('DS_TEST_DB') ?: 'dstest');
define('DB_USER', 'root');
define('DB_PASS', '');
$who = $argv[1] ?? 'admin';
$qs = $argv[2] ?? '';
$out = $argv[3] ?? null;
parse_str($qs, $_GET);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'portal.test';
$_SERVER['SCRIPT_NAME'] = $who === 'admin' ? '/admin/sign.php' : '/sign.php';
$_SERVER['QUERY_STRING'] = $qs;
$_SERVER['REMOTE_ADDR'] = '10.0.0.9';
putenv('DS_KEY_FILE=' . (getenv('DS_KEY_FILE') ?: ''));
$problems = [];
set_error_handler(static function ($no, $str, $file, $line) use (&$problems) {
    if (str_contains($str, 'already defined') || str_contains($str, 'headers already sent') || str_contains($str, 'session') || str_contains($str, 'Cannot modify header')) {
        return true;
    }
    $problems[] = "PHP $no: $str at " . basename($file) . ":$line";
    return true;
});
register_shutdown_function(static function () use (&$problems, $out) {
    $html = (string) ob_get_clean();
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $problems[] = 'FATAL: ' . $err['message'] . ' at ' . basename($err['file']) . ':' . $err['line'];
    }
    if ($out) {
        file_put_contents($out, $html);
    }
    if (str_contains($html, '<title>Not available</title>') && preg_match('/<div>([^<]{0,200})<\/div>/', $html, $m)) {
        echo 'DENIED ' . $m[1] . "\n";
        return;
    }
    echo $problems ? implode("\n", array_unique($problems)) . "\n" : 'OK ' . strlen($html) . "\n";
});
ob_start();
require __DIR__ . '/../lib/ui.php';
fabo_boot();
require __DIR__ . '/../lib/ds/app.php';
if ($who === 'admin') {
    $viewer = fabo_admin_viewer();
    fabo_session();
    $_SESSION['ds_admin_org'] = (int) (getenv('DS_ADMIN_ORG') ?: 0);
} else {
    $p = fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [(int) $who]);
    $viewer = fabo_person_viewer($p);
}
ds_main($viewer);

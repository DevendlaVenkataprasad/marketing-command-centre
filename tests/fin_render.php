<?php
/**
 * Renders one Finance Portal page from the command line, as the
 * administrator or as one person, and reports PHP errors in it:
 *
 *     php tests/fin_render.php <who: admin|person id> <query string> [POST json]
 *
 * Prints "OK <bytes>" or the problems found. For a local copy (after
 * tests/fin_selftest.php has made the data); never run against live.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
chdir(dirname(__DIR__));
$who = $argv[1] ?? 'admin';
parse_str($argv[2] ?? '', $_GET);
$_SERVER['REQUEST_METHOD'] = isset($argv[3]) ? 'POST' : 'GET';
$postJson = isset($argv[3]) ? (json_decode($argv[3], true) ?: []) : null;
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SCRIPT_NAME'] = $who === 'admin' ? '/admin/fin.php' : '/fin.php';
$_SERVER['QUERY_STRING'] = $argv[2] ?? '';
$problems = [];
set_error_handler(static function ($no, $str, $file, $line) use (&$problems) {
    if (str_contains($str, 'headers already sent') || str_contains($str, 'session')) {
        return true;
    }
    $problems[] = "PHP $no: $str at " . basename($file) . ":$line";
    return true;
});
register_shutdown_function(static function () use (&$problems) {
    $out = ob_get_clean();
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $problems[] = 'FATAL: ' . $err['message'] . ' at ' . basename($err['file']) . ':' . $err['line'];
    }
    if (preg_match('/<div>([^<]{0,200})<\/div>$/', trim((string) $out), $m) && str_contains((string) $out, '<title>Not available</title>')) {
        echo 'DENIED ' . $m[1] . "\n";
        return;
    }
    // A POST that succeeded redirects with its message in the session; one that was refused shows it on the page.
    if (!empty($_SESSION['inc_flash'])) {
        echo 'FLASH ' . implode(': ', $_SESSION['inc_flash']) . "\n";
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && preg_match('/<div class="flash err" role="alert">([^<]+)</', (string) $out, $m)) {
        echo 'REFUSED ' . html_entity_decode($m[1]) . "\n";
    }
    echo $problems ? implode("\n", $problems) . "\n" : 'OK ' . strlen((string) $out) . "\n";
});
ob_start();
require __DIR__ . '/../lib/ui.php';
fabo_boot();
require __DIR__ . '/../lib/fin/app.php';
if ($who === 'admin') {
    $viewer = fabo_admin_viewer();
} else {
    $p = fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [(int) $who]);
    $viewer = fabo_person_viewer($p);
}
if ($postJson !== null) {
    fabo_session();
    $_POST = $postJson;
    $_POST['csrf'] = fabo_csrf_token();
    if (($_POST['_nonce'] ?? '') === 'auto') {
        preg_match('/value="([0-9a-f]+)"/', fp_nonce_field(), $nm);
        $_POST['_nonce'] = $nm[1];
    }
}
fp_main($viewer);

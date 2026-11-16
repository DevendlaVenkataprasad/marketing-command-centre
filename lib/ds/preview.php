<?php
/**
 * Digital Signatures — page pictures for the viewer.
 *
 * A browser drawing a photo-heavy PDF itself is slow: it must fetch a PDF
 * engine and decode every image on the page. Instead the server renders
 * each page once (Ghostscript, a few hundred milliseconds for several
 * pages) to a JPEG of about 100 KB, keeps it encrypted beside the other
 * files, and the viewer shows pictures. The PDF itself is still what is
 * signed, verified and downloaded; the pictures are only for looking.
 *
 * A stored file never changes, so its pictures are keyed by its SHA-256
 * and never go stale. Without Ghostscript the viewer falls back to pdf.js.
 */

const DS_PREVIEW_DPI = 130;
const DS_PREVIEW_BATCH = 6;

/** The Ghostscript binary, or null when pages cannot be rendered here. */
function ds_gs(): ?string
{
    static $gs = false;
    if ($gs !== false) {
        return $gs;
    }
    $gs = null;
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    if (!function_exists('exec') || in_array('exec', $disabled, true)) {
        return null;
    }
    $env = (string) getenv('DS_GS');
    if ($env !== '' && is_file($env)) {
        return $gs = $env;                 // configured by whoever runs the server
    }
    foreach (['/usr/bin/gs', '/usr/local/bin/gs', '/opt/homebrew/bin/gs'] as $p) {
        if (@is_executable($p)) {
            return $gs = $p;
        }
    }
    return null;
}

function ds_preview_dir(string $sha): string
{
    require_once __DIR__ . '/../uploads.php';
    return rtrim(fabo_upload_dir(), '/\\') . '/ds/cache/' . substr($sha, 0, 2) . '/' . $sha;
}

/** Encrypts / decrypts a cached picture with the module's file key. */
function ds_preview_seal(string $bytes): string
{
    $nonce = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($bytes, 'aes-256-gcm', ds_subkey('files'), OPENSSL_RAW_DATA, $nonce, $tag, 'dsprev');
    return 'DSP1' . $nonce . $tag . $ct;
}

function ds_preview_open(string $raw): ?string
{
    if (strncmp($raw, 'DSP1', 4) !== 0) {
        return null;
    }
    $pt = openssl_decrypt(substr($raw, 32), 'aes-256-gcm', ds_subkey('files'), OPENSSL_RAW_DATA, substr($raw, 4, 12), substr($raw, 16, 16), 'dsprev');
    return $pt === false ? null : $pt;
}

/**
 * What the viewer needs to lay pages out before any picture arrives: the
 * displayed size of each page (rotation applied). Null when pictures are
 * not available here, so the viewer uses pdf.js.
 */
function ds_preview_info(int $fileId): ?array
{
    if (!ds_gs()) {
        return null;
    }
    $f = ds_file($fileId);
    if (!$f) {
        return null;
    }
    $dir = ds_preview_dir($f['sha256']);
    $cache = $dir . '/sizes.json';
    $sizes = is_file($cache) ? json_decode((string) file_get_contents($cache), true) : null;
    if (!is_array($sizes) || !$sizes) {
        try {
            $pdf = new DsPdf(ds_read($fileId));
            $sizes = [];
            foreach ($pdf->pages() as $pg) {
                $w = $pg['box'][2] - $pg['box'][0];
                $h = $pg['box'][3] - $pg['box'][1];
                $sizes[] = in_array($pg['rotate'], [90, 270], true) ? [round($h, 1), round($w, 1)] : [round($w, 1), round($h, 1)];
            }
        } catch (Throwable $e) {
            return null;
        }
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        @file_put_contents($cache, json_encode($sizes), LOCK_EX);
    }
    return ['sizes' => $sizes];
}

/** The data-preview attribute for a viewer of this document's file, or ''. */
function ds_preview_attr(int $docId, int $fileId): string
{
    $info = ds_preview_info($fileId);
    if (!$info) {
        return '';
    }
    return ' data-preview="' . e(json_encode(['img' => ds_url('file', ['doc' => $docId, 'kind' => 'page', 'f' => $fileId]) . '&n=', 'sizes' => $info['sizes']])) . '"';
}

/**
 * One page as a JPEG (1-based). Rendered with the next few pages in one
 * Ghostscript run, under a lock so two viewers never render the same file
 * at once, and kept encrypted.
 */
function ds_preview_page(int $fileId, int $n): string
{
    $f = ds_file($fileId);
    $gs = ds_gs();
    if (!$f || !$gs) {
        throw new RuntimeException('Page pictures are not available.');
    }
    $info = ds_preview_info($fileId);
    $total = count($info['sizes'] ?? []);
    if ($n < 1 || $n > $total) {
        throw new InvalidArgumentException('No such page.');
    }
    $dir = ds_preview_dir($f['sha256']);
    $path = $dir . '/p' . $n . '.bin';
    if (is_file($path) && ($img = ds_preview_open((string) file_get_contents($path))) !== null) {
        return $img;
    }
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    $lock = fopen($dir . '/.lock', 'c');
    flock($lock, LOCK_EX);
    try {
        if (is_file($path) && ($img = ds_preview_open((string) file_get_contents($path))) !== null) {
            return $img;
        }
        ds_preview_render($fileId, $f['sha256'], $n, min($total, $n + DS_PREVIEW_BATCH - 1), $info['sizes']);
        $img = is_file($path) ? ds_preview_open((string) file_get_contents($path)) : null;
        if ($img === null) {
            throw new RuntimeException('The page could not be rendered.');
        }
        return $img;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** Renders pages $from..$to into the cache (pages already there are left alone). */
function ds_preview_render(int $fileId, string $sha, int $from, int $to, array $sizes): void
{
    $dir = ds_preview_dir($sha);
    $work = sys_get_temp_dir() . '/dsprev_' . bin2hex(random_bytes(8));
    @mkdir($work, 0700);
    $pdfPath = $work . '/in.pdf';
    try {
        file_put_contents($pdfPath, ds_read($fileId));
        @chmod($pdfPath, 0600);
        // Large sheets (A3, drawings) render at a lower resolution so a picture stays small.
        $widest = 1.0;
        for ($i = $from; $i <= $to; $i++) {
            $widest = max($widest, (float) ($sizes[$i - 1][0] ?? 595), (float) ($sizes[$i - 1][1] ?? 842) * 0.75);
        }
        $dpi = (int) max(50, min(DS_PREVIEW_DPI, floor(1300 * 72 / $widest)));
        $timeout = is_executable('/usr/bin/timeout') ? '/usr/bin/timeout 60 ' : '';
        $cmd = $timeout . escapeshellarg(ds_gs()) . ' -q -dSAFER -dBATCH -dNOPAUSE -dNOINTERPOLATE -sDEVICE=jpeg -dJPEGQ=78 -r' . $dpi
             . ' -dTextAlphaBits=4 -dGraphicsAlphaBits=4 -dUseCropBox -dFirstPage=' . $from . ' -dLastPage=' . $to
             . ' -sOutputFile=' . escapeshellarg($work . '/o%d.jpg') . ' ' . escapeshellarg($pdfPath) . ' 2>&1';
        $out = [];
        $rc = 0;
        exec($cmd, $out, $rc);
        if ($rc !== 0) {
            error_log('FABO esign preview gs rc ' . $rc . ': ' . mb_substr(implode(' ', $out), 0, 300));
        }
        for ($i = $from; $i <= $to; $i++) {
            $jpg = $work . '/o' . ($i - $from + 1) . '.jpg';
            $dest = $dir . '/p' . $i . '.bin';
            if (is_file($jpg) && !is_file($dest)) {
                @file_put_contents($dest . '.tmp', ds_preview_seal((string) file_get_contents($jpg)), LOCK_EX);
                @rename($dest . '.tmp', $dest);
            }
        }
    } finally {
        foreach ((array) glob($work . '/*') as $x) {
            @unlink($x);
        }
        @rmdir($work);
    }
}

/**
 * After a page that shows a document has gone out, renders its first pages
 * if they are not ready, so the pictures the browser asks for next are
 * already waiting.
 */
function ds_preview_warm(int $fileId): void
{
    if (!ds_gs()) {
        return;
    }
    register_shutdown_function(static function () use ($fileId) {
        try {
            if (function_exists('litespeed_finish_request')) {
                @litespeed_finish_request();
            } elseif (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            $f = ds_file($fileId);
            if ($f && !is_file(ds_preview_dir($f['sha256']) . '/p1.bin')) {
                ds_preview_page($fileId, 1);
            }
        } catch (Throwable $e) {
            error_log('FABO esign preview warm: ' . $e->getMessage());
        }
    });
}

/** Removes a file's cached pictures (retention purge). */
function ds_preview_forget(string $sha): void
{
    $dir = ds_preview_dir($sha);
    foreach ((array) glob($dir . '/*') as $x) {
        @unlink($x);
    }
    @unlink($dir . '/.lock');
    @rmdir($dir);
}

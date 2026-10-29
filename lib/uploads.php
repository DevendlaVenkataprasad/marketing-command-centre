<?php
/**
 * File uploads: content assets, design files and client images.
 *
 * Files are stored outside any executable path under uploads/, renamed to a
 * random token, and served back through download.php so the original name and
 * type are restored without ever letting the web server run the file.
 */

require_once __DIR__ . '/db.php';

function fabo_upload_dir(): string
{
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    // Belt and braces: refuse execution even if the folder ends up web-reachable.
    $ht = $dir . DIRECTORY_SEPARATOR . '.htaccess';
    if (!file_exists($ht)) {
        @file_put_contents($ht, "Require all denied\n<IfModule mod_php.c>\nphp_flag engine off\n</IfModule>\n");
    }
    $idx = $dir . DIRECTORY_SEPARATOR . 'index.html';
    if (!file_exists($idx)) {
        @file_put_contents($idx, '');
    }
    return $dir;
}

/** Extension => media type. Anything not listed here is refused. */
function fabo_upload_types(): array
{
    return [
        /* Every image format a phone or a camera is likely to produce. The
           bytes are checked below whatever the extension claims, so a wide
           list here costs nothing — what is actually enforced is that an
           image column receives an image. */
        'jpg'  => 'image/jpeg', 'jpeg' => 'image/jpeg', 'jpe'  => 'image/jpeg',
        'jfif' => 'image/jpeg', 'png'  => 'image/png',  'apng' => 'image/apng',
        'gif'  => 'image/gif',  'webp' => 'image/webp', 'avif' => 'image/avif',
        'bmp'  => 'image/bmp',  'dib'  => 'image/bmp',
        'tif'  => 'image/tiff', 'tiff' => 'image/tiff',
        'heic' => 'image/heic', 'heif' => 'image/heif',
        'ico'  => 'image/x-icon', 'svg' => 'image/svg+xml',
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx'=> 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx'=> 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx'=> 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'txt' => 'text/plain', 'csv' => 'text/csv',
        'mp4' => 'video/mp4',  'mov' => 'video/quicktime',
        'zip' => 'application/zip',
    ];
}

function fabo_upload_max_bytes(): int
{
    return 25 * 1024 * 1024;   // 25 MB
}

/** A php.ini size such as "8M" or "1G", in bytes; 0 for none. */
function fabo_ini_bytes(string $v): int
{
    $v = trim($v);
    if ($v === '') {
        return 0;
    }
    $n = (float) $v;
    switch (strtolower(substr($v, -1))) {
        case 'g': $n *= 1024;   // fall through
        case 'm': $n *= 1024;   // fall through
        case 'k': $n *= 1024;
    }
    return (int) $n;
}

/**
 * The biggest file this server will actually take: the portal's own 25 MB,
 * or less when the host's PHP settings are lower. Hosting plans often are,
 * and a file over that limit never reaches the portal at all.
 */
function fabo_upload_limit_bytes(): int
{
    $limit = fabo_upload_max_bytes();
    foreach (['upload_max_filesize', 'post_max_size'] as $k) {
        $b = fabo_ini_bytes((string) ini_get($k));
        if ($b > 0) {
            $limit = min($limit, $b);
        }
    }
    return $limit;
}

/** That limit in words: "8 MB". */
function fabo_upload_limit_words(): string
{
    $mb = fabo_upload_limit_bytes() / 1048576;
    return ($mb >= 1 ? rtrim(rtrim(number_format($mb, 1), '0'), '.') . ' MB'
                     : (int) round($mb * 1024) . ' KB');
}

/**
 * Was this request an upload that PHP threw away for being too big?
 *
 * Past post_max_size PHP discards the whole body -- the file, and every
 * field sent with it, the link's token and the session's CSRF code
 * included. Unchecked, that reads as "this link is no longer available" or
 * "your session expired", which sends people looking for the wrong problem.
 */
function fabo_upload_body_lost(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
        && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0
        && empty($_POST) && empty($_FILES);
}

/* ------------------------------------------------ files sent in pieces

   The live sheets take a file of any size. A file bigger than one request
   may carry is sliced by the page and sent a piece at a time, one after
   another; the pieces are added to a file under uploads/parts/ and, with
   the last one, the whole file stands in $_FILES as if it had come in one
   go -- so the code that stores it is the same either way. */

/** How big each piece is: well inside what the host takes per request. */
function fabo_chunk_bytes(): int
{
    $cap = 32 * 1048576;
    foreach (['upload_max_filesize', 'post_max_size'] as $k) {
        $b = fabo_ini_bytes((string) ini_get($k));
        if ($b > 0) {
            $cap = min($cap, (int) ($b / 2));
        }
    }
    return max(262144, $cap);
}

/** Paths put together from pieces this request; ask with $add false. */
function fabo_upload_assembled(string $path, bool $add = false): bool
{
    static $paths = [];
    if ($add) {
        $paths[$path] = true;
    }
    return isset($paths[$path]);
}

/**
 * Takes one piece of a file sent in pieces under $field.
 *
 * Not a piece (no chunk_id): returns at once and the request goes on as
 * any other upload. A piece before the last: kept, answered here, and the
 * request ends. The last piece: $_FILES[$field] becomes the whole file.
 * $scope is the link's token, so one link cannot add to another's file.
 * $fail answers with a refusal and ends the request.
 */
function fabo_upload_take_chunk(string $field, string $scope, callable $fail): void
{
    $id = (string) ($_POST['chunk_id'] ?? '');
    if ($id === '') {
        return;
    }
    $idx    = (int) ($_POST['chunk_index'] ?? -1);
    $count  = (int) ($_POST['chunk_count'] ?? 0);
    $offset = (int) ($_POST['chunk_offset'] ?? -1);
    $total  = (int) ($_POST['chunk_total'] ?? -1);
    $name   = (string) ($_POST['chunk_name'] ?? '');
    if (!preg_match('/^[A-Za-z0-9]{16,64}$/', $id) || $count < 1 || $idx < 0 || $idx >= $count
        || $offset < 0 || $total < 1 || $offset >= $total || $name === '') {
        $fail('That upload could not be followed. Try again.');
    }
    if (!isset(fabo_upload_types()[strtolower(pathinfo($name, PATHINFO_EXTENSION))])) {
        $fail($name . ': that file type is not accepted.');
    }
    $f = $_FILES[$field] ?? null;
    if ($f && is_array($f['name'] ?? null)) {
        $f = ['name' => $f['name'][0], 'type' => $f['type'][0], 'tmp_name' => $f['tmp_name'][0],
              'error' => $f['error'][0], 'size' => $f['size'][0]];
    }
    if (!$f || (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        || !is_uploaded_file((string) $f['tmp_name'])) {
        $fail('Part of ' . $name . ' did not arrive. Try again.');
    }

    $dir = fabo_upload_dir() . DIRECTORY_SEPARATOR . 'parts';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $part = $dir . DIRECTORY_SEPARATOR . hash('sha256', $scope . '|' . $id) . '.part';
    if ($idx === 0) {
        @unlink($part);
        // Pieces of files nobody finished sending, left a day or more.
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.part') ?: [] as $old) {
            if (@filemtime($old) < time() - 86400) {
                @unlink($old);
            }
        }
    }
    clearstatcache(true, $part);
    $have  = is_file($part) ? (int) filesize($part) : 0;
    $piece = (int) $f['size'];
    if ($have !== $offset + $piece) {           // the same piece sent again: kept already
        if ($have !== $offset || $offset + $piece > $total) {
            @unlink($part);
            $fail('The upload of ' . $name . ' lost its place. Try again.');
        }
        $in  = @fopen((string) $f['tmp_name'], 'rb');
        $out = @fopen($part, 'ab');
        $ok  = $in && $out && stream_copy_to_stream($in, $out) === $piece;
        if ($in)  { fclose($in); }
        if ($out) { fclose($out); }
        if (!$ok) {
            @unlink($part);
            $fail('The server could not keep ' . $name . '. Try again.');
        }
    }
    if ($idx < $count - 1) {
        echo json_encode(['ok' => true, 'partial' => true]);
        exit;
    }
    clearstatcache(true, $part);
    if ((int) filesize($part) !== $total) {
        @unlink($part);
        $fail('The upload of ' . $name . ' did not finish. Try again.');
    }
    $_FILES[$field] = ['name' => $name, 'type' => '', 'tmp_name' => $part,
                       'error' => UPLOAD_ERR_OK, 'size' => $total];
    fabo_upload_assembled($part, true);
    // Refused, or failed on the way: the pieces do not stay behind.
    register_shutdown_function(function () use ($part) {
        if (is_file($part)) {
            @unlink($part);
        }
    });
}

/** The sentence for that, naming the limit. */
function fabo_upload_too_big_words(): string
{
    return 'That file is bigger than this server accepts — the limit is '
         . fabo_upload_limit_words() . ' per upload. Nothing was uploaded. '
         . 'Send a smaller file, or one file at a time.';
}

function fabo_is_image(string $mime): bool
{
    return str_starts_with($mime, 'image/');
}

/**
 * May this be shown inline as a picture on the portal's own address?
 * Bitmaps only. An SVG is a document that can carry script, and served
 * inline from here it would run with the portal's own cookies.
 */
function fabo_is_inline_image(string $mime): bool
{
    return in_array(strtolower($mime), ['image/jpeg', 'image/png', 'image/gif', 'image/webp',
                                        'image/avif', 'image/bmp'], true);
}

/**
 * A small JPEG of a stored picture, for the grids that show dozens at once.
 *
 * A live sheet with five hundred phone photographs drew every one of them at
 * full size (half a gigabyte) each time it was opened; the host's CDN took
 * that burst for an attack and refused the whole network with a 403. The
 * grids now ask for this copy, made once and kept beside the original under
 * uploads/thumbs/, and the full picture is one click away.
 *
 * Returns the thumbnail's path, or null when it cannot be made (an SVG, an
 * unreadable file): the caller then serves the original.
 */
function fabo_upload_thumb(string $stored, string $mime, int $edge = 320): ?string
{
    if (!preg_match('/^[0-9a-f]{32}\.[A-Za-z0-9]{1,8}$/', $stored) || !fabo_is_inline_image($mime)) {
        return null;
    }
    $src = fabo_upload_dir() . DIRECTORY_SEPARATOR . $stored;
    $dir = fabo_upload_dir() . DIRECTORY_SEPARATOR . 'thumbs';
    $out = $dir . DIRECTORY_SEPARATOR . pathinfo($stored, PATHINFO_FILENAME) . '-' . $edge . '.jpg';
    if (is_file($out)) {
        return $out;
    }
    if (!is_file($src)) {
        return null;
    }
    // Already small: the original is the thumbnail.
    if (filesize($src) <= 150 * 1024) {
        return $src;
    }
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @ini_set('memory_limit', '512M');
    $tmp = $out . '.' . bin2hex(random_bytes(4)) . '.tmp';
    try {
        if (class_exists('Imagick')) {
            $im = new Imagick();
            $im->setOption('jpeg:size', ($edge * 2) . 'x' . ($edge * 2));   // decode at a fraction of the size
            $im->readImage($src . '[0]');
            if (method_exists($im, 'autoOrient')) {
                $im->autoOrient();
            }
            $im->thumbnailImage($edge, $edge, true);
            $im->setImageBackgroundColor('white');
            $im = $im->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
            $im->setImageFormat('jpeg');
            $im->setImageCompressionQuality(78);
            $im->stripImage();
            $im->writeImage($tmp);
            $im->clear();
        } elseif (function_exists('imagecreatefromstring')) {
            $img = @imagecreatefromstring((string) file_get_contents($src));
            if (!$img) {
                return null;
            }
            $w = imagesx($img);
            $h = imagesy($img);
            $k = min(1, $edge / max($w, $h));
            $t = imagecreatetruecolor(max(1, (int) round($w * $k)), max(1, (int) round($h * $k)));
            imagefill($t, 0, 0, imagecolorallocate($t, 255, 255, 255));
            imagecopyresampled($t, $img, 0, 0, 0, 0, imagesx($t), imagesy($t), $w, $h);
            imagejpeg($t, $tmp, 78);
            imagedestroy($img);
            imagedestroy($t);
        } else {
            return null;
        }
    } catch (Throwable $e) {
        error_log('FABO thumb ' . $stored . ': ' . $e->getMessage());
        @unlink($tmp);
        return null;
    }
    if (!is_file($tmp) || !@rename($tmp, $out)) {
        @unlink($tmp);
        return null;
    }
    @chmod($out, 0644);
    return $out;
}

/**
 * Makes a stored photograph lighter, in place: at most $edge pixels on its
 * long side, re-saved at a quality no one can tell from the original on a
 * screen or a printed report, and without the hidden camera data -- which on
 * a phone photo includes where it was taken. A 6 MB phone picture comes out
 * near 400 KB.
 *
 * Only JPEG, PNG and WebP, each kept in its own format under the same name,
 * so nothing that points at the file has to change; a PNG keeps its
 * transparency (logos, signatures). GIFs (they may move), documents and
 * anything unreadable are left exactly as they came. The file is replaced
 * only when the result is clearly smaller; any failure leaves the original.
 *
 * Returns the new size in bytes, or null when the file was left alone.
 */
function fabo_upload_compress(string $path, string $mime, int $edge = 2560): ?int
{
    $mime = strtolower($mime);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || !is_file($path)) {
        return null;
    }
    $before = (int) filesize($path);
    if ($before < 200 * 1024) {
        return null;                       // already light
    }
    $info = @getimagesize($path);
    if (!$info || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 120000000) {
        return null;                       // not readable as a picture, or absurdly large
    }
    @ini_set('memory_limit', '768M');
    $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
    try {
        if (class_exists('Imagick')) {
            $im = new Imagick();
            if ($mime === 'image/jpeg') {
                $im->setOption('jpeg:size', $edge . 'x' . $edge);
            }
            $im->readImage($path);
            if ($im->getNumberImages() > 1) {  // an animated WebP: leave it
                $im->clear();
                return null;
            }
            if (method_exists($im, 'autoOrient')) {
                $im->autoOrient();
            }
            $icc = null;
            try {
                $icc = $im->getImageProfile('icc');   // keep the colours as the camera meant them
            } catch (Throwable $e) {
            }
            $im->stripImage();
            if ($icc) {
                $im->profileImage('icc', $icc);
            }
            if (max($im->getImageWidth(), $im->getImageHeight()) > $edge) {
                $im->resizeImage($edge, $edge, Imagick::FILTER_LANCZOS, 1, true);
            }
            if ($mime === 'image/png') {
                $im->setImageFormat('png');
                $im->setOption('png:compression-level', '9');
            } else {
                $im->setImageFormat($mime === 'image/webp' ? 'webp' : 'jpeg');
                $im->setImageCompressionQuality(82);
                if ($mime === 'image/jpeg') {
                    $im->setSamplingFactors(['2x2', '1x1', '1x1']);
                    $im->setInterlaceScheme(Imagick::INTERLACE_PLANE);
                }
            }
            $im->writeImage($tmp);
            $im->clear();
        } elseif (function_exists('imagecreatefromstring') && $mime !== 'image/webp') {
            $img = @imagecreatefromstring((string) file_get_contents($path));
            if (!$img) {
                return null;
            }
            if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
                $o = (int) (@exif_read_data($path)['Orientation'] ?? 1);
                $turn = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
                if ($turn) {
                    $img = imagerotate($img, $turn, 0);
                }
            }
            $w = imagesx($img);
            $h = imagesy($img);
            $k = min(1, $edge / max($w, $h));
            $t = imagecreatetruecolor(max(1, (int) round($w * $k)), max(1, (int) round($h * $k)));
            if ($mime === 'image/png') {
                imagealphablending($t, false);
                imagesavealpha($t, true);
            }
            imagecopyresampled($t, $img, 0, 0, 0, 0, imagesx($t), imagesy($t), $w, $h);
            $mime === 'image/png' ? imagepng($t, $tmp, 9) : imagejpeg($t, $tmp, 82);
            imagedestroy($img);
            imagedestroy($t);
        } else {
            return null;
        }
    } catch (Throwable $e) {
        error_log('FABO compress ' . basename($path) . ': ' . $e->getMessage());
        @unlink($tmp);
        return null;
    }
    clearstatcache(true, $tmp);
    $after = is_file($tmp) ? (int) filesize($tmp) : 0;
    if ($after < 1 || $after > $before * 0.9 || !@getimagesize($tmp) || !@rename($tmp, $path)) {
        @unlink($tmp);
        return null;
    }
    @chmod($path, 0644);
    return $after;
}

/**
 * The headers for a person's photo or an organisation's logo.
 *
 * Bitmaps go out as themselves. An SVG goes out as an image too -- logos are
 * often SVG, and served as anything else they show as a broken picture -- but
 * with a policy that sandboxes it and forbids every script, style source and
 * fetch, so opening its address directly cannot run code under the portal's
 * name. (Inside an <img> tag an SVG never runs script in the first place.)
 * Anything else is a download, never displayed.
 */
function fabo_image_headers(string $mime): void
{
    $mime = strtolower(trim($mime));
    if (fabo_is_inline_image($mime)) {
        header('Content-Type: ' . $mime);
    } elseif ($mime === 'image/svg+xml') {
        header('Content-Type: image/svg+xml');
        /* "attachment" is ignored inside an <img> tag, so the logo still shows;
           opened directly, the browser saves it instead of rendering it, so it
           can never run as a page. This holds even where the server replaces
           the policy header below with its own site-wide one, as Hostinger's does. */
        header('Content-Disposition: attachment; filename="image.svg"');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox");
    } else {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment');
    }
}

/**
 * Validates and stores one uploaded file.
 * Returns [ok, message, meta] where meta has stored_name, original_name,
 * mime, size.
 */
function fabo_store_upload(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $map = [
            UPLOAD_ERR_INI_SIZE   => 'That file is larger than the server allows (' . fabo_upload_limit_words() . ').',
            UPLOAD_ERR_FORM_SIZE  => 'That file is too large.',
            UPLOAD_ERR_PARTIAL    => 'The upload did not finish. Try again.',
            UPLOAD_ERR_NO_FILE    => 'No file was chosen.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server has no temporary folder configured.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not write the file.',
        ];
        return [false, $map[$file['error']] ?? 'The upload failed.', null];
    }
    // Put together from pieces (above): any size, the way the live sheets want it.
    $assembled = fabo_upload_assembled((string) $file['tmp_name']);
    if (!$assembled && !is_uploaded_file($file['tmp_name'])) {
        return [false, 'That was not a valid upload.', null];
    }
    if (!$assembled && (int) $file['size'] > fabo_upload_max_bytes()) {
        return [false, 'Files must be ' . fabo_upload_limit_words() . ' or smaller.', null];
    }

    $original = (string) ($file['name'] ?? 'file');
    $ext      = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $allowed  = fabo_upload_types();
    if (!isset($allowed[$ext])) {
        return [false, 'That file type is not accepted. Allowed: '
                     . implode(', ', array_unique(array_keys($allowed))) . '.', null];
    }

    // Trust the file's own bytes over its name where PHP can tell us.
    $mime = $allowed[$ext];
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $detected = finfo_file($fi, $file['tmp_name']);
        finfo_close($fi);
        if ($detected && $detected !== 'application/octet-stream') {
            // An image that is not really an image is refused outright.
            if (fabo_is_image($mime) && !fabo_is_image($detected)) {
                return [false, 'That file is not really an image.', null];
            }
            $mime = $detected;
        }
    }

    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    $dest   = fabo_upload_dir() . DIRECTORY_SEPARATOR . $stored;
    if (!($assembled ? @rename($file['tmp_name'], $dest) : move_uploaded_file($file['tmp_name'], $dest))) {
        error_log('FABO upload: could not move file to ' . $dest);
        return [false, 'The file could not be saved.', null];
    }
    @chmod($dest, 0644);

    // Photographs are kept lighter (see fabo_upload_compress); documents as sent.
    $size = fabo_upload_compress($dest, $mime) ?? (int) $file['size'];

    return [true, 'Uploaded.', [
        'stored_name'   => $stored,
        'original_name' => mb_substr($original, 0, 190),
        'mime'          => $mime,
        'size'          => $size,
    ]];
}

function fabo_delete_stored(string $stored): void
{
    if (!preg_match('/^[0-9a-f]{32}\.[A-Za-z0-9]{1,8}$/', $stored)) {
        return;
    }
    $p = fabo_upload_dir() . DIRECTORY_SEPARATOR . $stored;
    if (is_file($p)) {
        @unlink($p);
    }
    foreach (glob(fabo_upload_dir() . DIRECTORY_SEPARATOR . 'thumbs' . DIRECTORY_SEPARATOR
                  . pathinfo($stored, PATHINFO_FILENAME) . '-*.jpg') ?: [] as $t) {
        @unlink($t);
    }
}

/* ------------------------------------------------------- attachments */

/** Attachments on one row, newest first. */
function fabo_attachments(string $sheetKey, int $rowId): array
{
    return fabo_all('SELECT * FROM attachments WHERE sheet_key = ? AND row_id = ?
                     ORDER BY created_at DESC, id DESC', 'si', [$sheetKey, $rowId]);
}

function fabo_attachment_counts(string $sheetKey, array $rowIds): array
{
    if (!$rowIds) {
        return [];
    }
    $in  = implode(',', array_fill(0, count($rowIds), '?'));
    $out = [];
    $rows = fabo_all('SELECT row_id, COUNT(*) AS n FROM attachments
                      WHERE sheet_key = ? AND row_id IN (' . $in . ') GROUP BY row_id',
                     's' . str_repeat('i', count($rowIds)),
                     array_merge([$sheetKey], array_map('intval', $rowIds)));
    foreach ($rows as $r) {
        $out[(int) $r['row_id']] = (int) $r['n'];
    }
    return $out;
}

function fabo_human_size(int $bytes): string
{
    if ($bytes >= 1048576) { return round($bytes / 1048576, 1) . ' MB'; }
    if ($bytes >= 1024)    { return round($bytes / 1024) . ' KB'; }
    return $bytes . ' B';
}

/* ------------------------------------------- people images */

/** Saves an uploaded image against a person, or clears it. Returns a flash. */
function fabo_save_person_image(int $personId, ?array $file, bool $remove = false): ?array
{
    $cur = fabo_one('SELECT image_file FROM clients WHERE id = ?', 'i', [$personId]);
    if ($remove) {
        if (!empty($cur['image_file'])) {
            fabo_delete_stored((string) $cur['image_file']);
        }
        fabo_exec('UPDATE clients SET image_file=NULL, image_name=NULL, image_mime=NULL WHERE id=?', 'i', [$personId]);
        return ['ok', 'Image removed.'];
    }
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;                       // nothing chosen: leave what is there
    }
    [$ok, $msg, $meta] = fabo_store_upload($file);
    if (!$ok) {
        return ['err', $msg];
    }
    // A photo or logo: a bitmap, or an SVG (served sandboxed -- fabo_image_headers()).
    if (!fabo_is_inline_image($meta['mime']) && strtolower($meta['mime']) !== 'image/svg+xml') {
        fabo_delete_stored($meta['stored_name']);
        return ['err', 'Please choose an image file (JPG, PNG, GIF, WebP or SVG).'];
    }
    if (!empty($cur['image_file'])) {
        fabo_delete_stored((string) $cur['image_file']);
    }
    fabo_exec('UPDATE clients SET image_file=?, image_name=?, image_mime=? WHERE id=?', 'sssi',
              [$meta['stored_name'], $meta['original_name'], $meta['mime'], $personId]);
    return ['ok', 'Image updated.'];
}

/** <img> for a person, or their initials when there is no image. */
function fabo_avatar(array $p, string $cls = '', string $src = ''): string
{
    if (!empty($p['image_file'])) {
        // The admin pages name the person; a portal passes its own address
        // for the viewer's photograph (image.php at the root, no id).
        $src = $src !== '' ? $src : 'image.php?p=' . (int) $p['id'];
        return '<img class="avatar ' . e($cls) . '" alt="" src="' . e($src) . '">';
    }
    $initials = '';
    foreach (preg_split('/\s+/', trim((string) $p['name'])) as $w) {
        if ($w !== '') { $initials .= mb_strtoupper(mb_substr($w, 0, 1)); }
        if (mb_strlen($initials) >= 2) { break; }
    }
    return '<span class="avatar avatar-ph ' . e($cls) . '">' . e($initials ?: '?') . '</span>';
}

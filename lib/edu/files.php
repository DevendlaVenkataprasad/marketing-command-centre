<?php
/**
 * Academic — course files and the richer course builder (course builder v2).
 *
 *   - Documents: a lesson of kind 'document' (a PDF, slides, a Word file…)
 *     and attachments on any lesson. Stored by lib/uploads.php under
 *     uploads/ with a random name, never run, never reachable by URL: they
 *     come back only through edu_file_send(), after the caller has checked
 *     who is asking. A big file arrives in pieces (fabo_upload_take_chunk).
 *   - A course cover image, learning outcomes and prerequisites.
 *   - Sections: a lesson names the section it sits in; the outline groups
 *     consecutive lessons with the same section.
 */

require_once __DIR__ . '/lms.php';
require_once __DIR__ . '/../uploads.php';

const EDU_BUILDER_VERSION = 6;

function edu_builder_install(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    edu_lms_install();
    if ((int) fabo_setting('edu_builder_schema', '0') >= EDU_BUILDER_VERSION) {
        return;
    }
    $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    fabo_exec("CREATE TABLE IF NOT EXISTS edu_files (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      org_id INT UNSIGNED NOT NULL,
      course_id INT UNSIGNED NOT NULL,
      lesson_id INT UNSIGNED NOT NULL DEFAULT 0,
      role ENUM('lesson','cover') NOT NULL DEFAULT 'lesson',
      stored_name VARCHAR(80) NOT NULL,
      original_name VARCHAR(190) NOT NULL,
      mime VARCHAR(120) NOT NULL,
      size BIGINT UNSIGNED NOT NULL DEFAULT 0,
      sort SMALLINT NOT NULL DEFAULT 0,
      created_by VARCHAR(190) NOT NULL DEFAULT '',
      created_at DATETIME NOT NULL,
      KEY k_lesson (org_id, lesson_id, sort),
      KEY k_course (org_id, course_id, role)
    ) $t");
    $col = static fn(string $table, string $c) => (bool) fabo_one("SHOW COLUMNS FROM `$table` LIKE '$c'");
    if (!$col('edu_lessons', 'section')) {
        fabo_exec("ALTER TABLE edu_lessons ADD COLUMN section VARCHAR(120) NOT NULL DEFAULT '' AFTER sort");
    }
    fabo_exec("ALTER TABLE edu_lessons MODIFY kind ENUM('text','video','link','quiz','document') NOT NULL DEFAULT 'text'");
    if (!$col('edu_courses', 'outcomes')) {
        fabo_exec('ALTER TABLE edu_courses ADD COLUMN outcomes TEXT NULL AFTER summary, ADD COLUMN prerequisites TEXT NULL AFTER outcomes');
    }
    fabo_exec('INSERT INTO app_settings (k, v) VALUES ("edu_builder_schema", ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', 's', [(string) EDU_BUILDER_VERSION]);
}

/** What a lesson may carry: documents, slides, sheets, images, short video, archives. ext => label. */
function edu_file_kinds(): array
{
    return ['pdf' => 'PDF', 'ppt' => 'PowerPoint', 'pptx' => 'PowerPoint', 'doc' => 'Word', 'docx' => 'Word', 'xls' => 'Excel', 'xlsx' => 'Excel',
            'csv' => 'CSV', 'txt' => 'Text', 'zip' => 'ZIP', 'mp4' => 'Video', 'mov' => 'Video',
            'jpg' => 'Image', 'jpeg' => 'Image', 'png' => 'Image', 'gif' => 'Image', 'webp' => 'Image'];
}

function edu_file_ext(array $f): string
{
    return strtolower(pathinfo((string) $f['original_name'], PATHINFO_EXTENSION));
}

/** Opens in the browser (PDF, images, mp4) rather than downloading. */
function edu_file_inline(array $f): bool
{
    return in_array(edu_file_ext($f), ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4'], true);
}

/** A short tag for the file's type, for its icon. */
function edu_file_tag(array $f): string
{
    $e = edu_file_ext($f);
    return in_array($e, ['ppt', 'pptx'], true) ? 'ppt' : (in_array($e, ['doc', 'docx'], true) ? 'doc' : (in_array($e, ['xls', 'xlsx', 'csv'], true) ? 'xls'
         : ($e === 'pdf' ? 'pdf' : (in_array($e, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true) ? 'img' : (in_array($e, ['mp4', 'mov'], true) ? 'vid' : 'file')))));
}

function edu_file(int $org, int $id): ?array
{
    return fabo_one('SELECT * FROM edu_files WHERE org_id = ? AND id = ?', 'ii', [$org, $id]);
}

function edu_lesson_files(int $org, int $lessonId): array
{
    return fabo_all('SELECT * FROM edu_files WHERE org_id = ? AND lesson_id = ? AND role = "lesson" ORDER BY sort, id', 'ii', [$org, $lessonId]);
}

/** Files per lesson for a course: lesson id => count. */
function edu_file_counts(int $org, int $courseId): array
{
    $out = [];
    foreach (fabo_all('SELECT lesson_id, COUNT(*) AS n FROM edu_files WHERE org_id = ? AND course_id = ? AND role = "lesson" GROUP BY lesson_id', 'ii', [$org, $courseId]) as $r) {
        $out[(int) $r['lesson_id']] = (int) $r['n'];
    }
    return $out;
}

function edu_course_cover(int $org, int $courseId): ?array
{
    return fabo_one('SELECT * FROM edu_files WHERE org_id = ? AND course_id = ? AND role = "cover" ORDER BY id DESC LIMIT 1', 'ii', [$org, $courseId]);
}

/**
 * Stores an uploaded file ($_FILES entry) on a lesson, or as the course
 * cover ($lessonId 0, $role 'cover': an image only, replacing any earlier).
 */
function edu_file_add(int $org, int $courseId, int $lessonId, array $upload, string $actor, string $role = 'lesson'): array
{
    edu_builder_install();
    if (!edu_course($org, $courseId)) {
        throw new InvalidArgumentException('That course is not in this organisation.');
    }
    if ($role === 'lesson') {
        $l = edu_lesson($org, $lessonId);
        if (!$l || (int) $l['course_id'] !== $courseId) {
            throw new InvalidArgumentException('That lesson is not in this course.');
        }
        if (count(edu_lesson_files($org, $lessonId)) >= 30) {
            throw new InvalidArgumentException('A lesson holds up to 30 files.');
        }
    }
    $ext = strtolower(pathinfo((string) ($upload['name'] ?? ''), PATHINFO_EXTENSION));
    if ($role === 'cover' ? !in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) : !isset(edu_file_kinds()[$ext])) {
        throw new InvalidArgumentException(($upload['name'] ?? 'That file') . ': ' . ($role === 'cover' ? 'use a JPG, PNG or WebP image.' : 'that kind of file is not accepted. Use PDF, PowerPoint, Word, Excel, an image, MP4 or ZIP.'));
    }
    [$ok, $msg, $meta] = fabo_store_upload($upload);
    if (!$ok) {
        throw new InvalidArgumentException(($upload['name'] ?? 'That file') . ': ' . $msg);
    }
    if ($role === 'cover') {
        foreach (fabo_all('SELECT * FROM edu_files WHERE org_id = ? AND course_id = ? AND role = "cover"', 'ii', [$org, $courseId]) as $old) {
            edu_file_delete($org, (int) $old['id']);
        }
    }
    $sort = (int) (fabo_one('SELECT COALESCE(MAX(sort), 0) + 1 AS s FROM edu_files WHERE org_id = ? AND lesson_id = ?', 'ii', [$org, $lessonId])['s'] ?? 1);
    $id = fabo_exec('INSERT INTO edu_files (org_id, course_id, lesson_id, role, stored_name, original_name, mime, size, sort, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                    'iiissssiiss', [$org, $courseId, $role === 'cover' ? 0 : $lessonId, $role, $meta['stored_name'], $meta['original_name'], $meta['mime'], (int) $meta['size'], $sort, mb_substr($actor, 0, 190), edu_now()]);
    return edu_file($org, $id);
}

function edu_file_delete(int $org, int $id): ?array
{
    $f = edu_file($org, $id);
    if ($f) {
        fabo_exec('DELETE FROM edu_files WHERE org_id = ? AND id = ?', 'ii', [$org, $id]);
        fabo_delete_stored((string) $f['stored_name']);
    }
    return $f;
}

/** Removes every file of a lesson (or, $lessonId null, of a whole course). */
function edu_files_purge(int $org, int $courseId, ?int $lessonId = null): void
{
    edu_builder_install();
    $rows = $lessonId === null
        ? fabo_all('SELECT id FROM edu_files WHERE org_id = ? AND course_id = ?', 'ii', [$org, $courseId])
        : fabo_all('SELECT id FROM edu_files WHERE org_id = ? AND lesson_id = ? AND role = "lesson"', 'ii', [$org, $lessonId]);
    foreach ($rows as $r) {
        edu_file_delete($org, (int) $r['id']);
    }
}

/**
 * Sends a file to the browser and ends the request. The caller has already
 * decided this person may have it. Inline for PDF, images and MP4 (with
 * byte ranges, so a video or a long PDF can be scrolled), a download for
 * the rest.
 */
function edu_file_send(array $f, bool $download = false): void
{
    $path = fabo_upload_dir() . DIRECTORY_SEPARATOR . (string) $f['stored_name'];
    if (!preg_match('/^[0-9a-f]{32}\.[A-Za-z0-9]{1,8}$/', (string) $f['stored_name']) || !is_file($path)) {
        http_response_code(404);
        echo 'This file is no longer here.';
        exit;
    }
    if (function_exists('fabo_db_release')) {
        fabo_db_release();     // a long download must not hold a database connection
    }
    $size = (int) filesize($path);
    $inline = !$download && edu_file_inline($f);
    $name = (string) $f['original_name'];
    $ascii = preg_replace('/[^A-Za-z0-9._ -]/', '_', $name);
    header('Content-Type: ' . ($inline ? (string) $f['mime'] : 'application/octet-stream'));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=300');
    header('Accept-Ranges: bytes');
    // Only our own pages may frame it (the lesson's PDF viewer). Anything not shown inline goes out as a download.
    header("Content-Security-Policy: frame-ancestors 'self'");
    $start = 0;
    $end = $size - 1;
    if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', (string) $_SERVER['HTTP_RANGE'], $m)) {
        $start = $m[1] === '' ? max(0, $size - (int) $m[2]) : (int) $m[1];
        $end = $m[1] !== '' && $m[2] !== '' ? min((int) $m[2], $size - 1) : $end;
        if ($start > $end || $start >= $size) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            exit;
        }
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }
    header('Content-Length: ' . ($end - $start + 1));
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $fh = fopen($path, 'rb');
    fseek($fh, $start);
    $left = $end - $start + 1;
    while ($left > 0 && !feof($fh)) {
        $chunk = fread($fh, (int) min(1048576, $left));
        if ($chunk === false) {
            break;
        }
        echo $chunk;
        $left -= strlen($chunk);
        flush();
    }
    fclose($fh);
    exit;
}

/** May a Campus person open this file? Covers of courses they may see; lesson files of courses they are on (or may join). */
function edu_file_allowed_for(int $org, array $f, array $person): bool
{
    $c = edu_course($org, (int) $f['course_id']);
    if (!$c) {
        return false;
    }
    if (edu_enrolment($org, (int) $c['id'], (int) $person['id'])) {
        return true;
    }
    return $f['role'] === 'cover' && edu_course_for($org, $c, $person);
}

/** The course's readiness for publishing: [[label, done?, hint, required?], ...] and a percentage. */
function edu_course_checklist(int $org, array $c): array
{
    require_once __DIR__ . '/blocks.php';
    $lessons = edu_lessons($org, (int) $c['id']);
    $problems = array_values(array_filter(array_map(static fn($l) => edu_lesson_problem($org, $l), $lessons)));
    $items = [
        ['Title and summary', trim((string) $c['summary']) !== '', 'Write a short summary for the course card.', false],
        ['At least one lesson', (bool) $lessons, 'Add lessons on the Curriculum tab.', true],
        ['Every lesson has its content', !$problems, $problems ? implode(' ', array_slice($problems, 0, 3)) . (count($problems) > 3 ? ' …and ' . (count($problems) - 3) . ' more.' : '') : '', true],
        ['Learning outcomes', trim((string) ($c['outcomes'] ?? '')) !== '', 'Say what students will be able to do.', false],
        ['Cover image', (bool) edu_course_cover($org, (int) $c['id']), 'Optional: a picture for the course card.', false],
    ];
    $done = count(array_filter($items, static fn($i) => $i[1]));
    return [$items, (int) round($done * 100 / count($items))];
}

/** Lessons grouped by section, in order: [[section name, [lessons…]], …]. Consecutive lessons with the same section form one group. */
function edu_lesson_groups(array $lessons): array
{
    $out = [];
    foreach ($lessons as $l) {
        $s = trim((string) ($l['section'] ?? ''));
        if (!$out || $out[count($out) - 1][0] !== $s) {
            $out[] = [$s, []];
        }
        $out[count($out) - 1][1][] = $l;
    }
    return $out;
}

/** Puts the lessons of a course in this order (ids not in the course are ignored; missing ones go last). */
function edu_lessons_reorder(int $org, int $courseId, array $ids): void
{
    $have = array_map(static fn($l) => (int) $l['id'], edu_lessons($org, $courseId));
    $order = array_values(array_intersect(array_map('intval', $ids), $have));
    foreach (array_diff($have, $order) as $rest) {
        $order[] = $rest;
    }
    foreach ($order as $n => $lid) {
        fabo_exec('UPDATE edu_lessons SET sort = ? WHERE org_id = ? AND id = ?', 'iii', [$n + 1, $org, $lid]);
    }
}

function edu_human_size(int $b): string
{
    return $b >= 1048576 ? round($b / 1048576, 1) . ' MB' : max(1, (int) round($b / 1024)) . ' KB';
}

/**
 * A lesson's files as the student sees them. A document lesson shows its
 * PDFs and images in the page (a viewer per file) and the rest as download
 * cards; any other lesson lists them as resources. $url(file, download?)
 * makes each address (the desk and the portal serve files differently).
 */
function edu_lesson_files_html(int $org, array $l, callable $url): string
{
    $files = edu_lesson_files($org, (int) $l['id']);
    if (!$files) {
        return '';
    }
    $main = $l['kind'] === 'document';
    // What opens in the page (PDF, images, video) first, then the downloads, each in the order staff set.
    if ($main) {
        usort($files, static fn($a, $b) => (int) edu_file_inline($b) <=> (int) edu_file_inline($a) ?: (int) $a['sort'] <=> (int) $b['sort']);
    }
    $h = '<div class="cp-files' . ($main ? ' main' : '') . '">' . ($main ? '' : '<h3 class="cp-files-h">Resources</h3>');
    foreach ($files as $f) {
        $ext = edu_file_ext($f);
        $tag = edu_file_tag($f);
        $name = htmlspecialchars((string) $f['original_name'], ENT_QUOTES);
        $open = htmlspecialchars($url($f, false), ENT_QUOTES);
        $dl = htmlspecialchars($url($f, true), ENT_QUOTES);
        if ($main && $ext === 'pdf') {
            $h .= '<figure class="cp-viewer"><figcaption><span class="cp-fico t-pdf">PDF</span><strong>' . $name . '</strong><a href="' . $open . '" target="_blank" rel="noopener">Open full screen ↗</a><a href="' . $dl . '">Download</a></figcaption>'
                . '<iframe src="' . $open . '#view=FitH" title="' . $name . '" loading="lazy"></iframe></figure>';
        } elseif ($main && $tag === 'img') {
            $h .= '<figure class="cp-viewer img"><img src="' . $open . '" alt="' . $name . '" loading="lazy"><figcaption><strong>' . $name . '</strong><a href="' . $dl . '">Download</a></figcaption></figure>';
        } elseif ($main && $ext === 'mp4') {
            $h .= '<figure class="cp-viewer vid"><video src="' . $open . '" controls preload="metadata"></video><figcaption><strong>' . $name . '</strong><a href="' . $dl . '">Download</a></figcaption></figure>';
        } else {
            $h .= '<a class="cp-fcard t-' . $tag . '" href="' . $dl . '"><span class="cp-fico t-' . $tag . '">' . htmlspecialchars(strtoupper($ext), ENT_QUOTES) . '</span><span><strong>' . $name . '</strong><em>'
                . htmlspecialchars(edu_file_kinds()[$ext] ?? 'File', ENT_QUOTES) . ' · ' . edu_human_size((int) $f['size']) . ($tag === 'ppt' || $tag === 'doc' || $tag === 'xls' ? ' · opens in PowerPoint, Word, Excel or Google' : '')
                . '</em></span><span class="cp-fdl">Download</span></a>';
        }
    }
    return $h . '</div>';
}

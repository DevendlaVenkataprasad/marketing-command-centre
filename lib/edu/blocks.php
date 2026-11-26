<?php
/**
 * Academic — lesson content blocks.
 *
 * A lesson is a stack of blocks, in any mix and order:
 *   text     formatted writing (the lesson text marks: # heading, - list, **bold**…)
 *   video    a YouTube or Vimeo video, with a caption
 *   file     a document, slides, sheet, image or video file (edu_files), with a caption
 *   link     a page elsewhere: address, title, what to do there
 *   callout  a highlighted tip, note or warning
 * A quiz lesson may carry blocks too; its questions follow them.
 *
 * Lessons made before blocks existed keep their old content (body, url,
 * files) and are shown that way until someone opens them in the builder,
 * which turns that content into blocks once (edu_blocks_migrate()).
 */

require_once __DIR__ . '/files.php';

function edu_blocks_install(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if ((int) fabo_setting('edu_blocks_schema', '0') >= 7) {
        return;
    }
    fabo_exec("CREATE TABLE IF NOT EXISTS edu_blocks (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      org_id INT UNSIGNED NOT NULL,
      lesson_id INT UNSIGNED NOT NULL,
      sort SMALLINT NOT NULL DEFAULT 0,
      type ENUM('text','video','file','link','callout') NOT NULL,
      title VARCHAR(190) NOT NULL DEFAULT '',
      body MEDIUMTEXT NULL,
      url VARCHAR(500) NOT NULL DEFAULT '',
      style VARCHAR(20) NOT NULL DEFAULT '',
      file_id INT UNSIGNED NULL,
      updated_at DATETIME NULL,
      KEY k_lesson (org_id, lesson_id, sort)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    fabo_exec('INSERT INTO app_settings (k, v) VALUES ("edu_blocks_schema", "7") ON DUPLICATE KEY UPDATE v = VALUES(v)');
}

function edu_block_types(): array
{
    return ['text' => 'Text', 'video' => 'Video', 'file' => 'Document', 'link' => 'Link', 'callout' => 'Callout'];
}

function edu_callout_styles(): array
{
    return ['tip' => 'Tip', 'info' => 'Note', 'warning' => 'Important'];
}

function edu_blocks(int $org, int $lessonId): array
{
    edu_blocks_install();
    return fabo_all('SELECT b.*, f.original_name, f.mime, f.size, f.stored_name, f.course_id AS f_course
                       FROM edu_blocks b LEFT JOIN edu_files f ON f.id = b.file_id AND f.org_id = b.org_id
                      WHERE b.org_id = ? AND b.lesson_id = ? ORDER BY b.sort, b.id', 'ii', [$org, $lessonId]);
}

function edu_block(int $org, int $id): ?array
{
    edu_blocks_install();
    return fabo_one('SELECT b.*, f.original_name, f.mime, f.size, f.stored_name FROM edu_blocks b LEFT JOIN edu_files f ON f.id = b.file_id AND f.org_id = b.org_id
                      WHERE b.org_id = ? AND b.id = ?', 'ii', [$org, $id]);
}

/** Block counts by type for each lesson of a course: lesson id => [type => n]. */
function edu_block_summary(int $org, int $courseId): array
{
    edu_blocks_install();
    $out = [];
    foreach (fabo_all('SELECT b.lesson_id, b.type, COUNT(*) AS n FROM edu_blocks b JOIN edu_lessons l ON l.id = b.lesson_id
                        WHERE b.org_id = ? AND l.course_id = ? GROUP BY b.lesson_id, b.type', 'ii', [$org, $courseId]) as $r) {
        $out[(int) $r['lesson_id']][$r['type']] = (int) $r['n'];
    }
    return $out;
}

/** "Text · Video · 2 documents" for a lesson. */
function edu_block_words(array $counts): string
{
    $w = [];
    foreach (['text' => ['text', 'texts'], 'video' => ['video', 'videos'], 'file' => ['document', 'documents'], 'link' => ['link', 'links'], 'callout' => ['callout', 'callouts']] as $t => [$one, $many]) {
        if (!empty($counts[$t])) {
            $w[] = ($counts[$t] > 1 ? $counts[$t] . ' ' . $many : $one);
        }
    }
    return implode(' · ', $w);
}

/**
 * Adds a block to a lesson, after block $after (0: at the end). File blocks
 * are made by edu_block_add_file(). Returns the new block's id.
 */
function edu_block_add(int $org, int $lessonId, string $type, int $after = 0, array $d = []): int
{
    edu_blocks_install();
    $l = edu_lesson($org, $lessonId);
    if (!$l) {
        throw new InvalidArgumentException('That lesson is not here.');
    }
    if (!isset(edu_block_types()[$type])) {
        throw new InvalidArgumentException('Pick what to add.');
    }
    if (count(edu_blocks($org, $lessonId)) >= 60) {
        throw new InvalidArgumentException('A lesson holds up to 60 blocks. Split it into two lessons.');
    }
    $id = fabo_exec('INSERT INTO edu_blocks (org_id, lesson_id, sort, type, style, updated_at) VALUES (?,?,?,?,?,?)',
                    'iiisss', [$org, $lessonId, 0, $type, $type === 'callout' ? 'tip' : '', edu_now()]);
    if ($d) {
        edu_block_save($org, $id, $d);
    }
    edu_block_place($org, $lessonId, $id, $after);
    return $id;
}

/** Stores an uploaded file and adds it to the lesson as a document block. */
function edu_block_add_file(int $org, int $lessonId, array $upload, string $actor, int $after = 0): int
{
    $l = edu_lesson($org, $lessonId);
    if (!$l) {
        throw new InvalidArgumentException('That lesson is not here.');
    }
    $f = edu_file_add($org, (int) $l['course_id'], $lessonId, $upload, $actor);
    $id = edu_block_add($org, $lessonId, 'file', $after);
    fabo_exec('UPDATE edu_blocks SET file_id = ? WHERE id = ?', 'ii', [(int) $f['id'], $id]);
    return $id;
}

/** Puts block $id right after block $after (0: last), renumbering the lesson. */
function edu_block_place(int $org, int $lessonId, int $id, int $after): void
{
    $ids = array_values(array_filter(array_map(static fn($b) => (int) $b['id'], edu_blocks($org, $lessonId)), static fn($x) => $x !== $id));
    $pos = $after ? array_search($after, $ids, true) : false;
    array_splice($ids, $pos === false ? count($ids) : $pos + 1, 0, [$id]);
    edu_blocks_reorder($org, $lessonId, $ids);
}

function edu_blocks_reorder(int $org, int $lessonId, array $ids): void
{
    $have = array_map(static fn($b) => (int) $b['id'], fabo_all('SELECT id FROM edu_blocks WHERE org_id = ? AND lesson_id = ? ORDER BY sort, id', 'ii', [$org, $lessonId]));
    $order = array_values(array_intersect(array_map('intval', $ids), $have));
    foreach (array_diff($have, $order) as $rest) {
        $order[] = $rest;
    }
    foreach ($order as $n => $bid) {
        fabo_exec('UPDATE edu_blocks SET sort = ? WHERE org_id = ? AND id = ?', 'iii', [$n + 1, $org, $bid]);
    }
}

/** Saves what a block holds; only the fields its type uses. */
function edu_block_save(int $org, int $id, array $d): void
{
    $b = edu_block($org, $id);
    if (!$b) {
        throw new InvalidArgumentException('That block is not here.');
    }
    $title = edu_clean($d['title'] ?? $b['title'], 190);
    $body = mb_substr((string) ($d['body'] ?? $b['body']), 0, 60000);
    $url = trim((string) ($d['url'] ?? $b['url']));
    $style = isset(edu_callout_styles()[$d['style'] ?? '']) ? $d['style'] : (string) $b['style'];
    if ($url !== '' && !preg_match('#^https://[^\s<>"]+$#i', $url)) {
        throw new InvalidArgumentException('Addresses must start with https://');
    }
    if ($b['type'] === 'video' && $url !== '' && edu_video_embed($url) === null) {
        throw new InvalidArgumentException('Use a YouTube or Vimeo address (or add the video file as a document).');
    }
    fabo_exec('UPDATE edu_blocks SET title = ?, body = ?, url = ?, style = ?, updated_at = ? WHERE org_id = ? AND id = ?',
              'sssssii', [$title, $body, in_array($b['type'], ['video', 'link'], true) ? mb_substr($url, 0, 500) : '', $style, edu_now(), $org, $id]);
}

/** Removes a block; a document block takes its file with it. Returns the lesson id. */
function edu_block_delete(int $org, int $id): int
{
    $b = edu_block($org, $id);
    if (!$b) {
        throw new InvalidArgumentException('That block is not here.');
    }
    fabo_exec('DELETE FROM edu_blocks WHERE org_id = ? AND id = ?', 'ii', [$org, $id]);
    if ($b['type'] === 'file' && $b['file_id']) {
        edu_file_delete($org, (int) $b['file_id']);
    }
    return (int) $b['lesson_id'];
}

function edu_blocks_purge(int $org, int $lessonId): void
{
    edu_blocks_install();
    fabo_exec('DELETE FROM edu_blocks WHERE org_id = ? AND lesson_id = ?', 'ii', [$org, $lessonId]);
}

/**
 * Turns a lesson made before blocks existed into blocks, once: its video
 * or link, its text, then its files. Does nothing when it has blocks.
 */
function edu_blocks_migrate(int $org, array $l): void
{
    edu_blocks_install();
    if (fabo_one('SELECT id FROM edu_blocks WHERE org_id = ? AND lesson_id = ? LIMIT 1', 'ii', [$org, (int) $l['id']])) {
        return;
    }
    $lid = (int) $l['id'];
    if ($l['kind'] === 'video' && $l['url'] !== '') {
        edu_block_add($org, $lid, 'video', 0, ['url' => $l['url']]);
    }
    if ($l['kind'] === 'link' && $l['url'] !== '') {
        edu_block_add($org, $lid, 'link', 0, ['url' => $l['url'], 'title' => $l['title']]);
    }
    if (trim((string) $l['body']) !== '') {
        edu_block_add($org, $lid, 'text', 0, ['body' => (string) $l['body']]);
    }
    foreach (edu_lesson_files($org, $lid) as $f) {
        $bid = edu_block_add($org, $lid, 'file');
        fabo_exec('UPDATE edu_blocks SET file_id = ? WHERE id = ?', 'ii', [(int) $f['id'], $bid]);
    }
    // The old fields stay as they were; with blocks present, only blocks are shown.
}

/** What is missing in a lesson before its course can be published, or null. */
function edu_lesson_problem(int $org, array $l): ?string
{
    if ($l['kind'] === 'quiz' && !edu_questions($org, (int) $l['id'])) {
        return 'The quiz "' . $l['title'] . '" has no questions yet.';
    }
    $blocks = edu_blocks($org, (int) $l['id']);
    if ($blocks) {
        foreach ($blocks as $b) {
            if ($b['type'] === 'video' && $b['url'] === '') {
                return 'A video in "' . $l['title'] . '" has no address yet.';
            }
            if ($b['type'] === 'link' && $b['url'] === '') {
                return 'A link in "' . $l['title'] . '" has no address yet.';
            }
            if ($b['type'] === 'file' && !$b['stored_name']) {
                return 'A document in "' . $l['title'] . '" has lost its file.';
            }
        }
        return null;
    }
    // Not yet in blocks: the old rules.
    if (in_array($l['kind'], ['video', 'link'], true) && $l['url'] === '') {
        return '"' . $l['title'] . '" needs its address.';
    }
    if ($l['kind'] === 'document' && !edu_lesson_files($org, (int) $l['id'])) {
        return 'The document lesson "' . $l['title'] . '" has no file yet.';
    }
    if ($l['kind'] !== 'quiz' && trim((string) $l['body']) === '' && $l['url'] === '' && !edu_lesson_files($org, (int) $l['id'])) {
        return '"' . $l['title'] . '" is empty: add some content.';
    }
    return null;
}

/* ================================================================ render */

/**
 * The lesson's content as students see it: its blocks in order, or, for a
 * lesson not yet in blocks, its old text, video or link and files.
 * $url(file row, download?) makes a file's address.
 */
function edu_lesson_render(int $org, array $l, callable $url): string
{
    $blocks = edu_blocks($org, (int) $l['id']);
    if (!$blocks) {
        ob_start();
        echo '<div class="cp-lesson-body">';
        edu_lesson_preview($l);
        echo '</div>';
        return (string) ob_get_clean() . edu_lesson_files_html($org, $l, $url);
    }
    $h = '<div class="cp-blocks">';
    foreach ($blocks as $b) {
        $h .= edu_block_render($b, $url);
    }
    return $h . '</div>';
}

function edu_block_render(array $b, callable $url): string
{
    $e = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
    $cap = trim((string) $b['title']) !== '' && $b['type'] !== 'link' ? '<figcaption class="cp-bcap">' . $e($b['title']) . '</figcaption>' : '';
    switch ($b['type']) {
        case 'text':
            return trim((string) $b['body']) === '' ? '' : '<div class="cp-block cp-lesson-body">' . edu_render_text((string) $b['body']) . '</div>';
        case 'callout':
            $icon = ['tip' => '💡', 'info' => 'ℹ️', 'warning' => '⚠️'][$b['style']] ?? '💡';
            return trim((string) $b['body']) === '' ? '' : '<aside class="cp-block cp-callout s-' . $e($b['style'] ?: 'tip') . '"><span aria-hidden="true">' . $icon . '</span><div class="cp-lesson-body">'
                 . ($b['title'] !== '' ? '<strong>' . $e($b['title']) . '</strong>' : '') . edu_render_text((string) $b['body']) . '</div></aside>';
        case 'video':
            $src = edu_video_embed((string) $b['url']);
            return !$src ? '' : '<figure class="cp-block cp-bvideo"><div class="cp-video"><iframe src="' . $e($src) . '" title="' . $e($b['title'] ?: 'Video') . '" allow="accelerometer; encrypted-media; gyroscope; picture-in-picture; fullscreen" allowfullscreen loading="lazy" referrerpolicy="strict-origin-when-cross-origin"></iframe></div>' . $cap . '</figure>';
        case 'link':
            if ($b['url'] === '') {
                return '';
            }
            $host = (string) parse_url((string) $b['url'], PHP_URL_HOST);
            return '<a class="cp-block cp-blink" href="' . $e($b['url']) . '" target="_blank" rel="noopener nofollow"><span class="cp-blink-ico" aria-hidden="true">↗</span><span><strong>' . $e($b['title'] ?: $host) . '</strong>'
                 . ($b['body'] !== '' && $b['body'] !== null ? '<em>' . $e($b['body']) . '</em>' : '') . '<small>' . $e($host) . '</small></span></a>';
        case 'file':
            if (!$b['stored_name']) {
                return '';
            }
            $f = ['id' => $b['file_id'], 'original_name' => $b['original_name'], 'mime' => $b['mime'], 'size' => $b['size']];
            $ext = edu_file_ext($f);
            $tag = edu_file_tag($f);
            $open = $e($url($f, false));
            $dl = $e($url($f, true));
            $name = $e($f['original_name']);
            if ($ext === 'pdf') {
                return '<figure class="cp-block cp-viewer"><figcaption><span class="cp-fico t-pdf">PDF</span><strong>' . ($b['title'] !== '' ? $e($b['title']) : $name) . '</strong><a href="' . $open . '" target="_blank" rel="noopener">Open full screen ↗</a><a href="' . $dl . '">Download</a></figcaption>'
                     . '<iframe src="' . $open . '#view=FitH" title="' . $name . '" loading="lazy"></iframe></figure>';
            }
            if ($tag === 'img') {
                return '<figure class="cp-block cp-bimg"><img src="' . $open . '" alt="' . $e($b['title'] ?: $f['original_name']) . '" loading="lazy">' . $cap . '</figure>';
            }
            if ($ext === 'mp4') {
                return '<figure class="cp-block cp-bvideo"><video src="' . $open . '" controls preload="metadata"></video>' . $cap . '</figure>';
            }
            return '<a class="cp-block cp-fcard t-' . $tag . '" href="' . $dl . '"><span class="cp-fico t-' . $tag . '">' . $e(strtoupper($ext)) . '</span><span><strong>' . ($b['title'] !== '' ? $e($b['title']) : $name) . '</strong><em>'
                 . $name . ' · ' . $e(edu_file_kinds()[$ext] ?? 'File') . ' · ' . edu_human_size((int) $f['size']) . '</em></span><span class="cp-fdl">Download</span></a>';
    }
    return '';
}

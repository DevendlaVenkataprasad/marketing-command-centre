<?php
/**
 * Academic desk — the course builder, and Mentoring. Loaded by lib/edu/app.php.
 *
 *   courses    a gallery of every course (search, status filter) and "New course"
 *   course     the builder for one course:
 *                ?tab=curriculum (default)  sections and lessons, drag to reorder; ?lesson= opens one in the editor
 *                ?tab=details               title, summary, outcomes, prerequisites, cover, colour, audience, pass mark
 *                ?tab=results               students and results
 *   lesson     (old links) opens that lesson in the builder
 *   file       ?id= sends a course file to staff
 *   mentoring  sessions across the organisation
 *
 * The page works without JavaScript (plain forms); assets/academic-builder.js
 * adds drag-and-drop ordering, file uploads in pieces with progress, the
 * formatting toolbar with live preview, the video preview and the quiz
 * answer rows.
 */

require_once __DIR__ . '/lms.php';
require_once __DIR__ . '/files.php';
require_once __DIR__ . '/mentoring.php';
require_once __DIR__ . '/app_blocks.php';      // the lesson editor: content blocks

/* ================================================================== POST */

function edu_json(array $out, int $code = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Builder actions. Redirects (or answers JSON) when it handles one. */
function edu_post_lms(int $org, string $a): bool
{
    $id = (int) ($_POST['id'] ?? 0);
    edu_post_blocks($org, $a);       // block actions: answers or redirects when it acts
    // The quiet actions the page sends in the background answer in JSON, errors included.
    if (in_array($a, ['lesson-order', 'file-upload', 'file-delete', 'preview-md'], true)) {
        try {
            if ($a === 'preview-md') {
                edu_json(['html' => edu_render_text(mb_substr((string) ($_POST['text'] ?? ''), 0, 60000))]);
            }
            if ($a === 'lesson-order') {
                $c = edu_course($org, $id);
                if (!$c) {
                    throw new InvalidArgumentException('That course is not here.');
                }
                $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
                $secs = (array) ($_POST['sections'] ?? []);
                edu_lessons_reorder($org, $id, $ids);
                foreach ($ids as $n => $lid) {
                    if (isset($secs[$n])) {
                        fabo_exec('UPDATE edu_lessons SET section = ? WHERE org_id = ? AND course_id = ? AND id = ?', 'siii', [edu_clean((string) $secs[$n], 120), $org, $id, $lid]);
                    }
                }
                edu_json(['ok' => true]);
            }
            if ($a === 'file-upload') {
                $lid = (int) ($_POST['lesson'] ?? 0);
                $l = edu_lesson($org, $lid);
                if (!$l) {
                    throw new InvalidArgumentException('That lesson is not here.');
                }
                fabo_session();
                fabo_upload_take_chunk('file', 'edu|' . $org . '|' . $lid . '|' . session_id(), static fn(string $m) => edu_json(['ok' => false, 'error' => $m], 400));
                $up = $_FILES['file'] ?? null;
                if (!$up) {
                    throw new InvalidArgumentException('No file arrived.');
                }
                if (!empty($_POST['block'])) {
                    // Into the lesson's content, as a document block.
                    $bid = edu_block_add_file($org, $lid, $up, edu_actor(), (int) ($_POST['after'] ?? 0));
                    $bk = edu_block($org, $bid);
                    edu_audit($org, edu_actor(), 'file-add', (string) $bk['original_name']);
                    edu_json(['ok' => true, 'id' => $bid, 'html' => edu_block_card($org, $bk, true)]);
                }
                $f = edu_file_add($org, (int) $l['course_id'], $lid, $up, edu_actor());
                edu_audit($org, edu_actor(), 'file-add', $f['original_name']);
                edu_json(['ok' => true, 'html' => edu_file_card_desk($f, true)]);
            }
            if ($a === 'file-delete') {
                $f = edu_file_delete($org, $id);
                fabo_exec('DELETE FROM edu_blocks WHERE org_id = ? AND file_id = ?', 'ii', [$org, $id]);
                if ($f) {
                    edu_audit($org, edu_actor(), 'file-delete', $f['original_name']);
                }
                if (!empty($_POST['ajax'])) {
                    edu_json(['ok' => true]);
                }
                edu_flash('ok', 'File removed.');
                edu_go('course', ['id' => (int) ($f['course_id'] ?? 0), 'lesson' => (int) ($f['lesson_id'] ?? 0)]);
            }
        } catch (InvalidArgumentException $e) {
            edu_json(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (Throwable $e) {
            error_log('FABO edu builder ' . $a . ': ' . $e->getMessage());
            edu_json(['ok' => false, 'error' => 'That could not be saved. Try again.'], 500);
        }
    }
    switch ($a) {
        case 'course-new':
            $cid = edu_course_save($org, $_POST, edu_actor());
            edu_audit($org, edu_actor(), 'course-add', (string) $_POST['title']);
            edu_flash('ok', 'Course created. Add its first lesson.');
            edu_go('course', ['id' => $cid]);
        case 'course-save':
            $cid = edu_course_save($org, $_POST, edu_actor(), $id ?: null);
            if (!empty($_FILES['cover']) && (int) ($_FILES['cover']['error'] ?? 4) !== UPLOAD_ERR_NO_FILE) {
                edu_file_add($org, $cid, 0, $_FILES['cover'], edu_actor(), 'cover');
            } elseif (!empty($_POST['cover_remove'])) {
                $cv = edu_course_cover($org, $cid);
                if ($cv) {
                    edu_file_delete($org, (int) $cv['id']);
                }
            }
            edu_audit($org, edu_actor(), 'course-edit', (string) $_POST['title']);
            edu_flash('ok', 'Saved.');
            edu_go('course', ['id' => $cid, 'tab' => 'details']);
        case 'course-status':
            $st = (string) ($_POST['status'] ?? '');
            edu_course_status($org, $id, $st);
            edu_audit($org, edu_actor(), 'course-' . $st, (string) $id);
            edu_flash('ok', ['published' => 'Published: students it is for can see it now.', 'draft' => 'Back to draft: students no longer see it.',
                             'archived' => 'Archived: hidden from new students; certificates stay valid.'][$st] ?? 'Saved.');
            edu_go('course', ['id' => $id]);
        case 'course-delete':
            if (($_POST['confirm'] ?? '') !== 'DELETE') {
                throw new InvalidArgumentException('Type DELETE to remove the course.');
            }
            $c = edu_course($org, $id);
            edu_course_delete($org, $id);
            edu_audit($org, edu_actor(), 'course-delete', (string) ($c['title'] ?? $id));
            edu_flash('ok', 'Course removed, with its files and everyone\'s progress on it.');
            edu_go('courses');
        case 'sample-course':
            $cid = edu_sample_course($org, edu_actor());
            edu_audit($org, edu_actor(), 'course-add', 'sample course');
            edu_flash('ok', 'Sample course added and published.');
            edu_go('course', ['id' => $cid]);
        case 'lesson-add':
            $course = (int) ($_POST['course_id'] ?? 0);
            $lid = edu_lesson_save($org, $course, $_POST);
            // A lesson starts with a block of its kind (a document waits for its file on the drop zone).
            $first = ['text' => 'text', 'video' => 'video', 'link' => 'link'][$_POST['kind'] ?? ''] ?? null;
            if ($first) {
                edu_block_add($org, $lid, $first);
            }
            edu_flash('ok', ['quiz' => 'Quiz added. Write its questions below.', 'document' => 'Document lesson added. Drop its files below.',
                             'video' => 'Video lesson added. Paste its YouTube or Vimeo address.'][$_POST['kind'] ?? ''] ?? 'Lesson added.');
            edu_go('course', ['id' => $course, 'lesson' => $lid]);
        case 'lesson-save':
            $course = (int) ($_POST['course_id'] ?? 0);
            edu_lesson_save($org, $course, $_POST, $id);
            edu_flash('ok', 'Lesson saved.');
            edu_go('course', ['id' => $course, 'lesson' => $id]);
        case 'lesson-delete':
            $course = edu_lesson_delete($org, $id);
            edu_flash('ok', 'Lesson removed, with its files.');
            edu_go('course', ['id' => $course]);
        case 'lesson-move':
            $course = edu_lesson_move($org, $id, (int) ($_POST['dir'] ?? 1));
            edu_go('course', ['id' => $course, 'lesson' => $id]);
        case 'section-rename':
            $course = (int) ($_POST['course_id'] ?? 0);
            if (!edu_course($org, $course)) {
                throw new InvalidArgumentException('That course is not here.');
            }
            fabo_exec('UPDATE edu_lessons SET section = ? WHERE org_id = ? AND course_id = ? AND section = ?', 'siis',
                      [edu_clean((string) ($_POST['to'] ?? ''), 120), $org, $course, (string) ($_POST['from'] ?? '')]);
            edu_flash('ok', 'Section renamed.');
            edu_go('course', ['id' => $course]);
        case 'question-save':
            edu_question_save($org, $id, $_POST, ((int) ($_POST['qid'] ?? 0)) ?: null);
            edu_flash('ok', 'Question saved.');
            edu_go('course', ['id' => (int) (edu_lesson($org, $id)['course_id'] ?? 0), 'lesson' => $id]);
        case 'question-delete':
            edu_question_delete($org, $id, (int) ($_POST['qid'] ?? 0));
            edu_flash('ok', 'Question removed.');
            edu_go('course', ['id' => (int) (edu_lesson($org, $id)['course_id'] ?? 0), 'lesson' => $id]);
    }
    return false;
}

/** Staff open or download a course file (?p=file&id=). */
function edu_desk_file(int $org): void
{
    $f = edu_file($org, (int) ($_GET['id'] ?? 0));
    if (!$f) {
        http_response_code(404);
        exit('This file is not here.');
    }
    edu_file_send($f, !empty($_GET['dl']));
}

/* =============================================================== helpers */

function edu_colour(int $n): string
{
    return 'c' . max(1, min(8, $n));
}

function edu_status_pill(string $s): string
{
    return ['published' => '<span class="pill green">Published</span>', 'draft' => '<span class="pill amber">Draft</span>',
            'archived' => '<span class="pill grey">Archived</span>'][$s] ?? '';
}

/** A line icon for a lesson kind (or file tag). */
function edu_icon(string $k): string
{
    $p = [
        'text'     => '<path d="M5 4h10l4 4v12H5z"/><path d="M15 4v4h4M8 12h8M8 16h6"/>',
        'video'    => '<rect x="3" y="5" width="18" height="14" rx="3"/><path d="M10 9l5 3-5 3z"/>',
        'document' => '<path d="M6 3h8l5 5v13H6z"/><path d="M14 3v5h5M9 13h7M9 17h5"/>',
        'link'     => '<path d="M10 14a4 4 0 0 0 6 0l3-3a4 4 0 0 0-6-6l-1 1"/><path d="M14 10a4 4 0 0 0-6 0l-3 3a4 4 0 0 0 6 6l1-1"/>',
        'quiz'     => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6V14M12 17h.01"/>',
        'drag'     => '<circle cx="9" cy="6" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="18" r="1"/>',
        'upload'   => '<path d="M12 16V4M7 9l5-5 5 5"/><path d="M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/>',
        'plus'     => '<path d="M12 5v14M5 12h14"/>',
        'eye'      => '<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'check'    => '<path d="M5 12l5 5 9-10"/>',
        'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'users'    => '<circle cx="9" cy="8" r="3.5"/><path d="M3 20a6 6 0 0 1 12 0M16 4.5a3.5 3.5 0 0 1 0 7M18 14.5a6 6 0 0 1 3 5.5"/>',
        'layers'   => '<path d="M12 3l9 5-9 5-9-5z"/><path d="M3 13l9 5 9-5"/>',
        'search'   => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/>',
        'trash'    => '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
        'image'    => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="M21 16l-5-5-9 9"/>',
        'download' => '<path d="M12 4v11M7 10l5 5 5-5M5 20h14"/>',
    ][$k] ?? '<circle cx="12" cy="12" r="8"/>';
    return '<svg class="ei" viewBox="0 0 24 24" aria-hidden="true">' . $p . '</svg>';
}

function edu_file_url_desk(array $f, bool $dl = false): string
{
    return edu_url('file', ['id' => (int) $f['id']] + ($dl ? ['dl' => 1] : []));
}

/** One file as a card on the builder. */
function edu_file_card_desk(array $f, bool $edit): string
{
    $tag = edu_file_tag($f);
    return '<li class="eb-file t-' . $tag . '" data-file="' . (int) $f['id'] . '"><span class="eb-fico">' . e(strtoupper(edu_file_ext($f))) . '</span>'
         . '<span class="eb-fname"><strong title="' . e($f['original_name']) . '">' . e($f['original_name']) . '</strong><em>' . e(edu_file_kinds()[edu_file_ext($f)] ?? 'File') . ' · ' . e(edu_human_size((int) $f['size'])) . '</em></span>'
         . (edu_file_inline($f) ? '<a class="eb-ibtn" href="' . e(edu_file_url_desk($f)) . '" target="_blank" rel="noopener" title="Open">' . edu_icon('eye') . '</a>' : '')
         . '<a class="eb-ibtn" href="' . e(edu_file_url_desk($f, true)) . '" title="Download">' . edu_icon('download') . '</a>'
         . ($edit ? '<form method="post" data-file-del>' . fabo_csrf_field() . '<input type="hidden" name="a" value="file-delete"><input type="hidden" name="id" value="' . (int) $f['id'] . '">'
                  . '<button class="eb-ibtn danger" title="Remove" aria-label="Remove ' . e($f['original_name']) . '">' . edu_icon('trash') . '</button></form>' : '') . '</li>';
}

/** The drop zone and file list for a lesson. */
function edu_file_zone(int $org, array $l, bool $main): string
{
    $files = edu_lesson_files($org, (int) $l['id']);
    $edit = edu_can_edit();
    $h = '<div class="eb-files' . ($main ? ' main' : '') . '"><ul class="eb-filelist" data-filelist>' . implode('', array_map(static fn($f) => edu_file_card_desk($f, $edit), $files)) . '</ul>';
    if ($edit) {
        $h .= '<label class="eb-drop" data-drop data-lesson="' . (int) $l['id'] . '" data-chunk="' . (int) min(fabo_chunk_bytes(), 8 * 1048576) . '" data-url="' . e(edu_url('course', ['id' => (int) $l['course_id']])) . '">'
            . '<input type="file" multiple accept=".pdf,.ppt,.pptx,.doc,.docx,.xls,.xlsx,.csv,.txt,.zip,.mp4,.mov,.jpg,.jpeg,.png,.gif,.webp" hidden>'
            . '<span class="eb-drop-ico">' . edu_icon('upload') . '</span><strong>' . ($main ? 'Drop your PDF, slides or document here' : 'Add resources for this lesson') . '</strong>'
            . '<em>or click to choose · PDF, PowerPoint, Word, Excel, images, MP4, ZIP · large files are sent in pieces</em></label>';
    } elseif (!$files) {
        $h .= '<p class="muted">No files yet.</p>';
    }
    return $h . '</div>';
}

/* ================================================================ gallery */

function edu_page_courses(int $org): void
{
    $courses = edu_courses($org);
    $edit = edu_can_edit();
    $counts = ['all' => count($courses), 'published' => 0, 'draft' => 0, 'archived' => 0];
    foreach ($courses as $c) {
        $counts[$c['status']]++;
    }
    echo '<div class="eg-bar"><div class="eg-search">' . edu_icon('search') . '<input type="search" placeholder="Search courses" aria-label="Search courses" data-eg-search></div><nav class="eg-chips" role="tablist">';
    foreach (['all' => 'All', 'published' => 'Published', 'draft' => 'Drafts', 'archived' => 'Archived'] as $k => $l) {
        echo '<button type="button" class="' . ($k === 'all' ? 'on' : '') . '" data-eg-filter="' . $k . '">' . $l . ' <b>' . $counts[$k] . '</b></button>';
    }
    echo '</nav><span class="spacer"></span>' . ($edit ? '<button type="button" class="btn btn-primary eb-glow" data-dialog="ebNewCourse">' . edu_icon('plus') . ' New course</button>' : '') . '</div>';
    if (!$courses) {
        echo '<section class="eg-empty"><div class="eg-empty-art" aria-hidden="true"><span></span><span></span><span></span></div><h2>Build your first course</h2>'
           . '<p class="muted">Lessons you write, videos, slides and PDFs, quizzes with a pass mark, and a certificate at the end.</p>';
        if ($edit) {
            echo '<div class="eg-empty-acts"><button type="button" class="btn btn-primary" data-dialog="ebNewCourse">' . edu_icon('plus') . ' New course</button>'
               . '<form method="post">' . fabo_csrf_field() . '<input type="hidden" name="a" value="sample-course"><button class="btn">Add a ready-made sample course</button></form></div>';
        }
        echo '</section>';
    }
    echo '<div class="eg-grid" data-eg-grid>';
    foreach ($courses as $i => $c) {
        $cover = edu_course_cover($org, (int) $c['id']);
        [, $ready] = edu_course_checklist($org, $c);
        $rate = (int) $c['enrolled'] ? (int) round($c['completed'] * 100 / $c['enrolled']) : 0;
        echo '<a class="eg-card ' . edu_colour((int) $c['colour']) . '" style="--i:' . $i . '" href="' . e(edu_url('course', ['id' => (int) $c['id']])) . '" data-status="' . e($c['status']) . '" data-text="' . e(mb_strtolower($c['title'] . ' ' . $c['category'] . ' ' . $c['summary'])) . '">'
           . '<span class="eg-cover">' . ($cover ? '<img src="' . e(edu_file_url_desk($cover)) . '" alt="" loading="lazy">' : '<span class="eg-pattern" aria-hidden="true"></span>')
           . '<span class="eg-cat">' . e($c['category'] ?: 'Course') . '</span>' . edu_status_pill($c['status']) . '</span>'
           . '<span class="eg-body"><strong>' . e($c['title']) . '</strong><span class="eg-sum">' . e((string) $c['summary']) . '</span>'
           . '<span class="eg-meta"><span>' . edu_icon('layers') . (int) $c['lessons'] . ((int) $c['lessons'] === 1 ? ' lesson' : ' lessons') . '</span><span>' . edu_icon('clock') . (int) $c['minutes_total'] . ' min</span><span>' . edu_icon('users') . (int) $c['enrolled'] . '</span></span>'
           . ($c['status'] === 'draft' ? '<span class="eg-ready"><span class="eg-ready-bar"><i style="width:' . $ready . '%"></i></span><em>' . $ready . '% ready</em></span>'
                                       : '<span class="eg-ready"><span class="eg-ready-bar done"><i style="width:' . $rate . '%"></i></span><em>' . $rate . '% finish</em></span>')
           . '</span></a>';
    }
    echo '</div><p class="edu-empty" data-eg-none hidden>No course matches.</p>';
    if ($edit) {
        echo '<dialog class="eb-dialog" id="ebNewCourse"><form method="post" class="edu-form">' . fabo_csrf_field() . '<input type="hidden" name="a" value="course-new">'
           . '<header><span class="eb-dlg-ico">' . edu_icon('layers') . '</span><div><h2>New course</h2><p class="muted">You can change all of this later.</p></div></header>'
           . '<label class="field"><span>Title</span><input name="title" required maxlength="190" placeholder="e.g. Design Thinking for Founders" autofocus></label>'
           . '<label class="field"><span>One-line summary</span><input name="summary" maxlength="300" placeholder="What students get out of it"></label>'
           . '<div class="edu-fields"><label class="field"><span>Category</span><input name="category" maxlength="80" list="ebCats" placeholder="Entrepreneurship"></label>'
           . '<label class="field"><span>Level</span><select name="level"><option>Beginner</option><option>Intermediate</option><option>Advanced</option></select></label></div>'
           . '<datalist id="ebCats"><option>Entrepreneurship</option><option>Design</option><option>Coding</option><option>Product</option><option>Marketing</option><option>Finance</option><option>Soft skills</option></datalist>'
           . edu_swatches(1) . '<footer><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Create course</button></footer></form></dialog>';
    }
}

function edu_swatches(int $sel): string
{
    $h = '<fieldset class="field edu-colours"><span>Colour</span><div>';
    for ($i = 1; $i <= 8; $i++) {
        $h .= '<label class="' . edu_colour($i) . '"><input type="radio" name="colour" value="' . $i . '"' . ($sel === $i ? ' checked' : '') . '><i></i></label>';
    }
    return $h . '</div></fieldset>';
}

/* ================================================================ builder */

function edu_page_lesson(int $org): void
{
    $l = edu_lesson($org, (int) ($_GET['id'] ?? 0));
    $_GET = ['p' => 'course', 'id' => (int) ($l['course_id'] ?? 0), 'lesson' => (int) ($l['id'] ?? 0)] + (isset($_GET['org']) ? ['org' => $_GET['org']] : []);
    edu_page_course($org);
}

function edu_page_course(int $org): void
{
    $id = (int) ($_GET['id'] ?? 0);
    $c = $id ? edu_course($org, $id) : null;
    if (!$c) {
        echo '<p class="edu-empty">That course is not here. <a href="' . e(edu_url('courses')) . '">All courses</a></p>';
        return;
    }
    $tab = in_array($_GET['tab'] ?? '', ['details', 'results'], true) ? $_GET['tab'] : 'curriculum';
    $lessons = edu_lessons($org, $id);
    $edit = edu_can_edit();
    [$check, $ready] = edu_course_checklist($org, $c);
    $blockers = array_filter($check, static fn($i) => $i[3] && !$i[1]);
    $cover = edu_course_cover($org, $id);
    $enrolled = (int) (fabo_one('SELECT COUNT(*) AS n FROM edu_enrolments WHERE course_id = ?', 'i', [$id])['n'] ?? 0);
    $mins = array_sum(array_column($lessons, 'minutes'));

    echo '<div class="eb">';
    echo '<header class="eb-hero ' . edu_colour((int) $c['colour']) . ($cover ? ' has-cover' : '') . '">' . ($cover ? '<img class="eb-hero-img" src="' . e(edu_file_url_desk($cover)) . '" alt="">' : '<span class="eg-pattern" aria-hidden="true"></span>')
       . '<div class="eb-hero-in"><a class="eb-crumb" href="' . e(edu_url('courses')) . '">← All courses</a><div class="eb-chips"><span>' . e($c['category'] ?: 'Course') . '</span><span>' . e($c['level']) . '</span>' . edu_status_pill($c['status']) . '</div>'
       . '<h1>' . e($c['title']) . '</h1>' . ($c['summary'] ? '<p>' . e((string) $c['summary']) . '</p>' : '')
       . '<div class="eb-stats"><span>' . edu_icon('layers') . count($lessons) . (count($lessons) === 1 ? ' lesson' : ' lessons') . '</span><span>' . edu_icon('clock') . $mins . ' min</span><span>' . edu_icon('users') . $enrolled . ' enrolled</span><span>' . e(edu_course_audience_words($org, $c)) . '</span></div></div>'
       . '<div class="eb-hero-side"><div class="eb-ring" style="--p:' . $ready . '"><b>' . $ready . '%</b><em>ready</em></div>';
    if ($edit) {
        echo '<div class="eb-hero-acts">';
        if ($c['status'] === 'published') {
            echo '<form method="post">' . fabo_csrf_field() . '<input type="hidden" name="a" value="course-status"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="status" value="draft"><button class="btn eb-ghost">Unpublish</button></form>'
               . '<form method="post">' . fabo_csrf_field() . '<input type="hidden" name="a" value="course-status"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="status" value="archived"><button class="btn eb-ghost">Archive</button></form>';
        } else {
            echo '<form method="post">' . fabo_csrf_field() . '<input type="hidden" name="a" value="course-status"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="status" value="published">'
               . '<button class="btn eb-publish"' . ($blockers ? ' disabled title="' . e(implode(' · ', array_map(static fn($b) => $b[0] . ($b[2] !== '' ? ': ' . $b[2] : ''), $blockers))) . '"' : '') . '>' . edu_icon('check') . ' Publish</button></form>';
        }
        echo '</div>';
    }
    echo '</div></header>';

    echo '<nav class="eb-tabs" role="tablist">';
    foreach (['curriculum' => 'Curriculum <b>' . count($lessons) . '</b>', 'details' => 'Details', 'results' => 'Students &amp; results <b>' . $enrolled . '</b>'] as $k => $l) {
        echo '<a role="tab" class="' . ($tab === $k ? 'on' : '') . '" aria-selected="' . ($tab === $k ? 'true' : 'false') . '" href="' . e(edu_url('course', ['id' => $id] + ($k === 'curriculum' ? [] : ['tab' => $k]))) . '">' . $l . '</a>';
    }
    echo '<span class="eb-tabline" aria-hidden="true"></span></nav>';

    if ($tab === 'results') {
        edu_course_results_page($org, $c);
    } elseif ($tab === 'details') {
        edu_builder_details($org, $c, $cover);
    } else {
        edu_builder_curriculum($org, $c, $lessons, $check, $ready);
    }
    echo '</div>';
}

function edu_builder_curriculum(int $org, array $c, array $lessons, array $check, int $ready): void
{
    $id = (int) $c['id'];
    $edit = edu_can_edit();
    $sel = (int) ($_GET['lesson'] ?? 0);
    $cur = null;
    foreach ($lessons as $l) {
        if ((int) $l['id'] === $sel) {
            $cur = $l;
        }
    }
    $files = edu_file_counts($org, $id);
    $bsum = edu_block_summary($org, $id);
    $sections = array_values(array_unique(array_filter(array_map(static fn($l) => (string) $l['section'], $lessons))));
    echo '<div class="eb-grid"><aside class="eb-outline"><div class="eb-outline-h"><h2>Curriculum</h2><span class="muted">' . count($lessons) . (count($lessons) === 1 ? ' lesson' : ' lessons') . '</span></div>';
    $n = 0;
    foreach (edu_lesson_groups($lessons) as [$sec, $group]) {
        $gm = array_sum(array_column($group, 'minutes'));
        echo '<div class="eb-sec" data-section="' . e($sec) . '"><div class="eb-sec-h">' . ($sec !== '' ? '<strong>' . e($sec) . '</strong>' : '<strong class="muted">No section</strong>')
           . '<span>' . count($group) . ' · ' . $gm . ' min</span>';
        if ($edit && $sec !== '') {
            echo '<details class="eb-secedit"><summary title="Rename section">Rename</summary><form method="post">' . fabo_csrf_field() . '<input type="hidden" name="a" value="section-rename"><input type="hidden" name="course_id" value="' . $id . '">'
               . '<input type="hidden" name="from" value="' . e($sec) . '"><input name="to" value="' . e($sec) . '" maxlength="120" aria-label="New section name"><button class="btn btn-sm">Save</button></form></details>';
        }
        echo '</div><ol class="eb-list" data-sortable data-course="' . $id . '">';
        foreach ($group as $l) {
            $n++;
            $bw = edu_block_words($bsum[(int) $l['id']] ?? []);
            $badge = trim(($l['kind'] === 'quiz' ? count(edu_questions($org, (int) $l['id'])) . ' Q' : '') . ($bw !== '' ? ($l['kind'] === 'quiz' ? ' · ' : '') . $bw : (!empty($files[(int) $l['id']]) ? $files[(int) $l['id']] . ' file' . ($files[(int) $l['id']] === 1 ? '' : 's') : '')));
            $warn = edu_lesson_problem($org, $l) !== null;
            echo '<li class="eb-item k-' . e($l['kind']) . ((int) $l['id'] === $sel ? ' on' : '') . '" data-id="' . (int) $l['id'] . '"' . ($edit ? ' draggable="true"' : '') . '>'
               . ($edit ? '<span class="eb-handle" title="Drag to reorder">' . edu_icon('drag') . '</span>' : '')
               . '<a href="' . e(edu_url('course', ['id' => $id, 'lesson' => (int) $l['id']])) . '"><span class="eb-kico">' . edu_icon($l['kind']) . '</span>'
               . '<span class="eb-ititle"><strong><i>' . $n . '.</i> ' . e($l['title']) . '</strong><em>' . e(edu_lesson_kinds()[$l['kind']]) . ' · ' . (int) $l['minutes'] . ' min' . ($badge !== '' ? ' · ' . $badge : '') . '</em></span>'
               . ($warn ? '<span class="eb-warn" title="Needs attention before publishing">!</span>' : '') . '</a></li>';
        }
        echo '</ol></div>';
    }
    if (!$lessons) {
        echo '<p class="eb-outline-empty">No lessons yet. Pick a type below to add the first one.</p>';
    }
    if ($edit) {
        echo '<div class="eb-add"><span>Add a lesson</span><div class="eb-add-grid">';
        foreach (edu_lesson_kinds() as $k => $label) {
            echo '<button type="button" class="eb-add-btn k-' . $k . '" data-dialog="ebAddLesson" data-kind="' . $k . '">' . edu_icon($k) . '<b>' . e($label) . '</b></button>';
        }
        echo '</div></div>';
    }
    echo '</aside><section class="eb-editor">';
    if ($cur) {
        edu_builder_lesson($org, $c, $cur, $sections);
    } else {
        edu_builder_checklist($org, $c, $check, $ready, (bool) $lessons);
    }
    echo '</section></div>';
    if ($edit) {
        echo '<dialog class="eb-dialog" id="ebAddLesson"><form method="post" class="edu-form">' . fabo_csrf_field() . '<input type="hidden" name="a" value="lesson-add"><input type="hidden" name="course_id" value="' . $id . '">'
           . '<header><span class="eb-dlg-ico" data-kind-ico>' . edu_icon('text') . '</span><div><h2>New lesson</h2><p class="muted" data-kind-help>Choose what kind of lesson it is.</p></div></header>'
           . '<div class="eb-kinds" role="radiogroup" aria-label="Lesson type">';
        foreach (edu_lesson_kinds() as $k => $label) {
            echo '<label class="k-' . $k . '"><input type="radio" name="kind" value="' . $k . '"' . ($k === 'text' ? ' checked' : '') . '>' . edu_icon($k) . '<b>' . e($label) . '</b></label>';
        }
        echo '</div><label class="field"><span>Title</span><input name="title" required maxlength="190" placeholder="e.g. What makes a good startup idea"></label>'
           . '<div class="edu-fields"><label class="field"><span>Section</span><input name="section" maxlength="120" list="ebSections" value="' . e((string) (end($lessons)['section'] ?? '')) . '" placeholder="e.g. Week 1 · Ideas"></label>'
           . '<label class="field"><span>Minutes</span><input type="number" name="minutes" min="1" max="600" value="10"></label></div>'
           . '<datalist id="ebSections">' . implode('', array_map(static fn($s) => '<option value="' . e($s) . '">', $sections)) . '</datalist>'
           . '<footer><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Add lesson</button></footer></form></dialog>';
    }
}

function edu_builder_checklist(int $org, array $c, array $check, int $ready, bool $hasLessons): void
{
    echo '<div class="eb-pane eb-welcome"><div class="eb-ring big" style="--p:' . $ready . '"><b>' . $ready . '%</b><em>ready</em></div><h2>' . ($hasLessons ? 'Pick a lesson to edit it' : 'Start with a lesson') . '</h2>'
       . '<p class="muted">' . ($hasLessons ? 'Drag lessons to reorder them, or between sections.' : 'Reading, a video, a PDF or slides, a link, or a quiz.') . '</p><ul class="eb-check">';
    foreach ($check as [$label, $done, $hint, $req]) {
        echo '<li class="' . ($done ? 'done' : ($req ? 'todo req' : 'todo')) . '"><span class="eb-tick">' . ($done ? edu_icon('check') : '') . '</span><span><b>' . e($label) . ($req ? '' : ' <em>optional</em>') . '</b>'
           . ($done || $hint === '' ? '' : '<small>' . e($hint) . '</small>') . '</span></li>';
    }
    echo '</ul></div>';
}

function edu_builder_quiz(int $org, array $c, array $l): void
{
    $edit = edu_can_edit();
    $qs = edu_questions($org, (int) $l['id']);
    echo '<section class="eb-block"><div class="eb-block-h"><h3>Questions <span class="muted">' . count($qs) . '</span></h3><span class="muted">Pass mark ' . (int) $c['pass_pct'] . '% · change it on Details</span></div><ol class="eb-qs">';
    foreach ($qs as $n => $q) {
        echo '<li class="eb-q">' . edu_question_card((int) $l['id'], $q, $n + 1, $edit) . '</li>';
    }
    echo '</ol>';
    if ($edit) {
        echo '<details class="eb-q new"' . ($qs ? '' : ' open') . '><summary class="btn">' . edu_icon('plus') . ' Add a question</summary>' . edu_question_card((int) $l['id'], null, count($qs) + 1, true) . '</details>';
    } elseif (!$qs) {
        echo '<p class="muted">No questions yet.</p>';
    }
    echo '</section>';
}

function edu_question_card(int $lessonId, ?array $q, int $n, bool $edit): string
{
    $opts = $q['options'] ?? ['', ''];
    $dis = $edit ? '' : ' disabled';
    $h = '<form method="post" class="eb-qform" data-qform>' . fabo_csrf_field() . '<input type="hidden" name="a" value="question-save"><input type="hidden" name="id" value="' . $lessonId . '">' . ($q ? '<input type="hidden" name="qid" value="' . (int) $q['id'] . '">' : '')
       . '<div class="eb-qhead"><span class="eb-qn">' . $n . '</span><textarea name="question" rows="2" required placeholder="Write the question"' . $dis . '>' . e((string) ($q['question'] ?? '')) . '</textarea></div>'
       . '<div class="eb-opts" data-opts>';
    foreach ($opts as $i => $o) {
        $h .= '<label class="eb-opt"><input type="radio" name="correct" value="' . $i . '"' . ($q && (int) $q['correct'] === $i ? ' checked' : '') . ' required' . $dis . ' aria-label="Mark as the right answer">'
            . '<input name="options[]" maxlength="300" value="' . e($o) . '" placeholder="Answer ' . ($i + 1) . '"' . $dis . '>' . ($edit ? '<button type="button" class="eb-ibtn" data-opt-del title="Remove this answer">×</button>' : '') . '</label>';
    }
    $h .= '</div>' . ($edit ? '<button type="button" class="eb-addopt" data-opt-add>' . edu_icon('plus') . ' Add an answer</button>' : '')
        . '<label class="field"><span>Why it is right (shown after they answer)</span><input name="explain" maxlength="2000" value="' . e((string) ($q['explain_text'] ?? '')) . '"' . $dis . '></label>';
    if ($edit) {
        $h .= '<div class="eb-qacts"><span class="muted">Tick the circle beside the right answer.</span><span class="spacer"></span>' . '<button class="btn btn-primary btn-sm">' . ($q ? 'Save question' : 'Add question') . '</button></div>';
    }
    $h .= '</form>';
    if ($edit && $q) {
        $h .= '<form method="post" class="eb-qdel" data-confirm="Remove this question?">' . fabo_csrf_field() . '<input type="hidden" name="a" value="question-delete"><input type="hidden" name="id" value="' . $lessonId . '"><input type="hidden" name="qid" value="' . (int) $q['id'] . '">'
            . '<button class="eb-ibtn danger" title="Remove question">' . edu_icon('trash') . '</button></form>';
    }
    return $h;
}

function edu_builder_details(int $org, array $c, ?array $cover): void
{
    $edit = edu_can_edit();
    $dis = $edit ? '' : ' disabled';
    $v = static fn(string $k, $d = '') => e((string) ($c[$k] ?? $d));
    $years = $c['years'] !== '' ? array_map('intval', explode(',', $c['years'])) : [];
    echo '<form method="post" enctype="multipart/form-data" class="edu-form eb-details">' . fabo_csrf_field() . '<input type="hidden" name="a" value="course-save"><input type="hidden" name="id" value="' . (int) $c['id'] . '">'
       . '<div class="eb-dgrid"><div class="eb-dmain"><section class="eb-card"><h3>About the course</h3>'
       . '<label class="field"><span>Title</span><input name="title" required maxlength="190" value="' . $v('title') . '"' . $dis . '></label>'
       . '<label class="field"><span>Summary (on the course card)</span><textarea name="summary" rows="3" maxlength="4000"' . $dis . '>' . $v('summary') . '</textarea></label>'
       . '<label class="field"><span>What students will learn <em class="muted">— one per line</em></span><textarea name="outcomes" rows="5" placeholder="Test an idea with real users&#10;Write a one-page pitch"' . $dis . '>' . $v('outcomes') . '</textarea></label>'
       . '<label class="field"><span>Before they start <em class="muted">— one per line, optional</em></span><textarea name="prerequisites" rows="3"' . $dis . '>' . $v('prerequisites') . '</textarea></label>'
       . '<div class="edu-fields"><label class="field"><span>Category</span><input name="category" maxlength="80" value="' . $v('category') . '"' . $dis . '></label><label class="field"><span>Level</span><select name="level"' . $dis . '>';
    foreach (['Beginner', 'Intermediate', 'Advanced'] as $l) {
        echo '<option' . ($c['level'] === $l ? ' selected' : '') . '>' . $l . '</option>';
    }
    echo '</select></label><label class="field"><span>Quiz pass mark (%)</span><input type="number" name="pass_pct" min="0" max="100" value="' . $v('pass_pct', 60) . '"' . $dis . '></label></div></section>'
       . '<section class="eb-card"><h3>Who it is for</h3><div class="edu-fields"><label class="field"><span>Offered to</span><select name="audience"' . $dis . '>';
    foreach (['all' => 'Every student in the organisation', 'institution' => 'One institution', 'department' => 'One department'] as $k => $l) {
        echo '<option value="' . $k . '"' . ($c['audience'] === $k ? ' selected' : '') . '>' . $l . '</option>';
    }
    echo '</select></label>' . edu_inst_dept_fields($org, (int) $c['institution_id'], (int) $c['department_id']) . '</div><div class="field"><span>Years of study (none ticked: every year)</span><div class="edu-years">';
    for ($y = 1; $y <= 6; $y++) {
        echo '<label><input type="checkbox" name="years[]" value="' . $y . '"' . (in_array($y, $years, true) ? ' checked' : '') . $dis . '> Year ' . $y . '</label>';
    }
    echo '</div></div></section></div><aside class="eb-dside"><section class="eb-card"><h3>Cover</h3><label class="eb-cover-drop ' . edu_colour((int) $c['colour']) . '" data-cover-drop>'
       . ($cover ? '<img src="' . e(edu_file_url_desk($cover)) . '" alt="" data-cover-img>' : '<span class="eg-pattern" aria-hidden="true"></span><img alt="" data-cover-img hidden>')
       . '<span class="eb-cover-hint">' . edu_icon('image') . ($cover ? 'Change image' : 'Add a cover image') . '</span>'
       . '<input type="file" name="cover" accept=".jpg,.jpeg,.png,.webp" hidden' . $dis . '></label>'
       . ($cover && $edit ? '<label class="edu-check"><input type="checkbox" name="cover_remove" value="1"> Remove the image</label>' : '') . '<p class="muted">JPG, PNG or WebP, about 1600 × 900. Without one, the colour below is used.</p>'
       . edu_swatches((int) $c['colour']) . '</section>';
    if ($edit) {
        echo '<button class="btn btn-primary eb-wide">Save details</button>';
    }
    echo '</aside></div></form>';
    if ($edit) {
        echo '<details class="edu-card edu-danger"><summary>Remove this course</summary><form method="post" class="edu-inline">' . fabo_csrf_field()
           . '<input type="hidden" name="a" value="course-delete"><input type="hidden" name="id" value="' . (int) $c['id'] . '"><p class="muted">Removes the course, its lessons and files, and every student\'s progress and certificates on it. Archive it instead to keep certificates valid. Type DELETE to confirm.</p>'
           . '<input name="confirm" placeholder="DELETE" aria-label="Type DELETE" autocomplete="off"><button class="btn btn-danger">Remove course</button></form></details>';
    }
}

function edu_course_results_page(int $org, array $c): void
{
    $rows = edu_course_results($org, (int) $c['id']);
    $done = count(array_filter($rows, static fn($r) => $r['completed_at'] !== null));
    $avg = $rows ? round(array_sum(array_column($rows, 'progress')) / count($rows)) : 0;
    echo '<section class="edu-kpis four">' . edu_kpi('Enrolled', count($rows), 't-student') . edu_kpi('Finished', $done, 't-faculty')
       . edu_kpi('Finish rate', ($rows ? round($done * 100 / count($rows)) : 0) . '%', 't-mentor') . edu_kpi('Average progress', $avg . '%', 't-acc') . '</section>'
       . '<section class="edu-card"><div class="edu-tablewrap"><table class="edu-table"><thead><tr><th>Student</th><th>Institution</th><th>Progress</th><th class="n">Quiz average</th><th>Enrolled</th><th>Finished</th><th>Certificate</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr><td><a href="' . e(edu_url('person', ['id' => (int) $r['person_id']])) . '"><strong>' . e($r['name']) . '</strong></a><br><span class="muted">' . e($r['roll_no'] ?: (string) $r['email']) . '</span></td>'
           . '<td class="muted">' . e((string) $r['inst_names']) . '</td><td><span class="edu-bar"><i style="width:' . (int) $r['progress'] . '%"></i></span> <span class="muted">' . (int) $r['progress'] . '%</span></td>'
           . '<td class="n">' . ($r['quiz_avg'] !== null ? (int) $r['quiz_avg'] . '%' : '—') . '</td><td>' . e(fabo_short_date($r['enrolled_at'])) . '</td>'
           . '<td>' . ($r['completed_at'] ? e(fabo_short_date($r['completed_at'])) : '') . '</td><td><code>' . e((string) $r['cert_code']) . '</code></td></tr>';
    }
    if (!$rows) {
        echo '<tr><td colspan="7" class="edu-empty">Nobody has enrolled yet.' . ($c['status'] !== 'published' ? ' Publish the course so students can see it.' : '') . '</td></tr>';
    }
    echo '</tbody></table></div></section>';
}

function edu_page_mentoring(int $org): void
{
    $t = edu_mentoring_totals($org);
    $f = ['mentor' => (int) ($_GET['mentor'] ?? 0), 'status' => (string) ($_GET['status'] ?? ''), 'when' => ($_GET['when'] ?? '') === 'past' ? 'past' : 'upcoming'];
    echo '<section class="edu-kpis">' . edu_kpi('Open slots', (int) $t['open_slots'], 't-faculty') . edu_kpi('Booked, upcoming', (int) $t['booked'], 't-student')
       . edu_kpi('Held, last 30 days', (int) $t['held_30'], 't-mentor') . edu_kpi('Missed, last 30 days', (int) $t['missed_30'], 't-acc')
       . edu_kpi('Average rating', $t['ratings'] ? $t['rating'] . ' ★' : '—', 't-inst') . edu_kpi('Ratings', (int) $t['ratings'], 't-recent') . '</section>';
    $mentors = edu_people($org, ['role' => 'mentor', 'limit' => 500]);
    echo '<form method="get" class="edu-filters">' . (!empty($GLOBALS['edu_viewer']['is_admin']) ? '<input type="hidden" name="org" value="' . $org . '">' : '')
       . '<input type="hidden" name="p" value="mentoring"><select name="when"><option value="upcoming">Upcoming</option><option value="past"' . ($f['when'] === 'past' ? ' selected' : '') . '>Past</option></select>'
       . '<select name="mentor"><option value="0">Every mentor</option>';
    foreach ($mentors as $m) {
        echo '<option value="' . (int) $m['id'] . '"' . ($f['mentor'] === (int) $m['id'] ? ' selected' : '') . '>' . e($m['name']) . '</option>';
    }
    echo '</select><select name="status"><option value="">Every status</option>';
    foreach (edu_slot_statuses() as $k => $l) {
        echo '<option value="' . $k . '"' . ($f['status'] === $k ? ' selected' : '') . '>' . $l . '</option>';
    }
    echo '</select><button class="btn">Show</button></form>';
    $rows = edu_org_sessions($org, $f);
    echo '<section class="edu-card"><div class="edu-tablewrap"><table class="edu-table"><thead><tr><th>When</th><th>Mentor</th><th>Student</th><th>Topic</th><th>Mode</th><th>Status</th><th>Rating</th></tr></thead><tbody>';
    foreach ($rows as $s) {
        echo '<tr><td>' . e(edu_slot_when($s)) . '</td><td><a href="' . e(edu_url('person', ['id' => (int) $s['mentor_id']])) . '">' . e($s['mentor_name']) . '</a></td>'
           . '<td>' . ($s['student_id'] ? '<a href="' . e(edu_url('person', ['id' => (int) $s['student_id']])) . '">' . e((string) $s['student_name']) . '</a>' : '') . '</td>'
           . '<td>' . e((string) $s['topic']) . ($s['mentor_notes'] ? '<br><span class="muted">Notes: ' . e(mb_strimwidth((string) $s['mentor_notes'], 0, 140, '…')) . '</span>' : '') . '</td>'
           . '<td>' . ($s['mode'] === 'online' ? 'Online' : 'In person') . '</td><td>' . e(edu_slot_statuses()[$s['status']]) . '</td>'
           . '<td>' . ($s['rating'] ? str_repeat('★', (int) $s['rating']) . ($s['feedback'] ? '<br><span class="muted">' . e(mb_strimwidth((string) $s['feedback'], 0, 120, '…')) . '</span>' : '') : '') . '</td></tr>';
    }
    if (!$rows) {
        echo '<tr><td colspan="7" class="edu-empty">No sessions here. Mentors offer times from their Campus portal.</td></tr>';
    }
    echo '</tbody></table></div></section>';
}

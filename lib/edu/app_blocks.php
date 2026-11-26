<?php
/**
 * Academic desk — the lesson editor as a stack of content blocks.
 * Loaded by lib/edu/app_lms.php. Each block is its own small form (it
 * works without JavaScript); assets/academic-builder.js saves them as you
 * type, adds blocks in place, uploads documents into the stack, and lets
 * blocks be dragged into a new order.
 */

require_once __DIR__ . '/blocks.php';

function edu_is_ajax(): bool
{
    return !empty($_POST['ajax']) || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
}

/** Block actions. JSON for the page, a redirect without JavaScript. */
function edu_post_blocks(int $org, string $a): void
{
    if (!in_array($a, ['block-add', 'block-save', 'block-delete', 'block-order'], true)) {
        return;
    }
    $ajax = edu_is_ajax();
    $lessonId = 0;
    try {
        if ($a === 'block-add') {
            $lessonId = (int) ($_POST['lesson'] ?? 0);
            $bid = edu_block_add($org, $lessonId, (string) ($_POST['type'] ?? ''), (int) ($_POST['after'] ?? 0));
            if ($ajax) {
                edu_json(['ok' => true, 'id' => $bid, 'html' => edu_block_card($org, edu_block($org, $bid), true)]);
            }
        } elseif ($a === 'block-save') {
            $b = edu_block($org, (int) ($_POST['id'] ?? 0));
            if (!$b) {
                throw new InvalidArgumentException('That block is not here.');
            }
            $lessonId = (int) $b['lesson_id'];
            edu_block_save($org, (int) $b['id'], $_POST);
            if ($ajax) {
                $b = edu_block($org, (int) $b['id']);
                edu_json(['ok' => true, 'embed' => $b['type'] === 'video' ? (string) edu_video_embed((string) $b['url']) : null]);
            }
            edu_flash('ok', 'Saved.');
        } elseif ($a === 'block-delete') {
            $lessonId = edu_block_delete($org, (int) ($_POST['id'] ?? 0));
            if ($ajax) {
                edu_json(['ok' => true]);
            }
            edu_flash('ok', 'Removed.');
        } else {
            $lessonId = (int) ($_POST['lesson'] ?? 0);
            if (!edu_lesson($org, $lessonId)) {
                throw new InvalidArgumentException('That lesson is not here.');
            }
            edu_blocks_reorder($org, $lessonId, (array) ($_POST['ids'] ?? []));
            if ($ajax) {
                edu_json(['ok' => true]);
            }
        }
    } catch (InvalidArgumentException $e) {
        if ($ajax) {
            edu_json(['ok' => false, 'error' => $e->getMessage()], 400);
        }
        edu_flash('err', $e->getMessage());
    }
    $l = edu_lesson($org, $lessonId);
    edu_go('course', ['id' => (int) ($l['course_id'] ?? 0), 'lesson' => $lessonId]);
}

/** The "add" menu: one button per block type, each a tiny form (Document picks files). */
function edu_block_adders(int $lessonId, int $after, bool $bar = false): string
{
    $h = '<div class="' . ($bar ? 'ebk-addbar' : 'ebk-menu') . '">';
    foreach (edu_block_types() as $t => $label) {
        $ico = edu_icon(['text' => 'text', 'video' => 'video', 'file' => 'document', 'link' => 'link', 'callout' => 'quiz'][$t]);
        if ($t === 'callout') {
            $ico = '<svg class="ei" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3a6 6 0 0 0-4 10.5V17h8v-3.5A6 6 0 0 0 12 3z"/><path d="M9 21h6"/></svg>';
        }
        if ($t === 'file') {
            $h .= '<button type="button" class="ebk-addbtn t-file" data-pick-file data-after="' . $after . '">' . $ico . '<b>' . $label . '</b></button>';
            continue;
        }
        $h .= '<form method="post" data-block-add>' . fabo_csrf_field() . '<input type="hidden" name="a" value="block-add"><input type="hidden" name="lesson" value="' . $lessonId . '">'
            . '<input type="hidden" name="type" value="' . $t . '"><input type="hidden" name="after" value="' . $after . '"><button class="ebk-addbtn t-' . $t . '">' . $ico . '<b>' . $label . '</b></button></form>';
    }
    return $h . '</div>';
}

/** One block in the editor. */
function edu_block_card(int $org, array $b, bool $edit): string
{
    $id = (int) $b['id'];
    $t = $b['type'];
    $dis = $edit ? '' : ' disabled';
    $v = static fn(string $k) => e((string) ($b[$k] ?? ''));
    $icon = ['text' => edu_icon('text'), 'video' => edu_icon('video'), 'file' => edu_icon('document'), 'link' => edu_icon('link'),
             'callout' => '<svg class="ei" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3a6 6 0 0 0-4 10.5V17h8v-3.5A6 6 0 0 0 12 3z"/><path d="M9 21h6"/></svg>'][$t];
    $h = '<li class="ebk t-' . e($t) . '" data-block="' . $id . '"' . ($edit ? ' draggable="true"' : '') . '><div class="ebk-bar">'
       . ($edit ? '<span class="eb-handle" title="Drag to move">' . edu_icon('drag') . '</span>' : '') . '<span class="ebk-ico">' . $icon . '</span><b>' . e(edu_block_types()[$t]) . '</b>'
       . '<span class="ebk-state" data-state aria-live="polite"></span><span class="spacer"></span>';
    if ($edit) {
        $h .= '<button type="button" class="eb-ibtn" data-bmove="-1" title="Move up">↑</button><button type="button" class="eb-ibtn" data-bmove="1" title="Move down">↓</button>'
            . '<form method="post" data-block-del>' . fabo_csrf_field() . '<input type="hidden" name="a" value="block-delete"><input type="hidden" name="id" value="' . $id . '">'
            . '<button class="eb-ibtn danger" title="Remove this block" aria-label="Remove this block">' . edu_icon('trash') . '</button></form>';
    }
    $h .= '</div><form method="post" class="ebk-form" data-block-form>' . fabo_csrf_field() . '<input type="hidden" name="a" value="block-save"><input type="hidden" name="id" value="' . $id . '">';
    $tools = $edit ? '<div class="eb-tools" role="toolbar" aria-label="Formatting"><button type="button" data-md="h" title="Heading">H</button><button type="button" data-md="b" title="Bold"><b>B</b></button>'
        . '<button type="button" data-md="i" title="Italic"><i>I</i></button><button type="button" data-md="ul" title="Bulleted list">•</button><button type="button" data-md="ol" title="Numbered list">1.</button>'
        . '<button type="button" data-md="link" title="Link">↗</button><button type="button" data-md="code" title="Code">&lt;/&gt;</button><span class="ebk-tabs"><button type="button" class="on" data-md-tab="write">Write</button><button type="button" data-md-tab="preview">Preview</button></span></div>' : '';
    switch ($t) {
        case 'text':
            $h .= $tools . '<textarea name="body" rows="7" data-md-src placeholder="Write here. # for a heading, - for a list, **bold**, [words](https://…)"' . $dis . '>' . $v('body') . '</textarea>'
                . '<div class="ebk-prev cp-lesson-body" data-md-prev hidden></div>';
            break;
        case 'callout':
            $h .= '<div class="ebk-row"><select name="style" aria-label="Kind of callout"' . $dis . '>';
            foreach (edu_callout_styles() as $k => $l) {
                $h .= '<option value="' . $k . '"' . ($b['style'] === $k ? ' selected' : '') . '>' . $l . '</option>';
            }
            $h .= '</select><input name="title" maxlength="190" value="' . $v('title') . '" placeholder="Heading (optional)"' . $dis . '></div>' . $tools
                . '<textarea name="body" rows="3" data-md-src placeholder="The tip, note or warning"' . $dis . '>' . $v('body') . '</textarea><div class="ebk-prev cp-lesson-body" data-md-prev hidden></div>';
            break;
        case 'video':
            $src = edu_video_embed((string) $b['url']);
            $h .= '<div class="ebk-media"><div class="ebk-fields"><label class="field"><span>YouTube or Vimeo address</span><input name="url" maxlength="500" value="' . $v('url') . '" placeholder="https://www.youtube.com/watch?v=…" data-video-url' . $dis . '></label>'
                . '<label class="field"><span>Caption (optional)</span><input name="title" maxlength="190" value="' . $v('title') . '"' . $dis . '></label></div>'
                . '<div class="eb-vprev" data-video-prev>' . ($src ? '<iframe src="' . e($src) . '" title="Video preview" allowfullscreen loading="lazy"></iframe>' : '<span>Paste an address: the video appears here.</span>') . '</div></div>';
            break;
        case 'link':
            $h .= '<div class="ebk-fields two"><label class="field"><span>Address</span><input name="url" maxlength="500" value="' . $v('url') . '" placeholder="https://"' . $dis . '></label>'
                . '<label class="field"><span>Title</span><input name="title" maxlength="190" value="' . $v('title') . '" placeholder="What the page is"' . $dis . '></label></div>'
                . '<label class="field"><span>What to do there (optional)</span><input name="body" maxlength="600" value="' . $v('body') . '"' . $dis . '></label>';
            break;
        case 'file':
            if ($b['stored_name']) {
                $f = ['id' => $b['file_id'], 'original_name' => $b['original_name'], 'mime' => $b['mime'], 'size' => $b['size']];
                $h .= '<div class="ebk-file t-' . edu_file_tag($f) . '"><span class="eb-fico">' . e(strtoupper(edu_file_ext($f))) . '</span><span class="eb-fname"><strong>' . e($f['original_name']) . '</strong><em>'
                    . e(edu_file_kinds()[edu_file_ext($f)] ?? 'File') . ' · ' . e(edu_human_size((int) $f['size'])) . ' · ' . (edu_file_inline($f) ? 'shown in the lesson' : 'students download it') . '</em></span>'
                    . (edu_file_inline($f) ? '<a class="eb-ibtn" href="' . e(edu_file_url_desk($f)) . '" target="_blank" rel="noopener" title="Open">' . edu_icon('eye') . '</a>' : '')
                    . '<a class="eb-ibtn" href="' . e(edu_file_url_desk($f, true)) . '" title="Download">' . edu_icon('download') . '</a></div>';
            } else {
                $h .= '<p class="muted">This document\'s file is missing. Remove the block and upload it again.</p>';
            }
            $h .= '<label class="field"><span>Caption (optional)</span><input name="title" maxlength="190" value="' . $v('title') . '" placeholder="e.g. Week 1 slides"' . $dis . '></label>';
            break;
    }
    $h .= $edit ? '<noscript><button class="btn btn-sm">Save</button></noscript>' : '';
    $h .= '</form>';
    if ($edit) {
        $h .= '<details class="ebk-insert"><summary title="Add a block below">' . edu_icon('plus') . '</summary>' . edu_block_adders((int) $b['lesson_id'], $id) . '</details>';
    }
    return $h . '</li>';
}

function edu_builder_lesson(int $org, array $c, array $l, array $sections): void
{
    $edit = edu_can_edit();
    $dis = $edit ? '' : ' disabled';
    $id = (int) $l['id'];
    $kind = $l['kind'];
    if ($edit) {
        edu_blocks_migrate($org, $l);      // a lesson made before blocks: its content becomes blocks, once
    }
    $blocks = edu_blocks($org, $id);
    $all = edu_lessons($org, (int) $c['id']);
    $pos = array_search($id, array_map(static fn($x) => (int) $x['id'], $all), true);
    echo '<div class="eb-pane k-' . e($kind) . '"><div class="eb-pane-h"><span class="eb-kico big">' . edu_icon($kind) . '</span><div><span class="eb-kind">' . e(edu_lesson_kinds()[$kind]) . ' · lesson ' . ($pos + 1) . ' of ' . count($all) . '</span>'
       . '<h2>' . e($l['title']) . '</h2></div><span class="spacer"></span><span class="eb-dirty" data-dirty hidden>Unsaved changes</span>'
       . '<button type="button" class="btn btn-sm" data-preview-toggle>' . edu_icon('eye') . ' Student view</button></div>';
    echo '<div class="eb-studentview" data-studentview hidden>' . edu_lesson_render($org, $l, static fn($f, $dl = false) => edu_file_url_desk($f, $dl)) . '</div>';

    echo '<form method="post" class="edu-form eb-lform" data-lesson-form>' . fabo_csrf_field() . '<input type="hidden" name="a" value="lesson-save"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="course_id" value="' . (int) $c['id'] . '">'
       . '<div class="eb-row"><label class="field grow"><span>Title</span><input name="title" required maxlength="190" value="' . e($l['title']) . '"' . $dis . '></label>'
       . '<label class="field"><span>Shown as</span><select name="kind"' . $dis . '>';
    foreach (edu_lesson_kinds() as $k => $lab) {
        echo '<option value="' . $k . '"' . ($kind === $k ? ' selected' : '') . '>' . $lab . '</option>';
    }
    echo '</select></label><label class="field"><span>Section</span><input name="section" maxlength="120" list="ebSections2" value="' . e((string) $l['section']) . '"' . $dis . '></label>'
       . '<label class="field sm"><span>Minutes</span><input type="number" name="minutes" min="1" max="600" value="' . (int) $l['minutes'] . '"' . $dis . '></label>'
       . ($edit ? '<div class="field eb-rowsave"><span>&nbsp;</span><button class="btn btn-primary">Save</button></div>' : '') . '</div>'
       . '<datalist id="ebSections2">' . implode('', array_map(static fn($s) => '<option value="' . e($s) . '">', $sections)) . '</datalist></form>';

    echo '<section class="eb-block ebk-wrap"><div class="eb-block-h"><h3>Content <span class="muted" data-bcount>' . count($blocks) . '</span></h3><span class="muted">Mix text, videos, documents, links and callouts, in any order. Changes save as you type.</span></div>'
       . '<ol class="ebk-list" data-blocks data-lesson="' . $id . '">';
    foreach ($blocks as $b) {
        echo edu_block_card($org, $b, $edit);
    }
    echo '</ol>';
    if (!$blocks) {
        echo '<p class="ebk-empty" data-bempty>Nothing here yet. Add the first block below' . ($kind === 'quiz' ? ' (optional for a quiz: instructions, a video to watch first…)' : '') . '.</p>';
    }
    if ($edit) {
        echo '<div class="ebk-add"><span>Add to this lesson</span>' . edu_block_adders($id, 0, true) . '</div>'
           . '<label class="eb-drop ebk-drop" data-drop data-blocks-mode data-lesson="' . $id . '" data-chunk="' . (int) min(fabo_chunk_bytes(), 8 * 1048576) . '" data-url="' . e(edu_url('course', ['id' => (int) $c['id']])) . '">'
           . '<input type="file" multiple accept=".pdf,.ppt,.pptx,.doc,.docx,.xls,.xlsx,.csv,.txt,.zip,.mp4,.mov,.jpg,.jpeg,.png,.gif,.webp" hidden>'
           . '<span class="eb-drop-ico">' . edu_icon('upload') . '</span><strong>Drop documents, slides, images or videos here</strong>'
           . '<em>each file becomes a block · PDF, PowerPoint, Word, Excel, images, MP4, ZIP · large files are sent in pieces</em></label>';
    }
    echo '</section>';
    if ($kind === 'quiz') {
        edu_builder_quiz($org, $c, $l);
    }
    if ($edit) {
        echo '<div class="eb-foot"><form method="post">' . fabo_csrf_field() . '<input type="hidden" name="a" value="lesson-move"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="dir" value="-1"><button class="btn btn-sm"' . ($pos === 0 ? ' disabled' : '') . '>↑ Move lesson up</button></form>'
           . '<form method="post">' . fabo_csrf_field() . '<input type="hidden" name="a" value="lesson-move"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="dir" value="1"><button class="btn btn-sm"' . ($pos === count($all) - 1 ? ' disabled' : '') . '>↓ Move lesson down</button></form>'
           . '<span class="spacer"></span><form method="post" data-confirm="Remove this lesson, all its content and students\' progress on it?">' . fabo_csrf_field() . '<input type="hidden" name="a" value="lesson-delete"><input type="hidden" name="id" value="' . $id . '"><button class="btn btn-sm btn-danger">' . edu_icon('trash') . ' Remove lesson</button></form></div>';
    }
    echo '</div>';
}

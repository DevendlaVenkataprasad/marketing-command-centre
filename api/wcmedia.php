<?php
/**
 * Changes to a website post made in the preview window.
 *
 * The preview stages changes and saves them together, so nothing changes
 * until somebody presses Save -- and Discard really does leave the post as it
 * was. Three actions:
 *
 *   action=upload   one file (field "file"): an image, a PDF or a video. It is
 *                   stored, and its name returned, but it is NOT attached to
 *                   the post: that happens on save.
 *   action=discard  files uploaded and then abandoned (field "files", a JSON
 *                   list of names). Deleted, unless some post uses them.
 *   action=save     everything staged, in one go: the LinkedIn kind, the
 *                   text, the poll, the slides, the date and time, and the
 *                   files. Each change is written to the edit history. A post
 *                   already approved whose content changes goes back for
 *                   approval -- what was signed off is what goes out.
 *
 * Files go to media/, not uploads/: LinkedIn is sent the bytes, but Instagram
 * (later) fetches by address, and the preview shows them. Names are 32 random
 * hex characters, so the folder cannot be walked, and only images, PDFs and
 * videos are accepted -- checked by content, not by the name.
 */

require_once __DIR__ . '/../lib/endpoint.php';
require_once __DIR__ . '/../lib/webcontent.php';
require_once __DIR__ . '/../lib/creative.php';
require_once __DIR__ . '/../lib/approvals.php';

fabo_csrf_check();
[$viewer, $key, $sheet, $perm] = fabo_endpoint('edit');
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
if ($key !== FABO_WC_SHEET) {
    fabo_json_fail('This is for website posts only.', 400);
}
$action = (string) ($_POST['action'] ?? '');
$dir    = fabo_media_dir() . DIRECTORY_SEPARATOR;
$named  = static fn($f) => is_string($f) && preg_match('~^[0-9a-f]{32}\.[a-z0-9]{2,5}$~', $f);

/** What an upload may be, by its real type: [kind, extension, largest size in MB]. */
$allowed = [
    'image/jpeg'      => ['image', 'jpg', 10],
    'image/png'       => ['image', 'png', 10],
    'image/webp'      => ['image', 'webp', 10],
    'image/gif'       => ['image', 'gif', 10],
    'application/pdf' => ['pdf', 'pdf', 100],
    'video/mp4'       => ['video', 'mp4', 200],
    'video/quicktime' => ['video', 'mov', 200],
];
$kindOfExt = ['jpg' => 'image', 'png' => 'image', 'webp' => 'image', 'gif' => 'image',
              'pdf' => 'pdf', 'mp4' => 'video', 'mov' => 'video'];

/* ------------------------------------------------------------- upload */
if ($action === 'upload') {
    $f = $_FILES['file'] ?? null;
    if (!$f || !is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $err = (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE);
        fabo_json_fail(in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 'That file is bigger than this server accepts (' . ini_get('upload_max_filesize')
              . '). Raise upload_max_filesize in hPanel, or upload a smaller file.'
            : 'No file arrived.', 400);
    }
    $mime = '';
    if (function_exists('finfo_open')) {
        $mime = (string) finfo_file(finfo_open(FILEINFO_MIME_TYPE), (string) $f['tmp_name']);
    }
    if (!isset($allowed[$mime])) {
        fabo_json_fail('Only images (JPG, PNG, WebP, GIF), PDFs and videos (MP4, MOV) can be used.', 400);
    }
    [$kind, $ext, $maxMb] = $allowed[$mime];
    if ((int) $f['size'] > $maxMb * 1048576) {
        fabo_json_fail('That file is too big: ' . $kind . 's can be up to ' . $maxMb . ' MB.', 400);
    }
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file((string) $f['tmp_name'], $dir . $name)) {
        fabo_json_fail('The file could not be saved.', 500);
    }
    fabo_json(['ok' => true, 'file' => ['file' => $name, 'kind' => $kind,
               'name' => mb_substr(basename((string) $f['name']), 0, 120)]]);
}

/**
 * Does any post other than $except use this file? Every column a media file
 * can live in is checked -- the post's own media, its generated graphic and
 * its carousel PDF. Checking post_media alone let one post's discard or edit
 * delete a file another post was still showing.
 */
$inUseElsewhere = static function (string $f, int $except): bool {
    $like = '%' . $f . '%';
    return (bool) fabo_one('SELECT id FROM `' . FABO_WC_TABLE . '` WHERE id <> ?
                              AND (post_media LIKE ? OR media_asset LIKE ? OR document_asset LIKE ?) LIMIT 1',
                           'isss', [$except, $like, $like, $like]);
};

/* ------------------------------------------------------------ discard */
if ($action === 'discard') {
    foreach ((array) json_decode((string) ($_POST['files'] ?? '[]'), true) as $f) {
        if (!$named($f) || !is_file($dir . $f)) {
            continue;
        }
        // Only a file no post uses at all.
        if (!$inUseElsewhere($f, 0)) {
            @unlink($dir . $f);
        }
    }
    fabo_json(['ok' => true]);
}

if ($action !== 'save') {
    fabo_json_fail('Nothing to do.', 400);
}

/* --------------------------------------------------------------- save */
$row = fabo_load_row($viewer, $key, (int) ($_POST['id'] ?? 0));
if (!$row) {
    fabo_json_fail('That post was not found.', 404);
}
$id = (int) $row['id'];
if (in_array((string) $row['status'], ['PUBLISHING', 'PUBLISHED'], true)) {
    fabo_json_fail('This post has already gone out, so it cannot be changed here.', 400);
}

$kinds = ['Text', 'Image', 'Multi-image', 'Carousel', 'Document', 'Video', 'Poll',
          'Article', 'Newsletter', 'Event', 'Job'];
$in    = static fn(string $k) => array_key_exists($k, $_POST) ? trim((string) $_POST[$k]) : null;
$set   = [];

$type = $in('linkedin_type');
if ($type !== null) {
    if (!in_array($type, $kinds, true)) {
        fabo_json_fail('That is not a LinkedIn post kind.', 400);
    }
    $set['linkedin_type'] = $type;
}
$copy = $in('linkedin_copy');
if ($copy !== null) {
    if ($copy === '') {
        fabo_json_fail('The post text cannot be empty.', 400);
    }
    if (mb_strlen($copy) > 3000) {
        fabo_json_fail('LinkedIn takes at most 3,000 characters; this is ' . mb_strlen($copy) . '.', 400);
    }
    $set['linkedin_copy'] = $copy;
}
$q = $in('poll_question');
if ($q !== null) {
    if (mb_strlen($q) > 140) {
        fabo_json_fail('A poll question can be at most 140 characters.', 400);
    }
    $set['poll_question'] = $q;
}
$opts = $in('poll_options');
if ($opts !== null) {
    $list = array_values(array_filter(array_map('trim', explode('|', $opts)), 'strlen'));
    foreach ($list as $o) {
        if (mb_strlen($o) > 30) {
            fabo_json_fail('Each poll option can be at most 30 characters: "' . $o . '" is longer.', 400);
        }
    }
    if (count($list) > 4) {
        fabo_json_fail('A poll can have at most 4 options.', 400);
    }
    $set['poll_options'] = implode(' | ', $list);
}
$dur = $in('poll_duration');
if ($dur !== null) {
    if ($dur !== '' && !in_array($dur, ['1 day', '3 days', '1 week', '2 weeks'], true)) {
        fabo_json_fail('A poll runs for 1 day, 3 days, 1 week or 2 weeks.', 400);
    }
    $set['poll_duration'] = $dur;
}
$slides = $in('carousel_slides');
if ($slides !== null) {
    $set['carousel_slides'] = mb_substr($slides, 0, 6000);
}
$date = $in('scheduled_date');
if ($date !== null) {
    if ($date !== '' && (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $date) || !strtotime($date))) {
        fabo_json_fail('That date is not valid.', 400);
    }
    $set['scheduled_date'] = $date === '' ? null : $date;
}
$time = $in('scheduled_time');
if ($time !== null) {
    if ($time !== '' && !preg_match('~^([01]\d|2[0-3]):[0-5]\d$~', $time)) {
        fabo_json_fail('That time is not valid; use HH:MM.', 400);
    }
    $set['scheduled_time'] = $time;
}

/* Files: what the post should carry after the save. A file is accepted
   only by a name this portal could have given it, sitting in media/, of a
   kind its extension says. Files dropped from the list are deleted. */
$oldMedia = json_decode((string) ($row['post_media'] ?? ''), true) ?: [];
$oldFiles = array_column($oldMedia, 'file');
$media    = $in('post_media');
if ($media !== null) {
    $new = [];
    foreach ((array) json_decode($media, true) as $m) {
        $f   = (string) ($m['file'] ?? '');
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (!$named($f) || !is_file($dir . $f) || !isset($kindOfExt[$ext])) {
            continue;
        }
        // A file another post already uses is that post's, not this one's to adopt.
        if (!in_array($f, $oldFiles, true) && $inUseElsewhere($f, $id)) {
            continue;
        }
        $new[] = ['file' => $f, 'kind' => $kindOfExt[$ext],
                  'name' => mb_substr((string) ($m['name'] ?? $f), 0, 120)];
        if (count($new) >= 20) {
            break;
        }
    }
    $set['post_media'] = $new ? json_encode($new, JSON_UNESCAPED_SLASHES) : '';
}

/* Only what actually changed is written and recorded. */
$changed = [];
foreach ($set as $field => $value) {
    if ((string) ($row[$field] ?? '') !== (string) ($value ?? '')) {
        $changed[$field] = $value;
    }
}
if (!$changed) {
    fabo_json(['ok' => true, 'message' => 'Nothing had changed.', 'reapproval' => false]);
}

$cols  = [];
$types = '';
$vals  = [];
foreach ($changed as $field => $value) {
    $cols[]  = '`' . $field . '` = ?';
    $types  .= 's';
    $vals[]  = $value;
}
$vals[] = $id;
fabo_exec('UPDATE `' . FABO_WC_TABLE . '` SET ' . implode(', ', $cols) . ' WHERE id = ?',
          $types . 'i', $vals);
foreach ($changed as $field => $value) {
    fabo_audit($viewer, FABO_WC_TABLE, $id, $field,
               mb_substr((string) ($row[$field] ?? ''), 0, 2000), mb_substr((string) ($value ?? ''), 0, 2000));
}

/* Files taken off the post go from the server too. */
if (isset($changed['post_media'])) {
    $keep = array_column(json_decode((string) $changed['post_media'], true) ?: [], 'file');
    foreach ($oldMedia as $m) {
        $f = (string) ($m['file'] ?? '');
        if ($named($f) && !in_array($f, $keep, true) && !$inUseElsewhere($f, $id)) {
            @unlink($dir . $f);
        }
    }
}

/* A carousel whose slides changed, or a post just made a carousel, gets its
   PDF made again from the slides as they are now. */
$fresh = fabo_one('SELECT * FROM `' . FABO_WC_TABLE . '` WHERE id = ?', 'i', [$id]);
if ((isset($changed['carousel_slides']) || (isset($changed['linkedin_type']) && $fresh['linkedin_type'] === 'Carousel'))
    && count(fabo_creative_slides($fresh)) >= 2) {
    $old = (string) ($fresh['document_asset'] ?? '');
    $doc = fabo_creative_carousel($fresh);
    if ($doc !== '') {
        fabo_exec('UPDATE `' . FABO_WC_TABLE . '` SET document_asset = ? WHERE id = ?', 'si', [$doc, $id]);
        if (preg_match('~^([0-9a-f]{32})\.pdf$~', $old, $m)) {
            @unlink($dir . $old);
            foreach (glob($dir . $m[1] . '-*.jpg') ?: [] as $jpg) {
                @unlink($jpg);
            }
        }
    }
}

/* What was approved is what goes out. A change to what the post says or
   shows sends it back for approval; a new date or time does not. */
$content    = array_diff_key($changed, ['scheduled_date' => 1, 'scheduled_time' => 1]);
$reapproval = false;
$state      = fabo_wc_approval_state($id);
if ($content && ($state === 'approved' || ($state === '' && in_array((string) $row['status'],
                                                                     ['APPROVED', 'SCHEDULED'], true)))) {
    fabo_set_approval(FABO_WC_SHEET, $id, 'pending', 'Edited after approval: '
                      . implode(', ', array_keys($content)) . '.',
                      $viewer['is_admin'] ? 'Admin' : (string) $viewer['client']['name']);
    $reapproval = true;
} elseif (!$content && isset($changed['scheduled_date']) && (string) $fresh['status'] === 'APPROVED'
          && $fresh['scheduled_date']) {
    /* Approved and waiting for a date: given one, it goes on the schedule. */
    fabo_exec('UPDATE `' . FABO_WC_TABLE . '` SET status = "SCHEDULED" WHERE id = ?', 'i', [$id]);
}

fabo_json(['ok' => true, 'reapproval' => $reapproval,
           'message' => $reapproval ? 'Saved. Because the post changed, it needs approving again.'
                                    : 'Saved.']);

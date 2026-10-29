<?php
/**
 * One Website Content row, as the post preview needs it.
 *
 * Read from the database rather than from the grid, because a long cell on
 * the grid is a one-line box and the paragraph breaks in a post would be lost
 * on the way. Cells save as they are edited, so this is what the grid shows.
 *
 * Scoped like every other row read: fabo_load_row() applies the viewer's
 * scope, so a client can preview only their own posts.
 */

require_once __DIR__ . '/../lib/endpoint.php';
require_once __DIR__ . '/../lib/webcontent.php';
require_once __DIR__ . '/../lib/social.php';        // fabo_linkedin_kind()
require_once __DIR__ . '/../lib/approvals.php';

[$viewer, $key, $sheet, $perm] = fabo_endpoint('view');
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
if ($key !== FABO_WC_SHEET) {
    fabo_json_fail('Previews are for website posts only.', 400);
}

$row = fabo_load_row($viewer, $key, (int) ($_GET['id'] ?? 0));
if (!$row) {
    fabo_json_fail('That post was not found.', 404);
}

$want = ['wc_id', 'brand_name', 'platform', 'linkedin_headline', 'linkedin_copy',
         'instagram_hook', 'instagram_copy', 'hashtags', 'cta', 'cta_url', 'source_url',
         'headline', 'supporting_text', 'alt_text', 'media_asset', 'scheduled_date',
         'scheduled_time', 'status', 'hallucination_check', 'generation_notes',
         'poll_question', 'poll_options', 'poll_duration', 'primary_message', 'carousel_slides'];
$post = [];
foreach ($want as $f) {
    $post[$f] = (string) ($row[$f] ?? '');
}

/* The graphic, if one was made. Only a name the creative step itself would
   have written is passed on -- never a path somebody typed. The page adds
   the folder, because only it knows where media/ is relative to itself. */
$post['image'] = preg_match('~^[0-9a-f]{32}\.(png|svg)$~', $post['media_asset'])
    ? $post['media_asset'] : '';

/* The LinkedIn kind and what it shows: the carousel's pages, and anything
   somebody uploaded -- which goes out ahead of the portal's own. */
$post['id']            = (int) $row['id'];
$post['linkedin_type'] = fabo_linkedin_kind($row);
$post['pages']         = [];
if (preg_match('~^([0-9a-f]{32})\.pdf$~', (string) ($row['document_asset'] ?? ''), $m)) {
    $post['document'] = $m[0];
    $n = 1;
    while (is_file(__DIR__ . '/../media/' . $m[1] . '-' . $n . '.jpg') && $n <= 20) {
        $post['pages'][] = $m[1] . '-' . $n . '.jpg';
        $n++;
    }
}
$post['files'] = [];
foreach (json_decode((string) ($row['post_media'] ?? ''), true) ?: [] as $f) {
    if (preg_match('~^[0-9a-f]{32}\.[a-z0-9]{2,5}$~', (string) ($f['file'] ?? ''))) {
        $post['files'][] = ['file' => $f['file'], 'kind' => (string) ($f['kind'] ?? ''),
                            'name' => (string) ($f['name'] ?? '')];
    }
}
[$ready, $why] = fabo_linkedin_ready_row($row);
$post['ready'] = $ready;
$post['ready_note'] = $why;

/* Where it stands: the decision, who made it and when, and what this viewer
   may do about it. The buttons are offered from these; api/approve.php
   checks them again. */
$a = fabo_approval($key, (int) $row['id']);
/* decided_at is the database's clock, which on the host is UTC; shown in the
   portal's own time like everything else on the page. */
$skew = time() - (int) strtotime((string) (fabo_one('SELECT NOW() n')['n'] ?? 'now'));
$post['approval'] = [
    'state'       => (string) $a['state'],
    'label'       => fabo_approval_label((string) $a['state']),
    'by'          => (string) ($a['decided_by'] ?? ''),
    'when'        => !empty($a['decided_at'])
                     ? date('d M Y, H:i', (int) strtotime((string) $a['decided_at']) + $skew) : '',
    'remarks'     => (string) ($a['remarks'] ?? ''),
    'can_decide'  => fabo_may_decide($viewer, $key, (int) $row['id']),
    'can_request' => fabo_may_request($viewer, $key),
    'can_edit'    => $perm === 'edit',
    'locked'      => in_array((string) $row['status'], ['PUBLISHING', 'PUBLISHED'], true),
];

fabo_json(['ok' => true, 'post' => $post]);

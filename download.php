<?php
/**
 * Serves an uploaded file. The file is streamed from outside the web path and
 * only after the viewer's access to that row's sheet has been re-checked, so a
 * link cannot be guessed or shared outside the portal.
 */

require_once __DIR__ . '/lib/endpoint.php';
require_once __DIR__ . '/lib/uploads.php';
fabo_boot();

/*
 * Two ways in. A portal viewer, re-checked against the row's sheet as
 * before; or a department's sealed link, which may see only the images on
 * the one event that link was issued for. The second path never consults a
 * sheet permission because the department has none — the token is the whole
 * of its authority, and it is scoped to a single event.
 */
$sealed = (string) ($_GET['t'] ?? '');
$shared = (string) ($_GET['s'] ?? '');

if ($shared !== '') {
    /* A shared live sheet's link (s.php): any file on a row that share can
       see, and nothing else. See lib/shares.php. */
    require_once __DIR__ . '/lib/shares.php';
    $share = fabo_share_open($shared);
    $a     = $share ? fabo_share_attachment($share, (int) ($_GET['f'] ?? 0)) : null;
    if (!$a) {
        fabo_deny('File not found.', 404);
    }
} elseif ($sealed !== '') {
    require_once __DIR__ . '/lib/departments.php';
    $dept = fabo_dept_open($sealed);
    if (!$dept) {
        fabo_deny('File not found.', 404);
    }
    /* Their event's own images, and the images on any row they contributed.
       The second is where photographs actually land, since a department's
       rows are its own rows — see api/deptsheet.php. */
    $id = (int) ($_GET['a'] ?? $_GET['f'] ?? 0);
    $a  = fabo_one("SELECT a.* FROM attachments a
                    LEFT JOIN `events` e ON e.id = a.row_id
                    WHERE a.id = ? AND a.sheet_key = 'events'
                      AND (a.row_id = ? OR e.department_id = ?)",
                   'iii', [$id, (int) $dept['event_row_id'], (int) $dept['id']]);
    /* Images from either place; documents only from the department's own
       rows -- the Attachments column on their live sheet. What the
       organisers keep on the event itself stays theirs. */
    $ownRow = $a && (int) (fabo_one('SELECT department_id FROM `events` WHERE id = ?', 'i',
                                    [(int) $a['row_id']])['department_id'] ?? 0) === (int) $dept['id'];
    if (!$a) {
        /* A quotation on the budget of one of their own rows. It hangs on a
           budget line, not on the row, so it is found through the line. */
        $a = fabo_one("SELECT a.* FROM attachments a
                         JOIN budget_lines l ON l.id = a.row_id AND l.sheet_key = 'events'
                         JOIN `events` e ON e.id = l.row_id
                        WHERE a.id = ? AND a.sheet_key = 'budget' AND e.department_id = ?",
                      'ii', [$id, (int) $dept['id']]);
        $ownRow = (bool) $a;
    }
    if (!$a || (!fabo_is_image((string) $a['mime']) && !$ownRow)) {
        fabo_deny('File not found.', 404);   // not theirs
    }
} else {
    $token  = (string) ($_GET['k'] ?? '');
    $viewer = $token !== '' ? fabo_client_viewer($token)
            : (fabo_signed_in() ? fabo_person_viewer(fabo_signed_in())
                                : (!fabo_admin_signed_in()
                                   ? fabo_deny('Please sign in.', 401) : fabo_admin_viewer()));

    $id = (int) ($_GET['f'] ?? 0);
    $a  = fabo_one('SELECT * FROM attachments WHERE id = ?', 'i', [$id]);
    if (!$a) {
        fabo_deny('File not found.', 404);
    }

    $key = (string) $a['sheet_key'];
    $row = (int) $a['row_id'];

    /* A budget document hangs on a budget line, not on a sheet row, so its
       sheet_key is not a sheet and there is nothing to check it against
       directly. The line knows which row it belongs to: resolve that, and the
       ordinary check below then asks the real question — may this person see
       the event this quotation was filed against? */
    if ($key === 'budget') {
        require_once __DIR__ . '/lib/budget.php';
        $line = fabo_one('SELECT sheet_key, row_id FROM budget_lines WHERE id = ?',
                         'i', [$row]);
        if (!$line) {
            fabo_deny('File not found.', 404);
        }
        $key = (string) $line['sheet_key'];
        $row = (int) $line['row_id'];
    }

    /* A committee member holds no sheets: their reason to see an event's
       papers is that the event was sent to a committee they sit on. */
    require_once __DIR__ . '/lib/committees.php';
    if ($key === 'events' && fabo_cm_sees_event($viewer, $row)) {
        // allowed
    } else {
        fabo_require($viewer, $key, 'view');
        if (!fabo_load_row($viewer, $key, $row)) {
            fabo_deny('File not found.', 404);   // out of the viewer's data scope
        }
    }
}

$path = fabo_upload_dir() . DIRECTORY_SEPARATOR . (string) $a['stored_name'];
if (!is_file($path)) {
    error_log('FABO download: missing file ' . $path);
    fabo_deny('That file is no longer on the server.', 404);
}

$mime = (string) $a['mime'];
// Never let the browser run what it downloads; images may show inline.
$inline = fabo_is_image($mime) && $mime !== 'image/svg+xml';

/* ?thumb=1: the small copy the grids show (see fabo_upload_thumb). */
if ($inline && !empty($_GET['thumb'])) {
    $small = fabo_upload_thumb((string) $a['stored_name'], $mime);
    if ($small !== null && $small !== $path) {
        $path = $small;
        $mime = 'image/jpeg';
    }
}

/* Nothing below needs the database, and a picture can take a while to reach
   a phone: let the connection go now, not when the last byte has left. */
fabo_db_release();
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

header('Content-Type: ' . ($inline ? $mime : 'application/octet-stream'));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
     . '; filename="' . str_replace('"', '', (string) $a['original_name']) . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
/* A stored file never changes under its id, so a picture is kept by this
   browser (never a shared cache) instead of being fetched again on every
   visit; a sheet of five hundred photographs opened twice cost twice. */
header_remove('Pragma');
header_remove('Expires');
header($inline ? 'Cache-Control: private, max-age=604800' : 'Cache-Control: no-store, private');
readfile($path);

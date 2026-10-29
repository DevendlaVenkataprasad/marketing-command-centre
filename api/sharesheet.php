<?php
/**
 * The shared live sheet's own endpoint (s.php).
 *
 * Everything the page does -- load the rows, add one, save a cell, attach a
 * picture or a document, take one off -- answered as JSON so the page never
 * reloads, and 'rows' polled every few seconds so the sheet stays live.
 *
 * There is no session behind this: the token in the request is the whole of
 * the authority, and it names exactly one share. Every action below is bound
 * to that share and to the rows it may see, so no id in the request can be
 * changed to reach anybody else's work.
 */

require_once __DIR__ . '/../lib/shares.php';
require_once __DIR__ . '/../lib/endpoint.php';
require_once __DIR__ . '/../lib/grid.php';

/** The Attachments column's marker in an attachment's caption. */
const FABO_SS_FILE = 'file';

fabo_boot();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
header('Pragma: no-cache');
header('X-LiteSpeed-Cache-Control: no-cache');
header('X-Robots-Tag: noindex, nofollow');

function fabo_ss_fail(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fabo_ss_fail('Use the sheet page.', 405);
}

/* Before the link: an upload too big for the server arrives with no fields
   -- its token included -- and must not be taken for a closed link. */
if (fabo_upload_body_lost()) {
    fabo_ss_fail(fabo_upload_too_big_words(), 413);
}

$share = fabo_share_open((string) ($_POST['k'] ?? ''));
if (!$share) {
    fabo_ss_fail('This link is no longer available. Ask whoever shared it for a new one.', 403);
}
$sheet = fabo_share_sheet($share);           // also sets the organisation's view
$key   = (string) $share['sheet_key'];
$cols  = fabo_share_columns($sheet, $share);
$action = (string) ($_POST['action'] ?? '');

/** The one row an action is about, or a refusal. */
function fabo_ss_row(array $share, array $sheet): array
{
    $r = fabo_share_row($share, $sheet, (int) ($_POST['id'] ?? 0));
    if (!$r) {
        fabo_ss_fail('That row is not on this sheet.', 403);
    }
    return $r;
}

/** The uploaded files of one field, as a list of single-file arrays. */
function fabo_ss_files(string $name): array
{
    $f = $_FILES[$name] ?? null;
    if (!$f || !isset($f['name'])) {
        return [];
    }
    if (!is_array($f['name'])) {
        return ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE ? [] : [$f];
    }
    $out = [];
    foreach ($f['name'] as $i => $n) {
        if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $out[] = ['name' => $n, 'type' => $f['type'][$i], 'tmp_name' => $f['tmp_name'][$i],
                  'error' => $f['error'][$i], 'size' => $f['size'][$i]];
    }
    return $out;
}

/** A file over upload_max_filesize arrives as an error, not as nothing. */
function fabo_ss_too_big(): void
{
    foreach ($_FILES as $f) {
        foreach ((array) ($f['error'] ?? []) as $err) {
            if (in_array((int) $err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                fabo_ss_fail(fabo_upload_too_big_words(), 413);
            }
        }
    }
}

try {
    switch ($action) {

        /* ------------------------------------------------- the whole sheet */
        case 'rows':
            $out = [];
            foreach (fabo_share_rows($share, $sheet) as $r) {
                $out[] = fabo_share_payload($share, $sheet, $r);
            }
            /* The columns go back too, so a column renamed, added or taken
               off on Sheet Management reaches an open page on its next poll. */
            $cmeta = [];
            foreach ($cols as $f => $m) {
                $cmeta[] = ['field' => $f, 'label' => $m['label'], 'type' => $m['type'],
                            'list' => $m['list'], 'mode' => $m['mode'], 'note' => $m['note']];
            }
            echo json_encode(['ok' => true, 'rows' => $out, 'columns' => $cmeta,
                              'lists' => fabo_share_lists($cols),
                              'sig' => md5((string) json_encode($cmeta)),
                              'at' => date('H:i:s')], JSON_UNESCAPED_UNICODE);
            exit;

        /* ---------------------------------------------------------- adding */
        case 'add':
            $id = fabo_share_add_row($share, $sheet);
            $r  = fabo_share_row($share, $sheet, $id);
            echo json_encode(['ok' => true, 'row' => $r ? fabo_share_payload($share, $sheet, $r) : null,
                              'message' => 'Row added.'], JSON_UNESCAPED_UNICODE);
            exit;

        /* An event added already filled in, as Upcoming or Completed. */
        case 'addfilled':
            $vals = json_decode((string) ($_POST['values'] ?? ''), true);
            [$id, $warn] = fabo_share_add_filled($share, $sheet, (string) ($_POST['stage'] ?? ''),
                                                 is_array($vals) ? $vals : [],
                                                 (string) ($_POST['results_name'] ?? ''),
                                                 (string) ($_POST['results_email'] ?? ''));
            $r = fabo_share_row($share, $sheet, $id);
            echo json_encode(['ok' => true, 'row' => $r ? fabo_share_payload($share, $sheet, $r) : null,
                              'message' => 'Event added.', 'warn' => implode(' ', $warn)],
                             JSON_UNESCAPED_UNICODE);
            exit;

        /* ---------------------------------------------------------- saving */
        case 'save':
            $r = fabo_share_save($share, $sheet, (int) ($_POST['id'] ?? 0),
                                 (string) ($_POST['field'] ?? ''), (string) ($_POST['value'] ?? ''));
            echo json_encode(['ok' => true, 'row' => fabo_share_payload($share, $sheet, $r)],
                             JSON_UNESCAPED_UNICODE);
            exit;

        /* -------------------------------------------------------- removing */
        case 'delete':
            fabo_share_delete_row($share, $sheet, (int) ($_POST['id'] ?? 0));
            echo json_encode(['ok' => true, 'removed' => (int) $_POST['id'], 'message' => 'Row removed.']);
            exit;

        /* ------------------------------------- pictures, and documents */
        case 'upload':
        case 'attach':
            fabo_ss_too_big();
            $row    = fabo_ss_row($share, $sheet);
            $images = $action === 'upload';
            // Any size, any number: a big file comes in pieces, put together here.
            fabo_upload_take_chunk($images ? 'images' : 'files', (string) $share['token'],
                                   fn (string $m) => fabo_ss_fail($m));
            $files = fabo_ss_files($images ? 'images' : 'files');
            if (!$files) {
                fabo_ss_fail('No file was sent.');
            }
            $errs = [];
            $n    = 0;
            foreach ($files as $one) {
                try {
                    fabo_share_store($share, $key, (int) $row['id'], $one, $images ? '' : FABO_SS_FILE, $images);
                    $n++;
                } catch (InvalidArgumentException $e) {
                    $errs[] = $e->getMessage();
                }
            }
            if (!$n) {
                fabo_ss_fail(implode(' ', $errs));
            }
            fabo_share_audit($share, $sheet, (int) $row['id'], $images ? '(images)' : '(attachments)',
                             null, $n . ' added');
            $r = fabo_share_row($share, $sheet, (int) $row['id']);
            echo json_encode(['ok' => true, 'row' => fabo_share_payload($share, $sheet, $r),
                              'message' => $n === 1 ? ($images ? 'Picture added.' : 'Document attached.')
                                                    : $n . ($images ? ' pictures added.' : ' documents attached.'),
                              'warn' => implode(' ', $errs)], JSON_UNESCAPED_UNICODE);
            exit;

        case 'drop':
            $row = fabo_ss_row($share, $sheet);
            $aid = (int) ($_POST['file'] ?? 0);
            $a   = fabo_one('SELECT id, original_name FROM attachments WHERE id = ? AND sheet_key = ? AND row_id = ?',
                            'isi', [$aid, $key, (int) $row['id']]);
            if (!$a) {
                fabo_ss_fail('That file is not on this row.', 404);
            }
            // A file that fills a column is cleared from the column with it.
            foreach ($cols as $f => $m) {
                if ($m['mode'] === 'file' && (int) ($row[$f] ?? 0) === $aid) {
                    fabo_exec('UPDATE `' . $sheet['table'] . '` SET `' . $f . '` = NULL WHERE id = ?',
                              'i', [(int) $row['id']]);
                }
            }
            fabo_share_drop($aid);
            fabo_share_audit($share, $sheet, (int) $row['id'], '(files)', (string) $a['original_name'], 'removed');
            $r = fabo_share_row($share, $sheet, (int) $row['id']);
            echo json_encode(['ok' => true, 'row' => fabo_share_payload($share, $sheet, $r),
                              'message' => 'Removed.'], JSON_UNESCAPED_UNICODE);
            exit;

        /* -------------------------------- a file or image column's cell */
        case 'cellfile':
            fabo_ss_too_big();
            $row   = fabo_ss_row($share, $sheet);
            $field = (string) ($_POST['field'] ?? '');
            $m     = $cols[$field] ?? null;
            if (!$m || $m['mode'] !== 'file') {
                fabo_ss_fail('That column does not take a file.');
            }
            if (fabo_cell_locked($m['col'], $row)) {
                fabo_ss_fail(fabo_cell_lock_note($m['col'], $row));
            }
            fabo_upload_take_chunk('file', (string) $share['token'], fn (string $m) => fabo_ss_fail($m));
            $files = fabo_ss_files('file');
            if (!$files) {
                fabo_ss_fail('No file was sent.');
            }
            [$aid] = fabo_share_store($share, $key, (int) $row['id'], $files[0],
                                      '__cell:' . $field, $m['type'] === 'image');
            $old = (int) ($row[$field] ?? 0);
            fabo_exec('UPDATE `' . $sheet['table'] . '` SET `' . $field . '` = ? WHERE id = ?',
                      'ii', [$aid, (int) $row['id']]);
            if ($old > 0) {
                fabo_share_drop($old);           // the file it replaces
            }
            fabo_share_audit($share, $sheet, (int) $row['id'], $field, $old ?: null, (string) $aid);
            fabo_share_after_change($key, (int) $row['id']);
            $r = fabo_share_row($share, $sheet, (int) $row['id']);
            echo json_encode(['ok' => true, 'row' => fabo_share_payload($share, $sheet, $r),
                              'message' => 'File added.'], JSON_UNESCAPED_UNICODE);
            exit;
    }
    fabo_ss_fail('Unknown action.');
} catch (InvalidArgumentException $e) {
    fabo_ss_fail($e->getMessage());
} catch (Throwable $e) {
    error_log('FABO sharesheet ' . $action . ': ' . $e->getMessage());
    fabo_ss_fail('That did not go through. Nothing was changed — try again.', 500);
}

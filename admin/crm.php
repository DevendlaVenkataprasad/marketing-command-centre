<?php
/**
 * CRM — boards of applications, leads or enquiries moving through stages.
 *
 * Two places in the menu:
 *
 *   CRM Dashboard  (?view=dashboard) — every CRM this person can open, at a
 *                  glance: totals, conversion, what is assigned to them.
 *   CRM            — the list of CRMs, and each CRM's own tabs:
 *
 *     Dashboard  — this CRM's figures: stages, weekly intake, people.
 *     Board      — a column per stage; drag a card to move it.
 *     Table      — find, filter, sort, and act on many records at once.
 *     Fields     — the Field Studio: what a record holds, and of what type.
 *     Stages     — the columns of the board, their colour and their order.
 *     Import     — an Excel or CSV file, mapped onto the fields.
 *     Emails     — send to a stage or the ticked records; templates; what was
 *                  sent (lib/crm_mail.php).
 *     Form       — the CRM's one application form: its link, questions, who
 *                  new applications go to, and the acknowledgement email the
 *                  applicant gets (lib/crm_form_tab.php, lib/crm_forms.php).
 *     Access     — who may open it, and whether they see every record or
 *                  only the ones assigned to them.
 *     Settings   — name, who runs it, export, delete.
 *
 * This is the administrator's copy. Anyone else reaches the same page through
 * crm.php at the root, which leaves them in $GLOBALS['fabo_crm_viewer'];
 * what they may see is decided per CRM by fabo_crm_access() in lib/crm.php.
 */

require_once __DIR__ . '/../lib/ui.php';
require_once __DIR__ . '/../lib/crm.php';
require_once __DIR__ . '/../lib/crm_mail.php';
require_once __DIR__ . '/../lib/uploads.php';
fabo_boot();
fabo_crm_install();

header('X-Robots-Tag: noindex, nofollow');

$viewer = $GLOBALS['fabo_crm_viewer'] ?? null;
if ($viewer === null) {
    fabo_require_admin();
    $viewer = fabo_admin_viewer();
}
$isAdmin = !empty($viewer['is_admin']);
$me      = fabo_crm_me($viewer);
$who     = fabo_crm_who($viewer);
$flash   = null;

/** A link on this desk that keeps whatever identified the viewer. */
$u = static function (array $q = []) use ($viewer): string {
    $q = array_filter($q, static fn($v) => $v !== '' && $v !== null && $v !== 0);
    return !empty($viewer['is_admin'])
        ? 'crm.php' . ($q ? '?' . http_build_query($q) : '')
        : fabo_link($viewer, 'crm.php', $q);
};

/* ------------------------------------------------------- which board */

$crmId = (int) ($_POST['crm'] ?? ($_GET['crm'] ?? 0));
$board = $crmId > 0 ? fabo_crm_board($crmId) : null;
if ($crmId > 0 && (!$board || !fabo_crm_may_see($viewer, $board))) {
    fabo_deny('That CRM was not found, or is not yours to open.', 404);
}
$mayCreate = fabo_crm_may_create($viewer);
$access    = $board ? fabo_crm_access($viewer, $board) : ['perm' => 'none', 'scope' => 'all', 'why' => ''];
$mayEdit   = $access['perm'] === 'edit';
$mayDesign = $board ? fabo_crm_may_design($viewer, $board) : false;
/* Somebody limited to the records assigned to them: their id. Every query
   on a board passes it, and every record they touch is checked against it. */
$only      = $board ? fabo_crm_only($viewer, $board) : null;
$recOk     = static fn(?array $r): bool => $r !== null && ($only === null || (int) $r['assigned_to'] === $only);

/* ---------------------------------------------------------- downloads */

if ($board && isset($_GET['export'])) {
    fabo_crm_export($board, $only);
}
if ($board && isset($_GET['template'])) {
    fabo_crm_template($board);
}
/* The Emails tab, while open, asks every few seconds for the next few
   queued messages to go -- so a send makes progress even where no cron job
   runs. It only ever sends what was already queued on this CRM. */
if ($board && isset($_GET['pump']) && $mayEdit) {
    $sentNow = fabo_crm_mail_tick(5, (int) $board['id']);
    $left    = fabo_crm_mail_waiting((int) $board['id']);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['sent' => $sentNow, 'waiting' => $left,
                      'paused' => $sentNow === 0 && $left > 0 ? fabo_crm_mail_pause_words() : '']);
    exit;
}
if ($board && !empty($_GET['file'])) {
    $f = fabo_crm_file((int) $board['id'], (int) $_GET['file']);
    if (!$f || !$recOk(fabo_crm_record((int) $board['id'], (int) $f['record_id']))) {
        fabo_deny('That file was not found.', 404);
    }
    fabo_crm_file_send($f);
}

/* ------------------------------------------------------------ actions */

fabo_session();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    fabo_csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $back   = $board ? $u(['crm' => $board['id'], 'tab' => (string) ($_POST['tab'] ?? 'board')]) : $u();
    $json   = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';

    try {
        /* Every write names what it needs, checked here once against the
           action: making a CRM needs the right to create; anything on a CRM
           needs Edit on it; changing its design or access needs design. */
        $design = ['board_save', 'board_delete', 'field_add', 'field_save', 'field_delete', 'field_move',
                   'stage_add', 'stage_save', 'stage_delete', 'stage_move', 'access_save',
                   'mail_tpl_save', 'mail_tpl_delete',
                   'form_items', 'form_settings', 'form_ack', 'form_question', 'form_toggle', 'form_rotate'];
        if ($action === 'board_create') {
            if (!$mayCreate) {
                throw new InvalidArgumentException('Your account cannot create CRMs.');
            }
        } elseif (!$board) {
            throw new InvalidArgumentException('Open a CRM first.');
        } elseif (in_array($action, $design, true) ? !$mayDesign : !$mayEdit) {
            throw new InvalidArgumentException(in_array($action, $design, true)
                ? 'Only the person who runs this CRM can change how it is laid out or who has access.'
                : 'Your access to this CRM is view only.');
        }
        $cid    = $board ? (int) $board['id'] : 0;
        $fields = $board ? fabo_crm_fields($cid) : [];
        /** A record named in the form, held to this viewer's records. */
        $pick = static function () use ($cid, $recOk): array {
            $r = fabo_crm_record($cid, (int) ($_POST['rec'] ?? 0));
            if (!$recOk($r)) {
                throw new InvalidArgumentException('That record was not found.');
            }
            return $r;
        };

        switch ($action) {

            /* ------------------------------------------------- boards */

            case 'board_create':
                $id = fabo_crm_board_create($viewer, $_POST);
                $flash = ['ok', 'CRM created. Check its fields, then import your Excel file or add the first record.'];
                $back  = $u(['crm' => $id, 'tab' => 'fields']);
                break;

            case 'board_save':
                fabo_crm_board_save($board, $viewer, $_POST);
                $flash = ['ok', 'Settings saved.'];
                $back  = $u(['crm' => $cid, 'tab' => 'settings']);
                break;

            case 'board_delete':
                if (trim((string) ($_POST['confirm'] ?? '')) !== $board['name']) {
                    throw new InvalidArgumentException('Type the CRM’s name exactly to delete it. Nothing was deleted.');
                }
                fabo_crm_board_delete($board);
                $flash = ['ok', '“' . $board['name'] . '” and all of its records were deleted.'];
                $back  = $u();
                break;

            case 'access_save':
                $offer = fabo_crm_grantable_people($board, $isAdmin);
                $perm  = (array) ($_POST['accperm'] ?? []);
                $scope = (array) ($_POST['accscope'] ?? []);
                foreach ($offer as $pid => $_) {
                    if (array_key_exists($pid, $perm)) {
                        fabo_crm_grant($cid, (int) $pid, (string) $perm[$pid], (string) ($scope[$pid] ?? 'all'));
                    }
                }
                $flash = ['ok', 'Access saved. People given access find this CRM in their CRM menu now.'];
                $back  = $u(['crm' => $cid, 'tab' => 'access']);
                break;

            /* ------------------------------------------------- fields */

            case 'field_add':
                fabo_crm_field_add($cid, $_POST);
                $flash = ['ok', 'Field added. It is on every record and in the import mapping now.'];
                $back  = $u(['crm' => $cid, 'tab' => 'fields']);
                break;

            case 'field_save':
                fabo_crm_field_save($cid, (int) ($_POST['field_id'] ?? 0), $_POST);
                $flash = ['ok', 'Field saved.'];
                $back  = $u(['crm' => $cid, 'tab' => 'fields']);
                break;

            case 'field_delete':
                fabo_crm_field_delete($cid, (int) ($_POST['field_id'] ?? 0));
                $flash = ['ok', 'Field deleted, with what records held in it.'];
                $back  = $u(['crm' => $cid, 'tab' => 'fields']);
                break;

            case 'field_move':
                fabo_crm_field_move($cid, (int) ($_POST['field_id'] ?? 0), (string) ($_POST['dir'] ?? 'up'));
                $back = $u(['crm' => $cid, 'tab' => 'fields']);
                break;

            /* ------------------------------------------------- stages */

            case 'stage_add':
                fabo_crm_stage_add($cid, $_POST);
                $flash = ['ok', 'Stage added to the board.'];
                $back  = $u(['crm' => $cid, 'tab' => 'stages']);
                break;

            case 'stage_save':
                fabo_crm_stage_save($cid, (int) ($_POST['stage_id'] ?? 0), $_POST);
                $flash = ['ok', 'Stage saved.'];
                $back  = $u(['crm' => $cid, 'tab' => 'stages']);
                break;

            case 'stage_move':
                fabo_crm_stage_move($cid, (int) ($_POST['stage_id'] ?? 0), (string) ($_POST['dir'] ?? 'up'));
                $back = $u(['crm' => $cid, 'tab' => 'stages']);
                break;

            case 'stage_delete':
                fabo_crm_stage_delete($cid, (int) ($_POST['stage_id'] ?? 0), (int) ($_POST['move_to'] ?? 0));
                $flash = ['ok', 'Stage deleted. Its records moved across first.'];
                $back  = $u(['crm' => $cid, 'tab' => 'stages']);
                break;

            /* ------------------------------------------------ records */

            case 'record_create':
                $back = $u(['crm' => $cid, 'tab' => 'new']);
                [$data, $errs] = fabo_crm_from_post($fields, (array) ($_POST['f'] ?? []));
                $errs = array_merge($errs, fabo_crm_check($cid, $fields, $data, 0, $only !== null));
                if ($errs) {
                    $_SESSION['fabo_crm_draft'] = ['crm' => $cid, 'f' => $_POST['f'] ?? [],
                                                   'stage' => $_POST['stage'] ?? 0, 'assign' => $_POST['assign'] ?? 0];
                    throw new InvalidArgumentException(implode(' ', $errs));
                }
                $stage = (int) ($_POST['stage'] ?? 0);
                if (!fabo_crm_stage($cid, $stage)) {
                    $stage = (int) (fabo_crm_stages($cid)[0]['id'] ?? 0);
                }
                $assign = $only ?? (int) ($_POST['assign'] ?? 0);
                if ($assign > 0 && !isset(fabo_crm_assignable($board)[$assign])) {
                    throw new InvalidArgumentException('That person cannot be assigned records on this CRM.');
                }
                $rid  = fabo_crm_record_create($board, $fields, $data, $stage, $who, $assign, $viewer);
                $ferr = fabo_crm_files_take($board, $fields, $rid, $who);
                $flash = $ferr ? ['err', 'Saved, but: ' . implode(' ', $ferr)]
                               : ['ok', $board['record_label'] . ' added.'];
                $back = !empty($_POST['another'])
                      ? $u(['crm' => $cid, 'tab' => 'new'])
                      : $u(['crm' => $cid, 'tab' => 'record', 'rec' => $rid]);
                break;

            case 'record_assign':
                // The assignee picker in a record's header, on its own.
                $rec  = $pick();
                $back = $u(['crm' => $cid, 'tab' => 'record', 'rec' => $rec['id']]);
                if ($only !== null) {
                    throw new InvalidArgumentException('Only someone who sees every record can reassign one.');
                }
                $flash = fabo_crm_assign($board, $rec, (int) ($_POST['assign'] ?? 0), $viewer)
                       ? ['ok', 'Assignment saved.'] : null;
                break;

            case 'record_save':
                $rec  = $pick();
                $back = $u(['crm' => $cid, 'tab' => 'record', 'rec' => $rec['id']]);
                [$data, $errs] = fabo_crm_from_post($fields, (array) ($_POST['f'] ?? []));
                $errs = array_merge($errs, fabo_crm_check($cid, $fields, array_merge($rec['d'], $data), (int) $rec['id'], $only !== null));
                if ($errs) {
                    throw new InvalidArgumentException(implode(' ', $errs));
                }
                fabo_crm_record_update($board, $fields, $rec, $data, 0, $who);
                if ($only === null && isset($_POST['assign'])) {
                    fabo_crm_assign($board, $rec, (int) $_POST['assign'], $viewer);
                }
                $ferr  = fabo_crm_files_take($board, $fields, (int) $rec['id'], $who);
                $flash = $ferr ? ['err', 'Saved, but: ' . implode(' ', $ferr)] : ['ok', 'Saved.'];
                break;

            case 'record_stage':
                /* A card dropped on another column, or a step chosen on the
                   record page. Answers in JSON when the board asked. */
                $rec = $pick();
                fabo_crm_set_stage($board, $rec, (int) ($_POST['stage'] ?? 0), $who);
                if ($json) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['ok' => true]);
                    exit;
                }
                $back = $u(['crm' => $cid, 'tab' => 'record', 'rec' => $rec['id']]);
                break;

            case 'record_note':
                $rec  = $pick();
                $note = trim((string) ($_POST['note'] ?? ''));
                $back = $u(['crm' => $cid, 'tab' => 'record', 'rec' => $rec['id']]);
                if ($note === '') {
                    throw new InvalidArgumentException('Write what happened first.');
                }
                // A note, or a call, meeting or email logged against the record.
                $kind = (string) ($_POST['kind'] ?? 'note');
                $kinds = ['note' => 'Note added.', 'call' => 'Call logged.', 'meeting' => 'Meeting logged.', 'email' => 'Email logged.'];
                if (!isset($kinds[$kind])) {
                    $kind = 'note';
                }
                fabo_crm_log($cid, (int) $rec['id'], $who, $kind, $note);
                fabo_exec('UPDATE crm_records SET updated_at = NOW() WHERE id = ?', 'i', [(int) $rec['id']]);
                $flash = ['ok', $kinds[$kind]];
                break;

            case 'record_delete':
                $rec = $pick();
                fabo_crm_record_delete($cid, (int) $rec['id']);
                $flash = ['ok', $board['record_label'] . ' #' . $rec['id'] . ' deleted.'];
                $back  = $u(['crm' => $cid, 'tab' => 'table']);
                break;

            case 'file_delete':
                $f = fabo_crm_file($cid, (int) ($_POST['file_id'] ?? 0));
                if ($f && $recOk(fabo_crm_record($cid, (int) $f['record_id']))) {
                    fabo_crm_file_delete($cid, (int) $f['id'], $who);
                    $back = $u(['crm' => $cid, 'tab' => 'record', 'rec' => $f['record_id']]);
                }
                $flash = ['ok', 'File removed.'];
                break;

            case 'bulk':
                $ids  = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
                $do   = (string) ($_POST['bulk'] ?? '');
                $back = (string) ($_POST['back'] ?? '');
                // Back into this desk only; anything else the form carries is replaced.
                if (!preg_match('~^crm\.php(\?[^\r\n#]*)?$~', $back)) {
                    $back = $u(['crm' => $cid, 'tab' => 'table']);
                }
                $recs = [];
                foreach ($ids as $id) {
                    $r = fabo_crm_record($cid, $id);
                    if ($recOk($r)) {
                        $recs[] = $r;
                    }
                }
                if (!$recs) {
                    throw new InvalidArgumentException('Tick the records first.');
                }
                $n = 0;
                if ($do === 'delete') {
                    foreach ($recs as $r) {
                        fabo_crm_record_delete($cid, (int) $r['id']);
                    }
                    $flash = ['ok', count($recs) . ' deleted.'];
                } elseif (str_starts_with($do, 'stage:')) {
                    $st = fabo_crm_stage($cid, (int) substr($do, 6));
                    if (!$st) {
                        throw new InvalidArgumentException('Choose a stage to move them to.');
                    }
                    foreach ($recs as $r) {
                        $n += fabo_crm_set_stage($board, $r, (int) $st['id'], $who) ? 1 : 0;
                    }
                    $flash = ['ok', $n . ' moved to ' . $st['name'] . '.'];
                } elseif ($do === 'email') {
                    // Carried to the Emails tab, which writes the message.
                    $_SESSION['fabo_crm_mail_ids'][$cid] = array_map(static fn($r) => (int) $r['id'], $recs);
                    $back = $u(['crm' => $cid, 'tab' => 'emails', 'to' => 'sel']);
                } elseif (str_starts_with($do, 'assign:') && $only === null) {
                    $pid = (int) substr($do, 7);
                    foreach ($recs as $r) {
                        $n += fabo_crm_assign($board, $r, $pid, $viewer) ? 1 : 0;
                    }
                    $people = fabo_crm_assignable($board);
                    $flash = ['ok', $n . ($pid ? ' assigned to ' . ($people[$pid] ?? 'them') : ' unassigned') . '.'];
                } else {
                    throw new InvalidArgumentException('Choose what to do with them.');
                }
                break;

            /* ------------------------------------------------- emails */

            case 'mail_tpl_save':
                $tid = fabo_crm_mail_template_save($board, $_POST, $who);
                $flash = ['ok', 'Template saved.'];
                $back  = $u(['crm' => $cid, 'tab' => 'emails', 'v' => 'templates']) . '#tpl' . $tid;
                break;

            case 'mail_tpl_delete':
                fabo_crm_mail_template_delete($cid, (int) ($_POST['tpl'] ?? 0));
                $flash = ['ok', 'Template deleted. Emails already sent with it are not affected.'];
                $back  = $u(['crm' => $cid, 'tab' => 'emails', 'v' => 'templates']);
                break;

            case 'mail_send':
                $to = (string) ($_POST['to'] ?? '0');
                $back = $u(['crm' => $cid, 'tab' => 'emails', 'to' => $to, 'tpl' => (int) ($_POST['tpl'] ?? 0)]);
                if ($to === 'sel') {
                    $recs = [];
                    foreach ((array) ($_SESSION['fabo_crm_mail_ids'][$cid] ?? []) as $rid) {
                        $r = fabo_crm_record($cid, (int) $rid);
                        if ($recOk($r)) {
                            $recs[] = $r;
                        }
                    }
                    $stageTo = 0;
                } else {
                    $stageTo = (int) $to;
                    if ($stageTo > 0 && !fabo_crm_stage($cid, $stageTo)) {
                        throw new InvalidArgumentException('That stage is not on this CRM.');
                    }
                    [$recs] = fabo_crm_records($cid, $fields, ['stage' => $stageTo, 'only' => $only, 'sort' => 'created',
                                                               'dir' => 'asc'], FABO_CRM_MAIL_MAX);
                }
                [, $nq, $skipped] = fabo_crm_mail_queue_send($board, $fields, $viewer, $recs,
                                                             array_merge($_POST, ['stage' => $stageTo]));
                unset($_SESSION['fabo_crm_mail_ids'][$cid]);
                $flash = ['ok', number_format($nq) . ' email' . ($nq === 1 ? '' : 's') . ' queued'
                              . ($skipped ? ' (left out: ' . fabo_crm_mail_skip_words($skipped) . ')' : '')
                              . '. They are going out now — you can watch them here, or leave this page.'];
                $back = $u(['crm' => $cid, 'tab' => 'emails', 'v' => 'sent']);
                break;

            case 'mail_cancel':
                $n = fabo_crm_mail_cancel($cid, (int) ($_POST['send'] ?? 0));
                $flash = ['ok', $n ? number_format($n) . ' email' . ($n === 1 ? '' : 's') . ' that had not gone yet were stopped.'
                                   : 'Nothing was waiting on that send.'];
                $back = $u(['crm' => $cid, 'tab' => 'emails', 'v' => 'sent']);
                break;

            case 'mail_retry':
                $n = fabo_crm_mail_retry($cid, (int) ($_POST['send'] ?? 0));
                $flash = ['ok', number_format($n) . ' failed email' . ($n === 1 ? '' : 's') . ' put back in the queue.'];
                $back = $u(['crm' => $cid, 'tab' => 'emails', 'v' => 'sent']);
                break;

            /* --------------------------------------------- application form */

            case 'form_items':
            case 'form_settings':
            case 'form_ack':
            case 'form_question':
            case 'form_toggle':
            case 'form_rotate':
                require_once __DIR__ . '/../lib/crm_forms.php';
                $cform = fabo_cf_for_board($board, true, $who);
                $back  = $u(['crm' => $cid, 'tab' => 'form']);
                if ($action === 'form_items') {
                    fabo_cf_save_items($cform, $board, (array) ($_POST['item'] ?? []));
                    $flash = ['ok', 'Questions saved.'];
                    $back .= '#questions';
                } elseif ($action === 'form_settings') {
                    fabo_cf_save_settings($cform, $board, $_POST);
                    $flash = ['ok', 'Settings saved.'];
                } elseif ($action === 'form_ack') {
                    fabo_cf_save_ack($cform, $_POST);
                    $flash = ['ok', !empty($_POST['ack_on']) ? 'Acknowledgement email saved. Applicants get it as soon as they apply.'
                                                             : 'Saved. Applicants will not be emailed.'];
                    $back .= '#ack';
                } elseif ($action === 'form_question') {
                    fabo_cf_add_question($cform, $board, $_POST);
                    $flash = ['ok', 'Question added to the form, and as a field on the CRM.'];
                    $back .= '#questions';
                } elseif ($action === 'form_toggle') {
                    fabo_exec('UPDATE crm_forms SET active = 1 - active WHERE id = ?', 'i', [(int) $cform['id']]);
                    $flash = ['ok', (int) $cform['active'] ? 'Form closed. The link now says it is not taking applications.' : 'Form open again.'];
                } else {
                    fabo_cf_rotate($cform);
                    $flash = ['ok', 'New link made. The old one no longer works — share the new one.'];
                }
                break;

            /* ------------------------------------------------- import */

            case 'import_upload':
                [$ok, $msg, $meta] = fabo_crm_stash($_FILES['file'] ?? []);
                if (!$ok) {
                    throw new InvalidArgumentException($msg);
                }
                // Read it once now, so a file that cannot be read says so here.
                $path = fabo_crm_stash_path($meta['token'], $meta['ext']);
                try {
                    $t = fabo_crm_read_file($path, $meta['ext']);
                } catch (Throwable $e) {
                    @unlink($path);
                    throw $e instanceof InvalidArgumentException ? $e
                        : new InvalidArgumentException('That file could not be read. Save it again as .xlsx or .csv and retry.');
                }
                if (!$t['rows']) {
                    @unlink($path);
                    throw new InvalidArgumentException('That file has a heading row but no rows under it.');
                }
                $_SESSION['fabo_crm_import'][$cid] = $meta + ['sheet' => 0];
                $back = $u(['crm' => $cid, 'tab' => 'import']);
                break;

            case 'import_cancel':
                $p = $_SESSION['fabo_crm_import'][$cid] ?? null;
                if ($p) {
                    @unlink(fabo_crm_stash_path($p['token'], $p['ext']));
                }
                unset($_SESSION['fabo_crm_import'][$cid]);
                $back = $u(['crm' => $cid, 'tab' => 'import']);
                break;

            case 'import_run':
                $p = $_SESSION['fabo_crm_import'][$cid] ?? null;
                $back = $u(['crm' => $cid, 'tab' => 'import']);
                if (!$p || !is_file(fabo_crm_stash_path($p['token'], $p['ext']))) {
                    unset($_SESSION['fabo_crm_import'][$cid]);
                    throw new InvalidArgumentException('The uploaded file has expired. Upload it again.');
                }
                $map  = (array) ($_POST['map'] ?? []);
                $seen = [];
                foreach ($map as $target) {
                    $target = (string) $target;
                    if ($target !== '' && $target !== '__new') {
                        if (isset($seen[$target])) {
                            $lab = ['__stage' => 'Stage', '__assign' => 'Assigned to'][$target]
                                ?? (fabo_crm_field_by_key($cid, $target)['label'] ?? $target);
                            throw new InvalidArgumentException('Two columns are mapped to “' . $lab
                                . '”. Map each field from one column only.');
                        }
                        $seen[$target] = true;
                    }
                }
                if (!$mayDesign && in_array('__new', array_map('strval', $map), true)) {
                    throw new InvalidArgumentException('Only the person who runs this CRM can create new fields.');
                }
                $path  = fabo_crm_stash_path($p['token'], $p['ext']);
                $table = fabo_crm_read_file($path, $p['ext'], (int) $p['sheet']);
                $imp   = fabo_crm_import_run($board, $table, [
                    'map'            => $map,
                    'default_stage'  => (int) ($_POST['default_stage'] ?? 0),
                    'create_stages'  => !empty($_POST['create_stages']) && $mayDesign,
                    'match'          => (string) ($_POST['match'] ?? ''),
                    'mode'           => (string) ($_POST['mode'] ?? 'skip'),
                    'add_options'    => !empty($_POST['add_options']) && $mayDesign,
                    'assign_default' => (int) ($_POST['assign_default'] ?? 0),
                    'only'           => $only,
                ], $viewer, (string) $p['name']);
                @unlink($path);
                unset($_SESSION['fabo_crm_import'][$cid]);
                $flash = [(int) $imp['created_n'] + (int) $imp['updated_n'] > 0 ? 'ok' : 'err',
                          'Import finished: ' . number_format((int) $imp['created_n']) . ' added, '
                        . number_format((int) $imp['updated_n']) . ' updated, '
                        . number_format((int) $imp['skipped_n']) . ' skipped'
                        . ((int) $imp['warning_n'] ? ', ' . number_format((int) $imp['warning_n']) . ' with warnings' : '')
                        . '. The details are in the import history below.'];
                $back = $u(['crm' => $cid, 'tab' => 'import', 'open' => $imp['id']]);
                break;

            case 'import_undo':
                if ($only !== null) {
                    throw new InvalidArgumentException('Ask the person who runs this CRM to undo an import.');
                }
                $n = fabo_crm_import_undo($cid, (int) ($_POST['import_id'] ?? 0));
                $flash = ['ok', 'Import undone: ' . number_format($n) . ' record' . ($n === 1 ? '' : 's')
                              . ' it added were removed. Records it only updated were left as they are.'];
                $back = $u(['crm' => $cid, 'tab' => 'import']);
                break;

            default:
                throw new InvalidArgumentException('That action is not recognised.');
        }
    } catch (InvalidArgumentException $e) {
        $flash = ['err', $e->getMessage()];
    } catch (Throwable $e) {
        $flash = ['err', fabo_db_words($e)];
    }
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => ($flash[0] ?? 'ok') === 'ok', 'error' => $flash[1] ?? '']);
        exit;
    }
    if ($flash) {
        $_SESSION['fabo_crm_flash'] = $flash;
    }
    header('Location: ' . $back);
    exit;
}

if (!empty($_SESSION['fabo_crm_flash'])) {
    $flash = $_SESSION['fabo_crm_flash'];
    unset($_SESSION['fabo_crm_flash']);
}

/* ------------------------------------------------------- what to show */

$view = (string) ($_GET['view'] ?? '') === 'dashboard' && !$board ? 'dashboard' : 'list';

$tabs = ['dashboard' => 'Dashboard', 'board' => 'Board', 'table' => 'Table', 'emails' => 'Emails', 'form' => 'Form',
         'fields' => 'Fields', 'stages' => 'Stages', 'import' => 'Import', 'access' => 'Access', 'settings' => 'Settings'];
if (!$mayEdit) {
    unset($tabs['import'], $tabs['emails'], $tabs['form']);
}
if (!$mayDesign) {
    unset($tabs['settings'], $tabs['access']);
}
$tab = (string) ($_GET['tab'] ?? 'dashboard');
if (!isset($tabs[$tab]) && !in_array($tab, ['record', 'new'], true)) {
    $tab = 'dashboard';
}
if ($tab === 'new' && !$mayEdit) {
    $tab = 'board';
}

$fields = $board ? fabo_crm_fields((int) $board['id']) : [];
$stages = $board ? fabo_crm_stages((int) $board['id']) : [];
$stageById = [];
foreach ($stages as $s) {
    $stageById[(int) $s['id']] = $s;
}
$people = $board ? fabo_crm_assignable($board) : [];
// Counts follow what this viewer can see, not what the board holds.
$total = 0;
if ($board) {
    [, $total] = fabo_crm_records((int) $board['id'], [], ['only' => $only], 1);
}
$search = trim((string) ($_GET['q'] ?? ''));
$types  = fabo_crm_types();
$recLow = $board ? mb_strtolower($board['record_label']) : '';

/** A stage as a coloured pill with its name. */
$stagePill = static function (?array $s): string {
    if (!$s) {
        return '<span class="crm-stage tone-muted">No stage</span>';
    }
    return '<span class="crm-stage crm-t-' . e($s['tone']) . '"><i></i>' . e($s['name']) . '</span>';
};

/* A value shown inside something that is already a link (a board card, the
   table's first column) loses its own mailto/tel/web link: a link inside a
   link is not allowed, and browsers break the card apart to escape it. */
$unlinked = static fn(string $html): string => strip_tags($html, '<span><i><b>');

/** The hidden fields every form on this desk carries. */
$hid = static function (string $action, string $tab = '') use ($board): string {
    return fabo_csrf_field()
         . '<input type="hidden" name="action" value="' . e($action) . '">'
         . ($board ? '<input type="hidden" name="crm" value="' . (int) $board['id'] . '">' : '')
         . ($tab !== '' ? '<input type="hidden" name="tab" value="' . e($tab) . '">' : '');
};

/** The token a GET form needs to carry for somebody on a private link. */
$tokenField = !$isAdmin && !fabo_signed_in()
    ? '<input type="hidden" name="k" value="' . e($viewer['client']['token']) . '">' : '';

/** A tile: a figure and what it is. */
$tile = static function (string $label, string $value, string $foot = '', string $tone = ''): string {
    return '<div class="crm-tile' . ($tone ? ' ' . e($tone) : '') . '"><span class="k">' . e($label) . '</span>'
         . '<span class="v">' . $value . '</span>' . ($foot !== '' ? '<span class="f">' . $foot . '</span>' : '') . '</div>';
};
$pct = static fn(?float $f): string => $f === null ? '—' : round($f * 100) . '%';

/** Who has access, in words. */
$accessWords = static function (array $a): string {
    if ($a['why'] === 'admin') { return 'Administrator'; }
    if ($a['why'] === 'owner') { return 'You run this CRM'; }
    return ($a['perm'] === 'edit' ? 'Can edit' : 'View only')
         . ($a['scope'] === 'own' ? ' · only records assigned to you' : '');
};

/** The two views of the CRM menu entry: the list of CRMs, and the dashboard over all of them. */
$hubNav = static function (string $on) use ($u): string {
    $items = ['list' => ['All CRMs', 'board', $u()], 'dashboard' => ['Dashboard', 'dashboard', $u(['view' => 'dashboard'])]];
    $h = '<nav class="crm-nav" aria-label="CRM"><div class="crm-seg" role="list">';
    foreach ($items as $k => [$label, $icon, $href]) {
        $h .= '<a role="listitem" class="' . ($k === $on ? 'on' : '') . '"' . ($k === $on ? ' aria-current="page"' : '')
            . ' href="' . e($href) . '">' . fabo_crm_icon($icon) . ' ' . e($label) . '</a>';
    }
    return $h . '</div></nav>';
};

fabo_head($viewer, $board ? $board['name'] . ' · CRM' : ($view === 'dashboard' ? 'CRM Dashboard' : 'CRM'),
          $view === 'dashboard' ? 'crm-dash' : 'crm');
echo '<link rel="stylesheet" href="' . e(fabo_asset(fabo_root_prefix() . 'assets/crm.css')) . '">';
?>
<div class="wrap-page crm" data-csrf="<?= e(fabo_csrf_token()) ?>">

<?php if (!$board && $view === 'dashboard'): ?>
  <?php /* ====================================================== CRM DASHBOARD */
    $boards = fabo_crm_boards_for($viewer);
    $rows = [];
    $sum  = ['total' => 0, 'open' => 0, 'won' => 0, 'lost' => 0, 'added30' => 0, 'prev30' => 0];
    $feed = [];
    foreach ($boards as $b) {
        $bOnly = $b['access']['scope'] === 'own' ? $me : null;
        $bst   = fabo_crm_stages((int) $b['id']);
        $st    = fabo_crm_stats($b, $bst, $bOnly);
        $rows[] = ['b' => $b, 's' => $st, 'stages' => $bst];
        foreach (array_keys($sum) as $k) { $sum[$k] += $st[$k]; }
        foreach (array_slice($st['activity'], 0, 6) as $a) { $a['board'] = $b; $feed[] = $a; }
    }
    usort($feed, static fn($x, $y) => strcmp($y['created_at'], $x['created_at']));
    $feed = array_slice($feed, 0, 12);
    $mine = [];
    if ($me > 0) {
        foreach ($boards as $b) {
            $bf = fabo_crm_fields((int) $b['id']);
            [$rs] = fabo_crm_records((int) $b['id'], $bf, ['assigned' => $me, 'sort' => 'updated'], 8);
            foreach ($rs as $r) { $r['board'] = $b; $r['title'] = fabo_crm_title($b, $bf, $r); $mine[] = $r; }
        }
        usort($mine, static fn($x, $y) => strcmp($y['updated_at'], $x['updated_at']));
        $mine = array_slice($mine, 0, 10);
    }
    $conv = ($sum['won'] + $sum['lost']) > 0 ? $sum['won'] / ($sum['won'] + $sum['lost']) : null;
    $delta = $sum['added30'] - $sum['prev30'];
  ?>
  <div class="pagehead crm-head">
    <div>
      <h1>CRM</h1>
      <div class="sub">Every CRM you can open, at a glance<?= $isAdmin ? '' : ' — only what you have access to' ?>.</div>
    </div>
    <div class="crm-head-acts">
      <?php if ($mayCreate): ?><a class="btn btn-primary crm-btn" href="<?= e($u()) ?>#new"><?= fabo_crm_icon('plus') ?> New CRM</a><?php endif; ?>
    </div>
  </div>
  <?= $hubNav('dashboard') ?>
  <?php if ($flash): ?><div class="flash <?= e($flash[0]) ?>" role="status"><?= e($flash[1]) ?></div><?php endif; ?>

  <?php if (!$boards): ?>
    <div class="card crm-emptystate">
      <h2>No CRMs yet</h2>
      <p><?= $mayCreate ? 'Create your first CRM, design its fields, then import your Excel data.'
                        : 'When a CRM is shared with you it appears here.' ?></p>
      <?php if ($mayCreate): ?><a class="btn btn-primary" href="<?= e($u()) ?>#new">Create a CRM</a><?php endif; ?>
    </div>
  <?php else: ?>
    <section class="crm-tiles" aria-label="Totals across your CRMs">
      <?= $tile('CRMs', number_format(count($boards))) ?>
      <?= $tile('Records', number_format($sum['total'])) ?>
      <?= $tile('In progress', number_format($sum['open']), '', 'info') ?>
      <?= $tile('Won', number_format($sum['won']), '', 'good') ?>
      <?= $tile('Lost', number_format($sum['lost']), '', 'risk') ?>
      <?= $tile('Conversion', $pct($conv), 'won ÷ (won + lost)') ?>
      <?= $tile('Added · 30 days', number_format($sum['added30']),
               ($delta >= 0 ? '▲ ' : '▼ ') . number_format(abs($delta)) . ' vs previous 30', $delta >= 0 ? 'good' : 'risk') ?>
    </section>

    <div class="card">
      <h2>Your CRMs</h2>
      <div class="crm-tablewrap">
        <table class="data crm-dashtable">
          <thead><tr><th scope="col">CRM</th><th scope="col">Stages</th><th scope="col" class="num">Records</th>
            <th scope="col" class="num">In progress</th><th scope="col" class="num">Won</th><th scope="col" class="num">Lost</th>
            <th scope="col" class="num">Conversion</th><th scope="col" class="num">Added 30d</th></tr></thead>
          <tbody>
          <?php foreach ($rows as $row): $b = $row['b']; $st = $row['s']; ?>
            <tr>
              <td><a href="<?= e($u(['crm' => $b['id']])) ?>"><b><?= e($b['name']) ?></b></a>
                <div class="crm-sub"><?= $isAdmin && (int) $b['client_id'] > 0 ? e($b['org_name'] ?: 'Client') . ' · ' : '' ?><?= e($accessWords($b['access'])) ?><?= $b['runner'] ? ' · run by ' . e($b['runner']) : '' ?></div></td>
              <td style="min-width:180px">
                <div class="crm-bar" role="img" aria-label="<?= e(implode(', ', array_map(
                    static fn($s) => $s['name'] . ' ' . ($st['by_stage'][(int) $s['id']] ?? 0), $row['stages']))) ?>">
                  <?php foreach ($row['stages'] as $s): $n = $st['by_stage'][(int) $s['id']] ?? 0; if (!$n) { continue; } ?>
                    <span class="crm-t-<?= e($s['tone']) ?>" style="flex:<?= $n ?>" data-tip="<?= e($s['name'] . ': ' . $n) ?>"></span>
                  <?php endforeach; ?>
                  <?php if (!$st['total']): ?><span class="crm-bar-empty" style="flex:1"></span><?php endif; ?>
                </div></td>
              <td class="num"><?= number_format($st['total']) ?></td>
              <td class="num"><?= number_format($st['open']) ?></td>
              <td class="num"><?= number_format($st['won']) ?></td>
              <td class="num"><?= number_format($st['lost']) ?></td>
              <td class="num"><?= $pct($st['conv']) ?></td>
              <td class="num"><?= number_format($st['added30']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="crm-cols2<?= $me > 0 ? '' : ' one' ?>">
      <?php if ($me > 0): ?>
      <div class="card">
        <h2>Assigned to you</h2>
        <?php if (!$mine): ?><div class="empty">Nothing is assigned to you right now.</div><?php else: ?>
          <ul class="crm-mylist">
            <?php foreach ($mine as $r): ?>
              <li><a href="<?= e($u(['crm' => $r['board']['id'], 'tab' => 'record', 'rec' => $r['id']])) ?>"><b><?= e($r['title']) ?></b></a>
                <span><?= e($r['board']['name']) ?></span>
                <?= $stagePill(['name' => $r['stage_name'] ?? '—', 'tone' => $r['stage_tone'] ?? 'muted']) ?>
                <small><?= e(fabo_short_date($r['updated_at'])) ?></small></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <div class="card">
        <h2>Latest activity</h2>
        <?php if (!$feed): ?><div class="empty">No activity yet.</div><?php else: ?>
          <ol class="crm-feed">
            <?php foreach ($feed as $a): ?>
              <li class="k-<?= e($a['kind']) ?>">
                <div><a href="<?= e($u(['crm' => $a['board']['id'], 'tab' => 'record', 'rec' => $a['record_id']])) ?>"><?= e($a['board']['name']) ?> #<?= (int) $a['record_id'] ?></a>
                  — <?= e(mb_strimwidth((string) $a['detail'], 0, 120, '…')) ?></div>
                <small><?= e($a['who']) ?> &middot; <?= e(date('j M, g:i a', strtotime($a['created_at']))) ?></small>
              </li>
            <?php endforeach; ?>
          </ol>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

<?php elseif (!$board): ?>
  <?php /* ============================================================ ALL CRMS */
    $boards = fabo_crm_boards_for($viewer);
  ?>
  <div class="pagehead crm-head">
    <div>
      <h1>CRM</h1>
      <div class="sub">Applications, leads and enquiries, each moving through its own stages.
        Design the fields, bring your Excel data in, and work it on a board.</div>
    </div>
    <div class="crm-head-acts">
      <?php if ($mayCreate): ?><a class="btn btn-primary crm-btn" href="#new" data-open="new"><?= fabo_crm_icon('plus') ?> New CRM</a><?php endif; ?>
    </div>
  </div>
  <?= $hubNav('list') ?>

  <?php if ($flash): ?><div class="flash <?= e($flash[0]) ?>" role="status"><?= e($flash[1]) ?></div><?php endif; ?>

  <?php if ($boards): ?>
  <div class="crm-boards">
    <?php foreach ($boards as $b):
        $bs = fabo_crm_stages((int) $b['id']);
        $bOnly = $b['access']['scope'] === 'own' ? $me : null;
        [, $bn] = fabo_crm_records((int) $b['id'], [], ['only' => $bOnly], 1); ?>
      <a class="crm-bcard" href="<?= e($u(['crm' => $b['id']])) ?>">
        <div class="crm-bcard-top">
          <span class="crm-ws-icon sm crm-t-<?= e(fabo_crm_board_tone($b)) ?>"><?= fabo_crm_icon('target', 18) ?></span>
          <h3><?= e($b['name']) ?></h3>
          <?php if ($isAdmin && (int) $b['client_id'] > 0): ?>
            <span class="crm-owner" title="Belongs to this client organisation"><?= e($b['org_name'] ?: 'Client') ?></span>
          <?php elseif ($b['access']['scope'] === 'own'): ?>
            <span class="crm-owner ours">Your records</span>
          <?php endif; ?>
        </div>
        <?php if ($b['description'] !== ''): ?><p><?= e($b['description']) ?></p><?php endif; ?>
        <div class="crm-bcard-n"><strong><?= number_format($bn) ?></strong>
          <?= e(mb_strtolower($b['record_label'])) ?><?= $bn === 1 ? '' : 's' ?></div>
        <?php if ($bOnly === null): ?>
          <div class="crm-bar" aria-hidden="true">
            <?php foreach ($bs as $s): if (!(int) $s['records']) { continue; } ?>
              <span class="crm-t-<?= e($s['tone']) ?>" style="flex:<?= (int) $s['records'] ?>"></span>
            <?php endforeach; ?>
            <?php if (!$bn): ?><span class="crm-bar-empty" style="flex:1"></span><?php endif; ?>
          </div>
        <?php endif; ?>
        <div class="crm-bcard-foot">
          <?= $b['runner'] ? fabo_crm_avatar($b['runner']) . ' Run by ' . e($b['runner']) : 'Run by ' . e($b['created_by'] ?: 'the administrator') ?>
          <?php if ($b['access']['why'] !== 'admin'): ?>&middot; <?= e($accessWords($b['access'])) ?><?php endif; ?></div>
      </a>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
    <div class="card crm-emptystate">
      <h2>No CRMs yet</h2>
      <p><?= $mayCreate ? 'Create one below. Pick a starting layout — you can change every field and stage afterwards.'
                        : 'Nothing has been shared with you yet. When a CRM is, it appears here.' ?></p>
    </div>
  <?php endif; ?>

  <?php if ($mayCreate):
      $owners = fabo_crm_owner_choices($viewer); ?>
  <details class="card crm-create" id="new"<?= $boards ? '' : ' open' ?>>
    <summary><span class="crm-ws-icon sm crm-t-info"><?= fabo_crm_icon('plus', 18) ?></span>
      <span><b>Create a new CRM</b><small>Pick a starting layout — every field and stage can be changed afterwards.</small></span>
      <span class="crm-sum-ch" aria-hidden="true"><?= fabo_crm_icon('chevron') ?></span></summary>
    <form method="post" action="<?= e($u()) ?>">
      <?= $hid('board_create') ?>
      <fieldset class="crm-fs">
        <legend class="crm-lbl">1 &middot; Start from</legend>
        <div class="crm-tpls">
          <?php $first = true; foreach (fabo_crm_templates() as $tk => $tp): ?>
            <label class="crm-tpl">
              <input type="radio" name="template" value="<?= e($tk) ?>" <?= $first ? 'checked' : '' ?>
                     data-record="<?= e($tp['record']) ?>">
              <span><b><?= e($tp['label']) ?></b><small><?= e($tp['hint']) ?></small>
                <em><?= e(implode(' → ', array_column($tp['stages'], 0))) ?></em></span>
            </label>
          <?php $first = false; endforeach; ?>
        </div>
      </fieldset>
      <div class="crm-lbl">2 &middot; Name it</div>
      <div class="sb-row">
        <div class="sb-field grow"><label for="crmNewName">Name</label>
          <input id="crmNewName" name="name" required maxlength="120" placeholder="e.g. MBA Admissions 2026"></div>
        <div class="sb-field"><label for="crmRecordLabel">Each record is called</label>
          <input name="record_label" id="crmRecordLabel" maxlength="60" placeholder="Application"></div>
        <div class="sb-field"><label for="crmOwner">Belongs to <small>(runs it)</small></label>
          <select name="owner_person" id="crmOwner">
            <?php foreach ($owners as $oid => $on): ?>
              <option value="<?= (int) $oid ?>"><?= e($on) ?></option>
            <?php endforeach; ?>
          </select></div>
      </div>
      <div class="sb-row" style="margin-top:12px">
        <div class="sb-field grow"><label for="crmNewDesc">Description <small>(optional)</small></label>
          <input id="crmNewDesc" name="description" maxlength="255"></div>
      </div>
      <p class="crm-hint">It belongs to <?= $isAdmin ? 'your own team' : 'your organisation' ?>. The person it belongs to
        runs it: fields, stages and who has access. Share it with others from its <b>Access</b> tab, or from a
        person’s page under <b>CRM access</b>.</p>
      <button class="btn btn-primary">Create CRM</button>
    </form>
  </details>
  <?php endif; ?>

<?php else: ?>
  <?php /* ============================================================ ONE CRM */ ?>
  <?php
    $workTabs  = ['dashboard' => ['Dashboard', 'dashboard'], 'board' => ['Board', 'board'], 'table' => ['List', 'list']];
    if ($mayEdit) {
        $workTabs['emails'] = ['Emails', 'mail'];
    }
    $setupTabs = ['form' => ['Form', 'inbox'], 'fields' => ['Fields', 'fields'], 'stages' => ['Stages', 'stages'], 'import' => ['Import', 'upload'],
                  'access' => ['Access', 'lock'], 'settings' => ['Settings', 'cog']];
  ?>
  <header class="crm-ws">
    <div class="crm-ws-id">
      <span class="crm-ws-icon crm-t-<?= e(fabo_crm_board_tone($board)) ?>"><?= fabo_crm_icon('target', 22) ?></span>
      <div>
        <nav class="crm-crumb" aria-label="Breadcrumb"><a href="<?= e($u()) ?>">All CRMs</a> <span aria-hidden="true">/</span></nav>
        <h1><?= e($board['name']) ?></h1>
        <div class="crm-ws-meta">
          <span><b><?= number_format($total) ?></b> <?= e($recLow) ?><?= $total === 1 ? '' : 's' ?><?= $only !== null ? ' assigned to you' : '' ?></span>
          <span class="crm-ws-badge <?= $mayEdit ? 'edit' : 'view' ?>"><?= fabo_crm_icon($mayEdit ? 'note' : 'lock', 12) ?> <?= e($accessWords($access)) ?></span>
        </div>
      </div>
    </div>
    <div class="crm-ws-acts">
      <a class="btn crm-btn" href="<?= e($u(['crm' => $board['id'], 'export' => 1])) ?>"><?= fabo_crm_icon('download') ?> Export</a>
      <?php if ($mayEdit): ?>
        <a class="btn crm-btn" href="<?= e($u(['crm' => $board['id'], 'tab' => 'import'])) ?>"><?= fabo_crm_icon('upload') ?> Import Excel</a>
        <a class="btn btn-primary crm-btn" href="<?= e($u(['crm' => $board['id'], 'tab' => 'new'])) ?>"><?= fabo_crm_icon('plus') ?> New <?= e($recLow) ?></a>
      <?php endif; ?>
    </div>
  </header>

  <nav class="crm-nav" aria-label="CRM sections">
    <div class="crm-seg" role="list">
      <?php foreach ($workTabs as $tk => [$tl, $ic]):
          $on = $tab === $tk || ($tk === 'table' && in_array($tab, ['record', 'new'], true)); ?>
        <a role="listitem" class="<?= $on ? 'on' : '' ?>"<?= $on ? ' aria-current="page"' : '' ?>
           href="<?= e($u(['crm' => $board['id'], 'tab' => $tk])) ?>"><?= fabo_crm_icon($ic) ?> <?= e($tl) ?></a>
      <?php endforeach; ?>
    </div>
    <?php $setupShown = array_intersect_key($setupTabs, $tabs); if ($setupShown): ?>
      <div class="crm-setup" role="list" aria-label="Setup">
        <span class="crm-setup-l"><?= fabo_crm_icon('sliders', 14) ?> Setup</span>
        <?php foreach ($setupShown as $tk => [$tl, $ic]): $on = $tab === $tk; ?>
          <a role="listitem" class="<?= $on ? 'on' : '' ?>"<?= $on ? ' aria-current="page"' : '' ?>
             href="<?= e($u(['crm' => $board['id'], 'tab' => $tk])) ?>"><?= fabo_crm_icon($ic, 14) ?> <?= e($tl) ?></a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </nav>

  <?php if ($flash): ?><div class="flash <?= e($flash[0]) ?>" role="status"><?= e($flash[1]) ?></div><?php endif; ?>

  <?php if (!$fields && in_array($tab, ['board', 'table', 'dashboard'], true)): ?>
    <div class="flash err">This CRM has no fields yet. <?= $mayDesign
        ? '<a href="' . e($u(['crm' => $board['id'], 'tab' => 'fields'])) . '">Open the Field Studio</a> and add what each record should hold.'
        : 'Ask the person who runs it to add some.' ?></div>
  <?php endif; ?>

<?php /* ======================================================== DASHBOARD */ ?>
<?php if ($tab === 'dashboard'):
    $breakable = array_values(array_filter($fields, static fn($f) => in_array($f['type'], ['select', 'radio', 'checkbox', 'rating'], true)));
    $by = (string) ($_GET['by'] ?? ($breakable[0]['field_key'] ?? ''));
    $byField = null;
    foreach ($breakable as $f) { if ($f['field_key'] === $by) { $byField = $f; } }
    if (!$byField) { $by = ''; }
    $st = fabo_crm_stats($board, $stages, $only, $by);
    $delta = $st['added30'] - $st['prev30'];
    /* What to do next, until it is all done. Shown to whoever runs it. */
    $shared = (bool) fabo_one('SELECT 1 AS x FROM crm_access WHERE crm_id = ? LIMIT 1', 'i', [(int) $board['id']]);
    $steps = [
        ['Design the fields', 'What each ' . $recLow . ' holds — name, phone, course…', count($fields) > 0, 'fields', 'Open Fields'],
        ['Set your stages', 'The journey from new to won or lost', count($stages) > 1, 'stages', 'Open Stages'],
        ['Bring your data in', 'Import an Excel file, or add records one by one', $total > 0, 'import', 'Import Excel'],
        ['Share with your team', 'Give people access — all records, or only theirs', $shared, 'access', 'Open Access'],
    ];
    $doneN = count(array_filter($steps, static fn($s) => $s[2]));
?>
  <?php if ($mayDesign && $doneN < count($steps)): ?>
    <section class="card crm-start" aria-labelledby="crmStartH">
      <div class="crm-start-head">
        <h2 id="crmStartH">Getting started</h2>
        <span><?= $doneN ?> of <?= count($steps) ?> done</span>
        <div class="crm-start-bar" role="progressbar" aria-valuemin="0" aria-valuemax="<?= count($steps) ?>" aria-valuenow="<?= $doneN ?>"><i style="width:<?= $doneN / count($steps) * 100 ?>%"></i></div>
      </div>
      <ol class="crm-start-steps">
        <?php foreach ($steps as $i => [$sTitle, $sHint, $sDone, $sTab, $sBtn]): ?>
          <li class="<?= $sDone ? 'done' : '' ?>">
            <span class="crm-start-n"><?= $sDone ? fabo_crm_icon('check', 14) : $i + 1 ?></span>
            <div><b><?= e($sTitle) ?></b><small><?= e($sHint) ?></small></div>
            <?php if (!$sDone && isset($tabs[$sTab])): ?>
              <a class="btn btn-sm" href="<?= e($u(['crm' => $board['id'], 'tab' => $sTab])) ?>"><?= e($sBtn) ?> →</a>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ol>
    </section>
  <?php endif; ?>

  <?php if ($st['total'] === 0): ?>
    <div class="card crm-emptystate">
      <?= fabo_crm_icon('inbox', 36) ?>
      <h2><?= $only !== null ? 'Nothing is assigned to you yet' : 'No ' . e($recLow) . 's yet' ?></h2>
      <p><?= $only !== null ? 'When a ' . e($recLow) . ' is assigned to you, it appears here and you are notified.'
                            : 'The dashboard fills in as soon as there is data. Bring your Excel file in, or add the first one by hand.' ?></p>
      <?php if ($mayEdit): ?>
        <span class="crm-emptyacts" style="justify-content:center">
          <a class="btn btn-primary" href="<?= e($u(['crm' => $board['id'], 'tab' => 'import'])) ?>"><?= fabo_crm_icon('upload') ?> Import Excel</a>
          <a class="btn" href="<?= e($u(['crm' => $board['id'], 'tab' => 'new'])) ?>"><?= fabo_crm_icon('plus') ?> Add one</a>
        </span>
      <?php endif; ?>
    </div>
  <?php else: ?>
  <section class="crm-tiles" aria-label="Key figures">
    <?= $tile('Total', number_format($st['total'])) ?>
    <?= $tile('In progress', number_format($st['open']), '', 'info') ?>
    <?= $tile('Won', number_format($st['won']), '', 'good') ?>
    <?= $tile('Lost', number_format($st['lost']), '', 'risk') ?>
    <?= $tile('Conversion', $pct($st['conv']), 'won ÷ (won + lost)') ?>
    <?= $tile('Added · 30 days', number_format($st['added30']),
             ($delta >= 0 ? '▲ ' : '▼ ') . number_format(abs($delta)) . ' vs previous 30', $delta >= 0 ? 'good' : 'risk') ?>
    <?php if ($only === null): ?>
      <?= $tile('Unassigned', number_format($st['unassigned']),
               $st['unassigned'] ? '<a href="' . e($u(['crm' => $board['id'], 'tab' => 'table', 'assigned' => -1])) . '">assign them →</a>' : '',
               $st['unassigned'] ? 'warn' : '') ?>
    <?php endif; ?>
  </section>

  <div class="crm-cols2">
    <div class="card">
      <h2>By stage</h2>
      <?= fabo_crm_hbars(array_map(static fn($s) => [$s['name'], $st['by_stage'][(int) $s['id']] ?? 0, $s['tone'],
                                    $u(['crm' => $board['id'], 'tab' => 'table', 'stage' => $s['id']])], $stages),
                         $st['total'], 'No records yet — import a file or add one.') ?>
    </div>
    <div class="card">
      <h2>Added per week <small class="crm-h2sub">last 12 weeks</small></h2>
      <?= fabo_crm_weeks_chart($st['weeks']) ?>
    </div>
  </div>

  <div class="crm-cols2">
    <div class="card">
      <div class="crm-cardhead">
        <h2>Split by <?= $byField ? e($byField['label']) : 'a field' ?></h2>
        <?php if (count($breakable) > 1): ?>
          <form method="get" action="crm.php" class="crm-inline">
            <?= $tokenField ?>
            <input type="hidden" name="crm" value="<?= (int) $board['id'] ?>"><input type="hidden" name="tab" value="dashboard">
            <label for="crmBy" class="crm-vh">Split by</label>
            <select name="by" id="crmBy" onchange="this.form.submit()">
              <?php foreach ($breakable as $f): ?>
                <option value="<?= e($f['field_key']) ?>"<?= $f['field_key'] === $by ? ' selected' : '' ?>><?= e($f['label']) ?></option>
              <?php endforeach; ?>
            </select>
            <noscript><button class="btn btn-sm">Show</button></noscript>
          </form>
        <?php endif; ?>
      </div>
      <?php if (!$byField): ?>
        <div class="empty">Add a dropdown, single choice, yes/no or rating field to split the records by it.</div>
      <?php else:
          $items = [];
          $i = 0;
          $other = 0;
          foreach ($st['break'] as $label => $n) {
              if ($byField['type'] === 'checkbox') { $label = $label === '1' ? 'Yes' : ($label === 'Not filled' ? $label : 'No'); }
              if ($byField['type'] === 'rating' && $label !== 'Not filled') { $label = str_repeat('★', (int) $label) ?: '0 ★'; }
              if ($i++ < 8) { $items[] = [$label, $n]; } else { $other += $n; }
          }
          if ($other) { $items[] = ['Other', $other]; }
          echo fabo_crm_hbars($items, $st['total']);
      endif; ?>
    </div>
    <div class="card">
      <h2><?= $only === null ? 'By person' : 'Your records' ?></h2>
      <?php if (!$st['people']): ?><div class="empty">No records yet.</div><?php else: ?>
        <table class="data crm-peopletable">
          <thead><tr><th scope="col">Assigned to</th><th scope="col" class="num">In progress</th><th scope="col" class="num">Won</th>
            <th scope="col" class="num">Lost</th><th scope="col" class="num">Total</th><th scope="col" class="num">Conversion</th></tr></thead>
          <tbody>
          <?php foreach ($st['people'] as $pid => $pp): $pc = ($pp['won'] + $pp['lost']) ? $pp['won'] / ($pp['won'] + $pp['lost']) : null; ?>
            <tr>
              <td><a href="<?= e($u(['crm' => $board['id'], 'tab' => 'table', 'assigned' => $pid ?: -1])) ?>">
                <?= $pid ? fabo_crm_avatar($pp['name']) : '' ?><?= e($pp['name']) ?></a></td>
              <td class="num"><?= number_format($pp['open']) ?></td>
              <td class="num"><?= number_format($pp['won']) ?></td>
              <td class="num"><?= number_format($pp['lost']) ?></td>
              <td class="num"><b><?= number_format($pp['total']) ?></b></td>
              <td class="num"><?= $pct($pc) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <h2>Latest activity</h2>
    <?php if (!$st['activity']): ?><div class="empty">No activity yet.</div><?php else: ?>
      <ol class="crm-feed crm-feed-wide">
        <?php foreach ($st['activity'] as $a): ?>
          <li class="k-<?= e($a['kind']) ?>">
            <div><a href="<?= e($u(['crm' => $board['id'], 'tab' => 'record', 'rec' => $a['record_id']])) ?>">#<?= (int) $a['record_id'] ?></a>
              — <?= e(mb_strimwidth((string) $a['detail'], 0, 160, '…')) ?></div>
            <small><?= e($a['who']) ?> &middot; <?= e(date('j M, g:i a', strtotime($a['created_at']))) ?></small>
          </li>
        <?php endforeach; ?>
      </ol>
    <?php endif; ?>
  </div>
  <?php endif; /* any records */ ?>

<?php /* ============================================================ BOARD */ ?>
<?php elseif ($tab === 'board' || $tab === 'table'):
    $isList = $tab === 'table';
    $asg    = (int) ($_GET['assigned'] ?? 0);
    $stageF = $isList ? (int) ($_GET['stage'] ?? 0) : 0;
    $cnt    = fabo_crm_counts((int) $board['id'], $only, $me);
    /** A link that keeps the other filters and changes one. */
    $fq     = ['crm' => $board['id'], 'tab' => $tab, 'q' => $search, 'assigned' => $asg ?: '', 'stage' => $stageF ?: ''];
    $fl     = static fn(array $ch) => $u(array_merge($fq, $ch, ['page' => '']));
    $chip   = static function (string $label, int $n, bool $on, string $href, string $dot = ''): string {
        return '<a class="crm-chip' . ($on ? ' on' : '') . '"' . ($on ? ' aria-current="true"' : '') . ' href="' . e($href) . '">'
             . ($dot !== '' ? '<i class="crm-t-' . e($dot) . '"></i>' : '') . e($label)
             . ' <span>' . number_format($n) . '</span></a>';
    };
?>
  <div class="crm-filters">
    <form method="get" action="crm.php" role="search" class="crm-searchbox">
      <?= $tokenField ?>
      <input type="hidden" name="crm" value="<?= (int) $board['id'] ?>">
      <input type="hidden" name="tab" value="<?= e($tab) ?>">
      <?php if ($asg): ?><input type="hidden" name="assigned" value="<?= $asg ?>"><?php endif; ?>
      <?php if ($stageF): ?><input type="hidden" name="stage" value="<?= $stageF ?>"><?php endif; ?>
      <?= fabo_crm_icon('search') ?>
      <label for="crmQ" class="crm-vh">Search</label>
      <input type="search" id="crmQ" name="q" value="<?= e($search) ?>" placeholder="Search name, email, phone, anything…">
      <?php if ($search !== ''): ?><a class="crm-x" href="<?= e($fl(['q' => ''])) ?>" aria-label="Clear search"><?= fabo_crm_icon('x', 14) ?></a><?php endif; ?>
    </form>
    <?php if ($only === null): ?>
      <div class="crm-chips" aria-label="Whose records">
        <?= $chip('All', $cnt['all'], !$asg, $fl(['assigned' => ''])) ?>
        <?php if ($me > 0 && isset($people[$me])): ?><?= $chip('Mine', $cnt['mine'], $asg === $me, $fl(['assigned' => $me])) ?><?php endif; ?>
        <?= $chip('Unassigned', $cnt['none'], $asg === -1, $fl(['assigned' => -1])) ?>
        <?php if (count($people) > 1): ?>
          <form method="get" action="crm.php" class="crm-chipsel">
            <?= $tokenField ?>
            <?php foreach (['crm' => $board['id'], 'tab' => $tab, 'q' => $search, 'stage' => $stageF ?: ''] as $hk => $hv): if ($hv === '') { continue; } ?>
              <input type="hidden" name="<?= e($hk) ?>" value="<?= e($hv) ?>">
            <?php endforeach; ?>
            <label for="crmAsg" class="crm-vh">Assigned to</label>
            <select name="assigned" id="crmAsg" onchange="this.form.submit()" class="<?= $asg > 0 && $asg !== $me ? 'on' : '' ?>">
              <option value="">Someone else…</option>
              <?php foreach ($people as $pid => $pn): if ($pid === $me) { continue; } ?>
                <option value="<?= $pid ?>"<?= $asg === $pid ? ' selected' : '' ?>><?= e($pn) ?></option>
              <?php endforeach; ?>
            </select>
            <noscript><button class="btn btn-sm">Go</button></noscript>
          </form>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
  <?php if ($isList): ?>
    <div class="crm-chips crm-stagechips" aria-label="Stage">
      <?= $chip('Every stage', $cnt['all'], !$stageF, $fl(['stage' => ''])) ?>
      <?php foreach ($stages as $s): ?>
        <?= $chip($s['name'], $cnt['stage'][(int) $s['id']] ?? 0, $stageF === (int) $s['id'], $fl(['stage' => $s['id']]), $s['tone']) ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

<?php if (!$isList):
    $perStage = 60;
?>
  <?php if ($mayEdit): ?><p class="crm-tip-line"><?= fabo_crm_icon('board', 14) ?> Drag a card to another column to move it to that stage. Click a card to open it.</p><?php endif; ?>
  <div class="crm-kanban<?= $mayEdit ? ' can-drag' : '' ?>" data-crm="<?= (int) $board['id'] ?>"
       data-post="<?= e($u(['crm' => $board['id']])) ?>">
    <?php foreach ($stages as $s):
        [$cards, $n] = fabo_crm_records((int) $board['id'], $fields,
                                        ['stage' => $s['id'], 'search' => $search, 'sort' => 'updated',
                                         'only' => $only, 'assigned' => $asg], $perStage); ?>
      <section class="crm-col crm-t-<?= e($s['tone']) ?>" data-stage="<?= (int) $s['id'] ?>" aria-label="<?= e($s['name']) ?>, <?= $n ?> records">
        <header>
          <i></i><b><?= e($s['name']) ?></b><span class="crm-count"><?= number_format($n) ?></span>
          <?php if ($s['kind'] !== 'open'): ?><em><?= $s['kind'] === 'won' ? 'Won' : 'Lost' ?></em><?php endif; ?>
          <?php if ($mayEdit): ?>
            <a class="crm-coladd" href="<?= e($u(['crm' => $board['id'], 'tab' => 'emails', 'to' => $s['id']])) ?>"
               title="Email everyone in <?= e($s['name']) ?>" aria-label="Email everyone in <?= e($s['name']) ?>"><?= fabo_crm_icon('mail', 14) ?></a>
            <a class="crm-coladd" href="<?= e($u(['crm' => $board['id'], 'tab' => 'new', 'stage' => $s['id']])) ?>"
               title="Add a <?= e($recLow) ?> to <?= e($s['name']) ?>" aria-label="Add a <?= e($recLow) ?> to <?= e($s['name']) ?>"><?= fabo_crm_icon('plus', 14) ?></a>
          <?php endif; ?>
        </header>
        <div class="crm-cards">
          <?php foreach ($cards as $r):
              $title = fabo_crm_title($board, $fields, $r);
              $ct    = fabo_crm_contact($fields, $r['d']);
              $shown = 0; ?>
            <a class="crm-card" draggable="<?= $mayEdit ? 'true' : 'false' ?>" data-rec="<?= (int) $r['id'] ?>"
               href="<?= e($u(['crm' => $board['id'], 'tab' => 'record', 'rec' => $r['id']])) ?>">
              <span class="crm-card-top">
                <strong><?= e($title) ?></strong>
                <?= $r['assignee'] ? fabo_crm_avatar($r['assignee']) : '<span class="crm-av crm-av-none" title="Unassigned">?</span>' ?>
              </span>
              <?php if ($ct['phone'] !== ''): ?><span class="crm-card-c"><?= fabo_crm_icon('phone', 12) ?> <?= e($ct['phone']) ?></span><?php endif; ?>
              <?php if ($ct['email'] !== ''): ?><span class="crm-card-c"><?= fabo_crm_icon('mail', 12) ?> <?= e($ct['email']) ?></span><?php endif; ?>
              <?php foreach ($fields as $f):
                  if ($shown >= 2 || empty($f['in_list']) || $f['field_key'] === $board['title_field']
                      || in_array($f['type'], ['file', 'textarea', 'email', 'phone', 'autonumber'], true)) { continue; }
                  $v = $r['d'][$f['field_key']] ?? null;
                  if (fabo_crm_blank($v) || (string) (is_array($v) ? '' : $v) === $title) { continue; }
                  $shown++; ?>
                <span class="crm-card-f"><small><?= e($f['label']) ?></small> <?= $unlinked(fabo_crm_show($f, $v, true)) ?></span>
              <?php endforeach; ?>
              <span class="crm-card-foot"><?= fabo_crm_icon('clock', 12) ?> <?= e(fabo_crm_ago($r['updated_at'])) ?></span>
            </a>
          <?php endforeach; ?>
          <?php if ($n > $perStage): ?>
            <a class="crm-more" href="<?= e($u(['crm' => $board['id'], 'tab' => 'table', 'stage' => $s['id'], 'q' => $search])) ?>">
              + <?= number_format($n - $perStage) ?> more in the list</a>
          <?php elseif (!$n): ?>
            <div class="crm-none"><?= $search !== '' ? 'Nothing matching' : 'Drop cards here' ?></div>
          <?php endif; ?>
        </div>
      </section>
    <?php endforeach; ?>
  </div>

<?php else: /* ------------------------------------------------- LIST */
    $sort   = (string) ($_GET['sort'] ?? 'updated');
    $dir    = (string) ($_GET['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
    $page   = max(1, (int) ($_GET['page'] ?? 1));
    $per    = 50;
    [$rows, $n] = fabo_crm_records((int) $board['id'], $fields,
                                   ['stage' => $stageF, 'search' => $search, 'sort' => $sort, 'dir' => $dir,
                                    'only' => $only, 'assigned' => $asg], $per, ($page - 1) * $per);
    $pages = max(1, (int) ceil($n / $per));
    // The title leads the row; email and phone sit under it; the rest are columns.
    $cols  = array_values(array_filter($fields, static fn($f) => !empty($f['in_list'])
                && !in_array($f['type'], ['file', 'email', 'phone', 'textarea'], true)
                && $f['field_key'] !== $board['title_field']));
    $tq    = $fq + ['sort' => $sort, 'dir' => $dir];
    $sortLink = static function (string $key, string $label) use ($u, $tq, $sort, $dir): string {
        $on   = $sort === $key;
        $next = $on && $dir === 'asc' ? 'desc' : 'asc';
        return '<a class="crm-sort' . ($on ? ' on' : '') . '" href="' . e($u(array_merge($tq, ['sort' => $key, 'dir' => $next, 'page' => '']))) . '"'
             . ($on ? ' aria-sort="' . ($dir === 'asc' ? 'ascending' : 'descending') . '"' : '') . '>'
             . e($label) . ($on ? ($dir === 'asc' ? ' ↑' : ' ↓') : '') . '</a>';
    };
?>
  <form method="post" action="<?= e($u(['crm' => $board['id']])) ?>" id="crmBulk">
    <?= $hid('bulk', 'table') ?>
    <input type="hidden" name="back" value="<?= e($u($tq + ['page' => $page > 1 ? $page : ''])) ?>">
    <div class="crm-listcard">
      <div class="crm-listhead">
        <span role="status"><b><?= number_format($n) ?></b> <?= e($recLow) ?><?= $n === 1 ? '' : 's' ?><?= $search !== '' ? ' matching “' . e($search) . '”' : '' ?></span>
        <?php if ($mayEdit && $rows): ?>
          <div class="crm-bulk" id="crmBulkBar" hidden>
            <span id="crmBulkN">0 selected</span>
            <label for="crmBulkDo" class="crm-vh">Action</label>
            <select name="bulk" id="crmBulkDo">
              <option value="">Choose an action…</option>
              <optgroup label="Move to stage">
                <?php foreach ($stages as $s): ?><option value="stage:<?= (int) $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?>
              </optgroup>
              <?php if ($only === null && $people): ?>
                <optgroup label="Assign to">
                  <?php foreach ($people as $pid => $pn): ?><option value="assign:<?= $pid ?>"><?= e($pn) ?></option><?php endforeach; ?>
                  <option value="assign:0">Nobody (unassign)</option>
                </optgroup>
              <?php endif; ?>
              <option value="email">Send them an email…</option>
              <option value="delete">Delete them</option>
            </select>
            <button class="btn btn-sm btn-primary" onclick="var s=this.form.bulk.value;return s!=='delete'||confirm('Delete the selected records? This cannot be undone.')">Apply</button>
          </div>
        <?php endif; ?>
      </div>
      <div class="crm-tablewrap">
        <table class="crm-table">
          <thead><tr>
            <?php if ($mayEdit): ?><th class="crm-tick"><input type="checkbox" id="crmAll" aria-label="Select all on this page"></th><?php endif; ?>
            <th scope="col"><?= $sortLink($board['title_field'] ?: 'created', 'Name') ?></th>
            <?php foreach ($cols as $f): ?><th scope="col"><?= $sortLink($f['field_key'], $f['label']) ?></th><?php endforeach; ?>
            <th scope="col"><?= $sortLink('stage', 'Stage') ?></th>
            <th scope="col"><?= $sortLink('assigned', 'Owner') ?></th>
            <th scope="col"><?= $sortLink('updated', 'Last activity') ?></th>
          </tr></thead>
          <tbody>
          <?php foreach ($rows as $r):
              $href  = $u(['crm' => $board['id'], 'tab' => 'record', 'rec' => $r['id']]);
              $title = fabo_crm_title($board, $fields, $r);
              $ct    = fabo_crm_contact($fields, $r['d']); ?>
            <tr data-href="<?= e($href) ?>">
              <?php if ($mayEdit): ?><td class="crm-tick"><input type="checkbox" name="ids[]" value="<?= (int) $r['id'] ?>" aria-label="Select <?= e($title) ?>"></td><?php endif; ?>
              <td class="crm-namecell">
                <span class="crm-rav crm-t-<?= e($r['stage_tone'] ?? 'muted') ?>" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($title, 0, 1))) ?></span>
                <span><a href="<?= e($href) ?>"><?= e($title) ?></a>
                  <small><?= e(trim($ct['email'] . ($ct['email'] !== '' && $ct['phone'] !== '' ? ' · ' : '') . $ct['phone'])) ?: '#' . (int) $r['id'] ?></small></span>
              </td>
              <?php foreach ($cols as $f): ?>
                <td><?= fabo_crm_show($f, $r['d'][$f['field_key']] ?? null, true) ?: '<span class="crm-dash">—</span>' ?></td>
              <?php endforeach; ?>
              <td><?= $stagePill($stageById[(int) $r['stage_id']] ?? null) ?></td>
              <td class="crm-ownercell"><?= $r['assignee'] ? fabo_crm_avatar($r['assignee']) . ' <span>' . e($r['assignee']) . '</span>' : '<span class="crm-dash">Unassigned</span>' ?></td>
              <td class="crm-when" title="<?= e(date('j M Y, g:i a', strtotime($r['updated_at']))) ?>"><?= e(fabo_crm_ago($r['updated_at'])) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php if (!$rows): ?>
          <div class="crm-emptyrow">
            <?= fabo_crm_icon('inbox', 32) ?>
            <b><?= $search !== '' || $stageF || $asg ? 'Nothing matches that' : 'No ' . e($recLow) . 's yet' ?></b>
            <?php if ($mayEdit && $search === '' && !$stageF && !$asg): ?>
              <span>Bring your Excel data in, or add the first one by hand.</span>
              <span class="crm-emptyacts">
                <a class="btn btn-primary" href="<?= e($u(['crm' => $board['id'], 'tab' => 'import'])) ?>"><?= fabo_crm_icon('upload') ?> Import Excel</a>
                <a class="btn" href="<?= e($u(['crm' => $board['id'], 'tab' => 'new'])) ?>"><?= fabo_crm_icon('plus') ?> Add one</a>
              </span>
            <?php elseif ($search !== '' || $stageF || $asg): ?>
              <a href="<?= e($u(['crm' => $board['id'], 'tab' => 'table'])) ?>">Clear the filters</a>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </form>

  <?php if ($pages > 1): ?>
    <nav class="crm-pager" aria-label="Pages">
      <?php if ($page > 1): ?><a class="btn btn-sm" href="<?= e($u($tq + ['page' => $page - 1])) ?>">← Previous</a><?php endif; ?>
      <span>Page <?= $page ?> of <?= $pages ?></span>
      <?php if ($page < $pages): ?><a class="btn btn-sm" href="<?= e($u($tq + ['page' => $page + 1])) ?>">Next →</a><?php endif; ?>
    </nav>
  <?php endif; ?>
<?php endif; ?>

<?php /* ====================================================== RECORD / NEW */ ?>
<?php elseif ($tab === 'record' || $tab === 'new'):
    $isNew = $tab === 'new';
    $rec   = null;
    if (!$isNew) {
        $rec = fabo_crm_record((int) $board['id'], (int) ($_GET['rec'] ?? 0));
        if (!$recOk($rec)) {
            $rec = null;
            echo '<div class="card crm-emptystate">' . fabo_crm_icon('inbox', 32)
               . '<h2>Record not found</h2><p>It may have been deleted, or it is not assigned to you.</p>'
               . '<a class="btn" href="' . e($u(['crm' => $board['id'], 'tab' => 'table'])) . '">Back to the list</a></div>';
        }
    }
    $vals   = $rec['d'] ?? [];
    $stage  = (int) ($rec['stage_id'] ?? ((int) ($_GET['stage'] ?? 0) ?: ($stages[0]['id'] ?? 0)));
    $assign = (int) ($rec['assigned_to'] ?? ($isNew && isset($people[$me]) ? $me : 0));
    // A form sent back for a correction keeps what was typed.
    $draft = $_SESSION['fabo_crm_draft'] ?? null;
    if ($isNew && $draft && (int) $draft['crm'] === (int) $board['id']) {
        foreach ($fields as $f) {
            if (isset($draft['f'][$f['field_key']])) {
                $vals[$f['field_key']] = $draft['f'][$f['field_key']];
            }
        }
        $stage  = (int) $draft['stage'] ?: $stage;
        $assign = (int) $draft['assign'];
    }
    unset($_SESSION['fabo_crm_draft']);
    $files   = $rec ? fabo_crm_files((int) $board['id'], (int) $rec['id']) : [];
    $editNow = $isNew || !empty($_GET['edit']);
    $kindIcon = ['note' => 'note', 'call' => 'phone', 'meeting' => 'calendar', 'email' => 'mail', 'stage' => 'stages',
                 'assign' => 'userplus', 'create' => 'plus', 'import' => 'upload', 'file' => 'file', 'edit' => 'note'];
?>
  <?php if ($isNew || $rec): ?>
  <a class="crm-backlink" href="<?= e($u(['crm' => $board['id'], 'tab' => 'table'])) ?>"><?= fabo_crm_icon('back', 14) ?> All <?= e($recLow) ?>s</a>

  <div class="crm-rec<?= $isNew ? ' is-new' : '' ?>">
    <div class="crm-rec-main">

      <?php if ($rec):
          $title = fabo_crm_title($board, $fields, $rec);
          $ct    = fabo_crm_contact($fields, $rec['d']);
          $curSt = $stageById[(int) $rec['stage_id']] ?? null; ?>
        <section class="card crm-profile">
          <div class="crm-profile-top">
            <span class="crm-bigav crm-t-<?= e($curSt['tone'] ?? 'muted') ?>" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($title, 0, 1))) ?></span>
            <div class="crm-profile-id">
              <h2><?= e($title) ?></h2>
              <div class="crm-meta"><?= e($board['record_label']) ?> #<?= (int) $rec['id'] ?>
                &middot; added <?= e(fabo_short_date($rec['created_at'])) ?><?= $rec['created_by'] ? ' by ' . e($rec['created_by']) : '' ?>
                &middot; last activity <?= e(fabo_crm_ago($rec['updated_at'])) ?></div>
              <div class="crm-quick">
                <?php if ($ct['phone'] !== ''): ?>
                  <a class="crm-qa" href="tel:<?= e(preg_replace('/[^\d+]/', '', $ct['phone'])) ?>"><?= fabo_crm_icon('phone', 14) ?> Call</a>
                <?php endif; ?>
                <?php if ($ct['wa'] !== ''): ?>
                  <a class="crm-qa" href="https://wa.me/<?= e($ct['wa']) ?>" target="_blank" rel="noopener"><?= fabo_crm_icon('chat', 14) ?> WhatsApp</a>
                <?php endif; ?>
                <?php if ($ct['email'] !== ''): ?>
                  <a class="crm-qa" href="mailto:<?= e($ct['email']) ?>"><?= fabo_crm_icon('mail', 14) ?> Email</a>
                <?php endif; ?>
              </div>
            </div>
            <div class="crm-profile-side">
              <span class="crm-side-l">Owner</span>
              <?php if ($mayEdit && $only === null): ?>
                <form method="post" action="<?= e($u(['crm' => $board['id']])) ?>" class="crm-ownerform">
                  <?= $hid('record_assign') ?><input type="hidden" name="rec" value="<?= (int) $rec['id'] ?>">
                  <label for="crmOwnerSel" class="crm-vh">Assigned to</label>
                  <select name="assign" id="crmOwnerSel" onchange="this.form.submit()">
                    <option value="0">Nobody yet</option>
                    <?php foreach ($people as $pid => $pn): ?>
                      <option value="<?= $pid ?>"<?= $pid === (int) $rec['assigned_to'] ? ' selected' : '' ?>><?= e($pn) ?><?= $pid === $me ? ' (me)' : '' ?></option>
                    <?php endforeach; ?>
                  </select>
                  <noscript><button class="btn btn-sm">Save</button></noscript>
                </form>
              <?php else: ?>
                <span class="crm-ownerro"><?= $rec['assignee'] ? fabo_crm_avatar($rec['assignee']) . ' ' . e($rec['assignee']) : 'Nobody yet' ?></span>
              <?php endif; ?>
            </div>
          </div>

          <?php if ($stages): ?>
            <div class="crm-path" role="group" aria-label="Stage — choose one to move this <?= e($recLow) ?>">
              <?php foreach ($stages as $s):
                  $on = (int) $s['id'] === (int) $rec['stage_id']; ?>
                <?php if ($mayEdit && !$on): ?>
                  <form method="post" action="<?= e($u(['crm' => $board['id']])) ?>">
                    <?= $hid('record_stage') ?>
                    <input type="hidden" name="rec" value="<?= (int) $rec['id'] ?>">
                    <input type="hidden" name="stage" value="<?= (int) $s['id'] ?>">
                    <button class="crm-step crm-t-<?= e($s['tone']) ?>" title="Move to <?= e($s['name']) ?>"><?= e($s['name']) ?></button>
                  </form>
                <?php else: ?>
                  <span class="crm-step crm-t-<?= e($s['tone']) ?><?= $on ? ' on' : '' ?>"<?= $on ? ' aria-current="step"' : '' ?>><?= $on ? fabo_crm_icon('check', 12) . ' ' : '' ?><?= e($s['name']) ?></span>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>
      <?php endif; ?>

      <section class="card crm-details<?= $editNow ? ' editing' : '' ?>" id="crmDetails">
        <div class="crm-cardhead">
          <h2><?= $isNew ? 'New ' . e($recLow) : 'Details' ?></h2>
          <?php if ($rec && $mayEdit && $fields): ?>
            <a class="btn btn-sm crm-editbtn" href="<?= e($u(['crm' => $board['id'], 'tab' => 'record', 'rec' => $rec['id'], 'edit' => 1])) ?>"
               data-toggle-edit><?= fabo_crm_icon('note', 14) ?> Edit details</a>
          <?php endif; ?>
        </div>
        <?php if (!$fields): ?><div class="empty">No fields yet.</div><?php endif; ?>

        <?php if ($rec): ?>
          <dl class="crm-dl crm-viewmode">
            <?php foreach ($fields as $f):
                $v = $vals[$f['field_key']] ?? null;
                $wide = in_array($f['type'], ['textarea', 'multiselect', 'file'], true); ?>
              <div class="<?= $wide ? 'wide' : '' ?>">
                <dt><?= e($f['label']) ?></dt>
                <dd>
                  <?php if ($f['type'] === 'file'): ?>
                    <?php if (!empty($files[$f['field_key']])): ?>
                      <ul class="crm-files">
                        <?php foreach ($files[$f['field_key']] as $x): ?>
                          <li><?= fabo_crm_icon('file', 14) ?> <a href="<?= e($u(['crm' => $board['id'], 'file' => $x['id']])) ?>" target="_blank" rel="noopener"><?= e($x['original_name']) ?></a>
                            <small><?= e(fabo_human_size((int) $x['size'])) ?></small></li>
                        <?php endforeach; ?>
                      </ul>
                    <?php else: ?><span class="crm-dash">No files</span><?php endif; ?>
                  <?php else: ?>
                    <?= fabo_crm_show($f, $v) ?: '<span class="crm-dash">—</span>' ?>
                  <?php endif; ?>
                </dd>
              </div>
            <?php endforeach; ?>
          </dl>
        <?php endif; ?>

        <?php if ($mayEdit && $fields): ?>
        <form method="post" action="<?= e($u(['crm' => $board['id']])) ?>" enctype="multipart/form-data" class="crm-form crm-editmode">
          <?= $hid($isNew ? 'record_create' : 'record_save') ?>
          <?php if ($rec): ?><input type="hidden" name="rec" value="<?= (int) $rec['id'] ?>"><?php endif; ?>

          <?php if ($isNew): ?>
            <div class="crm-grid crm-grid-top">
              <div class="crm-fld">
                <label for="crmStage">Stage</label>
                <select name="stage" id="crmStage">
                  <?php foreach ($stages as $s): ?>
                    <option value="<?= (int) $s['id'] ?>"<?= (int) $s['id'] === $stage ? ' selected' : '' ?>><?= e($s['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="crm-fld">
                <label for="crmAssign">Owner (assigned to)</label>
                <?php if ($only === null): ?>
                  <select name="assign" id="crmAssign">
                    <option value="0">Nobody yet</option>
                    <?php foreach ($people as $pid => $pn): ?>
                      <option value="<?= $pid ?>"<?= $pid === $assign ? ' selected' : '' ?>><?= e($pn) ?><?= $pid === $me ? ' (me)' : '' ?></option>
                    <?php endforeach; ?>
                  </select>
                <?php else: ?>
                  <div class="crm-ro">You</div>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>

          <div class="crm-grid">
            <?php foreach ($fields as $f):
                $wide = in_array($f['type'], ['textarea', 'multiselect', 'file', 'radio'], true); ?>
              <div class="crm-fld<?= $wide ? ' wide' : '' ?>">
                <label for="crmf_<?= e($f['field_key']) ?>"><?= e($f['label']) ?><?= !empty($f['required']) ? ' <b class="crm-req" aria-label="required">*</b>' : '' ?></label>
                <?= fabo_crm_input($f, $vals[$f['field_key']] ?? null) ?>
                <?php if ($f['type'] === 'file' && !empty($files[$f['field_key']])): ?>
                  <ul class="crm-files">
                    <?php foreach ($files[$f['field_key']] as $x): ?>
                      <li><?= fabo_crm_icon('file', 14) ?> <a href="<?= e($u(['crm' => $board['id'], 'file' => $x['id']])) ?>" target="_blank" rel="noopener"><?= e($x['original_name']) ?></a>
                        <small><?= e(fabo_human_size((int) $x['size'])) ?></small>
                        <button type="submit" form="crmDelFile<?= (int) $x['id'] ?>" class="crm-x" aria-label="Remove <?= e($x['original_name']) ?>"
                                onclick="return confirm('Remove this file?')"><?= fabo_crm_icon('x', 14) ?></button></li>
                    <?php endforeach; ?>
                  </ul>
                <?php endif; ?>
                <?php if ($f['help'] !== ''): ?><small class="crm-help"><?= e($f['help']) ?></small><?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="crm-form-acts">
            <button class="btn btn-primary"><?= $isNew ? 'Add ' . e($recLow) : 'Save changes' ?></button>
            <?php if ($isNew): ?>
              <button class="btn" name="another" value="1">Add and start another</button>
            <?php else: ?>
              <a class="btn" href="<?= e($u(['crm' => $board['id'], 'tab' => 'record', 'rec' => $rec['id']])) ?>" data-toggle-edit>Cancel</a>
            <?php endif; ?>
          </div>
        </form>
        <?php endif; ?>

        <?php foreach ($files as $list): foreach ($list as $x): ?>
          <form method="post" action="<?= e($u(['crm' => $board['id']])) ?>" id="crmDelFile<?= (int) $x['id'] ?>" hidden>
            <?= $hid('file_delete') ?><input type="hidden" name="file_id" value="<?= (int) $x['id'] ?>">
          </form>
        <?php endforeach; endforeach; ?>
      </section>
    </div>

    <?php if ($rec): ?>
    <aside class="crm-rec-side">
      <section class="card">
        <h2>Activity</h2>
        <?php if ($mayEdit): ?>
          <form method="post" action="<?= e($u(['crm' => $board['id']])) ?>" class="crm-composer">
            <?= $hid('record_note') ?>
            <input type="hidden" name="rec" value="<?= (int) $rec['id'] ?>">
            <fieldset class="crm-fs crm-kinds">
              <legend class="crm-vh">What are you logging?</legend>
              <?php foreach (['note' => 'Note', 'call' => 'Call', 'meeting' => 'Meeting', 'email' => 'Email'] as $kk => $kl): ?>
                <label><input type="radio" name="kind" value="<?= $kk ?>" <?= $kk === 'note' ? 'checked' : '' ?>>
                  <span><?= fabo_crm_icon($kindIcon[$kk], 13) ?> <?= $kl ?></span></label>
              <?php endforeach; ?>
            </fieldset>
            <label for="crmNote" class="crm-vh">Details</label>
            <textarea id="crmNote" name="note" rows="3" placeholder="What happened? e.g. Called — interested, send brochure on Monday" required></textarea>
            <button class="btn btn-primary btn-sm">Log it</button>
          </form>
        <?php endif; ?>
        <ol class="crm-timeline">
          <?php foreach (fabo_crm_activity((int) $board['id'], (int) $rec['id']) as $a):
              $k = (string) $a['kind']; ?>
            <li class="k-<?= e($k) ?>">
              <span class="crm-tl-ic" aria-hidden="true"><?= fabo_crm_icon($kindIcon[$k] ?? 'note', 13) ?></span>
              <div>
                <?php if (in_array($k, ['note', 'call', 'meeting', 'email'], true)): ?>
                  <b class="crm-tl-k"><?= e(ucfirst($k)) ?></b>
                  <div class="crm-tl-body"><?= nl2br(e($a['detail'])) ?></div>
                <?php else: ?>
                  <div><?= e($a['detail']) ?></div>
                <?php endif; ?>
                <small><?= e($a['who']) ?> &middot; <time datetime="<?= e(date('c', strtotime($a['created_at']))) ?>" title="<?= e(date('j M Y, g:i a', strtotime($a['created_at']))) ?>"><?= e(fabo_crm_ago($a['created_at'])) ?></time></small>
              </div>
            </li>
          <?php endforeach; ?>
          <?php if ($rec['import_id']): ?>
            <li class="k-import"><span class="crm-tl-ic" aria-hidden="true"><?= fabo_crm_icon('upload', 13) ?></span>
              <div><div>Brought in by an import</div><small><?= e(fabo_short_date($rec['created_at'])) ?></small></div></li>
          <?php endif; ?>
        </ol>
      </section>
      <?php if ($mayEdit): ?>
        <form method="post" action="<?= e($u(['crm' => $board['id']])) ?>" class="crm-delform"
              onsubmit="return confirm('Delete this record, its notes and its files? This cannot be undone.')">
          <?= $hid('record_delete') ?>
          <input type="hidden" name="rec" value="<?= (int) $rec['id'] ?>">
          <button class="crm-linkbtn danger"><?= fabo_crm_icon('trash', 14) ?> Delete this <?= e($recLow) ?></button>
        </form>
      <?php endif; ?>
    </aside>
    <?php endif; ?>
  </div>
  <?php endif; ?>

<?php /* =========================================================== FIELDS */ ?>
<?php elseif ($tab === 'fields'):
    $usage   = fabo_crm_field_usage((int) $board['id'], $fields);
    $editing = !empty($_GET['field']) ? fabo_crm_field((int) $board['id'], (int) $_GET['field']) : null;
    $ft      = $editing ? $editing['type'] : (string) ($_GET['type'] ?? 'text');
    if (!isset($types[$ft])) { $ft = 'text'; }
    $groups  = [];
    foreach ($types as $tk => $tv) { $groups[$tv[3]][$tk] = $tv; }
?>
  <div class="crm-studio<?= $mayDesign ? '' : ' ro' ?>">
    <div class="card crm-flist">
      <h2>Fields <small><?= count($fields) ?></small></h2>
      <p class="crm-hint">What every <?= e($recLow) ?> holds, in the order the form shows it.
        Create these before importing — each column of your Excel file is then mapped onto one of them.</p>
      <?php if (!$fields): ?><div class="empty">No fields yet. Add the first one<?= $mayDesign ? ' on the right' : '' ?>.</div><?php endif; ?>
      <ul class="crm-fields">
        <?php foreach ($fields as $i => $f): ?>
          <li class="<?= $editing && (int) $editing['id'] === (int) $f['id'] ? 'on' : '' ?>">
            <span class="crm-glyph" aria-hidden="true"><?= e($types[$f['type']][2] ?? '?') ?></span>
            <div class="crm-fmeta">
              <b><?= e($f['label']) ?></b>
              <small><?= e(fabo_crm_type_label($f['type'])) ?>
                <?php if (fabo_crm_has_options($f['type'])): ?>&middot; <?= count($f['opts']) ?> option<?= count($f['opts']) === 1 ? '' : 's' ?><?php endif; ?>
                <?php if ($f['type'] === 'autonumber'): ?>&middot; next <?= e(fabo_crm_autonumber_format($f['cfg'], (int) ($f['cfg']['next'] ?? 1))) ?><?php endif; ?>
                &middot; <?= number_format($usage[$f['field_key']] ?? 0) ?> filled</small>
              <span class="crm-flags">
                <?php if (!empty($f['required'])): ?><i>Required</i><?php endif; ?>
                <?php if (!empty($f['is_unique'])): ?><i>Unique</i><?php endif; ?>
                <?php if (!empty($f['in_list'])): ?><i class="soft">In table</i><?php endif; ?>
                <?php if ($f['field_key'] === $board['title_field']): ?><i class="title">Title</i><?php endif; ?>
              </span>
            </div>
            <?php if ($mayDesign): ?>
              <div class="crm-facts">
                <a class="btn btn-sm" href="<?= e($u(['crm' => $board['id'], 'tab' => 'fields', 'field' => $f['id']])) ?>">Edit</a>
                <form method="post" action="<?= e($u(['crm' => $board['id']])) ?>">
                  <?= $hid('field_move', 'fields') ?><input type="hidden" name="field_id" value="<?= (int) $f['id'] ?>">
                  <button class="btn btn-sm" name="dir" value="up" <?= $i === 0 ? 'disabled' : '' ?> aria-label="Move <?= e($f['label']) ?> up">↑</button>
                  <button class="btn btn-sm" name="dir" value="down" <?= $i === count($fields) - 1 ? 'disabled' : '' ?> aria-label="Move <?= e($f['label']) ?> down">↓</button>
                </form>
                <form method="post" action="<?= e($u(['crm' => $board['id']])) ?>"
                      onsubmit="return confirm('Delete the field “<?= e(addslashes($f['label'])) ?>”? What <?= number_format($usage[$f['field_key']] ?? 0) ?> record(s) hold in it is deleted too.')">
                  <?= $hid('field_delete', 'fields') ?><input type="hidden" name="field_id" value="<?= (int) $f['id'] ?>">
                  <button class="btn btn-sm btn-danger" aria-label="Delete <?= e($f['label']) ?>">×</button>
                </form>
              </div>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>

    <?php if ($mayDesign): ?>
    <div class="card crm-editor">
      <div class="crm-editor-head">
        <h2><?= $editing ? 'Edit “' . e($editing['label']) . '”' : 'Add a field' ?></h2>
        <?php if ($editing): ?><a class="btn btn-sm" href="<?= e($u(['crm' => $board['id'], 'tab' => 'fields'])) ?>">＋ New field instead</a><?php endif; ?>
      </div>
      <form method="post" action="<?= e($u(['crm' => $board['id']])) ?>" id="crmFieldForm">
        <?= $hid($editing ? 'field_save' : 'field_add', 'fields') ?>
        <?php if ($editing): ?><input type="hidden" name="field_id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>

        <fieldset class="crm-fs">
          <legend class="crm-lbl">1 &middot; What kind of field?</legend>
          <div class="crm-types">
            <?php foreach ($groups as $gname => $gtypes): ?>
              <div class="crm-tgroup"><span><?= e($gname) ?></span>
                <div>
                <?php foreach ($gtypes as $tk => $tv): ?>
                  <label class="crm-type" title="<?= e($tv[1]) ?>">
                    <input type="radio" name="type" value="<?= e($tk) ?>" <?= $tk === $ft ? 'checked' : '' ?>>
                    <span><i aria-hidden="true"><?= e($tv[2]) ?></i><b><?= e($tv[0]) ?></b><small><?= e($tv[1]) ?></small></span>
                  </label>
                <?php endforeach; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </fieldset>
        <?php if ($editing): ?>
          <p class="crm-hint">Changing the type keeps what records already hold; values that do not fit the new type show as they were typed.</p>
        <?php endif; ?>

        <div class="crm-lbl">2 &middot; Name it</div>
        <div class="sb-row">
          <div class="sb-field grow"><label for="crmFLabel">Field name</label>
            <input name="label" id="crmFLabel" required maxlength="120" value="<?= e($editing['label'] ?? '') ?>"
                   placeholder="e.g. Date of Birth"></div>
          <div class="sb-field grow" data-show="text textarea email phone url number decimal currency percent">
            <label for="crmFPh">Placeholder <small>(optional)</small></label>
            <input id="crmFPh" name="placeholder" maxlength="120" value="<?= e($editing['cfg']['placeholder'] ?? '') ?>"></div>
        </div>
        <div class="sb-row" style="margin-top:10px">
          <div class="sb-field grow"><label for="crmFHelp">Help text <small>(shown under the field)</small></label>
            <input id="crmFHelp" name="help" maxlength="255" value="<?= e($editing['help'] ?? '') ?>"></div>
        </div>

        <div data-show="select radio multiselect">
          <label class="crm-lbl" for="crmFOpts">3 &middot; The options</label>
          <textarea id="crmFOpts" name="options" rows="6" class="crm-opts" placeholder="One option per line"><?= e(implode("\n", $editing['opts'] ?? [])) ?></textarea>
          <p class="crm-hint">One per line. During import, values are matched to these without regard to capitals,
            and new values can be added to the list automatically.</p>
        </div>

        <div data-show="autonumber">
          <div class="crm-lbl">3 &middot; Numbering</div>
          <div class="sb-row">
            <div class="sb-field"><label for="crmFPre">Prefix</label>
              <input id="crmFPre" name="prefix" maxlength="20" value="<?= e($editing['cfg']['prefix'] ?? 'APP-') ?>"></div>
            <div class="sb-field"><label for="crmFDig">Digits</label>
              <input id="crmFDig" type="number" name="digits" min="1" max="10" value="<?= (int) ($editing['cfg']['digits'] ?? 4) ?>"></div>
            <div class="sb-field"><label for="crmFNext">Next number</label>
              <input id="crmFNext" type="number" name="next" min="1" value="<?= (int) ($editing['cfg']['next'] ?? 1) ?>"></div>
          </div>
          <p class="crm-hint">Every new record gets the next number. Records already on the board are numbered when the field is added.</p>
        </div>

        <div class="crm-lbl">Rules</div>
        <div class="crm-rules">
          <label data-hide="autonumber"><input type="checkbox" name="required" value="1" <?= !empty($editing['required']) ? 'checked' : '' ?>>
            Required <small>— a record cannot be saved, or a row imported, without it</small></label>
          <label data-hide="file checkbox multiselect autonumber"><input type="checkbox" name="is_unique" value="1" <?= !empty($editing['is_unique']) ? 'checked' : '' ?>>
            Unique <small>— no two records may share a value (good for email or application no.)</small></label>
          <label><input type="checkbox" name="in_list" value="1" <?= !$editing || !empty($editing['in_list']) ? 'checked' : '' ?>>
            Show in the table and on board cards</label>
        </div>

        <div class="crm-preview" aria-live="polite">
          <span>Preview</span>
          <div class="crm-fld" id="crmPreview"></div>
        </div>

        <button class="btn btn-primary"><?= $editing ? 'Save field' : 'Add field' ?></button>
      </form>
    </div>
    <?php endif; ?>
  </div>

<?php /* =========================================================== STAGES */ ?>
<?php elseif ($tab === 'stages'): ?>
  <div class="card">
    <h2>Stages</h2>
    <p class="crm-hint">The columns of the board, left to right. Mark the stages that end a record as <b>Won</b>
      (enrolled, hired, closed) or <b>Lost</b> (rejected, dropped) — the dashboards count conversion from them.</p>
    <table class="data crm-stagetable">
      <thead><tr><th></th><th scope="col">Name</th><th scope="col">Colour</th><th scope="col">Kind</th><th scope="col" class="num">Records</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($stages as $i => $s): ?>
        <tr>
          <td><span class="crm-dot crm-t-<?= e($s['tone']) ?>" aria-hidden="true"></span></td>
          <?php if ($mayDesign): ?>
            <td colspan="3">
              <form method="post" action="<?= e($u(['crm' => $board['id']])) ?>" class="crm-inline">
                <?= $hid('stage_save', 'stages') ?><input type="hidden" name="stage_id" value="<?= (int) $s['id'] ?>">
                <input name="name" value="<?= e($s['name']) ?>" maxlength="80" required aria-label="Stage name">
                <select name="tone" aria-label="Colour"><?php foreach (fabo_crm_tones() as $tk => $tl): ?>
                  <option value="<?= e($tk) ?>"<?= $tk === $s['tone'] ? ' selected' : '' ?>><?= e($tl) ?></option><?php endforeach; ?></select>
                <select name="kind" aria-label="Kind">
                  <?php foreach (['open' => 'In progress', 'won' => 'Won / done', 'lost' => 'Lost / closed'] as $kk => $kl): ?>
                    <option value="<?= $kk ?>"<?= $kk === $s['kind'] ? ' selected' : '' ?>><?= $kl ?></option>
                  <?php endforeach; ?></select>
                <button class="btn btn-sm">Save</button>
              </form>
            </td>
          <?php else: ?>
            <td><?= e($s['name']) ?></td><td><?= e(fabo_crm_tones()[$s['tone']] ?? '') ?></td>
            <td><?= ['open' => 'In progress', 'won' => 'Won / done', 'lost' => 'Lost / closed'][$s['kind']] ?></td>
          <?php endif; ?>
          <td class="num"><?= $only === null ? '<a href="' . e($u(['crm' => $board['id'], 'tab' => 'table', 'stage' => $s['id']])) . '">' . number_format((int) $s['records']) . '</a>' : '—' ?></td>
          <td>
            <?php if ($mayDesign): ?>
              <div class="crm-facts">
                <form method="post" action="<?= e($u(['crm' => $board['id']])) ?>">
                  <?= $hid('stage_move', 'stages') ?><input type="hidden" name="stage_id" value="<?= (int) $s['id'] ?>">
                  <button class="btn btn-sm" name="dir" value="up" <?= $i === 0 ? 'disabled' : '' ?> aria-label="Move <?= e($s['name']) ?> left">←</button>
                  <button class="btn btn-sm" name="dir" value="down" <?= $i === count($stages) - 1 ? 'disabled' : '' ?> aria-label="Move <?= e($s['name']) ?> right">→</button>
                </form>
                <?php if (count($stages) > 1): ?>
                  <details class="crm-del">
                    <summary class="btn btn-sm btn-danger">Delete</summary>
                    <form method="post" action="<?= e($u(['crm' => $board['id']])) ?>">
                      <?= $hid('stage_delete', 'stages') ?><input type="hidden" name="stage_id" value="<?= (int) $s['id'] ?>">
                      <label for="mv<?= (int) $s['id'] ?>">Move its <?= number_format((int) $s['records']) ?> record(s) to</label>
                      <select id="mv<?= (int) $s['id'] ?>" name="move_to"><?php foreach ($stages as $o): if ((int) $o['id'] === (int) $s['id']) { continue; } ?>
                        <option value="<?= (int) $o['id'] ?>"><?= e($o['name']) ?></option><?php endforeach; ?></select>
                      <button class="btn btn-sm btn-danger">Delete stage</button>
                    </form>
                  </details>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <?php if ($mayDesign): ?>
      <form method="post" action="<?= e($u(['crm' => $board['id']])) ?>" class="sb-row" style="margin-top:16px">
        <?= $hid('stage_add', 'stages') ?>
        <div class="sb-field grow"><label for="crmNS">New stage</label><input id="crmNS" name="name" required maxlength="80" placeholder="e.g. Documents Pending"></div>
        <div class="sb-field"><label for="crmNT">Colour</label>
          <select id="crmNT" name="tone"><?php foreach (fabo_crm_tones() as $tk => $tl): ?><option value="<?= e($tk) ?>"><?= e($tl) ?></option><?php endforeach; ?></select></div>
        <div class="sb-field"><label for="crmNK">Kind</label>
          <select id="crmNK" name="kind"><option value="open">In progress</option><option value="won">Won / done</option><option value="lost">Lost / closed</option></select></div>
        <div class="sb-field"><label>&nbsp;</label><button class="btn btn-primary">Add stage</button></div>
      </form>
    <?php endif; ?>
  </div>

<?php /* =========================================================== EMAILS */ ?>
<?php elseif ($tab === 'emails'):
    /* Three plain views: Send, Templates, Sent. One editor for a template, one
       "Insert a detail" dropdown -- nothing else to learn. */
    $cidM     = (int) $board['id'];
    $mv       = in_array($_GET['v'] ?? '', ['templates', 'sent'], true) ? $_GET['v'] : 'send';
    $tpls     = fabo_crm_mail_templates($cidM);
    $mailKeys = fabo_crm_mail_fields($fields);
    $tags     = fabo_crm_mail_tags($fields);
    $waiting  = fabo_crm_mail_waiting($cidM);
    $brandM   = fabo_crm_mail_brand(fabo_crm_mail_actor($viewer));
    $perHour  = defined('MAIL_PER_HOUR') && (int) MAIL_PER_HOUR > 0 ? max(0, (int) MAIL_PER_HOUR - FABO_CRM_MAIL_RESERVE) : 0;
    $vLink    = static fn(string $v, array $more = []) => $u(array_merge(['crm' => $cidM, 'tab' => 'emails', 'v' => $v === 'send' ? '' : $v], $more));
    $stageName = static fn($sid) => $sid === null ? 'Any stage' : (string) ($stageById[(int) $sid]['name'] ?? '—');
?>
  <?php if (!$mailKeys): ?>
    <div class="flash err">This CRM has no Email field, so there is nobody to write to.
      <?= $mayDesign ? '<a href="' . e($u(['crm' => $cidM, 'tab' => 'fields'])) . '">Add an Email field</a> first.' : 'Ask the person who runs it to add one.' ?></div>
  <?php endif; ?>

  <nav class="crm-mail-views" aria-label="Emails">
    <a class="<?= $mv === 'send' ? 'on' : '' ?>" href="<?= e($vLink('send')) ?>"><?= fabo_crm_icon('mail', 14) ?> Send an email</a>
    <a class="<?= $mv === 'templates' ? 'on' : '' ?>" href="<?= e($vLink('templates')) ?>"><?= fabo_crm_icon('note', 14) ?> Templates <span><?= count($tpls) ?></span></a>
    <a class="<?= $mv === 'sent' ? 'on' : '' ?>" href="<?= e($vLink('sent')) ?>"><?= fabo_crm_icon('history', 14) ?> Sent<?= $waiting ? ' <span>' . number_format($waiting) . ' going</span>' : '' ?></a>
  </nav>

<?php if ($mv === 'send'):
    $selIds  = (array) ($_SESSION['fabo_crm_mail_ids'][$cidM] ?? []);
    $toRaw   = (string) ($_GET['to'] ?? '');
    $to      = $toRaw === 'sel' && $selIds ? 'sel' : (string) max(0, (int) $toRaw);
    $toStage = $to === 'sel' ? 0 : (int) $to;
    if ($toStage > 0 && !isset($stageById[$toStage])) { $to = '0'; $toStage = 0; }
    $offered = fabo_crm_mail_templates_for($cidM, $toStage);
    $picked  = fabo_crm_mail_template($cidM, (int) ($_GET['tpl'] ?? 0));
    if (!$picked && isset($_GET['to']) && !isset($_GET['tpl']) && $offered && $offered[0]['stage_id'] !== null) {
        $picked = $offered[0];            // a stage's own template is the likely one
    }
    if ($to === 'sel') {
        $audRecs = [];
        foreach ($selIds as $rid) {
            $r = fabo_crm_record($cidM, (int) $rid);
            if ($recOk($r)) { $audRecs[] = $r; }
        }
    } else {
        [$audRecs] = fabo_crm_records($cidM, $fields, ['stage' => $toStage, 'only' => $only, 'sort' => 'created', 'dir' => 'asc'], FABO_CRM_MAIL_MAX);
    }
    $aud    = fabo_crm_mail_audience($board, $fields, $audRecs, '', $picked ? (int) $picked['id'] : 0, true);
    $first  = $aud['to'][0] ?? (fabo_crm_mail_audience($board, $fields, $audRecs, '', 0, false)['to'][0] ?? null);
    $sample = $first ? fabo_crm_mail_values($board, $fields, $first, $brandM) : [];
    $counts = [];
    foreach (fabo_all('SELECT stage_id, COUNT(*) AS n FROM crm_records WHERE crm_id = ?' . ($only !== null ? ' AND assigned_to = ?' : '')
                      . ' GROUP BY stage_id', $only !== null ? 'ii' : 'i', $only !== null ? [$cidM, $only] : [$cidM]) as $sc) {
        $counts[(int) $sc['stage_id']] = (int) $sc['n'];
    }
?>
  <div class="card crm-mail-card">
    <form method="get" action="crm.php" class="crm-mail-steps">
      <?= $tokenField ?><input type="hidden" name="crm" value="<?= $cidM ?>"><input type="hidden" name="tab" value="emails">
      <label><span class="crm-mail-n">1</span> Send to
        <select name="to" onchange="this.form.submit()">
          <option value="0"<?= $to === '0' ? ' selected' : '' ?>>Everyone (<?= number_format(array_sum($counts)) ?>)</option>
          <?php foreach ($stages as $s): ?>
            <option value="<?= (int) $s['id'] ?>"<?= $to === (string) $s['id'] ? ' selected' : '' ?>><?= e($s['name']) ?> (<?= number_format($counts[(int) $s['id']] ?? 0) ?>)</option>
          <?php endforeach; ?>
          <?php if ($selIds): ?><option value="sel"<?= $to === 'sel' ? ' selected' : '' ?>>The <?= count($selIds) ?> ticked on the list</option><?php endif; ?>
        </select></label>
      <label><span class="crm-mail-n">2</span> Start from
        <select name="tpl" onchange="this.form.submit()">
          <option value="0">A blank email</option>
          <?php foreach ($offered as $t): ?>
            <option value="<?= (int) $t['id'] ?>"<?= $picked && (int) $picked['id'] === (int) $t['id'] ? ' selected' : '' ?>><?= e($t['name']) ?></option>
          <?php endforeach; ?>
        </select></label>
      <noscript><button class="btn btn-sm">Show</button></noscript>
    </form>

    <form method="post" action="<?= e($u(['crm' => $cidM])) ?>" id="crmMailForm">
      <?= $hid('mail_send', 'emails') ?>
      <input type="hidden" name="to" value="<?= e($to) ?>">
      <input type="hidden" name="tpl" value="<?= $picked ? (int) $picked['id'] : 0 ?>">
      <div class="crm-mail-write">
        <div class="crm-mail-n-row"><span class="crm-mail-n">3</span> <b>Write it</b></div>
        <input name="subject" maxlength="200" required value="<?= e((string) ($picked['subject'] ?? '')) ?>" placeholder="Subject" aria-label="Subject" data-merge class="crm-mail-subjin">
        <textarea name="body" rows="9" required data-merge aria-label="Message"
                  placeholder="Write your message here, just as you would in any email."><?= e((string) ($picked['body'] ?? '')) ?></textarea>
        <?= fabo_crm_mail_insert($tags) ?>
        <?php if ($picked): ?>
          <label class="crm-mail-opt"><input type="checkbox" name="skip_sent" value="1" checked> Don’t send to anyone who already got “<?= e($picked['name']) ?>”</label>
        <?php endif; ?>
      </div>

      <?php if ($first): ?>
        <details class="crm-mail-preview" open>
          <summary>Preview — how <?= e($first['_email']) ?> will see it</summary>
          <div class="crm-mail-subj" id="mPrevSubj"></div>
          <div class="crm-mail-body" id="mPrevBody"></div>
        </details>
      <?php endif; ?>

      <div class="crm-mail-foot">
        <div><b><?= number_format(count($aud['to'])) ?></b> <?= count($aud['to']) === 1 ? 'person' : 'people' ?> will get it, from <b><?= e($brandM) ?></b>
          <?php if ($aud['skipped']): ?><div class="crm-mail-skip">Left out: <?= e(fabo_crm_mail_skip_words($aud['skipped'])) ?></div><?php endif; ?></div>
        <button class="btn btn-primary" <?= $aud['to'] ? '' : 'disabled' ?>
                onclick="return confirm('Send this email to <?= count($aud['to']) ?> <?= count($aud['to']) === 1 ? 'person' : 'people' ?>?')"><?= fabo_crm_icon('mail', 14) ?> Send</button>
      </div>
    </form>
  </div>
  <script>
  (function () {
    var sample = <?= json_encode($sample, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
    var f = document.getElementById('crmMailForm'), ps = document.getElementById('mPrevSubj'), pb = document.getElementById('mPrevBody');
    if (!ps) { return; }
    function merge(t) { return t.replace(/\{\{\s*([^{}]+?)\s*\}\}/g, function (m, k) { var v = sample[k.toLowerCase()]; return v === undefined ? '' : v; }); }
    function esc(t) { return t.replace(/[&<>"]/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
    function draw() {
      ps.textContent = merge(f.subject.value);
      pb.innerHTML = merge(f.body.value).trim().split(/\n\s*\n/).map(function (p) { return '<p>' + esc(p).replace(/\n/g, '<br>') + '</p>'; }).join('');
    }
    f.addEventListener('input', draw);
    draw();
  })();
  </script>

<?php elseif ($mv === 'templates'):
    $editId  = isset($_GET['edit']) ? (int) $_GET['edit'] : -1;      // 0 = a new one
    $editing = $editId > 0 ? fabo_crm_mail_template($cidM, $editId) : ($editId === 0 ? ['id' => 0, 'stage_id' => null, 'name' => '', 'subject' => '', 'body' => ''] : null);
?>
  <?php if ($editing && $mayDesign): ?>
    <form method="post" action="<?= e($u(['crm' => $cidM])) ?>" class="card crm-mail-card">
      <?= $hid('mail_tpl_save', 'emails') ?><input type="hidden" name="tpl" value="<?= (int) $editing['id'] ?>">
      <h2><?= $editing['id'] ? 'Edit template' : 'New template' ?></h2>
      <div class="sb-row">
        <div class="sb-field grow"><label for="tN">Name <small>(only you see this)</small></label>
          <input id="tN" name="name" maxlength="120" required value="<?= e($editing['name']) ?>" placeholder="e.g. Interview invitation"></div>
        <div class="sb-field"><label for="tS">Suggest it for</label>
          <select id="tS" name="stage_id"><option value="0">Any stage</option>
            <?php foreach ($stages as $s): ?><option value="<?= (int) $s['id'] ?>"<?= (int) ($editing['stage_id'] ?? 0) === (int) $s['id'] ? ' selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
          </select></div>
      </div>
      <div class="crm-mail-write" style="margin-top:12px">
        <input name="subject" maxlength="200" required value="<?= e($editing['subject']) ?>" placeholder="Subject" aria-label="Subject" class="crm-mail-subjin">
        <textarea name="body" rows="10" required aria-label="Message" placeholder="Write the email just as you would send it."><?= e($editing['body']) ?></textarea>
        <?= fabo_crm_mail_insert($tags) ?>
      </div>
      <div class="crm-mail-foot">
        <a class="btn" href="<?= e($vLink('templates')) ?>">Cancel</a>
        <button class="btn btn-primary">Save template</button>
      </div>
    </form>
  <?php else: ?>
    <div class="card crm-mail-card">
      <div class="crm-mail-head">
        <div><h2>Templates</h2><p class="crm-hint">Emails you send again and again. Pick one when sending, change what you need, and send.</p></div>
        <?php if ($mayDesign): ?><a class="btn btn-primary" href="<?= e($vLink('templates', ['edit' => 0])) ?>"><?= fabo_crm_icon('plus', 14) ?> New template</a><?php endif; ?>
      </div>
      <?php if (!$tpls): ?>
        <div class="empty">No templates yet.<?= $mayDesign ? ' Make one for each email you send often — a welcome, a reminder, an interview invitation.' : '' ?></div>
      <?php else: ?>
        <table class="data crm-mail-tpls">
          <thead><tr><th>Name</th><th>Subject</th><th>For</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($tpls as $t): ?>
            <tr id="tpl<?= (int) $t['id'] ?>">
              <td><b><?= e($t['name']) ?></b></td>
              <td><?= e($t['subject']) ?></td>
              <td><?= e($stageName($t['stage_id'])) ?></td>
              <td><div class="crm-facts">
                <a class="btn btn-sm btn-primary" href="<?= e($u(['crm' => $cidM, 'tab' => 'emails', 'to' => $t['stage_id'] === null ? '0' : (int) $t['stage_id'], 'tpl' => (int) $t['id']])) ?>">Use</a>
                <?php if ($mayDesign): ?>
                  <a class="btn btn-sm" href="<?= e($vLink('templates', ['edit' => (int) $t['id']])) ?>">Edit</a>
                  <form method="post" action="<?= e($u(['crm' => $cidM])) ?>"><?= $hid('mail_tpl_delete', 'emails') ?>
                    <input type="hidden" name="tpl" value="<?= (int) $t['id'] ?>">
                    <button class="btn btn-sm btn-danger" onclick="return confirm('Delete this template?')">Delete</button></form>
                <?php endif; ?>
              </div></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  <?php endif; ?>

<?php else: /* sent */
    $sends = fabo_crm_mail_sends($cidM);
?>
  <div class="card crm-mail-card" id="sent">
    <h2>Sent</h2>
    <p class="crm-hint" id="crmMailState" data-waiting="<?= $waiting ?>" data-pump="<?= e($u(['crm' => $cidM, 'tab' => 'emails', 'pump' => 1])) ?>">
      <?php if ($waiting): ?><b><?= number_format($waiting) ?></b> on their way — a few at a time<?= $perHour ? ', about ' . number_format($perHour) . ' an hour' : '' ?>. They keep going after you leave this page.
      <?php else: ?>Nothing waiting.<?= $perHour ? ' Up to about ' . number_format($perHour) . ' emails go out an hour, so sign-in emails are never held up.' : '' ?><?php endif; ?>
    </p>
    <?php if (!$sends): ?>
      <div class="empty">Nothing sent from this CRM yet.</div>
    <?php else: ?>
      <div class="scrollx"><table class="data">
        <thead><tr><th>When</th><th>Email</th><th>To</th><th class="num">Sent</th><th class="num">Waiting</th><th class="num">Failed</th><th class="num">Left out</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($sends as $sd): ?>
          <tr>
            <td><?= e(date('j M, g:i A', strtotime((string) $sd['created_at']))) ?><br><small style="color:var(--ink-mute)"><?= e($sd['who']) ?></small></td>
            <td><b><?= e($sd['subject']) ?></b></td>
            <td><?= $sd['stage_name'] !== null ? e($sd['stage_name']) : 'Everyone / ticked' ?></td>
            <td class="num tone-good"><?= number_format((int) $sd['n_sent']) ?></td>
            <td class="num"><?= number_format((int) $sd['n_waiting']) ?></td>
            <td class="num<?= (int) $sd['n_failed'] ? ' tone-risk' : '' ?>"><?= number_format((int) $sd['n_failed']) ?></td>
            <td class="num"><?= number_format((int) $sd['skipped'] + (int) $sd['n_dropped']) ?></td>
            <td><div class="crm-facts">
              <?php if ((int) $sd['n_waiting']): ?>
                <form method="post" action="<?= e($u(['crm' => $cidM])) ?>"><?= $hid('mail_cancel', 'emails') ?><input type="hidden" name="send" value="<?= (int) $sd['id'] ?>">
                  <button class="btn btn-sm btn-danger" onclick="return confirm('Stop the emails that have not gone yet?')">Stop</button></form>
              <?php endif; ?>
              <?php if ((int) $sd['n_failed']): ?>
                <form method="post" action="<?= e($u(['crm' => $cidM])) ?>"><?= $hid('mail_retry', 'emails') ?><input type="hidden" name="send" value="<?= (int) $sd['id'] ?>">
                  <button class="btn btn-sm">Try again</button></form>
              <?php endif; ?>
            </div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </div>
  <script>
  (function () {
    var st = document.getElementById('crmMailState');
    if (!st || +st.getAttribute('data-waiting') <= 0) { return; }
    var url = st.getAttribute('data-pump'), busy = false, idle = 0;
    var t = setInterval(function () {
      if (busy) { return; }
      busy = true;
      fetch(url, {credentials: 'same-origin', headers: {'X-Requested-With': 'fetch'}})
        .then(function (r) { return r.json(); })
        .then(function (d) {
          busy = false;
          if (d.waiting === 0) { clearInterval(t); location.reload(); return; }
          st.textContent = d.waiting + ' on their way. ' + (d.paused || 'Sending now…');
          if (d.sent > 0) { idle = 0; } else if (++idle > 40) { clearInterval(t); }
        })
        .catch(function () { busy = false; });
    }, 6000);
  })();
  </script>
<?php endif; ?>

<?php /* ============================================================= FORM */ ?>
<?php elseif ($tab === 'form'): ?>
  <?php require __DIR__ . '/../lib/crm_form_tab.php'; ?>

<?php /* =========================================================== IMPORT */ ?>
<?php elseif ($tab === 'import'):
    $pending = $_SESSION['fabo_crm_import'][(int) $board['id']] ?? null;
    $table   = null;
    if ($pending) {
        if (isset($_GET['sheet'])) {
            $pending['sheet'] = max(0, (int) $_GET['sheet']);
            $_SESSION['fabo_crm_import'][(int) $board['id']] = $pending;
        }
        $path = fabo_crm_stash_path($pending['token'], $pending['ext']);
        try {
            if (!is_file($path)) {
                throw new InvalidArgumentException('The uploaded file has expired. Upload it again.');
            }
            $table = fabo_crm_read_file($path, $pending['ext'], (int) $pending['sheet']);
        } catch (Throwable $e) {
            unset($_SESSION['fabo_crm_import'][(int) $board['id']]);
            echo '<div class="flash err">' . e($e instanceof InvalidArgumentException ? $e->getMessage()
                 : 'That file could not be read.') . '</div>';
            $pending = null;
        }
    }
?>
  <ol class="crm-wizard" aria-label="Import steps">
    <li class="<?= $pending && $table ? 'done' : 'on' ?>"><span>1</span> Upload Excel / CSV</li>
    <li class="<?= $pending && $table ? 'on' : '' ?>"><span>2</span> Map columns to fields</li>
    <li><span>3</span> Import &amp; review</li>
  </ol>

  <?php if ($pending && $table): ?>
    <?php
      $importable = array_values(array_filter($fields, static fn($f) => $f['type'] !== 'file'));
      $uniqueKeys = array_values(array_filter($importable, static fn($f) => !empty($f['is_unique']) || $f['type'] === 'autonumber'));
      $samples = [];
      foreach ($table['headers'] as $i => $h) {
          $samples[$i] = [];
          foreach ($table['rows'] as [$ln, $cells]) {
              $v = trim((string) ($cells[$i] ?? ''));
              if ($v !== '' && !in_array($v, $samples[$i], true)) { $samples[$i][] = $v; }
              if (count($samples[$i]) >= 3) { break; }
          }
      }
    ?>
    <div class="card">
      <div class="crm-rec-head">
        <div>
          <h2>Map the columns of <?= e($pending['name']) ?></h2>
          <div class="crm-meta"><?= number_format(count($table['rows'])) ?> row<?= count($table['rows']) === 1 ? '' : 's' ?>
            &middot; <?= count($table['headers']) ?> column<?= count($table['headers']) === 1 ? '' : 's' ?>.
            Columns whose heading matches a field are mapped already — check them, then import.</div>
        </div>
        <form method="post" action="<?= e($u(['crm' => $board['id']])) ?>">
          <?= $hid('import_cancel', 'import') ?>
          <button class="btn btn-sm">Choose a different file</button>
        </form>
      </div>

      <?php if (count($table['sheets']) > 1): ?>
        <div class="crm-sheets">Worksheet:
          <?php foreach ($table['sheets'] as $si => $sn): ?>
            <a class="btn btn-sm<?= $si === (int) $pending['sheet'] ? ' btn-primary' : '' ?>"
               href="<?= e($u(['crm' => $board['id'], 'tab' => 'import', 'sheet' => $si])) ?>"><?= e($sn) ?></a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form method="post" action="<?= e($u(['crm' => $board['id']])) ?>" id="crmMap"
            onsubmit="var b=this.querySelector('button[type=submit]');b.disabled=true;b.textContent='Importing… please wait'">
        <?= $hid('import_run', 'import') ?>
        <div class="crm-tablewrap">
        <table class="data crm-maptable">
          <thead><tr><th scope="col">Column in your file</th><th scope="col">Sample values</th><th scope="col">Goes into</th></tr></thead>
          <tbody>
          <?php foreach ($table['headers'] as $i => $h):
              $guess = fabo_crm_guess_target($h, $importable); ?>
            <tr class="<?= $guess === '' ? 'skip' : '' ?>">
              <td><label for="map<?= $i ?>"><b><?= e($h) ?></b></label></td>
              <td class="crm-samples"><?php foreach ($samples[$i] as $s): ?><span><?= e(mb_strimwidth($s, 0, 40, '…')) ?></span><?php endforeach; ?>
                <?php if (!$samples[$i]): ?><em>empty</em><?php endif; ?></td>
              <td>
                <select name="map[<?= $i ?>]" id="map<?= $i ?>" class="crm-mapsel">
                  <option value="">— Don’t import —</option>
                  <optgroup label="Fields">
                    <?php foreach ($importable as $f): ?>
                      <option value="<?= e($f['field_key']) ?>"<?= $guess === $f['field_key'] ? ' selected' : '' ?>>
                        <?= e($f['label']) ?> · <?= e(fabo_crm_type_label($f['type'])) ?><?= !empty($f['required']) ? ' *' : '' ?></option>
                    <?php endforeach; ?>
                  </optgroup>
                  <optgroup label="Record">
                    <option value="__stage"<?= $guess === '__stage' ? ' selected' : '' ?>>Stage (its column on the board)</option>
                    <?php if ($only === null): ?>
                      <option value="__assign"<?= $guess === '__assign' ? ' selected' : '' ?>>Assigned to (a person’s name or email)</option>
                    <?php endif; ?>
                  </optgroup>
                  <?php if ($mayDesign): ?>
                    <option value="__new">＋ Create a new field from this column</option>
                  <?php endif; ?>
                </select>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
        <p class="crm-hint" id="crmMapNote" role="status"></p>

        <div class="crm-impopts">
          <div class="sb-field"><label for="crmDS">Rows with no stage go to</label>
            <select id="crmDS" name="default_stage"><?php foreach ($stages as $s): ?>
              <option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
          <?php if ($only === null): ?>
            <div class="sb-field"><label for="crmDA">Assign new records to</label>
              <select id="crmDA" name="assign_default">
                <option value="0">Nobody (assign later)</option>
                <?php foreach ($people as $pid => $pn): ?><option value="<?= $pid ?>"><?= e($pn) ?></option><?php endforeach; ?>
              </select></div>
          <?php endif; ?>
          <div class="sb-field"><label for="crmMatch">Recognise records already here by</label>
            <select name="match" id="crmMatch">
              <option value="">— Don’t check: add every row —</option>
              <?php foreach ($importable as $f): if (in_array($f['type'], ['checkbox', 'multiselect'], true)) { continue; } ?>
                <option value="<?= e($f['field_key']) ?>"<?= $uniqueKeys && $uniqueKeys[0]['field_key'] === $f['field_key'] ? ' selected' : '' ?>><?= e($f['label']) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="sb-field" id="crmMode"><label for="crmModeSel">When a row matches one</label>
            <select id="crmModeSel" name="mode"><option value="skip">Skip the row</option><option value="update">Update the record with the row</option></select></div>
        </div>
        <?php if ($mayDesign): ?>
        <div class="crm-rules">
          <label><input type="checkbox" name="create_stages" value="1" checked>
            Create a stage for any stage name the board does not have yet</label>
          <label><input type="checkbox" name="add_options" value="1" checked>
            Add values that are not yet an option to dropdown / choice fields</label>
        </div>
        <?php endif; ?>
        <?php if ($only !== null): ?>
          <p class="crm-hint">Everything you import is assigned to you.</p>
        <?php endif; ?>

        <details class="crm-peek" open>
          <summary>Preview of the first rows</summary>
          <div class="crm-tablewrap">
            <table class="data">
              <thead><tr><th scope="col">Row</th><?php foreach ($table['headers'] as $h): ?><th scope="col"><?= e($h) ?></th><?php endforeach; ?></tr></thead>
              <tbody>
              <?php foreach (array_slice($table['rows'], 0, 5) as [$ln, $cells]): ?>
                <tr><td><?= (int) $ln ?></td><?php foreach ($table['headers'] as $i => $h): ?><td><?= e(mb_strimwidth((string) ($cells[$i] ?? ''), 0, 60, '…')) ?></td><?php endforeach; ?></tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </details>

        <button type="submit" class="btn btn-primary btn-lg">Import <?= number_format(count($table['rows'])) ?> row<?= count($table['rows']) === 1 ? '' : 's' ?></button>
      </form>
    </div>

  <?php else: ?>
    <div class="crm-importgrid">
      <div class="card">
        <h2>Upload your data</h2>
        <ol class="crm-steps">
          <li><b>Create the fields first</b> in <a href="<?= e($u(['crm' => $board['id'], 'tab' => 'fields'])) ?>">Fields</a>
            — one for each piece of information you hold<?= $mayDesign ? ' (you can also make new ones while mapping)' : '' ?>.</li>
          <li><b>Upload</b> an Excel (.xlsx) or CSV file. The first row must be the column headings.</li>
          <li><b>Map</b> each column onto a field, choose how duplicates are handled, and import.</li>
        </ol>
        <form method="post" action="<?= e($u(['crm' => $board['id']])) ?>" enctype="multipart/form-data" class="crm-drop" id="crmDrop">
          <?= $hid('import_upload', 'import') ?>
          <input type="file" name="file" id="crmFile" accept=".csv,.xlsx,.tsv,.txt" required>
          <label for="crmFile"><b>Choose a file</b> or drag it here<small>.xlsx or .csv &middot; up to <?= number_format(FABO_CRM_IMPORT_MAX_ROWS) ?> rows &middot; <?= e(fabo_upload_limit_words()) ?></small></label>
          <button class="btn btn-primary">Upload and map columns →</button>
        </form>
      </div>
      <div class="card">
        <h2>Template</h2>
        <p class="crm-hint">An Excel file whose headings are exactly this CRM’s fields, with a Guide sheet saying what each
          column takes. Fill it in, upload it, and every column maps itself.</p>
        <a class="btn" href="<?= e($u(['crm' => $board['id'], 'template' => 1])) ?>">Download the import template</a>
      </div>
    </div>
  <?php endif; ?>

  <?php /* Import notes quote other records' values: not for someone limited to their own. */
        $imports = $only === null ? fabo_crm_imports((int) $board['id']) : [];
        $open    = (int) ($_GET['open'] ?? 0); ?>
  <?php if ($imports): ?>
    <div class="card">
      <h2>Import history</h2>
      <div class="crm-tablewrap">
      <table class="data crm-imptable">
        <thead><tr><th scope="col">When</th><th scope="col">File</th><th scope="col">By</th><th scope="col" class="num">Rows</th><th scope="col" class="num">Added</th>
          <th scope="col" class="num">Updated</th><th scope="col" class="num">Skipped</th><th scope="col" class="num">Warnings</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($imports as $im):
            $msgs = json_decode((string) $im['messages'], true) ?: []; ?>
          <tr class="<?= $im['undone_at'] ? 'crm-undone' : '' ?>">
            <td><?= e(date('j M Y, g:i a', strtotime($im['created_at']))) ?></td>
            <td><?= e($im['file_name']) ?><?= $im['undone_at'] ? ' <span class="pill grey">undone</span>' : '' ?></td>
            <td><?= e($im['who']) ?></td>
            <td class="num"><?= number_format((int) $im['total_rows']) ?></td>
            <td class="num"><?= number_format((int) $im['created_n']) ?></td>
            <td class="num"><?= number_format((int) $im['updated_n']) ?></td>
            <td class="num"><?= number_format((int) $im['skipped_n']) ?></td>
            <td class="num"><?= number_format((int) $im['warning_n']) ?></td>
            <td>
              <?php if (!$im['undone_at'] && (int) $im['still_there'] > 0 && $only === null): ?>
                <form method="post" action="<?= e($u(['crm' => $board['id']])) ?>"
                      onsubmit="return confirm('Remove the <?= (int) $im['still_there'] ?> record(s) this import added? Records it updated are left alone.')">
                  <?= $hid('import_undo', 'import') ?><input type="hidden" name="import_id" value="<?= (int) $im['id'] ?>">
                  <button class="btn btn-sm">Undo</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php if ($msgs): ?>
            <tr class="crm-msgs"><td colspan="9">
              <details<?= $open === (int) $im['id'] ? ' open' : '' ?>>
                <summary><?= count($msgs) ?> note<?= count($msgs) === 1 ? '' : 's' ?> on rows<?= count($msgs) >= 500 ? ' (first 500)' : '' ?></summary>
                <ul><?php foreach ($msgs as $m): ?><li><?= e($m) ?></li><?php endforeach; ?></ul>
              </details>
            </td></tr>
          <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div>
  <?php endif; ?>

<?php /* =========================================================== ACCESS */ ?>
<?php elseif ($tab === 'access'):
    $offer = fabo_crm_grantable_people($board, $isAdmin);
    $have  = [];
    foreach (fabo_all('SELECT person_id, permission, scope FROM crm_access WHERE crm_id = ?', 'i', [(int) $board['id']]) as $g) {
        $have[(int) $g['person_id']] = $g;
    }
    $groups = [];
    foreach ($offer as $pid => $o) { $groups[$o['group']][$pid] = $o['name']; }
    $runner = $board['owner_person'] ? (fabo_one('SELECT name FROM clients WHERE id = ?', 'i', [(int) $board['owner_person']])['name'] ?? '') : '';
?>
  <div class="card">
    <h2>Who has access</h2>
    <p class="crm-hint">
      <?php if ((int) $board['client_id'] === 0): ?>
        This CRM belongs to your own team. Share it with team members, or with a client — they then see it in
        their own CRM menu, and nothing else of yours.
      <?php else: ?>
        This CRM belongs to <b><?= e(fabo_one('SELECT COALESCE(NULLIF(organisation,""),name) n FROM clients WHERE id = ?', 'i', [(int) $board['client_id']])['n'] ?? 'a client') ?></b>.
        It can be shared with their own team only, so their data stays inside their organisation.
      <?php endif; ?>
      <b>Only assigned</b> means the person sees just the records assigned to them.
    </p>
    <ul class="crm-always">
      <li><?= fabo_crm_avatar($runner ?: 'Administrator') ?> <b><?= e($runner ?: ($board['created_by'] ?: 'Administrator')) ?></b> — runs this CRM <small>(change in Settings)</small></li>
      <?php if ((int) $board['client_id'] > 0): ?>
        <li><b>The head of the organisation</b> — sees every CRM their organisation owns, through the CRM module on their page</li>
      <?php endif; ?>
      <li><b>Administrator</b> — always</li>
      <?php /* People given "All our CRMs" on their own page reach this one too. */
        $allHolders = fabo_all('SELECT c.name, a.permission, a.scope FROM crm_access a JOIN clients c ON c.id = a.person_id
                                 WHERE a.crm_id = 0 AND c.kind = \'team\' AND COALESCE(c.parent_id, 0) = ? ORDER BY c.name',
                               'i', [(int) $board['client_id']]);
        foreach ($allHolders as $ah): ?>
        <li><?= fabo_crm_avatar($ah['name']) ?> <b><?= e($ah['name']) ?></b> —
          <?= $ah['permission'] === 'edit' ? 'can edit' : 'view only' ?><?= $ah['scope'] === 'own' ? ', only assigned records' : '' ?>,
          through <i>All our CRMs</i> <small>(change on their team page)</small></li>
      <?php endforeach; ?>
    </ul>

    <?php if (!$offer): ?>
      <div class="empty">There is nobody else to share it with yet. Add team members first<?= $isAdmin ? ' on Team Management' : ' on My Team' ?>.</div>
    <?php else: ?>
      <form method="post" action="<?= e($u(['crm' => $board['id']])) ?>">
        <?= $hid('access_save', 'access') ?>
        <label for="crmAccFind" class="crm-vh">Find a person</label>
        <input type="search" id="crmAccFind" class="crm-find" placeholder="Find a person…">
        <div class="crm-tablewrap">
        <table class="data accesstable crm-acctable">
          <thead><tr><th scope="col">Person</th><th scope="col">No access</th><th scope="col">View only</th><th scope="col">Can edit</th><th scope="col" style="width:220px">Records</th></tr></thead>
          <?php foreach ($groups as $gname => $list): ?>
            <tbody>
              <tr class="crm-accgroup"><th colspan="5" scope="rowgroup"><?= e($gname) ?></th></tr>
              <?php foreach ($list as $pid => $pn):
                  $cur = $have[$pid]['permission'] ?? 'none';
                  $sc  = $have[$pid]['scope'] ?? 'all'; ?>
                <tr data-name="<?= e(mb_strtolower($pn)) ?>">
                  <td><?= fabo_crm_avatar($pn) ?> <?= e($pn) ?></td>
                  <?php foreach (['none', 'view', 'edit'] as $p): ?>
                    <td><input type="radio" name="accperm[<?= $pid ?>]" value="<?= $p ?>" <?= $cur === $p ? 'checked' : '' ?>
                               aria-label="<?= e($pn) ?>: <?= ['none' => 'no access', 'view' => 'view only', 'edit' => 'can edit'][$p] ?>"></td>
                  <?php endforeach; ?>
                  <td><select name="accscope[<?= $pid ?>]" aria-label="Which records <?= e($pn) ?> sees">
                    <option value="all"<?= $sc === 'all' ? ' selected' : '' ?>>All records</option>
                    <option value="own"<?= $sc === 'own' ? ' selected' : '' ?>>Only assigned to them</option>
                  </select></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          <?php endforeach; ?>
        </table>
        </div>
        <button class="btn btn-primary" style="margin-top:14px">Save access</button>
      </form>
    <?php endif; ?>
  </div>

<?php /* ========================================================= SETTINGS */ ?>
<?php elseif ($tab === 'settings'):
    $team = fabo_crm_team((int) $board['client_id']); ?>
  <div class="card">
    <h2>Settings</h2>
    <form method="post" action="<?= e($u(['crm' => $board['id']])) ?>">
      <?= $hid('board_save', 'settings') ?>
      <div class="sb-row">
        <div class="sb-field grow"><label for="crmSN">Name</label><input id="crmSN" name="name" required maxlength="120" value="<?= e($board['name']) ?>"></div>
        <div class="sb-field"><label for="crmSR">Each record is called</label><input id="crmSR" name="record_label" maxlength="60" value="<?= e($board['record_label']) ?>"></div>
        <div class="sb-field"><label for="crmST">Title on cards and records</label>
          <select id="crmST" name="title_field">
            <option value="">First text field</option>
            <?php foreach ($fields as $f): if (in_array($f['type'], ['file', 'checkbox', 'textarea'], true)) { continue; } ?>
              <option value="<?= e($f['field_key']) ?>"<?= $f['field_key'] === $board['title_field'] ? ' selected' : '' ?>><?= e($f['label']) ?></option>
            <?php endforeach; ?>
          </select></div>
      </div>
      <div class="sb-row" style="margin-top:12px">
        <div class="sb-field grow"><label for="crmSD">Description</label><input id="crmSD" name="description" maxlength="255" value="<?= e($board['description']) ?>"></div>
        <div class="sb-field"><label for="crmSO">Belongs to <small>(runs it)</small></label>
          <select id="crmSO" name="owner_person">
            <option value="0"><?= (int) $board['client_id'] === 0 ? 'Administrator' : 'The head of the organisation' ?></option>
            <?php foreach ($team as $tid => $tn): if ($tid === (int) $board['client_id']) { continue; } ?>
              <option value="<?= $tid ?>"<?= $tid === (int) $board['owner_person'] ? ' selected' : '' ?>><?= e($tn) ?></option>
            <?php endforeach; ?>
          </select></div>
      </div>
      <div class="crm-rules">
        <label><input type="checkbox" name="client_design" value="1" <?= !empty($board['client_design']) ? 'checked' : '' ?>>
          People with <b>Can edit · All records</b> may also change its fields and stages
          <small>— otherwise only the person who runs it does</small></label>
      </div>
      <button class="btn btn-primary">Save settings</button>
    </form>
  </div>

  <div class="card crm-danger">
    <h2>Delete this CRM</h2>
    <p class="crm-hint">Deletes the CRM, its <?= number_format($total) ?> record<?= $total === 1 ? '' : 's' ?>, their notes and files,
      its fields, stages, access and import history. This cannot be undone — <a href="<?= e($u(['crm' => $board['id'], 'export' => 1])) ?>">export first</a>.</p>
    <form method="post" action="<?= e($u(['crm' => $board['id']])) ?>" class="sb-row">
      <?= $hid('board_delete', 'settings') ?>
      <div class="sb-field grow"><label for="crmDel">Type <b><?= e($board['name']) ?></b> to confirm</label>
        <input id="crmDel" name="confirm" autocomplete="off" required></div>
      <div class="sb-field"><label>&nbsp;</label><button class="btn btn-danger">Delete CRM</button></div>
    </form>
  </div>
<?php endif; ?>

<?php endif; ?>
</div>
<div class="crm-tooltip" id="crmTip" role="tooltip" hidden></div>
<script src="<?= e(fabo_asset(fabo_root_prefix() . 'assets/crm.js')) ?>"></script>
<?php fabo_foot();

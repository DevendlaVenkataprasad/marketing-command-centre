<?php
/**
 * Every file the module serves, after the document's access check:
 * the current PDF, a version, an attachment, the signature certificate, a
 * verification report, and a DSC signing copy.
 */

$kind = (string) ($_GET['kind'] ?? 'current');
$inline = !empty($_GET['inline']) && empty($_GET['dl']);

if ($kind === 'report') {
    $v = fabo_one('SELECT * FROM ds_signature_verifications WHERE id = ? AND org_id = ?', 'ii', [(int) ($_GET['vid'] ?? 0), ds_org()]);
    if (!$v || ($v['doc_id'] ? !ds_doc_can_view(ds_doc((int) $v['doc_id']) ?? ['org_id' => -1]) : ((int) $v['verified_by_id'] !== ds_me() && !ds_can('audit.view') && !ds_is_super()))) {
        fabo_deny('That report is not available.', 404);
    }
    ds_audit($v['doc_id'] ? (int) $v['doc_id'] : null, 'verification', (int) $v['id'], 'download', 'Downloaded verification report ' . $v['report_ref']);
    ds_verification_pdf($v)->send(preg_replace('/[^A-Za-z0-9]+/', '-', $v['report_ref']) . '.pdf');
}
if ($kind === 'dsc') {
    try {
        [$bytes, $name] = ds_dsc_download((int) ($_GET['req'] ?? 0));
    } catch (InvalidArgumentException $e) {
        inc_redirect(ds_url('sign', ['id' => (int) ($_GET['req'] ?? 0)]), ['err', $e->getMessage()]);
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: no-store, private');
    echo $bytes;
    exit;
}

$id = (int) ($_GET['doc'] ?? 0);
$doc = ds_doc_open($id);
switch ($kind) {
    case 'page':
        // A page picture for the viewer: only of a file that belongs to this document.
        $fid = (int) ($_GET['f'] ?? 0);
        if (!fabo_one('SELECT 1 FROM ds_files WHERE id = ? AND doc_id = ? AND org_id = ? AND deleted_at IS NULL', 'iii', [$fid, $id, ds_org()])) {
            fabo_deny('That file is not available.', 404);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $n = max(1, (int) ($_GET['n'] ?? 1));
        $etag = '"' . substr((string) ds_file($fid)['sha256'], 0, 40) . '-' . $n . '"';
        header('Cache-Control: private, max-age=604800');
        header('ETag: ' . $etag);
        header('X-Content-Type-Options: nosniff');
        if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
            http_response_code(304);
            exit;
        }
        try {
            $img = ds_preview_page($fid, $n);
        } catch (Throwable $ex) {
            header('Cache-Control: no-store');
            fabo_deny('That page cannot be shown.', 404);
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . strlen($img));
        echo $img;
        exit;
    case 'current':
        $fid = (int) ($doc['signed_file_id'] ?: $doc['working_file_id']);
        // A URL naming the exact file (f=) may be kept privately: a later signature is a new file, so a new URL.
        ds_send_file($fid, $doc['ref_no'] . ($doc['status'] === 'signed' ? '-signed' : '') . '.pdf', $inline, $id, $inline ? 'preview' : 'current PDF',
                     (int) ($_GET['f'] ?? 0) === $fid);
        // no break: ds_send_file exits
    case 'version':
        $v = fabo_one('SELECT * FROM ds_document_versions WHERE id = ? AND doc_id = ?', 'ii', [(int) ($_GET['v'] ?? 0), $id]);
        if (!$v) {
            fabo_deny('That version does not exist.', 404);
        }
        ds_send_file((int) $v['file_id'], $doc['ref_no'] . '-v' . $v['version_no'] . '.pdf', $inline, $id, 'version ' . $v['version_no']);
    case 'attachment':
        $a = fabo_one('SELECT * FROM ds_document_attachments WHERE id = ? AND doc_id = ? AND deleted_at IS NULL', 'ii', [(int) ($_GET['a'] ?? 0), $id]);
        if (!$a) {
            fabo_deny('That attachment does not exist.', 404);
        }
        $f = ds_file((int) $a['file_id']);
        ds_send_file((int) $a['file_id'], (string) $f['original_name'], false, $id, 'attachment');
    case 'certificate':
        if (!(int) $doc['cycle']) {
            fabo_deny('This document has not been sent for signature.', 404);
        }
        ds_audit($id, 'document', $id, 'download', ds_actor() . ' downloaded the signature certificate of ' . $doc['ref_no']);
        ds_certificate_pdf($doc)->send(preg_replace('/[^A-Za-z0-9]+/', '-', $doc['ref_no']) . '-signature-certificate.pdf');
}
fabo_deny('Unknown file.', 404);

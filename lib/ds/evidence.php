<?php
/**
 * Digital Signatures — evidence: verification records and the two PDFs a
 * relying party asks for.
 *
 *   Verification report   what was checked, how, and the result, for one
 *                         PDF at one moment (kept, with its reference).
 *   Signature certificate everything that happened to a document: who acted,
 *                         how, when, from where, with which certificate; the
 *                         hashes of the version signed and of the final PDF;
 *                         and the seal over each record.
 */

require_once __DIR__ . '/../hr/pdf.php';

/**
 * Verifies a PDF and keeps the result. For a repository document the
 * report also says whether every mandatory digital signature is present
 * (each signatory's recorded certificate found, intact) and lists the
 * electronic approvals with their seals checked. Returns the record id.
 */
function ds_verify_record(?int $docId, ?int $fileId, string $source, ?string $bytes = null, ?string $name = null): int
{
    if ($bytes === null) {
        $bytes = ds_read((int) $fileId);
        $name = $name ?? (string) (ds_file((int) $fileId)['original_name'] ?? 'document.pdf');
    }
    $report = ds_verify_pdf($bytes, ['anchors' => ds_anchors(), 'fetch' => ds_fetcher()]);
    $mandOk = null;
    if ($docId) {
        $report['mandatory'] = ds_mandatory_check($docId, $report);
        $mandOk = $report['mandatory']['ok'] ? 1 : 0;
        $report['approvals'] = ds_approvals_check($docId);
    }
    $ref = ds_next_ref('vr');
    $id = fabo_exec('INSERT INTO ds_signature_verifications (org_id, report_ref, doc_id, file_id, source, file_name, sha256, overall, signature_count, mandatory_ok, report_json, verified_by_id, verified_by)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', 'isiissssiisis',
                    [ds_org(), $ref, $docId, $fileId, $source, mb_substr((string) $name, 0, 255), $report['sha256'], $report['overall'], count($report['signatures']), $mandOk,
                     json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR), ds_me(), ds_actor()]);
    ds_audit($docId, 'verification', $id, 'verify', 'Verified ' . ($docId ? (ds_doc($docId)['ref_no'] ?? 'a document') : '"' . $name . '"') . ': '
        . ds_verdicts()[$report['overall']][0] . ' (' . count($report['signatures']) . ' signature' . (count($report['signatures']) === 1 ? '' : 's') . ', report ' . $ref . ')',
        ['sha256' => $report['sha256'], 'source' => $source]);
    return $id;
}

/** Are the document's mandatory digital signatures all in the file, intact, by the certificates recorded at signing? */
function ds_mandatory_check(int $docId, array $report): array
{
    $doc = ds_doc($docId);
    $items = [];
    $ok = true;
    if (!$doc || (int) $doc['cycle'] === 0) {
        return ['ok' => false, 'items' => [], 'message' => 'The document has not been sent for signature.'];
    }
    foreach (ds_doc_requests($docId, (int) $doc['cycle']) as $r) {
        if ((int) $r['mandatory'] !== 1 || $r['action'] === 'review') {
            continue;
        }
        $done = in_array($r['status'], ds_done_statuses(), true);
        $item = ['person' => ds_req_name($r), 'stage' => $r['stage_name'], 'method' => $r['used_method'] ?: $r['method'], 'status' => $r['status'],
                 'digital' => ds_method_is_digital($r['used_method']), 'found' => null, 'verdict' => null];
        if (!$done) {
            $ok = false;
        } elseif ($item['digital']) {
            $ev = json_decode((string) $r['evidence_json'], true) ?: [];
            $fp = (string) ($ev['certificate']['fingerprint'] ?? '');
            $item['found'] = false;
            foreach ($report['signatures'] as $s) {
                if ($fp !== '' && ($s['signer']['fingerprint'] ?? '') === $fp) {
                    $item['found'] = true;
                    $item['verdict'] = $s['verdict'];
                    if (in_array($s['verdict'], ['invalid', 'modified', 'revoked'], true)) {
                        $ok = false;
                    }
                    break;
                }
            }
            if (!$item['found']) {
                $ok = false;
            }
        }
        $items[] = $item;
    }
    return ['ok' => $ok, 'items' => $items, 'message' => $ok ? 'Every mandatory signatory has acted, and every mandatory digital signature is present and intact.'
        : 'One or more mandatory signatures are missing, pending or no longer intact.'];
}

/** Electronic approvals and drawn signatures of the current cycle, with their seals re-checked. */
function ds_approvals_check(int $docId): array
{
    $doc = ds_doc($docId);
    $out = [];
    foreach (ds_doc_requests($docId, (int) ($doc['cycle'] ?? 0)) as $r) {
        if (!in_array($r['used_method'], ['eapproval', 'drawn', 'review'], true) || !$r['evidence_json']) {
            continue;
        }
        $ev = json_decode((string) $r['evidence_json'], true) ?: [];
        $out[] = ['person' => ds_req_name($r), 'method' => ds_method_label($r['used_method']), 'at' => $r['acted_at'],
                  'sealed' => $r['seal'] !== null && hash_equals((string) $r['seal'], ds_seal($ev))];
    }
    return $out;
}

/* ============================================================ the PDFs */

/** Wraps text into lines that fit $w at $size. */
function ds_pdf_wrap(HrPdf $p, string $s, float $w, float $size, bool $bold = false): array
{
    return $p->wrap(DsPdfUpdate::plain($s), $w, $size, $bold);
}

/** The verification report for a stored verification. */
function ds_verification_pdf(array $row): HrPdf
{
    $rep = json_decode((string) $row['report_json'], true) ?: [];
    $p = new HrPdf();
    $W = $p->width();
    $H = $p->height();
    $y = 0;
    $page = 0;
    $new = function () use ($p, $row, &$y, &$page, $W) {
        $p->addPage();
        $page++;
        $p->rectRgb(0, 0, $W, 6, '1d5fd0');
        $p->text(36, 40, 'Digital Signature Verification Report', 15, true);
        $p->text(36, 56, DsPdfUpdate::plain(ds_org_name()) . '  ·  Report ' . $row['report_ref'], 9, false, 'left', 0.4);
        $p->text($W - 36, 40, 'Page ' . $page, 8, false, 'right', 0.5);
        $y = 76;
    };
    $need = function (float $h) use (&$y, $H, $new) {
        if ($y + $h > $H - 40) {
            $new();
        }
    };
    $line = function (string $label, string $value, float $lw = 130) use ($p, &$y, $W, $need) {
        $lines = $p->wrap(DsPdfUpdate::plain($value), $W - 72 - $lw, 9);
        $need(13 * max(1, count($lines)));
        $p->text(36, $y, DsPdfUpdate::plain($label), 9, true, 'left', 0.35);
        foreach ($lines ?: [''] as $i => $l) {
            $p->text(36 + $lw, $y + $i * 12, $l, 9);
        }
        $y += 12 * max(1, count($lines)) + 3;
    };
    $new();
    $verd = ds_verdicts()[$rep['overall'] ?? 'unsigned'] ?? ['Unknown', ''];
    $tone = ['green' => '12805c', 'amber' => 'b26b00', 'red' => 'b3261e'][$verd[1]] ?? '5f6979';
    $p->rectRgb(36, $y - 4, $W - 72, 30, ['green' => 'e6f5ec', 'amber' => 'fdf3e0', 'red' => 'fdecea'][$verd[1]] ?? 'eef0f4');
    $p->textRgb(48, $y + 15, 'Result: ' . $verd[0], 13, true, 'left', $tone);
    $y += 40;
    $doc = $row['doc_id'] ? ds_doc((int) $row['doc_id']) : null;
    if ($doc) {
        $line('Document', $doc['ref_no'] . ' — ' . $doc['title']);
    }
    $line('File', (string) $row['file_name']);
    $line('SHA-256', (string) $row['sha256']);
    $line('Size / pages', number_format((int) ($rep['size'] ?? 0)) . ' bytes' . (isset($rep['pages']) ? ' · ' . $rep['pages'] . ' pages' : ''));
    $line('Verified', ds_fmt_dt($row['created_at']) . ' by ' . $row['verified_by'] . ' (' . $row['source'] . ')');
    $line('Trust store', (int) ($rep['anchors'] ?? 0) . ' trusted certificate(s)');
    if (!empty($rep['problem'])) {
        $line('Problem', $rep['problem']);
    }
    foreach ($rep['signatures'] ?? [] as $s) {
        $need(70);
        $y += 8;
        $v = ds_verdicts()[$s['verdict']] ?? ['?', ''];
        $p->rect(36, $y - 10, $W - 72, 18, 0.95);
        $p->text(44, $y + 2, DsPdfUpdate::plain(($s['kind'] === 'timestamp' ? 'Document timestamp ' : 'Signature ') . $s['index'] . ' — ' . ($s['signer']['cn'] ?? $s['name'] ?? 'unknown signer')), 10, true);
        $p->textRgb($W - 44, $y + 2, $v[0], 9, true, 'right', ['green' => '12805c', 'amber' => 'b26b00', 'red' => 'b3261e'][$v[1]] ?? '5f6979');
        $y += 18;
        if (!empty($s['signer'])) {
            $line('Signer', $s['signer']['cn'] . ($s['signer']['email'] ? ' <' . $s['signer']['email'] . '>' : '') . ($s['signer']['org'] ? ', ' . $s['signer']['org'] : ''));
            $line('Issued by', $s['signer']['issuer_cn'] . ($s['signer']['issuer_org'] ? ', ' . $s['signer']['issuer_org'] : ''));
            $line('Serial / validity', $s['signer']['serial'] . ' · ' . gmdate('d M Y', $s['signer']['not_before']) . ' to ' . gmdate('d M Y', $s['signer']['not_after']));
            $line('Fingerprint', $s['signer']['fingerprint']);
        }
        $line('Signing time', ($s['signing_time'] ? gmdate('d M Y H:i:s', (int) $s['signing_time']) . ' UTC' : '—') . ' (' . $s['time_source'] . ')');
        if ($s['reason'] || $s['location']) {
            $line('Reason / place', trim(($s['reason'] ?? '') . ' ' . ($s['location'] ? '· ' . $s['location'] : '')));
        }
        $line('Covers', $s['covers_whole'] ? 'The whole file' : 'Revision of ' . number_format($s['revision_len']) . ' bytes (later revisions: ' . ($s['modifications']['status'] ?? '?') . ')');
        foreach ($s['checks'] as $c) {
            $line(['pass' => 'PASS  ', 'warn' => 'CHECK ', 'fail' => 'FAIL  '][$c['state']] . $c['label'], $c['message']);
        }
    }
    if (!empty($rep['mandatory'])) {
        $need(40);
        $y += 8;
        $p->text(36, $y, 'Mandatory signatures', 11, true);
        $y += 16;
        $line('Result', $rep['mandatory']['message']);
        foreach ($rep['mandatory']['items'] as $it) {
            $line($it['person'], ds_method_label($it['method']) . ' — ' . (ds_request_statuses()[$it['status']][0] ?? $it['status'])
                . ($it['digital'] ? ($it['found'] ? ' — signature present (' . (ds_verdicts()[$it['verdict']][0] ?? '') . ')' : ' — SIGNATURE NOT FOUND') : ''));
        }
    }
    if (!empty($rep['approvals'])) {
        $need(40);
        $y += 8;
        $p->text(36, $y, 'Electronic approvals and drawn signatures (not digital signatures)', 11, true);
        $y += 16;
        foreach ($rep['approvals'] as $a) {
            $line($a['person'], $a['method'] . ' on ' . ds_fmt_dt($a['at']) . ' — record seal ' . ($a['sealed'] ? 'intact' : 'BROKEN'));
        }
    }
    $need(50);
    $y += 10;
    foreach ($p->wrap('A digital signature is valid only when its integrity, certificate chain to a trusted authority, certificate validity and revocation status are all confirmed. '
        . '"Verification incomplete" means a check could not be completed (for example, the revocation service could not be reached) and must not be relied on as valid.', $W - 72, 7.5) as $l) {
        $p->text(36, $y, $l, 7.5, false, 'left', 0.45);
        $y += 10;
    }
    return $p;
}

/** The signature certificate: the document's whole signing record. */
function ds_certificate_pdf(array $doc): HrPdf
{
    $p = new HrPdf();
    $W = $p->width();
    $H = $p->height();
    $y = 0;
    $page = 0;
    $new = function () use ($p, $doc, &$y, &$page, $W) {
        $p->addPage();
        $page++;
        $p->rectRgb(0, 0, $W, 6, '12805c');
        $p->text(36, 40, 'Signature Certificate', 16, true);
        $p->text(36, 56, DsPdfUpdate::plain(ds_org_name() . '  ·  ' . $doc['ref_no'] . '  ·  Tracking ' . $doc['tracking_code']), 9, false, 'left', 0.4);
        $p->text($W - 36, 40, 'Page ' . $page, 8, false, 'right', 0.5);
        $y = 78;
    };
    $need = function (float $h) use (&$y, $H, $new) {
        if ($y + $h > $H - 40) {
            $new();
        }
    };
    $kv = function (string $k, string $v) use ($p, &$y, $W, $need) {
        $lines = $p->wrap(DsPdfUpdate::plain($v), $W - 72 - 120, 9);
        $need(12 * max(1, count($lines)) + 2);
        $p->text(36, $y, DsPdfUpdate::plain($k), 9, true, 'left', 0.35);
        foreach ($lines ?: [''] as $i => $l) {
            $p->text(156, $y + $i * 12, $l, 9);
        }
        $y += 12 * max(1, count($lines)) + 2;
    };
    $new();
    $kv('Title', $doc['title']);
    $kv('Status', ds_doc_statuses()[$doc['status']][0] . ($doc['completed_at'] ? ' on ' . ds_fmt_dt($doc['completed_at']) : ''));
    $kv('Owner', ds_person_name((int) $doc['owner_id']) . ' · ' . ds_dept_name($doc['department_id'] ? (int) $doc['department_id'] : null) . ($doc['unit'] ? ' · ' . $doc['unit'] : ''));
    $kv('Category', ds_category_name($doc['category_id'] ? (int) $doc['category_id'] : null));
    $ver = fabo_one('SELECT * FROM ds_document_versions WHERE id = ?', 'i', [(int) $doc['current_version_id']]);
    if ($ver) {
        $kv('Version signed', 'Version ' . $ver['version_no'] . ' · ' . $ver['pages'] . ' page(s) · SHA-256 ' . $ver['sha256']);
    }
    $final = ds_file((int) ($doc['signed_file_id'] ?: $doc['working_file_id']));
    if ($final) {
        $kv('Final PDF', 'SHA-256 ' . $final['sha256'] . ' · ' . number_format((int) $final['size']) . ' bytes');
    }
    $kv('Sent', ds_fmt_dt($doc['submitted_at']) . ' (signing cycle ' . $doc['cycle'] . ')');
    $y += 6;
    $need(30);
    $p->text(36, $y, 'Signatories', 12, true);
    $y += 16;
    foreach (ds_doc_requests((int) $doc['id'], (int) $doc['cycle']) as $r) {
        $ev = json_decode((string) $r['evidence_json'], true) ?: [];
        $need(70);
        $p->rect(36, $y - 9, $W - 72, 16, 0.955);
        $st = ds_request_statuses()[$r['status']][0] ?? $r['status'];
        $p->text(44, $y + 2, DsPdfUpdate::plain(ds_req_name($r) . ($r['on_behalf_of_id'] ? ' (on behalf of ' . ds_person_name((int) $r['on_behalf_of_id']) . ')' : '')), 9.5, true);
        $p->text($W - 44, $y + 2, DsPdfUpdate::plain($st . ($r['used_method'] ? ' · ' . ds_method_label($r['used_method']) : '')), 8.5, true, 'right', 0.3);
        $y += 16;
        $kv('Stage', $r['stage_name'] . ' · ' . (ds_actions()[$r['action']] ?? $r['action']) . ((int) $r['mandatory'] ? ' · mandatory' : ' · optional'));
        if ($r['acted_at']) {
            $kv('When / where', ds_fmt_dt($r['acted_at']) . (isset($ev['ip']) ? ' · IP ' . $ev['ip'] : ''));
        }
        if (!empty($ev['designation'])) {
            $kv('Designation', $ev['designation']);
        }
        if (!empty($ev['certificate'])) {
            $kv('Certificate', $ev['certificate']['subject'] . ' · issued by ' . $ev['certificate']['issuer'] . ' · serial ' . $ev['certificate']['serial']);
            $kv('Fingerprint', $ev['certificate']['fingerprint']);
            if (!empty($ev['transaction'])) {
                $kv('Transaction', $ev['transaction'] . (!empty($ev['esp']) ? ' · ' . $ev['esp'] : ''));
            }
        }
        if ($r['used_method'] === 'eapproval' || $r['used_method'] === 'drawn') {
            $kv('Nature', 'Electronic record confirmed with the portal password. Not a digital signature under the IT Act.');
        }
        if ($r['remarks']) {
            $kv('Remarks', (string) $r['remarks']);
        }
        if ($r['seal']) {
            $kv('Record seal', $r['seal'] . (hash_equals((string) $r['seal'], ds_seal($ev)) ? ' (intact)' : ' (BROKEN)'));
        }
        $y += 4;
    }
    $need(40);
    [$ok, $n, , $msg] = ds_audit_check();
    $last = fabo_one('SELECT seq, hash FROM ds_audit WHERE org_id = ? ORDER BY seq DESC LIMIT 1', 'i', [ds_org()]);
    $y += 6;
    $kv('Audit trail', $msg . ($last ? ' Head #' . $last['seq'] . ' ' . substr((string) $last['hash'], 0, 32) . '…' : ''));
    $kv('Generated', date('d M Y, H:i T') . ' by ' . ds_actor());
    $y += 6;
    foreach ($p->wrap('Verify this document at any time from the portal\'s Verification page with tracking code ' . $doc['tracking_code'] . ', or by uploading the signed PDF.', $W - 72, 8) as $l) {
        $need(12);
        $p->text(36, $y, $l, 8, false, 'left', 0.45);
        $y += 11;
    }
    return $p;
}

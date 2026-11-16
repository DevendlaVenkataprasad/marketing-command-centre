<?php
/**
 * Verification: upload any signed PDF, or pick one from the repository, and
 * get the full check — kept as a report with its own reference.
 */

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $flash = ds_action(static function () {
        require_once __DIR__ . '/../../throttle.php';
        if (fabo_throttle_blocked('ds-verify', 'p' . ds_me(), 40, 3600)) {
            throw new InvalidArgumentException('Too many verifications this hour. Try again later.');
        }
        fabo_throttle_hit('ds-verify', 'p' . ds_me());
        if (($_POST['source'] ?? '') === 'repo') {
            $ref = trim((string) ($_POST['ref'] ?? ''));
            $d = fabo_one('SELECT * FROM ds_documents WHERE org_id = ? AND deleted_at IS NULL AND (ref_no = ? OR tracking_code = ? OR id = ?)', 'issi', [ds_org(), $ref, strtoupper($ref), (int) $ref]);
            if (!$d || !ds_doc_can_view($d)) {
                throw new InvalidArgumentException('No document you can see has that reference or tracking code.');
            }
            $vid = ds_verify_record((int) $d['id'], (int) ($d['signed_file_id'] ?: $d['working_file_id']), 'repository');
        } else {
            $up = ds_upload_bytes('pdf');
            if (!$up) {
                throw new InvalidArgumentException('Choose the signed PDF to verify.');
            }
            if (strlen($up[0]) > max(1, (int) ds_setting('max_upload_mb')) * 1048576 || !str_contains(substr($up[0], 0, 1024), '%PDF-')) {
                throw new InvalidArgumentException('Upload a PDF of up to ' . ds_setting('max_upload_mb') . ' MB.');
            }
            // A copy of a repository document is checked against that document's signatories too.
            $match = fabo_one('SELECT d.id FROM ds_files f JOIN ds_documents d ON d.id = f.doc_id WHERE f.org_id = ? AND f.sha256 = ? AND d.deleted_at IS NULL LIMIT 1', 'is', [ds_org(), hash('sha256', $up[0])]);
            $docId = $match && ds_doc_can_view(ds_doc((int) $match['id'])) ? (int) $match['id'] : null;
            $vid = ds_verify_record($docId, null, 'upload', $up[0], $up[1]);
        }
        inc_redirect(ds_url('verify', ['vid' => $vid]), ['ok', 'Verification complete.']);
    }, ds_url('verify'));
}

$row = null;
if (($vid = (int) ($_GET['vid'] ?? 0)) > 0) {
    $row = fabo_one('SELECT * FROM ds_signature_verifications WHERE id = ? AND org_id = ?', 'ii', [$vid, ds_org()]);
    if ($row && ($row['doc_id'] ? !ds_doc_can_view(ds_doc((int) $row['doc_id']) ?? ['org_id' => -1]) : ((int) $row['verified_by_id'] !== ds_me() && !ds_can('audit.view') && !ds_is_super()))) {
        $row = null;
    }
}

ds_layout_start('Verify signatures', 'Check any signed PDF: integrity, certificate chain to a trusted CA, validity, revocation and changes after signing.',
    $row ? '<a class="ds-btn ds-btn-ghost" href="' . e(ds_url('verify')) . '">' . ds_icon('refresh') . 'Verify another</a><a class="ds-btn ds-btn-primary" href="' . e(ds_url('file', ['kind' => 'report', 'vid' => $row['id']])) . '">' . ds_icon('download') . 'Report (PDF)</a>' : '',
    $row ? ['Verify' => ds_url('verify'), $row['report_ref'] => null] : [], $flash);

if (!$row) {
    echo '<div class="ds-grid g-1-1">';
    echo ds_card_start('Upload a signed PDF', '', '', 'upload') . ds_form('data-busy-form', true) . '<input type="hidden" name="source" value="upload">'
       . '<label class="ds-drop" data-drop><input type="file" name="pdf" accept="application/pdf,.pdf" required><span class="ds-drop-i">' . ds_icon('shield-check') . '</span><strong>Drop a signed PDF</strong><span>or <u>browse</u></span><span class="ds-drop-f" data-drop-name hidden></span></label>'
       . '<button class="ds-btn ds-btn-primary ds-btn-block" data-busy="Verifying…">' . ds_icon('shield-check') . 'Verify</button></form>' . ds_card_end();
    echo ds_card_start('Or a document in the repository', '', '', 'files') . ds_form('data-busy-form') . '<input type="hidden" name="source" value="repo">'
       . ds_field('Reference or tracking code', '<input name="ref" required placeholder="' . e(ds_setting('ref_prefix')) . '/26-27/000001 or tracking code" list="ds-reflist">')
       . '<datalist id="ds-reflist">';
    [$w, $t, $p] = ds_visible_sql();
    foreach (fabo_all('SELECT d.ref_no, d.title FROM ds_documents d WHERE ' . $w . ' AND d.cycle > 0 ORDER BY d.last_activity_at DESC LIMIT 40', $t, $p) as $d) {
        echo '<option value="' . e($d['ref_no']) . '">' . e($d['title']) . '</option>';
    }
    echo '</datalist><button class="ds-btn ds-btn-primary ds-btn-block" data-busy="Verifying…">' . ds_icon('shield-check') . 'Verify document</button></form>'
       . '<div class="ds-legal">' . ds_icon('alert') . '<p>A check that cannot be completed — an unreachable revocation service, an untrusted root — is shown as <strong>Verification incomplete</strong>, never as valid.</p></div>' . ds_card_end();
    echo '</div>';
    $recent = fabo_all('SELECT * FROM ds_signature_verifications WHERE org_id = ? AND verified_by_id = ? ORDER BY id DESC LIMIT 8', 'ii', [ds_org(), ds_me()]);
    if ($recent) {
        echo ds_card_start('Your recent verifications', '', '', 'history') . '<ul class="ds-list">';
        foreach ($recent as $v) {
            echo '<li><a href="' . e(ds_url('verify', ['vid' => $v['id']])) . '"><span class="ds-fileic">' . ds_icon('shield') . '</span><span class="ds-li-b"><strong>' . e((string) $v['file_name']) . '</strong><small>'
               . e($v['report_ref']) . ' · ' . e(ds_fmt_dt($v['created_at'])) . '</small></span>' . ds_verdict_badge($v['overall']) . '</a></li>';
        }
        echo '</ul>' . ds_card_end();
    }
    ds_layout_end();
    return;
}

$rep = json_decode((string) $row['report_json'], true) ?: [];
[$vl, $tone] = ds_verdicts()[$row['overall']] ?? ['?', ''];
$icon = ['green' => 'shield-check', 'amber' => 'alert', 'red' => 'x-circle'][$tone] ?? 'shield';
$doc = $row['doc_id'] ? ds_doc((int) $row['doc_id']) : null;
echo '<div class="ds-verdict t-' . e($tone ?: 'grey') . ' ds-rise"><div class="ds-verdict-i"><svg viewBox="0 0 64 64" aria-hidden="true"><circle cx="32" cy="32" r="28"/></svg>' . ds_icon($icon) . '</div><div>'
   . '<div class="ds-eyebrow">Report ' . e($row['report_ref']) . ' · ' . e(ds_fmt_dt($row['created_at'])) . '</div><h2>' . e($vl) . '</h2><p>' . e((string) $row['file_name'])
   . ($doc ? ' · <a href="' . e(ds_url('doc', ['id' => $doc['id']])) . '">' . e($doc['ref_no']) . '</a>' : '') . ' · ' . count($rep['signatures'] ?? []) . ' signature' . (count($rep['signatures'] ?? []) === 1 ? '' : 's')
   . ' · ' . (int) ($rep['anchors'] ?? 0) . ' trusted roots</p><p class="mono small">SHA-256 ' . e($row['sha256']) . '</p></div></div>';
if (!empty($rep['problem'])) {
    echo '<div class="ds-note warn">' . ds_icon('alert') . '<span>' . e($rep['problem']) . '</span></div>';
}
if (!($rep['signatures'] ?? [])) {
    echo ds_empty('This PDF carries no digital signature', 'Electronic approvals and drawn signatures are not digital signatures; they are verified from the document\'s record, below when it is a repository document.', 'file');
}
foreach ($rep['signatures'] ?? [] as $i => $s) {
    [$sl, $st] = ds_verdicts()[$s['verdict']] ?? ['?', ''];
    $c = $s['signer'] ?? null;
    echo '<section class="ds-card ds-sigrep ds-rise" style="--i:' . ($i + 1) . '"><div class="ds-sigrep-h"><span class="ds-sigrep-n">' . (int) $s['index'] . '</span><div><strong>'
       . e($s['kind'] === 'timestamp' ? 'Document timestamp' : ($c['cn'] ?? ($s['name'] ?: 'Unknown signer'))) . '</strong><small>'
       . ($c ? e(($c['email'] ? $c['email'] . ' · ' : '') . 'issued by ' . $c['issuer_cn']) : '') . ($s['signing_time'] ? ' · signed ' . e(date('d M Y, H:i', (int) $s['signing_time'])) . ' (' . e($s['time_source']) . ')' : '') . '</small></div>'
       . ds_badge($sl, $st) . '</div><ul class="ds-checks">';
    foreach ($s['checks'] as $j => $ch) {
        echo '<li class="c-' . e($ch['state']) . '" style="--j:' . $j . '"><span class="ds-check-i">' . ds_icon(['pass' => 'check', 'warn' => 'alert', 'fail' => 'x'][$ch['state']]) . '</span><div><strong>' . e($ch['label']) . '</strong><p>' . e($ch['message']) . '</p></div></li>';
    }
    echo '</ul>';
    if ($c) {
        echo '<details class="ds-certd"><summary>' . ds_icon('key') . 'Certificate and chain</summary><dl class="ds-kv"><div><dt>Subject</dt><dd>' . e($c['subject_dn']) . '</dd></div><div><dt>Issuer</dt><dd>' . e($c['issuer_dn']) . '</dd></div>'
           . '<div><dt>Serial</dt><dd class="mono">' . e($c['serial']) . '</dd></div><div><dt>Valid</dt><dd>' . e(gmdate('d M Y', $c['not_before']) . ' – ' . gmdate('d M Y', $c['not_after'])) . '</dd></div>'
           . '<div><dt>Fingerprint (SHA-256)</dt><dd class="mono">' . e($c['fingerprint']) . '</dd></div><div><dt>Key usage</dt><dd>' . e($c['key_usage'] ?: '—') . '</dd></div>'
           . '<div><dt>Format</dt><dd>' . e(($s['subfilter'] ?? '') . ($s['pades'] ? ' · PAdES attributes present' : '')) . '</dd></div><div><dt>Byte range</dt><dd class="mono">' . e(implode(' ', $s['range'])) . '</dd></div></dl>';
        if (!empty($s['chain']['path'])) {
            echo '<ol class="ds-chain">';
            foreach ($s['chain']['path'] as $k => $pc) {
                echo '<li>' . ds_icon($k === count($s['chain']['path']) - 1 && $s['chain']['status'] === 'trusted' ? 'shield-check' : 'key') . '<span><strong>' . e($pc['cn']) . '</strong><small>' . e($pc['org']) . ' · to ' . e(gmdate('d M Y', (int) $pc['not_after'])) . '</small></span></li>';
            }
            echo '</ol>';
        }
        echo '</details>';
    }
    echo '</section>';
}
if (!empty($rep['mandatory'])) {
    echo ds_card_start('Mandatory signatures', '', '', 'users') . '<div class="ds-note ' . ($rep['mandatory']['ok'] ? 'ok' : 'warn') . '">' . ds_icon($rep['mandatory']['ok'] ? 'check-circle' : 'alert') . '<span>' . e($rep['mandatory']['message']) . '</span></div><ul class="ds-list">';
    foreach ($rep['mandatory']['items'] as $it) {
        echo '<li><span class="ds-li-x"><span class="ds-li-b"><strong>' . e($it['person']) . '</strong><small>' . e((string) $it['stage']) . '</small></span>' . ds_method_chip($it['method']) . ds_req_badge($it['status'])
           . ($it['digital'] ? ($it['found'] ? ds_verdict_badge((string) $it['verdict']) : ds_badge('Signature not found', 'red')) : '') . '</span></li>';
    }
    echo '</ul>' . ds_card_end();
}
if (!empty($rep['approvals'])) {
    echo ds_card_start('Electronic approvals & drawn signatures', '', '', 'check') . '<p class="ds-muted small">Recorded and sealed by this portal; not digital signatures under the IT Act.</p><ul class="ds-list">';
    foreach ($rep['approvals'] as $a) {
        echo '<li><span class="ds-li-x"><span class="ds-li-b"><strong>' . e($a['person']) . '</strong><small>' . e($a['method']) . ' · ' . e(ds_fmt_dt($a['at'])) . '</small></span>' . ($a['sealed'] ? ds_badge('Seal intact', 'green') : ds_badge('Seal broken', 'red')) . '</span></li>';
    }
    echo '</ul>' . ds_card_end();
}
ds_layout_end();

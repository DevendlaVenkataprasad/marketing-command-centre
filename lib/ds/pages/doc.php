<?php
/**
 * One document: the PDF, where it stands, everything that happened to it,
 * and what the viewer may do next.
 */

require_once __DIR__ . '/_routing.php';

$id = (int) ($_GET['id'] ?? 0);
$doc = ds_doc_open($id, $_SERVER['REQUEST_METHOD'] !== 'POST');
$self = ds_url('doc', ['id' => $id, 'tab' => $_GET['tab'] ?? null]);
$manage = ds_doc_can_manage($doc);
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    $ver = $_POST['version'] ?? $doc['version'];
    $flash = ds_action(static function () use ($a, $id, $ver, $doc) {
        switch ($a) {
            case 'update':
                return ds_doc_update($id, $_POST, $ver);
            case 'new_version':
                $up = ds_upload_bytes('pdf');
                if (!$up) {
                    throw new InvalidArgumentException('Choose the new PDF.');
                }
                return ds_doc_new_version($id, $up[0], $up[1], ds_str($_POST['note'] ?? '', 400, false, 'Note'), $ver);
            case 'attach':
                $up = ds_upload_bytes('file');
                if (!$up) {
                    throw new InvalidArgumentException('Choose a file to attach.');
                }
                return ds_doc_attach($id, $up[0], $up[1], ds_str($_POST['title'] ?? '', 255));
            case 'comment':
                return ds_doc_comment($id, (string) ($_POST['body'] ?? ''));
            case 'share':
                return ds_doc_share($id, (int) ($_POST['person_id'] ?? 0), (int) ($_POST['department_id'] ?? 0), (string) ($_POST['access'] ?? 'view'));
            case 'unshare':
                return ds_doc_unshare($id, (int) ($_POST['perm_id'] ?? 0));
            case 'cancel':
                return ds_doc_cancel($id, (string) ($_POST['reason'] ?? ''), $ver);
            case 'reinitiate':
                return ds_reinitiate($id, $ver);
            case 'submit':
                // Route chosen: on to the studio to place each signatory's fields, and send from there.
                [$plan, $chosen] = ds_plan_from_post($_POST);
                ds_plan_prepare($id, $plan, $chosen);
                inc_redirect(ds_url('studio', ['id' => $id]), ['ok', 'Now place each signatory\x27s fields on the pages, then send it.']);
            case 'delete':
                $m = ds_doc_delete($id);
                inc_redirect(ds_url('documents'), ['ok', $m]);
            case 'verify':
                $vid = ds_verify_record($id, (int) ($doc['signed_file_id'] ?: $doc['working_file_id']), 'repository');
                inc_redirect(ds_url('verify', ['vid' => $vid]), ['ok', 'Verification complete.']);
            case 'remind':
                $r = fabo_one('SELECT * FROM ds_signature_requests WHERE id = ? AND doc_id = ? AND status = "pending"', 'ii', [(int) ($_POST['req'] ?? 0), $id]);
                if (!$r || (!ds_doc_can_manage($doc) && !ds_can('monitor.manage'))) {
                    throw new InvalidArgumentException('That request is not waiting.');
                }
                fabo_exec('UPDATE ds_signature_requests SET reminders = reminders + 1, last_reminded_at = NOW() WHERE id = ?', 'i', [(int) $r['id']]);
                ds_notify_req($r, 'reminder', 'Reminder from ' . ds_actor() . ': ' . $doc['ref_no'], '"' . $doc['title'] . '" is waiting for you.', $id, ['p' => 'sign', 'id' => (int) $r['id']]);
                ds_audit($id, 'request', (int) $r['id'], 'reminder', ds_actor() . ' reminded ' . ds_req_name($r));
                return 'Reminder sent to ' . ds_req_name($r) . '.';
            case 'hold':
                if (!ds_is_super()) {
                    fabo_deny('Only the super administrator sets a legal hold.', 403);
                }
                $on = (int) !((int) $doc['legal_hold']);
                fabo_exec('UPDATE ds_documents SET legal_hold = ? WHERE id = ?', 'ii', [$on, $id]);
                ds_audit($id, 'document', $id, $on ? 'hold_on' : 'hold_off', ($on ? 'Placed ' : 'Released ') . $doc['ref_no'] . ($on ? ' under legal hold' : ' from legal hold'));
                return $on ? 'Legal hold placed: it cannot be deleted or purged.' : 'Legal hold released.';
        }
        throw new InvalidArgumentException('Unknown action.');
    }, $self);
    $doc = ds_doc($id);
}

$st = $doc['status'];
$reqs = (int) $doc['cycle'] ? ds_doc_requests($id, (int) $doc['cycle']) : [];
$mine = array_values(array_filter($reqs, static fn($r) => $r['status'] === 'pending' && (int) $r['person_id'] === ds_me()));
$open = in_array($st, ds_open_statuses(), true);
$canSend = $manage && in_array($st, ['draft', 'reinitiated', 'rejected', 'changes_requested'], true);
$finalId = (int) ($doc['signed_file_id'] ?: $doc['working_file_id']);
$fileUrl = static fn(array $q) => ds_url('file', ['doc' => $id] + $q);
$ver = fabo_one('SELECT * FROM ds_document_versions WHERE id = ?', 'i', [(int) $doc['current_version_id']]);

$acts = '';
if ($mine) {
    $acts .= '<a class="ds-btn ds-btn-primary ds-pulse" href="' . e(ds_url('sign', ['id' => $mine[0]['id']])) . '">' . ds_icon('pen') . ['sign' => 'Sign now', 'approve' => 'Approve now', 'review' => 'Review now'][$mine[0]['action']] . '</a>';
}
if ($canSend) {
    if ($doc['draft_plan_json']) {
        $acts .= '<a class="ds-btn ds-btn-primary" href="' . e(ds_url('studio', ['id' => $id])) . '">' . ds_icon('move') . 'Continue placing fields</a>';
    }
    $acts .= '<button class="ds-btn ' . ($doc['draft_plan_json'] ? 'ds-btn-ghost' : 'ds-btn-primary') . '" type="button" data-open="dlg-send"' . (!empty($_GET['send']) ? ' data-autoopen' : '') . '>' . ds_icon('send') . ($st === 'draft' ? 'Send for signature' : 'Send again') . '</button>';
}
$acts .= '<div class="ds-menu"><button type="button" class="ds-btn ds-btn-ghost" data-menu aria-haspopup="true" aria-expanded="false">' . ds_icon('download') . 'Download' . ds_icon('chev-d') . '</button><div class="ds-menu-b" role="menu">'
       . '<a role="menuitem" href="' . e($fileUrl(['kind' => 'current', 'dl' => 1])) . '">' . ($st === 'signed' ? 'Signed PDF' : 'Current PDF (with signatures so far)') . '</a>'
       . '<a role="menuitem" href="' . e($fileUrl(['kind' => 'version', 'v' => $doc['current_version_id'], 'dl' => 1])) . '">Original upload (version ' . (int) ($ver['version_no'] ?? 1) . ')</a>'
       . ((int) $doc['cycle'] ? '<a role="menuitem" href="' . e($fileUrl(['kind' => 'certificate'])) . '">Signature certificate (PDF)</a>' : '') . '</div></div>';
if ($manage || ds_is_super()) {
    $more = '';
    if ($manage && !in_array($st, ['signed', 'cancelled', 'expired'], true)) {
        $more .= '<button type="button" role="menuitem" data-open="dlg-edit">' . ds_icon('edit') . 'Edit details</button><button type="button" role="menuitem" data-open="dlg-version">' . ds_icon('upload') . 'Upload new version</button>';
    }
    if ($manage && in_array($st, ['rejected', 'changes_requested', 'expired', 'cancelled'], true)) {
        $more .= ds_button('Reinitiate', ['action' => 'reinitiate', 'version' => $doc['version']], 'ds-menu-btn', 'Reinitiate ' . $doc['ref_no'] . '? It goes back to you to correct and send again.', 'refresh');
    }
    if ($manage && !in_array($st, ['signed', 'cancelled'], true)) {
        $more .= '<button type="button" role="menuitem" class="danger" data-open="dlg-cancel">' . ds_icon('x-circle') . 'Cancel document</button>';
    }
    if (ds_is_super()) {
        $more .= ds_button((int) $doc['legal_hold'] ? 'Release legal hold' : 'Place legal hold', ['action' => 'hold'], 'ds-menu-btn', '', 'lock');
    }
    if ($manage && ds_can('docs.delete') && !$open) {
        $more .= ds_button('Delete', ['action' => 'delete'], 'ds-menu-btn danger', 'Delete ' . $doc['ref_no'] . '? It leaves every list; its files and audit trail are kept until retention ends.', 'trash');
    }
    if ($more !== '') {
        $acts .= '<div class="ds-menu"><button type="button" class="ds-btn ds-btn-ghost ds-btn-icon" data-menu aria-label="More actions" aria-haspopup="true" aria-expanded="false">⋯</button><div class="ds-menu-b" role="menu">' . $more . '</div></div>';
    }
}

$sub = '<span class="mono">' . e($doc['ref_no']) . '</span> · ' . ds_status_badge($st) . ((int) $doc['cycle'] > 1 ? ' ' . ds_badge('Reinitiated · cycle ' . $doc['cycle'], 'blue', false) : '')
     . ' · ' . ds_priority_chip($doc['priority']) . ($doc['confidentiality'] !== 'internal' ? ' · ' . ds_badge(ds_confidentiality()[$doc['confidentiality']], 'grey', false) : '')
     . ((int) $doc['legal_hold'] ? ' · ' . ds_badge('Legal hold', 'red', false) : '');
ds_layout_start($doc['title'], $sub, $acts, ['Documents' => ds_url('documents'), $doc['ref_no'] => null], $flash);

// Progress strip
$tot = count(array_filter($reqs, static fn($r) => !in_array($r['status'], ['skipped', 'cancelled'], true)));
$done = count(array_filter($reqs, static fn($r) => in_array($r['status'], ds_done_statuses(), true)));
if ($tot) {
    $now = array_values(array_filter($reqs, static fn($r) => $r['status'] === 'pending'));
    echo '<div class="ds-strip ds-rise"><div class="ds-strip-l"><strong>' . $done . ' of ' . $tot . '</strong> signatories done' . ($now ? ' · waiting on ' . e(implode(', ', array_map(static fn($r) => ds_req_name($r), $now))) : '')
       . '</div><div class="ds-progress big"><i style="--w:' . round($done / $tot * 100) . '%"></i></div>'
       . ($doc['deadline'] ? '<div class="ds-strip-r">' . ds_icon('calendar') . 'Deadline ' . e(ds_fmt_date($doc['deadline'])) . '</div>' : '') . '</div>';
}

echo '<div class="ds-docgrid"><div class="ds-docgrid-l">';
$viewFid = (int) ($doc['signed_file_id'] ?: $doc['working_file_id']);
ds_preview_warm($viewFid);
echo '<div class="ds-viewer ds-rise" data-viewer' . ds_preview_attr($id, $viewFid) . ' data-src="' . e($fileUrl(['kind' => 'current', 'inline' => 1, 'f' => (int) ($doc['signed_file_id'] ?: $doc['working_file_id'])])) . '">'
   . '<div class="ds-viewer-bar"><span class="ds-viewer-t">' . ds_icon('file') . ($st === 'signed' ? 'Signed PDF' : 'Current PDF') . ' <small data-pageinfo></small></span><span class="ds-viewer-c">'
   . '<button type="button" class="ds-icobtn" data-zoom="-1" aria-label="Zoom out">' . ds_icon('zoom-out') . '</button><button type="button" class="ds-icobtn" data-zoom="1" aria-label="Zoom in">' . ds_icon('zoom-in') . '</button>'
   . '<a class="ds-icobtn" href="' . e($fileUrl(['kind' => 'current', 'inline' => 1])) . '" target="_blank" rel="noopener" aria-label="Open in a new tab">' . ds_icon('link') . '</a></span></div>'
   . '<div class="ds-viewer-pages" data-pages><div class="ds-skel"></div><div class="ds-skel"></div></div></div>';
echo '</div><div class="ds-docgrid-r">';

$tab = (string) ($_GET['tab'] ?? 'workflow');
$tabs = ['workflow' => ['Workflow', 'flow'], 'details' => ['Details', 'file'], 'activity' => ['Activity', 'history'], 'versions' => ['Versions', 'files'],
         'files' => ['Attachments', 'paperclip'], 'comments' => ['Comments', 'chat'], 'sharing' => ['Access', 'share'], 'verify' => ['Verification', 'shield-check']];
if (!isset($tabs[$tab])) {
    $tab = 'workflow';
}
$counts = ['comments' => count(ds_doc_comments($id)), 'files' => count(ds_doc_attachments($id)), 'versions' => count(ds_doc_versions($id))];
echo '<div class="ds-card ds-rise ds-dtabs" data-tabs><nav class="ds-tabs small" role="tablist">';
foreach ($tabs as $k => [$l, $ic]) {
    echo '<a role="tab" href="' . e(ds_url('doc', ['id' => $id, 'tab' => $k])) . '" data-tab="' . $k . '" aria-selected="' . ($k === $tab ? 'true' : 'false') . '"' . ($k === $tab ? ' class="on"' : '') . '>' . ds_icon($ic) . e($l)
       . (!empty($counts[$k]) ? ' <span>' . $counts[$k] . '</span>' : '') . '</a>';
}
echo '</nav>';

$pane = static fn(string $k) => '<div class="ds-tabpane" role="tabpanel" data-pane="' . $k . '"' . ($k === $tab ? '' : ' hidden') . '>';

/* Workflow */
echo $pane('workflow');
$plan = json_decode((string) $doc['plan_json'], true) ?: [];
if ($plan) {
    echo '<p class="ds-muted small">' . ($plan['workflow'] ? 'Workflow <strong>' . e($plan['workflow']) . '</strong>' : 'Signatories chosen for this document') . ' · cycle ' . (int) $doc['cycle']
       . ' · sent ' . e(ds_fmt_dt($doc['submitted_at'])) . ($doc['completed_at'] ? ' · completed ' . e(ds_fmt_dt($doc['completed_at'])) : '') . '</p>';
}
echo ds_stepper($doc, $reqs);
if ($open && ($manage || ds_can('monitor.manage'))) {
    foreach (array_filter($reqs, static fn($r) => $r['status'] === 'pending' && (int) $r['person_id'] !== ds_me()) as $r) {
        echo ds_button('Remind ' . ds_req_name($r), ['action' => 'remind', 'req' => $r['id']], 'ds-btn ds-btn-ghost ds-btn-sm', '', 'bell') . ' ';
    }
}
$older = (int) $doc['cycle'] > 1 ? fabo_all('SELECT * FROM ds_signature_requests WHERE doc_id = ? AND cycle < ? ORDER BY cycle DESC, stage_seq, seq', 'ii', [$id, (int) $doc['cycle']]) : [];
if ($older) {
    echo '<details class="ds-older"><summary>' . ds_icon('history') . 'Earlier cycles (' . count($older) . ' requests)</summary><table class="ds-table compact"><tbody>';
    foreach ($older as $r) {
        echo '<tr><td>Cycle ' . (int) $r['cycle'] . '</td><td>' . e(ds_req_name($r)) . '</td><td>' . e((string) $r['stage_name']) . '</td><td>' . ds_req_badge($r['status']) . '</td><td>' . e(ds_fmt_dt($r['acted_at'])) . '</td></tr>';
    }
    echo '</tbody></table></details>';
}
echo '</div>';

/* Details */
$kv = static fn(string $k, string $v) => '<div><dt>' . e($k) . '</dt><dd>' . $v . '</dd></div>';
echo $pane('details') . '<dl class="ds-kv">'
   . $kv('Reference', '<span class="mono">' . e($doc['ref_no']) . '</span>') . $kv('Tracking code', '<span class="mono ds-copy" data-copy="' . e($doc['tracking_code']) . '">' . e($doc['tracking_code']) . ' ' . ds_icon('link') . '</span>')
   . $kv('Category', e(ds_category_name($doc['category_id'] ? (int) $doc['category_id'] : null))) . $kv('Department', e(ds_dept_name($doc['department_id'] ? (int) $doc['department_id'] : null)))
   . $kv('Unit / spoke', e($doc['unit'] ?: '—')) . $kv('Owner', '<span class="ds-who">' . ds_avatar((int) $doc['owner_id'], 'sm') . e(ds_person_name((int) $doc['owner_id'])) . '</span>')
   . $kv('Created', e(ds_fmt_dt($doc['created_at'])) . ' by ' . e((string) $doc['created_by'])) . $kv('Document date', e(ds_fmt_date($doc['doc_date'])))
   . $kv('Valid until', e(ds_fmt_date($doc['valid_until']))) . $kv('Signing deadline', e(ds_fmt_date($doc['deadline'])))
   . $kv('Value', e(ds_money($doc['value_amount']))) . $kv('Confidentiality', e(ds_confidentiality()[$doc['confidentiality']]))
   . $kv('Retention until', e(ds_fmt_date($doc['retention_until']))) . $kv('Version', 'v' . (int) ($ver['version_no'] ?? 1) . ($ver['locked_at'] ? ' · ' . ds_icon('lock') . 'locked ' . e(ds_fmt_dt($ver['locked_at'])) : ''))
   . '</dl>' . ($doc['description'] ? '<h4>Description</h4><p class="ds-pre">' . e($doc['description']) . '</p>' : '') . ($doc['remarks'] ? '<h4>Remarks</h4><p class="ds-pre">' . e($doc['remarks']) . '</p>' : '')
   . '<p class="ds-hash">' . ds_icon('lock') . 'SHA-256 of version: <span class="mono">' . e((string) ($ver['sha256'] ?? '')) . '</span></p></div>';

/* Activity */
echo $pane('activity') . ds_timeline(ds_doc_timeline($id)) . '</div>';

/* Versions */
echo $pane('versions') . '<ul class="ds-list">';
foreach (ds_doc_versions($id) as $v) {
    echo '<li><a href="' . e($fileUrl(['kind' => 'version', 'v' => $v['id'], 'dl' => 1])) . '"><span class="ds-fileic">v' . (int) $v['version_no'] . '</span><span class="ds-li-b"><strong>Version ' . (int) $v['version_no']
       . ((int) $v['id'] === (int) $doc['current_version_id'] ? ' · current' : '') . '</strong><small>' . e(ds_fmt_dt($v['created_at'])) . ' · ' . e((string) $v['uploaded_by']) . ' · ' . (int) $v['pages'] . ' pages · '
       . e(fabo_human_size((int) $v['size'])) . ($v['note'] ? ' · ' . e($v['note']) : '') . '</small><small class="mono">' . e(substr($v['sha256'], 0, 32)) . '…</small></span>'
       . ($v['locked_at'] ? ds_badge('Locked', 'violet', false) : '') . ($v['superseded_at'] ? ds_badge('Superseded', 'grey', false) : '') . ds_icon('download') . '</a></li>';
}
echo '</ul>' . ($manage && !in_array($st, ['signed', 'cancelled', 'expired'], true) ? '<button type="button" class="ds-btn ds-btn-ghost ds-btn-sm" data-open="dlg-version">' . ds_icon('upload') . 'Upload new version</button>' : '') . '</div>';

/* Attachments */
echo $pane('files');
$atts = ds_doc_attachments($id);
echo $atts ? '<ul class="ds-list">' : ds_empty('No supporting files', '', 'paperclip');
foreach ($atts as $a) {
    echo '<li><a href="' . e($fileUrl(['kind' => 'attachment', 'a' => $a['id']])) . '"><span class="ds-fileic">' . ds_icon('paperclip') . '</span><span class="ds-li-b"><strong>' . e($a['title']) . '</strong><small>'
       . e(fabo_human_size((int) $a['size'])) . ' · ' . e((string) $a['created_by']) . ' · ' . e(ds_fmt_dt($a['created_at'])) . '</small></span>' . ds_icon('download') . '</a></li>';
}
echo $atts ? '</ul>' : '';
if ($manage) {
    echo ds_form('class="ds-inlineform"', true) . '<input type="hidden" name="action" value="attach"><input type="file" name="file" required aria-label="File"><input name="title" placeholder="Title (optional)" maxlength="255">'
       . '<button class="ds-btn ds-btn-sm">' . ds_icon('paperclip') . 'Attach</button></form>';
}
echo '</div>';

/* Comments */
echo $pane('comments') . '<div class="ds-comments">';
foreach (ds_doc_comments($id) as $c) {
    echo '<div class="ds-comment' . ((int) $c['person_id'] === ds_me() ? ' me' : '') . '">' . ds_avatar((int) $c['person_id'], 'sm') . '<div><div class="ds-comment-m"><strong>' . e($c['author']) . '</strong> · ' . e(ds_ago($c['created_at'])) . '</div><p>' . nl2br(e($c['body'])) . '</p></div></div>';
}
echo '</div>' . (ds_writable() ? ds_form('class="ds-commentform"') . '<input type="hidden" name="action" value="comment"><textarea name="body" rows="2" required maxlength="4000" placeholder="Write a comment — everyone on the document is told"></textarea>'
   . '<button class="ds-btn ds-btn-primary ds-btn-sm">' . ds_icon('send') . 'Post</button></form>' : '') . '</div>';

/* Access */
echo $pane('sharing') . '<p class="ds-muted small">Everyone on the workflow, the owner and the creator always see it' . ($doc['confidentiality'] === 'restricted' ? '; it is <strong>Restricted</strong>, so no one else does unless shared here.' : '.') . '</p><ul class="ds-list">';
foreach (fabo_all('SELECT * FROM ds_permissions WHERE doc_id = ? ORDER BY id', 'i', [$id]) as $pm) {
    echo '<li><span class="ds-li-x">' . ($pm['person_id'] ? ds_avatar((int) $pm['person_id'], 'sm') . '<span class="ds-li-b"><strong>' . e(ds_person_name((int) $pm['person_id'])) . '</strong>'
       : '<span class="ds-fileic">' . ds_icon('building') . '</span><span class="ds-li-b"><strong>' . e(ds_dept_name((int) $pm['department_id'])) . ' department</strong>')
       . '<small>' . e($pm['access'] === 'manage' ? 'Can manage' : 'Can view') . ' · shared by ' . e((string) $pm['granted_by']) . '</small></span>'
       . ($manage ? ds_button('Remove', ['action' => 'unshare', 'perm_id' => $pm['id']], 'ds-btn ds-btn-ghost ds-btn-sm') : '') . '</span></li>';
}
echo '</ul>';
if ($manage) {
    echo ds_form('class="ds-inlineform"') . '<input type="hidden" name="action" value="share">' . ds_select('person_id', ds_people(), '', 'A person…', 'aria-label="Person"')
       . (ds_departments() ? ds_select('department_id', ds_departments(), '', '…or a department', 'aria-label="Department"') : '')
       . ds_select('access', ['view' => 'Can view', 'manage' => 'Can manage'], 'view', '', 'aria-label="Access"') . '<button class="ds-btn ds-btn-sm">' . ds_icon('share') . 'Share</button></form>';
}
echo '</div>';

/* Verification */
echo $pane('verify');
$vs = fabo_all('SELECT * FROM ds_signature_verifications WHERE doc_id = ? ORDER BY id DESC LIMIT 10', 'i', [$id]);
echo '<p class="ds-muted small">Checks every digital signature in the current PDF against the trust store, revocation and later changes, and that every mandatory signatory\'s signature is present.</p>'
   . ds_button('Verify now', ['action' => 'verify'], 'ds-btn ds-btn-primary ds-btn-sm', '', 'shield-check');
if ($vs) {
    echo '<ul class="ds-list">';
    foreach ($vs as $v) {
        echo '<li><a href="' . e(ds_url('verify', ['vid' => $v['id']])) . '"><span class="ds-fileic">' . ds_icon('shield') . '</span><span class="ds-li-b"><strong>' . e($v['report_ref']) . '</strong><small>'
           . e(ds_fmt_dt($v['created_at'])) . ' · ' . e((string) $v['verified_by']) . ' · ' . (int) $v['signature_count'] . ' signature(s)' . ($v['mandatory_ok'] !== null ? ' · mandatory ' . ((int) $v['mandatory_ok'] ? 'complete' : 'incomplete') : '') . '</small></span>'
           . ds_verdict_badge($v['overall']) . '</a></li>';
    }
    echo '</ul>';
}
echo '</div></div>';
echo '</div></div>';

/* ---- dialogs */
if ($canSend) {
    echo '<dialog class="ds-dlg wide" id="dlg-send"><form method="post" data-busy-form>' . fabo_csrf_field() . '<input type="hidden" name="action" value="submit"><input type="hidden" name="version" value="' . (int) $doc['version'] . '">'
       . '<header><h3>' . ds_icon('send') . 'Send ' . e($doc['ref_no']) . ' for signature</h3><button type="button" class="ds-icobtn" data-close aria-label="Close">' . ds_icon('x') . '</button></header>'
       . '<div class="ds-dlg-b">' . ds_routing_picker($doc) . ($st !== 'draft' && ($plan['resubmission_rule'] ?? '') === 'resume' ? '<p class="ds-hint">' . ds_icon('history') . 'This workflow resumes from the stage that returned it when the version has not changed.</p>' : '')
       . '<div class="ds-legal">' . ds_icon('lock') . '<p>Sending locks version ' . (int) ($ver['version_no'] ?? 1) . ' (SHA-256 ' . e(substr((string) ($ver['sha256'] ?? ''), 0, 16)) . '…). A new version later stops the signing and needs re-sending.</p></div></div>'
       . '<footer><button type="button" class="ds-btn ds-btn-ghost" data-close>Cancel</button><button class="ds-btn ds-btn-primary">' . ds_icon('move') . 'Next: place signature fields</button></footer></form></dialog>';
}
if ($manage) {
    $cats = ds_categories();
    echo '<dialog class="ds-dlg wide" id="dlg-edit">' . ds_form() . '<input type="hidden" name="action" value="update"><input type="hidden" name="version" value="' . (int) $doc['version'] . '">'
       . '<header><h3>' . ds_icon('edit') . 'Edit details</h3><button type="button" class="ds-icobtn" data-close aria-label="Close">' . ds_icon('x') . '</button></header><div class="ds-dlg-b ds-formgrid">'
       . ds_field('Title', '<input name="title" required maxlength="255" value="' . e($doc['title']) . '">', '', true, 'span-2')
       . ds_field('Category', ds_select('category_id', $cats, $doc['category_id'], 'Choose…')) . ds_field('Department', ds_select('department_id', ds_departments(), $doc['department_id'], 'No department'))
       . ds_field('Unit / spoke', ds_units() ? ds_select('unit', array_combine(ds_units(), ds_units()), $doc['unit'], 'None') : '<input name="unit" value="' . e((string) $doc['unit']) . '">')
       . ds_field('Owner', ds_select('owner_id', ds_people() + [0 => 'Administrator'], $doc['owner_id']))
       . ds_field('Priority', ds_select('priority', ds_priorities(), $doc['priority'])) . ds_field('Confidentiality', ds_select('confidentiality', ds_confidentiality(), $doc['confidentiality']))
       . ds_field('Value (₹)', '<input name="value_amount" value="' . e((string) $doc['value_amount']) . '">') . ds_field('Document date', '<input type="date" name="doc_date" value="' . e((string) $doc['doc_date']) . '">')
       . ds_field('Valid until', '<input type="date" name="valid_until" value="' . e((string) $doc['valid_until']) . '">') . ds_field('Signing deadline', '<input type="date" name="deadline" value="' . e((string) $doc['deadline']) . '">')
       . ds_field('Description', '<textarea name="description" rows="3">' . e((string) $doc['description']) . '</textarea>', '', false, 'span-2')
       . ds_field('Remarks', '<textarea name="remarks" rows="2">' . e((string) $doc['remarks']) . '</textarea>', '', false, 'span-2')
       . '</div><footer><button type="button" class="ds-btn ds-btn-ghost" data-close>Cancel</button><button class="ds-btn ds-btn-primary">Save</button></footer></form></dialog>';
    echo '<dialog class="ds-dlg" id="dlg-version">' . ds_form('', true) . '<input type="hidden" name="action" value="new_version"><input type="hidden" name="version" value="' . (int) $doc['version'] . '">'
       . '<header><h3>' . ds_icon('upload') . 'Upload a new version</h3><button type="button" class="ds-icobtn" data-close aria-label="Close">' . ds_icon('x') . '</button></header><div class="ds-dlg-b">'
       . ($open ? '<div class="ds-note warn">' . ds_icon('alert') . '<span>Signing is in progress. A new version <strong>stops it</strong>: pending requests are cancelled and the document must be sent again. Signatures already given stay in its history.</span></div>' : '')
       . '<label class="ds-drop small" data-drop><input type="file" name="pdf" accept="application/pdf,.pdf" required><span class="ds-drop-i">' . ds_icon('upload') . '</span><strong>Choose the PDF</strong><span class="ds-drop-f" data-drop-name hidden></span></label>'
       . ds_field('What changed', '<input name="note" maxlength="400" placeholder="e.g. Clause 4 revised as requested">') . '</div>'
       . '<footer><button type="button" class="ds-btn ds-btn-ghost" data-close>Cancel</button><button class="ds-btn ds-btn-primary">Upload version</button></footer></form></dialog>';
    echo '<dialog class="ds-dlg" id="dlg-cancel">' . ds_form() . '<input type="hidden" name="action" value="cancel"><input type="hidden" name="version" value="' . (int) $doc['version'] . '">'
       . '<header><h3>' . ds_icon('x-circle') . 'Cancel ' . e($doc['ref_no']) . '</h3><button type="button" class="ds-icobtn" data-close aria-label="Close">' . ds_icon('x') . '</button></header><div class="ds-dlg-b">'
       . '<p>Pending requests are withdrawn and everyone on it is told. It can be reinitiated later.</p>' . ds_field('Reason', '<textarea name="reason" rows="2" required maxlength="500"></textarea>', '', true)
       . '</div><footer><button type="button" class="ds-btn ds-btn-ghost" data-close>Keep it</button><button class="ds-btn ds-btn-danger">Cancel document</button></footer></form></dialog>';
}
ds_layout_end();

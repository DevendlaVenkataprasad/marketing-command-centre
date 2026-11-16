<?php
/**
 * The field studio: before a document goes out, its sender places each
 * signatory's fields on the pages — signature, initials, name, date signed,
 * designation — by dragging them from the palette. Each signatory has a
 * colour. Fields save as they are moved; "Send for signature" sends it.
 * Signatories then sign exactly there and cannot move them.
 */

$id = (int) ($_GET['id'] ?? 0);
$doc = ds_doc_open($id);
if (!ds_doc_can_manage($doc)) {
    fabo_deny('You cannot prepare this document.', 403);
}
if (!in_array($doc['status'], ['draft', 'reinitiated', 'rejected', 'changes_requested'], true)) {
    inc_redirect(ds_url('doc', ['id' => $id]), ['err', 'This document is ' . strtolower(ds_doc_statuses()[$doc['status']][0]) . '; its fields can no longer be changed.']);
}
$plan = json_decode((string) $doc['draft_plan_json'], true);
if (!$plan) {
    inc_redirect(ds_url('doc', ['id' => $id]), ['err', 'Choose who signs first (Send for signature), then place the fields.']);
}
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fields = json_decode((string) ($_POST['fields'] ?? '{}'), true);
    $fields = is_array($fields) ? $fields : [];
    if (($_POST['action'] ?? '') === 'save_fields') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $p = ds_studio_save($id, $fields);
            echo json_encode(['ok' => true, 'count' => array_sum(array_map('count', $p['fields']))]);
        } catch (InvalidArgumentException $e) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
    if (($_POST['action'] ?? '') === 'send') {
        $flash = ds_action(static function () use ($id, $fields) {
            $p = ds_studio_save($id, $fields);
            $n = array_sum(array_map('count', $p['fields']));
            $msg = ds_submit($id, $p, (array) ($p['chosen'] ?? []), (int) ($_POST['version'] ?? 0));
            ds_audit($id, 'document', $id, 'fields', $n . ' field' . ($n === 1 ? '' : 's') . ' placed for the signatories');
            return $msg . ' Signatories sign in the ' . $n . ' place' . ($n === 1 ? '' : 's') . ' you marked.';
        }, ds_url('doc', ['id' => $id]));
        $doc = ds_doc($id);
        $plan = json_decode((string) $doc['draft_plan_json'], true) ?: $plan;
    }
}

$recips = ds_studio_recipients($doc, $plan);
$list = [];
$i = 0;
foreach ($recips as $slot => $r) {
    $list[] = ['slot' => $slot, 'name' => $r['name'], 'stage' => $r['stage'], 'stage_name' => $r['stage_name'], 'action' => $r['action'],
               'action_label' => ds_actions()[$r['action']] ?? $r['action'], 'method' => ds_method_label($r['method']), 'mandatory' => $r['mandatory'],
               'skip' => $r['skip'], 'error' => $r['error'], 'color' => ($i++ % 8) + 1, 'initials' => ds_initials($r['name'])];
}
$errors = array_filter($recips, static fn($r) => $r['error'] !== '');
$state = ['recipients' => $list, 'fields' => (object) ($plan['fields'] ?? []), 'kinds' => ds_field_kinds(),
          'preview' => ($pi = ds_preview_info((int) ($doc['signed_file_id'] ?: $doc['working_file_id']))) ? ['img' => ds_url('file', ['doc' => $id, 'kind' => 'page', 'f' => (int) ($doc['signed_file_id'] ?: $doc['working_file_id'])]) . '&n=', 'sizes' => $pi['sizes']] : null,
          'src' => ds_url('file', ['doc' => $id, 'kind' => 'current', 'inline' => 1, 'f' => (int) ($doc['signed_file_id'] ?: $doc['working_file_id'])]), 'csrf' => fabo_csrf_token()];
$fileTitle = $plan['workflow'] ? 'Workflow "' . $plan['workflow'] . '"' : 'Signatories chosen for this document';

ds_layout_start('Place signature fields', '<span class="mono">' . e($doc['ref_no']) . '</span> · ' . e($doc['title']) . ' · ' . e($fileTitle),
    '<a class="ds-btn ds-btn-ghost" href="' . e(ds_url('doc', ['id' => $id, 'send' => 1])) . '">' . ds_icon('chev-l') . 'Change signatories</a>'
    . '<button type="submit" form="ds-studio-send" class="ds-btn ds-btn-primary" data-studio-send>' . ds_icon('send') . 'Send for signature</button>',
    ['Documents' => ds_url('documents'), $doc['ref_no'] => ds_url('doc', ['id' => $id]), 'Fields' => null], $flash, 'Signature studio');
foreach ($errors as $r) {
    echo '<div class="ds-note warn">' . ds_icon('alert') . '<span><strong>' . e($r['stage_name']) . ':</strong> ' . e($r['error']) . ' It must be fixed before sending.</span></div>';
}
echo '<form method="post" id="ds-studio-send">' . fabo_csrf_field() . '<input type="hidden" name="action" value="send"><input type="hidden" name="version" value="' . (int) $doc['version'] . '">'
   . '<input type="hidden" name="fields" data-studio-out></form>';
echo '<div class="ds-studio" data-studio><script type="application/json" data-studio-state>' . json_encode($state, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . '</script>'
   . '<aside class="ds-studio-side ds-rise"><div class="ds-card"><div class="ds-card-h"><h2>' . ds_icon('users') . 'Signatories</h2><span class="ds-studio-save" data-studio-status></span></div>'
   . '<div class="ds-card-b"><p class="ds-hint">Pick a signatory, then drag a field onto the page — or click a field and then click the page.</p><ul class="ds-recips" data-recips></ul></div></div>'
   . '<div class="ds-card"><div class="ds-card-h"><h2>' . ds_icon('pen') . 'Fields <small data-active-name></small></h2></div><div class="ds-card-b"><div class="ds-palette" data-palette>';
foreach (ds_field_kinds() as $k => [$label]) {
    $ic = ['signature' => 'sign', 'initials' => 'pen', 'name' => 'user', 'date' => 'calendar', 'designation' => 'briefcase'][$k] ?? 'pen';
    echo '<button type="button" class="ds-chipfield" draggable="true" data-kind="' . $k . '">' . ds_icon($ic === 'briefcase' ? 'building' : $ic) . e($label) . '</button>';
}
echo '</div><div class="ds-studio-tools"><button type="button" class="ds-btn ds-btn-ghost ds-btn-sm" data-autoplace>' . ds_icon('flow') . 'Signature for everyone on the last page</button>'
   . '<button type="button" class="ds-btn ds-btn-ghost ds-btn-sm danger" data-clearall>' . ds_icon('trash') . 'Clear all fields</button></div>'
   . '<p class="ds-hint small">Drag a field to move it, its corner to resize it; select it and press Delete to remove it. Names, dates and designations fill themselves in when each person signs.</p></div></div></aside>'
   . '<section class="ds-studio-main"><div class="ds-viewer ds-studio-viewer ds-rise"><div class="ds-viewer-bar"><span class="ds-viewer-t">' . ds_icon('file') . e($doc['ref_no']) . ' <small data-pageinfo></small></span>'
   . '<span class="ds-viewer-c"><span class="ds-studio-count" data-fieldcount></span><button type="button" class="ds-icobtn" data-szoom="-1" aria-label="Zoom out">' . ds_icon('zoom-out') . '</button>'
   . '<button type="button" class="ds-icobtn" data-szoom="1" aria-label="Zoom in">' . ds_icon('zoom-in') . '</button></span></div>'
   . '<div class="ds-viewer-pages ds-studio-pages" data-studio-pages><div class="ds-skel"></div><div class="ds-skel"></div></div></div></section></div>';
ds_layout_end();

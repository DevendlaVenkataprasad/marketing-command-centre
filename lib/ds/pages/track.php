<?php
/** Track a document by its reference or tracking code. */

$q = trim((string) ($_GET['q'] ?? ''));
if ($q !== '') {
    $d = fabo_one('SELECT * FROM ds_documents WHERE org_id = ? AND deleted_at IS NULL AND (ref_no = ? OR tracking_code = ?)', 'iss', [ds_org(), $q, strtoupper($q)]);
    if ($d && ds_doc_can_view($d)) {
        inc_redirect(ds_url('doc', ['id' => $d['id'], 'tab' => 'activity']));
    }
}
ds_layout_start('Track a document', 'Enter a reference number or tracking code.');
echo ($q !== '' ? ds_flash_html(['err', 'No document you can see has that reference or code.']) : '')
   . '<div class="ds-card ds-rise"><div class="ds-card-b"><form method="get" class="ds-inlineform"><input type="hidden" name="p" value="track"><input name="q" value="' . e($q) . '" placeholder="Reference or tracking code" required autofocus>'
   . '<button class="ds-btn ds-btn-primary">' . ds_icon('search') . 'Track</button></form></div></div>';
ds_layout_end();

<?php
/**
 * New document: upload the PDF, describe it, route it — then keep it as a
 * draft or send it for signature at once.
 */

require_once __DIR__ . '/_routing.php';

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $flash = ds_action(static function () {
        ds_nonce_check();
        $up = ds_upload_bytes('pdf');
        if (!$up) {
            throw new InvalidArgumentException('Choose the PDF to upload.');
        }
        $send = ($_POST['go'] ?? '') === 'prepare';
        $plan = null;
        $chosen = [];
        if ($send) {
            // Check the routing before anything is stored, so a mistake keeps the form.
            [$plan, $chosen] = ds_plan_from_post($_POST);
        }
        $id = ds_doc_create($_POST, $up[0], $up[1]);
        foreach ((array) ($_FILES['attachments']['name'] ?? []) as $i => $n) {
            if ((int) ($_FILES['attachments']['error'][$i] ?? 4) === UPLOAD_ERR_OK && ($a = ds_upload_bytes('attachments', (int) $i))) {
                ds_doc_attach($id, $a[0], $a[1], '');
            }
        }
        $GLOBALS['ds_new_id'] = $id;
        if ($send) {
            // On to the studio: the sender places each signatory's fields, then sends it from there.
            ds_plan_prepare($id, $plan, $chosen);
            inc_redirect(ds_url('studio', ['id' => $id]), ['ok', ds_doc($id)['ref_no'] . ' was created. Now place each signatory\'s fields on the pages, then send it.']);
        }
        inc_redirect(ds_url('doc', ['id' => $id]), ['ok', ds_doc($id)['ref_no'] . ' was saved as a draft.']);
    }, ds_url('documents'));
}

$v = static fn(string $k, string $d = '') => e((string) ($_POST[$k] ?? $d));
$maxMb = (int) ds_setting('max_upload_mb');
ds_layout_start('New document', 'Upload a PDF, describe it and choose who signs. Its reference number is given when it is saved.', '', ['Documents' => ds_url('documents'), 'New document' => null], $flash);

echo ds_form('class="ds-wizard" data-wizard novalidate', true) . ds_nonce_field();
echo '<ol class="ds-wsteps" aria-label="Steps"><li class="on" data-goto="1"><span>1</span>Upload</li><li data-goto="2"><span>2</span>Details</li><li data-goto="3"><span>3</span>Signatories</li><li data-goto="4"><span>4</span>Review</li><li class="ds-wstep-next" aria-disabled="true"><span>5</span>Place fields</li></ol>';

/* 1. upload */
echo '<section class="ds-wpane" data-step="1"><div class="ds-grid g-1-1">'
   . '<div class="ds-card"><div class="ds-card-b"><label class="ds-drop" data-drop><input type="file" name="pdf" accept="application/pdf,.pdf" required data-pdf-input>'
   . '<span class="ds-drop-i">' . ds_icon('upload') . '</span><strong>Drop the PDF here</strong><span>or <u>browse</u> · PDF up to ' . $maxMb . ' MB</span>'
   . '<span class="ds-drop-f" data-drop-name hidden></span></label>'
   . '<ul class="ds-safe"><li>' . ds_icon('shield') . 'Scanned for scripts, launch actions and embedded files</li><li>' . ds_icon('lock') . 'Encrypted at rest (AES-256)</li>'
   . '<li>' . ds_icon('history') . 'Kept as version 1 — every change is a new version</li></ul>'
   . ds_field('Supporting files (optional)', '<input type="file" name="attachments[]" multiple accept=".pdf,.png,.jpg,.jpeg,.docx,.xlsx,.csv,.txt">', 'Annexures, quotations, notes — kept with the document, not signed.')
   . '</div></div><div class="ds-card ds-preview-card"><div class="ds-pdfmini" data-pdf-mini><div class="ds-pdfmini-e">' . ds_icon('file') . '<span>The first page appears here</span></div></div></div>'
   . '</div><div class="ds-wnav"><span></span><button type="button" class="ds-btn ds-btn-primary" data-next>Continue' . ds_icon('chev-r') . '</button></div></section>';

/* 2. details */
$cats = ds_categories();
$depts = ds_departments();
$units = ds_units();
echo '<section class="ds-wpane" data-step="2" hidden><div class="ds-card"><div class="ds-card-b ds-formgrid">'
   . ds_field('Document title', '<input name="title" required maxlength="255" value="' . $v('title') . '" data-title placeholder="e.g. MoU with Andhra University for incubation support">', '', true, 'span-2')
   . ds_field('Reference number', '<input value="Given automatically on saving" disabled>', 'Format ' . e(ds_setting('ref_prefix')) . '/YY-YY/000001')
   . ds_field('Category', ds_select('category_id', $cats, $_POST['category_id'] ?? '', 'Choose…', 'required data-cond="category"'), '', true)
   . ds_field('Department', $depts ? ds_select('department_id', $depts, $_POST['department_id'] ?? (string) ds_person_dept(ds_me()), 'No department', 'data-cond="department"')
        : '<input disabled value="No departments yet">', $depts ? '' : (ds_can('settings.manage') ? 'Add departments in <a href="' . e(ds_url('settings')) . '">Settings</a>.' : 'HR departments are used here.'))
   . ds_field('Organisational unit / spoke', $units ? ds_select('unit', array_combine($units, $units), $_POST['unit'] ?? '', 'None', 'data-cond="unit"') : '<input name="unit" maxlength="120" value="' . $v('unit') . '">')
   . ds_field('Document owner', ds_select('owner_id', ds_people() + (ds_me() === 0 ? [0 => 'Administrator'] : []), $_POST['owner_id'] ?? (string) ds_me()), 'Answers for it and is told of every step.', true)
   . ds_field('Priority', ds_select('priority', ds_priorities(), $_POST['priority'] ?? 'normal'))
   . ds_field('Confidentiality', ds_select('confidentiality', ds_confidentiality(), $_POST['confidentiality'] ?? 'internal', '', 'data-cond="confidentiality"'),
        'Confidential: not visible department-wide. Restricted: only the people on it.')
   . ds_field('Document value (₹)', '<input name="value_amount" inputmode="decimal" value="' . $v('value_amount') . '" data-cond="value" placeholder="Optional">', 'Used for authority limits and value-based routing.')
   . ds_field('Document date', '<input type="date" name="doc_date" value="' . $v('doc_date', date('Y-m-d')) . '">')
   . ds_field('Valid until / expiry', '<input type="date" name="valid_until" value="' . $v('valid_until') . '">', 'An unsigned document expires on this date.')
   . ds_field('Signing deadline', '<input type="date" name="deadline" min="' . date('Y-m-d') . '" value="' . $v('deadline') . '">')
   . ds_field('Description', '<textarea name="description" rows="3" maxlength="4000">' . $v('description') . '</textarea>', '', false, 'span-2')
   . ds_field('Remarks for signatories', '<textarea name="remarks" rows="2" maxlength="1000">' . $v('remarks') . '</textarea>', '', false, 'span-2')
   . '</div></div><div class="ds-wnav"><button type="button" class="ds-btn ds-btn-ghost" data-prev>' . ds_icon('chev-l') . 'Back</button><button type="button" class="ds-btn ds-btn-primary" data-next>Continue' . ds_icon('chev-r') . '</button></div></section>';

/* 3. signatories */
echo '<section class="ds-wpane" data-step="3" hidden><div class="ds-card"><div class="ds-card-b">' . ds_routing_picker() . '</div></div>'
   . '<div class="ds-wnav"><button type="button" class="ds-btn ds-btn-ghost" data-prev>' . ds_icon('chev-l') . 'Back</button><button type="button" class="ds-btn ds-btn-primary" data-next>Review' . ds_icon('chev-r') . '</button></div></section>';

/* 4. review */
echo '<section class="ds-wpane" data-step="4" hidden><div class="ds-card"><div class="ds-card-b"><div class="ds-review" data-review></div>'
   . '<div class="ds-legal">' . ds_icon('shield') . '<p>Next you place each signatory&rsquo;s signature, initials, name and date fields on the pages, then send it. Once sent, this version is <strong>locked</strong>. Signatories are asked in order; nobody is marked as signed until their signature (or approval) is confirmed. '
   . 'Electronic approvals and drawn signatures are recorded as such — only Aadhaar eSign and DSC create digital signatures recognised under the IT Act, 2000.</p></div></div></div>'
   . '<div class="ds-wnav"><button type="button" class="ds-btn ds-btn-ghost" data-prev>' . ds_icon('chev-l') . 'Back</button><span class="ds-wnav-r">'
   . '<button class="ds-btn ds-btn-ghost" name="go" value="draft">Save as draft</button><button class="ds-btn ds-btn-primary" name="go" value="prepare" data-busy="Opening the studio…">' . ds_icon('move') . 'Next: place signature fields</button></span></div></section>';
echo '</form>';
ds_layout_end();

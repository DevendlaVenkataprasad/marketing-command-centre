<?php
/**
 * Where the signatory lands after the eSign provider: the result of the
 * transaction, as the callback recorded it.
 */

$t = fabo_one('SELECT * FROM ds_signature_transactions WHERE txn_ref = ? AND org_id = ?', 'si', [(string) ($_GET['t'] ?? ''), ds_org()]);
if (!$t || ((int) $t['initiated_by_id'] !== ds_me() && !ds_is_super())) {
    fabo_deny('That signing session is not available.', 404);
}
$doc = ds_doc((int) $t['doc_id']);
$ok = $t['status'] === 'completed';
ds_layout_start($ok ? 'Signed' : 'Signing did not complete', $doc ? '<span class="mono">' . e($doc['ref_no']) . '</span> · ' . e($doc['title']) : '', '', ['My signatures' => ds_url('inbox'), 'Result' => null]);
echo '<div class="ds-verdict t-' . ($ok ? 'green' : 'red') . ' ds-rise"><div class="ds-verdict-i"><svg viewBox="0 0 64 64" aria-hidden="true"><circle cx="32" cy="32" r="28"/></svg>' . ds_icon($ok ? 'shield-check' : 'x-circle') . '</div><div>'
   . '<div class="ds-eyebrow">Transaction ' . e($t['txn_ref']) . '</div><h2>' . ($ok ? 'Your digital signature was verified and added' : 'Nothing was signed') . '</h2>'
   . '<p>' . e($ok ? 'The signature verified over the exact bytes prepared for you and is now part of the document.' : ((string) $t['error_message'] ?: 'The provider did not return a signature.')) . '</p>'
   . '<p><a class="ds-btn ds-btn-primary" href="' . e(ds_url('doc', ['id' => $t['doc_id']])) . '">' . ds_icon('file') . 'Open the document</a> '
   . (!$ok ? '<a class="ds-btn ds-btn-ghost" href="' . e(ds_url('sign', ['id' => $t['request_id']])) . '">' . ds_icon('refresh') . 'Try again</a>' : '') . '</p></div></div>';
ds_layout_end();

<?php
/**
 * The trust store: the root and intermediate certificates a signature must
 * chain to before it is called valid. For India, the CCA's root
 * certificates (and the licensed CAs' intermediates). Portal-wide anchors
 * are kept by the administrator; an organisation may add its own.
 */

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $flash = ds_action(static function () {
        $a = (string) ($_POST['action'] ?? '');
        if ($a === 'add') {
            $up = ds_upload_bytes('cert');
            $data = $up ? $up[0] : trim((string) ($_POST['pem'] ?? ''));
            if ($data === '') {
                throw new InvalidArgumentException('Upload a certificate file or paste its PEM.');
            }
            $global = !empty($_POST['global']) && !empty(ds_viewer()['is_admin']);
            $n = ds_anchor_add($data, ds_str($_POST['label'] ?? '', 160), ($_POST['kind'] ?? 'root') === 'tsa' ? 'tsa' : 'root', $global);
            return $n ? $n . ' certificate(s) added to the trust store.' : 'Already in the trust store.';
        }
        $id = (int) ($_POST['id'] ?? 0);
        $r = fabo_one('SELECT * FROM ds_trust_anchors WHERE id = ?', 'i', [$id]);
        if (!$r || ((int) $r['org_id'] === 0 && empty(ds_viewer()['is_admin'])) || ((int) $r['org_id'] !== 0 && (int) $r['org_id'] !== ds_org())) {
            throw new InvalidArgumentException('You cannot change that certificate.');
        }
        if ($a === 'toggle') {
            fabo_exec('UPDATE ds_trust_anchors SET active = 1 - active WHERE id = ?', 'i', [$id]);
            ds_audit(null, 'trust', $id, 'trust_toggle', ((int) $r['active'] ? 'Disabled' : 'Enabled') . ' trust in "' . $r['label'] . '"');
            return 'Updated.';
        }
        if ($a === 'delete') {
            fabo_exec('DELETE FROM ds_trust_anchors WHERE id = ?', 'i', [$id]);
            ds_audit(null, 'trust', $id, 'trust_remove', 'Removed "' . $r['label'] . '" from the trust store', ['fingerprint' => $r['fingerprint']]);
            return 'Removed.';
        }
        throw new InvalidArgumentException('Unknown action.');
    }, ds_url('trust'));
}
$rows = fabo_all('SELECT * FROM ds_trust_anchors WHERE org_id IN (0, ?) ORDER BY org_id, kind, label', 'i', [ds_org()]);
ds_layout_start('Trust store', 'A digital signature is valid only if its certificate chains to one of these. Add the Controller of Certifying Authorities (CCA) India root certificates and, if needed, the CA intermediates.',
    '', ['Administration' => ds_url('admin'), 'Trust store' => null], $flash);
if (!$rows) {
    echo '<div class="ds-note warn">' . ds_icon('alert') . '<span>The trust store is empty, so every signature shows as <strong>Verification incomplete</strong>. Download the CCA root certificates (CCA India 2022 / 2014 / 2015 SPL) from <span class="mono">cca.gov.in</span> → Repository, and add them below.</span></div>';
}
echo '<div class="ds-grid g-2-1"><div class="ds-card ds-rise"><div class="ds-tablewrap"><table class="ds-table"><thead><tr><th>Certificate</th><th>Type</th><th>Scope</th><th>Valid to</th><th>Fingerprint</th><th></th></tr></thead><tbody>';
foreach ($rows as $r) {
    $own = (int) $r['org_id'] === 0 ? !empty(ds_viewer()['is_admin']) : true;
    echo '<tr class="' . ((int) $r['active'] ? '' : 'is-off') . '"><td data-l="Certificate"><strong>' . e($r['label']) . '</strong><small class="ds-muted">' . e((string) $r['subject']) . '</small></td>'
       . '<td data-l="Type">' . ds_badge(['root' => 'Root', 'intermediate' => 'Intermediate', 'tsa' => 'Timestamp authority'][$r['kind']] ?? $r['kind'], 'blue', false) . '</td>'
       . '<td data-l="Scope">' . ((int) $r['org_id'] === 0 ? 'All organisations' : 'This organisation') . '</td>'
       . '<td data-l="Valid to">' . (strtotime((string) $r['not_after']) < time() ? ds_badge('Expired ' . ds_fmt_date($r['not_after']), 'red') : e(ds_fmt_date($r['not_after']))) . '</td>'
       . '<td data-l="Fingerprint" class="mono small">' . e(substr($r['fingerprint'], 0, 24)) . '…</td><td>'
       . ($own ? ds_button((int) $r['active'] ? 'Disable' : 'Enable', ['action' => 'toggle', 'id' => $r['id']], 'ds-btn ds-btn-ghost ds-btn-sm') . ds_button('Remove', ['action' => 'delete', 'id' => $r['id']], 'ds-btn ds-btn-ghost ds-btn-sm danger', 'Remove "' . $r['label'] . '" from the trust store?') : '')
       . '</td></tr>';
}
if (!$rows) {
    echo '<tr><td colspan="6">' . ds_empty('No trusted certificates', '', 'shield') . '</td></tr>';
}
echo '</tbody></table></div></div>';
echo ds_card_start('Add certificates', '', '', 'plus') . ds_form('', true) . '<input type="hidden" name="action" value="add">'
   . ds_field('Certificate file', '<input type="file" name="cert" accept=".cer,.crt,.pem,.der,.p7b">', 'PEM may hold several certificates.')
   . ds_field('…or paste PEM', '<textarea name="pem" rows="4" class="mono" placeholder="-----BEGIN CERTIFICATE-----"></textarea>')
   . ds_field('Label', '<input name="label" maxlength="160" placeholder="e.g. CCA India 2022">')
   . ds_field('Type', ds_select('kind', ['root' => 'Root / CA', 'tsa' => 'Timestamp authority'], 'root'))
   . (!empty(ds_viewer()['is_admin']) ? '<label class="ds-check"><input type="checkbox" name="global" value="1" checked> Trust for all organisations</label>' : '')
   . '<button class="ds-btn ds-btn-primary ds-btn-block">' . ds_icon('shield') . 'Add to trust store</button></form>' . ds_card_end() . '</div>';
ds_layout_end();

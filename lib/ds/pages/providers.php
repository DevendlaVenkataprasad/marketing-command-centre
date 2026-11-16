<?php
/**
 * Signing providers. Each is an adapter configuration: switching ESP is a
 * change here, not in code. Secrets are never stored: the ASP key is named
 * by its path on the server, a passphrase by its environment variable.
 */

$kinds = ds_provider_kinds();
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $flash = ds_action(static function () use ($kinds) {
        $a = (string) ($_POST['action'] ?? '');
        $id = (int) ($_POST['id'] ?? 0);
        $row = $id ? fabo_one('SELECT * FROM ds_providers WHERE id = ? AND org_id = ?', 'ii', [$id, ds_org()]) : null;
        if ($a === 'save') {
            $kind = $row ? $row['provider'] : ds_pick($_POST['provider'] ?? '', $kinds, 'provider');
            $cfg = [];
            foreach ($kinds[$kind]['fields'] as $f => [$label, $type]) {
                $v = trim((string) ($_POST['cfg'][$f] ?? ''));
                if ($type === 'pem' && $v !== '' && !ds_cert_summary($v)) {
                    throw new InvalidArgumentException($label . ' is not a valid PEM certificate.');
                }
                if ($type === 'url' && $v !== '' && !filter_var($v, FILTER_VALIDATE_URL)) {
                    throw new InvalidArgumentException($label . ' is not a valid URL.');
                }
                if ($type === 'path' && $v !== '' && (str_contains($v, "\0") || !preg_match('~^(/|[A-Za-z]:[\\\\/])~', $v))) {
                    throw new InvalidArgumentException($label . ' must be an absolute path on the server.');
                }
                if (preg_match('/-----BEGIN [A-Z ]*PRIVATE KEY-----/', $v)) {
                    throw new InvalidArgumentException('A private key was pasted. Keys are never stored here: save it as a file on the server and give its path.');
                }
                $cfg[$f] = mb_substr($v, 0, $type === 'pem' || $type === 'textarea' ? 20000 : 600);
            }
            $label = ds_str($_POST['label'] ?? '', 120, true, 'Name');
            $env = ($_POST['environment'] ?? 'sandbox') === 'production' ? 'production' : 'sandbox';
            $active = !empty($_POST['active']) ? 1 : 0;
            if ($active) {
                [$ok, $bad] = ds_provider_check(['provider' => $kind, 'config' => $cfg]);
                if (!$ok) {
                    throw new InvalidArgumentException('It cannot be activated yet: ' . implode(' ', $bad));
                }
            }
            if ($row) {
                fabo_exec('UPDATE ds_providers SET label = ?, environment = ?, config_json = ?, active = ? WHERE id = ?', 'sssii', [$label, $env, json_encode($cfg), $active, $id]);
            } else {
                $id = fabo_exec('INSERT INTO ds_providers (org_id, provider, label, environment, config_json, active, created_by) VALUES (?,?,?,?,?,?,?)', 'issssis',
                                [ds_org(), $kind, $label, $env, json_encode($cfg), $active, ds_actor()]);
            }
            if ($active) {
                fabo_exec('UPDATE ds_providers SET active = 0 WHERE org_id = ? AND provider = ? AND id <> ?', 'isi', [ds_org(), $kind, $id]);
            }
            ds_audit(null, 'provider', $id, 'provider_save', 'Saved provider "' . $label . '" (' . $kinds[$kind]['label'] . ', ' . $env . ($active ? ', active' : ', inactive') . ')');
            return 'Provider saved.';
        }
        if ($a === 'test' && $row) {
            $cfg = json_decode((string) $row['config_json'], true) ?: [];
            [$ok, $bad] = ds_provider_check(['provider' => $row['provider'], 'config' => $cfg]);
            if (!$ok) {
                throw new InvalidArgumentException('Not ready: ' . implode(' ', $bad));
            }
            if ($row['provider'] === 'esign_cca') {
                $xml = ds_esign_request_xml($cfg, 'TEST-' . bin2hex(random_bytes(4)), hash('sha256', 'test'), 'Configuration test');
                $dom = ds_xml_load($xml);
                $key = ds_asp_key($cfg);
                $pub = openssl_pkey_get_details($key)['key'];
                $selfOk = $dom && ds_xmldsig_verify($dom, $pub);
                $esp = ds_cert_summary((string) $cfg['esp_cert_pem']);
                ds_audit(null, 'provider', (int) $row['id'], 'provider_test', 'Tested provider "' . $row['label'] . '"');
                if (!$selfOk) {
                    throw new InvalidArgumentException('A request was built but its XML signature did not verify with the ASP key. Check the key file.');
                }
                return 'Ready: a signed eSign request was built and its XML signature verifies with the ASP key. ESP certificate: ' . ($esp['cn'] ?? '?') . ', valid to ' . gmdate('d M Y', (int) ($esp['not_after'] ?? 0))
                     . '. The gateway itself is reached only when a signatory signs.';
            }
            return 'Ready.';
        }
        if ($a === 'delete' && $row) {
            fabo_exec('DELETE FROM ds_providers WHERE id = ?', 'i', [$id]);
            ds_audit(null, 'provider', $id, 'provider_delete', 'Deleted provider "' . $row['label'] . '"');
            return 'Provider removed.';
        }
        throw new InvalidArgumentException('Unknown action.');
    }, ds_url('providers'));
}
$rows = fabo_all('SELECT * FROM ds_providers WHERE org_id = ? ORDER BY provider, id', 'i', [ds_org()]);
ds_layout_start('Signing providers', 'Legally recognised digital signatures come from a licensed eSign Service Provider (Aadhaar eSign) or the signatory\'s own DSC. Configure them here.',
    '<button class="ds-btn ds-btn-primary" type="button" data-open="dlg-prov-new">' . ds_icon('plus') . 'Add provider</button>', ['Administration' => ds_url('admin'), 'Providers' => null], $flash);
echo '<div class="ds-note">' . ds_icon('shield') . '<span><strong>How Aadhaar eSign works here.</strong> Your organisation registers as an ASP with an ESP (eMudhra, C-DAC, Protean …) and receives an ASP ID, sandbox and production gateway URLs, '
   . 'and the ESP\'s certificate. Keep your ASP private key in a file outside the web folder' . (str_contains(str_replace('\\', '/', __DIR__), '/home/') ? ' (e.g. <span class="mono">' . e(dirname(ds_key_file())) . '/asp.key</span>)' : '')
   . '. Every request is XML-signed with it; every response is checked against the ESP certificate; the returned signature is verified over the exact bytes before it is accepted.</span></div>';
$dsc = array_filter($rows, static fn($r) => $r['provider'] === 'dsc_upload');
echo '<div class="ds-provs">';
$card = static function (array $r, array $kinds) {
    $cfg = json_decode((string) $r['config_json'], true) ?: [];
    [$ok, $bad] = ds_provider_check(['provider' => $r['provider'], 'config' => $cfg]);
    return '<article class="ds-card ds-prov ds-rise"><div class="ds-prov-h"><span class="ds-tile-i">' . ds_icon($r['provider'] === 'esign_cca' ? 'fingerprint' : 'usb') . '</span><div><h3>' . e($r['label']) . '</h3><small>'
         . e($kinds[$r['provider']]['label']) . '</small></div>' . ((int) $r['active'] ? ds_badge('Active', 'green') : ds_badge('Inactive', 'grey')) . ds_badge(ucfirst($r['environment']), $r['environment'] === 'production' ? 'blue' : 'amber', false) . '</div>'
         . ($ok ? '<p class="ds-ok">' . ds_icon('check-circle') . 'Configuration complete</p>' : '<ul class="ds-bad">' . implode('', array_map(static fn($b) => '<li>' . ds_icon('alert') . e($b) . '</li>', $bad)) . '</ul>')
         . '<div class="ds-prov-a">' . ds_button('Test', ['action' => 'test', 'id' => $r['id']], 'ds-btn ds-btn-ghost ds-btn-sm', '', 'refresh') . '<button type="button" class="ds-btn ds-btn-sm" data-open="dlg-prov-' . $r['id'] . '">' . ds_icon('edit') . 'Configure</button>'
         . ds_button('Remove', ['action' => 'delete', 'id' => $r['id']], 'ds-btn ds-btn-ghost ds-btn-sm danger', 'Remove this provider?') . '</div></article>';
};
foreach ($rows as $r) {
    echo $card($r, $kinds);
}
if (!$dsc) {
    echo '<article class="ds-card ds-prov ds-rise"><div class="ds-prov-h"><span class="ds-tile-i">' . ds_icon('usb') . '</span><div><h3>DSC on a USB token</h3><small>Built in — always available</small></div>' . ds_badge('Active', 'green') . '</div>'
       . '<p class="ds-muted small">Signatories download the document, sign it in Adobe Acrobat/Reader with their Class 3 token and upload it. Add a configuration only to change the holding time or the instructions.</p></article>';
}
echo '</div>';
$form = static function (?array $r, array $kinds, string $dlgId) {
    $cfg = $r ? (json_decode((string) $r['config_json'], true) ?: []) : [];
    $h = '<dialog class="ds-dlg wide" id="' . $dlgId . '">' . ds_form() . '<input type="hidden" name="action" value="save"><input type="hidden" name="id" value="' . (int) ($r['id'] ?? 0) . '">'
       . '<header><h3>' . ds_icon('plug') . ($r ? 'Configure ' . e($r['label']) : 'Add a signing provider') . '</h3><button type="button" class="ds-icobtn" data-close aria-label="Close">' . ds_icon('x') . '</button></header><div class="ds-dlg-b ds-formgrid">';
    if (!$r) {
        $h .= ds_field('Type', '<select name="provider" data-provkind>' . inc_options(array_map(static fn($k) => $k['label'], $kinds), 'esign_cca') . '</select>', '', true, 'span-2');
    }
    $h .= ds_field('Name', '<input name="label" required maxlength="120" value="' . e((string) ($r['label'] ?? 'Aadhaar eSign')) . '">', '', true)
        . ds_field('Environment', ds_select('environment', ['sandbox' => 'Sandbox / UAT', 'production' => 'Production'], $r['environment'] ?? 'sandbox'));
    foreach ($kinds as $kind => $k) {
        if ($r && $r['provider'] !== $kind) {
            continue;
        }
        $h .= '<div class="ds-provfields span-2 ds-formgrid" data-kind="' . $kind . '"' . (!$r && $kind !== 'esign_cca' ? ' hidden' : '') . '>';
        foreach ($k['fields'] as $f => [$label, $type, $hint, $req]) {
            $val = (string) ($cfg[$f] ?? ($f === 'request_field' ? 'msg' : ($f === 'api_version' ? '2.1' : ($f === 'auth_mode' ? '1' : ($f === 'reserve_kb' ? '16' : ($f === 'window_minutes' ? '60' : ''))))));
            $name = 'cfg[' . $f . ']';
            $in = match (true) {
                $type === 'pem', $type === 'textarea' => '<textarea name="' . $name . '" rows="' . ($type === 'pem' ? 6 : 3) . '" class="' . ($type === 'pem' ? 'mono' : '') . '"' . ($type === 'pem' ? ' placeholder="-----BEGIN CERTIFICATE-----"' : '') . '>' . e($val) . '</textarea>',
                str_starts_with($type, 'select:') => ds_select($name, array_combine(explode(',', substr($type, 7)), explode(',', substr($type, 7))), $val),
                $f === 'esp_name' => '<input name="' . $name . '" value="' . e($val) . '" list="ds-esps">',
                default => '<input name="' . $name . '" value="' . e($val) . '"' . ($type === 'number' ? ' type="number"' : '') . ($type === 'path' ? ' class="mono" placeholder="/home/…/asp.key"' : '') . '>',
            };
            $h .= ds_field($label, $in, e($hint), $req, in_array($type, ['pem', 'textarea'], true) ? 'span-2' : '');
        }
        $h .= '</div>';
    }
    return $h . '<label class="ds-check span-2"><input type="checkbox" name="active" value="1"' . (!empty($r['active']) ? ' checked' : '') . '> Active (replaces any other active provider of this type)</label></div>'
         . '<footer><button type="button" class="ds-btn ds-btn-ghost" data-close>Cancel</button><button class="ds-btn ds-btn-primary">Save</button></footer></form></dialog>';
};
echo $form(null, $kinds, 'dlg-prov-new');
foreach ($rows as $r) {
    echo $form($r, $kinds, 'dlg-prov-' . $r['id']);
}
echo '<datalist id="ds-esps"><option value="eMudhra"><option value="C-DAC"><option value="Protean (NSDL)"><option value="Capricorn"><option value="IDSign"></datalist>';
ds_layout_end();

<?php
/** Event Organizers — API keys and webhooks. A key and a secret are shown once, then only their hash or a mask is kept in view. */

$self = inc_url('developer');
$flash = null;
$shown = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    try {
        switch ($a) {
            case 'key':
                $shown = 'Your new key: ' . eo_api_key_create((string) ($_POST['label'] ?? '')) . ' — copy it now, it is not shown again.';
                break;
            case 'key_del':
                eo_api_key_revoke((int) ($_POST['row'] ?? 0));
                break;
            case 'hook':
                $shown = 'Signing secret: ' . eo_webhook_save(trim((string) ($_POST['url'] ?? '')), (array) ($_POST['events'] ?? [])) . ' — copy it now, it is not shown again.';
                break;
            case 'hook_del':
                eo_webhook_delete((int) ($_POST['row'] ?? 0));
                break;
        }
    } catch (InvalidArgumentException $e) {
        $flash = ['err', $e->getMessage()];
    }
}
inc_layout_start('Developer', 'Read your events from other systems, and hear about changes as they happen', [], '', $flash);
if ($shown !== '') {
    echo '<div class="inc-note" role="status">' . e($shown) . '</div>';
}
$base = eo_site_url('event-api.php?r=events');
echo '<div class="inc-grid2"><section class="card"><h2>API keys</h2><p class="muted small">Send as <code>Authorization: Bearer &lt;key&gt;</code>. <code>' . e($base) . '</code>, and <code>r=registrations&amp;event_id=1</code>.</p>';
foreach (eo_api_keys() as $k) {
    echo '<p>' . e($k['label']) . ' <span class="muted small">' . e($k['prefix']) . '… ' . ($k['revoked_at'] ? 'revoked' : ($k['last_used_at'] ? 'used ' . e(inc_fmt_datetime($k['last_used_at'])) : 'not used yet')) . '</span> '
       . ($k['revoked_at'] ? '' : inc_form_open('class="inc-inline"') . '<input type="hidden" name="action" value="key_del"><input type="hidden" name="row" value="' . (int) $k['id'] . '"><button class="btn btn-sm">Revoke</button></form>') . '</p>';
}
echo inc_form_open() . '<input type="hidden" name="action" value="key"><div class="inc-row"><input type="text" name="label" maxlength="120" class="grow" required aria-label="Key label" placeholder="What is it for?"><button class="btn">Create key</button></div></form></section>';
echo '<section class="card"><h2>Webhooks</h2><p class="muted small">Each call is signed: <code>X-Portal-Signature</code> is an HMAC-SHA256 of the body.</p>';
foreach (eo_webhooks() as $w) {
    echo '<p>' . e($w['url']) . ' <span class="muted small">' . e($w['events']) . '</span> ' . inc_form_open('class="inc-inline"') . '<input type="hidden" name="action" value="hook_del"><input type="hidden" name="row" value="' . (int) $w['id'] . '"><button class="btn btn-sm">Remove</button></form></p>';
}
echo inc_form_open() . '<input type="hidden" name="action" value="hook">' . inc_field('Address', '<input type="url" name="url" maxlength="500" required placeholder="https://">');
foreach (eo_webhook_events() as $k) {
    echo '<label class="check"><input type="checkbox" name="events[]" value="' . e($k) . '"> ' . e($k) . '</label> ';
}
echo '<p><button class="btn">Add webhook</button></p></form></section></div>';
inc_layout_end();

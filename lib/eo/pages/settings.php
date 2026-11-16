<?php
/**
 * Event Organizers — settings: categories, the public pages' wording, payment
 * details, and which outside services have their keys. Stages, forms and teams
 * stay with the main pipeline. Logo, name and mail sender are the organisation's
 * own (Clients, lib/brand.php), so they are not asked for again here.
 */

$self = inc_url('settings');
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $flash = inc_action(static function () {
        $a = (string) ($_POST['action'] ?? '');
        if ($a === 'category') {
            inc_exec('INSERT IGNORE INTO inc_ev_categories (name) VALUES (?)', 's', [inc_str($_POST['name'] ?? '', 120, true, 'Category')]);
            return 'Category added.';
        }
        if ($a === 'category_del') {
            inc_exec('DELETE FROM inc_ev_categories WHERE id = ?', 'i', [(int) ($_POST['row'] ?? 0)]);
            inc_exec('UPDATE inc_ev_events SET category_id = NULL WHERE category_id = ?', 'i', [(int) ($_POST['row'] ?? 0)]);
            return 'Category removed.';
        }
        if ($a === 'pages') {
            foreach (['about', 'terms', 'privacy'] as $k) {
                inc_setting_set('eo_page_' . $k, inc_str($_POST[$k] ?? '', 20000));
            }
            inc_setting_set('eo_bank_details', inc_str($_POST['bank'] ?? '', 2000));
            return 'Saved.';
        }
        throw new InvalidArgumentException('Unknown action.');
    }, $self);
}
inc_layout_start('Organizer settings', '', [], '', $flash);
$cats = inc_all('SELECT * FROM inc_ev_categories ORDER BY name');
echo '<div class="inc-grid2"><section class="card"><h2>Categories</h2>';
foreach ($cats as $c) {
    echo '<p>' . e($c['name']) . ' ' . inc_form_open('class="inc-inline"') . '<input type="hidden" name="action" value="category_del"><input type="hidden" name="row" value="' . (int) $c['id'] . '"><button class="btn btn-sm">Remove</button></form></p>';
}
echo inc_form_open() . '<input type="hidden" name="action" value="category"><div class="inc-row"><input type="text" name="name" maxlength="120" class="grow" aria-label="New category" placeholder="New category"><button class="btn">Add</button></div></form></section>';
$svc = ['Card payment (Stripe)' => [eo_stripe_ready() && eo_env('STRIPE_WEBHOOK_SECRET') !== '', 'STRIPE_SECRET_KEY, STRIPE_WEBHOOK_SECRET'], 'WhatsApp' => [eo_channels()['whatsapp'], 'WHATSAPP_TOKEN, WHATSAPP_PHONE_NUMBER_ID'],
        'SMS' => [eo_channels()['sms'], 'MSG91_AUTH_KEY, MSG91_TEMPLATE_ID, or TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN, TWILIO_FROM_NUMBER'], 'Zoom' => [eo_zoom_ready(), 'ZOOM_ACCOUNT_ID, ZOOM_CLIENT_ID, ZOOM_CLIENT_SECRET, ZOOM_WEBHOOK_SECRET_TOKEN'],
        'AI writer and assistant' => [eo_env('GEMINI_API_KEY') !== '', 'GEMINI_API_KEY']];
echo '<section class="card"><h2>Connected services</h2><p class="muted small">Keys live in config.local.php on the server.</p><table class="data"><tbody>';
foreach ($svc as $name => [$on, $keys]) {
    echo '<tr><td>' . e($name) . '<div class="muted small">' . e($keys) . '</div></td><td>' . ($on ? '<span class="pill green">Connected</span>' : '<span class="pill grey">Not set up</span>') . '</td></tr>';
}
echo '</tbody></table><p class="muted small">Stripe webhook address: ' . e(rtrim(fabo_portal_url_safe(), '/') . '/event-hook.php?s=stripe') . '</p></section></div>';
echo '<section class="card"><h2>Public pages and payment details</h2>' . inc_form_open() . '<input type="hidden" name="action" value="pages">'
   . inc_field('Bank details for transfers', '<textarea name="bank" rows="3">' . e(inc_setting('eo_bank_details')) . '</textarea>', 'Shown to someone who chooses bank transfer.');
foreach (['about' => 'About page', 'terms' => 'Terms page', 'privacy' => 'Privacy page'] as $k => $label) {
    echo inc_field($label, '<textarea name="' . $k . '" rows="4">' . e(inc_setting('eo_page_' . $k)) . '</textarea>', 'Empty keeps the standard wording.');
}
echo '<button class="btn btn-primary">Save</button></form></section>';
inc_layout_end();

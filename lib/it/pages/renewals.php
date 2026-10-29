<?php
/** IT Management — renewals: licences, subscriptions, domains, SSL, hosting, AMC and lines, soonest first. */

$editId = (int) ($_GET['edit'] ?? 0);
$self = it_url('renewals', array_filter(['due' => $_GET['due'] ?? '', 'kind' => $_GET['kind'] ?? '', 'show' => $_GET['show'] ?? '']));
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    $flash = inc_action(static function () use ($a) {
        switch ($a) {
            case 'save':
                $id = it_renewal_save(inc_int_or_null($_POST['id'] ?? null), $_POST);
                return 'Saved "' . it_renewal($id)['name'] . '".';
            case 'renewed':
                return it_renewal_renewed((int) ($_POST['id'] ?? 0), ($_POST['paid'] ?? '') !== '' ? (float) $_POST['paid'] : null);
        }
        throw new InvalidArgumentException('Unknown action.');
    }, $self);
}

$days = (int) it_setting('remind_days');
$w = [($_GET['show'] ?? '') === 'cancelled' ? "r.status = 'cancelled'" : "r.status = 'active'"];
$types = '';
$p = [];
if (!empty($_GET['due'])) {
    $w[] = 'r.renews_on IS NOT NULL AND r.renews_on <= CURDATE() + INTERVAL ? DAY';
    $types .= 'i';
    $p[] = $days;
}
if (isset(it_renewal_kinds()[$_GET['kind'] ?? ''])) {
    $w[] = 'r.kind = ?';
    $types .= 's';
    $p[] = (string) $_GET['kind'];
}
$rows = it_all('SELECT r.*, v.name AS vendor_name FROM it_renewals r LEFT JOIN it_vendors v ON v.id = r.vendor_id WHERE ' . implode(' AND ', $w) . ' ORDER BY r.renews_on IS NULL, r.renews_on, r.name', $types, $p);
$monthly = array_sum(array_map('it_monthly_cost', $rows));

it_layout_start(it_w('Renewals'), count($rows) . ' item' . (count($rows) === 1 ? '' : 's') . ' · about <strong>' . e(it_money($monthly)) . '</strong> a month · flagged ' . $days . ' days ahead',
                '<a class="btn btn-primary" href="' . e(it_url('renewals', ['edit' => 'new'])) . it_w('">+ Add renewal</a>'), $flash);

echo '<nav class="inc-apptabs">';
foreach (['' => 'All active', 'due' => 'Due soon', 'cancelled' => 'Cancelled'] as $k => $l) {
    $on = ($k === '' && empty($_GET['due']) && ($_GET['show'] ?? '') === '') || ($k === 'due' && !empty($_GET['due'])) || ($k === 'cancelled' && ($_GET['show'] ?? '') === 'cancelled');
    echo '<a href="' . e(it_url('renewals', $k === 'due' ? ['due' => 1] : ($k === 'cancelled' ? ['show' => 'cancelled'] : []))) . '"' . ($on ? ' class="on"' : '') . '>' . e($l) . '</a>';
}
echo '</nav>';

if ($editId || ($_GET['edit'] ?? '') === 'new' || ($flash && ($_POST['action'] ?? '') === 'save')) {
    $r = $editId ? (it_renewal($editId) ?? []) : [];
    $r = ($flash && ($_POST['action'] ?? '') === 'save') ? $_POST + $r : $r;
    echo '<section class="card"><h2>' . ($editId ? 'Edit "' . e($r['name'] ?? '') . '"' : it_w('Add a renewal')) . '</h2>' . inc_form_open() . '<input type="hidden" name="action" value="save">'
       . ($editId ? '<input type="hidden" name="id" value="' . $editId . '">' : '')
       . '<div class="inc-row">' . inc_field('Name', '<input type="text" name="name" maxlength="160" required value="' . e($r['name'] ?? '') . '" placeholder="e.g. Microsoft 365 Business, mark-up.in domain">', '', true, 'grow')
       . inc_field('Kind', inc_select('kind', it_renewal_kinds(), $r['kind'] ?? 'software')) . '</div>'
       . '<div class="inc-row">' . inc_field(it_w('Next renewal'), '<input type="date" name="renews_on" value="' . e((string) ($r['renews_on'] ?? '')) . '">')
       . inc_field('Cost per period (₹)', '<input type="number" name="cost" min="0" step="0.01" value="' . e((string) ($r['cost'] ?? '')) . '">')
       . inc_field('Billed', inc_select('billing', it_billing(), $r['billing'] ?? 'yearly'))
       . inc_field('Vendor', inc_select('vendor_id', it_vendors(), $r['vendor_id'] ?? '', 'None')) . '</div>'
       . '<div class="inc-row">' . inc_field('Seats / licences', '<input type="number" name="seats" min="0" value="' . e((string) ($r['seats'] ?? '')) . '">')
       . inc_field('In use', '<input type="number" name="seats_used" min="0" value="' . e((string) ($r['seats_used'] ?? '')) . '">')
       . inc_field('Owner', '<input type="text" name="owner" maxlength="160" value="' . e((string) ($r['owner'] ?? '')) . '" placeholder="Who looks after it">')
       . inc_field('Status', inc_select('status', ['active' => 'Active', 'cancelled' => 'Cancelled'], $r['status'] ?? 'active')) . '</div>'
       . '<label class="check"><input type="checkbox" name="auto_renew" value="1"' . (!empty($r['auto_renew']) ? ' checked' : '') . '> Renews automatically (card or auto-debit)</label>'
       . inc_field('Notes', inc_textarea('notes', (string) ($r['notes'] ?? ''), 2))
       . '<button class="btn btn-primary">Save</button> <a class="btn" href="' . e(it_url('renewals')) . '">Cancel</a></form>';
    if ($editId) {
        $hist = it_all("SELECT * FROM it_activity WHERE entity = 'renewal' AND entity_id = ? ORDER BY created_at DESC LIMIT 20", 'i', [$editId]);
        echo $hist ? '<h3>History</h3><ul class="small">' . implode('', array_map(static fn($h) => '<li>' . e(inc_fmt_date($h['created_at'])) . ' — ' . e($h['summary']) . '</li>', $hist)) . '</ul>' : '';
    }
    echo '</section>';
}

if (!$rows) {
    echo inc_empty('Nothing here.', 'Add licences, subscriptions, domains, SSL certificates, hosting, AMCs and internet lines to be reminded before they lapse.');
} else {
    echo it_w('<div class="scrollx"><table class="data stack inc-table"><thead><tr><th>Item</th><th>Kind</th><th>Next renewal</th><th class="num">Cost</th><th>Seats</th><th>Vendor · owner</th><th></th></tr></thead><tbody>');
    $today = strtotime(date('Y-m-d'));
    foreach ($rows as $r) {
        $d = $r['renews_on'] ? (int) floor((strtotime($r['renews_on']) - $today) / 86400) : null;
        $when = $d === null ? '—' : e(inc_fmt_date($r['renews_on'])) . ' <span class="small ' . ($d < 0 ? 'tone-risk' : ($d <= $days ? 'tone-warn' : 'muted')) . '">'
              . ($d < 0 ? abs($d) . 'd late' : ($d === 0 ? 'today' : 'in ' . $d . 'd')) . '</span>';
        echo '<tr><td data-l="Item"><a href="' . e(it_url('renewals', ['edit' => $r['id']])) . '"><strong>' . e($r['name']) . '</strong></a>' . ($r['auto_renew'] ? ' ' . inc_badge('Auto') : '') . '</td>'
           . '<td data-l="Kind">' . e(it_renewal_kinds()[$r['kind']]) . it_w('</td><td data-l="Next renewal" class="nowrap">') . $when . '</td>'
           . '<td data-l="Cost" class="num">' . e(it_money($r['cost'])) . '<div class="muted small">' . e(it_billing()[$r['billing']]) . '</div></td>'
           . '<td data-l="Seats">' . ($r['seats'] !== null ? (int) $r['seats_used'] . ' / ' . (int) $r['seats'] : '—') . '</td>'
           . '<td data-l="Vendor · owner">' . e($r['vendor_name'] ?: '—') . '<div class="muted small">' . e((string) $r['owner']) . '</div></td><td>';
        if ($r['status'] === 'active') {
            echo inc_form_open('class="inc-inline it-renew"') . it_w('<input type="hidden" name="action" value="renewed"><input type="hidden" name="id" value="') . (int) $r['id'] . '">'
               . '<input type="number" name="paid" min="0" step="0.01" placeholder="Paid ₹" title="Amount paid this time (optional)">'
               . '<button class="btn btn-sm" data-confirm="Mark &quot;' . e($r['name']) . it_w('&quot; renewed? The next date moves on by one ') . e(strtolower(it_billing()[$r['billing']])) . it_w(' period.">Renewed</button></form>');
        }
        echo '</td></tr>';
    }
    echo '</tbody></table></div>';
}

it_layout_end();

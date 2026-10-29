<?php
/** Fixed assets: the register, bill lines to capitalise, depreciation runs, and bringing in the IT and Facilities registers. */

$tab = in_array($_GET['tab'] ?? '', ['register', 'capitalise', 'depreciation', 'add'], true) ? $_GET['tab'] : 'register';
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    if ($a === 'add') {
        $flash = fp_action(static function () {
            fp_nonce_check();
            $id = fp_asset_add($_POST);
            inc_redirect(fp_url('asset', ['id' => $id]), ['ok', 'Asset added to the register.']);
        }, fp_url('assets', ['tab' => $_POST['source'] === 'bill' ? 'capitalise' : 'add']));
    } elseif ($a === 'depreciate') {
        $flash = fp_action(static function () {
            [$n, $t] = fp_dep_run((string) ($_POST['month'] ?? ''));
            return 'Depreciation of ' . fp_money($t) . ' posted on ' . $n . ' asset(s).';
        }, fp_url('assets', ['tab' => 'depreciation']));
    } elseif ($a === 'import') {
        $flash = fp_action(static function () {
            [$n] = fp_asset_import_desks((int) ($_POST['category_id'] ?? 0), !empty($_POST['opening']));
            return $n ? $n . ' asset(s) brought in from the IT and Facilities registers.' : 'Nothing new with a cost on the IT or Facilities registers.';
        }, fp_url('assets'));
    }
}
$acts = fp_can('assets.manage') ? '<a class="btn btn-primary" href="' . e(fp_url('assets', ['tab' => 'add'])) . '">+ Add asset</a>' : '';
fp_layout_start('Fixed assets', 'Cost, depreciation and book value of everything the organisation owns.', $acts, [], $flash);
echo '<nav class="subtabs">';
$cap = fp_assets_to_capitalise();
foreach (['register' => 'Register', 'capitalise' => 'To capitalise' . ($cap ? ' <span class="c">' . count($cap) . '</span>' : ''), 'depreciation' => 'Depreciation', 'add' => 'Add'] as $k => $l) {
    echo '<a href="' . e(fp_url('assets', ['tab' => $k])) . '"' . ($tab === $k ? ' class="on"' : '') . '>' . $l . '</a>';
}
echo '</nav>';

if ($tab === 'register') {
    $rows = fp_all('SELECT a.*, c.name AS category, cc.code AS cc_code FROM fp_assets a JOIN fp_asset_categories c ON c.id = a.category_id LEFT JOIN fp_cost_centres cc ON cc.id = a.cost_centre_id ORDER BY a.status = "active" DESC, a.tag');
    if (!$rows) {
        echo inc_empty('The register is empty.', 'Capitalise bill lines charged to fixed-asset accounts, add assets by hand, or bring in the IT and Facilities registers below.');
    } else {
        $t = ['cost' => 0, 'acc' => 0];
        echo '<div class="scrollx"><table class="data inc-table stack"><thead><tr><th>Tag</th><th>Asset</th><th>Category</th><th>In service</th><th>Cost centre / place</th><th class="num">Cost</th><th class="num">Depreciated</th><th class="num">Book value</th><th>Status</th></tr></thead><tbody>';
        foreach ($rows as $a) {
            if ($a['status'] === 'active') {
                $t['cost'] += (float) $a['cost'];
                $t['acc'] += (float) $a['accumulated'];
            }
            echo '<tr' . ($a['status'] !== 'active' ? ' class="muted"' : '') . '><td data-l="Tag"><a href="' . e(fp_url('asset', ['id' => $a['id']])) . '"><strong>' . e($a['tag']) . '</strong></a></td><td data-l="Asset">' . e($a['name']) . '</td>'
               . '<td data-l="Category">' . e($a['category']) . '</td><td data-l="In service">' . e(fp_fmt_date($a['in_service_on'])) . '</td><td data-l="Where" class="small">' . e(trim(($a['cc_code'] ?? '') . ' ' . ($a['location'] ?? ''))) . '</td>'
               . fp_money_td($a['cost'], 'Cost') . fp_money_td($a['accumulated'], 'Depreciated') . fp_money_td((float) $a['cost'] - (float) $a['accumulated'], 'Book value')
               . '<td data-l="Status">' . inc_badge(ucfirst(str_replace('_', ' ', $a['status'])), $a['status'] === 'active' ? 'green' : '') . '</td></tr>';
        }
        echo '<tr class="fp-total"><td colspan="5">In use</td>' . fp_money_td($t['cost']) . fp_money_td($t['acc']) . fp_money_td($t['cost'] - $t['acc']) . '<td></td></tr></tbody></table></div>';
    }
    if (fp_can('assets.manage') && fp_writable()) {
        echo '<section class="card"><h2>Bring in the IT and Facilities registers</h2><p class="muted small">Devices and facility assets with a cost come in once each. "Register only" assumes their cost is already in the books; tick the box to post them as opening balances instead.</p>'
           . inc_form_open() . '<input type="hidden" name="action" value="import"><div class="inc-row">' . inc_field('Into category', inc_select('category_id', fp_asset_cat_options(), '', 'Choose…', 'required'), '', true)
           . '<label class="check"><input type="checkbox" name="opening" value="1"> Post as opening balances (against opening balance equity)</label></div><button class="btn">Bring in</button></form></section>';
    }
} elseif ($tab === 'capitalise') {
    if (!$cap) {
        echo '<p class="inc-allclear">Every bill line charged to a fixed-asset account is on the register.</p>';
    }
    foreach ($cap as $l) {
        $cat = fp_one('SELECT id FROM fp_asset_categories WHERE asset_account_id = ? AND active = 1 LIMIT 1', 'i', [(int) $l['account_id']]);
        echo '<section class="card"><div class="inc-cardhead"><h2>' . e($l['description']) . '</h2><span class="muted small"><a href="' . e(fp_url('bill', ['id' => $l['bill_id']])) . '">' . e($l['number']) . '</a> · ' . e($l['vendor']) . ' · ' . e(fp_fmt_date($l['bill_date'])) . '</span></div>'
           . '<p>' . e($l['account_code'] . ' ' . $l['account_name']) . ' · ' . e(fp_money((float) $l['amount'] * (float) $l['fx_rate'])) . ' · qty ' . e(rtrim(rtrim((string) $l['qty'], '0'), '.')) . '</p>';
        if (fp_can('assets.manage') && fp_writable()) {
            echo inc_form_open() . fp_nonce_field() . '<input type="hidden" name="action" value="add"><input type="hidden" name="source" value="bill"><input type="hidden" name="bill_line_id" value="' . (int) $l['id'] . '">'
               . '<div class="inc-row">' . inc_field('Name', '<input name="name" maxlength="160" required value="' . e($l['description']) . '">', '', true, 'grow')
               . inc_field('Category', inc_select('category_id', fp_asset_cat_options(), $cat['id'] ?? '', 'Choose…', 'required'), '', true)
               . inc_field('Acquired', '<input type="date" name="acquired_on" value="' . e($l['bill_date']) . '" required>') . inc_field('In service from', '<input type="date" name="in_service_on" value="' . e($l['bill_date']) . '">')
               . inc_field('Cost centre', inc_select('cost_centre_id', fp_cc_options(), $l['cost_centre_id'] ?? '', '—')) . inc_field('Location', '<input name="location" maxlength="120">') . '</div>'
               . '<button class="btn btn-primary">Capitalise</button></form>';
        }
        echo '</section>';
    }
} elseif ($tab === 'depreciation') {
    $last = fp_one('SELECT MAX(period) p FROM fp_dep_runs')['p'] ?? null;
    $next = $last ? date('Y-m', strtotime($last . '-01 +1 month')) : date('Y-m', strtotime('first day of last month'));
    if (fp_can('assets.depreciate') && fp_writable()) {
        $preview = 0.0;
        $n = 0;
        foreach (fp_all('SELECT * FROM fp_assets WHERE status = "active"') as $a) {
            $d = fp_dep_amount($a, $next);
            $preview += $d;
            $n += $d > 0 ? 1 : 0;
        }
        echo '<section class="card"><h2>Run depreciation</h2><p>' . ($next <= date('Y-m') ? 'Next month to run: <strong>' . e(date('F Y', strtotime($next . '-01'))) . '</strong> — ' . e(fp_money($preview)) . ' on ' . $n . ' asset(s).' : 'Depreciation is up to date.') . '</p>'
           . ($next <= date('Y-m') ? inc_form_open('data-confirm="Post depreciation for ' . e(date('F Y', strtotime($next . '-01'))) . '?"') . '<input type="hidden" name="action" value="depreciate"><input type="hidden" name="month" value="' . e($next) . '">'
             . '<button class="btn btn-primary">Post depreciation for ' . e(date('F Y', strtotime($next . '-01'))) . '</button></form>' : '') . '</section>';
    }
    $runs = fp_all('SELECT * FROM fp_dep_runs ORDER BY period DESC LIMIT 36');
    echo '<section class="card"><h2>Runs</h2>' . ($runs ? '<table class="data inc-table stack"><thead><tr><th>Month</th><th class="num">Assets</th><th class="num">Depreciation</th><th>Journal</th><th>Run by</th></tr></thead><tbody>'
       . implode('', array_map(static fn($r) => '<tr><td>' . e(date('F Y', strtotime($r['period'] . '-01'))) . '</td><td class="num">' . (int) $r['assets'] . '</td>' . fp_money_td($r['total'])
         . '<td>' . ($r['journal_id'] ? '<a href="' . e(fp_url('journal', ['id' => $r['journal_id']])) . '">View</a>' : '—') . '</td><td class="small">' . e($r['run_by'] . ' · ' . fp_fmt_dt($r['created_at'])) . '</td></tr>', $runs)) . '</tbody></table>'
       : '<p class="muted small">Not run yet.</p>') . '</section>';
} else {
    if (!fp_can('assets.manage')) {
        echo inc_empty('Adding assets needs the "manage assets" permission.');
    } else {
        $p = $flash ? $_POST : [];
        echo '<section class="card"><h2>Add an asset</h2>' . inc_form_open() . fp_nonce_field() . '<input type="hidden" name="action" value="add">'
           . '<div class="inc-row">' . inc_field('Name', '<input name="name" maxlength="160" required value="' . e($p['name'] ?? '') . '">', '', true, 'grow')
           . inc_field('Tag', '<input name="tag" maxlength="30" value="' . e($p['tag'] ?? '') . '" placeholder="auto">') . inc_field('Category', inc_select('category_id', fp_asset_cat_options(), $p['category_id'] ?? '', 'Choose…', 'required'), 'Sets the accounts, method and life', true) . '</div>'
           . '<div class="inc-row">' . inc_field('Acquired on', '<input type="date" name="acquired_on" required value="' . e($p['acquired_on'] ?? date('Y-m-d')) . '">', '', true)
           . inc_field('In service from', '<input type="date" name="in_service_on" value="' . e($p['in_service_on'] ?? '') . '">') . inc_field('Cost', '<input name="cost" inputmode="decimal" required value="' . e($p['cost'] ?? '') . '">', '', true)
           . inc_field('Salvage value', '<input name="salvage" inputmode="decimal" value="' . e($p['salvage'] ?? '') . '">', 'Blank: the category\'s %')
           . inc_field('Already depreciated', '<input name="accumulated" inputmode="decimal" value="' . e($p['accumulated'] ?? '') . '">', 'For an asset brought onto the books part-way through its life') . '</div>'
           . '<div class="inc-row">' . inc_field('How it is recorded', inc_select('source', ['journal' => 'Post the acquisition (choose where it was paid from)', 'register' => 'Register only — the cost is already in the books'], $p['source'] ?? 'journal'))
           . inc_field('Paid from / against', inc_select('counter_account_id', fp_account_options(['asset', 'liability', 'equity']), $p['counter_account_id'] ?? fp_acct('opening_equity'), 'Choose…'), 'Bank, a payable, or opening balance equity') . '</div>'
           . '<div class="inc-row">' . inc_field('Method', inc_select('method', ['' => 'As the category', 'slm' => 'Straight line', 'wdv' => 'Written-down value'], $p['method'] ?? ''))
           . inc_field('Useful life (months)', '<input name="life_months" type="number" min="1" value="' . e($p['life_months'] ?? '') . '" placeholder="category">')
           . inc_field('WDV rate % a year', '<input name="wdv_rate" inputmode="decimal" value="' . e($p['wdv_rate'] ?? '') . '" placeholder="category">')
           . inc_field('Cost centre', inc_select('cost_centre_id', fp_cc_options(), $p['cost_centre_id'] ?? '', '—')) . inc_field('Location', '<input name="location" maxlength="120" value="' . e($p['location'] ?? '') . '">')
           . inc_field('Custodian', '<input name="custodian" maxlength="160" value="' . e($p['custodian'] ?? '') . '">') . inc_field('Serial', '<input name="serial" maxlength="120" value="' . e($p['serial'] ?? '') . '">') . '</div>'
           . '<button class="btn btn-primary">Add to the register</button></form></section>';
    }
}
fp_layout_end();

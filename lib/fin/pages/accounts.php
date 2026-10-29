<?php
/** Chart of accounts: the tree of groups and postable accounts, with balances; kept by whoever holds coa.manage. */

$flash = null;
$edit = $_GET['edit'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $flash = fp_action(static function () {
        $id = fp_account_save(inc_int_or_null($_POST['id'] ?? null), $_POST);
        inc_redirect(fp_url('accounts'), ['ok', 'Account saved.']);
    }, fp_url('accounts'));
}
$accts = fp_accounts_all();
$bal = fp_balances(['to' => date('Y-m-d')], false);
$children = [];
foreach ($accts as $id => $a) {
    $children[(int) $a['parent_id']][] = $id;
}
$roll = static function (int $id) use (&$roll, $accts, $children, $bal): float {
    $a = $accts[$id];
    [$dr, $cr] = $bal[$id] ?? [0, 0];
    $v = fp_natural($a['type'], $dr, $cr);
    foreach ($children[$id] ?? [] as $c) {
        $v += $roll($c);
    }
    return $v;
};

fp_layout_start('Chart of accounts', count($accts) . ' accounts · balances to today',
                fp_can('coa.manage') ? '<a class="btn btn-primary" href="' . e(fp_url('accounts', ['edit' => 'new'])) . '">+ Add account</a>' : '', [], $flash);

if (fp_can('coa.manage') && ($edit !== '' || $flash)) {
    $a = ctype_digit((string) $edit) ? ($accts[(int) $edit] ?? []) : [];
    $a = $flash ? $_POST + $a : $a;
    $groups = [];
    foreach ($accts as $id => $x) {
        if ((int) $x['is_group'] === 1) {
            $groups[$id] = $x['code'] . ' · ' . $x['name'] . ' (' . $x['type'] . ')';
        }
    }
    $subs = [];
    foreach (fp_account_subtypes() as $t => $list) {
        foreach ($list as $k => $l) {
            $subs[$k] = $l;
        }
    }
    echo '<section class="card"><h2>' . (!empty($a['id']) ? 'Edit ' . e($a['code']) : 'Add an account') . '</h2>' . inc_form_open() . '<input type="hidden" name="action" value="save">'
       . (!empty($a['id']) ? '<input type="hidden" name="id" value="' . (int) $a['id'] . '">' : '')
       . '<div class="inc-row">' . inc_field('Code', '<input name="code" maxlength="20" required value="' . e($a['code'] ?? '') . '">', '', true)
       . inc_field('Name', '<input name="name" maxlength="160" required value="' . e($a['name'] ?? '') . '">', '', true, 'grow')
       . inc_field('Type', inc_select('type', fp_account_types(), $a['type'] ?? 'expense'), '', true)
       . inc_field('Kind', inc_select('subtype', $subs, $a['subtype'] ?? 'other')) . '</div>'
       . '<div class="inc-row">' . inc_field('Under group', inc_select('parent_id', $groups, $a['parent_id'] ?? '', 'Top level'))
       . inc_field('Cash-flow class', inc_select('cash_flow', ['operating' => 'Operating', 'investing' => 'Investing', 'financing' => 'Financing'], $a['cash_flow'] ?? 'operating'))
       . inc_field('Description', '<input name="description" maxlength="255" value="' . e($a['description'] ?? '') . '">', '', false, 'grow') . '</div>'
       . '<label class="check"><input type="checkbox" name="is_group" value="1"' . (!empty($a['is_group']) ? ' checked' : '') . '> Group (holds other accounts; takes no entries)</label> '
       . '<label class="check"><input type="hidden" name="active" value="0"><input type="checkbox" name="active" value="1"' . (!isset($a['active']) || (int) $a['active'] === 1 ? ' checked' : '') . '> Active</label>'
       . (!empty($a['system_key']) ? '<p class="muted small">Control account used by the system: <code>' . e($a['system_key']) . '</code>. It can be renamed but not removed.</p>' : '')
       . '<div class="inc-formfoot"><button class="btn btn-primary">Save</button> <a class="btn" href="' . e(fp_url('accounts')) . '">Cancel</a></div></form></section>';
}

echo '<div class="scrollx"><table class="data inc-table fp-coa"><thead><tr><th>Code</th><th>Account</th><th>Type</th><th>Cash flow</th><th class="num">Balance</th><th></th></tr></thead><tbody>';
$draw = static function (int $parent, int $depth) use (&$draw, $accts, $children, $roll): void {
    foreach ($children[$parent] ?? [] as $id) {
        $a = $accts[$id];
        $g = (int) $a['is_group'] === 1;
        echo '<tr class="' . ($g ? 'fp-sub' : '') . ((int) $a['active'] !== 1 ? ' muted' : '') . '"><td>' . e($a['code']) . '</td>'
           . '<td style="padding-left:' . (8 + $depth * 18) . 'px">' . ($g ? '<strong>' . e($a['name']) . '</strong>' : '<a href="' . e(fp_url('ledger', ['account' => $id])) . '">' . e($a['name']) . '</a>')
           . ($a['system_key'] ? ' <span class="muted small" title="Posted to by the system">●</span>' : '') . ((int) $a['active'] !== 1 ? ' ' . inc_badge('Inactive') : '') . '</td>'
           . '<td>' . e(fp_account_types()[$a['type']]) . '</td><td class="small">' . e(ucfirst($a['cash_flow'])) . '</td>' . fp_money_td($roll($id))
           . '<td>' . (fp_can('coa.manage') ? '<a class="btn btn-sm" href="' . e(fp_url('accounts', ['edit' => $id])) . '">Edit</a>' : '') . '</td></tr>';
        $draw($id, $depth + 1);
    }
};
$draw(0, 0);
echo '</tbody></table></div><p class="muted small">● marks a control account the system posts to (payables, receivables, GST, TDS, bank …). Balances are in the account\'s natural direction.</p>';
fp_layout_end();

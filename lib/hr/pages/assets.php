<?php
/**
 * Assets — the register of company property, who holds what, and the
 * history of every hand-over.
 *
 *   ?asset=ID          one asset
 *   ?asset=new         the add form
 */

require_once __DIR__ . '/parts.php';

$orgView = hr_is_admin() || hr_scope('assets', 'view') === 'org';
$hr = hr_is_admin() || hr_scope('assets', 'edit') === 'org';
$canAdd = hr_is_admin() || hr_scope('assets', 'create') === 'org';
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    $aid = (int) ($_POST['asset'] ?? 0);
    $back = $aid ? hr_url('assets', ['asset' => $aid]) : hr_url('assets');
    if (in_array($a, ['master_save', 'master_toggle', 'master_delete', 'master_import'], true)) {
        $flash = hr_parts_post(hr_url('assets', ['tab' => 'setup']));
    } elseif ($a === 'save') {
        $flash = hr_action(static function () use ($aid) {
            $id = hr_asset_save($aid ?: null, $_POST);
            hr_redirect(hr_url('assets', ['asset' => $id]), ['ok', 'Asset saved.']);
        }, $back);
    } elseif ($a === 'issue') {
        $flash = hr_action(static function () use ($aid) { hr_asset_issue($aid, $_POST); return 'Issued.'; }, $back);
    } elseif ($a === 'return') {
        $flash = hr_action(static function () use ($aid) { hr_asset_return($aid, $_POST); return 'Taken back.'; }, (string) ($_POST['back'] ?? '') === 'list' ? hr_url('assets', ['status' => 'assigned']) : $back);
    } elseif ($a === 'delete') {
        $flash = hr_action(static function () use ($aid) { hr_asset_delete($aid); return 'Asset deleted.'; }, hr_url('assets'));
    }
}

/* ---------------------------------------------------------------- one asset */

$aParam = (string) ($_GET['asset'] ?? '');
if ($aParam !== '') {
    $isNew = $aParam === 'new';
    $x = $isNew ? null : hr_asset((int) $aParam);
    if (!$isNew && !$x) {
        fabo_deny('That asset is not available to you.', 404);
    }
    if ($isNew && !$canAdd) {
        fabo_deny('Only HR or admin add assets.', 403);
    }
    $aid = $isNew ? 0 : (int) $x['id'];
    $editing = $isNew || ($hr && !empty($_GET['edit']));
    if ($editing) {
        $v = $x ?? ($flash ? $_POST : []);
        hr_layout_start($isNew ? 'Add an asset' : 'Edit ' . $x['tag'], '', ['Assets' => hr_url('assets'), $isNew ? 'New' : $x['tag'] => '']);
        echo hr_flash_html($flash);
        $statuses = hr_asset_statuses();
        unset($statuses['assigned']);
        echo '<div class="card">' . hr_form_open($isNew ? hr_url('assets', ['asset' => 'new']) : hr_url('assets', ['asset' => $aid, 'edit' => 1]), 'class="hr-form"')
           . '<input type="hidden" name="action" value="save"><input type="hidden" name="asset" value="' . $aid . '"><div class="formgrid">'
           . hr_field('Asset tag', hr_input('tag', $v['tag'] ?? '', 'text', ['required' => true, 'maxlength' => 40, 'placeholder' => 'e.g. LAP-0042']), true, 'The label stuck on the item. Must be unique.')
           . hr_field('Name', hr_input('name', $v['name'] ?? '', 'text', ['required' => true, 'maxlength' => 160, 'placeholder' => 'e.g. Dell Latitude 5440']), true)
           . hr_field('Category', hr_select('category_id', hr_master_map('asset_category'), $v['category_id'] ?? '', 'None'))
           . hr_field('Make / model', hr_input('make_model', $v['make_model'] ?? '', 'text', ['maxlength' => 120]))
           . hr_field('Serial number', hr_input('serial', $v['serial'] ?? '', 'text', ['maxlength' => 80]))
           . hr_field('Purchased on', hr_input('purchase_date', $v['purchase_date'] ?? '', 'date'))
           . hr_field('Cost (₹)', hr_input('cost', $v['cost'] ?? '', 'number', ['step' => '0.01', 'min' => 0]))
           . hr_field('Warranty until', hr_input('warranty_until', $v['warranty_until'] ?? '', 'date'))
           . ($x && $x['status'] !== 'assigned' ? hr_field('Status', hr_select('status', $statuses, $x['status'])) : '')
           . hr_field('Notes', '<textarea name="notes" rows="2" maxlength="2000">' . e($v['notes'] ?? '') . '</textarea>', false, '', 'full')
           . '</div><div class="hr-formfoot"><button class="btn btn-primary">Save</button><a class="btn" href="' . e($x ? hr_url('assets', ['asset' => $aid]) : hr_url('assets')) . '">Cancel</a></div></form></div>';
        hr_layout_end();
        return;
    }
    hr_layout_start($x['tag'] . ' — ' . $x['name'], e($x['category'] ?? 'No category') . ' · ' . hr_badge(strtolower(hr_asset_statuses()[$x['status']]), hr_asset_statuses()[$x['status']]),
                    ['Assets' => hr_url('assets'), $x['tag'] => ''], $hr ? '<a class="btn btn-sm" href="' . e(hr_url('assets', ['asset' => $aid, 'edit' => 1])) . '">Edit</a>' : '');
    echo hr_flash_html($flash);
    echo '<div class="hr-two"><div class="card"><h2>Details</h2><dl class="hr-kv">'
       . '<dt>Make / model</dt><dd>' . e($x['make_model'] ?? '—') . '</dd><dt>Serial</dt><dd>' . e($x['serial'] ?? '—') . '</dd>'
       . '<dt>Purchased</dt><dd>' . e(hr_fmt_date($x['purchase_date']) ?: '—') . '</dd><dt>Cost</dt><dd>' . ($x['cost'] !== null ? e(fabo_money_exact($x['cost'])) : '—') . '</dd>'
       . '<dt>Warranty until</dt><dd>' . ($x['warranty_until'] ? e(hr_fmt_date($x['warranty_until'])) . ($x['warranty_until'] < date('Y-m-d') ? ' ' . hr_badge('closed', 'Expired') : '') : '—') . '</dd>'
       . '<dt>Notes</dt><dd>' . nl2br(e($x['notes'] ?? '—')) . '</dd></dl></div>';
    echo '<div class="card"><h2>Who has it</h2>';
    if ($x['status'] === 'assigned') {
        echo '<p class="mt0">' . hr_person_cell(['id' => $x['employee_id'], 'full_name' => $x['full_name'], 'emp_code' => $x['emp_code']]) . '</p>';
        if ($hr) {
            echo hr_form_open(hr_url('assets', ['asset' => $aid]), 'class="hr-form"') . '<input type="hidden" name="action" value="return"><input type="hidden" name="asset" value="' . $aid . '"><div class="formgrid">'
               . hr_field('Returned on', hr_input('returned_on', date('Y-m-d'), 'date'))
               . hr_field('Then', hr_select('status', ['in_stock' => 'Back in stock', 'repair' => 'Send for repair', 'lost' => 'Lost / not returned', 'retired' => 'Retire it']))
               . hr_field('Condition note', hr_input('note', '', 'text', ['maxlength' => 255]), false, '', 'full')
               . '</div><button class="btn btn-primary">Take back</button></form>';
        }
    } elseif ($x['status'] === 'in_stock' && $hr) {
        echo '<p class="mt0 muted">In stock.</p>' . hr_form_open(hr_url('assets', ['asset' => $aid]), 'class="hr-form"') . '<input type="hidden" name="action" value="issue"><input type="hidden" name="asset" value="' . $aid . '"><div class="formgrid">'
           . hr_field('Issue to', hr_select('employee_id', hr_employee_choices(true, 'assets', 'edit'), (int) ($_GET['to'] ?? 0), 'Choose…', ['required' => true]), true)
           . hr_field('Issued on', hr_input('assigned_on', date('Y-m-d'), 'date'))
           . hr_field('Due back', hr_input('due_back', '', 'date'), false, 'Only for a loan.')
           . hr_field('Note', hr_input('note', '', 'text', ['maxlength' => 255]), false, '', 'full')
           . '</div><button class="btn btn-primary">Issue</button></form>';
    } else {
        echo hr_empty(hr_asset_statuses()[$x['status']] . '.');
    }
    echo '</div></div>';
    $hist = hr_asset_history($aid);
    echo '<div class="card"><h2>History</h2>';
    if (!$hist) {
        echo hr_empty('Never issued.');
    } else {
        echo '<table class="data hr-table stack"><thead><tr><th>Employee</th><th>Issued</th><th>Returned</th><th>Notes</th></tr></thead><tbody>';
        foreach ($hist as $h) {
            echo '<tr><td data-l="Employee">' . e($h['full_name']) . ' <span class="muted small">' . e($h['emp_code']) . '</span></td>'
               . '<td data-l="Issued" class="nowrap">' . e(hr_fmt_date($h['assigned_on'])) . '<div class="muted small">' . e($h['assigned_by'] ?? '') . '</div></td>'
               . '<td data-l="Returned" class="nowrap">' . ($h['returned_on'] ? e(hr_fmt_date($h['returned_on'])) : hr_badge('assigned', 'Still held')) . '</td>'
               . '<td data-l="Notes" class="small">' . e(trim(($h['issue_note'] ?? '') . ($h['return_note'] ? ' · Return: ' . $h['return_note'] : ''), ' ·')) . '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';
    if (hr_scope('assets', 'delete') === 'org' && !$hist) {
        echo hr_button_form('Delete asset', ['action' => 'delete', 'asset' => $aid], 'btn btn-sm btn-danger', 'Delete this asset from the register?');
    }
    hr_layout_end();
    return;
}

/* -------------------------------------------------------------- the desk */

$tabs = ['register' => $orgView ? 'Register' : 'Assets held'];
if ($hr) { $tabs['setup'] = 'Categories'; }
$tab = hr_tab($tabs, 'register');
hr_layout_start('Assets', $orgView ? 'Company property: what there is, where it is and who has it.' : 'Company property you (or your team) hold.', ['Assets' => ''],
                ($canAdd ? '<a class="btn btn-sm btn-primary" href="' . e(hr_url('assets', ['asset' => 'new'])) . '">+ Add asset</a>' : '')
              . (hr_report_allowed('asset_register') ? '<a class="btn btn-sm" href="' . e(hr_url('file', ['kind' => 'report', 'report' => 'asset_register', 'fmt' => 'xlsx'])) . '">Export (Excel)</a>' : ''));
echo hr_flash_html($flash);
hr_tabs('assets', $tabs, $tab);

if ($tab === 'register') {
    $f = ['q' => trim((string) ($_GET['q'] ?? '')), 'status' => (string) ($_GET['status'] ?? ''), 'category' => (int) ($_GET['category'] ?? 0), 'employee' => (int) ($_GET['employee'] ?? 0)];
    $rows = hr_assets_list($f);
    if ($orgView && !array_filter($f)) {
        $all = hr_assets_list([]);
        $by = array_count_values(array_column($all, 'status'));
        $value = array_sum(array_map(static fn($r) => in_array($r['status'], ['retired', 'lost'], true) ? 0 : (float) $r['cost'], $all));
        echo '<div class="tiles">' . hr_tile('Assets', (string) count($all), '', '', hr_url('assets'))
           . hr_tile('Issued', (string) ($by['assigned'] ?? 0), '', '', hr_url('assets', ['status' => 'assigned']))
           . hr_tile('In stock', (string) ($by['in_stock'] ?? 0), 'green', '', hr_url('assets', ['status' => 'in_stock']))
           . hr_tile('In repair', (string) ($by['repair'] ?? 0), ($by['repair'] ?? 0) ? 'amber' : '', '', hr_url('assets', ['status' => 'repair']))
           . hr_tile('Value in use', e(fabo_money_exact($value)), '', 'At purchase cost') . '</div>';
    }
    if ($orgView) {
        echo '<form method="get" class="card hr-filters" role="search"><input type="hidden" name="p" value="assets">'
           . '<div class="grp"><label for="aq">Search</label><input id="aq" type="search" name="q" value="' . e($f['q']) . '" placeholder="Tag, name, serial"></div>'
           . '<div class="grp"><label for="as">Status</label><select id="as" name="status" data-autosubmit>' . hr_options(hr_asset_statuses(), $f['status'], 'All') . '</select></div>'
           . '<div class="grp"><label for="ac">Category</label><select id="ac" name="category" data-autosubmit>' . hr_options(hr_master_map('asset_category', false), $f['category'], 'All') . '</select></div>'
           . '<div class="grp"><label for="ae">Held by</label><select id="ae" name="employee" data-autosubmit>' . hr_options(hr_employee_choices(false, 'assets', 'view'), $f['employee'], 'Anyone') . '</select></div>'
           . '<button class="btn btn-sm btn-primary">Search</button>' . (array_filter($f) ? '<a class="btn btn-sm" href="' . e(hr_url('assets')) . '">Clear</a>' : '') . '</form>';
    }
    echo '<div class="card">';
    if (!$rows) {
        echo hr_empty(array_filter($f) ? 'No assets match.' : ($orgView ? 'The register is empty.' : 'No company assets are issued to you.'),
                      $canAdd && !array_filter($f) ? ' Add categories first (laptops, phones, ID cards), then the assets.' : '');
    } else {
        echo '<div class="scrollx"><table class="data hr-table stack"><thead><tr><th>Tag</th><th>Asset</th><th>Category</th><th>Held by</th><th>Status</th><th></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $late = $r['due_back'] && $r['due_back'] < date('Y-m-d');
            echo '<tr><td data-l="Tag"><a class="nm" href="' . e(hr_url('assets', ['asset' => $r['id']])) . '"><strong>' . e($r['tag']) . '</strong></a></td>'
               . '<td data-l="Asset">' . e($r['name']) . '<div class="muted small">' . e(trim(($r['make_model'] ?? '') . ($r['serial'] ? ' · ' . $r['serial'] : ''), ' ·')) . '</div></td>'
               . '<td data-l="Category">' . e($r['category'] ?? '—') . '</td>'
               . '<td data-l="Held by">' . ($r['employee_id'] ? hr_person_cell(['id' => $r['employee_id'], 'full_name' => $r['full_name'], 'emp_code' => $r['emp_code'], 'photo_file' => $r['photo_file']])
                                                            . ($r['due_back'] ? '<div class="small ' . ($late ? 'req' : 'muted') . '">Due back ' . e(hr_fmt_date($r['due_back'])) . '</div>' : '') : '—') . '</td>'
               . '<td data-l="Status">' . hr_badge(strtolower(hr_asset_statuses()[$r['status']]), hr_asset_statuses()[$r['status']]) . '</td>'
               . '<td class="hr-acts"><a class="btn btn-sm" href="' . e(hr_url('assets', ['asset' => $r['id']])) . '">' . ($hr && $r['status'] === 'in_stock' ? 'Issue' : 'Open') . '</a></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';
}

if ($tab === 'setup') {
    hr_part_masters('asset_category', hr_url('assets', ['tab' => 'setup']), ['tab' => 'setup']);
}

hr_layout_end();

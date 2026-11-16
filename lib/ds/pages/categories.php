<?php
/** Document categories with their default workflow, and retention policies. */

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $flash = ds_action(static function () {
        switch ($_POST['action'] ?? '') {
            case 'add':
                $name = ds_str($_POST['name'] ?? '', 120, true, 'Category name');
                fabo_exec('INSERT INTO ds_categories (org_id, name, code, sort) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE active = 1, deleted_at = NULL', 'issi',
                          [ds_org(), $name, ds_str($_POST['code'] ?? '', 12) ?: null, 500]);
                ds_audit(null, 'category', null, 'category_add', 'Added category "' . $name . '"');
                return 'Category added.';
            case 'save':
                foreach ((array) ($_POST['cat'] ?? []) as $id => $c) {
                    $id = (int) $id;
                    $wf = (int) ($c['wf'] ?? 0) ?: null;
                    if ($wf && !ds_workflow($wf)) {
                        $wf = null;
                    }
                    fabo_exec('UPDATE ds_categories SET name = ?, code = ?, default_workflow_id = ?, active = ?, sort = ? WHERE id = ? AND org_id = ?', 'ssiiiii',
                              [ds_str($c['name'] ?? '', 120, true, 'Category name'), ds_str($c['code'] ?? '', 12) ?: null, $wf, !empty($c['active']) ? 1 : 0, (int) ($c['sort'] ?? 0), $id, ds_org()]);
                    $years = trim((string) ($c['years'] ?? ''));
                    if ($years === '') {
                        fabo_exec('DELETE FROM ds_retention_policies WHERE org_id = ? AND category_id = ?', 'ii', [ds_org(), $id]);
                    } else {
                        fabo_exec('INSERT INTO ds_retention_policies (org_id, category_id, retain_years, action) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE retain_years = VALUES(retain_years), action = VALUES(action), active = 1',
                                  'iiis', [ds_org(), $id, max(1, min(100, (int) $years)), ($c['purge'] ?? '') === 'purge' ? 'purge' : 'archive']);
                    }
                }
                $def = max(1, min(100, (int) ($_POST['default_years'] ?? 8)));
                fabo_exec('DELETE FROM ds_retention_policies WHERE org_id = ? AND category_id IS NULL', 'i', [ds_org()]);
                fabo_exec('INSERT INTO ds_retention_policies (org_id, category_id, retain_years, action) VALUES (?,NULL,?,?)', 'iis', [ds_org(), $def, ($_POST['default_action'] ?? '') === 'purge' ? 'purge' : 'archive']);
                ds_audit(null, 'category', null, 'category_save', 'Saved categories, default workflows and retention policies');
                return 'Saved.';
        }
        throw new InvalidArgumentException('Unknown action.');
    }, ds_url('categories'));
}
ds_categories();
$rows = fabo_all('SELECT * FROM ds_categories WHERE org_id = ? AND deleted_at IS NULL ORDER BY sort, name', 'i', [ds_org()]);
$ret = [];
foreach (fabo_all('SELECT * FROM ds_retention_policies WHERE org_id = ?', 'i', [ds_org()]) as $r) {
    $ret[$r['category_id'] === null ? 0 : (int) $r['category_id']] = $r;
}
$wfs = [];
foreach (ds_workflows() as $w) {
    $wfs[(int) $w['id']] = $w['name'];
}
ds_layout_start('Categories & retention', 'Each category may name the workflow its documents take by default, and how long they are kept after closing.', '', ['Administration' => ds_url('admin'), 'Categories' => null], $flash);
echo ds_form() . '<input type="hidden" name="action" value="save"><div class="ds-card ds-rise"><div class="ds-tablewrap"><table class="ds-table"><thead><tr><th>Order</th><th>Category</th><th>Code</th><th>Default workflow</th><th>Keep for (years)</th><th>Then</th><th>Active</th><th class="num">Documents</th></tr></thead><tbody>';
foreach ($rows as $c) {
    $id = (int) $c['id'];
    $n = (int) fabo_one('SELECT COUNT(*) n FROM ds_documents WHERE org_id = ? AND category_id = ? AND deleted_at IS NULL', 'ii', [ds_org(), $id])['n'];
    echo '<tr><td><input type="number" class="ds-num" name="cat[' . $id . '][sort]" value="' . (int) $c['sort'] . '" aria-label="Order"></td><td><input name="cat[' . $id . '][name]" value="' . e($c['name']) . '" maxlength="120" required aria-label="Name"></td>'
       . '<td><input class="ds-num" name="cat[' . $id . '][code]" value="' . e((string) $c['code']) . '" maxlength="12" aria-label="Code"></td><td>' . ds_select('cat[' . $id . '][wf]', $wfs, $c['default_workflow_id'], 'Suggest by conditions') . '</td>'
       . '<td><input type="number" class="ds-num" min="1" max="100" name="cat[' . $id . '][years]" value="' . e((string) ($ret[$id]['retain_years'] ?? '')) . '" placeholder="Default" aria-label="Years"></td>'
       . '<td>' . ds_select('cat[' . $id . '][purge]', ['archive' => 'Archive', 'purge' => 'Purge files'], $ret[$id]['action'] ?? 'archive') . '</td>'
       . '<td><input type="checkbox" name="cat[' . $id . '][active]" value="1"' . ((int) $c['active'] ? ' checked' : '') . ' aria-label="Active"></td><td class="num">' . $n . '</td></tr>';
}
echo '</tbody></table></div><div class="ds-card-b ds-formgrid">' . ds_field('Default retention (years)', '<input type="number" name="default_years" min="1" max="100" value="' . e((string) ($ret[0]['retain_years'] ?? ds_setting('retention_years'))) . '">', 'For categories without their own.')
   . ds_field('Then', ds_select('default_action', ['archive' => 'Archive (keep)', 'purge' => 'Purge the files'], $ret[0]['action'] ?? 'archive'), 'Purging removes the PDFs; the record and audit trail remain. Legal hold always prevents it.')
   . '</div><div class="ds-card-f"><button class="ds-btn ds-btn-primary">Save</button></div></div></form>';
echo ds_card_start('Add a category', '', '', 'plus') . ds_form('class="ds-inlineform"') . '<input type="hidden" name="action" value="add"><input name="name" required maxlength="120" placeholder="Category name"><input name="code" maxlength="12" placeholder="Code (optional)">'
   . '<button class="ds-btn ds-btn-sm">' . ds_icon('plus') . 'Add</button></form>' . ds_card_end();
ds_layout_end();

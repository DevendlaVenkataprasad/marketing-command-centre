<?php
/** Roles & permissions: who holds which role (and in which departments), and what each role may do. */

$roles = ds_roles();
$perms = ds_permissions();
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $flash = ds_action(static function () use ($roles, $perms) {
        switch ($_POST['action'] ?? '') {
            case 'grant':
                $pid = (int) ($_POST['person_id'] ?? 0);
                $role = ds_pick($_POST['role'] ?? '', $roles, 'role');
                if (!ds_is_member($pid) || $pid === 0) {
                    throw new InvalidArgumentException('Choose someone in this organisation.');
                }
                $depts = array_values(array_filter(array_map('intval', (array) ($_POST['depts'] ?? []))));
                if ($role === 'dept_admin' && !$depts) {
                    throw new InvalidArgumentException('Name the departments a Department Administrator runs.');
                }
                foreach ($depts ?: [0] as $d) {
                    fabo_exec('INSERT IGNORE INTO ds_role_members (org_id, person_id, role, department_id, granted_by) VALUES (?,?,?,?,?)', 'iisis', [ds_org(), $pid, $role, $d, ds_actor()]);
                }
                ds_audit(null, 'role', $pid, 'role_grant', 'Gave ' . ds_person_name($pid) . ' the role ' . $roles[$role][0] . ($depts ? ' for ' . implode(', ', array_map('ds_dept_name', $depts)) : ''));
                ds_notify([$pid], 'role', 'You are now ' . $roles[$role][0] . ' in Digital Signatures', $roles[$role][1], null, ['p' => 'dashboard']);
                return 'Role given.';
            case 'revoke':
                $m = fabo_one('SELECT * FROM ds_role_members WHERE id = ? AND org_id = ?', 'ii', [(int) ($_POST['id'] ?? 0), ds_org()]);
                if ($m) {
                    fabo_exec('DELETE FROM ds_role_members WHERE id = ?', 'i', [(int) $m['id']]);
                    ds_audit(null, 'role', (int) $m['person_id'], 'role_revoke', 'Took the role ' . ($roles[$m['role']][0] ?? $m['role']) . ' from ' . ds_person_name((int) $m['person_id']));
                }
                return 'Role removed.';
            case 'matrix':
                $m = [];
                foreach (array_keys($roles) as $r) {
                    $m[$r] = array_values(array_intersect(array_keys((array) ($_POST['m'][$r] ?? [])), array_keys($perms)));
                }
                ds_setting_put('perm_matrix', json_encode($m));
                ds_audit(null, 'role', null, 'matrix', 'Changed the permission matrix');
                return 'Permissions saved.';
            case 'reset':
                ds_setting_put('perm_matrix', '');
                ds_audit(null, 'role', null, 'matrix', 'Reset the permission matrix to the defaults');
                return 'Permissions reset to the defaults.';
        }
        throw new InvalidArgumentException('Unknown action.');
    }, ds_url('roles'));
}
$members = fabo_all('SELECT * FROM ds_role_members WHERE org_id = ? ORDER BY role, person_id', 'i', [ds_org()]);
ds_layout_start('Roles & permissions', 'The organisation\'s head (and the portal administrator) is always Super Administrator. Everyone else signs what is sent to them; roles add the rest.', '',
    ['Administration' => ds_url('admin'), 'Roles' => null], $flash);
echo '<div class="ds-grid g-2-1"><div>';
foreach ($roles as $k => [$label, $desc]) {
    $ms = array_filter($members, static fn($m) => $m['role'] === $k);
    echo '<section class="ds-card ds-rolecard ds-rise"><div class="ds-card-h"><h2>' . ds_icon('key') . e($label) . ' <small>' . count($ms) . '</small></h2></div><div class="ds-card-b"><p class="ds-muted small">' . e($desc) . '</p><div class="ds-chips">';
    foreach ($ms as $m) {
        echo '<span class="ds-person">' . ds_avatar((int) $m['person_id'], 'sm') . e(ds_person_name((int) $m['person_id'])) . ((int) $m['department_id'] ? ' <small>' . e(ds_dept_name((int) $m['department_id'])) . '</small>' : '')
           . ds_button('×', ['action' => 'revoke', 'id' => $m['id']], 'ds-x', 'Remove ' . $label . ' from ' . ds_person_name((int) $m['person_id']) . '?') . '</span>';
    }
    echo $ms ? '' : '<span class="ds-muted small">Nobody yet</span>';
    echo '</div></div></section>';
}
echo '</div>' . ds_card_start('Give a role', '', '', 'plus') . ds_form() . '<input type="hidden" name="action" value="grant">'
   . ds_field('Person', ds_select('person_id', ds_people(), '', 'Choose…', 'required'), '', true) . ds_field('Role', ds_select('role', array_map(static fn($r) => $r[0], $roles), 'creator'), '', true)
   . (ds_departments() ? ds_field('Departments (for scoped roles)', '<select name="depts[]" multiple size="5">' . inc_options(ds_departments(), []) . '</select>', 'Required for Department Administrator; optional for Viewer.') : '')
   . '<button class="ds-btn ds-btn-primary ds-btn-block">Give role</button></form>' . ds_card_end() . '</div>';
$m = ds_matrix();
echo ds_form() . '<input type="hidden" name="action" value="matrix"><section class="ds-card ds-rise"><div class="ds-card-h"><h2>' . ds_icon('gear') . 'Permission matrix</h2><div class="ds-card-a">'
   . '<button class="ds-btn ds-btn-ghost ds-btn-sm" name="action" value="reset" data-confirm="Reset every role to its default permissions?">Reset to defaults</button></div></div><div class="ds-tablewrap"><table class="ds-table ds-matrix"><thead><tr><th>Permission</th>';
foreach ($roles as $k => [$label]) {
    echo '<th class="c"><span>' . e($label) . '</span></th>';
}
echo '</tr></thead><tbody>';
$group = '';
foreach ($perms as $p => [$g, $label]) {
    if ($g !== $group) {
        $group = $g;
        echo '<tr class="grp"><td colspan="' . (count($roles) + 1) . '">' . e($g) . '</td></tr>';
    }
    echo '<tr><td>' . e($label) . '</td>';
    foreach (array_keys($roles) as $r) {
        echo '<td class="c"><input type="checkbox" name="m[' . $r . '][' . $p . ']" value="1"' . (in_array($p, $m[$r], true) ? ' checked' : '') . ($r === 'super_admin' ? ' disabled checked' : '') . ' aria-label="' . e($roles[$r][0] . ': ' . $label) . '"></td>';
    }
    echo '</tr>';
}
echo '</tbody></table></div><div class="ds-card-f"><span class="ds-muted small">Everyone may always sign what is addressed to them and verify a PDF. ' . (ds_setting('members_create') === '1' ? 'Everyone may also create documents (Settings).' : '') . '</span>'
   . '<button class="ds-btn ds-btn-primary">Save permissions</button></div></section></form>';
ds_layout_end();

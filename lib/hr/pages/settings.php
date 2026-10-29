<?php
/**
 * Access Control — administrator only.
 *
 * Nobody but the administrator has any HR access until a role is created
 * here AND given to them. Templates only fill in a starting set of ticks for
 * a new role; a role grants nothing until people are added to it.
 */

if (!hr_is_admin()) {
    fabo_deny('Only the administrator manages HR access.', 403);
}

/** Accounts in the current organisation that can be given a role. */
function hr_org_accounts(): array
{
    $org = hr_org();
    $rows = $org === 0
        ? fabo_all("SELECT id, name, email, kind FROM clients WHERE kind = 'team' AND (parent_id IS NULL OR parent_id = 0) ORDER BY name")
        : fabo_all('SELECT id, name, email, kind FROM clients WHERE id = ? OR parent_id = ? ORDER BY name', 'ii', [$org, $org]);
    $out = [];
    foreach ($rows as $r) {
        $out[(int) $r['id']] = $r['name'] . ($r['email'] ? ' — ' . $r['email'] : '') . ($org ? ($r['kind'] === 'team' ? ' (their team)' : ' (client)') : '');
    }
    return $out;
}

$flash = null;
$roleId = (int) ($_GET['role'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    $rid = (int) ($_POST['role'] ?? 0);
    $back = hr_url('settings', ['tab' => 'roles', 'role' => $rid]);
    $role = $rid ? fabo_one('SELECT * FROM hr_roles WHERE id = ? AND org_id = ?', 'ii', [$rid, hr_org()]) : null;
    if ($rid && !$role) {
        $flash = ['err', 'That role no longer exists.'];
    } elseif ($a === 'role_create') {
        $flash = hr_action(static function () {
            $name = hr_str($_POST['name'] ?? '', 120, true, 'Role name');
            $scope = (string) ($_POST['scope'] ?? 'self');
            if (!in_array($scope, ['self', 'team', 'org'], true)) { throw new InvalidArgumentException('Choose who the role covers.'); }
            if (fabo_one('SELECT id FROM hr_roles WHERE org_id = ? AND name = ?', 'is', [hr_org(), $name])) {
                throw new InvalidArgumentException('A role called “' . $name . '” already exists.');
            }
            $tpl = hr_role_templates()[(string) ($_POST['template'] ?? '')] ?? null;
            $id = hr_tx(static function () use ($name, $scope, $tpl) {
                $id = fabo_exec('INSERT INTO hr_roles (org_id, name, description, scope, created_by) VALUES (?,?,?,?,?)', 'issss',
                                [hr_org(), $name, hr_str($_POST['description'] ?? '', 255) ?: ($tpl[2] ?? null), $scope, hr_actor()]);
                if ($tpl) {
                    foreach ($tpl[3] as $m => $acts) {
                        foreach ($acts as $act) {
                            if ($scope === 'self' && !hr_self_allowed($m, $act)) { continue; }
                            if ($scope !== 'org' && hr_org_only_module($m)) { continue; }
                            fabo_exec('INSERT IGNORE INTO hr_role_perms (role_id, module, action) VALUES (?,?,?)', 'iss', [$id, $m, $act]);
                        }
                    }
                }
                return $id;
            });
            hr_audit('create', 'hr_role', $id, 'Role created: ' . $name . ' (' . $scope . ')');
            hr_redirect(hr_url('settings', ['tab' => 'roles', 'role' => $id]), ['ok', 'Role created. It grants nothing until you add people to it below.']);
        }, hr_url('settings', ['tab' => 'roles']));
    } elseif ($a === 'role_perms') {
        $flash = hr_action(static function () use ($role) {
            $scope = (string) ($_POST['scope'] ?? $role['scope']);
            if (!in_array($scope, ['self', 'team', 'org'], true)) { throw new InvalidArgumentException('Choose who the role covers.'); }
            $want = [];
            $refused = [];
            foreach (hr_modules() as $m => $def) {
                if (!empty($def['admin_only'])) { continue; }
                foreach ($def['actions'] as $act) {
                    if (empty($_POST['perm'][$m][$act])) { continue; }
                    if (($scope === 'self' && !hr_self_allowed($m, $act)) || ($scope !== 'org' && hr_org_only_module($m))) {
                        $refused[] = $def['label'] . ' · ' . hr_action_labels()[$act];
                        continue;
                    }
                    $want[] = [$m, $act];
                }
            }
            $before = fabo_all('SELECT module, action FROM hr_role_perms WHERE role_id = ?', 'i', [(int) $role['id']]);
            hr_tx(static function () use ($role, $scope, $want) {
                fabo_exec('UPDATE hr_roles SET scope = ?, description = ?, active = ? WHERE id = ?', 'ssii',
                          [$scope, hr_str($_POST['description'] ?? '', 255) ?: null, !empty($_POST['active']) ? 1 : 0, (int) $role['id']]);
                fabo_exec('DELETE FROM hr_role_perms WHERE role_id = ?', 'i', [(int) $role['id']]);
                foreach ($want as [$m, $act]) {
                    fabo_exec('INSERT INTO hr_role_perms (role_id, module, action) VALUES (?,?,?)', 'iss', [(int) $role['id'], $m, $act]);
                }
            });
            hr_audit('permissions', 'hr_role', (int) $role['id'], 'Permissions saved for role ' . $role['name'] . ' (' . count($want) . ' granted, scope ' . $scope . ')',
                     $before, $want);
            return 'Permissions saved.' . ($refused ? ' Not allowed for this scope and left off: ' . implode(', ', $refused) . '.' : '');
        }, $back);
    } elseif ($a === 'member_add') {
        $flash = hr_action(static function () use ($role) {
            $pid = (int) ($_POST['person_id'] ?? 0);
            if (!isset(hr_org_accounts()[$pid])) { throw new InvalidArgumentException('Choose an account from this organisation.'); }
            fabo_exec('INSERT IGNORE INTO hr_role_members (role_id, person_id, granted_by) VALUES (?,?,?)', 'iis', [(int) $role['id'], $pid, hr_actor()]);
            hr_audit('grant', 'hr_role', (int) $role['id'], 'Role ' . $role['name'] . ' given to person #' . $pid);
            $p = fabo_one('SELECT name FROM clients WHERE id = ?', 'i', [$pid]);
            fabo_notify($pid, 'You have been given HR access', 'You now have the “' . $role['name'] . '” HR role. Open HR from the menu.', 'hr.php');
            $linked = fabo_one('SELECT id FROM hr_employees WHERE person_id = ?', 'i', [$pid]);
            return ($p['name'] ?? 'They') . ' now hold “' . $role['name'] . '”.' . (!$linked && $role['scope'] !== 'org'
                   ? ' Their account is not linked to an employee record yet, so self and team access will show nothing until you link it on their employee record.' : '');
        }, $back);
    } elseif ($a === 'member_remove') {
        $flash = hr_action(static function () use ($role) {
            $pid = (int) ($_POST['person_id'] ?? 0);
            fabo_exec('DELETE FROM hr_role_members WHERE role_id = ? AND person_id = ?', 'ii', [(int) $role['id'], $pid]);
            hr_audit('revoke', 'hr_role', (int) $role['id'], 'Role ' . $role['name'] . ' removed from person #' . $pid);
            return 'Access removed. It takes effect on their next page.';
        }, $back);
    } elseif ($a === 'roles_upgrade') {
        $flash = hr_action(static function () {
            $n = hr_roles_add_new_modules();
            return $n ? $n . ' permission(s) added to roles made from the standard templates.' : 'Those roles already include the newer modules.';
        }, hr_url('settings', ['tab' => 'roles']));
    } elseif ($a === 'role_delete') {
        $flash = hr_action(static function () use ($role) {
            fabo_exec('DELETE FROM hr_roles WHERE id = ? AND org_id = ?', 'ii', [(int) $role['id'], hr_org()]);
            hr_audit('delete', 'hr_role', (int) $role['id'], 'Role deleted: ' . $role['name'], $role, null);
            return 'Role deleted. Everyone who held it lost that access.';
        }, hr_url('settings', ['tab' => 'roles']));
    }
}

$tabs = ['roles' => 'Roles & permissions', 'check' => 'Who can do what', 'audit' => 'Audit log', 'general' => 'General settings'];
$tab = hr_tab($tabs, 'roles');
hr_layout_start('Access Control', 'Only the administrator can open this page. Everyone else has no HR access until you give them a role.',
                ['HR Setup' => hr_url('setup'), 'Access Control' => '']);
echo hr_flash_html($flash);
hr_tabs('settings', $tabs, $tab);

if ($tab === 'roles') {
    $roles = fabo_all('SELECT r.*, (SELECT COUNT(*) FROM hr_role_members m WHERE m.role_id = r.id) AS members,
                              (SELECT COUNT(*) FROM hr_role_perms p WHERE p.role_id = r.id) AS perms
                         FROM hr_roles r WHERE r.org_id = ? ORDER BY r.name', 'i', [hr_org()]);
    $scopes = ['self' => 'Their own record only', 'team' => 'Their team (people reporting to them) and themselves', 'org' => 'Everyone in the organisation'];
    echo '<div class="hr-note"><strong>How access works.</strong> A role is a set of ticks — module × action — plus who it covers. '
       . 'Give a role to a person and they see HR in their menu, limited to exactly those ticks. The checks run on the server for every page, '
       . 'action and download, so changing an address or an ID in the browser does not get round them.</div>';
    $behind = hr_roles_missing_new_modules();
    if ($behind) {
        echo '<div class="hr-warn"><strong>Newer HR modules are not in ' . count($behind) . ' of your roles yet</strong> — ' . e(implode(', ', $behind))
           . '. Recruitment, Onboarding &amp; Exits, Performance, Training, Assets, Expense Claims and the Helpdesk were added after these roles were made, '
           . 'so the people holding them cannot see those pages. '
           . hr_button_form('Add the standard permissions for them', ['action' => 'roles_upgrade'], 'btn btn-sm btn-primary',
                            'Add each template\'s permissions for the newer modules to the roles made from it? Nothing is removed; you can untick anything afterwards.')
           . '</div>';
    }
    echo '<div class="card"><h2>Roles in ' . e(hr_org_choices()[hr_org()] ?? 'this organisation') . '</h2>';
    if (!$roles) {
        echo hr_empty('No roles yet — so nobody but you has HR access.', ' Create a role below when you are ready to share.');
    } else {
        echo '<table class="data hr-table stack"><thead><tr><th>Role</th><th>Covers</th><th class="num">Permissions</th><th class="num">People</th><th>Status</th><th></th></tr></thead><tbody>';
        foreach ($roles as $r) {
            echo '<tr' . ((int) $r['id'] === $roleId ? ' style="background:#f4f8ff"' : '') . '><td data-l="Role"><strong>' . e($r['name']) . '</strong><div class="muted small">' . e($r['description'] ?? '') . '</div></td>'
               . '<td data-l="Covers">' . e($scopes[$r['scope']]) . '</td><td class="num" data-l="Permissions">' . (int) $r['perms'] . '</td><td class="num" data-l="People">' . (int) $r['members'] . '</td>'
               . '<td data-l="Status">' . hr_badge($r['active'] ? 'active' : 'inactive') . '</td><td><a class="btn btn-sm" href="' . e(hr_url('settings', ['tab' => 'roles', 'role' => $r['id']])) . '#role">Manage</a></td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';

    $role = $roleId ? fabo_one('SELECT * FROM hr_roles WHERE id = ? AND org_id = ?', 'ii', [$roleId, hr_org()]) : null;
    if ($role) {
        $have = [];
        foreach (fabo_all('SELECT module, action FROM hr_role_perms WHERE role_id = ?', 'i', [$roleId]) as $p) { $have[$p['module']][$p['action']] = true; }
        echo '<div class="card" id="role"><h2>Role: ' . e($role['name']) . '</h2>' . hr_form_open('', 'class="hr-form"') . '<input type="hidden" name="action" value="role_perms"><input type="hidden" name="role" value="' . $roleId . '">'
           . '<div class="formgrid">' . hr_field('Covers', hr_select('scope', $scopes, $role['scope']), true, 'Team means the people who report to them on their employee record, at any depth.')
           . hr_field('Description', hr_input('description', $role['description'] ?? '', 'text', ['maxlength' => 255]))
           . '<div class="field"><label>Status</label>' . hr_checkbox('active', (bool) $role['active'], 'Active') . '</div></div>'
           . '<div class="scrollx"><table class="data hr-table hr-perm"><thead><tr><th>Module</th>';
        foreach (hr_action_labels() as $al) { echo '<th>' . e($al) . '</th>'; }
        echo '</tr></thead><tbody>';
        foreach (hr_modules() as $m => $def) {
            if (!empty($def['admin_only'])) { continue; }
            echo '<tr><td>' . e($def['label']) . (hr_org_only_module($m) ? ' <span class="muted small">(organisation-wide roles only)</span>' : '') . '</td>';
            foreach (array_keys(hr_action_labels()) as $act) {
                if (!in_array($act, $def['actions'], true)) { echo '<td class="na">—</td>'; continue; }
                $ok = ($role['scope'] !== 'self' || hr_self_allowed($m, $act)) && ($role['scope'] === 'org' || !hr_org_only_module($m));
                echo '<td><input type="checkbox" name="perm[' . e($m) . '][' . e($act) . ']" value="1"' . (!empty($have[$m][$act]) ? ' checked' : '')
                   . ($ok ? '' : ' title="Not available for this scope"') . ' aria-label="' . e($def['label'] . ' ' . hr_action_labels()[$act]) . '"></td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div><p class="hr-hint">With “their own record only”, approving, configuring and payroll processing are refused even if ticked. '
           . 'Access Control itself is never grantable.</p><div class="hr-formfoot"><button class="btn btn-primary">Save permissions</button></div></form>';

        $members = fabo_all('SELECT m.*, c.name, c.email FROM hr_role_members m JOIN clients c ON c.id = m.person_id WHERE m.role_id = ? ORDER BY c.name', 'i', [$roleId]);
        echo '<h3 style="margin-top:18px">People with this role</h3>';
        if (!$members) {
            echo '<p class="muted">Nobody yet — this role grants nothing.</p>';
        } else {
            echo '<ul class="hr-list">';
            foreach ($members as $mb) {
                $emp = fabo_one('SELECT full_name, emp_code FROM hr_employees WHERE person_id = ?', 'i', [(int) $mb['person_id']]);
                echo '<li><span>' . e($mb['name']) . ' <span class="muted small">' . e($mb['email'] ?? '') . ' · ' . ($emp ? 'linked to ' . e($emp['full_name'] . ' (' . $emp['emp_code'] . ')') : 'not linked to an employee') . '</span></span>'
                   . hr_button_form('Remove', ['action' => 'member_remove', 'role' => $roleId, 'person_id' => $mb['person_id']], 'btn btn-sm btn-danger', 'Remove this person\'s HR access from this role?') . '</li>';
            }
            echo '</ul>';
        }
        $acc = hr_org_accounts();
        foreach ($members as $mb) { unset($acc[(int) $mb['person_id']]); }
        if ($acc) {
            echo hr_form_open('', 'class="hr-filters" style="padding:10px 0 0"') . '<input type="hidden" name="action" value="member_add"><input type="hidden" name="role" value="' . $roleId . '">'
               . '<div class="grp"><label for="ap">Give this role to</label><select id="ap" name="person_id" required>' . hr_options($acc, '', 'Choose an account…') . '</select></div>'
               . '<button class="btn btn-primary" data-confirm="Give “' . e($role['name']) . '” to this person? They get exactly the ticked permissions.">Add person</button></form>';
        } else {
            echo '<p class="hr-hint">No other sign-in accounts in this organisation. Create one under Team Management (our staff) or on the client\'s page.</p>';
        }
        echo '<div style="margin-top:16px">' . hr_button_form('Delete role', ['action' => 'role_delete', 'role' => $roleId], 'btn btn-sm btn-danger', 'Delete this role? Everyone holding it loses that access.') . '</div></div>';
    }

    echo '<div class="card"><h2>Create a role</h2>' . hr_form_open('', 'class="hr-form"') . '<input type="hidden" name="action" value="role_create"><div class="formgrid">'
       . hr_field('Role name', hr_input('name', '', 'text', ['required' => true, 'maxlength' => 120, 'placeholder' => 'e.g. Team leads']), true)
       . hr_field('Covers', hr_select('scope', $scopes, 'self'), true)
       . hr_field('Start from', hr_select('template', array_map(static fn($t) => $t[0] . ' — ' . $t[2], hr_role_templates()), '', 'Nothing ticked'), false,
                  'A template only pre-ticks permissions. You review them before anyone is added.')
       . hr_field('Description', hr_input('description', '', 'text', ['maxlength' => 255]))
       . '</div><button class="btn btn-primary">Create role</button></form></div>';
}

if ($tab === 'check') {
    $holders = fabo_all('SELECT DISTINCT c.id, c.name, c.email FROM hr_role_members m JOIN hr_roles r ON r.id = m.role_id AND r.org_id = ?
                         JOIN clients c ON c.id = m.person_id ORDER BY c.name', 'i', [hr_org()]);
    echo '<div class="card"><h2>Who can do what</h2><p class="mt0">Everyone with any HR access in this organisation, and exactly what it adds up to across their roles. '
       . 'Anyone not listed has no HR access at all.</p>';
    if (!$holders) {
        echo hr_empty('Nobody but the administrator has HR access.');
    } else {
        foreach ($holders as $h) {
            $perms = hr_person_perms((int) $h['id'], hr_org());
            echo '<details style="margin-bottom:8px"><summary class="btn btn-sm">' . e($h['name']) . ' — ' . count($perms) . ' module(s)</summary><table class="data hr-table" style="margin-top:6px"><tbody>';
            foreach ($perms as $m => $acts) {
                $parts = [];
                foreach ($acts as $act => $sc) { $parts[] = hr_action_labels()[$act] . ' (' . ['self' => 'own', 'team' => 'team', 'org' => 'all'][$sc] . ')'; }
                echo '<tr><td>' . e(hr_modules()[$m]['label']) . '</td><td>' . e(implode(', ', $parts)) . '</td></tr>';
            }
            echo '</tbody></table></details>';
        }
    }
    echo '</div>';
}

if ($tab === 'audit') {
    $entity = (string) ($_GET['entity'] ?? '');
    $q = trim((string) ($_GET['q'] ?? ''));
    $where = ' WHERE org_id = ?';
    $types = 'i';
    $params = [hr_org()];
    if ($entity !== '') { $where .= ' AND entity = ?'; $types .= 's'; $params[] = $entity; }
    if ($q !== '') { $where .= ' AND (summary LIKE ? OR actor_label LIKE ?)'; $types .= 'ss'; $params[] = '%' . $q . '%'; $params[] = '%' . $q . '%'; }
    $total = (int) (fabo_one('SELECT COUNT(*) n FROM hr_audit' . $where, $types, $params)['n'] ?? 0);
    [$off, $pager] = hr_pager($total, 50, 'settings', array_filter(['tab' => 'audit', 'entity' => $entity, 'q' => $q]));
    $rows = fabo_all('SELECT * FROM hr_audit' . $where . ' ORDER BY id DESC LIMIT ' . $off . ', 50', $types, $params);
    $ents = [];
    foreach (fabo_all('SELECT DISTINCT entity FROM hr_audit WHERE org_id = ? ORDER BY entity', 'i', [hr_org()]) as $r) { $ents[$r['entity']] = $r['entity']; }
    echo '<form method="get" class="card hr-filters"><input type="hidden" name="p" value="settings"><input type="hidden" name="tab" value="audit">'
       . '<div class="grp"><label for="aq">Search</label><input id="aq" type="search" name="q" value="' . e($q) . '"></div>'
       . '<div class="grp"><label for="ae">Record type</label><select id="ae" name="entity" data-autosubmit>' . hr_options($ents, $entity, 'All') . '</select></div>'
       . '<button class="btn btn-sm btn-primary">Filter</button></form><div class="card"><h2>Audit log (' . $total . ')</h2>';
    if (!$rows) {
        echo hr_empty('Nothing logged yet.');
    } else {
        echo '<div class="scrollx"><table class="data hr-table stack"><thead><tr><th>When</th><th>Who</th><th>Action</th><th>Record</th><th>What happened</th><th>From</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr><td data-l="When" class="nowrap">' . e(date('d M Y H:i:s', strtotime($r['created_at']))) . '</td><td data-l="Who">' . e($r['actor_label']) . '</td>'
               . '<td data-l="Action">' . e($r['action']) . '</td><td data-l="Record">' . e($r['entity'] . ($r['entity_id'] ? ' #' . $r['entity_id'] : '')) . '</td>'
               . '<td data-l="What">' . e($r['summary']) . '</td><td data-l="From" class="small">' . e($r['ip']) . '</td></tr>';
        }
        echo '</tbody></table></div>' . $pager;
    }
    echo '</div>';
}

if ($tab === 'general') {
    echo '<div class="card"><h2>General settings</h2><p class="mt0">HR rules live in Policies & Rules, grouped by area, each with a preview before it is applied and a full change history.</p><ul class="hr-list">'
       . '<li><a href="' . e(hr_url('sheets', ['tab' => 'leave'])) . '">Leave types, policies and approval flow</a></li>'
       . '<li><a href="' . e(hr_url('sheets', ['tab' => 'attendance'])) . '">Attendance rules</a></li>'
       . '<li><a href="' . e(hr_url('sheets', ['tab' => 'payroll'])) . '">Payroll rules and salary components</a></li>'
       . '<li><a href="' . e(hr_url('sheets', ['tab' => 'employees'])) . '">Employee ID format, notifications and master lists</a></li>'
       . '<li><a href="' . e(hr_url('sheets', ['tab' => 'history'])) . '">Change history</a></li></ul>'
       . '<p class="hr-hint">Email notifications use the portal\'s mail settings' . (function_exists('fabo_mail_configured') && fabo_mail_configured() ? ' (configured).' : ' — none is configured, so HR notifications are in-app only.') . '</p></div>';
}

hr_layout_end();

<?php
/**
 * Admin & Facilities — support staff: security, housekeeping, drivers,
 * pantry, maintenance. Own staff or an agency's (vendors), each with their
 * usual shift and post, ID and police verification.
 */

$editId = (int) ($_GET['edit'] ?? 0);
$f = ['q' => trim((string) ($_GET['q'] ?? '')), 'role' => (string) ($_GET['role'] ?? ''), 'agency' => (string) ($_GET['agency'] ?? ''),
      'verification' => (string) ($_GET['verification'] ?? ''), 'show' => (string) ($_GET['show'] ?? '')];
$fq = array_filter($f, static fn($v) => $v !== '');
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    $flash = inc_action(static function () use ($a) {
        $id = inc_int_or_null($_POST['id'] ?? null);
        if ($a === 'leave') {
            it_exec('UPDATE it_people SET active = 0, left_on = COALESCE(?, CURDATE()) WHERE id = ?', 'si', [inc_date($_POST['left_on'] ?? null), (int) $id]);
            it_log('staff', $id, 'Left.');
            return 'Marked as left.';
        }
        if ($a !== 'save') {
            throw new InvalidArgumentException('Unknown action.');
        }
        $agency = inc_int_or_null($_POST['agency_id'] ?? null);
        $row = [inc_str($_POST['name'] ?? '', 160, true, 'Name'), inc_str($_POST['role'] ?? '', 120) ?: null, $agency && isset(it_vendors()[$agency]) ? $agency : null,
                inc_str($_POST['phone'] ?? '', 40) ?: null, inc_str($_POST['id_proof'] ?? '', 160) ?: null,
                in_array($_POST['verification'] ?? '', ['pending', 'verified', 'not_needed'], true) ? $_POST['verification'] : 'pending',
                inc_str($_POST['shift'] ?? '', 120) ?: null, inc_str($_POST['post'] ?? '', 120) ?: null, inc_str($_POST['emergency'] ?? '', 190) ?: null,
                inc_date($_POST['joined_on'] ?? null), inc_str($_POST['notes'] ?? '', 2000) ?: null];
        if ($id) {
            it_exec('UPDATE it_people SET name = ?, role = ?, agency_id = ?, phone = ?, id_proof = ?, verification = ?, shift = ?, post = ?, emergency = ?, joined_on = ?, notes = ?, active = 1, left_on = NULL WHERE id = ?',
                    'ssissssssssi', array_merge($row, [$id]));
            it_log('staff', $id, 'Details updated.');
        } else {
            $id = it_exec('INSERT INTO it_people (name, role, agency_id, phone, id_proof, verification, shift, post, emergency, joined_on, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                          'ssissssssss', $row);
            it_log('staff', $id, 'Added.');
        }
        inc_redirect(it_url('staff'), ['ok', 'Saved ' . $row[0] . '.']);
    }, it_url('staff', $fq));
}

$w = [$f['show'] === 'left' ? 'p.active = 0' : 'p.active = 1'];
$types = '';
$p = [];
if ($f['q'] !== '') {
    $w[] = '(p.name LIKE ? OR p.phone LIKE ? OR p.post LIKE ?)';
    $like = '%' . $f['q'] . '%';
    $types .= 'sss';
    array_push($p, $like, $like, $like);
}
foreach (['role' => 'p.role', 'verification' => 'p.verification'] as $k => $col) {
    if ($f[$k] !== '') {
        $w[] = $col . ' = ?';
        $types .= 's';
        $p[] = $f[$k];
    }
}
if ($f['agency'] !== '') {
    $w[] = $f['agency'] === 'own' ? 'p.agency_id IS NULL' : 'p.agency_id = ' . (int) $f['agency'];
}
$rows = it_all('SELECT p.*, v.name AS agency_name, d.status AS today FROM it_people p LEFT JOIN it_vendors v ON v.id = p.agency_id
                LEFT JOIN it_duty d ON d.person_id = p.id AND d.day = CURDATE() WHERE ' . implode(' AND ', $w) . ' ORDER BY p.role, p.name', $types, $p);
$verif = ['pending' => 'Verification pending', 'verified' => 'Police verified', 'not_needed' => 'Not needed'];

it_layout_start('Support staff', count($rows) . ' ' . ($f['show'] === 'left' ? 'former' : 'active') . ' · security, housekeeping, drivers, pantry and maintenance',
                '<a class="btn" href="' . e(it_url('duty')) . '">Today\'s duty</a> <a class="btn btn-primary" href="' . e(it_url('staff', ['edit' => 'new'])) . '">+ Add person</a>', $flash);

if ($editId || ($_GET['edit'] ?? '') === 'new' || $flash) {
    $r = $editId ? (it_one('SELECT * FROM it_people WHERE id = ?', 'i', [$editId]) ?? []) : [];
    $r = $flash ? $_POST + $r : $r;
    echo '<section class="card"><h2>' . ($editId ? 'Edit ' . e($r['name'] ?? '') : 'Add a person') . '</h2>' . inc_form_open() . '<input type="hidden" name="action" value="save">'
       . ($editId ? '<input type="hidden" name="id" value="' . $editId . '">' : '')
       . '<div class="inc-row">' . inc_field('Name', '<input type="text" name="name" maxlength="160" required value="' . e($r['name'] ?? '') . '">', '', true, 'grow')
       . inc_field('Role', inc_select('role', it_list('staff_role'), $r['role'] ?? '', 'Choose…'))
       . inc_field('Agency', inc_select('agency_id', it_vendors(true), $r['agency_id'] ?? '', 'Our own staff'), 'Add agencies under Vendors & agencies.') . '</div>'
       . '<div class="inc-row">' . inc_field('Phone', '<input type="text" name="phone" maxlength="40" value="' . e((string) ($r['phone'] ?? '')) . '">')
       . inc_field('Usual shift', inc_select('shift', it_list('shift'), $r['shift'] ?? '', 'Choose…'))
       . inc_field('Usual post / area', inc_select('post', it_list('area') + it_list('location'), $r['post'] ?? '', 'Choose…'))
       . inc_field('Joined on', '<input type="date" name="joined_on" value="' . e((string) ($r['joined_on'] ?? '')) . '">') . '</div>'
       . '<div class="inc-row">' . inc_field('ID proof (type and number)', '<input type="text" name="id_proof" maxlength="160" value="' . e((string) ($r['id_proof'] ?? '')) . '" placeholder="e.g. Aadhaar XXXX-1234">')
       . inc_field('Police verification', inc_select('verification', $verif, $r['verification'] ?? 'pending'))
       . inc_field('Emergency contact', '<input type="text" name="emergency" maxlength="190" value="' . e((string) ($r['emergency'] ?? '')) . '">', '', false, 'grow') . '</div>'
       . inc_field('Notes', inc_textarea('notes', (string) ($r['notes'] ?? ''), 2))
       . '<button class="btn btn-primary">Save</button> <a class="btn" href="' . e(it_url('staff')) . '">Cancel</a></form>';
    if ($editId && (int) ($r['active'] ?? 1) === 1) {
        echo '<hr>' . inc_form_open('class="inc-row"') . '<input type="hidden" name="action" value="leave"><input type="hidden" name="id" value="' . $editId . '">'
           . inc_field('Last day', '<input type="date" name="left_on" value="' . e(date('Y-m-d')) . '">') . '<button class="btn btn-reject" data-confirm="Mark this person as left?">Mark as left</button></form>';
    }
    echo '</section>';
}

echo it_filter_bar('staff', $f, ['role' => ['Role', it_list('staff_role', false)], 'agency' => ['Agency', ['own' => 'Our own staff'] + it_vendors()],
                                 'verification' => ['Verification', $verif], 'show' => ['Show', ['left' => 'Former staff']]]);

if (!$rows) {
    echo inc_empty($fq ? 'Nobody matches.' : 'No support staff yet.', $fq ? 'Try clearing some filters.' : 'Add your guards, housekeeping, drivers and pantry staff with + Add person.');
} else {
    $tone = ['present' => 'green', 'absent' => 'red', 'leave' => 'amber', 'replaced' => 'blue', 'off' => ''];
    echo '<div class="scrollx"><table class="data stack inc-table"><thead><tr><th>Name</th><th>Role</th><th>Agency</th><th>Shift · post</th><th>Phone</th><th>Verification</th><th>Today</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr><td data-l="Name"><a href="' . e(it_url('staff', ['edit' => $r['id']])) . '"><strong>' . e($r['name']) . '</strong></a>' . ($r['joined_on'] ? '<div class="muted small">since ' . e(inc_fmt_date($r['joined_on'])) . '</div>' : '') . '</td>'
           . '<td data-l="Role">' . e($r['role'] ?: '—') . '</td><td data-l="Agency">' . e($r['agency_name'] ?: 'Own staff') . '</td>'
           . '<td data-l="Shift · post">' . e($r['shift'] ?: '—') . '<div class="muted small">' . e((string) $r['post']) . '</div></td>'
           . '<td data-l="Phone">' . ($r['phone'] ? '<a href="tel:' . e($r['phone']) . '">' . e($r['phone']) . '</a>' : '—') . '</td>'
           . '<td data-l="Verification">' . inc_badge($verif[$r['verification']], ['verified' => 'green', 'pending' => 'amber', 'not_needed' => ''][$r['verification']]) . '</td>'
           . '<td data-l="Today">' . ($r['today'] ? inc_badge(ucfirst($r['today']), $tone[$r['today']]) : '<span class="muted small">not marked</span>') . '</td></tr>';
    }
    echo '</tbody></table></div>';
}

it_layout_end();

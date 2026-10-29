<?php
/**
 * Admin & Facilities — the visitor register. Check a visitor in at the gate
 * (a pass number is given, and the person they came to meet is emailed),
 * see who is inside now, check them out, and look back by day.
 */

$day = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['day'] ?? '')) ? (string) $_GET['day'] : date('Y-m-d');
$q = trim((string) ($_GET['q'] ?? ''));
$self = it_url('visitors', array_filter(['day' => $day !== date('Y-m-d') ? $day : null, 'q' => $q ?: null]));
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    $flash = inc_action(static function () use ($a) {
        if ($a === 'out') {
            $n = it_exec('UPDATE it_visitors SET out_at = NOW() WHERE id = ? AND out_at IS NULL', 'i', [(int) ($_POST['id'] ?? 0)]);
            return $n ? 'Checked out.' : 'Already checked out.';
        }
        if ($a !== 'in') {
            throw new InvalidArgumentException('Unknown action.');
        }
        $hostId = inc_int_or_null($_POST['host_id'] ?? null);
        $hostId = $hostId && isset(it_staff()[$hostId]) ? $hostId : null;
        $host = $hostId ? it_staff()[$hostId]['name'] : inc_str($_POST['host_name'] ?? '', 160);
        $name = inc_str($_POST['name'] ?? '', 160, true, 'Visitor name');
        $id = it_exec('INSERT INTO it_visitors (name, phone, company, purpose, host_name, host_id, id_proof, vehicle, people) VALUES (?,?,?,?,?,?,?,?,?)', 'sssssissi',
                      [$name, inc_str($_POST['phone'] ?? '', 40) ?: null, inc_str($_POST['company'] ?? '', 160) ?: null, inc_str($_POST['purpose'] ?? '', 255) ?: null,
                       $host ?: null, $hostId, inc_str($_POST['id_proof'] ?? '', 160) ?: null, inc_str($_POST['vehicle'] ?? '', 40) ?: null, max(1, min(200, (int) ($_POST['people'] ?? 1)))]);
        $pass = 'V-' . date('ymd') . '-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT);
        it_exec('UPDATE it_visitors SET pass_no = ? WHERE id = ?', 'si', [$pass, $id]);
        $told = '';
        if ($hostId && ($em = it_staff()[$hostId]['email'] ?? '') !== '') {
            it_mail($em, 'Your visitor has arrived: ' . $name, 'Hello ' . $host . ",\n\n" . $name . (trim((string) ($_POST['company'] ?? '')) !== '' ? ' (' . trim((string) $_POST['company']) . ')' : '')
                  . " is at reception to meet you" . (trim((string) ($_POST['purpose'] ?? '')) !== '' ? ' — ' . trim((string) $_POST['purpose']) : '') . ".\nVisitor pass: " . $pass . "\n\nRegards,\n" . it_cfg('support'));
            $told = ' ' . $host . ' has been emailed.';
        }
        return 'Checked in ' . $name . ' — pass ' . $pass . '.' . $told;
    }, it_url('visitors'));
}

$inside = it_all('SELECT * FROM it_visitors WHERE out_at IS NULL ORDER BY in_at');
$w = ['DATE(in_at) = ?'];
$types = 's';
$p = [$day];
if ($q !== '') {
    $w = ['(name LIKE ? OR phone LIKE ? OR company LIKE ? OR host_name LIKE ? OR pass_no LIKE ?)'];
    $like = '%' . $q . '%';
    $types = 'sssss';
    $p = [$like, $like, $like, $like, $like];
}
$log = it_all('SELECT * FROM it_visitors WHERE ' . implode(' AND ', $w) . ' ORDER BY in_at DESC LIMIT 500', $types, $p);

it_layout_start('Visitors', count($inside) . ' inside now · ' . (int) (it_one('SELECT COUNT(*) n FROM it_visitors WHERE DATE(in_at) = CURDATE()')['n'] ?? 0) . ' today', '', $flash);

echo '<div class="inc-grid2 wide-left"><section class="card"><h2>Check a visitor in</h2>' . inc_form_open() . '<input type="hidden" name="action" value="in">'
   . '<div class="inc-row">' . inc_field('Visitor name', '<input type="text" name="name" maxlength="160" required>', '', true, 'grow')
   . inc_field('Phone', '<input type="text" name="phone" maxlength="40">') . inc_field('People', '<input type="number" name="people" min="1" max="200" value="1" class="inc-w">') . '</div>'
   . '<div class="inc-row">' . inc_field('Company / from', '<input type="text" name="company" maxlength="160">', '', false, 'grow')
   . inc_field('Purpose', '<input type="text" name="purpose" maxlength="255" placeholder="Meeting, delivery, interview…">', '', false, 'grow') . '</div>'
   . '<div class="inc-row">' . inc_field('To meet (team member)', inc_select('host_id', array_map(static fn($s) => $s['name'], it_staff()), '', 'Choose…'), 'They are emailed that their visitor has arrived.')
   . inc_field('…or someone else', '<input type="text" name="host_name" maxlength="160" placeholder="Name / startup">') . '</div>'
   . '<div class="inc-row">' . inc_field('ID shown', '<input type="text" name="id_proof" maxlength="160" placeholder="e.g. Driving licence">')
   . inc_field('Vehicle number', '<input type="text" name="vehicle" maxlength="40">') . '</div>'
   . '<button class="btn btn-primary">Check in</button></form></section>';

echo '<section class="card"><h2>Inside now</h2>';
if (!$inside) {
    echo '<p class="inc-allclear">Nobody is checked in.</p>';
} else {
    echo '<ul class="it-tasks">';
    foreach ($inside as $v) {
        echo '<li><div><strong>' . e($v['name']) . '</strong>' . ((int) $v['people'] > 1 ? ' +' . ((int) $v['people'] - 1) : '') . '<div class="muted small">' . e($v['pass_no'] . ' · in ' . date('H:i', strtotime($v['in_at'])))
           . ($v['host_name'] ? ' · to meet ' . e($v['host_name']) : '') . '</div></div><div class="it-taskacts">' . inc_button('Check out', ['action' => 'out', 'id' => $v['id']], 'btn btn-sm btn-primary') . '</div></li>';
    }
    echo '</ul>';
}
echo '</section></div>';

echo '<section class="card"><div class="inc-cardhead"><h2>' . ($q !== '' ? 'Search: “' . e($q) . '”' : 'Register for ' . e(date('d M Y', strtotime($day)))) . '</h2></div>'
   . '<form method="get" class="inc-row"><input type="hidden" name="p" value="visitors"><input type="date" name="day" value="' . e($day) . '" data-autosubmit>'
   . '<input type="search" name="q" value="' . e($q) . '" placeholder="Search name, phone, company, host or pass"><button class="btn">Search</button></form>';
if (!$log) {
    echo '<p class="muted small">No visitors.</p>';
} else {
    echo '<div class="scrollx"><table class="data stack"><thead><tr><th>Pass</th><th>Visitor</th><th>From</th><th>To meet · purpose</th><th>In</th><th>Out</th><th>ID · vehicle</th></tr></thead><tbody>';
    foreach ($log as $v) {
        echo '<tr><td data-l="Pass">' . e((string) $v['pass_no']) . '</td><td data-l="Visitor"><strong>' . e($v['name']) . '</strong>' . ((int) $v['people'] > 1 ? ' +' . ((int) $v['people'] - 1) : '') . '<div class="muted small">' . e((string) $v['phone']) . '</div></td>'
           . '<td data-l="From">' . e($v['company'] ?: '—') . '</td><td data-l="To meet · purpose">' . e($v['host_name'] ?: '—') . '<div class="muted small">' . e((string) $v['purpose']) . '</div></td>'
           . '<td data-l="In" class="nowrap">' . e(date('d M, H:i', strtotime($v['in_at']))) . '</td><td data-l="Out" class="nowrap">' . ($v['out_at'] ? e(date('H:i', strtotime($v['out_at']))) : inc_badge('Inside', 'amber')) . '</td>'
           . '<td data-l="ID · vehicle">' . e(trim(($v['id_proof'] ?? '') . ' · ' . ($v['vehicle'] ?? ''), ' ·') ?: '—') . '</td></tr>';
    }
    echo '</tbody></table></div>';
}
echo '</section>';

it_layout_end();

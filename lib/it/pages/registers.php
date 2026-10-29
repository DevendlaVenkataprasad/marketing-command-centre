<?php
/**
 * Admin & Facilities — the security and front-desk registers: material going
 * out (returnable gate passes) and coming in, keys issued, incidents, and
 * courier in and out. An entry stays open until it is closed — the material
 * comes back, the key is returned, the incident is dealt with, the courier is
 * handed over or sent — and the closing note is kept.
 */

$types = ['material_out' => ['Gate pass — material out', 'GP', 'Item(s) going out', 'Taken by', 'Returned'],
          'material_in' => ['Material in', 'MI', 'Item(s) received', 'Brought by / from', 'Received and stored'],
          'key' => ['Keys', 'KY', 'Key (room / cabinet)', 'Issued to', 'Key returned'],
          'incident' => ['Incidents', 'IN', 'What happened', 'Reported by', 'Resolved'],
          'courier_in' => ['Courier in', 'CI', 'Courier / parcel', 'For (recipient)', 'Handed over'],
          'courier_out' => ['Courier out', 'CO', 'Courier / parcel', 'Sent by (sender)', 'Dispatched']];
$type = isset($types[$_GET['type'] ?? '']) ? (string) $_GET['type'] : 'material_out';
$show = ($_GET['show'] ?? '') === 'closed' ? 'closed' : 'open';
[$label, $prefix, $titleL, $personL, $closeL] = $types[$type];
$self = it_url('registers', ['type' => $type] + ($show === 'closed' ? ['show' => 'closed'] : []));
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    $flash = inc_action(static function () use ($a, $type, $prefix) {
        if ($a === 'close') {
            $n = it_exec("UPDATE it_registers SET status = 'closed', closed_at = NOW(), closed_note = ? WHERE id = ? AND status = 'open'", 'si',
                         [mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 500) ?: null, (int) ($_POST['id'] ?? 0)]);
            return $n ? 'Closed.' : 'Already closed.';
        }
        if ($a !== 'add') {
            throw new InvalidArgumentException('Unknown action.');
        }
        $id = it_exec('INSERT INTO it_registers (type, title, person, details, due_back) VALUES (?,?,?,?,?)', 'sssss',
                      [$type, inc_str($_POST['title'] ?? '', 200, true, 'Description'), inc_str($_POST['person'] ?? '', 160) ?: null,
                       inc_str($_POST['details'] ?? '', 4000) ?: null, inc_date($_POST['due_back'] ?? null)]);
        $ref = $prefix . '-' . date('ymd') . '-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT);
        it_exec('UPDATE it_registers SET ref_no = ? WHERE id = ?', 'si', [$ref, $id]);
        it_log('register', $id, $ref . ' entered.');
        return 'Entered as ' . $ref . '.';
    }, $self);
}

$counts = [];
foreach (it_all("SELECT type, COUNT(*) n FROM it_registers WHERE status = 'open' GROUP BY type") as $r) {
    $counts[$r['type']] = (int) $r['n'];
}
$rows = it_all('SELECT * FROM it_registers WHERE type = ? AND status = ? ORDER BY ' . ($show === 'open' ? 'due_back IS NULL, due_back, opened_at' : 'closed_at DESC') . ' LIMIT 500', 'ss', [$type, $show]);

it_layout_start('Registers', 'Gate passes, material, keys, incidents and courier — each open until it is closed', '', $flash);
echo '<nav class="inc-apptabs">';
foreach ($types as $k => $t) {
    echo '<a href="' . e(it_url('registers', ['type' => $k])) . '"' . ($k === $type ? ' class="on"' : '') . '>' . e($t[0]) . (!empty($counts[$k]) ? ' <span class="inc-fcount">' . $counts[$k] . '</span>' : '') . '</a>';
}
echo '</nav>';

echo '<div class="inc-grid2 wide-left"><section class="card"><div class="inc-cardhead"><h2>' . e($label) . ' — ' . ($show === 'open' ? 'open' : 'closed') . '</h2>'
   . '<a class="small" href="' . e(it_url('registers', ['type' => $type] + ($show === 'open' ? ['show' => 'closed'] : []))) . '">' . ($show === 'open' ? 'Closed entries →' : '← Open entries') . '</a></div>';
if (!$rows) {
    echo '<p class="muted small">' . ($show === 'open' ? 'Nothing open.' : 'Nothing closed yet.') . '</p>';
} else {
    echo '<ul class="it-tasks">';
    foreach ($rows as $r) {
        $late = $r['status'] === 'open' && $r['due_back'] && $r['due_back'] < date('Y-m-d');
        echo '<li><div><strong>' . e($r['title']) . '</strong> <span class="muted small">' . e((string) $r['ref_no']) . '</span>' . ($late ? ' ' . inc_badge('Overdue', 'red') : '')
           . '<div class="muted small">' . e($personL) . ': ' . e($r['person'] ?: '—') . ' · ' . e(inc_fmt_datetime($r['opened_at'])) . ($r['due_back'] ? ' · back by ' . e(inc_fmt_date($r['due_back'])) : '')
           . ($r['closed_at'] ? ' · ' . e($closeL) . ' ' . e(inc_fmt_datetime($r['closed_at'])) . ($r['closed_note'] ? ' — ' . e($r['closed_note']) : '') : '') . '</div>'
           . ($r['details'] ? '<div class="small">' . nl2br(e($r['details'])) . '</div>' : '') . '</div>';
        if ($r['status'] === 'open') {
            echo '<div class="it-taskacts">' . inc_form_open('class="inc-inline"') . '<input type="hidden" name="action" value="close"><input type="hidden" name="id" value="' . (int) $r['id'] . '">'
               . '<input type="text" name="note" maxlength="500" placeholder="Note (optional)"> <button class="btn btn-sm btn-primary">' . e($closeL) . '</button></form></div>';
        }
        echo '</li>';
    }
    echo '</ul>';
}
echo '</section><section class="card"><h2>New entry</h2>' . inc_form_open() . '<input type="hidden" name="action" value="add">'
   . inc_field($titleL, '<input type="text" name="title" maxlength="200" required>', '', true)
   . inc_field($personL, '<input type="text" name="person" maxlength="160">')
   . (in_array($type, ['material_out', 'key'], true) ? inc_field('Due back', '<input type="date" name="due_back">', 'Shown as overdue after this date.') : '')
   . inc_field($type === 'incident' ? 'Details and action taken' : 'Details', inc_textarea('details', '', 3))
   . '<button class="btn btn-primary">Enter</button></form></section></div>';

it_layout_end();

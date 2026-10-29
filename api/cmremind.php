<?php
/**
 * Reminds the committees about the ticked events still waiting on them —
 * the Events grid's "Remind committee".
 *
 *   POST sheet=events, ids[]=…, note=<optional>
 *
 * Each event must be one the viewer can open. fabo_cm_remind() skips events
 * with no committee or already decided, and sends every member who has not
 * yet voted ONE email listing all of their pending events.
 */

require_once __DIR__ . '/../lib/endpoint.php';
require_once __DIR__ . '/../lib/committees.php';

fabo_csrf_check();
[$viewer, $key, $sheet, $perm] = fabo_endpoint('edit');
if ($key !== 'events') {
    fabo_json_fail('Committees decide on events only.', 400);
}
$ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])))));
if (!$ids) {
    fabo_json_fail('Tick at least one programme.', 400);
}
if (count($ids) > 500) {
    fabo_json_fail('Remind about at most 500 programmes at a time.', 400);
}
$note = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 1000);

$events  = [];
$missing = 0;
foreach ($ids as $id) {
    $row = fabo_load_row($viewer, $key, $id);
    if ($row) {
        $events[] = $row;
    } else {
        $missing++;
    }
}
$r = fabo_cm_remind($viewer, $events, $note);
foreach ($r['reminded'] as $eid) {
    fabo_audit($viewer, $sheet['table'], (int) $eid, '(committee reminder)', null,
               'reminder sent' . ($note !== '' ? ': ' . $note : ''));
}

if (!$r['events']) {
    $msg = 'None of the selected programmes is waiting on a committee, so no reminder was sent.';
} elseif (!$r['members']) {
    $msg = 'Every member has already voted on the selected programmes — nobody to remind.';
} else {
    $msg = $r['members'] . ' committee member' . ($r['members'] === 1 ? '' : 's') . ' reminded about '
         . $r['events'] . ' pending programme' . ($r['events'] === 1 ? '' : 's') . ' — one email each ('
         . $r['mailed'] . ' sent or queued' . ($r['mailed'] < $r['members'] ? ', ' . ($r['members'] - $r['mailed']) . ' without an email address' : '') . ').';
}
if ($r['skipped']) {
    $msg .= ' Skipped: ' . implode('; ', array_slice($r['skipped'], 0, 5)) . (count($r['skipped']) > 5 ? '; …' : '') . '.';
}
if ($missing) {
    $msg .= ' ' . $missing . ' not found.';
}
fabo_json(['ok' => true, 'message' => $msg] + $r);

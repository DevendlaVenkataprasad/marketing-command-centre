<?php
/**
 * A row's checklist: read it, tick an item, write the description the eye icon
 * shows — and (admin) edit the checklist itself: add, rename, reorder, mark
 * mandatory, hide or delete an item.
 */

require_once __DIR__ . '/../lib/endpoint.php';
require_once __DIR__ . '/../lib/checklist.php';

$need = ($_SERVER['REQUEST_METHOD'] === 'POST') ? 'edit' : 'view';
if ($need === 'edit') {
    fabo_csrf_check();
}
[$viewer, $key, $sheet, $perm] = fabo_endpoint($need);

if (!fabo_has_checklist($key)) {
    fabo_json_fail('This sheet has no checklist.', 400);
}

$rowId = (int) ($_REQUEST['id'] ?? 0);
if (!fabo_load_row($viewer, $key, $rowId)) {
    fabo_json_fail('Row not found.', 404);
}

$who     = $viewer['is_admin'] ? 'Admin' : (string) $viewer['client']['name'];
$isAdmin = (bool) $viewer['is_admin'];
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    // ---- editing the checklist itself is admin-only
    $templateActions = ['add_item', 'rename_item', 'delete_item', 'toggle_required',
                        'toggle_active', 'move_up', 'move_down'];
    if (in_array($action, $templateActions, true)) {
        if (!$isAdmin) {
            fabo_json_fail('Only the admin can change the checklist itself.', 403);
        }
        $tid  = (int) ($_POST['template_id'] ?? 0);
        $text = (string) ($_POST['item'] ?? '');
        switch ($action) {
            case 'add_item':        [$ok, $message] = fabo_checklist_add($key, $text, !empty($_POST['required'])); break;
            case 'rename_item':     [$ok, $message] = fabo_checklist_rename($key, $tid, $text); break;
            case 'delete_item':     [$ok, $message] = fabo_checklist_delete($key, $tid); break;
            case 'toggle_required': [$ok, $message] = fabo_checklist_flag($key, $tid, 'required'); break;
            case 'toggle_active':   [$ok, $message] = fabo_checklist_flag($key, $tid, 'active'); break;
            case 'move_up':         [$ok, $message] = fabo_checklist_move($key, $tid, 'up'); break;
            case 'move_down':       [$ok, $message] = fabo_checklist_move($key, $tid, 'down'); break;
        }
        if (!$ok) {
            fabo_json_fail($message, 400);
        }
    } else {
        // ---- ticking and describing: anyone who may edit the sheet
        $item = (string) ($_POST['item'] ?? '');
        if (!in_array($item, fabo_checklist_names($key), true)) {
            fabo_json_fail('That is not an item on this checklist.', 400);
        }
        if ($action === 'toggle') {
            $done = ($_POST['done'] ?? '') === '1';
            fabo_checklist_set($key, $rowId, $item, $done, $who);
            fabo_audit($viewer, $sheet['table'], $rowId, '(checklist)', null,
                       ($done ? 'done: ' : 'cleared: ') . $item);
        } elseif ($action === 'note') {
            $note = (string) ($_POST['note'] ?? '');
            fabo_checklist_note($key, $rowId, $item, $note, $who);
            fabo_audit($viewer, $sheet['table'], $rowId, '(checklist note)', null,
                       $item . ($note === '' ? ' — cleared' : ': ' . mb_substr($note, 0, 120)));
        } else {
            fabo_json_fail('Unknown action.', 400);
        }
    }
}

/* ------------------------------------------------------------- reply */

$template = fabo_checklist_template($key, $isAdmin);   // admin also sees hidden items
$state    = fabo_checklist_state($key, $rowId);
$items    = [];
$done = 0; $must = 0; $mustDone = 0;

foreach ($template as $t) {
    $item = (string) $t['item'];
    $st   = $state[$item] ?? ['done' => false, 'note' => '', 'by' => '', 'when' => ''];
    $req  = (int) $t['required'] === 1;
    $on   = (int) $t['active'] === 1;
    if ($on) {
        if ($st['done']) { $done++; }
        if ($req) {
            $must++;
            if ($st['done']) { $mustDone++; }
        }
    }
    $items[] = [
        'template_id' => (int) $t['id'],
        'item'        => $item,
        'required'    => $req,
        'active'      => $on,
        'done'        => (bool) $st['done'],
        'note'        => $st['note'],
        'has_note'    => $st['note'] !== '',
        'by'          => $st['by'],
        'when'        => $st['when'],
    ];
}
$total = count(array_filter($items, static fn($i) => $i['active']));

$fresh = fabo_refresh_row($key, fabo_compute($key,
            fabo_one('SELECT * FROM `' . $sheet['table'] . '` WHERE id = ?', 'i', [$rowId])));

fabo_json([
    'ok'        => true,
    'message'   => $message,
    'items'     => $items,
    'done'      => $done,
    'total'     => $total,
    'must'      => $must,
    'must_done' => $mustDone,
    'can_edit'  => $perm === 'edit',
    'is_admin'  => $isAdmin,
    'sheet'     => $sheet['label'],
    'row'       => fabo_row_payload($sheet, $fresh),
]);

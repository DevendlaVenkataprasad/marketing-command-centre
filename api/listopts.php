<?php
/**
 * Dropdown options, managed from inside the sheet.
 *   GET  ?sheet=key&list=Name        -> the options with ids, order and state
 *   POST action=add|rename|remove|toggle|up|down
 * Returns the refreshed option list every time, so the grid can repaint.
 *
 * Options belong to an organisation, not to the portal. We keep the shared
 * set that every client starts from; a client who changes anything gets
 * their own copy of that list from then on, and neither can see the other's.
 * That is what makes add and remove safe to offer a client at all.
 *
 * Who may edit: anyone who may edit the sheet, their own organisation's copy
 * only. A team member included — they do the work and so they are the ones
 * who find an option missing. Copy-on-write is what keeps that safe.
 */

require_once __DIR__ . '/../lib/endpoint.php';
require_once __DIR__ . '/../lib/lists.php';
require_once __DIR__ . '/../lib/deletions.php';   // fabo_may_delete(): head of an org

// A sheet key is required now: it is what proves the caller may touch this
// list at all. Before this, the endpoint asked for nothing and answered
// anyone — a signed-in client could rewrite every client's dropdowns.
[$viewer, $key, $sheet, $perm] = fabo_endpoint('edit');

$owner = fabo_customiser($viewer);

/**
 * Exactly the rule the page rendered the controls from — deliberately the
 * same function, not a second copy of the same idea. The two disagreeing is
 * how you ship a dropdown that offers "Add option" and then refuses to add
 * one, which this portal has done once already.
 */
function fabo_may_edit_options(array $viewer, string $perm): bool
{
    return fabo_may_customise($viewer, $perm);
}

$list = trim((string) ($_REQUEST['list'] ?? ''));
if ($list === '') {
    fabo_json_fail('Which list?', 400);
}
// A live list is built from the data, not stored on the Lists sheet.
if (fabo_is_dynamic_list($list)) {
    fabo_json_fail('That dropdown is built from your clients — manage them on the Clients page.', 400);
}
// And it has to be a list this sheet actually uses, so the sheet permission
// checked above means something.
$used = false;
foreach ($sheet['columns'] as $c) {
    if (($c[2] ?? '') === 'select' && ($c[3]['list'] ?? '') === $list) {
        $used = true;
        break;
    }
}
if (!$used) {
    fabo_json_fail('That dropdown is not on this sheet.', 403);
}

/** The options as this organisation holds them. */
function fabo_listopts(string $list, int $owner): array
{
    return fabo_all('SELECT id, value, sort_order, active FROM lists
                     WHERE list_name = ? AND client_id = ?
                     ORDER BY sort_order, value', 'si', [$list, $owner]);
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    fabo_csrf_check();
    if (!fabo_may_edit_options($viewer, $perm)) {
        fabo_json_fail('You need edit access to this sheet to change what the '
                     . 'dropdown offers.', 403);
    }
    /* Scope 0 is the shared set every client starts from. Our own staff also
       resolve to it, so without this one staff member's edit changed the
       dropdown for every client that had not made a copy of its own. */
    if ($owner === 0 && empty($viewer['is_admin'])) {
        fabo_json_fail('Only the administrator changes the shared dropdown options.', 403);
    }
    $action = (string) ($_POST['action'] ?? '');
    $id     = (int) ($_POST['id'] ?? 0);
    $value  = trim((string) ($_POST['value'] ?? ''));

    // First change takes a copy of the shared defaults, so the organisation
    // starts from what it was already seeing rather than from nothing.
    fabo_list_adopt($owner, $list);

    switch ($action) {
        case 'add':
            if ($value === '') {
                fabo_json_fail('Type a value to add.', 400);
            }
            if (fabo_one('SELECT id FROM lists WHERE list_name = ? AND value = ? AND client_id = ?',
                         'ssi', [$list, $value, $owner])) {
                fabo_json_fail("“{$value}” is already an option.", 400);
            }
            $max = (int) (fabo_one('SELECT MAX(sort_order) AS m FROM lists
                                    WHERE list_name = ? AND client_id = ?',
                                   'si', [$list, $owner])['m'] ?? 0);
            fabo_exec('INSERT INTO lists (list_name, value, sort_order, active, client_id)
                       VALUES (?,?,?,1,?)', 'ssii',
                      [$list, mb_substr($value, 0, 255), $max + 10, $owner]);
            $message = "Added “{$value}”.";
            break;

        case 'rename':
            if ($value === '' || !$id) {
                fabo_json_fail('Nothing to rename.', 400);
            }
            fabo_exec('UPDATE lists SET value = ? WHERE id = ? AND list_name = ? AND client_id = ?',
                      'sisi', [mb_substr($value, 0, 255), $id, $list, $owner]);
            $message = 'Renamed. Rows already holding the old text keep it.';
            break;

        case 'remove':
            fabo_exec('DELETE FROM lists WHERE id = ? AND list_name = ? AND client_id = ?',
                      'isi', [$id, $list, $owner]);
            $message = 'Removed from the dropdown. No data row was changed.';
            break;

        case 'toggle':
            fabo_exec('UPDATE lists SET active = 1 - active
                       WHERE id = ? AND list_name = ? AND client_id = ?',
                      'isi', [$id, $list, $owner]);
            $message = 'Updated.';
            break;

        case 'up':
        case 'down':
            $rows = fabo_listopts($list, $owner);
            foreach ($rows as $i => $r) {
                if ((int) $r['id'] !== $id) { continue; }
                $j = $action === 'up' ? $i - 1 : $i + 1;
                if (!isset($rows[$j])) { break; }
                foreach ($rows as $k => $rr) {   // normalise 10, 20, 30… then swap
                    fabo_exec('UPDATE lists SET sort_order = ? WHERE id = ?', 'ii',
                              [($k + 1) * 10, (int) $rr['id']]);
                }
                fabo_exec('UPDATE lists SET sort_order = ? WHERE id = ?', 'ii', [($j + 1) * 10, $id]);
                fabo_exec('UPDATE lists SET sort_order = ? WHERE id = ?', 'ii',
                          [($i + 1) * 10, (int) $rows[$j]['id']]);
                break;
            }
            break;

        default:
            fabo_json_fail('Unknown action.', 400);
    }
}

/*
 * What to show. An organisation that has never changed this list has no
 * rows of its own, so the shared defaults are shown — read-only until they
 * change something, at which point the copy above makes them theirs.
 */
$own  = fabo_list_is_own($owner, $list);
$opts = $own ? fabo_listopts($list, $owner) : fabo_listopts($list, 0);

fabo_json([
    'ok'       => true,
    'list'     => $list,
    'message'  => $message,
    'can_edit' => fabo_may_edit_options($viewer, $perm),
    // Whose options these are, said plainly — see fabo_list_scope_note().
    'scope'    => fabo_list_scope_note($viewer),
    // True once this organisation has its own copy; the panel says so, so
    // nobody wonders why their change did not reach anyone else.
    'own_copy' => $own && $owner > 0,
    'options'  => array_map(static fn($r) => [
        'id' => (int) $r['id'], 'value' => $r['value'], 'active' => (int) $r['active'],
    ], $opts),
    // What the grids should offer right now: active values, in order.
    'active'   => array_values(array_map(static fn($r) => $r['value'],
                      array_filter($opts, static fn($r) => (int) $r['active'] === 1))),
]);

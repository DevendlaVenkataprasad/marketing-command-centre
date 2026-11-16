<?php
/**
 * Incubation Management — adding and editing stages, from the board itself.
 *
 * The only place stages are managed: each board column has a ⋯ menu with
 * this editor, and the board ends in an "Add stage" column. The application
 * pipeline is edited on Applications, the incubation lifecycle on
 * Incubations. (Settings no longer carries a copy.)
 */

/** Handles a stage POST from a board page. Returns the flash text, or null when $a is not a stage action. */
function inc_stage_post(string $a): ?string
{
    if (inc_kind() === 'eo') {
        throw new InvalidArgumentException('The stages belong to the main pipeline: change them in Incubation Management.');
    }
    switch ($a) {
        case 'stage_save':
            $sid = inc_stage_save((string) ($_POST['pipeline'] ?? ''), inc_int_or_null($_POST['id'] ?? null), $_POST);
            if (isset($_POST['gem_form'])) {
                inc_stage_gems_save($sid, (array) ($_POST['gem_ids'] ?? []));
            }
            return empty($_POST['id']) ? 'Stage added at the end of the board. Use ← → in its ⋯ menu to move it.' : 'Stage saved.';
        case 'stage_move':
            inc_stage_move((int) ($_POST['id'] ?? 0), (int) ($_POST['dir'] ?? 0));
            return 'Order changed.';
        case 'stage_delete':
            return inc_stage_delete((int) ($_POST['id'] ?? 0));
        case 'check_save':
            inc_checklist_item_save((int) ($_POST['stage_id'] ?? 0), inc_int_or_null($_POST['id'] ?? null), (string) ($_POST['text'] ?? ''), !isset($_POST['remove']));
            return 'Checklist saved.';
    }
    return null;
}

/** The ⋯ menu on a board column: order, and every setting of the stage. */
function inc_stage_menu_html(array $s, string $pipe): string
{
    if (!inc_can('configure') || inc_kind() === 'eo') {
        return '';
    }
    return '<details class="kb-menu"><summary title="Edit this stage" aria-label="Edit stage ' . e($s['name']) . '">⋯</summary>'
         . '<div class="kb-dialog" role="dialog" aria-label="Edit stage"><h3>Stage: ' . e($s['name']) . '</h3>'
         . '<div class="inc-row">' . inc_button('← Move left', ['action' => 'stage_move', 'id' => $s['id'], 'dir' => -1], 'btn btn-sm')
         . inc_button('Move right →', ['action' => 'stage_move', 'id' => $s['id'], 'dir' => 1], 'btn btn-sm') . '</div>'
         . inc_stage_editor_html($s, $pipe)
         . '<div class="kb-danger">' . inc_button('Delete this stage', ['action' => 'stage_delete', 'id' => $s['id']], 'btn btn-sm btn-reject',
                                                     'Delete the stage "' . $s['name'] . '"? Only an empty stage can be deleted.')
         . '<span class="muted small">Only when nothing is in it.</span></div></div></details>';
}

/** The edit form for one stage (and, on the lifecycle, its checklist). */
function inc_stage_editor_html(array $s, string $pipe): string
{
    $req = inc_stage_requires($s);
    $next = inc_stage_allowed_next($s);
    $h = inc_form_open() . '<input type="hidden" name="action" value="stage_save"><input type="hidden" name="pipeline" value="' . e($pipe) . '"><input type="hidden" name="id" value="' . (int) $s['id'] . '">'
       . inc_field('Name', '<input type="text" name="name" value="' . e($s['name']) . '" maxlength="80" required>')
       . inc_field('Description', '<input type="text" name="description" value="' . e((string) $s['description']) . '" maxlength="255">')
       . '<div class="inc-row"><label class="check"><input type="checkbox" name="active" value="1"' . ((int) $s['active'] === 1 ? ' checked' : '') . '> Active (shown on the board)</label>'
       . '<label class="check"><input type="checkbox" name="is_terminal" value="1"' . ((int) $s['is_terminal'] === 1 ? ' checked' : '') . '> Final stage' . ($pipe === 'lifecycle' ? ' (closes the incubation)' : '') . '</label>'
       . '<label class="check"><input type="checkbox" name="notify" value="1"' . ((int) $s['notify'] === 1 ? ' checked' : '') . '> Notify the owner on entry</label></div>';
    // Which Gems' scores show while an application sits here (never wanted at New).
    if ($pipe === 'crm' && ($gems = inc_gems())) {
        $on = inc_stage_gem_ids($s);
        $h .= '<fieldset class="inc-fs"><legend>Show Gem scores at this stage</legend><input type="hidden" name="gem_form" value="1">';
        foreach ($gems as $gid => $g) {
            $h .= '<label class="check"><input type="checkbox" name="gem_ids[]" value="' . $gid . '"' . (in_array($gid, $on, true) ? ' checked' : '') . '> ' . e($g['name']) . '</label>';
        }
        $h .= '</fieldset>';
    }
    $h .= '<fieldset class="inc-fs"><legend>Needed before a record may enter</legend>'
        . ($pipe === 'crm' ? '<label class="check"><input type="checkbox" name="req_eval_approved" value="1"' . (!empty($req['eval_approved']) ? ' checked' : '') . '> An approved evaluation</label>'
                            . '<label class="check"><input type="checkbox" name="req_workspace" value="1"' . (!empty($req['workspace']) ? ' checked' : '') . '> Assigned for incubation</label>' : '')
        . '<label class="check"><input type="checkbox" name="req_reason" value="1"' . (!empty($req['reason']) ? ' checked' : '') . '> A reason</label></fieldset>'
        . '<details class="inc-fs kb-next"><summary>May move on to' . ($next ? ' (' . count($next) . ' chosen)' : ' (any stage)') . '</summary>';
    foreach (inc_stage_map($pipe) as $sid => $sname) {
        if ($sid !== (int) $s['id']) {
            $h .= '<label class="check"><input type="checkbox" name="allowed_next[]" value="' . $sid . '"' . (in_array($sid, $next, true) ? ' checked' : '') . '> ' . e($sname) . '</label>';
        }
    }
    $h .= '<p class="muted small">None ticked means any stage.</p></details><button class="btn btn-primary">Save stage</button></form>';
    if ($pipe === 'lifecycle') {
        $h .= '<h4>Checklist</h4>';
        foreach (inc_checklist((int) $s['id']) as $it) {
            $h .= inc_form_open('class="inc-row"') . '<input type="hidden" name="action" value="check_save"><input type="hidden" name="stage_id" value="' . (int) $s['id'] . '"><input type="hidden" name="id" value="' . (int) $it['id'] . '">'
                . '<input type="text" name="text" value="' . e($it['text']) . '" maxlength="255" class="grow"> <button class="btn btn-sm">Save</button> <button class="btn btn-sm btn-reject" name="remove" value="1">Remove</button></form>';
        }
        $h .= inc_form_open('class="inc-row"') . '<input type="hidden" name="action" value="check_save"><input type="hidden" name="stage_id" value="' . (int) $s['id'] . '">'
            . '<input type="text" name="text" placeholder="Add a checklist item" maxlength="255" required class="grow"> <button class="btn btn-sm">Add</button></form>';
    }
    return $h;
}

/** The last board column: add a stage, and reach the hidden (inactive, empty) ones. */
function inc_stage_add_column_html(string $pipe, array $shownIds): string
{
    if (!inc_can('configure') || inc_kind() === 'eo') {
        return '';
    }
    $hidden = array_filter(inc_stages($pipe), static fn($s) => !in_array((int) $s['id'], $shownIds, true));
    $h = '<section class="kb-col kb-add" aria-label="Add a stage"><header class="kb-colhead"><span class="kb-colname">Add a stage</span></header><div class="kb-list">'
       . inc_form_open() . '<input type="hidden" name="action" value="stage_save"><input type="hidden" name="pipeline" value="' . e($pipe) . '"><input type="hidden" name="active" value="1">'
       . '<input type="text" name="name" maxlength="80" required placeholder="Stage name, e.g. Gem 2 Scoring" aria-label="New stage name">'
       . '<button class="btn btn-primary btn-sm">+ Add stage</button></form>'
       . '<p class="muted small">It goes at the end; move it with ← → in its ⋯ menu.</p>';
    if ($hidden) {
        $h .= '<div class="kb-hidden"><strong class="small">Hidden stages</strong>';
        foreach ($hidden as $s) {
            $h .= '<div class="kb-hiddenrow"><span>' . e($s['name']) . '</span>' . inc_stage_menu_html($s, $pipe) . '</div>';
        }
        $h .= '</div>';
    }
    return $h . '</div></section>';
}

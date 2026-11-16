<?php
/** Workflows: the organisation's signing routes, in the order they are tried. */

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $flash = ds_action(static function () {
        $w = ds_workflow((int) ($_POST['id'] ?? 0));
        if (!$w) {
            throw new InvalidArgumentException('That workflow no longer exists.');
        }
        switch ($_POST['action'] ?? '') {
            case 'toggle':
                fabo_exec('UPDATE ds_workflows SET active = 1 - active WHERE id = ? AND org_id = ?', 'ii', [(int) $w['id'], ds_org()]);
                ds_audit(null, 'workflow', (int) $w['id'], 'workflow_toggle', ((int) $w['active'] ? 'Deactivated' : 'Activated') . ' workflow "' . $w['name'] . '"');
                return (int) $w['active'] ? 'Deactivated.' : 'Activated.';
            case 'delete':
                fabo_exec('UPDATE ds_workflows SET deleted_at = NOW(), active = 0 WHERE id = ? AND org_id = ?', 'ii', [(int) $w['id'], ds_org()]);
                fabo_exec('UPDATE ds_categories SET default_workflow_id = NULL WHERE org_id = ? AND default_workflow_id = ?', 'ii', [ds_org(), (int) $w['id']]);
                ds_audit(null, 'workflow', (int) $w['id'], 'workflow_delete', 'Deleted workflow "' . $w['name'] . '" (documents already sent keep their route)');
                return 'Workflow deleted. Documents already sent keep the route they were sent with.';
            case 'copy':
                $plan = ds_plan_from_workflow($w);
                $id = ds_workflow_save(0, ['name' => mb_substr('Copy of ' . $w['name'], 0, 160), 'description' => $w['description'], 'conditions' => $w['conditions'], 'active' => 0,
                    'priority' => $w['priority'] + 1, 'stages' => $plan['stages']] + $plan);
                inc_redirect(ds_url('workflow', ['id' => $id]), ['ok', 'Copied. It starts inactive.']);
        }
        throw new InvalidArgumentException('Unknown action.');
    }, ds_url('workflows'));
}
ds_layout_start('Workflows', 'Routes a document can take. When a document is sent, the first active workflow whose conditions it meets is suggested (a category\'s default first).',
    '<a class="ds-btn ds-btn-primary" href="' . e(ds_url('workflow')) . '">' . ds_icon('plus') . 'New workflow</a>', ['Administration' => ds_url('admin'), 'Workflows' => null], $flash);
$wfs = ds_workflows();
if (!$wfs) {
    echo ds_empty('No workflows yet', 'A workflow is a reusable route: e.g. Creator → Department Head → Manager → CEO, with deadlines, escalation and conditions.', 'flow',
        '<a class="ds-btn ds-btn-primary" href="' . e(ds_url('workflow')) . '">' . ds_icon('plus') . 'Create the first workflow</a>');
}
echo '<div class="ds-wflist">';
foreach ($wfs as $i => $w0) {
    $w = ds_workflow((int) $w0['id']);
    $used = (int) fabo_one('SELECT COUNT(*) n FROM ds_documents WHERE org_id = ? AND workflow_id = ?', 'ii', [ds_org(), (int) $w['id']])['n'];
    echo '<article class="ds-card ds-wfcard ds-rise' . ((int) $w['active'] ? '' : ' is-off') . '" style="--i:' . $i . '"><div class="ds-wfcard-h"><div><span class="ds-prio-n">#' . (int) $w['priority'] . '</span><h3><a href="' . e(ds_url('workflow', ['id' => $w['id']])) . '">' . e($w['name']) . '</a></h3>'
       . ((int) $w['active'] ? ds_badge('Active', 'green') : ds_badge('Inactive', 'grey')) . '</div><div class="ds-wfcard-a">'
       . ds_button((int) $w['active'] ? 'Deactivate' : 'Activate', ['action' => 'toggle', 'id' => $w['id']], 'ds-btn ds-btn-ghost ds-btn-sm')
       . ds_button('Copy', ['action' => 'copy', 'id' => $w['id']], 'ds-btn ds-btn-ghost ds-btn-sm')
       . ds_button('Delete', ['action' => 'delete', 'id' => $w['id']], 'ds-btn ds-btn-ghost ds-btn-sm danger', 'Delete workflow "' . $w['name'] . '"?')
       . '<a class="ds-btn ds-btn-sm" href="' . e(ds_url('workflow', ['id' => $w['id']])) . '">' . ds_icon('edit') . 'Edit</a></div></div>'
       . '<p class="ds-muted small">' . e(ds_conditions_text($w['conditions'])) . ' · used by ' . $used . ' document' . ($used === 1 ? '' : 's') . ' · reminders every ' . (int) $w['reminder_hours'] . ' h'
       . ($w['escalate_hours'] ? ' · escalates after ' . (int) $w['escalate_hours'] . ' h' : '') . ' · rejection ' . ($w['rejection_rule'] === 'final' ? 'is final' : 'can be reinitiated') . '</p><ol class="ds-flowline">';
    foreach ($w['stages'] as $s) {
        echo '<li><span class="ds-flow-s">' . e($s['name']) . '</span><span class="ds-flow-p">';
        foreach ($s['signatories'] as $g) {
            echo '<em>' . e(match ($g['assignee_type']) {
                'person' => ds_person_name((int) $g['person_id']), 'owner' => 'Owner', 'owner_manager' => 'Owner\'s manager', 'authority' => (string) $g['authority'], default => $g['label'] ?: 'Chosen' })
               . ' · ' . e(strtolower(ds_actions()[$g['action']])) . '</em>';
        }
        echo '</span>' . (count($s['signatories']) > 1 ? '<small>' . ($s['completion'] === 'any' ? 'any one' : 'parallel') . '</small>' : '') . ($s['condition'] ? '<small>if ' . e(ds_conditions_text($s['condition'])) . '</small>' : '') . '</li>';
    }
    echo '</ol></article>';
}
echo '</div>';
ds_layout_end();

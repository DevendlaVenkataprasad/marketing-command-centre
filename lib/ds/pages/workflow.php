<?php
/**
 * The workflow builder: stages in order, people in each stage (parallel),
 * conditions, deadlines, escalation, rejection and resubmission rules. The
 * editor (sign.js) keeps its state as JSON in one field; the server
 * validates all of it again in ds_workflow_save().
 */

$id = (int) ($_GET['id'] ?? 0);
$w = $id ? ds_workflow($id) : null;
if ($id && !$w) {
    fabo_deny('That workflow does not exist.', 404);
}
$flash = null;
$state = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $state = json_decode((string) ($_POST['payload'] ?? ''), true);
    $flash = ds_action(static function () use ($id, $state) {
        if (!is_array($state)) {
            throw new InvalidArgumentException('The workflow could not be read. Reload and try again.');
        }
        $nid = ds_workflow_save($id, $state);
        inc_redirect(ds_url('workflow', ['id' => $nid]), ['ok', 'Workflow saved.']);
    }, ds_url('workflows'));
}
if (!$state) {
    if ($w) {
        $plan = ds_plan_from_workflow($w);
        $state = ['name' => $w['name'], 'description' => (string) $w['description'], 'priority' => (int) $w['priority'], 'active' => (int) $w['active'], 'conditions' => $w['conditions']] + $plan;
    } else {
        $state = ['name' => '', 'description' => '', 'priority' => 100, 'active' => 1, 'conditions' => [], 'rejection_rule' => 'reinitiate', 'resubmission_rule' => 'restart',
                  'reminder_hours' => (int) ds_setting('reminder_hours'), 'max_reminders' => 3, 'escalate_hours' => null, 'escalate_to' => 'manager', 'escalate_person_id' => null,
                  'escalate_action' => 'notify', 'deadline_days' => null,
                  'stages' => [['name' => 'Department Head', 'completion' => 'all', 'deadline_days' => 3, 'condition' => [],
                                'signatories' => [['type' => 'authority', 'person_id' => null, 'authority' => 'Department Head', 'scope' => 'doc_dept', 'action' => 'approve', 'method' => 'any', 'mandatory' => 1, 'label' => null]]]]];
    }
}
$authorities = array_values(array_unique(array_filter(array_map(static fn($r) => (string) $r['authority'],
    fabo_all('SELECT DISTINCT authority FROM ds_signatories WHERE org_id = ? AND authority IS NOT NULL AND authority <> ""', 'i', [ds_org()])))));
$meta = ['people' => ds_people(), 'categories' => ds_categories(), 'departments' => ds_departments(), 'units' => ds_units(), 'authorities' => $authorities,
         'types' => ds_assignee_types(), 'actions' => ds_actions(), 'methods' => array_map(static fn($m) => $m[0], ds_methods()), 'confidentiality' => ds_confidentiality()];
ds_layout_start($w ? $w['name'] : 'New workflow', 'Stages run in order; people in one stage act in parallel. Optional people never hold a stage up.', '',
    ['Administration' => ds_url('admin'), 'Workflows' => ds_url('workflows'), $w ? $w['name'] : 'New' => null], $flash);
echo ds_form('class="ds-wfb" data-wfbuilder') . '<input type="hidden" name="payload" data-payload>'
   . '<script type="application/json" data-wf-state>' . json_encode($state, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . '</script>'
   . '<script type="application/json" data-wf-meta>' . json_encode($meta, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT) . '</script>'
   . '<div class="ds-wfb-grid"><div class="ds-wfb-main"><div class="ds-card"><div class="ds-card-b ds-formgrid">'
   . ds_field('Workflow name', '<input data-k="name" required maxlength="160" placeholder="e.g. Purchase orders above ₹5 lakh">', '', true, 'span-2')
   . ds_field('Description', '<input data-k="description" maxlength="500">', '', false, 'span-2') . '</div></div>'
   . '<div class="ds-stages" data-stages></div><button type="button" class="ds-btn ds-btn-ghost ds-addstage" data-add-stage>' . ds_icon('plus') . 'Add stage</button></div>'
   . '<aside class="ds-wfb-side"><div class="ds-card"><div class="ds-card-h"><h2>' . ds_icon('filter') . 'When it applies</h2></div><div class="ds-card-b" data-conds></div></div>'
   . '<div class="ds-card"><div class="ds-card-h"><h2>' . ds_icon('gear') . 'Rules</h2></div><div class="ds-card-b ds-formgrid one">'
   . ds_field('Priority (lower is tried first)', '<input type="number" data-k="priority" min="1" max="9999">')
   . ds_field('Days for each stage (default)', '<input type="number" data-k="deadline_days" min="1" max="365" placeholder="' . e(ds_setting('deadline_days')) . '">')
   . ds_field('Remind every (hours)', '<input type="number" data-k="reminder_hours" min="1" max="720">')
   . ds_field('At most (reminders)', '<input type="number" data-k="max_reminders" min="0" max="50">')
   . ds_field('Escalate after (hours waiting)', '<input type="number" data-k="escalate_hours" min="1" max="2160" placeholder="Never">')
   . ds_field('Escalate to', '<select data-k="escalate_to"><option value="manager">The signatory\'s manager</option><option value="person">A named person</option><option value="admins">Administrators</option></select>')
   . ds_field('Escalation person', '<select data-k="escalate_person_id">' . inc_options(ds_people(), '', '—') . '</select>', '', false, 'ds-if-person')
   . ds_field('On escalation', '<select data-k="escalate_action"><option value="notify">Tell them</option><option value="reassign">Reassign the request to them</option></select>')
   . ds_field('When rejected', '<select data-k="rejection_rule"><option value="reinitiate">Owner may correct and re-send</option><option value="final">Rejection is final</option></select>')
   . ds_field('When re-sent', '<select data-k="resubmission_rule"><option value="restart">Restart from the first stage</option><option value="resume">Resume from the returning stage (same version only)</option></select>')
   . '<label class="ds-check"><input type="checkbox" data-k="active" value="1"> Active</label></div></div>'
   . '<button class="ds-btn ds-btn-primary ds-btn-block">' . ds_icon('check') . 'Save workflow</button></aside></div></form>';
ds_layout_end();

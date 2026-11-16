<?php
/**
 * The routing picker shared by New document and a draft's "Send for
 * signature": a configured workflow (suggested by its conditions) or
 * signatories chosen for this document, in order, with parallel steps.
 */

/** The plan and chosen people from a submitted picker. */
function ds_plan_from_post(array $post): array
{
    $mode = ($post['route'] ?? 'workflow') === 'adhoc' ? 'adhoc' : 'workflow';
    if ($mode === 'workflow') {
        $w = ds_workflow((int) ($post['workflow_id'] ?? 0));
        if (!$w || (int) $w['active'] !== 1) {
            throw new InvalidArgumentException('Choose an active workflow, or pick signatories yourself.');
        }
        $chosen = [];
        foreach ((array) ($post['chosen'] ?? []) as $k => $v) {
            if (preg_match('/^\d+:\d+$/', (string) $k)) {
                $chosen[(string) $k] = (int) $v;
            }
        }
        return [ds_plan_from_workflow($w), $chosen];
    }
    $rows = [];
    foreach ((array) ($post['signers'] ?? []) as $r) {
        if (is_array($r) && ($r['person_id'] ?? '') !== '') {
            $rows[] = $r + ['mandatory' => '0'];
        }
    }
    return [ds_plan_adhoc($rows, ['deadline_days' => $post['deadline_days'] ?? '', 'reminder_hours' => $post['reminder_hours'] ?? '']), []];
}

/** The picker's HTML. $doc gives the conditions to suggest a workflow by. */
function ds_routing_picker(?array $doc = null): string
{
    $wfs = [];
    foreach (ds_workflows(true) as $w) {
        $full = ds_workflow((int) $w['id']);
        $stages = [];
        foreach ($full['stages'] as $si => $s) {
            $sigs = [];
            foreach ($s['signatories'] as $gi => $g) {
                $sigs[] = ['type' => $g['assignee_type'], 'who' => match ($g['assignee_type']) {
                    'person' => ds_person_name((int) $g['person_id']), 'owner' => 'Document owner', 'owner_manager' => 'Owner\'s manager',
                    'authority' => $g['authority'] . ($g['scope'] === 'doc_dept' ? ' (document\'s department)' : ''), default => $g['label'] ?: 'Chosen when sending',
                }, 'action' => ds_actions()[$g['action']], 'method' => ds_method_label($g['method']), 'mandatory' => (int) $g['mandatory'], 'slot' => $si . ':' . $gi, 'label' => $g['label']];
            }
            $stages[] = ['name' => $s['name'], 'completion' => $s['completion'], 'condition' => $s['condition'], 'cond_text' => $s['condition'] ? ds_conditions_text($s['condition']) : '', 'sigs' => $sigs];
        }
        $wfs[] = ['id' => (int) $w['id'], 'name' => $w['name'], 'description' => (string) $w['description'], 'conditions' => $full['conditions'],
                  'cond_text' => ds_conditions_text($full['conditions']), 'stages' => $stages];
    }
    $catDefaults = [];
    foreach (fabo_all('SELECT id, default_workflow_id FROM ds_categories WHERE org_id = ? AND default_workflow_id IS NOT NULL', 'i', [ds_org()]) as $r) {
        $catDefaults[(int) $r['id']] = (int) $r['default_workflow_id'];
    }
    $people = ds_people();
    $peopleOpts = inc_options($people, '', 'Choose a person…');
    $methodOpts = inc_options(array_map(static fn($m) => $m[0], ds_methods()), 'any');
    $actionOpts = inc_options(ds_actions(), 'sign');
    $suggest = $doc ? ds_workflow_for($doc) : null;
    $h = '<div class="ds-route" data-route>'
       . '<script type="application/json" data-workflows>' . json_encode(['workflows' => $wfs, 'catDefaults' => $catDefaults, 'suggest' => $suggest], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . '</script>'
       . '<div class="ds-seg" role="radiogroup" aria-label="How it is routed">'
       . '<label><input type="radio" name="route" value="workflow"' . ($wfs ? ' checked' : ' disabled') . '><span>' . ds_icon('flow') . 'Use a workflow</span></label>'
       . '<label><input type="radio" name="route" value="adhoc"' . ($wfs ? '' : ' checked') . '><span>' . ds_icon('users') . 'Choose signatories</span></label></div>';
    // Workflow
    $h .= '<div class="ds-route-wf" data-pane="workflow"' . ($wfs ? '' : ' hidden') . '>';
    if ($wfs) {
        $h .= '<div class="ds-wfpick" role="radiogroup" aria-label="Workflow">';
        foreach ($wfs as $w) {
            $h .= '<label class="ds-wfopt"><input type="radio" name="workflow_id" value="' . $w['id'] . '"' . ($suggest === $w['id'] ? ' checked' : '') . '><span class="ds-wfopt-b"><strong>' . e($w['name'])
                . '</strong><small>' . e($w['cond_text']) . ' · ' . count($w['stages']) . ' stage' . (count($w['stages']) === 1 ? '' : 's') . '</small><em class="ds-wf-sug" hidden>Suggested</em></span></label>';
        }
        $h .= '</div><div class="ds-wfpreview" data-wfpreview></div>';
    } else {
        $h .= '<p class="ds-muted">No workflows are set up yet.' . (ds_can('workflows.manage') ? ' <a href="' . e(ds_url('workflow')) . '">Create one</a>.' : '') . '</p>';
    }
    $h .= '<template data-chosen-tpl><select>' . $peopleOpts . '</select></template></div>';
    // Ad hoc
    $h .= '<div class="ds-route-adhoc" data-pane="adhoc"' . ($wfs ? ' hidden' : '') . '><p class="ds-hint">Signatories with the same step number sign at the same time (parallel); a higher step waits for the lower ones. Drag to reorder. '
        . 'To include someone outside the organisation, choose <strong>Someone outside the organisation</strong>: they get an e-mail link to a single page where they draw or upload their signature — no account needed.</p>'
        . '<ol class="ds-signers" data-signers></ol><button type="button" class="ds-btn ds-btn-ghost ds-btn-sm" data-add-signer>' . ds_icon('plus') . 'Add signatory</button>'
        . '<template data-signer-tpl><li class="ds-signer"><span class="ds-grip" title="Drag to reorder">' . ds_icon('grip') . '</span>'
        . '<label class="ds-signer-o"><span>Step</span><input type="number" min="1" max="50" value="1" data-f="order" aria-label="Step"></label>'
        . '<select data-f="person_id" aria-label="Signatory" data-signer-who>' . $peopleOpts . '<option value="ext">Someone outside the organisation (by e-mail)…</option></select>'
        . '<span class="ds-signer-ext" data-ext hidden><input data-f="ext_name" maxlength="160" placeholder="Full name" aria-label="Their full name">'
        . '<input type="email" data-f="ext_email" maxlength="190" placeholder="E-mail" aria-label="Their e-mail">'
        . '<input data-f="ext_org" maxlength="190" placeholder="Organisation (optional)" aria-label="Their organisation"></span>'
        . '<select data-f="action" aria-label="Action">' . $actionOpts . '</select>'
        . '<select data-f="method" aria-label="Signing method">' . $methodOpts . '</select>'
        . '<label class="ds-check"><input type="checkbox" data-f="mandatory" value="1" checked> Mandatory</label>'
        . '<button type="button" class="ds-icobtn" data-remove aria-label="Remove">' . ds_icon('x') . '</button></li></template>'
        . '<div class="ds-row2">' . ds_field('Days each signatory has', '<input type="number" name="deadline_days" min="1" max="365" placeholder="' . e(ds_setting('deadline_days')) . '">')
        . ds_field('Remind every (hours)', '<input type="number" name="reminder_hours" min="1" max="720" placeholder="' . e(ds_setting('reminder_hours')) . '">') . '</div></div>';
    return $h . '</div>';
}

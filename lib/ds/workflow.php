<?php
/**
 * Digital Signatures — the workflow engine.
 *
 * A PLAN is what a document will go through: stages in order; inside a
 * stage, signatories who act in parallel. A stage completes when every
 * mandatory signatory has acted ("all"), or when the first one has ("any");
 * optional signatories never hold a stage up. Sequential signing is one
 * person per stage; parallel signing is several in one; anything in between
 * is a mix. A plan comes from a configured workflow (chosen automatically by
 * its conditions, or picked) or is built for the one document.
 *
 * Signatories are resolved when the document is sent: a named person; the
 * document's owner; the owner's manager; or a signing AUTHORITY (Department
 * Head, CEO …) from the signatory directory — preferring the document's
 * department, and only someone whose authority limit covers the document's
 * value. Stages can carry conditions (value bands, categories, departments,
 * units) and are skipped when the document does not meet them.
 *
 * A request becomes "pending" only when its stage is reached; until then it
 * is "waiting" and the person is not told. Every decision re-checks that the
 * request is the viewer's, still pending, and for the version on screen.
 */

function ds_assignee_types(): array
{
    return [
        'person'        => 'A named person',
        'owner'         => 'The document owner',
        'owner_manager' => 'The owner\'s manager',
        'authority'     => 'A signing authority',
        'chosen'        => 'Chosen when sending',
    ];
}

function ds_actions(): array
{
    return ['sign' => 'Sign', 'approve' => 'Approve', 'review' => 'Review'];
}

/** Signing methods: key => [label, kind]. Kind: electronic | drawn | digital. */
function ds_methods(): array
{
    return [
        'any'       => ['Signatory chooses', 'any'],
        'digital'   => ['Digital signature (eSign or DSC)', 'digital'],
        'esign'     => ['Aadhaar eSign', 'digital'],
        'dsc'       => ['DSC (USB token)', 'digital'],
        'drawn'     => ['Drawn signature', 'drawn'],
        'eapproval' => ['Electronic approval', 'electronic'],
    ];
}

function ds_method_label(?string $m): string
{
    return ['esign_cca' => 'Aadhaar eSign', 'dsc_upload' => 'DSC'][$m ?? ''] ?? (ds_methods()[$m ?? ''][0] ?? (string) $m);
}

/** Is this method a cryptographic (legally recognised) digital signature? */
function ds_method_is_digital(?string $m): bool
{
    return in_array($m, ['esign', 'dsc'], true);
}

/* ============================================================ workflows */

function ds_workflows(bool $activeOnly = false): array
{
    return fabo_all('SELECT * FROM ds_workflows WHERE org_id = ? AND deleted_at IS NULL' . ($activeOnly ? ' AND active = 1' : '') . ' ORDER BY priority, name', 'i', [ds_org()]);
}

/** A workflow with its stages and signatories, or null. */
function ds_workflow(int $id): ?array
{
    $w = fabo_one('SELECT * FROM ds_workflows WHERE id = ? AND org_id = ? AND deleted_at IS NULL', 'ii', [$id, ds_org()]);
    if (!$w) {
        return null;
    }
    $w['conditions'] = json_decode((string) $w['conditions_json'], true) ?: [];
    $w['stages'] = [];
    foreach (fabo_all('SELECT * FROM ds_workflow_stages WHERE workflow_id = ? ORDER BY seq, id', 'i', [$id]) as $s) {
        $s['condition'] = json_decode((string) $s['condition_json'], true) ?: [];
        $s['signatories'] = fabo_all('SELECT * FROM ds_workflow_signatories WHERE stage_id = ? ORDER BY seq, id', 'i', [(int) $s['id']]);
        $w['stages'][] = $s;
    }
    return $w;
}

/** Validates a condition set: categories, departments, units, confidentiality, min_value, max_value. */
function ds_conditions_clean($c): array
{
    $c = is_array($c) ? $c : [];
    $out = [];
    foreach (['categories', 'departments'] as $k) {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($c[$k] ?? [])))));
        if ($ids) {
            $out[$k] = $ids;
        }
    }
    $units = array_values(array_filter(array_map(static fn($u) => mb_substr(trim((string) $u), 0, 120), (array) ($c['units'] ?? []))));
    if ($units) {
        $out['units'] = $units;
    }
    $conf = array_values(array_intersect((array) ($c['confidentiality'] ?? []), array_keys(ds_confidentiality())));
    if ($conf) {
        $out['confidentiality'] = $conf;
    }
    foreach (['min_value', 'max_value'] as $k) {
        if (isset($c[$k]) && trim((string) $c[$k]) !== '') {
            $out[$k] = (float) ds_amount($c[$k]);
        }
    }
    if (isset($out['min_value'], $out['max_value']) && $out['min_value'] > $out['max_value']) {
        throw new InvalidArgumentException('A minimum value cannot be above the maximum.');
    }
    return $out;
}

/** Does a document meet a condition set? */
function ds_conditions_match(array $c, array $doc): bool
{
    if (!empty($c['categories']) && !in_array((int) $doc['category_id'], $c['categories'], true)) {
        return false;
    }
    if (!empty($c['departments']) && !in_array((int) $doc['department_id'], $c['departments'], true)) {
        return false;
    }
    if (!empty($c['units']) && !in_array((string) $doc['unit'], $c['units'], true)) {
        return false;
    }
    if (!empty($c['confidentiality']) && !in_array((string) $doc['confidentiality'], $c['confidentiality'], true)) {
        return false;
    }
    $v = $doc['value_amount'] === null || $doc['value_amount'] === '' ? null : (float) $doc['value_amount'];
    if (isset($c['min_value']) && ($v === null || $v < $c['min_value'])) {
        return false;
    }
    if (isset($c['max_value']) && $v !== null && $v > $c['max_value']) {
        return false;
    }
    return true;
}

/** Plain words for a condition set. */
function ds_conditions_text(array $c): string
{
    $p = [];
    if (!empty($c['categories'])) {
        $p[] = implode(' / ', array_map('ds_category_name', $c['categories']));
    }
    if (!empty($c['departments'])) {
        $p[] = implode(' / ', array_map('ds_dept_name', $c['departments'])) . ' dept.';
    }
    if (!empty($c['units'])) {
        $p[] = implode(' / ', $c['units']);
    }
    if (!empty($c['confidentiality'])) {
        $p[] = implode(' / ', array_map(static fn($k) => ds_confidentiality()[$k] ?? $k, $c['confidentiality']));
    }
    if (isset($c['min_value'])) {
        $p[] = 'value ≥ ' . ds_money($c['min_value']);
    }
    if (isset($c['max_value'])) {
        $p[] = 'value ≤ ' . ds_money($c['max_value']);
    }
    return $p ? implode(' · ', $p) : 'Any document';
}

/** The first active workflow whose conditions the document meets (by priority), or null. */
function ds_workflow_for(array $doc): ?int
{
    if (!empty($doc['category_id'])) {
        $def = fabo_one('SELECT default_workflow_id FROM ds_categories WHERE id = ? AND org_id = ?', 'ii', [(int) $doc['category_id'], ds_org()]);
        if ($def && $def['default_workflow_id'] && ($w = ds_workflow((int) $def['default_workflow_id'])) && (int) $w['active'] === 1 && ds_conditions_match($w['conditions'], $doc)) {
            return (int) $w['id'];
        }
    }
    foreach (ds_workflows(true) as $w) {
        if (ds_conditions_match(json_decode((string) $w['conditions_json'], true) ?: [], $doc)) {
            return (int) $w['id'];
        }
    }
    return null;
}

/**
 * Saves a workflow from the builder. $in: name, description, priority,
 * active, rules, conditions, stages[ {name, completion, deadline_days,
 * condition, signatories[ {type, person_id, authority, scope, action,
 * method, mandatory, label} ] } ]. Returns the id. Documents already sent
 * keep the plan they were sent with; the change applies to the next send.
 */
function ds_workflow_save(int $id, array $in): int
{
    ds_need('workflows.manage');
    $name = ds_str($in['name'] ?? '', 160, true, 'Workflow name');
    $desc = ds_str($in['description'] ?? '', 500, false, 'Description');
    $cond = ds_conditions_clean($in['conditions'] ?? []);
    $rej = ds_pick($in['rejection_rule'] ?? 'reinitiate', ['reinitiate' => 1, 'final' => 1], 'rejection rule');
    $res = ds_pick($in['resubmission_rule'] ?? 'restart', ['restart' => 1, 'resume' => 1], 'resubmission rule');
    $esc = ds_pick($in['escalate_to'] ?? 'manager', ['manager' => 1, 'person' => 1, 'admins' => 1], 'escalation target');
    $escAct = ds_pick($in['escalate_action'] ?? 'notify', ['notify' => 1, 'reassign' => 1], 'escalation action');
    $escPerson = (int) ($in['escalate_person_id'] ?? 0) ?: null;
    if ($esc === 'person' && (!$escPerson || !ds_is_member($escPerson))) {
        throw new InvalidArgumentException('Choose who escalations go to.');
    }
    $stages = array_values(array_filter((array) ($in['stages'] ?? []), 'is_array'));
    if (!$stages) {
        throw new InvalidArgumentException('A workflow needs at least one stage.');
    }
    if (count($stages) > 30) {
        throw new InvalidArgumentException('A workflow can have at most 30 stages.');
    }
    $clean = [];
    foreach ($stages as $i => $s) {
        $sigs = [];
        foreach (array_values(array_filter((array) ($s['signatories'] ?? []), 'is_array')) as $j => $g) {
            $type = ds_pick($g['type'] ?? 'person', ds_assignee_types(), 'signatory type');
            $pid = (int) ($g['person_id'] ?? 0) ?: null;
            $auth = mb_substr(trim((string) ($g['authority'] ?? '')), 0, 120) ?: null;
            if ($type === 'person' && (!$pid || !ds_is_member($pid))) {
                throw new InvalidArgumentException('Stage ' . ($i + 1) . ': choose the person for signatory ' . ($j + 1) . '.');
            }
            if ($type === 'authority' && !$auth) {
                throw new InvalidArgumentException('Stage ' . ($i + 1) . ': name the signing authority for signatory ' . ($j + 1) . '.');
            }
            $sigs[] = ['type' => $type, 'person_id' => $type === 'person' ? $pid : null, 'authority' => $type === 'authority' ? $auth : null,
                       'scope' => ($g['scope'] ?? 'doc_dept') === 'any' ? 'any' : 'doc_dept',
                       'action' => ds_pick($g['action'] ?? 'sign', ds_actions(), 'action'), 'method' => ds_pick($g['method'] ?? 'any', ds_methods(), 'signing method'),
                       'mandatory' => !empty($g['mandatory']) ? 1 : 0, 'label' => mb_substr(trim((string) ($g['label'] ?? '')), 0, 120) ?: null];
        }
        if (!$sigs) {
            throw new InvalidArgumentException('Stage ' . ($i + 1) . ' has nobody in it.');
        }
        if (!array_filter($sigs, static fn($g) => $g['mandatory'])) {
            $sigs[0]['mandatory'] = 1;             // a stage nobody must act on would pass by itself
        }
        $dd = trim((string) ($s['deadline_days'] ?? ''));
        $clean[] = ['name' => ds_str($s['name'] ?? ('Stage ' . ($i + 1)), 120, true, 'Stage name'), 'completion' => ($s['completion'] ?? 'all') === 'any' ? 'any' : 'all',
                    'deadline_days' => $dd === '' ? null : max(1, min(365, (int) $dd)), 'condition' => ds_conditions_clean($s['condition'] ?? []), 'signatories' => $sigs];
    }
    $num = static function ($v, int $min, int $max, ?int $def) {
        $v = trim((string) ($v ?? ''));
        return $v === '' ? $def : max($min, min($max, (int) $v));
    };
    return ds_tx(static function () use ($id, $name, $desc, $cond, $rej, $res, $esc, $escAct, $escPerson, $clean, $in, $num) {
        $vals = [$name, $desc ?: null, json_encode($cond), $num($in['priority'] ?? 100, 1, 9999, 100), $rej, $res, $num($in['reminder_hours'] ?? 24, 1, 720, 24),
                 $num($in['max_reminders'] ?? 3, 0, 50, 3), $num($in['escalate_hours'] ?? '', 1, 2160, null), $esc, $escPerson, $escAct, $num($in['deadline_days'] ?? '', 1, 365, null),
                 !empty($in['active']) ? 1 : 0];
        if ($id > 0) {
            if (!ds_workflow($id)) {
                throw new InvalidArgumentException('That workflow no longer exists.');
            }
            fabo_exec('UPDATE ds_workflows SET name=?, description=?, conditions_json=?, priority=?, rejection_rule=?, resubmission_rule=?, reminder_hours=?, max_reminders=?,
                       escalate_hours=?, escalate_to=?, escalate_person_id=?, escalate_action=?, deadline_days=?, active=?, version = version + 1 WHERE id = ? AND org_id = ?',
                      'sssissiiisisiiii', array_merge($vals, [$id, ds_org()]));
            fabo_exec('DELETE FROM ds_workflow_stages WHERE workflow_id = ?', 'i', [$id]);
        } else {
            $id = fabo_exec('INSERT INTO ds_workflows (name, description, conditions_json, priority, rejection_rule, resubmission_rule, reminder_hours, max_reminders,
                             escalate_hours, escalate_to, escalate_person_id, escalate_action, deadline_days, active, org_id, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                            'sssissiiisisiiis', array_merge($vals, [ds_org(), ds_actor()]));
        }
        foreach ($clean as $i => $s) {
            $sid = fabo_exec('INSERT INTO ds_workflow_stages (workflow_id, seq, name, completion, deadline_days, condition_json) VALUES (?,?,?,?,?,?)', 'iissis',
                             [$id, $i + 1, $s['name'], $s['completion'], $s['deadline_days'], $s['condition'] ? json_encode($s['condition']) : null]);
            foreach ($s['signatories'] as $j => $g) {
                fabo_exec('INSERT INTO ds_workflow_signatories (stage_id, seq, assignee_type, person_id, authority, scope, action, method, mandatory, label) VALUES (?,?,?,?,?,?,?,?,?,?)',
                          'iisissssis', [$sid, $j + 1, $g['type'], $g['person_id'], $g['authority'], $g['scope'], $g['action'], $g['method'], $g['mandatory'], $g['label']]);
            }
        }
        ds_audit(null, 'workflow', $id, 'workflow_save', 'Saved workflow "' . $name . '" (' . count($clean) . ' stage' . (count($clean) === 1 ? '' : 's') . ')', ['stages' => array_map(static fn($s) => $s['name'], $clean)]);
        return $id;
    });
}

/* ================================================================= plans */

/** A plan from a workflow. */
function ds_plan_from_workflow(array $w): array
{
    $plan = ['mode' => 'workflow', 'workflow_id' => (int) $w['id'], 'workflow' => $w['name']];
    foreach (['rejection_rule', 'resubmission_rule', 'reminder_hours', 'max_reminders', 'escalate_hours', 'escalate_to', 'escalate_person_id', 'escalate_action', 'deadline_days'] as $k) {
        $plan[$k] = $w[$k];
    }
    $plan['stages'] = [];
    foreach ($w['stages'] as $s) {
        $plan['stages'][] = ['name' => $s['name'], 'completion' => $s['completion'], 'deadline_days' => $s['deadline_days'], 'condition' => $s['condition'],
            'signatories' => array_map(static fn($g) => ['type' => $g['assignee_type'], 'person_id' => $g['person_id'] ? (int) $g['person_id'] : null,
                'authority' => $g['authority'], 'scope' => $g['scope'], 'action' => $g['action'], 'method' => $g['method'], 'mandatory' => (int) $g['mandatory'],
                'label' => $g['label']], $s['signatories'])];
    }
    return $plan;
}

/**
 * A plan built for one document from rows of [person_id, order, action,
 * method, mandatory]: the same order number signs in parallel, a higher one
 * after. Rules come from the form or the defaults.
 */
function ds_plan_adhoc(array $rows, array $rules = []): array
{
    $byOrder = [];
    foreach ($rows as $r) {
        if (!isset($r['person_id']) || $r['person_id'] === '') {
            continue;
        }
        if ($r['person_id'] === 'ext') {
            // Someone outside the organisation, by name and e-mail: they sign on a one-page link.
            $ext = ds_external_clean($r);
            $o = max(1, min(50, (int) ($r['order'] ?? 1)));
            $byOrder[$o][] = ['type' => 'external', 'person_id' => null, 'authority' => null, 'scope' => 'any', 'external' => $ext,
                              'action' => ($r['action'] ?? 'sign') === 'approve' ? 'approve' : 'sign', 'method' => 'any',
                              'mandatory' => !isset($r['mandatory']) || !empty($r['mandatory']) ? 1 : 0, 'label' => $ext['name']];
            continue;
        }
        $pid = (int) $r['person_id'];
        if (!ds_is_member($pid)) {
            throw new InvalidArgumentException('Every signatory must be someone in this organisation.');
        }
        $o = max(1, min(50, (int) ($r['order'] ?? 1)));
        $byOrder[$o][] = ['type' => 'person', 'person_id' => $pid, 'authority' => null, 'scope' => 'any',
                          'action' => ds_pick($r['action'] ?? 'sign', ds_actions(), 'action'), 'method' => ds_pick($r['method'] ?? 'any', ds_methods(), 'signing method'),
                          'mandatory' => !isset($r['mandatory']) || !empty($r['mandatory']) ? 1 : 0, 'label' => null];
    }
    if (!$byOrder) {
        throw new InvalidArgumentException('Add at least one signatory.');
    }
    ksort($byOrder);
    $plan = ['mode' => 'adhoc', 'workflow_id' => null, 'workflow' => null, 'rejection_rule' => 'reinitiate', 'resubmission_rule' => 'restart',
             'reminder_hours' => max(1, (int) ($rules['reminder_hours'] ?? ds_setting('reminder_hours'))), 'max_reminders' => 3,
             'escalate_hours' => null, 'escalate_to' => 'manager', 'escalate_person_id' => null, 'escalate_action' => 'notify',
             'deadline_days' => ($rules['deadline_days'] ?? '') !== '' ? max(1, (int) $rules['deadline_days']) : null, 'stages' => []];
    $n = 1;
    foreach ($byOrder as $sigs) {
        if (!array_filter($sigs, static fn($g) => $g['mandatory'])) {
            $sigs[0]['mandatory'] = 1;
        }
        $first = $sigs[0];
        $name = count($sigs) > 1 ? 'Step ' . $n . ' (parallel)'
              : (ds_actions()[$first['action']] . ' · ' . ($first['type'] === 'external' ? $first['external']['name'] : ds_person_name($first['person_id'])));
        $plan['stages'][] = ['name' => $name, 'completion' => 'all', 'deadline_days' => null, 'condition' => [], 'signatories' => $sigs];
        $n++;
    }
    return $plan;
}

/**
 * Who a plan signatory is, for this document: [person id, label] or null
 * when nobody fits (the send is then refused with the reason).
 */
function ds_resolve(array $g, array $doc, array $chosen, int $stageIdx, int $sigIdx): array
{
    switch ($g['type']) {
        case 'person':
            return [(int) $g['person_id'], $g['label'] ?: null];
        case 'external':
            // Someone outside the organisation (person 0 marks "nobody here"; the request carries external_id).
            return [0, $g['external']['name'] ?? 'External signatory'];
        case 'owner':
            return [(int) $doc['owner_id'], $g['label'] ?: 'Document owner'];
        case 'owner_manager':
            $m = ds_manager_of((int) $doc['owner_id']);
            if (!$m) {
                throw new InvalidArgumentException('Stage ' . ($stageIdx + 1) . ' needs the owner\'s manager, but ' . ds_person_name((int) $doc['owner_id']) . ' has no manager in HR.');
            }
            return [$m, $g['label'] ?: 'Owner\'s manager'];
        case 'chosen':
            $pid = (int) ($chosen[$stageIdx . ':' . $sigIdx] ?? 0);
            if (!$pid || !ds_is_member($pid)) {
                throw new InvalidArgumentException('Choose the person for "' . ($g['label'] ?: 'stage ' . ($stageIdx + 1)) . '".');
            }
            return [$pid, $g['label'] ?: null];
        case 'authority':
            $auth = (string) $g['authority'];
            $value = $doc['value_amount'] === null ? null : (float) $doc['value_amount'];
            $rows = fabo_all('SELECT s.* FROM ds_signatories s WHERE s.org_id = ? AND s.status = "active" AND s.authority = ? ORDER BY s.id', 'is', [ds_org(), $auth]);
            $rows = array_values(array_filter($rows, static fn($r) => ds_is_member((int) $r['person_id'])
                && ($value === null || $r['authority_limit'] === null || (float) $r['authority_limit'] >= $value)));
            if ($g['scope'] === 'doc_dept' && $doc['department_id']) {
                $inDept = array_values(array_filter($rows, static fn($r) => (int) $r['department_id'] === (int) $doc['department_id']));
                if ($inDept) {
                    $rows = $inDept;
                } else {
                    // An organisation-wide authority (no department) stands in.
                    $rows = array_values(array_filter($rows, static fn($r) => !$r['department_id']));
                }
            }
            if (!$rows) {
                throw new InvalidArgumentException('Nobody holds the signing authority "' . $auth . '"'
                    . ($value !== null ? ' for a value of ' . ds_money($value) : '') . ($g['scope'] === 'doc_dept' && $doc['department_id'] ? ' in ' . ds_dept_name((int) $doc['department_id']) : '')
                    . '. Add them in the Signatory directory.');
            }
            return [(int) $rows[0]['person_id'], $g['label'] ?: $auth];
    }
    throw new InvalidArgumentException('Unknown signatory type.');
}

/** An active delegation for a person today: [delegate id, delegation row] or null. */
function ds_delegate_of(int $personId, ?int $categoryId): ?array
{
    $p = ds_profile($personId);
    if (!$p || (int) $p['may_delegate'] !== 1) {
        return null;
    }
    $d = fabo_one('SELECT * FROM ds_delegations WHERE org_id = ? AND from_person_id = ? AND status = "active" AND starts_on <= CURDATE() AND ends_on >= CURDATE()
                    AND (category_id IS NULL OR category_id = ?) ORDER BY id DESC LIMIT 1', 'iii', [ds_org(), $personId, (int) $categoryId]);
    return $d && ds_is_member((int) $d['to_person_id']) ? [(int) $d['to_person_id'], $d] : null;
}

/* ============================================================== sending */

/**
 * Sends a document for signature: fixes the plan, opens a new cycle, locks
 * the version, makes every request ("waiting"), and starts the first stage.
 * $chosen holds people for "Chosen when sending" slots, keyed stage:slot.
 */
function ds_submit(int $docId, array $plan, array $chosen, $version): string
{
    $doc = ds_doc_open($docId);
    if (!ds_doc_can_manage($doc)) {
        fabo_deny('You cannot send this document.', 403);
    }
    if (!in_array($doc['status'], ['draft', 'reinitiated', 'rejected', 'changes_requested'], true)) {
        throw new InvalidArgumentException('This document is ' . strtolower(ds_doc_statuses()[$doc['status']][0]) . '; it cannot be sent now.');
    }
    if ($doc['status'] === 'rejected' && ($doc['plan_json'] ? (json_decode($doc['plan_json'], true)['rejection_rule'] ?? '') : '') === 'final') {
        throw new InvalidArgumentException('This workflow treats a rejection as final. Create a new document instead.');
    }
    if ($doc['valid_until'] && $doc['valid_until'] < date('Y-m-d')) {
        throw new InvalidArgumentException('The document\'s validity date has passed. Change it before sending.');
    }
    // Resolve everyone first, so a send is all or nothing.
    $resolved = [];
    $kept = [];
    foreach ($plan['stages'] as $si => $s) {
        if ($s['condition'] && !ds_conditions_match($s['condition'], $doc)) {
            continue;                               // a stage this document does not need
        }
        $people = [];
        foreach ($s['signatories'] as $gi => $g) {
            [$pid, $label] = ds_resolve($g, $doc, $chosen, $si, $gi);
            $people[] = ['person_id' => $pid, 'label' => $label, 'fields' => ds_fields_clean($plan['fields'][$si . ':' . $gi] ?? [])] + $g;
        }
        $resolved[] = ['name' => $s['name'], 'completion' => $s['completion'], 'deadline_days' => $s['deadline_days'] ?? null, 'people' => $people];
        $kept[] = $s;
    }
    if (!$resolved) {
        throw new InvalidArgumentException('No stage of this workflow applies to this document. Check the stage conditions.');
    }
    $prevCycle = (int) $doc['cycle'];
    $resume = $prevCycle > 0 && ($plan['resubmission_rule'] ?? 'restart') === 'resume' && in_array($doc['status'], ['rejected', 'changes_requested'], true)
           && (int) (fabo_one('SELECT version_id FROM ds_signature_requests WHERE doc_id = ? AND cycle = ? LIMIT 1', 'ii', [$docId, $prevCycle])['version_id'] ?? 0) === (int) $doc['current_version_id'];
    $msg = ds_tx(static function () use ($doc, $docId, $plan, $resolved, $version, $prevCycle, $resume) {
        ds_bump($docId, $version);
        $cycle = $prevCycle + 1;
        $ver = fabo_one('SELECT * FROM ds_document_versions WHERE id = ?', 'i', [(int) $doc['current_version_id']]);
        // Restart: signing starts again from the version as uploaded. Resume keeps what is on the file.
        if (!$resume) {
            fabo_exec('UPDATE ds_documents SET working_file_id = ? WHERE id = ?', 'ii', [(int) $ver['file_id'], $docId]);
        }
        $carried = [];
        if ($resume) {
            $stop = (int) (fabo_one('SELECT MIN(stage_seq) s FROM ds_signature_requests WHERE doc_id = ? AND cycle = ? AND status IN ("rejected","changes")', 'ii', [$docId, $prevCycle])['s'] ?? 0);
            foreach (fabo_all('SELECT * FROM ds_signature_requests WHERE doc_id = ? AND cycle = ? AND stage_seq < ? AND status IN ("signed","approved","reviewed","carried")', 'iii', [$docId, $prevCycle, $stop]) as $r) {
                $carried[(int) $r['stage_seq'] . ':' . (int) $r['person_id']] = $r;
            }
        }
        foreach ($resolved as $si => $s) {
            foreach ($s['people'] as $gi => $p) {
                $extId = ($p['type'] ?? '') === 'external' ? ds_external_upsert((array) $p['external']) : null;
                $prev = $extId ? null : ($carried[($si + 1) . ':' . $p['person_id']] ?? null);
                fabo_exec('INSERT INTO ds_signature_requests (org_id, doc_id, version_id, cycle, stage_seq, stage_name, completion, seq, person_id, label, action, method, mandatory, status,
                           deadline_days, used_method, acted_at, evidence_json, seal, remarks, placement_json, external_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', 'iiiiissiisssisissssssi',
                          [ds_org(), $docId, (int) $ver['id'], $cycle, $si + 1, $s['name'], $s['completion'], $gi + 1, $p['person_id'], $p['label'], $p['action'], $p['method'],
                           (int) $p['mandatory'], $prev ? 'carried' : 'waiting', $s['deadline_days'] ?? ($plan['deadline_days'] ?? null),
                           $prev['used_method'] ?? null, $prev['acted_at'] ?? null, $prev['evidence_json'] ?? null, $prev['seal'] ?? null,
                           $prev ? 'Carried over from cycle ' . $prevCycle : null, $p['fields'] ? json_encode($p['fields']) : null, $extId]);
            }
        }
        fabo_exec('UPDATE ds_document_versions SET locked_at = COALESCE(locked_at, NOW()) WHERE id = ?', 'i', [(int) $ver['id']]);
        $planJson = json_encode($plan, JSON_UNESCAPED_UNICODE);
        fabo_exec('UPDATE ds_documents SET draft_plan_json = NULL, plan_json = ?, workflow_id = ?, cycle = ?, status = "submitted", submitted_at = NOW(), completed_at = NULL, closed_at = NULL, signed_file_id = NULL
                   WHERE id = ?', 'siii', [$planJson, $plan['workflow_id'] ?: null, $cycle, $docId]);
        if (empty($doc['deadline']) && !empty($plan['deadline_days'])) {
            fabo_exec('UPDATE ds_documents SET deadline = DATE_ADD(CURDATE(), INTERVAL ? DAY) WHERE id = ?', 'ii', [(int) $plan['deadline_days'], $docId]);
        }
        $who = [];
        foreach ($resolved as $s) {
            foreach ($s['people'] as $p) {
                $who[] = ($p['type'] ?? '') === 'external' ? $p['external']['name'] . ' (external)' : ds_person_name($p['person_id']);
            }
        }
        ds_audit($docId, 'document', $docId, $prevCycle ? 'resubmit' : 'submit', ($prevCycle ? 'Re-sent ' : 'Sent ') . $doc['ref_no'] . ' for signature (cycle ' . $cycle . ', '
            . count($resolved) . ' stage' . (count($resolved) === 1 ? '' : 's') . ($plan['workflow'] ? ', workflow "' . $plan['workflow'] . '"' : '') . ')'
            . ($resume && $carried ? '; ' . count($carried) . ' earlier decision(s) carried over' : ''),
            ['version' => (int) $ver['version_no'], 'sha256' => $ver['sha256'], 'signatories' => $who]);
        ds_audit($docId, 'version', (int) $ver['id'], 'lock', 'Version ' . $ver['version_no'] . ' locked for signing (SHA-256 ' . substr($ver['sha256'], 0, 16) . '…)');
        ds_advance($docId);
        return 'Sent for signature.';
    });
    $own = (int) $doc['owner_id'];
    if ($own !== ds_me()) {
        ds_notify([$own], 'submitted', $doc['ref_no'] . ' was sent for signature', '"' . $doc['title'] . '" was sent for signature by ' . ds_actor() . '.', $docId);
    }
    return $msg;
}

/**
 * Moves a document on: completes the active stage when it is done, opens the
 * next, finishes the document when none is left, and keeps its status in
 * step. Safe to call any time; called after every decision.
 */
function ds_advance(int $docId): void
{
    $doc = fabo_one('SELECT * FROM ds_documents WHERE id = ?', 'i', [$docId]);
    if (!$doc || !in_array($doc['status'], ds_open_statuses(), true)) {
        return;
    }
    $cycle = (int) $doc['cycle'];
    $guard = 0;
    while ($guard++ < 60) {
        $reqs = fabo_all('SELECT * FROM ds_signature_requests WHERE doc_id = ? AND cycle = ? ORDER BY stage_seq, seq', 'ii', [$docId, $cycle]);
        $stages = [];
        foreach ($reqs as $r) {
            $stages[(int) $r['stage_seq']][] = $r;
        }
        ksort($stages);
        $active = null;
        foreach ($stages as $seq => $rs) {
            if (!ds_stage_done($rs)) {
                $active = $seq;
                break;
            }
            // A finished stage leaves nobody still asked.
            foreach ($rs as $r) {
                if (in_array($r['status'], ['waiting', 'pending'], true)) {
                    fabo_exec('UPDATE ds_signature_requests SET status = "skipped", acted_at = NOW() WHERE id = ?', 'i', [(int) $r['id']]);
                    if ($r['status'] === 'pending') {
                        ds_audit($docId, 'request', (int) $r['id'], 'skipped', ds_req_name($r) . '\'s optional ' . $r['action'] . ' was no longer needed: "' . $r['stage_name'] . '" completed');
                    }
                }
            }
        }
        if ($active === null) {
            ds_complete($docId);
            return;
        }
        $waiting = array_filter($stages[$active], static fn($r) => $r['status'] === 'waiting');
        if (!$waiting) {
            break;                                  // the active stage is open; wait for decisions
        }
        foreach ($waiting as $r) {
            ds_activate_request($doc, $r);
        }
        break;
    }
    ds_status_refresh($docId);
}

/** Is a stage complete? "all": every mandatory one acted; "any": anyone did. */
function ds_stage_done(array $rs): bool
{
    $done = ds_done_statuses();
    if (($rs[0]['completion'] ?? 'all') === 'any') {
        foreach ($rs as $r) {
            if (in_array($r['status'], $done, true)) {
                return true;
            }
        }
        return false;
    }
    foreach ($rs as $r) {
        if ((int) $r['mandatory'] === 1 && !in_array($r['status'], $done, true)) {
            return false;
        }
    }
    return true;
}

/** When a request opened now is due: its days, or the document's deadline (end of that day) if sooner. On the database clock. */
function ds_due_for(array $doc, int $days): string
{
    $due = time() + 86400 * max(1, $days);
    if (!empty($doc['deadline'])) {
        $due = min($due, (int) strtotime($doc['deadline'] . ' 23:59:59'));
    }
    return ds_db_time($due);
}

/** Opens one request: a delegation is applied, the due date set, the person told. */
function ds_activate_request(array $doc, array $r): void
{
    if (!empty($r['external_id'])) {
        // Someone outside the organisation: a one-page signing link by e-mail.
        $days = (int) ($r['deadline_days'] ?? 0) ?: (int) ds_setting('deadline_days');
        $due = ds_due_for($doc, $days);
        if (!fabo_exec('UPDATE ds_signature_requests SET status = "pending", activated_at = NOW(), due_at = ? WHERE id = ? AND status = "waiting"', 'si', [$due, (int) $r['id']])) {
            return; // already opened (and the link sent) by a parallel step
        }
        ds_audit((int) $doc['id'], 'request', (int) $r['id'], 'requested', 'Asked ' . ds_req_name($r) . ' (external, ' . ds_external((int) $r['external_id'])['email'] . ') to '
            . ($r['action'] === 'approve' ? 'approve' : 'sign') . ' — "' . $r['stage_name'] . '", due ' . ds_fmt_dt($due));
        ds_guest_send_link((int) $r['id'], false);
        return;
    }
    $person = (int) $r['person_id'];
    $behalf = null;
    $del = ds_delegate_of($person, $doc['category_id'] ? (int) $doc['category_id'] : null);
    if ($del) {
        [$to, $row] = $del;
        $behalf = $person;
        $person = $to;
        ds_audit((int) $doc['id'], 'request', (int) $r['id'], 'delegated', ds_person_name($behalf) . '\'s request goes to ' . ds_person_name($to) . ' under delegation #' . $row['id']
            . ' (until ' . ds_fmt_date($row['ends_on']) . '). They act in their own name, on behalf of ' . ds_person_name($behalf) . '.');
    }
    $days = (int) ($r['deadline_days'] ?? 0) ?: (int) ds_setting('deadline_days');
    $due = ds_due_for($doc, $days);
    if (!fabo_exec('UPDATE ds_signature_requests SET status = "pending", person_id = ?, on_behalf_of_id = ?, activated_at = NOW(), due_at = ? WHERE id = ? AND status = "waiting"',
                   'iisi', [$person, $behalf, $due, (int) $r['id']])) {
        return; // already opened (and the person told) by a parallel step
    }
    $verb = ['sign' => 'sign', 'approve' => 'approve', 'review' => 'review'][$r['action']] ?? 'sign';
    ds_audit((int) $doc['id'], 'request', (int) $r['id'], 'requested', 'Asked ' . ds_person_name($person) . ($behalf ? ' (for ' . ds_person_name($behalf) . ')' : '') . ' to ' . $verb
        . ' — "' . $r['stage_name'] . '", due ' . ds_fmt_dt($due));
    ds_notify([$person], 'requested', 'Please ' . $verb . ': ' . $doc['ref_no'] . ' — ' . $doc['title'],
        ds_person_name((int) $doc['owner_id']) . ' needs you to ' . $verb . ' "' . $doc['title'] . '" (' . $doc['ref_no'] . ').'
        . ($behalf ? ' You are acting on behalf of ' . ds_person_name($behalf) . ' under a delegation.' : '')
        . "\nDue: " . ds_fmt_dt($due) . "\nPriority: " . ds_priorities()[$doc['priority']], (int) $doc['id'], ['p' => 'sign', 'id' => (int) $r['id']]);
}

/** Keeps the document's status in step with its requests. */
function ds_status_refresh(int $docId): void
{
    $doc = fabo_one('SELECT * FROM ds_documents WHERE id = ?', 'i', [$docId]);
    if (!$doc || !in_array($doc['status'], ds_open_statuses(), true)) {
        return;
    }
    $reqs = ds_doc_requests($docId, (int) $doc['cycle']);
    $pending = array_filter($reqs, static fn($r) => $r['status'] === 'pending');
    $signed = array_filter($reqs, static fn($r) => in_array($r['status'], ['signed', 'carried'], true) && $r['action'] === 'sign');
    $firstSignStage = null;
    foreach ($reqs as $r) {
        if ($r['action'] === 'sign') {
            $firstSignStage = (int) $r['stage_seq'];
            break;
        }
    }
    $status = 'pending';
    if ($pending && !array_filter($pending, static fn($r) => $r['action'] !== 'review')) {
        $status = 'under_review';
    } elseif ($signed) {
        $status = 'partial';
    } elseif ($pending && $firstSignStage !== null && (int) reset($pending)['stage_seq'] === $firstSignStage) {
        $status = 'pending_first';
    }
    if ($status !== $doc['status']) {
        fabo_exec('UPDATE ds_documents SET status = ? WHERE id = ?', 'si', [$status, $docId]);
    }
}

/** Has the document just been completed (fully signed)? */
function ds_doc_completed(int $docId): bool
{
    return (fabo_one('SELECT status FROM ds_documents WHERE id = ?', 'i', [$docId])['status'] ?? '') === 'signed';
}

/** Every stage done: the document is fully signed. The final file is checked and kept. */
function ds_complete(int $docId): void
{
    $doc = fabo_one('SELECT * FROM ds_documents WHERE id = ?', 'i', [$docId]);
    if (!fabo_exec('UPDATE ds_documents SET status = "signed", completed_at = NOW(), closed_at = NOW(), signed_file_id = working_file_id WHERE id = ? AND status <> "signed"', 'i', [$docId])) {
        return; // already completed: everyone was told then
    }
    ds_audit($docId, 'document', $docId, 'completed', $doc['ref_no'] . ' is fully signed: every mandatory signatory has acted');
    try {
        ds_verify_record($docId, (int) $doc['working_file_id'], 'auto');
    } catch (Throwable $e) {
        error_log('FABO esign completion verify: ' . $e->getMessage());
    }
    foreach (fabo_all('SELECT id FROM ds_signature_requests WHERE doc_id = ? AND cycle = ? AND external_id IS NOT NULL AND status IN ("signed","approved")', 'ii', [$docId, (int) $doc['cycle']]) as $x) {
        ds_guest_send_link((int) $x['id'], 'completed');
    }
    $people = ds_doc_participants($docId);
    ds_notify($people, 'completed', $doc['ref_no'] . ' is fully signed', '"' . $doc['title'] . '" has been signed by everyone. The signed PDF and its signature certificate can be downloaded from the portal.', $docId);
}

/* ============================================================= decisions */

/**
 * The request a viewer may act on now, or a refusal. Checks: theirs (never
 * someone else's, whatever their role), pending, the document open and on
 * the version they saw.
 */
function ds_request_mine(int $reqId, ?int $docVersion = null): array
{
    $r = fabo_one('SELECT * FROM ds_signature_requests WHERE id = ? AND org_id = ?', 'ii', [$reqId, ds_org()]);
    if (!$r) {
        fabo_deny('That signature request does not exist.', 404);
    }
    if (!empty($r['external_id']) || (int) $r['person_id'] !== ds_me()) {
        $doc = ds_doc($r['doc_id']);
        ds_audit((int) $r['doc_id'], 'request', $reqId, 'access_denied', ds_actor() . ' tried to act on a request addressed to ' . ds_req_name($r) . ($doc ? ' (' . $doc['ref_no'] . ')' : ''));
        fabo_deny('This request is addressed to ' . ds_req_name($r) . '. Nobody can sign on another person\'s behalf.', 403);
    }
    $doc = ds_doc((int) $r['doc_id']);
    if (!$doc) {
        fabo_deny('That document no longer exists.', 404);
    }
    if ($r['status'] !== 'pending') {
        throw new InvalidArgumentException('This request is ' . strtolower(ds_request_statuses()[$r['status']][0] ?? $r['status']) . '; there is nothing to do on it.');
    }
    if (!in_array($doc['status'], ds_open_statuses(), true) || (int) $r['cycle'] !== (int) $doc['cycle']) {
        throw new InvalidArgumentException('This document is no longer collecting signatures (' . strtolower(ds_doc_statuses()[$doc['status']][0]) . ').');
    }
    if ((int) $r['version_id'] !== (int) $doc['current_version_id']) {
        throw new InvalidArgumentException('The document has a newer version than the one this request was for.');
    }
    if ($docVersion !== null && (int) $docVersion !== (int) $doc['version']) {
        throw new InvalidArgumentException('The document changed while you had it open (another signature or a new version). Reload and review it again.');
    }
    if (!ds_writable()) {
        throw new InvalidArgumentException('Your organisation holds Digital Signatures read-only.');
    }
    return [$r, $doc];
}

/** Records a completed action on a request, sealed, and moves the document on. */
function ds_request_done(array $r, array $doc, string $status, string $method, string $remarks, array $evidence, string $summary): void
{
    $evidence += ['request' => (int) $r['id'], 'document' => $doc['ref_no'], 'person' => ds_req_name($r), 'person_id' => (int) $r['person_id'],
                  'on_behalf_of' => $r['on_behalf_of_id'] ? ds_person_name((int) $r['on_behalf_of_id']) : null, 'designation' => ds_designation((int) $r['person_id']),
                  'method' => $method, 'at' => date('c'), 'ip' => PHP_SAPI === 'cli' ? 'cli' : fabo_client_ip(),
                  'user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200), 'version_sha256' => (string) (fabo_one('SELECT sha256 FROM ds_document_versions WHERE id = ?', 'i', [(int) $r['version_id']])['sha256'] ?? '')];
    $seal = ds_seal($evidence);
    $n = fabo_exec('UPDATE ds_signature_requests SET status = ?, used_method = ?, acted_at = NOW(), remarks = ?, evidence_json = ?, seal = ? WHERE id = ? AND status = "pending"',
                   'sssssi', [$status, $method, $remarks !== '' ? mb_substr($remarks, 0, 1000) : null, json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $seal, (int) $r['id']]);
    if ($n < 1) {
        throw new InvalidArgumentException('This request was already decided.');
    }
    fabo_exec('UPDATE ds_documents SET version = version + 1, last_activity_at = NOW() WHERE id = ?', 'i', [(int) $doc['id']]);
    ds_audit((int) $doc['id'], 'request', (int) $r['id'], $status, $summary . ($remarks !== '' ? ' — "' . mb_substr($remarks, 0, 200) . '"' : ''), array_diff_key($evidence, ['user_agent' => 1]));
}

/** Review or electronic approval with no PDF change, or a rejection / changes request. */
function ds_decide(int $reqId, string $decision, string $remarks, $docVersion): string
{
    [$r, $doc] = ds_request_mine($reqId, (int) $docVersion);
    $remarks = ds_str($remarks, 1000, in_array($decision, ['reject', 'changes'], true), 'A reason');
    if ($decision === 'review') {
        if ($r['action'] !== 'review') {
            throw new InvalidArgumentException('This request asks you to ' . $r['action'] . ', not to review.');
        }
        ds_tx(static function () use ($r, $doc, $remarks) {
            ds_request_done($r, $doc, 'reviewed', 'review', $remarks, ['intent' => 'Reviewed and forwarded'], ds_req_name($r) . ' reviewed ' . $doc['ref_no']);
            ds_advance((int) $doc['id']);
        });
        return 'Marked as reviewed. The document moves on.';
    }
    if ($decision === 'reject' || $decision === 'changes') {
        $status = $decision === 'reject' ? 'rejected' : 'changes';
        $docStatus = $decision === 'reject' ? 'rejected' : 'changes_requested';
        ds_tx(static function () use ($r, $doc, $remarks, $status, $docStatus) {
            ds_request_done($r, $doc, $status, $r['action'], $remarks, ['intent' => $status === 'rejected' ? 'Rejected' : 'Requested changes'],
                ds_req_name($r) . ($status === 'rejected' ? ' rejected ' : ' requested changes to ') . $doc['ref_no']);
            fabo_exec('UPDATE ds_signature_requests SET status = "cancelled" WHERE doc_id = ? AND cycle = ? AND status IN ("waiting","pending")', 'ii', [(int) $doc['id'], (int) $doc['cycle']]);
            fabo_exec('UPDATE ds_signature_transactions SET status = "cancelled" WHERE doc_id = ? AND status IN ("initiated","awaiting")', 'i', [(int) $doc['id']]);
            fabo_exec('UPDATE ds_documents SET status = ? WHERE id = ?', 'si', [$docStatus, (int) $doc['id']]);
        });
        $others = array_diff(ds_doc_participants((int) $doc['id']), [ds_me()]);
        ds_notify($others, $decision === 'reject' ? 'rejected' : 'changes', $doc['ref_no'] . ($decision === 'reject' ? ' was rejected' : ': changes requested'),
            ds_actor() . ($decision === 'reject' ? ' rejected "' : ' asked for changes to "') . $doc['title'] . '": ' . $remarks, (int) $doc['id']);
        return $decision === 'reject' ? 'Rejected. The owner has been told.' : 'Changes requested. The owner has been told.';
    }
    throw new InvalidArgumentException('Unknown decision.');
}

/**
 * Takes a returned document back to the owner for changes: it can be
 * edited, given a new version, and sent again (a new cycle).
 */
function ds_reinitiate(int $docId, $version): string
{
    $doc = ds_doc_open($docId);
    if (!ds_doc_can_manage($doc)) {
        fabo_deny('You cannot reinitiate this document.', 403);
    }
    if (!in_array($doc['status'], ['rejected', 'changes_requested', 'expired', 'cancelled'], true)) {
        throw new InvalidArgumentException('Only a rejected, returned, expired or cancelled document can be reinitiated.');
    }
    $plan = json_decode((string) $doc['plan_json'], true) ?: [];
    if ($doc['status'] === 'rejected' && ($plan['rejection_rule'] ?? '') === 'final') {
        throw new InvalidArgumentException('This workflow treats a rejection as final. Create a new document instead.');
    }
    ds_tx(static function () use ($doc, $docId, $version) {
        ds_bump($docId, $version);
        fabo_exec('UPDATE ds_documents SET status = "reinitiated", closed_at = NULL WHERE id = ?', 'i', [$docId]);
        ds_audit($docId, 'document', $docId, 'reinitiate', 'Reinitiated ' . $doc['ref_no'] . ' from "' . ds_doc_statuses()[$doc['status']][0] . '": it can be corrected and sent again');
    });
    return 'Reinitiated. Make your changes (a new version if needed), then send it again.';
}

/* ================================================================ studio */

/**
 * Keeps the route chosen for a document while its sender places the fields
 * in the studio. Fields already placed for the same slots are kept, so going
 * back to change one signatory does not lose the rest.
 */
function ds_plan_prepare(int $docId, array $plan, array $chosen): void
{
    $doc = ds_doc_open($docId);
    if (!ds_doc_can_manage($doc)) {
        fabo_deny('You cannot send this document.', 403);
    }
    if (!in_array($doc['status'], ['draft', 'reinitiated', 'rejected', 'changes_requested'], true)) {
        throw new InvalidArgumentException('This document is ' . strtolower(ds_doc_statuses()[$doc['status']][0]) . '; it cannot be prepared now.');
    }
    $old = json_decode((string) ($doc['draft_plan_json'] ?: $doc['plan_json']), true) ?: [];
    $plan['chosen'] = $chosen;
    $plan['fields'] = $old['fields'] ?? [];
    fabo_exec('UPDATE ds_documents SET draft_plan_json = ? WHERE id = ?', 'si', [json_encode($plan, JSON_UNESCAPED_UNICODE), $docId]);
    ds_audit($docId, 'document', $docId, 'prepare', 'Chose the route for ' . $doc['ref_no'] . ($plan['workflow'] ? ' (workflow "' . $plan['workflow'] . '")' : '') . ' and opened the field studio');
}

/**
 * Who the studio places fields for: every slot of the plan, resolved now
 * as it would be on sending. slot "stage:index" => [stage, name, person,
 * action, method, mandatory, skipped (stage condition not met), error].
 */
function ds_studio_recipients(array $doc, array $plan): array
{
    $out = [];
    foreach ($plan['stages'] ?? [] as $si => $s) {
        $skip = !empty($s['condition']) && !ds_conditions_match($s['condition'], $doc);
        foreach ($s['signatories'] as $gi => $g) {
            $pid = null;
            $err = '';
            try {
                [$pid] = ds_resolve($g, $doc, (array) ($plan['chosen'] ?? []), $si, $gi);
            } catch (InvalidArgumentException $e) {
                $err = $e->getMessage();
            }
            $out[$si . ':' . $gi] = ['stage' => $si + 1, 'stage_name' => $s['name'], 'person_id' => $pid, 'name' => ($g['type'] ?? '') === 'external' ? $g['external']['name'] . ' (external)' : ($pid !== null ? ds_person_name($pid) : ($g['label'] ?: ($g['authority'] ?? 'Unresolved'))),
                                      'action' => $g['action'], 'method' => $g['method'], 'mandatory' => (int) $g['mandatory'], 'skip' => $skip, 'error' => $skip ? '' : $err];
        }
    }
    return $out;
}

/** Saves the studio's fields onto the draft plan (only for slots the plan has). */
function ds_studio_save(int $docId, array $fieldsBySlot): array
{
    $doc = ds_doc_open($docId);
    if (!ds_doc_can_manage($doc)) {
        fabo_deny('You cannot change this document.', 403);
    }
    $plan = json_decode((string) $doc['draft_plan_json'], true);
    if (!$plan) {
        throw new InvalidArgumentException('Choose who signs first.');
    }
    $slots = ds_studio_recipients($doc, $plan);
    $clean = [];
    foreach ($fieldsBySlot as $slot => $spots) {
        if (isset($slots[$slot]) && $slots[$slot]['action'] !== 'review' && ($f = ds_fields_clean($spots))) {
            $clean[$slot] = $f;
        }
    }
    $plan['fields'] = $clean;
    fabo_exec('UPDATE ds_documents SET draft_plan_json = ? WHERE id = ?', 'si', [json_encode($plan, JSON_UNESCAPED_UNICODE), $docId]);
    return $plan;
}

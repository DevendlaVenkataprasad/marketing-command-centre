<?php
/**
 * Leave approval steps: who has to approve, and in what order, before leave
 * is granted.
 *
 * HR sets a default chain (Policies & Rules → Leave → Approval steps) and,
 * where it should differ, a chain for one leave type — Maternity Leave might
 * need three approvals where Casual Leave needs one. Each step is one of:
 *
 *   manager  the employee's reporting manager
 *   skip     their manager's manager
 *   person   one named person (who must have a sign-in and an approving role)
 *   hr       anyone holding organisation-wide leave approval
 *   admin    the administrator
 *
 * When a request is submitted the chain is resolved for that employee — the
 * actual people — and stored on the request, so a later change of manager or
 * of the chain does not move a request already waiting. Steps that cannot
 * apply (no manager on record, or the approver is the requester) are left
 * out; if none remain, the administrator approves.
 *
 * The leave is deducted only when the last step approves. A rejection at
 * any step ends the request.
 */

function hr_chain_step_types(): array
{
    return [
        'manager' => 'Reporting manager',
        'skip'    => 'Manager\'s manager',
        'person'  => 'A specific person',
        'hr'      => 'Anyone in HR (organisation-wide approvers)',
        'admin'   => 'Administrator',
    ];
}

/** Steps from stored JSON: [['type' => ..., 'employee_id' => int|null], ...]. */
function hr_chain_parse($json): array
{
    $raw = is_string($json) && $json !== '' ? json_decode($json, true) : null;
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    foreach ($raw as $s) {
        $t = (string) ($s['type'] ?? '');
        if (!isset(hr_chain_step_types()[$t])) {
            continue;
        }
        $out[] = ['type' => $t, 'employee_id' => $t === 'person' ? (int) ($s['employee_id'] ?? 0) : null];
    }
    return $out;
}

/** The organisation's default chain. Before one is set, the earlier single setting decides; failing that, the administrator. */
function hr_chain_default(): array
{
    $c = hr_chain_parse(hr_setting('leave_approval_chain'));
    if ($c) {
        return $c;
    }
    return match ((string) hr_setting('leave_approval_flow')) {
        'manager'       => [['type' => 'manager', 'employee_id' => null]],
        'manager_admin' => [['type' => 'manager', 'employee_id' => null], ['type' => 'admin', 'employee_id' => null]],
        default         => [['type' => 'admin', 'employee_id' => null]],
    };
}

/** The chain a leave type uses: its own, or the default. */
function hr_chain_for_type(array $type): array
{
    return hr_chain_parse($type['approval_chain'] ?? null) ?: hr_chain_default();
}

/** "Reporting manager → HR → Administrator", for showing a chain in words. */
function hr_chain_words(array $steps): string
{
    $names = hr_chain_step_types();
    $parts = [];
    foreach ($steps as $s) {
        if ($s['type'] === 'person') {
            $e = $s['employee_id'] ? fabo_one('SELECT full_name FROM hr_employees WHERE id = ?', 'i', [(int) $s['employee_id']]) : null;
            $parts[] = $e ? $e['full_name'] : 'a person (not chosen)';
        } elseif ($s['type'] === 'hr') {
            $parts[] = 'HR';
        } else {
            $parts[] = $names[$s['type']];
        }
    }
    return implode(' → ', $parts);
}

/** Can this person approve at all? (Signed in, and holding Leave → Approve in some role.) */
function hr_emp_can_approve(int $empId): bool
{
    $e = fabo_one('SELECT person_id, org_id FROM hr_employees WHERE id = ?', 'i', [$empId]);
    if (!$e || !$e['person_id']) {
        return false;
    }
    return isset(hr_person_perms((int) $e['person_id'], (int) $e['org_id'])['leave']['approve']);
}

/**
 * Reads the chain editor's rows (step_type[], step_emp[]) and checks them.
 * Returns the steps, or throws with what to fix.
 */
function hr_chain_from_post(array $p): array
{
    $types = (array) ($p['step_type'] ?? []);
    $emps  = (array) ($p['step_emp'] ?? []);
    $steps = [];
    foreach ($types as $i => $t) {
        $t = (string) $t;
        if ($t === '') {
            continue;
        }
        if (!isset(hr_chain_step_types()[$t])) {
            throw new InvalidArgumentException('Choose who approves at each step.');
        }
        $eid = null;
        if ($t === 'person') {
            $eid = (int) ($emps[$i] ?? 0);
            $e = $eid ? hr_employee($eid) : null;
            if (!$e) {
                throw new InvalidArgumentException('Step ' . (count($steps) + 1) . ': choose the person who approves.');
            }
            if (!$e['person_id']) {
                throw new InvalidArgumentException($e['full_name'] . ' has no sign-in account, so they could never approve. '
                    . 'Give them dashboard access on their employee record first.');
            }
            if (!hr_emp_can_approve($eid)) {
                throw new InvalidArgumentException($e['full_name'] . ' cannot approve leave yet. Under Access Control, '
                    . 'give them a role with Leave → Approve (the Manager or HR staff template).');
            }
        }
        $step = ['type' => $t, 'employee_id' => $eid];
        if ($steps && end($steps) == $step) {
            continue;   // the same approver twice in a row is one step
        }
        $steps[] = $step;
    }
    if (!$steps) {
        throw new InvalidArgumentException('Add at least one approval step.');
    }
    if (count($steps) > 5) {
        throw new InvalidArgumentException('A request can need at most 5 approvals.');
    }
    return $steps;
}

/** Saves the default chain ($typeId null) or one leave type's chain. $steps null clears a type back to the default. */
function hr_chain_save(?int $typeId, ?array $steps): void
{
    $json = $steps === null ? null : json_encode($steps);
    if ($typeId === null) {
        $old = hr_chain_words(hr_chain_default());
        hr_setting_set('leave_approval_chain', $json);
        hr_audit('approval_chain', 'leave', null, 'Default leave approval steps: ' . hr_chain_words($steps ?? []), $old, $steps);
        return;
    }
    $type = hr_leave_type($typeId);
    if (!$type) {
        throw new InvalidArgumentException('That leave type no longer exists.');
    }
    $old = hr_chain_words(hr_chain_for_type($type));
    fabo_exec('UPDATE hr_leave_types SET approval_chain = ? WHERE id = ? AND org_id = ?', 'sii', [$json, $typeId, hr_org()]);
    $new = $steps === null ? 'Same as the default (' . hr_chain_words(hr_chain_default()) . ')' : hr_chain_words($steps);
    hr_config_log('leave', 'Approval steps for ' . $type['name'], $old, $new);
    hr_audit('approval_chain', 'leave_type', $typeId, 'Approval steps for ' . $type['name'] . ': ' . $new);
}

/**
 * The chain for one employee and leave type, with the actual approvers:
 * [['type', 'employee_id', 'label'], ...]. Never empty.
 */
function hr_chain_resolve(array $emp, array $type): array
{
    $out = [];
    foreach (hr_chain_for_type($type) as $s) {
        $id = null;
        $label = '';
        switch ($s['type']) {
            case 'manager':
                $id = $emp['manager_id'] ? (int) $emp['manager_id'] : null;
                if (!$id) { continue 2; }   // nobody on record: the step does not apply
                break;
            case 'skip':
                $m = $emp['manager_id'] ? fabo_one('SELECT manager_id FROM hr_employees WHERE id = ?', 'i', [(int) $emp['manager_id']]) : null;
                $id = $m && $m['manager_id'] ? (int) $m['manager_id'] : null;
                if (!$id) { continue 2; }
                break;
            case 'person':
                $id = (int) $s['employee_id'];
                $p = fabo_one('SELECT status FROM hr_employees WHERE id = ?', 'i', [$id]);
                if (!$p || !in_array($p['status'], hr_working_statuses(), true)) { continue 2; }
                break;
            case 'hr':
                $label = 'HR';
                break;
            case 'admin':
                $label = 'Administrator';
                break;
        }
        if ($id !== null) {
            if ($id === (int) $emp['id']) {
                continue;               // nobody approves their own leave
            }
            $label = (string) (fabo_one('SELECT full_name FROM hr_employees WHERE id = ?', 'i', [$id])['full_name'] ?? 'Approver');
        }
        $step = ['type' => in_array($s['type'], ['manager', 'skip', 'person'], true) ? 'person' : $s['type'], 'employee_id' => $id,
                 'label' => $label, 'as' => $s['type']];
        $last = $out ? end($out) : null;
        if ($last && $last['type'] === $step['type'] && $last['employee_id'] === $step['employee_id']) {
            continue;
        }
        $out[] = $step;
    }
    return $out ?: [['type' => 'admin', 'employee_id' => null, 'label' => 'Administrator', 'as' => 'admin']];
}

/** The resolved steps stored on a request. Requests from before steps existed get a stand-in of the right length. */
function hr_leave_chain(array $r): array
{
    $c = is_string($r['approval_chain'] ?? null) ? json_decode($r['approval_chain'], true) : null;
    if (is_array($c) && $c) {
        return $c;
    }
    $n = max(1, (int) ($r['levels_needed'] ?? 1));
    $emp = hr_employee((int) $r['employee_id']);
    $mgr = $emp && $emp['manager_id'] ? (int) $emp['manager_id'] : null;
    if ($n >= 2 && $mgr) {
        return array_merge([['type' => 'person', 'employee_id' => $mgr, 'label' => 'Reporting manager', 'as' => 'manager']],
                           array_fill(0, $n - 1, ['type' => 'hr', 'employee_id' => null, 'label' => 'HR', 'as' => 'hr']));
    }
    return array_fill(0, $n, ['type' => 'hr', 'employee_id' => null, 'label' => 'HR', 'as' => 'hr']);
}

/** The step a waiting request is at, or null. */
function hr_leave_step(array $r): ?array
{
    if (($r['status'] ?? '') !== 'submitted') {
        return null;
    }
    return hr_leave_chain($r)[(int) $r['level_done']] ?? null;
}

/** Is the viewer named anywhere in this request's chain? (Lets them open it even outside their usual scope.) */
function hr_leave_is_named_approver(array $r): bool
{
    $self = hr_self_emp();
    if (!$self || !hr_can('leave', 'approve')) {
        return false;
    }
    foreach (hr_leave_chain($r) as $s) {
        if ($s['type'] === 'person' && (int) $s['employee_id'] === (int) $self['id']) {
            return true;
        }
    }
    return false;
}

/** Employee ids who can act on a step (for notices). Empty for the administrator's step. */
function hr_leave_step_people(array $step): array
{
    if ($step['type'] === 'person') {
        return [(int) $step['employee_id']];
    }
    if ($step['type'] === 'hr') {
        $ids = [];
        foreach (fabo_all("SELECT DISTINCT e.id FROM hr_role_members m JOIN hr_roles r ON r.id = m.role_id AND r.active = 1 AND r.scope = 'org' AND r.org_id = ?
                           JOIN hr_role_perms p ON p.role_id = r.id AND p.module = 'leave' AND p.action = 'approve'
                           JOIN hr_employees e ON e.person_id = m.person_id", 'i', [hr_org()]) as $row) {
            $ids[] = (int) $row['id'];
        }
        return $ids;
    }
    return [];
}

/**
 * Tells whoever must act next that a request is waiting for them, by
 * portal notice and email. If nobody at that step can be reached — no
 * sign-in, no approving role — the administrator is told instead, so a
 * request never waits on someone who cannot see it.
 */
function hr_leave_notify_step(int $id): void
{
    $r = hr_leave_request($id);
    $step = $r ? hr_leave_step($r) : null;
    if (!$step) {
        return;
    }
    $chain = hr_leave_chain($r);
    $n = (int) $r['level_done'] + 1;
    $what = $r['full_name'] . ' (' . $r['emp_code'] . ') asked for ' . hr_fmt_days($r['days']) . ' day(s) of ' . $r['type_name'] . ', '
          . hr_fmt_date($r['start_date']) . ($r['end_date'] !== $r['start_date'] ? ' to ' . hr_fmt_date($r['end_date']) : '') . '.'
          . ($r['reason'] ? "\nReason: " . $r['reason'] : '')
          . "\nThis is approval " . $n . ' of ' . count($chain) . ($n > 1 ? ' — the earlier step(s) have approved.' : '.');
    $reached = 0;
    foreach (hr_leave_step_people($step) as $eid) {
        if ((int) $eid === (int) $r['employee_id']) {
            continue;
        }
        if (hr_emp_can_approve((int) $eid)) {
            hr_notify_employee((int) $eid, 'leave-step-' . $id . '-' . $n . '-v' . $r['version'], 'Leave request waiting for your approval', $what,
                               'hr.php?p=leave&id=' . $id);
            $reached++;
        }
    }
    if (!$reached) {
        $why = $step['type'] === 'admin' ? '' : "\n(" . ($step['label'] ?: 'The approver') . ' cannot approve in the portal, so this comes to you.)';
        hr_notify_admin('leave-step-' . $id . '-' . $n . '-v' . $r['version'], 'Leave request waiting for approval', $what . $why,
                        'admin/hr.php?p=leave&id=' . $id);
    }
}

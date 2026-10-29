<?php
/**
 * Finance Portal — the approval engine, one for every kind of document.
 *
 * RULES (Settings → Approval rules) say who approves what: per document
 * type, an amount band, optionally one entity or one cost centre, and an
 * approver — people holding a role, one named person, the submitter's
 * manager (from HR, else the cost centre's owner) or the cost centre's
 * owner. Rules at the same level run in parallel (all must approve); levels
 * run in order. A rule may need more than one approval (two of the CFO's
 * office, say).
 *
 * SUBMITTING a document turns the rules that apply into steps for that
 * version of it. Approve, reject (comment required) and return for
 * correction (comment required) are the only actions; each is recorded
 * with who, when, from where, on behalf of whom, against which version and
 * for what amount. A returned document is edited and resubmitted — a new
 * version, with a fresh set of steps — never approved as it was.
 *
 * SEPARATION OF DUTIES. Nobody approves their own expense claim, advance or
 * requisition, ever. With "strict" on (the default) nobody approves anything
 * they submitted, and one person cannot approve two levels of one document.
 * Every check is made here, on the server, on the action itself.
 *
 * WHEN NOBODY HOLDS THE ROLE a step names, the finance administrator may act
 * on it, so a document is never stranded — the step says so plainly.
 *
 * Each document type answers the engine through fp_wf_on_<type>($event, $doc,
 * $comment) with 'approved', 'rejected' or 'returned'.
 */

function fp_wf_doc_meta(string $type, array $doc): array
{
    $amount = (float) ($doc['total'] ?? $doc['amount'] ?? $doc['est_total'] ?? 0);
    if ($type === 'claim') {
        $amount = (float) $doc['total'];
    }
    $submitter = (int) ($doc['employee_person_id'] ?? $doc['requester_person_id'] ?? $doc['prepared_by_id'] ?? 0);
    return ['amount' => $amount, 'cc' => !empty($doc['cost_centre_id']) ? (int) $doc['cost_centre_id'] : null,
            'entity' => (int) ($doc['entity_id'] ?? 1), 'submitter' => $submitter, 'version' => (int) ($doc['version'] ?? 1)];
}

/** Types where approving your own is never allowed, whatever the setting. */
function fp_wf_never_self(): array
{
    return ['claim', 'advance', 'pr', 'payment'];
}

function fp_wf_rules_for(string $type, float $amount, ?int $cc, int $entity): array
{
    return fp_all('SELECT * FROM fp_wf_rules WHERE doc_type = ? AND active = 1 AND min_amount <= ? AND (max_amount IS NULL OR ? <= max_amount)
                   AND (entity_id IS NULL OR entity_id = ?) AND (cost_centre_id IS NULL OR cost_centre_id = ?) ORDER BY level, id',
                  'sddii', [$type, $amount, $amount, $entity, (int) $cc]);
}

/**
 * Starts approval for a document's current version. Returns 'pending', or
 * 'approved' when no step stands in the way (which the rules never allow
 * for long: with no rule at all a Finance approval step is used).
 * $skip lists approver types to leave out (imported HR claims have already
 * had their manager's approval in HR).
 */
function fp_wf_start(string $type, int $docId, float $amount, ?int $cc, int $entity, int $submitter, array $skip = []): string
{
    $doc = fp_doc($type, $docId);
    $version = (int) ($doc['version'] ?? 1);
    fp_exec('UPDATE fp_wf_steps SET status = "cancelled" WHERE doc_type = ? AND doc_id = ? AND status IN ("waiting","pending")', 'si', [$type, $docId]);
    $rules = array_values(array_filter(fp_wf_rules_for($type, $amount, $cc, $entity), static fn($r) => !in_array($r['approver_type'], $skip, true)));
    if (!$rules) {
        $rules = [['level' => 1, 'label' => 'Finance approval', 'approver_type' => 'role', 'approver_roles' => 'finance_manager,controller,cfo',
                   'approver_person_id' => null, 'required' => 1, 'escalate_hours' => null]];
    }
    foreach ($rules as $r) {
        fp_exec('INSERT INTO fp_wf_steps (doc_type, doc_id, doc_version, level, label, approver_type, approver_roles, approver_person_id, cost_centre_id, submitter_id,
                 required, escalate_hours) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', 'siiisssiiiii',
                [$type, $docId, $version, (int) $r['level'], (string) $r['label'], (string) $r['approver_type'], $r['approver_roles'] ?: null,
                 $r['approver_person_id'] ? (int) $r['approver_person_id'] : null, $cc, $submitter, max(1, (int) $r['required']),
                 $r['escalate_hours'] ? (int) $r['escalate_hours'] : null]);
    }
    fp_wf_event($type, $docId, $version, null, null, 'submit', '', $amount);
    return fp_wf_advance($type, $docId, $version);
}

/** Opens the next level, or finishes. Returns 'pending' or 'approved'. */
function fp_wf_advance(string $type, int $docId, int $version): string
{
    $open = fp_all('SELECT * FROM fp_wf_steps WHERE doc_type = ? AND doc_id = ? AND doc_version = ? AND status IN ("waiting","pending") ORDER BY level, id',
                   'sii', [$type, $docId, $version]);
    if (!$open) {
        $doc = fp_doc($type, $docId);
        fp_wf_event($type, $docId, $version, null, null, 'approved', 'All approvals given');
        $fn = 'fp_wf_on_' . $type;
        $fn('approved', $doc, '');
        $sub = fp_wf_doc_meta($type, $doc)['submitter'];
        fp_notify([$sub], fp_doc_types()[$type]['label'] . ' approved', fp_doc_title($type, $doc) . ' has been approved.', fp_doc_types()[$type]['page'], ['id' => $docId]);
        return 'approved';
    }
    $level = (int) $open[0]['level'];
    if (array_filter($open, static fn($s) => (int) $s['level'] === $level && $s['status'] === 'pending')) {
        return 'pending';   // this level is still being decided
    }
    $doc = fp_doc($type, $docId);
    foreach ($open as $s) {
        if ((int) $s['level'] !== $level) {
            continue;
        }
        fp_exec('UPDATE fp_wf_steps SET status = "pending", activated_at = NOW() WHERE id = ?', 'i', [(int) $s['id']]);
        $s['status'] = 'pending';
        [$people] = fp_wf_approvers($s);
        fp_notify($people, 'Approval needed: ' . fp_doc_types()[$type]['label'],
                  fp_doc_title($type, $doc) . ' is waiting for your approval (' . ($s['label'] ?: 'level ' . $level) . ').', fp_doc_types()[$type]['page'], ['id' => $docId]);
    }
    return 'pending';
}

/** A short name for a document: its number, party and amount. */
function fp_doc_title(string $type, array $doc): string
{
    $no = (string) ($doc[fp_doc_types()[$type]['no']] ?? ('#' . $doc['id']));
    $amt = $doc['total'] ?? $doc['amount'] ?? $doc['est_total'] ?? null;
    $who = $doc['employee_name'] ?? $doc['payee_name'] ?? $doc['requester'] ?? $doc['name'] ?? '';
    return trim($no . ($who !== '' ? ' — ' . $who : '') . ($amt !== null && (float) $amt != 0 ? ' (' . fp_money($amt) . ')' : ''));
}

/**
 * Who may act on a step: [people, fallback?]. fallback is true when nobody
 * holds what the step names and the finance administrator stands in.
 */
function fp_wf_approvers(array $s): array
{
    $sub = (int) $s['submitter_id'];
    $cc = $s['cost_centre_id'] ? (int) $s['cost_centre_id'] : null;
    $people = [];
    switch ($s['approver_type']) {
        case 'role':
            $people = fp_role_holders(array_filter(explode(',', (string) $s['approver_roles'])), $cc);
            break;
        case 'person':
            $people = $s['approver_person_id'] !== null ? [(int) $s['approver_person_id']] : [];
            break;
        case 'manager':
            $m = fp_hr_manager_of($sub);
            if ($m === null && $cc) {
                $m = fp_cc_owner($cc);
            }
            $people = $m !== null ? [$m] : ($cc ? fp_role_holders(['dept_manager'], $cc) : []);
            break;
        case 'cc_owner':
            $m = $cc ? fp_cc_owner($cc) : null;
            $people = $m !== null ? [$m] : ($cc ? fp_role_holders(['dept_manager'], $cc) : []);
            if (!$people && ($hm = fp_hr_manager_of($sub)) !== null) {
                $people = [$hm];
            }
            break;
    }
    $people = array_values(array_filter(array_unique($people), static fn($p) => $p !== $sub || !fp_wf_no_self((string) $s['doc_type'])));
    // Delegates stand in for whoever delegated to them, for the dates given.
    foreach ($people as $p) {
        foreach (fp_all('SELECT to_person_id, doc_types FROM fp_delegations WHERE active = 1 AND from_person_id = ? AND CURDATE() BETWEEN from_date AND to_date', 'i', [$p]) as $d) {
            $types = array_filter(explode(',', (string) $d['doc_types']));
            if (!$types || in_array($s['doc_type'], $types, true)) {
                $people[] = (int) $d['to_person_id'];
            }
        }
    }
    $people = array_values(array_unique(array_filter($people, static fn($p) => $p !== $sub || !fp_wf_no_self((string) $s['doc_type']))));
    if ($people) {
        return [$people, false];
    }
    // Nobody: the finance administrator(s) of the organisation.
    $admins = array_merge([fp_head_id()], fp_role_holders(['sysadmin']));
    $admins = array_values(array_unique(array_filter($admins, static fn($p) => $p !== $sub || !fp_wf_no_self((string) $s['doc_type']))));
    return [$admins, true];
}

/** Is approving one's own submission of this type refused? */
function fp_wf_no_self(string $type): bool
{
    return in_array($type, fp_wf_never_self(), true) || fp_setting('sod_strict') === '1';
}

function fp_cc_owner(int $cc): ?int
{
    $r = fp_one('SELECT manager_person_id FROM fp_cost_centres WHERE id = ?', 'i', [$cc]);
    return $r && $r['manager_person_id'] !== null ? (int) $r['manager_person_id'] : null;
}

/** May the viewer act on this step? [yes?, on behalf of (or null), why not]. */
function fp_wf_can_act(array $s): array
{
    if ($s['status'] !== 'pending') {
        return [false, null, 'This step is not waiting for a decision.'];
    }
    if (!fp_writable()) {
        return [false, null, 'This organisation\'s Finance is view only.'];
    }
    $me = fp_me();
    if ((int) $s['submitter_id'] === $me && fp_wf_no_self((string) $s['doc_type'])) {
        return [false, null, 'You submitted this, so someone else must approve it.'];
    }
    // One person, one approval per document version (strict separation of duties).
    if (fp_setting('sod_strict') === '1') {
        $already = fp_one('SELECT 1 FROM fp_wf_events WHERE doc_type = ? AND doc_id = ? AND doc_version = ? AND action = "approve" AND actor_id = ? AND step_id <> ? LIMIT 1',
                          'siiii', [$s['doc_type'], (int) $s['doc_id'], (int) $s['doc_version'], $me, (int) $s['id']]);
        if ($already) {
            return [false, null, 'You approved an earlier level of this document; another approver must take this one.'];
        }
    }
    if (fp_one('SELECT 1 FROM fp_wf_events WHERE step_id = ? AND action = "approve" AND actor_id = ? LIMIT 1', 'ii', [(int) $s['id'], $me])) {
        return [false, null, 'You have already approved this step.'];
    }
    [$people, $fallback] = fp_wf_approvers($s);
    if (in_array($me, $people, true)) {
        // Was it delegated? Then record whose approval this stands for.
        $direct = $fallback ? [] : fp_wf_direct_approvers($s);
        if (!$fallback && !in_array($me, $direct, true)) {
            $from = fp_one('SELECT from_person_id FROM fp_delegations WHERE active = 1 AND to_person_id = ? AND CURDATE() BETWEEN from_date AND to_date LIMIT 1', 'i', [$me]);
            return [true, $from ? (int) $from['from_person_id'] : null, ''];
        }
        return [true, null, ''];
    }
    if (!empty(fp_viewer()['is_admin']) && $fallback) {
        return [true, null, ''];
    }
    return [false, null, 'You are not an approver for this step.'];
}

/** The approvers a step names directly, without delegates or fallback. */
function fp_wf_direct_approvers(array $s): array
{
    $copy = $s;
    $sub = (int) $s['submitter_id'];
    switch ($s['approver_type']) {
        case 'role':
            return fp_role_holders(array_filter(explode(',', (string) $s['approver_roles'])), $s['cost_centre_id'] ? (int) $s['cost_centre_id'] : null);
        case 'person':
            return [(int) $s['approver_person_id']];
        default:
            $m = $s['approver_type'] === 'manager' ? fp_hr_manager_of($sub) : null;
            $cc = $copy['cost_centre_id'] ? (int) $copy['cost_centre_id'] : null;
            if ($m === null && $cc) {
                $m = fp_cc_owner($cc);
            }
            return $m !== null ? [$m] : ($cc ? fp_role_holders(['dept_manager'], $cc) : []);
    }
}

/**
 * Approve, reject or return a document. $version is the version the
 * approver was looking at: if the document changed since, the decision is
 * refused — nobody approves something they have not seen.
 */
function fp_wf_act(string $type, int $docId, string $action, string $comment, $version): string
{
    if (!in_array($action, ['approve', 'reject', 'return'], true)) {
        throw new InvalidArgumentException('Choose approve, reject or return.');
    }
    $comment = fp_str($comment, 1000, $action !== 'approve', $action === 'reject' ? 'A reason for rejecting' : 'What needs correcting');
    $meta = fp_doc_types()[$type] ?? null;
    if (!$meta) {
        throw new InvalidArgumentException('Unknown document.');
    }
    return fp_tx(static function () use ($type, $docId, $action, $comment, $version, $meta) {
        $doc = fp_one('SELECT * FROM ' . $meta['table'] . ' WHERE id = ? FOR UPDATE', 'i', [$docId]);
        if (!$doc) {
            throw new InvalidArgumentException('That document no longer exists.');
        }
        if ((int) $doc['version'] !== (int) $version) {
            throw new InvalidArgumentException('This document was changed after you opened it. Reload it and review it again before deciding.');
        }
        $steps = fp_all('SELECT * FROM fp_wf_steps WHERE doc_type = ? AND doc_id = ? AND doc_version = ? AND status = "pending" ORDER BY id FOR UPDATE',
                        'sii', [$type, $docId, (int) $doc['version']]);
        $step = null;
        $why = 'Nothing is waiting for a decision on this document.';
        foreach ($steps as $s) {
            [$ok, $behalf, $reason] = fp_wf_can_act($s);
            if ($ok) {
                $step = $s + ['_behalf' => $behalf];
                break;
            }
            $why = $reason;
        }
        if (!$step) {
            error_log('FABO finance approval refused ' . fp_actor() . ' ' . $type . '#' . $docId . ': ' . $why);
            fabo_deny($why, 403);
        }
        $amount = fp_wf_doc_meta($type, $doc)['amount'];
        $title = fp_doc_title($type, $doc);
        $sub = (int) $step['submitter_id'];
        if ($action === 'approve') {
            fp_wf_event($type, $docId, (int) $doc['version'], (int) $step['id'], (int) $step['level'], 'approve', $comment, $amount, $step['_behalf']);
            $n = (int) $step['approvals'] + 1;
            fp_exec('UPDATE fp_wf_steps SET approvals = ?, status = IF(? >= required, "approved", "pending"), done_at = IF(? >= required, NOW(), NULL) WHERE id = ?',
                    'iiii', [$n, $n, $n, (int) $step['id']]);
            fp_audit($type, $docId, 'approve', $title . ' approved at ' . ($step['label'] ?: 'level ' . $step['level']) . ($comment ? ': ' . $comment : ''));
            $state = fp_wf_advance($type, $docId, (int) $doc['version']);
            return $state === 'approved' ? 'Approved — ' . strtolower($meta['label']) . ' is now fully approved.' : 'Approved. It moves to the next approver.';
        }
        fp_wf_event($type, $docId, (int) $doc['version'], (int) $step['id'], (int) $step['level'], $action, $comment, $amount, $step['_behalf']);
        fp_exec('UPDATE fp_wf_steps SET status = ?, done_at = NOW() WHERE id = ?', 'si', [$action === 'reject' ? 'rejected' : 'returned', (int) $step['id']]);
        fp_exec('UPDATE fp_wf_steps SET status = "cancelled" WHERE doc_type = ? AND doc_id = ? AND doc_version = ? AND status IN ("waiting","pending")',
                'sii', [$type, $docId, (int) $doc['version']]);
        $fn = 'fp_wf_on_' . $type;
        $fn($action === 'reject' ? 'rejected' : 'returned', $doc, $comment);
        // The document itself moves to a new version, so a later edit is reviewed afresh.
        fp_exec('UPDATE ' . $meta['table'] . ' SET version = version + 1 WHERE id = ?', 'i', [$docId]);
        fp_audit($type, $docId, $action, $title . ($action === 'reject' ? ' rejected: ' : ' returned for correction: ') . $comment);
        fp_notify([$sub], $meta['label'] . ($action === 'reject' ? ' rejected' : ' returned for correction'),
                  $title . ($action === 'reject' ? ' was rejected by ' : ' was sent back by ') . fp_actor() . '. Reason: ' . $comment, $meta['page'], ['id' => $docId]);
        return $action === 'reject' ? 'Rejected. The submitter has been told why.' : 'Returned for correction. The submitter has been told what to fix.';
    });
}

function fp_wf_event(string $type, int $docId, int $version, ?int $stepId, ?int $level, string $action, string $comment = '', ?float $amount = null, ?int $behalf = null): void
{
    fp_exec('INSERT INTO fp_wf_events (doc_type, doc_id, doc_version, step_id, level, action, comment, actor_id, actor, on_behalf_of, amount, ip) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
            'siiiissisiss', [$type, $docId, $version, $stepId, $level, $action, $comment !== '' ? $comment : null, fp_me(), fp_actor(), $behalf,
                             $amount !== null ? fp_amt($amount) : null, function_exists('fabo_client_ip') ? fabo_client_ip() : null]);
}

/** Stops approval of a document being withdrawn or cancelled. */
function fp_wf_cancel(string $type, int $docId, string $why = 'Withdrawn'): void
{
    $n = fp_exec('UPDATE fp_wf_steps SET status = "cancelled" WHERE doc_type = ? AND doc_id = ? AND status IN ("waiting","pending")', 'si', [$type, $docId]);
    if ($n) {
        $doc = fp_doc($type, $docId);
        fp_wf_event($type, $docId, (int) ($doc['version'] ?? 1), null, null, 'withdraw', $why);
    }
}

function fp_wf_steps(string $type, int $docId): array
{
    $doc = fp_doc($type, $docId);
    return fp_all('SELECT * FROM fp_wf_steps WHERE doc_type = ? AND doc_id = ? AND doc_version >= ? - 1 AND status <> "cancelled" ORDER BY doc_version DESC, level, id',
                  'sii', [$type, $docId, (int) ($doc['version'] ?? 1)]);
}

function fp_wf_history(string $type, int $docId): array
{
    return fp_all('SELECT * FROM fp_wf_events WHERE doc_type = ? AND doc_id = ? ORDER BY id', 'si', [$type, $docId]);
}

/** The latest reason a document was returned or rejected, for the person who has to act on it. */
function fp_wf_last_reason(string $type, int $docId): ?array
{
    return fp_one('SELECT * FROM fp_wf_events WHERE doc_type = ? AND doc_id = ? AND action IN ("return","reject") ORDER BY id DESC LIMIT 1', 'si', [$type, $docId]);
}

/** Is the viewer an approver of (or a past decider on) this document? Lets them read it. */
function fp_wf_is_approver_of(string $type, int $docId): bool
{
    $me = fp_me();
    if (fp_one('SELECT 1 FROM fp_wf_events WHERE doc_type = ? AND doc_id = ? AND actor_id = ? AND action IN ("approve","reject","return") LIMIT 1', 'sii', [$type, $docId, $me])) {
        return true;
    }
    foreach (fp_all('SELECT * FROM fp_wf_steps WHERE doc_type = ? AND doc_id = ? AND status = "pending"', 'si', [$type, $docId]) as $s) {
        if (fp_wf_can_act($s)[0]) {
            return true;
        }
    }
    return false;
}

/** Everything waiting for the viewer's decision, across every document type. */
function fp_wf_inbox(int $limit = 200): array
{
    $out = [];
    foreach (fp_all('SELECT * FROM fp_wf_steps WHERE status = "pending" ORDER BY activated_at LIMIT 1000') as $s) {
        if (!fp_wf_can_act($s)[0]) {
            continue;
        }
        $doc = fp_doc((string) $s['doc_type'], (int) $s['doc_id']);
        if (!$doc || (int) $doc['version'] !== (int) $s['doc_version']) {
            continue;
        }
        $key = $s['doc_type'] . ':' . $s['doc_id'];
        if (isset($out[$key])) {
            continue;
        }
        $out[$key] = ['step' => $s, 'doc' => $doc, 'type' => (string) $s['doc_type'], 'title' => fp_doc_title((string) $s['doc_type'], $doc),
                      'amount' => fp_wf_doc_meta((string) $s['doc_type'], $doc)['amount'], 'since' => $s['activated_at']];
        if (count($out) >= $limit) {
            break;
        }
    }
    return array_values($out);
}

/** Every document anywhere still waiting for someone: for the dashboard. */
function fp_wf_pending_all(): array
{
    return fp_all('SELECT s.doc_type, COUNT(DISTINCT s.doc_id) n, MIN(s.activated_at) oldest FROM fp_wf_steps s WHERE s.status = "pending" GROUP BY s.doc_type');
}

/**
 * Reminds approvers once a day, and escalates a step that has waited longer
 * than its rule (or the organisation's setting) allows: the Finance Manager
 * and CFO are told, and the step is marked escalated. Run by the scheduler.
 */
function fp_wf_escalate(): int
{
    $n = 0;
    $def = max(1, (int) fp_setting('escalate_hours'));
    foreach (fp_all('SELECT * FROM fp_wf_steps WHERE status = "pending" AND activated_at IS NOT NULL') as $s) {
        $doc = fp_doc((string) $s['doc_type'], (int) $s['doc_id']);
        if (!$doc) {
            continue;
        }
        $hours = (int) ($s['escalate_hours'] ?: $def);
        $age = (time() - strtotime((string) $s['activated_at'])) / 3600;
        [$people] = fp_wf_approvers($s);
        $label = fp_doc_types()[$s['doc_type']]['label'];
        if ($age >= $hours && !$s['escalated_at']) {
            $up = fp_role_holders(['finance_manager', 'cfo']);
            fp_notify(array_merge($people, $up ?: [fp_head_id()]), 'Overdue approval: ' . $label,
                      fp_doc_title((string) $s['doc_type'], $doc) . ' has waited ' . (int) $age . ' hours for ' . ($s['label'] ?: 'approval') . '.',
                      fp_doc_types()[$s['doc_type']]['page'], ['id' => (int) $s['doc_id']]);
            fp_exec('UPDATE fp_wf_steps SET escalated_at = NOW(), reminded_at = NOW() WHERE id = ?', 'i', [(int) $s['id']]);
            fp_wf_event((string) $s['doc_type'], (int) $s['doc_id'], (int) $s['doc_version'], (int) $s['id'], (int) $s['level'], 'escalate', 'Waiting ' . (int) $age . ' hours');
            $n++;
        } elseif ($age >= 24 && (!$s['reminded_at'] || strtotime((string) $s['reminded_at']) < time() - 86400)) {
            fp_notify($people, 'Reminder: approval waiting', fp_doc_title((string) $s['doc_type'], $doc) . ' is still waiting for your decision.',
                      fp_doc_types()[$s['doc_type']]['page'], ['id' => (int) $s['doc_id']]);
            fp_exec('UPDATE fp_wf_steps SET reminded_at = NOW() WHERE id = ?', 'i', [(int) $s['id']]);
            $n++;
        }
    }
    return $n;
}

/* ================================================================ rules */

function fp_wf_rule_save(?int $id, array $p): int
{
    fp_need('settings.manage');
    $type = (string) ($p['doc_type'] ?? '');
    if (!isset(fp_doc_types()[$type]) || in_array($type, ['receipt', 'asset', 'control', 'evidence'], true)) {
        throw new InvalidArgumentException('Choose which document this rule is for.');
    }
    $level = max(1, min(9, (int) ($p['level'] ?? 1)));
    $min = fp_dec($p['min_amount'] ?? '0', false, 'From amount', 0) ?? '0.00';
    $max = fp_dec($p['max_amount'] ?? '', false, 'Up to amount', 0);
    if ($max !== null && (float) $max < (float) $min) {
        throw new InvalidArgumentException('"Up to" must be at least "from".');
    }
    $atype = (string) ($p['approver_type'] ?? 'role');
    if (!in_array($atype, ['role', 'person', 'manager', 'cc_owner'], true)) {
        throw new InvalidArgumentException('Choose who approves.');
    }
    $roles = null;
    $person = null;
    if ($atype === 'role') {
        $roles = array_values(array_intersect((array) ($p['approver_roles'] ?? []), array_keys(fp_roles())));
        $roles = array_values(array_diff($roles, ['employee']));
        if (!$roles) {
            throw new InvalidArgumentException('Choose at least one role that approves.');
        }
        $roles = implode(',', $roles);
    } elseif ($atype === 'person') {
        $person = (int) ($p['approver_person_id'] ?? -1);
        if (!fp_is_member($person)) {
            throw new InvalidArgumentException('Choose the person who approves.');
        }
    }
    $label = fp_str($p['label'] ?? '', 80, true, 'Step name');
    $entity = fp_ref($p['entity_id'] ?? null, 'fp_entities', 'entity');
    $cc = fp_ref($p['cost_centre_id'] ?? null, 'fp_cost_centres', 'cost centre');
    $req = max(1, min(5, (int) ($p['required'] ?? 1)));
    $esc = (int) ($p['escalate_hours'] ?? 0) ?: null;
    $active = !isset($p['active']) || !empty($p['active']) ? 1 : 0;
    $vals = [$type, $level, $label, $min, $max, $entity, $cc, $atype, $roles, $person, $req, $esc, $active];
    if ($id) {
        $before = fp_one('SELECT * FROM fp_wf_rules WHERE id = ?', 'i', [$id]);
        fp_exec('UPDATE fp_wf_rules SET doc_type=?, level=?, label=?, min_amount=?, max_amount=?, entity_id=?, cost_centre_id=?, approver_type=?, approver_roles=?,
                 approver_person_id=?, required=?, escalate_hours=?, active=? WHERE id=?', 'sisssiissiiiii', array_merge($vals, [$id]));
        fp_audit('wf_rule', $id, 'update', 'Approval rule changed: ' . $type . ' level ' . $level . ' ' . $label, $before, fp_one('SELECT * FROM fp_wf_rules WHERE id = ?', 'i', [$id]));
        return $id;
    }
    $id = fp_exec('INSERT INTO fp_wf_rules (doc_type, level, label, min_amount, max_amount, entity_id, cost_centre_id, approver_type, approver_roles, approver_person_id,
                   required, escalate_hours, active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', 'sisssiissiiii', $vals);
    fp_audit('wf_rule', $id, 'create', 'Approval rule added: ' . $type . ' level ' . $level . ' ' . $label);
    return $id;
}

function fp_delegation_save(array $p): int
{
    $from = (int) ($p['from_person_id'] ?? fp_me());
    if ($from !== fp_me() && !fp_can('settings.manage')) {
        fabo_deny('You can delegate only your own approvals.', 403);
    }
    $to = (int) ($p['to_person_id'] ?? 0);
    if (!fp_is_member($to) || $to === $from) {
        throw new InvalidArgumentException('Choose someone else in your organisation to approve for you.');
    }
    $a = fp_date($p['from_date'] ?? '', true, 'From');
    $b = fp_date($p['to_date'] ?? '', true, 'To');
    if ($b < $a) {
        throw new InvalidArgumentException('The delegation must end on or after it starts.');
    }
    $types = array_values(array_intersect((array) ($p['doc_types'] ?? []), array_keys(fp_doc_types())));
    $id = fp_exec('INSERT INTO fp_delegations (from_person_id, to_person_id, from_date, to_date, doc_types, reason, created_by) VALUES (?,?,?,?,?,?,?)',
                  'iisssss', [$from, $to, $a, $b, $types ? implode(',', $types) : null, fp_str($p['reason'] ?? '', 255) ?: null, fp_actor()]);
    fp_audit('delegation', $id, 'create', fp_person_name($from) . ' delegated approvals to ' . fp_person_name($to) . ' from ' . $a . ' to ' . $b);
    return $id;
}

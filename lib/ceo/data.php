<?php
/**
 * CEO Dashboard — everything that is more than a single figure: the action
 * queue, the activity feed, the trend, the hub-and-spoke comparison, the
 * department view and targets against actuals.
 *
 * Raw items are gathered per organisation and held briefly; they are cut down
 * to the person in ceo_item_for(), every time. An item keeps its own words only
 * for someone who may open its module and holds any sensitive category it
 * touches. Everyone else gets the neutral label, so a title can never leak what
 * a record contains.
 */

require_once __DIR__ . '/kpis.php';

/** Item: [module, type, label (neutral), detail (its own words), status, due, age, owner, owner_id, spoke, link query, sensitive]. */
function ceo_item(string $m, string $type, string $label, string $detail, string $status, ?string $due, ?string $when, string $owner, int $ownerId, string $spoke, array $link, ?string $sens = null): array
{
    return ['module' => $m, 'type' => $type, 'label' => $label, 'detail' => $detail, 'status' => $status, 'due' => $due, 'when' => $when,
            'owner' => $owner, 'owner_id' => $ownerId, 'spoke' => $spoke, 'link' => $link, 'sens' => $sens];
}

/** The queue for an organisation: every open thing that wants a person, from each module that has one. */
function ceo_actions_raw(int $org): array
{
    $items = [];
    $today = ceo_today();
    $soon = date('Y-m-d', strtotime($today . ' +14 days'));
    $ok = static fn(string $m) => ceo_module_state($org, $m) === 'ok';
    $guard = static function (string $m, callable $fn) use (&$items, $ok) {
        if (!$ok($m)) {
            return;
        }
        try {
            $items = array_merge($items, $fn());
        } catch (Throwable $e) {
            error_log('FABO ceo actions ' . $m . ': ' . $e->getMessage());
        }
    };

    $guard('fin', static function () use ($org) {
        require_once __DIR__ . '/../fin/core.php';
        $types = fp_doc_types();
        $out = [];
        foreach (fabo_all('SELECT id, doc_type, doc_id, label, activated_at, escalated_at FROM `' . ceo_t('fp_wf_steps', $org) . "` WHERE status = 'pending' ORDER BY activated_at LIMIT 200") as $s) {
            $t = $types[$s['doc_type']] ?? null;
            $name = $t['label'] ?? 'Document';
            $out[] = ceo_item('fin', 'approval', $name . ' awaiting approval', $name . ($s['label'] !== '' ? ' — ' . $s['label'] : ''), $s['escalated_at'] ? 'Escalated' : 'Pending',
                              null, $s['activated_at'], '', 0, '', ['p' => $t['page'] ?? 'approvals', 'id' => (int) $s['doc_id']], 'finance');
        }
        return $out;
    });

    $guard('hr', static function () use ($org, $today) {
        $out = [];
        foreach (fabo_all("SELECT x.id, x.start_date, x.days, x.submitted_at, e.full_name, e.person_id, m.name loc FROM hr_leave_requests x JOIN hr_employees e ON e.id = x.employee_id
                             LEFT JOIN hr_masters m ON m.id = e.location_id WHERE x.org_id = ? AND x.status = 'submitted' ORDER BY x.submitted_at LIMIT 100", 'i', [$org]) as $r) {
            $out[] = ceo_item('hr', 'approval', 'Leave request awaiting a decision', $r['full_name'] . ' — ' . rtrim(rtrim((string) $r['days'], '0'), '.') . ' day(s) from ' . $r['start_date'],
                              'Pending', (string) $r['start_date'], $r['submitted_at'], (string) $r['full_name'], (int) $r['person_id'], (string) $r['loc'], ['p' => 'leave', 'id' => (int) $r['id']], 'personal');
        }
        foreach (fabo_all("SELECT x.id, x.number, x.created_at, e.full_name, e.person_id, m.name loc FROM hr_claims x JOIN hr_employees e ON e.id = x.employee_id
                             LEFT JOIN hr_masters m ON m.id = e.location_id WHERE x.org_id = ? AND x.status = 'submitted' ORDER BY x.created_at LIMIT 100", 'i', [$org]) as $r) {
            $out[] = ceo_item('hr', 'approval', 'Expense claim awaiting a decision', $r['number'] . ' — ' . $r['full_name'], 'Pending', null, $r['created_at'], (string) $r['full_name'], (int) $r['person_id'],
                              (string) $r['loc'], ['p' => 'claims'], 'personal');
        }
        foreach (fabo_all("SELECT x.number, x.subject, x.status, x.created_at, x.assigned_to, e.full_name, m.name loc FROM hr_tickets x JOIN hr_employees e ON e.id = x.employee_id
                             LEFT JOIN hr_masters m ON m.id = e.location_id WHERE x.org_id = ? AND x.status NOT IN ('resolved','closed') ORDER BY x.created_at LIMIT 100", 'i', [$org]) as $r) {
            $out[] = ceo_item('hr', 'ticket', 'HR ticket unresolved', $r['number'] . ' — ' . $r['subject'], ucfirst((string) $r['status']), null, $r['created_at'], (string) $r['assigned_to'], 0,
                              (string) $r['loc'], ['p' => 'helpdesk'], 'personal');
        }
        foreach (fabo_all("SELECT x.title, x.due_date, x.kind, e.full_name, m.name loc FROM hr_lifecycle_tasks x JOIN hr_employees e ON e.id = x.employee_id LEFT JOIN hr_masters m ON m.id = e.location_id
                            WHERE x.org_id = ? AND x.status = 'pending' AND x.due_date < ? ORDER BY x.due_date LIMIT 100", 'is', [$org, $today]) as $r) {
            $out[] = ceo_item('hr', 'overdue', ucfirst((string) $r['kind']) . ' task overdue', $r['title'] . ' — ' . $r['full_name'], 'Overdue', (string) $r['due_date'], null, '', 0, (string) $r['loc'], ['p' => 'lifecycle'], 'personal');
        }
        return $out;
    });

    foreach (['it' => 'IT ticket', 'fa' => 'Facilities request'] as $desk => $word) {
        $guard($desk, static function () use ($org, $desk, $word, $today, $soon) {
            $out = [];
            foreach (fabo_all('SELECT t.id, t.ref, t.title, t.priority, t.status, t.due_at, t.created_at, t.assignee_id, c.name who FROM `' . ceo_t($desk . '_tickets', $org) . '` t
                                 LEFT JOIN clients c ON c.id = t.assignee_id WHERE t.status IN ("new","in_progress","waiting") ORDER BY t.due_at IS NULL, t.due_at LIMIT 200') as $r) {
                $late = $r['due_at'] !== null && $r['due_at'] < date('Y-m-d H:i:s');
                $out[] = ceo_item($desk, $late ? 'overdue' : 'ticket', $word . ($late ? ' overdue' : ' open'), trim($r['ref'] . ' — ' . $r['title'], ' —'), $late ? 'Overdue' : ucfirst(str_replace('_', ' ', (string) $r['status'])),
                                  $r['due_at'] ? substr((string) $r['due_at'], 0, 10) : null, $r['created_at'], (string) $r['who'], (int) $r['assignee_id'], '', ['p' => 'ticket', 'id' => (int) $r['id']]);
            }
            if ($desk === 'it') {
                foreach (fabo_all('SELECT id, name, renews_on, owner FROM `' . ceo_t('it_renewals', $org) . "` WHERE status = 'active' AND renews_on <= ? ORDER BY renews_on LIMIT 50", 's', [$soon]) as $r) {
                    $out[] = ceo_item('it', 'deadline', 'Renewal coming up', (string) $r['name'], $r['renews_on'] < $today ? 'Overdue' : 'Due soon', (string) $r['renews_on'], null, (string) $r['owner'], 0, '', ['p' => 'renewals'], 'contracts');
                }
            }
            return $out;
        });
    }

    $guard('esign', static function () use ($org) {
        $out = [];
        foreach (fabo_all("SELECT r.doc_id, r.due_at, r.activated_at, r.person_id, d.title, d.ref_no, d.unit, d.confidentiality, c.name who FROM ds_signature_requests r JOIN ds_documents d ON d.id = r.doc_id
                             LEFT JOIN clients c ON c.id = r.person_id WHERE r.org_id = ? AND r.status = 'pending' ORDER BY r.due_at IS NULL, r.due_at LIMIT 200", 'i', [$org]) as $r) {
            $late = $r['due_at'] !== null && $r['due_at'] < date('Y-m-d H:i:s');
            $out[] = ceo_item('esign', 'signature', $late ? 'Signature overdue' : 'Signature pending', $r['ref_no'] . ' — ' . $r['title'], $late ? 'Overdue' : 'Pending', $r['due_at'] ? substr((string) $r['due_at'], 0, 10) : null,
                              $r['activated_at'], (string) $r['who'], (int) $r['person_id'], (string) $r['unit'], ['p' => 'doc', 'id' => (int) $r['doc_id']], $r['confidentiality'] === 'internal' ? null : 'contracts');
        }
        return $out;
    });

    $guard('inc', static function () use ($org, $today) {
        $A = ceo_t('inc_applications', $org);
        $M = ceo_t('inc_masters', $org);
        $out = [];
        foreach (fabo_all('SELECT t.id, t.title, t.due_date, t.application_id, t.assignee_id, c.name who, a.startup_name, m.name spoke FROM `' . ceo_t('inc_tasks', $org) . "` t
                             LEFT JOIN `$A` a ON a.id = t.application_id LEFT JOIN `$M` m ON m.id = a.spoke_id LEFT JOIN clients c ON c.id = t.assignee_id
                            WHERE t.status = 'open' AND t.due_date IS NOT NULL ORDER BY t.due_date LIMIT 200") as $r) {
            $late = $r['due_date'] < $today;
            $out[] = ceo_item('inc', $late ? 'overdue' : 'deadline', $late ? 'Incubation task overdue' : 'Incubation task due', trim($r['title'] . ' — ' . $r['startup_name'], ' —'), $late ? 'Overdue' : 'Open', (string) $r['due_date'], null,
                              (string) $r['who'], (int) $r['assignee_id'], (string) $r['spoke'], $r['application_id'] ? ['p' => 'application', 'id' => (int) $r['application_id']] : ['p' => 'applications']);
        }
        foreach (fabo_all('SELECT w.id, w.target_date, w.owner_id, a.startup_name, m.name spoke, c.name who FROM `' . ceo_t('inc_workspaces', $org) . "` w JOIN `$A` a ON a.id = w.application_id
                             LEFT JOIN `$M` m ON m.id = a.spoke_id LEFT JOIN clients c ON c.id = w.owner_id WHERE w.closed_at IS NULL AND w.target_date < ? ORDER BY w.target_date LIMIT 100", 's', [$today]) as $r) {
            $out[] = ceo_item('inc', 'milestone', 'Incubation milestone delayed', (string) $r['startup_name'], 'Delayed', (string) $r['target_date'], null, (string) $r['who'], (int) $r['owner_id'], (string) $r['spoke'], ['p' => 'workspace', 'id' => (int) $r['id']]);
        }
        return $out;
    });

    $guard('marketing', static function () use ($org, $today, $soon) {
        $out = [];
        foreach (ceo_owing_results($org)['rows'] as $r) {
            $k = (string) ($r['_results']['key'] ?? '');
            $out[] = ceo_item('marketing', 'report', 'Event results not submitted', (string) $r['event_name'], $k === 'overdue' ? 'Overdue' : ($k === 'started' ? 'In progress' : 'Due'),
                              (string) ($r['end_date'] ?: $r['start_date']), null, (string) $r['owner'], 0, (string) $r['hub_spoke'], ['sheet' => 'events']);
        }
        foreach (fabo_all("SELECT event_name, start_date, end_date, status, owner, hub_spoke FROM events WHERE COALESCE(client_id, 0) = ? AND (status = 'Delayed' OR (COALESCE(status, '') NOT IN ('Completed','Cancelled','Postponed') AND COALESCE(end_date, start_date) < ?)) ORDER BY start_date LIMIT 50", 'is', [$org, $today]) as $r) {
            $out[] = ceo_item('marketing', 'milestone', 'Programme past its dates', (string) $r['event_name'], 'Delayed', (string) ($r['end_date'] ?: $r['start_date']), null, (string) $r['owner'], 0, (string) $r['hub_spoke'], ['sheet' => 'events']);
        }
        foreach (fabo_all("SELECT event_name, start_date, owner, hub_spoke FROM events WHERE COALESCE(client_id, 0) = ? AND start_date BETWEEN ? AND ? AND COALESCE(status, '') NOT IN ('Completed','Cancelled','Postponed') ORDER BY start_date LIMIT 50", 'iss', [$org, $today, $soon]) as $r) {
            $out[] = ceo_item('marketing', 'deadline', 'Event coming up', (string) $r['event_name'], 'Upcoming', (string) $r['start_date'], null, (string) $r['owner'], 0, (string) $r['hub_spoke'], ['sheet' => 'events']);
        }
        foreach (fabo_all("SELECT activity_name, due_date, owner FROM master_log WHERE COALESCE(client_id, 0) = ? AND due_date < ? AND completion_date IS NULL AND COALESCE(status, '') NOT IN ('Completed','Cancelled','Published/Live') ORDER BY due_date LIMIT 100", 'is', [$org, $today]) as $r) {
            $out[] = ceo_item('marketing', 'overdue', 'Marketing activity overdue', (string) $r['activity_name'], 'Overdue', (string) $r['due_date'], null, (string) $r['owner'], 0, '', ['sheet' => 'master']);
        }
        return $out;
    });
    return $items;
}

/** Cuts a raw item down for one person, or null when they may not see that it exists. */
function ceo_item_for(array $i, array $viewer, int $org, array $a): ?array
{
    if (!in_array($i['module'], $a['modules'], true)) {
        return null;
    }
    $spoke = $i['spoke'] !== '' ? ceo_spoke_key($i['spoke']) : '';
    if ($a['spokes'] !== '*' && !in_array($spoke, $a['spokes'], true)) {
        return null;
    }
    $open = ceo_can_open($viewer, $i['module'], $org);
    $sensOk = $i['sens'] === null || in_array($i['sens'], $a['sensitive'], true);
    $mods = ceo_modules();
    $spokes = ceo_spokes($org);
    return [
        'module' => $i['module'], 'module_label' => $mods[$i['module']]['label'], 'color' => $mods[$i['module']]['color'],
        'type' => $i['type'], 'title' => $i['label'], 'detail' => $open && $sensOk ? $i['detail'] : '',
        'status' => $i['status'], 'due' => $i['due'], 'when' => $i['when'],
        'owner' => $sensOk ? $i['owner'] : '', 'owner_id' => $sensOk ? (int) $i['owner_id'] : 0,
        'spoke' => $spoke, 'spoke_name' => $spoke !== '' ? ($spokes[$spoke]['name'] ?? $i['spoke']) : '',
        'href' => $open ? ceo_link($viewer, $org, $i['module'], $i['link']) : null,
    ];
}

function ceo_action_types(): array
{
    return ['approval' => 'Approvals', 'overdue' => 'Overdue', 'deadline' => 'Upcoming deadlines', 'ticket' => 'Open tickets', 'signature' => 'Signatures', 'report' => 'Reports', 'milestone' => 'Delayed milestones'];
}

/** The queue for one person with the filters on it, soonest trouble first. Server-side filtering and paging. */
function ceo_actions(array $viewer, int $org, array $a, array $f, bool $force = false): array
{
    [$raw, $at] = ceo_cached($org, 'actions', CEO_TTL['fast'], static fn() => ceo_actions_raw($org), $force);
    $rows = [];
    foreach ($raw as $i) {
        $x = ceo_item_for($i, $viewer, $org, $a);
        if ($x) {
            $rows[] = $x;
        }
    }
    $managed = !empty($f['manager']) ? ceo_reports_of($org, (int) $f['manager']) : null;
    $rows = array_values(array_filter($rows, static function ($r) use ($f, $managed) {
        return ($f['spoke'] === '' || $r['spoke'] === $f['spoke'])
            && (empty($f['module']) || $r['module'] === $f['module'])
            && (empty($f['type']) || $r['type'] === $f['type'])
            && (empty($f['status']) || strcasecmp($r['status'], $f['status']) === 0)
            && (empty($f['assignee']) || $r['owner_id'] === (int) $f['assignee'])
            && ($managed === null || in_array($r['owner_id'], $managed, true));
    }));
    $rank = ['Overdue' => 0, 'Escalated' => 1, 'Delayed' => 2];
    usort($rows, static fn($x, $y) => [$rank[$x['status']] ?? 5, $x['due'] ?? '9999', $x['when'] ?? '9999'] <=> [$rank[$y['status']] ?? 5, $y['due'] ?? '9999', $y['when'] ?? '9999']);
    $counts = array_fill_keys(array_keys(ceo_action_types()), 0);
    $byModule = [];
    foreach ($rows as $r) {
        $counts[$r['type']]++;
        $byModule[$r['module']] = ($byModule[$r['module']] ?? 0) + 1;
    }
    return ['items' => $rows, 'counts' => $counts, 'by_module' => $byModule, 'total' => count($rows), 'at' => $at];
}

/** Person ids who report to this manager, through HR. */
function ceo_reports_of(int $org, int $personId): array
{
    if (!ceo_has_table('hr_employees')) {
        return [];
    }
    return array_map(static fn($r) => (int) $r['person_id'], fabo_all(
        'SELECT e.person_id FROM hr_employees e JOIN hr_employees m ON m.id = e.manager_id WHERE e.org_id = ? AND m.person_id = ? AND e.person_id IS NOT NULL', 'ii', [$org, $personId]));
}

/* ================================================================ activity */

/** Latest significant events from each module's own log. */
function ceo_feed_raw(int $org, string $since): array
{
    $items = [];
    $add = static function (string $m, callable $fn) use (&$items, $org) {
        if (ceo_module_state($org, $m) !== 'ok') {
            return;
        }
        try {
            $items = array_merge($items, $fn());
        } catch (Throwable $e) {
            error_log('FABO ceo feed ' . $m . ': ' . $e->getMessage());
        }
    };
    $add('inc', static function () use ($org, $since) {
        $A = ceo_t('inc_applications', $org);
        $M = ceo_t('inc_masters', $org);
        $words = ['application' => 'Startup application updated', 'stage' => 'Application moved stage', 'note' => 'Note added', 'evaluation' => 'Evaluation recorded', 'assign' => 'Application assigned'];
        $out = [];
        foreach (fabo_all('SELECT v.kind, v.summary, v.created_at, v.application_id, m.name spoke FROM `' . ceo_t('inc_activities', $org) . "` v LEFT JOIN `$A` a ON a.id = v.application_id LEFT JOIN `$M` m ON m.id = a.spoke_id
                            WHERE v.created_at >= ? ORDER BY v.id DESC LIMIT 60", 's', [$since]) as $r) {
            $label = $words[$r['kind']] ?? 'Incubation record updated';
            $out[] = ceo_item('inc', 'activity', $label, (string) $r['summary'], 'Updated', null, $r['created_at'], '', 0, (string) $r['spoke'], $r['application_id'] ? ['p' => 'application', 'id' => (int) $r['application_id']] : ['p' => 'applications']);
        }
        foreach (fabo_all("SELECT a.id, a.startup_name, a.submitted_at, m.name spoke FROM `$A` a LEFT JOIN `$M` m ON m.id = a.spoke_id WHERE a.submitted_at >= ? ORDER BY a.id DESC LIMIT 40", 's', [$since]) as $r) {
            $out[] = ceo_item('inc', 'activity', 'Startup application submitted', (string) $r['startup_name'], 'New', null, $r['submitted_at'], '', 0, (string) $r['spoke'], ['p' => 'application', 'id' => (int) $r['id']]);
        }
        return $out;
    });
    foreach (['it' => 'IT ticket', 'fa' => 'Facilities request'] as $desk => $word) {
        $add($desk, static function () use ($org, $desk, $word, $since) {
            $out = [];
            foreach (fabo_all('SELECT entity, entity_id, summary, created_at FROM `' . ceo_t($desk . '_activity', $org) . '` WHERE created_at >= ? ORDER BY id DESC LIMIT 40', 's', [$since]) as $r) {
                $isT = $r['entity'] === 'ticket';
                $s = strtolower((string) $r['summary']);
                $label = !$isT ? ($desk === 'it' ? 'IT register updated' : 'Facilities register updated')
                       : (str_contains($s, 'opened') ? $word . ' opened' : (str_contains($s, 'resolved') ? $word . ' resolved' : (str_contains($s, 'closed') ? $word . ' closed' : $word . ' updated')));
                $out[] = ceo_item($desk, 'activity', $label, (string) $r['summary'], str_contains($s, 'resolved') || str_contains($s, 'closed') ? 'Resolved' : 'Updated', null, $r['created_at'], '', 0, '',
                                  $isT ? ['p' => 'ticket', 'id' => (int) $r['entity_id']] : ['p' => 'dashboard']);
            }
            return $out;
        });
    }
    $add('cw', static function () use ($org, $since) {
        $out = [];
        foreach (fabo_all('SELECT a.detail, a.created_at, c.name centre FROM cw_activity a LEFT JOIN cw_centres c ON c.id = a.centre_id WHERE a.org_id = ? AND a.created_at >= ? ORDER BY a.id DESC LIMIT 40', 'is', [$org, $since]) as $r) {
            $out[] = ceo_item('cw', 'activity', 'Co-working register updated', (string) $r['detail'], 'Updated', null, $r['created_at'], '', 0, (string) $r['centre'], ['p' => 'overview'], 'finance');
        }
        return $out;
    });
    $add('esign', static function () use ($org, $since) {
        $out = [];
        $words = ['sent' => 'Document sent for signature', 'submitted' => 'Document sent for signature', 'signed' => 'Document signed', 'completed' => 'Document fully signed', 'rejected' => 'Document rejected'];
        foreach (fabo_all("SELECT a.action, a.summary, a.created_at, a.doc_id, d.title, d.unit, d.confidentiality FROM ds_audit a LEFT JOIN ds_documents d ON d.id = a.doc_id
                            WHERE a.org_id = ? AND a.created_at >= ? AND a.entity IN ('document','request') ORDER BY a.id DESC LIMIT 120", 'is', [$org, $since]) as $r) {
            $label = null;
            foreach ($words as $w => $l) {
                if (str_contains(strtolower((string) $r['action']), $w)) {
                    $label = $l;
                    break;
                }
            }
            if ($label !== null) {
                $out[] = ceo_item('esign', 'activity', $label, (string) $r['title'], 'Updated', null, $r['created_at'], '', 0, (string) $r['unit'], $r['doc_id'] ? ['p' => 'doc', 'id' => (int) $r['doc_id']] : ['p' => 'documents'],
                                  $r['confidentiality'] === 'internal' ? null : 'contracts');
            }
        }
        return array_slice($out, 0, 40);
    });
    $add('fin', static function () use ($org, $since) {
        require_once __DIR__ . '/../fin/core.php';
        $types = fp_doc_types();
        $out = [];
        foreach (fabo_all('SELECT doc_type, doc_id, action, created_at FROM `' . ceo_t('fp_wf_events', $org) . "` WHERE created_at >= ? AND action IN ('approve','approved','reject','rejected','return','returned') ORDER BY id DESC LIMIT 40", 's', [$since]) as $r) {
            $t = $types[$r['doc_type']] ?? null;
            $word = str_starts_with((string) $r['action'], 'approve') ? 'approved' : (str_starts_with((string) $r['action'], 'reject') ? 'rejected' : 'returned');
            $out[] = ceo_item('fin', 'activity', ($t['label'] ?? 'Finance document') . ' ' . $word, '', ucfirst($word), null, $r['created_at'], '', 0, '', ['p' => $t['page'] ?? 'approvals', 'id' => (int) $r['doc_id']], 'finance');
        }
        return $out;
    });
    $add('hr', static function () use ($org, $since) {
        $out = [];
        foreach (fabo_all("SELECT x.id, x.status, COALESCE(x.decided_at, x.submitted_at) w, e.full_name, m.name loc FROM hr_leave_requests x JOIN hr_employees e ON e.id = x.employee_id LEFT JOIN hr_masters m ON m.id = e.location_id
                            WHERE x.org_id = ? AND x.status IN ('submitted','approved','rejected') AND COALESCE(x.decided_at, x.submitted_at) >= ? ORDER BY w DESC LIMIT 40", 'is', [$org, $since]) as $r) {
            $out[] = ceo_item('hr', 'activity', $r['status'] === 'submitted' ? 'Leave request submitted' : 'Leave request ' . $r['status'], (string) $r['full_name'], ucfirst((string) $r['status']), null, $r['w'], '', 0, (string) $r['loc'], ['p' => 'leave', 'id' => (int) $r['id']], 'personal');
        }
        return $out;
    });
    $add('marketing', static function () use ($org, $since) {
        $out = [];
        foreach (fabo_all("SELECT event_name, status, created_at, updated_at, hub_spoke FROM events WHERE COALESCE(client_id, 0) = ? AND updated_at >= ? ORDER BY updated_at DESC LIMIT 40", 'is', [$org, $since]) as $r) {
            $new = substr((string) $r['created_at'], 0, 16) === substr((string) $r['updated_at'], 0, 16);
            $done = $r['status'] === 'Completed';
            $out[] = ceo_item('marketing', 'activity', $new ? 'Event created' : ($done ? 'Event completed' : 'Event updated'), (string) $r['event_name'], (string) ($r['status'] ?: 'Updated'), null, $r['updated_at'], '', 0, (string) $r['hub_spoke'], ['sheet' => 'events']);
        }
        return $out;
    });
    usort($items, static fn($x, $y) => strcmp((string) $y['when'], (string) $x['when']));
    return array_slice($items, 0, 300);
}

function ceo_feed(array $viewer, int $org, array $a, array $f, bool $force = false, int $limit = 60): array
{
    $since = date('Y-m-d 00:00:00', strtotime(ceo_today() . ' -45 days'));
    [$raw, $at] = ceo_cached($org, 'feed', 30, static fn() => ceo_feed_raw($org, $since), $force);
    $rows = [];
    foreach ($raw as $i) {
        $x = ceo_item_for($i, $viewer, $org, $a);
        if ($x && ($f['spoke'] === '' || $x['spoke'] === $f['spoke']) && (empty($f['module']) || $x['module'] === $f['module'])) {
            $rows[] = $x;
            if (count($rows) >= $limit) {
                break;
            }
        }
    }
    return ['items' => $rows, 'at' => $at];
}

/* ================================================================ trend */

/** Records per day, week or month, one series per module that has dated records. */
function ceo_trend(int $org, array $a, array $f): array
{
    $days = (int) round((strtotime($f['to']) - strtotime($f['from'])) / 86400) + 1;
    $unit = $days <= 45 ? 'day' : ($days <= 200 ? 'week' : 'month');
    $fmt = ['day' => '%Y-%m-%d', 'week' => '%x-W%v', 'month' => '%Y-%m'][$unit];
    $buckets = [];
    for ($t = strtotime($f['from']); $t <= strtotime($f['to']); $t += 86400) {
        $b = $unit === 'day' ? date('Y-m-d', $t) : ($unit === 'week' ? date('o-\WW', $t) : date('Y-m', $t));
        $buckets[$b] = true;
    }
    $spoke = $f['spoke'];
    $limited = $a['spokes'] !== '*';
    $defs = [
        'inc'       => ['Applications', ceo_t('inc_applications', $org), 'submitted_at', ''],
        'it'        => ['IT tickets', ceo_t('it_tickets', $org), 'created_at', ''],
        'fa'        => ['Facilities requests', ceo_t('fa_tickets', $org), 'created_at', ''],
        'esign'     => ['Documents signed', 'ds_documents', 'completed_at', ' AND org_id = ' . $org],
        'marketing' => ['Events', 'events', 'start_date', ' AND COALESCE(client_id, 0) = ' . $org . " AND COALESCE(status, '') NOT IN ('Cancelled','Postponed')"],
        'hr'        => ['Leave requests', 'hr_leave_requests', 'submitted_at', ' AND org_id = ' . $org],
    ];
    $byspoke = ['inc' => true, 'esign' => true, 'marketing' => true];
    [$key, $at] = [implode('|', ['trend', $f['from'], $f['to'], $spoke]), null];
    $mods = ceo_modules();
    $series = [];
    foreach ($defs as $m => [$label, $table, $col, $extra]) {
        if (!in_array($m, $a['modules'], true) || (!empty($f['module']) && $f['module'] !== $m) || ceo_module_state($org, $m) !== 'ok') {
            continue;
        }
        if (($spoke !== '' || $limited) && empty($byspoke[$m])) {
            continue;
        }
        try {
            [$rows, $when] = ceo_cached($org, $key . '|' . $m . '|' . json_encode($limited ? $a['spokes'] : ''), CEO_TTL['standard'], static function () use ($org, $m, $table, $col, $extra, $f, $fmt, $spoke, $limited, $a) {
                $cuts = $spoke !== '' ? [$spoke] : ($limited ? $a['spokes'] : ['']);
                $acc = [];
                foreach ($cuts as $s) {
                    $w = $extra;
                    $t = 'ss';
                    $p = [ceo_day_from($f), ceo_day_to($f)];
                    if ($s !== '') {
                        if ($m === 'inc') {
                            $ids = array_map(static fn($r) => (int) $r['id'], array_filter(fabo_all('SELECT id, name FROM `' . ceo_t('inc_masters', $org) . '` WHERE kind = "spoke"'), static fn($r) => ceo_spoke_key((string) $r['name']) === $s));
                            [$x, $xt, $xp] = ceo_in('spoke_id', $ids, 'i');
                        } elseif ($m === 'esign') {
                            [$x, $xt, $xp] = ceo_in('unit', ceo_raw_for_spoke('ds_documents', 'unit', $s, ' AND org_id = ?', 'i', [$org]), 's');
                        } else {
                            [$x, $xt, $xp] = ceo_in('hub_spoke', ceo_raw_for_spoke('events', 'hub_spoke', $s, ' AND COALESCE(client_id, 0) = ?', 'i', [$org]), 's');
                        }
                        $w .= $x;
                        $t .= $xt;
                        $p = array_merge($p, $xp);
                    }
                    foreach (fabo_all('SELECT DATE_FORMAT(`' . $col . "`, '" . $fmt . "') b, COUNT(*) n FROM `" . $table . '` WHERE `' . $col . '` BETWEEN ? AND ?' . $w . ' GROUP BY b', $t, $p) as $r) {
                        $acc[$r['b']] = ($acc[$r['b']] ?? 0) + (int) $r['n'];
                    }
                }
                return $acc;
            });
        } catch (Throwable $e) {
            error_log('FABO ceo trend ' . $m . ': ' . $e->getMessage());
            continue;
        }
        $at = $at === null || $when < $at ? $when : $at;
        $vals = [];
        foreach (array_keys($buckets) as $b) {
            $vals[] = (int) ($rows[$b] ?? 0);
        }
        $series[] = ['module' => $m, 'label' => $label, 'color' => $mods[$m]['color'], 'values' => $vals, 'total' => array_sum($vals)];
    }
    return ['unit' => $unit, 'labels' => array_keys($buckets), 'series' => $series, 'at' => $at];
}

/** The incubation pipeline, stage by stage. */
function ceo_funnel(int $org, array $a, array $f): array
{
    if (!in_array('inc', $a['modules'], true) || ceo_module_state($org, 'inc') !== 'ok') {
        return [];
    }
    $A = ceo_t('inc_applications', $org);
    $G = ceo_t('inc_stages', $org);
    $S = ceo_t('inc_startups', $org);
    $cuts = ceo_cuts($a, $f);
    $acc = [];
    foreach ($cuts as $c) {
        [$w, $t, $p] = ['', '', []];
        if ($c !== '') {
            $ids = array_map(static fn($r) => (int) $r['id'], array_filter(fabo_all('SELECT id, name FROM `' . ceo_t('inc_masters', $org) . '` WHERE kind = "spoke"'), static fn($r) => ceo_spoke_key((string) $r['name']) === $c));
            [$w, $t, $p] = ceo_in('COALESCE(a.spoke_id, s.spoke_id)', $ids, 'i');
        }
        foreach (fabo_all("SELECT g.id, g.name, g.sort, COUNT(a.id) n FROM `$G` g LEFT JOIN `$A` a ON a.stage_id = g.id AND a.dup_status <> 'confirmed' LEFT JOIN `$S` s ON s.id = a.startup_id
                            WHERE g.pipeline = 'crm' AND g.active = 1$w GROUP BY g.id, g.name, g.sort ORDER BY g.sort", $t, $p) as $r) {
            $acc[(int) $r['id']] = ['name' => (string) $r['name'], 'sort' => (int) $r['sort'], 'n' => (int) $r['n'] + (int) ($acc[(int) $r['id']]['n'] ?? 0)];
        }
    }
    uasort($acc, static fn($x, $y) => $x['sort'] <=> $y['sort']);
    return array_values(array_filter($acc, static fn($r) => $r['n'] > 0));
}

/* ================================================================ hub and spoke */

/** The figures the comparison shows. Only ones that really are recorded by spoke. */
function ceo_spoke_kpis(): array
{
    return ['inc_active', 'inc_incubating', 'inc_new', 'mk_programs', 'mk_events', 'mk_attendance', 'mk_reports_due', 'hr_active', 'cw_members', 'cw_occupancy', 'cw_dues', 'ds_pending', 'ds_overdue'];
}

function ceo_hub_spoke(array $viewer, int $org, array $a, array $f): array
{
    $all = ceo_spokes($org);
    $kpis = ceo_kpis();
    $use = array_values(array_filter(ceo_spoke_kpis(), static function ($k) use ($kpis, $a) {
        $d = $kpis[$k];
        return in_array($d['module'], $a['modules'], true) && ($a['kpis'] === '*' || in_array($k, $a['kpis'], true))
            && ($d['sensitive'] === null || in_array($d['sensitive'], $a['sensitive'], true));
    }));
    $actions = ceo_actions($viewer, $org, $a, ['spoke' => ''] + $f);
    $pending = [];
    foreach ($actions['items'] as $i) {
        $s = $i['spoke'] !== '' ? $i['spoke'] : '_none';
        $pending[$s]['open'] = ($pending[$s]['open'] ?? 0) + 1;
        if ($i['status'] === 'Overdue' || $i['status'] === 'Delayed') {
            $pending[$s]['overdue'] = ($pending[$s]['overdue'] ?? 0) + 1;
        }
    }
    $mods = array_unique(array_map(static fn($k) => $kpis[$k]['module'], $use));
    $rows = [];
    foreach ($all as $key => $s) {
        if ($a['spokes'] !== '*' && !in_array($key, $a['spokes'], true)) {
            continue;
        }
        $vals = [];
        $states = [];
        foreach ($mods as $m) {
            $r = ceo_source($org, $m, ['spoke' => $key, 'dept' => 0] + $f);
            $states[$m] = $r['state'];
            foreach ($r['values'] as $k => $v) {
                if (in_array($k, $use, true)) {
                    $vals[$k] = ceo_ratio($v);
                }
            }
        }
        $res = in_array('marketing', $a['modules'], true) && ceo_module_state($org, 'marketing') === 'ok' ? ceo_results_compliance($org, $key) : null;
        $rows[] = ['key' => $key, 'name' => $s['name'], 'kind' => $s['kind'], 'values' => $vals, 'states' => $states,
                   'open' => (int) ($pending[$key]['open'] ?? 0), 'overdue' => (int) ($pending[$key]['overdue'] ?? 0), 'compliance' => $res];
    }
    $cols = [];
    foreach ($use as $k) {
        $cols[] = ['key' => $k, 'label' => $kpis[$k]['label'], 'unit' => $kpis[$k]['unit'], 'module' => $kpis[$k]['module'], 'better' => $kpis[$k]['better']];
    }
    return ['spokes' => $rows, 'columns' => $cols, 'unassigned' => (int) ($pending['_none']['open'] ?? 0)];
}

/** Reporting compliance the data can really show: event results submitted against those that fell due. */
function ceo_results_compliance(int $org, string $spoke): ?array
{
    try {
        [$v] = ceo_cached($org, 'compliance|' . $spoke, CEO_TTL['standard'], static function () use ($org, $spoke) {
            $r = ceo_owing_results($org, $spoke);
            return ['submitted' => $r['submitted'], 'owing' => $r['owing']];
        });
        $total = $v['submitted'] + $v['owing'];
        return $v + ['rate' => $total > 0 ? round($v['submitted'] / $total * 100) : null];
    } catch (Throwable $e) {
        error_log('FABO ceo compliance: ' . $e->getMessage());
        return null;
    }
}

/* ================================================================ departments */

function ceo_departments(array $viewer, int $org, array $a, array $f): array
{
    $actions = ceo_actions($viewer, $org, $a, $f);
    $open = [];
    $late = [];
    foreach ($actions['items'] as $i) {
        $open[$i['module']] = ($open[$i['module']] ?? 0) + 1;
        if (in_array($i['status'], ['Overdue', 'Delayed', 'Escalated'], true)) {
            $late[$i['module']] = ($late[$i['module']] ?? 0) + 1;
        }
    }
    $done = ['inc' => 'inc_new', 'marketing' => 'mk_events', 'it' => 'it_resolved', 'fa' => 'fa_resolved', 'esign' => 'ds_signed', 'hr' => 'hr_joiners'];
    $words = ['inc' => 'applications received', 'marketing' => 'events conducted', 'it' => 'tickets resolved', 'fa' => 'requests resolved', 'esign' => 'documents signed', 'hr' => 'people joined'];
    $vals = ceo_kpi_values($viewer, $org, $a, $f);
    $targets = ceo_targets_eval($viewer, $org, $a, $f);
    $mods = ceo_modules();
    $out = [];
    foreach ($a['modules'] as $m) {
        $state = ceo_module_state($org, $m);
        $k = $done[$m] ?? null;
        $mine = array_filter($targets, static fn($t) => $t['module'] === $m);
        $out[] = ['module' => $m, 'label' => $mods[$m]['label'], 'color' => $mods[$m]['color'], 'state' => $state, 'open' => (int) ($open[$m] ?? 0), 'late' => (int) ($late[$m] ?? 0),
                  'done' => $k !== null ? ($vals[$k]['value'] ?? null) : null, 'done_label' => $words[$m] ?? '',
                  'targets' => count($mine), 'targets_ok' => count(array_filter($mine, static fn($t) => in_array($t['status'], ['achieved', 'on_track'], true))),
                  'href' => ceo_can_open($viewer, $m, $org) ? ceo_link($viewer, $org, $m, $m === 'marketing' ? ['sheet' => 'events'] : []) : null];
    }
    return $out;
}

/* ================================================================ targets */

/** The window a target is judged in, as of a day. */
function ceo_period_window(string $type, string $day): array
{
    $t = strtotime($day);
    switch ($type) {
        case 'daily':
            return [$day, $day];
        case 'weekly':
            $s = date('Y-m-d', strtotime('monday this week', $t));
            return [$s, date('Y-m-d', strtotime($s . ' +6 days'))];
        case 'monthly':
            return [date('Y-m-01', $t), date('Y-m-t', $t)];
        case 'quarterly':
            // The Indian financial year: April to March.
            $m = (int) date('n', $t);
            $start = [1 => 1, 2 => 1, 3 => 1, 4 => 4, 5 => 4, 6 => 4, 7 => 7, 8 => 7, 9 => 7, 10 => 10, 11 => 10, 12 => 10][$m];
            $s = date('Y', $t) . '-' . str_pad((string) $start, 2, '0', STR_PAD_LEFT) . '-01';
            return [$s, date('Y-m-t', strtotime($s . ' +2 months'))];
        default:
            $y = (int) date('Y', $t) - ((int) date('n', $t) < 4 ? 1 : 0);
            return [$y . '-04-01', ($y + 1) . '-03-31'];
    }
}

/** Where a figure stands against its target. Flows are judged against the share of the period already gone. */
function ceo_target_status(?float $actual, float $target, string $better, string $kind, float $elapsed): string
{
    if ($actual === null) {
        return 'no_data';
    }
    if ($better === 'down') {
        return $actual <= $target ? 'on_track' : ($actual <= $target * 1.25 ? 'at_risk' : 'behind');
    }
    if ($target <= 0 || $actual >= $target) {
        return 'achieved';
    }
    $pace = $target * ($kind === 'flow' ? max(0.05, min(1, $elapsed)) : 1);
    return $actual >= $pace ? 'on_track' : ($actual >= $pace * 0.75 ? 'at_risk' : 'behind');
}

function ceo_targets_eval(array $viewer, int $org, array $a, array $f): array
{
    $kpis = ceo_kpis();
    $today = ceo_today();
    $spokes = ceo_spokes($org);
    $out = [];
    foreach (fabo_all('SELECT * FROM ceo_targets WHERE org_id = ? AND active = 1 ORDER BY kpi_key, period_type', 'i', [$org]) as $t) {
        $d = $kpis[$t['kpi_key']] ?? null;
        if (!$d || !in_array($d['module'], $a['modules'], true) || ($a['kpis'] !== '*' && !in_array($d['key'], $a['kpis'], true))) {
            continue;
        }
        if ($d['sensitive'] !== null && !in_array($d['sensitive'], $a['sensitive'], true)) {
            continue;
        }
        $sp = (string) $t['spoke_key'];
        if (!ceo_allowed_spoke($a, $sp) || ($f['spoke'] !== '' && $sp !== $f['spoke'])) {
            continue;
        }
        [$from, $to] = ceo_period_window((string) $t['period_type'], $today);
        $win = ['from' => $from, 'to' => $to, 'spoke' => $sp, 'dept' => 0];
        $r = ceo_source($org, $d['module'], $win);
        $val = array_key_exists($d['key'], $r['values']) ? ceo_ratio($r['values'][$d['key']]) : null;
        $prev = null;
        if ($d['kind'] === 'flow') {
            [$pf] = ceo_period_window((string) $t['period_type'], date('Y-m-d', strtotime($from . ' -1 day')));
            $pr = ceo_source($org, $d['module'], ['from' => $pf, 'to' => date('Y-m-d', strtotime($from . ' -1 day')), 'spoke' => $sp, 'dept' => 0]);
            $prev = array_key_exists($d['key'], $pr['values']) ? ceo_ratio($pr['values'][$d['key']]) : null;
        } else {
            $s = fabo_one('SELECT value FROM ceo_snapshots WHERE org_id = ? AND kpi_key = ? AND spoke_key = ? AND day < ? ORDER BY day DESC LIMIT 1', 'isss', [$org, $d['key'], $sp, $from]);
            $prev = $s ? (float) $s['value'] : null;
        }
        $target = (float) $t['target_value'];
        $span = max(1, strtotime($to) - strtotime($from) + 86400);
        $elapsed = (strtotime($today) - strtotime($from) + 86400) / $span;
        $out[] = ['id' => (int) $t['id'], 'kpi' => $d['key'], 'label' => $d['label'], 'module' => $d['module'], 'module_label' => ceo_modules()[$d['module']]['label'], 'color' => ceo_modules()[$d['module']]['color'],
                  'unit' => $d['unit'], 'better' => $d['better'], 'period_type' => $t['period_type'], 'from' => $from, 'to' => $to,
                  'spoke' => $sp, 'spoke_name' => $sp === '' ? 'Organisation' : ($spokes[$sp]['name'] ?? $sp),
                  'target' => $target, 'actual' => $val, 'prev' => $prev, 'pct' => $val !== null && $target > 0 ? round($val / $target * 100, 1) : null,
                  'gap' => $val !== null ? round($target - $val, 2) : null, 'status' => ceo_target_status($val, $target, $d['better'], $d['kind'], $elapsed), 'owner' => (string) $t['owner_label'], 'elapsed' => round(min(1, $elapsed) * 100)];
    }
    return $out;
}

/* ================================================================ coverage */

/** What the dashboard can and cannot honestly report, module by module. */
function ceo_coverage(int $org, array $a, array $f): array
{
    $kpis = ceo_kpis();
    $words = ['ok' => 'Connected', 'not_granted' => 'Not held by this organisation', 'not_set_up' => 'Held, not yet opened', 'failed' => 'Could not be read'];
    $out = [];
    foreach (ceo_modules() as $m => $def) {
        if (!in_array($m, $a['modules'], true)) {
            continue;
        }
        $state = ceo_module_state($org, $m);
        if ($state === 'ok') {
            $state = ceo_source($org, $m, ['spoke' => '', 'dept' => 0] + $f)['state'];
        }
        $mine = array_filter($kpis, static fn($k) => $k['module'] === $m);
        $out[] = ['module' => $m, 'label' => $def['label'], 'color' => $def['color'], 'state' => $state, 'state_label' => $words[$state] ?? $state, 'kpis' => count($mine),
                  'by_spoke' => count(array_filter($mine, static fn($k) => $k['spoke'])), 'gap' => ceo_known_gaps()[$m] ?? ''];
    }
    return $out;
}

/** Fields the modules do not hold, so these cannot be measured yet. Named so nobody takes silence for zero. */
function ceo_known_gaps(): array
{
    return [
        'inc'       => 'Registrations are applications on event and programme forms; people who attend without one are not counted.',
        'marketing' => 'Events have no registrations field, only expected and actual attendees. Master Activity Log rows carry no spoke.',
        'hr'        => 'Split by spoke needs each employee\'s Location set to a hub or spoke name. Open positions and payroll carry no spoke.',
        'it'        => 'Tickets and assets carry no spoke or department, so they are organisation-wide only.',
        'fa'        => 'Requests and registers carry no spoke, so they are organisation-wide only.',
        'cw'        => 'No history of occupancy before the dashboard began taking its daily reading, apart from each centre\'s Monthly Log.',
        'esign'     => 'Split by spoke needs the document\'s Unit filled in.',
        'fin'       => 'Finance records cost centres, not spokes, so its figures are organisation-wide only.',
    ];
}

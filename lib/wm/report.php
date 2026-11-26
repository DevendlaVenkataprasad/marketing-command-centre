<?php
/**
 * The Work report: every task's status on a "compare" date beside its status
 * on the "current" date, with a summary, as an on-screen preview and as a
 * formatted .xlsx.
 *
 * STATUS AT A DATE is the newest wm_task_status_history row whose changed_at
 * is on or before the end of that day (23:59:59). A task with no such row did
 * not exist yet: "Not created". The history is written in the same
 * transaction as every status or progress change, including the first row on
 * creation, so the answer for any past day is exact.
 */

require_once __DIR__ . '/core.php';
require_once __DIR__ . '/xlsx.php';

const WM_REPORT_COLUMNS = ['S.No', 'Task ID', 'Task Name', 'Description', 'Department', 'Assigned To', 'Priority',
    'Start Date', 'Due Date', 'Status (Last Week)', 'Status (Today)', 'Progress % (Last Week)', 'Progress % (Today)',
    'Change', 'Completed On', 'Hours Logged (in period)', 'Latest Update / Remarks'];

/**
 * The status and progress of each task at the end of $date.
 * Returns task_id => ['status' => ..., 'progress' => int] (missing = not created yet).
 */
function wm_status_at(array $taskIds, string $date): array
{
    $ids = array_values(array_filter(array_map('intval', $taskIds)));
    if (!$ids) {
        return [];
    }
    $out = [];
    foreach (array_chunk($ids, 500) as $chunk) {
        $rows = fabo_all('SELECT task_id, new_status, new_progress FROM wm_task_status_history
                          WHERE task_id IN (' . implode(',', $chunk) . ') AND changed_at <= ?
                          ORDER BY task_id, changed_at, id', 's', [$date . ' 23:59:59']);
        foreach ($rows as $r) {
            $out[(int) $r['task_id']] = ['status' => (string) $r['new_status'], 'progress' => (int) $r['new_progress']];
        }
    }
    return $out;
}

/**
 * Validated report inputs. Defaults: compare = the same day last week, current = today.
 * For a client the client filter is always themselves.
 */
function wm_report_filters(array $ctx, array $in): array
{
    $today = wm_today();
    $cur = wm_date_in($in['current'] ?? '', 'Current date') ?? $today;
    $cmp = wm_date_in($in['compare'] ?? '', 'Compare date') ?? date('Y-m-d', strtotime($cur . ' -7 days'));
    if ($cmp > $cur) {
        wm_fail('The compare date must be on or before the current date.');
    }
    $depts = array_values(array_unique(array_filter(array_map('intval', is_array($in['depts'] ?? null) ? $in['depts'] : explode(',', (string) ($in['depts'] ?? ''))))));
    foreach ($depts as $d) {
        if (!wm_dept_may_use($ctx, $d) && $ctx['role'] !== 'client') {
            wm_fail('That department was not found.', 404);
        }
    }
    $assignee = (string) ($in['assignee'] ?? '');
    $assignee = $assignee === 'none' ? 'none' : ((int) $assignee > 0 ? (string) (int) $assignee : '');
    $status = (string) ($in['status'] ?? '');
    if ($status !== '' && !in_array($status, WM_STATUSES, true)) {
        wm_fail('Choose a valid status.');
    }
    $client = $ctx['role'] === 'client' ? (int) $ctx['id'] : (int) ($in['client'] ?? 0);
    if ($client > 0 && $ctx['role'] !== 'client') {
        if (!fabo_one("SELECT client_id FROM wm_members WHERE client_id = ? AND role = 'client'", 'i', [$client])) {
            wm_fail('That client was not found.', 404);
        }
    }
    return ['compare' => $cmp, 'current' => $cur, 'depts' => $depts, 'assignee' => $assignee, 'status' => $status, 'client' => $client];
}

/** What a client member can see, as a context (used for the admin's client filter as well). */
function wm_client_ctx(int $clientId): array
{
    $depts = array_map(static fn($r) => (int) $r['department_id'],
        fabo_all('SELECT department_id FROM wm_user_department_access WHERE user_id = ?', 'i', [$clientId]));
    $n = fabo_one('SELECT name FROM clients WHERE id = ?', 'i', [$clientId]);
    return ['role' => 'client', 'id' => $clientId, 'name' => (string) ($n['name'] ?? ''), 'can_comment' => false, 'depts' => $depts];
}

/** The report: ['rows' => [...], 'summary' => [...], 'filters' => [...]]. */
function wm_report(array $ctx, array $in): array
{
    $f = wm_report_filters($ctx, $in);
    $scopeCtx = $ctx['role'] === 'client' ? $ctx : ($f['client'] > 0 ? wm_client_ctx($f['client']) : $ctx);
    [$w, $ty, $p] = wm_task_scope($scopeCtx);
    if ($scopeCtx !== $ctx) {                   // the admin's or a team member's own scope still applies
        [$w2, $ty2, $p2] = wm_task_scope($ctx);
        $w .= ' AND ' . $w2;
        $ty .= $ty2;
        $p = array_merge($p, $p2);
    }
    $w .= ' AND t.created_at <= ?';
    $ty .= 's';
    $p[] = $f['current'] . ' 23:59:59';
    if ($f['depts']) {
        $w .= ' AND t.department_id IN (' . implode(',', $f['depts']) . ')';
    }
    if ($f['assignee'] === 'none') {
        $w .= ' AND t.assignee_id IS NULL';
    } elseif ($f['assignee'] !== '') {
        $w .= ' AND t.assignee_id = ?';
        $ty .= 'i';
        $p[] = (int) $f['assignee'];
    }
    $tasks = fabo_all("SELECT t.* FROM wm_tasks t WHERE $w ORDER BY t.department_id, t.id", $ty, $p);
    $ids = array_map(static fn($t) => (int) $t['id'], $tasks);
    $atCmp = wm_status_at($ids, $f['compare']);
    $atCur = wm_status_at($ids, $f['current']);
    $names = wm_names(array_column($tasks, 'assignee_id'));
    $depts = [];
    foreach (fabo_all('SELECT id, name, color FROM wm_departments') as $d) {
        $depts[(int) $d['id']] = $d;
    }
    $client = $ctx['role'] === 'client';
    $hours = $remarks = [];
    if ($ids) {
        $in = implode(',', $ids);
        if (!$client) {
            foreach (fabo_all("SELECT task_id, SUM(minutes) AS m FROM wm_work_logs WHERE task_id IN ($in) AND date BETWEEN ? AND ? GROUP BY task_id",
                              'ss', [$f['compare'], $f['current']]) as $r) {
                $hours[(int) $r['task_id']] = round((int) $r['m'] / 60, 2);
            }
        }
        // Latest update: the newest comment or history note up to the current date. A client's
        // report uses client-visible comments only; history notes are staff notes and stay out.
        $end = $f['current'] . ' 23:59:59';
        foreach (fabo_all("SELECT task_id, author_name AS who, body AS txt, created_at AS at FROM wm_task_comments
                           WHERE task_id IN ($in) AND created_at <= ?" . ($client ? ' AND is_internal = 0' : '') . '
                           ORDER BY created_at, id', 's', [$end]) as $r) {
            $remarks[(int) $r['task_id']] = $r;
        }
        foreach ($client ? [] : fabo_all("SELECT task_id, changed_by_name AS who, note AS txt, changed_at AS at FROM wm_task_status_history
                           WHERE task_id IN ($in) AND changed_at <= ? AND note IS NOT NULL AND note <> '' AND note <> 'Created'
                           ORDER BY changed_at, id", 's', [$end]) as $r) {
            $k = (int) $r['task_id'];
            if (!isset($remarks[$k]) || $remarks[$k]['at'] <= $r['at']) {
                $remarks[$k] = $r;
            }
        }
    }
    $rows = [];
    $sn = 0;
    $cmpCounts = $curCounts = array_fill_keys(array_merge(WM_STATUSES, ['Not created']), 0);
    $perDept = [];
    foreach ($tasks as $t) {
        $id = (int) $t['id'];
        $c = $atCmp[$id] ?? null;
        $n = $atCur[$id] ?? null;
        if (!$n) {
            continue;                                // no history at all by the current date
        }
        if ($f['status'] !== '' && $n['status'] !== $f['status']) {
            continue;
        }
        $sCmp = $c['status'] ?? 'Not created';
        $sCur = $n['status'];
        $overdueCur = $t['due_date'] !== null && $t['due_date'] < $f['current'] && !in_array($sCur, WM_CLOSED, true);
        $overdueCmp = $c && $t['due_date'] !== null && $t['due_date'] < $f['compare'] && !in_array($sCmp, WM_CLOSED, true);
        $completedOn = ($sCur === 'Completed' && $t['completed_at'] !== null && substr($t['completed_at'], 0, 10) <= $f['current'])
            ? substr($t['completed_at'], 0, 10) : null;
        $rem = $remarks[$id] ?? null;
        $rows[] = [
            'sn' => ++$sn, 'id' => $id, 'code' => (string) $t['task_code'], 'title' => (string) $t['title'],
            'description' => (string) $t['description'], 'department' => (string) ($depts[(int) $t['department_id']]['name'] ?? ''),
            'department_id' => (int) $t['department_id'],
            'assignee' => $t['assignee_id'] === null ? '' : ($names[(int) $t['assignee_id']] ?? ''),
            'priority' => (string) $t['priority'], 'start_date' => $t['start_date'], 'due_date' => $t['due_date'],
            'status_cmp' => $sCmp, 'status_cur' => $sCur,
            'progress_cmp' => $c ? (int) $c['progress'] : null, 'progress_cur' => (int) $n['progress'],
            'change' => $sCmp === $sCur ? 'No change' : $sCmp . ' → ' . $sCur,
            'changed' => $sCmp !== $sCur,
            'completed_on' => $completedOn,
            'hours' => $client ? null : ($hours[$id] ?? 0),
            'remarks' => $rem ? wm_report_date((string) $rem['at']) . ' · ' . $rem['who'] . ': ' . $rem['txt'] : '',
            'overdue_cur' => $overdueCur, 'overdue_cmp' => $overdueCmp,
        ];
        $cmpCounts[$sCmp]++;
        $curCounts[$sCur]++;
        $dn = (string) ($depts[(int) $t['department_id']]['name'] ?? '—');
        $perDept[$dn] = $perDept[$dn] ?? ['department' => $dn, 'total' => 0, 'completed' => 0, 'in_progress' => 0, 'overdue' => 0];
        $perDept[$dn]['total']++;
        $perDept[$dn]['completed'] += $sCur === 'Completed' ? 1 : 0;
        $perDept[$dn]['in_progress'] += $sCur === 'In Progress' ? 1 : 0;
        $perDept[$dn]['overdue'] += $overdueCur ? 1 : 0;
    }
    ksort($perDept, SORT_NATURAL | SORT_FLAG_CASE);
    $counts = [];
    foreach ($cmpCounts as $s => $nCmp) {
        $counts[] = ['status' => $s, 'compare' => $nCmp, 'current' => $curCounts[$s]];
    }
    $deptLabel = 'All';
    if (count($f['depts']) === 1) {
        $deptLabel = (string) ($depts[$f['depts'][0]]['name'] ?? 'All');
    } elseif (count($f['depts']) > 1) {
        $deptLabel = count($f['depts']) . ' departments';
    }
    return ['rows' => $rows, 'filters' => $f, 'dept_label' => $deptLabel,
            'summary' => ['counts' => $counts, 'per_dept' => array_values($perDept), 'changed' => count(array_filter($rows, static fn($r) => $r['changed']))]];
}

function wm_report_date(?string $d): string
{
    if (!$d) {
        return '';
    }
    $t = strtotime(substr($d, 0, 10));
    return $t ? date('d-M-Y', $t) : '';
}

function wm_report_filename(array $rep): string
{
    $label = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', (string) $rep['dept_label']), '-');
    return 'Work-Report_' . ($label !== '' ? $label : 'All') . '_' . $rep['filters']['current'] . '.xlsx';
}

/** Builds the formatted workbook. */
function wm_report_xlsx(array $rep, string $generatedBy): WmXlsx
{
    $x = new WmXlsx();
    $brand = '1D5FD0';
    $x->style('head', ['font' => ['b' => true, 'color' => 'FFFFFF'], 'fill' => $brand, 'wrap' => true, 'align' => 'center', 'valign' => 'center', 'border' => true]);
    $x->style('txt', ['border' => true, 'valign' => 'top']);
    $x->style('wrap', ['border' => true, 'wrap' => true, 'valign' => 'top']);
    $x->style('num', ['border' => true, 'valign' => 'top', 'align' => 'center']);
    $x->style('hrs', ['border' => true, 'valign' => 'top', 'fmt' => '0.00', 'align' => 'right']);
    $x->style('date', ['border' => true, 'valign' => 'top', 'fmt' => 'date', 'align' => 'center']);
    $x->style('date_red', ['border' => true, 'valign' => 'top', 'fmt' => 'date', 'align' => 'center', 'font' => ['b' => true, 'color' => 'B3261E']]);
    $x->style('pct', ['border' => true, 'valign' => 'top', 'fmt' => 'pct', 'align' => 'center']);
    $fills = ['Completed' => 'D9F2E3', 'In Progress' => 'DCE9FB', 'On Hold' => 'FDEFD3', 'To Do' => 'ECEEF2', 'Cancelled' => 'F3F4F6', 'Not created' => 'FFFFFF'];
    $inks = ['Completed' => '1A6B3C', 'In Progress' => '1D4FA8', 'On Hold' => '8A5600', 'To Do' => '4B5563', 'Cancelled' => '6B7280', 'Not created' => '8A93A3'];
    foreach ($fills as $s => $fill) {
        $k = strtolower(str_replace(' ', '_', $s));
        $x->style('st_' . $k, ['border' => true, 'valign' => 'top', 'align' => 'center', 'fill' => $fill, 'font' => ['b' => true, 'color' => $inks[$s]]]);
        $x->style('st_' . $k . '_od', ['border' => true, 'valign' => 'top', 'align' => 'center', 'fill' => $fill, 'font' => ['b' => true, 'color' => 'B3261E']]);
    }
    $x->style('change', ['border' => true, 'valign' => 'top', 'fill' => 'FFF4C2', 'font' => ['b' => true, 'color' => '5B4300'], 'wrap' => true]);
    $x->style('same', ['border' => true, 'valign' => 'top', 'font' => ['color' => '8A93A3']]);
    $x->style('title', ['font' => ['b' => true, 'sz' => 16, 'color' => '1B2230']]);
    $x->style('label', ['font' => ['b' => true, 'color' => '5F6979']]);
    $x->style('sub', ['font' => ['b' => true, 'sz' => 12, 'color' => $brand]]);
    $x->style('cnt', ['border' => true, 'align' => 'center']);
    $x->style('cnt_b', ['border' => true, 'align' => 'center', 'font' => ['b' => true]]);
    $x->style('txt_b', ['border' => true, 'font' => ['b' => true]]);

    $st = static function (string $s, bool $od): array {
        $k = 'st_' . strtolower(str_replace(' ', '_', $s));
        return ['v' => $s . ($od ? ' (Overdue)' : ''), 's' => $k . ($od ? '_od' : '')];
    };
    $rows = [array_map(static fn($h) => ['v' => $h, 's' => 'head'], WM_REPORT_COLUMNS)];
    foreach ($rep['rows'] as $r) {
        $rows[] = [
            ['v' => $r['sn'], 's' => 'num', 't' => 'n'],
            ['v' => $r['code'], 's' => 'txt'],
            ['v' => $r['title'], 's' => 'wrap'],
            ['v' => $r['description'], 's' => 'wrap'],
            ['v' => $r['department'], 's' => 'txt'],
            ['v' => $r['assignee'] !== '' ? $r['assignee'] : 'Unassigned', 's' => 'txt'],
            ['v' => $r['priority'], 's' => 'num'],
            ['v' => $r['start_date'], 's' => 'date', 't' => 'd'],
            ['v' => $r['due_date'], 's' => $r['overdue_cur'] ? 'date_red' : 'date', 't' => 'd'],
            $st($r['status_cmp'], $r['overdue_cmp']),
            $st($r['status_cur'], $r['overdue_cur']),
            $r['progress_cmp'] === null ? ['v' => '—', 's' => 'num'] : ['v' => $r['progress_cmp'], 's' => 'pct', 't' => 'n'],
            ['v' => $r['progress_cur'], 's' => 'pct', 't' => 'n'],
            ['v' => $r['change'], 's' => $r['changed'] ? 'change' : 'same'],
            ['v' => $r['completed_on'], 's' => 'date', 't' => 'd'],
            $r['hours'] === null ? ['v' => '—', 's' => 'num'] : ['v' => $r['hours'], 's' => 'hrs', 't' => 'n'],
            ['v' => $r['remarks'], 's' => 'wrap'],
        ];
    }
    $x->addSheet('Task Status', $rows, ['freeze' => true, 'filter' => true, 'landscape' => true, 'heights' => [0 => 32],
        'widths' => [6, 10, 34, 46, 18, 20, 11, 13, 13, 20, 20, 13, 13, 30, 14, 13, 50]]);

    $f = $rep['filters'];
    $s = [
        [['v' => 'Work Status Report', 's' => 'title']],
        [],
        [['v' => 'Period', 's' => 'label'], wm_report_date($f['compare']) . ' → ' . wm_report_date($f['current'])],
        [['v' => 'Departments', 's' => 'label'], $rep['dept_label']],
        [['v' => 'Generated on', 's' => 'label'], date('d-M-Y H:i', strtotime(wm_now()))],
        [['v' => 'Generated by', 's' => 'label'], $generatedBy],
        [['v' => 'Tasks in report', 's' => 'label'], ['v' => count($rep['rows']), 't' => 'n']],
        [],
        [['v' => 'Tasks by status', 's' => 'sub']],
        [['v' => 'Status', 's' => 'head'], ['v' => 'Last week (' . wm_report_date($f['compare']) . ')', 's' => 'head'],
         ['v' => 'Today (' . wm_report_date($f['current']) . ')', 's' => 'head'], ['v' => 'Change', 's' => 'head']],
    ];
    $tc = $tn = 0;
    foreach ($rep['summary']['counts'] as $c) {
        $d = $c['current'] - $c['compare'];
        $s[] = [['v' => $c['status'], 's' => 'txt_b'], ['v' => $c['compare'], 's' => 'cnt', 't' => 'n'], ['v' => $c['current'], 's' => 'cnt', 't' => 'n'],
                ['v' => $d === 0 ? '0' : ($d > 0 ? '+' . $d : (string) $d), 's' => 'cnt']];
        $tc += $c['compare'];
        $tn += $c['current'];
    }
    $s[] = [['v' => 'Total', 's' => 'txt_b'], ['v' => $tc, 's' => 'cnt_b', 't' => 'n'], ['v' => $tn, 's' => 'cnt_b', 't' => 'n'], ['v' => '', 's' => 'cnt']];
    $s[] = [];
    $s[] = [['v' => 'By department (as of ' . wm_report_date($f['current']) . ')', 's' => 'sub']];
    $s[] = [['v' => 'Department', 's' => 'head'], ['v' => 'Total', 's' => 'head'], ['v' => 'Completed', 's' => 'head'],
            ['v' => 'In Progress', 's' => 'head'], ['v' => 'Overdue', 's' => 'head']];
    foreach ($rep['summary']['per_dept'] as $d) {
        $s[] = [['v' => $d['department'], 's' => 'txt_b'], ['v' => $d['total'], 's' => 'cnt', 't' => 'n'], ['v' => $d['completed'], 's' => 'cnt', 't' => 'n'],
                ['v' => $d['in_progress'], 's' => 'cnt', 't' => 'n'], ['v' => $d['overdue'], 's' => 'cnt', 't' => 'n']];
    }
    if (!$rep['summary']['per_dept']) {
        $s[] = [['v' => 'No tasks in this report.', 's' => 'txt']];
    }
    $x->addSheet('Summary', $s, ['widths' => [28, 24, 24, 14, 12]]);
    return $x;
}

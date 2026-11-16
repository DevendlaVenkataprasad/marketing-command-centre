<?php
/**
 * Digital Signatures — reports and the scheduler.
 *
 * Every report is limited to what the viewer may see (ds_visible_sql), with
 * the same filters: dates, department, category, status, person. Exports
 * (Excel, PDF, CSV) are in the audit trail.
 *
 * The scheduler runs from cron.php, and — because cron may not be set up —
 * at most hourly when someone opens the module: reminders at each
 * workflow's interval, a warning before a deadline, escalation when a
 * request has waited too long, documents past their validity marked
 * expired, abandoned signing sessions closed, and retention applied.
 */

function ds_reports(): array
{
    return [
        'register'     => ['Document register', 'Every document with its status, owner, department and dates.', 'file'],
        'pending'      => ['Pending signatures', 'Who is holding which document, since when, and how overdue.', 'clock'],
        'completed'    => ['Completed signatures', 'Each signature and approval given, by whom, how and when.', 'check'],
        'department'   => ['Department-wise', 'Documents per department by status.', 'building'],
        'signatory'    => ['Signatory-wise', 'Requests, completions, rejections and turnaround per signatory.', 'user'],
        'rejected'     => ['Rejected documents', 'Rejections and change requests with the reasons given.', 'x'],
        'expired'      => ['Expired documents', 'Documents that expired before or after signing.', 'hourglass'],
        'turnaround'   => ['Signature turnaround', 'Hours from request to action, per document and stage.', 'gauge'],
        'workflow'     => ['Workflow performance', 'Use, completion and timing of each workflow.', 'flow'],
        'verification' => ['Verification report', 'Every verification run and its result.', 'shield'],
        'audit'        => ['Audit trail', 'The tamper-evident log of every action.', 'list'],
    ];
}

/** Report filters from the query string, validated. */
function ds_report_filters(array $q): array
{
    $f = ['from' => ds_date($q['from'] ?? '') ?? date('Y-m-d', strtotime('-90 days')), 'to' => ds_date($q['to'] ?? '') ?? date('Y-m-d'),
          'dept' => (int) ($q['dept'] ?? 0) ?: null, 'cat' => (int) ($q['cat'] ?? 0) ?: null, 'status' => (string) ($q['status'] ?? ''),
          'person' => (int) ($q['person'] ?? 0) ?: null];
    if (!isset(ds_doc_statuses()[$f['status']])) {
        $f['status'] = '';
    }
    if ($f['from'] > $f['to']) {
        [$f['from'], $f['to']] = [$f['to'], $f['from']];
    }
    return $f;
}

/** WHERE over documents (alias d) for visibility and filters. */
function ds_doc_filter_sql(array $f, string $dateCol = 'd.created_at'): array
{
    [$w, $t, $p] = ds_visible_sql();
    $w .= ' AND ' . $dateCol . ' >= ? AND ' . $dateCol . ' < DATE_ADD(?, INTERVAL 1 DAY)';
    $t .= 'ss';
    array_push($p, $f['from'], $f['to']);
    if ($f['dept']) {
        $w .= ' AND d.department_id = ?';
        $t .= 'i';
        $p[] = $f['dept'];
    }
    if ($f['cat']) {
        $w .= ' AND d.category_id = ?';
        $t .= 'i';
        $p[] = $f['cat'];
    }
    if ($f['status'] !== '') {
        $w .= ' AND d.status = ?';
        $t .= 's';
        $p[] = $f['status'];
    }
    return [$w, $t, $p];
}

function ds_hours(?string $a, ?string $b): ?float
{
    return $a && $b ? round((strtotime($b) - strtotime($a)) / 3600, 1) : null;
}

/** Builds a report: title, sub, cols (key => [label, type, width]), rows. */
function ds_report(string $key, array $f): array
{
    $reps = ds_reports();
    if (!isset($reps[$key])) {
        throw new InvalidArgumentException('Unknown report.');
    }
    $sub = ds_fmt_date($f['from']) . ' – ' . ds_fmt_date($f['to']) . ($f['dept'] ? ' · ' . ds_dept_name($f['dept']) : '') . ($f['cat'] ? ' · ' . ds_category_name($f['cat']) : '')
         . ($f['status'] !== '' ? ' · ' . ds_doc_statuses()[$f['status']][0] : '') . ($f['person'] ? ' · ' . ds_person_name($f['person']) : '');
    $rep = ['key' => $key, 'title' => $reps[$key][0], 'sub' => $sub, 'cols' => [], 'rows' => [], 'generated' => date('d M Y, H:i')];
    $st = ds_doc_statuses();
    switch ($key) {
        case 'register':
        case 'rejected':
        case 'expired':
            [$w, $t, $p] = ds_doc_filter_sql($f);
            if ($key === 'rejected') {
                $w .= ' AND d.status IN ("rejected","changes_requested")';
            }
            if ($key === 'expired') {
                $w .= ' AND (d.status = "expired" OR (d.status = "signed" AND d.valid_until < CURDATE()))';
            }
            if ($f['person']) {
                $w .= ' AND d.owner_id = ?';
                $t .= 'i';
                $p[] = $f['person'];
            }
            $rep['cols'] = ['ref' => ['Reference', 'text', 95], 'title' => ['Title', 'text', 170], 'category' => ['Category', 'text', 90], 'department' => ['Department', 'text', 90],
                            'owner' => ['Owner', 'text', 90], 'status' => ['Status', 'text', 80], 'priority' => ['Priority', 'text', 50], 'created' => ['Created', 'date', 62],
                            'valid' => ['Valid until', 'date', 62], 'completed' => ['Completed', 'date', 62]];
            if ($key === 'rejected') {
                $rep['cols']['reason'] = ['Reason', 'text', 170];
            }
            foreach (fabo_all('SELECT d.* FROM ds_documents d WHERE ' . $w . ' ORDER BY d.created_at DESC LIMIT 5000', $t, $p) as $d) {
                $row = ['ref' => $d['ref_no'], 'title' => $d['title'], 'category' => ds_category_name($d['category_id'] ? (int) $d['category_id'] : null),
                        'department' => ds_dept_name($d['department_id'] ? (int) $d['department_id'] : null), 'owner' => ds_person_name((int) $d['owner_id']),
                        'status' => $st[$d['status']][0], 'priority' => ds_priorities()[$d['priority']], 'created' => substr($d['created_at'], 0, 10),
                        'valid' => $d['valid_until'], 'completed' => $d['completed_at'] ? substr($d['completed_at'], 0, 10) : null, '_id' => (int) $d['id']];
                if ($key === 'rejected') {
                    $rr = fabo_one('SELECT person_id, remarks FROM ds_signature_requests WHERE doc_id = ? AND status IN ("rejected","changes") ORDER BY acted_at DESC LIMIT 1', 'i', [(int) $d['id']]);
                    $row['reason'] = $rr ? ds_person_name((int) $rr['person_id']) . ': ' . $rr['remarks'] : '';
                }
                $rep['rows'][] = $row;
            }
            break;

        case 'pending':
            [$w, $t, $p] = ds_doc_filter_sql($f, 'r.activated_at');
            $w .= ' AND r.status = "pending"';
            if ($f['person']) {
                $w .= ' AND r.person_id = ?';
                $t .= 'i';
                $p[] = $f['person'];
            }
            $rep['cols'] = ['ref' => ['Reference', 'text', 95], 'title' => ['Title', 'text', 160], 'signatory' => ['Signatory', 'text', 110], 'stage' => ['Stage', 'text', 100],
                            'action' => ['Action', 'text', 50], 'since' => ['Waiting since', 'datetime', 90], 'due' => ['Due', 'datetime', 90], 'overdue' => ['Days overdue', 'num', 55],
                            'reminders' => ['Reminders', 'num', 50]];
            foreach (fabo_all('SELECT r.*, d.ref_no, d.title FROM ds_signature_requests r JOIN ds_documents d ON d.id = r.doc_id WHERE ' . $w . ' ORDER BY r.due_at LIMIT 5000', $t, $p) as $r) {
                $over = $r['due_at'] && ds_ts($r['due_at']) < time() ? (int) floor((time() - ds_ts($r['due_at'])) / 86400) : 0;
                $rep['rows'][] = ['ref' => $r['ref_no'], 'title' => $r['title'], 'signatory' => ds_req_name($r), 'stage' => $r['stage_name'],
                                  'action' => ds_actions()[$r['action']] ?? $r['action'], 'since' => $r['activated_at'], 'due' => $r['due_at'], 'overdue' => $over,
                                  'reminders' => (int) $r['reminders'], '_id' => (int) $r['doc_id']];
            }
            break;

        case 'completed':
        case 'turnaround':
            [$w, $t, $p] = ds_doc_filter_sql($f, 'r.acted_at');
            $w .= ' AND r.status IN ("signed","approved","reviewed")';
            if ($f['person']) {
                $w .= ' AND r.person_id = ?';
                $t .= 'i';
                $p[] = $f['person'];
            }
            $rep['cols'] = ['ref' => ['Reference', 'text', 95], 'title' => ['Title', 'text', 150], 'signatory' => ['Signatory', 'text', 110], 'stage' => ['Stage', 'text', 95],
                            'method' => ['Method', 'text', 95], 'requested' => ['Requested', 'datetime', 88], 'acted' => ['Acted', 'datetime', 88], 'hours' => ['Hours', 'num', 45]];
            if ($key === 'turnaround') {
                $rep['cols']['ontime'] = ['On time', 'text', 45];
            }
            foreach (fabo_all('SELECT r.*, d.ref_no, d.title FROM ds_signature_requests r JOIN ds_documents d ON d.id = r.doc_id WHERE ' . $w . ' ORDER BY r.acted_at DESC LIMIT 5000', $t, $p) as $r) {
                $row = ['ref' => $r['ref_no'], 'title' => $r['title'], 'signatory' => ds_req_name($r), 'stage' => $r['stage_name'],
                        'method' => ds_method_label($r['used_method']), 'requested' => $r['activated_at'], 'acted' => $r['acted_at'],
                        'hours' => ds_hours($r['activated_at'], $r['acted_at']), '_id' => (int) $r['doc_id']];
                if ($key === 'turnaround') {
                    $row['ontime'] = !$r['due_at'] || strtotime($r['acted_at']) <= strtotime($r['due_at']) ? 'Yes' : 'No';
                }
                $rep['rows'][] = $row;
            }
            if ($key === 'turnaround' && $rep['rows']) {
                $hrs = array_filter(array_column($rep['rows'], 'hours'), static fn($h) => $h !== null);
                $rep['summary'] = ['Average hours' => $hrs ? round(array_sum($hrs) / count($hrs), 1) : '—',
                                   'On time' => round(100 * count(array_filter($rep['rows'], static fn($r) => $r['ontime'] === 'Yes')) / count($rep['rows'])) . '%'];
            }
            break;

        case 'department':
            [$w, $t, $p] = ds_doc_filter_sql($f);
            $rep['cols'] = ['department' => ['Department', 'text', 150], 'total' => ['Total', 'num', 55], 'draft' => ['Draft', 'num', 50], 'open' => ['In signing', 'num', 60],
                            'signed' => ['Fully signed', 'num', 65], 'rejected' => ['Rejected / returned', 'num', 85], 'expired' => ['Expired', 'num', 55], 'cancelled' => ['Cancelled', 'num', 60],
                            'rate' => ['Completion', 'pct', 65]];
            $agg = [];
            foreach (fabo_all('SELECT d.department_id, d.status, COUNT(*) n FROM ds_documents d WHERE ' . $w . ' GROUP BY d.department_id, d.status', $t, $p) as $r) {
                $k = (int) $r['department_id'];
                $agg[$k] = $agg[$k] ?? ['total' => 0, 'draft' => 0, 'open' => 0, 'signed' => 0, 'rejected' => 0, 'expired' => 0, 'cancelled' => 0];
                $n = (int) $r['n'];
                $agg[$k]['total'] += $n;
                $bucket = in_array($r['status'], ds_open_statuses(), true) ? 'open' : (in_array($r['status'], ['rejected', 'changes_requested', 'reinitiated'], true) ? 'rejected' : $r['status']);
                if (isset($agg[$k][$bucket])) {
                    $agg[$k][$bucket] += $n;
                }
            }
            foreach ($agg as $k => $a) {
                $done = $a['total'] - $a['draft'];
                $rep['rows'][] = ['department' => $k ? ds_dept_name($k) : 'No department'] + $a + ['rate' => $done > 0 ? $a['signed'] / $done : null];
            }
            usort($rep['rows'], static fn($a, $b) => $b['total'] <=> $a['total']);
            break;

        case 'signatory':
            [$w, $t, $p] = ds_doc_filter_sql($f, 'COALESCE(r.activated_at, r.created_at)');
            $w .= ' AND r.status NOT IN ("waiting","cancelled","skipped")';
            $rep['cols'] = ['signatory' => ['Signatory', 'text', 130], 'designation' => ['Designation', 'text', 100], 'requests' => ['Requests', 'num', 55],
                            'completed' => ['Completed', 'num', 60], 'pending' => ['Pending', 'num', 55], 'rejected' => ['Rejected / returned', 'num', 80],
                            'digital' => ['Digital signatures', 'num', 75], 'avg' => ['Avg hours', 'num', 55], 'ontime' => ['On time', 'pct', 55]];
            $agg = [];
            foreach (fabo_all('SELECT r.* FROM ds_signature_requests r JOIN ds_documents d ON d.id = r.doc_id WHERE ' . $w, $t, $p) as $r) {
                // External signers are counted apart from staff ("x" + their id).
                $k = !empty($r['external_id']) ? 'x' . $r['external_id'] : (int) $r['person_id'];
                if ($f['person'] && $k !== $f['person']) {
                    continue;
                }
                $a = &$agg[$k];
                $a = $a ?? ['requests' => 0, 'completed' => 0, 'pending' => 0, 'rejected' => 0, 'digital' => 0, 'h' => [], 'on' => 0, 'acted' => 0];
                $a['requests']++;
                if (in_array($r['status'], ['signed', 'approved', 'reviewed'], true)) {
                    $a['completed']++;
                    $a['acted']++;
                    $h = ds_hours($r['activated_at'], $r['acted_at']);
                    if ($h !== null) {
                        $a['h'][] = $h;
                    }
                    if (!$r['due_at'] || strtotime((string) $r['acted_at']) <= strtotime($r['due_at'])) {
                        $a['on']++;
                    }
                    if (ds_method_is_digital($r['used_method'])) {
                        $a['digital']++;
                    }
                } elseif ($r['status'] === 'pending') {
                    $a['pending']++;
                } elseif (in_array($r['status'], ['rejected', 'changes'], true)) {
                    $a['rejected']++;
                    $a['acted']++;
                }
                unset($a);
            }
            foreach ($agg as $k => $a) {
                $isExt = is_string($k);
                $extRow = $isExt ? ds_external((int) substr($k, 1)) : null;
                $rep['rows'][] = ['signatory' => $isExt ? $extRow['name'] . ' (external)' : ds_person_name($k), 'designation' => $isExt ? (string) ($extRow['organisation'] ?? 'External') : ds_designation($k), 'requests' => $a['requests'], 'completed' => $a['completed'],
                                  'pending' => $a['pending'], 'rejected' => $a['rejected'], 'digital' => $a['digital'],
                                  'avg' => $a['h'] ? round(array_sum($a['h']) / count($a['h']), 1) : null, 'ontime' => $a['completed'] ? $a['on'] / $a['completed'] : null, '_person' => $k];
            }
            usort($rep['rows'], static fn($a, $b) => $b['requests'] <=> $a['requests']);
            break;

        case 'workflow':
            [$w, $t, $p] = ds_doc_filter_sql($f);
            $rep['cols'] = ['workflow' => ['Workflow', 'text', 170], 'documents' => ['Documents', 'num', 60], 'signed' => ['Fully signed', 'num', 65], 'open' => ['In signing', 'num', 60],
                            'rejected' => ['Rejected / returned', 'num', 85], 'days' => ['Avg days to complete', 'num', 90], 'rate' => ['Completion', 'pct', 65]];
            $agg = [];
            foreach (fabo_all('SELECT d.* FROM ds_documents d WHERE ' . $w . ' AND d.cycle > 0', $t, $p) as $d) {
                $plan = json_decode((string) $d['plan_json'], true) ?: [];
                $k = $plan['workflow'] ?? 'Built for the document';
                $a = &$agg[$k];
                $a = $a ?? ['documents' => 0, 'signed' => 0, 'open' => 0, 'rejected' => 0, 'days' => []];
                $a['documents']++;
                if ($d['status'] === 'signed') {
                    $a['signed']++;
                    if ($d['submitted_at'] && $d['completed_at']) {
                        $a['days'][] = (strtotime($d['completed_at']) - strtotime($d['submitted_at'])) / 86400;
                    }
                } elseif (in_array($d['status'], ds_open_statuses(), true)) {
                    $a['open']++;
                } elseif (in_array($d['status'], ['rejected', 'changes_requested'], true)) {
                    $a['rejected']++;
                }
                unset($a);
            }
            foreach ($agg as $k => $a) {
                $rep['rows'][] = ['workflow' => $k, 'documents' => $a['documents'], 'signed' => $a['signed'], 'open' => $a['open'], 'rejected' => $a['rejected'],
                                  'days' => $a['days'] ? round(array_sum($a['days']) / count($a['days']), 1) : null,
                                  'rate' => $a['documents'] ? $a['signed'] / $a['documents'] : null];
            }
            usort($rep['rows'], static fn($a, $b) => $b['documents'] <=> $a['documents']);
            break;

        case 'verification':
            $w = 'v.org_id = ? AND v.created_at >= ? AND v.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
            $t = 'iss';
            $p = [ds_org(), $f['from'], $f['to']];
            if (!ds_can('audit.view') && !ds_can_all_docs()) {
                $w .= ' AND v.verified_by_id = ?';
                $t .= 'i';
                $p[] = ds_me();
            }
            $rep['cols'] = ['ref' => ['Report', 'text', 90], 'document' => ['Document', 'text', 95], 'file' => ['File', 'text', 150], 'result' => ['Result', 'text', 120],
                            'signatures' => ['Signatures', 'num', 55], 'mandatory' => ['Mandatory', 'text', 60], 'by' => ['Verified by', 'text', 100], 'at' => ['When', 'datetime', 90]];
            foreach (fabo_all('SELECT v.*, d.ref_no FROM ds_signature_verifications v LEFT JOIN ds_documents d ON d.id = v.doc_id WHERE ' . $w . ' ORDER BY v.id DESC LIMIT 5000', $t, $p) as $v) {
                $rep['rows'][] = ['ref' => $v['report_ref'], 'document' => $v['ref_no'] ?? '—', 'file' => $v['file_name'], 'result' => ds_verdicts()[$v['overall']][0] ?? $v['overall'],
                                  'signatures' => (int) $v['signature_count'], 'mandatory' => $v['mandatory_ok'] === null ? '—' : ((int) $v['mandatory_ok'] ? 'Complete' : 'Missing'),
                                  'by' => $v['verified_by'], 'at' => $v['created_at'], '_vid' => (int) $v['id']];
            }
            break;

        case 'audit':
            ds_need('audit.view');
            $w = 'a.org_id = ? AND a.created_at >= ? AND a.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
            $t = 'iss';
            $p = [ds_org(), $f['from'], $f['to']];
            if ($f['person']) {
                $w .= ' AND a.actor_id = ?';
                $t .= 'i';
                $p[] = $f['person'];
            }
            $scope = ds_is_super() || ds_can_all_docs() ? null : ds_scope_depts('audit.view');
            if ($scope !== null) {
                $w .= ' AND (a.doc_id IS NULL AND a.actor_id = ? OR a.doc_id IN (SELECT id FROM ds_documents WHERE department_id IN (' . ($scope ? implode(',', array_map('intval', $scope)) : '0') . ')))';
                $t .= 'i';
                $p[] = ds_me();
            }
            $rep['cols'] = ['seq' => ['#', 'num', 40], 'at' => ['When', 'datetime', 88], 'actor' => ['Who', 'text', 100], 'designation' => ['Designation', 'text', 85],
                            'action' => ['Action', 'text', 80], 'summary' => ['What happened', 'text', 260], 'ip' => ['IP', 'text', 70]];
            foreach (fabo_all('SELECT a.* FROM ds_audit a WHERE ' . $w . ' ORDER BY a.seq DESC LIMIT 10000', $t, $p) as $a) {
                $rep['rows'][] = ['seq' => (int) $a['seq'], 'at' => ds_db_time((int) strtotime($a['created_at'])), 'actor' => $a['actor'], 'designation' => $a['designation'], 'action' => str_replace('_', ' ', $a['action']),
                                  'summary' => $a['summary'], 'ip' => $a['ip'], '_id' => $a['doc_id'] ? (int) $a['doc_id'] : null];
            }
            break;
    }
    return $rep;
}

function ds_report_cell($v, string $type): string
{
    if ($v === null || $v === '') {
        return '';
    }
    return match ($type) {
        'date' => ds_fmt_date((string) $v),
        'datetime' => ds_fmt_dt((string) $v),
        'pct' => round((float) $v * 100) . '%',
        'num' => is_float($v) ? number_format($v, 1) : (string) $v,
        default => (string) $v,
    };
}

/** Sends a report as Excel, PDF or CSV. */
function ds_report_export(array $rep, string $format): void
{
    ds_need('reports.export');
    ds_audit(null, 'report', null, 'export', 'Exported "' . $rep['title'] . '" (' . $rep['sub'] . ') as ' . strtoupper($format) . ', ' . count($rep['rows']) . ' rows');
    $name = preg_replace('/[^A-Za-z0-9]+/', '-', $rep['title']) . '-' . date('Ymd-His');
    if ($format === 'xlsx') {
        require_once __DIR__ . '/../xlsx.php';
        $x = new FaboXlsx();
        $rows = [array_values(array_map(static fn($c) => $c[0], $rep['cols']))];
        foreach ($rep['rows'] as $r) {
            $line = [];
            foreach ($rep['cols'] as $k => [$label, $type]) {
                $v = $r[$k] ?? null;
                $line[] = $v === null || $v === '' ? '' : ($type === 'pct' ? ['p' => (float) $v] : ($type === 'date' ? ['d' => (string) $v] : ($type === 'num' ? $v + 0 : ds_report_cell($v, $type))));
            }
            $rows[] = $line;
        }
        $rows[] = [];
        $rows[] = [$rep['title'] . ' — ' . $rep['sub']];
        $rows[] = ['Generated ' . $rep['generated'] . ' by ' . ds_actor() . ' · ' . ds_org_name()];
        $x->addSheet(mb_substr($rep['title'], 0, 30), $rows);
        $x->download($name . '.xlsx');
        exit;
    }
    if ($format === 'pdf') {
        require_once __DIR__ . '/../hr/pdf.php';
        $cols = [];
        foreach ($rep['cols'] as [$label, $type, $w]) {
            $cols[] = [$label, (float) ($w ?? 80), in_array($type, ['num', 'pct'], true) ? 'right' : 'left'];
        }
        $rows = [];
        foreach ($rep['rows'] as $r) {
            $line = [];
            foreach ($rep['cols'] as $k => [$label, $type]) {
                $line[] = DsPdfUpdate::plain(ds_report_cell($r[$k] ?? null, $type));
            }
            $rows[] = $line;
        }
        hr_pdf_table(DsPdfUpdate::plain($rep['title'] . ' — ' . ds_org_name()), DsPdfUpdate::plain($rep['sub'] . ' · ' . count($rep['rows']) . ' rows'), $cols, $rows)->send($name . '.pdf');
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [$rep['title'], $rep['sub'], 'Generated ' . $rep['generated']]);
    fputcsv($out, array_map(static fn($c) => $c[0], $rep['cols']));
    foreach ($rep['rows'] as $r) {
        $line = [];
        foreach ($rep['cols'] as $k => [$label, $type]) {
            $line[] = ds_report_cell($r[$k] ?? null, $type);
        }
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
}

/* ============================================================ scheduler */

/** Runs at most hourly per organisation from a page open (cron runs it regardless). */
function ds_cron_lazy(): void
{
    $last = ds_setting('last_cron');
    if ($last !== '' && strtotime($last) > time() - 3600) {
        return;
    }
    ds_setting_put('last_cron', date('c'));
    register_shutdown_function(static function () {
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        try {
            ds_cron_org();
        } catch (Throwable $e) {
            error_log('FABO esign lazy cron: ' . $e->getMessage());
        }
    });
}

/** Every organisation with the module: ours and each client still holding it. */
function ds_cron_all(): array
{
    require_once __DIR__ . '/app.php';
    ds_install();
    $out = [];
    foreach (array_keys(ds_orgs_available()) as $org) {
        ds_use((int) $org);
        $GLOBALS['ds_viewer'] = ['is_admin' => true, 'client' => null];
        $GLOBALS['ds_grant'] = 'edit';
        try {
            $out[$org] = ds_cron_org();
        } catch (Throwable $e) {
            error_log('FABO esign cron org ' . $org . ': ' . $e->getMessage());
        }
    }
    return $out;
}

function ds_cron_org(): array
{
    $GLOBALS['ds_system'] = true;
    $out = [];
    try {
        foreach (['transactions' => 'ds_cron_transactions', 'expiry' => 'ds_cron_expiry', 'reminders' => 'ds_cron_reminders',
                  'escalations' => 'ds_cron_escalate', 'retention' => 'ds_cron_retention'] as $k => $fn) {
            try {
                $out[$k] = $fn();
            } catch (Throwable $e) {
                $out[$k] = 'failed: ' . $e->getMessage();
                error_log('FABO esign cron ' . $k . ' org ' . ds_org() . ': ' . $e->getMessage());
            }
        }
        ds_setting_put('last_cron', date('c'));
    } finally {
        $GLOBALS['ds_system'] = false;
    }
    return $out;
}

/** Signing sessions abandoned at the ESP or never uploaded release the document. */
function ds_cron_transactions(): int
{
    $n = 0;
    foreach (fabo_all('SELECT * FROM ds_signature_transactions WHERE org_id = ? AND status IN ("initiated","awaiting") AND expires_at < NOW()', 'i', [ds_org()]) as $t) {
        fabo_exec('UPDATE ds_signature_transactions SET status = "expired" WHERE id = ?', 'i', [(int) $t['id']]);
        ds_audit((int) $t['doc_id'], 'transaction', (int) $t['id'], 'txn_expired', 'Signing session ' . $t['txn_ref'] . ' (' . ds_method_label($t['provider']) . ') expired unused');
        $n++;
    }
    return $n;
}

/** Past their validity (or, if set, their signing deadline) before completion: expired. */
function ds_cron_expiry(): int
{
    $n = 0;
    $sql = 'SELECT * FROM ds_documents WHERE org_id = ? AND deleted_at IS NULL AND status IN ("submitted","under_review","pending_first","pending","partial","draft","reinitiated","changes_requested")
            AND (valid_until < CURDATE()' . (ds_setting('expire_on_deadline') === '1' ? ' OR deadline < CURDATE()' : '') . ')';
    foreach (fabo_all($sql, 'i', [ds_org()]) as $d) {
        fabo_exec('UPDATE ds_signature_requests SET status = "expired" WHERE doc_id = ? AND status IN ("waiting","pending")', 'i', [(int) $d['id']]);
        fabo_exec('UPDATE ds_documents SET status = "expired", closed_at = NOW() WHERE id = ?', 'i', [(int) $d['id']]);
        ds_audit((int) $d['id'], 'document', (int) $d['id'], 'expired', $d['ref_no'] . ' expired before it was fully signed ('
            . ($d['valid_until'] && $d['valid_until'] < date('Y-m-d') ? 'validity ended ' . ds_fmt_date($d['valid_until']) : 'signing deadline ' . ds_fmt_date($d['deadline'])) . ')');
        ds_notify(ds_doc_participants((int) $d['id']), 'expired', $d['ref_no'] . ' expired', '"' . $d['title'] . '" expired before it was fully signed. The owner may reinitiate it with new dates.', (int) $d['id']);
        $n++;
    }
    // Signed documents whose validity has ended: told once.
    foreach (fabo_all('SELECT d.* FROM ds_documents d WHERE d.org_id = ? AND d.status = "signed" AND d.valid_until < CURDATE() AND d.deleted_at IS NULL
                       AND NOT EXISTS (SELECT 1 FROM ds_audit a WHERE a.org_id = d.org_id AND a.doc_id = d.id AND a.action = "validity_ended")', 'i', [ds_org()]) as $d) {
        ds_audit((int) $d['id'], 'document', (int) $d['id'], 'validity_ended', 'The validity of ' . $d['ref_no'] . ' ended on ' . ds_fmt_date($d['valid_until']));
        ds_notify([(int) $d['owner_id']], 'expired', 'Validity ended: ' . $d['ref_no'], 'The signed document "' . $d['title'] . '" was valid until ' . ds_fmt_date($d['valid_until']) . '.', (int) $d['id']);
        $n++;
    }
    return $n;
}

/** Reminders at each plan's interval, and one warning before a deadline. */
function ds_cron_reminders(): int
{
    $n = 0;
    $warnH = max(1, (int) ds_setting('deadline_warn_hours'));
    foreach (fabo_all('SELECT r.*, d.ref_no, d.title, d.plan_json, d.owner_id FROM ds_signature_requests r JOIN ds_documents d ON d.id = r.doc_id
                       WHERE r.org_id = ? AND r.status = "pending" AND d.deleted_at IS NULL', 'i', [ds_org()]) as $r) {
        $plan = json_decode((string) $r['plan_json'], true) ?: [];
        $every = max(1, (int) ($plan['reminder_hours'] ?? ds_setting('reminder_hours')));
        $max = (int) ($plan['max_reminders'] ?? 3);
        $verb = ['sign' => 'sign', 'approve' => 'approve', 'review' => 'review'][$r['action']] ?? 'sign';
        if ($r['due_at'] && !$r['warned_at'] && ds_ts($r['due_at']) > time() && ds_ts($r['due_at']) - time() <= $warnH * 3600) {
            if (!fabo_exec('UPDATE ds_signature_requests SET warned_at = NOW() WHERE id = ? AND warned_at IS NULL AND status = "pending"', 'i', [(int) $r['id']])) {
                continue; // another run (cron and a page open at once) already sent it
            }
            ds_notify_req($r, 'deadline', 'Due soon: ' . $r['ref_no'] . ' — please ' . $verb, '"' . $r['title'] . '" is due by ' . ds_fmt_dt($r['due_at']) . '.', (int) $r['doc_id'],
                      ['p' => 'sign', 'id' => (int) $r['id']]);
            ds_audit((int) $r['doc_id'], 'request', (int) $r['id'], 'deadline_warning', 'Deadline warning sent to ' . ds_req_name($r) . ' (due ' . ds_fmt_dt($r['due_at']) . ')');
            $n++;
            continue;
        }
        $since = $r['last_reminded_at'] ?: $r['activated_at'];
        if ($max > 0 && (int) $r['reminders'] < $max && $since && ds_ts($since) <= time() - $every * 3600) {
            if (!fabo_exec('UPDATE ds_signature_requests SET reminders = reminders + 1, last_reminded_at = NOW() WHERE id = ? AND reminders = ? AND status = "pending"',
                           'ii', [(int) $r['id'], (int) $r['reminders']])) {
                continue;
            }
            ds_notify_req($r, 'reminder', 'Reminder: please ' . $verb . ' ' . $r['ref_no'], '"' . $r['title'] . '" is still waiting for you'
                . ($r['due_at'] ? ' (due ' . ds_fmt_dt($r['due_at']) . ')' : '') . '.', (int) $r['doc_id'], ['p' => 'sign', 'id' => (int) $r['id']]);
            ds_audit((int) $r['doc_id'], 'request', (int) $r['id'], 'reminder', 'Reminder ' . ((int) $r['reminders'] + 1) . ' sent to ' . ds_req_name($r));
            $n++;
        }
    }
    return $n;
}

/** Requests waiting longer than the plan allows go to the escalation target (told, or reassigned). */
function ds_cron_escalate(): int
{
    $n = 0;
    foreach (fabo_all('SELECT r.*, d.ref_no, d.title, d.plan_json, d.owner_id FROM ds_signature_requests r JOIN ds_documents d ON d.id = r.doc_id
                       WHERE r.org_id = ? AND r.status = "pending" AND r.escalated_at IS NULL AND d.deleted_at IS NULL', 'i', [ds_org()]) as $r) {
        $plan = json_decode((string) $r['plan_json'], true) ?: [];
        $h = (int) ($plan['escalate_hours'] ?? 0);
        $overdue = $r['due_at'] && ds_ts($r['due_at']) < time();
        if (!(($h > 0 && ds_ts($r['activated_at']) <= time() - $h * 3600) || ($h === 0 && $overdue && !empty($plan['escalate_on_overdue'])))) {
            continue;
        }
        $n += ds_escalate($r, $plan) ? 1 : 0;
    }
    return $n;
}

/** Escalates one request now (also the Monitor's "Escalate" button). */
function ds_escalate(array $r, array $plan): bool
{
    $to = match ($plan['escalate_to'] ?? 'manager') {
        'person' => (int) ($plan['escalate_person_id'] ?? 0),
        'admins' => 0,
        default => (int) (ds_manager_of((int) $r['person_id']) ?? 0),
    };
    $targets = $to > 0 ? [$to] : array_values(array_unique(array_merge([ds_org()], array_map(static fn($x) => (int) $x['person_id'],
        fabo_all('SELECT person_id FROM ds_role_members WHERE org_id = ? AND role IN ("super_admin","org_admin")', 'i', [ds_org()])))));
    if (!fabo_exec('UPDATE ds_signature_requests SET escalated_at = NOW() WHERE id = ? AND escalated_at IS NULL', 'i', [(int) $r['id']])) {
        return false; // already escalated by another run
    }
    $who = ds_req_name($r);
    if (empty($r['external_id']) && ($plan['escalate_action'] ?? 'notify') === 'reassign' && $to > 0 && $to !== (int) $r['person_id'] && ds_is_member($to)) {
        fabo_exec('UPDATE ds_signature_requests SET person_id = ?, on_behalf_of_id = NULL, activated_at = NOW(), reminders = 0, last_reminded_at = NULL, warned_at = NULL WHERE id = ?',
                  'ii', [$to, (int) $r['id']]);
        ds_audit((int) $r['doc_id'], 'request', (int) $r['id'], 'escalated', 'Escalated: ' . $who . ' did not act in time; the request was reassigned to ' . ds_person_name($to));
        ds_notify([$to], 'escalated', 'Escalated to you: ' . ($r['ref_no'] ?? 'a document'), $who . ' did not act on "' . ($r['title'] ?? '') . '" in time, so it has been reassigned to you.',
                  (int) $r['doc_id'], ['p' => 'sign', 'id' => (int) $r['id']]);
        ds_notify_req($r, 'escalated', 'Reassigned: ' . ($r['ref_no'] ?? ''), 'Your request on "' . ($r['title'] ?? '') . '" was reassigned to ' . ds_person_name($to) . ' after the deadline.', (int) $r['doc_id']);
        return true;
    }
    ds_audit((int) $r['doc_id'], 'request', (int) $r['id'], 'escalated', 'Escalated: ' . $who . ' has not acted since ' . ds_fmt_dt($r['activated_at'])
        . '; ' . implode(', ', array_map('ds_person_name', $targets)) . ' told');
    ds_notify($targets, 'escalated', 'Escalation: ' . ($r['ref_no'] ?? '') . ' is waiting on ' . $who, '"' . ($r['title'] ?? '') . '" has been waiting on ' . $who . ' since '
        . ds_fmt_dt($r['activated_at']) . ($r['due_at'] ? ' (due ' . ds_fmt_dt($r['due_at']) . ')' : '') . '.', (int) $r['doc_id']);
    return true;
}

/**
 * Retention: closed documents past their retention date are purged (files
 * removed, record kept as a tombstone) when the policy says so, unless under
 * legal hold. "Archive" keeps them.
 */
function ds_cron_retention(): int
{
    $n = 0;
    foreach (fabo_all('SELECT d.* FROM ds_documents d WHERE d.org_id = ? AND d.retention_until < CURDATE() AND d.legal_hold = 0
                       AND d.status IN ("signed","rejected","cancelled","expired") LIMIT 200', 'i', [ds_org()]) as $d) {
        $pol = fabo_one('SELECT action FROM ds_retention_policies WHERE org_id = ? AND (category_id = ? OR category_id IS NULL) AND active = 1 ORDER BY category_id IS NULL LIMIT 1',
                        'ii', [ds_org(), (int) $d['category_id']]);
        if (($pol['action'] ?? 'archive') !== 'purge') {
            continue;
        }
        require_once __DIR__ . '/../uploads.php';
        foreach (fabo_all('SELECT * FROM ds_files WHERE doc_id = ? AND deleted_at IS NULL', 'i', [(int) $d['id']]) as $f) {
            @unlink(rtrim(fabo_upload_dir(), '/\\') . '/ds/' . $f['stored_name']);
            ds_preview_forget((string) $f['sha256']);
            fabo_exec('UPDATE ds_files SET deleted_at = NOW() WHERE id = ?', 'i', [(int) $f['id']]);
        }
        fabo_exec('UPDATE ds_documents SET deleted_at = COALESCE(deleted_at, NOW()), retention_until = NULL WHERE id = ?', 'i', [(int) $d['id']]);
        ds_audit((int) $d['id'], 'document', (int) $d['id'], 'purged', 'Retention ended for ' . $d['ref_no'] . ': its files were permanently removed; this record remains');
        $n++;
    }
    return $n;
}

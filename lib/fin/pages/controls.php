<?php
/** Audit & controls: the audit trail, compliance issues, audit evidence (kept for the retention period), and the control checklist. */

$tabs = [];
if (fp_can('audit.view')) {
    $tabs['audit'] = 'Audit trail';
}
if (fp_can('controls.manage') || fp_can('audit.view')) {
    $tabs += ['issues' => 'Compliance issues', 'evidence' => 'Audit evidence', 'checklist' => 'Control checklist'];
}
$tab = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : (string) array_key_first($tabs);
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    if ($a === 'issue') {
        $flash = fp_action(static function () {
            fp_issue_save(inc_int_or_null($_POST['id'] ?? null), $_POST);
            return 'Issue saved.';
        }, fp_url('controls', ['tab' => 'issues']));
    } elseif ($a === 'evidence') {
        $flash = fp_action(static function () {
            fp_tx(static fn() => fp_evidence_add($_POST));
            return 'Evidence filed.';
        }, fp_url('controls', ['tab' => 'evidence']));
    } elseif ($a === 'control') {
        $flash = fp_action(static function () {
            fp_need('settings.manage');
            $name = fp_str($_POST['name'] ?? '', 190, true, 'Control');
            $id = fp_exec('INSERT INTO fp_controls (name, area, frequency, owner_role) VALUES (?,?,?,?)', 'ssss',
                          [$name, fp_str($_POST['area'] ?? '', 60, true, 'Area'), in_array($_POST['frequency'] ?? '', ['monthly', 'quarterly', 'yearly'], true) ? $_POST['frequency'] : 'monthly',
                           isset(fp_roles()[$_POST['owner_role'] ?? '']) ? $_POST['owner_role'] : null]);
            fp_audit('control', $id, 'create', 'Control added: ' . $name);
            return 'Control added to the checklist.';
        }, fp_url('controls', ['tab' => 'checklist']));
    }
}
fp_layout_start('Audit & controls', 'Every change in Finance is recorded with who, when, from where, and what changed.', '', [], $flash);
echo '<nav class="subtabs">';
foreach ($tabs as $k => $l) {
    echo '<a href="' . e(fp_url('controls', ['tab' => $k])) . '"' . ($tab === $k ? ' class="on"' : '') . '>' . e($l) . '</a>';
}
echo '</nav>';

if ($tab === 'audit') {
    $q = array_intersect_key($_GET, array_flip(['q', 'entity', 'from', 'to', 'actor']));
    $where = ' WHERE 1=1';
    $t = '';
    $p = [];
    if (($q['entity'] ?? '') !== '') {
        $where .= ' AND entity = ?';
        $t .= 's';
        $p[] = $q['entity'];
    }
    foreach (['from' => 'created_at >= ?', 'to' => 'created_at < DATE_ADD(?, INTERVAL 1 DAY)'] as $k => $sql) {
        if (fp_safe_date($q[$k] ?? '')) {
            $where .= ' AND ' . $sql;
            $t .= 's';
            $p[] = $q[$k];
        }
    }
    if (trim((string) ($q['q'] ?? '')) !== '') {
        $where .= ' AND (summary LIKE ? OR actor LIKE ?)';
        $like = '%' . trim((string) $q['q']) . '%';
        $t .= 'ss';
        array_push($p, $like, $like);
    }
    $total = (int) fp_val('SELECT COUNT(*) FROM fp_audit' . $where, $t, $p);
    $pg = max(1, (int) ($_GET['pg'] ?? 1));
    $rows = fp_all('SELECT * FROM fp_audit' . $where . ' ORDER BY id DESC LIMIT 100 OFFSET ' . (($pg - 1) * 100), $t, $p);
    $ents = array_column(fp_all('SELECT DISTINCT entity FROM fp_audit ORDER BY entity'), 'entity', 'entity');
    echo '<section class="card fp-filtercard"><form method="get" class="fp-filters"><input type="hidden" name="p" value="controls"><input type="hidden" name="tab" value="audit">'
       . '<label><span>Search</span><input type="search" name="q" value="' . e($q['q'] ?? '') . '"></label><label><span>Record</span>' . inc_select('entity', $ents, $q['entity'] ?? '', 'Any') . '</label>'
       . '<label><span>From</span><input type="date" name="from" value="' . e($q['from'] ?? '') . '"></label><label><span>To</span><input type="date" name="to" value="' . e($q['to'] ?? '') . '"></label><button class="btn btn-primary">Apply</button></form></section>';
    echo '<div class="scrollx"><table class="data inc-table stack"><thead><tr><th>When</th><th>Who</th><th>Record</th><th>What</th><th>From</th><th></th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $page = fp_doc_types()[$r['entity']]['page'] ?? null;
        echo '<tr><td data-l="When" class="nowrap">' . e(fp_fmt_dt($r['created_at'])) . '</td><td data-l="Who">' . e($r['actor']) . '</td>'
           . '<td data-l="Record">' . ($page && $r['entity_id'] ? '<a href="' . e(fp_url($page, ['id' => $r['entity_id']])) . '">' . e($r['entity'] . ' #' . $r['entity_id']) . '</a>' : e($r['entity'] . ($r['entity_id'] ? ' #' . $r['entity_id'] : ''))) . '</td>'
           . '<td data-l="What">' . e(ucfirst(str_replace('_', ' ', $r['action']))) . ': ' . e($r['summary']) . '</td><td data-l="From" class="small">' . e($r['ip'] ?? '') . '</td><td>'
           . ($r['before_json'] || $r['after_json'] ? '<details><summary class="small">Changes</summary><pre class="fp-pre">' . e(fp_audit_diff($r['before_json'], $r['after_json'])) . '</pre></details>' : '') . '</td></tr>';
    }
    echo '</tbody></table></div>' . fp_pager($total, 100, $pg, 'controls', ['tab' => 'audit'] + $q);
} elseif ($tab === 'issues') {
    $rows = fp_all('SELECT * FROM fp_issues ORDER BY FIELD(status, "open", "in_progress", "resolved", "accepted"), FIELD(severity, "critical", "high", "medium", "low"), due_date');
    $edit = ctype_digit((string) ($_GET['edit'] ?? '')) ? fp_one('SELECT * FROM fp_issues WHERE id = ?', 'i', [(int) $_GET['edit']]) : null;
    if (fp_can('tax.manage') && fp_writable()) {
        $x = $edit ?? ['severity' => 'medium', 'status' => 'open'];
        echo '<section class="card"><h2>' . ($edit ? 'Update issue' : 'Log a compliance issue') . '</h2>' . inc_form_open() . '<input type="hidden" name="action" value="issue">' . ($edit ? '<input type="hidden" name="id" value="' . (int) $edit['id'] . '"><input type="hidden" name="version" value="' . (int) $edit['version'] . '">' : '')
           . '<div class="inc-row">' . inc_field('Issue', '<input name="title" maxlength="190" required value="' . e($x['title'] ?? '') . '">', '', true, 'grow')
           . inc_field('Area', '<input name="area" maxlength="60" required value="' . e($x['area'] ?? '') . '" placeholder="GST, TDS, ROC, audit…">', '', true)
           . inc_field('Severity', inc_select('severity', ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'], $x['severity']))
           . inc_field('Owner', inc_select('owner_person_id', fp_people(), $x['owner_person_id'] ?? '', '—')) . inc_field('Due', '<input type="date" name="due_date" value="' . e($x['due_date'] ?? '') . '">')
           . inc_field('Status', inc_select('status', ['open' => 'Open', 'in_progress' => 'In progress', 'resolved' => 'Resolved', 'accepted' => 'Risk accepted'], $x['status'])) . '</div>'
           . inc_field('Details', '<textarea name="details" rows="2">' . e($x['details'] ?? '') . '</textarea>') . inc_field('Resolution', '<input name="resolution" maxlength="1000" value="' . e($x['resolution'] ?? '') . '">', 'Required to resolve or accept')
           . '<button class="btn btn-primary">Save</button></form></section>';
    }
    if (!$rows) {
        echo '<p class="inc-allclear">No compliance issues logged.</p>';
    } else {
        echo '<div class="scrollx"><table class="data inc-table stack"><thead><tr><th>Issue</th><th>Area</th><th>Severity</th><th>Owner</th><th>Due</th><th>Status</th><th></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr><td data-l="Issue"><strong>' . e($r['title']) . '</strong>' . ($r['resolution'] ? '<div class="muted small">' . e($r['resolution']) . '</div>' : '') . '</td><td data-l="Area">' . e($r['area']) . '</td>'
               . '<td data-l="Severity">' . inc_badge(ucfirst($r['severity']), ['critical' => 'red', 'high' => 'red', 'medium' => 'amber', 'low' => ''][$r['severity']]) . '</td><td data-l="Owner">' . e($r['owner_person_id'] !== null ? fp_person_name((int) $r['owner_person_id']) : '') . '</td>'
               . '<td data-l="Due" class="' . ($r['due_date'] && $r['due_date'] < date('Y-m-d') && in_array($r['status'], ['open', 'in_progress'], true) ? 'late' : '') . '">' . e(fp_fmt_date($r['due_date'])) . '</td>'
               . '<td data-l="Status">' . e(ucfirst(str_replace('_', ' ', $r['status']))) . '</td><td>' . (fp_can('tax.manage') ? '<a class="btn btn-sm" href="' . e(fp_url('controls', ['tab' => 'issues', 'edit' => $r['id']])) . '">Update</a>' : '') . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
} elseif ($tab === 'evidence') {
    if (fp_can('controls.manage') && fp_writable()) {
        echo '<section class="card"><h2>File audit evidence</h2><p class="muted small">Kept at least ' . (int) fp_setting('retention_years') . ' years after the year ends (Settings; check your statutory requirement). Evidence files cannot be removed before then.</p>'
           . inc_form_open('', true) . '<input type="hidden" name="action" value="evidence"><div class="inc-row">' . inc_field('Title', '<input name="title" maxlength="190" required>', '', true, 'grow')
           . inc_field('Area', '<input name="area" maxlength="60" required placeholder="Bank, GST, payroll…">', '', true) . inc_field('Financial year', inc_select('fy', fp_fy_options(), fp_fy_of())) . '</div>'
           . inc_field('Notes', '<input name="notes" maxlength="500">') . inc_field('Files', '<input type="file" name="files[]" multiple required>', '', true) . '<button class="btn btn-primary">File evidence</button></form></section>';
    }
    $rows = fp_all('SELECT * FROM fp_evidence ORDER BY fy DESC, id DESC LIMIT 300');
    echo $rows ? '<div class="scrollx"><table class="data inc-table stack"><thead><tr><th>Evidence</th><th>Area</th><th>FY</th><th>Files</th><th>Keep until</th><th>Filed by</th></tr></thead><tbody>' . implode('', array_map(static fn($r) => '<tr><td data-l="Evidence"><strong>' . e($r['title']) . '</strong>'
         . ($r['notes'] ? '<div class="muted small">' . e($r['notes']) . '</div>' : '') . '</td><td data-l="Area">' . e($r['area']) . '</td><td data-l="FY">' . e(fp_fy_label((int) $r['fy'])) . '</td><td data-l="Files">'
         . implode(', ', array_map(static fn($f) => '<a href="' . e(fp_url('file', ['id' => $f['id']])) . '">' . e($f['original_name']) . '</a>', fp_files('evidence', (int) $r['id']))) . '</td>'
         . '<td data-l="Keep until">' . e(fp_fmt_date($r['keep_until'])) . '</td><td data-l="Filed by" class="small">' . e($r['created_by'] . ' · ' . fp_fmt_dt($r['created_at'])) . '</td></tr>', $rows)) . '</tbody></table></div>'
       : '<p class="muted small">No evidence filed yet.</p>';
} else {
    echo '<p>The checklist is completed month by month on <a href="' . e(fp_url('close')) . '">Period close</a>. These are the controls on it:</p>';
    echo '<div class="scrollx"><table class="data inc-table stack"><thead><tr><th>Control</th><th>Area</th><th>How often</th><th>Owner</th><th>System check</th></tr></thead><tbody>';
    foreach (fp_all('SELECT * FROM fp_controls WHERE active = 1 ORDER BY sort, id') as $c) {
        echo '<tr><td data-l="Control">' . e($c['name']) . '</td><td data-l="Area">' . e($c['area']) . '</td><td data-l="How often">' . e($c['frequency']) . '</td><td data-l="Owner">' . e(fp_roles()[$c['owner_role']] ?? '') . '</td>'
           . '<td data-l="System check">' . ($c['check_key'] ? 'Yes' : '—') . '</td></tr>';
    }
    echo '</tbody></table></div>';
    if (fp_can('settings.manage') && fp_writable()) {
        echo '<section class="card"><h2>Add a control</h2>' . inc_form_open() . '<input type="hidden" name="action" value="control"><div class="inc-row">' . inc_field('Control', '<input name="name" maxlength="190" required>', '', true, 'grow')
           . inc_field('Area', '<input name="area" maxlength="60" required>', '', true) . inc_field('How often', inc_select('frequency', ['monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'yearly' => 'Yearly'], 'monthly'))
           . inc_field('Owner role', inc_select('owner_role', array_diff_key(fp_roles(), ['employee' => 1]), '', '—')) . '</div><button class="btn">Add</button></form></section>';
    }
}
fp_layout_end();

/** What changed between two audit snapshots, one field per line. */
function fp_audit_diff(?string $before, ?string $after): string
{
    $b = $before ? (json_decode($before, true) ?: []) : [];
    $a = $after ? (json_decode($after, true) ?: []) : [];
    if (!is_array($b) || !is_array($a)) {
        return (string) $after;
    }
    $out = [];
    foreach (array_unique(array_merge(array_keys($b), array_keys($a))) as $k) {
        if (in_array($k, ['updated_at', 'version'], true)) {
            continue;
        }
        $x = is_scalar($b[$k] ?? null) ? (string) $b[$k] : json_encode($b[$k] ?? null);
        $y = is_scalar($a[$k] ?? null) ? (string) $a[$k] : json_encode($a[$k] ?? null);
        if ($x !== $y && array_key_exists($k, $a)) {
            $out[] = $k . ': ' . ($x === '' || $x === 'null' ? '—' : $x) . ' → ' . ($y === '' || $y === 'null' ? '—' : $y);
        }
    }
    return $out ? implode("\n", $out) : 'No field changes recorded.';
}

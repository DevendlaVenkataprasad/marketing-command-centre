<?php
/**
 * Tax & compliance: the GST position and registers from the ledger, TDS
 * withheld, the statutory filing calendar (the portal's own filing register
 * for this organisation — the same one Client billing keeps), and the tax
 * codes every document uses.
 */

$tab = in_array($_GET['tab'] ?? '', ['gst', 'tds', 'calendar', 'codes'], true) ? $_GET['tab'] : 'gst';
$back = fp_url('tax', ['tab' => $tab]);
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    fp_need('tax.manage');
    $org = fp_org();
    if ($a === 'code') {
        $flash = fp_action(static function () {
            fp_tax_code_save(inc_int_or_null($_POST['id'] ?? null), $_POST);
            return 'Tax code saved.';
        }, fp_url('tax', ['tab' => 'codes']));
    } elseif ($a === 'filing_add') {
        $flash = fp_action(static function () use ($org) {
            [$id, $msg] = fabo_filing_create($org, (string) ($_POST['kind'] ?? ''), (string) ($_POST['period_label'] ?? ''), (string) ($_POST['due_date'] ?? ''),
                                             (string) ($_POST['period_start'] ?? ''), (string) ($_POST['period_end'] ?? ''), (string) ($_POST['notes'] ?? ''));
            if (!$id) {
                throw new InvalidArgumentException($msg);
            }
            fp_audit('filing', $id, 'create', $msg);
            return $msg;
        }, fp_url('tax', ['tab' => 'calendar']));
    } elseif ($a === 'filing_generate') {
        $flash = fp_action(static function () use ($org) {
            [$n, $msg] = fabo_filing_generate($org, (string) ($_POST['kind'] ?? ''), (int) ($_POST['year'] ?? fp_fy_of()));
            fp_audit('filing', null, 'generate', $msg);
            return $msg;
        }, fp_url('tax', ['tab' => 'calendar']));
    } elseif ($a === 'filing_state') {
        $flash = fp_action(static function () use ($org) {
            [$ok, $msg] = fabo_filing_set_state((int) ($_POST['id'] ?? 0), (string) ($_POST['state'] ?? ''), (string) ($_POST['ack_no'] ?? ''), (float) ($_POST['tax_amount'] ?? 0),
                                                (float) ($_POST['penalty'] ?? 0), (string) ($_POST['filed_on'] ?? ''), $org);
            if (!$ok) {
                throw new InvalidArgumentException($msg);
            }
            fp_audit('filing', (int) $_POST['id'], 'state', $msg);
            return $msg;
        }, fp_url('tax', ['tab' => 'calendar']));
    }
}

fp_layout_start('Tax & compliance', 'Rates and due dates are records you keep, not fixed in the system — check them against current notifications.');
echo '<nav class="subtabs">';
$fm = fp_filing_metrics();
foreach (['gst' => 'GST', 'tds' => 'TDS', 'calendar' => 'Filing calendar' . ($fm['overdue'] ? ' <span class="c bad">' . (int) $fm['overdue'] . '</span>' : ''), 'codes' => 'Tax codes'] as $k => $l) {
    echo '<a href="' . e(fp_url('tax', ['tab' => $k])) . '"' . ($tab === $k ? ' class="on"' : '') . '>' . $l . '</a>';
}
echo '</nav>';

if ($tab === 'gst' || $tab === 'tds') {
    $f = fp_report_filters($_GET + ['from' => date('Y-m-01', strtotime('first day of last month')), 'to' => date('Y-m-t', strtotime('first day of last month'))]);
    echo '<section class="card fp-filtercard">' . fp_filters('tax', ['from', 'to', 'entity'], $f, [], ['tab' => $tab]) . '</section>';
    $rep = fp_report_run($tab, $f);
    echo '<section class="card"><div class="inc-cardhead"><h2>' . e($rep['title']) . ' · ' . e($rep['sub']) . '</h2>'
       . (fp_can('reports.export') ? '<span><a class="btn btn-sm" href="' . e(fp_url('reports', ['r' => $tab, 'export' => 'xlsx', 'from' => $f['from'], 'to' => $f['to']])) . '">Excel</a> <a class="btn btn-sm" href="' . e(fp_url('reports', ['r' => $tab, 'export' => 'csv', 'from' => $f['from'], 'to' => $f['to']])) . '">CSV</a></span>' : '')
       . '</div>' . fp_report_html($rep) . ($rep['note'] ? '<p class="muted small">' . e($rep['note']) . '</p>' : '') . '</section>';
    if ($tab === 'tds' && fp_can('pay.prepare')) {
        echo '<p><a class="btn" href="' . e(fp_url('payment', ['new' => 'tax'])) . '">Prepare the TDS deposit</a> <span class="muted small">— a tax payment against TDS payable, authorised and recorded like any payment.</span></p>';
    }
} elseif ($tab === 'calendar') {
    $rows = fp_filings();
    echo '<div class="inc-kpis">' . fp_kpi('Overdue', (string) $fm['overdue'], '', '', $fm['overdue'] ? 'red' : 'green') . fp_kpi('Due in 15 days', (string) $fm['due_soon'], '', '', $fm['due_soon'] ? 'amber' : '')
       . fp_kpi('Filed on time', (string) $fm['filed']) . fp_kpi('Filed late', (string) $fm['late'], '', '', $fm['late'] ? 'red' : '') . fp_kpi('Penalties', fp_money($fm['penalty'], false)) . '</div>';
    if (!$rows) {
        echo inc_empty('Nothing on the filing calendar yet.', 'Lay out a year of a return below.');
    } else {
        echo '<div class="scrollx"><table class="data inc-table stack"><thead><tr><th>Due</th><th>Return</th><th>Period</th><th>Status</th><th class="num">Tax</th><th>Acknowledgement</th><th></th></tr></thead><tbody>';
        foreach ($rows as $x) {
            $late = fabo_filing_overdue($x);
            echo '<tr><td data-l="Due" class="' . ($late ? 'late' : '') . '">' . e(fp_fmt_date($x['due_date'])) . '</td><td data-l="Return"><strong>' . e(fabo_filing_label($x['kind'])) . '</strong> <span class="muted small">' . e(fabo_filing_group($x['kind'])) . '</span></td>'
               . '<td data-l="Period">' . e($x['period_label']) . '</td><td data-l="Status">' . inc_badge($late ? 'Overdue' : fabo_filing_state_label($x['state']), $late ? 'red' : ['pending' => 'amber', 'filed' => 'green', 'paid' => 'green', 'late' => 'red', 'na' => ''][$x['state']] ?? '') . '</td>'
               . fp_money_td((float) $x['tax_amount'] ?: null, 'Tax') . '<td data-l="Acknowledgement" class="small">' . e($x['ack_no'] ?? '') . '</td><td>';
            if (fp_can('tax.manage') && fp_writable()) {
                echo '<details><summary class="btn btn-sm">' . ($x['state'] === 'pending' ? 'Record filing' : 'Update') . '</summary>' . inc_form_open() . '<input type="hidden" name="action" value="filing_state"><input type="hidden" name="id" value="' . (int) $x['id'] . '">'
                   . inc_select('state', array_map(static fn($s) => $s[0], fabo_filing_states()), $x['state']) . '<input type="date" name="filed_on" value="' . e($x['filed_on'] ?: date('Y-m-d')) . '" aria-label="Filed on">'
                   . '<input name="ack_no" placeholder="ARN / acknowledgement" value="' . e($x['ack_no'] ?? '') . '"><input name="tax_amount" inputmode="decimal" placeholder="Tax paid" value="' . e((float) $x['tax_amount'] ? (string) $x['tax_amount'] : '') . '">'
                   . '<input name="penalty" inputmode="decimal" placeholder="Penalty" value="' . e((float) $x['penalty'] ? (string) $x['penalty'] : '') . '"><button class="btn btn-sm btn-primary">Save</button></form></details>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    if (fp_can('tax.manage') && fp_writable()) {
        $kinds = [];
        foreach (fabo_filing_kinds() as $k => $v) {
            $kinds[$k] = $v[0] . ' — ' . $v[2];
        }
        echo '<div class="inc-grid2"><section class="card"><h2>Lay out a year</h2><p class="muted small">Periods and ordinary due dates are worked out; each stays editable for extensions. Running it twice adds nothing.</p>'
           . inc_form_open() . '<input type="hidden" name="action" value="filing_generate"><div class="inc-row">' . inc_field('Return', inc_select('kind', $kinds, 'gstr3b'))
           . inc_field('Financial year', inc_select('year', fp_fy_options(1, 1), fp_fy_of())) . '</div><button class="btn">Lay out</button></form></section>'
           . '<section class="card"><h2>Add one filing</h2>' . inc_form_open() . '<input type="hidden" name="action" value="filing_add"><div class="inc-row">'
           . inc_field('Return', inc_select('kind', array_map(static fn($v) => $v[0], fabo_filing_kinds()), 'other')) . inc_field('Period', '<input name="period_label" required placeholder="Sep-2026, Q2 FY 2026-27">', '', true)
           . inc_field('Due on', '<input type="date" name="due_date" required>', '', true) . '</div><button class="btn">Add</button></form></section></div>';
    }
} else {
    $codes = fp_all('SELECT * FROM fp_tax_codes ORDER BY kind, rate, code');
    $edit = ctype_digit((string) ($_GET['edit'] ?? '')) ? fp_tax_code((int) $_GET['edit']) : null;
    if (fp_can('tax.manage') && fp_writable()) {
        $x = $edit ?? ['kind' => 'gst', 'rate' => ''];
        echo '<section class="card"><h2>' . ($edit ? 'Edit ' . e($edit['code']) : 'Add a tax code') . '</h2>' . inc_form_open() . '<input type="hidden" name="action" value="code">' . ($edit ? '<input type="hidden" name="id" value="' . (int) $edit['id'] . '">' : '')
           . '<div class="inc-row">' . inc_field('Code', '<input name="code" maxlength="20" required value="' . e($x['code'] ?? '') . '">', '', true)
           . inc_field('Name', '<input name="name" maxlength="120" required value="' . e($x['name'] ?? '') . '">', '', true, 'grow')
           . inc_field('Kind', inc_select('kind', ['gst' => 'GST (CGST + SGST)', 'igst' => 'IGST', 'tds' => 'TDS (withheld)', 'exempt' => 'Exempt / nil', 'other' => 'Other tax'], $x['kind']))
           . inc_field('Rate %', '<input name="rate" inputmode="decimal" required value="' . e((string) ($x['rate'] ?? '')) . '">', '', true)
           . inc_field('Section (TDS)', '<input name="section" maxlength="20" value="' . e($x['section'] ?? '') . '">') . inc_field('Threshold', '<input name="threshold" inputmode="decimal" value="' . e((string) ($x['threshold'] ?? '')) . '">', 'TDS only above this') . '</div>'
           . '<div class="inc-row">' . inc_field('Input account (optional)', inc_select('input_account_id', fp_account_options(['asset']), $x['input_account_id'] ?? '', 'Standard'))
           . inc_field('Output account (optional)', inc_select('output_account_id', fp_account_options(['liability']), $x['output_account_id'] ?? '', 'Standard'))
           . ($edit ? '<label class="check"><input type="hidden" name="active" value="0"><input type="checkbox" name="active" value="1"' . ((int) $edit['active'] === 1 ? ' checked' : '') . '> Active</label>' : '') . '</div>'
           . '<button class="btn btn-primary">Save</button>' . ($edit ? ' <a class="btn" href="' . e(fp_url('tax', ['tab' => 'codes'])) . '">Cancel</a>' : '') . '</form></section>';
    }
    echo '<div class="scrollx"><table class="data inc-table stack"><thead><tr><th>Code</th><th>Name</th><th>Kind</th><th class="num">Rate</th><th>Section</th><th>Status</th><th></th></tr></thead><tbody>';
    foreach ($codes as $c) {
        echo '<tr' . ((int) $c['active'] !== 1 ? ' class="muted"' : '') . '><td data-l="Code"><strong>' . e($c['code']) . '</strong></td><td data-l="Name">' . e($c['name']) . '</td><td data-l="Kind">' . e(strtoupper($c['kind'])) . '</td>'
           . '<td class="num" data-l="Rate">' . e((string) (float) $c['rate']) . '%</td><td data-l="Section">' . e($c['section'] ?? '') . '</td><td data-l="Status">' . ((int) $c['active'] === 1 ? 'Active' : 'Inactive') . '</td>'
           . '<td>' . (fp_can('tax.manage') ? '<a class="btn btn-sm" href="' . e(fp_url('tax', ['tab' => 'codes', 'edit' => $c['id']])) . '">Edit</a>' : '') . '</td></tr>';
    }
    echo '</tbody></table></div>';
}
fp_layout_end();

<?php
/**
 * Finance Portal — reports and MIS, and the scheduler.
 *
 * Every report comes out of fp_report_run() as one shape — columns, rows,
 * totals, a note on its basis — so the page, the Excel and CSV exports and
 * the print view all show exactly the same figures. Every figure is read
 * from posted journals or the documents behind them; a report built on
 * forecasts or open documents says "management estimate" on its face.
 *
 *   cols  key => [label, type]   type: text | money | num | pct | date
 *   rows  key => value, plus _href (drill-down), _cls (head | sub | total)
 */

function fp_report_list(): array
{
    return [
        'Financial statements' => [
            'pnl' => ['Profit and loss', 'gl.view'], 'bs' => ['Balance sheet', 'gl.view'], 'cf' => ['Cash-flow statement', 'gl.view'],
            'tb' => ['Trial balance', 'gl.view'], 'consolidated' => ['Consolidated profit and loss (by entity)', 'gl.view'],
        ],
        'Ledger' => ['gl' => ['General ledger', 'gl.view'], 'journals' => ['Journal register', 'gl.view'], 'close' => ['Month-end closing status', 'gl.view']],
        'Payables & receivables' => [
            'ap_aging' => ['Payables ageing', 'ap.view'], 'vendor_payments' => ['Vendor payments', 'pay.view'], 'vendor_spend' => ['Spend by vendor', 'ap.view'],
            'ar_aging' => ['Receivables ageing and DSO', 'ar.view'], 'collections' => ['Customer collections', 'ar.view'],
        ],
        'Spend & budgets' => [
            'bva' => ['Budget vs actual', 'budget.view'], 'dept' => ['Expenses by department / cost centre', 'gl.view'],
            'project' => ['Expenditure by project', 'gl.view'], 'claims' => ['Employee expenses', 'claims.view'],
        ],
        'Assets & tax' => [
            'assets' => ['Fixed asset register and depreciation', 'assets.view'], 'gst' => ['GST summary and registers', 'tax.view'],
            'tds' => ['TDS withheld', 'tax.view'], 'compliance' => ['Tax and compliance status', 'tax.view'],
        ],
    ];
}

function fp_report_meta(string $key): ?array
{
    foreach (fp_report_list() as $group) {
        if (isset($group[$key])) {
            return $group[$key];
        }
    }
    return null;
}

/** The filters every report understands, read from the query string. */
function fp_report_filters(array $q): array
{
    $fy = (int) ($q['fy'] ?? fp_fy_of());
    [$fyFrom, $fyTo] = fp_fy_range($fy);
    $from = fp_safe_date($q['from'] ?? '') ?? $fyFrom;
    $to = fp_safe_date($q['to'] ?? '') ?? min($fyTo, date('Y-m-d'));
    if ($to < $from) {
        [$from, $to] = [$to, $from];
    }
    return ['fy' => $fy, 'from' => $from, 'to' => $to, 'entity' => (int) ($q['entity'] ?? 0) ?: null, 'cc' => (int) ($q['cc'] ?? 0) ?: null,
            'project' => (int) ($q['project'] ?? 0) ?: null, 'account' => (int) ($q['account'] ?? 0) ?: null, 'eliminate' => !empty($q['eliminate']),
            'status' => (string) ($q['status'] ?? '')];
}

function fp_safe_date($v): ?string
{
    $v = (string) $v;
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) ? $v : null;
}

/** Runs one report. Throws when the viewer may not see it. */
function fp_report_run(string $key, array $f): array
{
    $meta = fp_report_meta($key);
    if (!$meta) {
        throw new InvalidArgumentException('Unknown report.');
    }
    fp_need($meta[1]);
    $rep = ['key' => $key, 'title' => $meta[0], 'sub' => '', 'cols' => [], 'rows' => [], 'note' => '', 'basis' => 'Accounting data: posted journals only.', 'checks' => []];
    $period = fp_fmt_date($f['from']) . ' – ' . fp_fmt_date($f['to']);
    $acct = static fn($id) => fp_url('ledger', ['account' => $id, 'from' => $f['from'], 'to' => $f['to']] + ($f['entity'] ? ['entity' => $f['entity']] : []));
    switch ($key) {
        case 'pnl':
            $r = fp_profit_loss($f);
            $rep['sub'] = $period;
            $rep['cols'] = ['code' => ['Code', 'text'], 'name' => ['Account', 'text'], 'amount' => ['Amount', 'money']];
            foreach (['revenue' => 'Revenue', 'cogs' => 'Direct costs', 'opex' => 'Operating expenses', 'other_income' => 'Other income', 'other_exp' => 'Other expenses'] as $s => $label) {
                if (!$r['sections'][$s] && in_array($s, ['other_income', 'other_exp', 'cogs'], true)) {
                    continue;
                }
                $rep['rows'][] = ['name' => $label, '_cls' => 'head'];
                foreach ($r['sections'][$s] as $l) {
                    $rep['rows'][] = ['code' => $l['code'], 'name' => $l['name'], 'amount' => $l['amount'], '_href' => $acct($l['id'])];
                }
                $rep['rows'][] = ['name' => 'Total ' . strtolower($label), 'amount' => $r['sum'][$s], '_cls' => 'sub'];
                if ($s === 'cogs') {
                    $rep['rows'][] = ['name' => 'Gross profit', 'amount' => $r['gross'], '_cls' => 'total'];
                }
                if ($s === 'opex') {
                    $rep['rows'][] = ['name' => 'Operating profit', 'amount' => $r['operating'], '_cls' => 'total'];
                }
            }
            $rep['rows'][] = ['name' => 'Net profit' . ($r['margin'] !== null ? ' (' . fp_pct($r['margin'], 1) . ' of revenue)' : ''), 'amount' => $r['net'], '_cls' => 'total'];
            break;

        case 'consolidated':
            $ents = fp_all('SELECT id, code, name FROM fp_entities ORDER BY id');
            $rep['sub'] = $period . ($f['eliminate'] ? ' · intercompany eliminated' : '');
            $rep['cols'] = ['name' => ['Line', 'text']];
            foreach ($ents as $e) {
                $rep['cols']['e' . $e['id']] = [$e['code'], 'money'];
            }
            $rep['cols']['total'] = ['Consolidated', 'money'];
            $per = [];
            foreach ($ents as $e) {
                $per[(int) $e['id']] = fp_profit_loss(['entity' => (int) $e['id']] + $f);
            }
            $all = fp_profit_loss(['entity' => null] + $f);
            foreach (['Revenue' => 'revenue', 'Direct costs' => 'cogs', 'Operating expenses' => 'opex', 'Other income' => 'other_income', 'Other expenses' => 'other_exp'] as $label => $s) {
                $row = ['name' => $label, 'total' => $all['sum'][$s]];
                foreach ($ents as $e) {
                    $row['e' . $e['id']] = $per[(int) $e['id']]['sum'][$s];
                }
                $rep['rows'][] = $row;
            }
            $row = ['name' => 'Net profit', 'total' => $all['net'], '_cls' => 'total'];
            foreach ($ents as $e) {
                $row['e' . $e['id']] = $per[(int) $e['id']]['net'];
            }
            $rep['rows'][] = $row;
            $rep['note'] = 'With "eliminate intercompany" on, entries between your own entities are left out of every column.';
            break;

        case 'bs':
            $r = fp_balance_sheet($f);
            $rep['sub'] = 'As at ' . fp_fmt_date($f['to']);
            $rep['cols'] = ['code' => ['Code', 'text'], 'name' => ['Account', 'text'], 'amount' => ['Amount', 'money']];
            foreach (['asset' => 'Assets', 'liability' => 'Liabilities', 'equity' => 'Equity'] as $s => $label) {
                $rep['rows'][] = ['name' => $label, '_cls' => 'head'];
                foreach ($r['sections'][$s] as $l) {
                    $rep['rows'][] = ['code' => $l['code'], 'name' => $l['name'], 'amount' => $l['amount'], '_href' => $l['id'] ? $acct($l['id']) : null];
                }
                $rep['rows'][] = ['name' => 'Total ' . strtolower($label), 'amount' => $r['sum'][$s], '_cls' => 'sub'];
            }
            $rep['rows'][] = ['name' => 'Liabilities and equity', 'amount' => fp_r2($r['sum']['liability'] + $r['sum']['equity']), '_cls' => 'total'];
            $rep['checks'][] = fp_paise($r['difference']) === 0 ? ['ok', 'Assets equal liabilities plus equity.'] : ['err', 'Out of balance by ' . fp_money($r['difference']) . ' — investigate.'];
            break;

        case 'cf':
            $r = fp_cash_flow($f);
            $rep['sub'] = $period . ' · direct method';
            $rep['cols'] = ['name' => ['', 'text'], 'amount' => ['Amount', 'money']];
            $rep['rows'][] = ['name' => 'Cash and bank at the start', 'amount' => $r['opening'], '_cls' => 'sub'];
            foreach (['operating' => 'Operating activities', 'investing' => 'Investing activities', 'financing' => 'Financing activities'] as $s => $label) {
                $rep['rows'][] = ['name' => $label, '_cls' => 'head'];
                foreach ($r['sections'][$s] ?? [] as $name => $amt) {
                    $rep['rows'][] = ['name' => $name, 'amount' => $amt];
                }
                $rep['rows'][] = ['name' => 'Net cash from ' . strtolower($label), 'amount' => $r['sum'][$s] ?? 0, '_cls' => 'sub'];
            }
            $rep['rows'][] = ['name' => 'Net change in cash', 'amount' => $r['net'], '_cls' => 'total'];
            $rep['rows'][] = ['name' => 'Cash and bank at the end', 'amount' => $r['closing'], '_cls' => 'total'];
            $end = 0.0;
            foreach (fp_cash_position($f['to'], $f) as $b) {
                $end += $b['kind'] !== 'card' ? $b['balance'] : 0;
            }
            $rep['checks'][] = fp_paise($end) === fp_paise($r['closing']) ? ['ok', 'Agrees with the cash and bank balances at the end of the period.'] : ['warn', 'Cash and bank accounts show ' . fp_money($end) . ' at the end of the period; the difference is movements between cash and card accounts.'];
            break;

        case 'tb':
            $r = fp_trial_balance($f);
            $rep['sub'] = $period;
            $rep['cols'] = ['code' => ['Code', 'text'], 'name' => ['Account', 'text'], 'open_dr' => ['Opening Dr', 'money'], 'open_cr' => ['Opening Cr', 'money'],
                            'dr' => ['Debit', 'money'], 'cr' => ['Credit', 'money'], 'close_dr' => ['Closing Dr', 'money'], 'close_cr' => ['Closing Cr', 'money']];
            foreach ($r['rows'] as $l) {
                $rep['rows'][] = $l + ['_href' => $acct($l['id'])];
            }
            $t = $r['totals'];
            $rep['rows'][] = ['name' => 'Totals', 'open_dr' => $t['od'], 'open_cr' => $t['oc'], 'dr' => $t['md'], 'cr' => $t['mc'], 'close_dr' => $t['cd'], 'close_cr' => $t['cc'], '_cls' => 'total'];
            $rep['checks'][] = $r['balanced'] ? ['ok', 'Debits equal credits.'] : ['err', 'Debits and credits do not agree — investigate.'];
            break;

        case 'gl':
            if (!$f['account']) {
                $rep['sub'] = $period . ' · choose an account to see its entries';
                $rep['cols'] = ['code' => ['Code', 'text'], 'name' => ['Account', 'text'], 'dr' => ['Debit', 'money'], 'cr' => ['Credit', 'money']];
                foreach (fp_trial_balance($f)['rows'] as $l) {
                    if ($l['dr'] || $l['cr']) {
                        $rep['rows'][] = ['code' => $l['code'], 'name' => $l['name'], 'dr' => $l['dr'], 'cr' => $l['cr'], '_href' => $acct($l['id'])];
                    }
                }
                break;
            }
            $g = fp_gl_account($f['account'], $f, 20000);
            $rep['title'] .= ' — ' . $g['account']['code'] . ' ' . $g['account']['name'];
            $rep['sub'] = $period;
            $rep['cols'] = ['jdate' => ['Date', 'date'], 'number' => ['Journal', 'text'], 'desc' => ['Description', 'text'], 'cc' => ['Cost centre', 'text'],
                            'dr' => ['Debit', 'money'], 'cr' => ['Credit', 'money'], 'bal' => ['Balance', 'money']];
            $rep['rows'][] = ['desc' => 'Opening balance', 'bal' => $g['opening'], '_cls' => 'sub'];
            foreach ($g['rows'] as $l) {
                $rep['rows'][] = ['jdate' => $l['jdate'], 'number' => $l['number'], 'desc' => trim(($l['description'] ?: $l['jdesc'])), 'cc' => $l['cc_code'], 'dr' => (float) $l['debit_base'],
                                  'cr' => (float) $l['credit_base'], 'bal' => $l['running'], '_href' => fp_url('journal', ['id' => $l['journal_id']])];
            }
            break;

        case 'journals':
            $rep['sub'] = $period;
            $rep['cols'] = ['jdate' => ['Date', 'date'], 'number' => ['Number', 'text'], 'type' => ['Type', 'text'], 'source' => ['Source', 'text'], 'description' => ['Description', 'text'],
                            'total' => ['Amount', 'money'], 'status' => ['Status', 'text'], 'by' => ['Prepared by', 'text'], 'posted' => ['Posted by', 'text']];
            $st = isset(fp_journal_statuses()[$f['status']]) ? $f['status'] : 'posted';
            $sql = 'SELECT * FROM fp_journals WHERE jdate BETWEEN ? AND ? AND status = ?' . ($f['entity'] ? ' AND entity_id = ' . (int) $f['entity'] : '') . ' ORDER BY jdate, id LIMIT 20000';
            foreach (fp_all($sql, 'sss', [$f['from'], $f['to'], $st]) as $j) {
                $rep['rows'][] = ['jdate' => $j['jdate'], 'number' => $j['number'] ?: '(draft #' . $j['id'] . ')', 'type' => fp_journal_types()[$j['type']] ?? $j['type'], 'source' => $j['source'],
                                  'description' => $j['description'], 'total' => (float) $j['total'], 'status' => fp_journal_statuses()[$j['status']][0], 'by' => $j['prepared_by'],
                                  'posted' => $j['posted_by'], '_href' => fp_url('journal', ['id' => $j['id']])];
            }
            break;

        case 'close':
            $rep['sub'] = 'FY ' . fp_fy_label($f['fy']);
            $rep['cols'] = ['period' => ['Period', 'text'], 'status' => ['Status', 'text'], 'unposted' => ['Unposted journals', 'num'], 'controls' => ['Checklist done', 'text'],
                            'auto' => ['System checks', 'text'], 'closed' => ['Closed by', 'text']];
            $controls = fp_all('SELECT * FROM fp_controls WHERE active = 1 AND frequency = "monthly"');
            foreach (fp_all('SELECT * FROM fp_periods WHERE fy = ? ORDER BY period_no', 'i', [$f['fy']]) as $p) {
                $ym = substr($p['start_date'], 0, 7);
                $done = (int) fp_val('SELECT COUNT(*) FROM fp_control_runs r JOIN fp_controls c ON c.id = r.control_id WHERE c.frequency = "monthly" AND r.period = ?', 's', [$ym]);
                $issues = [];
                foreach ($controls as $c) {
                    $a = $p['start_date'] <= date('Y-m-d') ? fp_control_auto($c['check_key'], $ym) : null;
                    if ($a && !$a[0]) {
                        $issues[] = $a[1];
                    }
                }
                $rep['rows'][] = ['period' => $p['name'], 'status' => ucfirst($p['status']),
                                  'unposted' => (int) fp_val('SELECT COUNT(*) FROM fp_journals WHERE jdate BETWEEN ? AND ? AND status IN ("draft","submitted","approved")', 'ss', [$p['start_date'], $p['end_date']]),
                                  'controls' => $done . ' of ' . count($controls), 'auto' => $p['start_date'] > date('Y-m-d') ? '—' : ($issues ? implode('; ', $issues) : 'All clear'),
                                  'closed' => $p['status'] !== 'open' ? $p['closed_by'] . ' ' . fp_fmt_dt($p['closed_at']) : '', '_href' => fp_url('close', ['month' => $ym])];
            }
            break;

        case 'ap_aging':
        case 'ar_aging':
            $rows = $key === 'ap_aging' ? fp_ap_aging($f['to'], $f['entity']) : fp_ar_aging($f['to'], $f['entity']);
            $rep['sub'] = 'As at ' . fp_fmt_date($f['to']);
            $rep['cols'] = ['name' => [$key === 'ap_aging' ? 'Vendor' : 'Customer', 'text'], 'docs' => ['Documents', 'num']];
            foreach (fp_aging_buckets() as $k => $label) {
                $rep['cols'][$k] = [$label, 'money'];
            }
            $rep['cols']['total'] = ['Total', 'money'];
            $tot = ['name' => 'Totals', 'docs' => 0, '_cls' => 'total'] + array_fill_keys(array_merge(array_keys(fp_aging_buckets()), ['total']), 0.0);
            foreach ($rows as $r) {
                $rep['rows'][] = $r + ['_href' => fp_url($key === 'ap_aging' ? 'vendor' : 'customer', ['id' => $r['id']])];
                foreach (array_merge(array_keys(fp_aging_buckets()), ['total', 'docs']) as $k) {
                    $tot[$k] += $r[$k];
                }
            }
            $rep['rows'][] = $tot;
            $control = fp_balances(['to' => $f['to'], 'entity' => $f['entity']], false)[fp_acct($key === 'ap_aging' ? 'ap' : 'ar')] ?? [0, 0];
            $ledger = $key === 'ap_aging' ? $control[1] - $control[0] : $control[0] - $control[1];
            $rep['checks'][] = abs($ledger - $tot['total']) < 0.5
                ? ['ok', 'Agrees with the ' . ($key === 'ap_aging' ? 'trade payables' : 'trade receivables') . ' control account (' . fp_money($ledger) . ').']
                : ['warn', 'The control account shows ' . fp_money($ledger) . '; the difference of ' . fp_money($ledger - $tot['total']) . ' is ' . ($key === 'ap_aging' ? 'debit notes and advances not yet applied to bills.' : 'unallocated receipts and credit notes not yet applied to invoices.')];
            if ($key === 'ar_aging') {
                $dso = fp_dso(90, $f['entity']);
                $rep['note'] = 'Days sales outstanding (last 90 days): ' . ($dso === null ? 'no sales in the period' : $dso . ' days') . '.';
            }
            break;

        case 'vendor_payments':
        case 'collections':
            $rep['sub'] = $period;
            if ($key === 'vendor_payments') {
                $rep['cols'] = ['date' => ['Paid on', 'date'], 'number' => ['Payment', 'text'], 'kind' => ['Kind', 'text'], 'payee' => ['Payee', 'text'], 'ref' => ['Bank reference', 'text'], 'amount' => ['Amount', 'money']];
                foreach (fp_all('SELECT * FROM fp_payments WHERE status = "completed" AND completed_on BETWEEN ? AND ? ORDER BY completed_on, id', 'ss', [$f['from'], $f['to']]) as $p) {
                    $rep['rows'][] = ['date' => $p['completed_on'], 'number' => $p['number'], 'kind' => fp_payment_kinds()[$p['kind']], 'payee' => $p['payee_name'], 'ref' => $p['bank_ref'],
                                      'amount' => fp_r2((float) $p['amount'] * (float) $p['fx_rate']), '_href' => fp_url('payment', ['id' => $p['id']])];
                }
            } else {
                $rep['cols'] = ['date' => ['Received on', 'date'], 'number' => ['Receipt', 'text'], 'customer' => ['Customer', 'text'], 'ref' => ['Reference', 'text'], 'amount' => ['Amount', 'money'],
                                'tds' => ['TDS', 'money'], 'unalloc' => ['Unallocated', 'money']];
                foreach (fp_all('SELECT r.*, c.name AS customer FROM fp_receipts r JOIN fp_customers c ON c.id = r.customer_id WHERE r.status = "posted" AND r.receipt_date BETWEEN ? AND ? ORDER BY r.receipt_date', 'ss', [$f['from'], $f['to']]) as $r) {
                    $rep['rows'][] = ['date' => $r['receipt_date'], 'number' => $r['number'], 'customer' => $r['customer'], 'ref' => $r['reference'], 'amount' => fp_r2((float) $r['amount'] * (float) $r['fx_rate']),
                                      'tds' => (float) $r['tds_amount'], 'unalloc' => fp_receipt_unallocated($r), '_href' => fp_url('receipt', ['id' => $r['id']])];
                }
            }
            $sum = ['_cls' => 'total', array_key_first($rep['cols']) => 'Total', 'amount' => array_sum(array_column($rep['rows'], 'amount'))];
            $rep['rows'][] = $sum;
            break;

        case 'vendor_spend':
            $rep['sub'] = $period . ' · posted bills less debit notes';
            $rep['cols'] = ['vendor' => ['Vendor', 'text'], 'bills' => ['Bills', 'num'], 'net' => ['Net of tax', 'money'], 'tax' => ['Tax', 'money'], 'total' => ['Total', 'money'], 'share' => ['Share', 'pct']];
            $rows = fp_all('SELECT v.id, v.name, SUM(b.kind = "bill") n, SUM(CASE WHEN b.kind = "bill" THEN 1 ELSE -1 END * b.subtotal * b.fx_rate) net,
                                   SUM(CASE WHEN b.kind = "bill" THEN 1 ELSE -1 END * b.tax_total * b.fx_rate) tax FROM fp_bills b JOIN fp_vendors v ON v.id = b.vendor_id
                             WHERE b.journal_id IS NOT NULL AND b.bill_date BETWEEN ? AND ? GROUP BY v.id, v.name ORDER BY net DESC', 'ss', [$f['from'], $f['to']]);
            $all = array_sum(array_map(static fn($r) => (float) $r['net'], $rows));
            foreach ($rows as $r) {
                $rep['rows'][] = ['vendor' => $r['name'], 'bills' => (int) $r['n'], 'net' => (float) $r['net'], 'tax' => (float) $r['tax'], 'total' => (float) $r['net'] + (float) $r['tax'],
                                  'share' => $all > 0 ? (float) $r['net'] / $all : null, '_href' => fp_url('vendor', ['id' => $r['id']])];
            }
            break;

        case 'bva':
            $mf = fp_fy_month_no($f['from']);
            $mt = fp_fy_month_no($f['to']);
            $months = range(max(1, fp_fy_of($f['from']) === $f['fy'] ? $mf : 1), min(12, fp_fy_of($f['to']) === $f['fy'] ? $mt : 12));
            $rows = fp_budget_vs_actual($f['fy'], $f, $months);
            $rep['sub'] = 'FY ' . fp_fy_label($f['fy']) . ', ' . date('M Y', strtotime(fp_fy_month($f['fy'], min($months)) . '-01')) . ' – ' . date('M Y', strtotime(fp_fy_month($f['fy'], max($months)) . '-01'));
            $rep['cols'] = ['account' => ['Account', 'text'], 'cc' => ['Cost centre', 'text'], 'budget' => ['Budget', 'money'], 'actual' => ['Actual', 'money'], 'committed' => ['Committed', 'money'],
                            'available' => ['Available', 'money'], 'variance' => ['Variance (budget − actual)', 'money'], 'util' => ['Used', 'pct']];
            $tot = ['account' => 'Totals', '_cls' => 'total', 'budget' => 0, 'actual' => 0, 'committed' => 0, 'available' => 0, 'variance' => 0];
            foreach ($rows as $r) {
                $rep['rows'][] = ['account' => $r['account_code'] . ' ' . $r['account_name'], 'cc' => $r['cc_code'] ?: 'All', 'budget' => $r['budget'], 'actual' => $r['actual'],
                                  'committed' => $r['committed'], 'available' => $r['available'], 'variance' => $r['variance'], 'util' => $r['utilisation'],
                                  '_href' => fp_url('ledger', ['account' => $r['account_id'], 'from' => $f['from'], 'to' => $f['to']] + ($r['cost_centre_id'] ? ['cc' => $r['cost_centre_id']] : []))];
                foreach (['budget', 'actual', 'committed', 'available', 'variance'] as $k) {
                    $tot[$k] += $r[$k];
                }
            }
            $tot['util'] = $tot['budget'] > 0 ? ($tot['actual'] + $tot['committed']) / $tot['budget'] : null;
            $rep['rows'][] = $tot;
            $rep['basis'] = 'Budget: approved budgets in force. Actual: posted journals. Committed: approved purchase orders not yet billed.';
            break;

        case 'dept':
        case 'project':
            $by = $key === 'dept' ? 'cost_centre_id' : 'project_id';
            $tbl = $key === 'dept' ? 'fp_cost_centres' : 'fp_projects';
            [$w, $t, $p] = fp_ledger_where(['exclude_closing' => 1] + $f);
            $rows = fp_all('SELECT x.id, COALESCE(x.code, "—") code, COALESCE(x.name, "Not assigned") name, a.type, SUM(l.debit_base) dr, SUM(l.credit_base) cr
                              FROM fp_journal_lines l JOIN fp_journals j ON j.id = l.journal_id JOIN fp_accounts a ON a.id = l.account_id LEFT JOIN ' . $tbl . ' x ON x.id = l.' . $by . '
                             WHERE a.type IN ("expense","income") AND (a.type = "expense" OR ? = "dept")' . $w . ($key === 'project' ? ' AND l.project_id IS NOT NULL' : '') . '
                             GROUP BY x.id, x.code, x.name, a.type', 's' . $t, array_merge([$key], $p));
            $agg = [];
            foreach ($rows as $r) {
                $k = (int) $r['id'];
                $agg[$k] ??= ['code' => $r['code'], 'name' => $r['name'], 'expense' => 0.0, 'income' => 0.0, 'id' => $r['id']];
                $agg[$k][$r['type']] += fp_natural($r['type'], (float) $r['dr'], (float) $r['cr']);
            }
            uasort($agg, static fn($a, $b) => $b['expense'] <=> $a['expense']);
            $rep['sub'] = $period;
            $rep['cols'] = ['code' => ['Code', 'text'], 'name' => [$key === 'dept' ? 'Cost centre' : 'Project', 'text'], 'expense' => ['Expenses', 'money']]
                         + ($key === 'dept' ? ['income' => ['Income', 'money'], 'net' => ['Net contribution', 'money']] : []);
            $all = array_sum(array_column($agg, 'expense'));
            foreach ($agg as $a) {
                $rep['rows'][] = ['code' => $a['code'], 'name' => $a['name'], 'expense' => fp_r2($a['expense']), 'income' => fp_r2($a['income']), 'net' => fp_r2($a['income'] - $a['expense']),
                                  '_href' => fp_url('reports', ['r' => 'tb', $key === 'dept' ? 'cc' : 'project' => $a['id'], 'from' => $f['from'], 'to' => $f['to']])];
            }
            $rep['rows'][] = ['name' => 'Total', 'expense' => fp_r2($all), '_cls' => 'total'];
            break;

        case 'claims':
            $rep['sub'] = $period . ' · by submission date';
            $rep['cols'] = ['number' => ['Claim', 'text'], 'employee' => ['Employee', 'text'], 'cc' => ['Cost centre', 'text'], 'purpose' => ['Purpose', 'text'], 'status' => ['Status', 'text'],
                            'total' => ['Claimed', 'money'], 'net' => ['Reimbursable', 'money'], 'paid' => ['Paid', 'money']];
            foreach (fp_all('SELECT c.*, cc.code AS cc_code FROM fp_claims c LEFT JOIN fp_cost_centres cc ON cc.id = c.cost_centre_id
                              WHERE DATE(COALESCE(c.submitted_at, c.created_at)) BETWEEN ? AND ?' . ($f['cc'] ? ' AND c.cost_centre_id = ' . (int) $f['cc'] : '') . ' ORDER BY c.employee_name, c.id', 'ss', [$f['from'], $f['to']]) as $c) {
                $rep['rows'][] = ['number' => $c['number'], 'employee' => $c['employee_name'], 'cc' => $c['cc_code'], 'purpose' => $c['purpose'], 'status' => fp_claim_statuses()[$c['status']][0],
                                  'total' => (float) $c['total'], 'net' => (float) $c['net_payable'], 'paid' => (float) $c['paid_amount'], '_href' => fp_url('claim', ['id' => $c['id']])];
            }
            $rep['rows'][] = ['number' => 'Total', '_cls' => 'total', 'total' => array_sum(array_column($rep['rows'], 'total')), 'net' => array_sum(array_column($rep['rows'], 'net')),
                              'paid' => array_sum(array_column($rep['rows'], 'paid'))];
            $rep['basis'] = 'Claims as submitted, whatever their status; only approved claims are in the ledger.';
            break;

        case 'assets':
            $rep['sub'] = 'As at ' . fp_fmt_date($f['to']) . ' · depreciation in ' . $period;
            $rep['cols'] = ['tag' => ['Tag', 'text'], 'name' => ['Asset', 'text'], 'category' => ['Category', 'text'], 'acquired' => ['Acquired', 'date'], 'cost' => ['Cost', 'money'],
                            'dep' => ['Depreciation in period', 'money'], 'accum' => ['Accumulated', 'money'], 'book' => ['Book value', 'money'], 'status' => ['Status', 'text']];
            foreach (fp_all('SELECT a.*, c.name AS category FROM fp_assets a JOIN fp_asset_categories c ON c.id = a.category_id WHERE a.acquired_on <= ? ORDER BY a.tag', 's', [$f['to']]) as $a) {
                $dep = fp_val('SELECT COALESCE(SUM(l.amount),0) FROM fp_dep_lines l JOIN fp_dep_runs r ON r.id = l.run_id WHERE l.asset_id = ? AND r.period BETWEEN ? AND ?',
                              'iss', [(int) $a['id'], substr($f['from'], 0, 7), substr($f['to'], 0, 7)]);
                $rep['rows'][] = ['tag' => $a['tag'], 'name' => $a['name'], 'category' => $a['category'], 'acquired' => $a['acquired_on'], 'cost' => (float) $a['cost'], 'dep' => $dep,
                                  'accum' => (float) $a['accumulated'], 'book' => fp_r2((float) $a['cost'] - (float) $a['accumulated']), 'status' => ucfirst(str_replace('_', ' ', $a['status'])),
                                  '_href' => fp_url('asset', ['id' => $a['id']])];
            }
            $rep['rows'][] = ['tag' => 'Total', '_cls' => 'total'] + array_combine(['cost', 'dep', 'accum', 'book'], array_map(static fn($k) => array_sum(array_column($rep['rows'], $k)), ['cost', 'dep', 'accum', 'book']));
            $rep['basis'] = 'The register; accumulated depreciation is to date.';
            break;

        case 'gst':
            $g = fp_gst_summary($f['from'], $f['to'], $f['entity']);
            $rep['sub'] = $period;
            $rep['cols'] = ['line' => ['', 'text'], 'output' => ['Output tax', 'money'], 'input' => ['Input credit', 'money'], 'net' => ['Net payable', 'money']];
            foreach (['cgst' => 'CGST', 'sgst' => 'SGST', 'igst' => 'IGST'] as $k => $label) {
                $rep['rows'][] = ['line' => $label] + $g[$k];
            }
            $rep['rows'][] = ['line' => 'Total'] + $g['total'] + ['_cls' => 'total'];
            $rep['rows'][] = ['line' => 'Outward supplies (sales)', '_cls' => 'head'];
            foreach (fp_gst_sales($f['from'], $f['to']) as $s) {
                $sign = $s['kind'] === 'credit_note' ? -1 : 1;
                $rep['rows'][] = ['line' => $s['invoice_date'] . ' ' . $s['number'] . ' — ' . $s['customer'] . ($s['gstin'] ? ' (' . $s['gstin'] . ')' : ' (unregistered)'),
                                  'output' => $sign * (float) $s['tax_total'] * (float) $s['fx_rate'], 'net' => $sign * (float) $s['subtotal'] * (float) $s['fx_rate']];
            }
            $rep['rows'][] = ['line' => 'Inward supplies (purchases)', '_cls' => 'head'];
            foreach (fp_gst_purchases($f['from'], $f['to']) as $s) {
                $sign = $s['kind'] === 'debit_note' ? -1 : 1;
                $rep['rows'][] = ['line' => $s['bill_date'] . ' ' . $s['number'] . ' — ' . $s['vendor'] . ' inv ' . $s['bill_no'] . ($s['gstin'] ? ' (' . $s['gstin'] . ')' : ' (no GSTIN)'),
                                  'input' => $sign * (float) $s['tax_total'] * (float) $s['fx_rate'], 'net' => $sign * (float) $s['subtotal'] * (float) $s['fx_rate']];
            }
            $rep['note'] = 'Registers list taxable value in the Net column. Use them to prepare and reconcile your returns; the return itself is filed on the GST portal.';
            break;

        case 'tds':
            $rep['sub'] = $period;
            $rep['cols'] = ['section' => ['Section', 'text'], 'date' => ['Bill date', 'date'], 'bill' => ['Bill', 'text'], 'vendor' => ['Vendor', 'text'], 'pan' => ['PAN', 'text'],
                            'base' => ['Amount paid / credited', 'money'], 'rate' => ['Rate', 'num'], 'tds' => ['TDS', 'money']];
            foreach (fp_tds_register($f['from'], $f['to']) as $t) {
                $rep['rows'][] = ['section' => $t['section'], 'date' => $t['bill_date'], 'bill' => $t['number'], 'vendor' => $t['vendor'], 'pan' => $t['pan'] ?: 'MISSING',
                                  'base' => (float) $t['subtotal'], 'rate' => (float) $t['rate'], 'tds' => (float) $t['tds_total']];
            }
            $rep['rows'][] = ['section' => 'Total', '_cls' => 'total', 'tds' => array_sum(array_column($rep['rows'], 'tds'))];
            $bal = fp_balances(['to' => $f['to']], false)[fp_acct('tds_payable')] ?? [0, 0];
            $rep['note'] = 'TDS payable in the ledger at ' . fp_fmt_date($f['to']) . ': ' . fp_money($bal[1] - $bal[0]) . ' (deducted but not yet deposited).';
            break;

        case 'compliance':
            $rep['sub'] = 'Statutory filing calendar and open compliance issues';
            $rep['cols'] = ['due' => ['Due', 'date'], 'item' => ['Return / issue', 'text'], 'period' => ['Period', 'text'], 'status' => ['Status', 'text'], 'detail' => ['Detail', 'text']];
            foreach (fp_filings(['from' => $f['from'], 'to' => $f['to']]) as $x) {
                $rep['rows'][] = ['due' => $x['due_date'], 'item' => fabo_filing_label($x['kind']), 'period' => $x['period_label'],
                                  'status' => fabo_filing_overdue($x) ? 'Overdue' : fabo_filing_state_label($x['state']), 'detail' => trim(($x['ack_no'] ? 'Ack ' . $x['ack_no'] . ' ' : '') . ((float) $x['penalty'] > 0 ? 'Penalty ' . fp_money($x['penalty']) : ''))];
            }
            foreach (fp_all('SELECT * FROM fp_issues WHERE status IN ("open","in_progress") ORDER BY due_date') as $i) {
                $rep['rows'][] = ['due' => $i['due_date'], 'item' => 'Issue: ' . $i['title'], 'period' => $i['area'], 'status' => ucfirst(str_replace('_', ' ', $i['status'])), 'detail' => ucfirst($i['severity']) . ' severity'];
            }
            $rep['basis'] = 'The filing register and the compliance issue log.';
            break;
    }
    $rep['generated'] = date('d M Y, H:i') . ' by ' . fp_actor();
    return $rep;
}

/** A report cell as text for export. */
function fp_report_cell($v, string $type)
{
    if ($v === null || $v === '') {
        return '';
    }
    return match ($type) {
        'money' => round((float) $v, 2),
        'num' => is_numeric($v) ? $v + 0 : $v,
        'pct' => round((float) $v * 100, 1),
        default => (string) $v,
    };
}

/** Sends a report as CSV or Excel. Every export is in the audit trail. */
function fp_report_export(array $rep, string $format): void
{
    fp_need('reports.export');
    fp_audit('report', null, 'export', $rep['title'] . ' (' . $rep['sub'] . ') exported as ' . strtoupper($format));
    $name = preg_replace('/[^A-Za-z0-9]+/', '-', $rep['title']) . '-' . date('Ymd-His');
    $head = array_map(static fn($c) => $c[0] . ($c[1] === 'pct' ? ' (%)' : ''), $rep['cols']);
    if ($format === 'xlsx') {
        require_once __DIR__ . '/../xlsx.php';
        $x = new FaboXlsx();
        $rows = [array_values($head)];
        foreach ($rep['rows'] as $r) {
            $line = [];
            foreach ($rep['cols'] as $k => [$label, $type]) {
                $v = $r[$k] ?? null;
                $line[] = $v === null || $v === '' ? '' : ($type === 'money' ? ['m' => (float) $v] : ($type === 'pct' ? ['p' => (float) $v] : ($type === 'date' ? ['d' => (string) $v] : $v)));
            }
            $rows[] = $line;
        }
        $rows[] = [];
        $rows[] = [$rep['title'] . ' — ' . $rep['sub']];
        $rows[] = ['Generated ' . $rep['generated'] . '. ' . $rep['basis']];
        $x->addSheet(mb_substr($rep['title'], 0, 30), $rows);
        $x->download($name . '.xlsx');
        exit;
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [$rep['title'], $rep['sub'], 'Generated ' . $rep['generated']]);
    fputcsv($out, array_values($head));
    foreach ($rep['rows'] as $r) {
        $line = [];
        foreach ($rep['cols'] as $k => [$label, $type]) {
            $line[] = fp_report_cell($r[$k] ?? null, $type);
        }
        fputcsv($out, $line);
    }
    fputcsv($out, []);
    fputcsv($out, [$rep['basis']]);
    fclose($out);
    exit;
}

/* ============================================================== scheduler */

/**
 * The background work for one organisation: approval reminders and
 * escalations, recurring journals and accrual reversals, receivables
 * reminders and budget alerts. Each step stands alone; one failing does not
 * stop the others.
 */
function fp_cron_org(): array
{
    $GLOBALS['fp_system'] = true;
    $out = [];
    try {
        foreach (['escalations' => 'fp_wf_escalate', 'recurring' => 'fp_recurring_run', 'reminders' => 'fp_ar_auto_remind', 'budget_alerts' => 'fp_budget_alerts_run'] as $k => $fn) {
            try {
                $out[$k] = $fn();
            } catch (Throwable $e) {
                $out[$k] = 'failed: ' . $e->getMessage();
                error_log('FABO finance cron ' . $k . ' org ' . fp_org() . ': ' . $e->getMessage());
            }
        }
        fp_setting_put('last_cron', date('c'));
    } finally {
        $GLOBALS['fp_system'] = false;
    }
    return $out;
}

/** Every organisation with the portal: ours, and each client that has opened it and still holds the grant. */
function fp_cron_all(): array
{
    $res = [];
    $was = fp_org();
    $viewer = $GLOBALS['fp_viewer'] ?? null;
    $GLOBALS['fp_viewer'] = fabo_admin_viewer();
    foreach (array_merge([0], fp_orgs_installed()) as $org) {
        if ($org > 0 && fp_org_grant($org) === 'none') {
            continue;
        }
        fp_use($org);
        $GLOBALS['fp_grant'] = $org === 0 ? 'edit' : fp_org_grant($org);
        try {
            if ($org === 0 && !fp_one("SHOW TABLES LIKE 'fp_settings'")) {
                continue;   // our portal has never been opened
            }
            $res[$org] = fp_cron_org();
        } catch (Throwable $e) {
            $res[$org] = 'failed: ' . $e->getMessage();
        }
    }
    fp_use($was);
    $GLOBALS['fp_viewer'] = $viewer;
    return $res;
}

/**
 * Runs the background work when someone opens the portal and it has not run
 * for an hour — so reminders and recurring entries happen even when the
 * server's scheduler is not set up.
 */
function fp_cron_lazy(): void
{
    $last = fp_setting('last_cron');
    if ($last !== '' && strtotime($last) > time() - 3600) {
        return;
    }
    $viewer = $GLOBALS['fp_viewer'];
    $grant = $GLOBALS['fp_grant'];
    try {
        $GLOBALS['fp_viewer'] = fabo_admin_viewer();
        $GLOBALS['fp_grant'] = 'edit';
        fp_cron_org();
    } catch (Throwable $e) {
        error_log('FABO finance lazy cron: ' . $e->getMessage());
    } finally {
        $GLOBALS['fp_viewer'] = $viewer;
        $GLOBALS['fp_grant'] = $grant;
    }
}

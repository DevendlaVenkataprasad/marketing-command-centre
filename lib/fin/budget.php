<?php
/**
 * Finance Portal — budgets, forecasts and budgetary control.
 *
 * A budget is a set of lines — an account, optionally a cost centre and a
 * project — with an amount for each month of the financial year. It is
 * prepared, approved (Settings → Approval rules), and then in force; it can
 * be frozen (no transfers or revisions) and released again; it is changed
 * only by a transfer between two of its lines, or a revision, each approved.
 * Forecasts and what-if scenarios are budgets with another scenario name,
 * compared side by side and never used for control.
 *
 * THE FORMULAS, the same everywhere:
 *   actual      posted ledger movement on the line's account (and cost centre
 *               and project, where the line names them), debits less credits;
 *   committed   approved purchase orders not yet billed (fp_po_commitments);
 *   available   budget − actual − committed;
 *   utilisation (actual + committed) ÷ budget.
 * A posting is counted once, against the most specific line that covers it.
 *
 * CONTROL. When an order or a bill would take a line past the warning
 * threshold it says so; past the blocking threshold, with control set to
 * "block", it is stopped unless a Finance Manager accepts the exception with
 * a note. Both thresholds and the mode are settings.
 */

function fp_budget(int $id): ?array
{
    return fp_one('SELECT * FROM fp_budgets WHERE id = ?', 'i', [$id]);
}

function fp_budget_lines(int $id): array
{
    return fp_all('SELECT l.*, a.code AS account_code, a.name AS account_name, c.code AS cc_code, c.name AS cc_name, p.code AS project_code
                     FROM fp_budget_lines l JOIN fp_accounts a ON a.id = l.account_id LEFT JOIN fp_cost_centres c ON c.id = l.cost_centre_id
                     LEFT JOIN fp_projects p ON p.id = l.project_id WHERE l.budget_id = ? ORDER BY a.code, c.code', 'i', [$id]);
}

function fp_budget_statuses(): array
{
    return ['draft' => ['Draft', ''], 'submitted' => ['Awaiting approval', 'amber'], 'approved' => ['Approved — in force', 'green'],
            'frozen' => ['Frozen', 'blue'], 'rejected' => ['Rejected', 'red'], 'superseded' => ['Superseded', '']];
}

function fp_budget_scenarios(): array
{
    return ['budget' => 'Budget (used for control)', 'forecast' => 'Forecast', 'best' => 'Best case', 'worst' => 'Worst case', 'scenario' => 'Other scenario'];
}

function fp_budget_save(?int $id, array $p): int
{
    fp_need('budget.prepare');
    $fy = (int) ($p['fy'] ?? fp_fy_of());
    if ($fy < 2000 || $fy > 2100) {
        throw new InvalidArgumentException('Choose the financial year.');
    }
    $name = fp_str($p['name'] ?? '', 160, true, 'Name');
    $kind = ($p['kind'] ?? 'operating') === 'capital' ? 'capital' : 'operating';
    $scenario = isset(fp_budget_scenarios()[$p['scenario'] ?? '']) ? $p['scenario'] : 'budget';
    $entity = fp_ref($p['entity_id'] ?? 1, 'fp_entities', 'entity', true, true);
    $assume = fp_str($p['assumptions'] ?? '', 5000);
    $lines = [];
    $seen = [];
    $total = 0;
    foreach ((array) ($p['lines'] ?? []) as $i => $l) {
        $acc = (int) ($l['account_id'] ?? 0);
        if (!$acc) {
            continue;
        }
        $a = fp_account($acc);
        if (!$a || (int) $a['is_group'] === 1 || !in_array($a['type'], $kind === 'capital' ? ['asset'] : ['expense', 'income'], true)) {
            throw new InvalidArgumentException('Line ' . ($i + 1) . ': ' . ($kind === 'capital' ? 'a capital budget is for asset accounts.' : 'an operating budget is for income and expense accounts.'));
        }
        $cc = fp_ref($l['cost_centre_id'] ?? null, 'fp_cost_centres', 'cost centre');
        $pr = fp_ref($l['project_id'] ?? null, 'fp_projects', 'project');
        $key = $acc . '-' . $cc . '-' . $pr;
        if (isset($seen[$key])) {
            throw new InvalidArgumentException('Account ' . $a['code'] . ' appears twice for the same cost centre and project. Put it on one line.');
        }
        $seen[$key] = true;
        $m = [];
        $annual = fp_dec($l['annual'] ?? '', false, 'Annual amount', 0);
        for ($n = 1; $n <= 12; $n++) {
            $m[$n] = (float) (fp_dec($l['m' . $n] ?? '', false, 'Month ' . $n, -1e12) ?? 0);
        }
        if ($annual !== null && array_sum($m) == 0) {
            // Spread evenly; the paise left over go to the last month.
            $each = floor((float) $annual * 100 / 12) / 100;
            for ($n = 1; $n <= 12; $n++) {
                $m[$n] = $n < 12 ? $each : fp_r2((float) $annual - $each * 11);
            }
        }
        $lt = array_sum(array_map('fp_paise', $m));
        $lines[] = [$acc, $cc, $pr, $m, $lt / 100, fp_str($l['notes'] ?? '', 255)];
        $total += $lt;
    }
    if (!$lines) {
        throw new InvalidArgumentException('Add at least one budget line.');
    }
    return fp_tx(static function () use ($id, $p, $fy, $name, $kind, $scenario, $entity, $assume, $lines, $total) {
        if ($id) {
            $b = fp_budget($id);
            if (!$b || $b['status'] !== 'draft') {
                throw new InvalidArgumentException('Only a draft budget can be edited. An approved one is changed by a transfer or a revision.');
            }
            fp_bump('fp_budgets', $id, $p['version'] ?? 0);
            fp_exec('UPDATE fp_budgets SET fy=?, name=?, kind=?, scenario=?, entity_id=?, assumptions=?, total=? WHERE id=?', 'isssissi',
                    [$fy, $name, $kind, $scenario, $entity, $assume ?: null, fp_amt($total / 100), $id]);
            fp_exec('DELETE FROM fp_budget_lines WHERE budget_id = ?', 'i', [$id]);
        } else {
            $id = fp_exec('INSERT INTO fp_budgets (number, entity_id, fy, name, kind, scenario, assumptions, total, prepared_by_id, prepared_by) VALUES (?,?,?,?,?,?,?,?,?,?)', 'siisssssis',
                          [fp_next_no('BUD', fp_fy_range($fy)[0]), $entity, $fy, $name, $kind, $scenario, $assume ?: null, fp_amt($total / 100), fp_me(), fp_actor()]);
            fp_audit('budget', $id, 'create', 'Budget ' . $name . ' drafted (' . fp_money($total / 100) . ')');
        }
        foreach ($lines as [$acc, $cc, $pr, $m, $lt, $notes]) {
            fp_exec('INSERT INTO fp_budget_lines (budget_id, account_id, cost_centre_id, project_id, m1, m2, m3, m4, m5, m6, m7, m8, m9, m10, m11, m12, total, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    'iiiissssssssssssss', array_merge([$id, $acc, $cc, $pr], array_map('fp_amt', array_values($m)), [fp_amt($lt), $notes ?: null]));
        }
        return $id;
    });
}

function fp_budget_submit(int $id, $version): string
{
    fp_need('budget.prepare');
    $b = fp_budget($id);
    if (!$b || $b['status'] !== 'draft') {
        throw new InvalidArgumentException('Only a draft budget can be submitted.');
    }
    return fp_tx(static function () use ($id, $b, $version) {
        fp_bump('fp_budgets', $id, $version);
        fp_exec('UPDATE fp_budgets SET status = "submitted", return_note = NULL WHERE id = ?', 'i', [$id]);
        fp_audit('budget', $id, 'submit', 'Budget submitted for approval');
        fp_wf_start('budget', $id, (float) $b['total'], null, (int) $b['entity_id'], (int) $b['prepared_by_id']);
        return 'Submitted for approval.';
    });
}

function fp_wf_on_budget(string $event, array $b, string $comment): void
{
    $id = (int) $b['id'];
    if ($event === 'approved') {
        fp_exec('UPDATE fp_budgets SET status = "approved", approved_by = ?, approved_at = NOW() WHERE id = ?', 'si', [fp_actor(), $id]);
        if ($b['scenario'] === 'budget') {
            // One budget is in force per year, entity and kind: an earlier one (or the one revised) steps aside.
            fp_exec('UPDATE fp_budgets SET status = "superseded" WHERE id <> ? AND fy = ? AND entity_id = ? AND kind = ? AND scenario = "budget" AND status IN ("approved","frozen")',
                    'iiis', [$id, (int) $b['fy'], (int) $b['entity_id'], $b['kind']]);
        }
        return;
    }
    fp_exec('UPDATE fp_budgets SET status = ?, return_note = ? WHERE id = ?', 'ssi', [$event === 'rejected' ? 'rejected' : 'draft', $comment, $id]);
}

function fp_budget_freeze(int $id, bool $freeze, string $note, $version): string
{
    fp_need('budget.control');
    $b = fp_budget($id);
    if (!$b || $b['status'] !== ($freeze ? 'approved' : 'frozen')) {
        throw new InvalidArgumentException($freeze ? 'Only an approved budget can be frozen.' : 'This budget is not frozen.');
    }
    fp_bump('fp_budgets', $id, $version);
    fp_exec('UPDATE fp_budgets SET status = ? WHERE id = ?', 'si', [$freeze ? 'frozen' : 'approved', $id]);
    fp_audit('budget', $id, $freeze ? 'freeze' : 'release', ($freeze ? 'Frozen' : 'Released') . ($note ? ': ' . $note : ''));
    return $freeze ? 'Budget frozen: no transfers or revisions until it is released.' : 'Budget released.';
}

/** Starts a revision: a draft copy of an approved budget that, once approved, replaces it. */
function fp_budget_revise(int $id, string $reason): int
{
    fp_need('budget.prepare');
    $b = fp_budget($id);
    if (!$b || $b['status'] !== 'approved') {
        throw new InvalidArgumentException('Only an approved (not frozen) budget can be revised.');
    }
    $reason = fp_str($reason, 500, true, 'The reason for the revision');
    if (fp_one('SELECT id FROM fp_budgets WHERE parent_id = ? AND status IN ("draft","submitted")', 'i', [$id])) {
        throw new InvalidArgumentException('A revision of this budget is already being prepared.');
    }
    return fp_tx(static function () use ($id, $b, $reason) {
        $new = fp_exec('INSERT INTO fp_budgets (number, entity_id, fy, name, kind, scenario, revision, parent_id, assumptions, total, prepared_by_id, prepared_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                       'siisssiissis', [fp_next_no('BUD', fp_fy_range((int) $b['fy'])[0]), (int) $b['entity_id'], (int) $b['fy'], $b['name'], $b['kind'], $b['scenario'],
                                        (int) $b['revision'] + 1, $id, trim(($b['assumptions'] ?? '') . "\n\nRevision " . ((int) $b['revision'] + 1) . ': ' . $reason), $b['total'], fp_me(), fp_actor()]);
        fp_exec('INSERT INTO fp_budget_lines (budget_id, account_id, cost_centre_id, project_id, m1, m2, m3, m4, m5, m6, m7, m8, m9, m10, m11, m12, total, notes)
                 SELECT ?, account_id, cost_centre_id, project_id, m1, m2, m3, m4, m5, m6, m7, m8, m9, m10, m11, m12, total, notes FROM fp_budget_lines WHERE budget_id = ?', 'ii', [$new, $id]);
        fp_audit('budget', $new, 'revise', 'Revision ' . ((int) $b['revision'] + 1) . ' of ' . $b['number'] . ' started: ' . $reason);
        return $new;
    });
}

/** Moves budget from one line to another in one month; approved before it applies. */
function fp_transfer_create(array $p): int
{
    fp_need('budget.prepare');
    $b = fp_budget((int) ($p['budget_id'] ?? 0));
    if (!$b || $b['status'] !== 'approved') {
        throw new InvalidArgumentException('Transfers are made within an approved budget that is not frozen.');
    }
    $from = (int) ($p['from_line_id'] ?? 0);
    $to = (int) ($p['to_line_id'] ?? 0);
    $lf = fp_one('SELECT * FROM fp_budget_lines WHERE id = ? AND budget_id = ?', 'ii', [$from, (int) $b['id']]);
    $lt = fp_one('SELECT * FROM fp_budget_lines WHERE id = ? AND budget_id = ?', 'ii', [$to, (int) $b['id']]);
    if (!$lf || !$lt || $from === $to) {
        throw new InvalidArgumentException('Choose two different lines of this budget.');
    }
    $month = (int) ($p['month_no'] ?? 0);
    if ($month < 1 || $month > 12) {
        throw new InvalidArgumentException('Choose the month the transfer is for.');
    }
    $amt = (float) fp_dec($p['amount'] ?? '', true, 'Amount', 0.01);
    if ($amt > (float) $lf['m' . $month] + 0.004) {
        throw new InvalidArgumentException('That line has only ' . fp_money($lf['m' . $month]) . ' in ' . date('M Y', strtotime(fp_fy_month((int) $b['fy'], $month) . '-01')) . '.');
    }
    $reason = fp_str($p['reason'] ?? '', 500, true, 'The reason');
    return fp_tx(static function () use ($b, $from, $to, $month, $amt, $reason) {
        $id = fp_exec('INSERT INTO fp_budget_transfers (number, budget_id, from_line_id, to_line_id, month_no, amount, reason, status, prepared_by_id, prepared_by) VALUES (?,?,?,?,?,?,?,"submitted",?,?)',
                      'siiiissis', [fp_next_no('BT', date('Y-m-d')), (int) $b['id'], $from, $to, $month, fp_amt($amt), $reason, fp_me(), fp_actor()]);
        fp_audit('transfer', $id, 'create', 'Budget transfer of ' . fp_money($amt) . ' requested: ' . $reason);
        fp_wf_start('transfer', $id, $amt, null, (int) $b['entity_id'], fp_me());
        return $id;
    });
}

function fp_wf_on_transfer(string $event, array $t, string $comment): void
{
    $id = (int) $t['id'];
    if ($event !== 'approved') {
        fp_exec('UPDATE fp_budget_transfers SET status = ?, return_note = ? WHERE id = ?', 'ssi', [$event === 'rejected' ? 'rejected' : 'cancelled', $comment, $id]);
        return;
    }
    $b = fp_budget((int) $t['budget_id']);
    if ($b['status'] !== 'approved') {
        throw new InvalidArgumentException('The budget is no longer open to transfers (it is ' . $b['status'] . ').');
    }
    $col = 'm' . (int) $t['month_no'];
    $lf = fp_one('SELECT ' . $col . ' v FROM fp_budget_lines WHERE id = ? FOR UPDATE', 'i', [(int) $t['from_line_id']]);
    if ((float) $lf['v'] + 0.004 < (float) $t['amount']) {
        throw new InvalidArgumentException('The line it comes from no longer has enough budget in that month.');
    }
    fp_exec('UPDATE fp_budget_lines SET ' . $col . ' = ' . $col . ' - ?, total = total - ? WHERE id = ?', 'ssi', [$t['amount'], $t['amount'], (int) $t['from_line_id']]);
    fp_exec('UPDATE fp_budget_lines SET ' . $col . ' = ' . $col . ' + ?, total = total + ? WHERE id = ?', 'ssi', [$t['amount'], $t['amount'], (int) $t['to_line_id']]);
    fp_exec('UPDATE fp_budget_transfers SET status = "approved" WHERE id = ?', 'i', [$id]);
    fp_audit('budget', (int) $t['budget_id'], 'transfer', 'Transfer ' . $t['number'] . ' applied: ' . fp_money($t['amount']));
}

/** The budgets in force for a financial year (approved or frozen, scenario "budget"). */
function fp_budgets_active(int $fy, ?int $entity = null): array
{
    return fp_all('SELECT * FROM fp_budgets WHERE fy = ? AND scenario = "budget" AND status IN ("approved","frozen")' . ($entity ? ' AND entity_id = ' . (int) $entity : ''), 'i', [$fy]);
}

/** Which of $lines (budget lines) covers a posting best: exact cost centre and project first, then wider lines. */
function fp_budget_best(array $lines, int $acc, ?int $cc, ?int $pr): ?int
{
    $best = null;
    $score = -1;
    foreach ($lines as $i => $l) {
        if ((int) $l['account_id'] !== $acc) {
            continue;
        }
        $lcc = $l['cost_centre_id'] !== null ? (int) $l['cost_centre_id'] : null;
        $lpr = $l['project_id'] !== null ? (int) $l['project_id'] : null;
        if (($lcc !== null && $lcc !== $cc) || ($lpr !== null && $lpr !== $pr)) {
            continue;
        }
        $s = ($lcc !== null ? 2 : 0) + ($lpr !== null ? 1 : 0);
        if ($s > $score) {
            $score = $s;
            $best = $i;
        }
    }
    return $best;
}

/**
 * Budget against actual and committed for a financial year, line by line.
 * $months limits the months counted (1..12 of the year; default all of them).
 * Each line: budget, actual, committed, available, utilisation, variance.
 */
function fp_budget_vs_actual(int $fy, array $f = [], ?array $months = null): array
{
    $months = $months ?: range(1, 12);
    $lines = [];
    foreach (fp_budgets_active($fy, $f['entity'] ?? null) as $b) {
        foreach (fp_budget_lines((int) $b['id']) as $l) {
            if (!empty($f['cc']) && (int) $l['cost_centre_id'] !== (int) $f['cc']) {
                continue;
            }
            if (!empty($f['project']) && (int) $l['project_id'] !== (int) $f['project']) {
                continue;
            }
            $budget = 0.0;
            foreach ($months as $m) {
                $budget += (float) $l['m' . $m];
            }
            $lines[] = $l + ['budget_id' => (int) $b['id'], 'budget_name' => $b['name'], 'budget_status' => $b['status'], 'budget' => fp_r2($budget), 'full_year' => (float) $l['total'],
                             'actual' => 0.0, 'committed' => 0.0];
        }
    }
    if (!$lines) {
        return [];
    }
    [$from] = fp_fy_range($fy);
    $start = fp_fy_month($fy, min($months)) . '-01';
    $end = date('Y-m-t', strtotime(fp_fy_month($fy, max($months)) . '-01'));
    $accIds = implode(',', array_unique(array_map(static fn($l) => (int) $l['account_id'], $lines)));
    [$w, $t, $p] = fp_ledger_where(['from' => $start, 'to' => $end, 'entity' => $f['entity'] ?? null, 'exclude_closing' => 1]);
    foreach (fp_all('SELECT l.account_id, l.cost_centre_id, l.project_id, a.type, SUM(l.debit_base) dr, SUM(l.credit_base) cr FROM fp_journal_lines l
                      JOIN fp_journals j ON j.id = l.journal_id JOIN fp_accounts a ON a.id = l.account_id
                     WHERE l.account_id IN (' . $accIds . ')' . $w . ' GROUP BY l.account_id, l.cost_centre_id, l.project_id, a.type', $t, $p) as $r) {
        $i = fp_budget_best($lines, (int) $r['account_id'], $r['cost_centre_id'] !== null ? (int) $r['cost_centre_id'] : null, $r['project_id'] !== null ? (int) $r['project_id'] : null);
        if ($i !== null) {
            $lines[$i]['actual'] += $r['type'] === 'income' ? (float) $r['cr'] - (float) $r['dr'] : (float) $r['dr'] - (float) $r['cr'];
        }
    }
    foreach (fp_po_commitments() as $c) {
        if (fp_fy_of((string) $c['po_date']) !== $fy) {
            continue;
        }
        $i = fp_budget_best($lines, (int) $c['account_id'], $c['cost_centre_id'] !== null ? (int) $c['cost_centre_id'] : null, $c['project_id'] !== null ? (int) $c['project_id'] : null);
        if ($i !== null) {
            $lines[$i]['committed'] += (float) $c['open_amount'];
        }
    }
    foreach ($lines as &$l) {
        $l['actual'] = fp_r2($l['actual']);
        $l['committed'] = fp_r2($l['committed']);
        $l['available'] = fp_r2($l['budget'] - $l['actual'] - $l['committed']);
        $l['variance'] = fp_r2($l['budget'] - $l['actual']);
        $l['utilisation'] = $l['budget'] > 0 ? ($l['actual'] + $l['committed']) / $l['budget'] : null;
    }
    unset($l);
    return $lines;
}

/**
 * What a purchase order or bill would do to the budget. Lines already
 * committed by an order (po_line_id set) are not counted again. Returns
 * ['messages' => [...], 'block' => bool].
 */
function fp_budget_check(array $docLines, string $date, ?int $excludePo = null): array
{
    $fy = fp_fy_of($date);
    $bva = fp_budget_vs_actual($fy);
    if (!$bva) {
        return ['messages' => [], 'block' => false];
    }
    // Over the whole year: a budget is for the year, phased by month.
    foreach ($bva as &$l) {
        $l['budget'] = $l['full_year'];
        $l['available'] = fp_r2($l['budget'] - $l['actual'] - $l['committed']);
    }
    unset($l);
    if ($excludePo) {
        // An amended order is checked without its own earlier commitment.
        foreach (fp_all('SELECT l.account_id, l.cost_centre_id, l.project_id, GREATEST(0, l.amount * p.fx_rate - l.billed_amount) amt FROM fp_po_lines l JOIN fp_pos p ON p.id = l.po_id WHERE p.id = ?', 'i', [$excludePo]) as $c) {
            $i = fp_budget_best($bva, (int) $c['account_id'], $c['cost_centre_id'] !== null ? (int) $c['cost_centre_id'] : null, $c['project_id'] !== null ? (int) $c['project_id'] : null);
            if ($i !== null) {
                $bva[$i]['committed'] -= (float) $c['amt'];
                $bva[$i]['available'] += (float) $c['amt'];
            }
        }
    }
    $add = [];
    foreach ($docLines as $d) {
        if (!empty($d['po_line_id'])) {
            continue;
        }
        $i = fp_budget_best($bva, (int) $d['account_id'], $d['cost_centre_id'] ?? null, $d['project_id'] ?? null);
        if ($i === null) {
            continue;
        }
        $add[$i] = ($add[$i] ?? 0) + (float) $d['amount'];
    }
    $warn = (float) fp_setting('budget_warn_pct') / 100;
    $blockAt = (float) fp_setting('budget_block_pct') / 100;
    $mode = fp_setting('budget_control');
    $msgs = [];
    $block = false;
    foreach ($add as $i => $amt) {
        $l = $bva[$i];
        if ($l['budget'] <= 0) {
            $msgs[] = $l['account_code'] . ($l['cc_code'] ? '/' . $l['cc_code'] : '') . ' has no budget for FY ' . fp_fy_label($fy) . '.';
            $block = $block || ($mode === 'block');
            continue;
        }
        $after = ($l['actual'] + $l['committed'] + $amt) / $l['budget'];
        if ($after >= $blockAt) {
            $msgs[] = $l['account_code'] . ' ' . $l['account_name'] . ($l['cc_code'] ? ' (' . $l['cc_code'] . ')' : '') . ' would reach ' . fp_pct($after) . ' of its budget; '
                    . fp_money(max(0, $l['available'])) . ' is available.';
            $block = $block || $mode === 'block';
        } elseif ($after >= $warn) {
            $msgs[] = $l['account_code'] . ' ' . $l['account_name'] . ($l['cc_code'] ? ' (' . $l['cc_code'] . ')' : '') . ' would reach ' . fp_pct($after) . ' of its budget.';
        }
    }
    return ['messages' => $msgs, 'block' => $block];
}

/** Tells budget holders once when a line passes the warning threshold and again at 100%. Run by the scheduler. */
function fp_budget_alerts_run(): int
{
    $n = 0;
    $warn = (int) fp_setting('budget_warn_pct');
    foreach (fp_budget_vs_actual(fp_fy_of()) as $l) {
        if ($l['utilisation'] === null) {
            continue;
        }
        foreach (array_unique([$warn, 100]) as $th) {
            if ($l['utilisation'] * 100 < $th) {
                continue;
            }
            if (fp_one('SELECT id FROM fp_budget_alerts WHERE budget_line_id = ? AND threshold = ?', 'ii', [(int) $l['id'], $th])) {
                continue;
            }
            fp_exec('INSERT INTO fp_budget_alerts (budget_line_id, threshold, utilisation) VALUES (?,?,?)', 'iis', [(int) $l['id'], $th, number_format($l['utilisation'] * 100, 2, '.', '')]);
            $owner = $l['cost_centre_id'] ? fp_cc_owner((int) $l['cost_centre_id']) : null;
            fp_notify(array_merge($owner !== null ? [$owner] : [], fp_role_holders(['fpa', 'finance_manager'])), 'Budget ' . ($th >= 100 ? 'used up' : 'at ' . $th . '%'),
                      $l['account_code'] . ' ' . $l['account_name'] . ($l['cc_code'] ? ' (' . $l['cc_code'] . ')' : '') . ' is at ' . fp_pct($l['utilisation']) . ' of its budget: '
                      . fp_money($l['actual']) . ' spent, ' . fp_money($l['committed']) . ' committed, ' . fp_money($l['available']) . ' available.', 'budgets', ['tab' => 'bva']);
            $n++;
        }
    }
    return $n;
}

/** Scenario totals side by side for a year: account => [scenario budget id => full-year amount]. */
function fp_budget_compare(int $fy): array
{
    $docs = fp_all('SELECT * FROM fp_budgets WHERE fy = ? AND status NOT IN ("rejected","superseded") ORDER BY scenario = "budget" DESC, id', 'i', [$fy]);
    $rows = [];
    foreach ($docs as $d) {
        foreach (fp_budget_lines((int) $d['id']) as $l) {
            $k = $l['account_code'] . ' ' . $l['account_name'];
            $rows[$k][(int) $d['id']] = ($rows[$k][(int) $d['id']] ?? 0) + (float) $l['total'];
        }
    }
    ksort($rows);
    return ['docs' => $docs, 'rows' => $rows];
}

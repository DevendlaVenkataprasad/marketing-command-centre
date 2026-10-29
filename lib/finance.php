<?php
/**
 * The Finance desk: statutory filings and what we spend.
 *
 * Invoicing already lives in lib/invoices.php — money coming in. This file is
 * the other two thirds of an accounts function: money going out (expenses) and
 * the compliance calendar (GST, income tax, TDS, PF/ESI, ROC), plus the
 * combined figures that only make sense once you have both sides.
 *
 * A filing or an expense with client_id 0 is our own; anything else belongs to
 * the client named. That single split is what lets every figure below be read
 * either across the whole desk or for one client.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/invoices.php';

/* ------------------------------------------------------------- chrome */

/**
 * The Finance tab strip.
 *
 * Invoices keeps its own page because the workbench there is substantial;
 * this strip is what makes the four of them read as one desk rather than
 * four places. $client keeps the chosen client while you move between tabs.
 */
function fabo_finance_tabs(string $current, int $client = 0, string $base = '',
                           string $period = 'all'): void
{
    $scope = $client > 0 ? $client : null;
    $inv   = fabo_invoice_metrics($scope);
    $fil   = fabo_filing_metrics($scope);
    $exp   = fabo_expense_metrics($scope);

    // A count on the tab is what makes the other tabs findable: Compliance
    // saying "12" is read where the word "Compliance" alone was not.
    $open    = (int) $inv['overdue_count'];
    $overdue = (int) $fil['overdue'];
    $soon    = (int) $fil['due_soon'];

    $tabs = [
        'overview'   => ['Overview',   $base . 'finance.php', '', ''],
        'invoices'   => ['Invoices',   $base . 'invoices.php',
                         $open ? (string) $open : '', 'bad'],
        'compliance' => ['Compliance', $base . 'finance.php?tab=compliance',
                         $overdue ? (string) $overdue : ($soon ? (string) $soon : ''),
                         $overdue ? 'bad' : 'warn'],
        'expenses'   => ['Expenses',   $base . 'finance.php?tab=expenses',
                         $exp['count'] ? (string) $exp['count'] : '', ''],
    ];

    echo '<nav class="subtabs">';
    foreach ($tabs as $key => [$label, $href, $count, $tone]) {
        if ($client > 0) {
            $href .= (str_contains($href, '?') ? '&' : '?') . 'client=' . $client;
        }
        // Whatever period is being read stays chosen across the tabs.
        if ($period !== '' && $period !== 'all') {
            $href .= (str_contains($href, '?') ? '&' : '?') . 'period=' . rawurlencode($period);
        }
        echo '<a' . ($key === $current ? ' class="on"' : '') . ' href="' . e($href) . '">'
           . e($label)
           . ($count !== '' ? ' <span class="c ' . e($tone) . '">' . e($count) . '</span>' : '')
           . '</a>';
    }
    echo '</nav>';
}

/* ------------------------------------------------------------ periods */

/**
 * The windows the Finance desk can be read through, on the Indian financial
 * year: April to March. Everything on the page — invoices, spend, filings —
 * narrows to the same window, so the figures always belong together.
 */
function fabo_finance_periods(): array
{
    return [
        'all'      => 'All time',
        'month'    => 'This month',
        'lastmonth'=> 'Last month',
        'quarter'  => 'This quarter',
        'half'     => 'This half-year',
        'fy'       => 'This financial year',
        'lastfy'   => 'Last financial year',
    ];
}

/** The financial year a date falls in, as the year it starts in. */
function fabo_fy_of(?string $on = null): int
{
    $ts = $on ? strtotime($on) : time();
    return (int) date('n', $ts) >= 4 ? (int) date('Y', $ts) : (int) date('Y', $ts) - 1;
}

/**
 * ['from', 'to', 'label'] for one period key, or null for all time.
 * Quarters and halves are counted from April, not from January.
 */
function fabo_finance_window(string $key): ?array
{
    $fy  = fabo_fy_of();
    $mon = (int) date('n');

    switch ($key) {
        case 'month':
            return ['from'  => date('Y-m-01'),
                    'to'    => date('Y-m-t'),
                    'label' => date('F Y')];

        case 'lastmonth':
            $t = strtotime('first day of last month');
            return ['from'  => date('Y-m-01', $t),
                    'to'    => date('Y-m-t', $t),
                    'label' => date('F Y', $t)];

        case 'quarter':
            // Q1 Apr-Jun, Q2 Jul-Sep, Q3 Oct-Dec, Q4 Jan-Mar.
            $q     = (int) floor(((($mon - 4) + 12) % 12) / 3);          // 0..3
            $start = $q === 3 ? sprintf('%04d-01-01', $fy + 1)
                              : sprintf('%04d-%02d-01', $fy, 4 + $q * 3);
            $end   = date('Y-m-t', strtotime($start . ' +2 month'));
            return ['from'  => $start, 'to' => $end,
                    'label' => 'Q' . ($q + 1) . ' FY ' . $fy . '-' . substr((string) ($fy + 1), 2)];

        case 'half':
            $first = $mon >= 4 && $mon <= 9;
            return $first
                ? ['from'  => sprintf('%04d-04-01', $fy),
                   'to'    => sprintf('%04d-09-30', $fy),
                   'label' => 'Apr-Sep ' . $fy]
                : ['from'  => sprintf('%04d-10-01', $fy),
                   'to'    => sprintf('%04d-03-31', $fy + 1),
                   'label' => 'Oct ' . $fy . ' - Mar ' . ($fy + 1)];

        case 'fy':
            return ['from'  => sprintf('%04d-04-01', $fy),
                    'to'    => sprintf('%04d-03-31', $fy + 1),
                    'label' => 'FY ' . $fy . '-' . substr((string) ($fy + 1), 2)];

        case 'lastfy':
            return ['from'  => sprintf('%04d-04-01', $fy - 1),
                    'to'    => sprintf('%04d-03-31', $fy),
                    'label' => 'FY ' . ($fy - 1) . '-' . substr((string) $fy, 2)];
    }
    return null;   // 'all', and anything unrecognised
}

/* ---------------------------------------------------------- the returns */

/**
 * The returns the desk tracks.
 *
 * 'every' is how often one falls due and 'day' the day of the month it is due
 * — together they are enough to lay out a year in advance. Statutory due dates
 * move with notifications and extensions, so these are the ordinary case and
 * every generated date stays editable.
 */
function fabo_filing_kinds(): array
{
    return [
        'gstr1'  => ['GSTR-1',              'GST',        'monthly',   11],
        'gstr3b' => ['GSTR-3B',             'GST',        'monthly',   20],
        'gstr9'  => ['GSTR-9 annual',       'GST',        'yearly',    31],
        'itr'    => ['Income tax return',   'Income tax', 'yearly',    31],
        'advtax' => ['Advance tax',         'Income tax', 'quarterly', 15],
        'tds24q' => ['TDS 24Q (salary)',    'TDS',        'quarterly', 31],
        'tds26q' => ['TDS 26Q (non-salary)','TDS',        'quarterly', 31],
        'tdspay' => ['TDS payment',         'TDS',        'monthly',    7],
        'pf'     => ['PF (EPF ECR)',        'Payroll',    'monthly',   15],
        'esi'    => ['ESI',                 'Payroll',    'monthly',   15],
        'ptax'   => ['Professional tax',    'Payroll',    'monthly',   20],
        'roc'    => ['ROC / MCA',           'Company',    'yearly',    30],
        'other'  => ['Other filing',        'Other',      'yearly',    31],
    ];
}

function fabo_filing_label(string $kind): string
{
    return fabo_filing_kinds()[$kind][0] ?? $kind;
}

function fabo_filing_group(string $kind): string
{
    return fabo_filing_kinds()[$kind][1] ?? 'Other';
}

/** The groups, in the order the Compliance tab shows them. */
function fabo_filing_groups(): array
{
    $out = [];
    foreach (fabo_filing_kinds() as $v) {
        $out[$v[1]] = true;
    }
    return array_keys($out);
}

function fabo_filing_states(): array
{
    return [
        'pending' => ['Pending',        'amber'],
        'filed'   => ['Filed',          'green'],
        'paid'    => ['Filed and paid', 'green'],
        'late'    => ['Filed late',     'red'],
        'na'      => ['Not applicable', 'grey'],
    ];
}

function fabo_filing_state_label(string $s): string
{
    return fabo_filing_states()[$s][0] ?? $s;
}

function fabo_filing_state_tone(string $s): string
{
    return fabo_filing_states()[$s][1] ?? 'grey';
}

/** Still open, and the due date has gone by. */
function fabo_filing_overdue(array $f): bool
{
    return ($f['state'] ?? '') === 'pending'
        && !empty($f['due_date'])
        && $f['due_date'] < date('Y-m-d');
}

function fabo_valid_date(string $d): bool
{
    return $d !== '' && (bool) preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $d)
        && strtotime($d) !== false;
}

/* ------------------------------------------------------ reading filings */

function fabo_filing(int $id): ?array
{
    return fabo_one('SELECT * FROM filings WHERE id = ?', 'i', [$id]);
}

/**
 * The filing register. Every argument narrows it; passing none returns the
 * whole desk, with anything still open first and soonest due on top, so the
 * next thing to do is always the first row.
 */
function fabo_filings(?int $clientId = null, string $state = '', string $kind = '',
                      int $limit = 400, ?array $win = null): array
{
    $sql = 'SELECT f.*, c.name AS cname, c.organisation AS corg
            FROM filings f LEFT JOIN clients c ON c.id = f.client_id WHERE 1=1';
    $t = '';
    $p = [];
    if ($clientId !== null) { $sql .= ' AND f.client_id = ?'; $t .= 'i'; $p[] = $clientId; }
    $c = fabo_win_clause($win, 'f.due_date');
    $sql .= $c['sql'];
    $t   .= $c['types'];
    $p    = array_merge($p, $c['params']);
    if ($state !== '')      { $sql .= ' AND f.state = ?';     $t .= 's'; $p[] = $state; }
    if ($kind !== '')       { $sql .= ' AND f.kind = ?';      $t .= 's'; $p[] = $kind; }
    $sql .= ' ORDER BY (f.state = "pending") DESC, f.due_date ASC, f.id DESC LIMIT ' . (int) $limit;
    return fabo_all($sql, $t, $p);
}

/** What falls due inside the next $days — the compliance calendar. */
function fabo_filings_upcoming(int $days = 45, ?int $clientId = null): array
{
    $sql = 'SELECT f.*, c.name AS cname, c.organisation AS corg
            FROM filings f LEFT JOIN clients c ON c.id = f.client_id
            WHERE f.state = "pending" AND f.due_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)';
    $t = 'i';
    $p = [$days];
    if ($clientId !== null) { $sql .= ' AND f.client_id = ?'; $t .= 'i'; $p[] = $clientId; }
    $sql .= ' ORDER BY f.due_date ASC LIMIT 100';
    return fabo_all($sql, $t, $p);
}

/* ------------------------------------------------------ writing filings */

/** Creates one filing. Returns [id, message]. */
function fabo_filing_create(int $clientId, string $kind, string $period, string $due,
                            ?string $start = null, ?string $end = null,
                            string $notes = ''): array
{
    if (!isset(fabo_filing_kinds()[$kind])) {
        return [0, 'Choose which return this is.'];
    }
    if (trim($period) === '') {
        return [0, 'Give the period this covers, such as Sep-2026 or FY 2025-26.'];
    }
    if (!fabo_valid_date($due)) {
        return [0, 'Give the date it is due.'];
    }
    $name = null;
    if ($clientId > 0) {
        $c = fabo_one('SELECT name FROM clients WHERE id = ?', 'i', [$clientId]);
        if (!$c) {
            return [0, 'That client no longer exists.'];
        }
        $name = $c['name'];
    }
    // The same return for the same period is a duplicate, not a second filing.
    $dup = fabo_one('SELECT id FROM filings WHERE client_id = ? AND kind = ? AND period_label = ?',
                    'iss', [$clientId, $kind, trim($period)]);
    if ($dup) {
        return [0, fabo_filing_label($kind) . ' for ' . trim($period)
                   . ' is already on the register.'];
    }
    $id = fabo_exec('INSERT INTO filings (client_id, client_name, kind, period_label,
                      period_start, period_end, due_date, notes)
                     VALUES (?,?,?,?,?,?,?,?)', 'isssssss',
                    [$clientId, $name, $kind, trim($period),
                     fabo_valid_date((string) $start) ? $start : null,
                     fabo_valid_date((string) $end) ? $end : null,
                     $due, trim($notes)]);
    return [(int) $id, fabo_filing_label($kind) . ' added to the register.'];
}

/** Records the outcome of a filing. Returns [ok, message]. */
function fabo_filing_set_state(int $id, string $state, string $ack = '',
                               float $tax = 0.0, float $penalty = 0.0,
                               string $filedOn = '', ?int $ownerId = null): array
{
    $f = fabo_filing($id);
    if (!$f) {
        return [false, 'That filing is no longer on the register.'];
    }
    // $ownerId is set when a client is writing: their own register only.
    if ($ownerId !== null && (int) $f['client_id'] !== $ownerId) {
        return [false, 'That filing is not on your register.'];
    }
    if (!isset(fabo_filing_states()[$state])) {
        return [false, 'Unknown status.'];
    }
    // Filed after the due date is late, whatever the form said.
    $when = fabo_valid_date($filedOn) ? $filedOn : date('Y-m-d');
    if (in_array($state, ['filed', 'paid'], true)
        && !empty($f['due_date']) && $when > $f['due_date']) {
        $state = 'late';
    }
    $done = in_array($state, ['filed', 'paid', 'late'], true);
    fabo_exec('UPDATE filings SET state=?, ack_no=?, tax_amount=?, penalty=?,
                 filed_on=?, updated_at=NOW() WHERE id=?',
              'ssddsi', [$state, trim($ack) ?: null, $tax, $penalty,
                         $done ? $when : null, $id]);
    return [true, fabo_filing_label($f['kind']) . ' marked '
                  . strtolower(fabo_filing_state_label($state)) . '.'];
}

function fabo_filing_delete(int $id, ?int $ownerId = null): array
{
    $f = fabo_filing($id);
    if (!$f) {
        return [false, 'Already gone.'];
    }
    if ($ownerId !== null && (int) $f['client_id'] !== $ownerId) {
        return [false, 'That filing is not on your register.'];
    }
    if ($f['state'] !== 'pending') {
        return [false, 'A filing that has been submitted stays on the record.'];
    }
    fabo_exec('DELETE FROM filings WHERE id = ?', 'i', [$id]);
    return [true, 'Removed from the register.'];
}

/**
 * Lays a year of one return out in advance.
 *
 * This is what makes the calendar worth keeping: GSTR-3B alone is twelve rows
 * a year per client, and nobody types those twice. $year is the financial year
 * it starts in, so 2026 means FY 2026-27.
 */
function fabo_filing_generate(int $clientId, string $kind, int $year): array
{
    $kinds = fabo_filing_kinds();
    if (!isset($kinds[$kind])) {
        return [0, 'Choose which return to lay out.'];
    }
    [, , $every, $day] = $kinds[$kind];

    $periods = [];
    if ($every === 'monthly') {
        // April to March, the Indian financial year.
        for ($i = 0; $i < 12; $i++) {
            $start = date('Y-m-01', strtotime(sprintf('%04d-04-01 +%d month', $year, $i)));
            $end   = date('Y-m-t', strtotime($start));
            // A monthly return is filed in the month after the one it covers.
            $periods[] = [date('M-Y', strtotime($start)), $start, $end,
                          fabo_filing_due_date(strtotime($start . ' +1 month'), $day)];
        }
    } elseif ($every === 'quarterly') {
        foreach ([[4, 6], [7, 9], [10, 12], [1, 3]] as $i => [$a, $b]) {
            $y     = $i === 3 ? $year + 1 : $year;
            $start = sprintf('%04d-%02d-01', $y, $a);
            $end   = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $y, $b)));
            $periods[] = ['Q' . ($i + 1) . ' FY ' . $year . '-' . substr((string) ($year + 1), 2),
                          $start, $end,
                          fabo_filing_due_date(strtotime($end . ' +1 month'), $day)];
        }
    } else {
        $start = sprintf('%04d-04-01', $year);
        $end   = sprintf('%04d-03-31', $year + 1);
        $periods[] = ['FY ' . $year . '-' . substr((string) ($year + 1), 2), $start, $end,
                      fabo_filing_due_date(strtotime(sprintf('%04d-09-01', $year + 1)), $day)];
    }

    $made = 0;
    foreach ($periods as [$label, $start, $end, $due]) {
        [$id] = fabo_filing_create($clientId, $kind, $label, $due, $start, $end);
        if ($id) { $made++; }
    }
    $skipped = count($periods) - $made;
    return [$made, $made . ' ' . fabo_filing_label($kind) . ' period'
                   . ($made === 1 ? '' : 's') . ' laid out'
                   . ($skipped ? ', ' . $skipped . ' already on the register' : '') . '.'];
}

/** Day $day of the month $ts falls in, clamped for months that are shorter. */
function fabo_filing_due_date(int $ts, int $day): string
{
    $last = (int) date('t', $ts);
    return date('Y-m-', $ts) . sprintf('%02d', min($day, $last));
}

/* ------------------------------------------------------ filing metrics */

function fabo_filing_metrics(?int $clientId = null, ?array $win = null): array
{
    // A filing belongs to the window its due date falls in.
    $c = fabo_win_clause($win, 'due_date');
    $w = ' WHERE 1=1' . ($clientId !== null ? ' AND client_id = ?' : '') . $c['sql'];
    $t = ($clientId !== null ? 'i' : '') . $c['types'];
    $p = array_merge($clientId !== null ? [$clientId] : [], $c['params']);

    $r = fabo_one('SELECT
        COALESCE(SUM(state = "pending"),0)                          AS pending,
        COALESCE(SUM(state = "pending" AND due_date < CURDATE()),0) AS overdue,
        COALESCE(SUM(state = "pending" AND due_date BETWEEN CURDATE()
                     AND DATE_ADD(CURDATE(), INTERVAL 15 DAY)),0)   AS due_soon,
        COALESCE(SUM(state IN ("filed","paid")),0)                  AS filed,
        COALESCE(SUM(state = "late"),0)                             AS late,
        COALESCE(SUM(tax_amount),0)                                 AS tax_paid,
        COALESCE(SUM(penalty),0)                                    AS penalty,
        COUNT(*)                                                    AS total
        FROM filings' . $w, $t, $p);

    $onTime = (int) $r['filed'];
    $rated  = $onTime + (int) $r['late'] + (int) $r['overdue'];

    return [
        'pending'     => (int) $r['pending'],
        'overdue'     => (int) $r['overdue'],
        'due_soon'    => (int) $r['due_soon'],
        'filed'       => $onTime,
        'late'        => (int) $r['late'],
        'tax_paid'    => (float) $r['tax_paid'],
        'penalty'     => (float) $r['penalty'],
        'count'       => (int) $r['total'],
        // Filed on time as a share of everything that has come due. Cast:
        // an int divided by an int is an int in PHP when it divides evenly,
        // and callers compare this against a float.
        'on_time_pct' => $rated > 0 ? (float) $onTime / $rated : null,
    ];
}

/** How the register splits by family of return, for the compliance chart. */
function fabo_filing_by_group(?int $clientId = null, ?array $win = null): array
{
    $c = fabo_win_clause($win, 'due_date');
    $w = ' WHERE 1=1' . ($clientId !== null ? ' AND client_id = ?' : '') . $c['sql'];
    $t = ($clientId !== null ? 'i' : '') . $c['types'];
    $p = array_merge($clientId !== null ? [$clientId] : [], $c['params']);

    $out = [];
    foreach (fabo_all('SELECT kind, state, due_date < CURDATE() AS late_now, COUNT(*) n
                       FROM filings' . $w . ' GROUP BY kind, state, late_now', $t, $p) as $r) {
        $g = fabo_filing_group($r['kind']);
        if (!isset($out[$g])) {
            $out[$g] = ['pending' => 0, 'done' => 0, 'overdue' => 0];
        }
        $n = (int) $r['n'];
        if ($r['state'] === 'pending') {
            $out[$g]['pending'] += $n;
            if ((int) $r['late_now'] === 1) { $out[$g]['overdue'] += $n; }
        } elseif ($r['state'] !== 'na') {
            $out[$g]['done'] += $n;
        }
    }
    return $out;
}

/* ------------------------------------------------------------ expenses */

function fabo_expense_categories(): array
{
    return ['Salaries', 'Contractors', 'Ad spend', 'Software', 'Production',
            'Travel', 'Office', 'Professional fees', 'Bank charges', 'Other'];
}

function fabo_expense(int $id): ?array
{
    return fabo_one('SELECT * FROM expenses WHERE id = ?', 'i', [$id]);
}

function fabo_expenses(?int $clientId = null, int $limit = 300, ?array $win = null): array
{
    $sql = 'SELECT e.*, c.name AS cname, c.organisation AS corg
            FROM expenses e LEFT JOIN clients c ON c.id = e.client_id WHERE 1=1';
    $t = '';
    $p = [];
    if ($clientId !== null) { $sql .= ' AND e.client_id = ?'; $t = 'i'; $p = [$clientId]; }
    $c = fabo_win_clause($win, 'e.spent_on');
    $sql .= $c['sql'];
    $t   .= $c['types'];
    $p    = array_merge($p, $c['params']);
    $sql .= ' ORDER BY e.spent_on DESC, e.id DESC LIMIT ' . (int) $limit;
    return fabo_all($sql, $t, $p);
}

/** Returns [id, message]. */
function fabo_expense_create(int $clientId, string $on, string $category, string $vendor,
                             string $description, float $amount, float $tax,
                             bool $gstCredit, bool $billable, bool $paid,
                             string $notes = ''): array
{
    if (!fabo_valid_date($on)) {
        return [0, 'Give the date it was spent.'];
    }
    if (trim($category) === '') {
        return [0, 'Choose a category.'];
    }
    if ($amount <= 0) {
        return [0, 'An expense needs an amount.'];
    }
    $id = fabo_exec('INSERT INTO expenses (client_id, spent_on, category, vendor, description,
                      amount, tax_amount, gst_credit, billable, paid, notes)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?)', 'issssddiiis',
                    [$clientId, $on, trim($category), trim($vendor) ?: null,
                     trim($description) ?: null, $amount, $tax,
                     $gstCredit ? 1 : 0, $billable ? 1 : 0, $paid ? 1 : 0, trim($notes)]);
    return [(int) $id, 'Expense recorded.'];
}

function fabo_expense_delete(int $id, ?int $ownerId = null): array
{
    $e = fabo_expense($id);
    if (!$e) {
        return [false, 'Already gone.'];
    }
    if ($ownerId !== null && (int) $e['client_id'] !== $ownerId) {
        return [false, 'That entry is not yours.'];
    }
    fabo_exec('DELETE FROM expenses WHERE id = ?', 'i', [$id]);
    return [true, 'Expense removed.'];
}

function fabo_expense_metrics(?int $clientId = null, ?array $win = null): array
{
    $c = fabo_win_clause($win, 'spent_on');
    $w = ' WHERE 1=1' . ($clientId !== null ? ' AND client_id = ?' : '') . $c['sql'];
    $t = ($clientId !== null ? 'i' : '') . $c['types'];
    $p = array_merge($clientId !== null ? [$clientId] : [], $c['params']);

    $r = fabo_one('SELECT
        COALESCE(SUM(amount),0)                                       AS spent,
        COALESCE(SUM(tax_amount),0)                                   AS tax,
        COALESCE(SUM(CASE WHEN gst_credit = 1 THEN tax_amount END),0) AS input_credit,
        COALESCE(SUM(CASE WHEN paid = 0 THEN amount END),0)           AS unpaid,
        COALESCE(SUM(CASE WHEN billable = 1 THEN amount END),0)       AS billable,
        COUNT(*)                                                      AS n
        FROM expenses' . $w, $t, $p);

    return [
        'spent'        => (float) $r['spent'],
        'tax'          => (float) $r['tax'],
        'input_credit' => (float) $r['input_credit'],
        'unpaid'       => (float) $r['unpaid'],
        'billable'     => (float) $r['billable'],
        'count'        => (int) $r['n'],
    ];
}

/** Spend by category, biggest first. */
function fabo_expense_by_category(?int $clientId = null, ?array $win = null): array
{
    $c = fabo_win_clause($win, 'spent_on');
    $w = ' WHERE 1=1' . ($clientId !== null ? ' AND client_id = ?' : '') . $c['sql'];
    $t = ($clientId !== null ? 'i' : '') . $c['types'];
    $p = array_merge($clientId !== null ? [$clientId] : [], $c['params']);

    $out = [];
    foreach (fabo_all('SELECT category, SUM(amount) total FROM expenses' . $w
                      . ' GROUP BY category ORDER BY total DESC', $t, $p) as $r) {
        $out[$r['category']] = (float) $r['total'];
    }
    return $out;
}

/* ------------------------------------------------- both sides together */

/**
 * Revenue against spend, month by month, with the margin between them.
 *
 * Revenue here is what was invoiced rather than what was collected, so work
 * lands in the month it was earned in. Collection is a separate question and
 * the invoice metrics answer it.
 */
function fabo_finance_pnl(?int $clientId = null, int $months = 6, ?array $win = null): array
{
    $out = [];
    foreach (fabo_month_span($win, $months) as $k) {
        $out[$k] = ['revenue' => 0.0, 'expense' => 0.0, 'margin' => 0.0];
    }

    $w = $clientId !== null ? ' AND client_id = ?' : '';
    $t = $clientId !== null ? 'i' : '';
    $p = $clientId !== null ? [$clientId] : [];

    // The month list decides the span; SQL just hands over every month.
    foreach (fabo_all('SELECT DATE_FORMAT(issue_date, "%b-%Y") m, SUM(total) t
                       FROM invoices WHERE state IN ("sent","paid")'
                      . $w . ' GROUP BY m', $t, $p) as $r) {
        if (isset($out[$r['m']])) { $out[$r['m']]['revenue'] = (float) $r['t']; }
    }
    foreach (fabo_all('SELECT DATE_FORMAT(spent_on, "%b-%Y") m, SUM(amount) t
                       FROM expenses WHERE 1=1' . $w . ' GROUP BY m', $t, $p) as $r) {
        if (isset($out[$r['m']])) { $out[$r['m']]['expense'] = (float) $r['t']; }
    }
    foreach ($out as $m => $v) {
        $out[$m]['margin'] = $v['revenue'] - $v['expense'];
    }
    return $out;
}

/**
 * Revenue per client, with the spend booked against them beside it — so the
 * desk can see which accounts earn their keep, not only which bill the most.
 */
function fabo_revenue_by_client(int $limit = 12, ?array $win = null): array
{
    // The window goes on the JOIN, not the WHERE: a client with no invoice
    // inside it should still be reachable, just with nothing against them.
    $ci = fabo_win_clause($win, 'i.issue_date');
    $rows = fabo_all('SELECT c.id, c.name, c.organisation,
        COALESCE(SUM(CASE WHEN i.state IN ("sent","paid") THEN i.total END),0) AS revenue,
        COALESCE(SUM(CASE WHEN i.state = "paid" THEN i.total END),0)           AS collected,
        COALESCE(SUM(CASE WHEN i.state = "sent" THEN i.total END),0)           AS due,
        COALESCE(SUM(CASE WHEN i.state IN ("sent","paid") THEN 1 END),0)       AS invoices
        FROM clients c LEFT JOIN invoices i ON i.client_id = c.id' . $ci['sql'] . '
        WHERE c.kind = "client"
        GROUP BY c.id, c.name, c.organisation
        HAVING invoices > 0
        ORDER BY revenue DESC LIMIT ' . (int) $limit, $ci['types'], $ci['params']);

    $ce    = fabo_win_clause($win, 'spent_on');
    $spend = [];
    foreach (fabo_all('SELECT client_id, SUM(amount) t FROM expenses
                       WHERE client_id > 0' . $ce['sql'] . ' GROUP BY client_id',
                      $ce['types'], $ce['params']) as $r) {
        $spend[(int) $r['client_id']] = (float) $r['t'];
    }

    $out = [];
    foreach ($rows as $r) {
        $rev  = (float) $r['revenue'];
        $cost = $spend[(int) $r['id']] ?? 0.0;
        $n    = (int) $r['invoices'];
        $out[] = [
            'id'         => (int) $r['id'],
            'name'       => $r['name'],
            'org'        => (string) ($r['organisation'] ?? ''),
            'revenue'    => $rev,
            'collected'  => (float) $r['collected'],
            'due'        => (float) $r['due'],
            'invoices'   => $n,
            'cost'       => $cost,
            'margin'     => $rev - $cost,
            'margin_pct' => $rev > 0 ? ($rev - $cost) / $rev : null,
            'avg'        => $n > 0 ? $rev / $n : 0.0,
        ];
    }
    return $out;
}

/**
 * The GST position for a window: tax charged out against credit coming in.
 * It is an indication to file against, not the return itself.
 */
function fabo_gst_summary(string $from, string $to, ?int $clientId = null): array
{
    $w = $clientId !== null ? ' AND client_id = ?' : '';
    $t = $clientId !== null ? 'ssi' : 'ss';
    $p = $clientId !== null ? [$from, $to, $clientId] : [$from, $to];

    $out = fabo_one('SELECT COALESCE(SUM(tax_amount),0) tax, COALESCE(SUM(subtotal),0) base
                     FROM invoices WHERE state IN ("sent","paid")
                     AND issue_date BETWEEN ? AND ?' . $w, $t, $p);
    $in  = fabo_one('SELECT COALESCE(SUM(CASE WHEN gst_credit = 1 THEN tax_amount END),0) credit
                     FROM expenses WHERE spent_on BETWEEN ? AND ?' . $w, $t, $p);

    $output = (float) $out['tax'];
    $credit = (float) $in['credit'];
    return [
        'taxable' => (float) $out['base'],
        'output'  => $output,
        'credit'  => $credit,
        'payable' => max(0.0, $output - $credit),
    ];
}

/** The headline figures for the Finance overview, for everyone or one client. */
function fabo_finance_summary(?int $clientId = null, ?array $win = null): array
{
    $inv = fabo_invoice_metrics($clientId, $win);
    $exp = fabo_expense_metrics($clientId, $win);
    $fil = fabo_filing_metrics($clientId, $win);

    $revenue = $inv['raised'];
    $profit  = $revenue - $exp['spent'];

    return [
        'invoice'    => $inv,
        'expense'    => $exp,
        'filing'     => $fil,
        'revenue'    => $revenue,
        'profit'     => $profit,
        'margin_pct' => $revenue > 0 ? $profit / $revenue : null,
    ];
}

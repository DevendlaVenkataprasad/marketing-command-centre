<?php
/**
 * Invoices.
 *
 * An invoice is raised against a client, sent to them, and later marked paid.
 * Each of those three steps tells the client on their dashboard and by email,
 * so nobody has to ask whether an invoice went out or whether it was settled.
 *
 * A draft can be edited freely. Once sent, the figures are the figures: the
 * totals live on the header, so editing a line afterwards cannot quietly
 * change what somebody was asked to pay.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/brand.php';

function fabo_invoice_states(): array
{
    return [
        'draft'     => ['Draft',     'muted'],
        'sent'      => ['Sent',      'warn'],
        'paid'      => ['Paid',      'good'],
        'cancelled' => ['Cancelled', 'muted'],
    ];
}

function fabo_invoice_label(string $state): string
{
    return fabo_invoice_states()[$state][0] ?? $state;
}

function fabo_invoice_tone(string $state): string
{
    return fabo_invoice_states()[$state][1] ?? 'muted';
}

/** The next invoice number, as INV-2026-0007. */
function fabo_invoice_next_number(): string
{
    $year = date('Y');
    $like = 'INV-' . $year . '-%';
    $r = fabo_one('SELECT MAX(CAST(SUBSTRING(number, ?) AS UNSIGNED)) AS n
                   FROM invoices WHERE number LIKE ?', 'is', [strlen('INV-' . $year . '-') + 1, $like]);
    return 'INV-' . $year . '-' . str_pad((string) (((int) ($r['n'] ?? 0)) + 1), 4, '0', STR_PAD_LEFT);
}

/* ------------------------------------------------------------- reading */

function fabo_invoice(int $id): ?array
{
    return fabo_one('SELECT * FROM invoices WHERE id = ?', 'i', [$id]);
}

function fabo_invoice_items(int $id): array
{
    return fabo_all('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY sort_order, id',
                    'i', [$id]);
}

/**
 * The register: every invoice, narrowed by state, by client and by the
 * period being read. Newest first.
 */
function fabo_invoices(string $state = '', int $limit = 300,
                       ?array $win = null, ?int $clientId = null): array
{
    $sql = 'SELECT * FROM invoices WHERE 1=1';
    $t   = '';
    $p   = [];
    if ($state !== '' && isset(fabo_invoice_states()[$state])) {
        $sql .= ' AND state = ?';
        $t   .= 's';
        $p[]  = $state;
    }
    if ($clientId) {
        $sql .= ' AND client_id = ?';
        $t   .= 'i';
        $p[]  = $clientId;
    }
    $c    = fabo_win_clause($win, 'issue_date');
    $sql .= $c['sql'];
    $t   .= $c['types'];
    $p    = array_merge($p, $c['params']);

    $sql .= ' ORDER BY issue_date DESC, id DESC LIMIT ' . max(1, min(500, $limit));
    return fabo_all($sql, $t, $p);
}

/** One client's invoices — what they see on their own portal. */
function fabo_invoices_for(int $clientId): array
{
    return fabo_all("SELECT * FROM invoices
                     WHERE client_id = ? AND state <> 'draft'
                     ORDER BY issue_date DESC, id DESC LIMIT 200", 'i', [$clientId]);
}

/** Totals for the admin list. */
function fabo_invoice_totals(): array
{
    $out = ['outstanding' => 0.0, 'paid' => 0.0, 'overdue' => 0];
    $r = fabo_one("SELECT
                     COALESCE(SUM(CASE WHEN state = 'sent' THEN total END),0) AS outstanding,
                     COALESCE(SUM(CASE WHEN state = 'paid' THEN total END),0) AS paid,
                     COALESCE(SUM(CASE WHEN state = 'sent' AND due_date IS NOT NULL
                                        AND due_date < CURDATE() THEN 1 END),0) AS overdue
                   FROM invoices");
    if ($r) {
        $out = ['outstanding' => (float) $r['outstanding'],
                'paid'        => (float) $r['paid'],
                'overdue'     => (int) $r['overdue']];
    }
    return $out;
}

/* ------------------------------------------------------------- writing */

/** Creates a draft against a client. Returns [id, message]. */
function fabo_invoice_create(int $clientId, string $issue, string $due, string $notes,
                             float $taxPct): array
{
    $c = fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [$clientId]);
    if (!$c) {
        return [0, 'Choose a client.'];
    }
    $issue = $issue !== '' ? date('Y-m-d', strtotime($issue)) : date('Y-m-d');
    $dueD  = $due !== '' ? date('Y-m-d', strtotime($due)) : null;

    $id = fabo_exec('INSERT INTO invoices (number, client_id, client_name, client_org, client_email,
                        client_address, issue_date, due_date, notes, tax_pct)
                     VALUES (?,?,?,?,?,?,?,?,?,?)', 'sisssssssd',
                    [fabo_invoice_next_number(), $clientId, (string) $c['name'],
                     (string) $c['organisation'], (string) $c['email'],
                     trim((string) ($c['address'] ?? '')) ?: null,
                     $issue, $dueD, $notes === '' ? null : $notes, max(0, $taxPct)]);
    return [$id, 'Draft invoice created.'];
}

/** Replaces the lines and recalculates the totals. Drafts only. */
function fabo_invoice_save_items(int $id, array $lines, float $taxPct): array
{
    $inv = fabo_invoice($id);
    if (!$inv) {
        return [false, 'That invoice no longer exists.'];
    }
    if ($inv['state'] !== 'draft') {
        return [false, 'This invoice has already been sent — its figures are fixed.'];
    }

    fabo_exec('DELETE FROM invoice_items WHERE invoice_id = ?', 'i', [$id]);
    $subtotal = 0.0;
    $n = 0;
    foreach ($lines as $line) {
        $desc = trim((string) ($line['description'] ?? ''));
        if ($desc === '') {
            continue;
        }
        $qty    = (float) ($line['quantity'] ?? 1);
        $rate   = (float) ($line['rate'] ?? 0);
        $amount = round($qty * $rate, 2);
        $subtotal += $amount;
        fabo_exec('INSERT INTO invoice_items (invoice_id, description, quantity, rate, amount, sort_order)
                   VALUES (?,?,?,?,?,?)', 'isdddi',
                  [$id, mb_substr($desc, 0, 255), $qty, $rate, $amount, $n++]);
    }

    $taxPct = max(0, $taxPct);
    $tax    = round($subtotal * $taxPct / 100, 2);
    fabo_exec('UPDATE invoices SET subtotal = ?, tax_pct = ?, tax_amount = ?, total = ? WHERE id = ?',
              'ddddi', [$subtotal, $taxPct, $tax, round($subtotal + $tax, 2), $id]);

    return [true, $n ? 'Saved.' : 'Saved — no lines yet.'];
}

/** Moves an invoice on. Returns [ok, message]. */
function fabo_invoice_set_state(int $id, string $state, string $note = ''): array
{
    $inv = fabo_invoice($id);
    if (!$inv) {
        return [false, 'That invoice no longer exists.'];
    }
    if (!isset(fabo_invoice_states()[$state])) {
        return [false, 'Unknown state.'];
    }
    if ($state === 'sent') {
        if ($inv['state'] !== 'draft') {
            return [false, 'That invoice has already been sent.'];
        }
        if ((float) $inv['total'] <= 0) {
            return [false, 'Add at least one line before sending it.'];
        }
        fabo_exec("UPDATE invoices SET state = 'sent', sent_at = NOW() WHERE id = ?", 'i', [$id]);
        return [true, 'Invoice sent.'];
    }
    if ($state === 'paid') {
        if ($inv['state'] !== 'sent') {
            return [false, 'Only a sent invoice can be marked paid.'];
        }
        fabo_exec("UPDATE invoices SET state = 'paid', paid_at = NOW(), paid_note = ? WHERE id = ?",
                  'si', [$note === '' ? null : mb_substr($note, 0, 190), $id]);
        return [true, 'Marked as paid.'];
    }
    if ($state === 'cancelled') {
        if ($inv['state'] === 'paid') {
            return [false, 'A paid invoice cannot be cancelled.'];
        }
        fabo_exec("UPDATE invoices SET state = 'cancelled' WHERE id = ?", 'i', [$id]);
        return [true, 'Invoice cancelled.'];
    }
    return [false, 'Nothing to do.'];
}

/** Deletes a draft. Anything sent stays on the record. */
function fabo_invoice_delete(int $id): array
{
    $inv = fabo_invoice($id);
    if (!$inv) {
        return [false, 'That invoice no longer exists.'];
    }
    if ($inv['state'] !== 'draft') {
        return [false, 'Only a draft can be deleted. Cancel it instead.'];
    }
    fabo_exec('DELETE FROM invoice_items WHERE invoice_id = ?', 'i', [$id]);
    fabo_exec('DELETE FROM invoices WHERE id = ?', 'i', [$id]);
    return [true, 'Draft deleted.'];
}

/** Is this invoice past its due date and still unpaid? */
function fabo_invoice_overdue(array $inv): bool
{
    return $inv['state'] === 'sent'
        && !empty($inv['due_date'])
        && strtotime((string) $inv['due_date']) < strtotime(date('Y-m-d'));
}

/* ----------------------------------------------------------- analytics */

/**
 * The money side of the dashboard, for everyone or for one client.
 *
 * A draft is not money — it has not been asked for — so it is counted
 * separately and kept out of every total.
 */
function fabo_invoice_metrics(?int $clientId = null, ?array $win = null): array
{
    // WHERE 1=1 so the client and the date window append the same way.
    $c = fabo_win_clause($win, 'issue_date');
    $w = ' WHERE 1=1' . ($clientId ? ' AND client_id = ?' : '') . $c['sql'];
    $t = ($clientId ? 'i' : '') . $c['types'];
    $p = array_merge($clientId ? [$clientId] : [], $c['params']);

    $r = fabo_one("SELECT
        COALESCE(SUM(CASE WHEN state IN ('sent','paid') THEN total END),0) AS raised,
        COALESCE(SUM(CASE WHEN state = 'paid' THEN total END),0)           AS paid,
        COALESCE(SUM(CASE WHEN state = 'sent' THEN total END),0)           AS outstanding,
        COALESCE(SUM(CASE WHEN state = 'sent' AND due_date IS NOT NULL
                           AND due_date < CURDATE() THEN total END),0)     AS overdue_value,
        COALESCE(SUM(CASE WHEN state = 'sent' AND due_date IS NOT NULL
                           AND due_date < CURDATE() THEN 1 END),0)         AS overdue_count,
        COALESCE(SUM(CASE WHEN state = 'draft' THEN 1 END),0)              AS drafts,
        COALESCE(COUNT(*),0)                                               AS total_count
        FROM invoices" . $w, $t, $p);

    $raised = (float) ($r['raised'] ?? 0);
    $paid   = (float) ($r['paid'] ?? 0);

    return [
        'raised'        => $raised,
        'paid'          => $paid,
        'outstanding'   => (float) ($r['outstanding'] ?? 0),
        'overdue_value' => (float) ($r['overdue_value'] ?? 0),
        'overdue_count' => (int) ($r['overdue_count'] ?? 0),
        'drafts'        => (int) ($r['drafts'] ?? 0),
        'count'         => (int) ($r['total_count'] ?? 0),
        // What share of what we asked for has actually arrived.
        'collected_pct' => $raised > 0 ? $paid / $raised : null,
    ];
}

/**
 * The months a trend chart runs over: the window's own months when one is
 * chosen, otherwise the last $months. Capped, because a wide window would
 * otherwise draw a bar chart nobody can read.
 */
function fabo_month_span(?array $win, int $months = 6): array
{
    if ($win && !empty($win['from']) && !empty($win['to'])) {
        $keys = [];
        $cur  = strtotime(date('Y-m-01', strtotime($win['from'])));
        $end  = strtotime(date('Y-m-01', strtotime($win['to'])));
        while ($cur <= $end && count($keys) < 24) {
            $keys[] = date('M-Y', $cur);
            $cur    = strtotime('+1 month', $cur);
        }
        return $keys;
    }
    $keys = [];
    for ($m = $months - 1; $m >= 0; $m--) {
        $keys[] = date('M-Y', strtotime("-{$m} months", strtotime(date('Y-m-01'))));
    }
    return $keys;
}

/** Invoiced against collected, month by month. Oldest first. */
function fabo_invoice_monthly(?int $clientId = null, int $months = 6, ?array $win = null): array
{
    $out = [];
    foreach (fabo_month_span($win, $months) as $k) {
        $out[$k] = ['invoiced' => 0.0, 'paid' => 0.0];
    }

    $w = $clientId ? ' AND client_id = ?' : '';
    $t = $clientId ? 'i' : '';
    $p = $clientId ? [$clientId] : [];

    // Invoiced by the month it was issued; collected by the month it settled.
    // No date bound in SQL: the month list above decides what is kept, so a
    // window and a rolling run of months are read exactly the same way.
    foreach (fabo_all("SELECT DATE_FORMAT(issue_date, '%b-%Y') AS k, SUM(total) AS v
                       FROM invoices WHERE state IN ('sent','paid')" . $w
                      . ' GROUP BY k', $t, $p) as $r) {
        if (isset($out[$r['k']])) { $out[$r['k']]['invoiced'] = (float) $r['v']; }
    }
    foreach (fabo_all("SELECT DATE_FORMAT(paid_at, '%b-%Y') AS k, SUM(total) AS v
                       FROM invoices WHERE state = 'paid' AND paid_at IS NOT NULL" . $w
                      . ' GROUP BY k', $t, $p) as $r) {
        if (isset($out[$r['k']])) { $out[$r['k']]['paid'] = (float) $r['v']; }
    }
    return $out;
}

/** How many invoices sit in each state. */
function fabo_invoice_state_counts(?int $clientId = null, ?array $win = null): array
{
    $c = fabo_win_clause($win, 'issue_date');
    $w = ' WHERE 1=1' . ($clientId ? ' AND client_id = ?' : '') . $c['sql'];
    $t = ($clientId ? 'i' : '') . $c['types'];
    $p = array_merge($clientId ? [$clientId] : [], $c['params']);

    $out = [];
    foreach (fabo_invoice_states() as $k => $v) {
        $out[$k] = 0;
    }
    foreach (fabo_all('SELECT state, COUNT(*) AS n FROM invoices' . $w . ' GROUP BY state',
                      $t, $p) as $r) {
        $out[(string) $r['state']] = (int) $r['n'];
    }
    return $out;
}

/** The clients with the most billed against them. */
function fabo_invoice_by_client(int $limit = 8): array
{
    $out = [];
    foreach (fabo_all("SELECT client_name, client_org,
                              COALESCE(SUM(total),0) AS billed,
                              COALESCE(SUM(CASE WHEN state = 'sent' THEN total END),0) AS due
                       FROM invoices
                       WHERE state IN ('sent','paid')
                       GROUP BY client_id, client_name, client_org
                       ORDER BY billed DESC
                       LIMIT " . max(1, min(20, $limit))) as $r) {
        $out[] = [
            'name'   => (string) $r['client_name'],
            'org'    => (string) $r['client_org'],
            'billed' => (float) $r['billed'],
            'due'    => (float) $r['due'],
        ];
    }
    return $out;
}

<?php
/**
 * The programme model behind the Events calendar.
 *
 * An event row IS a programme: the calendar does not keep a second table of
 * its own. That is the whole point — a launch you add to the Events sheet
 * appears on the calendar, and a programme you fill in from the calendar's
 * detail panel is the same row the sheet shows. One record, two ways of
 * looking at it, so the two can never disagree.
 *
 * Everything here works on rows already fetched through fabo_rows(), which
 * applies the viewer's scope. Nothing in this file talks to the database, so
 * a client can never see a programme they were not given.
 */

require_once __DIR__ . '/rules.php';
require_once __DIR__ . '/finance.php';   // the Apr-Mar period windows

/** Where a programme stands. Kept in the sheet's own Status column. */
function fabo_prog_statuses(): array
{
    return ['Planned', 'Ongoing', 'Completed', 'Cancelled', 'Postponed'];
}

/** Whether it has been signed off. Separate from status: a Completed
 *  programme can still be waiting on a sign-off, and often is. */
function fabo_prog_signoffs(): array
{
    return ['Pending', 'Approved', 'Rejected'];
}

/**
 * The chip colour for a programme. Sign-off wins when it has been decided,
 * because "Rejected" is the thing you need to see from across the room;
 * otherwise the status carries it.
 */
function fabo_prog_tone(array $r): string
{
    $signoff = trim((string) ($r['approval_status'] ?? ''));
    if ($signoff === 'Approved')  { return 'approved'; }
    if ($signoff === 'Rejected')  { return 'rejected'; }

    return match (trim((string) ($r['status'] ?? ''))) {
        'Completed'            => 'completed',
        'Ongoing', 'Live'      => 'live',
        'Cancelled'            => 'rejected',
        'Postponed', 'On Hold' => 'other',
        default                => 'planned',
    };
}

/** The periods the Agenda view offers. Apr-Mar, like the rest of the portal. */
function fabo_prog_periods(): array
{
    return [
        'month'     => 'This month',
        'lastmonth' => 'Last month',
        'quarter'   => 'This quarter',
        'half'      => 'This half-year',
        'fy'        => 'This financial year',
        'lastfy'    => 'Last financial year',
        'all'       => 'All time',
        'custom'    => 'Custom range',
    ];
}

/**
 * ['from', 'to', 'label'] for a period key.
 *
 * Reuses the finance windows so a quarter means the same thing on the
 * calendar as it does on an invoice — there is one financial year in this
 * portal, not two.
 */
function fabo_prog_window(string $key, string $from = '', string $to = ''): array
{
    if ($key === 'custom') {
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : date('Y-m-01');
        $to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)   ? $to   : date('Y-m-t', strtotime($from));
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }
        return ['from' => $from, 'to' => $to,
                'label' => date('d M Y', strtotime($from)) . ' – ' . date('d M Y', strtotime($to))];
    }
    if ($key === 'all') {
        return ['from' => '1970-01-01', 'to' => '2999-12-31', 'label' => 'All time'];
    }
    $w = fabo_finance_window($key);
    return $w ?: ['from' => date('Y-m-01'), 'to' => date('Y-m-t'), 'label' => date('F Y')];
}

/**
 * The filters the calendar reads off the URL, cleaned.
 *
 * Kept in one place so the month grid, the agenda and the figures below them
 * are all looking at the same set of programmes — a filter that shaped the
 * grid but not the totals underneath would be worse than no filter at all.
 */
function fabo_prog_filters(): array
{
    return [
        'q'        => trim((string) ($_GET['q'] ?? '')),
        'brand'    => trim((string) ($_GET['fbrand'] ?? '')),
        'type'     => trim((string) ($_GET['ftype'] ?? '')),
        'status'   => trim((string) ($_GET['fstatus'] ?? '')),
        'signoff'  => trim((string) ($_GET['fsign'] ?? '')),
        'owner'    => trim((string) ($_GET['fowner'] ?? '')),
        'period'   => (string) ($_GET['period'] ?? 'month'),
        'from'     => trim((string) ($_GET['from'] ?? '')),
        'to'       => trim((string) ($_GET['to'] ?? '')),
    ];
}

/** Is any filter actually narrowing anything? Decides whether to offer Clear. */
function fabo_prog_filtered(array $f): bool
{
    foreach (['q', 'brand', 'type', 'status', 'signoff', 'owner'] as $k) {
        if (($f[$k] ?? '') !== '') {
            return true;
        }
    }
    return ($f['period'] ?? 'month') !== 'month';
}

/**
 * Narrow a set of event rows by the filter bar. Dates are NOT applied here:
 * the month/week/day views navigate by their own date, and only the Agenda
 * uses the period window.
 */
function fabo_prog_match(array $rows, array $f): array
{
    $q = mb_strtolower($f['q'] ?? '');

    return array_values(array_filter($rows, static function (array $r) use ($f, $q): bool {
        if ($f['brand']   !== '' && (string) ($r['brand'] ?? '')           !== $f['brand'])   { return false; }
        if ($f['type']    !== '' && (string) ($r['event_type'] ?? '')      !== $f['type'])    { return false; }
        if ($f['status']  !== '' && (string) ($r['status'] ?? '')          !== $f['status'])  { return false; }
        if ($f['signoff'] !== '' && (string) ($r['approval_status'] ?? '') !== $f['signoff']) { return false; }
        if ($f['owner']   !== '' && (string) ($r['owner'] ?? '')           !== $f['owner'])   { return false; }
        if ($q === '') {
            return true;
        }
        // The search covers what someone would actually type: the name, where
        // it is, who is running it and who to call about it.
        foreach (['event_name', 'programme_details', 'location', 'city', 'owner',
                  'spoc_name', 'partner_details', 'partners', 'target_audience',
                  'sector', 'remarks'] as $field) {
            if (str_contains(mb_strtolower((string) ($r[$field] ?? '')), $q)) {
                return true;
            }
        }
        return false;
    }));
}

/** The rows whose run touches [from, to]. A multi-day event counts if any of
 *  its days fall inside, not only its first. */
function fabo_prog_in_range(array $rows, string $from, string $to): array
{
    return array_values(array_filter($rows, static function (array $r) use ($from, $to): bool {
        $s = (string) ($r['start_date'] ?? '');
        if ($s === '') {
            // A programme with no date yet belongs to no month. It still
            // shows in an All-time agenda, which is where it can be found.
            return $from <= '1970-01-01';
        }
        $e = (string) ($r['end_date'] ?? '') ?: $s;
        if ($e < $s) { $e = $s; }
        return $e >= $from && $s <= $to;
    }));
}

/** Sorted for reading: by start date, undated last, then by name. */
function fabo_prog_sort(array $rows): array
{
    usort($rows, static function (array $a, array $b): int {
        $as = (string) ($a['start_date'] ?? '');
        $bs = (string) ($b['start_date'] ?? '');
        if ($as === '' xor $bs === '') {
            return $as === '' ? 1 : -1;
        }
        return $as === $bs
            ? strcasecmp((string) ($a['event_name'] ?? ''), (string) ($b['event_name'] ?? ''))
            : strcmp($as, $bs);
    });
    return $rows;
}

/**
 * The headline figures for a set of programmes.
 *
 * Planned against actual throughout, because that comparison is the reason
 * anyone opens this page: a programme that drew 40 people against a planned
 * 400 is the finding, and a total attendance figure alone hides it.
 */
function fabo_prog_stats(array $rows): array
{
    $today = fabo_today();
    $weekEnd = date('Y-m-d', strtotime($today . ' +6 days'));

    $s = ['total' => count($rows), 'upcoming' => 0, 'this_week' => 0, 'pending' => 0,
          'attend_plan' => 0, 'attend_actual' => 0, 'leads_plan' => 0, 'leads_actual' => 0,
          'budget_plan' => 0.0, 'budget_actual' => 0.0, 'partners' => 0, 'brands' => []];

    foreach ($rows as $r) {
        $start = (string) ($r['start_date'] ?? '');
        if ($start !== '' && $start >= $today)                        { $s['upcoming']++; }
        if ($start !== '' && $start >= $today && $start <= $weekEnd)  { $s['this_week']++; }
        if (trim((string) ($r['approval_status'] ?? '')) === 'Pending'
            && strcasecmp(trim((string) ($r['status'] ?? '')), FABO_STATUS_COMPLETED) !== 0) {
            $s['pending']++;           // a completed event is not awaiting sign-off
        }

        $s['attend_plan']   += (int) ($r['expected_attendees'] ?? 0);
        $s['attend_actual'] += (int) ($r['actual_attendees'] ?? 0);
        $s['leads_plan']    += (int) ($r['leads_expected'] ?? 0);
        $s['leads_actual']  += (int) ($r['leads_generated'] ?? 0);
        $s['budget_plan']   += (float) ($r['budget_allocated'] ?? 0);
        $s['budget_actual'] += (float) ($r['budget_spent'] ?? 0);
        $s['partners']      += (int) ($r['partners_count'] ?? 0);

        $b = trim((string) ($r['brand'] ?? ''));
        if ($b !== '') { $s['brands'][$b] = true; }
    }
    $s['brands'] = count($s['brands']);
    return $s;
}

/**
 * How an actual compares with its plan, as a percentage.
 * Returns null when there was no plan to compare against — which is not the
 * same as 0%, and must not be drawn as if it were.
 */
function fabo_prog_pct(float $actual, float $plan): ?float
{
    return $plan > 0 ? round($actual / $plan * 100, 1) : null;
}

/** The distinct values of one field across a set of rows, for a filter menu. */
function fabo_prog_values(array $rows, string $field): array
{
    $out = [];
    foreach ($rows as $r) {
        $v = trim((string) ($r[$field] ?? ''));
        if ($v !== '') { $out[$v] = true; }
    }
    $out = array_keys($out);
    sort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

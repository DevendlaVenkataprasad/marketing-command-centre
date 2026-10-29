<?php
/**
 * Excel export. One click for the whole portal, or one sheet on its own.
 * A client's export covers only their assigned sheets, scoped to their brand
 * and business unit, and is refused once their link has expired.
 */

require_once __DIR__ . '/lib/grid.php';
require_once __DIR__ . '/lib/dashboard.php';
require_once __DIR__ . '/lib/xlsx.php';

fabo_boot();

$token  = (string) ($_GET['k'] ?? '');
$viewer = $token !== '' ? fabo_client_viewer($token)
        : (fabo_signed_in() ? fabo_person_viewer(fabo_signed_in())
                            : (!fabo_admin_signed_in()
                               ? fabo_deny('Please sign in.', 401) : fabo_admin_viewer()));

/* Whose column names, order and options this export carries. Set before any
   sheet is read: there is no page header here to do it, and an export built
   without it comes out with the shipped column names and the shipped order
   however the organisation has arranged its own. */
require_once __DIR__ . '/lib/lists.php';
fabo_view_owner(fabo_customiser($viewer));

$scope = (string) ($_GET['scope'] ?? 'all');

/** A sheet tab: the exact column labels, in order, then its rows. */
function fabo_export_sheet_rows(string $key, array $viewer): array
{
    $sheet = fabo_sheet($key);
    $out   = [array_map(static fn($c) => $c[0], $sheet['columns'])];

    foreach (fabo_rows($key, $viewer, ['client' => (int) ($_GET['client'] ?? 0)]) as $row) {
        $line = [];
        foreach ($sheet['columns'] as $c) {
            $v      = $row[$c[1]] ?? null;
            $render = fabo_col_render($c);

            if ($v === null || $v === '') {
                $line[] = '';
            } elseif ($c[2] === 'date') {
                $line[] = ['d' => (string) $v];
            } elseif ($render === 'money') {
                $line[] = ['m' => $v];
            } elseif ($render === 'pct') {
                $line[] = ['p' => $v];
            } elseif ($render === 'num' || $render === 'dec') {
                $line[] = ['n' => $v];
            } else {
                $line[] = (string) $v;   // sanitised for leading = + - @ by the writer
            }
        }
        $out[] = $line;
    }
    return $out;
}

/**
 * The Programme Calendar's own tab: a heading saying what period this is,
 * then one row per programme with planned beside actual.
 *
 * Planned and actual sit next to each other on purpose. Someone reading this
 * in Excel is almost always asking "did it do what we said it would", and
 * two columns apart is where that question stops being answerable at a
 * glance.
 */
function fabo_export_programme_rows(array $rows, string $period): array
{
    $R = [];
    $R[] = ['PROGRAMMES — ' . strtoupper($period)];
    $R[] = [count($rows) . ' programme' . (count($rows) === 1 ? '' : 's')
            . ' | produced ' . date('d-M-Y')];
    $R[] = [];
    $R[] = ['Event ID', 'Programme', 'Type', 'Brand', 'Business Unit',
            'Starts', 'Ends', 'Location', 'City',
            'Sector', 'Target Audience', 'Partners', 'Partner Count',
            'Attendance Planned', 'Attendance Actual',
            'Leads Planned', 'Leads Actual',
            'Budget Allocated', 'Budget Spent', 'Funding Source',
            'Duration Planned (hrs)', 'Duration Actual (hrs)',
            'Owner', 'SPOC', 'SPOC Contact', 'SPOC Email',
            'Status', 'Priority', 'Sign-off', 'Sign-off Note',
            'Creative Due', 'Linked Campaign',
            'Programme Details', 'Partner Details', 'Local Resources',
            'Case Studies', 'Remarks'];

    $n = static fn($v) => ($v === null || $v === '') ? '' : ['n' => $v];
    $m = static fn($v) => ($v === null || $v === '') ? '' : ['m' => $v];
    $d = static fn($v) => ($v === null || $v === '') ? '' : ['d' => (string) $v];
    $t = static fn($v) => (string) ($v ?? '');

    foreach ($rows as $r) {
        $R[] = [
            $t($r['event_id'] ?? ''), $t($r['event_name'] ?? ''), $t($r['event_type'] ?? ''),
            $t($r['brand'] ?? ''), $t($r['business_unit'] ?? ''),
            $d($r['start_date'] ?? ''), $d($r['end_date'] ?? ''),
            $t($r['location'] ?? ''), $t($r['city'] ?? ''),
            $t($r['sector'] ?? ''), $t($r['target_audience'] ?? ''),
            $t($r['partners'] ?? ''), $n($r['partners_count'] ?? ''),
            $n($r['expected_attendees'] ?? ''), $n($r['actual_attendees'] ?? ''),
            $n($r['leads_expected'] ?? ''), $n($r['leads_generated'] ?? ''),
            $m($r['budget_allocated'] ?? ''), $m($r['budget_spent'] ?? ''),
            $t($r['funding_source'] ?? ''),
            $n($r['duration_hours_expected'] ?? ''), $n($r['duration_hours'] ?? ''),
            $t($r['owner'] ?? ''), $t($r['spoc_name'] ?? ''),
            $t($r['spoc_contact'] ?? ''), $t($r['spoc_email'] ?? ''),
            $t($r['status'] ?? ''), $t($r['priority'] ?? ''),
            $t($r['approval_status'] ?? ''), $t($r['approval_note'] ?? ''),
            $d($r['creative_due_date'] ?? ''), $t($r['linked_campaign_id'] ?? ''),
            $t($r['programme_details'] ?? ''), $t($r['partner_details'] ?? ''),
            $t($r['local_resources'] ?? ''), $t($r['case_studies'] ?? ''),
            $t($r['remarks'] ?? ''),
        ];
    }
    if (!$rows) {
        $R[] = ['No programmes fall in this period with these filters.'];
    }
    return $R;
}

/** The Dashboard tab: every block, stacked, with a blank row between. */
function fabo_export_dashboard_rows(array $viewer): array
{
    $d = fabo_dashboard_data($viewer);
    $B = $d['brand_list'];   // one combined group; see FABO_DASH_ALL
    $R = [];

    $R[] = ['MARKETING DASHBOARD — ' . strtoupper(fabo_viewer_brand($viewer))];
    $R[] = ['As of ' . date('d-M-Y', strtotime($d['as_of'])) . ' | Source: Master Log (live)'];
    $R[] = [];

    $tileBlock = static function (string $title, array $tiles) use (&$R) {
        $R[] = [$title];
        $R[] = array_keys($tiles);
        $vals = [];
        foreach ($tiles as $t) {
            $vals[] = $t['k'] === 'money' ? ['m' => $t['v']]
                    : ($t['k'] === 'pct' ? ['p' => $t['v'] ?? 0] : ['n' => $t['v']]);
        }
        $R[] = $vals;
        $R[] = [];
    };

    $tileBlock('OVERALL SNAPSHOT', $d['overall']);

    foreach ($B as $b) {
        $bd = $d['brands'][$b];
        $tileBlock('BREAKDOWN BY FUNCTION, STATUS AND BUDGET', $bd['tiles']);

        $R[] = ['Function', 'Total', 'Completed', 'Overdue'];
        foreach ($bd['by_function'] as $f => $v) {
            $R[] = [$f, ['n' => $v['total']], ['n' => $v['completed']], ['n' => $v['overdue']]];
        }
        $R[] = [];
        $R[] = ['Status', 'Count'];
        foreach ($bd['by_status'] as $s => $n) {
            $R[] = [$s, ['n' => $n]];
        }
        $R[] = [];
        $R[] = ['Function', 'Budget Allocated', 'Budget Spent'];
        foreach ($bd['by_budget'] as $f => $v) {
            $R[] = [$f, ['m' => $v['alloc']], ['m' => $v['spent']]];
        }
        $R[] = [];
    }

    $R[] = ['DAILY SNAPSHOT'];
    $R[] = ['Due Today', 'Completed Today', 'Overdue (as of today)', 'Due in Next 3 Days'];
    foreach ($d['daily'] as $r) {
        $R[] = [['n' => $r['due_today']], ['n' => $r['done_today']],
                ['n' => $r['overdue']], ['n' => $r['next3']]];
    }
    $R[] = [];

    $R[] = ['WEEKLY VIEW — Last 5 / Next 2 Weeks (by Due Date)'];
    $R[] = ['Week Starting', 'Week Ending', 'Activities Due', 'Completed', 'Budget Spent (₹)'];
    foreach ($d['weekly'] as $r) {
        $R[] = [['d' => $r['start']], ['d' => $r['end']],
                ['n' => $r['due']], ['n' => $r['completed']], ['m' => $r['spent']]];
    }
    $R[] = [];

    $R[] = ['MONTHLY VIEW — Last 6 Months (by Due Date)'];
    $hdr = ['Month'];
    foreach ($B as $b) {
        $p = fabo_dash_prefix($b);
        array_push($hdr, $p . 'Due', $p . 'Completed', $p . 'Budget Allocated (₹)',
                   $p . 'Budget Spent (₹)', $p . 'Leads');
    }
    $R[] = $hdr;
    foreach ($d['monthly'] as $r) {
        $line = [$r['month']];
        foreach ($B as $b) {
            $p = $r[$b];
            array_push($line, ['n' => $p['activities']], ['n' => $p['completed']],
                       ['m' => $p['alloc']], ['m' => $p['spent']], ['n' => $p['leads']]);
        }
        $R[] = $line;
    }
    $R[] = [];

    $periodHeader = [];
    foreach ($B as $b) {
        $p = fabo_dash_prefix($b);
        array_push($periodHeader, $p . 'Activities', $p . 'Completed', $p . 'Budget Alloc (₹)',
                   $p . 'Budget Spent (₹)', $p . 'Leads', $p . 'ROI %');
    }

    foreach ([['QUARTERLY VIEW — ' . date('Y'), 'Quarter', $d['quarterly'], 'quarter'],
              ['ANNUAL VIEW', 'Year', $d['annual'], 'year']] as [$title, $first, $rows, $kf]) {
        $R[] = [$title];
        $R[] = array_merge([$first], $periodHeader);
        foreach ($rows as $r) {
            $line = [$r[$kf]];
            foreach ($B as $b) {
                $p = $r[$b];
                array_push($line, ['n' => $p['activities']], ['n' => $p['completed']],
                           ['m' => $p['alloc']], ['m' => $p['spent']], ['n' => $p['leads']],
                           $p['roi'] === null ? '' : ['p' => $p['roi']]);
            }
            $R[] = $line;
        }
        $R[] = [];
    }

    return $R;
}

/* --------------------------------------------------------------- build */

$xlsx = new FaboXlsx();

if ($scope === 'programmes') {
    /* The Programme Calendar's export: the programmes the filter bar is
       showing, for the period it is showing them for. Deliberately driven by
       the very same helpers the page uses, so what downloads is what was on
       screen — an export that quietly differs from the page is worse than no
       export at all. */
    require_once __DIR__ . '/lib/programs.php';
    fabo_require($viewer, 'events', 'view');

    $f    = fabo_prog_filters();
    $w    = fabo_prog_window($f['period'], $f['from'], $f['to']);
    $rows = fabo_prog_sort(fabo_prog_in_range(
                fabo_prog_match(fabo_rows('events', $viewer,
                                          ['client' => (int) ($_GET['client'] ?? 0)]), $f),
                $w['from'], $w['to']));

    $xlsx->addSheet('Programmes', fabo_export_programme_rows($rows, $w['label']));
    $file = fabo_viewer_brand($viewer) . ' — Programmes ' . $w['label'] . ' ' . date('Y-m-d') . '.xlsx';

} elseif ($scope === 'sheet') {
    $key = (string) ($_GET['sheet'] ?? '');
    if ($key !== 'dashboard' && !fabo_sheet($key)) {
        fabo_deny('Unknown sheet.', 404);
    }
    fabo_require($viewer, $key, 'view');   // re-checked here, never assumed from the UI

    if ($key === 'dashboard') {
        $xlsx->addSheet('Dashboard', fabo_export_dashboard_rows($viewer));
        $name = 'Dashboard';
    } else {
        $xlsx->addSheet(fabo_sheet_label($key), fabo_export_sheet_rows($key, $viewer));
        $name = fabo_sheet_label($key);
    }
    // A sheet filtered to one client is named after them, not after us.
    $only = trim((string) ($_GET['client'] ?? ''));
    $file = ($only !== '' ? $only : fabo_viewer_brand($viewer))
          . ' — ' . $name . ' ' . date('Y-m-d') . '.xlsx';
} else {
    $any = false;
    foreach (fabo_sheet_order() as $key) {
        if (fabo_permission($viewer, $key) === 'none') {
            continue;
        }
        $any = true;
        if ($key === 'dashboard') {
            $xlsx->addSheet('Dashboard', fabo_export_dashboard_rows($viewer));
        } else {
            $xlsx->addSheet(fabo_sheet_label($key), fabo_export_sheet_rows($key, $viewer));
        }
    }
    if (!$any) {
        fabo_deny('There is nothing shared with you to export.', 403);
    }
    // The workbook a client downloads is named after them, not after us.
    $file = fabo_viewer_brand($viewer) . ' — All Sheets ' . date('Y-m-d') . '.xlsx';
}

try {
    $xlsx->download($file);
} catch (Throwable $e) {
    error_log('FABO export failed: ' . $e->getMessage());
    fabo_deny('The export could not be produced. Please try again.', 500);
}

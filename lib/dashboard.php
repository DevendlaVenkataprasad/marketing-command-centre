<?php
/**
 * The live dashboard. Nothing is entered here — every tile, table and chart
 * reads the Master Log, through the viewer's brand / business-unit scope.
 *
 * "Completed in period" counts use Completion Date, never Due Date: a task can
 * be due in one period and completed in another. Everything else in a period
 * (activities, budget, leads) buckets on Due Date.
 */

require_once __DIR__ . '/grid.php';
require_once __DIR__ . '/charts.php';
require_once __DIR__ . '/rules.php';

function fabo_dash_functions(): array
{
    $v = fabo_list_values('Function');
    return $v ?: ['Content', 'Website', 'Design', 'Digital Marketing', 'BTL Activities'];
}

function fabo_dash_statuses(): array
{
    $v = fabo_list_values('Status');
    return $v ?: ['Not Started', 'In Progress', 'In Review', 'Approved',
                  'Published/Live', 'Completed', 'On Hold', 'Delayed', 'Cancelled'];
}

/** Rows counted as real activities — the workbook keys this off Function. */
function fabo_dash_rows(array $viewer, int $clientId = 0): array
{
    return array_values(array_filter(
        fabo_rows('master', $viewer, ['client' => $clientId]),
        static fn($r) => ($r['function_name'] ?? '') !== ''
    ));
}

function fabo_dash_brand(array $rows, string $brand): array
{
    // The empty group is "everything": the dashboard is no longer split by
    // brand, because the portal serves many clients rather than two brands.
    if ($brand === FABO_DASH_ALL) {
        return $rows;
    }
    return array_values(array_filter($rows, static fn($r) => ($r['brand'] ?? '') === $brand));
}

function fabo_dash_sum(array $rows, string $field): float
{
    $t = 0.0;
    foreach ($rows as $r) { $t += (float) ($r[$field] ?? 0); }
    return $t;
}

function fabo_dash_count(array $rows, string $field, string $value): int
{
    $n = 0;
    foreach ($rows as $r) { if (($r[$field] ?? '') === $value) { $n++; } }
    return $n;
}

/** The nine tiles, for any row set. */
/**
 * Whether anything in these rows actually records this measure.
 *
 * The dashboard was written for a campaign workbook and shows budget,
 * leads, revenue and a RAG status. None of those are stored on the Master
 * Activity Log: they are computed columns, filled from the linked detail row
 * through linked_detail_id (see fabo_resolve_link()). Where a line has no
 * link — which on this portal is nearly all of them — they come back null,
 * and a null summed into a tile renders a confident 0.
 *
 * A zero that means "we spent nothing" and a zero that means "nobody has ever
 * recorded this" look identical and mean the opposite, so a measure nothing
 * records is not shown at all.
 *
 * This asks the rows rather than the schema. Asking the schema was wrong: the
 * columns genuinely are absent from the table, so it hid these tiles even for
 * a portal whose campaigns ARE linked and whose figures are real.
 */
function fabo_dash_recorded(array $rows, string $field): bool
{
    foreach ($rows as $r) {
        $v = $r[$field] ?? null;
        if ($v !== null && $v !== '') {
            return true;
        }
    }
    return false;
}

function fabo_dash_tiles(array $rows): array
{
    $alloc = fabo_dash_sum($rows, 'budget_allocated');
    $spent = fabo_dash_sum($rows, 'budget_spent');
    $tiles = [
        'Total Activities'   => ['v' => count($rows),                                 'k' => 'n'],
        'Completed'          => ['v' => fabo_dash_count($rows, 'status', FABO_STATUS_COMPLETED),   'k' => 'n', 'c' => 'green'],
        'In Progress'        => ['v' => fabo_dash_count($rows, 'status', FABO_STATUS_IN_PROGRESS), 'k' => 'n'],
        'Overdue (Red)'      => ['v' => fabo_dash_count($rows, 'rag_status', FABO_RAG_RED),        'k' => 'n', 'c' => 'red',   'needs' => 'rag_status'],
        'Due Soon (Amber)'   => ['v' => fabo_dash_count($rows, 'rag_status', FABO_RAG_AMBER),      'k' => 'n', 'c' => 'amber', 'needs' => 'rag_status'],
        'Budget Allocated'   => ['v' => $alloc, 'k' => 'money', 'needs' => 'budget_allocated'],
        'Budget Spent'       => ['v' => $spent, 'k' => 'money', 'needs' => 'budget_spent'],
        'Budget Utilization' => ['v' => fabo_div($spent, $alloc), 'k' => 'pct', 'needs' => 'budget_spent'],
        'Leads Generated'    => ['v' => fabo_dash_sum($rows, 'leads_generated'), 'k' => 'n', 'needs' => 'leads_generated'],
    ];
    return array_filter($tiles, static fn($t) => !isset($t['needs'])
                                              || fabo_dash_recorded($rows, $t['needs']));
}

/* ------------------------------------------------------------- periods */

function fabo_dash_in_month(?string $d, string $key): bool
{
    return $d && date('M-Y', strtotime($d)) === $key;
}

function fabo_dash_in_quarter(?string $d, string $key): bool
{
    return $d && fabo_quarter_label($d) === $key;
}

function fabo_dash_in_year(?string $d, string $year): bool
{
    return $d && date('Y', strtotime($d)) === $year;
}

function fabo_dash_in_range(?string $d, string $from, string $to): bool
{
    return $d && $d >= $from && $d <= $to;
}

/** One period row for a brand: activities by due date, completed by completion date. */
function fabo_dash_period(array $rows, callable $inPeriod): array
{
    $due = array_values(array_filter($rows, static fn($r) => $inPeriod($r['due_date'] ?? null)));
    $comp = 0;
    foreach ($rows as $r) {
        if ($inPeriod($r['completion_date'] ?? null)) { $comp++; }
    }
    $spent = fabo_dash_sum($due, 'budget_spent');
    return [
        'activities' => count($due),
        'completed'  => $comp,
        'alloc'      => fabo_dash_sum($due, 'budget_allocated'),
        'spent'      => $spent,
        'leads'      => fabo_dash_sum($due, 'leads_generated'),
        // Blended, spend-weighted — never the average of row ROIs.
        'roi'        => fabo_blended_roi(fabo_dash_sum($due, 'revenue_attributed'), $spent),
    ];
}

/**
 * The dashboard once had one section per brand. It now has one section, over
 * everything, and this is the key that group is filed under. The grouping
 * machinery is left intact so a real grouping could be reintroduced.
 */
const FABO_DASH_ALL = '';

/** Column prefix for a group — nothing at all for the single combined group. */
function fabo_dash_prefix(string $group): string
{
    return $group === FABO_DASH_ALL ? '' : $group . ': ';
}

/* ---------------------------------------------------------------- data */

function fabo_dashboard_data(array $viewer, int $clientId = 0): array
{
    $rows   = fabo_dash_rows($viewer, $clientId);
    $brands = [FABO_DASH_ALL];        // one combined view, not one per brand
    $today  = fabo_today();

    $out = [
        'as_of'      => $today,
        'all'        => $rows,
        'brand_list' => $brands,
        'overall'    => fabo_dash_tiles($rows),
        'brands'     => [],
    ];

    foreach ($brands as $b) {
        $br = fabo_dash_brand($rows, $b);
        $byFunction = [];
        $byBudget   = [];
        foreach (fabo_dash_functions() as $f) {
            $fr = array_values(array_filter($br, static fn($r) => ($r['function_name'] ?? '') === $f));
            $byFunction[$f] = [
                'total'     => count($fr),
                'completed' => fabo_dash_count($fr, 'status', FABO_STATUS_COMPLETED),
                'overdue'   => fabo_dash_count($fr, 'rag_status', FABO_RAG_RED),
            ];
            $byBudget[$f] = [
                'alloc' => fabo_dash_sum($fr, 'budget_allocated'),
                'spent' => fabo_dash_sum($fr, 'budget_spent'),
            ];
        }
        $byStatus = [];
        foreach (fabo_dash_statuses() as $s) {
            $byStatus[$s] = fabo_dash_count($br, 'status', $s);
        }
        $out['brands'][$b] = [
            'rows'        => $br,
            'tiles'       => fabo_dash_tiles($br),
            'by_function' => $byFunction,
            'by_status'   => $byStatus,
            'by_budget'   => $byBudget,
        ];
    }

    // Daily snapshot — one row per brand.
    $out['daily'] = [];
    foreach ($brands as $b) {
        $br = $out['brands'][$b]['rows'];
        $in3 = 0;
        foreach ($br as $r) {
            $d = $r['due_date'] ?? null;
            if ($d && $d > $today && $d <= date('Y-m-d', strtotime($today . ' +3 days'))) { $in3++; }
        }
        $out['daily'][] = [
            'brand'      => $b,
            'due_today'  => count(array_filter($br, static fn($r) => ($r['due_date'] ?? '') === $today)),
            'done_today' => count(array_filter($br, static fn($r) => ($r['completion_date'] ?? '') === $today)),
            'overdue'    => fabo_dash_count($br, 'rag_status', FABO_RAG_RED),
            'next3'      => $in3,
        ];
    }

    // Weekly — last 5 / current / next 2, by Due Date. Weeks run Monday to Sunday.
    $out['weekly'] = [];
    $monday = strtotime('monday this week', strtotime($today));
    for ($w = -5; $w <= 2; $w++) {
        $ws = date('Y-m-d', strtotime("{$w} week", $monday));
        $we = date('Y-m-d', strtotime($ws . ' +6 days'));
        foreach ($brands as $b) {
            $br    = $out['brands'][$b]['rows'];
            $dueIn = array_values(array_filter($br, static fn($r) =>
                        fabo_dash_in_range($r['due_date'] ?? null, $ws, $we)));
            $done  = count(array_filter($br, static fn($r) =>
                        fabo_dash_in_range($r['completion_date'] ?? null, $ws, $we)));
            $out['weekly'][] = [
                'start' => $ws, 'end' => $we, 'brand' => $b,
                'due'   => count($dueIn), 'completed' => $done,
                'spent' => fabo_dash_sum($dueIn, 'budget_spent'),
            ];
        }
    }

    // Monthly — last 6 months by Due Date.
    $out['monthly'] = [];
    for ($m = 5; $m >= 0; $m--) {
        $key = date('M-Y', strtotime("-{$m} months", strtotime(date('Y-m-01'))));
        $row = ['month' => $key];
        foreach ($brands as $b) {
            $p = fabo_dash_period($out['brands'][$b]['rows'],
                                  static fn($d) => fabo_dash_in_month($d, $key));
            $row[$b] = $p;
        }
        $out['monthly'][] = $row;
    }

    // Quarterly — the current year.
    $year = date('Y');
    $out['quarterly'] = [];
    foreach ([1, 2, 3, 4] as $q) {
        $key = 'Q' . $q . '-' . $year;
        $row = ['quarter' => $key];
        foreach ($brands as $b) {
            $row[$b] = fabo_dash_period($out['brands'][$b]['rows'],
                                        static fn($d) => fabo_dash_in_quarter($d, $key));
        }
        $out['quarterly'][] = $row;
    }

    // Annual — last year, this year, next year.
    $out['annual'] = [];
    foreach ([-1, 0, 1] as $off) {
        $y   = (string) ((int) $year + $off);
        $row = ['year' => $y];
        foreach ($brands as $b) {
            $row[$b] = fabo_dash_period($out['brands'][$b]['rows'],
                                        static fn($d) => fabo_dash_in_year($d, $y));
        }
        $out['annual'][] = $row;
    }

    return $out;
}

/* --------------------------------------------------------------- page */

function fabo_dash_tile_value(array $t): string
{
    switch ($t['k']) {
        case 'money': return fabo_money($t['v']);
        case 'pct':   return $t['v'] === null ? '0%' : fabo_pct($t['v'], 0);
        default:      return fabo_thousands($t['v']);
    }
}

function fabo_dash_tiles_html(array $tiles): void
{
    echo '<div class="tiles">';
    foreach ($tiles as $label => $t) {
        echo '<div class="tile ' . e($t['c'] ?? '') . '"><div class="k">' . e($label) . '</div>'
           . '<div class="v">' . e(fabo_dash_tile_value($t)) . '</div></div>';
    }
    echo '</div>';
}

function fabo_dashboard_page(array $viewer): void
{
    fabo_require($viewer, 'dashboard', 'view');
    require_once __DIR__ . '/invoices.php';

    // The admin can read the whole dashboard through one client's eyes.
    $picked  = $viewer['is_admin'] ? (int) ($_GET['client'] ?? 0) : 0;
    $clients = $viewer['is_admin']
        ? fabo_all("SELECT id, name, organisation FROM clients
                    WHERE kind = 'client' AND revoked_at IS NULL
                    ORDER BY organisation, name")
        : [];
    $pickedName = '';
    foreach ($clients as $c) {
        if ((int) $c['id'] === $picked) {
            $pickedName = $c['name'] . ($c['organisation'] ? ' · ' . $c['organisation'] : '');
        }
    }
    if ($pickedName === '') {
        $picked = 0;
    }

    $d = fabo_dashboard_data($viewer, $picked);

    fabo_head($viewer, 'Dashboard', 'dashboard');
    ?>
<div class="wrap">
  <div class="pagehead">
    <h1>MARKETING DASHBOARD &mdash; <?= e(strtoupper($picked > 0 ? $pickedName : fabo_viewer_brand($viewer))) ?></h1>
    <div class="sub">As of <?= e(date('d-M-Y', strtotime($d['as_of']))) ?> | Source: Master Log (live)</div>
  </div>

<?php if ($clients): ?>
  <div class="toolbar">
    <select id="dashclient" data-base="dashboard.php">
      <option value="">Everything</option>
      <?php foreach ($clients as $c): ?>
        <option value="<?= (int) $c['id'] ?>"<?= (int) $c['id'] === $picked ? ' selected' : '' ?>>
          <?= e($c['name']) ?><?= $c['organisation'] ? ' · ' . e($c['organisation']) : '' ?></option>
      <?php endforeach; ?>
    </select>
    <?php if ($picked > 0): ?>
      <span class="filternote">Everything below is <strong><?= e($pickedName) ?></strong> only</span>
      <a class="btn btn-sm" href="dashboard.php">Clear</a>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php fabo_dash_invoice_block($viewer, $picked); ?>
<?php fabo_dash_client_block($viewer, $picked); ?>

<?php if ($d['overall']): ?>
  <div class="block">
    <h2>Master Activity Log — Snapshot</h2>
    <p class="sb-lead">What the log itself holds. The block below counts the
      sheets directly, so the two totals differ where a row has not been
      logged yet.</p>
    <?php fabo_dash_tiles_html($d['overall']); ?>
  </div>
<?php endif; ?>

<?php
  /* The picture of the work, and then the two questions somebody actually
     opens a dashboard with: where does the work stand, and what needs me
     today. All three read from the sheets themselves, so they say Content and
     Design rather than Function A and Function B — and all three go through
     fabo_rows(), so each person sees their own level of it.

     The charts come first because they are what the page is for. The tables
     under them are the detail somebody drops to once a chart has told them
     where to look. */
  require_once __DIR__ . '/dashsheets.php';
  require_once __DIR__ . '/dashgraphs.php';

  $scan = fabo_graph_scan($viewer, $picked);
  fabo_graphs_block($viewer, $scan);

  /* Fed from the scan above rather than walked again — same shape, one pass. */
  $health = $scan['sheets'];
  fabo_dash_attention_block($viewer, fabo_dash_attention($viewer, $picked),
                            fabo_dash_attention_counts($health));
  fabo_dash_sheets_block($viewer, $health);
?>

<?php foreach ($d['brand_list'] as $b): $bd = $d['brands'][$b]; ?>
  <div class="block">
    <h2>Breakdown by Function, Status and Budget</h2>
    <?php fabo_dash_tiles_html($bd['tiles']); ?>
    <div class="cols">
      <table class="data">
        <thead><tr><th>Function</th><th class="num">Total</th><th class="num">Completed</th><th class="num">Overdue</th></tr></thead>
        <tbody>
        <?php foreach ($bd['by_function'] as $f => $v): ?>
          <tr><td><?= e($f) ?></td>
              <td class="num"><?= (int) $v['total'] ?></td>
              <td class="num"><?= (int) $v['completed'] ?></td>
              <td class="num"><?= (int) $v['overdue'] ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <table class="data">
        <thead><tr><th>Status</th><th class="num">Count</th></tr></thead>
        <tbody>
        <?php foreach ($bd['by_status'] as $s => $n): ?>
          <tr><td><?= e($s) ?></td><td class="num"><?= (int) $n ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <table class="data">
        <thead><tr><th>Function</th><th class="num">Budget Allocated</th><th class="num">Budget Spent</th></tr></thead>
        <tbody>
        <?php foreach ($bd['by_budget'] as $f => $v): ?>
          <tr><td><?= e($f) ?></td>
              <td class="num"><?= e(fabo_money($v['alloc'])) ?></td>
              <td class="num"><?= e(fabo_money($v['spent'])) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endforeach; ?>

  <div class="block">
    <h2>Daily Snapshot</h2>
    <table class="data">
      <thead><tr><th class="num">Due Today</th><th class="num">Completed Today</th>
                 <th class="num">Overdue (as of today)</th><th class="num">Due in Next 3 Days</th></tr></thead>
      <tbody>
      <?php foreach ($d['daily'] as $r): ?>
        <tr><td class="num"><?= (int) $r['due_today'] ?></td>
            <td class="num"><?= (int) $r['done_today'] ?></td>
            <td class="num"><?= (int) $r['overdue'] ?></td>
            <td class="num"><?= (int) $r['next3'] ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="block">
    <h2>Weekly View — Last 5 / Next 2 Weeks (by Due Date)</h2>
    <div class="scrollx">
    <table class="data">
      <thead><tr><th>Week Starting</th><th>Week Ending</th>
                 <th class="num">Activities Due</th><th class="num">Completed</th>
                 <th class="num">Budget Spent (₹)</th></tr></thead>
      <tbody>
      <?php foreach ($d['weekly'] as $r): ?>
        <tr><td><?= e(date('d-M-Y', strtotime($r['start']))) ?></td>
            <td><?= e(date('d-M-Y', strtotime($r['end']))) ?></td>
            <td class="num"><?= (int) $r['due'] ?></td>
            <td class="num"><?= (int) $r['completed'] ?></td>
            <td class="num"><?= e(fabo_money($r['spent'])) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>

  <div class="block">
    <h2>Monthly View — Last 6 Months (by Due Date)</h2>
    <div class="scrollx">
    <table class="data">
      <thead><tr><th>Month</th>
        <?php foreach ($d['brand_list'] as $b): ?>
          <th class="num"><?= e(fabo_dash_prefix($b)) ?>Due</th>
          <th class="num"><?= e(fabo_dash_prefix($b)) ?>Completed</th>
          <th class="num"><?= e(fabo_dash_prefix($b)) ?>Budget Allocated (₹)</th>
          <th class="num"><?= e(fabo_dash_prefix($b)) ?>Budget Spent (₹)</th>
          <th class="num"><?= e(fabo_dash_prefix($b)) ?>Leads</th>
        <?php endforeach; ?>
      </tr></thead>
      <tbody>
      <?php foreach ($d['monthly'] as $r): ?>
        <tr><td><?= e($r['month']) ?></td>
        <?php foreach ($d['brand_list'] as $b): $p = $r[$b]; ?>
          <td class="num"><?= (int) $p['activities'] ?></td>
          <td class="num"><?= (int) $p['completed'] ?></td>
          <td class="num"><?= e(fabo_money($p['alloc'])) ?></td>
          <td class="num"><?= e(fabo_money($p['spent'])) ?></td>
          <td class="num"><?= e(fabo_thousands($p['leads'])) ?></td>
        <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>

<?php
  $periodTable = static function (string $title, string $firstCol, array $rows, string $keyField) use ($d) {
      $brandList = $d['brand_list'];
      ?>
  <div class="block">
    <h2><?= e($title) ?></h2>
    <div class="scrollx">
    <table class="data">
      <thead><tr><th><?= e($firstCol) ?></th>
        <?php foreach ($brandList as $b): ?>
          <th class="num"><?= e(fabo_dash_prefix($b)) ?>Activities</th>
          <th class="num"><?= e(fabo_dash_prefix($b)) ?>Completed</th>
          <th class="num"><?= e(fabo_dash_prefix($b)) ?>Budget Alloc (₹)</th>
          <th class="num"><?= e(fabo_dash_prefix($b)) ?>Budget Spent (₹)</th>
          <th class="num"><?= e(fabo_dash_prefix($b)) ?>Leads</th>
          <th class="num"><?= e(fabo_dash_prefix($b)) ?>ROI %</th>
        <?php endforeach; ?>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr><td><?= e($r[$keyField]) ?></td>
        <?php foreach ($brandList as $b): $p = $r[$b]; ?>
          <td class="num"><?= (int) $p['activities'] ?></td>
          <td class="num"><?= (int) $p['completed'] ?></td>
          <td class="num"><?= e(fabo_money($p['alloc'])) ?></td>
          <td class="num"><?= e(fabo_money($p['spent'])) ?></td>
          <td class="num"><?= e(fabo_thousands($p['leads'])) ?></td>
          <td class="num"><?= $p['roi'] === null ? '' : e(fabo_pct($p['roi'])) ?></td>
        <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
      <?php
  };
  $periodTable('Quarterly View — ' . date('Y'), 'Quarter', $d['quarterly'], 'quarter');
  $periodTable('Annual View', 'Year', $d['annual'], 'year');
?>

  <div class="block">
    <h2>Charts</h2>
    <div class="chartgrid">
<?php
    $funcs  = fabo_dash_functions();
    $months = array_column($d['monthly'], 'month');
    $brands = $d['brand_list'];

    /* The dashboard is no longer split by brand, so the group is the empty
       string and every title came out as " — Activities by Function" with a
       dangling dash. Name it for what it is instead. */
    $named = static fn(string $b, string $what) =>
        trim($b) === '' ? $what : $b . ' — ' . $what;

    foreach ($brands as $b) {
        $bd = $d['brands'][$b];
        fabo_chart_bar($named($b, 'Activities by Function'), $funcs, [
            ['name' => 'Total',     'color' => FABO_BLUE,   'values' => array_column($bd['by_function'], 'total')],
            ['name' => 'Completed', 'color' => FABO_AQUA,   'values' => array_column($bd['by_function'], 'completed')],
            ['name' => 'Overdue',   'color' => FABO_ORANGE, 'values' => array_column($bd['by_function'], 'overdue')],
        ]);
    }

    foreach ($brands as $b) {
        $slices = [];
        $i = 0;
        foreach ($d['brands'][$b]['by_status'] as $s => $n) {
            if ($n <= 0) { continue; }
            $slices[] = ['label' => $s, 'value' => $n, 'color' => FABO_SERIES[$i]];
            $i++;
            if ($i >= count(FABO_SERIES)) { break; }
        }
        // Hues are never cycled: anything past the last slot folds into Other.
        $shown = array_sum(array_column($slices, 'value'));
        $all   = array_sum($d['brands'][$b]['by_status']);
        if ($all > $shown) {
            $slices[] = ['label' => 'Other', 'value' => $all - $shown, 'color' => FABO_NEUTRAL];
        }
        fabo_chart_pie($named($b, 'Activities by Status'), $slices);
    }

    /* Only where something actually records a budget — see
       fabo_dash_recorded(). An empty money chart says "we spent nothing",
       which is not what an unrecorded measure means. */
    if (fabo_dash_recorded($d['brands'][$brands[0]]['rows'] ?? [], 'budget_allocated')) {
        foreach ($brands as $b) {
            $bd = $d['brands'][$b];
            fabo_chart_bar($named($b, 'Budget Allocated vs Spent'), $funcs, [
                ['name' => 'Allocated', 'color' => FABO_BLUE,   'values' => array_column($bd['by_budget'], 'alloc')],
                ['name' => 'Spent',     'color' => FABO_ORANGE, 'values' => array_column($bd['by_budget'], 'spent')],
            ], 'money');
        }
    }

    // One series per brand, each keeping its own colour slot.
    $seriesFor = static function (string $metric) use ($brands, $d) {
        $out = [];
        foreach ($brands as $b) {
            $out[] = [
                'name'   => $b,
                'color'  => fabo_brand_color($b),
                'values' => array_map(static fn($r) => $r[$b][$metric], $d['monthly']),
            ];
        }
        return $out;
    };

    $brandNames = trim(implode(' vs ', $brands));
    fabo_chart_line($named($brandNames, 'Monthly Activities Due'), $months, $seriesFor('activities'));
    if (fabo_dash_recorded($d['brands'][$brands[0]]['rows'] ?? [], 'budget_spent')) {
        fabo_chart_bar($named($brandNames, 'Monthly Budget Spent'), $months, $seriesFor('spent'), 'money');
    }
?>
    </div>
  </div>

</div>
<?php
    if ($clients) {
        // The dashboard has no grid, so the picker brings its own behaviour.
        echo '<script>(function(){var s=document.getElementById("dashclient");'
           . 'if(!s){return;}s.addEventListener("change",function(){'
           . 'window.location="dashboard.php"+(s.value?"?client="+encodeURIComponent(s.value):"");'
           . '});})();</script>';
    }
    fabo_foot();
}

/* --------------------------------------------------------- the money */

/**
 * Invoicing on the dashboard: what has been billed, what has arrived, and
 * what is late — with the same three charts the rest of the page uses.
 *
 * Only the admin and our own team see it. A client is shown their own figures
 * on their Invoices page; the dashboard is where the agency reads the business.
 */
function fabo_dash_invoice_block(array $viewer, int $clientId = 0): void
{
    require_once __DIR__ . '/invoices.php';
    require_once __DIR__ . '/finance.php';

    // Billing is raised and settled by us. finance.php turns staff away for the
    // same reason, and a client reads their own bills on their Finance page.
    if (empty($viewer['is_admin'])) {
        return;
    }

    $scope   = $clientId ?: null;
    $sum     = fabo_finance_summary($scope);
    $m       = $sum['invoice'];
    $exp     = $sum['expense'];
    $fil     = $sum['filing'];
    $monthly = fabo_invoice_monthly($scope);
    $states  = fabo_invoice_state_counts($scope);
    ?>
  <div class="block">
    <h2>Finance</h2>
    <div class="tiles">
      <div class="tile"><div class="k">Revenue</div>
        <div class="v"><?= e(fabo_money($m['raised'])) ?></div></div>
      <div class="tile green"><div class="k">Collected</div>
        <div class="v"><?= e(fabo_money($m['paid'])) ?></div></div>
      <div class="tile amber"><div class="k">Outstanding</div>
        <div class="v"><?= e(fabo_money($m['outstanding'])) ?></div></div>
      <div class="tile red"><div class="k">Overdue</div>
        <div class="v"><?= e(fabo_money($m['overdue_value'])) ?></div>
        <div class="k"><?= (int) $m['overdue_count'] ?> invoice<?= $m['overdue_count'] === 1 ? '' : 's' ?></div></div>
      <div class="tile"><div class="k">Collection rate</div>
        <div class="v"><?= $m['collected_pct'] === null ? '—' : e(fabo_pct($m['collected_pct'], 0)) ?></div></div>
      <div class="tile"><div class="k">Drafts</div>
        <div class="v"><?= (int) $m['drafts'] ?></div></div>
    </div>

    <div class="tiles">
      <div class="tile"><div class="k">Spend</div>
        <div class="v"><?= e(fabo_money($exp['spent'])) ?></div></div>
      <div class="tile <?= $sum['profit'] >= 0 ? 'green' : 'red' ?>"><div class="k">Gross profit</div>
        <div class="v"><?= e(fabo_money($sum['profit'])) ?></div>
        <div class="k"><?= $sum['margin_pct'] === null ? '&mdash;'
                         : e(fabo_pct($sum['margin_pct'], 0)) . ' margin' ?></div></div>
      <div class="tile <?= $fil['overdue'] ? 'red' : '' ?>"><div class="k">Filings overdue</div>
        <div class="v"><?= (int) $fil['overdue'] ?></div></div>
      <div class="tile <?= $fil['due_soon'] ? 'amber' : '' ?>"><div class="k">Filings due in 15 days</div>
        <div class="v"><?= (int) $fil['due_soon'] ?></div></div>
      <div class="tile"><div class="k">Filed on time</div>
        <div class="v"><?= $fil['on_time_pct'] === null ? '&mdash;'
                         : e(fabo_pct($fil['on_time_pct'], 0)) ?></div></div>
      <div class="tile"><div class="k">Tax paid</div>
        <div class="v"><?= e(fabo_money($fil['tax_paid'])) ?></div></div>
    </div>

    <?php if ($m['count'] === 0): ?>
    <p class="muted" style="margin:12px 2px 0">
      No invoices raised yet<?= $clientId ? ' for this client' : '' ?>.
      Raise one from <a href="finance.php">Finance</a>.
    </p>
    <?php else: ?>
    <div class="chartgrid" style="margin-top:14px">
      <?php
        $months = array_keys($monthly);
        fabo_chart_bar('Invoiced vs Collected — last 6 months', $months, [
            ['name' => 'Invoiced',  'color' => 'var(--s1)',
             'values' => array_map(static fn($v) => $v['invoiced'], array_values($monthly))],
            ['name' => 'Collected', 'color' => 'var(--s3)',
             'values' => array_map(static fn($v) => $v['paid'], array_values($monthly))],
        ], 'money');

        // Each state keeps the colour it wears everywhere else in the portal.
        $stateColour = ['draft' => 'var(--ink-mute)', 'sent' => 'var(--amber)',
                        'paid'  => 'var(--green)',    'cancelled' => 'var(--line)'];
        $slices = [];
        foreach (fabo_invoice_states() as $k => $v) {
            if (($states[$k] ?? 0) > 0) {
                $slices[] = [
                    'label' => $v[0],
                    'value' => (float) $states[$k],
                    'color' => $stateColour[$k] ?? 'var(--s1)',
                ];
            }
        }
        fabo_chart_pie('Invoices by status', $slices);

        // Revenue against what it cost to earn it.
        $pnl = fabo_finance_pnl($scope, 6);
        fabo_chart_bar('Revenue vs spend — last 6 months', array_keys($pnl), [
            ['name' => 'Revenue', 'color' => 'var(--s1)',
             'values' => array_map(static fn($v) => $v['revenue'], array_values($pnl))],
            ['name' => 'Spend',   'color' => 'var(--s4)',
             'values' => array_map(static fn($v) => $v['expense'], array_values($pnl))],
        ], 'money');

        // Who earns what — only worth drawing when looking across everyone.
        $byClient = $clientId === 0 ? fabo_revenue_by_client(10) : [];
        if ($byClient) {
            $names = array_map(
                static fn($r) => $r['org'] !== '' ? $r['org'] : $r['name'], $byClient);
            fabo_chart_bar('Revenue by client', $names, [
                ['name' => 'Collected', 'color' => 'var(--s3)',
                 'values' => array_map(static fn($r) => $r['collected'], $byClient)],
                ['name' => 'Still due', 'color' => 'var(--s4)',
                 'values' => array_map(static fn($r) => $r['due'], $byClient)],
            ], 'money');
        }
      ?>
    </div>

    <?php if ($byClient): ?>
    <table class="data" style="margin-top:14px">
      <thead><tr><th>Client</th><th class="num">Invoices</th><th class="num">Revenue</th>
        <th class="num">Collected</th><th class="num">Still due</th>
        <th class="num">Avg invoice</th><th class="num">Margin</th></tr></thead>
      <tbody>
      <?php foreach ($byClient as $r): ?>
        <tr>
          <td><a href="?client=<?= (int) $r['id'] ?>"><?= e($r['org'] !== '' ? $r['org'] : $r['name']) ?></a></td>
          <td class="num"><?= (int) $r['invoices'] ?></td>
          <td class="num"><?= e(fabo_money($r['revenue'])) ?></td>
          <td class="num"><?= e(fabo_money($r['collected'])) ?></td>
          <td class="num"><?= $r['due'] > 0 ? e(fabo_money($r['due'])) : '&mdash;' ?></td>
          <td class="num"><?= e(fabo_money($r['avg'])) ?></td>
          <td class="num"><?= e(fabo_money($r['margin'])) ?>
            <?= $r['margin_pct'] === null ? '' : ' <span class="pill '
                . ($r['margin_pct'] >= 0 ? 'green' : 'red') . '">'
                . e(fabo_pct($r['margin_pct'], 0)) . '</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="muted" style="margin:10px 2px 0">
      The whole desk, in detail, is on <a href="finance.php">Finance</a>.
    </p>
    <?php endif; ?>
    <?php endif; ?>
  </div>
<?php
}

/**
 * Clients, counted.
 *
 * A row-by-row comparison of every account belongs on the Finance desk,
 * where the money that makes it meaningful already lives. What the
 * dashboard wants is the headline: how many clients there are, how many
 * are actually active, and how many need something doing.
 */
function fabo_dash_client_block(array $viewer, int $clientId = 0): void
{
    if (empty($viewer['is_admin']) || $clientId !== 0) {
        return;   // narrowed to one client already, so a count says nothing
    }

    $c = fabo_one('SELECT
        COALESCE(SUM(kind = "client"),0)                                  AS clients,
        COALESCE(SUM(kind = "client" AND revoked_at IS NULL),0)           AS active,
        COALESCE(SUM(kind = "client" AND revoked_at IS NOT NULL),0)       AS revoked,
        COALESCE(SUM(kind = "client" AND parent_id IS NOT NULL),0)        AS sub,
        COALESCE(SUM(kind = "team"),0)                                    AS team,
        COALESCE(SUM(kind = "client" AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)),0) AS fresh
        FROM clients');

    // A client counts as working with us once they hold a sheet.
    $withSheet = fabo_one('SELECT COUNT(DISTINCT a.client_id) n
                           FROM client_sheet_access a
                           JOIN clients c ON c.id = a.client_id
                           WHERE c.kind = "client"');
    $billed = fabo_one('SELECT COUNT(DISTINCT client_id) n FROM invoices
                        WHERE state IN ("sent","paid")');
    $owing  = fabo_one('SELECT COUNT(DISTINCT client_id) n FROM invoices
                        WHERE state = "sent" AND due_date IS NOT NULL AND due_date < CURDATE()');
    ?>
  <div class="block">
    <h2>Clients</h2>
    <div class="tiles">
      <div class="tile"><div class="k">Clients</div>
        <div class="v"><?= (int) $c['clients'] ?></div>
        <div class="k">on the portal</div></div>
      <div class="tile green"><div class="k">Active</div>
        <div class="v"><?= (int) $c['active'] ?></div>
        <div class="k">not revoked</div></div>
      <div class="tile"><div class="k">With sheet access</div>
        <div class="v"><?= (int) $withSheet['n'] ?></div>
        <div class="k">working with us</div></div>
      <div class="tile"><div class="k">Billed</div>
        <div class="v"><?= (int) $billed['n'] ?></div>
        <div class="k">have had an invoice</div></div>
      <div class="tile <?= (int) $owing['n'] ? 'red' : '' ?>"><div class="k">Owing us</div>
        <div class="v"><?= (int) $owing['n'] ?></div>
        <div class="k">past a due date</div></div>
      <div class="tile"><div class="k">Added this month</div>
        <div class="v"><?= (int) $c['fresh'] ?></div>
        <div class="k">last 30 days</div></div>
    </div>
    <div class="tiles">
      <div class="tile"><div class="k">Team members</div>
        <div class="v"><?= (int) $c['team'] ?></div></div>
      <div class="tile"><div class="k">Sub-accounts</div>
        <div class="v"><?= (int) $c['sub'] ?></div>
        <div class="k">created by a client</div></div>
      <div class="tile <?= (int) $c['revoked'] ? 'amber' : '' ?>"><div class="k">Revoked</div>
        <div class="v"><?= (int) $c['revoked'] ?></div>
        <div class="k">access withdrawn</div></div>
    </div>
    <p class="muted" style="margin:2px 2px 0">
      Account by account, with what each has billed, is on
      <a href="finance.php">Finance</a>.
    </p>
  </div>
<?php
}

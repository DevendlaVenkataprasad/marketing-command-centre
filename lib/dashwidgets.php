<?php
/**
 * Dashboard charts chosen in Sheet Management.
 *
 * A chart is three choices, made on the Dashboard tab of Sheet Management:
 *
 *   WHAT to measure  -- one figure or several side by side: the number of
 *                       rows, approvals, or the total / average of any
 *                       number column;
 *   WHAT TO SPLIT BY -- any dropdown or text column, approval, or a date by
 *                       month / quarter / year;
 *   HOW TO SHOW IT   -- tile, progress, bar, stacked bar, column, stacked
 *                       column, line, area, pie, donut or table.
 *
 * Nothing names a column in code. The choices are read from the sheet's own
 * columns -- renamed, hidden and added ones included -- so a new column can
 * be charted the moment it exists.
 *
 * Charts are kept per organisation the way dropdown options are: 0 is ours
 * and everybody sees it; a client's own sit on top of ours and only their
 * people see them. Everything is drawn from rows the caller already fetched,
 * so it is scoped and filtered exactly as the sheet is.
 */

require_once __DIR__ . '/grid.php';
require_once __DIR__ . '/sheetdash.php';   // fabo_dash_cash(), fabo_dash_short()

/** At most this many figures in one chart; past it nothing is readable. */
const FABO_DW_MAX_MEASURES = 6;

/* ------------------------------------------------------------- choices */

/**
 * How a chart can be shown:
 * key => [label, needs a split, takes several measures, what it is for].
 */
function fabo_dw_charts(): array
{
    return [
        'tile'           => ['Number tiles',      false, true,  'The figures as big numbers. Pick several to show them side by side.'],
        'progress'       => ['Progress bar',      false, false, 'The first figure against the "Compare with" figure, as a bar and a %.'],
        'bar'            => ['Bar chart',         true,  true,  'Horizontal bars. Best for many named groups: spokes, owners, types.'],
        'stacked_bar'    => ['Stacked bar',       true,  true,  'Horizontal bars with each figure stacked on the last.'],
        'column'         => ['Column chart',      true,  true,  'Vertical columns, side by side when there are several figures.'],
        'stacked_column' => ['Stacked column',    true,  true,  'Vertical columns with each figure stacked on the last.'],
        'line'           => ['Line chart',        true,  true,  'A trend, one line per figure. Split by a date.'],
        'area'           => ['Area chart',        true,  true,  'A trend with the volume filled in. Split by a date.'],
        'pie'            => ['Pie chart',         true,  false, 'Share of the whole for the first figure. Up to about six groups.'],
        'donut'          => ['Donut chart',       true,  false, 'A pie with the total in the middle.'],
        'table'          => ['Table',             true,  true,  'Every group with each figure in its own column, and a total.'],
    ];
}

/** A small picture of each chart type, for the picker. */
function fabo_dw_icon(string $chart): string
{
    $p = [
        'tile'           => '<rect x="3" y="7" width="12" height="10" rx="2"/><rect x="17" y="7" width="12" height="10" rx="2"/>',
        'progress'       => '<rect x="3" y="13" width="26" height="6" rx="3" fill-opacity=".25"/><rect x="3" y="13" width="17" height="6" rx="3"/>',
        'bar'            => '<rect x="4" y="5" width="22" height="5" rx="1"/><rect x="4" y="13" width="15" height="5" rx="1"/><rect x="4" y="21" width="9" height="5" rx="1"/>',
        'stacked_bar'    => '<rect x="4" y="6" width="12" height="7" rx="1"/><rect x="16" y="6" width="10" height="7" rx="1" fill-opacity=".45"/><rect x="4" y="17" width="8" height="7" rx="1"/><rect x="12" y="17" width="7" height="7" rx="1" fill-opacity=".45"/>',
        'column'         => '<rect x="5" y="12" width="5" height="14" rx="1"/><rect x="11" y="6" width="5" height="20" rx="1" fill-opacity=".45"/><rect x="19" y="15" width="5" height="11" rx="1"/><rect x="25" y="9" width="4" height="17" rx="1" fill-opacity=".45"/>',
        'stacked_column' => '<rect x="6" y="14" width="7" height="12" rx="1"/><rect x="6" y="7" width="7" height="7" rx="1" fill-opacity=".45"/><rect x="18" y="17" width="7" height="9" rx="1"/><rect x="18" y="11" width="7" height="6" rx="1" fill-opacity=".45"/>',
        'line'           => '<polyline points="3,22 10,14 17,18 24,8 29,11" fill="none" stroke="currentColor" stroke-width="2.4"/>',
        'area'           => '<polygon points="3,26 3,20 10,13 17,17 24,8 29,11 29,26" fill-opacity=".45"/><polyline points="3,20 10,13 17,17 24,8 29,11" fill="none" stroke="currentColor" stroke-width="2"/>',
        'pie'            => '<circle cx="16" cy="16" r="11" fill-opacity=".35"/><path d="M16 16 L16 5 A11 11 0 0 1 26.5 19.5 Z"/>',
        'donut'          => '<circle cx="16" cy="16" r="11" fill="none" stroke="currentColor" stroke-width="6" stroke-opacity=".35"/><path d="M16 5 A11 11 0 0 1 27 16" fill="none" stroke="currentColor" stroke-width="6"/>',
        'table'          => '<rect x="4" y="6" width="24" height="4" rx="1"/><rect x="4" y="13" width="24" height="3" rx="1" fill-opacity=".45"/><rect x="4" y="19" width="24" height="3" rx="1" fill-opacity=".45"/><rect x="4" y="25" width="24" height="2" rx="1" fill-opacity=".45"/>',
    ][$chart] ?? '';
    return '<svg viewBox="0 0 32 32" width="32" height="32" fill="currentColor" aria-hidden="true">' . $p . '</svg>';
}

/** How wide a chart sits on the dashboard. */
function fabo_dw_widths(): array
{
    return ['third' => 'One third', 'half' => 'Half', 'full' => 'Full width'];
}

/** A column's heading as people know it: "No. of Participants — Achieved". */
function fabo_dw_col_label(array $c): string
{
    $g = trim((string) ($c[3]['group'] ?? ''));
    return $g !== '' ? $g . ' — ' . $c[0] : (string) $c[0];
}

/** Is this column a number that can be totalled? Ratios cannot. */
function fabo_dw_is_number(array $c): bool
{
    if (in_array($c[2], ['int', 'dec', 'money'], true)) {
        return true;
    }
    return $c[2] === 'computed'
        && in_array($c[3]['calc'] ?? '', ['link_money', 'link_int', 'store_roll', 'budget_remaining'], true);
}

/** Does the sheet record approvals, by the portal's trail or a Sign-off column? */
function fabo_dw_has_approval(array $sheet): bool
{
    foreach ($sheet['columns'] as $c) {
        if (in_array($c[1], ['approval_state', 'approval_status'], true)) {
            return true;
        }
    }
    return false;
}

/**
 * Every figure the sheet can be measured by:
 * key => [label, kind, family]. kind is how it is written (money, num, dec);
 * family is the heading it is listed under in the builder.
 */
function fabo_dw_metrics(array $sheet): array
{
    $noun = trim((string) ($sheet['label'] ?? 'entries'));
    $out  = ['rows' => ['Total ' . $noun . ' (number of rows)', 'num', 'Counts']];
    if (fabo_dw_has_approval($sheet)) {
        $out['approved'] = ['Approved', 'num', 'Counts'];
        $out['pending']  = ['Pending approval', 'num', 'Counts'];
        $out['rejected'] = ['Rejected', 'num', 'Counts'];
    }
    foreach ($sheet['columns'] as $c) {
        if (!fabo_dw_is_number($c)) { continue; }
        $kind = fabo_col_render($c) === 'money' ? 'money' : ($c[2] === 'dec' ? 'dec' : 'num');
        $l    = fabo_dw_col_label($c);
        $out['sum:' . $c[1]] = [preg_match('/^total\b/i', $l) ? $l : 'Total ' . $l, $kind, 'Totals'];
    }
    foreach ($sheet['columns'] as $c) {
        if (!fabo_dw_is_number($c)) { continue; }
        $kind = fabo_col_render($c) === 'money' ? 'money' : 'dec';
        $out['avg:' . $c[1]] = ['Average of ' . preg_replace('/^total /i', '', fabo_dw_col_label($c)),
                                $kind, 'Averages'];
    }
    foreach ($sheet['columns'] as $c) {
        if (in_array($c[2], ['select', 'text'], true)) {
            $out['distinct:' . $c[1]] = ['Number of different ' . fabo_dw_col_label($c), 'num', 'Different values'];
        }
    }
    return $out;
}

/** Everything a chart can be split by, as key => label. */
function fabo_dw_groupings(array $sheet): array
{
    $out = [];
    if (fabo_dw_has_approval($sheet)) {
        $out['approval'] = 'Approval (Approved / Pending / Rejected)';
    }
    foreach ($sheet['columns'] as $c) {
        if ($c[2] === 'select') {
            $out[$c[1]] = fabo_dw_col_label($c);
        }
    }
    foreach ($sheet['columns'] as $c) {
        if ($c[2] === 'date') {
            $l = fabo_dw_col_label($c);
            $out['month:' . $c[1]]   = $l . ' — by month';
            $out['quarter:' . $c[1]] = $l . ' — by quarter';
            $out['year:' . $c[1]]    = $l . ' — by year';
        }
    }
    foreach ($sheet['columns'] as $c) {
        if ($c[2] === 'text') {
            $out[$c[1]] = fabo_dw_col_label($c);
        }
    }
    return $out;
}

/** The measures of a stored chart, as a list of keys. */
function fabo_dw_measures(array $w): array
{
    return array_values(array_filter(array_map('trim',
        explode(',', (string) ($w['metric'] ?? '')))));
}

/* ---------------------------------------------------------- arithmetic */

/** Where a row stands on approval. The portal's decision wins over Sign-off. */
function fabo_dw_approval(array $r): string
{
    $s = strtolower(trim((string) ($r['approval_state'] ?? '')));
    if (str_starts_with($s, 'approved')) { return 'approved'; }
    if (str_starts_with($s, 'rejected')) { return 'rejected'; }
    $o = strtolower(trim((string) ($r['approval_status'] ?? '')));
    if ($o === 'approved') { return 'approved'; }
    if ($o === 'rejected') { return 'rejected'; }
    // A completed event has happened: it is approved, not waiting.
    if (strcasecmp(trim((string) ($r['status'] ?? '')), FABO_STATUS_COMPLETED) === 0) {
        return 'approved';
    }
    return 'pending';
}

/** One figure over a set of rows. */
function fabo_dw_value(string $metric, array $rows): float
{
    if ($metric === 'rows') {
        return (float) count($rows);
    }
    if (in_array($metric, ['approved', 'pending', 'rejected'], true)) {
        $n = 0;
        foreach ($rows as $r) { if (fabo_dw_approval($r) === $metric) { $n++; } }
        return (float) $n;
    }
    [$op, $f] = array_pad(explode(':', $metric, 2), 2, '');
    if ($op === 'sum') {
        $t = 0.0;
        foreach ($rows as $r) { $t += (float) ($r[$f] ?? 0); }
        return $t;
    }
    if ($op === 'avg') {
        $t = 0.0; $n = 0;
        foreach ($rows as $r) {
            $v = $r[$f] ?? null;
            if ($v === null || $v === '') { continue; }
            $t += (float) $v; $n++;
        }
        return $n ? $t / $n : 0.0;
    }
    if ($op === 'distinct') {
        $seen = [];
        foreach ($rows as $r) {
            $v = trim((string) ($r[$f] ?? ''));
            if ($v !== '') { $seen[mb_strtolower($v)] = true; }
        }
        return (float) count($seen);
    }
    return 0.0;
}

/** Do the groups of this figure add up to the whole? Counts and totals do;
 *  averages and "number of different" do not, so they get no shares or total. */
function fabo_dw_additive(string $metric): bool
{
    return in_array($metric, ['rows', 'approved', 'pending', 'rejected'], true)
        || str_starts_with($metric, 'sum:');
}

/**
 * The rows split into groups, every measure worked out for every group.
 *
 * Returns ['labels' => [..], 'series' => [metric => [..values in label order]]].
 * Dates and approval come out in their natural order; everything else by the
 * first measure, largest first, with the smallest folded into "Other" past
 * $top. $keep lists groups that must appear even when no row has them.
 */
function fabo_dw_series(string $group, array $metrics, array $rows, int $top = 8, array $keep = []): array
{
    [$op, $f] = str_contains($group, ':') ? explode(':', $group, 2) : ['', $group];
    $buckets = [];
    $names   = [];
    foreach ($keep as $k) {
        $buckets[mb_strtolower($k)] = [];
        $names[mb_strtolower($k)]   = $k;
    }
    foreach ($rows as $r) {
        if ($group === 'approval') {
            $k = ['approved' => '1', 'pending' => '2', 'rejected' => '3'][fabo_dw_approval($r)];
            $names[$k] = ['1' => 'Approved', '2' => 'Pending', '3' => 'Rejected'][$k];
        } elseif ($op !== '') {
            $d = (string) ($r[$f] ?? '');
            $t = $d !== '' ? strtotime($d) : false;
            if (!$t) {
                $k = '~';
                $names[$k] = '(no date)';
            } elseif ($op === 'month') {
                $k = date('Y-m', $t);
                $names[$k] = date('M y', $t);
            } elseif ($op === 'quarter') {
                $q = (int) ceil(date('n', $t) / 3);
                $k = date('Y', $t) . '-Q' . $q;
                $names[$k] = 'Q' . $q . ' ' . date('Y', $t);
            } else {
                $k = date('Y', $t);
                $names[$k] = $k;
            }
        } else {
            $v = trim((string) ($r[$f] ?? ''));
            $k = $v === '' ? '~' : mb_strtolower($v);
            $names[$k] = $names[$k] ?? ($v === '' ? '(not set)' : $v);
        }
        $buckets[$k][] = $r;
    }

    $first = $metrics[0] ?? 'rows';
    if ($op !== '' || $group === 'approval') {
        ksort($buckets, SORT_STRING);        // chronological; "~" (no date) last
    } else {
        $rank = [];
        foreach ($buckets as $k => $set) { $rank[$k] = fabo_dw_value($first, $set); }
        arsort($rank);
        $ordered = [];
        foreach ($rank as $k => $_) { $ordered[$k] = $buckets[$k]; }
        $buckets = $ordered;
        if (count($buckets) > $top && !$keep) {
            $kept = array_slice($buckets, 0, $top - 1, true);
            $rest = [];
            foreach (array_diff_key($buckets, $kept) as $set) {
                foreach ($set as $r) { $rest[] = $r; }
            }
            $buckets = $kept + ['~other' => $rest];
            $names['~other'] = 'Other';
        }
    }

    $out = ['labels' => [], 'series' => []];
    foreach ($metrics as $m) { $out['series'][$m] = []; }
    foreach ($buckets as $k => $set) {
        $out['labels'][] = (string) $names[$k];
        foreach ($metrics as $m) {
            $out['series'][$m][] = fabo_dw_value($m, $set);
        }
    }
    return $out;
}

/** A figure, written the way its column is. */
function fabo_dw_fmt(float $v, string $kind, bool $short = false): string
{
    if ($kind === 'money') {
        return fabo_dash_cash($v);
    }
    if ($kind === 'dec' && floor($v) != $v) {
        return number_format($v, 1);
    }
    return $short ? fabo_dash_short($v) : number_format($v);
}

/* ------------------------------------------------------------- storage */

/** The table, made if it is not there yet, and widened for several measures. */
function fabo_dw_install(): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    $db = fabo_db();
    $db->query("CREATE TABLE IF NOT EXISTS `dashboard_widgets` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sheet_key` VARCHAR(48) NOT NULL,
  `client_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `title` VARCHAR(120) NOT NULL DEFAULT '',
  `metric` VARCHAR(600) NOT NULL,
  `compare_metric` VARCHAR(80) NULL,
  `group_by` VARCHAR(80) NULL,
  `chart` VARCHAR(16) NOT NULL DEFAULT 'bar',
  `width` VARCHAR(8) NOT NULL DEFAULT 'third',
  `top_n` TINYINT UNSIGNED NOT NULL DEFAULT 8,
  `sort_order` INT NOT NULL DEFAULT 0,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_sheet` (`sheet_key`,`client_id`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    /* Shared charts an organisation has taken off its own dashboard, or
       replaced with its own edited copy. The shared chart itself stays for
       everybody else. */
    $db->query("CREATE TABLE IF NOT EXISTS `dashboard_widget_hides` (
  `widget_id` INT UNSIGNED NOT NULL,
  `client_id` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`widget_id`,`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    /* The first version held one measure in VARCHAR(80). Several measures
       need more room; widened once, here, so nobody has to remember to. */
    try {
        $res = $db->query("SHOW COLUMNS FROM `dashboard_widgets` LIKE 'metric'");
        $col = $res ? $res->fetch_assoc() : null;
        if ($col && stripos((string) $col['Type'], 'varchar(80)') !== false) {
            $db->query('ALTER TABLE `dashboard_widgets` MODIFY `metric` VARCHAR(600) NOT NULL');
        }
    } catch (Throwable $e) {
        error_log('FABO dashboard widgets widen: ' . $e->getMessage());
    }
}

/**
 * The charts on one sheet as one organisation sees them: the shared ones it
 * has not taken off (or replaced with its own copy), and its own, in order.
 */
function fabo_dw_list(string $key, int $owner = 0, bool $activeOnly = false): array
{
    $owner = max(0, $owner);
    try {
        fabo_dw_install();
        return fabo_all('SELECT * FROM dashboard_widgets w
                          WHERE w.sheet_key = ? AND w.client_id IN (0, ?)'
                      . ($activeOnly ? ' AND w.active = 1' : '')
                      . ' AND NOT (w.client_id = 0 AND ? > 0 AND EXISTS (
                              SELECT 1 FROM dashboard_widget_hides h
                               WHERE h.widget_id = w.id AND h.client_id = ?))
                          ORDER BY w.sort_order, w.client_id, w.id',
                        'siii', [$key, $owner, $owner, $owner]);
    } catch (Throwable $e) {
        error_log('FABO dashboard widgets: ' . $e->getMessage());
        return [];
    }
}

/** One chart, if this organisation can see it. */
function fabo_dw_get(int $id, int $scope): ?array
{
    fabo_dw_install();
    $w = fabo_one('SELECT * FROM dashboard_widgets WHERE id = ?', 'i', [$id]);
    return ($w && in_array((int) $w['client_id'], [0, $scope], true)) ? $w : null;
}

/** Is this a shared chart, seen from an organisation's own desk? */
function fabo_dw_is_shared_for(array $w, int $scope): bool
{
    return $scope > 0 && (int) $w['client_id'] === 0;
}

/**
 * The chart this organisation may change, making it theirs first if need be.
 *
 * Changing a shared chart from a client's desk must not change it for every
 * other client. So the first change makes a copy that is the organisation's
 * own -- same settings, same place on the dashboard -- and takes the shared
 * one off their dashboard. Everybody else keeps the shared one. Returns the
 * id to change.
 */
function fabo_dw_adopt(int $id, int $scope): int
{
    $w = fabo_dw_get($id, $scope);
    if (!$w) {
        throw new InvalidArgumentException('That chart was not found.');
    }
    if (!fabo_dw_is_shared_for($w, $scope)) {
        return $id;
    }
    fabo_exec('INSERT INTO dashboard_widgets
                 (sheet_key, client_id, title, metric, compare_metric, group_by, chart, width, top_n, sort_order, active)
               VALUES (?,?,?,?,?,?,?,?,?,?,?)',
              'sissssssiii', [$w['sheet_key'], $scope, $w['title'], $w['metric'], $w['compare_metric'],
                              $w['group_by'], $w['chart'], $w['width'], (int) $w['top_n'],
                              (int) $w['sort_order'], (int) $w['active']]);
    $copy = (int) fabo_db()->insert_id;
    fabo_dw_hide_shared($id, $scope);
    return $copy;
}

function fabo_dw_hide_shared(int $id, int $scope): void
{
    fabo_exec('INSERT IGNORE INTO dashboard_widget_hides (widget_id, client_id) VALUES (?,?)',
              'ii', [$id, $scope]);
}

/** Shared charts this organisation has taken off, so they can be put back. */
function fabo_dw_hidden_shared(string $key, int $scope): array
{
    if ($scope <= 0) {
        return [];
    }
    fabo_dw_install();
    return fabo_all('SELECT w.* FROM dashboard_widgets w
                       JOIN dashboard_widget_hides h ON h.widget_id = w.id AND h.client_id = ?
                      WHERE w.sheet_key = ? AND w.client_id = 0
                      ORDER BY w.sort_order, w.id', 'is', [$scope, $key]);
}

function fabo_dw_restore_shared(int $id, int $scope): void
{
    fabo_exec('DELETE FROM dashboard_widget_hides WHERE widget_id = ? AND client_id = ?',
              'ii', [$id, $scope]);
}

/**
 * Check what was posted against what the sheet actually offers.
 * Measures come as `metrics[]` (several) or `metric` (one, or a comma list).
 */
function fabo_dw_clean(string $key, array $in): array
{
    $sheet = fabo_sheet($key);
    if (!$sheet) {
        throw new InvalidArgumentException('That sheet was not found.');
    }
    $metrics = fabo_dw_metrics($sheet);
    $groups  = fabo_dw_groupings($sheet);
    $charts  = fabo_dw_charts();

    $want = $in['metrics'] ?? (isset($in['metric']) ? explode(',', (string) $in['metric']) : []);
    $want = array_values(array_unique(array_filter(array_map('trim', (array) $want),
        static fn($m) => isset($metrics[$m]))));
    $compare = (string) ($in['compare_metric'] ?? '');
    $group   = (string) ($in['group_by'] ?? '');
    $chart   = (string) ($in['chart'] ?? '');
    $width   = (string) ($in['width'] ?? 'third');

    if (!isset($charts[$chart])) {
        throw new InvalidArgumentException('Pick how the chart should look.');
    }
    if (!$want) {
        throw new InvalidArgumentException('Tick at least one figure to measure.');
    }
    if (count($want) > FABO_DW_MAX_MEASURES) {
        throw new InvalidArgumentException('Up to ' . FABO_DW_MAX_MEASURES
            . ' figures fit in one chart. Untick some, or make a second chart.');
    }
    if (!$charts[$chart][2]) {
        $want = [$want[0]];        // pie, donut, progress: one figure
    }
    if ($group !== '' && !isset($groups[$group])) {
        throw new InvalidArgumentException('That column is not on this sheet any more.');
    }
    if ($charts[$chart][1] && $group === '') {
        throw new InvalidArgumentException('A ' . mb_strtolower($charts[$chart][0])
            . ' needs something to split by. Pick a column under "Split by".');
    }
    if ($chart === 'progress' && !isset($metrics[$compare])) {
        throw new InvalidArgumentException('A progress bar needs a "Compare with" figure to measure against.');
    }
    if (!isset($metrics[$compare]) || !in_array($chart, ['tile', 'progress'], true)) {
        $compare = '';
    }
    if (!$charts[$chart][1]) {
        $group = '';
    }

    $title = trim((string) ($in['title'] ?? ''));
    if ($title === '') {
        $names = array_map(static fn($m) => preg_replace('/ \(number of rows\)$/', '', $metrics[$m][0]), $want);
        $title = implode(' vs ', array_slice($names, 0, 3)) . (count($names) > 3 ? ' and more' : '')
               . ($group !== '' ? ' by ' . mb_strtolower(preg_replace('/ — by /', ' by ', $groups[$group])) : '');
    }
    return [
        'title'   => mb_substr($title, 0, 120),
        'metric'  => implode(',', $want),
        'compare' => $compare !== '' ? $compare : null,
        'group'   => $group !== '' ? $group : null,
        'chart'   => $chart,
        'width'   => isset(fabo_dw_widths()[$width]) ? $width : 'third',
        'top'     => max(3, min(20, (int) ($in['top_n'] ?? 8))),
        'active'  => array_key_exists('active', $in) ? (!empty($in['active']) ? 1 : 0) : 1,
    ];
}

function fabo_dw_add(string $key, array $in, int $scope): int
{
    fabo_dw_install();
    $c    = fabo_dw_clean($key, $in);
    // After every chart this organisation sees, shared ones included.
    $next = (int) (fabo_one('SELECT COALESCE(MAX(sort_order), 0) + 10 n FROM dashboard_widgets
                             WHERE sheet_key = ? AND client_id IN (0, ?)', 'si', [$key, $scope])['n'] ?? 10);
    fabo_exec('INSERT INTO dashboard_widgets
                 (sheet_key, client_id, title, metric, compare_metric, group_by, chart, width, top_n, sort_order, active)
               VALUES (?,?,?,?,?,?,?,?,?,?,1)',
              'sissssssii', [$key, $scope, $c['title'], $c['metric'], $c['compare'], $c['group'],
                             $c['chart'], $c['width'], $c['top'], $next]);
    return (int) fabo_db()->insert_id;
}

function fabo_dw_save(int $id, array $in, int $scope): void
{
    $id = fabo_dw_adopt($id, $scope);
    $w  = fabo_dw_get($id, $scope);
    $c  = fabo_dw_clean((string) $w['sheet_key'], $in + ['active' => $w['active']]);
    fabo_exec('UPDATE dashboard_widgets SET title = ?, metric = ?, compare_metric = ?, group_by = ?,
                      chart = ?, width = ?, top_n = ?, active = ? WHERE id = ?',
              'ssssssiii', [$c['title'], $c['metric'], $c['compare'], $c['group'], $c['chart'],
                            $c['width'], $c['top'], $c['active'], $id]);
}

function fabo_dw_toggle(int $id, int $scope): void
{
    $id = fabo_dw_adopt($id, $scope);
    fabo_exec('UPDATE dashboard_widgets SET active = 1 - active WHERE id = ?', 'i', [$id]);
}

/** Deleting a shared chart from a client's desk takes it off their dashboard only. */
function fabo_dw_delete(int $id, int $scope): void
{
    $w = fabo_dw_get($id, $scope);
    if (!$w) {
        throw new InvalidArgumentException('That chart was not found.');
    }
    if (fabo_dw_is_shared_for($w, $scope)) {
        fabo_dw_hide_shared($id, $scope);
        return;
    }
    fabo_exec('DELETE FROM dashboard_widgets WHERE id = ?', 'i', [$id]);
    fabo_exec('DELETE FROM dashboard_widget_hides WHERE widget_id = ?', 'i', [$id]);
}

/** One place up or down, among the charts this organisation sees. */
function fabo_dw_move(int $id, string $dir, int $scope): void
{
    $w = fabo_dw_get($id, $scope);
    if (!$w) {
        return;
    }
    $list = fabo_dw_list((string) $w['sheet_key'], $scope);
    $ids  = array_map(static fn($r) => (int) $r['id'], $list);
    $at   = array_search($id, $ids, true);
    if ($at === false) {
        return;
    }
    $to = $dir === 'up' ? $at - 1 : $at + 1;
    if ($to < 0 || $to >= count($ids)) {
        return;
    }
    /* Both charts become this organisation's before they trade places, so
       nobody else's dashboard moves. */
    $a  = fabo_dw_adopt($ids[$at], $scope);
    $b  = fabo_dw_adopt($ids[$to], $scope);
    $sa = (int) $list[$at]['sort_order'];
    $sb = (int) $list[$to]['sort_order'];
    if ($sa === $sb) {
        $sb = $dir === 'up' ? $sa - 1 : $sa + 1;
    }
    fabo_exec('UPDATE dashboard_widgets SET sort_order = ? WHERE id = ?', 'ii', [$sb, $a]);
    fabo_exec('UPDATE dashboard_widgets SET sort_order = ? WHERE id = ?', 'ii', [$sa, $b]);
}

/* ------------------------------------------------------------- drawing */

/**
 * The charts for one sheet, drawn from the rows on screen.
 * Says where to add some, to whoever may, when there are none.
 */
function fabo_dw_render(array $viewer, string $key, array $sheet, array $rows,
                        ?callable $skip = null): void
{
    require_once __DIR__ . '/manage.php';
    $widgets = fabo_dw_visible(fabo_dw_list($key, fabo_customiser($viewer), true), $skip);
    $desk    = fabo_chart_desk($viewer);
    $may     = $desk !== '';
    $setup   = $may ? fabo_link($viewer, $desk, ['tab' => 'dashboard', 'sheet' => $key]) : '';

    if (!$widgets) {
        if ($may) {
            echo '<div class="hint dw-hint">Want a chart of any column here? '
               . '<a href="' . e($setup) . '">Add one under Dashboard Charts</a>.</div>';
        }
        return;
    }

    echo '<div class="block dwgrid-block"><div class="calhead"><h2 style="margin:0;flex:1">Charts</h2>';
    if ($may) {
        echo '<a class="btn btn-sm" href="' . e($setup) . '">Edit charts</a>';
    }
    echo '</div><div class="dwgrid">';
    foreach ($widgets as $w) {
        echo '<div class="dwcard dw-' . e((string) $w['width']) . '">'
           . '<h3>' . e((string) $w['title']) . '</h3>'
           . fabo_dw_draw($w, $sheet, $rows) . '</div>';
    }
    echo '</div></div>';
}

/**
 * The charts worth drawing: none the page says it already shows ($skip), and
 * no two the same -- one measure set, one split, one look is drawn once, the
 * first time it comes up, however many copies exist.
 */
function fabo_dw_visible(array $widgets, ?callable $skip = null): array
{
    $seen = [];
    $out  = [];
    foreach ($widgets as $w) {
        if ($skip && $skip($w)) {
            continue;
        }
        $m = fabo_dw_measures($w);
        sort($m);
        $sig = implode(',', $m) . '|' . ($w['group_by'] ?? '') . '|' . ($w['chart'] ?? '')
             . '|' . ($w['compare_metric'] ?? '');
        if (isset($seen[$sig])) {
            continue;
        }
        $seen[$sig] = true;
        $out[] = $w;
    }
    return $out;
}

/**
 * One chart's body, from a stored chart (or anything shaped like one).
 * Shared by the dashboard, the builder's preview and the spoke-wise block.
 */
function fabo_dw_draw(array $w, array $sheet, array $rows, array $keep = []): string
{
    $metrics = fabo_dw_metrics($sheet);
    $charts  = fabo_dw_charts();
    $chart   = (string) ($w['chart'] ?? '');
    $want    = array_values(array_filter(fabo_dw_measures($w), static fn($m) => isset($metrics[$m])));
    if (!$want || !isset($charts[$chart])) {
        return '<div class="empty">This chart\'s column is no longer on the sheet.</div>';
    }
    if (!$rows && !$keep) {
        return '<div class="empty">Nothing to chart yet.</div>';
    }
    $names = [];
    $kinds = [];
    foreach ($want as $m) {
        $names[$m] = preg_replace('/ \(number of rows\)$/', '', $metrics[$m][0]);
        $kinds[$m] = $metrics[$m][1];
    }

    if ($chart === 'tile' || $chart === 'progress') {
        $cmp = (string) ($w['compare_metric'] ?? '');
        $of  = isset($metrics[$cmp]) ? fabo_dw_value($cmp, $rows) : null;
        if ($chart === 'progress') {
            return fabo_dw_progress(fabo_dw_value($want[0], $rows), $kinds[$want[0]],
                                    (float) $of, $metrics[$cmp] ?? ['', 'num']);
        }
        $h = '<div class="dwtiles">';
        foreach ($want as $m) {
            $h .= '<div class="dwtilebox">' . (count($want) > 1 ? '<span>' . e($names[$m]) . '</span>' : '')
                . fabo_dw_tile(fabo_dw_value($m, $rows), $kinds[$m], $of, $of !== null ? $metrics[$cmp] : null)
                . '</div>';
        }
        return $h . '</div>';
    }

    $data  = fabo_dw_series((string) ($w['group_by'] ?? ''), $want, $rows, (int) ($w['top_n'] ?? 8), $keep);
    $kind  = $kinds[$want[0]];
    $whole = [];
    foreach ($want as $m) {
        $whole[$m] = fabo_dw_additive($m) ? fabo_dw_value($m, $rows) : null;
    }
    return match ($chart) {
        'bar', 'stacked_bar'       => fabo_dw_bars($data, $names, $kind, $chart === 'stacked_bar'),
        'column', 'stacked_column' => fabo_dw_columns($data, $names, $kind, $chart === 'stacked_column'),
        'line', 'area'             => fabo_dw_lines($data, $names, $kind, $chart === 'area'),
        'pie', 'donut'             => fabo_dw_pie($data, $kind, $chart === 'donut', $whole[$want[0]]),
        default                    => fabo_dw_table($data, $names, $kinds, $whole),
    };
}

function fabo_dw_colour(int $i): string
{
    return FABO_SERIES[$i % count(FABO_SERIES)];
}

/** A key naming each figure's colour, for charts with more than one. */
function fabo_dw_key(array $names): string
{
    if (count($names) < 2) {
        return '';
    }
    $h = '<div class="dwkey">';
    $i = 0;
    foreach ($names as $n) {
        $h .= '<span><i style="background:' . fabo_dw_colour($i++) . '"></i>' . e($n) . '</span>';
    }
    return $h . '</div>';
}

function fabo_dw_tile(float $v, string $kind, ?float $of, ?array $ofMeta): string
{
    $h = '<div class="dwtile">' . e(fabo_dw_fmt($v, $kind)) . '</div>';
    if ($of !== null && $ofMeta) {
        $h .= '<div class="dwsub">of ' . e(fabo_dw_fmt($of, $ofMeta[1])) . ' '
            . e(mb_strtolower(preg_replace('/^Total /', '', $ofMeta[0])))
            . ($of > 0 ? ' &middot; <strong>' . round($v / $of * 100, 1) . '%</strong>' : '') . '</div>';
    }
    return $h;
}

function fabo_dw_progress(float $v, string $kind, float $of, array $ofMeta): string
{
    $pct = $of > 0 ? round($v / $of * 100, 1) : null;
    $cls = $pct === null ? 'muted' : ($pct >= 90 ? '' : ($pct >= 50 ? 'warn' : 'risk'));
    return '<div class="dwtile">' . ($pct === null ? '—' : $pct . '%') . '</div>'
         . '<div class="bar ' . $cls . '" style="margin:8px 0 6px"><i style="width:'
         . min(100, (float) $pct) . '%"></i></div>'
         . '<div class="dwsub">' . e(fabo_dw_fmt($v, $kind)) . ' of ' . e(fabo_dw_fmt($of, $ofMeta[1]))
         . ' ' . e(mb_strtolower(preg_replace('/^Total /', '', $ofMeta[0]))) . '</div>';
}

/** Horizontal bars: one per figure per group, or stacked into one. */
function fabo_dw_bars(array $data, array $names, string $kind, bool $stacked): string
{
    if (!$data['labels']) { return '<div class="empty">No data.</div>'; }
    $series = array_values($data['series']);
    $max = 0.0;
    foreach ($data['labels'] as $i => $_) {
        $vals = array_map(static fn($s) => max(0, $s[$i]), $series);
        $max  = max($max, $stacked ? array_sum($vals) : max($vals));
    }
    $max = $max ?: 1;
    $h = fabo_dw_key($names) . '<div class="dwbars">';
    foreach ($data['labels'] as $i => $label) {
        $h .= '<div class="dwbar"><span class="dwl" title="' . e($label) . '">' . e($label) . '</span>';
        if ($stacked) {
            $h .= '<span class="dwtrack dwstack">';
            foreach ($series as $k => $s) {
                $h .= '<i style="width:' . round(max(0, $s[$i]) / $max * 100, 2) . '%;background:'
                    . fabo_dw_colour($k) . '" title="' . e(array_values($names)[$k] . ': ' . fabo_dw_fmt($s[$i], $kind)) . '"></i>';
            }
            $h .= '</span><span class="dwv">' . e(fabo_dw_fmt(array_sum(array_map(static fn($s) => $s[$i], $series)), $kind, true)) . '</span>';
        } else {
            $h .= '<span class="dwmulti">';
            foreach ($series as $k => $s) {
                $h .= '<span class="dwrow"><span class="dwtrack"><i style="width:'
                    . round(max(0, $s[$i]) / $max * 100, 2) . '%;background:' . fabo_dw_colour($k) . '"></i></span>'
                    . '<span class="dwv">' . e(fabo_dw_fmt($s[$i], $kind, true)) . '</span></span>';
            }
            $h .= '</span>';
        }
        $h .= '</div>';
    }
    return $h . '</div>';
}

/** Vertical columns: side by side per group, or stacked into one. */
function fabo_dw_columns(array $data, array $names, string $kind, bool $stacked): string
{
    if (!$data['labels']) { return '<div class="empty">No data.</div>'; }
    $series = array_values($data['series']);
    $nm     = array_values($names);
    $n      = count($data['labels']);
    $ns     = count($series);
    $W = max(360, $n * ($stacked ? 46 : 22 * $ns + 18)); $top = 22; $base = 168; $H = 200;
    $max = 0.0;
    foreach ($data['labels'] as $i => $_) {
        $vals = array_map(static fn($s) => max(0, $s[$i]), $series);
        $max  = max($max, $stacked ? array_sum($vals) : max($vals));
    }
    $max  = $max ?: 1;
    $slot = $W / $n;
    $grp  = min($slot * 0.74, $stacked ? 40 : 26 * $ns);
    $bw   = $stacked ? $grp : $grp / $ns;
    $svg  = '<svg class="dwsvg" viewBox="0 0 ' . $W . ' ' . $H . '" role="img">'
          . '<line x1="0" y1="' . $base . '" x2="' . $W . '" y2="' . $base . '" stroke="#dde2ea"/>';
    foreach ($data['labels'] as $i => $label) {
        $x0 = $slot * $i + ($slot - $grp) / 2;
        $y  = $base;
        foreach ($series as $k => $s) {
            $v  = max(0, $s[$i]);
            $bh = $v / $max * ($base - $top);
            $x  = $stacked ? $x0 : $x0 + $bw * $k;
            $yy = $stacked ? $y - $bh : $base - $bh;
            $svg .= '<rect x="' . round($x, 1) . '" y="' . round($yy, 1) . '" width="' . round(max(1, $bw - ($stacked ? 0 : 2)), 1)
                  . '" height="' . max(0.5, round($bh, 1)) . '" rx="2" fill="' . fabo_dw_colour($k) . '">'
                  . '<title>' . e($label . ' — ' . $nm[$k] . ': ' . fabo_dw_fmt($s[$i], $kind)) . '</title></rect>';
            if (!$stacked && $ns <= 3) {
                $svg .= '<text x="' . round($x + $bw / 2 - 1, 1) . '" y="' . round($yy - 4, 1)
                      . '" text-anchor="middle" class="dwsv">' . e(fabo_dw_fmt($s[$i], $kind, true)) . '</text>';
            }
            if ($stacked) { $y -= $bh; }
        }
        if ($stacked) {
            $svg .= '<text x="' . round($x0 + $grp / 2, 1) . '" y="' . round($y - 4, 1) . '" text-anchor="middle" class="dwsv">'
                  . e(fabo_dw_fmt(array_sum(array_map(static fn($s) => $s[$i], $series)), $kind, true)) . '</text>';
        }
        $svg .= '<text x="' . round($slot * $i + $slot / 2, 1) . '" y="' . ($base + 14)
              . '" text-anchor="middle" class="dwsl">' . e(mb_strimwidth($label, 0, (int) max(5, $slot / 6), '…')) . '</text>';
    }
    return fabo_dw_key($names) . '<div class="dwscroll">' . $svg . '</svg></div>';
}

/** Lines (or filled areas), one per figure. */
function fabo_dw_lines(array $data, array $names, string $kind, bool $area): string
{
    if (!$data['labels']) { return '<div class="empty">No data.</div>'; }
    $W = 360; $top = 22; $base = 160; $padX = 20;
    $n    = count($data['labels']);
    $max  = 0.0;
    foreach ($data['series'] as $s) { $max = max($max, max($s)); }
    $max  = $max ?: 1;
    $step = $n > 1 ? ($W - 2 * $padX) / ($n - 1) : 0;
    $xAt  = static fn($i) => $n === 1 ? $W / 2 : $padX + $step * $i;
    $svg  = '<svg class="dwsvg" viewBox="0 0 ' . $W . ' 200" role="img">'
          . '<line x1="0" y1="' . $base . '" x2="' . $W . '" y2="' . $base . '" stroke="#dde2ea"/>';
    $one  = count($data['series']) === 1;
    $k    = 0;
    $nm   = array_values($names);
    foreach ($data['series'] as $s) {
        $col = fabo_dw_colour($k);
        $pts = [];
        foreach ($s as $i => $v) {
            $pts[] = [$xAt($i), $base - max(0, $v) / $max * ($base - $top), $v];
        }
        $line = implode(' ', array_map(static fn($p) => round($p[0], 1) . ',' . round($p[1], 1), $pts));
        if ($area) {
            $svg .= '<polygon points="' . round($pts[0][0], 1) . ',' . $base . ' ' . $line . ' '
                  . round(end($pts)[0], 1) . ',' . $base . '" fill="' . $col . '" fill-opacity="' . ($one ? '.18' : '.12') . '"/>';
        }
        $svg .= '<polyline points="' . $line . '" fill="none" stroke="' . $col . '" stroke-width="2.2" stroke-linejoin="round"/>';
        $every = (int) max(1, ceil($n / 8));
        foreach ($pts as $i => [$x, $y, $v]) {
            $svg .= '<circle cx="' . round($x, 1) . '" cy="' . round($y, 1) . '" r="3" fill="' . $col . '">'
                  . '<title>' . e($data['labels'][$i] . ' — ' . $nm[$k] . ': ' . fabo_dw_fmt($v, $kind)) . '</title></circle>';
            if ($one && ($i % $every === 0 || $i === $n - 1)) {
                $svg .= '<text x="' . round($x, 1) . '" y="' . round($y - 7, 1) . '" text-anchor="middle" class="dwsv">'
                      . e(fabo_dw_fmt($v, $kind, true)) . '</text>';
            }
        }
        $k++;
    }
    $every = (int) max(1, ceil($n / 8));
    foreach ($data['labels'] as $i => $label) {
        if ($i % $every === 0 || $i === $n - 1) {
            $svg .= '<text x="' . round($xAt($i), 1) . '" y="' . ($base + 14) . '" text-anchor="middle" class="dwsl">'
                  . e(mb_strimwidth($label, 0, 10, '…')) . '</text>';
        }
    }
    return fabo_dw_key($names) . $svg . '</svg>';
}

function fabo_dw_pie(array $data, string $kind, bool $donut, ?float $whole): string
{
    $vals = [];
    foreach ($data['labels'] as $i => $l) {
        $v = (float) (array_values($data['series'])[0][$i] ?? 0);
        if ($v > 0) { $vals[$l] = $v; }
    }
    if (!$vals) { return '<div class="empty">No data.</div>'; }
    $total = array_sum($vals);
    $cx = 90; $cy = 90; $r = 80; $ri = 48;
    $svg = '<svg class="dwsvg dwpie" viewBox="0 0 180 180" role="img">';
    $a0  = -M_PI / 2;
    $i   = 0;
    foreach ($vals as $label => $v) {
        $col = fabo_dw_colour($i++);
        $tip = '<title>' . e($label . ': ' . fabo_dw_fmt($v, $kind)
             . ($whole !== null ? ' (' . round($v / $total * 100, 1) . '%)' : '')) . '</title>';
        if (count($vals) === 1) {
            $svg .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '" fill="' . $col . '">' . $tip . '</circle>';
            break;
        }
        $a1 = $a0 + $v / $total * 2 * M_PI;
        $p  = sprintf('M%.2f %.2f A%d %d 0 %d 1 %.2f %.2f L%.2f %.2f Z',
              $cx + $r * cos($a0), $cy + $r * sin($a0), $r, $r, ($a1 - $a0) > M_PI ? 1 : 0,
              $cx + $r * cos($a1), $cy + $r * sin($a1), $cx, $cy);
        $svg .= '<path d="' . $p . '" fill="' . $col . '" stroke="#fff" stroke-width="1.5">' . $tip . '</path>';
        $a0 = $a1;
    }
    if ($donut) {
        $svg .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $ri . '" fill="#fff"/>';
        if ($whole !== null) {
            $svg .= '<text x="' . $cx . '" y="' . ($cy - 2) . '" text-anchor="middle" class="dwdt">'
                  . e(fabo_dw_fmt($whole, $kind, true)) . '</text>'
                  . '<text x="' . $cx . '" y="' . ($cy + 14) . '" text-anchor="middle" class="dwsl">total</text>';
        }
    }
    $h = '<ul class="dwlegend">';
    $i = 0;
    foreach ($vals as $label => $v) {
        $h .= '<li><i style="background:' . fabo_dw_colour($i++) . '"></i><span>' . e($label)
            . '</span><b>' . e(fabo_dw_fmt($v, $kind, true)) . '</b>'
            . '<small>' . ($whole !== null ? round($v / $total * 100) . '%' : '') . '</small></li>';
    }
    return '<div class="dwpiewrap">' . $svg . '</svg>' . $h . '</ul></div>';
}

/** A table: one column per figure, a total row where the figures add up. */
function fabo_dw_table(array $data, array $names, array $kinds, array $whole): string
{
    if (!$data['labels']) { return '<div class="empty">No data.</div>'; }
    $single = count($names) === 1;
    $m0     = array_key_first($names);
    $h = '<div class="scrollx"><table class="data dwtable"><thead><tr><th></th>';
    foreach ($names as $n) { $h .= '<th class="num">' . e($n) . '</th>'; }
    if ($single && $whole[$m0] !== null) { $h .= '<th class="num">Share</th>'; }
    $h .= '</tr></thead><tbody>';
    foreach ($data['labels'] as $i => $label) {
        $h .= '<tr><td>' . e($label) . '</td>';
        foreach ($data['series'] as $m => $s) {
            $h .= '<td class="num">' . e(fabo_dw_fmt($s[$i], $kinds[$m])) . '</td>';
        }
        if ($single && $whole[$m0] !== null) {
            $h .= '<td class="num" style="color:var(--ink-soft)">'
                . ($whole[$m0] > 0 ? round($data['series'][$m0][$i] / $whole[$m0] * 100, 1) . '%' : '') . '</td>';
        }
        $h .= '</tr>';
    }
    if (array_filter($whole, static fn($v) => $v !== null)) {
        $h .= '<tr class="dwtotal"><td>Total</td>';
        foreach ($names as $m => $_) {
            $h .= '<td class="num">' . ($whole[$m] !== null ? e(fabo_dw_fmt($whole[$m], $kinds[$m])) : '') . '</td>';
        }
        if ($single && $whole[$m0] !== null) { $h .= '<td></td>'; }
        $h .= '</tr>';
    }
    return $h . '</tbody></table></div>';
}

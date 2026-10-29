<?php
/**
 * A dashboard for every sheet, built generically from that sheet's own
 * columns — no sheet-specific code. Money columns become totals, status and
 * brand columns become breakdowns, the sheet's driving date becomes a trend.
 *
 * The whole panel is ONE inline <svg> using literal colours (not CSS
 * variables), so it can be serialised and saved as a PNG exactly as it looks.
 */

require_once __DIR__ . '/grid.php';
require_once __DIR__ . '/rules.php';
require_once __DIR__ . '/charts.php';   // fabo_trim_label(), palette helpers

/* ------------------------------------------------------------- metrics */

/** Columns of a given render kind, as [field => label]. */
function fabo_dash_cols(array $sheet, array $kinds): array
{
    $out = [];
    foreach ($sheet['columns'] as $c) {
        if (in_array(fabo_col_render($c), $kinds, true)) {
            $out[$c[1]] = $c[0];
        }
    }
    return $out;
}

/** Does this sheet have a column with this field name? */
function fabo_dash_has(array $sheet, string $field): bool
{
    foreach ($sheet['columns'] as $c) {
        if ($c[1] === $field) { return true; }
    }
    return false;
}

/**
 * The few figures a CEO reads a sheet by, computed correctly.
 *
 * Each shipped sheet gets the measures that matter for that kind of work --
 * spend against budget, leads and what they cost, return, delivery on time,
 * what is late -- rather than a total of every money column. Ratios (cost per
 * lead, ROI, CTR, engagement) are never summed or averaged row by row: each
 * is recomputed from its parts, so the figure is the one the whole sheet earns.
 * Cancelled work is left out of "completed", not counted as unfinished.
 *
 * A measure is skipped when its columns are not on the sheet, so a hidden or
 * renamed column, or a sheet built in the portal, still gets a correct panel.
 * It reads the rows already on screen, so it honours the viewer's scope.
 *
 * Returns ['tiles' => [[label, value, tone, note]], 'charts' => [...], 'count'].
 */
function fabo_sheet_metrics(string $key, array $sheet, array $rows): array
{
    $n      = count($rows);
    $today  = fabo_today();
    $in7    = date('Y-m-d', strtotime($today . ' +7 days'));
    $has    = static fn(string $f): bool => fabo_dash_has($sheet, $f);
    $sum    = static function (string $f) use ($rows): float {
        $t = 0.0;
        foreach ($rows as $r) { $t += (float) ($r[$f] ?? 0); }
        return $t;
    };
    $label  = static function (string $f, string $fallback = '') use ($sheet): string {
        $c = fabo_dash_col($sheet, $f);
        return $c ? (string) $c[0] : $fallback;
    };
    $num    = static fn($v): string => number_format((float) $v);
    $pct    = static fn(float $a, float $b): string => $b > 0 ? round($a / $b * 100, 1) . '%' : '—';

    /* ---- the work: done, cancelled, late, on time ---------------------- */
    $stat  = $has('status') ? 'status' : ($has('content_status') ? 'content_status' : '');
    $dueF  = fabo_dash_due_field($key, $sheet);
    $doneF = fabo_dash_done_date_field($sheet);
    $done = 0; $cancelled = 0; $late = 0; $soon = 0; $onTime = 0; $timed = 0;
    foreach ($rows as $r) {
        $s      = $stat !== '' ? (string) ($r[$stat] ?? '') : '';
        $isDone = fabo_dash_is_done($key, $s, $r);
        $isOff  = !$isDone && fabo_dash_is_cancelled($s);
        if ($isDone) { $done++; }
        if ($isOff)  { $cancelled++; }
        $due = $dueF !== '' ? (string) ($r[$dueF] ?? '') : '';
        if (!$isDone && !$isOff && $due !== '') {
            if ($due < $today)    { $late++; }
            elseif ($due <= $in7) { $soon++; }
        }
        if ($isDone && $due !== '' && $doneF !== '' && !empty($r[$doneF])) {
            $timed++;
            if ((string) $r[$doneF] <= $due) { $onTime++; }
        }
    }
    $live = $n - $cancelled;

    $T   = [];
    $add = static function (string $l, string $v, string $tone = '', string $note = '') use (&$T): void {
        $T[] = [$l, $v, $tone, $note];
    };
    $work = static function (string $noun) use ($add, $n, $done, $live, $late, $soon, $cancelled,
                                                $stat, $dueF, $pct, $num): void {
        $add($noun, $num($n), '', $cancelled ? $num($cancelled) . ' cancelled' : '');
        if ($stat !== '') {
            $add('Completed', $pct($done, $live), $live && $done / $live >= .7 ? 'good' : '',
                 $num($done) . ' of ' . $num($live));
        }
        if ($dueF !== '') {
            $add('Overdue', $num($late), $late ? 'risk' : 'good',
                 $soon ? $num($soon) . ' due in 7 days' : 'nothing due in 7 days');
        }
    };
    $onTimeTile = static function () use ($add, $onTime, $timed, $pct): void {
        if ($timed > 0) {
            $add('Delivered on time', $pct($onTime, $timed), $onTime / $timed >= .8 ? 'good' : 'warn',
                 $onTime . ' of ' . $timed . ' finished');
        }
    };
    $spend = static function (string $alloc, string $spent) use ($add, $has, $sum, $pct): void {
        if (!$has($spent)) { return; }
        $a = $has($alloc) ? $sum($alloc) : 0.0;
        $s = $sum($spent);
        $add('Spend', fabo_dash_cash($s), $a > 0 && $s > $a ? 'risk' : '',
             $a > 0 ? $pct($s, $a) . ' of ' . fabo_dash_cash($a) . ' budget' : 'no budget set');
    };
    $costPer = static function (string $what, float $cost, float $units) use ($add): void {
        $add('Cost per ' . $what, $units > 0 ? fabo_dash_cash($cost / $units) : '—', '',
             $units > 0 ? '' : 'no ' . $what . 's yet');
    };
    $roi = static function (float $rev, float $cost) use ($add): void {
        if ($rev <= 0 && $cost <= 0) { return; }
        $v = $cost > 0 ? round(($rev - $cost) / $cost * 100, 1) : null;
        $add('ROI', $v === null ? '—' : $v . '%', $v === null ? '' : ($v >= 0 ? 'good' : 'risk'),
             fabo_dash_cash($rev) . ' revenue');
    };

    /* ---- what each sheet is judged on -------------------------------- */
    switch ($key) {
        case 'master':
            $work('Activities');
            $onTimeTile();
            $spend('budget_allocated', 'budget_spent');
            if ($has('leads_generated'))    { $add('Leads', $num($sum('leads_generated'))); }
            if ($has('revenue_attributed')) { $roi($sum('revenue_attributed'), $sum('budget_spent')); }
            break;

        case 'store':
            $liveStores = count(array_filter($rows,
                static fn($r) => fabo_tone('status', $r['status'] ?? '') === 'good'));
            $add('Stores', $num($n), '', $num($liveStores) . ' live');
            if ($has('total_spend')) { $add('Marketing spend', fabo_dash_cash($sum('total_spend'))); }
            if ($has('total_leads')) { $add('Leads / walk-ins', $num($sum('total_leads'))); }
            if ($has('total_spend') && $has('total_leads')) {
                $costPer('walk-in', $sum('total_spend'), $sum('total_leads'));
            }
            if ($has('state')) {
                $add('States covered', $num(count(array_unique(array_filter(array_map(
                    static fn($r) => trim((string) ($r['state'] ?? '')), $rows))))));
            }
            break;

        case 'calendar':
            $next30 = 0; $ready = 0; $gaps = 0;
            $end30  = date('Y-m-d', strtotime($today . ' +30 days'));
            foreach ($rows as $r) {
                $d = (string) ($r['occasion_date'] ?? '');
                if ($d === '' || $d < $today || $d > $end30) { continue; }
                $next30++;
                $t = fabo_tone('status', $r['content_status'] ?? '');
                if ($t === 'good') { $ready++; }
                if ($t === '' || $t === 'muted') { $gaps++; }
            }
            $add('Key dates', $num($n));
            $add('Next 30 days', $num($next30));
            $add('Content ready', $pct($ready, $next30), '', $num($ready) . ' of ' . $num($next30) . ' coming up');
            $add('Not planned yet', $num($gaps), $gaps ? 'risk' : 'good', 'in the next 30 days');
            break;

        case 'content':
            $work('Pieces');
            if ($has('reach_views')) {
                $reach = $sum('reach_views');
                $eng   = $sum('likes') + $sum('comments_count') + $sum('shares');
                $add('Reach', fabo_dash_short($reach));
                $add('Engagement rate', $pct($eng, $reach), '', fabo_dash_short($eng) . ' interactions');
            }
            break;

        case 'website':
            $work('Tasks');
            if ($has('organic_traffic')) {
                $add('Organic traffic', fabo_dash_short($sum('organic_traffic')), '', 'monthly, all pages');
            }
            if ($has('current_position')) {
                $top10 = 0; $ranked = 0;
                foreach ($rows as $r) {
                    $p = (int) ($r['current_position'] ?? 0);
                    if ($p > 0) { $ranked++; if ($p <= 10) { $top10++; } }
                }
                $add('Ranking in top 10', $num($top10), '', 'of ' . $num($ranked) . ' tracked');
            }
            break;

        case 'design':
            $work('Requests');
            $onTimeTile();
            if ($has('revisions') && $n) {
                $avg = $sum('revisions') / $n;
                $add('Avg. revisions', (string) round($avg, 1), $avg > 2 ? 'warn' : '', 'per request');
            }
            break;

        case 'campaigns':
            $running = count(array_filter($rows,
                static fn($r) => ($r['status'] ?? '') === FABO_STATUS_IN_PROGRESS));
            $add('Campaigns', $num($n), '', $num($running) . ' running');
            $spend('budget_allocated', 'budget_spent');
            if ($has('leads_generated')) {
                $leads = $sum('leads_generated');
                $add('Leads', $num($leads), '', $has('conversions')
                    ? $num($sum('conversions')) . ' converted · ' . $pct($sum('conversions'), $leads) : '');
                $costPer('lead', $sum('budget_spent'), $leads);
            }
            if ($has('revenue_attributed')) { $roi($sum('revenue_attributed'), $sum('budget_spent')); }
            if ($has('impressions') && $has('clicks')) {
                $add('CTR', $pct($sum('clicks'), $sum('impressions')), '',
                     fabo_dash_short($sum('impressions')) . ' impressions');
            }
            break;

        case 'influencers':
            $add('Influencers', $num($n));
            $paid   = $sum('amount_paid');
            $agreed = $sum('agreed_budget');
            $add('Paid', fabo_dash_cash($paid), $agreed > 0 && $paid > $agreed ? 'risk' : '',
                 $agreed > 0 ? $pct($paid, $agreed) . ' of ' . fabo_dash_cash($agreed) . ' agreed' : '');
            if ($has('reach')) {
                $add('Reach', fabo_dash_short($sum('reach')), '',
                     $has('engagement') ? $pct($sum('engagement'), $sum('reach')) . ' engaged' : '');
            }
            if ($has('leads_clicks')) {
                $add('Leads / clicks', $num($sum('leads_clicks')));
                $costPer('lead', $paid, $sum('leads_clicks'));
            }
            if ($has('revenue_attributed')) { $roi($sum('revenue_attributed'), $paid); }
            break;

        case 'btl':
            $work('Activities');
            $spend('budget_allocated', 'budget_spent');
            if ($has('attendees')) { $add('Footfall', $num($sum('attendees'))); }
            if ($has('leads_walkins')) {
                $add('Leads / walk-ins', $num($sum('leads_walkins')), '',
                     $has('attendees') ? $pct($sum('leads_walkins'), $sum('attendees')) . ' of footfall' : '');
                $costPer('walk-in', $sum('budget_spent'), $sum('leads_walkins'));
            }
            if ($has('feedback_score')) {
                $scored = array_filter($rows, static fn($r) => ($r['feedback_score'] ?? null) !== null
                                                            && $r['feedback_score'] !== '');
                if ($scored) {
                    $avg = array_sum(array_map(static fn($r) => (float) $r['feedback_score'], $scored))
                         / count($scored);
                    $add('Feedback', round($avg, 1) . ' / 5', $avg >= 4 ? 'good' : ($avg < 3 ? 'risk' : ''),
                         count($scored) . ' rated');
                }
            }
            break;

        case 'events':
            $work('Programmes');
            $spend('budget_allocated', 'budget_spent');
            if ($has('achieved_participants')) {
                $add('Participants', $num($sum('achieved_participants')), '',
                     $has('expected_participants')
                         ? $pct($sum('achieved_participants'), $sum('expected_participants'))
                           . ' of ' . $num($sum('expected_participants')) . ' expected' : '');
            }
            if ($has('leads_generated')) { $add('Leads', $num($sum('leads_generated'))); }
            break;

        case 'requisitions':
            $work('Requests');
            $onTimeTile();
            if ($has('turnaround_hours')) {
                $tt = array_filter($rows, static fn($r) => (int) ($r['turnaround_hours'] ?? 0) > 0);
                if ($tt) {
                    $add('Avg. turnaround', round(array_sum(array_map(
                        static fn($r) => (int) $r['turnaround_hours'], $tt)) / count($tt)) . ' hrs');
                }
            }
            if ($has('extended_count')) {
                $ext = count(array_filter($rows, static fn($r) => (int) ($r['extended_count'] ?? 0) > 0));
                $add('Extended', $num($ext), $ext ? 'warn' : 'good', 'requests whose date moved');
            }
            break;

        default:
            // Any other sheet, including the ones built in the portal.
            $work('Entries');
            $shown = 0;
            foreach (fabo_dash_cols($sheet, ['money']) as $f => $l) {
                if (in_array($f, ['cost_per_lead', 'budget_remaining'], true)) { continue; }
                $add($l, fabo_dash_cash($sum($f)));
                if (++$shown >= 2) { break; }
            }
    }

    // Sign-off, where the sheet carries the portal's approval trail.
    if ($has('approval_state') && count($T) < 8) {
        $ap = ['Approved' => 0, 'Rejected' => 0, 'Pending' => 0];
        foreach ($rows as $r) {
            $v = (string) ($r['approval_state'] ?? '');
            foreach ($ap as $k => $_) { if (stripos($v, $k) === 0) { $ap[$k]++; } }
        }
        if (array_sum($ap) > 0) {
            $add('Awaiting approval', $num($ap['Pending']), $ap['Pending'] ? 'warn' : '',
                 $ap['Approved'] . ' approved · ' . $ap['Rejected'] . ' rejected');
        }
    }

    /* ---- three pictures at most: the state of the work, where the effort
            goes, and the trend ------------------------------------------- */
    $charts = [];
    $count = static function (string $field) use ($rows): array {
        $c = [];
        foreach ($rows as $r) {
            $v = trim((string) ($r[$field] ?? ''));
            $v = $v === '' ? '(not set)' : $v;
            $c[$v] = ($c[$v] ?? 0) + 1;
        }
        arsort($c);
        return $c;
    };
    $total = static function (string $by, string $f) use ($rows): array {
        $c = [];
        foreach ($rows as $r) {
            $v = trim((string) ($r[$by] ?? ''));
            $v = $v === '' ? '(not set)' : $v;
            $c[$v] = ($c[$v] ?? 0) + (float) ($r[$f] ?? 0);
        }
        $c = array_filter($c, static fn($x) => $x != 0);
        arsort($c);
        return $c;
    };

    if ($stat !== '') {
        $charts[] = ['title' => 'By ' . mb_strtolower($label($stat, 'status')), 'type' => 'bar',
                     'data' => $count($stat), 'tones' => 'status'];
    }

    // Where the money or the results go: the sheet's own key measure.
    $focus = [
        'master'       => ['function_name', 'budget_spent',  'Spend by'],
        'store'        => ['state',         'total_spend',   'Spend by'],
        'calendar'     => ['category',      '',              'By'],
        'content'      => ['platform',      'reach_views',   'Reach by'],
        'website'      => ['category',      '',              'By'],
        'design'       => ['design_type',   '',              'By'],
        'campaigns'    => ['platform',      'budget_spent',  'Spend by'],
        'influencers'  => ['platform',      'reach',         'Reach by'],
        'btl'          => ['activity_type', 'leads_walkins', 'Leads by'],
        'events'       => ['event_type',    '',              'By'],
        'requisitions' => ['req_type',      '',              'By'],
    ][$key] ?? null;
    if ($focus && $has($focus[0])) {
        [$by, $f, $word] = $focus;
        $data = ($f !== '' && $has($f)) ? $total($by, $f) : [];
        if ($data) {
            $charts[] = ['title' => $word . ' ' . mb_strtolower($label($by, $by)), 'type' => 'bar',
                         'data' => $data, 'tones' => 'series',
                         'fmt' => fabo_col_render(fabo_dash_col($sheet, $f))];
        } else {
            $charts[] = ['title' => 'By ' . mb_strtolower($label($by, $by)), 'type' => 'bar',
                         'data' => $count($by), 'tones' => 'series'];
        }
    } elseif (!empty($sheet['brand']) && $has($sheet['brand'])) {
        $charts[] = ['title' => 'By brand', 'type' => 'bar', 'data' => $count($sheet['brand']),
                     'tones' => 'brand'];
    }

    // The trend, on the sheet's own date: entries per month, in date order.
    if (!empty($sheet['date_driver']) && $has($sheet['date_driver'])) {
        $by = [];
        foreach ($rows as $r) {
            $d = (string) ($r[$sheet['date_driver']] ?? '');
            if ($d === '' || !strtotime($d)) { continue; }
            $m = date('Y-m', strtotime($d));
            $by[$m] = ($by[$m] ?? 0) + 1;
        }
        ksort($by);
        if (count($by) > 1) {
            $out = [];
            foreach ($by as $m => $v) { $out[date('M y', strtotime($m . '-01'))] = $v; }
            $charts[] = ['title' => 'Per month — by ' . mb_strtolower($label($sheet['date_driver'])),
                         'type' => 'trend', 'data' => $out];
        }
    }

    return ['tiles' => array_slice($T, 0, 8), 'charts' => array_slice($charts, 0, 3), 'count' => $n];
}

/** One column's definition by field, or null. */
function fabo_dash_col(array $sheet, string $field): ?array
{
    foreach ($sheet['columns'] as $c) {
        if ($c[1] === $field) { return $c; }
    }
    return null;
}

/** The column a row is due by, for "overdue". */
function fabo_dash_due_field(string $key, array $sheet): string
{
    $by = ['master' => 'due_date', 'website' => 'due_date', 'design' => 'due_date',
           'content' => 'planned_publish_date', 'campaigns' => 'end_date',
           'influencers' => 'content_due_date', 'btl' => 'activity_date',
           'events' => 'end_date', 'requisitions' => 'promised_due'];
    $f = $by[$key] ?? '';
    if ($f !== '' && fabo_dash_has($sheet, $f)) {
        return $f;
    }
    if (isset($by[$key]) || in_array($key, ['store', 'calendar', 'webcontent', 'lists'], true)) {
        return '';                  // a shipped sheet with no deadline has no "overdue"
    }
    foreach (['due_date', 'deadline', 'end_date'] as $g) {
        if (fabo_dash_has($sheet, $g)) { return $g; }
    }
    return '';
}

/** The column holding the day the work was actually finished, for "on time". */
function fabo_dash_done_date_field(array $sheet): string
{
    foreach (['completion_date', 'delivered_on', 'actual_publish_date', 'content_live_date'] as $f) {
        if (fabo_dash_has($sheet, $f)) { return $f; }
    }
    return '';
}

/** Finished work. Cancelled is not finished, and Approved is not published. */
function fabo_dash_is_done(string $key, string $status, array $row): bool
{
    if ($key === 'content' && !empty($row['actual_publish_date'])) {
        return true;
    }
    return (bool) preg_match('/^(completed?|done|delivered|published(\/live)?|live)$/i', trim($status));
}

function fabo_dash_is_cancelled(string $status): bool
{
    return (bool) preg_match('/cancel|reject|withdrawn|dropped/i', $status);
}

/** Money for a tile, the Indian way: ₹4.2 L, ₹1.3 Cr. */
function fabo_dash_cash(float $v): string
{
    $a = abs($v);
    $s = $v < 0 ? '-' : '';
    if ($a >= 1e7) { return $s . '₹' . rtrim(rtrim(number_format($a / 1e7, 2), '0'), '.') . ' Cr'; }
    if ($a >= 1e5) { return $s . '₹' . rtrim(rtrim(number_format($a / 1e5, 2), '0'), '.') . ' L'; }
    return fabo_money($v);
}

/** A big count, short: 12.4K, 3.1M. */
function fabo_dash_short(float $v): string
{
    if ($v >= 1e6) { return rtrim(rtrim(number_format($v / 1e6, 1), '0'), '.') . 'M'; }
    if ($v >= 1e4) { return rtrim(rtrim(number_format($v / 1e3, 1), '0'), '.') . 'K'; }
    return number_format($v);
}

/* ------------------------------------------------------------ drawing */

/** Literal colours — CSS variables do not survive being saved as an image. */
function fabo_dash_ink(): array
{
    return [
        'surface' => '#ffffff', 'panel' => '#f7f9fc', 'line' => '#dde2ea',
        'ink' => '#1b2230', 'soft' => '#5f6979', 'mute' => '#8a93a3',
        'good' => '#1a7f44', 'goodbg' => '#e9f6ee',
        'risk' => '#b3261e', 'riskbg' => '#fdeceb',
        'warn' => '#a76a00', 'warnbg' => '#fff4e0',
        'info' => '#1e4f9c', 'infobg' => '#eaf1fd',
    ];
}

function fabo_dash_tone_colour(string $tones, string $label, int $i): string
{
    if ($tones === 'status') {
        $t = fabo_tone('status', $label);
        $map = ['good' => '#1a7f44', 'info' => '#2a78d6', 'warn' => '#eda100',
                'risk' => '#d03b3b', 'muted' => '#98a1ae'];
        return $map[$t] ?? '#98a1ae';
    }
    if ($tones === 'brand') {
        $c = fabo_brand_color($label);
        return $c === FABO_NEUTRAL ? '#98a1ae' : $c;
    }
    return FABO_SERIES[$i % count(FABO_SERIES)];
}

function fabo_svg_text(float $x, float $y, string $t, float $size, string $fill,
                       string $anchor = 'start', int $weight = 400): string
{
    return '<text x="' . round($x, 1) . '" y="' . round($y, 1) . '" font-size="' . $size
         . '" fill="' . $fill . '" text-anchor="' . $anchor . '" font-weight="' . $weight
         . '" font-family="system-ui,Segoe UI,Roboto,sans-serif">' . e($t) . '</text>';
}

/** One horizontal bar block, returns [svg, heightUsed]. */
function fabo_dash_bars(float $x, float $y, float $w, array $chart, array $ink): array
{
    $rows = array_slice($chart['data'], 0, 6, true);
    $max  = $rows ? max($rows) : 1;
    $svg  = fabo_svg_text($x, $y + 12, $chart['title'], 12, $ink['ink'], 'start', 600);
    $ry   = $y + 28;
    $i    = 0;
    foreach ($rows as $label => $n) {
        $bw = $max > 0 ? ($w - 178) * ($n / $max) : 0;
        $col = fabo_dash_tone_colour($chart['tones'] ?? 'series', (string) $label, $i);
        $svg .= fabo_svg_text($x, $ry + 9, fabo_trim_label((string) $label, 18), 11, $ink['soft']);
        $svg .= '<rect x="' . ($x + 118) . '" y="' . $ry . '" width="' . max(2, round($bw, 1))
              . '" height="12" rx="3" fill="' . $col . '"/>';
        $shown = match ($chart['fmt'] ?? '') {
            'money' => fabo_dash_cash((float) $n),
            'num'   => fabo_dash_short((float) $n),
            default => (string) $n,
        };
        $svg .= fabo_svg_text($x + $w, $ry + 9, $shown, 11, $ink['ink'], 'end', 600);
        $ry += 19;
        $i++;
    }
    if (count($chart['data']) > 6) {
        $svg .= fabo_svg_text($x, $ry + 8, '+' . (count($chart['data']) - 6) . ' more', 10, $ink['mute']);
        $ry += 16;
    }
    return [$svg, $ry - $y + 6];
}

/** A small column trend, returns [svg, heightUsed]. */
function fabo_dash_trend(float $x, float $y, float $w, array $chart, array $ink): array
{
    $rows = array_slice($chart['data'], -8, 8, true);
    $max  = $rows ? max($rows) : 1;
    $svg  = fabo_svg_text($x, $y + 12, $chart['title'], 12, $ink['ink'], 'start', 600);
    $top  = $y + 26;
    $h    = 74;
    $n    = max(1, count($rows));
    $slot = $w / $n;
    $bw   = min(30, $slot * 0.6);
    $i    = 0;
    foreach ($rows as $label => $v) {
        $bh = $max > 0 ? $h * ($v / $max) : 0;
        $bx = $x + $slot * $i + ($slot - $bw) / 2;
        $by = $top + $h - $bh;
        $svg .= '<rect x="' . round($bx, 1) . '" y="' . round($by, 1) . '" width="' . round($bw, 1)
              . '" height="' . max(2, round($bh, 1)) . '" rx="3" fill="#2a78d6"/>';
        $svg .= fabo_svg_text($bx + $bw / 2, $by - 4, (string) $v, 9.5, $ink['soft'], 'middle', 600);
        $svg .= fabo_svg_text($bx + $bw / 2, $top + $h + 13, (string) $label, 9, $ink['mute'], 'middle');
        $i++;
    }
    $svg .= '<line x1="' . $x . '" y1="' . ($top + $h) . '" x2="' . ($x + $w) . '" y2="' . ($top + $h)
          . '" stroke="' . $ink['line'] . '" stroke-width="1"/>';
    return [$svg, $h + 48];
}

/**
 * Renders the sheet's dashboard panel: one SVG plus a download button.
 */
function fabo_sheet_dashboard(string $key, array $sheet, array $rows, array $viewer): void
{
    $m   = fabo_sheet_metrics($key, $sheet, $rows);
    $ink = fabo_dash_ink();

    // Four tiles to a row, each with a line underneath saying what the
    // figure is out of: a CEO reads "62% of budget" faster than two totals.
    $W   = 980;
    $pad = 16;

    $perRow   = 4;
    $tileW    = ($W - $pad * 2 - 10 * ($perRow - 1)) / $perRow;
    $tileH    = 66;
    $tileRows = (int) ceil(count($m['tiles']) / $perRow);

    $body = '';
    $y    = 54;
    foreach ($m['tiles'] as $i => $t) {
        [$label, $value, $tone] = $t;
        $note = (string) ($t[3] ?? '');
        $col = $i % $perRow;
        $row = intdiv($i, $perRow);
        $tx  = $pad + $col * ($tileW + 10);
        $ty  = $y + $row * ($tileH + 8);
        $bg  = ['good' => $ink['goodbg'], 'risk' => $ink['riskbg'], 'warn' => $ink['warnbg']][$tone] ?? $ink['panel'];
        $fg  = ['good' => $ink['good'],   'risk' => $ink['risk'],   'warn' => $ink['warn']][$tone]   ?? $ink['ink'];
        $body .= '<rect x="' . $tx . '" y="' . $ty . '" width="' . round($tileW, 1) . '" height="' . $tileH
               . '" rx="8" fill="' . $bg . '" stroke="' . $ink['line'] . '"/>';
        $body .= fabo_svg_text($tx + 11, $ty + 18, mb_strtoupper($label), 9, $ink['soft'], 'start', 600);
        $body .= fabo_svg_text($tx + 11, $ty + 42, $value, 19, $fg, 'start', 700);
        if ($note !== '') {
            $body .= fabo_svg_text($tx + 11, $ty + 58, fabo_trim_label($note, 38), 10, $ink['soft']);
        }
    }
    $y += $tileRows * ($tileH + 8) + 10;

    // Charts: three columns rather than two, so the panel is wide not tall.
    $colW = ($W - $pad * 2 - 20 * 2) / 3;
    $left = $y; $right = $y;
    $colY = [$y, $y, $y];
    foreach ($m['charts'] as $i => $chart) {
        // Into whichever column is currently shortest, so three uneven
        // charts do not leave one column trailing far below the others.
        $col = array_search(min($colY), $colY, true);
        $cx  = $pad + $col * ($colW + 20);
        [$svg, $used] = $chart['type'] === 'trend'
            ? fabo_dash_trend($cx, $colY[$col], $colW, $chart, $ink)
            : fabo_dash_bars($cx, $colY[$col], $colW, $chart, $ink);
        $body .= $svg;
        $colY[$col] += $used + 14;
    }
    $H = max($colY) + 6;

    $head = '<rect x="0" y="0" width="' . $W . '" height="' . $H . '" fill="' . $ink['surface'] . '"/>'
          . fabo_svg_text($pad, 26, mb_strtoupper($sheet['title']) . ' — DASHBOARD', 13, $ink['ink'], 'start', 700)
          . fabo_svg_text($pad, 42, $m['count'] . ' row' . ($m['count'] === 1 ? '' : 's')
              . '  ·  as of ' . date('d M Y')
              . (($viewer['brand'] ?? 'both') !== 'both' ? '  ·  ' . $viewer['brand'] : ''), 11, $ink['soft']);

    $file = preg_replace('/[^A-Za-z0-9]+/', '-', $sheet['label']) . '-dashboard-' . date('Y-m-d');
    ?>
  <div class="block sheetdash" style="margin-top:22px">
    <div class="calhead">
      <h2 style="margin:0;flex:1">Key figures</h2>
      <button class="btn btn-sm" id="dashpng" data-file="<?= e($file) ?>">&#128247; Download as image (PNG)</button>
    </div>
    <div class="chartcard" style="overflow:auto">
      <svg id="sheetdashsvg" xmlns="http://www.w3.org/2000/svg"
           viewBox="0 0 <?= $W ?> <?= round($H) ?>" width="<?= $W ?>" height="<?= round($H) ?>"
           style="max-width:100%;height:auto"><?= $head . $body ?></svg>
    </div>
    <div class="hint" style="margin-top:6px">The key figures for this sheet, worked out from the rows shown above. The image saves exactly what you see.</div>
  </div>
<?php
}

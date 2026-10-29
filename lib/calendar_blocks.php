<?php
/**
 * Social Media Calendar: the Monthly and Quarterly rollup tables that turn the
 * festival list into campaign-planning themes.
 */

require_once __DIR__ . '/grid.php';

function fabo_calendar_blocks(array $rows): void
{
    if (!$rows) {
        return;
    }

    /* ------------------------------------------------------- monthly */

    $months = [];
    foreach ($rows as $r) {
        $m = (string) ($r['month_label'] ?? '');
        if ($m === '') {
            continue;
        }
        if (!isset($months[$m])) {
            $months[$m] = ['sort' => strtotime('01-' . $m), 'occasions' => [], 'high' => 0, 'total' => 0];
        }
        $months[$m]['occasions'][] = (string) $r['occasion'];
        $months[$m]['total']++;
        if (($r['priority'] ?? '') === 'High') {
            $months[$m]['high']++;
        }
    }
    uasort($months, static fn($a, $b) => $a['sort'] <=> $b['sort']);

    echo '<div class="block" style="margin-top:24px"><h2>Monthly View — Content Themes</h2>'
       . '<div class="scrollx"><table class="data"><thead><tr>'
       . '<th>Month</th><th>Key Occasions</th><th class="num">High-Priority Count</th>'
       . '<th class="num">Total Occasions</th></tr></thead><tbody>';
    foreach ($months as $m => $v) {
        echo '<tr><td>' . e($m) . '</td>'
           . '<td style="white-space:normal">' . e(implode(', ', $v['occasions'])) . '</td>'
           . '<td class="num">' . (int) $v['high'] . '</td>'
           . '<td class="num">' . (int) $v['total'] . '</td></tr>';
    }
    echo '</tbody></table></div></div>';

    /* ----------------------------------------------------- quarterly */

    $quarters = [];
    foreach ($rows as $r) {
        $q = (string) ($r['quarter_label'] ?? '');
        if ($q === '') {
            continue;
        }
        if (!isset($quarters[$q])) {
            $quarters[$q] = ['high' => 0, 'total' => 0, 'occasions' => []];
        }
        $quarters[$q]['total']++;
        if (($r['priority'] ?? '') === 'High') {
            $quarters[$q]['high']++;
            $quarters[$q]['occasions'][] = (string) $r['occasion'];
        }
    }
    ksort($quarters);

    echo '<div class="block"><h2>Quarterly View — Campaign Planning</h2>'
       . '<div class="scrollx"><table class="data"><thead><tr>'
       . '<th>Quarter</th><th class="num">High-Priority Count</th><th class="num">Total Occasions</th>'
       . '<th>High-Priority Occasions</th></tr></thead><tbody>';
    foreach ($quarters as $q => $v) {
        echo '<tr><td>' . e($q) . '</td>'
           . '<td class="num">' . (int) $v['high'] . '</td>'
           . '<td class="num">' . (int) $v['total'] . '</td>'
           . '<td style="white-space:normal">' . e(implode(', ', $v['occasions'])) . '</td></tr>';
    }
    echo '</tbody></table></div></div>';
}

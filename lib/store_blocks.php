<?php
/**
 * Store Tracker: Store Summary, By State, By Store Type, New Store Launch
 * Readiness, and the three charts. Everything reads the store rows' computed
 * roll-ups, so adding a store with a new Match Keyword needs no code change.
 */

require_once __DIR__ . '/grid.php';
require_once __DIR__ . '/charts.php';
require_once __DIR__ . '/rules.php';

/**
 * First matching status from a source sheet, the way the workbook's
 * INDEX/MATCH readiness formulas resolve it.
 */
function fabo_first_status(array $rows, string $typeField, string $typeValue,
                           string $locField, string $keyword, string $brand): string
{
    foreach ($rows as $r) {
        if (($r['brand'] ?? '') !== $brand) {
            continue;
        }
        if (($r[$typeField] ?? '') !== $typeValue) {
            continue;
        }
        if (!fabo_kw_hit($r[$locField] ?? '', $keyword)) {
            continue;
        }
        return (string) ($r['status'] ?? '');
    }
    return FABO_STATUS_NOT_STARTED;
}

function fabo_store_blocks(array $viewer, array $rows): void
{
    $today = fabo_today();

    /* --------------------------------------------------- summary tiles */

    $types = fabo_store_types();

    // Tiles are generated from the Lists values: a new store type adds its own
    // tiles without touching this file. Stores are no longer split by brand.
    $tiles = ['Stores' => 0];
    foreach ($types as $t) {
        $tiles[$t . 's'] = 0;
    }
    foreach ($types as $t) {
        $tiles[$t . ' Marketing Spend'] = 0.0;
        $tiles[$t . ' Leads/Walk-ins']  = 0;
    }

    foreach ($rows as $r) {
        $type = (string) ($r['store_type'] ?? '');
        $tiles['Stores']++;
        if (isset($tiles[$type . 's'])) {
            $tiles[$type . 's']++;
            $tiles[$type . ' Marketing Spend'] += (float) $r['total_spend'];
            $tiles[$type . ' Leads/Walk-ins']  += (int)   $r['total_leads'];
        }
    }

    echo '<div class="block" style="margin-top:24px"><h2>Store Summary</h2><div class="tiles">';
    foreach ($tiles as $label => $v) {
        $isMoney = str_contains($label, 'Spend');
        echo '<div class="tile"><div class="k">' . e($label) . '</div><div class="v">'
           . e($isMoney ? fabo_money($v) : fabo_thousands($v)) . '</div></div>';
    }
    echo '</div></div>';

    /* ------------------------------------------- by state / store type */

    /*
     * One set of columns, not one per brand. These tables used to be split
     * FABO / Pristino down the side; the portal serves many clients now and
     * Brand is an ordinary column, so the split went — and took $brands with
     * it, which is what left the three references below pointing at nothing.
     */
    $breakdown = static function (string $title, string $firstCol, string $field, array $keys) use ($rows) {
        echo '<div class="block"><h2>' . e($title) . '</h2><div class="scrollx"><table class="data"><thead><tr>'
           . '<th>' . e($firstCol) . '</th>'
           . '<th class="num">Stores</th>'
           . '<th class="num">Spend (₹)</th>'
           . '<th class="num">Leads</th>'
           . '</tr></thead><tbody>';
        $any = false;
        foreach ($keys as $k) {
            $n = 0; $spend = 0.0; $leads = 0;
            foreach ($rows as $r) {
                if (($r[$field] ?? '') !== $k) {
                    continue;
                }
                $n++;
                $spend += (float) ($r['total_spend'] ?? 0);
                $leads += (int)   ($r['total_leads'] ?? 0);
            }
            if ($n === 0) {
                continue;            // a row of zeroes tells nobody anything
            }
            $any = true;
            echo '<tr><td>' . e($k) . '</td>'
               . '<td class="num">' . $n . '</td>'
               . '<td class="num">' . e(fabo_money($spend)) . '</td>'
               . '<td class="num">' . e(fabo_thousands($leads)) . '</td></tr>';
        }
        if (!$any) {
            echo '<tr><td colspan="4" class="muted">No stores recorded yet.</td></tr>';
        }
        echo '</tbody></table></div></div>';
    };

    $states = fabo_states();
    $breakdown('By State — Store Count, Marketing Spend & Leads', 'State', 'state', $states);
    $breakdown('By Store Type — ' . implode(' vs ', $types), 'Store Type', 'store_type', $types);

    /* ------------------------------------------------ launch readiness */

    $sources = [
        'design'    => fabo_all('SELECT brand, design_type, store_location, status FROM design_tracker'),
        'btl'       => fabo_all('SELECT brand, activity_type, store_location, status FROM btl_activities'),
        'campaigns' => fabo_all('SELECT brand, objective, location, status FROM dm_campaigns'),
    ];
    $checks = fabo_readiness_checks();

    // "New Store" is whichever store type the Lists sheet puts first.
    $newType   = $types[0] ?? '';
    $newStores = array_values(array_filter($rows,
                     static fn($r) => ($r['store_type'] ?? '') === $newType));

    echo '<div class="block"><h2>' . e($newType) . ' Launch Readiness</h2>'
       . '<div class="scrollx"><table class="data"><thead><tr>'
       . '<th>Store Name</th><th>Brand</th><th>State</th><th>City</th><th class="num">Days to Opening</th>';
    foreach (array_keys($checks) as $label) {
        echo '<th>' . e($label) . '</th>';
    }
    echo '<th>Overall Readiness</th></tr></thead><tbody>';

    if (!$newStores) {
        echo '<tr><td colspan="' . (6 + count($checks)) . '" style="color:var(--ink-mute)">No '
           . e($newType) . ' rows on the tracker.</td></tr>';
    }
    foreach ($newStores as $r) {
        $kw    = (string) ($r['match_keyword'] ?? '');
        $brand = (string) ($r['brand'] ?? '');

        $statuses = [];
        foreach ($checks as [$src, $typeField, $typeValue, $locField]) {
            $statuses[] = fabo_first_status($sources[$src], $typeField, $typeValue,
                                            $locField, $kw, $brand);
        }
        $moving = count(array_filter($statuses, static fn($s) =>
                      $s === FABO_STATUS_COMPLETED || $s === FABO_STATUS_IN_PROGRESS));
        $notYet = count(array_filter($statuses, static fn($s) => $s === FABO_STATUS_NOT_STARTED));
        $overall = $moving === count($statuses) ? FABO_READY_ON_TRACK
                 : ($notYet >= 1 ? FABO_READY_AT_RISK : FABO_READY_IN_PROGRESS);

        $days = $r['opening_date']
              ? (int) round((strtotime($r['opening_date']) - strtotime($today)) / 86400) : '';

        echo '<tr>'
           . '<td>' . e($r['store_name']) . '</td>'
           . '<td>' . e($brand) . '</td>'
           . '<td>' . e($r['state']) . '</td>'
           . '<td>' . e($r['city']) . '</td>'
           . '<td class="num">' . e((string) $days) . '</td>';
        foreach ($statuses as $st) {
            echo '<td>' . e($st) . '</td>';
        }
        echo '<td' . ($overall === FABO_READY_AT_RISK ? ' class="rag-red"' : '') . '>'
           . e($overall) . '</td></tr>';
    }
    echo '</tbody></table></div></div>';

    /* ------------------------------------------------------- the charts */

    echo '<div class="block"><h2>Charts</h2><div class="chartgrid">';

    fabo_chart_bar('Total Marketing Spend by Store',
        array_map(static fn($r) => (string) $r['store_name'], $rows),
        [['name' => 'Total Marketing Spend', 'color' => FABO_BLUE,
          'values' => array_map(static fn($r) => (float) $r['total_spend'], $rows)]],
        'money');

    $splitSlices = [];
    foreach ($types as $i => $t) {
        $splitSlices[] = ['label' => $t, 'value' => $tiles[$t . ' Marketing Spend'],
                          'color' => FABO_SERIES[$i % count(FABO_SERIES)]];
    }
    fabo_chart_pie(implode(' vs ', $types) . ' — Spend Split', $splitSlices, 'money');

    // One bar per state, for the same reason: spend is spend, whoever the
    // brand on the row happens to be.
    $shown  = [];
    $values = [];
    foreach ($states as $st) {
        $t = 0.0;
        $n = 0;
        foreach ($rows as $r) {
            if (($r['state'] ?? '') === $st) {
                $t += (float) ($r['total_spend'] ?? 0);
                $n++;
            }
        }
        if ($n > 0) {
            $shown[]  = $st;
            $values[] = $t;
        }
    }
    if ($shown) {
        fabo_chart_bar('Marketing Spend by State', $shown,
                       [['name' => 'Spend', 'color' => 'var(--s1)', 'values' => $values]],
                       'money');
    }

    echo '</div></div>';
}

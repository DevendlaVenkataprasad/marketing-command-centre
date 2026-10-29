<?php
/**
 * Inline-SVG charts. No library, no CDN.
 *
 * The palette is the validated categorical order (see assets/app.css): hues are
 * assigned by slot and never cycled. Every chart here sits beside the table it
 * draws, which is the relief the contrast check asks for, and each carries a
 * legend for two or more series plus a hover tooltip.
 */

require_once __DIR__ . '/ui.php';
require_once __DIR__ . '/rules.php';

/** Compact Indian money for axis ticks: ₹2.5L, ₹1.2Cr. */
function fabo_axis_money(float $v): string
{
    $a = abs($v);
    if ($a >= 10000000) { return '₹' . fabo_trim_zeros(number_format($v / 10000000, 1, '.', '')) . 'Cr'; }
    if ($a >= 100000)   { return '₹' . fabo_trim_zeros(number_format($v / 100000, 1, '.', '')) . 'L'; }
    if ($a >= 1000)     { return '₹' . fabo_trim_zeros(number_format($v / 1000, 1, '.', '')) . 'K'; }
    return '₹' . number_format($v, 0, '.', ',');
}

function fabo_axis_num(float $v): string
{
    // Trim only a fractional tail: a plain rtrim would turn 10 into 1.
    return fabo_trim_zeros(number_format($v, ($v == (float) (int) $v ? 0 : 1), '.', ','));
}

/** Drops a trailing ".0", ".50" and the like, leaving whole numbers alone. */
function fabo_trim_zeros(string $s): string
{
    return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
}

/** A tick step that lands on 1/2/5 × 10^n. */
function fabo_nice_max(float $max, int $ticks = 4): array
{
    if ($max <= 0) {
        return [1.0, 1.0];
    }
    $raw  = $max / $ticks;
    $mag  = pow(10, floor(log10($raw)));
    $norm = $raw / $mag;
    $step = ($norm <= 1 ? 1 : ($norm <= 2 ? 2 : ($norm <= 5 ? 5 : 10))) * $mag;
    return [$step * $ticks, $step];
}

function fabo_legend(array $series): string
{
    if (count($series) < 2) {
        return '';
    }
    $h = '<div class="legend">';
    foreach ($series as $s) {
        $h .= '<span><i style="background:' . e($s['color']) . '"></i>' . e($s['name']) . '</span>';
    }
    return $h . '</div>';
}

/**
 * Grouped (or single-series) vertical bar chart.
 *
 * @param array $cats    category labels along the x axis
 * @param array $series  [['name'=>, 'color'=>, 'values'=>[]], ...]
 * @param string $fmt    'num' | 'money'
 */
function fabo_chart_bar(string $title, array $cats, array $series, string $fmt = 'num'): void
{
    // Kept deliberately small: a sheet shows several of these under a grid,
    // and a chart that fills the screen buries the rows it is describing.
    $W = 340;
    $ml = 42; $mr = 8; $mt = 8;
    $pw = $W - $ml - $mr;

    // Tight category slots would collide, so the labels tilt instead.
    $slotW  = $pw / max(1, count($cats));
    $rotate = $slotW < 54;
    $mb = $rotate ? 62 : 32;
    $H  = 140 + $mb;
    $ph = $H - $mt - $mb;

    $max = 0.0;
    foreach ($series as $s) {
        foreach ($s['values'] as $v) { $max = max($max, (float) $v); }
    }
    [$top, $step] = fabo_nice_max($max);

    $n     = max(1, count($cats));
    $slot  = $pw / $n;
    $band  = $slot * 0.68;
    $sn    = max(1, count($series));
    $gap   = 2;                                   // 2px surface gap between bars
    $bw    = max(3, ($band - $gap * ($sn - 1)) / $sn);

    echo '<div class="chartcard"><h3>' . e($title) . '</h3>';
    echo '<svg viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . e($title) . '">';

    // gridlines + y ticks
    for ($v = 0; $v <= $top + 0.0001; $v += $step) {
        $y = $mt + $ph - ($top > 0 ? $v / $top * $ph : 0);
        echo '<line x1="' . $ml . '" y1="' . round($y, 1) . '" x2="' . ($W - $mr)
           . '" y2="' . round($y, 1) . '" stroke="var(--grid)" stroke-width="1"/>';
        $lab = $fmt === 'money' ? fabo_axis_money($v) : fabo_axis_num($v);
        echo '<text x="' . ($ml - 7) . '" y="' . round($y + 3.5, 1)
           . '" text-anchor="end" font-size="10" fill="var(--ink-axis)">' . e($lab) . '</text>';
    }
    echo '<line x1="' . $ml . '" y1="' . ($mt + $ph) . '" x2="' . ($W - $mr) . '" y2="' . ($mt + $ph)
       . '" stroke="var(--axis)" stroke-width="1"/>';

    foreach ($cats as $i => $cat) {
        $cx = $ml + $slot * $i + $slot / 2;
        foreach ($series as $j => $s) {
            $v = (float) ($s['values'][$i] ?? 0);
            $h = $top > 0 ? $v / $top * $ph : 0;
            if ($h < 0.5 && $v <= 0) { continue; }
            $h = max($h, 1.5);
            $x = $cx - $band / 2 + $j * ($bw + $gap);
            $y = $mt + $ph - $h;
            $r = min(4, $bw / 2, $h);             // 4px rounded data end
            $path = 'M' . round($x, 1) . ' ' . round($mt + $ph, 1)
                  . 'V' . round($y + $r, 1)
                  . 'q0 ' . (-$r) . ' ' . $r . ' ' . (-$r)
                  . 'h' . round($bw - 2 * $r, 1)
                  . 'q' . $r . ' 0 ' . $r . ' ' . $r
                  . 'V' . round($mt + $ph, 1) . 'Z';
            $tip = $cat . ' · ' . $s['name'] . ': '
                 . ($fmt === 'money' ? fabo_money($v) : fabo_thousands($v));
            echo '<path d="' . $path . '" fill="' . e($s['color']) . '" class="mk"><title>'
               . e($tip) . '</title></path>';
        }
        echo fabo_axis_label($cx, $mt + $ph + ($rotate ? 12 : 15), $cat, $rotate);
    }

    echo '</svg>';
    echo fabo_legend($series);
    echo '</div>';
}

/** An x-axis category label, tilted when the slots are too narrow to sit flat. */
function fabo_axis_label(float $x, float $y, string $text, bool $rotate): string
{
    $x = round($x, 1);
    $y = round($y, 1);
    if (!$rotate) {
        return '<text x="' . $x . '" y="' . $y . '" text-anchor="middle" font-size="10"'
             . ' fill="var(--ink-axis)">' . e(fabo_trim_label($text)) . '</text>';
    }
    return '<text x="' . $x . '" y="' . $y . '" text-anchor="end" font-size="10"'
         . ' fill="var(--ink-axis)" transform="rotate(-38 ' . $x . ' ' . $y . ')">'
         . e(fabo_trim_label($text, 16)) . '</text>';
}

/** Multi-series line chart with visible markers. */
function fabo_chart_line(string $title, array $cats, array $series, string $fmt = 'num'): void
{
    $W = 340;
    $ml = 42; $mr = 10; $mt = 8;
    $pw = $W - $ml - $mr;
    $rotate = ($pw / max(1, count($cats))) < 54;
    $mb = $rotate ? 62 : 32;
    $H  = 140 + $mb;
    $ph = $H - $mt - $mb;

    $max = 0.0;
    foreach ($series as $s) {
        foreach ($s['values'] as $v) { $max = max($max, (float) $v); }
    }
    [$top, $step] = fabo_nice_max($max);

    $n = max(1, count($cats));
    $dx = $n > 1 ? $pw / ($n - 1) : 0;

    echo '<div class="chartcard"><h3>' . e($title) . '</h3>';
    echo '<svg viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . e($title) . '">';

    for ($v = 0; $v <= $top + 0.0001; $v += $step) {
        $y = $mt + $ph - ($top > 0 ? $v / $top * $ph : 0);
        echo '<line x1="' . $ml . '" y1="' . round($y, 1) . '" x2="' . ($W - $mr)
           . '" y2="' . round($y, 1) . '" stroke="var(--grid)" stroke-width="1"/>';
        $lab = $fmt === 'money' ? fabo_axis_money($v) : fabo_axis_num($v);
        echo '<text x="' . ($ml - 7) . '" y="' . round($y + 3.5, 1)
           . '" text-anchor="end" font-size="10" fill="var(--ink-axis)">' . e($lab) . '</text>';
    }
    echo '<line x1="' . $ml . '" y1="' . ($mt + $ph) . '" x2="' . ($W - $mr) . '" y2="' . ($mt + $ph)
       . '" stroke="var(--axis)" stroke-width="1"/>';

    foreach ($series as $s) {
        $pts = [];
        foreach ($cats as $i => $cat) {
            $v = (float) ($s['values'][$i] ?? 0);
            $x = $ml + $dx * $i;
            $y = $mt + $ph - ($top > 0 ? $v / $top * $ph : 0);
            $pts[] = round($x, 1) . ',' . round($y, 1);
        }
        echo '<polyline points="' . implode(' ', $pts) . '" fill="none" stroke="'
           . e($s['color']) . '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>';
        foreach ($cats as $i => $cat) {
            $v = (float) ($s['values'][$i] ?? 0);
            $x = $ml + $dx * $i;
            $y = $mt + $ph - ($top > 0 ? $v / $top * $ph : 0);
            $tip = $cat . ' · ' . $s['name'] . ': '
                 . ($fmt === 'money' ? fabo_money($v) : fabo_thousands($v));
            // 2px surface ring keeps overlapping markers readable
            echo '<circle cx="' . round($x, 1) . '" cy="' . round($y, 1) . '" r="4" fill="'
               . e($s['color']) . '" stroke="#fff" stroke-width="2"><title>' . e($tip)
               . '</title></circle>';
        }
    }

    foreach ($cats as $i => $cat) {
        echo fabo_axis_label($ml + $dx * $i, $mt + $ph + ($rotate ? 12 : 15), $cat, $rotate);
    }

    echo '</svg>';
    echo fabo_legend($series);
    echo '</div>';
}

/**
 * Pie. Slices carry a 2px surface gap and are direct-labelled beneath, so
 * identity never rests on colour alone.
 */
function fabo_chart_pie(string $title, array $slices, string $fmt = 'num'): void
{
    $slices = array_values(array_filter($slices, static fn($s) => (float) $s['value'] > 0));
    $total  = 0.0;
    foreach ($slices as $s) { $total += (float) $s['value']; }

    echo '<div class="chartcard"><h3>' . e($title) . '</h3>';
    if ($total <= 0) {
        echo '<div class="empty">Nothing to chart yet.</div></div>';
        return;
    }

    $W = 340; $H = max(150, 24 + count($slices) * 16 + 12);
    $cx = 84; $cy = $H / 2; $r = min(76, $H / 2 - 12);
    echo '<svg viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . e($title) . '">';

    $a0 = -M_PI / 2;
    foreach ($slices as $s) {
        $frac = (float) $s['value'] / $total;
        $a1   = $a0 + $frac * 2 * M_PI;
        $x0 = $cx + $r * cos($a0); $y0 = $cy + $r * sin($a0);
        $x1 = $cx + $r * cos($a1); $y1 = $cy + $r * sin($a1);
        $large = ($a1 - $a0) > M_PI ? 1 : 0;
        $d = $frac >= 0.9999
           ? 'M' . ($cx - $r) . ' ' . $cy . 'a' . $r . ' ' . $r . ' 0 1 0 ' . ($r * 2)
             . ' 0a' . $r . ' ' . $r . ' 0 1 0 ' . (-$r * 2) . ' 0'
           : 'M' . round($cx, 1) . ' ' . round($cy, 1)
             . 'L' . round($x0, 1) . ' ' . round($y0, 1)
             . 'A' . $r . ' ' . $r . ' 0 ' . $large . ' 1 ' . round($x1, 1) . ' ' . round($y1, 1) . 'Z';
        $val = $fmt === 'money' ? fabo_money($s['value']) : fabo_thousands($s['value']);
        echo '<path d="' . $d . '" fill="' . e($s['color']) . '" stroke="#fff" stroke-width="2">'
           . '<title>' . e($s['label'] . ': ' . $val . ' · '
                          . number_format($frac * 100, 1) . '%') . '</title></path>';
        $a0 = $a1;
    }

    // Direct labels to the right of the pie.
    $ly = 30;
    foreach ($slices as $s) {
        $frac = (float) $s['value'] / $total;
        $val  = $fmt === 'money' ? fabo_money($s['value']) : fabo_thousands($s['value']);
        echo '<rect x="176" y="' . ($ly - 8) . '" width="10" height="10" rx="2" fill="'
           . e($s['color']) . '"/>';
        echo '<text x="192" y="' . $ly . '" font-size="11" fill="var(--ink)">'
           . e(fabo_trim_label($s['label'], 18)) . '</text>';
        echo '<text x="' . ($W - 8) . '" y="' . $ly . '" font-size="11" text-anchor="end"'
           . ' fill="var(--ink-soft)">' . e($val . ' · ' . number_format($frac * 100, 1) . '%')
           . '</text>';
        $ly += 19;
        if ($ly > $H - 6) { break; }
    }

    echo '</svg></div>';
}

function fabo_trim_label(string $s, int $max = 14): string
{
    return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
}

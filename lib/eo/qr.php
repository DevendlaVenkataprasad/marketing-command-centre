<?php
/**
 * A QR code as inline SVG, for tickets. It holds the ticket code alone, not a
 * link: 24 characters make a small, coarse code that a phone reads at a glance
 * where a full link made a dense one the scanner often missed. Byte mode, level M,
 * versions 1 to 10 (up to 213 bytes). Kept here
 * rather than as a library because the portal is deployed by copying files.
 */

/** [error-correction codewords per block, [[blocks, data codewords], ...]] for level M. */
function eo_qr_blocks(int $version): array
{
    return [1 => [10, [[1, 16]]], 2 => [16, [[1, 28]]], 3 => [26, [[1, 44]]], 4 => [18, [[2, 32]]], 5 => [24, [[2, 43]]],
            6 => [16, [[4, 27]]], 7 => [18, [[4, 31]]], 8 => [22, [[2, 38], [2, 39]]], 9 => [22, [[3, 36], [2, 37]]],
            10 => [26, [[4, 43], [1, 44]]]][$version];
}

/** The data bytes for $text in the smallest version that holds it: [version, codewords]. */
function eo_qr_codewords(string $text): array
{
    $len = strlen($text);
    for ($v = 1; $v <= 10; $v++) {
        [, $groups] = eo_qr_blocks($v);
        $cap = 0;
        foreach ($groups as [$n, $k]) {
            $cap += $n * $k;
        }
        $countBits = $v < 10 ? 8 : 16;
        if (4 + $countBits + 8 * $len <= 8 * $cap) {
            $bits = '0100' . str_pad(decbin($len), $countBits, '0', STR_PAD_LEFT);
            for ($i = 0; $i < $len; $i++) {
                $bits .= str_pad(decbin(ord($text[$i])), 8, '0', STR_PAD_LEFT);
            }
            $bits .= str_repeat('0', min(4, 8 * $cap - strlen($bits)));
            $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);
            $out = array_map('bindec', str_split($bits, 8));
            for ($pad = 0; count($out) < $cap; $pad ^= 1) {
                $out[] = $pad ? 0x11 : 0xEC;
            }
            return [$v, $out];
        }
    }
    throw new InvalidArgumentException('That is too long for a QR code.');
}

/** Reed-Solomon remainder of $data for $n error-correction codewords. */
function eo_qr_rs(array $data, int $n): array
{
    static $exp = [], $log = [];
    if (!$exp) {
        for ($i = 0, $x = 1; $i < 255; $i++) {
            $exp[$i] = $x;
            $log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D;
            }
        }
    }
    $mul = static fn(int $a, int $b): int => $a && $b ? $exp[($log[$a] + $log[$b]) % 255] : 0;
    $gen = [1];
    for ($i = 0; $i < $n; $i++) {
        $next = array_fill(0, count($gen) + 1, 0);
        foreach ($gen as $j => $c) {
            $next[$j] ^= $c;
            $next[$j + 1] ^= $mul($c, $exp[$i]);
        }
        $gen = $next;
    }
    $rem = array_fill(0, $n, 0);
    foreach ($data as $b) {
        $f = $b ^ array_shift($rem);
        $rem[] = 0;
        for ($i = 0; $i < $n; $i++) {
            $rem[$i] ^= $mul($gen[$i + 1], $f);
        }
    }
    return $rem;
}

/** The modules: a square array of booleans, true for dark. */
function eo_qr_matrix(string $text): array
{
    [$v, $data] = eo_qr_codewords($text);
    [$ecLen, $groups] = eo_qr_blocks($v);

    // Split into blocks, add error correction, interleave.
    $blocks = [];
    $ecs = [];
    $at = 0;
    foreach ($groups as [$n, $k]) {
        for ($i = 0; $i < $n; $i++) {
            $b = array_slice($data, $at, $k);
            $at += $k;
            $blocks[] = $b;
            $ecs[] = eo_qr_rs($b, $ecLen);
        }
    }
    $stream = [];
    $longest = max(array_map('count', $blocks));
    for ($i = 0; $i < $longest; $i++) {
        foreach ($blocks as $b) {
            if (isset($b[$i])) {
                $stream[] = $b[$i];
            }
        }
    }
    for ($i = 0; $i < $ecLen; $i++) {
        foreach ($ecs as $b) {
            $stream[] = $b[$i];
        }
    }

    $size = 17 + 4 * $v;
    $m = array_fill(0, $size, array_fill(0, $size, false));
    $fixed = $m;
    $set = static function (int $r, int $c, bool $dark) use (&$m, &$fixed): void {
        $m[$r][$c] = $dark;
        $fixed[$r][$c] = true;
    };

    // Finder patterns with their separators.
    foreach ([[0, 0], [0, $size - 7], [$size - 7, 0]] as [$r0, $c0]) {
        for ($r = -1; $r <= 7; $r++) {
            for ($c = -1; $c <= 7; $c++) {
                if ($r0 + $r < 0 || $r0 + $r >= $size || $c0 + $c < 0 || $c0 + $c >= $size) {
                    continue;
                }
                $ring = max(abs($r - 3), abs($c - 3));
                $set($r0 + $r, $c0 + $c, $ring !== 2 && $ring !== 4);
            }
        }
    }
    // Alignment patterns.
    $centres = [1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34],
                7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50]][$v];
    foreach ($centres as $r0) {
        foreach ($centres as $c0) {
            if ($fixed[$r0][$c0]) {
                continue;                       // on a finder pattern
            }
            for ($r = -2; $r <= 2; $r++) {
                for ($c = -2; $c <= 2; $c++) {
                    $set($r0 + $r, $c0 + $c, max(abs($r), abs($c)) !== 1);
                }
            }
        }
    }
    // Timing lines, and the one module that is always dark.
    for ($i = 8; $i < $size - 8; $i++) {
        if (!$fixed[6][$i]) {
            $set(6, $i, $i % 2 === 0);
        }
        if (!$fixed[$i][6]) {
            $set($i, 6, $i % 2 === 0);
        }
    }
    $set($size - 8, 8, true);

    // The format modules are reserved now, and written for each mask below.
    eo_qr_format($m, 0);
    for ($r = 0; $r < $size; $r++) {
        for ($c = 0; $c < $size; $c++) {
            if ($r === 8 && ($c <= 8 || $c >= $size - 8) || $c === 8 && ($r <= 8 || $r >= $size - 7)) {
                $fixed[$r][$c] = true;
            }
        }
    }
    // Version information, from version 7.
    if ($v >= 7) {
        $rem = $v;
        for ($i = 0; $i < 12; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
        }
        $info = ($v << 12) | $rem;
        for ($i = 0; $i < 18; $i++) {
            $a = $size - 11 + $i % 3;
            $b = intdiv($i, 3);
            $dark = (($info >> $i) & 1) === 1;
            $set($a, $b, $dark);
            $set($b, $a, $dark);
        }
    }

    // The data, up and down the column pairs from the bottom right.
    $i = 0;
    $total = count($stream) * 8;
    for ($right = $size - 1; $right >= 1; $right -= 2) {
        if ($right === 6) {
            $right = 5;
        }
        for ($vert = 0; $vert < $size; $vert++) {
            for ($j = 0; $j < 2; $j++) {
                $c = $right - $j;
                $upward = (($right + 1) & 2) === 0;
                $r = $upward ? $size - 1 - $vert : $vert;
                if ($fixed[$r][$c]) {
                    continue;
                }
                $m[$r][$c] = $i < $total && (($stream[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                $i++;
            }
        }
    }

    /* All eight masks, keeping the one with the lowest penalty, as the standard
       has it. This is not a nicety: with one fixed mask about one link in
       eighteen drew patterns that real scanners could not read. */
    $best = null;
    $bestScore = PHP_INT_MAX;
    for ($mask = 0; $mask < 8; $mask++) {
        $try = $m;
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if (!$fixed[$r][$c] && eo_qr_flip($mask, $r, $c)) {
                    $try[$r][$c] = !$try[$r][$c];
                }
            }
        }
        eo_qr_format($try, $mask);
        $score = eo_qr_penalty($try);
        if ($score < $bestScore) {
            $bestScore = $score;
            $best = $try;
        }
    }
    return $best;
}

function eo_qr_flip(int $mask, int $r, int $c): bool
{
    switch ($mask) {
        case 0:  return ($r + $c) % 2 === 0;
        case 1:  return $r % 2 === 0;
        case 2:  return $c % 3 === 0;
        case 3:  return ($r + $c) % 3 === 0;
        case 4:  return (intdiv($r, 2) + intdiv($c, 3)) % 2 === 0;
        case 5:  return ($r * $c) % 2 + ($r * $c) % 3 === 0;
        case 6:  return (($r * $c) % 2 + ($r * $c) % 3) % 2 === 0;
        default: return (($r + $c) % 2 + ($r * $c) % 3) % 2 === 0;
    }
}

/** Writes the format information: level M (00) and the mask, BCH-protected, in both places. */
function eo_qr_format(array &$m, int $mask): void
{
    $size = count($m);
    $rem = $mask;
    for ($i = 0; $i < 10; $i++) {
        $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
    }
    $fmt = (($mask << 10) | $rem) ^ 0x5412;
    $bit = static fn(int $i): bool => (($fmt >> $i) & 1) === 1;
    for ($i = 0; $i <= 5; $i++) {
        $m[$i][8] = $bit($i);
    }
    $m[7][8] = $bit(6);
    $m[8][8] = $bit(7);
    $m[8][7] = $bit(8);
    for ($i = 9; $i < 15; $i++) {
        $m[8][14 - $i] = $bit($i);
    }
    for ($i = 0; $i < 8; $i++) {
        $m[8][$size - 1 - $i] = $bit($i);
    }
    for ($i = 8; $i < 15; $i++) {
        $m[$size - 15 + $i][8] = $bit($i);
    }
}

/** The standard's four penalty rules: long runs, blocks, finder look-alikes, dark balance. */
function eo_qr_penalty(array $m): int
{
    $size = count($m);
    $score = 0;
    $dark = 0;
    $lines = [];
    for ($i = 0; $i < $size; $i++) {
        $row = '';
        $col = '';
        for ($j = 0; $j < $size; $j++) {
            $row .= $m[$i][$j] ? '1' : '0';
            $col .= $m[$j][$i] ? '1' : '0';
        }
        $lines[] = $row;
        $lines[] = $col;
        $dark += substr_count($row, '1');
    }
    foreach ($lines as $line) {
        preg_match_all('/0{5,}|1{5,}/', $line, $runs);
        foreach ($runs[0] as $run) {
            $score += strlen($run) - 2;
        }
        // Overlapping matches count, so walk the line rather than use one regex.
        for ($at = 0; ($at = strpos($line, '1011101', $at)) !== false; $at++) {
            if (substr($line, $at + 7, 4) === '0000' || ($at >= 4 && substr($line, $at - 4, 4) === '0000')) {
                $score += 40;
            }
        }
    }
    for ($r = 0; $r < $size - 1; $r++) {
        for ($c = 0; $c < $size - 1; $c++) {
            if ($m[$r][$c] === $m[$r][$c + 1] && $m[$r][$c] === $m[$r + 1][$c] && $m[$r][$c] === $m[$r + 1][$c + 1]) {
                $score += 3;
            }
        }
    }
    return $score + 10 * intdiv(abs(intdiv($dark * 100, $size * $size) - 50), 5);
}

/** The code as an <svg>, with its quiet zone. */
function eo_qr_svg(string $text, int $px = 240): string
{
    $m = eo_qr_matrix($text);
    $size = count($m);
    $path = '';
    foreach ($m as $r => $row) {
        foreach ($row as $c => $dark) {
            if ($dark) {
                $path .= 'M' . ($c + 4) . ' ' . ($r + 4) . 'h1v1h-1z';
            }
        }
    }
    $box = $size + 8;
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $px . '" height="' . $px . '" viewBox="0 0 ' . $box . ' ' . $box
         . '" role="img" aria-label="Ticket QR code" shape-rendering="crispEdges"><rect width="' . $box . '" height="' . $box . '" fill="#fff"/>'
         . '<path d="' . $path . '" fill="#000"/></svg>';
}

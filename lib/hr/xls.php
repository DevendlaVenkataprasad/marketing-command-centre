<?php
/**
 * A reader for old-style Excel files (.xls, BIFF8 inside an OLE2 container),
 * the format most biometric attendance machines export. No library, in the
 * same spirit as the portal's own .xlsx reader and writer.
 *
 * It reads cell values only — text, numbers, dates and the cached results
 * of formulas — from one worksheet. Formatting, merged cells and charts are
 * ignored. Returns rows in the same shape as fabo_crm_read_xlsx():
 *   [sheet names, [[row number, [cell, cell, ...]], ...]]
 * Dates come back as 'Y-m-d', date-times as 'Y-m-d H:i:s', times as 'H:i:s'.
 */

function hr_xls_read(string $path, int $want = 0): array
{
    $data = @file_get_contents($path);
    if ($data === false || strlen($data) < 512 || substr($data, 0, 8) !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
        throw new InvalidArgumentException('That does not look like an Excel 97–2003 (.xls) file.');
    }
    $wb = hr_xls_ole_stream($data, ['Workbook', 'Book']);
    if ($wb === null) {
        throw new InvalidArgumentException('That .xls file has no workbook inside it.');
    }
    return hr_xls_parse($wb, $want);
}

/* --------------------------------------------------------------- OLE2 */

/** One named stream out of an OLE2 compound file, or null. */
function hr_xls_ole_stream(string $d, array $names): ?string
{
    $u16 = static fn(int $o) => unpack('v', substr($d, $o, 2))[1];
    $u32 = static fn(int $o) => unpack('V', substr($d, $o, 4))[1];
    $secSize  = 1 << $u16(0x1E);
    $miniSize = 1 << $u16(0x20);
    $nFat     = $u32(0x2C);
    $dirStart = $u32(0x30);
    $cutoff   = $u32(0x38);
    $miniFatStart = $u32(0x3C);
    $difStart = $u32(0x44);
    $nDif     = $u32(0x48);
    $sector = static fn(int $id) => substr($d, ($id + 1) * $secSize, $secSize);

    // The FAT is listed by the header's DIFAT, then by any DIFAT sectors.
    $fatSecs = [];
    for ($i = 0; $i < 109 && count($fatSecs) < $nFat; $i++) {
        $fatSecs[] = $u32(0x4C + $i * 4);
    }
    $per = intdiv($secSize, 4);
    for ($s = $difStart, $n = 0; $n < $nDif && $s < 0xFFFFFFFA; $n++) {
        $sec = $sector($s);
        for ($i = 0; $i < $per - 1 && count($fatSecs) < $nFat; $i++) {
            $fatSecs[] = unpack('V', substr($sec, $i * 4, 4))[1];
        }
        $s = unpack('V', substr($sec, ($per - 1) * 4, 4))[1];
    }
    $fat = [];
    foreach ($fatSecs as $fs) {
        $fat = array_merge($fat, array_values(unpack('V*', $sector($fs))));
    }
    $chain = static function (int $start, array $table, int $limit = 1000000) {
        $out = [];
        for ($s = $start; $s < 0xFFFFFFFA && isset($table[$s]) && count($out) < $limit; $s = $table[$s]) {
            if (isset($out[$s])) { break; }     // a loop in a damaged file
            $out[$s] = true;
        }
        if ($start < 0xFFFFFFFA && !isset($table[$start])) { $out[$start] = true; }
        return array_keys($out);
    };
    $read = static function (int $start) use ($chain, $fat, $sector) {
        $buf = '';
        foreach ($chain($start, $fat) as $s) { $buf .= $sector($s); }
        return $buf;
    };

    $dir = $read($dirStart);
    $entries = [];
    for ($o = 0; $o + 128 <= strlen($dir); $o += 128) {
        $len = unpack('v', substr($dir, $o + 64, 2))[1];
        $name = $len > 2 ? mb_convert_encoding(substr($dir, $o, $len - 2), 'UTF-8', 'UTF-16LE') : '';
        $entries[] = ['name' => $name, 'type' => ord($dir[$o + 66]),
                      'start' => unpack('V', substr($dir, $o + 116, 4))[1], 'size' => unpack('V', substr($dir, $o + 120, 4))[1]];
    }
    $root = $entries[0] ?? null;
    foreach ($names as $want) {
        foreach ($entries as $e) {
            if ($e['type'] !== 2 || strcasecmp($e['name'], $want) !== 0) {
                continue;
            }
            if ($e['size'] >= $cutoff || !$root) {
                return substr($read($e['start']), 0, $e['size']);
            }
            // Small streams live in the mini stream, addressed through the mini FAT.
            $miniFat = array_values(unpack('V*', $miniFatStart < 0xFFFFFFFA ? $read($miniFatStart) : '') ?: []);
            $ministream = $read($root['start']);
            $buf = '';
            foreach ($chain($e['start'], $miniFat) as $s) { $buf .= substr($ministream, $s * $miniSize, $miniSize); }
            return substr($buf, 0, $e['size']);
        }
    }
    return null;
}

/* --------------------------------------------------------------- BIFF8 */

function hr_xls_parse(string $wb, int $want): array
{
    $len = strlen($wb);
    $records = [];      // [type, offset of data, data]
    for ($p = 0; $p + 4 <= $len;) {
        $h = unpack('vtype/vsize', substr($wb, $p, 4));
        $records[] = [$h['type'], $p, substr($wb, $p + 4, $h['size'])];
        $p += 4 + $h['size'];
    }

    $sheets = [];
    $sst = [];
    $formats = [];
    $xfFmt = [];
    $date1904 = false;
    $n = count($records);
    for ($i = 0; $i < $n; $i++) {
        [$type, $pos, $rec] = $records[$i];
        if ($type === 0x0085) {                       // BOUNDSHEET
            $o = unpack('V', substr($rec, 0, 4))[1];
            $kind = ord($rec[5]);
            [$name] = hr_xls_short_string($rec, 6);
            if ($kind === 0) { $sheets[] = ['name' => $name, 'pos' => $o]; }
        } elseif ($type === 0x041E) {                 // FORMAT
            $id = unpack('v', substr($rec, 0, 2))[1];
            [$code] = hr_xls_long_string([$rec], 2);
            $formats[$id] = $code;
        } elseif ($type === 0x00E0) {                 // XF
            $xfFmt[] = unpack('v', substr($rec, 2, 2))[1];
        } elseif ($type === 0x0022) {                 // DATEMODE
            $date1904 = unpack('v', $rec)[1] === 1;
        } elseif ($type === 0x00FC) {                 // SST, with its CONTINUE records
            $parts = [$rec];
            while ($i + 1 < $n && $records[$i + 1][0] === 0x003C) {
                $parts[] = $records[++$i][2];
            }
            $sst = hr_xls_sst($parts);
        } elseif ($type === 0x000A && $sheets) {
            break;                                    // end of the workbook globals
        }
    }
    if (!$sheets) {
        throw new InvalidArgumentException('That .xls file has no worksheets in it.');
    }
    $want = max(0, min($want, count($sheets) - 1));

    $isDate = static function (int $xf) use ($xfFmt, $formats): ?bool {
        $id = $xfFmt[$xf] ?? 0;
        if (($id >= 14 && $id <= 22) || ($id >= 45 && $id <= 47)) {
            return in_array($id, [18, 19, 20, 21, 45, 46, 47], true) ? true : ($id === 22 ? true : false);
        }
        if (!isset($formats[$id])) {
            return null;
        }
        $code = preg_replace('/"[^"]*"|\[[^\]]*\]|\\\\./', '', $formats[$id]);
        if (!preg_match('/[dmyhs]/i', $code)) {
            return null;
        }
        return (bool) preg_match('/[hs]/i', $code);     // true: has a time part
    };
    $toCell = static function (float $v, int $xf) use ($isDate, $date1904): string {
        $d = $isDate($xf);
        if ($d !== null) {
            $days = floor($v);
            $secs = (int) round(($v - $days) * 86400);
            if ($days <= 0 && $v < 1) {
                return gmdate('H:i:s', $secs);
            }
            $base = $date1904 ? gmmktime(0, 0, 0, 1, 1, 1904) : gmmktime(0, 0, 0, 12, 30, 1899);
            $ts = $base + (int) $days * 86400 + $secs;
            return $d ? gmdate('Y-m-d H:i:s', $ts) : gmdate('Y-m-d', $ts);
        }
        return (floor($v) == $v && abs($v) < 1e15) ? number_format($v, 0, '.', '') : (string) round($v, 10);
    };

    // The worksheet: from its BOF to its EOF.
    $start = $sheets[$want]['pos'];
    $cells = [];
    $pendingFormula = null;
    $begun = false;
    foreach ($records as [$type, $pos, $rec]) {
        if ($pos < $start) {
            continue;
        }
        if ($type === 0x0809) {
            if ($begun) { break; }
            $begun = true;
            continue;
        }
        if ($type === 0x000A) {
            break;
        }
        switch ($type) {
            case 0x00FD:  // LABELSST
                $h = unpack('vrow/vcol/vxf/Vidx', $rec);
                $cells[$h['row']][$h['col']] = (string) ($sst[$h['idx']] ?? '');
                break;
            case 0x0204:  // LABEL
                $h = unpack('vrow/vcol/vxf', $rec);
                [$s] = hr_xls_long_string([substr($rec, 6)], 0);
                $cells[$h['row']][$h['col']] = $s;
                break;
            case 0x0203:  // NUMBER
                $h = unpack('vrow/vcol/vxf', $rec);
                $cells[$h['row']][$h['col']] = $toCell(unpack('e', substr($rec, 6, 8))[1], $h['xf']);
                break;
            case 0x027E:  // RK
                $h = unpack('vrow/vcol/vxf/Vrk', $rec);
                $cells[$h['row']][$h['col']] = $toCell(hr_xls_rk($h['rk']), $h['xf']);
                break;
            case 0x00BD:  // MULRK
                $row = unpack('v', substr($rec, 0, 2))[1];
                $col = unpack('v', substr($rec, 2, 2))[1];
                $cnt = intdiv(strlen($rec) - 6, 6);
                for ($k = 0; $k < $cnt; $k++) {
                    $h = unpack('vxf/Vrk', substr($rec, 4 + $k * 6, 6));
                    $cells[$row][$col + $k] = $toCell(hr_xls_rk($h['rk']), $h['xf']);
                }
                break;
            case 0x0006:  // FORMULA: its cached result
                $h = unpack('vrow/vcol/vxf', $rec);
                $res = substr($rec, 6, 8);
                if (substr($res, 6, 2) === "\xFF\xFF") {
                    $t = ord($res[0]);
                    if ($t === 0) {
                        $pendingFormula = [$h['row'], $h['col']];      // the text follows in a STRING record
                    } elseif ($t === 1) {
                        $cells[$h['row']][$h['col']] = ord($res[2]) ? 'TRUE' : 'FALSE';
                    } else {
                        $cells[$h['row']][$h['col']] = '';
                    }
                } else {
                    $cells[$h['row']][$h['col']] = $toCell(unpack('e', $res)[1], $h['xf']);
                }
                break;
            case 0x0207:  // STRING (a formula's text result)
                if ($pendingFormula) {
                    [$s] = hr_xls_long_string([$rec], 0);
                    $cells[$pendingFormula[0]][$pendingFormula[1]] = $s;
                    $pendingFormula = null;
                }
                break;
            case 0x0205:  // BOOLERR
                $h = unpack('vrow/vcol/vxf', $rec);
                $cells[$h['row']][$h['col']] = ord($rec[7]) === 0 ? (ord($rec[6]) ? 'TRUE' : 'FALSE') : '';
                break;
        }
    }
    ksort($cells);
    $rows = [];
    foreach ($cells as $r => $line) {
        $max = max(array_keys($line));
        $out = [];
        for ($c = 0; $c <= $max; $c++) {
            $out[] = trim((string) ($line[$c] ?? ''));
        }
        $rows[] = [$r + 1, $out];
    }
    return [array_column($sheets, 'name'), $rows];
}

/** An RK number: a compressed integer or double, optionally ÷ 100. */
function hr_xls_rk(int $rk): float
{
    if ($rk & 0x02) {
        $v = $rk >> 2;
        if ($v & 0x20000000) {
            $v -= 0x40000000;      // sign of the 30-bit integer
        }
        $v = (float) $v;
    } else {
        $v = unpack('e', pack('V', 0) . pack('V', $rk & 0xFFFFFFFC))[1];
    }
    return ($rk & 0x01) ? $v / 100 : $v;
}

/** A string with an 8-bit length (sheet names). Returns [text, bytes used]. */
function hr_xls_short_string(string $b, int $o): array
{
    $cch = ord($b[$o]);
    $wide = ord($b[$o + 1]) & 0x01;
    $raw = substr($b, $o + 2, $cch * ($wide ? 2 : 1));
    return [hr_xls_chars($raw, (bool) $wide), 2 + strlen($raw)];
}

/** A string with a 16-bit length, from a single record. Returns [text, bytes used]. */
function hr_xls_long_string(array $parts, int $o): array
{
    $b = $parts[0];
    $cch = unpack('v', substr($b, $o, 2))[1];
    $flags = ord($b[$o + 2]);
    $wide = $flags & 0x01;
    $p = $o + 3;
    $runs = 0;
    $ext = 0;
    if ($flags & 0x08) { $runs = unpack('v', substr($b, $p, 2))[1]; $p += 2; }
    if ($flags & 0x04) { $ext = unpack('V', substr($b, $p, 4))[1]; $p += 4; }
    $raw = substr($b, $p, $cch * ($wide ? 2 : 1));
    return [hr_xls_chars($raw, (bool) $wide), $p - $o + strlen($raw) + $runs * 4 + $ext];
}

function hr_xls_chars(string $raw, bool $wide): string
{
    return $wide ? mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE') : mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
}

/**
 * The shared string table. A string may be split across CONTINUE records;
 * where its characters are split, the next record begins with a fresh flags
 * byte saying whether the rest is 8- or 16-bit.
 */
function hr_xls_sst(array $parts): array
{
    $out = [];
    $pi = 0;
    $b = $parts[0];
    $p = 8;
    $unique = unpack('V', substr($b, 4, 4))[1];
    $need = static function (int $bytes) use (&$pi, &$b, &$p, $parts) {
        if ($p + $bytes > strlen($b) && $p >= strlen($b) && isset($parts[$pi + 1])) {
            $pi++;
            $b = $parts[$pi];
            $p = 0;
        }
    };
    for ($s = 0; $s < $unique; $s++) {
        $need(3);
        if ($p + 3 > strlen($b)) {
            break;
        }
        $cch = unpack('v', substr($b, $p, 2))[1];
        $flags = ord($b[$p + 2]);
        $p += 3;
        $wide = (bool) ($flags & 0x01);
        $runs = 0;
        $ext = 0;
        if ($flags & 0x08) { $need(2); $runs = unpack('v', substr($b, $p, 2))[1]; $p += 2; }
        if ($flags & 0x04) { $need(4); $ext = unpack('V', substr($b, $p, 4))[1]; $p += 4; }
        $text = '';
        $left = $cch;
        while ($left > 0) {
            if ($p >= strlen($b)) {
                if (!isset($parts[$pi + 1])) { break 2; }
                $pi++;
                $b = $parts[$pi];
                $p = 0;
                $wide = (bool) (ord($b[0]) & 0x01);     // the characters carry on with fresh flags
                $p = 1;
            }
            $avail = intdiv(strlen($b) - $p, $wide ? 2 : 1);
            $take = min($left, $avail);
            $text .= hr_xls_chars(substr($b, $p, $take * ($wide ? 2 : 1)), $wide);
            $p += $take * ($wide ? 2 : 1);
            $left -= $take;
        }
        // Formatting runs and phonetic data are skipped, across records if need be.
        $skip = $runs * 4 + $ext;
        while ($skip > 0) {
            if ($p >= strlen($b)) {
                if (!isset($parts[$pi + 1])) { break; }
                $pi++;
                $b = $parts[$pi];
                $p = 0;
            }
            $take = min($skip, strlen($b) - $p);
            $p += $take;
            $skip -= $take;
        }
        $out[] = $text;
    }
    return $out;
}

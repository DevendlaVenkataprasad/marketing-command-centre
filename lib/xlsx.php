<?php
/**
 * A dependency-free multi-worksheet .xlsx writer built on ZipArchive.
 * Inline strings, a bold header row, and numbers stored as numbers.
 */

class FaboXlsx
{
    /** @var array<int, array{name:string, rows:array}> */  
    private array $sheets = [];
    private array $usedNames = [];

    /**
     * @param string $name  worksheet name (sanitised here)
     * @param array  $rows  first row is the header; cells are scalars, or a
     *                      one-key array to force a type:
     *                        ['n' => 1234]         plain number
     *                        ['m' => 1234.5]       rupee-formatted number
     *                        ['p' => 0.85]         percent-formatted number
     *                        ['d' => '2026-09-17'] real Excel date
     */
    public function addSheet(string $name, array $rows): void
    {
        $this->sheets[] = ['name' => $this->safeName($name), 'rows' => $rows];
    }

    public function sheetCount(): int
    {
        return count($this->sheets);
    }

    /** Tab names: 31 chars max, []:*?/\ stripped, duplicates suffixed. */
    private function safeName(string $name): string
    {
        $n = str_replace(['[', ']', ':', '*', '?', '/', '\\'], '', $name);
        $n = trim($n);
        if ($n === '') {
            $n = 'Sheet';
        }
        $n = mb_substr($n, 0, 31);
        $base = $n;
        $i = 2;
        while (isset($this->usedNames[mb_strtolower($n)])) {
            $suffix = ' (' . $i . ')';
            $n = mb_substr($base, 0, 31 - mb_strlen($suffix)) . $suffix;
            $i++;
        }
        $this->usedNames[mb_strtolower($n)] = true;
        return $n;
    }

    /** Excel formula injection guard for text cells. */
    public static function sanitiseText(string $v): string
    {
        // A bare dash (or dashes) is a placeholder, never a formula: "-" stays "-".
        if ($v !== '' && strpbrk($v[0], "=+-@") !== false && !preg_match('/^-+$/', $v)) {
            return "'" . $v;
        }
        return $v;
    }

    private static function xml(string $v): string
    {
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $v);
        return htmlspecialchars($v, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /**
     * Excel serial date: days since 1899-12-30.
     *
     * Counted on the calendar in UTC, never from local timestamps. Subtracting
     * two local timestamps gave a day too few: the server's zone (Asia/Kolkata)
     * was not +05:30 in 1899, so the gap was a little short of whole days and
     * floor() dropped one -- every date in every export read the day before.
     */
    private static function dateSerial(string $d): ?int
    {
        $d = trim($d);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $d, $m)) {
            $t = strtotime($d);             // anything else strtotime can read
            if (!$t) {
                return null;
            }
            [$m[1], $m[2], $m[3]] = explode('-', date('Y-m-d', $t));
        }
        $y = (int) $m[1]; $mo = (int) $m[2]; $dd = (int) $m[3];
        if (!checkdate($mo, $dd, $y)) {
            return null;
        }
        // Whole days between the two dates, both at midnight UTC.
        $utc = new DateTimeZone('UTC');
        $at  = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, $mo, $dd), $utc);
        $one = new DateTimeImmutable('1899-12-30', $utc);
        return (int) $one->diff($at)->format('%r%a');
    }

    private static function colRef(int $i): string
    {
        $s = '';
        for ($n = $i; $n >= 0; $n = intdiv($n, 26) - 1) {
            $s = chr(65 + $n % 26) . $s;
        }
        return $s;
    }

    private function sheetXml(array $rows): string
    {
        $out = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
             . '<sheetData>';
        foreach ($rows as $r => $row) {
            $out .= '<row r="' . ($r + 1) . '">';
            $c = 0;
            foreach ($row as $cell) {
                $ref   = self::colRef($c) . ($r + 1);
                $style = $r === 0 ? ' s="1"' : '';

                $isNum = false;
                $val   = $cell;
                if (is_array($cell)) {
                    foreach (['n' => 0, 'm' => 4, 'p' => 3, 'd' => 2] as $k => $styleIdx) {
                        if (!array_key_exists($k, $cell)) {
                            continue;
                        }
                        $val = $cell[$k];
                        if ($k === 'd') {
                            $val = self::dateSerial((string) $val);
                            $isNum = $val !== null;
                        } else {
                            $isNum = $val !== null && $val !== '' && is_numeric($val);
                        }
                        if ($isNum && $r > 0 && $styleIdx) {
                            $style = ' s="' . $styleIdx . '"';
                        }
                        break;
                    }
                } elseif (is_int($cell) || is_float($cell)) {
                    $isNum = true;
                }

                if ($val === null || $val === '') {
                    $out .= '<c r="' . $ref . '"' . $style . '/>';
                } elseif ($isNum) {
                    $out .= '<c r="' . $ref . '"' . $style . '><v>'
                          . self::xml((string) (0 + $val)) . '</v></c>';
                } else {
                    $out .= '<c r="' . $ref . '"' . $style . ' t="inlineStr"><is><t xml:space="preserve">'
                          . self::xml(self::sanitiseText((string) $val)) . '</t></is></c>';
                }
                $c++;
            }
            $out .= '</row>';
        }
        return $out . '</sheetData></worksheet>';
    }

    /** Streams the workbook to the browser as a download. */
    public function download(string $filename): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'fabo');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the workbook file.');
        }

        $n = count($this->sheets);

        $types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
               . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
               . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
               . '<Default Extension="xml" ContentType="application/xml"/>'
               . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
               . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        for ($i = 1; $i <= $n; $i++) {
            $types .= '<Override PartName="/xl/worksheets/sheet' . $i
                    . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $zip->addFromString('[Content_Types].xml', $types . '</Types>');

        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
          . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
          . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
          . '</Relationships>');

        $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
              . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($this->sheets as $i => $s) {
            $id = $i + 1;
            $wb   .= '<sheet name="' . self::xml($s['name']) . '" sheetId="' . $id . '" r:id="rId' . $id . '"/>';
            $rels .= '<Relationship Id="rId' . $id . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $id . '.xml"/>';
            $zip->addFromString('xl/worksheets/sheet' . $id . '.xml', $this->sheetXml($s['rows']));
        }
        $rels .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
        $zip->addFromString('xl/workbook.xml', $wb . '</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', $rels);

        $zip->addFromString('xl/styles.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
          . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
          . '<numFmts count="2"><numFmt numFmtId="164" formatCode="dd-mmm-yyyy"/>'
          . '<numFmt numFmtId="165" formatCode="&quot;₹&quot;#,##0"/></numFmts>'
          . '<fonts count="2"><font><sz val="11"/><color theme="1"/><name val="Calibri"/></font>'
          . '<font><b/><sz val="11"/><color theme="1"/><name val="Calibri"/></font></fonts>'
          . '<fills count="2"><fill><patternFill patternType="none"/></fill>'
          . '<fill><patternFill patternType="gray125"/></fill></fills>'
          . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
          . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
          . '<cellXfs count="5"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
          . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
          . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
          . '<xf numFmtId="10" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
          . '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>'
          . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
          . '</styleSheet>');

        $zip->close();

        // The name carries an organisation someone typed, so it must never go
        // into the header as-is: a quote would break it and a newline would let
        // a header be injected. A plain ASCII form is offered for old browsers,
        // with the real name alongside it in the RFC 5987 form.
        $ascii = preg_replace('/[^A-Za-z0-9 ._-]+/', ' ', $filename);
        $ascii = trim(preg_replace('/\s+/', ' ', (string) $ascii));
        if ($ascii === '' || !str_ends_with(strtolower($ascii), '.xlsx')) {
            $ascii = ($ascii !== '' ? $ascii . ' ' : '') . 'export.xlsx';
        }
        $utf8 = rawurlencode(str_replace(["\r", "\n"], '', $filename));

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $ascii . '"; '
             . "filename*=UTF-8''" . $utf8);
        header('Content-Length: ' . filesize($tmp));
        header('X-Robots-Tag: noindex, nofollow');
        readfile($tmp);
        unlink($tmp);
        exit;
    }
}

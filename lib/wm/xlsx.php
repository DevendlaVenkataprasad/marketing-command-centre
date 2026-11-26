<?php
/**
 * A small styled .xlsx writer for the Work report (ZipArchive, no library):
 * fills, fonts, wrapped text, date formats, column widths, a frozen header
 * row and an auto-filter. lib/xlsx.php stays the plain writer for every other
 * export; this one exists because the report's formatting is part of the
 * deliverable.
 *
 * A cell is a scalar, or ['v' => value, 's' => style name, 't' => 'n'|'d'|'s'].
 * Styles are named arrays: font (b, color, sz), fill (rgb), fmt ('date'|'0'|'0.00'),
 * wrap, align (left|center|right), valign (top|center), border.
 */

class WmXlsx
{
    private array $sheets = [];
    private array $styles = ['_' => []];
    private array $styleIndex = ['_' => 0];

    public function style(string $name, array $def): void
    {
        $this->styles[$name] = $def;
        $this->styleIndex[$name] = count($this->styleIndex);
    }

    /** $opt: widths (array of numbers), freeze (bool), filter (bool), heights (row index => points). */
    public function addSheet(string $name, array $rows, array $opt = []): void
    {
        $n = str_replace(['[', ']', ':', '*', '?', '/', '\\'], '', $name);
        $this->sheets[] = ['name' => mb_substr($n !== '' ? $n : 'Sheet', 0, 31), 'rows' => $rows, 'opt' => $opt];
    }

    public static function col(int $i): string
    {
        $s = '';
        for ($n = $i; $n >= 0; $n = intdiv($n, 26) - 1) {
            $s = chr(65 + $n % 26) . $s;
        }
        return $s;
    }

    private static function x(string $v): string
    {
        $v = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $v);
        return htmlspecialchars($v, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** Text that would start a formula is kept as text. */
    private static function safe(string $v): string
    {
        return ($v !== '' && strpbrk($v[0], '=+-@') !== false && !preg_match('/^-+$/', $v)
                && !preg_match('/^-?\d/', $v)) ? "'" . $v : $v;
    }

    public static function serial(string $d): ?int
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', trim($d), $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        $utc = new DateTimeZone('UTC');
        $at = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]), $utc);
        return (int) (new DateTimeImmutable('1899-12-30', $utc))->diff($at)->format('%r%a');
    }

    private function sheetXml(array $s): string
    {
        $rows = $s['rows'];
        $opt = $s['opt'];
        $maxC = 0;
        foreach ($rows as $r) {
            $maxC = max($maxC, count($r));
        }
        $o = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
           . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        $last = self::col(max(0, $maxC - 1)) . max(1, count($rows));
        $o .= '<dimension ref="A1:' . $last . '"/>';
        $o .= '<sheetViews><sheetView workbookViewId="0"' . (empty($opt['grid']) ? '' : '') . '>';
        if (!empty($opt['freeze'])) {
            $o .= '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/><selection pane="bottomLeft" activeCell="A2" sqref="A2"/>';
        }
        $o .= '</sheetView></sheetViews><sheetFormatPr defaultRowHeight="15"/>';
        if (!empty($opt['widths'])) {
            $o .= '<cols>';
            foreach (array_values($opt['widths']) as $i => $w) {
                $o .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (float) $w . '" customWidth="1"/>';
            }
            $o .= '</cols>';
        }
        $o .= '<sheetData>';
        foreach ($rows as $ri => $row) {
            $h = $opt['heights'][$ri] ?? null;
            $o .= '<row r="' . ($ri + 1) . '"' . ($h ? ' ht="' . (float) $h . '" customHeight="1"' : '') . '>';
            foreach (array_values($row) as $ci => $cell) {
                $ref = self::col($ci) . ($ri + 1);
                $style = '_';
                $type = null;
                $v = $cell;
                if (is_array($cell)) {
                    $v = $cell['v'] ?? null;
                    $style = $cell['s'] ?? '_';
                    $type = $cell['t'] ?? null;
                }
                $sAttr = ($this->styleIndex[$style] ?? 0) ? ' s="' . $this->styleIndex[$style] . '"' : '';
                if ($type === 'd') {
                    $v = $v === null || $v === '' ? null : self::serial((string) $v);
                    $type = 'n';
                }
                if ($v === null || $v === '') {
                    $o .= '<c r="' . $ref . '"' . $sAttr . '/>';
                } elseif ($type === 'n' || (($type === null) && (is_int($v) || is_float($v)))) {
                    $o .= '<c r="' . $ref . '"' . $sAttr . '><v>' . self::x((string) (0 + $v)) . '</v></c>';
                } else {
                    $o .= '<c r="' . $ref . '"' . $sAttr . ' t="inlineStr"><is><t xml:space="preserve">' . self::x(self::safe((string) $v)) . '</t></is></c>';
                }
            }
            $o .= '</row>';
        }
        $o .= '</sheetData>';
        if (!empty($opt['filter']) && count($rows) > 0) {
            $o .= '<autoFilter ref="A1:' . self::col($maxC - 1) . max(1, count($rows)) . '"/>';
        }
        if (!empty($opt['merge'])) {
            $o .= '<mergeCells count="' . count($opt['merge']) . '">';
            foreach ($opt['merge'] as $m) {
                $o .= '<mergeCell ref="' . self::x($m) . '"/>';
            }
            $o .= '</mergeCells>';
        }
        $o .= '<pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/>';
        if (!empty($opt['landscape'])) {
            $o .= '<pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/>';
        }
        return $o . '</worksheet>';
    }

    private function stylesXml(): string
    {
        $fonts = ['<font><sz val="11"/><color theme="1"/><name val="Calibri"/><family val="2"/></font>'];
        $fills = ['<fill><patternFill patternType="none"/></fill>', '<fill><patternFill patternType="gray125"/></fill>'];
        $borders = ['<border><left/><right/><top/><bottom/><diagonal/></border>',
                    '<border><left style="thin"><color rgb="FFDDE2EA"/></left><right style="thin"><color rgb="FFDDE2EA"/></right>'
                  . '<top style="thin"><color rgb="FFDDE2EA"/></top><bottom style="thin"><color rgb="FFDDE2EA"/></bottom><diagonal/></border>'];
        $fontKey = $fillKey = [];
        $xfs = ['<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'];
        foreach ($this->styles as $name => $d) {
            if ($name === '_') {
                continue;
            }
            $f = $d['font'] ?? [];
            $fk = json_encode($f);
            if (!isset($fontKey[$fk])) {
                $fontKey[$fk] = count($fonts);
                $fonts[] = '<font>' . (!empty($f['b']) ? '<b/>' : '') . (!empty($f['i']) ? '<i/>' : '')
                         . '<sz val="' . (float) ($f['sz'] ?? 11) . '"/>'
                         . (!empty($f['color']) ? '<color rgb="FF' . strtoupper(ltrim($f['color'], '#')) . '"/>' : '<color theme="1"/>')
                         . '<name val="Calibri"/><family val="2"/></font>';
            }
            $fillId = 0;
            if (!empty($d['fill'])) {
                $rgb = strtoupper(ltrim($d['fill'], '#'));
                if (!isset($fillKey[$rgb])) {
                    $fillKey[$rgb] = count($fills);
                    $fills[] = '<fill><patternFill patternType="solid"><fgColor rgb="FF' . $rgb . '"/><bgColor indexed="64"/></patternFill></fill>';
                }
                $fillId = $fillKey[$rgb];
            }
            $fmt = ['date' => 164, '0' => 1, '0.00' => 2, 'pct' => 165][$d['fmt'] ?? ''] ?? 0;
            $align = '';
            if (!empty($d['wrap']) || !empty($d['align']) || !empty($d['valign'])) {
                $align = '<alignment' . (!empty($d['align']) ? ' horizontal="' . $d['align'] . '"' : '')
                       . ' vertical="' . ($d['valign'] ?? 'top') . '"' . (!empty($d['wrap']) ? ' wrapText="1"' : '') . '/>';
            }
            $xfs[] = '<xf numFmtId="' . $fmt . '" fontId="' . $fontKey[$fk] . '" fillId="' . $fillId . '" borderId="' . (!empty($d['border']) ? 1 : 0) . '" xfId="0"'
                   . ($fmt ? ' applyNumberFormat="1"' : '') . ' applyFont="1"' . ($fillId ? ' applyFill="1"' : '')
                   . (!empty($d['border']) ? ' applyBorder="1"' : '') . ($align !== '' ? ' applyAlignment="1">' . $align . '</xf>' : '/>');
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
             . '<numFmts count="2"><numFmt numFmtId="164" formatCode="dd\-mmm\-yyyy"/><numFmt numFmtId="165" formatCode="0&quot;%&quot;"/></numFmts>'
             . '<fonts count="' . count($fonts) . '">' . implode('', $fonts) . '</fonts>'
             . '<fills count="' . count($fills) . '">' . implode('', $fills) . '</fills>'
             . '<borders count="' . count($borders) . '">' . implode('', $borders) . '</borders>'
             . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
             . '<cellXfs count="' . count($xfs) . '">' . implode('', $xfs) . '</cellXfs>'
             . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
             . '</styleSheet>';
    }

    /** Writes the workbook to $path. */
    public function save(string $path): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the workbook file.');
        }
        $n = count($this->sheets);
        $types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
               . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
               . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
               . '<Default Extension="xml" ContentType="application/xml"/>'
               . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
               . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
               . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>';
        for ($i = 1; $i <= $n; $i++) {
            $types .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $zip->addFromString('[Content_Types].xml', $types . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '</Relationships>');
        $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>Work Report</dc:title><dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created></cp:coreProperties>');
        $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        $defined = '';
        foreach ($this->sheets as $i => $s) {
            $id = $i + 1;
            $wb .= '<sheet name="' . self::x($s['name']) . '" sheetId="' . $id . '" r:id="rId' . $id . '"/>';
            $rels .= '<Relationship Id="rId' . $id . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $id . '.xml"/>';
            $zip->addFromString('xl/worksheets/sheet' . $id . '.xml', $this->sheetXml($s));
            if (!empty($s['opt']['filter']) && $s['rows']) {
                $maxC = 0;
                foreach ($s['rows'] as $r) {
                    $maxC = max($maxC, count($r));
                }
                $defined .= '<definedName name="_xlnm._FilterDatabase" localSheetId="' . $i . '" hidden="1">\''
                          . self::x(str_replace("'", "''", $s['name'])) . '\'!$A$1:$' . self::col($maxC - 1) . '$' . count($s['rows']) . '</definedName>';
            }
        }
        $wb .= '</sheets>' . ($defined !== '' ? '<definedNames>' . $defined . '</definedNames>' : '') . '</workbook>';
        $rels .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
        $zip->addFromString('xl/workbook.xml', $wb);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $rels);
        $zip->addFromString('xl/styles.xml', $this->stylesXml());
        $zip->close();
    }

    public function download(string $filename): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'wmx');
        $this->save($tmp);
        $ascii = trim((string) preg_replace('/[^A-Za-z0-9 ._-]+/', '-', $filename));
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
        header('Content-Length: ' . filesize($tmp));
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        readfile($tmp);
        @unlink($tmp);
        exit;
    }
}

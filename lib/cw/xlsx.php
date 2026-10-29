<?php
/**
 * A small .xlsx reader for the Co-working import: every sheet's cell values,
 * shared strings resolved. Formulas come back as the value Excel last
 * calculated. Nothing else (styles, merges) is read.
 *
 * Returns [sheet name => [row number => [column letter => value]]].
 */
function cw_xlsx_read(string $path): array
{
    $z = new ZipArchive();
    if ($z->open($path) !== true) {
        throw new InvalidArgumentException('That file could not be opened as an Excel workbook (.xlsx).');
    }
    $prev = libxml_use_internal_errors(true);
    try {
        $strings = [];
        if (($x = $z->getFromName('xl/sharedStrings.xml')) !== false) {
            foreach (simplexml_load_string($x, 'SimpleXMLElement', LIBXML_NONET)->si as $si) {
                if (isset($si->t)) {
                    $strings[] = (string) $si->t;
                } else {
                    $t = '';
                    foreach ($si->r as $r) {
                        $t .= (string) $r->t;
                    }
                    $strings[] = $t;
                }
            }
        }
        $wb   = simplexml_load_string((string) $z->getFromName('xl/workbook.xml'), 'SimpleXMLElement', LIBXML_NONET);
        $rels = simplexml_load_string((string) $z->getFromName('xl/_rels/workbook.xml.rels'), 'SimpleXMLElement', LIBXML_NONET);
        if (!$wb || !$rels) {
            throw new InvalidArgumentException('That file is not a readable Excel workbook.');
        }
        $target = [];
        foreach ($rels->Relationship as $r) {
            $target[(string) $r['Id']] = (string) $r['Target'];
        }
        $book = [];
        foreach ($wb->sheets->sheet as $sh) {
            $rid  = (string) $sh->attributes('r', true)['id'];
            $file = 'xl/' . ltrim(preg_replace('#^/?xl/#', '', $target[$rid] ?? ''), '/');
            $xml  = $z->getFromName($file);
            if ($xml === false) {
                continue;
            }
            $s = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
            $rows = [];
            foreach ($s->sheetData->row as $row) {
                $cells = [];
                foreach ($row->c as $c) {
                    $t = (string) $c['t'];
                    $v = $t === 'inlineStr' ? (string) $c->is->t : (string) $c->v;
                    if ($t === 's') {
                        $v = $strings[(int) $v] ?? '';
                    }
                    if ($v !== '') {
                        $cells[preg_replace('/\d+/', '', (string) $c['r'])] = $v;
                    }
                }
                $rows[(int) $row['r']] = $cells;
            }
            $book[(string) $sh['name']] = $rows;
        }
        return $book;
    } finally {
        libxml_use_internal_errors($prev);
        $z->close();
    }
}

/** An Excel date (a day number, or typed text such as 20/11/2025) as Y-m-d, or '' when it is not one. */
function cw_xlsx_date($v): string
{
    $v = trim((string) $v);
    if ($v === '') {
        return '';
    }
    if (is_numeric($v) && (float) $v > 20000 && (float) $v < 80000) {
        return date('Y-m-d', (int) round(((float) $v - 25569) * 86400));
    }
    return (string) (cw_date_in($v) ?? '');
}

/** A number Excel stored as a float (a phone number: 9.475654101E9) back as the digits typed. */
function cw_xlsx_text($v): string
{
    $v = trim((string) $v);
    if ($v !== '' && is_numeric($v) && stripos($v, 'e') !== false) {
        return number_format((float) $v, 0, '', '');
    }
    return preg_replace('/\.0$/', '', $v);
}

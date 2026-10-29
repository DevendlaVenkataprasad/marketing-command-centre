<?php
/**
 * A small PDF writer: text in Helvetica, lines, filled boxes, several pages.
 * Enough for a payslip and a tabular report, with no library and nothing
 * fetched at runtime — the same stance as the portal's own .xlsx writer.
 *
 * Coordinates are in points from the TOP-left of the page (A4 is 595 × 842),
 * which is how a layout is thought about; the writer flips them for PDF.
 * Text is converted to Windows-1252, the encoding the built-in fonts carry;
 * the rupee sign is not in it, so amounts are printed with "Rs.".
 */

class HrPdf
{
    private array $pages = [];
    private string $cur = '';
    private float $w;
    private float $h;

    private const HELV = [278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,
        278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,
        667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,
        500,722,500,500,500,334,260,334,584];
    private const HELVB = [278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,
        333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,
        667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,
        556,778,556,556,500,389,280,389,584];

    public function __construct(bool $landscape = false)
    {
        $this->w = $landscape ? 842.0 : 595.0;
        $this->h = $landscape ? 595.0 : 842.0;
    }

    public function width(): float  { return $this->w; }
    public function height(): float { return $this->h; }

    public function addPage(): void
    {
        if ($this->cur !== '' || $this->pages) {
            $this->pages[] = $this->cur;
        }
        $this->cur = '';
    }

    /** Text as the built-in fonts can print it. */
    public static function enc(string $s): string
    {
        $s = str_replace(['₹', "\u{2009}"], ['Rs.', ' '], $s);
        $out = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $s);
        if ($out === false) {
            $out = preg_replace('/[^\x20-\x7E]/', '?', $s) ?? '';
        }
        return $out;
    }

    public function textWidth(string $s, float $size, bool $bold = false): float
    {
        $tbl = $bold ? self::HELVB : self::HELV;
        $w = 0;
        $enc = self::enc($s);
        for ($i = 0, $n = strlen($enc); $i < $n; $i++) {
            $c = ord($enc[$i]);
            $w += ($c >= 32 && $c <= 126) ? $tbl[$c - 32] : ($c === 0x97 ? 1000 : 556);
        }
        return $w * $size / 1000;
    }

    /** Cuts text to fit a width, ending in "…" (as "...") when it had to. */
    public function fit(string $s, float $maxW, float $size, bool $bold = false): string
    {
        if ($this->textWidth($s, $size, $bold) <= $maxW) {
            return $s;
        }
        while (mb_strlen($s) > 1 && $this->textWidth($s . '...', $size, $bold) > $maxW) {
            $s = mb_substr($s, 0, -1);
        }
        return $s . '...';
    }

    /** Splits text into lines no wider than $maxW. */
    public function wrap(string $s, float $maxW, float $size, bool $bold = false): array
    {
        $lines = [];
        foreach (preg_split('/\r?\n/', $s) as $para) {
            $line = '';
            foreach (preg_split('/\s+/', trim($para)) as $word) {
                $try = $line === '' ? $word : $line . ' ' . $word;
                if ($this->textWidth($try, $size, $bold) <= $maxW || $line === '') {
                    $line = $try;
                } else {
                    $lines[] = $line;
                    $line = $word;
                }
            }
            $lines[] = $line;
        }
        return $lines;
    }

    public function text(float $x, float $y, string $s, float $size = 10, bool $bold = false, string $align = 'left', float $gray = 0.1): void
    {
        if ($align === 'right') {
            $x -= $this->textWidth($s, $size, $bold);
        } elseif ($align === 'center') {
            $x -= $this->textWidth($s, $size, $bold) / 2;
        }
        $esc = strtr(self::enc($s), ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => ' ']);
        $this->cur .= sprintf("BT /%s %.2F Tf %.3F g %.2F %.2F Td (%s) Tj ET\n", $bold ? 'F2' : 'F1', $size, $gray, $x, $this->h - $y, $esc);
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $width = 0.5, float $gray = 0.75): void
    {
        $this->cur .= sprintf("%.3F G %.2F w %.2F %.2F m %.2F %.2F l S\n", $gray, $width, $x1, $this->h - $y1, $x2, $this->h - $y2);
    }

    public function rect(float $x, float $y, float $w, float $h, ?float $fill = null, ?float $stroke = null): void
    {
        $op = $fill !== null && $stroke !== null ? 'B' : ($fill !== null ? 'f' : 'S');
        $this->cur .= ($fill !== null ? sprintf('%.3F g ', $fill) : '') . ($stroke !== null ? sprintf('%.3F G 0.5 w ', $stroke) : '')
                    . sprintf("%.2F %.2F %.2F %.2F re %s\n", $x, $this->h - $y - $h, $w, $h, $op);
    }

    /** A box filled with one colour, given as #rrggbb (the greys above stay for everything else). */
    public function rectRgb(float $x, float $y, float $w, float $h, string $hex): void
    {
        [$r, $g, $b] = self::rgb($hex);
        $this->cur .= sprintf("%.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f\n", $r, $g, $b, $x, $this->h - $y - $h, $w, $h);
    }

    /** Text in one colour, given as #rrggbb. */
    public function textRgb(float $x, float $y, string $s, float $size, bool $bold, string $align, string $hex): void
    {
        if ($align === 'right') {
            $x -= $this->textWidth($s, $size, $bold);
        } elseif ($align === 'center') {
            $x -= $this->textWidth($s, $size, $bold) / 2;
        }
        [$r, $g, $b] = self::rgb($hex);
        $esc = strtr(self::enc($s), ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => ' ']);
        $this->cur .= sprintf("BT /%s %.2F Tf %.3F %.3F %.3F rg %.2F %.2F Td (%s) Tj ET\n", $bold ? 'F2' : 'F1', $size, $r, $g, $b, $x, $this->h - $y, $esc);
    }

    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        return [hexdec(substr($hex, 0, 2)) / 255, hexdec(substr($hex, 2, 2)) / 255, hexdec(substr($hex, 4, 2)) / 255];
    }

    public function output(): string
    {
        $pages = $this->pages;
        $pages[] = $this->cur;
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $kids = [];
        $n = 5;
        foreach ($pages as $content) {
            $pid = $n++;
            $cid = $n++;
            $kids[] = $pid . ' 0 R';
            $objs[$pid] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.0F %.0F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>',
                                  $this->w, $this->h, $cid);
            $objs[$cid] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($objs);
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offs = [];
        foreach ($objs as $id => $body) {
            $offs[$id] = strlen($out);
            $out .= $id . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($out);
        $max = max(array_keys($objs));
        $out .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) {
            $out .= sprintf("%010d 00000 n \n", $offs[$i] ?? 0);
        }
        $out .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
        return $out;
    }

    /** Sends the PDF to the browser. Inline to view, or as a download. */
    public function send(string $filename, bool $download = true): void
    {
        $pdf = $this->output();
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="'
             . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"');
        header('Content-Length: ' . strlen($pdf));
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        echo $pdf;
        exit;
    }
}

/** Amount for a PDF: Indian grouping, two places, "Rs." prefix. */
function hr_pdf_money($v): string
{
    $v = (float) $v;
    return ($v < 0 ? '-' : '') . 'Rs. ' . fabo_thousands(abs($v), 2);
}

/**
 * A titled, paginated table report. $cols: [[label, width, align], ...];
 * $rows: arrays of strings in column order. Landscape when wide.
 */
function hr_pdf_table(string $title, string $subtitle, array $cols, array $rows, array $totals = []): HrPdf
{
    $total = array_sum(array_map(static fn($c) => $c[1], $cols));
    $pdf = new HrPdf($total > 520);
    $W = $pdf->width();
    $H = $pdf->height();
    $scale = min(1.0, ($W - 60) / max(1, $total));
    $page = 0;
    $y = 0;
    $head = function () use ($pdf, $title, $subtitle, $cols, $scale, $W, &$y, &$page) {
        $pdf->addPage();
        $page++;
        $pdf->text(30, 40, $title, 14, true);
        $pdf->text(30, 56, $subtitle, 9, false, 'left', 0.4);
        $pdf->text($W - 30, 40, 'Page ' . $page, 8, false, 'right', 0.5);
        $y = 72;
        $pdf->rect(30, $y, $W - 60, 18, 0.93);
        $x = 30;
        foreach ($cols as [$lab, $w, $al]) {
            $cw = $w * $scale;
            $pdf->text($al === 'right' ? $x + $cw - 4 : $x + 4, $y + 12.5, $pdf->fit($lab, $cw - 8, 8, true), 8, true, $al === 'right' ? 'right' : 'left');
            $x += $cw;
        }
        $y += 18;
    };
    $head();
    if (!$rows) {
        $pdf->text(30, $y + 20, 'No records for the chosen filters.', 10, false, 'left', 0.4);
    }
    foreach ($rows as $i => $r) {
        if ($y > $H - 50) {
            $head();
        }
        if ($i % 2 === 1) {
            $pdf->rect(30, $y, $W - 60, 15, 0.975);
        }
        $x = 30;
        foreach ($cols as $j => [$lab, $w, $al]) {
            $cw = $w * $scale;
            $val = (string) ($r[$j] ?? '');
            $pdf->text($al === 'right' ? $x + $cw - 4 : $x + 4, $y + 10.5, $pdf->fit($val, $cw - 8, 8), 8, false, $al === 'right' ? 'right' : 'left');
            $x += $cw;
        }
        $y += 15;
    }
    if ($totals) {
        $pdf->line(30, $y + 1, $W - 30, $y + 1, 0.8, 0.4);
        $x = 30;
        foreach ($cols as $j => [$lab, $w, $al]) {
            $cw = $w * $scale;
            if (isset($totals[$j])) {
                $pdf->text($al === 'right' ? $x + $cw - 4 : $x + 4, $y + 13, $pdf->fit((string) $totals[$j], $cw - 8, 8, true), 8, true,
                           $al === 'right' ? 'right' : 'left');
            }
            $x += $cw;
        }
    }
    $pdf->text(30, $H - 22, 'Generated ' . date('d M Y H:i') . ' · Confidential', 7, false, 'left', 0.5);
    return $pdf;
}

/** A payslip, one A4 page. */
function hr_payslip_pdf(array $data): HrPdf
{
    $it = $data['item'];
    $e  = $data['emp'];
    $pdf = new HrPdf(false);
    $pdf->addPage();
    $company = (string) (hr_setting('pay_company_name') ?: fabo_app_name());
    $addr    = (string) hr_setting('pay_company_address');
    $month   = date('F Y', strtotime($it['period'] . '-01'));

    $pdf->text(40, 50, $company, 16, true);
    $y = 64;
    foreach ($addr !== '' ? $pdf->wrap($addr, 300, 8.5) : [] as $l) {
        $pdf->text(40, $y, $l, 8.5, false, 'left', 0.35);
        $y += 11;
    }
    $pdf->text(555, 50, 'PAYSLIP', 14, true, 'right');
    $pdf->text(555, 64, $month, 10, false, 'right', 0.3);
    $pdf->text(555, 77, $it['number'] ? 'No. ' . $it['number'] : 'Not yet issued', 8.5, false, 'right', 0.4);
    $y = max($y, 90) + 8;
    $pdf->line(40, $y, 555, $y, 1, 0.3);
    $y += 18;

    $left = [
        ['Employee', $e['full_name']], ['Employee ID', $e['emp_code']], ['Department', $e['department'] ?? ''],
        ['Designation', $e['designation'] ?? ''], ['Date of joining', hr_fmt_date($e['date_of_joining'])],
    ];
    $acct = $e['bank_account'] ? 'XXXX' . substr($e['bank_account'], -4) : '';
    $right = [
        ['Pay period', hr_fmt_date($it['period_start']) . ' - ' . hr_fmt_date($it['period_end'])],
        ['Bank', trim(($e['bank_name'] ?? '') . ' ' . $acct)], ['PAN', $e['pan'] ?? ''], ['UAN', $e['uan'] ?? ''],
        ['Payable days', hr_fmt_days($it['payable_days']) . ' of ' . hr_fmt_days($it['divisor_days'])],
    ];
    foreach ($left as $i => [$k, $v]) {
        $pdf->text(40, $y + $i * 15, $k, 8.5, false, 'left', 0.45);
        $pdf->text(130, $y + $i * 15, $pdf->fit((string) $v, 150, 9), 9, true);
    }
    foreach ($right as $i => [$k, $v]) {
        $pdf->text(310, $y + $i * 15, $k, 8.5, false, 'left', 0.45);
        $pdf->text(400, $y + $i * 15, $pdf->fit((string) $v, 155, 9), 9, true);
    }
    $y += count($left) * 15 + 12;

    // Attendance summary strip.
    $pdf->rect(40, $y, 515, 30, 0.96);
    $cells = [['Working days', $it['working_days']], ['Present', $it['present_days']], ['Paid leave', $it['paid_leave_days']],
              ['Unpaid leave', $it['unpaid_leave_days']], ['Absent', $it['absent_days']], ['Loss of pay', $it['lop_days']],
              ['Overtime (h)', $it['overtime_hours']]];
    foreach ($cells as $i => [$k, $v]) {
        $x = 40 + $i * (515 / count($cells)) + 6;
        $pdf->text($x, $y + 12, $k, 7, false, 'left', 0.45);
        $pdf->text($x, $y + 24, hr_fmt_days($v), 9.5, true);
    }
    $y += 44;

    // Earnings and deductions side by side.
    $earn = array_values(array_filter($data['lines'], static fn($l) => in_array($l['kind'], ['earning', 'reimbursement', 'earning_round'], true)));
    $ded  = array_values(array_filter($data['lines'], static fn($l) => in_array($l['kind'], ['deduction', 'deduction_round'], true)));
    $emp  = array_values(array_filter($data['lines'], static fn($l) => $l['kind'] === 'employer'));
    $pdf->rect(40, $y, 255, 18, 0.9);
    $pdf->rect(300, $y, 255, 18, 0.9);
    $pdf->text(46, $y + 12.5, 'Earnings', 9, true);
    $pdf->text(289, $y + 12.5, 'Amount', 9, true, 'right');
    $pdf->text(306, $y + 12.5, 'Deductions', 9, true);
    $pdf->text(549, $y + 12.5, 'Amount', 9, true, 'right');
    $y += 18;
    $rows = max(count($earn), count($ded), 1);
    for ($i = 0; $i < $rows; $i++) {
        $ry = $y + $i * 16;
        if (isset($earn[$i])) {
            $pdf->text(46, $ry + 11, $pdf->fit($earn[$i]['name'], 160, 8.5), 8.5);
            $pdf->text(289, $ry + 11, hr_pdf_money($earn[$i]['amount']), 8.5, false, 'right');
        }
        if (isset($ded[$i])) {
            $pdf->text(306, $ry + 11, $pdf->fit($ded[$i]['name'], 160, 8.5), 8.5);
            $pdf->text(549, $ry + 11, hr_pdf_money($ded[$i]['amount']), 8.5, false, 'right');
        }
        $pdf->line(40, $ry + 16, 295, $ry + 16, 0.3, 0.88);
        $pdf->line(300, $ry + 16, 555, $ry + 16, 0.3, 0.88);
    }
    $y += $rows * 16 + 4;
    $tEarn = array_sum(array_map(static fn($l) => (float) $l['amount'], $earn));
    $tDed  = array_sum(array_map(static fn($l) => (float) $l['amount'], $ded));
    $pdf->text(46, $y + 12, 'Total earnings', 9, true);
    $pdf->text(289, $y + 12, hr_pdf_money($tEarn), 9, true, 'right');
    $pdf->text(306, $y + 12, 'Total deductions', 9, true);
    $pdf->text(549, $y + 12, hr_pdf_money($tDed), 9, true, 'right');
    $y += 26;
    $pdf->rect(40, $y, 515, 30, 0.15);
    $pdf->text(52, $y + 19, 'NET PAY', 11, true, 'left', 1);
    $pdf->text(543, $y + 19, hr_pdf_money($it['net']), 13, true, 'right', 1);
    $y += 40;
    $pdf->text(40, $y + 6, 'In words: ' . hr_amount_words((float) $it['net']), 8.5, false, 'left', 0.3);
    $y += 22;

    if ($emp) {
        $pdf->text(40, $y, 'Employer contributions (not deducted from pay)', 8.5, true, 'left', 0.35);
        $y += 6;
        foreach ($emp as $l) {
            $y += 13;
            $pdf->text(46, $y, $l['name'], 8.5, false, 'left', 0.35);
            $pdf->text(289, $y, hr_pdf_money($l['amount']), 8.5, false, 'right', 0.35);
        }
        $y += 16;
    }
    $pdf->text(40, 800, 'This is a computer-generated payslip and does not need a signature.', 7.5, false, 'left', 0.5);
    $pdf->text(555, 800, 'Generated ' . date('d M Y'), 7.5, false, 'right', 0.5);
    return $pdf;
}

/** An amount in Indian words: "Rupees Forty-Five Thousand Two Hundred Only". */
function hr_amount_words(float $amt): string
{
    $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen',
             'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    $two = static function (int $n) use ($ones, $tens): string {
        if ($n < 20) { return $ones[$n]; }
        return $tens[intdiv($n, 10)] . ($n % 10 ? '-' . $ones[$n % 10] : '');
    };
    $three = static function (int $n) use ($two, $ones): string {
        $h = intdiv($n, 100);
        $r = $n % 100;
        return trim(($h ? $ones[$h] . ' Hundred' : '') . ($r ? ($h ? ' ' : '') . $two($r) : ''));
    };
    $neg = $amt < 0;
    $amt = abs(round($amt, 2));
    $rupees = (int) floor($amt);
    $paise  = (int) round(($amt - $rupees) * 100);
    if ($rupees === 0 && $paise === 0) {
        return 'Rupees Zero Only';
    }
    $parts = [];
    foreach ([[10000000, 'Crore'], [100000, 'Lakh'], [1000, 'Thousand']] as [$div, $name]) {
        if ($rupees >= $div) {
            $q = intdiv($rupees, $div);
            $parts[] = ($q >= 100 ? $three($q) : $two($q)) . ' ' . $name;
            $rupees %= $div;
        }
    }
    if ($rupees) {
        $parts[] = $three($rupees);
    }
    $s = ($neg ? 'Minus ' : '') . 'Rupees ' . implode(' ', $parts);
    if ($paise) {
        $s .= ' and ' . $two($paise) . ' Paise';
    }
    return trim($s) . ' Only';
}

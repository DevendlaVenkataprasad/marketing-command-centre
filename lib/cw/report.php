<?php
/**
 * Co-working Space — the downloadable PDF report.
 *
 *   cw_report_centre()  one centre: headline numbers, occupancy by area,
 *                       billing status, paperwork, renewals, then the
 *                       startups, agreements, action items and waiting list.
 *   cw_report_all()     every centre side by side, then each centre's
 *                       headline numbers.
 *
 * Drawn with the portal's own PDF writer (lib/hr/pdf.php): nothing fetched,
 * nothing installed. Its fonts have no rupee sign, so amounts read "Rs.".
 */

require_once __DIR__ . '/core.php';
require_once __DIR__ . '/../hr/pdf.php';

const CW_PDF_ACCENT = '#1d5fd0';
const CW_PDF_INK    = '#101828';

/** A report being drawn: the writer, where the pen is, and the page furniture. */
final class CwReport
{
    public HrPdf $pdf;
    public float $y = 0;
    public int $page = 0;
    public float $W;
    public float $H;
    public float $L = 36;

    public function __construct(public string $title, public string $sub)
    {
        $this->pdf = new HrPdf(false);
        $this->W = $this->pdf->width();
        $this->H = $this->pdf->height();
        $this->newPage(true);
    }

    public function right(): float
    {
        return $this->W - $this->L;
    }

    public function newPage(bool $cover = false): void
    {
        $p = $this->pdf;
        $p->addPage();
        $this->page++;
        if ($cover) {
            $p->rectRgb(0, 0, $this->W, 92, '#0f2f6b');
            $p->rectRgb(0, 92, $this->W, 4, CW_PDF_ACCENT);
            $p->textRgb($this->L, 34, 'CO-WORKING SPACE REPORT', 8.5, true, 'left', '#9fbcf2');
            $p->textRgb($this->L, 58, $this->title, 20, true, 'left', '#ffffff');
            $p->textRgb($this->L, 76, $this->sub, 9.5, false, 'left', '#c9d8f5');
            $p->textRgb($this->right(), 34, date('d M Y'), 9, true, 'right', '#ffffff');
            $this->y = 118;
        } else {
            $p->text($this->L, 32, $this->title, 9, true, 'left', 0.25);
            $p->text($this->right(), 32, $this->sub, 8, false, 'right', 0.45);
            $p->line($this->L, 40, $this->right(), 40, 0.6, 0.85);
            $this->y = 60;
        }
        $p->text($this->L, $this->H - 22, 'Generated ' . date('d M Y, H:i') . ' · Confidential', 7, false, 'left', 0.5);
        $p->text($this->right(), $this->H - 22, 'Page ' . $this->page, 7, false, 'right', 0.5);
    }

    /** Room for $h more points, or a new page. */
    public function need(float $h): void
    {
        if ($this->y + $h > $this->H - 46) {
            $this->newPage();
        }
    }

    public function heading(string $t, string $note = ''): void
    {
        $this->need(56);
        $this->y += 18;
        $this->pdf->rectRgb($this->L, $this->y - 9, 3, 12, CW_PDF_ACCENT);
        $this->pdf->textRgb($this->L + 9, $this->y + 1, $t, 12, true, 'left', CW_PDF_INK);
        if ($note !== '') {
            $this->pdf->text($this->right(), $this->y + 1, $note, 8, false, 'right', 0.45);
        }
        $this->y += 16;
    }

    /** A row of number tiles: [[label, value, sub], ...]. */
    public function tiles(array $tiles, int $perRow = 3): void
    {
        $gap = 10;
        $w = ($this->right() - $this->L - $gap * ($perRow - 1)) / $perRow;
        foreach (array_chunk($tiles, $perRow) as $row) {
            $this->need(62);
            foreach ($row as $i => [$label, $value, $sub]) {
                $x = $this->L + $i * ($w + $gap);
                $this->pdf->rect($x, $this->y, $w, 54, 0.975, 0.86);
                $this->pdf->text($x + 10, $this->y + 15, strtoupper($label), 7, true, 'left', 0.42);
                $this->pdf->textRgb($x + 10, $this->y + 34, $value, 15, true, 'left', CW_PDF_INK);
                $this->pdf->text($x + 10, $this->y + 47, $this->pdf->fit($sub, $w - 20, 7.5), 7.5, false, 'left', 0.45);
            }
            $this->y += 62;
        }
    }

    /** Labelled bars: [[label, value, max, shown], ...]. */
    public function bars(array $rows): void
    {
        $lw = 150;
        $vw = 90;
        $bw = $this->right() - $this->L - $lw - $vw - 10;
        foreach ($rows as [$label, $v, $max, $shown]) {
            $this->need(18);
            $this->pdf->text($this->L, $this->y + 9, $this->pdf->fit($label, $lw - 8, 8.5), 8.5);
            $this->pdf->rect($this->L + $lw, $this->y + 2, $bw, 8, 0.93);
            if ($max > 0 && $v > 0) {
                $this->pdf->rectRgb($this->L + $lw, $this->y + 2, max(2, $bw * min(1, $v / $max)), 8, CW_PDF_ACCENT);
            }
            $this->pdf->text($this->right(), $this->y + 9, $shown, 8.5, true, 'right', 0.2);
            $this->y += 16;
        }
        $this->y += 4;
    }

    /** Label / value pairs in two columns. */
    public function pairs(array $pairs): void
    {
        $half = ($this->right() - $this->L - 20) / 2;
        $i = 0;
        foreach ($pairs as $k => $v) {
            $col = $i % 2;
            if ($col === 0) {
                $this->need(16);
            }
            $x = $this->L + $col * ($half + 20);
            $this->pdf->text($x, $this->y + 9, $this->pdf->fit((string) $k, $half - 70, 8.5), 8.5, false, 'left', 0.3);
            $this->pdf->text($x + $half, $this->y + 9, (string) $v, 8.5, true, 'right', 0.1);
            $this->pdf->line($x, $this->y + 14, $x + $half, $this->y + 14, 0.4, 0.9);
            if ($col === 1 || $i === count($pairs) - 1) {
                $this->y += 17;
            }
            $i++;
        }
        $this->y += 6;
    }

    /** A table that runs over pages, repeating its header. $cols: [[label, width, align], ...]. */
    public function table(array $cols, array $rows, string $empty = 'None.'): void
    {
        $total = array_sum(array_column($cols, 1));
        $scale = ($this->right() - $this->L) / max(1, $total);
        $head = function () use ($cols, $scale) {
            $this->pdf->rectRgb($this->L, $this->y, $this->right() - $this->L, 17, '#eef2f8');
            $x = $this->L;
            foreach ($cols as [$lab, $w, $al]) {
                $cw = $w * $scale;
                $this->pdf->text($al === 'right' ? $x + $cw - 5 : $x + 5, $this->y + 11.5, $this->pdf->fit(strtoupper($lab), $cw - 10, 7, true), 7, true, $al, 0.3);
                $x += $cw;
            }
            $this->y += 17;
        };
        $this->need(40);
        $head();
        if (!$rows) {
            $this->pdf->text($this->L + 5, $this->y + 12, $empty, 8.5, false, 'left', 0.45);
            $this->y += 22;
            return;
        }
        foreach ($rows as $i => $r) {
            if ($this->y + 15 > $this->H - 46) {
                $this->newPage();
                $head();
            }
            if ($i % 2 === 1) {
                $this->pdf->rect($this->L, $this->y, $this->right() - $this->L, 15, 0.978);
            }
            $x = $this->L;
            foreach ($cols as $j => [$lab, $w, $al]) {
                $cw = $w * $scale;
                $val = (string) ($r[$j] ?? '');
                $bold = $j === 0;
                $this->pdf->text($al === 'right' ? $x + $cw - 5 : $x + 5, $this->y + 10.5, $this->pdf->fit($val, $cw - 10, 8, $bold), 8, $bold, $al, 0.12);
                $x += $cw;
            }
            $this->y += 15;
        }
        $this->y += 8;
    }

    public function note(string $t): void
    {
        foreach ($this->pdf->wrap($t, $this->right() - $this->L, 8) as $line) {
            $this->need(12);
            $this->pdf->text($this->L, $this->y + 8, $line, 8, false, 'left', 0.45);
            $this->y += 11;
        }
        $this->y += 4;
    }
}

function cw_pdf_money($v): string
{
    return 'Rs. ' . substr(cw_inr($v), strlen('₹'));
}

function cw_pdf_date(?string $d): string
{
    return $d ? date('d M Y', strtotime($d)) : '';
}

/** One centre's report. */
function cw_report_centre(array $m, string $orgName): HrPdf
{
    $c = $m['centre'];
    $r = new CwReport($c['name'] . ($c['kind'] === 'hub' ? ' (Hub)' : ' (Spoke)'), $orgName);

    $r->heading('Headline numbers', 'as of ' . date('d M Y'));
    $r->tiles([
        ['Desk occupancy', round($m['occupancy'] * 100) . '%', $m['desks_taken'] . ' of ' . $m['desks_total'] . ' desks taken'],
        ['Cabins occupied', $m['cabins_taken'] . ' / ' . $m['cabins_total'], ($m['cabins_total'] - $m['cabins_taken']) . ' vacant'],
        ['Open action items', (string) $m['action_count'], 'to follow up'],
        ['Billed rent / month', cw_pdf_money($m['billed']), 'Expected ' . cw_pdf_money($m['expected'])],
        ['Billing to confirm', cw_pdf_money($m['confirm']), $m['confirm_n'] . ' startup(s) to confirm'],
        ['Dues outstanding', cw_pdf_money($m['dues']), cw_pdf_money($m['dues_past']) . ' from past tenants'],
    ]);

    $r->heading('Occupancy by area', 'occupied of total');
    $r->bars(array_map(static fn($a) => [$a['label'], (float) $a['occupied'], (float) $a['total'],
        $a['occupied'] . ' / ' . $a['total'] . ' · ' . ($a['total'] ? round($a['occupied'] / $a['total'] * 100) : 0) . '%'], $m['area']));
    if ($m['partners']) {
        $r->note('Occupied = startups, plus vendors on ' . implode(', ', $m['partners']) . ' desks (they collect their own payment).');
    }

    $r->heading('Startups by billing status');
    $r->pairs(array_map('strval', $m['by_status']) ?: ['No startups yet' => '']);

    $r->heading('Paperwork & deposits');
    $r->pairs(array_map('strval', $m['paperwork']) + ['Security deposits held' => cw_pdf_money($m['deposits'])]);

    $r->heading('Renewals', (int) $c['term_months'] . '-month agreements');
    $r->pairs(array_map('strval', $m['renewals']) + ['Waiting list (open)' => (string) array_sum($m['pipeline'])]);

    $r->heading('Top dues');
    $r->bars(array_map(static fn($s) => [$s['name'], (float) $s['due_amount'], (float) ($m['top_dues'][0]['due_amount'] ?? 1), cw_pdf_money($s['due_amount'])], $m['top_dues']));

    $r->heading('Startups', count($m['startups']) . ' on the register');
    $r->table([['Startup', 190, 'left'], ['Billable', 60, 'left'], ['Desks', 38, 'right'], ['Cabins', 40, 'right'],
               ['Rent charged', 72, 'right'], ['Dues', 70, 'right'], ['Status', 95, 'left']],
        array_map(static fn($s) => [$s['name'], $s['billable'], (string) $s['_desks'], (string) $s['_cabins'] . ($s['_open'] > 0 ? ' +' . (float) $s['_open'] . ' sq ft' : ''),
            cw_pdf_money($s['_rent']), $s['due_amount'] !== null ? cw_pdf_money($s['due_amount']) : '', $s['_status']], $m['startups']), 'No startups yet.');

    $soon = array_values(array_filter($m['agreements'], static fn($g) => $g['status'] !== 'Active'));
    $r->heading('Agreements to renew', count($soon) . ' expired or due within 60 days');
    $r->table([['Startup', 210, 'left'], ['Spaces', 45, 'right'], ['Signed', 75, 'left'], ['Ends', 75, 'left'], ['Days left', 55, 'right'], ['Status', 90, 'left']],
        array_map(static fn($g) => [$g['startup'], (string) $g['spaces'], cw_pdf_date($g['date']), cw_pdf_date($g['end']),
            $g['days'] < 0 ? abs($g['days']) . ' over' : (string) $g['days'], $g['status']], $soon), 'Nothing due for renewal.');

    $rows = [];
    foreach ($m['actions'] as $prio => $issues) {
        foreach ($issues as $issue => $items) {
            foreach ($items as [$who, $detail]) {
                $rows[] = [$prio, $issue, $who, $detail];
            }
        }
    }
    $r->heading('Action items', $m['action_count'] . ' open');
    $r->table([['Priority', 52, 'left'], ['Issue', 125, 'left'], ['Startup / space', 150, 'left'], ['Detail', 220, 'left']], $rows, 'All clear.');

    $r->heading('Waiting list', array_sum($m['pipeline']) . ' open');
    $r->table([['Applicant', 160, 'left'], ['Ref', 70, 'left'], ['Contact', 110, 'left'], ['Desks', 38, 'right'], ['Applied', 70, 'left'], ['Status', 100, 'left']],
        array_map(static fn($w) => [$w['applicant'], (string) ($w['ref'] ?? ''), (string) ($w['contact'] ?: $w['email']), (string) (int) $w['desks_needed'],
            cw_pdf_date($w['applied_on']), $w['status']], $m['waiting']), 'No applicants.');
    return $r->pdf;
}

/** Every centre of the organisation. */
function cw_report_all(array $models, string $orgName): HrPdf
{
    $r = new CwReport('All centres', $orgName);
    $t = ['desks' => 0, 'taken' => 0, 'cabins' => 0, 'ctaken' => 0, 'billed' => 0, 'dues' => 0, 'actions' => 0, 'startups' => 0];
    foreach ($models as $m) {
        $t['desks'] += $m['desks_total'];
        $t['taken'] += $m['desks_taken'];
        $t['cabins'] += $m['cabins_total'];
        $t['ctaken'] += $m['cabins_taken'];
        $t['billed'] += $m['billed'];
        $t['dues'] += $m['dues'];
        $t['actions'] += $m['action_count'];
        $t['startups'] += $m['paperwork']['Startups using space'];
    }
    $r->heading('The network', count($models) . ' centres · as of ' . date('d M Y'));
    $r->tiles([
        ['Desk occupancy', ($t['desks'] ? round($t['taken'] / $t['desks'] * 100) : 0) . '%', $t['taken'] . ' of ' . $t['desks'] . ' desks taken'],
        ['Cabins occupied', $t['ctaken'] . ' / ' . $t['cabins'], ($t['cabins'] - $t['ctaken']) . ' vacant'],
        ['Startups in residence', (string) $t['startups'], 'across all centres'],
        ['Billed rent / month', cw_pdf_money($t['billed']), 'all centres'],
        ['Dues outstanding', cw_pdf_money($t['dues']), 'all centres'],
        ['Open action items', (string) $t['actions'], 'to follow up'],
    ]);
    $r->heading('Desk occupancy by centre');
    $r->bars(array_map(static fn($m) => [$m['centre']['name'] . ($m['centre']['kind'] === 'hub' ? ' (Hub)' : ''), (float) $m['desks_taken'], (float) max(1, $m['desks_total']),
        $m['desks_total'] ? $m['desks_taken'] . ' / ' . $m['desks_total'] . ' · ' . round($m['occupancy'] * 100) . '%' : 'not set up'], $models));
    $r->heading('Centres side by side');
    $r->table([['Centre', 120, 'left'], ['Desks', 60, 'right'], ['Cabins', 55, 'right'], ['Startups', 52, 'right'],
               ['Billed / month', 80, 'right'], ['Dues', 80, 'right'], ['Actions', 48, 'right'], ['Waiting', 48, 'right']],
        array_map(static fn($m) => [$m['centre']['name'], $m['desks_taken'] . ' / ' . $m['desks_total'], $m['cabins_taken'] . ' / ' . $m['cabins_total'],
            (string) $m['paperwork']['Startups using space'], cw_pdf_money($m['billed']), cw_pdf_money($m['dues']), (string) $m['action_count'],
            (string) array_sum($m['pipeline'])], $models));
    return $r->pdf;
}

<?php
/** Leave pieces shown on more than one page (Leave, the employee profile, My HR). */

/**
 * The month and the year side by side, for each leave type: what is left
 * this year, and what happened this month. Then the whole year by month.
 */
function hr_render_month_and_year(array $emp, int $year): void
{
    $bal = hr_leave_balances($emp, $year);
    $ms  = hr_leave_month_stats($emp, $year);
    $cur = date('Y-m');
    [$yFrom, $yTo] = hr_leave_year_bounds($year, (int) $emp['org_id']);
    if (!$bal) {
        echo '<div class="hr-note">No leave applies to you yet. HR sets up the leave policy.</div>';
        return;
    }
    echo '<div class="hr-bal">';
    foreach ($bal as $tid => $b) {
        $t = $b['type'];
        $m = $ms[$tid][$cur] ?? [];
        $month = 'This month: ' . hr_fmt_days($m['taken'] ?? 0) . ' taken'
               . (!empty($m['credited']) ? ' · ' . hr_fmt_days($m['credited']) . ' credited' : '')
               . (!empty($m['pending']) ? ' · ' . hr_fmt_days($m['pending']) . ' waiting for approval' : '');
        if ($t['unlimited']) {
            echo '<div class="b"><div class="t">' . e($t['name']) . '</div><div class="big">' . hr_fmt_days($b['taken']) . ' <small>days taken this year</small></div>'
               . '<div class="meta">' . e($month) . '<br>No balance limit' . ($t['is_paid'] ? '' : ' · unpaid') . '</div></div>';
            continue;
        }
        $av = (float) $b['available'];
        $monthly = ($b['rule']['accrual'] ?? '') === 'monthly';
        $cls = $av < 0 ? ' neg' : ($av <= 1 ? ' low' : '');
        echo '<div class="b' . $cls . '"><div class="t">' . e($t['name']) . '</div>'
           . '<div class="big">' . hr_fmt_days($av) . ' <small>day' . (abs($av) == 1.0 ? '' : 's') . ' left this year</small></div>'
           . '<div class="meta"><strong>' . e($month) . '</strong><br>'
           . 'This year: ' . hr_fmt_days($b['taken']) . ' taken of ' . hr_fmt_days($b['entitlement'])
           . ($monthly ? ' (credited ' . hr_fmt_days($b['entitlement'] / 12) . ' a month; ' . hr_fmt_days($b['accrued']) . ' so far)' : '')
           . ($b['carried'] ? ' · ' . hr_fmt_days($b['carried']) . ' carried in' : '')
           . ($b['pending'] ? ' · ' . hr_fmt_days($b['pending']) . ' waiting for approval' : '') . '</div></div>';
    }
    echo '</div>';

    $types = array_filter($bal, static fn($b) => true);
    echo '<div class="card"><h2>Month by month — ' . e(hr_leave_year_label($year)) . '</h2><div class="scrollx"><table class="data hr-table">'
       . '<thead><tr><th>Month</th>';
    foreach ($types as $b) {
        echo '<th class="num" title="' . e($b['type']['name']) . '">' . e($b['type']['code']) . ' taken</th>';
    }
    echo '<th class="num">Total taken</th></tr></thead><tbody>';
    $tot = [];
    for ($d = $yFrom; $d <= $yTo; $d = date('Y-m-d', strtotime($d . ' +1 month'))) {
        $mk = substr($d, 0, 7);
        $row = 0.0;
        echo '<tr' . ($mk === $cur ? ' style="background:#f4f8ff;font-weight:600"' : '') . '><td>' . e(date('F Y', strtotime($d))) . ($mk === $cur ? ' (this month)' : '') . '</td>';
        foreach ($types as $tid => $b) {
            $v = (float) ($ms[$tid][$mk]['taken'] ?? 0);
            $p = (float) ($ms[$tid][$mk]['pending'] ?? 0);
            $row += $v;
            $tot[$tid] = ($tot[$tid] ?? 0) + $v;
            echo '<td class="num">' . ($v ? hr_fmt_days($v) : '<span class="muted">—</span>') . ($p ? ' <span class="muted small">(+' . hr_fmt_days($p) . ' waiting)</span>' : '') . '</td>';
        }
        echo '<td class="num">' . ($row ? hr_fmt_days($row) : '<span class="muted">—</span>') . '</td></tr>';
    }
    echo '</tbody><tfoot><tr><th>Year</th>';
    foreach ($types as $tid => $b) {
        echo '<th class="num">' . hr_fmt_days($tot[$tid] ?? 0) . '</th>';
    }
    echo '<th class="num">' . hr_fmt_days(array_sum($tot)) . '</th></tr></tfoot></table></div></div>';
}

/** "Casual Leave — 8 days remaining", one card per leave type. */
function hr_render_balance_cards(array $bal): void
{
    if (!$bal) {
        echo '<div class="hr-note">No leave applies yet: there is no leave policy for this employee, or it has no leave types.</div>';
        return;
    }
    echo '<div class="hr-bal">';
    foreach ($bal as $b) {
        $t = $b['type'];
        if ($t['unlimited']) {
            echo '<div class="b"><div class="t">' . e($t['name']) . '</div><div class="big">' . hr_fmt_days($b['taken']) . ' <small>days taken</small></div>'
               . '<div class="meta">No balance limit' . ($t['is_paid'] ? '' : ' · unpaid') . ($b['pending'] ? ' · ' . hr_fmt_days($b['pending']) . ' pending' : '') . '</div></div>';
            continue;
        }
        $av = (float) $b['available'];
        $cls = $av < 0 ? ' neg' : ($av <= 1 ? ' low' : '');
        echo '<div class="b' . $cls . '"><div class="t">' . e($t['name']) . '</div><div class="big">' . hr_fmt_days($av) . ' <small>day' . (abs($av) == 1.0 ? '' : 's') . ' remaining</small></div>'
           . '<div class="meta">' . hr_fmt_days($b['taken']) . ' taken' . ($b['pending'] ? ' · ' . hr_fmt_days($b['pending']) . ' pending approval' : '')
           . ' · ' . hr_fmt_days($b['entitlement']) . ' a year' . ($b['carried'] ? ' · ' . hr_fmt_days($b['carried']) . ' carried in' : '') . '</div></div>';
    }
    echo '</div>';
}

/** The full breakdown: where every remaining day comes from. */
function hr_render_balance_table(array $bal): void
{
    $rows = array_filter($bal, static fn($b) => !$b['type']['unlimited']);
    if (!$rows) {
        return;
    }
    echo '<div class="card"><h2>How each balance is made up</h2><div class="scrollx"><table class="data hr-table stack"><thead><tr><th>Leave type</th>'
       . '<th class="num">Opening</th><th class="num">Entitlement / year</th><th class="num">Credited so far</th><th class="num">Carried in</th>'
       . '<th class="num">Taken</th><th class="num">Pending</th><th class="num">Expired / lapsed</th><th class="num">Adjusted</th><th class="num">Available</th></tr></thead><tbody>';
    foreach ($rows as $b) {
        echo '<tr><td data-l="Leave type"><strong>' . e($b['type']['name']) . '</strong>' . ($b['type']['is_paid'] ? '' : ' <span class="muted small">(unpaid)</span>') . '</td>'
           . '<td class="num" data-l="Opening">' . hr_fmt_days($b['opening']) . '</td><td class="num" data-l="Entitlement">' . hr_fmt_days($b['entitlement']) . '</td>'
           . '<td class="num" data-l="Credited">' . hr_fmt_days($b['accrued']) . '</td><td class="num" data-l="Carried in">' . hr_fmt_days($b['carried']) . '</td>'
           . '<td class="num" data-l="Taken">' . hr_fmt_days($b['taken']) . '</td><td class="num" data-l="Pending">' . hr_fmt_days($b['pending']) . '</td>'
           . '<td class="num" data-l="Expired">' . hr_fmt_days($b['expired'] + ($b['carried_out'] ?? 0)) . '</td><td class="num" data-l="Adjusted">' . hr_fmt_days($b['adjusted']) . '</td>'
           . '<td class="num" data-l="Available"><strong>' . hr_fmt_days($b['available']) . '</strong></td></tr>';
    }
    echo '</tbody></table></div><p class="hr-hint">Available = opening + credited + carried in − taken − expired ± adjustments'
       . (hr_setting('leave_reserve_pending') === '1' ? ' − pending (pending requests are held until decided)' : '') . '.</p></div>';
}

/** A list of leave requests. */
function hr_render_leave_rows(array $rows, bool $showEmp = true): void
{
    if (!$rows) {
        echo hr_empty('No leave requests.');
        return;
    }
    echo '<div class="scrollx"><table class="data hr-table stack"><thead><tr>' . ($showEmp ? '<th>Employee</th>' : '')
       . '<th>Leave type</th><th>Dates</th><th class="num">Days</th><th>Status</th><th>Reason</th><th></th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $dates = hr_fmt_date($r['start_date']) . ($r['end_date'] !== $r['start_date'] ? ' – ' . hr_fmt_date($r['end_date']) : '')
               . ($r['half_session'] ? ' (' . ($r['half_session'] === 'first' ? 'first half' : 'second half') . ')' : '')
               . ($r['start_half'] && $r['start_date'] !== $r['end_date'] ? ' · from mid-day' : '') . ($r['end_half'] ? ' · until mid-day' : '');
        $lvl = '';
        if ($r['status'] === 'submitted') {
            $chain = hr_leave_chain($r);
            $st = $chain[(int) $r['level_done']] ?? null;
            $lvl = '<div class="muted small">Step ' . ((int) $r['level_done'] + 1) . ' of ' . count($chain) . ($st ? ': waiting for ' . e($st['label']) : '') . '</div>';
        }
        echo '<tr>' . ($showEmp ? '<td data-l="Employee">' . hr_person_cell(['id' => $r['employee_id'], 'full_name' => $r['full_name'], 'emp_code' => $r['emp_code']]) . '</td>' : '')
           . '<td data-l="Leave type">' . e($r['type_name']) . '</td><td data-l="Dates">' . e($dates) . '</td><td class="num" data-l="Days">' . hr_fmt_days($r['days']) . '</td>'
           . '<td data-l="Status">' . hr_badge($r['status'], hr_leave_statuses()[$r['status']] ?? $r['status']) . $lvl . '</td>'
           . '<td data-l="Reason" class="small">' . e(mb_strimwidth((string) $r['reason'], 0, 90, '…')) . '</td>'
           . '<td><a class="btn btn-sm" href="' . e(hr_url('leave', ['id' => $r['id']])) . '">' . (hr_leave_can_decide($r) ? 'Review' : 'Open') . '</a></td></tr>';
    }
    echo '</tbody></table></div>';
}

/** The apply form. $empChoices empty = the viewer applies for themselves. */
function hr_render_leave_form(array $empChoices, int $empId, array $old = [], string $action = ''): void
{
    $types = [];
    foreach (hr_leave_types() as $t) {
        $types[(int) $t['id']] = $t['name'] . ($t['is_paid'] ? '' : ' (unpaid)');
    }
    echo hr_form_open($action, 'class="hr-form" id="hr-leave-form"', true) . '<input type="hidden" name="action" value="apply"><div class="formgrid">';
    if ($empChoices) {
        echo hr_field('Employee', hr_select('employee_id', $empChoices, $old['employee_id'] ?? $empId, 'Choose…', ['required' => true]), true);
    } else {
        echo '<input type="hidden" name="employee_id" value="' . $empId . '">';
    }
    $sd = $old['start_date'] ?? '';
    $ed = $old['end_date'] ?? '';
    echo hr_field('Leave type', hr_select('leave_type_id', $types, $old['leave_type_id'] ?? '', 'Choose…', ['required' => true]), true)
       . hr_field('From', hr_input('start_date', $sd, 'date', ['required' => true]), true)
       . hr_field('To', hr_input('end_date', $ed, 'date'), false, 'Same as “From” for a single day.')
       . '</div><div class="formgrid"><div class="field" data-oneday><label>For that day</label>'
       . hr_select('day_type', ['full' => 'Full day', 'first' => 'First half', 'second' => 'Second half'], $old['day_type'] ?? 'full') . '</div>'
       . '<div class="field" data-manyday hidden><label>Half days</label>' . hr_checkbox('start_half', !empty($old['start_half']), 'Start from the second half of the first day')
       . hr_checkbox('end_half', !empty($old['end_half']), 'End after the first half of the last day') . '</div></div>'
       . '<div id="hr-leave-preview" class="hr-note" hidden aria-live="polite"></div>'
       . '<div class="formgrid">' . hr_field('Reason', '<textarea name="reason" rows="3" maxlength="1000" placeholder="A short reason">' . e($old['reason'] ?? '') . '</textarea>', true, '', 'full')
       . hr_field('Supporting document', '<input type="file" name="attachment">', false, 'Required for some leave types when the leave is long (for example a medical certificate).')
       . '</div><div class="hr-formfoot"><button class="btn btn-primary" name="submit" value="1">Submit request</button>'
       . '<button class="btn" name="submit" value="0">Save as draft</button></div></form>';
}

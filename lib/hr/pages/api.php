<?php
/**
 * Small JSON answers for the HR pages' scripts. Read-only, and checked like
 * the pages: the viewer must be able to create leave for that employee.
 */

header('Content-Type: application/json; charset=utf-8');
$do = (string) ($_GET['do'] ?? '');

if ($do === 'leave_days') {
    try {
        $empId = (int) ($_GET['employee_id'] ?? 0);
        if (!hr_emp_allowed($empId, 'leave', 'create') && !hr_emp_allowed($empId, 'leave', 'approve')) {
            echo json_encode(['ok' => false, 'error' => 'Choose an employee.']);
            exit;
        }
        $emp  = hr_employee($empId);
        $type = hr_leave_type((int) ($_GET['leave_type_id'] ?? 0));
        if (!$type) {
            echo json_encode(['ok' => false, 'error' => 'Choose a leave type to see the days.']);
            exit;
        }
        [$from, $to, $sh, $eh] = hr_leave_dates_from_post($_GET);
        if ($to < $from) {
            throw new InvalidArgumentException('The end date is before the start date.');
        }
        $rule = hr_emp_rule($emp, (int) $type['id'], $from);
        $c = hr_leave_count($emp, $rule, $from, $to, $sh, $eh);
        $text = hr_fmt_days($c['days']) . ' day(s) of ' . $type['name'];
        if ($c['skipped']) {
            $text .= ' (' . $c['skipped'] . ' weekly off / holiday day(s) in the range are not counted)';
        }
        if (!$type['unlimited']) {
            $b = hr_leave_balances($emp, hr_leave_year_of($from))[(int) $type['id']] ?? null;
            $av = $b ? (float) $b['available'] : 0.0;
            $text .= '. Available now: ' . hr_fmt_days($av) . ' day(s); after this request: ' . hr_fmt_days($av - $c['days']) . '.';
            if (!$rule) {
                $text = $type['name'] . ' is not in this employee\'s leave policy.';
                echo json_encode(['ok' => false, 'error' => $text]);
                exit;
            }
            if ($av - $c['days'] < -((float) ($rule['allow_negative'] ? $rule['negative_limit'] : 0)) - 0.001) {
                echo json_encode(['ok' => false, 'error' => $text . ' That is more than the balance allows.']);
                exit;
            }
        }
        echo json_encode(['ok' => true, 'text' => $text, 'days' => $c['days']]);
    } catch (InvalidArgumentException $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
http_response_code(404);
echo json_encode(['ok' => false, 'error' => 'Unknown request.']);

<?php
/**
 * HR reminders, run from cron.php (and safe to run as often as cron runs:
 * every notice carries a key and is sent at most once).
 *
 *   - approved leave starting tomorrow: the employee, and their manager;
 *   - a holiday in the next three days: everyone whose calendar has it;
 *   - unmarked working days in the past week: once a week per person.
 *
 * Every organisation that has HR employees is visited, with the system
 * acting as administrator for that organisation.
 */

require_once __DIR__ . '/attendance.php';
require_once __DIR__ . '/workplace.php';

function hr_reminders_run(): array
{
    hr_install();
    $done = ['leave' => 0, 'holidays' => 0, 'missing' => 0];
    foreach (fabo_all('SELECT DISTINCT org_id FROM hr_employees') as $o) {
        $org = (int) $o['org_id'];
        $GLOBALS['hr_ctx'] = ['viewer' => fabo_admin_viewer(), 'is_admin' => true, 'person' => null, 'org' => $org,
                              'actor' => 'System', 'perms' => [], 'self_emp' => null];
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        foreach (fabo_all("SELECT r.*, t.name AS type_name, e.full_name, e.manager_id FROM hr_leave_requests r
                           JOIN hr_leave_types t ON t.id = r.leave_type_id JOIN hr_employees e ON e.id = r.employee_id
                           WHERE r.org_id = ? AND r.status = 'approved' AND r.start_date = ?", 'is', [$org, $tomorrow]) as $r) {
            $what = $r['type_name'] . ' from ' . hr_fmt_date($r['start_date']) . ($r['end_date'] !== $r['start_date'] ? ' to ' . hr_fmt_date($r['end_date']) : '');
            if (hr_notify_employee((int) $r['employee_id'], 'leave-soon-' . $r['id'] . '-v' . $r['version'], 'Your leave starts tomorrow', $what . '.')) {
                $done['leave']++;
            }
            if ($r['manager_id']) {
                hr_notify_employee((int) $r['manager_id'], 'leave-soon-mgr-' . $r['id'] . '-v' . $r['version'], $r['full_name'] . ' is on leave from tomorrow', $what . '.');
            }
        }
        $soon = date('Y-m-d', strtotime('+3 day'));
        foreach (fabo_all("SELECT * FROM hr_employees WHERE org_id = ? AND person_id IS NOT NULL AND status IN ('active','probation','notice')", 'i', [$org]) as $e) {
            $cal = hr_emp_calendar_id($e);
            if (!$cal) {
                continue;
            }
            foreach (hr_holiday_dates([$cal], date('Y-m-d'), $soon) as $d => $h) {
                if (hr_notify_employee((int) $e['id'], 'holiday-' . $d . '-' . $h['id'], 'Holiday on ' . date('l, j M', strtotime($d)), $h['name'] . ' — the office is closed.')) {
                    $done['holidays']++;
                }
            }
        }
        if ((int) date('N') === 1) {     // Mondays: last week's unmarked days
            $done['missing'] += hr_att_missing_reminders();
        }
        // Interviews tomorrow: the interviewer.
        foreach (fabo_all("SELECT i.id, i.interviewer_id, i.round, i.scheduled_at, c.full_name FROM hr_interviews i JOIN hr_candidates c ON c.id = i.candidate_id
                           WHERE i.org_id = ? AND i.status = 'scheduled' AND i.interviewer_id IS NOT NULL AND DATE(i.scheduled_at) = ?", 'is', [$org, $tomorrow]) as $i) {
            hr_notify_employee((int) $i['interviewer_id'], 'interview-soon-' . $i['id'], 'Interview tomorrow: ' . $i['full_name'],
                               $i['round'] . ' at ' . date('H:i', strtotime($i['scheduled_at'])) . '.', 'hr.php?p=recruitment&tab=myinterviews');
        }
        // Training tomorrow: everyone enrolled.
        foreach (fabo_all("SELECT x.employee_id, t.id, t.title, t.location FROM hr_training_enrolments x JOIN hr_trainings t ON t.id = x.training_id
                           WHERE x.org_id = ? AND x.status = 'enrolled' AND t.status IN ('planned','open') AND t.start_date = ?", 'is', [$org, $tomorrow]) as $x) {
            hr_notify_employee((int) $x['employee_id'], 'training-soon-' . $x['id'], 'Training tomorrow: ' . $x['title'],
                               ($x['location'] ? 'Where: ' . $x['location'] . '.' : 'See the session for details.'), 'hr.php?p=training&id=' . $x['id']);
        }
        // Probation ending in a week: the owner, once per employee.
        foreach (fabo_all("SELECT id, full_name, emp_code, probation_end FROM hr_employees WHERE org_id = ? AND status = 'probation' AND probation_end = ?",
                          'is', [$org, date('Y-m-d', strtotime('+7 day'))]) as $e) {
            hr_notify_admin('probation-' . $e['id'] . '-' . $e['probation_end'], 'Probation ends in a week: ' . $e['full_name'],
                            $e['full_name'] . ' (' . $e['emp_code'] . ') finishes probation on ' . hr_fmt_date($e['probation_end']) . '. Confirm them or extend it on their record.',
                            'admin/hr.php?p=employee&id=' . $e['id']);
        }
    }
    unset($GLOBALS['hr_ctx']);
    return $done;
}

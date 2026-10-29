<?php
/**
 * Talent — recruitment, performance reviews and training.
 *
 *   RECRUITMENT  job openings, each with its candidates moving through
 *                applied → screening → interview → offer → hired. Interviews
 *                are booked with an employee as interviewer, who records the
 *                feedback from their own My HR page. Hiring a candidate
 *                creates the employee record (and starts onboarding).
 *   PERFORMANCE  a review cycle gives each chosen employee a review. The
 *                employee and their manager agree goals with weights; the
 *                employee rates themselves, the manager rates, and the
 *                final rating is the weighted manager rating unless HR sets
 *                another with a reason.
 *   TRAINING     sessions with a capacity, people enrolled by HR or by
 *                themselves when a session is open for enrolment, and
 *                completion with a score and the attendee's feedback.
 */

require_once __DIR__ . '/lifecycle.php';

/* ============================================================ recruitment */

function hr_opening_statuses(): array
{
    return ['draft' => 'Draft', 'open' => 'Open', 'on_hold' => 'On hold', 'filled' => 'Filled', 'closed' => 'Closed'];
}

/** The pipeline, in order. Rejected and withdrawn sit outside it. */
function hr_candidate_stages(): array
{
    return ['applied' => 'Applied', 'screening' => 'Screening', 'interview' => 'Interview', 'offer' => 'Offer',
            'hired' => 'Hired', 'rejected' => 'Rejected', 'withdrawn' => 'Withdrawn'];
}

function hr_candidate_sources(): array
{
    return ['Careers page', 'Job portal', 'Referral', 'LinkedIn', 'Consultant', 'Campus', 'Walk-in', 'Other'];
}

function hr_interview_modes(): array
{
    return ['in_person' => 'In person', 'video' => 'Video call', 'phone' => 'Phone'];
}

function hr_interview_results(): array
{
    return ['pass' => 'Move forward', 'hold' => 'On hold', 'fail' => 'Do not proceed'];
}

/** Openings the viewer may see: all of them org-wide, or those whose hiring manager is in their team. */
function hr_openings_scope_sql(string $action = 'view'): string
{
    $scope = hr_scope('recruitment', $action);
    if ($scope === 'org') {
        return '';
    }
    $self = hr_self_emp();
    if ($scope !== 'team' || !$self) {
        return ' AND 1 = 0';
    }
    $ids = array_merge([(int) $self['id']], hr_team_ids((int) $self['id']));
    return ' AND o.hiring_manager_id IN (' . implode(',', array_map('intval', $ids)) . ')';
}

function hr_openings(string $status = '', int $limit = 300): array
{
    return fabo_all('SELECT o.*, d.name AS department, g.name AS designation, l.name AS location, m.full_name AS hiring_manager,
                            (SELECT COUNT(*) FROM hr_candidates c WHERE c.opening_id = o.id) AS candidates,
                            (SELECT COUNT(*) FROM hr_candidates c WHERE c.opening_id = o.id AND c.stage NOT IN ("rejected","withdrawn","hired")) AS active,
                            (SELECT COUNT(*) FROM hr_candidates c WHERE c.opening_id = o.id AND c.stage = "hired") AS hired
                       FROM hr_job_openings o LEFT JOIN hr_departments d ON d.id = o.department_id LEFT JOIN hr_designations g ON g.id = o.designation_id
                       LEFT JOIN hr_masters l ON l.id = o.location_id LEFT JOIN hr_employees m ON m.id = o.hiring_manager_id
                      WHERE o.org_id = ?' . ($status !== '' ? ' AND o.status = ?' : '') . hr_openings_scope_sql()
                  . ' ORDER BY FIELD(o.status, "open", "on_hold", "draft", "filled", "closed"), o.created_at DESC LIMIT ' . max(1, min(2000, $limit)),
                    'i' . ($status !== '' ? 's' : ''), $status !== '' ? [hr_org(), $status] : [hr_org()]);
}

function hr_opening(int $id, string $action = 'view'): ?array
{
    return fabo_one('SELECT o.*, d.name AS department, g.name AS designation, l.name AS location, t.name AS employment_type, m.full_name AS hiring_manager
                       FROM hr_job_openings o LEFT JOIN hr_departments d ON d.id = o.department_id LEFT JOIN hr_designations g ON g.id = o.designation_id
                       LEFT JOIN hr_masters l ON l.id = o.location_id LEFT JOIN hr_masters t ON t.id = o.employment_type_id
                       LEFT JOIN hr_employees m ON m.id = o.hiring_manager_id
                      WHERE o.id = ? AND o.org_id = ?' . hr_openings_scope_sql($action), 'ii', [$id, hr_org()]);
}

function hr_opening_save(?int $id, array $p): int
{
    if ($id ? !hr_opening($id, 'edit') : hr_scope('recruitment', 'create') !== 'org') {
        fabo_deny('You cannot change this opening.', 403);
    }
    $status = (string) ($p['status'] ?? 'open');
    if (!isset(hr_opening_statuses()[$status])) {
        throw new InvalidArgumentException('Choose a status.');
    }
    $v = [
        'title' => hr_str($p['title'] ?? '', 160, true, 'Job title'),
        'department_id' => hr_ref_id($p['department_id'] ?? 0, 'hr_departments', 'Department'),
        'designation_id' => hr_ref_id($p['designation_id'] ?? 0, 'hr_designations', 'Designation'),
        'location_id' => hr_ref_id($p['location_id'] ?? 0, 'hr_masters', 'Location'),
        'employment_type_id' => hr_ref_id($p['employment_type_id'] ?? 0, 'hr_masters', 'Employment type'),
        'hiring_manager_id' => hr_ref_id($p['hiring_manager_id'] ?? 0, 'hr_employees', 'Hiring manager'),
        'positions' => max(1, min(999, (int) ($p['positions'] ?? 1))),
        'experience' => hr_str($p['experience'] ?? '', 80) ?: null,
        'salary_range' => hr_str($p['salary_range'] ?? '', 80) ?: null,
        'description' => hr_str($p['description'] ?? '', 10000) ?: null,
        'status' => $status,
        'target_date' => hr_date($p['target_date'] ?? '', false, 'Target date'),
    ];
    $cols = array_keys($v);
    $types = 'siiiiiisssss';
    if ($id) {
        $before = hr_opening($id);
        fabo_exec('UPDATE hr_job_openings SET ' . implode(', ', array_map(static fn($c) => $c . ' = ?', $cols)) . ' WHERE id = ? AND org_id = ?',
                  $types . 'ii', array_merge(array_values($v), [$id, hr_org()]));
        hr_audit('update', 'job_opening', $id, 'Opening updated: ' . $v['title'], $before, $v);
        return $id;
    }
    $id = fabo_exec('INSERT INTO hr_job_openings (org_id, ' . implode(', ', $cols) . ', created_by) VALUES (?, ' . implode(', ', array_fill(0, count($cols), '?')) . ', ?)',
                    'i' . $types . 's', array_merge([hr_org()], array_values($v), [hr_actor()]));
    hr_audit('create', 'job_opening', $id, 'Opening created: ' . $v['title']);
    return $id;
}

function hr_opening_delete(int $id): void
{
    $o = hr_opening($id);
    if (!$o || hr_scope('recruitment', 'delete') !== 'org') {
        fabo_deny('You cannot delete this opening.', 403);
    }
    if ((int) (fabo_one('SELECT COUNT(*) n FROM hr_candidates WHERE opening_id = ?', 'i', [$id])['n'] ?? 0) > 0) {
        throw new InvalidArgumentException('This opening has candidates. Close it instead, so their history is kept.');
    }
    fabo_exec('DELETE FROM hr_job_openings WHERE id = ? AND org_id = ?', 'ii', [$id, hr_org()]);
    hr_audit('delete', 'job_opening', $id, 'Opening deleted: ' . $o['title'], $o, null);
}

/** One candidate, only when the viewer can see the opening they applied for. */
function hr_candidate(int $id, string $action = 'view'): ?array
{
    $c = fabo_one('SELECT c.*, o.title AS opening, o.hiring_manager_id FROM hr_candidates c JOIN hr_job_openings o ON o.id = c.opening_id
                    WHERE c.id = ? AND c.org_id = ?', 'ii', [$id, hr_org()]);
    if (!$c || !hr_opening((int) $c['opening_id'], $action)) {
        return null;
    }
    return $c;
}

function hr_candidates(array $f, int $limit = 500): array
{
    $where = ' WHERE c.org_id = ?';
    $types = 'i';
    $params = [hr_org()];
    if (!empty($f['opening'])) {
        $where .= ' AND c.opening_id = ' . (int) $f['opening'];
    }
    if (($f['stage'] ?? '') !== '') {
        if ($f['stage'] === 'active') {
            $where .= " AND c.stage NOT IN ('hired','rejected','withdrawn')";
        } else {
            $where .= ' AND c.stage = ?';
            $types .= 's';
            $params[] = (string) $f['stage'];
        }
    }
    if (($f['q'] ?? '') !== '') {
        $where .= ' AND (c.full_name LIKE ? OR c.email LIKE ? OR c.phone LIKE ?)';
        $types .= 'sss';
        array_push($params, '%' . $f['q'] . '%', '%' . $f['q'] . '%', '%' . $f['q'] . '%');
    }
    return fabo_all('SELECT c.*, o.title AS opening,
                            (SELECT MIN(i.scheduled_at) FROM hr_interviews i WHERE i.candidate_id = c.id AND i.status = "scheduled" AND i.scheduled_at >= NOW()) AS next_interview
                       FROM hr_candidates c JOIN hr_job_openings o ON o.id = c.opening_id' . $where . hr_openings_scope_sql()
                  . ' ORDER BY FIELD(c.stage, "offer", "interview", "screening", "applied", "hired", "rejected", "withdrawn"), c.updated_at DESC, c.id DESC LIMIT '
                  . max(1, min(2000, $limit)), $types, $params);
}

function hr_candidate_log(int $candId, string $summary): void
{
    fabo_exec('INSERT INTO hr_candidate_events (candidate_id, summary, created_by) VALUES (?,?,?)', 'iss', [$candId, mb_substr($summary, 0, 255), hr_actor()]);
}

function hr_candidate_events(int $candId): array
{
    return fabo_all('SELECT * FROM hr_candidate_events WHERE candidate_id = ? ORDER BY id DESC', 'i', [$candId]);
}

function hr_candidate_save(?int $id, array $p, ?array $resume = null): int
{
    $openingId = (int) ($p['opening_id'] ?? 0);
    if (!hr_opening($openingId, 'edit')) {
        throw new InvalidArgumentException('Choose an opening you look after.');
    }
    $before = $id ? hr_candidate($id, 'edit') : null;
    if ($id && !$before) {
        fabo_deny('That candidate is not available to you.', 404);
    }
    $email = hr_str($p['email'] ?? '', 190);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Email is not a valid address.');
    }
    if ($email !== '' && fabo_one('SELECT id FROM hr_candidates WHERE org_id = ? AND opening_id = ? AND email = ?' . ($id ? ' AND id <> ' . (int) $id : ''),
                                  'iis', [hr_org(), $openingId, mb_strtolower($email)])) {
        throw new InvalidArgumentException('This person has already applied for this opening.');
    }
    $rating = (int) ($p['rating'] ?? 0);
    $v = [
        'opening_id' => $openingId,
        'full_name' => hr_str($p['full_name'] ?? '', 160, true, 'Name'),
        'email' => $email !== '' ? mb_strtolower($email) : null,
        'phone' => hr_str($p['phone'] ?? '', 40) ?: null,
        'source' => hr_str($p['source'] ?? '', 60) ?: null,
        'current_company' => hr_str($p['current_company'] ?? '', 120) ?: null,
        'experience_years' => hr_dec($p['experience_years'] ?? '', 1, false, 'Experience', 0, 60),
        'expected_salary' => hr_dec($p['expected_salary'] ?? '', 2, false, 'Expected salary', 0, 1000000000),
        'notice_days' => ($p['notice_days'] ?? '') === '' ? null : max(0, min(365, (int) $p['notice_days'])),
        'rating' => $rating >= 1 && $rating <= 5 ? $rating : null,
        'notes' => hr_str($p['notes'] ?? '', 5000) ?: null,
    ];
    $types = 'isssssssiis';
    if ($id) {
        fabo_exec('UPDATE hr_candidates SET ' . implode(', ', array_map(static fn($c) => $c . ' = ?', array_keys($v))) . ' WHERE id = ? AND org_id = ?',
                  $types . 'ii', array_merge(array_values($v), [$id, hr_org()]));
        hr_audit('update', 'candidate', $id, 'Candidate updated: ' . $v['full_name']);
    } else {
        $id = fabo_exec('INSERT INTO hr_candidates (org_id, ' . implode(', ', array_keys($v)) . ', created_by) VALUES (?, '
                        . implode(', ', array_fill(0, count($v), '?')) . ', ?)', 'i' . $types . 's', array_merge([hr_org()], array_values($v), [hr_actor()]));
        hr_candidate_log($id, 'Added to “' . (hr_opening($openingId)['title'] ?? '') . '”' . ($v['source'] ? ' via ' . $v['source'] : ''));
        hr_audit('create', 'candidate', $id, 'Candidate added: ' . $v['full_name']);
    }
    if ($resume && ($resume['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        [$ok, $msg, $meta] = fabo_store_upload($resume);
        if (!$ok) {
            throw new InvalidArgumentException('Résumé: ' . $msg);
        }
        $old = fabo_one('SELECT resume_file FROM hr_candidates WHERE id = ?', 'i', [$id]);
        fabo_exec('UPDATE hr_candidates SET resume_file = ?, resume_name = ?, resume_mime = ? WHERE id = ?', 'sssi',
                  [$meta['stored_name'], $meta['original_name'], $meta['mime'], $id]);
        if (!empty($old['resume_file'])) {
            fabo_delete_stored($old['resume_file']);
        }
    }
    return $id;
}

/** Moves a candidate along the pipeline. An offer records the salary and joining date offered. */
function hr_candidate_stage(int $id, string $stage, array $p = []): void
{
    $c = hr_candidate($id, 'edit');
    if (!$c) {
        fabo_deny('That candidate is not available to you.', 404);
    }
    if (!isset(hr_candidate_stages()[$stage]) || $stage === 'hired') {
        throw new InvalidArgumentException('Choose a stage. To hire, use “Hire and create employee”.');
    }
    if ($c['stage'] === 'hired') {
        throw new InvalidArgumentException('This candidate has already been hired.');
    }
    $note = hr_str($p['note'] ?? '', 255);
    if ($stage === 'offer') {
        $sal = hr_dec($p['offer_salary'] ?? '', 2, false, 'Offered salary', 0, 1000000000);
        $join = hr_date($p['offer_join_date'] ?? '', false, 'Joining date');
        fabo_exec('UPDATE hr_candidates SET stage = ?, offer_salary = COALESCE(?, offer_salary), offer_join_date = COALESCE(?, offer_join_date) WHERE id = ?',
                  'sssi', [$stage, $sal, $join, $id]);
    } else {
        fabo_exec('UPDATE hr_candidates SET stage = ? WHERE id = ?', 'si', [$stage, $id]);
    }
    hr_candidate_log($id, 'Moved to ' . hr_candidate_stages()[$stage] . ($note !== '' ? ' — ' . $note : ''));
    hr_audit('stage', 'candidate', $id, $c['full_name'] . ' → ' . hr_candidate_stages()[$stage]);
}

/**
 * Hires a candidate: creates the employee from what the candidate and the
 * opening already say, links them, marks the candidate hired, fills the
 * opening when every position is taken, and starts onboarding.
 */
function hr_candidate_hire(int $id, array $p): int
{
    $c = hr_candidate($id, 'edit');
    if (!$c) {
        fabo_deny('That candidate is not available to you.', 404);
    }
    if (hr_scope('employees', 'create') !== 'org') {
        fabo_deny('Hiring creates an employee record, which needs organisation-wide rights to add employees.', 403);
    }
    if ($c['stage'] === 'hired') {
        throw new InvalidArgumentException('This candidate has already been hired.');
    }
    $o = hr_opening((int) $c['opening_id']);
    $doj = hr_date($p['date_of_joining'] ?? '', false, 'Date of joining') ?? ($c['offer_join_date'] ?: null);
    if (!$doj) {
        throw new InvalidArgumentException('Give the date of joining.');
    }
    $empId = hr_tx(static function () use ($c, $o, $doj, $id) {
        $empId = hr_employee_save(null, [
            'full_name' => $c['full_name'], 'work_email' => '', 'phone' => $c['phone'] ?? '',
            'department_id' => $o['department_id'], 'designation_id' => $o['designation_id'], 'manager_id' => $o['hiring_manager_id'],
            'location_id' => $o['location_id'], 'employment_type_id' => $o['employment_type_id'],
            'date_of_joining' => $doj, 'status' => 'probation', 'notes' => 'Hired through recruitment for “' . $o['title'] . '”.',
        ], false);
        fabo_exec("UPDATE hr_candidates SET stage = 'hired', employee_id = ?, offer_join_date = ? WHERE id = ?", 'isi', [$empId, $doj, $id]);
        $hired = (int) (fabo_one("SELECT COUNT(*) n FROM hr_candidates WHERE opening_id = ? AND stage = 'hired'", 'i', [(int) $o['id']])['n'] ?? 0);
        if ($hired >= (int) $o['positions']) {
            fabo_exec("UPDATE hr_job_openings SET status = 'filled' WHERE id = ?", 'i', [(int) $o['id']]);
        }
        return $empId;
    });
    hr_candidate_log($id, 'Hired — employee record created, joining ' . hr_fmt_date($doj));
    hr_audit('hire', 'candidate', $id, $c['full_name'] . ' hired for ' . $o['title']);
    if (hr_setting('onboard_auto') === '1' && hr_checklist_default('onboarding')) {
        try {
            hr_lifecycle_start($empId, 'onboarding');
        } catch (InvalidArgumentException $e) {
            // Onboarding can be started by hand later.
        }
    }
    return $empId;
}

function hr_interviews_for(int $candId): array
{
    return fabo_all('SELECT i.*, e.full_name AS interviewer FROM hr_interviews i LEFT JOIN hr_employees e ON e.id = i.interviewer_id
                     WHERE i.candidate_id = ? AND i.org_id = ? ORDER BY i.scheduled_at', 'ii', [$candId, hr_org()]);
}

function hr_interview_save(int $candId, array $p): int
{
    $c = hr_candidate($candId, 'edit');
    if (!$c) {
        fabo_deny('That candidate is not available to you.', 404);
    }
    $when = trim((string) ($p['scheduled_at'] ?? ''));
    $ts = strtotime(str_replace('T', ' ', $when));
    if ($when === '' || !$ts) {
        throw new InvalidArgumentException('Give the interview date and time.');
    }
    $mode = (string) ($p['mode'] ?? 'in_person');
    if (!isset(hr_interview_modes()[$mode])) {
        $mode = 'in_person';
    }
    $iv = hr_ref_id($p['interviewer_id'] ?? 0, 'hr_employees', 'Interviewer');
    $id = fabo_exec('INSERT INTO hr_interviews (org_id, candidate_id, round, scheduled_at, mode, interviewer_id, location, created_by) VALUES (?,?,?,?,?,?,?,?)',
                    'iisssiss', [hr_org(), $candId, hr_str($p['round'] ?? '', 60, true, 'Round'), date('Y-m-d H:i:s', $ts), $mode, $iv,
                                 hr_str($p['location'] ?? '', 190) ?: null, hr_actor()]);
    if (in_array($c['stage'], ['applied', 'screening'], true)) {
        fabo_exec("UPDATE hr_candidates SET stage = 'interview' WHERE id = ?", 'i', [$candId]);
    }
    hr_candidate_log($candId, 'Interview booked: ' . $p['round'] . ' on ' . date('d M Y H:i', $ts));
    hr_audit('create', 'interview', $id, 'Interview booked for ' . $c['full_name']);
    if ($iv) {
        hr_notify_employee($iv, 'interview-' . $id, 'You are interviewing ' . $c['full_name'],
            $p['round'] . ' for “' . $c['opening'] . '” on ' . date('D j M, H:i', $ts) . '. Record your feedback on My HR afterwards.', 'hr.php?p=recruitment&tab=myinterviews', false);
    }
    return $id;
}

/** May the viewer write the feedback for this interview? The interviewer, or whoever edits the opening. */
function hr_interview_can_feedback(array $i): bool
{
    $self = hr_self_emp();
    if ($self && (int) $i['interviewer_id'] === (int) $self['id']) {
        return true;
    }
    return (bool) hr_candidate((int) $i['candidate_id'], 'edit');
}

function hr_interview_feedback(int $id, array $p): void
{
    $i = fabo_one('SELECT * FROM hr_interviews WHERE id = ? AND org_id = ?', 'ii', [$id, hr_org()]);
    if (!$i || !hr_interview_can_feedback($i)) {
        fabo_deny('That interview is not yours.', 404);
    }
    $status = (string) ($p['status'] ?? 'completed');
    if (!in_array($status, ['completed', 'cancelled', 'no_show'], true)) {
        throw new InvalidArgumentException('Choose what happened.');
    }
    $result = (string) ($p['result'] ?? '');
    if ($status === 'completed' && !isset(hr_interview_results()[$result])) {
        throw new InvalidArgumentException('Choose your recommendation.');
    }
    $rating = (int) ($p['rating'] ?? 0);
    fabo_exec('UPDATE hr_interviews SET status = ?, result = ?, rating = ?, feedback = ?, feedback_by = ?, feedback_at = NOW() WHERE id = ?',
              'ssissi', [$status, $status === 'completed' ? $result : null, $rating >= 1 && $rating <= 5 ? $rating : null,
                         hr_str($p['feedback'] ?? '', 5000, $status === 'completed', 'Feedback') ?: null, hr_actor(), $id]);
    hr_candidate_log((int) $i['candidate_id'], $i['round'] . ': ' . str_replace('_', ' ', $status) . ($result ? ' — ' . hr_interview_results()[$result] : ''));
    hr_audit('feedback', 'interview', $id, 'Interview feedback recorded');
}

/** Interviews where the viewer is the interviewer and feedback is still due. */
function hr_interviews_mine(bool $pendingOnly = true): array
{
    $self = hr_self_emp();
    if (!$self) {
        return [];
    }
    return fabo_all('SELECT i.*, c.full_name AS candidate, c.id AS cand_id, o.title AS opening FROM hr_interviews i
                       JOIN hr_candidates c ON c.id = i.candidate_id JOIN hr_job_openings o ON o.id = c.opening_id
                      WHERE i.org_id = ? AND i.interviewer_id = ?' . ($pendingOnly ? " AND i.status = 'scheduled'" : '') . ' ORDER BY i.scheduled_at',
                    'ii', [hr_org(), (int) $self['id']]);
}

/** An offer letter for a candidate at the offer stage. */
function hr_offer_letter(array $c): HrPdf
{
    $o = hr_opening((int) $c['opening_id']);
    $role = trim(($o['designation'] ?: $o['title']) . ($o['department'] ? ', ' . $o['department'] : ''));
    $paras = [
        'Dear ' . strtok($c['full_name'], ' ') . ',',
        'We are pleased to offer you the position of ' . $role . ($o['location'] ? ' at our ' . $o['location'] . ' location' : '') . '.',
    ];
    if ($c['offer_salary'] !== null) {
        $paras[] = 'Your annual cost to company will be Rs. ' . fabo_thousands((float) $c['offer_salary'], 2)
                 . ' (' . trim(hr_amount_words((float) $c['offer_salary'])) . '), with the detailed salary structure given in your appointment letter.';
    }
    if ($c['offer_join_date']) {
        $paras[] = 'We expect you to join on ' . hr_fmt_date($c['offer_join_date']) . '. Please bring proof of identity, address, education and previous employment on your first day.';
    }
    $paras[] = 'This offer is subject to satisfactory verification of your documents and references. Please confirm your acceptance by signing and returning a copy of this letter.';
    $paras[] = 'We look forward to welcoming you.';
    return hr_letter_pdf('OFR/' . date('Y') . '/' . str_pad((string) $c['id'], 4, '0', STR_PAD_LEFT),
                         array_values(array_filter([$c['full_name'], $c['email'], $c['phone']])), 'Offer of employment', $paras);
}

/* ============================================================ performance */

function hr_review_stages(): array
{
    return ['self' => 'Self assessment', 'manager' => 'Manager review', 'done' => 'Completed'];
}

function hr_rating_labels(): array
{
    return [1 => 'Needs improvement', 2 => 'Below expectations', 3 => 'Meets expectations', 4 => 'Exceeds expectations', 5 => 'Outstanding'];
}

function hr_rating_words($r): string
{
    if ($r === null || $r === '') {
        return '—';
    }
    $f = (float) $r;
    return rtrim(rtrim(number_format($f, 1), '0'), '.') . ' · ' . (hr_rating_labels()[(int) round($f)] ?? '');
}

function hr_cycles(): array
{
    return fabo_all('SELECT c.*, (SELECT COUNT(*) FROM hr_reviews r WHERE r.cycle_id = c.id) AS reviews,
                            (SELECT COUNT(*) FROM hr_reviews r WHERE r.cycle_id = c.id AND r.stage = "done") AS done
                       FROM hr_review_cycles c WHERE c.org_id = ? ORDER BY c.period_to DESC, c.id DESC', 'i', [hr_org()]);
}

function hr_cycle(int $id): ?array
{
    return fabo_one('SELECT * FROM hr_review_cycles WHERE id = ? AND org_id = ?', 'ii', [$id, hr_org()]);
}

/**
 * Creates a cycle and a review for every current employee in it (optionally
 * one department). The reviewer is each employee's reporting manager today.
 */
function hr_cycle_create(array $p): array
{
    if (!hr_is_admin() && hr_scope('performance', 'configure') !== 'org') {
        fabo_deny('Only HR starts a review cycle.', 403);
    }
    $name = hr_str($p['name'] ?? '', 120, true, 'Cycle name');
    $from = hr_date($p['period_from'] ?? '', true, 'Period from');
    $to = hr_date($p['period_to'] ?? '', true, 'Period to');
    if ($to < $from) {
        throw new InvalidArgumentException('The period ends before it starts.');
    }
    $dept = hr_ref_id($p['department_id'] ?? 0, 'hr_departments', 'Department');
    $emps = fabo_all("SELECT id, manager_id FROM hr_employees WHERE org_id = ? AND status IN ('active','probation','notice')"
                   . ($dept ? ' AND department_id = ' . (int) $dept : '') . ' AND (date_of_joining IS NULL OR date_of_joining <= ?)', 'is', [hr_org(), $to]);
    if (!$emps) {
        throw new InvalidArgumentException('Nobody is employed in that period' . ($dept ? ' in that department' : '') . '.');
    }
    return hr_tx(static function () use ($name, $from, $to, $p, $emps) {
        $id = fabo_exec('INSERT INTO hr_review_cycles (org_id, name, period_from, period_to, self_due, manager_due, created_by) VALUES (?,?,?,?,?,?,?)',
                        'issssss', [hr_org(), $name, $from, $to, hr_date($p['self_due'] ?? '', false, 'Self-assessment due'),
                                    hr_date($p['manager_due'] ?? '', false, 'Manager review due'), hr_actor()]);
        foreach ($emps as $e) {
            fabo_exec('INSERT INTO hr_reviews (org_id, cycle_id, employee_id, reviewer_id) VALUES (?,?,?,?)', 'iiii',
                      [hr_org(), $id, (int) $e['id'], $e['manager_id'] ? (int) $e['manager_id'] : null]);
        }
        hr_audit('create', 'review_cycle', $id, 'Review cycle started: ' . $name . ' (' . count($emps) . ' reviews)');
        foreach ($emps as $e) {
            hr_notify_employee((int) $e['id'], 'review-open-' . $id, 'Your performance review is open',
                $name . ': add your goals and complete your self-assessment on My HR.', 'hr.php?p=performance', false);
        }
        return [$id, count($emps)];
    });
}

function hr_cycle_close(int $id, bool $close): void
{
    if (!hr_is_admin() && hr_scope('performance', 'configure') !== 'org') {
        fabo_deny('Only HR closes a review cycle.', 403);
    }
    fabo_exec('UPDATE hr_review_cycles SET status = ? WHERE id = ? AND org_id = ?', 'sii', [$close ? 'closed' : 'open', $id, hr_org()]);
    hr_audit($close ? 'close' : 'reopen', 'review_cycle', $id, 'Review cycle ' . ($close ? 'closed' : 'reopened'));
}

function hr_reviews_list(array $f): array
{
    $sc = hr_scope_sql('performance', 'view', 'e');
    $where = ' WHERE r.org_id = ?';
    if (!empty($f['cycle'])) {
        $where .= ' AND r.cycle_id = ' . (int) $f['cycle'];
    }
    if (!empty($f['department'])) {
        $where .= ' AND e.department_id = ' . (int) $f['department'];
    }
    if (($f['stage'] ?? '') !== '' && isset(hr_review_stages()[$f['stage']])) {
        $where .= " AND r.stage = '" . $f['stage'] . "'";
    }
    return fabo_all('SELECT r.*, e.full_name, e.emp_code, e.photo_file, d.name AS department, m.full_name AS reviewer, c.name AS cycle, c.status AS cycle_status,
                            (SELECT COUNT(*) FROM hr_review_goals g WHERE g.review_id = r.id) AS goals
                       FROM hr_reviews r JOIN hr_employees e ON e.id = r.employee_id JOIN hr_review_cycles c ON c.id = r.cycle_id
                       LEFT JOIN hr_departments d ON d.id = e.department_id LEFT JOIN hr_employees m ON m.id = r.reviewer_id'
                  . $where . $sc['sql'] . ' ORDER BY c.period_to DESC, e.full_name LIMIT 2000', 'i', [hr_org()]);
}

function hr_review(int $id): ?array
{
    $r = fabo_one('SELECT r.*, e.full_name, e.emp_code, e.photo_file, e.manager_id, d.name AS department, g.name AS designation,
                          m.full_name AS reviewer, c.name AS cycle, c.status AS cycle_status, c.period_from, c.period_to, c.self_due, c.manager_due
                     FROM hr_reviews r JOIN hr_employees e ON e.id = r.employee_id JOIN hr_review_cycles c ON c.id = r.cycle_id
                     LEFT JOIN hr_departments d ON d.id = e.department_id LEFT JOIN hr_designations g ON g.id = e.designation_id
                     LEFT JOIN hr_employees m ON m.id = r.reviewer_id
                    WHERE r.id = ? AND r.org_id = ?', 'ii', [$id, hr_org()]);
    return $r && hr_emp_allowed((int) $r['employee_id'], 'performance', 'view') ? $r : null;
}

function hr_review_goals(int $reviewId): array
{
    return fabo_all('SELECT * FROM hr_review_goals WHERE review_id = ? ORDER BY sort, id', 'i', [$reviewId]);
}

/**
 * Who the viewer is to this review: 'self' (the employee), 'manager' (the
 * reviewer, or anyone above with team rights), 'hr' (organisation-wide), or
 * null when they may only look.
 */
function hr_review_role(array $r): ?string
{
    $self = hr_self_emp();
    $isSelf = $self && (int) $self['id'] === (int) $r['employee_id'];
    if (hr_is_admin() || (!$isSelf && hr_scope('performance', 'approve') === 'org')) {
        return 'hr';
    }
    if ($isSelf) {
        return hr_can('performance', 'edit') ? 'self' : null;
    }
    return hr_emp_allowed((int) $r['employee_id'], 'performance', 'edit') ? 'manager' : null;
}

/** Saves the goal list (title, target, weight) while the review is open. */
function hr_review_goals_save(int $id, array $p): void
{
    $r = hr_review($id);
    $role = $r ? hr_review_role($r) : null;
    if (!$r || !$role) {
        fabo_deny('You cannot change this review.', 403);
    }
    if ($r['stage'] === 'done' || $r['cycle_status'] === 'closed') {
        throw new InvalidArgumentException('This review is completed. Goals can no longer change.');
    }
    if ($role === 'self' && $r['stage'] !== 'self') {
        throw new InvalidArgumentException('Your manager is reviewing now. Ask them to change the goals.');
    }
    $rows = [];
    foreach ((array) ($p['goal_title'] ?? []) as $i => $t) {
        $t = hr_str($t, 190);
        if ($t === '') {
            continue;
        }
        $w = hr_dec($p['goal_weight'][$i] ?? '0', 2, false, 'Weight', 0, 100) ?? '0';
        $rows[] = [(int) ($p['goal_id'][$i] ?? 0), $t, hr_str($p['goal_target'][$i] ?? '', 255) ?: null, $w];
    }
    if (!$rows) {
        throw new InvalidArgumentException('Add at least one goal.');
    }
    $sum = array_sum(array_map(static fn($g) => (float) $g[3], $rows));
    if (abs($sum - 100) > 0.01 && abs($sum) > 0.001) {
        throw new InvalidArgumentException('Goal weights add up to ' . hr_fmt_days($sum) . '%. Make them add up to 100%, or leave every weight at 0 to weigh goals equally.');
    }
    hr_tx(static function () use ($id, $rows) {
        $keep = [];
        foreach ($rows as $n => [$gid, $t, $target, $w]) {
            if ($gid && fabo_one('SELECT id FROM hr_review_goals WHERE id = ? AND review_id = ?', 'ii', [$gid, $id])) {
                fabo_exec('UPDATE hr_review_goals SET title = ?, target = ?, weight = ?, sort = ? WHERE id = ?', 'sssii', [$t, $target, $w, $n, $gid]);
                $keep[] = $gid;
            } else {
                $keep[] = fabo_exec('INSERT INTO hr_review_goals (review_id, title, target, weight, added_by, sort) VALUES (?,?,?,?,?,?)', 'issssi',
                                    [$id, $t, $target, $w, hr_actor(), $n]);
            }
        }
        fabo_exec('DELETE FROM hr_review_goals WHERE review_id = ? AND id NOT IN (' . implode(',', array_map('intval', $keep)) . ')', 'i', [$id]);
    });
    hr_audit('goals', 'review', $id, count($rows) . ' goal(s) saved for ' . $r['full_name']);
}

/** Weighted average of ratings on the goals (equal weights when none are set). */
function hr_review_weighted(array $goals, string $col): ?float
{
    $sum = 0.0;
    $wt = 0.0;
    $equal = array_sum(array_map(static fn($g) => (float) $g['weight'], $goals)) <= 0;
    foreach ($goals as $g) {
        if ($g[$col] === null || $g[$col] === '') {
            continue;
        }
        $w = $equal ? 1.0 : (float) $g['weight'];
        $sum += $w * (float) $g[$col];
        $wt += $w;
    }
    return $wt > 0 ? round($sum / $wt, 1) : null;
}

/**
 * The employee's self-assessment ($who = 'self') or the manager's rating
 * ($who = 'manager'). $submit moves the review on; without it the ratings are
 * saved as a draft.
 */
function hr_review_assess(int $id, string $who, array $p, bool $submit): string
{
    $r = hr_review($id);
    $role = $r ? hr_review_role($r) : null;
    if (!$r || !$role || ($who === 'self' && $role !== 'self') || ($who === 'manager' && !in_array($role, ['manager', 'hr'], true))) {
        fabo_deny('You cannot do that on this review.', 403);
    }
    if ($r['cycle_status'] === 'closed' || $r['stage'] === 'done') {
        throw new InvalidArgumentException('This review is completed.');
    }
    if ($who === 'self' && $r['stage'] !== 'self') {
        throw new InvalidArgumentException('You have already submitted your self-assessment.');
    }
    if ($who === 'manager' && $r['stage'] === 'self' && hr_setting('perf_self_first') === '1' && $role !== 'hr') {
        throw new InvalidArgumentException('The employee has not submitted their self-assessment yet.');
    }
    $goals = hr_review_goals($id);
    if (!$goals) {
        throw new InvalidArgumentException('Add the goals first.');
    }
    $col = $who === 'self' ? 'self' : 'manager';
    foreach ($goals as $g) {
        $rt = (int) ($p['rating'][$g['id']] ?? 0);
        if ($submit && ($rt < 1 || $rt > 5)) {
            throw new InvalidArgumentException('Rate every goal from 1 to 5 before submitting (“' . $g['title'] . '” has no rating).');
        }
        fabo_exec('UPDATE hr_review_goals SET ' . $col . '_rating = ?, ' . $col . '_note = ? WHERE id = ?', 'isi',
                  [$rt >= 1 && $rt <= 5 ? $rt : null, hr_str($p['note'][$g['id']] ?? '', 500) ?: null, (int) $g['id']]);
    }
    $goals = hr_review_goals($id);
    $avg = hr_review_weighted($goals, $col . '_rating');
    $comment = hr_str($p['comment'] ?? '', 5000) ?: null;
    if ($who === 'self') {
        fabo_exec('UPDATE hr_reviews SET self_comment = ?, self_rating = ?' . ($submit ? ", stage = 'manager', self_at = NOW()" : '') . ' WHERE id = ?',
                  'ssi', [$comment, $avg, $id]);
        if ($submit) {
            if ($r['reviewer_id']) {
                hr_notify_employee((int) $r['reviewer_id'], 'review-self-' . $id, $r['full_name'] . ' completed their self-assessment',
                    $r['cycle'] . ': it is your turn to rate.', 'hr.php?p=performance&id=' . $id, false);
            }
            hr_audit('self_submit', 'review', $id, 'Self-assessment submitted by ' . $r['full_name']);
            return 'Self-assessment submitted. Your manager reviews it next.';
        }
        return 'Saved. Submit when you are ready.';
    }
    fabo_exec('UPDATE hr_reviews SET manager_comment = ?, manager_rating = ?' . ($submit ? ", stage = 'done', final_rating = ?, manager_at = NOW(), manager_by = ?" : '')
            . ' WHERE id = ?', $submit ? 'ssssi' : 'ssi', $submit ? [$comment, $avg, $avg, hr_actor(), $id] : [$comment, $avg, $id]);
    if ($submit) {
        hr_notify_employee((int) $r['employee_id'], 'review-done-' . $id, 'Your performance review is complete',
            $r['cycle'] . ': final rating ' . hr_rating_words($avg) . '.', 'hr.php?p=performance&id=' . $id, false);
        hr_audit('manager_submit', 'review', $id, 'Manager review submitted for ' . $r['full_name'] . ' (' . $avg . ')');
        return 'Review completed. The final rating is ' . hr_rating_words($avg) . '.';
    }
    return 'Saved. Submit when you are ready.';
}

/** HR's override of the final rating, with a reason; or reopening a completed review. */
function hr_review_finalise(int $id, array $p): string
{
    $r = hr_review($id);
    if (!$r || hr_review_role($r) !== 'hr') {
        fabo_deny('Only HR changes a final rating.', 403);
    }
    if (!empty($p['reopen'])) {
        fabo_exec("UPDATE hr_reviews SET stage = 'manager', final_rating = NULL WHERE id = ?", 'i', [$id]);
        hr_audit('reopen', 'review', $id, 'Review reopened for ' . $r['full_name']);
        return 'Reopened for the manager.';
    }
    $rating = hr_dec($p['final_rating'] ?? '', 1, true, 'Final rating', 1, 5);
    $note = hr_str($p['final_note'] ?? '', 500, true, 'A reason for the change');
    fabo_exec("UPDATE hr_reviews SET final_rating = ?, final_note = ?, stage = 'done' WHERE id = ?", 'ssi', [$rating, $note, $id]);
    hr_audit('final', 'review', $id, 'Final rating set to ' . $rating . ' for ' . $r['full_name'] . ': ' . $note);
    return 'Final rating saved.';
}

/** Reviews waiting on the viewer: their own self-assessment, and their team's manager reviews. */
function hr_reviews_waiting_me(): array
{
    $self = hr_self_emp();
    if (!$self && !hr_is_admin()) {
        return [];
    }
    $out = [];
    if ($self && hr_can('performance', 'edit')) {
        foreach (fabo_all("SELECT r.id, r.stage, c.name AS cycle, e.full_name, r.employee_id FROM hr_reviews r JOIN hr_review_cycles c ON c.id = r.cycle_id
                           JOIN hr_employees e ON e.id = r.employee_id
                           WHERE r.org_id = ? AND c.status = 'open' AND ((r.employee_id = ? AND r.stage = 'self') OR (r.reviewer_id = ? AND r.stage <> 'done'))",
                          'iii', [hr_org(), (int) $self['id'], (int) $self['id']]) as $r) {
            if ((int) $r['employee_id'] !== (int) $self['id'] && $r['stage'] === 'self' && hr_setting('perf_self_first') === '1') {
                continue;     // not the manager's turn yet
            }
            $out[] = $r;
        }
    }
    return $out;
}

/* =============================================================== training */

function hr_training_modes(): array
{
    return ['classroom' => 'Classroom', 'online' => 'Online / e-learning', 'virtual' => 'Live virtual', 'on_the_job' => 'On the job', 'external' => 'External'];
}

function hr_training_statuses(): array
{
    return ['planned' => 'Planned', 'open' => 'Open for enrolment', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
}

function hr_enrolment_statuses(): array
{
    return ['enrolled' => 'Enrolled', 'completed' => 'Completed', 'no_show' => 'No show', 'cancelled' => 'Cancelled'];
}

function hr_trainings(string $when = 'upcoming'): array
{
    $where = ' WHERE t.org_id = ?';
    if ($when === 'upcoming') {
        $where .= " AND t.status IN ('planned','open') AND COALESCE(t.end_date, t.start_date) >= CURDATE()";
    } elseif ($when === 'past') {
        $where .= " AND (t.status IN ('completed','cancelled') OR COALESCE(t.end_date, t.start_date) < CURDATE())";
    }
    return fabo_all('SELECT t.*, m.name AS category,
                            (SELECT COUNT(*) FROM hr_training_enrolments x WHERE x.training_id = t.id AND x.status <> "cancelled") AS enrolled,
                            (SELECT COUNT(*) FROM hr_training_enrolments x WHERE x.training_id = t.id AND x.status = "completed") AS completed
                       FROM hr_trainings t LEFT JOIN hr_masters m ON m.id = t.category_id' . $where
                  . ' ORDER BY t.start_date ' . ($when === 'past' ? 'DESC' : 'ASC') . ' LIMIT 500', 'i', [hr_org()]);
}

function hr_training(int $id): ?array
{
    return fabo_one('SELECT t.*, m.name AS category,
                            (SELECT COUNT(*) FROM hr_training_enrolments x WHERE x.training_id = t.id AND x.status <> "cancelled") AS enrolled
                       FROM hr_trainings t LEFT JOIN hr_masters m ON m.id = t.category_id WHERE t.id = ? AND t.org_id = ?', 'ii', [$id, hr_org()]);
}

function hr_training_save(?int $id, array $p): int
{
    if (hr_scope('training', $id ? 'edit' : 'create') !== 'org') {
        fabo_deny('Only HR plans training.', 403);
    }
    $status = (string) ($p['status'] ?? 'planned');
    if (!isset(hr_training_statuses()[$status])) {
        throw new InvalidArgumentException('Choose a status.');
    }
    $mode = (string) ($p['mode'] ?? 'classroom');
    if (!isset(hr_training_modes()[$mode])) {
        throw new InvalidArgumentException('Choose how it is delivered.');
    }
    $start = hr_date($p['start_date'] ?? '', true, 'Start date');
    $end = hr_date($p['end_date'] ?? '', false, 'End date');
    if ($end && $end < $start) {
        throw new InvalidArgumentException('The end date is before the start date.');
    }
    $v = [
        'title' => hr_str($p['title'] ?? '', 160, true, 'Title'),
        'category_id' => hr_ref_id($p['category_id'] ?? 0, 'hr_masters', 'Category'),
        'mode' => $mode,
        'trainer' => hr_str($p['trainer'] ?? '', 160) ?: null,
        'location' => hr_str($p['location'] ?? '', 190) ?: null,
        'start_date' => $start,
        'end_date' => $end,
        'hours' => hr_dec($p['hours'] ?? '', 1, false, 'Hours', 0, 10000),
        'capacity' => ($p['capacity'] ?? '') === '' ? null : max(1, min(10000, (int) $p['capacity'])),
        'mandatory' => !empty($p['mandatory']) ? 1 : 0,
        'open_enrol' => !empty($p['open_enrol']) ? 1 : 0,
        'description' => hr_str($p['description'] ?? '', 5000) ?: null,
        'status' => $status,
    ];
    $types = 'sissssssiiiss';
    if ($id) {
        if (!hr_training($id)) {
            throw new InvalidArgumentException('That session no longer exists.');
        }
        fabo_exec('UPDATE hr_trainings SET ' . implode(', ', array_map(static fn($c) => $c . ' = ?', array_keys($v))) . ' WHERE id = ? AND org_id = ?',
                  $types . 'ii', array_merge(array_values($v), [$id, hr_org()]));
        hr_audit('update', 'training', $id, 'Training updated: ' . $v['title']);
        return $id;
    }
    $id = fabo_exec('INSERT INTO hr_trainings (org_id, ' . implode(', ', array_keys($v)) . ', created_by) VALUES (?, '
                    . implode(', ', array_fill(0, count($v), '?')) . ', ?)', 'i' . $types . 's', array_merge([hr_org()], array_values($v), [hr_actor()]));
    hr_audit('create', 'training', $id, 'Training planned: ' . $v['title']);
    return $id;
}

function hr_training_delete(int $id): void
{
    $t = hr_training($id);
    if (!$t || hr_scope('training', 'delete') !== 'org') {
        fabo_deny('You cannot delete this session.', 403);
    }
    if ((int) (fabo_one("SELECT COUNT(*) n FROM hr_training_enrolments WHERE training_id = ? AND status = 'completed'", 'i', [$id])['n'] ?? 0) > 0) {
        throw new InvalidArgumentException('People have completed this session. Mark it cancelled instead so their records stay.');
    }
    fabo_exec('DELETE FROM hr_training_enrolments WHERE training_id = ?', 'i', [$id]);
    fabo_exec('DELETE FROM hr_trainings WHERE id = ? AND org_id = ?', 'ii', [$id, hr_org()]);
    hr_audit('delete', 'training', $id, 'Training deleted: ' . $t['title'], $t, null);
}

function hr_enrolments(int $trainingId): array
{
    $sc = hr_scope_sql('training', 'view', 'e');
    return fabo_all('SELECT x.*, e.full_name, e.emp_code, e.photo_file, d.name AS department FROM hr_training_enrolments x
                       JOIN hr_employees e ON e.id = x.employee_id LEFT JOIN hr_departments d ON d.id = e.department_id
                      WHERE x.training_id = ? AND x.org_id = ?' . $sc['sql'] . ' ORDER BY e.full_name', 'ii', [$trainingId, hr_org()]);
}

/**
 * Enrols employees. HR enrols anyone; an employee enrols themselves in a
 * session open for enrolment. Capacity is enforced either way.
 */
function hr_enrol(int $trainingId, array $empIds): int
{
    $t = hr_training($trainingId);
    if (!$t || in_array($t['status'], ['completed', 'cancelled'], true)) {
        throw new InvalidArgumentException('This session is not taking enrolments.');
    }
    $self = hr_self_emp();
    $hr = hr_is_admin() || hr_scope('training', 'edit') === 'org';
    $n = 0;
    foreach (array_unique(array_map('intval', $empIds)) as $empId) {
        $own = $self && (int) $self['id'] === $empId;
        if (!$hr && !($own && $t['open_enrol'] && hr_can('training', 'create'))) {
            fabo_deny('You cannot enrol people in this session.', 403);
        }
        if (!$hr && !hr_emp_allowed($empId, 'training', 'create')) {
            continue;
        }
        $cur = (int) (fabo_one("SELECT COUNT(*) n FROM hr_training_enrolments WHERE training_id = ? AND status <> 'cancelled'", 'i', [$trainingId])['n'] ?? 0);
        if ($t['capacity'] && $cur >= (int) $t['capacity']) {
            throw new InvalidArgumentException('The session is full (' . (int) $t['capacity'] . ' places).' . ($n ? ' ' . $n . ' enrolled before it filled.' : ''));
        }
        $have = fabo_one('SELECT id, status FROM hr_training_enrolments WHERE training_id = ? AND employee_id = ?', 'ii', [$trainingId, $empId]);
        if ($have && $have['status'] !== 'cancelled') {
            continue;
        }
        if ($have) {
            fabo_exec("UPDATE hr_training_enrolments SET status = 'enrolled' WHERE id = ?", 'i', [(int) $have['id']]);
        } else {
            fabo_exec('INSERT INTO hr_training_enrolments (org_id, training_id, employee_id, created_by) VALUES (?,?,?,?)', 'iiis',
                      [hr_org(), $trainingId, $empId, hr_actor()]);
        }
        if (!$own) {
            hr_notify_employee($empId, 'training-' . $trainingId, 'You are enrolled: ' . $t['title'],
                hr_fmt_date($t['start_date']) . ($t['location'] ? ' · ' . $t['location'] : '') . ($t['mandatory'] ? ' · Mandatory' : ''), 'hr.php?p=training', false);
        }
        $n++;
    }
    hr_audit('enrol', 'training', $trainingId, $n . ' enrolled in ' . $t['title']);
    return $n;
}

/** HR records the outcome for one attendee; the attendee may cancel their own enrolment or give feedback. */
function hr_enrolment_update(int $id, array $p): string
{
    $x = fabo_one('SELECT x.*, t.title, t.status AS t_status FROM hr_training_enrolments x JOIN hr_trainings t ON t.id = x.training_id
                    WHERE x.id = ? AND x.org_id = ?', 'ii', [$id, hr_org()]);
    if (!$x || !hr_emp_allowed((int) $x['employee_id'], 'training', 'view')) {
        fabo_deny('That enrolment is not available to you.', 404);
    }
    $self = hr_self_emp();
    $own = $self && (int) $self['id'] === (int) $x['employee_id'];
    $hr = hr_is_admin() || hr_scope('training', 'edit') === 'org';
    $what = (string) ($p['what'] ?? '');
    if ($what === 'feedback' && $own) {
        $rt = (int) ($p['rating'] ?? 0);
        fabo_exec('UPDATE hr_training_enrolments SET rating = ?, feedback = ? WHERE id = ?', 'isi',
                  [$rt >= 1 && $rt <= 5 ? $rt : null, hr_str($p['feedback'] ?? '', 1000) ?: null, $id]);
        return 'Thank you for your feedback.';
    }
    if ($what === 'cancel' && ($own || $hr)) {
        if ($x['status'] === 'completed') {
            throw new InvalidArgumentException('A completed session cannot be cancelled.');
        }
        fabo_exec("UPDATE hr_training_enrolments SET status = 'cancelled' WHERE id = ?", 'i', [$id]);
        hr_audit('cancel', 'training_enrolment', $id, 'Enrolment cancelled: ' . $x['title']);
        return 'Enrolment cancelled.';
    }
    if ($what === 'outcome' && $hr) {
        $st = (string) ($p['status'] ?? '');
        if (!isset(hr_enrolment_statuses()[$st])) {
            throw new InvalidArgumentException('Choose the outcome.');
        }
        fabo_exec('UPDATE hr_training_enrolments SET status = ?, score = ?, completed_on = ? WHERE id = ?', 'sssi',
                  [$st, hr_dec($p['score'] ?? '', 1, false, 'Score', 0, 1000), $st === 'completed' ? (hr_date($p['completed_on'] ?? '') ?? date('Y-m-d')) : null, $id]);
        hr_audit('outcome', 'training_enrolment', $id, $x['title'] . ': ' . $st);
        return 'Saved.';
    }
    fabo_deny('You cannot do that.', 403);
}

/** Marks every enrolled attendee of a session completed, and the session too. */
function hr_training_complete_all(int $id): int
{
    $t = hr_training($id);
    if (!$t || (!hr_is_admin() && hr_scope('training', 'edit') !== 'org')) {
        fabo_deny('Only HR closes a session.', 403);
    }
    $n = fabo_exec("UPDATE hr_training_enrolments SET status = 'completed', completed_on = ? WHERE training_id = ? AND status = 'enrolled'", 'si',
                   [$t['end_date'] ?: $t['start_date'], $id]);
    fabo_exec("UPDATE hr_trainings SET status = 'completed' WHERE id = ?", 'i', [$id]);
    hr_audit('complete', 'training', $id, 'Session completed: ' . $t['title']);
    return (int) $n;
}

/** An employee's training record. */
function hr_emp_trainings(int $empId): array
{
    return fabo_all('SELECT x.*, t.title, t.start_date, t.end_date, t.hours, t.mode, t.mandatory, t.status AS t_status FROM hr_training_enrolments x
                       JOIN hr_trainings t ON t.id = x.training_id WHERE x.employee_id = ? AND x.org_id = ? ORDER BY t.start_date DESC', 'ii', [$empId, hr_org()]);
}

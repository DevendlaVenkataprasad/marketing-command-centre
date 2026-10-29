<?php
/**
 * Interview assessments — the RTIH Interview Assessment Sheet, online.
 *
 *   SHORTLIST    HR enters (or imports, or brings over from Recruitment) the
 *                shortlisted candidates with their rank and written-test marks.
 *   ASSIGN       HR ticks several of them and assigns them to one interviewer
 *                for a round. The interviewer needs no portal account: they
 *                are emailed a private link to their own interview dashboard.
 *   ASSESS       On that dashboard the interviewer fills one sheet per
 *                candidate — ten competencies scored 1–5 with comments, the
 *                overall recommendation, strengths, concerns, observations —
 *                saves a draft as they go, and submits. A submitted sheet is
 *                locked; HR can reopen it.
 *   RESULTS      HR sees every sheet per candidate, the totals out of 50 and
 *                averages out of 5, prints a sheet, exports the lot, and
 *                records the decision.
 *
 * The public page (interview-panel.php) has no HR context, so every function
 * it calls takes the org from the row it already holds, never from hr_org().
 */

/** The ten competencies of Part A: [title, key indicators observed]. */
function hr_assess_params(): array
{
    return [
        1  => ['Educational & Professional Qualifications', 'Relevance of degree, certifications, and academic performance to role requirements'],
        2  => ['Relevant Work Experience', 'Depth and applicability of past experience; achievement highlights; progression'],
        3  => ['Technical / Domain Knowledge', 'Accuracy and depth of answers to role-specific technical questions'],
        4  => ['Problem-Solving & Analytical Ability', 'Ability to break down complex problems; structured reasoning; case/scenario handling'],
        5  => ['Communication Skills (Verbal)', 'Clarity, confidence, articulation, listening, and ability to explain ideas'],
        6  => ['Teamwork & Collaboration', 'Evidence of working effectively in teams; handling conflicts; peer learning'],
        7  => ['Initiative & Innovation', 'Examples of proactively identifying opportunities, creating solutions, or improving processes'],
        8  => ['Leadership & Decision-Making (for senior roles)', 'Ability to lead, inspire, delegate, and make sound decisions under pressure'],
        9  => ['Alignment with RTIH Mission & Values', 'Understanding of RTIH\'s public purpose mandate; enthusiasm for ecosystem building'],
        10 => ['Professional Conduct & Attitude', 'Punctuality, preparation, professionalism, and overall impression during interview'],
    ];
}

function hr_assess_rounds(): array
{
    return ['r1' => 'Round 1 – HR Screening', 'r2' => 'Round 2 – Written Test', 'r3' => 'Round 3 – Technical', 'r4' => 'Round 4 – Leadership'];
}

function hr_assess_scale(): array
{
    return [5 => '5 – Excellent', 4 => '4 – Good', 3 => '3 – Satisfactory', 2 => '2 – Below Average', 1 => '1 – Poor'];
}

/** Part B — overall recommendation. */
function hr_assess_recs(): array
{
    return ['strong' => 'Strongly recommend', 'recommend' => 'Recommend', 'reservations' => 'Recommend with reservations', 'not' => 'Do not recommend'];
}

/** HR's final word on a shortlisted candidate. */
function hr_assess_decisions(): array
{
    return ['selected' => 'Selected', 'hold' => 'On hold', 'rejected' => 'Not selected'];
}

function hr_assess_statuses(): array
{
    return ['pending' => 'Not started', 'draft' => 'In progress', 'submitted' => 'Submitted'];
}

function hr_assess_rec_tone(?string $r): string
{
    return ['strong' => 'green', 'recommend' => 'green', 'reservations' => 'amber', 'not' => 'red'][$r] ?? 'grey';
}

/** A pill in a given tone, for labels hr_tone() does not know. */
function hr_assess_pill(string $tone, string $label): string
{
    return '<span class="pill hr-pill ' . e($tone) . '"><i class="dot"></i>' . e($label) . '</span>';
}

function hr_assess_num($v): ?float
{
    $v = trim(str_replace(',', '', (string) $v));
    if ($v === '') {
        return null;
    }
    if (!is_numeric($v) || (float) $v < 0 || (float) $v > 99999) {
        throw new InvalidArgumentException('Marks must be numbers, e.g. 42 or 42.5.');
    }
    return round((float) $v, 2);
}

/** “Objective: x · Descriptive: y · Total: z” for a candidate row, as HTML. */
function hr_assess_written_html(array $r): string
{
    return 'Objective: ' . e(hr_assess_fmt($r['written_obj'] ?? null)) . ' &nbsp;·&nbsp; Descriptive: ' . e(hr_assess_fmt($r['written_desc'] ?? null))
         . ' &nbsp;·&nbsp; Total: <strong>' . e(hr_assess_fmt($r['written_total'] ?? null)) . '</strong>';
}

function hr_assess_fmt($n): string
{
    return $n === null || $n === '' ? '—' : rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.');
}

/* ============================================================== shortlist */

/** Shortlisted candidates with a summary of their sheets. $f: q, position, decision. */
function hr_shortlist_list(array $f = []): array
{
    $sql = 'SELECT s.*,
                   COUNT(a.id) AS sheets,
                   SUM(a.status = \'submitted\') AS submitted,
                   AVG(CASE WHEN a.status = \'submitted\' THEN a.total END) AS avg_total
              FROM hr_shortlist s LEFT JOIN hr_assessments a ON a.shortlist_id = s.id
             WHERE s.org_id = ?';
    $types = 'i';
    $args = [hr_org()];
    if (($q = trim((string) ($f['q'] ?? ''))) !== '') {
        $sql .= ' AND (s.full_name LIKE ? OR s.email LIKE ? OR s.phone LIKE ? OR s.hall_ticket LIKE ?)';
        $like = '%' . $q . '%';
        $types .= 'ssss';
        array_push($args, $like, $like, $like, $like);
    }
    if (($p = (string) ($f['position'] ?? '')) !== '') {
        $sql .= ' AND s.position = ?';
        $types .= 's';
        $args[] = $p;
    }
    if (($d = (string) ($f['decision'] ?? '')) !== '') {
        if ($d === 'none') {
            $sql .= ' AND s.decision IS NULL';
        } else {
            $sql .= ' AND s.decision = ?';
            $types .= 's';
            $args[] = $d;
        }
    }
    $sql .= ' GROUP BY s.id ORDER BY s.position, s.rank_no IS NULL, s.rank_no, s.full_name LIMIT 2000';
    $rows = fabo_all($sql, $types, $args);
    // Who each candidate is assigned to, in one query.
    if ($rows) {
        $ids = implode(',', array_map('intval', array_column($rows, 'id')));
        $by = [];
        foreach (fabo_all("SELECT a.shortlist_id, a.round, a.status, a.total, p.name FROM hr_assessments a JOIN hr_panelists p ON p.id = a.panelist_id
                            WHERE a.shortlist_id IN ($ids) ORDER BY a.round, p.name") as $a) {
            $by[(int) $a['shortlist_id']][] = $a;
        }
        foreach ($rows as &$r) {
            $r['assigned'] = $by[(int) $r['id']] ?? [];
        }
        unset($r);
    }
    return $rows;
}

function hr_shortlist_get(int $id): ?array
{
    return fabo_one('SELECT * FROM hr_shortlist WHERE id = ? AND org_id = ?', 'ii', [$id, hr_org()]);
}

/** The positions in use, for filters and the add form's suggestions. */
function hr_shortlist_positions(): array
{
    return array_column(fabo_all('SELECT DISTINCT position FROM hr_shortlist WHERE org_id = ? ORDER BY position', 'i', [hr_org()]), 'position');
}

function hr_shortlist_save(?int $id, array $p, ?array $resume = null): int
{
    $old = $id ? hr_shortlist_get($id) : null;
    if ($id && !$old) {
        fabo_deny('That candidate is not available to you.', 404);
    }
    if ($resume && ($resume['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
        && !in_array(strtolower(pathinfo((string) ($resume['name'] ?? ''), PATHINFO_EXTENSION)), ['pdf', 'doc', 'docx', 'odt', 'rtf', 'txt'], true)) {
        throw new InvalidArgumentException('Résumé: upload a PDF or Word document.');
    }
    $email = trim((string) ($p['email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('That email address does not look right.');
    }
    $rank = trim((string) ($p['rank_no'] ?? ''));
    if ($rank !== '' && (!ctype_digit($rank) || (int) $rank < 1)) {
        throw new InvalidArgumentException('Rank must be a whole number, 1 or more.');
    }
    // Written exam: objective and descriptive marks; the total is their sum unless given.
    $obj = hr_assess_num($p['written_obj'] ?? '');
    $desc = hr_assess_num($p['written_desc'] ?? '');
    $total = hr_assess_num($p['written_total'] ?? '');
    if ($total === null && ($obj !== null || $desc !== null)) {
        $total = round((float) $obj + (float) $desc, 2);
    }
    $decision = (string) ($p['decision'] ?? '');
    $decision = isset(hr_assess_decisions()[$decision]) ? $decision : null;
    $vals = [hr_str($p['full_name'] ?? '', 160, true, 'Name'), $email ?: null, hr_str($p['phone'] ?? '', 40) ?: null,
             hr_str($p['position'] ?? '', 160, true, 'Position applied for'), $rank === '' ? null : (int) $rank, $obj, $desc, $total,
             hr_str($p['qualification'] ?? '', 255) ?: null, hr_str($p['experience'] ?? '', 120) ?: null, hr_str($p['notes'] ?? '', 5000) ?: null,
             hr_str($p['hall_ticket'] ?? '', 40) ?: null];
    if ($id) {
        fabo_exec('UPDATE hr_shortlist SET full_name = ?, email = ?, phone = ?, position = ?, rank_no = ?, written_obj = ?, written_desc = ?, written_total = ?,
                          qualification = ?, experience = ?, notes = ?, hall_ticket = ?' . (array_key_exists('decision', $p) ? ', decision = ?' : '') . ' WHERE id = ? AND org_id = ?',
                  'ssssidddssss' . (array_key_exists('decision', $p) ? 's' : '') . 'ii',
                  array_merge($vals, array_key_exists('decision', $p) ? [$decision] : [], [$id, hr_org()]));
        hr_audit('update', 'shortlist', $id, 'Shortlisted candidate updated: ' . $vals[0]);
        hr_shortlist_attach($id, $resume, $old);
        return $id;
    }
    $new = fabo_exec('INSERT INTO hr_shortlist (org_id, full_name, email, phone, position, rank_no, written_obj, written_desc, written_total, qualification, experience, notes, hall_ticket, candidate_id, created_by)
                      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', 'issssidddssssis',
                     array_merge([hr_org()], $vals, [((int) ($p['candidate_id'] ?? 0)) ?: null, hr_actor()]));
    hr_audit('create', 'shortlist', $new, 'Shortlisted: ' . $vals[0] . ' for ' . $vals[3]);
    hr_shortlist_attach($new, $resume, null);
    return $new;
}

/** Stores an uploaded résumé on a shortlisted candidate, replacing any earlier one. */
function hr_shortlist_attach(int $id, ?array $resume, ?array $old): void
{
    if (!$resume || ($resume['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return;
    }
    $ext = strtolower(pathinfo((string) ($resume['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, ['pdf', 'doc', 'docx', 'odt', 'rtf', 'txt'], true)) {
        throw new InvalidArgumentException('Résumé: upload a PDF or Word document. The candidate was saved without it.');
    }
    [$ok, $msg, $meta] = fabo_store_upload($resume);
    if (!$ok) {
        throw new InvalidArgumentException('Résumé: ' . $msg . ' The candidate was saved without it.');
    }
    fabo_exec('UPDATE hr_shortlist SET resume_file = ?, resume_name = ?, resume_mime = ? WHERE id = ?', 'sssi',
              [$meta['stored_name'], $meta['original_name'], $meta['mime'], $id]);
    if (!empty($old['resume_file'])) {
        fabo_delete_stored($old['resume_file']);
    }
}

/**
 * Sends a shortlisted candidate's résumé to the browser and stops. A PDF
 * opens in the browser's viewer; anything else downloads.
 */
function hr_shortlist_send_resume(array $s): void
{
    $stored = (string) ($s['resume_file'] ?? '');
    $path = fabo_upload_dir() . DIRECTORY_SEPARATOR . $stored;
    if (!preg_match('/^[0-9a-f]{32}\.[A-Za-z0-9]{1,8}$/', $stored) || !is_file($path)) {
        http_response_code(404);
        exit('That résumé is not available.');
    }
    $name = preg_replace('/[^A-Za-z0-9._ -]/', '_', (string) ($s['resume_name'] ?: 'resume'));
    $pdf = strtolower(pathinfo($stored, PATHINFO_EXTENSION)) === 'pdf';
    header('Content-Type: ' . ($pdf ? 'application/pdf' : 'application/octet-stream'));
    header('Content-Disposition: ' . ($pdf ? 'inline' : 'attachment') . '; filename="' . $name . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

function hr_shortlist_decide(array $ids, string $decision): int
{
    $decision = isset(hr_assess_decisions()[$decision]) ? $decision : null;
    $n = 0;
    foreach (array_unique(array_map('intval', $ids)) as $id) {
        if ($id > 0 && hr_shortlist_get($id)) {
            fabo_exec('UPDATE hr_shortlist SET decision = ? WHERE id = ? AND org_id = ?', 'sii', [$decision, $id, hr_org()]);
            $n++;
        }
    }
    hr_audit('update', 'shortlist', null, 'Decision "' . ($decision ?? 'cleared') . '" on ' . $n . ' shortlisted candidate(s)');
    return $n;
}

/** Removes candidates and their sheets. */
function hr_shortlist_delete(array $ids): int
{
    $n = 0;
    foreach (array_unique(array_map('intval', $ids)) as $id) {
        $s = $id > 0 ? hr_shortlist_get($id) : null;
        if ($s) {
            fabo_exec('DELETE FROM hr_assessments WHERE shortlist_id = ? AND org_id = ?', 'ii', [$id, hr_org()]);
            fabo_exec('DELETE FROM hr_shortlist WHERE id = ? AND org_id = ?', 'ii', [$id, hr_org()]);
            foreach (['resume_file', 'offer_file'] as $f) {
                if (!empty($s[$f])) {
                    fabo_delete_stored($s[$f]);
                }
            }
            hr_audit('delete', 'shortlist', $id, 'Removed from shortlist: ' . $s['full_name']);
            $n++;
        }
    }
    return $n;
}

/** Column headings the import understands, matched loosely. */
function hr_shortlist_import_columns(): array
{
    return [
        'full_name' => ['candidate name', 'full name', 'name', 'candidate'],
        'email' => ['email', 'e-mail', 'mail id', 'email id'],
        'phone' => ['phone', 'mobile', 'contact'],
        'position' => ['position applied for', 'position', 'post', 'role', 'designation', 'job'],
        'hall_ticket' => ['hall ticket', 'hallticket', 'ht no', 'ht number', 'roll'],
        'rank_no' => ['rank'],
        'written_obj' => ['objective'],
        'written_desc' => ['descriptive'],
        'written_total' => ['written total', 'written exam', 'written', 'total score', 'total', 'marks'],
        'qualification' => ['qualification', 'education'],
        'experience' => ['experience'],
        'notes' => ['notes', 'remarks', 'comments'],
    ];
}

/** Imports a CSV/XLS/XLSX of shortlisted candidates; $position fills rows without one. */
function hr_shortlist_import(string $path, string $name, string $position, bool $dryRun): array
{
    $rows = hr_read_table_file($path, $name);
    if (!$rows) {
        throw new InvalidArgumentException('The file is empty.');
    }
    [, $head] = array_shift($rows);
    $cols = [];
    foreach (hr_shortlist_import_columns() as $key => $words) {
        foreach ($head as $i => $h) {
            $h = mb_strtolower(trim((string) $h));
            if ($h === '' || isset($cols[$i])) {
                continue;
            }
            foreach ($words as $w) {
                if ($h === $w || str_contains($h, $w)) {
                    $cols[$i] = $key;
                    continue 3;
                }
            }
        }
    }
    if (!in_array('full_name', $cols, true)) {
        throw new InvalidArgumentException('The first row must be headings, including a “Name” column. Download the template to see every column.');
    }
    if (!in_array('position', $cols, true) && trim($position) === '') {
        throw new InvalidArgumentException('The file has no “Position” column — type the position the candidates applied for.');
    }
    $created = 0;
    $errors = [];
    foreach ($rows as [$line, $cells]) {
        $p = [];
        foreach ($cols as $i => $key) {
            $p[$key] = trim((string) ($cells[$i] ?? ''));
        }
        if (implode('', $p) === '') {
            continue;
        }
        if (($p['position'] ?? '') === '') {
            $p['position'] = $position;
        }
        if (isset($p['rank_no'])) {
            $p['rank_no'] = preg_replace('/\.0+$/', '', $p['rank_no']);
        }
        try {
            if ($dryRun) {
                hr_str($p['full_name'] ?? '', 160, true, 'Name');
                hr_str($p['position'] ?? '', 160, true, 'Position');
                foreach (['written_obj', 'written_desc', 'written_total'] as $k) {
                    hr_assess_num($p[$k] ?? '');
                }
                if (($p['email'] ?? '') !== '' && !filter_var($p['email'], FILTER_VALIDATE_EMAIL)) {
                    throw new InvalidArgumentException('That email address does not look right.');
                }
            } else {
                hr_shortlist_save(null, $p);
            }
            $created++;
        } catch (InvalidArgumentException $e) {
            $errors[$line] = $e->getMessage();
        }
    }
    if (!$dryRun) {
        hr_audit('import', 'shortlist', null, 'Imported ' . $created . ' shortlisted candidates from ' . $name);
    }
    return ['created' => $created, 'errors' => $errors, 'rows' => count($rows)];
}

/** Recruitment candidates not yet on the shortlist, for “bring over”. */
function hr_shortlist_candidate_choices(): array
{
    $out = [];
    foreach (fabo_all("SELECT c.id, c.full_name, o.title FROM hr_candidates c JOIN hr_job_openings o ON o.id = c.opening_id
                        WHERE c.org_id = ? AND c.stage IN ('applied','screening','interview','offer')
                          AND c.id NOT IN (SELECT candidate_id FROM hr_shortlist WHERE org_id = ? AND candidate_id IS NOT NULL)
                        ORDER BY o.title, c.full_name LIMIT 1000", 'ii', [hr_org(), hr_org()]) as $c) {
        $out[$c['id']] = $c['full_name'] . ' — ' . $c['title'];
    }
    return $out;
}

function hr_shortlist_from_candidates(array $ids): int
{
    $n = 0;
    foreach (array_unique(array_map('intval', $ids)) as $id) {
        $c = $id > 0 ? fabo_one('SELECT c.*, o.title FROM hr_candidates c JOIN hr_job_openings o ON o.id = c.opening_id WHERE c.id = ? AND c.org_id = ?', 'ii', [$id, hr_org()]) : null;
        if (!$c || fabo_one('SELECT 1 FROM hr_shortlist WHERE candidate_id = ? AND org_id = ?', 'ii', [$id, hr_org()])) {
            continue;
        }
        $new = hr_shortlist_save(null, ['full_name' => $c['full_name'], 'email' => $c['email'], 'phone' => $c['phone'], 'position' => $c['title'],
                                        'experience' => $c['experience_years'] !== null ? hr_assess_fmt($c['experience_years']) . ' years' : '',
                                        'notes' => $c['notes'], 'candidate_id' => $id]);
        // The résumé comes too, as its own copy, so removing either one leaves the other intact.
        $src = fabo_upload_dir() . DIRECTORY_SEPARATOR . (string) $c['resume_file'];
        if ($c['resume_file'] && preg_match('/^[0-9a-f]{32}\.([A-Za-z0-9]{1,8})$/', (string) $c['resume_file'], $m) && is_file($src)) {
            $copy = bin2hex(random_bytes(16)) . '.' . $m[1];
            if (@copy($src, fabo_upload_dir() . DIRECTORY_SEPARATOR . $copy)) {
                fabo_exec('UPDATE hr_shortlist SET resume_file = ?, resume_name = ?, resume_mime = ? WHERE id = ?', 'sssi',
                          [$copy, $c['resume_name'], $c['resume_mime'], $new]);
            }
        }
        $n++;
    }
    return $n;
}

/* ============================================================ interviewers */

function hr_panelists(): array
{
    return fabo_all("SELECT p.*, COUNT(a.id) AS sheets, SUM(a.status = 'submitted') AS submitted
                       FROM hr_panelists p LEFT JOIN hr_assessments a ON a.panelist_id = p.id
                      WHERE p.org_id = ? GROUP BY p.id ORDER BY p.name", 'i', [hr_org()]);
}

function hr_panelist_get(int $id): ?array
{
    return fabo_one('SELECT * FROM hr_panelists WHERE id = ? AND org_id = ?', 'ii', [$id, hr_org()]);
}

/** Adds an interviewer, or ($id) changes one. Email is unique within the organisation. */
function hr_panelist_save(?int $id, array $p): int
{
    $email = mb_strtolower(trim((string) ($p['email'] ?? '')));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Give the interviewer\'s email address — their dashboard link is sent there.');
    }
    $name = hr_str($p['name'] ?? '', 160, true, 'Interviewer name');
    $designation = hr_str($p['designation'] ?? '', 160) ?: null;
    $other = fabo_one('SELECT id FROM hr_panelists WHERE org_id = ? AND email = ?', 'is', [hr_org(), $email]);
    if ($other && (int) $other['id'] !== (int) $id) {
        throw new InvalidArgumentException('An interviewer with that email address is already on the list.');
    }
    if ($id) {
        if (!hr_panelist_get($id)) {
            fabo_deny('That interviewer is not available to you.', 404);
        }
        fabo_exec('UPDATE hr_panelists SET name = ?, email = ?, designation = ? WHERE id = ? AND org_id = ?', 'sssii', [$name, $email, $designation, $id, hr_org()]);
        hr_audit('update', 'panelist', $id, 'Interviewer updated: ' . $name . ' <' . $email . '>');
        return $id;
    }
    $id = fabo_exec('INSERT INTO hr_panelists (org_id, name, email, designation, token, created_by) VALUES (?,?,?,?,?,?)',
                    'isssss', [hr_org(), $name, $email, $designation, bin2hex(random_bytes(16)), hr_actor()]);
    hr_audit('create', 'panelist', $id, 'Interviewer added: ' . $name . ' <' . $email . '>');
    return $id;
}

/** Removes an interviewer who has no submitted sheets; their unsubmitted assignments go with them. */
function hr_panelist_delete(int $id): string
{
    $p = hr_panelist_get($id);
    if (!$p) {
        fabo_deny('That interviewer is not available to you.', 404);
    }
    if (fabo_one("SELECT 1 FROM hr_assessments WHERE panelist_id = ? AND status = 'submitted' LIMIT 1", 'i', [$id])) {
        throw new InvalidArgumentException($p['name'] . ' has submitted assessment sheets, so they stay on the list. Make a new link if their access should stop.');
    }
    fabo_exec('DELETE FROM hr_assessments WHERE panelist_id = ? AND org_id = ?', 'ii', [$id, hr_org()]);
    fabo_exec('DELETE FROM hr_panelists WHERE id = ? AND org_id = ?', 'ii', [$id, hr_org()]);
    hr_audit('delete', 'panelist', $id, 'Interviewer removed: ' . $p['name']);
    return $p['name'] . ' removed.';
}

/** A new link for an interviewer; the old one stops working. */
function hr_panelist_new_link(int $id): void
{
    if (!hr_panelist_get($id)) {
        fabo_deny('That interviewer is not available to you.', 404);
    }
    fabo_exec('UPDATE hr_panelists SET token = ? WHERE id = ? AND org_id = ?', 'sii', [bin2hex(random_bytes(16)), $id, hr_org()]);
    hr_audit('update', 'panelist', $id, 'Interviewer dashboard link replaced');
}

function hr_panelist_link(array $p): string
{
    return rtrim(fabo_portal_url(), '/') . '/interview-panel.php?t=' . $p['token'];
}

/**
 * Assigns shortlisted candidates to one interviewer for one round and, when
 * asked, emails them their dashboard link. Returns a sentence for the flash.
 */
function hr_assess_assign(array $ids, array $p): string
{
    $ids = array_values(array_filter(array_unique(array_map('intval', $ids))));
    if (!$ids) {
        throw new InvalidArgumentException('Tick at least one candidate to assign.');
    }
    $round = (string) ($p['round'] ?? '');
    if (!isset(hr_assess_rounds()[$round])) {
        throw new InvalidArgumentException('Choose the interview round.');
    }
    $pid = (int) ($p['panelist_id'] ?? 0);
    $panelist = $pid ? hr_panelist_get($pid) : null;
    if (!$panelist) {
        throw new InvalidArgumentException('Choose an interviewer. New interviewers are added on the Interviewers tab.');
    }
    $when = trim((string) ($p['interview_at'] ?? ''));
    $ts = $when !== '' ? strtotime(str_replace('T', ' ', $when)) : null;
    if ($when !== '' && !$ts) {
        throw new InvalidArgumentException('The interview date and time is not valid.');
    }
    $loc = hr_str($p['location'] ?? '', 120) ?: null;
    $added = $already = 0;
    foreach ($ids as $id) {
        $s = hr_shortlist_get($id);
        if (!$s) {
            continue;
        }
        $have = fabo_one('SELECT id, status FROM hr_assessments WHERE shortlist_id = ? AND panelist_id = ? AND round = ?', 'iis', [$id, (int) $panelist['id'], $round]);
        if ($have) {
            if ($have['status'] !== 'submitted') {
                fabo_exec('UPDATE hr_assessments SET interview_at = COALESCE(?, interview_at), location = COALESCE(?, location) WHERE id = ?',
                          'ssi', [$ts ? date('Y-m-d H:i:s', $ts) : null, $loc, (int) $have['id']]);
            }
            $already++;
            continue;
        }
        fabo_exec('INSERT INTO hr_assessments (org_id, shortlist_id, panelist_id, round, interview_at, location, assigned_by) VALUES (?,?,?,?,?,?,?)',
                  'iiissss', [hr_org(), $id, (int) $panelist['id'], $round, $ts ? date('Y-m-d H:i:s', $ts) : null, $loc, hr_actor()]);
        $added++;
    }
    hr_audit('assign', 'panelist', (int) $panelist['id'], $added . ' candidate(s) assigned to ' . $panelist['name'] . ' for ' . hr_assess_rounds()[$round]);
    $msg = $added . ' candidate' . ($added === 1 ? '' : 's') . ' assigned to ' . $panelist['name'] . ($already ? ' (' . $already . ' already assigned for this round)' : '') . '.';
    if (!empty($p['send'])) {
        [$status, $detail] = hr_assess_send_link($panelist, $ts ? date('D j M Y, g:i A', $ts) : '');
        $msg .= $status === 'sent' ? ' Their dashboard link has been emailed to ' . $panelist['email'] . '.'
              : ($status === 'queued' ? ' Their dashboard link is queued to ' . $panelist['email'] . ' and goes out shortly.'
              : ' The email could not be sent (' . $detail . ') — copy the link from the Interviewers tab and send it yourself.');
    }
    return $msg;
}

/** Emails an interviewer the link to their dashboard. Returns [status, detail]. */
function hr_assess_send_link(array $panelist, string $when = ''): array
{
    try {
        require_once __DIR__ . '/../mailer.php';
        if (!fabo_mail_configured()) {
            return ['failed', 'outgoing mail is not configured'];
        }
        require_once __DIR__ . '/../outbox.php';
        require_once __DIR__ . '/../mailtpl.php';
        $org = (int) $panelist['org_id'];
        $pending = fabo_all("SELECT s.full_name, s.position, a.round FROM hr_assessments a JOIN hr_shortlist s ON s.id = a.shortlist_id
                              WHERE a.panelist_id = ? AND a.status <> 'submitted' ORDER BY s.position, s.rank_no IS NULL, s.rank_no, s.full_name",
                            'i', [(int) $panelist['id']]);
        $rounds = hr_assess_rounds();
        $url = hr_panelist_link($panelist);
        $brand = hr_org_label($org);
        $subject = 'Interview assessments — ' . count($pending) . ' candidate' . (count($pending) === 1 ? '' : 's') . ' for you';
        $lines = array_map(static fn($r) => $r['full_name'] . ' — ' . $r['position'] . ' (' . ($rounds[$r['round']] ?? $r['round']) . ')', $pending);
        $intro = 'You are on the interview panel. ' . (count($pending) ?: 'No') . ' candidate' . (count($pending) === 1 ? ' is' : 's are')
               . ' waiting for your assessment' . ($when !== '' ? ', interviews on ' . $when : '') . '.';
        $after = 'Open your interview dashboard to see each candidate\'s details and fill in their Interview Assessment Sheet: '
               . 'a score from 1 to 5 for each of the ten competencies, your recommendation and remarks. You can save as you go and submit when done.';
        $text = 'Hello ' . $panelist['name'] . ",\n\n" . $intro . "\n\n" . implode("\n", $lines) . "\n\n" . $after
              . "\n\nYour interview dashboard: " . $url . "\n\nThis link is personal to you — please do not forward it.\n";
        $html = fabo_mail_wrap($brand, 'Interview assessments',
            fabo_mail_p('Hello ' . e($panelist['name']) . ',') . fabo_mail_p(e($intro))
            . ($lines ? fabo_mail_list(array_map('e', $lines)) : '') . fabo_mail_p(e($after))
            . fabo_mail_own_link($url, 'Open interview dashboard', 'This link is personal to you — please do not forward it.'), $intro);
        /* A client company's mail goes out under its organisation's name, with
           replies to its owner (or from its own mailbox when granted), not as
           the portal. The kind carries the owner so a queued retry does too. */
        $owner = $org > 0 ? fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [$org]) : null;
        if ($owner) {
            $owner['send_as_name'] = $brand;
        }
        [$ok, $why, $oid] = fabo_post_or_queue((string) $panelist['email'], $subject, $text, $html, null,
                                               'hr ' . ($owner ? 'o' . (int) $owner['id'] . ' ' : '') . 'panel ' . substr(sha1($panelist['email'] . microtime()), 0, 12), $owner);
        fabo_exec('UPDATE hr_panelists SET last_sent_at = NOW() WHERE id = ?', 'i', [(int) $panelist['id']]);
        if ($ok) {
            return ['sent', ''];
        }
        return [$oid ? 'queued' : 'failed', (string) $why];
    } catch (Throwable $e) {
        error_log('FABO HR panel email: ' . $e->getMessage());
        return ['failed', 'the mail service raised an error'];
    }
}

/**
 * One email from this module, sent under the organisation's name (see
 * hr_assess_send_link). $tag names the kind in the outbox. Returns
 * [sent|queued|failed, detail]. Never throws.
 */
function hr_assess_mail(int $org, string $to, string $subject, string $text, string $html, string $tag): array
{
    // A test seam: tests set this to capture what would be sent.
    if (isset($GLOBALS['hr_mail_hook']) && is_callable($GLOBALS['hr_mail_hook'])) {
        return ($GLOBALS['hr_mail_hook'])($org, $to, $subject, $text, $tag);
    }
    try {
        require_once __DIR__ . '/../mailer.php';
        if (!fabo_mail_configured()) {
            return ['failed', 'outgoing mail is not configured'];
        }
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['failed', 'no valid email address'];
        }
        require_once __DIR__ . '/../outbox.php';
        $owner = $org > 0 ? fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [$org]) : null;
        if ($owner) {
            $owner['send_as_name'] = hr_org_label($org);
        }
        [$ok, $why, $oid] = fabo_post_or_queue($to, $subject, $text, $html, null,
                                               'hr ' . ($owner ? 'o' . (int) $owner['id'] . ' ' : '') . $tag . ' ' . substr(sha1($to . microtime()), 0, 12), $owner);
        return $ok ? ['sent', ''] : [$oid ? 'queued' : 'failed', (string) $why];
    } catch (Throwable $e) {
        error_log('FABO HR assess email: ' . $e->getMessage());
        return ['failed', 'the mail service raised an error'];
    }
}

/** A sentence about how an email went, for a flash message. */
function hr_assess_mail_words(array $r, string $to, string $what): string
{
    return $r[0] === 'sent' ? ' ' . $what . ' emailed to ' . $to . '.'
         : ($r[0] === 'queued' ? ' ' . $what . ' queued to ' . $to . ' — it goes out shortly.'
         : ' The email to ' . $to . ' could not be sent (' . $r[1] . ').');
}

require_once __DIR__ . '/approval.php';

/* ============================================================ the sheets */

/** Every sheet in the org (or for one candidate / interviewer), with names. */
function hr_assess_list(array $f = []): array
{
    $sql = 'SELECT a.*, s.full_name, s.email AS cand_email, s.phone AS cand_phone, s.position, s.rank_no, s.hall_ticket, s.written_obj, s.written_desc, s.written_total, s.decision,
                   p.name AS iv_name, p.email AS iv_email, p.designation AS iv_designation
              FROM hr_assessments a JOIN hr_shortlist s ON s.id = a.shortlist_id JOIN hr_panelists p ON p.id = a.panelist_id
             WHERE a.org_id = ?';
    $types = 'i';
    $args = [hr_org()];
    foreach (['shortlist' => 'a.shortlist_id', 'panelist' => 'a.panelist_id'] as $k => $col) {
        if (!empty($f[$k])) {
            $sql .= " AND $col = ?";
            $types .= 'i';
            $args[] = (int) $f[$k];
        }
    }
    if (($p = (string) ($f['position'] ?? '')) !== '') {
        $sql .= ' AND s.position = ?';
        $types .= 's';
        $args[] = $p;
    }
    return fabo_all($sql . ' ORDER BY s.position, s.rank_no IS NULL, s.rank_no, s.full_name, a.round, p.name', $types, $args);
}

function hr_assess_get(int $id): ?array
{
    return hr_assess_list_one('a.id = ? AND a.org_id = ?', 'ii', [$id, hr_org()]);
}

/** One sheet with candidate and interviewer, by any condition (the public page passes the panelist). */
function hr_assess_list_one(string $where, string $types, array $args): ?array
{
    return fabo_one('SELECT a.*, s.full_name, s.email AS cand_email, s.phone AS cand_phone, s.position, s.rank_no, s.hall_ticket, s.written_obj, s.written_desc, s.written_total,
                            s.qualification, s.experience, s.notes AS cand_notes, s.decision, s.resume_file, s.resume_name,
                            p.name AS iv_name, p.email AS iv_email, p.designation AS iv_designation
                       FROM hr_assessments a JOIN hr_shortlist s ON s.id = a.shortlist_id JOIN hr_panelists p ON p.id = a.panelist_id
                      WHERE ' . $where, $types, $args);
}

function hr_assess_reopen(int $id): void
{
    $a = hr_assess_get($id);
    if (!$a) {
        fabo_deny('That sheet is not available to you.', 404);
    }
    fabo_exec("UPDATE hr_assessments SET status = 'draft', submitted_at = NULL WHERE id = ?", 'i', [$id]);
    hr_audit('update', 'assessment', $id, 'Assessment sheet reopened: ' . $a['full_name'] . ' / ' . $a['iv_name']);
}

function hr_assess_remove(int $id): void
{
    $a = hr_assess_get($id);
    if (!$a) {
        fabo_deny('That sheet is not available to you.', 404);
    }
    fabo_exec('DELETE FROM hr_assessments WHERE id = ?', 'i', [$id]);
    hr_audit('delete', 'assessment', $id, 'Assignment removed: ' . $a['full_name'] . ' from ' . $a['iv_name']);
}

/** The decoded scores: [n => ['s' => int|null, 'c' => string]]. */
function hr_assess_scores(array $a): array
{
    $raw = json_decode((string) ($a['scores'] ?? ''), true) ?: [];
    $out = [];
    foreach (hr_assess_params() as $n => $_) {
        $s = (int) ($raw[$n]['s'] ?? 0);
        $out[$n] = ['s' => $s >= 1 && $s <= 5 ? $s : null, 'c' => (string) ($raw[$n]['c'] ?? '')];
    }
    return $out;
}

/**
 * Saves the interviewer's sheet. $submit checks that it is complete and
 * locks it. Called from the public page with the row it already holds.
 */
function hr_assess_save_sheet(array $a, array $p, bool $submit): void
{
    if ($a['status'] === 'submitted') {
        throw new InvalidArgumentException('This sheet has already been submitted. Ask HR to reopen it if something needs changing.');
    }
    $scores = [];
    $total = 0;
    $missing = [];
    foreach (hr_assess_params() as $n => [$title]) {
        $s = (int) ($p['s' . $n] ?? 0);
        $s = $s >= 1 && $s <= 5 ? $s : null;
        if ($s === null) {
            $missing[] = $n;
        }
        $total += (int) $s;
        $scores[$n] = ['s' => $s, 'c' => mb_substr(trim((string) ($p['c' . $n] ?? '')), 0, 1000)];
    }
    $rec = (string) ($p['recommendation'] ?? '');
    $rec = isset(hr_assess_recs()[$rec]) ? $rec : null;
    if ($submit) {
        if ($missing) {
            throw new InvalidArgumentException('Give a score from 1 to 5 for every competency before submitting (missing: ' . implode(', ', $missing) . ').');
        }
        if (!$rec) {
            throw new InvalidArgumentException('Choose your overall recommendation before submitting.');
        }
    }
    $when = trim((string) ($p['interview_at'] ?? ''));
    $ts = $when !== '' ? strtotime(str_replace('T', ' ', $when)) : null;
    $cut = static fn($k) => ($v = mb_substr(trim((string) ($p[$k] ?? '')), 0, 5000)) === '' ? null : $v;
    fabo_exec('UPDATE hr_assessments SET scores = ?, total = ?, recommendation = ?, strengths = ?, concerns = ?, observations = ?,
                      interview_at = COALESCE(?, interview_at), status = ?, submitted_at = ' . ($submit ? 'NOW()' : 'NULL') . ' WHERE id = ? AND panelist_id = ?',
              'sissssssii',
              [json_encode($scores, JSON_UNESCAPED_UNICODE), count($missing) === 10 ? null : $total, $rec, $cut('strengths'), $cut('concerns'), $cut('observations'),
               $ts ? date('Y-m-d H:i:s', $ts) : null, $submit ? 'submitted' : 'draft', (int) $a['id'], (int) $a['panelist_id']]);
}

/**
 * The filled sheet, laid out like the paper one. Read-only HTML, used by HR
 * (with print) and by the interviewer after submitting.
 */
function hr_assess_sheet_html(array $a): string
{
    $sc = hr_assess_scores($a);
    $rounds = hr_assess_rounds();
    $scale = hr_assess_scale();
    $total = array_sum(array_map(static fn($x) => (int) $x['s'], $sc));
    $filled = count(array_filter($sc, static fn($x) => $x['s'] !== null));
    $kv = static fn($k, $v) => '<tr><th>' . e($k) . '</th><td>' . $v . '</td></tr>';
    $h = '<div class="ias"><h2 class="ias-title">Interview Assessment Sheet</h2><table class="ias-kv">'
       . $kv('Candidate name', '<strong>' . e($a['full_name']) . '</strong>')
       . $kv('Position applied for', e($a['position']))
       . $kv('Rank', e($a['rank_no'] ?? '—'))
       . $kv('Hall ticket number', e($a['hall_ticket'] ?? '') ?: '—')
       . $kv('Marks in written exam', hr_assess_written_html($a))
       . $kv('Interview date & time', e($a['interview_at'] ? date('D j M Y, g:i A', strtotime($a['interview_at'])) : '—'))
       . $kv('Interview round', e($rounds[$a['round']] ?? $a['round']))
       . $kv('Interviewer name', e($a['iv_name']))
       . $kv('Interviewer designation', e($a['iv_designation'] ?? '—'))
       . $kv('Entity / Location', e($a['location'] ?: 'Amaravati'))
       . '</table><p class="ias-scale">Rating scale: 5 = Excellent | 4 = Good | 3 = Satisfactory | 2 = Below Average | 1 = Poor</p>'
       . '<h3>Part A – Competency assessment</h3><table class="ias-grid"><thead><tr><th>#</th><th>Competency / Parameter</th><th>Key indicators observed</th>'
       . '<th class="num">Score (1–5)</th><th>Comments</th></tr></thead><tbody>';
    foreach (hr_assess_params() as $n => [$title, $ind]) {
        $h .= '<tr><td>' . $n . '</td><td><strong>' . e($title) . '</strong></td><td class="ias-ind">' . e($ind) . '</td>'
            . '<td class="num"><strong>' . ($sc[$n]['s'] ?? '—') . '</strong>' . ($sc[$n]['s'] ? '<div class="ias-ind">' . e(substr($scale[$sc[$n]['s']], 4)) . '</div>' : '') . '</td>'
            . '<td>' . nl2br(e($sc[$n]['c'])) . '</td></tr>';
    }
    $h .= '</tbody><tfoot><tr><th colspan="3">Total score (out of 50)</th><th class="num">' . ($filled ? $total : '—') . ' / 50</th><td></td></tr>'
        . '<tr><th colspan="3">Average score (total ÷ 10)</th><th class="num">' . ($filled ? number_format($total / 10, 1) : '—') . ' / 5</th><td></td></tr></tfoot></table>'
        . '<h3>Part B – Overall recommendation</h3><p class="ias-recs">';
    foreach (hr_assess_recs() as $k => $label) {
        $h .= '<span class="' . ($a['recommendation'] === $k ? 'on' : '') . '">' . ($a['recommendation'] === $k ? '☑' : '☐') . ' ' . e(mb_strtoupper($label)) . '</span>';
    }
    $h .= '</p><table class="ias-kv">'
        . $kv('Key strengths of candidate', nl2br(e($a['strengths'] ?? '')) ?: '—')
        . $kv('Concerns / areas needing clarification', nl2br(e($a['concerns'] ?? '')) ?: '—')
        . $kv('Any other observations', nl2br(e($a['observations'] ?? '')) ?: '—')
        . '</table><p class="ias-sign">' . ($a['status'] === 'submitted'
            ? 'Submitted by <strong>' . e($a['iv_name']) . '</strong> on ' . e(date('j M Y, g:i A', strtotime($a['submitted_at']))) . '.'
            : 'Not submitted yet (' . e(hr_assess_statuses()[$a['status']] ?? $a['status']) . ').') . '</p></div>';
    return $h;
}

/** Shared CSS for the sheet, on the HR page and the interviewer's page. */
function hr_assess_css(): string
{
    return '<style>
.ias{max-width:960px}
.ias-title{margin:0 0 12px;text-align:center;letter-spacing:.04em;text-transform:uppercase;font-size:18px}
.ias h3{margin:20px 0 8px;font-size:14px;text-transform:uppercase;letter-spacing:.03em}
.ias table{width:100%;border-collapse:collapse;font-size:13.5px}
.ias th,.ias td{border:1px solid #cfd6df;padding:7px 9px;text-align:left;vertical-align:top}
.ias-kv th{width:32%;background:#f4f6f9;font-weight:650}
.ias-grid thead th,.ias-grid tfoot th{background:#f4f6f9}
.ias .num{text-align:center;white-space:nowrap}
.ias-ind{color:#5f6979;font-size:12px}
.ias-scale{margin:8px 0 0;font-size:12.5px;color:#5f6979}
.ias-recs{display:flex;flex-wrap:wrap;gap:8px 18px;font-size:13px}
.ias-recs .on{font-weight:700}
.ias-sign{margin-top:14px;font-size:13px;color:#5f6979}
.ias-form .ias-grid select{min-width:120px}
.ias-form .ias-grid textarea{width:100%;min-height:54px;resize:vertical}
.ias-form .ias-tot{font-size:15px;font-weight:700}
@media (max-width:720px){.ias-grid thead{display:none}.ias-grid tr{display:block;border:1px solid #cfd6df;margin-bottom:10px}
 .ias-grid td,.ias-grid th{display:block;border:0;border-bottom:1px solid #eef1f5}.ias .num{text-align:left}.ias-kv th{width:auto}}
@media print{body *{visibility:hidden}.ias,.ias *{visibility:visible}.ias{position:absolute;left:0;top:0;max-width:none}.no-print{display:none!important}}
</style>';
}

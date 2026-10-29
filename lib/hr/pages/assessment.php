<?php
/**
 * Interview Assessments — the shortlist, the interview panel, and the filled
 * RTIH Interview Assessment Sheets. See lib/hr/assess.php.
 *
 *   ?tab=shortlist|panel|results
 *   ?sl=ID      one shortlisted candidate (edit, decision, their sheets)
 *   ?sheet=ID   one filled sheet, printable
 *   ?export=1   every sheet as CSV
 */

if (hr_scope('recruitment', 'view') !== 'org') {
    fabo_deny('Interview assessments need organisation-wide Recruitment access.', 403);
}
$canEdit = hr_scope('recruitment', 'edit') === 'org';
$canDelete = hr_scope('recruitment', 'delete') === 'org';
$flash = null;
$importRes = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canEdit) {
        fabo_deny('You can view interview assessments but not change them.', 403);
    }
    $a = (string) ($_POST['action'] ?? '');
    $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
    if ($a === 'sl_save') {
        $slId = (int) ($_POST['id'] ?? 0);
        $flash = hr_action(static function () use ($slId) {
            $id = hr_shortlist_save($slId ?: null, $_POST, $_FILES['resume'] ?? null);
            hr_redirect($slId ? hr_url('assessment', ['sl' => $id]) : hr_url('assessment'), ['ok', $slId ? 'Saved.' : 'Added to the shortlist.']);
        }, hr_url('assessment'));
    } elseif ($a === 'import') {
        try {
            [$tmp, $name] = hr_uploaded_table('file');
            $dry = !empty($_POST['dry']);
            $importRes = [hr_shortlist_import($tmp, $name, (string) ($_POST['position'] ?? ''), $dry), $dry];
            if (!$dry && $importRes[0]['created'] && !$importRes[0]['errors']) {
                hr_redirect(hr_url('assessment'), ['ok', $importRes[0]['created'] . ' candidate(s) imported to the shortlist.']);
            }
        } catch (InvalidArgumentException $e) {
            $flash = ['err', $e->getMessage()];
        } catch (Throwable $e) {
            $flash = ['err', fabo_user_error($e)];
        }
    } elseif ($a === 'from_cands') {
        $flash = hr_action(static function () {
            $n = hr_shortlist_from_candidates((array) ($_POST['cand_ids'] ?? []));
            return $n . ' candidate(s) brought over from Recruitment.';
        }, hr_url('assessment'));
    } elseif ($a === 'bulk') {
        $do = (string) ($_POST['do'] ?? '');
        $flash = hr_action(static function () use ($do, $ids, $canDelete) {
            if (!$ids) {
                throw new InvalidArgumentException('Tick at least one candidate first.');
            }
            if ($do === 'assign') {
                return hr_assess_assign($ids, $_POST);
            }
            if ($do === 'decide') {
                return 'Decision recorded for ' . hr_shortlist_decide($ids, (string) ($_POST['decision'] ?? '')) . ' candidate(s).';
            }
            if ($do === 'ceo') {
                return hr_approval_send($ids, (string) ($_POST['ceo_note'] ?? ''));
            }
            if ($do === 'delete' && $canDelete) {
                return hr_shortlist_delete($ids) . ' candidate(s) removed from the shortlist.';
            }
            throw new InvalidArgumentException('Choose what to do with the ticked candidates.');
        }, hr_url('assessment', array_filter(['position' => (string) ($_POST['f_position'] ?? '')])));
    } elseif ($a === 'sheet_reopen') {
        $sid = (int) ($_POST['id'] ?? 0);
        $flash = hr_action(static function () use ($sid) { hr_assess_reopen($sid); return 'Sheet reopened — the interviewer can change and submit it again.'; },
                           hr_url('assessment', ['sheet' => $sid]));
    } elseif ($a === 'sheet_remove') {
        $sid = (int) ($_POST['id'] ?? 0);
        $back = (int) ($_POST['sl'] ?? 0);
        $flash = hr_action(static function () use ($sid) { hr_assess_remove($sid); return 'Assignment removed.'; },
                           $back ? hr_url('assessment', ['sl' => $back]) : hr_url('assessment', ['tab' => 'results']));
    } elseif ($a === 'panel_send') {
        $pid = (int) ($_POST['id'] ?? 0);
        $flash = hr_action(static function () use ($pid) {
            $p = hr_panelist_get($pid);
            if (!$p) {
                fabo_deny('That interviewer is not available to you.', 404);
            }
            [$status, $detail] = hr_assess_send_link($p);
            if ($status === 'failed') {
                throw new InvalidArgumentException('The email could not be sent: ' . $detail . '. Copy the link below and send it yourself.');
            }
            return 'Dashboard link ' . ($status === 'sent' ? 'emailed' : 'queued') . ' to ' . $p['email'] . '.';
        }, hr_url('assessment', ['tab' => 'panel']));
    } elseif ($a === 'panel_save') {
        $pid = (int) ($_POST['id'] ?? 0);
        $flash = hr_action(static function () use ($pid) {
            hr_panelist_save($pid ?: null, $_POST);
            return $pid ? 'Interviewer updated.' : 'Interviewer added. You can now assign shortlisted candidates to them.';
        }, hr_url('assessment', ['tab' => 'panel']));
    } elseif ($a === 'panel_delete') {
        $pid = (int) ($_POST['id'] ?? 0);
        $flash = hr_action(static fn() => hr_panelist_delete($pid), hr_url('assessment', ['tab' => 'panel']));
    } elseif ($a === 'panel_newlink') {
        $pid = (int) ($_POST['id'] ?? 0);
        $flash = hr_action(static function () use ($pid) { hr_panelist_new_link($pid); return 'New link made. The old link no longer opens the dashboard — send the new one.'; },
                           hr_url('assessment', ['tab' => 'panel']));
    } elseif ($a === 'ceo_save') {
        $flash = hr_action(static function () { hr_ceo_save($_POST); return 'CEO details saved.'; }, hr_url('assessment', ['tab' => 'approval']));
    } elseif ($a === 'ceo_newlink') {
        $flash = hr_action(static function () { hr_ceo_new_link(); return 'New approval link made. The old link no longer works — email the new one.'; },
                           hr_url('assessment', ['tab' => 'approval']));
    } elseif ($a === 'ceo_resend') {
        $flash = hr_action(static function () {
            $ceo = hr_ceo();
            if ($ceo['email'] === '') {
                throw new InvalidArgumentException('Set the CEO first.');
            }
            $r = hr_approval_mail_ceo($ceo);
            if ($r[0] === 'failed') {
                throw new InvalidArgumentException('The email could not be sent: ' . $r[1] . '. Copy the link and send it yourself.');
            }
            return trim(hr_assess_mail_words($r, $ceo['email'], 'The approval link was'));
        }, hr_url('assessment', ['tab' => 'approval']));
    } elseif ($a === 'approval_send') {
        $sid = (int) ($_POST['id'] ?? 0);
        $flash = hr_action(static fn() => hr_approval_send([$sid], (string) ($_POST['ceo_note'] ?? '')), hr_url('assessment', ['sl' => $sid]));
    } elseif ($a === 'offer_preview') {
        $sid = (int) ($_POST['id'] ?? 0);
        try {
            $s = hr_offer_save($sid, $_POST);
            hr_offer_pdf($s)->send('offer-letter-' . preg_replace('/[^A-Za-z0-9]+/', '-', $s['full_name']) . '.pdf', false);
            exit;
        } catch (InvalidArgumentException $e) {
            $flash = ['err', $e->getMessage()];
        }
    } elseif ($a === 'offer_send') {
        $sid = (int) ($_POST['id'] ?? 0);
        $flash = hr_action(static fn() => hr_offer_send($sid, $_POST), hr_url('assessment', ['sl' => $sid]));
    }
}

// The offer letter that was sent, as the candidate received it.
if (($oid = (int) ($_GET['offer'] ?? 0)) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $s = hr_shortlist_get($oid);
    if (!$s || !$s['offer_file']) {
        fabo_deny('That offer letter is not available.', 404);
    }
    hr_shortlist_send_resume(['resume_file' => $s['offer_file'], 'resume_name' => 'Offer letter - ' . $s['full_name'] . '.pdf']);
}

$rounds = hr_assess_rounds();
$recs = hr_assess_recs();
$decisions = hr_assess_decisions();
$statuses = hr_assess_statuses();
$statusTone = ['pending' => 'grey', 'draft' => 'amber', 'submitted' => 'green'];

/* ------------------------------------------------------------- export */

if (!empty($_GET['export'])) {
    $rows = [['Position', 'Rank', 'Hall ticket', 'Candidate', 'Email', 'Phone', 'Written objective', 'Written descriptive', 'Written total', 'Round', 'Interviewer', 'Designation',
              'Interview at', 'Status', ...array_map(static fn($p) => $p[0], hr_assess_params()), 'Total (/50)', 'Average (/5)', 'Recommendation',
              'Strengths', 'Concerns', 'Observations', 'Submitted at', 'HR decision']];
    foreach (hr_assess_list(['position' => (string) ($_GET['position'] ?? '')]) as $r) {
        $sc = hr_assess_scores($r);
        $rows[] = [$r['position'], $r['rank_no'], $r['hall_ticket'], $r['full_name'], $r['cand_email'], $r['cand_phone'], $r['written_obj'], $r['written_desc'], $r['written_total'], $rounds[$r['round']] ?? $r['round'],
                   $r['iv_name'], $r['iv_designation'], $r['interview_at'], $statuses[$r['status']] ?? $r['status'], ...array_map(static fn($x) => $x['s'], array_values($sc)),
                   $r['total'], $r['total'] !== null ? round($r['total'] / 10, 1) : '', $recs[$r['recommendation']] ?? '', $r['strengths'], $r['concerns'], $r['observations'],
                   $r['submitted_at'], $decisions[$r['decision']] ?? ''];
    }
    hr_audit('export', 'assessment', null, 'Interview assessments exported');
    hr_csv_download('interview-assessments-' . date('Y-m-d') . '.csv', $rows);
}

if ($rid = (int) ($_GET['resume'] ?? 0)) {
    $s = hr_shortlist_get($rid);
    if (!$s || !$s['resume_file']) {
        fabo_deny('That résumé is not available.', 404);
    }
    hr_audit('download', 'shortlist', $rid, 'Résumé opened: ' . $s['full_name']);
    hr_shortlist_send_resume($s);
}

if (!empty($_GET['template'])) {
    hr_csv_download('shortlist-template.csv', [['Hall Ticket Number', 'Rank', 'Candidate Name', 'Email', 'Phone', 'Position Applied For', 'Objective', 'Descriptive', 'Written Total', 'Qualification', 'Experience', 'Notes'],
                                               ['HT1001', 1, 'Example Candidate', 'candidate@example.com', '9876543210', 'Programme Manager', 42, 30, 72, 'MBA', '4 years', '']]);
}

/* -------------------------------------------------------- one sheet */

$sheetId = (int) ($_GET['sheet'] ?? 0);
if ($sheetId) {
    $s = hr_assess_get($sheetId);
    if (!$s) {
        fabo_deny('That sheet is not available to you.', 404);
    }
    $acts = '<button type="button" class="btn btn-sm btn-primary" onclick="window.print()">Print / save as PDF</button>';
    hr_layout_start($s['full_name'] . ' — ' . ($rounds[$s['round']] ?? $s['round']), e($s['position']) . ' · assessed by ' . e($s['iv_name']) . ' · '
                  . hr_assess_pill($statusTone[$s['status']] ?? 'grey', $statuses[$s['status']] ?? $s['status']),
                    ['Interview assessments' => hr_url('assessment'), $s['full_name'] => hr_url('assessment', ['sl' => $s['shortlist_id']]), 'Sheet' => ''], $acts);
    echo hr_flash_html($flash) . hr_assess_css();
    echo '<div class="card">' . hr_assess_sheet_html($s) . '</div>';
    if ($canEdit) {
        echo '<div class="no-print">';
        if ($s['status'] === 'submitted') {
            echo hr_button_form('Reopen for the interviewer', ['action' => 'sheet_reopen', 'id' => $sheetId], 'btn btn-sm', 'Let ' . $s['iv_name'] . ' change this sheet again?');
        }
        echo hr_button_form('Remove this assignment', ['action' => 'sheet_remove', 'id' => $sheetId, 'sl' => $s['shortlist_id']], 'btn btn-sm btn-danger',
                            'Remove this sheet' . ($s['status'] !== 'pending' ? ' and everything the interviewer entered on it' : '') . '?') . '</div>';
    }
    hr_layout_end();
    return;
}

/* -------------------------------------------- one shortlisted candidate */

$slId = (int) ($_GET['sl'] ?? 0);
if ($slId) {
    $c = hr_shortlist_get($slId);
    if (!$c) {
        fabo_deny('That candidate is not available to you.', 404);
    }
    $v = $flash ? $_POST + $c : $c;
    hr_layout_start($c['full_name'], e($c['position']) . ($c['rank_no'] ? ' · Rank ' . (int) $c['rank_no'] : '')
                  . ($c['decision'] ? ' · ' . hr_assess_pill(['selected' => 'green', 'hold' => 'amber', 'rejected' => 'red'][$c['decision']], $decisions[$c['decision']]) : ''),
                    ['Interview assessments' => hr_url('assessment'), $c['full_name'] => ''],
                    $c['resume_file'] ? '<a class="btn btn-sm" target="_blank" rel="noopener" href="' . e(hr_url('assessment', ['resume' => $slId])) . '">Résumé</a>' : '');
    echo hr_flash_html($flash);
    $sheets = hr_assess_list(['shortlist' => $slId]);
    echo '<div class="card"><h2>Assessment sheets</h2>';
    if (!$sheets) {
        echo hr_empty('Not assigned to an interviewer yet.', ' Tick this candidate on the shortlist and assign them.');
    } else {
        echo '<div class="scrollx"><table class="data hr-table stack"><thead><tr><th>Round</th><th>Interviewer</th><th>Interview</th><th>Status</th><th class="num">Total /50</th><th class="num">Avg /5</th><th>Recommendation</th><th></th></tr></thead><tbody>';
        foreach ($sheets as $s) {
            echo '<tr><td data-l="Round">' . e($rounds[$s['round']] ?? $s['round']) . '</td><td data-l="Interviewer"><strong>' . e($s['iv_name']) . '</strong><div class="muted small">' . e($s['iv_designation'] ?? '') . '</div></td>'
               . '<td data-l="Interview" class="nowrap">' . e($s['interview_at'] ? date('j M Y, g:i A', strtotime($s['interview_at'])) : '—') . '</td>'
               . '<td data-l="Status">' . hr_assess_pill($statusTone[$s['status']] ?? 'grey', $statuses[$s['status']] ?? $s['status']) . '</td>'
               . '<td class="num" data-l="Total">' . ($s['status'] === 'submitted' ? (int) $s['total'] : '—') . '</td>'
               . '<td class="num" data-l="Average">' . ($s['status'] === 'submitted' ? number_format($s['total'] / 10, 1) : '—') . '</td>'
               . '<td data-l="Recommendation">' . ($s['status'] === 'submitted' && $s['recommendation'] ? hr_assess_pill(hr_assess_rec_tone($s['recommendation']), $recs[$s['recommendation']]) : '—') . '</td>'
               . '<td><a class="btn btn-sm" href="' . e(hr_url('assessment', ['sheet' => $s['id']])) . '">View sheet</a></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';

    /* CEO approval, then the offer letter — which only an approval unlocks. */
    $ast = $c['approval_status'];
    echo '<div class="hr-two"><div class="card"><h2>CEO approval</h2>';
    if ($ast) {
        echo '<p class="mt0">' . hr_assess_pill(hr_approval_tone($ast), hr_approval_statuses()[$ast]) . ' <span class="muted small">'
           . e($ast === 'pending' ? 'Sent ' . date('j M Y, g:i A', strtotime($c['approval_sent_at'])) : ($c['approval_by'] . ' · ' . date('j M Y, g:i A', strtotime($c['approval_at'])))) . '</span></p>'
           . ($c['approval_remarks'] ? '<p><strong>CEO\'s remarks:</strong><br>' . nl2br(e($c['approval_remarks'])) . '</p>' : '')
           . ($c['approval_note'] ? '<p class="muted small">Your note to the CEO: ' . e($c['approval_note']) . '</p>' : '');
    } else {
        echo '<p class="mt0 muted">Not sent to the CEO yet.</p>';
    }
    if ($canEdit && $ast !== 'approved' && $ast !== 'pending') {
        echo hr_form_open(hr_url('assessment', ['sl' => $slId]), 'class="hr-form"') . '<input type="hidden" name="action" value="approval_send"><input type="hidden" name="id" value="' . $slId . '">'
           . hr_field('Note to the CEO (optional)', '<textarea name="ceo_note" rows="2" maxlength="5000"></textarea>')
           . '<button class="btn btn-primary">' . ($ast === 'rejected' ? 'Send to CEO again' : 'Send to CEO for approval') . '</button></form>';
    }
    echo '</div><div class="card"><h2>Offer letter</h2>';
    if ($ast !== 'approved') {
        echo hr_empty('Available once the CEO approves.', $ast === 'rejected' ? ' The CEO rejected this candidate.' : ' Send the candidate to the CEO first.');
    } else {
        if ($c['offer_sent_at']) {
            echo '<p class="mt0">' . hr_assess_pill('green', 'Sent') . ' <span class="muted small">' . e(date('j M Y, g:i A', strtotime($c['offer_sent_at'])) . ' · ' . $c['offer_sent_by'])
               . '</span> · <a href="' . e(hr_url('assessment', ['offer' => $slId])) . '" target="_blank" rel="noopener">Letter as sent</a></p>';
        }
        if ($canEdit) {
            $ov = $flash && in_array($_POST['action'] ?? '', ['offer_send', 'offer_preview'], true) ? $_POST : $c;
            echo hr_form_open(hr_url('assessment', ['sl' => $slId]), 'class="hr-form"') . '<input type="hidden" name="id" value="' . $slId . '"><div class="formgrid">'
               . hr_field('Designation', hr_input('offer_designation', $ov['offer_designation'] ?? $c['position'], 'text', ['required' => true, 'maxlength' => 160]), true)
               . hr_field('Annual CTC (₹)', hr_input('offer_salary', $ov['offer_salary'] ?? '', 'number', ['min' => 0, 'step' => '1']))
               . hr_field('Joining date', hr_input('offer_join_date', $ov['offer_join_date'] ?? '', 'date'))
               . hr_field('Place of work', hr_input('offer_place', $ov['offer_place'] ?? 'Amaravati', 'text', ['maxlength' => 160]))
               . '</div><div class="hr-formfoot"><button class="btn" name="action" value="offer_preview" formtarget="_blank">Preview letter (PDF)</button>'
               . '<button class="btn btn-primary" name="action" value="offer_send" data-confirm="Email the offer letter to ' . e($c['full_name']) . ' at ' . e($c['email'] ?: '(no email)') . '?">'
               . ($c['offer_sent_at'] ? 'Send offer letter again' : 'Send offer letter') . '</button></div>'
               . '<p class="hr-hint">The candidate is emailed a private link to download the letter, sent under the organisation\'s name.</p></form>';
        }
    }
    echo '</div></div>';

    if ($canEdit) {
        echo '<div class="card"><h2>Details and decision</h2>' . hr_form_open(hr_url('assessment', ['sl' => $slId]), 'class="hr-form"', true)
           . '<input type="hidden" name="action" value="sl_save"><input type="hidden" name="id" value="' . $slId . '"><div class="formgrid">'
           . hr_sl_fields($v)
           . hr_field('HR decision', hr_select('decision', $decisions, $v['decision'] ?? '', 'Not decided'))
           . '</div><button class="btn btn-primary">Save</button></form></div>';
    }
    hr_layout_end();
    return;
}

/** The candidate fields, shared by the add form and the edit form. */
function hr_sl_fields(array $v): string
{
    return hr_field('Candidate name', hr_input('full_name', $v['full_name'] ?? '', 'text', ['required' => true, 'maxlength' => 160]), true)
         . hr_field('Position applied for', hr_input('position', $v['position'] ?? '', 'text', ['required' => true, 'maxlength' => 160, 'list' => 'hr-sl-positions']), true)
         . hr_field('Rank', hr_input('rank_no', $v['rank_no'] ?? '', 'number', ['min' => 1, 'step' => 1]))
         . hr_field('Email', hr_input('email', $v['email'] ?? '', 'email', ['maxlength' => 190]))
         . hr_field('Phone', hr_input('phone', $v['phone'] ?? '', 'tel', ['maxlength' => 40]))
         . hr_field('Hall ticket number', hr_input('hall_ticket', $v['hall_ticket'] ?? '', 'text', ['maxlength' => 40]))
         . hr_field('Written exam — objective', hr_input('written_obj', $v['written_obj'] ?? '', 'number', ['min' => 0, 'step' => '0.01']))
         . hr_field('Written exam — descriptive', hr_input('written_desc', $v['written_desc'] ?? '', 'number', ['min' => 0, 'step' => '0.01']))
         . hr_field('Written exam — total', hr_input('written_total', $v['written_total'] ?? '', 'number', ['min' => 0, 'step' => '0.01']), false, 'Left empty, it is objective + descriptive.')
         . hr_field('Qualification', hr_input('qualification', $v['qualification'] ?? '', 'text', ['maxlength' => 255]))
         . hr_field('Experience', hr_input('experience', $v['experience'] ?? '', 'text', ['maxlength' => 120, 'placeholder' => 'e.g. 4 years']))
         . hr_field('Résumé', '<input type="file" name="resume" accept=".pdf,.doc,.docx,.odt,.rtf,.txt">', false,
                    !empty($v['resume_name']) ? 'Now: ' . e($v['resume_name']) . '. Choosing a file replaces it.' : 'PDF or Word. The interviewers can open it from their dashboard.')
         . hr_field('Notes for the panel', '<textarea name="notes" rows="2" maxlength="5000">' . e($v['notes'] ?? '') . '</textarea>', false, '', 'full');
}

/* ---------------------------------------------------------------- desk */

$tabs = ['shortlist' => 'Shortlisted candidates', 'panel' => 'Interviewers', 'results' => 'Results', 'approval' => 'CEO approval'];
$tab = hr_tab($tabs, 'shortlist');
hr_layout_start('Interview Assessments', 'Shortlisted candidates, the interview panel and their assessment sheets.', ['Interview assessments' => ''],
                '<a class="btn btn-sm" href="' . e(hr_url('assessment', ['export' => 1])) . '">Export all sheets (CSV)</a>');
echo hr_flash_html($flash);
if ($importRes) {
    echo hr_import_report($importRes[0], $importRes[1]);
}
hr_tabs('assessment', $tabs, $tab);
$positions = hr_shortlist_positions();
echo '<datalist id="hr-sl-positions">';
foreach ($positions as $p) {
    echo '<option value="' . e($p) . '">';
}
echo '</datalist>';

if ($tab === 'shortlist') {
    echo hr_guide('How interview assessments work', [
        'Add your interviewers on the <strong>Interviewers</strong> tab (name, email, designation).',
        '<strong>Add</strong> the shortlisted candidates below — one by one, by importing a spreadsheet, or by bringing them over from Recruitment — with their rank and written-exam marks.',
        '<strong>Tick</strong> the candidates one interviewer will see, choose the interviewer, the round and the date, and press <strong>Assign &amp; email dashboard</strong>.',
        'The interviewer gets an email with a private link to <strong>their own dashboard</strong>: their candidates, and the Interview Assessment Sheet for each. No sign-in is needed.',
        'They score the ten competencies from 1 to 5, add comments and their recommendation, and <strong>submit</strong>. You see the sheets, totals and averages under <strong>Results</strong>.',
        'Tick the assessed candidates and press <strong>Send to CEO for approval</strong>. The CEO approves, or rejects with remarks, from their own link (set the CEO on the <strong>CEO approval</strong> tab).',
        'Only after the CEO approves can you open the candidate and <strong>send the offer letter</strong>.',
    ]);
    $f = ['q' => trim((string) ($_GET['q'] ?? '')), 'position' => (string) ($_GET['position'] ?? ''), 'decision' => (string) ($_GET['decision'] ?? '')];
    $rows = hr_shortlist_list($f);
    $all = $f['q'] === '' && $f['position'] === '' && $f['decision'] === '' ? $rows : hr_shortlist_list();
    $assigned = count(array_filter($all, static fn($r) => (int) $r['sheets'] > 0));
    $done = count(array_filter($all, static fn($r) => (int) $r['sheets'] > 0 && (int) $r['submitted'] === (int) $r['sheets']));
    echo '<div class="tiles">' . hr_tile('Shortlisted', (string) count($all)) . hr_tile('Assigned to a panel', (string) $assigned, $assigned < count($all) ? 'amber' : '')
       . hr_tile('Fully assessed', (string) $done, 'green') . hr_tile('Selected', (string) count(array_filter($all, static fn($r) => $r['decision'] === 'selected')), 'green') . '</div>';

    if ($canEdit) {
        echo '<div class="hr-two"><details class="card"' . ($flash && ($_POST['action'] ?? '') === 'sl_save' ? ' open' : '') . '><summary><strong>+ Add a shortlisted candidate</strong></summary>'
           . hr_form_open(hr_url('assessment'), 'class="hr-form" style="margin-top:10px"', true) . '<input type="hidden" name="action" value="sl_save"><div class="formgrid">'
           . hr_sl_fields($flash && ($_POST['action'] ?? '') === 'sl_save' ? $_POST : ['position' => $f['position']])
           . '</div><button class="btn btn-primary">Add to shortlist</button></form></details>';
        echo '<details class="card"' . ($importRes || ($flash && ($_POST['action'] ?? '') === 'import') ? ' open' : '') . '><summary><strong>Import a list (Excel / CSV)</strong></summary>'
           . hr_form_open(hr_url('assessment'), 'class="hr-form" style="margin-top:10px"', true) . '<input type="hidden" name="action" value="import">'
           . '<p class="hr-hint mt0">First row headings: Hall Ticket Number, Rank, Candidate Name, Email, Phone, Position Applied For, Objective, Descriptive, Written Total, Qualification, Experience, Notes. '
           . '<a href="' . e(hr_url('assessment', ['template' => 1])) . '">Download the template</a>.</p><div class="formgrid">'
           . hr_field('File', '<input type="file" name="file" accept=".xlsx,.xls,.csv" required>', true)
           . hr_field('Position (when the file has none)', hr_input('position', $f['position'], 'text', ['maxlength' => 160, 'list' => 'hr-sl-positions']))
           . '</div>' . hr_checkbox('dry', false, 'Check the file only — do not save') . '<div style="margin-top:10px"><button class="btn btn-primary">Import</button></div></form>';
        $cands = hr_shortlist_candidate_choices();
        if ($cands) {
            echo '<hr style="margin:16px 0"><strong>Or bring over from Recruitment</strong>' . hr_form_open(hr_url('assessment'), 'class="hr-form" style="margin-top:8px"')
               . '<input type="hidden" name="action" value="from_cands"><select name="cand_ids[]" multiple size="6" style="width:100%">' . hr_options($cands, '') . '</select>'
               . '<div class="hr-hint">Hold Ctrl (⌘ on Mac) to pick several.</div><button class="btn btn-sm btn-primary" style="margin-top:8px">Add to shortlist</button></form>';
        }
        echo '</details></div>';
    }

    echo '<form method="get" class="card hr-filters" role="search"><input type="hidden" name="p" value="assessment">'
       . '<div class="grp"><label for="sq">Search</label><input id="sq" type="search" name="q" value="' . e($f['q']) . '" placeholder="Name, email, phone or hall ticket"></div>'
       . '<div class="grp"><label for="sp">Position</label><select id="sp" name="position" data-autosubmit>' . hr_options(array_combine($positions, $positions) ?: [], $f['position'], 'All positions') . '</select></div>'
       . '<div class="grp"><label for="sd">Decision</label><select id="sd" name="decision" data-autosubmit>' . hr_options(['none' => 'Not decided'] + $decisions, $f['decision'], 'Any') . '</select></div>'
       . '<button class="btn btn-sm btn-primary">Search</button></form>';

    echo '<div class="card">';
    if (!$rows) {
        echo hr_empty('No shortlisted candidates' . ($f['q'] . $f['position'] . $f['decision'] !== '' ? ' match.' : ' yet.'), $canEdit && !$all ? ' Add or import them above.' : '');
    } else {
        echo $canEdit ? hr_form_open(hr_url('assessment'), 'id="hr-sl-bulk"') . '<input type="hidden" name="action" value="bulk"><input type="hidden" name="f_position" value="' . e($f['position']) . '">' : '';
        echo '<div class="scrollx"><table class="data hr-table stack"><thead><tr>' . ($canEdit ? '<th><input type="checkbox" data-checkall="ids[]" aria-label="Select all"></th>' : '')
           . '<th class="num">Rank</th><th>Candidate</th><th>Position</th><th>Hall ticket</th><th class="num">Written (obj / desc / total)</th><th>Assigned to</th><th class="num">Avg /50</th><th>Decision</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $who = '';
            foreach ($r['assigned'] as $as) {
                $who .= '<div class="small">' . e($as['name']) . ' <span class="muted">· ' . e('Round ' . substr($as['round'], 1)) . '</span> '
                      . hr_assess_pill($statusTone[$as['status']] ?? 'grey', $as['status'] === 'submitted' ? (int) $as['total'] . '/50' : $statuses[$as['status']]) . '</div>';
            }
            echo '<tr>' . ($canEdit ? '<td><input type="checkbox" name="ids[]" value="' . (int) $r['id'] . '" aria-label="Select ' . e($r['full_name']) . '"></td>' : '')
               . '<td class="num" data-l="Rank">' . e($r['rank_no'] ?? '—') . '</td>'
               . '<td data-l="Candidate"><a class="nm" href="' . e(hr_url('assessment', ['sl' => $r['id']])) . '"><strong>' . e($r['full_name']) . '</strong></a>' . ($r['resume_file'] ? ' <a class="small" target="_blank" rel="noopener" href="' . e(hr_url('assessment', ['resume' => $r['id']])) . '">Résumé</a>' : '') . '<div class="muted small">' . e(implode(' · ', array_filter([$r['email'], $r['phone']]))) . '</div></td>'
               . '<td data-l="Position">' . e($r['position']) . '</td>'
               . '<td data-l="Hall ticket">' . e($r['hall_ticket'] ?? '—') . '</td>'
               . '<td class="num" data-l="Written">' . e(hr_assess_fmt($r['written_obj'])) . ' / ' . e(hr_assess_fmt($r['written_desc'])) . ' / <strong>' . e(hr_assess_fmt($r['written_total'])) . '</strong></td>'
               . '<td data-l="Assigned to">' . ($who ?: '<span class="muted small">Not assigned</span>') . '</td>'
               . '<td class="num" data-l="Avg /50">' . ($r['avg_total'] !== null ? '<strong>' . e(hr_assess_fmt(round((float) $r['avg_total'], 1))) . '</strong>' : '—') . '</td>'
               . '<td data-l="Decision">' . ($r['approval_status'] ? hr_assess_pill(hr_approval_tone($r['approval_status']), hr_approval_statuses()[$r['approval_status']])
                    . ($r['offer_sent_at'] ? ' ' . hr_assess_pill('green', 'Offer sent') : '')
                    : ($r['decision'] ? hr_assess_pill(['selected' => 'green', 'hold' => 'amber', 'rejected' => 'red'][$r['decision']], $decisions[$r['decision']]) : '—')) . '</td></tr>';
        }
        echo '</tbody></table></div>';
        if ($canEdit) {
            $panel = hr_panelists();
            echo '<div class="formsec" style="margin-top:16px"><h3>Assign the ticked candidates to an interviewer</h3><div class="formgrid">'
               . hr_field('Interviewer', hr_select('panelist_id', array_column(array_map(static fn($p) => ['id' => $p['id'], 'l' => $p['name'] . ($p['designation'] ? ' — ' . $p['designation'] : '') . ' <' . $p['email'] . '>'], $panel), 'l', 'id'),
                          '', $panel ? 'Choose…' : 'No interviewers yet', ['required' => true]), true,
                          'Not in the list? Add them on the <a href="' . e(hr_url('assessment', ['tab' => 'panel'])) . '">Interviewers</a> tab first.')
               . hr_field('Interview round', hr_select('round', $rounds, 'r1'), true)
               . hr_field('Interview date & time', hr_input('interview_at', '', 'datetime-local'))
               . hr_field('Entity / location', hr_input('location', 'Amaravati', 'text', ['maxlength' => 120]))
               . '</div>' . hr_checkbox('send', true, 'Email the interviewer the link to their dashboard')
               . '<div style="margin-top:10px"><button class="btn btn-primary" name="do" value="assign">Assign &amp; email dashboard</button></div></div>'
               . '<div class="formsec" style="margin-top:16px"><h3>Send the ticked candidates to the CEO for approval</h3>'
               . '<p class="hr-hint mt0">Only candidates with at least one submitted assessment are sent. The CEO gets a link to approve or reject them; '
               . 'an offer letter can be sent only after approval.</p>'
               . hr_field('Note to the CEO (optional)', '<textarea name="ceo_note" rows="2" maxlength="5000"></textarea>')
               . '<button class="btn btn-primary" name="do" value="ceo" formnovalidate data-confirm="Send the ticked candidates to the CEO for approval?">Send to CEO for approval</button></div>'
               . '<div class="formsec" style="margin-top:16px"><h3>Or, for the ticked candidates</h3><div class="hr-filters" style="padding:0">'
               . '<div class="grp"><label for="bd">Decision</label><select id="bd" name="decision">' . hr_options($decisions, '', 'Clear decision') . '</select></div>'
               . '<button class="btn btn-sm" name="do" value="decide" formnovalidate>Record decision</button>'
               . ($canDelete ? '<button class="btn btn-sm btn-danger" name="do" value="delete" formnovalidate data-confirm="Remove the ticked candidates and all their assessment sheets?">Remove from shortlist</button>' : '')
               . '</div></div></form>';
        }
    }
    echo '</div>';
}

if ($tab === 'panel') {
    $panel = hr_panelists();
    echo '<div class="card"><h2>Interviewers</h2><p class="hr-hint mt0">Each interviewer has one private dashboard link listing every candidate assigned to them. '
       . 'Add interviewers here; then assign shortlisted candidates to them from the Shortlisted candidates tab.</p>';
    if ($canEdit) {
        $editP = (int) ($_GET['edit'] ?? 0) ? hr_panelist_get((int) $_GET['edit']) : null;
        $pv = $flash && ($_POST['action'] ?? '') === 'panel_save' ? $_POST : ($editP ?? []);
        echo '<div class="formsec"><h3>' . ($editP ? 'Edit ' . e($editP['name']) : '+ Add an interviewer') . '</h3>'
           . hr_form_open(hr_url('assessment', ['tab' => 'panel']), 'class="hr-form"') . '<input type="hidden" name="action" value="panel_save">'
           . ($editP ? '<input type="hidden" name="id" value="' . (int) $editP['id'] . '">' : '') . '<div class="formgrid">'
           . hr_field('Name', hr_input('name', $pv['name'] ?? '', 'text', ['required' => true, 'maxlength' => 160]), true)
           . hr_field('Email', hr_input('email', $pv['email'] ?? '', 'email', ['required' => true, 'maxlength' => 190]), true, 'Their dashboard link is sent here.')
           . hr_field('Designation', hr_input('designation', $pv['designation'] ?? '', 'text', ['maxlength' => 160, 'placeholder' => 'e.g. Director – Programmes']))
           . '</div><button class="btn btn-primary">' . ($editP ? 'Save' : 'Add interviewer') . '</button>'
           . ($editP ? ' <a class="btn" href="' . e(hr_url('assessment', ['tab' => 'panel'])) . '">Cancel</a>' : '') . '</form></div><hr style="margin:16px 0">';
    }
    if (!$panel) {
        echo hr_empty('No interviewers yet.', $canEdit ? ' Add the first one above.' : '');
    } else {
        echo '<div class="scrollx"><table class="data hr-table stack"><thead><tr><th>Interviewer</th><th class="num">Assigned</th><th class="num">Submitted</th><th>Link last emailed</th><th>Last opened</th><th>Dashboard link</th><th></th></tr></thead><tbody>';
        foreach ($panel as $p) {
            $link = hr_panelist_link($p);
            echo '<tr><td data-l="Interviewer"><strong>' . e($p['name']) . '</strong><div class="muted small">' . e(implode(' · ', array_filter([$p['designation'], $p['email']]))) . '</div></td>'
               . '<td class="num" data-l="Assigned">' . (int) $p['sheets'] . '</td><td class="num" data-l="Submitted">' . (int) $p['submitted'] . '</td>'
               . '<td data-l="Emailed" class="nowrap">' . e($p['last_sent_at'] ? date('j M, g:i A', strtotime($p['last_sent_at'])) : '—') . '</td>'
               . '<td data-l="Opened" class="nowrap">' . e($p['last_seen_at'] ? date('j M, g:i A', strtotime($p['last_seen_at'])) : 'Not yet') . '</td>'
               . '<td data-l="Link"><input type="text" readonly value="' . e($link) . '" onclick="this.select()" style="width:100%;min-width:220px;font-size:12px"></td><td class="hr-acts">'
               . ($canEdit ? hr_button_form('Email link again', ['action' => 'panel_send', 'id' => $p['id']], 'btn btn-sm')
                           . hr_button_form('New link', ['action' => 'panel_newlink', 'id' => $p['id']], 'btn btn-sm', 'Make a new link? The old one will stop working.')
                           . '<a class="btn btn-sm" href="' . e(hr_url('assessment', ['tab' => 'panel', 'edit' => $p['id']])) . '">Edit</a>'
                           . ((int) $p['submitted'] === 0 ? hr_button_form('Remove', ['action' => 'panel_delete', 'id' => $p['id']], 'btn btn-sm btn-danger',
                                 'Remove ' . $p['name'] . ((int) $p['sheets'] ? ' and their ' . (int) $p['sheets'] . ' unsubmitted assignment(s)?' : '?')) : '') : '')
               . '<a class="btn btn-sm" href="' . e(hr_url('assessment', ['tab' => 'results', 'panelist' => $p['id']])) . '">Sheets</a></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';
}

if ($tab === 'approval') {
    $ceo = hr_ceo();
    echo '<div class="card"><h2>CEO</h2><p class="hr-hint mt0">Candidates are approved by this person from a private link. Only HR can set it and send candidates; '
       . 'only candidates the CEO approves can be sent an offer letter.</p>';
    if ($canEdit) {
        $cv = $flash && ($_POST['action'] ?? '') === 'ceo_save' ? $_POST : $ceo;
        echo hr_form_open(hr_url('assessment', ['tab' => 'approval']), 'class="hr-form"') . '<input type="hidden" name="action" value="ceo_save"><div class="formgrid">'
           . hr_field('Name', hr_input('name', $cv['name'] ?? '', 'text', ['required' => true, 'maxlength' => 160]), true)
           . hr_field('Email', hr_input('email', $cv['email'] ?? '', 'email', ['required' => true, 'maxlength' => 190]), true, 'The approval link is sent here.')
           . hr_field('Designation', hr_input('designation', ($cv['designation'] ?? '') ?: 'Chief Executive Officer', 'text', ['maxlength' => 160]))
           . '</div><button class="btn btn-primary">Save CEO</button></form>';
    } elseif ($ceo['email'] !== '') {
        echo '<p>' . e($ceo['name'] . ' · ' . $ceo['designation'] . ' · ' . $ceo['email']) . '</p>';
    }
    if ($ceo['token'] !== '' && $canEdit) {
        echo '<div class="formsec" style="margin-top:14px"><label class="muted small">Approval page link</label>'
           . '<input type="text" readonly value="' . e(hr_ceo_link($ceo)) . '" onclick="this.select()" style="width:100%;font-size:12px;margin:4px 0 8px">'
           . hr_button_form('Email the link to the CEO', ['action' => 'ceo_resend'], 'btn btn-sm')
           . hr_button_form('New link', ['action' => 'ceo_newlink'], 'btn btn-sm', 'Make a new link? The old one will stop working.') . '</div>';
    }
    echo '</div>';
    $list = hr_approval_list(hr_org());
    echo '<div class="card"><h2>Candidates sent for approval</h2>';
    if (!$list) {
        echo hr_empty('None yet.', ' Tick assessed candidates on the shortlist and press “Send to CEO for approval”.');
    } else {
        echo '<div class="scrollx"><table class="data hr-table stack"><thead><tr><th>Candidate</th><th>Position</th><th class="num">Interview avg /50</th><th>CEO decision</th><th>CEO remarks</th><th>Offer letter</th><th></th></tr></thead><tbody>';
        foreach ($list as $s) {
            $sum = hr_approval_summary(hr_org(), (int) $s['id']);
            echo '<tr><td data-l="Candidate"><strong>' . e($s['full_name']) . '</strong></td><td data-l="Position">' . e($s['position']) . '</td>'
               . '<td class="num" data-l="Interview avg">' . e($sum['avg'] !== null ? hr_assess_fmt($sum['avg']) : '—') . '</td>'
               . '<td data-l="CEO decision">' . hr_assess_pill(hr_approval_tone($s['approval_status']), hr_approval_statuses()[$s['approval_status']])
               . '<div class="muted small">' . e($s['approval_at'] ? date('j M Y', strtotime($s['approval_at'])) : 'Sent ' . date('j M Y', strtotime($s['approval_sent_at']))) . '</div></td>'
               . '<td data-l="Remarks" class="small">' . nl2br(e($s['approval_remarks'] ?? '')) . '</td>'
               . '<td data-l="Offer">' . ($s['offer_sent_at'] ? hr_assess_pill('green', 'Sent ' . date('j M', strtotime($s['offer_sent_at']))) : ($s['approval_status'] === 'approved' ? hr_assess_pill('amber', 'To send') : '—')) . '</td>'
               . '<td><a class="btn btn-sm" href="' . e(hr_url('assessment', ['sl' => $s['id']])) . '">Open</a></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';
}

if ($tab === 'results') {
    $pf = (int) ($_GET['panelist'] ?? 0);
    $posf = (string) ($_GET['position'] ?? '');
    $sheets = hr_assess_list(['panelist' => $pf, 'position' => $posf]);
    $panelMap = array_column(hr_panelists(), 'name', 'id');
    echo '<form method="get" class="card hr-filters"><input type="hidden" name="p" value="assessment"><input type="hidden" name="tab" value="results">'
       . '<div class="grp"><label for="rp">Position</label><select id="rp" name="position" data-autosubmit>' . hr_options(array_combine($positions, $positions) ?: [], $posf, 'All positions') . '</select></div>'
       . '<div class="grp"><label for="ri">Interviewer</label><select id="ri" name="panelist" data-autosubmit>' . hr_options($panelMap, $pf ?: '', 'Everyone') . '</select></div>'
       . '<noscript><button class="btn btn-sm">Show</button></noscript><a class="btn btn-sm" href="' . e(hr_url('assessment', ['export' => 1, 'position' => $posf])) . '">Export CSV</a></form>';
    echo '<div class="card">';
    if (!$sheets) {
        echo hr_empty('No assessment sheets yet.');
    } else {
        echo '<div class="scrollx"><table class="data hr-table stack"><thead><tr><th class="num">Rank</th><th>Candidate</th><th>Position</th><th>Round</th><th>Interviewer</th><th>Status</th>'
           . '<th class="num">Total /50</th><th class="num">Avg /5</th><th>Recommendation</th><th></th></tr></thead><tbody>';
        foreach ($sheets as $s) {
            $sub = $s['status'] === 'submitted';
            echo '<tr><td class="num" data-l="Rank">' . e($s['rank_no'] ?? '—') . '</td><td data-l="Candidate"><a class="nm" href="' . e(hr_url('assessment', ['sl' => $s['shortlist_id']])) . '"><strong>' . e($s['full_name']) . '</strong></a></td>'
               . '<td data-l="Position">' . e($s['position']) . '</td><td data-l="Round">' . e($rounds[$s['round']] ?? $s['round']) . '</td><td data-l="Interviewer">' . e($s['iv_name']) . '</td>'
               . '<td data-l="Status">' . hr_assess_pill($statusTone[$s['status']] ?? 'grey', $statuses[$s['status']] ?? $s['status']) . '</td>'
               . '<td class="num" data-l="Total">' . ($sub ? '<strong>' . (int) $s['total'] . '</strong>' : '—') . '</td><td class="num" data-l="Avg">' . ($sub ? number_format($s['total'] / 10, 1) : '—') . '</td>'
               . '<td data-l="Recommendation">' . ($sub && $s['recommendation'] ? hr_assess_pill(hr_assess_rec_tone($s['recommendation']), $recs[$s['recommendation']]) : '—') . '</td>'
               . '<td><a class="btn btn-sm" href="' . e(hr_url('assessment', ['sheet' => $s['id']])) . '">View</a></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';
}

hr_layout_end();

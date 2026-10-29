<?php
/**
 * Incubation Management — Gems: each Gem's set-up (instructions, rubric,
 * questions, email), who it has been sent to, who has answered, and their
 * scores; and the two ways to send it — existing applicants (from the
 * Applications list, bulk action "Email a Gem form") or a pasted list of
 * addresses here.
 */

$gems = inc_gems();
$new = ($_GET['gem'] ?? '') === 'new' && inc_can('configure');
$gid = $new ? 0 : (int) ($_GET['gem'] ?? array_key_first($gems) ?? 0);
$gem = $gems[$gid] ?? null;
$self = inc_url('gems', $new ? ['gem' => 'new'] : ($gem ? ['gem' => $gid] : []));
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    $flash = inc_action(static function () use ($a, $gid) {
        switch ($a) {
            case 'save':
                $id = inc_gem_save($gid ?: null, $_POST);
                inc_redirect(inc_url('gems', ['gem' => $id, 'tab' => 'setup']), ['ok', 'Gem saved.']);
            case 'invite':
                return inc_gem_send_summary(inc_gem_invite($gid, (string) ($_POST['emails'] ?? '')));
            case 'resend':
                return inc_gem_send_summary(inc_gem_send($gid, [(int) ($_POST['app_id'] ?? 0)]));
            case 'remind':
                inc_need('edit');
                $ids = array_map(static fn($r) => (int) $r['application_id'],
                                 inc_all('SELECT application_id FROM inc_gem_requests WHERE gem_id = ? AND submitted_at IS NULL AND sent_at IS NOT NULL', 'i', [$gid]));
                if (!$ids) {
                    throw new InvalidArgumentException('Everyone who was sent the form has answered.');
                }
                return 'Reminder: ' . inc_gem_send_summary(inc_gem_send($gid, $ids));
            case 'score':
                inc_need('edit');
                [$ok, $msg] = inc_gem_score((int) ($_POST['req_id'] ?? 0));
                if (!$ok) {
                    throw new InvalidArgumentException($msg);
                }
                return $msg;
            case 'score_pending':
                inc_need('edit');
                set_time_limit(300);
                $n = inc_gem_score_pending(15);
                return $n . ' scored. ' . ($n === 15 ? 'Press again for the rest.' : '');
        }
        throw new InvalidArgumentException('Unknown action.');
    }, $self);
    $gems = inc_gems();
    $gem = $gems[$gid] ?? null;
}

$acts = inc_can('configure') ? '<a class="btn" href="' . e(inc_url('gems', ['gem' => 'new'])) . '">New Gem</a>' : '';
inc_layout_start('Gems', 'Gemini set-ups that score applicants\' answers against your rubric. The score shows only at the stages you choose.', ['Gems' => null], $acts, $flash);

if (!$gems && !$new) {
    echo inc_empty('No Gems yet.', inc_can('configure') ? 'Create one with <a href="' . e(inc_url('gems', ['gem' => 'new'])) . '">New Gem</a>.' : 'Ask the owner of Incubation Management to create one.');
    inc_layout_end();
    return;
}

if ($gems) {
    echo '<nav class="subtabs" aria-label="Gems">';
    foreach ($gems as $id => $g) {
        echo '<a href="' . e(inc_url('gems', ['gem' => $id])) . '"' . ($id === $gid && !$new ? ' class="on" aria-current="page"' : '') . '>' . e($g['name'])
           . ((int) $g['active'] !== 1 ? ' <span class="muted small">(off)</span>' : '') . '</a>';
    }
    if ($new) {
        echo '<a class="on" aria-current="page" href="' . e(inc_url('gems', ['gem' => 'new'])) . '">New Gem</a>';
    }
    echo '</nav>';
}

$tab = $new ? 'setup' : ((($_GET['tab'] ?? '') === 'setup' && inc_can('configure')) ? 'setup' : 'responses');
if ($gem && !$new) {
    echo '<nav class="subtabs" aria-label="Sections"><a href="' . e(inc_url('gems', ['gem' => $gid])) . '"' . ($tab === 'responses' ? ' class="on"' : '') . '>Responses &amp; scores</a>'
       . (inc_can('configure') ? '<a href="' . e(inc_url('gems', ['gem' => $gid, 'tab' => 'setup'])) . '"' . ($tab === 'setup' ? ' class="on"' : '') . '>Set-up</a>' : '') . '</nav>';
}

/* ------------------------------------------------------------ responses */
if ($tab === 'responses' && $gem) {
    $show = (string) ($_GET['show'] ?? '');
    $where = ['r.gem_id = ?'];
    $where[] = ['waiting' => 'r.submitted_at IS NULL', 'answered' => 'r.submitted_at IS NOT NULL', 'scored' => 'r.score IS NOT NULL',
                'unscored' => 'r.submitted_at IS NOT NULL AND r.score IS NULL'][$show] ?? '1=1';
    $rows = inc_all('SELECT r.*, a.ref, a.applicant_name, a.startup_name, a.stage_id, st.name AS stage_name FROM inc_gem_requests r
                     JOIN inc_applications a ON a.id = r.application_id JOIN inc_stages st ON st.id = a.stage_id
                     WHERE ' . implode(' AND ', $where) . ' ORDER BY r.score IS NULL, r.score DESC, r.submitted_at IS NULL, r.sent_at DESC LIMIT 1000', 'i', [$gid]);
    $c = inc_one('SELECT COUNT(*) AS sent, SUM(submitted_at IS NOT NULL) AS answered, SUM(score IS NOT NULL) AS scored, AVG(score) AS avg,
                         SUM(submitted_at IS NOT NULL AND score IS NULL) AS unscored FROM inc_gem_requests WHERE gem_id = ?', 'i', [$gid]);
    $stagesShowing = inc_gem_stage_names($gid);
    echo '<div class="tiles inc-tiles">'
       . inc_tile('Sent', number_format((int) $c['sent']), inc_url('gems', ['gem' => $gid]))
       . inc_tile('Awaiting answers', number_format((int) $c['sent'] - (int) $c['answered']), inc_url('gems', ['gem' => $gid, 'show' => 'waiting']), (int) $c['sent'] - (int) $c['answered'] ? 'amber' : '')
       . inc_tile('Answered', number_format((int) $c['answered']), inc_url('gems', ['gem' => $gid, 'show' => 'answered']))
       . inc_tile('Scored', number_format((int) $c['scored']), inc_url('gems', ['gem' => $gid, 'show' => 'scored']), 'green',
                  $c['avg'] !== null ? 'Average ' . number_format((float) $c['avg'], 1) . ' / ' . rtrim(rtrim(number_format($gem['max'], 1), '0'), '.') : '')
       . ((int) $c['unscored'] ? inc_tile('Waiting to be scored', number_format((int) $c['unscored']), inc_url('gems', ['gem' => $gid, 'show' => 'unscored']), 'red') : '')
       . '</div>';
    if (!$stagesShowing) {
        echo '<div class="inc-warn">No pipeline stage shows this Gem\'s score yet. Open the <a href="' . e(inc_url('applications', ['view' => 'board'])) . '">Applications board</a>, use the ⋯ menu on a column (not New) and tick this Gem.</div>';
    } else {
        echo '<p class="muted small">Scores show on applications at: <strong>' . e(implode(', ', $stagesShowing)) . '</strong>. This page shows every score to whoever can see Gems.</p>';
    }

    echo '<div class="inc-grid2 wide-left"><section class="card"><h2>Responses</h2>'
       . '<div class="inc-row">' . implode(' ', array_map(static fn($k, $l) => '<a class="btn btn-sm' . ($k === $show ? ' btn-primary' : '') . '" href="' . e(inc_url('gems', ['gem' => $gid] + ($k !== '' ? ['show' => $k] : []))) . '">' . e($l) . '</a>',
              ['', 'waiting', 'answered', 'scored', 'unscored'], ['All', 'Awaiting answers', 'Answered', 'Scored', 'Not scored yet'])) . '</div>';
    if (!$rows) {
        echo inc_empty('Nobody here yet.', 'Send the form from the right, or select applications under <a href="' . e(inc_url('applications')) . '">Applications</a> and choose "Email a Gem form".');
    } else {
        echo '<div class="scrollx"><table class="data stack"><thead><tr><th>Applicant</th><th>Stage</th><th>Emailed</th><th>Answered</th><th class="num">Score</th><th></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $mail = $r['sent_at'] ? e(inc_fmt_date($r['sent_at'])) . ($r['send_count'] > 1 ? ' <span class="muted small">×' . (int) $r['send_count'] . '</span>' : '')
                  . ($r['mail_status'] && $r['mail_status'] !== 'sent' ? ' ' . inc_badge($r['mail_status'], $r['mail_status'] === 'queued' ? 'blue' : 'red') : '') : '<span class="muted">—</span>';
            echo '<tr><td data-l="Applicant"><a data-popup="' . e(inc_url('quick', ['id' => $r['application_id']])) . '" href="' . e(inc_url('application', ['id' => $r['application_id']])) . '"><strong>' . e($r['startup_name'] !== '' ? $r['startup_name'] : $r['applicant_name']) . '</strong></a>'
               . '<div class="muted small">' . e($r['ref']) . ' · ' . e((string) $r['email']) . '</div></td>'
               . '<td data-l="Stage">' . e($r['stage_name']) . '</td><td data-l="Emailed">' . $mail . '</td>'
               . '<td data-l="Answered">' . ($r['submitted_at'] ? e(inc_fmt_date($r['submitted_at'])) : inc_badge('Waiting', 'amber')) . '</td>'
               . '<td data-l="Score" class="num">' . ($r['score'] !== null ? inc_gem_score_html($gem, (float) $r['score']) . ($r['scorecard_sent_at'] ? '<div class="muted small" title="' . e(inc_fmt_datetime($r['scorecard_sent_at'])) . '">card sent</div>' : '') : ($r['submitted_at'] ? ($r['score_error'] ? '<span class="muted small" title="' . e($r['score_error']) . '">failed</span>' : '<span class="muted small">pending</span>') : '<span class="muted">—</span>')) . '</td><td>';
            if (inc_can('edit')) {
                echo !$r['submitted_at'] ? inc_button('Send again', ['action' => 'resend', 'app_id' => $r['application_id']])
                   : ($r['score'] === null ? inc_button('Score now', ['action' => 'score', 'req_id' => $r['id']]) : '');
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</section><div>';
    if (inc_can('edit') && (int) $gem['active'] === 1) {
        echo '<section class="card"><h2>Send the form</h2>'
           . '<p class="small"><strong>To applicants already here:</strong> select them under <a href="' . e(inc_url('applications')) . '">Applications</a> (filters and "all matching" work), then choose the action <em>Email a Gem form</em>.</p>'
           . inc_form_open() . '<input type="hidden" name="action" value="invite">'
           . inc_field('Or paste new people, one per line', inc_textarea('emails', '', 6, 'placeholder="Asha Rao <asha@example.com>&#10;ravi@example.com, Ravi Kumar&#10;team@startup.in"'),
                       'Anyone without an application gets one (at New); anyone already here is matched by email. Each gets their own link.')
           . '<button class="btn btn-primary" data-confirm="Email the ' . e($gem['name']) . ' form to these people?">Send</button></form>'
           . ((int) $c['sent'] - (int) $c['answered'] ? '<hr>' . inc_button('Remind everyone who has not answered (' . ((int) $c['sent'] - (int) $c['answered']) . ')', ['action' => 'remind'], 'btn btn-sm', 'Email the form again to everyone who has not answered?') : '')
           . ((int) $c['unscored'] ? ' ' . inc_button('Score the ' . (int) $c['unscored'] . ' waiting', ['action' => 'score_pending'], 'btn btn-sm') : '')
           . ($gem['send_stage_id'] ? '<p class="muted small">Applicants move to <strong>' . e(inc_stage_map('crm')[(int) $gem['send_stage_id']] ?? '') . '</strong>.</p>' : '')
           . '</section>';
    }
    echo '<section class="card"><h2>Rubric</h2><table class="data"><tbody>';
    foreach ($gem['criteria'] as $cr) {
        echo '<tr><td><strong>' . e($cr['name']) . '</strong><div class="muted small">' . e($cr['asks']) . '</div></td><td class="num">' . e(rtrim(rtrim(number_format($cr['weight'], 1), '0'), '.')) . '</td></tr>';
    }
    echo '<tr><td><strong>Total</strong></td><td class="num"><strong>' . e(rtrim(rtrim(number_format($gem['max'], 1), '0'), '.')) . '</strong></td></tr></tbody></table>'
       . '<h3>Questions applicants answer</h3><ol class="small">';
    foreach ($gem['fields'] as $f) {
        echo '<li>' . e($f['label']) . ($f['required'] ? '' : ' <span class="muted">(optional)</span>')
           . (trim((string) ($f['help'] ?? '')) !== '' ? '<div class="muted small">' . nl2br(e(trim((string) $f['help']))) . '</div>' : '') . '</li>';
    }
    echo '</ol></section></div></div>';
}

/* --------------------------------------------------------------- set-up */

/**
 * The questions applicants answer, one row each: wording, answer length and
 * whether it must be answered. Rows can be added, removed and moved (inc.js);
 * without script, two blank rows are there to add to. A row's hidden uid keeps
 * it the same question when it is reworded.
 */
function inc_gem_questions_editor(array $p, array $g): string
{
    if (isset($p['q_label']) && is_array($p['q_label'])) {
        $rows = [];
        foreach ($p['q_label'] as $i => $label) {
            $rows[] = ['uid' => (string) ($p['q_uid'][$i] ?? ''), 'label' => (string) $label,
                       'help' => (string) ($p['q_help'][$i] ?? ''), 'type' => (string) ($p['q_type'][$i] ?? 'long'), 'required' => !empty($p['q_req'][$i])];
        }
    } else {
        $rows = $g['fields'];
    }
    $rows[] = ['uid' => '', 'label' => '', 'type' => 'long', 'required' => true];
    $rows[] = ['uid' => '', 'label' => '', 'type' => 'long', 'required' => true];
    $row = static function (int $i, array $f): string {
        return '<tr data-q-row><td class="inc-q-n" data-q-n></td>'
             . '<td><input type="hidden" name="q_uid[' . $i . ']" value="' . e($f['uid']) . '">'
             . '<input type="text" name="q_label[' . $i . ']" maxlength="160" value="' . e($f['label']) . '" placeholder="Question title" aria-label="Question">'
             . '<textarea name="q_help[' . $i . ']" rows="2" maxlength="1000" placeholder="Description shown under the question (optional) — what to explain, examples" aria-label="Description">' . e((string) ($f['help'] ?? '')) . '</textarea></td>'
             . '<td><select name="q_type[' . $i . ']" aria-label="Answer length">'
             . '<option value="long"' . ($f['type'] !== 'short' ? ' selected' : '') . '>Long answer</option>'
             . '<option value="short"' . ($f['type'] === 'short' ? ' selected' : '') . '>Short answer</option></select></td>'
             . '<td><label class="check"><input type="checkbox" name="q_req[' . $i . ']" value="1"' . ($f['required'] ? ' checked' : '') . '> Required</label></td>'
             . '<td class="inc-q-act"><button type="button" class="btn btn-sm" data-q-move="-1" title="Move up" aria-label="Move up" hidden>↑</button>'
             . '<button type="button" class="btn btn-sm" data-q-move="1" title="Move down" aria-label="Move down" hidden>↓</button>'
             . '<button type="button" class="btn btn-sm" data-q-del title="Remove" aria-label="Remove question" hidden>✕</button></td></tr>';
    };
    $h = '<fieldset class="inc-fs" data-q-editor><legend>Questions for applicants</legend>'
       . '<table class="inc-q"><thead><tr><th></th><th>Question</th><th>Answer</th><th>Must answer?</th><th></th></tr></thead><tbody>';
    foreach (array_values($rows) as $i => $f) {
        $h .= $row($i, $f);
    }
    $h .= '</tbody></table><template data-q-template>' . $row(999, ['uid' => '', 'label' => '', 'type' => 'long', 'required' => true]) . '</template>'
        . '<p><button type="button" class="btn btn-sm" data-q-add hidden>+ Add question</button></p>'
        . '<p class="muted small" style="margin:4px 0 0">Leave a question blank to remove it. You can change questions at any time: '
        . 'people who have not answered yet see the new questions; answers already given are kept exactly as they were given.</p></fieldset>';
    return $h;
}
if ($tab === 'setup') {
    inc_need('configure');
    $g = $gem && !$new ? $gem : ['name' => '', 'intro' => '', 'instructions' => '', 'criteria' => [], 'fields' => [], 'mail_subject' => 'Please complete: {gem}',
                                 'mail_body' => inc_gem_default_mail(), 'send_stage_id' => null, 'active' => 1,
                                 'ack_subject' => '', 'ack_body' => ''];
    [$ackS, $ackB] = inc_gem_default_ack();
    $usedBy = $gem && !$new ? array_column(inc_all('SELECT name FROM inc_forms WHERE gem_id = ? ORDER BY name', 'i', [$gid]), 'name') : [];
    $p = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : [];
    echo '<section class="card"><h2>' . ($new ? 'New Gem' : 'Set-up') . '</h2>' . inc_form_open() . '<input type="hidden" name="action" value="save">'
       . '<div class="inc-row">' . inc_field('Name', '<input type="text" name="name" maxlength="120" required value="' . e($p['name'] ?? $g['name']) . '">', '', true)
       . inc_field('When the applicant submits this Gem\'s form, move the application to', inc_select('send_stage_id', inc_stage_map('crm', true), $p['send_stage_id'] ?? $g['send_stage_id'], 'Leave it where it is'),
                   'Usually the stage that shows this Gem\'s score.') . '</div>'
       . inc_field('Instructions for Gemini (the Gem\'s role)', inc_textarea('instructions', (string) ($p['instructions'] ?? $g['instructions']), 6),
                   'Who it is, who the applicants are, how strict to be. Paste a Gem\'s instructions from gemini.google.com here.')
       . inc_field('Scoring criteria — one per line: Name | weight | what it asks', inc_textarea('criteria', (string) ($p['criteria'] ?? inc_gem_criteria_text($g)), 9, 'required'),
                   'The total of the weights is the score\'s maximum. Gemini marks each criterion up to its weight; the total is added up here.', true)
       . inc_gem_questions_editor($p, $g)
       . inc_field('Introduction shown above the questions', inc_textarea('intro', (string) ($p['intro'] ?? $g['intro']), 3))
       . '<div class="inc-row">' . inc_field('Email subject', '<input type="text" name="mail_subject" maxlength="190" value="' . e($p['mail_subject'] ?? $g['mail_subject']) . '">', '', false, 'grow') . '</div>'
       . inc_field('Email text', inc_textarea('mail_body', (string) ($p['mail_body'] ?? $g['mail_body']), 9),
                   'Fills in <code>{name}</code>, <code>{startup}</code>, <code>{startup_line}</code>, <code>{ref}</code>, <code>{gem}</code> and <code>{link}</code> — the applicant\'s personal link; keep it in.')
       . '<fieldset class="inc-fs"><legend>Acknowledgement email (for forms that send this Gem)</legend>'
       . '<p class="muted small" style="margin:0 0 8px">Which forms send it is chosen in each form\'s settings (Settings → Forms → Open builder). '
       . ($usedBy ? 'Sent by: <strong>' . e(implode(', ', $usedBy)) . '</strong>.' : 'No form sends it yet.') . '</p>'
       . inc_field('Acknowledgement subject', '<input type="text" name="ack_subject" maxlength="190" value="' . e($p['ack_subject'] ?? ($g['ack_subject'] ?: $ackS)) . '">')
       . inc_field('Acknowledgement text', inc_textarea('ack_body', (string) ($p['ack_body'] ?? ($g['ack_body'] ?: $ackB)), 9),
                   'Same placeholders as the email above; keep <code>{link}</code> in. Forms that send no Gem use the plain acknowledgement in Settings → Notifications.')
       . '</fieldset>'
       . '<label class="check"><input type="checkbox" name="active" value="1"' . (!empty($p) ? (!empty($p['active']) ? ' checked' : '') : ((int) $g['active'] === 1 ? ' checked' : '')) . '> Active (can be sent; links already sent keep working)</label><br>'
       . '<button class="btn btn-primary">Save Gem</button></form></section>';
}

inc_layout_end();

<?php
/**
 * Performance Reviews — cycles, each employee's review with weighted goals,
 * the self-assessment, the manager's rating and the final rating.
 *
 *   ?id=ID   one review
 */

require_once __DIR__ . '/parts.php';

$self = hr_self_emp();
$canCfg = hr_is_admin() || hr_scope('performance', 'configure') === 'org';
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    $rid = (int) ($_POST['id'] ?? 0);
    $back = $rid ? hr_url('performance', ['id' => $rid]) : hr_url('performance', ['tab' => (string) ($_GET['tab'] ?? '')]);
    if ($a === 'settings_apply') {
        $flash = hr_parts_post(hr_url('performance', ['tab' => 'cycles']));
    } elseif ($a === 'cycle_create') {
        $flash = hr_action(static function () {
            [$id, $n] = hr_cycle_create($_POST);
            hr_redirect(hr_url('performance', ['tab' => 'all', 'cycle' => $id]), ['ok', 'Cycle started with ' . $n . ' review(s). Everyone has been told to set their goals.']);
        }, hr_url('performance', ['tab' => 'cycles']));
    } elseif ($a === 'cycle_close') {
        $flash = hr_action(static function () { hr_cycle_close((int) ($_POST['cycle'] ?? 0), !empty($_POST['close'])); return 'Saved.'; }, hr_url('performance', ['tab' => 'cycles']));
    } elseif ($a === 'goals') {
        $flash = hr_action(static function () use ($rid) { hr_review_goals_save($rid, $_POST); return 'Goals saved.'; }, $back);
    } elseif ($a === 'assess') {
        $flash = hr_action(static function () use ($rid) {
            return hr_review_assess($rid, (string) ($_POST['who'] ?? ''), $_POST, !empty($_POST['submit']));
        }, $back);
    } elseif ($a === 'final') {
        $flash = hr_action(static function () use ($rid) { return hr_review_finalise($rid, $_POST); }, $back);
    }
}

/* ------------------------------------------------------------ one review */

$rid = (int) ($_GET['id'] ?? 0);
if ($rid) {
    $r = hr_review($rid);
    if (!$r) {
        fabo_deny('That review is not available to you.', 404);
    }
    $role = hr_review_role($r);
    $goals = hr_review_goals($rid);
    $open = $r['stage'] !== 'done' && $r['cycle_status'] !== 'closed';
    hr_layout_start($r['full_name'] . ' — ' . $r['cycle'], e($r['designation'] ?? '') . ($r['department'] ? ' · ' . e($r['department']) : '') . ' · reviewer: '
                  . e($r['reviewer'] ?? 'not set') . ' · ' . e(hr_fmt_date($r['period_from'])) . ' – ' . e(hr_fmt_date($r['period_to'])),
                    ['Performance' => hr_url('performance'), $r['full_name'] => '']);
    echo hr_flash_html($flash);
    echo hr_steps_html(['goals' => 'Goals agreed', 'self' => 'Self assessment', 'manager' => 'Manager review', 'done' => 'Completed'],
                       $r['stage'] === 'self' ? ($goals ? 'self' : 'goals') : $r['stage']);
    if ($r['stage'] === 'done') {
        echo '<div class="tiles">' . hr_tile('Final rating', e(hr_rating_words($r['final_rating'])), 'green', $r['final_note'] ? e($r['final_note']) : '')
           . hr_tile('Self rating', e(hr_rating_words($r['self_rating']))) . hr_tile('Manager rating', e(hr_rating_words($r['manager_rating']))) . '</div>';
    }

    // Goals: editable by the employee while they assess, by the manager / HR until completed.
    $goalEdit = $open && $role && ($role !== 'self' || $r['stage'] === 'self');
    echo '<div class="card"><h2>Goals</h2>';
    if ($goalEdit) {
        echo '<p class="hr-hint mt0">What this person should achieve in the period. Weights show how much each goal counts and must add up to 100% — or leave them all at 0 to count goals equally.</p>'
           . hr_form_open(hr_url('performance', ['id' => $rid]), 'class="hr-form"') . '<input type="hidden" name="action" value="goals"><input type="hidden" name="id" value="' . $rid . '">'
           . '<div class="scrollx"><table class="data hr-table hr-edit"><thead><tr><th>Goal</th><th>Measure of success</th><th>Weight %</th></tr></thead><tbody>';
        foreach (array_merge($goals, array_fill(0, max(2, 5 - count($goals)), ['id' => 0, 'title' => '', 'target' => '', 'weight' => ''])) as $g) {
            echo '<tr><td><input type="hidden" name="goal_id[]" value="' . (int) $g['id'] . '"><input type="text" name="goal_title[]" value="' . e($g['title']) . '" maxlength="190" aria-label="Goal"></td>'
               . '<td><input type="text" name="goal_target[]" value="' . e($g['target'] ?? '') . '" maxlength="255" aria-label="Measure"></td>'
               . '<td><input type="number" name="goal_weight[]" value="' . e($g['weight'] === '' ? '' : hr_fmt_days($g['weight'])) . '" min="0" max="100" step="1" style="width:80px" aria-label="Weight"></td></tr>';
        }
        echo '</tbody></table></div><button class="btn btn-primary">Save goals</button></form>';
    } elseif (!$goals) {
        echo hr_empty('No goals yet.');
    }
    echo '</div>';

    if ($goals) {
        $who = $role === 'self' && $r['stage'] === 'self' ? 'self'
             : (in_array($role, ['manager', 'hr'], true) && $open && ($r['stage'] === 'manager' || hr_setting('perf_self_first') !== '1' || $role === 'hr') ? 'manager' : null);
        $showMgr = $r['stage'] !== 'self' || $role !== 'self';
        echo '<div class="card"><h2>' . ($who === 'self' ? 'Your self-assessment' : ($who === 'manager' ? 'Manager assessment' : 'Ratings')) . '</h2>';
        if ($who) {
            echo '<p class="hr-hint mt0">Rate each goal from 1 to 5: ' . e(implode(' · ', array_map(static fn($k, $v) => $k . ' ' . $v, array_keys(hr_rating_labels()), hr_rating_labels()))) . '.</p>'
               . hr_form_open(hr_url('performance', ['id' => $rid]), 'class="hr-form"') . '<input type="hidden" name="action" value="assess"><input type="hidden" name="id" value="' . $rid . '">'
               . '<input type="hidden" name="who" value="' . $who . '">';
        }
        echo '<div class="scrollx"><table class="data hr-table stack"><thead><tr><th>Goal</th><th class="num">Weight</th><th>Self</th>' . ($showMgr ? '<th>Manager</th>' : '') . '</tr></thead><tbody>';
        $ratingOpts = hr_rating_labels();
        $rateOpts = [];
        foreach ($ratingOpts as $k => $v) {
            $rateOpts[$k] = $k . ' — ' . $v;
        }
        foreach ($goals as $g) {
            $selfCell = $who === 'self'
                ? '<select name="rating[' . (int) $g['id'] . ']" aria-label="Rating">' . hr_options($rateOpts, $g['self_rating'], 'Rate…') . '</select>'
                  . '<input type="text" name="note[' . (int) $g['id'] . ']" value="' . e($g['self_note'] ?? '') . '" maxlength="500" placeholder="What you achieved" aria-label="Comment">'
                : e($g['self_rating'] ? $g['self_rating'] . ' — ' . $ratingOpts[(int) $g['self_rating']] : '—') . ($g['self_note'] ? '<div class="muted small">' . e($g['self_note']) . '</div>' : '');
            $mgrCell = $who === 'manager'
                ? '<select name="rating[' . (int) $g['id'] . ']" aria-label="Rating">' . hr_options($rateOpts, $g['manager_rating'], 'Rate…') . '</select>'
                  . '<input type="text" name="note[' . (int) $g['id'] . ']" value="' . e($g['manager_note'] ?? '') . '" maxlength="500" placeholder="Your comment" aria-label="Comment">'
                : e($g['manager_rating'] ? $g['manager_rating'] . ' — ' . $ratingOpts[(int) $g['manager_rating']] : '—') . ($g['manager_note'] ? '<div class="muted small">' . e($g['manager_note']) . '</div>' : '');
            echo '<tr><td data-l="Goal"><strong>' . e($g['title']) . '</strong>' . ($g['target'] ? '<div class="muted small">' . e($g['target']) . '</div>' : '') . '</td>'
               . '<td class="num" data-l="Weight">' . ((float) $g['weight'] > 0 ? hr_fmt_days($g['weight']) . '%' : '—') . '</td>'
               . '<td data-l="Self" class="hr-rate">' . $selfCell . '</td>' . ($showMgr ? '<td data-l="Manager" class="hr-rate">' . $mgrCell . '</td>' : '') . '</tr>';
        }
        echo '</tbody></table></div>';
        if ($who) {
            $cur = $who === 'self' ? $r['self_comment'] : $r['manager_comment'];
            echo hr_field($who === 'self' ? 'Overall, how did the period go?' : 'Overall comments for the employee',
                          '<textarea name="comment" rows="4" maxlength="5000">' . e($cur ?? '') . '</textarea>', false, '', 'full')
               . '<div class="hr-formfoot"><button class="btn">Save draft</button><button class="btn btn-primary" name="submit" value="1" data-confirm="'
               . ($who === 'self' ? 'Submit your self-assessment? Your manager reviews it next and you can no longer change it.' : 'Submit the review? This sets the final rating and tells the employee.')
               . '">' . ($who === 'self' ? 'Submit self-assessment' : 'Submit review') . '</button></div></form>';
        } else {
            echo '<dl class="hr-kv" style="margin-top:12px"><dt>Employee\'s comments</dt><dd>' . nl2br(e($r['self_comment'] ?? '—')) . '</dd>'
               . ($showMgr ? '<dt>Manager\'s comments</dt><dd>' . nl2br(e($r['manager_comment'] ?? '—')) . '</dd>' : '') . '</dl>';
        }
        echo '</div>';
    }

    if ($role === 'hr') {
        echo '<div class="card"><h2>HR</h2>' . hr_form_open(hr_url('performance', ['id' => $rid]), 'class="hr-form"') . '<input type="hidden" name="action" value="final"><input type="hidden" name="id" value="' . $rid . '">'
           . '<div class="formgrid">' . hr_field('Final rating (1–5)', hr_input('final_rating', $r['final_rating'] ?? '', 'number', ['min' => 1, 'max' => 5, 'step' => '0.1']), false,
                                                 'Normally the weighted manager rating. Change it only after calibration, with a reason.')
           . hr_field('Reason', hr_input('final_note', $r['final_note'] ?? '', 'text', ['maxlength' => 500]), false, '', 'full')
           . '</div><div class="hr-formfoot"><button class="btn btn-primary">Set final rating</button>'
           . ($r['stage'] === 'done' ? '<button class="btn" name="reopen" value="1" data-confirm="Reopen this review for the manager?">Reopen review</button>' : '') . '</div></form></div>';
    }
    hr_layout_end();
    return;
}

/* -------------------------------------------------------------- the desk */

$tabs = [];
if ($self || hr_reviews_waiting_me()) { $tabs['mine'] = 'My reviews'; }
if (hr_scope('performance', 'view') !== 'self' || hr_is_admin()) { $tabs['all'] = hr_scope('performance', 'view') === 'team' ? 'My team' : 'All reviews'; }
if ($canCfg) { $tabs['cycles'] = 'Review cycles'; }
$tab = hr_tab($tabs, isset($tabs['all']) && !$self ? 'all' : 'mine');
hr_layout_start('Performance Reviews', 'Goals, self-assessments and manager reviews, one cycle at a time.', ['Performance' => '']);
echo hr_flash_html($flash);
hr_tabs('performance', $tabs, $tab);

/** A table of reviews. */
function hr_render_reviews(array $rows, bool $showEmp = true): void
{
    if (!$rows) {
        echo hr_empty('No reviews.');
        return;
    }
    echo '<div class="scrollx"><table class="data hr-table stack"><thead><tr>' . ($showEmp ? '<th>Employee</th><th>Reviewer</th>' : '') . '<th>Cycle</th><th class="num">Goals</th><th>Stage</th><th>Final rating</th><th></th></tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr>' . ($showEmp ? '<td data-l="Employee">' . hr_person_cell($r) . '</td><td data-l="Reviewer">' . e($r['reviewer'] ?? '—') . '</td>' : '')
           . '<td data-l="Cycle">' . e($r['cycle']) . '</td><td class="num" data-l="Goals">' . (int) $r['goals'] . '</td>'
           . '<td data-l="Stage">' . hr_badge(strtolower(hr_review_stages()[$r['stage']]), hr_review_stages()[$r['stage']]) . '</td>'
           . '<td data-l="Final">' . e(hr_rating_words($r['final_rating'])) . '</td><td><a class="btn btn-sm" href="' . e(hr_url('performance', ['id' => $r['id']])) . '">Open</a></td></tr>';
    }
    echo '</tbody></table></div>';
}

if ($tab === 'mine') {
    echo hr_guide('How reviews work', [
        'HR opens a review cycle. You and your manager agree a few goals, each with a weight.',
        'You rate yourself on each goal and submit your self-assessment.',
        'Your manager rates each goal. The final rating is the weighted average of the manager\'s ratings, unless HR changes it after calibration.',
    ]);
    $wait = hr_reviews_waiting_me();
    if ($wait) {
        echo '<div class="card hr-attn"><h2>Waiting on you</h2><ul>';
        foreach ($wait as $w) {
            echo '<li><a href="' . e(hr_url('performance', ['id' => $w['id']])) . '"><span class="n amber">!</span><span>'
               . e((int) $w['employee_id'] === (int) ($self['id'] ?? 0) ? 'Your self-assessment — ' . $w['cycle'] : 'Review ' . $w['full_name'] . ' — ' . $w['cycle'])
               . '</span><span class="go" aria-hidden="true">&rsaquo;</span></a></li>';
        }
        echo '</ul></div>';
    }
    if ($self) {
        $mine = fabo_all('SELECT r.*, c.name AS cycle, m.full_name AS reviewer, (SELECT COUNT(*) FROM hr_review_goals g WHERE g.review_id = r.id) AS goals
                          FROM hr_reviews r JOIN hr_review_cycles c ON c.id = r.cycle_id LEFT JOIN hr_employees m ON m.id = r.reviewer_id
                          WHERE r.employee_id = ? AND r.org_id = ? ORDER BY c.period_to DESC', 'ii', [(int) $self['id'], hr_org()]);
        echo '<div class="card"><h2>My reviews</h2>';
        hr_render_reviews($mine, false);
        echo '</div>';
    }
}

if ($tab === 'all') {
    $cycles = array_column(hr_cycles(), 'name', 'id');
    $f = ['cycle' => (int) ($_GET['cycle'] ?? array_key_first($cycles)), 'department' => (int) ($_GET['department'] ?? 0), 'stage' => (string) ($_GET['stage'] ?? '')];
    $rows = hr_reviews_list($f);
    echo '<form method="get" class="card hr-filters"><input type="hidden" name="p" value="performance"><input type="hidden" name="tab" value="all">'
       . '<div class="grp"><label for="pc">Cycle</label><select id="pc" name="cycle" data-autosubmit>' . hr_options($cycles, $f['cycle'], 'All cycles') . '</select></div>'
       . '<div class="grp"><label for="pd">Department</label><select id="pd" name="department" data-autosubmit>' . hr_options(hr_departments_map(false), $f['department'], 'All') . '</select></div>'
       . '<div class="grp"><label for="ps">Stage</label><select id="ps" name="stage" data-autosubmit>' . hr_options(hr_review_stages(), $f['stage'], 'All') . '</select></div>'
       . '<noscript><button class="btn btn-sm">Apply</button></noscript></form>';
    if ($rows) {
        $dist = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        $done = 0;
        foreach ($rows as $r) {
            if ($r['final_rating'] !== null) {
                $dist[max(1, min(5, (int) round((float) $r['final_rating'])))]++;
                $done++;
            }
        }
        echo '<div class="tiles">' . hr_tile('Reviews', (string) count($rows)) . hr_tile('Completed', $done . ' / ' . count($rows), $done === count($rows) ? 'green' : 'amber')
           . hr_tile('Waiting for self-assessment', (string) count(array_filter($rows, static fn($r) => $r['stage'] === 'self')))
           . hr_tile('Waiting for manager', (string) count(array_filter($rows, static fn($r) => $r['stage'] === 'manager'))) . '</div>';
        if ($done) {
            echo '<div class="card"><h2>Rating distribution</h2><div class="hr-dist">';
            foreach ($dist as $k => $n) {
                $pct = (int) round($n * 100 / $done);
                echo '<div><span class="lbl">' . $k . ' · ' . e(hr_rating_labels()[$k]) . '</span><span class="bar"><i style="width:' . $pct . '%"></i></span><span class="v">' . $n . ' (' . $pct . '%)</span></div>';
            }
            echo '</div></div>';
        }
    }
    echo '<div class="card">';
    hr_render_reviews($rows);
    echo '</div>';
}

if ($tab === 'cycles') {
    $cycles = hr_cycles();
    echo '<div class="card"><h2>Review cycles</h2>';
    if (!$cycles) {
        echo hr_empty('No cycles yet.', ' Start the first one below — for example “Annual review ' . date('Y') . '”.');
    } else {
        echo '<table class="data hr-table stack"><thead><tr><th>Cycle</th><th>Period</th><th>Self-assessment due</th><th>Manager review due</th><th>Progress</th><th>Status</th><th></th></tr></thead><tbody>';
        foreach ($cycles as $c) {
            echo '<tr><td data-l="Cycle"><strong>' . e($c['name']) . '</strong></td><td data-l="Period" class="nowrap">' . e(hr_fmt_date($c['period_from'])) . ' – ' . e(hr_fmt_date($c['period_to'])) . '</td>'
               . '<td data-l="Self due">' . e(hr_fmt_date($c['self_due'])) . '</td><td data-l="Manager due">' . e(hr_fmt_date($c['manager_due'])) . '</td>'
               . '<td data-l="Progress"><div class="hr-mini-bar"><i style="width:' . ($c['reviews'] ? (int) round($c['done'] * 100 / $c['reviews']) : 0) . '%"></i></div> <span class="muted small">' . (int) $c['done'] . ' / ' . (int) $c['reviews'] . '</span></td>'
               . '<td data-l="Status">' . hr_badge($c['status'] === 'open' ? 'open' : 'closed', ucfirst($c['status'])) . '</td><td class="hr-acts">'
               . '<a class="btn btn-sm" href="' . e(hr_url('performance', ['tab' => 'all', 'cycle' => $c['id']])) . '">Reviews</a>'
               . hr_button_form($c['status'] === 'open' ? 'Close' : 'Reopen', ['action' => 'cycle_close', 'cycle' => $c['id'], 'close' => $c['status'] === 'open' ? 1 : 0], 'btn btn-sm',
                                $c['status'] === 'open' ? 'Close this cycle? Reviews can no longer change.' : '') . '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div><div class="card"><h2>Start a review cycle</h2><p class="hr-hint mt0">Creates a review for every current employee (or one department), with their reporting manager as reviewer, and tells each of them.</p>'
       . hr_form_open(hr_url('performance', ['tab' => 'cycles']), 'class="hr-form"') . '<input type="hidden" name="action" value="cycle_create"><div class="formgrid">'
       . hr_field('Name', hr_input('name', 'Annual review ' . date('Y'), 'text', ['required' => true, 'maxlength' => 120]), true)
       . hr_field('Period from', hr_input('period_from', date('Y') . '-01-01', 'date', ['required' => true]), true)
       . hr_field('Period to', hr_input('period_to', date('Y') . '-12-31', 'date', ['required' => true]), true)
       . hr_field('Department', hr_select('department_id', hr_departments_map(), '', 'Everyone'))
       . hr_field('Self-assessment due', hr_input('self_due', '', 'date'))
       . hr_field('Manager review due', hr_input('manager_due', '', 'date'))
       . '</div><button class="btn btn-primary" data-confirm="Start the cycle? Every employee in it is told.">Start cycle</button></form></div>';
    hr_part_settings('performance', hr_url('performance', ['tab' => 'cycles']), 'Review rules');
}

hr_layout_end();

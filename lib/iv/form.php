<?php
/**
 * The student's idea form (idea-form.php?f=<token>): no sign-in. The token
 * names one department's form for one round; the student is found on the
 * roster by PIN / roll number (then email, then phone) or, when the round
 * allows it, added to that department. One idea per student per round: a
 * second send updates it until the HoD starts reviewing.
 */

require_once __DIR__ . '/../ui.php';
require_once __DIR__ . '/../throttle.php';
require_once __DIR__ . '/core.php';

function iv_form_main(): void
{
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store, private');
    $form = iv_form_by_token((string) ($_GET['f'] ?? ''));
    $org = $form ? (int) $form['org_id'] : 0;
    $round = $form ? iv_round($org, (int) $form['round_id']) : null;
    $dept = $form ? iv_department($org, (int) $form['department_id']) : null;
    if (!$form || !$round || !$dept || $round['status'] === 'draft') {
        fabo_simple_page('Idea form', 'This link is not in use. Ask your Head of Department for the right one.');
        return;
    }
    fabo_session();
    $err = '';
    $in = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        fabo_csrf_check();
        $in = $_POST;
        $ip = fabo_client_ip();
        if (trim((string) ($_POST['website'] ?? '')) !== '') {          // a field people never see: a bot filled it
            $err = 'Something went wrong. Please try again.';
        } elseif (fabo_throttle_blocked('iv-form-ip', $ip, 40, 3600)) {
            $err = fabo_throttle_words();
        } else {
            fabo_throttle_hit('iv-form-ip', $ip);
            try {
                [$id, $how] = iv_idea_submit($form, ['name' => $_POST['name'] ?? '', 'roll_no' => $_POST['roll_no'] ?? '', 'email' => $_POST['email'] ?? '',
                                                     'phone' => $_POST['phone'] ?? '', 'title' => $_POST['title'] ?? '', 'q' => (array) ($_POST['q'] ?? [])]);
                $_SESSION['iv_sent'] = [(int) $form['id'], $how, iv_clean($_POST['title'] ?? '', 190)];
                header('Location: idea-form.php?f=' . rawurlencode($form['token']) . '&done=1', true, 303);
                exit;
            } catch (InvalidArgumentException $e) {
                $err = $e->getMessage();
            } catch (Throwable $e) {
                error_log('FABO iv form: ' . $e->getMessage());
                $err = 'Your idea could not be saved. Please try again.';
            }
        }
    }
    $orgName = iv_org_name($org);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">'
       . '<title>' . e($form['title']) . '</title><link rel="stylesheet" href="' . e(fabo_asset('assets/app.css')) . '"><link rel="stylesheet" href="' . e(fabo_asset('assets/ideas.css')) . '"></head><body class="hp">'
       . '<main class="sf iv" id="main"><header class="sf-head"><span class="iv-eyebrow">' . e($orgName) . ' · ' . e($round['name']) . '</span><h1>' . e($form['title']) . '</h1>'
       . '<p class="muted">' . e($dept['name']) . ', ' . e($dept['college_name']) . '</p></header>';

    $sent = $_SESSION['iv_sent'] ?? null;
    if (!empty($_GET['done']) && is_array($sent) && (int) $sent[0] === (int) $form['id']) {
        unset($_SESSION['iv_sent']);
        echo '<section class="iv-card sf-done"><div class="sf-tick" aria-hidden="true">✓</div><h2>' . ($sent[1] === 'updated' ? 'Your idea was updated' : 'Your idea was sent') . '</h2>'
           . '<p><strong>' . e($sent[2]) . '</strong></p><p class="muted">Your Head of Department will review it. You can send it again with changes until ' . e(iv_when(iv_closes_for($round, (int) $dept['id']))) . ', as long as the review has not started.</p></section></main></body></html>';
        return;
    }
    $why = !(int) $form['is_open'] ? 'Your Head of Department has paused this form for now.' : iv_window_problem($round, (int) $dept['id']);
    if ($why) {
        echo '<section class="iv-card"><h2>Not accepting ideas</h2><p>' . e($why) . '</p></section></main></body></html>';
        return;
    }
    if (trim((string) $form['intro']) !== '') {
        echo '<section class="iv-card sf-intro"><p>' . nl2br(e($form['intro'])) . '</p><p class="muted sm">Open until ' . e(iv_when(iv_closes_for($round, (int) $dept['id']))) . '.</p></section>';
    }
    $val = static fn(string $k) => e((string) ($in[$k] ?? ''));
    echo ($err !== '' ? '<div class="iv-flash err" role="alert">' . e($err) . '</div>' : '')
       . '<form method="post" class="iv-form iv-card">' . fabo_csrf_field()
       . '<div class="sf-hp" aria-hidden="true"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></div>'
       . '<h2>About you</h2><div class="iv-fields">'
       . '<label class="field"><span>Full name *</span><input name="name" required maxlength="160" autocomplete="name" value="' . $val('name') . '"></label>'
       . '<label class="field"><span>PIN / roll number *</span><input name="roll_no" required maxlength="40" placeholder="24045-CM-042" value="' . $val('roll_no') . '"></label>'
       . '<label class="field"><span>Email *</span><input type="email" name="email" required maxlength="160" autocomplete="email" value="' . $val('email') . '"></label>'
       . '<label class="field"><span>Phone</span><input type="tel" name="phone" maxlength="20" autocomplete="tel" value="' . $val('phone') . '"></label></div>'
       . '<h2>Your idea</h2><label class="field"><span>Idea title *</span><input name="title" required maxlength="190" value="' . $val('title') . '"></label>';
    foreach (iv_form_questions($form) as $q) {
        $v = e((string) ($in['q'][$q['uid']] ?? ''));
        $name = 'q[' . e($q['uid']) . ']';
        $req = !empty($q['required']) ? ' required' : '';
        echo '<label class="field"><span>' . e($q['label']) . (!empty($q['required']) ? ' *' : '') . '</span>' . (($q['help'] ?? '') !== '' ? '<small>' . e($q['help']) . '</small>' : '');
        if ($q['type'] === 'long') {
            echo '<textarea name="' . $name . '" rows="5" maxlength="5000"' . $req . '>' . $v . '</textarea>';
        } else {
            echo '<input type="' . ($q['type'] === 'number' ? 'number' : ($q['type'] === 'url' ? 'url' : 'text')) . '" name="' . $name . '" maxlength="5000"' . $req . ' value="' . $v . '">';
        }
        echo '</label>';
    }
    echo '<div class="iv-actions"><button class="btn btn-primary">Send my idea</button></div><p class="muted sm">One idea per student this round. Your details go to your Head of Department and ' . e($orgName) . ' only.</p></form></main></body></html>';
}

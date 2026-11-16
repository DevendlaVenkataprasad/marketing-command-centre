<?php
/**
 * Incubation Management — an application entered by staff (a walk-in, a phone call,
 * a referral received by email). The purpose chosen loads the published form
 * for that purpose, and only that form's fields.
 *
 * ?dept=<id>: entered for a department, from its desk. A department member
 * may file one this way without the Incubation Management itself; it goes in
 * the main pipeline as usual and is open on the department's desk at once.
 */

require_once __DIR__ . '/../depts.php';
// Departments file their own; mentors and a jury are given theirs by the owner only.
$myDepts = array_filter(inc_my_depts(), static fn($n, $t) => inc_dept_may_intake((int) $t), ARRAY_FILTER_USE_BOTH);
$deptId = (int) ($_GET['dept'] ?? 0);
if (!isset($myDepts[$deptId])) {
    $deptId = 0;
}
if (!inc_can('edit') && !$deptId) {
    if (count($myDepts) !== 1) {
        inc_redirect(inc_url('desk'), ['err', 'Add an application from your department\'s desk.']);
    }
    $deptId = (int) array_key_first($myDepts);
}

$forms = array_filter(inc_forms_list(), static fn($f) => $f['published_no'] !== null);
$purpose = (string) ($_GET['purpose'] ?? '');
$formId = (int) ($_GET['form'] ?? 0);
if (!$formId && $purpose !== '') {
    $match = array_values(array_filter($forms, static fn($f) => $f['purpose'] === $purpose));
    if (count($match) === 1) {
        $formId = (int) $match[0]['id'];
    }
}
if (!$formId && $purpose === '' && count($forms) === 1) {
    $formId = (int) array_values($forms)[0]['id'];       // one form only: straight to its questions
}
$form = $formId ? inc_form($formId) : null;
$version = $form ? inc_form_published($formId) : null;
$errors = [];
$flash = null;
$values = [];

/* Inside the pop-up (?embed=1) every link keeps it, and a save takes the whole page (not the pop-up) to where the application now is. */
$embed = !empty($GLOBALS['inc_embed']);
$here = static fn(array $q = []): string => inc_url('new', $q + ($deptId ? ['dept' => $deptId] : []) + ($embed ? ['embed' => 1] : []));
$done = static function (string $url, array $flash) use ($embed): void {
    if (!$embed) {
        inc_redirect($url, $flash);
    }
    $url = (string) preg_replace('/&embed=1\b/', '', $url);    // inc_url() adds it inside the pop-up; the page underneath is a full one
    fabo_session();
    $_SESSION['inc_flash'] = $flash;
    echo '<!doctype html><meta charset="utf-8"><title>Saved</title><p style="font:15px system-ui;padding:24px">Saved. <a href="' . e($url) . '" target="_top">Continue</a></p>'
       . '<script>window.top.location.href = ' . json_encode($url) . ';</script>';
    exit;
};

if ($form && $version && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $fields = inc_form_fields((int) $version['id'], true);
    [$raw, $files] = inc_posted_answers($fields);
    $values = $raw;
    try {
        $r = inc_app_submit($form, $version, $raw, $files, ['channel' => 'admin', 'source_id' => $_POST['source_id'] ?? null, 'applicant_type_id' => $_POST['applicant_type_id'] ?? null,
                                                             'ticket' => (string) ($_POST['ticket'] ?? ''), 'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                                                             'dept_team_id' => $deptId]);
        if ($deptId) {
            inc_dept_take_new($deptId, (int) $r['id']);
            $done(inc_url('desk', ['dept' => $deptId]),
                  ['ok', ($r['repeat'] ? 'This application was already saved as ' : 'Application saved as ') . $r['ref'] . ' and is open on ' . $myDepts[$deptId]
                         . '\'s desk. It is in the incubation pipeline too, and the incubation team has been told.'
                         . ($r['duplicate_of'] ? ' It looks like a possible duplicate; the incubation team will review it.' : '')]);
        }
        $done(inc_url('application', ['id' => $r['id']]), ['ok', ($r['repeat'] ? 'This application was already saved as ' : 'Application saved as ') . $r['ref'] . '.'
                     . ($r['duplicate_of'] ? ' It looks like a possible duplicate — review it on the application.' : '')]);
    } catch (InvalidArgumentException $e) {
        [$errors, $msg] = inc_submit_errors($e);
        $flash = ['err', $msg];
    } catch (Throwable $e) {
        error_log('FABO incubation new: ' . $e->getMessage());
        $flash = ['err', fabo_user_error($e)];
    }
}

if ($deptId) {
    inc_layout_start('New application', 'Enter an application on someone\'s behalf for ' . e($myDepts[$deptId]) . '. It goes into the incubation pipeline and onto your desk.',
                     ['Department desk' => inc_url('desk', ['dept' => $deptId]), 'New' => null], '', $flash);
} else {
    inc_layout_start('New application', 'Enter an application on someone\'s behalf. It goes straight into Incubation Management.', ['Applications' => inc_url('applications'), 'New' => null], '', $flash);
}

if (!$forms) {
    echo inc_empty('No published forms yet.', inc_can('configure') ? 'Create and publish a form under <a href="' . e(inc_url('settings', ['tab' => 'forms'])) . '">Settings → Forms</a>.'
                                                                  : 'The incubation team has not published a form yet.');
    inc_layout_end();
    return;
}

$chosen = $form && $version;
echo '<ol class="ap-steps"><li class="' . ($chosen ? 'done' : 'on') . '"><b>' . ($chosen ? '✓' : '1') . '</b> Choose the form</li>'
   . '<li class="' . ($chosen ? 'on' : '') . '"><b>2</b> Fill in the details</li><li><b>3</b> Save</li></ol>';

if (!$chosen) {
    if ($formId) {
        echo '<div class="inc-warn">That form is not published. Choose another one.</div>';
    }
    // The published forms as tiles, grouped by what they are for.
    $groups = [];
    foreach ($forms as $f) {
        if ($purpose === '' || $f['purpose'] === $purpose) {
            $groups[$f['purpose']][] = $f;
        }
    }
    $labels = inc_purposes();
    foreach ($groups as $p => $list) {
        echo '<section class="ap-group"><h3>' . e($labels[$p] ?? ucfirst(str_replace('_', ' ', (string) $p))) . '</h3><div class="ap-tiles">';
        foreach ($list as $f) {
            echo '<a class="ap-tile" href="' . e($here(['form' => (int) $f['id']])) . '"><strong>' . e($f['name']) . '</strong>'
               . (($f['event_name'] ?? '') !== '' ? '<span>' . e($f['event_name']) . '</span>' : '')
               . ($f['description'] ? '<span>' . e(mb_strimwidth((string) $f['description'], 0, 110, '…')) . '</span>' : '') . '</a>';
        }
        echo '</div></section>';
    }
    if ($purpose !== '') {
        echo '<p><a href="' . e($here()) . '">Show every form</a></p>';
    }
    inc_layout_end();
    return;
}

$fields = inc_form_fields((int) $version['id'], true);
echo '<div class="ap-chosen"><div><strong>' . e($form['name']) . '</strong><span>' . e(inc_purposes()[$form['purpose']] ?? '')
   . (($form['event_name'] ?? '') !== '' ? ' · ' . e($form['event_name']) : '') . ' · version ' . (int) $version['version_no'] . '</span></div>'
   . '<a class="btn btn-sm" href="' . e($here()) . '">Change form</a></div>'
   . '<section class="card ap-form">' . ($form['description'] ? '<p class="muted">' . nl2br(e($form['description'])) . '</p>' : '')
   . '<form method="post" enctype="multipart/form-data" class="inc-applyform" novalidate>' . fabo_csrf_field()
   . '<input type="hidden" name="ticket" value="' . e((string) ($_POST['ticket'] ?? bin2hex(random_bytes(16)))) . '">'
   . '<div class="inc-row">' . inc_field('Applicant type', inc_select('applicant_type_id', inc_masters('applicant_type'), $_POST['applicant_type_id'] ?? ($form['default_type_id'] ?? ''), 'Not set'))
   . inc_field('Source', inc_select('source_id', inc_masters('source'), $_POST['source_id'] ?? ($form['default_source_id'] ?? ''), 'Not recorded')) . '</div>'
   . inc_render_fields($fields, $values, $errors)
   . '<div class="inc-formfoot"><a class="btn" href="' . e($here()) . '">Back</a><button class="btn btn-primary">Save application</button></div></form></section>';
inc_layout_end();

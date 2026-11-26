<?php
/**
 * Campus portal — data protection and security pages (Phase 5). Loaded by lib/edu/portal.php.
 *
 *   ?v=code              the emailed sign-in code (two-step sign-in)
 *   (gate)               accept the privacy notice; a student under 18 names a guardian
 *   ?guardian=<token>    the guardian reads the notice and agrees or refuses (no sign-in)
 *   ?v=privacy           the privacy notice, for anyone
 *   ?v=mydata            "Your data": download, ask for a correction or erasure (signed in)
 */

require_once __DIR__ . '/privacy.php';
require_once __DIR__ . '/lms.php';      // edu_render_text() for the notice

/** The notice, as HTML, with the grievance contact under it. */
function edu_notice_html(int $org): string
{
    $g = trim(edu_get($org, 'grievance_contact', ''));
    return '<div class="cp-lesson-body cp-notice">' . edu_render_text(edu_notice_text($org)) . '</div>'
         . '<p class="cp-griev"><strong>Grievance officer:</strong> ' . ($g !== '' ? nl2br(e($g)) : 'ask your institution, or write to ' . e(edu_org_name($org)) . '.') . '</p>';
}

function edu_portal_notice(int $org): void
{
    edu_portal_gate($org, 'Privacy notice', '<h1>Privacy notice</h1>' . edu_notice_html($org)
        . '<p class="cp-alt"><a href="' . e(edu_purl()) . '">Back to the portal</a></p>');
}

/* ======================================================== two-step code */

function edu_portal_code(int $org, array $me, string $a): void
{
    $err = '';
    if ($a === 'code') {
        if (edu_mfa_check($org, $me, (string) ($_POST['code'] ?? ''))) {
            $_SESSION['edu']['mfa'] = 'ok';
            session_regenerate_id(true);
            header('Location: ' . edu_purl(), true, 303);
            exit;
        }
        $err = 'That code is not right, or has expired. Check the latest email, or send a new code.';
    } elseif ($a === 'resend') {
        [, $words] = edu_mfa_send($org, $me);
        edu_pflash('info', $words);
        header('Location: ' . edu_purl(['v' => 'code']), true, 303);
        exit;
    }
    $flash = $_SESSION['edu_pflash'] ?? null;
    unset($_SESSION['edu_pflash']);
    edu_portal_gate($org, 'Sign-in code', '<h1>Check your email</h1><p class="muted">For your security, enter the 6-digit code we sent to <strong>' . e(edu_mask_email((string) $me['email'])) . '</strong>. It works for ' . EDU_CODE_MINUTES . ' minutes.</p>'
        . ($flash ? '<div class="edu-flash ' . e($flash[0]) . '" role="status">' . e($flash[1]) . '</div>' : '')
        . ($err !== '' ? '<div class="edu-flash err" role="alert">' . e($err) . '</div>' : '')
        . '<form method="post" class="cp-form">' . fabo_csrf_field() . '<input type="hidden" name="a" value="code">'
        . '<label class="field"><span>Code</span><input name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code" required autofocus class="cp-code"></label>'
        . '<button class="btn btn-primary cp-wide">Continue</button></form>'
        . '<form method="post" class="cp-alt">' . fabo_csrf_field() . '<input type="hidden" name="a" value="resend"><button class="btn btn-sm">Send a new code</button></form>'
        . '<form method="post" class="cp-alt">' . fabo_csrf_field() . '<input type="hidden" name="a" value="logout"><button class="btn btn-sm">Sign out</button></form>');
}

/* =============================================================== consent */

function edu_portal_consent(int $org, array $me, string $gate, string $a): void
{
    $err = '';
    if ($a === 'consent') {
        try {
            if (empty($_POST['agree'])) {
                throw new InvalidArgumentException('Tick the box to say you have read the notice and agree.');
            }
            $age = (string) ($_POST['age'] ?? '');
            $isStudent = (bool) fabo_one('SELECT id FROM edu_roles WHERE org_id = ? AND person_id = ? AND role = "student" LIMIT 1', 'ii', [$org, (int) $me['id']]);
            if ($isStudent && !in_array($age, ['adult', 'minor'], true)) {
                throw new InvalidArgumentException('Say whether you are 18 or older.');
            }
            edu_consent_give($org, $me, $age === 'minor', (string) ($_POST['guardian_name'] ?? ''), (string) ($_POST['guardian_email'] ?? ''));
            header('Location: ' . edu_purl(), true, 303);
            exit;
        } catch (InvalidArgumentException $e) {
            $err = $e->getMessage();
        }
    }
    $c = edu_consent_of($org, (int) $me['id']);
    if ($gate === 'guardian' && $a !== 'consent-change') {
        if ($a === 'guardian-resend') {
            try {
                edu_consent_give($org, $me, true, (string) $c['guardian_name'], (string) $c['guardian_email']);
                edu_pflash('ok', 'Sent again to ' . edu_mask_email((string) $c['guardian_email']) . '.');
            } catch (InvalidArgumentException $e) {
                edu_pflash('err', $e->getMessage());
            }
            header('Location: ' . edu_purl(), true, 303);
            exit;
        }
        $flash = $_SESSION['edu_pflash'] ?? null;
        unset($_SESSION['edu_pflash']);
        edu_portal_gate($org, 'Waiting for your guardian', '<h1>Almost there</h1>'
            . ($flash ? '<div class="edu-flash ' . e($flash[0]) . '" role="status">' . e($flash[1]) . '</div>' : '')
            . '<p>Because you are under 18, we asked <strong>' . e((string) $c['guardian_name']) . '</strong> (' . e(edu_mask_email((string) $c['guardian_email'])) . ') to agree. '
            . 'The portal opens as soon as they click the link in that email.</p>'
            . '<form method="post" class="cp-alt">' . fabo_csrf_field() . '<input type="hidden" name="a" value="guardian-resend"><button class="btn">Send the email again</button></form>'
            . '<form method="post" class="cp-alt">' . fabo_csrf_field() . '<input type="hidden" name="a" value="consent-change"><button class="btn btn-sm">Use a different parent or guardian</button></form>'
            . '<form method="post" class="cp-alt">' . fabo_csrf_field() . '<input type="hidden" name="a" value="logout"><button class="btn btn-sm">Sign out</button></form>');
        return;
    }
    $isStudent = (bool) fabo_one('SELECT id FROM edu_roles WHERE org_id = ? AND person_id = ? AND role = "student" LIMIT 1', 'ii', [$org, (int) $me['id']]);
    $changed = $c && $c['notice_version'] !== edu_notice_version($org) && $c['withdrawn_at'] === null;
    $html = '<h1>' . ($changed ? 'Our privacy notice has changed' : 'Before you start') . '</h1><p class="muted">Please read how ' . e(edu_org_name($org)) . ' uses your information.</p>'
          . ($err !== '' ? '<div class="edu-flash err" role="alert">' . e($err) . '</div>' : '')
          . '<div class="cp-noticebox">' . edu_notice_html($org) . '</div>'
          . '<form method="post" class="cp-form">' . fabo_csrf_field() . '<input type="hidden" name="a" value="consent">';
    if ($isStudent) {
        $minor = ($_POST['age'] ?? ($c && (int) $c['minor'] === 1 ? 'minor' : '')) === 'minor';
        $html .= '<fieldset class="cp-age"><legend>Your age</legend><label class="edu-check"><input type="radio" name="age" value="adult" required' . (($_POST['age'] ?? '') === 'adult' ? ' checked' : '') . '> I am 18 or older</label>'
               . '<label class="edu-check"><input type="radio" name="age" value="minor" data-minor' . ($minor ? ' checked' : '') . '> I am under 18</label></fieldset>'
               . '<div class="cp-guardian" data-guardian' . ($minor ? '' : ' hidden') . '><p class="muted">We will email your parent or guardian to agree. The portal opens once they do.</p>'
               . '<label class="field"><span>Parent or guardian\'s name</span><input name="guardian_name" maxlength="160" value="' . e((string) ($_POST['guardian_name'] ?? $c['guardian_name'] ?? '')) . '"></label>'
               . '<label class="field"><span>Their email</span><input type="email" name="guardian_email" maxlength="160" value="' . e((string) ($_POST['guardian_email'] ?? $c['guardian_email'] ?? '')) . '"></label></div>';
    }
    $html .= '<label class="edu-check"><input type="checkbox" name="agree" value="1" required> I have read the notice and agree to my information being used this way</label>'
           . '<button class="btn btn-primary cp-wide">Agree and continue</button></form>'
           . '<form method="post" class="cp-alt">' . fabo_csrf_field() . '<input type="hidden" name="a" value="logout"><button class="btn btn-sm">I do not agree — sign out</button></form>'
           . '<script>document.addEventListener("change",function(e){if(e.target.name==="age"){var g=document.querySelector("[data-guardian]");if(g){g.hidden=e.target.value!=="minor";g.querySelectorAll("input").forEach(function(i){i.required=e.target.value==="minor";});}}});</script>';
    edu_portal_gate($org, 'Privacy notice', $html);
}

function edu_portal_guardian(int $org, string $token, string $a): void
{
    $c = edu_guardian_consent($token);
    if (!$c || (int) $c['org_id'] !== $org) {
        edu_portal_gate($org, 'Link expired', '<h1>This link is no longer valid</h1><p>It may have been used already, or it has expired. If the student still needs your consent, they can send a new email from the portal.</p>');
        return;
    }
    $p = edu_person($org, (int) $c['person_id']);
    if (in_array($a, ['guardian-agree', 'guardian-refuse'], true)) {
        edu_guardian_confirm($token, $a === 'guardian-agree');
        edu_portal_gate($org, 'Thank you', $a === 'guardian-agree'
            ? '<h1>Thank you</h1><p>' . e($p['name'] ?? 'The student') . ' can now use the Campus portal. You can ask for their data to be corrected or erased at any time by writing to the grievance officer named in the notice.</p>'
            : '<h1>Recorded</h1><p>You did not agree, so ' . e($p['name'] ?? 'the student') . ' cannot use the Campus portal. Their institution will be able to see this.</p>');
        return;
    }
    edu_portal_gate($org, 'Consent for a student under 18', '<h1>Consent for ' . e($p['name'] ?? 'a student') . '</h1>'
        . '<p class="muted">' . e((string) $c['guardian_name']) . ', ' . e($p['name'] ?? 'a student') . ' has named you as their parent or guardian. Please read how their information is used.</p>'
        . '<div class="cp-noticebox">' . edu_notice_html($org) . '</div>'
        . '<form method="post" class="cp-form">' . fabo_csrf_field() . '<input type="hidden" name="a" value="guardian-agree"><button class="btn btn-primary cp-wide">I agree, as their parent or guardian</button></form>'
        . '<form method="post" class="cp-alt">' . fabo_csrf_field() . '<input type="hidden" name="a" value="guardian-refuse"><button class="btn btn-sm">I do not agree</button></form>');
}

/* ============================================================ your data */

function edu_portal_privacy_post(int $org, array $me, string $a): void
{
    if ($a !== 'data-request') {
        return;
    }
    try {
        edu_request_make($org, $me, (string) ($_POST['kind'] ?? ''), (string) ($_POST['details'] ?? ''));
        edu_pflash('ok', ($_POST['kind'] ?? '') === 'erase' ? 'Your request to erase your account is with the programme team. We will email you when it is done.'
                                                         : 'Your correction request is with the programme team. We will email you.');
    } catch (InvalidArgumentException $e) {
        edu_pflash('err', $e->getMessage());
    }
    header('Location: ' . edu_purl(['v' => 'profile']) . '#data', true, 303);
    exit;
}

/** "Download my data": everything held, as a JSON file. */
function edu_portal_mydata(int $org, array $me): void
{
    if (fabo_throttle_blocked('edu-export', (string) $me['id'], 10, 3600)) {
        fabo_simple_page('Please wait', fabo_throttle_words());
        exit;
    }
    fabo_throttle_hit('edu-export', (string) $me['id']);
    edu_seclog($org, (int) $me['id'], 'export');
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="my-campus-data-' . date('Y-m-d') . '.json"');
    echo json_encode(edu_person_export($org, (int) $me['id']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** The "Your data" section of the profile page. */
function edu_pv_your_data(int $org, array $me): void
{
    $c = edu_consent_of($org, (int) $me['id']);
    $open = fabo_all('SELECT kind, created_at FROM edu_requests WHERE org_id = ? AND person_id = ? AND status = "open"', 'ii', [$org, (int) $me['id']]);
    echo '<section class="cp-card" id="data"><h2>Your data</h2><dl class="edu-dl">'
       . '<dt>Privacy notice</dt><dd>' . ($c ? 'Agreed ' . e(fabo_short_date($c['accepted_at'])) : '—') . ' · <a href="' . e(edu_purl(['v' => 'privacy'])) . '" target="_blank" rel="noopener">Read it</a></dd>'
       . ($c && (int) $c['minor'] === 1 ? '<dt>Guardian</dt><dd>' . e((string) $c['guardian_name']) . ' · ' . ($c['guardian_at'] ? 'agreed ' . e(fabo_short_date($c['guardian_at'])) : 'not yet') . '</dd>' : '')
       . '</dl><p><a class="btn" href="' . e(edu_purl(['v' => 'mydata', 'dl' => 1])) . '">Download my data (JSON)</a></p>';
    foreach ($open as $r) {
        echo '<p class="edu-flash info">Your request to ' . ($r['kind'] === 'erase' ? 'erase your account' : 'correct your record') . ' (sent ' . e(fabo_short_date($r['created_at'])) . ') is waiting.</p>';
    }
    echo '<details class="cp-rate"><summary class="btn btn-sm">Ask for a correction</summary><form method="post" class="cp-form">' . fabo_csrf_field()
       . '<input type="hidden" name="a" value="data-request"><input type="hidden" name="kind" value="correct"><textarea name="details" rows="3" maxlength="2000" required placeholder="What is wrong, and what should it be?"></textarea>'
       . '<button class="btn btn-primary btn-sm">Send</button></form></details>'
       . '<details class="cp-rate"><summary class="btn btn-sm btn-danger">Withdraw consent and erase my account</summary><form method="post" class="cp-form" data-confirm="Ask for your account and everything held with it to be erased? This cannot be undone once done.">' . fabo_csrf_field()
       . '<input type="hidden" name="a" value="data-request"><input type="hidden" name="kind" value="erase"><p class="muted">The programme team erases your account, courses, rewards, sessions and ideas, and emails you when done. '
       . 'An application already sent to incubation stays with the incubation team.</p><textarea name="details" rows="2" maxlength="2000" placeholder="Anything you want to tell us (optional)"></textarea>'
       . '<button class="btn btn-danger btn-sm">Ask to erase my account</button></form></details></section>';
}

<?php
/**
 * Co-working Space — the public application form and the applicant emails.
 *
 *   The form     coworking-apply.php?k=<the organisation's form key>, a page
 *                the organisation links to or embeds on its website. Each
 *                application lands on the chosen centre's Waiting List as New.
 *   The emails   an acknowledgement when someone applies, and one at every
 *                stage the application moves to (In review, Approved, Offer
 *                sent, Moved in, Dropped). Each organisation writes its own
 *                wording on the Form & emails page and turns any of them off;
 *                they are kept in cw_mail_tpl. Mail goes out under the
 *                organisation's name, with replies to its head.
 *   History      every application keeps what happened to it (cw_events).
 *
 * Settings live in app_settings, per organisation: cw_form_key_<org>,
 * cw_form_on_<org>, cw_form_intro_<org>, cw_notify_<org>.
 */

require_once __DIR__ . '/core.php';
require_once __DIR__ . '/../google.php';

/* ================================================================ settings */

function cw_org_setting(int $org, string $k, string $default = ''): string
{
    return fabo_setting('cw_' . $k . '_' . $org, $default);
}

function cw_org_setting_put(int $org, string $k, ?string $v): void
{
    fabo_setting_put('cw_' . $k . '_' . $org, $v);
}

/** The key in the form's link. Made on first use; a new one retires the old link. */
function cw_form_key(int $org, bool $renew = false): string
{
    $k = cw_org_setting($org, 'form_key');
    if ($k === '' || $renew) {
        $k = bin2hex(random_bytes(12));
        cw_org_setting_put($org, 'form_key', $k);
    }
    return $k;
}

/** Which organisation a form key belongs to, or null. */
function cw_form_org(string $key): ?int
{
    if (!preg_match('/^[a-f0-9]{24}$/', $key)) {
        return null;
    }
    $r = fabo_one("SELECT k FROM app_settings WHERE k LIKE 'cw\\_form\\_key\\_%' AND v = ?", 's', [$key]);
    return $r ? (int) substr((string) $r['k'], strlen('cw_form_key_')) : null;
}

function cw_form_on(int $org): bool
{
    return cw_org_setting($org, 'form_on', '1') === '1';
}

/** The organisation's name, as applicants should see it. */
function cw_org_name(int $org): string
{
    if ($org <= 0) {
        return fabo_app_name();
    }
    $c = fabo_one('SELECT name, organisation FROM clients WHERE id = ?', 'i', [$org]);
    return trim((string) ($c['organisation'] ?? '')) ?: trim((string) ($c['name'] ?? '')) ?: fabo_app_name();
}

/** Who the applicant's mail is from: the organisation's head (its name on the From line, replies to them). */
function cw_org_actor(int $org): ?array
{
    if ($org <= 0) {
        return null;
    }
    $c = fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [$org]);
    if ($c) {
        $c['send_as_name'] = cw_org_name($org);
    }
    return $c ?: null;
}

/** Where "a new application has come in" goes: the addresses set on Form & emails, else the head's own. */
function cw_notify_emails(int $org): array
{
    $list = array_filter(array_map('trim', preg_split('/[,;\s]+/', cw_org_setting($org, 'notify'))),
                         static fn($x) => filter_var($x, FILTER_VALIDATE_EMAIL));
    if (!$list && $org > 0) {
        $e = (string) (fabo_one('SELECT email FROM clients WHERE id = ?', 'i', [$org])['email'] ?? '');
        if (filter_var($e, FILTER_VALIDATE_EMAIL)) {
            $list = [$e];
        }
    }
    return array_values(array_unique($list));
}

/* =============================================================== templates */

/** The stages an applicant hears about, in order: key => what the page calls it. */
function cw_mail_stages(): array
{
    return [
        'received' => 'Application received (acknowledgement)',
        'In review' => 'In review',
        'Approved – waiting for space' => 'Approved – waiting for space',
        'Offer sent' => 'Offer sent',
        'Moved in' => 'Moved in',
        'Dropped' => 'Dropped (not taken forward)',
    ];
}

/** The words each stage starts with, until the organisation writes its own. */
function cw_mail_defaults(): array
{
    return [
        'received' => ['We have received your co-working application ({reference})',
            "Dear {contact},\n\nThank you for applying for co-working space with {organisation} at our {centre} centre. Your application reference is {reference}.\n\nYou asked for {desks} desk(s){cabin}, from {expected_start}. Our team will review your application and write to you about the next step.\n\nIf you have any questions, simply reply to this email."],
        'In review' => ['Your co-working application is under review ({reference})',
            "Dear {contact},\n\nYour application for co-working space at {organisation}, {centre} is now being reviewed by our team.\n\nWe will be in touch soon. If anything has changed, simply reply to this email."],
        'Approved – waiting for space' => ['Your co-working application has been approved ({reference})',
            "Dear {contact},\n\nGood news: your application for co-working space at {organisation}, {centre} has been approved.\n\nWe will let you know as soon as a suitable space is free for {applicant}. {next_step}"],
        'Offer sent' => ['Your co-working space offer ({reference})',
            "Dear {contact},\n\nWe are pleased to offer {applicant} co-working space at {organisation}, {centre}: {desks} desk(s){cabin}, from {expected_start}.\n\n{next_step}\n\nPlease reply to this email to confirm, or if you have any questions."],
        'Moved in' => ['Welcome to {organisation}, {centre}',
            "Dear {contact},\n\nWelcome! {applicant} is now part of the co-working community at {organisation}, {centre}.\n\nWe look forward to supporting you. If you need anything, simply reply to this email."],
        'Dropped' => ['Update on your co-working application ({reference})',
            "Dear {contact},\n\nThank you for your interest in co-working space at {organisation}, {centre}. After careful review, we are unable to take your application forward at this time.\n\nYou are welcome to apply again in future. We wish {applicant} every success."],
    ];
}

/** One stage's email for an organisation: [enabled, subject, body]. */
function cw_mail_tpl(int $org, string $stage): array
{
    cw_install();
    $r = fabo_one('SELECT enabled, subject, body FROM cw_mail_tpl WHERE org_id = ? AND stage = ?', 'is', [$org, $stage]);
    if ($r) {
        return [(int) $r['enabled'] === 1, (string) $r['subject'], (string) $r['body']];
    }
    $d = cw_mail_defaults()[$stage] ?? ['', ''];
    return [$d[0] !== '', $d[0], $d[1]];
}

function cw_mail_tpl_save(int $org, string $stage, bool $on, string $subject, string $body): void
{
    if (!isset(cw_mail_stages()[$stage])) {
        return;
    }
    $subject = mb_substr(trim($subject), 0, 255);
    $body = trim($body);
    if ($subject === '' || $body === '') {
        [$subject, $body] = cw_mail_defaults()[$stage];
    }
    fabo_exec('INSERT INTO cw_mail_tpl (org_id, stage, enabled, subject, body) VALUES (?,?,?,?,?)
               ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), subject = VALUES(subject), body = VALUES(body)',
              'isiss', [$org, $stage, $on ? 1 : 0, $subject, mb_substr($body, 0, 8000)]);
}

/** What {placeholders} stand for in an application's emails. */
function cw_mail_vars(array $w, array $centre, int $org): array
{
    $d = static fn($v) => $v ? date('d M Y', strtotime((string) $v)) : 'a date to be agreed';
    return [
        '{applicant}' => (string) $w['applicant'],
        '{contact}' => trim((string) ($w['contact'] ?? '')) ?: (string) $w['applicant'],
        '{centre}' => (string) $centre['name'],
        '{organisation}' => cw_org_name($org),
        '{reference}' => (string) ($w['ref'] ?: ('#' . $w['id'])),
        '{desks}' => (string) max(0, (int) ($w['desks_needed'] ?? 0)),
        '{cabin}' => ($w['cabin_needed'] ?? '') === 'Yes' ? ' and a cabin' : '',
        '{expected_start}' => $d($w['expected_start'] ?? null),
        '{status}' => (string) $w['status'],
        '{next_step}' => trim((string) ($w['next_step'] ?? '')),
    ];
}

/**
 * Sends one stage's email to an applicant, when that stage's email is on and
 * they gave an address. Records it on the application. Returns what happened, in words.
 */
function cw_stage_mail(int $org, array $centre, array $w, string $stage, string $actor = ''): string
{
    $to = trim((string) ($w['email'] ?? ''));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return 'no email address on the application';
    }
    [$on, $subjectT, $bodyT] = cw_mail_tpl($org, $stage);
    if (!$on) {
        return 'the "' . (cw_mail_stages()[$stage] ?? $stage) . '" email is turned off';
    }
    require_once __DIR__ . '/../mailtpl.php';
    require_once __DIR__ . '/../outbox.php';
    $vars = cw_mail_vars($w, $centre, $org);
    $subject = strtr($subjectT, $vars);
    $text = trim(preg_replace("/\n{3,}/", "\n\n", strtr($bodyT, $vars)));
    $brand = cw_org_name($org);
    $inner = '';
    foreach (preg_split("/\n\s*\n/", $text) as $para) {
        if (trim($para) !== '') {
            $inner .= fabo_mail_p(nl2br(e(trim($para)), false));
        }
    }
    $inner .= fabo_mail_rows(array_map('e', array_filter([
        'Reference' => $vars['{reference}'], 'Applicant' => $vars['{applicant}'], 'Centre' => $vars['{centre}'],
        'Stage' => $stage === 'received' ? 'Received' : $stage,
    ])));
    $html = fabo_mail_wrap($brand, $stage === 'received' ? 'Application received' : 'Your application: ' . $stage, $inner, $subject);
    $text .= "\n\nReference: " . $vars['{reference}'] . fabo_sign_off($brand);
    [$sent, , $queued] = fabo_post_or_queue($to, $subject, $text, $html, null, 'cw ' . $vars['{reference}'] . ' ' . $stage, cw_org_actor($org));
    $word = $sent ? 'sent' : ($queued ? 'queued (goes out shortly)' : 'could not be sent');
    cw_event((int) $w['id'], 'mail', '"' . $subject . '" to ' . $to . ' — ' . $word, $actor);
    return $word;
}

/* ================================================================= history */

function cw_event(int $waitId, string $kind, string $detail, string $actor = ''): void
{
    try {
        fabo_exec('INSERT INTO cw_events (wait_id, kind, detail, actor) VALUES (?,?,?,?)', 'isss',
                  [$waitId, $kind, mb_substr($detail, 0, 500), mb_substr($actor, 0, 190)]);
    } catch (Throwable $e) {
        error_log('FABO cw event: ' . $e->getMessage());
    }
}

/** Each application's history, newest first: wait id => [[when, kind, detail, who], ...]. */
function cw_events_for(array $waitIds): array
{
    $ids = array_values(array_filter(array_map('intval', $waitIds)));
    if (!$ids) {
        return [];
    }
    $out = [];
    foreach (fabo_all('SELECT * FROM cw_events WHERE wait_id IN (' . implode(',', $ids) . ') ORDER BY id DESC') as $r) {
        $out[(int) $r['wait_id']][] = [(string) $r['created_at'], (string) $r['kind'], (string) $r['detail'], (string) $r['actor']];
    }
    return $out;
}

/** The next reference for an organisation's applications: CW-2026-0001, … */
function cw_next_ref(int $org): string
{
    $n = (int) (fabo_one("SELECT COUNT(*) n FROM cw_waiting w JOIN cw_centres c ON c.id = w.centre_id
                           WHERE c.org_id = ? AND w.ref LIKE ?", 'is', [$org, 'CW-' . date('Y') . '-%'])['n'] ?? 0);
    do {
        $ref = sprintf('CW-%s-%04d', date('Y'), ++$n);
    } while (fabo_one('SELECT 1 x FROM cw_waiting w JOIN cw_centres c ON c.id = w.centre_id WHERE c.org_id = ? AND w.ref = ?', 'is', [$org, $ref]));
    return $ref;
}

/* ============================================================ the public form */

/**
 * The form's own guard, which needs no cookie: a page embedded on another
 * website does not get the portal's session. Signed with a secret kept in
 * app_settings and good for two hours; too quick a submission is a script.
 */
function cw_form_stamp(int $org): string
{
    $t = (string) time();
    return $t . '.' . hash_hmac('sha256', $org . '|' . $t, cw_form_secret());
}

function cw_form_stamp_ok(int $org, string $stamp): bool
{
    [$t, $sig] = array_pad(explode('.', $stamp, 2), 2, '');
    if (!ctype_digit($t) || !hash_equals(hash_hmac('sha256', $org . '|' . $t, cw_form_secret()), $sig)) {
        return false;
    }
    $age = time() - (int) $t;
    return $age >= 3 && $age <= 7200;
}

function cw_form_secret(): string
{
    $s = fabo_setting('cw_form_secret');
    if ($s === '') {
        $s = bin2hex(random_bytes(32));
        fabo_setting_put('cw_form_secret', $s);
    }
    return $s;
}

/**
 * Files an application from the website: onto the chosen centre's Waiting
 * List as New, the applicant thanked by email, the team told. Returns the
 * application. Throws InvalidArgumentException with words for the applicant.
 */
function cw_apply(int $org, array $in, string $ticket): array
{
    cw_install();
    $req = ['applicant' => 'Startup / company name', 'contact' => 'Your name', 'email' => 'Email', 'phone' => 'Phone', 'centre' => 'Centre'];
    foreach ($req as $k => $label) {
        if (trim((string) ($in[$k] ?? '')) === '') {
            throw new InvalidArgumentException('Please fill in ' . $label . '.');
        }
    }
    if (!filter_var(trim((string) $in['email']), FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Please give a valid email address.');
    }
    if (!preg_match('/^[0-9+()\-\s]{7,20}$/', trim((string) $in['phone']))) {
        throw new InvalidArgumentException('Please give a valid phone number.');
    }
    $centre = cw_centre($org, (int) $in['centre']);
    if (!$centre) {
        throw new InvalidArgumentException('Please choose a centre.');
    }
    if (!preg_match('/^[a-f0-9]{32}$/', $ticket)) {
        throw new InvalidArgumentException('The form expired. Please reload the page and try again.');
    }
    if ($done = fabo_one('SELECT * FROM cw_waiting WHERE ticket = ?', 's', [$ticket])) {
        return $done;                       // pressed twice: the first one counts
    }
    $website = trim((string) ($in['website'] ?? ''));
    if ($website !== '' && !preg_match('~^https?://~i', $website)) {
        $website = 'https://' . $website;
    }
    $id = cw_wait_save((int) $centre['id'], null, [
        'applicant' => $in['applicant'], 'contact' => $in['contact'], 'email' => $in['email'], 'phone' => $in['phone'],
        'sector' => mb_substr((string) ($in['sector'] ?? ''), 0, 120), 'desks_needed' => max(0, min(200, (int) ($in['desks'] ?? 0))),
        'cabin_needed' => ($in['cabin'] ?? '') === 'Yes' ? 'Yes' : 'No', 'source' => trim((string) ($in['heard'] ?? '')) ?: 'Website form',
        'applied_on' => cw_today(), 'status' => 'New', 'expected_start' => $in['start'] ?? '',
        'notes' => mb_substr(trim((string) ($in['about'] ?? '')), 0, 4000),
    ]);
    fabo_exec('UPDATE cw_waiting SET ref = ?, channel = ?, website = ?, ticket = ? WHERE id = ?', 'ssssi',
              [cw_next_ref($org), 'Website', $website !== '' ? mb_substr($website, 0, 255) : null, $ticket, $id]);
    $w = fabo_one('SELECT * FROM cw_waiting WHERE id = ?', 'i', [$id]);
    cw_event($id, 'created', 'Applied through the website form', (string) $w['contact']);
    try {
        cw_stage_mail($org, $centre, $w, 'received');
    } catch (Throwable $e) {
        error_log('FABO cw acknowledgement: ' . $e->getMessage());
    }
    cw_tell_team($org, $centre, $w);
    return $w;
}

/** A new application: a portal notice for the head, and an email to the addresses on Form & emails. */
function cw_tell_team(int $org, array $centre, array $w): void
{
    $head = 'New co-working application: ' . $w['applicant'] . ' (' . $centre['name'] . ')';
    $line = $w['ref'] . ' · ' . trim(implode(' · ', array_filter([(string) $w['contact'], (string) $w['email'], (string) $w['phone'],
            (int) $w['desks_needed'] ? $w['desks_needed'] . ' desk(s)' : '', $w['cabin_needed'] === 'Yes' ? 'cabin' : ''])));
    try {
        require_once __DIR__ . '/../notify.php';
        if ($org > 0) {
            fabo_notify($org, $head, $line . '. It is on the Waiting list as New.', 'coworking.php?p=waiting&c=' . (int) $centre['id']);
        } else {
            fabo_notify_admin($head, $line);
        }
        // The centre's own team hears too: its people get the notice, its managers the email.
        require_once __DIR__ . '/team.php';
        foreach (cw_centre_people_ids((int) $centre['id']) as $pid) {
            fabo_notify($pid, $head, $line . '. It is on the Waiting list as New.', 'coworking.php?p=waiting&c=' . (int) $centre['id']);
        }
        require_once __DIR__ . '/../outbox.php';
        foreach (array_unique(array_merge(cw_notify_emails($org), cw_centre_manager_emails((int) $centre['id']))) as $to) {
            $text = $head . "\n\n" . $line . "\n\nAbout them:\n" . ((string) $w['notes'] ?: '—')
                  . "\n\nIt is on " . $centre['name'] . "'s Waiting list in Co-working Space, as New.";
            fabo_post_or_queue($to, $head, $text, '', null, 'cw new ' . $w['ref']);
        }
    } catch (Throwable $e) {
        error_log('FABO cw team notice: ' . $e->getMessage());
    }
}

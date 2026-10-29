<?php
/**
 * Application form: each CRM has one, built on the CRM's own Form tab. Its
 * link is shared, and every application lands straight in the CRM as a new
 * record -- no Excel, no import -- and the applicant is emailed an
 * acknowledgement.
 *
 *   crm_forms       — the form: which CRM it feeds, the stage new applications
 *                     start in, its words (title, intro, thank-you), its public
 *                     token, who new applications are assigned to, and the
 *                     acknowledgement email (ack_*).
 *   crm_form_items  — its questions, in order. A question is one of the CRM's
 *                     fields, asked in the form's own words; a heading breaks
 *                     the form into sections. A new question made on the
 *                     builder becomes a new field on the CRM first, so the
 *                     CRM and its forms never disagree about what a record holds.
 *
 * Assigning: nobody; always one person; or taking turns among several. Turns
 * are kept on the form (rotate_next) and advanced in one atomic UPDATE, so two
 * applications arriving together do not go to the same person.
 *
 * The public page is apply.php; the builder is lib/crm_form_tab.php, the
 * CRM's Form tab.
 */

require_once __DIR__ . '/crm.php';

function fabo_cf_install(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    fabo_crm_install();
    $db   = fabo_db();
    $tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $db->query("CREATE TABLE IF NOT EXISTS `crm_forms` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `crm_id` INT UNSIGNED NOT NULL,
  `org_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `token` CHAR(32) NOT NULL,
  `title` VARCHAR(160) NOT NULL,
  `intro` TEXT NULL,
  `thanks` TEXT NULL,
  `stage_id` INT UNSIGNED NULL,
  `assign_mode` VARCHAR(8) NOT NULL DEFAULT 'none',
  `assign_people` VARCHAR(500) NOT NULL DEFAULT '',
  `rotate_next` INT UNSIGNED NOT NULL DEFAULT 0,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `ack_on` TINYINT(1) NOT NULL DEFAULT 1,
  `ack_subject` VARCHAR(200) NOT NULL DEFAULT '',
  `ack_body` TEXT NULL,
  `submit_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_submitted_at` DATETIME NULL,
  `created_by` VARCHAR(160) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `u_token` (`token`),
  KEY `k_org` (`org_id`),
  KEY `k_crm` (`crm_id`)
)" . $tail);
    $db->query("CREATE TABLE IF NOT EXISTS `crm_form_items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `form_id` INT UNSIGNED NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `kind` VARCHAR(8) NOT NULL DEFAULT 'field',
  `field_key` VARCHAR(48) NULL,
  `label` VARCHAR(200) NOT NULL DEFAULT '',
  `help` VARCHAR(500) NOT NULL DEFAULT '',
  `required` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `k_form` (`form_id`,`sort_order`)
)" . $tail);
    // The acknowledgement email arrived after the first release of this table.
    $res = $db->query("SHOW COLUMNS FROM `crm_forms` LIKE 'ack_on'");
    if ($res && !$res->num_rows) {
        $db->query("ALTER TABLE `crm_forms` ADD COLUMN `ack_on` TINYINT(1) NOT NULL DEFAULT 1 AFTER `active`,
                    ADD COLUMN `ack_subject` VARCHAR(200) NOT NULL DEFAULT '' AFTER `ack_on`,
                    ADD COLUMN `ack_body` TEXT NULL AFTER `ack_subject`");
    }
}

/** The acknowledgement a new form starts with: plain, and easy to change. */
function fabo_cf_ack_default(): array
{
    return ['Thank you for applying — your reference is {{Reference}}',
            "Dear {{Name}},\n\nThank you for your application. We have received it, and your reference number is {{Reference}}.\n\nWe will be in touch soon.\n\nRegards,\n{{Sender}}"];
}

/* ============================================================== reading */

function fabo_cf(int $id): ?array
{
    fabo_cf_install();
    return $id > 0 ? fabo_one('SELECT * FROM crm_forms WHERE id = ?', 'i', [$id]) : null;
}

function fabo_cf_by_token(string $token): ?array
{
    fabo_cf_install();
    if (!preg_match('/^[0-9a-f]{32}$/', $token)) {
        return null;
    }
    return fabo_one('SELECT * FROM crm_forms WHERE token = ?', 's', [$token]);
}

function fabo_cf_items(int $formId): array
{
    fabo_cf_install();
    return fabo_all('SELECT * FROM crm_form_items WHERE form_id = ? ORDER BY sort_order, id', 'i', [$formId]);
}

/** 'ok' | 'off' | 'gone' (its CRM was deleted or archived). */
function fabo_cf_state(array $form): string
{
    $b = fabo_crm_board((int) $form['crm_id']);
    if (!$b || !empty($b['archived'])) {
        return 'gone';
    }
    return (int) $form['active'] ? 'ok' : 'off';
}

function fabo_cf_url(array $form): string
{
    return fabo_portal_url() . 'apply.php?f=' . $form['token'];
}

/**
 * This CRM's form -- its oldest, if more than one was ever made -- made now
 * when $create and it has none. Null when it has none and none was made.
 */
function fabo_cf_for_board(array $board, bool $create = false, string $who = ''): ?array
{
    fabo_cf_install();
    $f = fabo_one('SELECT * FROM crm_forms WHERE crm_id = ? ORDER BY id LIMIT 1', 'i', [(int) $board['id']]);
    if (!$f && $create) {
        $f = fabo_cf((int) fabo_cf_create($board, (string) $board['name'], $who));
    }
    return $f;
}

/**
 * People new applications may be assigned to: the CRM's assignable people who
 * can actually open it -- assigning a record to somebody who cannot see the
 * CRM would hide it from everyone who looks. id => name.
 */
function fabo_cf_assignees(array $board): array
{
    $out = [];
    foreach (fabo_crm_assignable($board) as $pid => $name) {
        $p = fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [(int) $pid]);
        if ($p && fabo_crm_may_see(fabo_person_viewer($p), $board)) {
            $out[(int) $pid] = $name;
        }
    }
    return $out;
}

/** The ids a form assigns to, as ints, in their stored order. */
function fabo_cf_people(array $form): array
{
    return array_values(array_filter(array_map('intval', explode(',', (string) $form['assign_people']))));
}

/** "Taking turns: Asha, Ravi" — the assignment, in words, for the list. */
function fabo_cf_assign_words(array $form, array $board): string
{
    $people = fabo_crm_assignable($board);
    $names  = [];
    foreach (fabo_cf_people($form) as $pid) {
        $names[] = $people[$pid] ?? ('#' . $pid);
    }
    switch ($form['assign_mode']) {
        case 'one':
            return $names ? 'Always ' . $names[0] : 'Nobody';
        case 'rotate':
            return $names ? 'Taking turns: ' . implode(', ', $names) : 'Nobody';
    }
    return 'Not assigned';
}

/* ============================================================== writing */

function fabo_cf_new_token(): string
{
    do {
        $t = bin2hex(random_bytes(16));
    } while (fabo_one('SELECT id FROM crm_forms WHERE token = ?', 's', [$t]));
    return $t;
}

/**
 * A new form for a CRM, asking every field it has (bar the ones the CRM
 * fills itself), in the CRM's order. Returns its id.
 */
function fabo_cf_create(array $board, string $title, string $who): int
{
    fabo_cf_install();
    $title = trim($title);
    if ($title === '') {
        throw new InvalidArgumentException('Give the form a title applicants will see, e.g. “Admissions 2027”.');
    }
    $stages = fabo_crm_stages((int) $board['id']);
    [$ackSubj, $ackBody] = fabo_cf_ack_default();
    foreach (fabo_crm_fields((int) $board['id']) as $f) {
        if ($f['type'] === 'text') {
            $ackBody = str_replace('{{Name}}', '{{' . $f['label'] . '}}', $ackBody);
            break;
        }
    }
    $id = (int) fabo_exec('INSERT INTO crm_forms (crm_id, org_id, token, title, intro, thanks, stage_id, ack_on, ack_subject, ack_body, created_by)
                           VALUES (?,?,?,?,?,?,?,1,?,?,?)', 'iissssisss',
        [(int) $board['id'], (int) $board['client_id'], fabo_cf_new_token(), mb_substr($title, 0, 160),
         'Fill in the form below. Fields marked * are required.',
         'Thank you — your application has been received. We have emailed you a confirmation.',
         $stages ? (int) $stages[0]['id'] : null, $ackSubj, $ackBody, mb_substr($who, 0, 160)]);
    $i = 0;
    foreach (fabo_crm_fields((int) $board['id']) as $f) {
        if ($f['type'] === 'autonumber') {
            continue;                       // given when the record is saved
        }
        fabo_exec('INSERT INTO crm_form_items (form_id, sort_order, kind, field_key, label, help, required)
                   VALUES (?,?,"field",?,?,?,?)', 'iisssi',
                  [$id, ++$i, $f['field_key'], (string) $f['label'], (string) ($f['help'] ?? ''), (int) !empty($f['required'])]);
    }
    return $id;
}

/** Saves the settings half of the builder. */
function fabo_cf_save_settings(array $form, array $board, array $in): void
{
    $title = trim((string) ($in['title'] ?? ''));
    if ($title === '') {
        throw new InvalidArgumentException('The form needs a title.');
    }
    $stage = (int) ($in['stage_id'] ?? 0);
    if ($stage > 0 && !fabo_crm_stage((int) $board['id'], $stage)) {
        throw new InvalidArgumentException('That stage is not on this CRM.');
    }
    $mode = (string) ($in['assign_mode'] ?? 'none');
    if (!in_array($mode, ['none', 'one', 'rotate'], true)) {
        $mode = 'none';
    }
    $allowed = fabo_cf_assignees($board);
    $people  = [];
    if ($mode === 'one') {
        $one = (int) ($in['assign_one'] ?? 0);
        if ($one > 0) {
            if (!isset($allowed[$one])) {
                throw new InvalidArgumentException('That person cannot open this CRM, so applications cannot be assigned to them.');
            }
            $people = [$one];
        }
    } elseif ($mode === 'rotate') {
        foreach ((array) ($in['assign_many'] ?? []) as $pid) {
            $pid = (int) $pid;
            if (isset($allowed[$pid])) {
                $people[$pid] = $pid;
            }
        }
        $people = array_values($people);
        if (count($people) < 2) {
            throw new InvalidArgumentException('Tick at least two people to take turns — or choose “Always the same person”.');
        }
    }
    if ($mode !== 'none' && !$people) {
        $mode = 'none';
    }
    // rotate_next first: SET runs left to right, and it must compare against the OLD list of people.
    fabo_exec('UPDATE crm_forms SET rotate_next = IF(assign_people = ?, rotate_next, 0), title = ?, intro = ?, thanks = ?,
                      stage_id = ?, assign_mode = ?, assign_people = ?, active = ? WHERE id = ?', 'sssssssii',
              [implode(',', $people), mb_substr($title, 0, 160), mb_substr(trim((string) ($in['intro'] ?? '')), 0, 4000),
               mb_substr(trim((string) ($in['thanks'] ?? '')), 0, 4000), $stage > 0 ? (string) $stage : null,
               $mode, implode(',', $people), !empty($in['active']) ? 1 : 0, (int) $form['id']]);
}

/** Saves the acknowledgement email. */
function fabo_cf_save_ack(array $form, array $in): void
{
    $on   = !empty($in['ack_on']) ? 1 : 0;
    $subj = trim((string) ($in['ack_subject'] ?? ''));
    $body = trim(str_replace("\r\n", "\n", (string) ($in['ack_body'] ?? '')));
    if ($on && ($subj === '' || $body === '')) {
        throw new InvalidArgumentException('The acknowledgement needs a subject and a message — or untick it to send none.');
    }
    fabo_exec('UPDATE crm_forms SET ack_on = ?, ack_subject = ?, ack_body = ? WHERE id = ?', 'issi',
              [$on, mb_substr($subj, 0, 200), mb_substr($body, 0, 8000), (int) $form['id']]);
}

/**
 * Saves the questions half: the posted list, in the posted order, replaces
 * what was there. Only this CRM's fields, each at most once.
 */
function fabo_cf_save_items(array $form, array $board, array $posted): void
{
    $fields = [];
    foreach (fabo_crm_fields((int) $board['id']) as $f) {
        $fields[(string) $f['field_key']] = $f;
    }
    $rows = [];
    $seen = [];
    foreach ($posted as $it) {
        if (!is_array($it) || !empty($it['remove'])) {
            continue;
        }
        $kind = ($it['kind'] ?? '') === 'heading' ? 'heading' : 'field';
        $label = mb_substr(trim((string) ($it['label'] ?? '')), 0, 200);
        if ($kind === 'heading') {
            if ($label !== '') {
                $rows[] = ['heading', null, $label, mb_substr(trim((string) ($it['help'] ?? '')), 0, 500), 0];
            }
            continue;
        }
        $key = (string) ($it['field_key'] ?? '');
        if (!isset($fields[$key]) || isset($seen[$key]) || $fields[$key]['type'] === 'autonumber') {
            continue;
        }
        $seen[$key] = true;
        $rows[] = ['field', $key, $label !== '' ? $label : (string) $fields[$key]['label'],
                   mb_substr(trim((string) ($it['help'] ?? '')), 0, 500),
                   (!empty($it['required']) || !empty($fields[$key]['required'])) ? 1 : 0];
    }
    if (!array_filter($rows, static fn($r) => $r[0] === 'field')) {
        throw new InvalidArgumentException('A form needs at least one question.');
    }
    $db = fabo_db();
    $db->begin_transaction();
    try {
        fabo_exec('DELETE FROM crm_form_items WHERE form_id = ?', 'i', [(int) $form['id']]);
        foreach ($rows as $i => [$kind, $key, $label, $help, $req]) {
            fabo_exec('INSERT INTO crm_form_items (form_id, sort_order, kind, field_key, label, help, required)
                       VALUES (?,?,?,?,?,?,?)', 'iissssi', [(int) $form['id'], $i + 1, $kind, $key, $label, $help, $req]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

/** A brand-new question: a new field on the CRM, added to the end of the form. */
function fabo_cf_add_question(array $form, array $board, array $in): void
{
    $key = fabo_crm_field_add((int) $board['id'], [
        'label'    => (string) ($in['label'] ?? ''),
        'type'     => (string) ($in['type'] ?? 'text'),
        'options'  => (string) ($in['options'] ?? ''),
        'required' => 0,                  // required on the form, not for records typed in by hand
        'in_list'  => 0,
    ]);
    $n = (int) (fabo_one('SELECT MAX(sort_order) AS n FROM crm_form_items WHERE form_id = ?', 'i', [(int) $form['id']])['n'] ?? 0);
    $f = fabo_crm_field_by_key((int) $board['id'], $key);
    fabo_exec('INSERT INTO crm_form_items (form_id, sort_order, kind, field_key, label, help, required)
               VALUES (?,?,"field",?,?,"",?)', 'iissi',
              [(int) $form['id'], $n + 1, $key, (string) ($f['label'] ?? $key), !empty($in['required']) ? 1 : 0]);
}

function fabo_cf_delete(array $form): void
{
    fabo_exec('DELETE FROM crm_form_items WHERE form_id = ?', 'i', [(int) $form['id']]);
    fabo_exec('DELETE FROM crm_forms WHERE id = ?', 'i', [(int) $form['id']]);
}

/** A new link: the old one stops working at once. */
function fabo_cf_rotate(array $form): void
{
    fabo_exec('UPDATE crm_forms SET token = ? WHERE id = ?', 'si', [fabo_cf_new_token(), (int) $form['id']]);
}

/* ============================================================ the form */

/**
 * What the public page asks: each item with its field, the field's own
 * label swapped for the form's wording and 'required' set as the form says.
 * Items whose field has since been deleted from the CRM are dropped.
 */
function fabo_cf_questions(array $form): array
{
    $fields = [];
    foreach (fabo_crm_fields((int) $form['crm_id']) as $f) {
        $fields[(string) $f['field_key']] = $f;
    }
    $out = [];
    foreach (fabo_cf_items((int) $form['id']) as $it) {
        if ($it['kind'] === 'heading') {
            $out[] = ['kind' => 'heading', 'label' => $it['label'], 'help' => $it['help']];
            continue;
        }
        $f = $fields[(string) $it['field_key']] ?? null;
        if (!$f || $f['type'] === 'autonumber') {
            continue;
        }
        $f['label']    = $it['label'] !== '' ? $it['label'] : $f['label'];
        $f['help']     = $it['help'] !== '' ? $it['help'] : (string) ($f['help'] ?? '');
        $f['required'] = (int) $it['required'] || !empty($f['required']) ? 1 : 0;
        $out[] = ['kind' => 'field', 'field' => $f];
    }
    return $out;
}

/** The next person in line for a form, or 0. One atomic step, so turns are never taken twice. */
function fabo_cf_next_assignee(array $form, array $board): int
{
    $people = fabo_cf_people($form);
    if ($form['assign_mode'] === 'none' || !$people) {
        return 0;
    }
    // Only people who can still open the CRM; somebody removed since is skipped.
    $allowed = fabo_cf_assignees($board);
    $people  = array_values(array_filter($people, static fn($p) => isset($allowed[$p])));
    if (!$people) {
        return 0;
    }
    if ($form['assign_mode'] === 'one') {
        return $people[0];
    }
    $n = fabo_exec('UPDATE crm_forms SET rotate_next = LAST_INSERT_ID(rotate_next + 1) WHERE id = ?', 'i', [(int) $form['id']]);
    return $people[($n - 1) % count($people)];
}

/**
 * Turns one submission into a CRM record. $post is $_POST['f'] (answers by
 * field key); files come from $_FILES['file_<key>'] as on the CRM's own form.
 * Returns [recordId, reference, fileProblems]; throws InvalidArgumentException
 * with every problem, one per line, when something must be put right first.
 */
function fabo_cf_submit(array $form, array $post): array
{
    $board = fabo_crm_board((int) $form['crm_id']);
    if (!$board || fabo_cf_state($form) !== 'ok') {
        throw new InvalidArgumentException('This form is not taking applications at the moment.');
    }
    $asked = [];
    foreach (fabo_cf_questions($form) as $q) {
        if ($q['kind'] === 'field') {
            $asked[] = $q['field'];
        }
    }
    $onForm = array_values(array_filter($asked, static fn($f) => $f['type'] !== 'file'));
    [$data, $errs] = fabo_crm_from_post($onForm, $post);
    // Required and unique, for what this form asks -- worded privately, as for a stranger.
    $errs = array_merge($errs, fabo_crm_check((int) $board['id'], $onForm, $data, 0, true));
    foreach ($asked as $f) {
        if ($f['type'] === 'file' && !empty($f['required'])) {
            $set = $_FILES['file_' . $f['field_key']] ?? null;
            $any = false;
            foreach ((array) ($set['error'] ?? []) as $err) {
                $any = $any || (int) $err === UPLOAD_ERR_OK;
            }
            if (!$any) {
                $errs[] = $f['label'] . ' is required.';
            }
        }
    }
    if ($errs) {
        // "Email: … is not an email address" already says it; "Email is required" on top only confuses.
        $errs = array_values(array_filter(array_unique($errs), static function ($er) use ($errs) {
            if (!str_ends_with($er, ' is required.')) {
                return true;
            }
            $label = substr($er, 0, -strlen(' is required.'));
            foreach ($errs as $o) {
                if (str_starts_with($o, $label . ': ')) {
                    return false;
                }
            }
            return true;
        }));
        throw new InvalidArgumentException(implode("\n", $errs));
    }
    $who    = 'Form: ' . $form['title'];
    $all    = fabo_crm_fields((int) $board['id']);         // so auto numbers are given
    $stage  = (int) ($form['stage_id'] ?? 0);
    if ($stage > 0 && !fabo_crm_stage((int) $board['id'], $stage)) {
        $stage = 0;
    }
    if ($stage === 0) {
        $stages = fabo_crm_stages((int) $board['id']);
        $stage  = $stages ? (int) $stages[0]['id'] : 0;
    }
    $id = fabo_crm_record_create($board, $all, $data, $stage, $who);
    fabo_crm_log((int) $board['id'], $id, $who, 'note', 'Received from the form “' . $form['title'] . '”.');
    $fileErrs = fabo_crm_files_take($board, array_values(array_filter($asked, static fn($f) => $f['type'] === 'file')), $id, $who);

    $to = fabo_cf_next_assignee($form, $board);
    if ($to > 0) {
        try {
            fabo_crm_assign($board, fabo_crm_record((int) $board['id'], $id), $to,
                            ['is_admin' => false, 'client' => ['id' => 0, 'name' => $who]]);
        } catch (Throwable $e) {
            error_log('FABO crm form assign: ' . $e->getMessage());
        }
    }
    fabo_exec('UPDATE crm_forms SET submit_count = submit_count + 1, last_submitted_at = NOW() WHERE id = ?', 'i',
              [(int) $form['id']]);

    // The reference an applicant can quote: the CRM's own number for it, if it gives one.
    $rec = fabo_crm_record((int) $board['id'], $id);
    $ref = $board['record_label'] . ' #' . $id;
    foreach ($all as $f) {
        if ($f['type'] === 'autonumber' && !fabo_crm_blank($rec['d'][$f['field_key']] ?? null)) {
            $ref = (string) $rec['d'][$f['field_key']];
            break;
        }
    }
    fabo_cf_acknowledge($form, $board, $all, $rec, $ref);
    return [$id, $ref, $fileErrs];
}

/**
 * Emails the applicant to say their application arrived. Sent from the
 * organisation that owns the CRM (their own mailbox if they have one), through
 * the portal's outbox, so a busy mail server queues it rather than losing it.
 * Never stops the application itself: it is already saved.
 */
function fabo_cf_acknowledge(array $form, array $board, array $fields, array $rec, string $ref): void
{
    if (empty($form['ack_on']) || trim((string) $form['ack_subject']) === '' || trim((string) $form['ack_body']) === '') {
        return;
    }
    try {
        require_once __DIR__ . '/crm_mail.php';
        $to = fabo_crm_mail_address($fields, $rec);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $org   = (int) $board['client_id'];
        $actor = $org > 0 ? fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [$org]) : null;
        $brand = fabo_crm_mail_brand($actor);
        $vals  = fabo_crm_mail_values($board, $fields, $rec, $brand, ['reference' => $ref]);
        // The form's own wording works too: {{Full name}} for a question asked that way of the CRM's "Name".
        foreach (fabo_cf_questions($form) as $q) {
            if ($q['kind'] === 'field') {
                $vals += [mb_strtolower(trim((string) $q['field']['label'])) => fabo_crm_mail_word($q['field'], $rec['d'][$q['field']['field_key']] ?? null)];
            }
        }
        $subj  = fabo_crm_mail_merge((string) $form['ack_subject'], $vals);
        $text  = fabo_crm_mail_merge((string) $form['ack_body'], $vals);
        require_once __DIR__ . '/outbox.php';
        [$sent, $why] = fabo_post_or_queue($to, $subj, $text . "\n\n--\n" . $brand,
                                           fabo_crm_mail_html($brand, $subj, $text, ''), null, 'crm-ack', $actor);
        fabo_crm_log((int) $board['id'], (int) $rec['id'], 'Form: ' . $form['title'], 'email',
                     ($sent ? 'Acknowledgement emailed to ' : 'Acknowledgement queued for ') . $to . ': “' . $subj . '”.');
    } catch (Throwable $e) {
        error_log('FABO crm form acknowledgement: ' . $e->getMessage());
    }
}

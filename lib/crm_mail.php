<?php
/**
 * CRM bulk email: templates kept per stage, sent to every record in a stage
 * (or to the records ticked on the list), one personalised message each.
 *
 *   crm_mail_templates  — a CRM's saved emails. stage_id NULL = any stage.
 *                         Several per stage: a welcome, a reminder, a
 *                         documents request — whatever that stage needs.
 *   crm_mail_sends      — one row per "Send": what went, to which stage, by whom.
 *   crm_mail_queue      — one row per recipient, already personalised, so a
 *                         message sent an hour later is the one that was written.
 *   crm_mail_optout     — addresses that asked to stop, per organisation. Every
 *                         message carries a link to join it, and nobody on it
 *                         is ever queued again by any of that organisation's CRMs.
 *
 * Why a queue of its own rather than the portal's outbox: the outbox carries
 * sign-in links and passwords, first come first served. Five hundred CRM
 * emails in front of somebody's password would keep them locked out for
 * hours. So bulk mail waits behind the outbox, stops a little short of the
 * hourly allowance (MAIL_PER_HOUR) to leave room for it, and backs off the
 * moment the mail server says "slow down" — the same signal the outbox uses.
 */

require_once __DIR__ . '/crm.php';
require_once __DIR__ . '/outbox.php';
require_once __DIR__ . '/notify.php';
require_once __DIR__ . '/mailtpl.php';
require_once __DIR__ . '/cryptolink.php';

/** Messages a bulk send leaves free each hour for sign-in links and the rest of the portal. */
const FABO_CRM_MAIL_RESERVE = 10;

/** The most records one send reaches. */
const FABO_CRM_MAIL_MAX = 5000;

function fabo_crm_mail_install(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    fabo_crm_install();
    $db   = fabo_db();
    $tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    $db->query("CREATE TABLE IF NOT EXISTS `crm_mail_templates` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `crm_id` INT UNSIGNED NOT NULL,
  `stage_id` INT UNSIGNED NULL,
  `name` VARCHAR(120) NOT NULL,
  `subject` VARCHAR(200) NOT NULL,
  `body` MEDIUMTEXT NOT NULL,
  `created_by` VARCHAR(160) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_crm_stage` (`crm_id`,`stage_id`)
)" . $tail);

    $db->query("CREATE TABLE IF NOT EXISTS `crm_mail_sends` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `crm_id` INT UNSIGNED NOT NULL,
  `template_id` INT UNSIGNED NULL,
  `stage_id` INT UNSIGNED NULL,
  `name` VARCHAR(120) NOT NULL DEFAULT '',
  `subject` VARCHAR(200) NOT NULL DEFAULT '',
  `who` VARCHAR(160) NOT NULL DEFAULT '',
  `sender_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `total` INT UNSIGNED NOT NULL DEFAULT 0,
  `skipped` INT UNSIGNED NOT NULL DEFAULT 0,
  `cancelled_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_crm` (`crm_id`,`created_at`)
)" . $tail);

    $db->query("CREATE TABLE IF NOT EXISTS `crm_mail_queue` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `send_id` INT UNSIGNED NOT NULL,
  `crm_id` INT UNSIGNED NOT NULL,
  `record_id` INT UNSIGNED NOT NULL,
  `to_email` VARCHAR(190) NOT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `body_text` MEDIUMTEXT NULL,
  `body_html` MEDIUMTEXT NULL,
  `state` VARCHAR(12) NOT NULL DEFAULT 'waiting',
  `tries` INT NOT NULL DEFAULT 0,
  `last_error` VARCHAR(255) NULL,
  `sent_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_state` (`state`,`id`),
  KEY `k_send` (`send_id`,`state`),
  KEY `k_record` (`crm_id`,`record_id`)
)" . $tail);

    $db->query("CREATE TABLE IF NOT EXISTS `crm_mail_optout` (
  `org_id` INT UNSIGNED NOT NULL,
  `email` VARCHAR(190) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`org_id`,`email`)
)" . $tail);
}

/* ========================================================== templates */

function fabo_crm_mail_templates(int $crmId): array
{
    fabo_crm_mail_install();
    return fabo_all('SELECT * FROM crm_mail_templates WHERE crm_id = ? ORDER BY stage_id IS NULL, stage_id, name, id',
                    'i', [$crmId]);
}

function fabo_crm_mail_template(int $crmId, int $id): ?array
{
    fabo_crm_mail_install();
    return $id > 0 ? fabo_one('SELECT * FROM crm_mail_templates WHERE id = ? AND crm_id = ?', 'ii', [$id, $crmId]) : null;
}

/** Templates for one stage first, then the ones for any stage. */
function fabo_crm_mail_templates_for(int $crmId, int $stageId): array
{
    $mine = $any = [];
    foreach (fabo_crm_mail_templates($crmId) as $t) {
        if ($t['stage_id'] === null) {
            $any[] = $t;
        } elseif ((int) $t['stage_id'] === $stageId) {
            $mine[] = $t;
        }
    }
    return array_merge($mine, $any);
}

/** Adds or changes a template. Returns its id. */
function fabo_crm_mail_template_save(array $board, array $in, string $who): int
{
    fabo_crm_mail_install();
    $cid     = (int) $board['id'];
    $name    = trim((string) ($in['name'] ?? ''));
    $subject = trim((string) ($in['subject'] ?? ''));
    $body    = trim(str_replace("\r\n", "\n", (string) ($in['body'] ?? '')));
    if ($name === '' || $subject === '' || $body === '') {
        throw new InvalidArgumentException('A template needs a name, a subject and a message.');
    }
    $stage = (int) ($in['stage_id'] ?? 0);
    if ($stage > 0 && !fabo_crm_stage($cid, $stage)) {
        throw new InvalidArgumentException('That stage is not on this CRM.');
    }
    $stageVal = $stage > 0 ? $stage : null;
    $id = (int) ($in['tpl'] ?? 0);
    if ($id > 0) {
        if (!fabo_crm_mail_template($cid, $id)) {
            throw new InvalidArgumentException('That template was not found.');
        }
        fabo_exec('UPDATE crm_mail_templates SET stage_id = ?, name = ?, subject = ?, body = ? WHERE id = ? AND crm_id = ?',
                  'isssii', [$stageVal, mb_substr($name, 0, 120), mb_substr($subject, 0, 200), $body, $id, $cid]);
        return $id;
    }
    return (int) fabo_exec('INSERT INTO crm_mail_templates (crm_id, stage_id, name, subject, body, created_by)
                            VALUES (?,?,?,?,?,?)', 'iissss',
                           [$cid, $stageVal, mb_substr($name, 0, 120), mb_substr($subject, 0, 200), $body,
                            mb_substr($who, 0, 160)]);
}

function fabo_crm_mail_template_delete(int $crmId, int $id): void
{
    fabo_crm_mail_install();
    fabo_exec('DELETE FROM crm_mail_templates WHERE id = ? AND crm_id = ?', 'ii', [$id, $crmId]);
}

/* ============================================================== merge */

/** The tags a message may use, as tag => what it stands for. Field labels first. */
function fabo_crm_mail_tags(array $fields): array
{
    $out = [];
    foreach ($fields as $f) {
        if ($f['type'] !== 'file') {
            $out[(string) $f['label']] = fabo_crm_type_label($f['type']);
        }
    }
    foreach (['Stage' => 'Where the record is now', 'Assigned to' => 'Who it is assigned to',
              'Record no' => 'Its number, e.g. 42', 'Sender' => 'Your organisation’s name'] as $k => $v) {
        $out += [$k => $v];
    }
    return $out;
}

/**
 * The one control for putting a person's details into a message: a dropdown
 * that drops {{Detail}} in at the cursor, in whichever box of its form was
 * used last (assets/crm.js). Replaces a wall of buttons, one per field.
 */
function fabo_crm_mail_insert(array $tags): string
{
    $h = '<div class="crm-ins"><label>' . fabo_crm_icon('plus', 13) . ' <span>Insert a detail</span>'
       . '<select data-insert aria-label="Insert a detail about the person"><option value="">choose…</option>';
    foreach ($tags as $tag => $what) {
        $h .= '<option value="' . e((string) $tag) . '">' . e((string) $tag) . '</option>';
    }
    return $h . '</select></label><small>It is filled in for each person when the email goes.</small></div>';
}

/** A stored value as plain words, for a merged message (fabo_crm_plain() is the export's, as spreadsheet cells). */
function fabo_crm_mail_word(array $f, $v): string
{
    if (fabo_crm_blank($v)) {
        return $f['type'] === 'checkbox' ? 'No' : '';
    }
    switch ($f['type']) {
        case 'checkbox':
            return $v ? 'Yes' : 'No';
        case 'multiselect':
            return implode(', ', (array) $v);
        case 'date':
            $ts = strtotime((string) $v);
            return $ts ? date('j M Y', $ts) : (string) $v;
        case 'datetime':
            $ts = strtotime((string) $v);
            return $ts ? date('j M Y, g:i A', $ts) : (string) $v;
        case 'time':
            $ts = strtotime('2000-01-01 ' . $v);
            return $ts ? date('g:i A', $ts) : (string) $v;
        case 'currency':
            return fabo_money_exact($v);
        case 'number':
            return number_format((float) $v);
        case 'rating':
            return (int) $v . ' of 5';
        case 'file':
            return '';
    }
    return is_array($v) ? implode(', ', $v) : (string) $v;
}

/** Tag => value for one record. Keys are lower-cased for matching. $extra adds more (e.g. 'reference'). */
function fabo_crm_mail_values(array $board, array $fields, array $rec, string $sender, array $extra = []): array
{
    $vals = [];
    foreach ($fields as $f) {
        $vals[mb_strtolower(trim((string) $f['label']))] = fabo_crm_mail_word($f, $rec['d'][$f['field_key']] ?? null);
    }
    $stage = !empty($rec['stage_id']) ? fabo_crm_stage((int) $board['id'], (int) $rec['stage_id']) : null;
    $vals += [
        'stage'       => (string) ($stage['name'] ?? ''),
        'assigned to' => (string) ($rec['assignee'] ?? ''),
        'record no'   => (string) $rec['id'],
        'sender'      => $sender,
    ];
    return $extra + $vals;
}

/** Replaces {{Tag}} with the record's value. Unknown tags come out blank. */
function fabo_crm_mail_merge(string $text, array $vals): string
{
    return preg_replace_callback('/\{\{\s*([^{}]+?)\s*\}\}/u', static function ($m) use ($vals) {
        return $vals[mb_strtolower($m[1])] ?? '';
    }, $text);
}

/** Tags used in $text that this CRM does not have — shown as a warning before sending. */
function fabo_crm_mail_unknown_tags(string $text, array $fields): array
{
    $known = array_map('mb_strtolower', array_keys(fabo_crm_mail_tags($fields)));
    preg_match_all('/\{\{\s*([^{}]+?)\s*\}\}/u', $text, $m);
    $out = [];
    foreach ($m[1] as $t) {
        if (!in_array(mb_strtolower($t), $known, true)) {
            $out[$t] = true;
        }
    }
    return array_keys($out);
}

/* ============================================================= opt-out */

function fabo_crm_mail_token(int $queueId): string
{
    return substr(fabo_b64url(hash_hmac('sha256', 'crm-unsub|' . $queueId, fabo_seal_key(), true)), 0, 24);
}

function fabo_crm_mail_unsub_url(int $queueId): string
{
    return fabo_portal_url() . 'unsubscribe.php?m=' . $queueId . '&t=' . fabo_crm_mail_token($queueId);
}

/** The queued message an unsubscribe link belongs to, or null when the token does not match. */
function fabo_crm_mail_by_token(int $queueId, string $token): ?array
{
    fabo_crm_mail_install();
    if ($queueId <= 0 || $token === '' || !hash_equals(fabo_crm_mail_token($queueId), $token)) {
        return null;
    }
    return fabo_one('SELECT q.*, b.client_id AS org_id, b.name AS crm_name FROM crm_mail_queue q
                       JOIN crm_boards b ON b.id = q.crm_id WHERE q.id = ?', 'i', [$queueId]);
}

function fabo_crm_mail_opted_out(int $orgId, string $email): bool
{
    fabo_crm_mail_install();
    return (bool) fabo_one('SELECT 1 AS x FROM crm_mail_optout WHERE org_id = ? AND email = ?', 'is',
                           [$orgId, mb_strtolower(trim($email))]);
}

/** Stops all further bulk mail from this organisation to this address. */
function fabo_crm_mail_optout(array $q): void
{
    fabo_crm_mail_install();
    $email = mb_strtolower(trim((string) $q['to_email']));
    fabo_exec('INSERT IGNORE INTO crm_mail_optout (org_id, email) VALUES (?,?)', 'is', [(int) $q['org_id'], $email]);
    // Anything still waiting for them, from any of the organisation's CRMs, is dropped.
    fabo_exec('UPDATE crm_mail_queue q JOIN crm_boards b ON b.id = q.crm_id
                  SET q.state = "skipped", q.last_error = "unsubscribed"
                WHERE q.state = "waiting" AND q.to_email = ? AND b.client_id = ?', 'si', [$email, (int) $q['org_id']]);
    fabo_crm_log((int) $q['crm_id'], (int) $q['record_id'], $email, 'email', 'Unsubscribed from bulk emails.');
}

/* ============================================================= sending */

/** The person a message is sent as: their row, or null for the administrator (the portal itself). */
function fabo_crm_mail_actor(array $viewer): ?array
{
    return !empty($viewer['is_admin']) ? null : ($viewer['client'] ?? null);
}

/** The organisation name a message is signed with. */
function fabo_crm_mail_brand(?array $actor): string
{
    return fabo_sender_brand($actor);
}

/** The record's address: the chosen email field, else its first email. */
function fabo_crm_mail_address(array $fields, array $rec, string $fieldKey = ''): string
{
    if ($fieldKey !== '') {
        $v = $rec['d'][$fieldKey] ?? '';
        return is_string($v) ? trim($v) : '';
    }
    return trim(fabo_crm_contact($fields, $rec['d'] ?? [])['email']);
}

/** The email fields of a CRM. key => label. */
function fabo_crm_mail_fields(array $fields): array
{
    $out = [];
    foreach ($fields as $f) {
        if ($f['type'] === 'email') {
            $out[(string) $f['field_key']] = (string) $f['label'];
        }
    }
    return $out;
}

/** The message as HTML: their words, paragraph by paragraph, and the unsubscribe line. */
function fabo_crm_mail_html(string $brand, string $subject, string $body, string $unsubUrl): string
{
    $paras = preg_split('/\n\s*\n/', trim($body)) ?: [];
    $inner = '';
    foreach ($paras as $p) {
        $h = htmlspecialchars($p, ENT_QUOTES, 'UTF-8');
        // Addresses become links; everything else is shown exactly as written.
        $h = preg_replace('~(https?://[^\s<]+[^\s<.,;:!?)\]])~i',
                          '<a href="$1" style="color:' . fabo_mail_accent() . ';">$1</a>', $h);
        $inner .= fabo_mail_p(nl2br($h));
    }
    $soft = fabo_mail_soft();
    $line = fabo_mail_line();
    return '<!doctype html><html><head><meta charset="utf-8">'
         . '<meta name="viewport" content="width=device-width,initial-scale=1">'
         . '<title>' . e($subject) . '</title></head>'
         . '<body style="margin:0;padding:0;background:' . fabo_mail_panel() . ';">'
         . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;background:' . fabo_mail_panel() . ';">'
         . '<tr><td align="center" style="padding:24px 12px;">'
         . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" style="border-collapse:collapse;width:600px;max-width:100%;background:#ffffff;border:1px solid ' . $line . ';border-radius:10px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;">'
         . '<tr><td style="padding:22px 28px 6px;"><div style="font-size:13px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;color:' . fabo_mail_accent() . ';">' . e($brand) . '</div></td></tr>'
         . '<tr><td style="padding:12px 28px 8px;">' . $inner . '</td></tr>'
         . '<tr><td style="padding:14px 28px 22px;border-top:1px solid ' . $line . ';"><div style="font-size:12px;line-height:1.6;color:' . $soft . ';">'
         . e($brand)
         // Bulk mail carries the way out; a one-off confirmation (no link given) does not need one.
         . ($unsubUrl !== '' ? '<br>Do not want these emails? <a href="' . e($unsubUrl) . '" style="color:' . $soft . ';">Unsubscribe</a>.' : '')
         . '</div></td></tr></table></td></tr></table></body></html>';
}

/**
 * The records a send would reach, each with its address, and why the rest
 * are left out. $recs are already held to what the viewer may see.
 * Returns ['to' => [rec...], 'skipped' => [reason => n]].
 */
function fabo_crm_mail_audience(array $board, array $fields, array $recs, string $fieldKey,
                                int $templateId, bool $skipSent): array
{
    fabo_crm_mail_install();
    $org  = (int) $board['client_id'];
    $seen = [];
    $to   = [];
    $skip = [];
    // Already sent: by record, and by address -- two records for one person are still one person.
    $already = $alreadyTo = [];
    if ($skipSent && $templateId > 0 && $recs) {
        foreach (fabo_all('SELECT DISTINCT q.record_id, q.to_email FROM crm_mail_queue q JOIN crm_mail_sends s ON s.id = q.send_id
                            WHERE s.crm_id = ? AND s.template_id = ? AND q.state IN ("waiting","sent")',
                          'ii', [(int) $board['id'], $templateId]) as $r) {
            $already[(int) $r['record_id']] = true;
            $alreadyTo[mb_strtolower((string) $r['to_email'])] = true;
        }
    }
    $out = [];
    foreach (fabo_all('SELECT email FROM crm_mail_optout WHERE org_id = ?', 'i', [$org]) as $o) {
        $out[$o['email']] = true;
    }
    foreach ($recs as $r) {
        $email = fabo_crm_mail_address($fields, $r, $fieldKey);
        $low   = mb_strtolower($email);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $skip['no email address'] = ($skip['no email address'] ?? 0) + 1;
        } elseif (isset($out[$low])) {
            $skip['unsubscribed'] = ($skip['unsubscribed'] ?? 0) + 1;
        } elseif (isset($already[(int) $r['id']]) || isset($alreadyTo[$low])) {
            $skip['already sent this template'] = ($skip['already sent this template'] ?? 0) + 1;
        } elseif (isset($seen[$low])) {
            $skip['same address twice'] = ($skip['same address twice'] ?? 0) + 1;
        } else {
            $seen[$low] = true;
            $r['_email'] = $email;
            $to[] = $r;
        }
    }
    return ['to' => $to, 'skipped' => $skip];
}

/**
 * Queues one personalised message per record. Returns [sendId, queued, skipped map].
 * Nothing is sent here: the queue drains by itself (fabo_crm_mail_tick).
 */
function fabo_crm_mail_queue_send(array $board, array $fields, array $viewer, array $recs, array $in): array
{
    fabo_crm_mail_install();
    $cid     = (int) $board['id'];
    $subject = trim((string) ($in['subject'] ?? ''));
    $body    = trim(str_replace("\r\n", "\n", (string) ($in['body'] ?? '')));
    if ($subject === '' || $body === '') {
        throw new InvalidArgumentException('Write a subject and a message first.');
    }
    $tpl = fabo_crm_mail_template($cid, (int) ($in['tpl'] ?? 0));
    $fieldKey = (string) ($in['email_field'] ?? '');
    if ($fieldKey !== '' && !isset(fabo_crm_mail_fields($fields)[$fieldKey])) {
        $fieldKey = '';
    }
    $aud = fabo_crm_mail_audience($board, $fields, $recs, $fieldKey, $tpl ? (int) $tpl['id'] : 0,
                                  !empty($in['skip_sent']));
    if (!$aud['to']) {
        throw new InvalidArgumentException('Nobody to send to: none of these records has a usable email address'
            . ($aud['skipped'] ? ' (' . fabo_crm_mail_skip_words($aud['skipped']) . ')' : '') . '.');
    }
    $actor = fabo_crm_mail_actor($viewer);
    $brand = fabo_crm_mail_brand($actor);
    $stage = (int) ($in['stage'] ?? 0);

    $db = fabo_db();
    $db->begin_transaction();
    try {
        $sendId = (int) fabo_exec('INSERT INTO crm_mail_sends (crm_id, template_id, stage_id, name, subject, who, sender_id, total, skipped)
                                   VALUES (?,?,?,?,?,?,?,?,?)', 'iiisssiii',
            [$cid, $tpl ? (int) $tpl['id'] : null, $stage > 0 ? $stage : null,
             mb_substr($tpl['name'] ?? 'One-off email', 0, 120), mb_substr($subject, 0, 200),
             mb_substr(fabo_crm_who($viewer), 0, 160), (int) ($actor['id'] ?? 0), count($aud['to']),
             array_sum($aud['skipped'])]);
        foreach ($aud['to'] as $r) {
            $vals = fabo_crm_mail_values($board, $fields, $r, $brand);
            $subj = fabo_crm_mail_merge($subject, $vals);
            $text = fabo_crm_mail_merge($body, $vals);
            $qid  = (int) fabo_exec('INSERT INTO crm_mail_queue (send_id, crm_id, record_id, to_email, subject)
                                     VALUES (?,?,?,?,?)', 'iiiss',
                                    [$sendId, $cid, (int) $r['id'], $r['_email'], mb_substr($subj, 0, 255)]);
            // The unsubscribe link needs the queue id, so the bodies go in second.
            $unsub = fabo_crm_mail_unsub_url($qid);
            fabo_exec('UPDATE crm_mail_queue SET body_text = ?, body_html = ? WHERE id = ?', 'ssi',
                      [$text . "\n\n--\n" . $brand . "\nUnsubscribe: " . $unsub,
                       fabo_crm_mail_html($brand, $subj, $text, $unsub), $qid]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
    return [$sendId, count($aud['to']), $aud['skipped']];
}

/** "3 with no email address, 1 unsubscribed" */
function fabo_crm_mail_skip_words(array $skipped): string
{
    $bits = [];
    foreach ($skipped as $why => $n) {
        $bits[] = $n . ' ' . ($why === 'no email address' ? 'with no email address' : $why);
    }
    return implode(', ', $bits);
}

/**
 * How many more bulk messages may go this hour, leaving room for the
 * portal's own mail. Null when no hourly limit is set.
 */
function fabo_crm_mail_room(): ?int
{
    $cap = defined('MAIL_PER_HOUR') ? (int) MAIL_PER_HOUR : 0;
    if ($cap <= 0) {
        return null;
    }
    return max(0, $cap - FABO_CRM_MAIL_RESERVE - fabo_outbox_sent_this_hour());
}

/**
 * A refusal that means "not now". The outbox's rule, plus the wording
 * providers use for a spent quota -- Gmail's "5.4.5 Daily user sending limit
 * exceeded" is a 5xx, but tomorrow it goes through, so it must not burn
 * every message's tries tonight.
 */
function fabo_crm_mail_temporary(string $why): bool
{
    return fabo_outbox_is_temporary($why) || (bool) preg_match('~quota|limit exceeded|5\.4\.5|sending limit~i', $why);
}

/** The mail client's own words for "could not get through to the server at all". */
function fabo_crm_mail_cant_connect(string $why): bool
{
    return (bool) preg_match('~could not reach|refused the connection|rejected the greeting|would not start TLS|'
                           . 'TLS handshake|would not accept a login|connection to the mail server is not open|'
                           . 'no mail server is configured~i', $why);
}

/** Why nothing is going right now, in words for the page. */
function fabo_crm_mail_pause_words(): string
{
    if (fabo_outbox_waiting(null, 1)) {
        return 'Paused while the portal sends its own emails (sign-in links and notices) first.';
    }
    if (fabo_crm_mail_backing_off()) {
        return 'The mail server asked us to slow down; sending carries on by itself in a few minutes.';
    }
    if (fabo_crm_mail_room() === 0) {
        return 'This hour’s sending allowance is used up; the rest go out as the hour rolls on.';
    }
    return 'Waiting for the next batch.';
}

/** Is the mail server asking us to slow down right now? */
function fabo_crm_mail_backing_off(): bool
{
    require_once __DIR__ . '/google.php';
    $at = fabo_setting('mail_limited_at', '');
    return $at !== '' && (time() - strtotime($at)) < 300;
}

/**
 * Sends the next few waiting messages. $crmId limits it to one CRM (the page
 * a person is watching); 0 takes any. Returns how many went.
 *
 * Holds back while the portal's own outbox has anything waiting, while the
 * hour's allowance is spent, and for five minutes after the server says
 * "slow down". One SMTP connection per sender per batch.
 */
function fabo_crm_mail_tick(int $max = 10, int $crmId = 0): int
{
    fabo_crm_mail_install();
    try {
        if (fabo_outbox_waiting(null, 1) || fabo_crm_mail_backing_off()) {
            return 0;
        }
        $room = fabo_crm_mail_room();
        if ($room !== null) {
            $max = min($max, $room);
        }
        if ($max <= 0) {
            return 0;
        }
        // A record deleted since it was queued is not written to.
        fabo_exec('UPDATE crm_mail_queue q LEFT JOIN crm_records r ON r.id = q.record_id
                      SET q.state = "cancelled", q.last_error = "record deleted"
                    WHERE q.state = "waiting" AND r.id IS NULL');
        $rows = $crmId > 0
            ? fabo_all('SELECT q.*, s.sender_id FROM crm_mail_queue q JOIN crm_mail_sends s ON s.id = q.send_id
                         WHERE q.state = "waiting" AND q.crm_id = ? ORDER BY q.id LIMIT ' . (int) $max, 'i', [$crmId])
            : fabo_all('SELECT q.*, s.sender_id FROM crm_mail_queue q JOIN crm_mail_sends s ON s.id = q.send_id
                         WHERE q.state = "waiting" ORDER BY q.id LIMIT ' . (int) $max);
        if (!$rows) {
            return 0;
        }
        $bySender = [];
        foreach ($rows as $r) {
            $bySender[(int) $r['sender_id']][(int) $r['id']] = $r;
        }
        $sent = 0;
        foreach ($bySender as $senderId => $batch) {
            $actor = $senderId > 0 ? fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [$senderId]) : null;
            $msgs = [];
            foreach ($batch as $id => $r) {
                $msgs[$id] = ['to' => $r['to_email'], 'subject' => $r['subject'],
                              'body' => (string) $r['body_text'], 'html' => (string) $r['body_html']];
            }
            fabo_outbox_touch();
            $results = fabo_smtp_send_many($msgs, fabo_sender_for($actor));
            /* The whole batch failing for one reason is the server, not the
               messages: it could not be reached, or would not let us in.
               That is waited out like a rate limit rather than counted as a
               failed try against every message in it. */
            $whys = [];
            foreach ($batch as $id => $r) {
                if (!($results[$id][0] ?? false)) {
                    $whys[(string) ($results[$id][1] ?? '')] = true;
                }
            }
            $why1 = (string) array_key_first($whys);
            $serverDown = count($whys) === 1 && count($batch) === count(array_filter($results, static fn($x) => !$x[0]))
                       && (count($batch) > 1 || fabo_crm_mail_cant_connect($why1))
                       && $why1 !== 'no valid email address on file';
            $stop = false;
            foreach ($batch as $id => $r) {
                [$ok, $why] = $results[$id] ?? [false, 'not attempted'];
                if ($ok) {
                    fabo_exec('UPDATE crm_mail_queue SET state = "sent", sent_at = NOW(), tries = tries + 1, last_error = ""
                               WHERE id = ?', 'i', [$id]);
                    // Counted in the outbox too, so the hourly pacing sees bulk mail as well.
                    fabo_exec('INSERT INTO mail_outbox (client_id, kind, to_email, subject, body_text, body_html,
                                 tries, last_error, last_try_at, sent_at, state)
                               VALUES (NULL,"crm-bulk",?,?,"","",1,"",NOW(),NOW(),"sent")', 'ss',
                              [$r['to_email'], mb_substr((string) $r['subject'], 0, 255)]);
                    fabo_crm_log((int) $r['crm_id'], (int) $r['record_id'], 'Bulk email', 'email',
                                 'Sent “' . $r['subject'] . '” to ' . $r['to_email'] . '.');
                    $sent++;
                } elseif ($serverDown || fabo_crm_mail_temporary($why)) {
                    // Left waiting, untouched: it goes when the server lets us.
                    fabo_exec('UPDATE crm_mail_queue SET last_error = ? WHERE id = ?', 'si', [mb_substr($why, 0, 255), $id]);
                    $stop = true;
                } else {
                    // SET runs left to right, so the IF below already sees this attempt counted.
                    fabo_exec('UPDATE crm_mail_queue SET tries = tries + 1, last_error = ?,
                                      state = IF(tries >= 3, "failed", "waiting") WHERE id = ?',
                              'si', [mb_substr($why, 0, 255), $id]);
                }
            }
            if ($stop) {
                fabo_outbox_cool();
                break;
            }
        }
        return $sent;
    } catch (Throwable $e) {
        error_log('FABO crm mail tick: ' . $e->getMessage());
        return 0;
    }
}

/**
 * A few queued messages on an ordinary page load, at most once a minute
 * across the portal -- so bulk mail keeps moving where no cron job runs.
 * Costs one indexed query when nothing is waiting.
 */
function fabo_crm_mail_nudge(): void
{
    static $ran = false;
    if ($ran) {
        return;
    }
    $ran = true;
    try {
        $db = fabo_db();
        $res = $db->query("SHOW TABLES LIKE 'crm_mail_queue'");
        if (!$res || !$res->num_rows
            || !fabo_one('SELECT 1 AS x FROM crm_mail_queue WHERE state = "waiting" LIMIT 1')) {
            return;
        }
        require_once __DIR__ . '/google.php';
        $last = fabo_setting('crm_mail_nudge', '');
        if ($last !== '' && time() - strtotime($last) < 60) {
            return;
        }
        fabo_setting_put('crm_mail_nudge', date('Y-m-d H:i:s'));
        fabo_crm_mail_tick(3);
    } catch (Throwable $e) {
        error_log('FABO crm mail nudge: ' . $e->getMessage());
    }
}

/** Recent sends on a CRM, with how each is getting on. */
function fabo_crm_mail_sends(int $crmId, int $limit = 20): array
{
    fabo_crm_mail_install();
    $rows = fabo_all('SELECT s.*, st.name AS stage_name,
                             SUM(q.state = "sent") AS n_sent, SUM(q.state = "waiting") AS n_waiting,
                             SUM(q.state = "failed") AS n_failed, SUM(q.state IN ("skipped","cancelled")) AS n_dropped
                        FROM crm_mail_sends s
                        LEFT JOIN crm_mail_queue q ON q.send_id = s.id
                        LEFT JOIN crm_stages st ON st.id = s.stage_id
                       WHERE s.crm_id = ? GROUP BY s.id ORDER BY s.id DESC LIMIT ' . (int) $limit, 'i', [$crmId]);
    return $rows;
}

function fabo_crm_mail_waiting(int $crmId): int
{
    fabo_crm_mail_install();
    return (int) (fabo_one('SELECT COUNT(*) AS n FROM crm_mail_queue WHERE crm_id = ? AND state = "waiting"', 'i',
                           [$crmId])['n'] ?? 0);
}

/** Stops whatever of one send has not gone yet. Returns how many were stopped. */
function fabo_crm_mail_cancel(int $crmId, int $sendId): int
{
    fabo_crm_mail_install();
    $n = fabo_exec('UPDATE crm_mail_queue SET state = "cancelled" WHERE send_id = ? AND crm_id = ? AND state = "waiting"',
                   'ii', [$sendId, $crmId]);
    fabo_exec('UPDATE crm_mail_sends SET cancelled_at = NOW() WHERE id = ? AND crm_id = ?', 'ii', [$sendId, $crmId]);
    return (int) $n;
}

/** Puts a send's failed messages back in the queue. */
function fabo_crm_mail_retry(int $crmId, int $sendId): int
{
    fabo_crm_mail_install();
    return (int) fabo_exec('UPDATE crm_mail_queue SET state = "waiting", tries = 0, last_error = ""
                             WHERE send_id = ? AND crm_id = ? AND state = "failed"', 'ii', [$sendId, $crmId]);
}

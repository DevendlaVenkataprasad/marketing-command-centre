<?php
/**
 * Incubation Management — Gems.
 *
 * A Gem is a saved Gemini set-up, like a Gem in gemini.google.com: a name,
 * instructions, and a weighted scoring rubric. Gems made in the Gemini app
 * cannot be called from outside it (there is no API for them), so the set-up
 * lives here and is sent to the Gemini API with each application.
 *
 * Each Gem has its own short form. Applicants are emailed a personal link to
 * it (inc_gem_requests.token); what they answer is scored by Gemini against
 * the rubric. The score is shown only at the pipeline stages the
 * administrator ticks for that Gem (inc_stages.gem_ids) — never at New.
 *
 * Gemini proposes a mark per criterion; the total is added up here, each
 * mark clamped to its weight, so a model that miscounts cannot produce a
 * score the rubric does not allow.
 */

require_once __DIR__ . '/apps.php';

/* ================================================================ schema */

/** Creates the Gem tables and the stage column. Called from inc_install(). */
function inc_gem_upgrade(): void
{
    $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $db = fabo_db();
    $db->query(inc_sql("CREATE TABLE IF NOT EXISTS inc_gems (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(120) NOT NULL,
      intro TEXT NULL,
      instructions TEXT NULL,
      criteria_json TEXT NULL,
      fields_json TEXT NULL,
      mail_subject VARCHAR(190) NULL,
      mail_body TEXT NULL,
      send_stage_id INT UNSIGNED NULL,
      invite_form_id INT UNSIGNED NULL,
      active TINYINT(1) NOT NULL DEFAULT 1,
      created_by VARCHAR(190) NOT NULL DEFAULT '',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) $t"));
    $db->query(inc_sql("CREATE TABLE IF NOT EXISTS inc_gem_requests (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      gem_id INT UNSIGNED NOT NULL,
      application_id INT UNSIGNED NOT NULL,
      email VARCHAR(190) NULL,
      token CHAR(32) NOT NULL,
      send_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
      sent_at DATETIME NULL,
      mail_status VARCHAR(20) NULL,
      mail_detail VARCHAR(500) NULL,
      submitted_at DATETIME NULL,
      answers_json MEDIUMTEXT NULL,
      score DECIMAL(6,2) NULL,
      breakdown_json TEXT NULL,
      summary TEXT NULL,
      scored_at DATETIME NULL,
      score_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
      score_error VARCHAR(500) NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY u_token (token),
      UNIQUE KEY u_gem_app (gem_id, application_id),
      KEY k_app (application_id)
    ) $t"));
    // Sent with the acknowledgement when a registration form is submitted (5).
    $g = inc_sql('inc_gems');
    if (!$db->query("SHOW COLUMNS FROM `$g` LIKE 'auto_on_submit'")->num_rows) {
        $db->query("ALTER TABLE `$g` ADD COLUMN auto_on_submit TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN auto_form_ids VARCHAR(255) NULL,
                     ADD COLUMN ack_subject VARCHAR(190) NULL, ADD COLUMN ack_body TEXT NULL");
    }
    // Which Gem (if any) a registration form sends with its acknowledgement (6). Carried over from the
    // Gem-side switch it replaces: a Gem that was on for every form (or some) becomes those forms' choice.
    $f = inc_sql('inc_forms');
    if (!$db->query("SHOW COLUMNS FROM `$f` LIKE 'gem_id'")->num_rows) {
        $db->query("ALTER TABLE `$f` ADD COLUMN gem_id INT UNSIGNED NULL");
        foreach (inc_all('SELECT id, auto_form_ids FROM inc_gems WHERE auto_on_submit = 1 ORDER BY id DESC') as $on) {
            $ids = array_filter(array_map('intval', explode(',', (string) $on['auto_form_ids'])));
            inc_exec('UPDATE inc_forms SET gem_id = ? WHERE id NOT IN (SELECT invite_form_id FROM inc_gems WHERE invite_form_id IS NOT NULL)'
                     . ($ids ? ' AND id IN (' . implode(',', $ids) . ')' : ''), 'i', [(int) $on['id']]);
        }
    }
    $rq = inc_sql('inc_gem_requests');
    if (!$db->query("SHOW COLUMNS FROM `$rq` LIKE 'scorecard_sent_at'")->num_rows) {
        $db->query("ALTER TABLE `$rq` ADD COLUMN scorecard_sent_at DATETIME NULL");
    }
    // What the applicant should improve, as a JSON list; shown below the score card.
    if (!$db->query("SHOW COLUMNS FROM `$rq` LIKE 'improvements'")->num_rows) {
        $db->query("ALTER TABLE `$rq` ADD COLUMN improvements TEXT NULL");
    }
    // When scoring was last tried, so a retry waits a few minutes between goes.
    if (!$db->query("SHOW COLUMNS FROM `$rq` LIKE 'score_tried_at'")->num_rows) {
        $db->query("ALTER TABLE `$rq` ADD COLUMN score_tried_at DATETIME NULL");
    }
    $tbl = inc_sql('inc_stages');
    if (!$db->query("SHOW COLUMNS FROM `$tbl` LIKE 'gem_ids'")->num_rows) {
        $db->query("ALTER TABLE `$tbl` ADD COLUMN gem_ids VARCHAR(255) NULL");
    }
}

/* ================================================================= reads */

function inc_gems(bool $activeOnly = false): array
{
    $out = [];
    foreach (inc_all('SELECT * FROM inc_gems' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY id') as $g) {
        $out[(int) $g['id']] = inc_gem_decode($g);
    }
    return $out;
}

function inc_gem(int $id): ?array
{
    $g = inc_one('SELECT * FROM inc_gems WHERE id = ?', 'i', [$id]);
    return $g ? inc_gem_decode($g) : null;
}

function inc_gem_decode(array $g): array
{
    $g['criteria'] = json_decode((string) $g['criteria_json'], true) ?: [];
    $g['fields'] = json_decode((string) $g['fields_json'], true) ?: [];
    $g['max'] = array_sum(array_map(static fn($c) => (float) $c['weight'], $g['criteria']));
    return $g;
}

/** The Gems whose score a pipeline stage shows. */
function inc_stage_gem_ids(?array $stage): array
{
    if (!$stage || trim((string) ($stage['gem_ids'] ?? '')) === '') {
        return [];
    }
    return array_values(array_filter(array_map('intval', explode(',', (string) $stage['gem_ids']))));
}

/** May an application sitting at $stageId show this Gem's score? */
function inc_gem_visible_at(int $gemId, int $stageId): bool
{
    static $cache = [];
    $k = inc_scope_key() . ':' . $stageId;
    if (!isset($cache[$k])) {
        $cache[$k] = inc_stage_gem_ids(inc_stage($stageId));
    }
    return in_array($gemId, $cache[$k], true);
}

/** The stage names that show a Gem's score, for the "shown at" hint. */
function inc_gem_stage_names(int $gemId): array
{
    $out = [];
    foreach (inc_stages('crm') as $s) {
        if (in_array($gemId, inc_stage_gem_ids($s), true)) {
            $out[] = $s['name'];
        }
    }
    return $out;
}

/** Every Gem request of an application, keyed by gem id. */
function inc_gem_requests_for_app(int $appId): array
{
    $out = [];
    foreach (inc_all('SELECT * FROM inc_gem_requests WHERE application_id = ?', 'i', [$appId]) as $r) {
        $out[(int) $r['gem_id']] = $r;
    }
    return $out;
}

/**
 * The Gem scores to show beside a list of applications: app id => [[gem, score], ...],
 * only those whose application sits at a stage that shows that Gem.
 */
function inc_gem_scores_for_rows(array $rows): array
{
    $ids = array_map(static fn($a) => (int) $a['id'], $rows);
    if (!$ids) {
        return [];
    }
    $stageOf = array_column($rows, 'stage_id', 'id');
    $gems = inc_gems();
    $out = [];
    try {
        $reqs = inc_all('SELECT application_id, gem_id, score FROM inc_gem_requests WHERE score IS NOT NULL AND application_id IN (' . implode(',', $ids) . ')');
    } catch (Throwable $e) {
        return [];
    }
    foreach ($reqs as $r) {
        $aid = (int) $r['application_id'];
        $gid = (int) $r['gem_id'];
        if (isset($gems[$gid]) && inc_gem_visible_at($gid, (int) ($stageOf[$aid] ?? 0))) {
            $out[$aid][] = [$gems[$gid], (float) $r['score']];
        }
    }
    return $out;
}

/** "72/100" with a tone, for lists. */
function inc_gem_score_html(array $gem, float $score): string
{
    $max = $gem['max'] ?: 100;
    $pct = $score * 100 / $max;
    $tone = $pct >= 70 ? 'green' : ($pct >= 50 ? 'amber' : 'red');
    return '<span class="inc-gemscore ' . $tone . '" title="' . e($gem['name']) . '">' . e(rtrim(rtrim(number_format($score, 1), '0'), '.'))
         . '<small>/' . e(rtrim(rtrim(number_format((float) $max, 1), '0'), '.')) . '</small></span>';
}

/* ================================================================ set-up */

/**
 * Saves a Gem from the settings form. Criteria arrive as text, one per line,
 * separated by "|": Name | weight | what it asks. Questions arrive as rows from
 * the question editor (q_label[], q_type[], q_req[], q_uid[]); the older text
 * form, Label | long or short | required or optional, is still read.
 */
function inc_gem_save(?int $id, array $in): int
{
    inc_need('configure');
    $name = inc_str($in['name'] ?? '', 120, true, 'Gem name');
    $criteria = inc_gem_parse_criteria((string) ($in['criteria'] ?? ''));
    $fields = isset($in['q_label']) && is_array($in['q_label'])
        ? inc_gem_fields_from_rows($in)
        : inc_gem_parse_fields((string) ($in['fields'] ?? ''));
    $stage = inc_int_or_null($in['send_stage_id'] ?? null);
    if ($stage && !isset(inc_stage_map('crm')[$stage])) {
        $stage = null;
    }
    $row = [$name, inc_str($in['intro'] ?? '', 4000), inc_str($in['instructions'] ?? '', 8000),
            json_encode($criteria, JSON_UNESCAPED_UNICODE), json_encode($fields, JSON_UNESCAPED_UNICODE),
            inc_str($in['mail_subject'] ?? '', 190) ?: 'Please complete: {gem}', inc_str($in['mail_body'] ?? '', 4000) ?: inc_gem_default_mail(),
            $stage, !empty($in['active']) ? 1 : 0,
            inc_str($in['ack_subject'] ?? '', 190) ?: null, inc_str($in['ack_body'] ?? '', 4000) ?: null];
    if ($id) {
        if (!inc_gem($id)) {
            throw new InvalidArgumentException('That Gem no longer exists.');
        }
        inc_exec('UPDATE inc_gems SET name = ?, intro = ?, instructions = ?, criteria_json = ?, fields_json = ?, mail_subject = ?, mail_body = ?, send_stage_id = ?, active = ?,
                         ack_subject = ?, ack_body = ? WHERE id = ?', 'sssssssiissi', array_merge($row, [$id]));
        inc_audit('update', 'gem', $id, $name);
        return $id;
    }
    $id = inc_exec('INSERT INTO inc_gems (name, intro, instructions, criteria_json, fields_json, mail_subject, mail_body, send_stage_id, active, ack_subject, ack_body, created_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', 'sssssssiisss', array_merge($row, [inc_actor()]));
    inc_audit('create', 'gem', $id, $name);
    return $id;
}

function inc_gem_parse_criteria(string $text): array
{
    $out = [];
    foreach (preg_split('/\R/', $text) as $line) {
        if (trim($line) === '') {
            continue;
        }
        $p = array_map('trim', explode('|', $line));
        $p[0] = preg_replace('/^\d+[.)]?\s+/', '', $p[0]);       // "1. Problem clarity" -> "Problem clarity"
        $w = (float) ($p[1] ?? 0);
        if ($p[0] === '' || $w <= 0) {
            throw new InvalidArgumentException('Each criterion needs a name and a weight above 0: "' . mb_substr($line, 0, 60) . '".');
        }
        $out[] = ['name' => mb_substr($p[0], 0, 120), 'weight' => $w, 'asks' => mb_substr($p[2] ?? '', 0, 500)];
    }
    if (!$out) {
        throw new InvalidArgumentException('Add at least one scoring criterion.');
    }
    return $out;
}

function inc_gem_parse_fields(string $text): array
{
    $out = [];
    $seen = [];
    foreach (preg_split('/\R/', $text) as $line) {
        if (trim($line) === '') {
            continue;
        }
        $p = array_map('trim', explode('|', $line));
        if (count($p) === 1) {
            // "Label — long — required" typed with dashes instead of bars.
            [$p[0], $t, $r] = inc_gem_split_spec($p[0]);
            $p[1] = $t ?? 'long';
            $p[2] = $r ?? 'required';
        }
        if ($p[0] === '') {
            continue;
        }
        $out[] = ['uid' => inc_gem_field_uid($p[0], '', $seen, count($out)), 'label' => mb_substr($p[0], 0, 160),
                  'type' => stripos($p[1] ?? 'long', 'short') === 0 ? 'short' : 'long',
                  'required' => stripos($p[2] ?? 'required', 'opt') !== 0];
    }
    if (!$out) {
        throw new InvalidArgumentException('Add at least one question for applicants.');
    }
    return $out;
}

/**
 * Questions from the editor's rows, each with an optional description shown
 * under it on the form (and given to Gemini with the answer). A row keeps the uid it was given, so a
 * question that is reworded or moved is still the same question; a new row
 * gets one from its label. Rows with no label are dropped.
 */
function inc_gem_fields_from_rows(array $in): array
{
    $out = [];
    $seen = [];
    $types = (array) ($in['q_type'] ?? []);
    $req = (array) ($in['q_req'] ?? []);
    $uids = (array) ($in['q_uid'] ?? []);
    $helps = (array) ($in['q_help'] ?? []);
    foreach ((array) $in['q_label'] as $i => $label) {
        $label = trim(preg_replace('/\s+/', ' ', (string) $label));
        if ($label === '') {
            continue;
        }
        [$label, $t, $r] = inc_gem_split_spec($label);
        $type = (string) ($types[$i] ?? 'long') === 'short' ? 'short' : 'long';
        $out[] = ['uid' => inc_gem_field_uid($label, (string) ($uids[$i] ?? ''), $seen, count($out)),
                  'label' => mb_substr($label, 0, 160),
                  'help' => mb_substr(trim((string) ($helps[$i] ?? '')), 0, 1000),
                  'type' => $t !== null ? (stripos($t, 'short') === 0 ? 'short' : 'long') : $type,
                  'required' => $r !== null ? stripos($r, 'opt') !== 0 : !empty($req[$i])];
    }
    if (!$out) {
        throw new InvalidArgumentException('Add at least one question for applicants.');
    }
    return $out;
}

/**
 * Splits "Label — long — required" (any dash or comma) into its parts, so a
 * type or required word typed into the label never reaches the applicant.
 * Returns [label, type or null, required or null].
 */
function inc_gem_split_spec(string $label): array
{
    $type = $req = null;
    $sep = '(?:\s*[\x{2014}\x{2013}|]\s*|\s+-\s+)';
    if (preg_match('/^(.*?)' . $sep . '(long|short)(?:' . $sep . '(required|optional))?\s*$/iu', $label, $m) && trim($m[1]) !== '') {
        [$label, $type, $req] = [trim($m[1]), strtolower($m[2]), isset($m[3]) && $m[3] !== '' ? strtolower($m[3]) : null];
    } elseif (preg_match('/^(.*?)' . $sep . '(required|optional)\s*$/iu', $label, $m) && trim($m[1]) !== '') {
        [$label, $req] = [trim($m[1]), strtolower($m[2])];
    }
    return [$label, $type, $req];
}

/** A question's stable key: the one it already has, else one made from its label. */
function inc_gem_field_uid(string $label, string $keep, array &$seen, int $n): string
{
    $uid = preg_match('/^[a-z0-9_]{1,40}$/', $keep) ? $keep
         : substr(trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($label)), '_'), 0, 40);
    $base = $uid = $uid !== '' ? $uid : 'q' . ($n + 1);
    for ($i = 2; isset($seen[$uid]); $i++) {
        $uid = substr($base, 0, 36) . '_' . $i;
    }
    $seen[$uid] = true;
    return $uid;
}

function inc_gem_criteria_text(array $gem): string
{
    return implode("\n", array_map(static fn($c) => $c['name'] . ' | ' . rtrim(rtrim(number_format((float) $c['weight'], 2, '.', ''), '0'), '.') . ' | ' . $c['asks'], $gem['criteria']));
}

function inc_gem_fields_text(array $gem): string
{
    return implode("\n", array_map(static fn($f) => $f['label'] . ' | ' . $f['type'] . ' | ' . ($f['required'] ? 'required' : 'optional'), $gem['fields']));
}

function inc_gem_default_mail(): string
{
    return "Hello {name},\n\nThank you for your application{startup_line}. As the next step, please answer a few short questions about your idea for {gem}.\n\n"
         . "Open your form here:\n{link}\n\nThe link is personal to you. It takes about 10 minutes.\n\nRegards,\nThe Incubation Team";
}

/** Which stages show which Gems: saved from a stage's form in Settings. */
function inc_stage_gems_save(int $stageId, array $gemIds): void
{
    inc_need('configure');
    $valid = array_keys(inc_gems());
    $ids = array_values(array_intersect(array_map('intval', $gemIds), $valid));
    inc_exec('UPDATE inc_stages SET gem_ids = ? WHERE id = ?', 'si', [$ids ? implode(',', $ids) : null, $stageId]);
}

/* ====================================================== on registration */

/** The acknowledgement a Gem sends when a registration form comes in: thanks, the reference, and the Gem's link. */
function inc_gem_default_ack(): array
{
    return ['We received your application {ref} — next step: {gem}',
            "Hello {name},\n\nThank you for registering{startup_line}. Your application reference is {ref}.\n\n"
          . "As the next step, please answer a few short questions about your idea:\n{link}\n\n"
          . "The link is personal to you and takes about 10 minutes.\n\nRegards,\nThe Incubation Team"];
}

/**
 * Emails one application a Gem's form and moves it to the Gem's stage —
 * the work behind both the "Email Gem form" action and the acknowledgement
 * sent on registration. No permission check here: callers decide.
 * Returns [status, detail]; status 'skipped' when there is nothing to send.
 */
function inc_gem_deliver(array $gem, array $app, string $subjectTpl, string $bodyTpl, string $why): array
{
    $appId = (int) $app['id'];
    $req = inc_gem_request((int) $gem['id'], $appId);
    if ($req['submitted_at']) {
        return ['skipped', 'already answered'];
    }
    $to = trim((string) $app['applicant_email']);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['skipped', 'no valid email address'];
    }
    $ref = (string) ($app['ref'] ?: '#' . $appId);
    $vars = ['{name}' => $app['applicant_name'] ?: 'there', '{gem}' => $gem['name'], '{link}' => inc_gem_link($req),
             '{startup}' => $app['startup_name'], '{startup_line}' => $app['startup_name'] !== '' ? ' for ' . $app['startup_name'] : '', '{ref}' => $ref];
    [$status, $detail] = inc_send_email($to, strtr($subjectTpl, $vars), strtr($bodyTpl, $vars));
    inc_exec('UPDATE inc_gem_requests SET email = ?, send_count = send_count + 1, sent_at = NOW(), mail_status = ?, mail_detail = ? WHERE id = ?',
              'sssi', [$to, $status, mb_substr($detail, 0, 500) ?: null, (int) $req['id']]);
    if ($status !== 'sent' && $status !== 'queued') {
        return [$status, $detail];
    }
    inc_activity($appId, null, 'gem', $gem['name'] . ' form emailed to ' . $to . ($status === 'queued' ? ' (queued)' : '') . ' — ' . $why . '.');
    return [$status, $detail];
}

/**
 * Right after a registration form is submitted: the applicant's
 * acknowledgement. When the form is set to send a Gem (form builder → Form
 * settings), the acknowledgement carries that Gem's link; the application
 * moves to the Gem's stage once the applicant answers it. Otherwise it is the
 * plain "application received" note (Settings → Notifications →
 * Acknowledgement). Never throws.
 */
function inc_app_acknowledge(int $appId): void
{
    try {
        $app = inc_one('SELECT * FROM inc_applications WHERE id = ?', 'i', [$appId]);
        if (!$app) {
            return;
        }
        $form = inc_one('SELECT name, gem_id FROM inc_forms WHERE id = ?', 'i', [(int) $app['form_id']]);
        $gem = !empty($form['gem_id']) ? inc_gem((int) $form['gem_id']) : null;
        if ($gem && (int) $gem['active'] === 1) {
            [$d0, $d1] = inc_gem_default_ack();
            inc_gem_deliver($gem, $app, (string) ($gem['ack_subject'] ?: $d0), (string) ($gem['ack_body'] ?: $d1), 'acknowledgement on registration');
            return;
        }
        $t = inc_mail_template('acknowledgement');
        $to = trim((string) $app['applicant_email']);
        if (!$t['email_on'] || $to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $vars = ['ref' => (string) $app['ref'], 'applicant' => $app['applicant_name'] ?: 'there', 'startup' => $app['startup_name'] ?: '—',
                 'form' => (string) ($form['name'] ?? ''), 'date' => inc_fmt_datetime($app['submitted_at'])];
        [$status] = inc_send_email($to, inc_fill($t['subject'], $vars), inc_fill($t['body'], $vars));
        if ($status === 'sent' || $status === 'queued') {
            inc_activity($appId, null, 'email', 'Acknowledgement emailed to ' . $to . ($status === 'queued' ? ' (queued)' : '') . '.');
        }
    } catch (Throwable $e) {
        error_log('FABO incubation acknowledgement: ' . $e->getMessage());
    }
}

/**
 * A new organisation's Incubation Management starts with Gem 1, set up like
 * the administrator's (or the built-in one), on its own "Gem 1 Scoring"
 * stage, ready to be chosen in a form's settings. Runs once per organisation: a Gem
 * someone deletes later is not put back.
 */
function inc_gem_seed_default(): void
{
    if (inc_setting('_gem1_seeded') === '1') {
        return;
    }
    inc_exec("INSERT INTO inc_settings (k, v) VALUES ('_gem1_seeded', '1') ON DUPLICATE KEY UPDATE v = '1'");
    $GLOBALS['inc_settings_cache']['_gem1_seeded'] = '1';
    if (inc_one('SELECT id FROM inc_gems LIMIT 1')) {
        return;
    }
    // The administrator's own first Gem, read from the administrator's tables, so clients get what was set up there.
    $src = null;
    if (inc_org() > 0) {
        try {
            $src = fabo_one('SELECT * FROM inc_gems ORDER BY id LIMIT 1');
        } catch (Throwable $e) {
            $src = null;
        }
    }
    $t = inc_gem_template();
    [$ackS, $ackB] = inc_gem_default_ack();
    $stage = inc_stage_by_code('crm', 'gem1', false);
    if (!$stage) {
        $new = inc_stage_by_code('crm', 'new', false) ?: inc_first_stage('crm');
        inc_exec("UPDATE inc_stages SET sort = sort + 1 WHERE pipeline = 'crm' AND sort > ?", 'i', [(int) $new['sort']]);
        inc_exec("INSERT INTO inc_stages (pipeline, code, name, description, sort, active) VALUES ('crm','gem1','Gem 1 Scoring',?,?,1)", 'si',
                  ['Gem 1 form sent; its score shows here once the applicant answers.', (int) $new['sort'] + 1]);
        $stage = inc_stage_by_code('crm', 'gem1', false);
    }
    $gid = inc_exec('INSERT INTO inc_gems (name, intro, instructions, criteria_json, fields_json, mail_subject, mail_body, send_stage_id, active, ack_subject, ack_body, created_by)
                     VALUES (?,?,?,?,?,?,?,?,1,?,?,?)', 'sssssssisss',
                    [$src['name'] ?? $t['name'], $src['intro'] ?? $t['intro'], $src['instructions'] ?? $t['instructions'],
                     $src['criteria_json'] ?? json_encode(inc_gem_parse_criteria($t['criteria']), JSON_UNESCAPED_UNICODE),
                     $src['fields_json'] ?? json_encode(inc_gem_parse_fields($t['fields']), JSON_UNESCAPED_UNICODE),
                     $src['mail_subject'] ?? 'Next step for your idea: {gem}', $src['mail_body'] ?? inc_gem_default_mail(),
                     (int) $stage['id'], $src['ack_subject'] ?? $ackS, $src['ack_body'] ?? $ackB, 'setup']);
    inc_exec('UPDATE inc_stages SET gem_ids = ? WHERE id = ?', 'si', [(string) $gid, (int) $stage['id']]);
}

/** The built-in Gem 1: the polytechnic idea rubric. */
function inc_gem_template(): array
{
    return [
        'name' => 'Gem 1 – Idea Evaluation',
        'intro' => "Tell us about your idea in your own words. Answer each question in a few sentences — be specific about the problem, who has it, and what you will build.\nIt takes about 10 minutes.",
        'instructions' => "You are an experienced innovation-challenge evaluator for student teams from polytechnic (diploma) colleges in Andhra Pradesh, India.\n"
            . "Be fair and specific. Judge ideas for what diploma students can realistically build with polytechnic lab resources and a modest budget; do not expect revenue, patents or funding.\n"
            . "Reward clear, specific problems with real users, honest feasibility, and links to Andhra Pradesh priorities such as agriculture, aquaculture, MSMEs, rural health and water.\n"
            . "Penalise vague, generic or copied ideas, and claims the answers do not support. Answers may be in simple or imperfect English; judge the idea, not the language.",
        'criteria' => "Problem clarity and relevance | 20 | Is it a real, specific problem? Who suffers, how often, how badly?\n"
            . "Solution fit and logic | 15 | Does the solution actually address the stated problem, and does the mechanism make sense?\n"
            . "Originality | 15 | Is it different from the common ideas, or a meaningful twist on them?\n"
            . "Technical feasibility at diploma level | 15 | Can this team build a working prototype with polytechnic lab resources and a modest budget?\n"
            . "Impact and scale | 15 | How many people benefit, and does it connect to AP priorities like agriculture, aquaculture, MSMEs, rural health, and water?\n"
            . "User understanding | 10 | Do they know who will use it, and have they talked to any of them?\n"
            . "Sustainability and viability | 5 | Could it survive beyond the event through cost savings, a buyer, or a government or CSR adopter?\n"
            . "Prototype readiness | 5 | Is there a sketch, circuit, code, or model already, or just an idea?",
        'fields' => "Problem statement | long | required\nTarget users | long | required\nWhat are you developing in the prototype? | long | required\nUSP — what makes your idea different? | long | required",
    ];
}

/* ============================================================== sending */

/** The request for one application, created on first use. */
function inc_gem_request(int $gemId, int $appId): array
{
    $r = inc_one('SELECT * FROM inc_gem_requests WHERE gem_id = ? AND application_id = ?', 'ii', [$gemId, $appId]);
    if ($r) {
        return $r;
    }
    $app = inc_one('SELECT applicant_email FROM inc_applications WHERE id = ?', 'i', [$appId]);
    inc_exec('INSERT IGNORE INTO inc_gem_requests (gem_id, application_id, email, token) VALUES (?,?,?,?)', 'iiss',
              [$gemId, $appId, $app['applicant_email'] ?? null, bin2hex(random_bytes(16))]);
    return inc_one('SELECT * FROM inc_gem_requests WHERE gem_id = ? AND application_id = ?', 'ii', [$gemId, $appId]);
}

function inc_gem_link(array $req): string
{
    $o = (inc_org() > 0 ? '&o=' . inc_org() : '') . inc_kind_q();
    return rtrim(fabo_portal_url_safe(), '/') . '/incubation-gem.php?t=' . $req['token'] . $o;
}

/**
 * Emails a Gem's form to applications. An application that has already
 * answered is skipped; one that has not is sent its same link again.
 * Returns ['sent' => n, 'queued' => n, 'skipped' => [ref => reason], 'failed' => [ref => reason]].
 */
function inc_gem_send(int $gemId, array $appIds): array
{
    inc_need('edit');
    $gem = inc_gem($gemId);
    if (!$gem || (int) $gem['active'] !== 1) {
        throw new InvalidArgumentException('Choose an active Gem.');
    }
    $out = ['sent' => 0, 'queued' => 0, 'skipped' => [], 'failed' => []];
    foreach (array_unique(array_map('intval', $appIds)) as $appId) {
        $app = inc_one('SELECT * FROM inc_applications WHERE id = ?', 'i', [$appId]);
        if (!$app) {
            continue;
        }
        $ref = (string) ($app['ref'] ?: '#' . $appId);
        [$status, $detail] = inc_gem_deliver($gem, $app, (string) $gem['mail_subject'], (string) $gem['mail_body'], 'sent by ' . inc_actor());
        if ($status === 'sent' || $status === 'queued') {
            $out[$status]++;
        } elseif ($status === 'skipped') {
            $out['skipped'][$ref] = $detail;
        } else {
            $out['failed'][$ref] = $detail ?: $status;
        }
    }
    return $out;
}

/**
 * Emails the chosen applications a message written on the spot (the bulk
 * action "Send an email"). {name}, {startup} and {ref} are filled in per
 * person; each send is noted on the application's activity.
 */
function inc_apps_email(array $appIds, string $subject, string $body): array
{
    inc_need('edit');
    return inc_apps_email_send($appIds, $subject, $body);
}

/** The sending itself, once the caller has settled who may (inc_apps_email, inc_dept_email). $tag starts the activity line. */
function inc_apps_email_send(array $appIds, string $subject, string $body, string $tag = ''): array
{
    $subject = inc_str($subject, 190, true, 'Subject');
    $body = inc_str($body, 5000, true, 'Message');
    $out = ['sent' => 0, 'queued' => 0, 'skipped' => [], 'failed' => []];
    foreach (array_unique(array_map('intval', $appIds)) as $appId) {
        $app = inc_one('SELECT * FROM inc_applications WHERE id = ?', 'i', [$appId]);
        if (!$app) {
            continue;
        }
        $ref = (string) ($app['ref'] ?: '#' . $appId);
        $to = trim((string) $app['applicant_email']);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $out['skipped'][$ref] = 'no valid email address';
            continue;
        }
        $vars = ['{name}' => $app['applicant_name'] ?: 'there', '{startup}' => $app['startup_name'], '{ref}' => $ref];
        [$status, $detail] = inc_send_email($to, strtr($subject, $vars), strtr($body, $vars));
        if ($status === 'sent' || $status === 'queued') {
            $out[$status]++;
            inc_activity($appId, null, 'email', $tag . 'Emailed "' . mb_substr(strtr($subject, $vars), 0, 150) . '" to ' . $to . ($status === 'queued' ? ' (queued)' : '') . '.');
        } else {
            $out['failed'][$ref] = $detail ?: $status;
        }
    }
    return $out;
}

/** One line for a flash message after sending. */
function inc_gem_send_summary(array $r): string
{
    $msg = ($r['created'] ?? 0 ? $r['created'] . ' new application' . ($r['created'] === 1 ? '' : 's') . ' created. ' : '')
         . $r['sent'] . ' emailed' . ($r['queued'] ? ', ' . $r['queued'] . ' queued (sent as the hourly mail limit allows)' : '')
         . ', ' . count($r['skipped']) . ' skipped, ' . count($r['failed']) . ' failed.';
    $notes = array_slice(array_merge($r['failed'], $r['skipped']), 0, 4, true);
    if ($notes) {
        $msg .= ' ' . implode('; ', array_map(static fn($k, $v) => $k . ' — ' . $v, array_keys($notes), $notes)) . (count($r['failed']) + count($r['skipped']) > 4 ? '; …' : '');
    }
    return $msg;
}

/**
 * Invites people who have not applied yet: one application each (or their
 * existing one, matched by email), then the Gem's form by email.
 * $lines: "Name <email>", "email, Name" or a bare address, one per line.
 */
function inc_gem_invite(int $gemId, string $lines): array
{
    inc_need('edit');
    $gem = inc_gem($gemId);
    if (!$gem) {
        throw new InvalidArgumentException('Choose a Gem.');
    }
    $people = [];
    foreach (preg_split('/[\r\n;]+/', $lines) as $line) {
        $line = trim($line);
        if ($line === '' || !preg_match('/[A-Z0-9._%+\'-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $line, $m)) {
            continue;
        }
        $email = strtolower($m[0]);
        $name = trim(str_replace([$m[0], '<', '>', ',', '"'], ' ', $line));
        $people[$email] = preg_replace('/\s+/', ' ', $name) ?: ucwords(str_replace(['.', '_', '-'], ' ', explode('@', $email)[0]));
    }
    if (!$people) {
        throw new InvalidArgumentException('Paste at least one email address.');
    }
    if (count($people) > 500) {
        throw new InvalidArgumentException('Invite up to 500 people at a time.');
    }
    $ids = [];
    $created = 0;
    foreach ($people as $email => $name) {
        $have = inc_one('SELECT id FROM inc_applications WHERE applicant_email = ? ORDER BY id DESC LIMIT 1', 's', [$email]);
        if ($have) {
            $ids[] = (int) $have['id'];
            continue;
        }
        $ids[] = inc_gem_new_application($gem, $name, $email, '');
        $created++;
    }
    $r = inc_gem_send($gemId, $ids);
    $r['created'] = $created;
    return $r;
}

/** A bare application for someone invited to a Gem, filed under the Gem's own intake form. */
function inc_gem_new_application(array $gem, string $name, string $email, string $startup, ?string $actor = null): int
{
    [$formId, $versionId] = inc_gem_invite_form($gem);
    $stage = inc_first_stage('crm');
    $id = inc_exec('INSERT INTO inc_applications (form_id, form_version_id, purpose, channel, applicant_name, applicant_email, startup_name, stage_id, submitted_by)
                    VALUES (?,?,"other","admin",?,?,?,?,?)', 'iisssis', [$formId, $versionId, $name, $email, $startup, (int) $stage['id'], $actor ?? inc_actor()]);
    inc_exec('UPDATE inc_applications SET ref = ? WHERE id = ?', 'si',
              [inc_setting('ref_prefix') . '-' . date('Y') . '-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT), $id]);
    inc_activity($id, null, 'created', 'Added from the ' . $gem['name'] . ' invitation list.');
    return $id;
}

/** The form invited applications are filed under: one per Gem, created on first use, never public. */
function inc_gem_invite_form(array $gem): array
{
    $fid = (int) (inc_one('SELECT invite_form_id FROM inc_gems WHERE id = ?', 'i', [(int) $gem['id']])['invite_form_id'] ?? 0);
    if ($fid && ($v = inc_one('SELECT id FROM inc_form_versions WHERE form_id = ? AND status = "published" LIMIT 1', 'i', [$fid]))) {
        return [$fid, (int) $v['id']];
    }
    $fid = inc_exec('INSERT INTO inc_forms (name, purpose, description, public_token, created_by) VALUES (?, "other", ?, ?, ?)', 'ssss',
                    [mb_substr($gem['name'] . ' — invited applicants', 0, 160), 'Applications created when people are invited to ' . $gem['name'] . '. Not a public form.',
                     bin2hex(random_bytes(16)), inc_actor()]);
    $vid = inc_exec("INSERT INTO inc_form_versions (form_id, version_no, status, created_by, published_at, published_by) VALUES (?,1,'published',?,NOW(),?)",
                    'iss', [$fid, inc_actor(), inc_actor()]);
    inc_exec('UPDATE inc_gems SET invite_form_id = ? WHERE id = ?', 'ii', [$fid, (int) $gem['id']]);
    return [$fid, $vid];
}

/* ========================================================== answering */

function inc_gem_request_by_token(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        return null;
    }
    return inc_one('SELECT r.*, a.applicant_name, a.startup_name, a.ref FROM inc_gem_requests r JOIN inc_applications a ON a.id = r.application_id WHERE r.token = ?', 's', [$token]);
}

/** Saves an applicant's answers. Throws InvalidArgumentException with field errors as JSON. */
function inc_gem_submit(array $req, array $gem, array $post): void
{
    $answers = [];
    $errors = [];
    foreach ($gem['fields'] as $f) {
        $v = trim((string) ($post[$f['uid']] ?? ''));
        $v = mb_substr($v, 0, $f['type'] === 'short' ? 300 : 5000);
        if ($f['required'] && $v === '') {
            $errors[$f['uid']] = 'Please answer this question.';
        }
        $answers[] = ['uid' => $f['uid'], 'label' => $f['label'], 'help' => (string) ($f['help'] ?? ''), 'value' => $v];
    }
    if ($errors) {
        throw new InvalidArgumentException(json_encode($errors));
    }
    $n = inc_exec('UPDATE inc_gem_requests SET answers_json = ?, submitted_at = NOW(), score = NULL, breakdown_json = NULL, summary = NULL, improvements = NULL, scored_at = NULL, score_attempts = 0, score_error = NULL
                   WHERE id = ? AND submitted_at IS NULL', 'si', [json_encode($answers, JSON_UNESCAPED_UNICODE), (int) $req['id']]);
    if ($n) {
        inc_activity((int) $req['application_id'], null, 'gem', 'Answered the ' . $gem['name'] . ' form.');
        // Answered: on to the Gem's stage, where it is scored and ranked.
        if ($gem['send_stage_id']) {
            inc_app_system_move((int) $req['application_id'], (int) $gem['send_stage_id'], 'Answered the ' . $gem['name'] . ' form');
        }
    }
}

/* =========================================================== score cards */

function inc_gem_default_scorecard(): array
{
    return ['Your {gem} score card — {ref}',
            "Hello {name},\n\nThank you for completing {gem}{startup_line}. Here is how your idea was scored, with the reason for each part, "
          . "followed by what you can improve next. We hope it helps you sharpen the idea.\n\n{scorecard}\n\nRegards,\nThe Incubation Team"];
}

/** Score, summary and every criterion with its reason — as [plain text, HTML] for one email. The card goes where {scorecard} is in the message, else after it. */
function inc_gem_scorecard_body(array $gem, array $req, string $intro): array
{
    require_once __DIR__ . '/../mailtpl.php';
    $fmt = static fn($n) => rtrim(rtrim(number_format((float) $n, 1), '0'), '.');
    $rows = json_decode((string) $req['breakdown_json'], true) ?: [];
    $total = $fmt($req['score']) . ' / ' . $fmt($gem['max']);
    [$before, $after] = array_pad(explode('{scorecard}', $intro, 2), 2, '');
    $before = rtrim($before);
    $after = trim($after);
    $text = $before . "\n\n" . $gem['name'] . ": " . $total . "\n";
    if (trim((string) $req['summary']) !== '') {
        $text .= "\n" . trim((string) $req['summary']) . "\n";
    }
    foreach ($rows as $b) {
        $text .= "\n" . $b['name'] . ': ' . $fmt($b['score']) . ' / ' . $fmt($b['max']) . "\n   " . $b['reason'];
    }
    $td = 'padding:10px 12px;border-bottom:1px solid #e6e9ef;font-size:14px;line-height:1.5;vertical-align:top;color:#1b2230;';
    $html = fabo_mail_p(nl2br(e($before)))
          . '<div style="margin:6px 0 16px;padding:14px 18px;background:#f3f6fc;border-radius:10px;">'
          . '<div style="font-size:13px;color:#5f6979;">' . e($gem['name']) . '</div>'
          . '<div style="font-size:30px;font-weight:700;color:#1b2230;">' . e($fmt($req['score'])) . '<span style="font-size:15px;color:#8a93a3;"> / ' . e($fmt($gem['max'])) . '</span></div></div>'
          . (trim((string) $req['summary']) !== '' ? fabo_mail_p(nl2br(e(trim((string) $req['summary'])))) : '')
          . '<table role="presentation" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;margin:4px 0 8px;">'
          . '<tr><th style="' . $td . 'text-align:left;font-size:12px;color:#5f6979;">CRITERION</th><th style="' . $td . 'text-align:right;font-size:12px;color:#5f6979;white-space:nowrap;">SCORE</th>'
          . '<th style="' . $td . 'text-align:left;font-size:12px;color:#5f6979;">WHY</th></tr>';
    foreach ($rows as $b) {
        $html .= '<tr><td style="' . $td . 'font-weight:600;">' . e($b['name']) . '</td><td style="' . $td . 'text-align:right;white-space:nowrap;">'
               . e($fmt($b['score'])) . ' / ' . e($fmt($b['max'])) . '</td><td style="' . $td . 'font-size:13px;color:#4a5363;">' . e($b['reason']) . '</td></tr>';
    }
    $html .= '</table>';
    // What to improve, right below the card.
    if ($improve = inc_gem_improvements($req)) {
        $text .= "\n\nWHAT TO IMPROVE IN YOUR IDEA\n";
        $items = '';
        foreach ($improve as $i => $p) {
            $text .= ($i + 1) . '. ' . $p . "\n";
            $items .= '<li style="margin:0 0 8px;">' . e($p) . '</li>';
        }
        $html .= '<div style="margin:16px 0 8px;padding:14px 18px;background:#fff8ec;border-left:4px solid #e0a43a;border-radius:8px;">'
               . '<div style="font-size:15px;font-weight:700;color:#1b2230;margin:0 0 8px;">What to improve in your idea</div>'
               . '<ol style="margin:0;padding-left:20px;font-size:14px;line-height:1.55;color:#1b2230;">' . $items . '</ol></div>';
    }
    if ($after !== '') {
        $text .= "\n\n" . $after;
    }
    return [$text, $html . ($after !== '' ? fabo_mail_p(nl2br(e($after)), 'margin-top:16px;') : '')];
}

/**
 * Emails each chosen applicant their score card for one Gem (the bulk
 * action "Email score card"). Only answered and scored applications get one;
 * the rest are skipped with the reason. Each send is noted on the request
 * and the application's activity.
 */
function inc_gem_scorecard_send(int $gemId, array $appIds, string $subject, string $intro): array
{
    inc_need('edit');
    $gem = inc_gem($gemId);
    if (!$gem) {
        throw new InvalidArgumentException('Choose a Gem.');
    }
    [$dS, $dI] = inc_gem_default_scorecard();
    $subject = inc_str($subject, 190) ?: $dS;
    $intro = inc_str($intro, 4000) ?: $dI;
    $out = ['sent' => 0, 'queued' => 0, 'skipped' => [], 'failed' => []];
    foreach (array_unique(array_map('intval', $appIds)) as $appId) {
        $app = inc_one('SELECT * FROM inc_applications WHERE id = ?', 'i', [$appId]);
        if (!$app) {
            continue;
        }
        $ref = (string) ($app['ref'] ?: '#' . $appId);
        $req = inc_one('SELECT * FROM inc_gem_requests WHERE gem_id = ? AND application_id = ?', 'ii', [$gemId, $appId]);
        if (!$req || $req['score'] === null) {
            $out['skipped'][$ref] = !$req || !$req['submitted_at'] ? 'has not answered ' . $gem['name'] : 'not scored yet';
            continue;
        }
        $to = trim((string) $app['applicant_email']);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $out['skipped'][$ref] = 'no valid email address';
            continue;
        }
        // Scored before improvement points were asked for: add them now (the score stays as it is).
        // A card without them is not sent: it is skipped, to be sent again once Gemini answers.
        if (!inc_gem_improvements($req)) {
            [$got, $points] = inc_gem_improve((int) $req['id']);
            if (!$got) {
                $out['skipped'][$ref] = 'the "What to improve" points could not be prepared (' . mb_substr((string) $points, 0, 120) . '); try again later';
                continue;
            }
            $req['improvements'] = json_encode($points, JSON_UNESCAPED_UNICODE);
        }
        $vars = ['{name}' => $app['applicant_name'] ?: 'there', '{gem}' => $gem['name'], '{startup}' => $app['startup_name'],
                 '{startup_line}' => $app['startup_name'] !== '' ? ' for ' . $app['startup_name'] : '', '{ref}' => $ref];
        [$text, $html] = inc_gem_scorecard_body($gem, $req, strtr($intro, $vars));
        [$status, $detail] = inc_send_email($to, strtr($subject, $vars), $text, $html);
        if ($status === 'sent' || $status === 'queued') {
            $out[$status]++;
            inc_exec('UPDATE inc_gem_requests SET scorecard_sent_at = NOW() WHERE id = ?', 'i', [(int) $req['id']]);
            inc_activity($appId, null, 'gem', $gem['name'] . ' score card emailed to ' . $to . ($status === 'queued' ? ' (queued)' : '') . '.');
        } else {
            $out['failed'][$ref] = $detail ?: $status;
        }
    }
    return $out;
}

/* ============================================================== scoring */

/**
 * Asks Gemini to score one answered request against its Gem's rubric, and
 * saves the result. Returns [ok, message].
 */
function inc_gem_score(int $requestId): array
{
    require_once __DIR__ . '/../google.php';
    $req = inc_one('SELECT r.*, a.applicant_name, a.startup_name FROM inc_gem_requests r JOIN inc_applications a ON a.id = r.application_id WHERE r.id = ?', 'i', [$requestId]);
    if (!$req || !$req['submitted_at']) {
        return [false, 'There are no answers to score yet.'];
    }
    $gem = inc_gem((int) $req['gem_id']);
    if (!$gem || !$gem['criteria']) {
        return [false, 'The Gem has no scoring criteria.'];
    }
    $key = trim(fabo_env_or('GEMINI_API_KEY'));
    if ($key === '') {
        return [false, 'No Gemini API key is set on this server.'];
    }
    inc_exec('UPDATE inc_gem_requests SET score_tried_at = NOW() WHERE id = ?', 'i', [$requestId]);

    $rubric = '';
    foreach ($gem['criteria'] as $i => $c) {
        $rubric .= ($i + 1) . '. ' . $c['name'] . ' — maximum ' . $c['weight'] . ' points. ' . $c['asks'] . "\n";
    }
    $answers = '';
    foreach (json_decode((string) $req['answers_json'], true) ?: [] as $a) {
        $help = trim((string) ($a['help'] ?? ''));
        $answers .= '### ' . $a['label'] . "\n" . ($help !== '' ? 'Question asked: ' . $help . "\nAnswer:\n" : '') . ($a['value'] !== '' ? $a['value'] : '(no answer)') . "\n\n";
    }
    $prompt = trim((string) $gem['instructions']) . "\n\n"
            . "Score the application below against this rubric. Give each criterion a whole or half-point mark from 0 up to its maximum, "
            . "and a one or two sentence reason that cites what the applicant wrote. Judge only what is written; do not invent facts. "
            . "A missing or vague answer earns a low mark for the criteria it affects.\n\nRUBRIC (total " . $gem['max'] . " points)\n" . $rubric
            . "\nAPPLICATION" . ($req['startup_name'] !== '' ? ' — ' . $req['startup_name'] : '') . "\n" . $answers
            . "Reply with JSON only, in this shape:\n"
            . '{"criteria":[{"n":1,"score":0,"reason":""}],"summary":"three sentences: strengths, weaknesses, recommendation",'
            . '"improvements":["one specific thing to improve"]}'
            . "\nInclude every criterion, numbered as in the rubric. " . inc_gem_improve_rule();

    // Gemini being busy, hung or over quota is not the answers' fault and is retried without
    // limit; only a reply that cannot be used counts towards the five tries.
    $fail = static function (string $why, bool $counts = true) use ($requestId): array {
        inc_exec('UPDATE inc_gem_requests SET score_error = ?, score_attempts = score_attempts + ? WHERE id = ?', 'sii', [mb_substr($why, 0, 500), $counts ? 1 : 0, $requestId]);
        return [false, $why];
    };
    [$ok, $json] = inc_gem_gemini($key, $prompt);
    if (!$ok) {
        return $fail($json, false);
    }
    if (empty($json['criteria']) || !is_array($json['criteria'])) {
        return $fail('Gemini\'s reply could not be read as a score.');
    }
    $byN = [];
    foreach ($json['criteria'] as $k => $c) {
        $byN[(int) ($c['n'] ?? $k + 1)] = $c;
    }
    $breakdown = [];
    $total = 0.0;
    foreach ($gem['criteria'] as $i => $c) {
        $g = $byN[$i + 1] ?? null;
        if ($g === null || !is_numeric($g['score'] ?? null)) {
            return $fail('Gemini left out "' . $c['name'] . '".');
        }
        $s = max(0.0, min((float) $c['weight'], round((float) $g['score'] * 2) / 2));
        $total += $s;
        $breakdown[] = ['name' => $c['name'], 'max' => (float) $c['weight'], 'score' => $s, 'reason' => mb_substr(trim((string) ($g['reason'] ?? '')), 0, 600)];
    }
    $improve = inc_gem_improve_clean($json['improvements'] ?? []);
    inc_exec('UPDATE inc_gem_requests SET score = ?, breakdown_json = ?, summary = ?, improvements = ?, scored_at = NOW(), score_error = NULL WHERE id = ?', 'dsssi',
              [$total, json_encode($breakdown, JSON_UNESCAPED_UNICODE), mb_substr(trim((string) ($json['summary'] ?? '')), 0, 2000),
               $improve ? json_encode($improve, JSON_UNESCAPED_UNICODE) : null, $requestId]);
    inc_activity((int) $req['application_id'], null, 'gem', $gem['name'] . ' score: ' . rtrim(rtrim(number_format($total, 1), '0'), '.') . '/' . $gem['max'] . '.');
    return [true, 'Scored ' . rtrim(rtrim(number_format($total, 1), '0'), '.') . '/' . $gem['max'] . '.'];
}

/**
 * One JSON request to Gemini, retried while it is busy. Returns [true, decoded
 * reply] or [false, why].
 *
 * When the configured model gives no answer (it hung, as the newest
 * "latest" models have done) or is still busy or over its daily quota, the
 * request goes to the GEMINI_FALLBACK_MODEL list (comma-separated) in turn
 * instead; for the rest of this run the fallback is used straight away, so a
 * bulk send does not wait a minute per applicant.
 */
function inc_gem_gemini(string $key, string $prompt): array
{
    static $mainHung = false;
    require_once __DIR__ . '/../google.php';
    $main = fabo_env_or('GEMINI_MODEL', 'gemini-flash-latest');
    $spares = array_filter(array_map('trim', explode(',', fabo_env_or('GEMINI_FALLBACK_MODEL', 'gemini-3.5-flash,gemini-flash-lite-latest'))));
    $models = array_values(array_unique($mainHung ? $spares : array_merge([$main], $spares)));
    $payload = ['contents' => [['parts' => [['text' => $prompt]]]],
                'generationConfig' => ['temperature' => 0.1, 'maxOutputTokens' => 4096, 'responseMimeType' => 'application/json']];
    $busy = [429, 500, 502, 503, 504];
    $ok = false;
    $res = 'not attempted';
    $status = 0;
    foreach ($models as $model) {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($key);
        for ($try = 1; $try <= 3; $try++) {
            [$ok, $res, $status] = fabo_http('POST', $url, $payload, ['Content-Type: application/json'], 60);
            // A used-up quota will not come back in a few seconds: go on to the next model.
            if ($ok || !in_array($status, $busy, true) || stripos(is_string($res) ? $res : json_encode($res), 'quota') !== false) {
                break;
            }
            if ($try < 3) {
                sleep($try * 3);
            }
        }
        // No answer, or still busy / over its daily quota after the retries: try the spare.
        if ($ok || ($status !== 0 && !in_array($status, $busy, true))) {
            break;
        }
        if ($model === $main) {
            $mainHung = true;
            error_log('FABO gem: ' . $main . ' unavailable (HTTP ' . $status . '); trying ' . implode(', ', $spares));
        }
    }
    if (!$ok) {
        return [false, 'Gemini did not answer: ' . (is_string($res) ? $res : json_encode($res))];
    }
    $text = '';
    foreach ($res['candidates'][0]['content']['parts'] ?? [] as $part) {
        $text .= (string) ($part['text'] ?? '');
    }
    $json = json_decode(trim(preg_replace('/^```(?:json)?|```$/m', '', $text)), true);
    return is_array($json) ? [true, $json] : [false, 'Gemini\'s reply could not be read.'];
}

/** What Gemini is told about the improvement points, the same for a scoring and for points added later. */
function inc_gem_improve_rule(): string
{
    return 'In "improvements" give 3 to 5 suggestions that make the IDEA ITSELF better: changes to the product or solution, '
         . 'not advice on filling in the form or writing better answers. First work out what the idea is (the problem, the solution, '
         . 'who it is for), then suggest what to add, change, drop or focus on so it solves the problem better, stands out from what '
         . 'already exists, is simpler or cheaper to build, and is more likely to be used or paid for. Each suggestion must name a '
         . 'concrete change for THIS idea (a specific feature, a sharper user group, a different way it works, a cheaper material or '
         . 'technology, a way to earn money or reach users), most important first, aimed at the criteria where it lost the most marks. '
         . 'Write each as one or two short sentences addressed to the applicant ("you"), in simple English. No scores. Never say '
         . '"explain more", "add more detail", "be specific" or "write clearly": say what to change in the idea.';
}

/** Gemini's list, trimmed to plain, non-empty points (at most 6). */
function inc_gem_improve_clean($list): array
{
    $out = [];
    foreach (is_array($list) ? $list : [] as $p) {
        $p = trim(preg_replace('/^\s*(?:[-*•]|\d+[.)])\s*/u', '', (string) (is_array($p) ? ($p['text'] ?? '') : $p)));
        if ($p !== '') {
            $out[] = mb_substr($p, 0, 500);
        }
    }
    return array_slice($out, 0, 6);
}

function inc_gem_improvements(array $req): array
{
    return inc_gem_improve_clean(json_decode((string) ($req['improvements'] ?? ''), true));
}

/**
 * Adds improvement points to an application scored before they were asked
 * for. The score is left exactly as it is; Gemini sees the answers and the
 * marks already given. Returns [ok, points or why].
 */
function inc_gem_improve(int $requestId): array
{
    require_once __DIR__ . '/../google.php';
    $req = inc_one('SELECT * FROM inc_gem_requests WHERE id = ?', 'i', [$requestId]);
    if (!$req || $req['score'] === null) {
        return [false, 'not scored yet'];
    }
    if ($have = inc_gem_improvements($req)) {
        return [true, $have];
    }
    $gem = inc_gem((int) $req['gem_id']);
    $key = trim(fabo_env_or('GEMINI_API_KEY'));
    if (!$gem || $key === '') {
        return [false, 'No Gemini API key is set on this server.'];
    }
    $marks = '';
    foreach (json_decode((string) $req['breakdown_json'], true) ?: [] as $b) {
        $marks .= '- ' . $b['name'] . ': ' . $b['score'] . ' / ' . $b['max'] . '. ' . $b['reason'] . "\n";
    }
    $answers = '';
    foreach (json_decode((string) $req['answers_json'], true) ?: [] as $a) {
        $answers .= '### ' . $a['label'] . "\n" . ($a['value'] !== '' ? $a['value'] : '(no answer)') . "\n\n";
    }
    $prompt = trim((string) $gem['instructions']) . "\n\n"
            . "This application has already been scored. Do not re-score it. Using the answers and the marks below, "
            . "suggest how to make the idea itself better.\n\nANSWERS\n" . $answers . "MARKS GIVEN\n" . $marks
            . "\nReply with JSON only, in this shape: {\"improvements\":[\"...\"]}\n" . inc_gem_improve_rule();
    [$ok, $json] = inc_gem_gemini($key, $prompt);
    $points = $ok ? inc_gem_improve_clean($json['improvements'] ?? []) : [];
    if (!$points) {
        return [false, $ok ? 'Gemini gave no improvement points.' : $json];
    }
    inc_exec('UPDATE inc_gem_requests SET improvements = ? WHERE id = ?', 'si', [json_encode($points, JSON_UNESCAPED_UNICODE), $requestId]);
    return [true, $points];
}

/** Scores what is waiting (answered, not scored, fewer than 5 tries). For cron. */
function inc_gem_score_pending(int $limit = 10): int
{
    $n = 0;
    foreach (inc_all('SELECT id FROM inc_gem_requests WHERE submitted_at IS NOT NULL AND score IS NULL AND score_attempts < 5
                               AND (score_tried_at IS NULL OR score_tried_at < NOW() - INTERVAL 5 MINUTE) ORDER BY submitted_at LIMIT ' . (int) $limit) as $r) {
        $n += inc_gem_score((int) $r['id'])[0] ? 1 : 0;
    }
    return $n;
}

/* ============================================================ rendering */

/** The Gem section of an application's Overview: answers always, score only at its stages. */
function inc_gem_app_html(array $app, bool $quick = false): string
{
    $gems = inc_gems();
    if (!$gems) {
        return '';
    }
    $reqs = inc_gem_requests_for_app((int) $app['id']);
    $h = '';
    foreach ($gems as $gid => $gem) {
        $r = $reqs[$gid] ?? null;
        // Nothing to say about a Gem never sent (a new application is just its registration form).
        if (!$r || !$r['sent_at'] && !$r['submitted_at']) {
            continue;
        }
        if ($quick && (!$r['submitted_at'] || !inc_gem_visible_at($gid, (int) $app['stage_id']))) {
            continue;
        }
        $visible = inc_gem_visible_at($gid, (int) $app['stage_id']);
        $h .= '<section class="card inc-gem"><h2>' . e($gem['name']) . '</h2>';
        if (!$r || !$r['sent_at'] && !$r['submitted_at']) {
            $h .= '<p class="muted small">Not sent to this applicant yet.</p>';
        } elseif (!$r['submitted_at']) {
            $h .= '<p class="small">Form emailed to ' . e((string) $r['email']) . ' on ' . e(inc_fmt_datetime($r['sent_at'])) . ' · ' . inc_badge('Awaiting answers', 'amber')
                . ($r['mail_status'] && $r['mail_status'] !== 'sent' ? ' ' . inc_badge('Email ' . $r['mail_status'], $r['mail_status'] === 'queued' ? 'blue' : 'red') : '') . '</p>';
        } else {
            if ($visible && $r['score'] !== null) {
                $h .= '<div class="inc-gem-total">' . inc_gem_score_html($gem, (float) $r['score']) . '<span class="muted small">scored ' . e(inc_fmt_datetime($r['scored_at'])) . '</span></div>';
                if (trim((string) $r['summary']) !== '') {
                    $h .= '<p>' . nl2br(e($r['summary'])) . '</p>';
                }
                $h .= '<table class="data inc-gem-table"><thead><tr><th>Criterion</th><th class="num">Score</th><th>Why</th></tr></thead><tbody>';
                foreach (json_decode((string) $r['breakdown_json'], true) ?: [] as $b) {
                    $w = $b['max'] > 0 ? (int) round($b['score'] * 100 / $b['max']) : 0;
                    $h .= '<tr><td><strong>' . e($b['name']) . '</strong><div class="inc-mini-bar"><i style="width:' . $w . '%"></i></div></td>'
                        . '<td class="num nowrap">' . e(rtrim(rtrim(number_format($b['score'], 1), '0'), '.')) . ' / ' . e(rtrim(rtrim(number_format($b['max'], 1), '0'), '.')) . '</td>'
                        . '<td class="small">' . e($b['reason']) . '</td></tr>';
                }
                $h .= '</tbody></table>';
                if ($improve = inc_gem_improvements($r)) {
                    $h .= '<div class="inc-gem-improve"><strong>What to improve in the idea</strong><ol>';
                    foreach ($improve as $p) {
                        $h .= '<li>' . e($p) . '</li>';
                    }
                    $h .= '</ol></div>';
                }
            } elseif ($visible) {
                $h .= '<p class="small">' . inc_badge('Scoring', 'blue') . ' ' . e($r['score_error'] ? 'Last try: ' . $r['score_error'] : 'Gemini is scoring the answers; refresh in a minute.') . '</p>';
            } else {
                $names = inc_gem_stage_names($gid);
                $h .= '<p class="muted small">The score is shown at ' . ($names ? 'the ' . e(implode(', ', $names)) . ' stage' . (count($names) > 1 ? 's' : '') : 'a stage set to show it (the ⋯ menu on a column of the Applications board)') . '.</p>';
            }
            $h .= '<details class="inc-gem-answers"' . ($visible ? '' : ' open') . '><summary>Answers · ' . e(inc_fmt_datetime($r['submitted_at'])) . '</summary>';
            foreach (json_decode((string) $r['answers_json'], true) ?: [] as $a) {
                $h .= '<h4>' . e($a['label']) . '</h4><p>' . ($a['value'] !== '' ? nl2br(e($a['value'])) : '<span class="muted">No answer</span>') . '</p>';
            }
            $h .= '</details>';
        }
        if (!$quick && inc_can('edit')) {
            $h .= '<div class="inc-row">';
            if (!$r || !$r['submitted_at']) {
                $h .= inc_button($r && $r['sent_at'] ? 'Send the form again' : 'Email the form', ['action' => 'gem_send', 'gem_id' => $gid], 'btn btn-sm');
            }
            if ($r && $r['submitted_at']) {
                $h .= inc_button($r['score'] !== null ? 'Score again' : 'Score now', ['action' => 'gem_score', 'gem_id' => $gid], 'btn btn-sm', $r['score'] !== null ? 'Ask Gemini to score these answers again? The current score is replaced.' : '');
            }
            if ($r && !$r['submitted_at']) {
                $h .= ' <input type="text" readonly class="inc-copy" style="max-width:420px" value="' . e(inc_gem_link($r)) . '" onclick="this.select()" title="The applicant\'s personal link">';
            }
            $h .= '</div>';
        }
        $h .= '</section>';
    }
    return $h;
}

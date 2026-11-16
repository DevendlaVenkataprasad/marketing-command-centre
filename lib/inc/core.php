<?php
/**
 * Incubation Management — context, permissions, audit, settings, stages, people and
 * notifications. Everything the other lib/inc files share.
 *
 * WHO MAY DO WHAT. The administrator uses admin/incubation.php; our own staff
 * granted the module on Team Management use incubation.php at the root, as
 * View only (view and export) or Owner (everything the administrator can do)
 * — see inc_grant(). Every service function still asks inc_need() before it
 * reads or writes, so a call that arrives any other way is refused rather
 * than trusted.
 * Team members appear in the module as owners, evaluators and reviewers
 * (inc_team_members); what they are eligible for is enforced here, on the
 * server, whatever the form offered.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/org.php';
require_once __DIR__ . '/schema.php';

const INC_CALC_VERSION = 1;

/* ================================================================ context */

/**
 * $org names the organisation whose Incubation Management to open; by default it is
 * the viewer's own (inc_org_of()). The public form and cron.php pass it.
 */
function inc_start(array $viewer, ?int $org = null): void
{
    require_once __DIR__ . '/nav.php';
    $org = $org ?? inc_org_of($viewer);
    inc_use_org(max(0, $org));
    inc_install();
    $GLOBALS['inc_ctx'] = [
        'viewer'   => $viewer,
        'is_admin' => !empty($viewer['is_admin']),
        'grant'    => inc_grant($viewer, inc_grant_key()),
        'desk'     => inc_desk_member($viewer, inc_kind()),
        'actor'    => !empty($viewer['is_admin']) ? 'Administrator' : (string) ($viewer['client']['name'] ?? 'unknown'),
    ];
}

function inc_ctx(): array
{
    if (empty($GLOBALS['inc_ctx'])) {
        throw new RuntimeException('Incubation module used before inc_start().');
    }
    return $GLOBALS['inc_ctx'];
}

function inc_actor(): string
{
    return (string) (inc_ctx()['actor'] ?? 'system');
}

/**
 * The permissions the module knows. The administrator holds every one; the
 * list exists so each protected operation names what it needs.
 */
function inc_permissions(): array
{
    return [
        'view'      => 'View applications, incubations and reports',
        'edit'      => 'Edit applications and record activity',
        'stage'     => 'Move applications and incubations between stages',
        'assign'    => 'Assign and reassign incubation teams',
        'evaluate'  => 'Create and fill evaluations',
        'review'    => 'Review, approve and return evaluations',
        'bulk'      => 'Run bulk screening actions',
        'export'    => 'Export reports',
        'configure' => 'Configure forms, pipelines, templates and teams',
        'desk'      => 'Work the applications handed to a department',
    ];
}

function inc_can(string $perm): bool
{
    if (!isset(inc_permissions()[$perm])) {
        return false;
    }
    $ctx = inc_ctx();
    if (!empty($ctx['is_admin'])) {
        return true;
    }
    $grant = $ctx['grant'] ?? 'none';
    // The department desk: anyone holding the CRM, and every department member (lib/inc/depts.php).
    if ($perm === 'desk') {
        return $grant !== 'none' || !empty($ctx['desk']);
    }
    // Owner holds every permission; View only reads and exports.
    return $grant === 'edit' || ($grant === 'view' && in_array($perm, ['view', 'export'], true));
}

function inc_need(string $perm): void
{
    if (!inc_can($perm)) {
        error_log('FABO incubation denied ' . inc_actor() . ' -> ' . $perm);
        if (PHP_SAPI === 'cli') {
            throw new RuntimeException('Not permitted: ' . $perm);
        }
        fabo_deny('You do not have permission to do that in Incubation Management.', 403);
    }
}

/* ================================================================== audit */

function inc_audit(string $action, string $entity, ?int $id, string $summary = '', $before = null, $after = null, ?string $reason = null): void
{
    inc_exec('INSERT INTO inc_audit (actor, action, entity, entity_id, summary, before_json, after_json, reason, ip) VALUES (?,?,?,?,?,?,?,?,?)',
              'sssisssss', [inc_actor(), $action, $entity, $id, mb_substr($summary, 0, 255),
                            $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
                            $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
                            $reason === null || $reason === '' ? null : mb_substr($reason, 0, 500),
                            mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'cli'), 0, 45)]);
}

/** A line on an application's and/or workspace's timeline. */
function inc_activity(?int $appId, ?int $wsId, string $kind, string $summary): void
{
    inc_exec('INSERT INTO inc_activities (application_id, workspace_id, kind, summary, actor) VALUES (?,?,?,?,?)',
              'iisss', [$appId, $wsId, $kind, mb_substr($summary, 0, 500), inc_actor()]);
}

/* =============================================================== settings */

function inc_setting_defaults(): array
{
    return [
        'dup_window_days'   => '180',  // look-back for suspected duplicates
        'move_crm_on_assign' => '1',   // first assignment moves the application to "Assigned Application"
        'notify_prev_owner' => '1',    // tell the previous owner when a startup is reassigned
        'rating_labels'     => json_encode([
            '1' => 'Very early progress or insufficient evidence',
            '2' => 'Some initial progress; important gaps remain',
            '3' => 'Reasonable progress for the current stage',
            '4' => 'Strong progress for the current stage',
            '5' => 'Excellent progress for the current stage',
            'na' => 'Not applicable or not expected at the current stage',
        ]),
        'public_index'      => '0',    // list public forms on the open application page
        'ref_prefix'        => 'INC',
    ];
}

function inc_setting(string $k): string
{
    $cache = &$GLOBALS['inc_settings_cache'];
    if (!is_array($cache) || !array_key_exists($k, $cache)) {
        $r = inc_one('SELECT v FROM inc_settings WHERE k = ?', 's', [$k]);
        $cache[$k] = $r !== null ? (string) $r['v'] : (inc_setting_defaults()[$k] ?? '');
    }
    return $cache[$k];
}

function inc_setting_set(string $k, string $v): void
{
    $old = inc_setting($k);
    inc_exec('INSERT INTO inc_settings (k, v, updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE v = VALUES(v), updated_by = VALUES(updated_by)',
              'sss', [$k, $v, inc_actor()]);
    $GLOBALS['inc_settings_cache'][$k] = $v;
    if ($old !== $v) {
        inc_audit('setting', 'setting', null, $k, $old, $v);
    }
}

function inc_rating_labels(): array
{
    $l = json_decode(inc_setting('rating_labels'), true);
    return is_array($l) ? $l + json_decode(inc_setting_defaults()['rating_labels'], true) : json_decode(inc_setting_defaults()['rating_labels'], true);
}

/* ============================================================ validation */

function inc_str($v, int $max = 255, bool $required = false, string $label = 'This field'): string
{
    $s = trim(str_replace("\0", '', (string) ($v ?? '')));
    if ($required && $s === '') {
        throw new InvalidArgumentException($label . ' is required.');
    }
    if (mb_strlen($s) > $max) {
        throw new InvalidArgumentException($label . ' must be ' . $max . ' characters or fewer.');
    }
    return $s;
}

function inc_date($v, bool $required = false, string $label = 'Date'): ?string
{
    $s = trim((string) ($v ?? ''));
    if ($s === '') {
        if ($required) {
            throw new InvalidArgumentException($label . ' is required.');
        }
        return null;
    }
    $d = DateTime::createFromFormat('!Y-m-d', $s);
    if (!$d || $d->format('Y-m-d') !== $s) {
        throw new InvalidArgumentException($label . ' is not a valid date.');
    }
    return $s;
}

function inc_int_or_null($v): ?int
{
    $s = trim((string) ($v ?? ''));
    return $s === '' || !ctype_digit($s) || (int) $s === 0 ? null : (int) $s;
}

/**
 * Runs $fn in a transaction; rolls back on any throw. Re-entrant: an inner
 * call joins the outer transaction.
 */
function inc_tx(callable $fn)
{
    static $depth = 0;
    if ($depth > 0) {
        $depth++;
        try {
            return $fn();
        } finally {
            $depth--;
        }
    }
    $db = fabo_db();
    $db->begin_transaction();
    $depth = 1;
    try {
        $out = $fn();
        $depth = 0;
        $db->commit();
        return $out;
    } catch (Throwable $e) {
        $depth = 0;
        $db->rollback();
        throw $e;
    }
}

/* =============================================================== masters */

function inc_masters(string $kind, bool $activeOnly = true): array
{
    $rows = inc_all('SELECT * FROM inc_masters WHERE kind = ?' . ($activeOnly ? ' AND active = 1' : '') . ' ORDER BY sort, name', 's', [$kind]);
    $out = [];
    foreach ($rows as $r) {
        $out[(int) $r['id']] = $r['name'];
    }
    return $out;
}

/**
 * Sectors to filter by: the Sectors list, plus what applicants answered on a
 * form's own "Sector" question (forms whose sector options are typed in,
 * not taken from the list, never fill sector_id). Name => name.
 */
function inc_sector_options(): array
{
    $out = [];
    foreach (inc_masters('sector', false) as $name) {
        $out[$name] = $name;
    }
    foreach (inc_all('SELECT DISTINCT value_short FROM inc_app_values WHERE label = "Sector" AND value_short IS NOT NULL AND value_short <> "" ORDER BY value_short LIMIT 200') as $r) {
        $out[$r['value_short']] = $r['value_short'];
    }
    natcasesort($out);
    return $out;
}

function inc_master_name(?int $id): string
{
    if (!$id) {
        return '';
    }
    static $byOrg = [];
    $all = &$byOrg[inc_scope_key()];
    if ($all === null) {
        $all = [];
        foreach (inc_all('SELECT id, name FROM inc_masters') as $r) {
            $all[(int) $r['id']] = $r['name'];
        }
    }
    return (string) ($all[$id] ?? '');
}

function inc_master_save(string $kind, ?int $id, array $in): int
{
    inc_need('configure');
    if (!in_array($kind, ['spoke', 'sector', 'source', 'applicant_type'], true)) {
        throw new InvalidArgumentException('Unknown list.');
    }
    $name = inc_str($in['name'] ?? '', 120, true, 'Name');
    $active = !empty($in['active']) ? 1 : 0;
    $dup = inc_one('SELECT id FROM inc_masters WHERE kind = ? AND name = ? AND id <> ?', 'ssi', [$kind, $name, (int) $id]);
    if ($dup) {
        throw new InvalidArgumentException('"' . $name . '" is already on the list.');
    }
    if ($id) {
        inc_exec('UPDATE inc_masters SET name = ?, active = ? WHERE id = ? AND kind = ?', 'siis', [$name, $active, $id, $kind]);
    } else {
        $sort = (int) (inc_one('SELECT COALESCE(MAX(sort),0) + 1 AS n FROM inc_masters WHERE kind = ?', 's', [$kind])['n'] ?? 1);
        $id = inc_exec('INSERT INTO inc_masters (kind, name, sort, active) VALUES (?,?,?,?)', 'ssii', [$kind, $name, $sort, $active]);
    }
    inc_audit('save', 'master', $id, $kind . ': ' . $name);
    return $id;
}

/* ================================================================ purposes */

/**
 * The purposes the module ships with: code => [label, the name it asks for].
 * Their codes are fixed (each loads its own starting questions,
 * inc_purpose_preset()); their wording, the name they ask for and whether
 * they are offered are the organisation's to change, and it can add its own
 * (Settings -> Forms -> Form purposes), kept in the setting 'purposes'.
 */
function inc_purpose_builtins(): array
{
    return [
        'walk_in'          => ['Walk-in application', ''],
        'incubation'       => ['Startup incubation application', ''],
        'program'          => ['Program application', 'Program name'],
        'event'            => ['Event application', 'Event name'],
        'startup_referral' => ['Startup referral', ''],
        'partner_referral' => ['Partner or institutional referral', ''],
        'other'            => ['Other', ''],
    ];
}

/**
 * Every purpose, built-in and the organisation's own, in order:
 * code => ['label', 'ask' (the event/program name it asks for, or ''),
 * 'base' (whose starting questions a new form gets), 'hidden', 'builtin'].
 */
function inc_purpose_defs(): array
{
    $saved = json_decode(inc_setting('purposes'), true);
    $saved = is_array($saved) ? $saved : [];
    $out = [];
    foreach (inc_purpose_builtins() as $code => [$label, $ask]) {
        $s = is_array($saved[$code] ?? null) ? $saved[$code] : [];
        $out[$code] = ['label' => (string) ($s['label'] ?? $label), 'ask' => (string) ($s['ask'] ?? $ask), 'base' => $code,
                       'hidden' => !empty($s['hidden']), 'builtin' => true,
                       // Once its questions are edited (inc_purpose_template()), they are its own.
                       'template' => (int) ($s['template'] ?? 0)];
    }
    foreach ($saved as $code => $s) {
        if (!isset($out[$code]) && is_array($s) && trim((string) ($s['label'] ?? '')) !== '') {
            $base = (string) ($s['base'] ?? 'other');
            $out[(string) $code] = ['label' => (string) $s['label'], 'ask' => (string) ($s['ask'] ?? ''),
                                    'base' => isset(inc_purpose_builtins()[$base]) ? $base : 'other',
                                    'hidden' => !empty($s['hidden']), 'builtin' => false,
                                    // Its own starting questions: a hidden form edited in the builder (inc_purpose_template()).
                                    'template' => (int) ($s['template'] ?? 0)];
        }
    }
    return $out;
}

/**
 * code => label. Every purpose (so a form or application made with one that
 * is now hidden still reads right), or with $choosable only those offered
 * when creating a form.
 */
function inc_purposes(bool $choosable = false): array
{
    $out = [];
    foreach (inc_purpose_defs() as $code => $d) {
        if (!$choosable || !$d['hidden']) {
            $out[$code] = $d['label'];
        }
    }
    return $out;
}

/** Purposes whose form names one event or program, and what that name is called ('' for the rest). */
function inc_purpose_event_label(string $purpose): string
{
    return inc_purpose_defs()[$purpose]['ask'] ?? '';
}

/** The built-in purpose whose starting questions a new form of $purpose gets. */
function inc_purpose_base(string $purpose): string
{
    return inc_purpose_defs()[$purpose]['base'] ?? 'other';
}

/** Adds or changes a purpose ($code null adds one). */
function inc_purpose_save(?string $code, array $in): string
{
    inc_need('configure');
    $label = inc_str($in['label'] ?? '', 80, true, 'Purpose name');
    $ask = inc_str($in['ask'] ?? '', 60);
    $defs = inc_purpose_defs();
    foreach ($defs as $c => $d) {
        if ($c !== $code && strcasecmp($d['label'], $label) === 0) {
            throw new InvalidArgumentException('There is already a purpose called "' . $label . '".');
        }
    }
    $saved = json_decode(inc_setting('purposes'), true);
    $saved = is_array($saved) ? $saved : [];
    if ($code === null || $code === '') {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($label)), '_') ?: 'purpose';
        $code = 'c_' . substr($slug, 0, 22);
        for ($n = 2; isset($defs[$code]); $n++) {
            $code = 'c_' . substr($slug, 0, 20) . $n;
        }
        $defs[$code] = ['builtin' => false];
    } elseif (!isset($defs[$code])) {
        throw new InvalidArgumentException('That purpose no longer exists.');
    }
    $base = (string) ($in['base'] ?? 'other');
    $keep = is_array($saved[$code] ?? null) ? $saved[$code] : [];     // its template, and where it started from
    $saved[$code] = ['label' => $label, 'ask' => $ask, 'hidden' => !empty($in['hidden'])]
                  + (empty($defs[$code]['builtin']) ? ['base' => isset($in['base']) && isset(inc_purpose_builtins()[$base]) ? $base : ($keep['base'] ?? 'other')] : [])
                  + array_intersect_key($keep, ['template' => 1]);
    inc_setting_set('purposes', json_encode($saved, JSON_UNESCAPED_UNICODE));
    return $code;
}

/** Removes one of the organisation's own purposes, while no form uses it. */
function inc_purpose_delete(string $code): void
{
    inc_need('configure');
    $d = inc_purpose_defs()[$code] ?? null;
    if (!$d) {
        throw new InvalidArgumentException('That purpose no longer exists.');
    }
    if ($d['builtin']) {
        throw new InvalidArgumentException('A built-in purpose can be hidden, not removed.');
    }
    if (inc_one('SELECT id FROM inc_forms WHERE purpose = ? AND id <> ? LIMIT 1', 'si', [$code, (int) ($d['template'] ?? 0)])) {
        throw new InvalidArgumentException('Forms use "' . $d['label'] . '". Hide it instead, so it is no longer offered.');
    }
    // Its question template goes with it (nothing was ever submitted on a template).
    if (!empty($d['template'])) {
        inc_exec('DELETE FROM inc_forms WHERE id = ? AND NOT EXISTS (SELECT 1 FROM inc_applications a WHERE a.form_id = inc_forms.id)', 'i', [(int) $d['template']]);
    }
    $saved = json_decode(inc_setting('purposes'), true);
    unset($saved[$code]);
    inc_setting_set('purposes', json_encode($saved ?: new stdClass(), JSON_UNESCAPED_UNICODE));
}

/** "Event application · Startup Summit 2026": the purpose, with its event or program when it has one. */
function inc_purpose_text(string $purpose, ?string $event = null): string
{
    $p = inc_purposes()[$purpose] ?? $purpose;
    $event = trim((string) $event);
    return $event !== '' ? $p . ' · ' . $event : $p;
}

/**
 * Where an application came from, in a word or two: its source (Website,
 * Walk-in...), else what it was for (Startup, Program, Event...).
 */
function inc_came_from(array $a): string
{
    $src = inc_master_name(!empty($a['source_id']) ? (int) $a['source_id'] : null);
    if ($src !== '') {
        return $src;
    }
    $short = ['walk_in' => 'Walk-in', 'incubation' => 'Startup', 'program' => 'Program', 'event' => 'Event',
              'startup_referral' => 'Startup referral', 'partner_referral' => 'Partner referral'];
    return $short[$a['purpose'] ?? ''] ?? (inc_purposes()[$a['purpose'] ?? ''] ?? 'Not recorded');
}

/** Every event or program name in use, for the filter: on forms, and on applications already received. */
function inc_event_names(): array
{
    $out = [];
    foreach (inc_all('SELECT event_name AS n FROM inc_forms WHERE event_name IS NOT NULL AND event_name <> ""
                      UNION SELECT event_name FROM inc_applications WHERE event_name IS NOT NULL AND event_name <> "" ORDER BY n') as $r) {
        $out[$r['n']] = $r['n'];
    }
    return $out;
}

/* ============================================================ dev stages */

function inc_dev_stages(bool $activeOnly = false): array
{
    static $byOrg = [];
    $rows = &$byOrg[inc_scope_key()];
    if ($rows === null) {
        $rows = inc_all('SELECT * FROM inc_dev_stages ORDER BY sort');
    }
    $out = [];
    foreach ($rows as $r) {
        if (!$activeOnly || (int) $r['active'] === 1) {
            $out[$r['code']] = $r['name'];
        }
    }
    return $out;
}

function inc_dev_stage_desc(string $code): string
{
    $r = inc_one('SELECT description FROM inc_dev_stages WHERE code = ?', 's', [$code]);
    return (string) ($r['description'] ?? '');
}

/* ================================================================ stages */

function inc_stages(string $pipeline, bool $activeOnly = false): array
{
    return inc_all('SELECT * FROM inc_stages WHERE pipeline = ?' . ($activeOnly ? ' AND active = 1' : '') . ' ORDER BY sort, id', 's', [$pipeline]);
}

function inc_stage_map(string $pipeline, bool $activeOnly = false): array
{
    $out = [];
    foreach (inc_stages($pipeline, $activeOnly) as $s) {
        $out[(int) $s['id']] = $s['name'];
    }
    return $out;
}

function inc_stage(int $id): ?array
{
    return inc_one('SELECT * FROM inc_stages WHERE id = ?', 'i', [$id]);
}

function inc_stage_by_code(string $pipeline, string $code, bool $activeOnly = true): ?array
{
    return inc_one('SELECT * FROM inc_stages WHERE pipeline = ? AND code = ?' . ($activeOnly ? ' AND active = 1' : ''), 'ss', [$pipeline, $code]);
}

/** The first active stage of a pipeline: where new records start. */
function inc_first_stage(string $pipeline): array
{
    $s = inc_one('SELECT * FROM inc_stages WHERE pipeline = ? AND active = 1 ORDER BY sort, id LIMIT 1', 's', [$pipeline]);
    if (!$s) {
        throw new InvalidArgumentException('The ' . ($pipeline === 'crm' ? 'application pipeline' : 'incubation lifecycle') . ' has no active stage.');
    }
    return $s;
}

function inc_stage_requires(array $stage): array
{
    $r = json_decode((string) ($stage['requires_json'] ?? ''), true);
    return is_array($r) ? $r : [];
}

function inc_stage_allowed_next(array $stage): array
{
    $s = trim((string) ($stage['allowed_next'] ?? ''));
    return $s === '' ? [] : array_map('intval', array_filter(explode(',', $s)));
}

/**
 * May a record move from $from to $to? Returns null when it may, or the
 * reason it may not. $facts carries what the stage requirements check:
 * eval_approved, workspace, reason.
 */
function inc_transition_problem(?array $from, array $to, array $facts): ?string
{
    if ((int) $to['active'] !== 1) {
        return '"' . $to['name'] . '" is not an active stage.';
    }
    if ($from && (int) $from['id'] === (int) $to['id']) {
        return 'It is already at "' . $to['name'] . '".';
    }
    if ($from) {
        $allowed = inc_stage_allowed_next($from);
        if ($allowed && !in_array((int) $to['id'], $allowed, true)) {
            return 'Moving from "' . $from['name'] . '" to "' . $to['name'] . '" is not an allowed transition.';
        }
    }
    $req = inc_stage_requires($to);
    if (!empty($req['eval_approved']) && empty($facts['eval_approved'])) {
        return '"' . $to['name'] . '" needs an approved evaluation with an official score.';
    }
    if (!empty($req['workspace']) && empty($facts['workspace'])) {
        return '"' . $to['name'] . '" needs the application to be assigned for incubation first.';
    }
    if (!empty($req['reason']) && trim((string) ($facts['reason'] ?? '')) === '') {
        return '"' . $to['name'] . '" needs a reason.';
    }
    return null;
}

function inc_stage_save(string $pipeline, ?int $id, array $in): int
{
    inc_need('configure');
    if (!in_array($pipeline, ['crm', 'lifecycle'], true)) {
        throw new InvalidArgumentException('Unknown pipeline.');
    }
    $name = inc_str($in['name'] ?? '', 80, true, 'Stage name');
    $desc = inc_str($in['description'] ?? '', 255);
    $dup = inc_one('SELECT id FROM inc_stages WHERE pipeline = ? AND name = ? AND id <> ?', 'ssi', [$pipeline, $name, (int) $id]);
    if ($dup) {
        throw new InvalidArgumentException('Another stage is already called "' . $name . '".');
    }
    $valid = array_keys(inc_stage_map($pipeline));
    $next = [];
    foreach ((array) ($in['allowed_next'] ?? []) as $n) {
        $n = (int) $n;
        if (in_array($n, $valid, true) && $n !== (int) $id) {
            $next[] = $n;
        }
    }
    $req = [];
    foreach (['eval_approved', 'workspace', 'reason'] as $k) {
        if (!empty($in['req_' . $k])) {
            $req[$k] = true;
        }
    }
    $active = !empty($in['active']) ? 1 : 0;
    $terminal = !empty($in['is_terminal']) ? 1 : 0;
    $notify = !empty($in['notify']) ? 1 : 0;
    $before = $id ? inc_stage($id) : null;
    if ($id && !$before) {
        throw new InvalidArgumentException('That stage no longer exists.');
    }
    if ($before && $before['pipeline'] !== $pipeline) {
        throw new InvalidArgumentException('That stage belongs to the other pipeline.');
    }
    if ($id && !$active) {
        // A stage that records are sitting in may be deactivated, but the
        // pipeline must keep at least one active stage to receive new ones.
        $left = (int) inc_one('SELECT COUNT(*) AS n FROM inc_stages WHERE pipeline = ? AND active = 1 AND id <> ?', 'si', [$pipeline, $id])['n'];
        if ($left === 0) {
            throw new InvalidArgumentException('At least one stage must stay active.');
        }
    }
    $params = [$name, $desc, $active, $terminal, $next ? implode(',', $next) : null, $req ? json_encode($req) : null, $notify];
    if ($id) {
        inc_exec('UPDATE inc_stages SET name = ?, description = ?, active = ?, is_terminal = ?, allowed_next = ?, requires_json = ?, notify = ? WHERE id = ?',
                  'ssiissii', array_merge($params, [$id]));
    } else {
        $sort = (int) (inc_one('SELECT COALESCE(MAX(sort),0) + 10 AS n FROM inc_stages WHERE pipeline = ?', 's', [$pipeline])['n'] ?? 10);
        $id = inc_exec('INSERT INTO inc_stages (pipeline, name, description, active, is_terminal, allowed_next, requires_json, notify, sort) VALUES (?,?,?,?,?,?,?,?,?)',
                        'sssiissii', array_merge([$pipeline], $params, [$sort]));
    }
    inc_audit($before ? 'update' : 'create', 'stage', $id, $pipeline . ': ' . $name, $before, inc_stage($id));
    return $id;
}

/**
 * Deletes a stage that nothing sits in. Records that passed through it keep
 * their history (shown as a deleted stage); stages that listed it as an
 * allowed next step simply stop listing it.
 */
function inc_stage_delete(int $id): string
{
    inc_need('configure');
    $s = inc_stage($id);
    if (!$s) {
        throw new InvalidArgumentException('That stage no longer exists.');
    }
    $table = $s['pipeline'] === 'crm' ? 'inc_applications' : 'inc_workspaces';
    $n = (int) (inc_one('SELECT COUNT(*) AS n FROM ' . $table . ' WHERE stage_id = ?', 'i', [$id])['n'] ?? 0);
    if ($n > 0) {
        throw new InvalidArgumentException('"' . $s['name'] . '" still holds ' . $n . ' record' . ($n === 1 ? '' : 's') . '. Move them to another stage first.');
    }
    $others = array_filter(inc_stages($s['pipeline']), static fn($o) => (int) $o['id'] !== $id && (int) $o['active'] === 1);
    if (!$others) {
        throw new InvalidArgumentException('A pipeline needs at least one active stage.');
    }
    inc_tx(static function () use ($id, $s) {
        foreach (inc_stages($s['pipeline']) as $o) {
            $next = inc_stage_allowed_next($o);
            if (in_array($id, $next, true)) {
                $next = array_values(array_diff($next, [$id]));
                inc_exec('UPDATE inc_stages SET allowed_next = ? WHERE id = ?', 'si', [$next ? implode(',', $next) : null, (int) $o['id']]);
            }
        }
        if ($s['pipeline'] === 'crm') {
            inc_exec('UPDATE inc_gems SET send_stage_id = NULL WHERE send_stage_id = ?', 'i', [$id]);
        }
        inc_exec('DELETE FROM inc_stages WHERE id = ?', 'i', [$id]);
    });
    inc_audit('delete', 'stage', $id, $s['pipeline'] . ': ' . $s['name']);
    return 'Stage "' . $s['name'] . '" deleted.';
}

/** Moves a stage up or down within its pipeline. */
function inc_stage_move(int $id, int $dir): void
{
    inc_need('configure');
    $s = inc_stage($id);
    if (!$s) {
        throw new InvalidArgumentException('That stage no longer exists.');
    }
    $all = inc_stages($s['pipeline']);
    $ids = array_map(static fn($r) => (int) $r['id'], $all);
    $pos = array_search($id, $ids, true);
    $swap = $pos + ($dir < 0 ? -1 : 1);
    if ($pos === false || $swap < 0 || $swap >= count($ids)) {
        return;
    }
    [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]];
    inc_tx(static function () use ($ids) {
        foreach ($ids as $i => $sid) {
            inc_exec('UPDATE inc_stages SET sort = ? WHERE id = ?', 'ii', [($i + 1) * 10, $sid]);
        }
    });
    inc_audit('reorder', 'stage', $id, $s['pipeline'] . ': ' . $s['name'] . ($dir < 0 ? ' up' : ' down'));
}

/* ================================================================ people */

/**
 * Active team members of the organisation in use, id => name: our own staff
 * for us, the head's own team for a client (inc_people_cond()).
 */
function inc_people(): array
{
    static $byOrg = [];
    $out = &$byOrg[inc_scope_key()];
    if ($out === null) {
        $out = [];
        foreach (inc_all('SELECT c.id, c.name FROM clients c WHERE ' . inc_people_cond('c')
                       . ' AND c.revoked_at IS NULL AND (c.expires_at IS NULL OR c.expires_at > NOW()) ORDER BY c.name') as $r) {
            $out[(int) $r['id']] = $r['name'];
        }
    }
    return $out;
}

function inc_person(?int $id): ?array
{
    if (!$id) {
        return null;
    }
    return inc_one('SELECT id, name, email, kind, parent_id, revoked_at, expires_at FROM clients WHERE id = ?', 'i', [$id]);
}

function inc_person_name(?int $id): string
{
    if (!$id) {
        return '';
    }
    $p = inc_person($id);
    return $p ? (string) $p['name'] : 'Person #' . $id . ' (removed)';
}

/** Can sign in, and belongs to the organisation in use (our staff, or this client's own team). */
function inc_person_active(?array $p): bool
{
    $org = inc_org();
    return $p && $p['kind'] === 'team' && $p['revoked_at'] === null
        && ($p['expires_at'] === null || strtotime((string) $p['expires_at']) > time())
        && ($org > 0 ? (int) ($p['parent_id'] ?? 0) === $org : empty($p['parent_id']));
}

/* ================================================================= teams */

function inc_teams(bool $activeOnly = true): array
{
    $out = [];
    foreach (inc_all('SELECT id, name FROM inc_teams' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY name') as $r) {
        $out[(int) $r['id']] = $r['name'];
    }
    return $out;
}

function inc_team(int $id): ?array
{
    return inc_one('SELECT * FROM inc_teams WHERE id = ?', 'i', [$id]);
}

function inc_team_name(?int $id): string
{
    if (!$id) {
        return '';
    }
    $t = inc_team($id);
    return $t ? (string) $t['name'] : '';
}

function inc_team_members(int $teamId): array
{
    return inc_all('SELECT m.*, c.name, c.email FROM inc_team_members m JOIN clients c ON c.id = m.person_id
                     WHERE m.team_id = ? ORDER BY c.name, m.role', 'i', [$teamId]);
}

function inc_team_spokes(int $teamId): array
{
    return array_map(static fn($r) => (int) $r['spoke_id'], inc_all('SELECT spoke_id FROM inc_team_spokes WHERE team_id = ?', 'i', [$teamId]));
}

/**
 * Whom the owner, evaluator and assignee pickers offer: the organisation's
 * existing team, every active person in it. Incubation roles are no longer
 * needed to be picked -- people are made once, on Team Management (or a
 * client's My Team), and used here as they are. $role is kept for callers.
 */
function inc_people_with_role(?string $role = null): array
{
    unset($role);
    return inc_people();
}

/** Teams a person belongs to (any role). */
function inc_person_team_ids(int $personId): array
{
    return array_map(static fn($r) => (int) $r['team_id'],
        inc_all('SELECT DISTINCT m.team_id FROM inc_team_members m JOIN inc_teams t ON t.id = m.team_id AND t.active = 1 WHERE m.person_id = ?', 'i', [$personId]));
}

function inc_team_save(?int $id, array $in): int
{
    inc_need('configure');
    $name = inc_str($in['name'] ?? '', 120, true, 'Department name');
    $desc = inc_str($in['description'] ?? '', 255);
    $active = !empty($in['active']) ? 1 : 0;
    // Department, mentor panel or jury (lib/inc/depts.php inc_dept_kinds()); only the owner gets here (configure).
    $kind = (string) ($in['kind'] ?? 'department');
    if (!isset(inc_dept_kinds()[$kind])) {
        throw new InvalidArgumentException('Choose whether it is a department, mentors or a jury.');
    }
    if (inc_one('SELECT id FROM inc_teams WHERE name = ? AND id <> ?', 'si', [$name, (int) $id])) {
        throw new InvalidArgumentException('Another department is already called "' . $name . '".');
    }
    $spokes = array_values(array_intersect(array_map('intval', (array) ($in['spokes'] ?? [])), array_keys(inc_masters('spoke', false))));
    return inc_tx(static function () use ($id, $name, $desc, $active, $spokes, $kind) {
        $before = $id ? inc_team($id) : null;
        if ($id) {
            if (!$before) {
                throw new InvalidArgumentException('That team no longer exists.');
            }
            inc_exec('UPDATE inc_teams SET name = ?, description = ?, kind = ?, active = ? WHERE id = ?', 'sssii', [$name, $desc, $kind, $active, $id]);
        } else {
            $id = inc_exec('INSERT INTO inc_teams (name, description, kind, active) VALUES (?,?,?,?)', 'sssi', [$name, $desc, $kind, $active]);
        }
        inc_exec('DELETE FROM inc_team_spokes WHERE team_id = ?', 'i', [$id]);
        foreach ($spokes as $s) {
            inc_exec('INSERT INTO inc_team_spokes (team_id, spoke_id) VALUES (?,?)', 'ii', [$id, $s]);
        }
        inc_audit($before ? 'update' : 'create', 'team', $id, $name, $before, ['name' => $name, 'kind' => $kind, 'active' => $active, 'spokes' => $spokes]);
        return $id;
    });
}

function inc_team_member_add(int $teamId, int $personId, string $role): void
{
    inc_need('configure');
    if (!inc_team($teamId)) {
        throw new InvalidArgumentException('That team no longer exists.');
    }
    if (!in_array($role, ['member', 'lead', 'evaluator', 'reviewer'], true)) {
        throw new InvalidArgumentException('Choose a role.');
    }
    if (!inc_person_active(inc_person($personId))) {
        throw new InvalidArgumentException('Only active team members of the portal can join an incubation team.');
    }
    inc_exec('INSERT IGNORE INTO inc_team_members (team_id, person_id, role, added_by) VALUES (?,?,?,?)', 'iiss', [$teamId, $personId, $role, inc_actor()]);
    inc_audit('member_add', 'team', $teamId, inc_person_name($personId) . ' as ' . $role);
}

function inc_team_member_remove(int $teamId, int $personId, string $role): void
{
    inc_need('configure');
    inc_exec('DELETE FROM inc_team_members WHERE team_id = ? AND person_id = ? AND role = ?', 'iis', [$teamId, $personId, $role]);
    inc_audit('member_remove', 'team', $teamId, inc_person_name($personId) . ' as ' . $role);
}

/**
 * Why $personId may not own a startup for $teamId on an application from
 * $spokeId — or null when they may. Checked on every assignment.
 */
function inc_assignment_problem(int $teamId, int $personId, ?int $spokeId): ?string
{
    $team = inc_team($teamId);
    if (!$team || (int) $team['active'] !== 1) {
        return 'Choose an active incubation team.';
    }
    $p = inc_person($personId);
    if (!inc_person_active($p)) {
        return 'The chosen owner is not an active team member of the portal.';
    }
    // Any active person of the organisation may own it; department membership is not required.
    $spokes = inc_team_spokes($teamId);
    if ($spokes && $spokeId && !in_array($spokeId, $spokes, true)) {
        return $team['name'] . ' does not cover the ' . inc_master_name($spokeId) . ' spoke.';
    }
    if ($spokes && !$spokeId) {
        return $team['name'] . ' only takes applications from specific spokes, and this application has no spoke.';
    }
    return null;
}

/* ========================================================= notifications */

/** The events the module notifies about, with their default wording. */
function inc_mail_defaults(): array
{
    return [
        'acknowledgement' => ['We received your application {ref}', "Hello {applicant},\n\nThank you for applying. Your application reference is {ref} ({form}).\nWe will be in touch about the next step.\n\nRegards,\nThe Incubation Team"],
        'submitted'      => ['New incubation application {ref}', "A new application has been received.\n\nReference: {ref}\nApplicant: {applicant}\nStartup: {startup}\nForm: {form}\nSubmitted: {date}"],
        'assigned'       => ['Incubation assignment: {startup} ({ref})', "{startup} has been assigned to you for incubation.\n\nApplication: {ref}\nIncubation ID: {workspace}\nAssigned on: {date}\nTeam: {team}\nOwner: {owner}\nCurrent incubation stage: {stage}"],
        'reassigned'     => ['Incubation reassignment: {startup} ({ref})', "{startup} has been reassigned to you.\n\nApplication: {ref}\nIncubation ID: {workspace}\nReassigned on: {date}\nTeam: {team}\nOwner: {owner}\nCurrent incubation stage: {stage}\n{history}"],
        'reassigned_from' => ['{startup} has been reassigned', "{startup} ({ref}) is no longer assigned to you. It has moved to {owner} in {team} on {date}.\nThe incubation record and its history are kept."],
        'stage_changed'  => ['{startup}: incubation stage is now {stage}', "The incubation stage of {startup} ({workspace}) changed from {from} to {stage} on {date}."],
        'task'           => ['New task: {task}', "A task has been assigned to you for {startup} ({ref}).\n\nTask: {task}\nDue: {due}"],
        'review_request' => ['Evaluation ready for review: {startup}', "An evaluation of {startup} ({ref}) has been submitted for review by {evaluator}."],
        'approved'       => ['Evaluation approved: {startup}', "The evaluation of {startup} ({ref}) was approved.\nOfficial score: {score}\nUniqueness & Differentiation: {uniqueness}"],
        'returned'       => ['Evaluation returned for clarification: {startup}', "The evaluation of {startup} ({ref}) was returned for clarification.\n\nReviewer's note:\n{note}"],
        'reminder'       => ['Reminder: {task}', "Reminder for {startup} ({ref}): {task} is due {due}."],
        'dept_assigned'  => ['New application for {team}: {startup} ({ref})', "An application has been assigned to your department, {team}.\n\nApplication: {ref}\nStartup: {startup}\nApplicant: {applicant}\nAssigned by: {owner} on {date}\nNote: {note}\n\nSign in and open Incubation Management → My department to work on it. When you are done, return it to the incubation team with your remarks."],
        'dept_returned'  => ['{team} returned {startup} ({ref})', "{team} has returned the application to the incubation team.\n\nApplication: {ref}\nStartup: {startup}\nReturned by: {by} on {date}\nRemarks: {note}"],
    ];
}

function inc_mail_template(string $event): array
{
    $d = inc_mail_defaults()[$event] ?? ['{event}', ''];
    $r = inc_one('SELECT * FROM inc_mail_templates WHERE event = ?', 's', [$event]);
    return [
        'subject'   => $r ? (string) $r['subject'] : $d[0],
        'body'      => $r ? (string) $r['body'] : $d[1],
        'email_on'  => $r ? (int) $r['email_on'] === 1 : true,
        'portal_on' => $r ? (int) $r['portal_on'] === 1 : true,
        'custom'    => (bool) $r,
    ];
}

function inc_mail_template_save(string $event, array $in): void
{
    inc_need('configure');
    if (!isset(inc_mail_defaults()[$event])) {
        throw new InvalidArgumentException('Unknown notification.');
    }
    $subject = inc_str($in['subject'] ?? '', 255, true, 'Subject');
    $body = inc_str($in['body'] ?? '', 5000, true, 'Message');
    inc_exec('INSERT INTO inc_mail_templates (event, subject, body, email_on, portal_on, updated_by) VALUES (?,?,?,?,?,?)
               ON DUPLICATE KEY UPDATE subject = VALUES(subject), body = VALUES(body), email_on = VALUES(email_on), portal_on = VALUES(portal_on), updated_by = VALUES(updated_by)',
              'sssiis', [$event, $subject, $body, !empty($in['email_on']) ? 1 : 0, !empty($in['portal_on']) ? 1 : 0, inc_actor()]);
    inc_audit('update', 'mail_template', null, $event);
}

function inc_fill(string $tpl, array $vars): string
{
    return preg_replace_callback('/\{([a-z_]+)\}/', static fn($m) => array_key_exists($m[1], $vars) ? (string) $vars[$m[1]] : $m[0], $tpl);
}

/**
 * The product's own name for itself, in anything shown or sent. Whole phrases
 * only, never the bare word: an event may well be called "Incubation Bootcamp".
 */
function inc_words(string $text): string
{
    if (inc_kind() !== 'eo') {
        return $text;
    }
    return strtr($text, [
        'Incubation Management' => 'Event Organizers', 'Incubation Overview' => 'Event Organizers',
        'Incubation settings' => 'Organizer settings', 'Incubation applications' => 'Event applications',
        'The incubation team' => 'The organizers team', 'the incubation team' => 'the organizers team',
        'incubation teams' => 'organizers teams', 'incubation team' => 'organizers team',
        'Incubation team' => 'Organizers team', 'Incubation assignment' => 'Event assignment',
        'Incubation reassignment' => 'Event reassignment', 'Their incubation work' => 'Their event work',
        'Department desk' => 'Organizers desk',
    ]);
}

/** Where a notice should link. Only the administrator can open the module, so only they get a link. */
function inc_admin_link(string $page, array $q = []): string
{
    // A client organisation's head opens their Incubation Management at the root, not under admin/.
    $file = inc_kind() === 'eo' ? 'organizers.php?' : 'incubation.php?';
    return (inc_org() > 0 ? $file : 'admin/' . $file) . http_build_query(['p' => $page] + $q);
}

/**
 * Notifies one recipient about one business event, once.
 *
 * $recipientId is a team member's clients.id, or 0 for the administrator.
 * $eventKey identifies the business event (e.g. "assign:42"); with the
 * recipient it forms the de-duplication key, so the same event never reaches
 * the same person twice however often the code path runs. A portal notice
 * and an email are written as separate log rows; a failed email keeps its
 * rendered text for inc_notify_retry().
 *
 * Returns ['portal' => status, 'email' => status].
 */
function inc_notify(string $event, string $eventKey, int $recipientId, array $vars, ?int $appId = null, ?int $wsId = null, string $adminLink = '', string $personLink = ''): array
{
    $tpl = inc_mail_template($event);
    $subject = mb_substr(inc_words(inc_fill($tpl['subject'], $vars)), 0, 255);
    $body = inc_words(inc_fill($tpl['body'], $vars));
    $key = mb_substr($event . ':' . $eventKey . ':' . $recipientId, 0, 120);
    $out = ['portal' => 'skipped', 'email' => 'skipped'];
    // Recipient 0 is whoever runs this Incubation Management: the administrator for
    // us, the client's head for a client organisation (who gets the link too).
    $runsIt = $recipientId === 0;
    if ($runsIt && inc_org() > 0) {
        $recipientId = inc_org();
    }
    $isAdmin = $recipientId === 0;
    $person = $isAdmin ? null : inc_person($recipientId);
    if (!$isAdmin && !$person) {
        return $out;
    }
    // A team member gets a link only to somewhere they can open: their department desk.
    $link = $runsIt ? $adminLink : $personLink;

    if ($tpl['portal_on']) {
        $exists = inc_one("SELECT status FROM inc_notify_log WHERE event_key = ? AND channel = 'portal'", 's', [$key]);
        if ($exists) {
            $out['portal'] = 'duplicate';
        } else {
            require_once __DIR__ . '/../notify.php';
            $nid = fabo_notify($recipientId, $subject, $body, $link);
            inc_exec("INSERT INTO inc_notify_log (event_key, event, channel, recipient_id, subject, body_text, status, notice_id, application_id, workspace_id)
                       VALUES (?,?,'portal',?,?,?,'sent',?,?,?)", 'ssissiii', [$key, $event, $recipientId, $subject, $body, $nid, $appId, $wsId]);
            $out['portal'] = 'sent';
        }
    }

    if ($tpl['email_on']) {
        $exists = inc_one("SELECT status FROM inc_notify_log WHERE event_key = ? AND channel = 'email'", 's', [$key]);
        if ($exists) {
            $out['email'] = 'duplicate';
        } else {
            $to = $isAdmin ? (defined('ADMIN_INBOX_EMAIL') ? (string) ADMIN_INBOX_EMAIL : '') : (string) ($person['email'] ?? '');
            $text = ($person ? 'Hello ' . $person['name'] . ",\n\n" : '') . $body
                  . ($link !== '' ? "\n\nOpen: " . rtrim(fabo_portal_url_safe(), '/') . '/' . $link : '') . "\n";
            [$status, $detail, $outbox] = inc_send_email($to, $subject, $text);
            inc_exec("INSERT INTO inc_notify_log (event_key, event, channel, recipient_id, recipient_email, subject, body_text, status, detail, outbox_id, application_id, workspace_id)
                       VALUES (?,?,'email',?,?,?,?,?,?,?,?,?)", 'ssisssssiii',
                      [$key, $event, $recipientId, $to !== '' ? $to : null, $subject, $text, $status, mb_substr($detail, 0, 500), $outbox, $appId, $wsId]);
            $out['email'] = $status;
        }
    }
    return $out;
}

function fabo_portal_url_safe(): string
{
    // An organisation with its own working address has it in background mail too.
    if (function_exists('fabo_link_host') && fabo_link_host() !== '') {
        return 'https://' . fabo_link_host() . '/';
    }
    if (PHP_SAPI === 'cli' && empty($_SERVER['HTTP_HOST'])) {
        return 'https://portal.invalid/';
    }
    require_once __DIR__ . '/../ui.php';
    return fabo_portal_url();
}

/**
 * Hands one email to the portal's shared mail delivery (lib/outbox.php).
 * Returns [status, detail, outbox id]. Never throws: a notice that cannot be
 * mailed must never undo the business change that caused it.
 */
function inc_send_email(string $to, string $subject, string $text, ?string $htmlBody = null): array
{
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['skipped', 'no valid email address on record', null];
    }
    // A test seam: tests set this to force a failure or capture what was sent.
    if (isset($GLOBALS['inc_mail_hook']) && is_callable($GLOBALS['inc_mail_hook'])) {
        $r = ($GLOBALS['inc_mail_hook'])($to, $subject, $text);
        return [$r[0] ? 'sent' : 'failed', (string) ($r[1] ?? ''), null];
    }
    try {
        require_once __DIR__ . '/../mailer.php';
        if (!fabo_mail_configured()) {
            return ['failed', 'outgoing mail is not configured on this server', null];
        }
        require_once __DIR__ . '/../outbox.php';
        require_once __DIR__ . '/../mailtpl.php';
        require_once __DIR__ . '/../notify.php';
        // A client organisation's mail goes out under its organisation name (on From,
        // owner's address on Reply-To, or their own mailbox when granted), not as us.
        $owner = inc_org() > 0 ? fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [inc_org()]) : null;
        if ($owner) {
            $owner['send_as_name'] = fabo_owner_brand($owner);
        }
        $brand = $owner ? fabo_owner_brand($owner) : fabo_app_name();
        $html = fabo_mail_wrap($brand, $subject, $htmlBody ?? fabo_mail_p(nl2br(e($text))), $text);
        $kind = 'inc ' . ($owner ? 'o' . (int) $owner['id'] . ' ' : '') . substr(sha1($subject . microtime()), 0, 12);
        [$ok, $why, $oid] = fabo_post_or_queue($to, $subject, $text, $html, null, $kind, $owner);
        if ($ok) {
            return ['sent', '', null];
        }
        return [$oid ? 'queued' : 'failed', (string) $why, $oid ?: null];
    } catch (Throwable $e) {
        error_log('FABO incubation email: ' . $e->getMessage());
        return ['failed', 'the mail service raised an error', null];
    }
}

/**
 * Stops every queued email: each is taken out of the outbox so it is never
 * sent to anyone, and its log row is marked skipped. Portal notices that
 * already went out stay as they are. Returns how many were stopped.
 */
function inc_notify_cancel_queued(): int
{
    inc_need('configure');
    $rows = inc_all("SELECT id, outbox_id FROM inc_notify_log WHERE channel = 'email' AND status = 'queued'");
    if (!$rows) {
        return 0;
    }
    inc_tx(static function () use ($rows) {
        foreach (array_chunk($rows, 200) as $chunk) {
            $ids = implode(',', array_map(static fn($r) => (int) $r['id'], $chunk));
            $out = implode(',', array_filter(array_map(static fn($r) => (int) $r['outbox_id'], $chunk))) ?: '0';
            inc_exec('DELETE FROM mail_outbox WHERE state = "waiting" AND id IN (' . $out . ')');
            inc_exec("UPDATE inc_notify_log SET status = 'skipped', outbox_id = NULL, detail = ? WHERE id IN (" . $ids . ')',
                     's', ['Cancelled before sending by ' . inc_actor()]);
        }
    });
    inc_audit('cancel', 'notification', null, count($rows) . ' queued emails cancelled before sending');
    return count($rows);
}

/**
 * Tries a failed or queued email again from its log row. Only the email is
 * re-sent: no assignment, workspace or portal notice is touched.
 */
function inc_notify_retry(int $logId): array
{
    inc_need('assign');
    $r = inc_one("SELECT * FROM inc_notify_log WHERE id = ? AND channel = 'email'", 'i', [$logId]);
    if (!$r) {
        throw new InvalidArgumentException('That notification is no longer in the log.');
    }
    if ($r['status'] === 'sent') {
        return ['sent', 'It had already been sent.'];
    }
    // A message dropped from the outbox has nothing left to resend there: send it afresh.
    if ($r['outbox_id'] && !inc_one('SELECT id FROM mail_outbox WHERE id = ?', 'i', [(int) $r['outbox_id']])) {
        inc_exec('UPDATE inc_notify_log SET outbox_id = NULL WHERE id = ?', 'i', [$logId]);
        $r['outbox_id'] = null;
    }
    if ($r['outbox_id']) {
        require_once __DIR__ . '/../outbox.php';
        [$ok, $why] = fabo_outbox_send((int) $r['outbox_id']);
        $status = $ok ? 'sent' : 'queued';
    } else {
        [$status, $why] = inc_send_email((string) $r['recipient_email'], (string) $r['subject'], (string) $r['body_text']);
    }
    inc_exec('UPDATE inc_notify_log SET status = ?, detail = ?, tries = tries + 1, last_try_at = NOW() WHERE id = ?',
              'ssi', [$status, mb_substr((string) $why, 0, 500), $logId]);
    inc_audit('retry', 'notification', $logId, $r['subject'] . ' -> ' . $status);
    return [$status, $status === 'sent' ? 'Sent.' : 'Not sent yet: ' . $why];
}

/**
 * Brings queued emails in the log up to date with the portal's outbox.
 *
 * A queued email is delivered later by the outbox (on an admin page load or
 * by cron.php), which knows nothing of this log, so without this the log and
 * the dashboard went on reporting it as unsent after it had gone.
 */
function inc_notify_sync(): void
{
    try {
        inc_exec("UPDATE inc_notify_log l JOIN mail_outbox o ON o.id = l.outbox_id
                   SET l.status = 'sent', l.detail = '', l.last_try_at = COALESCE(o.sent_at, NOW())
                   WHERE l.channel = 'email' AND l.status = 'queued' AND o.state = 'sent'");
        inc_exec("UPDATE inc_notify_log l JOIN mail_outbox o ON o.id = l.outbox_id
                   SET l.detail = LEFT(o.last_error, 500), l.tries = o.tries, l.last_try_at = COALESCE(o.last_try_at, l.last_try_at)
                   WHERE l.channel = 'email' AND l.status = 'queued' AND o.state = 'waiting' AND o.last_error <> ''");
        inc_exec("UPDATE inc_notify_log l LEFT JOIN mail_outbox o ON o.id = l.outbox_id
                   SET l.status = 'failed', l.outbox_id = NULL, l.detail = 'removed from the mail queue before it was sent; press Retry to send it again'
                   WHERE l.channel = 'email' AND l.status = 'queued' AND l.outbox_id IS NOT NULL AND o.id IS NULL");
    } catch (Throwable $e) {
        error_log('FABO incubation notify sync: ' . $e->getMessage());
    }
}

/* ================================================================ formats */

function inc_fmt_date(?string $d): string
{
    if (!$d || str_starts_with($d, '0000')) {
        return '—';
    }
    return date('d M Y', strtotime($d));
}

function inc_fmt_datetime(?string $d): string
{
    if (!$d) {
        return '—';
    }
    return date('d M Y, H:i', strtotime($d));
}

function inc_fmt_score($s): string
{
    return $s === null || $s === '' ? '—' : number_format((float) $s, 1);
}

function inc_fmt_rating($r): string
{
    return $r === null || $r === '' ? '—' : rtrim(rtrim(number_format((float) $r, 2), '0'), '.');
}

function inc_eval_status_labels(): array
{
    return [
        'none'         => 'Not Evaluated',
        'incomplete'   => 'Evaluation Incomplete',
        'draft'        => 'Draft',
        'in_progress'  => 'In Progress',
        'submitted'    => 'Submitted',
        'under_review' => 'Under Review',
        'returned'     => 'Returned for Clarification',
        'approved'     => 'Approved',
    ];
}

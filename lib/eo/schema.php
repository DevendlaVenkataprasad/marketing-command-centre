<?php
/**
 * Event Organizers — the event layer's tables, and the move onto the main pipeline.
 *
 * Written inc_ev_* like every statement here; inc_sql() lands them in eo_ev_* for
 * us and eo_o<client id>_ev_* for a client. Forms and applications are not here:
 * they are the organisation's main ones (inc_forms, inc_applications).
 *
 * The first version of this module ran a second copy of the engine on eo_forms,
 * eo_applications and so on. eo_move_to_main() brings whatever was entered there
 * across, once; the old tables are then left unread, never dropped.
 */

const EO_SCHEMA_VERSION = 2;

function eo_ddl(): array
{
    $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $id = 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
    $ev = 'event_id INT UNSIGNED NOT NULL';
    return [
"CREATE TABLE IF NOT EXISTS inc_ev_events ($id, source_id INT UNSIGNED NOT NULL, token CHAR(24) NOT NULL, name VARCHAR(190) NOT NULL, label VARCHAR(190) NOT NULL,
  event_type VARCHAR(120) NULL, start_date DATE NULL, end_date DATE NULL, location VARCHAR(255) NULL, approved TINYINT(1) NOT NULL DEFAULT 1, published TINYINT(1) NOT NULL DEFAULT 0,
  summary VARCHAR(300) NULL, description TEXT NULL, venue VARCHAR(255) NULL, mode ENUM('in_person','online','hybrid') NOT NULL DEFAULT 'in_person', meeting_url VARCHAR(500) NULL,
  capacity INT UNSIGNED NULL, reg_deadline DATE NULL, banner VARCHAR(80) NULL, banner_mime VARCHAR(80) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY u_source (source_id), UNIQUE KEY u_token (token), KEY k_dates (start_date, end_date)) $t",
"CREATE TABLE IF NOT EXISTS inc_ev_forms ($ev, form_id INT UNSIGNED NOT NULL, PRIMARY KEY (event_id, form_id), UNIQUE KEY u_form (form_id),
  CONSTRAINT fk_inc_evf_event FOREIGN KEY (event_id) REFERENCES inc_ev_events(id) ON DELETE CASCADE) $t",
"CREATE TABLE IF NOT EXISTS inc_ev_tickets ($id, $ev, application_id INT UNSIGNED NOT NULL, code CHAR(24) NOT NULL, status ENUM('issued','cancelled','reserved') NOT NULL DEFAULT 'issued',
  issued_by VARCHAR(190) NOT NULL DEFAULT '', emailed_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY u_code (code), UNIQUE KEY u_app (event_id, application_id),
  CONSTRAINT fk_inc_evt_event FOREIGN KEY (event_id) REFERENCES inc_ev_events(id) ON DELETE CASCADE) $t",
"CREATE TABLE IF NOT EXISTS inc_ev_checkins (ticket_id INT UNSIGNED NOT NULL, day_no SMALLINT UNSIGNED NOT NULL, checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, checked_by VARCHAR(190) NOT NULL DEFAULT '',
  PRIMARY KEY (ticket_id, day_no), CONSTRAINT fk_inc_evc_ticket FOREIGN KEY (ticket_id) REFERENCES inc_ev_tickets(id) ON DELETE CASCADE) $t",
"CREATE TABLE IF NOT EXISTS inc_ev_sessions ($id, $ev, day_no SMALLINT UNSIGNED NOT NULL DEFAULT 1, starts VARCHAR(5) NULL, ends VARCHAR(5) NULL, title VARCHAR(190) NOT NULL, speaker VARCHAR(190) NULL, room VARCHAR(120) NULL,
  KEY k_event (event_id, day_no, starts), CONSTRAINT fk_inc_evs_event FOREIGN KEY (event_id) REFERENCES inc_ev_events(id) ON DELETE CASCADE) $t",
"CREATE TABLE IF NOT EXISTS inc_ev_people ($id, $ev, kind ENUM('speaker','mentor','sponsor','partner') NOT NULL, name VARCHAR(190) NOT NULL, detail VARCHAR(255) NULL, url VARCHAR(500) NULL,
  KEY k_event (event_id, kind), CONSTRAINT fk_inc_evp_event FOREIGN KEY (event_id) REFERENCES inc_ev_events(id) ON DELETE CASCADE) $t",
"CREATE TABLE IF NOT EXISTS inc_ev_feedback (ticket_id INT UNSIGNED NOT NULL PRIMARY KEY, rating TINYINT UNSIGNED NOT NULL, comment TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_inc_evb_ticket FOREIGN KEY (ticket_id) REFERENCES inc_ev_tickets(id) ON DELETE CASCADE) $t",

"CREATE TABLE IF NOT EXISTS inc_ev_categories ($id, name VARCHAR(120) NOT NULL, color VARCHAR(9) NULL, UNIQUE KEY u_name (name)) $t",
/* What became of an application as a registration. */
"CREATE TABLE IF NOT EXISTS inc_ev_regs (application_id INT UNSIGNED NOT NULL PRIMARY KEY, $ev, status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  flagged TINYINT(1) NOT NULL DEFAULT 0, flag_reason VARCHAR(255) NULL, reminder24_at DATETIME NULL, reminder1_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_event (event_id, status)) $t",
"CREATE TABLE IF NOT EXISTS inc_ev_orders ($id, $ev, application_id INT UNSIGNED NULL, token CHAR(24) NOT NULL, name VARCHAR(190) NOT NULL DEFAULT '', email VARCHAR(190) NOT NULL DEFAULT '',
  amount DECIMAL(12,2) NOT NULL DEFAULT 0, discount DECIMAL(12,2) NOT NULL DEFAULT 0, currency VARCHAR(3) NOT NULL DEFAULT 'INR', method ENUM('none','stripe','cash','bank') NOT NULL DEFAULT 'none',
  status ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending', stripe_session VARCHAR(255) NULL, stripe_intent VARCHAR(255) NULL, receipt_url VARCHAR(500) NULL, bank_ref VARCHAR(120) NULL,
  coupon_id INT UNSIGNED NULL, refund_amount DECIMAL(12,2) NULL, refunded_at DATETIME NULL, paid_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY u_token (token), UNIQUE KEY u_stripe (stripe_session), KEY k_event (event_id, status), KEY k_email (email)) $t",
"CREATE TABLE IF NOT EXISTS inc_ev_coupons ($id, $ev, code VARCHAR(40) NOT NULL, type ENUM('percentage','fixed') NOT NULL DEFAULT 'percentage', value DECIMAL(12,2) NOT NULL, max_redemptions INT UNSIGNED NULL,
  redeemed INT UNSIGNED NOT NULL DEFAULT 0, referrer VARCHAR(120) NULL, active TINYINT(1) NOT NULL DEFAULT 1, expires_on DATE NULL, UNIQUE KEY u_code (event_id, code)) $t",
"CREATE TABLE IF NOT EXISTS inc_ev_waitlist ($id, $ev, name VARCHAR(190) NOT NULL, email VARCHAR(190) NOT NULL, phone VARCHAR(40) NULL, position INT UNSIGNED NOT NULL, status ENUM('waiting','notified','expired','converted') NOT NULL DEFAULT 'waiting',
  notified_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY u_person (event_id, email), KEY k_next (event_id, status, position)) $t",
"CREATE TABLE IF NOT EXISTS inc_ev_food ($id, $ev, application_id INT UNSIGNED NOT NULL, token CHAR(24) NOT NULL, quantity SMALLINT UNSIGNED NOT NULL DEFAULT 1, redeemed SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('sent','redeemed','cancelled') NOT NULL DEFAULT 'sent', redeemed_at DATETIME NULL, redeemed_by VARCHAR(190) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY u_token (token), UNIQUE KEY u_app (event_id, application_id)) $t",
"CREATE TABLE IF NOT EXISTS inc_ev_certs ($id, $ev, application_id INT UNSIGNED NOT NULL, number VARCHAR(40) NOT NULL, token CHAR(32) NOT NULL, days_attended SMALLINT UNSIGNED NOT NULL, total_days SMALLINT UNSIGNED NOT NULL,
  issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY u_token (token), UNIQUE KEY u_app (event_id, application_id)) $t",
"CREATE TABLE IF NOT EXISTS inc_ev_questions ($id, session_id INT UNSIGNED NOT NULL, ticket_id INT UNSIGNED NOT NULL, question VARCHAR(500) NOT NULL, answered TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_session (session_id), CONSTRAINT fk_inc_evq_s FOREIGN KEY (session_id) REFERENCES inc_ev_sessions(id) ON DELETE CASCADE) $t",
"CREATE TABLE IF NOT EXISTS inc_ev_upvotes (question_id INT UNSIGNED NOT NULL, ticket_id INT UNSIGNED NOT NULL, PRIMARY KEY (question_id, ticket_id),
  CONSTRAINT fk_inc_evu_q FOREIGN KEY (question_id) REFERENCES inc_ev_questions(id) ON DELETE CASCADE) $t",
"CREATE TABLE IF NOT EXISTS inc_ev_polls ($id, session_id INT UNSIGNED NOT NULL, question VARCHAR(300) NOT NULL, open_now TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_session (session_id), CONSTRAINT fk_inc_evl_s FOREIGN KEY (session_id) REFERENCES inc_ev_sessions(id) ON DELETE CASCADE) $t",
"CREATE TABLE IF NOT EXISTS inc_ev_poll_options ($id, poll_id INT UNSIGNED NOT NULL, label VARCHAR(190) NOT NULL, KEY k_poll (poll_id),
  CONSTRAINT fk_inc_evo_p FOREIGN KEY (poll_id) REFERENCES inc_ev_polls(id) ON DELETE CASCADE) $t",
"CREATE TABLE IF NOT EXISTS inc_ev_poll_votes (poll_id INT UNSIGNED NOT NULL, ticket_id INT UNSIGNED NOT NULL, option_id INT UNSIGNED NOT NULL, PRIMARY KEY (poll_id, ticket_id),
  CONSTRAINT fk_inc_evv_p FOREIGN KEY (poll_id) REFERENCES inc_ev_polls(id) ON DELETE CASCADE) $t",
"CREATE TABLE IF NOT EXISTS inc_ev_online ($id, $ev, ticket_id INT UNSIGNED NULL, email VARCHAR(190) NULL, day_no SMALLINT UNSIGNED NOT NULL DEFAULT 1, joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, left_at DATETIME NULL, source VARCHAR(30) NOT NULL DEFAULT 'join-link',
  KEY k_event (event_id, day_no)) $t",
"CREATE TABLE IF NOT EXISTS inc_ev_mentors ($id, name VARCHAR(190) NOT NULL, email VARCHAR(190) NULL, phone VARCHAR(40) NULL, expertise VARCHAR(255) NULL, organization VARCHAR(190) NULL, active TINYINT(1) NOT NULL DEFAULT 1) $t",
"CREATE TABLE IF NOT EXISTS inc_ev_event_mentors ($id, $ev, mentor_id INT UNSIGNED NOT NULL, role_label VARCHAR(120) NULL, notes VARCHAR(500) NULL, UNIQUE KEY u_pair (event_id, mentor_id),
  CONSTRAINT fk_inc_evm_m FOREIGN KEY (mentor_id) REFERENCES inc_ev_mentors(id) ON DELETE CASCADE) $t",
"CREATE TABLE IF NOT EXISTS inc_ev_mentor_tasks ($id, assignment_id INT UNSIGNED NOT NULL, day_no SMALLINT UNSIGNED NULL, title VARCHAR(190) NOT NULL, status ENUM('pending','in_progress','completed') NOT NULL DEFAULT 'pending', due_on DATE NULL,
  KEY k_a (assignment_id), CONSTRAINT fk_inc_evk_a FOREIGN KEY (assignment_id) REFERENCES inc_ev_event_mentors(id) ON DELETE CASCADE) $t",
"CREATE TABLE IF NOT EXISTS inc_ev_sponsors ($id, name VARCHAR(190) NOT NULL, website VARCHAR(500) NULL, description VARCHAR(500) NULL, logo VARCHAR(80) NULL, logo_mime VARCHAR(80) NULL) $t",
"CREATE TABLE IF NOT EXISTS inc_ev_event_sponsors ($id, $ev, sponsor_id INT UNSIGNED NOT NULL, tier ENUM('platinum','gold','silver','bronze','partner') NOT NULL DEFAULT 'partner', clicks INT UNSIGNED NOT NULL DEFAULT 0, UNIQUE KEY u_pair (event_id, sponsor_id),
  CONSTRAINT fk_inc_evz_s FOREIGN KEY (sponsor_id) REFERENCES inc_ev_sponsors(id) ON DELETE CASCADE) $t",
"CREATE TABLE IF NOT EXISTS inc_ev_leads ($id, event_sponsor_id INT UNSIGNED NOT NULL, application_id INT UNSIGNED NOT NULL, captured_by VARCHAR(190) NOT NULL DEFAULT '', rating TINYINT UNSIGNED NULL, notes VARCHAR(500) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY u_lead (event_sponsor_id, application_id), CONSTRAINT fk_inc_evd_s FOREIGN KEY (event_sponsor_id) REFERENCES inc_ev_event_sponsors(id) ON DELETE CASCADE) $t",
/* People given one job on an event by a link, with no portal account. */
"CREATE TABLE IF NOT EXISTS inc_ev_team ($id, event_id INT UNSIGNED NULL, name VARCHAR(190) NOT NULL DEFAULT '', email VARCHAR(190) NOT NULL, role ENUM('scanner','viewer','finance','sponsor_rep') NOT NULL, sponsor_id INT UNSIGNED NULL,
  token CHAR(40) NOT NULL, status ENUM('active','revoked') NOT NULL DEFAULT 'active', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY u_token (token)) $t",
"CREATE TABLE IF NOT EXISTS inc_ev_api_keys ($id, label VARCHAR(120) NOT NULL, prefix VARCHAR(20) NOT NULL, hash CHAR(64) NOT NULL, last_used_at DATETIME NULL, revoked_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY u_hash (hash)) $t",
"CREATE TABLE IF NOT EXISTS inc_ev_webhooks ($id, url VARCHAR(500) NOT NULL, secret CHAR(48) NOT NULL, events VARCHAR(255) NOT NULL, active TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) $t",
"CREATE TABLE IF NOT EXISTS inc_ev_messages ($id, channel ENUM('whatsapp','sms') NOT NULL, kind VARCHAR(30) NOT NULL, recipient VARCHAR(60) NOT NULL, body VARCHAR(1000) NOT NULL, status ENUM('queued','sent','failed','skipped') NOT NULL DEFAULT 'queued',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0, run_after DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, error VARCHAR(255) NULL, event_id INT UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY k_due (status, run_after)) $t",
"CREATE TABLE IF NOT EXISTS inc_ev_contact ($id, name VARCHAR(190) NOT NULL, email VARCHAR(190) NOT NULL, subject VARCHAR(190) NOT NULL DEFAULT '', message TEXT NOT NULL, reply TEXT NULL, replied_at DATETIME NULL, replied_by VARCHAR(190) NULL, is_read TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) $t",
"CREATE TABLE IF NOT EXISTS inc_ev_reports ($id, $ev, content MEDIUMTEXT NOT NULL, created_by VARCHAR(190) NOT NULL DEFAULT '', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY k_event (event_id)) $t",
"CREATE TABLE IF NOT EXISTS inc_ev_users ($id, email VARCHAR(190) NOT NULL, name VARCHAR(190) NOT NULL, phone VARCHAR(40) NULL, password_hash VARCHAR(255) NOT NULL, organization VARCHAR(190) NULL, job_title VARCHAR(190) NULL, location VARCHAR(190) NULL, bio VARCHAR(1000) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1, reset_hash CHAR(64) NULL, reset_until DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY u_email (email)) $t",
"CREATE TABLE IF NOT EXISTS inc_ev_notices ($id, email VARCHAR(190) NOT NULL, title VARCHAR(190) NOT NULL, message VARCHAR(500) NOT NULL, link VARCHAR(500) NULL, read_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY k_email (email, read_at)) $t",
    ];
}

/** Columns added to tables the first version made. [table, column, definition] */
function eo_columns(): array
{
    return [
        ['ev_events', 'category_id', 'INT UNSIGNED NULL'], ['ev_events', 'tags', 'VARCHAR(255) NULL'], ['ev_events', 'start_time', 'VARCHAR(5) NULL'],
        ['ev_events', 'city', 'VARCHAR(120) NULL'], ['ev_events', 'is_paid', 'TINYINT(1) NOT NULL DEFAULT 0'], ['ev_events', 'price', 'DECIMAL(12,2) NOT NULL DEFAULT 0'],
        ['ev_events', 'currency', "VARCHAR(3) NOT NULL DEFAULT 'INR'"], ['ev_events', 'approval_required', 'TINYINT(1) NOT NULL DEFAULT 1'], ['ev_events', 'waitlist_enabled', 'TINYINT(1) NOT NULL DEFAULT 0'],
        ['ev_events', 'cancelled_at', 'DATETIME NULL'], ['ev_events', 'cancel_reason', 'VARCHAR(500) NULL'], ['ev_events', 'is_featured', 'TINYINT(1) NOT NULL DEFAULT 0'],
        ['ev_events', 'view_count', 'INT UNSIGNED NOT NULL DEFAULT 0'], ['ev_events', 'reminders_enabled', 'TINYINT(1) NOT NULL DEFAULT 1'], ['ev_events', 'food_provided', 'TINYINT(1) NOT NULL DEFAULT 0'],
        ['ev_events', 'accommodation_provided', 'TINYINT(1) NOT NULL DEFAULT 0'], ['ev_events', 'amenities', 'VARCHAR(500) NULL'], ['ev_events', 'video_url', 'VARCHAR(500) NULL'],
        ['ev_events', 'zoom_meeting_id', 'VARCHAR(40) NULL'],
        ['ev_tickets', 'used_at', 'DATETIME NULL'],
        ['ev_checkins', 'scanned_offline', 'TINYINT(1) NOT NULL DEFAULT 0'], ['ev_checkins', 'client_scan_id', 'VARCHAR(64) NULL'],
        ['ev_feedback', 'status', "ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending'"], ['ev_feedback', 'moderated_by', 'VARCHAR(190) NULL'],
        ['ev_sessions', 'description', 'VARCHAR(1000) NULL'],
    ];
}

function eo_table_exists(string $name): bool
{
    return (bool) fabo_one('SELECT 1 AS x FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', 's', [$name]);
}

/** Copies one row into a table, keeping only the columns the target has. Returns the new id. */
function eo_copy_row(string $table, array $row, array $over = []): int
{
    static $cols = [];
    if (!isset($cols[$table])) {
        $cols[$table] = array_column(fabo_all('SHOW COLUMNS FROM `' . $table . '`'), 'Field');
    }
    unset($row['id']);
    $row = array_intersect_key($over + $row, array_flip($cols[$table]));
    $names = array_keys($row);
    fabo_exec('INSERT INTO `' . $table . '` (`' . implode('`,`', $names) . '`) VALUES (' . rtrim(str_repeat('?,', count($names)), ',') . ')',
              str_repeat('s', count($names)), array_values($row));
    return (int) fabo_db()->insert_id;
}

/**
 * Brings the first version's forms and applications onto the main pipeline.
 * Public form links keep working: a form keeps its token. Tickets and event
 * forms follow the new ids. Anything that cannot be matched by name (a stage,
 * say) lands at the pipeline's first stage rather than being lost.
 */
function eo_move_to_main(): int
{
    $old = eo_prefix();
    if (!eo_table_exists($old . 'forms') || !eo_table_exists($old . 'ev_forms')) {
        return 0;
    }
    $new = inc_prefix();
    $moved = 0;
    $team = eo_team_id();
    $first = (int) (inc_one('SELECT id FROM inc_stages WHERE pipeline = "crm" ORDER BY sort LIMIT 1')['id'] ?? 0);
    $stages = array_column(inc_all('SELECT id, name FROM inc_stages WHERE pipeline = "crm"'), 'id', 'name');
    $oldStages = array_column(fabo_all('SELECT id, name FROM `' . $old . 'stages`'), 'name', 'id');
    $oldTypes = array_column(fabo_all('SELECT id, name FROM `' . $old . 'masters` WHERE kind = "applicant_type"'), 'name', 'id');
    $db = fabo_db();

    // Everyone on the old Organizers team stays on the team.
    foreach (fabo_all('SELECT DISTINCT m.person_id, m.role, m.added_by FROM `' . $old . 'team_members` m JOIN `' . $old . 'teams` t ON t.id = m.team_id WHERE t.name = "Organizers"') as $m) {
        inc_exec('INSERT IGNORE INTO inc_team_members (team_id, person_id, role, added_by) VALUES (?,?,?,?)', 'iiss', [$team, (int) $m['person_id'], $m['role'], $m['added_by']]);
    }

    $links = fabo_all('SELECT * FROM `' . $old . 'ev_forms`');
    $formMap = $verMap = $appMap = [];
    $db->begin_transaction();
    try {
        foreach (fabo_all('SELECT * FROM `' . $old . 'forms` WHERE id IN (SELECT form_id FROM `' . $old . 'ev_forms`)') as $f) {
            $nf = eo_copy_row($new . 'forms', $f, ['dept_team_id' => $team, 'default_source_id' => null, 'default_type_id' => null]);
            $formMap[(int) $f['id']] = $nf;
            foreach (fabo_all('SELECT * FROM `' . $old . 'form_versions` WHERE form_id = ?', 'i', [(int) $f['id']]) as $v) {
                $nv = eo_copy_row($new . 'form_versions', $v, ['form_id' => $nf]);
                $verMap[(int) $v['id']] = $nv;
                foreach (fabo_all('SELECT * FROM `' . $old . 'form_fields` WHERE version_id = ?', 'i', [(int) $v['id']]) as $x) {
                    eo_copy_row($new . 'form_fields', $x, ['version_id' => $nv, 'maps_to' => $x['uid'] === 'participation' ? 'applicant_type' : $x['maps_to']]);
                }
            }
            foreach (fabo_all('SELECT * FROM `' . $old . 'applications` WHERE form_id = ?', 'i', [(int) $f['id']]) as $a) {
                $stage = (int) ($stages[$oldStages[(int) $a['stage_id']] ?? ''] ?? $first);
                $typeName = $oldTypes[(int) ($a['applicant_type_id'] ?? 0)] ?? '';
                $na = eo_copy_row($new . 'applications', $a, ['ref' => null, 'form_id' => $nf, 'form_version_id' => $verMap[(int) $a['form_version_id']] ?? null, 'stage_id' => $stage,
                                                             'startup_id' => null, 'startup_suggest_id' => null, 'workspace_id' => null, 'dup_of_id' => null, 'spoke_id' => null, 'sector_id' => null,
                                                             'source_id' => null, 'team_id' => $team, 'official_eval_id' => null,
                                                             'applicant_type_id' => $typeName !== '' ? inc_applicant_type_id($typeName) : null]);
                $appMap[(int) $a['id']] = $na;
                inc_exec('UPDATE inc_applications SET ref = ? WHERE id = ?', 'si', [inc_setting('ref_prefix') . '-' . date('Y') . '-' . str_pad((string) $na, 5, '0', STR_PAD_LEFT), $na]);
                foreach (['app_values', 'activities'] as $tbl) {
                    foreach (fabo_all('SELECT * FROM `' . $old . $tbl . '` WHERE application_id = ?', 'i', [(int) $a['id']]) as $row) {
                        eo_copy_row($new . $tbl, $row, ['application_id' => $na, 'workspace_id' => null]);
                    }
                }
                foreach (fabo_all('SELECT * FROM `' . $old . 'files` WHERE entity = "application" AND entity_id = ?', 'i', [(int) $a['id']]) as $row) {
                    eo_copy_row($new . 'files', $row, ['entity_id' => $na]);
                }
                $moved++;
            }
        }
        // Keys may overlap, so step clear of them before writing the new ones.
        fabo_exec('UPDATE `' . eo_prefix() . 'ev_tickets` SET application_id = application_id + 1000000000');
        foreach ($appMap as $from => $to) {
            fabo_exec('UPDATE `' . eo_prefix() . 'ev_tickets` SET application_id = ? WHERE application_id = ?', 'ii', [$to, $from + 1000000000]);
        }
        fabo_exec('DELETE FROM `' . eo_prefix() . 'ev_forms`');
        foreach ($links as $l) {
            if (isset($formMap[(int) $l['form_id']])) {
                fabo_exec('INSERT INTO `' . eo_prefix() . 'ev_forms` (event_id, form_id) VALUES (?,?)', 'ii', [(int) $l['event_id'], $formMap[(int) $l['form_id']]]);
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
    return $moved;
}

function eo_install(): void
{
    static $done = [];
    if (isset($done[inc_scope_key()])) {
        return;
    }
    $done[inc_scope_key()] = true;
    if ((int) inc_setting('_eo_schema') >= EO_SCHEMA_VERSION) {
        return;
    }
    // One at a time: two first visitors at once must not move the data twice.
    $lock = 'eo_install_' . inc_org();
    fabo_one('SELECT GET_LOCK(?, 60) AS x', 's', [$lock]);
    try {
        if ((int) (inc_one('SELECT v FROM inc_settings WHERE k = "_eo_schema"')['v'] ?? 0) < EO_SCHEMA_VERSION) {
            eo_install_now();
        }
    } finally {
        fabo_one('SELECT RELEASE_LOCK(?) AS x', 's', [$lock]);
        $GLOBALS['inc_settings_cache'] = [];
    }
}

function eo_install_now(): void
{
    $db = fabo_db();
    $p = eo_prefix();
    // The first version tied these two to its own copy of forms and applications.
    foreach ([['ev_forms', 'evf_form'], ['ev_tickets', 'evt_app']] as [$table, $fk]) {
        if (eo_table_exists($p . $table) && fabo_one('SELECT 1 AS x FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = ? AND constraint_name = ?', 'ss', [$p . $table, 'fk_' . $p . $fk])) {
            $db->query('ALTER TABLE `' . $p . $table . '` DROP FOREIGN KEY `fk_' . $p . $fk . '`');
        }
    }
    foreach (eo_ddl() as $sql) {
        $db->query(inc_sql($sql));
    }
    foreach (eo_columns() as [$table, $col, $def]) {
        if (!$db->query('SHOW COLUMNS FROM `' . $p . $table . "` LIKE '" . $col . "'")->num_rows) {
            $db->query('ALTER TABLE `' . $p . $table . '` ADD COLUMN `' . $col . '` ' . $def);
        }
    }
    $db->query('ALTER TABLE `' . $p . "ev_tickets` MODIFY status ENUM('issued','cancelled','reserved') NOT NULL DEFAULT 'issued'");
    if (!$db->query('SHOW INDEX FROM `' . $p . "ev_checkins` WHERE Key_name = 'u_scan'")->num_rows) {
        $db->query('ALTER TABLE `' . $p . 'ev_checkins` ADD UNIQUE KEY u_scan (client_scan_id)');
    }
    eo_team_id();
    if (!inc_one('SELECT v FROM inc_settings WHERE k = "_eo_moved" AND v = "1"')) {
        $moved = eo_move_to_main();
        inc_setting_set('_eo_moved', '1');
        if ($moved) {
            error_log('FABO organizers: moved ' . $moved . ' applications onto the main pipeline for org ' . inc_org());
        }
    }
    // Deleting an application in the main pipeline takes its ticket with it.
    foreach ([['ev_tickets', 'application_id', 'applications', 'fk_inc_evt_app'], ['ev_forms', 'form_id', 'forms', 'fk_inc_evf_form']] as [$table, $col, $ref, $fk]) {
        $name = inc_sql($fk);
        if (!fabo_one('SELECT 1 AS x FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = ? AND constraint_name = ?', 'ss', [$p . $table, $name])) {
            $db->query('DELETE x FROM `' . $p . $table . '` x LEFT JOIN `' . inc_prefix() . $ref . '` r ON r.id = x.`' . $col . '` WHERE r.id IS NULL');
            $db->query('ALTER TABLE `' . $p . $table . '` ADD CONSTRAINT `' . $name . '` FOREIGN KEY (`' . $col . '`) REFERENCES `' . inc_prefix() . $ref . '`(id) ON DELETE CASCADE');
        }
    }
    inc_setting_set('_eo_schema', (string) EO_SCHEMA_VERSION);
}

/** The Organizers team: a department of the main pipeline, made once. A rename keeps it. */
function eo_team_id(): int
{
    $id = (int) inc_setting('_eo_team_id');
    if ($id > 0 && inc_one('SELECT id FROM inc_teams WHERE id = ?', 'i', [$id])) {
        return $id;
    }
    $row = inc_one('SELECT id FROM inc_teams WHERE name = "Organizers"');
    $id = $row ? (int) $row['id']
               : inc_exec('INSERT INTO inc_teams (name, description) VALUES ("Organizers", "Runs approved events: applications, tickets and check-in.")');
    inc_setting_set('_eo_team_id', (string) $id);
    return $id;
}

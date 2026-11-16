<?php
/**
 * Incubation Management — tables.
 *
 * A module of its own. Every table here starts inc_ and nothing in it reads
 * or writes the main CRM's crm_* tables: applications, pipelines, stages,
 * assignments, activities and reports for incubation live here and only here.
 *
 * The only links outward are to shared infrastructure:
 *   - people (owners, evaluators) are rows in `clients` with kind = 'team',
 *     referenced by id and checked in code — never by a foreign key, so the
 *     Team Management desk can still remove a person as it always could;
 *   - portal notices go into the shared `notifications` table and email
 *     through the shared outbox (lib/outbox.php).
 *
 * Installed like the rest of the portal: CREATE TABLE IF NOT EXISTS, once per
 * request, the first time an incubation page is opened. Defaults (stages,
 * pillars, questions) are seeded only when their table is empty, so an
 * administrator's changes are never overwritten.
 */

const INC_SCHEMA_VERSION = 13;  // 13: mentors and jury (inc_teams.kind) and Gem stages on a department desk (inc_dept_stages.gem_ids); 12: applicant type (inc_applications.applicant_type_id, inc_forms.default_type_id); 11: a form shown step by step (inc_forms.stepwise); 10: a form owned by a department (inc_forms.dept_team_id); 9: stage certificates (inc_certificates); 2: departments (inc_dept_*) and the two department pipeline stages; 3: event / program name; 4: Gems; 5: Gem 1 set up for every organisation, acknowledgement on registration; 6: the form chooses its Gem; 7: score cards; 8: improvement points, last scoring try

function inc_ddl(): array
{
    $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [

/* -------------------------------------------------------------- settings */

"CREATE TABLE IF NOT EXISTS inc_settings (
  k VARCHAR(64) NOT NULL PRIMARY KEY,
  v MEDIUMTEXT NULL,
  updated_by VARCHAR(160) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) $t",

"CREATE TABLE IF NOT EXISTS inc_audit (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor VARCHAR(190) NOT NULL DEFAULT '',
  action VARCHAR(60) NOT NULL,
  entity VARCHAR(40) NOT NULL,
  entity_id INT UNSIGNED NULL,
  summary VARCHAR(255) NULL,
  before_json MEDIUMTEXT NULL,
  after_json MEDIUMTEXT NULL,
  reason VARCHAR(500) NULL,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_time (created_at),
  KEY k_entity (entity, entity_id)
) $t",

/* Spokes, sectors, sources and applicant types: one list each. */
"CREATE TABLE IF NOT EXISTS inc_masters (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kind ENUM('spoke','sector','source','applicant_type') NOT NULL,
  name VARCHAR(120) NOT NULL,
  sort SMALLINT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY u_kind_name (kind, name)
) $t",

/* The five startup development stages. Codes are fixed (questions map to
   them); names and descriptions are the administrator's. */
"CREATE TABLE IF NOT EXISTS inc_dev_stages (
  code VARCHAR(20) NOT NULL PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  description VARCHAR(500) NULL,
  sort SMALLINT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1
) $t",

/* Both pipelines: the application pipeline (crm) and the incubation
   lifecycle. Same shape, never mixed: every query names its pipeline. */
"CREATE TABLE IF NOT EXISTS inc_stages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pipeline ENUM('crm','lifecycle') NOT NULL,
  code VARCHAR(40) NULL,
  name VARCHAR(80) NOT NULL,
  description VARCHAR(255) NULL,
  sort SMALLINT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  is_terminal TINYINT(1) NOT NULL DEFAULT 0,
  allowed_next VARCHAR(500) NULL,
  requires_json VARCHAR(500) NULL,
  notify TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY u_pipe_code (pipeline, code),
  UNIQUE KEY u_pipe_name (pipeline, name),
  KEY k_pipe_sort (pipeline, sort)
) $t",

"CREATE TABLE IF NOT EXISTS inc_stage_history (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pipeline ENUM('crm','lifecycle') NOT NULL,
  entity_id INT UNSIGNED NOT NULL,
  from_stage_id INT UNSIGNED NULL,
  to_stage_id INT UNSIGNED NOT NULL,
  actor VARCHAR(190) NOT NULL DEFAULT '',
  reason VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_entity (pipeline, entity_id, created_at)
) $t",

/* ------------------------------------------------------------------ forms */

"CREATE TABLE IF NOT EXISTS inc_forms (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  purpose VARCHAR(30) NOT NULL,
  description TEXT NULL,
  public_token CHAR(32) NOT NULL,
  public_enabled TINYINT(1) NOT NULL DEFAULT 0,
  block_duplicates TINYINT(1) NOT NULL DEFAULT 1,
  default_source_id INT UNSIGNED NULL,
  archived TINYINT(1) NOT NULL DEFAULT 0,
  created_by VARCHAR(190) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY u_token (public_token),
  KEY k_purpose (purpose)
) $t",

/* A form's questions belong to a version. Publishing freezes a version; an
   edit after that starts a new draft. Submissions name the version they used. */
"CREATE TABLE IF NOT EXISTS inc_form_versions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  form_id INT UNSIGNED NOT NULL,
  version_no SMALLINT UNSIGNED NOT NULL,
  status ENUM('draft','published','retired') NOT NULL DEFAULT 'draft',
  note VARCHAR(255) NULL,
  created_by VARCHAR(190) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  published_at DATETIME NULL,
  published_by VARCHAR(190) NULL,
  UNIQUE KEY u_form_ver (form_id, version_no),
  KEY k_form_status (form_id, status),
  CONSTRAINT fk_inc_fv_form FOREIGN KEY (form_id) REFERENCES inc_forms(id) ON DELETE CASCADE
) $t",

/* uid is the field's stable identity: it carries across versions and a
   rename, so a response always finds its question. */
"CREATE TABLE IF NOT EXISTS inc_form_fields (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  version_id INT UNSIGNED NOT NULL,
  uid VARCHAR(40) NOT NULL,
  type VARCHAR(20) NOT NULL,
  label VARCHAR(255) NOT NULL,
  placeholder VARCHAR(255) NULL,
  help TEXT NULL,
  required TINYINT(1) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  sort SMALLINT NOT NULL DEFAULT 0,
  options_json TEXT NULL,
  option_source VARCHAR(20) NULL,
  rules_json TEXT NULL,
  cond_json TEXT NULL,
  maps_to VARCHAR(30) NULL,
  UNIQUE KEY u_ver_uid (version_id, uid),
  KEY k_ver_sort (version_id, sort),
  CONSTRAINT fk_inc_ff_ver FOREIGN KEY (version_id) REFERENCES inc_form_versions(id) ON DELETE CASCADE
) $t",

/* --------------------------------------------------------------- startups */

"CREATE TABLE IF NOT EXISTS inc_startups (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  name_norm VARCHAR(190) NOT NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(40) NULL,
  website VARCHAR(300) NULL,
  spoke_id INT UNSIGNED NULL,
  sector_id INT UNSIGNED NULL,
  dev_stage VARCHAR(20) NULL,
  dev_stage_at DATETIME NULL,
  created_by VARCHAR(190) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY k_norm (name_norm),
  KEY k_email (email),
  KEY k_stage (dev_stage)
) $t",

"CREATE TABLE IF NOT EXISTS inc_dev_history (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  startup_id INT UNSIGNED NOT NULL,
  from_stage VARCHAR(20) NULL,
  to_stage VARCHAR(20) NOT NULL,
  actor VARCHAR(190) NOT NULL DEFAULT '',
  reason VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_startup (startup_id, created_at),
  CONSTRAINT fk_inc_dh_st FOREIGN KEY (startup_id) REFERENCES inc_startups(id) ON DELETE CASCADE
) $t",

/* ----------------------------------------------------------- applications */

/* official_score, uniqueness_rating and eval_status are a cache of the
   evaluations, rewritten by inc_app_refresh_eval() whenever an evaluation
   changes state, so the list can filter and sort on them in SQL. */
"CREATE TABLE IF NOT EXISTS inc_applications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ref VARCHAR(24) NULL,
  form_id INT UNSIGNED NOT NULL,
  form_version_id INT UNSIGNED NOT NULL,
  purpose VARCHAR(30) NOT NULL,
  channel ENUM('public','admin') NOT NULL DEFAULT 'admin',
  source_id INT UNSIGNED NULL,
  applicant_name VARCHAR(190) NOT NULL DEFAULT '',
  applicant_email VARCHAR(190) NULL,
  applicant_phone VARCHAR(40) NULL,
  startup_name VARCHAR(190) NOT NULL DEFAULT '',
  startup_id INT UNSIGNED NULL,
  startup_suggest_id INT UNSIGNED NULL,
  spoke_id INT UNSIGNED NULL,
  sector_id INT UNSIGNED NULL,
  stage_id INT UNSIGNED NOT NULL,
  dup_status ENUM('none','suspected','confirmed','cleared') NOT NULL DEFAULT 'none',
  dup_of_id INT UNSIGNED NULL,
  team_id INT UNSIGNED NULL,
  owner_id INT UNSIGNED NULL,
  workspace_id INT UNSIGNED NULL,
  eval_status VARCHAR(20) NOT NULL DEFAULT 'none',
  official_eval_id INT UNSIGNED NULL,
  official_score DECIMAL(6,2) NULL,
  uniqueness_rating DECIMAL(5,3) NULL,
  official_coverage DECIMAL(6,2) NULL,
  submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  submitted_by VARCHAR(190) NOT NULL DEFAULT '',
  submit_ip VARCHAR(45) NULL,
  submit_ticket CHAR(32) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY u_ref (ref),
  UNIQUE KEY u_ticket (submit_ticket),
  KEY k_stage (stage_id),
  KEY k_form (form_id),
  KEY k_submitted (submitted_at),
  KEY k_email (applicant_email),
  KEY k_startup (startup_id),
  KEY k_spoke (spoke_id),
  KEY k_owner (owner_id),
  KEY k_score (official_score),
  KEY k_eval (eval_status),
  CONSTRAINT fk_inc_app_form FOREIGN KEY (form_id) REFERENCES inc_forms(id),
  CONSTRAINT fk_inc_app_ver FOREIGN KEY (form_version_id) REFERENCES inc_form_versions(id),
  CONSTRAINT fk_inc_app_stage FOREIGN KEY (stage_id) REFERENCES inc_stages(id),
  CONSTRAINT fk_inc_app_startup FOREIGN KEY (startup_id) REFERENCES inc_startups(id) ON DELETE SET NULL
) $t",

/* One row per answered field, keyed by the field's stable uid. label is a
   snapshot of the wording the applicant saw. */
"CREATE TABLE IF NOT EXISTS inc_app_values (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id INT UNSIGNED NOT NULL,
  field_uid VARCHAR(40) NOT NULL,
  label VARCHAR(255) NOT NULL DEFAULT '',
  type VARCHAR(20) NOT NULL DEFAULT 'text',
  value_text MEDIUMTEXT NULL,
  value_short VARCHAR(191) NULL,
  file_id INT UNSIGNED NULL,
  UNIQUE KEY u_app_field (application_id, field_uid),
  KEY k_field_val (field_uid, value_short),
  CONSTRAINT fk_inc_av_app FOREIGN KEY (application_id) REFERENCES inc_applications(id) ON DELETE CASCADE
) $t",

"CREATE TABLE IF NOT EXISTS inc_app_changes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id INT UNSIGNED NOT NULL,
  field VARCHAR(120) NOT NULL,
  old_value TEXT NULL,
  new_value TEXT NULL,
  actor VARCHAR(190) NOT NULL DEFAULT '',
  reason VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_app (application_id, created_at)
) $t",

/* ----------------------------------------------------- shared record bits */

/* entity: application | workspace | evaluation. */
"CREATE TABLE IF NOT EXISTS inc_files (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entity VARCHAR(20) NOT NULL,
  entity_id INT UNSIGNED NOT NULL,
  label VARCHAR(190) NULL,
  stored_name VARCHAR(64) NOT NULL,
  original_name VARCHAR(190) NOT NULL,
  mime VARCHAR(120) NOT NULL,
  size INT UNSIGNED NOT NULL DEFAULT 0,
  uploaded_by VARCHAR(190) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_entity (entity, entity_id)
) $t",

"CREATE TABLE IF NOT EXISTS inc_notes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entity VARCHAR(20) NOT NULL,
  entity_id INT UNSIGNED NOT NULL,
  kind ENUM('note','comment','call','email') NOT NULL DEFAULT 'note',
  body TEXT NOT NULL,
  actor VARCHAR(190) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_entity (entity, entity_id, created_at)
) $t",

"CREATE TABLE IF NOT EXISTS inc_tasks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entity VARCHAR(20) NOT NULL,
  entity_id INT UNSIGNED NOT NULL,
  application_id INT UNSIGNED NULL,
  kind ENUM('task','follow_up','evaluation','reminder') NOT NULL DEFAULT 'task',
  title VARCHAR(255) NOT NULL,
  detail TEXT NULL,
  assignee_id INT UNSIGNED NULL,
  due_date DATE NULL,
  status ENUM('open','done','cancelled') NOT NULL DEFAULT 'open',
  created_by VARCHAR(190) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  done_at DATETIME NULL,
  done_by VARCHAR(190) NULL,
  KEY k_entity (entity, entity_id),
  KEY k_assignee (assignee_id, status, due_date)
) $t",

/* The one timeline every record shows. */
"CREATE TABLE IF NOT EXISTS inc_activities (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id INT UNSIGNED NULL,
  workspace_id INT UNSIGNED NULL,
  kind VARCHAR(40) NOT NULL,
  summary VARCHAR(500) NOT NULL,
  actor VARCHAR(190) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_app (application_id, created_at),
  KEY k_ws (workspace_id, created_at),
  KEY k_time (created_at)
) $t",

/* ------------------------------------------------------------- evaluation */

/* rules_json: the completion rules that applied to this version, frozen with
   it, so an approved evaluation is always judged by the rules it was made under. */
"CREATE TABLE IF NOT EXISTS inc_eval_templates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  version_no SMALLINT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  status ENUM('draft','published','retired') NOT NULL DEFAULT 'draft',
  rules_json TEXT NULL,
  note VARCHAR(500) NULL,
  created_by VARCHAR(190) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  published_at DATETIME NULL,
  published_by VARCHAR(190) NULL,
  UNIQUE KEY u_version (version_no)
) $t",

/* stage_weights_json: optional {dev_stage: weight} overrides; each stage's
   set must also total 100 to publish. guidance_json: {dev_stage: text}. */
"CREATE TABLE IF NOT EXISTS inc_eval_pillars (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  template_id INT UNSIGNED NOT NULL,
  code VARCHAR(30) NOT NULL,
  name VARCHAR(120) NOT NULL,
  description TEXT NULL,
  weight DECIMAL(6,2) NOT NULL DEFAULT 0,
  stage_weights_json VARCHAR(500) NULL,
  guidance_json TEXT NULL,
  sort SMALLINT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY u_tpl_code (template_id, code),
  CONSTRAINT fk_inc_ep_tpl FOREIGN KEY (template_id) REFERENCES inc_eval_templates(id) ON DELETE CASCADE
) $t",

"CREATE TABLE IF NOT EXISTS inc_eval_questions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  template_id INT UNSIGNED NOT NULL,
  uid VARCHAR(40) NOT NULL,
  pillar_code VARCHAR(30) NOT NULL,
  stages VARCHAR(120) NOT NULL DEFAULT 'all',
  text VARCHAR(500) NOT NULL,
  explanation TEXT NULL,
  guidance TEXT NULL,
  required TINYINT(1) NOT NULL DEFAULT 0,
  evidence ENUM('none','optional','required') NOT NULL DEFAULT 'optional',
  allow_na TINYINT(1) NOT NULL DEFAULT 1,
  applies_field VARCHAR(40) NULL,
  applies_value VARCHAR(190) NULL,
  sort SMALLINT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY u_tpl_uid (template_id, uid),
  KEY k_tpl_pillar (template_id, pillar_code, sort),
  CONSTRAINT fk_inc_eq_tpl FOREIGN KEY (template_id) REFERENCES inc_eval_templates(id) ON DELETE CASCADE
) $t",

/* result_json: the full calculation as saved — pillar ratings, the weights
   applied, counts and reasons — so an approved result never needs recomputing. */
"CREATE TABLE IF NOT EXISTS inc_evaluations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id INT UNSIGNED NOT NULL,
  startup_id INT UNSIGNED NULL,
  template_id INT UNSIGNED NOT NULL,
  dev_stage VARCHAR(20) NOT NULL,
  evaluator_id INT UNSIGNED NULL,
  evaluator_label VARCHAR(190) NOT NULL DEFAULT '',
  status ENUM('draft','in_progress','submitted','under_review','approved','returned') NOT NULL DEFAULT 'draft',
  strengths TEXT NULL,
  improvements TEXT NULL,
  summary TEXT NULL,
  next_eval_date DATE NULL,
  score DECIMAL(6,2) NULL,
  coverage DECIMAL(6,2) NULL,
  eligible TINYINT(1) NOT NULL DEFAULT 0,
  uniqueness_rating DECIMAL(5,3) NULL,
  result_json MEDIUMTEXT NULL,
  calc_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  submitted_at DATETIME NULL,
  review_started_at DATETIME NULL,
  reviewed_by VARCHAR(190) NULL,
  reviewed_at DATETIME NULL,
  review_note TEXT NULL,
  approved_at DATETIME NULL,
  created_by VARCHAR(190) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY k_app (application_id, status),
  KEY k_startup (startup_id, approved_at),
  KEY k_status (status),
  CONSTRAINT fk_inc_ev_app FOREIGN KEY (application_id) REFERENCES inc_applications(id) ON DELETE CASCADE,
  CONSTRAINT fk_inc_ev_tpl FOREIGN KEY (template_id) REFERENCES inc_eval_templates(id)
) $t",

"CREATE TABLE IF NOT EXISTS inc_eval_responses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  evaluation_id INT UNSIGNED NOT NULL,
  question_id INT UNSIGNED NOT NULL,
  status ENUM('pending','insufficient','rated','na') NOT NULL DEFAULT 'pending',
  rating TINYINT UNSIGNED NULL,
  comment TEXT NULL,
  evidence_url VARCHAR(500) NULL,
  evidence_file_id INT UNSIGNED NULL,
  auto_na TINYINT(1) NOT NULL DEFAULT 0,
  updated_by VARCHAR(190) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY u_eval_q (evaluation_id, question_id),
  CONSTRAINT fk_inc_er_ev FOREIGN KEY (evaluation_id) REFERENCES inc_evaluations(id) ON DELETE CASCADE,
  CONSTRAINT fk_inc_er_q FOREIGN KEY (question_id) REFERENCES inc_eval_questions(id)
) $t",

/* The Uniqueness & Differentiation pillar's own evidence record. */
"CREATE TABLE IF NOT EXISTS inc_eval_uniqueness (
  evaluation_id INT UNSIGNED NOT NULL PRIMARY KEY,
  competitors_json MEDIUMTEXT NULL,
  uvp TEXT NULL,
  evidence_links TEXT NULL,
  strengths TEXT NULL,
  concerns TEXT NULL,
  improvements TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_inc_eu_ev FOREIGN KEY (evaluation_id) REFERENCES inc_evaluations(id) ON DELETE CASCADE
) $t",

"CREATE TABLE IF NOT EXISTS inc_recommendations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id INT UNSIGNED NOT NULL,
  evaluation_id INT UNSIGNED NULL,
  startup_id INT UNSIGNED NULL,
  pillar_code VARCHAR(30) NULL,
  dev_stage VARCHAR(20) NULL,
  text TEXT NOT NULL,
  source ENUM('auto','manual') NOT NULL DEFAULT 'manual',
  owner_id INT UNSIGNED NULL,
  due_date DATE NULL,
  status ENUM('open','in_progress','done','dropped') NOT NULL DEFAULT 'open',
  progress_note VARCHAR(500) NULL,
  created_by VARCHAR(190) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY u_auto (evaluation_id, pillar_code, source),
  KEY k_app (application_id, status)
) $t",

/* --------------------------------------------------------- teams & work */

"CREATE TABLE IF NOT EXISTS inc_teams (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  description VARCHAR(255) NULL,
  kind VARCHAR(12) NOT NULL DEFAULT 'department',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY u_name (name)
) $t",

/* role: member (can own a startup), lead, evaluator, reviewer. A person may
   hold more than one role in a team, one row each. */
"CREATE TABLE IF NOT EXISTS inc_team_members (
  team_id INT UNSIGNED NOT NULL,
  person_id INT UNSIGNED NOT NULL,
  role ENUM('member','lead','evaluator','reviewer') NOT NULL DEFAULT 'member',
  added_by VARCHAR(190) NOT NULL DEFAULT '',
  added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (team_id, person_id, role),
  KEY k_person (person_id),
  CONSTRAINT fk_inc_tm_team FOREIGN KEY (team_id) REFERENCES inc_teams(id) ON DELETE CASCADE
) $t",

/* -------------------------------------------------------------- departments
   A department is an incubation team (inc_teams) seen from its own desk: its
   people are the organisation's existing team members, it keeps its own
   stages, and applications are handed to it and handed back
   (lib/inc/depts.php). */

"CREATE TABLE IF NOT EXISTS inc_dept_stages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  team_id INT UNSIGNED NOT NULL,
  name VARCHAR(80) NOT NULL,
  sort SMALLINT NOT NULL DEFAULT 0,
  is_final TINYINT(1) NOT NULL DEFAULT 0,
  gem_ids VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY u_team_name (team_id, name),
  KEY k_team_sort (team_id, sort)
) $t",

"CREATE TABLE IF NOT EXISTS inc_dept_assignments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id INT UNSIGNED NOT NULL,
  team_id INT UNSIGNED NOT NULL,
  stage_id INT UNSIGNED NULL,
  state ENUM('open','returned') NOT NULL DEFAULT 'open',
  note TEXT NULL,
  return_note TEXT NULL,
  assigned_by VARCHAR(190) NOT NULL DEFAULT '',
  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  returned_by VARCHAR(190) NULL,
  returned_at DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY u_app_team (application_id, team_id),
  KEY k_team_state (team_id, state)
) $t",

/* A team with no rows here may take any spoke. */
"CREATE TABLE IF NOT EXISTS inc_team_spokes (
  team_id INT UNSIGNED NOT NULL,
  spoke_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (team_id, spoke_id),
  CONSTRAINT fk_inc_ts_team FOREIGN KEY (team_id) REFERENCES inc_teams(id) ON DELETE CASCADE
) $t",

/* open_startup is the startup id while the workspace is open and NULL once
   it is closed: the unique key then allows exactly one open workspace per
   startup, and u_app exactly one workspace per application. */
"CREATE TABLE IF NOT EXISTS inc_workspaces (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ref VARCHAR(24) NULL,
  application_id INT UNSIGNED NOT NULL,
  startup_id INT UNSIGNED NULL,
  open_startup INT UNSIGNED NULL,
  team_id INT UNSIGNED NOT NULL,
  owner_id INT UNSIGNED NOT NULL,
  stage_id INT UNSIGNED NOT NULL,
  stage_since DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_at DATETIME NULL,
  target_date DATE NULL,
  last_reassigned_at DATETIME NULL,
  created_by VARCHAR(190) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY u_ref (ref),
  UNIQUE KEY u_app (application_id),
  UNIQUE KEY u_open_startup (open_startup),
  KEY k_owner (owner_id),
  KEY k_team (team_id),
  KEY k_stage (stage_id),
  CONSTRAINT fk_inc_ws_app FOREIGN KEY (application_id) REFERENCES inc_applications(id),
  CONSTRAINT fk_inc_ws_stage FOREIGN KEY (stage_id) REFERENCES inc_stages(id)
) $t",

/* Every assignment event. event_key makes a repeated request a no-op. */
"CREATE TABLE IF NOT EXISTS inc_assignments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  workspace_id INT UNSIGNED NOT NULL,
  application_id INT UNSIGNED NOT NULL,
  kind ENUM('assign','reassign') NOT NULL,
  team_id INT UNSIGNED NOT NULL,
  owner_id INT UNSIGNED NOT NULL,
  prev_team_id INT UNSIGNED NULL,
  prev_owner_id INT UNSIGNED NULL,
  stage_id_at INT UNSIGNED NULL,
  reason VARCHAR(500) NULL,
  event_key CHAR(40) NOT NULL,
  actor VARCHAR(190) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY u_event (event_key),
  KEY k_ws (workspace_id, created_at),
  KEY k_owner (owner_id, created_at),
  CONSTRAINT fk_inc_as_ws FOREIGN KEY (workspace_id) REFERENCES inc_workspaces(id)
) $t",

"CREATE TABLE IF NOT EXISTS inc_meetings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  workspace_id INT UNSIGNED NOT NULL,
  title VARCHAR(190) NOT NULL,
  met_on DATE NOT NULL,
  attendees VARCHAR(500) NULL,
  notes TEXT NULL,
  next_steps TEXT NULL,
  actor VARCHAR(190) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_ws (workspace_id, met_on),
  CONSTRAINT fk_inc_mt_ws FOREIGN KEY (workspace_id) REFERENCES inc_workspaces(id) ON DELETE CASCADE
) $t",

/* A lifecycle stage's checklist, and each workspace's ticks against it. */
"CREATE TABLE IF NOT EXISTS inc_checklist_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stage_id INT UNSIGNED NOT NULL,
  text VARCHAR(255) NOT NULL,
  sort SMALLINT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  KEY k_stage (stage_id, sort),
  CONSTRAINT fk_inc_ci_stage FOREIGN KEY (stage_id) REFERENCES inc_stages(id) ON DELETE CASCADE
) $t",

"CREATE TABLE IF NOT EXISTS inc_workspace_checks (
  workspace_id INT UNSIGNED NOT NULL,
  item_id INT UNSIGNED NOT NULL,
  done_by VARCHAR(190) NOT NULL DEFAULT '',
  done_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (workspace_id, item_id),
  CONSTRAINT fk_inc_wc_ws FOREIGN KEY (workspace_id) REFERENCES inc_workspaces(id) ON DELETE CASCADE,
  CONSTRAINT fk_inc_wc_item FOREIGN KEY (item_id) REFERENCES inc_checklist_items(id) ON DELETE CASCADE
) $t",

/* --------------------------------------------------------- notifications */

/* One row per (event, recipient, channel). event_key is unique, so the same
   business event can never notify the same person twice; a failed email
   keeps its rendered text here and is retried from this row. */
"CREATE TABLE IF NOT EXISTS inc_notify_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_key VARCHAR(120) NOT NULL,
  event VARCHAR(40) NOT NULL,
  channel ENUM('portal','email') NOT NULL,
  recipient_id INT UNSIGNED NULL,
  recipient_email VARCHAR(190) NULL,
  subject VARCHAR(255) NOT NULL DEFAULT '',
  body_text MEDIUMTEXT NULL,
  status ENUM('sent','queued','failed','skipped') NOT NULL,
  detail VARCHAR(500) NULL,
  outbox_id INT UNSIGNED NULL,
  notice_id INT UNSIGNED NULL,
  application_id INT UNSIGNED NULL,
  workspace_id INT UNSIGNED NULL,
  tries SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_try_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY u_event (event_key, channel),
  KEY k_status (status, created_at),
  KEY k_app (application_id),
  KEY k_ws (workspace_id)
) $t",

"CREATE TABLE IF NOT EXISTS inc_mail_templates (
  event VARCHAR(40) NOT NULL PRIMARY KEY,
  subject VARCHAR(255) NOT NULL,
  body TEXT NOT NULL,
  email_on TINYINT(1) NOT NULL DEFAULT 1,
  portal_on TINYINT(1) NOT NULL DEFAULT 1,
  updated_by VARCHAR(190) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) $t",

/* ---------------------------------------------------------------- screening */

"CREATE TABLE IF NOT EXISTS inc_screen_presets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  criteria_json TEXT NOT NULL,
  created_by VARCHAR(190) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY u_name (name)
) $t",

"CREATE TABLE IF NOT EXISTS inc_bulk_runs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  action VARCHAR(40) NOT NULL,
  params_json TEXT NULL,
  total INT UNSIGNED NOT NULL DEFAULT 0,
  done INT UNSIGNED NOT NULL DEFAULT 0,
  skipped INT UNSIGNED NOT NULL DEFAULT 0,
  failed INT UNSIGNED NOT NULL DEFAULT 0,
  details_json MEDIUMTEXT NULL,
  actor VARCHAR(190) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_time (created_at)
) $t",

    ];
}

/**
 * Creates the tables (once per request, per organisation) and seeds defaults
 * into empty ones. A client organisation's set is inc_o<id>_* (lib/inc/org.php).
 */
function inc_install(): void
{
    static $done = [];
    if (isset($done[inc_scope_key()])) {
        return;
    }
    $done[inc_scope_key()] = true;
    try {
        $have = inc_one("SELECT v FROM inc_settings WHERE k = '_schema'");
    } catch (Throwable $e) {
        $have = null;   // first run: the settings table itself is missing
    }
    if ($have !== null && (int) $have['v'] >= INC_SCHEMA_VERSION) {
        return;
    }
    $db = fabo_db();
    foreach (inc_ddl() as $sql) {
        $db->query(inc_sql($sql));
    }
    inc_seed_defaults();
    inc_dept_stage_upgrade();
    inc_event_name_upgrade();
    inc_form_dept_upgrade();
    inc_dept_kind_upgrade();
    inc_form_steps_upgrade();
    inc_applicant_type_upgrade();
    require_once __DIR__ . '/gems.php';
    inc_gem_upgrade();
    inc_gem_seed_default();
    require_once __DIR__ . '/certs.php';
    inc_cert_upgrade();
    inc_exec("INSERT INTO inc_settings (k, v) VALUES ('_schema', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)",
              's', [(string) INC_SCHEMA_VERSION]);
}

/**
 * The event or program a form collects applications for (inc_forms), and the
 * name each application was submitted under (inc_applications) -- kept on the
 * application so filtering and reports still read right after the form is
 * renamed for its next edition.
 */
function inc_event_name_upgrade(): void
{
    $db = fabo_db();
    foreach (['inc_forms', 'inc_applications'] as $table) {
        $tbl = inc_sql($table);
        if (!$db->query("SHOW COLUMNS FROM `$tbl` LIKE 'event_name'")->num_rows) {
            $db->query("ALTER TABLE `$tbl` ADD COLUMN event_name VARCHAR(190) NULL AFTER purpose"
                     . ($table === 'inc_applications' ? ', ADD KEY k_event (event_name)' : ''));
        }
    }
}

/** The applicant types every organisation starts with; renamed, added to or retired under Settings → Lists. */
function inc_default_applicant_types(): array
{
    return ['Student', 'Startup', 'Mentor', 'Partner', 'Investor', 'Other'];
}

/**
 * Who an application is from -- Student, Startup, Mentor, Partner... -- as an
 * administrator-managed list (inc_masters kind applicant_type): on each
 * application (applicant_type_id), and a default per form (default_type_id)
 * recorded on everything that form receives.
 */
function inc_applicant_type_upgrade(): void
{
    $db = fabo_db();
    $m = inc_sql('inc_masters');
    $kind = $db->query("SHOW COLUMNS FROM `$m` LIKE 'kind'")->fetch_assoc();
    if ($kind && !str_contains((string) $kind['Type'], 'applicant_type')) {
        $db->query("ALTER TABLE `$m` MODIFY kind ENUM('spoke','sector','source','applicant_type') NOT NULL");
    }
    $apps = inc_sql('inc_applications');
    if (!$db->query("SHOW COLUMNS FROM `$apps` LIKE 'applicant_type_id'")->num_rows) {
        $db->query("ALTER TABLE `$apps` ADD COLUMN applicant_type_id INT UNSIGNED NULL AFTER source_id, ADD KEY k_type (applicant_type_id)");
    }
    $forms = inc_sql('inc_forms');
    if (!$db->query("SHOW COLUMNS FROM `$forms` LIKE 'default_type_id'")->num_rows) {
        $db->query("ALTER TABLE `$forms` ADD COLUMN default_type_id INT UNSIGNED NULL AFTER default_source_id");
    }
    if (!inc_one("SELECT id FROM inc_masters WHERE kind = 'applicant_type' LIMIT 1")) {
        foreach (inc_default_applicant_types() as $i => $name) {
            inc_exec("INSERT INTO inc_masters (kind, name, sort) VALUES ('applicant_type',?,?)", 'si', [$name, $i + 1]);
        }
    }
}

/**
 * A form shown step by step (inc_forms.stepwise): each section heading starts
 * a step of its own, with Back / Next (assets/inc.js). Off by default.
 */
function inc_form_steps_upgrade(): void
{
    $db = fabo_db();
    $tbl = inc_sql('inc_forms');
    if (!$db->query("SHOW COLUMNS FROM `$tbl` LIKE 'stepwise'")->num_rows) {
        $db->query("ALTER TABLE `$tbl` ADD COLUMN stepwise TINYINT(1) NOT NULL DEFAULT 0");
    }
}

/**
 * The department a form belongs to (inc_forms.dept_team_id): NULL for the
 * incubation team's own forms. A department's form sends every submission
 * straight to that department's desk (inc_dept_route_app()).
 */
function inc_form_dept_upgrade(): void
{
    $db = fabo_db();
    $tbl = inc_sql('inc_forms');
    if (!$db->query("SHOW COLUMNS FROM `$tbl` LIKE 'dept_team_id'")->num_rows) {
        $db->query("ALTER TABLE `$tbl` ADD COLUMN dept_team_id INT UNSIGNED NULL AFTER event_name, ADD KEY k_dept (dept_team_id)");
    }
}

/**
 * What a department is (inc_teams.kind): a department, a mentor panel or a
 * jury -- the same tables and desk, each kind with a desk of its own -- and
 * which Gems' scores a department's own stage shows (inc_dept_stages.gem_ids),
 * set by the department on its board as the main pipeline does (13).
 */
function inc_dept_kind_upgrade(): void
{
    $db = fabo_db();
    $t = inc_sql('inc_teams');
    if (!$db->query("SHOW COLUMNS FROM `$t` LIKE 'kind'")->num_rows) {
        $db->query("ALTER TABLE `$t` ADD COLUMN kind VARCHAR(12) NOT NULL DEFAULT 'department' AFTER description, ADD KEY k_kind (kind)");
    }
    $s = inc_sql('inc_dept_stages');
    if (!$db->query("SHOW COLUMNS FROM `$s` LIKE 'gem_ids'")->num_rows) {
        $db->query("ALTER TABLE `$s` ADD COLUMN gem_ids VARCHAR(255) NULL AFTER is_final");
    }
}

/**
 * The two application-pipeline stages the department hand-off moves through,
 * added to a pipeline that does not have them yet (new or existing), right
 * after Initial Screening. Never touches a stage that is already there, so an
 * administrator's renames stand.
 */
function inc_dept_stage_upgrade(): void
{
    if (inc_setting('_dept_stages_added') === '1') {
        return;
    }
    inc_exec("INSERT INTO inc_settings (k, v) VALUES ('_dept_stages_added', '1') ON DUPLICATE KEY UPDATE v = '1'");
    $want = ['dept_assigned' => 'Assigned to Departments', 'reassigned' => 'Re-assigned Application'];
    $missing = [];
    foreach ($want as $code => $name) {
        if (!inc_one("SELECT id FROM inc_stages WHERE pipeline = 'crm' AND code = ?", 's', [$code])) {
            $missing[$code] = $name;
        }
    }
    if (!$missing) {
        return;
    }
    $after = inc_one("SELECT sort FROM inc_stages WHERE pipeline = 'crm' AND code = 'screening'")
          ?: inc_one("SELECT MAX(sort) AS sort FROM inc_stages WHERE pipeline = 'crm'");
    $at = (int) ($after['sort'] ?? 0);
    inc_exec("UPDATE inc_stages SET sort = sort + ? WHERE pipeline = 'crm' AND sort > ?", 'ii', [count($missing), $at]);
    foreach ($missing as $code => $name) {
        $n = $name;
        // A stage the administrator already called this keeps its name; ours gets a code-free twin name.
        if (inc_one("SELECT id FROM inc_stages WHERE pipeline = 'crm' AND name = ?", 's', [$n])) {
            inc_exec("UPDATE inc_stages SET code = ? WHERE pipeline = 'crm' AND name = ? AND code IS NULL", 'ss', [$code, $n]);
            continue;
        }
        inc_exec("INSERT INTO inc_stages (pipeline, code, name, description, sort, is_terminal, notify) VALUES ('crm',?,?,?,?,0,0)", 'sssi',
                 [$code, $n, $code === 'dept_assigned' ? 'Handed to one or more departments; they work it in their own stages.'
                                                       : 'A department handed it back to the incubation team.', ++$at]);
    }
}

/* ================================================================ defaults */

function inc_default_dev_stages(): array
{
    return [
        'idea'         => ['Idea Stage', 'A problem and a proposed solution; little or no product yet. Assessed on clarity, research and a realistic next step — not on revenue, customers, funding, registration, patents or employees.'],
        'validation'   => ['Validation Stage', 'Testing the problem and solution with potential customers. Assessed on evidence of need, interviews and early competitive understanding.'],
        'prototype'    => ['Prototype / MVP Stage', 'A working prototype or MVP exists. Assessed on development, testing and feedback, not on commercial customers.'],
        'early_market' => ['Early Market Stage', 'Launched or piloting. Assessed on pilots, first customers and testing of pricing and the revenue model.'],
        'growth'       => ['Growth Stage', 'Repeat customers and revenue. Assessed on repeatability, retention, revenue trends, efficiency and scalability.'],
    ];
}

function inc_default_crm_stages(): array
{
    // code => [name, terminal, requires, notify]
    return [
        // Two to start with; everything after Gem 1 is added on the board as each organisation needs it.
        'new'           => ['New Applications', 0, null, 0],
        'gem1'          => ['Gem 1 Scoring', 0, null, 0],
    ];
}

function inc_default_lifecycle_stages(): array
{
    // code => [name, terminal, requires, notify, checklist]
    return [
        'assigned'        => ['Assigned Application', 0, null, 1, ['Owner has reviewed the application', 'First contact made with the founder']],
        'initial_review'  => ['Initial Review', 0, null, 1, ['Evaluation results reviewed with the team']],
        'founder_meeting' => ['Founder Meeting', 0, null, 1, ['Meeting held and recorded', 'Next steps agreed with the founder']],
        'due_diligence'   => ['Due Diligence', 0, null, 1, ['Founder identity and documents verified', 'Claims checked against evidence']],
        'onboarding'      => ['Incubation Onboarding', 0, null, 1, ['Incubation agreement signed', 'Workspace and resources allocated']],
        'active'          => ['Active Incubation', 0, null, 1, []],
        'mentorship'      => ['Mentorship and Support', 0, null, 1, ['Mentor assigned']],
        'progress_review' => ['Progress Review', 0, null, 1, ['Milestones reviewed', 'Re-evaluation scheduled if due']],
        'completed'       => ['Completed', 1, null, 1, ['Exit review recorded']],
        'on_hold'         => ['On Hold', 0, ['reason' => true], 1, []],
        'closed'          => ['Rejected or Closed', 1, ['reason' => true], 1, []],
    ];
}

/**
 * The nine pillars and their default weights. They total exactly 100.
 * code => [name, weight, description, [stage => expectation]]
 */
function inc_default_pillars(): array
{
    return [
        'problem_solution' => ['Problem & Solution', 14,
            'Problem clarity, customer pain points, the proposed solution, its relevance, and understanding of the target customer.',
            ['idea' => 'A clearly stated problem and an understandable proposed solution, backed by initial research.',
             'validation' => 'The problem has been confirmed with potential customers; the solution responds to what they said.',
             'prototype' => 'The prototype addresses the validated problem; the solution is refined by feedback.',
             'early_market' => 'Customers confirm the solution solves the problem in practice.',
             'growth' => 'The solution keeps solving the problem as the customer base widens.']],
        'product' => ['Product Development', 14,
            'Concept and design, prototype or MVP, testing and feedback, functionality, and readiness for the current stage.',
            ['idea' => 'A concept or sketch of the product and a plan to build a first version. No product is expected.',
             'validation' => 'Mock-ups or a concept test; a plan for a first prototype.',
             'prototype' => 'A working prototype or MVP that has been tested with users.',
             'early_market' => 'A product in use by pilot users or customers, being improved from feedback.',
             'growth' => 'A stable product with a roadmap and a reliable release process.']],
        'market' => ['Market Opportunity', 14,
            'Target customers, market research, customer needs, competitors and alternatives, and market size where evidence exists.',
            ['idea' => 'Target customer identified; initial desk research on the market and alternatives.',
             'validation' => 'Competitive landscape understood; customer segments being tested.',
             'prototype' => 'A defined target segment and an informed view of market size.',
             'early_market' => 'An initial market foothold and a clear go-to-market channel.',
             'growth' => 'Evidence of a large enough market and a path into adjacent segments.']],
        'team' => ['Team & Execution', 14,
            'Founder commitment, relevant skills, role clarity, execution planning and the ability to make progress. A solo founder is not penalised for having no team yet.',
            ['idea' => 'Founder commitment and a realistic next-step plan. A solo founder is fine.',
             'validation' => 'Skills needed for validation are covered or planned; progress since the idea is visible.',
             'prototype' => 'Product-building skills are in place; product risks are understood and owned.',
             'early_market' => 'Clear roles for delivery, sales and support; execution against plan.',
             'growth' => 'A team and processes that let operations scale efficiently.']],
        'business_model' => ['Business Model', 9,
            'Value proposition, business-model clarity, revenue possibilities, pricing approach, and understanding of costs.',
            ['idea' => 'A value proposition and initial thoughts on who would pay. No revenue is expected.',
             'validation' => 'Business model options being explored with potential customers.',
             'prototype' => 'A preferred model and a pricing hypothesis ready to test.',
             'early_market' => 'Pricing and the revenue model being tested with real customers.',
             'growth' => 'A repeatable model with known unit economics.']],
        'financial' => ['Financial Readiness', 9,
            'Estimated costs, financial planning, funding requirements, resource planning and stage-appropriate financial management. Revenue or funding is not expected at the idea stage.',
            ['idea' => 'An estimate of what the next step costs. Revenue or funding received is not expected.',
             'validation' => 'A simple budget for validation and a view of funding needs.',
             'prototype' => 'Costs to build and test known; funding plan for the next phase.',
             'early_market' => 'Basic bookkeeping; revenue and costs tracked against plan.',
             'growth' => 'Revenue performance tracked; financial controls and funding strategy in place.']],
        'traction' => ['Traction & Validation', 9,
            'Customer interviews, market research, feedback, prototype testing, pilots, adoption, retention and sales where relevant.',
            ['idea' => 'Initial research or conversations. Customers or sales are not expected.',
             'validation' => 'Customer interviews held; evidence of need collected.',
             'prototype' => 'User testing of the prototype with feedback recorded.',
             'early_market' => 'Pilots, first customers or initial sales.',
             'growth' => 'Repeat customers, retention and growing sales.']],
        'growth_impact' => ['Growth & Impact', 9,
            'Growth roadmap, scalability, business/social/economic/environmental impact, measurable outcomes, and stage-appropriate expansion plans.',
            ['idea' => 'An understanding of the potential impact and who benefits.',
             'validation' => 'Early view of how the startup could scale and what impact it measures.',
             'prototype' => 'Milestones from prototype to market defined.',
             'early_market' => 'Plans for customer acquisition and retention; impact being measured.',
             'growth' => 'A measurable growth and expansion plan with tracked outcomes.']],
        'uniqueness' => ['Uniqueness & Differentiation', 8,
            'Whether the startup has a meaningful, evidence-supported distinction from existing products, services, business models or alternatives: problem and solution differentiation, competitor understanding, a unique value proposition, evidence, and defensibility. Patents, proprietary technology or a new market category are not required — serving a customer group better, more affordably, more accessibly or with a better experience counts.',
            ['idea' => 'Existing alternatives identified and a clear, testable differentiation hypothesis.',
             'validation' => 'Solution compared with alternatives; customers say which differences matter; hypothesis tested.',
             'prototype' => 'The prototype demonstrates the differentiating feature and it has been tested with users.',
             'early_market' => 'Pilot users or customers recognise the difference; evidence of preference or adoption.',
             'growth' => 'Differentiation maintained; evidence of a repeatable, sustainable competitive advantage.']],
    ];
}

/**
 * The baseline question bank: pillar => stage => [[text, explanation, guidance, required, evidence, allow_na], ...].
 * Every pillar has questions at every stage. Questions that an early stage
 * cannot be expected to meet allow N/A rather than a low rating.
 */
function inc_default_questions(): array
{
    $Q = static fn(string $t, string $x, string $g, int $req = 1, string $ev = 'optional', int $na = 0) => [$t, $x, $g, $req, $ev, $na];
    return [
        'problem_solution' => [
            'idea' => [
                $Q('Is the problem clearly identified?', 'Can the founder state the problem in one or two sentences, and who has it?', 'Rate 3 when the problem and who has it are clear; 5 when it is specific, well-evidenced by initial research, and sized.'),
                $Q('Is the proposed solution understandable?', 'Would a potential customer understand what the solution does?', 'Rate on clarity and relevance to the problem, not on whether it is built.'),
                $Q('Are the customer pain points described?', 'Does the founder describe what hurts today and why?', 'Specific pain points from conversations or research rate higher than assumptions.', 0),
            ],
            'validation' => [
                $Q('Is there evidence of customer need?', 'Evidence such as interview notes, surveys or sign-ups showing the problem is real.', 'Rate 3 for a handful of consistent interviews; 5 for strong, documented evidence across the target segment.', 1, 'required'),
                $Q('Has the solution been adjusted to what customers said?', 'Has validation changed or confirmed the solution?', 'Rate on how well the solution responds to validated need.'),
            ],
            'prototype' => [
                $Q('Does the prototype address the validated problem?', 'Is what was built aimed at the problem customers confirmed?', 'Rate on alignment between problem evidence and the prototype.'),
                $Q('Is the target customer well understood?', 'Personas, segments, buying context.', 'Clear personas grounded in testing rate higher.'),
            ],
            'early_market' => [
                $Q('Do customers confirm the solution solves their problem?', 'Testimonials, usage, renewals or pilot results.', 'Rate on real-world evidence from pilots or customers.', 1, 'required'),
                $Q('Is the solution still relevant as the market responds?', 'Has the founder adapted to what the market said?', 'Rate on responsiveness and continued fit.', 0),
            ],
            'growth' => [
                $Q('Does the solution keep solving the problem at scale?', 'Is quality and fit maintained as customers increase?', 'Rate on retention, satisfaction and fit across segments.'),
                $Q('Is the problem understood in new segments the startup enters?', 'Expansion grounded in understanding of new customers.', 'Rate on evidence of fit in each new segment.', 0, 'optional', 1),
            ],
        ],
        'product' => [
            'idea' => [
                $Q('Is there a concept or design for the product?', 'Sketches, flows or a written concept. A built product is not expected.', 'Rate on clarity of the concept for an idea-stage startup.'),
                $Q('Is there a realistic plan to build a first version?', 'What would be built first, how, and with what resources?', 'Rate on realism of the plan.', 0),
            ],
            'validation' => [
                $Q('Has the proposed solution been validated?', 'Concept tests, mock-ups or demos shown to potential users.', 'Rate on the quality of the validation activity and what was learned.'),
                $Q('Is there a plan for a first prototype?', 'Scope, timeline and resources for a prototype.', 'Rate on clarity and realism.', 0),
            ],
            'prototype' => [
                $Q('Has a prototype or MVP been developed?', 'A working version users can try.', 'Rate 3 for a basic working prototype; 5 for a well-functioning MVP covering the core use case.', 1, 'required'),
                $Q('Has the prototype been tested?', 'Usability or functional testing with real users.', 'Rate on how many users, how structured, and what was learned.'),
                $Q('Are key product risks understood?', 'Technical, usability, regulatory or supply risks.', 'Rate on how well risks are identified and mitigated.'),
                $Q('Is there a plan for improving the product?', 'A prioritised roadmap from feedback.', 'Rate on clarity and grounding in feedback.', 0),
            ],
            'early_market' => [
                $Q('Is customer feedback being addressed in the product?', 'Feedback loop from pilots and customers into releases.', 'Rate on evidence of iterations driven by feedback.'),
                $Q('Is the product reliable enough for paying customers?', 'Stability, support and quality.', 'Rate on reliability evidence.'),
            ],
            'growth' => [
                $Q('Is the product stable with a clear roadmap?', 'Release process, quality and a forward plan.', 'Rate on maturity of product management.'),
                $Q('Can the product scale technically?', 'Architecture, operations and support capacity.', 'Rate on evidence the product can handle growth.', 0),
            ],
        ],
        'market' => [
            'idea' => [
                $Q('Is the target customer identified?', 'Who exactly would use and pay for this?', 'Rate on specificity of the target customer.'),
                $Q('Has initial market research been done?', 'Desk research on the market and alternatives.', 'Rate on quality and relevance of the research.', 0),
            ],
            'validation' => [
                $Q('Is the competitive landscape understood?', 'Competitors, substitutes and alternatives.', 'Rate on breadth and accuracy of the landscape.'),
                $Q('Are customer segments being tested?', 'Which segments respond best?', 'Rate on evidence about segments.', 0),
            ],
            'prototype' => [
                $Q('Is the target segment defined?', 'A clear first segment to enter.', 'Rate on clarity and evidence.'),
                $Q('Is there an informed view of market size?', 'Bottom-up sizing where evidence exists.', 'Rate on method and evidence, not on how large the number is.', 0, 'optional', 1),
            ],
            'early_market' => [
                $Q('Is there a clear go-to-market channel?', 'How customers are reached and acquired.', 'Rate on evidence the channel works.'),
                $Q('Is the startup gaining a foothold in its segment?', 'Share of first segment, references.', 'Rate on market evidence.', 0),
            ],
            'growth' => [
                $Q('Is the market large enough for continued growth?', 'Evidence-based sizing and trends.', 'Rate on evidence of headroom.'),
                $Q('Is there a path into adjacent segments or regions?', 'Expansion opportunities.', 'Rate on evidence-backed expansion paths.', 0),
            ],
        ],
        'team' => [
            'idea' => [
                $Q('Does the founder have a realistic next-step plan?', 'What happens in the next 3 months, and how.', 'Rate on realism and specificity.'),
                $Q('Is the founder committed and able to make progress?', 'Time committed and progress so far. A solo founder is not penalised.', 'Rate on commitment and progress, not team size.'),
            ],
            'validation' => [
                $Q('Are the skills needed for validation covered or planned?', 'Through the founder, co-founders, advisors or partners.', 'Rate on coverage of needed skills, however sourced.'),
                $Q('Has the team made visible progress since the idea?', 'Milestones achieved.', 'Rate on progress relative to time and resources.'),
            ],
            'prototype' => [
                $Q('Are product-building skills in place?', 'In-house or reliably sourced.', 'Rate on capability to build and iterate.'),
                $Q('Are roles clear?', 'Who does what.', 'Rate on clarity. A solo founder with clear outsourcing can rate well.', 0),
            ],
            'early_market' => [
                $Q('Are delivery, sales and support roles clear?', 'Ownership of the critical functions.', 'Rate on clarity and coverage.'),
                $Q('Is execution tracking against plan?', 'Plans versus results.', 'Rate on execution evidence.'),
            ],
            'growth' => [
                $Q('Are operations becoming more efficient?', 'Processes, automation, cost per unit.', 'Rate on evidence of improving efficiency.'),
                $Q('Is there a hiring and leadership plan for scale?', 'Key hires and structure.', 'Rate on realism and progress.', 0),
            ],
        ],
        'business_model' => [
            'idea' => [
                $Q('Is the value proposition clear?', 'Why would a customer choose this?', 'Rate on clarity. No revenue is expected.'),
                $Q('Are possible revenue sources identified?', 'Who might pay, and for what.', 'Rate on thoughtfulness of options.', 0),
            ],
            'validation' => [
                $Q('Is the business model being explored?', 'Options discussed with potential customers.', 'Rate on evidence from exploration.'),
                $Q('Are the main business costs understood?', 'What it costs to deliver.', 'Rate on understanding of costs.', 0),
            ],
            'prototype' => [
                $Q('Is there a preferred business model with a pricing hypothesis?', 'A model ready to test.', 'Rate on clarity and grounding.'),
                $Q('Is willingness to pay being explored?', 'Pre-orders, letters of intent, pricing interviews.', 'Rate on evidence.', 0, 'optional', 1),
            ],
            'early_market' => [
                $Q('Are pricing and the revenue model being tested?', 'Real prices with real customers.', 'Rate on the quality of testing and what was learned.', 1, 'required'),
                $Q('Are unit costs known?', 'Cost to serve a customer.', 'Rate on evidence.', 0),
            ],
            'growth' => [
                $Q('Is the business model repeatable?', 'Predictable acquisition, delivery and revenue.', 'Rate on evidence of repeatability.'),
                $Q('Are unit economics positive or improving?', 'Margins, CAC and lifetime value.', 'Rate on trend and evidence.'),
            ],
        ],
        'financial' => [
            'idea' => [
                $Q('Has the founder estimated the costs of the next step?', 'A rough budget for validation. Revenue or funding received is not expected.', 'Rate on realism of the estimate.'),
                $Q('Are resource needs understood?', 'Time, people, money, equipment.', 'Rate on understanding.', 0),
            ],
            'validation' => [
                $Q('Is there a simple budget for validation?', 'Costs to reach the next milestone.', 'Rate on clarity and realism.'),
                $Q('Are funding requirements understood?', 'How much, for what, from where.', 'Rate on understanding, not on funding raised.', 0),
            ],
            'prototype' => [
                $Q('Are the costs to build and test known?', 'Prototype and testing budget.', 'Rate on accuracy and tracking.'),
                $Q('Is there a funding plan for the next phase?', 'Grants, investors, revenue or own funds.', 'Rate on realism.'),
            ],
            'early_market' => [
                $Q('Are revenue and costs tracked?', 'Basic bookkeeping and reporting.', 'Rate on practice and accuracy.', 1, 'optional', 1),
                $Q('Is cash runway understood?', 'Months of operation funded.', 'Rate on clarity and planning.'),
            ],
            'growth' => [
                $Q('Is revenue performance being tracked?', 'Monthly revenue, trends, forecasts.', 'Rate on rigour and trend.', 1, 'required'),
                $Q('Are financial controls and a funding strategy in place?', 'Controls, compliance, capital plan.', 'Rate on maturity.'),
            ],
        ],
        'traction' => [
            'idea' => [
                $Q('Has initial research been conducted?', 'Desk research or early conversations.', 'Rate on quality of research. Customers or sales are not expected.'),
                $Q('Have potential customers been spoken to?', 'Even informal conversations count.', 'Rate on number and relevance of conversations.', 0, 'optional', 1),
            ],
            'validation' => [
                $Q('Has the problem been discussed with potential customers?', 'Structured interviews or surveys.', 'Rate 3 for ~10 relevant interviews; 5 for a substantial, documented set.', 1, 'required'),
                $Q('Has feedback been recorded and analysed?', 'Notes and conclusions.', 'Rate on rigour.', 0),
            ],
            'prototype' => [
                $Q('Has feedback on the prototype been collected?', 'From user testing.', 'Rate on quantity, quality and use of feedback.', 1, 'required'),
                $Q('Are there early adopters or a waitlist?', 'Sign-ups, pilots agreed.', 'Rate on evidence of interest.', 0, 'optional', 1),
            ],
            'early_market' => [
                $Q('Has the solution been launched or piloted?', 'Live with real users.', 'Rate on scale and quality of the launch or pilot.', 1, 'required'),
                $Q('Are there active users or customers?', 'Usage and customer count.', 'Rate on evidence of active use.'),
            ],
            'growth' => [
                $Q('Are customers returning or renewing?', 'Retention, repeat purchase, renewals.', 'Rate on retention evidence.', 1, 'required'),
                $Q('Are sales growing?', 'Sales trend over recent periods.', 'Rate on trend and consistency.'),
            ],
        ],
        'growth_impact' => [
            'idea' => [
                $Q('Is the potential impact understood?', 'Business, social, economic or environmental.', 'Rate on clarity of who benefits and how.'),
                $Q('Is there an early view of how the idea could scale?', 'A rough path beyond the first customers.', 'Rate on thoughtfulness, not certainty.', 0),
            ],
            'validation' => [
                $Q('Are measurable outcomes defined?', 'What will be measured to show impact.', 'Rate on clarity.'),
                $Q('Is scalability considered in the solution design?', 'Could it serve many more customers?', 'Rate on consideration.', 0),
            ],
            'prototype' => [
                $Q('Are milestones from prototype to market defined?', 'Timeline and targets.', 'Rate on clarity and realism.'),
                $Q('Is impact being measured in testing?', 'Outcomes recorded in pilots or tests.', 'Rate on practice.', 0, 'optional', 1),
            ],
            'early_market' => [
                $Q('Are there plans for customer acquisition and retention?', 'Channels, targets and retention tactics.', 'Rate on clarity and evidence.'),
                $Q('Is impact being measured with customers?', 'Outcome data from real use.', 'Rate on rigour.', 0),
            ],
            'growth' => [
                $Q('Is there a measurable growth and expansion plan?', 'Targets, markets and resources.', 'Rate on clarity, ambition and realism.'),
                $Q('Are impact outcomes tracked and reported?', 'Regular impact reporting.', 'Rate on practice and results.', 0),
            ],
        ],
        'uniqueness' => [
            'idea' => [
                $Q('Has the founder identified existing alternatives?', 'Competitors, substitutes, workarounds customers use today.', 'Rate on breadth and accuracy. "No competitors" usually means alternatives have not been looked for.'),
                $Q('Can the founder explain the proposed difference?', 'What would be different from those alternatives, and for whom.', 'Rate on clarity. A claim alone is not evidence; rate the explanation.'),
                $Q('Is the differentiation hypothesis clear and testable?', 'Could the claimed difference be tested with customers?', 'Rate on how specific and testable it is.'),
            ],
            'validation' => [
                $Q('Has the founder compared the proposed solution with alternatives?', 'A structured comparison, not only a claim.', 'Rate on rigour of the comparison.', 1, 'required'),
                $Q('Have potential customers said which differences matter to them?', 'From interviews or surveys.', 'Rate on evidence from customers.'),
                $Q('Has the differentiation hypothesis been tested?', 'A test designed and run.', 'Rate on test quality and result.'),
            ],
            'prototype' => [
                $Q('Does the prototype demonstrate the claimed differentiating feature?', 'Can it be seen working?', 'Rate on whether the difference is real in the prototype.', 1, 'required'),
                $Q('Has the difference been tested with potential users?', 'User testing of the differentiator.', 'Rate on testing evidence.'),
                $Q('Is there evidence the differentiation matters to the target customer?', 'Preference, feedback, willingness to switch.', 'Rate on strength of evidence.'),
            ],
            'early_market' => [
                $Q('Do pilot users or customers recognise the claimed difference?', 'Unprompted mentions, feedback.', 'Rate on evidence from users.', 1, 'required'),
                $Q('Is there evidence of customer preference or adoption because of it?', 'Choice over alternatives, switching.', 'Rate on strength of evidence.'),
                $Q('Is the startup improving its position relative to alternatives?', 'Responding to competitors.', 'Rate on trend.'),
            ],
            'growth' => [
                $Q('Is the startup maintaining meaningful differentiation?', 'Has the difference held as competitors respond?', 'Rate on evidence over time.'),
                $Q('Is there evidence of a repeatable competitive advantage?', 'Wins, retention or margins attributable to it.', 'Rate on evidence.', 1, 'required'),
                $Q('Can the position be sustained?', 'Through execution, technology, distribution, relationships or another advantage. Patents are not required.', 'Rate on durability of the advantage.'),
            ],
        ],
    ];
}

/** Recommended next actions per pillar and stage (used for low-rated pillars). */
function inc_default_recommendations(): array
{
    return [
        'problem_solution' => ['idea' => 'Conduct interviews with at least 10 potential customers to confirm the problem and how they describe it.',
            'validation' => 'Document the evidence of need and refine the solution to what interviews revealed.',
            'prototype' => 'Check the prototype against the validated problem; drop features that do not address it.',
            'early_market' => 'Collect structured outcome evidence from pilot customers that the problem is solved.',
            'growth' => 'Track fit and satisfaction by segment as the customer base grows.'],
        'product' => ['idea' => 'Sketch the core user flow and define what a first, minimal prototype would include.',
            'validation' => 'Build and test a basic prototype or mock-up with potential users.',
            'prototype' => 'Run structured user tests on the MVP and prioritise fixes to key product risks.',
            'early_market' => 'Set up a regular feedback-to-release loop and improve reliability for paying customers.',
            'growth' => 'Formalise the product roadmap and invest in scalability and release quality.'],
        'market' => ['idea' => 'Research competitors and alternatives and define the target market precisely.',
            'validation' => 'Map the competitive landscape and test which customer segment responds best.',
            'prototype' => 'Define the first segment to enter and estimate its size bottom-up.',
            'early_market' => 'Prove one repeatable go-to-market channel in the first segment.',
            'growth' => 'Evaluate adjacent segments or regions with evidence before expanding.'],
        'team' => ['idea' => 'Identify skill gaps and prepare a 3-month execution plan with clear milestones.',
            'validation' => 'Cover the skills validation needs through co-founders, advisors or partners.',
            'prototype' => 'Clarify roles and secure product-building capacity.',
            'early_market' => 'Assign clear owners for delivery, sales and support and track execution weekly.',
            'growth' => 'Plan key hires and processes that let operations scale efficiently.'],
        'business_model' => ['idea' => 'Explore pricing and revenue options and who would pay.',
            'validation' => 'Test business-model options in customer conversations and list main costs.',
            'prototype' => 'Choose a model and test willingness to pay with a pricing hypothesis.',
            'early_market' => 'Test pricing with real customers and calculate cost to serve.',
            'growth' => 'Track unit economics and remove what makes the model unrepeatable.'],
        'financial' => ['idea' => 'Prepare a cost and funding estimate for the next validation step.',
            'validation' => 'Prepare a simple budget to the next milestone and identify funding sources.',
            'prototype' => 'Track build and test costs and prepare a funding plan for the next phase.',
            'early_market' => 'Set up basic bookkeeping and track cash runway monthly.',
            'growth' => 'Put financial controls and a funding strategy in place; forecast revenue.'],
        'traction' => ['idea' => 'Collect customer feedback through interviews to test early assumptions.',
            'validation' => 'Run structured customer interviews and record and analyse what was learned.',
            'prototype' => 'Collect customer feedback through pilot testing of the prototype.',
            'early_market' => 'Grow active users from pilots and measure usage and conversion.',
            'growth' => 'Measure and improve retention, renewals and repeat purchases.'],
        'growth_impact' => ['idea' => 'Describe the intended impact and who benefits, and how it could be measured.',
            'validation' => 'Define measurable outcomes and early milestones.',
            'prototype' => 'Define measurable milestones and a growth roadmap from prototype to market.',
            'early_market' => 'Plan customer acquisition and retention and start measuring impact.',
            'growth' => 'Set a measurable expansion plan and report impact regularly.'],
        'uniqueness' => ['idea' => 'Compare alternatives customers use today and write a testable differentiation hypothesis.',
            'validation' => 'Test the unique value proposition with customers and record which differences matter to them.',
            'prototype' => 'Demonstrate the differentiating feature in the prototype and collect evidence that it matters to users.',
            'early_market' => 'Collect evidence that customers choose the startup because of the difference (switching, preference, feedback).',
            'growth' => 'Track whether the advantage holds as competitors respond and invest in what sustains it.'],
    ];
}

/** Default completion rules for a new evaluation template. */
function inc_default_rules(): array
{
    return [
        'min_coverage'          => 60,   // % of total pillar weight that must be rated
        'require_all_pillars'   => 1,    // every applicable pillar must have a rating
        'enforce_required'      => 1,    // required questions may not stay Pending
        'enforce_evidence'      => 1,    // "evidence required" questions need a link or file when rated
        'min_approved'          => 1,    // approved evaluations needed for an official score
        'low_threshold'         => 2.5,  // pillar rating at or below which a recommendation is generated
    ];
}

function inc_seed_defaults(): void
{
    if (!inc_one('SELECT code FROM inc_dev_stages LIMIT 1')) {
        $i = 0;
        foreach (inc_default_dev_stages() as $code => [$name, $desc]) {
            inc_exec('INSERT INTO inc_dev_stages (code, name, description, sort) VALUES (?,?,?,?)', 'sssi', [$code, $name, $desc, ++$i]);
        }
    }
    if (!inc_one("SELECT id FROM inc_stages WHERE pipeline = 'crm' LIMIT 1")) {
        $i = 0;
        foreach (inc_default_crm_stages() as $code => [$name, $term, $req, $notify]) {
            inc_exec("INSERT INTO inc_stages (pipeline, code, name, sort, is_terminal, requires_json, notify) VALUES ('crm',?,?,?,?,?,?)",
                      'ssiisi', [$code, $name, ++$i * 10, $term, $req ? json_encode($req) : null, $notify]);
        }
        // A new pipeline is complete as it is: no department stages added to it.
        inc_exec("INSERT INTO inc_settings (k, v) VALUES ('_dept_stages_added', '1') ON DUPLICATE KEY UPDATE v = '1'");
    }
    if (!inc_one("SELECT id FROM inc_stages WHERE pipeline = 'lifecycle' LIMIT 1")) {
        $i = 0;
        foreach (inc_default_lifecycle_stages() as $code => [$name, $term, $req, $notify, $checks]) {
            $sid = inc_exec("INSERT INTO inc_stages (pipeline, code, name, sort, is_terminal, requires_json, notify) VALUES ('lifecycle',?,?,?,?,?,?)",
                             'ssiisi', [$code, $name, ++$i * 10, $term, $req ? json_encode($req) : null, $notify]);
            foreach ($checks as $j => $text) {
                inc_exec('INSERT INTO inc_checklist_items (stage_id, text, sort) VALUES (?,?,?)', 'isi', [$sid, $text, $j + 1]);
            }
        }
    }
    if (!inc_one('SELECT id FROM inc_masters LIMIT 1')) {
        $i = 0;
        foreach (['Walk-in', 'Website', 'Referral', 'Partner institution', 'Event', 'Outreach'] as $s) {
            inc_exec("INSERT INTO inc_masters (kind, name, sort) VALUES ('source',?,?)", 'si', [$s, ++$i]);
        }
    }
    if (!inc_one('SELECT id FROM inc_eval_templates LIMIT 1')) {
        inc_seed_template();
    }
    // Mail templates are read from code until an administrator edits one.
}

/** The first evaluation template: nine pillars, the baseline questions, published. */
function inc_seed_template(): int
{
    $tid = inc_exec("INSERT INTO inc_eval_templates (version_no, name, status, rules_json, note, created_by, published_at, published_by)
                      VALUES (1, 'Nine-pillar stage-based evaluation', 'published', ?, 'Default template', 'system', NOW(), 'system')",
                     's', [json_encode(inc_default_rules())]);
    $sort = 0;
    foreach (inc_default_pillars() as $code => [$name, $weight, $desc, $guide]) {
        inc_exec('INSERT INTO inc_eval_pillars (template_id, code, name, description, weight, guidance_json, sort) VALUES (?,?,?,?,?,?,?)',
                  'isssdsi', [$tid, $code, $name, $desc, $weight, json_encode($guide), ++$sort]);
    }
    foreach (inc_default_questions() as $pillar => $byStage) {
        $n = 0;
        foreach ($byStage as $stage => $qs) {
            foreach ($qs as [$text, $expl, $guide, $req, $ev, $na]) {
                $n++;
                inc_exec('INSERT INTO inc_eval_questions (template_id, uid, pillar_code, stages, text, explanation, guidance, required, evidence, allow_na, sort)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                          'issssssisii', [$tid, 'q_' . $pillar . '_' . $stage . '_' . $n, $pillar, $stage, $text, $expl, $guide, $req, $ev, $na, $n]);
            }
        }
    }
    return $tid;
}

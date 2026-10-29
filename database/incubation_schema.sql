-- Incubation CRM schema (37 tables, schema version 1).
-- Reference copy of inc_ddl() in lib/inc/schema.php. The portal installs these itself
-- the first time an Incubation CRM page is opened (CREATE TABLE IF NOT EXISTS, and
-- default stages, pillars and questions seeded only into empty tables), so running
-- this file by hand is optional. It is additive: no existing table is altered, and
-- nothing here touches the main CRM's crm_* tables.

CREATE TABLE IF NOT EXISTS inc_settings (
  k VARCHAR(64) NOT NULL PRIMARY KEY,
  v MEDIUMTEXT NULL,
  updated_by VARCHAR(160) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inc_audit (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inc_masters (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kind ENUM('spoke','sector','source') NOT NULL,
  name VARCHAR(120) NOT NULL,
  sort SMALLINT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY u_kind_name (kind, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inc_dev_stages (
  code VARCHAR(20) NOT NULL PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  description VARCHAR(500) NULL,
  sort SMALLINT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inc_stages (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inc_stage_history (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pipeline ENUM('crm','lifecycle') NOT NULL,
  entity_id INT UNSIGNED NOT NULL,
  from_stage_id INT UNSIGNED NULL,
  to_stage_id INT UNSIGNED NOT NULL,
  actor VARCHAR(190) NOT NULL DEFAULT '',
  reason VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_entity (pipeline, entity_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inc_forms (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inc_form_versions (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inc_form_fields (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inc_startups (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inc_dev_history (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  startup_id INT UNSIGNED NOT NULL,
  from_stage VARCHAR(20) NULL,
  to_stage VARCHAR(20) NOT NULL,
  actor VARCHAR(190) NOT NULL DEFAULT '',
  reason VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_startup (startup_id, created_at),
  CONSTRAINT fk_inc_dh_st FOREIGN KEY (startup_id) REFERENCES inc_startups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inc_applications (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inc_app_values (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inc_app_changes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id INT UNSIGNED NOT NULL,
  field VARCHAR(120) NOT NULL,
  old_value TEXT NULL,
  new_value TEXT NULL,
  actor VARCHAR(190) NOT NULL DEFAULT '',
  reason VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_app (application_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inc_files (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inc_notes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entity VARCHAR(20) NOT NULL,
  entity_id INT UNSIGNED NOT NULL,
  kind ENUM('note','comment','call','email') NOT NULL DEFAULT 'note',
  body TEXT NOT NULL,
  actor VARCHAR(190) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_entity (entity, entity_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inc_tasks (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inc_activities (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inc_eval_templates (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inc_eval_pillars (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inc_eval_questions (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inc_evaluations (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inc_eval_responses (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inc_eval_uniqueness (
  evaluation_id INT UNSIGNED NOT NULL PRIMARY KEY,
  competitors_json MEDIUMTEXT NULL,
  uvp TEXT NULL,
  evidence_links TEXT NULL,
  strengths TEXT NULL,
  concerns TEXT NULL,
  improvements TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_inc_eu_ev FOREIGN KEY (evaluation_id) REFERENCES inc_evaluations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inc_recommendations (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inc_teams (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  description VARCHAR(255) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY u_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inc_team_members (
  team_id INT UNSIGNED NOT NULL,
  person_id INT UNSIGNED NOT NULL,
  role ENUM('member','lead','evaluator','reviewer') NOT NULL DEFAULT 'member',
  added_by VARCHAR(190) NOT NULL DEFAULT '',
  added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (team_id, person_id, role),
  KEY k_person (person_id),
  CONSTRAINT fk_inc_tm_team FOREIGN KEY (team_id) REFERENCES inc_teams(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inc_team_spokes (
  team_id INT UNSIGNED NOT NULL,
  spoke_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (team_id, spoke_id),
  CONSTRAINT fk_inc_ts_team FOREIGN KEY (team_id) REFERENCES inc_teams(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inc_workspaces (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inc_assignments (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inc_meetings (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inc_checklist_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stage_id INT UNSIGNED NOT NULL,
  text VARCHAR(255) NOT NULL,
  sort SMALLINT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  KEY k_stage (stage_id, sort),
  CONSTRAINT fk_inc_ci_stage FOREIGN KEY (stage_id) REFERENCES inc_stages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inc_workspace_checks (
  workspace_id INT UNSIGNED NOT NULL,
  item_id INT UNSIGNED NOT NULL,
  done_by VARCHAR(190) NOT NULL DEFAULT '',
  done_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (workspace_id, item_id),
  CONSTRAINT fk_inc_wc_ws FOREIGN KEY (workspace_id) REFERENCES inc_workspaces(id) ON DELETE CASCADE,
  CONSTRAINT fk_inc_wc_item FOREIGN KEY (item_id) REFERENCES inc_checklist_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inc_notify_log (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inc_mail_templates (
  event VARCHAR(40) NOT NULL PRIMARY KEY,
  subject VARCHAR(255) NOT NULL,
  body TEXT NOT NULL,
  email_on TINYINT(1) NOT NULL DEFAULT 1,
  portal_on TINYINT(1) NOT NULL DEFAULT 1,
  updated_by VARCHAR(190) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inc_screen_presets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  criteria_json TEXT NOT NULL,
  created_by VARCHAR(190) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY u_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inc_bulk_runs (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


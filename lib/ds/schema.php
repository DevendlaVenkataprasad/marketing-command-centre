<?php
/**
 * Digital Signatures — the tables, made on first use and migrated by
 * version (app_settings ds_schema). Every organisation's rows carry org_id;
 * nothing is read without it. People, departments and designations are
 * the portal's own (clients, hr_departments, hr_designations) and the
 * organisational units are the organisation's Hub / Spoke list: none of
 * them is copied here.
 *
 * Secrets never live in these tables: no private key, no OTP, no password.
 * A provider's private key is a file on the server named by path; what is
 * kept of a signing is the request and response digests, the provider's
 * reference and the signed PDF itself.
 */

const DS_SCHEMA_VERSION = 3;

function ds_schema_sql(): array
{
    $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
"CREATE TABLE IF NOT EXISTS ds_settings (
  org_id INT UNSIGNED NOT NULL,
  k VARCHAR(64) NOT NULL,
  v MEDIUMTEXT NULL,
  PRIMARY KEY (org_id, k)
) $t",

"CREATE TABLE IF NOT EXISTS ds_seq (
  org_id INT UNSIGNED NOT NULL,
  kind VARCHAR(16) NOT NULL,
  fy INT NOT NULL,
  next_no INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (org_id, kind, fy)
) $t",

"CREATE TABLE IF NOT EXISTS ds_role_members (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  person_id INT UNSIGNED NOT NULL,
  role VARCHAR(32) NOT NULL,
  department_id INT UNSIGNED NOT NULL DEFAULT 0,
  granted_by VARCHAR(160) NULL,
  granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY u_member (org_id, person_id, role, department_id),
  KEY k_person (person_id)
) $t",

"CREATE TABLE IF NOT EXISTS ds_categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  code VARCHAR(12) NULL,
  description VARCHAR(255) NULL,
  default_workflow_id INT UNSIGNED NULL,
  sort INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  UNIQUE KEY u_name (org_id, name),
  KEY k_org (org_id, active)
) $t",

"CREATE TABLE IF NOT EXISTS ds_signatories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  person_id INT UNSIGNED NOT NULL,
  employee_id VARCHAR(40) NULL,
  mobile VARCHAR(20) NULL,
  department_id INT UNSIGNED NULL,
  designation VARCHAR(120) NULL,
  unit VARCHAR(120) NULL,
  authority VARCHAR(120) NULL,
  authority_limit DECIMAL(16,2) NULL,
  provider_pref VARCHAR(20) NULL,
  may_delegate TINYINT(1) NOT NULL DEFAULT 0,
  status VARCHAR(12) NOT NULL DEFAULT 'active',
  notes VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY u_person (org_id, person_id),
  KEY k_dept (org_id, department_id),
  KEY k_auth (org_id, authority)
) $t",

"CREATE TABLE IF NOT EXISTS ds_certificates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  person_id INT UNSIGNED NULL,
  source VARCHAR(12) NOT NULL DEFAULT 'observed',
  fingerprint CHAR(64) NOT NULL,
  serial VARCHAR(80) NULL,
  subject_cn VARCHAR(255) NULL,
  subject_dn VARCHAR(600) NULL,
  issuer_dn VARCHAR(600) NULL,
  email VARCHAR(190) NULL,
  not_before DATETIME NULL,
  not_after DATETIME NULL,
  pem TEXT NOT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'unknown',
  checked_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY u_fp (org_id, fingerprint),
  KEY k_person (org_id, person_id)
) $t",

"CREATE TABLE IF NOT EXISTS ds_trust_anchors (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL DEFAULT 0,
  label VARCHAR(160) NOT NULL,
  kind VARCHAR(16) NOT NULL DEFAULT 'root',
  fingerprint CHAR(64) NOT NULL,
  subject VARCHAR(600) NULL,
  not_after DATETIME NULL,
  pem TEXT NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  added_by VARCHAR(160) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY u_fp (org_id, fingerprint)
) $t",

"CREATE TABLE IF NOT EXISTS ds_providers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  provider VARCHAR(24) NOT NULL,
  label VARCHAR(120) NOT NULL,
  environment VARCHAR(12) NOT NULL DEFAULT 'sandbox',
  config_json TEXT NULL,
  active TINYINT(1) NOT NULL DEFAULT 0,
  priority INT NOT NULL DEFAULT 0,
  created_by VARCHAR(160) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY k_org (org_id, active)
) $t",

"CREATE TABLE IF NOT EXISTS ds_workflows (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  description VARCHAR(500) NULL,
  conditions_json TEXT NULL,
  priority INT NOT NULL DEFAULT 100,
  rejection_rule VARCHAR(20) NOT NULL DEFAULT 'reinitiate',
  resubmission_rule VARCHAR(20) NOT NULL DEFAULT 'restart',
  reminder_hours INT NOT NULL DEFAULT 24,
  max_reminders INT NOT NULL DEFAULT 3,
  escalate_hours INT NULL,
  escalate_to VARCHAR(16) NOT NULL DEFAULT 'manager',
  escalate_person_id INT UNSIGNED NULL,
  escalate_action VARCHAR(12) NOT NULL DEFAULT 'notify',
  deadline_days INT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  created_by VARCHAR(160) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  KEY k_org (org_id, active, priority)
) $t",

"CREATE TABLE IF NOT EXISTS ds_workflow_stages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  workflow_id INT UNSIGNED NOT NULL,
  seq INT NOT NULL,
  name VARCHAR(120) NOT NULL,
  completion VARCHAR(8) NOT NULL DEFAULT 'all',
  deadline_days INT NULL,
  condition_json TEXT NULL,
  KEY k_wf (workflow_id, seq),
  CONSTRAINT fk_ds_stage_wf FOREIGN KEY (workflow_id) REFERENCES ds_workflows(id) ON DELETE CASCADE
) $t",

"CREATE TABLE IF NOT EXISTS ds_workflow_signatories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stage_id INT UNSIGNED NOT NULL,
  seq INT NOT NULL DEFAULT 0,
  assignee_type VARCHAR(16) NOT NULL,
  person_id INT UNSIGNED NULL,
  authority VARCHAR(120) NULL,
  scope VARCHAR(12) NOT NULL DEFAULT 'doc_dept',
  action VARCHAR(10) NOT NULL DEFAULT 'sign',
  method VARCHAR(12) NOT NULL DEFAULT 'any',
  mandatory TINYINT(1) NOT NULL DEFAULT 1,
  label VARCHAR(120) NULL,
  KEY k_stage (stage_id, seq),
  CONSTRAINT fk_ds_wsig_stage FOREIGN KEY (stage_id) REFERENCES ds_workflow_stages(id) ON DELETE CASCADE
) $t",

"CREATE TABLE IF NOT EXISTS ds_files (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  doc_id INT UNSIGNED NULL,
  kind VARCHAR(16) NOT NULL,
  stored_name VARCHAR(190) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  mime VARCHAR(100) NOT NULL DEFAULT 'application/pdf',
  size INT UNSIGNED NOT NULL DEFAULT 0,
  sha256 CHAR(64) NOT NULL,
  key_id VARCHAR(16) NOT NULL DEFAULT 'k1',
  created_by VARCHAR(160) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  UNIQUE KEY u_stored (stored_name),
  KEY k_doc (doc_id, kind),
  KEY k_sha (org_id, sha256)
) $t",

"CREATE TABLE IF NOT EXISTS ds_documents (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  ref_no VARCHAR(40) NOT NULL,
  tracking_code CHAR(16) NOT NULL,
  title VARCHAR(255) NOT NULL,
  category_id INT UNSIGNED NULL,
  department_id INT UNSIGNED NULL,
  unit VARCHAR(120) NULL,
  description TEXT NULL,
  doc_date DATE NULL,
  valid_until DATE NULL,
  owner_id INT UNSIGNED NOT NULL DEFAULT 0,
  created_by_id INT UNSIGNED NOT NULL DEFAULT 0,
  created_by VARCHAR(160) NULL,
  priority VARCHAR(8) NOT NULL DEFAULT 'normal',
  confidentiality VARCHAR(16) NOT NULL DEFAULT 'internal',
  value_amount DECIMAL(16,2) NULL,
  remarks VARCHAR(1000) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'draft',
  workflow_id INT UNSIGNED NULL,
  plan_json MEDIUMTEXT NULL,
  draft_plan_json MEDIUMTEXT NULL,
  cycle INT UNSIGNED NOT NULL DEFAULT 0,
  current_version_id INT UNSIGNED NULL,
  working_file_id INT UNSIGNED NULL,
  signed_file_id INT UNSIGNED NULL,
  deadline DATE NULL,
  submitted_at DATETIME NULL,
  completed_at DATETIME NULL,
  closed_at DATETIME NULL,
  last_activity_at DATETIME NULL,
  retention_until DATE NULL,
  legal_hold TINYINT(1) NOT NULL DEFAULT 0,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  UNIQUE KEY u_ref (org_id, ref_no),
  UNIQUE KEY u_track (tracking_code),
  KEY k_status (org_id, status),
  KEY k_dept (org_id, department_id),
  KEY k_owner (org_id, owner_id),
  KEY k_cat (org_id, category_id),
  KEY k_created (org_id, created_at)
) $t",

"CREATE TABLE IF NOT EXISTS ds_document_versions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  doc_id INT UNSIGNED NOT NULL,
  version_no INT UNSIGNED NOT NULL,
  file_id INT UNSIGNED NOT NULL,
  sha256 CHAR(64) NOT NULL,
  pages INT UNSIGNED NULL,
  size INT UNSIGNED NOT NULL DEFAULT 0,
  note VARCHAR(500) NULL,
  uploaded_by_id INT UNSIGNED NOT NULL DEFAULT 0,
  uploaded_by VARCHAR(160) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  locked_at DATETIME NULL,
  superseded_at DATETIME NULL,
  UNIQUE KEY u_ver (doc_id, version_no),
  CONSTRAINT fk_ds_ver_doc FOREIGN KEY (doc_id) REFERENCES ds_documents(id) ON DELETE CASCADE
) $t",

"CREATE TABLE IF NOT EXISTS ds_document_attachments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  doc_id INT UNSIGNED NOT NULL,
  file_id INT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  created_by VARCHAR(160) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  KEY k_doc (doc_id),
  CONSTRAINT fk_ds_att_doc FOREIGN KEY (doc_id) REFERENCES ds_documents(id) ON DELETE CASCADE
) $t",

"CREATE TABLE IF NOT EXISTS ds_signature_requests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  doc_id INT UNSIGNED NOT NULL,
  version_id INT UNSIGNED NOT NULL,
  cycle INT UNSIGNED NOT NULL,
  stage_seq INT NOT NULL,
  stage_name VARCHAR(120) NULL,
  completion VARCHAR(8) NOT NULL DEFAULT 'all',
  seq INT NOT NULL DEFAULT 0,
  person_id INT UNSIGNED NOT NULL,
  on_behalf_of_id INT UNSIGNED NULL,
  label VARCHAR(120) NULL,
  action VARCHAR(10) NOT NULL DEFAULT 'sign',
  method VARCHAR(12) NOT NULL DEFAULT 'any',
  mandatory TINYINT(1) NOT NULL DEFAULT 1,
  status VARCHAR(12) NOT NULL DEFAULT 'waiting',
  deadline_days INT NULL,
  due_at DATETIME NULL,
  activated_at DATETIME NULL,
  acted_at DATETIME NULL,
  used_method VARCHAR(12) NULL,
  remarks VARCHAR(1000) NULL,
  placement_json TEXT NULL,
  external_id INT UNSIGNED NULL,
  token_hash CHAR(64) NULL,
  token_expires DATETIME NULL,
  otp_hash CHAR(64) NULL,
  otp_expires DATETIME NULL,
  otp_tries INT NOT NULL DEFAULT 0,
  reminders INT NOT NULL DEFAULT 0,
  last_reminded_at DATETIME NULL,
  warned_at DATETIME NULL,
  escalated_at DATETIME NULL,
  evidence_json TEXT NULL,
  seal CHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY k_doc (doc_id, cycle, stage_seq),
  KEY k_person (person_id, status),
  KEY k_due (org_id, status, due_at),
  UNIQUE KEY u_token (token_hash),
  CONSTRAINT fk_ds_req_doc FOREIGN KEY (doc_id) REFERENCES ds_documents(id) ON DELETE CASCADE
) $t",

"CREATE TABLE IF NOT EXISTS ds_signature_transactions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  request_id INT UNSIGNED NOT NULL,
  doc_id INT UNSIGNED NOT NULL,
  provider_id INT UNSIGNED NULL,
  provider VARCHAR(24) NOT NULL,
  txn_ref VARCHAR(64) NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'initiated',
  base_file_id INT UNSIGNED NULL,
  prepared_file_id INT UNSIGNED NULL,
  output_file_id INT UNSIGNED NULL,
  byte_range VARCHAR(80) NULL,
  hex_at INT UNSIGNED NULL,
  hex_len INT UNSIGNED NULL,
  doc_hash CHAR(64) NULL,
  request_digest CHAR(64) NULL,
  response_digest CHAR(64) NULL,
  provider_ref VARCHAR(120) NULL,
  response_code VARCHAR(40) NULL,
  error_message VARCHAR(1000) NULL,
  callback_count INT NOT NULL DEFAULT 0,
  ip VARCHAR(64) NULL,
  user_agent VARCHAR(255) NULL,
  initiated_by_id INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  expires_at DATETIME NULL,
  UNIQUE KEY u_txn (txn_ref),
  KEY k_req (request_id),
  KEY k_doc (doc_id),
  CONSTRAINT fk_ds_txn_req FOREIGN KEY (request_id) REFERENCES ds_signature_requests(id) ON DELETE CASCADE
) $t",

"CREATE TABLE IF NOT EXISTS ds_signature_verifications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  report_ref VARCHAR(40) NOT NULL,
  doc_id INT UNSIGNED NULL,
  file_id INT UNSIGNED NULL,
  source VARCHAR(12) NOT NULL DEFAULT 'upload',
  file_name VARCHAR(255) NULL,
  sha256 CHAR(64) NOT NULL,
  overall VARCHAR(12) NOT NULL,
  signature_count INT NOT NULL DEFAULT 0,
  mandatory_ok TINYINT(1) NULL,
  report_json MEDIUMTEXT NOT NULL,
  verified_by_id INT UNSIGNED NOT NULL DEFAULT 0,
  verified_by VARCHAR(160) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY u_ref (org_id, report_ref),
  KEY k_doc (doc_id),
  KEY k_org (org_id, created_at)
) $t",

"CREATE TABLE IF NOT EXISTS ds_audit (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  seq BIGINT UNSIGNED NOT NULL,
  doc_id INT UNSIGNED NULL,
  entity VARCHAR(24) NOT NULL,
  entity_id INT UNSIGNED NULL,
  action VARCHAR(32) NOT NULL,
  summary VARCHAR(600) NOT NULL,
  detail_json TEXT NULL,
  actor_id INT UNSIGNED NOT NULL DEFAULT 0,
  actor VARCHAR(160) NOT NULL,
  designation VARCHAR(120) NULL,
  ip VARCHAR(64) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  prev_hash CHAR(64) NOT NULL,
  hash CHAR(64) NOT NULL,
  UNIQUE KEY u_seq (org_id, seq),
  KEY k_doc (doc_id, id),
  KEY k_action (org_id, action),
  KEY k_actor (org_id, actor_id)
) $t",

"CREATE TABLE IF NOT EXISTS ds_comments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  doc_id INT UNSIGNED NOT NULL,
  person_id INT UNSIGNED NOT NULL DEFAULT 0,
  author VARCHAR(160) NOT NULL,
  body TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  KEY k_doc (doc_id),
  CONSTRAINT fk_ds_cmt_doc FOREIGN KEY (doc_id) REFERENCES ds_documents(id) ON DELETE CASCADE
) $t",

"CREATE TABLE IF NOT EXISTS ds_delegations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  from_person_id INT UNSIGNED NOT NULL,
  to_person_id INT UNSIGNED NOT NULL,
  starts_on DATE NOT NULL,
  ends_on DATE NOT NULL,
  category_id INT UNSIGNED NULL,
  reason VARCHAR(255) NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'active',
  created_by VARCHAR(160) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revoked_at DATETIME NULL,
  KEY k_from (org_id, from_person_id, status)
) $t",

"CREATE TABLE IF NOT EXISTS ds_notifications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  person_id INT UNSIGNED NOT NULL,
  doc_id INT UNSIGNED NULL,
  event VARCHAR(24) NOT NULL,
  channel VARCHAR(8) NOT NULL,
  subject VARCHAR(255) NOT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'sent',
  error VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_org (org_id, created_at),
  KEY k_doc (doc_id)
) $t",

"CREATE TABLE IF NOT EXISTS ds_permissions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  doc_id INT UNSIGNED NOT NULL,
  person_id INT UNSIGNED NULL,
  department_id INT UNSIGNED NULL,
  access VARCHAR(8) NOT NULL DEFAULT 'view',
  granted_by VARCHAR(160) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_doc (doc_id),
  KEY k_person (person_id),
  CONSTRAINT fk_ds_perm_doc FOREIGN KEY (doc_id) REFERENCES ds_documents(id) ON DELETE CASCADE
) $t",

"CREATE TABLE IF NOT EXISTS ds_retention_policies (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  category_id INT UNSIGNED NULL,
  retain_years INT NOT NULL DEFAULT 8,
  action VARCHAR(8) NOT NULL DEFAULT 'archive',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY u_cat (org_id, category_id)
) $t",

"CREATE TABLE IF NOT EXISTS ds_api_tokens (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  person_id INT UNSIGNED NOT NULL DEFAULT 0,
  label VARCHAR(120) NOT NULL,
  prefix CHAR(8) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  scopes VARCHAR(255) NOT NULL DEFAULT 'read',
  last_used_at DATETIME NULL,
  expires_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revoked_at DATETIME NULL,
  UNIQUE KEY u_hash (token_hash)
) $t",

"CREATE TABLE IF NOT EXISTS ds_external_signers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  email VARCHAR(190) NOT NULL,
  organisation VARCHAR(190) NULL,
  designation VARCHAR(120) NULL,
  mobile VARCHAR(20) NULL,
  created_by VARCHAR(160) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY u_email (org_id, email)
) $t",

"CREATE TABLE IF NOT EXISTS ds_crl_cache (
  url_hash CHAR(64) NOT NULL PRIMARY KEY,
  url VARCHAR(600) NOT NULL,
  body MEDIUMBLOB NOT NULL,
  fetched_at DATETIME NOT NULL,
  next_update DATETIME NULL
) $t",
    ];
}

/** Makes or migrates the tables once per request, when the stored version is behind. */
function ds_install(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $have = (int) (fabo_one('SELECT v FROM app_settings WHERE k = "ds_schema"')['v'] ?? 0);
    if ($have >= DS_SCHEMA_VERSION) {
        return;
    }
    foreach (ds_schema_sql() as $sql) {
        fabo_exec($sql);
    }
    ds_migrate($have);
    fabo_exec('INSERT INTO app_settings (k, v) VALUES ("ds_schema", ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', 's', [(string) DS_SCHEMA_VERSION]);
}

/** Changes after version 1 go here, each guarded by the version it introduces. */
function ds_migrate(int $from): void
{
    if ($from >= 1 && $from < 2) {
        // v2: the sender places fields (several per signatory) in the studio; a plan can be kept as a draft while they do.
        fabo_exec('ALTER TABLE ds_signature_requests MODIFY placement_json TEXT NULL');
        if (!fabo_one("SHOW COLUMNS FROM ds_documents LIKE 'draft_plan_json'")) {
            fabo_exec('ALTER TABLE ds_documents ADD COLUMN draft_plan_json MEDIUMTEXT NULL AFTER plan_json');
        }
    }
    if ($from >= 1 && $from < 3) {
        // v3: people outside the organisation sign through a one-page link.
        foreach (['external_id INT UNSIGNED NULL', 'token_hash CHAR(64) NULL', 'token_expires DATETIME NULL', 'otp_hash CHAR(64) NULL', 'otp_expires DATETIME NULL', 'otp_tries INT NOT NULL DEFAULT 0'] as $col) {
            $name = strtok($col, ' ');
            if (!fabo_one("SHOW COLUMNS FROM ds_signature_requests LIKE '" . $name . "'")) {
                fabo_exec('ALTER TABLE ds_signature_requests ADD COLUMN ' . $col);
            }
        }
        if (!fabo_one("SHOW INDEX FROM ds_signature_requests WHERE Key_name = 'u_token'")) {
            fabo_exec('ALTER TABLE ds_signature_requests ADD UNIQUE KEY u_token (token_hash)');
        }
    }
}

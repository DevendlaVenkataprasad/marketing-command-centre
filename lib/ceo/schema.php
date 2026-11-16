<?php
/**
 * CEO Dashboard — its own tables, made on first use and migrated by version
 * (app_settings ceo_schema). Only what the dashboard itself owns lives here:
 * who may see it, targets, schedules, a short-lived cache and daily snapshots
 * of the figures. Every operational record stays in its own module; nothing
 * here copies one. Every row carries org_id.
 */

const CEO_SCHEMA_VERSION = 1;

function ceo_schema_sql(): array
{
    $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
"CREATE TABLE IF NOT EXISTS ceo_settings (
  org_id INT UNSIGNED NOT NULL,
  k VARCHAR(64) NOT NULL,
  v MEDIUMTEXT NULL,
  PRIMARY KEY (org_id, k)
) $t",

"CREATE TABLE IF NOT EXISTS ceo_profiles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  description VARCHAR(255) NULL,
  scope_json TEXT NOT NULL,
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  created_by VARCHAR(160) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  KEY k_org (org_id, deleted_at)
) $t",

"CREATE TABLE IF NOT EXISTS ceo_access (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  subject ENUM('person','position') NOT NULL DEFAULT 'person',
  person_id INT UNSIGNED NOT NULL DEFAULT 0,
  position VARCHAR(120) NOT NULL DEFAULT '',
  profile_id INT UNSIGNED NOT NULL,
  status ENUM('active','revoked') NOT NULL DEFAULT 'active',
  granted_by VARCHAR(160) NOT NULL DEFAULT '',
  granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revoked_by VARCHAR(160) NULL,
  revoked_at DATETIME NULL,
  UNIQUE KEY u_subject (org_id, subject, person_id, position),
  KEY k_person (person_id, status),
  KEY k_profile (profile_id)
) $t",

"CREATE TABLE IF NOT EXISTS ceo_targets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  kpi_key VARCHAR(40) NOT NULL,
  period_type ENUM('daily','weekly','monthly','quarterly','annual') NOT NULL,
  spoke_key VARCHAR(60) NOT NULL DEFAULT '',
  target_value DECIMAL(16,2) NOT NULL,
  owner_label VARCHAR(160) NOT NULL DEFAULT '',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by VARCHAR(160) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY u_target (org_id, kpi_key, period_type, spoke_key),
  KEY k_org (org_id, active)
) $t",

"CREATE TABLE IF NOT EXISTS ceo_schedules (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  kind VARCHAR(20) NOT NULL,
  frequency ENUM('daily','weekly','monthly','quarterly') NOT NULL,
  spoke_key VARCHAR(60) NOT NULL DEFAULT '',
  recipients_json TEXT NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  next_run DATE NOT NULL,
  last_run_at DATETIME NULL,
  last_result VARCHAR(255) NULL,
  created_by VARCHAR(160) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_due (active, next_run),
  KEY k_org (org_id)
) $t",

"CREATE TABLE IF NOT EXISTS ceo_reports (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  kind VARCHAR(20) NOT NULL,
  format VARCHAR(8) NOT NULL,
  date_from DATE NOT NULL,
  date_to DATE NOT NULL,
  spoke_key VARCHAR(60) NOT NULL DEFAULT '',
  person_id INT UNSIGNED NOT NULL DEFAULT 0,
  actor VARCHAR(160) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_org (org_id, created_at)
) $t",

"CREATE TABLE IF NOT EXISTS ceo_snapshots (
  org_id INT UNSIGNED NOT NULL,
  day DATE NOT NULL,
  spoke_key VARCHAR(60) NOT NULL DEFAULT '',
  kpi_key VARCHAR(40) NOT NULL,
  value DECIMAL(18,4) NOT NULL,
  PRIMARY KEY (org_id, kpi_key, spoke_key, day),
  KEY k_day (day)
) $t",

"CREATE TABLE IF NOT EXISTS ceo_cache (
  org_id INT UNSIGNED NOT NULL,
  ck CHAR(40) NOT NULL,
  payload MEDIUMTEXT NOT NULL,
  computed_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  PRIMARY KEY (org_id, ck),
  KEY k_exp (expires_at)
) $t",

"CREATE TABLE IF NOT EXISTS ceo_prefs (
  org_id INT UNSIGNED NOT NULL,
  person_id INT UNSIGNED NOT NULL,
  prefs_json TEXT NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (org_id, person_id)
) $t",

"CREATE TABLE IF NOT EXISTS ceo_audit (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  actor_id INT UNSIGNED NOT NULL DEFAULT 0,
  actor VARCHAR(160) NOT NULL DEFAULT '',
  action VARCHAR(40) NOT NULL,
  subject VARCHAR(190) NOT NULL DEFAULT '',
  detail_json TEXT NULL,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_org (org_id, id),
  KEY k_when (created_at)
) $t",
    ];
}

function ceo_install(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $have = (int) (fabo_one('SELECT v FROM app_settings WHERE k = "ceo_schema"')['v'] ?? 0);
    if ($have >= CEO_SCHEMA_VERSION) {
        return;
    }
    foreach (ceo_schema_sql() as $sql) {
        fabo_exec($sql);
    }
    fabo_exec('INSERT INTO app_settings (k, v) VALUES ("ceo_schema", ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', 's', [(string) CEO_SCHEMA_VERSION]);
}

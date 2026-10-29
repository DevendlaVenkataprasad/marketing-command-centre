-- HR Management module tables.
-- The portal creates these by itself the first time an HR page is opened (lib/hr/schema.php),
-- and adds any new column to an older install automatically.
-- Import this file only if you prefer to create them by hand.
-- No business data is seeded: leave entitlements, shifts, holidays and payroll rules are configured in the app.

CREATE TABLE IF NOT EXISTS hr_settings (
  org_id INT UNSIGNED NOT NULL DEFAULT 0,
  k VARCHAR(64) NOT NULL,
  v TEXT NULL,
  updated_by VARCHAR(160) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (org_id, k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_config_history (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL DEFAULT 0,
  area VARCHAR(40) NOT NULL,
  item VARCHAR(120) NOT NULL,
  old_value TEXT NULL,
  new_value TEXT NULL,
  effective_from DATE NULL,
  changed_by VARCHAR(160) NULL,
  changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_org_area (org_id, area, changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_audit (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL DEFAULT 0,
  actor_type ENUM('admin','person','system') NOT NULL DEFAULT 'admin',
  actor_id INT UNSIGNED NULL,
  actor_label VARCHAR(190) NULL,
  action VARCHAR(60) NOT NULL,
  entity VARCHAR(40) NOT NULL,
  entity_id INT UNSIGNED NULL,
  summary VARCHAR(255) NULL,
  before_json MEDIUMTEXT NULL,
  after_json MEDIUMTEXT NULL,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_org_time (org_id, created_at),
  KEY k_entity (entity, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_notice_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL DEFAULT 0,
  dedupe_key VARCHAR(190) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY u_key (org_id, dedupe_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_roles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL DEFAULT 0,
  name VARCHAR(120) NOT NULL,
  description VARCHAR(255) NULL,
  scope ENUM('self','team','org') NOT NULL DEFAULT 'self',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by VARCHAR(160) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY u_org_name (org_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_role_perms (
  role_id INT UNSIGNED NOT NULL,
  module VARCHAR(30) NOT NULL,
  action VARCHAR(20) NOT NULL,
  PRIMARY KEY (role_id, module, action),
  CONSTRAINT fk_hr_rp_role FOREIGN KEY (role_id) REFERENCES hr_roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_role_members (
  role_id INT UNSIGNED NOT NULL,
  person_id INT UNSIGNED NOT NULL,
  granted_by VARCHAR(160) NULL,
  granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (role_id, person_id),
  KEY k_person (person_id),
  CONSTRAINT fk_hr_rm_role FOREIGN KEY (role_id) REFERENCES hr_roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_departments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL DEFAULT 0,
  name VARCHAR(120) NOT NULL,
  code VARCHAR(20) NULL,
  description VARCHAR(255) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by VARCHAR(160) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY u_org_name (org_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_designations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL DEFAULT 0,
  name VARCHAR(120) NOT NULL,
  department_id INT UNSIGNED NULL,
  level VARCHAR(40) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by VARCHAR(160) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY u_org_name (org_id, name),
  KEY k_dept (department_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_masters (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL DEFAULT 0,
  kind VARCHAR(30) NOT NULL,
  name VARCHAR(120) NOT NULL,
  description VARCHAR(255) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  amount_limit DECIMAL(14,2) NULL,
  created_by VARCHAR(160) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY u_org_kind_name (org_id, kind, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_shifts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL DEFAULT 0,
  name VARCHAR(120) NOT NULL,
  code VARCHAR(20) NULL,
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  break_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  grace_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  early_grace_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  full_day_hours DECIMAL(5,2) NOT NULL,
  half_day_hours DECIMAL(5,2) NOT NULL,
  overtime_after_hours DECIMAL(5,2) NULL,
  min_overtime_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  weekly_offs VARCHAR(60) NOT NULL DEFAULT '',
  active TINYINT(1) NOT NULL DEFAULT 1,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  created_by VARCHAR(160) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY u_org_name (org_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_shift_assignments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL DEFAULT 0,
  employee_id INT UNSIGNED NOT NULL,
  shift_id INT UNSIGNED NOT NULL,
  effective_from DATE NOT NULL,
  effective_to DATE NULL,
  created_by VARCHAR(160) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY u_emp_from (employee_id, effective_from),
  KEY k_shift (shift_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_holiday_calendars (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL DEFAULT 0,
  name VARCHAR(120) NOT NULL,
  location_id INT UNSIGNED NULL,
  department_id INT UNSIGNED NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by VARCHAR(160) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY u_org_name (org_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_holidays (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL DEFAULT 0,
  calendar_id INT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  type VARCHAR(20) NOT NULL DEFAULT 'public',
  is_optional TINYINT(1) NOT NULL DEFAULT 0,
  recurring TINYINT(1) NOT NULL DEFAULT 0,
  description VARCHAR(255) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by VARCHAR(160) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY k_cal_date (calendar_id, start_date),
  KEY k_org_date (org_id, start_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_employees (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL DEFAULT 0,
  emp_code VARCHAR(30) NOT NULL,
  biometric_id VARCHAR(40) NULL,
  person_id INT UNSIGNED NULL,
  full_name VARCHAR(160) NOT NULL,
  photo_file VARCHAR(80) NULL,
  photo_mime VARCHAR(120) NULL,
  work_email VARCHAR(190) NULL,
  phone VARCHAR(40) NULL,
  date_of_birth DATE NULL,
  gender VARCHAR(20) NULL,
  department_id INT UNSIGNED NULL,
  designation_id INT UNSIGNED NULL,
  manager_id INT UNSIGNED NULL,
  date_of_joining DATE NULL,
  probation_end DATE NULL,
  date_of_leaving DATE NULL,
  employment_type_id INT UNSIGNED NULL,
  category_id INT UNSIGNED NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  location_id INT UNSIGNED NULL,
  holiday_calendar_id INT UNSIGNED NULL,
  weekly_offs VARCHAR(60) NULL,
  leave_policy_id INT UNSIGNED NULL,
  emergency_name VARCHAR(160) NULL,
  emergency_relation VARCHAR(60) NULL,
  emergency_phone VARCHAR(40) NULL,
  address TEXT NULL,
  bank_name VARCHAR(120) NULL,
  bank_account VARCHAR(40) NULL,
  bank_ifsc VARCHAR(20) NULL,
  pan VARCHAR(20) NULL,
  uan VARCHAR(20) NULL,
  pf_number VARCHAR(40) NULL,
  esi_number VARCHAR(40) NULL,
  payment_mode VARCHAR(20) NULL,
  notes TEXT NULL,
  created_by VARCHAR(160) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY u_org_code (org_id, emp_code),
  UNIQUE KEY u_org_bio (org_id, biometric_id),
  UNIQUE KEY u_org_email (org_id, work_email),
  UNIQUE KEY u_person (person_id),
  KEY k_org_status (org_id, status),
  KEY k_dept (department_id),
  KEY k_manager (manager_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_employee_history (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id INT UNSIGNED NOT NULL,
  field VARCHAR(40) NOT NULL,
  old_value VARCHAR(255) NULL,
  new_value VARCHAR(255) NULL,
  effective_on DATE NULL,
  changed_by VARCHAR(160) NULL,
  changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_emp (employee_id, changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_employee_docs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id INT UNSIGNED NOT NULL,
  title VARCHAR(160) NOT NULL,
  stored_name VARCHAR(80) NOT NULL,
  original_name VARCHAR(190) NOT NULL,
  mime VARCHAR(120) NOT NULL,
  size INT UNSIGNED NOT NULL DEFAULT 0,
  uploaded_by VARCHAR(160) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_emp (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_leave_types (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL DEFAULT 0,
  code VARCHAR(12) NOT NULL,
  name VARCHAR(120) NOT NULL,
  is_paid TINYINT(1) NOT NULL DEFAULT 1,
  is_comp_off TINYINT(1) NOT NULL DEFAULT 0,
  unlimited TINYINT(1) NOT NULL DEFAULT 0,
  attachment_after_days DECIMAL(5,1) NULL,
  color VARCHAR(10) NULL,
  description VARCHAR(255) NULL,
  approval_chain TEXT NULL,
  sort SMALLINT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by VARCHAR(160) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY u_org_code (org_id, code),
  UNIQUE KEY u_org_name (org_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_leave_policies (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL DEFAULT 0,
  name VARCHAR(120) NOT NULL,
  description VARCHAR(255) NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  department_ids VARCHAR(255) NULL,
  employment_type_ids VARCHAR(255) NULL,
  effective_from DATE NULL,
  effective_to DATE NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by VARCHAR(160) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY u_org_name (org_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_leave_rules (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  policy_id INT UNSIGNED NOT NULL,
  leave_type_id INT UNSIGNED NOT NULL,
  annual_entitlement DECIMAL(7,2) NOT NULL DEFAULT 0,
  accrual VARCHAR(10) NOT NULL DEFAULT 'annual',
  prorate_joining TINYINT(1) NOT NULL DEFAULT 1,
  carry_forward TINYINT(1) NOT NULL DEFAULT 0,
  carry_limit DECIMAL(7,2) NULL,
  carry_expiry_months TINYINT UNSIGNED NULL,
  encashable TINYINT(1) NOT NULL DEFAULT 0,
  max_consecutive DECIMAL(6,1) NULL,
  min_notice_days SMALLINT UNSIGNED NULL,
  min_service_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  allow_in_probation TINYINT(1) NOT NULL DEFAULT 1,
  allow_half_day TINYINT(1) NOT NULL DEFAULT 1,
  allow_negative TINYINT(1) NOT NULL DEFAULT 0,
  negative_limit DECIMAL(6,1) NOT NULL DEFAULT 0,
  count_weekly_offs TINYINT(1) NOT NULL DEFAULT 0,
  count_holidays TINYINT(1) NOT NULL DEFAULT 0,
  effective_from DATE NULL,
  updated_by VARCHAR(160) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY u_policy_type (policy_id, leave_type_id),
  CONSTRAINT fk_hr_lr_policy FOREIGN KEY (policy_id) REFERENCES hr_leave_policies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_leave_ledger (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL DEFAULT 0,
  employee_id INT UNSIGNED NOT NULL,
  leave_type_id INT UNSIGNED NOT NULL,
  leave_year SMALLINT UNSIGNED NOT NULL,
  kind VARCHAR(20) NOT NULL,
  days DECIMAL(7,2) NOT NULL,
  posted_on DATE NOT NULL,
  ref_type VARCHAR(20) NOT NULL DEFAULT 'manual',
  ref_id INT UNSIGNED NOT NULL DEFAULT 0,
  ref_version SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  note VARCHAR(255) NULL,
  created_by VARCHAR(160) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY u_once (employee_id, leave_type_id, ref_type, ref_id, kind, ref_version),
  KEY k_emp_year (employee_id, leave_year, leave_type_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_leave_requests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL DEFAULT 0,
  employee_id INT UNSIGNED NOT NULL,
  leave_type_id INT UNSIGNED NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  start_half TINYINT(1) NOT NULL DEFAULT 0,
  end_half TINYINT(1) NOT NULL DEFAULT 0,
  half_session VARCHAR(10) NULL,
  days DECIMAL(7,2) NOT NULL DEFAULT 0,
  reason TEXT NULL,
  attachment_file VARCHAR(80) NULL,
  attachment_name VARCHAR(190) NULL,
  attachment_mime VARCHAR(120) NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'draft',
  level_done TINYINT UNSIGNED NOT NULL DEFAULT 0,
  levels_needed TINYINT UNSIGNED NOT NULL DEFAULT 1,
  approval_chain TEXT NULL,
  version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  submitted_at DATETIME NULL,
  decided_at DATETIME NULL,
  decision_comment VARCHAR(500) NULL,
  created_by VARCHAR(160) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY k_emp_dates (employee_id, start_date, end_date),
  KEY k_org_status (org_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_leave_actions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id INT UNSIGNED NOT NULL,
  level TINYINT UNSIGNED NOT NULL DEFAULT 0,
  action VARCHAR(20) NOT NULL,
  actor_label VARCHAR(190) NULL,
  actor_person_id INT UNSIGNED NULL,
  comment VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_req (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_attendance (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL DEFAULT 0,
  employee_id INT UNSIGNED NOT NULL,
  work_date DATE NOT NULL,
  shift_id INT UNSIGNED NULL,
  check_in DATETIME NULL,
  check_out DATETIME NULL,
  break_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  worked_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  overtime_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  overtime_approved TINYINT(1) NOT NULL DEFAULT 0,
  late_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  early_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  status VARCHAR(30) NOT NULL,
  status_manual TINYINT(1) NOT NULL DEFAULT 0,
  source VARCHAR(12) NOT NULL DEFAULT 'manual',
  remarks VARCHAR(255) NULL,
  comp_off_ledger_id INT UNSIGNED NULL,
  created_by VARCHAR(160) NULL,
  updated_by VARCHAR(160) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY u_emp_date (employee_id, work_date),
  KEY k_org_date (org_id, work_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_attendance_corrections (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL DEFAULT 0,
  employee_id INT UNSIGNED NOT NULL,
  work_date DATE NOT NULL,
  req_check_in DATETIME NULL,
  req_check_out DATETIME NULL,
  req_status VARCHAR(30) NULL,
  reason VARCHAR(500) NOT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'submitted',
  decided_by VARCHAR(190) NULL,
  decided_at DATETIME NULL,
  decision_comment VARCHAR(500) NULL,
  created_by VARCHAR(160) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_org_status (org_id, status),
  KEY k_emp_date (employee_id, work_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_attendance_locks (
  org_id INT UNSIGNED NOT NULL DEFAULT 0,
  period CHAR(7) NOT NULL,
  locked TINYINT(1) NOT NULL DEFAULT 1,
  locked_by VARCHAR(160) NULL,
  locked_at DATETIME NULL,
  reopened_by VARCHAR(160) NULL,
  reopened_at DATETIME NULL,
  reason VARCHAR(255) NULL,
  PRIMARY KEY (org_id, period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_salary_components (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL DEFAULT 0,
  code VARCHAR(20) NOT NULL,
  name VARCHAR(120) NOT NULL,
  kind VARCHAR(20) NOT NULL,
  calc VARCHAR(20) NOT NULL DEFAULT 'fixed',
  default_value DECIMAL(14,2) NOT NULL DEFAULT 0,
  wage_ceiling DECIMAL(14,2) NULL,
  gross_limit DECIMAL(14,2) NULL,
  prorate TINYINT(1) NOT NULL DEFAULT 1,
  is_basic TINYINT(1) NOT NULL DEFAULT 0,
  taxable TINYINT(1) NOT NULL DEFAULT 1,
  sort SMALLINT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  description VARCHAR(255) NULL,
  created_by VARCHAR(160) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY u_org_code (org_id, code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_salary_structures (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL DEFAULT 0,
  employee_id INT UNSIGNED NOT NULL,
  effective_from DATE NOT NULL,
  effective_to DATE NULL,
  monthly_gross DECIMAL(14,2) NOT NULL DEFAULT 0,
  note VARCHAR(255) NULL,
  created_by VARCHAR(160) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY u_emp_from (employee_id, effective_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_salary_lines (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  structure_id INT UNSIGNED NOT NULL,
  component_id INT UNSIGNED NOT NULL,
  calc VARCHAR(20) NOT NULL,
  value DECIMAL(14,2) NOT NULL DEFAULT 0,
  UNIQUE KEY u_struct_comp (structure_id, component_id),
  CONSTRAINT fk_hr_sl_struct FOREIGN KEY (structure_id) REFERENCES hr_salary_structures(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_payroll_runs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL DEFAULT 0,
  period CHAR(7) NOT NULL,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'draft',
  settings_json TEXT NULL,
  notes VARCHAR(500) NULL,
  created_by VARCHAR(160) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  calculated_by VARCHAR(160) NULL,
  calculated_at DATETIME NULL,
  reviewed_by VARCHAR(160) NULL,
  reviewed_at DATETIME NULL,
  approved_by VARCHAR(160) NULL,
  approved_at DATETIME NULL,
  finalized_by VARCHAR(160) NULL,
  finalized_at DATETIME NULL,
  payslips_at DATETIME NULL,
  UNIQUE KEY u_org_period (org_id, period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_payroll_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  run_id INT UNSIGNED NOT NULL,
  employee_id INT UNSIGNED NOT NULL,
  structure_id INT UNSIGNED NULL,
  calendar_days DECIMAL(5,1) NOT NULL DEFAULT 0,
  working_days DECIMAL(5,1) NOT NULL DEFAULT 0,
  divisor_days DECIMAL(5,1) NOT NULL DEFAULT 0,
  present_days DECIMAL(5,1) NOT NULL DEFAULT 0,
  paid_leave_days DECIMAL(5,1) NOT NULL DEFAULT 0,
  unpaid_leave_days DECIMAL(5,1) NOT NULL DEFAULT 0,
  absent_days DECIMAL(5,1) NOT NULL DEFAULT 0,
  not_employed_days DECIMAL(5,1) NOT NULL DEFAULT 0,
  holidays DECIMAL(5,1) NOT NULL DEFAULT 0,
  weekly_offs DECIMAL(5,1) NOT NULL DEFAULT 0,
  lop_days DECIMAL(5,1) NOT NULL DEFAULT 0,
  payable_days DECIMAL(5,1) NOT NULL DEFAULT 0,
  overtime_hours DECIMAL(7,2) NOT NULL DEFAULT 0,
  gross DECIMAL(14,2) NOT NULL DEFAULT 0,
  deductions DECIMAL(14,2) NOT NULL DEFAULT 0,
  employer_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
  net DECIMAL(14,2) NOT NULL DEFAULT 0,
  warnings TEXT NULL,
  hold TINYINT(1) NOT NULL DEFAULT 0,
  payment_status VARCHAR(12) NOT NULL DEFAULT 'pending',
  paid_on DATE NULL,
  payment_ref VARCHAR(120) NULL,
  UNIQUE KEY u_run_emp (run_id, employee_id),
  KEY k_emp (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_payroll_lines (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_id INT UNSIGNED NOT NULL,
  component_id INT UNSIGNED NULL,
  code VARCHAR(20) NOT NULL,
  name VARCHAR(120) NOT NULL,
  kind VARCHAR(20) NOT NULL,
  full_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  basis VARCHAR(255) NULL,
  sort SMALLINT NOT NULL DEFAULT 0,
  KEY k_item (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_payroll_adjustments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL DEFAULT 0,
  employee_id INT UNSIGNED NOT NULL,
  period CHAR(7) NOT NULL,
  kind VARCHAR(20) NOT NULL,
  label VARCHAR(120) NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  reason VARCHAR(255) NULL,
  applied_run_id INT UNSIGNED NULL,
  created_by VARCHAR(160) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_org_period (org_id, period),
  KEY k_emp (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_payslips (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL DEFAULT 0,
  run_id INT UNSIGNED NOT NULL,
  item_id INT UNSIGNED NOT NULL,
  employee_id INT UNSIGNED NOT NULL,
  number VARCHAR(60) NOT NULL,
  generated_by VARCHAR(160) NULL,
  generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY u_org_number (org_id, number),
  UNIQUE KEY u_item (item_id),
  KEY k_emp (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO hr_settings (org_id, k, v) VALUES (0, '_schema', '2026-10-14.1') ON DUPLICATE KEY UPDATE v = VALUES(v);

-- Recruitment, onboarding & exits, performance, training, assets, claims, helpdesk.

CREATE TABLE IF NOT EXISTS hr_job_openings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL DEFAULT 0,
  title VARCHAR(160) NOT NULL,
  department_id INT UNSIGNED NULL,
  designation_id INT UNSIGNED NULL,
  location_id INT UNSIGNED NULL,
  employment_type_id INT UNSIGNED NULL,
  hiring_manager_id INT UNSIGNED NULL,
  positions SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  experience VARCHAR(80) NULL,
  salary_range VARCHAR(80) NULL,
  description TEXT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'open',
  target_date DATE NULL,
  created_by VARCHAR(160) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY k_org_status (org_id, status),
  KEY k_manager (hiring_manager_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_candidates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL DEFAULT 0,
  opening_id INT UNSIGNED NOT NULL,
  full_name VARCHAR(160) NOT NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(40) NULL,
  source VARCHAR(60) NULL,
  current_company VARCHAR(120) NULL,
  experience_years DECIMAL(4,1) NULL,
  expected_salary DECIMAL(14,2) NULL,
  notice_days SMALLINT UNSIGNED NULL,
  stage VARCHAR(20) NOT NULL DEFAULT 'applied',
  rating TINYINT UNSIGNED NULL,
  resume_file VARCHAR(80) NULL,
  resume_name VARCHAR(190) NULL,
  resume_mime VARCHAR(120) NULL,
  notes TEXT NULL,
  offer_salary DECIMAL(14,2) NULL,
  offer_join_date DATE NULL,
  employee_id INT UNSIGNED NULL,
  created_by VARCHAR(160) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY k_org_stage (org_id, stage),
  KEY k_opening (opening_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_candidate_events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  candidate_id INT UNSIGNED NOT NULL,
  summary VARCHAR(255) NOT NULL,
  created_by VARCHAR(160) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_cand (candidate_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_interviews (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL DEFAULT 0,
  candidate_id INT UNSIGNED NOT NULL,
  round VARCHAR(60) NOT NULL,
  scheduled_at DATETIME NOT NULL,
  mode VARCHAR(20) NOT NULL DEFAULT 'in_person',
  interviewer_id INT UNSIGNED NULL,
  location VARCHAR(190) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'scheduled',
  result VARCHAR(20) NULL,
  rating TINYINT UNSIGNED NULL,
  feedback TEXT NULL,
  feedback_by VARCHAR(160) NULL,
  feedback_at DATETIME NULL,
  created_by VARCHAR(160) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_cand (candidate_id),
  KEY k_interviewer (interviewer_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_checklists (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL DEFAULT 0,
  kind VARCHAR(20) NOT NULL,
  name VARCHAR(120) NOT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by VARCHAR(160) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_org_kind (org_id, kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_checklist_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  checklist_id INT UNSIGNED NOT NULL,
  title VARCHAR(190) NOT NULL,
  owner VARCHAR(20) NOT NULL DEFAULT 'hr',
  due_days SMALLINT NOT NULL DEFAULT 0,
  sort SMALLINT NOT NULL DEFAULT 0,
  KEY k_list (checklist_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_lifecycle_tasks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL DEFAULT 0,
  employee_id INT UNSIGNED NOT NULL,
  kind VARCHAR(20) NOT NULL,
  separation_id INT UNSIGNED NULL,
  title VARCHAR(190) NOT NULL,
  owner VARCHAR(20) NOT NULL DEFAULT 'hr',
  due_date DATE NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  note VARCHAR(255) NULL,
  done_by VARCHAR(160) NULL,
  done_at DATETIME NULL,
  sort SMALLINT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_emp (employee_id, kind),
  KEY k_org_status (org_id, status, due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_separations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL DEFAULT 0,
  employee_id INT UNSIGNED NOT NULL,
  type VARCHAR(20) NOT NULL DEFAULT 'resignation',
  reason TEXT NULL,
  notice_date DATE NOT NULL,
  requested_lwd DATE NULL,
  approved_lwd DATE NULL,
  prev_status VARCHAR(20) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'submitted',
  decided_by VARCHAR(160) NULL,
  decided_at DATETIME NULL,
  decision_note VARCHAR(500) NULL,
  exit_interview TEXT NULL,
  exit_rating TINYINT UNSIGNED NULL,
  rehire_eligible TINYINT(1) NULL,
  fnf_status VARCHAR(20) NOT NULL DEFAULT 'pending',
  fnf_amount DECIMAL(14,2) NULL,
  fnf_note VARCHAR(500) NULL,
  fnf_settled_on DATE NULL,
  completed_at DATETIME NULL,
  created_by VARCHAR(160) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_emp (employee_id),
  KEY k_org_status (org_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_review_cycles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL DEFAULT 0,
  name VARCHAR(120) NOT NULL,
  period_from DATE NOT NULL,
  period_to DATE NOT NULL,
  self_due DATE NULL,
  manager_due DATE NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'open',
  created_by VARCHAR(160) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_org (org_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_reviews (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL DEFAULT 0,
  cycle_id INT UNSIGNED NOT NULL,
  employee_id INT UNSIGNED NOT NULL,
  reviewer_id INT UNSIGNED NULL,
  stage VARCHAR(20) NOT NULL DEFAULT 'self',
  self_comment TEXT NULL,
  manager_comment TEXT NULL,
  self_rating DECIMAL(3,1) NULL,
  manager_rating DECIMAL(3,1) NULL,
  final_rating DECIMAL(3,1) NULL,
  final_note VARCHAR(500) NULL,
  self_at DATETIME NULL,
  manager_at DATETIME NULL,
  manager_by VARCHAR(160) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY u_cycle_emp (cycle_id, employee_id),
  KEY k_org_stage (org_id, stage),
  KEY k_reviewer (reviewer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_review_goals (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  review_id INT UNSIGNED NOT NULL,
  title VARCHAR(190) NOT NULL,
  target VARCHAR(255) NULL,
  weight DECIMAL(5,2) NOT NULL DEFAULT 0,
  self_rating TINYINT UNSIGNED NULL,
  self_note VARCHAR(500) NULL,
  manager_rating TINYINT UNSIGNED NULL,
  manager_note VARCHAR(500) NULL,
  added_by VARCHAR(160) NULL,
  sort SMALLINT NOT NULL DEFAULT 0,
  KEY k_review (review_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_trainings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL DEFAULT 0,
  title VARCHAR(160) NOT NULL,
  category_id INT UNSIGNED NULL,
  mode VARCHAR(20) NOT NULL DEFAULT 'classroom',
  trainer VARCHAR(160) NULL,
  location VARCHAR(190) NULL,
  start_date DATE NOT NULL,
  end_date DATE NULL,
  hours DECIMAL(6,1) NULL,
  capacity SMALLINT UNSIGNED NULL,
  mandatory TINYINT(1) NOT NULL DEFAULT 0,
  open_enrol TINYINT(1) NOT NULL DEFAULT 0,
  description TEXT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'planned',
  created_by VARCHAR(160) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_org (org_id, start_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_training_enrolments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL DEFAULT 0,
  training_id INT UNSIGNED NOT NULL,
  employee_id INT UNSIGNED NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'enrolled',
  score DECIMAL(5,1) NULL,
  completed_on DATE NULL,
  rating TINYINT UNSIGNED NULL,
  feedback VARCHAR(1000) NULL,
  created_by VARCHAR(160) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY u_training_emp (training_id, employee_id),
  KEY k_emp (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_assets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL DEFAULT 0,
  tag VARCHAR(40) NOT NULL,
  name VARCHAR(160) NOT NULL,
  category_id INT UNSIGNED NULL,
  make_model VARCHAR(120) NULL,
  serial VARCHAR(80) NULL,
  purchase_date DATE NULL,
  cost DECIMAL(14,2) NULL,
  warranty_until DATE NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'in_stock',
  employee_id INT UNSIGNED NULL,
  notes TEXT NULL,
  created_by VARCHAR(160) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY u_org_tag (org_id, tag),
  KEY k_emp (employee_id),
  KEY k_org_status (org_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_asset_assignments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL DEFAULT 0,
  asset_id INT UNSIGNED NOT NULL,
  employee_id INT UNSIGNED NOT NULL,
  assigned_on DATE NOT NULL,
  due_back DATE NULL,
  returned_on DATE NULL,
  issue_note VARCHAR(255) NULL,
  return_note VARCHAR(255) NULL,
  assigned_by VARCHAR(160) NULL,
  returned_by VARCHAR(160) NULL,
  KEY k_asset (asset_id),
  KEY k_emp (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_claims (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL DEFAULT 0,
  employee_id INT UNSIGNED NOT NULL,
  number VARCHAR(30) NOT NULL,
  category_id INT UNSIGNED NULL,
  expense_date DATE NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  approved_amount DECIMAL(14,2) NULL,
  description VARCHAR(500) NOT NULL,
  receipt_file VARCHAR(80) NULL,
  receipt_name VARCHAR(190) NULL,
  receipt_mime VARCHAR(120) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'submitted',
  decided_by VARCHAR(160) NULL,
  decided_at DATETIME NULL,
  decision_note VARCHAR(500) NULL,
  paid_via VARCHAR(20) NULL,
  paid_on DATE NULL,
  paid_ref VARCHAR(120) NULL,
  adjustment_id INT UNSIGNED NULL,
  created_by VARCHAR(160) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY u_org_number (org_id, number),
  KEY k_org_status (org_id, status),
  KEY k_emp (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_announcements (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL DEFAULT 0,
  title VARCHAR(190) NOT NULL,
  body TEXT NOT NULL,
  department_id INT UNSIGNED NULL,
  pinned TINYINT(1) NOT NULL DEFAULT 0,
  publish_from DATE NOT NULL,
  publish_to DATE NULL,
  created_by VARCHAR(160) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_org (org_id, publish_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_tickets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL DEFAULT 0,
  employee_id INT UNSIGNED NOT NULL,
  number VARCHAR(30) NOT NULL,
  category_id INT UNSIGNED NULL,
  subject VARCHAR(190) NOT NULL,
  body TEXT NOT NULL,
  priority VARCHAR(10) NOT NULL DEFAULT 'normal',
  status VARCHAR(20) NOT NULL DEFAULT 'open',
  assigned_to VARCHAR(160) NULL,
  created_by VARCHAR(160) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  resolved_at DATETIME NULL,
  UNIQUE KEY u_org_number (org_id, number),
  KEY k_org_status (org_id, status),
  KEY k_emp (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_ticket_replies (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT UNSIGNED NOT NULL,
  author VARCHAR(160) NOT NULL,
  from_hr TINYINT(1) NOT NULL DEFAULT 0,
  internal TINYINT(1) NOT NULL DEFAULT 0,
  body TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_ticket (ticket_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

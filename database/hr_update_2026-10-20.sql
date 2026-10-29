-- HR Management update: Recruitment, Onboarding & Exits, Performance, Training, Assets,
-- Expense Claims, and Helpdesk & Announcements.
-- The portal creates these by itself the first time an HR page is opened after the update,
-- so running this by hand is optional. "Duplicate column" on the ALTER means it was already done.

ALTER TABLE hr_masters ADD COLUMN amount_limit DECIMAL(14,2) NULL;

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

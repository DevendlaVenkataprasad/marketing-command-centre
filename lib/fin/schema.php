<?php
/**
 * Finance Portal — the tables, created for an organisation the first time
 * anyone in it opens the portal, and brought up to date by version.
 *
 * Nothing here drops or rewrites an existing table: a new version only adds
 * tables, columns and indexes (CREATE TABLE IF NOT EXISTS / guarded ALTER).
 * Amounts are DECIMAL(16,2); rates DECIMAL(12,6); every table is InnoDB so
 * posting runs inside one transaction.
 *
 * The starting data — a chart of accounts for an Indian business, GST and
 * TDS codes, expense categories, approval rules, the month-end checklist —
 * is written once, as ordinary editable records. None of it is fixed in code
 * after that: rates, accounts and rules are the organisation's to change.
 */

const FP_SCHEMA_VERSION = 1;

function fp_install(): void
{
    static $done = [];
    if (isset($done[fp_prefix()])) {
        return;
    }
    $done[fp_prefix()] = true;
    try {
        $have = fp_one("SELECT v FROM fp_settings WHERE k = '_schema'");
        if ($have && (int) $have['v'] >= FP_SCHEMA_VERSION) {
            return;
        }
    } catch (Throwable $e) {
        // First run: nothing is there yet.
    }
    $db = fabo_db();
    foreach (fp_ddl() as $sql) {
        $db->query(fp_sql($sql));
    }
    $fresh = !fp_one("SELECT v FROM fp_settings WHERE k = '_seeded'");
    if ($fresh) {
        fp_seed();
        fp_setting_put('_seeded', date('c'));
    }
    fp_setting_put('_schema', (string) FP_SCHEMA_VERSION);
}

function fp_ddl(): array
{
    $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $amt = 'DECIMAL(16,2) NOT NULL DEFAULT 0';
    $who = 'prepared_by_id INT NULL, prepared_by VARCHAR(160) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, version INT UNSIGNED NOT NULL DEFAULT 1';
    return [
        /* ------------------------------------------------ settings & audit */
        "CREATE TABLE IF NOT EXISTS fp_settings (k VARCHAR(60) NOT NULL PRIMARY KEY, v MEDIUMTEXT NULL) $t",
        "CREATE TABLE IF NOT EXISTS fp_seq (kind VARCHAR(12) NOT NULL, fy SMALLINT NOT NULL, next_no INT UNSIGNED NOT NULL DEFAULT 1, PRIMARY KEY (kind, fy)) $t",
        "CREATE TABLE IF NOT EXISTS fp_audit (
           id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, entity VARCHAR(20) NOT NULL, entity_id INT UNSIGNED NULL, action VARCHAR(30) NOT NULL,
           summary VARCHAR(500) NOT NULL, before_json MEDIUMTEXT NULL, after_json MEDIUMTEXT NULL, actor_id INT NULL, actor VARCHAR(160) NOT NULL DEFAULT '',
           ip VARCHAR(45) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
           KEY k_entity (entity, entity_id, created_at), KEY k_when (created_at), KEY k_actor (actor_id)) $t",
        "CREATE TABLE IF NOT EXISTS fp_files (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, doc_type VARCHAR(12) NOT NULL, doc_id INT UNSIGNED NOT NULL, stored_name VARCHAR(80) NOT NULL,
           original_name VARCHAR(190) NOT NULL, mime VARCHAR(120) NULL, size INT UNSIGNED NOT NULL DEFAULT 0, note VARCHAR(255) NULL,
           uploaded_by_id INT NULL, uploaded_by VARCHAR(160) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY k_doc (doc_type, doc_id)) $t",

        /* ------------------------------------------------ people & roles */
        "CREATE TABLE IF NOT EXISTS fp_role_members (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, person_id INT UNSIGNED NOT NULL, role VARCHAR(30) NOT NULL, cost_centre_id INT UNSIGNED NULL,
           entity_id INT UNSIGNED NULL, added_by VARCHAR(160) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
           UNIQUE KEY u_member (person_id, role, cost_centre_id), KEY k_role (role)) $t",
        "CREATE TABLE IF NOT EXISTS fp_delegations (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, from_person_id INT NOT NULL, to_person_id INT NOT NULL, from_date DATE NOT NULL, to_date DATE NOT NULL,
           doc_types VARCHAR(255) NULL, reason VARCHAR(255) NULL, active TINYINT(1) NOT NULL DEFAULT 1, created_by VARCHAR(160) NULL,
           created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY k_to (to_person_id, from_date, to_date)) $t",

        /* ------------------------------------------------ masters */
        "CREATE TABLE IF NOT EXISTS fp_entities (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(20) NOT NULL, name VARCHAR(190) NOT NULL, base_currency CHAR(3) NOT NULL DEFAULT 'INR',
           gstin VARCHAR(20) NULL, pan VARCHAR(12) NULL, state VARCHAR(60) NULL, address VARCHAR(500) NULL, active TINYINT(1) NOT NULL DEFAULT 1,
           UNIQUE KEY u_code (code)) $t",
        "CREATE TABLE IF NOT EXISTS fp_accounts (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(20) NOT NULL, name VARCHAR(160) NOT NULL,
           type ENUM('asset','liability','equity','income','expense') NOT NULL, subtype VARCHAR(30) NOT NULL DEFAULT 'other',
           parent_id INT UNSIGNED NULL, is_group TINYINT(1) NOT NULL DEFAULT 0, cash_flow ENUM('operating','investing','financing') NOT NULL DEFAULT 'operating',
           system_key VARCHAR(30) NULL, description VARCHAR(255) NULL, active TINYINT(1) NOT NULL DEFAULT 1,
           created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
           UNIQUE KEY u_code (code), UNIQUE KEY u_system (system_key), KEY k_type (type, subtype), KEY k_parent (parent_id)) $t",
        "CREATE TABLE IF NOT EXISTS fp_cost_centres (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(20) NOT NULL, name VARCHAR(120) NOT NULL, parent_id INT UNSIGNED NULL,
           manager_person_id INT UNSIGNED NULL, hr_department_id INT UNSIGNED NULL, active TINYINT(1) NOT NULL DEFAULT 1,
           UNIQUE KEY u_code (code), KEY k_hr (hr_department_id)) $t",
        "CREATE TABLE IF NOT EXISTS fp_projects (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(20) NOT NULL, name VARCHAR(160) NOT NULL, cost_centre_id INT UNSIGNED NULL,
           start_date DATE NULL, end_date DATE NULL, status ENUM('active','closed') NOT NULL DEFAULT 'active', notes VARCHAR(255) NULL,
           UNIQUE KEY u_code (code)) $t",
        "CREATE TABLE IF NOT EXISTS fp_tax_codes (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(20) NOT NULL, name VARCHAR(120) NOT NULL,
           kind ENUM('gst','igst','tds','exempt','other') NOT NULL, rate DECIMAL(7,3) NOT NULL DEFAULT 0, section VARCHAR(20) NULL,
           threshold DECIMAL(16,2) NULL, input_account_id INT UNSIGNED NULL, output_account_id INT UNSIGNED NULL,
           active TINYINT(1) NOT NULL DEFAULT 1, UNIQUE KEY u_code (code)) $t",
        "CREATE TABLE IF NOT EXISTS fp_fx_rates (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, currency CHAR(3) NOT NULL, rate_date DATE NOT NULL, rate DECIMAL(12,6) NOT NULL,
           source VARCHAR(60) NULL, UNIQUE KEY u_rate (currency, rate_date)) $t",
        "CREATE TABLE IF NOT EXISTS fp_periods (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, fy SMALLINT NOT NULL, period_no TINYINT NOT NULL, name VARCHAR(20) NOT NULL,
           start_date DATE NOT NULL, end_date DATE NOT NULL, status ENUM('open','closed','locked') NOT NULL DEFAULT 'open',
           closed_by VARCHAR(160) NULL, closed_at DATETIME NULL, UNIQUE KEY u_period (fy, period_no), KEY k_dates (start_date, end_date)) $t",

        /* ------------------------------------------------ general ledger */
        "CREATE TABLE IF NOT EXISTS fp_journals (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, entity_id INT UNSIGNED NOT NULL DEFAULT 1, number VARCHAR(30) NULL, jdate DATE NOT NULL,
           period_id INT UNSIGNED NULL,
           type ENUM('manual','accrual','prepayment','adjustment','reversal','recurring','intercompany','opening','closing','system') NOT NULL DEFAULT 'manual',
           source VARCHAR(12) NULL, source_id INT UNSIGNED NULL, reference VARCHAR(120) NULL, description VARCHAR(500) NOT NULL DEFAULT '',
           currency CHAR(3) NOT NULL DEFAULT 'INR', fx_rate DECIMAL(12,6) NOT NULL DEFAULT 1, total $amt,
           status ENUM('draft','submitted','approved','posted','rejected','cancelled') NOT NULL DEFAULT 'draft',
           reversal_of INT UNSIGNED NULL, reversed_by INT UNSIGNED NULL, auto_reverse_on DATE NULL, ic_entity_id INT UNSIGNED NULL, ic_pair_id INT UNSIGNED NULL,
           ic_account_id INT UNSIGNED NULL,
           return_note VARCHAR(500) NULL, posted_by VARCHAR(160) NULL, posted_at DATETIME NULL, approved_by VARCHAR(160) NULL, $who,
           UNIQUE KEY u_number (number), KEY k_date (jdate), KEY k_status (status), KEY k_source (source, source_id), KEY k_entity (entity_id, jdate)) $t",
        "CREATE TABLE IF NOT EXISTS fp_journal_lines (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, journal_id INT UNSIGNED NOT NULL, line_no SMALLINT NOT NULL DEFAULT 1, account_id INT UNSIGNED NOT NULL,
           cost_centre_id INT UNSIGNED NULL, project_id INT UNSIGNED NULL, description VARCHAR(255) NULL,
           debit $amt, credit $amt, debit_base $amt, credit_base $amt, tax_code_id INT UNSIGNED NULL,
           party_type ENUM('vendor','customer','employee') NULL, party_id INT UNSIGNED NULL,
           KEY k_journal (journal_id), KEY k_account (account_id), KEY k_cc (cost_centre_id), KEY k_project (project_id), KEY k_party (party_type, party_id),
           CONSTRAINT fp_fk_jl_journal FOREIGN KEY (journal_id) REFERENCES fp_journals(id) ON DELETE CASCADE) $t",
        "CREATE TABLE IF NOT EXISTS fp_recurring (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, journal_id INT UNSIGNED NOT NULL, frequency ENUM('monthly','quarterly','yearly') NOT NULL DEFAULT 'monthly',
           next_date DATE NOT NULL, end_date DATE NULL, active TINYINT(1) NOT NULL DEFAULT 1, last_run DATE NULL, runs INT NOT NULL DEFAULT 0,
           created_by VARCHAR(160) NULL, KEY k_next (active, next_date)) $t",

        /* ------------------------------------------------ approval engine */
        "CREATE TABLE IF NOT EXISTS fp_wf_rules (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, doc_type VARCHAR(12) NOT NULL, level TINYINT NOT NULL DEFAULT 1, label VARCHAR(80) NOT NULL DEFAULT '',
           min_amount DECIMAL(16,2) NOT NULL DEFAULT 0, max_amount DECIMAL(16,2) NULL, entity_id INT UNSIGNED NULL, cost_centre_id INT UNSIGNED NULL,
           approver_type ENUM('role','person','manager','cc_owner') NOT NULL DEFAULT 'role', approver_roles VARCHAR(200) NULL, approver_person_id INT UNSIGNED NULL,
           required TINYINT NOT NULL DEFAULT 1, escalate_hours SMALLINT NULL, active TINYINT(1) NOT NULL DEFAULT 1,
           KEY k_doc (doc_type, active, level)) $t",
        "CREATE TABLE IF NOT EXISTS fp_wf_steps (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, doc_type VARCHAR(12) NOT NULL, doc_id INT UNSIGNED NOT NULL, doc_version INT UNSIGNED NOT NULL DEFAULT 1,
           level TINYINT NOT NULL, label VARCHAR(80) NOT NULL DEFAULT '', approver_type VARCHAR(10) NOT NULL, approver_roles VARCHAR(200) NULL,
           approver_person_id INT NULL, cost_centre_id INT UNSIGNED NULL, submitter_id INT NULL, required TINYINT NOT NULL DEFAULT 1, approvals TINYINT NOT NULL DEFAULT 0,
           status ENUM('waiting','pending','approved','rejected','returned','cancelled') NOT NULL DEFAULT 'waiting',
           escalate_hours SMALLINT NULL, activated_at DATETIME NULL, escalated_at DATETIME NULL, reminded_at DATETIME NULL, done_at DATETIME NULL,
           KEY k_doc (doc_type, doc_id, doc_version), KEY k_status (status)) $t",
        "CREATE TABLE IF NOT EXISTS fp_wf_events (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, doc_type VARCHAR(12) NOT NULL, doc_id INT UNSIGNED NOT NULL, doc_version INT UNSIGNED NOT NULL DEFAULT 1,
           step_id INT UNSIGNED NULL, level TINYINT NULL, action VARCHAR(20) NOT NULL, comment VARCHAR(1000) NULL, actor_id INT NULL, actor VARCHAR(160) NOT NULL,
           on_behalf_of INT NULL, amount DECIMAL(16,2) NULL, ip VARCHAR(45) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
           KEY k_doc (doc_type, doc_id, created_at), KEY k_actor (actor_id)) $t",

        /* ------------------------------------------------ payables */
        "CREATE TABLE IF NOT EXISTS fp_vendors (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(20) NOT NULL, name VARCHAR(190) NOT NULL, legal_name VARCHAR(190) NULL,
           category VARCHAR(80) NULL, gstin VARCHAR(20) NULL, pan VARCHAR(12) NULL, email VARCHAR(190) NULL, phone VARCHAR(40) NULL,
           address VARCHAR(500) NULL, state VARCHAR(60) NULL, payment_terms_days SMALLINT NOT NULL DEFAULT 30, tds_code_id INT UNSIGNED NULL,
           msme TINYINT(1) NOT NULL DEFAULT 0, bank_name VARCHAR(120) NULL, bank_account VARCHAR(40) NULL, bank_ifsc VARCHAR(15) NULL, upi VARCHAR(80) NULL,
           status ENUM('draft','pending','active','blocked','rejected') NOT NULL DEFAULT 'draft', pending_json TEXT NULL,
           verified_by VARCHAR(160) NULL, verified_at DATETIME NULL, source_ref VARCHAR(20) NULL, notes VARCHAR(500) NULL, $who,
           UNIQUE KEY u_code (code), KEY k_name (name), KEY k_status (status), KEY k_gstin (gstin), KEY k_source (source_ref)) $t",
        "CREATE TABLE IF NOT EXISTS fp_requisitions (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, number VARCHAR(30) NOT NULL, requester_person_id INT NOT NULL, requester VARCHAR(160) NOT NULL,
           entity_id INT UNSIGNED NOT NULL DEFAULT 1, cost_centre_id INT UNSIGNED NULL, project_id INT UNSIGNED NULL, needed_by DATE NULL,
           purpose VARCHAR(500) NOT NULL, suggested_vendor_id INT UNSIGNED NULL, est_total $amt,
           status ENUM('draft','submitted','approved','rejected','ordered','closed','cancelled') NOT NULL DEFAULT 'draft', return_note VARCHAR(500) NULL, $who,
           UNIQUE KEY u_number (number), KEY k_status (status), KEY k_req (requester_person_id)) $t",
        "CREATE TABLE IF NOT EXISTS fp_requisition_lines (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, requisition_id INT UNSIGNED NOT NULL, description VARCHAR(255) NOT NULL, account_id INT UNSIGNED NULL,
           qty DECIMAL(14,3) NOT NULL DEFAULT 1, est_rate $amt, amount $amt, KEY k_req (requisition_id)) $t",
        "CREATE TABLE IF NOT EXISTS fp_pos (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, entity_id INT UNSIGNED NOT NULL DEFAULT 1, number VARCHAR(30) NOT NULL, vendor_id INT UNSIGNED NOT NULL,
           requisition_id INT UNSIGNED NULL, po_date DATE NOT NULL, delivery_date DATE NULL, currency CHAR(3) NOT NULL DEFAULT 'INR', fx_rate DECIMAL(12,6) NOT NULL DEFAULT 1,
           cost_centre_id INT UNSIGNED NULL, project_id INT UNSIGNED NULL, subtotal $amt, tax_total $amt, total $amt, terms VARCHAR(1000) NULL,
           status ENUM('draft','submitted','approved','partially_received','received','closed','cancelled','rejected') NOT NULL DEFAULT 'draft',
           amendment_no SMALLINT NOT NULL DEFAULT 0, return_note VARCHAR(500) NULL, $who,
           UNIQUE KEY u_number (number), KEY k_vendor (vendor_id), KEY k_status (status)) $t",
        "CREATE TABLE IF NOT EXISTS fp_po_lines (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, po_id INT UNSIGNED NOT NULL, line_no SMALLINT NOT NULL DEFAULT 1, description VARCHAR(255) NOT NULL,
           account_id INT UNSIGNED NOT NULL, cost_centre_id INT UNSIGNED NULL, project_id INT UNSIGNED NULL, qty DECIMAL(14,3) NOT NULL DEFAULT 1,
           rate $amt, amount $amt, tax_code_id INT UNSIGNED NULL, tax_amount $amt, received_qty DECIMAL(14,3) NOT NULL DEFAULT 0,
           billed_qty DECIMAL(14,3) NOT NULL DEFAULT 0, billed_amount $amt, KEY k_po (po_id)) $t",
        "CREATE TABLE IF NOT EXISTS fp_po_revisions (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, po_id INT UNSIGNED NOT NULL, amendment_no SMALLINT NOT NULL, snapshot MEDIUMTEXT NOT NULL,
           reason VARCHAR(500) NULL, created_by VARCHAR(160) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY k_po (po_id)) $t",
        "CREATE TABLE IF NOT EXISTS fp_grns (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, number VARCHAR(30) NOT NULL, po_id INT UNSIGNED NOT NULL, grn_date DATE NOT NULL,
           received_by_id INT NULL, received_by VARCHAR(160) NULL, notes VARCHAR(500) NULL, status ENUM('posted','cancelled') NOT NULL DEFAULT 'posted',
           created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY u_number (number), KEY k_po (po_id)) $t",
        "CREATE TABLE IF NOT EXISTS fp_grn_lines (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, grn_id INT UNSIGNED NOT NULL, po_line_id INT UNSIGNED NOT NULL, qty DECIMAL(14,3) NOT NULL,
           rejected_qty DECIMAL(14,3) NOT NULL DEFAULT 0, remarks VARCHAR(255) NULL, KEY k_grn (grn_id), KEY k_line (po_line_id)) $t",
        "CREATE TABLE IF NOT EXISTS fp_bills (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, entity_id INT UNSIGNED NOT NULL DEFAULT 1, number VARCHAR(30) NOT NULL,
           kind ENUM('bill','debit_note') NOT NULL DEFAULT 'bill', ref_bill_id INT UNSIGNED NULL, vendor_id INT UNSIGNED NOT NULL,
           bill_no VARCHAR(60) NOT NULL DEFAULT '', bill_no_key VARCHAR(60) NOT NULL DEFAULT '', bill_date DATE NOT NULL, due_date DATE NULL, posting_date DATE NULL,
           po_id INT UNSIGNED NULL, currency CHAR(3) NOT NULL DEFAULT 'INR', fx_rate DECIMAL(12,6) NOT NULL DEFAULT 1,
           subtotal $amt, tax_total $amt, tds_code_id INT UNSIGNED NULL, tds_total $amt, total $amt, paid_amount $amt, scheduled_on DATE NULL,
           status ENUM('draft','submitted','under_review','awaiting_approval','approved','on_hold','rejected','partially_paid','paid','cancelled') NOT NULL DEFAULT 'draft',
           hold_from VARCHAR(20) NULL, hold_reason VARCHAR(500) NULL, match_status ENUM('na','matched','exception','overridden') NOT NULL DEFAULT 'na',
           match_note VARCHAR(1000) NULL, budget_note VARCHAR(1000) NULL, dup_note VARCHAR(500) NULL, description VARCHAR(500) NULL,
           journal_id INT UNSIGNED NULL, return_note VARCHAR(500) NULL, $who,
           UNIQUE KEY u_number (number), KEY k_vendor (vendor_id, bill_no_key), KEY k_status (status), KEY k_due (due_date), KEY k_po (po_id)) $t",
        "CREATE TABLE IF NOT EXISTS fp_bill_lines (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, bill_id INT UNSIGNED NOT NULL, line_no SMALLINT NOT NULL DEFAULT 1, description VARCHAR(255) NOT NULL,
           account_id INT UNSIGNED NOT NULL, cost_centre_id INT UNSIGNED NULL, project_id INT UNSIGNED NULL, qty DECIMAL(14,3) NOT NULL DEFAULT 1,
           rate $amt, amount $amt, tax_code_id INT UNSIGNED NULL, tax_amount $amt, po_line_id INT UNSIGNED NULL, asset_id INT UNSIGNED NULL,
           KEY k_bill (bill_id), KEY k_po_line (po_line_id)) $t",
        "CREATE TABLE IF NOT EXISTS fp_ap_allocs (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, bill_id INT UNSIGNED NOT NULL, payment_id INT UNSIGNED NULL, note_id INT UNSIGNED NULL,
           amount $amt, status ENUM('pending','applied','released') NOT NULL DEFAULT 'pending', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
           KEY k_bill (bill_id), KEY k_payment (payment_id)) $t",

        /* ------------------------------------------------ receivables */
        "CREATE TABLE IF NOT EXISTS fp_customers (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(20) NOT NULL, name VARCHAR(190) NOT NULL, legal_name VARCHAR(190) NULL,
           gstin VARCHAR(20) NULL, pan VARCHAR(12) NULL, email VARCHAR(190) NULL, phone VARCHAR(40) NULL, address VARCHAR(500) NULL, state VARCHAR(60) NULL,
           payment_terms_days SMALLINT NOT NULL DEFAULT 30, credit_limit DECIMAL(16,2) NULL, client_id INT UNSIGNED NULL,
           status ENUM('active','blocked') NOT NULL DEFAULT 'active', notes VARCHAR(500) NULL, $who,
           UNIQUE KEY u_code (code), KEY k_name (name), KEY k_client (client_id)) $t",
        "CREATE TABLE IF NOT EXISTS fp_invoices (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, entity_id INT UNSIGNED NOT NULL DEFAULT 1, number VARCHAR(30) NOT NULL,
           kind ENUM('invoice','credit_note') NOT NULL DEFAULT 'invoice', ref_invoice_id INT UNSIGNED NULL, customer_id INT UNSIGNED NOT NULL,
           invoice_date DATE NOT NULL, due_date DATE NULL, currency CHAR(3) NOT NULL DEFAULT 'INR', fx_rate DECIMAL(12,6) NOT NULL DEFAULT 1,
           customer_po VARCHAR(60) NULL, place_of_supply VARCHAR(60) NULL, subtotal $amt, tax_total $amt, total $amt, received_amount $amt,
           status ENUM('draft','submitted','issued','partially_paid','paid','cancelled','rejected') NOT NULL DEFAULT 'draft',
           disputed TINYINT(1) NOT NULL DEFAULT 0, dispute_note VARCHAR(500) NULL, notes VARCHAR(1000) NULL, journal_id INT UNSIGNED NULL,
           legacy_invoice_id INT UNSIGNED NULL, last_reminder_at DATETIME NULL, reminders SMALLINT NOT NULL DEFAULT 0, return_note VARCHAR(500) NULL, $who,
           UNIQUE KEY u_number (number), UNIQUE KEY u_legacy (legacy_invoice_id), KEY k_customer (customer_id), KEY k_status (status), KEY k_due (due_date)) $t",
        "CREATE TABLE IF NOT EXISTS fp_invoice_lines (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, invoice_id INT UNSIGNED NOT NULL, line_no SMALLINT NOT NULL DEFAULT 1, description VARCHAR(255) NOT NULL,
           account_id INT UNSIGNED NOT NULL, cost_centre_id INT UNSIGNED NULL, project_id INT UNSIGNED NULL, hsn VARCHAR(12) NULL,
           qty DECIMAL(14,3) NOT NULL DEFAULT 1, rate $amt, amount $amt, tax_code_id INT UNSIGNED NULL, tax_amount $amt, KEY k_invoice (invoice_id)) $t",
        "CREATE TABLE IF NOT EXISTS fp_receipts (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, entity_id INT UNSIGNED NOT NULL DEFAULT 1, number VARCHAR(30) NOT NULL, customer_id INT UNSIGNED NOT NULL,
           receipt_date DATE NOT NULL, bank_account_id INT UNSIGNED NOT NULL, currency CHAR(3) NOT NULL DEFAULT 'INR', fx_rate DECIMAL(12,6) NOT NULL DEFAULT 1,
           amount $amt, tds_amount $amt, method VARCHAR(20) NOT NULL DEFAULT 'neft', reference VARCHAR(120) NULL, notes VARCHAR(500) NULL,
           status ENUM('posted','cancelled') NOT NULL DEFAULT 'posted', journal_id INT UNSIGNED NULL, legacy_invoice_id INT UNSIGNED NULL, $who,
           UNIQUE KEY u_number (number), UNIQUE KEY u_legacy (legacy_invoice_id), KEY k_customer (customer_id)) $t",
        "CREATE TABLE IF NOT EXISTS fp_ar_allocs (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, invoice_id INT UNSIGNED NOT NULL, receipt_id INT UNSIGNED NULL, note_id INT UNSIGNED NULL,
           amount $amt, created_by VARCHAR(160) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY k_invoice (invoice_id), KEY k_receipt (receipt_id)) $t",
        "CREATE TABLE IF NOT EXISTS fp_followups (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, customer_id INT UNSIGNED NOT NULL, invoice_id INT UNSIGNED NULL, kind VARCHAR(20) NOT NULL DEFAULT 'note',
           note VARCHAR(1000) NOT NULL, next_date DATE NULL, actor VARCHAR(160) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
           KEY k_customer (customer_id, created_at)) $t",

        /* ------------------------------------------------ treasury */
        "CREATE TABLE IF NOT EXISTS fp_bank_accounts (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, entity_id INT UNSIGNED NOT NULL DEFAULT 1, name VARCHAR(120) NOT NULL, kind ENUM('bank','cash','card') NOT NULL DEFAULT 'bank',
           bank VARCHAR(120) NULL, account_no VARCHAR(40) NULL, ifsc VARCHAR(15) NULL, currency CHAR(3) NOT NULL DEFAULT 'INR', account_id INT UNSIGNED NOT NULL,
           active TINYINT(1) NOT NULL DEFAULT 1, notes VARCHAR(255) NULL, UNIQUE KEY u_account (account_id)) $t",
        "CREATE TABLE IF NOT EXISTS fp_payment_batches (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, number VARCHAR(30) NOT NULL, bank_account_id INT UNSIGNED NOT NULL, pay_date DATE NOT NULL,
           status ENUM('draft','submitted','authorized','released','completed','partly_failed','rejected','cancelled') NOT NULL DEFAULT 'draft',
           total $amt, count SMALLINT NOT NULL DEFAULT 0, released_at DATETIME NULL, released_by VARCHAR(160) NULL, return_note VARCHAR(500) NULL, $who,
           UNIQUE KEY u_number (number), KEY k_status (status)) $t",
        "CREATE TABLE IF NOT EXISTS fp_payments (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, entity_id INT UNSIGNED NOT NULL DEFAULT 1, number VARCHAR(30) NOT NULL,
           kind ENUM('vendor','claim','advance','request','tax') NOT NULL DEFAULT 'vendor', payee_type ENUM('vendor','employee','other') NOT NULL DEFAULT 'vendor',
           payee_id INT UNSIGNED NULL, payee_name VARCHAR(190) NOT NULL, bank_account_id INT UNSIGNED NULL, pay_date DATE NOT NULL,
           method ENUM('neft','rtgs','imps','upi','cheque','cash','card','other') NOT NULL DEFAULT 'neft',
           currency CHAR(3) NOT NULL DEFAULT 'INR', fx_rate DECIMAL(12,6) NOT NULL DEFAULT 1, amount $amt, purpose VARCHAR(500) NOT NULL DEFAULT '',
           account_id INT UNSIGNED NULL, cost_centre_id INT UNSIGNED NULL, project_id INT UNSIGNED NULL, requester_person_id INT NULL,
           status ENUM('draft','submitted','authorized','released','completed','failed','rejected','cancelled') NOT NULL DEFAULT 'draft',
           batch_id INT UNSIGNED NULL, bank_ref VARCHAR(120) NULL, failure_reason VARCHAR(500) NULL, completed_on DATE NULL, retry_of INT UNSIGNED NULL,
           journal_id INT UNSIGNED NULL, authorized_by VARCHAR(160) NULL, authorized_at DATETIME NULL, return_note VARCHAR(500) NULL, $who,
           UNIQUE KEY u_number (number), KEY k_status (status), KEY k_batch (batch_id), KEY k_date (pay_date)) $t",
        "CREATE TABLE IF NOT EXISTS fp_payment_allocs (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, payment_id INT UNSIGNED NOT NULL, doc_type ENUM('bill','claim','advance') NOT NULL, doc_id INT UNSIGNED NOT NULL,
           amount $amt, KEY k_payment (payment_id), KEY k_doc (doc_type, doc_id)) $t",
        "CREATE TABLE IF NOT EXISTS fp_bank_imports (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, bank_account_id INT UNSIGNED NOT NULL, file_name VARCHAR(190) NOT NULL, line_count INT NOT NULL DEFAULT 0,
           skipped INT NOT NULL DEFAULT 0, imported_by VARCHAR(160) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) $t",
        "CREATE TABLE IF NOT EXISTS fp_bank_lines (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, bank_account_id INT UNSIGNED NOT NULL, import_id INT UNSIGNED NULL, txn_date DATE NOT NULL,
           description VARCHAR(255) NOT NULL DEFAULT '', ref VARCHAR(120) NULL, withdrawal $amt, deposit $amt, balance DECIMAL(16,2) NULL,
           status ENUM('unmatched','matched','ignored') NOT NULL DEFAULT 'unmatched', matched_line_id INT UNSIGNED NULL, matched_by VARCHAR(160) NULL,
           matched_at DATETIME NULL, hash CHAR(40) NOT NULL, UNIQUE KEY u_hash (bank_account_id, hash), KEY k_status (bank_account_id, status, txn_date),
           KEY k_matched (matched_line_id)) $t",

        /* ------------------------------------------------ budgets */
        "CREATE TABLE IF NOT EXISTS fp_budgets (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, number VARCHAR(30) NOT NULL, entity_id INT UNSIGNED NOT NULL DEFAULT 1, fy SMALLINT NOT NULL,
           name VARCHAR(160) NOT NULL, kind ENUM('operating','capital') NOT NULL DEFAULT 'operating',
           scenario VARCHAR(20) NOT NULL DEFAULT 'budget', revision SMALLINT NOT NULL DEFAULT 0, parent_id INT UNSIGNED NULL,
           status ENUM('draft','submitted','approved','frozen','rejected','superseded') NOT NULL DEFAULT 'draft',
           assumptions TEXT NULL, total $amt, approved_by VARCHAR(160) NULL, approved_at DATETIME NULL, return_note VARCHAR(500) NULL, $who,
           UNIQUE KEY u_number (number), KEY k_fy (fy, scenario, status)) $t",
        "CREATE TABLE IF NOT EXISTS fp_budget_lines (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, budget_id INT UNSIGNED NOT NULL, account_id INT UNSIGNED NOT NULL, cost_centre_id INT UNSIGNED NULL,
           project_id INT UNSIGNED NULL, m1 $amt, m2 $amt, m3 $amt, m4 $amt, m5 $amt, m6 $amt, m7 $amt, m8 $amt, m9 $amt, m10 $amt, m11 $amt, m12 $amt,
           total $amt, notes VARCHAR(255) NULL, KEY k_budget (budget_id), KEY k_key (account_id, cost_centre_id, project_id)) $t",
        "CREATE TABLE IF NOT EXISTS fp_budget_transfers (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, number VARCHAR(30) NOT NULL, budget_id INT UNSIGNED NOT NULL, from_line_id INT UNSIGNED NOT NULL,
           to_line_id INT UNSIGNED NOT NULL, month_no TINYINT NOT NULL, amount $amt, reason VARCHAR(500) NOT NULL,
           status ENUM('draft','submitted','approved','rejected','cancelled') NOT NULL DEFAULT 'draft', return_note VARCHAR(500) NULL, $who,
           UNIQUE KEY u_number (number), KEY k_budget (budget_id)) $t",
        "CREATE TABLE IF NOT EXISTS fp_budget_alerts (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, budget_line_id INT UNSIGNED NOT NULL, threshold SMALLINT NOT NULL, utilisation DECIMAL(7,2) NOT NULL,
           created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY u_alert (budget_line_id, threshold)) $t",

        /* ------------------------------------------------ employee expenses */
        "CREATE TABLE IF NOT EXISTS fp_expense_categories (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL, kind ENUM('normal','travel','accommodation','mileage','per_diem') NOT NULL DEFAULT 'normal',
           account_id INT UNSIGNED NOT NULL, limit_per_item DECIMAL(16,2) NULL, receipt_above DECIMAL(16,2) NULL, active TINYINT(1) NOT NULL DEFAULT 1,
           UNIQUE KEY u_name (name)) $t",
        "CREATE TABLE IF NOT EXISTS fp_advances (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, number VARCHAR(30) NOT NULL, entity_id INT UNSIGNED NOT NULL DEFAULT 1, employee_person_id INT NOT NULL,
           employee_name VARCHAR(160) NOT NULL, cost_centre_id INT UNSIGNED NULL, purpose VARCHAR(500) NOT NULL, needed_by DATE NULL, amount $amt,
           paid_amount $amt, settled_amount $amt, status ENUM('draft','submitted','approved','paid','settled','rejected','cancelled') NOT NULL DEFAULT 'draft',
           journal_id INT UNSIGNED NULL, return_note VARCHAR(500) NULL, $who, UNIQUE KEY u_number (number), KEY k_emp (employee_person_id)) $t",
        "CREATE TABLE IF NOT EXISTS fp_claims (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, number VARCHAR(30) NOT NULL, entity_id INT UNSIGNED NOT NULL DEFAULT 1, employee_person_id INT NOT NULL,
           employee_name VARCHAR(160) NOT NULL, cost_centre_id INT UNSIGNED NULL, project_id INT UNSIGNED NULL, purpose VARCHAR(500) NOT NULL,
           trip_from DATE NULL, trip_to DATE NULL, total $amt, card_total $amt, approved_total $amt, advance_id INT UNSIGNED NULL, advance_adjusted $amt,
           net_payable $amt, paid_amount $amt, policy_flags TEXT NULL,
           status ENUM('draft','submitted','returned','approved','paid','rejected','cancelled') NOT NULL DEFAULT 'draft',
           hr_claim_id INT UNSIGNED NULL, journal_id INT UNSIGNED NULL, submitted_at DATETIME NULL, return_note VARCHAR(1000) NULL, $who,
           UNIQUE KEY u_number (number), UNIQUE KEY u_hr (hr_claim_id), KEY k_emp (employee_person_id, status), KEY k_status (status)) $t",
        "CREATE TABLE IF NOT EXISTS fp_claim_lines (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, claim_id INT UNSIGNED NOT NULL, expense_date DATE NOT NULL, category_id INT UNSIGNED NOT NULL,
           description VARCHAR(255) NOT NULL, merchant VARCHAR(160) NULL, amount $amt, tax_amount $amt, km DECIMAL(10,2) NULL, days DECIMAL(6,2) NULL,
           paid_by ENUM('employee','card') NOT NULL DEFAULT 'employee', approved_amount DECIMAL(16,2) NULL, receipt_file_id INT UNSIGNED NULL, flags VARCHAR(500) NULL,
           KEY k_claim (claim_id)) $t",

        /* ------------------------------------------------ fixed assets */
        "CREATE TABLE IF NOT EXISTS fp_asset_categories (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL, asset_account_id INT UNSIGNED NOT NULL, accum_account_id INT UNSIGNED NOT NULL,
           expense_account_id INT UNSIGNED NOT NULL, method ENUM('slm','wdv') NOT NULL DEFAULT 'slm', life_months SMALLINT NOT NULL DEFAULT 60,
           wdv_rate DECIMAL(7,3) NULL, salvage_pct DECIMAL(6,3) NOT NULL DEFAULT 5, active TINYINT(1) NOT NULL DEFAULT 1, UNIQUE KEY u_name (name)) $t",
        "CREATE TABLE IF NOT EXISTS fp_assets (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, entity_id INT UNSIGNED NOT NULL DEFAULT 1, tag VARCHAR(30) NOT NULL, name VARCHAR(160) NOT NULL,
           category_id INT UNSIGNED NOT NULL, acquired_on DATE NOT NULL, in_service_on DATE NOT NULL, cost $amt, salvage $amt,
           method ENUM('slm','wdv') NOT NULL DEFAULT 'slm', life_months SMALLINT NOT NULL DEFAULT 60, wdv_rate DECIMAL(7,3) NULL,
           accumulated $amt, cost_centre_id INT UNSIGNED NULL, location VARCHAR(120) NULL, custodian VARCHAR(160) NULL, serial VARCHAR(120) NULL,
           vendor_id INT UNSIGNED NULL, bill_id INT UNSIGNED NULL, bill_line_id INT UNSIGNED NULL, source_ref VARCHAR(20) NULL,
           status ENUM('active','disposed','written_off') NOT NULL DEFAULT 'active', disposed_on DATE NULL, disposal_proceeds DECIMAL(16,2) NULL,
           journal_id INT UNSIGNED NULL, disposal_journal_id INT UNSIGNED NULL, notes VARCHAR(500) NULL, $who,
           UNIQUE KEY u_tag (tag), KEY k_status (status), UNIQUE KEY u_source (source_ref)) $t",
        "CREATE TABLE IF NOT EXISTS fp_dep_runs (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, period CHAR(7) NOT NULL, total $amt, assets INT NOT NULL DEFAULT 0, journal_id INT UNSIGNED NULL,
           run_by VARCHAR(160) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY u_period (period)) $t",
        "CREATE TABLE IF NOT EXISTS fp_dep_lines (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, run_id INT UNSIGNED NOT NULL, asset_id INT UNSIGNED NOT NULL, amount $amt,
           UNIQUE KEY u_asset_run (asset_id, run_id), KEY k_run (run_id)) $t",
        "CREATE TABLE IF NOT EXISTS fp_asset_events (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, asset_id INT UNSIGNED NOT NULL, kind VARCHAR(20) NOT NULL, summary VARCHAR(500) NOT NULL,
           actor VARCHAR(160) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY k_asset (asset_id)) $t",

        /* ------------------------------------------------ compliance & audit */
        "CREATE TABLE IF NOT EXISTS fp_controls (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(190) NOT NULL, area VARCHAR(60) NOT NULL, frequency ENUM('monthly','quarterly','yearly') NOT NULL DEFAULT 'monthly',
           owner_role VARCHAR(30) NULL, check_key VARCHAR(30) NULL, description VARCHAR(500) NULL, active TINYINT(1) NOT NULL DEFAULT 1, sort SMALLINT NOT NULL DEFAULT 0) $t",
        "CREATE TABLE IF NOT EXISTS fp_control_runs (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, control_id INT UNSIGNED NOT NULL, period VARCHAR(10) NOT NULL, status ENUM('done','exception','na') NOT NULL,
           note VARCHAR(1000) NULL, actor_id INT NULL, actor VARCHAR(160) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
           UNIQUE KEY u_run (control_id, period)) $t",
        "CREATE TABLE IF NOT EXISTS fp_evidence (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, title VARCHAR(190) NOT NULL, area VARCHAR(60) NOT NULL, fy SMALLINT NOT NULL, notes VARCHAR(500) NULL,
           keep_until DATE NOT NULL, created_by VARCHAR(160) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) $t",
        "CREATE TABLE IF NOT EXISTS fp_issues (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, title VARCHAR(190) NOT NULL, area VARCHAR(60) NOT NULL, severity ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
           owner_person_id INT NULL, due_date DATE NULL, status ENUM('open','in_progress','resolved','accepted') NOT NULL DEFAULT 'open', details TEXT NULL,
           resolution VARCHAR(1000) NULL, $who) $t",
        "CREATE TABLE IF NOT EXISTS fp_imports (
           id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, source VARCHAR(20) NOT NULL, source_id INT UNSIGNED NOT NULL, target VARCHAR(12) NOT NULL, target_id INT UNSIGNED NULL,
           created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY u_source (source, source_id)) $t",
    ];
}

/* ================================================================= seed */

function fp_seed(): void
{
    $orgName = fp_org() > 0
        ? (string) (fabo_one('SELECT COALESCE(NULLIF(organisation, ""), name) n FROM clients WHERE id = ?', 'i', [fp_org()])['n'] ?? 'Main entity')
        : (defined('APP_NAME') && APP_NAME !== '' ? APP_NAME : 'Main entity');
    fp_exec('INSERT IGNORE INTO fp_entities (id, code, name, base_currency) VALUES (1, "MAIN", ?, "INR")', 's', [mb_substr($orgName, 0, 190)]);

    // code, name, type, subtype, parent code, group?, cash flow, system key
    $coa = [
        ['1000', 'Assets', 'asset', 'group', null, 1, 'operating', null],
        ['1100', 'Cash and bank', 'asset', 'group', '1000', 1, 'operating', null],
        ['1110', 'Cash in hand', 'asset', 'cash', '1100', 0, 'operating', 'cash'],
        ['1121', 'Main bank account', 'asset', 'bank', '1100', 0, 'operating', 'bank_main'],
        ['1200', 'Trade receivables', 'asset', 'receivable', '1000', 0, 'operating', 'ar'],
        ['1210', 'Employee advances', 'asset', 'current_asset', '1000', 0, 'operating', 'emp_adv'],
        ['1220', 'Prepaid expenses', 'asset', 'current_asset', '1000', 0, 'operating', 'prepaid'],
        ['1230', 'Other current assets', 'asset', 'current_asset', '1000', 0, 'operating', null],
        ['1300', 'GST input credit', 'asset', 'group', '1000', 1, 'operating', null],
        ['1310', 'Input CGST', 'asset', 'tax_input', '1300', 0, 'operating', 'gst_in_cgst'],
        ['1320', 'Input SGST', 'asset', 'tax_input', '1300', 0, 'operating', 'gst_in_sgst'],
        ['1330', 'Input IGST', 'asset', 'tax_input', '1300', 0, 'operating', 'gst_in_igst'],
        ['1340', 'TDS receivable', 'asset', 'tax_input', '1000', 0, 'operating', 'tds_recv'],
        ['1400', 'Intercompany receivable', 'asset', 'current_asset', '1000', 0, 'operating', 'ic_recv'],
        ['1500', 'Fixed assets', 'asset', 'group', '1000', 1, 'investing', null],
        ['1510', 'Furniture and fixtures', 'asset', 'fixed_asset', '1500', 0, 'investing', 'fa_furniture'],
        ['1520', 'Computers and IT equipment', 'asset', 'fixed_asset', '1500', 0, 'investing', 'fa_computers'],
        ['1530', 'Office equipment', 'asset', 'fixed_asset', '1500', 0, 'investing', 'fa_office'],
        ['1540', 'Vehicles', 'asset', 'fixed_asset', '1500', 0, 'investing', 'fa_vehicles'],
        ['1550', 'Plant and machinery', 'asset', 'fixed_asset', '1500', 0, 'investing', 'fa_plant'],
        ['1590', 'Accumulated depreciation', 'asset', 'accum_dep', '1500', 0, 'investing', 'accum_dep'],
        ['1600', 'Capital work in progress', 'asset', 'fixed_asset', '1500', 0, 'investing', 'cwip'],
        ['2000', 'Liabilities', 'liability', 'group', null, 1, 'operating', null],
        ['2100', 'Trade payables', 'liability', 'payable', '2000', 0, 'operating', 'ap'],
        ['2110', 'Employee reimbursements payable', 'liability', 'current_liability', '2000', 0, 'operating', 'emp_payable'],
        ['2120', 'Salaries payable', 'liability', 'current_liability', '2000', 0, 'operating', 'salary_payable'],
        ['2130', 'Statutory dues payable (PF, ESI, PT)', 'liability', 'current_liability', '2000', 0, 'operating', 'statutory_payable'],
        ['2140', 'Corporate card payable', 'liability', 'current_liability', '2000', 0, 'operating', 'card_payable'],
        ['2150', 'Accrued expenses', 'liability', 'current_liability', '2000', 0, 'operating', 'accrued'],
        ['2160', 'Customer advances', 'liability', 'current_liability', '2000', 0, 'operating', 'cust_adv'],
        ['2200', 'GST output', 'liability', 'group', '2000', 1, 'operating', null],
        ['2210', 'Output CGST', 'liability', 'tax_output', '2200', 0, 'operating', 'gst_out_cgst'],
        ['2220', 'Output SGST', 'liability', 'tax_output', '2200', 0, 'operating', 'gst_out_sgst'],
        ['2230', 'Output IGST', 'liability', 'tax_output', '2200', 0, 'operating', 'gst_out_igst'],
        ['2240', 'TDS payable', 'liability', 'tax_output', '2000', 0, 'operating', 'tds_payable'],
        ['2300', 'Intercompany payable', 'liability', 'current_liability', '2000', 0, 'operating', 'ic_pay'],
        ['2400', 'Borrowings', 'liability', 'long_term', '2000', 0, 'financing', 'loans'],
        ['3000', 'Equity', 'equity', 'group', null, 1, 'financing', null],
        ['3100', 'Share capital / owner\'s capital', 'equity', 'capital', '3000', 0, 'financing', 'capital'],
        ['3200', 'Retained earnings', 'equity', 'retained', '3000', 0, 'financing', 'retained'],
        ['3300', 'Opening balance equity', 'equity', 'capital', '3000', 0, 'financing', 'opening_equity'],
        ['4000', 'Income', 'income', 'group', null, 1, 'operating', null],
        ['4100', 'Revenue from services', 'income', 'revenue', '4000', 0, 'operating', 'revenue'],
        ['4200', 'Sale of goods', 'income', 'revenue', '4000', 0, 'operating', null],
        ['4300', 'Other income', 'income', 'other_income', '4000', 0, 'operating', 'other_income'],
        ['4310', 'Interest income', 'income', 'other_income', '4000', 0, 'operating', 'interest_income'],
        ['4320', 'Gain or loss on asset disposal', 'income', 'other_income', '4000', 0, 'investing', 'asset_gain_loss'],
        ['4330', 'Exchange gain or loss', 'income', 'other_income', '4000', 0, 'operating', 'fx_gain_loss'],
        ['5000', 'Direct costs', 'expense', 'group', null, 1, 'operating', null],
        ['5100', 'Cost of services', 'expense', 'cogs', '5000', 0, 'operating', 'cogs'],
        ['6000', 'Operating expenses', 'expense', 'group', null, 1, 'operating', null],
        ['6100', 'Salaries and wages', 'expense', 'opex', '6000', 0, 'operating', 'salary_exp'],
        ['6110', 'Employer contributions', 'expense', 'opex', '6000', 0, 'operating', 'employer_contrib'],
        ['6200', 'Rent', 'expense', 'opex', '6000', 0, 'operating', null],
        ['6210', 'Electricity and utilities', 'expense', 'opex', '6000', 0, 'operating', null],
        ['6300', 'Travel and conveyance', 'expense', 'opex', '6000', 0, 'operating', 'travel'],
        ['6310', 'Accommodation', 'expense', 'opex', '6000', 0, 'operating', 'accommodation'],
        ['6320', 'Meals and per diem', 'expense', 'opex', '6000', 0, 'operating', 'per_diem'],
        ['6330', 'Local conveyance and mileage', 'expense', 'opex', '6000', 0, 'operating', 'mileage'],
        ['6400', 'Software and subscriptions', 'expense', 'opex', '6000', 0, 'operating', 'software'],
        ['6410', 'Internet and telephone', 'expense', 'opex', '6000', 0, 'operating', null],
        ['6500', 'Advertising and marketing', 'expense', 'opex', '6000', 0, 'operating', 'marketing'],
        ['6510', 'Events', 'expense', 'opex', '6000', 0, 'operating', 'events'],
        ['6600', 'Professional fees', 'expense', 'opex', '6000', 0, 'operating', 'professional'],
        ['6610', 'Contractors', 'expense', 'opex', '6000', 0, 'operating', null],
        ['6700', 'Office supplies', 'expense', 'opex', '6000', 0, 'operating', 'office'],
        ['6710', 'Repairs and maintenance', 'expense', 'opex', '6000', 0, 'operating', null],
        ['6800', 'Bank charges', 'expense', 'opex', '6000', 0, 'operating', 'bank_charges'],
        ['6900', 'Depreciation', 'expense', 'depreciation', '6000', 0, 'operating', 'depreciation'],
        ['6910', 'Interest expense', 'expense', 'finance_cost', '6000', 0, 'financing', 'interest_exp'],
        ['6990', 'Miscellaneous expenses', 'expense', 'opex', '6000', 0, 'operating', 'misc'],
    ];
    $ids = [];
    foreach ($coa as [$code, $name, $type, $sub, $parent, $group, $cf, $key]) {
        $ids[$code] = fp_exec('INSERT INTO fp_accounts (code, name, type, subtype, parent_id, is_group, cash_flow, system_key) VALUES (?,?,?,?,?,?,?,?)',
                              'ssssiiss', [$code, $name, $type, $sub, $parent ? $ids[$parent] : null, $group, $cf, $key]);
    }
    fp_exec('INSERT INTO fp_bank_accounts (entity_id, name, kind, account_id) VALUES (1, "Main bank account", "bank", ?), (1, "Cash in hand", "cash", ?)',
            'ii', [$ids['1121'], $ids['1110']]);

    // Tax codes. Rates are the ordinary ones at the time of writing and are the organisation's to change.
    foreach ([
        ['GST0', 'GST 0% / nil rated', 'gst', 0, null], ['GST5', 'GST 5% (intra-state)', 'gst', 5, null], ['GST12', 'GST 12% (intra-state)', 'gst', 12, null],
        ['GST18', 'GST 18% (intra-state)', 'gst', 18, null], ['GST28', 'GST 28% (intra-state)', 'gst', 28, null],
        ['IGST5', 'IGST 5% (inter-state)', 'igst', 5, null], ['IGST12', 'IGST 12% (inter-state)', 'igst', 12, null],
        ['IGST18', 'IGST 18% (inter-state)', 'igst', 18, null], ['IGST28', 'IGST 28% (inter-state)', 'igst', 28, null],
        ['EXEMPT', 'Exempt / non-GST', 'exempt', 0, null],
        ['TDS194C1', 'TDS 194C contractors (individual) 1%', 'tds', 1, '194C'], ['TDS194C2', 'TDS 194C contractors (company) 2%', 'tds', 2, '194C'],
        ['TDS194J', 'TDS 194J professional / technical 10%', 'tds', 10, '194J'], ['TDS194I', 'TDS 194I rent 10%', 'tds', 10, '194I'],
        ['TDS194H', 'TDS 194H commission 5%', 'tds', 5, '194H'],
    ] as [$code, $name, $kind, $rate, $sec]) {
        fp_exec('INSERT INTO fp_tax_codes (code, name, kind, rate, section) VALUES (?,?,?,?,?)', 'sssds', [$code, $name, $kind, $rate, $sec]);
    }

    foreach ([
        ['Travel (air, rail, bus, cab)', 'travel', '6300', null], ['Accommodation', 'accommodation', '6310', null], ['Meals', 'normal', '6320', null],
        ['Per diem', 'per_diem', '6320', null], ['Mileage (own vehicle)', 'mileage', '6330', null], ['Local conveyance', 'normal', '6330', null],
        ['Software and subscriptions', 'normal', '6400', null], ['Internet and phone', 'normal', '6410', null], ['Office supplies', 'normal', '6700', null],
        ['Client entertainment', 'normal', '6500', null], ['Other', 'normal', '6990', null],
    ] as [$name, $kind, $code, $limit]) {
        fp_exec('INSERT INTO fp_expense_categories (name, kind, account_id, limit_per_item) VALUES (?,?,?,?)', 'ssis', [$name, $kind, $ids[$code], $limit]);
    }

    foreach ([
        ['Furniture and fixtures', '1510', 'slm', 120, null], ['Computers and IT equipment', '1520', 'slm', 36, null],
        ['Office equipment', '1530', 'slm', 60, null], ['Vehicles', '1540', 'wdv', 96, 25.89], ['Plant and machinery', '1550', 'slm', 180, null],
    ] as [$name, $code, $method, $life, $wdv]) {
        fp_exec('INSERT INTO fp_asset_categories (name, asset_account_id, accum_account_id, expense_account_id, method, life_months, wdv_rate) VALUES (?,?,?,?,?,?,?)',
                'siiisis', [$name, $ids[$code], $ids['1590'], $ids['6900'], $method, $life, $wdv]);
    }

    fp_exec('INSERT INTO fp_cost_centres (code, name) VALUES ("GEN", "General / administration")');

    // Approval rules: [doc, level, label, min, type, roles]
    foreach ([
        ['journal', 1, 'Finance review', 0, 'role', 'finance_manager,controller,cfo'],
        ['bill', 1, 'Finance approval', 0, 'role', 'finance_manager,controller,cfo'],
        ['bill', 2, 'CFO approval (large bills)', 500000, 'role', 'cfo'],
        ['invoice', 1, 'Invoice approval', 0, 'role', 'finance_manager,controller,cfo'],
        ['payment', 1, 'Payment authorisation', 0, 'role', 'finance_manager,controller,cfo'],
        ['payment', 2, 'Second authorisation (large payments)', 1000000, 'role', 'cfo'],
        ['batch', 1, 'Batch authorisation', 0, 'role', 'finance_manager,controller,cfo'],
        ['claim', 1, 'Manager approval', 0, 'manager', null],
        ['claim', 2, 'Finance review', 0, 'role', 'ap_accountant,finance_manager'],
        ['advance', 1, 'Manager approval', 0, 'manager', null],
        ['advance', 2, 'Finance approval', 0, 'role', 'finance_manager'],
        ['budget', 1, 'Finance manager review', 0, 'role', 'finance_manager,controller'],
        ['budget', 2, 'CFO approval', 0, 'role', 'cfo'],
        ['transfer', 1, 'Budget transfer approval', 0, 'role', 'finance_manager,controller,cfo'],
        ['vendor', 1, 'Vendor verification', 0, 'role', 'finance_manager,controller'],
        ['pr', 1, 'Manager approval', 0, 'manager', null],
        ['po', 1, 'Budget holder approval', 0, 'cc_owner', null],
        ['po', 2, 'Finance approval (large orders)', 200000, 'role', 'finance_manager,cfo'],
    ] as [$doc, $lvl, $label, $min, $type, $roles]) {
        fp_exec('INSERT INTO fp_wf_rules (doc_type, level, label, min_amount, approver_type, approver_roles) VALUES (?,?,?,?,?,?)',
                'sisdss', [$doc, $lvl, $label, $min, $type, $roles]);
    }

    // The month-end checklist. check_key ties a control to what the system can verify by itself.
    foreach ([
        ['All journals for the month posted or cancelled', 'General ledger', 'monthly', 'accountant', 'drafts'],
        ['Bank accounts reconciled to statements', 'Treasury', 'monthly', 'accountant', 'bank_rec'],
        ['Depreciation run for the month', 'Fixed assets', 'monthly', 'accountant', 'depreciation'],
        ['Accruals and prepayments reviewed', 'General ledger', 'monthly', 'accountant', null],
        ['Payables aging reviewed; no bills stuck in approval', 'Payables', 'monthly', 'ap_accountant', 'ap_pending'],
        ['Receivables aging reviewed; overdue followed up', 'Receivables', 'monthly', 'ar_accountant', null],
        ['GST input vs output reconciled to returns', 'Tax', 'monthly', 'accountant', null],
        ['TDS deducted deposited by the due date', 'Tax', 'monthly', 'accountant', null],
        ['Payroll journal posted and reconciled with HR', 'Payroll', 'monthly', 'accountant', 'payroll'],
        ['Budget vs actual reviewed with budget holders', 'Budgets', 'monthly', 'fpa', null],
        ['Trial balance reviewed and signed off', 'General ledger', 'monthly', 'finance_manager', null],
        ['Fixed asset physical verification', 'Fixed assets', 'yearly', 'accountant', null],
        ['Vendor master changes reviewed', 'Payables', 'quarterly', 'finance_manager', null],
    ] as $i => [$name, $area, $freq, $role, $key]) {
        fp_exec('INSERT INTO fp_controls (name, area, frequency, owner_role, check_key, sort) VALUES (?,?,?,?,?,?)', 'sssssi', [$name, $area, $freq, $role, $key, $i]);
    }

    fp_periods_ensure(fp_fy_of());
}

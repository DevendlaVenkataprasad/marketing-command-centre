-- HR Management update: biometric machine import.
-- Only for a database where the HR tables already exist. The portal runs this
-- by itself the first time an HR page is opened after the update, so running
-- it by hand is optional. Run each statement once; "Duplicate column" or
-- "Duplicate key name" means it was already done.
ALTER TABLE hr_employees ADD COLUMN biometric_id VARCHAR(40) NULL AFTER emp_code;
ALTER TABLE hr_employees ADD UNIQUE KEY u_org_bio (org_id, biometric_id);

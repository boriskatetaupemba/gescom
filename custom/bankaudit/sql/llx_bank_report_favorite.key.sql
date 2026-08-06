-- Copyright (C) 2026 BankAudit module
--
-- Indexes for llx_bank_report_favorite

ALTER TABLE llx_bank_report_favorite ADD INDEX idx_bank_report_favorite_user (entity, fk_user, tms);
ALTER TABLE llx_bank_report_favorite ADD INDEX idx_bank_report_favorite_code (entity, report_code);

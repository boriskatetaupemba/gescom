-- Copyright (C) 2026 BankAudit module
--
-- Indexes for llx_bank_report_export_log

ALTER TABLE llx_bank_report_export_log ADD INDEX idx_bank_report_export_log_tms (entity, tms);
ALTER TABLE llx_bank_report_export_log ADD INDEX idx_bank_report_export_log_user (entity, fk_user, tms);
ALTER TABLE llx_bank_report_export_log ADD INDEX idx_bank_report_export_log_code (entity, report_code, export_format);

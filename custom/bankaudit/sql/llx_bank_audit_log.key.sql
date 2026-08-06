-- Copyright (C) 2026 BankAudit module
--
-- Indexes for llx_bank_audit_log

ALTER TABLE llx_bank_audit_log ADD INDEX idx_bank_audit_object (object_type, object_id);
ALTER TABLE llx_bank_audit_log ADD INDEX idx_bank_audit_tms (tms);
ALTER TABLE llx_bank_audit_log ADD INDEX idx_bank_audit_user (fk_user);

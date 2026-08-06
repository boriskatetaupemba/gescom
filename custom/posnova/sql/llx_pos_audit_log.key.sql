-- Copyright (C) 2026 PosNova module
--
-- Indexes for llx_pos_audit_log

ALTER TABLE llx_pos_audit_log ADD INDEX idx_pos_audit_tms (tms);
ALTER TABLE llx_pos_audit_log ADD INDEX idx_pos_audit_pos (fk_pos);
ALTER TABLE llx_pos_audit_log ADD INDEX idx_pos_audit_user (fk_user);
ALTER TABLE llx_pos_audit_log ADD INDEX idx_pos_audit_action (action);

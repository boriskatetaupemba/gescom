-- Copyright (C) 2026 BankAudit module
--
-- Indexes for llx_bank_anomaly_log

ALTER TABLE llx_bank_anomaly_log ADD INDEX idx_anomaly_log_statut (statut, tms);
ALTER TABLE llx_bank_anomaly_log ADD INDEX idx_anomaly_log_bank (fk_bank);
ALTER TABLE llx_bank_anomaly_log ADD INDEX idx_anomaly_log_account (fk_account);

-- Copyright (C) 2026 BankAudit module
--
-- Indexes for llx_bank_anomaly_rule

ALTER TABLE llx_bank_anomaly_rule ADD UNIQUE INDEX uk_bank_anomaly_rule (code, entity);

-- Copyright (C) 2026 BankAudit module
--
-- Indexes for llx_bank_dashboard_config

ALTER TABLE llx_bank_dashboard_config ADD UNIQUE INDEX uk_bank_dashboard_config (fk_account, entity);

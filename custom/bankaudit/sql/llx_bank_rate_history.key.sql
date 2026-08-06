-- Copyright (C) 2026 BankAudit module
--
-- Indexes for llx_bank_rate_history

ALTER TABLE llx_bank_rate_history ADD INDEX idx_rate_hist_codes (code_from, code_to, tms);

-- Copyright (C) 2026 PosNova module
--
-- Indexes for llx_pos_config_account

ALTER TABLE llx_pos_config_account ADD UNIQUE INDEX uk_pos_config_account (fk_pos, fk_account);
ALTER TABLE llx_pos_config_account ADD INDEX idx_pos_config_account_pos (fk_pos);

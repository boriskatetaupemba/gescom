-- Copyright (C) 2026 PosNova module
--
-- Indexes and uniqueness for llx_pos_config

ALTER TABLE llx_pos_config ADD UNIQUE INDEX uk_pos_config_ref (ref, entity);
ALTER TABLE llx_pos_config ADD INDEX idx_pos_config_warehouse (fk_warehouse);
ALTER TABLE llx_pos_config ADD INDEX idx_pos_config_active (active, entity);

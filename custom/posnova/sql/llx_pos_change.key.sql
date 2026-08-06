-- Copyright (C) 2026 PosNova module
--
-- Indexes for llx_pos_change

ALTER TABLE llx_pos_change ADD INDEX idx_pos_change_ticket (fk_ticket);

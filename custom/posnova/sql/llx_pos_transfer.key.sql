-- Copyright (C) 2026 PosNova module
--
-- Indexes for llx_pos_transfer

ALTER TABLE llx_pos_transfer ADD INDEX idx_pos_transfer_status (status, entity);
ALTER TABLE llx_pos_transfer ADD INDEX idx_pos_transfer_whto (wh_to, status);
ALTER TABLE llx_pos_transfer ADD INDEX idx_pos_transfer_initiator (fk_user_initiator);

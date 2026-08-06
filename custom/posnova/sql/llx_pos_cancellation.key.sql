-- Copyright (C) 2026 PosNova module
--
-- Indexes for llx_pos_cancellation

ALTER TABLE llx_pos_cancellation ADD INDEX idx_pos_cancellation_ticket (fk_ticket);
ALTER TABLE llx_pos_cancellation ADD INDEX idx_pos_cancellation_status (status, entity);

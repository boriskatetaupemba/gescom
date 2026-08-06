-- Copyright (C) 2026 PosNova module
--
-- Indexes for llx_pos_payment

ALTER TABLE llx_pos_payment ADD INDEX idx_pos_payment_ticket (fk_ticket);
ALTER TABLE llx_pos_payment ADD INDEX idx_pos_payment_account (fk_account);

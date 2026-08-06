-- Copyright (C) 2026 PosNova module
--
-- Indexes for llx_pos_ticket_line

ALTER TABLE llx_pos_ticket_line ADD INDEX idx_pos_ticket_line_ticket (fk_ticket);
ALTER TABLE llx_pos_ticket_line ADD INDEX idx_pos_ticket_line_product (fk_product);

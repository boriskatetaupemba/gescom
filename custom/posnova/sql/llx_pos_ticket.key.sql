-- Copyright (C) 2026 PosNova module
--
-- Indexes for llx_pos_ticket

ALTER TABLE llx_pos_ticket ADD UNIQUE INDEX uk_pos_ticket_ref (ref, entity);
ALTER TABLE llx_pos_ticket ADD INDEX idx_pos_ticket_session (fk_session);
ALTER TABLE llx_pos_ticket ADD INDEX idx_pos_ticket_facture (fk_facture);
ALTER TABLE llx_pos_ticket ADD INDEX idx_pos_ticket_status (status, sync_status);

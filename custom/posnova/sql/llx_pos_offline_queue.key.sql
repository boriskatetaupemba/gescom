-- Copyright (C) 2026 PosNova module
--
-- Indexes for llx_pos_offline_queue

ALTER TABLE llx_pos_offline_queue ADD UNIQUE INDEX uk_pos_offline_uid (client_uid);
ALTER TABLE llx_pos_offline_queue ADD INDEX idx_pos_offline_session (fk_session, sync_status);

-- Copyright (C) 2026 PosNova module
--
-- Indexes for llx_pos_session

ALTER TABLE llx_pos_session ADD UNIQUE INDEX uk_pos_session_ref (ref, entity);
ALTER TABLE llx_pos_session ADD UNIQUE INDEX uk_pos_session_token (session_token);
ALTER TABLE llx_pos_session ADD INDEX idx_pos_session_pos (fk_pos, status);
ALTER TABLE llx_pos_session ADD INDEX idx_pos_session_user (fk_user_open);

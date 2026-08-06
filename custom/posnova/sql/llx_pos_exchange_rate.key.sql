-- Copyright (C) 2026 PosNova module
--
-- Indexes for llx_pos_exchange_rate

ALTER TABLE llx_pos_exchange_rate ADD UNIQUE INDEX uk_pos_exchange_rate_day (day, entity);

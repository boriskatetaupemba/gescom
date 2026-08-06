-- Copyright (C) 2026 PosNova module
--
-- Cash session. Decoupled from the HTTP session: survives browser/network outage.

CREATE TABLE llx_pos_session
(
	rowid              integer AUTO_INCREMENT PRIMARY KEY,
	entity             integer      NOT NULL DEFAULT 1,
	ref                varchar(64)  NOT NULL,                   -- POS + date + sequence
	fk_pos             integer      NOT NULL,                   -- llx_pos_config.rowid
	fk_warehouse       integer      NOT NULL,                   -- inherited from POS
	fk_user_open       integer      NOT NULL,                   -- vendor who opened
	fk_rate            integer      DEFAULT NULL,               -- llx_pos_exchange_rate.rowid applied
	rate_usd_cdf       double(24,8)  DEFAULT NULL,              -- snapshot of the rate value
	rate_source        varchar(10)  DEFAULT NULL,              -- MANUAL | SYSTEM
	session_token      varchar(64)  NOT NULL,                   -- long-lived POS token (distinct from HTTP)
	fund_init_usd      double(24,8)  DEFAULT 0,
	fund_init_cdf      double(24,8)  DEFAULT 0,
	fund_final_usd     double(24,8)  DEFAULT NULL,              -- counted at close
	fund_final_cdf     double(24,8)  DEFAULT NULL,
	reconnection_count integer      DEFAULT 0,
	pin_lock           varchar(255) DEFAULT NULL,               -- hashed PIN when locked (pause)
	status             varchar(12)  NOT NULL DEFAULT 'OPEN',    -- OPEN | LOCKED | CLOSED | CANCELLED
	date_open          datetime     NOT NULL,
	date_close         datetime     DEFAULT NULL,
	last_activity      datetime     DEFAULT NULL,
	tms                timestamp    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;

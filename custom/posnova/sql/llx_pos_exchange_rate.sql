-- Copyright (C) 2026 PosNova module
--
-- Daily USD/CDF exchange rate. Manual entry, fallback on multicurrency module.

CREATE TABLE llx_pos_exchange_rate
(
	rowid         integer AUTO_INCREMENT PRIMARY KEY,
	entity        integer      NOT NULL DEFAULT 1,
	day           date         NOT NULL,                      -- the day the rate applies to
	rate_usd_cdf  double(24,8)  NOT NULL,                      -- 1 USD = rate CDF
	source        varchar(10)  NOT NULL DEFAULT 'MANUAL',     -- MANUAL | SYSTEM
	fk_user       integer      DEFAULT NULL,
	datec         datetime     DEFAULT NULL,
	tms           timestamp    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;

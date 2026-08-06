-- Copyright (C) 2026 BankAudit module
--
-- History of exchange rate changes (P4).

CREATE TABLE llx_bank_rate_history
(
	rowid         integer AUTO_INCREMENT PRIMARY KEY,
	tms           datetime      NOT NULL,
	entity        integer       NOT NULL DEFAULT 1,
	fk_user       integer       NOT NULL,
	code_from     varchar(3)    NOT NULL,             -- source currency (ex: USD = main currency)
	code_to       varchar(3)    NOT NULL,             -- target currency (ex: CDF)
	rate_old      double(24,8)  DEFAULT NULL,         -- previous rate
	rate_new      double(24,8)  NOT NULL,             -- new rate
	variation_pct double(8,4)   DEFAULT NULL,         -- variation in % vs previous rate
	alerte        tinyint       NOT NULL DEFAULT 0,   -- 1 if variation > threshold
	ip            varchar(45)   DEFAULT NULL,
	source        varchar(50)   NOT NULL DEFAULT 'manual'  -- 'manual' | 'api'
) ENGINE=innodb;

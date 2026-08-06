-- Copyright (C) 2026 PosNova module
--
-- Accounts / cash registers activated on a POS (subset of the warehouse accounts).

CREATE TABLE llx_pos_config_account
(
	rowid                integer AUTO_INCREMENT PRIMARY KEY,
	entity               integer     NOT NULL DEFAULT 1,
	fk_pos               integer     NOT NULL,                  -- llx_pos_config.rowid
	fk_account           integer     NOT NULL,                  -- llx_bank_account.rowid
	is_default           tinyint     DEFAULT 0,                 -- default account of its currency
	is_active            tinyint     DEFAULT 1,
	default_payment_mode varchar(16) DEFAULT NULL,             -- payment mode code for this account
	position             integer     DEFAULT 0
) ENGINE=innodb;

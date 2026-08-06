-- Copyright (C) 2026 BankAudit module
--
-- Configuration of anomaly detection rules (P12).

CREATE TABLE llx_bank_anomaly_rule
(
	rowid         integer AUTO_INCREMENT PRIMARY KEY,
	code          varchar(50)   NOT NULL,            -- rule identifier (R01, R02, ...)
	label         varchar(200)  NOT NULL,
	active        tinyint       NOT NULL DEFAULT 1,
	seuil         double(24,8)  DEFAULT NULL,         -- trigger value
	seuil_unite   varchar(20)   DEFAULT NULL,         -- 'USD' | '%' | 'jours' | 'x'
	fk_account    integer       DEFAULT NULL,         -- NULL = all accounts
	action_email  tinyint       NOT NULL DEFAULT 1,
	action_notif  tinyint       NOT NULL DEFAULT 1,
	fk_user_notif integer       DEFAULT NULL,
	entity        integer       NOT NULL DEFAULT 1
) ENGINE=innodb;

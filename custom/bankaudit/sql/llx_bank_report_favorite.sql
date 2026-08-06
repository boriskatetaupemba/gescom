-- Copyright (C) 2026 BankAudit module
--
-- Saved report filter presets per user.

CREATE TABLE llx_bank_report_favorite
(
	rowid        integer AUTO_INCREMENT PRIMARY KEY,
	entity       integer      NOT NULL DEFAULT 1,
	fk_user      integer      NOT NULL,
	label        varchar(255) NOT NULL,
	report_code  varchar(20)  NOT NULL,
	filters_json text         DEFAULT NULL,
	tms          datetime     NOT NULL
) ENGINE=innodb;

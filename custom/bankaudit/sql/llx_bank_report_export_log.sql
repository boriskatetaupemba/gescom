-- Copyright (C) 2026 BankAudit module
--
-- Export history for confidentiality/audit.

CREATE TABLE llx_bank_report_export_log
(
	rowid         integer AUTO_INCREMENT PRIMARY KEY,
	entity        integer      NOT NULL DEFAULT 1,
	fk_user       integer      NOT NULL,
	report_code   varchar(20)  NOT NULL,
	export_format varchar(10)  NOT NULL,
	filters_json  text         DEFAULT NULL,
	ip            varchar(45)  DEFAULT NULL,
	tms           datetime     NOT NULL
) ENGINE=innodb;

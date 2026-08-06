-- Copyright (C) 2026 BankAudit module
--
-- History of detected anomalies (P12).

CREATE TABLE llx_bank_anomaly_log
(
	rowid        integer AUTO_INCREMENT PRIMARY KEY,
	tms          datetime     NOT NULL,
	entity       integer      NOT NULL DEFAULT 1,
	fk_rule      integer      NOT NULL,
	rule_code    varchar(50)  DEFAULT NULL,
	fk_bank      integer      DEFAULT NULL,           -- concerned bank line
	fk_account   integer      DEFAULT NULL,           -- concerned account
	severity     varchar(20)  NOT NULL DEFAULT 'warning',  -- 'info' | 'warning' | 'critical'
	detail       text         DEFAULT NULL,            -- JSON: values that triggered the rule
	statut       varchar(20)  NOT NULL DEFAULT 'new',  -- 'new' | 'ack' | 'false_positive'
	fk_user_ack  integer      DEFAULT NULL,
	tms_ack      datetime     DEFAULT NULL
) ENGINE=innodb;

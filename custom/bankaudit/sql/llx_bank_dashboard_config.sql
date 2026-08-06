-- Copyright (C) 2026 BankAudit module
--
-- Per-account configuration: minimum balance threshold (P1 dashboard + P2 transfer guard).
-- seuil_min NULL means no balance blocking is applied for this account.

CREATE TABLE llx_bank_dashboard_config
(
	rowid         integer AUTO_INCREMENT PRIMARY KEY,
	fk_account    integer       NOT NULL,
	seuil_min     double(24,8)  DEFAULT NULL,         -- minimum allowed balance after operation (NULL = disabled)
	seuil_devise  varchar(3)    NOT NULL DEFAULT 'USD',
	fk_user_notif integer       DEFAULT NULL,         -- user to notify
	entity        integer       NOT NULL DEFAULT 1
) ENGINE=innodb;

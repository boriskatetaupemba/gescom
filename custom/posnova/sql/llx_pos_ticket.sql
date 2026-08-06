-- Copyright (C) 2026 PosNova module
--
-- POS ticket. Linked to a Dolibarr invoice but flagged origin=pos and excluded from standard lists.

CREATE TABLE llx_pos_ticket
(
	rowid          integer AUTO_INCREMENT PRIMARY KEY,
	entity         integer      NOT NULL DEFAULT 1,
	ref            varchar(64)  DEFAULT NULL,                  -- server-generated sequence, e.g. POS001-2026-00001
	fk_session     integer      NOT NULL,                      -- llx_pos_session.rowid
	fk_pos         integer      NOT NULL,
	fk_facture     integer      DEFAULT NULL,                  -- llx_facture.rowid (cohérence comptable)
	fk_soc         integer      DEFAULT NULL,                  -- customer
	currency       varchar(3)   NOT NULL DEFAULT 'USD',        -- POS working currency
	rate_usd_cdf   double(24,8)  DEFAULT NULL,                 -- rate snapshot used
	total_ht       double(24,8)  DEFAULT 0,
	total_tva      double(24,8)  DEFAULT 0,
	total_ttc      double(24,8)  DEFAULT 0,
	total_discount double(24,8)  DEFAULT 0,
	is_backdated   tinyint      DEFAULT 0,
	invoice_date   date         DEFAULT NULL,
	status         varchar(20)  NOT NULL DEFAULT 'VALIDATED',  -- DRAFT | VALIDATED | CANCEL_PENDING | CANCELLED
	sync_status    varchar(16)  NOT NULL DEFAULT 'SYNCED',     -- SYNCED | PENDING_SYNC
	created_offline tinyint     DEFAULT 0,
	fk_user_creat  integer      DEFAULT NULL,
	datec          datetime     DEFAULT NULL,
	tms            timestamp    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;

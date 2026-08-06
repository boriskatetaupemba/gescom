-- Copyright (C) 2026 PosNova module
--
-- Payment received on a ticket, per account / currency / mode.

CREATE TABLE llx_pos_payment
(
	rowid       integer AUTO_INCREMENT PRIMARY KEY,
	fk_ticket   integer      NOT NULL,                      -- llx_pos_ticket.rowid
	fk_account  integer      NOT NULL,                      -- llx_bank_account.rowid
	fk_payment  integer      DEFAULT NULL,                  -- llx_paiement.rowid (Dolibarr)
	amount      double(24,8)  NOT NULL DEFAULT 0,           -- amount in the account currency
	currency    varchar(3)   NOT NULL,
	mode        varchar(16)  DEFAULT NULL,                  -- payment mode code
	ref_ext     varchar(128) DEFAULT NULL,                  -- transaction / cheque / transfer reference
	datec       datetime     DEFAULT NULL
) ENGINE=innodb;

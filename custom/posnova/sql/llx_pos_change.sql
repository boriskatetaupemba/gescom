-- Copyright (C) 2026 PosNova module
--
-- Change given back to the customer, per account / currency.

CREATE TABLE llx_pos_change
(
	rowid      integer AUTO_INCREMENT PRIMARY KEY,
	fk_ticket  integer      NOT NULL,                       -- llx_pos_ticket.rowid
	fk_account integer      NOT NULL,                       -- llx_bank_account.rowid
	amount     double(24,8)  NOT NULL DEFAULT 0,
	currency   varchar(3)   NOT NULL
) ENGINE=innodb;

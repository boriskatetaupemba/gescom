-- Copyright (C) 2026 PosNova module
--
-- Inter-warehouse transfer request. wh_from is locked server-side to the POS warehouse.

CREATE TABLE llx_pos_transfer
(
	rowid            integer AUTO_INCREMENT PRIMARY KEY,
	entity           integer      NOT NULL DEFAULT 1,
	fk_pos           integer      NOT NULL,
	fk_user_initiator integer     NOT NULL,
	fk_product       integer      NOT NULL,
	qty              double(24,8)  NOT NULL DEFAULT 0,
	wh_from          integer      NOT NULL,                     -- locked = POS warehouse
	wh_to            integer      NOT NULL,
	motif            varchar(255) DEFAULT NULL,
	status           varchar(16)  NOT NULL DEFAULT 'PENDING',   -- PENDING | APPROVED | REFUSED | CANCELLED | EXPIRED
	fk_user_approver integer      DEFAULT NULL,
	motif_refus      text         DEFAULT NULL,
	date_request     datetime     NOT NULL,
	date_decision    datetime     DEFAULT NULL,
	expires_at       datetime     DEFAULT NULL
) ENGINE=innodb;

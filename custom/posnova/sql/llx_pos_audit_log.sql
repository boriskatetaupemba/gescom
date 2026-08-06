-- Copyright (C) 2026 PosNova module
--
-- Immutable audit journal: who / what / when / before / after.

CREATE TABLE llx_pos_audit_log
(
	rowid      integer AUTO_INCREMENT PRIMARY KEY,
	entity     integer      NOT NULL DEFAULT 1,
	tms        datetime     NOT NULL,
	fk_pos     integer      DEFAULT NULL,
	fk_user    integer      NOT NULL,
	action     varchar(48)  NOT NULL,                       -- e.g. SALE, CANCEL, RATE_CHANGE, PRICE_OVERRIDE, TRANSFER
	object_type varchar(48) DEFAULT NULL,
	object_id  integer      DEFAULT NULL,
	before_val text         DEFAULT NULL,
	after_val  text         DEFAULT NULL,
	ip         varchar(45)  DEFAULT NULL
) ENGINE=innodb;

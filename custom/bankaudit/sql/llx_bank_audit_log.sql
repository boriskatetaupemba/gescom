-- Copyright (C) 2026 BankAudit module
--
-- This program is free software; you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation; either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
-- GNU General Public License for more details.
--
-- Historization of CREATE/UPDATE/DELETE on bank accounts and bank lines.

CREATE TABLE llx_bank_audit_log
(
	rowid        integer AUTO_INCREMENT PRIMARY KEY,
	tms          datetime     NOT NULL,
	entity       integer      NOT NULL DEFAULT 1,
	fk_user      integer      NOT NULL,
	object_type  varchar(50)  NOT NULL,             -- 'bank_account' | 'bank_line'
	object_id    integer      NOT NULL,             -- rowid of concerned object
	action       varchar(20)  NOT NULL,             -- 'CREATE' | 'UPDATE' | 'DELETE'
	field_name   varchar(100) DEFAULT NULL,         -- modified field (NULL for CREATE/DELETE)
	old_value    text         DEFAULT NULL,
	new_value    text         DEFAULT NULL,
	ip           varchar(45)  DEFAULT NULL,
	context      text         DEFAULT NULL           -- JSON: complementary info
) ENGINE=innodb;

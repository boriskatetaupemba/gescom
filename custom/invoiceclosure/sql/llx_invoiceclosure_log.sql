-- ============================================================================
-- Copyright (C) 2026 InvoiceClosure module
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
-- You should have received a copy of the GNU General Public License
-- along with this program. If not, see <https://www.gnu.org/licenses/>.
--
-- History table of the InvoiceClosure module. Every closure (CLOSE) and
-- every reopening (REOPEN) is kept forever. There is intentionally NO
-- foreign key on fk_facture so the audit trail survives an invoice deletion.
-- ============================================================================

CREATE TABLE llx_invoiceclosure_log(
	rowid          integer AUTO_INCREMENT PRIMARY KEY,
	entity         integer DEFAULT 1 NOT NULL,
	fk_facture     integer NOT NULL,
	action_code    varchar(16) NOT NULL,
	action_date    datetime NOT NULL,
	fk_user_action integer NOT NULL,
	action_note    text NULL,
	action_source  varchar(8) DEFAULT 'UI' NOT NULL,
	request_id     varchar(64) NULL,
	date_creation  datetime NOT NULL,
	tms            timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;

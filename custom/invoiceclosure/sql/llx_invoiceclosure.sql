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
-- Main table of the InvoiceClosure module: business closure state of a
-- customer invoice. One row maximum per invoice and per entity.
-- The "llx_" prefix is replaced by the configured prefix by run_sql().
-- ============================================================================

CREATE TABLE llx_invoiceclosure(
	rowid           integer AUTO_INCREMENT PRIMARY KEY,
	entity          integer DEFAULT 1 NOT NULL,
	fk_facture      integer NOT NULL,
	closure_status  smallint DEFAULT 0 NOT NULL,
	date_closure    datetime NULL,
	fk_user_closure integer NULL,
	closure_note    text NULL,
	date_reopen     datetime NULL,
	fk_user_reopen  integer NULL,
	reopen_note     text NULL,
	date_creation   datetime NOT NULL,
	fk_user_creat   integer NOT NULL,
	tms             timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	import_key      varchar(14) NULL
) ENGINE=innodb;

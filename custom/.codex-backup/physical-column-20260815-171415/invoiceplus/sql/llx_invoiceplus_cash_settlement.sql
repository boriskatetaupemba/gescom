-- Atomic/idempotent mixed-currency cash-settlement operation journal.
-- The llx_ prefix is replaced by Dolibarr's configured database prefix.

CREATE TABLE llx_invoiceplus_cash_settlement(
	rowid           integer AUTO_INCREMENT PRIMARY KEY,
	entity          integer DEFAULT 1 NOT NULL,
	operation_id    varchar(64) NOT NULL,
	fk_facture      integer NOT NULL,
	payload_sha256  char(64) NOT NULL,
	status          varchar(16) NOT NULL,
	result_json     mediumtext NULL,
	error_code      varchar(32) NULL,
	error_message   text NULL,
	fk_user         integer NOT NULL,
	date_creation   datetime NOT NULL,
	date_update     datetime NOT NULL,
	tms             timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;

-- Copyright (C) 2026 PosNova module
--
-- POS ticket line.

CREATE TABLE llx_pos_ticket_line
(
	rowid          integer AUTO_INCREMENT PRIMARY KEY,
	fk_ticket      integer      NOT NULL,                      -- llx_pos_ticket.rowid
	fk_product     integer      DEFAULT NULL,                  -- llx_product.rowid
	label          varchar(255) DEFAULT NULL,
	qty            double(24,8)  NOT NULL DEFAULT 1,
	price_unit     double(24,8)  NOT NULL DEFAULT 0,           -- unit price in POS currency
	price_modified tinyint      DEFAULT 0,                     -- 1 if vendor changed catalog price
	discount_pct   double(6,3)  DEFAULT 0,
	tva_tx         double(7,4)  DEFAULT 0,
	total_ht       double(24,8)  DEFAULT 0,
	total_ttc      double(24,8)  DEFAULT 0,
	position       integer      DEFAULT 0
) ENGINE=innodb;

-- Copyright (C) 2026 PosNova module
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
-- Point of Sale terminal. Each POS is bound to one warehouse.

CREATE TABLE llx_pos_config
(
	rowid                    integer AUTO_INCREMENT PRIMARY KEY,
	entity                   integer      NOT NULL DEFAULT 1,
	ref                      varchar(64)  NOT NULL,                    -- terminal code, e.g. POS001
	label                    varchar(255) NOT NULL,
	fk_warehouse             integer      NOT NULL,                    -- llx_entrepot.rowid (immutable after first sale)
	fk_default_account       integer      DEFAULT NULL,                -- llx_bank_account.rowid, defines POS working currency
	default_payment_mode     varchar(16)  DEFAULT 'LIQ',              -- payment mode code (c_paiement)
	max_discount_percent     double(6,3)  DEFAULT 0,                   -- discount cap in %
	allow_price_edit         tinyint      DEFAULT 0,                   -- 0=locked, 1=editable
	allow_sale_without_stock tinyint      DEFAULT 0,                   -- 0=forbidden, 1=allowed
	transfer_enabled         tinyint      DEFAULT 0,                   -- inter-warehouse transfer button
	offline_mode             tinyint      DEFAULT 1,                   -- local buffer if disconnected
	allow_backdating         tinyint      DEFAULT 0,                   -- backdated invoices
	print_format             varchar(8)   DEFAULT '80mm',             -- 80mm | A5 | A4
	autoprint                tinyint      DEFAULT 1,                   -- auto print after validation
	fk_default_customer      integer      DEFAULT NULL,                -- walk-in customer (llx_societe.rowid)
	low_stock_threshold      integer      DEFAULT 5,                   -- visual low-stock badge
	transfer_timeout_hours   integer      DEFAULT 24,                  -- reservation timeout
	cancel_escalation_min    integer      DEFAULT 30,                  -- escalation delay (minutes)
	active                   tinyint      DEFAULT 1,
	datec                    datetime     DEFAULT NULL,
	tms                      timestamp    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat            integer      DEFAULT NULL,
	fk_user_modif            integer      DEFAULT NULL
) ENGINE=innodb;

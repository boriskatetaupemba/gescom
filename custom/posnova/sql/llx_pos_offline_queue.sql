-- Copyright (C) 2026 PosNova module
--
-- Offline ticket queue. Tickets created without connection, numbered server-side at sync.

CREATE TABLE llx_pos_offline_queue
(
	rowid           integer AUTO_INCREMENT PRIMARY KEY,
	entity          integer      NOT NULL DEFAULT 1,
	fk_session      integer      NOT NULL,
	client_uid      varchar(64)  NOT NULL,                    -- idempotency key generated client-side
	ticket_data     mediumtext   NOT NULL,                    -- JSON payload
	created_at      datetime     NOT NULL,
	synced_at       datetime     DEFAULT NULL,
	sync_status     varchar(16)  NOT NULL DEFAULT 'PENDING',  -- PENDING | SYNCED | CONFLICT
	conflict_detail text         DEFAULT NULL,
	fk_ticket       integer      DEFAULT NULL                 -- resulting ticket once synced
) ENGINE=innodb;

-- Copyright (C) 2026 PosNova module
--
-- Ticket cancellation workflow with supervisor approval and admin escalation.

CREATE TABLE llx_pos_cancellation
(
	rowid          integer AUTO_INCREMENT PRIMARY KEY,
	entity         integer      NOT NULL DEFAULT 1,
	fk_ticket      integer      NOT NULL,
	fk_user_req    integer      NOT NULL,                     -- requester
	motif          varchar(64)  DEFAULT NULL,                 -- reason code
	motif_detail   text         DEFAULT NULL,
	fk_user_approver integer    DEFAULT NULL,
	motif_refus    text         DEFAULT NULL,
	status         varchar(16)  NOT NULL DEFAULT 'PENDING',   -- PENDING | APPROVED | REFUSED | ESCALATED
	date_request   datetime     NOT NULL,
	date_decision  datetime     DEFAULT NULL,
	escalated_at   datetime     DEFAULT NULL
) ENGINE=innodb;

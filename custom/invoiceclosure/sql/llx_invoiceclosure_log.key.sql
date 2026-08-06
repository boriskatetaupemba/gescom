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
-- Keys and indexes for llx_invoiceclosure_log.
-- The unique key on (entity, fk_facture, action_code, request_id) guarantees
-- API idempotency: the same request_id cannot be replayed for the same action
-- on the same invoice. MySQL/MariaDB allow several NULL values in a unique
-- index, so rows without request_id (UI actions) are not constrained.
-- ============================================================================

ALTER TABLE llx_invoiceclosure_log ADD UNIQUE INDEX uk_invoiceclosurelog_request (entity, fk_facture, action_code, request_id);

ALTER TABLE llx_invoiceclosure_log ADD INDEX idx_invoiceclosurelog_facture (fk_facture);
ALTER TABLE llx_invoiceclosure_log ADD INDEX idx_invoiceclosurelog_entity (entity);
ALTER TABLE llx_invoiceclosure_log ADD INDEX idx_invoiceclosurelog_action (action_code);
ALTER TABLE llx_invoiceclosure_log ADD INDEX idx_invoiceclosurelog_date (action_date);

ALTER TABLE llx_invoiceclosure_log ADD CONSTRAINT fk_invoiceclosurelog_user_action FOREIGN KEY (fk_user_action) REFERENCES llx_user (rowid);

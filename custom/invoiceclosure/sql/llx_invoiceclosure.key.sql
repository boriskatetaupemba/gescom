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
-- Keys, indexes and constraints for llx_invoiceclosure.
-- ============================================================================

ALTER TABLE llx_invoiceclosure ADD UNIQUE INDEX uk_invoiceclosure_facture (entity, fk_facture);

ALTER TABLE llx_invoiceclosure ADD INDEX idx_invoiceclosure_fk_facture (fk_facture);
ALTER TABLE llx_invoiceclosure ADD INDEX idx_invoiceclosure_entity (entity);
ALTER TABLE llx_invoiceclosure ADD INDEX idx_invoiceclosure_status (closure_status);
ALTER TABLE llx_invoiceclosure ADD INDEX idx_invoiceclosure_date_closure (date_closure);

ALTER TABLE llx_invoiceclosure ADD CONSTRAINT fk_invoiceclosure_facture FOREIGN KEY (fk_facture) REFERENCES llx_facture (rowid);
ALTER TABLE llx_invoiceclosure ADD CONSTRAINT fk_invoiceclosure_user_closure FOREIGN KEY (fk_user_closure) REFERENCES llx_user (rowid);
ALTER TABLE llx_invoiceclosure ADD CONSTRAINT fk_invoiceclosure_user_reopen FOREIGN KEY (fk_user_reopen) REFERENCES llx_user (rowid);
ALTER TABLE llx_invoiceclosure ADD CONSTRAINT fk_invoiceclosure_user_creat FOREIGN KEY (fk_user_creat) REFERENCES llx_user (rowid);

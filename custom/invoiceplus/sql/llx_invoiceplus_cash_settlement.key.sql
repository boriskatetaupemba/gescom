-- One client operation can be executed only once inside an entity.

ALTER TABLE llx_invoiceplus_cash_settlement ADD UNIQUE INDEX uk_invoiceplus_cash_operation (entity, operation_id);
ALTER TABLE llx_invoiceplus_cash_settlement ADD INDEX idx_invoiceplus_cash_invoice (fk_facture);
ALTER TABLE llx_invoiceplus_cash_settlement ADD INDEX idx_invoiceplus_cash_user (fk_user);
ALTER TABLE llx_invoiceplus_cash_settlement ADD INDEX idx_invoiceplus_cash_status (entity, status);

ALTER TABLE llx_invoiceplus_cash_settlement ADD CONSTRAINT fk_invoiceplus_cash_invoice FOREIGN KEY (fk_facture) REFERENCES llx_facture (rowid);
ALTER TABLE llx_invoiceplus_cash_settlement ADD CONSTRAINT fk_invoiceplus_cash_user FOREIGN KEY (fk_user) REFERENCES llx_user (rowid);

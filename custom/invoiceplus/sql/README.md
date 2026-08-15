# Database schema

InvoicePlus 1.3.0 creates `llx_invoiceplus_cash_settlement`. This operation
journal is the concurrency and retry boundary of the mixed CDF/USD cash
settlement endpoint. Its unique `(entity, operation_id)` key guarantees that a
client retry cannot post a second payment. The normalized payload SHA-256
rejects reuse of a key with different amounts, accounts or exchange rate.
Module activation fails if table loading returns zero or if the table and exact
unique `uk_invoiceplus_cash_operation(entity, operation_id)` index cannot be
verified after installation.

The row stores `processing`, `completed` or `failed`, the authenticated user,
invoice, timestamps, the immutable completed JSON result, or a sanitized error.
Invoice payments and linked bank transfers remain in Dolibarr's native tables.
The operation row also serializes the `PAYMENT_CUSTOMER_CREATE` trigger before
it locks the invoice and reads native payment/credit rows with `FOR UPDATE`.

The warehouse filter uses the standard `facture`, `facturedet`, `facture_extrafields`, `entrepot`, and `societe_commerciaux` tables with `MAIN_DB_PREFIX`. The authenticated-sales-representative endpoint reads the standard `societe` and `societe_commerciaux` tables only.

For legacy invoices whose lines have no positive warehouse, the optional compatibility resolution can also read standard `stock_mouvement`, TakePOS `const`, PosNova `pos_ticket`/`pos_config`, and the declared `bank_account_extrafields.warehouse` column. No installation or migration SQL is required.

When the optional `closed` or `paid_not_closed` filter is requested, InvoicePlus also reads InvoiceClosure's indexed `invoiceclosure` table. That table remains created and owned by the InvoiceClosure module.

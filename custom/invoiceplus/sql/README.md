# Database schema

InvoicePlus 1.1.0 creates no database table. The warehouse filter always uses the standard `facture`, `facturedet`, `facture_extrafields`, `entrepot`, and `societe_commerciaux` tables with `MAIN_DB_PREFIX`.

For legacy invoices whose lines have no positive warehouse, the optional compatibility resolution can also read standard `stock_mouvement`, TakePOS `const`, PosNova `pos_ticket`/`pos_config`, and the declared `bank_account_extrafields.warehouse` column. No installation or migration SQL is required.

When the optional `closed` or `paid_not_closed` filter is requested, InvoicePlus also reads InvoiceClosure's indexed `invoiceclosure` table. That table remains created and owned by the InvoiceClosure module.

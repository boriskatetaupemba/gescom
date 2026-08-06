# Database schema

InvoicePlus 1.0.1 creates no database table. The warehouse filter always uses the standard `facture`, `facturedet`, `facture_extrafields`, `entrepot`, and `societe_commerciaux` tables with `MAIN_DB_PREFIX`.

For legacy invoices whose lines have no positive warehouse, the optional compatibility resolution can also read standard `stock_mouvement`, TakePOS `const`, PosNova `pos_ticket`/`pos_config`, and the declared `bank_account_extrafields.warehouse` column. No installation or migration SQL is required.

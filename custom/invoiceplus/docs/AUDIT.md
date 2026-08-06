# Installed-version audit

## Environment

| Item | Evidence | Result |
|---|---|---|
| Dolibarr | `filefunc.inc.php` | 20.0.4 |
| PHP | `C:\xampp\php\php.exe -v` | 8.2.12 ZTS x64 |
| Database driver | `conf/conf.php` | `mysqli` |
| Local database distribution | `C:\xampp\mysql\bin\mysql.exe --version` | MariaDB 10.4.32 x64 |
| Standard table engine | `install/mysql/tables/llx_facturedet.sql` | InnoDB |
| Warehouse invoice-line field | same schema and `Facture::fetch_lines()` | nullable integer `fk_warehouse`, loaded into every `FactureLigne` |

The local MariaDB service rejected the configured account from this checkout, so its authenticated runtime version and information-schema foreign keys must still be confirmed after deployment. This module uses only Dolibarr's `DoliDB` abstraction and `MAIN_DB_PREFIX`.

## Core files inspected, not modified

- `filefunc.inc.php`
- `api/index.php`
- `api/class/api.class.php`
- `api/class/api_access.class.php`
- `core/lib/functions2.lib.php`
- `compta/facture/class/api_invoices.class.php`
- `compta/facture/class/facture.class.php`
- `product/stock/class/api_warehouses.class.php`
- `product/stock/class/entrepot.class.php`
- `install/mysql/tables/llx_facturedet.sql`

## Native `Invoices` behavior

`index($sortfield='t.rowid', $sortorder='ASC', $limit=100, $page=0, $thirdparty_ids='', $status='', $sqlfilters='', $properties='')` accepts eight parameters. It checks `facture.lire`, forces an external user's `socid`, restricts internal sales users through `societe_commerciaux`, filters `t.entity IN (getEntity('invoice'))`, supports native status strings `draft`, `unpaid`, `paid`, `cancelled`, validates Universal Search through `forgeSQLFromUniversalSearchCriteria()`, loads each invoice and computes its four payment-related values.

`get()` calls private `_fetch()`. `_fetch()` checks `facture.lire`, fetches the `Facture`, computes payments, calls `_checkAccessToResource('facture', id)`, loads external contacts, linked objects and the online-payment URL, then calls protected `_cleanObjectDatas()`.

The base `_cleanObjectDatas()` removes database handles, internal implementation state, sensitive fields, heavy linked objects, and equivalent line internals recursively. The invoice override removes additional invoice-only fields. `_filterObjectProperties()` retains exactly the requested comma-separated real or magic properties.

`Facture::fetch()` loads standard fields, extrafields and lines. `fetch_lines()` explicitly selects and assigns `facturedet.fk_warehouse`. Amount/date/multicurrency types therefore remain those of the installed native API.

## InvoiceClosure audit

Inspected custom files:

- `custom/invoiceclosure/core/modules/modInvoiceClosure.class.php`
- `custom/invoiceclosure/class/invoiceclosure.class.php`
- `custom/invoiceclosure/class/api_invoiceclosure.class.php`
- `custom/invoiceclosure/class/actions_invoiceclosure.class.php`
- `custom/invoiceclosure/core/triggers/interface_99_modInvoiceClosure_InvoiceClosureTriggers.class.php`

The installed native `api_invoices.class.php` already enriches cleaned customer invoices through its private `_getInvoiceClosureData()`. It checks module activation and `invoiceclosure.read`, uses the public `InvoiceClosure::fetchByInvoice()` method, and adds `invoiceclosure` with:

- `business_status`
- `business_status_code`
- `business_status_label`
- `locked`
- `closed_at`, `closed_at_iso`, `closed_by`, `closure_note`
- `reopened_at`, `reopened_at_iso`, `reopened_by`, `reopen_note`

InvoicePlus does not reproduce these fields. Its native `Invoices::get()` call is the single source of truth. Closure-status filtering uses public `InvoiceClosure::getClosureStatus()` and never reads an InvoiceClosure table.

## Warehouse security and SQL

The installed warehouse API requires `stock.lire`, fetches `Entrepot`, and applies `_checkAccessToResource('stock', id, 'entrepot')`. InvoicePlus repeats those checks and explicitly verifies the warehouse's entity against `getEntity('stock')`.

The invoice query uses:

```sql
WHERE t.entity IN (getEntity('invoice'))
AND EXISTS (
    SELECT 1
    FROM MAIN_DB_PREFIX.facturedet fd
    WHERE fd.fk_facture = t.rowid
      AND fd.fk_warehouse = <validated integer>
)
```

The literal SQL is assembled with `MAIN_DB_PREFIX`; the conceptual placeholder above is documentation only. Pagination is applied to invoice ids, and the separate count query counts invoice rows rather than invoice lines.

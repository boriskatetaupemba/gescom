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

## Authenticated sales-representative third parties

InvoicePlus 1.2.0 adds `GET /invoiceplus/thirdparties`. The caller cannot
provide a user id: the predicate always uses the internal user authenticated by
`DolibarrApiAccess`. Selection occurs before pagination and is constrained by
the current Dolibarr entity context:

```sql
WHERE t.entity IN (getEntity('societe'))
AND EXISTS (
    SELECT 1
    FROM MAIN_DB_PREFIX.societe_commerciaux sc
    WHERE sc.fk_soc = t.rowid
      AND sc.fk_user = <authenticated user id>
)
```

`EXISTS` is intentional: the standard association table may contain more than
one relationship type for a third-party/user pair, while the endpoint must
return each third party once. The service creates a fresh `Societe` for every
selected id and passes it through Dolibarr's native `_cleanObjectDatas()` and
`_filterObjectProperties()` helpers, matching the core third-party list shape
without changing core files. Empty selections return HTTP 200 with `[]`.

## InvoiceClosure audit and extraction

Inspected custom files:

- `custom/invoiceclosure/core/modules/modInvoiceClosure.class.php`
- `custom/invoiceclosure/class/invoiceclosure.class.php`
- `custom/invoiceclosure/class/api_invoiceclosure.class.php`
- `custom/invoiceclosure/class/actions_invoiceclosure.class.php`
- `custom/invoiceclosure/core/triggers/interface_99_modInvoiceClosure_InvoiceClosureTriggers.class.php`

The previous Gescom fork enriched cleaned customer invoices through a private `_getInvoiceClosureData()` method added to the native `api_invoices.class.php`. That core customization has been removed. InvoicePlus 1.1.0 now checks module activation and `invoiceclosure.read`, uses the public `InvoiceClosure::fetchByInvoice()` method, and adds `invoiceclosure` with:

- `business_status`
- `business_status_code`
- `business_status_label`
- `locked`
- `closed_at`, `closed_at_iso`, `closed_by`, `closure_note`
- `reopened_at`, `reopened_at_iso`, `reopened_by`, `reopen_note`

Dolibarr remains the source of the invoice payload; InvoicePlus owns only this nested extension block. To keep filtering bounded, closure-status filters use the indexed, module-owned `invoiceclosure` table before count and pagination; response enrichment still uses public `InvoiceClosure::fetchByInvoice()`. The official `compta/facture/class/api_invoices.class.php` now matches tag 20.0.4.

## Warehouse security and SQL

The installed warehouse API requires `stock.lire`, fetches `Entrepot`, and applies `_checkAccessToResource('stock', id, 'entrepot')`. InvoicePlus repeats those checks and explicitly verifies the warehouse's entity against `getEntity('stock')`.

The invoice query first uses the authoritative line assignment:

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

The supplied native API sample shows that invoices 214 through 223 have `fk_warehouse = 0` on every line. A strict `facturedet.fk_warehouse = <warehouse>` predicate therefore cannot return them, regardless of the requested warehouse id. The sample also shows `fk_account = 9` and no usable `module_source`/`pos_source` value.

InvoicePlus 1.1.0 keeps a positive line warehouse authoritative. Only when no line has a positive warehouse does it try these existing relations, in order-independent `EXISTS` predicates:

1. native `stock_mouvement` rows whose origin is the invoice;
2. `pos_ticket -> pos_config.fk_warehouse` when PosNova is enabled;
3. TakePOS `CASHDESK_ID_WAREHOUSE{terminal}` configuration when TakePOS source data exists;
4. the existing `bank_account_extrafields.warehouse` mapping for `facture.fk_account`, when that extrafield is declared.

This is a read-only compatibility path. It does not manufacture a warehouse and does not alter old invoice lines. If none of these sources contains the requested warehouse, the invoice is correctly excluded. The path can be disabled with `INVOICEPLUS_ENABLE_WAREHOUSE_FALLBACKS=0`.

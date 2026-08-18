# InvoicePlus 1.3.0

InvoicePlus is an extensible Dolibarr module for customer-invoice services. It now owns every invoice API customization that previously lived in Dolibarr core:

```text
GET /api/index.php/invoiceplus
GET /api/index.php/invoiceplus/{invoice_id}
GET /api/index.php/invoiceplus/ref/{ref}
GET /api/index.php/invoiceplus/ref_ext/{ref_ext}
GET /api/index.php/invoiceplus/byaccounts?account_ids=1,2,3
GET /api/index.php/invoiceplus/warehouse/{warehouse_id}
GET /api/index.php/invoiceplus/thirdparties
POST /api/index.php/invoiceplus/invoices/{invoice_id}/cash-settlement
GET /api/index.php/invoiceplus/cash-settlements/{operation_id}
```

The module delegates standard invoice construction and access checks to Dolibarr's native `Invoices` API, adds optional InvoiceClosure information itself, and changes no Dolibarr core file. Version 1.3.0 adds one module-owned operation journal used to make cash-settlement retries atomic and idempotent. Native `/invoices` routes remain available with their stock 20.0.4 behavior.

## Verified target environment

- Dolibarr program version: 20.0.4 (`filefunc.inc.php`).
- PHP CLI available for verification: 8.2.12, 64-bit ZTS.
- Configured database driver: `mysqli` (`conf/conf.php`).
- Local database distribution: MariaDB 10.4.32 (`C:\xampp\mysql\bin\mysql.exe --version`).
- Standard installation schema engine: InnoDB; `install/mysql/tables/llx_facturedet.sql` contains nullable integer `fk_warehouse`.
- The standard relationship is `facturedet.fk_facture -> facture.rowid`; warehouse ids reference `entrepot.rowid`. The delivered schema does not declare a physical foreign key for `facturedet.fk_warehouse`.
- A live authenticated server query could not be completed from this checkout because its configured database account is not accepted by the local MariaDB service. Runtime API tests must therefore be run in the deployed Dolibarr environment.

The native Dolibarr 20.0.4 `Invoices::index()` parameters are exactly `sortfield`, `sortorder`, `limit`, `page`, `thirdparty_ids`, `status`, `sqlfilters`, and `properties`. It enforces invoice read permission, entity visibility, the external user's third party, and customer-sales-representative restrictions. It loads each `Facture`, computes `totalpaid`, `totalcreditnotes`, `totaldeposits`, and `remaintopay`, adds external contact ids and the online payment URL, then applies native cleanup and property filtering.

InvoicePlus calls the public native API and then builds the optional `invoiceclosure` property through the public InvoiceClosure business class. The native `compta/facture/class/api_invoices.class.php` is left byte-for-byte identical to Dolibarr 20.0.4.

The complete audit is in [docs/AUDIT.md](docs/AUDIT.md).

## Installation

1. Back up the Dolibarr database and custom modules directory.
2. Extract `invoiceplus.zip` so the resulting path is `htdocs/custom/invoiceplus/`.
3. Check that the web-server user can read the extracted files.
4. In **Home > Setup > Modules/Applications**, enable REST API, Customer Invoices, Stocks, Banks/Cash and Multi-currency, then Invoice Plus. Re-enable Invoice Plus after upgrading so its 1.3.0 table is installed.
5. Open Invoice Plus setup and review the five constants.
6. If `API_PRODUCTION_MODE` is enabled, clear Dolibarr's REST/Restler cache or disable and re-enable the REST API module so the explorer is regenerated.

The archive contains a top-level `invoiceplus/` directory and is directly suitable for extraction under `htdocs/custom/`.

## Configuration

| Constant | Default | Behavior |
|---|---:|---|
| `INVOICEPLUS_API_ENABLED` | `1` | Enables the endpoint. Disabled calls return HTTP 403. |
| `INVOICEPLUS_MAX_API_LIMIT` | `1000` | Caps page size; `limit<=0` uses this cap. |
| `INVOICEPLUS_ADD_WAREHOUSE_METADATA` | `1` | Adds non-persistent `invoiceplus_warehouse_filter`. |
| `INVOICEPLUS_LOAD_CLOSURE_DATA` | `1` | Adds the module-owned `invoiceclosure` property to InvoicePlus responses. `0` omits it. |
| `INVOICEPLUS_ENABLE_WAREHOUSE_FALLBACKS` | `1` | For invoices with no assigned line warehouse, resolves legacy records from native stock movements, PosNova ticket configuration, TakePOS terminal configuration, or the bank-account `warehouse` extrafield. |

## Routes moved out of core

| Old core-customized route | Module route | Notes |
|---|---|---|
| `GET /invoices` | `GET /invoiceplus` | Native-compatible list plus optional `invoiceclosure`. |
| `GET /invoices/{id}` | `GET /invoiceplus/{id}` | Native-compatible object plus optional `invoiceclosure`. |
| `GET /invoices/ref/{ref}` | `GET /invoiceplus/ref/{ref}` | Lookup by reference. |
| `GET /invoices/ref_ext/{ref_ext}` | `GET /invoiceplus/ref_ext/{ref_ext}` | Lookup by external reference. |
| `GET /invoices/byaccounts` | `GET /invoiceplus/byaccounts` | Requires `account_ids=1,2,3`. |

## Third parties of the authenticated sales representative

`GET /invoiceplus/thirdparties` returns every Dolibarr third party assigned to
the user authenticated by the REST API key. The route never accepts a user id:
even users allowed to view all customers receive only their own assignments
from `societe_commerciaux`.

The response is an array of native-compatible third-party list objects. It is
empty (`[]`, HTTP 200) when no assignment matches. All third-party types are
included (customers, prospects and suppliers); active records are selected by
default.

| Parameter | Default | Notes |
|---|---:|---|
| `sortfield` | `t.nom` | Whitelist: id, name, alias, customer code, town, creation/modification date and status. |
| `sortorder` | `ASC` | `ASC` or `DESC`. |
| `limit` | `100` | Capped by `INVOICEPLUS_MAX_API_LIMIT`; zero or negative uses the cap. |
| `page` | `0` | Zero-based and non-negative. |
| `status` | `1` | `1` active, `0` closed, `-1` all. |
| `properties` | empty | Native comma-separated property filtering. |

This route requires an internal user with `societe.lire`; invoice-read access
is not required because no invoice data is returned. It applies
`getEntity('societe')`, so the `DOLAPIENTITY` header and configured entity
sharing are respected. External users receive HTTP 403.

The old custom paths cannot be retained by an external module because Dolibarr routes `/invoices` to its core API class. Clients must switch to the module paths above. Standard invoice writes remain on native `/invoices`; the atomic POS cash settlement is module-owned, and InvoiceClosure state remains available separately from `/invoiceclosureapi`.

## Atomic CDF/USD cash settlement

`POST /invoiceplus/invoices/{id}/cash-settlement` is the POS write boundary. It
accepts CDF and/or USD received amounts and change in either currency. The
client sends the invoice's frozen CDF-per-USD rate and current CDF remainder;
the server compares both with the locked invoice, works in integer cents and
rejects a stale or non-reconcilable request before creating any payment.

```json
{
  "operation_id": "cash-20260812-0001",
  "date": 1786492800,
  "payment_method_id": 4,
  "exchange_rate": "2850",
  "total_cdf": "285000.00",
  "received": { "cdf": "142500.00", "usd": "50.00" },
  "change": { "cdf": "0.00", "usd": "0.00" },
  "accounts": { "cdf": 8, "usd": 9 }
}
```

The authenticated user must be internal and have `facture.lire`,
`facture.creer`, `facture.paiement`, `banque.lire` and `banque.modifier`. A settlement that needs
cross-currency change additionally requires `banque.transfer`. The server ignores any browser warehouse
context and exclusively uses `user.fk_warehouse`. Every used account must be
an open Dolibarr cash account, belong to the visible bank-account entity, have the exact USD/CDF
currency, and be linked to that warehouse through
`bank_account_extrafields.warehouse`. Account ledger rows are locked and the
physical current balance (future-dated entries excluded) plus cash received must cover requested change.

The invoice customer must be explicitly assigned to the authenticated sales
representative in `societe_commerciaux`, even when that user has the global
customer-view right. Every product/service line must explicitly carry that same
warehouse; legacy or mixed-warehouse invoices are refused by this POS endpoint.

The endpoint supports a company base currency of USD and a CDF invoice. It
accepts only an active incoming `LIQ` payment method. Native `Paiement` records,
payment/invoice links and bank lines are created for the exact tender
allocation. When change crosses currencies, the service creates the two native
linked bank-transfer lines required to preserve each cash drawer's physical
delta. The invoice is marked paid only after both its USD and CDF remainders are
exactly zero.

Automatic invoice-PDF regeneration is disabled during the database transaction,
then run once after commit. A document-generation failure is logged without
rolling back or replaying a successful financial settlement.

`operation_id` is mandatory and unique per Dolibarr entity. Replaying the exact
normalized payload returns the saved result without a second payment. Reusing
the key with different amounts, accounts, rate, date or invoice returns HTTP
409. After a lost response, call
`GET /invoiceplus/cash-settlements/{operation_id}`; only the creating user can
retrieve the status. A completed GET returns the same settlement result at the
response root with `idempotent_replay=true`.

Module activation is fail-closed: InvoicePlus verifies both the settlement
table and the exact unique `uk_invoiceplus_cash_operation(entity,
operation_id)` key after loading SQL. Its `PAYMENT_CUSTOMER_CREATE` trigger also
serializes native Dolibarr payments against processing/completed InvoicePlus
operations. It uses locking reads and integer cents in both currencies; a
native payment calculated from a stale balance is rolled back before it can
overpay the protected invoice.

All InvoicePlus list routes cap `limit` with `INVOICEPLUS_MAX_API_LIMIT`; a zero or negative value uses that configured maximum instead of producing an unbounded response.

## Warehouse endpoint parameters

| Parameter | Default | Notes |
|---|---|---|
| `sortfield` | `t.rowid` | Whitelist includes invoice ids, refs, dates, statuses, totals, third party, and multicurrency totals. |
| `sortorder` | `DESC` | `ASC` or `DESC`. |
| `limit` | `100` | Capped by module configuration. |
| `page` | `0` | Zero-based; negative values become zero. |
| `thirdparty_ids` | empty | Comma-separated integers; ignored and replaced for external users. |
| `status` | empty | Native values: `draft`, `unpaid`, `paid`, `cancelled`; closure values: `closed`, `paid_not_closed`. Other values keep the native 20.0.4 behavior and do not add a status condition. |
| `sqlfilters` | empty | Native Universal Search validation; only aliases `t` and `ef` are accepted. The warehouse condition cannot be overridden. |
| `properties` | empty | Native property-filter behavior, including filtering `invoiceclosure` and InvoicePlus metadata. |
| `pagination_data` | `false` | Accepts `true`, `false`, `1`, `0`. |
| `loadlinkedobjects` | `false` | `false` reproduces the list endpoint's unloaded `linkedObjectsIds`; `true` keeps the single-invoice native linked ids. |
| `date_start`, `date_end` | empty | Strict `YYYY-MM-DD`, applied to invoice date `datef`, inclusive. |
| `withLines` | `true` | Removes `lines` only when false. |
| `warehouse_lines_only` | `false` | Filters response lines by their actual `fk_warehouse`; official invoice and payment totals remain unchanged. A legacy invoice selected by a fallback can therefore have an empty `lines` array when all its native lines still contain `0`. |

`pagination_data=false` returns a JSON array. Dolibarr 20.0.4 has no native invoice pagination envelope, so `pagination_data=true` uses the documented fallback:

```json
{
  "data": [],
  "pagination": {
    "total": 0,
    "page": 0,
    "page_count": 0,
    "limit": 100
  }
}
```

## Security model

All routes require authenticated REST access. Invoice routes require `facture.lire`; the warehouse route additionally requires `stock.lire` and applies the native stock resource check. Cash settlement additionally requires `facture.creer`, `banque.lire`, an internal user, trusted server-side warehouse/account mappings and native per-invoice access. The assigned-third-party route instead requires `societe.lire` and an internal user. Invoice and third-party selection use their native entity scopes, so `DOLAPIENTITY` follows Dolibarr's entity context.

On invoice routes, external users are forced to their own `socid`; internal users without the global customer-view permission are restricted through `societe_commerciaux`, matching the installed native invoice list. Every selected invoice passes through `Invoices::get()`, which applies `_checkAccessToResource('facture', id)` before returning data. The assigned-third-party route rejects external users and always applies the exact authenticated internal user id, regardless of global customer-view permission.

An explicit positive `facturedet.fk_warehouse` is authoritative. Fallbacks are considered only when every invoice line is unassigned (`NULL` or `0`), so a POS/account mapping cannot override a real line warehouse. Invoice-level `EXISTS` predicates guarantee one occurrence per invoice even when several matching records exist. User values never select the warehouse SQL expression or the sort field.

## InvoiceClosure integration

When InvoiceClosure is disabled, InvoicePlus adds no closure property and continues normally. When enabled and authorized, `InvoicePlusInvoiceService` calls `InvoiceClosure::fetchByInvoice()` and builds the same documented `invoiceclosure` object that formerly came from the patched core API. Native `/invoices` responses are no longer modified.

On `GET /invoiceplus/warehouse/{warehouse_id}` only, the extra `closed` and `paid_not_closed` filters first restrict invoices to Dolibarr's paid status, then apply an indexed predicate on InvoiceClosure's module-owned status table before counting and paging. They require the InvoiceClosure read permission. The root and `/byaccounts` lists retain the native status set. See [docs/CLOSURE.md](docs/CLOSURE.md).

## REST explorer

After activation, visit:

```text
https://YOUR-DOLIBARR/api/index.php/explorer/
```

Find the `invoiceplus` API and verify the root list, `GET /byaccounts`, `GET /warehouse/{warehouse_id}`, and `GET /thirdparties`. If they are absent while production mode is active, clear the API cache or re-enable the API module. No core routing edit is required: Dolibarr 20.0.4 maps `invoiceplus` to `custom/invoiceplus/class/api_invoiceplus.class.php` and class `Invoiceplus`.

## Tests

- Unit tests: run `phpunit test/unit/InvoicePlusInvoiceServiceTest.php`, `phpunit test/unit/InvoicePlusCashSettlementServiceTest.php`, and `phpunit test/unit/InvoicePlusTriggersTest.php` from the module directory with a PHPUnit release compatible with the installed PHP version. The legacy PEAR PHPUnit bundled with some XAMPP releases is not supported on PHP 8.
- PowerShell API suite: `test/api/test_invoiceplus_api.ps1`.
- POSIX API suite: `test/api/test_invoiceplus_api.sh` (requires `curl` and `jq`).
- Manual acceptance matrix: [test/MANUAL_TESTS.md](test/MANUAL_TESTS.md).

Pass `InvoiceId`/`INVOICE_ID` for a record belonging to the tested warehouse. The scripts compare the canonical native `/invoices/{id}` payload with InvoicePlus using `loadlinkedobjects=true`, excluding the module-owned `invoiceclosure` and `invoiceplus_warehouse_filter` fields from the structural comparison.
Pass `AccountIds`/`ACCOUNT_IDS` as a comma-separated list to exercise the `/byaccounts` route as well.

## Performance and limitations

- Native response construction performs one native invoice fetch per selected id. This intentionally follows Dolibarr's own list implementation and prevents format/security drift.
- Warehouse fallbacks add indexed existence checks for legacy invoices. Set `INVOICEPLUS_ENABLE_WAREHOUSE_FALLBACKS=0` to restore strict line-only selection.
- Fallback resolution and closure enrichment do not rewrite historical data or alter the native `/invoices` response. PosNova 1.0.1 writes the warehouse on newly created invoice lines and records the invoice as stock-movement origin.
- On the warehouse endpoint, `closed` and `paid_not_closed` use InvoiceClosure's indexed status table in the selection query, so filtering, counting and pagination stay bounded by the database query rather than an application-side scan.
- `pagination_data`, date filtering, `withLines`, `warehouse_lines_only`, and the maximum page cap are InvoicePlus extensions because the installed 20.0.4 native `Invoices::index()` does not expose them.
- Live HTTP and database integration tests require the deployed Dolibarr database and API authentication; they cannot be truthfully completed against a source-only checkout.

## Adding future endpoints

Create the new public Restler method in `class/api_invoiceplus.class.php`, keep it limited to parameter/access handling, and put reusable invoice logic in a dedicated service class under `class/`. Reuse `InvoicePlusInvoiceService::buildInvoiceApiResponse()` whenever the result must remain native-compatible. Add the method's `@url` annotation, translations/configuration only when needed, and cover it with unit and API tests. The module name, descriptor, base API class, setup page, and route discovery need no restructuring.

Examples are collected in [docs/CURL_EXAMPLES.md](docs/CURL_EXAMPLES.md).

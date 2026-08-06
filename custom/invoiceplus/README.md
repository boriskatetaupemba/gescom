# InvoicePlus 1.0.0

InvoicePlus is an extensible Dolibarr module for customer-invoice services. Its first endpoint lists the invoices having at least one standard invoice line assigned to a requested warehouse:

```text
GET /api/index.php/invoiceplus/warehouse/{warehouse_id}
```

The module does not replace `Invoices`, does not publish a conflicting `/invoices/{warehouse_id}` route, creates no table, and changes no Dolibarr core file.

## Verified target environment

- Dolibarr program version: 20.0.4 (`filefunc.inc.php`).
- PHP CLI available for verification: 8.2.12, 64-bit ZTS.
- Configured database driver: `mysqli` (`conf/conf.php`).
- Local database distribution: MariaDB 10.4.32 (`C:\xampp\mysql\bin\mysql.exe --version`).
- Standard installation schema engine: InnoDB; `install/mysql/tables/llx_facturedet.sql` contains nullable integer `fk_warehouse`.
- The standard relationship is `facturedet.fk_facture -> facture.rowid`; warehouse ids reference `entrepot.rowid`. The delivered schema does not declare a physical foreign key for `facturedet.fk_warehouse`.
- A live authenticated server query could not be completed from this checkout because its configured database account is not accepted by the local MariaDB service. Runtime API tests must therefore be run in the deployed Dolibarr environment.

The native Dolibarr 20.0.4 `Invoices::index()` parameters are exactly `sortfield`, `sortorder`, `limit`, `page`, `thirdparty_ids`, `status`, `sqlfilters`, and `properties`. It enforces invoice read permission, entity visibility, the external user's third party, and customer-sales-representative restrictions. It loads each `Facture`, computes `totalpaid`, `totalcreditnotes`, `totaldeposits`, and `remaintopay`, adds external contact ids and the online payment URL, then applies native cleanup and property filtering.

This installation also contains a native invoice API enrichment for InvoiceClosure. InvoicePlus calls the public `Invoices::get()` method, so its `invoiceclosure` property, types, labels, dates, permissions, and absence rules come directly from that native implementation.

The complete audit is in [docs/AUDIT.md](docs/AUDIT.md).

## Installation

1. Back up the Dolibarr database and custom modules directory.
2. Extract `invoiceplus.zip` so the resulting path is `htdocs/custom/invoiceplus/`.
3. Check that the web-server user can read the extracted files.
4. In **Home > Setup > Modules/Applications**, enable REST API, Customer Invoices, Stocks, then Invoice Plus.
5. Open Invoice Plus setup and review the four constants.
6. If `API_PRODUCTION_MODE` is enabled, clear Dolibarr's REST/Restler cache or disable and re-enable the REST API module so the explorer is regenerated.

The archive contains a top-level `invoiceplus/` directory and is directly suitable for extraction under `htdocs/custom/`.

## Configuration

| Constant | Default | Behavior |
|---|---:|---|
| `INVOICEPLUS_API_ENABLED` | `1` | Enables the endpoint. Disabled calls return HTTP 403. |
| `INVOICEPLUS_MAX_API_LIMIT` | `1000` | Caps page size; `limit<=0` uses this cap. |
| `INVOICEPLUS_ADD_WAREHOUSE_METADATA` | `1` | Adds non-persistent `invoiceplus_warehouse_filter`. |
| `INVOICEPLUS_LOAD_CLOSURE_DATA` | `1` | Keeps the exact native `invoiceclosure` property. `0` removes it from InvoicePlus responses. |

## Endpoint parameters

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
| `warehouse_lines_only` | `false` | Filters response lines by `fk_warehouse`; official invoice and payment totals remain unchanged. |

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

The route requires authenticated REST access plus both `facture.lire` and `stock.lire`. Warehouse loading then uses the native stock resource check. Invoice selection uses `getEntity('stock')` and `getEntity('invoice')`, so `DOLAPIENTITY` follows Dolibarr's entity context.

External users are forced to their own `socid`. Internal users without the global customer-view permission are restricted through `societe_commerciaux`, matching the installed native invoice list. Finally, every selected invoice passes through `Invoices::get()`, which applies `_checkAccessToResource('facture', id)` before returning data.

The `EXISTS` warehouse predicate guarantees one occurrence per invoice even when several matching lines exist. User values never select the warehouse SQL expression or the sort field.

## InvoiceClosure integration

When InvoiceClosure is disabled, the native API adds no closure property and InvoicePlus continues normally. When enabled and authorized, InvoicePlus preserves the exact native `invoiceclosure` object. It does not query InvoiceClosure tables.

The extra `closed` and `paid_not_closed` filters first restrict invoices to Dolibarr's paid status and then call the public `InvoiceClosure::getClosureStatus()` interface. They require the InvoiceClosure read permission. See [docs/CLOSURE.md](docs/CLOSURE.md).

## REST explorer

After activation, visit:

```text
https://YOUR-DOLIBARR/api/index.php/explorer/
```

Find the `invoiceplus` API and verify `GET /warehouse/{warehouse_id}`. If it is absent while production mode is active, clear the API cache or re-enable the API module. No core routing edit is required: Dolibarr 20.0.4 maps `invoiceplus` to `custom/invoiceplus/class/api_invoiceplus.class.php` and class `Invoiceplus`.

## Tests

- Unit tests: `phpunit test/unit/InvoicePlusInvoiceServiceTest.php` from the module directory.
- PowerShell API suite: `test/api/test_invoiceplus_api.ps1`.
- POSIX API suite: `test/api/test_invoiceplus_api.sh` (requires `curl` and `jq`).
- Manual acceptance matrix: [test/MANUAL_TESTS.md](test/MANUAL_TESTS.md).

Pass `InvoiceId`/`INVOICE_ID` for a record belonging to the tested warehouse. The scripts then compare the canonical native `/invoices/{id}` payload with InvoicePlus using `loadlinkedobjects=true`, allowing only removal of `invoiceplus_warehouse_filter` before comparison.

## Performance and limitations

- Native response construction performs one native invoice fetch per selected id. This intentionally follows Dolibarr's own list implementation and prevents format/security drift.
- `closed` and `paid_not_closed` scan eligible paid ids through the public InvoiceClosure method before pagination. This is slower on very large paid-invoice sets but preserves module encapsulation and exact pagination counts. A future public batch method in InvoiceClosure can replace this without changing the endpoint.
- `pagination_data`, date filtering, `withLines`, `warehouse_lines_only`, and the maximum page cap are InvoicePlus extensions because the installed 20.0.4 native `Invoices::index()` does not expose them.
- Live HTTP and database integration tests require the deployed Dolibarr database and API authentication; they cannot be truthfully completed against a source-only checkout.

## Adding future endpoints

Create the new public Restler method in `class/api_invoiceplus.class.php`, keep it limited to parameter/access handling, and put reusable invoice logic in a dedicated service class under `class/`. Reuse `InvoicePlusInvoiceService::buildInvoiceApiResponse()` whenever the result must remain native-compatible. Add the method's `@url` annotation, translations/configuration only when needed, and cover it with unit and API tests. The module name, descriptor, base API class, setup page, and route discovery need no restructuring.

Examples are collected in [docs/CURL_EXAMPLES.md](docs/CURL_EXAMPLES.md).

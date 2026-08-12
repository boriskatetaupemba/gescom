# Changelog

## 1.2.0 - 2026-08-12

- Add `GET /invoiceplus/thirdparties`, scoped exclusively to the sales
  assignments of the authenticated internal API user.
- Return native-compatible third-party list objects with entity filtering,
  stable pagination, whitelisted sorting, status and property filters.
- Reject external users and require the native third-party read right.

## 1.1.0 - 2026-08-11

- Move the former core route `GET /invoices/byaccounts` to
  `GET /invoiceplus/byaccounts`.
- Add core-free InvoicePlus wrappers for the invoice list, id, reference and
  external-reference routes.
- Build the optional `invoiceclosure` block inside InvoicePlus instead of
  modifying Dolibarr's native `Invoices` API class.
- Cap every list route, validate qualified SQL-filter fields and apply closure
  status predicates before pagination.
- Keep the native Dolibarr 20.0.4 invoice files untouched and upgrade-safe.

## 1.0.1 - 2026-08-06

- Kept positive invoice-line warehouse assignments authoritative.
- Added read-only resolution for legacy invoices whose lines contain only `fk_warehouse = 0` or `NULL`.
- Added standard stock-movement, PosNova, TakePOS, and declared bank-account warehouse sources.
- Added `INVOICEPLUS_ENABLE_WAREHOUSE_FALLBACKS` to switch compatibility resolution off when strict line-only matching is required.
- Documented that native invoice payloads and historical line values are never rewritten.

## 1.0.0 - 2026-08-06

- Added installable InvoicePlus module descriptor and administration pages.
- Added `GET /api/index.php/invoiceplus/warehouse/{warehouse_id}`.
- Added warehouse, invoice, third-party, sales-representative, entity, and per-resource access controls.
- Added invoice-level `EXISTS` selection, whitelisted sorting, dates, native status and Universal Search filters.
- Added optional line suppression and warehouse-only response-line filtering without total recalculation.
- Added native `Invoices::get()` response construction and exact InvoiceClosure preservation.
- Added closure business-status filters through the public InvoiceClosure interface.
- Added configuration, English/French translations, unit/API tests, audit, cURL examples, and installation documentation.

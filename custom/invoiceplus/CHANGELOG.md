# Changelog

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

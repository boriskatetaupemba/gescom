# InvoicePlus manual acceptance checklist

Run these checks on a disposable Dolibarr 20.0.4 test entity with API, Customer Invoices, Stock, and InvoicePlus enabled.

1. Confirm `/api/index.php/explorer/` lists `invoiceplus`, the root list, `GET /byaccounts`, and `GET /warehouse/{warehouse_id}`.
2. Test a warehouse with no invoices: HTTP 200 and `[]`.
3. Test valid, zero, negative, non-numeric, and unknown warehouse ids.
4. Compare an invoice returned by InvoicePlus with `GET /invoices/{id}` using `loadlinkedobjects=true`; exclude the module-owned `invoiceclosure` and `invoiceplus_warehouse_filter` fields before comparing the native payload.
5. Create two invoice lines in one warehouse and confirm the invoice occurs once.
6. Put invoice lines in two warehouses and confirm both warehouse filters select the same invoice.
7. Verify `warehouse_lines_only=true` changes only `lines`, never totals or payment values.
8. Verify `withLines=false`, `properties`, sorting, date bounds, status, third-party filtering, page boundaries, and the configured maximum limit.
9. Test an external user, an internal user limited to assigned customers, a user without invoice-read permission, and a user without stock-read permission.
10. Switch entity with `DOLAPIENTITY`; verify cross-entity warehouses and invoices are inaccessible.
11. Disable InvoiceClosure: the `invoiceclosure` property disappears, normal queries still work, and `status=closed` returns HTTP 400.
12. Enable InvoiceClosure with read permission: compare closed, reopened, and never-closed InvoicePlus payloads with `GET /invoiceclosureapi/invoices/{id}` field by field. Compare semantic values rather than raw JSON types: the canonical API may expose `locked` as a boolean while the compatibility block uses `0`/`1`, and translated labels depend on the active language.
13. Set `INVOICEPLUS_LOAD_CLOSURE_DATA=0`; confirm InvoicePlus omits the closure property while `/invoiceclosureapi` remains available.
14. Disable and re-enable InvoicePlus, clear the REST production cache if enabled, and repeat explorer and endpoint checks.
15. Run the native `/invoices` and `/invoices/{id}` endpoints after installation to confirm there is no regression.
16. Run `git diff -- compta/facture/class/api_invoices.class.php api/index.php` and confirm InvoicePlus installation changed neither file.
17. Select a historical POS invoice whose lines all contain `fk_warehouse = 0`; confirm it is returned for a matching invoice-origin stock movement, PosNova configuration, TakePOS terminal, or bank-account warehouse mapping.
18. For that legacy invoice, confirm `warehouse_lines_only=false` preserves the exact native lines and `warehouse_lines_only=true` returns no unmatched line without changing totals.
19. Give one line an explicit positive warehouse; confirm only explicit line warehouses select the invoice and no legacy fallback can override that assignment.
20. Set `INVOICEPLUS_ENABLE_WAREHOUSE_FALLBACKS=0`; confirm historical zero-line invoices are excluded while invoices with matching positive line warehouses remain available.
21. Call `GET /invoiceplus/byaccounts?account_ids=<ids>` and verify filtering, pagination, access restrictions, property filtering, and optional `invoiceclosure` data.
22. Confirm the former custom path `GET /invoices/byaccounts` is absent and that clients use `GET /invoiceplus/byaccounts`.

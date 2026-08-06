# InvoicePlus manual acceptance checklist

Run these checks on a disposable Dolibarr 20.0.4 test entity with API, Customer Invoices, Stock, and InvoicePlus enabled.

1. Confirm `/api/index.php/explorer/` lists `invoiceplus` and `GET /warehouse/{warehouse_id}`.
2. Test a warehouse with no invoices: HTTP 200 and `[]`.
3. Test valid, zero, negative, non-numeric, and unknown warehouse ids.
4. Compare an invoice returned by InvoicePlus with `GET /invoices/{id}` using `loadlinkedobjects=true`; only `invoiceplus_warehouse_filter` may differ.
5. Create two invoice lines in one warehouse and confirm the invoice occurs once.
6. Put invoice lines in two warehouses and confirm both warehouse filters select the same invoice.
7. Verify `warehouse_lines_only=true` changes only `lines`, never totals or payment values.
8. Verify `withLines=false`, `properties`, sorting, date bounds, status, third-party filtering, page boundaries, and the configured maximum limit.
9. Test an external user, an internal user limited to assigned customers, a user without invoice-read permission, and a user without stock-read permission.
10. Switch entity with `DOLAPIENTITY`; verify cross-entity warehouses and invoices are inaccessible.
11. Disable InvoiceClosure: the `invoiceclosure` property disappears, normal queries still work, and `status=closed` returns HTTP 400.
12. Enable InvoiceClosure with read permission: compare closed, reopened, and never-closed invoice payloads with the native API.
13. Set `INVOICEPLUS_LOAD_CLOSURE_DATA=0`; confirm only InvoicePlus removes the native closure property.
14. Disable and re-enable InvoicePlus, clear the REST production cache if enabled, and repeat explorer and endpoint checks.
15. Run the native `/invoices` and `/invoices/{id}` endpoints after installation to confirm there is no regression.
16. Run `git diff -- compta/facture/class/api_invoices.class.php api/index.php` and confirm InvoicePlus installation changed neither file.

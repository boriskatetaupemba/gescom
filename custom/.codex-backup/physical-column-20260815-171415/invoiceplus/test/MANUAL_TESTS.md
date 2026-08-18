# InvoicePlus manual acceptance checklist

Run these checks on a disposable Dolibarr 20.0.4 test entity with API, Customer Invoices, Stock, and InvoicePlus enabled.

1. Confirm `/api/index.php/explorer/` lists `invoiceplus`, the root list, `GET /byaccounts`, `GET /warehouse/{warehouse_id}`, and `GET /thirdparties`.
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
23. Assign A and C to sales representative U, B and C to V, and leave D unassigned. Verify U receives A and C exactly once, V receives B and C, and neither receives D.
24. Give U the global customer-view right and verify `/invoiceplus/thirdparties` is still restricted to A and C. Removing an assignment must remove the third party immediately.
25. Verify `status=1`, `status=0`, `status=-1`, property filtering, stable pages and invalid sorting/status parameters. No assignment must return HTTP 200 with `[]`.
26. Verify an external user and a user missing `societe.lire` receive HTTP 403. A user with `societe.lire` but without `facture.lire` must still access this route, and `DOLAPIENTITY` must never leak a third party from another entity.
27. Re-enable InvoicePlus 1.3.0 and verify `llx_invoiceplus_cash_settlement` and its unique `(entity, operation_id)` index exist.
28. Settle fresh validated CDF invoices with exact CDF, exact USD, and mixed CDF/USD. Verify native payment, `paiement_facture`, bank lines, invoice paid state, and the stored completed operation.
29. Receive excess USD and return CDF, then receive excess CDF and return USD. Verify each case creates two reciprocal `banktransfert` URLs and that final CDF/USD account deltas equal received minus change.
30. Replay the identical body and verify payment ids and bank-line ids are unchanged. Reuse the key with a changed cent, account, rate, date or invoice and verify HTTP 409 with no new ledger row.
31. Send two concurrent requests with the same operation id, and then two different operation ids for one invoice. Verify at most one settlement commits and the invoice never receives excess payment.
32. Verify stale total/rate, inactive or non-LIQ payment mode, closed/wrong-currency/wrong-warehouse account, external user, missing rights, and insufficient change all fail without payment or bank mutation.
33. Drop the HTTP response after commit, call `GET /cash-settlements/{operation_id}`, and verify the creating user receives the stored result while another user receives 404.
34. Force failures from the second payment, bank line, transfer link, invoice close and result update in a disposable environment; verify the outer transaction rolls back every payment, bank line and invoice status change.
35. Hold a full InvoicePlus settlement before commit, start a native customer payment that calculated the old remainder, then release the InvoicePlus transaction. Verify the native `PAYMENT_CUSTOMER_CREATE` trigger rejects and rolls back the stale payment with no orphan `paiement`, `paiement_facture` or bank row.
36. Repeat the race in reverse order. Verify the native payment commits first and InvoicePlus rejects the changed remainder without adding a second payment.
37. Remove or alter `uk_invoiceplus_cash_operation` in a disposable database, then re-enable InvoicePlus. Activation must fail until the exact unique `(entity, operation_id)` key is restored.
38. Validate a stock-managed invoice through the native API with the authenticated user's warehouse while its lines still have `fk_warehouse = 0`; settle it and verify InvoicePlus accepts the matching invoice-origin stock movements, then backfills only those stock-managed lines with the warehouse id in the same transaction.
39. Validate an otherwise identical invoice without stock calculation or without a warehouse and verify settlement fails closed because no exact invoice-origin stock movement proves the exit. No payment, bank line, paid status, or warehouse backfill may be committed.
40. Split one invoiced quantity across several batch movements and verify their net quantity is accepted. Then alter one movement quantity in a disposable database and verify settlement fails without mutation.
41. Set an invoice line's `fk_warehouse` to another positive warehouse, or leave a non-zero net invoice-origin movement in another warehouse; verify HTTP 403 and no backfill. A fully reversed historical movement in the other warehouse followed by a correct revalidation in the user warehouse must be accepted.
42. Submit a CDF account different from `facture.fk_account`; verify HTTP 409 even when both accounts belong to the same warehouse.
43. Settle a mixed invoice with 142,500 CDF and 100 USD at 2,850 CDF/USD. Verify the CDF payment stores `paiement.amount=50`, `paiement.multicurrency_amount=142500`, `bank.amount=142500`, and `bank.amount_main_currency=50`; verify the USD payment stores `bank.amount=100` and a null `bank.amount_main_currency`.
44. In a disposable database, force any one of the payment, `paiement_facture`, bank-line, account-id, account-currency, or base-equivalent values to differ before InvoicePlus post-write reconciliation. Verify HTTP 500 and rollback of the complete settlement, including both mixed payments.
45. Re-enable InvoicePlus so the `invoicecard` hook is registered, then open the native card for a mixed InvoicePlus invoice. Verify the read-only block shows 142,500 CDF as physical cash and 50 USD as company-currency equivalent while the unchanged native Payments table below still shows 50 USD.

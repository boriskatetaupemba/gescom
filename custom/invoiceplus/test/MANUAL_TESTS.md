# InvoicePlus manual acceptance checklist

Run these checks on a disposable Dolibarr 20.0.4 test entity with API, Customer Invoices, Stock, and InvoicePlus enabled.

1. Confirm `/api/index.php/explorer/` lists `invoiceplus`, the root list, `GET /byaccounts`, `GET /warehouse/{warehouse_id}`, `GET /thirdparties`, `GET /products/customer/{customer_id}`, and `GET /products/warehouse/{warehouse_id}`.
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
45. Re-enable InvoicePlus so the `invoicecard` hook is registered, then open the native card for a mixed InvoicePlus invoice. Verify the existing Payments table has exactly one **Physical amount** column immediately before **Amount**, with 142,500 CDF beside the native 50 USD and 100 USD beside the native 100 USD. Verify there is no separate InvoicePlus table or explanatory notice.
46. Add a non-InvoicePlus payment plus credit-note/deposit and remainder summary rows to the same disposable invoice. Verify its physical cell shows an em dash, every native Amount remains under the original Amount header, every summary label remains aligned, and repeated enrichment never adds a second header, cell or colspan increment. Repeat with the Bank module column enabled and disabled and at narrow viewport width.
47. In a disposable database, alter an InvoicePlus payment `ref_ext`, settlement status, entity, invoice journal link or account currency one field at a time. Verify the row is never mapped to a physical amount, including a `:cdf` suffix on a USD account and `:usd` on CDF; restore the exact suffix/currency pair and completed same-entity/same-invoice journal and verify mapping returns.

## Price levels (1.4.0)

Run these on a disposable entity with `PRODUIT_MULTIPRICES` enabled and
`PRODUIT_MULTIPRICES_LIMIT` set to a known value N, after re-enabling InvoicePlus.

48. Confirm `/api/index.php/explorer/` lists `GET /products/customer/{customer_id}` and `GET /products/warehouse/{warehouse_id}`, and that `GET /warehouse/{warehouse_id}` still returns invoices.
49. Open **Products > New product**. Confirm exactly N price rows appear, labelled with the native `SellingPrice` label plus any configured `PRODUIT_MULTIPRICES_LABEL{N}`, each with its HT/TTC selector. Change `PRODUIT_MULTIPRICES_LIMIT` and reload: the number of rows must follow immediately, with no reinstallation.
50. Submit the creation form with every price empty. The product must be created by the native cycle, and `product_price` must contain only the native level-1 line.
51. Create a product with level 1 and level 2 filled in. Confirm two history lines, one per level, with the submitted HT/TTC bases, and confirm the native `PRODUCT_PRICE_MODIFY` trigger fired for level 2.
52. Create a product with level 2 exactly `0`. Confirm a level-2 line exists with price `0`. Create another with level 2 empty and confirm no level-2 line exists at all.
53. Submit level 2 with level 1 empty. Creation must be refused before any record is written, with an explicit message, and the submitted values must still be in the redisplayed form.
54. Submit a malformed amount such as `abc` on any level. Same expectation: refusal, no product, values preserved.
55. In a disposable database, force a level to fail (for example a `PRODUCT_PRICE_MODIFY` trigger returning `-1`). Confirm the whole creation is rolled back: no `product` row, no `product_price` row.
56. Create a product through `POST /api/index.php/products` with a price. Confirm no InvoicePlus processing occurs and no extra history line is created.
57. Open the native **Prices** tab of a product created through the new form. Confirm edition, minimum prices, VAT, automatic rules and history all behave exactly as before, and that InvoicePlus adds no second edition interface.
58. Open a warehouse card. Confirm a **Price level** row offering *None* and levels 1..N, in creation, edition and view mode, and that the native extrafield is not also rendered as a raw number.
59. Save *None*, then reload: the stored column must be `NULL`, not `0`. Save level 1, then level N, and confirm each is stored and redisplayed.
60. Post `options_invoiceplus_price_level` values `0`, `-1` and `N+1` directly. Each must be refused with an explicit message and no record written.
61. Switch entity with `DOLAPIENTITY` / the multicompany selector. Confirm the level of a warehouse in another entity is never read.
62. Disable `PRODUIT_MULTIPRICES`. Confirm the warehouse row disappears, the product creation grid disappears, saving a warehouse does not erase its stored level, and both product routes answer HTTP 409. Re-enable it and confirm the previous levels are still there.
63. Disable and re-enable InvoicePlus. Confirm the `invoiceplus_price_level` definition and every stored value survive.
64. In a disposable database, create an extrafield named `invoiceplus_price_level` on `entrepot` with a different type, then enable InvoicePlus. Activation must fail with an explicit message instead of reusing it.
65. Customer on level 3, product with level 3 = 45: the customer route returns 45, `applied_price_level` 3, `price_fallback` false.
66. Customer on level 3, product without level 3 but with level 1 = 50: the route returns 50, `applied_price_level` 1, `price_fallback` true.
67. Customer with no level and product level 1 = 50: 50 with `price_level_source` `default_level`.
68. Set a customer's `price_level` to a value above the current limit directly in the database: the route must return level 1 with `default_level` and log the ignored level.
69. Repeat 65 to 68 on the warehouse route with the warehouse level.
70. Customer on level 3, warehouse on level 2, product with level 1 = 50, level 2 = 45 and no level 3. The customer route must return **50**, never 45, with `requested_price_level` 3 and `applied_price_level` 1.
71. Product with level 2 price exactly `0` and a customer on level 2: the route must return `0` with no fallback. Set the level-2 price column to `NULL` and confirm the fallback to level 1 resumes.
72. Product with no price at the requested level and none at level 1: the default answer is HTTP 422 naming the product; `on_missing_price=skip` omits it and logs a warning.
73. Verify `pagination_data`, `limit`, `page`, `sortfield`, `sortorder`, `mode`, `category`, `variant_filter`, `sqlfilters`, `includestockdata` and `properties`. Confirm `properties` can select and exclude the four new resolution properties.
74. Verify HTTP 400 for a non-whitelisted `sortfield`, a non-whitelisted `sqlfilters` field, an unknown alias, an invalid `on_missing_price`, and a zero, negative or non-numeric id. Verify HTTP 404 for an unknown customer or warehouse.
75. Verify HTTP 403 for a user without `produit.lire`, for a user without `societe.lire` on the customer route, for a user without `stock.lire` on the warehouse route, and for an external user requesting another third party.
76. Enable the SQL log and call each route with `limit=50`. Confirm exactly one query touches `product_price` for the whole page.
77. Confirm the native `GET /products` response and the native product list page are unchanged, and that stock movements, PMP/AWP and stock valuation are untouched by any warehouse level change.
78. Post an invoice through native `POST /api/index.php/invoices` with `lines[].subprice` set to `0` and to a positive value. Confirm both are stored verbatim, and confirm that omitting `subprice` stores `0` rather than a resolved price.
79. Re-run the existing checks 1 to 47, in particular `cash-settlement`, invoice validation and the invoice-card physical-amount column, and confirm nothing changed.

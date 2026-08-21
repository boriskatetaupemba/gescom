# Changelog

## 1.4.0 - 2026-08-21

- Add a central Customer > Warehouse > N1 price-level resolver built entirely
  on the native `product_price` grid, with a direct level-1 fallback that never
  tries an intermediate level and never turns a defined zero into an absence.
- Read the raw `societe.price_level` column so "no customer level" stays
  distinguishable from an explicit N1, and treat a legacy level above
  `PRODUIT_MULTIPRICES_LIMIT` as undefined.
- Add the N dynamic price rows missing from the native product creation form
  through the `productcard` hook, reusing the native `price`, `price_base_type`,
  `price_{N}` and `multiprices_base_type_{N}` field names.
- Persist levels 2 to N with a dedicated `PRODUCT_CREATE` trigger calling the
  native `Product::updatePrice()` inside the creation transaction; an empty
  field writes nothing, an explicit zero writes zero, and a failing level rolls
  the whole creation back.
- Give warehouses a nullable commercial price level stored in the native
  `entrepot_extrafields` table, rendered by the `warehousecard` hook from a
  choice list rebuilt on every request.
- Add `GET /invoiceplus/products/customer/{customer_id}` and
  `GET /invoiceplus/products/warehouse/{warehouse_id}`, returning the native
  product representation with the applicable price and the four resolution
  properties, resolved by one grouped price query per page.
- Keep `POST /invoiceplus/invoices/{id}/cash-settlement`, every existing route
  and every stock valuation rule unchanged; document that InvoicePlus has no
  invoice-creation POST and that the native one preserves an explicit
  `lines[].subprice`, `0` included.

## 1.3.4 - 2026-08-18

- Record exactly one positive cash-account movement for the full amount
  physically received and one negative movement for the full amount returned,
  independently for CDF and USD.
- Keep native invoice allocations exact while grossing up their bank ledger
  rows; remove visible compensating and cross-currency transfer movements.
- Give every movement a concise French description containing its amount,
  currency, invoice reference and customer, with native links to both records.
- Re-read and reconcile the final bank rows before commit, rejecting net
  receipts, wrong signs, missing ids or altered descriptions.

## 1.3.3 - 2026-08-18

- Record same-currency returned change as an explicit negative native cash-
  account row instead of hiding it inside the net invoice payment.
- Add the compensating gross-tender row so account balances remain unchanged,
  link both rows, and reconcile their signs, currencies and base amounts before
  committing the settlement.
- Prove that cross-currency change deficits remain carried by the existing
  negative transfer row and expose direct rows through `cash_movements`.
- Treat cash received by the current sale as available for change even when a
  historical account ledger balance is negative.

## 1.3.2 - 2026-08-15

- Replace the separate physical-cash block with an upgrade-safe, idempotent
  enrichment of Dolibarr's native invoice payment table.
- Insert the physical cash-account amount immediately before the native company-
  currency Amount column, while preserving payment ids and summary alignment.
- Map only exact CDF/USD payment references backed by a completed settlement
  journal for the same Dolibarr entity and invoice, with a suffix that matches
  the cash-account currency.

## 1.3.1 - 2026-08-15

- Prove the invoice warehouse from exact native stock movements and backfill
  only verified legacy missing line metadata inside the transaction.
- Reconcile each created payment through its invoice link, bank line and account
  currency; expose physical cash versus company-currency equivalent explicitly.
- Add an upgrade-safe invoice-card hook showing physical CDF/USD tender amounts
  alongside their company-currency equivalents without changing core files.

## 1.3.0 - 2026-08-12

- Add atomic `POST /invoiceplus/invoices/{id}/cash-settlement` for CDF/USD
  cash received and CDF/USD change.
- Add `GET /invoiceplus/cash-settlements/{operation_id}` for safe recovery
  after a timeout or lost HTTP response.
- Freeze the invoice exchange rate, reconcile integer cents in both currencies,
  validate server-side warehouse cash accounts and available change, and reject
  stale invoice totals.
- Create standard Dolibarr customer payments and linked cross-currency bank
  transfers inside one outer transaction, closing the invoice only at an exact
  zero balance.
- Add a unique operation journal so concurrent retries execute once; identical
  payloads replay the stored result and changed payloads return HTTP 409.
- Add a transactional `PAYMENT_CUSTOMER_CREATE` safeguard: native Dolibarr
  payments are serialized against protected InvoicePlus invoices and rolled
  back if either the company-currency or invoice-currency total is exceeded.
- Refuse module activation when SQL loading fails or when the settlement table
  and exact unique `(entity, operation_id)` operation key cannot be verified.

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

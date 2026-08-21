# InvoicePlus price levels

InvoicePlus 1.4.0 completes Dolibarr's native multi-level prices with the three
functions Dolibarr does not provide: entering levels N1..N while a product is
being created, giving a commercial price level to a warehouse, and resolving
the applicable price through a single documented algorithm.

Target environment: Dolibarr 20.0.4, PHP 8.2, MariaDB/MySQL with a
configurable table prefix. No Dolibarr core file is modified.

## 1. One single price grid

Customers and warehouses share the native `product_price` grid, keyed by the
native `price_level` column and filtered by the native `productprice` entity
perimeter. The module creates:

- no price table of its own;
- no per-warehouse price table;
- no copy of the product prices in an extrafield.

The only new data is the **level number** of a warehouse.

The number of levels is never hardcoded. Every loop, list, validation and query
uses `getDolGlobalInt('PRODUIT_MULTIPRICES_LIMIT')`, and every interface and
endpoint requires `getDolGlobalString('PRODUIT_MULTIPRICES')` together with a
strictly positive limit.

The level of a warehouse is commercial information only. It never touches the
PMP/AWP, stock valuation, stock-movement amounts, accounting algorithms or the
native validation and cancellation of movements.

## 2. Resolution algorithm

### 2.1 Level

```text
if the customer has an explicit valid level
    requested level = customer level
    source = customer
else if the warehouse has an explicit valid level
    requested level = warehouse level
    source = warehouse
else
    requested level = 1
    source = default_level
```

`societe.price_level` is nullable, but `Societe::fetch()` replaces an empty
value by `1`. To keep "no customer level" distinguishable from an explicit N1,
the service:

1. loads the third party with the native `Societe` class, which checks
   existence, entity visibility and access rights;
2. reads the raw persisted `societe.price_level` with a separate read-only
   query;
3. treats `NULL` and `0` as undefined.

The module never writes into `societe`. A legacy persisted level that is now
greater than `PRODUIT_MULTIPRICES_LIMIT` is treated as undefined and logged
with `dol_syslog`, so a level outside the current configuration is never read.

### 2.2 Price

```text
if the current price line of the requested level exists
and its price column is not NULL
    use that level, price = 0 included
else
    read the current line of level 1 directly
```

An intermediate level is never tried, and the warehouse level is never used
after a customer level has been selected. The current line of a level is the
native one:

```sql
WHERE entity IN (<productprice entities>) AND price_level = <level>
ORDER BY date_price DESC, rowid DESC
```

A price is defined when the line exists and `price IS NOT NULL`. A numeric zero
is defined; neither `empty()` nor `> 0` is used to decide. When the requested
level and level 1 are both absent or `NULL`, the service raises a business
error — it never invents the price `0`.

### 2.3 Returned metadata

```json
{
  "requested_price_level": 3,
  "applied_price_level": 1,
  "price_level_source": "customer",
  "price_fallback": true,
  "price": 50.00,
  "price_ttc": 60.00,
  "price_base_type": "HT",
  "tva_tx": 20.0
}
```

`price_level_source` is `customer`, `warehouse` or `default_level`.
`applied_price_level` is `1` whenever the level-1 fallback was used. The
service also returns `price_min`, `price_min_ttc`, `price_label`,
`default_vat_code`, `recuperableonly`, the two local taxes, the multicurrency
columns of the applied line and its `price_line_id`. Every field comes from the
same applied line, so a level price is never mixed with the tax data of another
level.

Entry points, in `class/invoicepluspricelevelservice.class.php`:

```php
resolvePriceLevel(?int $customerId, ?int $warehouseId): array
resolveProductPrice(int $productId, int $requestedLevel): array
getProductPriceForCustomer(int $productId, int $customerId): array
getProductPriceForWarehouse(int $productId, int $warehouseId): array
```

## 3. Multi-level prices when a product is created

The native creation form deliberately hides the multiprice grid and only keeps
the VAT selector. InvoicePlus adds the grid through the `productcard` hook
context, during creation only:

```text
Selling price 1 : [        ] [HT/TTC]
...
Selling price N : [        ] [HT/TTC]
```

Labels use `PRODUIT_MULTIPRICES_LABEL{N}` when configured, appended to the
native `SellingPrice N` label. The fields reuse the names the native form
already reads: `price` and `price_base_type` for level 1, `price_{N}` and
`multiprices_base_type_{N}` for levels 2 to N. The VAT stays the one of the
native form; no per-level VAT is introduced.

### 3.1 Validation

For each level:

- empty field: level not provided;
- `0` or `0.00`: explicitly null price;
- any other value: monetary value validated with Dolibarr's own `price2num()`
  after a strict character check;
- malformed or non-finite value: creation refused with an explicit message.

`empty()` is never used to decide whether a price was entered. If any higher
level is filled in, level 1 must be filled in too because it is the fallback.
If every field is empty, the native creation runs untouched.

The hook only refuses; it never grants. The native product/service creation
rights and the native CSRF token remain authoritative.

### 3.2 Persistence

Level 1 stays created and historized by the native `Product::create()` cycle.
A hidden `invoiceplus_multiprices_form` marker identifies this form, and the
validated levels 2 to N travel in `$object->context` to a dedicated
`PRODUCT_CREATE` trigger
(`core/triggers/interface_98_modInvoicePlus_InvoicePlusProductPrices.class.php`).

The trigger runs inside the creation transaction and calls the native
`Product::updatePrice()` once per filled level. Consequently:

- an empty field triggers no `updatePrice()`;
- an explicit zero triggers `updatePrice(0, ...)`;
- there is no direct `INSERT` into `product_price`;
- the native `PRODUCT_PRICE_MODIFY` trigger keeps firing;
- the context is consumed on first use, so no level can be historized twice;
- a failing level returns `-1`, which rolls the whole creation back.

A product created by the native API, by another module or by any form without
the marker is never processed.

### 3.3 Edition after creation

The native **Prices** tab remains the only edition interface. It already covers
edition, VAT, HT/TTC prices, minimum prices, automatic rules and history.
InvoicePlus adds no second edition interface.

Note: like the native Prices tab, writing levels 2 to N leaves the `product`
table's own `price` columns holding the values of the last written level. The
authoritative per-level data is `product_price`, which the resolver reads.

## 4. Warehouse price level

The level is stored in the native `entrepot_extrafields` table through an
extrafield created by `modInvoicePlus::init()`:

| Property | Value |
|---|---|
| Name | `invoiceplus_price_level` |
| Type | `int`, nullable, size 5 |
| Element | `entrepot` |
| Required | no |
| Entity | the current entity, or `0` when a shared definition already exists |
| `enabled` | `getDolGlobalString("PRODUIT_MULTIPRICES") && getDolGlobalInt("PRODUIT_MULTIPRICES_LIMIT") > 0` |

No SQL script is added. Activation is idempotent: an existing compatible
definition is refreshed with `ExtraFields::updateExtraField()`, a missing one is
created with `ExtraFields::addExtraField()`. An incompatible definition carrying
the same name — a different type, or a required, unique or computed field —
stops the activation with an explicit error instead of silently reusing it.

Deactivating the module removes neither the definition nor the stored values.

The `warehousecard` hook context renders the field:

```text
Price level : None / Selling price 1 / ... / Selling price N
```

The list is rebuilt from the current configuration on every request; it is
never frozen into the extrafield options at installation time. During the
display phase the hook disables the native rendering of that single field in
memory only, so the stored definition, the stored values and the native
`setOptionalsFromPost()` persistence stay untouched.

Persisted values:

- `NULL`, or an empty selection normalized to `NULL`: no level;
- an integer between 1 and N: valid level;
- anything else — `0`, a negative value, N+1, a decimal, text: validation error
  that stops the native create/update.

When multiprices are disabled the `enabled` condition evaluates to `0`: the
field is not displayed, and `setOptionalsFromPost()` skips it, so an existing
value is never erased.

## 5. REST endpoints

```http
GET /api/index.php/invoiceplus/products/customer/{customer_id}
GET /api/index.php/invoiceplus/products/warehouse/{warehouse_id}
```

The customer route takes no warehouse and never infers one. The warehouse route
must not be confused with `GET /api/index.php/invoiceplus/warehouse/{warehouse_id}`,
which returns invoices and keeps exactly its previous behaviour.

### 5.1 Parameters

| Parameter | Default | Notes |
|---|---:|---|
| `sortfield` | `t.ref` | Whitelist: id, ref, external ref, label, barcode, dates, price, price_ttc, tosell, tobuy, type, stock. |
| `sortorder` | `ASC` | `ASC` or `DESC`. |
| `limit` | `100` | Capped by `INVOICEPLUS_MAX_API_LIMIT`; zero or negative uses the cap. |
| `page` | `0` | Zero-based and non-negative. |
| `mode` | `0` | `0` all, `1` products, `2` services. |
| `category` | `0` | Native category filter. |
| `sqlfilters` | empty | Universal Search restricted to the `t.` and `ef.` aliases with a product-field whitelist. |
| `variant_filter` | `0` | Native variant filter, `0` to `3`. |
| `pagination_data` | `false` | Native `data` / `pagination` envelope. |
| `includestockdata` | `0` | Requires `stock.lire`. |
| `properties` | empty | Native property filter, applied after enrichment. |
| `on_missing_price` | `error` | `error` refuses the page with HTTP 422; `skip` omits unpriced products. |

### 5.2 Response

The representation stays that of the native products API. Only the commercial
fields of the applied price line are replaced — `price`, `price_ttc`,
`price_min`, `price_min_ttc`, `price_base_type`, `price_label`, `tva_tx`,
`default_vat_code` and the two local taxes — and four properties are added:

```text
requested_price_level
applied_price_level
price_level_source
price_fallback
```

Native multicurrency fields are neither recalculated nor converted.

Because products are loaded with the sixth argument of `Product::fetch()`
(`$ignore_price_load`) set to `1`, the `multiprices*` arrays of the response are
empty. This is the documented cost of avoiding
`PRODUIT_MULTIPRICES_LIMIT` queries per product; the applicable price is in the
fields above.

### 5.3 Performance

There is no price query per product. After selection and pagination:

1. one grouped query reads the current lines of the requested level and of
   level 1 for every product of the page;
2. the native `date_price DESC, rowid DESC` order settles the history;
3. a PHP index by product and level is built;
4. prices are resolved in memory.

Measured on the reference installation: one `product_price` query for a
five-product page.

### 5.4 Permissions and entities

Both routes use the Dolibarr REST authentication, honour
`INVOICEPLUS_API_ENABLED`, and require `produit.lire`. The customer route also
requires `societe.lire` and the warehouse route `stock.lire`. Native resource
checks (`_checkAccessToResource`) are applied to the third party and to the
warehouse, external users are restricted to their own third party, and
products, third parties, warehouses and prices are filtered by the native
entity perimeters. A null, negative or malformed identifier is rejected.

### 5.5 Error codes

| Situation | HTTP |
|---|---:|
| Malformed id or invalid parameter | 400 |
| Insufficient permission | 403 |
| Customer or warehouse not found | 404 |
| Multiprices disabled | 409 |
| No applicable price, level 1 included | 422 |
| Database read error | 503 |

No empty array and no zero price are returned to hide a business error.

## 6. Known native limitation on level 1

In Dolibarr 20.0.4, `Product::create()` normalizes an empty level-1 price to
zero and always writes a level-1 history line. That native line must therefore
be considered a **defined price equal to zero**. InvoicePlus does not add a
presence table only to distinguish an empty level 1 from an explicitly null
level 1.

For levels 2 to N the added form preserves the difference:

- empty: no price line is created;
- zero: a price line is created with zero.

## 7. Invoice creation is out of scope

InvoicePlus has **no** invoice-creation POST. Its only write endpoint remains
`POST /api/index.php/invoiceplus/invoices/{invoice_id}/cash-settlement`, which
settles an existing invoice and is unchanged by this version. Standard creation
stays on the native `POST /api/index.php/invoices`.

Targeted analysis of Dolibarr 20.0.4 confirms that the native route preserves
an explicitly supplied `lines[].subprice`, `0` included:

- `Invoices::post()` assigns the request fields to the `Facture` object and
  calls `Facture::create()`;
- `Facture::create()` casts each line array to an object and forwards
  `$line->subprice` to `Facture::addline()`;
- `Facture::addline()` passes it through `price2num()` into
  `calcul_price_total()`. Its only `Product::fetch()` call reads the product
  type and stock, never a price.

Consequently, an absent or `null` `subprice` produces a line at zero: it does
**not** trigger the InvoicePlus resolution in this version. Automatic
resolution at API creation time requires a separate deliverable that explicitly
defines the new InvoicePlus route, the real `lines[].subprice` field, a pricing
warehouse supplied before creation, the difference between absent, `null` and
zero, free lines without a product, the possible persistence of the warehouse
on lines, and the multicurrency contract.

Manually entered invoices are also out of scope. Dolibarr already knows the
customer while an invoice is being typed and applies the native customer level;
the global warehouse is generally chosen at stock validation, after the prices,
and an invoice may carry different warehouses per line. This version therefore
never rewrites the price of a manual invoice line from an undetermined
warehouse. The central service nevertheless supports Customer > Warehouse > N1
and is tested independently so a future caller holding both identifiers can use
it.

## 8. Upgrading an existing installation

1. Back up the database and the `custom/invoiceplus` directory.
2. Replace the module files.
3. In **Home > Setup > Modules/Applications**, disable and re-enable
   **Invoice Plus**. Re-activation is required: it refreshes the declared hook
   contexts (`productcard`, `warehousecard`) and creates or refreshes the
   `invoiceplus_price_level` extrafield.
4. Check that **Home > Setup > Other > `PRODUIT_MULTIPRICES`** is enabled and
   that `PRODUIT_MULTIPRICES_LIMIT` is strictly positive; otherwise the new
   interfaces stay hidden and the two new endpoints answer HTTP 409.
5. If `API_PRODUCTION_MODE` is enabled, clear the REST/Restler cache, or
   disable and re-enable the REST API module, so the two new routes appear in
   the explorer.

No data is lost: no table is dropped, the settlement journal is preserved, and
the warehouse levels stored in `entrepot_extrafields` survive deactivation.

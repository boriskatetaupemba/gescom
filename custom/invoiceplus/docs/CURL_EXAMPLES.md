# cURL examples

Replace the base URL, API key, and warehouse id.

## Atomic mixed CDF/USD cash settlement

```bash
curl -X POST \
  -H "DOLAPIKEY: YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "operation_id":"cash-20260812-0001",
    "date":1786492800,
    "payment_method_id":4,
    "exchange_rate":"2850",
    "total_cdf":"285000.00",
    "received":{"cdf":"142500.00","usd":"50.00"},
    "change":{"cdf":"0.00","usd":"0.00"},
    "accounts":{"cdf":8,"usd":9}
  }' \
  "https://YOUR-DOLIBARR/api/index.php/invoiceplus/invoices/123/cash-settlement"
```

Retry that exact body with the same `operation_id` after a timeout, or inspect
the operation without creating anything:

```bash
curl -X GET -H "DOLAPIKEY: YOUR_API_KEY" \
  "https://YOUR-DOLIBARR/api/index.php/invoiceplus/cash-settlements/cash-20260812-0001"
```

## Native-compatible list with InvoiceClosure enrichment

```bash
curl -X GET -H "DOLAPIKEY: YOUR_API_KEY" \
  "https://YOUR-DOLIBARR/api/index.php/invoiceplus?limit=100&page=0"
```

## Invoices linked to bank or cash accounts

```bash
curl -X GET -H "DOLAPIKEY: YOUR_API_KEY" \
  "https://YOUR-DOLIBARR/api/index.php/invoiceplus/byaccounts?account_ids=5,9&sortorder=DESC"
```

## Third parties assigned to the authenticated sales representative

```bash
curl -X GET -H "DOLAPIKEY: YOUR_API_KEY" \
  "https://YOUR-DOLIBARR/api/index.php/invoiceplus/thirdparties?limit=100&page=0&status=1"
```

The route deliberately has no `user_id` parameter and always uses the owner of
the supplied API key.

## Simple search

```bash
curl -X GET -H "DOLAPIKEY: YOUR_API_KEY" \
  "https://YOUR-DOLIBARR/api/index.php/invoiceplus/warehouse/5"
```

## Pagination

```bash
curl -X GET -H "DOLAPIKEY: YOUR_API_KEY" \
  "https://YOUR-DOLIBARR/api/index.php/invoiceplus/warehouse/5?limit=100&page=0&pagination_data=true"
```

## Paid or business-closed invoices

```bash
curl -X GET -H "DOLAPIKEY: YOUR_API_KEY" \
  "https://YOUR-DOLIBARR/api/index.php/invoiceplus/warehouse/5?status=paid"

curl -X GET -H "DOLAPIKEY: YOUR_API_KEY" \
  "https://YOUR-DOLIBARR/api/index.php/invoiceplus/warehouse/5?status=closed"
```

## Invoice period

```bash
curl -X GET -H "DOLAPIKEY: YOUR_API_KEY" \
  "https://YOUR-DOLIBARR/api/index.php/invoiceplus/warehouse/5?date_start=2026-08-01&date_end=2026-08-31"
```

## Without lines or with matching lines only

```bash
curl -X GET -H "DOLAPIKEY: YOUR_API_KEY" \
  "https://YOUR-DOLIBARR/api/index.php/invoiceplus/warehouse/5?withLines=false"

curl -X GET -H "DOLAPIKEY: YOUR_API_KEY" \
  "https://YOUR-DOLIBARR/api/index.php/invoiceplus/warehouse/5?warehouse_lines_only=true"
```

## Selected properties

```bash
curl -X GET -H "DOLAPIKEY: YOUR_API_KEY" \
  "https://YOUR-DOLIBARR/api/index.php/invoiceplus/warehouse/5?properties=id,ref,total_ttc,remaintopay,lines,invoiceclosure,invoiceplus_warehouse_filter"
```

## Multi-entity context

```bash
curl -X GET \
  -H "DOLAPIKEY: YOUR_API_KEY" \
  -H "DOLAPIENTITY: 1" \
  "https://YOUR-DOLIBARR/api/index.php/invoiceplus/warehouse/5"
```

## Products priced for a customer

```bash
curl -X GET -H "DOLAPIKEY: YOUR_API_KEY" \
  "https://YOUR-DOLIBARR/api/index.php/invoiceplus/products/customer/42?limit=50&page=0"
```

## Products priced for a warehouse

```bash
curl -X GET -H "DOLAPIKEY: YOUR_API_KEY" \
  "https://YOUR-DOLIBARR/api/index.php/invoiceplus/products/warehouse/5?limit=50&page=0"
```

Example object, for a customer on level 3 whose level has no price and falls
back to level 1:

```json
{
  "id": "14292",
  "ref": "05587",
  "label": "Sample product",
  "price": 50,
  "price_ttc": 60,
  "price_base_type": "HT",
  "tva_tx": 20,
  "requested_price_level": 3,
  "applied_price_level": 1,
  "price_level_source": "customer",
  "price_fallback": true
}
```

## Products, services or a category only

```bash
curl -X GET -H "DOLAPIKEY: YOUR_API_KEY" \
  "https://YOUR-DOLIBARR/api/index.php/invoiceplus/products/warehouse/5?mode=1&category=7"
```

## Pagination envelope, sorting and property filtering

```bash
curl -X GET -H "DOLAPIKEY: YOUR_API_KEY" \
  "https://YOUR-DOLIBARR/api/index.php/invoiceplus/products/customer/42?pagination_data=true&limit=20&page=2&sortfield=t.ref&sortorder=ASC"

curl -X GET -H "DOLAPIKEY: YOUR_API_KEY" \
  "https://YOUR-DOLIBARR/api/index.php/invoiceplus/products/customer/42?properties=id,ref,label,price,price_ttc,price_base_type,tva_tx,requested_price_level,applied_price_level,price_level_source,price_fallback"
```

## Universal Search filter and stock data

```bash
curl -X GET -H "DOLAPIKEY: YOUR_API_KEY" \
  --data-urlencode "sqlfilters=(t.tosell:=:1) and (t.ref:like:'PR%')" -G \
  "https://YOUR-DOLIBARR/api/index.php/invoiceplus/products/warehouse/5"

curl -X GET -H "DOLAPIKEY: YOUR_API_KEY" \
  "https://YOUR-DOLIBARR/api/index.php/invoiceplus/products/warehouse/5?includestockdata=1"
```

## Products without any applicable price

The default refuses the page and names the product:

```bash
curl -i -X GET -H "DOLAPIKEY: YOUR_API_KEY" \
  "https://YOUR-DOLIBARR/api/index.php/invoiceplus/products/warehouse/5"
# HTTP/1.1 422 Unprocessable Entity
# {"error":{"code":422,"message":"No applicable price for product 4074 (4682): level 1 has no defined price."}}
```

Omitting them is an explicit caller decision:

```bash
curl -X GET -H "DOLAPIKEY: YOUR_API_KEY" \
  "https://YOUR-DOLIBARR/api/index.php/invoiceplus/products/warehouse/5?on_missing_price=skip"
```

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

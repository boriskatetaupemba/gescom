# cURL examples

Replace the base URL, API key, and warehouse id.

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

#!/usr/bin/env sh
# InvoicePlus REST API compatibility test. Requires curl and jq.
# Usage: BASE_URL=https://dolibarr.example API_KEY=key WAREHOUSE_ID=5 [INVOICE_ID=123] [ACCOUNT_IDS=8,9] ./test_invoiceplus_api.sh

set -eu

: "${BASE_URL:?BASE_URL is required}"
: "${API_KEY:?API_KEY is required}"
: "${WAREHOUSE_ID:?WAREHOUSE_ID is required}"

BASE_URL=${BASE_URL%/}
ROOT_ENDPOINT="$BASE_URL/api/index.php/invoiceplus"
ENDPOINT="$ROOT_ENDPOINT/warehouse/$WAREHOUSE_ID"
TMPDIR_INVOICEPLUS=$(mktemp -d)
trap 'rm -rf "$TMPDIR_INVOICEPLUS"' EXIT INT TERM

request() {
	url=$1
	body=$2
	curl --silent --show-error --output "$body" --write-out '%{http_code}' -H "DOLAPIKEY: $API_KEY" "$url"
}

assert_code() {
	label=$1
	expected=$2
	actual=$3
	if [ "$actual" != "$expected" ]; then
		echo "FAIL  $label: expected HTTP $expected, got $actual"
		exit 1
	fi
	echo "PASS  $label"
}

code=$(request "$ROOT_ENDPOINT?limit=2&page=0" "$TMPDIR_INVOICEPLUS/root.json")
assert_code 'root invoice list' 200 "$code"
jq -e 'type == "array" and length <= 2' "$TMPDIR_INVOICEPLUS/root.json" >/dev/null
echo 'PASS  root list cap'

code=$(request "$ROOT_ENDPOINT/thirdparties?limit=2&page=0&properties=id,name" "$TMPDIR_INVOICEPLUS/thirdparties.json")
assert_code 'assigned third-party list' 200 "$code"
jq -e 'type == "array" and length <= 2 and all(.[]; ((keys - ["id", "name"]) | length) == 0)' "$TMPDIR_INVOICEPLUS/thirdparties.json" >/dev/null
echo 'PASS  assigned third-party scope and properties'

code=$(request "$ROOT_ENDPOINT/thirdparties?sortfield=t.unknown" "$TMPDIR_INVOICEPLUS/bad-thirdparty-sort.json")
assert_code 'invalid third-party sort field' 400 "$code"

if [ -n "${ACCOUNT_IDS:-}" ]; then
	encoded_account_ids=$(jq -rn --arg value "$ACCOUNT_IDS" '$value | @uri')
	code=$(request "$ROOT_ENDPOINT/byaccounts?account_ids=$encoded_account_ids&limit=2" "$TMPDIR_INVOICEPLUS/by-accounts.json")
	assert_code 'invoice list by accounts' 200 "$code"
	jq -e 'type == "array"' "$TMPDIR_INVOICEPLUS/by-accounts.json" >/dev/null
fi

code=$(request "$ENDPOINT" "$TMPDIR_INVOICEPLUS/list.json")
assert_code 'valid warehouse list' 200 "$code"
jq -e 'type == "array"' "$TMPDIR_INVOICEPLUS/list.json" >/dev/null

code=$(request "$ENDPOINT?pagination_data=true&limit=2&page=0" "$TMPDIR_INVOICEPLUS/page.json")
assert_code 'pagination envelope' 200 "$code"
jq -e '.data | type == "array"' "$TMPDIR_INVOICEPLUS/page.json" >/dev/null
jq -e '.pagination.total >= 0 and .pagination.page == 0 and .pagination.limit == 2' "$TMPDIR_INVOICEPLUS/page.json" >/dev/null

code=$(request "$ENDPOINT?withLines=false" "$TMPDIR_INVOICEPLUS/no-lines.json")
assert_code 'withLines=false' 200 "$code"
jq -e 'all(.[]; has("lines") | not)' "$TMPDIR_INVOICEPLUS/no-lines.json" >/dev/null

code=$(request "$ENDPOINT?warehouse_lines_only=true" "$TMPDIR_INVOICEPLUS/warehouse-lines.json")
assert_code 'warehouse_lines_only=true' 200 "$code"
jq -e --argjson warehouse "$WAREHOUSE_ID" 'all(.[]; all((.lines // [])[]; (.fk_warehouse | tonumber) == $warehouse))' "$TMPDIR_INVOICEPLUS/warehouse-lines.json" >/dev/null

code=$(request "$ENDPOINT?date_start=2026-99-99" "$TMPDIR_INVOICEPLUS/bad-date.json")
assert_code 'invalid date' 400 "$code"

code=$(request "$BASE_URL/api/index.php/invoiceplus/warehouse/0" "$TMPDIR_INVOICEPLUS/bad-id.json")
assert_code 'zero warehouse id' 400 "$code"

code=$(request "$BASE_URL/api/index.php/invoiceplus/warehouse/999999999" "$TMPDIR_INVOICEPLUS/missing.json")
assert_code 'unknown warehouse' 404 "$code"

if [ -n "${INVOICE_ID:-}" ]; then
	code=$(request "$BASE_URL/api/index.php/invoices/$INVOICE_ID" "$TMPDIR_INVOICEPLUS/native.json")
	assert_code 'native invoice used for comparison' 200 "$code"

	code=$(request "$ROOT_ENDPOINT/$INVOICE_ID" "$TMPDIR_INVOICEPLUS/detail.json")
	assert_code 'InvoicePlus invoice detail' 200 "$code"
	jq 'del(.invoiceclosure)' "$TMPDIR_INVOICEPLUS/detail.json" | jq -S . > "$TMPDIR_INVOICEPLUS/detail.sorted.json"
	jq -S . "$TMPDIR_INVOICEPLUS/native.json" > "$TMPDIR_INVOICEPLUS/native.sorted.json"
	cmp "$TMPDIR_INVOICEPLUS/native.sorted.json" "$TMPDIR_INVOICEPLUS/detail.sorted.json"
	echo 'PASS  detail native-compatible payload'

	invoice_ref=$(jq -r '.ref // empty' "$TMPDIR_INVOICEPLUS/native.json")
	if [ -n "$invoice_ref" ]; then
		encoded_ref=$(jq -rn --arg value "$invoice_ref" '$value | @uri')
		code=$(request "$ROOT_ENDPOINT/ref/$encoded_ref" "$TMPDIR_INVOICEPLUS/by-ref.json")
		assert_code 'invoice lookup by ref' 200 "$code"
	fi

	invoice_ref_ext=$(jq -r '.ref_ext // empty' "$TMPDIR_INVOICEPLUS/native.json")
	if [ -n "$invoice_ref_ext" ]; then
		encoded_ref_ext=$(jq -rn --arg value "$invoice_ref_ext" '$value | @uri')
		code=$(request "$ROOT_ENDPOINT/ref_ext/$encoded_ref_ext" "$TMPDIR_INVOICEPLUS/by-ref-ext.json")
		assert_code 'invoice lookup by external ref' 200 "$code"
	fi

	code=$(request "$ENDPOINT?limit=1000&loadlinkedobjects=true" "$TMPDIR_INVOICEPLUS/plus.json")
	assert_code 'InvoicePlus comparison list' 200 "$code"
	jq --argjson id "$INVOICE_ID" 'map(select((.id | tonumber) == $id)) | .[0] | del(.invoiceplus_warehouse_filter, .invoiceclosure)' "$TMPDIR_INVOICEPLUS/plus.json" > "$TMPDIR_INVOICEPLUS/selected.json"
	jq -S . "$TMPDIR_INVOICEPLUS/selected.json" > "$TMPDIR_INVOICEPLUS/selected.sorted.json"
	cmp "$TMPDIR_INVOICEPLUS/native.sorted.json" "$TMPDIR_INVOICEPLUS/selected.sorted.json"
	echo 'PASS  native-compatible invoice payload'
fi


# ---------------------------------------------------------------------------
# Price-level product routes.
# Set CUSTOMER_ID to also run the customer route. PRICE_LEVELS_ENABLED=0 checks
# the HTTP 409 returned when PRODUIT_MULTIPRICES is off.
# ---------------------------------------------------------------------------

PRODUCTS_WAREHOUSE_ENDPOINT="$ROOT_ENDPOINT/products/warehouse/$WAREHOUSE_ID"

if [ "${PRICE_LEVELS_ENABLED:-1}" = "0" ]; then
	code=$(request "$PRODUCTS_WAREHOUSE_ENDPOINT?limit=1" "$TMPDIR_INVOICEPLUS/levels-off.json")
	assert_code 'products by warehouse with multiprices disabled' 409 "$code"
else
	code=$(request "$PRODUCTS_WAREHOUSE_ENDPOINT?limit=5&on_missing_price=skip" "$TMPDIR_INVOICEPLUS/products-warehouse.json")
	assert_code 'products by warehouse' 200 "$code"
	jq -e 'type == "array" and length <= 5' "$TMPDIR_INVOICEPLUS/products-warehouse.json" >/dev/null
	jq -e 'all(.[];
		has("requested_price_level") and has("applied_price_level")
		and has("price_level_source") and has("price_fallback")
		and has("price") and has("price_ttc") and has("price_base_type") and has("tva_tx")
		and (.price_level_source | IN("customer", "warehouse", "default_level"))
		and ((.price_fallback | not) or (.applied_price_level | tonumber) == 1)
	)' "$TMPDIR_INVOICEPLUS/products-warehouse.json" >/dev/null
	echo 'PASS  warehouse price metadata and level-1 fallback contract'

	code=$(request "$PRODUCTS_WAREHOUSE_ENDPOINT?limit=2&pagination_data=true&on_missing_price=skip" "$TMPDIR_INVOICEPLUS/products-warehouse-page.json")
	assert_code 'products by warehouse pagination envelope' 200 "$code"
	jq -e '.data | type == "array"' "$TMPDIR_INVOICEPLUS/products-warehouse-page.json" >/dev/null
	jq -e '.pagination.total >= 0 and .pagination.page == 0 and .pagination.limit == 2' "$TMPDIR_INVOICEPLUS/products-warehouse-page.json" >/dev/null

	code=$(request "$PRODUCTS_WAREHOUSE_ENDPOINT?limit=2&on_missing_price=skip&properties=id,ref,price,applied_price_level" "$TMPDIR_INVOICEPLUS/products-warehouse-properties.json")
	assert_code 'properties filter applied after enrichment' 200 "$code"
	jq -e 'all(.[]; ((keys - ["id", "ref", "price", "applied_price_level"]) | length) == 0)' "$TMPDIR_INVOICEPLUS/products-warehouse-properties.json" >/dev/null

	code=$(request "$PRODUCTS_WAREHOUSE_ENDPOINT?sortfield=t.note_public" "$TMPDIR_INVOICEPLUS/bad-product-sort.json")
	assert_code 'non-whitelisted product sort field' 400 "$code"

	code=$(request "$PRODUCTS_WAREHOUSE_ENDPOINT?sqlfilters=%28t.unknown%3A%3D%3A%27x%27%29" "$TMPDIR_INVOICEPLUS/bad-product-filter.json")
	assert_code 'non-whitelisted product sqlfilters field' 400 "$code"

	code=$(request "$PRODUCTS_WAREHOUSE_ENDPOINT?on_missing_price=ignore" "$TMPDIR_INVOICEPLUS/bad-missing-price.json")
	assert_code 'invalid on_missing_price' 400 "$code"

	code=$(request "$ROOT_ENDPOINT/products/warehouse/0" "$TMPDIR_INVOICEPLUS/bad-product-warehouse.json")
	assert_code 'zero warehouse id on the product route' 400 "$code"

	code=$(request "$ROOT_ENDPOINT/products/warehouse/999999999" "$TMPDIR_INVOICEPLUS/missing-product-warehouse.json")
	assert_code 'unknown warehouse on the product route' 404 "$code"

	code=$(request "$ROOT_ENDPOINT/products/customer/0" "$TMPDIR_INVOICEPLUS/bad-product-customer.json")
	assert_code 'zero customer id on the product route' 400 "$code"

	code=$(request "$ROOT_ENDPOINT/products/customer/999999999" "$TMPDIR_INVOICEPLUS/missing-product-customer.json")
	assert_code 'unknown customer on the product route' 404 "$code"

	# The invoice route must keep returning invoices, not products.
	code=$(request "$ENDPOINT?limit=1" "$TMPDIR_INVOICEPLUS/still-invoices.json")
	assert_code 'existing warehouse invoice route unchanged' 200 "$code"
	jq -e 'all(.[]; has("requested_price_level") | not)' "$TMPDIR_INVOICEPLUS/still-invoices.json" >/dev/null
	echo 'PASS  GET /invoiceplus/warehouse/{id} still returns invoices'

	if [ -n "${CUSTOMER_ID:-}" ]; then
		code=$(request "$ROOT_ENDPOINT/products/customer/$CUSTOMER_ID?limit=5&on_missing_price=skip" "$TMPDIR_INVOICEPLUS/products-customer.json")
		assert_code 'products by customer' 200 "$code"
		jq -e 'all(.[]; .price_level_source | IN("customer", "default_level"))' "$TMPDIR_INVOICEPLUS/products-customer.json" >/dev/null
		echo 'PASS  customer route never uses a warehouse level'
	fi
fi

echo 'All InvoicePlus API checks passed.'

#!/usr/bin/env sh
# InvoicePlus REST API compatibility test. Requires curl and jq.
# Usage: BASE_URL=https://dolibarr.example API_KEY=key WAREHOUSE_ID=5 [INVOICE_ID=123] ./test_invoiceplus_api.sh

set -eu

: "${BASE_URL:?BASE_URL is required}"
: "${API_KEY:?API_KEY is required}"
: "${WAREHOUSE_ID:?WAREHOUSE_ID is required}"

BASE_URL=${BASE_URL%/}
ENDPOINT="$BASE_URL/api/index.php/invoiceplus/warehouse/$WAREHOUSE_ID"
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
	code=$(request "$ENDPOINT?limit=1000&loadlinkedobjects=true" "$TMPDIR_INVOICEPLUS/plus.json")
	assert_code 'InvoicePlus comparison list' 200 "$code"
	jq --argjson id "$INVOICE_ID" 'map(select((.id | tonumber) == $id)) | .[0] | del(.invoiceplus_warehouse_filter)' "$TMPDIR_INVOICEPLUS/plus.json" > "$TMPDIR_INVOICEPLUS/selected.json"
	jq -S . "$TMPDIR_INVOICEPLUS/native.json" > "$TMPDIR_INVOICEPLUS/native.sorted.json"
	jq -S . "$TMPDIR_INVOICEPLUS/selected.json" > "$TMPDIR_INVOICEPLUS/selected.sorted.json"
	cmp "$TMPDIR_INVOICEPLUS/native.sorted.json" "$TMPDIR_INVOICEPLUS/selected.sorted.json"
	echo 'PASS  exact native invoice payload'
fi

echo 'All InvoicePlus API checks passed.'

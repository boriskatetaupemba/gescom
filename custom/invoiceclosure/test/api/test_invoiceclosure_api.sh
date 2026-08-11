#!/bin/bash
# ============================================================================
# InvoiceClosure module - REST API test script (curl)
#
# Usage:
#   DOLAPIKEY=your_api_key BASE_URL=https://your-dolibarr INVOICE_ID=123 ./test_invoiceclosure_api.sh
#
# The invoice INVOICE_ID must be a customer invoice at the standard status
# "Paid". The script exercises: status read, close (idempotency included),
# history, reopen, and the list endpoint.
# ============================================================================

set -u

BASE_URL="${BASE_URL:-https://dev-admin.quinleysarlu.com}"
API="${BASE_URL}/api/index.php/invoiceclosureapi"
DOLAPIKEY="${DOLAPIKEY:?Set DOLAPIKEY environment variable}"
INVOICE_ID="${INVOICE_ID:?Set INVOICE_ID environment variable}"
STAMP=$(date +%Y%m%d%H%M%S)

pass=0
fail=0

check() {
	local label="$1" expected="$2" got="$3"
	if [ "$got" = "$expected" ]; then
		echo "PASS  ${label} (HTTP ${got})"
		pass=$((pass+1))
	else
		echo "FAIL  ${label} (expected HTTP ${expected}, got HTTP ${got})"
		fail=$((fail+1))
	fi
}

call() {
	# $1 = method, $2 = url, $3 = body (optional). Prints body, returns code in CURL_CODE
	local method="$1" url="$2" body="${3:-}"
	local response
	if [ -n "$body" ]; then
		response=$(curl -s -w '\n%{http_code}' -X "$method" \
			-H "DOLAPIKEY: ${DOLAPIKEY}" \
			-H "Content-Type: application/json" \
			-d "$body" "$url")
	else
		response=$(curl -s -w '\n%{http_code}' -X "$method" \
			-H "DOLAPIKEY: ${DOLAPIKEY}" "$url")
	fi
	CURL_CODE=$(echo "$response" | tail -n1)
	echo "$response" | sed '$d'
}

echo "== 1. Read closure status =="
call GET "${API}/invoices/${INVOICE_ID}"
check "GET status" 200 "$CURL_CODE"

echo
echo "== 2. Close the invoice (request_id CLOSE-${STAMP}) =="
call POST "${API}/invoices/${INVOICE_ID}/close" \
	"{\"note\": \"Clôture après vérification de la caisse\", \"request_id\": \"CLOSE-${STAMP}\"}"
check "POST close" 200 "$CURL_CODE"

echo
echo "== 3. Replay the SAME request_id (idempotency, expect already_closed=true) =="
call POST "${API}/invoices/${INVOICE_ID}/close" \
	"{\"note\": \"Replay\", \"request_id\": \"CLOSE-${STAMP}\"}"
check "POST close replay same request_id" 200 "$CURL_CODE"

echo
echo "== 4. Close again with ANOTHER request_id (expect 200 already_closed=true) =="
call POST "${API}/invoices/${INVOICE_ID}/close" \
	"{\"note\": \"Other request\", \"request_id\": \"CLOSE-${STAMP}-B\"}"
check "POST close other request_id" 200 "$CURL_CODE"

echo
echo "== 5. Forbidden server-side field is rejected (expect 400) =="
call POST "${API}/invoices/${INVOICE_ID}/close" \
	"{\"note\": \"x\", \"closure_status\": 1}"
check "POST close with forbidden field" 400 "$CURL_CODE"

echo
echo "== 6. Read history =="
call GET "${API}/invoices/${INVOICE_ID}/history"
check "GET history" 200 "$CURL_CODE"

echo
echo "== 7. Reopen the closure (request_id REOPEN-${STAMP}) =="
call POST "${API}/invoices/${INVOICE_ID}/reopen" \
	"{\"note\": \"Réouverture demandée pour correction\", \"request_id\": \"REOPEN-${STAMP}\"}"
check "POST reopen" 200 "$CURL_CODE"

echo
echo "== 8. Reopen when not closed (expect 409) =="
call POST "${API}/invoices/${INVOICE_ID}/reopen" \
	"{\"note\": \"again\", \"request_id\": \"REOPEN-${STAMP}-B\"}"
check "POST reopen not closed" 409 "$CURL_CODE"

echo
echo "== 9. Close again to restore the closed state =="
call POST "${API}/invoices/${INVOICE_ID}/close" \
	"{\"note\": \"Nouvelle clôture après test\", \"request_id\": \"CLOSE-${STAMP}-C\"}"
check "POST close again" 200 "$CURL_CODE"

echo
echo "== 10. List closed invoices =="
call GET "${API}/?status=1&limit=20"
check "GET list" 200 "$CURL_CODE"

echo
echo "== 11. Unknown invoice (expect 404) =="
call GET "${API}/invoices/99999999"
check "GET unknown invoice" 404 "$CURL_CODE"

echo
echo "===================================="
echo "Result: ${pass} passed, ${fail} failed"
exit $((fail > 0 ? 1 : 0))

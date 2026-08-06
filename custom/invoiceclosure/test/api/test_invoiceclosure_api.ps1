# ============================================================================
# InvoiceClosure module - REST API test script (PowerShell 5.1+)
#
# Usage:
#   .\test_invoiceclosure_api.ps1 -ApiKey "YOUR_KEY" -InvoiceId 123 [-BaseUrl "https://your-dolibarr"]
#
# The invoice must be a customer invoice at the standard status "Paid".
# ============================================================================

param(
	[Parameter(Mandatory = $true)][string]$ApiKey,
	[Parameter(Mandatory = $true)][int]$InvoiceId,
	[string]$BaseUrl = "https://dev-admin.quinleysarlu.com"
)

$Api = "$BaseUrl/api/index.php/invoiceclosureapi"
$Stamp = Get-Date -Format "yyyyMMddHHmmss"
$Headers = @{ DOLAPIKEY = $ApiKey; "Content-Type" = "application/json" }
$script:Pass = 0
$script:Fail = 0

function Invoke-Api {
	param([string]$Method, [string]$Url, [string]$Body = $null)
	try {
		$params = @{ Method = $Method; Uri = $Url; Headers = $Headers; UseBasicParsing = $true }
		if ($Body) { $params.Body = $Body }
		$resp = Invoke-WebRequest @params
		return @{ Code = [int]$resp.StatusCode; Body = $resp.Content }
	} catch {
		$code = 0
		if ($_.Exception.Response) { $code = [int]$_.Exception.Response.StatusCode.value__ }
		$content = ""
		if ($_.Exception.Response) {
			$reader = New-Object System.IO.StreamReader($_.Exception.Response.GetResponseStream())
			$content = $reader.ReadToEnd()
		}
		return @{ Code = $code; Body = $content }
	}
}

function Assert-Code {
	param([string]$Label, [int]$Expected, [hashtable]$Result)
	if ($Result.Code -eq $Expected) {
		Write-Host "PASS  $Label (HTTP $($Result.Code))" -ForegroundColor Green
		$script:Pass++
	} else {
		Write-Host "FAIL  $Label (expected HTTP $Expected, got HTTP $($Result.Code))" -ForegroundColor Red
		Write-Host $Result.Body
		$script:Fail++
	}
}

Write-Host "== 1. Read closure status =="
$r = Invoke-Api GET "$Api/invoices/$InvoiceId"
Assert-Code "GET status" 200 $r
Write-Host $r.Body

Write-Host "`n== 2. Close the invoice =="
$r = Invoke-Api POST "$Api/invoices/$InvoiceId/close" ('{"note": "Clôture après vérification de la caisse", "request_id": "CLOSE-' + $Stamp + '"}')
Assert-Code "POST close" 200 $r
Write-Host $r.Body

Write-Host "`n== 3. Replay the SAME request_id (idempotency) =="
$r = Invoke-Api POST "$Api/invoices/$InvoiceId/close" ('{"note": "Replay", "request_id": "CLOSE-' + $Stamp + '"}')
Assert-Code "POST close replay same request_id" 200 $r

Write-Host "`n== 4. Close again with ANOTHER request_id =="
$r = Invoke-Api POST "$Api/invoices/$InvoiceId/close" ('{"note": "Other", "request_id": "CLOSE-' + $Stamp + '-B"}')
Assert-Code "POST close other request_id" 200 $r

Write-Host "`n== 5. Forbidden server-side field (expect 400) =="
$r = Invoke-Api POST "$Api/invoices/$InvoiceId/close" '{"note": "x", "closure_status": 1}'
Assert-Code "POST close forbidden field" 400 $r

Write-Host "`n== 6. Read history =="
$r = Invoke-Api GET "$Api/invoices/$InvoiceId/history"
Assert-Code "GET history" 200 $r
Write-Host $r.Body

Write-Host "`n== 7. Reopen the closure =="
$r = Invoke-Api POST "$Api/invoices/$InvoiceId/reopen" ('{"note": "Réouverture demandée pour correction", "request_id": "REOPEN-' + $Stamp + '"}')
Assert-Code "POST reopen" 200 $r
Write-Host $r.Body

Write-Host "`n== 8. Reopen when not closed (expect 409) =="
$r = Invoke-Api POST "$Api/invoices/$InvoiceId/reopen" ('{"note": "again", "request_id": "REOPEN-' + $Stamp + '-B"}')
Assert-Code "POST reopen not closed" 409 $r

Write-Host "`n== 9. Close again to restore the closed state =="
$r = Invoke-Api POST "$Api/invoices/$InvoiceId/close" ('{"note": "Nouvelle clôture après test", "request_id": "CLOSE-' + $Stamp + '-C"}')
Assert-Code "POST close again" 200 $r

Write-Host "`n== 10. List closed invoices =="
$r = Invoke-Api GET "$Api/?status=1&limit=20"
Assert-Code "GET list" 200 $r

Write-Host "`n== 11. Unknown invoice (expect 404) =="
$r = Invoke-Api GET "$Api/invoices/99999999"
Assert-Code "GET unknown invoice" 404 $r

Write-Host "`n===================================="
Write-Host "Result: $script:Pass passed, $script:Fail failed"
if ($script:Fail -gt 0) { exit 1 } else { exit 0 }

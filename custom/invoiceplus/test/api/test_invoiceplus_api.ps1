# InvoicePlus REST API compatibility test (PowerShell 5.1+)
#
# Usage:
# .\test_invoiceplus_api.ps1 -BaseUrl "https://dolibarr.example" -ApiKey "KEY" -WarehouseId 5 [-InvoiceId 123]

param(
	[Parameter(Mandatory = $true)][string]$BaseUrl,
	[Parameter(Mandatory = $true)][string]$ApiKey,
	[Parameter(Mandatory = $true)][int]$WarehouseId,
	[int]$InvoiceId = 0
)

$BaseUrl = $BaseUrl.TrimEnd('/')
$Headers = @{ DOLAPIKEY = $ApiKey }
$script:Passed = 0
$script:Failed = 0

function Invoke-TestRequest {
	param([string]$Url)
	try {
		$response = Invoke-WebRequest -Method GET -Uri $Url -Headers $Headers -UseBasicParsing
		return @{ Code = [int]$response.StatusCode; Body = $response.Content }
	} catch {
		$code = 0
		$body = ''
		if ($_.Exception.Response) {
			$code = [int]$_.Exception.Response.StatusCode.value__
			$reader = New-Object System.IO.StreamReader($_.Exception.Response.GetResponseStream())
			$body = $reader.ReadToEnd()
		}
		return @{ Code = $code; Body = $body }
	}
}

function Assert-Status {
	param([string]$Label, [int]$Expected, [hashtable]$Result)
	if ($Result.Code -eq $Expected) {
		Write-Host "PASS  $Label" -ForegroundColor Green
		$script:Passed++
	} else {
		Write-Host "FAIL  $Label - expected HTTP $Expected, got $($Result.Code)" -ForegroundColor Red
		Write-Host $Result.Body
		$script:Failed++
	}
}

$Endpoint = "$BaseUrl/api/index.php/invoiceplus/warehouse/$WarehouseId"

$result = Invoke-TestRequest $Endpoint
Assert-Status 'valid warehouse list' 200 $result

$result = Invoke-TestRequest "$Endpoint`?pagination_data=true&limit=2&page=0"
Assert-Status 'pagination envelope' 200 $result
if ($result.Code -eq 200) {
	$payload = $result.Body | ConvertFrom-Json
	if ($null -ne $payload.data -and $null -ne $payload.pagination) {
		Write-Host 'PASS  pagination structure' -ForegroundColor Green
		$script:Passed++
	} else {
		Write-Host 'FAIL  pagination structure' -ForegroundColor Red
		$script:Failed++
	}
}

$result = Invoke-TestRequest "$Endpoint`?withLines=false"
Assert-Status 'withLines=false' 200 $result

$result = Invoke-TestRequest "$Endpoint`?warehouse_lines_only=true"
Assert-Status 'warehouse_lines_only=true' 200 $result
if ($result.Code -eq 200) {
	$payload = $result.Body | ConvertFrom-Json
	$badLines = @($payload | ForEach-Object { $_.lines } | Where-Object { $null -ne $_ -and [int]$_.fk_warehouse -ne $WarehouseId })
	if ($badLines.Count -eq 0) {
		Write-Host 'PASS  warehouse line ids' -ForegroundColor Green
		$script:Passed++
	} else {
		Write-Host 'FAIL  warehouse line ids' -ForegroundColor Red
		$script:Failed++
	}
}

$result = Invoke-TestRequest "$Endpoint`?date_start=2026-99-99"
Assert-Status 'invalid date' 400 $result

$result = Invoke-TestRequest "$BaseUrl/api/index.php/invoiceplus/warehouse/0"
Assert-Status 'zero warehouse id' 400 $result

$result = Invoke-TestRequest "$BaseUrl/api/index.php/invoiceplus/warehouse/999999999"
Assert-Status 'unknown warehouse' 404 $result

if ($InvoiceId -gt 0) {
	$native = Invoke-TestRequest "$BaseUrl/api/index.php/invoices/$InvoiceId"
	Assert-Status 'native invoice used for comparison' 200 $native
	$plus = Invoke-TestRequest "$Endpoint`?limit=1000&loadlinkedobjects=true"
	Assert-Status 'InvoicePlus comparison list' 200 $plus
	if ($native.Code -eq 200 -and $plus.Code -eq 200) {
		$nativeObject = $native.Body | ConvertFrom-Json
		$plusObject = @($plus.Body | ConvertFrom-Json | Where-Object { [int]$_.id -eq $InvoiceId })[0]
		if ($null -eq $plusObject) {
			Write-Host 'FAIL  comparison invoice absent from warehouse result' -ForegroundColor Red
			$script:Failed++
		} else {
			$plusObject.PSObject.Properties.Remove('invoiceplus_warehouse_filter')
			$nativeJson = $nativeObject | ConvertTo-Json -Depth 100 -Compress
			$plusJson = $plusObject | ConvertTo-Json -Depth 100 -Compress
			if ($nativeJson -eq $plusJson) {
				Write-Host 'PASS  exact native invoice payload' -ForegroundColor Green
				$script:Passed++
			} else {
				Write-Host 'FAIL  native and InvoicePlus payloads differ' -ForegroundColor Red
				$script:Failed++
			}
		}
	}
}

Write-Host "Result: $script:Passed passed, $script:Failed failed"
if ($script:Failed -gt 0) { exit 1 }
exit 0

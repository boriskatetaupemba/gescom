# InvoicePlus REST API compatibility test (PowerShell 5.1+)
#
# Usage:
# .\test_invoiceplus_api.ps1 -BaseUrl "https://dolibarr.example" -ApiKey "KEY" -WarehouseId 5 [-InvoiceId 123] [-AccountIds "8,9"]

param(
	[Parameter(Mandatory = $true)][string]$BaseUrl,
	[Parameter(Mandatory = $true)][string]$ApiKey,
	[Parameter(Mandatory = $true)][int]$WarehouseId,
	[int]$InvoiceId = 0,
	[string]$AccountIds = ''
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
			$response = $_.Exception.Response
			$code = [int]$response.StatusCode
			if ($_.ErrorDetails -and $_.ErrorDetails.Message) {
				$body = [string]$_.ErrorDetails.Message
			} elseif ($response.PSObject.Methods.Name -contains 'GetResponseStream') {
				$reader = New-Object System.IO.StreamReader($response.GetResponseStream())
				$body = $reader.ReadToEnd()
				$reader.Dispose()
			} elseif ($response.Content -and $response.Content.PSObject.Methods.Name -contains 'ReadAsStringAsync') {
				$body = $response.Content.ReadAsStringAsync().GetAwaiter().GetResult()
			}
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

$RootEndpoint = "$BaseUrl/api/index.php/invoiceplus"
$Endpoint = "$RootEndpoint/warehouse/$WarehouseId"

$result = Invoke-TestRequest "${RootEndpoint}?limit=2&page=0"
Assert-Status 'root invoice list' 200 $result
if ($result.Code -eq 200) {
	$payload = $result.Body | ConvertFrom-Json
	if ($result.Body.Trim().StartsWith('[') -and @($payload).Count -le 2) {
		Write-Host 'PASS  root list cap' -ForegroundColor Green
		$script:Passed++
	} else {
		Write-Host 'FAIL  root list cap' -ForegroundColor Red
		$script:Failed++
	}
}

if ($AccountIds -ne '') {
	$encodedAccountIds = [Uri]::EscapeDataString($AccountIds)
	$result = Invoke-TestRequest "${RootEndpoint}/byaccounts?account_ids=$encodedAccountIds&limit=2"
	Assert-Status 'invoice list by accounts' 200 $result
}

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
	$detail = Invoke-TestRequest "${RootEndpoint}/$InvoiceId"
	Assert-Status 'InvoicePlus invoice detail' 200 $detail
	if ($native.Code -eq 200 -and $detail.Code -eq 200) {
		$nativeObject = $native.Body | ConvertFrom-Json
		$detailObject = $detail.Body | ConvertFrom-Json
		$detailObject.PSObject.Properties.Remove('invoiceclosure')
		$nativeJson = $nativeObject | ConvertTo-Json -Depth 100 -Compress
		$detailJson = $detailObject | ConvertTo-Json -Depth 100 -Compress
		if ($nativeJson -eq $detailJson) {
			Write-Host 'PASS  detail native-compatible payload' -ForegroundColor Green
			$script:Passed++
		} else {
			Write-Host 'FAIL  detail payload differs from native API' -ForegroundColor Red
			$script:Failed++
		}
		if ($nativeObject.ref) {
			$encodedRef = [Uri]::EscapeDataString([string]$nativeObject.ref)
			$byRef = Invoke-TestRequest "${RootEndpoint}/ref/$encodedRef"
			Assert-Status 'invoice lookup by ref' 200 $byRef
		}
		if ($nativeObject.ref_ext) {
			$encodedRefExt = [Uri]::EscapeDataString([string]$nativeObject.ref_ext)
			$byRefExt = Invoke-TestRequest "${RootEndpoint}/ref_ext/$encodedRefExt"
			Assert-Status 'invoice lookup by external ref' 200 $byRefExt
		}
	}
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
			$plusObject.PSObject.Properties.Remove('invoiceclosure')
			$nativeJson = $nativeObject | ConvertTo-Json -Depth 100 -Compress
			$plusJson = $plusObject | ConvertTo-Json -Depth 100 -Compress
			if ($nativeJson -eq $plusJson) {
				Write-Host 'PASS  native-compatible invoice payload' -ForegroundColor Green
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

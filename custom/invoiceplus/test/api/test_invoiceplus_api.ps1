# InvoicePlus REST API compatibility test (PowerShell 5.1+)
#
# Usage:
# .\test_invoiceplus_api.ps1 -BaseUrl "https://dolibarr.example" -ApiKey "KEY" -WarehouseId 5 [-InvoiceId 123] [-AccountIds "8,9"] [-CustomerId 42] [-PriceLevelsEnabled $false]

param(
	[Parameter(Mandatory = $true)][string]$BaseUrl,
	[Parameter(Mandatory = $true)][string]$ApiKey,
	[Parameter(Mandatory = $true)][int]$WarehouseId,
	[int]$InvoiceId = 0,
	[string]$AccountIds = '',
	[int]$CustomerId = 0,
	[bool]$PriceLevelsEnabled = $true
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

$result = Invoke-TestRequest "${RootEndpoint}/thirdparties?limit=2&page=0&properties=id,name"
Assert-Status 'assigned third-party list' 200 $result
if ($result.Code -eq 200) {
	$payload = $result.Body | ConvertFrom-Json
	$invalidObject = @($payload | Where-Object {
		$propertyNames = @($_.PSObject.Properties.Name)
		$propertyNames.Count -gt 2 -or
		@($propertyNames | Where-Object { $_ -notin @('id', 'name') }).Count -gt 0
	})
	if ($result.Body.Trim().StartsWith('[') -and @($payload).Count -le 2 -and $invalidObject.Count -eq 0) {
		Write-Host 'PASS  assigned third-party scope and properties' -ForegroundColor Green
		$script:Passed++
	} else {
		Write-Host 'FAIL  assigned third-party scope and properties' -ForegroundColor Red
		$script:Failed++
	}
}

$result = Invoke-TestRequest "${RootEndpoint}/thirdparties?sortfield=t.unknown"
Assert-Status 'invalid third-party sort field' 400 $result

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


# ---------------------------------------------------------------------------
# Price-level product routes.
# ---------------------------------------------------------------------------

$ProductsWarehouseEndpoint = "$RootEndpoint/products/warehouse/$WarehouseId"

function Assert-True {
	param([string]$Label, [bool]$Condition)
	if ($Condition) {
		Write-Host "PASS  $Label" -ForegroundColor Green
		$script:Passed++
	} else {
		Write-Host "FAIL  $Label" -ForegroundColor Red
		$script:Failed++
	}
}

if (-not $PriceLevelsEnabled) {
	$result = Invoke-TestRequest "${ProductsWarehouseEndpoint}?limit=1"
	Assert-Status 'products by warehouse with multiprices disabled' 409 $result
} else {
	$result = Invoke-TestRequest "${ProductsWarehouseEndpoint}?limit=5&on_missing_price=skip"
	Assert-Status 'products by warehouse' 200 $result
	if ($result.Code -eq 200) {
		$products = @($result.Body | ConvertFrom-Json)
		$allowedSources = @('customer', 'warehouse', 'default_level')
		$contractOk = $true
		foreach ($product in $products) {
			$names = $product.PSObject.Properties.Name
			foreach ($required in @('requested_price_level', 'applied_price_level', 'price_level_source', 'price_fallback', 'price', 'price_ttc', 'price_base_type', 'tva_tx')) {
				if ($names -notcontains $required) { $contractOk = $false }
			}
			if ($allowedSources -notcontains $product.price_level_source) { $contractOk = $false }
			if ($product.price_fallback -and ([int]$product.applied_price_level -ne 1)) { $contractOk = $false }
		}
		Assert-True 'warehouse price metadata and level-1 fallback contract' $contractOk
	}

	$result = Invoke-TestRequest "${ProductsWarehouseEndpoint}?limit=2&pagination_data=true&on_missing_price=skip"
	Assert-Status 'products by warehouse pagination envelope' 200 $result
	if ($result.Code -eq 200) {
		$envelope = $result.Body | ConvertFrom-Json
		Assert-True 'pagination envelope shape' (($envelope.PSObject.Properties.Name -contains 'data') -and ([int]$envelope.pagination.limit -eq 2))
	}

	$result = Invoke-TestRequest "${ProductsWarehouseEndpoint}?limit=2&on_missing_price=skip&properties=id,ref,price,applied_price_level"
	Assert-Status 'properties filter applied after enrichment' 200 $result
	if ($result.Code -eq 200) {
		$filtered = @($result.Body | ConvertFrom-Json)
		$onlyRequested = $true
		foreach ($product in $filtered) {
			$extra = @($product.PSObject.Properties.Name | Where-Object { @('id', 'ref', 'price', 'applied_price_level') -notcontains $_ })
			if ($extra.Count -gt 0) { $onlyRequested = $false }
		}
		Assert-True 'only the requested properties are returned' $onlyRequested
	}

	$result = Invoke-TestRequest "${ProductsWarehouseEndpoint}?sortfield=t.note_public"
	Assert-Status 'non-whitelisted product sort field' 400 $result

	$result = Invoke-TestRequest "${ProductsWarehouseEndpoint}?sqlfilters=%28t.unknown%3A%3D%3A%27x%27%29"
	Assert-Status 'non-whitelisted product sqlfilters field' 400 $result

	$result = Invoke-TestRequest "${ProductsWarehouseEndpoint}?on_missing_price=ignore"
	Assert-Status 'invalid on_missing_price' 400 $result

	$result = Invoke-TestRequest "$RootEndpoint/products/warehouse/0"
	Assert-Status 'zero warehouse id on the product route' 400 $result

	$result = Invoke-TestRequest "$RootEndpoint/products/warehouse/999999999"
	Assert-Status 'unknown warehouse on the product route' 404 $result

	$result = Invoke-TestRequest "$RootEndpoint/products/customer/0"
	Assert-Status 'zero customer id on the product route' 400 $result

	$result = Invoke-TestRequest "$RootEndpoint/products/customer/999999999"
	Assert-Status 'unknown customer on the product route' 404 $result

	$result = Invoke-TestRequest "${Endpoint}?limit=1"
	Assert-Status 'existing warehouse invoice route unchanged' 200 $result
	if ($result.Code -eq 200) {
		$invoices = @($result.Body | ConvertFrom-Json)
		$stillInvoices = $true
		foreach ($invoice in $invoices) {
			if ($invoice.PSObject.Properties.Name -contains 'requested_price_level') { $stillInvoices = $false }
		}
		Assert-True 'GET /invoiceplus/warehouse/{id} still returns invoices' $stillInvoices
	}

	if ($CustomerId -gt 0) {
		$result = Invoke-TestRequest "$RootEndpoint/products/customer/${CustomerId}?limit=5&on_missing_price=skip"
		Assert-Status 'products by customer' 200 $result
		if ($result.Code -eq 200) {
			$customerProducts = @($result.Body | ConvertFrom-Json)
			$noWarehouseSource = $true
			foreach ($product in $customerProducts) {
				if (@('customer', 'default_level') -notcontains $product.price_level_source) { $noWarehouseSource = $false }
			}
			Assert-True 'customer route never uses a warehouse level' $noWarehouseSource
		}
	}
}

Write-Host "Result: $script:Passed passed, $script:Failed failed"
if ($script:Failed -gt 0) { exit 1 }
exit 0

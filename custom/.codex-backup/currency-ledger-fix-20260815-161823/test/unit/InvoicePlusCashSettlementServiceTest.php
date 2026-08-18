<?php
/* Copyright (C) 2026 InvoicePlus module */

/**
 * Deterministic monetary tests for the mixed-currency cash settlement.
 *
 * Run from htdocs/custom/invoiceplus with:
 * phpunit test/unit/InvoicePlusCashSettlementServiceTest.php
 */

global $db, $user;

require_once dirname(__FILE__).'/../../../../master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/api/class/api_access.class.php';
require_once DOL_DOCUMENT_ROOT.'/api/class/api.class.php';
dol_include_once('/invoiceplus/class/invoiceplusinvoiceservice.class.php');
dol_include_once('/invoiceplus/class/invoicepluscashsettlementservice.class.php');

use PHPUnit\Framework\TestCase;
use Luracast\Restler\RestException;

/**
 * Tests that never mutate the database.
 */
class InvoicePlusCashSettlementServiceTest extends TestCase
{
	/** @var InvoicePlusCashSettlementService */
	private $service;

	/** @return void */
	protected function setUp(): void
	{
		global $db, $user;
		$this->service = new InvoicePlusCashSettlementService($db, $user);
	}

	/** @return void */
	public function testRequestNormalizationUsesIntegerCents()
	{
		$request = $this->validRequest();
		$result = $this->service->normalizeRequest(42, $request);

		$this->assertSame(28500000, $result['total_cdf_cents']);
		$this->assertSame(14250000, $result['received_cdf_cents']);
		$this->assertSame(5000, $result['received_usd_cents']);
		$this->assertSame(0, $result['change_cdf_cents']);
		$this->assertSame(0, $result['change_usd_cents']);
		$this->assertSame('2850.00000000', $result['exchange_rate_decimal']);
	}

	/** @return void */
	public function testAmountsWithMoreThanTwoDecimalsAreRejected()
	{
		$request = $this->validRequest();
		$request['received']['usd'] = '50.001';

		$this->expectException(RestException::class);
		$this->service->normalizeRequest(42, $request);
	}

	/** @return void */
	public function testCashOnlyRequestNormalizesWithoutUsdAccount()
	{
		$request = $this->validRequest();
		$request['received'] = array('cdf' => '285000.00', 'usd' => '0.00');
		$request['accounts']['usd'] = null;

		$result = $this->service->normalizeRequest(42, $request);

		$this->assertSame(28500000, $result['received_cdf_cents']);
		$this->assertSame(0, $result['received_usd_cents']);
		$this->assertSame(0, $result['accounts']['usd']);
	}

	/** @return void */
	public function testMixedTenderNeedsNoTransferWhenBothNetsMatchPayments()
	{
		$result = $this->service->allocatePayments(28500000, 10000, 2850.0, 14250000, 5000);

		$this->assertSame(14250000, $result['payment_cdf_cents']);
		$this->assertSame(5000, $result['payment_usd_cents']);
		$this->assertNull($result['transfer']);
	}

	/** @return void */
	public function testUsdOverTenderAndCdfChangeCreateCdfToUsdTransfer()
	{
		// $200 received, CDF 285,000 returned, invoice total USD 100.
		$result = $this->service->allocatePayments(28500000, 10000, 2850.0, -28500000, 20000);

		$this->assertSame(0, $result['payment_cdf_cents']);
		$this->assertSame(10000, $result['payment_usd_cents']);
		$this->assertSame('CDF', $result['transfer']['from']);
		$this->assertSame('USD', $result['transfer']['to']);
		$this->assertSame(28500000, $result['transfer']['from_cents']);
		$this->assertSame(10000, $result['transfer']['to_cents']);
	}

	/** @return void */
	public function testCdfOverTenderAndUsdChangeCreateUsdToCdfTransfer()
	{
		// CDF 570,000 received, USD 100 returned, invoice total CDF 285,000.
		$result = $this->service->allocatePayments(28500000, 10000, 2850.0, 57000000, -10000);

		$this->assertSame(28500000, $result['payment_cdf_cents']);
		$this->assertSame(0, $result['payment_usd_cents']);
		$this->assertSame('USD', $result['transfer']['from']);
		$this->assertSame('CDF', $result['transfer']['to']);
		$this->assertSame(10000, $result['transfer']['from_cents']);
		$this->assertSame(28500000, $result['transfer']['to_cents']);
	}

	/** @return void */
	public function testWarehouseProofAcceptsBatchSplitsAndTargetWarehouseComponents()
	{
		$requirements = array(
			10 => array('quantity_units' => 500000000, 'line_count' => 1),
		);
		$movements = array(
			array('product_id' => 10, 'warehouse_id' => 3, 'quantity_units' => -200000000, 'type' => 2),
			array('product_id' => 10, 'warehouse_id' => 3, 'quantity_units' => -300000000, 'type' => 2),
			// A kit component is legitimate evidence but not an invoice requirement.
			array('product_id' => 11, 'warehouse_id' => 3, 'quantity_units' => -1000000000, 'type' => 2),
		);

		$this->invokeWarehouseProof($requirements, $movements, 3);
		$this->addToAssertionCount(1);
	}

	/** @return void */
	public function testWarehouseProofAcceptsFullyReversedHistoryBeforeRevalidation()
	{
		$requirements = array(
			10 => array('quantity_units' => 500000000, 'line_count' => 1),
		);
		$movements = array(
			array('product_id' => 10, 'warehouse_id' => 8, 'quantity_units' => -500000000, 'type' => 2),
			array('product_id' => 10, 'warehouse_id' => 8, 'quantity_units' => 500000000, 'type' => 3),
			array('product_id' => 10, 'warehouse_id' => 3, 'quantity_units' => -500000000, 'type' => 2),
		);

		$this->invokeWarehouseProof($requirements, $movements, 3);
		$this->addToAssertionCount(1);
	}

	/** @return void */
	public function testWarehouseProofRejectsRemainingMovementInAnotherWarehouse()
	{
		$requirements = array(
			10 => array('quantity_units' => 500000000, 'line_count' => 1),
		);
		$movements = array(
			array('product_id' => 10, 'warehouse_id' => 8, 'quantity_units' => -500000000, 'type' => 2),
		);

		$this->expectException(RestException::class);
		$this->invokeWarehouseProof($requirements, $movements, 3);
	}

	/** @return void */
	public function testWarehouseProofRejectsIncompleteStockExit()
	{
		$requirements = array(
			10 => array('quantity_units' => 500000000, 'line_count' => 1),
		);
		$movements = array(
			array('product_id' => 10, 'warehouse_id' => 3, 'quantity_units' => -499999999, 'type' => 2),
		);

		$this->expectException(RestException::class);
		$this->invokeWarehouseProof($requirements, $movements, 3);
	}

	/** @return void */
	public function testWarehouseProofRejectsMissingNativeMovement()
	{
		$requirements = array(
			10 => array('quantity_units' => 500000000, 'line_count' => 1),
		);

		$this->expectException(RestException::class);
		$this->invokeWarehouseProof($requirements, array(), 3);
	}

	/** Invoke the private pure proof checker without touching the database. */
	private function invokeWarehouseProof(array $requirements, array $movements, $warehouseId)
	{
		$method = new ReflectionMethod(InvoicePlusCashSettlementService::class, 'assertWarehouseMovementProof');
		$method->setAccessible(true);
		$method->invoke($this->service, $requirements, $movements, $warehouseId);
	}

	/** @return array */
	private function validRequest()
	{
		return array(
			'operation_id' => 'cash-20260812-0001',
			'date' => 1786492800,
			'payment_method_id' => 4,
			'exchange_rate' => '2850',
			'total_cdf' => '285000.00',
			'received' => array('cdf' => '142500.00', 'usd' => '50.00'),
			'change' => array('cdf' => '0.00', 'usd' => '0.00'),
			'accounts' => array('cdf' => 8, 'usd' => 9),
		);
	}
}

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
	public function testMixedTenderAllocatesBothInvoiceCurrencies()
	{
		$result = $this->service->allocatePayments(28500000, 10000, 2850.0, 14250000, 5000);

		$this->assertSame(14250000, $result['payment_cdf_cents']);
		$this->assertSame(5000, $result['payment_usd_cents']);
		$this->assertArrayNotHasKey('transfer', $result);
	}

	/** @return void */
	public function testExactInvoice248AllocatesBothInvoiceCurrencies()
	{
		$result = $this->service->allocatePayments(42750000, 15000, 2850.0, 14250000, 10000);

		$this->assertSame(14250000, $result['payment_cdf_cents']);
		$this->assertSame(10000, $result['payment_usd_cents']);
		$this->assertArrayNotHasKey('transfer', $result);
	}

	/** @return void */
	public function testUsdReceiptAndCdfChangeAllocateInvoiceOnlyToUsd()
	{
		// $200 received, CDF 285,000 returned, invoice total USD 100.
		$result = $this->service->allocatePayments(28500000, 10000, 2850.0, -28500000, 20000);

		$this->assertSame(0, $result['payment_cdf_cents']);
		$this->assertSame(10000, $result['payment_usd_cents']);
		$this->assertArrayNotHasKey('transfer', $result);
	}

	/** @return void */
	public function testCdfReceiptAndUsdChangeAllocateInvoiceOnlyToCdf()
	{
		// CDF 570,000 received, USD 100 returned, invoice total CDF 285,000.
		$result = $this->service->allocatePayments(28500000, 10000, 2850.0, 57000000, -10000);

		$this->assertSame(28500000, $result['payment_cdf_cents']);
		$this->assertSame(0, $result['payment_usd_cents']);
		$this->assertArrayNotHasKey('transfer', $result);
	}

	/** @return void */
	public function testCurrentTenderIsAvailableDespiteNegativeHistoricalBalance()
	{
		$this->assertSame(20000000, $this->invokeAvailableChange(-23056900, 20000000));
		$this->assertSame(25000000, $this->invokeAvailableChange(5000000, 20000000));
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

	/** @return void */
	public function testCdfPaymentLedgerProofKeepsPhysicalAndBaseAmountsDistinct()
	{
		$this->invokePaymentLedgerProof($this->validPaymentLedgerProof('CDF'), 'CDF', 14250000);
		$this->addToAssertionCount(1);
	}

	/** @return void */
	public function testUsdPaymentLedgerProofUsesBaseAccountAmount()
	{
		$this->invokePaymentLedgerProof($this->validPaymentLedgerProof('USD'), 'USD', 10000);
		$this->addToAssertionCount(1);
	}

	/** @return void */
	public function testCdfPaymentLedgerProofRejectsBaseAmountPostedAsPhysicalCash()
	{
		$proof = $this->validPaymentLedgerProof('CDF');
		$proof['bank_amount_cents'] = 5000;

		$this->expectException(RestException::class);
		$this->invokePaymentLedgerProof($proof, 'CDF', 14250000);
	}

	/** @return void */
	public function testPaymentLedgerProofRejectsInvoiceAllocationMismatch()
	{
		$proof = $this->validPaymentLedgerProof('USD');
		$proof['link_foreign_cents']--;

		$this->expectException(RestException::class);
		$this->invokePaymentLedgerProof($proof, 'USD', 10000);
	}

	/** @return void */
	public function testUsdPhysicalProofRecordsGrossReceiptAndGrossChange()
	{
		$invoice = $this->fakeInvoice();
		$proof = array(
			'account_id' => 8,
			'account_currency' => 'USD',
			'received_bank_line_id' => 80,
			'change_bank_line_id' => 81,
			'received_amount_cents' => 15000,
			'change_amount_cents' => -3000,
			'received_main_cents' => null,
			'change_main_cents' => null,
			'received_label' => 'Paiement reçu 150,00 USD - Facture IN2608-0187 - Client CLIENT PDV KOLWEZI',
			'change_label' => 'Monnaie rendue 30,00 USD - Facture IN2608-0187 - Client CLIENT PDV KOLWEZI',
		);

		$this->invokePhysicalCashMovementProof($proof, 'USD', 15000, 3000, $invoice);
		$this->addToAssertionCount(1);
	}

	/** @return void */
	public function testCdfPhysicalProofRecordsGrossReceiptAndGrossChange()
	{
		$invoice = $this->fakeInvoice();
		$proof = array(
			'account_id' => 9,
			'account_currency' => 'CDF',
			'received_bank_line_id' => 80,
			'change_bank_line_id' => 81,
			'received_amount_cents' => 34250000,
			'change_amount_cents' => -50000,
			'received_main_cents' => 12018,
			'change_main_cents' => -18,
			'received_label' => 'Paiement reçu 342 500,00 CDF - Facture IN2608-0187 - Client CLIENT PDV KOLWEZI',
			'change_label' => 'Monnaie rendue 500,00 CDF - Facture IN2608-0187 - Client CLIENT PDV KOLWEZI',
		);

		$this->invokePhysicalCashMovementProof($proof, 'CDF', 34250000, 50000, $invoice);
		$this->addToAssertionCount(1);
	}

	/** @return void */
	public function testPhysicalProofRejectsNetReceiptInsteadOfGrossReceipt()
	{
		$proof = array(
			'account_id' => 8,
			'account_currency' => 'USD',
			'received_bank_line_id' => 80,
			'change_bank_line_id' => 81,
			'received_amount_cents' => 12000,
			'change_amount_cents' => -3000,
			'received_main_cents' => null,
			'change_main_cents' => null,
			'received_label' => 'Paiement reçu 150,00 USD - Facture IN2608-0187 - Client CLIENT PDV KOLWEZI',
			'change_label' => 'Monnaie rendue 30,00 USD - Facture IN2608-0187 - Client CLIENT PDV KOLWEZI',
		);

		$this->expectException(RestException::class);
		$this->invokePhysicalCashMovementProof($proof, 'USD', 15000, 3000, $this->fakeInvoice());
	}

	/** @return void */
	public function testPhysicalCoverageMatchesRequestExactly()
	{
		$normalized = array(
			'received_cdf_cents' => 20000000,
			'change_cdf_cents' => 50000,
			'received_usd_cents' => 15000,
			'change_usd_cents' => 3000,
		);
		$cashMovements = array(
			'cdf' => array(
				'received_amount' => '200000.00',
				'change_amount' => '500.00',
				'received_bank_line_id' => 70,
				'change_bank_line_id' => 71,
			),
			'usd' => array(
				'received_amount' => '150.00',
				'change_amount' => '30.00',
				'received_bank_line_id' => 72,
				'change_bank_line_id' => 73,
			),
		);

		$this->invokePhysicalCashMovementCoverage($normalized, $cashMovements);
		$this->addToAssertionCount(1);
	}

	/** Invoke the private available-change calculation without database access. */
	private function invokeAvailableChange($balanceCents, $receivedCents)
	{
		$method = new ReflectionMethod(InvoicePlusCashSettlementService::class, 'getAvailableChangeCents');
		$method->setAccessible(true);
		return $method->invoke($this->service, $balanceCents, $receivedCents);
	}

	/** Invoke the private pure proof checker without touching the database. */
	private function invokeWarehouseProof(array $requirements, array $movements, $warehouseId)
	{
		$method = new ReflectionMethod(InvoicePlusCashSettlementService::class, 'assertWarehouseMovementProof');
		$method->setAccessible(true);
		$method->invoke($this->service, $requirements, $movements, $warehouseId);
	}

	/** Invoke the private pure native-ledger checker without database writes. */
	private function invokePaymentLedgerProof(array $proof, $currency, $amountCents)
	{
		$method = new ReflectionMethod(InvoicePlusCashSettlementService::class, 'assertPaymentLedgerProof');
		$method->setAccessible(true);
		$method->invoke($this->service, $proof, 27, 76, $currency === 'CDF' ? 9 : 8, $currency, $amountCents, 2850.0);
	}

	/** Invoke the private exact physical-cash proof without database writes. */
	private function invokePhysicalCashMovementProof(array $proof, $currency, $receivedCents, $changeCents, $invoice)
	{
		$method = new ReflectionMethod(InvoicePlusCashSettlementService::class, 'assertPhysicalCashMovementProof');
		$method->setAccessible(true);
		$method->invoke($this->service, $proof, $currency === 'CDF' ? 9 : 8, $currency, $receivedCents, $changeCents, 80, 81, 2850.0, $invoice);
	}

	/** Invoke the private response-to-request movement coverage proof. */
	private function invokePhysicalCashMovementCoverage(array $normalized, array $cashMovements)
	{
		$method = new ReflectionMethod(InvoicePlusCashSettlementService::class, 'assertPhysicalCashMovementCoverage');
		$method->setAccessible(true);
		$method->invoke($this->service, $normalized, $cashMovements);
	}

	/** Minimal already-loaded invoice object for pure description/proof tests. */
	private function fakeInvoice()
	{
		$invoice = new stdClass();
		$invoice->id = 187;
		$invoice->socid = 21;
		$invoice->ref = 'IN2608-0187';
		$invoice->thirdparty = (object) array('id' => 21, 'name' => 'CLIENT PDV KOLWEZI');
		return $invoice;
	}

	/** Native values expected for one half-CDF / two-thirds-USD mixed tender. */
	private function validPaymentLedgerProof($currency)
	{
		if ($currency === 'CDF') {
			return array(
				'payment_id' => 27,
				'bank_line_id' => 76,
				'account_id' => 9,
				'account_currency' => 'CDF',
				'payment_base_cents' => 5000,
				'payment_foreign_cents' => 14250000,
				'link_base_cents' => 5000,
				'link_foreign_cents' => 14250000,
				'link_currency' => 'CDF',
				'link_rate' => 2850.0,
				'bank_amount_cents' => 14250000,
				'bank_main_cents' => 5000,
			);
		}
		return array(
			'payment_id' => 27,
			'bank_line_id' => 76,
			'account_id' => 8,
			'account_currency' => 'USD',
			'payment_base_cents' => 10000,
			'payment_foreign_cents' => 28500000,
			'link_base_cents' => 10000,
			'link_foreign_cents' => 28500000,
			'link_currency' => 'CDF',
			'link_rate' => 2850.0,
			'bank_amount_cents' => 10000,
			'bank_main_cents' => null,
		);
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

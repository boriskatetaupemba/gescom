<?php
/* Copyright (C) 2026 InvoicePlus module */

/**
 * Deterministic tests for the transaction trigger monetary invariant.
 * These tests do not mutate the database.
 */

global $db;

require_once dirname(__FILE__).'/../../../../master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/custom/invoiceplus/core/triggers/interface_99_modInvoicePlus_InvoicePlusTriggers.class.php';

use PHPUnit\Framework\TestCase;

/**
 * Tests for InvoicePlus customer-payment triggers.
 */
class InvoicePlusTriggersTest extends TestCase
{
	/** @var InterfaceInvoicePlusTriggers */
	private $trigger;

	/** @return void */
	protected function setUp(): void
	{
		global $db;
		$this->trigger = new InterfaceInvoicePlusTriggers($db);
	}

	/** @return void */
	public function testExactBaseAndForeignTotalsAreAccepted()
	{
		$this->assertFalse($this->hasOverpayment(10000, 28500000, 10000, 28500000));
	}

	/** @return void */
	public function testOneCentBaseOverpaymentIsRejected()
	{
		$this->assertTrue($this->hasOverpayment(10000, 28500000, 10001, 28500000));
	}

	/** @return void */
	public function testOneCentForeignOverpaymentIsRejected()
	{
		$this->assertTrue($this->hasOverpayment(10000, 28500000, 10000, 28500001));
	}

	/** @return void */
	public function testDecimalConversionUsesHalfUpIntegerCents()
	{
		$method = new ReflectionMethod(InterfaceInvoicePlusTriggers::class, 'decimalToCents');
		$method->setAccessible(true);

		$this->assertSame(28500001, $method->invoke($this->trigger, '285000.005'));
		$this->assertSame(5000, $method->invoke($this->trigger, '50.004'));
	}

	/**
	 * @param int $totalBase       Base total cents
	 * @param int $totalForeign    Foreign total cents
	 * @param int $appliedBase     Applied base cents
	 * @param int $appliedForeign  Applied foreign cents
	 * @return bool
	 */
	private function hasOverpayment($totalBase, $totalForeign, $appliedBase, $appliedForeign)
	{
		$method = new ReflectionMethod(InterfaceInvoicePlusTriggers::class, 'hasOverpayment');
		$method->setAccessible(true);
		return (bool) $method->invoke(
			$this->trigger,
			$totalBase,
			$totalForeign,
			array('base_cents' => $appliedBase, 'foreign_cents' => $appliedForeign)
		);
	}
}

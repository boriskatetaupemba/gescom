<?php
/* Copyright (C) 2026 InvoicePlus module */

/**
 * Deterministic rendering tests for the native invoice payment-table hook.
 *
 * Run from htdocs/custom/invoiceplus with:
 * phpunit test/unit/ActionsInvoiceplusTest.php
 */

global $db;

require_once dirname(__FILE__).'/../../../../master.inc.php';
dol_include_once('/invoiceplus/class/actions_invoiceplus.class.php');

use PHPUnit\Framework\TestCase;

/** Tests that never query or mutate the database. */
class ActionsInvoiceplusTest extends TestCase
{
	/** @var ActionsInvoiceplus */
	private $actions;

	/** @return void */
	protected function setUp(): void
	{
		global $db;
		$this->actions = new ActionsInvoiceplus($db);
	}

	/** @return void */
	public function testEmptyPaymentMapProducesNoMarkup()
	{
		$this->assertSame('', $this->render(array(), 'Physical amount'));
	}

	/** @return void */
	public function testPayloadTargetsNativeTableByPaymentIdAndIsScriptSafe()
	{
		$script = $this->render(
			array(
				27 => '142 500,00 CDF',
				28 => '</script><script>alert(1)</script>',
			),
			'Physical </script> amount'
		);

		$this->assertSame(1, substr_count($script, '<script'));
		$this->assertSame(1, substr_count($script, '</script>'));
		$this->assertSame(1, preg_match('/^<script nonce="[^"]+" type="text\/javascript">/', $script));
		$this->assertStringNotContainsString('<table', $script);
		$this->assertStringContainsString('"27":"142 500,00 CDF"', $script);
		$this->assertStringContainsString('\\u003C\\/script\\u003E', $script);
		$this->assertStringContainsString("querySelectorAll('table.paymenttable')", $script);
		$this->assertStringContainsString("data-invoiceplus-physical-enriched", $script);
		$this->assertStringContainsString('paymentIdFromRow', $script);
		$this->assertStringContainsString("amount.textContent = '\\u2014'", $script);
		$this->assertStringContainsString('summaryRows[k].cells[0].colSpan = originalColumnCount - 1', $script);
		$this->assertStringContainsString('headerRow.insertBefore(header, headerRow.cells[originalColumnCount - 2])', $script);
	}

	/** @return void */
	public function testInvalidPaymentIdsAreDroppedBeforeEncoding()
	{
		$this->assertSame('', $this->render(array(0 => '0 CDF', -2 => '1 USD'), 'Physical amount'));
	}

	/** @return void */
	public function testInvalidUtf8IsSubstitutedInsteadOfDroppingTheEnrichment()
	{
		$script = $this->render(array(27 => "\xB1\x31"), 'Physical amount');

		$this->assertNotSame('', $script);
		$this->assertSame(1, substr_count($script, '</script>'));
	}

	/** Invoke the private pure renderer without database access. */
	private function render(array $amounts, $label)
	{
		$method = new ReflectionMethod(ActionsInvoiceplus::class, 'buildPhysicalAmountEnrichmentScript');
		$method->setAccessible(true);
		return $method->invoke($this->actions, $amounts, $label);
	}
}

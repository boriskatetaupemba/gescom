<?php
/* Copyright (C) 2026 InvoicePlus module
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    custom/invoiceplus/test/unit/InvoicePlusPriceLevelFormsTest.php
 * \ingroup invoiceplus
 * \brief   Tests of the product/warehouse card validations and of the
 *          PRODUCT_CREATE price-level trigger.
 *
 * Run from htdocs/custom/invoiceplus with:
 * phpunit test/unit/InvoicePlusPriceLevelFormsTest.php
 */

global $db, $user, $langs, $conf;

require_once dirname(__FILE__).'/../../../../master.inc.php';
dol_include_once('/invoiceplus/class/actions_invoiceplus.class.php');
require_once dirname(__FILE__).'/../../core/triggers/interface_98_modInvoicePlus_InvoicePlusProductPrices.class.php';

use PHPUnit\Framework\TestCase;

/**
 * Product object double recording every native price write.
 */
class InvoicePlusProductPriceDouble
{
	/** @var string */
	public $element = 'product';

	/** @var int */
	public $id = 1;

	/** @var array */
	public $context = array();

	/** @var int */
	public $tva_npr = 0;

	/** @var string */
	public $default_vat_code = '';

	/** @var string */
	public $error = '';

	/** @var array */
	public $errors = array();

	/** @var array Recorded updatePrice() calls */
	public $calls = array();

	/** @var int Value returned by updatePrice() */
	public $updatePriceResult = 1;

	/**
	 * Record a native price update.
	 *
	 * @param mixed $newprice          New price
	 * @param string $newpricebase     HT or TTC
	 * @param mixed $user              Acting user
	 * @param mixed $newvat            New VAT rate
	 * @param mixed $newminprice       New minimum price
	 * @param int   $level             Price level
	 * @param int   $newnpr            NPR flag
	 * @param int   $newpbq            Price by quantity
	 * @param int   $ignore_autogen    Autogeneration flag
	 * @param array $localtaxes_array  Local taxes
	 * @param string $newdefaultvatcode Default VAT code
	 * @param string $price_label      Price label
	 * @param int   $notrigger         Trigger flag
	 * @return int                      Configured result
	 */
	public function updatePrice($newprice, $newpricebase, $user, $newvat = null, $newminprice = 0, $level = 0, $newnpr = 0, $newpbq = 0, $ignore_autogen = 0, $localtaxes_array = array(), $newdefaultvatcode = '', $price_label = '', $notrigger = 0)
	{
		$this->calls[] = array(
			'price' => $newprice,
			'base' => $newpricebase,
			'vat' => $newvat,
			'min' => $newminprice,
			'level' => $level,
			'npr' => $newnpr,
			'default_vat_code' => $newdefaultvatcode,
			'notrigger' => $notrigger,
		);

		return $this->updatePriceResult;
	}
}

/**
 * Deterministic tests that never touch the database.
 */
class InvoicePlusPriceLevelFormsTest extends TestCase
{
	/** @var array Saved globals */
	private $saved = array();

	/** @return void */
	protected function setUp(): void
	{
		global $conf;

		$this->saved = array(
			'enabled' => isset($conf->global->PRODUIT_MULTIPRICES) ? $conf->global->PRODUIT_MULTIPRICES : null,
			'limit' => isset($conf->global->PRODUIT_MULTIPRICES_LIMIT) ? $conf->global->PRODUIT_MULTIPRICES_LIMIT : null,
			'base' => isset($conf->global->PRODUCT_PRICE_BASE_TYPE) ? $conf->global->PRODUCT_PRICE_BASE_TYPE : null,
			'module' => isset($conf->modules['invoiceplus']) ? $conf->modules['invoiceplus'] : null,
		);
		$conf->global->PRODUIT_MULTIPRICES = '1';
		$conf->global->PRODUIT_MULTIPRICES_LIMIT = 4;
		$conf->global->PRODUCT_PRICE_BASE_TYPE = 'HT';
		$conf->modules['invoiceplus'] = 'invoiceplus';
	}

	/** @return void */
	protected function tearDown(): void
	{
		global $conf;

		$map = array(
			'PRODUIT_MULTIPRICES' => 'enabled',
			'PRODUIT_MULTIPRICES_LIMIT' => 'limit',
			'PRODUCT_PRICE_BASE_TYPE' => 'base',
		);
		foreach ($map as $key => $saved) {
			if ($this->saved[$saved] === null) {
				unset($conf->global->$key);
			} else {
				$conf->global->$key = $this->saved[$saved];
			}
		}
		if ($this->saved['module'] === null) {
			unset($conf->modules['invoiceplus']);
		} else {
			$conf->modules['invoiceplus'] = $this->saved['module'];
		}
	}

	/** @return void */
	public function testNativeFieldNamesAreReused()
	{
		$this->assertSame('price', ActionsInvoiceplus::getPriceFieldName(1));
		$this->assertSame('price_base_type', ActionsInvoiceplus::getPriceBaseTypeFieldName(1));
		$this->assertSame('price_3', ActionsInvoiceplus::getPriceFieldName(3));
		$this->assertSame('multiprices_base_type_3', ActionsInvoiceplus::getPriceBaseTypeFieldName(3));
	}

	/**
	 * @param mixed $raw      Submitted value
	 * @param bool  $provided Field was filled in
	 * @param bool  $valid    Value is a usable amount
	 * @param mixed $value    Normalized amount
	 * @return void
	 * @dataProvider monetaryProvider
	 */
	public function testMonetaryInputSeparatesAbsenceZeroAndGarbage($raw, $provided, $valid, $value)
	{
		$result = ActionsInvoiceplus::normalizeMonetaryInput($raw);

		$this->assertSame($provided, $result['provided']);
		$this->assertSame($valid, $result['valid']);
		$this->assertSame($value, $result['value']);
	}

	/** @return array<string,array<int,mixed>> */
	public function monetaryProvider()
	{
		return array(
			'missing field' => array(null, false, true, null),
			'empty string' => array('', false, true, null),
			'blanks only' => array("  \xC2\xA0 ", false, true, null),
			'explicit zero' => array('0', true, true, '0'),
			'explicit decimal zero' => array('0.00', true, true, '0'),
			'plain amount' => array('45', true, true, '45'),
			'letters' => array('abc', true, false, null),
			'mixed' => array('12abc', true, false, null),
			'scientific notation' => array('1e999', true, false, null),
			'no digit' => array('-', true, false, null),
			'array' => array(array('1'), false, true, null),
		);
	}

	/** @return void */
	public function testEmptyGridLetsTheNativeCreationRun()
	{
		$parsed = ActionsInvoiceplus::parseSubmittedPriceLevels(
			array(1 => '', 2 => '', 3 => '', 4 => ''),
			array(),
			4
		);

		$this->assertSame(array(), $parsed['levels']);
		$this->assertSame(array(), $parsed['errors']);
	}

	/** @return void */
	public function testFilledLevelsKeepTheirBaseTypeAndExplicitZero()
	{
		$parsed = ActionsInvoiceplus::parseSubmittedPriceLevels(
			array(1 => '50', 2 => '0', 3 => '', 4 => '40'),
			array(1 => 'HT', 2 => 'TTC', 3 => 'HT', 4 => 'unknown'),
			4
		);

		$this->assertSame(array(), $parsed['errors']);
		$this->assertSame(array(1, 2, 4), array_keys($parsed['levels']));
		$this->assertSame('50', $parsed['levels'][1]['price']);
		$this->assertSame('HT', $parsed['levels'][1]['price_base_type']);
		$this->assertSame('0', $parsed['levels'][2]['price']);
		$this->assertSame('TTC', $parsed['levels'][2]['price_base_type']);
		$this->assertArrayNotHasKey(3, $parsed['levels']);
		$this->assertSame('HT', $parsed['levels'][4]['price_base_type']);
	}

	/** @return void */
	public function testUpperLevelWithoutLevelOneIsRefused()
	{
		$parsed = ActionsInvoiceplus::parseSubmittedPriceLevels(
			array(1 => '', 2 => '45'),
			array(),
			4
		);

		$this->assertCount(1, $parsed['errors']);
		$this->assertSame('InvoicePlusPriceLevelOneRequired', $parsed['errors'][0]['code']);
	}

	/** @return void */
	public function testMalformedLevelIsReportedWithItsLevelNumber()
	{
		$parsed = ActionsInvoiceplus::parseSubmittedPriceLevels(
			array(1 => '50', 2 => 'abc'),
			array(),
			4
		);

		$this->assertCount(1, $parsed['errors']);
		$this->assertSame('InvoicePlusInvalidPriceLevelValue', $parsed['errors'][0]['code']);
		$this->assertSame(2, $parsed['errors'][0]['params'][0]);
	}

	/** @return void */
	public function testGridNeverExceedsTheConfiguredNumberOfLevels()
	{
		$parsed = ActionsInvoiceplus::parseSubmittedPriceLevels(
			array(1 => '50', 2 => '45', 3 => '40', 4 => '35', 5 => '30'),
			array(),
			4
		);

		$this->assertSame(array(), $parsed['errors']);
		$this->assertSame(array(1, 2, 3, 4), array_keys($parsed['levels']));
	}

	/**
	 * @param mixed    $raw   Submitted warehouse level
	 * @param bool     $valid Validation result
	 * @param int|null $value Normalized level
	 * @return void
	 * @dataProvider warehouseLevelProvider
	 */
	public function testWarehousePriceLevelValidation($raw, $valid, $value)
	{
		$result = ActionsInvoiceplus::validateWarehousePriceLevelInput($raw, 4);

		$this->assertSame($valid, $result['valid']);
		$this->assertSame($value, $result['value']);
	}

	/** @return array<string,array<int,mixed>> */
	public function warehouseLevelProvider()
	{
		return array(
			'empty means no level' => array('', true, null),
			'blanks mean no level' => array('   ', true, null),
			'first level' => array('1', true, 1),
			'last configured level' => array('4', true, 4),
			'zero is refused' => array('0', false, null),
			'negative is refused' => array('-1', false, null),
			'above the limit is refused' => array('5', false, null),
			'text is refused' => array('two', false, null),
			'decimal is refused' => array('1.5', false, null),
			'array is refused' => array(array('1'), false, null),
		);
	}

	/** @return void */
	public function testColspanFollowsTheHostCard()
	{
		$this->assertSame(2, ActionsInvoiceplus::resolveValueColspan(array('cols' => 2)));
		$this->assertSame(3, ActionsInvoiceplus::resolveValueColspan(array('colspan' => ' colspan="3"')));
		$this->assertSame(4, ActionsInvoiceplus::resolveValueColspan(array('colspanvalue' => '4')));
		$this->assertSame(0, ActionsInvoiceplus::resolveValueColspan(array()));
		$this->assertSame(0, ActionsInvoiceplus::resolveValueColspan('not an array'));
	}

	/** @return void */
	public function testHooksIgnoreEveryForeignContext()
	{
		global $db;

		$actions = new ActionsInvoiceplus($db);
		$object = new InvoicePlusProductPriceDouble();
		$action = 'add';

		$this->assertSame(0, $actions->doActions(array('currentcontext' => 'invoicecard'), $object, $action, null));
		$this->assertSame('add', $action);
		$this->assertSame('', (string) $actions->resprints);

		$viewAction = '';
		$this->assertSame(0, $actions->formObjectOptions(array('currentcontext' => 'invoicecard'), $object, $viewAction, null));
		$this->assertSame('', (string) $actions->resprints);
	}

	/** @return void */
	public function testTriggerIgnoresCreationsWithoutTheInvoicePlusMarker()
	{
		global $db, $user, $langs, $conf;

		$trigger = new InterfaceInvoicePlusProductPrices($db);
		$object = new InvoicePlusProductPriceDouble();

		$this->assertSame(0, $trigger->runTrigger('PRODUCT_CREATE', $object, $user, $langs, $conf));
		$this->assertSame(array(), $object->calls);
	}

	/** @return void */
	public function testTriggerIgnoresEveryOtherAction()
	{
		global $db, $user, $langs, $conf;

		$trigger = new InterfaceInvoicePlusProductPrices($db);
		$object = new InvoicePlusProductPriceDouble();
		$object->context[ActionsInvoiceplus::PRICE_LEVELS_CONTEXT_KEY] = array(
			2 => array('price' => '45', 'price_base_type' => 'HT'),
		);

		$this->assertSame(0, $trigger->runTrigger('PRODUCT_MODIFY', $object, $user, $langs, $conf));
		$this->assertSame(array(), $object->calls);
	}

	/** @return void */
	public function testTriggerWritesOnlyTheUpperLevelsThroughTheNativeMethod()
	{
		global $db, $user, $langs, $conf;

		$trigger = new InterfaceInvoicePlusProductPrices($db);
		$object = new InvoicePlusProductPriceDouble();
		$object->default_vat_code = 'X';
		$object->tva_npr = 1;
		$object->context[ActionsInvoiceplus::PRICE_LEVELS_CONTEXT_KEY] = array(
			4 => array('price' => '35', 'price_base_type' => 'TTC'),
			2 => array('price' => '0', 'price_base_type' => 'HT'),
		);

		$this->assertSame(0, $trigger->runTrigger('PRODUCT_CREATE', $object, $user, $langs, $conf));

		$this->assertCount(2, $object->calls);
		$this->assertSame(2, $object->calls[0]['level']);
		$this->assertSame('0', $object->calls[0]['price']);
		$this->assertSame('HT', $object->calls[0]['base']);
		$this->assertSame(4, $object->calls[1]['level']);
		$this->assertSame('35', $object->calls[1]['price']);
		$this->assertSame('TTC', $object->calls[1]['base']);
		// A null VAT makes the native method reuse the VAT of the product form,
		// and notrigger 0 keeps the native PRODUCT_PRICE_MODIFY trigger.
		$this->assertNull($object->calls[0]['vat']);
		$this->assertSame('X', $object->calls[0]['default_vat_code']);
		$this->assertSame(1, $object->calls[0]['npr']);
		$this->assertSame(0, $object->calls[0]['notrigger']);
		// The context is consumed, so a second create cannot duplicate history.
		$this->assertArrayNotHasKey(ActionsInvoiceplus::PRICE_LEVELS_CONTEXT_KEY, $object->context);
	}

	/** @return void */
	public function testTriggerRollsTheCreationBackWhenALevelFails()
	{
		global $db, $user, $langs, $conf;

		$trigger = new InterfaceInvoicePlusProductPrices($db);
		$object = new InvoicePlusProductPriceDouble();
		$object->updatePriceResult = -1;
		$object->context[ActionsInvoiceplus::PRICE_LEVELS_CONTEXT_KEY] = array(
			2 => array('price' => '45', 'price_base_type' => 'HT'),
			3 => array('price' => '40', 'price_base_type' => 'HT'),
		);

		$result = $trigger->runTrigger('PRODUCT_CREATE', $object, $user, $langs, $conf);

		$this->assertSame(-1, $result);
		$this->assertCount(1, $object->calls);
		$this->assertNotSame('', $trigger->error);
		$this->assertNotSame('', $object->error);
	}

	/** @return void */
	public function testTriggerRefusesAnOutOfRangeLevel()
	{
		global $db, $user, $langs, $conf;

		$trigger = new InterfaceInvoicePlusProductPrices($db);
		$object = new InvoicePlusProductPriceDouble();
		$object->context[ActionsInvoiceplus::PRICE_LEVELS_CONTEXT_KEY] = array(
			9 => array('price' => '45', 'price_base_type' => 'HT'),
		);

		$this->assertSame(-1, $trigger->runTrigger('PRODUCT_CREATE', $object, $user, $langs, $conf));
		$this->assertSame(array(), $object->calls);
	}

	/** @return void */
	public function testTriggerRefusesWhenMultiPricesAreDisabled()
	{
		global $db, $user, $langs, $conf;

		$conf->global->PRODUIT_MULTIPRICES = '';
		$trigger = new InterfaceInvoicePlusProductPrices($db);
		$object = new InvoicePlusProductPriceDouble();
		$object->context[ActionsInvoiceplus::PRICE_LEVELS_CONTEXT_KEY] = array(
			2 => array('price' => '45', 'price_base_type' => 'HT'),
		);

		$this->assertSame(-1, $trigger->runTrigger('PRODUCT_CREATE', $object, $user, $langs, $conf));
		$this->assertSame(array(), $object->calls);
	}
}

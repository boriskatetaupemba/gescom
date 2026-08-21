<?php
/* Copyright (C) 2026 InvoicePlus module
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    custom/invoiceplus/test/unit/InvoicePlusPriceLevelServiceTest.php
 * \ingroup invoiceplus
 * \brief   Deterministic tests of the Customer > Warehouse > N1 resolver.
 *
 * Run from htdocs/custom/invoiceplus with:
 * phpunit test/unit/InvoicePlusPriceLevelServiceTest.php
 */

global $db, $user, $conf;

require_once dirname(__FILE__).'/../../../../master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/api/class/api_access.class.php';
require_once DOL_DOCUMENT_ROOT.'/api/class/api.class.php';
dol_include_once('/invoiceplus/class/invoicepluspricelevelservice.class.php');
dol_include_once('/invoiceplus/class/invoiceplusproductlistservice.class.php');

use PHPUnit\Framework\TestCase;
use Luracast\Restler\RestException;

if (empty($user->id)) {
	$user->fetch(1);
	$user->loadRights();
}
DolibarrApiAccess::$user = $user;

/**
 * Resolver whose persisted levels are injected instead of read from the
 * database, so the mandatory priority and fallback rules are testable alone.
 */
class InvoicePlusPriceLevelServiceStub extends InvoicePlusPriceLevelService
{
	/** @var mixed Raw societe.price_level value */
	public $rawCustomerLevel = null;

	/** @var mixed Raw warehouse extrafield value */
	public $rawWarehouseLevel = null;

	/**
	 * @param int $customerId Third-party id
	 * @return int|null        Normalized level
	 */
	public function getCustomerPriceLevel($customerId)
	{
		return self::normalizeStoredLevel($this->rawCustomerLevel, self::getPriceLevelLimit(), 'test-customer');
	}

	/**
	 * @param int $warehouseId Warehouse id
	 * @return int|null         Normalized level
	 */
	public function getWarehousePriceLevel($warehouseId)
	{
		return self::normalizeStoredLevel($this->rawWarehouseLevel, self::getPriceLevelLimit(), 'test-warehouse');
	}
}

/**
 * Deterministic unit tests that never mutate the database.
 */
class InvoicePlusPriceLevelServiceTest extends TestCase
{
	/** @var array Saved multiprice configuration */
	private $savedConf = array();

	/** @return void */
	protected function setUp(): void
	{
		global $conf;

		$this->savedConf = array(
			'enabled' => isset($conf->global->PRODUIT_MULTIPRICES) ? $conf->global->PRODUIT_MULTIPRICES : null,
			'limit' => isset($conf->global->PRODUIT_MULTIPRICES_LIMIT) ? $conf->global->PRODUIT_MULTIPRICES_LIMIT : null,
		);
		$conf->global->PRODUIT_MULTIPRICES = '1';
		$conf->global->PRODUIT_MULTIPRICES_LIMIT = 5;
	}

	/** @return void */
	protected function tearDown(): void
	{
		global $conf;

		foreach (array('PRODUIT_MULTIPRICES' => 'enabled', 'PRODUIT_MULTIPRICES_LIMIT' => 'limit') as $key => $saved) {
			if ($this->savedConf[$saved] === null) {
				unset($conf->global->$key);
			} else {
				$conf->global->$key = $this->savedConf[$saved];
			}
		}
	}

	/** @return void */
	public function testFeatureFollowsNativeConfigurationOnly()
	{
		global $conf;

		$this->assertTrue(InvoicePlusPriceLevelService::isMultiPriceEnabled());
		$this->assertSame(5, InvoicePlusPriceLevelService::getPriceLevelLimit());

		$conf->global->PRODUIT_MULTIPRICES_LIMIT = 0;
		$this->assertFalse(InvoicePlusPriceLevelService::isMultiPriceEnabled());

		$conf->global->PRODUIT_MULTIPRICES_LIMIT = 5;
		$conf->global->PRODUIT_MULTIPRICES = '';
		$this->assertFalse(InvoicePlusPriceLevelService::isMultiPriceEnabled());
	}

	/** @return void */
	public function testDisabledMultiPricesRaiseABusinessConflict()
	{
		global $conf, $db;

		$conf->global->PRODUIT_MULTIPRICES = '';
		$service = new InvoicePlusPriceLevelService($db, null);

		$this->expectException(RestException::class);
		$this->expectExceptionCode(409);
		$service->assertMultiPriceEnabled();
	}

	/**
	 * @param mixed    $rawValue Persisted value
	 * @param int|null $expected Normalized level
	 * @return void
	 * @dataProvider storedLevelProvider
	 */
	public function testStoredLevelNormalization($rawValue, $expected)
	{
		$this->assertSame($expected, InvoicePlusPriceLevelService::normalizeStoredLevel($rawValue, 5, 'test'));
	}

	/** @return array<string,array<int,mixed>> */
	public function storedLevelProvider()
	{
		return array(
			'null is undefined' => array(null, null),
			'empty string is undefined' => array('', null),
			'zero is undefined' => array('0', null),
			'negative is undefined' => array('-2', null),
			'above the limit is undefined' => array('9', null),
			'text is undefined' => array('two', null),
			'level one' => array('1', 1),
			'level five' => array(5, 5),
			'level three as string' => array('3', 3),
		);
	}

	/** @return void */
	public function testDefinedPriceKeepsExplicitZeroAndRejectsNull()
	{
		$this->assertTrue(InvoicePlusPriceLevelService::isDefinedPrice($this->priceRow('0.00000000')));
		$this->assertTrue(InvoicePlusPriceLevelService::isDefinedPrice($this->priceRow(0)));
		$this->assertTrue(InvoicePlusPriceLevelService::isDefinedPrice($this->priceRow('50.00000000')));
		$this->assertFalse(InvoicePlusPriceLevelService::isDefinedPrice($this->priceRow(null)));
		$this->assertFalse(InvoicePlusPriceLevelService::isDefinedPrice($this->priceRow('')));
		$this->assertFalse(InvoicePlusPriceLevelService::isDefinedPrice(null));
	}

	/** @return void */
	public function testRequestedLevelWinsWhenItHoldsAPrice()
	{
		$selection = InvoicePlusPriceLevelService::selectPriceRow(
			array(1 => $this->priceRow('50'), 3 => $this->priceRow('45')),
			3
		);

		$this->assertNotNull($selection);
		$this->assertSame(3, $selection['applied_price_level']);
		$this->assertFalse($selection['price_fallback']);
		$this->assertSame('45', $selection['row']->price);
	}

	/** @return void */
	public function testMissingLevelFallsBackDirectlyToLevelOne()
	{
		$selection = InvoicePlusPriceLevelService::selectPriceRow(
			array(1 => $this->priceRow('50')),
			3
		);

		$this->assertSame(1, $selection['applied_price_level']);
		$this->assertTrue($selection['price_fallback']);
		$this->assertSame('50', $selection['row']->price);
	}

	/** @return void */
	public function testNullPriceOnRequestedLevelFallsBackToLevelOne()
	{
		$selection = InvoicePlusPriceLevelService::selectPriceRow(
			array(1 => $this->priceRow('50'), 3 => $this->priceRow(null)),
			3
		);

		$this->assertSame(1, $selection['applied_price_level']);
		$this->assertTrue($selection['price_fallback']);
	}

	/** @return void */
	public function testExplicitZeroOnRequestedLevelNeverFallsBack()
	{
		$selection = InvoicePlusPriceLevelService::selectPriceRow(
			array(1 => $this->priceRow('50'), 2 => $this->priceRow('0')),
			2
		);

		$this->assertSame(2, $selection['applied_price_level']);
		$this->assertFalse($selection['price_fallback']);
		$this->assertSame(0.0, InvoicePlusPriceLevelService::buildPriceMetadata($selection, 2, 'customer')['price']);
	}

	/**
	 * An intermediate level must never be tried before N1.
	 *
	 * @return void
	 */
	public function testFallbackSkipsEveryIntermediateLevel()
	{
		$selection = InvoicePlusPriceLevelService::selectPriceRow(
			array(1 => $this->priceRow('50'), 2 => $this->priceRow('45')),
			3
		);

		$this->assertSame(1, $selection['applied_price_level']);
		$this->assertSame('50', $selection['row']->price);
		$this->assertNotSame('45', $selection['row']->price);
	}

	/** @return void */
	public function testMissingRequestedLevelAndMissingLevelOneIsABusinessError()
	{
		$this->assertNull(InvoicePlusPriceLevelService::selectPriceRow(array(2 => $this->priceRow('45')), 3));
		$this->assertNull(InvoicePlusPriceLevelService::selectPriceRow(array(), 1));
		$this->assertNull(InvoicePlusPriceLevelService::selectPriceRow(array(1 => $this->priceRow(null)), 1));
	}

	/**
	 * @param mixed  $customerLevel  Raw societe.price_level
	 * @param mixed  $warehouseLevel Raw warehouse extrafield value
	 * @param int    $expectedLevel  Requested level
	 * @param string $expectedSource Resolution source
	 * @return void
	 * @dataProvider priorityProvider
	 */
	public function testCustomerThenWarehouseThenLevelOnePriority($customerLevel, $warehouseLevel, $expectedLevel, $expectedSource)
	{
		global $db;

		$service = new InvoicePlusPriceLevelServiceStub($db, null);
		$service->rawCustomerLevel = $customerLevel;
		$service->rawWarehouseLevel = $warehouseLevel;

		$resolution = $service->resolvePriceLevel(7, 9);

		$this->assertSame($expectedLevel, $resolution['requested_price_level']);
		$this->assertSame($expectedSource, $resolution['price_level_source']);
	}

	/** @return array<string,array<int,mixed>> */
	public function priorityProvider()
	{
		return array(
			'customer wins over warehouse' => array('3', '2', 3, 'customer'),
			'warehouse used without customer level' => array(null, '2', 2, 'warehouse'),
			'customer used without warehouse level' => array('3', null, 3, 'customer'),
			'neither source defines a level' => array(null, null, 1, 'default_level'),
			'legacy customer level out of range' => array('9', null, 1, 'default_level'),
			'legacy warehouse level out of range' => array(null, '9', 1, 'default_level'),
			'zero customer level is undefined' => array('0', '2', 2, 'warehouse'),
		);
	}

	/**
	 * A customer level takes the request, and its own failure then falls back to
	 * N1 directly instead of using the warehouse level.
	 *
	 * @return void
	 */
	public function testWarehouseLevelIsNeverUsedAfterACustomerLevelFails()
	{
		global $db;

		$service = new InvoicePlusPriceLevelServiceStub($db, null);
		$service->rawCustomerLevel = '3';
		$service->rawWarehouseLevel = '2';
		$resolution = $service->resolvePriceLevel(7, 9);

		$selection = InvoicePlusPriceLevelService::selectPriceRow(
			array(1 => $this->priceRow('50'), 2 => $this->priceRow('45')),
			$resolution['requested_price_level']
		);
		$metadata = InvoicePlusPriceLevelService::buildPriceMetadata(
			$selection,
			$resolution['requested_price_level'],
			$resolution['price_level_source']
		);

		$this->assertSame(3, $metadata['requested_price_level']);
		$this->assertSame(1, $metadata['applied_price_level']);
		$this->assertSame('customer', $metadata['price_level_source']);
		$this->assertTrue($metadata['price_fallback']);
		$this->assertSame(50.0, $metadata['price']);
	}

	/** @return void */
	public function testMetadataKeepsOneSingleAppliedLineCoherent()
	{
		$row = $this->priceRow('50', array(
			'price_ttc' => '60',
			'price_base_type' => 'ht',
			'tva_tx' => '20.0000',
			'default_vat_code' => 'X',
			'multicurrency_code' => 'USD',
			'rowid' => '77',
		));
		$metadata = InvoicePlusPriceLevelService::buildPriceMetadata(
			array('row' => $row, 'applied_price_level' => 1, 'price_fallback' => true),
			3,
			'warehouse'
		);

		$this->assertSame(3, $metadata['requested_price_level']);
		$this->assertSame(1, $metadata['applied_price_level']);
		$this->assertSame('warehouse', $metadata['price_level_source']);
		$this->assertTrue($metadata['price_fallback']);
		$this->assertSame(50.0, $metadata['price']);
		$this->assertSame(60.0, $metadata['price_ttc']);
		$this->assertSame('HT', $metadata['price_base_type']);
		$this->assertSame(20.0, $metadata['tva_tx']);
		$this->assertSame('X', $metadata['default_vat_code']);
		$this->assertSame('USD', $metadata['multicurrency_code']);
		$this->assertSame(77, $metadata['price_line_id']);
	}

	/**
	 * The whole page must be priced by one grouped query using the native
	 * ordering, never by one query per product.
	 *
	 * @return void
	 */
	public function testPricesOfAWholePageAreReadByOneGroupedQuery()
	{
		$database = new class {
			/** @var array<int,string> */
			public $queries = array();
			/** @var array<int,object> */
			public $rows = array();
			/** @param string $sql Query */
			public function query($sql)
			{
				$this->queries[] = $sql;
				return new stdClass();
			}
			/** @param mixed $result Result */
			public function fetch_object($result)
			{
				return array_shift($this->rows);
			}
			/** @param mixed $result Result */
			public function free($result)
			{
			}
			/** @return string Error */
			public function lasterror()
			{
				return 'not exposed';
			}
		};
		$database->rows = array(
			$this->priceRow('50', array('fk_product' => 11, 'price_level' => 1, 'rowid' => 5)),
			$this->priceRow('49', array('fk_product' => 11, 'price_level' => 1, 'rowid' => 4)),
			$this->priceRow('45', array('fk_product' => 11, 'price_level' => 3, 'rowid' => 6)),
			$this->priceRow('52', array('fk_product' => 12, 'price_level' => 1, 'rowid' => 7)),
		);
		$service = new InvoicePlusPriceLevelService($database, null);

		$index = $service->fetchCurrentPriceRows(array(11, 12, 0, '13'), array(3, 1, 3));

		$this->assertCount(1, $database->queries);
		$sql = $database->queries[0];
		$this->assertStringContainsString('FROM '.MAIN_DB_PREFIX.'product_price AS pp', $sql);
		$this->assertStringContainsString('pp.entity IN (', $sql);
		$this->assertStringContainsString('pp.fk_product IN (11,12,13)', $sql);
		$this->assertStringContainsString('pp.price_level IN (3,1)', $sql);
		$this->assertStringContainsString('ORDER BY pp.fk_product ASC, pp.price_level ASC, pp.date_price DESC, pp.rowid DESC', $sql);

		// Only the current line of each (product, level) couple is kept.
		$this->assertSame('50', $index[11][1]->price);
		$this->assertSame('45', $index[11][3]->price);
		$this->assertSame('52', $index[12][1]->price);
		$this->assertArrayNotHasKey(3, $index[12]);
	}

	/** @return void */
	public function testEmptySelectionRunsNoQueryAtAll()
	{
		$database = new class {
			/** @var int Number of queries */
			public $count = 0;
			/** @param string $sql Query */
			public function query($sql)
			{
				$this->count++;
				return false;
			}
		};
		$service = new InvoicePlusPriceLevelService($database, null);

		$this->assertSame(array(), $service->fetchCurrentPriceRows(array(), array(1)));
		$this->assertSame(array(), $service->fetchCurrentPriceRows(array(1), array()));
		$this->assertSame(0, $database->count);
	}

	/** @return void */
	public function testMalformedIdentifiersAreRejected()
	{
		global $db;

		$service = new InvoicePlusPriceLevelService($db, null);

		$this->assertTrue($service->isPositiveIdentifier(4));
		$this->assertTrue($service->isPositiveIdentifier('4'));
		$this->assertFalse($service->isPositiveIdentifier(0));
		$this->assertFalse($service->isPositiveIdentifier('0'));
		$this->assertFalse($service->isPositiveIdentifier('-1'));
		$this->assertFalse($service->isPositiveIdentifier('1.5'));
		$this->assertFalse($service->isPositiveIdentifier('abc'));
		$this->assertFalse($service->isPositiveIdentifier(null));
	}

	/** @return void */
	public function testRequestedLevelOutOfConfigurationIsRejected()
	{
		global $db;

		$service = new InvoicePlusPriceLevelService($db, null);
		$this->assertSame(5, $service->normalizeRequestedLevel('5'));

		$this->expectException(RestException::class);
		$service->normalizeRequestedLevel(6);
	}

	/**
	 * Build a product_price row fixture.
	 *
	 * @param mixed $price Price column value
	 * @param array $extra Additional columns
	 * @return stdClass     Row
	 */
	private function priceRow($price, array $extra = array())
	{
		$row = new stdClass();
		$row->price = $price;
		$row->price_ttc = null;
		$row->price_min = null;
		$row->price_min_ttc = null;
		$row->price_base_type = 'HT';
		$row->price_label = null;
		$row->tva_tx = 0;
		$row->default_vat_code = null;
		$row->recuperableonly = 0;
		$row->localtax1_tx = 0;
		$row->localtax1_type = '0';
		$row->localtax2_tx = 0;
		$row->localtax2_type = '0';
		$row->multicurrency_code = null;
		$row->multicurrency_tx = null;
		$row->multicurrency_price = null;
		$row->multicurrency_price_ttc = null;
		$row->rowid = 1;
		$row->date_price = '2026-01-01 00:00:00';
		foreach ($extra as $field => $value) {
			$row->$field = $value;
		}

		return $row;
	}
}

/**
 * Deterministic validation tests of the enriched product list.
 */
class InvoicePlusProductListServiceTest extends TestCase
{
	/** @var InvoicePlusProductListService */
	private $service;

	/** @return void */
	protected function setUp(): void
	{
		global $db, $user;

		$this->service = new InvoicePlusProductListService($db, $user);
	}

	/** @return void */
	public function testProductSortFieldWhitelist()
	{
		$this->assertSame('t.ref', $this->service->validateSortField('t.ref'));
		$this->assertSame('t.rowid', $this->service->validateSortField('t.rowid'));
		$this->assertSame('t.tms', $this->service->validateSortField('t.tms'));
	}

	/**
	 * @param string $sortfield Rejected sort field
	 * @return void
	 * @dataProvider invalidSortFieldProvider
	 */
	public function testUnsafeSortFieldsAreRejected($sortfield)
	{
		$this->expectException(RestException::class);
		$this->service->validateSortField($sortfield);
	}

	/** @return array<string,array<int,string>> */
	public function invalidSortFieldProvider()
	{
		return array(
			'unqualified' => array('ref'),
			'unknown alias' => array('x.ref'),
			'not whitelisted' => array('t.note_public'),
			'injection attempt' => array('t.rowid, (SELECT 1)'),
		);
	}

	/** @return void */
	public function testQualifiedProductSqlFiltersAreAccepted()
	{
		$this->service->validateSqlFilterAliases("(t.ref:like:'PR%') and (ef.channel:=:'shop')");
		$this->addToAssertionCount(1);
	}

	/**
	 * @param string $filter Rejected Universal Search expression
	 * @return void
	 * @dataProvider invalidProductSqlFilterProvider
	 */
	public function testUnsafeProductSqlFiltersAreRejected($filter)
	{
		$this->expectException(RestException::class);
		$this->service->validateSqlFilterAliases($filter);
	}

	/** @return array<string,array<int,string>> */
	public function invalidProductSqlFilterProvider()
	{
		return array(
			'unqualified field' => array("(ref:like:'PR%')"),
			'unknown alias' => array("(x.ref:=:'PR1')"),
			'unknown product field' => array("(t.unknown:=:'x')"),
			'constant operand' => array('(1:=:1)'),
		);
	}

	/** @return void */
	public function testPaginationAndModeValidation()
	{
		$this->assertSame(25, $this->service->validateLimit('25'));
		$this->assertSame(0, $this->service->validatePage('0'));
		$this->assertSame(2, $this->service->validateMode(2));
		$this->assertSame(3, $this->service->validateVariantFilter('3'));
		$this->assertSame(0, $this->service->validateNonNegativeInteger('0', 'category'));
	}

	/**
	 * @param string $method Validation method
	 * @param mixed  $value  Rejected value
	 * @return void
	 * @dataProvider invalidListParameterProvider
	 */
	public function testInvalidListParametersAreRejected($method, $value)
	{
		$this->expectException(RestException::class);
		if ($method === 'validateNonNegativeInteger') {
			$this->service->validateNonNegativeInteger($value, 'category');
		} else {
			$this->service->$method($value);
		}
	}

	/** @return array<string,array<int,mixed>> */
	public function invalidListParameterProvider()
	{
		return array(
			'negative page' => array('validatePage', '-1'),
			'text limit' => array('validateLimit', 'many'),
			'unknown mode' => array('validateMode', 3),
			'unknown variant filter' => array('validateVariantFilter', 4),
			'negative category' => array('validateNonNegativeInteger', '-2'),
			'bad sort order' => array('validateSortOrder', 'RANDOM'),
		);
	}

	/** @return void */
	public function testMissingPriceBehaviorDefaultsToTheBusinessError()
	{
		$this->assertSame('error', $this->service->validateOnMissingPrice(''));
		$this->assertSame('error', $this->service->validateOnMissingPrice(null));
		$this->assertSame('error', $this->service->validateOnMissingPrice('ERROR'));
		$this->assertSame('skip', $this->service->validateOnMissingPrice('skip'));

		$this->expectException(RestException::class);
		$this->service->validateOnMissingPrice('ignore');
	}

	/** @return void */
	public function testResolvedPriceReplacesOnlyTheCommercialFields()
	{
		$response = new stdClass();
		$response->id = 42;
		$response->ref = 'PR42';
		$response->price = 99.0;
		$response->price_ttc = 118.8;
		$response->stock_reel = 7;

		$metadata = InvoicePlusPriceLevelService::buildPriceMetadata(
			array(
				'row' => (object) array(
					'price' => '50',
					'price_ttc' => '60',
					'price_min' => '10',
					'price_min_ttc' => '12',
					'price_base_type' => 'HT',
					'price_label' => 'Retail',
					'tva_tx' => '20',
					'default_vat_code' => null,
					'recuperableonly' => 0,
					'localtax1_tx' => 0,
					'localtax1_type' => '0',
					'localtax2_tx' => 0,
					'localtax2_type' => '0',
					'rowid' => 3,
				),
				'applied_price_level' => 1,
				'price_fallback' => true,
			),
			3,
			'customer'
		);

		$result = $this->service->applyResolvedPrice($response, $metadata);

		$this->assertSame(50.0, $result->price);
		$this->assertSame(60.0, $result->price_ttc);
		$this->assertSame('HT', $result->price_base_type);
		$this->assertSame(20.0, $result->tva_tx);
		$this->assertSame(3, $result->requested_price_level);
		$this->assertSame(1, $result->applied_price_level);
		$this->assertSame('customer', $result->price_level_source);
		$this->assertTrue($result->price_fallback);
		$this->assertSame(42, $result->id);
		$this->assertSame('PR42', $result->ref);
		$this->assertSame(7, $result->stock_reel);
	}

	/** @return void */
	public function testNativeProductBridgeExposesCleanupAndFiltering()
	{
		$bridge = new InvoicePlusNativeProductsApi();
		$object = new stdClass();
		$object->id = 42;
		$object->ref = 'PR42';
		$object->requested_price_level = 3;

		$filtered = $bridge->filterResponseProperties($object, 'id,requested_price_level');

		$this->assertSame(42, $filtered->id);
		$this->assertSame(3, $filtered->requested_price_level);
		$this->assertFalse(property_exists($filtered, 'ref'));
	}
}

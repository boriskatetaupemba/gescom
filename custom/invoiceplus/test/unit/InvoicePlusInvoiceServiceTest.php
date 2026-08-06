<?php
/* Copyright (C) 2026 InvoicePlus module
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    custom/invoiceplus/test/unit/InvoicePlusInvoiceServiceTest.php
 * \ingroup invoiceplus
 * \brief   Unit tests for deterministic InvoicePlus service behavior.
 *
 * Run from htdocs/custom/invoiceplus with:
 * phpunit test/unit/InvoicePlusInvoiceServiceTest.php
 */

global $db, $user;

require_once dirname(__FILE__).'/../../../../master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/api/class/api_access.class.php';
require_once DOL_DOCUMENT_ROOT.'/api/class/api.class.php';
dol_include_once('/invoiceplus/class/invoiceplusinvoiceservice.class.php');

use PHPUnit\Framework\TestCase;

if (empty($user->id)) {
	$user->fetch(1);
	$user->loadRights();
}
DolibarrApiAccess::$user = $user;

/**
 * Deterministic service unit tests that do not mutate the database.
 */
class InvoicePlusInvoiceServiceTest extends TestCase
{
	/** @var InvoicePlusInvoiceService */
	private $service;

	/**
	 * @return void
	 */
	protected function setUp(): void
	{
		global $db, $user;
		$this->service = new InvoicePlusInvoiceService($db, $user);
	}

	/**
	 * @return void
	 */
	public function testBooleanNormalization()
	{
		$this->assertTrue($this->service->normalizeBooleanParameter(true));
		$this->assertTrue($this->service->normalizeBooleanParameter('true'));
		$this->assertTrue($this->service->normalizeBooleanParameter('1'));
		$this->assertFalse($this->service->normalizeBooleanParameter(false));
		$this->assertFalse($this->service->normalizeBooleanParameter('false'));
		$this->assertFalse($this->service->normalizeBooleanParameter('0'));
		$this->assertTrue($this->service->normalizeBooleanParameter('', true));
	}

	/**
	 * @return void
	 */
	public function testSortFieldWhitelist()
	{
		$this->assertSame('t.rowid', $this->service->validateSortField('t.rowid'));
		$this->assertSame('t.datef', $this->service->validateSortField('t.datef'));
	}

	/**
	 * @return void
	 */
	public function testWarehouseLineFilteringDoesNotChangeTotals()
	{
		$response = new stdClass();
		$response->total_ht = '150.00';
		$response->total_tva = '30.00';
		$response->total_ttc = '180.00';
		$response->totalpaid = '180.00';
		$response->remaintopay = '0.00';
		$lineOne = new stdClass();
		$lineOne->id = 10;
		$lineOne->fk_warehouse = 5;
		$lineTwo = new stdClass();
		$lineTwo->id = 11;
		$lineTwo->fk_warehouse = 6;
		$response->lines = array($lineOne, $lineTwo);

		$result = $this->service->filterInvoiceLines($response, 5, true, true);

		$this->assertCount(1, $result->lines);
		$this->assertSame(10, $result->lines[0]->id);
		$this->assertSame('150.00', $result->total_ht);
		$this->assertSame('30.00', $result->total_tva);
		$this->assertSame('180.00', $result->total_ttc);
		$this->assertSame('180.00', $result->totalpaid);
		$this->assertSame('0.00', $result->remaintopay);
	}

	/**
	 * @return void
	 */
	public function testWithLinesFalseRemovesOnlyLines()
	{
		$response = new stdClass();
		$response->total_ttc = '42.00';
		$response->lines = array(new stdClass());

		$result = $this->service->filterInvoiceLines($response, 5, false, false);

		$this->assertFalse(property_exists($result, 'lines'));
		$this->assertSame('42.00', $result->total_ttc);
	}

	/**
	 * Legacy fallback selection must not pretend that unassigned native lines
	 * belong to the requested warehouse.
	 *
	 * @return void
	 */
	public function testWarehouseLineFilteringRemovesLegacyUnassignedLines()
	{
		$response = new stdClass();
		$response->total_ttc = '42.00';
		$line = new stdClass();
		$line->id = 12;
		$line->fk_warehouse = 0;
		$response->lines = array($line);

		$result = $this->service->filterInvoiceLines($response, 5, true, true);

		$this->assertSame(array(), $result->lines);
		$this->assertSame('42.00', $result->total_ttc);
	}

	/**
	 * @return void
	 */
	public function testPaginationEnvelope()
	{
		$result = $this->service->buildPaginationData(array('a', 'b'), 11, 1, 5);
		$this->assertSame(array('a', 'b'), $result['data']);
		$this->assertSame(11, $result['pagination']['total']);
		$this->assertSame(1, $result['pagination']['page']);
		$this->assertSame(3, $result['pagination']['page_count']);
		$this->assertSame(5, $result['pagination']['limit']);
	}
}

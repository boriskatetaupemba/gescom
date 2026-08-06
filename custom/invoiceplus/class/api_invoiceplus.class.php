<?php
/* Copyright (C) 2026 InvoicePlus module
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    custom/invoiceplus/class/api_invoiceplus.class.php
 * \ingroup invoiceplus
 * \brief   REST API of the InvoicePlus module.
 */

use Luracast\Restler\RestException;

dol_include_once('/invoiceplus/class/invoiceplusinvoiceservice.class.php');

/**
 * InvoicePlus REST API.
 *
 * @access protected
 * @class  DolibarrApiAccess {@requires user,external}
 */
class Invoiceplus extends DolibarrApi
{
	/** @var InvoicePlusInvoiceService */
	private $invoiceService;

	/**
	 * Constructor.
	 */
	public function __construct()
	{
		global $db;

		$this->db = $db;
		$this->invoiceService = null;
	}

	/**
	 * Return customer invoices associated with a warehouse.
	 *
	 * Invoice objects are built by the installed native Invoices API. The
	 * endpoint therefore preserves its fields, payment values, contacts,
	 * access checks, cleanup rules and InvoiceClosure data.
	 *
	 * @param int    $warehouse_id       Warehouse id
	 * @param string $sortfield          Whitelisted invoice sort field
	 * @param string $sortorder          ASC or DESC
	 * @param int    $limit              Page size, capped by INVOICEPLUS_MAX_API_LIMIT
	 * @param int    $page               Zero-based page
	 * @param string $thirdparty_ids     Third-party ids, ignored for external users {@pattern /^[0-9,]*$/i}
	 * @param string $status             draft | unpaid | paid | cancelled | closed | paid_not_closed
	 * @param string $sqlfilters         Native Universal Search criteria using t or ef aliases
	 * @param string $properties         Comma-separated response properties
	 * @param mixed  $pagination_data    true, false, 1 or 0
	 * @param mixed  $loadlinkedobjects  true, false, 1 or 0
	 * @param string $date_start         Invoice date lower bound (YYYY-MM-DD)
	 * @param string $date_end           Invoice date upper bound (YYYY-MM-DD)
	 * @param mixed  $withLines          true, false, 1 or 0
	 * @param mixed  $warehouse_lines_only true, false, 1 or 0
	 * @return array                     Native-compatible invoices or pagination envelope
	 *
	 * @url GET /warehouse/{warehouse_id}
	 *
	 * @throws RestException 400 Invalid parameter
	 * @throws RestException 403 Access denied
	 * @throws RestException 404 Warehouse not found
	 * @throws RestException 503 Database read error
	 */
	public function getByWarehouse(
		$warehouse_id,
		$sortfield = 't.rowid',
		$sortorder = 'DESC',
		$limit = 100,
		$page = 0,
		$thirdparty_ids = '',
		$status = '',
		$sqlfilters = '',
		$properties = '',
		$pagination_data = false,
		$loadlinkedobjects = 0,
		$date_start = '',
		$date_end = '',
		$withLines = true,
		$warehouse_lines_only = false
	) {
		if (!getDolGlobalInt('INVOICEPLUS_API_ENABLED', 1)) {
			throw new RestException(403, 'InvoicePlus API is disabled.');
		}
		if (!DolibarrApiAccess::$user->hasRight('facture', 'lire')) {
			throw new RestException(403, 'Access forbidden.');
		}
		if (!DolibarrApiAccess::$user->hasRight('stock', 'lire')) {
			throw new RestException(403, 'Access forbidden.');
		}
		// Build the user-bound service only after Restler authentication. Keeping
		// the constructor user-agnostic also makes explorer discovery reliable.
		$this->invoiceService = new InvoicePlusInvoiceService($this->db, DolibarrApiAccess::$user);

		if (
			(is_string($warehouse_id) && !preg_match('/^[0-9]+$/', $warehouse_id))
			|| (int) $warehouse_id <= 0
		) {
			throw new RestException(400, 'Invalid warehouse ID.');
		}

		$paginationData = $this->invoiceService->normalizeBooleanParameter(
			$pagination_data,
			false,
			'pagination_data'
		);
		$loadLinkedObjects = $this->invoiceService->normalizeBooleanParameter(
			$loadlinkedobjects,
			false,
			'loadlinkedobjects'
		);
		$withLinesValue = $this->invoiceService->normalizeBooleanParameter($withLines, true, 'withLines');
		$warehouseLinesOnly = $this->invoiceService->normalizeBooleanParameter(
			$warehouse_lines_only,
			false,
			'warehouse_lines_only'
		);

		$warehouse = $this->invoiceService->getWarehouse((int) $warehouse_id);

		return $this->invoiceService->getInvoicesByWarehouse($warehouse, array(
			'sortfield' => (string) $sortfield,
			'sortorder' => (string) $sortorder,
			'limit' => (int) $limit,
			'page' => (int) $page,
			'thirdparty_ids' => (string) $thirdparty_ids,
			'status' => (string) $status,
			'sqlfilters' => (string) $sqlfilters,
			'properties' => (string) $properties,
			'pagination_data' => $paginationData,
			'loadlinkedobjects' => $loadLinkedObjects,
			'date_start' => (string) $date_start,
			'date_end' => (string) $date_end,
			'withLines' => $withLinesValue,
			'warehouse_lines_only' => $warehouseLinesOnly,
		));
	}
}

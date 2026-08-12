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
dol_include_once('/invoiceplus/class/invoiceplusthirdpartyservice.class.php');

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

	/** @var InvoicePlusThirdPartyService */
	private $thirdPartyService;

	/**
	 * Constructor.
	 */
	public function __construct()
	{
		global $db;

		$this->db = $db;
		$this->invoiceService = null;
		$this->thirdPartyService = null;
	}

	/**
	 * List active third parties assigned to the authenticated sales representative.
	 *
	 * The sales representative is always the user identified by the REST API key;
	 * no user id can be supplied by the caller.
	 *
	 * @param string $sortfield Third-party sort field
	 * @param string $sortorder ASC or DESC
	 * @param int    $limit     Page size, capped by INVOICEPLUS_MAX_API_LIMIT
	 * @param int    $page      Zero-based page
	 * @param int    $status    1 active, 0 closed, -1 all
	 * @param string $properties Comma-separated response properties
	 * @return array             Native Dolibarr third-party objects
	 *
	 * @url GET /thirdparties
	 *
	 * @throws RestException 400 Invalid parameter
	 * @throws RestException 403 Access denied
	 * @throws RestException 503 Database read error
	 */
	public function getAssignedThirdParties($sortfield = 't.nom', $sortorder = 'ASC', $limit = 100, $page = 0, $status = 1, $properties = '')
	{
		$this->assertInvoicePlusApiEnabled();
		$user = DolibarrApiAccess::$user;
		if (empty($user->id) || !empty($user->socid) || !$user->hasRight('societe', 'lire')) {
			throw new RestException(403, 'Access forbidden.');
		}

		return $this->getThirdPartyService()->getAssignedThirdParties(array(
			'sortfield' => $sortfield,
			'sortorder' => $sortorder,
			'limit' => $limit,
			'page' => $page,
			'status' => $status,
			'properties' => $properties,
		));
	}

	/**
	 * List native-compatible invoices enriched by custom invoice modules.
	 *
	 * This is the core-free replacement for the former customization of the
	 * native GET /invoices response.
	 *
	 * @param string $sortfield      Sort field
	 * @param string $sortorder      Sort order
	 * @param int    $limit          Limit for list
	 * @param int    $page           Page number
	 * @param string $thirdparty_ids Third-party ids {@pattern /^[0-9,]*$/i}
	 * @param string $status         draft | unpaid | paid | cancelled
	 * @param string $sqlfilters     Native Universal Search criteria
	 * @param string $properties     Comma-separated response properties
	 * @return array                 Invoice objects
	 *
	 */
	public function index($sortfield = 't.rowid', $sortorder = 'ASC', $limit = 100, $page = 0, $thirdparty_ids = '', $status = '', $sqlfilters = '', $properties = '')
	{
		$this->assertInvoiceApiAccess();
		$maxLimit = max(1, (int) getDolGlobalInt('INVOICEPLUS_MAX_API_LIMIT', 1000));
		$limit = (int) $limit;
		if ($limit <= 0) {
			$limit = $maxLimit;
		}
		$limit = min($limit, $maxLimit);
		$page = max(0, (int) $page);
		$nativeApi = new InvoicePlusNativeInvoicesApi();
		$responses = $nativeApi->index($sortfield, $sortorder, $limit, $page, $thirdparty_ids, $status, $sqlfilters, '');

		foreach ($responses as $key => $response) {
			$responses[$key] = $this->enrichAndFilterResponse($nativeApi, $response, $properties);
		}

		return $responses;
	}

	/**
	 * Return one native-compatible invoice enriched by custom invoice modules.
	 *
	 * @param int    $id           Invoice id
	 * @param int    $contact_list Contact list detail level
	 * @param string $properties   Comma-separated response properties
	 * @return Object              Invoice response
	 *
	 * @url GET /{id}
	 */
	public function get($id, $contact_list = 1, $properties = '')
	{
		$this->assertInvoiceApiAccess();
		$nativeApi = new InvoicePlusNativeInvoicesApi();
		$response = $nativeApi->get((int) $id, (int) $contact_list);

		return $this->enrichAndFilterResponse($nativeApi, $response, $properties);
	}

	/**
	 * Return an invoice by reference with InvoicePlus enrichment.
	 *
	 * @param string $ref          Invoice reference
	 * @param int    $contact_list Contact list detail level
	 * @param string $properties   Comma-separated response properties
	 * @return Object              Invoice response
	 *
	 * @url GET /ref/{ref}
	 */
	public function getByRef($ref, $contact_list = 1, $properties = '')
	{
		$this->assertInvoiceApiAccess();
		$nativeApi = new InvoicePlusNativeInvoicesApi();
		$response = $nativeApi->getByRef($ref, (int) $contact_list);

		return $this->enrichAndFilterResponse($nativeApi, $response, $properties);
	}

	/**
	 * Return an invoice by external reference with InvoicePlus enrichment.
	 *
	 * @param string $ref_ext      External reference
	 * @param int    $contact_list Contact list detail level
	 * @param string $properties   Comma-separated response properties
	 * @return Object              Invoice response
	 *
	 * @url GET /ref_ext/{ref_ext}
	 */
	public function getByRefExt($ref_ext, $contact_list = 1, $properties = '')
	{
		$this->assertInvoiceApiAccess();
		$nativeApi = new InvoicePlusNativeInvoicesApi();
		$response = $nativeApi->getByRefExt($ref_ext, (int) $contact_list);

		return $this->enrichAndFilterResponse($nativeApi, $response, $properties);
	}

	/**
	 * List invoices linked to bank accounts or cash registers.
	 *
	 * This endpoint replaces the former core route GET /invoices/byaccounts.
	 *
	 * @param string $account_ids     Required account ids, for example 1,2,3 {@pattern /^[0-9,]*$/i}
	 * @param string $sortfield       Whitelisted invoice sort field
	 * @param string $sortorder       ASC or DESC
	 * @param int    $limit           Page size, capped by INVOICEPLUS_MAX_API_LIMIT
	 * @param int    $page            Zero-based page
	 * @param string $thirdparty_ids  Third-party ids {@pattern /^[0-9,]*$/i}
	 * @param string $status          draft | unpaid | paid | cancelled
	 * @param string $sqlfilters      Native Universal Search criteria using t or ef aliases
	 * @param string $properties      Comma-separated response properties
	 * @return array                  Invoice objects
	 *
	 * @url GET /byaccounts
	 *
	 * @throws RestException 400 Invalid parameter
	 * @throws RestException 403 Access denied
	 * @throws RestException 503 Database read error
	 */
	public function getByAccounts($account_ids = '', $sortfield = 't.rowid', $sortorder = 'ASC', $limit = 100, $page = 0, $thirdparty_ids = '', $status = '', $sqlfilters = '', $properties = '')
	{
		$this->assertInvoiceApiAccess();
		if (!is_string($account_ids) || !preg_match('/^[0-9]+(,[0-9]+)*$/', $account_ids)) {
			throw new RestException(400, 'account_ids is required and must contain comma-separated positive integers.');
		}
		if ($thirdparty_ids !== '' && !preg_match('/^[0-9]+(,[0-9]+)*$/', (string) $thirdparty_ids)) {
			throw new RestException(400, 'Invalid thirdparty_ids parameter.');
		}

		$service = $this->getInvoiceService();
		$sortfield = $service->validateSortField($sortfield);
		$sortorder = strtoupper(trim((string) $sortorder));
		if (!in_array($sortorder, array('ASC', 'DESC'), true)) {
			throw new RestException(400, 'Invalid sort order. Expected ASC or DESC.');
		}
		$maxLimit = max(1, (int) getDolGlobalInt('INVOICEPLUS_MAX_API_LIMIT', 1000));
		$limit = (int) $limit;
		if ($limit <= 0) {
			$limit = $maxLimit;
		}
		$limit = min($limit, $maxLimit);
		$page = max(0, (int) $page);

		$socids = !empty(DolibarrApiAccess::$user->socid)
			? (string) ((int) DolibarrApiAccess::$user->socid)
			: (string) $thirdparty_ids;
		$searchSale = 0;
		if (!DolibarrApiAccess::$user->hasRight('societe', 'client', 'voir') && $socids === '') {
			$searchSale = (int) DolibarrApiAccess::$user->id;
		}

		$sql = 'SELECT t.rowid';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'facture AS t';
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'facture_extrafields AS ef ON ef.fk_object = t.rowid';
		$sql .= " WHERE t.entity IN (".getEntity('invoice').")";
		$sql .= ' AND t.fk_account IN ('.$this->db->sanitize($account_ids).')';
		if ($socids !== '') {
			$sql .= ' AND t.fk_soc IN ('.$this->db->sanitize($socids).')';
		}
		if ($searchSale > 0) {
			$sql .= ' AND EXISTS (SELECT sc.fk_soc FROM '.MAIN_DB_PREFIX.'societe_commerciaux AS sc';
			$sql .= ' WHERE sc.fk_soc = t.fk_soc AND sc.fk_user = '.$searchSale.')';
		}
		switch ((string) $status) {
			case 'draft':
				$sql .= ' AND t.fk_statut IN (0)';
				break;
			case 'unpaid':
				$sql .= ' AND t.fk_statut IN (1)';
				break;
			case 'paid':
				$sql .= ' AND t.fk_statut IN (2)';
				break;
			case 'cancelled':
				$sql .= ' AND t.fk_statut IN (3)';
				break;
		}
		if ($sqlfilters !== '') {
			$service->validateSqlFilterAliases($sqlfilters);
			$errorMessage = '';
			$sql .= forgeSQLFromUniversalSearchCriteria($sqlfilters, $errorMessage);
			if ($errorMessage !== '') {
				throw new RestException(400, 'Error when validating parameter sqlfilters -> '.$errorMessage);
			}
		}
		$sql .= $this->db->order($sortfield, $sortorder);
		$sql .= $this->db->plimit($limit, $page * $limit);

		$result = $this->db->query($sql);
		if (!$result) {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_ERR);
			throw new RestException(503, 'Unable to retrieve invoices for the requested accounts.');
		}

		$responses = array();
		while ($object = $this->db->fetch_object($result)) {
			$nativeApi = new InvoicePlusNativeInvoicesApi();
			$response = $nativeApi->get((int) $object->rowid, 1);
			$response->linkedObjectsIds = null;
			$responses[] = $this->enrichAndFilterResponse($nativeApi, $response, $properties);
		}
		$this->db->free($result);

		return $responses;
	}

	/**
	 * Return customer invoices associated with a warehouse.
	 *
	 * Invoice objects are built by the installed native Invoices API. The
	 * endpoint therefore preserves its fields, payment values, contacts,
	 * access checks and cleanup rules, then InvoicePlus optionally adds
	 * InvoiceClosure data.
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
		$this->assertInvoiceApiAccess();
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

	/**
	 * Check the module switch and native invoice read permission.
	 *
	 * @return void
	 * @throws RestException
	 */
	private function assertInvoiceApiAccess()
	{
		$this->assertInvoicePlusApiEnabled();
		if (!DolibarrApiAccess::$user->hasRight('facture', 'lire')) {
			throw new RestException(403, 'Access forbidden.');
		}
	}

	/**
	 * Check the module and API switches shared by every InvoicePlus route.
	 *
	 * @return void
	 * @throws RestException
	 */
	private function assertInvoicePlusApiEnabled()
	{
		if (!isModEnabled('invoiceplus')) {
			throw new RestException(403, 'InvoicePlus module is disabled.');
		}
		if (!getDolGlobalInt('INVOICEPLUS_API_ENABLED', 1)) {
			throw new RestException(403, 'InvoicePlus API is disabled.');
		}
	}

	/**
	 * Lazily create the authenticated service.
	 *
	 * @return InvoicePlusInvoiceService
	 */
	private function getInvoiceService()
	{
		if ($this->invoiceService === null) {
			$this->invoiceService = new InvoicePlusInvoiceService($this->db, DolibarrApiAccess::$user);
		}

		return $this->invoiceService;
	}

	/**
	 * Lazily create the authenticated third-party service.
	 *
	 * @return InvoicePlusThirdPartyService
	 */
	private function getThirdPartyService()
	{
		if ($this->thirdPartyService === null) {
			$this->thirdPartyService = new InvoicePlusThirdPartyService($this->db, DolibarrApiAccess::$user);
		}

		return $this->thirdPartyService;
	}

	/**
	 * Add module-owned fields, then apply the native properties filter.
	 *
	 * @param InvoicePlusNativeInvoicesApi $nativeApi  Native API bridge
	 * @param Object                       $response   Native response
	 * @param string                       $properties Requested properties
	 * @return Object                                  Enriched response
	 */
	private function enrichAndFilterResponse($nativeApi, $response, $properties)
	{
		$invoiceId = (is_object($response) && !empty($response->id)) ? (int) $response->id : 0;
		$response = $this->getInvoiceService()->enrichWithClosureData($response, $invoiceId);

		return $nativeApi->filterResponseProperties($response, (string) $properties);
	}
}

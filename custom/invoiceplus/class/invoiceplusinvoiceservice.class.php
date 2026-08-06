<?php
/* Copyright (C) 2026 InvoicePlus module
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    custom/invoiceplus/class/invoiceplusinvoiceservice.class.php
 * \ingroup invoiceplus
 * \brief   Invoice selection and native API response service.
 */

if (!class_exists('DolibarrApiAccess')) {
	require_once DOL_DOCUMENT_ROOT.'/api/class/api_access.class.php';
}
if (!class_exists('DolibarrApi')) {
	require_once DOL_DOCUMENT_ROOT.'/api/class/api.class.php';
}
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/api_invoices.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/stock/class/entrepot.class.php';

use Luracast\Restler\RestException;

/**
 * Small bridge exposing the native protected properties filter.
 *
 * Keeping this bridge as a subclass of the installed Invoices API guarantees
 * that InvoicePlus follows Dolibarr's native filtering semantics.
 */
class InvoicePlusNativeInvoicesApi extends Invoices
{
	/**
	 * Apply the native API properties filter.
	 *
	 * @param Object $object     Cleaned native invoice object
	 * @param string $properties Comma-separated properties
	 * @return Object            Filtered object
	 */
	public function filterResponseProperties($object, $properties)
	{
		return $this->_filterObjectProperties($object, $properties);
	}
}

/**
 * Service used by the InvoicePlus REST endpoints.
 */
class InvoicePlusInvoiceService
{
	/** @var DoliDB */
	private $db;

	/** @var User */
	private $user;

	/** @var int */
	private $maxApiLimit;

	/** @var bool|null */
	private $hasBankAccountWarehouseField = null;

	/**
	 * @param DoliDB $db   Database handler
	 * @param User   $user Authenticated API user
	 */
	public function __construct($db, $user)
	{
		$this->db = $db;
		$this->user = $user;
		$this->maxApiLimit = max(1, (int) getDolGlobalInt('INVOICEPLUS_MAX_API_LIMIT', 1000));
	}

	/**
	 * Load and authorize a warehouse.
	 *
	 * @param int $warehouseId Warehouse id
	 * @return Entrepot         Loaded warehouse
	 * @throws RestException
	 */
	public function getWarehouse($warehouseId)
	{
		$warehouse = new Entrepot($this->db);
		$result = $warehouse->fetch((int) $warehouseId);
		if ($result < 0) {
			dol_syslog(__METHOD__.' '.$warehouse->error, LOG_ERR);
			throw new RestException(503, 'Unable to read warehouse.');
		}
		if ($result == 0) {
			throw new RestException(404, 'Warehouse not found.');
		}

		$this->validateWarehouseAccess($warehouse);

		return $warehouse;
	}

	/**
	 * Apply the same stock permission and resource checks as Warehouses::get().
	 *
	 * @param Entrepot $warehouse Loaded warehouse
	 * @return void
	 * @throws RestException
	 */
	public function validateWarehouseAccess($warehouse)
	{
		if (!$this->user->hasRight('stock', 'lire')) {
			throw new RestException(403, 'Access forbidden.');
		}

		$visibleEntities = array_map('intval', explode(',', (string) getEntity('stock')));
		if (!in_array((int) $warehouse->entity, $visibleEntities, true)) {
			throw new RestException(403, 'Access forbidden.');
		}

		if (!DolibarrApi::_checkAccessToResource('stock', (int) $warehouse->id, 'entrepot')) {
			throw new RestException(403, 'Access forbidden.');
		}
	}

	/**
	 * Select unique invoice ids associated with a warehouse.
	 *
	 * @param int   $warehouseId Warehouse id
	 * @param array $options     Validated endpoint options
	 * @return array             Array with ids, total, page and limit
	 * @throws RestException
	 */
	public function getInvoiceIdsByWarehouse($warehouseId, array $options = array())
	{
		$options = $this->applyOptionDefaults($options);
		$sortfield = $this->validateSortField($options['sortfield']);
		$sortorder = strtoupper(trim((string) $options['sortorder']));
		if (!in_array($sortorder, array('ASC', 'DESC'), true)) {
			throw new RestException(400, 'Invalid sort order. Expected ASC or DESC.');
		}

		$limit = (int) $options['limit'];
		if ($limit <= 0) {
			$limit = $this->maxApiLimit;
		}
		$limit = min($limit, $this->maxApiLimit);
		$page = max(0, (int) $options['page']);

		$conditionSql = $this->buildInvoiceConditions((int) $warehouseId, $options);
		$isClosureFilter = in_array($options['status'], array('closed', 'paid_not_closed'), true);
		if ($isClosureFilter && !$this->isInvoiceClosureModuleEnabled()) {
			throw new RestException(400, 'The status '.$options['status'].' requires the InvoiceClosure module.');
		}

		if ($isClosureFilter) {
			$sql = 'SELECT t.rowid FROM '.MAIN_DB_PREFIX.'facture AS t';
			$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'facture_extrafields AS ef ON (ef.fk_object = t.rowid)';
			$sql .= $conditionSql;
			$sql .= $this->db->order($sortfield, $sortorder);
			$allIds = $this->fetchIds($sql);
			$allIds = $this->filterIdsByClosureStatus($allIds, $options['status']);
			$total = count($allIds);
			$ids = array_slice($allIds, $page * $limit, $limit);

			return array('ids' => $ids, 'total' => $total, 'page' => $page, 'limit' => $limit);
		}

		$countSql = 'SELECT COUNT(DISTINCT t.rowid) AS invoice_count FROM '.MAIN_DB_PREFIX.'facture AS t';
		$countSql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'facture_extrafields AS ef ON (ef.fk_object = t.rowid)';
		$countSql .= $conditionSql;
		$total = $this->fetchCount($countSql);

		$sql = 'SELECT t.rowid FROM '.MAIN_DB_PREFIX.'facture AS t';
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'facture_extrafields AS ef ON (ef.fk_object = t.rowid)';
		$sql .= $conditionSql;
		$sql .= $this->db->order($sortfield, $sortorder);
		$sql .= $this->db->plimit($limit, $page * $limit);
		$ids = $this->fetchIds($sql);

		return array('ids' => $ids, 'total' => $total, 'page' => $page, 'limit' => $limit);
	}

	/**
	 * Return API invoices for a warehouse.
	 *
	 * @param Entrepot $warehouse Loaded and authorized warehouse
	 * @param array     $options   Endpoint options
	 * @return array               List or pagination envelope
	 * @throws RestException
	 */
	public function getInvoicesByWarehouse($warehouse, array $options = array())
	{
		$options = $this->applyOptionDefaults($options);
		$selection = $this->getInvoiceIdsByWarehouse((int) $warehouse->id, $options);
		$invoices = array();
		foreach ($selection['ids'] as $invoiceId) {
			$invoices[] = $this->buildInvoiceApiResponse((int) $invoiceId, $warehouse, $options);
		}

		if (!$options['pagination_data']) {
			return $invoices;
		}

		return $this->buildPaginationData(
			$invoices,
			$selection['total'],
			$selection['page'],
			$selection['limit']
		);
	}

	/**
	 * Build one response through the installed native Invoices API.
	 *
	 * @param int      $invoiceId Invoice id
	 * @param Entrepot $warehouse Applied warehouse filter
	 * @param array    $options   Endpoint options
	 * @return Object             Native-compatible response object
	 * @throws RestException
	 */
	public function buildInvoiceApiResponse($invoiceId, $warehouse, array $options = array())
	{
		$options = $this->applyOptionDefaults($options);

		// Public native API method: payment values, contacts, linked objects,
		// online payment URL, access checks, cleanup and InvoiceClosure enrichment.
		// Native cleanup deliberately unsets internal Facture properties. A fresh
		// native API object per invoice is therefore required for safe list usage.
		$nativeInvoicesApi = new InvoicePlusNativeInvoicesApi();
		$response = $nativeInvoicesApi->get((int) $invoiceId, 1);

		if (!$options['loadlinkedobjects'] && is_object($response)) {
			// Invoices::index() does not call fetchObjectLinked(). Its freshly fetched
			// Facture object exposes linkedObjectsIds as null.
			$response->linkedObjectsIds = null;
		}

		$response = $this->filterInvoiceLines(
			$response,
			(int) $warehouse->id,
			(bool) $options['withLines'],
			(bool) $options['warehouse_lines_only']
		);

		$response = $this->enrichWithClosureData($response);

		if (getDolGlobalInt('INVOICEPLUS_ADD_WAREHOUSE_METADATA', 1)) {
			$response->invoiceplus_warehouse_filter = array(
				'id' => (int) $warehouse->id,
				'ref' => (string) $warehouse->ref,
				'label' => (string) $warehouse->label,
			);
		}

		return $nativeInvoicesApi->filterResponseProperties($response, $options['properties']);
	}

	/**
	 * Keep or filter the already-cleaned response lines without recalculating totals.
	 *
	 * @param Object $response           Invoice response
	 * @param int    $warehouseId        Warehouse filter
	 * @param bool   $withLines          Return lines
	 * @param bool   $warehouseLinesOnly Keep only matching lines
	 * @return Object                    Updated response
	 */
	public function filterInvoiceLines($response, $warehouseId, $withLines, $warehouseLinesOnly)
	{
		if (!$withLines) {
			unset($response->lines);
			return $response;
		}
		if (!$warehouseLinesOnly || empty($response->lines) || !is_array($response->lines)) {
			return $response;
		}

		$filteredLines = array();
		foreach ($response->lines as $line) {
			if (isset($line->fk_warehouse) && (int) $line->fk_warehouse === (int) $warehouseId) {
				$filteredLines[] = $line;
			}
		}
		$response->lines = $filteredLines;

		return $response;
	}

	/**
	 * Preserve the native closure block or remove it when explicitly configured.
	 *
	 * The native Invoices API installed in Dolibarr 20.0.4 owns the exact format.
	 * InvoicePlus deliberately does not rebuild that data.
	 *
	 * @param Object $response Native response
	 * @return Object          Response respecting InvoicePlus configuration
	 */
	public function enrichWithClosureData($response)
	{
		if (!getDolGlobalInt('INVOICEPLUS_LOAD_CLOSURE_DATA', 1)) {
			unset($response->invoiceclosure);
		}

		return $response;
	}

	/**
	 * Test whether InvoiceClosure is active.
	 *
	 * @return bool True when active
	 */
	public function isInvoiceClosureModuleEnabled()
	{
		return isModEnabled('invoiceclosure');
	}

	/**
	 * Normalize a URL boolean without PHP's "false" casting trap.
	 *
	 * @param mixed  $value         Input value
	 * @param bool   $default       Default for null or empty string
	 * @param string $parameterName Parameter name for errors
	 * @return bool                 Normalized boolean
	 * @throws RestException
	 */
	public function normalizeBooleanParameter($value, $default = false, $parameterName = 'parameter')
	{
		if ($value === null || $value === '') {
			return (bool) $default;
		}
		if (is_bool($value)) {
			return $value;
		}
		if ($value === 1 || $value === '1') {
			return true;
		}
		if ($value === 0 || $value === '0') {
			return false;
		}
		if (is_string($value)) {
			$normalized = strtolower(trim($value));
			if ($normalized === 'true') {
				return true;
			}
			if ($normalized === 'false') {
				return false;
			}
		}

		throw new RestException(400, 'Invalid boolean value for '.$parameterName.'. Expected true, false, 1 or 0.');
	}

	/**
	 * Validate and return a whitelisted sort field.
	 *
	 * @param string $sortfield Requested sort field
	 * @return string           Safe SQL field
	 * @throws RestException
	 */
	public function validateSortField($sortfield)
	{
		$allowed = array(
			't.rowid',
			't.ref',
			't.fk_soc',
			't.type',
			't.fk_statut',
			't.paye',
			't.datef',
			't.date_lim_reglement',
			't.datec',
			't.tms',
			't.total_ht',
			't.total_tva',
			't.total_ttc',
			't.multicurrency_total_ht',
			't.multicurrency_total_tva',
			't.multicurrency_total_ttc',
		);
		$sortfield = trim((string) $sortfield);
		if (!in_array($sortfield, $allowed, true)) {
			throw new RestException(400, 'Invalid sort field.');
		}

		return $sortfield;
	}

	/**
	 * Create the documented pagination envelope used because Dolibarr 20.0.4
	 * native Invoices::index() has no pagination_data parameter.
	 *
	 * @param array $data  Invoice data
	 * @param int   $total Total matching invoices
	 * @param int   $page  Zero-based page
	 * @param int   $limit Effective limit
	 * @return array       Pagination envelope
	 */
	public function buildPaginationData(array $data, $total, $page, $limit)
	{
		$total = max(0, (int) $total);
		$limit = max(1, (int) $limit);

		return array(
			'data' => $data,
			'pagination' => array(
				'total' => $total,
				'page' => max(0, (int) $page),
				'page_count' => $total === 0 ? 0 : (int) ceil($total / $limit),
				'limit' => $limit,
			),
		);
	}

	/**
	 * Build WHERE conditions shared by count and id queries.
	 *
	 * @param int   $warehouseId Warehouse id
	 * @param array $options     Endpoint options
	 * @return string            SQL beginning with WHERE
	 * @throws RestException
	 */
	private function buildInvoiceConditions($warehouseId, array $options)
	{
		$sql = ' WHERE t.entity IN ('.getEntity('invoice').')';
		$sql .= ' AND '.$this->buildWarehouseMembershipCondition((int) $warehouseId);

		$socids = '';
		if (!empty($this->user->socid)) {
			$socids = (string) ((int) $this->user->socid);
		} elseif (trim((string) $options['thirdparty_ids']) !== '') {
			$socids = trim((string) $options['thirdparty_ids']);
			if (!preg_match('/^[0-9]+(,[0-9]+)*$/', $socids)) {
				throw new RestException(400, 'Invalid thirdparty_ids parameter.');
			}
		}
		if ($socids !== '') {
			$sql .= ' AND t.fk_soc IN ('.$this->db->sanitize($socids).')';
		}

		$searchSale = 0;
		if (!$this->user->hasRight('societe', 'client', 'voir') && $socids === '') {
			$searchSale = (int) $this->user->id;
		}
		if ($searchSale > 0) {
			$sql .= ' AND EXISTS (';
			$sql .= 'SELECT sc.fk_soc FROM '.MAIN_DB_PREFIX.'societe_commerciaux AS sc';
			$sql .= ' WHERE sc.fk_soc = t.fk_soc AND sc.fk_user = '.((int) $searchSale);
			$sql .= ')';
		}

		switch ($options['status']) {
			case 'draft':
				$sql .= ' AND t.fk_statut IN (0)';
				break;
			case 'unpaid':
				$sql .= ' AND t.fk_statut IN (1)';
				break;
			case 'paid':
			case 'closed':
			case 'paid_not_closed':
				$sql .= ' AND t.fk_statut IN (2)';
				break;
			case 'cancelled':
				$sql .= ' AND t.fk_statut IN (3)';
				break;
		}

		$dateStart = null;
		$dateEnd = null;
		if ($options['date_start'] !== '') {
			$dateStart = $this->parseDate($options['date_start'], false);
			$sql .= " AND t.datef >= '".$this->db->idate($dateStart)."'";
		}
		if ($options['date_end'] !== '') {
			$dateEnd = $this->parseDate($options['date_end'], true);
			$sql .= " AND t.datef <= '".$this->db->idate($dateEnd)."'";
		}
		if ($dateStart !== null && $dateEnd !== null && $dateStart > $dateEnd) {
			throw new RestException(400, 'date_start must not be later than date_end.');
		}

		if ($options['sqlfilters'] !== '') {
			$this->validateSqlFilterAliases($options['sqlfilters']);
			$errorMessage = '';
			$sql .= forgeSQLFromUniversalSearchCriteria($options['sqlfilters'], $errorMessage);
			if ($errorMessage !== '') {
				throw new RestException(400, 'Error when validating parameter sqlfilters -> '.$errorMessage);
			}
		}

		return $sql;
	}

	/**
	 * Build the warehouse-membership predicate.
	 *
	 * An explicit facturedet.fk_warehouse assignment is authoritative. Legacy
	 * invoices whose lines are all unassigned may be resolved through standard
	 * stock movements, PosNova tickets, TakePOS terminal configuration or the
	 * existing bank-account warehouse extrafield.
	 *
	 * @param int $warehouseId Warehouse id
	 * @return string          Parenthesized SQL predicate
	 */
	private function buildWarehouseMembershipCondition($warehouseId)
	{
		$warehouseId = (int) $warehouseId;
		$direct = 'EXISTS (';
		$direct .= 'SELECT 1 FROM '.MAIN_DB_PREFIX.'facturedet AS fd';
		$direct .= ' WHERE fd.fk_facture = t.rowid AND fd.fk_warehouse = '.$warehouseId;
		$direct .= ')';

		if (!getDolGlobalInt('INVOICEPLUS_ENABLE_WAREHOUSE_FALLBACKS', 1)) {
			return '('.$direct.')';
		}

		$fallbacks = array();

		// Native invoice validation records the invoice as stock-movement origin.
		$fallbacks[] = 'EXISTS ('
			.'SELECT 1 FROM '.MAIN_DB_PREFIX.'stock_mouvement AS sm'
			.' WHERE sm.fk_origin = t.rowid'
			." AND sm.origintype IN ('facture', 'invoice')"
			.' AND sm.fk_entrepot = '.$warehouseId
			.')';

		// PosNova stores the immutable warehouse on the terminal configuration.
		if (isModEnabled('posnova')) {
			$fallbacks[] = 'EXISTS ('
				.'SELECT 1 FROM '.MAIN_DB_PREFIX.'pos_ticket AS pt'
				.' INNER JOIN '.MAIN_DB_PREFIX.'pos_config AS pc ON pc.rowid = pt.fk_pos AND pc.entity = pt.entity'
				.' WHERE pt.fk_facture = t.rowid AND pt.entity = t.entity'
				.' AND pc.fk_warehouse = '.$warehouseId
				.')';
		}

		// TakePOS keeps its forced warehouse in CASHDESK_ID_WAREHOUSE{terminal}.
		if (isModEnabled('takepos')) {
			$fallbacks[] = 'EXISTS ('
				.'SELECT 1 FROM '.MAIN_DB_PREFIX.'const AS tc'
				." WHERE t.module_source = 'takepos'"
				." AND tc.name = CONCAT('CASHDESK_ID_WAREHOUSE', t.pos_source)"
				.' AND tc.entity IN (0, t.entity)'
				." AND tc.value = '".$warehouseId."'"
				.')';
		}

		// BankAudit/PosNova already provide this explicit cash-account mapping.
		if ($this->hasBankAccountWarehouseField()) {
			$fallbacks[] = 'EXISTS ('
				.'SELECT 1 FROM '.MAIN_DB_PREFIX.'bank_account_extrafields AS bae'
				.' WHERE bae.fk_object = t.fk_account'
				.' AND bae.warehouse = '.$warehouseId
				.')';
		}

		$noExplicitWarehouse = 'NOT EXISTS (';
		$noExplicitWarehouse .= 'SELECT 1 FROM '.MAIN_DB_PREFIX.'facturedet AS assigned_fd';
		$noExplicitWarehouse .= ' WHERE assigned_fd.fk_facture = t.rowid';
		$noExplicitWarehouse .= ' AND COALESCE(assigned_fd.fk_warehouse, 0) > 0';
		$noExplicitWarehouse .= ')';

		return '('.$direct.' OR ('.$noExplicitWarehouse.' AND ('.implode(' OR ', $fallbacks).')))';
	}

	/**
	 * Detect the optional bank_account_extrafields.warehouse column through
	 * Dolibarr's extrafield metadata, avoiding a reference to a missing column.
	 *
	 * @return bool True when the configured extrafield exists
	 */
	private function hasBankAccountWarehouseField()
	{
		if ($this->hasBankAccountWarehouseField !== null) {
			return $this->hasBankAccountWarehouseField;
		}

		$this->hasBankAccountWarehouseField = false;
		$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'extrafields';
		$sql .= " WHERE elementtype = 'bank_account' AND name = 'warehouse'";
		$sql .= $this->db->plimit(1);
		$result = $this->db->query($sql);
		if (!$result) {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_WARNING);
			return false;
		}

		$this->hasBankAccountWarehouseField = ($this->db->num_rows($result) > 0);
		$this->db->free($result);

		return $this->hasBankAccountWarehouseField;
	}

	/**
	 * Validate a strict YYYY-MM-DD date and return a local timestamp.
	 *
	 * @param string $value    Date value
	 * @param bool   $endOfDay Use 23:59:59
	 * @return int             Timestamp
	 * @throws RestException
	 */
	private function parseDate($value, $endOfDay)
	{
		$value = (string) $value;
		$date = DateTime::createFromFormat('!Y-m-d', $value);
		$errors = DateTime::getLastErrors();
		if (
			!$date
			|| ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
			|| $date->format('Y-m-d') !== $value
		) {
			throw new RestException(400, 'Invalid date format. Expected YYYY-MM-DD.');
		}
		if ($endOfDay) {
			$date->setTime(23, 59, 59);
		}

		return $date->getTimestamp();
	}

	/**
	 * Limit universal-search aliases to the two aliases provided by this query.
	 * Field/operator/value validation remains delegated to Dolibarr's native
	 * forgeSQLFromUniversalSearchCriteria().
	 *
	 * @param string $sqlfilters Universal-search expression
	 * @return void
	 * @throws RestException
	 */
	private function validateSqlFilterAliases($sqlfilters)
	{
		$allowedInvoiceFields = array(
			'rowid', 'ref', 'entity', 'ref_ext', 'ref_client', 'type', 'subtype', 'fk_soc',
			'datec', 'datef', 'date_pointoftax', 'date_valid', 'tms', 'date_closing', 'paye',
			'remise_percent', 'remise_absolue', 'remise', 'close_code', 'close_missing_amount',
			'close_note', 'total_tva', 'localtax1', 'localtax2', 'revenuestamp', 'total_ht',
			'total_ttc', 'fk_statut', 'fk_user_author', 'fk_user_modif', 'fk_user_valid',
			'fk_user_closing', 'module_source', 'pos_source', 'fk_fac_rec_source',
			'fk_facture_source', 'fk_projet', 'increment', 'fk_account', 'fk_currency',
			'fk_cond_reglement', 'fk_mode_reglement', 'date_lim_reglement', 'note_private',
			'note_public', 'model_pdf', 'last_main_doc', 'fk_incoterms', 'location_incoterms',
			'fk_transport_mode', 'prorata_discount', 'situation_cycle_ref', 'situation_counter',
			'situation_final', 'retained_warranty', 'retained_warranty_date_limit',
			'retained_warranty_fk_cond_reglement', 'import_key', 'extraparams',
			'fk_multicurrency', 'multicurrency_code', 'multicurrency_tx',
			'multicurrency_total_ht', 'multicurrency_total_tva', 'multicurrency_total_ttc',
		);
		$matches = array();
		if (preg_match_all('/\(+\s*([a-zA-Z][a-zA-Z0-9_]*)\.([a-zA-Z][a-zA-Z0-9_]*)\s*:/', $sqlfilters, $matches)) {
			foreach ($matches[1] as $index => $alias) {
				if (!in_array($alias, array('t', 'ef'), true)) {
					throw new RestException(400, 'Invalid sqlfilters alias. Only t and ef are allowed.');
				}
				if ($alias === 't' && !in_array($matches[2][$index], $allowedInvoiceFields, true)) {
					throw new RestException(400, 'Invalid invoice field in sqlfilters.');
				}
			}
		}
	}

	/**
	 * Filter paid invoice ids through InvoiceClosure's public API.
	 *
	 * @param int[]  $ids    Candidate ids
	 * @param string $status closed or paid_not_closed
	 * @return int[]         Filtered ids
	 * @throws RestException
	 */
	private function filterIdsByClosureStatus(array $ids, $status)
	{
		if (!$this->isInvoiceClosureModuleEnabled()) {
			throw new RestException(400, 'The status '.$status.' requires the InvoiceClosure module.');
		}
		if (!$this->user->hasRight('invoiceclosure', 'read')) {
			throw new RestException(403, 'Access forbidden.');
		}
		if (!dol_include_once('/invoiceclosure/class/invoiceclosure.class.php')) {
			dol_syslog(__METHOD__.' unable to load InvoiceClosure business class', LOG_ERR);
			throw new RestException(500, 'Unable to load invoice closure information.');
		}

		$closure = new InvoiceClosure($this->db);
		$wantClosed = ($status === 'closed');
		$filtered = array();
		foreach ($ids as $invoiceId) {
			$closureStatus = $closure->getClosureStatus((int) $invoiceId);
			if ($closureStatus < 0) {
				dol_syslog(__METHOD__.' '.$closure->error, LOG_ERR);
				throw new RestException(503, 'Unable to read invoice closure information.');
			}
			$isClosed = ((int) $closureStatus === InvoiceClosure::STATUS_CLOSED);
			if (($wantClosed && $isClosed) || (!$wantClosed && !$isClosed)) {
				$filtered[] = (int) $invoiceId;
			}
		}

		return $filtered;
	}

	/**
	 * Fetch ids from a completed query.
	 *
	 * @param string $sql SQL query
	 * @return int[]       Invoice ids
	 * @throws RestException
	 */
	private function fetchIds($sql)
	{
		$result = $this->db->query($sql);
		if (!$result) {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_ERR);
			throw new RestException(503, 'Unable to retrieve invoice list.');
		}

		$ids = array();
		while ($object = $this->db->fetch_object($result)) {
			$ids[] = (int) $object->rowid;
		}
		$this->db->free($result);

		return $ids;
	}

	/**
	 * Fetch an invoice count.
	 *
	 * @param string $sql Count query
	 * @return int         Count
	 * @throws RestException
	 */
	private function fetchCount($sql)
	{
		$result = $this->db->query($sql);
		if (!$result) {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_ERR);
			throw new RestException(503, 'Unable to count invoices.');
		}
		$object = $this->db->fetch_object($result);
		$total = $object ? (int) $object->invoice_count : 0;
		$this->db->free($result);

		return $total;
	}

	/**
	 * Merge endpoint defaults and normalize scalar types.
	 *
	 * @param array $options Supplied options
	 * @return array         Complete options
	 */
	private function applyOptionDefaults(array $options)
	{
		$defaults = array(
			'sortfield' => 't.rowid',
			'sortorder' => 'DESC',
			'limit' => 100,
			'page' => 0,
			'thirdparty_ids' => '',
			'status' => '',
			'sqlfilters' => '',
			'properties' => '',
			'pagination_data' => false,
			'loadlinkedobjects' => false,
			'date_start' => '',
			'date_end' => '',
			'withLines' => true,
			'warehouse_lines_only' => false,
		);

		return array_merge($defaults, $options);
	}
}

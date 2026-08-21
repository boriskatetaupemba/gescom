<?php
/* Copyright (C) 2026 InvoicePlus module
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    custom/invoiceplus/class/invoiceplusproductlistservice.class.php
 * \ingroup invoiceplus
 * \brief   Native-compatible product lists enriched with the applicable price.
 */

if (!class_exists('DolibarrApiAccess')) {
	require_once DOL_DOCUMENT_ROOT.'/api/class/api_access.class.php';
}
if (!class_exists('DolibarrApi')) {
	require_once DOL_DOCUMENT_ROOT.'/api/class/api.class.php';
}
require_once DOL_DOCUMENT_ROOT.'/product/class/api_products.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
dol_include_once('/invoiceplus/class/invoicepluspricelevelservice.class.php');

use Luracast\Restler\RestException;

/**
 * Bridge exposing the protected cleanup and property filter of the native
 * Products API, so InvoicePlus keeps Dolibarr's own response semantics.
 */
class InvoicePlusNativeProductsApi extends Products
{
	/**
	 * Apply the native product cleanup.
	 *
	 * @param Product $object Loaded product
	 * @return Object          Cleaned object
	 */
	public function cleanProduct($object)
	{
		return $this->_cleanObjectDatas($object);
	}

	/**
	 * Apply the native properties filter.
	 *
	 * @param Object $object     Cleaned and enriched object
	 * @param string $properties Comma-separated properties
	 * @return Object             Filtered object
	 */
	public function filterResponseProperties($object, $properties)
	{
		return $this->_filterObjectProperties($object, (string) $properties);
	}
}

/**
 * Build the two InvoicePlus product lists.
 *
 * The list keeps the native product representation and only replaces the
 * commercial price fields by the values of the applied native price line,
 * then adds the four documented resolution properties.
 */
class InvoicePlusProductListService
{
	/** Refuse the whole page when a product has no applicable price. */
	const ON_MISSING_PRICE_ERROR = 'error';

	/** Omit the products the caller explicitly accepts to lose. */
	const ON_MISSING_PRICE_SKIP = 'skip';

	/** @var DoliDB */
	private $db;

	/** @var User */
	private $user;

	/** @var int */
	private $maxApiLimit;

	/** @var InvoicePlusPriceLevelService */
	private $priceLevelService;

	/**
	 * @param DoliDB $db   Database handler
	 * @param User   $user Authenticated API user
	 */
	public function __construct($db, $user)
	{
		$this->db = $db;
		$this->user = $user;
		$this->maxApiLimit = max(1, (int) getDolGlobalInt('INVOICEPLUS_MAX_API_LIMIT', 1000));
		$this->priceLevelService = new InvoicePlusPriceLevelService($db, $user);
	}

	/**
	 * Return the price-level service used by this list service.
	 *
	 * @return InvoicePlusPriceLevelService Shared resolver
	 */
	public function getPriceLevelService()
	{
		return $this->priceLevelService;
	}

	/**
	 * List products priced for one customer.
	 *
	 * The customer level is used when it exists, otherwise N1. No warehouse is
	 * accepted and none is inferred.
	 *
	 * @param int   $customerId Third-party id
	 * @param array $options    Validated endpoint options
	 * @return array             Product objects or pagination envelope
	 * @throws RestException
	 */
	public function getProductsForCustomer($customerId, array $options)
	{
		$this->assertProductReadAccess();
		$this->priceLevelService->assertMultiPriceEnabled();
		$customer = $this->priceLevelService->getAccessibleThirdParty($customerId);
		$level = $this->priceLevelService->getCustomerPriceLevel((int) $customer->id);
		$resolution = ($level === null)
			? array('requested_price_level' => 1, 'price_level_source' => InvoicePlusPriceLevelService::SOURCE_DEFAULT)
			: array('requested_price_level' => $level, 'price_level_source' => InvoicePlusPriceLevelService::SOURCE_CUSTOMER);

		return $this->buildProductList($resolution, $options);
	}

	/**
	 * List products priced for one warehouse.
	 *
	 * @param int   $warehouseId Warehouse id
	 * @param array $options     Validated endpoint options
	 * @return array              Product objects or pagination envelope
	 * @throws RestException
	 */
	public function getProductsForWarehouse($warehouseId, array $options)
	{
		$this->assertProductReadAccess();
		$this->priceLevelService->assertMultiPriceEnabled();
		if (!$this->user->hasRight('stock', 'lire')) {
			throw new RestException(403, 'Access forbidden.');
		}
		$warehouse = $this->priceLevelService->getAccessibleWarehouse($warehouseId);
		$level = $this->priceLevelService->getWarehousePriceLevelFromObject($warehouse);
		$resolution = ($level === null)
			? array('requested_price_level' => 1, 'price_level_source' => InvoicePlusPriceLevelService::SOURCE_DEFAULT)
			: array('requested_price_level' => $level, 'price_level_source' => InvoicePlusPriceLevelService::SOURCE_WAREHOUSE);

		return $this->buildProductList($resolution, $options);
	}

	/**
	 * Select, load, price and format one page of products.
	 *
	 * @param array $resolution Requested level and its source
	 * @param array $options    Endpoint options
	 * @return array             Product objects or pagination envelope
	 * @throws RestException
	 */
	private function buildProductList(array $resolution, array $options)
	{
		$options = $this->applyOptionDefaults($options);
		$sortfield = $this->validateSortField($options['sortfield']);
		$sortorder = $this->validateSortOrder($options['sortorder']);
		$limit = $this->validateLimit($options['limit']);
		$page = $this->validatePage($options['page']);
		$mode = $this->validateMode($options['mode']);
		$category = $this->validateNonNegativeInteger($options['category'], 'category');
		$variantFilter = $this->validateVariantFilter($options['variant_filter']);
		$paginationData = $this->normalizeBooleanParameter($options['pagination_data'], false, 'pagination_data');
		$includeStockData = $this->normalizeBooleanParameter($options['includestockdata'], false, 'includestockdata');
		$onMissingPrice = $this->validateOnMissingPrice($options['on_missing_price']);

		$conditions = $this->buildProductConditions($mode, $category, $variantFilter, (string) $options['sqlfilters']);

		$sql = 'SELECT t.rowid FROM '.MAIN_DB_PREFIX.'product AS t';
		$sql .= $this->buildProductJoins($category);
		$sql .= $conditions;
		$sql .= $this->db->order($sortfield, $sortorder);
		if ($sortfield !== 't.rowid') {
			$sql .= ', t.rowid '.$sortorder;
		}
		$sql .= $this->db->plimit($limit, $page * $limit);

		$result = $this->db->query($sql);
		if (!$result) {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_ERR);
			throw new RestException(503, 'Unable to retrieve the product list.');
		}
		$productIds = array();
		while ($row = $this->db->fetch_object($result)) {
			$productIds[] = (int) $row->rowid;
		}
		$this->db->free($result);

		$requestedLevel = (int) $resolution['requested_price_level'];
		// One grouped query for the whole page; never one price query per product.
		$priceRows = $this->priceLevelService->fetchCurrentPriceRows($productIds, array($requestedLevel, 1));

		$nativeApi = new InvoicePlusNativeProductsApi();
		$responses = array();
		foreach ($productIds as $productId) {
			$product = new Product($this->db);
			// The sixth argument skips the native per-level price loading, which
			// would otherwise run PRODUIT_MULTIPRICES_LIMIT queries per product.
			$loaded = $product->fetch($productId, '', '', '', 0, 1);
			if ($loaded < 0) {
				dol_syslog(__METHOD__.' '.$product->error, LOG_ERR);
				throw new RestException(503, 'Unable to load a product of the requested page.');
			}
			if ($loaded == 0) {
				continue;
			}
			if ($includeStockData && $this->user->hasRight('stock', 'lire')) {
				$this->loadStockData($product);
			}

			$selection = InvoicePlusPriceLevelService::selectPriceRow(
				isset($priceRows[$productId]) ? $priceRows[$productId] : array(),
				$requestedLevel
			);
			if ($selection === null) {
				// Nothing is masked by default: a product without any usable
				// price is a business error, never an invented zero.
				if ($onMissingPrice !== self::ON_MISSING_PRICE_SKIP) {
					throw new RestException(422, 'No applicable price for product '.$productId
						.' ('.$product->ref.'): '
						.InvoicePlusPriceLevelService::describeMissingPrice($requestedLevel));
				}
				dol_syslog(
					__METHOD__.' product '.$productId.' skipped on caller request: no price for level '
					.$requestedLevel.' nor level 1',
					LOG_WARNING
				);
				continue;
			}
			$metadata = InvoicePlusPriceLevelService::buildPriceMetadata(
				$selection,
				$requestedLevel,
				$resolution['price_level_source']
			);

			$response = $nativeApi->cleanProduct($product);
			$response = $this->applyResolvedPrice($response, $metadata);
			// The properties filter runs last so consumers can request or
			// exclude the four InvoicePlus resolution properties.
			$responses[] = $nativeApi->filterResponseProperties($response, (string) $options['properties']);
		}

		if (!$paginationData) {
			return $responses;
		}

		$total = $this->countProducts($category, $conditions);

		return array(
			'data' => $responses,
			'pagination' => array(
				'total' => $total,
				'page' => $page,
				'page_count' => $total === 0 ? 0 : (int) ceil($total / $limit),
				'limit' => $limit,
			),
		);
	}

	/**
	 * Replace the commercial price fields by the applied price line.
	 *
	 * Every replaced value comes from the same line, so a level price is never
	 * mixed with the tax data of another level. Nothing else is recalculated.
	 *
	 * @param Object $response Cleaned native product object
	 * @param array  $metadata Applied price metadata
	 * @return Object           Enriched response
	 */
	public function applyResolvedPrice($response, array $metadata)
	{
		if (!is_object($response)) {
			return $response;
		}

		$response->price = $metadata['price'];
		$response->price_ttc = $metadata['price_ttc'];
		$response->price_min = $metadata['price_min'];
		$response->price_min_ttc = $metadata['price_min_ttc'];
		$response->price_base_type = $metadata['price_base_type'];
		$response->price_label = $metadata['price_label'];
		$response->tva_tx = $metadata['tva_tx'];
		$response->default_vat_code = $metadata['default_vat_code'];
		$response->localtax1_tx = $metadata['localtax1_tx'];
		$response->localtax1_type = $metadata['localtax1_type'];
		$response->localtax2_tx = $metadata['localtax2_tx'];
		$response->localtax2_type = $metadata['localtax2_type'];

		$response->requested_price_level = $metadata['requested_price_level'];
		$response->applied_price_level = $metadata['applied_price_level'];
		$response->price_level_source = $metadata['price_level_source'];
		$response->price_fallback = $metadata['price_fallback'];

		return $response;
	}

	/**
	 * Count the products matching the current selection.
	 *
	 * @param int    $category   Category filter
	 * @param string $conditions SQL conditions beginning with WHERE
	 * @return int                Total matching products
	 * @throws RestException
	 */
	private function countProducts($category, $conditions)
	{
		$sql = 'SELECT COUNT(DISTINCT t.rowid) AS product_count FROM '.MAIN_DB_PREFIX.'product AS t';
		$sql .= $this->buildProductJoins($category);
		$sql .= $conditions;

		$result = $this->db->query($sql);
		if (!$result) {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_ERR);
			throw new RestException(503, 'Unable to count products.');
		}
		$row = $this->db->fetch_object($result);
		$total = $row ? (int) $row->product_count : 0;
		$this->db->free($result);

		return $total;
	}

	/**
	 * Build the joins shared by the selection and count queries.
	 *
	 * @param int $category Category filter
	 * @return string        SQL join clause
	 */
	private function buildProductJoins($category)
	{
		$sql = ' LEFT JOIN '.MAIN_DB_PREFIX.'product_extrafields AS ef ON ef.fk_object = t.rowid';
		if ((int) $category > 0) {
			$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'categorie_product AS c ON c.fk_product = t.rowid';
			$sql .= ' AND c.fk_categorie = '.((int) $category);
		}

		return $sql;
	}

	/**
	 * Build the conditions shared by the selection and count queries.
	 *
	 * @param int    $mode          0 all, 1 products, 2 services
	 * @param int    $category      Category filter
	 * @param int    $variantFilter Native variant filter
	 * @param string $sqlfilters    Universal Search criteria
	 * @return string                SQL beginning with WHERE
	 * @throws RestException
	 */
	private function buildProductConditions($mode, $category, $variantFilter, $sqlfilters)
	{
		$sql = ' WHERE t.entity IN ('.getEntity('product').')';

		if ($variantFilter == 1) {
			$sql .= ' AND t.rowid NOT IN (SELECT DISTINCT fk_product_parent FROM '.MAIN_DB_PREFIX.'product_attribute_combination)';
			$sql .= ' AND t.rowid NOT IN (SELECT DISTINCT fk_product_child FROM '.MAIN_DB_PREFIX.'product_attribute_combination)';
		} elseif ($variantFilter == 2) {
			$sql .= ' AND t.rowid IN (SELECT DISTINCT fk_product_parent FROM '.MAIN_DB_PREFIX.'product_attribute_combination)';
		} elseif ($variantFilter == 3) {
			$sql .= ' AND t.rowid IN (SELECT DISTINCT fk_product_child FROM '.MAIN_DB_PREFIX.'product_attribute_combination)';
		}

		if ($mode == 1) {
			$sql .= ' AND t.fk_product_type = 0';
		} elseif ($mode == 2) {
			$sql .= ' AND t.fk_product_type = 1';
		}

		if ($sqlfilters !== '') {
			$this->validateSqlFilterAliases($sqlfilters);
			$errorMessage = '';
			$sql .= forgeSQLFromUniversalSearchCriteria($sqlfilters, $errorMessage);
			if ($errorMessage !== '') {
				throw new RestException(400, 'Error when validating parameter sqlfilters -> '.$errorMessage);
			}
		}

		return $sql;
	}

	/**
	 * Load native stock data and drop the non-serializable handlers.
	 *
	 * @param Product $product Loaded product
	 * @return void
	 */
	private function loadStockData($product)
	{
		$product->load_stock();
		if (!is_array($product->stock_warehouse)) {
			return;
		}
		foreach ($product->stock_warehouse as $warehouseKey => $warehouseStock) {
			if (isset($warehouseStock->detail_batch) && is_array($warehouseStock->detail_batch)) {
				foreach ($warehouseStock->detail_batch as $batchKey => $batch) {
					unset($product->stock_warehouse[$warehouseKey]->detail_batch[$batchKey]->db);
				}
			}
		}
	}

	/**
	 * Validate and return a whitelisted product sort field.
	 *
	 * @param string $sortfield Requested sort field
	 * @return string            Safe SQL field
	 * @throws RestException
	 */
	public function validateSortField($sortfield)
	{
		$allowed = array(
			't.rowid',
			't.ref',
			't.ref_ext',
			't.label',
			't.barcode',
			't.datec',
			't.tms',
			't.price',
			't.price_ttc',
			't.tosell',
			't.tobuy',
			't.fk_product_type',
			't.stock',
		);
		$sortfield = trim((string) $sortfield);
		if (!in_array($sortfield, $allowed, true)) {
			throw new RestException(400, 'Invalid product sort field.');
		}

		return $sortfield;
	}

	/**
	 * @param mixed $sortorder Requested sort order
	 * @return string           ASC or DESC
	 * @throws RestException
	 */
	public function validateSortOrder($sortorder)
	{
		$sortorder = strtoupper(trim((string) $sortorder));
		if (!in_array($sortorder, array('ASC', 'DESC'), true)) {
			throw new RestException(400, 'Invalid sort order. Expected ASC or DESC.');
		}

		return $sortorder;
	}

	/**
	 * @param mixed $limit Requested page size
	 * @return int          Effective page size
	 * @throws RestException
	 */
	public function validateLimit($limit)
	{
		if (!$this->isIntegerValue($limit)) {
			throw new RestException(400, 'Invalid limit. Expected an integer.');
		}
		if ((int) $limit <= 0) {
			return $this->maxApiLimit;
		}

		return min((int) $limit, $this->maxApiLimit);
	}

	/**
	 * @param mixed $page Requested zero-based page
	 * @return int         Validated page
	 * @throws RestException
	 */
	public function validatePage($page)
	{
		if (!$this->isIntegerValue($page) || (int) $page < 0) {
			throw new RestException(400, 'Invalid page. Expected a non-negative integer.');
		}

		return (int) $page;
	}

	/**
	 * @param mixed $mode 0 all, 1 products, 2 services
	 * @return int         Validated mode
	 * @throws RestException
	 */
	public function validateMode($mode)
	{
		if (!$this->isIntegerValue($mode) || !in_array((int) $mode, array(0, 1, 2), true)) {
			throw new RestException(400, 'Invalid mode. Expected 0, 1 or 2.');
		}

		return (int) $mode;
	}

	/**
	 * @param mixed $variantFilter Native variant filter
	 * @return int                  Validated filter
	 * @throws RestException
	 */
	public function validateVariantFilter($variantFilter)
	{
		if (!$this->isIntegerValue($variantFilter) || !in_array((int) $variantFilter, array(0, 1, 2, 3), true)) {
			throw new RestException(400, 'Invalid variant_filter. Expected 0, 1, 2 or 3.');
		}

		return (int) $variantFilter;
	}

	/**
	 * Validate the behaviour requested for products without applicable price.
	 *
	 * The default refuses the request with HTTP 422 and names the product. The
	 * skip mode is an explicit caller decision, is logged, and makes the
	 * pagination total larger than the returned page.
	 *
	 * @param mixed $value Requested behaviour
	 * @return string       error or skip
	 * @throws RestException
	 */
	public function validateOnMissingPrice($value)
	{
		if ($value === null || $value === '') {
			return self::ON_MISSING_PRICE_ERROR;
		}
		$candidate = strtolower(trim((string) $value));
		if (!in_array($candidate, array(self::ON_MISSING_PRICE_ERROR, self::ON_MISSING_PRICE_SKIP), true)) {
			throw new RestException(400, 'Invalid on_missing_price. Expected error or skip.');
		}

		return $candidate;
	}

	/**
	 * @param mixed  $value         Candidate value
	 * @param string $parameterName Parameter name for errors
	 * @return int                   Validated value
	 * @throws RestException
	 */
	public function validateNonNegativeInteger($value, $parameterName)
	{
		if (!$this->isIntegerValue($value) || (int) $value < 0) {
			throw new RestException(400, 'Invalid '.$parameterName.'. Expected a non-negative integer.');
		}

		return (int) $value;
	}

	/**
	 * Normalize a URL boolean without PHP's "false" casting trap.
	 *
	 * @param mixed  $value         Input value
	 * @param bool   $default       Default for null or empty string
	 * @param string $parameterName Parameter name for errors
	 * @return bool                  Normalized boolean
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
	 * Limit universal-search aliases to the two aliases provided by this query.
	 * Field, operator and value validation stays delegated to Dolibarr's native
	 * forgeSQLFromUniversalSearchCriteria().
	 *
	 * @param string $sqlfilters Universal-search expression
	 * @return void
	 * @throws RestException
	 */
	public function validateSqlFilterAliases($sqlfilters)
	{
		$allowedProductFields = array(
			'rowid', 'ref', 'entity', 'ref_ext', 'datec', 'tms', 'fk_parent', 'label', 'description',
			'note_public', 'note', 'customcode', 'fk_country', 'fk_state', 'price', 'price_ttc',
			'price_min', 'price_min_ttc', 'price_base_type', 'price_label', 'cost_price',
			'default_vat_code', 'tva_tx', 'recuperableonly', 'localtax1_tx', 'localtax1_type',
			'localtax2_tx', 'localtax2_type', 'fk_user_author', 'fk_user_modif', 'tosell', 'tobuy',
			'tobatch', 'sell_or_eat_by_mandatory', 'batch_mask', 'fk_product_type', 'duration',
			'seuil_stock_alerte', 'url', 'barcode', 'fk_barcode_type', 'accountancy_code_sell',
			'accountancy_code_sell_intra', 'accountancy_code_sell_export', 'accountancy_code_buy',
			'accountancy_code_buy_intra', 'accountancy_code_buy_export', 'partnumber', 'net_measure',
			'net_measure_units', 'weight', 'weight_units', 'length', 'length_units', 'width',
			'width_units', 'height', 'height_units', 'surface', 'surface_units', 'volume',
			'volume_units', 'stockable_product', 'stock', 'pmp', 'fifo', 'lifo',
			'fk_default_warehouse', 'fk_default_bom', 'fk_default_workstation', 'canvas', 'finished',
			'lifetime', 'qc_frequency', 'hidden', 'import_key', 'model_pdf', 'fk_price_expression',
			'desiredstock', 'fk_unit', 'price_autogen', 'fk_project', 'mandatory_period',
			'last_main_doc',
		);
		$filterForValidation = trim((string) $sqlfilters);
		if (!preg_match('/^\(.*\)$/s', $filterForValidation)) {
			$filterForValidation = '('.$filterForValidation.')';
		}
		$matches = array();
		if (preg_match_all('/\(+\s*([a-zA-Z0-9_.]+)\s*:[<>!=a-z]+:/i', $filterForValidation, $matches)) {
			foreach ($matches[1] as $operand) {
				$parts = explode('.', $operand);
				if (count($parts) !== 2) {
					throw new RestException(400, 'Invalid sqlfilters field. Prefix product fields with t. and extrafields with ef.');
				}
				$alias = strtolower($parts[0]);
				$field = strtolower($parts[1]);
				if (!in_array($alias, array('t', 'ef'), true)) {
					throw new RestException(400, 'Invalid sqlfilters alias. Only t and ef are allowed.');
				}
				if ($alias === 't' && !in_array($field, $allowedProductFields, true)) {
					throw new RestException(400, 'Invalid product field in sqlfilters.');
				}
			}
		}
	}

	/**
	 * Require the native product read permission.
	 *
	 * @return void
	 * @throws RestException
	 */
	private function assertProductReadAccess()
	{
		if (empty($this->user) || !$this->user->hasRight('produit', 'lire')) {
			throw new RestException(403, 'Access forbidden.');
		}
	}

	/**
	 * @param mixed $value Value to validate
	 * @return bool         True for an integer or an integer-formatted string
	 */
	private function isIntegerValue($value)
	{
		return is_int($value) || (is_string($value) && preg_match('/^-?[0-9]+$/', $value));
	}

	/**
	 * Merge endpoint defaults and keep every option present.
	 *
	 * @param array $options Supplied options
	 * @return array          Complete options
	 */
	private function applyOptionDefaults(array $options)
	{
		$defaults = array(
			'sortfield' => 't.ref',
			'sortorder' => 'ASC',
			'limit' => 100,
			'page' => 0,
			'mode' => 0,
			'category' => 0,
			'sqlfilters' => '',
			'variant_filter' => 0,
			'pagination_data' => false,
			'includestockdata' => 0,
			'properties' => '',
			'on_missing_price' => self::ON_MISSING_PRICE_ERROR,
		);

		return array_merge($defaults, $options);
	}
}

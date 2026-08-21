<?php
/* Copyright (C) 2026 InvoicePlus module
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    custom/invoiceplus/class/invoicepluspricelevelservice.class.php
 * \ingroup invoiceplus
 * \brief   Central Customer > Warehouse > N1 price-level resolution service.
 */

// This service is also loaded by the product and warehouse card hooks, so it
// deliberately avoids the REST API classes. RestException is self-contained and
// checkUserAccessToObject() comes from security.lib.php, loaded by master.inc.php.
if (!class_exists('Luracast\\Restler\\RestException')) {
	require_once DOL_DOCUMENT_ROOT.'/includes/restler/framework/Luracast/Restler/RestException.php';
}
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/stock/class/entrepot.class.php';

use Luracast\Restler\RestException;

/**
 * Resolve the applicable native price level and price of a product.
 *
 * The module owns no price grid: prices are always read from the native
 * product_price table with the native price_level column, the native entity
 * perimeter and the native "most recent line wins" tie-break. Only the price
 * level of a warehouse is new data, and it is stored in the native
 * entrepot_extrafields table.
 */
class InvoicePlusPriceLevelService
{
	/** Level came from societe.price_level. */
	const SOURCE_CUSTOMER = 'customer';

	/** Level came from the warehouse extrafield. */
	const SOURCE_WAREHOUSE = 'warehouse';

	/** Neither the customer nor the warehouse defines a level. */
	const SOURCE_DEFAULT = 'default_level';

	/** Name of the native extrafield holding the commercial level of a warehouse. */
	const WAREHOUSE_LEVEL_FIELD = 'invoiceplus_price_level';

	/** @var DoliDB */
	private $db;

	/** @var User|null */
	private $user;

	/**
	 * @param DoliDB    $db   Database handler
	 * @param User|null $user Authenticated user, used for native access checks
	 */
	public function __construct($db, $user = null)
	{
		$this->db = $db;
		$this->user = $user;
	}

	/**
	 * Test whether Dolibarr multiprices are usable.
	 *
	 * The feature is active only when the native switch is on and the native
	 * limit is strictly positive. The number of levels is never hardcoded.
	 *
	 * @return bool True when N1..N are configured
	 */
	public static function isMultiPriceEnabled()
	{
		return getDolGlobalString('PRODUIT_MULTIPRICES') && self::getPriceLevelLimit() > 0;
	}

	/**
	 * Return the configured number of price levels.
	 *
	 * @return int Native PRODUIT_MULTIPRICES_LIMIT
	 */
	public static function getPriceLevelLimit()
	{
		return (int) getDolGlobalInt('PRODUIT_MULTIPRICES_LIMIT');
	}

	/**
	 * Return the localized label of one price level, native labels included.
	 *
	 * @param int $level Level between 1 and the configured limit
	 * @return string     Label such as "Selling price 2 - Wholesale"
	 */
	public static function getPriceLevelLabel($level)
	{
		global $langs;

		$level = (int) $level;
		$label = $langs->transnoentitiesnoconv('SellingPrice').' '.$level;
		$configured = getDolGlobalString('PRODUIT_MULTIPRICES_LABEL'.$level);
		if ($configured !== '') {
			$label .= ' - '.$langs->transnoentitiesnoconv($configured);
		}

		return $label;
	}

	/**
	 * Apply the mandatory Customer > Warehouse > N1 priority.
	 *
	 * The first source that defines a valid level wins outright. A level that
	 * exists but has no price is never replaced by the next source; only the
	 * direct N1 fallback of resolveProductPrice() applies afterwards.
	 *
	 * @param int|null $customerId  Third-party id, or null/0 when unknown
	 * @param int|null $warehouseId Warehouse id, or null/0 when unknown
	 * @return array                requested_price_level and price_level_source
	 * @throws RestException
	 */
	public function resolvePriceLevel($customerId, $warehouseId)
	{
		$this->assertMultiPriceEnabled();

		$customerId = (int) $customerId;
		if ($customerId > 0) {
			$customerLevel = $this->getCustomerPriceLevel($customerId);
			if ($customerLevel !== null) {
				return array(
					'requested_price_level' => $customerLevel,
					'price_level_source' => self::SOURCE_CUSTOMER,
				);
			}
		}

		$warehouseId = (int) $warehouseId;
		if ($warehouseId > 0) {
			$warehouseLevel = $this->getWarehousePriceLevel($warehouseId);
			if ($warehouseLevel !== null) {
				return array(
					'requested_price_level' => $warehouseLevel,
					'price_level_source' => self::SOURCE_WAREHOUSE,
				);
			}
		}

		return array(
			'requested_price_level' => 1,
			'price_level_source' => self::SOURCE_DEFAULT,
		);
	}

	/**
	 * Return the persisted price level of a third party, or null.
	 *
	 * societe.price_level is nullable but Societe::fetch() replaces an empty
	 * value by 1. The native class is still used to check existence, entity
	 * visibility and access rights; the raw column is then read separately so
	 * "no customer level" stays distinguishable from an explicit N1.
	 *
	 * @param int $customerId Third-party id
	 * @return int|null        Valid level, or null when undefined
	 * @throws RestException
	 */
	public function getCustomerPriceLevel($customerId)
	{
		$customer = $this->getAccessibleThirdParty($customerId);

		$sql = 'SELECT s.price_level FROM '.MAIN_DB_PREFIX.'societe AS s';
		$sql .= ' WHERE s.rowid = '.((int) $customer->id);
		$sql .= ' AND s.entity IN ('.getEntity('societe').')';
		$result = $this->db->query($sql);
		if (!$result) {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_ERR);
			throw new RestException(503, 'Unable to read the customer price level.');
		}
		$row = $this->db->fetch_object($result);
		$this->db->free($result);
		if (!$row) {
			throw new RestException(404, 'Third party not found.');
		}

		return self::normalizeStoredLevel(
			$row->price_level,
			self::getPriceLevelLimit(),
			'societe '.((int) $customer->id)
		);
	}

	/**
	 * Return the persisted price level of a warehouse, or null.
	 *
	 * @param int $warehouseId Warehouse id
	 * @return int|null         Valid level, or null when undefined
	 * @throws RestException
	 */
	public function getWarehousePriceLevel($warehouseId)
	{
		$warehouse = $this->getAccessibleWarehouse($warehouseId);

		return $this->getWarehousePriceLevelFromObject($warehouse);
	}

	/**
	 * Read the level of an already loaded and authorized warehouse.
	 *
	 * @param Entrepot $warehouse Warehouse loaded through the native class
	 * @return int|null            Valid level, or null when undefined
	 */
	public function getWarehousePriceLevelFromObject($warehouse)
	{
		$options = (is_object($warehouse) && isset($warehouse->array_options) && is_array($warehouse->array_options))
			? $warehouse->array_options
			: array();
		$key = 'options_'.self::WAREHOUSE_LEVEL_FIELD;
		if (!array_key_exists($key, $options)) {
			return null;
		}

		return self::normalizeStoredLevel(
			$options[$key],
			self::getPriceLevelLimit(),
			'entrepot '.((int) $warehouse->id)
		);
	}

	/**
	 * Resolve the applicable price of one product for an already known level.
	 *
	 * @param int $productId      Product id
	 * @param int $requestedLevel Level obtained from resolvePriceLevel()
	 * @return array               Price metadata of the applied price line
	 * @throws RestException
	 */
	public function resolveProductPrice($productId, $requestedLevel)
	{
		$this->assertMultiPriceEnabled();

		$productId = (int) $productId;
		if ($productId <= 0) {
			throw new RestException(400, 'Invalid product ID.');
		}
		$requestedLevel = $this->normalizeRequestedLevel($requestedLevel);

		$rows = $this->fetchCurrentPriceRows(array($productId), array($requestedLevel, 1));
		$selection = self::selectPriceRow(
			isset($rows[$productId]) ? $rows[$productId] : array(),
			$requestedLevel
		);
		if ($selection === null) {
			throw new RestException(422, 'No applicable price for product '.$productId.': '
				.self::describeMissingPrice($requestedLevel));
		}

		return self::buildPriceMetadata($selection, $requestedLevel, self::SOURCE_DEFAULT);
	}

	/**
	 * Resolve the price applicable to a product for one customer.
	 *
	 * @param int $productId  Product id
	 * @param int $customerId Third-party id
	 * @return array           Price metadata including resolution source
	 * @throws RestException
	 */
	public function getProductPriceForCustomer($productId, $customerId)
	{
		$resolution = $this->resolvePriceLevel($customerId, 0);

		return $this->resolveProductPriceWithSource(
			$productId,
			$resolution['requested_price_level'],
			$resolution['price_level_source']
		);
	}

	/**
	 * Resolve the price applicable to a product for one warehouse.
	 *
	 * @param int $productId   Product id
	 * @param int $warehouseId Warehouse id
	 * @return array            Price metadata including resolution source
	 * @throws RestException
	 */
	public function getProductPriceForWarehouse($productId, $warehouseId)
	{
		$resolution = $this->resolvePriceLevel(0, $warehouseId);

		return $this->resolveProductPriceWithSource(
			$productId,
			$resolution['requested_price_level'],
			$resolution['price_level_source']
		);
	}

	/**
	 * Read the current price line of every requested product and level in one
	 * query, so a product list never issues one price query per product.
	 *
	 * The native tie-break "date_price DESC, rowid DESC" and the native
	 * productprice entity perimeter are reproduced exactly.
	 *
	 * @param int[] $productIds Product ids of the current page
	 * @param int[] $levels     Price levels to read, typically the level and 1
	 * @return array            array[productId][level] = current price row
	 * @throws RestException
	 */
	public function fetchCurrentPriceRows(array $productIds, array $levels)
	{
		$ids = array();
		foreach ($productIds as $productId) {
			$productId = (int) $productId;
			if ($productId > 0) {
				$ids[$productId] = $productId;
			}
		}
		$wantedLevels = array();
		foreach ($levels as $level) {
			$level = (int) $level;
			if ($level > 0) {
				$wantedLevels[$level] = $level;
			}
		}
		if (count($ids) === 0 || count($wantedLevels) === 0) {
			return array();
		}

		$sql = 'SELECT pp.fk_product, pp.price_level, pp.rowid, pp.date_price,';
		$sql .= ' pp.price, pp.price_ttc, pp.price_min, pp.price_min_ttc, pp.price_base_type,';
		$sql .= ' pp.tva_tx, pp.default_vat_code, pp.recuperableonly, pp.price_label,';
		$sql .= ' pp.localtax1_tx, pp.localtax1_type, pp.localtax2_tx, pp.localtax2_type,';
		$sql .= ' pp.multicurrency_code, pp.multicurrency_tx, pp.multicurrency_price, pp.multicurrency_price_ttc';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'product_price AS pp';
		$sql .= ' WHERE pp.entity IN ('.getEntity('productprice').')';
		$sql .= ' AND pp.fk_product IN ('.implode(',', $ids).')';
		$sql .= ' AND pp.price_level IN ('.implode(',', $wantedLevels).')';
		$sql .= ' ORDER BY pp.fk_product ASC, pp.price_level ASC, pp.date_price DESC, pp.rowid DESC';

		$result = $this->db->query($sql);
		if (!$result) {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_ERR);
			throw new RestException(503, 'Unable to read product prices.');
		}

		$index = array();
		while ($row = $this->db->fetch_object($result)) {
			$productId = (int) $row->fk_product;
			$level = (int) $row->price_level;
			if (!isset($index[$productId])) {
				$index[$productId] = array();
			}
			// The ordering already places the current line first for each
			// (product, level) couple; later history rows are ignored.
			if (!isset($index[$productId][$level])) {
				$index[$productId][$level] = $row;
			}
		}
		$this->db->free($result);

		return $index;
	}

	/**
	 * Choose the price line to apply for one product.
	 *
	 * Pure function: the requested level wins when it holds a defined price,
	 * including an explicit zero. Otherwise the fallback goes directly to N1;
	 * no intermediate level and no other source is ever tried.
	 *
	 * @param array $levelRows      Current price rows indexed by price level
	 * @param int   $requestedLevel Requested price level
	 * @return array|null           row, applied_price_level and price_fallback
	 */
	public static function selectPriceRow(array $levelRows, $requestedLevel)
	{
		$requestedLevel = (int) $requestedLevel;
		if ($requestedLevel < 1) {
			$requestedLevel = 1;
		}

		if (isset($levelRows[$requestedLevel]) && self::isDefinedPrice($levelRows[$requestedLevel])) {
			return array(
				'row' => $levelRows[$requestedLevel],
				'applied_price_level' => $requestedLevel,
				'price_fallback' => false,
			);
		}

		if ($requestedLevel !== 1 && isset($levelRows[1]) && self::isDefinedPrice($levelRows[1])) {
			return array(
				'row' => $levelRows[1],
				'applied_price_level' => 1,
				'price_fallback' => true,
			);
		}

		return null;
	}

	/**
	 * Test whether a price line carries a defined price.
	 *
	 * A line is defined when it exists and its price column is not NULL. A
	 * numeric zero is defined; empty() and "> 0" are deliberately not used.
	 *
	 * @param object|array|null $row Current price row
	 * @return bool                   True when the price is defined
	 */
	public static function isDefinedPrice($row)
	{
		if (is_object($row)) {
			$price = property_exists($row, 'price') ? $row->price : null;
		} elseif (is_array($row)) {
			$price = array_key_exists('price', $row) ? $row['price'] : null;
		} else {
			return false;
		}

		if ($price === null || $price === '') {
			return false;
		}

		return is_numeric($price);
	}

	/**
	 * Convert a persisted level into a usable level or null.
	 *
	 * NULL, an empty string and 0 all mean "not defined". A legacy value now
	 * greater than the configured limit is treated as undefined and logged, so
	 * a level outside the current configuration is never read.
	 *
	 * @param mixed  $rawValue Persisted column value
	 * @param int    $limit    Configured PRODUIT_MULTIPRICES_LIMIT
	 * @param string $context  Record description used for the log line
	 * @return int|null         Valid level between 1 and the limit, or null
	 */
	public static function normalizeStoredLevel($rawValue, $limit, $context = '')
	{
		if ($rawValue === null || $rawValue === '' || $rawValue === false) {
			return null;
		}
		if (!is_int($rawValue) && !(is_string($rawValue) && preg_match('/^-?[0-9]+$/', $rawValue))) {
			dol_syslog(
				__METHOD__.' ignoring non-integer price level "'.dol_trunc((string) $rawValue, 32).'" on '.$context,
				LOG_WARNING
			);
			return null;
		}

		$level = (int) $rawValue;
		$limit = (int) $limit;
		if ($level <= 0) {
			return null;
		}
		if ($level > $limit) {
			dol_syslog(
				__METHOD__.' price level '.$level.' on '.$context.' exceeds PRODUIT_MULTIPRICES_LIMIT='.$limit
				.'; treated as undefined',
				LOG_WARNING
			);
			return null;
		}

		return $level;
	}

	/**
	 * Build the documented metadata of an applied price line.
	 *
	 * Pure function. Every monetary and tax field comes from the same applied
	 * line, so a level price is never mixed with the tax data of another level.
	 *
	 * @param array  $selection      Result of selectPriceRow()
	 * @param int    $requestedLevel Requested price level
	 * @param string $source         customer, warehouse or default_level
	 * @return array                  Resolution metadata
	 */
	public static function buildPriceMetadata(array $selection, $requestedLevel, $source)
	{
		$row = $selection['row'];
		$get = function ($field) use ($row) {
			if (is_object($row)) {
				return property_exists($row, $field) ? $row->$field : null;
			}

			return (is_array($row) && array_key_exists($field, $row)) ? $row[$field] : null;
		};

		$baseType = strtoupper(trim((string) $get('price_base_type')));
		if (!in_array($baseType, array('HT', 'TTC'), true)) {
			$baseType = 'HT';
		}

		return array(
			'requested_price_level' => (int) $requestedLevel,
			'applied_price_level' => (int) $selection['applied_price_level'],
			'price_level_source' => (string) $source,
			'price_fallback' => (bool) $selection['price_fallback'],
			'price' => (float) $get('price'),
			'price_ttc' => (float) $get('price_ttc'),
			'price_min' => (float) $get('price_min'),
			'price_min_ttc' => (float) $get('price_min_ttc'),
			'price_base_type' => $baseType,
			'price_label' => $get('price_label'),
			'tva_tx' => (float) $get('tva_tx'),
			'default_vat_code' => $get('default_vat_code'),
			'recuperableonly' => (int) $get('recuperableonly'),
			'localtax1_tx' => (float) $get('localtax1_tx'),
			'localtax1_type' => $get('localtax1_type'),
			'localtax2_tx' => (float) $get('localtax2_tx'),
			'localtax2_type' => $get('localtax2_type'),
			'multicurrency_code' => $get('multicurrency_code'),
			'multicurrency_tx' => $get('multicurrency_tx'),
			'multicurrency_price' => $get('multicurrency_price'),
			'multicurrency_price_ttc' => $get('multicurrency_price_ttc'),
			'price_line_id' => (int) $get('rowid'),
		);
	}

	/**
	 * Describe why no price line could be applied.
	 *
	 * @param int $requestedLevel Requested price level
	 * @return string              Human-readable reason
	 */
	public static function describeMissingPrice($requestedLevel)
	{
		$requestedLevel = (int) $requestedLevel;

		return $requestedLevel === 1
			? 'level 1 has no defined price.'
			: 'neither level '.$requestedLevel.' nor the level 1 fallback has a defined price.';
	}

	/**
	 * Refuse every price-level operation when multiprices are inactive.
	 *
	 * @return void
	 * @throws RestException
	 */
	public function assertMultiPriceEnabled()
	{
		if (!self::isMultiPriceEnabled()) {
			throw new RestException(
				409,
				'Price levels require PRODUIT_MULTIPRICES with a strictly positive PRODUIT_MULTIPRICES_LIMIT.'
			);
		}
	}

	/**
	 * Validate a requested level against the current configuration.
	 *
	 * @param mixed $requestedLevel Requested level
	 * @return int                   Level between 1 and the configured limit
	 * @throws RestException
	 */
	public function normalizeRequestedLevel($requestedLevel)
	{
		$level = (int) $requestedLevel;
		if ($level < 1 || $level > self::getPriceLevelLimit()) {
			throw new RestException(400, 'Invalid price level.');
		}

		return $level;
	}

	/**
	 * Load a third party through the native class and native access checks.
	 *
	 * @param int $customerId Third-party id
	 * @return Societe         Loaded third party
	 * @throws RestException
	 */
	public function getAccessibleThirdParty($customerId)
	{
		if (!$this->isPositiveIdentifier($customerId)) {
			throw new RestException(400, 'Invalid customer ID.');
		}
		$customerId = (int) $customerId;
		$this->assertAuthenticatedUser();

		if (!$this->user->hasRight('societe', 'lire')) {
			throw new RestException(403, 'Access forbidden.');
		}
		// External users may only read their own third party.
		if (!empty($this->user->socid) && (int) $this->user->socid !== $customerId) {
			throw new RestException(403, 'Access forbidden.');
		}

		$customer = new Societe($this->db);
		$result = $customer->fetch($customerId);
		if ($result < 0) {
			dol_syslog(__METHOD__.' '.$customer->error, LOG_ERR);
			throw new RestException(503, 'Unable to read third party.');
		}
		if ($result == 0) {
			throw new RestException(404, 'Third party not found.');
		}
		// Same native check as DolibarrApi::_checkAccessToResource('societe', id).
		if (!checkUserAccessToObject($this->user, array('societe'), (int) $customer->id, '', '', 'fk_soc', 'rowid')) {
			throw new RestException(403, 'Access forbidden.');
		}

		return $customer;
	}

	/**
	 * Load a warehouse through the native class and native access checks.
	 *
	 * @param int $warehouseId Warehouse id
	 * @return Entrepot         Loaded warehouse
	 * @throws RestException
	 */
	public function getAccessibleWarehouse($warehouseId)
	{
		if (!$this->isPositiveIdentifier($warehouseId)) {
			throw new RestException(400, 'Invalid warehouse ID.');
		}
		$warehouseId = (int) $warehouseId;
		$this->assertAuthenticatedUser();

		if (!$this->user->hasRight('stock', 'lire')) {
			throw new RestException(403, 'Access forbidden.');
		}

		$warehouse = new Entrepot($this->db);
		$result = $warehouse->fetch($warehouseId);
		if ($result < 0) {
			dol_syslog(__METHOD__.' '.$warehouse->error, LOG_ERR);
			throw new RestException(503, 'Unable to read warehouse.');
		}
		if ($result == 0) {
			throw new RestException(404, 'Warehouse not found.');
		}

		// Entrepot::fetch() does not filter the entity when fetching by id.
		$visibleEntities = array_map('intval', explode(',', (string) getEntity('stock')));
		if (!in_array((int) $warehouse->entity, $visibleEntities, true)) {
			throw new RestException(403, 'Access forbidden.');
		}
		// Same native check as DolibarrApi::_checkAccessToResource('stock', id, 'entrepot').
		if (!checkUserAccessToObject($this->user, array('stock'), (int) $warehouse->id, 'entrepot', '', 'fk_soc', 'rowid')) {
			throw new RestException(403, 'Access forbidden.');
		}

		return $warehouse;
	}

	/**
	 * Require an authenticated user before any resource access check.
	 *
	 * @return void
	 * @throws RestException
	 */
	private function assertAuthenticatedUser()
	{
		if (empty($this->user) || empty($this->user->id)) {
			throw new RestException(403, 'Access forbidden.');
		}
	}

	/**
	 * Reject null, negative, decimal and malformed identifiers.
	 *
	 * @param mixed $value Candidate identifier
	 * @return bool         True for a strictly positive integer
	 */
	public function isPositiveIdentifier($value)
	{
		if (is_int($value)) {
			return $value > 0;
		}
		if (is_string($value) && preg_match('/^[0-9]+$/', $value)) {
			return (int) $value > 0;
		}

		return false;
	}

	/**
	 * Share one resolution between the single-product helper methods.
	 *
	 * @param int    $productId      Product id
	 * @param int    $requestedLevel Requested level
	 * @param string $source         Resolution source
	 * @return array                  Price metadata
	 * @throws RestException
	 */
	private function resolveProductPriceWithSource($productId, $requestedLevel, $source)
	{
		$metadata = $this->resolveProductPrice($productId, $requestedLevel);
		$metadata['price_level_source'] = (string) $source;

		return $metadata;
	}
}

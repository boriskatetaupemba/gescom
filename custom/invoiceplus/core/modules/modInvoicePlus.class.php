<?php
/* Copyright (C) 2026 InvoicePlus module
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \defgroup invoiceplus Module InvoicePlus
 * \brief    Extensible customer invoice services.
 *
 * \file     custom/invoiceplus/core/modules/modInvoicePlus.class.php
 * \ingroup  invoiceplus
 * \brief    Module descriptor and activation class.
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * InvoicePlus module descriptor.
 */
class modInvoicePlus extends DolibarrModules
{
	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $conf;

		$this->db = $db;
		$this->numero = 4930000;
		$this->rights_class = 'invoiceplus';
		$this->family = 'financial';
		$this->module_position = '92';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = 'ModuleInvoicePlusDesc';
		$this->descriptionlong = 'ModuleInvoicePlusDescLong';
		$this->editor_name = 'InvoicePlus';
		$this->editor_url = '';
		$this->version = '1.4.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'bill';

		$this->module_parts = array(
			'triggers' => 1,
			'login' => 0,
			'substitutions' => 0,
			'menus' => 0,
			'tpl' => 0,
			'barcode' => 0,
			'models' => 0,
			'printing' => 0,
			'theme' => 0,
			'css' => array(),
			'js' => array(),
			'hooks' => array('invoicecard', 'productcard', 'warehousecard'),
			'moduleforexternal' => 0,
		);

		$this->dirs = array('/invoiceplus/temp');
		$this->config_page_url = array('setup.php@invoiceplus');
		$this->hidden = false;
		$this->depends = array('modApi', 'modSociete', 'modFacture', 'modStock', 'modBanque', 'modMultiCurrency');
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array('invoiceplus@invoiceplus');
		$this->phpmin = array(7, 1);
		$this->need_dolibarr_version = array(20, -3);
		$this->need_javascript_ajax = 0;
		$this->warnings_activation = array();
		$this->warnings_activation_ext = array();

		$this->const = array();
		$this->const[0] = array('INVOICEPLUS_API_ENABLED', 'chaine', '1', 'Enable the InvoicePlus REST API', 0);
		$this->const[1] = array('INVOICEPLUS_MAX_API_LIMIT', 'chaine', '1000', 'Maximum number of invoices returned per page', 0);
		$this->const[2] = array('INVOICEPLUS_ADD_WAREHOUSE_METADATA', 'chaine', '1', 'Add non-persistent warehouse filter metadata', 0);
		$this->const[3] = array('INVOICEPLUS_LOAD_CLOSURE_DATA', 'chaine', '1', 'Add InvoiceClosure information to InvoicePlus responses', 0);
		$this->const[4] = array('INVOICEPLUS_ENABLE_WAREHOUSE_FALLBACKS', 'chaine', '1', 'Resolve legacy invoice warehouses from stock/POS/account sources', 0);

		if (!isModEnabled('invoiceplus')) {
			$conf->invoiceplus = new stdClass();
			$conf->invoiceplus->enabled = 0;
		}

		$this->tabs = array();
		$this->dictionaries = array();
		$this->boxes = array();
		$this->cronjobs = array();
		$this->rights = array();
		$this->menu = array();
	}

	/**
	 * Enable module, create/upgrade its idempotency table and its extrafields.
	 *
	 * @param string $options Activation options
	 * @return int            Result
	 */
	public function init($options = '')
	{
		$result = $this->_load_tables('/invoiceplus/sql/');
		if ($result <= 0 || !$this->verifyCashSettlementSchema()) {
			return -1;
		}
		if (!$this->installWarehousePriceLevelExtraField()) {
			return -1;
		}

		return $this->_init(array(), $options);
	}

	/**
	 * Create or refresh the nullable warehouse price-level extrafield.
	 *
	 * The value lives in the native entrepot_extrafields table; the module owns
	 * no SQL table for it. The operation is idempotent, is scoped to the current
	 * entity, works on a simple re-activation and never deletes stored values.
	 *
	 * @return bool True when the definition is installed and compatible
	 */
	private function installWarehousePriceLevelExtraField()
	{
		global $conf, $langs;

		require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
		dol_include_once('/invoiceplus/class/invoicepluspricelevelservice.class.php');

		$attributeName = InvoicePlusPriceLevelService::WAREHOUSE_LEVEL_FIELD;
		$elementType = 'entrepot';
		$entity = (int) $conf->entity;

		$definitions = $this->readExtraFieldDefinitions($elementType, $attributeName);
		if ($definitions === null) {
			$this->error = 'InvoicePlus could not read the existing extrafield definitions of '.$elementType.'.';
			dol_syslog(__METHOD__.' '.$this->error, LOG_ERR);
			return false;
		}

		$targetEntity = $entity;
		$hasReusableDefinition = false;
		foreach ($definitions as $definition) {
			if (!$this->isCompatibleWarehousePriceLevelDefinition($definition)) {
				$this->error = 'InvoicePlus cannot install "'.$attributeName.'" on '.$elementType
					.': an incompatible extrafield with the same name already exists (entity '
					.((int) $definition->entity).', type '.$definition->type.').';
				dol_syslog(__METHOD__.' '.$this->error, LOG_ERR);
				return false;
			}
			if ((int) $definition->entity === 0) {
				// A definition shared by every entity keeps its own scope.
				$targetEntity = 0;
				$hasReusableDefinition = true;
			} elseif ((int) $definition->entity === $entity) {
				$hasReusableDefinition = true;
			}
		}

		if (is_object($langs)) {
			$langs->load('invoiceplus@invoiceplus');
		}
		$extrafields = new ExtraFields($this->db);
		// The list of levels is never frozen here: the label stays a plain
		// integer field and the warehouse card rebuilds the choices per request.
		$enabledCondition = 'getDolGlobalString("PRODUIT_MULTIPRICES") && getDolGlobalInt("PRODUIT_MULTIPRICES_LIMIT") > 0';
		$arguments = array(
			$attributeName,
			'InvoicePlusWarehousePriceLevel',
			'int',
			500,
			'5',
			$elementType,
			0,
			0,
			'',
			// An empty array reaches strlen() in ExtraFields::create_label() and
			// raises a PHP 8 TypeError, so "no parameter" is the empty string.
			'',
			0,
			'',
			'1',
			'InvoicePlusWarehousePriceLevelHelp',
			'',
			$targetEntity,
			'invoiceplus@invoiceplus',
			$enabledCondition,
			0,
			0,
		);

		$result = $hasReusableDefinition
			? call_user_func_array(array($extrafields, 'updateExtraField'), $arguments)
			: call_user_func_array(array($extrafields, 'addExtraField'), $arguments);
		if ($result <= 0) {
			$this->error = 'InvoicePlus could not install the warehouse price-level extrafield: '
				.($extrafields->error !== '' ? $extrafields->error : 'unknown error').'.';
			dol_syslog(__METHOD__.' '.$this->error, LOG_ERR);
			return false;
		}

		return true;
	}

	/**
	 * Read every stored definition of one extrafield, all entities included.
	 *
	 * @param string $elementType   Native element type
	 * @param string $attributeName Extrafield name
	 * @return array|null            Definitions, or null on SQL error
	 */
	private function readExtraFieldDefinitions($elementType, $attributeName)
	{
		$sql = 'SELECT rowid, entity, type, size, fieldrequired, fieldunique, fieldcomputed';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'extrafields';
		$sql .= " WHERE elementtype = '".$this->db->escape($elementType)."'";
		$sql .= " AND name = '".$this->db->escape($attributeName)."'";

		$result = $this->db->query($sql);
		if (!$result) {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_ERR);
			return null;
		}
		$definitions = array();
		while ($row = $this->db->fetch_object($result)) {
			$definitions[] = $row;
		}
		$this->db->free($result);

		return $definitions;
	}

	/**
	 * Test whether an existing definition can host the warehouse price level.
	 *
	 * @param object $definition Stored extrafield definition
	 * @return bool               True when the definition is compatible
	 */
	private function isCompatibleWarehousePriceLevelDefinition($definition)
	{
		return $definition->type === 'int'
			&& empty($definition->fieldrequired)
			&& empty($definition->fieldunique)
			&& (string) $definition->fieldcomputed === '';
	}

	/**
	 * Refuse activation unless the idempotency journal and its exact unique key exist.
	 *
	 * _load_tables() returns 0 on SQL errors, so relying on a negative result alone
	 * can enable a module whose settlement boundary is not idempotent.
	 *
	 * @return bool True when the required table and unique key are present
	 */
	private function verifyCashSettlementSchema()
	{
		$table = MAIN_DB_PREFIX.'invoiceplus_cash_settlement';
		$columns = $this->db->DDLInfoTable($table);
		if (!is_array($columns) || count($columns) === 0) {
			$this->error = 'InvoicePlus settlement table is missing after schema installation.';
			dol_syslog(__METHOD__.' '.$this->error, LOG_ERR);
			return false;
		}

		$indexName = 'uk_invoiceplus_cash_operation';
		$indexColumns = array();
		$isUnique = false;
		if ($this->db->type === 'mysqli') {
			$sql = 'SELECT NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART';
			$sql .= ' FROM information_schema.statistics';
			$sql .= ' WHERE table_schema = DATABASE()';
			$sql .= " AND table_name = '".$this->db->escape($table)."'";
			$sql .= " AND index_name = '".$this->db->escape($indexName)."'";
			$sql .= ' ORDER BY SEQ_IN_INDEX';
			$result = $this->db->query($sql);
			if ($result) {
				$isUnique = true;
				while ($row = $this->db->fetch_object($result)) {
					$isUnique = $isUnique && ((int) $row->NON_UNIQUE === 0) && $row->SUB_PART === null;
					$indexColumns[] = strtolower((string) $row->COLUMN_NAME);
				}
				$this->db->free($result);
			}
		} elseif ($this->db->type === 'pgsql') {
			$sql = 'SELECT indexdef FROM pg_indexes';
			$sql .= ' WHERE schemaname = current_schema()';
			$sql .= " AND tablename = '".$this->db->escape($table)."'";
			$sql .= " AND indexname = '".$this->db->escape($indexName)."'";
			$result = $this->db->query($sql);
			if ($result && ($row = $this->db->fetch_object($result))) {
				$definition = strtolower((string) $row->indexdef);
				$isUnique = strpos($definition, 'create unique index') !== false;
				if (preg_match('/\\(([^)]+)\\)/', $definition, $matches)) {
					$indexColumns = array_map(function ($column) {
						return trim($column, " \t\n\r\0\x0B\\\"");
					}, explode(',', $matches[1]));
				}
				$this->db->free($result);
			}
		} else {
			$this->error = 'InvoicePlus cannot verify its unique operation key on database type '.$this->db->type.'.';
			dol_syslog(__METHOD__.' '.$this->error, LOG_ERR);
			return false;
		}

		if (!$isUnique || $indexColumns !== array('entity', 'operation_id')) {
			$this->error = 'InvoicePlus unique key uk_invoiceplus_cash_operation(entity, operation_id) is missing or invalid.';
			dol_syslog(__METHOD__.' '.$this->error, LOG_ERR);
			return false;
		}

		return true;
	}

	/**
	 * Disable module while preserving configuration values.
	 *
	 * Neither the settlement journal nor the warehouse price-level extrafield
	 * and its stored values are removed, so a deactivation followed by a
	 * re-activation keeps every record.
	 *
	 * @param string $options Removal options
	 * @return int            Result
	 */
	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}
}

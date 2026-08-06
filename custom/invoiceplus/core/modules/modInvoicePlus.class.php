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
		$this->version = '1.0.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'bill';

		$this->module_parts = array(
			'triggers' => 0,
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
			'hooks' => array(),
			'moduleforexternal' => 0,
		);

		$this->dirs = array('/invoiceplus/temp');
		$this->config_page_url = array('setup.php@invoiceplus');
		$this->hidden = false;
		$this->depends = array('modApi', 'modFacture', 'modStock');
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
		$this->const[3] = array('INVOICEPLUS_LOAD_CLOSURE_DATA', 'chaine', '1', 'Keep native InvoiceClosure information', 0);

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
	 * Enable module. No InvoicePlus table is needed by version 1.0.0.
	 *
	 * @param string $options Activation options
	 * @return int            Result
	 */
	public function init($options = '')
	{
		return $this->_init(array(), $options);
	}

	/**
	 * Disable module while preserving configuration values.
	 *
	 * @param string $options Removal options
	 * @return int            Result
	 */
	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}
}

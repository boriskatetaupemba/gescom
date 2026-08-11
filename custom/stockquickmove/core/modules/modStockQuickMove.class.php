<?php
/* Copyright (C) 2026 StockQuickMove module
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \defgroup stockquickmove Module StockQuickMove
 * \brief    Quick stock entry, exit and transfer form.
 *
 * \file     custom/stockquickmove/core/modules/modStockQuickMove.class.php
 * \ingroup  stockquickmove
 * \brief    Module descriptor and activation class.
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Description and activation class for module StockQuickMove.
 */
class modStockQuickMove extends DolibarrModules
{
	/**
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $conf;

		$this->db = $db;
		$this->numero = 4940000;
		$this->rights_class = 'stockquickmove';
		$this->family = 'products';
		$this->module_position = '93';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = 'ModuleStockQuickMoveDesc';
		$this->descriptionlong = 'ModuleStockQuickMoveDescLong';
		$this->editor_name = 'StockQuickMove';
		$this->editor_url = '';
		$this->version = '1.0.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'stock';

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
			'hooks' => array(
				'data' => array('stockmovementlist'),
				'entity' => '0',
			),
			'moduleforexternal' => 0,
		);

		$this->dirs = array();
		$this->config_page_url = array();
		$this->hidden = false;
		$this->depends = array('modProduct', 'modStock');
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array('stockquickmove@stockquickmove');
		$this->phpmin = array(7, 1);
		$this->need_dolibarr_version = array(20, -3);
		$this->need_javascript_ajax = 1;
		$this->warnings_activation = array();
		$this->warnings_activation_ext = array();
		$this->const = array();

		if (!isModEnabled('stockquickmove')) {
			$conf->stockquickmove = new stdClass();
			$conf->stockquickmove->enabled = 0;
		}

		$this->tabs = array();
		$this->dictionaries = array();
		$this->boxes = array();
		$this->cronjobs = array();
		$this->rights = array();

		$this->menu = array();
		$r = 0;
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=products,fk_leftmenu=stock',
			'type' => 'left',
			'titre' => 'StockQuickMoveMenu',
			'mainmenu' => 'products',
			'leftmenu' => 'stockquickmove_quickmovement',
			'url' => '/custom/stockquickmove/quickmovement.php',
			'langs' => 'stockquickmove@stockquickmove',
			'position' => 1100,
			'enabled' => 'isModEnabled("stockquickmove")',
			'perms' => '$user->hasRight("stock", "mouvement", "creer")',
			'target' => '',
			'user' => 0,
		);
	}

	/**
	 * Enable the module.
	 *
	 * @param string $options Activation options
	 * @return int            Result
	 */
	public function init($options = '')
	{
		return $this->_init(array(), $options);
	}

	/**
	 * Disable the module.
	 *
	 * @param string $options Removal options
	 * @return int            Result
	 */
	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}
}

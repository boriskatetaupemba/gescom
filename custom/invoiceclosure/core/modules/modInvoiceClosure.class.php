<?php
/* Copyright (C) 2026 InvoiceClosure module
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \defgroup   invoiceclosure     Module InvoiceClosure
 * \brief      InvoiceClosure module descriptor.
 *
 * \file       custom/invoiceclosure/core/modules/modInvoiceClosure.class.php
 * \ingroup    invoiceclosure
 * \brief      Description and activation file for module InvoiceClosure
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';


/**
 * Description and activation class for module InvoiceClosure.
 *
 * Adds a complementary business status "Closed" (Cloturee) after the standard
 * Dolibarr status "Paid" on customer invoices, without ever touching
 * llx_facture.fk_statut or any core file.
 */
class modInvoiceClosure extends DolibarrModules
{
	/**
	 * Constructor. Define names, constants, directories, boxes, permissions
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $langs, $conf;

		$this->db = $db;

		// Id for module (must be unique). 4920000 is in the range reserved for external/custom modules.
		$this->numero = 4920000;

		// Key text used to identify module (for permissions, menus, etc...)
		$this->rights_class = 'invoiceclosure';

		// Family
		$this->family = "financial";
		$this->module_position = '91';

		// Module label (no space allowed), used if translation string 'ModuleInvoiceClosureName' not found.
		$this->name = preg_replace('/^mod/i', '', get_class($this));

		// Module description, used if translation string 'ModuleInvoiceClosureDesc' not found.
		$this->description = "ModuleInvoiceClosureDesc";
		$this->descriptionlong = "ModuleInvoiceClosureDesc";

		$this->editor_name = 'InvoiceClosure';
		$this->editor_url = '';

		$this->version = '1.0.0';

		// Key used in llx_const table to save module status enabled/disabled
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);

		// Icon
		$this->picto = 'bill';

		// Define some features supported by module
		$this->module_parts = array(
			// The module provides triggers (core/triggers)
			'triggers' => 1,
			'login' => 0,
			'substitutions' => 0,
			'menus' => 0,
			'tpl' => 0,
			'barcode' => 0,
			'models' => 0,
			'printing' => 0,
			'theme' => 0,
			// CSS loaded on every page (tiny file, used on invoice card/list and history page)
			'css' => array('/invoiceclosure/css/invoiceclosure.css.php'),
			'js' => array(),
			// Hook contexts managed by this module (verified in Dolibarr 20.0.4 sources)
			'hooks' => array(
				'data' => array(
					'invoicecard',
					'invoicelist',
				),
				'entity' => '0',
			),
			'moduleforexternal' => 0,
		);

		// Data directories to create when module is enabled.
		$this->dirs = array("/invoiceclosure/temp");

		// Config pages.
		$this->config_page_url = array("setup.php@invoiceclosure");

		// Dependencies
		$this->hidden = false;
		$this->depends = array('modFacture');		// Customer invoices module is required
		$this->requiredby = array();
		$this->conflictwith = array();

		// The language file dedicated to the module
		$this->langfiles = array("invoiceclosure@invoiceclosure");

		// Prerequisites
		$this->phpmin = array(7, 1);
		$this->need_dolibarr_version = array(20, -3);
		$this->need_javascript_ajax = 0;

		$this->warnings_activation = array();
		$this->warnings_activation_ext = array();

		// Constants (key, 'chaine', value, description, visible)
		// Values already customized by the administrator are preserved on re-activation.
		// Constants are NOT deleted when the module is disabled (data/config preserved).
		$this->const = array();
		$this->const[0] = array('INVOICECLOSURE_REQUIRE_ZERO_REMAIN', 'chaine', '1', 'Require a remain to pay equal to zero (and fully paid flag) before closing', 0);
		$this->const[1] = array('INVOICECLOSURE_LOCK_CLOSED_INVOICES', 'chaine', '1', 'Lock closed invoices against modifications', 0);
		$this->const[2] = array('INVOICECLOSURE_ALLOW_REOPEN', 'chaine', '1', 'Allow reopening of the business closure', 0);
		$this->const[3] = array('INVOICECLOSURE_REQUIRE_CLOSE_NOTE', 'chaine', '0', 'Make the closure note mandatory', 0);
		$this->const[4] = array('INVOICECLOSURE_REQUIRE_REOPEN_NOTE', 'chaine', '1', 'Make the reopen note mandatory', 0);
		$this->const[5] = array('INVOICECLOSURE_CREATE_AGENDA_EVENT', 'chaine', '1', 'Create an event in the Dolibarr agenda on close/reopen', 0);
		$this->const[6] = array('INVOICECLOSURE_SHOW_IN_INVOICE_LIST', 'chaine', '1', 'Show the closure column and filters in the customer invoice list', 0);
		$this->const[7] = array('INVOICECLOSURE_SHOW_BADGE', 'chaine', '1', 'Show the "Closed" badge on the invoice card', 0);

		if (!isModEnabled("invoiceclosure")) {
			$conf->invoiceclosure = new stdClass();
			$conf->invoiceclosure->enabled = 0;
		}

		// Tabs
		$this->tabs = array();

		// Dictionaries
		$this->dictionaries = array();

		// Boxes/Widgets
		$this->boxes = array();

		// Cronjobs
		$this->cronjobs = array();

		// Permissions provided by this module
		$this->rights = array();
		$r = 0;

		$this->rights[$r][0] = 4920001;
		$this->rights[$r][1] = 'Read invoice closure information';
		$this->rights[$r][4] = 'read';
		$this->rights[$r][5] = '';
		$r++;

		$this->rights[$r][0] = 4920002;
		$this->rights[$r][1] = 'Close an invoice (business closure)';
		$this->rights[$r][4] = 'close';
		$this->rights[$r][5] = '';
		$r++;

		$this->rights[$r][0] = 4920003;
		$this->rights[$r][1] = 'Reopen a closed invoice (business closure)';
		$this->rights[$r][4] = 'reopen';
		$this->rights[$r][5] = '';
		$r++;

		$this->rights[$r][0] = 4920004;
		$this->rights[$r][1] = 'Read the closure history';
		$this->rights[$r][4] = 'readhistory';
		$this->rights[$r][5] = '';
		$r++;

		$this->rights[$r][0] = 4920005;
		$this->rights[$r][1] = 'Configure the module';
		$this->rights[$r][4] = 'setup';
		$this->rights[$r][5] = '';
		$r++;

		$this->rights[$r][0] = 4920006;
		$this->rights[$r][1] = 'Force an operation on a closed invoice (bypass lock, logged)';
		$this->rights[$r][4] = 'force';
		$this->rights[$r][5] = '';
		$r++;

		// Menus: none (the module acts on native invoice pages)
		$this->menu = array();
	}

	/**
	 * Function called when module is enabled.
	 * The init function adds constants, boxes, permissions and menus (defined in constructor) into Dolibarr database.
	 * It also creates data directories and module tables.
	 *
	 * @param  string $options Options when enabling module ('', 'noboxes')
	 * @return int             1 if OK, 0 if KO
	 */
	public function init($options = '')
	{
		// Create tables of the module (sql/llx_*.sql then llx_*.key.sql).
		// run_sql() replaces the llx_ prefix with the configured database prefix.
		$result = $this->_load_tables('/invoiceclosure/sql/');
		if ($result < 0) {
			return -1; // Do not activate module if error 'not allowed' returned when loading module SQL queries
		}

		$sql = array();

		return $this->_init($sql, $options);
	}

	/**
	 * Function called when module is disabled.
	 * Remove from database constants flagged for deletion, boxes and permissions from Dolibarr database.
	 * Data directories are not deleted and module tables are KEPT (closure data and history are preserved).
	 *
	 * @param  string $options Options when disabling module ('', 'noboxes')
	 * @return int             1 if OK, 0 if KO
	 */
	public function remove($options = '')
	{
		$sql = array();

		return $this->_remove($sql, $options);
	}
}

<?php
/* Copyright (C) 2026 PosNova module
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
 * \defgroup   posnova     Module PosNova
 * \brief      PosNova module descriptor.
 *
 * \file       custom/posnova/core/modules/modPosNova.class.php
 * \ingroup    posnova
 * \brief      Description and activation file for module PosNova (custom multi-currency POS).
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';


/**
 *  Description and activation class for module PosNova.
 *
 *  Modern multi-currency (USD / CDF) Point of Sale layer for Dolibarr:
 *  warehouse -> cash accounts -> POS terminal -> cash session -> ticket.
 */
class modPosNova extends DolibarrModules
{
	/**
	 * Constructor. Define names, constants, directories, boxes, permissions
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $conf;

		$this->db = $db;

		// Unique module id in the range reserved for external/custom modules.
		$this->numero = 4910000;

		$this->rights_class = 'posnova';

		$this->family = "products";
		$this->module_position = '91';

		$this->name = preg_replace('/^mod/i', '', get_class($this));

		$this->description = "ModulePosNovaDesc";
		$this->descriptionlong = "ModulePosNovaDescLong";

		$this->editor_name = 'PosNova';
		$this->editor_url = '';

		$this->version = '1.0.0';

		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);

		$this->picto = 'cash-register';

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
			// Hooks to exclude POS invoices from the standard customer-invoice lists/cards.
			'hooks' => array(
				'data' => array(
					'invoicelist',
					'invoicecard',
				),
				'entity' => '0',
			),
			'moduleforexternal' => 0,
		);

		// Data directories to create when module is enabled.
		$this->dirs = array("/posnova/temp");

		// Config page.
		$this->config_page_url = array("setup.php@posnova");

		// Dependencies.
		$this->hidden = false;
		$this->depends = array('modFacture', 'modProduct', 'modStock', 'modBanque');
		$this->requiredby = array();
		$this->conflictwith = array();

		$this->langfiles = array("posnova@posnova");

		$this->phpmin = array(7, 1);
		$this->need_dolibarr_version = array(19, -3);
		$this->need_javascript_ajax = 1;

		$this->warnings_activation = array();
		$this->warnings_activation_ext = array();

		// Constants: array(key, 'chaine', value, description, visible)
		$this->const = array();
		$this->const[0] = array('POSNOVA_ROUNDING_CDF', 'chaine', 'DOWN_UNIT', 'CDF rounding rule on conversion (DOWN_UNIT|NEAREST_UNIT|NEAREST_5)', 0);
		$this->const[1] = array('POSNOVA_SESSION_LIFETIME_HOURS', 'chaine', '12', 'POS session token lifetime in hours', 0);
		$this->const[2] = array('POSNOVA_CATALOG_PAGE_SIZE', 'chaine', '50', 'Catalog page size', 0);
		$this->const[3] = array('POSNOVA_POLL_INTERVAL_SEC', 'chaine', '30', 'Differential polling interval in seconds', 0);
		$this->const[4] = array('POSNOVA_DEFAULT_PRINT_FORMAT', 'chaine', '80mm', 'Default print format (80mm|A5|A4)', 0);
		$this->const[5] = array('POSNOVA_CDF_CURRENCY_CODE', 'chaine', 'CDF', 'Local currency ISO code', 0);
		$this->const[6] = array('POSNOVA_TRANSFER_TIMEOUT_HOURS', 'chaine', '24', 'Default transfer reservation timeout in hours', 0);
		$this->const[7] = array('POSNOVA_CANCEL_ESCALATION_MIN', 'chaine', '30', 'Default cancellation escalation delay in minutes', 0);
		$this->const[8] = array('POSNOVA_FALLBACK_TO_MULTICURRENCY', 'chaine', '1', 'Use multicurrency rate when no daily rate is set', 0);

		if (!isModEnabled("posnova")) {
			$conf->posnova = new stdClass();
			$conf->posnova->enabled = 0;
		}

		$this->tabs = array();
		$this->dictionaries = array();
		$this->boxes = array();

		// Cron job: clean expired transfer reservations.
		$this->cronjobs = array(
			0 => array(
				'label' => 'PosNova: release expired transfer reservations',
				'jobtype' => 'method',
				'class' => '/custom/posnova/class/postransfer.class.php',
				'objectname' => 'PosTransfer',
				'method' => 'cronReleaseExpired',
				'parameters' => '',
				'comment' => 'Release stock reserved by transfer requests that timed out',
				'frequency' => 1,
				'unitfrequency' => 3600,
				'priority' => 50,
				'datestart' => -1,
				'enabled' => '$conf->posnova->enabled',
			),
		);

		// Permissions.
		$this->rights = array();
		$r = 0;

		$this->rights[$r][0] = 4910001;
		$this->rights[$r][1] = 'Use the POS terminal and sell';
		$this->rights[$r][4] = 'run';
		$this->rights[$r][5] = '';
		$r++;

		$this->rights[$r][0] = 4910002;
		$this->rights[$r][1] = 'Configure POS terminals and module';
		$this->rights[$r][4] = 'setup';
		$this->rights[$r][5] = '';
		$r++;

		$this->rights[$r][0] = 4910003;
		$this->rights[$r][1] = 'Open and close cash sessions';
		$this->rights[$r][4] = 'session';
		$this->rights[$r][5] = '';
		$r++;

		$this->rights[$r][0] = 4910004;
		$this->rights[$r][1] = 'Force a discount above the cap';
		$this->rights[$r][4] = 'discount';
		$this->rights[$r][5] = '';
		$r++;

		$this->rights[$r][0] = 4910005;
		$this->rights[$r][1] = 'Approve ticket cancellations';
		$this->rights[$r][4] = 'cancel';
		$this->rights[$r][5] = '';
		$r++;

		$this->rights[$r][0] = 4910006;
		$this->rights[$r][1] = 'Initiate and approve stock transfers';
		$this->rights[$r][4] = 'transfer';
		$this->rights[$r][5] = '';
		$r++;

		$this->rights[$r][0] = 4910007;
		$this->rights[$r][1] = 'Manage the daily exchange rate';
		$this->rights[$r][4] = 'rate';
		$this->rights[$r][5] = '';
		$r++;

		$this->rights[$r][0] = 4910008;
		$this->rights[$r][1] = 'Read POS reports and dashboards';
		$this->rights[$r][4] = 'reports';
		$this->rights[$r][5] = '';
		$r++;

		// Menus.
		$this->menu = array();
		$r = 0;

		// Top menu.
		$this->menu[$r++] = array(
			'fk_menu' => '',
			'type' => 'top',
			'titre' => 'PosNovaMenu',
			'mainmenu' => 'posnova',
			'leftmenu' => '',
			'prefix' => img_picto('', $this->picto, 'class="pictofixedwidth"'),
			'url' => '/custom/posnova/pos.php',
			'langs' => 'posnova@posnova',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("posnova")',
			'perms' => '$user->hasRight("posnova", "run")',
			'target' => '',
			'user' => 2,
		);

		// Left: launch the cash desk.
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=posnova',
			'type' => 'left',
			'titre' => 'PosNovaLaunch',
			'mainmenu' => 'posnova',
			'leftmenu' => 'posnova_launch',
			'url' => '/custom/posnova/pos.php',
			'langs' => 'posnova@posnova',
			'position' => 1001,
			'enabled' => 'isModEnabled("posnova")',
			'perms' => '$user->hasRight("posnova", "run")',
			'target' => '',
			'user' => 2,
		);

		// Left: daily exchange rate.
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=posnova',
			'type' => 'left',
			'titre' => 'PosNovaDailyRate',
			'mainmenu' => 'posnova',
			'leftmenu' => 'posnova_rate',
			'url' => '/custom/posnova/rate.php',
			'langs' => 'posnova@posnova',
			'position' => 1002,
			'enabled' => 'isModEnabled("posnova")',
			'perms' => '$user->hasRight("posnova", "run")',
			'target' => '',
			'user' => 2,
		);

		// Left: POS invoices.
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=posnova',
			'type' => 'left',
			'titre' => 'PosNovaInvoices',
			'mainmenu' => 'posnova',
			'leftmenu' => 'posnova_invoices',
			'url' => '/custom/posnova/invoices.php',
			'langs' => 'posnova@posnova',
			'position' => 1003,
			'enabled' => 'isModEnabled("posnova")',
			'perms' => '$user->hasRight("posnova", "run")',
			'target' => '',
			'user' => 2,
		);

		// Left: sessions.
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=posnova',
			'type' => 'left',
			'titre' => 'PosNovaSessions',
			'mainmenu' => 'posnova',
			'leftmenu' => 'posnova_sessions',
			'url' => '/custom/posnova/sessions.php',
			'langs' => 'posnova@posnova',
			'position' => 1004,
			'enabled' => 'isModEnabled("posnova")',
			'perms' => '$user->hasRight("posnova", "run")',
			'target' => '',
			'user' => 2,
		);

		// Left: terminals configuration (admin).
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=posnova',
			'type' => 'left',
			'titre' => 'PosNovaConfig',
			'mainmenu' => 'posnova',
			'leftmenu' => 'posnova_config',
			'url' => '/custom/posnova/admin/pos_list.php',
			'langs' => 'posnova@posnova',
			'position' => 1005,
			'enabled' => 'isModEnabled("posnova")',
			'perms' => '$user->hasRight("posnova", "setup")',
			'target' => '',
			'user' => 2,
		);
	}

	/**
	 *  Function called when module is enabled.
	 *
	 *  @param      string  $options    Options when enabling module ('', 'noboxes')
	 *  @return     int                 1 if OK, 0 if KO
	 */
	public function init($options = '')
	{
		global $conf, $langs;

		// Create tables and indexes from the sql/ directory of the module.
		$result = $this->_load_tables('/posnova/sql/');
		if ($result < 0) {
			return -1;
		}

		// Clean previous permissions/menus before re-adding them.
		$this->remove($options);

		// Ensure the warehouse <-> bank account link extrafield exists (idempotent).
		$this->loadExtraFields();

		$sql = array();
		return $this->_init($sql, $options);
	}

	/**
	 *  Function called when module is disabled.
	 *
	 *  @param      string  $options    Options when disabling module ('', 'noboxes')
	 *  @return     int                 1 if OK, 0 if KO
	 */
	public function remove($options = '')
	{
		$sql = array();
		return $this->_remove($sql, $options);
	}

	/**
	 *  Create the extrafield linking a bank account / cash register to a warehouse.
	 *
	 *  A warehouse may own several accounts (USD, CDF...). The link is an optional
	 *  'sellist' extrafield on the bank account pointing to llx_entrepot. Idempotent:
	 *  co-exists with the same field if already created by another module.
	 *
	 *  @return int   1 if OK, <0 if KO
	 */
	private function loadExtraFields()
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';

		$extrafields = new ExtraFields($this->db);

		// Do not recreate the field if it already exists (e.g. provided by the BankAudit module).
		$sqlcheck = "SELECT rowid FROM ".MAIN_DB_PREFIX."extrafields";
		$sqlcheck .= " WHERE name = 'warehouse'";
		$sqlcheck .= " AND elementtype = 'bank_account'";
		$sqlcheck .= " AND entity IN (0, ".((int) $conf->entity).")";
		$resql = $this->db->query($sqlcheck);
		if ($resql && $this->db->num_rows($resql) > 0) {
			return 1;
		}

		$extrafields->addExtraField(
			'warehouse',
			'PosNovaLinkedWarehouse',
			'sellist',
			100,
			'',
			'bank_account',
			0,
			0,
			'',
			array('options' => array('entrepot:ref:rowid::statut=1' => null)),
			1,
			'',
			'1',
			'PosNovaLinkedWarehouseHelp',
			'',
			'',
			'posnova@posnova',
			'1'
		);

		return 1;
	}
}

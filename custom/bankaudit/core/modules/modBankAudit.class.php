<?php
/* Copyright (C) 2026 BankAudit module
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
 * \defgroup   bankaudit     Module BankAudit
 * \brief      BankAudit module descriptor.
 *
 * \file       custom/bankaudit/core/modules/modBankAudit.class.php
 * \ingroup    bankaudit
 * \brief      Description and activation file for module BankAudit
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';


/**
 *  Description and activation class for module BankAudit
 */
class modBankAudit extends DolibarrModules
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

		// Id for module (must be unique). 4900000 is in the range reserved for external/custom modules.
		$this->numero = 4900000;

		// Key text used to identify module (for permissions, menus, etc...)
		$this->rights_class = 'bankaudit';

		// Family
		$this->family = "financial";
		$this->module_position = '90';

		// Module label (no space allowed), used if translation string 'ModuleBankAuditName' not found.
		$this->name = preg_replace('/^mod/i', '', get_class($this));

		// Module description
		$this->description = "ModuleBankAuditDesc";
		$this->descriptionlong = "ModuleBankAuditDesc";

		$this->editor_name = 'BankAudit';
		$this->editor_url = '';

		$this->version = '1.1.0';

		// Key used in llx_const table to save module status enabled/disabled
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);

		// Icon
		$this->picto = 'bank_account';

		// Define some features supported by module
		$this->module_parts = array(
			// Module has its own trigger directory (core/triggers)
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
			// Hook contexts managed by this module
			'hooks' => array(
				'data' => array(
					'bankline',
					'banktransfer',
					'warehousecard',
					'variouscard',
				),
				'entity' => '0',
			),
			'moduleforexternal' => 0,
		);

		// Data directories to create when module is enabled.
		$this->dirs = array("/bankaudit/temp");

		// Config pages.
		$this->config_page_url = array("setup.php@bankaudit");

		// Dependencies
		$this->hidden = false;
		$this->depends = array('modBanque', 'modStock'); // Bank/cash and warehouse modules are required
		$this->requiredby = array();
		$this->conflictwith = array();

		// The language file dedicated to your module
		$this->langfiles = array("bankaudit@bankaudit");

		// Prerequisites
		$this->phpmin = array(7, 1);
		$this->need_dolibarr_version = array(19, -3);
		$this->need_javascript_ajax = 1;

		$this->warnings_activation = array();
		$this->warnings_activation_ext = array();

		// Constants (key, 'chaine', value, desc, visible, 'current'|'allentities', deleteonunactive)
		$this->const = array();
		$this->const[0] = array('BANKAUDIT_RATE_ALERT_THRESHOLD', 'chaine', '10', 'Rate variation alert threshold in percent', 0);
		$this->const[1] = array('BANKAUDIT_ANOMALY_AVG_FACTOR', 'chaine', '3', 'Unusual amount factor (x average)', 0);
		$this->const[2] = array('BANKAUDIT_OLD_DATE_DAYS', 'chaine', '30', 'Old operation date threshold in days', 0);
		$this->const[3] = array('BANKAUDIT_INACTIVE_DAYS', 'chaine', '90', 'Inactive account threshold in days', 0);
		$this->const[4] = array('BANKAUDIT_DUPLICATE_DAYS', 'chaine', '1', 'Duplicate detection window in days', 0);
		$this->const[5] = array('BANKAUDIT_ENABLE_BALANCE_GUARD', 'chaine', '1', 'Enable balance guard before internal transfer', 0);
		$this->const[6] = array('BANKAUDIT_TICKET_COPIES', 'chaine', '1', 'Number of cash ticket copies to print (80mm)', 0);
		$this->const[7] = array('BANKAUDIT_DEFAULT_PAYMENT_MODE', 'chaine', 'LIQ', 'Default payment mode code for the new entry form', 0);

		if (!isModEnabled("bankaudit")) {
			$conf->bankaudit = new stdClass();
			$conf->bankaudit->enabled = 0;
		}

		// Tabs: replace the native "Info/Suivi" tab of a bank line by our modification-history tab.
		// The native bankline_prepare_head() passes a NULL object to complete_head_from_modules(),
		// so __ID__ cannot be substituted here. The tab is therefore added (with the correct rowid)
		// through the completeTabsHead hook in ActionsBankaudit. We only remove the native info tab here.
		$this->tabs = array();
		$this->tabs[] = 'bankline:-info';

		// Dictionaries
		$this->dictionaries = array();

		// Boxes/Widgets
		$this->boxes = array();

		// Cronjobs
		$this->cronjobs = array(
			1 => array(
				'label' => 'Daily treasury snapshot report (R02)',
				'jobtype' => 'method',
				'class' => '/custom/bankaudit/class/bankauditreports.class.php',
				'objectname' => 'BankAuditReports',
				'method' => 'sendScheduledReport',
				'parameters' => 'R02',
				'comment' => 'Generate daily treasury snapshot report',
				'frequency' => 1,
				'unitfrequency' => 86400,
				'priority' => 50,
				'datestart' => -1,
				'enabled' => '$conf->bankaudit->enabled',
			),
			2 => array(
				'label' => 'Weekly anomaly report (R12)',
				'jobtype' => 'method',
				'class' => '/custom/bankaudit/class/bankauditreports.class.php',
				'objectname' => 'BankAuditReports',
				'method' => 'sendScheduledReport',
				'parameters' => 'R12',
				'comment' => 'Generate weekly anomaly summary report',
				'frequency' => 7,
				'unitfrequency' => 86400,
				'priority' => 50,
				'datestart' => -1,
				'enabled' => '$conf->bankaudit->enabled',
			),
		);

		// Permissions provided by this module
		$this->rights = array();
		$r = 0;

		$this->rights[$r][0] = 4900001;
		$this->rights[$r][1] = 'Read audit and treasury dashboard';
		$this->rights[$r][4] = 'read';
		$this->rights[$r][5] = '';
		$r++;

		$this->rights[$r][0] = 4900002;
		$this->rights[$r][1] = 'Configure module (thresholds, anomaly rules)';
		$this->rights[$r][4] = 'setup';
		$this->rights[$r][5] = '';
		$r++;

		$this->rights[$r][0] = 4900003;
		$this->rights[$r][1] = 'Read financial reports';
		$this->rights[$r][4] = 'reports';
		$this->rights[$r][5] = 'read';
		$r++;

		$this->rights[$r][0] = 4900004;
		$this->rights[$r][1] = 'Export financial reports';
		$this->rights[$r][4] = 'reports';
		$this->rights[$r][5] = 'export';
		$r++;

		$this->rights[$r][0] = 4900005;
		$this->rights[$r][1] = 'Read audit reports';
		$this->rights[$r][4] = 'audit';
		$this->rights[$r][5] = 'read';
		$r++;

		// Main menu entries (attached under the Bank top menu)
		$this->menu = array();
		$r = 0;

		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=bank,fk_leftmenu=bank',
			'type' => 'left',
			'titre' => 'BankAuditNewEntry',
			'mainmenu' => 'bank',
			'leftmenu' => 'bankaudit_newentry',
			'url' => '/custom/bankaudit/entry_card.php?action=create',
			'langs' => 'bankaudit@bankaudit',
			'position' => 50,
			'enabled' => 'isModEnabled("bankaudit")',
			'perms' => '$user->hasRight("banque", "modifier")',
			'target' => '',
			'user' => 2,
		);

		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=bank',
			'type' => 'left',
			'titre' => 'TreasuryDashboard',
			'mainmenu' => 'bank',
			'leftmenu' => 'bankaudit_dashboard',
			'url' => '/custom/bankaudit/treasury_dashboard.php',
			'langs' => 'bankaudit@bankaudit',
			'position' => 1100,
			'enabled' => 'isModEnabled("bankaudit")',
			'perms' => '$user->hasRight("bankaudit", "read")',
			'target' => '',
			'user' => 2,
		);

		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=bank',
			'type' => 'left',
			'titre' => 'RateHistoryMenu',
			'mainmenu' => 'bank',
			'leftmenu' => 'bankaudit_rate_history',
			'url' => '/custom/bankaudit/rate_history.php',
			'langs' => 'bankaudit@bankaudit',
			'position' => 1101,
			'enabled' => 'isModEnabled("bankaudit") && isModEnabled("multicurrency")',
			'perms' => '$user->hasRight("bankaudit", "read")',
			'target' => '',
			'user' => 2,
		);

		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=bank',
			'type' => 'left',
			'titre' => 'AnomaliesMenu',
			'mainmenu' => 'bank',
			'leftmenu' => 'bankaudit_anomalies',
			'url' => '/custom/bankaudit/anomalies.php',
			'langs' => 'bankaudit@bankaudit',
			'position' => 1102,
			'enabled' => 'isModEnabled("bankaudit")',
			'perms' => '$user->hasRight("bankaudit", "read")',
			'target' => '',
			'user' => 2,
		);

		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=bank',
			'type' => 'left',
			'titre' => 'BankReportsMenu',
			'mainmenu' => 'bank',
			'leftmenu' => 'bankaudit_reports',
			'url' => '/custom/bankaudit/reports.php',
			'langs' => 'bankaudit@bankaudit',
			'position' => 1103,
			'enabled' => 'isModEnabled("bankaudit")',
			'perms' => '$user->hasRight("bankaudit", "reports", "read") || $user->hasRight("bankaudit", "read")',
			'target' => '',
			'user' => 2,
		);

		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=bank',
			'type' => 'left',
			'titre' => 'BankAuditLogMenu',
			'mainmenu' => 'bank',
			'leftmenu' => 'bankaudit_auditlog',
			'url' => '/custom/bankaudit/audit_log.php',
			'langs' => 'bankaudit@bankaudit',
			'position' => 1104,
			'enabled' => 'isModEnabled("bankaudit")',
			'perms' => '$user->hasRight("bankaudit", "audit", "read") || $user->hasRight("bankaudit", "read")',
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

		// Create tables and indexes from the sql/ directory of the module
		$result = $this->_load_tables('/bankaudit/sql/');
		if ($result < 0) {
			return -1;
		}

		// Clean previous permissions/menus before re-adding them
		$this->remove($options);

		// Seed the default anomaly detection rules (only when not already present)
		$this->loadDefaultAnomalyRules();

		// Create the extrafield linking a bank account / cash register to a warehouse
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
	 *  Insert the default anomaly rules for the current entity if the table is empty.
	 *
	 *  @return int   1 if OK, <0 if KO
	 */
	private function loadDefaultAnomalyRules()
	{
		global $conf;

		$entity = (int) $conf->entity;

		// Default rules: code, label key, seuil, unit
		$defaults = array(
			array('R01', 'Doublon probable', 1, 'jours'),
			array('R02', 'Montant inhabituel', 3, 'x'),
			array('R03', 'Ecriture a date ancienne', 30, 'jours'),
			array('R04', 'Taux de change anormal', 10, '%'),
			array('R05', 'Solde negatif', null, 'USD'),
			array('R06', 'Compte inactif mouvemente', 90, 'jours'),
		);

		foreach ($defaults as $rule) {
			// Skip if the rule already exists for this entity
			$sqlcheck = "SELECT rowid FROM ".MAIN_DB_PREFIX."bank_anomaly_rule";
			$sqlcheck .= " WHERE code = '".$this->db->escape($rule[0])."' AND entity = ".$entity;
			$resql = $this->db->query($sqlcheck);
			if ($resql && $this->db->num_rows($resql) > 0) {
				continue;
			}

			$sql = "INSERT INTO ".MAIN_DB_PREFIX."bank_anomaly_rule";
			$sql .= " (code, label, active, seuil, seuil_unite, fk_account, action_email, action_notif, entity)";
			$sql .= " VALUES (";
			$sql .= "'".$this->db->escape($rule[0])."',";
			$sql .= " '".$this->db->escape($rule[1])."',";
			$sql .= " 1,";
			$sql .= " ".(is_null($rule[2]) ? "NULL" : ((float) $rule[2])).",";
			$sql .= " '".$this->db->escape($rule[3])."',";
			$sql .= " NULL,";
			$sql .= " 1,";
			$sql .= " 1,";
			$sql .= " ".$entity;
			$sql .= ")";

			$this->db->query($sql);
		}

		return 1;
	}

	/**
	 *  Create the extrafields managed by this module.
	 *
	 *  Adds an optional link from a bank account / cash register (element 'bank_account')
	 *  to a warehouse (table llx_entrepot). A warehouse may be linked to several accounts.
	 *
	 *  @return int   1 if OK, <0 if KO
	 */
	private function loadExtraFields()
	{
		require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';

		$extrafields = new ExtraFields($this->db);

		// 1) Optional link from a bank account / cash register to a warehouse (sellist).
		$this->ensureExtraField(
			$extrafields,
			'warehouse',
			'BankAuditLinkedWarehouse',
			'sellist',
			'bank_account',
			array('options' => array('entrepot:ref:rowid::statut=1' => null)),
			'BankAuditLinkedWarehouseHelp',
			100,
			''
		);

		// 2) Beneficiary on a bank line, used by debit entries of the custom entry form.
		$this->ensureExtraField(
			$extrafields,
			'beneficiaire',
			'BankAuditBeneficiary',
			'varchar',
			'bank',
			'',
			'BankAuditBeneficiaryHelp',
			50,
			'255'
		);

		return 1;
	}

	/**
	 *  Create one extrafield if it does not already exist for the current entity.
	 *
	 *  @param  ExtraFields    $extrafields  Extrafields handler
	 *  @param  string         $attrname     Field code
	 *  @param  string         $label        Label (lang key)
	 *  @param  string         $type         Field type
	 *  @param  string         $elementtype  Element type
	 *  @param  array|string   $param        Parameters (for sellist)
	 *  @param  string         $help         Help (lang key)
	 *  @param  int            $pos          Position
	 *  @param  string         $size         Size
	 *  @return int                          1 if OK
	 */
	private function ensureExtraField($extrafields, $attrname, $label, $type, $elementtype, $param, $help, $pos, $size = '')
	{
		global $conf;

		$sqlcheck = "SELECT rowid FROM ".MAIN_DB_PREFIX."extrafields";
		$sqlcheck .= " WHERE name = '".$this->db->escape($attrname)."'";
		$sqlcheck .= " AND elementtype = '".$this->db->escape($elementtype)."'";
		$sqlcheck .= " AND entity IN (0, ".((int) $conf->entity).")";
		$resql = $this->db->query($sqlcheck);
		if ($resql && $this->db->num_rows($resql) > 0) {
			return 1;
		}

		$extrafields->addExtraField(
			$attrname,
			$label,
			$type,
			$pos,
			$size,
			$elementtype,
			0,
			0,
			'',
			$param,
			1,
			'',
			'1',
			$help,
			'',
			'',
			'bankaudit@bankaudit',
			'1'
		);

		return 1;
	}
}

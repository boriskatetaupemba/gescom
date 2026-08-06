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
 * \file    custom/posnova/class/posconfig.class.php
 * \ingroup posnova
 * \brief   POS terminal configuration object (CRUD + linked accounts).
 */

dol_include_once('/posnova/class/posnova.class.php');


/**
 * Manage a Point of Sale terminal: warehouse binding, activated cash accounts,
 * default currency/payment, behavioural flags and launch validation.
 */
class PosConfig
{
	/** @var DoliDB */
	public $db;
	/** @var string */
	public $error = '';

	public $id;
	public $entity;
	public $ref;
	public $label;
	public $fk_warehouse;
	public $fk_default_account;
	public $default_payment_mode = 'LIQ';
	public $max_discount_percent = 0;
	public $allow_price_edit = 0;
	public $allow_sale_without_stock = 0;
	public $transfer_enabled = 0;
	public $offline_mode = 1;
	public $allow_backdating = 0;
	public $print_format = '80mm';
	public $autoprint = 1;
	public $fk_default_customer;
	public $low_stock_threshold = 5;
	public $transfer_timeout_hours = 24;
	public $cancel_escalation_min = 30;
	public $active = 1;

	/**
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Load a terminal by id.
	 *
	 * @param  int $id Terminal id
	 * @return int     1 if found, 0 if not, <0 on error
	 */
	public function fetch($id)
	{
		$sql = "SELECT rowid, entity, ref, label, fk_warehouse, fk_default_account, default_payment_mode,";
		$sql .= " max_discount_percent, allow_price_edit, allow_sale_without_stock, transfer_enabled,";
		$sql .= " offline_mode, allow_backdating, print_format, autoprint, fk_default_customer,";
		$sql .= " low_stock_threshold, transfer_timeout_hours, cancel_escalation_min, active";
		$sql .= " FROM ".MAIN_DB_PREFIX."pos_config WHERE rowid = ".((int) $id);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		if (!$this->db->num_rows($resql)) {
			return 0;
		}
		$obj = $this->db->fetch_object($resql);
		$this->id = (int) $obj->rowid;
		$this->entity = (int) $obj->entity;
		$this->ref = $obj->ref;
		$this->label = $obj->label;
		$this->fk_warehouse = (int) $obj->fk_warehouse;
		$this->fk_default_account = $obj->fk_default_account ? (int) $obj->fk_default_account : null;
		$this->default_payment_mode = $obj->default_payment_mode;
		$this->max_discount_percent = (float) $obj->max_discount_percent;
		$this->allow_price_edit = (int) $obj->allow_price_edit;
		$this->allow_sale_without_stock = (int) $obj->allow_sale_without_stock;
		$this->transfer_enabled = (int) $obj->transfer_enabled;
		$this->offline_mode = (int) $obj->offline_mode;
		$this->allow_backdating = (int) $obj->allow_backdating;
		$this->print_format = $obj->print_format;
		$this->autoprint = (int) $obj->autoprint;
		$this->fk_default_customer = $obj->fk_default_customer ? (int) $obj->fk_default_customer : null;
		$this->low_stock_threshold = (int) $obj->low_stock_threshold;
		$this->transfer_timeout_hours = (int) $obj->transfer_timeout_hours;
		$this->cancel_escalation_min = (int) $obj->cancel_escalation_min;
		$this->active = (int) $obj->active;
		return 1;
	}

	/**
	 * List all terminals of the current entity.
	 *
	 * @param  bool $activeonly Restrict to active terminals
	 * @return PosConfig[]      Array of loaded terminals
	 */
	public function fetchAll($activeonly = false)
	{
		global $conf;

		$out = array();
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."pos_config";
		$sql .= " WHERE entity IN (".getEntity('posnova').")";
		if ($activeonly) {
			$sql .= " AND active = 1";
		}
		$sql .= " ORDER BY ref ASC";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return $out;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$c = new PosConfig($this->db);
			if ($c->fetch((int) $obj->rowid) > 0) {
				$out[] = $c;
			}
		}
		return $out;
	}

	/**
	 * Create a terminal.
	 *
	 * @param  User $user Acting user
	 * @return int        New id, <0 on error
	 */
	public function create($user)
	{
		global $conf;

		$this->ref = trim($this->ref);
		$this->label = trim($this->label);
		if ($this->ref === '' || $this->label === '' || empty($this->fk_warehouse)) {
			$this->error = 'MissingMandatoryFields';
			return -1;
		}

		$now = dol_now();
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."pos_config";
		$sql .= " (entity, ref, label, fk_warehouse, fk_default_account, default_payment_mode,";
		$sql .= " max_discount_percent, allow_price_edit, allow_sale_without_stock, transfer_enabled,";
		$sql .= " offline_mode, allow_backdating, print_format, autoprint, fk_default_customer,";
		$sql .= " low_stock_threshold, transfer_timeout_hours, cancel_escalation_min, active, datec, fk_user_creat)";
		$sql .= " VALUES (".$this->sqlValues($user, $now).")";

		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -2;
		}
		$this->id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX."pos_config");

		PosNova::audit($this->db, $user, 'POS_CREATE', 'pos_config', $this->id, null, $this->ref, $this->id);
		return $this->id;
	}

	/**
	 * Update a terminal.
	 *
	 * @param  User $user Acting user
	 * @return int        1 on success, <0 on error
	 */
	public function update($user)
	{
		if (empty($this->id)) {
			return -1;
		}
		// The warehouse cannot be changed once the terminal has recorded sales.
		if ($this->hasSales()) {
			$orig = new PosConfig($this->db);
			$orig->fetch($this->id);
			$this->fk_warehouse = $orig->fk_warehouse;
		}

		$sql = "UPDATE ".MAIN_DB_PREFIX."pos_config SET";
		$sql .= " ref = '".$this->db->escape($this->ref)."',";
		$sql .= " label = '".$this->db->escape($this->label)."',";
		$sql .= " fk_warehouse = ".((int) $this->fk_warehouse).",";
		$sql .= " fk_default_account = ".($this->fk_default_account ? ((int) $this->fk_default_account) : "NULL").",";
		$sql .= " default_payment_mode = '".$this->db->escape($this->default_payment_mode)."',";
		$sql .= " max_discount_percent = ".((float) $this->max_discount_percent).",";
		$sql .= " allow_price_edit = ".((int) $this->allow_price_edit).",";
		$sql .= " allow_sale_without_stock = ".((int) $this->allow_sale_without_stock).",";
		$sql .= " transfer_enabled = ".((int) $this->transfer_enabled).",";
		$sql .= " offline_mode = ".((int) $this->offline_mode).",";
		$sql .= " allow_backdating = ".((int) $this->allow_backdating).",";
		$sql .= " print_format = '".$this->db->escape($this->print_format)."',";
		$sql .= " autoprint = ".((int) $this->autoprint).",";
		$sql .= " fk_default_customer = ".($this->fk_default_customer ? ((int) $this->fk_default_customer) : "NULL").",";
		$sql .= " low_stock_threshold = ".((int) $this->low_stock_threshold).",";
		$sql .= " transfer_timeout_hours = ".((int) $this->transfer_timeout_hours).",";
		$sql .= " cancel_escalation_min = ".((int) $this->cancel_escalation_min).",";
		$sql .= " active = ".((int) $this->active).",";
		$sql .= " fk_user_modif = ".((int) $user->id);
		$sql .= " WHERE rowid = ".((int) $this->id);

		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -2;
		}
		PosNova::audit($this->db, $user, 'POS_UPDATE', 'pos_config', $this->id, null, $this->ref, $this->id);
		return 1;
	}

	/**
	 * Build the VALUES clause shared by create().
	 *
	 * @param  User $user Acting user
	 * @param  int  $now  Timestamp
	 * @return string     SQL fragment
	 */
	private function sqlValues($user, $now)
	{
		global $conf;
		$v = ((int) $conf->entity).",";
		$v .= "'".$this->db->escape($this->ref)."',";
		$v .= "'".$this->db->escape($this->label)."',";
		$v .= ((int) $this->fk_warehouse).",";
		$v .= ($this->fk_default_account ? ((int) $this->fk_default_account) : "NULL").",";
		$v .= "'".$this->db->escape($this->default_payment_mode)."',";
		$v .= ((float) $this->max_discount_percent).",";
		$v .= ((int) $this->allow_price_edit).",";
		$v .= ((int) $this->allow_sale_without_stock).",";
		$v .= ((int) $this->transfer_enabled).",";
		$v .= ((int) $this->offline_mode).",";
		$v .= ((int) $this->allow_backdating).",";
		$v .= "'".$this->db->escape($this->print_format)."',";
		$v .= ((int) $this->autoprint).",";
		$v .= ($this->fk_default_customer ? ((int) $this->fk_default_customer) : "NULL").",";
		$v .= ((int) $this->low_stock_threshold).",";
		$v .= ((int) $this->transfer_timeout_hours).",";
		$v .= ((int) $this->cancel_escalation_min).",";
		$v .= ((int) $this->active).",";
		$v .= "'".$this->db->idate($now)."',";
		$v .= ((int) $user->id);
		return $v;
	}

	/**
	 * Whether this terminal already recorded at least one ticket (warehouse becomes immutable).
	 *
	 * @return bool
	 */
	public function hasSales()
	{
		if (empty($this->id)) {
			return false;
		}
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."pos_ticket WHERE fk_pos = ".((int) $this->id)." LIMIT 1";
		$resql = $this->db->query($sql);
		return ($resql && $this->db->num_rows($resql) > 0);
	}

	/**
	 * Return every bank account / cash register attached to the terminal warehouse
	 * (link held by the 'warehouse' extrafield on the bank account).
	 *
	 * @return array[] Each: rowid, ref, label, currency_code
	 */
	public function getWarehouseAccounts()
	{
		$out = array();
		if (empty($this->fk_warehouse)) {
			return $out;
		}
		$sql = "SELECT ba.rowid, ba.ref, ba.label, ba.currency_code";
		$sql .= " FROM ".MAIN_DB_PREFIX."bank_account ba";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."bank_account_extrafields ef ON ef.fk_object = ba.rowid";
		$sql .= " WHERE ef.warehouse = ".((int) $this->fk_warehouse);
		$sql .= " AND ba.entity IN (".getEntity('bank_account').")";
		$sql .= " AND ba.clos = 0";
		$sql .= " ORDER BY ba.currency_code, ba.label";
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$out[] = array(
					'rowid' => (int) $obj->rowid,
					'ref' => $obj->ref,
					'label' => $obj->label,
					'currency_code' => strtoupper((string) $obj->currency_code),
				);
			}
		}
		return $out;
	}

	/**
	 * Return the accounts activated on the terminal (joined with bank account data).
	 *
	 * @param  bool $activeonly Restrict to active rows
	 * @return array[]          Each: id, fk_account, ref, label, currency_code, is_default, is_active, default_payment_mode
	 */
	public function getAccounts($activeonly = false)
	{
		$out = array();
		if (empty($this->id)) {
			return $out;
		}
		$sql = "SELECT pca.rowid, pca.fk_account, pca.is_default, pca.is_active, pca.default_payment_mode,";
		$sql .= " ba.ref, ba.label, ba.currency_code";
		$sql .= " FROM ".MAIN_DB_PREFIX."pos_config_account pca";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."bank_account ba ON ba.rowid = pca.fk_account";
		$sql .= " WHERE pca.fk_pos = ".((int) $this->id);
		if ($activeonly) {
			$sql .= " AND pca.is_active = 1";
		}
		$sql .= " ORDER BY ba.currency_code, pca.position, ba.label";
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$out[] = array(
					'id' => (int) $obj->rowid,
					'fk_account' => (int) $obj->fk_account,
					'ref' => $obj->ref,
					'label' => $obj->label,
					'currency_code' => strtoupper((string) $obj->currency_code),
					'is_default' => (int) $obj->is_default,
					'is_active' => (int) $obj->is_active,
					'default_payment_mode' => $obj->default_payment_mode,
				);
			}
		}
		return $out;
	}

	/**
	 * Replace the activated-account set of the terminal.
	 *
	 * @param  array $accounts Array of arrays: fk_account, is_active, is_default, default_payment_mode
	 * @return int             1 on success, <0 on error
	 */
	public function setAccounts($accounts)
	{
		global $conf;

		if (empty($this->id)) {
			return -1;
		}
		$this->db->query("DELETE FROM ".MAIN_DB_PREFIX."pos_config_account WHERE fk_pos = ".((int) $this->id));

		$pos = 0;
		foreach ($accounts as $a) {
			$fkacc = (int) $a['fk_account'];
			if ($fkacc <= 0) {
				continue;
			}
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."pos_config_account";
			$sql .= " (entity, fk_pos, fk_account, is_default, is_active, default_payment_mode, position)";
			$sql .= " VALUES (".((int) $conf->entity).", ".((int) $this->id).", ".$fkacc.",";
			$sql .= " ".(!empty($a['is_default']) ? 1 : 0).",";
			$sql .= " ".(!empty($a['is_active']) ? 1 : 0).",";
			$sql .= " ".(empty($a['default_payment_mode']) ? "NULL" : "'".$this->db->escape($a['default_payment_mode'])."'").",";
			$sql .= " ".($pos++).")";
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				return -2;
			}
		}
		return 1;
	}

	/**
	 * Working currency of the terminal = currency of its default account.
	 *
	 * @return string Currency code, defaults to USD
	 */
	public function getWorkingCurrency()
	{
		if (!empty($this->fk_default_account)) {
			$cur = PosNova::getAccountCurrency($this->db, $this->fk_default_account);
			if ($cur !== '') {
				return $cur;
			}
		}
		return PosNova::USD;
	}

	/**
	 * Validate that the terminal can be launched: bound warehouse and at least one active
	 * USD account and one active CDF account.
	 *
	 * @return array array('ok'=>bool, 'errors'=>string[])
	 */
	public function validateForLaunch()
	{
		$errors = array();
		if (empty($this->fk_warehouse)) {
			$errors[] = 'PosNovaErrNoWarehouse';
		}
		$cdfCode = getDolGlobalString('POSNOVA_CDF_CURRENCY_CODE', PosNova::CDF);
		$hasUsd = false;
		$hasCdf = false;
		foreach ($this->getAccounts(true) as $a) {
			if ($a['currency_code'] === PosNova::USD) {
				$hasUsd = true;
			}
			if ($a['currency_code'] === $cdfCode) {
				$hasCdf = true;
			}
		}
		if (!$hasUsd) {
			$errors[] = 'PosNovaErrNoUsdAccount';
		}
		if (!$hasCdf) {
			$errors[] = 'PosNovaErrNoCdfAccount';
		}
		if (empty($this->fk_default_account)) {
			$errors[] = 'PosNovaErrNoDefaultAccount';
		}
		return array('ok' => empty($errors), 'errors' => $errors);
	}
}

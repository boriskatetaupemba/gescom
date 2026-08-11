<?php
/* Copyright (C) 2026 BankAudit module
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    custom/bankaudit/class/bankauditusercontext.class.php
 * \ingroup bankaudit
 * \brief   User warehouse and cash-account context service.
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
require_once DOL_DOCUMENT_ROOT.'/multicurrency/class/multicurrency.class.php';

/**
 * Build the warehouse context formerly added directly to the native login API.
 */
class BankAuditUserContext
{
	/** @var DoliDB */
	private $db;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Return the user's default warehouse and its linked bank/cash accounts.
	 *
	 * @param User $contextUser User whose own context is requested
	 * @return array|null       Context, or null when no default warehouse exists
	 * @throws RuntimeException On a database read error
	 */
	public function getDefaultWarehouse($contextUser)
	{
		global $langs;

		$warehouseId = (int) $contextUser->fk_warehouse;
		if ($warehouseId <= 0) {
			return null;
		}

		if (is_object($langs)) {
			$langs->loadLangs(array('stocks', 'banks'));
		}

		$sql = 'SELECT e.rowid, e.ref, e.lieu, e.description, e.address, e.zip, e.town, e.fk_pays, e.phone, e.statut';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'entrepot AS e';
		$sql .= ' WHERE e.rowid = '.$warehouseId;
		$sql .= " AND e.entity IN (".getEntity('stock').")";
		$result = $this->db->query($sql);
		if (!$result) {
			throw new RuntimeException('Unable to read the default warehouse: '.$this->db->lasterror());
		}
		if (!$this->db->num_rows($result)) {
			$this->db->free($result);
			return null;
		}

		$object = $this->db->fetch_object($result);
		$this->db->free($result);
		$countryLabel = '';
		if (!empty($object->fk_pays)) {
			$countryLabel = getCountry($object->fk_pays, '', $this->db);
			if ($countryLabel === 'NotDefined') {
				$countryLabel = '';
			}
		}

		$warehouse = array(
			'id' => (int) $object->rowid,
			'ref' => $object->ref,
			'nom' => ($object->lieu !== '' && $object->lieu !== null) ? $object->lieu : $object->ref,
			'description' => $object->description,
			'adresse' => $object->address,
			'ville' => trim($object->zip.' '.$object->town),
			'pays' => $countryLabel,
			'telephone' => $object->phone,
			'etat' => ((int) $object->statut === 1) ? 'Opened' : 'Closed',
			'comptes_caisses' => array(),
		);

		$typeLabels = array(
			0 => is_object($langs) ? $langs->trans('BankType0') : 'BankType0',
			1 => is_object($langs) ? $langs->trans('BankType1') : 'BankType1',
			2 => is_object($langs) ? $langs->trans('BankType2') : 'BankType2',
		);

		$sql = 'SELECT ba.rowid, ba.ref, ba.label, ba.courant, ba.number, ba.currency_code, ba.clos';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'bank_account AS ba';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'bank_account_extrafields AS ef ON ef.fk_object = ba.rowid';
		$sql .= ' WHERE ef.warehouse = '.$warehouseId;
		$sql .= " AND ba.entity IN (".getEntity('bank_account').")";
		$sql .= ' ORDER BY ba.clos ASC, ba.label ASC';
		$result = $this->db->query($sql);
		if (!$result) {
			throw new RuntimeException('Unable to read warehouse accounts: '.$this->db->lasterror());
		}

		while ($account = $this->db->fetch_object($result)) {
			$accountId = (int) $account->rowid;
			$balance = 0.0;
			$balanceSql = 'SELECT SUM(amount) AS amount FROM '.MAIN_DB_PREFIX.'bank WHERE fk_account = '.$accountId;
			$balanceResult = $this->db->query($balanceSql);
			if (!$balanceResult) {
				$this->db->free($result);
				throw new RuntimeException('Unable to read account balance: '.$this->db->lasterror());
			}
			$balanceObject = $this->db->fetch_object($balanceResult);
			if ($balanceObject) {
				$balance = (float) price2num($balanceObject->amount, 'MU');
			}
			$this->db->free($balanceResult);

			$conversionRate = 1.0;
			if (!empty($account->currency_code)) {
				$rate = MultiCurrency::getIdAndTxFromCode($this->db, $account->currency_code);
				if (isset($rate[1]) && (float) $rate[1] != 0.0) {
					$conversionRate = (float) $rate[1];
				}
			}

			$warehouse['comptes_caisses'][] = array(
				'id' => $accountId,
				'ref' => $account->ref,
				'libelle' => $account->label,
				'type' => isset($typeLabels[(int) $account->courant]) ? $typeLabels[(int) $account->courant] : '',
				'numero' => $account->number,
				'devise' => $account->currency_code,
				'taux_conversion' => $conversionRate,
				'solde' => $balance,
				'etat' => ((int) $account->clos === 0) ? 'Opened' : 'Closed',
			);
		}
		$this->db->free($result);

		return $warehouse;
	}
}

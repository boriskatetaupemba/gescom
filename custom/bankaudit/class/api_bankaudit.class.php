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

use Luracast\Restler\RestException;

dol_include_once('/bankaudit/class/bankauditusercontext.class.php');

/**
 * \file       custom/bankaudit/class/api_bankaudit.class.php
 * \ingroup    bankaudit
 * \brief      API to expose bank entries of the accounts / cash registers of a warehouse.
 */

/**
 * API class for the BankAudit module.
 *
 * Note: the class is named "BankAuditApi" (and not "BankAudit") to avoid a collision
 * with the existing service class BankAudit (class/bankaudit.class.php). The Dolibarr API
 * loader publishes it under the endpoint base "/bankauditapi".
 *
 * @access protected
 * @class  DolibarrApiAccess {@requires user}
 */
class BankAuditApi extends DolibarrApi
{
	/**
	 * Constructor
	 */
	public function __construct()
	{
		global $db;
		$this->db = $db;
	}

	/**
	 * Return the authenticated user's default warehouse and linked cash accounts.
	 *
	 * This endpoint replaces the former core customization of POST /login. A
	 * client first obtains its token from the native login route, then calls
	 * this endpoint with the DOLAPIKEY header.
	 *
	 * @return array User context containing a nullable default_warehouse property
	 *
	 * @url GET /context
	 *
	 * @throws RestException 403 Access denied
	 * @throws RestException 503 Database read error
	 */
	public function getCurrentUserContext()
	{
		$this->assertModuleEnabled();
		$apiUser = DolibarrApiAccess::$user;
		if (!$apiUser->hasRight('stock', 'lire') || !$apiUser->hasRight('banque', 'lire')) {
			throw new RestException(403, 'Stock and bank-account read permissions are required.');
		}

		$warehouseId = (int) $apiUser->fk_warehouse;
		if ($warehouseId > 0 && !DolibarrApi::_checkAccessToResource('stock', $warehouseId, 'entrepot')) {
			throw new RestException(403, 'Access not allowed to the default warehouse.');
		}

		try {
			$contextService = new BankAuditUserContext($this->db);
			$defaultWarehouse = $contextService->getDefaultWarehouse($apiUser);
		} catch (RuntimeException $exception) {
			dol_syslog(__METHOD__.' '.$exception->getMessage(), LOG_ERR);
			throw new RestException(503, 'Unable to read the user warehouse context.');
		}

		return array('default_warehouse' => $defaultWarehouse);
	}

	/**
	 * List bank entries of the accounts / cash registers of a warehouse
	 *
	 * Return the list of bank entries (rows of the bank ledger) for every account / cash register
	 * linked to the given warehouse (link held by the 'warehouse' extrafield of the bank account).
	 *
	 * @param   int     $warehouse_id     Id of the warehouse / store (mandatory)
	 * @param   string  $account_ids      Optional list of account / cash register ids to restrict to (example '9' or '8,9') {@pattern /^[0-9,]*$/i}
	 * @param   string  $date_start       Optional start date (value date), format 'YYYY-MM-DD'
	 * @param   string  $date_end         Optional end date (value date), format 'YYYY-MM-DD'
	 * @param   string  $sortfield        Sort field (default b.datev)
	 * @param   string  $sortorder        Sort order (ASC or DESC, default DESC)
	 * @param   int     $limit            Limit for list (default 100, 0 = no limit)
	 * @param   int     $page             Page number (default 0)
	 * @param   string  $sqlfilters       Other criteria to filter answers separated by a comma. Syntax example "(b.amount:<:'0')"
	 * @return  array                     Array of bank entries
	 *
	 * @url GET /warehouse/{warehouse_id}/entries
	 *
	 * @throws RestException 400 Bad value for a parameter
	 * @throws RestException 403 Access denied
	 * @throws RestException 503 Error
	 */
	public function getWarehouseEntries($warehouse_id, $account_ids = '', $date_start = '', $date_end = '', $sortfield = "b.datev", $sortorder = 'DESC', $limit = 100, $page = 0, $sqlfilters = '')
	{
		global $conf;
		$this->assertModuleEnabled();

		// The viewer must be allowed to read bank accounts
		if (!DolibarrApiAccess::$user->hasRight('banque', 'lire')) {
			throw new RestException(403, "Insufficient rights to read bank accounts.");
		}

		$warehouse_id = (int) $warehouse_id;
		if ($warehouse_id <= 0) {
			throw new RestException(400, "Parameter warehouse_id is mandatory and must be a positive integer.");
		}

		// Optional restriction on account ids: must be a comma separated list of integers
		if ($account_ids !== '' && !preg_match('/^[0-9]+(,[0-9]+)*$/', $account_ids)) {
			throw new RestException(400, "Parameter account_ids must be a comma separated list of bank account / cash register ids (example '9' or '8,9').");
		}

		$obj_ret = array();

		$sql = "SELECT b.rowid, b.fk_account, b.datev, b.label, b.amount, b.fk_type,";
		$sql .= " ba.ref as account_ref, ba.currency_code,";
		$sql .= " bu.url_id as tiers_id, soc.nom as tiers_name, bu.label as tiers_label,";
		$sql .= " b.fk_user_author, u.login as author_login, u.firstname as author_firstname, u.lastname as author_lastname";
		$sql .= " FROM ".MAIN_DB_PREFIX."entrepot as e";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."bank_account_extrafields as ef ON ef.warehouse = e.rowid";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."bank_account as ba ON ba.rowid = ef.fk_object";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."bank as b ON b.fk_account = ba.rowid";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."bank_url as bu ON bu.fk_bank = b.rowid AND bu.type = 'company'";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."societe as soc ON soc.rowid = bu.url_id";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."user as u ON u.rowid = b.fk_user_author";
		$sql .= " WHERE e.entity IN (".getEntity('stock').")";
		$sql .= " AND ba.entity IN (".getEntity('bank_account').")";
		$sql .= " AND e.rowid = ".((int) $warehouse_id);
		if ($account_ids !== '') {
			$sql .= " AND ba.rowid IN (".$this->db->sanitize($account_ids).")";
		}
		// Filter by value date range
		$tmpstart = dol_stringtotime($date_start);
		if (!empty($date_start) && $tmpstart > 0) {
			$sql .= " AND b.datev >= '".$this->db->idate($tmpstart)."'";
		}
		$tmpend = dol_stringtotime($date_end);
		if (!empty($date_end) && $tmpend > 0) {
			$sql .= " AND b.datev <= '".$this->db->idate($tmpend)."'";
		}
		// Add sql filters
		if ($sqlfilters) {
			$errormessage = '';
			$sql .= forgeSQLFromUniversalSearchCriteria($sqlfilters, $errormessage);
			if ($errormessage) {
				throw new RestException(400, 'Error when validating parameter sqlfilters -> '.$errormessage);
			}
		}

		$sql .= $this->db->order($sortfield, $sortorder);
		if ($limit) {
			if ($page < 0) {
				$page = 0;
			}
			$offset = $limit * $page;
			$sql .= $this->db->plimit($limit + 1, $offset);
		}

		$result = $this->db->query($sql);
		if (!$result) {
			throw new RestException(503, 'Error when retrieving the list of bank entries : '.$this->db->lasterror());
		}

		global $langs;
		$langs->loadLangs(array('banks', 'bills'));

		$num = $this->db->num_rows($result);
		$min = min($num, ($limit <= 0 ? $num : $limit));
		$lineids = array();
		$i = 0;
		while ($i < $min) {
			$obj = $this->db->fetch_object($result);
			$lineids[] = (int) $obj->rowid;

			// Payment type label (translated), fallback to the raw code
			$typelabel = '';
			if (!empty($obj->fk_type)) {
				$typelabel = $langs->trans('PaymentType'.$obj->fk_type);
				if ($typelabel == 'PaymentType'.$obj->fk_type) {
					$typelabel = $obj->fk_type;
				}
			}

			// User who created the entry
			$authorname = '';
			if (!empty($obj->fk_user_author)) {
				$authorname = trim($obj->author_firstname.' '.$obj->author_lastname);
				if ($authorname === '') {
					$authorname = (string) $obj->author_login;
				}
			}

			// Third party linked to the entry (company link on the bank line)
			$tiersname = $obj->tiers_name;
			if (empty($tiersname)) {
				$tiersname = $obj->tiers_label;
			}

			$obj_ret[] = array(
				'id' => (int) $obj->rowid,
				'description' => $obj->label,
				'date_valeur' => $this->db->jdate($obj->datev),
				'type' => $typelabel,
				'tiers_id' => (!empty($obj->tiers_id) ? (int) $obj->tiers_id : null),
				'tiers' => (string) $tiersname,
				'account_id' => (int) $obj->fk_account,
				'account_ref' => $obj->account_ref,
				'currency' => $obj->currency_code,
				'amount' => (float) price2num($obj->amount, 'MU'),
				'author_id' => (!empty($obj->fk_user_author) ? (int) $obj->fk_user_author : null),
				'author' => $authorname,
				'categories' => array(),
			);
			$i++;
		}
		$this->db->free($result);

		// Attach the transaction categories (tags) of each entry (multi-value link table bank_class -> bank_categ).
		// Bank-line tags ("Tags/categories des transactions") live in llx_bank_categ, NOT llx_categorie
		// (see Categorie::containing() special-case for TYPE_BANK_LINE).
		if (!empty($lineids)) {
			$catmap = array();
			$sqlcat = "SELECT bc.lineid, c.rowid, c.label";
			$sqlcat .= " FROM ".MAIN_DB_PREFIX."bank_class as bc";
			$sqlcat .= " INNER JOIN ".MAIN_DB_PREFIX."bank_categ as c ON c.rowid = bc.fk_categ";
			$sqlcat .= " WHERE bc.lineid IN (".$this->db->sanitize(implode(',', $lineids)).")";
			$sqlcat .= " AND c.entity IN (".getEntity('category').")";
			$resqlcat = $this->db->query($sqlcat);
			if ($resqlcat) {
				while ($objcat = $this->db->fetch_object($resqlcat)) {
					$catmap[(int) $objcat->lineid][] = array(
						'id' => (int) $objcat->rowid,
						'label' => $objcat->label,
					);
				}
				$this->db->free($resqlcat);
			}
			foreach ($obj_ret as $k => $entry) {
				if (isset($catmap[$entry['id']])) {
					$obj_ret[$k]['categories'] = $catmap[$entry['id']];
				}
			}
		}

		return $obj_ret;
	}

	/**
	 * Reject direct API calls while the module is disabled.
	 *
	 * Dolibarr's direct route loader can include a custom API class without
	 * checking the corresponding module switch.
	 *
	 * @return void
	 * @throws RestException 403 Module disabled
	 */
	private function assertModuleEnabled()
	{
		if (!isModEnabled('bankaudit')) {
			throw new RestException(403, 'BankAudit module is disabled.');
		}
	}
}

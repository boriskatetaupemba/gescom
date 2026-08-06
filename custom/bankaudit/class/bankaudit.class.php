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
 * \file    custom/bankaudit/class/bankaudit.class.php
 * \ingroup bankaudit
 * \brief   Shared helpers for the BankAudit module (logging, transfer sync, currency conversion).
 */


/**
 * Utility class gathering shared helpers used by triggers, hooks and pages.
 */
class BankAudit
{
	/**
	 * Anti-recursion guard. Set to true while a synchronization is being propagated
	 * to a linked transfer line, so nested triggers/hooks do not loop.
	 *
	 * @var bool
	 */
	public static $syncInProgress = false;

	/**
	 * Insert a row into the audit log table.
	 *
	 * @param DoliDB      $db          Database handler
	 * @param User        $user        Acting user
	 * @param string      $object_type 'bank_account' | 'bank_line'
	 * @param int         $object_id   Rowid of the concerned object
	 * @param string      $action      'CREATE' | 'UPDATE' | 'DELETE'
	 * @param string|null $field_name  Modified field (NULL for CREATE/DELETE)
	 * @param string|null $old_value   Old value
	 * @param string|null $new_value   New value
	 * @param string|null $context     JSON context (optional)
	 * @return int                     1 if OK, -1 if KO
	 */
	public static function logChange($db, $user, $object_type, $object_id, $action, $field_name = null, $old_value = null, $new_value = null, $context = null)
	{
		global $conf;

		$now = dol_now();
		$ip = function_exists('getUserRemoteIP') ? getUserRemoteIP() : (empty($_SERVER['REMOTE_ADDR']) ? '' : $_SERVER['REMOTE_ADDR']);
		$userid = is_object($user) && !empty($user->id) ? (int) $user->id : 0;

		$sql = "INSERT INTO ".MAIN_DB_PREFIX."bank_audit_log";
		$sql .= " (tms, entity, fk_user, object_type, object_id, action, field_name, old_value, new_value, ip, context)";
		$sql .= " VALUES (";
		$sql .= "'".$db->idate($now)."',";
		$sql .= " ".((int) $conf->entity).",";
		$sql .= " ".$userid.",";
		$sql .= " '".$db->escape($object_type)."',";
		$sql .= " ".((int) $object_id).",";
		$sql .= " '".$db->escape($action)."',";
		$sql .= " ".($field_name === null ? "NULL" : "'".$db->escape($field_name)."'").",";
		$sql .= " ".($old_value === null ? "NULL" : "'".$db->escape((string) $old_value)."'").",";
		$sql .= " ".($new_value === null ? "NULL" : "'".$db->escape((string) $new_value)."'").",";
		$sql .= " ".($ip === '' ? "NULL" : "'".$db->escape($ip)."'").",";
		$sql .= " ".($context === null ? "NULL" : "'".$db->escape($context)."'");
		$sql .= ")";

		return $db->query($sql) ? 1 : -1;
	}

	/**
	 * Return the rowid of the bank line linked to the given one through an internal transfer.
	 *
	 * @param DoliDB $db     Database handler
	 * @param int    $lineid Bank line rowid
	 * @return int           Linked bank line rowid, or 0 if none
	 */
	public static function getLinkedTransferLine($db, $lineid)
	{
		$sql = "SELECT url_id FROM ".MAIN_DB_PREFIX."bank_url";
		$sql .= " WHERE fk_bank = ".((int) $lineid)." AND type = 'banktransfert'";
		$sql .= " LIMIT 1";

		$resql = $db->query($sql);
		if ($resql && ($obj = $db->fetch_object($resql))) {
			return (int) $obj->url_id;
		}
		return 0;
	}

	/**
	 * Return the ISO currency code of a bank account.
	 *
	 * @param DoliDB $db        Database handler
	 * @param int    $accountid Bank account rowid
	 * @return string           Currency code (ex: 'USD') or '' if not found
	 */
	public static function getAccountCurrency($db, $accountid)
	{
		$sql = "SELECT currency_code FROM ".MAIN_DB_PREFIX."bank_account WHERE rowid = ".((int) $accountid);
		$resql = $db->query($sql);
		if ($resql && ($obj = $db->fetch_object($resql))) {
			return $obj->currency_code;
		}
		return '';
	}

	/**
	 * Return a human readable name (label, or ref) of a bank account.
	 *
	 * @param DoliDB $db        Database handler
	 * @param int    $accountid Bank account rowid
	 * @return string           Account name or '' if not found
	 */
	public static function getAccountLabel($db, $accountid)
	{
		$sql = "SELECT ref, label FROM ".MAIN_DB_PREFIX."bank_account WHERE rowid = ".((int) $accountid);
		$resql = $db->query($sql);
		if ($resql && ($obj = $db->fetch_object($resql))) {
			$name = trim((string) $obj->label);
			if ($name === '') {
				$name = trim((string) $obj->ref);
			}
			return $name;
		}
		return '';
	}

	/**
	 * Return whether the balance guard before transfer is globally enabled.
	 *
	 * @return bool   True when enabled (default), false when disabled in setup
	 */
	public static function isBalanceGuardEnabled()
	{
		return getDolGlobalString('BANKAUDIT_ENABLE_BALANCE_GUARD', '1') == '1';
	}

	/**
	 * Return the map of bank account rowid => ref for the current entity.
	 * Used to display only the account reference on the transfer form.
	 *
	 * @param DoliDB $db Database handler
	 * @return array     Associative array id => ref
	 */
	public static function getAccountsRefMap($db)
	{
		$map = array();
		$sql = "SELECT rowid, ref, label FROM ".MAIN_DB_PREFIX."bank_account";
		$sql .= " WHERE entity IN (".getEntity('bank_account').")";
		$resql = $db->query($sql);
		if ($resql) {
			while ($obj = $db->fetch_object($resql)) {
				$ref = trim((string) $obj->ref);
				if ($ref === '') {
					$ref = trim((string) $obj->label);
				}
				$map[(int) $obj->rowid] = $ref;
			}
		}
		return $map;
	}

	/**
	 * Return display data for the bank line linked to the given one through an internal transfer.
	 *
	 * @param DoliDB $db     Database handler
	 * @param int    $lineid Source bank line rowid
	 * @return array|null    Display data, or null when there is no linked line
	 */
	public static function getLinkedLineDisplay($db, $lineid)
	{
		$linkedId = self::getLinkedTransferLine($db, $lineid);
		if ($linkedId <= 0 || $linkedId == $lineid) {
			return null;
		}

		$sql = "SELECT b.rowid, b.label, b.amount, b.dateo, b.datev, b.fk_type, b.fk_account,";
		$sql .= " ba.ref as account_ref, ba.label as account_label, ba.currency_code,";
		$sql .= " pt.libelle as type_label";
		$sql .= " FROM ".MAIN_DB_PREFIX."bank as b";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."bank_account as ba ON ba.rowid = b.fk_account";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."c_paiement as pt ON pt.code = b.fk_type AND pt.entity IN (".getEntity('c_paiement').")";
		$sql .= " WHERE b.rowid = ".((int) $linkedId);
		$resql = $db->query($sql);
		if (!$resql || !($obj = $db->fetch_object($resql))) {
			return null;
		}

		$accountName = trim((string) $obj->account_label);
		if ($accountName === '') {
			$accountName = trim((string) $obj->account_ref);
		}

		return array(
			'id' => (int) $obj->rowid,
			'account_id' => (int) $obj->fk_account,
			'account_name' => $accountName,
			'account_ref' => (string) $obj->account_ref,
			'currency_code' => (string) $obj->currency_code,
			'label' => (string) $obj->label,
			'amount' => (float) $obj->amount,
			'dateo' => $db->jdate($obj->dateo),
			'datev' => $db->jdate($obj->datev),
			'type' => trim((string) $obj->type_label) !== '' ? (string) $obj->type_label : (string) $obj->fk_type,
		);
	}

	/**
	 * Return the ISO currency code from a multicurrency rowid.
	 *
	 * @param DoliDB $db Database handler
	 * @param int    $id llx_multicurrency rowid
	 * @return string    Currency code or '' if not found
	 */
	public static function getCurrencyCodeById($db, $id)
	{
		$sql = "SELECT code FROM ".MAIN_DB_PREFIX."multicurrency WHERE rowid = ".((int) $id);
		$resql = $db->query($sql);
		if ($resql && ($obj = $db->fetch_object($resql))) {
			return $obj->code;
		}
		return '';
	}

	/**
	 * Return the conversion factor to apply to convert an amount from one currency to another.
	 * Uses the authoritative Dolibarr multicurrency rates (foreign units per 1 main currency).
	 *
	 * amount_to = amount_from * getConversionRate(from, to)
	 *
	 * @param DoliDB $db   Database handler
	 * @param string $from Source currency code
	 * @param string $to   Target currency code
	 * @return float|null  Conversion factor, or null if a required rate is unknown
	 */
	public static function getConversionRate($db, $from, $to)
	{
		global $conf;

		if ($from === $to) {
			return 1.0;
		}
		if (!isModEnabled('multicurrency')) {
			return null;
		}

		require_once DOL_DOCUMENT_ROOT.'/multicurrency/class/multicurrency.class.php';

		$main = $conf->currency;
		$rateFrom = 1.0;
		$rateTo = 1.0;

		if ($from !== $main) {
			$r = MultiCurrency::getIdAndTxFromCode($db, $from);
			if (empty($r[0])) {
				return null; // unknown rate for source currency
			}
			$rateFrom = (float) $r[1];
		}
		if ($to !== $main) {
			$r = MultiCurrency::getIdAndTxFromCode($db, $to);
			if (empty($r[0])) {
				return null; // unknown rate for target currency
			}
			$rateTo = (float) $r[1];
		}

		if ($rateFrom <= 0 || $rateTo <= 0) {
			return null;
		}

		return $rateTo / $rateFrom;
	}

	/**
	 * Return the current balance of a bank account (sum of all bank lines).
	 *
	 * @param DoliDB $db            Database handler
	 * @param int    $accountid     Bank account rowid
	 * @param bool   $excludeFuture If true, exclude lines with an operation date in the future
	 * @return float                Account balance in the account currency
	 */
	public static function getAccountBalance($db, $accountid, $excludeFuture = false)
	{
		$sql = "SELECT COALESCE(SUM(amount), 0) as solde FROM ".MAIN_DB_PREFIX."bank";
		$sql .= " WHERE fk_account = ".((int) $accountid);
		if ($excludeFuture) {
			$sql .= " AND dateo <= '".$db->idate(dol_now())."'";
		}
		$resql = $db->query($sql);
		if ($resql && ($obj = $db->fetch_object($resql))) {
			return (float) $obj->solde;
		}
		return 0.0;
	}

	/**
	 * Return the balance of a bank account at a given point in time (operations up to $tsmax).
	 *
	 * @param DoliDB $db        Database handler
	 * @param int    $accountid Bank account rowid
	 * @param int    $tsmax     Timestamp (operations with dateo <= this date are summed)
	 * @return float            Account balance in the account currency at that date
	 */
	public static function getBalanceAsOf($db, $accountid, $tsmax)
	{
		$sql = "SELECT COALESCE(SUM(amount), 0) as solde FROM ".MAIN_DB_PREFIX."bank";
		$sql .= " WHERE fk_account = ".((int) $accountid);
		$sql .= " AND dateo <= '".$db->idate($tsmax)."'";
		$resql = $db->query($sql);
		if ($resql && ($obj = $db->fetch_object($resql))) {
			return (float) $obj->solde;
		}
		return 0.0;
	}

	/**
	 * Return the list of category ids attached to a bank line.
	 *
	 * @param DoliDB $db     Database handler
	 * @param int    $lineid Bank line rowid
	 * @return int[]         Array of category ids
	 */
	public static function getLineCategories($db, $lineid)
	{
		$cats = array();
		$sql = "SELECT fk_categ FROM ".MAIN_DB_PREFIX."bank_class WHERE lineid = ".((int) $lineid);
		$resql = $db->query($sql);
		if ($resql) {
			while ($obj = $db->fetch_object($resql)) {
				$cats[] = (int) $obj->fk_categ;
			}
		}
		return $cats;
	}

	/**
	 * Replace the categories of a bank line with the given set.
	 *
	 * @param DoliDB $db           Database handler
	 * @param int    $targetlineid Bank line rowid
	 * @param int[]  $categids      Category ids to set
	 * @return int                 1 if OK
	 */
	public static function syncLineCategories($db, $targetlineid, $categids)
	{
		$db->query("DELETE FROM ".MAIN_DB_PREFIX."bank_class WHERE lineid = ".((int) $targetlineid));
		foreach ($categids as $cid) {
			$cid = (int) $cid;
			if ($cid <= 0) {
				continue;
			}
			$db->query("INSERT INTO ".MAIN_DB_PREFIX."bank_class (lineid, fk_categ) VALUES (".((int) $targetlineid).", ".$cid.")");
		}
		return 1;
	}

	/**
	 * Return the minimum balance threshold configured for an account, expressed in the
	 * account's own currency. Returns null when no threshold is configured (no blocking).
	 *
	 * @param DoliDB $db        Database handler
	 * @param int    $accountid Bank account rowid
	 * @return float|null       Threshold in the account currency, or null
	 */
	public static function getMinBalanceForAccount($db, $accountid)
	{
		global $conf;

		$sql = "SELECT seuil_min, seuil_devise FROM ".MAIN_DB_PREFIX."bank_dashboard_config";
		$sql .= " WHERE fk_account = ".((int) $accountid)." AND entity = ".((int) $conf->entity);
		$resql = $db->query($sql);
		if (!$resql || !($obj = $db->fetch_object($resql))) {
			return null;
		}
		if ($obj->seuil_min === null || $obj->seuil_min === '') {
			return null;
		}

		$threshold = (float) $obj->seuil_min;
		$seuilCurrency = $obj->seuil_devise;
		$accountCurrency = self::getAccountCurrency($db, $accountid);

		if ($seuilCurrency && $accountCurrency && $seuilCurrency !== $accountCurrency) {
			$rate = self::getConversionRate($db, $seuilCurrency, $accountCurrency);
			if ($rate !== null) {
				$threshold = $threshold * $rate;
			}
			// If the rate is unknown we keep the raw threshold value as a safe fallback.
		}

		return $threshold;
	}

	/**
	 * Record an exchange rate change into the rate history table and flag large variations.
	 *
	 * @param DoliDB $db        Database handler
	 * @param User   $user      Acting user
	 * @param string $code_from Source currency (main currency)
	 * @param string $code_to   Target currency
	 * @param float  $rate_new  New rate
	 * @param string $source    'manual' | 'api'
	 * @return array            array('alerte'=>int, 'variation'=>float|null, 'rate_old'=>float|null)
	 */
	public static function logRateChange($db, $user, $code_from, $code_to, $rate_new, $source = 'manual')
	{
		global $conf;

		$sql = "SELECT rate_new FROM ".MAIN_DB_PREFIX."bank_rate_history";
		$sql .= " WHERE code_from = '".$db->escape($code_from)."' AND code_to = '".$db->escape($code_to)."'";
		$sql .= " AND entity = ".((int) $conf->entity);
		$sql .= " ORDER BY tms DESC LIMIT 1";
		$resql = $db->query($sql);
		$rate_old = null;
		if ($resql && ($obj = $db->fetch_object($resql))) {
			$rate_old = (float) $obj->rate_new;
		}

		$variation = null;
		$alerte = 0;
		$threshold = (float) getDolGlobalString('BANKAUDIT_RATE_ALERT_THRESHOLD', '10');
		if ($rate_old !== null && $rate_old > 0) {
			$variation = abs(($rate_new - $rate_old) / $rate_old * 100);
			if ($variation > $threshold) {
				$alerte = 1;
			}
		}

		$now = dol_now();
		$ip = function_exists('getUserRemoteIP') ? getUserRemoteIP() : (empty($_SERVER['REMOTE_ADDR']) ? '' : $_SERVER['REMOTE_ADDR']);
		$userid = is_object($user) && !empty($user->id) ? (int) $user->id : 0;

		$sql = "INSERT INTO ".MAIN_DB_PREFIX."bank_rate_history";
		$sql .= " (tms, entity, fk_user, code_from, code_to, rate_old, rate_new, variation_pct, alerte, ip, source)";
		$sql .= " VALUES (";
		$sql .= "'".$db->idate($now)."',";
		$sql .= " ".((int) $conf->entity).",";
		$sql .= " ".$userid.",";
		$sql .= " '".$db->escape($code_from)."',";
		$sql .= " '".$db->escape($code_to)."',";
		$sql .= " ".($rate_old === null ? "NULL" : (float) $rate_old).",";
		$sql .= " ".((float) $rate_new).",";
		$sql .= " ".($variation === null ? "NULL" : (float) $variation).",";
		$sql .= " ".((int) $alerte).",";
		$sql .= " ".($ip === '' ? "NULL" : "'".$db->escape($ip)."'").",";
		$sql .= " '".$db->escape($source)."'";
		$sql .= ")";
		$db->query($sql);

		return array('alerte' => $alerte, 'variation' => $variation, 'rate_old' => $rate_old);
	}

	/**
	 * Capture a complete snapshot of a bank line (and everything needed to recreate it later):
	 * the full llx_bank row, its categories and its llx_bank_url links, plus the rowid of the
	 * line it is linked to through an internal transfer.
	 *
	 * Must be called BEFORE the line and its dependent rows are physically deleted.
	 *
	 * @param DoliDB $db     Database handler
	 * @param int    $lineid Bank line rowid
	 * @return array|null    Snapshot array, or null when the line no longer exists
	 */
	public static function captureLineSnapshot($db, $lineid)
	{
		$lineid = (int) $lineid;

		$sql = "SELECT * FROM ".MAIN_DB_PREFIX."bank WHERE rowid = ".$lineid;
		$resql = $db->query($sql);
		if (!$resql || !($obj = $db->fetch_object($resql))) {
			return null;
		}
		$bank = (array) $obj;

		$urls = array();
		$linkedId = 0;
		$sqlu = "SELECT url_id, url, label, type FROM ".MAIN_DB_PREFIX."bank_url WHERE fk_bank = ".$lineid;
		$resu = $db->query($sqlu);
		if ($resu) {
			while ($u = $db->fetch_object($resu)) {
				$urls[] = array(
					'url_id' => (int) $u->url_id,
					'url' => (string) $u->url,
					'label' => (string) $u->label,
					'type' => (string) $u->type,
				);
				if ($u->type === 'banktransfert') {
					$linkedId = (int) $u->url_id;
				}
			}
		}

		return array(
			'bank' => $bank,
			'categories' => self::getLineCategories($db, $lineid),
			'urls' => $urls,
			'linked_line_id' => $linkedId,
		);
	}

	/**
	 * Re-insert a bank line from a snapshot taken by captureLineSnapshot().
	 * The reconciliation flag is intentionally reset (a deleted line was never reconciled).
	 *
	 * @param DoliDB $db   Database handler
	 * @param array  $bank Associative array of the original llx_bank columns
	 * @return int         New bank line rowid, or <0 on error
	 */
	private static function insertLineFromSnapshot($db, $bank)
	{
		// Columns we restore (rowid/tms are auto-managed, reconciliation is reset).
		$cols = array(
			'datec', 'dateo', 'datev', 'amount', 'amount_main_currency', 'label', 'note',
			'fk_user_author', 'fk_type', 'num_chq', 'num_releve', 'fk_account', 'banque',
			'emetteur', 'numero_compte',
		);

		$names = array();
		$values = array();
		foreach ($cols as $c) {
			if (!array_key_exists($c, $bank)) {
				continue;
			}
			$val = $bank[$c];
			$names[] = $c;
			if ($val === null || $val === '') {
				// Numeric columns must not receive an empty string
				if (in_array($c, array('amount', 'amount_main_currency', 'fk_user_author'), true)) {
					$values[] = ($c === 'amount') ? '0' : 'NULL';
				} else {
					$values[] = "NULL";
				}
			} elseif (in_array($c, array('amount', 'amount_main_currency'), true)) {
				$values[] = (string) price2num($val);
			} elseif (in_array($c, array('fk_user_author', 'fk_account', 'fk_type'), true)) {
				// fk_type is a code (varchar) in llx_bank, keep it quoted; the two others are ints
				if ($c === 'fk_type') {
					$values[] = "'".$db->escape($val)."'";
				} else {
					$values[] = (int) $val;
				}
			} else {
				$values[] = "'".$db->escape($val)."'";
			}
		}
		// Force reconciliation flag to 0
		$names[] = 'rappro';
		$values[] = '0';

		$sql = "INSERT INTO ".MAIN_DB_PREFIX."bank (".implode(', ', $names).") VALUES (".implode(', ', $values).")";
		if (!$db->query($sql)) {
			return -1;
		}
		return (int) $db->last_insert_id(MAIN_DB_PREFIX.'bank');
	}

	/**
	 * Tell whether a deletion audit-log entry has already been restored.
	 *
	 * @param object $logrow Row from llx_bank_audit_log (must expose ->context)
	 * @return bool          True when already restored
	 */
	public static function isLogRestored($logrow)
	{
		if (empty($logrow) || empty($logrow->context)) {
			return false;
		}
		$ctx = json_decode($logrow->context, true);
		return is_array($ctx) && !empty($ctx['restored']);
	}

	/**
	 * Flag a deletion audit-log entry as restored (keeps any previous context keys).
	 *
	 * @param DoliDB $db    Database handler
	 * @param int    $logid llx_bank_audit_log rowid
	 * @param int    $newid New bank line rowid created by the restore
	 * @return void
	 */
	private static function markLogRestored($db, $logid, $newid)
	{
		$ctx = array();
		$sql = "SELECT context FROM ".MAIN_DB_PREFIX."bank_audit_log WHERE rowid = ".((int) $logid);
		$resql = $db->query($sql);
		if ($resql && ($obj = $db->fetch_object($resql)) && !empty($obj->context)) {
			$decoded = json_decode($obj->context, true);
			if (is_array($decoded)) {
				$ctx = $decoded;
			}
		}
		$ctx['restored'] = 1;
		$ctx['restored_to'] = (int) $newid;
		$ctx['restored_at'] = dol_print_date(dol_now(), 'standard');

		$sqlu = "UPDATE ".MAIN_DB_PREFIX."bank_audit_log SET context = '".$db->escape(json_encode($ctx))."'";
		$sqlu .= " WHERE rowid = ".((int) $logid);
		$db->query($sqlu);
	}

	/**
	 * Find the most recent, not-yet-restored DELETE audit-log entry for a given bank line id.
	 *
	 * @param DoliDB $db     Database handler
	 * @param int    $lineid Original bank line rowid
	 * @return object|null   Log row, or null
	 */
	private static function findDeleteLog($db, $lineid)
	{
		$sql = "SELECT rowid, old_value, context FROM ".MAIN_DB_PREFIX."bank_audit_log";
		$sql .= " WHERE object_type = 'bank_line' AND action = 'DELETE' AND object_id = ".((int) $lineid);
		$sql .= " ORDER BY tms DESC, rowid DESC";
		$resql = $db->query($sql);
		if ($resql) {
			while ($obj = $db->fetch_object($resql)) {
				if (!self::isLogRestored($obj)) {
					return $obj;
				}
			}
		}
		return null;
	}

	/**
	 * Insert categories and links for a freshly restored bank line, remapping the internal
	 * transfer link (banktransfert) from the old partner id to the new partner id.
	 *
	 * @param DoliDB $db          Database handler
	 * @param int    $newid       New bank line rowid
	 * @param array  $snap        Snapshot of the restored line
	 * @param int    $oldPartner  Original partner line id (0 if none)
	 * @param int    $newPartner  New partner line id (0 if not restored)
	 * @return void
	 */
	private static function restoreDependencies($db, $newid, $snap, $oldPartner, $newPartner)
	{
		if (!empty($snap['categories']) && is_array($snap['categories'])) {
			self::syncLineCategories($db, $newid, $snap['categories']);
		}
		if (!empty($snap['urls']) && is_array($snap['urls'])) {
			foreach ($snap['urls'] as $u) {
				$urlId = (int) (isset($u['url_id']) ? $u['url_id'] : 0);
				$type = isset($u['type']) ? (string) $u['type'] : '';
				if ($type === 'banktransfert') {
					if ($newPartner <= 0 || $oldPartner <= 0 || $urlId !== $oldPartner) {
						// Cannot rebuild a coherent transfer link, skip it
						continue;
					}
					$urlId = $newPartner;
				}
				$sql = "INSERT INTO ".MAIN_DB_PREFIX."bank_url (fk_bank, url_id, url, label, type) VALUES (";
				$sql .= ((int) $newid).", ".((int) $urlId).", ";
				$sql .= "'".$db->escape(isset($u['url']) ? $u['url'] : '')."', ";
				$sql .= "'".$db->escape(isset($u['label']) ? $u['label'] : '')."', ";
				$sql .= "'".$db->escape($type)."')";
				$db->query($sql);
			}
		}
	}

	/**
	 * Restore a deleted bank line (and its internal-transfer counterpart, if it was also deleted)
	 * from a DELETE entry of the audit log.
	 *
	 * @param DoliDB $db    Database handler
	 * @param User   $user  Acting user
	 * @param int    $logid llx_bank_audit_log rowid of the DELETE entry
	 * @return array        array('error'=>string) on failure, or array('newid'=>int,'newlinked'=>int) on success
	 */
	public static function restoreDeletedLine($db, $user, $logid)
	{
		$logid = (int) $logid;

		$sql = "SELECT rowid, object_id, action, old_value, context FROM ".MAIN_DB_PREFIX."bank_audit_log";
		$sql .= " WHERE rowid = ".$logid." AND object_type = 'bank_line' AND action = 'DELETE'";
		$resql = $db->query($sql);
		if (!$resql || !($log = $db->fetch_object($resql))) {
			return array('error' => 'NotFound');
		}
		if (self::isLogRestored($log)) {
			return array('error' => 'AlreadyRestored');
		}

		$snap = json_decode($log->old_value, true);
		if (!is_array($snap) || empty($snap['bank']) || !is_array($snap['bank'])) {
			// Old simplified format: not enough data to recreate the line
			return array('error' => 'InsufficientData');
		}

		$oldId = (int) $log->object_id;
		$oldPartner = (int) (isset($snap['linked_line_id']) ? $snap['linked_line_id'] : 0);

		$db->begin();

		$newid = self::insertLineFromSnapshot($db, $snap['bank']);
		if ($newid <= 0) {
			$db->rollback();
			return array('error' => 'InsertFailed');
		}

		// Restore the linked transfer counterpart if it was deleted too
		$newPartner = 0;
		$partnerLog = null;
		$partnerSnap = null;
		if ($oldPartner > 0) {
			$partnerLog = self::findDeleteLog($db, $oldPartner);
			if ($partnerLog !== null) {
				$partnerSnap = json_decode($partnerLog->old_value, true);
				if (is_array($partnerSnap) && !empty($partnerSnap['bank']) && is_array($partnerSnap['bank'])) {
					$newPartner = self::insertLineFromSnapshot($db, $partnerSnap['bank']);
					if ($newPartner <= 0) {
						$db->rollback();
						return array('error' => 'InsertFailed');
					}
				}
			}
		}

		// Categories + links (with transfer link remapping on both sides)
		self::restoreDependencies($db, $newid, $snap, $oldPartner, $newPartner);
		if ($newPartner > 0 && is_array($partnerSnap)) {
			self::restoreDependencies($db, $newPartner, $partnerSnap, $oldId, $newid);
		}

		// Mark logs as restored and keep an audit trace of the restore
		self::markLogRestored($db, (int) $log->rowid, $newid);
		self::logChange($db, $user, 'bank_line', $newid, 'RESTORE', null, null, json_encode(array('restored_from_log' => (int) $log->rowid, 'old_line_id' => $oldId)));
		if ($newPartner > 0 && $partnerLog !== null) {
			self::markLogRestored($db, (int) $partnerLog->rowid, $newPartner);
			self::logChange($db, $user, 'bank_line', $newPartner, 'RESTORE', null, null, json_encode(array('restored_from_log' => (int) $partnerLog->rowid, 'old_line_id' => $oldPartner)));
		}

		$db->commit();

		return array('newid' => $newid, 'newlinked' => $newPartner);
	}
}

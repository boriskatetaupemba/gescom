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
 * \file    custom/posnova/class/posnova.class.php
 * \ingroup posnova
 * \brief   Shared helpers for the PosNova module: exchange rate, currency conversion, audit.
 */


/**
 * Stateless utility class gathering helpers used by the POS pages, AJAX API and classes.
 */
class PosNova
{
	/** @var string Local currency ISO code (Franc Congolais). */
	const CDF = 'CDF';

	/** @var string Reference foreign currency ISO code. */
	const USD = 'USD';

	/**
	 * Resolve the exchange rate (1 USD = X CDF) in effect for a given day.
	 *
	 * Priority: a MANUAL rate saved for the day -> fallback on the Dolibarr
	 * multicurrency module (flagged SYSTEM). Never throws: returns a safe default.
	 *
	 * @param  DoliDB      $db      Database handler
	 * @param  int|null    $entity  Entity (defaults to current)
	 * @param  string|null $day     Day 'YYYY-MM-DD' (defaults to today)
	 * @return array                array('rate'=>float, 'source'=>'MANUAL'|'SYSTEM', 'rowid'=>int, 'day'=>string)
	 */
	public static function getDailyRate($db, $entity = null, $day = null)
	{
		global $conf;

		$entity = is_null($entity) ? (int) $conf->entity : (int) $entity;
		$day = $day ? $day : dol_print_date(dol_now(), '%Y-%m-%d');

		$sql = "SELECT rowid, rate_usd_cdf, source FROM ".MAIN_DB_PREFIX."pos_exchange_rate";
		$sql .= " WHERE day = '".$db->escape($day)."' AND entity = ".$entity;
		$sql .= " ORDER BY rowid DESC LIMIT 1";
		$resql = $db->query($sql);
		if ($resql && $db->num_rows($resql) > 0) {
			$obj = $db->fetch_object($resql);
			return array(
				'rate' => (float) $obj->rate_usd_cdf,
				'source' => $obj->source,
				'rowid' => (int) $obj->rowid,
				'day' => $day,
			);
		}

		// Fallback on the multicurrency module.
		$rate = 0.0;
		if (getDolGlobalInt('POSNOVA_FALLBACK_TO_MULTICURRENCY', 1) && isModEnabled('multicurrency')) {
			$rate = self::getMulticurrencyUsdCdf($db);
		}

		return array(
			'rate' => $rate,
			'source' => 'SYSTEM',
			'rowid' => 0,
			'day' => $day,
		);
	}

	/**
	 * Compute "1 USD = X CDF" from the multicurrency rates, regardless of the company main currency.
	 *
	 * In Dolibarr, mc.rate is "foreign units per one main-currency unit", so:
	 *   1 USD = (rate(CDF) / rate(USD)) CDF
	 *
	 * @param  DoliDB $db Database handler
	 * @return float      Rate, or 0 if it cannot be resolved
	 */
	public static function getMulticurrencyUsdCdf($db)
	{
		if (!class_exists('MultiCurrency')) {
			require_once DOL_DOCUMENT_ROOT.'/multicurrency/class/multicurrency.class.php';
		}

		$cdfCode = getDolGlobalString('POSNOVA_CDF_CURRENCY_CODE', self::CDF);

		$usd = MultiCurrency::getIdAndTxFromCode($db, self::USD);
		$cdf = MultiCurrency::getIdAndTxFromCode($db, $cdfCode);

		$rateUsd = (float) (is_array($usd) ? $usd[1] : 0);
		$rateCdf = (float) (is_array($cdf) ? $cdf[1] : 0);

		if ($rateUsd <= 0 || $rateCdf <= 0) {
			return 0.0;
		}

		return $rateCdf / $rateUsd;
	}

	/**
	 * Persist a MANUAL daily rate (insert or update) and trace the change.
	 *
	 * @param  DoliDB $db    Database handler
	 * @param  User   $user  Acting user
	 * @param  float  $rate  Rate 1 USD = X CDF
	 * @param  string $day   Day 'YYYY-MM-DD' (defaults to today)
	 * @return int           rowid on success, <0 on error
	 */
	public static function setDailyRate($db, $user, $rate, $day = null)
	{
		global $conf;

		$rate = (float) $rate;
		if ($rate <= 0) {
			return -1;
		}
		$day = $day ? $day : dol_print_date(dol_now(), '%Y-%m-%d');
		$entity = (int) $conf->entity;
		$now = dol_now();

		$before = self::getDailyRate($db, $entity, $day);

		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."pos_exchange_rate";
		$sql .= " WHERE day = '".$db->escape($day)."' AND entity = ".$entity." LIMIT 1";
		$resql = $db->query($sql);
		$rowid = ($resql && $db->num_rows($resql) > 0) ? (int) $db->fetch_object($resql)->rowid : 0;

		if ($rowid > 0) {
			$sqlu = "UPDATE ".MAIN_DB_PREFIX."pos_exchange_rate SET";
			$sqlu .= " rate_usd_cdf = ".((float) $rate).",";
			$sqlu .= " source = 'MANUAL',";
			$sqlu .= " fk_user = ".((int) $user->id);
			$sqlu .= " WHERE rowid = ".$rowid;
			if (!$db->query($sqlu)) {
				return -2;
			}
		} else {
			$sqli = "INSERT INTO ".MAIN_DB_PREFIX."pos_exchange_rate";
			$sqli .= " (entity, day, rate_usd_cdf, source, fk_user, datec)";
			$sqli .= " VALUES (".$entity.", '".$db->escape($day)."', ".((float) $rate).", 'MANUAL', ".((int) $user->id).", '".$db->idate($now)."')";
			if (!$db->query($sqli)) {
				return -3;
			}
			$rowid = (int) $db->last_insert_id(MAIN_DB_PREFIX."pos_exchange_rate");
		}

		self::audit($db, $user, 'RATE_CHANGE', 'exchange_rate', $rowid, $before['rate'].' ('.$before['source'].')', $rate.' (MANUAL)');

		return $rowid;
	}

	/**
	 * Convert an amount between USD and CDF using a given USD/CDF rate.
	 *
	 * @param  float  $amount Amount in $from currency
	 * @param  string $from   Source currency code
	 * @param  string $to     Target currency code
	 * @param  float  $rate   1 USD = $rate CDF
	 * @return float          Converted, rounded amount in $to currency
	 */
	public static function convert($amount, $from, $to, $rate)
	{
		$amount = (float) $amount;
		$rate = (float) $rate;

		if ($from === $to || $rate <= 0) {
			return self::roundAmount($amount, $to);
		}

		if ($from === self::USD && $to !== self::USD) {
			// USD -> local
			return self::roundAmount($amount * $rate, $to);
		}
		if ($from !== self::USD && $to === self::USD) {
			// local -> USD
			return self::roundAmount($amount / $rate, $to);
		}

		return self::roundAmount($amount, $to);
	}

	/**
	 * Round an amount according to its currency rules (USD = 2 decimals, CDF = configurable).
	 *
	 * @param  float  $amount   Amount
	 * @param  string $currency Currency code
	 * @return float            Rounded amount
	 */
	public static function roundAmount($amount, $currency)
	{
		$amount = (float) $amount;
		$cdfCode = getDolGlobalString('POSNOVA_CDF_CURRENCY_CODE', self::CDF);

		if ($currency === $cdfCode) {
			$rule = getDolGlobalString('POSNOVA_ROUNDING_CDF', 'DOWN_UNIT');
			switch ($rule) {
				case 'NEAREST_5':
					return round($amount / 5) * 5;
				case 'NEAREST_UNIT':
					return round($amount);
				case 'DOWN_UNIT':
				default:
					return floor($amount);
			}
		}

		return round($amount, 2);
	}

	/**
	 * Number of decimals to display for a currency.
	 *
	 * @param  string $currency Currency code
	 * @return int              Decimal places
	 */
	public static function decimals($currency)
	{
		$cdfCode = getDolGlobalString('POSNOVA_CDF_CURRENCY_CODE', self::CDF);
		return ($currency === $cdfCode) ? 0 : 2;
	}

	/**
	 * Return the ISO currency code of a bank account / cash register.
	 *
	 * @param  DoliDB $db        Database handler
	 * @param  int    $accountid llx_bank_account.rowid
	 * @return string            Currency code (uppercased), or '' if unknown
	 */
	public static function getAccountCurrency($db, $accountid)
	{
		$sql = "SELECT currency_code FROM ".MAIN_DB_PREFIX."bank_account WHERE rowid = ".((int) $accountid);
		$resql = $db->query($sql);
		if ($resql && $db->num_rows($resql) > 0) {
			return strtoupper((string) $db->fetch_object($resql)->currency_code);
		}
		return '';
	}

	/**
	 * Insert a row in the immutable POS audit journal.
	 *
	 * @param  DoliDB      $db          Database handler
	 * @param  User        $user        Acting user
	 * @param  string      $action      Action code (SALE, CANCEL, RATE_CHANGE, PRICE_OVERRIDE, TRANSFER...)
	 * @param  string|null $object_type Object type
	 * @param  int|null    $object_id   Object id
	 * @param  string|null $before      Before value
	 * @param  string|null $after       After value
	 * @param  int|null    $fk_pos      POS id
	 * @return int                      1 on success, -1 on error
	 */
	public static function audit($db, $user, $action, $object_type = null, $object_id = null, $before = null, $after = null, $fk_pos = null)
	{
		global $conf;

		$ip = function_exists('getUserRemoteIP') ? getUserRemoteIP() : (empty($_SERVER['REMOTE_ADDR']) ? '' : $_SERVER['REMOTE_ADDR']);
		$userid = is_object($user) && !empty($user->id) ? (int) $user->id : 0;

		$sql = "INSERT INTO ".MAIN_DB_PREFIX."pos_audit_log";
		$sql .= " (entity, tms, fk_pos, fk_user, action, object_type, object_id, before_val, after_val, ip)";
		$sql .= " VALUES (";
		$sql .= ((int) $conf->entity).",";
		$sql .= " '".$db->idate(dol_now())."',";
		$sql .= " ".(is_null($fk_pos) ? "NULL" : ((int) $fk_pos)).",";
		$sql .= " ".$userid.",";
		$sql .= " '".$db->escape($action)."',";
		$sql .= " ".(is_null($object_type) ? "NULL" : "'".$db->escape($object_type)."'").",";
		$sql .= " ".(is_null($object_id) ? "NULL" : ((int) $object_id)).",";
		$sql .= " ".(is_null($before) ? "NULL" : "'".$db->escape((string) $before)."'").",";
		$sql .= " ".(is_null($after) ? "NULL" : "'".$db->escape((string) $after)."'").",";
		$sql .= " ".($ip === '' ? "NULL" : "'".$db->escape($ip)."'");
		$sql .= ")";

		return $db->query($sql) ? 1 : -1;
	}

	/**
	 * Generate a cryptographically strong opaque token for a POS session.
	 *
	 * @return string 64-char hex token
	 */
	public static function generateToken()
	{
		if (function_exists('random_bytes')) {
			return bin2hex(random_bytes(32));
		}
		return md5(uniqid((string) mt_rand(), true)).md5(uniqid((string) mt_rand(), true));
	}

	/**
	 * Format an amount for display with the right number of decimals and a thin space
	 * as thousands separator (CDF) — used by the server-rendered parts of the UI.
	 *
	 * @param  float  $amount   Amount
	 * @param  string $currency Currency code
	 * @return string           Formatted string with the currency code appended
	 */
	public static function formatAmount($amount, $currency)
	{
		$dec = self::decimals($currency);
		return number_format((float) $amount, $dec, ',', ' ').' '.$currency;
	}
}

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
 * \file    custom/bankaudit/class/bankauditanomaly.class.php
 * \ingroup bankaudit
 * \brief   Anomaly detection engine (Spec 8 / P12).
 */

require_once __DIR__.'/bankaudit.class.php';


/**
 * Rule based engine detecting abnormal situations on bank lines and rates.
 */
class BankAuditAnomaly
{
	/**
	 * Load an active rule definition for the current entity.
	 *
	 * @param DoliDB $db   Database handler
	 * @param string $code Rule code (R01..R06)
	 * @return object|null  Rule row, or null if missing/inactive
	 */
	public static function getRule($db, $code)
	{
		global $conf;

		$sql = "SELECT rowid, code, label, active, seuil, seuil_unite, fk_account, action_email, action_notif, fk_user_notif";
		$sql .= " FROM ".MAIN_DB_PREFIX."bank_anomaly_rule";
		$sql .= " WHERE code = '".$db->escape($code)."' AND entity = ".((int) $conf->entity);
		$sql .= " LIMIT 1";

		$resql = $db->query($sql);
		if ($resql && ($obj = $db->fetch_object($resql))) {
			if (empty($obj->active)) {
				return null;
			}
			return $obj;
		}
		return null;
	}

	/**
	 * Fetch the data of a bank line needed by the detection rules.
	 *
	 * @param DoliDB $db     Database handler
	 * @param int    $lineid Bank line rowid
	 * @return object|null    Object with rowid, fk_account, amount, dateo (timestamp), or null
	 */
	public static function fetchLineData($db, $lineid)
	{
		$sql = "SELECT rowid, fk_account, amount, dateo FROM ".MAIN_DB_PREFIX."bank WHERE rowid = ".((int) $lineid);
		$resql = $db->query($sql);
		if ($resql && ($obj = $db->fetch_object($resql))) {
			$obj->dateo_ts = $db->jdate($obj->dateo);
			$obj->amount = (float) $obj->amount;
			$obj->fk_account = (int) $obj->fk_account;
			return $obj;
		}
		return null;
	}

	/**
	 * Record an anomaly into the log, unless an identical pending one already exists.
	 *
	 * @param DoliDB      $db         Database handler
	 * @param object      $rule       Rule row (from getRule)
	 * @param int|null    $fk_bank    Concerned bank line
	 * @param int|null    $fk_account Concerned account
	 * @param string      $severity   'info' | 'warning' | 'critical'
	 * @param array       $detail     Details that triggered the rule (stored as JSON)
	 * @return int                    1 if recorded, 0 if skipped (duplicate), -1 on error
	 */
	public static function recordAnomaly($db, $rule, $fk_bank, $fk_account, $severity, $detail = array())
	{
		global $conf;

		// Avoid duplicating a pending anomaly for the same rule and line
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."bank_anomaly_log";
		$sql .= " WHERE fk_rule = ".((int) $rule->rowid);
		$sql .= " AND statut = 'new'";
		$sql .= " AND entity = ".((int) $conf->entity);
		$sql .= " AND ".($fk_bank ? "fk_bank = ".((int) $fk_bank) : "fk_bank IS NULL");
		$sql .= " LIMIT 1";
		$resql = $db->query($sql);
		if ($resql && $db->num_rows($resql) > 0) {
			return 0;
		}

		$now = dol_now();
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."bank_anomaly_log";
		$sql .= " (tms, entity, fk_rule, rule_code, fk_bank, fk_account, severity, detail, statut)";
		$sql .= " VALUES (";
		$sql .= "'".$db->idate($now)."',";
		$sql .= " ".((int) $conf->entity).",";
		$sql .= " ".((int) $rule->rowid).",";
		$sql .= " '".$db->escape($rule->code)."',";
		$sql .= " ".($fk_bank ? ((int) $fk_bank) : "NULL").",";
		$sql .= " ".($fk_account ? ((int) $fk_account) : "NULL").",";
		$sql .= " '".$db->escape($severity)."',";
		$sql .= " '".$db->escape(json_encode($detail))."',";
		$sql .= " 'new'";
		$sql .= ")";

		return $db->query($sql) ? 1 : -1;
	}

	/**
	 * Return true if the rule scope matches the given account.
	 *
	 * @param object $rule      Rule row
	 * @param int    $accountid Account id
	 * @return bool
	 */
	private static function ruleAppliesToAccount($rule, $accountid)
	{
		if (empty($rule->fk_account)) {
			return true; // applies to all accounts
		}
		return ((int) $rule->fk_account === (int) $accountid);
	}

	/**
	 * Run all line-related rules (R01, R02, R03, R05, R06) against a single bank line.
	 *
	 * @param DoliDB    $db     Database handler
	 * @param User      $user   Acting user
	 * @param int       $lineid Bank line rowid
	 * @return int              Number of anomalies recorded
	 */
	public static function checkLine($db, $user, $lineid)
	{
		$line = self::fetchLineData($db, $lineid);
		if (!$line) {
			return 0;
		}

		$nb = 0;
		$nb += self::checkDoublon($db, $line);
		$nb += self::checkMontantInhabituel($db, $line);
		$nb += self::checkDateAncienne($db, $line);
		$nb += self::checkSoldeNegatif($db, $line);
		$nb += self::checkCompteInactif($db, $line);

		return $nb;
	}

	/**
	 * R01 - Probable duplicate (same account, same absolute amount, close date).
	 *
	 * @param DoliDB $db   Database handler
	 * @param object $line Line data
	 * @return int         1 if anomaly recorded, 0 otherwise
	 */
	public static function checkDoublon($db, $line)
	{
		$rule = self::getRule($db, 'R01');
		if (!$rule || !self::ruleAppliesToAccount($rule, $line->fk_account)) {
			return 0;
		}

		$days = ($rule->seuil !== null) ? (int) $rule->seuil : (int) getDolGlobalString('BANKAUDIT_DUPLICATE_DAYS', '1');
		if ($days < 0) {
			$days = 1;
		}
		$tsmin = $db->idate($line->dateo_ts - ($days * 86400));
		$tsmax = $db->idate($line->dateo_ts + ($days * 86400));

		$sql = "SELECT COUNT(rowid) as nb FROM ".MAIN_DB_PREFIX."bank";
		$sql .= " WHERE fk_account = ".((int) $line->fk_account);
		$sql .= " AND ABS(amount) = ".abs($line->amount);
		$sql .= " AND dateo BETWEEN '".$tsmin."' AND '".$tsmax."'";
		$sql .= " AND rowid <> ".((int) $line->rowid);

		$resql = $db->query($sql);
		if ($resql && ($obj = $db->fetch_object($resql)) && $obj->nb > 0) {
			self::recordAnomaly($db, $rule, $line->rowid, $line->fk_account, 'warning', array(
				'amount' => $line->amount,
				'window_days' => $days,
				'nb_similar' => (int) $obj->nb,
			));
			return 1;
		}
		return 0;
	}

	/**
	 * R02 - Unusual amount (greater than factor x the 30-day average).
	 *
	 * @param DoliDB $db   Database handler
	 * @param object $line Line data
	 * @return int         1 if anomaly recorded, 0 otherwise
	 */
	public static function checkMontantInhabituel($db, $line)
	{
		$rule = self::getRule($db, 'R02');
		if (!$rule || !self::ruleAppliesToAccount($rule, $line->fk_account)) {
			return 0;
		}

		$factor = ($rule->seuil !== null) ? (float) $rule->seuil : (float) getDolGlobalString('BANKAUDIT_ANOMALY_AVG_FACTOR', '3');
		if ($factor <= 0) {
			$factor = 3;
		}
		$tsmin = $db->idate(dol_now() - (30 * 86400));

		$sql = "SELECT AVG(ABS(amount)) as moyenne FROM ".MAIN_DB_PREFIX."bank";
		$sql .= " WHERE fk_account = ".((int) $line->fk_account);
		$sql .= " AND dateo >= '".$tsmin."'";
		$sql .= " AND rowid <> ".((int) $line->rowid);

		$resql = $db->query($sql);
		if ($resql && ($obj = $db->fetch_object($resql)) && $obj->moyenne > 0) {
			$moyenne = (float) $obj->moyenne;
			if (abs($line->amount) > ($factor * $moyenne)) {
				self::recordAnomaly($db, $rule, $line->rowid, $line->fk_account, 'warning', array(
					'amount' => $line->amount,
					'average_30d' => round($moyenne, 2),
					'factor' => $factor,
				));
				return 1;
			}
		}
		return 0;
	}

	/**
	 * R03 - Operation date significantly in the past.
	 *
	 * @param DoliDB $db   Database handler
	 * @param object $line Line data
	 * @return int         1 if anomaly recorded, 0 otherwise
	 */
	public static function checkDateAncienne($db, $line)
	{
		$rule = self::getRule($db, 'R03');
		if (!$rule || !self::ruleAppliesToAccount($rule, $line->fk_account)) {
			return 0;
		}

		$maxdays = ($rule->seuil !== null) ? (int) $rule->seuil : (int) getDolGlobalString('BANKAUDIT_OLD_DATE_DAYS', '30');
		if ($maxdays <= 0) {
			$maxdays = 30;
		}
		$jours = (dol_now() - $line->dateo_ts) / 86400;

		if ($jours > $maxdays) {
			self::recordAnomaly($db, $rule, $line->rowid, $line->fk_account, 'info', array(
				'days_old' => (int) round($jours),
				'threshold' => $maxdays,
			));
			return 1;
		}
		return 0;
	}

	/**
	 * R05 - Negative balance (account balance below its configured minimum threshold).
	 *
	 * @param DoliDB $db   Database handler
	 * @param object $line Line data
	 * @return int         1 if anomaly recorded, 0 otherwise
	 */
	public static function checkSoldeNegatif($db, $line)
	{
		$rule = self::getRule($db, 'R05');
		if (!$rule || !self::ruleAppliesToAccount($rule, $line->fk_account)) {
			return 0;
		}

		$balance = BankAudit::getAccountBalance($db, $line->fk_account);
		$threshold = BankAudit::getMinBalanceForAccount($db, $line->fk_account);
		if ($threshold === null) {
			$threshold = 0.0; // default: no negative balance allowed
		}

		if ($balance < $threshold) {
			self::recordAnomaly($db, $rule, $line->rowid, $line->fk_account, 'critical', array(
				'balance' => round($balance, 2),
				'threshold' => round($threshold, 2),
			));
			return 1;
		}
		return 0;
	}

	/**
	 * R06 - Movement on an account that was inactive for a long time.
	 *
	 * @param DoliDB $db   Database handler
	 * @param object $line Line data
	 * @return int         1 if anomaly recorded, 0 otherwise
	 */
	public static function checkCompteInactif($db, $line)
	{
		$rule = self::getRule($db, 'R06');
		if (!$rule || !self::ruleAppliesToAccount($rule, $line->fk_account)) {
			return 0;
		}

		$maxdays = ($rule->seuil !== null) ? (int) $rule->seuil : (int) getDolGlobalString('BANKAUDIT_INACTIVE_DAYS', '90');
		if ($maxdays <= 0) {
			$maxdays = 90;
		}

		$sql = "SELECT MAX(dateo) as derniere FROM ".MAIN_DB_PREFIX."bank";
		$sql .= " WHERE fk_account = ".((int) $line->fk_account);
		$sql .= " AND rowid <> ".((int) $line->rowid);

		$resql = $db->query($sql);
		if ($resql && ($obj = $db->fetch_object($resql)) && !empty($obj->derniere)) {
			$last = $db->jdate($obj->derniere);
			$jours = ($line->dateo_ts - $last) / 86400;
			if ($jours > $maxdays) {
				self::recordAnomaly($db, $rule, $line->rowid, $line->fk_account, 'warning', array(
					'inactive_days' => (int) round($jours),
					'threshold' => $maxdays,
				));
				return 1;
			}
		}
		return 0;
	}

	/**
	 * R04 - Abnormal exchange rate variation. Called from the rate change trigger.
	 *
	 * @param DoliDB     $db        Database handler
	 * @param string     $code_from Source currency
	 * @param string     $code_to   Target currency
	 * @param float      $rate_new  New rate
	 * @param float|null $variation Variation in percent (already computed)
	 * @return int                  1 if anomaly recorded, 0 otherwise
	 */
	public static function checkTauxAnormal($db, $code_from, $code_to, $rate_new, $variation)
	{
		$rule = self::getRule($db, 'R04');
		if (!$rule || $variation === null) {
			return 0;
		}

		$threshold = ($rule->seuil !== null) ? (float) $rule->seuil : (float) getDolGlobalString('BANKAUDIT_RATE_ALERT_THRESHOLD', '10');
		if ($variation > $threshold) {
			self::recordAnomaly($db, $rule, null, null, 'critical', array(
				'pair' => $code_from.'/'.$code_to,
				'rate_new' => $rate_new,
				'variation_pct' => round($variation, 2),
				'threshold' => $threshold,
			));
			return 1;
		}
		return 0;
	}

	/**
	 * Batch scan of recent bank lines, to detect anomalies for entries that were
	 * not created through a hooked page (imports, payments, transfers...).
	 *
	 * @param DoliDB $db   Database handler
	 * @param User   $user Acting user
	 * @param int    $days Number of days to look back
	 * @return int         Number of anomalies recorded
	 */
	public static function scanRecentLines($db, $user, $days = 30)
	{
		global $conf;

		$days = (int) $days;
		if ($days <= 0) {
			$days = 30;
		}
		$tsmin = $db->idate(dol_now() - ($days * 86400));

		$sql = "SELECT b.rowid FROM ".MAIN_DB_PREFIX."bank as b";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."bank_account as ba ON ba.rowid = b.fk_account";
		$sql .= " WHERE ba.entity IN (".getEntity('bank_account').")";
		$sql .= " AND b.dateo >= '".$tsmin."'";
		$sql .= " ORDER BY b.rowid";

		$nb = 0;
		$resql = $db->query($sql);
		if ($resql) {
			while ($obj = $db->fetch_object($resql)) {
				$nb += self::checkLine($db, $user, (int) $obj->rowid);
			}
		}
		return $nb;
	}
}

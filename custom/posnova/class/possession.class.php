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
 * \file    custom/posnova/class/possession.class.php
 * \ingroup posnova
 * \brief   Cash session lifecycle, decoupled from the HTTP session.
 */

dol_include_once('/posnova/class/posnova.class.php');


/**
 * A cash session survives browser/network outages: it is only closed by an explicit
 * vendor action, by token expiry, or by an administrator from the back office.
 */
class PosSession
{
	/** @var DoliDB */
	public $db;
	/** @var string */
	public $error = '';

	public $id;
	public $entity;
	public $ref;
	public $fk_pos;
	public $fk_warehouse;
	public $fk_user_open;
	public $fk_rate;
	public $rate_usd_cdf;
	public $rate_source;
	public $session_token;
	public $fund_init_usd = 0;
	public $fund_init_cdf = 0;
	public $fund_final_usd;
	public $fund_final_cdf;
	public $reconnection_count = 0;
	public $status = 'OPEN';
	public $date_open;
	public $date_close;
	public $last_activity;

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
	 * Map a DB row onto the object.
	 *
	 * @param  object $obj DB row
	 * @return void
	 */
	private function setFromObj($obj)
	{
		$this->id = (int) $obj->rowid;
		$this->entity = (int) $obj->entity;
		$this->ref = $obj->ref;
		$this->fk_pos = (int) $obj->fk_pos;
		$this->fk_warehouse = (int) $obj->fk_warehouse;
		$this->fk_user_open = (int) $obj->fk_user_open;
		$this->fk_rate = $obj->fk_rate ? (int) $obj->fk_rate : null;
		$this->rate_usd_cdf = $obj->rate_usd_cdf !== null ? (float) $obj->rate_usd_cdf : null;
		$this->rate_source = $obj->rate_source;
		$this->session_token = $obj->session_token;
		$this->fund_init_usd = (float) $obj->fund_init_usd;
		$this->fund_init_cdf = (float) $obj->fund_init_cdf;
		$this->fund_final_usd = $obj->fund_final_usd !== null ? (float) $obj->fund_final_usd : null;
		$this->fund_final_cdf = $obj->fund_final_cdf !== null ? (float) $obj->fund_final_cdf : null;
		$this->reconnection_count = (int) $obj->reconnection_count;
		$this->status = $obj->status;
		$this->date_open = $this->db->jdate($obj->date_open);
		$this->date_close = $obj->date_close ? $this->db->jdate($obj->date_close) : null;
		$this->last_activity = $obj->last_activity ? $this->db->jdate($obj->last_activity) : null;
	}

	/**
	 * SELECT column list shared by all fetchers.
	 *
	 * @return string
	 */
	private function cols()
	{
		return "rowid, entity, ref, fk_pos, fk_warehouse, fk_user_open, fk_rate, rate_usd_cdf, rate_source,"
			." session_token, fund_init_usd, fund_init_cdf, fund_final_usd, fund_final_cdf, reconnection_count,"
			." status, date_open, date_close, last_activity";
	}

	/**
	 * Load a session by id.
	 *
	 * @param  int $id Session id
	 * @return int     1 if found, 0 if not, <0 on error
	 */
	public function fetch($id)
	{
		$sql = "SELECT ".$this->cols()." FROM ".MAIN_DB_PREFIX."pos_session WHERE rowid = ".((int) $id);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		if (!$this->db->num_rows($resql)) {
			return 0;
		}
		$this->setFromObj($this->db->fetch_object($resql));
		return 1;
	}

	/**
	 * Load a session by its long-lived token.
	 *
	 * @param  string $token Session token
	 * @return int           1 if found, 0 if not, <0 on error
	 */
	public function fetchByToken($token)
	{
		$sql = "SELECT ".$this->cols()." FROM ".MAIN_DB_PREFIX."pos_session";
		$sql .= " WHERE session_token = '".$this->db->escape($token)."'";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		if (!$this->db->num_rows($resql)) {
			return 0;
		}
		$this->setFromObj($this->db->fetch_object($resql));
		return 1;
	}

	/**
	 * Return the currently open (or locked) session of a terminal, if any.
	 *
	 * @param  int $posid Terminal id
	 * @return int        1 if an open/locked session is loaded, 0 otherwise
	 */
	public function fetchOpenForPos($posid)
	{
		$sql = "SELECT ".$this->cols()." FROM ".MAIN_DB_PREFIX."pos_session";
		$sql .= " WHERE fk_pos = ".((int) $posid)." AND status IN ('OPEN','LOCKED')";
		$sql .= " ORDER BY rowid DESC LIMIT 1";
		$resql = $this->db->query($sql);
		if ($resql && $this->db->num_rows($resql)) {
			$this->setFromObj($this->db->fetch_object($resql));
			return 1;
		}
		return 0;
	}

	/**
	 * Open a new cash session for a terminal.
	 *
	 * @param  User   $user    Vendor opening the session
	 * @param  int    $posid   Terminal id
	 * @param  int    $whid    Warehouse id (from terminal)
	 * @param  float  $fundUsd Initial USD cash
	 * @param  float  $fundCdf Initial CDF cash
	 * @param  array  $rate    Rate info from PosNova::getDailyRate()
	 * @return int             New session id, <0 on error
	 */
	public function open($user, $posid, $whid, $fundUsd, $fundCdf, $rate)
	{
		global $conf;

		// Refuse to open a second session on a terminal already in use.
		$existing = new PosSession($this->db);
		if ($existing->fetchOpenForPos($posid) > 0) {
			$this->error = 'PosNovaErrSessionAlreadyOpen';
			return -10;
		}

		$now = dol_now();
		$token = PosNova::generateToken();
		$ref = $this->buildRef($posid);
		$entity = (int) $conf->entity;

		$sql = "INSERT INTO ".MAIN_DB_PREFIX."pos_session";
		$sql .= " (entity, ref, fk_pos, fk_warehouse, fk_user_open, fk_rate, rate_usd_cdf, rate_source,";
		$sql .= " session_token, fund_init_usd, fund_init_cdf, status, date_open, last_activity)";
		$sql .= " VALUES (".$entity.", '".$this->db->escape($ref)."', ".((int) $posid).", ".((int) $whid).", ".((int) $user->id).",";
		$sql .= " ".(!empty($rate['rowid']) ? ((int) $rate['rowid']) : "NULL").",";
		$sql .= " ".(isset($rate['rate']) ? ((float) $rate['rate']) : "NULL").",";
		$sql .= " ".(isset($rate['source']) ? "'".$this->db->escape($rate['source'])."'" : "NULL").",";
		$sql .= " '".$this->db->escape($token)."', ".((float) $fundUsd).", ".((float) $fundCdf).", 'OPEN',";
		$sql .= " '".$this->db->idate($now)."', '".$this->db->idate($now)."')";

		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -2;
		}
		$this->id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX."pos_session");
		$this->session_token = $token;
		$this->ref = $ref;
		$this->status = 'OPEN';

		PosNova::audit($this->db, $user, 'SESSION_OPEN', 'pos_session', $this->id, null, $ref, $posid);
		return $this->id;
	}

	/**
	 * Build a gap-free per-terminal session reference: {posref}-S{Ymd}-{seq}.
	 *
	 * @param  int $posid Terminal id
	 * @return string     Reference
	 */
	private function buildRef($posid)
	{
		global $conf;

		$posref = 'POS'.$posid;
		$sqlref = "SELECT ref FROM ".MAIN_DB_PREFIX."pos_config WHERE rowid = ".((int) $posid);
		$resql = $this->db->query($sqlref);
		if ($resql && $this->db->num_rows($resql)) {
			$posref = $this->db->fetch_object($resql)->ref;
		}

		$ymd = dol_print_date(dol_now(), '%Y%m%d');
		$sql = "SELECT COUNT(rowid) as nb FROM ".MAIN_DB_PREFIX."pos_session";
		$sql .= " WHERE fk_pos = ".((int) $posid)." AND entity = ".((int) $conf->entity);
		$sql .= " AND ref LIKE '".$this->db->escape($posref."-S".$ymd)."%'";
		$resql = $this->db->query($sql);
		$seq = 1;
		if ($resql && $this->db->num_rows($resql)) {
			$seq = ((int) $this->db->fetch_object($resql)->nb) + 1;
		}
		return $posref.'-S'.$ymd.'-'.sprintf('%03d', $seq);
	}

	/**
	 * Update the last-activity timestamp (called on each meaningful interaction).
	 *
	 * @return void
	 */
	public function touch()
	{
		if (empty($this->id)) {
			return;
		}
		$this->db->query("UPDATE ".MAIN_DB_PREFIX."pos_session SET last_activity = '".$this->db->idate(dol_now())."' WHERE rowid = ".((int) $this->id));
	}

	/**
	 * Increment the reconnection counter when a session is resumed after an outage.
	 *
	 * @return void
	 */
	public function registerReconnection()
	{
		if (empty($this->id)) {
			return;
		}
		$this->db->query("UPDATE ".MAIN_DB_PREFIX."pos_session SET reconnection_count = reconnection_count + 1, last_activity = '".$this->db->idate(dol_now())."' WHERE rowid = ".((int) $this->id));
		$this->reconnection_count++;
	}

	/**
	 * Lock the session (pause) with a hashed PIN, without closing it.
	 *
	 * @param  string $pin Plain PIN
	 * @return int         1 on success, <0 on error
	 */
	public function lock($pin)
	{
		if (empty($this->id) || $this->status !== 'OPEN') {
			return -1;
		}
		$hash = dol_hash($pin, '0');
		$sql = "UPDATE ".MAIN_DB_PREFIX."pos_session SET status = 'LOCKED', pin_lock = '".$this->db->escape($hash)."' WHERE rowid = ".((int) $this->id);
		if (!$this->db->query($sql)) {
			return -2;
		}
		$this->status = 'LOCKED';
		return 1;
	}

	/**
	 * Unlock a paused session by verifying the PIN.
	 *
	 * @param  string $pin Plain PIN
	 * @return int         1 on success, 0 on wrong PIN, <0 on error
	 */
	public function unlock($pin)
	{
		if (empty($this->id) || $this->status !== 'LOCKED') {
			return -1;
		}
		$sql = "SELECT pin_lock FROM ".MAIN_DB_PREFIX."pos_session WHERE rowid = ".((int) $this->id);
		$resql = $this->db->query($sql);
		if (!$resql || !$this->db->num_rows($resql)) {
			return -2;
		}
		$stored = $this->db->fetch_object($resql)->pin_lock;
		if (!dol_verifyHash($pin, $stored, '0')) {
			return 0;
		}
		$this->db->query("UPDATE ".MAIN_DB_PREFIX."pos_session SET status = 'OPEN', pin_lock = NULL WHERE rowid = ".((int) $this->id));
		$this->status = 'OPEN';
		return 1;
	}

	/**
	 * Aggregate sales totals of the session, grouped by account and currency.
	 *
	 * @return array array('byaccount'=>array, 'expected'=>array('USD'=>float,'CDF'=>float))
	 */
	public function getCashSummary()
	{
		$cdfCode = getDolGlobalString('POSNOVA_CDF_CURRENCY_CODE', PosNova::CDF);
		$out = array('byaccount' => array(), 'expected' => array(PosNova::USD => (float) $this->fund_init_usd, $cdfCode => (float) $this->fund_init_cdf));

		// Payments received minus change given, per currency, for cash modes.
		$sql = "SELECT pp.currency, pp.mode, SUM(pp.amount) as total";
		$sql .= " FROM ".MAIN_DB_PREFIX."pos_payment pp";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."pos_ticket pt ON pt.rowid = pp.fk_ticket";
		$sql .= " WHERE pt.fk_session = ".((int) $this->id)." AND pt.status = 'VALIDATED'";
		$sql .= " GROUP BY pp.currency, pp.mode";
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$cur = strtoupper((string) $obj->currency);
				if (!isset($out['expected'][$cur])) {
					$out['expected'][$cur] = 0;
				}
				// Only cash (LIQ) feeds the physical drawer.
				if ($obj->mode === 'LIQ' || $obj->mode === 'CASH') {
					$out['expected'][$cur] += (float) $obj->total;
				}
				$out['byaccount'][] = array('currency' => $cur, 'mode' => $obj->mode, 'total' => (float) $obj->total);
			}
		}

		// Subtract change given in cash.
		$sqlc = "SELECT pc.currency, SUM(pc.amount) as total FROM ".MAIN_DB_PREFIX."pos_change pc";
		$sqlc .= " INNER JOIN ".MAIN_DB_PREFIX."pos_ticket pt ON pt.rowid = pc.fk_ticket";
		$sqlc .= " WHERE pt.fk_session = ".((int) $this->id)." AND pt.status = 'VALIDATED'";
		$sqlc .= " GROUP BY pc.currency";
		$resc = $this->db->query($sqlc);
		if ($resc) {
			while ($obj = $this->db->fetch_object($resc)) {
				$cur = strtoupper((string) $obj->currency);
				if (!isset($out['expected'][$cur])) {
					$out['expected'][$cur] = 0;
				}
				$out['expected'][$cur] -= (float) $obj->total;
			}
		}

		return $out;
	}

	/**
	 * Close the session: record counted cash and compute discrepancies.
	 *
	 * @param  User  $user      Acting user
	 * @param  float $finalUsd  Counted USD cash
	 * @param  float $finalCdf  Counted CDF cash
	 * @return int              1 on success, <0 on error
	 */
	public function close($user, $finalUsd, $finalCdf)
	{
		if (empty($this->id) || !in_array($this->status, array('OPEN', 'LOCKED'), true)) {
			$this->error = 'PosNovaErrSessionNotOpen';
			return -1;
		}
		// Refuse to close while offline tickets are still pending sync.
		$sqlp = "SELECT rowid FROM ".MAIN_DB_PREFIX."pos_ticket WHERE fk_session = ".((int) $this->id)." AND sync_status = 'PENDING_SYNC' LIMIT 1";
		$resp = $this->db->query($sqlp);
		if ($resp && $this->db->num_rows($resp) > 0) {
			$this->error = 'PosNovaErrPendingSync';
			return -3;
		}

		$now = dol_now();
		$sql = "UPDATE ".MAIN_DB_PREFIX."pos_session SET";
		$sql .= " status = 'CLOSED',";
		$sql .= " fund_final_usd = ".((float) $finalUsd).",";
		$sql .= " fund_final_cdf = ".((float) $finalCdf).",";
		$sql .= " date_close = '".$this->db->idate($now)."'";
		$sql .= " WHERE rowid = ".((int) $this->id);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -2;
		}
		$this->status = 'CLOSED';

		PosNova::audit($this->db, $user, 'SESSION_CLOSE', 'pos_session', $this->id, null, 'USD '.$finalUsd.' / CDF '.$finalCdf, $this->fk_pos);
		return 1;
	}
}

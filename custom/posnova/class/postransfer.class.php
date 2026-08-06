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
 * \file    custom/posnova/class/postransfer.class.php
 * \ingroup posnova
 * \brief   Inter-warehouse transfer workflow with approval, reservation and expiry.
 *
 * The source warehouse (wh_from) is always locked server-side to the POS warehouse of the
 * initiator: a request carrying a different wh_from is rejected. A PENDING request logically
 * reserves stock (counted against availability) until it is approved, refused or expires.
 */

dol_include_once('/posnova/class/posnova.class.php');


/**
 * Manage POS stock transfer requests.
 */
class PosTransfer
{
	/** @var DoliDB */
	public $db;
	/** @var string */
	public $error = '';

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
	 * Quantity currently reserved by PENDING transfers for a product on a warehouse.
	 *
	 * @param  int $pid  Product id
	 * @param  int $whid Source warehouse id
	 * @return float     Reserved quantity
	 */
	public function getReservedQty($pid, $whid)
	{
		$sql = "SELECT SUM(qty) as q FROM ".MAIN_DB_PREFIX."pos_transfer";
		$sql .= " WHERE fk_product = ".((int) $pid)." AND wh_from = ".((int) $whid)." AND status = 'PENDING'";
		$resql = $this->db->query($sql);
		if ($resql && $this->db->num_rows($resql)) {
			return (float) $this->db->fetch_object($resql)->q;
		}
		return 0.0;
	}

	/**
	 * Create a transfer request. wh_from is forced to the POS warehouse.
	 *
	 * @param  User      $user    Initiator (must hold the 'transfer' right)
	 * @param  PosConfig $pos     Terminal of the initiator
	 * @param  int       $pid     Product id
	 * @param  float     $qty     Quantity
	 * @param  int       $whTo    Destination warehouse id
	 * @param  string    $motif   Reason (mandatory)
	 * @return int                New id, <0 on error
	 */
	public function request($user, $pos, $pid, $qty, $whTo, $motif)
	{
		global $conf;

		if (!$user->hasRight('posnova', 'transfer')) {
			$this->error = 'PosNovaErrNoTransferRight';
			return -1;
		}
		$pid = (int) $pid;
		$qty = (float) $qty;
		$whFrom = (int) $pos->fk_warehouse; // locked server-side
		$whTo = (int) $whTo;
		$motif = trim((string) $motif);

		if ($pid <= 0 || $qty <= 0 || $whTo <= 0 || $whFrom <= 0) {
			$this->error = 'PosNovaErrBadTransfer';
			return -2;
		}
		if ($whTo === $whFrom) {
			$this->error = 'PosNovaErrSameWarehouse';
			return -3;
		}
		if ($motif === '') {
			$this->error = 'PosNovaErrMotifRequired';
			return -4;
		}

		// Availability check (reel minus already-reserved).
		$reel = $this->getWarehouseStock($pid, $whFrom);
		$reserved = $this->getReservedQty($pid, $whFrom);
		if (($reel - $reserved) < $qty) {
			$this->error = 'PosNovaErrInsufficientStock';
			return -5;
		}

		$now = dol_now();
		$timeoutH = (int) $pos->transfer_timeout_hours;
		if ($timeoutH <= 0) {
			$timeoutH = (int) getDolGlobalInt('POSNOVA_TRANSFER_TIMEOUT_HOURS', 24);
		}
		$expires = $now + $timeoutH * 3600;

		$sql = "INSERT INTO ".MAIN_DB_PREFIX."pos_transfer";
		$sql .= " (entity, fk_pos, fk_user_initiator, fk_product, qty, wh_from, wh_to, motif, status, date_request, expires_at)";
		$sql .= " VALUES (".((int) $conf->entity).", ".((int) $pos->id).", ".((int) $user->id).", ".$pid.", ".$qty.", ".$whFrom.", ".$whTo.",";
		$sql .= " '".$this->db->escape($motif)."', 'PENDING', '".$this->db->idate($now)."', '".$this->db->idate($expires)."')";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -6;
		}
		$id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX."pos_transfer");
		PosNova::audit($this->db, $user, 'TRANSFER_REQUEST', 'pos_transfer', $id, null, 'qty '.$qty.' wh '.$whFrom.'->'.$whTo, $pos->id);
		return $id;
	}

	/**
	 * Approve a pending transfer: move the stock and close the request.
	 *
	 * @param  User $user Approver (must hold the 'transfer' right)
	 * @param  int  $id   Transfer id
	 * @return int        1 on success, <0 on error
	 */
	public function approve($user, $id)
	{
		require_once DOL_DOCUMENT_ROOT.'/product/stock/class/mouvementstock.class.php';

		if (!$user->hasRight('posnova', 'transfer')) {
			$this->error = 'PosNovaErrNoTransferRight';
			return -1;
		}
		$row = $this->fetchRow($id);
		if (!$row || $row->status !== 'PENDING') {
			$this->error = 'PosNovaErrTransferNotPending';
			return -2;
		}

		$this->db->begin();

		$mv = new MouvementStock($this->db);
		$label = 'PosNova transfer #'.$id;
		// Output from source then input to destination (keeps stock history coherent).
		$resOut = $mv->livraison($user, (int) $row->fk_product, (int) $row->wh_from, (float) $row->qty, 0, $label, dol_now(), '', '', '', 0, 'POSNOVA-TRF');
		if ($resOut < 0) {
			$this->db->rollback();
			$this->error = $mv->error ? $mv->error : 'PosNovaErrStockOut';
			return -3;
		}
		$mv2 = new MouvementStock($this->db);
		$resIn = $mv2->reception($user, (int) $row->fk_product, (int) $row->wh_to, (float) $row->qty, 0, $label, dol_now(), '', '', '', '', 0, 'POSNOVA-TRF');
		if ($resIn < 0) {
			$this->db->rollback();
			$this->error = $mv2->error ? $mv2->error : 'PosNovaErrStockIn';
			return -4;
		}

		$sql = "UPDATE ".MAIN_DB_PREFIX."pos_transfer SET status = 'APPROVED', fk_user_approver = ".((int) $user->id).", date_decision = '".$this->db->idate(dol_now())."' WHERE rowid = ".((int) $id);
		if (!$this->db->query($sql)) {
			$this->db->rollback();
			$this->error = $this->db->lasterror();
			return -5;
		}

		PosNova::audit($this->db, $user, 'TRANSFER_APPROVE', 'pos_transfer', $id, null, 'qty '.$row->qty, (int) $row->fk_pos);
		$this->db->commit();
		return 1;
	}

	/**
	 * Refuse a pending transfer (releases the reservation).
	 *
	 * @param  User   $user   Approver
	 * @param  int    $id     Transfer id
	 * @param  string $reason Refusal reason (mandatory)
	 * @return int            1 on success, <0 on error
	 */
	public function refuse($user, $id, $reason)
	{
		if (!$user->hasRight('posnova', 'transfer')) {
			$this->error = 'PosNovaErrNoTransferRight';
			return -1;
		}
		$reason = trim((string) $reason);
		if ($reason === '') {
			$this->error = 'PosNovaErrMotifRequired';
			return -2;
		}
		$row = $this->fetchRow($id);
		if (!$row || $row->status !== 'PENDING') {
			$this->error = 'PosNovaErrTransferNotPending';
			return -3;
		}
		$sql = "UPDATE ".MAIN_DB_PREFIX."pos_transfer SET status = 'REFUSED', fk_user_approver = ".((int) $user->id).", motif_refus = '".$this->db->escape($reason)."', date_decision = '".$this->db->idate(dol_now())."' WHERE rowid = ".((int) $id);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -4;
		}
		PosNova::audit($this->db, $user, 'TRANSFER_REFUSE', 'pos_transfer', $id, null, $reason, (int) $row->fk_pos);
		return 1;
	}

	/**
	 * Cancel a pending transfer by its initiator (releases the reservation).
	 *
	 * @param  User $user Initiator
	 * @param  int  $id   Transfer id
	 * @return int        1 on success, <0 on error
	 */
	public function cancel($user, $id)
	{
		$row = $this->fetchRow($id);
		if (!$row || $row->status !== 'PENDING') {
			$this->error = 'PosNovaErrTransferNotPending';
			return -1;
		}
		if ((int) $row->fk_user_initiator !== (int) $user->id && !$user->admin) {
			$this->error = 'PosNovaErrNotInitiator';
			return -2;
		}
		$sql = "UPDATE ".MAIN_DB_PREFIX."pos_transfer SET status = 'CANCELLED', date_decision = '".$this->db->idate(dol_now())."' WHERE rowid = ".((int) $id);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -3;
		}
		PosNova::audit($this->db, $user, 'TRANSFER_CANCEL', 'pos_transfer', $id, null, null, (int) $row->fk_pos);
		return 1;
	}

	/**
	 * Cron entry point: expire stale PENDING reservations.
	 *
	 * @return int 0 on success (Dolibarr cron convention), <0 on error
	 */
	public function cronReleaseExpired()
	{
		$this->error = '';
		$now = dol_now();
		$sql = "UPDATE ".MAIN_DB_PREFIX."pos_transfer SET status = 'EXPIRED', date_decision = '".$this->db->idate($now)."'";
		$sql .= " WHERE status = 'PENDING' AND expires_at IS NOT NULL AND expires_at < '".$this->db->idate($now)."'";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$this->output = 'Expired transfer reservations released.';
		return 0;
	}

	/** @var string Cron output message. */
	public $output = '';

	/**
	 * Fetch a raw transfer row.
	 *
	 * @param  int         $id Transfer id
	 * @return object|null     Row or null
	 */
	private function fetchRow($id)
	{
		$sql = "SELECT rowid, entity, fk_pos, fk_user_initiator, fk_product, qty, wh_from, wh_to, status FROM ".MAIN_DB_PREFIX."pos_transfer WHERE rowid = ".((int) $id);
		$resql = $this->db->query($sql);
		if ($resql && $this->db->num_rows($resql)) {
			return $this->db->fetch_object($resql);
		}
		return null;
	}

	/**
	 * Read the available (reel) stock of a product on a warehouse.
	 *
	 * @param  int $pid  Product id
	 * @param  int $whid Warehouse id
	 * @return float     Available quantity
	 */
	private function getWarehouseStock($pid, $whid)
	{
		$sql = "SELECT reel FROM ".MAIN_DB_PREFIX."product_stock WHERE fk_product = ".((int) $pid)." AND fk_entrepot = ".((int) $whid);
		$resql = $this->db->query($sql);
		if ($resql && $this->db->num_rows($resql)) {
			return (float) $this->db->fetch_object($resql)->reel;
		}
		return 0.0;
	}
}

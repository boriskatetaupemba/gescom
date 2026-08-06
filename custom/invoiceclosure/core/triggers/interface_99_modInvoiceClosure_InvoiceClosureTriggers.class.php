<?php
/* Copyright (C) 2026 InvoiceClosure module
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
 * \file    custom/invoiceclosure/core/triggers/interface_99_modInvoiceClosure_InvoiceClosureTriggers.class.php
 * \ingroup invoiceclosure
 * \brief   Triggers of the InvoiceClosure module.
 *
 * Two roles:
 *  1. Log the module business events INVOICECLOSURE_CLOSE / INVOICECLOSURE_REOPEN.
 *  2. Enforce the server side lock of closed invoices: every standard action
 *     listed below runs inside a database transaction in Dolibarr 20.0.4 and
 *     is rolled back by the caller when a trigger returns a negative value.
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';
dol_include_once('/invoiceclosure/class/invoiceclosure.class.php');


/**
 * Class of triggers for the InvoiceClosure module
 */
class InterfaceInvoiceClosureTriggers extends DolibarrTriggers
{
	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		parent::__construct($db);
		$this->family = "financial";
		$this->description = "InvoiceClosure triggers: business events and lock of closed customer invoices.";
		$this->version = '1.0.0';
		$this->picto = 'bill';
	}

	/**
	 * Function called when a Dolibarr business event is fired.
	 *
	 * @param string       $action Event action code
	 * @param CommonObject $object Object concerned by the event
	 * @param User         $user   Object user
	 * @param Translate    $langs  Object langs
	 * @param Conf         $conf   Object conf
	 * @return int                 Return integer <0 if KO (transaction rolled back by caller), 0 if no action, >0 if OK
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (!isModEnabled('invoiceclosure')) {
			return 0;
		}

		// ------------------------------------------------------------
		// 1. Business events of the module itself
		// ------------------------------------------------------------
		if ($action === 'INVOICECLOSURE_CLOSE' || $action === 'INVOICECLOSURE_REOPEN') {
			$ctx = (is_object($object) && !empty($object->context) && is_array($object->context)) ? $object->context : array();
			dol_syslog(
				"Trigger '".$this->name."' for action '".$action."' launched by ".__FILE__.". invoice=".(isset($ctx['invoiceid']) ? (int) $ctx['invoiceid'] : 0)
				." ref=".(isset($ctx['invoiceref']) ? $ctx['invoiceref'] : '')
				." entity=".(isset($ctx['entity']) ? (int) $ctx['entity'] : 0)
				." oldbusinessstatus=".(isset($ctx['oldbusinessstatus']) ? (int) $ctx['oldbusinessstatus'] : '')
				." newbusinessstatus=".(isset($ctx['newbusinessstatus']) ? (int) $ctx['newbusinessstatus'] : '')
				." source=".(isset($ctx['source']) ? $ctx['source'] : '')
				." request_id=".(isset($ctx['request_id']) ? $ctx['request_id'] : '')
				." user=".$user->login,
				LOG_INFO
			);
			return 0;
		}

		// ------------------------------------------------------------
		// 2. Lock of closed invoices
		// ------------------------------------------------------------

		// Standard invoice level events that modify a customer invoice
		$invoiceActions = array('BILL_MODIFY', 'BILL_UNPAYED', 'BILL_CANCEL', 'BILL_UNVALIDATE');
		// Standard invoice line level events
		$lineActions = array('LINEBILL_INSERT', 'LINEBILL_MODIFY', 'LINEBILL_DELETE');

		if ($action === 'BILL_DELETE') {
			return $this->handleInvoiceDelete($object, $user, $langs);
		}

		if (in_array($action, $invoiceActions, true)) {
			if (!is_object($object) || empty($object->id) || (isset($object->element) && $object->element !== 'facture')) {
				return 0;
			}
			return $this->enforceLock($action, (int) $object->id, (string) (isset($object->ref) ? $object->ref : $object->id), $user, $langs);
		}

		if (in_array($action, $lineActions, true)) {
			$invoiceId = (is_object($object) && !empty($object->fk_facture)) ? (int) $object->fk_facture : 0;
			if ($invoiceId <= 0) {
				return 0;
			}
			return $this->enforceLock($action, $invoiceId, (string) $invoiceId, $user, $langs);
		}

		if ($action === 'PAYMENT_CUSTOMER_DELETE') {
			if (!is_object($object) || empty($object->id)) {
				return 0;
			}
			// Find the customer invoices linked to this payment
			$sql = "SELECT pf.fk_facture FROM ".MAIN_DB_PREFIX."paiement_facture as pf";
			$sql .= " WHERE pf.fk_paiement = ".((int) $object->id);
			$resql = $this->db->query($sql);
			if (!$resql) {
				// Do not block the payment operation for a read error, but log it
				dol_syslog(get_class($this)."::runTrigger unable to read invoices of payment ".((int) $object->id).": ".$this->db->lasterror(), LOG_ERR);
				return 0;
			}
			while ($obj = $this->db->fetch_object($resql)) {
				$res = $this->enforceLock($action, (int) $obj->fk_facture, (string) $obj->fk_facture, $user, $langs);
				if ($res < 0) {
					$this->db->free($resql);
					return $res;
				}
			}
			$this->db->free($resql);
			return 0;
		}

		return 0;
	}

	/**
	 * BILL_DELETE needs a dedicated handling: when the deletion is authorized
	 * (invoice not closed, lock disabled, or "force" permission), the closure
	 * record must be removed first, otherwise the foreign key on fk_facture
	 * would make the core deletion fail. History rows are kept (no FK there).
	 *
	 * @param  CommonObject $object Invoice being deleted
	 * @param  User         $user   Acting user
	 * @param  Translate    $langs  Translation handler
	 * @return int                  0 if OK, <0 to abort the deletion
	 */
	private function handleInvoiceDelete($object, User $user, Translate $langs)
	{
		if (!is_object($object) || empty($object->id) || (isset($object->element) && $object->element !== 'facture')) {
			return 0;
		}

		$record = $this->getClosureRecord((int) $object->id);
		if ($record === null) {
			return 0; // never closed: nothing to do
		}

		$isclosed = ((int) $record->closure_status === InvoiceClosure::STATUS_CLOSED);
		if ($isclosed && getDolGlobalInt('INVOICECLOSURE_LOCK_CLOSED_INVOICES') && !$user->hasRight('invoiceclosure', 'force')) {
			$langs->loadLangs(array('invoiceclosure@invoiceclosure'));
			$this->errors[] = $langs->trans('InvoiceClosureLockedError', (string) (isset($object->ref) ? $object->ref : $object->id));
			return -1;
		}

		if ($isclosed && $user->hasRight('invoiceclosure', 'force')) {
			dol_syslog(get_class($this)."::runTrigger FORCED operation BILL_DELETE on closed invoice ".((int) $object->id)." (".(isset($object->ref) ? $object->ref : '').") by user ".$user->login." (id ".((int) $user->id).")", LOG_WARNING);
		}

		// Deletion authorized: remove the closure record inside the same transaction
		$closure = new InvoiceClosure($this->db);
		$res = $closure->deleteByInvoice((int) $object->id, $user);
		if ($res < 0) {
			$this->errors[] = $closure->error;
			return -1;
		}
		return 0;
	}

	/**
	 * Block (or log a forced execution of) a standard modification of a
	 * closed and locked invoice.
	 *
	 * @param  string    $action     Trigger action being checked
	 * @param  int       $invoiceId  Invoice id
	 * @param  string    $invoiceRef Invoice reference (or id as string)
	 * @param  User      $user       Acting user
	 * @param  Translate $langs      Translation handler
	 * @return int                   0 if allowed, <0 to abort (caller rolls back)
	 */
	private function enforceLock($action, $invoiceId, $invoiceRef, User $user, Translate $langs)
	{
		if (!getDolGlobalInt('INVOICECLOSURE_LOCK_CLOSED_INVOICES')) {
			return 0;
		}

		$record = $this->getClosureRecord($invoiceId);
		if ($record === null || (int) $record->closure_status !== InvoiceClosure::STATUS_CLOSED) {
			return 0;
		}

		if ($user->hasRight('invoiceclosure', 'force')) {
			// Forced operation: allowed but always traced
			dol_syslog(get_class($this)."::runTrigger FORCED operation ".$action." on closed invoice ".$invoiceId." (".$invoiceRef.") by user ".$user->login." (id ".((int) $user->id).")", LOG_WARNING);
			return 0;
		}

		$langs->loadLangs(array('invoiceclosure@invoiceclosure'));
		$this->errors[] = $langs->trans('InvoiceClosureLockedError', $invoiceRef);
		dol_syslog(get_class($this)."::runTrigger blocked ".$action." on closed invoice ".$invoiceId." for user ".$user->login, LOG_NOTICE);
		return -1;
	}

	/**
	 * Read the closure record of an invoice (invoice rowid is globally unique,
	 * so no entity filter is needed for the lock check).
	 *
	 * @param  int $invoiceId Invoice id
	 * @return stdClass|null  Object with rowid and closure_status, or null
	 */
	private function getClosureRecord($invoiceId)
	{
		$sql = "SELECT ic.rowid, ic.closure_status FROM ".MAIN_DB_PREFIX."invoiceclosure as ic";
		$sql .= " WHERE ic.fk_facture = ".((int) $invoiceId);

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(get_class($this)."::getClosureRecord ".$this->db->lasterror(), LOG_ERR);
			return null;
		}
		$record = null;
		if ($this->db->num_rows($resql) > 0) {
			$record = $this->db->fetch_object($resql);
		}
		$this->db->free($resql);
		return $record;
	}
}

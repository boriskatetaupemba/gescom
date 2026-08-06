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
 * \file    custom/invoiceclosure/class/invoiceclosure.class.php
 * \ingroup invoiceclosure
 * \brief   Business class of the InvoiceClosure module.
 *
 * Single source of truth for the business closure of customer invoices:
 * the user interface (hooks) and the REST API both call close() and reopen()
 * of this class. The standard Dolibarr status of the invoice
 * (llx_facture.fk_statut / paye) is NEVER modified here.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';


/**
 * Class InvoiceClosure
 *
 * Manage the complementary business status "Closed" of a paid customer invoice.
 */
class InvoiceClosure extends CommonObject
{
	/**
	 * @var string ID of module.
	 */
	public $module = 'invoiceclosure';

	/**
	 * @var string ID to identify managed object.
	 */
	public $element = 'invoiceclosure';

	/**
	 * @var string Name of table without prefix where object is stored.
	 */
	public $table_element = 'invoiceclosure';

	/**
	 * @var string String with name of icon.
	 */
	public $picto = 'bill';

	/**
	 * Business status: not closed (or reopened).
	 */
	const STATUS_NOT_CLOSED = 0;

	/**
	 * Business status: closed.
	 */
	const STATUS_CLOSED = 1;

	/**
	 * Maximum length accepted for a note.
	 */
	const NOTE_MAX_LENGTH = 2000;

	/**
	 * @var int Entity of the record
	 */
	public $entity;

	/**
	 * @var int Invoice id (llx_facture.rowid)
	 */
	public $fk_facture;

	/**
	 * @var int Business closure status (0 or 1)
	 */
	public $closure_status;

	/**
	 * @var int|string Timestamp of the last closure ('' if none)
	 */
	public $date_closure = '';

	/**
	 * @var int Id of the user who performed the last closure
	 */
	public $fk_user_closure;

	/**
	 * @var string Login of the user who performed the last closure (filled by fetch*)
	 */
	public $closure_login = '';

	/**
	 * @var string Note of the last closure
	 */
	public $closure_note = '';

	/**
	 * @var int|string Timestamp of the last reopening ('' if none)
	 */
	public $date_reopen = '';

	/**
	 * @var int Id of the user who performed the last reopening
	 */
	public $fk_user_reopen;

	/**
	 * @var string Login of the user who performed the last reopening (filled by fetch*)
	 */
	public $reopen_login = '';

	/**
	 * @var string Note of the last reopening
	 */
	public $reopen_note = '';

	/**
	 * @var int|string Timestamp of record creation
	 */
	public $date_creation = '';

	/**
	 * @var int Id of the user who created the record
	 */
	public $fk_user_creat;

	/**
	 * @var string Import key
	 */
	public $import_key = '';

	/**
	 * Machine readable error code set by the methods of this class.
	 * Possible values: NOT_FOUND, NOT_CUSTOMER_INVOICE, ENTITY_MISMATCH,
	 * PERMISSION_DENIED, ABANDONED, NOT_PAID, REMAIN_NOT_ZERO, ALREADY_CLOSED,
	 * NOT_CLOSED, REOPEN_DISABLED, NOTE_REQUIRED, INVALID_REQUEST_ID, TECHNICAL.
	 *
	 * @var string
	 */
	public $errorCode = '';

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}


	// -------------------------------------------------------------------
	// Read methods
	// -------------------------------------------------------------------

	/**
	 * Load a closure record from its rowid.
	 *
	 * @param  int $id Rowid of the closure record
	 * @return int     1 if found, 0 if not found, <0 if error
	 */
	public function fetch($id)
	{
		return $this->fetchInternal((int) $id, 0);
	}

	/**
	 * Load the closure record of an invoice.
	 *
	 * @param  int      $invoiceId Invoice id (llx_facture.rowid)
	 * @param  int|null $entity    Entity to filter on (null = entities visible for invoices)
	 * @return int                 1 if found, 0 if not found, <0 if error
	 */
	public function fetchByInvoice($invoiceId, $entity = null)
	{
		return $this->fetchInternal(0, (int) $invoiceId, $entity);
	}

	/**
	 * Shared implementation of fetch() and fetchByInvoice().
	 * (Named fetchInternal to not collide with CommonObject::fetchCommon().)
	 *
	 * @param  int      $id        Rowid (0 if fetching by invoice)
	 * @param  int      $invoiceId Invoice id (0 if fetching by rowid)
	 * @param  int|null $entity    Entity filter when fetching by invoice
	 * @return int                 1 if found, 0 if not found, <0 if error
	 */
	private function fetchInternal($id, $invoiceId, $entity = null)
	{
		$sql = "SELECT ic.rowid, ic.entity, ic.fk_facture, ic.closure_status,";
		$sql .= " ic.date_closure, ic.fk_user_closure, ic.closure_note,";
		$sql .= " ic.date_reopen, ic.fk_user_reopen, ic.reopen_note,";
		$sql .= " ic.date_creation, ic.fk_user_creat, ic.tms, ic.import_key,";
		$sql .= " uc.login as closure_login, ur.login as reopen_login";
		$sql .= " FROM ".MAIN_DB_PREFIX."invoiceclosure as ic";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."user as uc ON uc.rowid = ic.fk_user_closure";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."user as ur ON ur.rowid = ic.fk_user_reopen";
		if ($id > 0) {
			$sql .= " WHERE ic.rowid = ".((int) $id);
		} else {
			$sql .= " WHERE ic.fk_facture = ".((int) $invoiceId);
			if ($entity !== null) {
				$sql .= " AND ic.entity = ".((int) $entity);
			} else {
				$sql .= " AND ic.entity IN (".getEntity('invoice').")";
			}
		}

		dol_syslog(get_class($this)."::fetchInternal", LOG_DEBUG);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			$this->errorCode = 'TECHNICAL';
			return -1;
		}

		if ($this->db->num_rows($resql) == 0) {
			$this->db->free($resql);
			return 0;
		}

		$obj = $this->db->fetch_object($resql);
		$this->id = (int) $obj->rowid;
		$this->entity = (int) $obj->entity;
		$this->fk_facture = (int) $obj->fk_facture;
		$this->closure_status = (int) $obj->closure_status;
		$this->date_closure = $this->db->jdate($obj->date_closure);
		$this->fk_user_closure = (int) $obj->fk_user_closure;
		$this->closure_login = (string) $obj->closure_login;
		$this->closure_note = (string) $obj->closure_note;
		$this->date_reopen = $this->db->jdate($obj->date_reopen);
		$this->fk_user_reopen = (int) $obj->fk_user_reopen;
		$this->reopen_login = (string) $obj->reopen_login;
		$this->reopen_note = (string) $obj->reopen_note;
		$this->date_creation = $this->db->jdate($obj->date_creation);
		$this->fk_user_creat = (int) $obj->fk_user_creat;
		$this->import_key = (string) $obj->import_key;
		$this->db->free($resql);

		return 1;
	}

	/**
	 * Tell whether an invoice is currently closed (business status).
	 *
	 * @param  int      $invoiceId Invoice id
	 * @param  int|null $entity    Entity filter (null = entities visible for invoices)
	 * @return bool                True if the invoice is closed
	 */
	public function isClosed($invoiceId, $entity = null)
	{
		return ($this->getClosureStatus($invoiceId, $entity) === self::STATUS_CLOSED);
	}

	/**
	 * Return the business closure status of an invoice.
	 *
	 * @param  int      $invoiceId Invoice id
	 * @param  int|null $entity    Entity filter (null = entities visible for invoices)
	 * @return int                 0 (not closed / reopened / no record), 1 (closed), -1 on error
	 */
	public function getClosureStatus($invoiceId, $entity = null)
	{
		$sql = "SELECT ic.closure_status";
		$sql .= " FROM ".MAIN_DB_PREFIX."invoiceclosure as ic";
		$sql .= " WHERE ic.fk_facture = ".((int) $invoiceId);
		if ($entity !== null) {
			$sql .= " AND ic.entity = ".((int) $entity);
		} else {
			$sql .= " AND ic.entity IN (".getEntity('invoice').")";
		}

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			$this->errorCode = 'TECHNICAL';
			return -1;
		}
		$status = self::STATUS_NOT_CLOSED;
		if ($this->db->num_rows($resql) > 0) {
			$obj = $this->db->fetch_object($resql);
			$status = ((int) $obj->closure_status === self::STATUS_CLOSED) ? self::STATUS_CLOSED : self::STATUS_NOT_CLOSED;
		}
		$this->db->free($resql);

		return $status;
	}

	/**
	 * Return the full closure/reopen history of an invoice, oldest first.
	 *
	 * @param  int      $invoiceId Invoice id
	 * @param  int|null $entity    Entity filter (null = entities visible for invoices)
	 * @return array|int           Array of associative rows, or -1 on error
	 */
	public function getHistory($invoiceId, $entity = null)
	{
		$sql = "SELECT l.rowid, l.entity, l.fk_facture, l.action_code, l.action_date,";
		$sql .= " l.fk_user_action, u.login, u.firstname, u.lastname,";
		$sql .= " l.action_note, l.action_source, l.request_id, l.date_creation";
		$sql .= " FROM ".MAIN_DB_PREFIX."invoiceclosure_log as l";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."user as u ON u.rowid = l.fk_user_action";
		$sql .= " WHERE l.fk_facture = ".((int) $invoiceId);
		if ($entity !== null) {
			$sql .= " AND l.entity = ".((int) $entity);
		} else {
			$sql .= " AND l.entity IN (".getEntity('invoice').")";
		}
		$sql .= " ORDER BY l.action_date ASC, l.rowid ASC";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			$this->errorCode = 'TECHNICAL';
			return -1;
		}

		$history = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$history[] = array(
				'rowid' => (int) $obj->rowid,
				'entity' => (int) $obj->entity,
				'fk_facture' => (int) $obj->fk_facture,
				'action_code' => (string) $obj->action_code,
				'action_date' => $this->db->jdate($obj->action_date),
				'fk_user_action' => (int) $obj->fk_user_action,
				'user_login' => (string) $obj->login,
				'user_fullname' => dolGetFirstLastname((string) $obj->firstname, (string) $obj->lastname),
				'action_note' => (string) $obj->action_note,
				'action_source' => (string) $obj->action_source,
				'request_id' => (string) $obj->request_id,
				'date_creation' => $this->db->jdate($obj->date_creation),
			);
		}
		$this->db->free($resql);

		return $history;
	}


	// -------------------------------------------------------------------
	// Eligibility checks
	// -------------------------------------------------------------------

	/**
	 * Check whether a user is allowed to close an invoice right now.
	 * Sets $this->errorCode and $this->error when the answer is no.
	 *
	 * @param  Facture $invoice Invoice object (must be fetched)
	 * @param  User    $user    Acting user
	 * @return int              1 if the invoice can be closed, 0 otherwise
	 */
	public function canClose(Facture $invoice, User $user)
	{
		if ($this->checkCloseEligibility($invoice, $user) <= 0) {
			return 0;
		}
		$status = $this->getClosureStatus((int) $invoice->id, (int) $invoice->entity);
		if ($status < 0) {
			return 0;
		}
		if ($status === self::STATUS_CLOSED) {
			$this->setErr('ALREADY_CLOSED', 'InvoiceAlreadyClosed');
			return 0;
		}
		return 1;
	}

	/**
	 * Check whether a user is allowed to reopen the closure of an invoice.
	 * Sets $this->errorCode and $this->error when the answer is no.
	 *
	 * @param  Facture $invoice Invoice object (must be fetched)
	 * @param  User    $user    Acting user
	 * @return int              1 if the closure can be reopened, 0 otherwise
	 */
	public function canReopen(Facture $invoice, User $user)
	{
		if ($this->checkReopenEligibility($invoice, $user) <= 0) {
			return 0;
		}
		$status = $this->getClosureStatus((int) $invoice->id, (int) $invoice->entity);
		if ($status < 0) {
			return 0;
		}
		if ($status !== self::STATUS_CLOSED) {
			$this->setErr('NOT_CLOSED', 'InvoiceNotClosedError');
			return 0;
		}
		return 1;
	}

	/**
	 * All closing conditions except the "not already closed" one
	 * (the latter is re-checked under lock inside close()).
	 *
	 * @param  Facture $invoice Invoice object
	 * @param  User    $user    Acting user
	 * @return int              1 if eligible, 0 otherwise
	 */
	private function checkCloseEligibility(Facture $invoice, User $user)
	{
		if (empty($invoice->id) || $invoice->id <= 0) {
			$this->setErr('NOT_FOUND', 'InvoiceClosureErrInvoiceNotFound');
			return 0;
		}
		if ($invoice->element !== 'facture') {
			$this->setErr('NOT_CUSTOMER_INVOICE', 'InvoiceClosureErrNotCustomerInvoice');
			return 0;
		}
		if ($this->checkEntityAndAccess($invoice, $user) <= 0) {
			return 0;
		}
		if (!$user->hasRight('invoiceclosure', 'close')) {
			$this->setErr('PERMISSION_DENIED', 'NoPermissionToCloseInvoice');
			return 0;
		}
		if ((int) $invoice->statut === Facture::STATUS_ABANDONED) {
			$this->setErr('ABANDONED', 'InvoiceClosureErrAbandoned');
			return 0;
		}
		if ((int) $invoice->statut !== Facture::STATUS_CLOSED) {
			// Standard status "Paid" corresponds to Facture::STATUS_CLOSED (fk_statut = 2)
			$this->setErr('NOT_PAID', 'InvoiceMustBePaidBeforeClosure');
			return 0;
		}
		if (getDolGlobalInt('INVOICECLOSURE_REQUIRE_ZERO_REMAIN')) {
			if (empty($invoice->paye)) {
				// Classified paid with a close code (discount, bad debt...) but not fully paid
				$this->setErr('NOT_PAID', 'InvoiceMustBeFullyPaidBeforeClosure');
				return 0;
			}
			// getRemainToPay() already returns a value rounded with price2num(..., 'MT'),
			// so the comparison below is done on an exact rounded decimal, never on raw floats.
			$remain = (float) price2num($invoice->getRemainToPay(0), 'MT');
			if ($remain != 0.0) {
				$this->setErr('REMAIN_NOT_ZERO', 'InvoiceRemainToPayNotZero');
				return 0;
			}
		}
		return 1;
	}

	/**
	 * All reopening conditions except the "currently closed" one
	 * (the latter is re-checked under lock inside reopen()).
	 *
	 * @param  Facture $invoice Invoice object
	 * @param  User    $user    Acting user
	 * @return int              1 if eligible, 0 otherwise
	 */
	private function checkReopenEligibility(Facture $invoice, User $user)
	{
		if (!getDolGlobalInt('INVOICECLOSURE_ALLOW_REOPEN')) {
			$this->setErr('REOPEN_DISABLED', 'InvoiceClosureReopenDisabled');
			return 0;
		}
		if (empty($invoice->id) || $invoice->id <= 0) {
			$this->setErr('NOT_FOUND', 'InvoiceClosureErrInvoiceNotFound');
			return 0;
		}
		if ($invoice->element !== 'facture') {
			$this->setErr('NOT_CUSTOMER_INVOICE', 'InvoiceClosureErrNotCustomerInvoice');
			return 0;
		}
		if ($this->checkEntityAndAccess($invoice, $user) <= 0) {
			return 0;
		}
		if (!$user->hasRight('invoiceclosure', 'reopen')) {
			$this->setErr('PERMISSION_DENIED', 'NoPermissionToReopenInvoice');
			return 0;
		}
		return 1;
	}

	/**
	 * Entity and invoice-access checks shared by close and reopen paths.
	 *
	 * @param  Facture $invoice Invoice object
	 * @param  User    $user    Acting user
	 * @return int              1 if OK, 0 otherwise
	 */
	private function checkEntityAndAccess(Facture $invoice, User $user)
	{
		$allowedEntities = array_map('intval', explode(',', getEntity('invoice')));
		if (!in_array((int) $invoice->entity, $allowedEntities, true)) {
			$this->setErr('ENTITY_MISMATCH', 'InvoiceClosureErrEntityMismatch');
			return 0;
		}
		if (!$user->hasRight('facture', 'lire')) {
			$this->setErr('PERMISSION_DENIED', 'InvoiceClosureErrNoInvoiceReadRight');
			return 0;
		}
		if (!empty($user->socid) && (int) $user->socid !== (int) $invoice->socid) {
			// External user restricted to his own third party
			$this->setErr('PERMISSION_DENIED', 'InvoiceClosureErrNoInvoiceReadRight');
			return 0;
		}
		return 1;
	}


	// -------------------------------------------------------------------
	// Write methods
	// -------------------------------------------------------------------

	/**
	 * Close an invoice (business status). The standard Dolibarr status of the
	 * invoice ("Paid") is left strictly unchanged.
	 *
	 * @param  Facture $invoice   Invoice object (must be fetched)
	 * @param  User    $user      Acting user
	 * @param  string  $note      Optional closure note (mandatory if INVOICECLOSURE_REQUIRE_CLOSE_NOTE)
	 * @param  string  $source    Source of the action: 'UI', 'API', 'IMPORT' or 'OTHER'
	 * @param  string  $requestId Optional idempotency key ([A-Za-z0-9._-], max 64 chars)
	 * @return int                1 closed now, 2 already satisfied (idempotent), <0 on error ($this->errorCode set)
	 */
	public function close(Facture $invoice, User $user, $note = '', $source = 'UI', $requestId = '')
	{
		global $langs;

		$this->clearErr();

		$note = $this->sanitizeNote($note);
		$source = $this->sanitizeSource($source);
		if ($requestId !== '' && !$this->isValidRequestId($requestId)) {
			$this->setErr('INVALID_REQUEST_ID', 'InvoiceClosureErrInvalidRequestId');
			return -1;
		}
		if (getDolGlobalInt('INVOICECLOSURE_REQUIRE_CLOSE_NOTE') && $note === '') {
			$this->setErr('NOTE_REQUIRED', 'InvoiceClosureCloseNoteRequired');
			return -1;
		}

		if ($this->checkCloseEligibility($invoice, $user) <= 0) {
			return -1;
		}

		$now = dol_now();
		$error = 0;

		dol_syslog(get_class($this)."::close invoice=".((int) $invoice->id)." user=".((int) $user->id)." source=".$source, LOG_DEBUG);

		$this->db->begin();

		// Idempotency: a CLOSE request already consumed with the same request_id
		// is never executed twice (documented behaviour: HTTP 200 / return 2).
		if ($requestId !== '') {
			$seen = $this->existsLog((int) $invoice->id, (int) $invoice->entity, 'CLOSE', $requestId);
			if ($seen < 0) {
				$this->db->rollback();
				$this->setErr('TECHNICAL', 'InvoiceClosureErrTechnical');
				return -1;
			}
			if ($seen > 0) {
				$this->db->commit(); // nothing was changed
				$this->fetchByInvoice((int) $invoice->id, (int) $invoice->entity);
				return 2;
			}
		}

		// Lock the current record (if any) to serialize concurrent closures
		$sql = "SELECT rowid, closure_status FROM ".MAIN_DB_PREFIX."invoiceclosure";
		$sql .= " WHERE fk_facture = ".((int) $invoice->id);
		$sql .= " AND entity = ".((int) $invoice->entity);
		$sql .= " FOR UPDATE";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->db->rollback();
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			$this->errorCode = 'TECHNICAL';
			return -1;
		}
		$existing = ($this->db->num_rows($resql) > 0) ? $this->db->fetch_object($resql) : null;
		$this->db->free($resql);

		if ($existing !== null && (int) $existing->closure_status === self::STATUS_CLOSED) {
			// Already closed: idempotent success, nothing modified, no new history line
			$this->db->commit();
			$this->fetchByInvoice((int) $invoice->id, (int) $invoice->entity);
			return 2;
		}

		if ($existing !== null) {
			$sql = "UPDATE ".MAIN_DB_PREFIX."invoiceclosure SET";
			$sql .= " closure_status = ".self::STATUS_CLOSED.",";
			$sql .= " date_closure = '".$this->db->idate($now)."',";
			$sql .= " fk_user_closure = ".((int) $user->id).",";
			$sql .= " closure_note = ".($note !== '' ? "'".$this->db->escape($note)."'" : "null");
			$sql .= " WHERE rowid = ".((int) $existing->rowid);
			if (!$this->db->query($sql)) {
				$error++;
			}
		} else {
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."invoiceclosure";
			$sql .= " (entity, fk_facture, closure_status, date_closure, fk_user_closure, closure_note, date_creation, fk_user_creat)";
			$sql .= " VALUES (";
			$sql .= ((int) $invoice->entity).",";
			$sql .= ((int) $invoice->id).",";
			$sql .= self::STATUS_CLOSED.",";
			$sql .= " '".$this->db->idate($now)."',";
			$sql .= ((int) $user->id).",";
			$sql .= ($note !== '' ? " '".$this->db->escape($note)."'" : " null").",";
			$sql .= " '".$this->db->idate($now)."',";
			$sql .= ((int) $user->id);
			$sql .= ")";
			if (!$this->db->query($sql)) {
				$error++;
			}
		}

		if (!$error) {
			$res = $this->addLog((int) $invoice->id, (int) $invoice->entity, 'CLOSE', $now, $user, $note, $source, $requestId);
			if ($res < 0) {
				$error++;
			}
		}

		if (!$error) {
			$res = $this->fetchByInvoice((int) $invoice->id, (int) $invoice->entity);
			if ($res <= 0) {
				$error++;
			}
		}

		if (!$error) {
			// Business event of the module, fired inside the transaction
			$this->context = array(
				'invoiceid' => (int) $invoice->id,
				'invoiceref' => (string) $invoice->ref,
				'entity' => (int) $invoice->entity,
				'oldbusinessstatus' => self::STATUS_NOT_CLOSED,
				'newbusinessstatus' => self::STATUS_CLOSED,
				'actiondate' => $now,
				'note' => $note,
				'source' => $source,
				'request_id' => $requestId,
			);
			$res = $this->call_trigger('INVOICECLOSURE_CLOSE', $user);
			if ($res < 0) {
				$error++;
			}
		}

		if (!$error) {
			$res = $this->createAgendaEvent($invoice, $user, 'CLOSE', $note, $now);
			if ($res < 0) {
				$error++;
			}
		}

		if ($error) {
			$this->db->rollback();
			if (empty($this->errorCode)) {
				$this->setErr('TECHNICAL', 'InvoiceClosureErrTechnical');
			}
			if (empty($this->error)) {
				$this->error = $this->db->lasterror();
				$this->errors[] = $this->error;
			}
			dol_syslog(get_class($this)."::close error invoice=".((int) $invoice->id)." ".$this->error, LOG_ERR);
			return -1;
		}

		$this->db->commit();
		dol_syslog(get_class($this)."::close done invoice=".((int) $invoice->id), LOG_INFO);
		return 1;
	}

	/**
	 * Reopen the business closure of an invoice. The invoice stays "Paid" for
	 * Dolibarr: this only switches the business status from Closed back to Paid.
	 *
	 * @param  Facture $invoice   Invoice object (must be fetched)
	 * @param  User    $user      Acting user
	 * @param  string  $note      Optional reopen note (mandatory if INVOICECLOSURE_REQUIRE_REOPEN_NOTE)
	 * @param  string  $source    Source of the action: 'UI', 'API', 'IMPORT' or 'OTHER'
	 * @param  string  $requestId Optional idempotency key ([A-Za-z0-9._-], max 64 chars)
	 * @return int                1 reopened now, 2 already satisfied (idempotent), <0 on error ($this->errorCode set)
	 */
	public function reopen(Facture $invoice, User $user, $note = '', $source = 'UI', $requestId = '')
	{
		global $langs;

		$this->clearErr();

		$note = $this->sanitizeNote($note);
		$source = $this->sanitizeSource($source);
		if ($requestId !== '' && !$this->isValidRequestId($requestId)) {
			$this->setErr('INVALID_REQUEST_ID', 'InvoiceClosureErrInvalidRequestId');
			return -1;
		}
		if (getDolGlobalInt('INVOICECLOSURE_REQUIRE_REOPEN_NOTE') && $note === '') {
			$this->setErr('NOTE_REQUIRED', 'InvoiceClosureReopenNoteRequired');
			return -1;
		}

		if ($this->checkReopenEligibility($invoice, $user) <= 0) {
			return -1;
		}

		$now = dol_now();
		$error = 0;

		dol_syslog(get_class($this)."::reopen invoice=".((int) $invoice->id)." user=".((int) $user->id)." source=".$source, LOG_DEBUG);

		$this->db->begin();

		// Idempotency on request_id
		if ($requestId !== '') {
			$seen = $this->existsLog((int) $invoice->id, (int) $invoice->entity, 'REOPEN', $requestId);
			if ($seen < 0) {
				$this->db->rollback();
				$this->setErr('TECHNICAL', 'InvoiceClosureErrTechnical');
				return -1;
			}
			if ($seen > 0) {
				$this->db->commit(); // nothing was changed
				$this->fetchByInvoice((int) $invoice->id, (int) $invoice->entity);
				return 2;
			}
		}

		// Lock the record and verify the invoice is really closed right now
		$sql = "SELECT rowid, closure_status FROM ".MAIN_DB_PREFIX."invoiceclosure";
		$sql .= " WHERE fk_facture = ".((int) $invoice->id);
		$sql .= " AND entity = ".((int) $invoice->entity);
		$sql .= " FOR UPDATE";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->db->rollback();
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			$this->errorCode = 'TECHNICAL';
			return -1;
		}
		$existing = ($this->db->num_rows($resql) > 0) ? $this->db->fetch_object($resql) : null;
		$this->db->free($resql);

		if ($existing === null || (int) $existing->closure_status !== self::STATUS_CLOSED) {
			$this->db->rollback();
			$this->setErr('NOT_CLOSED', 'InvoiceNotClosedError');
			return -1;
		}

		$sql = "UPDATE ".MAIN_DB_PREFIX."invoiceclosure SET";
		$sql .= " closure_status = ".self::STATUS_NOT_CLOSED.",";
		$sql .= " date_reopen = '".$this->db->idate($now)."',";
		$sql .= " fk_user_reopen = ".((int) $user->id).",";
		$sql .= " reopen_note = ".($note !== '' ? "'".$this->db->escape($note)."'" : "null");
		$sql .= " WHERE rowid = ".((int) $existing->rowid);
		if (!$this->db->query($sql)) {
			$error++;
		}

		if (!$error) {
			$res = $this->addLog((int) $invoice->id, (int) $invoice->entity, 'REOPEN', $now, $user, $note, $source, $requestId);
			if ($res < 0) {
				$error++;
			}
		}

		if (!$error) {
			$res = $this->fetchByInvoice((int) $invoice->id, (int) $invoice->entity);
			if ($res <= 0) {
				$error++;
			}
		}

		if (!$error) {
			$this->context = array(
				'invoiceid' => (int) $invoice->id,
				'invoiceref' => (string) $invoice->ref,
				'entity' => (int) $invoice->entity,
				'oldbusinessstatus' => self::STATUS_CLOSED,
				'newbusinessstatus' => self::STATUS_NOT_CLOSED,
				'actiondate' => $now,
				'note' => $note,
				'source' => $source,
				'request_id' => $requestId,
			);
			$res = $this->call_trigger('INVOICECLOSURE_REOPEN', $user);
			if ($res < 0) {
				$error++;
			}
		}

		if (!$error) {
			$res = $this->createAgendaEvent($invoice, $user, 'REOPEN', $note, $now);
			if ($res < 0) {
				$error++;
			}
		}

		if ($error) {
			$this->db->rollback();
			if (empty($this->errorCode)) {
				$this->setErr('TECHNICAL', 'InvoiceClosureErrTechnical');
			}
			if (empty($this->error)) {
				$this->error = $this->db->lasterror();
				$this->errors[] = $this->error;
			}
			dol_syslog(get_class($this)."::reopen error invoice=".((int) $invoice->id)." ".$this->error, LOG_ERR);
			return -1;
		}

		$this->db->commit();
		dol_syslog(get_class($this)."::reopen done invoice=".((int) $invoice->id), LOG_INFO);
		return 1;
	}

	/**
	 * Delete the closure record of an invoice (used when a deletion of the
	 * invoice itself has been authorized). The history table is intentionally
	 * NOT purged: the audit trail is preserved.
	 *
	 * @param  int  $invoiceId Invoice id
	 * @param  User $user      Acting user (for logging purpose)
	 * @return int             1 if OK (or nothing to delete), -1 on error
	 */
	public function deleteByInvoice($invoiceId, User $user)
	{
		$sql = "DELETE FROM ".MAIN_DB_PREFIX."invoiceclosure";
		$sql .= " WHERE fk_facture = ".((int) $invoiceId);

		dol_syslog(get_class($this)."::deleteByInvoice invoice=".((int) $invoiceId)." by user ".((int) $user->id), LOG_INFO);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			$this->errorCode = 'TECHNICAL';
			return -1;
		}
		return 1;
	}


	// -------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------

	/**
	 * Insert one history line.
	 *
	 * @param  int    $invoiceId  Invoice id
	 * @param  int    $entity     Entity of the invoice
	 * @param  string $actionCode 'CLOSE' or 'REOPEN'
	 * @param  int    $actionDate Timestamp of the action
	 * @param  User   $user       Acting user
	 * @param  string $note       Sanitized note
	 * @param  string $source     'UI', 'API', 'IMPORT' or 'OTHER'
	 * @param  string $requestId  Idempotency key ('' if none)
	 * @return int                1 if OK, -1 on error
	 */
	private function addLog($invoiceId, $entity, $actionCode, $actionDate, User $user, $note, $source, $requestId)
	{
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."invoiceclosure_log";
		$sql .= " (entity, fk_facture, action_code, action_date, fk_user_action, action_note, action_source, request_id, date_creation)";
		$sql .= " VALUES (";
		$sql .= ((int) $entity).",";
		$sql .= ((int) $invoiceId).",";
		$sql .= " '".$this->db->escape($actionCode)."',";
		$sql .= " '".$this->db->idate($actionDate)."',";
		$sql .= ((int) $user->id).",";
		$sql .= ($note !== '' ? " '".$this->db->escape($note)."'" : " null").",";
		$sql .= " '".$this->db->escape($source)."',";
		$sql .= ($requestId !== '' ? " '".$this->db->escape($requestId)."'" : " null").",";
		$sql .= " '".$this->db->idate(dol_now())."'";
		$sql .= ")";

		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			$this->errorCode = 'TECHNICAL';
			return -1;
		}
		return 1;
	}

	/**
	 * Tell whether a history line already exists for a request id.
	 *
	 * @param  int    $invoiceId  Invoice id
	 * @param  int    $entity     Entity
	 * @param  string $actionCode 'CLOSE' or 'REOPEN'
	 * @param  string $requestId  Idempotency key
	 * @return int                1 found, 0 not found, -1 on error
	 */
	private function existsLog($invoiceId, $entity, $actionCode, $requestId)
	{
		$sql = "SELECT l.rowid FROM ".MAIN_DB_PREFIX."invoiceclosure_log as l";
		$sql .= " WHERE l.entity = ".((int) $entity);
		$sql .= " AND l.action_code = '".$this->db->escape($actionCode)."'";
		$sql .= " AND l.request_id = '".$this->db->escape($requestId)."'";
		$sql .= " AND l.fk_facture = ".((int) $invoiceId);

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			return -1;
		}
		$found = ($this->db->num_rows($resql) > 0) ? 1 : 0;
		$this->db->free($resql);
		return $found;
	}

	/**
	 * Create the automatic agenda event if the option is enabled.
	 *
	 * @param  Facture $invoice    Invoice
	 * @param  User    $user       Acting user
	 * @param  string  $actionCode 'CLOSE' or 'REOPEN'
	 * @param  string  $note       Sanitized note
	 * @param  int     $now        Timestamp of the action
	 * @return int                 1 if OK or nothing to do, -1 on error
	 */
	private function createAgendaEvent(Facture $invoice, User $user, $actionCode, $note, $now)
	{
		global $langs;

		if (!getDolGlobalInt('INVOICECLOSURE_CREATE_AGENDA_EVENT') || !isModEnabled('agenda')) {
			return 1;
		}

		$langs->loadLangs(array('invoiceclosure@invoiceclosure'));

		require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';

		$labelKey = ($actionCode === 'CLOSE') ? 'InvoiceClosureAgendaClose' : 'InvoiceClosureAgendaReopen';

		$actioncomm = new ActionComm($this->db);
		$actioncomm->type_code = 'AC_OTH_AUTO'; // Event inserted into agenda automatically
		$actioncomm->code = 'AC_INVOICECLOSURE_'.$actionCode;
		$actioncomm->label = $langs->transnoentities($labelKey, $invoice->ref);
		$actioncomm->note_private = $note;
		$actioncomm->datep = $now;
		$actioncomm->datef = $now;
		$actioncomm->percentage = -1; // Not applicable
		$actioncomm->socid = (int) $invoice->socid;
		$actioncomm->authorid = (int) $user->id;
		$actioncomm->userownerid = (int) $user->id;
		$actioncomm->elementtype = 'invoice';
		$actioncomm->fk_element = (int) $invoice->id;

		$result = $actioncomm->create($user);
		if ($result < 0) {
			$this->error = $actioncomm->error;
			$this->errors = array_merge($this->errors, $actioncomm->errors);
			$this->errorCode = 'TECHNICAL';
			return -1;
		}
		return 1;
	}

	/**
	 * Sanitize a user supplied note: strip HTML tags (line feeds kept),
	 * trim and truncate to NOTE_MAX_LENGTH.
	 *
	 * @param  string $note Raw note
	 * @return string       Clean note
	 */
	private function sanitizeNote($note)
	{
		$note = (string) $note;
		$note = dol_string_nohtmltag($note, 0);
		$note = trim($note);
		if (dol_strlen($note) > self::NOTE_MAX_LENGTH) {
			$note = dol_substr($note, 0, self::NOTE_MAX_LENGTH);
		}
		return $note;
	}

	/**
	 * Normalize the action source.
	 *
	 * @param  string $source Raw source
	 * @return string         One of 'UI', 'API', 'IMPORT', 'OTHER'
	 */
	private function sanitizeSource($source)
	{
		$source = strtoupper((string) $source);
		return in_array($source, array('UI', 'API', 'IMPORT', 'OTHER'), true) ? $source : 'OTHER';
	}

	/**
	 * Validate the format of an idempotency key.
	 *
	 * @param  string $requestId Raw request id
	 * @return bool              True if valid
	 */
	private function isValidRequestId($requestId)
	{
		return (bool) preg_match('/^[A-Za-z0-9._\-]{1,64}$/', $requestId);
	}

	/**
	 * Set the error code and the translated error message.
	 *
	 * @param  string $code     Machine readable error code
	 * @param  string $langKey  Translation key of the human readable message
	 * @return void
	 */
	private function setErr($code, $langKey)
	{
		global $langs;
		$langs->loadLangs(array('invoiceclosure@invoiceclosure'));
		$this->errorCode = $code;
		$this->error = $langs->trans($langKey);
		$this->errors[] = $this->error;
	}

	/**
	 * Reset error state before an operation.
	 *
	 * @return void
	 */
	private function clearErr()
	{
		$this->error = '';
		$this->errors = array();
		$this->errorCode = '';
	}
}

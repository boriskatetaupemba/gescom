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

use Luracast\Restler\RestException;

require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
dol_include_once('/invoiceclosure/class/invoiceclosure.class.php');

/**
 * \file    custom/invoiceclosure/class/api_invoiceclosure.class.php
 * \ingroup invoiceclosure
 * \brief   REST API of the InvoiceClosure module.
 *
 * Endpoint base: /api/index.php/invoiceclosureapi
 *
 * Note on the naming (verified in the Dolibarr 20.0.4 sources, api/index.php +
 * getModuleDirForApiClass()): for a custom module living in the directory
 * "invoiceclosure", the direct routing only works when the endpoint name maps
 * back to that directory after stripping a trailing "api". The class is
 * therefore named "InvoiceclosureApi" and published under /invoiceclosureapi.
 * A plural endpoint "/invoiceclosures" cannot be routed in this version
 * without patching the core, which is forbidden.
 *
 * @access protected
 * @class  DolibarrApiAccess {@requires user,external}
 */
class InvoiceclosureApi extends DolibarrApi
{
	/**
	 * Constructor
	 */
	public function __construct()
	{
		global $db, $langs;
		$this->db = $db;
		$langs->loadLangs(array('bills', 'invoiceclosure@invoiceclosure'));
	}

	/**
	 * Get the closure status of an invoice
	 *
	 * Return the standard Dolibarr status and the business closure status of a customer invoice.
	 *
	 * @param  int   $id  Id of the invoice
	 * @return array      Closure status of the invoice
	 *
	 * @url GET /invoices/{id}
	 *
	 * @throws RestException 403 Insufficient rights
	 * @throws RestException 404 Invoice not found
	 */
	public function getClosureStatus($id)
	{
		if (!DolibarrApiAccess::$user->hasRight('facture', 'lire')) {
			throw new RestException(403, "Insufficient rights to read invoices.");
		}
		if (!DolibarrApiAccess::$user->hasRight('invoiceclosure', 'read')) {
			throw new RestException(403, "Insufficient rights to read invoice closure information.");
		}

		$invoice = $this->loadInvoice($id);

		$closure = new InvoiceClosure($this->db);
		$res = $closure->fetchByInvoice((int) $invoice->id, (int) $invoice->entity);
		if ($res < 0) {
			throw new RestException(500, $closure->error);
		}

		return $this->buildStatusResponse($invoice, ($res > 0 ? $closure : null));
	}

	/**
	 * Get the closure history of an invoice
	 *
	 * Return every closure (CLOSE) and reopening (REOPEN) recorded for the invoice, oldest first.
	 *
	 * @param  int   $id  Id of the invoice
	 * @return array      History lines
	 *
	 * @url GET /invoices/{id}/history
	 *
	 * @throws RestException 403 Insufficient rights
	 * @throws RestException 404 Invoice not found
	 */
	public function getClosureHistory($id)
	{
		if (!DolibarrApiAccess::$user->hasRight('facture', 'lire')) {
			throw new RestException(403, "Insufficient rights to read invoices.");
		}
		if (!DolibarrApiAccess::$user->hasRight('invoiceclosure', 'readhistory')) {
			throw new RestException(403, "Insufficient rights to read the closure history.");
		}

		$invoice = $this->loadInvoice($id);

		$closure = new InvoiceClosure($this->db);
		$history = $closure->getHistory((int) $invoice->id, (int) $invoice->entity);
		if (!is_array($history)) {
			throw new RestException(500, $closure->error);
		}

		$out = array();
		foreach ($history as $line) {
			$out[] = array(
				'id' => $line['rowid'],
				'invoice_id' => $line['fk_facture'],
				'action_code' => $line['action_code'],
				'action_date' => $line['action_date'],
				'action_date_iso' => dol_print_date($line['action_date'], 'dayhourrfc'),
				'user' => array(
					'id' => $line['fk_user_action'],
					'login' => $line['user_login'],
				),
				'note' => $line['action_note'],
				'source' => $line['action_source'],
				'request_id' => $line['request_id'],
			);
		}

		return array(
			'invoice_id' => (int) $invoice->id,
			'invoice_ref' => (string) $invoice->ref,
			'history' => $out,
		);
	}

	/**
	 * Close an invoice (business closure)
	 *
	 * Mark a paid customer invoice as "Closed" (business status). The standard
	 * Dolibarr status of the invoice remains "Paid". The call is idempotent:
	 * closing an already closed invoice returns HTTP 200 with already_closed=true,
	 * and a request_id already consumed is never executed twice.
	 *
	 * Accepted body fields: note (string, optional unless configured mandatory),
	 * request_id (string, optional idempotency key). Any server-managed field
	 * (entity, closure_status, date_closure, fk_user_closure) is rejected.
	 *
	 * @param  int   $id            Id of the invoice
	 * @param  array $request_data  Request data
	 * @return array                Result of the closure
	 *
	 * @url POST /invoices/{id}/close
	 *
	 * @throws RestException 400 Invalid parameters
	 * @throws RestException 403 Insufficient rights
	 * @throws RestException 404 Invoice not found
	 * @throws RestException 409 Invoice not eligible for closure
	 * @throws RestException 500 Internal error
	 */
	public function closeInvoice($id, $request_data = null)
	{
		if (!DolibarrApiAccess::$user->hasRight('facture', 'lire')) {
			throw new RestException(403, "Insufficient rights to read invoices.");
		}
		if (!DolibarrApiAccess::$user->hasRight('invoiceclosure', 'close')) {
			throw new RestException(403, $this->transErr('NoPermissionToCloseInvoice'));
		}

		$invoice = $this->loadInvoice($id);

		list($note, $requestId) = $this->parseBody($request_data);

		$closure = new InvoiceClosure($this->db);
		$result = $closure->close($invoice, DolibarrApiAccess::$user, $note, 'API', $requestId);
		if ($result < 0) {
			throw $this->mapBusinessError($closure);
		}

		$response = $this->buildStatusResponse($invoice, $closure);
		$response['success'] = true;
		$response['already_closed'] = ($result == 2);
		$response['note'] = $closure->closure_note;

		return $response;
	}

	/**
	 * Reopen the closure of an invoice
	 *
	 * Switch the business status from "Closed" back to "Paid". The standard
	 * Dolibarr status of the invoice is not modified: the invoice does not
	 * become draft nor unpaid. Idempotent through the request_id body field.
	 *
	 * @param  int   $id            Id of the invoice
	 * @param  array $request_data  Request data (note, request_id)
	 * @return array                Result of the reopening
	 *
	 * @url POST /invoices/{id}/reopen
	 *
	 * @throws RestException 400 Invalid parameters
	 * @throws RestException 403 Insufficient rights
	 * @throws RestException 404 Invoice not found
	 * @throws RestException 409 Invoice not currently closed
	 * @throws RestException 500 Internal error
	 */
	public function reopenInvoice($id, $request_data = null)
	{
		if (!DolibarrApiAccess::$user->hasRight('facture', 'lire')) {
			throw new RestException(403, "Insufficient rights to read invoices.");
		}
		if (!DolibarrApiAccess::$user->hasRight('invoiceclosure', 'reopen')) {
			throw new RestException(403, $this->transErr('NoPermissionToReopenInvoice'));
		}

		$invoice = $this->loadInvoice($id);

		list($note, $requestId) = $this->parseBody($request_data);

		$closure = new InvoiceClosure($this->db);
		$result = $closure->reopen($invoice, DolibarrApiAccess::$user, $note, 'API', $requestId);
		if ($result < 0) {
			throw $this->mapBusinessError($closure);
		}

		return array(
			'success' => true,
			'invoice_id' => (int) $invoice->id,
			'invoice_ref' => (string) $invoice->ref,
			'dolibarr_status' => (int) $invoice->statut,
			'dolibarr_status_code' => $this->getDolibarrStatusCode($invoice),
			'dolibarr_status_label' => $invoice->getLibStatut(0, -1),
			'business_status' => (int) $closure->closure_status,
			'business_status_code' => 'reopened',
			'business_status_label' => $invoice->getLibStatut(0, -1),
			'reopened_at' => (int) $closure->date_reopen,
			'reopened_at_iso' => dol_print_date($closure->date_reopen, 'dayhourrfc'),
			'reopened_by' => array(
				'id' => (int) $closure->fk_user_reopen,
				'login' => $closure->reopen_login,
			),
			'note' => $closure->reopen_note,
			'already_reopened' => ($result == 2),
		);
	}

	/**
	 * List invoice closure records
	 *
	 * Return the closure records of the entities visible to the API user.
	 * External users only see invoices of their own third party.
	 *
	 * @param  string $status         Filter on business status: '1' closed, '0' reopened records, '' all
	 * @param  int    $thirdparty_id  Filter on the third party of the invoice
	 * @param  int    $user_id        Filter on the user who performed the closure
	 * @param  string $date_start     Filter: closures on or after this date (YYYY-MM-DD)
	 * @param  string $date_end       Filter: closures on or before this date (YYYY-MM-DD)
	 * @param  string $sortfield      Sort field (default ic.date_closure)
	 * @param  string $sortorder      Sort order ASC or DESC (default DESC)
	 * @param  int    $limit          Limit for list (default 100, 0 = no limit)
	 * @param  int    $page           Page number (default 0)
	 * @param  string $sqlfilters     Other criteria with Universal Search syntax, example "(ic.closure_status:=:1)"
	 * @return array                  Array of closure records
	 *
	 * @throws RestException 400 Bad parameter value
	 * @throws RestException 403 Insufficient rights
	 * @throws RestException 503 Database error
	 */
	public function index($status = '', $thirdparty_id = 0, $user_id = 0, $date_start = '', $date_end = '', $sortfield = 'ic.date_closure', $sortorder = 'DESC', $limit = 100, $page = 0, $sqlfilters = '')
	{
		if (!DolibarrApiAccess::$user->hasRight('facture', 'lire')) {
			throw new RestException(403, "Insufficient rights to read invoices.");
		}
		if (!DolibarrApiAccess::$user->hasRight('invoiceclosure', 'read')) {
			throw new RestException(403, "Insufficient rights to read invoice closure information.");
		}

		if ($status !== '' && $status !== '0' && $status !== '1') {
			throw new RestException(400, "Bad value for parameter status: must be '', '0' or '1'.");
		}

		$sql = "SELECT ic.rowid, ic.entity, ic.fk_facture, ic.closure_status,";
		$sql .= " ic.date_closure, ic.fk_user_closure, ic.closure_note,";
		$sql .= " ic.date_reopen, ic.fk_user_reopen, ic.reopen_note,";
		$sql .= " f.ref as invoice_ref, f.fk_soc as thirdparty_id,";
		$sql .= " uc.login as closure_login, ur.login as reopen_login";
		$sql .= " FROM ".MAIN_DB_PREFIX."invoiceclosure as ic";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."facture as f ON f.rowid = ic.fk_facture";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."user as uc ON uc.rowid = ic.fk_user_closure";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."user as ur ON ur.rowid = ic.fk_user_reopen";
		$sql .= " WHERE ic.entity IN (".getEntity('invoice').")";

		if ($status !== '') {
			$sql .= " AND ic.closure_status = ".((int) $status);
		}
		if ((int) $thirdparty_id > 0) {
			$sql .= " AND f.fk_soc = ".((int) $thirdparty_id);
		}
		if ((int) $user_id > 0) {
			$sql .= " AND ic.fk_user_closure = ".((int) $user_id);
		}
		if ($date_start !== '') {
			$ts = dol_stringtotime($date_start.' 00:00:00');
			if (empty($ts)) {
				throw new RestException(400, "Bad value for parameter date_start: use YYYY-MM-DD.");
			}
			$sql .= " AND ic.date_closure >= '".$this->db->idate($ts)."'";
		}
		if ($date_end !== '') {
			$ts = dol_stringtotime($date_end.' 23:59:59');
			if (empty($ts)) {
				throw new RestException(400, "Bad value for parameter date_end: use YYYY-MM-DD.");
			}
			$sql .= " AND ic.date_closure <= '".$this->db->idate($ts)."'";
		}
		// External users only see their own invoices
		if (!empty(DolibarrApiAccess::$user->socid)) {
			$sql .= " AND f.fk_soc = ".((int) DolibarrApiAccess::$user->socid);
		}
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
			throw new RestException(503, 'Error when retrieving closure list: '.$this->db->lasterror());
		}

		$obj_ret = array();
		$num = $this->db->num_rows($result);
		$min = ($limit ? min($num, $limit) : $num);
		$i = 0;
		while ($i < $min) {
			$obj = $this->db->fetch_object($result);
			$dateClosure = $this->db->jdate($obj->date_closure);
			$dateReopen = $this->db->jdate($obj->date_reopen);
			$obj_ret[] = array(
				'id' => (int) $obj->rowid,
				'entity' => (int) $obj->entity,
				'invoice_id' => (int) $obj->fk_facture,
				'invoice_ref' => (string) $obj->invoice_ref,
				'thirdparty_id' => (int) $obj->thirdparty_id,
				'business_status' => (int) $obj->closure_status,
				'business_status_code' => ((int) $obj->closure_status === InvoiceClosure::STATUS_CLOSED) ? 'closed' : 'reopened',
				'closed_at' => $dateClosure ? (int) $dateClosure : null,
				'closed_at_iso' => $dateClosure ? dol_print_date($dateClosure, 'dayhourrfc') : null,
				'closed_by' => array(
					'id' => (int) $obj->fk_user_closure,
					'login' => (string) $obj->closure_login,
				),
				'closure_note' => (string) $obj->closure_note,
				'reopened_at' => $dateReopen ? (int) $dateReopen : null,
				'reopened_at_iso' => $dateReopen ? dol_print_date($dateReopen, 'dayhourrfc') : null,
				'reopened_by' => ((int) $obj->fk_user_reopen > 0) ? array(
					'id' => (int) $obj->fk_user_reopen,
					'login' => (string) $obj->reopen_login,
				) : null,
				'reopen_note' => (string) $obj->reopen_note,
			);
			$i++;
		}
		$this->db->free($result);

		return $obj_ret;
	}


	// -------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------

	/**
	 * Load a customer invoice and check the API user can access it.
	 *
	 * @param  int $id Invoice id
	 * @return Facture Loaded invoice
	 *
	 * @throws RestException 403 Access denied
	 * @throws RestException 404 Invoice not found
	 */
	private function loadInvoice($id)
	{
		$invoice = new Facture($this->db);
		$result = $invoice->fetch((int) $id);
		if ($result <= 0) {
			throw new RestException(404, $this->transErr('InvoiceClosureErrInvoiceNotFound'));
		}
		if (!DolibarrApi::_checkAccessToResource('facture', $invoice->id)) {
			throw new RestException(403, 'Access to instance id='.$invoice->id.' of object not allowed for login '.DolibarrApiAccess::$user->login);
		}
		return $invoice;
	}

	/**
	 * Extract and validate the accepted body fields.
	 * Server-managed fields are explicitly rejected.
	 *
	 * @param  array|null $request_data Raw request data
	 * @return array                    array($note, $requestId)
	 *
	 * @throws RestException 400 Forbidden field present in the body
	 */
	private function parseBody($request_data)
	{
		$note = '';
		$requestId = '';
		if (is_array($request_data)) {
			foreach (array('fk_user_closure', 'date_closure', 'entity', 'closure_status', 'fk_user_reopen', 'date_reopen') as $forbidden) {
				if (array_key_exists($forbidden, $request_data)) {
					throw new RestException(400, "Field '".$forbidden."' is managed by the server and cannot be provided.");
				}
			}
			if (isset($request_data['note'])) {
				$note = (string) $request_data['note'];
			}
			if (isset($request_data['request_id'])) {
				$requestId = (string) $request_data['request_id'];
			}
		}
		return array($note, $requestId);
	}

	/**
	 * Build the closure status response shared by GET and POST close.
	 *
	 * @param  Facture             $invoice Invoice
	 * @param  InvoiceClosure|null $closure Closure record (null if none)
	 * @return array                        Response payload
	 */
	private function buildStatusResponse(Facture $invoice, $closure)
	{
		global $langs;

		$isclosed = ($closure !== null && (int) $closure->closure_status === InvoiceClosure::STATUS_CLOSED);

		$businessStatusCode = 'not_closed';
		if ($isclosed) {
			$businessStatusCode = 'closed';
		} elseif ($closure !== null && !empty($closure->date_reopen)) {
			$businessStatusCode = 'reopened';
		}

		$response = array(
			'invoice_id' => (int) $invoice->id,
			'invoice_ref' => (string) $invoice->ref,
			'dolibarr_status' => (int) $invoice->statut,
			'dolibarr_status_code' => $this->getDolibarrStatusCode($invoice),
			'dolibarr_status_label' => $invoice->getLibStatut(0, -1),
			'business_status' => $isclosed ? 1 : 0,
			'business_status_code' => $businessStatusCode,
			'business_status_label' => $isclosed ? $langs->trans('InvoiceClosed') : $invoice->getLibStatut(0, -1),
			'locked' => ($isclosed && getDolGlobalInt('INVOICECLOSURE_LOCK_CLOSED_INVOICES')) ? true : false,
			'closed_at' => null,
			'closed_at_iso' => null,
			'closed_by' => null,
			'closure_note' => '',
			'reopened_at' => null,
			'reopened_at_iso' => null,
			'reopened_by' => null,
			'reopen_note' => '',
		);

		if ($closure !== null) {
			if (!empty($closure->date_closure)) {
				$response['closed_at'] = (int) $closure->date_closure;
				$response['closed_at_iso'] = dol_print_date($closure->date_closure, 'dayhourrfc');
				$response['closed_by'] = array(
					'id' => (int) $closure->fk_user_closure,
					'login' => $closure->closure_login,
				);
				$response['closure_note'] = $closure->closure_note;
			}
			if (!empty($closure->date_reopen)) {
				$response['reopened_at'] = (int) $closure->date_reopen;
				$response['reopened_at_iso'] = dol_print_date($closure->date_reopen, 'dayhourrfc');
				$response['reopened_by'] = array(
					'id' => (int) $closure->fk_user_reopen,
					'login' => $closure->reopen_login,
				);
				$response['reopen_note'] = $closure->reopen_note;
			}
		}

		return $response;
	}

	/**
	 * Machine readable code of the standard Dolibarr invoice status.
	 *
	 * @param  Facture $invoice Invoice
	 * @return string           Status code
	 */
	private function getDolibarrStatusCode(Facture $invoice)
	{
		switch ((int) $invoice->statut) {
			case Facture::STATUS_DRAFT:
				return 'draft';
			case Facture::STATUS_VALIDATED:
				return 'validated';
			case Facture::STATUS_CLOSED:
				return empty($invoice->paye) ? 'paid_partially' : 'paid';
			case Facture::STATUS_ABANDONED:
				return 'abandoned';
			default:
				return 'unknown';
		}
	}

	/**
	 * Map a business error of the InvoiceClosure class to a RestException.
	 *
	 * @param  InvoiceClosure $closure Business object holding errorCode and error
	 * @return RestException           Exception ready to be thrown
	 */
	private function mapBusinessError(InvoiceClosure $closure)
	{
		$message = ($closure->error !== '' ? $closure->error : 'Unknown error');
		switch ($closure->errorCode) {
			case 'NOT_FOUND':
			case 'ENTITY_MISMATCH':
				// Do not reveal the existence of invoices of other entities
				return new RestException(404, $message);
			case 'PERMISSION_DENIED':
				return new RestException(403, $message);
			case 'NOTE_REQUIRED':
			case 'INVALID_REQUEST_ID':
				return new RestException(400, $message);
			case 'NOT_PAID':
			case 'REMAIN_NOT_ZERO':
			case 'ABANDONED':
			case 'NOT_CUSTOMER_INVOICE':
			case 'NOT_CLOSED':
			case 'ALREADY_CLOSED':
			case 'REOPEN_DISABLED':
				return new RestException(409, $message);
			default:
				// Technical details are kept in dol_syslog only
				return new RestException(500, $this->transErr('InvoiceClosureErrTechnical'));
		}
	}

	/**
	 * Translate an error key with the module language files.
	 *
	 * @param  string $key Translation key
	 * @return string      Translated message
	 */
	private function transErr($key)
	{
		global $langs;
		$langs->loadLangs(array('invoiceclosure@invoiceclosure'));
		return $langs->trans($key);
	}
}

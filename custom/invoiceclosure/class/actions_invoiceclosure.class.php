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
 * \file    custom/invoiceclosure/class/actions_invoiceclosure.class.php
 * \ingroup invoiceclosure
 * \brief   Hook overloads for the InvoiceClosure module.
 *
 * Contexts handled (verified in Dolibarr 20.0.4 sources):
 *  - invoicecard : doActions, formConfirm, addMoreActionsButtons,
 *                  formObjectOptions (view), formDolBanner (status badge).
 *  - invoicelist : printFieldListSelect/From/Where/SearchParam/Option/Title/Value.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonhookactions.class.php';
dol_include_once('/invoiceclosure/class/invoiceclosure.class.php');


/**
 * Class ActionsInvoiceclosure
 */
class ActionsInvoiceclosure extends CommonHookActions
{
	/**
	 * @var DoliDB Database handler.
	 */
	public $db;

	/**
	 * @var string Error code (or message)
	 */
	public $error = '';

	/**
	 * @var array Errors
	 */
	public $errors = array();

	/**
	 * @var array Hook results.
	 */
	public $results = array();

	/**
	 * @var ?string String displayed by executeHook() immediately after return
	 */
	public $resprints;

	/**
	 * @var int Priority of hook
	 */
	public $priority;

	/**
	 * Per-request cache of the closure record of the current invoice.
	 *
	 * @var array<int,InvoiceClosure|null>
	 */
	private $closureCache = array();

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
	// Invoice card
	// -------------------------------------------------------------------

	/**
	 * doActions hook: perform the closure / reopening requested from the card.
	 *
	 * @param array       $parameters  Hook metadata (context, ...)
	 * @param CommonObject $object     Current object (Facture)
	 * @param string      $action      Current action
	 * @param HookManager $hookmanager Hook manager
	 * @return int                     0 to continue standard processing, <0 on error
	 */
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $user, $langs;

		$this->resprints = '';

		if (empty($parameters['currentcontext']) || $parameters['currentcontext'] != 'invoicecard') {
			return 0;
		}
		if (!is_object($object) || $object->element !== 'facture' || empty($object->id)) {
			return 0;
		}
		if (!isModEnabled('invoiceclosure')) {
			return 0;
		}

		$langs->loadLangs(array('invoiceclosure@invoiceclosure'));

		// The CSRF token of the confirmation POST has already been validated by main.inc.php
		if ($action == 'confirm_invoiceclosure_close' && GETPOST('confirm', 'alpha') == 'yes') {
			$note = GETPOST('closure_note', 'alphanohtml');

			$closure = new InvoiceClosure($this->db);
			$result = $closure->close($object, $user, $note, 'UI', '');
			if ($result == 1) {
				setEventMessages($langs->trans('InvoiceClosureSuccessful'), null, 'mesgs');
				header('Location: '.$_SERVER['PHP_SELF'].'?facid='.((int) $object->id));
				exit;
			}
			if ($result == 2) {
				setEventMessages($langs->trans('InvoiceAlreadyClosed'), null, 'warnings');
				header('Location: '.$_SERVER['PHP_SELF'].'?facid='.((int) $object->id));
				exit;
			}
			setEventMessages($closure->error, $closure->errors, 'errors');
			$action = '';
			return 0;
		}

		if ($action == 'confirm_invoiceclosure_reopen' && GETPOST('confirm', 'alpha') == 'yes') {
			$note = GETPOST('reopen_note', 'alphanohtml');

			$closure = new InvoiceClosure($this->db);
			$result = $closure->reopen($object, $user, $note, 'UI', '');
			if ($result == 1 || $result == 2) {
				setEventMessages($langs->trans('InvoiceClosureReopened'), null, 'mesgs');
				header('Location: '.$_SERVER['PHP_SELF'].'?facid='.((int) $object->id));
				exit;
			}
			setEventMessages($closure->error, $closure->errors, 'errors');
			$action = '';
			return 0;
		}

		return 0;
	}

	/**
	 * formConfirm hook: display the confirmation dialogs with the note field.
	 *
	 * @param array       $parameters  Hook metadata
	 * @param CommonObject $object     Current object (Facture)
	 * @param string      $action      Current action
	 * @param HookManager $hookmanager Hook manager
	 * @return int                     0 to append our confirm box to the standard one
	 */
	public function formConfirm($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $user, $langs;

		$this->resprints = '';

		if (empty($parameters['currentcontext']) || $parameters['currentcontext'] != 'invoicecard') {
			return 0;
		}
		if (!is_object($object) || $object->element !== 'facture' || empty($object->id)) {
			return 0;
		}
		if (!isModEnabled('invoiceclosure')) {
			return 0;
		}

		$langs->loadLangs(array('invoiceclosure@invoiceclosure'));

		$form = new Form($this->db);

		if ($action == 'invoiceclosure_close') {
			$closure = new InvoiceClosure($this->db);
			if (!$closure->canClose($object, $user)) {
				return 0;
			}
			$notemandatory = getDolGlobalInt('INVOICECLOSURE_REQUIRE_CLOSE_NOTE');
			$formquestion = array(
				array(
					'type' => 'textarea',
					'name' => 'closure_note',
					'label' => $langs->trans('ClosureNote').($notemandatory ? ' <span class="fieldrequired">*</span>' : ''),
					'value' => '',
					'morecss' => 'quatrevingtpercent',
				),
			);
			$this->resprints = $form->formconfirm(
				$_SERVER['PHP_SELF'].'?facid='.((int) $object->id),
				$langs->trans('CloseInvoice'),
				$langs->trans('ConfirmCloseInvoice'),
				'confirm_invoiceclosure_close',
				$formquestion,
				'yes',
				1,
				320
			);
			return 0;
		}

		if ($action == 'invoiceclosure_reopen') {
			$closure = new InvoiceClosure($this->db);
			if (!$closure->canReopen($object, $user)) {
				return 0;
			}
			$notemandatory = getDolGlobalInt('INVOICECLOSURE_REQUIRE_REOPEN_NOTE');
			$formquestion = array(
				array(
					'type' => 'textarea',
					'name' => 'reopen_note',
					'label' => $langs->trans('ReopenNote').($notemandatory ? ' <span class="fieldrequired">*</span>' : ''),
					'value' => '',
					'morecss' => 'quatrevingtpercent',
				),
			);
			$this->resprints = $form->formconfirm(
				$_SERVER['PHP_SELF'].'?facid='.((int) $object->id),
				$langs->trans('ReopenInvoiceClosure'),
				$langs->trans('ConfirmReopenInvoice'),
				'confirm_invoiceclosure_reopen',
				$formquestion,
				'yes',
				1,
				320
			);
			return 0;
		}

		return 0;
	}

	/**
	 * addMoreActionsButtons hook: add the "Close" / "Reopen closure" buttons.
	 * When the invoice is closed and locking is enabled, the standard action
	 * buttons are replaced by a safe subset (server side protection is done
	 * by the module triggers anyway).
	 *
	 * @param array       $parameters  Hook metadata
	 * @param CommonObject $object     Current object (Facture)
	 * @param string      $action      Current action
	 * @param HookManager $hookmanager Hook manager
	 * @return int                     0 to keep standard buttons, 1 to replace them
	 */
	public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $user, $langs;

		$this->resprints = '';

		if (empty($parameters['currentcontext']) || $parameters['currentcontext'] != 'invoicecard') {
			return 0;
		}
		if (!is_object($object) || $object->element !== 'facture' || empty($object->id)) {
			return 0;
		}
		if (!isModEnabled('invoiceclosure')) {
			return 0;
		}

		$langs->loadLangs(array('invoiceclosure@invoiceclosure'));

		$closure = $this->loadClosure((int) $object->id);
		$isclosed = ($closure !== null && (int) $closure->closure_status === InvoiceClosure::STATUS_CLOSED);

		// "Close" button
		$checker = new InvoiceClosure($this->db);
		if ($checker->canClose($object, $user)) {
			$url = $_SERVER['PHP_SELF'].'?facid='.((int) $object->id).'&action=invoiceclosure_close&token='.newToken();
			print dolGetButtonAction($langs->trans('CloseInvoice'), '', 'default', $url, 'invoiceclosure-close-btn', true);
		}

		// "Reopen closure" button
		if ($isclosed) {
			$reopenchecker = new InvoiceClosure($this->db);
			if ($reopenchecker->canReopen($object, $user)) {
				$url = $_SERVER['PHP_SELF'].'?facid='.((int) $object->id).'&action=invoiceclosure_reopen&token='.newToken();
				print dolGetButtonAction($langs->trans('ReopenInvoiceClosure'), '', 'default', $url, 'invoiceclosure-reopen-btn', true);
			}
		}

		// Locked invoice: replace the standard buttons with a safe subset
		if ($isclosed && getDolGlobalInt('INVOICECLOSURE_LOCK_CLOSED_INVOICES') && !$user->hasRight('invoiceclosure', 'force')) {
			// Sending the invoice by email does not modify it
			if ($user->hasRight('facture', 'lire')) {
				print dolGetButtonAction($langs->trans('SendMail'), '', 'default', $_SERVER['PHP_SELF'].'?facid='.((int) $object->id).'&action=presend&mode=init&token='.newToken().'#formmailbeforetitle', 'invoiceclosure-send-btn', true);
			}
			// Cloning creates a new draft and does not modify this invoice
			if ($user->hasRight('facture', 'creer')) {
				print dolGetButtonAction($langs->trans('ToClone'), '', 'default', $_SERVER['PHP_SELF'].'?facid='.((int) $object->id).'&action=clone&object=invoice&token='.newToken(), 'invoiceclosure-clone-btn', true);
			}
			return 1;
		}

		return 0;
	}

	/**
	 * formObjectOptions hook: display the closure information block on the
	 * invoice card (view mode only).
	 *
	 * @param array       $parameters  Hook metadata
	 * @param CommonObject $object     Current object (Facture)
	 * @param string      $action      Current action
	 * @param HookManager $hookmanager Hook manager
	 * @return int                     0
	 */
	public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $user, $langs;

		$this->resprints = '';

		if (empty($parameters['currentcontext']) || $parameters['currentcontext'] != 'invoicecard') {
			return 0;
		}
		if (!is_object($object) || $object->element !== 'facture' || empty($object->id)) {
			return 0;
		}
		// View mode only (the same hook also fires on the create/edit forms)
		if (in_array($action, array('create', 'edit', 'editline', 'update'), true)) {
			return 0;
		}
		if (!isModEnabled('invoiceclosure') || !$user->hasRight('invoiceclosure', 'read')) {
			return 0;
		}

		$langs->loadLangs(array('invoiceclosure@invoiceclosure'));

		$closure = $this->loadClosure((int) $object->id);
		$isclosed = ($closure !== null && (int) $closure->closure_status === InvoiceClosure::STATUS_CLOSED);

		$html = '<tr class="invoiceclosure-infos"><td>'.$langs->trans('InvoiceClosureBusinessStatus').'</td><td>';
		if ($isclosed) {
			$html .= $this->getClosedBadge($langs);
		} else {
			$html .= '<span class="opacitymedium">'.$langs->trans('InvoiceNotClosed').'</span>';
		}
		if ($user->hasRight('invoiceclosure', 'readhistory')) {
			$html .= ' <a class="paddingleft" href="'.dol_buildpath('/invoiceclosure/history.php', 1).'?id='.((int) $object->id).'">'.$langs->trans('InvoiceClosureHistory').'</a>';
		}
		$html .= '</td></tr>';

		if ($closure !== null && $isclosed) {
			$html .= '<tr class="invoiceclosure-infos"><td>'.$langs->trans('ClosureDate').'</td><td>'.dol_print_date($closure->date_closure, 'dayhour', 'tzuser').'</td></tr>';
			$html .= '<tr class="invoiceclosure-infos"><td>'.$langs->trans('ClosedBy').'</td><td>'.dol_escape_htmltag($closure->closure_login).'</td></tr>';
			if ($closure->closure_note !== '') {
				$html .= '<tr class="invoiceclosure-infos"><td>'.$langs->trans('ClosureNote').'</td><td>'.dol_nl2br(dol_escape_htmltag($closure->closure_note)).'</td></tr>';
			}
			if (!empty($closure->date_reopen)) {
				$html .= '<tr class="invoiceclosure-infos"><td>'.$langs->trans('LastReopenDate').'</td><td>'.dol_print_date($closure->date_reopen, 'dayhour', 'tzuser').'</td></tr>';
			}
		} elseif ($closure !== null && !empty($closure->date_reopen)) {
			// Previously closed then reopened
			$html .= '<tr class="invoiceclosure-infos"><td>'.$langs->trans('LastReopenDate').'</td><td>'.dol_print_date($closure->date_reopen, 'dayhour', 'tzuser');
			if ($closure->reopen_login !== '') {
				$html .= ' <span class="opacitymedium">('.$langs->trans('ReopenedBy').' '.dol_escape_htmltag($closure->reopen_login).')</span>';
			}
			$html .= '</td></tr>';
			if ($closure->reopen_note !== '') {
				$html .= '<tr class="invoiceclosure-infos"><td>'.$langs->trans('ReopenNote').'</td><td>'.dol_nl2br(dol_escape_htmltag($closure->reopen_note)).'</td></tr>';
			}
		}

		$this->resprints = $html;
		return 0;
	}

	/**
	 * formDolBanner hook: append the "Closed" badge next to the standard
	 * "Paid" status in the banner of the invoice card.
	 *
	 * @param array       $parameters  Hook metadata (morehtmlstatus passed by reference)
	 * @param CommonObject $object     Current object
	 * @param string      $action      Current action
	 * @param HookManager $hookmanager Hook manager
	 * @return int                     0
	 */
	public function formDolBanner($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $user, $langs;

		$this->resprints = '';

		if (empty($parameters['currentcontext']) || $parameters['currentcontext'] != 'invoicecard') {
			return 0;
		}
		if (!is_object($object) || $object->element !== 'facture' || empty($object->id)) {
			return 0;
		}
		if (!isModEnabled('invoiceclosure') || !getDolGlobalInt('INVOICECLOSURE_SHOW_BADGE')) {
			return 0;
		}
		if (!$user->hasRight('invoiceclosure', 'read')) {
			return 0;
		}

		$closure = $this->loadClosure((int) $object->id);
		if ($closure === null || (int) $closure->closure_status !== InvoiceClosure::STATUS_CLOSED) {
			return 0;
		}

		$langs->loadLangs(array('invoiceclosure@invoiceclosure'));

		if (isset($parameters['morehtmlstatus'])) {
			$parameters['morehtmlstatus'] .= ' '.$this->getClosedBadge($langs);
		}

		return 0;
	}


	// -------------------------------------------------------------------
	// Invoice list
	// -------------------------------------------------------------------

	/**
	 * printFieldListSelect hook: add the closure fields to the list query.
	 *
	 * @param array       $parameters  Hook metadata
	 * @param CommonObject $object     Current object
	 * @param string      $action      Current action
	 * @param HookManager $hookmanager Hook manager
	 * @return int                     0
	 */
	public function printFieldListSelect($parameters, &$object, &$action, $hookmanager)
	{
		$this->resprints = '';

		if (!$this->isInvoiceListEnabled($parameters)) {
			return 0;
		}

		$this->resprints = ', ic.rowid as invoiceclosure_id, ic.closure_status as invoiceclosure_status,';
		$this->resprints .= ' ic.date_closure as invoiceclosure_datec, uic.login as invoiceclosure_login';
		return 0;
	}

	/**
	 * printFieldListFrom hook: join the closure table to the list query.
	 *
	 * @param array       $parameters  Hook metadata
	 * @param CommonObject $object     Current object
	 * @param string      $action      Current action
	 * @param HookManager $hookmanager Hook manager
	 * @return int                     0
	 */
	public function printFieldListFrom($parameters, &$object, &$action, $hookmanager)
	{
		$this->resprints = '';

		if (!$this->isInvoiceListEnabled($parameters)) {
			return 0;
		}

		$this->resprints = ' LEFT JOIN '.MAIN_DB_PREFIX.'invoiceclosure as ic ON ic.fk_facture = f.rowid AND ic.entity IN ('.getEntity('invoice').')';
		$this->resprints .= ' LEFT JOIN '.MAIN_DB_PREFIX.'user as uic ON uic.rowid = ic.fk_user_closure';
		return 0;
	}

	/**
	 * printFieldListWhere hook: apply the closure filters to the list query.
	 *
	 * @param array       $parameters  Hook metadata
	 * @param CommonObject $object     Current object
	 * @param string      $action      Current action
	 * @param HookManager $hookmanager Hook manager
	 * @return int                     0
	 */
	public function printFieldListWhere($parameters, &$object, &$action, $hookmanager)
	{
		$this->resprints = '';

		if (!$this->isInvoiceListEnabled($parameters)) {
			return 0;
		}

		$sql = '';

		$searchStatus = GETPOST('search_invoiceclosure_status', 'alpha');
		if ($searchStatus === '1') {
			$sql .= " AND ic.closure_status = 1";
		} elseif ($searchStatus === '0') {
			$sql .= " AND (ic.rowid IS NULL OR ic.closure_status = 0)";
		}

		$searchUser = GETPOSTINT('search_invoiceclosure_user');
		if ($searchUser > 0) {
			$sql .= " AND ic.fk_user_closure = ".((int) $searchUser);
		}

		$dateStart = dol_mktime(0, 0, 0, GETPOSTINT('search_invoiceclosure_datestartmonth'), GETPOSTINT('search_invoiceclosure_datestartday'), GETPOSTINT('search_invoiceclosure_datestartyear'));
		if ($dateStart) {
			$sql .= " AND ic.date_closure >= '".$this->db->idate($dateStart)."'";
		}
		$dateEnd = dol_mktime(23, 59, 59, GETPOSTINT('search_invoiceclosure_dateendmonth'), GETPOSTINT('search_invoiceclosure_dateendday'), GETPOSTINT('search_invoiceclosure_dateendyear'));
		if ($dateEnd) {
			$sql .= " AND ic.date_closure <= '".$this->db->idate($dateEnd)."'";
		}

		$this->resprints = $sql;
		return 0;
	}

	/**
	 * printFieldListSearchParam hook: keep the closure filters in pagination
	 * and sort links.
	 *
	 * @param array       $parameters  Hook metadata
	 * @param CommonObject $object     Current object
	 * @param string      $action      Current action
	 * @param HookManager $hookmanager Hook manager
	 * @return int                     0
	 */
	public function printFieldListSearchParam($parameters, &$object, &$action, $hookmanager)
	{
		$this->resprints = '';

		if (!$this->isInvoiceListEnabled($parameters)) {
			return 0;
		}

		$param = '';

		$searchStatus = GETPOST('search_invoiceclosure_status', 'alpha');
		if ($searchStatus === '0' || $searchStatus === '1') {
			$param .= '&search_invoiceclosure_status='.urlencode($searchStatus);
		}
		$searchUser = GETPOSTINT('search_invoiceclosure_user');
		if ($searchUser > 0) {
			$param .= '&search_invoiceclosure_user='.((int) $searchUser);
		}
		foreach (array('datestart', 'dateend') as $bound) {
			foreach (array('day', 'month', 'year') as $unit) {
				$val = GETPOSTINT('search_invoiceclosure_'.$bound.$unit);
				if ($val > 0) {
					$param .= '&search_invoiceclosure_'.$bound.$unit.'='.((int) $val);
				}
			}
		}

		$this->resprints = $param;
		return 0;
	}

	/**
	 * printFieldListOption hook: print the search inputs of the closure column.
	 *
	 * @param array       $parameters  Hook metadata
	 * @param CommonObject $object     Current object
	 * @param string      $action      Current action
	 * @param HookManager $hookmanager Hook manager
	 * @return int                     0
	 */
	public function printFieldListOption($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $form;

		$this->resprints = '';

		if (!$this->isInvoiceListEnabled($parameters)) {
			return 0;
		}

		$langs->loadLangs(array('invoiceclosure@invoiceclosure'));

		if (!is_object($form)) {
			$form = new Form($this->db);
		}

		$searchStatus = GETPOST('search_invoiceclosure_status', 'alpha');
		$searchUser = GETPOSTINT('search_invoiceclosure_user');
		$dateStart = dol_mktime(0, 0, 0, GETPOSTINT('search_invoiceclosure_datestartmonth'), GETPOSTINT('search_invoiceclosure_datestartday'), GETPOSTINT('search_invoiceclosure_datestartyear'));
		$dateEnd = dol_mktime(23, 59, 59, GETPOSTINT('search_invoiceclosure_dateendmonth'), GETPOSTINT('search_invoiceclosure_dateendday'), GETPOSTINT('search_invoiceclosure_dateendyear'));

		print '<td class="liste_titre center">';
		print $form->selectarray(
			'search_invoiceclosure_status',
			array('1' => $langs->trans('InvoiceClosed'), '0' => $langs->trans('InvoiceNotClosed')),
			(($searchStatus === '0' || $searchStatus === '1') ? $searchStatus : ''),
			1,
			0,
			0,
			'',
			0,
			0,
			0,
			'',
			'maxwidth100'
		);
		print '<br>';
		print $form->select_dolusers(($searchUser > 0 ? $searchUser : ''), 'search_invoiceclosure_user', 1, null, 0, '', '', '0', 0, 0, '', 0, '', 'maxwidth100');
		print '<div class="nowrap">'.$form->selectDate($dateStart ? $dateStart : -1, 'search_invoiceclosure_datestart', 0, 0, 1, '', 1, 0).'</div>';
		print '<div class="nowrap">'.$form->selectDate($dateEnd ? $dateEnd : -1, 'search_invoiceclosure_dateend', 0, 0, 1, '', 1, 0).'</div>';
		print '</td>';

		return 0;
	}

	/**
	 * printFieldListTitle hook: print the title of the closure column.
	 *
	 * @param array       $parameters  Hook metadata (param, sortfield, sortorder)
	 * @param CommonObject $object     Current object
	 * @param string      $action      Current action
	 * @param HookManager $hookmanager Hook manager
	 * @return int                     0
	 */
	public function printFieldListTitle($parameters, &$object, &$action, $hookmanager)
	{
		global $langs;

		$this->resprints = '';

		if (!$this->isInvoiceListEnabled($parameters)) {
			return 0;
		}

		$langs->loadLangs(array('invoiceclosure@invoiceclosure'));

		$param = isset($parameters['param']) ? $parameters['param'] : '';
		$sortfield = isset($parameters['sortfield']) ? $parameters['sortfield'] : '';
		$sortorder = isset($parameters['sortorder']) ? $parameters['sortorder'] : '';

		print_liste_field_titre($langs->trans('InvoiceClosureColumn'), $_SERVER['PHP_SELF'], 'ic.date_closure', '', $param, '', $sortfield, $sortorder, 'center ');

		return 0;
	}

	/**
	 * printFieldListValue hook: print the closure cell of one list row.
	 *
	 * @param array       $parameters  Hook metadata (obj, i, totalarray by reference)
	 * @param CommonObject $object     Current object
	 * @param string      $action      Current action
	 * @param HookManager $hookmanager Hook manager
	 * @return int                     0
	 */
	public function printFieldListValue($parameters, &$object, &$action, $hookmanager)
	{
		global $langs;

		$this->resprints = '';

		if (!$this->isInvoiceListEnabled($parameters)) {
			return 0;
		}

		$langs->loadLangs(array('invoiceclosure@invoiceclosure'));

		$obj = isset($parameters['obj']) ? $parameters['obj'] : null;

		print '<td class="center nowraponall tdoverflowmax150">';
		if ($obj !== null && !empty($obj->invoiceclosure_id) && (int) $obj->invoiceclosure_status === InvoiceClosure::STATUS_CLOSED) {
			print $this->getClosedBadge($langs);
			if (!empty($obj->invoiceclosure_datec)) {
				print '<br><span class="opacitymedium small">'.dol_print_date($this->db->jdate($obj->invoiceclosure_datec), 'day', 'tzuser');
				if (!empty($obj->invoiceclosure_login)) {
					print ' - '.dol_escape_htmltag($obj->invoiceclosure_login);
				}
				print '</span>';
			}
		} else {
			print '<span class="opacitymedium small">'.$langs->trans('InvoiceNotClosed').'</span>';
		}
		print '</td>';

		if (empty($parameters['i']) && isset($parameters['totalarray']) && is_array($parameters['totalarray'])) {
			$parameters['totalarray']['nbfield']++;
		}

		return 0;
	}


	// -------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------

	/**
	 * Tell whether the closure column/filters must be handled on this list page.
	 *
	 * @param  array $parameters Hook metadata
	 * @return bool              True when the invoice list integration is active
	 */
	private function isInvoiceListEnabled($parameters)
	{
		global $user;

		if (empty($parameters['currentcontext']) || $parameters['currentcontext'] != 'invoicelist') {
			return false;
		}
		if (!isModEnabled('invoiceclosure')) {
			return false;
		}
		if (!getDolGlobalInt('INVOICECLOSURE_SHOW_IN_INVOICE_LIST')) {
			return false;
		}
		if (!$user->hasRight('invoiceclosure', 'read')) {
			return false;
		}
		return true;
	}

	/**
	 * Load (once per request) the closure record of an invoice.
	 *
	 * @param  int $invoiceId Invoice id
	 * @return InvoiceClosure|null Closure record or null when none exists
	 */
	private function loadClosure($invoiceId)
	{
		if (array_key_exists($invoiceId, $this->closureCache)) {
			return $this->closureCache[$invoiceId];
		}
		$closure = new InvoiceClosure($this->db);
		$res = $closure->fetchByInvoice($invoiceId);
		$this->closureCache[$invoiceId] = ($res > 0) ? $closure : null;
		return $this->closureCache[$invoiceId];
	}

	/**
	 * Build the native looking "Closed" badge.
	 *
	 * @param  Translate $langs Translation handler
	 * @return string           HTML of the badge
	 */
	private function getClosedBadge($langs)
	{
		return '<span class="badge badge-status8 badge-status invoiceclosure-badge" title="'.dol_escape_htmltag($langs->trans('InvoiceClosedBadgeHelp')).'">'.$langs->trans('InvoiceClosed').'</span>';
	}
}

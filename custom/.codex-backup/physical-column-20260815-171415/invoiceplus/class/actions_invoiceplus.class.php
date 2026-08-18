<?php
/* Copyright (C) 2026 InvoicePlus module */

/**
 * \file    custom/invoiceplus/class/actions_invoiceplus.class.php
 * \ingroup invoiceplus
 * \brief   Non-core invoice-card presentation hooks.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonhookactions.class.php';

/** Display InvoicePlus physical cash amounts without changing native records. */
class ActionsInvoiceplus extends CommonHookActions
{
	/** @var DoliDB */
	public $db;

	/** @var string */
	public $error = '';

	/** @var array */
	public $errors = array();

	/** @var array */
	public $results = array();

	/** @var string|null */
	public $resprints;

	/** @param DoliDB $db Database handler */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Add a read-only cash-currency block below the native invoice banner.
	 *
	 * Dolibarr's standard payment table intentionally prints
	 * paiement_facture.amount in company currency. For a CDF cash account this
	 * is the USD equivalent, not the physical amount posted to bank.amount.
	 *
	 * @param array        $parameters  Hook metadata
	 * @param CommonObject $object      Current customer invoice
	 * @param string       $action      Current action
	 * @param HookManager  $hookmanager Hook manager
	 * @return int                      0 to preserve the native invoice card
	 */
	public function tabContentViewInvoice($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $langs, $user;

		$this->resprints = '';
		// The block exposes cash-account identity and physical ledger amounts, so
		// keep it restricted to internal users who may read both resources.
		if (!isModEnabled('invoiceplus')
			|| empty($user->id)
			|| !empty($user->socid)
			|| !$user->hasRight('facture', 'lire')
			|| !$user->hasRight('banque', 'lire')) {
			return 0;
		}
		if (empty($parameters['currentcontext']) || $parameters['currentcontext'] !== 'invoicecard') {
			return 0;
		}
		if (!is_object($object) || $object->element !== 'facture' || (int) $object->id <= 0) {
			return 0;
		}

		$sql = 'SELECT p.rowid AS payment_id, p.ref AS payment_ref, p.amount AS payment_base,';
		$sql .= ' b.amount AS bank_amount, b.amount_main_currency AS bank_main,';
		$sql .= ' ba.rowid AS account_id, ba.ref AS account_ref, ba.currency_code AS account_currency';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'paiement_facture AS pf';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'paiement AS p ON p.rowid = pf.fk_paiement';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'bank AS b ON b.rowid = p.fk_bank';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'bank_account AS ba ON ba.rowid = b.fk_account';
		$sql .= ' WHERE pf.fk_facture = '.((int) $object->id);
		$sql .= " AND p.ref_ext LIKE 'invoiceplus:%'";
		$sql .= ' AND p.entity IN ('.getEntity('invoice').')';
		$sql .= ' AND ba.entity IN ('.getEntity('bank_account').')';
		$sql .= ' ORDER BY p.datep, p.tms, p.rowid';
		$result = $this->db->query($sql);
		if (!$result) {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_ERR);
			return 0;
		}
		if ($this->db->num_rows($result) === 0) {
			$this->db->free($result);
			return 0;
		}

		$langs->loadLangs(array('banks', 'invoiceplus@invoiceplus'));
		$companyCurrency = strtoupper((string) $conf->currency);
		$html = '<div class="fichecenter invoiceplus-cash-currency-block">';
		$html .= '<div class="underbanner clearboth"></div>';
		$html .= '<div class="div-table-responsive-no-min">';
		$html .= '<table class="noborder centpercent">';
		$html .= '<tr class="liste_titre">';
		$html .= '<td>'.$langs->trans('InvoicePlusPhysicalCashPayments').'</td>';
		$html .= '<td>'.$langs->trans('BankAccount').'</td>';
		$html .= '<td class="right">'.$langs->trans('InvoicePlusPhysicalAmount').'</td>';
		$html .= '<td class="right">'.$langs->trans('InvoicePlusCompanyEquivalent', $companyCurrency).'</td>';
		$html .= '</tr>';

		while ($row = $this->db->fetch_object($result)) {
			$accountCurrency = strtoupper((string) $row->account_currency);
			$companyAmount = $row->bank_main !== null ? (float) $row->bank_main : (float) $row->payment_base;
			$paymentUrl = DOL_URL_ROOT.'/compta/paiement/card.php?id='.((int) $row->payment_id);
			$accountUrl = DOL_URL_ROOT.'/compta/bank/bankentries_list.php?id='.((int) $row->account_id);
			$html .= '<tr class="oddeven">';
			$html .= '<td><a href="'.dol_escape_htmltag($paymentUrl).'">'.dol_escape_htmltag($row->payment_ref).'</a></td>';
			$html .= '<td><a href="'.dol_escape_htmltag($accountUrl).'">'.dol_escape_htmltag($row->account_ref).'</a></td>';
			$html .= '<td class="right amount">'.price((float) $row->bank_amount, 0, $langs, 1, -1, -1, $accountCurrency).'</td>';
			$html .= '<td class="right amount">'.price($companyAmount, 0, $langs, 1, -1, -1, $companyCurrency).'</td>';
			$html .= '</tr>';
		}
		$this->db->free($result);
		$html .= '</table></div>';
		$html .= '<div class="opacitymedium small">'.$langs->trans('InvoicePlusNativePaymentAmountHelp', $companyCurrency).'</div>';
		$html .= '</div>';

		// tabContentViewInvoice does not print hook resPrint in Dolibarr 20.0.4.
		// Direct output appends this block and returning 0 preserves native content.
		print $html;
		return 0;
	}
}

<?php
/* Copyright (C) 2026 InvoicePlus module */

/**
 * \file    custom/invoiceplus/class/actions_invoiceplus.class.php
 * \ingroup invoiceplus
 * \brief   Non-core invoice-card presentation hooks.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonhookactions.class.php';

/** Enrich native invoice payment rows without changing native records. */
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
	 * Add the physical cash amount immediately before the native Amount column.
	 *
	 * Dolibarr's standard payment table intentionally prints
	 * paiement_facture.amount in company currency. For a CDF cash account this
	 * is the USD equivalent, not the physical amount posted to bank.amount. The
	 * hook runs before the native table is printed, so it emits only a safe,
	 * idempotent DOM enrichment payload for that existing table.
	 *
	 * @param array        $parameters  Hook metadata
	 * @param CommonObject $object      Current customer invoice
	 * @param string       $action      Current action
	 * @param HookManager  $hookmanager Hook manager
	 * @return int                      0 to preserve the native invoice card
	 */
	public function tabContentViewInvoice($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $user;

		$this->resprints = '';
		// Physical ledger amounts remain restricted to internal users who may
		// read both the invoice and its bank account.
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

		$sql = 'SELECT p.rowid AS payment_id, b.amount AS bank_amount,';
		$sql .= ' ba.currency_code AS account_currency';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'paiement_facture AS pf';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'paiement AS p ON p.rowid = pf.fk_paiement';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'bank AS b ON b.rowid = p.fk_bank';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'bank_account AS ba ON ba.rowid = b.fk_account';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'invoiceplus_cash_settlement AS ics';
		$sql .= ' ON ics.entity = p.entity AND ics.fk_facture = pf.fk_facture';
		$sql .= " AND ics.status = 'completed'";
		$sql .= " AND ((p.ref_ext = CONCAT('invoiceplus:', ics.operation_id, ':cdf')";
		$sql .= " AND UPPER(ba.currency_code) = 'CDF')";
		$sql .= " OR (p.ref_ext = CONCAT('invoiceplus:', ics.operation_id, ':usd')";
		$sql .= " AND UPPER(ba.currency_code) = 'USD'))";
		$sql .= ' WHERE pf.fk_facture = '.((int) $object->id);
		$sql .= ' AND ics.fk_facture = '.((int) $object->id);
		$sql .= ' AND ics.entity = '.((int) $object->entity);
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
		$physicalAmounts = array();
		while ($row = $this->db->fetch_object($result)) {
			$paymentId = (int) $row->payment_id;
			$accountCurrency = strtoupper((string) $row->account_currency);
			if ($paymentId <= 0
				|| !in_array($accountCurrency, array('CDF', 'USD'), true)
				|| !is_numeric($row->bank_amount)) {
				dol_syslog(__METHOD__.' skipped invalid physical amount for payment '.$paymentId, LOG_WARNING);
				continue;
			}
			$physicalAmounts[$paymentId] = price((float) $row->bank_amount, 0, $langs, 1, -1, -1, $accountCurrency);
		}
		$this->db->free($result);
		$script = $this->buildPhysicalAmountEnrichmentScript(
			$physicalAmounts,
			$langs->transnoentitiesnoconv('InvoicePlusPhysicalAmount')
		);

		// tabContentViewInvoice does not print hook resPrint in Dolibarr 20.0.4.
		// Direct output registers the enrichment; returning 0 preserves the card.
		if ($script !== '') {
			print $script;
		}
		return 0;
	}

	/**
	 * Build the safe inline payload that enriches Dolibarr's native table.
	 *
	 * @param array  $physicalAmounts Formatted physical amount by payment id
	 * @param string $headerLabel     Localized column label
	 * @return string                 Script element, or an empty string
	 */
	private function buildPhysicalAmountEnrichmentScript(array $physicalAmounts, $headerLabel)
	{
		$amounts = array();
		foreach ($physicalAmounts as $paymentId => $displayAmount) {
			$paymentId = (int) $paymentId;
			$displayAmount = trim((string) $displayAmount);
			$normalizedDisplayAmount = preg_replace('/[\p{Z}\s]+/u', ' ', $displayAmount);
			if ($normalizedDisplayAmount === false || $normalizedDisplayAmount === null) {
				// Preserve the safe JSON_INVALID_UTF8_SUBSTITUTE fallback when an
				// invalid byte sequence prevents Unicode whitespace normalization.
				dol_syslog(__METHOD__.' unable to normalize payment amount whitespace', LOG_WARNING);
				$normalizedDisplayAmount = $displayAmount;
			}
			$normalizedDisplayAmount = trim($normalizedDisplayAmount);
			if ($paymentId > 0 && $normalizedDisplayAmount !== '') {
				$amounts[$paymentId] = $normalizedDisplayAmount;
			}
		}
		if (empty($amounts)) {
			return '';
		}

		$config = json_encode(
			array(
				'amounts' => (object) $amounts,
				'label' => (string) $headerLabel,
			),
			JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
			| JSON_INVALID_UTF8_SUBSTITUTE
		);
		if ($config === false) {
			dol_syslog(__METHOD__.' unable to encode invoice payment enrichment', LOG_ERR);
			return '';
		}

		$javascript = <<<'JAVASCRIPT'
(function () {
	'use strict';

	var config = __INVOICEPLUS_CONFIG__;
	var hasOwn = Object.prototype.hasOwnProperty;

	function paymentIdFromRow(row) {
		var links = row.getElementsByTagName('a');
		for (var i = 0; i < links.length; i++) {
			var href = links[i].getAttribute('href');
			if (!href) {
				continue;
			}
			try {
				var url = new URL(href, document.baseURI);
				var normalizedPath = url.pathname.replace(/\/{2,}/g, '/');
				if (!/\/compta\/paiement\/card\.php$/.test(normalizedPath)) {
					continue;
				}
				var paymentId = url.searchParams.get('id');
				if (/^[1-9][0-9]*$/.test(paymentId || '')) {
					return paymentId;
				}
			} catch (error) {
				// Ignore unrelated or malformed links from other hooks.
			}
		}
		return null;
	}

	function enrichTable(table) {
		if (table.getAttribute('data-invoiceplus-physical-enriched') === '1') {
			return;
		}

		var headerRow = table.querySelector('tr.liste_titre');
		if (!headerRow) {
			return;
		}

		var originalColumnCount = headerRow.cells.length;
		if (originalColumnCount < 5 || !headerRow.cells[originalColumnCount - 2].classList.contains('right')) {
			return;
		}

		// Preflight the complete native structure before changing any cell. Native
		// payment rows have the header width; every summary row has three DOM
		// cells and spans all columns preceding Amount. Unknown structures fail
		// closed so another module's markup is never shifted partially.
		var detailRows = [];
		var summaryRows = [];
		var hasMappedPayment = false;
		for (var i = 0; i < table.rows.length; i++) {
			var row = table.rows[i];
			if (row === headerRow) {
				continue;
			}
			if (row.classList.contains('oddeven')) {
				if (row.cells.length !== originalColumnCount) {
					return;
				}
				var paymentId = paymentIdFromRow(row);
				detailRows.push({row: row, paymentId: paymentId});
				if (paymentId !== null && hasOwn.call(config.amounts, paymentId)) {
					hasMappedPayment = true;
				}
			} else if (row.cells.length === 3 && row.cells[0].colSpan === originalColumnCount - 2) {
				summaryRows.push(row);
			} else {
				return;
			}
		}
		if (!hasMappedPayment || detailRows.length === 0) {
			return;
		}

		for (var j = 0; j < detailRows.length; j++) {
			var paymentRow = detailRows[j].row;
			var amountCell = paymentRow.cells[originalColumnCount - 2];
			var cell = document.createElement('td');
			cell.className = 'right invoiceplus-physical-amount';
			var amount = document.createElement('span');
			amount.className = 'amount';
			if (detailRows[j].paymentId !== null && hasOwn.call(config.amounts, detailRows[j].paymentId)) {
				amount.textContent = config.amounts[detailRows[j].paymentId];
			} else {
				amount.textContent = '\u2014';
			}
			cell.appendChild(amount);
			paymentRow.insertBefore(cell, amountCell);
		}

		// Native totals/remises have one leading colspan, followed by the native
		// Amount and action cells. Extend that colspan through the new column.
		for (var k = 0; k < summaryRows.length; k++) {
			summaryRows[k].cells[0].colSpan = originalColumnCount - 1;
		}

		var header = document.createElement('td');
		header.className = 'liste_titre right invoiceplus-physical-amount-header';
		header.setAttribute('scope', 'col');
		header.textContent = config.label;
		headerRow.insertBefore(header, headerRow.cells[originalColumnCount - 2]);

		table.setAttribute('data-invoiceplus-physical-enriched', '1');
	}

	function run() {
		var tables = document.querySelectorAll('table.paymenttable');
		for (var i = 0; i < tables.length; i++) {
			enrichTable(tables[i]);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', run, {once: true});
	} else {
		run();
	}
})();
JAVASCRIPT;

		return '<script nonce="'.dol_escape_htmltag(getNonce()).'" type="text/javascript">'."\n".str_replace('__INVOICEPLUS_CONFIG__', $config, $javascript)."\n".'</script>';
	}
}

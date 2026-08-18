<?php
/* Copyright (C) 2026 InvoicePlus module
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    custom/invoiceplus/class/invoicepluscashsettlementservice.class.php
 * \ingroup invoiceplus
 * \brief   Atomic and idempotent mixed-currency cash settlement service.
 */

require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';

use Luracast\Restler\RestException;

/**
 * Settle one validated CDF customer invoice with CDF and/or USD cash.
 *
 * All database mutations are performed below one outer transaction. The
 * native Paiement and Account classes are deliberately reused so Dolibarr
 * triggers, payment-to-invoice links and bank URLs remain standard.
 */
class InvoicePlusCashSettlementService
{
	const STATUS_PROCESSING = 'processing';
	const STATUS_COMPLETED = 'completed';
	const STATUS_FAILED = 'failed';

	/** @var DoliDB */
	private $db;

	/** @var User */
	private $user;

	/** @var int */
	private $entity;

	/**
	 * @param DoliDB $db   Database handler
	 * @param User   $user Authenticated API user
	 */
	public function __construct($db, $user)
	{
		global $conf;

		$this->db = $db;
		$this->user = $user;
		$this->entity = !empty($conf->entity) ? (int) $conf->entity : 1;
	}

	/**
	 * Create or replay an atomic settlement.
	 *
	 * @param int   $invoiceId Invoice id
	 * @param array $request   Raw JSON body
	 * @return array           Settlement result
	 * @throws RestException
	 */
	public function settle($invoiceId, $request)
	{
		global $conf;

		$this->assertWriteAccess();
		$invoiceId = $this->positiveInteger($invoiceId, 'invoice_id');
		$normalized = $this->normalizeRequest($invoiceId, $request);
		$payloadHash = hash('sha256', json_encode($normalized));

		// Perform the native resource check before an idempotent replay too. A
		// former cashier assignment must not become a permanent read capability.
		$this->loadAccessibleInvoice($invoiceId);

		$reservation = $this->reserveOperation($invoiceId, $normalized['operation_id'], $payloadHash);
		if (!empty($reservation['replay'])) {
			$response = $reservation['result'];
			$response['idempotent_replay'] = true;
			return $response;
		}

		$pdfAutoupdateWasSet = isset($conf->global->MAIN_DISABLE_PDF_AUTOUPDATE);
		$pdfAutoupdatePrevious = $pdfAutoupdateWasSet ? $conf->global->MAIN_DISABLE_PDF_AUTOUPDATE : null;
		$conf->global->MAIN_DISABLE_PDF_AUTOUPDATE = 1;

		try {
			$invoice = $this->lockAndValidateInvoice($invoiceId, $normalized);
			$remaining = $this->getInvoiceRemainingCents($invoice);
			if ($normalized['total_cdf_cents'] !== $remaining['cdf']) {
				throw new RestException(409, 'Invoice remaining amount changed. Refresh the invoice before retrying.');
			}

			$netCdf = $normalized['received_cdf_cents'] - $normalized['change_cdf_cents'];
			$netUsd = $normalized['received_usd_cents'] - $normalized['change_usd_cents'];
			$settlementValue = $netCdf + (int) round($netUsd * $normalized['exchange_rate']);
			if ($settlementValue !== $remaining['cdf']) {
				throw new RestException(422, 'Received amounts minus change do not equal the invoice balance at the invoice exchange rate.');
			}

			$allocation = $this->allocatePayments(
				$remaining['cdf'],
				$remaining['usd'],
				$normalized['exchange_rate'],
				$netCdf,
				$netUsd
			);
			if ($allocation['transfer'] !== null && !$this->user->hasRight('banque', 'transfer')) {
				throw new RestException(403, 'Bank transfer permission is required for cross-currency change.');
			}

			$warehouseId = (int) $this->user->fk_warehouse;
			$this->validateInvoiceWarehouse($invoice, $warehouseId);
			$accounts = $this->loadAndLockAccounts($normalized['accounts'], $allocation, $warehouseId);
			$this->assertChangeAvailability($accounts, $normalized);

			$paymentMode = $this->loadCashPaymentMode($normalized['payment_method_id']);
			$payments = array('cdf' => null, 'usd' => null);

			if ($allocation['payment_cdf_cents'] > 0) {
				$payments['cdf'] = $this->createPayment(
					$invoice,
					'CDF',
					$allocation['payment_cdf_cents'],
					$accounts['cdf']['id'],
					$paymentMode,
					$normalized
				);
			}
			if ($allocation['payment_usd_cents'] > 0) {
				$payments['usd'] = $this->createPayment(
					$invoice,
					'USD',
					$allocation['payment_usd_cents'],
					$accounts['usd']['id'],
					$paymentMode,
					$normalized
				);
			}

			$transfer = null;
			if ($allocation['transfer'] !== null) {
				$transfer = $this->createCurrencyTransfer($invoice, $accounts, $allocation['transfer'], $normalized);
			}

			$invoiceAfterPayment = new Facture($this->db);
			if ($invoiceAfterPayment->fetch($invoiceId) <= 0) {
				throw new RestException(500, 'Unable to reload invoice after payment creation.');
			}
			$posted = $this->getOperationPostedCents($invoiceId, $normalized['operation_id']);
			$postedCdf = $posted['cdf'];
			$postedUsd = $posted['usd'];
			if ($postedCdf !== $remaining['cdf'] || $postedUsd !== $remaining['usd']) {
				throw new RestException(409, 'Settlement could not reconcile the invoice to an exact zero balance. No payment was committed.');
			}

			$result = $invoiceAfterPayment->setPaid($this->user, '', '');
			if ($result < 0) {
				throw new RestException(500, 'Unable to mark the fully settled invoice as paid.');
			}

			$response = $this->buildResult(
				$invoiceAfterPayment,
				$normalized,
				$remaining,
				$allocation,
				$accounts,
				$payments,
				$transfer,
				$paymentMode
			);
			$this->completeOperation($normalized['operation_id'], $payloadHash, $response);

			if (!$this->commitAllTransactions('InvoicePlus cash settlement')) {
				$this->rollbackAllTransactions('InvoicePlus cash settlement commit failure');
				throw new RestException(500, 'Unable to commit the cash settlement.');
			}
			$this->restorePdfAutoupdateSetting($pdfAutoupdateWasSet, $pdfAutoupdatePrevious);
			$this->regenerateInvoiceDocumentBestEffort($invoiceAfterPayment);

			return $response;
		} catch (RestException $exception) {
			$this->restorePdfAutoupdateSetting($pdfAutoupdateWasSet, $pdfAutoupdatePrevious);
			$this->rollbackAllTransactions('InvoicePlus cash settlement failure');
			$this->recordFailure($invoiceId, $normalized['operation_id'], $payloadHash, (int) $exception->getCode(), $exception->getMessage());
			throw $exception;
		} catch (Throwable $exception) {
			$this->restorePdfAutoupdateSetting($pdfAutoupdateWasSet, $pdfAutoupdatePrevious);
			$this->rollbackAllTransactions('InvoicePlus cash settlement exception');
			dol_syslog(__METHOD__.' '.$exception->getMessage(), LOG_ERR);
			$this->recordFailure($invoiceId, $normalized['operation_id'], $payloadHash, 500, 'Unexpected settlement error.');
			throw new RestException(500, 'Unexpected cash settlement error. No payment was committed.');
		}
	}

	/** Restore the request-scoped PDF autoupdate flag exactly as it was. */
	private function restorePdfAutoupdateSetting($wasSet, $previous)
	{
		global $conf;

		if ($wasSet) {
			$conf->global->MAIN_DISABLE_PDF_AUTOUPDATE = $previous;
		} else {
			unset($conf->global->MAIN_DISABLE_PDF_AUTOUPDATE);
		}
	}

	/** Regenerate the final paid invoice document only after the financial commit. */
	private function regenerateInvoiceDocumentBestEffort($invoice)
	{
		global $langs;

		if (getDolGlobalString('MAIN_DISABLE_PDF_AUTOUPDATE')) {
			return;
		}
		try {
			$invoice->fetch((int) $invoice->id);
			$result = $invoice->generateDocument(
				$invoice->model_pdf,
				$langs,
				getDolGlobalString('MAIN_GENERATE_DOCUMENTS_HIDE_DETAILS') ? 1 : 0,
				getDolGlobalString('MAIN_GENERATE_DOCUMENTS_HIDE_DESC') ? 1 : 0,
				getDolGlobalString('MAIN_GENERATE_DOCUMENTS_HIDE_REF') ? 1 : 0
			);
			if ($result < 0) {
				dol_syslog(__METHOD__.' '.$invoice->error, LOG_WARNING);
			}
		} catch (Throwable $exception) {
			dol_syslog(__METHOD__.' '.$exception->getMessage(), LOG_WARNING);
		}
	}

	/**
	 * Return the state of an operation created by the authenticated user.
	 *
	 * @param string $operationId Idempotency key
	 * @return array              Operation state
	 * @throws RestException
	 */
	public function getStatus($operationId)
	{
		$this->assertReadAccess();
		$operationId = $this->normalizeOperationId($operationId);
		$row = $this->fetchOperation($operationId, false, true);
		if ($row === null) {
			throw new RestException(404, 'Cash settlement operation not found.');
		}

		$this->loadAccessibleInvoice((int) $row->fk_facture);
		if ((string) $row->status === self::STATUS_COMPLETED && !empty($row->result_json)) {
			$result = json_decode($row->result_json, true);
			if (!is_array($result)) {
				throw new RestException(500, 'Stored cash-settlement result is invalid.');
			}
			$result['idempotent_replay'] = true;
			return $result;
		}

		return array(
			'operation_id' => (string) $row->operation_id,
			'status' => (string) $row->status,
			'invoice_id' => (int) $row->fk_facture,
			'user_id' => (int) $row->fk_user,
			'created_at' => $this->db->jdate($row->date_creation),
			'updated_at' => $this->db->jdate($row->date_update),
			'error' => ((string) $row->status === self::STATUS_FAILED) ? array(
				'code' => $row->error_code !== null ? (string) $row->error_code : null,
				'message' => $row->error_message !== null ? (string) $row->error_message : null,
			) : null,
		);
	}

	/**
	 * Normalize a request into a deterministic, hashable representation.
	 * Public to keep monetary validation independently testable.
	 *
	 * @param int   $invoiceId Invoice id
	 * @param array $request   Raw request
	 * @return array           Normalized values
	 * @throws RestException
	 */
	public function normalizeRequest($invoiceId, $request)
	{
		if (!is_array($request)) {
			throw new RestException(400, 'A JSON object is required.');
		}
		$allowed = array('operation_id', 'date', 'payment_method_id', 'exchange_rate', 'total_cdf', 'received', 'change', 'accounts');
		foreach ($request as $field => $value) {
			if (!in_array($field, $allowed, true)) {
				throw new RestException(400, "Unknown or server-managed field '".$field."'.");
			}
		}
		foreach ($allowed as $field) {
			if (!array_key_exists($field, $request)) {
				throw new RestException(400, "Missing required field '".$field."'.");
			}
		}

		$operationId = $this->normalizeOperationId($request['operation_id']);
		$date = $this->normalizeDate($request['date']);
		$paymentMethodId = $this->positiveInteger($request['payment_method_id'], 'payment_method_id');
		$rate = $this->normalizeRate($request['exchange_rate']);
		$totalCdf = $this->moneyToCents($request['total_cdf'], 'total_cdf');
		if ($totalCdf <= 0) {
			throw new RestException(422, 'total_cdf must be greater than zero.');
		}

		$received = $this->normalizeCurrencyObject($request['received'], 'received', false);
		$change = $this->normalizeCurrencyObject($request['change'], 'change', false);
		$accounts = $this->normalizeCurrencyObject($request['accounts'], 'accounts', true);
		if ($received['cdf'] <= 0 && $received['usd'] <= 0) {
			throw new RestException(422, 'At least one received amount must be greater than zero.');
		}

		$receivedValue = $received['cdf'] + (int) round($received['usd'] * $rate);
		$changeValue = $change['cdf'] + (int) round($change['usd'] * $rate);
		if ($changeValue > $receivedValue) {
			throw new RestException(422, 'Total change cannot exceed total cash received.');
		}

		return array(
			'invoice_id' => (int) $invoiceId,
			'operation_id' => $operationId,
			'date' => $date,
			'payment_method_id' => $paymentMethodId,
			'exchange_rate' => $rate,
			'exchange_rate_decimal' => number_format($rate, 8, '.', ''),
			'total_cdf_cents' => $totalCdf,
			'received_cdf_cents' => $received['cdf'],
			'received_usd_cents' => $received['usd'],
			'change_cdf_cents' => $change['cdf'],
			'change_usd_cents' => $change['usd'],
			'accounts' => array('cdf' => $accounts['cdf'], 'usd' => $accounts['usd']),
		);
	}

	/**
	 * Allocate invoice payments as close as possible to the physical net tender.
	 * Any cross-currency difference is returned as one linked bank transfer.
	 *
	 * @param int   $remainingCdf Invoice remainder, CDF cents
	 * @param int   $remainingUsd Invoice remainder, USD cents
	 * @param float $rate         Invoice CDF-per-USD rate
	 * @param int   $netCdf       Physical CDF account delta in cents
	 * @param int   $netUsd       Physical USD account delta in cents
	 * @return array              Payment and transfer allocation
	 * @throws RestException
	 */
	public function allocatePayments($remainingCdf, $remainingUsd, $rate, $netCdf, $netUsd)
	{
		$remainingCdf = (int) $remainingCdf;
		$remainingUsd = (int) $remainingUsd;
		if ($remainingCdf <= 0 || $remainingUsd <= 0) {
			throw new RestException(409, 'Invoice has no positive remaining amount in both configured currencies.');
		}
		$seed = max(0, min($remainingUsd, (int) $netUsd));
		$candidateMap = array();
		$seeds = array(0, $remainingUsd, $seed);
		$physicalCdf = max(0, min($remainingCdf, (int) $netCdf));
		$seeds[] = (int) round(($remainingCdf - $physicalCdf) / $rate);
		foreach ($seeds as $candidateSeed) {
			for ($offset = -20; $offset <= 20; $offset++) {
				$candidate = max(0, min($remainingUsd, (int) $candidateSeed + $offset));
				$candidateMap[$candidate] = true;
			}
		}

		$best = null;
		$bestScore = null;
		foreach (array_keys($candidateMap) as $paymentUsd) {
			$paymentUsd = (int) $paymentUsd;
			$paymentCdf = $remainingCdf - (int) round($paymentUsd * $rate);
			if ($paymentCdf < 0) {
				continue;
			}
			$convertedBase = (int) round($paymentCdf / $rate) + $paymentUsd;
			if ($convertedBase !== $remainingUsd) {
				continue;
			}

			$transferCdfDelta = (int) $netCdf - $paymentCdf;
			$transferUsdDelta = (int) $netUsd - $paymentUsd;
			$transfer = null;
			if ($transferCdfDelta === 0 && $transferUsdDelta === 0) {
				$transfer = null;
			} elseif ($transferCdfDelta < 0 && $transferUsdDelta > 0) {
				$transfer = array(
					'from' => 'CDF',
					'to' => 'USD',
					'from_cents' => -$transferCdfDelta,
					'to_cents' => $transferUsdDelta,
				);
			} elseif ($transferCdfDelta > 0 && $transferUsdDelta < 0) {
				$transfer = array(
					'from' => 'USD',
					'to' => 'CDF',
					'from_cents' => -$transferUsdDelta,
					'to_cents' => $transferCdfDelta,
				);
			} else {
				continue;
			}

			$score = abs($transferCdfDelta) + (int) round(abs($transferUsdDelta) * $rate);
			if ($best === null || $score < $bestScore) {
				$best = array(
					'payment_cdf_cents' => $paymentCdf,
					'payment_usd_cents' => $paymentUsd,
					'transfer' => $transfer,
				);
				$bestScore = $score;
			}
		}

		if ($best === null) {
			throw new RestException(422, 'The mixed-currency amounts cannot be reconciled exactly with the invoice totals and exchange rate.');
		}

		return $best;
	}

	/** Assert endpoint write permissions. */
	private function assertWriteAccess()
	{
		$this->assertReadAccess();
		if (!$this->user->hasRight('facture', 'creer')) {
			throw new RestException(403, 'Invoice creation/payment permission is required.');
		}
		if (!$this->user->hasRight('facture', 'paiement')) {
			throw new RestException(403, 'Invoice payment permission is required.');
		}
		if (!isModEnabled('bank')) {
			throw new RestException(409, 'The bank module must be enabled for cash settlement.');
		}
		if (!$this->user->hasRight('banque', 'modifier')) {
			throw new RestException(403, 'Bank-entry write permission is required.');
		}
	}

	/** Assert endpoint read permissions and internal-user context. */
	private function assertReadAccess()
	{
		if (empty($this->user->id) || !empty($this->user->socid)) {
			throw new RestException(403, 'Cash settlement is restricted to authenticated internal users.');
		}
		if (!$this->user->hasRight('facture', 'lire')) {
			throw new RestException(403, 'Invoice read permission is required.');
		}
		if (!$this->user->hasRight('banque', 'lire')) {
			throw new RestException(403, 'Bank-account read permission is required.');
		}
	}

	/** Load an invoice and apply Dolibarr's native resource access check. */
	private function loadAccessibleInvoice($invoiceId)
	{
		$invoice = new Facture($this->db);
		$result = $invoice->fetch((int) $invoiceId);
		if ($result < 0) {
			throw new RestException(503, 'Unable to read invoice.');
		}
		if ($result === 0) {
			throw new RestException(404, 'Invoice not found.');
		}
		if (!InvoicePlusNativeInvoicesApi::checkResourceAccess('facture', (int) $invoice->id)) {
			throw new RestException(403, 'Access not allowed to this invoice.');
		}
		$sql = 'SELECT sc.fk_soc FROM '.MAIN_DB_PREFIX.'societe_commerciaux AS sc';
		$sql .= ' WHERE sc.fk_soc = '.((int) $invoice->socid);
		$sql .= ' AND sc.fk_user = '.((int) $this->user->id);
		$result = $this->db->query($sql);
		if (!$result) {
			throw new RestException(503, 'Unable to verify invoice sales assignment.');
		}
		$assigned = $this->db->num_rows($result) > 0;
		$this->db->free($result);
		if (!$assigned) {
			throw new RestException(403, 'Invoice customer is not assigned to the authenticated sales representative.');
		}
		return $invoice;
	}

	/** Lock and validate the invoice immediately before posting payments. */
	private function lockAndValidateInvoice($invoiceId, array $normalized)
	{
		global $conf;

		$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'facture';
		$sql .= ' WHERE rowid = '.((int) $invoiceId);
		$sql .= ' AND entity IN ('.getEntity('invoice').') FOR UPDATE';
		$result = $this->db->query($sql);
		if (!$result) {
			throw new RestException(503, 'Unable to lock invoice for settlement.');
		}
		$found = $this->db->num_rows($result) > 0;
		$this->db->free($result);
		if (!$found) {
			throw new RestException(404, 'Invoice not found.');
		}

		$invoice = $this->loadAccessibleInvoice($invoiceId);
		$status = isset($invoice->status) ? (int) $invoice->status : (int) $invoice->statut;
		if ($status !== Facture::STATUS_VALIDATED || !empty($invoice->paye)) {
			throw new RestException(409, 'Only a validated and unpaid invoice can be settled.');
		}
		if ((float) $invoice->total_ttc <= 0 || (float) $invoice->multicurrency_total_ttc <= 0) {
			throw new RestException(422, 'Only a positive customer invoice can be settled.');
		}
		if (strtoupper((string) $conf->currency) !== 'USD') {
			throw new RestException(409, 'Company base currency must be USD for this settlement endpoint.');
		}
		if (strtoupper((string) $invoice->multicurrency_code) !== 'CDF') {
			throw new RestException(409, 'Invoice currency must be CDF for this settlement endpoint.');
		}
		$invoiceRate = (float) $invoice->multicurrency_tx;
		if ($invoiceRate <= 0 || abs($invoiceRate - $normalized['exchange_rate']) > 0.00000001) {
			throw new RestException(409, 'Exchange rate changed. Refresh the invoice before retrying.');
		}
		if ((int) $this->user->fk_warehouse <= 0) {
			throw new RestException(403, 'The authenticated user has no default warehouse.');
		}
		$invoiceAccountId = (int) $invoice->fk_account;
		$requestedCdfAccountId = (int) $normalized['accounts']['cdf'];
		if ($invoiceAccountId <= 0 || $requestedCdfAccountId <= 0) {
			throw new RestException(409, 'Invoice and settlement must identify the same CDF cash account.');
		}
		if ($invoiceAccountId !== $requestedCdfAccountId) {
			throw new RestException(409, 'Requested CDF cash account does not match the invoice cash account.');
		}

		return $invoice;
	}

	/**
	 * Prove that validation moved every stock-managed invoice product through
	 * the authenticated user's warehouse.
	 *
	 * Facture::validate() consumes the warehouse passed to the validation call;
	 * it does not persist that value reliably on facturedet.fk_warehouse. Native
	 * stock movements linked to the invoice are therefore the authoritative
	 * evidence. Net quantities are used so an unvalidate/revalidate cycle is
	 * handled without accepting a remaining debit in another warehouse.
	 */
	private function validateInvoiceWarehouse($invoice, $warehouseId)
	{
		global $conf;

		$warehouseId = (int) $warehouseId;
		if ($warehouseId <= 0) {
			throw new RestException(403, 'The authenticated user has no default warehouse.');
		}

		$sql = 'SELECT fd.rowid, fd.fk_product, fd.qty, fd.fk_warehouse';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'facturedet AS fd';
		$sql .= ' WHERE fd.fk_facture = '.((int) $invoice->id);
		$sql .= ' ORDER BY fd.rowid FOR UPDATE';
		$result = $this->db->query($sql);
		if (!$result) {
			throw new RestException(503, 'Unable to read invoice stock requirements.');
		}

		$invoiceLines = array();
		$productIds = array();
		while ($row = $this->db->fetch_object($result)) {
			$productId = (int) $row->fk_product;
			$lineWarehouseId = (int) $row->fk_warehouse;
			if ($lineWarehouseId > 0 && $lineWarehouseId !== $warehouseId) {
				$this->db->free($result);
				throw new RestException(403, 'An invoice product line is assigned to a warehouse other than the authenticated user warehouse.');
			}
			$invoiceLines[] = array(
				'id' => (int) $row->rowid,
				'product_id' => $productId,
				'quantity' => $row->qty,
				'warehouse_id' => $lineWarehouseId,
			);
			if ($productId > 0) {
				$productIds[$productId] = $productId;
			}
		}
		$this->db->free($result);

		$productTypes = array();
		if (!empty($productIds)) {
			$sql = 'SELECT rowid, fk_product_type FROM '.MAIN_DB_PREFIX.'product';
			$sql .= ' WHERE rowid IN ('.implode(',', $productIds).')';
			$sql .= ' ORDER BY rowid FOR UPDATE';
			$result = $this->db->query($sql);
			if (!$result) {
				throw new RestException(503, 'Unable to lock invoice product definitions.');
			}
			while ($row = $this->db->fetch_object($result)) {
				$productTypes[(int) $row->rowid] = (int) $row->fk_product_type;
			}
			$this->db->free($result);
		}

		$requirements = array();
		$lineIdsToBackfill = array();
		foreach ($invoiceLines as $line) {
			$productId = (int) $line['product_id'];
			if ($productId <= 0) {
				continue;
			}
			if (!array_key_exists($productId, $productTypes)) {
				throw new RestException(409, 'Invoice references a product that no longer exists.');
			}
			$isStockManaged = $productId > 0
				&& ($productTypes[$productId] !== 1 || !empty($conf->global->STOCK_SUPPORTS_SERVICES));
			if (!$isStockManaged) {
				continue;
			}
			if ((int) $line['warehouse_id'] <= 0) {
				$lineIdsToBackfill[] = (int) $line['id'];
			}
			if (!isset($requirements[$productId])) {
				$requirements[$productId] = array('quantity_units' => 0, 'line_count' => 0);
			}
			$requirements[$productId]['quantity_units'] = $this->addQuantityUnits(
				$requirements[$productId]['quantity_units'],
				$this->quantityToUnits($line['quantity'])
			);
			$requirements[$productId]['line_count']++;
		}
		if (empty($requirements)) {
			throw new RestException(409, 'Invoice has no stock-managed product line eligible for warehouse cash settlement.');
		}

		// Lock the native evidence while the invoice row is already locked by the
		// settlement transaction. Legitimate validate/unvalidate paths serialize
		// through that invoice lock, while this lock also protects existing rows.
		$sql = 'SELECT rowid, fk_product, fk_entrepot, value, type_mouvement';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'stock_mouvement';
		$sql .= ' WHERE fk_origin = '.((int) $invoice->id);
		$sql .= " AND origintype = 'facture'";
		$sql .= ' ORDER BY rowid FOR UPDATE';
		$result = $this->db->query($sql);
		if (!$result) {
			throw new RestException(503, 'Unable to lock invoice stock-movement evidence.');
		}

		$movements = array();
		while ($row = $this->db->fetch_object($result)) {
			$movements[] = array(
				'product_id' => (int) $row->fk_product,
				'warehouse_id' => (int) $row->fk_entrepot,
				'quantity_units' => $this->quantityToUnits($row->value),
				'type' => (int) $row->type_mouvement,
			);
		}
		$this->db->free($result);

		$this->assertWarehouseMovementProof($requirements, $movements, $warehouseId);

		// Repair only legacy missing metadata and only after the authoritative
		// movement proof succeeded. A conflicting non-zero assignment is never
		// overwritten. The update is part of the outer settlement transaction.
		if (!empty($lineIdsToBackfill)) {
			$sql = 'UPDATE '.MAIN_DB_PREFIX.'facturedet';
			$sql .= ' SET fk_warehouse = '.$warehouseId;
			$sql .= ' WHERE fk_facture = '.((int) $invoice->id);
			$sql .= ' AND rowid IN ('.implode(',', $lineIdsToBackfill).')';
			$sql .= ' AND (fk_warehouse IS NULL OR fk_warehouse = 0)';
			$result = $this->db->query($sql);
			if (!$result || $this->db->affected_rows($result) !== count($lineIdsToBackfill)) {
				throw new RestException(503, 'Unable to persist verified invoice warehouse metadata.');
			}
		}
	}

	/**
	 * Validate an exact, net stock-movement proof using quantities scaled to
	 * eight decimal places (the precision of Dolibarr stock quantities).
	 *
	 * @param array $requirements Required invoice quantity by product
	 * @param array $movements    Native stock movements linked to the invoice
	 * @param int   $warehouseId  Authenticated user's warehouse
	 * @return void
	 */
	private function assertWarehouseMovementProof(array $requirements, array $movements, $warehouseId)
	{
		$netByProductWarehouse = array();
		$movementCountByProduct = array();
		foreach ($movements as $movement) {
			$productId = (int) $movement['product_id'];
			$movementWarehouseId = (int) $movement['warehouse_id'];
			$type = (int) $movement['type'];
			if ($productId <= 0 || $movementWarehouseId <= 0 || !in_array($type, array(2, 3), true)) {
				throw new RestException(409, 'Invoice stock-movement evidence contains an unexpected native movement.');
			}
			if (!isset($netByProductWarehouse[$productId])) {
				$netByProductWarehouse[$productId] = array();
				$movementCountByProduct[$productId] = 0;
			}
			if (!isset($netByProductWarehouse[$productId][$movementWarehouseId])) {
				$netByProductWarehouse[$productId][$movementWarehouseId] = 0;
			}
			$netByProductWarehouse[$productId][$movementWarehouseId] = $this->addQuantityUnits(
				$netByProductWarehouse[$productId][$movementWarehouseId],
				(int) $movement['quantity_units']
			);
			$movementCountByProduct[$productId]++;
		}

		// A fully reversed historical movement in another warehouse is harmless;
		// any non-zero remaining quantity there proves the invoice is outside the
		// authenticated warehouse context and must fail closed.
		foreach ($netByProductWarehouse as $warehouseTotals) {
			foreach ($warehouseTotals as $movementWarehouseId => $quantityUnits) {
				if ((int) $movementWarehouseId !== (int) $warehouseId && (int) $quantityUnits !== 0) {
					throw new RestException(403, 'Invoice stock was moved through a warehouse other than the authenticated user warehouse.');
				}
			}
		}

		foreach ($requirements as $productId => $requirement) {
			$productId = (int) $productId;
			$expectedMovement = 0 - (int) $requirement['quantity_units'];
			$actualMovement = isset($netByProductWarehouse[$productId][$warehouseId])
				? (int) $netByProductWarehouse[$productId][$warehouseId]
				: 0;
			if (empty($movementCountByProduct[$productId]) || $actualMovement !== $expectedMovement) {
				throw new RestException(409, 'Invoice stock exit from the authenticated user warehouse cannot be proven.');
			}
		}
	}

	/** Convert a Dolibarr quantity to an exact signed 1e-8 integer. */
	private function quantityToUnits($value)
	{
		$string = trim((string) $value);
		if (!preg_match('/^-?[0-9]+(?:\.[0-9]{1,8})?$/', $string)) {
			if (!is_numeric($value)) {
				throw new RestException(503, 'Invalid quantity in invoice stock evidence.');
			}
			$numeric = (float) $value;
			if (!is_finite($numeric)) {
				throw new RestException(503, 'Invalid quantity in invoice stock evidence.');
			}
			$string = number_format($numeric, 8, '.', '');
		}

		$negative = isset($string[0]) && $string[0] === '-';
		if ($negative) {
			$string = substr($string, 1);
		}
		$parts = explode('.', $string, 2);
		$whole = ltrim($parts[0], '0');
		$whole = ($whole === '') ? '0' : $whole;
		if (strlen($whole) > 10) {
			throw new RestException(503, 'Quantity in invoice stock evidence exceeds the supported exact range.');
		}
		$decimal = isset($parts[1]) ? str_pad($parts[1], 8, '0') : '00000000';
		$units = ((int) $whole * 100000000) + (int) $decimal;
		return $negative ? (0 - $units) : $units;
	}

	/** Add scaled quantities while failing closed instead of overflowing. */
	private function addQuantityUnits($left, $right)
	{
		$left = (int) $left;
		$right = (int) $right;
		if (($right > 0 && $left > PHP_INT_MAX - $right)
			|| ($right < 0 && $left < PHP_INT_MIN - $right)) {
			throw new RestException(503, 'Quantity total in invoice stock evidence exceeds the supported exact range.');
		}
		return $left + $right;
	}

	/** Return exact remaining values in cents for both invoice currencies. */
	private function getInvoiceRemainingCents($invoice)
	{
		$paidBase = $invoice->getSommePaiement(0);
		$creditBase = $invoice->getSumCreditNotesUsed(0);
		$depositBase = $invoice->getSumDepositsUsed(0);
		$paidForeign = $invoice->getSommePaiement(1);
		$creditForeign = $invoice->getSumCreditNotesUsed(1);
		$depositForeign = $invoice->getSumDepositsUsed(1);
		foreach (array($paidBase, $creditBase, $depositBase, $paidForeign, $creditForeign, $depositForeign) as $value) {
			if ($value < 0) {
				throw new RestException(503, 'Unable to calculate invoice remaining amount.');
			}
		}

		return array(
			'usd' => $this->decimalDifferenceToCents(array($invoice->total_ttc), array($paidBase, $creditBase, $depositBase)),
			'cdf' => $this->decimalDifferenceToCents(array($invoice->multicurrency_total_ttc), array($paidForeign, $creditForeign, $depositForeign)),
		);
	}

	/** Return exact invoice amounts posted by this operation from native links. */
	private function getOperationPostedCents($invoiceId, $operationId)
	{
		$prefix = 'invoiceplus:'.$this->db->escape($operationId).':';
		$sql = 'SELECT SUM(pf.amount) AS amount, SUM(pf.multicurrency_amount) AS multicurrency_amount';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'paiement_facture AS pf';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'paiement AS p ON p.rowid = pf.fk_paiement';
		$sql .= ' WHERE pf.fk_facture = '.((int) $invoiceId);
		$sql .= " AND LEFT(p.ref_ext, ".strlen($prefix).") = '".$prefix."'";
		$sql .= ' AND p.entity = '.((int) $this->entity);
		$result = $this->db->query($sql);
		if (!$result) {
			throw new RestException(503, 'Unable to reconcile created invoice payments.');
		}
		$row = $this->db->fetch_object($result);
		$this->db->free($result);
		return array(
			'usd' => $row ? (int) round(((float) $row->amount) * 100) : 0,
			'cdf' => $row ? (int) round(((float) $row->multicurrency_amount) * 100) : 0,
		);
	}

	/** Load, authorize and lock the exact accounts needed by the allocation. */
	private function loadAndLockAccounts(array $requested, array $allocation, $warehouseId)
	{
		$requiresCdf = $allocation['payment_cdf_cents'] > 0;
		$requiresUsd = $allocation['payment_usd_cents'] > 0;
		if ($allocation['transfer'] !== null) {
			$requiresCdf = true;
			$requiresUsd = true;
		}

		$accounts = array('cdf' => null, 'usd' => null);
		foreach (array('cdf' => 'CDF', 'usd' => 'USD') as $key => $currency) {
			$required = ($key === 'cdf') ? $requiresCdf : $requiresUsd;
			$accountId = isset($requested[$key]) ? (int) $requested[$key] : 0;
			if ($required && $accountId <= 0) {
				throw new RestException(422, $currency.' cash account is required for this settlement.');
			}
			if ($accountId > 0) {
				$accounts[$key] = $this->loadAndLockAccount($accountId, $currency, $warehouseId);
			}
		}

		return $accounts;
	}

	/** Load one account from the trusted warehouse mapping and lock its ledger. */
	private function loadAndLockAccount($accountId, $currency, $warehouseId)
	{
		$sql = 'SELECT ba.rowid, ba.ref, ba.label, ba.currency_code, ba.clos, ba.courant, ef.warehouse';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'bank_account AS ba';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'bank_account_extrafields AS ef ON ef.fk_object = ba.rowid';
		$sql .= ' WHERE ba.rowid = '.((int) $accountId);
		$sql .= ' AND ba.entity IN ('.getEntity('bank_account').') FOR UPDATE';
		$result = $this->db->query($sql);
		if (!$result) {
			throw new RestException(503, 'Unable to lock cash account.');
		}
		$row = $this->db->fetch_object($result);
		$this->db->free($result);
		if (!$row || (int) $row->warehouse !== (int) $warehouseId) {
			throw new RestException(403, 'Cash account is not linked to the authenticated user warehouse.');
		}
		if ((int) $row->clos !== Account::STATUS_OPEN) {
			throw new RestException(409, 'Cash account is closed.');
		}
		if ((int) $row->courant !== Account::TYPE_CASH) {
			throw new RestException(422, 'Selected account is not a Dolibarr cash account.');
		}
		if (strtoupper((string) $row->currency_code) !== $currency) {
			throw new RestException(422, 'Cash account currency does not match '.$currency.'.');
		}

		$balance = 0.0;
		$balanceSql = 'SELECT rowid, amount FROM '.MAIN_DB_PREFIX.'bank';
		$balanceSql .= ' WHERE fk_account = '.((int) $accountId);
		$balanceSql .= " AND dateo <= '".$this->db->idate(dol_now())."' FOR UPDATE";
		$balanceResult = $this->db->query($balanceSql);
		if (!$balanceResult) {
			throw new RestException(503, 'Unable to lock cash-account balance.');
		}
		while ($line = $this->db->fetch_object($balanceResult)) {
			$balance += (float) $line->amount;
		}
		$this->db->free($balanceResult);

		return array(
			'id' => (int) $row->rowid,
			'ref' => (string) $row->ref,
			'label' => (string) $row->label,
			'currency' => $currency,
			'balance_cents' => (int) round($balance * 100),
		);
	}

	/** Prevent giving change that is not physically available. */
	private function assertChangeAvailability(array $accounts, array $normalized)
	{
		foreach (array('cdf' => 'CDF', 'usd' => 'USD') as $key => $currency) {
			$change = $normalized['change_'.$key.'_cents'];
			if ($change <= 0) {
				continue;
			}
			if ($accounts[$key] === null) {
				throw new RestException(422, $currency.' account is required to give change.');
			}
			$available = $accounts[$key]['balance_cents'] + $normalized['received_'.$key.'_cents'];
			if ($available < $change) {
				throw new RestException(409, 'Insufficient '.$currency.' cash available to give the requested change.');
			}
		}
	}

	/** Load and validate an active incoming cash payment mode. */
	private function loadCashPaymentMode($paymentMethodId)
	{
		$sql = 'SELECT id, code, libelle, type FROM '.MAIN_DB_PREFIX.'c_paiement';
		$sql .= ' WHERE id = '.((int) $paymentMethodId);
		$sql .= ' AND entity IN (0, '.((int) $this->entity).') AND active = 1';
		$result = $this->db->query($sql);
		if (!$result) {
			throw new RestException(503, 'Unable to verify payment method.');
		}
		$row = $this->db->fetch_object($result);
		$this->db->free($result);
		if (!$row || strtoupper((string) $row->code) !== 'LIQ' || !in_array((int) $row->type, array(0, 2), true)) {
			throw new RestException(422, 'payment_method_id must reference an active incoming LIQ payment method.');
		}
		return array('id' => (int) $row->id, 'code' => 'LIQ', 'label' => (string) $row->libelle);
	}

	/** Create a native customer payment and its native bank line. */
	private function createPayment($invoice, $currency, $amountCents, $accountId, array $paymentMode, array $normalized)
	{
		global $conf;

		$payment = new Paiement($this->db);
		$payment->datepaye = $normalized['date'];
		$payment->paiementid = $paymentMode['id'];
		$payment->paiementcode = $paymentMode['code'];
		$payment->fk_account = (int) $accountId;
		$payment->amounts = array((int) $invoice->id => 0);
		$payment->multicurrency_amounts = array((int) $invoice->id => 0);
		$payment->multicurrency_code = array((int) $invoice->id => 'CDF');
		$payment->multicurrency_tx = array((int) $invoice->id => $normalized['exchange_rate']);
		if ($currency === 'CDF') {
			$payment->multicurrency_amounts[(int) $invoice->id] = (float) $this->centsToDecimal($amountCents);
		} else {
			$payment->amounts[(int) $invoice->id] = (float) $this->centsToDecimal($amountCents);
		}
		$suffix = strtolower($currency);
		$externalRef = 'invoiceplus:'.$normalized['operation_id'].':'.$suffix;
		$payment->ref_ext = $externalRef;
		$payment->num_payment = substr('IP-'.$normalized['operation_id'].'-'.$suffix, 0, 50);
		$payment->note_private = 'InvoicePlus cash settlement '.$normalized['operation_id'].' ('.$currency.')';

		$paymentId = $payment->create($this->user, 0);
		if ($paymentId < 0) {
			dol_syslog(__METHOD__.' '.$payment->error, LOG_ERR);
			throw new RestException(500, 'Unable to create '.$currency.' invoice payment.');
		}
		$bankLineId = $payment->addPaymentToBank(
			$this->user,
			'payment',
			'(CustomerInvoicePayment)',
			(int) $accountId,
			'',
			''
		);
		if ($bankLineId < 0) {
			dol_syslog(__METHOD__.' '.$payment->error, LOG_ERR);
			throw new RestException(500, 'Unable to add '.$currency.' payment to its cash account.');
		}
		$ledgerProof = $this->loadAndValidatePaymentLedgerProof(
			(int) $invoice->id,
			(int) $paymentId,
			(int) $bankLineId,
			(int) $accountId,
			$currency,
			(int) $amountCents,
			$normalized['exchange_rate']
		);
		return array(
			'id' => (int) $paymentId,
			'bank_line_id' => (int) $bankLineId,
			'currency' => $currency,
			'amount' => $this->centsToDecimal($amountCents),
			'account_id' => (int) $accountId,
			'ref_ext' => $externalRef,
			'ledger' => array(
				'account_amount' => $this->centsToDecimal($ledgerProof['bank_amount_cents']),
				'account_currency' => $currency,
				'company_amount' => $this->centsToDecimal($ledgerProof['payment_base_cents']),
				'company_currency' => strtoupper((string) $conf->currency),
			),
		);
	}

	/**
	 * Lock and prove the native payment, invoice allocation and cash-account
	 * ledger row immediately after Dolibarr created them.
	 */
	private function loadAndValidatePaymentLedgerProof($invoiceId, $paymentId, $bankLineId, $accountId, $currency, $amountCents, $exchangeRate)
	{
		$sql = 'SELECT p.rowid AS payment_id, p.amount AS payment_base, p.multicurrency_amount AS payment_foreign, p.fk_bank,';
		$sql .= ' pf.amount AS link_base, pf.multicurrency_amount AS link_foreign, pf.multicurrency_code AS link_currency, pf.multicurrency_tx AS link_rate,';
		$sql .= ' b.rowid AS bank_line_id, b.amount AS bank_amount, b.amount_main_currency AS bank_main, b.fk_account,';
		$sql .= ' ba.currency_code AS account_currency';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'paiement AS p';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'paiement_facture AS pf ON pf.fk_paiement = p.rowid';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'bank AS b ON b.rowid = p.fk_bank';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'bank_account AS ba ON ba.rowid = b.fk_account';
		$sql .= ' WHERE p.rowid = '.((int) $paymentId);
		$sql .= ' AND p.entity = '.((int) $this->entity);
		$sql .= ' AND pf.fk_facture = '.((int) $invoiceId);
		$sql .= ' AND b.rowid = '.((int) $bankLineId);
		$sql .= ' AND b.fk_account = '.((int) $accountId);
		$sql .= ' AND ba.entity IN ('.getEntity('bank_account').') FOR UPDATE';
		$result = $this->db->query($sql);
		if (!$result) {
			throw new RestException(503, 'Unable to lock the created cash-payment ledger proof.');
		}
		if ($this->db->num_rows($result) !== 1) {
			$this->db->free($result);
			throw new RestException(500, 'Created cash payment has no unique native ledger proof. No settlement was committed.');
		}
		$row = $this->db->fetch_object($result);
		$this->db->free($result);

		$proof = array(
			'payment_id' => (int) $row->payment_id,
			'bank_line_id' => (int) $row->bank_line_id,
			'account_id' => (int) $row->fk_account,
			'account_currency' => strtoupper((string) $row->account_currency),
			'payment_base_cents' => $this->storedMoneyToCents($row->payment_base, 'payment.amount'),
			'payment_foreign_cents' => $this->storedMoneyToCents($row->payment_foreign, 'payment.multicurrency_amount'),
			'link_base_cents' => $this->storedMoneyToCents($row->link_base, 'payment_invoice.amount'),
			'link_foreign_cents' => $this->storedMoneyToCents($row->link_foreign, 'payment_invoice.multicurrency_amount'),
			'link_currency' => strtoupper((string) $row->link_currency),
			'link_rate' => (float) $row->link_rate,
			'bank_amount_cents' => $this->storedMoneyToCents($row->bank_amount, 'bank.amount'),
			'bank_main_cents' => $this->storedMoneyToCents($row->bank_main, 'bank.amount_main_currency', true),
		);
		$this->assertPaymentLedgerProof(
			$proof,
			(int) $paymentId,
			(int) $bankLineId,
			(int) $accountId,
			$currency,
			(int) $amountCents,
			(float) $exchangeRate
		);
		return $proof;
	}

	/** Validate the pure monetary invariants of one native payment ledger proof. */
	private function assertPaymentLedgerProof(array $proof, $paymentId, $bankLineId, $accountId, $currency, $amountCents, $exchangeRate)
	{
		$currency = strtoupper((string) $currency);
		$valid = (int) $proof['payment_id'] === (int) $paymentId
			&& (int) $proof['bank_line_id'] === (int) $bankLineId
			&& (int) $proof['account_id'] === (int) $accountId
			&& (string) $proof['account_currency'] === $currency
			&& (int) $proof['payment_base_cents'] > 0
			&& (int) $proof['payment_foreign_cents'] > 0
			&& (int) $proof['link_base_cents'] === (int) $proof['payment_base_cents']
			&& (int) $proof['link_foreign_cents'] === (int) $proof['payment_foreign_cents']
			&& (string) $proof['link_currency'] === 'CDF'
			&& is_finite((float) $proof['link_rate'])
			&& abs((float) $proof['link_rate'] - (float) $exchangeRate) <= 0.00000001;

		if ($currency === 'CDF') {
			$valid = $valid
				&& (int) $proof['payment_foreign_cents'] === (int) $amountCents
				&& (int) $proof['bank_amount_cents'] === (int) $amountCents
				&& $proof['bank_main_cents'] !== null
				&& (int) $proof['bank_main_cents'] === (int) $proof['payment_base_cents'];
		} elseif ($currency === 'USD') {
			$valid = $valid
				&& (int) $proof['payment_base_cents'] === (int) $amountCents
				&& (int) $proof['bank_amount_cents'] === (int) $amountCents
				&& $proof['bank_main_cents'] === null;
		} else {
			$valid = false;
		}

		if (!$valid) {
			dol_syslog(__METHOD__.' payment='.$paymentId.' bank_line='.$bankLineId.' account='.$accountId.' currency='.$currency.' native cash ledger mismatch', LOG_ERR);
			throw new RestException(500, 'Created cash payment failed native currency-ledger reconciliation. No settlement was committed.');
		}
	}

	/** Create the two linked bank lines required by a cross-currency exchange. */
	private function createCurrencyTransfer($invoice, array $accounts, array $transfer, array $normalized)
	{
		$fromKey = strtolower($transfer['from']);
		$toKey = strtolower($transfer['to']);
		$fromAccount = new Account($this->db);
		$toAccount = new Account($this->db);
		if ($fromAccount->fetch($accounts[$fromKey]['id']) <= 0 || $toAccount->fetch($accounts[$toKey]['id']) <= 0) {
			throw new RestException(500, 'Unable to reload cash accounts for currency transfer.');
		}

		$description = 'InvoicePlus '.$normalized['operation_id'].' - invoice '.$invoice->ref;
		$num = substr('IP-'.$normalized['operation_id'], 0, 50);
		$fromAmount = (float) $this->centsToDecimal($transfer['from_cents']);
		$toAmount = (float) $this->centsToDecimal($transfer['to_cents']);
		$fromMainAmount = null;
		$toMainAmount = null;
		if ($transfer['from'] === 'CDF') {
			$fromMainAmount = -$toAmount;
		}
		if ($transfer['to'] === 'CDF') {
			$toMainAmount = $fromAmount;
		}

		$fromLineId = $fromAccount->addline(
			$normalized['date'],
			'LIQ',
			$description,
			-$fromAmount,
			$num,
			'',
			$this->user,
			'',
			'',
			'',
			null,
			'',
			$fromMainAmount
		);
		if ($fromLineId <= 0) {
			throw new RestException(500, 'Unable to create source line for currency transfer.');
		}
		$toLineId = $toAccount->addline(
			$normalized['date'],
			'LIQ',
			$description,
			$toAmount,
			$num,
			'',
			$this->user,
			'',
			'',
			'',
			null,
			'',
			$toMainAmount
		);
		if ($toLineId <= 0) {
			throw new RestException(500, 'Unable to create destination line for currency transfer.');
		}

		$url = DOL_URL_ROOT.'/compta/bank/line.php?rowid=';
		if ($fromAccount->add_url_line($fromLineId, $toLineId, $url, '(banktransfert)', 'banktransfert') <= 0) {
			throw new RestException(500, 'Unable to link source currency-transfer line.');
		}
		if ($toAccount->add_url_line($toLineId, $fromLineId, $url, '(banktransfert)', 'banktransfert') <= 0) {
			throw new RestException(500, 'Unable to link destination currency-transfer line.');
		}

		return array(
			'from_currency' => $transfer['from'],
			'to_currency' => $transfer['to'],
			'from_amount' => $this->centsToDecimal($transfer['from_cents']),
			'to_amount' => $this->centsToDecimal($transfer['to_cents']),
			'from_account_id' => (int) $accounts[$fromKey]['id'],
			'to_account_id' => (int) $accounts[$toKey]['id'],
			'from_bank_line_id' => (int) $fromLineId,
			'to_bank_line_id' => (int) $toLineId,
		);
	}

	/** Build the stable response stored for future idempotent replays. */
	private function buildResult($invoice, array $normalized, array $remaining, array $allocation, array $accounts, array $payments, $transfer, array $paymentMode)
	{
		return array(
			'success' => true,
			'status' => self::STATUS_COMPLETED,
			'idempotent_replay' => false,
			'operation_id' => $normalized['operation_id'],
			'invoice' => array(
				'id' => (int) $invoice->id,
				'ref' => (string) $invoice->ref,
				'status' => Facture::STATUS_CLOSED,
				'paid' => true,
			),
			'warehouse_id' => (int) $this->user->fk_warehouse,
			'date' => (int) $normalized['date'],
			'exchange_rate' => $normalized['exchange_rate_decimal'],
			'payment_method' => $paymentMode,
			'amounts' => array(
				'total' => array('cdf' => $this->centsToDecimal($remaining['cdf']), 'usd' => $this->centsToDecimal($remaining['usd'])),
				'received' => array('cdf' => $this->centsToDecimal($normalized['received_cdf_cents']), 'usd' => $this->centsToDecimal($normalized['received_usd_cents'])),
				'change' => array('cdf' => $this->centsToDecimal($normalized['change_cdf_cents']), 'usd' => $this->centsToDecimal($normalized['change_usd_cents'])),
				'net' => array(
					'cdf' => $this->centsToDecimal($normalized['received_cdf_cents'] - $normalized['change_cdf_cents']),
					'usd' => $this->centsToDecimal($normalized['received_usd_cents'] - $normalized['change_usd_cents']),
				),
				'invoice_payments' => array(
					'cdf' => $this->centsToDecimal($allocation['payment_cdf_cents']),
					'usd' => $this->centsToDecimal($allocation['payment_usd_cents']),
				),
			),
			'accounts' => array(
				'cdf' => $accounts['cdf'] !== null ? (int) $accounts['cdf']['id'] : null,
				'usd' => $accounts['usd'] !== null ? (int) $accounts['usd']['id'] : null,
			),
			'payments' => $payments,
			'transfer' => $transfer,
		);
	}

	/** Reserve an operation and keep its outer transaction open. */
	private function reserveOperation($invoiceId, $operationId, $payloadHash)
	{
		$now = dol_now();
		if (!$this->db->begin('InvoicePlus cash settlement')) {
			throw new RestException(503, 'Unable to start cash-settlement transaction.');
		}
		$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'invoiceplus_cash_settlement';
		$sql .= ' (entity, operation_id, fk_facture, payload_sha256, status, result_json, error_code, error_message, fk_user, date_creation, date_update) VALUES (';
		$sql .= ((int) $this->entity).", '".$this->db->escape($operationId)."', ".((int) $invoiceId).", '".$this->db->escape($payloadHash)."', '".self::STATUS_PROCESSING."', NULL, NULL, NULL, ".((int) $this->user->id).", '".$this->db->idate($now)."', '".$this->db->idate($now)."')";
		$result = $this->db->query($sql);
		if ($result) {
			return array('replay' => false);
		}

		$duplicate = ($this->db->lasterrno() === 'DB_ERROR_RECORD_ALREADY_EXISTS');
		$this->db->rollback('InvoicePlus operation reservation collision');
		if (!$duplicate) {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_ERR);
			throw new RestException(503, 'Unable to reserve cash-settlement operation. Reactivate InvoicePlus if its schema was not upgraded.');
		}

		if (!$this->db->begin('InvoicePlus cash settlement replay')) {
			throw new RestException(503, 'Unable to restart cash-settlement transaction.');
		}
		$row = $this->fetchOperation($operationId, true, false);
		if ($row === null) {
			$this->db->rollback('InvoicePlus missing operation after collision');
			throw new RestException(409, 'Cash-settlement operation is being retried concurrently.');
		}
		if ((int) $row->fk_user !== (int) $this->user->id || (int) $row->fk_facture !== (int) $invoiceId) {
			$this->db->rollback('InvoicePlus operation ownership mismatch');
			throw new RestException(409, 'operation_id is already used by another settlement.');
		}
		if (!hash_equals((string) $row->payload_sha256, (string) $payloadHash)) {
			$this->db->rollback('InvoicePlus operation payload mismatch');
			throw new RestException(409, 'operation_id was already used with a different payload.');
		}
		if ((string) $row->status === self::STATUS_COMPLETED) {
			$response = json_decode((string) $row->result_json, true);
			$this->db->commit('InvoicePlus idempotent replay');
			if (!is_array($response)) {
				throw new RestException(500, 'Stored settlement result is invalid.');
			}
			$response['idempotent_replay'] = true;
			return array('replay' => true, 'result' => $response);
		}
		if ((string) $row->status === self::STATUS_FAILED) {
			$update = 'UPDATE '.MAIN_DB_PREFIX.'invoiceplus_cash_settlement';
			$update .= " SET status='".self::STATUS_PROCESSING."', result_json=NULL, error_code=NULL, error_message=NULL, date_update='".$this->db->idate($now)."'";
			$update .= ' WHERE rowid = '.((int) $row->rowid);
			if (!$this->db->query($update)) {
				$this->db->rollback('InvoicePlus failed operation restart');
				throw new RestException(503, 'Unable to restart failed cash-settlement operation.');
			}
			return array('replay' => false);
		}

		$this->db->rollback('InvoicePlus concurrent operation still processing');
		throw new RestException(409, 'Cash-settlement operation is already processing.');
	}

	/** Persist the completed response inside the settlement transaction. */
	private function completeOperation($operationId, $payloadHash, array $response)
	{
		$json = json_encode($response);
		if ($json === false) {
			throw new RestException(500, 'Unable to serialize cash-settlement result.');
		}
		$sql = 'UPDATE '.MAIN_DB_PREFIX.'invoiceplus_cash_settlement';
		$sql .= " SET status='".self::STATUS_COMPLETED."', result_json='".$this->db->escape($json)."', error_code=NULL, error_message=NULL, date_update='".$this->db->idate(dol_now())."'";
		$sql .= ' WHERE entity = '.((int) $this->entity);
		$sql .= " AND operation_id = '".$this->db->escape($operationId)."'";
		$sql .= " AND payload_sha256 = '".$this->db->escape($payloadHash)."'";
		$sql .= ' AND fk_user = '.((int) $this->user->id);
		if (!$this->db->query($sql)) {
			throw new RestException(500, 'Unable to persist cash-settlement result.');
		}
	}

	/**
	 * Abort the physical outer transaction even when a nested Dolibarr method
	 * returned with an unbalanced transaction depth after an error.
	 *
	 * @param string $reason Log label
	 * @return void
	 */
	private function rollbackAllTransactions($reason)
	{
		$guard = 0;
		do {
			$this->db->rollback($reason);
			$guard++;
		} while (isset($this->db->transaction_opened) && $this->db->transaction_opened > 0 && $guard < 32);
	}

	/** Commit the physical transaction even if a nested Dolibarr method leaked a level. */
	private function commitAllTransactions($reason)
	{
		$guard = 0;
		do {
			if (!$this->db->commit($reason)) {
				return false;
			}
			$guard++;
		} while (isset($this->db->transaction_opened) && $this->db->transaction_opened > 0 && $guard < 32);

		return !isset($this->db->transaction_opened) || $this->db->transaction_opened === 0;
	}

	/** Best-effort failure audit written after the business transaction rollback. */
	private function recordFailure($invoiceId, $operationId, $payloadHash, $errorCode, $errorMessage)
	{
		$now = dol_now();
		if (!$this->db->begin('InvoicePlus settlement failure audit')) {
			return;
		}
		$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'invoiceplus_cash_settlement';
		$sql .= ' (entity, operation_id, fk_facture, payload_sha256, status, result_json, error_code, error_message, fk_user, date_creation, date_update) VALUES (';
		$sql .= ((int) $this->entity).", '".$this->db->escape($operationId)."', ".((int) $invoiceId).", '".$this->db->escape($payloadHash)."', '".self::STATUS_FAILED."', NULL, '".$this->db->escape((string) $errorCode)."', '".$this->db->escape(substr((string) $errorMessage, 0, 2000))."', ".((int) $this->user->id).", '".$this->db->idate($now)."', '".$this->db->idate($now)."')";
		$result = $this->db->query($sql);
		if (!$result && $this->db->lasterrno() === 'DB_ERROR_RECORD_ALREADY_EXISTS') {
			$sql = 'UPDATE '.MAIN_DB_PREFIX.'invoiceplus_cash_settlement';
			$sql .= " SET status='".self::STATUS_FAILED."', error_code='".$this->db->escape((string) $errorCode)."', error_message='".$this->db->escape(substr((string) $errorMessage, 0, 2000))."', date_update='".$this->db->idate($now)."'";
			$sql .= ' WHERE entity = '.((int) $this->entity);
			$sql .= " AND operation_id='".$this->db->escape($operationId)."'";
			$sql .= " AND payload_sha256='".$this->db->escape($payloadHash)."'";
			$sql .= ' AND fk_user='.((int) $this->user->id);
			$sql .= " AND status <> '".self::STATUS_COMPLETED."'";
			$result = $this->db->query($sql);
		}
		if ($result) {
			$this->db->commit('InvoicePlus settlement failure audit');
		} else {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_ERR);
			$this->db->rollback('InvoicePlus settlement failure audit error');
		}
	}

	/** Fetch one operation, optionally with a row lock and user filter. */
	private function fetchOperation($operationId, $forUpdate, $sameUserOnly)
	{
		$sql = 'SELECT rowid, entity, operation_id, fk_facture, payload_sha256, status, result_json, error_code, error_message, fk_user, date_creation, date_update';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'invoiceplus_cash_settlement';
		$sql .= ' WHERE entity = '.((int) $this->entity);
		$sql .= " AND operation_id = '".$this->db->escape($operationId)."'";
		if ($sameUserOnly) {
			$sql .= ' AND fk_user = '.((int) $this->user->id);
		}
		if ($forUpdate) {
			$sql .= ' FOR UPDATE';
		}
		$result = $this->db->query($sql);
		if (!$result) {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_ERR);
			throw new RestException(503, 'Unable to read cash-settlement operation.');
		}
		$row = $this->db->fetch_object($result);
		$this->db->free($result);
		return $row ?: null;
	}

	/** Normalize the two expected CDF/USD fields of a nested request object. */
	private function normalizeCurrencyObject($value, $field, $accounts)
	{
		if (!is_array($value)) {
			throw new RestException(400, $field.' must be an object with cdf and usd fields.');
		}
		foreach ($value as $key => $unused) {
			if (!in_array($key, array('cdf', 'usd'), true)) {
				throw new RestException(400, "Unknown field '".$field.'.'.$key."'.");
			}
		}
		foreach (array('cdf', 'usd') as $key) {
			if (!array_key_exists($key, $value)) {
				throw new RestException(400, "Missing required field '".$field.'.'.$key."'.");
			}
		}
		if ($accounts) {
			return array(
				'cdf' => $this->optionalPositiveInteger($value['cdf'], $field.'.cdf'),
				'usd' => $this->optionalPositiveInteger($value['usd'], $field.'.usd'),
			);
		}
		return array(
			'cdf' => $this->moneyToCents($value['cdf'], $field.'.cdf'),
			'usd' => $this->moneyToCents($value['usd'], $field.'.usd'),
		);
	}

	/** Convert a non-negative decimal with at most two digits into cents. */
	private function moneyToCents($value, $field)
	{
		if (is_int($value)) {
			$string = (string) $value;
		} elseif (is_float($value)) {
			if (!is_finite($value) || abs($value - round($value, 2)) > 0.0000001) {
				throw new RestException(400, $field.' must have at most two decimal digits.');
			}
			$string = number_format($value, 2, '.', '');
		} elseif (is_string($value)) {
			$string = trim($value);
		} else {
			throw new RestException(400, $field.' must be a non-negative decimal amount.');
		}
		if (!preg_match('/^[0-9]+(?:\.[0-9]{1,2})?$/', $string)) {
			throw new RestException(400, $field.' must be a non-negative decimal amount with at most two digits.');
		}
		$parts = explode('.', $string, 2);
		$whole = ltrim($parts[0], '0');
		$whole = ($whole === '') ? '0' : $whole;
		if (strlen($whole) > 12) {
			throw new RestException(400, $field.' is too large.');
		}
		$decimal = isset($parts[1]) ? str_pad($parts[1], 2, '0') : '00';
		return ((int) $whole * 100) + (int) $decimal;
	}

	/**
	 * Subtract monetary decimals after normalizing every value to integer cents.
	 *
	 * Native Dolibarr getters return floats, so this prevents binary floating
	 * point error from leaking into the settlement remainder comparison.
	 *
	 * @param array $positive Decimal values to add
	 * @param array $negative Decimal values to subtract
	 * @return int            Signed result in cents
	 */
	private function decimalDifferenceToCents(array $positive, array $negative)
	{
		$total = 0;
		foreach ($positive as $value) {
			$total += (int) round(((float) $value) * 100);
		}
		foreach ($negative as $value) {
			$total -= (int) round(((float) $value) * 100);
		}
		return $total;
	}

	/** Convert one native stored monetary value to exact cents. */
	private function storedMoneyToCents($value, $field, $allowNull = false)
	{
		if ($value === null) {
			if ($allowNull) {
				return null;
			}
			throw new RestException(500, $field.' is missing from the native cash ledger.');
		}
		if (!is_numeric($value)) {
			throw new RestException(500, $field.' is invalid in the native cash ledger.');
		}
		$numeric = (float) $value;
		$scaled = $numeric * 100;
		if (!is_finite($numeric) || !is_finite($scaled) || abs($scaled - round($scaled)) > 0.000001 || abs($scaled) > PHP_INT_MAX) {
			throw new RestException(500, $field.' cannot be represented as exact cents in the native cash ledger.');
		}
		return (int) round($scaled);
	}

	/** Format signed cents without floating-point arithmetic. */
	private function centsToDecimal($cents)
	{
		$cents = (int) $cents;
		$sign = $cents < 0 ? '-' : '';
		$absolute = abs($cents);
		return $sign.(string) intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
	}

	/** Normalize a strict, positive integer. */
	private function positiveInteger($value, $field)
	{
		if ((is_int($value) || is_string($value)) && preg_match('/^[1-9][0-9]*$/', (string) $value)) {
			return (int) $value;
		}
		throw new RestException(400, $field.' must be a positive integer.');
	}

	/** Normalize an optional account id; null and zero mean unused. */
	private function optionalPositiveInteger($value, $field)
	{
		if ($value === null || $value === 0 || $value === '0' || $value === '') {
			return 0;
		}
		return $this->positiveInteger($value, $field);
	}

	/** Normalize the invoice exchange rate. */
	private function normalizeRate($value)
	{
		if (!is_int($value) && !is_float($value) && !is_string($value)) {
			throw new RestException(400, 'exchange_rate must be a positive decimal.');
		}
		$string = trim((string) $value);
		if (!preg_match('/^[0-9]+(?:\.[0-9]{1,8})?$/', $string)) {
			throw new RestException(400, 'exchange_rate must be a positive decimal with at most eight digits.');
		}
		$rate = (float) $string;
		if ($rate <= 0 || !is_finite($rate)) {
			throw new RestException(400, 'exchange_rate must be greater than zero.');
		}
		return $rate;
	}

	/** Normalize payment date to a Unix timestamp. */
	private function normalizeDate($value)
	{
		if (is_int($value) || (is_string($value) && preg_match('/^[0-9]+$/', $value))) {
			$date = (int) $value;
		} elseif (is_string($value)) {
			$date = dol_stringtotime($value);
		} else {
			$date = 0;
		}
		if ($date <= 0) {
			throw new RestException(400, 'date must be a valid Unix timestamp or date string.');
		}
		return $date;
	}

	/** Normalize the client-generated idempotency key. */
	private function normalizeOperationId($operationId)
	{
		$operationId = trim((string) $operationId);
		if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,63}$/', $operationId)) {
			throw new RestException(400, 'operation_id must contain 8 to 64 safe characters.');
		}
		return $operationId;
	}
}

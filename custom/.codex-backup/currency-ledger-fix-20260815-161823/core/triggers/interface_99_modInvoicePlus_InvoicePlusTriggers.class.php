<?php
/* Copyright (C) 2026 InvoicePlus module
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    custom/invoiceplus/core/triggers/interface_99_modInvoicePlus_InvoicePlusTriggers.class.php
 * \ingroup invoiceplus
 * \brief   Transactional invariants for InvoicePlus customer payments.
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

/**
 * Prevent a native Dolibarr payment, calculated from a stale balance, from
 * overpaying an invoice while an InvoicePlus cash settlement is committing.
 */
class InterfaceInvoicePlusTriggers extends DolibarrTriggers
{
	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		parent::__construct($db);
		$this->family = 'financial';
		$this->description = 'InvoicePlus transactional customer-payment safeguards.';
		$this->version = '1.0.0';
		$this->picto = 'bill';
	}

	/**
	 * @param string       $action Trigger action
	 * @param CommonObject $object Payment concerned by the event
	 * @param User         $user   Acting user
	 * @param Translate    $langs  Translation handler
	 * @param Conf         $conf   Configuration
	 * @return int                 0 if ignored/accepted, <0 to rollback the payment
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (!isModEnabled('invoiceplus') || $action !== 'PAYMENT_CUSTOMER_CREATE') {
			return 0;
		}
		if (!is_object($object) || empty($object->id)) {
			return $this->rejectPayment($object, 'InvoicePlus cannot verify a customer payment without its identifier.');
		}

		$invoiceIds = $this->getPaymentInvoiceIds((int) $object->id);
		if ($invoiceIds === null) {
			return $this->rejectPayment($object, 'InvoicePlus could not read the invoices linked to this payment.');
		}
		if (count($invoiceIds) === 0) {
			return 0;
		}

		/*
		 * The operation journal is the serialization guard. InvoicePlus reserves
		 * it before locking the invoice, so every participant must lock journal
		 * rows first and invoice rows second to avoid lock-order inversion.
		 * FOR UPDATE is also a current read under InnoDB REPEATABLE READ.
		 */
		$protectedInvoices = $this->lockProtectedOperations($invoiceIds);
		if ($protectedInvoices === null) {
			return $this->rejectPayment($object, 'InvoicePlus could not lock its cash-settlement journal.');
		}
		if (count($protectedInvoices) === 0) {
			return 0;
		}

		ksort($protectedInvoices, SORT_NUMERIC);
		foreach ($protectedInvoices as $invoiceId => $entities) {
			$invoice = $this->lockInvoice((int) $invoiceId);
			if ($invoice === null) {
				return $this->rejectPayment($object, 'InvoicePlus could not lock a protected customer invoice.');
			}
			if (!isset($entities[(int) $invoice->entity])) {
				return $this->rejectPayment($object, 'InvoicePlus detected an inconsistent settlement entity.');
			}

			$applied = $this->lockAndSumAppliedAmounts((int) $invoiceId);
			if ($applied === null) {
				return $this->rejectPayment($object, 'InvoicePlus could not reconcile the protected invoice balance.');
			}

			$totalBaseCents = $this->decimalToCents($invoice->total_ttc);
			$totalForeignCents = $this->decimalToCents($invoice->multicurrency_total_ttc);
			if ($totalBaseCents <= 0 || $totalForeignCents <= 0) {
				return $this->rejectPayment($object, 'InvoicePlus cash settlement requires positive invoice totals.');
			}
			if ($this->hasOverpayment($totalBaseCents, $totalForeignCents, $applied)) {
				return $this->rejectPayment(
					$object,
					'Payment refused: the invoice balance changed or this payment would exceed it.'
				);
			}
		}

		return 0;
	}

	/**
	 * Read invoice ids inserted by Paiement::create() in the current transaction.
	 *
	 * @param int $paymentId Payment id
	 * @return array<int>|null Sorted ids, null on SQL error
	 */
	private function getPaymentInvoiceIds($paymentId)
	{
		$sql = 'SELECT DISTINCT fk_facture FROM '.MAIN_DB_PREFIX.'paiement_facture';
		$sql .= ' WHERE fk_paiement = '.((int) $paymentId);
		$sql .= ' ORDER BY fk_facture';
		$result = $this->db->query($sql);
		if (!$result) {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_ERR);
			return null;
		}

		$ids = array();
		while ($row = $this->db->fetch_object($result)) {
			if ((int) $row->fk_facture > 0) {
				$ids[] = (int) $row->fk_facture;
			}
		}
		$this->db->free($result);
		sort($ids, SORT_NUMERIC);
		return $ids;
	}

	/**
	 * Lock operation rows before invoice rows, matching the settlement service.
	 * Failed operations do not protect an invoice because no settlement committed.
	 *
	 * @param array<int> $invoiceIds Candidate invoice ids
	 * @return array<int,array<int,bool>>|null Invoice/entity map, null on SQL error
	 */
	private function lockProtectedOperations(array $invoiceIds)
	{
		$ids = array_map('intval', $invoiceIds);
		$sql = 'SELECT rowid, entity, fk_facture FROM '.MAIN_DB_PREFIX.'invoiceplus_cash_settlement';
		$sql .= ' WHERE fk_facture IN ('.implode(',', $ids).')';
		$sql .= " AND status IN ('processing', 'completed')";
		$sql .= ' ORDER BY fk_facture, rowid FOR UPDATE';
		$result = $this->db->query($sql);
		if (!$result) {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_ERR);
			return null;
		}

		$protected = array();
		while ($row = $this->db->fetch_object($result)) {
			$invoiceId = (int) $row->fk_facture;
			$entity = (int) $row->entity;
			if (!isset($protected[$invoiceId])) {
				$protected[$invoiceId] = array();
			}
			$protected[$invoiceId][$entity] = true;
		}
		$this->db->free($result);
		return $protected;
	}

	/**
	 * @param int $invoiceId Invoice id
	 * @return object|null Locked row, null on error/not found
	 */
	private function lockInvoice($invoiceId)
	{
		$sql = 'SELECT rowid, entity, total_ttc, multicurrency_total_ttc';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'facture';
		$sql .= ' WHERE rowid = '.((int) $invoiceId).' FOR UPDATE';
		$result = $this->db->query($sql);
		if (!$result) {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_ERR);
			return null;
		}
		$row = $this->db->fetch_object($result);
		$this->db->free($result);
		return $row ?: null;
	}

	/**
	 * Lock and sum all native payment links plus credits/deposits already applied.
	 * Row-by-row locking reads are intentional: a plain SUM may use an obsolete
	 * repeatable-read snapshot after waiting for another settlement to commit.
	 *
	 * @param int $invoiceId Invoice id
	 * @return array{base_cents:int,foreign_cents:int}|null Totals, null on error
	 */
	private function lockAndSumAppliedAmounts($invoiceId)
	{
		$base = 0.0;
		$foreign = 0.0;
		$sql = 'SELECT rowid, amount, multicurrency_amount';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'paiement_facture';
		$sql .= ' WHERE fk_facture = '.((int) $invoiceId);
		$sql .= ' ORDER BY rowid FOR UPDATE';
		$result = $this->db->query($sql);
		if (!$result) {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_ERR);
			return null;
		}
		while ($row = $this->db->fetch_object($result)) {
			if (!$this->addDecimal($base, $row->amount) || !$this->addDecimal($foreign, $row->multicurrency_amount)) {
				$this->db->free($result);
				return null;
			}
		}
		$this->db->free($result);

		$sql = 'SELECT rowid, amount_ttc, multicurrency_amount_ttc';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'societe_remise_except';
		$sql .= ' WHERE fk_facture = '.((int) $invoiceId);
		$sql .= ' ORDER BY rowid FOR UPDATE';
		$result = $this->db->query($sql);
		if (!$result) {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_ERR);
			return null;
		}
		while ($row = $this->db->fetch_object($result)) {
			if (!$this->addDecimal($base, $row->amount_ttc) || !$this->addDecimal($foreign, $row->multicurrency_amount_ttc)) {
				$this->db->free($result);
				return null;
			}
		}
		$this->db->free($result);

		return array(
			'base_cents' => $this->decimalToCents($base),
			'foreign_cents' => $this->decimalToCents($foreign),
		);
	}

	/**
	 * @param float $sum   Running total, passed by reference
	 * @param mixed $value Database decimal
	 * @return bool        False for malformed data
	 */
	private function addDecimal(&$sum, $value)
	{
		if ($value === null || $value === '') {
			return true;
		}
		if (!is_numeric($value)) {
			dol_syslog(__METHOD__.' invalid monetary value', LOG_ERR);
			return false;
		}
		$sum += (float) $value;
		return is_finite($sum);
	}

	/**
	 * Convert a Dolibarr decimal to the integer-cent invariant used by InvoicePlus.
	 *
	 * @param mixed $value Decimal amount
	 * @return int         Amount rounded to the nearest cent
	 */
	private function decimalToCents($value)
	{
		return (int) round(((float) $value) * 100, 0, PHP_ROUND_HALF_UP);
	}

	/**
	 * Pure comparison kept separate so deterministic tests can cover both ledgers.
	 *
	 * @param int   $totalBaseCents    Invoice total in company currency
	 * @param int   $totalForeignCents Invoice total in invoice currency
	 * @param array $applied           Applied base/foreign cents
	 * @return bool                    True when either ledger exceeds its total
	 */
	private function hasOverpayment($totalBaseCents, $totalForeignCents, array $applied)
	{
		return (int) $applied['base_cents'] > (int) $totalBaseCents
			|| (int) $applied['foreign_cents'] > (int) $totalForeignCents;
	}

	/**
	 * Report the reason on both the trigger and payment objects. Returning a
	 * negative value makes Paiement::create() rollback its transaction.
	 *
	 * @param mixed  $object  Payment object
	 * @param string $message Error message
	 * @return int            Always -1
	 */
	private function rejectPayment($object, $message)
	{
		$this->error = $message;
		$this->errors[] = $message;
		if (is_object($object)) {
			$object->error = $message;
			if (!isset($object->errors) || !is_array($object->errors)) {
				$object->errors = array();
			}
			$object->errors[] = $message;
		}
		dol_syslog(__METHOD__.' '.$message, LOG_WARNING);
		return -1;
	}
}

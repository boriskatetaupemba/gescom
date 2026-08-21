<?php
/* Copyright (C) 2026 InvoicePlus module
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    custom/invoiceplus/core/triggers/interface_98_modInvoicePlus_InvoicePlusProductPrices.class.php
 * \ingroup invoiceplus
 * \brief   Persist the upper price levels submitted by the product create form.
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';
dol_include_once('/invoiceplus/class/invoicepluspricelevelservice.class.php');
dol_include_once('/invoiceplus/class/actions_invoiceplus.class.php');

/**
 * Write price levels 2 to N right after a native product creation.
 *
 * Level 1 stays created and logged by the native Product::create() cycle. This
 * trigger runs inside that same transaction, so returning a negative value
 * rolls the whole creation back and never leaves a partially priced product.
 */
class InterfaceInvoicePlusProductPrices extends DolibarrTriggers
{
	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		parent::__construct($db);
		$this->family = 'products';
		$this->description = 'InvoicePlus multi-level prices at product creation.';
		$this->version = '1.4.0';
		$this->picto = 'product';
	}

	/**
	 * @param string       $action Trigger action
	 * @param CommonObject $object Product concerned by the event
	 * @param User         $user   Acting user
	 * @param Translate    $langs  Translation handler
	 * @param Conf         $conf   Configuration
	 * @return int                 0 if ignored/accepted, <0 to rollback the creation
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (!isModEnabled('invoiceplus') || $action !== 'PRODUCT_CREATE') {
			return 0;
		}
		if (!is_object($object) || $object->element !== 'product' || (int) $object->id <= 0) {
			return 0;
		}

		$levels = $this->getSubmittedLevels($object);
		if ($levels === null) {
			// No InvoicePlus marker: a native, API or third-party creation.
			return 0;
		}
		// Consume the context so a second create() on the same object can never
		// log a duplicate history line for the same level.
		unset($object->context[ActionsInvoiceplus::PRICE_LEVELS_CONTEXT_KEY]);

		if (!InvoicePlusPriceLevelService::isMultiPriceEnabled()) {
			return $this->rejectCreation(
				$object,
				'InvoicePlus cannot store additional price levels while multiprices are disabled.'
			);
		}
		$limit = InvoicePlusPriceLevelService::getPriceLevelLimit();

		ksort($levels, SORT_NUMERIC);
		foreach ($levels as $level => $definition) {
			$level = (int) $level;
			if ($level < 2 || $level > $limit) {
				return $this->rejectCreation($object, 'InvoicePlus received an out-of-range price level: '.$level.'.');
			}
			if (!is_array($definition) || !isset($definition['price']) || !is_numeric($definition['price'])) {
				return $this->rejectCreation($object, 'InvoicePlus received an invalid price for level '.$level.'.');
			}
			$baseType = isset($definition['price_base_type']) ? (string) $definition['price_base_type'] : 'HT';
			if (!in_array($baseType, array('HT', 'TTC'), true)) {
				return $this->rejectCreation($object, 'InvoicePlus received an invalid price base for level '.$level.'.');
			}

			// The native method historizes the level and fires the native
			// PRODUCT_PRICE_MODIFY trigger. Passing null as VAT makes it reuse
			// the VAT of the product form, so no per-level VAT is introduced.
			$result = $object->updatePrice(
				$definition['price'],
				$baseType,
				$user,
				null,
				0,
				$level,
				(int) $object->tva_npr,
				0,
				0,
				array(),
				(string) $object->default_vat_code,
				'',
				0
			);
			if ($result <= 0) {
				$message = 'InvoicePlus could not store the price of level '.$level.'.';
				if (!empty($object->error)) {
					$message .= ' '.$object->error;
				}
				return $this->rejectCreation($object, $message);
			}
		}

		return 0;
	}

	/**
	 * Return the levels prepared by the InvoicePlus product form, or null.
	 *
	 * @param CommonObject $object Product being created
	 * @return array|null           Levels indexed by level, null when absent
	 */
	private function getSubmittedLevels($object)
	{
		if (!isset($object->context) || !is_array($object->context)) {
			return null;
		}
		$key = ActionsInvoiceplus::PRICE_LEVELS_CONTEXT_KEY;
		if (!array_key_exists($key, $object->context) || !is_array($object->context[$key])) {
			return null;
		}

		return $object->context[$key];
	}

	/**
	 * Report the reason on both the trigger and the product.
	 *
	 * A negative return makes Product::create() roll its transaction back, so a
	 * failed level cancels the whole creation.
	 *
	 * @param mixed  $object  Product object
	 * @param string $message Error message
	 * @return int            Always -1
	 */
	private function rejectCreation($object, $message)
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
		dol_syslog(__METHOD__.' '.$message, LOG_ERR);

		return -1;
	}
}

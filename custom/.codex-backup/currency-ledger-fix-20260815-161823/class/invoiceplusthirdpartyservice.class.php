<?php
/* Copyright (C) 2026 InvoicePlus module
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    custom/invoiceplus/class/invoiceplusthirdpartyservice.class.php
 * \ingroup invoiceplus
 * \brief   Third-party selection for the authenticated sales representative.
 */

if (!class_exists('DolibarrApiAccess')) {
	require_once DOL_DOCUMENT_ROOT.'/api/class/api_access.class.php';
}
if (!class_exists('DolibarrApi')) {
	require_once DOL_DOCUMENT_ROOT.'/api/class/api.class.php';
}
require_once DOL_DOCUMENT_ROOT.'/societe/class/api_thirdparties.class.php';

use Luracast\Restler\RestException;

/**
 * Native API bridge exposing Dolibarr's protected cleanup helpers.
 */
class InvoicePlusThirdPartyApiBridge extends Thirdparties
{
	/**
	 * Clean a third-party like the native Thirdparties list, then filter fields.
	 *
	 * @param Societe $object     Loaded third-party object
	 * @param string $properties Comma-separated properties
	 * @return Object             Cleaned and filtered object
	 */
	public function cleanAndFilter($object, $properties)
	{
		return $this->_filterObjectProperties($this->_cleanObjectDatas($object), $properties);
	}
}

/**
 * Read-only service for third parties assigned to the current API user.
 */
class InvoicePlusThirdPartyService
{
	/** @var DoliDB */
	private $db;

	/** @var User */
	private $user;

	/** @var int */
	private $maxApiLimit;

	/**
	 * @param DoliDB $db   Database handler
	 * @param User   $user Authenticated API user
	 */
	public function __construct($db, $user)
	{
		$this->db = $db;
		$this->user = $user;
		$this->maxApiLimit = max(1, (int) getDolGlobalInt('INVOICEPLUS_MAX_API_LIMIT', 1000));
	}

	/**
	 * Return native third-party objects assigned to the authenticated user.
	 *
	 * @param array $options Validated endpoint options
	 * @return array         Third-party objects
	 * @throws RestException
	 */
	public function getAssignedThirdParties(array $options)
	{
		$sortfield = $this->validateSortField($options['sortfield']);
		$sortorder = $this->validateSortOrder($options['sortorder']);
		$limit = $this->validateLimit($options['limit']);
		$page = $this->validatePage($options['page']);
		$status = $this->validateStatus($options['status']);

		$sql = 'SELECT t.rowid';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'societe AS t';
		$sql .= ' WHERE t.entity IN ('.getEntity('societe').')';
		$sql .= ' AND EXISTS (SELECT 1 FROM '.MAIN_DB_PREFIX.'societe_commerciaux AS sc';
		$sql .= ' WHERE sc.fk_soc = t.rowid AND sc.fk_user = '.((int) $this->user->id).')';
		if ($status >= 0) {
			$sql .= ' AND t.status = '.$status;
		}
		$sql .= $this->db->order($sortfield, $sortorder);
		if ($sortfield !== 't.rowid') {
			$sql .= ', t.rowid '.$sortorder;
		}
		$sql .= $this->db->plimit($limit, $page * $limit);

		$result = $this->db->query($sql);
		if (!$result) {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_ERR);
			throw new RestException(503, 'Unable to retrieve assigned third parties.');
		}

		$responses = array();
		$apiBridge = null;
		while ($record = $this->db->fetch_object($result)) {
			$thirdParty = new Societe($this->db);
			$fetchResult = $thirdParty->fetch((int) $record->rowid);
			if ($fetchResult < 0) {
				dol_syslog(__METHOD__.' '.$thirdParty->error, LOG_ERR);
				$this->db->free($result);
				throw new RestException(503, 'Unable to load an assigned third party.');
			}
			if ($fetchResult == 0) {
				$this->db->free($result);
				throw new RestException(404, 'Assigned third party not found.');
			}
			if (isModEnabled('mailing')) {
				$thirdParty->getNoEmail();
			}
			if ($apiBridge === null) {
				$apiBridge = new InvoicePlusThirdPartyApiBridge();
			}
			$responses[] = $apiBridge->cleanAndFilter($thirdParty, (string) $options['properties']);
		}
		$this->db->free($result);

		return $responses;
	}

	/**
	 * @param string $sortfield Requested sort field
	 * @return string           Safe SQL field
	 * @throws RestException
	 */
	public function validateSortField($sortfield)
	{
		$allowed = array(
			't.rowid',
			't.nom',
			't.name_alias',
			't.code_client',
			't.town',
			't.datec',
			't.tms',
			't.status',
		);
		$sortfield = trim((string) $sortfield);
		if (!in_array($sortfield, $allowed, true)) {
			throw new RestException(400, 'Invalid third-party sort field.');
		}

		return $sortfield;
	}

	/**
	 * @param mixed $sortorder Requested sort order
	 * @return string          ASC or DESC
	 * @throws RestException
	 */
	public function validateSortOrder($sortorder)
	{
		$sortorder = strtoupper(trim((string) $sortorder));
		if (!in_array($sortorder, array('ASC', 'DESC'), true)) {
			throw new RestException(400, 'Invalid sort order. Expected ASC or DESC.');
		}

		return $sortorder;
	}

	/**
	 * @param mixed $limit Requested page size
	 * @return int         Effective page size
	 * @throws RestException
	 */
	public function validateLimit($limit)
	{
		if (!$this->isIntegerValue($limit)) {
			throw new RestException(400, 'Invalid limit. Expected an integer.');
		}
		if ((int) $limit <= 0) {
			return $this->maxApiLimit;
		}

		return min((int) $limit, $this->maxApiLimit);
	}

	/**
	 * @param mixed $page Requested zero-based page
	 * @return int        Validated page
	 * @throws RestException
	 */
	public function validatePage($page)
	{
		if (!$this->isIntegerValue($page) || (int) $page < 0) {
			throw new RestException(400, 'Invalid page. Expected a non-negative integer.');
		}

		return (int) $page;
	}

	/**
	 * @param mixed $status -1 for all, 0 for closed, 1 for active
	 * @return int          Validated status
	 * @throws RestException
	 */
	public function validateStatus($status)
	{
		if (!$this->isIntegerValue($status) || !in_array((int) $status, array(-1, 0, 1), true)) {
			throw new RestException(400, 'Invalid status. Expected -1, 0 or 1.');
		}

		return (int) $status;
	}

	/**
	 * @param mixed $value Value to validate
	 * @return bool        True for an integer or an integer-formatted string
	 */
	private function isIntegerValue($value)
	{
		return is_int($value) || (is_string($value) && preg_match('/^-?[0-9]+$/', $value));
	}
}

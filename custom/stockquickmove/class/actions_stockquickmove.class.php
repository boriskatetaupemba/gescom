<?php
/* Copyright (C) 2026 StockQuickMove module
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    custom/stockquickmove/class/actions_stockquickmove.class.php
 * \ingroup stockquickmove
 * \brief   Hook actions for the stock movement list.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonhookactions.class.php';

/**
 * Hook actions provided by StockQuickMove.
 */
class ActionsStockQuickMove extends CommonHookActions
{
	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Check whether the current user can access the quick movement form.
	 *
	 * @param array  $parameters Hook metadata
	 * @param string $action     Current action
	 * @return bool              True when the action can be displayed
	 */
	private function canDisplayQuickMovement($parameters, $action)
	{
		global $user;

		$currentcontext = empty($parameters['currentcontext']) ? '' : $parameters['currentcontext'];
		if (strpos(':'.$currentcontext.':', ':stockmovementlist:') === false) {
			return false;
		}
		if (!empty($action) && $action !== 'list') {
			return false;
		}

		return isModEnabled('stockquickmove')
			&& empty($user->socid)
			&& $user->hasRight('stock', 'lire')
			&& $user->hasRight('stock', 'mouvement', 'creer');
	}

	/**
	 * Add the quick movement action to the native stock movement list.
	 *
	 * @param array        $parameters  Hook metadata
	 * @param CommonObject $object      Current object
	 * @param string       $action      Current action
	 * @param HookManager  $hookmanager Hook manager
	 * @return int                      0 to keep the standard actions
	 */
	public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
	{
		global $langs;

		$this->resprints = '';
		if (!$this->canDisplayQuickMovement($parameters, $action)) {
			return 0;
		}

		$langs->load('stockquickmove@stockquickmove');
		$url = dol_buildpath('/stockquickmove/quickmovement.php', 1);
		print dolGetButtonAction(
			$langs->trans('StockQuickMoveButton'),
			'',
			'default',
			$url,
			'stockquickmove-quickmovement',
			true
		);

		return 0;
	}

	/**
	 * Add the quick movement action above the filters of the global movement list.
	 *
	 * Dolibarr only calls addMoreActionsButtons when a warehouse id is selected.
	 * This hook is also executed on the global list, where the historical create
	 * button used to be displayed by the customized core file.
	 *
	 * @param array        $parameters  Hook metadata
	 * @param CommonObject $object      Current object
	 * @param string       $action      Current action
	 * @param HookManager  $hookmanager Hook manager
	 * @return int                      0 to keep the standard filters
	 */
	public function printFieldPreListTitle($parameters, &$object, &$action, $hookmanager)
	{
		global $langs;

		$this->resprints = '';
		if (GETPOSTINT('id') > 0 || !$this->canDisplayQuickMovement($parameters, $action)) {
			return 0;
		}

		$langs->load('stockquickmove@stockquickmove');
		$url = dol_buildpath('/stockquickmove/quickmovement.php', 1);
		$this->resprints = '<div class="right centpercent">';
		$this->resprints .= dolGetButtonTitle(
			$langs->trans('StockQuickMoveButton'),
			'',
			'fa fa-plus-circle',
			$url,
			'stockquickmove-quickmovement-title',
			1
		);
		$this->resprints .= '</div>';

		return 0;
	}
}
